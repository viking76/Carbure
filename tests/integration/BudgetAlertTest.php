<?php

use Carbure\Lib\Db;
use Carbure\Lib\File;
use Carbure\Lib\Installer;
use Carbure\Lib\Mailer;
use Carbure\Resources\Bank;
use Carbure\Resources\Budget;
use Carbure\Resources\BudgetAlert;
use Carbure\Resources\Notification;
use Carbure\Resources\Transaction;

/**
 * Alerts when the spending of the month of a budget reaches the threshold of a user
 * (data set: Alimentation 200 € with its sub-category Supermarché at -80 €, Énergie
 * 100 € at -50 €, Salaire without budget)
 */
class BudgetAlertTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FakeSmtpServer::start();
    }

    protected function tearDown(): void
    {
        FakeSmtpServer::unconfigure();
        parent::tearDown();
    }

    public function testSpendingAsTheBudgetsScreen()
    {
        // The expense categories with a budget, the sub-categories added to their parent
        $this->assertSame([
            ['id' => 1, 'name' => 'Alimentation', 'budget' => 200.0, 'spent' => 80.0],
            ['id' => 3, 'name' => 'Énergie', 'budget' => 100.0, 'spent' => 50.0],
        ], $this->byId(BudgetAlert::spending((int)date('n'), (int)date('Y'))));

        // A budget of a sub-category is checked too; an income category never
        Db::execute("INSERT INTO budget (category, amount, date) VALUES (2, 60, ?), (4, 1000, ?)", "ss", $this->day(0), $this->day(0));
        $spending = $this->byId(BudgetAlert::spending((int)date('n'), (int)date('Y')));
        $this->assertSame([1, 2, 3], array_column($spending, 'id'));
        $this->assertSame(['id' => 2, 'name' => 'Supermarché', 'budget' => 60.0, 'spent' => 80.0], $spending[1]);
    }

    public function testARefundLargerThanTheSpendingIsNothingSpent()
    {
        // Énergie (100 €, -50 € spent) refunded 120 €: no spending to alert on (issue #469)
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user)
            VALUES ('refund', ?, ?, 1, 'REMBOURSEMENT ENERGIE', 3, 120, 1)", "ss", $this->day(0), $this->day(0));
        $spending = $this->byId(BudgetAlert::spending((int)date('n'), (int)date('Y')));
        $this->assertSame(['id' => 3, 'name' => 'Énergie', 'budget' => 100.0, 'spent' => 0.0], $spending[1]);
    }

    public function testOffByDefault()
    {
        $this->spend(2, -150);

        $this->assertSame(0, BudgetAlert::check());
        $this->assertSame([], FakePushRelay::requests());
        $this->assertSame([], FakeSmtpServer::messages());
        $this->assertSame(0, $this->alerts());
    }

    public function testAlertOncePerMonthWithTheSubCategories()
    {
        $this->subscribe(self::ADMIN, true, false);
        // Supermarché (sub-category of Alimentation): 80 + 130 = 210 € of 200 €
        $this->spend(2, -130);

        $this->assertSame(1, BudgetAlert::check());
        $requests = FakePushRelay::requests();
        $this->assertCount(1, $requests);
        $this->assertSame('Budget dépassé : Alimentation', $requests[0]['payload']['aps']['alert']['title']);
        $this->assertSame('210,00 € sur 200,00 € (105 %)', $requests[0]['payload']['aps']['alert']['body']);
        // Badge as the other notifications: the transactions to review
        $this->assertSame(Transaction::countUnpointed(), $requests[0]['payload']['aps']['badge']);
        $row = Db::queryOne("SELECT category, month, budget, spent, percent FROM budget_alert WHERE user_id = 1", "");
        $this->assertSame(['category' => 1, 'month' => $this->day(0), 'budget' => '200.00', 'spent' => '210.00', 'percent' => 105], $row);

        // Not again this month, even with more spending
        FakePushRelay::start();
        $this->spend(2, -20);
        $this->assertSame(0, BudgetAlert::check());
        $this->assertSame([], FakePushRelay::requests());
    }

    public function testAlertedAgainWhenTheBudgetIsRaisedAndReached()
    {
        $this->subscribe(self::ADMIN, true, false);
        $this->spend(2, -130);
        $this->assertSame(1, BudgetAlert::check());

        // Raised to 300 €: not reached, nothing
        Budget::create(1, 300);
        $this->assertSame(0, BudgetAlert::check());

        // Reached again: alerted for the new amount
        FakePushRelay::start();
        $this->spend(2, -100);
        $this->assertSame(1, BudgetAlert::check());
        $this->assertSame('310,00 € sur 300,00 € (103 %)', FakePushRelay::requests()[0]['payload']['aps']['alert']['body']);
        $this->assertSame('300.00', Db::queryOne("SELECT budget FROM budget_alert WHERE user_id = 1", "")['budget']);

        // Lowered: already alerted for a higher budget
        Budget::create(1, 250);
        $this->assertSame(0, BudgetAlert::check());
        $this->assertSame(1, $this->alerts());
    }

    public function testThresholdAndLanguageOfEachUser()
    {
        // The user (English) by email only, at 50 %: Énergie (50 of 100 €) is reached
        $this->subscribe(self::USER, false, true);
        Db::query("UPDATE users SET budget_threshold = 50 WHERE id = 2");
        // The administrator at 100 %: nothing yet
        $this->subscribe(self::ADMIN, true, true);

        $this->assertSame(1, BudgetAlert::check());
        $this->assertSame([], FakePushRelay::requests());
        $messages = FakeSmtpServer::messages();
        $this->assertCount(1, $messages);
        $this->assertSame(['user@example.com'], $messages[0]['to']);
        $this->assertStringContainsString('Subject: ' . Mailer::encodeHeader('[Carbure] Budget almost reached: Énergie'), $messages[0]['data']);
        $this->assertStringContainsString("Budget almost reached: Énergie\r\n\r\n50.00 € of 100.00 € (50%)", quoted_printable_decode($messages[0]['data']));
    }

    public function testAlertAfterASynchronization()
    {
        $this->subscribe(self::ADMIN, true, false);
        $this->loginAs(self::ADMIN);

        // The synchronization brings PRLV SEPA EDF (-60.10 €), categorized Énergie by its rule
        ob_start();
        try {
            Bank::getSync(null, '111@bnp');
        } finally {
            $output = ob_get_clean();
        }
        $this->assertStringContainsString('Checking the budgets...', $output);
        $titles = array_map(fn($request) => $request['payload']['aps']['alert']['title'], FakePushRelay::requests());
        $this->assertContains('Budget dépassé : Énergie', $titles);
        $this->assertSame(1, $this->alerts());
    }

    public function testAlertAfterAnImportOfTheCurrentMonth()
    {
        $this->subscribe(self::USER, true, false);
        Db::execute("INSERT INTO devices (name, token, user_id) VALUES ('iPad', ?, 2)", "s", str_repeat('b', 64));
        $this->loginAs(self::USER);

        // Categorized Supermarché by its rule: 80 + 150 = 230 € of 200 €
        $date = date('d/m/Y', strtotime($this->day(4)));
        Transaction::import(base64_encode("Date;Libellé;Montant\n$date;CB SUPERMARCHE IMPORT;-150,00\n"), 'releve.csv');

        $requests = FakePushRelay::requests();
        $this->assertCount(1, $requests);
        $this->assertSame('Budget exceeded: Alimentation', $requests[0]['payload']['aps']['alert']['title']);
        $this->assertSame('230.00 € of 200.00 € (115%)', $requests[0]['payload']['aps']['alert']['body']);
    }

    public function testNoAlertAfterAnImportOfPastMonths()
    {
        $this->subscribe(self::USER, true, false);
        $this->loginAs(self::USER);
        $this->spend(2, -150);

        // Only an old month: the budgets of the current month are not checked
        Transaction::import(base64_encode("Date;Libellé;Montant\n10/05/2019;CB SUPERMARCHE ANCIEN BIS;-500,00\n"), 'releve.csv');
        $this->assertSame([], FakePushRelay::requests());
        $this->assertSame(0, $this->alerts());
    }

    public function testThresholdSavedWithThePreferences()
    {
        $this->loginAs(self::USER);
        $notifications = Notification::update(['budget' => ['push' => 'true']], '80');
        $this->assertSame(80, $notifications['budget_threshold']);
        $this->assertSame(['push' => true, 'email' => false], $notifications['preferences']['budget']);

        // The threshold alone, the channels kept
        $notifications = Notification::update([], 150);
        $this->assertSame(150, $notifications['budget_threshold']);
        $this->assertSame(['push' => true, 'email' => false], $notifications['preferences']['budget']);
        $this->assertSame(100, (int)Db::queryOne("SELECT budget_threshold FROM users WHERE id = 1", "")['budget_threshold']);
    }

    /**
     * @return array Thresholds refused
     */
    public static function refusedThresholds(): array
    {
        return [
            'too low' => ['49'],
            'too high' => [201],
            'decimal' => ['80.5'],
            'not a number' => ['abc'],
            'empty' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedThresholds')]
    public function testRefusedThresholds($threshold)
    {
        $this->loginAs(self::USER);
        try {
            Notification::update(['budget' => ['push' => true]], $threshold);
            $this->fail('Invalid threshold accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        // Nothing saved
        $this->assertSame(100, (int)Db::queryOne("SELECT budget_threshold FROM users WHERE id = 2", "")['budget_threshold']);
        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS n FROM user_notification", "")['n']);
    }

    public function testOffWhenThePreferencesCannotBeRead()
    {
        // Database not migrated yet: the opt-in kinds stay off
        Db::query("RENAME TABLE user_notification TO user_notification_hidden");
        try {
            $sent = Notification::notify(self::ADMIN, 'budget', 'Title', 'Body');
        } finally {
            Db::query("RENAME TABLE user_notification_hidden TO user_notification");
        }
        $this->assertSame(['push' => false, 'email' => false], $sent);
        $this->assertSame([], FakePushRelay::requests());
    }

    public function testDefaultThresholdBeforeTheMigration()
    {
        $this->loginAs(self::USER);
        Db::query("ALTER TABLE users CHANGE budget_threshold budget_threshold_hidden smallint(5) unsigned NOT NULL DEFAULT 100");
        try {
            $notifications = Notification::get();
        } finally {
            Db::query("ALTER TABLE users CHANGE budget_threshold_hidden budget_threshold smallint(5) unsigned NOT NULL DEFAULT 100 COMMENT 'Percent of a budget whose spending of the month alerts (50 to 200)'");
        }
        $this->assertSame(100, $notifications['budget_threshold']);
    }

    public function testAFailureNeverStopsTheCaller()
    {
        $this->subscribe(self::ADMIN, true, false);
        $this->spend(2, -130);
        Db::query("RENAME TABLE budget_alert TO budget_alert_hidden");
        try {
            $this->assertSame(0, BudgetAlert::check());
        } finally {
            Db::query("RENAME TABLE budget_alert_hidden TO budget_alert");
        }
    }

    public function testOnlyTheCategoriesWithAThreshold()
    {
        // Both reached at 40 %: without the global threshold, only Énergie (its own) alerts
        $this->subscribe(self::ADMIN, true, false);
        Db::query("UPDATE users SET budget_threshold = 40, budget_alert_all = 0 WHERE id = 1");
        Db::query("INSERT INTO budget_alert_threshold (user_id, category, threshold) VALUES (1, 3, 50)");

        $this->assertSame(1, BudgetAlert::check());
        $requests = FakePushRelay::requests();
        $this->assertCount(1, $requests);
        $this->assertSame('Budget presque atteint : Énergie', $requests[0]['payload']['aps']['alert']['title']);
        $this->assertSame(3, (int)Db::queryOne("SELECT category FROM budget_alert", "")['category']);
    }

    public function testThresholdOfACategoryInsteadOfTheGlobalOne()
    {
        // 100 % on every budget, but 40 % on Alimentation (80 € of 200 €): only it alerts,
        // Énergie (50 %) is under the global threshold
        $this->subscribe(self::ADMIN, true, false);
        Db::query("INSERT INTO budget_alert_threshold (user_id, category, threshold) VALUES (1, 1, 40)");

        $this->assertSame(1, BudgetAlert::check());
        $this->assertSame('Budget presque atteint : Alimentation', FakePushRelay::requests()[0]['payload']['aps']['alert']['title']);
    }

    public function testTwoCategoriesAtTwoThresholds()
    {
        // Alimentation at 40 % (reached), Énergie at 60 % (50 %: not yet), nothing else
        $this->subscribe(self::ADMIN, true, false);
        Db::query("UPDATE users SET budget_alert_all = 0 WHERE id = 1");
        Db::query("INSERT INTO budget_alert_threshold (user_id, category, threshold) VALUES (1, 1, 40), (1, 3, 60)");
        $this->assertSame(1, BudgetAlert::check());

        // Énergie at 65 €: its threshold reached in turn, Alimentation not alerted again
        FakePushRelay::start();
        $this->spend(3, -15);
        $this->assertSame(1, BudgetAlert::check());
        $this->assertSame('Budget presque atteint : Énergie', FakePushRelay::requests()[0]['payload']['aps']['alert']['title']);
        $this->assertSame(2, $this->alerts());
    }

    public function testThresholdsSavedWithThePreferences()
    {
        $this->loginAs(self::USER);
        $notifications = Notification::get();
        $this->assertTrue($notifications['budget_all']);
        $this->assertSame([], $notifications['budget_thresholds']);

        // Two categories, the global threshold off; given as strings like the portal sends
        $notifications = Notification::update([], null, null, null, 'false', [['category' => '3', 'threshold' => '80'], ['category' => 1, 'threshold' => 90]]);
        $this->assertFalse($notifications['budget_all']);
        $this->assertSame([['category' => 1, 'threshold' => 90], ['category' => 3, 'threshold' => 80]], $notifications['budget_thresholds']);
        // Two categories: no budget watched for the older clients
        $this->assertNull($notifications['budget_category']);
        $this->assertNull(Db::queryOne("SELECT budget_alert_category FROM users WHERE id = 2", "")['budget_alert_category']);

        // One category left: the budget watched of the older clients
        $notifications = Notification::update([], null, null, null, null, [['category' => 3, 'threshold' => 80]]);
        $this->assertSame(3, $notifications['budget_category']);
        $this->assertSame(3, (int)Db::queryOne("SELECT budget_alert_category FROM users WHERE id = 2", "")['budget_alert_category']);

        // The global threshold back on: what is not sent is kept
        $notifications = Notification::update([], 120, null, null, true);
        $this->assertTrue($notifications['budget_all']);
        $this->assertSame(120, $notifications['budget_threshold']);
        $this->assertSame([['category' => 3, 'threshold' => 80]], $notifications['budget_thresholds']);
        $this->assertNull($notifications['budget_category']);

        // An older client watching one budget: the global threshold off, that category only
        $notifications = Notification::update([], null, null, '1');
        $this->assertFalse($notifications['budget_all']);
        $this->assertSame([['category' => 1, 'threshold' => 120]], $notifications['budget_thresholds']);
        // ...and all the budgets again
        $notifications = Notification::update([], null, null, '');
        $this->assertTrue($notifications['budget_all']);
        $this->assertSame([], $notifications['budget_thresholds']);
    }

    /**
     * @return array Thresholds refused: [budgetAll, budgetThresholds, budgetCategory]
     */
    public static function refusedCategoryThresholds(): array
    {
        return [
            'income category' => [null, [['category' => 4, 'threshold' => 80]], null],
            'unknown category' => [null, [['category' => 99, 'threshold' => 80]], null],
            'no category' => [null, [['category' => 0, 'threshold' => 80]], null],
            'twice the same category' => [null, [['category' => 3, 'threshold' => 80], ['category' => '3', 'threshold' => 90]], null],
            'threshold too low' => [null, [['category' => 3, 'threshold' => 30]], null],
            'threshold not an integer' => [null, [['category' => 3, 'threshold' => '80.5']], null],
            'not an object' => [null, ['3'], null],
            'missing threshold' => [null, [['category' => 3]], null],
            'global threshold not a flag' => ['maybe', null, null],
            'both the thresholds and the budget watched' => [null, [], '3'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedCategoryThresholds')]
    public function testRefusedCategoryThresholds($all, $thresholds, $category)
    {
        $this->loginAs(self::USER);
        try {
            Notification::update(['budget' => ['push' => true]], 80, null, $category, $all, $thresholds);
            $this->fail('Invalid thresholds accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        // Nothing saved
        $user = Db::queryOne("SELECT budget_threshold, budget_alert_all FROM users WHERE id = 2", "");
        $this->assertSame(['budget_threshold' => 100, 'budget_alert_all' => 1], $user);
        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS n FROM budget_alert_threshold", "")['n']);
        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS n FROM user_notification", "")['n']);
    }

    public function testThresholdRemovedWithItsCategory()
    {
        Db::query("INSERT INTO budget_alert_threshold (user_id, category, threshold) VALUES (2, 3, 80), (2, 1, 90)");
        Db::query("DELETE FROM budget WHERE category = 3");
        Db::query("DELETE FROM bank_transaction WHERE category = 3");
        Db::query("DELETE FROM bank_transaction_category WHERE id = 3");
        $this->assertSame(['all' => 100, 'categories' => [1 => 90]], BudgetAlert::rules(self::USER));
    }

    public function testMigrationKeepsTheSettingsAndBeforeItTheBudgetWatched()
    {
        // A database before the migration: the administrator watched Énergie at 50 %, the
        // user all the budgets at 70 %
        Db::query("DROP TABLE budget_alert_threshold");
        Db::query("ALTER TABLE users DROP COLUMN budget_alert_all");
        $migration = File::read(dirname(__DIR__, 2) . '/sql/migrations/2026-10-27_budget_alert_threshold.sql');
        try {
            Db::query("UPDATE users SET budget_threshold = 50, budget_alert_category = 3 WHERE id = 1");
            Db::query("UPDATE users SET budget_threshold = 70 WHERE id = 2");
            $this->assertFalse(BudgetAlert::thresholdsReady());
            $this->assertSame(['all' => null, 'categories' => [3 => 50]], BudgetAlert::rules(self::ADMIN));
            $this->assertSame(['all' => 70, 'categories' => []], BudgetAlert::rules(self::USER));

            // Only Énergie alerts the administrator, as before
            $this->subscribe(self::ADMIN, true, false);
            $this->assertSame(1, BudgetAlert::check());
            $this->assertSame('Budget presque atteint : Énergie', FakePushRelay::requests()[0]['payload']['aps']['alert']['title']);

            // An older client still saves its budget watched; the thresholds of categories
            // wait for the migration
            $this->loginAs(self::USER);
            $this->assertSame(3, Notification::update([], null, null, 3)['budget_category']);
            try {
                Notification::update([], null, null, null, null, [['category' => 1, 'threshold' => 80]]);
                $this->fail('Thresholds of categories saved before their migration');
            } catch (Error $e) {
                $this->assertSame(409, $e->getCode());
            }
        } finally {
            Installer::runScript(Db::getConnection(), $migration);
        }

        // After the migration: the same settings
        $this->assertTrue(BudgetAlert::thresholdsReady());
        $this->assertSame(['all' => null, 'categories' => [3 => 50]], BudgetAlert::rules(self::ADMIN));
        $this->assertSame(['all' => null, 'categories' => [3 => 70]], BudgetAlert::rules(self::USER));
        Db::query("UPDATE users SET budget_alert_category = NULL WHERE id = 2");
        Db::query("DELETE FROM budget_alert_threshold WHERE user_id = 2");
        Db::query("UPDATE users SET budget_alert_all = 1 WHERE id = 2");
        $this->assertSame(['all' => 70, 'categories' => []], BudgetAlert::rules(self::USER));
    }

    public function testBudgetsThatCanBeWatched()
    {
        // The expense categories with a budget this month, a sub-category after its parent
        Db::execute("INSERT INTO budget (category, amount, date) VALUES (2, 60, ?), (4, 1000, ?)", "ss", $this->day(0), $this->day(0));
        $this->assertSame([
            ['id' => 1, 'name' => 'Alimentation'],
            ['id' => 2, 'name' => 'Alimentation › Supermarché'],
            ['id' => 3, 'name' => 'Énergie'],
        ], BudgetAlert::budgets((int)date('n'), (int)date('Y')));
    }

    public function testBudgetWatchedSavedWithThePreferences()
    {
        $this->loginAs(self::USER);
        $notifications = Notification::get();
        $this->assertNull($notifications['budget_category']);
        $this->assertSame([['id' => 1, 'name' => 'Alimentation'], ['id' => 3, 'name' => 'Énergie']], $notifications['budgets']);

        $notifications = Notification::update([], null, null, '3');
        $this->assertSame(3, $notifications['budget_category']);
        $this->assertSame(3, (int)Db::queryOne("SELECT budget_alert_category FROM users WHERE id = 2", "")['budget_alert_category']);

        // A category without a budget this month stays listed while it is watched
        Notification::update([], null, null, 2);
        $this->assertSame(['id' => 2, 'name' => 'Supermarché'], Notification::get()['budgets'][2]);

        // 0 or "": back to all the budgets
        $this->assertNull(Notification::update([], null, null, '')['budget_category']);
        Notification::update([], null, null, 3);
        $this->assertNull(Notification::update([], null, null, 0)['budget_category']);
    }

    /**
     * @return array Budgets watched refused: an income category, unknown, not a number
     */
    public static function refusedCategories(): array
    {
        return ['income' => [4], 'unknown' => ['99'], 'not a number' => ['abc']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedCategories')]
    public function testRefusedCategories($category)
    {
        $this->loginAs(self::USER);
        try {
            Notification::update(['budget' => ['push' => true]], 80, null, $category);
            $this->fail('Invalid category accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
        }
        // Nothing saved
        $user = Db::queryOne("SELECT budget_threshold, budget_alert_category FROM users WHERE id = 2", "");
        $this->assertSame(['budget_threshold' => 100, 'budget_alert_category' => null], $user);
        $this->assertSame(0, (int)Db::queryOne("SELECT COUNT(*) AS n FROM user_notification", "")['n']);
    }

    public function testBackToAllTheBudgetsWhenTheCategoryIsDeleted()
    {
        Db::query("UPDATE users SET budget_alert_category = 3 WHERE id = 2");
        Db::query("DELETE FROM budget WHERE category = 3");
        Db::query("DELETE FROM bank_transaction WHERE category = 3");
        Db::query("DELETE FROM bank_transaction_category WHERE id = 3");
        $this->assertNull(BudgetAlert::watchedCategory(self::USER));
    }

    public function testAllTheBudgetsBeforeTheMigration()
    {
        $this->loginAs(self::USER);
        $this->subscribe(self::USER, true, false);
        Db::query("UPDATE users SET budget_threshold = 40");
        Db::query("ALTER TABLE users DROP FOREIGN KEY fk_users_budget_alert_category");
        Db::query("ALTER TABLE users DROP COLUMN budget_alert_category");
        try {
            $notifications = Notification::get();
            $sent = BudgetAlert::check();
            try {
                Notification::update([], null, null, 3);
                $this->fail('Budget watched saved without its column');
            } catch (Error $e) {
                $this->assertSame(409, $e->getCode());
            }
        } finally {
            Db::query("ALTER TABLE users ADD COLUMN budget_alert_category tinyint(3) unsigned DEFAULT NULL AFTER budget_threshold,
                ADD CONSTRAINT fk_users_budget_alert_category FOREIGN KEY (budget_alert_category) REFERENCES bank_transaction_category (id) ON DELETE SET NULL");
        }
        $this->assertNull($notifications['budget_category']);
        // Alimentation (40 %) and Énergie (50 %), both reached at 40 %
        $this->assertSame(2, $sent);
    }

    public function testAlertsDeletedWithTheUser()
    {
        $this->subscribe(self::ADMIN, true, false);
        $this->subscribe(self::USER, true, false);
        $this->spend(2, -130);
        $this->assertSame(2, BudgetAlert::check());

        Db::execute("DELETE FROM users WHERE id = ?", "i", self::USER);
        $this->assertSame(1, $this->alerts());
    }

    /**
     * Turn the budget alerts on for a user
     */
    private function subscribe($userId, $push, $email)
    {
        Db::execute("INSERT INTO user_notification (user_id, kind, push, email) VALUES (?, 'budget', ?, ?)", "iii", $userId, (int)$push, (int)$email);
    }

    /**
     * An expense of the current month in a category
     */
    private function spend($category, $amount)
    {
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES (?, ?, ?, 7, 'CB TEST', ?, ?, '', 1, 1)",
            "sssid", uniqid('t', true), $this->day(5), $this->day(5), $category, $amount);
    }

    /**
     * Number of alerts recorded
     */
    private function alerts()
    {
        return (int)Db::queryOne("SELECT COUNT(*) AS n FROM budget_alert", "")['n'];
    }

    /**
     * Spending sorted by category
     */
    private function byId(array $spending)
    {
        usort($spending, fn($a, $b) => $a['id'] <=> $b['id']);
        return $spending;
    }
}
