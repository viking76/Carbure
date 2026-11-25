<?php

/**
 * McpAnalysis.php
 *
 * Analysis tools of the MCP server (#496): spending month by month over an explicit
 * period, recurring series with their amount history, possible duplicates and one-off
 * transactions. Read-only; the computations are in Carbure\Lib\SpendingAnalysis, this
 * class reads the transactions of the period and the arguments of the tools.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\Db;
use Carbure\Lib\SpendingAnalysis;
use Error;

final class McpAnalysis {

    /** Names of the tools */
    public const TOOLS = ['spending_by_month', 'recurring_transactions', 'duplicate_transactions', 'one_off_transactions'];

    /** Most one-off transactions returned */
    private const MAX_ONE_OFF = 100;

    /**
     * Definition of the tools (JSON Schema of their arguments).
     *
     * @param array<string, bool> $annotations Annotations of a read-only tool
     * @return list<array<string, mixed>> The tools
     */
    public static function tools(array $annotations): array
    {
        $period = [
            'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$', 'description' => 'First month (YYYY-MM), 11 months before "to" by default'],
            'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$', 'description' => 'Last month (YYYY-MM), current month by default'],
        ];
        $ids = fn(string $description) => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => $description];

        return [
            self::tool('spending_by_month', 'Spending month by month',
                'Expenses, income and balance of each month of an explicit period (both months included), with the total, the monthly average '
                . 'and the month that spent the most. Off-budget categories (internal transfers, savings) are left out, as in get_trends.',
                $period + [
                    'category_ids' => $ids('Only these categories and their sub-categories (ids of list_categories)'),
                    'exclude_category_ids' => $ids('Leave out these categories and their sub-categories, e.g. the rent'),
                ], $annotations),
            self::tool('recurring_transactions', 'Recurring transactions',
                'Monthly recurring series still running at the end of the period, detected on its last 6 months (subscriptions, bills, '
                . 'salary): usual day, average and last amount, the amount of each month of the period and its changes (a price increase and its month).',
                $period, $annotations),
            self::tool('duplicate_transactions', 'Possible duplicates',
                'Transactions of the same account, day, label and amount, most recent first (100 at most): a payment charged twice, '
                . 'or two real identical payments (the user decides). The account is given by its name.',
                $period, $annotations),
            self::tool('one_off_transactions', 'One-off transactions',
                'Transactions whose label (without its numbers) appears only once in the period, largest first: one-off purchases, '
                . 'yearly payments. Off-budget categories are left out.',
                $period + [
                    'kind' => ['type' => 'string', 'enum' => ['debit', 'credit'], 'description' => 'debit: expenses only, credit: incomes only'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_ONE_OFF, 'description' => 'Maximum number of transactions (20 by default)'],
                ], $annotations),
        ];
    }

    /**
     * Definition of a tool.
     *
     * @param string               $name        Its name
     * @param string               $title       Its title
     * @param string               $description What it returns
     * @param array<string, mixed> $properties  Its arguments
     * @param array<string, bool>  $annotations Its annotations
     * @return array<string, mixed> The tool
     */
    private static function tool(string $name, string $title, string $description, array $properties, array $annotations): array
    {
        return ['name' => $name, 'title' => $title, 'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => $properties], 'annotations' => $annotations];
    }

    /**
     * Run a tool.
     *
     * @param string       $name      One of TOOLS
     * @param array<mixed> $arguments Its arguments
     * @return array<string, mixed> The period and the result of the tool
     * @throws Error If an argument is invalid (400)
     */
    public static function run(string $name, array $arguments): array
    {
        $period = SpendingAnalysis::period($arguments['from'] ?? null, $arguments['to'] ?? null, date('Y-m'));
        $head = ['from' => $period['from'], 'to' => $period['to']];

        return $head + match ($name) {
            'spending_by_month' => self::spendingByMonth($period, $arguments),
            'recurring_transactions' => ['series' => self::recurring($period)],
            'duplicate_transactions' => ['duplicates' => SpendingAnalysis::duplicates(self::rows($period), self::names())],
            default => ['transactions' => self::oneOff($period, $arguments)],
        };
    }

    /**
     * The tool spending_by_month.
     *
     * @param array{from: string, to: string, start: string, end: string} $period    The period
     * @param array<mixed>                                                $arguments category_ids, exclude_category_ids
     * @return array<string, mixed> See SpendingAnalysis::byMonth()
     * @throws Error If a category id is unknown (400)
     */
    private static function spendingByMonth(array $period, array $arguments): array
    {
        $keep = SpendingAnalysis::categoryFilter(Category::get(), self::ids($arguments['category_ids'] ?? null),
            self::ids($arguments['exclude_category_ids'] ?? null) ?? []);
        // Every row, the parts of a split transaction in their own category (as the trends)
        $rows = Db::select("SELECT t.date, t.amount, t.category, c.type FROM bank_transaction t
            LEFT JOIN bank_transaction_category c ON c.id = t.category
            WHERE t.date >= ? AND t.date < ?", "ss", $period['start'], $period['end'])->fetch_all(MYSQLI_ASSOC);
        return SpendingAnalysis::byMonth($rows, SpendingAnalysis::months($period['from'], $period['to']), $keep);
    }

    /**
     * The tool recurring_transactions: the detection of the portal (Transaction::findRecurring())
     * on the period, the series still running in its last month or the month before.
     *
     * @param array{from: string, to: string, start: string, end: string} $period The period
     * @return list<array<string, mixed>> See SpendingAnalysis::recurringHistory()
     */
    private static function recurring(array $period): array
    {
        $rows = Db::select("SELECT t.id, t.date, t.type, t.label, t.category, " . Transaction::BANK_AMOUNT . " AS amount
            FROM bank_transaction t WHERE t.split_of IS NULL AND t.date >= ? AND t.date < ? ORDER BY t.date, t.id",
            "ss", $period['start'], $period['end'])->fetch_all(MYSQLI_ASSOC);
        // Detected on the last months (a long period would hide a card subscription whose price rose),
        // the history of their amounts over the whole period
        $since = max($period['from'], SpendingAnalysis::shift($period['to'], 1 - SpendingAnalysis::RECURRING_MONTHS)) . '-01';
        $recent = array_values(array_filter($rows, fn($row) => $row['date'] >= $since));
        $series = array_slice(Transaction::findRecurring($recent, $period['to'] . '-01'), 0, SpendingAnalysis::MAX_RESULTS);
        return SpendingAnalysis::recurringHistory($series, $rows, self::names());
    }

    /**
     * The tool one_off_transactions.
     *
     * @param array{from: string, to: string, start: string, end: string} $period    The period
     * @param array<mixed>                                                $arguments kind, limit
     * @return list<array<string, mixed>> See SpendingAnalysis::oneOff()
     * @throws Error If the kind is unknown (400)
     */
    private static function oneOff(array $period, array $arguments): array
    {
        $kind = isset($arguments['kind']) && is_string($arguments['kind']) && $arguments['kind'] !== '' ? $arguments['kind'] : null;
        $limit = isset($arguments['limit']) && is_numeric($arguments['limit']) ? (int)$arguments['limit'] : 20;
        return SpendingAnalysis::oneOff(self::rows($period), self::names(), $kind, min(self::MAX_ONE_OFF, $limit));
    }

    /**
     * Transactions of a period as the bank sent them (a split one with the amount of the
     * bank, its parts left out), oldest first.
     *
     * @param array{from: string, to: string, start: string, end: string} $period The period
     * @return list<array<string, mixed>> date, label, amount, category, type (of the category), account (its name)
     */
    private static function rows(array $period): array
    {
        // The account by its name, never its number (bank_account has a row per user of the account)
        $rows = Db::select("SELECT t.date, t.label, " . Transaction::BANK_AMOUNT . " AS amount, t.category, c.type, t.bank_name, t.account_number,
                (SELECT MAX(a.label) FROM bank_account a WHERE a.bank_name = t.bank_name AND a.account_number <=> t.account_number) AS account_label
            FROM bank_transaction t LEFT JOIN bank_transaction_category c ON c.id = t.category
            WHERE t.split_of IS NULL AND t.date >= ? AND t.date < ? ORDER BY t.date, t.id",
            "ss", $period['start'], $period['end'])->fetch_all(MYSQLI_ASSOC);
        return array_map(fn($row) => [
            'date' => $row['date'], 'label' => stripslashes((string)$row['label']), 'amount' => $row['amount'],
            'category' => $row['category'], 'type' => $row['type'],
            'account' => SpendingAnalysis::accountName($row['bank_name'], $row['account_number'], $row['account_label']),
        ], $rows);
    }

    /**
     * Names of the categories by id, "Parent › Name" for a sub-category.
     *
     * @return array<int, string>
     */
    private static function names(): array
    {
        $categories = Category::get();
        $names = array_column($categories, 'name', 'id');
        $result = [];
        foreach ($categories as $c) {
            $parent = (int)$c['parent_category'];
            $result[(int)$c['id']] = ($parent !== 0 && $parent !== (int)$c['id'] && isset($names[$parent]) ? $names[$parent] . ' › ' : '') . $c['name'];
        }
        return $result;
    }

    /**
     * Category ids sent by the client.
     *
     * @param mixed $value The argument
     * @return list<int>|null The ids, null when not sent
     * @throws Error If it is not a list of integers (400)
     */
    private static function ids(mixed $value): ?array
    {
        if ($value === null || $value === []) {
            return null;
        }
        if (!is_array($value) || array_filter($value, fn($id) => !is_numeric($id)) !== []) {
            throw new Error("Category ids must be a list of integers", 400);
        }
        return array_values(array_map('intval', $value));
    }
}
