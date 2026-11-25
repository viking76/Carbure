<?php

use Carbure\Lib\ApiError;
use Carbure\Lib\Db;
use Carbure\Resources\Budget;

class BudgetTest extends DatabaseTestCase
{
    private function byName($rows)
    {
        return array_column($rows, null, 'name');
    }

    public function testTopLevelCategories()
    {
        $budget = $this->byName(Budget::get());

        // Sub-category expenses count for their parent
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertEquals(80, $budget['Alimentation']['consummed']);
        $this->assertEquals(40, $budget['Alimentation']['progress']);
        $this->assertEquals(50, $budget['Énergie']['consummed']);
        $this->assertArrayNotHasKey('Supermarché', $budget);
    }

    public function testSubCategories()
    {
        $budget = $this->byName(Budget::get(null, null, 1));

        $this->assertSame(['Supermarché'], array_keys($budget));
        $this->assertEquals(0, $budget['Supermarché']['budget']);
    }

    public function testTransactionsOfAParentCategoryItself()
    {
        // #492: a transaction put on the parent Alimentation (1), not on its sub-category Supermarché
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user)
            VALUES ('p1', ?, ?, 7, 'CB MARCHE', 1, -15.00, '', 0, 1)", "ss", $this->day(1), $this->day(1));

        // Counted for the parent
        $this->assertEquals(95, $this->byName(Budget::get())['Alimentation']['consummed']);

        // Without own (mobile apps): only the sub-categories, as before
        $this->assertSame(['Supermarché'], array_column(Budget::get(null, null, 1), 'name'));
        $this->assertSame(['Supermarché'], array_column(Budget::get(null, null, 1, 'false'), 'name'));

        // With own: an item for the parent itself, without budget, so that the level adds up to the parent
        $items = Budget::get(null, null, 1, '1');
        $this->assertSame(['Supermarché', 'Alimentation'], array_column($items, 'name'));
        $own = $items[1];
        $this->assertTrue($own['own']);
        $this->assertEquals(1, $own['id']);
        $this->assertSame('DEBIT', $own['type']);
        $this->assertEquals(15, $own['consummed']);
        $this->assertEquals(0, $own['budget']);
        $this->assertSame(0, $own['children']);
        $this->assertArrayNotHasKey('own', $items[0]);
        $this->assertEquals(95, array_sum(array_column($items, 'consummed')));
    }

    public function testNoOwnItemWithoutTransactionsOnTheParent()
    {
        $this->assertSame(['Supermarché'], array_column(Budget::get(null, null, 1, 'true'), 'name'));
        // Top level: nothing to add
        $this->assertNotContains(true, array_column(Budget::get(null, null, 0, 'true'), 'own'));
    }

    public function testCreateOrUpdate()
    {
        Budget::create(1, 300);
        Budget::create(4, 1000, date('n'), date('Y'));

        $budget = $this->byName(Budget::get());
        $this->assertEquals(300, $budget['Alimentation']['budget']);
        $this->assertEquals(1000, $budget['Salaire']['budget']);
    }

    public function testNoteOfABudget()
    {
        // Saved trimmed with the amount, returned with the budget of the month (#468)
        Budget::create(3, 120, date('n'), date('Y'), '  Électricité + gaz  ');
        $budget = $this->byName(Budget::get());
        $this->assertEquals(120, $budget['Énergie']['budget']);
        $this->assertSame('Électricité + gaz', $budget['Énergie']['note']);
        $this->assertNull($budget['Alimentation']['note']);

        // Not sent (mobile apps): kept; "" removes it
        Budget::create(3, 150, date('n'), date('Y'));
        $this->assertSame('Électricité + gaz', $this->byName(Budget::get())['Énergie']['note']);
        Budget::create(3, 150, date('n'), date('Y'), '   ');
        $budget = $this->byName(Budget::get());
        $this->assertNull($budget['Énergie']['note']);
        $this->assertEquals(150, $budget['Énergie']['budget']);
    }

    public function testNoteOfABudgetTooLong()
    {
        Budget::create(3, 100, date('n'), date('Y'), str_repeat('é', 200));
        $this->assertSame(200, mb_strlen($this->byName(Budget::get())['Énergie']['note']));
        try {
            Budget::create(3, 100, date('n'), date('Y'), str_repeat('é', 201));
            $this->fail('A note of 201 characters was saved');
        } catch (ApiError $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertSame('budget_note_length', $e->getErrorCode());
        }
    }

    public function testCopyKeepsTheNotes()
    {
        $previous = strtotime(date('Y-m-01') . ' -1 month');
        $current = strtotime(date('Y-m-01'));
        Db::query("DELETE FROM budget");
        Budget::create(1, 200, date('n', $previous), date('Y', $previous), 'Courses + cantine');
        Budget::create(3, 100, date('n', $previous), date('Y', $previous));
        Budget::create(1, 250, date('n', $current), date('Y', $current), 'Ce mois-ci');

        // Completing: the budget already set keeps its own note
        Budget::copy(date('n', $current), date('Y', $current));
        $budget = $this->byName(Budget::get(date('n', $current), date('Y', $current)));
        $this->assertSame('Ce mois-ci', $budget['Alimentation']['note']);
        $this->assertNull($budget['Énergie']['note']);

        // Overwriting: the note of the previous month comes with its amount
        Budget::copy(date('n', $current), date('Y', $current), true);
        $budget = $this->byName(Budget::get(date('n', $current), date('Y', $current)));
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertSame('Courses + cantine', $budget['Alimentation']['note']);
    }

    public function testBeforeTheMigrationTheBudgetsHaveNoNote()
    {
        Db::query("ALTER TABLE budget DROP COLUMN note");
        $ready = new ReflectionProperty(Budget::class, 'noteReady');
        $ready->setValue(null, null);
        try {
            // Read without note, set without note ("" accepted: nothing to remove), copied
            $this->assertNull($this->byName(Budget::get())['Énergie']['note']);
            Budget::create(3, 120, date('n'), date('Y'));
            Budget::create(3, 130, date('n'), date('Y'), '');
            $this->assertEquals(130, $this->byName(Budget::get())['Énergie']['budget']);
            $next = strtotime(date('Y-m-01') . ' +1 month');
            $this->assertGreaterThan(0, Budget::copy(date('n', $next), date('Y', $next))['copied']);
            try {
                Budget::create(3, 140, date('n'), date('Y'), 'Électricité');
                $this->fail('Note accepted before the migration');
            } catch (ApiError $e) {
                $this->assertSame(409, $e->getCode());
                $this->assertSame('budget_note_migration', $e->getErrorCode());
            }
        } finally {
            Db::query("ALTER TABLE budget ADD COLUMN note varchar(200) DEFAULT NULL AFTER date");
            $ready->setValue(null, null);
        }
    }

    public function testParentBudgetOverridesTheSumOfItsChildren()
    {
        // Alimentation has its own budget (200) and Supermarché one of 150
        Budget::create(2, 150);

        $budget = $this->byName(Budget::get());
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertSame('own', $budget['Alimentation']['budget_mode']);
        $this->assertSame(1, $budget['Alimentation']['children']);
        $this->assertEquals(150, $budget['Alimentation']['children_budget']);
        // No sub-category: always its own budget
        $this->assertSame('own', $budget['Énergie']['budget_mode']);
        $this->assertSame(0, $budget['Énergie']['children']);
    }

    public function testCopyPreviousMonth()
    {
        // Next month is empty: the budgets of the current month are copied to it
        $next = date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month'));
        $result = Budget::copy(date('n', strtotime($next)), date('Y', strtotime($next)));

        $this->assertSame(date('Y-m', strtotime($next)), $result['month']);
        $this->assertSame(date('Y-m'), $result['source']);
        $this->assertSame(2, $result['copied']);
        $this->assertFalse($result['overwrite']);

        $budget = $this->byName(Budget::get(date('n', strtotime($next)), date('Y', strtotime($next))));
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertEquals(100, $budget['Énergie']['budget']);
    }

    public function testCopyOnlyMissingByDefault()
    {
        // Next month already has its own budget for Alimentation: it is kept
        $next = date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month'));
        [$month, $year] = [(int)date('n', strtotime($next)), (int)date('Y', strtotime($next))];
        Budget::create(1, 500, $month, $year);

        $result = Budget::copy($month, $year);
        $this->assertSame(1, $result['copied']);

        $budget = $this->byName(Budget::get($month, $year));
        $this->assertEquals(500, $budget['Alimentation']['budget']);
        $this->assertEquals(100, $budget['Énergie']['budget']);
    }

    public function testCopyOverwrite()
    {
        // With overwrite, the budgets already set are replaced
        $next = date('Y-m-d', strtotime(date('Y-m-01') . ' +1 month'));
        [$month, $year] = [(int)date('n', strtotime($next)), (int)date('Y', strtotime($next))];
        Budget::create(1, 500, $month, $year);

        $result = Budget::copy($month, $year, true);
        $this->assertSame(2, $result['copied']);
        $this->assertTrue($result['overwrite']);

        $budget = $this->byName(Budget::get($month, $year));
        $this->assertEquals(200, $budget['Alimentation']['budget']);
        $this->assertEquals(100, $budget['Énergie']['budget']);
    }

    public function testCopyEmptySource()
    {
        // The month before next month is empty: nothing is copied
        $next = date('Y-m-d', strtotime(date('Y-m-01') . ' +2 months'));
        [$month, $year] = [(int)date('n', strtotime($next)), (int)date('Y', strtotime($next))];
        $result = Budget::copy($month, $year);

        $this->assertSame(0, $result['copied']);
        $this->assertSame(date('Y-m', strtotime(date('Y-m-01') . ' +1 month')), $result['source']);
    }

    public function testCopyAcrossYearBoundary()
    {
        // January 2020 is copied from December 2019
        Budget::create(1, 77, 12, 2019);

        $result = Budget::copy(1, 2020);
        $this->assertSame('2020-01', $result['month']);
        $this->assertSame('2019-12', $result['source']);
        $this->assertSame(1, $result['copied']);

        $budget = $this->byName(Budget::get(1, 2020));
        $this->assertEquals(77, $budget['Alimentation']['budget']);
    }

    public function testCopyKeepsTheSumOfChildren()
    {
        // A 0 budget means "sum of the sub-categories": copied as is
        Budget::create(1, 0, 2, 2020);
        Budget::create(2, 160, 2, 2020);

        $result = Budget::copy(3, 2020);
        $this->assertSame('2020-02', $result['source']);
        $this->assertSame(2, $result['copied']);

        $budget = $this->byName(Budget::get(3, 2020));
        $this->assertSame('children', $budget['Alimentation']['budget_mode']);
        $this->assertEquals(160, $budget['Alimentation']['budget']);
    }

    public function testCopyStatusOfAMonthWithBudgets()
    {
        // The current month has its budgets: the copy is not offered
        $status = Budget::copyStatus();
        $this->assertSame(date('Y-m'), $status['month']);
        $this->assertSame(date('Y-m', strtotime(date('Y-m-01') . ' -1 month')), $status['source']);
        $this->assertTrue($status['defined']);
        $this->assertFalse($status['copyable']);
    }

    public function testCopyStatusOfAnEmptyMonthAfterAMonthWithBudgets()
    {
        // Next month is empty, the current month has budgets: the copy is offered
        $next = strtotime(date('Y-m-01') . ' +1 month');
        $status = Budget::copyStatus(date('n', $next), date('Y', $next));
        $this->assertSame(date('Y-m', $next), $status['month']);
        $this->assertSame(date('Y-m'), $status['source']);
        $this->assertFalse($status['defined']);
        $this->assertTrue($status['source_defined']);
        $this->assertTrue($status['copyable']);

        // Once copied, the month has its budgets: no longer offered
        Budget::copy(date('n', $next), date('Y', $next));
        $this->assertFalse(Budget::copyStatus(date('n', $next), date('Y', $next))['copyable']);
    }

    public function testCopyStatusRouteForAUser()
    {
        // GET /budget/copy, the month from the query string, for any member of the household
        $this->loginAs(self::USER);
        $next = strtotime(date('Y-m-01') . ' +1 month');
        $status = json_decode($this->callRoute('GET', '/budget/copy', ['month' => date('n', $next), 'year' => date('Y', $next)]), true);
        $this->assertSame(['month' => date('Y-m', $next), 'source' => date('Y-m'), 'defined' => false, 'source_defined' => true, 'copyable' => true], $status);
    }

    public function testCopyStatusWithoutBudgetInThePreviousMonth()
    {
        // Two months ahead: neither the month nor the previous one has a budget
        $later = strtotime(date('Y-m-01') . ' +2 months');
        $status = Budget::copyStatus(date('n', $later), date('Y', $later));
        $this->assertFalse($status['defined']);
        $this->assertFalse($status['source_defined']);
        $this->assertFalse($status['copyable']);
    }

    public function testCopyStatusIgnoresBudgetsAtZero()
    {
        // A budget at 0 ("sum of the sub-categories") is not a defined budget
        Budget::create(1, 0, 2, 2020);
        Budget::create(1, 0, 3, 2020);
        $this->assertFalse(Budget::copyStatus(3, 2020)['source_defined']);
        $this->assertFalse(Budget::copyStatus(3, 2020)['defined']);

        // February has an amount, March only rows at 0: the copy is offered
        Budget::create(2, 160, 2, 2020);
        $status = Budget::copyStatus(3, 2020);
        $this->assertSame('2020-02', $status['source']);
        $this->assertTrue($status['copyable']);

        // Across the year boundary too
        Budget::create(4, 50, 12, 2019);
        $this->assertSame('2019-12', Budget::copyStatus('1', '2020')['source']);
        $this->assertTrue(Budget::copyStatus('1', '2020')['copyable']);
    }

    public function testParentBudgetIsTheSumOfItsChildren()
    {
        // 0 removes the override of Alimentation: the budget of Supermarché is used
        Budget::create(1, 0);
        Budget::create(2, 160);

        $budget = $this->byName(Budget::get());
        $this->assertEquals(160, $budget['Alimentation']['budget']);
        $this->assertSame('children', $budget['Alimentation']['budget_mode']);
        $this->assertEquals(50, $budget['Alimentation']['progress']);

        // Planned budget of the month: Alimentation 160 (sum) + Énergie 100
        $this->assertEquals(260, Budget::getTrends(1)[0]['planned']);
    }

    public function testTrends()
    {
        $trends = Budget::getTrends();

        $this->assertCount(24, $trends);
        $this->assertSame(date('Y-m'), $trends[23]['month']);

        // Current month of the data set: -80 -50 expenses, +2000 income,
        // -10 in category 0 which is off-budget
        $current = $trends[23];
        $this->assertEquals(130, $current['debit']);
        $this->assertEquals(2000, $current['credit']);
        $this->assertEquals(-10, $current['offBudget']);
        // No savings category in the data set
        $this->assertEquals(0, $current['savings']);
        // Only the top-level budgets: Alimentation 200 + Énergie 100
        $this->assertEquals(300, $current['planned']);

        // Empty months are present with zeros
        $this->assertEquals(0, $trends[0]['debit']);
    }

    public function testTrendsSavingsFromTheSavingsCategory()
    {
        // "Épargne" (accent and case insensitive) with a sub-category
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (20, 'Épargne', 0, 'HORS-BUDGET', 'banknote', 'green')");
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (21, 'Livret A', 20, 'HORS-BUDGET', 'banknote', 'green')");
        $date = date('Y-m-05');
        foreach ([['s1', 20, -300], ['s2', 21, -200], ['s3', 21, 50]] as [$uuid, $category, $amount]) {
            Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user)
                VALUES (?, ?, ?, 1, 'VIR EPARGNE', ?, ?, 1)", "sssid", $uuid, $date, $date, $category, $amount);
        }

        // 300 + 200 put aside, 50 taken back
        $this->assertEquals(450, Budget::getTrends()[23]['savings']);
    }

    public function testRefundLargerThanSpendingIsNotAnOverspending()
    {
        // An expense category with a budget whose month is a net refund (issue #469)
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (22, 'Enfants', 0, 'DEBIT', 'child_care', 'pink')");
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (23, 'Centre de loisirs', 22, 'DEBIT', 'child_care', 'pink')");
        Budget::create(22, 100, date('n'), date('Y'));
        $date = date('Y-m-05');
        foreach ([['r1', 23, -40], ['r2', 23, 90]] as [$uuid, $category, $amount]) {
            Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user)
                VALUES (?, ?, ?, 1, 'CENTRE DE LOISIRS', ?, ?, 1)", "sssid", $uuid, $date, $date, $category, $amount);
        }

        // 40 spent, 90 refunded: nothing spent, not 50 overspent
        $budget = $this->byName(Budget::get());
        $this->assertEquals(0, $budget['Enfants']['consummed']);
        $this->assertEquals(0, $budget['Enfants']['progress']);

        // A spending larger than the refund stays counted
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user)
            VALUES ('r3', ?, ?, 1, 'CENTRE DE LOISIRS', 23, -80, 1)", "ss", $date, $date);
        $budget = $this->byName(Budget::get());
        $this->assertEquals(30, $budget['Enfants']['consummed']);
        $this->assertEquals(30, $budget['Enfants']['progress']);
    }

    public function testFlow()
    {
        // Current month of the data set: +2000 income (Salaire), -80 Supermarché (child of
        // Alimentation), -50 Énergie, -10 uncategorized
        $flow = Budget::flow();
        $this->assertSame(date('Y-m'), $flow['month']);
        $this->assertEquals(2000, $flow['totalIncome']);
        $this->assertSame('Salaire', $flow['income'][0]['name']);
        $expenses = array_column($flow['expenses'], 'amount', 'name');
        // The child is grouped with its parent
        $this->assertEquals(80, $expenses['Alimentation']);
        $this->assertEquals(50, $expenses['Énergie']);
        $this->assertEquals(140, $flow['totalExpenses']);
        $this->assertEquals(0, $flow['savings']);
        $this->assertEquals(1860, $flow['balance']);

        // A month without transactions
        $empty = Budget::flow(1, 2001);
        $this->assertSame([], $empty['income']);
        $this->assertEquals(0, $empty['balance']);
    }

    public function testFlowTransactions()
    {
        // The transactions behind each node add up to its amount
        $flow = Budget::flow();
        foreach ($flow['expenses'] as $node) {
            $rows = Budget::flowTransactions('expense', (string)$node['id']);
            $this->assertEquals($node['amount'], -array_sum(array_column($rows, 'amount')), $node['name'] ?? 'uncategorized');
            $this->assertSame([$node['id']], array_values(array_unique(array_column($rows, 'top'))));
        }
        $income = Budget::flowTransactions('income');
        $this->assertEquals($flow['totalIncome'], array_sum(array_column($income, 'amount')));

        // Several categories at once ("Others" of the diagram)
        $ids = implode(',', array_column($flow['expenses'], 'id'));
        $this->assertEquals($flow['totalExpenses'], -array_sum(array_column(Budget::flowTransactions('expense', $ids), 'amount')));

        $this->assertSame([], Budget::flowTransactions('expense', null, 1, 2001));

        $this->expectException(Error::class);
        Budget::flowTransactions('balance');
    }

    public function testTrendsWithOffset()
    {
        // The 3 months before the last 3 months
        $trends = Budget::getTrends(3, 3);

        $this->assertCount(3, $trends);
        $this->assertSame(date('Y-m', strtotime(date('Y-m-01') . ' -3 months')), $trends[2]['month']);
        $this->assertEquals(0, $trends[2]['debit']);
    }

    public function testTrendsOffBudgetAndBounds()
    {
        Db::query("UPDATE bank_transaction_category SET type='HORS-BUDGET' WHERE id=3");

        $trends = Budget::getTrends(1);
        $this->assertCount(1, $trends);
        $this->assertEquals(80, $trends[0]['debit']);
        $this->assertEquals(-60, $trends[0]['offBudget']);

        $this->assertCount(240, Budget::getTrends(500));
    }

    public function testInsights()
    {
        $insights = Budget::getInsights();

        $this->assertSame('Dépenses', $insights[0]['name']);
        $this->assertEquals(-140, $insights[0]['amount']);
        $this->assertArrayNotHasKey('sql', $insights[0]);
    }

    public function testInsightsCastTheDate()
    {
        // Used to be injected as is in the SQL: now cast to 1, i.e. January 2020 only
        $this->assertEquals(-5, Budget::getInsights('1) OR (1=1', 2020)[0]['amount']);
    }

    public function testInsightsHistory()
    {
        $history = Budget::getInsightsHistory();

        $this->assertCount(12, $history[1]['history']);
        $this->assertEquals(-140, $history[1]['history'][date('n') - 1]['amount']);
    }
}
