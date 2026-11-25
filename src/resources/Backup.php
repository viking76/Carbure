<?php

/**
 * Backup.php
 *
 * Backup of the household data (Administration > Backup, administrators): export of
 * the chosen datasets (categories, rules, budgets, insights, transactions, deleted
 * transactions) and import that deletes and replaces them. The portal makes the ZIP
 * (one JSON file per dataset and a manifest) and reads it: the API only exchanges
 * JSON (the PHP image has no zip extension). The datasets are described by
 * Carbure\Lib\BackupData.
 *
 * Import: every row is checked before anything is deleted, then the datasets are
 * replaced in one database transaction, the foreign keys checked by Carbure once the
 * rows are written: a category still used by data that is kept (transactions, rules,
 * budgets not imported) cannot disappear, the whole import is then cancelled.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\BackupData;
use Carbure\Lib\Db;
use Carbure\Lib\Installer;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\Migrator;
use Error;
use Throwable;

final class Backup {

    /**
     * Columns that refer to a category, checked after an import: table, column, dataset
     */
    private const CATEGORY_REFERENCES = [
        ['bank_transaction_category', 'parent_category', 'categories'],
        ['bank_transaction_category_keyword', 'category', 'rules'],
        ['budget', 'category', 'budgets'],
        ['bank_transaction', 'category', 'transactions'],
    ];

    /**
     * The datasets that can be saved, with their number of rows (administrators).
     *
     * @return list<array{name: string, count: int}> The datasets, in the order of their dependencies
     */
    #[ApiRoute('/backup', method: 'GET', admin: true)]
    public static function get(): array
    {
        return array_map(fn(string $name): array => ['name' => $name, 'count' => self::count($name)], self::available(BackupData::names()));
    }

    /**
     * Export datasets (administrators): the manifest and the rows of each dataset, that
     * the portal writes in a ZIP. The users are written by their username.
     *
     * @param string|null $datasets Names separated by commas (all the datasets when not given)
     * @return string JSON: format, version, created_at, carbure, schema, counts, datasets {name: rows}
     * @throws Error 400 If a dataset is unknown
     */
    #[ApiRoute('/backup/export', method: 'GET', raw: true, admin: true)]
    public static function export(?string $datasets = null): string
    {
        $names = self::available(BackupData::select($datasets));
        $usernames = self::usernames();
        $data = [];
        foreach ($names as $name) {
            $data[$name] = self::rows($name, $usernames);
        }

        $backup = [
            'format' => BackupData::FORMAT,
            'version' => BackupData::VERSION,
            'created_at' => date('c'),
            'carbure' => System::version(),
            'schema' => (new Migrator(Db::getConnection()))->version(),
            'counts' => array_map('count', $data),
            'datasets' => $data,
        ];
        Logger::info("Backup exported: " . implode(', ', $names));

        return (string)json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Import datasets (administrators): the data of each dataset given is deleted and
     * replaced by its rows, in one transaction; the others are kept. A user unknown
     * here (username of the backup) becomes the administrator who imports.
     *
     * @param array<mixed> $datasets The rows of each dataset: {categories: [...], transactions: [...]}
     * @return array{imported: array<string, int>} Number of rows written by dataset
     * @throws Error 400 If a dataset or a row is invalid, or the data would refer to missing categories (nothing changed)
     */
    #[ApiRoute('/backup/import', method: 'POST', admin: true, changes: Changes::KINDS)]
    public static function import(array $datasets): array
    {
        if ($datasets === [] || array_is_list($datasets)) {
            throw new Error("No dataset to import", 400);
        }

        $unknown = array_diff(array_keys($datasets), BackupData::names());
        if ($unknown !== []) {
            throw new Error("Unknown dataset: " . implode(', ', $unknown), 400);
        }

        // Every row checked before anything is deleted; a dataset whose table is not
        // there yet (pending migration) is not imported
        $rows = [];
        foreach (self::available(BackupData::names()) as $name) {
            if (array_key_exists($name, $datasets)) {
                $rows[$name] = BackupData::validate($name, $datasets[$name]);
            }
        }
        self::checkCategories($rows['categories'] ?? null);
        if (isset($rows['insights'])) {
            $rows['insights'] = self::checkInsights($rows['insights']);
        }

        // In place: the rows of a large import are not copied
        $rows = self::dropUnknownUsers($rows);
        self::resolveUsers($rows);
        $imported = self::replace($rows);
        Logger::info("Backup imported", $imported);

        return ['imported' => $imported];
    }

    /**
     * The datasets whose table is there: without insight_alerts before the migration
     * 2026-11-03_insight_alert.
     *
     * @param list<string> $names The datasets
     * @return list<string> The datasets available, in the same order
     */
    public static function available(array $names): array
    {
        return array_values(array_filter($names, fn(string $name): bool => $name !== 'insight_alerts' || InsightAlert::ready()));
    }

    /**
     * Number of rows of a dataset.
     *
     * @param string $name The dataset
     * @return int The number of rows
     */
    private static function count(string $name): int
    {
        $table = BackupData::DATASETS[$name]['table'];
        return (int)(Db::queryOne("SELECT COUNT(*) AS n FROM `$table`", "")['n'] ?? 0);
    }

    /**
     * Usernames by user id.
     *
     * @return array<int, string> The usernames
     */
    private static function usernames(): array
    {
        $users = Db::select("SELECT id, username FROM users", "")->fetch_all(MYSQLI_ASSOC);
        return array_column($users, 'username', 'id');
    }

    /**
     * Rows of a dataset for the backup, ordered by their key.
     *
     * @param string             $name      The dataset
     * @param array<int, string> $usernames The usernames by id
     * @return list<array<string, mixed>> The rows
     */
    private static function rows(string $name, array $usernames): array
    {
        $dataset = BackupData::DATASETS[$name];
        $columns = implode(', ', array_map(fn(string $column): string => "`$column`", self::columns($name)));
        $order = implode(', ', array_map(fn(string $key): string => "`$key`", explode(',', $dataset['key'])));
        $result = Db::select("SELECT $columns FROM `{$dataset['table']}` ORDER BY $order", "");

        $userColumns = BackupData::userColumns($name);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            foreach ($userColumns as $column) {
                $row[$column] = $row[$column] === null ? null : ($usernames[(int)$row[$column]] ?? null);
            }
            $rows[] = BackupData::encode($name, $row);
        }
        return $rows;
    }

    /**
     * The columns of a dataset in the database: without the ones of a pending migration,
     * enabled of the insights (2026-11-01_insight_enabled: exported as null, imported
     * shown), category_source of the transactions (2026-11-02_category_source: exported
     * as null, not imported), amount of the rules (2026-11-05_rule_amount: exported as
     * null, not imported), note of the budgets (2026-11-06_budget_note: exported as null, not
     * imported) and comment of the transactions (2026-11-19_transaction_comment: exported as
     * null, not imported).
     *
     * @param string $name The dataset
     * @return list<string> The columns
     */
    private static function columns(string $name): array
    {
        $columns = array_keys(BackupData::DATASETS[$name]['columns']);
        // Each column of a migration, with the check that it is applied
        $migrated = match ($name) {
            'insights' => ['enabled' => Insight::ready(...), 'position' => Insight::positionReady(...)],
            'transactions' => ['category_source' => Transaction::categorySourceReady(...), 'comment' => Transaction::commentReady(...)],
            'rules' => ['amount' => Category::amountReady(...)],
            'budgets' => ['note' => Budget::noteReady(...)],
            default => [],
        };
        $pending = array_keys(array_filter($migrated, fn(callable $ready): bool => !$ready()));
        return array_values(array_diff($columns, $pending));
    }

    /**
     * Check the imported categories as when they are saved: one level of
     * sub-categories, the parent of a sub-category being a top-level category of the
     * import, and an icon and a color accepted by Category.
     *
     * @param list<list<mixed>>|null $categories The values of the categories (id, name, parent, type, icon, color), null if not imported
     * @return void
     * @throws Error 400 If a parent is not a top-level category of the import, or an icon or a color is invalid
     */
    private static function checkCategories(?array $categories): void
    {
        if ($categories === null) {
            return;
        }
        $parents = array_column($categories, 2, 0);
        foreach ($categories as $index => [$id, , $parent, , $icon, $color]) {
            if ($parent !== 0 && ($parents[$parent] ?? null) !== 0) {
                throw new Error("categories: the parent of category $id must be a top-level category of the import", 400);
            }
            if (!preg_match(Category::ICON_PATTERN, (string)$icon) || !preg_match(Category::COLOR_PATTERN, (string)$color)) {
                throw new Error("categories, row " . ($index + 1) . ": invalid icon or color", 400);
            }
        }
    }

    /**
     * Check the imported insights as when they are saved in the portal (their query is
     * stored and run by the server), and normalize them the same way.
     *
     * @param list<list<mixed>> $insights The values of the insights (id, name, color, icon, sql, enabled, position)
     * @return list<list<mixed>> The values as saved by Insight (name and query trimmed, empty icon as null)
     * @throws Error 400 If an insight is invalid
     */
    private static function checkInsights(array $insights): array
    {
        foreach ($insights as $index => [$id, $name, $color, $icon, $sql, $enabled, $position]) {
            try {
                [$name, $color, $sql] = Insight::validate((string)$name, (string)$color, (string)$sql);
                $insights[$index] = [$id, $name, $color, Insight::icon($icon === null ? null : (string)$icon), $sql, $enabled, $position];
            } catch (Error $e) {
                throw new Error("insights, row " . ($index + 1) . ": " . $e->getMessage(), 400);
            }
        }
        return $insights;
    }

    /**
     * Replace the usernames of the rows by user ids, in place: a username unknown here
     * becomes the administrator who imports (the rows of drop_unknown_users are dropped
     * before); no user stays none where a column accepts it (deleted
     * transactions), the administrator otherwise.
     *
     * @param array<string, list<list<mixed>>> $rows The values of the rows by dataset, changed in place
     * @return void
     */
    private static function resolveUsers(array &$rows): void
    {
        $ids = array_flip(self::usernames());
        $admin = Jwt::getUserIdFromToken();
        foreach (array_keys($rows) as $name) {
            $columns = BackupData::DATASETS[$name]['columns'];
            foreach (BackupData::userColumns($name) as $column) {
                $position = (int)array_search($column, array_keys($columns), true);
                $nullable = str_starts_with($columns[$column], '?');
                foreach ($rows[$name] as &$row) {
                    $row[$position] = $row[$position] === null && $nullable ? null : ($ids[(string)$row[$position]] ?? $admin);
                }
                unset($row);
            }
        }
    }

    /**
     * Drop the rows of the users unknown here, in the datasets that do not give them to
     * the administrator who imports (drop_unknown_users: insight alerts).
     *
     * @param array<string, list<list<mixed>>> $rows The values of the rows by dataset
     * @return array<string, list<list<mixed>>> The rows, without those of unknown users there
     */
    private static function dropUnknownUsers(array $rows): array
    {
        $ids = array_flip(self::usernames());
        foreach (array_keys($rows) as $name) {
            if (BackupData::DATASETS[$name]['drop_unknown_users'] ?? false) {
                $positions = array_keys(array_intersect(array_keys(BackupData::DATASETS[$name]['columns']), BackupData::userColumns($name)));
                $rows[$name] = array_values(array_filter($rows[$name],
                    fn(array $row): bool => array_filter($positions, fn(int $position): bool => !isset($ids[(string)$row[$position]])) === []));
            }
        }
        return $rows;
    }

    /**
     * Delete and replace the datasets in one transaction: deleted in the reverse order
     * of their dependencies, written in their order, the references to the categories
     * checked at the end (the foreign keys are off meanwhile).
     *
     * @param array<string, list<list<mixed>>> $rows The values of the rows by dataset
     * @return array<string, int> Number of rows written by dataset
     * @throws Error 400 If the data refers to missing categories or cannot be written (nothing changed)
     */
    private static function replace(array $rows): array
    {
        $db = Db::getConnection();
        $mode = (string)(Db::queryOne("SELECT @@SESSION.sql_mode AS mode", "")['mode'] ?? '');
        $db->begin_transaction();
        try {
            Db::query("SET FOREIGN_KEY_CHECKS=0");
            // Category 0 keeps its id
            Db::query("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
            foreach (array_reverse(array_keys($rows)) as $name) {
                Db::query("DELETE FROM `" . BackupData::DATASETS[$name]['table'] . "`");
            }
            $imported = [];
            foreach ($rows as $name => $values) {
                $imported[$name] = self::insert($name, $values);
            }
            self::afterCategories(isset($rows['categories']));
            if (isset($rows['insights']) || isset($rows['insight_alerts'])) {
                InsightAlert::dropOrphans();
            }
            $db->commit();
            return $imported;
        } catch (Throwable $e) {
            $db->rollback();
            throw $e instanceof Error ? $e : new Error("The import failed, nothing was changed: " . $e->getMessage(), 400);
        } finally {
            Db::query("SET FOREIGN_KEY_CHECKS=1");
            Db::execute("SET SESSION sql_mode = ?", "s", $mode);
        }
    }

    /**
     * Write the rows of a dataset.
     *
     * @param string            $name   The dataset
     * @param list<list<mixed>> $values The values of the rows
     * @return int The number of rows written
     */
    private static function insert(string $name, array $values): int
    {
        if ($values === []) {
            return 0;
        }
        $dataset = BackupData::DATASETS[$name];
        $columns = self::columns($name);
        $types = implode('', array_map(fn(string $type): string => preg_match('/^\??(int|bool|user)/', $type) ? 'i' : 's',
            array_slice($dataset['columns'], 0, count($columns))));
        // The columns of a pending migration are the last ones: their values are dropped
        $values = array_map(fn(array $row): array => array_slice($row, 0, count($columns)), $values);
        $sql = "INSERT INTO `{$dataset['table']}` (`" . implode('`, `', $columns) . "`) VALUES ("
            . implode(', ', array_fill(0, count($columns), '?')) . ")";
        return Db::executeMany($sql, $types, $values);
    }

    /**
     * Once the rows are written: category 0 (default category of the transactions)
     * created if the imported categories miss it, the budget alerts of the categories
     * that no longer exist deleted, and every reference to a category checked.
     *
     * @param bool $categories True if the categories were imported
     * @return void
     * @throws Error 400 If a dataset refers to categories that do not exist
     */
    private static function afterCategories(bool $categories): void
    {
        if ($categories) {
            Installer::defaultCategory(Db::getConnection());
            Db::query("DELETE a FROM budget_alert a LEFT JOIN bank_transaction_category c ON c.id = a.category WHERE c.id IS NULL");
        }
        foreach (self::CATEGORY_REFERENCES as [$table, $column, $name]) {
            $missing = Db::select("SELECT DISTINCT t.`$column` AS id FROM `$table` t
                LEFT JOIN bank_transaction_category c ON c.id = t.`$column` WHERE c.id IS NULL ORDER BY id LIMIT 10", "")->fetch_all(MYSQLI_ASSOC);
            if ($missing !== []) {
                throw new Error("The $name refer to categories that do not exist: " . implode(', ', array_column($missing, 'id'))
                    . ". Import the categories that contain them, or the $name with the categories. Nothing was changed.", 400);
            }
        }
    }
}
