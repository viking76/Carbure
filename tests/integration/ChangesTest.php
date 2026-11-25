<?php

use Carbure\Lib\Db;
use Carbure\Lib\File;
use Carbure\Resources\Bank;
use Carbure\Resources\Changes;
use Carbure\Resources\Transaction;

class ChangesTest extends DatabaseTestCase
{
    /**
     * The versions of GET /changes, by kind.
     *
     * @return array<string, int>
     */
    private function versions()
    {
        return array_map(fn($change) => $change['version'], json_decode($this->callRoute('GET', '/changes'), true));
    }

    /**
     * The kinds whose version moved since $before.
     *
     * @return list<string>
     */
    private function moved(array $before)
    {
        return array_keys(array_diff_assoc($this->versions(), $before));
    }

    public function testEveryKindIsListedFromVersionZero()
    {
        $this->loginAs(self::USER);
        $changes = json_decode($this->callRoute('GET', '/changes'), true);

        $this->assertSame(Changes::KINDS, array_keys($changes));
        $this->assertSame(['version' => 0, 'updated_at' => null], $changes['budgets']);
    }

    public function testTheMarkersAreReservedToTheUsers()
    {
        try {
            $this->callRoute('GET', '/changes');
            $this->fail('Change markers given without a session');
        } catch (Error $e) {
            $this->assertSame(401, $e->getCode());
        }
    }

    public function testAWriteBumpsTheKindsOfItsRoute()
    {
        $this->loginAs(self::ADMIN);
        $before = $this->versions();

        $this->callRoute('POST', '/budget', ['category' => 3, 'amount' => 120, 'month' => (int)date('n'), 'year' => (int)date('Y')]);
        $this->assertSame(['budgets'], $this->moved($before));
        $this->callRoute('POST', '/budget', ['category' => 3, 'amount' => 130, 'month' => (int)date('n'), 'year' => (int)date('Y')]);
        $this->assertSame(2, $this->versions()['budgets']);

        // Another user sees the change of the household
        $this->loginAs(self::USER);
        $changes = json_decode($this->callRoute('GET', '/changes'), true);
        $this->assertSame(2, $changes['budgets']['version']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/', $changes['budgets']['updated_at']);
    }

    public function testADeletedCategoryBumpsWhatItTouched()
    {
        $this->loginAs(self::ADMIN);
        $before = $this->versions();

        $this->callRoute('DELETE', '/category', ['id' => 3]);
        $this->assertEqualsCanonicalizing(['budgets', 'categories', 'transactions'], $this->moved($before));
    }

    public function testTheChartsOfTheTrendsBumpTheSettings()
    {
        $this->loginAs(self::ADMIN);
        $before = $this->versions();

        $this->callRoute('PUT', '/budget/trends/charts', ['charts' => [['type' => 'category', 'id' => 3]]]);
        $this->assertSame(['settings'], $this->moved($before));
    }

    public function testEachKindIsBumped()
    {
        $this->loginAs(self::ADMIN);
        $id = (int)Db::queryOne("SELECT id FROM bank_transaction WHERE uuid = 'u4'", "")['id'];
        $writes = [
            'transactions' => ['PUT', '/transaction/pointed', ['id' => $id, 'pointed' => 'true']],
            'accounts' => ['POST', '/bank', ['account_number' => '222', 'bank_name' => 'bnp']],
            'categories' => ['PUT', '/category/keyword/settings', ['keep_categorized' => 'true']],
            'insights' => ['POST', '/insight', ['name' => 'Revenus', 'color' => 'green',
                'sql' => 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE amount > 0']],
            'tags' => ['POST', '/tag', ['name' => '#voyage', 'start_date' => '2026-10-01']],
        ];
        foreach ($writes as $kind => [$method, $path, $params]) {
            $before = $this->versions();
            $this->callRoute($method, $path, $params);
            $this->assertSame([$kind], $this->moved($before), "$method $path");
        }
    }

    public function testAFailedWriteBumpsNothing()
    {
        $this->loginAs(self::ADMIN);
        $before = $this->versions();

        try {
            // Unknown category
            $this->callRoute('POST', '/budget', ['category' => 99, 'amount' => 10, 'month' => 1, 'year' => 2026]);
            $this->fail('Budget of an unknown category saved');
        } catch (Throwable $e) {
            $this->assertSame([], $this->moved($before));
        }
    }

    public function testAWriteThatChangesNothingBumpsNothing()
    {
        $this->loginAs(self::ADMIN);
        $before = $this->versions();

        $this->callRoute('PUT', '/user', ['id' => self::ADMIN, 'firstname' => 'Ada']);
        $this->assertSame([], $this->moved($before));
    }

    public function testTransactionsAreBumpedOnlyWhenTheyMoved()
    {
        $this->loginAs(self::ADMIN);
        $transaction = ['type' => 7, 'raw' => 'CB BOULANGERIE', 'amount' => -4.5, 'date' => $this->day(4)];
        $origin = ['bank_name' => 'bnp', 'account_number' => '111'];

        $before = $this->versions();
        $this->assertCount(1, Transaction::save([$transaction], self::ADMIN, false, $origin));
        Changes::flush();
        $this->assertSame(['transactions'], $this->moved($before));

        // Received again by the next synchronization, unchanged
        $before = $this->versions();
        $this->assertCount(0, Transaction::save([$transaction], self::ADMIN, false, $origin));
        Changes::flush();
        $this->assertSame([], $this->moved($before));
    }

    public function testAnUnchangedSynchronizationWakesNoDevice()
    {
        $this->loginAs(self::ADMIN);
        $sync = function () {
            ob_start();
            try {
                Bank::getSync(null, '111@bnp');
            } finally {
                ob_end_clean();
            }
        };

        $before = $this->versions();
        $sync();
        $this->assertEqualsCanonicalizing(['accounts', 'transactions'], $this->moved($before));

        // The same transactions, the same result
        $before = $this->versions();
        $sync();
        $this->assertSame([], $this->moved($before));
    }

    public function testTheMigrationCreatesTheTable()
    {
        Db::query("DROP TABLE data_change");
        try {
            $this->loginAs(self::ADMIN);
            // Before the migration: the write succeeds, its markers are only missing
            $this->callRoute('POST', '/budget', ['category' => 3, 'amount' => 120, 'month' => (int)date('n'), 'year' => (int)date('Y')]);
        } finally {
            Db::query(File::read(dirname(__DIR__, 2) . '/sql/migrations/2026-10-29_data_change.sql'));
        }
        $this->assertSame(0, $this->versions()['budgets']);
        $this->callRoute('POST', '/budget/copy', ['month' => (int)date('n'), 'year' => (int)date('Y')]);
        $this->assertSame(1, $this->versions()['budgets']);
    }
}
