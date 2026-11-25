<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Backup;
use Carbure\Resources\Budget;
use Carbure\Resources\Insight;

class InsightTest extends DatabaseTestCase
{
    private const SQL_SLOW_MONTH = "SELECT SUM(amount) AS amount FROM bank_transaction WHERE label = 'Slow' AND MONTH(date)={month} AND YEAR(date)={year}";

    private const SQL = 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE amount > 0 AND MONTH(date)={month} AND YEAR(date)={year}';

    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs(self::ADMIN);
    }

    public function testCrud()
    {
        $this->assertCount(1, Insight::get());

        $created = Insight::create('Revenus', 'green', self::SQL . ';');
        $this->assertSame(self::SQL, $created['sql']);
        $this->assertEquals(2000, $created['amount']);
        $this->assertCount(2, Budget::getInsights());

        $updated = Insight::update($created['id'], 'Entrées', 'blue', self::SQL);
        $this->assertSame('Entrées', $updated['name']);

        $this->assertTrue(Insight::delete($created['id']));
        $this->assertCount(1, Insight::get());
    }

    public function testDisabledInsightsAreOfferedAndNotRun()
    {
        $created = Insight::create('Revenus', 'green', self::SQL, 'savings');
        $this->assertSame([], Insight::disabled());

        // Any user hides an insight for the household, then shows it again
        $this->loginAs(self::USER);
        $this->assertSame(['id' => $created['id'], 'enabled' => false], Insight::setEnabled($created['id'], 'false'));
        $this->assertNotContains('Revenus', array_column(Budget::getInsights(), 'name'));
        $this->assertArrayNotHasKey($created['id'], Budget::getInsightsHistory());
        $this->assertSame([['id' => $created['id'], 'name' => 'Revenus', 'color' => 'green', 'icon' => 'savings']], Insight::disabled());

        Insight::setEnabled((string)$created['id'], '1');
        $shown = Budget::getInsights();
        $this->assertContains('Revenus', array_column($shown, 'name'));
        // Same format as before: no enabled field
        $this->assertArrayNotHasKey('enabled', $shown[0]);
        $this->assertSame([], Insight::disabled());

        $this->loginAs(self::ADMIN);
        $this->assertSame([true, true], array_column(Insight::get(), 'enabled'));
        try {
            Insight::setEnabled(999, true);
            $this->fail('Unknown insight enabled');
        } catch (ApiError $e) {
            $this->assertSame([404, 'insight_not_found'], [$e->getCode(), $e->getErrorCode()]);
        }
    }

    public function testEnabledIsABoolean()
    {
        $created = Insight::create('Revenus', 'green', self::SQL);
        $this->assertFalse(Insight::setEnabled($created['id'], 'off')['enabled']);
        // No value: the default, shown
        $this->assertTrue(Insight::setEnabled($created['id'], null)['enabled']);
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Insight::setEnabled($created['id'], 'ture');
    }

    public function testBeforeTheMigrationEveryInsightIsShown()
    {
        Insight::create('Revenus', 'green', self::SQL);
        Db::query("ALTER TABLE budget_insight DROP COLUMN enabled");
        $ready = new ReflectionProperty(Insight::class, 'ready');
        $ready->setValue(null, null);
        try {
            $this->assertCount(2, Budget::getInsights());
            $this->assertSame([], Insight::disabled());
            $this->assertSame([true, true], array_column(Insight::get(), 'enabled'));
            // The backups work without the column: exported as null, imported shown
            $exported = json_decode(Backup::export('insights'), true)['datasets']['insights'];
            $this->assertNull($exported[0]['enabled']);
            Backup::import(['insights' => $exported]);
            $this->assertCount(2, Insight::get());
            try {
                Insight::setEnabled(1, false);
                $this->fail('Hidden before the migration');
            } catch (ApiError $e) {
                $this->assertSame([409, 'insight_migration_pending'], [$e->getCode(), $e->getErrorCode()]);
            }
        } finally {
            Db::query("ALTER TABLE budget_insight ADD COLUMN `enabled` tinyint(1) NOT NULL DEFAULT 1 AFTER `sql`");
            $ready->setValue(null, null);
        }
    }

    public function testOrderOfTheInsights()
    {
        // Sorted by id until ordered (#467)
        $second = (int)Insight::create('Revenus', 'green', self::SQL)['id'];
        $third = (int)Insight::create('Loyer', 'blue', self::SQL)['id'];
        $this->assertSame([1, $second, $third], array_map('intval', array_column(Budget::getInsights(), 'id')));

        $this->assertSame([$third, 1, $second], Insight::setOrder([$third, '1', $second]));
        $this->assertSame([$third, 1, $second], array_map('intval', array_column(Budget::getInsights(), 'id')));
        $this->assertSame([$third, 1, $second], array_map('intval', array_column(Insight::get(), 'id')));
        // The position stays internal to the order
        $this->assertArrayNotHasKey('position', Budget::getInsights()[0]);

        // The insights not listed (hidden, created meanwhile) go after the ones listed, in their order
        Insight::setEnabled(1, false);
        $fourth = (int)Insight::create('Épargne', 'teal', self::SQL)['id'];
        $this->assertSame([$second, $third, $fourth], Insight::setOrder([$second, $third]));
        Insight::setEnabled(1, true);
        $this->assertSame([$second, $third, 1, $fourth], array_map('intval', array_column(Budget::getInsights(), 'id')));
    }

    public function testInvalidOrderOfTheInsights()
    {
        foreach ([[1, 1], ['x'], [1.5], [[1]], [-1]] as $ids) {
            try {
                Insight::setOrder($ids);
                $this->fail('Order accepted: ' . json_encode($ids));
            } catch (ApiError $e) {
                $this->assertSame([400, 'insight_order_invalid'], [$e->getCode(), $e->getErrorCode()]);
            }
        }
        try {
            Insight::setOrder([1, 999]);
            $this->fail('Unknown insight ordered');
        } catch (ApiError $e) {
            $this->assertSame([404, 'insight_not_found'], [$e->getCode(), $e->getErrorCode()]);
        }
    }

    public function testAMemberOfTheHouseholdOrdersTheInsights()
    {
        // As showing or hiding them: the order is the one of the whole household
        $second = (int)Insight::create('Revenus', 'green', self::SQL)['id'];
        $this->loginAs(self::USER);
        $this->assertSame([$second, 1], Insight::setOrder([$second, 1]));
    }

    public function testBeforeTheMigrationTheInsightsAreSortedById()
    {
        $second = (int)Insight::create('Revenus', 'green', self::SQL)['id'];
        Insight::setOrder([$second, 1]);
        Db::query("ALTER TABLE budget_insight DROP COLUMN position");
        $ready = new ReflectionProperty(Insight::class, 'positionReady');
        $ready->setValue(null, null);
        try {
            $this->assertSame([1, $second], array_map('intval', array_column(Budget::getInsights(), 'id')));
            // The backups work without the column
            $exported = json_decode(Backup::export('insights'), true)['datasets']['insights'];
            Backup::import(['insights' => $exported]);
            $this->assertCount(2, Insight::get());
            try {
                Insight::setOrder([$second, 1]);
                $this->fail('Ordered before the migration');
            } catch (ApiError $e) {
                $this->assertSame([409, 'insight_migration_pending'], [$e->getCode(), $e->getErrorCode()]);
            }
        } finally {
            Db::query("ALTER TABLE budget_insight ADD COLUMN `position` smallint(5) unsigned NOT NULL DEFAULT 0 AFTER `enabled`");
            $ready->setValue(null, null);
        }
    }

    public function testIconAndCheck()
    {
        $created = Insight::create('Revenus', 'green', self::SQL, 'savings');
        $this->assertSame('savings', $created['icon']);
        $this->assertSame('savings', array_column(Insight::get(), 'icon', 'name')['Revenus']);
        $this->assertSame('savings', array_column(Budget::getInsights(), 'icon', 'name')['Revenus']);
        $this->assertNull(Insight::update($created['id'], 'Revenus', 'green', self::SQL, '')['icon']);

        $this->assertSame(['valid' => true, 'amount' => 2000.0], Insight::check(self::SQL));
        $this->assertSame(['valid' => true, 'amount' => null], Insight::check(self::SQL, 1, 2001));
        $invalid = Insight::check('SELECT SUM(amount) AS amount FROM bank_transaction WHERE');
        $this->assertFalse($invalid['valid']);
        $this->assertStringContainsString('SQL syntax', $invalid['error']);
        // The refusal of the database: its cause, for the translation of the portal
        $this->assertSame(['insight_query_failed', ['cause' => $invalid['error']]], [$invalid['error_code'], $invalid['params']]);
        $this->assertFalse(Insight::check('SELECT 1 AS amount FROM users')['valid']);

        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Insight::create('X', 'red', self::SQL, 'Bad Icon');
    }

    public function testUserCannotManageInsights()
    {
        $this->loginAs(self::USER);
        $this->assertCount(1, Budget::getInsights());
        $this->assertAdminOnly(['GET /insight', 'POST /insight', 'POST /insight/check', 'PUT /insight', 'DELETE /insight']);
    }

    public function testDangerousQueriesAreRefused()
    {
        foreach ([
            'DELETE FROM bank_transaction',
            'SELECT 1 AS amount; DROP TABLE users',
            'SELECT password AS amount FROM users',
            'SELECT COUNT(*) AS amount FROM api_tokens',
            'SELECT SLEEP(10) AS amount',
            'SELECT 1 AS amount INTO OUTFILE \'/tmp/x\'',
            'SELECT 1 AS amount -- comment',
            'SELECT 1 AS amount /* x */',
            'SELECT LOAD_FILE(\'/etc/passwd\') AS amount',
            'SELECT COUNT(*) AS amount FROM information_schema.tables',
            'SELECT SUM(amount) FROM bank_transaction',          // no amount column
            'SELECT SUM(nope) AS amount FROM bank_transaction', // fails on test
            'WITH x AS (SELECT 1) SELECT 1 AS amount',
        ] as $sql) {
            try {
                Insight::create('X', 'red', $sql);
                $this->fail("Accepted: $sql");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $sql . ': ' . $e->getMessage());
            }
        }
        $this->assertCount(1, Insight::get());
    }

    public function testInvalidFields()
    {
        foreach ([['', 'red'], [str_repeat('x', 21), 'red'], ['X', 'fuchsia']] as [$name, $color]) {
            try {
                Insight::create($name, $color, self::SQL);
                $this->fail("Accepted $name/$color");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
        $this->expectException(Error::class);
        $this->expectExceptionCode(404);
        Insight::update(999, 'X', 'red', self::SQL);
    }

    public function testFailingStoredQueryGivesZero()
    {
        // A query stored before the checks (or broken by a schema change)
        Db::query("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Cassé', 'gray', 'SELECT nope AS amount FROM nothing')");
        $insights = array_column(Budget::getInsights(), 'amount', 'name');
        $this->assertEquals(0, $insights['Cassé']);
        $this->assertNotEquals(0, $insights['Dépenses']);
    }

    public function testReadOnlyTransaction()
    {
        // Even a stored write is not executed
        Db::query("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Écriture', 'gray', 'UPDATE bank_transaction SET amount = 0')");
        Budget::getInsights();
        $this->assertNotEquals(0, Db::queryOne("SELECT SUM(ABS(amount)) AS s FROM bank_transaction", "")['s']);
    }

    public function testStoredQueryOnARefusedTableIsNotRun()
    {
        // A query stored before the allow-list (or imported in the database)
        Db::query("INSERT INTO user_mfa (user_id, secret, created_at) VALUES (1, 'JBSWY3DPEHPK3PXP', NOW())");
        Db::query("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Secret', 'gray', 'SELECT ORD(SUBSTRING(secret,1,1)) AS amount FROM user_mfa')");
        $this->assertEquals(0, array_column(Budget::getInsights(), 'amount', 'name')['Secret']);

        $check = Insight::check('SELECT EXTRACTVALUE(1,CONCAT(0x7e,(SELECT secret FROM user_mfa LIMIT 1))) AS amount');
        $this->assertFalse($check['valid']);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $check['error']);
        $this->assertStringContainsString('cannot read user_mfa', $check['error']);
        $this->assertSame(['insight_query_table_refused', ['table' => 'user_mfa']], [$check['error_code'], $check['params']]);
    }

    public function testSlowQueryIsStopped()
    {
        // 300 transactions: the cartesian product of four copies has 8 billion rows (SUM: MySQL
        // computes a COUNT(*) of a cartesian product without reading it)
        $rows = [];
        for ($i = 0; $i < 300; $i++) {
            $rows[] = [md5("slow$i"), '2020-01-01', '2020-01-01', 'Slow', -1];
        }
        Db::executeMany("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user) VALUES (?, ?, ?, 1, ?, 0, ?, 1)", "ssssd", $rows);
        $slow = 'SELECT SUM(a.amount) AS amount FROM bank_transaction a, bank_transaction b, bank_transaction c, bank_transaction d';

        $previous = Insight::$maxSeconds;
        Insight::$maxSeconds = 0.05;
        try {
            $start = microtime(true);
            $check = Insight::check($slow);
            $this->assertFalse($check['valid']);
            $this->assertSame('The query takes more than 0.05 seconds', $check['error']);
            $this->assertSame(['insight_query_timeout', ['seconds' => '0.05']], [$check['error_code'], $check['params']]);
            $this->assertLessThan(5, microtime(true) - $start);

            // In the list, a value of 0 without breaking the other insights
            Db::execute("INSERT INTO budget_insight (name, color, `sql`) VALUES ('Lent', 'gray', ?)", "s", $slow);
            $insights = array_column(Budget::getInsights(1, 2020), 'amount', 'name');
            $this->assertEquals(0, $insights['Lent']);
            $this->assertLessThanOrEqual(-300, $insights['Dépenses']);

            try {
                Insight::create('Lent', 'red', $slow);
                $this->fail('Slow query saved');
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
                $this->assertStringContainsString('more than 0.05 seconds', $e->getMessage());
                // The test before the save keeps the code of the time limit
                $this->assertInstanceOf(ApiError::class, $e);
                $this->assertSame(['insight_query_timeout', ['seconds' => '0.05']], [$e->getErrorCode(), $e->getParams()]);
            }
        } finally {
            Insight::$maxSeconds = $previous;
        }
        // The connection stays usable, with the default time limit
        $this->assertSame(['valid' => true, 'amount' => -300.0], Insight::check(self::SQL_SLOW_MONTH, 1, 2020));
    }
}
