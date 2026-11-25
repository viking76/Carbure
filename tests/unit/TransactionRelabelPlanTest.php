<?php

use Carbure\Lib\Config;
use Carbure\Resources\TransactionRelabel;
use PHPUnit\Framework\TestCase;

/**
 * Pairing of the transactions relabeled by a fix of a woob module (#501)
 */
class TransactionRelabelPlanTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::set('woob_relabel', '');
    }

    public function testOneToOneByDateAndAmount()
    {
        $existing = [
            ['id' => 1, 'uuid' => 'old-super-u', 'rdate' => '2026-11-03', 'amount' => '-24.10'],
            ['id' => 2, 'uuid' => 'edf', 'rdate' => '2026-11-05', 'amount' => '-50.00'],
        ];
        $rows = [
            // PAIEMENT PSC 0311 became CARTE SUPER U: new UUID
            ['uuid' => 'new-super-u', 'rdate' => '2026-11-03', 'amount' => -24.1],
            // Known: nothing to do
            ['uuid' => 'edf', 'rdate' => '2026-11-05', 'amount' => -50],
            // Really new
            ['uuid' => 'bakery', 'rdate' => '2026-11-06', 'amount' => -3.2],
        ];
        $this->assertSame(['new-super-u' => 1], TransactionRelabel::plan($rows, $existing));
    }

    public function testNothingIsGuessedWhenItIsNotOneToOne()
    {
        // Two coffees of the same day saved, two new labels: which is which?
        $existing = [
            ['id' => 1, 'uuid' => 'a', 'rdate' => '2026-11-03', 'amount' => -2.5],
            ['id' => 2, 'uuid' => 'b', 'rdate' => '2026-11-03', 'amount' => -2.5],
        ];
        $rows = [
            ['uuid' => 'c', 'rdate' => '2026-11-03', 'amount' => -2.5],
            ['uuid' => 'd', 'rdate' => '2026-11-03', 'amount' => -2.5],
        ];
        $this->assertSame([], TransactionRelabel::plan($rows, $existing));

        // One saved, two received: a second real coffee, not a relabeling
        $this->assertSame([], TransactionRelabel::plan($rows, [$existing[0]]));
        // Two saved, one received: still sent by the bank or not, unknown
        $this->assertSame([], TransactionRelabel::plan([$rows[0]], $existing));
    }

    public function testASavedTransactionStillSentIsNeverTaken()
    {
        // The saved one is received again with its UUID: the new one of the same amount is another payment
        $existing = [['id' => 1, 'uuid' => 'a', 'rdate' => '2026-11-03', 'amount' => -2.5]];
        $rows = [['uuid' => 'a', 'rdate' => '2026-11-03', 'amount' => -2.5], ['uuid' => 'b', 'rdate' => '2026-11-03', 'amount' => -2.5]];
        $this->assertSame([], TransactionRelabel::plan($rows, $existing));
    }

    public function testOtherDayOrAmount()
    {
        $existing = [['id' => 1, 'uuid' => 'a', 'rdate' => '2026-11-03', 'amount' => -2.5]];
        $this->assertSame([], TransactionRelabel::plan([['uuid' => 'b', 'rdate' => '2026-11-04', 'amount' => -2.5]], $existing));
        $this->assertSame([], TransactionRelabel::plan([['uuid' => 'b', 'rdate' => '2026-11-03', 'amount' => -2.51]], $existing));
    }

    public function testAUuidAlreadyUsedElsewhereIsNeverGiven()
    {
        // The new label gives a UUID that a transaction of an imported file already has: unique, not given again
        $existing = [['id' => 1, 'uuid' => 'old', 'rdate' => '2026-11-03', 'amount' => -24.1]];
        $rows = [['uuid' => 'new', 'rdate' => '2026-11-03', 'amount' => -24.1]];
        $this->assertSame([], TransactionRelabel::plan($rows, $existing, ['new']));
        $this->assertSame(['new' => 1], TransactionRelabel::plan($rows, $existing, []));
    }

    public function testTheTypeMustBeTheSame()
    {
        // A card payment does not replace a transfer of the same amount
        $existing = [['id' => 1, 'uuid' => 'old', 'rdate' => '2026-11-03', 'amount' => -50, 'type' => 1]];
        $this->assertSame([], TransactionRelabel::plan([['uuid' => 'new', 'rdate' => '2026-11-03', 'amount' => -50, 'type' => 7]], $existing));
        $this->assertSame(['new' => 1], TransactionRelabel::plan([['uuid' => 'new', 'rdate' => '2026-11-03', 'amount' => -50, 'type' => 1]], $existing));
    }

    public function testOffByDefaultAndOnlyForASynchronizedAccount()
    {
        Config::set('woob_relabel', '');
        $this->assertFalse(TransactionRelabel::enabled(['bank_name' => 'creditmutuel', 'account_number' => '1']));
        Config::set('woob_relabel', '1');
        $this->assertTrue(TransactionRelabel::enabled(['bank_name' => 'creditmutuel', 'account_number' => '1']));
        // An imported file has no bank_name
        $this->assertFalse(TransactionRelabel::enabled(['account_number' => '1']));
        $this->assertSame([], TransactionRelabel::apply([], 1, ['account_number' => '1']));
    }
}
