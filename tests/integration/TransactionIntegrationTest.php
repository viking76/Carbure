<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Transaction;

class TransactionIntegrationTest extends DatabaseTestCase
{
    private function transaction($uuid)
    {
        return Db::queryOne("SELECT * FROM bank_transaction WHERE uuid=?", "s", $uuid);
    }

    public function testGetCurrentMonth()
    {
        $labels = array_column(Transaction::get(), 'label');

        $this->assertCount(4, $labels);
        $this->assertNotContains('CB SUPERMARCHE ANCIEN', $labels);
    }

    public function testGetGivenMonth()
    {
        $this->assertSame('CB SUPERMARCHE ANCIEN', Transaction::get(1, 2020)[0]['label']);
    }

    public function testGetCategoryWithSubCategories()
    {
        // Alimentation (1) includes Supermarché (2)
        $this->assertSame(['CB SUPERMARCHE'], array_column(Transaction::get(null, null, 1), 'label'));
        $this->assertSame(['PRLV SEPA EDF '], array_column(Transaction::get(null, null, 3), 'label'));
        $this->assertSame([], Transaction::get(null, null, 4 + 100));
    }

    public function testGetOnlyTheTransactionsOfTheCategoryItself()
    {
        // #492: a transaction put on the parent Alimentation (1), not on its sub-category
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user)
            VALUES ('p1', ?, ?, 7, 'CB MARCHE', 1, -15.00, '', 0, 1)", "ss", $this->day(1), $this->day(1));

        $this->assertEqualsCanonicalizing(['CB SUPERMARCHE', 'CB MARCHE'], array_column(Transaction::get(null, null, 1), 'label'));
        $this->assertSame(['CB MARCHE'], array_column(Transaction::get(null, null, 1, 'true'), 'label'));
        $this->assertSame(['CB SUPERMARCHE'], array_column(Transaction::get(null, null, 2, '1'), 'label'));
        $this->assertCount(2, Transaction::get(null, null, 1, 'false'));
    }

    public function testSearch()
    {
        $labels = array_column(Transaction::search('supermarche'), 'label');

        // All periods, most recent first
        $this->assertSame(['CB SUPERMARCHE', 'CB SUPERMARCHE ANCIEN'], $labels);
        $this->assertCount(1, Transaction::search('supermarche', 1));
    }

    public function testSearchEscapesWildcards()
    {
        $this->assertSame(['CB 100% BIO'], array_column(Transaction::search('100%'), 'label'));
        $this->assertSame([], Transaction::search('_B'));
    }

    public function testSearchTooShort()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Transaction::search(' a ');
    }

    public function testSearchByAmountWithoutSign()
    {
        // 50 to 80: the expenses of -50 and -80, not the income of 2000 nor the -10
        $this->assertSame(['PRLV SEPA EDF ', 'CB SUPERMARCHE'], $this->searchLabels(min: '50', max: '80'));
        $this->assertSame(['VIR SALAIRE'], $this->searchLabels(min: '1000,5'));
    }

    public function testSearchByKind()
    {
        $this->assertSame(['VIR SALAIRE'], $this->searchLabels(kind: 'credit'));
        $this->assertNotContains('VIR SALAIRE', $this->searchLabels(kind: 'debit'));
        $this->assertCount(4, $this->searchLabels(kind: 'debit'));
    }

    public function testSearchByCategoryWithItsSubCategories()
    {
        // Alimentation (1) includes Supermarché (2), of every period
        $this->assertSame(['CB SUPERMARCHE', 'CB SUPERMARCHE ANCIEN'], $this->searchLabels(categories: '1'));
        $this->assertSame(['PRLV SEPA EDF ', 'VIR SALAIRE'], $this->searchLabels(categories: '3, 4'));
        // The default category (0) has no sub-category: not every top-level category
        $this->assertSame(['CB 100% BIO'], $this->searchLabels(categories: '0'));
    }

    public function testSearchByAccount()
    {
        Db::query("UPDATE bank_transaction SET bank_name = 'bnp', account_number = '111' WHERE uuid IN ('u2', 'u3')");

        $this->assertSame(['PRLV SEPA EDF ', 'VIR SALAIRE'], $this->searchLabels(accounts: '111@bnp'));
        $this->assertSame([], $this->searchLabels(accounts: '111@other'));
    }

    public function testSearchByPeriodAndCombined()
    {
        $this->assertSame(['CB SUPERMARCHE ANCIEN'], $this->searchLabels(from: '2020-01-01', to: '2020-01-31'));
        $this->assertSame(['CB SUPERMARCHE'], $this->searchLabels('super', from: $this->day(1)));
        $this->assertSame(['CB SUPERMARCHE ANCIEN'], $this->searchLabels('super', max: '10', kind: 'debit'));
    }

    public function testSearchRejectsInvalidCriteria()
    {
        $cases = [
            'no criterion' => [[], 'search_too_short'],
            'empty criteria' => [['query' => ' ', 'min' => '', 'categories' => ' , '], 'search_too_short'],
            'short text with a criterion' => [['query' => 'a', 'kind' => 'debit'], 'search_too_short'],
            'negative amount' => [['min' => '-5'], 'search_amount_invalid'],
            'not an amount' => [['max' => 'abc'], 'search_amount_invalid'],
            'min above max' => [['min' => '20', 'max' => '10'], 'search_amount_invalid'],
            'invalid day' => [['from' => '2026-02-30'], 'search_date_invalid'],
            'reversed period' => [['from' => '2026-02-01', 'to' => '2026-01-01'], 'search_period_invalid'],
            'unknown kind' => [['kind' => 'all'], null],
            'invalid category' => [['categories' => '1,x'], null],
            'account without bank' => [['accounts' => '111'], null],
        ];
        foreach ($cases as $name => [$criteria, $code]) {
            try {
                Transaction::search(...$criteria);
                $this->fail("$name: no error");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $name);
                $this->assertSame($code, $e instanceof ApiError ? $e->getErrorCode() : null, $name);
            }
        }
    }

    /**
     * Labels found by a search
     *
     * @param string|null $query Text
     * @param string|null $min, $max, $kind, $categories, $accounts, $from, $to Criteria
     */
    private function searchLabels(?string $query = null, ?string $min = null, ?string $max = null, ?string $kind = null,
        ?string $categories = null, ?string $accounts = null, ?string $from = null, ?string $to = null): array
    {
        return array_column(Transaction::search($query, 100, $min, $max, $kind, $categories, $accounts, $from, $to), 'label');
    }

    public function testDelete()
    {
        $this->loginAs(self::USER);
        $id = $this->transaction('u2')['id'];

        $this->assertTrue(Transaction::delete($id));
        $this->assertNull($this->transaction('u2'));
        // Remembered, with the user who deleted it
        $deleted = Db::queryOne("SELECT user_id FROM bank_transaction_deleted WHERE uuid = ?", "s", 'u2');
        $this->assertEquals(self::USER, $deleted['user_id']);
        $this->assertNotContains('PRLV SEPA EDF ', array_column(Transaction::get(), 'label'));

        // Already deleted: unknown
        try {
            Transaction::delete($id);
            $this->fail('Deleted twice');
        } catch (Error $e) {
            $this->assertSame(404, $e->getCode());
        }
    }

    public function testPointAndUnpoint()
    {
        $id = $this->transaction('u1')['id'];

        Transaction::updatePointed($id);
        $this->assertSame(1, $this->transaction('u1')['pointed']);

        // Values coming from a query string or JSON
        Transaction::updatePointed($id, 'false');
        $this->assertSame(0, $this->transaction('u1')['pointed']);
    }

    public function testUpdateCategoryPointsTheTransaction()
    {
        $id = $this->transaction('u4')['id'];
        Transaction::updateCategory($id, 1);

        $this->assertSame(1, $this->transaction('u4')['category']);
        $this->assertSame(1, $this->transaction('u4')['pointed']);
    }

    public function testUpdateCategoriesOfASelection()
    {
        $supermarket = (int)$this->transaction('u1')['id'];
        $bio = (int)$this->transaction('u4')['id'];
        // u1 split: its part is pointed with it
        Transaction::split($supermarket, [['category' => 3, 'amount' => -30]]);

        // Ids sent as numbers or strings; u4 counted once
        $this->assertSame(['updated' => 2], Transaction::updateCategories([$supermarket, (string)$bio, $bio], 1));

        foreach (['u1', 'u4'] as $uuid) {
            $row = $this->transaction($uuid);
            $this->assertSame([1, 1, 'manual'], [$row['category'], $row['pointed'], $row['category_source']], $uuid);
        }
        $part = Db::queryOne("SELECT category, pointed FROM bank_transaction WHERE split_of = ?", "i", $supermarket);
        $this->assertSame([3, 1], [$part['category'], $part['pointed']]);
        // The others did not change
        $this->assertSame(4, $this->transaction('u3')['category']);
        // Already in the category: nothing changed
        $this->assertSame(['updated' => 0], Transaction::updateCategories([$bio], 1));

        // Back to uncategorized, without pointing (u3 stays unpointed)
        $salary = (int)$this->transaction('u3')['id'];
        $this->assertSame(['updated' => 1], Transaction::updateCategories([$salary], 0, 'false'));
        $this->assertSame([0, 0], [$this->transaction('u3')['category'], $this->transaction('u3')['pointed']]);
    }

    public function testUpdateCategoriesRejectsAnInvalidSelection()
    {
        $bio = (int)$this->transaction('u4')['id'];
        $errors = [
            'empty' => [fn() => Transaction::updateCategories([], 1), 400, 'tag_selection_limit'],
            'too large' => [fn() => Transaction::updateCategories(range(1, 501), 1), 400, 'tag_selection_limit'],
            'unknown category' => [fn() => Transaction::updateCategories([$bio], 999), 404, 'category_not_found'],
        ];
        foreach ($errors as $name => [$call, $status, $code]) {
            try {
                $call();
                $this->fail("No error: $name");
            } catch (ApiError $e) {
                $this->assertSame([$status, $code], [$e->getCode(), $e->getErrorCode()], $name);
            }
        }
        try {
            Transaction::updateCategories(['x'], 1);
            $this->fail('No error: invalid id');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        // Nothing changed
        $this->assertSame([0, 0], [$this->transaction('u4')['category'], $this->transaction('u4')['pointed']]);
    }

    public function testCountUnpointedOfTheMonth()
    {
        $this->assertSame(3, Transaction::countUnpointed());
    }

    public function testUpdateMissingCategories()
    {
        Db::query("UPDATE bank_transaction SET category=0");

        Transaction::updateMissingCategories();
        $this->assertSame(2, $this->transaction('u1')['category']);
        $this->assertSame(0, $this->transaction('u5')['category']);

        Transaction::updateMissingCategories(true);
        $this->assertSame(2, $this->transaction('u5')['category']);
        $this->assertSame(0, $this->transaction('u4')['category']);
    }

    public function testSaveIsIdempotent()
    {
        $transactions = [
            ['date' => $this->day(4), 'rdate' => $this->day(4), 'amount' => -9.99, 'raw' => "facture carte du 040126 l'epicerie carte 4974", 'type' => 7, 'card' => '4974'],
            ['date' => $this->day(27), 'amount' => 1500, 'raw' => 'VIR SALAIRE BONUS', 'type' => 1],
        ];

        ob_start();
        Transaction::save($transactions, self::USER);
        Transaction::save($transactions, self::USER);
        $progress = ob_get_clean();

        $this->assertStringContainsString("data: facture carte", $progress);
        $saved = Db::queryOne("SELECT * FROM bank_transaction WHERE label LIKE 'CB L%EPICERIE'", "");
        $this->assertSame(self::USER, $saved['user']);
        $this->assertSame(1, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label LIKE 'CB L%EPICERIE'", "")['n']);

        // Salary at the end of the month counts for the next month, real date is kept
        $salary = Db::queryOne("SELECT * FROM bank_transaction WHERE label='VIR SALAIRE BONUS'", "");
        $this->assertSame(date('Y-m-01', strtotime($this->day(27) . ' first day of next month')), $salary['date']);
        $this->assertSame($this->day(27), $salary['rdate']);
    }

    public function testSaveKeepsIdenticalTransactions()
    {
        // Two coffees on the same day: two transactions, saved again without duplicates
        $coffee = ['date' => $this->day(6), 'amount' => -2.5, 'raw' => 'PRLV CAFE DU COIN', 'type' => 2];
        $this->assertCount(2, Transaction::save([$coffee, $coffee], self::USER, false));
        $this->assertCount(0, Transaction::save([$coffee, $coffee], self::USER, false));
        // A third one received later
        $this->assertCount(1, Transaction::save([$coffee, $coffee, $coffee], self::USER, false));

        $this->assertEquals(3, Db::queryOne("SELECT COUNT(*) AS n FROM bank_transaction WHERE label = 'PRLV CAFE DU COIN'", "")['n']);
    }

    public function testRecurring()
    {
        // A subscription of the 3 previous months, not in the current month yet
        foreach ([3, 2, 1] as $ago) {
            $date = date('Y-m-d', strtotime(date('Y-m-01') . " -$ago months +9 days"));
            Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES (?, ?, ?, 7, 'CB NETFLIX.COM', 0, -13.49, '', 1, 1)",
                "sss", "netflix$ago", $date, $date);
        }

        $series = Transaction::recurring();
        $this->assertSame(['CB NETFLIX.COM'], array_column($series, 'label'));
        $this->assertSame('expected', $series[0]['status']);
        $this->assertSame(10, $series[0]['day']);

        // Received this month
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES ('netflix0', ?, ?, 7, 'CB NETFLIX.COM', 0, -13.49, '', 0, 1)",
            "ss", $this->day(10), $this->day(10));
        $id = (int)Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = 'netflix0'", "")['id'];
        $series = Transaction::recurring();
        $this->assertSame('received', $series[0]['status']);
        $this->assertSame([$id], $series[0]['ids']);

        // Looking at only 2 previous months of an older month: not enough months
        $old = strtotime(date('Y-m-01') . ' -2 months');
        $this->assertSame([], Transaction::recurring((int)date('n', $old), (int)date('Y', $old), 2));
    }

    public function testPeriods()
    {
        $periods = Transaction::periods();
        $this->assertNotEmpty($periods);
        $keys = array_map(fn($p) => $p['year'] * 100 + $p['month'], $periods);
        // Newest first, once each
        $sorted = $keys;
        rsort($sorted);
        $this->assertSame($sorted, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $count = Db::queryOne("SELECT COUNT(DISTINCT YEAR(date), MONTH(date)) AS n FROM bank_transaction", "")['n'];
        $this->assertCount((int)$count, $periods);
    }

    public function testSaveKeepsTheOriginOfTheTransaction()
    {
        $transaction = [['date' => $this->day(5), 'amount' => -12.5, 'raw' => 'PRLV SEPA ORIGINE', 'type' => 2]];

        // Saved before the origin was stored: a synchronization fills it, not a later one
        Transaction::save($transaction, self::USER, false);
        $this->assertNull(Db::queryOne("SELECT bank_name FROM bank_transaction WHERE label='PRLV SEPA ORIGINE'", "")['bank_name']);
        Transaction::save($transaction, self::USER, false, ['bank_name' => 'bnp', 'account_number' => 'DEMO0000001']);
        Transaction::save($transaction, self::USER, false, ['bank_name' => 'other', 'account_number' => '999']);

        $saved = Db::queryOne("SELECT bank_name, account_number FROM bank_transaction WHERE label='PRLV SEPA ORIGINE'", "");
        $this->assertSame(['bank_name' => 'bnp', 'account_number' => 'DEMO0000001'], $saved);
    }
}
