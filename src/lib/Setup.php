<?php

/**
 * Setup.php
 *
 * Installation wizard of the portal: as long as the configuration file
 * (data/conf/<env>.ini) does not exist, the API only answers /api/setup:
 *
 *   GET  /api/setup           State of the installation and default values
 *   POST /api/setup/database  Test the database and tell what the installation will do
 *   POST /api/setup/install   Install or migrate the schema, create the first
 *                             administrator and write the configuration
 *
 * From the local network (a NAS at home), anyone reaching the portal can install
 * it, like most self-hosted applications. From Internet, or when data/conf/setup.code
 * exists, the POST requests require the setup code of this file (created on the
 * first request, and printed in the logs of the Docker container).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use InvalidArgumentException;
use RuntimeException;
use mysqli;
use mysqli_sql_exception;

final class Setup {

    private string $root;
    private string $configFile;
    private string $codeFile;
    private string $clientAddress;

    /**
     * @param string $root       Root of the project
     * @param string $configFile Configuration file to create
     * @param string $codeFile   File of the setup code
     * @param string $clientAddress IP address of the client, or the addresses of the
     *                              request separated by commas (proxy headers included)
     */
    public function __construct(string $root, string $configFile, string $codeFile, string $clientAddress = '127.0.0.1')
    {
        $this->root = $root;
        $this->configFile = $configFile;
        $this->codeFile = $codeFile;
        $this->clientAddress = $clientAddress;
    }

    /**
     * Every address of the current request: the peer, and the client given by a
     * reverse proxy (X-Forwarded-For, X-Real-IP, Forwarded). Behind a proxy of the
     * NAS, the peer is local but the client may come from Internet.
     *
     * @return string The addresses, separated by commas
     */
    public static function requestAddresses(): string
    {
        $addresses = [$_SERVER['REMOTE_ADDR'] ?? ''];
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP'] as $header) {
            if (!empty($_SERVER[$header])) {
                $addresses = array_merge($addresses, explode(',', $_SERVER[$header]));
            }
        }
        if (!empty($_SERVER['HTTP_FORWARDED']) && preg_match_all('/for="?\[?([0-9a-fA-F:.]+)/i', $_SERVER['HTTP_FORWARDED'], $matches)) {
            $addresses = array_merge($addresses, $matches[1]);
        }
        return implode(',', array_map('trim', $addresses));
    }

    /**
     * Answer the current HTTP request (the configuration does not exist).
     *
     * @param string $root Root of the project
     * @param string $env  Name of the configuration (prod)
     * @return void
     */
    public static function serve(string $root, string $env): void
    {
        $setup = new self($root, "$root/data/conf/$env.ini", "$root/data/conf/setup.code", self::requestAddresses());
        $path = preg_replace('#^/api#', '', (strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/')) ?? '/';
        $data = json_decode(file_get_contents('php://input') ?: '[]', true);

        [$status, $body] = $setup->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, is_array($data) ? $data : []);

        http_response_code($status);
        header_remove('X-Powered-By');
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Handle a request of the wizard.
     *
     * @param string $method HTTP method
     * @param string $path   Path without /api
     * @param array<mixed> $data JSON body
     * @return array{0: int, 1: array<string, mixed>} [HTTP status, response]
     */
    public function handle(string $method, string $path, array $data): array
    {
        try {
            return $this->route($method, $path, $data);
        } catch (SetupError $e) {
            return [$e->getCode(), ['error' => $e->getMessage(), 'code' => $e->getCode()]];
        } catch (mysqli_sql_exception $e) {
            return [400, ['error' => 'Database: ' . $e->getMessage(), 'code' => 400]];
        } catch (InvalidArgumentException | RuntimeException $e) {
            return [400, ['error' => $e->getMessage(), 'code' => 400]];
        }
    }

    /**
     * Answer of a route of the wizard (the errors are turned into responses by handle()).
     *
     * @param string       $method HTTP method
     * @param string       $path   Path without /api
     * @param array<mixed> $data   JSON body
     * @return array{0: int, 1: array<string, mixed>} [HTTP status, response]
     */
    private function route(string $method, string $path, array $data): array
    {
        if ($path === '/setup' && $method === 'GET') {
            return [200, $this->status()];
        }
        // Healthy while waiting for the installation (Docker HEALTHCHECK)
        if ($path === '/health' && $method === 'GET') {
            return [200, $this->health()];
        }
        if ($path === '/setup/database' && $method === 'POST') {
            $this->checkCode($data);
            return [200, $this->database($data)];
        }
        if ($path === '/setup/install' && $method === 'POST') {
            $this->checkCode($data);
            return [200, $this->install($data)];
        }
        return [503, ['error' => 'Carbure is not installed yet: open the portal to install it', 'code' => 503, 'setup' => true]];
    }

    /**
     * Health while waiting for the installation, with the version of Carbure and the
     * lowest version of the mobile app supported.
     *
     * @return array{status: string, version: string, min_mobile_version: string} status (setup), version, min_mobile_version
     */
    private function health(): array
    {
        $file = "$this->root/VERSION";
        $version = is_file($file) ? trim((string)file_get_contents($file)) : 'dev';
        return ['status' => 'setup', 'version' => preg_match('/^[0-9A-Za-z.+-]{1,40}$/', $version) ? $version : 'dev',
            'min_mobile_version' => MobileApp::MIN_VERSION];
    }

    /**
     * State of the wizard and default values (environment of the Docker container).
     *
     * @return array<string, mixed> setup, codeFile, defaults
     */
    private function status(): array
    {
        $codeRequired = $this->codeRequired();
        if ($codeRequired) {
            $this->code();
        }
        return [
            'setup' => true,
            'codeRequired' => $codeRequired,
            // Docker: the database password is given by the environment
            'dbPasswordFromEnvironment' => getenv('DB_PASSWORD') !== false && getenv('DB_PASSWORD') !== '',
            'codeFile' => 'data/conf/setup.code',
            'defaults' => [
                'db_host' => getenv('DB_HOST') ?: 'localhost',
                'db_port' => (int)(getenv('DB_PORT') ?: 3306),
                'db_name' => getenv('DB_NAME') ?: 'carbure',
                'db_user' => getenv('DB_USER') ?: 'carbure',
                'admin_user' => 'admin',
                'language' => in_array(getenv('LANGUAGE'), Messages::LANGUAGES, true) ? getenv('LANGUAGE') : 'fr',
            ],
        ];
    }

    /**
     * Connect to the database and tell what the installation will do.
     *
     * @param array<mixed> $data db_host, db_port, db_name, db_user, db_password
     * @return array{state: string, version: string|null, pending: list<string>, hasAdmin: bool}
     *         state (none|current|outdated), version, pending, hasAdmin
     */
    private function database(array $data): array
    {
        $db = $this->connect($data);
        return Installer::state($db, $this->root);
    }

    /**
     * Install: schema (created, or migrated after confirmation), first
     * administrator if none, configuration file; the setup code is then deleted.
     *
     * @param array<mixed> $data Database fields, admin_user, admin_password, admin_email,
     *                    language, backup_confirmed (to apply migrations), and for a new
     *                    database starter_categories (categories and rules of
     *                    sql/starter.json) and starter_insights (its insights too)
     * @return array{done: bool, version: string|null, username: string|null} done, version, username
     * @throws SetupError If a migration is not confirmed or the configuration cannot be written
     */
    private function install(array $data): array
    {
        $db = $this->connect($data);
        $state = Installer::state($db, $this->root);
        $needsAdmin = !$state['hasAdmin'];
        $this->checkInstall($state['state'], $needsAdmin, $data);

        $this->installSchema($db, $state['state'], $data);

        $username = null;
        if ($needsAdmin) {
            $username = trim((string)($data['admin_user'] ?? 'admin'));
            Installer::createAdmin($db, $username, $data['admin_password'], $data['admin_email'] ?? null, $data['language'] ?? 'fr');
        }

        $this->writeConfig($data);
        @unlink($this->codeFile);

        return ['done' => true, 'version' => (new Migrator($db, "$this->root/sql/migrations"))->version(), 'username' => $username];
    }

    /**
     * Check that the installation can be done, before changing anything: migrations
     * confirmed, administrator password long enough, configuration folder writable.
     *
     * @param string       $state      State of the database (none|current|outdated)
     * @param bool         $needsAdmin No administrator yet: one is created
     * @param array<mixed> $data       Fields of the request
     * @return void
     * @throws SetupError If the installation cannot be done
     */
    private function checkInstall(string $state, bool $needsAdmin, array $data): void
    {
        if ($state === 'outdated' && empty($data['backup_confirmed'])) {
            throw new SetupError("Confirm that the database is saved before applying the migrations", 409);
        }
        if ($needsAdmin && strlen((string)($data['admin_password'] ?? '')) < 8) {
            throw new SetupError("The administrator password must contain at least 8 characters", 400);
        }
        @mkdir(dirname($this->configFile), 0750, true);
        if (!is_writable(dirname($this->configFile)) && !is_writable($this->configFile)) {
            throw new SetupError("The web server cannot write in data/conf/: give it the write permission on data/", 500);
        }
    }

    /**
     * Schema of the database: created (with the starter data if asked), migrated, or
     * only completed with the default category when it is current.
     *
     * @param mysqli       $db    The connection
     * @param string       $state State of the database (none|current|outdated)
     * @param array<mixed> $data  Fields of the request (starter_categories, starter_insights, language)
     * @return void
     */
    private function installSchema(mysqli $db, string $state, array $data): void
    {
        if ($state === 'none') {
            Installer::installSchema($db, $this->root);
            if (!empty($data['starter_categories'])) {
                Installer::starter($db, $this->root, $data['language'] ?? 'fr', !empty($data['starter_insights']));
            }
        } elseif ($state === 'outdated') {
            Installer::migrate($db, $this->root);
        } else {
            Installer::defaultCategory($db);
        }
    }

    /**
     * Command of woob written by the installation: CARBURE_WOOB_PATH (Docker image), else
     * woob keeping the banks (and their credentials) in data/woob.
     *
     * @param string $root Root directory of Carbure
     * @return string The command
     */
    public static function defaultWoobPath(string $root): string
    {
        return getenv('CARBURE_WOOB_PATH') ?: 'env HOME=' . $root . '/data/woob woob';
    }

    /**
     * Write the configuration file (database, woob command).
     *
     * @param array<mixed> $data Database fields of the request
     * @return void
     * @throws SetupError If the file cannot be written
     */
    private function writeConfig(array $data): void
    {
        [$ini] = Installer::config($this->root, [
            'db_hostname' => $data['db_host'],
            'db_port' => (string)(int)($data['db_port'] ?? 3306),
            'db_username' => $data['db_user'],
            'db_password' => $this->password($data),
            'db_name' => $data['db_name'],
            'woob_path' => self::defaultWoobPath($this->root),
        ]);
        if (@file_put_contents($this->configFile, $ini) === false) {
            throw new SetupError("Cannot write the configuration file: check the permissions of data/conf/", 500);
        }
        @chmod($this->configFile, 0640);
    }

    /**
     * Connect with the database fields of the request.
     *
     * @param array<mixed> $data db_host, db_port, db_name, db_user, db_password
     * @return mysqli The connection
     */
    private function connect(array $data): mysqli
    {
        foreach (['db_host', 'db_name', 'db_user'] as $field) {
            if (empty($data[$field]) || !is_string($data[$field])) {
                throw new SetupError("Missing field: $field", 400);
            }
        }
        return Installer::connect($data['db_host'], (int)($data['db_port'] ?? 3306), $data['db_name'], $data['db_user'], $this->password($data));
    }

    /**
     * Password of the database: the one typed, or the one of the environment
     * (Docker) for the database of the environment only.
     *
     * @param array<mixed> $data Database fields
     * @return string The password
     */
    private function password(array $data): string
    {
        $typed = (string)($data['db_password'] ?? '');
        $environment = getenv('DB_PASSWORD');
        if ($typed === '' && $environment !== false
            && $data['db_host'] === (getenv('DB_HOST') ?: 'localhost')
            && $data['db_user'] === (getenv('DB_USER') ?: 'carbure')
            && $data['db_name'] === (getenv('DB_NAME') ?: 'carbure')) {
            return $environment;
        }
        return $typed;
    }

    /**
     * The setup code, created on first use.
     *
     * @return string The code
     * @throws SetupError If the code cannot be written
     */
    public function code(): string
    {
        @mkdir(dirname($this->codeFile), 0750, true);
        // A file created by hand may be empty: a new code is written then
        if (is_file($this->codeFile) && trim((string)file_get_contents($this->codeFile)) !== '') {
            return trim(File::read($this->codeFile));
        }
        // No ambiguous characters (0/O, 1/I/L): it is typed by hand
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 12; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        if (@file_put_contents($this->codeFile, $code . "\n") === false) {
            throw new SetupError("Cannot write data/conf/setup.code: the web server must be able to write in data/conf/", 500);
        }
        @chmod($this->codeFile, 0600);
        // Shown in the logs of the server (docker logs)
        error_log("Carbure: installation code $code (also in data/conf/setup.code)");
        return $code;
    }

    /**
     * The setup code is required from Internet, or when the file exists (set by
     * the administrator of the server, e.g. the Docker container).
     *
     * @return bool True if required
     */
    private function codeRequired(): bool
    {
        if (is_file($this->codeFile)) {
            return true;
        }
        // Local only if every address of the request (peer and proxy headers) is private
        return !Network::isLocal($this->clientAddress);
    }

    /**
     * Check the setup code of a request (slowed down when wrong).
     *
     * @param array<mixed> $data The request with code
     * @return void
     * @throws SetupError If the code is wrong
     */
    private function checkCode(array $data): void
    {
        if (!$this->codeRequired()) {
            return;
        }
        $given = strtoupper(preg_replace('/\s+/', '', (string)($data['code'] ?? '')) ?? '');
        if ($given === '' || !hash_equals($this->code(), $given)) {
            usleep(500000);
            throw new SetupError("Wrong setup code: see data/conf/setup.code on the server, or the logs of the Docker container", 403);
        }
    }
}

/**
 * Error of the wizard, with its HTTP status as code.
 */
final class SetupError extends RuntimeException {
}
