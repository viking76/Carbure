<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Purge;

class PurgeTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs(self::ADMIN);
        $_SERVER['REMOTE_ADDR'] = '192.168.1.20';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']);
        parent::tearDown();
    }

    private function rowCount(string $table): int
    {
        return (int)Db::queryOne("SELECT COUNT(*) AS n FROM `$table`", "")['n'];
    }

    private function purge(array $datasets): array
    {
        return json_decode($this->callRoute('POST', '/purge', ['datasets' => $datasets]), true);
    }

    public function testDatasetsWithTheirCountsAndDependents()
    {
        $purge = json_decode($this->callRoute('GET', '/purge'), true);
        $this->assertTrue($purge['local']);
        $datasets = array_column($purge['datasets'], null, 'name');
        // The default category is kept: not counted
        $this->assertSame(4, $datasets['categories']['count']);
        $this->assertSame(5, $datasets['transactions']['count']);
        $this->assertSame(['rules', 'budgets', 'transactions', 'transaction_tags'], $datasets['categories']['dependents']);
        $this->assertSame(['transaction_tags'], $datasets['transactions']['dependents']);
        $this->assertSame(['insight_alerts'], $datasets['insights']['dependents']);
        $this->assertSame([], $datasets['deleted_transactions']['dependents']);
    }

    public function testPurgeTheTransactions()
    {
        Db::query("INSERT INTO tag (id, name, start_date) VALUES (7, 'voyage', '2026-07-01')");
        Db::query("INSERT INTO transaction_tag (transaction_id, tag_id) SELECT id, 7 FROM bank_transaction WHERE uuid IN ('u1', 'u2')");
        // A split transaction: its parts refer to it
        Db::query("INSERT INTO bank_transaction (split_of, uuid, date, rdate, type, label, category, amount, user)
            SELECT id, 'part', date, rdate, type, label, 0, -40, user FROM bank_transaction WHERE uuid = 'u1'");
        Db::query("INSERT INTO bank_transaction_deleted (uuid) VALUES ('gone')");

        $this->assertSame(['purged' => ['transactions' => 6, 'transaction_tags' => 2]], $this->purge(['transaction_tags', 'transactions']));

        $this->assertSame(0, $this->rowCount('bank_transaction'));
        $this->assertSame(0, $this->rowCount('transaction_tag'));
        // The rest is kept
        $this->assertSame(1, $this->rowCount('tag'));
        $this->assertSame(1, $this->rowCount('bank_transaction_deleted'));
        $this->assertSame(5, $this->rowCount('bank_transaction_category'));
        $this->assertSame(3, $this->rowCount('bank_account'));
    }

    public function testPurgeTheCategoriesKeepsTheDefaultOne()
    {
        Db::query("INSERT INTO budget_alert (user_id, category, month, budget, spent, percent, sent_at) VALUES (1, 1, '2026-10-01', 200, 210, 105, NOW())");
        Db::query("UPDATE users SET budget_alert_category = 3 WHERE id = 1");

        $purged = $this->purge(['categories', 'rules', 'budgets', 'transactions', 'transaction_tags'])['purged'];
        $this->assertSame(['categories' => 4, 'rules' => 3, 'budgets' => 2, 'transactions' => 5, 'transaction_tags' => 0], $purged);

        $this->assertSame([['id' => '0']], Db::select("SELECT id FROM bank_transaction_category")->fetch_all(MYSQLI_ASSOC));
        // The foreign keys applied: the alerts of the categories gone, the alert category of the user none
        $this->assertSame(0, $this->rowCount('budget_alert'));
        $this->assertNull(Db::queryOne("SELECT budget_alert_category FROM users WHERE id = 1", "")['budget_alert_category']);
        $this->assertSame(1, $this->rowCount('budget_insight'));
    }

    public function testTheDependentsMustBeChosen()
    {
        try {
            $this->purge(['categories', 'rules']);
            $this->fail('Purge without the dependents accepted');
        } catch (ApiError $e) {
            $this->assertSame([400, 'purge_dependents'], [$e->getCode(), $e->getErrorCode()]);
            $this->assertSame(['dataset' => 'categories', 'dependents' => 'budgets, transactions, transaction_tags'], $e->getParams());
        }
        // Nothing deleted
        $this->assertSame(5, $this->rowCount('bank_transaction_category'));
        $this->assertSame(3, $this->rowCount('bank_transaction_category_keyword'));
    }

    public function testInvalidDatasets()
    {
        foreach ([[], ['users'], ['transactions', 'settings']] as $datasets) {
            try {
                Purge::purge($datasets);
                $this->fail('Accepted: ' . json_encode($datasets));
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
        $this->assertSame(5, $this->rowCount('bank_transaction'));
    }

    public function testRefusedFromInternet()
    {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7';
        $this->assertFalse(json_decode($this->callRoute('GET', '/purge'), true)['local']);
        try {
            $this->purge(['deleted_transactions']);
            $this->fail('Purge from Internet accepted');
        } catch (ApiError $e) {
            $this->assertSame([403, 'purge_not_local'], [$e->getCode(), $e->getErrorCode()]);
        }
    }

    public function testAdministratorsOnly()
    {
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['GET /purge', 'POST /purge']);
    }
}
