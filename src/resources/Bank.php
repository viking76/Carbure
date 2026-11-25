<?php

/**
 * Bank.php
 *
 * Bank class to sync transactions from a bank
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\Messages;
use Carbure\Lib\Webservice;
use Carbure\Lib\Woob;
use Carbure\Lib\WoobRepository;
use Closure;
use Error;
use Exception;
use Throwable;

final class Bank {

    /** Pause of the waits between two attempts, in seconds (null: sleep(); replaced by the tests) */
    public static ?Closure $sleep = null;

    /** bank_name of a manual account: not a woob backend, never synchronized, its transactions
     *  are entered by hand (a bank woob does not support, cash) */
    public const MANUAL = 'manual';

    /** Header of the sync token (the scheduler) */
    public const SYNC_TOKEN_HEADER = 'X-Sync-Token';

    /**
     * Response headers when the sync token is passed in the URL (?token=, deprecated: the
     * URL is written in the access logs of nginx, proxies and shell histories). RFC 9745:
     * deprecated since 2026-10-16, and the page that explains the header to use instead.
     */
    public const TOKEN_IN_URL_HEADERS = [
        'Deprecation: @1792108800',
        'Link: <https://carbure.org/api/#bank-sync>; rel="deprecation"; type="text/html"',
    ];

    /**
     * Get the bank accounts of the household (every user sees all of them).
     * Each bank account is returned with bankId = account_number@bank_name
     * @return list<array<string, mixed>> List of bank accounts with bankId and details
     *               (label: name given to the account, null if none; manual: an account whose
     *               transactions are entered by hand)
     */
    #[ApiRoute('/bank', method: 'GET')]
    public static function get(): array
    {
        $sql = "SELECT MIN(id) AS id, account_number, bank_name, MAX(label) AS label FROM bank_account
                GROUP BY account_number, bank_name ORDER BY bank_name, account_number";
        $stmt = Db::execute($sql, "");
        $result = Db::result($stmt);
        
        $accounts = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $accounts[] = array(
                    'id' => $row['id'],
                    'bankId' => $row['account_number'] . '@' . $row['bank_name'],
                    'account_number' => $row['account_number'],
                    'bank_name' => $row['bank_name'],
                    'label' => $row['label'],
                    'manual' => $row['bank_name'] === self::MANUAL
                );
            }
        }
        
        return $accounts;
    }
    
    /**
     * Validate a bank account identifier and woob backend name.
     *
     * @param string $accountNumber The account identifier in woob
     * @param string $bankName      The woob backend name
     * @param bool   $manual        The account is a manual one (its bank_name stays MANUAL)
     * @return void
     * @throws Error If one of them is invalid
     */
    private static function validateAccount(string $accountNumber, string $bankName, bool $manual = false): void
    {
        if (($bankName === self::MANUAL) !== $manual) {
            throw new ApiError("The bank name " . self::MANUAL . " is reserved to the manual accounts", 400, 'bank_name_reserved');
        }
        // Never starting with "-": woob would read "-x@bank" as an option
        if (!preg_match('/^[A-Za-z0-9_.:][A-Za-z0-9_.:-]{0,99}$/', $accountNumber)) {
            throw new ApiError("Invalid account number", 400, 'account_number_invalid');
        }
        if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_-]{0,49}$/', $bankName)) {
            throw new ApiError("Invalid bank (woob backend) name", 400, 'account_bank_invalid');
        }
    }

    /**
     * Normalize the name given to an account or a bank connection: trimmed, without
     * control characters, null when empty.
     *
     * @param string|null $label The name given to the account or bank connection
     * @return string|null The name to store
     * @throws Error If it is too long
     */
    private static function normalizeLabel(?string $label): ?string
    {
        $label = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$label) ?? '');
        if ($label === '') {
            return null;
        }
        if (mb_strlen($label) > 100) {
            throw new ApiError("Name too long (100 characters max)", 400, 'label_too_long');
        }
        return $label;
    }

    /**
     * Get a bank account of the household.
     *
     * @param int $id The account id
     * @return array<string, mixed> The account
     * @throws Error If the account does not exist
     */
    private static function existingAccount(int $id): array
    {
        $account = Db::queryOne("SELECT * FROM bank_account WHERE id = ?", "i", $id);
        if (!$account) {
            throw new ApiError("Account not found", 404, 'account_not_found');
        }
        return $account;
    }

    /**
     * All the bank accounts of the household with the user who added them and the
     * result of their last synchronization (administrators).
     *
     * @return list<array<string, mixed>> The accounts: id, bankId, account_number, bank_name,
     *               label (name given to the account, null if none), user_id and username
     *               (who added it), last_sync_at, last_sync_status (OK|ERROR), last_sync_message,
     *               manual (an account whose transactions are entered by hand, never synchronized)
     */
    #[ApiRoute('/bank/accounts', method: 'GET', admin: true)]
    public static function accounts(): array
    {
        $sql = "SELECT a.id, a.account_number, a.bank_name, a.label, a.user_id, u.username,
                       a.last_sync_at, a.last_sync_status, a.last_sync_message
                FROM bank_account a JOIN users u ON u.id = a.user_id
                ORDER BY a.bank_name, a.account_number, u.username";
        $accounts = [];
        foreach (Db::select($sql, "")->fetch_all(MYSQLI_ASSOC) as $row) {
            $accounts[] = ['id' => $row['id'], 'bankId' => $row['account_number'] . '@' . $row['bank_name']] + $row
                + ['manual' => $row['bank_name'] === self::MANUAL];
        }
        return $accounts;
    }

    /**
     * Follow a bank account (it is synchronized from now on).
     *
     * @param string      $account_number The account identifier in woob
     * @param string      $bank_name      The woob backend name
     * @param string|null $label          Name given to the account (optional)
     * @return array<string, mixed> The account (user_id: the administrator who added it)
     * @throws Error If invalid or already followed by the household
     */
    #[ApiRoute('/bank', method: 'POST', admin: true, changes: ['accounts'])]
    public static function create(string $account_number, string $bank_name, ?string $label = null): array
    {
        self::validateAccount($account_number, $bank_name);
        $label = self::normalizeLabel($label);
        $userId = (int)Jwt::getUserIdFromToken();

        if (Db::queryOne("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ?", "ss", $account_number, $bank_name)) {
            throw new ApiError("This account is already followed", 409, 'account_already_followed');
        }

        $stmt = Db::execute("INSERT INTO bank_account (bank_name, account_number, label, user_id) VALUES (?, ?, ?, ?)", "sssi", $bank_name, $account_number, $label, $userId);

        return ['id' => $stmt->insert_id, 'bankId' => "$account_number@$bank_name", 'account_number' => $account_number, 'bank_name' => $bank_name, 'label' => $label, 'user_id' => $userId];
    }

    /**
     * Add a manual account: a bank woob does not support, or cash. It is never
     * synchronized; its transactions are entered by hand (POST /transaction).
     *
     * @param string      $label          Name of the account (required)
     * @param string|null $account_number Its number (optional, generated when empty)
     * @return array<string, mixed> The account
     * @throws Error If the name or the number is invalid, or the number already used (409)
     */
    #[ApiRoute('/bank/manual', method: 'POST', admin: true, changes: ['accounts'])]
    public static function createManual(string $label, ?string $account_number = null): array
    {
        $label = self::normalizeLabel($label);
        if ($label === null) {
            throw new ApiError("The name of the account is required", 400, 'account_label_required');
        }
        $number = trim((string)$account_number) ?: 'M' . bin2hex(random_bytes(4));
        self::validateAccount($number, self::MANUAL, true);
        $userId = (int)Jwt::getUserIdFromToken();

        if (Db::queryOne("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ?", "ss", $number, self::MANUAL)) {
            throw new ApiError("This account is already followed", 409, 'account_already_followed');
        }
        $stmt = Db::execute("INSERT INTO bank_account (bank_name, account_number, label, user_id) VALUES (?, ?, ?, ?)", "sssi",
            self::MANUAL, $number, $label, $userId);

        return ['id' => $stmt->insert_id, 'bankId' => $number . '@' . self::MANUAL, 'account_number' => $number,
                'bank_name' => self::MANUAL, 'label' => $label, 'user_id' => $userId, 'manual' => true];
    }

    /**
     * Modify a followed bank account.
     *
     * @param int         $id             The account id
     * @param string      $account_number The account identifier in woob
     * @param string      $bank_name      The woob backend name
     * @param string|null $label          Name given to the account: omitted (null) keeps
     *                                    the current one, empty removes it
     * @return array<string, mixed> The account
     * @throws Error If not found, invalid or already followed by the household
     */
    #[ApiRoute('/bank', method: 'PUT', admin: true, changes: ['accounts'])]
    public static function update(int $id, string $account_number, string $bank_name, ?string $label = null): array
    {
        $account = self::existingAccount($id);
        self::validateAccount($account_number, $bank_name, $account['bank_name'] === self::MANUAL);
        $label = $label === null ? $account['label'] : self::normalizeLabel($label);
        $userId = (int)$account['user_id'];

        if (Db::queryOne("SELECT id FROM bank_account WHERE account_number = ? AND bank_name = ? AND id <> ?", "ssi", $account_number, $bank_name, $id)) {
            throw new ApiError("This account is already followed", 409, 'account_already_followed');
        }

        Db::execute("UPDATE bank_account SET account_number = ?, bank_name = ?, label = ? WHERE id = ?", "sssi", $account_number, $bank_name, $label, $id);

        return ['id' => $id, 'bankId' => "$account_number@$bank_name", 'account_number' => $account_number, 'bank_name' => $bank_name, 'label' => $label, 'user_id' => $userId];
    }

    /**
     * Stop following a bank account (its transactions are kept).
     *
     * @param int $id The account id
     * @return bool True if deleted
     * @throws Error If not found
     */
    #[ApiRoute('/bank', method: 'DELETE', admin: true, changes: ['accounts'])]
    public static function delete(int $id): bool
    {
        self::existingAccount($id);
        Db::execute("DELETE FROM bank_account WHERE id = ?", "i", $id);
        return true;
    }

    /**
     * Bank backends configured in woob (name and module), to choose the bank of an account,
     * with the name given to each bank connection in Carbure.
     *
     * @return list<array{name: mixed, module: mixed, label: string|null}> The backends (label:
     *               name given to the bank connection, null if none)
     */
    #[ApiRoute('/bank/backends', method: 'GET', admin: true)]
    public static function backends(): array
    {
        $labels = [];
        foreach (Db::select("SELECT name, label FROM bank_backend", "")->fetch_all(MYSQLI_ASSOC) as $row) {
            $labels[$row['name']] = $row['label'];
        }
        return array_map(fn(array $backend) => $backend + ['label' => $labels[$backend['name']] ?? null], Woob::listBackends());
    }

    /**
     * Repository of the woob bank modules (administrators): trusted or not, and the
     * fingerprint of its keyring (the one trusted, else the one woob would ask to trust,
     * to compare with the one published by the woob maintainers).
     *
     * @return array{repository: string, trusted: bool, fingerprint: string|null, error: string|null}
     *         error: why the fingerprint is unknown (repository not reachable), null if none
     */
    #[ApiRoute('/bank/woob', method: 'GET', admin: true)]
    public static function woob(): array
    {
        return WoobRepository::status();
    }

    /**
     * Update the woob bank modules (administrators), streamed: each line of woob, then
     * "Woob updated", "Woob repository not trusted: <fingerprint>" (the keyring must be
     * confirmed, woob not started) or "Woob update failed: <cause>". While the repository
     * is not trusted, woob (which would trust any keyring by itself) is started only if the
     * confirmed fingerprint is the one of its keyring, and must store that very keyring.
     *
     * @param string|null $fingerprint Fingerprint of the keyring confirmed by the administrator
     * @return void
     * @throws Error If not an administrator or the fingerprint is invalid
     */
    #[ApiRoute('/bank/woob/update', method: 'GET', stream: true, admin: true)]
    public static function updateWoob(?string $fingerprint = null): void
    {
        $fingerprint = $fingerprint === '' ? null : $fingerprint;
        if ($fingerprint !== null && !preg_match('/^[0-9a-fA-F]{40}$/', $fingerprint)) {
            throw new ApiError("Invalid fingerprint (SHA-1: 40 hexadecimal characters)", 400, 'woob_fingerprint_invalid');
        }
        set_time_limit(0);

        // Not while a synchronization runs woob
        if (!(Db::select("SELECT GET_LOCK('bank_sync', 0) AS locked")->fetch_assoc()['locked'] ?? 0)) {
            Webservice::sendProgress("Woob update failed: synchronization in progress");
            return;
        }
        try {
            $result = WoobRepository::update($fingerprint, fn(string $line) => Webservice::sendProgress($line));
        } finally {
            Db::query("SELECT RELEASE_LOCK('bank_sync')");
        }
        Webservice::sendProgress(self::woobUpdateResult($result));
    }

    /**
     * Last event of an update of woob, logged.
     *
     * @param array{updated: bool, asked: string|null, refused: bool, message: string} $result Result of the update
     * @return string The event
     */
    private static function woobUpdateResult(array $result): string
    {
        if ($result['updated']) {
            Logger::info("woob updated: " . $result['message']);
            return "Woob updated";
        }
        if ($result['refused'] && $result['asked'] !== null) {
            Logger::warn("woob update stopped: keyring " . $result['asked'] . " not trusted");
            return "Woob repository not trusted: " . $result['asked'];
        }
        Logger::error("woob update failed: " . $result['message']);
        return "Woob update failed: " . $result['message'];
    }

    /**
     * Give a name to a bank connection (woob backend), e.g. "Compte joint", shown in the
     * portal instead of its woob name (administrators).
     *
     * @param string      $backend Name of the woob backend (e.g. bnp_2)
     * @param string|null $label   Name given to the bank connection: empty removes it
     * @return array{name: string, label: string|null} The woob backend and its name
     * @throws Error If the backend name is invalid, the name too long, or the backend unknown
     */
    #[ApiRoute('/bank/backend', method: 'PUT', admin: true, changes: ['accounts'])]
    public static function renameBackend(string $backend, ?string $label = null): array
    {
        if (!preg_match(Woob::BACKEND_NAME, $backend)) {
            throw new ApiError("Invalid bank name (lowercase letters, digits, - and _)", 400, 'bank_name_invalid');
        }
        $label = self::normalizeLabel($label);
        if (!in_array($backend, array_column(Woob::listBackends(), 'name'), true)) {
            throw new ApiError("Bank not configured in woob", 404, 'bank_not_configured');
        }
        self::saveBackendLabel($backend, $label);
        return ['name' => $backend, 'label' => $label];
    }

    /**
     * Remove a bank connection (woob backend) from the portal (administrators): woob
     * forgets it with its credentials, its name and its followed accounts are removed;
     * the transactions already imported are kept.
     *
     * @param string $backend Name of the woob backend (e.g. bnp_2)
     * @return bool True if removed
     * @throws Error If the backend name is invalid or the backend unknown
     * @throws Exception If woob did not remove it
     */
    #[ApiRoute('/bank/backend', method: 'DELETE', admin: true, changes: ['accounts'])]
    public static function deleteBackend(string $backend): bool
    {
        if (!preg_match(Woob::BACKEND_NAME, $backend)) {
            throw new ApiError("Invalid bank name (lowercase letters, digits, - and _)", 400, 'bank_name_invalid');
        }
        if (!in_array($backend, array_column(Woob::listBackends(), 'name'), true)) {
            throw new ApiError("Bank not configured in woob", 404, 'bank_not_configured');
        }

        // woob says nothing when it fails: the backend must be gone from its list
        Woob::removeBackend($backend);
        if (in_array($backend, array_column(Woob::listBackends(), 'name'), true)) {
            throw new Exception("woob could not remove the bank $backend");
        }
        Db::execute("DELETE FROM bank_backend WHERE name = ?", "s", $backend);
        Db::execute("DELETE FROM bank_account WHERE bank_name = ?", "s", $backend);
        Logger::info("Bank $backend removed from woob, with its followed accounts");
        return true;
    }

    /**
     * Store the name given to a bank connection (none: the name is removed).
     *
     * @param string      $backend Name of the woob backend
     * @param string|null $label   Name given to the bank connection, already normalized
     * @return void
     */
    private static function saveBackendLabel(string $backend, ?string $label): void
    {
        if ($label === null) {
            Db::execute("DELETE FROM bank_backend WHERE name = ?", "s", $backend);
            return;
        }
        Db::execute("INSERT INTO bank_backend (name, label) VALUES (?, ?) ON DUPLICATE KEY UPDATE label = VALUES(label)", "ss", $backend, $label);
    }

    /**
     * Banks supported by woob (module and description); a bank must be configured
     * in woob (from the portal, POST /bank/backend) before its accounts can be synchronized.
     *
     * @return list<array{module: mixed, description: mixed}> The modules
     */
    #[ApiRoute('/bank/modules', method: 'GET', admin: true)]
    public static function modules(): array
    {
        return Woob::listBankModules();
    }

    /**
     * Settings asked by a woob bank module, to configure the bank from the portal
     * (administrators).
     *
     * @param string $module The woob module (e.g. bnp)
     * @return array<string, mixed> module, description, fields [{key, label, description, default, required, masked, choices}]
     * @throws Error If the module name is invalid
     */
    #[ApiRoute('/bank/module', method: 'GET', admin: true)]
    public static function module(string $module): array
    {
        if (!preg_match(Woob::MODULE_NAME, $module)) {
            throw new ApiError("Invalid woob module", 400, 'woob_module_invalid');
        }
        return Woob::moduleFields($module);
    }

    /**
     * Configure a bank in woob from the portal (administrators): the credentials
     * are given to woob, which keeps them; Carbure stores and logs nothing of them.
     * The accounts woob then finds are returned, to follow them. When the bank asks a
     * two-factor authentication, which woob only starts in interactive mode, the bank stays
     * configured with no account and two_factor true: its accounts are then listed by
     * GET /bank/discover/interactive, while the administrator validates the connection (#299).
     *
     * @param string      $module   The woob module (e.g. bnp)
     * @param string      $backend  Name of the woob backend (e.g. bnp, bnp_pro)
     * @param mixed       $settings The settings of the module (login, password...): key => value
     *                              (anything else is taken as no setting, as sent by the client)
     * @param string|null $label    Name given to the bank connection, e.g. "Compte joint"
     *                              (optional)
     * @return array{backend: string, label: string|null, accounts: list<array<string, mixed>>, two_factor: bool}
     *               backend, label, accounts
     *               [{bankId, account_number, bank_name, label, balance, currency, followed}],
     *               two_factor (the bank asks a two-factor authentication)
     * @throws Error If a setting is missing or invalid, or woob refuses the bank; 409 if the
     *               module must be installed before the woob repository is trusted
     */
    #[ApiRoute('/bank/backend', method: 'POST', admin: true, changes: ['accounts'])]
    public static function createBackend(string $module, string $backend, mixed $settings = [], ?string $label = null): array
    {
        if (!preg_match(Woob::MODULE_NAME, $module) || !preg_match(Woob::BACKEND_NAME, $backend)) {
            throw new ApiError("Invalid woob module or bank name (lowercase letters, digits, - and _)", 400, 'bank_setup_invalid');
        }
        if ($backend === self::MANUAL) {
            throw new ApiError("The bank name " . self::MANUAL . " is reserved to the manual accounts", 400, 'bank_name_reserved');
        }
        $label = self::normalizeLabel($label);
        if (in_array($backend, array_column(Woob::listBackends(), 'name'), true)) {
            throw new ApiError("A bank named $backend is already configured in woob", 409, 'bank_exists', ['bank' => $backend]);
        }

        // Every setting of the module is given, required ones present, values on one line,
        // checked against the module's rules. An optional setting left empty is not written:
        // woob then takes its own default, and refuses an empty value for a setting whose
        // default is not empty (boursorama: identity)
        $params = [];
        foreach (Woob::moduleFields($module)['fields'] as $field) {
            $value = self::settingValue($field, $settings);
            self::checkSetting($field, $value);
            if ($value !== '' || $field['required']) {
                $params[$field['key']] = $value;
            }
        }

        try {
            Woob::addBackend($module, $backend, $params);
        } catch (Exception $e) {
            throw new Error($e->getMessage(), 400);
        }

        $accounts = self::firstConnection($module, $backend);
        // Replaces the name of a former connection of the same woob name, if any
        self::saveBackendLabel($backend, $label);
        return ['backend' => $backend, 'label' => $label, 'accounts' => $accounts ?? [], 'two_factor' => $accounts === null];
    }

    /**
     * First connection to a bank just configured in woob: its accounts, to follow them.
     * If the bank refuses it (wrong credentials...), the backend is removed from woob so
     * that the form can be sent again; if it asks a two-factor authentication, the backend
     * is kept: woob only starts it with the administrator waiting for it (#299).
     *
     * @param string $module  The woob module
     * @param string $backend Name of the woob backend
     * @return list<array<string, mixed>>|null The accounts, null if the bank asks a two-factor authentication
     * @throws Error If the bank refuses the connection (400)
     */
    private static function firstConnection(string $module, string $backend): ?array
    {
        try {
            return self::discover($backend);
        } catch (Exception $e) {
            if (Woob::needsTwoFactor($e->getMessage())) {
                Logger::info("The bank $backend asks a two-factor authentication: accounts to list interactively");
                return null;
            }
            Woob::removeBackend($backend);
            throw self::refusalError($module, $e->getMessage());
        }
    }

    /**
     * Causes of a failed first connection to a bank (woob error => [code, message]), the
     * first matching one wins: the credentials only when the bank says so
     */
    private const REFUSALS = [
        '/BrowserIncorrectPassword|incorrect|wrong ?password/i' => ['bank_credentials_refused', 'The bank refused the credentials, check them'],
        '/\b403\b|Forbidden|Bug\(|ClientError|BrowserUnavailable|\b5\d\d Server Error/i'
            => ['bank_blocked', 'The bank blocks woob or the woob module "%s" is broken, see the known issues of this module on the woob tracker'],
    ];

    /**
     * Error of a failed first connection to a bank, by cause (wrong credentials, the
     * bank blocking woob or a broken woob module, other failure), followed by the error
     * of woob.
     *
     * @param string $module The woob module
     * @param string $error  The error of woob
     * @return ApiError The error (400), with the module and the error of woob as values
     */
    private static function refusalError(string $module, string $error): ApiError
    {
        foreach (self::REFUSALS as $pattern => [$code, $message]) {
            if (preg_match($pattern, $error)) {
                return new ApiError(sprintf($message, $module) . ": $error", 400, $code, ['module' => $module, 'cause' => $error]);
            }
        }
        return new ApiError("The connection to the bank failed: $error", 400, 'bank_connection_failed', ['cause' => $error]);
    }

    /**
     * Value of a setting of a woob module, as sent by the portal (trimmed), else its
     * default value.
     *
     * @param array{key: string, label: string, default: string, required: bool} $field The setting
     * @param mixed $settings The settings sent: key => value (anything else: no setting)
     * @return string The value
     * @throws Error If a required setting is missing
     */
    private static function settingValue(array $field, mixed $settings): string
    {
        $value = is_array($settings) ? trim((string)($settings[$field['key']] ?? '')) : '';
        if ($value === '') {
            $value = $field['default'];
        }
        if ($value === '' && $field['required']) {
            throw new ApiError("Missing setting: " . $field['label'], 400, 'bank_setting_missing', ['setting' => $field['label']]);
        }
        return $value;
    }

    /**
     * Check the value of a setting: on one line, without quotes, among the choices of
     * the module and matching its format.
     *
     * @param array{label: string, regexp: string|null, choices: list<array{value: string, label: string}>} $field The setting
     * @param string $value The value
     * @return void
     * @throws Error If the value is not valid
     */
    private static function checkSetting(array $field, string $value): void
    {
        if (preg_match('/[\x00-\x1f\x7f"\\\\]/', $value) || preg_match('/\s/', $value) || mb_strlen($value) > 200) {
            throw new ApiError("Invalid value for " . $field['label'] . " (no spaces nor quotes)", 400, 'bank_setting_value_invalid',
                ['setting' => $field['label']]);
        }
        if ($value !== '' && $field['choices'] && !in_array($value, array_column($field['choices'], 'value'), true)) {
            throw new ApiError("Invalid choice for " . $field['label'], 400, 'bank_setting_choice_invalid', ['setting' => $field['label']]);
        }
        if ($value !== '' && !self::matchesFormat($field['regexp'], $value)) {
            throw new ApiError("Invalid format for " . $field['label'], 400, 'bank_setting_format_invalid', ['setting' => $field['label']]);
        }
    }

    /**
     * Whether a value matches the format of a woob setting (a format that is not a
     * valid regular expression is ignored).
     *
     * @param string|null $regexp The format of the setting (no delimiters)
     * @param string      $value  The value
     * @return bool
     */
    private static function matchesFormat(?string $regexp, string $value): bool
    {
        if (!$regexp) {
            return true;
        }
        $pattern = '/' . str_replace('/', '\\/', $regexp) . '/u';
        return @preg_match($pattern, '') === false || preg_match($pattern, $value) === 1;
    }

    /**
     * Accounts available in the configured woob backends (or in one of them), to follow
     * them in one click.
     *
     * @param string|null $backend Only the accounts of this woob backend (bank connection),
     *                             else of every configured one
     * @return list<array<string, mixed>> The accounts (bankId, account_number, bank_name, label,
     *               balance, currency, followed)
     * @throws Error If the backend name is invalid
     * @throws Exception If woob fails
     */
    #[ApiRoute('/bank/discover', method: 'GET', admin: true)]
    public static function discover(?string $backend = null): array
    {
        $backend = $backend === '' ? null : $backend;
        if ($backend !== null && !preg_match(Woob::BACKEND_NAME, $backend)) {
            throw new ApiError("Invalid bank name (lowercase letters, digits, - and _)", 400, 'bank_name_invalid');
        }
        return self::discovered(Woob::listAccounts($backend));
    }

    /**
     * Accounts of one bank connection, with a two-factor authentication allowed
     * (administrators), streamed: woob runs on a pseudo-terminal and may ask a validation
     * in the bank app, as on the first connection to some banks (#299). Each message of the
     * bank is sent as "Bank message <backend>: <message>", then the result: "Accounts
     * <JSON list>" (same format as GET /bank/discover) or "Discovery failed: <cause>" (also
     * "synchronization in progress": never while a synchronization runs woob).
     *
     * @param string $backend Name of the woob backend (bank connection)
     * @return void
     * @throws Error If the backend name is invalid (400)
     */
    #[ApiRoute('/bank/discover/interactive', method: 'GET', stream: true, admin: true)]
    public static function discoverInteractive(string $backend): void
    {
        if (!preg_match(Woob::BACKEND_NAME, $backend)) {
            throw new ApiError("Invalid bank name (lowercase letters, digits, - and _)", 400, 'bank_name_invalid');
        }
        // The validation in the bank app may take minutes
        set_time_limit(0);

        // Not while a synchronization runs woob: the bank would ask two validations
        if (!(Db::select("SELECT GET_LOCK('bank_sync', 0) AS locked")->fetch_assoc()['locked'] ?? 0)) {
            Webservice::sendProgress("Discovery failed: synchronization in progress");
            return;
        }
        try {
            $accounts = Woob::listAccounts($backend, true, fn(string $message) => Webservice::sendProgress("Bank message $backend: $message"));
            Webservice::sendProgress("Accounts " . json_encode(self::discovered($accounts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        } catch (Exception $e) {
            Logger::error("Discovery of the accounts of $backend failed: " . $e->getMessage());
            Webservice::sendProgress("Discovery failed: " . $e->getMessage());
        } finally {
            Db::query("SELECT RELEASE_LOCK('bank_sync')");
        }
    }

    /**
     * Accounts listed by woob as returned by the discovery, the followed ones marked.
     *
     * @param list<array<mixed>> $woobAccounts The accounts listed by woob
     * @return list<array<string, mixed>> The accounts (bankId, account_number, bank_name, label,
     *               balance, currency, followed)
     */
    private static function discovered(array $woobAccounts): array
    {
        $followed = array_column(self::accounts(), 'bankId');

        $accounts = [];
        foreach ($woobAccounts as $account) {
            $at = strrpos($account['id'], '@');
            if ($at === false) {
                continue;
            }
            $bankId = $account['id'];
            $accounts[] = [
                'bankId' => $bankId,
                'account_number' => substr($bankId, 0, $at),
                'bank_name' => substr($bankId, $at + 1),
                'label' => $account['label'] ?? null,
                'balance' => $account['balance'] ?? null,
                'currency' => $account['currency'] ?? null,
                'followed' => in_array($bankId, $followed, true),
            ];
        }

        return $accounts;
    }

    /**
     * Save the transactions of a bank account: coming (card transactions not debited
     * yet, type 12) or history.
     *
     * @param string $kind        "coming" or "history"
     * @param string $bankId      The bank id to sync (account_number@bank_name)
     * @param bool   $interactive Allow the bank to ask a two-factor authentication
     * @param bool   $debug       Send the debug logs of woob (woob_debug) in the progress
     * @return list<array<string, mixed>> The new transactions
     */
    private static function syncBankData(string $kind, string $bankId, bool $interactive = false, bool $debug = false): array
    {
        $transactions = self::getBankData($kind, $bankId, $interactive, $debug);
        if (empty($transactions)) {
            Logger::warn("No $kind transactions for $bankId");
            Webservice::sendProgress("Done $bankId ($kind): 0 received, 0 new");
            return [];
        }
        if ($kind === 'coming') {
            $transactions = array_filter($transactions, fn($transaction) => $transaction['type'] == 12);
        }

        [$accountNumber, $bankName] = explode('@', $bankId, 2);
        $created = Transaction::save($transactions, self::getBankOwner($bankId), true,
            ['bank_name' => $bankName, 'account_number' => $accountNumber]);
        Webservice::sendProgress("Done $bankId ($kind): " . count($transactions) . " received, " . count($created) . " new");
        return $created;
    }

    /**
     * Check that the caller may start a synchronization:
     * either an authenticated user (JWT) or the scheduler with the sync_token
     * (X-Sync-Token header, or the deprecated ?token= parameter).
     *
     * @param string|null $token The sync token passed as query parameter (deprecated)
     * @return bool True for an authenticated user, false for the scheduler
     * @throws Error If the caller is not allowed
     */
    private static function checkSyncAccess(?string $token): bool
    {
        $token = self::syncToken($token);

        if ($token !== null && Config::has('sync_token') && hash_equals((string)Config::get('sync_token'), $token)) {
            return false;
        }

        if (Jwt::checkAuthorization()) {
            return true;
        }

        throw new Error("Unauthorized - Invalid or missing JWT token or sync token", 401);
    }

    /**
     * The sync token of the request: the X-Sync-Token header, else the ?token= parameter,
     * still accepted (schedulers already configured) but deprecated: a warning is logged
     * (never the token) and the response has the Deprecation and Link headers.
     *
     * @param string|null $token The sync token passed as query parameter
     * @return string|null The token, null if there is none
     */
    private static function syncToken(?string $token): ?string
    {
        $header = Webservice::getHeader(self::SYNC_TOKEN_HEADER);
        if ($header !== null) {
            return $header;
        }
        if ($token === null || $token === '') {
            return null;
        }

        Logger::warn("The sync token passed in the URL is deprecated, use the " . self::SYNC_TOKEN_HEADER . " header");
        if (!headers_sent()) {
            foreach (self::TOKEN_IN_URL_HEADERS as $header) {
                header($header);
            }
        }
        return $token;
    }

    /**
     * Synchronize the bank data with Server-Sent Events progress.
     *
     * In interactive mode (a user in the portal, one account), woob may start a
     * two-factor authentication: its messages ("validate in your bank app") are sent
     * as "Bank message <account>: <message>" while it waits for the validation.
     *
     * With woob_debug enabled, an administrator also receives the debug logs of woob
     * (error output) as "Woob debug <account>: <line>", to diagnose a failing bank.
     *
     * A failure of the coming transactions does not prevent the history: it is sent as
     * "Coming failed <account>: <error>" and noted in the result of the account. For the
     * scheduler, an account whose bank asks a validation in its app is synchronized again
     * after woob_2fa_wait seconds (woob_2fa_retries times), sent as "Waiting for a
     * validation <account>: ...".
     *
     * The missing categories are only updated when at least one account was synchronized:
     * after a failure, only the users are notified of the error.
     *
     * @param string|null $token       The sync token (for the scheduler), deprecated: the
     *                                  X-Sync-Token header is preferred (never in the logs)
     * @param string|null $account     Synchronize only this account (account_number@bank_name)
     * @param bool|string|null $interactive "true": allow a two-factor authentication (needs
     *                                      $account); parsed as a boolean ("false" is false)
     * @return void
     * @throws Error If unauthorized, the account is not followed or the interactive mode not allowed
     */
    #[ApiRoute('/bank/sync', method: 'GET', public: true, stream: true)]
    public static function getSync(?string $token = null, ?string $account = null, bool|string|null $interactive = null): void
    {
        $user = self::checkSyncAccess($token);

        // A two-factor authentication is only started for a user who waits for it
        // (never by the scheduler), on one account
        $interactive = filter_var($interactive, FILTER_VALIDATE_BOOLEAN);
        if ($interactive && (!$user || $account === null || $account === '')) {
            throw new Error("The interactive mode needs a user and one account", 400);
        }

        $bankIds = self::syncedBankIds($account);

        // Allow unlimited execution time for bank sync
        set_time_limit(0);

        // The debug logs of woob may show bank data: only for an administrator
        $debug = $user && Config::get('woob_debug') && User::isAdmin();

        // Acquire exclusive lock to prevent concurrent syncs
        $lockAcquired = (Db::select("SELECT GET_LOCK('bank_sync', 0) AS locked")->fetch_assoc()['locked'] ?? 0);
        if (!$lockAcquired) {
            Webservice::sendProgress("Synchronization already in progress");
            return;
        }

        try {
            // At least one account synchronized: the transactions may need categories
            $synced = false;

            // Synchronize each bank account (every one, even after a failure)
            foreach ($bankIds as $bankId) {
                $synced = self::syncAccount($bankId, $interactive, $debug, !$user) || $synced;
            }

            self::finishSync($synced);
        } finally {
            Db::query("SELECT RELEASE_LOCK('bank_sync')");
        }
    }

    /**
     * Accounts to synchronize: the given one (if followed), else every followed account.
     *
     * @param string|null $account Synchronize only this account (account_number@bank_name)
     * @return list<string> The bank ids
     * @throws Error If the account is not followed
     */
    private static function syncedBankIds(?string $account): array
    {
        $bankIds = self::getBankIds();
        if ($account === null || $account === '') {
            return $bankIds;
        }
        if (!in_array($account, $bankIds, true)) {
            throw new ApiError("Account not found", 404, 'account_not_found');
        }
        return [$account];
    }

    /**
     * Synchronize one account (coming then history), record the result and notify the
     * household (result, large expenses, rules marked "notify").
     *
     * For the scheduler (no user, never interactive), when the bank asks a validation
     * in its app, the account is synchronized again after woob_2fa_wait seconds, up to
     * woob_2fa_retries times: the validation may have been done in the meantime. Only
     * this account waits; the result of the last attempt is recorded and notified.
     *
     * @param string $bankId      The bank id (account_number@bank_name)
     * @param bool   $interactive Allow the bank to ask a two-factor authentication
     * @param bool   $debug       Send the debug logs of woob in the progress
     * @param bool   $scheduled   Started by the scheduler (sync token): retry after a validation
     * @return bool True if the account was synchronized
     */
    private static function syncAccount(string $bankId, bool $interactive, bool $debug, bool $scheduled = false): bool
    {
        $created = [];
        $retries = $scheduled && !$interactive ? self::intSetting('woob_2fa_retries', 1, 0, 5) : 0;
        for ($attempt = 0; ; $attempt++) {
            [$attemptCreated, $error, $note] = self::syncAttempt($bankId, $interactive, $debug);
            $created = array_merge($created, $attemptCreated);
            if ($attempt >= $retries || !Woob::needsTwoFactor($error)) {
                break;
            }
            self::waitForValidation($bankId, $attempt + 1, $retries);
        }

        $status = $error === null;
        self::recordSyncResult($bankId, $status, $status ? count($created) . " new" . $note : $error);

        // Send notification to all users who own this bank account
        Webservice::sendProgress("Notifying users of $bankId...");
        self::notifyBankUsers($bankId, $status, $error);
        self::alertLargeExpenses($bankId, $created);
        self::alertRuleMatches($created);

        // The clients see the account as soon as it is synchronized, not at the end of
        // the synchronization of every account
        Changes::flush();

        return $status;
    }

    /**
     * One synchronization of an account: its accounts listed, coming then history. The coming transactions
     * are best effort: their failure does not prevent the history, it is only noted
     * (unless the bank asks a validation, which the history would ask too).
     *
     * @param string $bankId      The bank id (account_number@bank_name)
     * @param bool   $interactive Allow the bank to ask a two-factor authentication
     * @param bool   $debug       Send the debug logs of woob in the progress
     * @return array{0: list<array<string, mixed>>, 1: string|null, 2: string} The new
     *               transactions, the error (null on success) and the note of the result
     */
    private static function syncAttempt(string $bankId, bool $interactive, bool $debug): array
    {
        try {
            // The accounts are listed first: some modules only find the account afterwards (#43)
            Webservice::sendProgress("Syncing $bankId (accounts)...");
            Woob::findAccount($bankId, $interactive, ...self::woobCallbacks($bankId, $debug));
        } catch (Exception $e) {
            Logger::error("Error syncing $bankId: " . $e->getMessage());
            Webservice::sendProgress("Error syncing $bankId: " . $e->getMessage());
            return [[], $e->getMessage(), ''];
        }

        [$created, $comingError] = self::syncComing($bankId, $interactive, $debug);
        try {
            if (Woob::needsTwoFactor($comingError)) {
                throw new Exception((string)$comingError);
            }
            Webservice::sendProgress("Syncing $bankId (history)...");
            $created = array_merge($created, self::syncBankData('history', $bankId, $interactive, $debug));
        } catch (Exception $e) {
            Logger::error("Error syncing $bankId: " . $e->getMessage());
            Webservice::sendProgress("Error syncing $bankId: " . $e->getMessage());
            return [$created, $e->getMessage(), ''];
        }
        return [$created, null, $comingError === null ? '' : " (coming failed: $comingError)"];
    }

    /**
     * Save the coming transactions of an account; a failure is returned, not thrown, and
     * reported as "Coming failed <account>: <error>" (unless the bank asks a validation).
     *
     * @param string $bankId      The bank id (account_number@bank_name)
     * @param bool   $interactive Allow the bank to ask a two-factor authentication
     * @param bool   $debug       Send the debug logs of woob in the progress
     * @return array{0: list<array<string, mixed>>, 1: string|null} The new transactions and
     *               the error (null on success)
     */
    private static function syncComing(string $bankId, bool $interactive, bool $debug): array
    {
        try {
            Webservice::sendProgress("Syncing $bankId (coming)...");
            return [self::syncBankData('coming', $bankId, $interactive, $debug), null];
        } catch (Exception $e) {
            // A validation asked by the bank is the error of the account (sent by the caller)
            if (!Woob::needsTwoFactor($e->getMessage())) {
                Logger::warn("Coming transactions of $bankId failed: " . $e->getMessage());
                Webservice::sendProgress("Coming failed $bankId: " . $e->getMessage());
            }
            return [[], $e->getMessage()];
        }
    }

    /**
     * Wait woob_2fa_wait seconds before synchronizing an account again (the bank asked a
     * validation in its app), sending heartbeats to keep the connection open.
     *
     * @param string $bankId  The bank id (account_number@bank_name)
     * @param int    $attempt Number of the next attempt (1 for the first retry)
     * @param int    $retries Number of retries allowed
     * @return void
     */
    private static function waitForValidation(string $bankId, int $attempt, int $retries): void
    {
        $seconds = self::intSetting('woob_2fa_wait', 15, 0, 300);
        Logger::info("Validation asked by the bank of $bankId: new attempt in $seconds s ($attempt/$retries)");
        Webservice::sendProgress("Waiting for a validation $bankId: new attempt in $seconds s ($attempt/$retries)");
        $sleep = self::$sleep ?? fn(int $s) => sleep($s);
        for ($i = 0; $i < $seconds; $i++) {
            $sleep(1);
            Webservice::sendHeartbeat();
        }
    }

    /**
     * An integer setting, bounded; its default when it is not set or not a number.
     *
     * @param string $key     The setting key
     * @param int    $default The value when the setting is not set
     * @param int    $min     The lowest value
     * @param int    $max     The highest value
     * @return int
     */
    private static function intSetting(string $key, int $default, int $min, int $max): int
    {
        try {
            $value = Config::get($key);
        } catch (Exception $e) {
            return $default;
        }
        return is_numeric($value) ? max($min, min($max, (int)$value)) : $default;
    }

    /**
     * End of a synchronization: categories of the new transactions, budget alerts of
     * the month (BudgetAlert) and insight alerts (InsightAlert), cleanup of the old logs, then
     * the completion event.
     *
     * @param bool $synced At least one account was synchronized
     * @return void
     */
    private static function finishSync(bool $synced): void
    {
        // Update missing categories: useless when every account failed (no transaction)
        if ($synced) {
            Webservice::sendProgress("Updating missing categories...");
            Transaction::updateMissingCategories();

            // Spending of the budgets of the month, once the transactions are categorized
            Webservice::sendProgress("Checking the budgets...");
            BudgetAlert::check();
            InsightAlert::check();
        }

        // Delete the log files older than the retention: a cleanup must not fail the sync
        try {
            $deleted = Logger::purge();
            if ($deleted) {
                Webservice::sendProgress(count($deleted) . " old log file(s) deleted");
            }
        } catch (Throwable $e) {
            Logger::error("Cleanup of the logs failed: " . $e->getMessage());
        }

        // Send final completion event
        Webservice::sendProgress("Synchronization complete");
    }
    

    /**
     * Get the user owning a bank account (the first one if the account is shared).
     *
     * @param string $bankId The bank ID (account_number@bank_name)
     * @return int The user id
     * @throws Exception If the bank account has no owner
     */
    private static function getBankOwner(string $bankId): int
    {
        list($accountNumber, $bankName) = explode('@', $bankId, 2);

        $sql = "SELECT MIN(user_id) AS user_id FROM bank_account WHERE account_number = ? AND bank_name = ?";
        $row = Db::queryOne($sql, "ss", $accountNumber, $bankName);

        if (!$row || $row['user_id'] === null) {
            throw new Exception("No owner found for bank account $bankId");
        }

        return (int)$row['user_id'];
    }

    /**
     * Store the date and result of the last synchronization of an account.
     *
     * @param string      $bankId  The account (account_number@bank_name)
     * @param bool        $success True if the synchronization succeeded
     * @param string|null $message The number of new transactions or the error
     * @return void
     */
    private static function recordSyncResult(string $bankId, bool $success, ?string $message): void
    {
        [$number, $bank] = explode('@', $bankId, 2);
        $status = $success ? 'OK' : 'ERROR';
        $message = mb_substr((string)$message, 0, 500);
        $previous = Db::queryOne("SELECT last_sync_status, last_sync_message FROM bank_account WHERE account_number = ? AND bank_name = ? LIMIT 1",
            "ss", $number, $bank);
        Db::execute("UPDATE bank_account SET last_sync_at = NOW(), last_sync_status = ?, last_sync_message = ? WHERE account_number = ? AND bank_name = ?",
            "ssss", $status, $message, $number, $bank);
        // The clients reload the accounts when their state changed (an account in error,
        // repaired, another error), not at each synchronization: the number of new
        // transactions of the message moves the transactions
        if ($previous === null || $previous['last_sync_status'] !== $status
            || (!$success && $previous['last_sync_message'] !== $message)) {
            Changes::touch('accounts');
        }
    }

    /**
     * Get the bank IDs of the synchronized accounts (not the manual ones) from the database.
     *
     * @return list<string> List of bankIds (account_number@bank_name)
     */
    private static function getBankIds(): array
    {
        $sql = "SELECT DISTINCT account_number, bank_name FROM bank_account WHERE bank_name <> ? ORDER BY bank_name, account_number";
        $stmt = Db::execute($sql, "s", self::MANUAL);
        $result = Db::result($stmt);
        
        $bankIds = array();
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $bankIds[] = $row['account_number'] . '@' . $row['bank_name'];
            }
        }
        
        return $bankIds;
    }

    /**
     * Notify all users of the household of the result of the synchronization of a bank
     * account, each one in the language of his profile and on the channels he chose.
     *
     * A failure that needs a two-factor authentication asks to approve it in the portal.
     *
     * @param string      $bankId The bank ID (account_number@bank_name)
     * @param bool        $status The sync status (true=success, false=failure)
     * @param string|null $error  The error of a failed synchronization
     * @return void
     */
    public static function notifyBankUsers(string $bankId, bool $status, ?string $error = null): void
    {
        // The accounts are shared by the household: every user is notified
        $users = Db::select("SELECT id, language FROM users", "")->fetch_all(MYSQLI_ASSOC);
        foreach ($users as $user) {
            $userId = (int)$user['id'];
            try {
                // Count unpointed transactions of the household
                $unpointed = Transaction::countUnpointed();
                [$title, $body] = self::syncMessage((string)$user['language'], $bankId, $status, $error, $unpointed);
                Notification::notify($userId, 'sync', $title, $body, $unpointed);
                Logger::info("Notification sent to user $userId for bank $bankId with $unpointed unpointed transactions");
            } catch (Exception $e) {
                Logger::warn("Failed to send notification to user $userId: " . $e->getMessage());
            }
        }
    }

    /**
     * Title and text of the notification of a synchronization result, in a language:
     * success, two-factor authentication to approve, or failure (the error of woob as
     * is), then the number of transactions to review.
     *
     * @param string      $language  The language of the user
     * @param string      $bankId    The bank ID (account_number@bank_name)
     * @param bool        $status    The sync status
     * @param string|null $error     The error of a failed synchronization
     * @param int         $unpointed Transactions of the household to review
     * @return array{0: string, 1: string} Title and text
     */
    public static function syncMessage(string $language, string $bankId, bool $status, ?string $error, int $unpointed): array
    {
        $bank = strtoupper(explode('@', $bankId, 2)[1] ?? $bankId);
        $review = Messages::plural($language, 'sync_review', $unpointed);
        if ($status) {
            return [Messages::t($language, 'sync_success_title'), $review];
        }
        if (Woob::needsTwoFactor($error)) {
            return [Messages::t($language, 'sync_2fa_title'), Messages::t($language, 'sync_2fa_body', ['bank' => $bank])];
        }
        // The error on one line, not too long for a notification
        $error = trim((string)preg_replace('/\s+/', ' ', (string)$error));
        if (mb_strlen($error) > 200) {
            $error = mb_substr($error, 0, 199) . '…';
        }
        $body = $error === '' ? $review : Messages::t($language, 'sync_error_body', ['bank' => $bank, 'error' => $error]) . "\n" . $review;
        return [Messages::t($language, 'sync_error_title'), $body];
    }

    /**
     * Notify the household of the new transactions matching a rule marked
     * "notify" (one notification per transaction and user).
     *
     * @param array<array<string, mixed>> $transactions The new transactions (label, amount)
     * @return int The number of notifications sent
     */
    public static function alertRuleMatches(array $transactions): int
    {
        if (empty($transactions)) {
            return 0;
        }
        // A rule with an amount only matches the transactions of this amount
        $rules = array_values(array_filter(Category::loadKeywords(), fn(array $rule): bool => (int)$rule['notify'] === 1));
        if (!$rules) {
            return 0;
        }

        // The household is notified: every user
        $users = Db::select("SELECT id, language FROM users", "")->fetch_all(MYSQLI_ASSOC);
        $sent = 0;
        foreach ($transactions as $transaction) {
            $label = strtoupper((string)$transaction['label']);
            foreach ($rules as $rule) {
                if (!Category::matches($rule, $label, $transaction['amount'] ?? null)) {
                    continue;
                }
                $sent += self::notifyRuleMatch($users, $transaction, $rule['keyword']);
                // One notification per transaction, even if several rules match
                break;
            }
        }
        if ($sent > 0) {
            Logger::info("$sent rule notification(s) sent");
        }
        return $sent;
    }

    /**
     * Notify every user of a new transaction matching a rule, in the user's language.
     *
     * @param list<array<string, mixed>> $users       The users (id, language)
     * @param array<string, mixed>       $transaction The transaction (label, amount)
     * @param string                     $keyword     The keyword of the rule
     * @return int The number of notifications sent
     */
    private static function notifyRuleMatch(array $users, array $transaction, string $keyword): int
    {
        foreach ($users as $user) {
            $language = (string)$user['language'];
            Notification::notify(
                (int)$user['id'],
                'rule',
                Messages::t($language, 'rule_title', ['keyword' => $keyword]),
                Messages::transaction($language, (string)$transaction['label'], (float)$transaction['amount'], $transaction['date'] ?? null),
                Transaction::countUnpointed()
            );
        }
        return count($users);
    }

    /**
     * Alert the owners of a bank account of each new expense whose amount reaches
     * their alert threshold (users.alert_threshold, NULL = disabled), on the channels
     * each one chose (push, email).
     *
     * @param string                      $bankId       The bank ID (account_number@bank_name)
     * @param array<array<string, mixed>> $transactions The new transactions (label, amount)
     * @return int The number of alerts sent
     */
    public static function alertLargeExpenses(string $bankId, array $transactions): int
    {
        if (empty($transactions)) {
            return 0;
        }

        list($accountNumber, $bankName) = explode('@', $bankId, 2);
        // The accounts are shared by the household: every user with a threshold is alerted
        $sql = "SELECT id, language, alert_threshold FROM users WHERE alert_threshold > 0";
        $users = Db::select($sql, "")->fetch_all(MYSQLI_ASSOC);

        $sent = 0;
        foreach ($users as $user) {
            foreach ($transactions as $transaction) {
                if ($transaction['amount'] >= 0 || -$transaction['amount'] < (float)$user['alert_threshold']) {
                    continue;
                }

                $language = (string)$user['language'];
                Notification::notify(
                    (int)$user['id'],
                    'expense',
                    Messages::t($language, 'expense_title'),
                    Messages::transaction($language, (string)$transaction['label'], (float)$transaction['amount'], $transaction['date'] ?? null),
                    Transaction::countUnpointed()
                );
                $sent++;
            }
        }

        if ($sent > 0) {
            Logger::info("$sent large expense alert(s) sent for $bankId");
        }
        return $sent;
    }

    /**
     * Call Woob to get the list of transactions from a bank. The system command run is
     * sent in the progress as "Woob command <account>: <command>".
     *
     * @param string $type        The type of transactions to get (coming or history)
     * @param string $bankId      The bank id
     * @param bool   $interactive Allow the bank to ask a two-factor authentication
     * @param bool   $debug       Send the debug logs of woob in the progress
     * @return list<mixed>|null The transaction data or null if no data
     */
    private static function getBankData(string $type, string $bankId, bool $interactive = false, bool $debug = false): ?array
    {
        return Woob::getBankData($type, $bankId, $interactive, ...self::woobCallbacks($bankId, $debug));
    }

    /**
     * Callbacks of a woob call during a synchronization, sent in the progress: the messages
     * of the bank, the debug logs of woob (only when asked) and the command run.
     *
     * @param string $bankId The bank id (account_number@bank_name)
     * @param bool   $debug  Send the debug logs of woob in the progress
     * @return array{0: callable, 1: callable|null, 2: callable} onMessage, onDebug, onCommand
     */
    private static function woobCallbacks(string $bankId, bool $debug): array
    {
        return [
            fn($message) => Webservice::sendProgress("Bank message $bankId: $message"),
            $debug ? fn($line) => Webservice::sendProgress("Woob debug $bankId: $line") : null,
            fn($command) => Webservice::sendProgress("Woob command $bankId: $command"),
        ];
    }
}
