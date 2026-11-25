<?php

/**
 * Insight.php
 *
 * Insights of the Insights tab: a name, a color and an SQL query returning an
 * `amount` for a month ({month} and {year} are replaced by the selected month).
 * Administrators manage them; as the SQL is stored and executed by the server,
 * it is restricted to one read-only SELECT on the budget tables (Insight::READABLE_TABLES),
 * run in a read-only transaction with a time limit (Insight::$maxSeconds).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Logger;
use Carbure\Lib\Month;
use Carbure\Lib\SqlTables;
use Error;
use Throwable;

final class Insight {

    /**
     * Colors of the insight cards
     */
    public const COLORS = ['red', 'orange', 'amber', 'lime', 'green', 'teal', 'cyan', 'blue', 'indigo', 'purple', 'pink', 'brown', 'gray'];

    /**
     * Words refused in an insight query: writes, administration, files, locks, delays
     */
    private const FORBIDDEN_WORDS = 'INSERT|UPDATE|DELETE|REPLACE|MERGE|UPSERT|DROP|ALTER|CREATE|TRUNCATE|RENAME|GRANT|REVOKE|'
        . 'LOCK|UNLOCK|CALL|HANDLER|EXECUTE|PREPARE|DEALLOCATE|SET|DO|LOAD|LOAD_FILE|OUTFILE|DUMPFILE|INTO|SLEEP|BENCHMARK|'
        . 'GET_LOCK|RELEASE_LOCK|SHOW|DESCRIBE|EXPLAIN|KILL|SHUTDOWN|FLUSH|RESET|PURGE|INSTALL|UNINSTALL|USER|PASSWORD|CURRENT_USER|SYSTEM_USER|SESSION_USER';

    /**
     * Tables an insight can read: the budget data
     */
    public const READABLE_TABLES = ['bank_account', 'bank_backend', 'bank_transaction', 'bank_transaction_category',
        'bank_transaction_category_keyword', 'budget', 'tag', 'transaction_tag'];

    /**
     * Tables refused even as a word of the query: every other table of sql/carbure.sql
     * (credentials, MFA secrets, OAuth, settings... a unit test keeps the list in sync with
     * the schema) and the system schemas
     */
    public const REFUSED_TABLES = ['api_tokens', 'bank_transaction_deleted', 'budget_alert', 'budget_alert_threshold', 'budget_insight', 'data_change', 'devices', 'insight_alert',
        'mfa_recovery_code', 'mfa_trusted_browser', 'oauth_clients', 'oauth_codes', 'schema_migrations', 'settings',
        'user_browser', 'user_mfa', 'user_notification', 'users',
        'mysql', 'information_schema', 'performance_schema', 'sys'];

    /**
     * MySQL/MariaDB errors of a query stopped by its time limit: MariaDB max_statement_time,
     * MySQL max_execution_time
     */
    private const TIMEOUT_ERRORS = [1969, 3024];

    /**
     * Time limit of an insight query, in seconds (run at each display of the Insights tab,
     * twelve times for the history of a year)
     */
    public static float $maxSeconds = 5.0;

    /**
     * Whether budget_insight.enabled exists (migration 2026-11-01_insight_enabled), read once
     */
    private static ?bool $ready = null;

    /**
     * All the insights with their SQL (administrators).
     *
     * @return list<array<string, mixed>> The insights: id, name, color, icon, sql, enabled (once the
     *         migration 2026-11-01_insight_enabled is applied)
     */
    #[ApiRoute('/insight', method: 'GET', admin: true)]
    public static function get(): array
    {
        $enabled = self::ready() ? ", enabled" : "";
        return array_map(fn(array $insight): array => self::withEnabled($insight),
            Db::select("SELECT id, name, color, icon, `sql`$enabled FROM budget_insight ORDER BY " . self::order(), "")->fetch_all(MYSQLI_ASSOC));
    }

    /**
     * Whether budget_insight.position exists (migration 2026-11-07_insight_position), read once
     */
    private static ?bool $positionReady = null;

    /**
     * Whether the insights can be ordered: the migration 2026-11-07_insight_position is
     * applied (before it, they are sorted by id). Read once per request.
     *
     * @return bool
     */
    public static function positionReady(): bool
    {
        return self::$positionReady ??= Db::queryOne("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_insight' AND COLUMN_NAME = 'position'", "") !== null;
    }

    /**
     * ORDER BY of the insights: their position chosen in the Insights tab, then their id.
     *
     * @return string
     */
    private static function order(): string
    {
        return self::positionReady() ? "position, id" : "id";
    }

    /**
     * Order of the insights shown in the Insights tab (drag and drop of the cards, #467),
     * for the whole household as their display. The insights listed get the positions 1,
     * 2, 3... in this order; the others (hidden, or created meanwhile) go after them.
     *
     * @param list<int|string> $ids The insights, in their new order
     * @return list<int> The ids of the shown insights, in their order
     * @throws Error If the list is invalid (400), an insight is unknown (404), or the
     *               migration is not applied yet (409)
     */
    #[ApiRoute('/insight/order', method: 'PUT', changes: ['insights'])]
    public static function setOrder(array $ids): array
    {
        $order = array_flip(self::orderIds($ids));
        if (!self::positionReady()) {
            throw new ApiError("The database must be updated first", 409, 'insight_migration_pending');
        }
        $known = array_map('intval', array_column(Db::select("SELECT id FROM budget_insight", "")->fetch_all(MYSQLI_ASSOC), 'id'));
        if (array_diff(array_keys($order), $known)) {
            throw new ApiError("Insight not found", 404, 'insight_not_found');
        }

        // The others after the ones listed, in their current order
        $others = array_values(array_diff(
            array_map('intval', array_column(Db::select("SELECT id FROM budget_insight ORDER BY position, id", "")->fetch_all(MYSQLI_ASSOC), 'id')),
            array_keys($order)));
        foreach (array_merge(array_keys($order), $others) as $index => $id) {
            Db::execute("UPDATE budget_insight SET position = ? WHERE id = ?", "ii", $index + 1, $id);
        }
        return array_map('intval', array_column(self::enabled(), 'id'));
    }

    /**
     * Whether the insights can be shown or hidden: the migration 2026-11-01_insight_enabled
     * is applied (before it, every insight is shown). Read once per request.
     *
     * @return bool
     */
    public static function ready(): bool
    {
        return self::$ready ??= Db::queryOne("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_insight' AND COLUMN_NAME = 'enabled'", "") !== null;
    }

    /**
     * The insights enabled, shown in the Insights tab (all of them before the migration
     * 2026-11-01_insight_enabled).
     *
     * @return list<array<string, mixed>> The rows of budget_insight
     */
    public static function enabled(): array
    {
        $where = self::ready() ? " WHERE enabled = 1" : "";
        return Db::select("SELECT * FROM budget_insight$where ORDER BY " . self::order(), "")->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * The insights disabled, offered by the + button of the Insights tab.
     *
     * @return list<array{id: int, name: string, color: string, icon: string|null}> The insights
     */
    #[ApiRoute('/insight/disabled', method: 'GET')]
    public static function disabled(): array
    {
        if (!self::ready()) {
            return [];
        }
        $rows = Db::select("SELECT id, name, color, icon FROM budget_insight WHERE enabled = 0 ORDER BY " . self::order(), "")->fetch_all(MYSQLI_ASSOC);
        return array_map(fn(array $row): array => ['id' => (int)$row['id'], 'name' => (string)$row['name'],
            'color' => (string)$row['color'], 'icon' => $row['icon'] === null ? null : (string)$row['icon']], $rows);
    }

    /**
     * Enable an insight (shown in the Insights tab) or disable it (offered by the + button),
     * for the whole household; disabling it drops its alerts (InsightAlert).
     *
     * @param int|string           $id      The insight
     * @param bool|int|string|null $enabled True to show it (also "true", "1"...), false to hide it
     * @return array{id: int, enabled: bool} The insight
     * @throws Error If not found, enabled is not a boolean, or the migration is not applied yet (409)
     */
    #[ApiRoute('/insight/enabled', method: 'PUT', changes: ['insights'])]
    public static function setEnabled(int|string $id, bool|int|string|null $enabled = true): array
    {
        if (!Db::queryOne("SELECT id FROM budget_insight WHERE id = ?", "i", $id)) {
            throw new ApiError("Insight not found", 404, 'insight_not_found');
        }
        $enabled = $enabled === null ? true : filter_var($enabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            throw new Error("Invalid enabled: true or false is expected", 400);
        }
        if (!self::ready()) {
            throw new ApiError("The database must be updated first", 409, 'insight_migration_pending');
        }
        Db::execute("UPDATE budget_insight SET enabled = ? WHERE id = ?", "ii", $enabled ? 1 : 0, $id);
        // A disabled insight no longer alerts
        if (!$enabled) {
            InsightAlert::dropAlerts((int)$id);
        }
        return ['id' => (int)$id, 'enabled' => $enabled];
    }

    /**
     * The ids of an order of the insights: positive integers (also as digits), each once.
     *
     * @param array<mixed> $ids The ids sent
     * @return list<int> The ids
     * @throws Error If one is not a positive integer or is repeated (400)
     */
    private static function orderIds(array $ids): array
    {
        $valid = array_filter($ids, fn(mixed $id): bool => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int)$id > 0);
        $order = array_values(array_unique(array_map('intval', $valid)));
        if (count($order) !== count($ids) || count($order) > 1000) {
            throw new ApiError("Invalid order: a list of insight ids is expected", 400, 'insight_order_invalid');
        }
        return $order;
    }

    /**
     * An insight with its enabled flag as a boolean (true before the migration
     * 2026-11-01_insight_enabled).
     *
     * @param array<string, mixed> $insight A row of budget_insight
     * @return array<string, mixed> The insight
     */
    private static function withEnabled(array $insight): array
    {
        $insight['enabled'] = (int)($insight['enabled'] ?? 1) === 1;
        return $insight;
    }

    /**
     * Create an insight (administrators); the query is tested on the current month.
     *
     * @param string      $name  The name (1 to 20 characters)
     * @param string      $color One of Insight::COLORS (red, orange, amber... gray)
     * @param string      $sql   SELECT … AS amount, with {month} and {year}
     * @param string|null $icon  Material icon (optional: chosen from the name)
     * @return array<string, mixed> The insight with the amount of the current month
     * @throws Error If a field or the query is invalid
     */
    #[ApiRoute('/insight', method: 'POST', admin: true, changes: ['insights'])]
    public static function create(string $name, string $color, string $sql, ?string $icon = null): array
    {
        [$name, $color, $sql] = self::validate($name, $color, $sql);
        $icon = self::icon($icon);
        $amount = self::test($sql);

        $stmt = Db::execute("INSERT INTO budget_insight (name, color, icon, `sql`) VALUES (?, ?, ?, ?)", "ssss", $name, $color, $icon, $sql);
        // After the cards already ordered (#467)
        if (self::positionReady()) {
            Db::execute("UPDATE budget_insight SET position = (SELECT p FROM (SELECT IFNULL(MAX(position), 0) + 1 AS p FROM budget_insight) AS last)
                WHERE id = ?", "i", $stmt->insert_id);
        }

        return ['id' => $stmt->insert_id, 'name' => $name, 'color' => $color, 'icon' => $icon, 'sql' => $sql, 'amount' => $amount];
    }

    /**
     * Modify an insight (administrators); the query is tested on the current month.
     *
     * @param int         $id    The insight
     * @param string      $name  The name (1 to 20 characters)
     * @param string      $color One of Insight::COLORS (red, orange, amber... gray)
     * @param string      $sql   SELECT … AS amount, with {month} and {year}
     * @param string|null $icon  Material icon (optional: chosen from the name)
     * @return array<string, mixed> The insight with the amount of the current month
     * @throws Error If not found, or a field or the query is invalid
     */
    #[ApiRoute('/insight', method: 'PUT', admin: true, changes: ['insights'])]
    public static function update(int $id, string $name, string $color, string $sql, ?string $icon = null): array
    {
        if (!Db::queryOne("SELECT id FROM budget_insight WHERE id = ?", "i", $id)) {
            throw new ApiError("Insight not found", 404, 'insight_not_found');
        }
        [$name, $color, $sql] = self::validate($name, $color, $sql);
        $icon = self::icon($icon);
        $amount = self::test($sql);

        Db::execute("UPDATE budget_insight SET name = ?, color = ?, icon = ?, `sql` = ? WHERE id = ?", "ssssi", $name, $color, $icon, $sql, $id);

        return ['id' => $id, 'name' => $name, 'color' => $color, 'icon' => $icon, 'sql' => $sql, 'amount' => $amount];
    }

    /**
     * Check a query while it is typed (administrators): the same checks as when
     * saving, and its result on a month.
     *
     * @param string          $sql   The query
     * @param int|string|null $month The month (current month by default)
     * @param int|string|null $year  The year (current year by default)
     * @return array{valid: bool, amount?: float|null, error?: string, error_code?: string, params?: array<string, string|int|float>}
     *         valid, amount (if valid), error with its code and values (if not)
     */
    #[ApiRoute('/insight/check', method: 'POST', admin: true, changes: [])]
    public static function check(string $sql, int|string|null $month = null, int|string|null $year = null): array
    {
        try {
            [, , $sql] = self::validate('check', 'red', $sql);
            $amount = self::run($sql, ...Month::resolve($month, $year));
            return ['valid' => true, 'amount' => $amount];
        } catch (Throwable $e) {
            // MariaDB messages end with the place of the error: keep them readable
            return ['valid' => false, 'error' => self::cause($e)]
                + ($e instanceof ApiError ? ApiError::fields($e) : ['error_code' => 'insight_query_failed', 'params' => ['cause' => self::cause($e)]]);
        }
    }

    /**
     * The message of an error of a query, without the prefix of Db (MariaDB messages end
     * with the place of the error: kept readable).
     *
     * @param Throwable $error The error
     * @return string The message
     */
    private static function cause(Throwable $error): string
    {
        return (string)preg_replace('/^Database query failed: /', '', $error->getMessage());
    }

    /**
     * Check an icon name (Material icon), also used by the import of a backup.
     *
     * @param string|null $icon The icon
     * @return string|null The icon, or null for the default one
     * @throws Error If invalid
     */
    public static function icon(?string $icon): ?string
    {
        if ($icon === null || $icon === '') {
            return null;
        }
        if (!preg_match('/^[a-z0-9_]{1,50}$/', $icon)) {
            throw new ApiError("Invalid icon", 400, 'insight_icon_invalid');
        }
        return $icon;
    }

    /**
     * Delete an insight (administrators).
     *
     * @param int $id The insight
     * @return bool True if deleted
     * @throws Error If not found
     */
    #[ApiRoute('/insight', method: 'DELETE', admin: true, changes: ['insights'])]
    public static function delete(int $id): bool
    {
        $stmt = Db::execute("DELETE FROM budget_insight WHERE id = ?", "i", $id);
        if ($stmt->affected_rows === 0) {
            throw new ApiError("Insight not found", 404, 'insight_not_found');
        }
        return true;
    }

    /**
     * Compute the amount of an insight query for a month, in a read-only transaction.
     *
     * @param string $sql   The query with {month} and {year}
     * @param int    $month The month
     * @param int    $year  The year
     * @return float|null The amount (null if the query is invalid or fails)
     */
    public static function amount(string $sql, int $month, int $year): ?float
    {
        try {
            // Checked again: a query stored before the current checks is not run
            return self::run(self::validateQuery($sql), $month, $year);
        } catch (Throwable $e) {
            Logger::error("Insight query failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Check the fields of an insight.
     *
     * @param string $name  The name
     * @param string $color The color
     * @param string $sql   The query
     * @return array{string, string, string} [name, color, sql] normalized
     * @throws Error If a field is invalid
     */
    public static function validate(string $name, string $color, string $sql): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 20) {
            throw new ApiError("The name must contain 1 to 20 characters", 400, 'insight_name_length');
        }
        if (!in_array($color, self::COLORS, true)) {
            throw new ApiError("Invalid color", 400, 'insight_color_invalid');
        }

        return [$name, $color, self::validateQuery($sql)];
    }

    /**
     * Check the query of an insight: a single SELECT, without comment, backslash nor
     * forbidden keyword, reading only the budget tables, returning a column named amount.
     *
     * @param string $sql The query
     * @return string The query without its final semicolon and spaces
     * @throws Error If the query is invalid
     */
    private static function validateQuery(string $sql): string
    {
        $sql = rtrim(trim($sql), "; \t\r\n");
        if ($sql === '' || mb_strlen($sql) > 500) {
            throw new ApiError("The query must contain 1 to 500 characters", 400, 'insight_query_length');
        }
        if (!preg_match('/^SELECT\s/i', $sql)) {
            throw new ApiError("The query must be a SELECT", 400, 'insight_query_not_select');
        }
        // One statement, no comment (they could hide a part of the query), no backslash
        // (the end of a quoted text would depend on the SQL mode of the server)
        if (preg_match('/;|--|#|\/\*|\*\/|\\\\/', $sql)) {
            throw new ApiError("The query must be a single statement without comments nor backslashes", 400, 'insight_query_single_statement');
        }
        if (preg_match('/\b(' . self::FORBIDDEN_WORDS . ')\b/i', $sql, $match)) {
            throw new ApiError("Forbidden keyword in the query: " . strtoupper($match[1]), 400, 'insight_query_forbidden_keyword',
                ['keyword' => strtoupper($match[1])]);
        }
        self::validateTables($sql);
        if (!preg_match('/\bAS\s+`?amount`?\b/i', $sql)) {
            throw new ApiError("The query must return a column named amount (… AS amount)", 400, 'insight_query_no_amount');
        }

        return $sql;
    }

    /**
     * Check the tables of a query: no word naming a refused table, and only the readable
     * tables where a table is read (FROM, JOIN), without database name.
     *
     * @param string $sql The query
     * @return void
     * @throws Error If the query reads another table
     */
    private static function validateTables(string $sql): void
    {
        if (preg_match('/\b(' . implode('|', self::REFUSED_TABLES) . ')\b/i', $sql, $match)) {
            throw new ApiError("The query cannot read " . $match[1], 400, 'insight_query_table_refused', ['table' => $match[1]]);
        }
        foreach (SqlTables::references($sql) as $table) {
            if (!in_array(strtolower($table), [...self::READABLE_TABLES, 'dual'], true)) {
                $readable = implode(', ', self::READABLE_TABLES);
                throw new ApiError("The query cannot read $table (readable tables: $readable)", 400, 'insight_query_table_unreadable',
                    ['table' => $table, 'tables' => $readable]);
            }
        }
    }

    /**
     * Run a query on the current month to check it before saving it.
     *
     * @param string $sql The query
     * @return float|null The amount
     * @throws Error If the query fails
     */
    private static function test(string $sql): ?float
    {
        try {
            return self::run($sql, ...Month::resolve());
        } catch (Throwable $e) {
            // An error of Carbure keeps its code; a refusal of the database gives its cause
            throw $e instanceof ApiError
                ? new ApiError("Invalid query: " . $e->getMessage(), 400, $e->getErrorCode(), $e->getParams())
                : new ApiError("Invalid query: " . $e->getMessage(), 400, 'insight_query_failed', ['cause' => self::cause($e)]);
        }
    }

    /**
     * Run an insight query in a read-only transaction, with a time limit.
     *
     * @param string $sql   The query checked by validateQuery(), with {month} and {year}
     * @param int    $month The month
     * @param int    $year  The year
     * @return float|null The amount
     * @throws Throwable If the query fails, takes too long or does not return an amount
     */
    private static function run(string $sql, int $month, int $year): ?float
    {
        $sql = str_replace(['{month}', '{year}'], [(string)$month, (string)$year], $sql);

        Db::query("START TRANSACTION READ ONLY");
        try {
            $row = Db::select(self::bounded($sql))->fetch_assoc();
        } catch (Throwable $e) {
            if (in_array(Db::getConnection()->errno, self::TIMEOUT_ERRORS, true)) {
                throw new ApiError("The query takes more than " . self::$maxSeconds . " seconds", 400, 'insight_query_timeout',
                    ['seconds' => (string)self::$maxSeconds]);
            }
            throw $e;
        } finally {
            Db::query("COMMIT");
        }

        if (!is_array($row) || !array_key_exists('amount', $row)) {
            throw new ApiError("The query must return a column named amount", 400, 'insight_query_no_amount');
        }
        return $row['amount'] === null ? null : round((float)$row['amount'], 2);
    }

    /**
     * Add the time limit to a query: SET STATEMENT max_statement_time (seconds) on
     * MariaDB, the hint MAX_EXECUTION_TIME (milliseconds) on MySQL.
     *
     * @param string $sql The query (a SELECT)
     * @return string The query with its time limit
     */
    private static function bounded(string $sql): string
    {
        if (str_contains(Db::getConnection()->server_info, 'MariaDB')) {
            return "SET STATEMENT max_statement_time=" . self::$maxSeconds . " FOR " . $sql;
        }
        return (string)preg_replace('/^SELECT\b/i', 'SELECT /*+ MAX_EXECUTION_TIME(' . (int)ceil(self::$maxSeconds * 1000) . ') */', $sql, 1);
    }
}
