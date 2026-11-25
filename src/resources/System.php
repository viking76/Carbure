<?php

/**
 * System.php
 *
 * State of the instance for the administrators: version of the database schema
 * and migrations to apply, applied from the portal (no command to run).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\File;
use Carbure\Lib\Installer;
use Carbure\Lib\Logger;
use Carbure\Lib\Messages;
use Carbure\Lib\Migrator;
use Carbure\Lib\MobileApp;
use Carbure\Lib\ReleaseCheck;
use Error;
use Exception;
use RuntimeException;

final class System {

    /**
     * Configuration file changed by rotateJwtSecret (tests use a copy)
     */
    public static ?string $configFile = null;

    /**
     * Replace the secret of the sessions by a new random one (administrators):
     * every user, portal and iOS app, must log in again.
     *
     * @return bool True if replaced
     * @throws Exception If the configuration file cannot be written
     */
    #[ApiRoute('/system/jwt-secret', method: 'POST', admin: true, changes: [])]
    public static function rotateJwtSecret(): bool
    {
        $file = self::$configFile ?? Config::get('config_file');
        if (!is_file($file) || !is_writable($file)) {
            throw new Exception("The configuration file cannot be written by the web server");
        }
        $ini = Installer::setIni(File::read($file), 'jwtsecret', bin2hex(random_bytes(32)));
        if (file_put_contents($file, $ini) === false) {
            throw new Exception("The configuration file cannot be written by the web server");
        }
        Logger::warn("jwtsecret renewed: every session must log in again");
        return true;
    }

    /**
     * Check if the sessions use the example secret of the configuration
     * (administrators are asked to renew it).
     *
     * @return bool True if the secret is weak
     */
    public static function weakJwtSecret(): bool
    {
        $secret = (string)(Config::has('jwtsecret') ? Config::get('jwtsecret') : '');
        return strlen($secret) < 32 || in_array($secret, ['secret', 'changeme', 'change-me'], true);
    }

    /**
     * Health of the instance, for the Docker HEALTHCHECK, supervision tools and the
     * mobile app (no authentication, no data): the database answers. Polled every few
     * minutes: its successful calls are logged at the DEBUG level only (quiet).
     *
     * @return array<string, string|bool|null> status (ok), version (of Carbure), database (ok), schema (version),
     *               min_mobile_version (lowest version of the mobile app supported, MobileApp::MIN_VERSION),
     *               currency (of the amounts: setting currency, ISO 4217 code, EUR by default),
     *               demo (true, only on a demonstration instance: setting demo, Docker image carbure:demo)
     * @throws Exception If the database does not answer (HTTP 500)
     */
    #[ApiRoute('/health', method: 'GET', public: true, quiet: true)]
    public static function health(): array
    {
        Db::query("SELECT 1");
        $health = ['status' => 'ok', 'version' => self::version(), 'database' => 'ok', 'schema' => (new Migrator(Db::getConnection()))->version(),
            'min_mobile_version' => MobileApp::MIN_VERSION, 'currency' => Messages::currency()];
        // Demonstration instance: the portal shows its public credentials on the login screen
        return Config::has('demo') ? $health + ['demo' => true] : $health;
    }

    /**
     * Version of Carbure: the VERSION file written by the release build ("dev" otherwise).
     *
     * @return string The version
     */
    public static function version(): string
    {
        $file = dirname(__DIR__, 2) . '/VERSION';
        $version = is_file($file) ? trim((string)file_get_contents($file)) : '';
        return preg_match('/^[0-9A-Za-z.+-]{1,40}$/', $version) ? $version : 'dev';
    }

    /**
     * Log files of the instance (administrators), newest first.
     *
     * @return list<array{name: string, size: int|false, modified: string}> The files: name, size (bytes), modified (Y-m-d H:i:s)
     */
    #[ApiRoute('/system/logs', method: 'GET', admin: true)]
    public static function logs(): array
    {
        $files = [];
        foreach (Logger::files() as $name => $file) {
            $files[] = ['name' => $name, 'size' => filesize($file), 'modified' => date('Y-m-d H:i:s', (int)filemtime($file))];
        }
        usort($files, fn($a, $b) => strcmp($b['modified'], $a['modified']) ?: strcmp($b['name'], $a['name']));
        return $files;
    }

    /**
     * How long the log files are kept (administrators): they are deleted at the end
     * of the bank synchronization.
     *
     * @return array{days: int} days (0: kept forever)
     */
    #[ApiRoute('/system/logs/retention', method: 'GET', admin: true)]
    public static function logRetention(): array
    {
        return ['days' => Logger::retentionDays()];
    }

    /**
     * Entries of a log file (administrators), newest first. Only the end of a large
     * file is read.
     *
     * @param string      $file   The file name (carbure_*.log)
     * @param string|null $level  Minimum level: DEBUG, INFO, WARN or ERROR
     * @param string|null $search Text to find in the entries (or a request uid)
     * @param int|string  $limit  Maximum number of entries (1 to 1000; query string)
     * @return array{file: string, entries: list<array<string, mixed>>, truncated: bool} file, entries [{time, level, uid, caller, message}], truncated
     * @throws Error If the file name is invalid or the file does not exist
     */
    #[ApiRoute('/system/logs/entries', method: 'GET', admin: true)]
    public static function logEntries(string $file, ?string $level = null, ?string $search = null, int|string $limit = 200): array
    {
        // A file of the logs directory only: no path
        if (!preg_match(Logger::FILE_PATTERN, (string)$file)) {
            throw new Error("Invalid log file", 400);
        }
        $path = Logger::dir() . '/' . $file;
        if (!is_file($path)) {
            throw new Error("Log file not found", 404);
        }
        $limit = max(1, min(1000, (int)$limit));
        // WARN: the level of the filter of the portal, WARNING: the one written
        $levels = ['DEBUG' => 0, 'INFO' => 1, 'WARN' => 2, 'WARNING' => 2, 'ERROR' => 3];
        $minimum = $levels[strtoupper((string)$level)] ?? 0;

        [$content, $truncated] = self::tail($path);
        $entries = [];
        foreach (array_reverse(self::logParts($content)) as $part) {
            $entry = self::logEntry($part);
            if ($entry === null || ($levels[$entry['level']] ?? 0) < $minimum) {
                continue;
            }
            if ($search !== null && $search !== '' && stripos($part, (string)$search) === false) {
                continue;
            }
            // Session (JWT) and agent tokens are never shown, even in the debug entries
            $entry['message'] = preg_replace(['/eyJ[\w-]+\.[\w-]+\.[\w-]+/', '/cbt_\w+/'], ['eyJ***', 'cbt_***'], $entry['message']);
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }

        return ['file' => $file, 'entries' => $entries, 'truncated' => $truncated];
    }

    /**
     * The last 2 MB of a log file at most.
     *
     * @param string $path The log file
     * @return array{string, bool} Its end, and whether its beginning is left out
     * @throws Error If the file cannot be read (500)
     */
    private static function tail(string $path): array
    {
        $size = (int)filesize($path);
        $read = min($size, 2 * 1024 * 1024);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new Error("Log file not readable", 500);
        }
        fseek($handle, $size - $read);
        $content = $read > 0 ? (string)fread($handle, $read) : '';
        fclose($handle);
        return [$content, $read < $size];
    }

    /**
     * Entries of a log file: a JSON line, or, in the files written before, a text starting
     * with "yy:mm:dd HH:MM:SS : LEVEL : uid : caller : message" (on several lines).
     *
     * @param string $content The content of the file
     * @return list<string> The text of each entry
     */
    private static function logParts(string $content): array
    {
        return preg_split('/^(?=\{"time"|\d{2}:\d{2}:\d{2} \d{2}:\d{2}:\d{2} : [A-Z]+ : )/m', $content) ?: [];
    }

    /**
     * An entry of a log file as shown in the portal.
     *
     * @param string $part A JSON line, or a text entry of the files written before
     * @return array<string, mixed>|null time (Y-m-d H:i:s), level, uid, caller, message (with its
     *                                    context), and for a JSON entry ip, user, method, path
     */
    private static function logEntry(string $part): ?array
    {
        $part = rtrim($part);
        if (str_starts_with($part, '{')) {
            $json = json_decode($part, true);
            if (!is_array($json) || !isset($json['time'], $json['level'])) {
                return null;
            }
            $message = (string)($json['message'] ?? '');
            if (array_key_exists('context', $json)) {
                $message .= "\n" . json_encode($json['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $time = strtotime($json['time']);
            return [
                'time' => $time ? date('Y-m-d H:i:s', $time) : (string)$json['time'],
                'level' => (string)$json['level'],
                'uid' => (string)($json['uid'] ?? ''),
                'caller' => (string)($json['caller'] ?? ''),
                'message' => $message,
                'ip' => $json['ip'] ?? null,
                'user' => $json['user'] ?? null,
                'method' => $json['method'] ?? null,
                'path' => $json['path'] ?? null,
            ];
        }
        if (!preg_match('/^(\d{2}):(\d{2}):(\d{2}) (\d{2}:\d{2}:\d{2}) : ([A-Z]+) : ([^ ]*) : ([^ ]*) : (.*)$/s', $part, $m)) {
            return null;
        }
        return ['time' => "20{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}", 'level' => $m[5], 'uid' => $m[6], 'caller' => $m[7], 'message' => $m[8]];
    }

    /**
     * Version of the database schema and migrations to apply (administrators).
     *
     * @return array<string, mixed> version, pending
     */
    #[ApiRoute('/system/schema', method: 'GET', admin: true)]
    public static function schema(): array
    {
        $migrator = new Migrator(Db::getConnection());
        return ['version' => $migrator->version(), 'pending' => $migrator->pending(), 'weakJwtSecret' => self::weakJwtSecret()];
    }

    /**
     * Whether a newer release of Carbure is published (administrators), from the latest
     * release of GitHub, asked at most once a day (setting update_check, on by default).
     *
     * @return array<string, mixed> enabled, current (this version), latest (null if
     *               unknown), url (its release notes), available (latest is newer),
     *               checked_at (last check, null if never)
     */
    #[ApiRoute('/system/release', method: 'GET', admin: true)]
    public static function release(): array
    {
        return ReleaseCheck::status(self::version());
    }

    /**
     * Apply the pending migrations (administrators).
     *
     * @return array<string, mixed> version, applied
     * @throws Exception If a migration fails
     */
    #[ApiRoute('/system/migrate', method: 'POST', admin: true, changes: [])]
    public static function migrate(): array
    {
        $migrator = new Migrator(Db::getConnection());
        try {
            $applied = $migrator->migrate(fn($message) => Logger::info("Migration $message"));
        } catch (RuntimeException $e) {
            throw new Exception($e->getMessage());
        }
        return ['version' => $migrator->version(), 'applied' => $applied];
    }
}
