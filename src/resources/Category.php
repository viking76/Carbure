<?php

/**
 * Category.php
 * *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiError;
use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Setting;
use Error;

final class Category {

    /**
     * Get all the categories available.
     *
     * @return list<array<string, mixed>> The list of categories
     */
    #[ApiRoute('/category', method: 'GET')]
    public static function get(): array
    {
        // Get the category
        $sql = "SELECT * FROM bank_transaction_category";
        $result = Db::select($sql);

        $categories = array();

        // Collect the rows
        if ($result->num_rows > 0) {

            while ($row = $result->fetch_assoc()) {
                $categories[] = $row;
            }
        }

        return $categories;
    }

    /**
     * Types of category
     */
    private const TYPES = ['DEBIT', 'CREDIT', 'HORS-BUDGET'];

    /**
     * Icon of a category (SF Symbols name used by the iOS app, may be empty)
     */
    public const ICON_PATTERN = '/^[a-z0-9._]{0,50}$/';

    /**
     * Color of a category (SwiftUI name or hex, may be empty)
     */
    public const COLOR_PATTERN = '/^(#[0-9a-fA-F]{3,8}|[a-zA-Z]{0,30})$/';

    /**
     * Validate the fields of a category and return them normalized.
     *
     * @param int|null        $id     The category being modified (null for a new one)
     * @param string          $name   The name
     * @param int|string|null $parent The parent category (0, '' or null for a top-level category)
     * @param string          $type   DEBIT, CREDIT or HORS-BUDGET
     * @param string|null     $icon   The icon (SF Symbols name used by the iOS app)
     * @param string|null     $color  The color (SwiftUI name or hex)
     * @return array{string, int, string, string, string} [name, parent, type, icon, color]
     * @throws Error If a field is invalid
     */
    private static function validate(?int $id, string $name, int|string|null $parent, string $type, ?string $icon, ?string $color): array
    {
        $name = trim($name);
        $parent = (int)$parent;
        if ($name === '' || mb_strlen($name) > 50) {
            throw new ApiError("The name must contain 1 to 50 characters", 400, 'category_name_length');
        }
        if (!in_array($type, self::TYPES, true)) {
            throw new ApiError("Invalid category type", 400, 'category_type_invalid');
        }
        if (!preg_match(self::ICON_PATTERN, (string)$icon) || !preg_match(self::COLOR_PATTERN, (string)$color)) {
            throw new ApiError("Invalid icon or color", 400, 'category_style_invalid');
        }

        $duplicate = Db::queryOne("SELECT id FROM bank_transaction_category WHERE name = ? AND id <> ?", "si", $name, $id ?? -1);
        if ($duplicate) {
            throw new ApiError("A category with this name already exists", 409, 'category_exists');
        }

        if ($parent !== 0) {
            self::validateParent($id, $parent);
        }

        return [$name, $parent, $type, (string)$icon, (string)$color];
    }

    /**
     * Check the parent of a sub-category: one level of sub-categories, so the parent
     * must be a top-level category and the category must have no sub-category itself.
     *
     * @param int|null $id     The category (null for a new one)
     * @param int      $parent The parent category (not 0)
     * @return void
     * @throws Error If the parent is not a top-level category, or the category has sub-categories
     */
    private static function validateParent(?int $id, int $parent): void
    {
        $row = Db::queryOne("SELECT parent_category FROM bank_transaction_category WHERE id = ?", "i", $parent);
        if (!$row || (int)$row['parent_category'] !== 0 || $parent === (int)$id) {
            throw new ApiError("The parent must be a top-level category", 400, 'category_parent_invalid');
        }
        if ($id !== null && Db::queryOne("SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> ? LIMIT 1", "ii", $id, $id)) {
            throw new ApiError("A category with sub-categories cannot become a sub-category", 400, 'category_cannot_be_subcategory');
        }
    }

    /**
     * Create a category.
     *
     * @param string          $name            The name
     * @param string          $type            DEBIT, CREDIT or HORS-BUDGET
     * @param int|string|null $parent_category The parent category (0 for a top-level category)
     * @param string|null     $icon            The icon (SF Symbols name)
     * @param string|null     $color           The color (SwiftUI name or hex)
     * @return array<string, mixed> The category
     * @throws Error If invalid or the name already exists
     */
    #[ApiRoute('/category', method: 'POST', admin: true, changes: ['categories'])]
    public static function create(string $name, string $type, int|string|null $parent_category = 0, ?string $icon = '', ?string $color = ''): array
    {
        [$name, $parent, $type, $icon, $color] = self::validate(null, $name, $parent_category, $type, $icon, $color);

        $stmt = Db::execute("INSERT INTO bank_transaction_category (name, parent_category, type, icon, color) VALUES (?, ?, ?, ?, ?)",
            "sisss", $name, $parent, $type, $icon, $color);

        return ['id' => $stmt->insert_id, 'name' => $name, 'parent_category' => $parent, 'type' => $type, 'icon' => $icon, 'color' => $color];
    }

    /**
     * Modify a category.
     *
     * @param int             $id              The category
     * @param string          $name            The name
     * @param string          $type            DEBIT, CREDIT or HORS-BUDGET
     * @param int|string|null $parent_category The parent category (0 for a top-level category)
     * @param string|null     $icon            The icon (SF Symbols name)
     * @param string|null     $color           The color (SwiftUI name or hex)
     * @return array<string, mixed> The category
     * @throws Error If not found, protected, invalid or the name already exists
     */
    #[ApiRoute('/category', method: 'PUT', admin: true, changes: ['categories'])]
    public static function update(int $id, string $name, string $type, int|string|null $parent_category = 0, ?string $icon = '', ?string $color = ''): array
    {
        self::existing($id);
        [$name, $parent, $type, $icon, $color] = self::validate($id, $name, $parent_category, $type, $icon, $color);

        Db::execute("UPDATE bank_transaction_category SET name = ?, parent_category = ?, type = ?, icon = ?, color = ? WHERE id = ?",
            "sisssi", $name, $parent, $type, $icon, $color, $id);

        return ['id' => $id, 'name' => $name, 'parent_category' => $parent, 'type' => $type, 'icon' => $icon, 'color' => $color];
    }

    /**
     * Delete a category: its transactions become uncategorized, its budgets and
     * categorization rules are deleted.
     *
     * @param int $id The category
     * @return bool True if deleted
     * @throws Error If not found, protected or with sub-categories
     */
    #[ApiRoute('/category', method: 'DELETE', admin: true, changes: ['categories', 'budgets', 'transactions'])]
    public static function delete(int $id): bool
    {
        self::existing($id);
        if (Db::queryOne("SELECT id FROM bank_transaction_category WHERE parent_category = ? AND id <> ? LIMIT 1", "ii", $id, $id)) {
            throw new ApiError("Delete or move its sub-categories first", 409, 'category_has_subcategories');
        }

        Db::execute("UPDATE bank_transaction SET category = 0" . Transaction::sourceSql(null) . " WHERE category = ?", "i", $id);
        Db::execute("DELETE FROM budget WHERE category = ?", "i", $id);
        Db::execute("DELETE FROM bank_transaction_category WHERE id = ?", "i", $id);

        return true;
    }

    /**
     * Check that a category exists and can be modified.
     *
     * @param int $id The category
     * @return void
     * @throws Error If not found or protected
     */
    private static function existing(int $id): void
    {
        if ($id === 0) {
            throw new ApiError("The default category cannot be modified", 400, 'category_default_readonly');
        }
        if (!Db::queryOne("SELECT id FROM bank_transaction_category WHERE id = ?", "i", $id)) {
            throw new ApiError("Category not found", 404, 'category_not_found');
        }
    }

    /**
     * Cache of amountReady()
     */
    private static ?bool $amountReady = null;

    /**
     * Check if the rules have their optional amount (migration 2026-11-05_rule_amount).
     *
     * @return bool
     */
    public static function amountReady(): bool
    {
        return self::$amountReady ??= Db::queryOne("SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction_category_keyword' AND COLUMN_NAME = 'amount'", "") !== null;
    }

    /**
     * SQL of the amount of a rule in a SELECT on the rules (alias k): NULL before the
     * migration 2026-11-05_rule_amount.
     *
     * @return string "k.amount" or "NULL AS amount"
     */
    private static function amountColumn(): string
    {
        return self::amountReady() ? "k.amount" : "NULL AS amount";
    }

    /**
     * Get the automatic categorization rules (keywords found in the labels).
     *
     * @return list<array<string, mixed>> The rules with their category name and amount (null: any amount), sorted by category
     */
    #[ApiRoute('/category/keyword', method: 'GET')]
    public static function getKeywords(): array
    {
        $sql = "SELECT k.id, k.keyword, k.category, c.name AS category_name, k.notify, " . self::amountColumn() . "
                FROM bank_transaction_category_keyword k
                JOIN bank_transaction_category c ON c.id = k.category
                ORDER BY c.name, k.keyword" . (self::amountReady() ? ", k.amount" : "");
        return Db::select($sql, "")->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Validate the fields of a rule and return them normalized.
     *
     * @param int|null              $id       The rule being modified (null for a new one)
     * @param string                $keyword  The keyword
     * @param int                   $category The category
     * @param int|float|string|null $amount   The amount (null or '' for any amount; its absolute value is kept)
     * @return array{string, string|null} [keyword in upper case, amount with 2 decimals or null]
     * @throws Error If a field is invalid, the category does not exist or the rule already exists
     */
    private static function validateRule(?int $id, string $keyword, int $category, int|float|string|null $amount): array
    {
        // Labels are stored in upper case
        $keyword = strtoupper(trim($keyword));
        if ($keyword === '' || strlen($keyword) > 60) {
            throw new ApiError("The keyword must contain 1 to 60 characters", 400, 'keyword_length');
        }
        if (!Db::queryOne("SELECT id FROM bank_transaction_category WHERE id=?", "i", $category)) {
            throw new ApiError("Category not found", 404, 'category_not_found');
        }
        $amount = self::ruleAmount($amount);
        self::checkUnique($id, $keyword, $amount);
        return [$keyword, $amount];
    }

    /**
     * Normalize the amount of a rule: its absolute value with 2 decimals, null for any amount.
     *
     * @param int|float|string|null $amount The amount ("10", "10.5", "-10,50"...; null or '' for any amount)
     * @return string|null The amount ("10.50") or null
     * @throws Error If the amount is not a number, is 0 or is too large, or the migration is pending
     */
    private static function ruleAmount(int|float|string|null $amount): ?string
    {
        if ($amount === null || trim((string)$amount) === '') {
            return null;
        }
        $value = str_replace(',', '.', trim((string)$amount));
        $cents = is_numeric($value) ? (int)round(abs((float)$value) * 100) : 0;
        if ($cents <= 0 || $cents >= 10000000000) {
            throw new ApiError("Invalid amount", 400, 'rule_amount_invalid');
        }
        if (!self::amountReady()) {
            throw new ApiError("The amount of the rules needs the pending database migrations", 409, 'rule_amount_migration');
        }
        return sprintf('%.2f', $cents / 100);
    }

    /**
     * Check that no other rule has the same keyword and the same amount (or both no amount).
     *
     * @param int|null    $id      The rule being modified (null for a new one)
     * @param string      $keyword The keyword (upper case)
     * @param string|null $amount  The amount (null for any amount)
     * @return void
     * @throws Error 409 If such a rule exists
     */
    private static function checkUnique(?int $id, string $keyword, ?string $amount): void
    {
        $duplicate = self::amountReady()
            ? Db::queryOne("SELECT id FROM bank_transaction_category_keyword WHERE keyword=? AND amount <=> ? AND id<>?", "ssi", $keyword, $amount, $id ?? -1)
            : Db::queryOne("SELECT id FROM bank_transaction_category_keyword WHERE keyword=? AND id<>?", "si", $keyword, $id ?? -1);
        if ($duplicate && $amount !== null) {
            throw new ApiError("A rule with this keyword and this amount already exists", 409, 'rule_amount_exists', ['amount' => $amount]);
        }
        if ($duplicate) {
            throw new ApiError("This keyword already exists", 409, 'keyword_exists');
        }
    }

    /**
     * Add an automatic categorization rule.
     *
     * @param string                $keyword  The text to find in the transaction labels (stored in upper case)
     * @param int                   $category The category to set
     * @param bool|int|string|null  $notify   Notify the household when a new transaction matches (optional, "true"/"false" accepted)
     * @param int|float|string|null $amount   Only the transactions of this amount, in absolute value (optional: any amount)
     * @return array<string, mixed> The rule (amount: null for any amount), with applied: the transactions of the history recategorized
     * @throws Error If the keyword is empty, the amount invalid, the rule already exists, or the category does not exist
     */
    #[ApiRoute('/category/keyword', method: 'POST', admin: true, changes: ['categories', 'transactions'])]
    public static function createKeyword(string $keyword, int $category, bool|int|string|null $notify = false,
                                         int|float|string|null $amount = null): array
    {
        [$keyword, $amount] = self::validateRule(null, $keyword, $category, $amount);

        $notify = filter_var($notify, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $stmt = $amount !== null
            ? Db::execute("INSERT INTO bank_transaction_category_keyword (keyword, category, notify, amount) VALUES (?, ?, ?, ?)",
                "siis", $keyword, $category, $notify, $amount)
            : Db::execute("INSERT INTO bank_transaction_category_keyword (keyword, category, notify) VALUES (?, ?, ?)",
                "sii", $keyword, $category, $notify);

        $id = $stmt->insert_id;
        // The rule applies at once to the history (uncategorized transactions only if set so)
        $applied = self::applyRule($keyword, $category, $amount);

        return ['id' => $id, 'keyword' => $keyword, 'category' => $category, 'notify' => $notify, 'amount' => $amount, 'applied' => $applied];
    }

    /**
     * Modify a categorization rule (administrators).
     *
     * @param int                   $id       The rule
     * @param string                $keyword  The keyword found in the labels
     * @param int                   $category The category given to the transactions
     * @param bool|int|string|null  $notify   Notify the household when a new transaction matches ("true"/"false" accepted)
     * @param int|float|string|null $amount   Only the transactions of this amount, in absolute value ('' for any amount;
     *                                        omitted or null: unchanged, so that the older apps keep the amount of a rule)
     * @return array<string, mixed> The rule (amount: null for any amount), with applied: the transactions of the history recategorized
     * @throws Error If not found, invalid or the rule already exists
     */
    #[ApiRoute('/category/keyword', method: 'PUT', admin: true, changes: ['categories', 'transactions'])]
    public static function updateKeyword(int $id, string $keyword, int $category, bool|int|string|null $notify = false,
                                         int|float|string|null $amount = null): array
    {
        $rule = Db::queryOne("SELECT " . self::amountColumn() . " FROM bank_transaction_category_keyword k WHERE k.id=?", "i", $id);
        if (!$rule) {
            throw new ApiError("Rule not found", 404, 'rule_not_found');
        }
        [$keyword, $amount] = self::validateRule($id, $keyword, $category, $amount ?? $rule['amount']);
        $notify = filter_var($notify, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        if (self::amountReady()) {
            Db::execute("UPDATE bank_transaction_category_keyword SET keyword=?, category=?, notify=?, amount=? WHERE id=?",
                "siisi", $keyword, $category, $notify, $amount, $id);
        } else {
            Db::execute("UPDATE bank_transaction_category_keyword SET keyword=?, category=?, notify=? WHERE id=?", "siii", $keyword, $category, $notify, $id);
        }
        $applied = self::applyRule($keyword, $category, $amount);

        return ['id' => $id, 'keyword' => $keyword, 'category' => $category, 'notify' => $notify, 'amount' => $amount, 'applied' => $applied];
    }

    /**
     * Number of transactions matched by a rule (see ruleCondition()) (administrators).
     *
     * @param int $id The rule
     * @return array{matching: int, categorized: int} matching (transactions), categorized (with the category of the rule)
     * @throws Error If not found
     */
    #[ApiRoute('/category/keyword/count', method: 'GET', admin: true)]
    public static function countKeyword(int $id): array
    {
        $rule = Db::queryOne("SELECT k.keyword, k.category, " . self::amountColumn() . " FROM bank_transaction_category_keyword k WHERE k.id=?", "i", $id);
        if (!$rule) {
            throw new ApiError("Rule not found", 404, 'rule_not_found');
        }
        [$where, $types, $params] = self::ruleCondition((string)$rule['keyword'], $rule['amount'] === null ? null : (string)$rule['amount']);
        $row = Db::queryOne("SELECT COUNT(*) AS matching, SUM(category = ?) AS categorized FROM bank_transaction WHERE $where",
            "i" . $types, $rule['category'], ...$params);
        return ['matching' => (int)($row['matching'] ?? 0), 'categorized' => (int)($row['categorized'] ?? 0)];
    }

    /**
     * SQL condition of the transactions (table bank_transaction, no alias) matched by a
     * rule: the label contains the keyword and, for a rule with an amount, the absolute
     * amount equals it. A rule without amount leaves the transactions matched by a rule
     * with an amount (more specific, it wins).
     *
     * @param string      $keyword The keyword (upper case)
     * @param string|null $amount  The amount of the rule (null for any amount)
     * @return array{string, string, list<string>} [condition, types, params]
     */
    private static function ruleCondition(string $keyword, ?string $amount): array
    {
        $pattern = '%' . addcslashes($keyword, '%_\\') . '%';
        if ($amount !== null) {
            return ["label LIKE ? AND ABS(amount) = ?", "ss", [$pattern, $amount]];
        }
        if (!self::amountReady()) {
            return ["label LIKE ?", "s", [$pattern]];
        }
        return ["label LIKE ? AND NOT EXISTS (SELECT 1 FROM bank_transaction_category_keyword k
                 WHERE k.amount = ABS(bank_transaction.amount) AND LOCATE(k.keyword, bank_transaction.label) > 0)", "s", [$pattern]];
    }

    /**
     * Delete an automatic categorization rule.
     *
     * @param int $id The rule id
     * @return bool True if deleted
     * @throws Error If the rule does not exist
     */
    #[ApiRoute('/category/keyword', method: 'DELETE', admin: true, changes: ['categories'])]
    public static function deleteKeyword(int $id): bool
    {
        $stmt = Db::execute("DELETE FROM bank_transaction_category_keyword WHERE id=?", "i", $id);

        if ($stmt->affected_rows === 0) {
            throw new ApiError("Rule not found", 404, 'rule_not_found');
        }

        return true;
    }

    /**
     * Settings of the rules: keep_categorized = the rules (resynchronization, new or
     * modified rule) only categorize the uncategorized transactions of the history.
     *
     * @return array{keep_categorized: bool} keep_categorized
     */
    #[ApiRoute('/category/keyword/settings', method: 'GET')]
    public static function keywordSettings(): array
    {
        return ['keep_categorized' => self::keepCategorized()];
    }

    /**
     * Change the settings of the rules (administrators).
     *
     * @param bool|int|string|null $keep_categorized True to leave the categorized transactions of the history alone (or "true", "1"...)
     * @return array{keep_categorized: bool} keep_categorized
     */
    #[ApiRoute('/category/keyword/settings', method: 'PUT', admin: true, changes: ['categories'])]
    public static function updateKeywordSettings(bool|int|string|null $keep_categorized): array
    {
        Setting::set('rules_keep_categorized', filter_var($keep_categorized, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        return ['keep_categorized' => self::keepCategorized()];
    }

    /**
     * Check if the rules leave the categorized transactions of the history alone
     * (off until an administrator turns it on: the rules apply to every transaction).
     *
     * @return bool True if only the uncategorized transactions are categorized
     */
    public static function keepCategorized(): bool
    {
        return Setting::get('rules_keep_categorized', '0') === '1';
    }

    /**
     * SQL condition (to add with AND) of the transactions whose category the rules may
     * change. A category chosen by hand is never overwritten. With keep_categorized, only
     * the uncategorized transactions and the ones categorized by a rule (not the ones of
     * unknown origin, categorized before the migration 2026-11-02_category_source).
     *
     * @return string " AND ..." or ""
     */
    private static function overwritable(): string
    {
        if (!Transaction::categorySourceReady()) {
            return self::keepCategorized() ? " AND category = 0" : "";
        }
        return self::keepCategorized()
            ? " AND (category = 0 OR category_source = '" . Transaction::SOURCE_RULE . "')"
            : " AND NOT (category_source <=> '" . Transaction::SOURCE_MANUAL . "')";
    }

    /**
     * Apply the rules to every transaction of the database (categorized ones
     * included, unless keep_categorized is on), except the ones whose category was chosen
     * by hand: the first matching rule gives its category (see find()). A split transaction
     * and its parts keep the categories of the split.
     *
     * @return array{updated: int} The number of transactions whose category changed
     */
    #[ApiRoute('/category/keyword/apply', method: 'POST', admin: true, changes: ['transactions'])]
    public static function applyKeywords(): array
    {
        $keywords = self::loadKeywords();
        $sql = "SELECT id, label, category, amount FROM bank_transaction WHERE " . Transaction::NOT_SPLIT . self::overwritable();
        $updated = 0;
        foreach (Db::select($sql, "")->fetch_all(MYSQLI_ASSOC) as $row) {
            $category = self::find($row['label'], $keywords, $row['amount']);
            if ($category !== null && (int)$category !== (int)$row['category']) {
                Db::execute("UPDATE bank_transaction SET category = ?" . Transaction::sourceSql(Transaction::SOURCE_RULE) . " WHERE id = ?",
                    "ii", $category, $row['id']);
                $updated++;
            }
        }
        return ['updated' => $updated];
    }

    /**
     * Apply one rule to every transaction it matches (see ruleCondition(); categorized
     * ones included, unless keep_categorized is on), except the ones whose category was
     * chosen by hand, a split transaction and its parts.
     *
     * @param string      $keyword  The keyword (upper case)
     * @param int         $category The category of the rule
     * @param string|null $amount   The amount of the rule (null for any amount)
     * @return int The number of transactions whose category changed
     */
    private static function applyRule(string $keyword, int $category, ?string $amount): int
    {
        [$where, $types, $params] = self::ruleCondition($keyword, $amount);
        $sql = "UPDATE bank_transaction SET category = ?" . Transaction::sourceSql(Transaction::SOURCE_RULE)
            . " WHERE $where AND category <> ? AND " . Transaction::NOT_SPLIT . self::overwritable();
        return (int)Db::execute($sql, "i{$types}i", $category, ...[...$params, $category])->affected_rows;
    }

    /**
     * Get the category of a transaction based on its label and amount.
     *
     * @param string                          $label    The label of the transaction
     * @param list<array<string, mixed>>|null $keywords The keywords already loaded (loaded when null)
     * @param int|float|string|null           $amount   The amount of the transaction (null: only the rules without amount match)
     * @return int|null The category id or null if not found
     */
    public static function find(string $label, ?array $keywords = null, int|float|string|null $amount = null): ?int
    {
        $keywords ??= self::loadKeywords();

        // The first rule matching gives the category (the rules with an amount come first)
        foreach ($keywords as $row) {
            if (self::matches($row, $label, $amount)) {
                return (int)$row["category"];
            }
        }

        return null;
    }

    /**
     * Check if a rule matches a transaction: its label contains the keyword and, for a
     * rule with an amount, its absolute amount equals it to the cent (10 matches an
     * expense of -10.00 and an income of 10.00).
     *
     * @param array<string, mixed>  $rule   The rule (keyword, amount: null or missing for any amount)
     * @param string                $label  The label of the transaction
     * @param int|float|string|null $amount The amount of the transaction (null: unknown)
     * @return bool True if the rule matches
     */
    public static function matches(array $rule, string $label, int|float|string|null $amount): bool
    {
        if (strpos($label, (string)$rule['keyword']) === false) {
            return false;
        }
        if (($rule['amount'] ?? null) === null) {
            return true;
        }
        return is_numeric($amount) && (int)round(abs((float)$amount) * 100) === (int)round((float)$rule['amount'] * 100);
    }

    /**
     * Load all the categorization keywords: the rules with an amount first (more
     * specific), then in rule creation order.
     *
     * @return list<array<string, mixed>> The rows (keyword, category, notify, amount)
     */
    public static function loadKeywords(): array
    {
        $sql = self::amountReady()
            ? "SELECT keyword, category, notify, amount FROM bank_transaction_category_keyword ORDER BY amount IS NULL, id"
            : "SELECT keyword, category, notify, NULL AS amount FROM bank_transaction_category_keyword ORDER BY id";
        return Db::select($sql, "")->fetch_all(MYSQLI_ASSOC);
    }
}
