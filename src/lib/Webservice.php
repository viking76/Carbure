<?php

/**
 * Webservice.php
 *
 * Webservices functions for the API.
 * Route resolution is delegated to ApiResolver.
 * Resource classes are loaded by autoload.php.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Carbure\Resources\Changes;
use Carbure\Resources\User;
use Error;
use Exception;
use Throwable;

final class Webservice {

    /**
     * JSON Content-Type header
     */
    private const CONTENT_TYPE_JSON = "Content-Type: application/json";
    
    /**
     * Server-Sent Events Content-Type header
     */
    private const CONTENT_TYPE_SSE = "Content-Type: text/event-stream";

    /** Fields always kept as texts in the responses: versions such as "1.10" or "15" (devices) */
    private const TEXT_FIELDS = ['model', 'os_version', 'app_version'];

    /**
     * The calls that led to an exception, without their arguments: they may hold
     * passwords (bank credentials, login) that the masking of Logger cannot find in a
     * positional list.
     *
     * @param Throwable $exception The exception or error
     * @return list<array<string, mixed>> file, line, class, type and function of each call (no args, no object)
     */
    public static function traceWithoutArgs(Throwable $exception): array
    {
        return array_map(fn(array $call): array => array_diff_key($call, ['args' => true, 'object' => true]),
            $exception->getTrace());
    }

    /**
     * Return an error message in JSON and log the message.
     *
     * @param int       $code      The HTTP error code
     * @param Throwable $exception The exception or error to report
     * @return void
     */
    public static function error(int $code, Throwable $exception): void
    {
        Logger::error($exception->getMessage(), self::traceWithoutArgs($exception));

        if (!headers_sent()) {
            http_response_code($code);
            self::securityHeaders();
            header(self::CONTENT_TYPE_JSON);
            header('Cache-Control: no-store');
        }

        // We return the error message + UID for tracking, and the stable code (+ values)
        // of an ApiError, that the portal translates
        echo json_encode(
            array(
                "error" => $exception->getMessage(),
                "code" => $code,
                "uid" => Logger::getUID()
            ) + ApiError::fields($exception), 
            JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT
        );
    }

    /**
     * Get a request header (case-insensitive).
     *
     * @param string $name The header name (e.g. Authorization)
     * @return string|null The header value or null if not found
     */
    public static function getHeader(string $name): ?string
    {
        // Web servers expose the headers as HTTP_* server variables
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        if (isset($_SERVER['REDIRECT_' . $key])) {
            return $_SERVER['REDIRECT_' . $key];
        }

        // Apache may hide the Authorization header from $_SERVER
        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            return $headers[strtolower($name)] ?? null;
        }

        return null;
    }

    /**
     * Get the payload of the request.
     *
     * @param string|null $rawPayload  The raw body (read from php://input when null)
     * @param string|null $contentType The Content-Type of the body (header of the request when null)
     * @return array<mixed> The decoded JSON payload, or the fields of a form (OAuth token endpoint)
     * @throws Error If the JSON is invalid or is not an object
     */
    public static function getPayload(?string $rawPayload = null, ?string $contentType = null): array
    {
        // Get the raw POST data
        $rawPayload ??= (string)file_get_contents('php://input');
        
        if (empty($rawPayload)) {
            return [];
        }

        // Form fields (application/x-www-form-urlencoded), as OAuth clients send them
        $contentType ??= (string)($_SERVER['CONTENT_TYPE'] ?? self::getHeader('Content-Type') ?? '');
        if (stripos($contentType, 'application/x-www-form-urlencoded') === 0) {
            parse_str($rawPayload, $fields);
            Logger::debug("Payload", self::maskSensitive($fields));
            return $fields;
        }

        // Decode JSON to associative array (not object)
        $payload = json_decode($rawPayload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Error("Invalid JSON payload: " . json_last_error_msg());
        }

        // The parameters of a route: a JSON object (or nothing)
        if ($payload !== null && !is_array($payload)) {
            throw new Error("Invalid JSON payload: an object is expected");
        }

        Logger::debug("Payload", is_array($payload) ? self::maskSensitive($payload) : null);

        return $payload ?? [];
    }

    /**
     * Execute the Webservice.
     *
     * @return string The JSON encoded response
     * @throws Error If the route is not found or access is denied
     */
    public static function exec(): string
    {
        // Get the request path and HTTP method
        $requestPath = (string)strtok($_SERVER["REQUEST_URI"], '?');
        $httpMethod = $_SERVER['REQUEST_METHOD'];
        
        // Remove /api prefix from request path
        $requestPath = (string)preg_replace('#^/api#', '', $requestPath);

        // Find the matching route
        $route = ApiResolver::findRoute($requestPath, $httpMethod);

        if (!$route) {
            throw new Error("Route not found: $httpMethod $requestPath", 404);
        }

        // A route polled by the healthchecks (quiet): its successful calls at the DEBUG level
        Logger::setQuiet($route['apiRoute']->quiet);

        // Set appropriate headers based on route type
        self::setResponseHeaders($route['apiRoute']->stream);

        // Check access control
        self::checkAccess($route['apiRoute']);

        // The body read once the caller is allowed: an anonymous request cannot make the
        // server decode a large body (up to 32 MB on /api/backup/import)
        $data = self::getPayload();

        // Call the service
        $response = self::callService($route['class'], $route['method'], $data, $route['apiRoute']->raw,
            $route['apiRoute']->changes ?? []);

        // The versions of the data with every authenticated answer: the clients check at
        // each call whether what they loaded is still up to date (not for the public
        // routes, nor a stream that has already sent its events)
        if (!$route['apiRoute']->public && !$route['apiRoute']->stream) {
            Changes::sendVersions();
        }

        // A stream has already sent its events: nothing more to output
        return $route['apiRoute']->stream ? '' : $response;
    }

    /**
     * Check access control for the endpoint, before its method is called: a valid JWT
     * unless public, and an administrator for the routes declared with admin: true.
     * @param ApiRoute $apiRoute The API route metadata
     * @return void
     * @throws Error 401 without a valid JWT, 403 if the route is reserved to the administrators
     */
    private static function checkAccess(ApiRoute $apiRoute): void
    {
        // Public endpoints don't require authentication
        if ($apiRoute->public) {
            return;
        }

        // Protected endpoints require valid JWT token
        if (!Jwt::checkAuthorization()) {
            throw new Error("Unauthorized - Invalid or missing JWT token", 401);
        }

        // Administrator endpoints: same error as the checks made by the methods themselves
        if ($apiRoute->admin) {
            User::requireAdmin();
        }
    }

    /**
     * Set appropriate HTTP response headers
     * @param bool $isStream Whether the response is a Server-Sent Events stream
     * @return void
     */
    private static function setResponseHeaders(bool $isStream): void
    {
        self::securityHeaders();

        if ($isStream) {
            // Server-Sent Events headers
            header(self::CONTENT_TYPE_SSE);
            header('Cache-Control: no-store');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no');
            
            // Disable PHP output buffering
            if (ob_get_level()) ob_end_clean();
        } else {
            // Standard JSON response headers
            header(self::CONTENT_TYPE_JSON);
            // Bank data: never kept by the browser or a proxy
            header('Cache-Control: no-store');
            header('Connection: keep-alive');
        }
    }

    /**
     * Security headers of every API response (whatever the web server): no
     * sniffing, no framing, no referrer, nothing to run in a JSON response.
     *
     * @return void
     */
    public static function securityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Cross-Origin-Resource-Policy: same-origin');
    }

    /**
     * Call the service.
     *
     * @param string $resource The resource class name (fully qualified)
     * @param string $method   The method name to call
     * @param array<mixed> $data The request data
     * @param bool   $raw      True if the method returns the response body itself
     * @param list<string> $changes The kinds of data written by the route (ApiRoute), bumped
     *                       after a successful call; the ones bumped by the method itself
     *                       (Changes::touch) are written even after a failure
     * @return string The JSON encoded response
     * @throws Exception If the response cannot be encoded in JSON (invalid UTF-8...)
     */
    public static function callService(string $resource, string $method, array $data, bool $raw = false, array $changes = []): string
    {
        try {
            $response = self::call($resource, $method, $data);
            Changes::touch(...$changes);
        } finally {
            // Before the response: the kinds bumped go in its headers
            Changes::flush();
        }
        return self::encode($resource, $method, $response, $raw);
    }

    /**
     * Call the method of a route with the parameters of the request.
     *
     * @param string $resource The resource class name (fully qualified)
     * @param string $method   The method name to call
     * @param array<mixed> $data The request data
     * @return mixed The result of the method
     * @throws Error If the method does not exist
     */
    private static function call(string $resource, string $method, array $data): mixed
    {
        // Logged with the short class name (Bank.get), as before the namespaces
        $name = substr((string)strrchr('\\' . $resource, '\\'), 1);
        // Only the names of the parameters: their values are household data (labels, amounts,
        // email...), written in full at the debug level only; at the debug level only too for
        // a quiet route (GET /health)
        Logger::routine("Calling : $name.$method", $data ? ['params' => array_keys($data)] : null);

        // Merge GET parameters with payload data
        $data = array_merge($_GET, $data);
        
        // Call the method with or without parameters (a route of ApiResolver: always callable)
        $callable = [$resource, $method];
        if (!is_callable($callable)) {
            throw new Error("Unknown service $name.$method", 404);
        }
        if (empty($data)) {
            $response = call_user_func($callable);
        } else {
            Logger::debug("Data", self::maskSensitive($data));
            $response = call_user_func_array($callable, $data);
        }
        return $response;
    }

    /**
     * Encode the result of a route.
     *
     * @param string $resource The resource class name (fully qualified)
     * @param string $method   The method name
     * @param mixed  $response The result of the method
     * @param bool   $raw      True if the method returns the response body itself
     * @return string The response body
     * @throws Exception If the response cannot be encoded in JSON (invalid UTF-8...)
     */
    private static function encode(string $resource, string $method, mixed $response, bool $raw): string
    {
        $name = substr((string)strrchr('\\' . $resource, '\\'), 1);
        if ($raw) {
            // Not logged: tokens (OAuth) and household data (MCP)
            Logger::debug("Result", strlen((string)$response) . " bytes");
            return (string)$response;
        }

        // The secrets of the answer (session, second factor) are not written
        Logger::debug("Result", is_array($response) && Config::get('log_level') === 'debug' ? self::maskSensitive($response) : $response);

        // Return JSON response with pretty print in debug mode
        $flags = Config::get('log_level') === 'debug' ? JSON_PRETTY_PRINT : 0;
        $json = json_encode(self::normalizeNumbers($response), $flags);
        if ($json === false) {
            // E.g. text that is not UTF-8 (charset of the database connection): an error,
            // not an empty answer that the clients would take for no data
            Logger::error("The response of $name.$method cannot be encoded in JSON: " . json_last_error_msg());
            throw new Exception("The response cannot be encoded in JSON");
        }
        return $json;
    }

    /**
     * Convert the numeric strings returned by MySQL (amounts, ids...) to JSON numbers,
     * like JSON_NUMERIC_CHECK, but keep as strings the identifiers that a number would
     * alter: leading zeros ("00087654321") or more digits than a number can hold exactly,
     * and the fields that are always texts (TEXT_FIELDS: "1.10" is not 1.1).
     *
     * @param mixed $value The response
     * @return mixed The response with numbers
     */
    public static function normalizeNumbers(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (!in_array($key, self::TEXT_FIELDS, true)) {
                    $value[$key] = self::normalizeNumbers($item);
                }
            }
            return $value;
        }
        if (is_string($value) && preg_match('/^-?(0|[1-9][0-9]{0,14})(\.[0-9]{1,15})?$/', $value)) {
            return str_contains($value, '.') ? (float)$value : (int)$value;
        }
        return $value;
    }

    /**
     * Mask sensitive values (passwords, tokens) before logging.
     *
     * @param array<mixed> $data The request data
     * @return array<mixed> The data with sensitive values masked
     */
    public static function maskSensitive(array $data): array
    {
        foreach ($data as $key => $value) {
            // settings: the bank credentials sent to woob, whatever their names;
            // file: an imported bank statement (household data, large);
            // datasets: the rows of an imported backup (household data, large)
            if (in_array(strtolower((string)$key), ['password', 'token', 'settings', 'admin_password', 'db_password',
                    'code', 'code_verifier', 'refresh_token', 'access_token', 'client_secret', 'file', 'smtp_password',
                    'datasets', 'push_api_key', 'api_key', 'mfa_token', 'secret', 'otpauth', 'recovery_codes'], true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = self::maskSensitive($value);
            }
        }
        return $data;
    }

    /**
     * Send a progress event via Server-Sent Events
     * @param string $message Progress message
     * @return void
     */
    public static function sendProgress(string $message): void
    {
        echo "data: " . $message . "\n\n";
        
        // Force immediate send
        if (function_exists('flush')) {
            flush();
        }
    }

    /**
     * Send an SSE comment as heartbeat to keep the connection alive.
     * SSE comments (lines starting with :) are ignored by EventSource clients
     * but keep proxies and TCP connections from timing out.
     *
     * @return void
     */
    public static function sendHeartbeat(): void
    {
        echo ": heartbeat\n\n";
        
        if (function_exists('flush')) {
            flush();
        }
    }

 }