<?php

/**
 * Logger.php
 *
 * Logger of Carbure: one JSON object per line (JSON Lines) with the time (ISO 8601,
 * milliseconds and time zone), the level, the id of the request, the address of the
 * client, the user, the HTTP method and path, the caller, the message and its context.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use DateTimeImmutable;

final class Logger {

    // Unique ID of the request (or of the script)
    private static ?string $UID = null;

    /**
     * Context of the entries: user once authenticated
     * @var array<string, mixed>
     */
    private static array $context = [];

    /**
     * Request of the entries: ip, method, path (built once)
     * @var array<string, string|null>|null
     */
    private static ?array $request = null;

    /**
     * Log buffer for batched writes
     * @var list<string>
     */
    private static array $buffer = [];
    
    // Maximum buffer size before auto-flush
    private static int $bufferSize = 50;
    
    // Cache for log file path
    private static ?string $logFilePath = null;
    
    // Shutdown handler registered flag
    private static bool $shutdownRegistered = false;

    // Quiet request (ApiRoute quiet: GET /health): its routine entries are written at the DEBUG level
    private static bool $quiet = false;

    // Log level priorities
    private const LEVELS = [
        'DEBUG'   => 0,
        'INFO'    => 1,
        'WARNING' => 2,
        'ERROR'   => 3,
    ];

    // Cached minimum log level
    private static ?int $minLevel = null;

    /** Name of a log file: carbure_[scope_]YYYYMMDD.log (the date is captured) */
    public const FILE_PATTERN = '/^carbure_[a-z0-9_]*?(\d{8})\.log$/';

    /**
     * Get the unique ID of the log.
     *
     * @return string The unique ID
     */
    public static function getUID(): string {
        if (self::$UID === null){
            // The id given by a proxy (X-Request-Id) follows the request, if it is safe
            $given = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? '');
            self::$UID = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $given) ? $given : bin2hex(random_bytes(8));
        }
        return self::$UID;
    }

    /**
     * Add a field to the following entries of the request (e.g. the user).
     *
     * @param string $key   The field
     * @param mixed  $value The value (null removes it)
     * @return void
     */
    public static function setContext(string $key, mixed $value): void
    {
        if ($value === null) {
            unset(self::$context[$key]);
        } else {
            self::$context[$key] = $value;
        }
    }

    /**
     * Mark the current request as quiet (route polled by the healthchecks): its routine
     * entries (the call, the access line of a success) are written at the DEBUG level,
     * its warnings and errors keep their level.
     *
     * @param bool $quiet True for a quiet request
     * @return void
     */
    public static function setQuiet(bool $quiet): void
    {
        self::$quiet = $quiet;
    }

    /**
     * Log a routine message of the request (the call of a route): INFO, DEBUG for a quiet
     * request (setQuiet).
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function routine(string $msg, mixed $data = null): void
    {
        self::log($msg, $data, self::$quiet ? 'DEBUG' : 'INFO');
    }

    /**
     * Address of the client. Behind a trusted proxy (a private or local address: the
     * nginx of the image, the reverse proxy of the NAS), the client given by
     * X-Forwarded-For (the last public address) or X-Real-IP; otherwise the peer, as
     * these headers can then be forged.
     *
     * @return string|null The address, null outside of an HTTP request
     */
    public static function clientIp(): ?string
    {
        $peer = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($peer === null || !self::isPrivate($peer)) {
            return $peer;
        }
        $forwarded = array_filter(array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')),
            fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false);
        foreach (array_reverse($forwarded) as $ip) {
            if (!self::isPrivate($ip)) {
                return $ip;
            }
        }
        $real = trim($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if (filter_var($real, FILTER_VALIDATE_IP) !== false) {
            return $real;
        }
        return $forwarded ? reset($forwarded) : $peer;
    }

    /**
     * Whether an address is private, local or reserved (a proxy of the installation).
     *
     * @param string $ip The address
     * @return bool
     */
    private static function isPrivate(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * Fields of the current HTTP request: ip, method and path (tokens of the URL masked).
     *
     * @return array<string, string|null>
     */
    private static function request(): array
    {
        if (self::$request === null) {
            self::$request = isset($_SERVER['REQUEST_METHOD']) ? [
                'ip' => self::clientIp(),
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => self::maskUrl($_SERVER['REQUEST_URI'] ?? ''),
            ] : [];
        }
        return self::$request;
    }

    /**
     * URL without its tokens (synchronization, MCP), which are never written.
     *
     * @param string $url The URL
     * @return string
     */
    public static function maskUrl(string $url): string
    {
        return preg_replace('/([?&](?:token|access_token|code)=)[^&]*/i', '$1***', $url) ?? $url;
    }

    /**
     * Email address shortened for the logs: its first letter and its domain (j***@example.org).
     *
     * @param string $email The email address
     * @return string The masked address
     */
    public static function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false || $at === 0 ? '***' : $email[0] . '***' . substr($email, $at);
    }

    /**
     * Write the end of the request: method, path, status and duration. Level INFO (DEBUG
     * for a quiet request, see setQuiet), or WARNING for a client error (4xx) and ERROR for
     * a server error (5xx).
     *
     * @return void
     */
    public static function access(): void
    {
        $status = (int)(http_response_code() ?: 200);
        $start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
        $duration = (int)round((microtime(true) - $start) * 1000);
        $request = self::request();
        $level = $status >= 500 ? 'ERROR' : ($status >= 400 ? 'WARNING' : (self::$quiet ? 'DEBUG' : 'INFO'));
        self::log(($request['method'] ?? '') . ' ' . ($request['path'] ?? '') . " $status", ['status' => $status, 'duration_ms' => $duration], $level);
    }

    /**
     * Get the minimum log level from configuration.
     *
     * @return int The minimum log level priority
     */
    private static function getMinLevel(): int
    {
        if (self::$minLevel === null) {
            $configured = strtoupper(Config::get('log_level') ?? 'INFO');
            self::$minLevel = self::LEVELS[$configured] ?? self::LEVELS['INFO'];
        }
        return self::$minLevel;
    }

    /**
     * Log a debug message in the log file.
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function debug(string $msg, mixed $data = null): void {
        self::log($msg, $data, "DEBUG");
    }

    /**
     * Log an info message in the log file.
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function info(string $msg, mixed $data = null): void {
        self::log($msg, $data, "INFO");
    }

    /**
     * Log a warning in the log file.
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function warn(string $msg, mixed $data = null): void {
        self::log($msg, $data, "WARNING");
    }

    /**
     * Log an error in the log file.
     *
     * @param string     $msg  The message to log
     * @param mixed|null $data The data to log
     * @return void
     */
    public static function error(string $msg, mixed $data = null): void {
        self::log($msg, $data, "ERROR");
    }

    /**
     * Log a message in the log file.
     *
     * @param string     $msg   The message to log
     * @param mixed|null $data  The data to log
     * @param string     $level The log level
     * @return void
     */
    private static function log(string $msg, mixed $data, string $level): void {

        // Skip if below minimum log level
        if (self::LEVELS[$level] < self::getMinLevel()) {
            return;
        }

        // One JSON object per line: the newlines of a message cannot fake another entry
        $entry = ['time' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'), 'level' => $level, 'uid' => self::getUID()]
            + self::request() + self::$context
            + ['caller' => self::getCaller(), 'message' => $msg];
        if ($data !== null) {
            $entry['context'] = $data;
        }
        $logLine = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";

        // Add to buffer
        self::$buffer[] = $logLine;
        
        // Register shutdown function on first log
        if (!self::$shutdownRegistered) {
            register_shutdown_function([self::class, 'flush']);
            self::$shutdownRegistered = true;
        }
        
        // Auto-flush if buffer is full
        if (count(self::$buffer) >= self::$bufferSize) {
            self::flush();
        }
    }

    /**
     * Get the caller file and function from the backtrace.
     *
     * @return string The caller info formatted as "File.php : Class::method"
     */
    private static function getCaller(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        $caller = $trace[3] ?? $trace[2] ?? [];
        $file = isset($caller['file']) ? basename($caller['file']) : '?';
        $function = $caller['function'] ?? '?';
        // Short class name (Bank::get): the namespace adds nothing to the file name
        $class = isset($caller['class']) ? substr((string)strrchr('\\' . $caller['class'], '\\'), 1) . '::' : '';

        return $file . "->" . $class . $function;
    }
    
    /**
     * Flush buffered logs to file.
     *
     * @return void
     */
    public static function flush(): void {
        if (empty(self::$buffer)) {
            return;
        }
        
        // Write all buffered logs at once
        $logFile = self::getLogFilePath();
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $content = implode('', self::$buffer);
        file_put_contents($logFile, $content, FILE_APPEND | LOCK_EX);
        
        // Clear buffer
        self::$buffer = [];
    }

    /**
     * Number of days the log files are kept (log_retention_days; 0 or absent: forever).
     *
     * @return int The number of days
     */
    public static function retentionDays(): int
    {
        return Config::has('log_retention_days') ? max(0, (int)Config::get('log_retention_days')) : 0;
    }

    /**
     * Directory of the log files.
     *
     * @return string The directory (data/logs)
     */
    public static function dir(): string
    {
        return Config::get('data_dir') . '/logs';
    }

    /**
     * The log files of the instance.
     *
     * @return array<string, string> Path of each file, by name
     */
    public static function files(): array
    {
        $files = [];
        foreach (glob(self::dir() . '/carbure_*.log') ?: [] as $path) {
            if (preg_match(self::FILE_PATTERN, basename($path))) {
                $files[basename($path)] = $path;
            }
        }
        return $files;
    }

    /**
     * Delete the log files older than the retention (log_retention_days), by the date
     * in their name (carbure_[scope_]YYYYMMDD.log).
     *
     * @param int|null $days Days to keep (default: the configuration); 0 keeps everything
     * @return list<string> The names of the deleted files
     */
    public static function purge(?int $days = null): array
    {
        $days ??= self::retentionDays();
        if ($days <= 0) {
            return [];
        }
        $limit = date('Ymd', Month::timestamp("-$days days"));
        $deleted = [];
        foreach (self::files() as $name => $path) {
            if (!preg_match(self::FILE_PATTERN, $name, $match)) {
                continue;
            }
            if ($match[1] < $limit && @unlink($path)) {
                $deleted[] = $name;
            }
        }
        if ($deleted) {
            self::info(count($deleted) . " log file(s) older than $days days deleted");
        }
        return $deleted;
    }

    /**
     * Get the log file path, building and caching it on first call.
     *
     * @return string The log file path
     */
    private static function getLogFilePath(): string
    {
        if (self::$logFilePath === null) {
            $postfix = date("Ymd");
            if (isset($GLOBALS['SCOPE'])) {
                $postfix = $GLOBALS['SCOPE'] . "_" . $postfix;
            }
            self::$logFilePath = self::dir() . "/carbure_$postfix.log";
        }

        return self::$logFilePath;
    }
    
    /**
     * Set buffer size for performance tuning
     * @param int $size Number of log entries before auto-flush
     * @return void
     */
    public static function setBufferSize(int $size): void {
        self::$bufferSize = max(1, $size);
    }
}
