<?php

/**
 * SpendingAnalysis.php
 *
 * Analyses of the transactions of a period for the MCP server (#496): spending month by
 * month, recurring series with the history of their amounts, possible duplicates and
 * one-off transactions. Pure functions on rows already read from the database, so that
 * they are tested without one (tests/unit/SpendingAnalysisTest.php).
 *
 * The amounts follow GET /budget/trends: the off-budget categories (internal transfers,
 * savings) are neither expenses nor income.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Error;

final class SpendingAnalysis {

    /** Longest period analyzed, in months */
    public const MAX_MONTHS = 120;

    /** Most series, duplicates or transactions returned by a tool */
    public const MAX_RESULTS = 100;

    /** Months over which the recurring series are detected (the history covers the whole period) */
    public const RECURRING_MONTHS = 6;

    /** Type of the off-budget categories */
    private const OFF_BUDGET = 'HORS-BUDGET';

    /**
     * Period of months of a tool: both months included, the last 12 months by default.
     *
     * @param mixed  $from  First month (YYYY-MM), as sent by the client (null: 11 months before $to)
     * @param mixed  $to    Last month (YYYY-MM), as sent by the client (null: $today's month)
     * @param string $today Current month (YYYY-MM)
     * @return array{from: string, to: string, start: string, end: string} The months, the first day
     *               of the period and the first day after it (Y-m-d)
     * @throws Error If a month is invalid, the period reversed or longer than MAX_MONTHS (400)
     */
    public static function period(mixed $from, mixed $to, string $today): array
    {
        $to = self::month($to) ?? $today;
        $from = self::month($from) ?? self::shift($to, -11);
        if ($from > $to) {
            throw new Error("'from' must not be after 'to'", 400);
        }
        if (count(self::months($from, $to)) > self::MAX_MONTHS) {
            throw new Error("The period is limited to " . self::MAX_MONTHS . " months", 400);
        }
        return ['from' => $from, 'to' => $to, 'start' => "$from-01", 'end' => self::shift($to, 1) . '-01'];
    }

    /**
     * A month sent by the client.
     *
     * @param mixed $month The month (YYYY-MM), null or "" when not sent
     * @return string|null The month, null when not sent
     * @throws Error If it is not in the YYYY-MM format (400)
     */
    private static function month(mixed $month): ?string
    {
        if ($month === null || $month === '') {
            return null;
        }
        if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new Error("Months must be in the YYYY-MM format", 400);
        }
        return $month;
    }

    /**
     * Months of a period, both included.
     *
     * @param string $from First month (YYYY-MM)
     * @param string $to   Last month (YYYY-MM)
     * @return list<string> The months (YYYY-MM)
     */
    public static function months(string $from, string $to): array
    {
        $months = [];
        for ($month = $from; $month <= $to && count($months) <= self::MAX_MONTHS; $month = self::shift($month, 1)) {
            $months[] = $month;
        }
        return $months;
    }

    /**
     * A month moved by a number of months.
     *
     * Public: McpAnalysis starts the detection of the recurring series RECURRING_MONTHS before the end.
     *
     * @param string $month  The month (YYYY-MM)
     * @param int    $months Months to add (negative: before)
     * @return string The month (YYYY-MM)
     */
    public static function shift(string $month, int $months): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));
        $index = $year * 12 + $number - 1 + $months;
        return sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
    }

    /**
     * Ids of the categories to keep: the ones asked with their sub-categories, without the
     * excluded ones (and their sub-categories). Unknown ids are refused with the valid ones,
     * so that the model corrects itself.
     *
     * @param list<array<string, mixed>> $categories All the categories {id, parent_category}
     * @param list<int>|null             $only       Categories to keep (null: all)
     * @param list<int>                  $exclude    Categories to leave out
     * @return list<int>|null The ids kept (null: all the categories)
     * @throws Error If an id is unknown (400)
     */
    public static function categoryFilter(array $categories, ?array $only, array $exclude): ?array
    {
        $ids = array_map(fn($c) => (int)$c['id'], $categories);
        $unknown = array_diff(array_merge($only ?? [], $exclude), $ids);
        if ($unknown !== []) {
            sort($ids);
            throw new Error("Unknown category id(s): " . implode(', ', $unknown) . ". Valid ids: " . implode(', ', $ids)
                . " (see list_categories)", 400);
        }
        if ($only === null && $exclude === []) {
            return null;
        }
        $excluded = self::withChildren($categories, $exclude);
        return array_values(array_diff($only === null ? $ids : self::withChildren($categories, $only), $excluded));
    }

    /**
     * Categories with their sub-categories.
     *
     * @param list<array<string, mixed>> $categories All the categories {id, parent_category}
     * @param list<int>                  $ids        The categories
     * @return list<int> The ids, sub-categories included
     */
    private static function withChildren(array $categories, array $ids): array
    {
        $all = $ids;
        foreach ($categories as $category) {
            $parent = (int)$category['parent_category'];
            // A top-level category has the parent 0: it is not a sub-category of "uncategorized" (0)
            if ($parent !== 0 && $parent !== (int)$category['id'] && in_array($parent, $ids, true)) {
                $all[] = (int)$category['id'];
            }
        }
        return array_values(array_unique($all));
    }

    /**
     * Expenses, income and balance month by month, with the total, the monthly averages and
     * the month that spent the most. Off-budget categories are left out (as in the trends).
     *
     * @param list<array<string, mixed>> $rows   Transactions {date, amount, category, type (of the category)}
     * @param list<string>               $months The months of the period (YYYY-MM)
     * @param list<int>|null             $keep   Categories kept (null: all, see categoryFilter())
     * @return array<string, mixed> months [{month, expenses, income, balance}], total {expenses, income,
     *               balance}, average {expenses, income, balance} and highest {month, expenses} (null if
     *               nothing was spent)
     */
    public static function byMonth(array $rows, array $months, ?array $keep): array
    {
        $totals = array_fill_keys($months, ['expenses' => 0.0, 'income' => 0.0]);
        foreach ($rows as $row) {
            $month = substr((string)$row['date'], 0, 7);
            if (!isset($totals[$month]) || !self::counted($row, $keep)) {
                continue;
            }
            $amount = (float)$row['amount'];
            $totals[$month][$amount < 0 ? 'expenses' : 'income'] += abs($amount);
        }

        $list = [];
        foreach ($totals as $month => $total) {
            $list[] = ['month' => $month] + self::amounts($total['expenses'], $total['income']);
        }
        $count = max(1, count($list));
        $expenses = array_sum(array_column($list, 'expenses'));
        $income = array_sum(array_column($list, 'income'));
        return [
            'months' => $list,
            'total' => self::amounts($expenses, $income),
            'average' => self::amounts($expenses / $count, $income / $count),
            'highest' => self::highest($list),
        ];
    }

    /**
     * Whether a transaction counts in the expenses and income: not off-budget, and of a
     * category kept.
     *
     * @param array<string, mixed> $row  Transaction {category, type}
     * @param list<int>|null       $keep Categories kept (null: all)
     * @return bool
     */
    private static function counted(array $row, ?array $keep): bool
    {
        return ($row['type'] ?? '') !== self::OFF_BUDGET && ($keep === null || in_array((int)$row['category'], $keep, true));
    }

    /**
     * Expenses, income and balance, rounded to the cent.
     *
     * @param float $expenses Expenses (positive)
     * @param float $income   Income
     * @return array{expenses: float, income: float, balance: float}
     */
    private static function amounts(float $expenses, float $income): array
    {
        return ['expenses' => round($expenses, 2), 'income' => round($income, 2), 'balance' => round($income - $expenses, 2)];
    }

    /**
     * The month that spent the most (the first one on a tie).
     *
     * @param list<array<string, mixed>> $months The months {month, expenses}
     * @return array{month: string, expenses: float}|null Null if nothing was spent
     */
    private static function highest(array $months): ?array
    {
        $highest = null;
        foreach ($months as $month) {
            if ($month['expenses'] > 0 && ($highest === null || $month['expenses'] > $highest['expenses'])) {
                $highest = ['month' => $month['month'], 'expenses' => $month['expenses']];
            }
        }
        return $highest;
    }

    /**
     * Key of the series of a transaction: its direction and its label without numbers
     * (references, dates), as the detection of the recurring transactions groups them.
     *
     * @param string           $label  The label
     * @param float|int|string $amount The amount (its sign gives the direction)
     * @return string The key ("-label" or "+label"), "" for a label made of numbers only
     */
    public static function seriesKey(string $label, float|int|string $amount): string
    {
        $name = trim(preg_replace('/[^\p{L}]+/u', ' ', preg_replace('/\d+/', '', $label) ?? '') ?? '');
        return $name === '' ? '' : ((float)$amount < 0 ? '-' : '+') . $name;
    }

    /**
     * The recurring series found in a period, with the amount of each month and the
     * changes of amount (a price increase and its month).
     *
     * @param list<array<string, mixed>> $series Series of Transaction::findRecurring() on the period
     * @param list<array<string, mixed>> $rows   The transactions of the period {date, label, amount}, oldest first
     * @param array<int, string>         $names  Names of the categories by id
     * @return list<array<string, mixed>> label, category, day, months, average, amount (last one),
     *               history [{month, amount}] and changes [{month, from, to}]
     */
    public static function recurringHistory(array $series, array $rows, array $names): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[self::seriesKey((string)$row['label'], $row['amount'])][] = $row;
        }
        $result = [];
        foreach ($series as $found) {
            $history = self::monthlyAmounts($groups[self::seriesKey((string)$found['label'], $found['amount'])] ?? []);
            $result[] = [
                'label' => $found['label'],
                'category' => $names[(int)$found['category']] ?? null,
                'day' => $found['day'],
                'months' => $found['months'],
                'average' => $found['average'],
                'amount' => $found['amount'],
                'history' => $history,
                'changes' => self::changes($history),
            ];
        }
        return $result;
    }

    /**
     * Amount of a series in each month it was seen (the sum when twice in a month).
     *
     * @param list<array<string, mixed>> $rows The transactions of the series {date, amount}, oldest first
     * @return list<array{month: string, amount: float}>
     */
    private static function monthlyAmounts(array $rows): array
    {
        $byMonth = [];
        foreach ($rows as $row) {
            $month = substr((string)$row['date'], 0, 7);
            $byMonth[$month] = ($byMonth[$month] ?? 0) + (float)$row['amount'];
        }
        ksort($byMonth);
        $history = [];
        foreach ($byMonth as $month => $amount) {
            $history[] = ['month' => (string)$month, 'amount' => round($amount, 2)];
        }
        return $history;
    }

    /**
     * Changes of amount of a series from one month to the next (more than 1 %).
     *
     * @param list<array{month: string, amount: float}> $history The amount of each month
     * @return list<array{month: string, from: float, to: float}> The month of the new amount, the old and new amounts
     */
    private static function changes(array $history): array
    {
        $changes = [];
        for ($i = 1; $i < count($history); $i++) {
            $from = $history[$i - 1]['amount'];
            $to = $history[$i]['amount'];
            if (abs($to - $from) > 0.01 * abs($from)) {
                $changes[] = ['month' => $history[$i]['month'], 'from' => $from, 'to' => $to];
            }
        }
        return $changes;
    }

    /**
     * Possible duplicates: transactions of the same account, day, label and amount. They
     * may also be two real payments (two coffees the same day): the user decides.
     *
     * @param list<array<string, mixed>> $rows  Transactions {date, label, amount, category, account}
     * @param array<int, string>         $names Names of the categories by id
     * @return list<array<string, mixed>> date, label, amount, category, account and count (2 or more), most recent
     *               first, MAX_RESULTS at most
     */
    public static function duplicates(array $rows, array $names): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = implode("\x1F", [$row['date'], $row['label'], round((float)$row['amount'], 2), $row['account'] ?? '']);
            $groups[$key][] = $row;
        }
        $duplicates = [];
        foreach ($groups as $group) {
            if (count($group) > 1) {
                $row = $group[0];
                $duplicates[] = ['date' => $row['date'], 'label' => $row['label'], 'amount' => round((float)$row['amount'], 2),
                    'category' => $names[(int)$row['category']] ?? null, 'account' => $row['account'] ?? null, 'count' => count($group)];
            }
        }
        usort($duplicates, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp($a['label'], $b['label']));
        return array_slice($duplicates, 0, self::MAX_RESULTS);
    }

    /**
     * Name of an account shown to an agent: the name given to it, else its bank and the last
     * 4 characters of its number (never the whole number).
     *
     * @param string|null $bank   bank_name (woob backend, "manual"...)
     * @param string|null $number account_number
     * @param string|null $label  Name given to the account (bank_account.label)
     * @return string|null The name, null for a transaction without account (imported file)
     */
    public static function accountName(?string $bank, ?string $number, ?string $label): ?string
    {
        if ($label !== null && trim($label) !== '') {
            return trim($label);
        }
        if (($bank ?? '') === '' && ($number ?? '') === '') {
            return null;
        }
        $end = $number !== null && $number !== '' ? '···' . mb_substr($number, -4) : '';
        return trim(($bank ?? '') . ' ' . $end);
    }

    /**
     * One-off transactions: those whose label (without its numbers) appears only once in
     * the period, largest first: one-off purchases, yearly payments. Off-budget categories
     * are left out.
     *
     * @param list<array<string, mixed>> $rows  Transactions {date, label, amount, category, type}
     * @param array<int, string>         $names Names of the categories by id
     * @param string|null                $kind  "debit" (expenses), "credit" (income) or null (both)
     * @param int                        $limit Maximum number of transactions
     * @return list<array<string, mixed>> date, label, amount and category
     * @throws Error If the kind is unknown (400)
     */
    public static function oneOff(array $rows, array $names, ?string $kind, int $limit): array
    {
        if ($kind !== null && !in_array($kind, ['debit', 'credit'], true)) {
            throw new Error("kind must be debit or credit", 400);
        }
        $counted = array_filter($rows, fn($row) => self::counted($row, null) && self::ofKind((float)$row['amount'], $kind));
        $seen = array_count_values(array_map(fn($row) => self::seriesKey((string)$row['label'], $row['amount']), $counted));

        $list = [];
        foreach ($counted as $row) {
            $key = self::seriesKey((string)$row['label'], $row['amount']);
            if ($key !== '' && $seen[$key] === 1) {
                $list[] = ['date' => $row['date'], 'label' => $row['label'], 'amount' => round((float)$row['amount'], 2),
                    'category' => $names[(int)$row['category']] ?? null];
            }
        }
        usort($list, fn($a, $b) => abs($b['amount']) <=> abs($a['amount']) ?: strcmp($a['date'], $b['date']));
        return array_slice($list, 0, max(1, $limit));
    }

    /**
     * Whether an amount is of a kind.
     *
     * @param float       $amount The amount
     * @param string|null $kind   "debit", "credit" or null (both)
     * @return bool
     */
    private static function ofKind(float $amount, ?string $kind): bool
    {
        return $kind === null || ($kind === 'debit' ? $amount < 0 : $amount > 0);
    }
}
