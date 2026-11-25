<?php

/**
 * BackupData.php
 *
 * Datasets of a backup (Administration > Backup): the table and the columns of each
 * one, the check of the rows of an imported file, and the encoding of the exported
 * rows. No database access here (see Carbure\Resources\Backup).
 *
 * Format of a dataset in a backup: {"format": "carbure-backup", "version": 1,
 * "dataset": "categories", "rows": [{...}, ...]}, one object per row with the columns
 * below. The users are written by their username (their ids differ between instances).
 *
 * Never in a backup: users and passwords, API tokens, OAuth, devices, settings, bank
 * connections and accounts (their credentials live in woob), budget alerts sent, the
 * budget thresholds of the users. The insight alerts are saved (insight_alerts): those of
 * a user unknown on the instance are dropped, and an imported insight without its alerts
 * keeps the alerts of its id (those of the insights no longer there are dropped).
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Lib;

use Closure;
use DateTime;
use Error;
use InvalidArgumentException;

final class BackupData {

    /**
     * Name of the format, written in the manifest and in each dataset file
     */
    public const FORMAT = 'carbure-backup';

    /**
     * Version of the format (a later version may add datasets or columns)
     */
    public const VERSION = 1;

    /**
     * Datasets in the order of their dependencies (a dataset only refers to the ones
     * before it): table, primary key (its columns separated by commas), and type of each column.
     *
     * Types: int:<max>, string:<max length> (may be empty), name:<max length> (not
     * empty), enum:<a|b>, decimal (10,2; decimal:<n> = n digits before the point), date (Y-m-d), datetime (Y-m-d H:i:s), bool (bool:<0|1> = its value when missing),
     * user (username; none or unknown here: the administrator who imports, kept none with
     * ?user); a leading ? accepts null. drop_unknown_users: the rows of a user unknown
     * here are not imported (instead of going to the administrator who imports).
     */
    public const DATASETS = [
        'categories' => [
            'table' => 'bank_transaction_category',
            'key' => 'id',
            'columns' => [
                'id' => 'int:255', 'name' => 'name:50', 'parent_category' => 'int:255',
                'type' => 'enum:DEBIT|CREDIT|HORS-BUDGET', 'icon' => 'string:50', 'color' => 'string:50',
            ],
        ],
        'rules' => [
            'table' => 'bank_transaction_category_keyword',
            'key' => 'id',
            'columns' => ['id' => 'int:4294967295', 'keyword' => 'name:60', 'category' => 'int:255', 'notify' => 'bool',
                // Added with the migration 2026-11-05_rule_amount: any amount in an older backup
                'amount' => '?decimal'],
        ],
        'budgets' => [
            'table' => 'budget',
            'key' => 'id',
            'columns' => ['id' => 'int:' . PHP_INT_MAX, 'category' => 'int:255', 'amount' => 'decimal', 'date' => 'date',
                // Added with the migration 2026-11-06_budget_note: no note in an older backup
                'note' => '?string:200'],
        ],
        'insights' => [
            'table' => 'budget_insight',
            'key' => 'id',
            'columns' => ['id' => 'int:4294967295', 'name' => 'name:20', 'color' => 'name:20', 'icon' => '?string:50', 'sql' => 'name:500',
                // Added with the migration 2026-11-01_insight_enabled: enabled in an older backup
                'enabled' => 'bool:1',
                // Added with the migration 2026-11-07_insight_position: sorted by id in an older backup
                'position' => 'int:65535:0'],
        ],
        // Added with the migration 2026-11-03_insight_alert: the alerts of each user
        'insight_alerts' => [
            'table' => 'insight_alert',
            'key' => 'user_id,insight_id',
            'columns' => ['user_id' => 'user', 'insight_id' => 'int:4294967295', 'direction' => 'enum:above|below',
                'threshold' => 'decimal:10', 'notified_month' => '?date'],
            'drop_unknown_users' => true,
        ],
        'transactions' => [
            'table' => 'bank_transaction',
            'key' => 'id',
            'columns' => [
                'id' => 'int:' . PHP_INT_MAX, 'split_of' => '?int:' . PHP_INT_MAX, 'uuid' => 'name:32', 'imported' => 'datetime', 'date' => 'date', 'rdate' => 'date',
                'type' => 'int:255', 'label' => 'string:300', 'category' => 'int:255', 'amount' => 'decimal',
                'card' => '?string:19', 'bank_name' => '?string:50', 'account_number' => '?string:100',
                'pointed' => 'bool', 'user' => 'user',
                // Added with the migration 2026-11-02_category_source: unknown in an older backup
                'category_source' => '?enum:rule|manual',
                // Added with the migration 2026-11-19_transaction_comment: no comment in an older backup
                'comment' => '?comment:500',
            ],
        ],
        'deleted_transactions' => [
            'table' => 'bank_transaction_deleted',
            'key' => 'uuid',
            'columns' => ['uuid' => 'name:32', 'deleted_at' => 'datetime', 'user_id' => '?user'],
        ],
        'tags' => [
            'table' => 'tag',
            'key' => 'id',
            'columns' => ['id' => 'int:4294967295', 'name' => 'name:40', 'color' => 'string:30', 'start_date' => 'date',
                'end_date' => '?date', 'created' => 'datetime'],
        ],
        'transaction_tags' => [
            'table' => 'transaction_tag',
            'key' => 'transaction_id,tag_id',
            'columns' => ['transaction_id' => 'int:' . PHP_INT_MAX, 'tag_id' => 'int:4294967295'],
        ],
    ];

    /**
     * Names of the datasets, in the order of their dependencies.
     *
     * @return list<string> The names
     */
    public static function names(): array
    {
        return array_keys(self::DATASETS);
    }

    /**
     * Names of datasets asked for an export: all of them when none is given.
     *
     * @param string|null $datasets Names separated by commas (null or '' for all)
     * @return list<string> The names, in the order of their dependencies
     * @throws Error 400 If a name is unknown
     */
    public static function select(?string $datasets): array
    {
        $asked = array_filter(array_map('trim', explode(',', (string)$datasets)), fn(string $name): bool => $name !== '');
        if ($asked === []) {
            return self::names();
        }
        $unknown = array_diff($asked, self::names());
        if ($unknown !== []) {
            throw new Error("Unknown dataset: " . implode(', ', $unknown), 400);
        }
        return array_values(array_intersect(self::names(), $asked));
    }

    /**
     * Columns of the database of a dataset that hold a user id (written as usernames).
     *
     * @param string $dataset The dataset
     * @return list<string> The columns
     */
    public static function userColumns(string $dataset): array
    {
        return array_keys(array_filter(self::DATASETS[$dataset]['columns'], fn(string $type): bool => ltrim($type, '?') === 'user'));
    }

    /**
     * Encode a row read from the database for the backup: numbers as numbers, flags
     * as booleans, the text as it is (a label "2024" stays a string).
     *
     * @param string               $dataset The dataset
     * @param array<string, mixed> $row     The row, with the usernames already in place of the ids
     * @return array<string, mixed> The row of the backup
     */
    public static function encode(string $dataset, array $row): array
    {
        $encoded = [];
        foreach (self::DATASETS[$dataset]['columns'] as $column => $type) {
            $encoded[$column] = self::encodeValue(ltrim($type, '?'), $row[$column] ?? null);
        }
        return $encoded;
    }

    /**
     * Encode a value read from the database.
     *
     * @param string $type  The type of the column (without ?)
     * @param mixed  $value The value
     * @return mixed The value of the backup
     */
    private static function encodeValue(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $kind = strtok($type, ':');
        $casts = ['int' => fn($v) => (int)$v, 'decimal' => fn($v) => (float)$v, 'bool' => fn($v) => (bool)$v];
        return isset($casts[$kind]) ? $casts[$kind]($value) : (string)$value;
    }

    /**
     * Check the rows of a dataset of an imported file, before anything is deleted.
     *
     * @param string $dataset The dataset (one of DATASETS)
     * @param mixed  $rows    The rows of the file
     * @return list<list<mixed>> The values of each row, in the order of the columns
     * @throws Error 400 If the dataset is unknown or a row is invalid (its number and column in the message)
     */
    public static function validate(string $dataset, mixed $rows): array
    {
        if (!isset(self::DATASETS[$dataset])) {
            throw new Error("Unknown dataset: $dataset", 400);
        }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new Error("$dataset: a list of rows is expected", 400);
        }
        $values = [];
        foreach ($rows as $index => $row) {
            $values[] = self::validateRow($dataset, $index + 1, $row);
        }
        self::checkKeys($dataset, $values);
        return $values;
    }

    /**
     * Check a row of an imported file.
     *
     * @param string $dataset The dataset
     * @param int    $number  The number of the row (from 1), for the message
     * @param mixed  $row     The row
     * @return list<mixed> The values, in the order of the columns
     * @throws Error 400 If the row is invalid
     */
    private static function validateRow(string $dataset, int $number, mixed $row): array
    {
        if (!is_array($row)) {
            throw new Error("$dataset, row $number: an object is expected", 400);
        }
        $values = [];
        foreach (self::DATASETS[$dataset]['columns'] as $column => $type) {
            try {
                $values[] = self::value($type, $row[$column] ?? null);
            } catch (InvalidArgumentException $e) {
                throw new Error("$dataset, row $number: invalid $column (" . $e->getMessage() . ")", 400);
            }
        }
        return $values;
    }

    /**
     * Check that the primary keys of the rows are unique.
     *
     * @param string            $dataset The dataset
     * @param list<list<mixed>> $values  The values of the rows
     * @return void
     * @throws Error 400 If a key is repeated
     */
    private static function checkKeys(string $dataset, array $values): void
    {
        $columns = array_keys(self::DATASETS[$dataset]['columns']);
        $positions = array_map(fn(string $key): int => (int)array_search($key, $columns, true), explode(',', self::DATASETS[$dataset]['key']));
        $keys = array_map(fn(array $row): string => implode(',', array_map(fn(int $position): string => (string)$row[$position], $positions)), $values);
        $repeated = array_keys(array_filter(array_count_values(array_map('strval', $keys)), fn(int $count): bool => $count > 1));
        if ($repeated !== []) {
            throw new Error("$dataset: repeated " . self::DATASETS[$dataset]['key'] . ": " . implode(', ', array_slice($repeated, 0, 5)), 400);
        }
    }

    /**
     * Check a value of an imported file.
     *
     * @param string $type  The type of the column (see DATASETS)
     * @param mixed  $value The value
     * @return mixed The value to write in the database
     * @throws InvalidArgumentException If invalid
     */
    public static function value(string $type, mixed $value): mixed
    {
        if (str_starts_with($type, '?')) {
            if ($value === null) {
                return null;
            }
            $type = substr($type, 1);
        }
        [$kind, $argument] = array_pad(explode(':', $type, 2), 2, '');
        return self::checks()[$kind]($value, $argument);
    }

    /**
     * Check an integer of an imported file, or give the default of its column when it is
     * missing (column added after the backup).
     *
     * @param mixed  $value    The value
     * @param string $argument "<max>" or "<max>:<default>"
     * @return int The integer
     * @throws InvalidArgumentException If invalid
     */
    private static function integerOrDefault(mixed $value, string $argument): int
    {
        [$max, $default] = array_pad(explode(':', $argument, 2), 2, '');
        if ($value === null && $default !== '') {
            return (int)$default;
        }
        return self::integer($value, (int)$max);
    }

    /**
     * Check of a value of each type: function (value, argument of the type).
     *
     * @return array<string, Closure> The checks by kind of type
     */
    private static function checks(): array
    {
        return [
            // int:<max>[:<default>] = the default is the value of a column missing from an older backup
            'int' => fn(mixed $value, string $argument): int => self::integerOrDefault($value, $argument),
            'string' => fn(mixed $value, string $max): string => self::text($value, (int)$max, 0),
            'name' => fn(mixed $value, string $max): string => self::text($value, (int)$max, 1),
            'enum' => fn(mixed $value, string $choices): string => self::choice($value, explode('|', $choices)),
            'decimal' => fn(mixed $value, string $digits): string => self::decimal($value, $digits === '' ? 8 : (int)$digits),
            'date' => fn(mixed $value): string => self::moment($value, 'Y-m-d'),
            'datetime' => fn(mixed $value): string => self::moment($value, 'Y-m-d H:i:s'),
            // bool:<default> = the value of a column missing from an older backup
            'bool' => fn(mixed $value, string $default): int => $value === null && $default !== '' ? (int)$default : self::flag($value),
            'user' => fn(mixed $value): ?string => $value === null ? null : self::text($value, 20, 1),
            // comment:<max> = stored like the API does: trimmed, null when empty
            'comment' => fn(mixed $value, string $max): ?string => self::comment(self::text($value, (int)$max, 0)),
        ];
    }

    /**
     * A comment as the API stores it: trimmed, null when empty ("0" is a comment).
     *
     * @param string $text The text checked
     * @return string|null The comment, null for none
     */
    private static function comment(string $text): ?string
    {
        $text = trim($text);
        return $text === '' ? null : $text;
    }

    /**
     * An integer from 0 to a maximum (digits in a string accepted).
     *
     * @param mixed $value The value
     * @param int   $max   The maximum
     * @return int The integer
     * @throws InvalidArgumentException If invalid
     */
    private static function integer(mixed $value, int $max): int
    {
        if (is_string($value) && preg_match('/^[0-9]{1,18}$/', $value)) {
            $value = (int)$value;
        }
        if (!is_int($value) || $value < 0 || $value > $max) {
            throw new InvalidArgumentException("an integer from 0 to $max is expected");
        }
        return $value;
    }

    /**
     * A text of a length (numbers are taken as text).
     *
     * @param mixed $value The value
     * @param int   $max   The maximum length
     * @param int   $min   The minimum length
     * @return string The text
     * @throws InvalidArgumentException If invalid
     */
    private static function text(mixed $value, int $max, int $min): string
    {
        if (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value) < $min || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("a text of $min to $max characters is expected");
        }
        return $value;
    }

    /**
     * One of a list of values.
     *
     * @param mixed        $value   The value
     * @param list<string> $choices The values
     * @return string The value
     * @throws InvalidArgumentException If invalid
     */
    private static function choice(mixed $value, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new InvalidArgumentException("one of " . implode(', ', $choices) . " is expected");
        }
        return $value;
    }

    /**
     * An amount with 2 decimals: DECIMAL(10,2), or more digits before the point.
     *
     * @param mixed $value  The value
     * @param int   $digits The digits before the point (8 for DECIMAL(10,2))
     * @return string The amount with 2 decimals
     * @throws InvalidArgumentException If invalid
     */
    private static function decimal(mixed $value, int $digits): string
    {
        if (!(is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) || abs((float)$value) >= 10 ** $digits) {
            throw new InvalidArgumentException("an amount is expected");
        }
        return number_format((float)$value, 2, '.', '');
    }

    /**
     * A date in a format, that exists.
     *
     * @param mixed  $value  The value
     * @param string $format The format (Y-m-d or Y-m-d H:i:s)
     * @return string The date
     * @throws InvalidArgumentException If invalid
     */
    private static function moment(mixed $value, string $format): string
    {
        $date = is_string($value) ? DateTime::createFromFormat("!$format", $value) : false;
        if ($date === false || $date->format($format) !== $value) {
            throw new InvalidArgumentException("a date $format is expected");
        }
        return $value;
    }

    /**
     * A flag: true, false, 1 or 0.
     *
     * @param mixed $value The value
     * @return int 1 or 0
     * @throws InvalidArgumentException If invalid
     */
    private static function flag(mixed $value): int
    {
        if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
            throw new InvalidArgumentException("true or false is expected");
        }
        return (int)$value;
    }
}
