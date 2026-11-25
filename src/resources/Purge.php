<?php

/**
 * Purge.php
 *
 * Purge of the household data (Administration > Purge, administrators), to start again
 * from scratch: the chosen datasets of the backup (Carbure\Lib\BackupData) are deleted
 * in one database transaction. The data that refers to a dataset is purged with it
 * (the transactions of a purged category...): the administrator chooses it too, the
 * portal ticking it for him. The default category (id 0) is kept. The bank accounts,
 * users and settings are not purged: a synchronization fetches the transactions again.
 *
 * Refused from Internet: only from the local network (every address of the request,
 * reverse proxy headers included, private).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\BackupData;
use Carbure\Lib\Db;
use Carbure\Lib\Logger;
use Carbure\Lib\Network;
use Error;
use Throwable;

final class Purge {

    /**
     * Datasets that refer to each dataset, purged with it
     */
    public const DEPENDENTS = [
        'categories' => ['rules', 'budgets', 'transactions'],
        'insights' => ['insight_alerts'],
        'transactions' => ['transaction_tags'],
        'tags' => ['transaction_tags'],
    ];

    /**
     * Rows of a dataset that are purged (all of them when not given): the default
     * category is kept
     */
    private const WHERE = [
        'categories' => 'id <> 0',
    ];

    /**
     * Order of the deletion of the rows that refer to other rows of the same table: the
     * sub-categories before their parent, the parts of a split before the transaction
     */
    private const ORDER = [
        'categories' => 'parent_category = 0',
        'transactions' => 'split_of IS NULL',
    ];

    /**
     * The datasets that can be purged, with their number of rows and the datasets
     * purged with them, and whether the purge is allowed from here (administrators).
     *
     * @return array{local: bool, datasets: list<array{name: string, count: int, dependents: list<string>}>}
     */
    #[ApiRoute('/purge', method: 'GET', admin: true)]
    public static function get(): array
    {
        $names = Backup::available(BackupData::names());
        return [
            'local' => Network::isLocalRequest(),
            'datasets' => array_map(fn(string $name): array => [
                'name' => $name,
                'count' => self::count($name),
                'dependents' => self::dependents($name, $names),
            ], $names),
        ];
    }

    /**
     * Purge datasets (administrators, from the local network only): every row of each
     * dataset given is deleted, in one transaction. The datasets that refer to one of
     * them must be given too.
     *
     * @param array<mixed> $datasets The names of the datasets: ["transactions", "transaction_tags"]
     * @return array{purged: array<string, int>} Number of rows deleted by dataset
     * @throws ApiError 403 From Internet; 400 if a dataset is unknown or one that refers to it is missing (nothing deleted)
     */
    #[ApiRoute('/purge', method: 'POST', admin: true, changes: ['transactions', 'categories', 'budgets', 'insights', 'tags'])]
    public static function purge(array $datasets): array
    {
        if (!Network::isLocalRequest()) {
            throw new ApiError("The purge is only allowed from the local network", 403, 'purge_not_local');
        }
        $names = self::check($datasets);
        $purged = self::delete($names);
        Logger::info("Data purged", $purged);

        return ['purged' => $purged];
    }

    /**
     * Check the datasets asked: known, not empty, with the ones that refer to them.
     *
     * @param array<mixed> $datasets The names asked
     * @return list<string> The names, in the order of their dependencies
     * @throws Error 400 If none is given or one is unknown
     * @throws ApiError 400 If a dataset that refers to one of them is missing
     */
    private static function check(array $datasets): array
    {
        $asked = array_values(array_unique(array_map('strval', array_filter($datasets, 'is_scalar'))));
        if ($asked === []) {
            throw new Error("No dataset to purge", 400);
        }
        $available = Backup::available(BackupData::names());
        $unknown = array_diff($asked, $available);
        if ($unknown !== []) {
            throw new Error("Unknown dataset: " . implode(', ', $unknown), 400);
        }
        foreach ($asked as $name) {
            $missing = array_diff(self::dependents($name, $available), $asked);
            if ($missing !== []) {
                throw new ApiError("Purging $name also purges " . implode(', ', $missing) . ": choose them too", 400,
                    'purge_dependents', ['dataset' => $name, 'dependents' => implode(', ', $missing)]);
            }
        }
        return array_values(array_intersect($available, $asked));
    }

    /**
     * Every dataset that refers to a dataset, directly or not (the tags of the
     * transactions of a category), among the ones available.
     *
     * @param string       $name      The dataset
     * @param list<string> $available The datasets available
     * @return list<string> The datasets, in the order of their dependencies
     */
    private static function dependents(string $name, array $available): array
    {
        $found = [];
        $pending = self::DEPENDENTS[$name] ?? [];
        while ($pending !== []) {
            $next = (string)array_shift($pending);
            if (!in_array($next, $found, true)) {
                $found[] = $next;
                $pending = array_merge($pending, self::DEPENDENTS[$next] ?? []);
            }
        }
        return array_values(array_intersect($available, $found));
    }

    /**
     * Delete the datasets in one transaction, in the reverse order of their
     * dependencies, the foreign keys on (their cascades applied: budget alerts of the
     * categories, alert category of the users...).
     *
     * @param list<string> $names The datasets, in the order of their dependencies
     * @return array<string, int> Number of rows deleted by dataset
     * @throws Error 400 If the deletion fails (nothing deleted)
     */
    private static function delete(array $names): array
    {
        $db = Db::getConnection();
        $db->begin_transaction();
        try {
            $purged = [];
            foreach (array_reverse($names) as $name) {
                $purged[$name] = (int)Db::execute(self::deleteQuery($name), "")->affected_rows;
            }
            $db->commit();
            return array_reverse($purged);
        } catch (Throwable $e) {
            $db->rollback();
            throw new Error("The purge failed, nothing was deleted: " . $e->getMessage(), 400);
        }
    }

    /**
     * Query that deletes the rows of a dataset.
     *
     * @param string $name The dataset
     * @return string The DELETE query
     */
    private static function deleteQuery(string $name): string
    {
        $sql = "DELETE FROM `" . BackupData::DATASETS[$name]['table'] . "`";
        if (isset(self::WHERE[$name])) {
            $sql .= " WHERE " . self::WHERE[$name];
        }
        if (isset(self::ORDER[$name])) {
            $sql .= " ORDER BY " . self::ORDER[$name];
        }
        return $sql;
    }

    /**
     * Number of rows of a dataset that a purge deletes.
     *
     * @param string $name The dataset
     * @return int The number of rows
     */
    private static function count(string $name): int
    {
        $sql = "SELECT COUNT(*) AS n FROM `" . BackupData::DATASETS[$name]['table'] . "`";
        if (isset(self::WHERE[$name])) {
            $sql .= " WHERE " . self::WHERE[$name];
        }
        return (int)(Db::queryOne($sql, "")['n'] ?? 0);
    }
}
