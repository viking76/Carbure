<?php

/**
 * Tag.php
 *
 * Tags of a project (a trip, works...) limited in time: a second axis, apart from the
 * categories, to follow the whole cost of a project. A transaction keeps its category
 * (the budgets do not change) and may have several tags, set by hand. A tag is active
 * until its end date, archived after it: no more offered, its summary still readable.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Logger;
use DateTime;
use Error;
use Exception;

final class Tag {

    /** Name of a tag, without its #: lower case letters, digits, - and _ */
    const NAME_PATTERN = '/^[\p{Ll}\p{Lo}\p{N}_-]{1,40}$/u';

    /** Most transactions tagged by one call (a selection of the list) */
    const MAX_SELECTION = 500;

    /**
     * Every tag, the active ones first (most recent first), then the archived ones, with
     * the number and the net total of their transactions.
     *
     * @return list<array<string, mixed>> The tags: id, name, color, start_date, end_date
     *               (null: still running), active (its end is not past), count, total
     */
    #[ApiRoute('/tag', method: 'GET')]
    public static function get(): array
    {
        $sql = "SELECT g.id, g.name, g.color, g.start_date, g.end_date,
                       (g.end_date IS NULL OR g.end_date >= CURDATE()) AS active,
                       COUNT(b.id) AS count, COALESCE(SUM(b.amount), 0) AS total
                FROM tag g
                LEFT JOIN transaction_tag tt ON tt.tag_id = g.id
                LEFT JOIN bank_transaction b ON b.id = tt.transaction_id
                GROUP BY g.id, g.name, g.color, g.start_date, g.end_date
                ORDER BY active DESC, g.start_date DESC, g.name";
        return array_map([self::class, 'row'], Db::select($sql)->fetch_all(MYSQLI_ASSOC));
    }

    /**
     * Create a tag.
     *
     * @param string      $name       Its name (a leading # is removed, put in lower case)
     * @param string|null $color      Its color (SwiftUI name or hex, may be empty)
     * @param string|null $start_date First day of the project (YYYY-MM-DD, today by default)
     * @param string|null $end_date   Last day of the project (YYYY-MM-DD, optional)
     * @return array<string, mixed> The tag
     * @throws Error If a value is invalid (400) or the name already used (409)
     */
    #[ApiRoute('/tag', method: 'POST', changes: ['tags'])]
    public static function create(string $name, ?string $color = '', ?string $start_date = null, ?string $end_date = null): array
    {
        [$name, $color, $start, $end] = self::validate(null, $name, $color, $start_date ?: date('Y-m-d'), $end_date);
        $stmt = Db::execute("INSERT INTO tag (name, color, start_date, end_date) VALUES (?, ?, ?, ?)", "ssss", $name, $color, $start, $end);
        return self::find((int)$stmt->insert_id);
    }

    /**
     * Change a tag.
     *
     * @param int|string  $id         The id of the tag
     * @param string      $name       Its name
     * @param string|null $color      Its color
     * @param string      $start_date First day of the project (YYYY-MM-DD)
     * @param string|null $end_date   Last day of the project (YYYY-MM-DD, empty: still running)
     * @return array<string, mixed> The tag
     * @throws Error If it does not exist (404), a value is invalid (400) or the name already used (409)
     */
    #[ApiRoute('/tag', method: 'PUT', changes: ['tags'])]
    public static function update(int|string $id, string $name, ?string $color, string $start_date, ?string $end_date = null): array
    {
        self::find($id);
        [$name, $color, $start, $end] = self::validate((int)$id, $name, $color, $start_date, $end_date);
        Db::execute("UPDATE tag SET name = ?, color = ?, start_date = ?, end_date = ? WHERE id = ?", "ssssi", $name, $color, $start, $end, $id);
        return self::find($id);
    }

    /**
     * Delete a tag: its transactions lose it, nothing else changes.
     *
     * @param int|string $id The id of the tag
     * @return bool True once deleted
     * @throws Error If it does not exist (404)
     */
    #[ApiRoute('/tag', method: 'DELETE', changes: ['tags', 'transactions'])]
    public static function delete(int|string $id): bool
    {
        self::find($id);
        Db::execute("DELETE FROM tag WHERE id = ?", "i", $id);
        return true;
    }

    /**
     * Summary of a tag: the net cost of the project, by category, its transactions and
     * its evolution day by day.
     *
     * @param int|string $id The id of the tag
     * @return array<string, mixed> tag, total, count, categories [{category, total, count}]
     *               (largest expenses first), transactions (most recent first, with their
     *               tags), days [{date, total, cumulative}] (oldest first)
     * @throws Error If it does not exist (404)
     */
    #[ApiRoute('/tag/summary', method: 'GET')]
    public static function summary(int|string $id): array
    {
        $tag = self::find($id);
        $transactions = Db::select("SELECT b.* FROM bank_transaction b JOIN transaction_tag tt ON tt.transaction_id = b.id
            WHERE tt.tag_id = ? ORDER BY b.date DESC, b.rdate DESC, b.id DESC", "i", $id)->fetch_all(MYSQLI_ASSOC);

        return [
            'tag' => $tag,
            'total' => self::cents(array_sum(array_map(fn($t) => self::toCents($t['amount']), $transactions))),
            'count' => count($transactions),
            'categories' => self::byCategory($transactions),
            'transactions' => self::withTags($transactions),
            'days' => self::byDay($transactions),
        ];
    }

    /**
     * Add or remove tags on transactions (one, or a selection of the list), all at once.
     *
     * @param array<mixed> $ids    The ids of the transactions (at most MAX_SELECTION)
     * @param array<mixed> $add    The ids of the tags to add
     * @param array<mixed> $remove The ids of the tags to remove
     * @return array{updated: int} The number of links added or removed
     * @throws Error If an id is invalid (400) or a tag does not exist (404)
     */
    #[ApiRoute('/transaction/tags', method: 'PUT', changes: ['transactions'])]
    public static function tagTransactions(array $ids, array $add = [], array $remove = []): array
    {
        $ids = self::idList($ids);
        if ($ids === [] || count($ids) > self::MAX_SELECTION) {
            throw new ApiError("Select 1 to " . self::MAX_SELECTION . " transactions", 400, 'tag_selection_limit', ['max' => self::MAX_SELECTION]);
        }
        $add = self::idList($add);
        $remove = self::idList($remove);
        foreach (array_unique(array_merge($add, $remove)) as $tag) {
            self::find($tag);
        }

        $db = Db::getConnection();
        $db->begin_transaction();
        try {
            $updated = self::link($ids, $add) + self::unlink($ids, $remove);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        return ['updated' => $updated];
    }

    /**
     * Transactions with the ids of their tags (tags: [] before the migration of the tags).
     *
     * @param list<array<string, mixed>> $transactions Rows of bank_transaction
     * @return list<array<string, mixed>> The same rows, with tags
     */
    public static function withTags(array $transactions): array
    {
        $ids = array_map(fn($t) => (int)$t['id'], $transactions);
        $tags = [];
        if ($ids !== []) {
            try {
                $sql = "SELECT transaction_id, tag_id FROM transaction_tag WHERE transaction_id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY tag_id";
                foreach (Db::select($sql, str_repeat('i', count($ids)), ...$ids)->fetch_all(MYSQLI_ASSOC) as $row) {
                    $tags[(int)$row['transaction_id']][] = (int)$row['tag_id'];
                }
            } catch (Exception $e) {
                Logger::warn("Tags not read (migration 2026-10-31_tags not applied?): " . $e->getMessage());
            }
        }
        return array_map(fn($t) => $t + ['tags' => $tags[(int)$t['id']] ?? []], $transactions);
    }

    /**
     * Give the tags of a transaction to another one (a part of a split one); nothing before
     * the migration of the tags.
     *
     * @param int $from The transaction whose tags are copied
     * @param int $to   The transaction that gets them
     * @return void
     */
    public static function copyTags(int $from, int $to): void
    {
        try {
            Db::execute("INSERT IGNORE INTO transaction_tag (transaction_id, tag_id) SELECT ?, tag_id FROM transaction_tag
                WHERE transaction_id = ?", "ii", $to, $from);
        } catch (Exception $e) {
            Logger::warn("Tags not copied (migration 2026-10-31_tags not applied?): " . $e->getMessage());
        }
    }

    /**
     * Check the values of a tag.
     *
     * @param int|null    $id    The id of the tag changed (null for a new one)
     * @param string      $name  Its name
     * @param string|null $color Its color
     * @param string      $start First day (YYYY-MM-DD)
     * @param string|null $end   Last day (YYYY-MM-DD, empty: still running)
     * @return array{0: string, 1: string, 2: string, 3: string|null} name, color, start, end
     * @throws Error If a value is invalid (400) or the name already used (409)
     */
    private static function validate(?int $id, string $name, ?string $color, string $start, ?string $end): array
    {
        $name = mb_strtolower(ltrim(trim($name), '#'));
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new ApiError("The name of a tag has 1 to 40 letters, digits, - or _", 400, 'tag_name_invalid');
        }
        if (!preg_match(Category::COLOR_PATTERN, (string)$color)) {
            throw new ApiError("Invalid color", 400, 'category_style_invalid');
        }
        $end = $end === null || trim($end) === '' ? null : self::date($end);
        $start = self::date($start);
        if ($end !== null && $end < $start) {
            throw new ApiError("The end of a tag cannot be before its start", 400, 'tag_period_invalid');
        }
        if (Db::queryOne("SELECT id FROM tag WHERE name = ? AND id <> ?", "si", $name, $id ?? 0)) {
            throw new ApiError("A tag named #$name already exists", 409, 'tag_exists', ['name' => $name]);
        }
        return [$name, (string)$color, $start, $end];
    }

    /**
     * A date sent by a user.
     *
     * @param string $date The date (YYYY-MM-DD)
     * @return string The date
     * @throws Error If it is invalid (400)
     */
    private static function date(string $date): string
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ApiError("Invalid date (YYYY-MM-DD)", 400, 'tag_date_invalid');
        }
        return $date;
    }

    /**
     * A tag, by its id.
     *
     * @param int|string $id The id of the tag
     * @return array<string, mixed> The tag (see get())
     * @throws Error If it does not exist (404)
     */
    private static function find(int|string $id): array
    {
        $tag = Db::queryOne("SELECT id, name, color, start_date, end_date, (end_date IS NULL OR end_date >= CURDATE()) AS active
            FROM tag WHERE id = ?", "i", $id);
        if (!$tag) {
            throw new ApiError("Tag not found", 404, 'tag_not_found');
        }
        return self::row($tag);
    }

    /**
     * A tag as returned by the API.
     *
     * @param array<string, mixed> $row The row of the tag (count and total optional)
     * @return array<string, mixed> The tag
     */
    private static function row(array $row): array
    {
        $tag = ['id' => (int)$row['id'], 'name' => $row['name'], 'color' => $row['color'], 'start_date' => $row['start_date'],
                'end_date' => $row['end_date'], 'active' => (bool)$row['active']];
        if (array_key_exists('count', $row)) {
            $tag += ['count' => (int)$row['count'], 'total' => round((float)$row['total'], 2)];
        }
        return $tag;
    }

    /**
     * Ids sent by a user (transactions or tags).
     *
     * @param array<mixed> $ids The ids
     * @return list<int> The ids, once each
     * @throws Error If one is not a positive integer (400)
     */
    public static function idList(array $ids): array
    {
        $list = [];
        foreach ($ids as $id) {
            if (!(is_int($id) || is_string($id)) || !ctype_digit((string)$id) || (int)$id === 0) {
                throw new Error("Ids are positive integers", 400);
            }
            $list[] = (int)$id;
        }
        return array_values(array_unique($list));
    }

    /**
     * Add tags to transactions (the ones already there are kept).
     *
     * @param list<int> $ids  The transactions
     * @param list<int> $tags The tags
     * @return int The number of links added
     */
    private static function link(array $ids, array $tags): int
    {
        $added = 0;
        foreach ($tags as $tag) {
            $sql = "INSERT IGNORE INTO transaction_tag (transaction_id, tag_id) SELECT id, ? FROM bank_transaction WHERE id IN ("
                . implode(',', array_fill(0, count($ids), '?')) . ")";
            $added += (int)Db::execute($sql, 'i' . str_repeat('i', count($ids)), $tag, ...$ids)->affected_rows;
        }
        return $added;
    }

    /**
     * Remove tags from transactions.
     *
     * @param list<int> $ids  The transactions
     * @param list<int> $tags The tags
     * @return int The number of links removed
     */
    private static function unlink(array $ids, array $tags): int
    {
        if ($tags === []) {
            return 0;
        }
        $sql = "DELETE FROM transaction_tag WHERE transaction_id IN (" . implode(',', array_fill(0, count($ids), '?'))
            . ") AND tag_id IN (" . implode(',', array_fill(0, count($tags), '?')) . ")";
        return (int)Db::execute($sql, str_repeat('i', count($ids) + count($tags)), ...$ids, ...$tags)->affected_rows;
    }

    /**
     * Totals of transactions by category, largest expenses first.
     *
     * @param list<array<string, mixed>> $transactions The transactions
     * @return list<array{category: int, total: float, count: int}> The categories
     */
    private static function byCategory(array $transactions): array
    {
        $categories = [];
        foreach ($transactions as $t) {
            $category = (int)$t['category'];
            $categories[$category] ??= ['category' => $category, 'cents' => 0, 'count' => 0];
            $categories[$category]['cents'] += self::toCents($t['amount']);
            $categories[$category]['count']++;
        }
        usort($categories, fn($a, $b) => $a['cents'] <=> $b['cents']);
        return array_map(fn($c) => ['category' => $c['category'], 'total' => self::cents($c['cents']), 'count' => $c['count']], $categories);
    }

    /**
     * Totals of transactions by day, with the running total, oldest first.
     *
     * @param list<array<string, mixed>> $transactions The transactions
     * @return list<array{date: string, total: float, cumulative: float}> The days that have transactions
     */
    private static function byDay(array $transactions): array
    {
        $days = [];
        foreach ($transactions as $t) {
            $days[$t['date']] = ($days[$t['date']] ?? 0) + self::toCents($t['amount']);
        }
        ksort($days);
        $cumulative = 0;
        $result = [];
        foreach ($days as $date => $cents) {
            $cumulative += $cents;
            $result[] = ['date' => (string)$date, 'total' => self::cents($cents), 'cumulative' => self::cents($cumulative)];
        }
        return $result;
    }

    /**
     * An amount in cents.
     *
     * @param mixed $amount The amount (decimal of the database)
     * @return int The cents
     */
    private static function toCents(mixed $amount): int
    {
        return (int)round((float)$amount * 100);
    }

    /**
     * Cents as an amount.
     *
     * @param int $cents The cents
     * @return float The amount
     */
    private static function cents(int $cents): float
    {
        return round($cents / 100, 2);
    }
}
