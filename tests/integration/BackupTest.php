<?php

use Carbure\Lib\Db;
use Carbure\Resources\Backup;
use Carbure\Resources\Category;
use Carbure\Resources\InsightAlert;

class BackupTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs(self::ADMIN);
    }

    /**
     * Export through the route (raw JSON), decoded.
     *
     * @return array<string, mixed>
     */
    private function export(?string $datasets = null): array
    {
        return json_decode($this->callRoute('GET', '/backup/export', $datasets === null ? [] : ['datasets' => $datasets]), true);
    }

    private function rowCount(string $table): int
    {
        return (int)Db::queryOne("SELECT COUNT(*) AS n FROM `$table`", "")['n'];
    }

    public function testDatasetsWithTheirCounts()
    {
        $counts = array_column(Backup::get(), 'count', 'name');
        $this->assertSame(['categories' => 5, 'rules' => 3, 'budgets' => 2, 'insights' => 1, 'insight_alerts' => 0, 'transactions' => 5,
            'deleted_transactions' => 0, 'tags' => 0, 'transaction_tags' => 0], $counts);
    }

    public function testExport()
    {
        $backup = $this->export('transactions,categories');
        $this->assertSame('carbure-backup', $backup['format']);
        $this->assertSame(1, $backup['version']);
        $this->assertSame(['categories', 'transactions'], array_keys($backup['datasets']));
        $this->assertSame(['categories' => 5, 'transactions' => 5], $backup['counts']);
        $this->assertNotEmpty($backup['schema']);

        $transaction = $backup['datasets']['transactions'][0];
        $this->assertSame('u1', $transaction['uuid']);
        // The owner by username, the amount as a number
        $this->assertSame('admin', $transaction['user']);
        $this->assertSame(-80.0, $transaction['amount']);
        $this->assertFalse($transaction['pointed']);
        $this->assertSame(['id' => 0, 'name' => 'Aucune', 'parent_category' => 0, 'type' => 'HORS-BUDGET', 'icon' => 'help', 'color' => 'grey'],
            $backup['datasets']['categories'][0]);

        // Nothing secret
        $all = $this->callRoute('GET', '/backup/export');
        $this->assertStringNotContainsString(self::DEVICE_TOKEN, $all);
        $this->assertStringNotContainsString('$2y$', $all);
    }

    public function testExportThenImportRestoresTheData()
    {
        // Deleted transactions of a user, and of a user since removed (no user)
        Db::query("INSERT INTO bank_transaction_deleted (uuid, deleted_at, user_id) VALUES ('d1', '2026-01-02 03:04:05', 2), ('d2', '2026-01-03 00:00:00', NULL)");
        // A tag of a project and its transactions
        Db::query("INSERT INTO tag (id, name, color, start_date, end_date, created) VALUES (7, 'voyage', 'green', '2026-07-01', NULL, '2026-06-30 10:00:00')");
        Db::query("INSERT INTO transaction_tag (transaction_id, tag_id) SELECT id, 7 FROM bank_transaction WHERE uuid IN ('u1', 'u2')");
        // The alerts of two users on the insight
        Db::query("INSERT INTO insight_alert (user_id, insight_id, direction, threshold, notified_month) VALUES
            (" . self::ADMIN . ", 1, 'above', 500, '2026-10-01'), (" . self::USER . ", 1, 'below', 12.5, NULL)");
        $backup = $this->export();
        $before = $backup['datasets'];

        // Changes after the backup
        Db::query("DELETE FROM bank_transaction WHERE uuid = 'u1'");
        Db::query("UPDATE bank_transaction_category SET name = 'Courses' WHERE id = 2");
        Db::query("INSERT INTO budget_insight (name, color, `sql`) VALUES ('X', 'red', 'SELECT 1 AS amount')");
        Db::query("INSERT INTO bank_transaction_deleted (uuid) VALUES ('gone')");

        $result = Backup::import($before);
        $this->assertSame(['categories' => 5, 'rules' => 3, 'budgets' => 2, 'insights' => 1, 'insight_alerts' => 2, 'transactions' => 5,
            'deleted_transactions' => 2, 'tags' => 1, 'transaction_tags' => 2], $result['imported']);
        $this->assertSame([['id' => 7, 'name' => 'voyage', 'color' => 'green', 'start_date' => '2026-07-01', 'end_date' => null,
            'created' => '2026-06-30 10:00:00']], $before['tags']);
        $this->assertSame($before, $this->export()['datasets']);
        $this->assertSame([['user_id' => 'admin', 'insight_id' => 1, 'direction' => 'above', 'threshold' => 500.0, 'notified_month' => '2026-10-01'],
            ['user_id' => 'user', 'insight_id' => 1, 'direction' => 'below', 'threshold' => 12.5, 'notified_month' => null]], $before['insight_alerts']);
        $this->assertSame([['uuid' => 'd1', 'deleted_at' => '2026-01-02 03:04:05', 'user_id' => 'user'],
            ['uuid' => 'd2', 'deleted_at' => '2026-01-03 00:00:00', 'user_id' => null]], $before['deleted_transactions']);

        // Imported again: the same data (idempotent)
        Backup::import($before);
        $this->assertSame($before, $this->export()['datasets']);
    }

    public function testImportReplacesOnlyTheDatasetsGiven()
    {
        $result = Backup::import(['rules' => [['id' => 9, 'keyword' => 'BOULANGERIE', 'category' => 1, 'notify' => true]]]);
        $this->assertSame(['rules' => 1], $result['imported']);
        $this->assertSame(1, $this->rowCount('bank_transaction_category_keyword'));
        $this->assertSame(1, (int)Db::queryOne("SELECT notify FROM bank_transaction_category_keyword WHERE id = 9", "")['notify']);
        $this->assertSame(5, $this->rowCount('bank_transaction'));

        // An empty dataset empties its table
        Backup::import(['budgets' => []]);
        $this->assertSame(0, $this->rowCount('budget'));
    }

    public function testRulesKeepTheirAmount()
    {
        Db::query("INSERT INTO bank_transaction_category_keyword (keyword, category, amount) VALUES ('PAYPAL', 1, 10.00), ('PAYPAL', 3, NULL)");
        $rules = $this->export('rules')['datasets']['rules'];
        $this->assertSame([null, null, null, 10.0, null], array_column($rules, 'amount'));

        Backup::import(['rules' => $rules]);
        $this->assertSame(['10.00'], array_column(Db::select("SELECT amount FROM bank_transaction_category_keyword WHERE amount IS NOT NULL", "")->fetch_all(MYSQLI_ASSOC), 'amount'));
        // An older backup, without amount: any amount
        Backup::import(['rules' => [['id' => 9, 'keyword' => 'BOULANGERIE', 'category' => 1, 'notify' => false]]]);
        $this->assertNull(Db::queryOne("SELECT amount FROM bank_transaction_category_keyword WHERE id = 9", "")['amount']);
    }

    public function testUsersAreMatchedByUsername()
    {
        $transaction = $this->export('transactions')['datasets']['transactions'][0];
        Backup::import([
            'transactions' => [
                ['id' => 1, 'uuid' => 'a', 'user' => 'user'] + $transaction,
                ['id' => 2, 'uuid' => 'b', 'user' => 'somebody-else'] + $transaction,
            ],
            'deleted_transactions' => [['uuid' => 'gone', 'deleted_at' => '2026-01-01 10:00:00', 'user_id' => null]],
        ]);
        $users = array_column(Db::execute("SELECT uuid, user FROM bank_transaction", "")->get_result()->fetch_all(MYSQLI_ASSOC), 'user', 'uuid');
        // Unknown here: the administrator who imports
        $this->assertSame(['a' => self::USER, 'b' => self::ADMIN], array_map('intval', $users));
        // No user (removed since): kept so for a deleted transaction
        $this->assertNull(Db::queryOne("SELECT user_id FROM bank_transaction_deleted", "")['user_id']);

        // A transaction must have an owner: none becomes the administrator who imports
        Backup::import(['transactions' => [['user' => null] + $transaction]]);
        $this->assertSame(self::ADMIN, (int)Db::queryOne("SELECT user FROM bank_transaction", "")['user']);
    }

    public function testInsightAlertsOfUnknownUsersOrInsightsAreDropped()
    {
        $alert = ['insight_id' => 1, 'direction' => 'above', 'threshold' => 100, 'notified_month' => null];
        $result = Backup::import(['insight_alerts' => [
            ['user_id' => 'user'] + $alert,
            // A user of the other instance: not given to the administrator
            ['user_id' => 'somebody-else', 'direction' => 'below'] + $alert,
            // An insight not there
            ['user_id' => 'admin', 'insight_id' => 99] + $alert,
        ]]);
        $this->assertSame(['insight_alerts' => 2], $result['imported']);
        $rows = Db::select("SELECT user_id, insight_id, direction FROM insight_alert", "")->fetch_all(MYSQLI_ASSOC);
        $this->assertSame([['user_id' => self::USER, 'insight_id' => 1, 'direction' => 'above']],
            array_map(fn(array $row): array => array_map(fn($value) => is_numeric($value) ? (int)$value : $value, $row), $rows));
    }

    public function testBeforeTheMigrationTheInsightAlertsAreLeftOut()
    {
        Db::query("DROP TABLE insight_alert");
        $ready = new ReflectionProperty(InsightAlert::class, 'ready');
        $ready->setValue(null, null);
        try {
            $this->assertNotContains('insight_alerts', array_column(Backup::get(), 'name'));
            $this->assertArrayNotHasKey('insight_alerts', $this->export()['datasets']);
            // A backup made after the migration still imports, without its alerts
            $result = Backup::import(['insight_alerts' => [['user_id' => 'user', 'insight_id' => 1, 'direction' => 'above', 'threshold' => 1]],
                'budgets' => []]);
            $this->assertSame(['budgets' => 0], $result['imported']);
        } finally {
            Db::query(implode("\n", array_filter(explode("\n", file_get_contents(__DIR__ . '/../../sql/migrations/2026-11-03_insight_alert.sql')),
                fn($line) => !str_starts_with($line, '--'))));
            $ready->setValue(null, null);
        }
    }

    public function testCategoriesStillUsedCannotDisappear()
    {
        Db::query("INSERT INTO budget_alert (user_id, category, month, budget, spent, percent, sent_at) VALUES (1, 3, '2026-01-01', 10, 20, 200, NOW())");
        $categories = array_values(array_filter($this->export('categories')['datasets']['categories'], fn($c) => $c['id'] !== 3));

        try {
            Backup::import(['categories' => $categories]);
            $this->fail('Accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('The rules refer to categories that do not exist: 3', $e->getMessage());
        }
        // Nothing changed
        $this->assertSame(5, $this->rowCount('bank_transaction_category'));
        $this->assertSame(1, $this->rowCount('budget_alert'));

        // With the data that used it: accepted, the alerts of the category deleted
        $budgets = array_values(array_filter($this->export('budgets')['datasets']['budgets'], fn($b) => $b['category'] !== 3));
        $transactions = array_values(array_filter($this->export('transactions')['datasets']['transactions'], fn($t) => $t['category'] !== 3));
        $rules = array_values(array_filter($this->export('rules')['datasets']['rules'], fn($r) => $r['category'] !== 3));
        Backup::import(['categories' => $categories, 'rules' => $rules, 'budgets' => $budgets, 'transactions' => $transactions]);
        $this->assertSame(4, $this->rowCount('bank_transaction_category'));
        $this->assertSame(0, $this->rowCount('budget_alert'));
    }

    public function testDefaultCategoryIsKept()
    {
        Backup::import(['categories' => [['id' => 1, 'name' => 'Alimentation', 'parent_category' => 0, 'type' => 'DEBIT', 'icon' => '', 'color' => '']],
            'rules' => [], 'budgets' => [], 'transactions' => []]);
        $this->assertSame([0, 1], array_map('intval', array_column(Db::execute("SELECT id FROM bank_transaction_category ORDER BY id", "")->get_result()->fetch_all(MYSQLI_ASSOC), 'id')));
    }

    public function testInvalidImportChangesNothing()
    {
        $cases = [
            [[], 'No dataset to import'],
            [['users' => []], 'Unknown dataset: users'],
            [['budgets' => [['id' => 1]]], 'budgets, row 1: invalid category'],
            [['insights' => [['id' => 1, 'name' => 'X', 'color' => 'red', 'icon' => null, 'sql' => 'DELETE FROM bank_transaction']]], 'insights, row 1: The query must be a SELECT'],
            [['categories' => [['id' => 1, 'name' => 'A', 'parent_category' => 2, 'type' => 'DEBIT', 'icon' => '', 'color' => ''],
                ['id' => 2, 'name' => 'B', 'parent_category' => 1, 'type' => 'DEBIT', 'icon' => '', 'color' => '']]], 'the parent of category 1 must be a top-level category'],
            // Refused by the database (unique keyword), after the rules were deleted
            [['rules' => [['id' => 1, 'keyword' => 'EDF', 'category' => 3, 'notify' => 0], ['id' => 2, 'keyword' => 'EDF', 'category' => 3, 'notify' => 0]]], 'The import failed, nothing was changed'],
        ];
        foreach ($cases as [$datasets, $message]) {
            try {
                Backup::import($datasets);
                $this->fail("Accepted: $message");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $e->getMessage());
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame(3, $this->rowCount('bank_transaction_category_keyword'));
        $this->assertSame(1, (int)Db::queryOne("SELECT @@FOREIGN_KEY_CHECKS AS f", "")['f']);
    }

    public function testFailureOfALaterDatasetRollsBackTheEarlierOnes()
    {
        $transactions = $this->export('transactions')['datasets']['transactions'];
        $mode = Db::queryOne("SELECT @@SESSION.sql_mode AS m", "")['m'];
        try {
            // The categories are valid and written first; the transactions repeat a UUID
            Backup::import([
                'categories' => [['id' => 0, 'name' => 'Aucune', 'parent_category' => 0, 'type' => 'HORS-BUDGET', 'icon' => 'help', 'color' => 'grey'],
                    ['id' => 9, 'name' => 'Nouvelle', 'parent_category' => 0, 'type' => 'DEBIT', 'icon' => '', 'color' => '']],
                'rules' => [],
                'budgets' => [],
                'transactions' => [['id' => 1, 'uuid' => 'same', 'category' => 9] + $transactions[0], ['id' => 2, 'uuid' => 'same', 'category' => 9] + $transactions[1]],
            ]);
            $this->fail('Accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('The import failed, nothing was changed', $e->getMessage());
        }
        $this->assertSame(5, $this->rowCount('bank_transaction_category'));
        $this->assertNull(Db::queryOne("SELECT id FROM bank_transaction_category WHERE id = 9", ""));
        $this->assertSame(3, $this->rowCount('bank_transaction_category_keyword'));
        $this->assertSame(2, $this->rowCount('budget'));
        $this->assertSame(5, $this->rowCount('bank_transaction'));
        // The session is restored
        $this->assertSame(1, (int)Db::queryOne("SELECT @@FOREIGN_KEY_CHECKS AS f", "")['f']);
        $this->assertSame($mode, Db::queryOne("SELECT @@SESSION.sql_mode AS m", "")['m']);
    }

    public function testNewRowsAfterAnImportGetFreeIds()
    {
        $categories = $this->export('categories')['datasets']['categories'];
        $categories[] = ['id' => 40, 'name' => 'Loisirs', 'parent_category' => 0, 'type' => 'DEBIT', 'icon' => '', 'color' => ''];
        Backup::import(['categories' => $categories, 'rules' => [['id' => 500, 'keyword' => 'CINEMA', 'category' => 40, 'notify' => false]]]);

        $this->assertSame(41, Category::create('Voyages', 'DEBIT')['id']);
        $this->assertSame(501, Category::createKeyword('THEATRE', 40)['id']);
        // Category 0 kept its id (NO_AUTO_VALUE_ON_ZERO during the import)
        $this->assertSame('Aucune', Db::queryOne("SELECT name FROM bank_transaction_category WHERE id = 0", "")['name']);
    }

    public function testImportedInsightsAreCheckedAndNormalizedAsWhenSaved()
    {
        $insight = ['id' => 7, 'name' => ' Courses ', 'color' => 'blue', 'icon' => '', 'sql' => ' SELECT SUM(amount) AS amount FROM bank_transaction; '];
        Backup::import(['insights' => [$insight]]);
        $this->assertSame(['id' => 7, 'name' => 'Courses', 'color' => 'blue', 'icon' => null, 'sql' => 'SELECT SUM(amount) AS amount FROM bank_transaction', 'enabled' => true,
            'position' => 0], $this->export('insights')['datasets']['insights'][0]);

        // A disabled insight stays disabled; one of an older backup (no enabled) is enabled
        Backup::import(['insights' => [['enabled' => false] + $insight, ['id' => 8] + $insight]]);
        $this->assertSame([false, true], array_column($this->export('insights')['datasets']['insights'], 'enabled'));

        $cases = [
            [['sql' => 'SELECT COUNT(*) AS amount FROM users'] + $insight, 'The query cannot read users'],
            [['sql' => 'SELECT 1 AS amount; DROP TABLE budget'] + $insight, 'The query must be a single statement'],
            [['sql' => 'SELECT 1 AS amount /* x */'] + $insight, 'The query must be a single statement without comments'],
            [['sql' => 'SELECT SLEEP(10) AS amount'] + $insight, 'Forbidden keyword'],
            [['color' => 'black'] + $insight, 'Invalid color'],
            [['icon' => '<img src=x>'] + $insight, 'Invalid icon'],
        ];
        foreach ($cases as [$row, $message]) {
            try {
                Backup::import(['insights' => [$row]]);
                $this->fail("Accepted: $message");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $e->getMessage());
                $this->assertStringContainsString("insights, row 1: $message", $e->getMessage());
            }
        }
        // The two insights imported before the refused ones
        $this->assertSame(2, $this->rowCount('budget_insight'));
    }

    public function testImportedCategoriesAreCheckedAsWhenSaved()
    {
        $categories = $this->export('categories')['datasets']['categories'];
        $cases = [
            [['icon' => 'x"><script>'] + $categories[1], 'categories, row 2: invalid icon or color'],
            [['color' => 'red;background:url(x)'] + $categories[1], 'categories, row 2: invalid icon or color'],
            // Its own parent, or a sub-category as parent
            [['parent_category' => 1] + $categories[1], 'the parent of category 1 must be a top-level category'],
            [['parent_category' => 2] + $categories[3], 'the parent of category 3 must be a top-level category'],
        ];
        foreach ($cases as [$row, $message]) {
            $rows = $categories;
            $rows[array_search($row['id'], array_column($categories, 'id'), true)] = $row;
            try {
                Backup::import(['categories' => $rows]);
                $this->fail("Accepted: $message");
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode(), $e->getMessage());
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame($categories, $this->export('categories')['datasets']['categories']);
    }

    public function testTransactionsAloneMustFindTheirCategories()
    {
        $transaction = $this->export('transactions')['datasets']['transactions'][0];
        try {
            Backup::import(['transactions' => [['category' => 99] + $transaction]]);
            $this->fail('Accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('The transactions refer to categories that do not exist: 99', $e->getMessage());
        }
        $this->assertSame(5, $this->rowCount('bank_transaction'));
    }

    public function testExportOfAnUnknownDataset()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        Backup::export('categories,users');
    }

    public function testUserCannotBackup()
    {
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['GET /backup', 'GET /backup/export', 'POST /backup/import']);
    }
}
