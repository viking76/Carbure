<?php

/**
 * Transaction.php
 *
 * Bank transaction management
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\BankFile;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Logger;
use Carbure\Lib\Month;
use Carbure\Lib\SpendingAnalysis;
use Carbure\Lib\TransactionSearch;
use Carbure\Lib\Webservice;
use DateTime;
use Error;
use Exception;

// Credit or Debit
// 1 : VIREMENT
// 2 : PRELEVEMENT
// 3 : CHEQUE
// 4 : REMISE CHEQUE
// 5 : REMBOURSEMENT
// 6 : RETRAIT DAB
// 7 : FACTURE CARTE
// 8 : DEPENSE
// 9 : COMMISSIONS
// 12 : EN COURS CARTE  

final class Transaction {

    /** Days between a transaction of a file and one of the database for a probable duplicate */
    const DUPLICATE_DAYS = 3;

    /** Most parts a transaction can be split into */
    const MAX_SPLIT_PARTS = 10;

    /** SQL condition (bank_transaction without alias) of a transaction neither split nor a part
     *  of one: the rules leave them alone, as the parts have the label of their transaction */
    const NOT_SPLIT = "split_of IS NULL AND id NOT IN
        (SELECT split.split_of FROM (SELECT split_of FROM bank_transaction WHERE split_of IS NOT NULL) AS split)";

    /** Origin of a category (column category_source): set by a rule */
    const SOURCE_RULE = 'rule';

    /** Origin of a category: chosen by hand (portal, mobile apps, split, transaction entered
     *  by hand), never overwritten by the rules */
    const SOURCE_MANUAL = 'manual';

    /** Start of the UUID of a transaction entered by hand: never one of a bank (an md5, in
     *  hexadecimal), so that it is still told apart on a synchronized account, which a
     *  synchronization never overwrites (it merges on the UUID only) */
    const MANUAL_UUID = 'manual';

    /** Whether the migration 2026-11-02_category_source is applied (read once per request) */
    private static ?bool $sourceReady = null;

    /** Longest comment of a transaction, in characters */
    public const COMMENT_LENGTH = 500;

    /** Whether the migration 2026-11-19_transaction_comment is applied (read once per request) */
    private static ?bool $commentReady = null;

    /** SQL amount of the transaction t as the bank gave it: its own one plus the one of its parts */
    const BANK_AMOUNT = "(t.amount + COALESCE((SELECT SUM(p.amount) FROM bank_transaction p WHERE p.split_of = t.id), 0))";
    
    /**
     * Check if the origin of the categories is stored: the migration
     * 2026-11-02_category_source is applied (before it, the origin is not recorded).
     *
     * @return bool
     */
    public static function categorySourceReady(): bool
    {
        return self::$sourceReady ??= Db::queryOne("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction' AND COLUMN_NAME = 'category_source'", "") !== null;
    }

    /**
     * Check if the transactions have their comment: the migration
     * 2026-11-19_transaction_comment is applied (before it, the column does not exist and
     * the transactions are returned without comment).
     *
     * @return bool
     */
    public static function commentReady(): bool
    {
        return self::$commentReady ??= Db::queryOne("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction' AND COLUMN_NAME = 'comment'", "") !== null;
    }

    /**
     * SQL assignment of the origin of a category, to add after "SET category = ?" (nothing
     * before the migration 2026-11-02_category_source).
     *
     * @param string|null $source SOURCE_RULE, SOURCE_MANUAL or null (unknown)
     * @return string ", category_source = ..." or ""
     */
    public static function sourceSql(?string $source): string
    {
        if (!self::categorySourceReady()) {
            return '';
        }
        return ", category_source = " . ($source === null ? "NULL" : "'" . ($source === self::SOURCE_MANUAL ? self::SOURCE_MANUAL : self::SOURCE_RULE) . "'");
    }

    /**
     * Return the transactions of a month, optionally of one category.
     *
     * @param int|string|null $month    The month of the transactions
     * @param int|string|null $year     The year of the transactions
     * @param int|string|null $category Only this category and its sub-categories (optional)
     * @param bool|int|string|null $own With a category: only the transactions put on the category itself,
     *                                   not on its sub-categories ("true"/"1" from a query string)
     * @return list<array<string, mixed>> The list of transactions, with the ids of their tags
     */
    #[ApiRoute('/transaction', method: 'GET')]
    public static function get(int|string|null $month = null, int|string|null $year = null, int|string|null $category = null,
        bool|int|string|null $own = false): array
    {
        [$from, $to] = Month::range($month, $year);

        if ($category === null || $category === '') {
            $sql = "SELECT * FROM bank_transaction WHERE date >= ? AND date < ? ORDER BY rdate DESC";
            $stmt = Db::execute($sql, "ss", $from, $to);
        } elseif (filter_var($own, FILTER_VALIDATE_BOOLEAN)) {
            $sql = "SELECT * FROM bank_transaction WHERE date >= ? AND date < ? AND category = ? ORDER BY rdate DESC";
            $stmt = Db::execute($sql, "ssi", $from, $to, $category);
        } else {
            $sql = "SELECT * FROM bank_transaction WHERE date >= ? AND date < ?
                    AND (category = ? OR category IN (SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> 0))
                    ORDER BY rdate DESC";
            $stmt = Db::execute($sql, "ssii", $from, $to, $category, $category);
        }
        $result = Db::result($stmt);
        $transactions = array();

        // Collect the rows
        if ($result->num_rows > 0) {

            while ($row = $result->fetch_assoc()) {
                $transactions[] = $row;
            }
        }

        return Tag::withTags($transactions);
    }

    /**
     * Search transactions of every period, most recent first: by label or comment ("#name" gives the
     * transactions of the tag of this name) and by the criteria of the advanced search. Each
     * criterion is optional, at least one is needed.
     *
     * @param string|null           $query      The text to find in the label or the comment (2 characters minimum), or #tag
     * @param int|string            $limit      The maximum number of transactions (1 to 500)
     * @param int|float|string|null $min        Smallest amount, without sign (50: an expense of -50 or an income of 50)
     * @param int|float|string|null $max        Largest amount, without sign
     * @param string|null           $kind       "debit" (expenses) or "credit" (incomes)
     * @param string|null           $categories Ids of categories, comma-separated (their sub-categories included)
     * @param string|null           $accounts   Accounts, comma-separated bankIds (account_number@bank_name, see GET /bank)
     * @param string|null           $from       First day (YYYY-MM-DD)
     * @param string|null           $to         Last day (YYYY-MM-DD)
     * @param string|null           $in         "label": the text in the label only, not in the comment
     *                                          (the transactions similar to one); else both
     * @return list<array<string, mixed>> The list of transactions, with the ids of their tags
     * @throws Error If there is no criterion, the query is too short or a criterion is invalid (400)
     */
    #[ApiRoute('/transaction/search', method: 'GET')]
    public static function search(?string $query = null, int|string $limit = 100, int|float|string|null $min = null,
        int|float|string|null $max = null, ?string $kind = null, ?string $categories = null, ?string $accounts = null,
        ?string $from = null, ?string $to = null, ?string $in = null): array
    {
        [$where, $types, $params] = (new TransactionSearch($query, $min, $max, $kind, $categories, $accounts, $from, $to,
            $in === 'label'))->where();
        $limit = max(1, min(500, (int)$limit));

        $sql = "SELECT b.* FROM bank_transaction b WHERE $where ORDER BY b.date DESC, b.rdate DESC LIMIT ?";
        $rows = Db::select($sql, $types . 'i', ...[...$params, $limit])->fetch_all(MYSQLI_ASSOC);

        return Tag::withTags($rows);
    }

    /**
     * Months that have transactions, newest first: the periods offered by the portal.
     *
     * @return list<array<string, mixed>> The periods: [{year, month}]
     */
    #[ApiRoute('/transaction/periods', method: 'GET')]
    public static function periods(): array
    {
        return Db::select("SELECT DISTINCT YEAR(date) AS year, MONTH(date) AS month FROM bank_transaction ORDER BY year DESC, month DESC")
            ->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Recurring transactions of a month: the ones found about once a month at about the
     * same day (salary, rent, subscriptions, bills), from the label without its numbers.
     *
     * @param int|string|null $month  The month (current month by default)
     * @param int|string|null $year   The year (current year by default)
     * @param int|string      $months Number of previous months looked at (2 to 24)
     * @return list<array<string, mixed>> The series, by day of the month: label, category, amount (last one),
     *               average, variable (amount changing by more than 10 %), day (usual day
     *               of the month), months (number of months seen), last (date of the last
     *               one), ids (its transactions of the month), date (the one of its first
     *               transaction of the month, null if none) and status: "received", or
     *               "expected" when it is not in the month yet
     */
    #[ApiRoute('/transaction/recurring', method: 'GET')]
    public static function recurring(int|string|null $month = null, int|string|null $year = null, int|string $months = 6): array
    {
        $months = max(2, min(24, (int)$months));
        [$first, $to] = Month::range($month, $year);
        $from = date('Y-m-d', Month::timestamp("$first -$months months"));

        // A split transaction counts once, with the amount of the bank
        $sql = "SELECT t.id, t.date, t.type, t.label, t.category, " . self::BANK_AMOUNT . " AS amount
                FROM bank_transaction t WHERE t.split_of IS NULL AND t.date >= ? AND t.date < ? ORDER BY t.date, t.id";
        $rows = Db::select($sql, "ss", $from, $to)->fetch_all(MYSQLI_ASSOC);

        return self::findRecurring($rows, $first);
    }

    /**
     * Find the recurring series among transactions (see recurring()).
     *
     * A series is the transactions of the same label (without its numbers: references,
     * dates) and the same direction (debit or credit). It is recurring when it is seen in
     * 3 months at least, 1.5 times a month at most (not the bakery), mostly at the same
     * day (within 5 days), and still running: seen in the month or the month before.
     * Card payments must also keep about the same amount (within 20 %: a subscription,
     * not the restaurant); direct debits and transfers may change (electricity bill).
     *
     * @param list<array<string, mixed>> $rows  The transactions [{id, date, type, label, category, amount}], oldest first
     * @param string                     $first First day of the month (Y-m-d)
     * @return list<array<string, mixed>> The series (see recurring())
     */
    public static function findRecurring(array $rows, string $first): array
    {
        $current = substr($first, 0, 7);
        $previous = date('Y-m', Month::timestamp("$first -1 month"));

        $series = [];
        foreach (self::groupByLabel($rows) as $group) {
            $found = self::recurringSeries($group, $current, $previous);
            if ($found !== null) {
                $series[] = $found;
            }
        }

        usort($series, fn($a, $b) => $a['day'] <=> $b['day'] ?: strcmp($a['label'], $b['label']));
        return $series;
    }

    /**
     * Transactions grouped by direction and label without its numbers (see findRecurring()).
     *
     * @param list<array<string, mixed>> $rows The transactions, oldest first
     * @return array<string, list<array<string, mixed>>> The groups, by "+label" or "-label"
     */
    private static function groupByLabel(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = SpendingAnalysis::seriesKey((string)$row['label'], $row['amount']);
            if ($key !== '') {
                $groups[$key][] = $row;
            }
        }
        return $groups;
    }

    /**
     * The recurring series of a group of transactions, or null if it is not recurring
     * (see findRecurring()).
     *
     * @param list<array<string, mixed>> $group    Transactions of the same label and direction
     * @param string                     $current  The month (Y-m)
     * @param string                     $previous The month before (Y-m)
     * @return array<string, mixed>|null The series
     */
    private static function recurringSeries(array $group, string $current, string $previous): ?array
    {
        $byMonth = [];
        foreach ($group as $row) {
            $byMonth[substr($row['date'], 0, 7)][] = $row;
        }
        $count = count($byMonth);
        if ($count < 3 || count($group) > 1.5 * $count
            || (!isset($byMonth[$current]) && !isset($byMonth[$previous]))) {
            return null;
        }

        $day = self::usualDay($group);
        if ($day === null) {
            return null;
        }

        $amounts = array_map(fn($row) => (float)$row['amount'], $group);
        $average = array_sum($amounts) / count($amounts);
        $spread = max($amounts) - min($amounts);
        $last = end($group);
        if (in_array((int)$last['type'], [7, 12], true) && $spread > 0.2 * abs($average)) {
            return null;
        }
        $ids = array_map(fn($row) => (int)$row['id'], $byMonth[$current] ?? []);
        return [
            'label' => stripslashes($last['label']),
            'category' => (int)$last['category'],
            'amount' => round((float)$last['amount'], 2),
            'average' => round($average, 2),
            'variable' => $spread > 0.1 * abs($average),
            'day' => $day,
            'months' => $count,
            'last' => $last['date'],
            'ids' => $ids,
            'date' => $byMonth[$current][0]['date'] ?? null,
            'status' => $ids ? 'received' : 'expected',
        ];
    }

    /**
     * Usual day of a series: the median one, if 3 transactions out of 4 are within 5 days
     * of it (null otherwise).
     *
     * @param list<array<string, mixed>> $group The transactions of the series
     * @return int|null The day of the month
     */
    private static function usualDay(array $group): ?int
    {
        $days = array_map(fn($row) => (int)substr($row['date'], 8, 2), $group);
        sort($days);
        $day = $days[intdiv(count($days), 2)];
        $near = array_filter($days, fn($d) => min(abs($d - $day), 31 - abs($d - $day)) <= 5);
        return count($near) < 0.75 * count($days) ? null : $day;
    }

    /**
     * Load transactions from an array.
     *
     * @param array<array<string, mixed>> $data     The array of transactions
     * @param int                         $userId   The user owning the synchronized bank account
     * @param bool                        $progress Send each label as a progress event (synchronization)
     * @param array<string, string|null>  $origin   Account the transactions come from: bank_name (woob
     *                                              backend of a synchronized account), account_number
     *                                              (synchronized or imported)
     * @return list<array{label: string, amount: float, uuid: string}> The transactions that were not
     *              known yet (label cleaned, amount, uuid)
     */
    public static function save(array $data, int $userId, bool $progress = true, array $origin = []): array
    {
        $rows = self::prepareAll($data);
        if ($progress) {
            foreach ($data as $index => $transaction) {
                $rows[$index]['raw'] = self::rawLabel($transaction);
            }
        }
        $unlabeled = count(array_filter($rows, fn($row) => $row['label'] === ''));
        if ($unlabeled > 0) {
            Logger::warn("$unlabeled transactions without a label (raw and label empty) from "
                . ($origin['bank_name'] ?? 'a file') . ": check the woob module");
        }
        // Labels changed by the woob module (setting woob_relabel): the transaction saved takes them.
        // Only for a synchronization: never for a file imported on that account
        return self::store($rows, $userId, $origin, TransactionRelabel::apply(array_values($rows), $userId, $origin));
    }

    /**
     * Save prepared transactions (duplicates are merged on their UUID).
     *
     * @param array<array<string, mixed>> $rows   The transactions, as returned by prepareAll(), with
     *                                            their raw label to send it as a progress event
     * @param int                         $userId The user owning the transactions
     * @param array<string, string|null>  $origin    bank_name and account_number of the account they come from
     * @param array<string, true>         $relabeled UUIDs that relabeled a saved transaction (TransactionRelabel): not saved
     * @return list<array{label: string, amount: float, uuid: string, date: string|null}> The transactions
     *              that were not known yet (label cleaned, amount, uuid, real date); the deleted ones
     *              are skipped
     */
    private static function store(array $rows, int $userId, array $origin = [], array $relabeled = []): array
    {
        $created = [];
        // Deleted by a user: never saved again
        $deleted = self::deletedUuids(array_column($rows, 'uuid'));
        $repair = self::hasUnlabeled($userId, $origin);
        foreach ($rows as $row) {
            if (isset($row['raw'])) {
                Webservice::sendProgress($row['raw']);
            }
            if (!isset($deleted[$row['uuid']]) && !isset($relabeled[$row['uuid']]) && !($repair && self::relabel($row, $userId, $origin))
                && self::create($row, $userId, $origin)) {
                $created[] = ['label' => $row['label'], 'amount' => (float)$row['amount'], 'uuid' => $row['uuid'],
                    'date' => $row['rdate'] ?? $row['date'] ?? null];
            }
        }

        return $created;
    }

    /**
     * Check if the synchronized account has transactions saved without a label: a woob
     * module that only fills label, before Carbure read it (banquepopulaire, #328).
     *
     * @param int                        $userId The user owning the transactions
     * @param array<string, string|null> $origin bank_name and account_number of the account
     * @return bool True if some may be completed by the transactions received
     */
    private static function hasUnlabeled(int $userId, array $origin): bool
    {
        if (empty($origin['bank_name'])) {
            return false;
        }
        return Db::queryOne("SELECT id FROM bank_transaction WHERE label = '' AND user = ? AND bank_name = ?
            AND account_number <=> ? LIMIT 1", "iss", $userId, $origin['bank_name'], $origin['account_number'] ?? null) !== null;
    }

    /**
     * Give its label to a transaction of the same account saved without one (same real
     * date, amount and type) instead of saving the transaction again: it keeps its
     * category and its pointing, and gets the category of the rules if it had none.
     *
     * @param array<string, mixed>       $row    The transaction, as returned by prepare()
     * @param int                        $userId The user owning the transactions
     * @param array<string, string|null> $origin bank_name and account_number of the account
     * @return bool True if a transaction without a label was completed
     */
    private static function relabel(array $row, int $userId, array $origin): bool
    {
        if ($row['label'] === '' || Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = ?", "s", $row['uuid']) !== null) {
            return false;
        }
        $unlabeled = Db::queryOne("SELECT t.id, t.category FROM bank_transaction t WHERE t.label = '' AND t.split_of IS NULL
            AND t.user = ? AND t.bank_name = ? AND t.account_number <=> ? AND t.rdate = ? AND " . self::BANK_AMOUNT . " = ?
            AND t.type = ? ORDER BY t.id LIMIT 1", "isssdi",
            $userId, $origin['bank_name'], $origin['account_number'] ?? null, $row['rdate'], $row['amount'], $row['type']);
        if ($unlabeled === null) {
            return false;
        }
        Db::execute("UPDATE bank_transaction SET label = ?, uuid = ?, card = ? WHERE id = ?", "sssi",
            $row['label'], $row['uuid'], $row['card'], $unlabeled['id']);
        Db::execute("UPDATE bank_transaction SET label = ?, card = ? WHERE split_of = ?", "ssi",
            $row['label'], $row['card'], $unlabeled['id']);
        Changes::touch('transactions');
        $category = (int)$unlabeled['category'] === 0 ? Category::find($row['label'], null, $row['amount']) : null;
        if ($category !== null) {
            self::setCategory((int)$unlabeled['id'], $category, self::SOURCE_RULE);
        }
        return true;
    }

    /**
     * Prepare the transactions received together from one account (a synchronization or
     * a file), so that each one has its own UUID: identical transactions (same date,
     * label and amount, such as two coffees) are told apart by their occurrence. The
     * first one keeps the UUID of prepare(), the next ones get md5(uuid#n).
     *
     * @param array<array<string, mixed>> $data The transactions [{type, raw, amount, date, rdate?, card?}]
     * @return array<array<string, mixed>> The prepared transactions, with the keys of $data
     */
    public static function prepareAll(array $data): array
    {
        $rows = array_map([self::class, 'prepare'], $data);

        $groups = [];
        foreach ($rows as $index => $row) {
            $groups[$row['uuid']][] = $index;
        }
        foreach ($groups as $uuid => $indexes) {
            // Card labels truncated in the UUID: the order of the full labels is stable
            // from a synchronization to the next one, unlike the order of the bank
            usort($indexes, fn($a, $b) => strcmp($rows[$a]['label'], $rows[$b]['label']) ?: $a <=> $b);
            foreach (array_slice($indexes, 1) as $occurrence => $index) {
                $rows[$index]['uuid'] = md5($uuid . '#' . ($occurrence + 1));
            }
        }

        return $rows;
    }

    /**
     * Columns of a transaction as it is stored: cleaned label, date of the salaries
     * moved to the next month, UUID.
     *
     * @param array<string, mixed> $transaction The transaction {type, raw (or label), amount, date, rdate?, card?}
     * @return array<string, mixed> uuid, date, rdate, amount, label, type, card
     */
    public static function prepare(array $transaction): array
    {
        $type = $transaction['type'];
        $label = self::cleanLabel(self::rawLabel($transaction));
        $amount = $transaction['amount'];
        $rdate = $transaction['rdate'] ?? $transaction['date'];

        return [
            'uuid' => self::generateTransactionUuid($type, $rdate, $label, $amount),
            'date' => self::fixPrelevementDate($type, $amount, $transaction['date']),
            'rdate' => $rdate,
            'amount' => $amount,
            'label' => $label,
            'type' => $type,
            'card' => $transaction['card'] ?? '',
        ];
    }


    /**
     * Save a transaction in the database.
     *
     * @param array<string, mixed>       $row    The transaction, as returned by prepare()
     * @param int                        $userId The user owning the transaction
     * @param array<string, string|null> $origin bank_name and account_number of the account it comes from
     * @return bool True if the transaction is new, false if it was already known
     */
    private static function create(array $row, int $userId, array $origin = []): bool
    {
        ['uuid' => $uuid, 'date' => $date, 'rdate' => $rdate, 'amount' => $amount,
         'label' => $label, 'type' => $type, 'card' => $card] = $row;

        // A known transaction keeps its origin; one saved before it was stored gets it
        $sql = "INSERT INTO bank_transaction (uuid, date, rdate, amount, label, type, card, bank_name, account_number, user)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE type=?, label=?,
                    bank_name = IFNULL(bank_name, VALUES(bank_name)),
                    account_number = IFNULL(account_number, VALUES(account_number))";

        $stmt = Db::execute($sql, "sssdsisssiis", $uuid, $date, $rdate, $amount, $label, $type, $card,
            $origin['bank_name'] ?? null, $origin['account_number'] ?? null, $userId, $type, $label);

        // MySQL reports 1 affected row for an insert, 2 or 0 for an update of a known UUID
        // (0: unchanged, the daily synchronization of an account that did not move)
        if ($stmt->affected_rows > 0) {
            Changes::touch('transactions');
        }
        return $stmt->affected_rows === 1;
    }

    /**
     * Read a statement file downloaded from a bank (OFX/QFX, QIF, CAMT.053, CSV) and
     * tell, for each transaction, whether it would be new: "known" (same UUID, it would
     * be merged), "duplicate" (a transaction of the same amount a few days apart, most
     * likely the same one synchronized with another label) or "new".
     *
     * @param string|null $file     The content of the file, base64 encoded
     * @param string|null $filename The name of the file (helps to tell the format)
     * @return array<string, mixed> format, bank ({id, name} of the profile that read a CSV file,
     *               null when its columns were guessed or for another format), account, from, to,
     *               counts {new, known, duplicate} and rows
     *               [{index, date, rdate, amount, label, type, status, match?}]
     * @throws Error If the file is not understood
     */
    #[ApiRoute('/transaction/import/preview', method: 'POST', changes: [])]
    public static function previewImport(?string $file, ?string $filename = null): array
    {
        // No file: refused by the parser as an empty file, as before the types
        [$parsed, $rows] = self::analyzeImport($file ?? '', $filename);
        $counts = ['new' => 0, 'known' => 0, 'duplicate' => 0];
        foreach ($rows as $row) {
            $counts[$row['status']]++;
        }
        $dates = array_column($rows, 'date');
        if ($dates === []) {
            throw new ApiError("No transaction found in the file", 422, 'import_no_transaction');
        }

        return [
            'format' => $parsed['format'],
            // Bank whose profile read a CSV file (added after the first version: optional)
            'bank' => $parsed['bank'],
            'account' => $parsed['account'],
            'from' => min($dates),
            'to' => max($dates),
            'counts' => $counts,
            'rows' => $rows,
        ];
    }

    /**
     * Import a statement file: its transactions are saved for the current user, then
     * categorized with the rules. The known ones are skipped; the probable duplicates
     * only when they are not selected.
     *
     * @param string|null                           $file     The content of the file, base64 encoded
     * @param string|null                           $filename The name of the file
     * @param array<int|string>|int|string|null     $selected Indexes of the rows to import (by default:
     *                                                        the new ones)
     * @param int|string|null                       $account  Account of the transactions (id of GET /bank); by
     *                                                        default the account number read in the file, if any
     * @return array{imported: int, categorized: int, from: string|null, to: string|null}
     *              imported (number of new transactions), categorized, from, to
     * @throws Error If the file is not understood, or the account does not exist (404)
     */
    #[ApiRoute('/transaction/import', method: 'POST', changes: ['transactions'])]
    public static function import(?string $file, ?string $filename = null, array|int|string|null $selected = null,
        int|string|null $account = null): array
    {
        $origin = self::importOrigin($account);
        [$parsed, $rows, $prepared] = self::analyzeImport($file ?? '', $filename);
        $selected = $selected === null ? null : array_map('intval', (array)$selected);

        // Prepared with the whole file: a selected row keeps the UUID of its occurrence
        $data = [];
        foreach ($rows as $row) {
            $wanted = $selected === null ? $row['status'] === 'new' : in_array($row['index'], $selected, true);
            if ($wanted && $row['status'] !== 'known') {
                $data[] = $prepared[$row['index']];
            }
        }

        $created = self::store($data, (int)Jwt::getUserIdFromToken(), $origin ?? ['account_number' => $parsed['account']]);

        // The rules categorize the new transactions, whatever their date
        $categorized = self::categorizeImported($created);
        Logger::info("Import of a " . $parsed['format'] . " file (" . ($parsed['bank']['id'] ?? 'generic') . "): " . count($created) . " new transaction(s), $categorized categorized");

        // Budget and insight alerts: only the current month (an import of past months alerts nothing)
        if ($created && self::touchesCurrentMonth($data)) {
            BudgetAlert::check();
            InsightAlert::check();
        }

        $dates = array_column($data, 'date');
        return [
            'imported' => count($created),
            'categorized' => $categorized,
            'from' => $dates ? min($dates) : null,
            'to' => $dates ? max($dates) : null,
        ];
    }

    /**
     * Account chosen for the transactions of an imported file.
     *
     * @param int|string|null $account Id of the account (bank_account), null or "" for none
     * @return array{bank_name: string, account_number: string|null}|null Its bank and number (null for an
     *              account without number, as its synchronized transactions), null when none was chosen
     * @throws Error If the account does not exist (404)
     */
    private static function importOrigin(int|string|null $account): ?array
    {
        if ($account === null || $account === '') {
            return null;
        }
        $bank = Db::queryOne("SELECT bank_name, account_number FROM bank_account WHERE id = ?", "i", (int)$account);
        if ($bank === null) {
            throw new ApiError("Account not found", 404, 'account_not_found');
        }
        $number = $bank['account_number'];
        return ['bank_name' => (string)$bank['bank_name'], 'account_number' => $number === null ? null : (string)$number];
    }

    /**
     * Categorize imported transactions with the rules (those still without category).
     *
     * @param list<array{label: string, amount: float, uuid: string}> $created The new transactions
     * @return int The number of transactions categorized
     */
    private static function categorizeImported(array $created): int
    {
        $categorized = 0;
        $keywords = Category::loadKeywords();
        foreach ($created as $transaction) {
            $category = Category::find($transaction['label'], $keywords, $transaction['amount']);
            if ($category !== null) {
                Db::execute("UPDATE bank_transaction SET category = ?" . self::sourceSql(self::SOURCE_RULE)
                    . " WHERE uuid = ? AND category = 0", "is", $category, $transaction['uuid']);
                $categorized++;
            }
        }
        return $categorized;
    }

    /**
     * Whether transactions are dated in the current month.
     *
     * @param array<array<string, mixed>> $transactions The transactions (date, Y-m-d)
     * @return bool
     */
    private static function touchesCurrentMonth(array $transactions): bool
    {
        $first = date('Y-m-01');
        foreach ($transactions as $transaction) {
            if ((string)$transaction['date'] >= $first) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse a statement file and compare its transactions with the database.
     *
     * @param string      $file     The content of the file, base64 encoded
     * @param string|null $filename The name of the file
     * @return array{0: array{format: string, bank: array{id: string, name: string}|null, account: string|null, transactions: list<array<string, mixed>>},
     *               1: list<array<string, mixed>>, 2: array<array<string, mixed>>}
     *               [parsed file (BankFile::parse), rows with their status, transactions as
     *               prepared by prepareAll()]
     * @throws Error If the file is not understood
     */
    private static function analyzeImport(string $file, ?string $filename): array
    {
        $content = base64_decode($file, true);
        if ($content === false) {
            throw new Error("The file must be base64 encoded", 400);
        }
        $parsed = BankFile::parse($content, $filename);

        $prepared = self::prepareAll($parsed['transactions']);
        $rows = [];
        foreach ($prepared as $index => $row) {
            $rows[] = ['index' => $index, 'date' => $row['date'], 'rdate' => $row['rdate'], 'amount' => $row['amount'],
                       'label' => stripslashes($row['label']), 'type' => $row['type'], 'uuid' => $row['uuid'], 'status' => 'new'];
        }

        // Transactions of the period of the file (and a few days around)
        $dates = array_merge(array_column($rows, 'date'), array_column($rows, 'rdate'));
        if ($dates === []) {
            throw new ApiError("No transaction found in the file", 422, 'import_no_transaction');
        }
        $from = date('Y-m-d', Month::timestamp(min($dates) . ' -' . self::DUPLICATE_DAYS . ' days'));
        $to = date('Y-m-d', Month::timestamp(max($dates) . ' +' . self::DUPLICATE_DAYS . ' days'));
        // A split transaction is matched with the amount of the bank, its parts are not
        $existing = Db::select("SELECT t.id, t.uuid, t.date, t.rdate, " . self::BANK_AMOUNT . " AS amount, t.label
            FROM bank_transaction t WHERE t.split_of IS NULL AND (t.date BETWEEN ? AND ? OR t.rdate BETWEEN ? AND ?)",
            "ssss", $from, $to, $from, $to)->fetch_all(MYSQLI_ASSOC);
        $matched = self::markKnown($rows, $existing);
        self::markDeleted($rows);
        self::markDuplicates($rows, $existing, $matched);

        foreach ($rows as &$row) {
            unset($row['uuid']);
        }
        unset($row);
        return [$parsed, $rows, $prepared];
    }

    /**
     * Mark the rows of a file whose UUID is in the database (identical rows of the file
     * have their own UUID).
     *
     * @param list<array<string, mixed>>  $rows     The rows of the file (status updated)
     * @param list<array<string, mixed>>  $existing The transactions of the database around the file
     * @return array<int|string, true> The ids of the transactions of the database matched
     */
    private static function markKnown(array &$rows, array $existing): array
    {
        $uuids = array_flip(array_column($existing, 'uuid'));
        $matched = [];
        foreach ($rows as &$row) {
            if (isset($uuids[$row['uuid']])) {
                $row['status'] = 'known';
                $matched[$existing[$uuids[$row['uuid']]]['id']] = true;
            }
        }
        unset($row);
        return $matched;
    }

    /**
     * Mark the rows of a file deleted by a user: "known" (not imported again), with
     * deleted = true.
     *
     * @param list<array<string, mixed>> $rows The rows of the file (status updated)
     * @return void
     */
    private static function markDeleted(array &$rows): void
    {
        $deleted = self::deletedUuids(array_column($rows, 'uuid'));
        foreach ($rows as &$row) {
            if (isset($deleted[$row['uuid']])) {
                $row['status'] = 'known';
                $row['deleted'] = true;
            }
        }
        unset($row);
    }

    /**
     * UUIDs among the given ones of the transactions deleted by a user.
     *
     * @param list<mixed> $uuids The UUIDs to check
     * @return array<string, true> The deleted UUIDs (as keys)
     */
    private static function deletedUuids(array $uuids): array
    {
        $uuids = array_values(array_unique(array_map('strval', $uuids)));
        if (!$uuids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($uuids), '?'));
        $rows = Db::select("SELECT uuid FROM bank_transaction_deleted WHERE uuid IN ($placeholders)",
            str_repeat('s', count($uuids)), ...$uuids)->fetch_all(MYSQLI_ASSOC);
        return array_fill_keys(array_column($rows, 'uuid'), true);
    }

    /**
     * Mark the new rows of a file that a transaction of the database already has: same
     * amount, a few days apart; each transaction of the database matches one row at most.
     *
     * @param list<array<string, mixed>>  $rows     The rows of the file (status and match updated)
     * @param list<array<string, mixed>>  $existing The transactions of the database around the file
     * @param array<int|string, true>     $matched  The ids of the transactions already matched (updated)
     * @return void
     */
    private static function markDuplicates(array &$rows, array $existing, array &$matched): void
    {
        foreach ($rows as &$row) {
            if ($row['status'] !== 'new') {
                continue;
            }
            $transaction = self::findDuplicate($row, $existing, $matched);
            if ($transaction !== null) {
                $row['status'] = 'duplicate';
                $row['match'] = ['id' => (int)$transaction['id'], 'date' => $transaction['date'], 'label' => $transaction['label']];
                $matched[$transaction['id']] = true;
            }
        }
        unset($row);
    }

    /**
     * First transaction of the database not matched yet with the amount of a row and
     * at most DUPLICATE_DAYS days apart from it.
     *
     * @param array<string, mixed>        $row      The row of the file
     * @param list<array<string, mixed>>  $existing The transactions of the database around the file
     * @param array<int|string, true>     $matched  The ids of the transactions already matched
     * @return array<string, mixed>|null The transaction
     */
    private static function findDuplicate(array $row, array $existing, array $matched): ?array
    {
        foreach ($existing as $transaction) {
            if (isset($matched[$transaction['id']]) || abs((float)$transaction['amount'] - (float)$row['amount']) >= 0.005) {
                continue;
            }
            $gap = min(self::daysBetween($transaction['date'], $row['date']), self::daysBetween($transaction['rdate'], $row['rdate']));
            if ($gap <= self::DUPLICATE_DAYS) {
                return $transaction;
            }
        }
        return null;
    }

    /**
     * Number of days between two dates.
     *
     * @param string|null $a A date (Y-m-d)
     * @param string|null $b Another date
     * @return int The number of days (a large number if a date is missing)
     */
    private static function daysBetween(?string $a, ?string $b): int
    {
        if (!$a || !$b) {
            return PHP_INT_MAX;
        }
        return (int)abs((strtotime($a) - strtotime($b)) / 86400);
    }

    /**
     * Generate a unique identifier for a transaction.
     *
     * @param int|string       $type   The type of the transaction
     * @param string           $rdate  The real date of the transaction
     * @param string           $label  The label of the transaction
     * @param int|float|string $amount The amount of the transaction (as received: it is part of the UUID)
     * @return string The UUID
     */
    private static function generateTransactionUuid(int|string $type, string $rdate, string $label, int|float|string $amount): string
    {
        // For card transactions (types 7 and 12), truncate label to 24 chars to avoid duplicates
        $labelPart = ($type == 7 || $type == 12) ? substr($label, 0, 24) : $label;
        return md5($rdate . $labelPart . $amount);
    }

    /** Day of the month from which a received transfer counts for the next month, when
     *  next_month_day is not set */
    const NEXT_MONTH_DAY = 25;

    /**
     * Fix the date of a transaction: a received transfer (type 1, typically the salary)
     * dated from the day next_month_day of the month is attached to the 1st of the next
     * month, the month it pays for. rdate keeps the real date (and the UUID).
     *
     * @param int|string       $type   The type of the transaction
     * @param int|float|string $amount The amount of the transaction
     * @param string           $date   The date of the transaction
     * @return string The corrected date
     */
    private static function fixPrelevementDate(int|string $type, int|float|string $amount, string $date): string
    {
        $day = self::nextMonthDay();
        if ($type == 1 && $day > 0 && intval(date("d", Month::timestamp($date))) >= $day) {
            $date = date("Y-m-d", Month::timestamp("first day of next month", Month::timestamp($date)));
        }

        return $date;
    }

    /**
     * The setting next_month_day: 1 to 31, 0 to never move a transfer; 25 when it is not
     * set or not a number.
     *
     * @return int
     */
    private static function nextMonthDay(): int
    {
        // Not Config::has(): it takes "0" for an unset setting
        try {
            $value = Config::get('next_month_day');
        } catch (Exception $e) {
            return self::NEXT_MONTH_DAY;
        }
        return is_numeric($value) ? max(0, min(31, (int)$value)) : self::NEXT_MONTH_DAY;
    }

    /**
     * Label of a transaction as received: raw, or label when the woob module only fills
     * that one (banquepopulaire, #328).
     *
     * @param array<string, mixed> $transaction The transaction {raw?, label?...}
     * @return string The label, empty when the module gave none
     */
    private static function rawLabel(array $transaction): string
    {
        $raw = trim((string)($transaction['raw'] ?? ''));
        return $raw !== '' ? $raw : trim((string)($transaction['label'] ?? ''));
    }

    /**
     * Clean the label of a transaction.
     *
     * @param string|null $label The label to clean (none: an empty label)
     * @return string The cleaned label
     */
    public static function cleanLabel(?string $label): string
    {
        $label = strtoupper((string)$label);
        $label = addslashes($label);
        $label = trim($label);

        // Fix the label of the transaction
        // for each $SETTINGS['regex_label'] we replace the label by the $SETTINGS['replace_label']
        foreach (Config::get('regex_label') as $regex => $replace) {

            // An invalid expression of the configuration leaves the label as it is
            $label = preg_replace($regex, $replace, $label) ?? $label;
        }

        return $label;
    }

    /**
     * Count the number of unpointed transactions of the household.
     *
     * @return int The number of unpointed transactions
     */
    public static function countUnpointed(): int
    {
        // Count the number of transactions with pointed=0 for the current month only
        $sql = "SELECT COUNT(*) as count FROM bank_transaction WHERE pointed=0 AND date >= ? AND date < ?";
        $stmt = Db::execute($sql, "ss", ...Month::range());

        return (int)(Db::result($stmt)->fetch_assoc()['count'] ?? 0);
    }   

    /**
     * Update the category of transactions without category.
     *
     * @param bool $all Whether to update all transactions or only the last month
     * @return int The number of transactions categorized
     */
    public static function updateMissingCategories(bool $all = false): int
    {
        if ($all) {
            // We update all transactions without category
            $sql = "SELECT * FROM bank_transaction WHERE category=0 AND " . self::NOT_SPLIT;
        } else {
            // We update only transactions from the last 1 month without category
            $sql = "SELECT * FROM bank_transaction WHERE category=0 AND date >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH) AND " . self::NOT_SPLIT;
        }

        // Get the list of transactions without category
        $result = Db::select($sql);
        $updated = 0;

        // The rules are loaded once for all the transactions
        $keywords = Category::loadKeywords();

        // For each transaction found without category
        while ($row = $result->fetch_assoc()) {

            // Get the label
            $label = (string)$row["label"];

            // Get the category
            $category = Category::find($label, $keywords, $row['amount']);

            // A category has been found we update the transaction
            if ($category !== null) {

                // Update the transaction
                self::setCategory((int)$row["id"], $category, self::SOURCE_RULE);
                $updated++;
            }
        }
        if ($updated > 0) {
            Changes::touch('transactions');
        }

        return $updated;
    }

    /**
     * Set a category on a transaction, chosen by hand (portal, mobile apps): the rules
     * never overwrite it.
     *
     * @param int|string           $id          The id of the transaction
     * @param int|string           $category    The category to set
     * @param bool|int|string|null $autoPointed Whether to auto-point the transaction (any true value)
     * @return void
     */
    #[ApiRoute('/transaction/category', method: 'PUT', changes: ['transactions'])]
    public static function updateCategory(int|string $id, int|string $category, bool|int|string|null $autoPointed = true): void
    {
        self::setCategory($id, $category, self::SOURCE_MANUAL);
        if ($autoPointed) {
            self::updatePointed($id);
        }
    }

    /**
     * Set one category, chosen by hand, on several transactions at once (a selection of the
     * list), all or none: the rules never overwrite it. Like a single change, it also
     * points them, with the parts of the split ones.
     *
     * @param array<mixed>         $ids         The ids of the transactions (at most Tag::MAX_SELECTION)
     * @param int|string           $category    The category to set (0: uncategorized)
     * @param bool|int|string|null $autoPointed Whether to auto-point the transactions (true by default)
     * @return array{updated: int} The number of transactions whose category changed
     * @throws Error If an id is invalid (400), the selection too large (400) or the category unknown (404)
     */
    #[ApiRoute('/transaction/categories', method: 'PUT', changes: ['transactions'])]
    public static function updateCategories(array $ids, int|string $category, bool|int|string|null $autoPointed = true): array
    {
        $ids = Tag::idList($ids);
        if ($ids === [] || count($ids) > Tag::MAX_SELECTION) {
            throw new ApiError("Select 1 to " . Tag::MAX_SELECTION . " transactions", 400, 'tag_selection_limit', ['max' => Tag::MAX_SELECTION]);
        }
        $category = self::existingCategory($category);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $db = Db::getConnection();
        $db->begin_transaction();
        try {
            $updated = Db::execute("UPDATE bank_transaction SET category = ?" . self::sourceSql(self::SOURCE_MANUAL)
                . " WHERE id IN ($in)", "i$types", $category, ...$ids)->affected_rows;
            if (filter_var($autoPointed, FILTER_VALIDATE_BOOLEAN)) {
                Db::execute("UPDATE bank_transaction SET pointed = 1 WHERE id IN ($in) OR split_of IN ($in)", "$types$types", ...$ids, ...$ids);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        return ['updated' => (int)$updated];
    }

    /**
     * Set a category on a transaction, with its origin.
     *
     * @param int|string $id       The id of the transaction
     * @param int|string $category The category to set
     * @param string     $source   SOURCE_RULE or SOURCE_MANUAL
     * @return void
     */
    private static function setCategory(int|string $id, int|string $category, string $source): void
    {
        // Db::execute() throws on a failure
        Db::execute("UPDATE bank_transaction SET category = ?" . self::sourceSql($source) . " WHERE id = ?", "ii", $category, $id);
    }

    /**
     * Point (or unpoint) a transaction in database, with the parts it was split into.
     *
     * @param int|string           $id      The id of the transaction
     * @param bool|int|string|null $pointed True to point the transaction, false to unpoint it
     *                                      (also "true"/"false", 1/0)
     * @return void
     */
    #[ApiRoute('/transaction/pointed', method: 'PUT', changes: ['transactions'])]
    public static function updatePointed(int|string $id, bool|int|string|null $pointed = true): void
    {
        // Update the pointed field on a transaction
        $sql = "UPDATE bank_transaction SET pointed=? WHERE id=? OR split_of=?";
        Db::execute($sql, "iii", filter_var($pointed, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, $id, $id);
    }

    /**
     * Set the free-text comment of a transaction (synchronized, imported, entered by hand or
     * a part of a split one). The synchronization and the import never change it.
     *
     * @param int|string  $id      The id of the transaction
     * @param string|null $comment The comment (500 characters at most, trimmed); "" or null removes it
     * @return array<string, mixed> The transaction, with the ids of its tags
     * @throws Error If the transaction does not exist (404), the comment is too long (400), or
     *               sent before the migration 2026-11-19_transaction_comment (409)
     */
    #[ApiRoute('/transaction/comment', method: 'PUT', changes: ['transactions'])]
    public static function updateComment(int|string $id, ?string $comment = null): array
    {
        $transaction = self::manualRow($id);
        self::saveComment($transaction['id'], self::commentToStore($comment));
        return Tag::withTags([self::manualRow($id)])[0];
    }

    /**
     * A comment sent by a user, checked before anything is written: trimmed, null when empty
     * or not sent.
     *
     * @param string|null $comment The comment sent
     * @return string|null The comment to store, null for none
     * @throws Error If it is too long (400), or not empty before the migration
     *               2026-11-19_transaction_comment (409)
     */
    private static function commentToStore(?string $comment): ?string
    {
        $comment = self::comment((string)$comment);
        if ($comment !== null && !self::commentReady()) {
            throw new ApiError("The comment of the transactions needs the pending database migrations", 409,
                'transaction_comment_migration');
        }
        return $comment;
    }

    /**
     * Store the comment of a transaction (nothing to store before the migration
     * 2026-11-19_transaction_comment: commentToStore() only lets null through then).
     *
     * @param mixed       $id      The id of the transaction
     * @param string|null $comment The comment checked by commentToStore(), null for none
     * @return void
     */
    private static function saveComment(mixed $id, ?string $comment): void
    {
        if (self::commentReady()) {
            Db::execute("UPDATE bank_transaction SET comment = ? WHERE id = ?", "si", $comment, $id);
        }
    }

    /**
     * A comment of a transaction as stored: trimmed, null when empty.
     *
     * @param string $comment The comment sent
     * @return string|null The comment, null for none
     * @throws Error If it is longer than COMMENT_LENGTH characters (400)
     */
    private static function comment(string $comment): ?string
    {
        $comment = trim($comment);
        if (mb_strlen($comment) > self::COMMENT_LENGTH) {
            throw new ApiError("The comment of a transaction has " . self::COMMENT_LENGTH . " characters at most", 400,
                'transaction_comment_length', ['max' => self::COMMENT_LENGTH]);
        }
        return $comment === '' ? null : $comment;
    }

    /**
     * Change the date a transaction is counted on (its month, for the totals and the
     * budgets), synchronized or not: the real date of the bank (rdate) and the UUID are
     * kept, so that a synchronization or an import merges it again without changing this
     * date (they never update the date of a known transaction). The parts of a split
     * transaction follow it: the date of a part is the one of its transaction.
     *
     * @param int|string $id   The id of the transaction (or of a part of it)
     * @param string     $date The new date (YYYY-MM-DD)
     * @return array<string, mixed> The transaction of the id given
     * @throws Error If it does not exist (404) or the date is invalid (400)
     */
    #[ApiRoute('/transaction/date', method: 'PUT', changes: ['transactions'])]
    public static function updateDate(int|string $id, string $date): array
    {
        self::checkDate($date);
        $transaction = self::manualRow($id);
        $parent = $transaction['split_of'] ?? $transaction['id'];

        Db::execute("UPDATE bank_transaction SET date = ? WHERE id = ? OR split_of = ?", "sii", $date, $parent, $parent);
        // Moved into the current month: a budget may be exceeded now
        $current = date('Y-m');
        if (substr($date, 0, 7) === $current && substr((string)$transaction['date'], 0, 7) !== $current) {
            BudgetAlert::check();
            InsightAlert::check();
        }
        return Tag::withTags([self::manualRow($id)])[0];
    }

    /**
     * Check a date sent by a user.
     *
     * @param string $date The date (YYYY-MM-DD, a real day)
     * @return void
     * @throws Error If it is invalid (400)
     */
    private static function checkDate(string $date): void
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ApiError("Invalid date (YYYY-MM-DD)", 400, 'transaction_date_invalid');
        }
    }

    /**
     * Split a transaction across several categories: each part becomes a transaction of
     * its own (same date, label and account, split_of = the id of the transaction), and the
     * transaction keeps the rest of the amount of the bank, in its category. The parts sent
     * replace the previous ones: no part cancels the split. The parts get the tags of the
     * transaction. The synchronization never changes the amount of a known transaction, so
     * the split is kept.
     *
     * @param int|string   $id    The id of the transaction (not a part of one)
     * @param array<mixed> $parts [{category, amount}]: at most MAX_SPLIT_PARTS amounts of the
     *                            sign of the transaction, leaving it a rest
     * @return list<array<string, mixed>> The transaction, then its parts
     * @throws Error If the transaction does not exist (404) or a part is not valid (400)
     */
    #[ApiRoute('/transaction/split', method: 'PUT', changes: ['transactions'])]
    public static function split(int|string $id, array $parts = []): array
    {
        $transaction = Db::queryOne("SELECT t.*, ROUND(" . self::BANK_AMOUNT . " * 100) AS bank_cents
            FROM bank_transaction t WHERE t.id = ?", "i", $id);
        if (!$transaction) {
            throw new ApiError("Transaction not found", 404, 'transaction_not_found');
        }
        if ($transaction['split_of'] !== null) {
            throw new ApiError("A part of a split transaction cannot be split", 400, 'split_of_part');
        }

        self::replaceSplit($transaction, self::splitParts($parts, (int)$transaction['bank_cents']));
        return Tag::withTags(Db::select("SELECT * FROM bank_transaction WHERE id = ? OR split_of = ? ORDER BY split_of IS NOT NULL, id",
            "ii", $id, $id)->fetch_all(MYSQLI_ASSOC));
    }

    /**
     * Check the parts of a split (see split()).
     *
     * @param array<mixed> $parts [{category, amount}]
     * @param int          $bank  The amount of the bank of the transaction, in cents
     * @return list<array{0: int, 1: int}> The category and the amount in cents of each part
     * @throws Error If a part is not valid (400)
     */
    public static function splitParts(array $parts, int $bank): array
    {
        if (count($parts) > self::MAX_SPLIT_PARTS) {
            throw new ApiError("At most " . self::MAX_SPLIT_PARTS . " parts", 400, 'split_limit', ['max' => self::MAX_SPLIT_PARTS]);
        }
        $result = [];
        foreach ($parts as $part) {
            [$category, $cents] = self::splitPart($part);
            if ($cents === 0 || ($cents < 0) !== ($bank < 0)) {
                throw new ApiError("Each part has an amount of the sign of the transaction", 400, 'split_sign');
            }
            $result[] = [$category, $cents];
        }
        if ($result !== [] && abs(array_sum(array_column($result, 1))) >= abs($bank)) {
            throw new ApiError("The parts must leave a rest to the transaction", 400, 'split_total');
        }
        return $result;
    }

    /**
     * One part of a split sent by a user.
     *
     * @param mixed $part {category, amount}
     * @return array{0: int, 1: int} The category and the amount in cents
     * @throws Error If it is not valid (400) or its category does not exist (404)
     */
    private static function splitPart(mixed $part): array
    {
        $category = is_array($part) ? ($part['category'] ?? null) : null;
        $amount = is_array($part) ? ($part['amount'] ?? null) : null;
        if (!(is_int($category) || is_string($category)) || !is_numeric($amount)) {
            throw new Error("Each part is {category, amount}", 400);
        }
        return [self::existingCategory($category), (int)round((float)$amount * 100)];
    }

    /**
     * Replace the parts of a transaction, all at once: it keeps the rest of the amount of the bank.
     *
     * @param array<string, mixed>        $transaction The row of the transaction, with bank_cents
     * @param list<array{0: int, 1: int}> $parts       The category and the amount in cents of each part
     * @return void
     * @throws Exception If the database fails (nothing is changed)
     */
    private static function replaceSplit(array $transaction, array $parts): void
    {
        $id = (int)$transaction['id'];
        $rest = (int)$transaction['bank_cents'] - array_sum(array_column($parts, 1));
        // The parts are re-created: their comments follow them, by their UUID (same index)
        $comments = [];
        if (self::commentReady()) {
            foreach (Db::select("SELECT uuid, comment FROM bank_transaction WHERE split_of = ? AND comment IS NOT NULL", "i", $id) as $row) {
                $comments[$row['uuid']] = $row['comment'];
            }
        }
        $db = Db::getConnection();
        $db->begin_transaction();
        try {
            Db::execute("DELETE FROM bank_transaction WHERE split_of = ?", "i", $id);
            Db::execute("UPDATE bank_transaction SET amount = ? WHERE id = ?", "si", sprintf('%.2f', $rest / 100), $id);
            foreach ($parts as $index => [$category, $cents]) {
                $uuid = md5($transaction['uuid'] . '#split' . ($index + 1));
                $part = self::insertRow(['split_of' => $id, 'uuid' => $uuid, 'comment' => $comments[$uuid] ?? null,
                    'category' => $category, 'category_source' => self::SOURCE_MANUAL,
                    'amount' => sprintf('%.2f', $cents / 100)] + $transaction);
                // A part of a project stays in it; its tags may then change apart
                Tag::copyTags($id, $part);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Enter a transaction by hand on an account: a manual one (cash, a bank woob does not
     * support) or a synchronized one (a transaction the bank does not send yet; a duplicate
     * is possible when it does). Checked, of the category given or else of the rules, it
     * stays editable (PUT /transaction) and is never overwritten by a synchronization.
     *
     * @param int|string      $account  The id of an account of the household
     * @param string          $date     Its date (YYYY-MM-DD)
     * @param string          $label    Its label
     * @param int|float|string $amount  Its amount: negative for an expense
     * @param int|string|null $category Its category (optional: the rules, else none)
     * @param string|null     $comment  Its comment (optional, 500 characters at most)
     * @return array<string, mixed> The transaction
     * @throws Error If a value is invalid (400), the account or the category does not exist (404),
     *               or a comment is sent before the migration 2026-11-19_transaction_comment (409)
     */
    #[ApiRoute('/transaction', method: 'POST', changes: ['transactions'])]
    public static function createManual(int|string $account, string $date, string $label, int|float|string $amount,
                                  int|string|null $category = null, ?string $comment = null): array
    {
        $bank = Db::queryOne("SELECT account_number, bank_name FROM bank_account WHERE id = ?", "i", $account);
        if (!$bank) {
            throw new ApiError("Account not found", 404, 'account_not_found');
        }
        $fields = self::manualFields($date, $label, $amount);
        // Checked before the insert: a refused comment enters nothing
        $comment = self::commentToStore($comment);
        $chosen = $category !== null && $category !== '';
        $category = $chosen ? self::existingCategory($category) : (Category::find($fields['label'], null, $fields['amount']) ?? 0);
        $source = $chosen ? self::SOURCE_MANUAL : ($category !== 0 ? self::SOURCE_RULE : null);

        $id = self::insertRow(['split_of' => null, 'uuid' => self::manualUuid(), 'rdate' => $fields['date'],
            'type' => 8, 'category' => $category, 'category_source' => $source, 'card' => '', 'bank_name' => $bank['bank_name'],
            'account_number' => $bank['account_number'], 'pointed' => 1, 'user' => (int)Jwt::getUserIdFromToken(),
            'comment' => $comment] + $fields);
        if (substr($fields['date'], 0, 7) === date('Y-m')) {
            BudgetAlert::check();
            InsightAlert::check();
        }
        return Tag::withTags([self::manualRow($id)])[0];
    }

    /**
     * Change a transaction entered by hand (not a part of a split one): only the values given.
     * The amount of a split one is kept: its split is cancelled first.
     *
     * @param int|string            $id     The id of the transaction
     * @param string|null           $date   Its new date (YYYY-MM-DD)
     * @param string|null           $label  Its new label
     * @param int|float|string|null $amount Its new amount
     * @param string|null           $comment Its new comment (500 characters at most): "" removes it,
     *                                       null (not sent: mobile apps) keeps the current one
     * @return array<string, mixed> The transaction
     * @throws Error If it does not exist (404), was not entered by hand or a value is invalid (400),
     *               or a comment is sent before the migration 2026-11-19_transaction_comment (409)
     */
    #[ApiRoute('/transaction', method: 'PUT', changes: ['transactions'])]
    public static function updateManual(int|string $id, ?string $date = null, ?string $label = null,
                                  int|float|string|null $amount = null, ?string $comment = null): array
    {
        $transaction = self::manualRow($id);
        if (!self::enteredByHand($transaction)) {
            throw new ApiError("Only a transaction entered by hand can be changed", 400, 'transaction_not_manual');
        }
        $fields = self::manualFields($date ?? $transaction['date'], $label ?? stripslashes($transaction['label']),
            $amount ?? $transaction['amount']);
        if ($fields['amount'] !== sprintf('%.2f', (float)$transaction['amount'])
            && Db::queryOne("SELECT id FROM bank_transaction WHERE split_of = ? LIMIT 1", "i", $id)) {
            throw new ApiError("The amount of a split transaction cannot be changed", 400, 'transaction_split_amount');
        }
        $stored = self::commentToStore($comment);

        Db::execute("UPDATE bank_transaction SET date = ?, rdate = ?, label = ?, amount = ? WHERE id = ?", "ssssi",
            $fields['date'], $fields['date'], $fields['label'], $fields['amount'], $id);
        Db::execute("UPDATE bank_transaction SET date = ?, rdate = ?, label = ? WHERE split_of = ?", "sssi",
            $fields['date'], $fields['date'], $fields['label'], $id);
        if ($comment !== null) {
            self::saveComment($id, $stored);
        }
        return Tag::withTags([self::manualRow($id)])[0];
    }

    /**
     * A new UUID of a transaction entered by hand: MANUAL_UUID then random hexadecimal digits.
     *
     * @return string 32 characters
     */
    private static function manualUuid(): string
    {
        return self::MANUAL_UUID . substr(md5(random_bytes(16)), strlen(self::MANUAL_UUID));
    }

    /**
     * Whether a transaction was entered by hand (not a part of a split one): its UUID starts
     * with MANUAL_UUID, or it is on a manual account (entered before MANUAL_UUID).
     *
     * @param array<string, mixed> $transaction Its row
     * @return bool
     */
    public static function enteredByHand(array $transaction): bool
    {
        return $transaction['split_of'] === null && ($transaction['bank_name'] === Bank::MANUAL
            || str_starts_with((string)$transaction['uuid'], self::MANUAL_UUID));
    }

    /**
     * Check the values of a transaction entered by hand.
     *
     * @param string           $date   Its date (YYYY-MM-DD)
     * @param string           $label  Its label
     * @param int|float|string $amount Its amount
     * @return array{date: string, label: string, amount: string} The values to store (label cleaned)
     * @throws Error If one is invalid (400)
     */
    private static function manualFields(string $date, string $label, int|float|string $amount): array
    {
        $parsed = DateTime::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ApiError("Invalid date (YYYY-MM-DD)", 400, 'transaction_date_invalid');
        }
        $label = self::cleanLabel(mb_strtoupper(trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label) ?? '')));
        if ($label === '' || mb_strlen($label) > 300) {
            throw new ApiError("The label must contain 1 to 300 characters", 400, 'transaction_label_invalid');
        }
        $cents = is_numeric($amount) ? (int)round((float)$amount * 100) : 0;
        if ($cents === 0 || abs($cents) >= 100000000) {
            throw new ApiError("Invalid amount", 400, 'transaction_amount_invalid');
        }
        return ['date' => $date, 'label' => $label, 'amount' => sprintf('%.2f', $cents / 100)];
    }

    /**
     * A category sent by a user, if it exists.
     *
     * @param int|string $category The id of the category
     * @return int The category
     * @throws Error If it does not exist (404)
     */
    private static function existingCategory(int|string $category): int
    {
        if (!ctype_digit((string)$category) || !Db::queryOne("SELECT id FROM bank_transaction_category WHERE id = ?", "i", (int)$category)) {
            throw new ApiError("Category not found", 404, 'category_not_found');
        }
        return (int)$category;
    }

    /**
     * A transaction, by its id.
     *
     * @param int|string $id The id of the transaction
     * @return array<string, mixed> Its row
     * @throws Error If it does not exist (404)
     */
    private static function manualRow(int|string $id): array
    {
        $transaction = Db::queryOne("SELECT * FROM bank_transaction WHERE id = ?", "i", $id);
        if (!$transaction) {
            throw new ApiError("Transaction not found", 404, 'transaction_not_found');
        }
        return $transaction;
    }

    /**
     * Insert a transaction that does not come from a bank (a part of a split one, one entered by hand).
     *
     * @param array<string, mixed> $row split_of, uuid, date, rdate, type, label (cleaned), category,
     *                                  category_source, amount, card, bank_name, account_number, pointed, user,
     *                                  comment (checked by commentToStore(), null for none)
     * @return int The id of the transaction
     */
    private static function insertRow(array $row): int
    {
        // In the same INSERT: a transaction is never stored without its comment
        $comment = self::commentReady() ? ", comment = ?" : "";
        $sql = "INSERT INTO bank_transaction SET split_of = ?, uuid = ?, date = ?, rdate = ?, type = ?, label = ?,
                    category = ?" . self::sourceSql($row['category_source'] ?? null) . ", amount = ?, card = ?,
                    bank_name = ?, account_number = ?, pointed = ?, user = ?" . $comment;
        $params = [$row['split_of'], $row['uuid'], $row['date'], $row['rdate'], $row['type'], $row['label'],
            $row['category'], $row['amount'], $row['card'], $row['bank_name'], $row['account_number'], $row['pointed'], $row['user']];
        if ($comment !== '') {
            $params[] = $row['comment'] ?? null;
        }
        $stmt = Db::execute($sql, "isssisissssii" . ($comment !== '' ? 's' : ''), ...$params);
        return (int)$stmt->insert_id;
    }

    /**
     * Delete a transaction. Its UUID is remembered: the synchronization of its account
     * and the import of a file skip it, so that it does not come back. Its parts, if it was
     * split, are deleted with it; a part deleted gives its amount back to its transaction.
     *
     * @param int|string $id The id of the transaction
     * @return bool True once deleted
     * @throws Error If the transaction does not exist
     */
    #[ApiRoute('/transaction', method: 'DELETE', changes: ['transactions'])]
    public static function delete(int|string $id): bool
    {
        $transaction = Db::queryOne("SELECT uuid, label, amount, split_of FROM bank_transaction WHERE id = ?", "i", $id);
        if (!$transaction) {
            throw new ApiError("Transaction not found", 404, 'transaction_not_found');
        }

        $userId = Jwt::getUserIdFromToken();
        if ($transaction['split_of'] !== null) {
            // A part is not known by the bank: its amount goes back to its transaction
            Db::execute("UPDATE bank_transaction SET amount = amount + ? WHERE id = ?", "si", $transaction['amount'], $transaction['split_of']);
        } else {
            Db::execute("INSERT IGNORE INTO bank_transaction_deleted (uuid, user_id) VALUES (?, ?)", "si", $transaction['uuid'], $userId);
        }
        Db::execute("DELETE FROM bank_transaction WHERE id = ?", "i", $id);
        Logger::info("Transaction $id deleted by the user $userId");
        return true;
    }
}
