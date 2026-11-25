<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Transaction;

/**
 * Date a transaction is counted on, changed by a user (PUT /transaction/date, #427)
 */
class TransactionDateTest extends DatabaseTestCase
{
    private function row($uuid)
    {
        return Db::queryOne("SELECT * FROM bank_transaction WHERE uuid = ?", "s", $uuid);
    }

    private function assertApiError($code, callable $call)
    {
        try {
            $call();
            $this->fail("No error $code");
        } catch (ApiError $e) {
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    /**
     * Last day of the previous month (Y-m-d).
     */
    private function previousMonth()
    {
        return date('Y-m-d', strtotime($this->day(0) . ' -1 day'));
    }

    public function testMovesASynchronizedTransactionToThePreviousMonth()
    {
        $this->loginAs(self::USER);
        $u1 = $this->row('u1');

        $saved = Transaction::updateDate($u1['id'], $this->previousMonth());

        // Counted in the previous month; the real date of the bank and the UUID are kept
        $this->assertSame([$this->previousMonth(), $this->day(1), 'u1'], [$saved['date'], $saved['rdate'], $saved['uuid']]);
        $this->assertArrayHasKey('tags', $saved);
        $this->assertNotContains('CB SUPERMARCHE', array_column(Transaction::get(), 'label'));
        $previous = strtotime($this->previousMonth());
        $this->assertContains('CB SUPERMARCHE', array_column(Transaction::get((int)date('n', $previous), (int)date('Y', $previous)), 'label'));
        // Its other values do not change
        $this->assertSame([$u1['label'], $u1['amount'], $u1['category'], $u1['pointed']],
            [$saved['label'], $saved['amount'], $saved['category'], $saved['pointed']]);
    }

    public function testTheRouteBumpsTheTransactionsMarker()
    {
        $this->loginAs(self::USER);
        $id = $this->row('u4')['id'];

        $saved = json_decode($this->callRoute('PUT', '/transaction/date', ['id' => $id, 'date' => $this->day(10)]), true);

        $this->assertSame($this->day(10), $saved['date']);
        $this->assertSame(1, json_decode($this->callRoute('GET', '/changes'), true)['transactions']['version']);
    }

    public function testRefusesAnInvalidDateOrAnUnknownTransaction()
    {
        $this->loginAs(self::USER);
        $id = $this->row('u1')['id'];
        foreach (['2026-02-30', '2026-9-30', '30/09/2026', '', 'yesterday'] as $date) {
            $this->assertApiError('transaction_date_invalid', fn() => Transaction::updateDate($id, $date));
        }
        $this->assertSame($this->day(1), $this->row('u1')['date']);
        $this->assertApiError('transaction_not_found', fn() => Transaction::updateDate(999999, $this->day(1)));
    }

    public function testTheSynchronizationKeepsTheDateChosen()
    {
        $this->loginAs(self::USER);
        // A salary of the end of the month: counted in the next month by the synchronization
        $salary = ['date' => $this->day(27), 'amount' => 1500, 'raw' => 'VIR SALAIRE BONUS', 'type' => 1];
        $origin = ['bank_name' => 'bnp', 'account_number' => '111'];
        $this->assertCount(1, Transaction::save([$salary], self::USER, false, $origin));
        $row = Db::queryOne("SELECT * FROM bank_transaction WHERE label = 'VIR SALAIRE BONUS'", "");
        $this->assertNotSame(substr($this->day(0), 0, 7), substr($row['date'], 0, 7));

        // Counted in the month it was received by the user, then synchronized again
        Transaction::updateDate($row['id'], $this->day(27));
        $this->assertCount(0, Transaction::save([$salary], self::USER, false, $origin));
        // With a new transaction of the account: only that one is created
        $created = Transaction::save([$salary, ['date' => $this->day(5), 'amount' => -3, 'raw' => 'CB CAFE', 'type' => 7]],
            self::USER, false, $origin);
        $this->assertSame(['CB CAFE'], array_column($created, 'label'));

        $again = Db::queryOne("SELECT * FROM bank_transaction WHERE label = 'VIR SALAIRE BONUS'", "");
        $this->assertSame([$row['id'], $row['uuid'], $this->day(27), $this->day(27)], [$again['id'], $again['uuid'], $again['date'], $again['rdate']]);
        $this->assertEquals(1, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label = 'VIR SALAIRE BONUS'", "")['n']);
    }

    public function testAnImportKeepsTheDateChosen()
    {
        $this->loginAs(self::USER);
        $csv = base64_encode("Date;Libellé;Montant\n" . date('d/m/Y', strtotime($this->day(5))) . ";CB LIBRAIRIE;-12,34\n");
        $this->assertSame(1, Transaction::import($csv, 'releve.csv')['imported']);
        $row = Db::queryOne("SELECT * FROM bank_transaction WHERE label = 'CB LIBRAIRIE'", "");

        Transaction::updateDate($row['id'], $this->previousMonth());

        // Still recognized by its UUID (real date unchanged): known, not imported again
        $this->assertSame(['new' => 0, 'known' => 1, 'duplicate' => 0], Transaction::previewImport($csv, 'releve.csv')['counts']);
        $this->assertSame(0, Transaction::import($csv, 'releve.csv')['imported']);
        $this->assertSame(0, Transaction::import($csv, 'releve.csv', [0])['imported']);
        $again = Db::queryOne("SELECT date, rdate FROM bank_transaction WHERE label = 'CB LIBRAIRIE'", "");
        $this->assertSame([$this->previousMonth(), $this->day(5)], [$again['date'], $again['rdate']]);
    }

    public function testTheSplitTransactionAndItsPartsMoveTogether()
    {
        $this->loginAs(self::USER);
        $id = (int)$this->row('u1')['id'];
        $rows = Transaction::split($id, [['category' => 1, 'amount' => '-30'], ['category' => 3, 'amount' => '-20']]);
        $part = (int)$rows[1]['id'];
        $dates = fn() => array_column(Db::select("SELECT date FROM bank_transaction WHERE id = ? OR split_of = ? ORDER BY id",
            "ii", $id, $id)->fetch_all(MYSQLI_ASSOC), 'date');

        // From the transaction
        Transaction::updateDate($id, $this->day(4));
        $this->assertSame(array_fill(0, 3, $this->day(4)), $dates());

        // From a part: the part given is returned, its transaction and the other part follow
        $saved = Transaction::updateDate($part, $this->previousMonth());
        $this->assertSame([$part, $id], [$saved['id'], $saved['split_of']]);
        $this->assertSame(array_fill(0, 3, $this->previousMonth()), $dates());
        // Real dates untouched
        $this->assertEquals(3, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE (id = ? OR split_of = ?) AND rdate = ?",
            "iis", $id, $id, $this->day(1))['n']);
        // Another transaction does not move
        $this->assertSame($this->day(2), $this->row('u2')['date']);
    }

    public function testMovedIntoTheCurrentMonthChecksTheBudgets()
    {
        $this->loginAs(self::USER);
        $old = $this->row('u5');

        // 2020 -> current month: counted in the totals of the month
        $before = array_sum(array_column(Transaction::get(), 'amount'));
        Transaction::updateDate($old['id'], $this->day(5));
        $this->assertEqualsWithDelta($before - 5, array_sum(array_column(Transaction::get(), 'amount')), 0.001);
        $this->assertSame([], Transaction::get(1, 2020));
    }
}
