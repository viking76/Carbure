<?php

/**
 * TrendChart.php
 *
 * Charts of the Trends tab: the monthly amounts of every category of a type (to compare
 * them on one chart), the monthly series of a category (with its sub-categories) or of
 * an insight, and the list of the custom charts shown to the household (chosen by the
 * administrators, setting trend_charts).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Setting;
use Error;

final class TrendChart {

    /**
     * Kinds of series: the transactions of a category, or the amount of an insight
     */
    public const TYPES = ['category', 'insight'];

    /**
     * Most charts in the list
     */
    public const MAX_CHARTS = 12;

    /**
     * Setting holding the list: a JSON list of "c<id>" (category) and "i<id>" (insight),
     * at most 12 × 14 characters, within the 255 of settings.value
     */
    private const SETTING = 'trend_charts';

    /**
     * Prefix of each type in the stored list
     */
    private const PREFIXES = ['category' => 'c', 'insight' => 'i'];

    /**
     * Category types of the comparison chart: expenses, incomes, off-budget
     */
    public const CATEGORY_TYPES = ['DEBIT', 'CREDIT', 'HORS-BUDGET'];

    /**
     * Levels of the comparison chart: top-level categories (their sub-categories included)
     * or each category with its own transactions
     */
    public const LEVELS = ['top', 'sub'];

    /**
     * Monthly amounts of the categories of a type over a period, to compare them on one
     * chart: only the categories that have at least one month different from zero,
     * the largest total first. Uncategorized transactions (category 0) are left out.
     *
     * - level top: the transactions of a sub-category count for its top-level category,
     *   whose type is the one compared;
     * - level sub: every category with its own transactions only.
     *
     * An expense category (DEBIT) gives the money spent as a positive amount, the others
     * their signed sum.
     *
     * @param int|string|null $months The number of months (1 to 240)
     * @param int|string|null $offset Number of months between the current month and the last month
     *                                of the period (0 to 240)
     * @param string          $type   DEBIT (default), CREDIT or HORS-BUDGET
     * @param string          $level  top (default) or sub
     * @return array{months: list<string>, categories: list<array<string, mixed>>} The months
     *         (Y-m, oldest first) and the categories: id, name, icon, color, parent_category,
     *         total and amounts (one per month)
     * @throws Error If the type or the level is unknown (400)
     */
    #[ApiRoute('/budget/trends/categories', method: 'GET')]
    public static function categories(int|string|null $months = 24, int|string|null $offset = 0, string $type = 'DEBIT', string $level = 'top'): array
    {
        if (!in_array($type, self::CATEGORY_TYPES, true)) {
            throw new Error("Unknown type: expected DEBIT, CREDIT or HORS-BUDGET", 400);
        }
        if (!in_array($level, self::LEVELS, true)) {
            throw new Error("Unknown level: expected top or sub", 400);
        }
        [$list, $from, $to] = Budget::period($months, $offset);

        $rolled = self::rollUp(self::monthlySums($from, $to), self::comparedCategories($type, $level));
        $sign = $type === 'DEBIT' ? -1 : 1;

        $rows = [];
        foreach ($rolled as ['row' => $row, 'sums' => $sums]) {
            $amounts = array_map(fn(string $month): float => round($sign * ($sums[$month] ?? 0.0), 2) + 0.0, $list);
            if (array_filter($amounts, fn(float $amount): bool => $amount !== 0.0) !== []) {
                $rows[] = $row + ['total' => round(array_sum($amounts), 2) + 0.0, 'amounts' => $amounts];
            }
        }
        usort($rows, fn(array $a, array $b): int => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);
        return ['months' => $list, 'categories' => $rows];
    }

    /**
     * Monthly series of a category or of an insight over a period, oldest first.
     *
     * - category: net amount of its transactions and those of its sub-categories; an
     *   expense category (DEBIT) gives the money spent as a positive amount, the others
     *   their signed sum. planned = its budget of the month like the Budget tab (its own
     *   amount, or the sum of its sub-categories), null without any budget.
     * - insight: the amount of its query for each month (0 when it fails); planned is null.
     *
     * @param string          $type   category or insight
     * @param int|string      $id     The category or the insight
     * @param int|string|null $months The number of months (1 to 240)
     * @param int|string|null $offset Number of months between the current month and the last month
     *                                of the period (0 to 240)
     * @return list<array{month: string, amount: float, planned: float|null}> The months
     * @throws Error If the type or the id is invalid (400), or the category/insight unknown (404)
     */
    #[ApiRoute('/budget/trends/series', method: 'GET')]
    public static function series(string $type, int|string $id, int|string|null $months = 24, int|string|null $offset = 0): array
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new Error("Unknown type: expected category or insight", 400);
        }
        $id = self::id($id);
        [$list, $from, $to] = Budget::period($months, $offset);

        return $type === 'category'
            ? self::categorySeries($id, $list, $from, $to)
            : self::insightSeries($id, $list);
    }

    /**
     * Charts of the Trends tab, in display order. A chart whose category or insight was
     * deleted is left out.
     *
     * @return list<array{type: string, id: int, name: string, icon: string|null, color: string|null}>
     *         The charts, with the name, icon and color of their category or insight
     */
    #[ApiRoute('/budget/trends/charts', method: 'GET')]
    public static function get(): array
    {
        $charts = [];
        foreach (self::stored() as [$type, $id]) {
            $target = self::target($type, $id);
            if ($target !== null) {
                $charts[] = ['type' => $type, 'id' => $id, 'name' => (string)$target['name'],
                    'icon' => $target['icon'] ?? null, 'color' => $target['color'] ?? null];
            }
        }
        return $charts;
    }

    /**
     * Replace the charts of the Trends tab (administrators).
     *
     * @param array<mixed> $charts The charts in display order: [{type, id}], at most 12, without duplicates
     * @return list<array{type: string, id: int, name: string, icon: string|null, color: string|null}> The saved charts
     * @throws Error If a chart is invalid, duplicated, unknown, or there are too many (400)
     */
    #[ApiRoute('/budget/trends/charts', method: 'PUT', admin: true, changes: ['settings'])]
    public static function save(array $charts): array
    {
        if (!array_is_list($charts)) {
            throw new Error("The charts must be a list", 400);
        }
        if (count($charts) > self::MAX_CHARTS) {
            throw new Error("At most " . self::MAX_CHARTS . " charts", 400);
        }

        $tokens = [];
        foreach ($charts as $chart) {
            $token = self::token($chart);
            if (in_array($token, $tokens, true)) {
                throw new Error("The same chart is given twice", 400);
            }
            $tokens[] = $token;
        }

        Setting::set(self::SETTING, (string)json_encode($tokens));
        return self::get();
    }

    /**
     * Check a chart sent to save(): known type, positive id of an existing category or insight.
     *
     * @param mixed $chart The chart: {type, id}
     * @return string Its stored form ("c<id>" or "i<id>")
     * @throws Error If invalid or unknown (400)
     */
    private static function token(mixed $chart): string
    {
        if (!is_array($chart) || !in_array($chart['type'] ?? null, self::TYPES, true)) {
            throw new Error("Each chart needs a type: category or insight", 400);
        }
        $type = (string)$chart['type'];
        $id = self::id(is_int($chart['id'] ?? null) || is_string($chart['id'] ?? null) ? $chart['id'] : '');
        if (self::target($type, $id) === null) {
            throw new Error("Unknown $type: $id", 400);
        }
        return self::PREFIXES[$type] . $id;
    }

    /**
     * Check an id: a positive integer.
     *
     * @param int|string $id The id
     * @return int The id
     * @throws Error If it is not a positive integer (400)
     */
    private static function id(int|string $id): int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
        if ($value === false) {
            throw new Error("Invalid id: a positive integer is expected", 400);
        }
        return $value;
    }

    /**
     * Charts of the setting, unreadable entries left out.
     *
     * @return list<array{0: string, 1: int}> [type, id] of each chart
     */
    private static function stored(): array
    {
        $tokens = json_decode(Setting::get(self::SETTING, '[]'), true);
        $types = array_flip(self::PREFIXES);
        $charts = [];
        foreach (is_array($tokens) ? $tokens : [] as $token) {
            if (is_string($token) && preg_match('/^([ci])([1-9][0-9]{0,9})$/', $token, $match)) {
                $charts[] = [$types[$match[1]], (int)$match[2]];
            }
        }
        return $charts;
    }

    /**
     * Category or insight shown by a chart.
     *
     * @param string $type category or insight
     * @param int    $id   Its id
     * @return array<string, mixed>|null name, icon, color (and type, parent_category for a
     *         category, sql for an insight), null if it does not exist
     */
    private static function target(string $type, int $id): ?array
    {
        $sql = $type === 'category'
            ? "SELECT name, icon, color, type, parent_category FROM bank_transaction_category WHERE id = ?"
            : "SELECT name, icon, color, `sql` FROM budget_insight WHERE id = ?";
        return Db::queryOne($sql, "i", $id);
    }

    /**
     * Monthly series of a category and its sub-categories.
     *
     * @param int          $id     The category
     * @param list<string> $months The months of the period (Y-m)
     * @param string       $from   First day of the period (Y-m-d)
     * @param string       $to     First day after the period (Y-m-d)
     * @return list<array{month: string, amount: float, planned: float|null}> The months
     * @throws Error If the category does not exist (404)
     */
    private static function categorySeries(int $id, array $months, string $from, string $to): array
    {
        $category = self::target('category', $id);
        if ($category === null) {
            throw new Error("Category not found", 404);
        }
        $children = array_map('intval', array_column(Db::select(
            "SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> parent_category", "i", $id
        )->fetch_all(MYSQLI_ASSOC), 'id'));
        $ids = implode(',', [$id, ...$children]);

        $sql = "SELECT DATE_FORMAT(date, '%Y-%m') AS month, SUM(amount) AS total FROM bank_transaction
                WHERE FIND_IN_SET(category, ?) AND date >= ? AND date < ? GROUP BY month";
        $totals = array_column(Db::select($sql, "sss", $ids, $from, $to)->fetch_all(MYSQLI_ASSOC), 'total', 'month');
        $sign = $category['type'] === 'DEBIT' ? -1 : 1;
        $planned = self::plannedByMonth($id, $children, $from, $to);

        $series = [];
        foreach ($months as $month) {
            $series[] = ['month' => $month, 'amount' => round($sign * (float)($totals[$month] ?? 0), 2) + 0.0,
                'planned' => $planned[$month] ?? null];
        }
        return $series;
    }

    /**
     * Budget of a category for each month of a period, as in the Budget tab: its own
     * amount, or the sum of the budgets of its sub-categories when it has some and no
     * amount of its own.
     *
     * @param int       $id       The category
     * @param list<int> $children Its sub-categories
     * @param string    $from     First day of the period (Y-m-d)
     * @param string    $to       First day after the period (Y-m-d)
     * @return array<string, float> The budget of the months that have one (Y-m => amount)
     */
    private static function plannedByMonth(int $id, array $children, string $from, string $to): array
    {
        $sql = "SELECT DATE_FORMAT(date, '%Y-%m') AS month, category, amount FROM budget
                WHERE FIND_IN_SET(category, ?) AND date >= ? AND date < ?";
        $own = [];
        $sum = [];
        foreach (Db::select($sql, "sss", implode(',', [$id, ...$children]), $from, $to)->fetch_all(MYSQLI_ASSOC) as $row) {
            $month = (string)$row['month'];
            if ((int)$row['category'] === $id) {
                $own[$month] = (float)$row['amount'];
            } else {
                $sum[$month] = ($sum[$month] ?? 0.0) + (float)$row['amount'];
            }
        }

        $planned = [];
        foreach (array_keys($own + $sum) as $month) {
            $mode = Budget::budgetMode($own[$month] ?? 0.0, count($children));
            $planned[(string)$month] = round($mode === 'children' ? ($sum[$month] ?? 0.0) : ($own[$month] ?? 0.0), 2);
        }
        return $planned;
    }

    /**
     * Monthly series of an insight: the amount of its query for each month.
     *
     * @param int          $id     The insight
     * @param list<string> $months The months of the period (Y-m)
     * @return list<array{month: string, amount: float, planned: null}> The months
     * @throws Error If the insight does not exist (404)
     */
    private static function insightSeries(int $id, array $months): array
    {
        $insight = self::target('insight', $id);
        if ($insight === null) {
            throw new Error("Insight not found", 404);
        }

        $series = [];
        foreach ($months as $month) {
            [$year, $number] = array_map('intval', explode('-', $month));
            $series[] = ['month' => $month, 'amount' => Insight::amount((string)$insight['sql'], $number, $year) ?? 0.0, 'planned' => null];
        }
        return $series;
    }

    /**
     * Net amount of the transactions of each category for each month of a period, in a
     * single grouped query (uncategorized transactions left out).
     *
     * @param string $from First day of the period (Y-m-d)
     * @param string $to   First day after the period (Y-m-d)
     * @return array<int, array<string, float>> category => month (Y-m) => signed sum
     */
    private static function monthlySums(string $from, string $to): array
    {
        $sql = "SELECT category, DATE_FORMAT(date, '%Y-%m') AS month, SUM(amount) AS total FROM bank_transaction
                WHERE category <> 0 AND date >= ? AND date < ? GROUP BY category, month";
        $sums = [];
        foreach (Db::select($sql, "ss", $from, $to)->fetch_all(MYSQLI_ASSOC) as $row) {
            $sums[(int)$row['category']][(string)$row['month']] = (float)$row['total'];
        }
        return $sums;
    }

    /**
     * Categories counted by the comparison chart: for each category of the household,
     * the one its transactions count for (itself, or its top-level category at level
     * top), kept when that one has the compared type.
     *
     * @param string $type  The compared type
     * @param string $level top or sub
     * @return array<int, array{target: int, row: array<string, mixed>}> category => the
     *         category it counts for and the description of that one (id, name, icon,
     *         color, parent_category)
     */
    private static function comparedCategories(string $type, string $level): array
    {
        $all = [];
        foreach (Db::select("SELECT id, name, icon, color, type, parent_category FROM bank_transaction_category WHERE id <> 0", "")->fetch_all(MYSQLI_ASSOC) as $row) {
            $all[(int)$row['id']] = $row;
        }

        $compared = [];
        foreach ($all as $id => $row) {
            $parent = (int)$row['parent_category'];
            $target = $level === 'top' && $parent !== $id && isset($all[$parent]) ? $parent : $id;
            if ($all[$target]['type'] === $type) {
                $compared[$id] = ['target' => $target, 'row' => self::categoryRow($all[$target])];
            }
        }
        return $compared;
    }

    /**
     * Description of a category in the comparison chart.
     *
     * @param array<string, mixed> $row The category (bank_transaction_category)
     * @return array<string, mixed> id, name, icon, color, parent_category
     */
    private static function categoryRow(array $row): array
    {
        return ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'icon' => $row['icon'] ?? null,
            'color' => $row['color'] ?? null, 'parent_category' => (int)$row['parent_category']];
    }

    /**
     * Adds up the monthly sums of the categories into the categories they count for.
     *
     * @param array<int, array<string, float>>                          $sums       category => month => sum
     * @param array<int, array{target: int, row: array<string, mixed>}> $categories Result of comparedCategories()
     * @return array<int, array{row: array<string, mixed>, sums: array<string, float>}> counted
     *         category => its description and its sums (month => sum)
     */
    private static function rollUp(array $sums, array $categories): array
    {
        $rolled = [];
        foreach (array_intersect_key($sums, $categories) as $id => $byMonth) {
            $target = $categories[$id]['target'];
            $rolled[$target] ??= ['row' => $categories[$id]['row'], 'sums' => []];
            foreach ($byMonth as $month => $sum) {
                $rolled[$target]['sums'][$month] = ($rolled[$target]['sums'][$month] ?? 0.0) + $sum;
            }
        }
        return $rolled;
    }
}
