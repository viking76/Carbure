<?php

namespace Carbure\Lib;

use Carbure\Resources\Transaction;
use DateTime;
use Error;

/**
 * Criteria of a search of transactions (GET /transaction/search), turned into the WHERE
 * clause of a prepared statement on bank_transaction (alias b).
 */
final class TransactionSearch
{
    /** Most categories or accounts in one search */
    private const MAX_LIST = 100;

    /** @var list<string> Conditions, joined with AND */
    private array $conditions = [];

    /** @var string Types of the parameters (Db::execute) */
    private string $types = '';

    /** @var list<int|float|string> Parameters of the conditions */
    private array $params = [];

    /**
     * Reads the criteria; each one is optional, at least one is needed.
     *
     * @param string|null $query      Text found in the label or the comment (2 characters minimum), or #tag
     * @param mixed       $min        Smallest amount, without sign
     * @param mixed       $max        Largest amount, without sign
     * @param string|null $kind       "debit" (expenses) or "credit" (incomes)
     * @param string|null $categories Ids of categories, comma-separated (their sub-categories included)
     * @param string|null $accounts   Accounts, comma-separated bankIds (account_number@bank_name)
     * @param string|null $from       First day (YYYY-MM-DD)
     * @param string|null $to         Last day (YYYY-MM-DD)
     * @param bool        $labelOnly  The text is searched in the label only, not in the comment
     *                                (the transactions similar to one: same merchant)
     * @throws Error If a criterion is invalid, or none is given (400)
     */
    public function __construct(?string $query, mixed $min, mixed $max, ?string $kind,
        ?string $categories, ?string $accounts, ?string $from, ?string $to, bool $labelOnly = false)
    {
        $this->addQuery(trim((string)$query), $labelOnly);
        $this->addAmounts(self::amount($min), self::amount($max));
        $this->addKind((string)$kind);
        $this->addCategories(self::list($categories));
        $this->addAccounts(self::list($accounts));
        $this->addPeriod(self::date($from), self::date($to));
        if ($this->conditions === []) {
            throw new ApiError("Search query must contain at least 2 characters", 400, 'search_too_short');
        }
    }

    /**
     * The WHERE clause, its parameter types and its parameters.
     *
     * @return array{0: string, 1: string, 2: list<int|float|string>}
     */
    public function where(): array
    {
        return [implode(' AND ', $this->conditions), $this->types, $this->params];
    }

    /**
     * Adds a condition with its parameters.
     *
     * @param string                 $condition SQL condition, with ? placeholders
     * @param string                 $types     Types of the parameters
     * @param int|float|string       ...$params Parameters
     */
    private function add(string $condition, string $types = '', int|float|string ...$params): void
    {
        $this->conditions[] = $condition;
        $this->types .= $types;
        array_push($this->params, ...$params);
    }

    /**
     * Text in the label (stored in upper case, LIKE wildcards escaped) or in the comment (once
     * the migration 2026-11-19_transaction_comment is applied; case-insensitive collation),
     * or the tag of this name.
     *
     * @param string $query     Trimmed text ('' when none)
     * @param bool   $labelOnly In the label only, not in the comment
     * @throws Error If it is shorter than 2 characters (400)
     */
    private function addQuery(string $query, bool $labelOnly): void
    {
        if ($query === '') {
            return;
        }
        if (mb_strlen($query) < 2) {
            throw new ApiError("Search query must contain at least 2 characters", 400, 'search_too_short');
        }
        if (str_starts_with($query, '#')) {
            $this->add("b.id IN (SELECT tt.transaction_id FROM transaction_tag tt JOIN tag g ON g.id = tt.tag_id WHERE g.name = ?)",
                's', mb_strtolower(ltrim($query, '#')));
            return;
        }
        $like = '%' . addcslashes(strtoupper($query), '%_\\') . '%';
        if (!$labelOnly && Transaction::commentReady()) {
            $this->add("(b.label LIKE ? OR b.comment LIKE ?)", 'ss', $like, $like);
            return;
        }
        $this->add("b.label LIKE ?", 's', $like);
    }

    /**
     * Bounds of the amount, without sign: 50 finds an expense of -50 and an income of 50.
     *
     * @param float|null $min Smallest amount
     * @param float|null $max Largest amount
     * @throws Error If the smallest is above the largest (400)
     */
    private function addAmounts(?float $min, ?float $max): void
    {
        if ($min !== null && $max !== null && $min > $max) {
            throw new ApiError("The smallest amount is above the largest", 400, 'search_amount_invalid');
        }
        if ($min !== null) {
            $this->add("ABS(b.amount) >= ?", 'd', $min);
        }
        if ($max !== null) {
            $this->add("ABS(b.amount) <= ?", 'd', $max);
        }
    }

    /**
     * Expenses or incomes only.
     *
     * @param string $kind "debit", "credit" or '' for both
     * @throws Error If it is something else (400)
     */
    private function addKind(string $kind): void
    {
        $conditions = ['' => null, 'debit' => "b.amount < 0", 'credit' => "b.amount > 0"];
        if (!array_key_exists($kind, $conditions)) {
            throw new Error("Invalid kind: debit or credit", 400);
        }
        if ($conditions[$kind] !== null) {
            $this->add($conditions[$kind]);
        }
    }

    /**
     * Categories, with their sub-categories (the default category 0 has none).
     *
     * @param list<string> $ids Ids of categories
     * @throws Error If one is not a number (400)
     */
    private function addCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        foreach ($ids as $id) {
            if (!ctype_digit($id)) {
                throw new Error("Invalid category: $id", 400);
            }
        }
        $ids = array_map('intval', $ids);
        $parents = array_values(array_filter($ids, fn($id) => $id > 0)) ?: [-1];
        $this->add("(b.category IN (" . self::marks($ids) . ") OR b.category IN (SELECT c.id FROM bank_transaction_category c WHERE c.parent_category IN ("
            . self::marks($parents) . ")))", str_repeat('i', count($ids) + count($parents)), ...$ids, ...$parents);
    }

    /**
     * Accounts, given as bankIds (account_number@bank_name, as GET /bank lists them).
     *
     * @param list<string> $bankIds BankIds
     * @throws Error If one has no bank (400)
     */
    private function addAccounts(array $bankIds): void
    {
        if ($bankIds === []) {
            return;
        }
        $conditions = [];
        $params = [];
        foreach ($bankIds as $bankId) {
            $at = strrpos($bankId, '@');
            if ($at === false || $at === 0) {
                throw new Error("Invalid account: account_number@bank_name", 400);
            }
            $conditions[] = "(b.account_number = ? AND b.bank_name = ?)";
            array_push($params, substr($bankId, 0, $at), substr($bankId, $at + 1));
        }
        $this->add("(" . implode(' OR ', $conditions) . ")", str_repeat('s', count($params)), ...$params);
    }

    /**
     * Period, both days included.
     *
     * @param string|null $from First day
     * @param string|null $to   Last day
     * @throws Error If the first day is after the last one (400)
     */
    private function addPeriod(?string $from, ?string $to): void
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new ApiError("The first day is after the last one", 400, 'search_period_invalid');
        }
        if ($from !== null) {
            $this->add("b.date >= ?", 's', $from);
        }
        if ($to !== null) {
            $this->add("b.date <= ?", 's', $to);
        }
    }

    /**
     * An amount without sign ('' or null: none).
     *
     * @param mixed $value Amount, a comma as decimal separator accepted
     * @return float|null
     * @throws Error If it is not a positive number (400)
     */
    private static function amount(mixed $value): ?float
    {
        $value = str_replace(',', '.', trim((string)(is_scalar($value) ? $value : '')));
        if ($value === '') {
            return null;
        }
        if (!is_numeric($value) || (float)$value < 0) {
            throw new ApiError("Invalid amount", 400, 'search_amount_invalid');
        }
        return (float)$value;
    }

    /**
     * A day YYYY-MM-DD ('' or null: none).
     *
     * @param string|null $value Day
     * @return string|null
     * @throws Error If it is not a valid day (400)
     */
    private static function date(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        $parsed = DateTime::createFromFormat('!Y-m-d', $value);
        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            throw new ApiError("Invalid date (YYYY-MM-DD)", 400, 'search_date_invalid');
        }
        return $value;
    }

    /**
     * Values of a comma-separated list, without the empty ones.
     *
     * @param string|null $value List
     * @return list<string>
     * @throws Error If it has too many values (400)
     */
    private static function list(?string $value): array
    {
        $values = array_values(array_filter(array_map('trim', explode(',', (string)$value)), fn($v) => $v !== ''));
        if (count($values) > self::MAX_LIST) {
            throw new Error("At most " . self::MAX_LIST . " categories or accounts", 400);
        }
        return $values;
    }

    /**
     * Placeholders of a list: "?, ?, ?".
     *
     * @param list<int> $values Values
     * @return string
     */
    private static function marks(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
