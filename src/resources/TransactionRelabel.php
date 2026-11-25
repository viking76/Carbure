<?php

/**
 * TransactionRelabel.php
 *
 * Relabeling of the synchronized transactions (#501), opt-in with the setting
 * woob_relabel (off by default). The UUID of a transaction is md5(rdate . label . amount):
 * when a woob module changes how it labels the transactions (a fix of the module), the
 * next synchronization no longer recognizes the transactions already saved and saves them
 * again. With the setting, a transaction received without a known UUID takes the place of
 * the one transaction of the same account, real date and amount that the bank no longer
 * sends: it gets the new label and UUID, and keeps its category, tags, pointing and notes.
 * When it is not one to one (two payments of the same amount the same day), nothing is
 * guessed: the transaction is saved as before.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\Config;
use Carbure\Lib\Db;
use Carbure\Lib\Logger;

final class TransactionRelabel {

    /**
     * Whether the transactions of a synchronization may be relabeled: the setting is on and
     * the account is a synchronized one (Transaction::save(), never called for a file).
     *
     * @param array<string, string|null> $origin bank_name and account_number of the account
     * @return bool
     */
    public static function enabled(array $origin): bool
    {
        return Config::has('woob_relabel') && !empty($origin['bank_name']);
    }

    /**
     * Pairs the transactions received whose UUID is unknown with the transactions saved
     * that the synchronization no longer sends, by real date, amount and type, one to one only.
     *
     * @param list<array<string, mixed>> $rows     The transactions received {uuid, rdate, amount, type}
     * @param list<array<string, mixed>> $existing The transactions of the account over the same days {id, uuid, rdate, amount, type}
     * @param list<string>               $taken    UUIDs received already used by another transaction (another
     *                                             account, an imported file): never given again (the UUID is unique)
     * @return array<string, int> The UUIDs received relabeling a saved transaction => its id
     */
    public static function plan(array $rows, array $existing, array $taken = []): array
    {
        $received = array_flip(array_map('strval', array_column($rows, 'uuid')));
        $known = array_flip(array_map('strval', array_merge(array_column($existing, 'uuid'), $taken)));

        $new = self::byDay(array_filter($rows, fn($row) => !isset($known[(string)$row['uuid']])));
        $gone = self::byDay(array_filter($existing, fn($row) => !isset($received[(string)$row['uuid']])));

        $plan = [];
        foreach ($new as $key => $candidates) {
            if (count($candidates) === 1 && count($gone[$key] ?? []) === 1) {
                $plan[(string)$candidates[0]['uuid']] = (int)$gone[$key][0]['id'];
            }
        }
        return $plan;
    }

    /**
     * Transactions grouped by real date and amount.
     *
     * @param array<array<string, mixed>> $rows The transactions {rdate, amount}
     * @return array<string, list<array<string, mixed>>>
     */
    private static function byDay(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row['rdate'] . '|' . number_format((float)$row['amount'], 2, '.', '') . '|' . (int)($row['type'] ?? 0)][] = $row;
        }
        return $groups;
    }

    /**
     * Relabels the saved transactions that the transactions received replace (see plan()).
     *
     * @param list<array<string, mixed>> $rows   The transactions received, as returned by Transaction::prepareAll()
     * @param int                        $userId The user owning the transactions
     * @param array<string, string|null> $origin bank_name and account_number of the account
     * @return array<string, true> The UUIDs received that relabeled a transaction (not to save again)
     */
    public static function apply(array $rows, int $userId, array $origin): array
    {
        $days = array_column($rows, 'rdate');
        if (!self::enabled($origin) || $days === []) {
            return [];
        }
        // Entered by hand on the account: never sent by the bank, never relabeled
        $existing = Db::select("SELECT t.id, t.uuid, t.rdate, t.type, " . Transaction::BANK_AMOUNT . " AS amount FROM bank_transaction t
            WHERE t.split_of IS NULL AND t.user = ? AND t.bank_name = ? AND t.account_number <=> ? AND t.rdate BETWEEN ? AND ?
            AND t.uuid NOT LIKE ?", "isssss", $userId, $origin['bank_name'], $origin['account_number'] ?? null, min($days), max($days),
            Transaction::MANUAL_UUID . '%')->fetch_all(MYSQLI_ASSOC);

        $byUuid = array_column($rows, null, 'uuid');
        $done = [];
        foreach (self::plan($rows, $existing, self::taken(array_keys($byUuid))) as $uuid => $id) {
            $row = $byUuid[$uuid];
            Db::execute("UPDATE bank_transaction SET label = ?, uuid = ?, card = ? WHERE id = ?", "sssi",
                $row['label'], $uuid, $row['card'] ?? '', $id);
            Db::execute("UPDATE bank_transaction SET label = ? WHERE split_of = ?", "si", $row['label'], $id);
            $done[$uuid] = true;
        }
        if ($done !== []) {
            Changes::touch('transactions');
            Logger::info(count($done) . " transaction(s) of " . $origin['bank_name'] . " relabeled (woob_relabel)");
        }
        return $done;
    }

    /**
     * UUIDs received that a transaction already has, wherever it is (another account, a file).
     *
     * @param list<int|string> $uuids The UUIDs received
     * @return list<string> Those already used
     */
    private static function taken(array $uuids): array
    {
        $taken = [];
        foreach (array_chunk(array_map('strval', $uuids), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Db::select("SELECT uuid FROM bank_transaction WHERE uuid IN ($marks)", str_repeat('s', count($chunk)), ...$chunk);
            array_push($taken, ...array_map('strval', array_column($rows->fetch_all(MYSQLI_ASSOC), 'uuid')));
        }
        return $taken;
    }
}
