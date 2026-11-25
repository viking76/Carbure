<?php

/**
 * Woob.php
 *
 * Woob integration utility class
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Error;
use Exception;

final class Woob {

    /**
     * Seconds of silence from woob before sending an SSE heartbeat
     */
    private const HEARTBEAT_INTERVAL = 15;

    /**
     * Options of woob for each level of woob_logging (woob 3.7: -d, -v, -q, warnings by
     * default). Never "--logging <level>": woob expands it to --logging-file and writes
     * its logs to a file named after the level in the working directory.
     */
    private const LOGGING_OPTIONS = [
        'debug' => ' --debug', 'info' => ' --verbose', 'warning' => '', 'error' => ' --quiet', 'critical' => ' --quiet',
    ];

    /** Log line of woob below the warnings ("2026-10-12 20:40:53,390:DEBUG:woob.config:...") */
    private const LOG_LINE = '/^(\S+ \S+:)?(DEBUG|INFO):/';

    /** An error reported by woob on stderr (a "Bug(<backend>): ..." of a module included) */
    private const ERROR_LINE = '/error|exception|traceback|unable|no module|^Bug\(/im';

    /** Name of a woob module */
    public const MODULE_NAME = '/^[a-z0-9_]{1,50}$/';

    /** Name of a woob backend: never starting with "-", which woob would read as an option */
    public const BACKEND_NAME = '/^[a-z0-9_][a-z0-9_-]{0,49}$/';

    /**
     * Value of a setting that cannot be written in the configuration file of woob: on more
     * than one line, or between backquotes (woob runs it as a shell command to get the value)
     */
    private const UNSAFE_SETTING = '/[\x00-\x1f\x7f]|^`(.*`)?$/s';

    /** Hint woob adds after an error: not its cause */
    private const DEBUG_HINT = '/^Use --debug option to print backtraces/';

    /**
     * Command that runs woob, after making sure that woob will accept to start: it
     * refuses a configuration file of the banks (logins and passwords) readable by
     * the group or the other users, as a file copied into a NAS shared folder often is.
     * Not on a first run of woob before its repository is trusted: woob would trust the
     * keyring of its repository by itself.
     *
     * @param bool $refresh The command refreshes the repositories itself, once trusted by
     *                      an administrator (WoobRepository::update())
     * @return string The command (woob_path of the configuration)
     * @throws Exception If the file stays readable by other users (ACL of the folder)
     * @throws Error If the woob repository must be trusted first (409)
     */
    public static function path(bool $refresh = false): string
    {
        if (!$refresh) {
            WoobRepository::requireTrustBeforeFirstRun();
        }
        $woob_path = Config::get('woob_path');
        $home = self::home();
        $dir = rtrim((string)$home, '/') . '/.config/woob';
        if ($home && is_dir($dir)) {
            if ((fileperms($dir) & 0077) !== 0) {
                @chmod($dir, 0700);
            }
            $backends = "$dir/backends";
            if (is_file($backends) && (fileperms($backends) & 0077) !== 0) {
                @chmod($backends, 0600);
                clearstatcache(true, $backends);
                if ((fileperms($backends) & 0077) !== 0) {
                    throw new Exception("$backends is readable by other users and its permissions cannot be changed "
                        . "(ACL of a NAS shared folder?): allow only its owner to read it (chmod 600)");
                }
            }
        }
        // An unset woob_path gives an empty command, as before
        return (string)$woob_path;
    }

    /**
     * Home directory of woob (its configuration, keyrings and modules): the HOME given in
     * the command (Docker: "env HOME=/data/woob woob"), else the one of PHP.
     *
     * @return string|null The directory, null if unknown
     */
    public static function home(): ?string
    {
        $woob_path = (string)Config::get('woob_path');
        $home = preg_match('/\bHOME=(\S+)/', $woob_path, $m) ? trim($m[1], '"\'') : getenv('HOME');
        return $home ? (string)$home : null;
    }

    /**
     * Whether a woob error asks a two-factor authentication, which woob only starts
     * in interactive mode (a user who waits for it in the portal).
     *
     * @param string|null $error The error message
     * @return bool
     */
    public static function needsTwoFactor(?string $error): bool
    {
        return (bool)preg_match('/interactive mode to perform a two-factor authentication/i', (string)$error);
    }

    /**
     * Call Woob to get the list of transactions from a bank.
     * Uses proc_open to send SSE heartbeats while waiting for woob to finish,
     * preventing proxy/client timeouts on long-running commands.
     *
     * In interactive mode, the output of woob is a pseudo-terminal (proc_open "pty"): it may then
     * start a two-factor authentication and wait for its validation in the bank app,
     * and each message it writes (other than the data) is given to $onMessage.
     *
     * When woob_debug is enabled, each line of the error output of woob (its debug logs:
     * requests to the bank, Python traceback...) is given live to $onDebug.
     *
     * @param string        $type        The type of transactions to get (coming or history)
     * @param string        $bankId      The bank account identifier
     * @param bool          $interactive Allow woob to ask a two-factor authentication
     * @param callable|null $onMessage   Receives each message of woob (interactive mode)
     * @param callable|null $onDebug     Receives each line of the debug logs of woob (woob_debug)
     * @param callable|null $onCommand   Receives the system command run (shown in the sync log)
     * @return list<mixed>|null The transaction data or null if no data
     * @throws Exception If woob fails, or reports an error without returning data
     */
    public static function getBankData(string $type, string $bankId, bool $interactive = false, ?callable $onMessage = null, ?callable $onDebug = null, ?callable $onCommand = null): ?array
    {
        $command = self::buildCommand($type, $bankId);
        Logger::debug("Calling woob : $command");
        if ($onCommand !== null) {
            $onCommand($command);
        }

        $stderr = '';
        $stdout = self::executeWithHeartbeat($command, $stderr, true, $interactive ? $onMessage : null,
            Config::get('woob_debug') ? $onDebug : null, $interactive);
        $data = self::parseOutput($type, $stdout);
        self::failOnEmptyWithError($data, $stderr, "woob returned no data");

        return $data;
    }

    /**
     * List the bank accounts of every configured woob backend, or of one of them.
     *
     * In interactive mode (a stream route: SSE heartbeats while waiting), the output of woob
     * is a pseudo-terminal: it may start a two-factor authentication, as on the first
     * connection to a bank (#299), and each message it writes is given to $onMessage.
     *
     * @param string|null   $backend     Only the accounts of this woob backend (-b), else of every one
     * @param bool          $interactive Allow woob to ask a two-factor authentication
     * @param callable|null $onMessage   Receives each message of woob (interactive mode)
     * @return list<array<mixed>> The accounts: id ("<account>@<backend>"), label, balance, currency...
     * @throws Exception If woob fails or returns nothing while reporting an error
     */
    public static function listAccounts(?string $backend = null, bool $interactive = false, ?callable $onMessage = null): array
    {
        $woob_path = self::path();
        $only = $backend === null ? '' : ' -b ' . escapeshellarg($backend);
        $command = "$woob_path bank list$only -f json" . self::loggingOptions();
        Logger::debug("Calling woob : $command");

        // Plain JSON request: no Server-Sent Events heartbeat in the output, unless streamed
        $stderr = '';
        $stdout = self::executeWithHeartbeat($command, $stderr, $interactive, $interactive ? $onMessage : null, null, $interactive);
        $accounts = self::parseOutput('accounts', $stdout);
        self::failOnEmptyWithError($accounts, $stderr, "woob returned no account");

        return array_values(array_filter($accounts ?? [], fn($a) => is_array($a) && !empty($a['id'])));
    }

    /**
     * First step of a synchronization (#43): list the accounts of the backend of an account
     * and check that the bank gives this one. Some modules (creditmutuel) answer "Account not
     * found" to a history asked before any list.
     *
     * Like getBankData: SSE heartbeats while waiting, and in interactive mode a pseudo-terminal
     * (a two-factor authentication may be asked by this first call).
     *
     * @param string        $bankId      The bank account identifier (<account>@<backend>)
     * @param bool          $interactive Allow woob to ask a two-factor authentication
     * @param callable|null $onMessage   Receives each message of woob (interactive mode)
     * @param callable|null $onDebug     Receives each line of the debug logs of woob (woob_debug)
     * @param callable|null $onCommand   Receives the system command run (shown in the sync log)
     * @return array<mixed> The account as listed by woob
     * @throws Exception If woob fails, or the account is not in the list
     */
    public static function findAccount(string $bankId, bool $interactive = false, ?callable $onMessage = null, ?callable $onDebug = null, ?callable $onCommand = null): array
    {
        $backend = substr((string)strrchr($bankId, '@'), 1);
        if (str_starts_with($bankId, '-') || !preg_match(self::BACKEND_NAME, $backend)) {
            throw new Exception("Invalid bank account $bankId");
        }
        // Only their id: the check needs nothing else of the accounts
        $command = self::path() . " bank list -b " . escapeshellarg($backend) . " -f json -s id" . self::loggingOptions();
        Logger::debug("Calling woob : $command");
        if ($onCommand !== null) {
            $onCommand($command);
        }

        $stderr = '';
        $stdout = self::executeWithHeartbeat($command, $stderr, true, $interactive ? $onMessage : null,
            Config::get('woob_debug') ? $onDebug : null, $interactive);
        $accounts = self::parseOutput('accounts', $stdout) ?? [];
        self::failOnEmptyWithError($accounts, $stderr, "woob returned no account");

        foreach ($accounts as $account) {
            if (is_array($account) && ($account['id'] ?? null) === $bankId) {
                return $account;
            }
        }
        $count = count(array_filter($accounts, fn($a) => is_array($a) && !empty($a['id'])));
        throw new Exception("Account $bankId not in the $count account(s) listed by woob for $backend");
    }

    /**
     * List the bank backends configured in woob (only their name and module:
     * the configuration, which contains the bank login, is never returned).
     *
     * @return list<array{name: mixed, module: mixed}> The backends (names given by woob)
     */
    public static function listBackends(): array
    {
        $woob_path = self::path();
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config list CapBank -f json", $stderr, false);

        $backends = [];
        foreach (self::parseOutput('backends', $stdout) ?? [] as $row) {
            if (is_array($row) && !empty($row['Name'])) {
                $backends[] = ['name' => $row['Name'], 'module' => $row['Module'] ?? $row['Name']];
            }
        }
        usort($backends, fn($a, $b) => strcmp($a['name'], $b['name']));

        return $backends;
    }

    /**
     * List the bank modules supported by woob (cached for a day: the list only
     * changes with woob updates).
     *
     * @return list<array{module: mixed, description: mixed}> The modules (names given by woob)
     */
    public static function listBankModules(): array
    {
        $cacheKey = 'carbure_woob_bank_modules';
        if (self::apcuEnabled()) {
            $cached = apcu_fetch($cacheKey, $found);
            if ($found) {
                return $cached;
            }
        }

        $modules = self::fetchBankModules();

        if ($modules && self::apcuEnabled()) {
            apcu_store($cacheKey, $modules, 86400);
        }

        return $modules;
    }

    /**
     * Whether the APCu cache can be used.
     *
     * @return bool
     */
    private static function apcuEnabled(): bool
    {
        return function_exists('apcu_fetch') && ini_get('apc.enabled');
    }

    /**
     * Ask woob for the bank modules it supports, sorted by name.
     *
     * @return list<array{module: mixed, description: mixed}> The modules (names given by woob)
     */
    private static function fetchBankModules(): array
    {
        $woob_path = self::path();
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config modules CapBank -f json", $stderr, false);

        $modules = [];
        foreach (self::parseOutput('modules', $stdout) ?? [] as $row) {
            if (is_array($row) && !empty($row['Name'])) {
                $modules[] = ['module' => $row['Name'], 'description' => $row['Description'] ?? ''];
            }
        }
        usort($modules, fn($a, $b) => strcasecmp($a['description'] ?: $a['module'], $b['description'] ?: $b['module']));

        return $modules;
    }

    /**
     * Settings asked by a woob bank module (login, password, website...), to
     * build the form of the portal. Nothing secret is returned.
     *
     * @param string $module The woob module (e.g. bnp)
     * @return array{module: mixed, description: string, fields: list<array{key: string, label: string,
     *               description: string, default: string, required: bool, masked: bool, regexp: string|null,
     *               choices: list<array{value: string, label: string}>}>} The module and its fields
     * @throws Exception If woob does not know the module
     * @throws Error If the module must be installed before the woob repository is trusted (409)
     */
    public static function moduleFields(string $module): array
    {
        $info = self::moduleInfo($module);
        // woob only describes the settings of an installed module: install it first
        if (!isset($info['config'])) {
            self::installModule($module);
            $info = self::moduleInfo($module);
        }

        $fields = [];
        foreach (($info['config'] ?? []) as $key => $field) {
            $choices = [];
            $given = (array)($field['choices'] ?? []);
            // woob gives either {value: label} or a list of values: decided for the whole
            // field, PHP turning numeric keys ({"14707": "Alsace…"}) into ints (#327)
            $values = array_is_list($given);
            foreach ($given as $value => $label) {
                $choices[] = $values && !is_array($label)
                    ? ['value' => (string)$label, 'label' => (string)$label]
                    : ['value' => (string)$value, 'label' => (string)$label];
            }
            // Booleans are offered as y/n choices: false must stay "n", not the first choice
            $default = $field['default'] ?? '';
            if (is_bool($default)) {
                $default = $default ? 'y' : 'n';
            }
            $fields[] = [
                'key' => (string)$key,
                'label' => (string)($field['label'] ?? $key),
                'description' => (string)($field['description'] ?? ''),
                'default' => is_scalar($default) ? (string)$default : '',
                'required' => !empty($field['required']),
                'masked' => !empty($field['masked']),
                'regexp' => is_string($field['regexp'] ?? null) ? $field['regexp'] : null,
                'choices' => $choices,
            ];
        }

        return ['module' => $info['name'], 'description' => (string)($info['description'] ?? ''), 'fields' => $fields];
    }

    /**
     * Description of a woob module (with its settings once installed).
     *
     * @param string $module The woob module
     * @return array<mixed> The description given by "woob config info"
     * @throws Exception If woob does not know the module
     */
    private static function moduleInfo(string $module): array
    {
        $woob_path = self::path();
        $stderr = '';
        $stdout = self::executeWithHeartbeat("$woob_path config info " . escapeshellarg($module) . " -f json", $stderr, false);
        foreach (self::parseOutput('module', $stdout) ?? [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                return $row;
            }
        }
        throw new Exception("woob does not know the module $module" . self::lastLine($stderr ?: $stdout));
    }

    /**
     * Install a woob module: "config add" installs it, then stops at the first
     * setting it asks for (no input). A backend created anyway (module without
     * settings) is removed.
     *
     * Not before the keyring of the woob repository is trusted: woob checks the signature
     * of a module with it ("No keyring found" otherwise), and would trust it by itself on
     * a first run.
     *
     * @param string $module The woob module
     * @return void
     * @throws Error If the woob repository is not trusted yet (409), or woob cannot load the module (422)
     */
    private static function installModule(string $module): void
    {
        if (!WoobRepository::isTrusted()) {
            throw new Error(WoobRepository::TRUST_FIRST . ", to install the module $module", 409);
        }
        $woob_path = self::path();
        $probe = 'carbure_install_probe';
        $process = proc_open("$woob_path config add " . escapeshellarg($module) . ' ' . escapeshellarg($probe) . ' < /dev/null',
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return;
        }
        $stdout = (string)stream_get_contents($pipes[1]);
        $stderr = (string)stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (stripos($stdout, 'successfully added') !== false) {
            self::configRemove($probe);
        }
        self::checkModuleLoaded($module, "$stdout\n$stderr");
    }

    /**
     * Check that woob could load a module it installed: a module needing a Python package
     * missing from the environment of woob is refused ("Unable to load module ...: Module
     * requires python package ... but not installed"), and woob then describes no setting.
     *
     * @param string $module The woob module
     * @param string $output What woob wrote while installing it
     * @return void
     * @throws Error If woob cannot load the module (422, with the cause given by woob)
     */
    private static function checkModuleLoaded(string $module, string $output): void
    {
        if (preg_match('/Unable to load module[^\r\n]*/', $output, $match)) {
            Logger::warn("woob cannot load the module $module: $match[0]");
            throw new Error("woob cannot load the bank module $module: " . rtrim($match[0], '. ')
                . '. Its Python packages are missing from the environment of woob', 422);
        }
    }

    /**
     * Configure a bank backend in woob with the settings typed in the portal
     * (woob keeps them; Carbure does not store them).
     *
     * The settings hold the bank credentials: they are never given on the command line
     * of woob ("woob config add" only reads them there, where any process of the
     * container sees them in /proc/<pid>/cmdline). The backend is written in the
     * configuration file of woob instead, as "woob config add" does: a section named
     * after the backend, with the module and the settings (RawConfigParser format, the
     * file readable by its owner only). The module is installed before (moduleFields()).
     *
     * @param string $module  The woob module
     * @param string $backend The backend name
     * @param array<string, string> $params The settings: key => value
     * @return void
     * @throws Exception If the backend cannot be written (name taken, invalid setting, file)
     * @throws Error If the woob repository must be trusted first (409)
     */
    public static function addBackend(string $module, string $backend, array $params): void
    {
        // Same checks as before running woob (repository trusted, file readable by its owner only)
        self::path();
        // The settings are not logged: they contain the bank credentials
        try {
            self::appendBackend(self::backendsFile(), self::backendSection($module, $backend, $params));
        } catch (Exception $e) {
            Logger::error("woob could not add the backend $backend ($module)");
            throw new Exception("woob could not add the bank: " . $e->getMessage());
        }
    }

    /**
     * Section of the configuration file of woob for a backend, as written by woob
     * (RawConfigParser: lowercase keys, "key = value" lines, then an empty line).
     *
     * @param string $module  The woob module
     * @param string $backend The backend name
     * @param array<string, string> $params The settings: key => value
     * @return string The section
     * @throws Exception If a name, a key or a value cannot be written on one line of the file
     */
    private static function backendSection(string $module, string $backend, array $params): string
    {
        if (!preg_match(self::MODULE_NAME, $module) || !preg_match(self::BACKEND_NAME, $backend)) {
            throw new Exception("Invalid woob module or backend name");
        }
        $section = "[$backend]\n_module = $module\n";
        foreach ($params as $key => $value) {
            // Read stripped by woob
            $value = trim($value);
            if (!preg_match('/^[A-Za-z0-9_]{1,100}$/', (string)$key) || preg_match(self::UNSAFE_SETTING, $value)) {
                throw new Exception("Invalid setting $key");
            }
            $section .= strtolower((string)$key) . " = $value\n";
        }
        return "$section\n";
    }

    /**
     * Add a section to the configuration file of the woob backends (created readable by
     * its owner only, as woob refuses to start otherwise), locked while it is written.
     *
     * @param string $file    The configuration file of the woob backends
     * @param string $section The section of the backend ("[name]\n...")
     * @return void
     * @throws Exception If the backend already exists or the file cannot be written
     */
    private static function appendBackend(string $file, string $section): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new Exception("Unable to create $dir");
        }
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new Exception("Unable to open $file");
        }
        try {
            // Readable by its owner only before any credential is written in it
            @chmod($file, 0600);
            flock($handle, LOCK_EX);
            $content = (string)stream_get_contents($handle);
            $name = substr($section, 1, (int)strpos($section, ']') - 1);
            if (in_array($name, self::sections($content), true)) {
                throw new Exception("Backend \"$name\" already exists.");
            }
            $separator = $content !== '' && !str_ends_with($content, "\n") ? "\n" : '';
            if (fwrite($handle, $separator . $section) === false || !fflush($handle)) {
                throw new Exception("Unable to write $file");
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Names of the sections (backends) of a configuration file of woob.
     *
     * @param string $content The content of the file
     * @return list<string>
     */
    private static function sections(string $content): array
    {
        preg_match_all('/^\[(.+)\][ \t]*\r?$/m', $content, $matches);
        return $matches[1];
    }

    /**
     * Remove the section of a backend from the configuration file of woob, if it is still
     * there (its credentials with it).
     *
     * @param string $file    The configuration file of the woob backends
     * @param string $backend The backend name
     * @return void
     */
    private static function removeSection(string $file, string $backend): void
    {
        $handle = is_file($file) ? @fopen($file, 'c+') : false;
        if ($handle === false) {
            return;
        }
        flock($handle, LOCK_EX);
        $content = (string)stream_get_contents($handle);
        if (in_array($backend, self::sections($content), true)) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, self::withoutSection($content, $backend));
            fflush($handle);
        }
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * A configuration file of woob without the section of a backend: from its header to
     * the next section, or the end of the file.
     *
     * @param string $content The content of the file
     * @param string $backend The backend name
     * @return string
     */
    private static function withoutSection(string $content, string $backend): string
    {
        $kept = [];
        $inSection = false;
        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^\[(.+)\][ \t]*\r?$/', $line, $m)) {
                $inSection = $m[1] === $backend;
            }
            if (!$inSection) {
                $kept[] = $line;
            }
        }
        $content = implode("\n", $kept);
        return $content === '' || str_ends_with($content, "\n") ? $content : "$content\n";
    }

    /**
     * Configuration file of the woob backends (logins and passwords of the banks), found
     * as woob finds it: WOOB_BACKENDS, else "backends" in WOOB_WORKDIR, else in
     * $XDG_CONFIG_HOME/woob, else in $HOME/.config/woob. Each variable is read in the
     * command woob_path ("env HOME=/data/woob woob"), else in the environment of PHP,
     * which woob inherits.
     *
     * @return string The path of the file
     * @throws Exception If the home directory of woob is unknown
     */
    public static function backendsFile(): string
    {
        $file = self::commandEnv('WOOB_BACKENDS');
        if ($file !== null) {
            return $file;
        }
        $workdir = self::commandEnv('WOOB_WORKDIR')
            ?? (($config = self::commandEnv('XDG_CONFIG_HOME')) !== null ? "$config/woob" : null);
        $home = self::home();
        if ($workdir === null && $home === null) {
            throw new Exception("Unknown home directory of woob: set HOME in woob_path");
        }
        return rtrim($workdir ?? "$home/.config/woob", '/') . '/backends';
    }

    /**
     * Value of an environment variable for woob: set in the command woob_path
     * ("env NAME=value woob"), else in the environment of PHP.
     *
     * @param string $name The name of the variable
     * @return string|null The value, null if unset or empty
     */
    private static function commandEnv(string $name): ?string
    {
        $value = preg_match('/(?<![\w-])' . $name . '=(\S+)/', (string)Config::get('woob_path'), $m)
            ? trim($m[1], '"\'') : getenv($name);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Remove a backend from woob (and the credentials woob kept for it): also from the
     * configuration file of woob when woob did not remove it (a backend written by
     * addBackend() where woob does not read it).
     *
     * @param string $backend The backend name
     * @return void
     */
    public static function removeBackend(string $backend): void
    {
        self::configRemove($backend);
        try {
            self::removeSection(self::backendsFile(), $backend);
        } catch (Exception) {
            // Home directory of woob unknown: nothing written by Carbure
        }
    }

    /**
     * Remove a backend with "woob config remove": a failure (e.g. a backend woob does
     * not know, its credentials then possibly kept) is logged with the output of woob,
     * never thrown (the removal goes on with the file of the backends).
     *
     * @param string $backend The backend name
     * @return void
     */
    private static function configRemove(string $backend): void
    {
        exec(self::path() . ' config remove ' . escapeshellarg($backend) . ' < /dev/null 2>&1', $output, $code);
        if ($code !== 0) {
            Logger::warn("woob config remove $backend failed (exit code: $code)", ['output' => self::loggedOutput(implode("\n", $output))]);
        }
    }

    /**
     * woob may exit with 0 when a backend cannot be loaded or the bank refuses it, with
     * no data or an empty list ("[]") on stdout: nothing + an error on stderr is a
     * failure (e.g. "Bug(bnp): 403 Client Error: Forbidden"), not an empty result.
     *
     * @param array<mixed>|null $data    The data read from stdout
     * @param string            $stderr  The error output of woob
     * @param string            $message The message of the failure, followed by the woob error
     * @return void
     * @throws Exception If there is no data and woob reported an error
     */
    private static function failOnEmptyWithError(?array $data, string $stderr, string $message): void
    {
        if (!$data && preg_match(self::ERROR_LINE, self::withoutLogs($stderr))) {
            Logger::error($message, ["stderr" => self::loggedOutput($stderr)]);
            throw new Exception($message . self::lastLine($stderr));
        }
    }

    /**
     * Last non empty line of an output that is not a debug or info log of woob (the
     * cause of an error comes before woob logs that it stored the session...), nor the
     * hint woob adds after an error, prefixed with ": " (empty string if none).
     *
     * @param string $output The command output
     * @return string The suffix to add to an error message
     */
    private static function lastLine(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", self::withoutLogs($output))),
            fn(string $line): bool => $line !== '' && !preg_match(self::DEBUG_HINT, $line)));
        return $lines ? ': ' . mb_substr(end($lines), 0, 200) : '';
    }

    /**
     * Error output of woob as written in the logs: complete with woob_debug, otherwise only
     * its last error line (the rest may hold data of the bank: accounts, pages, labels).
     *
     * @param string $stderr The error output of woob
     * @return string
     */
    private static function loggedOutput(string $stderr): string
    {
        return Config::get('woob_debug') ? $stderr : substr(self::lastLine($stderr), 2);
    }

    /**
     * Build the woob CLI command string.
     *
     * @param string $type  The type of transactions to get (coming or history)
     * @param string $bankId The bank account identifier
     * @return string The full command to execute
     * @throws Exception If the account would be read as an option by woob
     */
    private static function buildCommand(string $type, string $bankId): string
    {
        $woob_path = self::path();
        $woob_transactions = Config::get('woob_transactions');
        // --auto-update refreshes the repositories after a crash of a module: woob would
        // trust the keyring of its repository by itself if none is trusted yet
        $woob_auto_update = Config::get('woob_auto_update') && WoobRepository::isTrusted();

        $auto_update_flag = $woob_auto_update ? " --auto-update" : "";

        // Never an option of woob ("-x@bank"): refused when the account is followed
        if (str_starts_with($bankId, '-')) {
            throw new Exception("Invalid bank account $bankId");
        }
        $bankId = escapeshellarg($bankId);

        return "$woob_path bank $type $bankId --count $woob_transactions$auto_update_flag" . self::loggingOptions()
            . " -f json -s date,rdate,amount,raw,label,type,category,card";
    }

    /**
     * Options of woob for its logs: --debug when woob_debug is enabled (it supersedes the
     * level: never "--debug --quiet"), else the level of woob_logging (nothing for an
     * unknown level).
     *
     * @return string The options, with a leading space
     */
    private static function loggingOptions(): string
    {
        if (Config::get('woob_debug')) {
            return self::LOGGING_OPTIONS['debug'];
        }
        return self::LOGGING_OPTIONS[(string)Config::get('woob_logging')] ?? '';
    }

    /**
     * An output of woob without its debug and info log lines (they may mention
     * "error" without being one: a page named error, a retry...).
     *
     * @param string $output The error output of woob
     * @return string
     */
    private static function withoutLogs(string $output): string
    {
        return implode("\n", array_filter(explode("\n", $output), fn(string $line): bool => !preg_match(self::LOG_LINE, $line)));
    }

    /**
     * Give the complete lines of a woob output that are not data (JSON) to a callback,
     * without terminal colors. The incomplete last line stays in the buffer.
     *
     * @param string   $buffer    The output not handled yet (updated)
     * @param callable $onMessage Receives each message
     * @param bool     $data      The output contains data (JSON lines, skipped)
     * @param int      $maxLength Maximum length of a message
     * @return void
     */
    private static function emitMessages(string &$buffer, callable $onMessage, bool $data = true, int $maxLength = 300): void
    {
        while (($end = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $end);
            $line = trim(preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $line) ?? $line);
            $buffer = substr($buffer, $end + 1);
            if ($line !== '' && (!$data || ($line[0] !== '[' && $line[0] !== '{'))) {
                $onMessage(mb_substr($line, 0, $maxLength));
            }
        }
    }

    /**
     * Execute a shell command with SSE heartbeats sent during execution.
     *
     * @param string        $command   The shell command to execute
     * @param string        $stderr    Receives the error output of the command
     * @param bool          $heartbeat Send SSE heartbeats while waiting (stream routes only)
     * @param callable|null $onMessage Receives each line of the output that is not data
     * @param callable|null $onError   Receives each line of the error output
     * @param bool          $terminal  Give woob a pseudo-terminal as standard output: woob
     *                                 only starts a two-factor authentication on a terminal
     * @return string The stdout output of the command
     * @throws Exception If the process fails to start or exits with a non-zero code
     */
    private static function executeWithHeartbeat(string $command, string &$stderr = '', bool $heartbeat = true, ?callable $onMessage = null, ?callable $onError = null, bool $terminal = false): string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => $terminal ? ['pty'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new Exception("Failed to start woob process");
        }

        fclose($pipes[0]);

        $output = self::readOutputs([1 => $pipes[1], 2 => $pipes[2]], $heartbeat, [1 => $onMessage, 2 => $onError]);

        // The terminal turns each "\n" into "\r\n" (a raw "\r" never appears in JSON)
        $stdout = $terminal ? str_replace("\r\n", "\n", $output[1]) : $output[1];
        $stderr = $output[2];
        self::checkExitCode(proc_close($process), $stdout, $stderr);

        return $stdout;
    }

    /**
     * Read stdout and stderr of a process until both are closed, sending a heartbeat
     * each time woob stays silent for HEARTBEAT_INTERVAL seconds.
     *
     * @param array<int, resource>       $open      The pipes to read: 1 (stdout), 2 (stderr)
     * @param bool                       $heartbeat Send SSE heartbeats while waiting
     * @param array<int, callable|null>  $callbacks Receive the lines of each pipe (or null)
     * @return array<int, string> The output of each pipe: 1 (stdout), 2 (stderr)
     */
    private static function readOutputs(array $open, bool $heartbeat, array $callbacks): array
    {
        $output = [1 => '', 2 => ''];
        $pending = [1 => '', 2 => ''];

        while (!empty($open)) {
            $read = array_values($open);
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, self::HEARTBEAT_INTERVAL) === 0) {
                if ($heartbeat) {
                    Webservice::sendHeartbeat();
                }
                continue;
            }

            // In the order of the pipes, as given back by stream_select()
            foreach ($open as $index => $pipe) {
                if (in_array($pipe, $read, true)) {
                    self::readChunk($index, $open, $output, $pending, $callbacks[$index]);
                }
            }
        }

        return $output;
    }

    /**
     * Read the next chunk of a pipe (closed at its end) and give its complete lines
     * to the callback of the pipe.
     *
     * @param int                       $index    The pipe: 1 (stdout), 2 (stderr)
     * @param array<int, resource>      $open     The pipes still open (updated)
     * @param array<int, string>        $output   The output read so far (updated)
     * @param array<int, string>        $pending  The output not given to the callback yet (updated)
     * @param callable|null             $callback Receives the lines of the pipe
     * @return void
     */
    private static function readChunk(int $index, array &$open, array &$output, array &$pending, ?callable $callback): void
    {
        $pipe = $open[$index];
        // A pseudo-terminal ends with a read error (EIO) instead of an empty read
        $chunk = @fread($pipe, 8192);
        if ($chunk === '' || $chunk === false) {
            fclose($pipe);
            unset($open[$index]);
            $chunk = "\n";
        } else {
            $output[$index] .= $chunk;
        }
        // stdout: messages besides the data; stderr: logs, kept longer (requests, tracebacks)
        if ($callback !== null) {
            $pending[$index] .= $chunk;
            self::emitMessages($pending[$index], $callback, $index === 1, $index === 1 ? 300 : 2000);
        }
    }

    /**
     * Log the output of woob (woob_debug) and fail on a non-zero exit code.
     *
     * @param int    $return_var The exit code of woob
     * @param string $stdout     The standard output
     * @param string $stderr     The error output
     * @return void
     * @throws Exception If woob exited with a non-zero code
     */
    private static function checkExitCode(int $return_var, string $stdout, string $stderr): void
    {
        if (Config::get('woob_debug')) {
            Logger::debug("Woob stdout", ["stdout" => $stdout]);
            Logger::debug("Woob stderr", ["stderr" => $stderr]);
        }

        if ($return_var != 0) {
            // The whole output (accounts, transactions, pages of the bank) only with woob_debug
            Logger::error("Error while calling woob (exit code: $return_var)", Config::get('woob_debug')
                ? ["stdout" => $stdout, "stderr" => $stderr] : ["stderr" => self::loggedOutput($stderr)]);

            // The last line of the error output usually gives the cause (e.g. a Python exception)
            throw new Exception("Error while calling woob (exit code: $return_var)" . self::lastLine($stderr));
        }
    }

    /**
     * Patch for the creditmutuel module FormNotFound issue.
     * Since March 2026, Credit Mutuel changed their form IDs, causing the woob
     * creditmutuel module to crash with "Bug(creditmutuel): FormNotFound".
     * This method applies the fix from woob commit e19ffde9641a6ab64f8c7732008a6347b66a0f34
     * automatically when PATCH_CREDITMUT environment variable is set to TRUE.
     *
     * @return bool True if the patch was applied, false if not needed or already applied
     */
    public static function patchCreditmutuelModule(): bool
    {
        // Only apply if explicitly enabled
        if (getenv('PATCH_CREDITMUT') !== 'TRUE') {
            Logger::debug("Creditmutuel patch not applied: PATCH_CREDITMUT not set to TRUE");
            return false;
        }

        $woobHome = self::home();
        if (empty($woobHome)) {
            Logger::warn("Cannot apply creditmutuel patch: woob home directory unknown");
            return false;
        }

        $backendDir = $woobHome . '/.local/share/woob/backends/creditmutuel';
        $patchMarker = $backendDir . '/.carbure_patched';

        // Check if already patched
        if (file_exists($patchMarker)) {
            Logger::debug("Creditmutuel patch already applied");
            return false;
        }

        // Check if module exists
        if (!is_dir($backendDir)) {
            Logger::debug("Creditmutuel module not installed, patch not needed");
            return false;
        }

        $moduleFile = $backendDir . '/module.py';
        if (!file_exists($moduleFile)) {
            Logger::warn("Creditmutuel module.py not found in $backendDir");
            return false;
        }

        // Backup original file
        $backupFile = $moduleFile . '.bak';
        if (!copy($moduleFile, $backupFile)) {
            Logger::error("Failed to backup creditmutuel module.py");
            return false;
        }

        Logger::info("Applying creditmutuel FormNotFound patch...");

        // Read the file
        $content = file_get_contents($moduleFile);
        if ($content === false) {
            Logger::error("Failed to read creditmutuel module.py");
            unlink($backupFile);
            return false;
        }

        // Apply patches based on woob commit e19ffde
        // These are the form ID changes that fix the FormNotFound error
        $patches = [
            // Old form IDs -> New form IDs
            'login_form' => 'loginForm',
            'auth_form' => 'authForm',
            'form_login' => 'formLogin',
            'form_auth' => 'formAuth',
        ];

        $modified = false;
        foreach ($patches as $old => $new) {
            if (strpos($content, $old) !== false) {
                $content = str_replace($old, $new, $content);
                $modified = true;
                Logger::debug("Patched: $old -> $new");
            }
        }

        if (!$modified) {
            Logger::debug("No form IDs to patch in creditmutuel module");
            unlink($backupFile);
            return false;
        }

        // Write the patched content
        $result = file_put_contents($moduleFile, $content);
        if ($result === false) {
            Logger::error("Failed to write patched creditmutuel module.py");
            // Restore backup
            copy($backupFile, $moduleFile);
            unlink($backupFile);
            return false;
        }

        // Mark as patched
        touch($patchMarker);

        // Verify the module is still valid
        try {
            $output = [];
            $returnVar = 0;
            $command = self::path() . ' config check creditmutuel';
            exec($command, $output, $returnVar);
            
            if ($returnVar !== 0) {
                Logger::error("Patched creditmutuel module failed validation: " . implode("\n", $output));
                // Restore backup
                copy($backupFile, $moduleFile);
                unlink($backupFile);
                unlink($patchMarker);
                return false;
            }
        } catch (Exception $e) {
            Logger::error("Error validating patched module: " . $e->getMessage());
            copy($backupFile, $moduleFile);
            unlink($backupFile);
            unlink($patchMarker);
            return false;
        }

        // Clean up backup
        unlink($backupFile);

        Logger::info("Creditmutuel patch applied successfully");
        return true;
    }

    /**
     * Parse the JSON output returned by woob.
     *
     * @param string $type   The transaction type (for logging)
     * @param string $stdout The raw stdout from the woob process
     * @return list<mixed>|null The parsed transaction data or null if empty
     */
    private static function parseOutput(string $type, string $stdout): ?array
    {
        $lines = array_filter(explode("\n", trim($stdout)));

        if (count($lines) === 0) {
            Logger::warn("No data received from the bank");
            return null;
        }

        $data = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            // Not JSON, or an empty list ("[]": nothing, not one empty row)
            if ($decoded === null || $decoded === []) {
                continue;
            }
            if (isset($decoded[0]) && is_array($decoded[0])) {
                $data = array_merge($data, $decoded);
            } else {
                $data[] = $decoded;
            }
        }

        Logger::debug("Synchronized $type: " . count($data) . " transactions");
        return array_values($data);
    }
}
