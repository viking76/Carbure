<?php

/**
 * BudgetAlert.php
 *
 * Alerts of the users when the spending of the month of a budgeted category reaches
 * their threshold (a percentage of the budget, 100 by default).
 *
 * - Checked after each synchronization and each import of a statement file, for the
 *   current month only: an import of past months does not alert on old budgets.
 * - The spending of a category is the one of the Budgets screen (Budget::get(): the
 *   net spending of its transactions and those of its sub-categories, 0 when refunds
 *   exceed it), for the expense categories (DEBIT) with a budget: the top-level
 *   categories and their sub-categories.
 * - Sent with the kind "budget" of the notification preferences, off on every channel
 *   by default (opt-in).
 * - Thresholds of each user: a global one for all the budgets (users.budget_threshold),
 *   which can be turned off (users.budget_alert_all), and thresholds of categories
 *   (budget_alert_threshold), used instead of the global one for their category: 80 % on
 *   two categories and 100 % on the others, or only two categories. Before the migration
 *   2026-10-27_budget_alert_threshold, all the budgets or the one of
 *   users.budget_alert_category, at users.budget_threshold.
 * - Once per user, category and month (table budget_alert); alerted again when the
 *   budget of the category is raised above the amount already alerted and the new
 *   amount is reached too.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Lib\Logger;
use Carbure\Lib\Messages;
use Error;
use Exception;
use Throwable;

final class BudgetAlert {

    /** Kind of notification of the alerts */
    const KIND = 'budget';

    /** Threshold of a user without a choice, in percent of the budget */
    const DEFAULT_THRESHOLD = 100;

    /** Lowest threshold accepted, in percent */
    const MIN_THRESHOLD = 50;

    /** Highest threshold accepted, in percent */
    const MAX_THRESHOLD = 200;

    /** Most categories with a threshold of their own, for one user */
    const MAX_CATEGORY_THRESHOLDS = 50;

    /**
     * Check the budgets of the current month and alert the users whose threshold is
     * reached. A failure is logged: it never fails a synchronization or an import.
     *
     * @return int The number of alerts sent
     */
    public static function check(): int
    {
        try {
            return self::checkMonth((int)date('n'), (int)date('Y'));
        } catch (Throwable $e) {
            Logger::error("Check of the budgets failed: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Check the budgets of a month and alert the users whose threshold is reached.
     *
     * @param int $month The month (1-12)
     * @param int $year  The year
     * @return int The number of alerts sent
     */
    public static function checkMonth(int $month, int $year): int
    {
        $categories = self::spending($month, $year);
        if (empty($categories)) {
            return 0;
        }

        $first = sprintf('%04d-%02d-01', $year, $month);
        $sent = 0;
        foreach (self::subscribers() as $user) {
            foreach ($categories as $category) {
                $sent += self::alert($user, $category, $first) ? 1 : 0;
            }
        }
        if ($sent > 0) {
            Logger::info("$sent budget alert(s) sent for $first");
        }
        return $sent;
    }

    /**
     * Spending of the month of each expense category with a budget, as the Budgets
     * screen computes it: the top-level categories, then the sub-categories.
     *
     * @param int $month The month (1-12)
     * @param int $year  The year
     * @return list<array{id: int, name: string, budget: float, spent: float}>
     */
    public static function spending(int $month, int $year): array
    {
        $rows = [];
        foreach (Budget::get($month, $year, 0) as $row) {
            $rows[] = $row;
            if ($row['children'] > 0) {
                array_push($rows, ...Budget::get($month, $year, (int)$row['id']));
            }
        }

        $categories = [];
        foreach ($rows as $row) {
            if ($row['type'] === 'DEBIT' && (float)$row['budget'] > 0) {
                $categories[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'budget' => round((float)$row['budget'], 2),
                    'spent' => round((float)$row['consummed'], 2),
                ];
            }
        }
        return $categories;
    }

    /**
     * Expense categories with a budget in a month, to choose the one watched: the
     * top-level categories, each followed by its sub-categories ("Parent › Child").
     *
     * @param int $month The month (1-12)
     * @param int $year  The year
     * @return list<array{id: int, name: string}>
     */
    public static function budgets(int $month, int $year): array
    {
        $budgets = [];
        foreach (Budget::get($month, $year, 0) as $row) {
            if ($row['type'] !== 'DEBIT') {
                continue;
            }
            if ((float)$row['budget'] > 0) {
                $budgets[] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
            }
            if ($row['children'] > 0) {
                array_push($budgets, ...self::childBudgets($month, $year, $row));
            }
        }
        return $budgets;
    }

    /**
     * Sub-categories with a budget of a category, named after it.
     *
     * @param int                  $month  The month (1-12)
     * @param int                  $year   The year
     * @param array<string, mixed> $parent The category (row of Budget::get())
     * @return list<array{id: int, name: string}>
     */
    private static function childBudgets(int $month, int $year, array $parent): array
    {
        $budgets = [];
        foreach (Budget::get($month, $year, (int)$parent['id']) as $row) {
            if ($row['type'] === 'DEBIT' && (float)$row['budget'] > 0) {
                $budgets[] = ['id' => (int)$row['id'], 'name' => $parent['name'] . ' › ' . $row['name']];
            }
        }
        return $budgets;
    }

    /**
     * Category watched by the budget alerts of a user: null for all the budgets, also
     * before the migration 2026-10-23_user_budget_alert_category is applied.
     *
     * @param int $userId The user
     * @return int|null
     */
    public static function watchedCategory(int $userId): ?int
    {
        try {
            $row = Db::queryOne("SELECT budget_alert_category FROM users WHERE id = ?", "i", $userId);
        } catch (Exception $e) {
            return null;
        }
        return empty($row['budget_alert_category']) ? null : (int)$row['budget_alert_category'];
    }

    /**
     * Stored value of the category watched sent by a user: null (all the budgets) for
     * 0, "" or null, otherwise an existing expense category.
     *
     * @param int|string|null $category The category sent
     * @return int|null
     * @throws Error If it is not an expense category (400)
     */
    public static function categoryValue(int|string|null $category): ?int
    {
        if ($category === null || $category === '' || $category === 0 || $category === '0') {
            return null;
        }
        $id = filter_var($category, FILTER_VALIDATE_INT);
        $row = $id === false ? null : Db::queryOne("SELECT type FROM bank_transaction_category WHERE id = ?", "i", $id);
        if ($row === null || $row['type'] !== 'DEBIT') {
            throw new ApiError("The budget watched must be an expense category (or 0 for all the budgets)", 400, 'budget_alert_category_invalid');
        }
        return $id;
    }

    /**
     * Thresholds of the budget alerts of a user: the global one (all the budgets, null
     * when it is off) and those of the categories that have their own.
     *
     * @param int $userId The user
     * @return array{all: int|null, categories: array<int, int>} Thresholds in percent, by category
     */
    public static function rules(int $userId): array
    {
        try {
            $user = Db::queryOne("SELECT budget_threshold, budget_alert_all FROM users WHERE id = ?", "i", $userId);
            $rows = Db::select("SELECT category, threshold FROM budget_alert_threshold WHERE user_id = ? ORDER BY category",
                "i", $userId)->fetch_all(MYSQLI_ASSOC);
        } catch (Exception $e) {
            return self::legacyRules($userId);
        }
        $categories = [];
        foreach ($rows as $row) {
            $categories[(int)$row['category']] = (int)$row['threshold'];
        }
        $threshold = $user === null ? self::DEFAULT_THRESHOLD : (int)$user['budget_threshold'];
        return ['all' => $user !== null && !(int)$user['budget_alert_all'] ? null : $threshold, 'categories' => $categories];
    }

    /**
     * Thresholds of a user before the migration 2026-10-27_budget_alert_threshold: his
     * threshold on all the budgets, or on the one he watched.
     *
     * @param int $userId The user
     * @return array{all: int|null, categories: array<int, int>}
     */
    private static function legacyRules(int $userId): array
    {
        try {
            $row = Db::queryOne("SELECT budget_threshold FROM users WHERE id = ?", "i", $userId);
        } catch (Exception $e) {
            $row = null;
        }
        $threshold = $row === null ? self::DEFAULT_THRESHOLD : (int)$row['budget_threshold'];
        $category = self::watchedCategory($userId);
        return $category === null ? ['all' => $threshold, 'categories' => []] : ['all' => null, 'categories' => [$category => $threshold]];
    }

    /**
     * Whether the thresholds of categories can be saved: the migration
     * 2026-10-27_budget_alert_threshold is applied.
     *
     * @return bool
     */
    public static function thresholdsReady(): bool
    {
        try {
            Db::queryOne("SELECT 1 FROM budget_alert_threshold LIMIT 1", "");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * The only category of thresholds without the global one, as the budget watched of
     * the clients older than the thresholds of categories (null: none, or several).
     *
     * @param array{all: int|null, categories: array<int, int>} $rules The thresholds of a user
     * @return int|null
     */
    public static function watchedOf(array $rules): ?int
    {
        return $rules['all'] === null && count($rules['categories']) === 1 ? (int)array_key_first($rules['categories']) : null;
    }

    /**
     * Stored values of the thresholds of categories sent by a user.
     *
     * @param array<mixed> $thresholds [{category, threshold}]: an expense category and an
     *                                 integer from MIN_THRESHOLD to MAX_THRESHOLD
     * @return array<int, int> Thresholds by category
     * @throws Error If one is not valid, or a category is given twice (400)
     */
    public static function thresholdsValue(array $thresholds): array
    {
        if (count($thresholds) > self::MAX_CATEGORY_THRESHOLDS) {
            throw new ApiError("At most " . self::MAX_CATEGORY_THRESHOLDS . " budget alert thresholds of categories", 400,
                'budget_alert_threshold_limit', ['max' => self::MAX_CATEGORY_THRESHOLDS]);
        }
        $values = [];
        foreach ($thresholds as $item) {
            [$id, $threshold] = self::thresholdItem($item);
            if (isset($values[$id])) {
                throw new ApiError("A category has two budget alert thresholds", 400, 'budget_alert_duplicate_category');
            }
            $values[$id] = $threshold;
        }
        return $values;
    }

    /**
     * One threshold of a category sent by a user.
     *
     * @param mixed $item {category, threshold}
     * @return array{0: int, 1: int} The category and the threshold
     * @throws Error If it is not valid (400)
     */
    private static function thresholdItem(mixed $item): array
    {
        $category = is_array($item) ? ($item['category'] ?? null) : null;
        $threshold = is_array($item) ? ($item['threshold'] ?? null) : null;
        if (!(is_int($category) || is_string($category)) || !(is_int($threshold) || is_float($threshold) || is_string($threshold))) {
            throw new Error("Each budget alert threshold is {category, threshold}", 400);
        }
        $id = self::categoryValue($category);
        if ($id === null) {
            throw new ApiError("The budget alert threshold of a category needs an expense category", 400, 'budget_alert_category_not_expense');
        }
        return [$id, self::thresholdValue($threshold)];
    }

    /**
     * Save the thresholds of a user, all at once; budget_alert_category keeps the budget
     * watched of the older clients.
     *
     * @param int             $userId     The user
     * @param bool            $all        The global threshold is on
     * @param array<int, int> $categories Thresholds by category
     * @return void
     * @throws Error If the migration 2026-10-27_budget_alert_threshold is not applied (409)
     */
    public static function saveRules(int $userId, bool $all, array $categories): void
    {
        $db = Db::getConnection();
        $db->begin_transaction();
        try {
            $watched = !$all && count($categories) === 1 ? (int)array_key_first($categories) : null;
            Db::execute("UPDATE users SET budget_alert_all = ?, budget_alert_category = ? WHERE id = ?", "iii",
                (int)$all, $watched, $userId);
            Db::execute("DELETE FROM budget_alert_threshold WHERE user_id = ?", "i", $userId);
            $rows = [];
            foreach ($categories as $category => $threshold) {
                $rows[] = [$userId, $category, $threshold];
            }
            Db::executeMany("INSERT INTO budget_alert_threshold (user_id, category, threshold) VALUES (?, ?, ?)", "iii", $rows);
            $db->commit();
        } catch (Exception $e) {
            $db->rollback();
            throw new Error("Apply the pending database migrations first: the budget alert thresholds cannot be saved yet", 409);
        }
    }

    /**
     * Users who receive the budget alerts: at least one channel of the kind "budget"
     * on, with their language and thresholds.
     *
     * @return list<array{id: int, language: string, rules: array{all: int|null, categories: array<int, int>}}>
     */
    private static function subscribers(): array
    {
        $users = Db::select("SELECT id, language FROM users", "")->fetch_all(MYSQLI_ASSOC);
        $subscribers = [];
        foreach ($users as $user) {
            $channels = Notification::preferences((int)$user['id'])[self::KIND];
            if ($channels['push'] || $channels['email']) {
                $subscribers[] = [
                    'id' => (int)$user['id'],
                    'language' => (string)$user['language'],
                    'rules' => self::rules((int)$user['id']),
                ];
            }
        }
        return $subscribers;
    }

    /**
     * Alert a user for a category when his threshold for it (its own, or the global one)
     * is reached and he was not alerted yet for this month (or for a lower budget).
     *
     * @param array{id: int, language: string, rules: array{all: int|null, categories: array<int, int>}} $user     The user
     * @param array{id: int, name: string, budget: float, spent: float}                                 $category The category
     * @param string                                                                                    $month    First day of the month (Y-m-d)
     * @return bool Whether the user was alerted
     */
    private static function alert(array $user, array $category, string $month): bool
    {
        $threshold = $user['rules']['categories'][$category['id']] ?? $user['rules']['all'];
        if ($threshold === null) {
            return false;
        }
        $percent = self::percent($category['spent'], $category['budget']);
        if ($percent < $threshold || self::alreadySent($user['id'], $category['id'], $month, $category['budget'])) {
            return false;
        }

        Db::execute("INSERT INTO budget_alert (user_id, category, month, budget, spent, percent, sent_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE budget = VALUES(budget), spent = VALUES(spent), percent = VALUES(percent), sent_at = NOW()",
            "iisddi", $user['id'], $category['id'], $month, $category['budget'], $category['spent'], $percent);

        [$title, $body] = self::message($user['language'], $category['name'], $category['spent'], $category['budget'], $percent);
        Notification::notify($user['id'], self::KIND, $title, $body, Transaction::countUnpointed());
        return true;
    }

    /**
     * Whether a user was already alerted for a category this month, for this budget
     * or a higher one (a budget raised since the alert arms it again).
     *
     * @param int    $userId   The user
     * @param int    $category The category
     * @param string $month    First day of the month (Y-m-d)
     * @param float  $budget   The budget of the month of the category
     * @return bool
     */
    private static function alreadySent(int $userId, int $category, string $month, float $budget): bool
    {
        $row = Db::queryOne("SELECT budget FROM budget_alert WHERE user_id = ? AND category = ? AND month = ?",
            "iis", $userId, $category, $month);
        return $row !== null && $budget <= (float)$row['budget'];
    }

    /**
     * Spending in percent of the budget, rounded down (99.9 % is not 100 %).
     *
     * @param float $spent  The spending of the month
     * @param float $budget The budget of the month (> 0)
     * @return int
     */
    public static function percent(float $spent, float $budget): int
    {
        return (int)floor(round($spent / $budget * 100, 6));
    }

    /**
     * Title and text of an alert, in the language of the user (Messages): "Budget
     * dépassé : Courses" / "312,40 € sur 300,00 € (104 %)"; "Budget presque atteint"
     * below 100 %.
     *
     * @param string $language The language of the user (fr, en; anything else fr)
     * @param string $name     The name of the category
     * @param float  $spent    The spending of the month
     * @param float  $budget   The budget of the month
     * @param int    $percent  The spending in percent of the budget
     * @return array{0: string, 1: string} Title and text
     */
    public static function message(string $language, string $name, float $spent, float $budget, int $percent): array
    {
        $title = Messages::t($language, $percent >= 100 ? 'budget_exceeded_title' : 'budget_reached_title', ['category' => $name]);
        $body = Messages::t($language, 'budget_body', [
            'spent' => Messages::amount($language, $spent),
            'budget' => Messages::amount($language, $budget),
            'percent' => $percent,
        ]);
        return [$title, $body];
    }

    /**
     * Stored value of a threshold sent by a user: an integer percentage between
     * MIN_THRESHOLD and MAX_THRESHOLD.
     *
     * @param int|float|string $threshold The threshold sent (a number or a numeric string)
     * @return int
     * @throws Error If it is not an integer in the range (400)
     */
    public static function thresholdValue(int|float|string $threshold): int
    {
        $value = filter_var($threshold, FILTER_VALIDATE_INT);
        if ($value === false || $value < self::MIN_THRESHOLD || $value > self::MAX_THRESHOLD) {
            throw new ApiError("The budget alert threshold must be an integer between "
                . self::MIN_THRESHOLD . " and " . self::MAX_THRESHOLD . " (percent)", 400, 'budget_alert_threshold_invalid',
                ['min' => self::MIN_THRESHOLD, 'max' => self::MAX_THRESHOLD]);
        }
        return $value;
    }
}
