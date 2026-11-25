<?php

/**
 * Budget.php
 *
 * Budget class to calculate the budget consumption by category.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Month;
use Error;

final class Budget {

    /**
     * Longest period of the trends, and furthest end of a period, in months (20 years)
     */
    public const MAX_TREND_MONTHS = 240;

    /**
     * Get the current budget consumption by category.
     *
     * The budget of a category with sub-categories is its own amount when one is set
     * (an override), otherwise the sum of the budgets of its sub-categories.
     *
     * @param int|string|null $month    The month to get the budget
     * @param int|string|null $year     The year to get the budget
     * @param int|string|null $category The parent category id
     * @param bool|int|string|null $own With a parent category: also an item for the transactions
     *               put on the parent itself, which no sub-category shows ("true"/"1" from a query string)
     * @return list<array<string, mixed>> The list of budget items; besides budget, note (of the
     *               budget of the month, null if none), consummed and progress: budget_mode ('own' or 'children': sum of the sub-categories),
     *               children (number of sub-categories) and children_budget (their sum); with $own, the item
     *               of the transactions of the parent itself has own = true (id of the parent, no budget)
     */
    #[ApiRoute('/budget', method: 'GET')]
    public static function get(int|string|null $month = null, int|string|null $year = null, int|string|null $category = 0,
        bool|int|string|null $own = false): array
    {
        [$from, $to] = Month::range($month, $year);

        // Total of the month for each category, in one pass
        $sql = "SELECT category, SUM(amount) AS total FROM bank_transaction
                WHERE date >= ? AND date < ? GROUP BY category";
        $totals = array_column(Db::select($sql, "ss", $from, $to)->fetch_all(MYSQLI_ASSOC), 'total', 'category');

        // Sub-categories of each category
        $children = self::children();

        // Budgets of the month by category
        $budgets = self::budgets($from, $to);

        // Categories of the requested level with their budget of the month
        $sql = "SELECT c.id, c.name, c.type, c.icon, c.color, IFNULL(b.amount, 0) AS budget, " . self::noteColumn('b') . "
                FROM bank_transaction_category c
                LEFT JOIN budget b ON b.category = c.id AND b.date >= ? AND b.date < ?
                WHERE c.parent_category = ?";
        $categories = Db::select($sql, "ssi", $from, $to, $category)->fetch_all(MYSQLI_ASSOC);

        // Consumption of a category = its transactions + those of its sub-categories
        $budget = [];
        foreach ($categories as $row) {
            $total = null;
            foreach (array_merge([$row['id']], $children[$row['id']] ?? []) as $id) {
                if (isset($totals[$id])) {
                    $total = ($total ?? 0) + (float)$totals[$id];
                }
            }

            // Own budget, or the sum of the budgets of the sub-categories
            $kids = $children[$row['id']] ?? [];
            $childrenBudget = round(array_sum(array_map(fn($id) => $budgets[$id] ?? 0, $kids)), 2);
            $mode = self::budgetMode((float)$row['budget'], count($kids));
            if ($mode === 'children') {
                $row['budget'] = number_format($childrenBudget, 2, '.', '');
            }
            $amount = (float)$row['budget'];
            $row['budget_mode'] = $mode;
            $row['children'] = count($kids);
            $row['children_budget'] = $childrenBudget;
            $consummed = self::consummed($row['type'], $total);
            $row['consummed'] = $consummed;
            $row['progress'] = $amount == 0 ? 0 : (int)abs(round($consummed / $amount * 100));
            $budget[] = $row;
        }

        // Transactions put on the parent itself (rules may target it): shown apart, or they would be in no card
        if ((int)$category !== 0 && filter_var($own, FILTER_VALIDATE_BOOLEAN) && isset($totals[$category])) {
            $budget[] = self::ownItem((int)$category, (float)$totals[$category]);
        }

        // Most consumed first
        usort($budget, fn($a, $b) => $b['consummed'] <=> $a['consummed']);

        return $budget;
    }

    /**
     * Budget item of the transactions put on a parent category itself rather than on
     * one of its sub-categories: no budget of its own, only what they consume.
     *
     * @param int   $category The parent category id
     * @param float $total    Sum of the transactions of the month put on the parent
     * @return array<string, mixed> The item, with the columns of the parent and own = true
     */
    private static function ownItem(int $category, float $total): array
    {
        $row = Db::queryOne("SELECT id, name, type, icon, color FROM bank_transaction_category WHERE id = ?", "i", $category)
            ?? ['id' => $category, 'name' => '', 'type' => 'DEBIT', 'icon' => '', 'color' => ''];
        return $row + [
            'budget' => '0.00',
            'note' => null,
            'budget_mode' => 'own',
            'children' => 0,
            'children_budget' => 0,
            'consummed' => self::consummed((string)$row['type'], $total),
            'progress' => 0,
            'own' => true,
        ];
    }

    /**
     * Sub-categories of each category.
     *
     * @return array<int|string, list<int|string>> [parent id => [child ids]]
     */
    private static function children(): array
    {
        $children = [];
        $sql = "SELECT id, parent_category FROM bank_transaction_category WHERE parent_category <> 0 AND id <> parent_category";
        foreach (Db::select($sql, "")->fetch_all(MYSQLI_ASSOC) as $row) {
            $children[$row['parent_category']][] = $row['id'];
        }
        return $children;
    }

    /**
     * Budget amounts of a month by category.
     *
     * @param string $from First day of the month
     * @param string $to   First day of the next month
     * @return array<int|string, float> [category id => amount]
     */
    private static function budgets(string $from, string $to): array
    {
        $sql = "SELECT category, amount FROM budget WHERE date >= ? AND date < ?";
        return array_map('floatval', array_column(Db::select($sql, "ss", $from, $to)->fetch_all(MYSQLI_ASSOC), 'amount', 'category'));
    }

    /**
     * Consumption of a category in the direction of its type: the net spending of an
     * expense category (DEBIT), the net income of an income category (CREDIT), never
     * negative (a refund larger than the spending of the month leaves 0 spent, not an
     * overspending); the absolute value for an off-budget category.
     *
     * @param string     $type  Type of the category: DEBIT, CREDIT or HORS-BUDGET
     * @param float|null $total Sum of the transactions of the month (null: none)
     * @return float The consumption, rounded to the cent
     */
    private static function consummed(string $type, ?float $total): float
    {
        if ($total === null) {
            return 0;
        }
        return round(match ($type) {
            'DEBIT' => max(0, -$total),
            'CREDIT' => max(0, $total),
            default => abs($total),
        }, 2);
    }

    /**
     * How the budget of a category is computed: its own amount, or the sum of the
     * budgets of its sub-categories when it has some and no amount of its own.
     *
     * @param float $amount   Own budget amount of the category (0 when not set)
     * @param int   $children Number of sub-categories
     * @return string 'own' or 'children'
     */
    public static function budgetMode(float $amount, int $children): string
    {
        return $children > 0 && $amount == 0 ? 'children' : 'own';
    }

    /** Longest note of a budget, in characters */
    public const NOTE_LENGTH = 200;

    /** Cache of noteReady() */
    private static ?bool $noteReady = null;

    /**
     * Whether the budgets have their note (migration 2026-11-06_budget_note). Read once
     * per request.
     *
     * @return bool
     */
    public static function noteReady(): bool
    {
        return self::$noteReady ??= Db::queryOne("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget' AND COLUMN_NAME = 'note'", "") !== null;
    }

    /**
     * SQL of the note of a budget in a SELECT: NULL before the migration 2026-11-06_budget_note.
     *
     * @param string $alias Alias of the table budget
     * @return string "<alias>.note" or "NULL AS note"
     */
    private static function noteColumn(string $alias): string
    {
        return self::noteReady() ? "$alias.note" : "NULL AS note";
    }

    /**
     * Set the budget for a category. For a category with sub-categories, the amount
     * overrides the sum of their budgets; 0 goes back to that sum.
     *
     * @param int              $category The category to set the budget
     * @param int|float|string $amount   The amount of the budget (a number or a numeric string)
     * @param int|string|null  $month    The month of the budget
     * @param int|string|null  $year     The year of the budget
     * @param string|null      $note     Reminder of what the budget covers (200 characters at
     *                                   most); "" removes it, null (not sent) keeps the current one
     * @return bool True on success
     * @throws Error If the note is too long (400), or sent before the migration 2026-11-06_budget_note (409)
     */
    #[ApiRoute('/budget', method: 'POST', changes: ['budgets'])]
    public static function create(int $category, int|float|string $amount, int|string|null $month = null, int|string|null $year = null,
        ?string $note = null): bool
    {
        // Insert or update the budget for the given category and month
        $date = Month::first($month, $year);
        if ($note !== null && !self::noteReady()) {
            if (trim($note) !== '') {
                throw new ApiError("The note of the budgets needs the pending database migrations", 409, 'budget_note_migration');
            }
            $note = null;
        }
        if ($note === null) {
            Db::execute("INSERT INTO budget (date, amount, category) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE amount=?", "sdid", $date, $amount, $category, $amount);
            return true;
        }

        $note = self::note($note);
        Db::execute("INSERT INTO budget (date, amount, category, note) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE amount=?, note=?", "sdisds", $date, $amount, $category, $note, $amount, $note);

        return true;
    }

    /**
     * A note of a budget as stored: trimmed, null when empty.
     *
     * @param string $note The note sent
     * @return string|null The note, null for none
     * @throws Error If it is longer than NOTE_LENGTH characters (400)
     */
    private static function note(string $note): ?string
    {
        $note = trim($note);
        if (mb_strlen($note) > self::NOTE_LENGTH) {
            throw new ApiError("The note of a budget has " . self::NOTE_LENGTH . " characters at most", 400, 'budget_note_length',
                ['max' => self::NOTE_LENGTH]);
        }
        return $note === '' ? null : $note;
    }

    /**
     * Copy the budgets of the previous month to the given month.
     *
     * Rows at 0 are copied as is (they mean "sum of the sub-categories"), with their note.
     * Without overwrite, only the categories without a budget in the target
     * month are copied; with overwrite, every budget of the source month
     * replaces the one of the target month.
     *
     * @param int|string|null      $month     The target month (current month by default)
     * @param int|string|null      $year      The target year (current year by default)
     * @param bool|int|string|null $overwrite True to replace the budgets already set in the
     *                                        target month ("true"/"false" from a query string)
     * @return array{month: string, source: string, copied: int, overwrite: bool} month,
     *               source (previous month, Y-m), copied (budgets written) and overwrite
     */
    #[ApiRoute('/budget/copy', method: 'POST', changes: ['budgets'])]
    public static function copy(int|string|null $month = null, int|string|null $year = null, bool|int|string|null $overwrite = false): array
    {
        [$targetMonth, $targetYear] = Month::resolve($month, $year);
        $target = sprintf('%04d-%02d-01', $targetYear, $targetMonth);
        $source = date('Y-m-d', Month::timestamp("$target -1 month"));
        [$from, $to] = Month::range(date('n', Month::timestamp($source)), date('Y', Month::timestamp($source)));

        $rows = Db::select("SELECT category, amount, " . self::noteColumn('budget') . " FROM budget WHERE date >= ? AND date < ?",
            "ss", $from, $to)->fetch_all(MYSQLI_ASSOC);

        $key = sprintf('%04d-%02d', $targetYear, $targetMonth);
        $sourceKey = date('Y-m', Month::timestamp($source));
        $overwrite = filter_var($overwrite, FILTER_VALIDATE_BOOLEAN);
        if (empty($rows)) {
            return ['month' => $key, 'source' => $sourceKey, 'copied' => 0, 'overwrite' => $overwrite];
        }

        $copied = 0;
        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $category = (int)$row['category'];
            $note = $row['note'];
            if (!self::noteReady()) {
                // Before the migration 2026-11-06_budget_note: the amounts only
                if ($overwrite) {
                    Db::execute("INSERT INTO budget (date, amount, category) VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE amount=?", "sdid", $target, $amount, $category, $amount);
                    $copied++;
                } else {
                    $copied += Db::execute("INSERT IGNORE INTO budget (date, amount, category) VALUES (?, ?, ?)",
                        "sdi", $target, $amount, $category)->affected_rows > 0 ? 1 : 0;
                }
                continue;
            }
            if ($overwrite) {
                Db::execute("INSERT INTO budget (date, amount, category, note) VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE amount=?, note=?", "sdisds", $target, $amount, $category, $note, $amount, $note);
                $copied++;
            } else {
                $stmt = Db::execute("INSERT IGNORE INTO budget (date, amount, category, note) VALUES (?, ?, ?, ?)",
                    "sdis", $target, $amount, $category, $note);
                $copied += $stmt->affected_rows > 0 ? 1 : 0;
            }
        }

        return ['month' => $key, 'source' => $sourceKey, 'copied' => $copied, 'overwrite' => $overwrite];
    }

    /**
     * Tell whether the budgets of the previous month can be copied to the given month:
     * the month has no budget defined yet and the previous month has some. A budget at 0
     * ("sum of the sub-categories") does not count as defined. Read only.
     *
     * @param int|string|null $month The target month (current month by default)
     * @param int|string|null $year  The target year (current year by default)
     * @return array{month: string, source: string, defined: bool, source_defined: bool, copyable: bool}
     *               month and source (previous month, Y-m), defined (the month has a budget),
     *               source_defined (the previous month has one) and copyable (the copy is offered)
     */
    #[ApiRoute('/budget/copy', method: 'GET')]
    public static function copyStatus(int|string|null $month = null, int|string|null $year = null): array
    {
        [$targetMonth, $targetYear] = Month::resolve($month, $year);
        $target = sprintf('%04d-%02d-01', $targetYear, $targetMonth);
        $source = date('Y-m-01', Month::timestamp("$target -1 month"));

        $defined = self::hasBudget($target);
        $sourceDefined = self::hasBudget($source);

        return [
            'month' => substr($target, 0, 7),
            'source' => substr($source, 0, 7),
            'defined' => $defined,
            'source_defined' => $sourceDefined,
            'copyable' => !$defined && $sourceDefined,
        ];
    }

    /**
     * Tell whether a month has at least one budget defined (an amount other than 0).
     *
     * @param string $first First day of the month (Y-m-d)
     * @return bool True if a budget of the month has an amount
     */
    private static function hasBudget(string $first): bool
    {
        $to = date('Y-m-d', Month::timestamp("$first +1 month"));
        $sql = "SELECT 1 FROM budget WHERE date >= ? AND date < ? AND amount <> 0 LIMIT 1";
        return Db::select($sql, "ss", $first, $to)->num_rows > 0;
    }


    /**
     * Get the monthly trends of the household over the last months.
     *
     * For each month (oldest first):
     * - debit:     expenses, off-budget categories excluded (positive amount)
     * - credit:    incomes, off-budget categories excluded
     * - offBudget: net amount of the off-budget categories (e.g. transfers to savings)
     * - planned:   sum of the budgets of the top-level categories (own amount, or
     *              the sum of their sub-categories)
     * - savings:   money put aside: net amount moved to the savings category
     *              ("Epargne" by default, setting savings_category) and its
     *              sub-categories, positive when saved
     *
     * @param int|string|null $months The number of months (1 to 240)
     * @param int|string|null $offset Number of months between the current month and the last month
     *                                of the period (0 to 240; 0 = up to the current month, used for comparisons)
     * @return list<array<string, mixed>> The list of months
     */
    #[ApiRoute('/budget/trends', method: 'GET')]
    public static function getTrends(int|string|null $months = 24, int|string|null $offset = 0): array
    {
        [$list, $from, $to] = self::period($months, $offset);

        // Empty months are kept with zero values
        $trends = [];
        foreach ($list as $month) {
            $trends[$month] = ['month' => $month, 'debit' => 0, 'credit' => 0, 'offBudget' => 0, 'planned' => 0, 'savings' => 0];
        }

        $trends = self::addTrendTotals($trends, $from, $to);
        foreach (self::plannedByMonth(array_keys($trends), $from, $to) as $month => $planned) {
            $trends[$month]['planned'] = $planned;
        }

        return array_values($trends);
    }

    /**
     * Months of a trends period, oldest first: the number of months is kept between 1
     * and Budget::MAX_TREND_MONTHS, the offset between 0 and Budget::MAX_TREND_MONTHS.
     *
     * @param int|string|null $months The number of months
     * @param int|string|null $offset Number of months between the current month and the last month of the period
     * @return array{0: list<string>, 1: string, 2: string} [months (Y-m), first day of the period,
     *         first day after the period (Y-m-d)]
     */
    public static function period(int|string|null $months, int|string|null $offset): array
    {
        $months = max(1, min(self::MAX_TREND_MONTHS, (int)$months));
        $offset = max(0, min(self::MAX_TREND_MONTHS, (int)$offset));
        $last = date('Y-m-01', Month::timestamp(date('Y-m-01') . " -$offset months"));
        $from = date('Y-m-01', Month::timestamp("$last -" . ($months - 1) . ' months'));
        $to = date('Y-m-01', Month::timestamp("$last +1 month"));

        $list = [];
        for ($i = 0; $i < $months; $i++) {
            $list[] = date('Y-m', Month::timestamp("$from +$i months"));
        }
        return [$list, $from, $to];
    }

    /**
     * Add the debit, credit, off-budget and savings totals of the transactions to the
     * months of the trends.
     *
     * @param array<string, array<string, mixed>> $trends The months (Y-m => values)
     * @param string                              $from   First day of the period (Y-m-d)
     * @param string                              $to     First day after the period (Y-m-d)
     * @return array<string, array<string, mixed>> The months with their totals
     */
    private static function addTrendTotals(array $trends, string $from, string $to): array
    {
        $sql = "SELECT DATE_FORMAT(t.date, '%Y-%m') AS month,
                SUM(CASE WHEN IFNULL(c.type, '') <> 'HORS-BUDGET' AND t.amount < 0 THEN -t.amount ELSE 0 END) AS debit,
                SUM(CASE WHEN IFNULL(c.type, '') <> 'HORS-BUDGET' AND t.amount > 0 THEN t.amount ELSE 0 END) AS credit,
                SUM(CASE WHEN c.type = 'HORS-BUDGET' THEN t.amount ELSE 0 END) AS offBudget,
                -SUM(CASE WHEN FIND_IN_SET(t.category, ?) THEN t.amount ELSE 0 END) AS savings
            FROM bank_transaction t
            LEFT JOIN bank_transaction_category c ON c.id = t.category
            WHERE t.date >= ? AND t.date < ?
            GROUP BY month";
        $savings = implode(',', self::savingsCategories());
        foreach (Db::select($sql, "sss", $savings, $from, $to)->fetch_all(MYSQLI_ASSOC) as $row) {
            foreach (['debit', 'credit', 'offBudget', 'savings'] as $key) {
                $trends[$row['month']][$key] = round((float)$row[$key], 2);
            }
        }
        return $trends;
    }

    /**
     * Planned amount of each month: sum of the budgets of the top-level categories,
     * their own amount, or the sum of their sub-categories.
     *
     * @param list<string> $months The months (Y-m)
     * @param string       $from   First day of the period (Y-m-d)
     * @param string       $to     First day after the period (Y-m-d)
     * @return array<string, float> The planned amount of each month (Y-m => amount)
     */
    private static function plannedByMonth(array $months, string $from, string $to): array
    {
        // Parent of each category: 0 for a top-level one
        $top = [];
        foreach (Db::select("SELECT id, parent_category FROM bank_transaction_category WHERE id <> 0", "")->fetch_all(MYSQLI_ASSOC) as $c) {
            $parent = (int)$c['parent_category'];
            $top[(int)$c['id']] = ($parent === 0 || $parent === (int)$c['id']) ? 0 : $parent;
        }
        [$own, $children] = self::budgetsByLevel($top, $from, $to);
        $hasChildren = array_count_values(array_filter($top));

        $planned = [];
        foreach ($months as $month) {
            $total = 0.0;
            foreach (array_keys(array_filter($top, fn(int $parent): bool => $parent === 0)) as $id) {
                $amount = $own[$month][$id] ?? 0.0;
                $total += self::budgetMode($amount, $hasChildren[$id] ?? 0) === 'children' ? ($children[$month][$id] ?? 0) : $amount;
            }
            $planned[$month] = round($total, 2);
        }
        return $planned;
    }

    /**
     * Budgets of the period by month: the own budget of the top-level categories, and
     * the sum of the budgets of the sub-categories of each top-level category.
     *
     * @param array<int, int> $top  Parent of each category (0 for a top-level one)
     * @param string          $from First day of the period (Y-m-d)
     * @param string          $to   First day after the period (Y-m-d)
     * @return array{0: array<string, array<int, float>>, 1: array<string, array<int, float>>}
     *         [own budgets, sums of the sub-categories] (Y-m => category => amount)
     */
    private static function budgetsByLevel(array $top, string $from, string $to): array
    {
        $own = [];
        $children = [];
        $sql = "SELECT DATE_FORMAT(date, '%Y-%m') AS month, category, amount FROM budget WHERE date >= ? AND date < ?";
        foreach (Db::select($sql, "ss", $from, $to)->fetch_all(MYSQLI_ASSOC) as $row) {
            $id = (int)$row['category'];
            if (!isset($top[$id])) {
                continue;
            }
            if ($top[$id] === 0) {
                $own[$row['month']][$id] = (float)$row['amount'];
            } else {
                $children[$row['month']][$top[$id]] = ($children[$row['month']][$top[$id]] ?? 0) + (float)$row['amount'];
            }
        }
        return [$own, $children];
    }

    /**
     * Money flow of a month, for the flow diagram of the Budget tab: where the
     * income comes from and where it goes. Amounts are grouped by top-level
     * category; the off-budget categories (internal transfers) are left out,
     * except the savings category.
     *
     * @param int|string|null $month The month (current month by default)
     * @param int|string|null $year  The year (current year by default)
     * @return array<string, mixed> month, income [{id, name, color, icon, amount}], expenses [...],
     *               savings (net amount put aside), totalIncome, totalExpenses, balance
     */
    #[ApiRoute('/budget/flow', method: 'GET')]
    public static function flow(int|string|null $month = null, int|string|null $year = null): array
    {
        [$month, $year] = Month::resolve($month, $year);
        [$categories, $rows] = self::flowRows($month, $year);

        $income = [];
        $expenses = [];
        $saved = 0.0;
        foreach ($rows as $row) {
            $amount = (float)$row['amount'];
            $top = (int)$row['top'];
            if ($row['kind'] === 'savings') {
                $saved -= $amount;
            } elseif ($row['kind'] === 'income') {
                $income[$top] = ($income[$top] ?? 0) + $amount;
            } else {
                $expenses[$top] = ($expenses[$top] ?? 0) - $amount;
            }
        }

        $nodes = function (array $amounts) use ($categories): array {
            $list = [];
            foreach ($amounts as $id => $amount) {
                if (round($amount, 2) <= 0) {
                    continue;
                }
                $c = $categories[$id] ?? ['name' => null, 'color' => '', 'icon' => ''];
                $list[] = ['id' => $id, 'name' => $c['name'], 'color' => $c['color'], 'icon' => $c['icon'], 'amount' => round($amount, 2)];
            }
            usort($list, fn($a, $b) => $b['amount'] <=> $a['amount']);
            return $list;
        };

        $income = $nodes($income);
        $expenses = $nodes($expenses);
        $totalIncome = round(array_sum(array_column($income, 'amount')), 2);
        $totalExpenses = round(array_sum(array_column($expenses, 'amount')), 2);
        $saved = round($saved, 2);

        return [
            'month' => sprintf('%04d-%02d', $year, $month),
            'income' => $income,
            'expenses' => $expenses,
            'savings' => $saved,
            'totalIncome' => $totalIncome,
            'totalExpenses' => $totalExpenses,
            // What is left once the expenses are paid and the savings put aside
            'balance' => round($totalIncome - $totalExpenses - max($saved, 0), 2),
        ];
    }

    /**
     * Transactions behind a node of the money flow diagram, most recent first.
     *
     * @param string          $kind       income, expense or savings
     * @param int|string|null $categories Top-level category ids, comma separated (all by default)
     * @param int|string|null $month      The month (current month by default)
     * @param int|string|null $year       The year (current year by default)
     * @return list<array<string, mixed>> The transactions, with their top-level category (top)
     * @throws Error If the kind is unknown
     */
    #[ApiRoute('/budget/flow/transactions', method: 'GET')]
    public static function flowTransactions(string $kind, int|string|null $categories = null, int|string|null $month = null, int|string|null $year = null): array
    {
        if (!in_array($kind, ['income', 'expense', 'savings'], true)) {
            throw new Error("Unknown kind: expected income, expense or savings", 400);
        }
        $ids = null;
        if ($categories !== null && trim((string)$categories) !== '') {
            $ids = array_map('intval', explode(',', (string)$categories));
        }

        [$month, $year] = Month::resolve($month, $year);
        [, $rows] = self::flowRows($month, $year);

        $transactions = [];
        foreach ($rows as $row) {
            if ($row['kind'] !== $kind || ($ids !== null && $kind !== 'savings' && !in_array((int)$row['top'], $ids, true))) {
                continue;
            }
            unset($row['kind']);
            $row['top'] = (int)$row['top'];
            $transactions[] = $row;
        }
        return $transactions;
    }

    /**
     * Transactions of a month classified as in the money flow: income, expense or
     * savings, with their top-level category. Internal transfers (off-budget
     * categories other than the savings) are left out.
     *
     * @param int $month The month
     * @param int $year  The year
     * @return array{array<int, array<string, mixed>>, list<array<string, mixed>>} [categories by id, rows (bank_transaction columns + top + kind)]
     */
    private static function flowRows(int $month, int $year): array
    {
        [$from, $to] = Month::range($month, $year);
        $savings = self::savingsCategories();

        // Top-level category of each transaction (the category itself, or its parent)
        $sql = "SELECT t.*, IF(c.parent_category IS NULL OR c.parent_category = 0 OR c.parent_category = c.id, IFNULL(c.id, 0), c.parent_category) AS top
                FROM bank_transaction t
                LEFT JOIN bank_transaction_category c ON c.id = t.category
                WHERE t.date >= ? AND t.date < ?
                ORDER BY t.rdate DESC, t.id DESC";
        $rows = Db::select($sql, "ss", $from, $to)->fetch_all(MYSQLI_ASSOC);

        $categories = [];
        foreach (Db::select("SELECT id, name, type, icon, color FROM bank_transaction_category", "")->fetch_all(MYSQLI_ASSOC) as $c) {
            $categories[(int)$c['id']] = $c;
        }

        $classified = [];
        foreach ($rows as $row) {
            $top = (int)$row['top'];
            if (in_array((int)$row['category'], $savings, true) || in_array($top, $savings, true)) {
                $row['kind'] = 'savings';
            } else {
                $type = $categories[$top]['type'] ?? 'HORS-BUDGET';
                // Internal transfers are neither income nor expenses; uncategorized ones are shown
                if ($type === 'HORS-BUDGET' && $top !== 0) {
                    continue;
                }
                $row['kind'] = (float)$row['amount'] > 0 ? 'income' : 'expense';
            }
            $classified[] = $row;
        }
        return [$categories, $classified];
    }

    /**
     * Ids of the savings category ("Epargne" by default, setting savings_category)
     * and of its sub-categories. Names are compared without case nor accents, in
     * PHP so that it does not depend on how the database stores the accents.
     *
     * @return list<int> The category ids (empty if there is no savings category)
     */
    private static function savingsCategories(): array
    {
        // French accents first: iconv transliteration depends on the C library
        $accents = ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'à' => 'a', 'â' => 'a', 'À' => 'A',
                    'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'Ô' => 'O', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'Ç' => 'C'];
        $normalize = fn($name) => strtolower(trim((string)preg_replace('/[^A-Za-z0-9 ]/', '', strtr((string)$name, $accents))));
        $wanted = $normalize(Config::has('savings_category') ? Config::get('savings_category') : 'Epargne');

        $categories = Db::select("SELECT id, name, parent_category FROM bank_transaction_category", "")->fetch_all(MYSQLI_ASSOC);
        $roots = [];
        foreach ($categories as $category) {
            if ((int)$category['id'] !== 0 && $normalize($category['name']) === $wanted) {
                $roots[] = (int)$category['id'];
            }
        }

        $ids = $roots;
        foreach ($categories as $category) {
            if (in_array((int)$category['parent_category'], $roots, true) && !in_array((int)$category['id'], $ids, true)) {
                $ids[] = (int)$category['id'];
            }
        }
        return $ids;
    }

    /**
     * Get the enabled insights of a month.
     *
     * @param int|string|null $month The month to get the insights
     * @param int|string|null $year  The year to get the insights
     * @return list<array<string, mixed>> The list of insights
     */
    #[ApiRoute('/budget/insights', method: 'GET')]
    public static function getInsights(int|string|null $month = null, int|string|null $year = null): array
    {
        [$month, $year] = Month::resolve($month, $year);

        // The enabled insights only; each query runs in a read-only transaction, a failing one gives 0
        $insights = Insight::enabled();
        foreach ($insights as &$insight) {
            $insight["amount"] = Insight::amount($insight["sql"], $month, $year) ?? 0;
            unset($insight["sql"], $insight["enabled"], $insight["position"]);
        }

        return $insights;
    }

    /**
     * Get the year insights data.
     *
     * @param int|string|null $year The year to get the insights
     * @return array<int|string, array<string, mixed>> The list of insights by month (by insight id)
     */
    #[ApiRoute('/budget/insights/history', method: 'GET')]
    public static function getInsightsHistory(int|string|null $year = null): array
    {
        // If the date are not set we use the current year
        if (empty($year)) {
            $year = date('Y');
        }

       // insights map
       $insights = array();
         
        // for each month of the year
        for ($month = 1; $month <= 12; $month++) {
            
            // call getInsights
            $monthInsights = self::getInsights($month, $year);

            // Put the results into the insights map
            foreach ($monthInsights as $insight) {
                if (!isset($insights[$insight["id"]])) {
                    $insights[$insight["id"]] = array(
                        "id" => $insight["id"],
                        "name" => $insight["name"],
                        "history" => array()
                    );
                }
                $insights[$insight["id"]]["history"][] = array(
                    "month" => $month,
                    "amount" => $insight["amount"]
                );
            }
        }

        return $insights;
    }
}
