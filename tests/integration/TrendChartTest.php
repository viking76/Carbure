<?php

use Carbure\Lib\Db;
use Carbure\Lib\Setting;
use Carbure\Resources\Budget;
use Carbure\Resources\TrendChart;

class TrendChartTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs(self::ADMIN);
    }

    /**
     * The error of a call: [HTTP code, message]
     */
    private function error(callable $call)
    {
        try {
            $call();
        } catch (Error $e) {
            return $e->getCode();
        }
        $this->fail('No error');
    }

    /**
     * Months between the current month and a month (Y-m)
     */
    private function monthsAgo($month)
    {
        [$year, $number] = array_map('intval', explode('-', $month));
        return ((int)date('Y') - $year) * 12 + (int)date('n') - $number;
    }

    private function addTransaction($uuid, $category, $amount, $date = null)
    {
        $date ??= $this->day(4);
        Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, user) VALUES (?, ?, ?, 7, 'CB TEST', ?, ?, 1)",
            "sssid", $uuid, $date, $date, $category, $amount);
    }

    public function testCategorySeriesIncludesTheSubCategories()
    {
        // Alimentation: Supermarché -80 this month, plus -20 in Alimentation itself
        $this->addTransaction('t1', 1, -20);
        $series = TrendChart::series('category', 1, 3);

        $this->assertCount(3, $series);
        $this->assertSame(['month', 'amount', 'planned'], array_keys($series[2]));
        $this->assertSame(date('Y-m'), $series[2]['month']);
        // Expenses: the money spent, as a positive amount
        $this->assertSame(100.0, $series[2]['amount']);
        // Own budget of Alimentation
        $this->assertSame(200.0, $series[2]['planned']);

        // The sub-category alone
        $this->assertSame(80.0, TrendChart::series('category', 2, 1)[0]['amount']);
    }

    public function testCategorySeriesEmptyMonths()
    {
        $series = TrendChart::series('category', 3);

        // 24 months by default, without transactions nor budget before the current one
        $this->assertCount(24, $series);
        $this->assertSame(['month' => date('Y-m', strtotime(date('Y-m-01') . ' -23 months')), 'amount' => 0.0, 'planned' => null], $series[0]);
        $this->assertSame(50.0, $series[23]['amount']);
        $this->assertSame(100.0, $series[23]['planned']);
    }

    public function testCategorySeriesSigns()
    {
        // An expense refunded counts against the spending
        $this->addTransaction('t1', 2, 30);
        $this->assertSame(50.0, TrendChart::series('category', 1, 1)[0]['amount']);

        // Income: the signed sum
        $this->assertSame(2000.0, TrendChart::series('category', 4, 1)[0]['amount']);

        // Off-budget: the signed sum too
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (5, 'Virements', 0, 'HORS-BUDGET', 'sync_alt', 'grey')");
        $this->addTransaction('t2', 5, -100);
        $this->addTransaction('t3', 5, 40);
        $this->assertSame(-60.0, TrendChart::series('category', 5, 1)[0]['amount']);
    }

    public function testCategoryPlannedLikeTheBudgetTab()
    {
        // No own budget: the sum of the sub-categories
        Budget::create(1, 0);
        Budget::create(2, 160);
        $this->assertSame(160.0, TrendChart::series('category', 1, 1)[0]['planned']);
        $this->assertSame(160.0, TrendChart::series('category', 2, 1)[0]['planned']);

        // An own budget overrides the sum
        Budget::create(1, 250);
        $this->assertSame(250.0, TrendChart::series('category', 1, 1)[0]['planned']);

        // A sub-category budget of another month only
        $previous = date('Y-m-01', strtotime(date('Y-m-01') . ' -1 month'));
        Db::execute("INSERT INTO budget (category, amount, date) VALUES (2, 90, ?)", "s", $previous);
        $series = TrendChart::series('category', 1, 3);
        $this->assertSame([null, 90.0, 250.0], array_column($series, 'planned'));

        // Without budget
        $this->assertNull(TrendChart::series('category', 4, 1)[0]['planned']);
    }

    public function testBoundsAndOffset()
    {
        $this->assertCount(240, TrendChart::series('category', 2, 240));
        $this->assertCount(240, TrendChart::series('category', 2, 241));
        $this->assertCount(1, TrendChart::series('category', 2, 0));
        $this->assertCount(240, Budget::getTrends(241));

        // The transaction of January 2020 is in a long period
        $series = array_column(TrendChart::series('category', 2, 240), 'amount', 'month');
        $this->assertSame(5.0, $series['2020-01']);

        // An offset to January 2020
        $offset = $this->monthsAgo('2020-01');
        $this->assertSame([['month' => '2020-01', 'amount' => 5.0, 'planned' => null]], TrendChart::series('category', 2, 1, $offset));
        $this->assertSame('2020-01', Budget::getTrends(1, $offset)[0]['month']);
        $this->assertEquals(5, Budget::getTrends(1, $offset)[0]['debit']);

        // The offset is kept up to 240 months
        $last = date('Y-m', strtotime(date('Y-m-01') . ' -240 months'));
        $this->assertSame($last, TrendChart::series('category', 2, 1, 240)[0]['month']);
        $this->assertSame($last, TrendChart::series('category', 2, 1, 241)[0]['month']);
        $this->assertSame($last, Budget::getTrends(1, 241)[0]['month']);
    }

    public function testInsightSeries()
    {
        $series = TrendChart::series('insight', 1, 2);

        $this->assertSame(date('Y-m'), $series[1]['month']);
        $this->assertSame(-140.0, $series[1]['amount']);
        $this->assertNull($series[1]['planned']);
        // No transaction: the query gives NULL, shown as 0
        $this->assertSame(0.0, $series[0]['amount']);

        $this->assertSame(-5.0, TrendChart::series('insight', '1', 1, $this->monthsAgo('2020-01'))[0]['amount']);

        // A query that fails gives 0
        Db::query("UPDATE budget_insight SET `sql` = 'SELECT SUM(nothing) AS amount FROM bank_transaction' WHERE id = 1");
        $this->assertSame(0.0, TrendChart::series('insight', 1, 1)[0]['amount']);
    }

    public function testSeriesErrors()
    {
        $this->assertSame(404, $this->error(fn() => TrendChart::series('category', 99)));
        $this->assertSame(404, $this->error(fn() => TrendChart::series('insight', 99)));
        $this->assertSame(400, $this->error(fn() => TrendChart::series('budget', 1)));
        $this->assertSame(400, $this->error(fn() => TrendChart::series('category', 0)));
        $this->assertSame(400, $this->error(fn() => TrendChart::series('category', 'abc')));

        // Through the API, as a user
        $this->loginAs(self::USER);
        $this->assertSame(400, $this->error(fn() => $this->callRoute('GET', '/budget/trends/series', ['type' => 'budget', 'id' => '1'])));
        $series = json_decode($this->callRoute('GET', '/budget/trends/series', ['type' => 'category', 'id' => '1', 'months' => '2']), true);
        $this->assertSame([date('Y-m', strtotime(date('Y-m-01') . ' -1 month')), date('Y-m')], array_column($series, 'month'));
        $this->assertEquals(80, $series[1]['amount']);
        $this->assertEquals(200, $series[1]['planned']);
    }

    public function testCategoriesRollUpTheSubCategories()
    {
        // Alimentation: Supermarché -80 this month, plus -20 in Alimentation itself
        $this->addTransaction('t1', 1, -20);
        $result = TrendChart::categories(1);

        $this->assertSame([date('Y-m')], $result['months']);
        $this->assertSame([
            ['id' => 1, 'name' => 'Alimentation', 'icon' => 'restaurant', 'color' => 'green', 'parent_category' => 0, 'total' => 100.0, 'amounts' => [100.0]],
            ['id' => 3, 'name' => 'Énergie', 'icon' => 'bolt', 'color' => 'yellow', 'parent_category' => 0, 'total' => 50.0, 'amounts' => [50.0]],
        ], $result['categories']);

        // A sub-category counts for its parent whatever its own type
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (5, 'Remboursements', 1, 'CREDIT', 'undo', 'green')");
        $this->addTransaction('t2', 5, 15);
        $this->assertSame([[1, 85.0], [3, 50.0]], array_map(fn($c) => [$c['id'], $c['total']], TrendChart::categories(1)['categories']));
        // ... so it is not an income at level top, but it is at level sub
        $this->assertSame([4], array_column(TrendChart::categories(1, 0, 'CREDIT')['categories'], 'id'));
        $this->assertSame([[4, 2000.0], [5, 15.0]], array_map(fn($c) => [$c['id'], $c['total']], TrendChart::categories(1, 0, 'CREDIT', 'sub')['categories']));
    }

    public function testCategoriesSubLevel()
    {
        $this->addTransaction('t1', 1, -20);
        $categories = TrendChart::categories(3, 0, 'DEBIT', 'sub')['categories'];

        // Each category with its own transactions, the largest total first
        $this->assertSame([2, 3, 1], array_column($categories, 'id'));
        $this->assertSame([80.0, 50.0, 20.0], array_column($categories, 'total'));
        $this->assertSame([1, 0, 0], array_column($categories, 'parent_category'));
        // Empty months are kept as zero
        $this->assertSame([0.0, 0.0, 80.0], $categories[0]['amounts']);
    }

    public function testCategoriesSigns()
    {
        // An expense refunded counts against the spending; equal totals sorted by name
        $this->addTransaction('t1', 2, 30);
        $this->assertSame([['Alimentation', 50.0], ['Énergie', 50.0]],
            array_map(fn($c) => [$c['name'], $c['total']], TrendChart::categories(1)['categories']));

        // Income: the signed sum
        $this->assertSame([[4, 2000.0]], array_map(fn($c) => [$c['id'], $c['total']], TrendChart::categories(1, 0, 'CREDIT')['categories']));

        // Off-budget: the signed sum too, the uncategorized transactions (category 0, also
        // off-budget) left out
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (5, 'Virements', 0, 'HORS-BUDGET', 'sync_alt', 'grey')");
        $this->addTransaction('t2', 5, -100);
        $this->addTransaction('t3', 5, 40);
        $this->assertSame([[5, -60.0, [-60.0]]],
            array_map(fn($c) => [$c['id'], $c['total'], $c['amounts']], TrendChart::categories(1, 0, 'HORS-BUDGET', 'sub')['categories']));
    }

    public function testCategoriesWithoutAmountAreLeftOut()
    {
        // Transactions that cancel each other out in their month
        $this->addTransaction('t1', 3, 50);
        $this->assertSame([1], array_column(TrendChart::categories(1)['categories'], 'id'));

        // Months without any transaction
        $result = TrendChart::categories(2, 1);
        $this->assertCount(2, $result['months']);
        $this->assertSame([], $result['categories']);
    }

    public function testCategoriesBoundsAndOffset()
    {
        $result = TrendChart::categories(241);
        $this->assertCount(240, $result['months']);
        $this->assertSame(date('Y-m'), $result['months'][239]);
        $this->assertCount(240, $result['categories'][0]['amounts']);
        // The transaction of January 2020 is in a long period
        $alimentation = array_combine($result['months'], $result['categories'][0]['amounts']);
        $this->assertSame(5.0, $alimentation['2020-01']);
        $this->assertSame(85.0, $result['categories'][0]['total']);
        $this->assertCount(1, TrendChart::categories(0)['months']);

        // An offset to January 2020
        $this->assertSame(['months' => ['2020-01'], 'categories' => [
            ['id' => 1, 'name' => 'Alimentation', 'icon' => 'restaurant', 'color' => 'green', 'parent_category' => 0, 'total' => 5.0, 'amounts' => [5.0]],
        ]], TrendChart::categories(1, $this->monthsAgo('2020-01')));

        // The offset is kept up to 240 months
        $last = date('Y-m', strtotime(date('Y-m-01') . ' -240 months'));
        $this->assertSame([$last], TrendChart::categories(1, 241)['months']);
    }

    public function testCategoriesErrors()
    {
        foreach (['debit', 'EXPENSE', ''] as $type) {
            $this->assertSame(400, $this->error(fn() => TrendChart::categories(12, 0, $type)), $type);
        }
        foreach (['TOP', 'all', ''] as $level) {
            $this->assertSame(400, $this->error(fn() => TrendChart::categories(12, 0, 'DEBIT', $level)), $level);
        }

        // Through the API, as a user
        $this->loginAs(self::USER);
        $this->assertSame(400, $this->error(fn() => $this->callRoute('GET', '/budget/trends/categories', ['level' => 'all'])));
        $result = json_decode($this->callRoute('GET', '/budget/trends/categories', ['type' => 'DEBIT', 'level' => 'sub', 'months' => '2']), true);
        $this->assertSame([date('Y-m', strtotime(date('Y-m-01') . ' -1 month')), date('Y-m')], $result['months']);
        $this->assertSame([2, 3], array_column($result['categories'], 'id'));
        $this->assertEquals([0, 80], $result['categories'][0]['amounts']);
    }

    public function testSaveAndGetCharts()
    {
        $this->assertSame([], TrendChart::get());

        $charts = TrendChart::save([['type' => 'category', 'id' => 2], ['type' => 'insight', 'id' => '1'], ['type' => 'category', 'id' => 1]]);
        $this->assertSame([
            ['type' => 'category', 'id' => 2, 'name' => 'Supermarché', 'icon' => 'shopping_cart', 'color' => 'green'],
            ['type' => 'insight', 'id' => 1, 'name' => 'Dépenses', 'icon' => null, 'color' => 'red'],
            ['type' => 'category', 'id' => 1, 'name' => 'Alimentation', 'icon' => 'restaurant', 'color' => 'green'],
        ], $charts);
        $this->assertSame($charts, TrendChart::get());

        // Every user sees them
        $this->loginAs(self::USER);
        $this->assertSame([2, 1, 1], array_column(json_decode($this->callRoute('GET', '/budget/trends/charts'), true), 'id'));

        // An empty list removes them all
        $this->assertSame([], TrendChart::save([]));
        $this->assertSame([], TrendChart::get());
    }

    public function testInvalidCharts()
    {
        foreach ([
            'not a list' => [1 => ['type' => 'category', 'id' => 1]],
            'not an object' => ['category'],
            'no type' => [['id' => 1]],
            'unknown type' => [['type' => 'budget', 'id' => 1]],
            'no id' => [['type' => 'category']],
            'zero id' => [['type' => 'category', 'id' => 0]],
            'negative id' => [['type' => 'category', 'id' => -1]],
            'text id' => [['type' => 'category', 'id' => 'abc']],
            'decimal id' => [['type' => 'category', 'id' => 1.5]],
            'unknown category' => [['type' => 'category', 'id' => 99]],
            'unknown insight' => [['type' => 'insight', 'id' => 99]],
            'duplicate' => [['type' => 'category', 'id' => 1], ['type' => 'category', 'id' => '1']],
        ] as $case => $charts) {
            $this->assertSame(400, $this->error(fn() => TrendChart::save($charts)), $case);
        }
        // Nothing was saved
        $this->assertSame([], TrendChart::get());
    }

    public function testAtMostTwelveCharts()
    {
        // The longest ids: the list still fits in the setting (255 characters)
        $charts = [];
        for ($id = 4294967284; $id <= 4294967295; $id++) {
            Db::execute("INSERT INTO budget_insight (id, name, color, `sql`) VALUES (?, 'Insight', 'red', 'SELECT 1 AS amount')", "i", $id);
            $charts[] = ['type' => 'insight', 'id' => $id];
        }
        $this->assertCount(12, TrendChart::save($charts));
        $this->assertSame(4294967295, TrendChart::get()[11]['id']);
        $this->assertLessThanOrEqual(255, strlen(Setting::get('trend_charts')));

        $charts[] = ['type' => 'category', 'id' => 1];
        $this->assertSame(400, $this->error(fn() => TrendChart::save($charts)));
        $this->assertCount(12, TrendChart::get());
    }

    public function testDeletedTargetsAreLeftOut()
    {
        Db::query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (6, 'Vacances', 0, 'DEBIT', 'flight', 'blue')");
        TrendChart::save([['type' => 'category', 'id' => 6], ['type' => 'insight', 'id' => 1], ['type' => 'category', 'id' => 4]]);
        Db::query("DELETE FROM bank_transaction_category WHERE id = 6");
        Db::query("DELETE FROM budget_insight WHERE id = 1");

        $this->assertSame([['type' => 'category', 'id' => 4, 'name' => 'Salaire', 'icon' => 'payments', 'color' => 'blue']], TrendChart::get());

        // An unreadable setting gives no chart, unreadable entries are skipped
        Setting::set('trend_charts', 'not json');
        $this->assertSame([], TrendChart::get());
        Setting::set('trend_charts', '["c4", "x1", "c0", 5, "i", "c04"]');
        $this->assertSame([4], array_column(TrendChart::get(), 'id'));
    }

    public function testOnlyAdministratorsSaveCharts()
    {
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['PUT /budget/trends/charts']);
        $this->assertSame([], TrendChart::get());
    }
}
