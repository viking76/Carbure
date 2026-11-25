<?php

use Carbure\Lib\SpendingAnalysis;
use Carbure\Resources\Transaction;
use PHPUnit\Framework\TestCase;

/**
 * Analyses of the MCP server (#496), on a household statement of six months: the questions
 * of the test bench of the issue (most expensive month without the rent and the savings,
 * subscriptions, price increase, payment charged twice, largest one-off expense, yearly
 * payment, average spending at the restaurant, balance of the period).
 */
class SpendingAnalysisTest extends TestCase
{
    /** Categories: Logement, Alimentation (Courses, Restaurant), Abonnements, Salaire, Épargne */
    private const CATEGORIES = [
        ['id' => 0, 'name' => 'Non catégorisé', 'parent_category' => 0, 'type' => 'HORS-BUDGET'],
        ['id' => 1, 'name' => 'Logement', 'parent_category' => 0, 'type' => 'DEBIT'],
        ['id' => 2, 'name' => 'Alimentation', 'parent_category' => 0, 'type' => 'DEBIT'],
        ['id' => 3, 'name' => 'Courses', 'parent_category' => 2, 'type' => 'DEBIT'],
        ['id' => 4, 'name' => 'Restaurant', 'parent_category' => 2, 'type' => 'DEBIT'],
        ['id' => 5, 'name' => 'Abonnements', 'parent_category' => 0, 'type' => 'DEBIT'],
        ['id' => 6, 'name' => 'Salaire', 'parent_category' => 0, 'type' => 'CREDIT'],
        ['id' => 7, 'name' => 'Épargne', 'parent_category' => 0, 'type' => 'HORS-BUDGET'],
    ];

    private const NAMES = [0 => 'Non catégorisé', 1 => 'Logement', 2 => 'Alimentation', 3 => 'Alimentation › Courses',
        4 => 'Alimentation › Restaurant', 5 => 'Abonnements', 6 => 'Salaire', 7 => 'Épargne'];

    private const MONTHS = ['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06'];

    /**
     * Statement of January to June 2026, oldest first: rows as McpAnalysis reads them.
     *
     * @return list<array<string, mixed>>
     */
    private function statement(): array
    {
        $types = array_column(self::CATEGORIES, 'type', 'id');
        $rows = [];
        $add = function (string $date, string $label, float $amount, int $category, int $type = 7) use (&$rows, $types) {
            $rows[] = ['id' => count($rows) + 1, 'date' => $date, 'label' => $label, 'amount' => $amount, 'category' => $category,
                'type' => $types[$category], 'bank_type' => $type, 'account' => '111@bnp'];
        };
        foreach (self::MONTHS as $i => $month) {
            $add("$month-01", 'VIR SALAIRE ACME', 2500, 6, 1);
            $add("$month-03", 'PRLV LOYER AGENCE', -900, 1, 2);
            // Netflix from 13.49 to 15.99 in April
            $add("$month-08", 'CB NETFLIX.COM ' . (10 + $i), $i < 3 ? -13.49 : -15.99, 5);
            $add("$month-12", 'PRLV FREE MOBILE', -19.99, 5, 2);
            $add("$month-10", 'CB CARREFOUR', -150 - 10 * $i, 3);
            $add("$month-25", 'VIR LIVRET A', -300, 7, 1);
        }
        // Restaurant in four months: 240 in all
        foreach (['2026-01-17' => -60, '2026-02-14' => -45, '2026-04-11' => -80, '2026-06-20' => -55] as $date => $amount) {
            $add($date, 'CB RESTAURANT ' . substr($date, 5, 2), $amount, 4);
        }
        // March: a laptop (the largest one-off expense); May: the yearly home insurance
        $add('2026-03-15', 'CB FNAC LAPTOP', -1200, 3);
        $add('2026-05-05', 'PRLV ASSURANCE HABITATION', -380, 1, 2);
        // June: the electricity bill charged twice
        $add('2026-06-18', 'PRLV EDF', -95.40, 1, 2);
        $add('2026-06-18', 'PRLV EDF', -95.40, 1, 2);
        usort($rows, fn($a, $b) => strcmp($a['date'], $b['date']) ?: $a['id'] <=> $b['id']);
        return $rows;
    }

    public function testPeriod()
    {
        $this->assertSame(['from' => '2025-07', 'to' => '2026-06', 'start' => '2025-07-01', 'end' => '2026-07-01'],
            SpendingAnalysis::period(null, null, '2026-06'));
        $this->assertSame(['from' => '2026-01', 'to' => '2026-06', 'start' => '2026-01-01', 'end' => '2026-07-01'],
            SpendingAnalysis::period('2026-01', '2026-06', '2026-11'));
        // Across a year, and a single month
        $this->assertSame('2026-01-01', SpendingAnalysis::period('2025-12', '2025-12', '2026-11')['end']);
        $this->assertSame('2025-01', SpendingAnalysis::period('', '2025-12', '2026-11')['from']);
    }

    public function testInvalidPeriods()
    {
        foreach ([['2026-13', null], ['2026-1', null], [null, 202606], [['2026-01'], null], ['2026-06', '2026-01'], ['2010-01', '2026-06']] as [$from, $to]) {
            try {
                SpendingAnalysis::period($from, $to, '2026-06');
                $this->fail('Accepted: ' . json_encode([$from, $to]));
            } catch (Error $e) {
                $this->assertSame(400, $e->getCode());
            }
        }
        // 120 months at most
        $this->assertSame('2017-01', SpendingAnalysis::period('2017-01', '2026-12', '2026-12')['from']);
    }

    public function testMonths()
    {
        $this->assertSame(['2025-11', '2025-12', '2026-01'], SpendingAnalysis::months('2025-11', '2026-01'));
        $this->assertSame([], SpendingAnalysis::months('2026-02', '2026-01'));
    }

    public function testCategoryFilter()
    {
        $this->assertNull(SpendingAnalysis::categoryFilter(self::CATEGORIES, null, []));
        // A parent with its sub-categories
        $this->assertEqualsCanonicalizing([2, 3, 4], SpendingAnalysis::categoryFilter(self::CATEGORIES, [2], []));
        // Everything but the rent and the food
        $this->assertEqualsCanonicalizing([0, 5, 6, 7], SpendingAnalysis::categoryFilter(self::CATEGORIES, null, [1, 2]));
        // A sub-category left out of its parent
        $this->assertEqualsCanonicalizing([2, 3], SpendingAnalysis::categoryFilter(self::CATEGORIES, [2], [4]));
    }

    public function testUncategorizedIsNotTheParentOfTheTopLevelCategories()
    {
        // Category 0 has the parent 0, as every top-level category: only itself
        $this->assertSame([0], SpendingAnalysis::categoryFilter(self::CATEGORIES, [0], []));
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5, 6, 7], SpendingAnalysis::categoryFilter(self::CATEGORIES, null, [0]));
    }

    public function testAccountName()
    {
        $this->assertSame('Compte joint', SpendingAnalysis::accountName('bnp', 'FR7630004000031234567890143', ' Compte joint '));
        $this->assertSame('bnp ···0143', SpendingAnalysis::accountName('bnp', 'FR7630004000031234567890143', null));
        $this->assertSame('manual', SpendingAnalysis::accountName('manual', '', ''));
        $this->assertNull(SpendingAnalysis::accountName(null, null, null));
    }

    public function testDuplicatesAreCapped()
    {
        $rows = [];
        for ($i = 0; $i < 2 * (SpendingAnalysis::MAX_RESULTS + 5); $i++) {
            $rows[] = ['date' => '2026-06-01', 'label' => 'CB ' . intdiv($i, 2), 'amount' => -1, 'category' => 0, 'account' => 'a'];
        }
        $this->assertCount(SpendingAnalysis::MAX_RESULTS, SpendingAnalysis::duplicates($rows, self::NAMES));
    }

    public function testShift()
    {
        $this->assertSame('2026-01', SpendingAnalysis::shift('2025-08', 5));
        $this->assertSame('2025-12', SpendingAnalysis::shift('2026-01', -1));
    }

    public function testUnknownCategoryGivesTheValidIds()
    {
        try {
            SpendingAnalysis::categoryFilter(self::CATEGORIES, [2], [42]);
            $this->fail('An unknown category was accepted');
        } catch (Error $e) {
            $this->assertSame(400, $e->getCode());
            $this->assertStringContainsString('Unknown category id(s): 42', $e->getMessage());
            $this->assertStringContainsString('Valid ids: 0, 1, 2, 3, 4, 5, 6, 7', $e->getMessage());
        }
    }

    public function testSpendingByMonth()
    {
        $result = SpendingAnalysis::byMonth($this->statement(), self::MONTHS, null);

        $this->assertSame(self::MONTHS, array_column($result['months'], 'month'));
        // January: rent 900 + Netflix 13.49 + Free 19.99 + groceries 150 + restaurant 60; the savings are off-budget
        $this->assertSame(['month' => '2026-01', 'expenses' => 1143.48, 'income' => 2500.0, 'balance' => 1356.52], $result['months'][0]);
        // March and its laptop
        $this->assertSame(['month' => '2026-03', 'expenses' => 2303.48], $result['highest']);
        $this->assertSame(15000.0, $result['total']['income']);
        $this->assertSame(round($result['total']['expenses'] / 6, 2), $result['average']['expenses']);
        $this->assertSame(round(15000 - $result['total']['expenses'], 2), $result['total']['balance']);
    }

    public function testMostExpensiveMonthWithoutTheRent()
    {
        $keep = SpendingAnalysis::categoryFilter(self::CATEGORIES, null, [1]);
        $result = SpendingAnalysis::byMonth($this->statement(), self::MONTHS, $keep);
        $this->assertSame('2026-03', $result['highest']['month']);
        // Without the rent, the insurance and the electricity: 13.49 + 19.99 + 150 + 60 in January
        $this->assertSame(243.48, $result['months'][0]['expenses']);
    }

    public function testAverageAtTheRestaurant()
    {
        $keep = SpendingAnalysis::categoryFilter(self::CATEGORIES, [4], []);
        $result = SpendingAnalysis::byMonth($this->statement(), self::MONTHS, $keep);
        $this->assertSame(240.0, $result['total']['expenses']);
        $this->assertSame(40.0, $result['average']['expenses']);
        $this->assertSame(0.0, $result['months'][2]['expenses']);
        $this->assertSame(0.0, $result['total']['income']);
    }

    public function testNothingSpent()
    {
        $result = SpendingAnalysis::byMonth([], ['2026-01'], null);
        $this->assertNull($result['highest']);
        $this->assertSame(['expenses' => 0.0, 'income' => 0.0, 'balance' => 0.0], $result['total']);
        // Outside the period: not counted
        $this->assertSame(0.0, SpendingAnalysis::byMonth($this->statement(), ['2025-12'], null)['total']['income']);
    }

    public function testSeriesKey()
    {
        $this->assertSame('-CB NETFLIX COM', SpendingAnalysis::seriesKey('CB NETFLIX.COM 12', -13.49));
        $this->assertSame('+VIR SALAIRE ACME', SpendingAnalysis::seriesKey('VIR SALAIRE ACME 03/2026', '2500.00'));
        $this->assertSame('', SpendingAnalysis::seriesKey('123 456', -5));
    }

    public function testRecurringWithThePriceIncrease()
    {
        $rows = array_map(fn($row) => ['type' => $row['bank_type']] + $row, $this->statement());
        $series = SpendingAnalysis::recurringHistory(Transaction::findRecurring($rows, '2026-06-01'), $rows, self::NAMES);
        $byLabel = array_column($series, null, 'label');

        // Subscriptions, rent, salary, savings; not the card payments whose amount varies (restaurant, groceries)
        $this->assertArrayHasKey('CB NETFLIX.COM 15', $byLabel);
        $this->assertArrayHasKey('PRLV FREE MOBILE', $byLabel);
        $this->assertArrayHasKey('VIR SALAIRE ACME', $byLabel);
        $this->assertArrayNotHasKey('CB RESTAURANT 06', $byLabel);

        $netflix = $byLabel['CB NETFLIX.COM 15'];
        $this->assertSame('Abonnements', $netflix['category']);
        $this->assertSame(8, $netflix['day']);
        $this->assertSame(6, $netflix['months']);
        $this->assertSame(-15.99, $netflix['amount']);
        $this->assertSame(['month' => '2026-01', 'amount' => -13.49], $netflix['history'][0]);
        $this->assertCount(6, $netflix['history']);
        // The increase and its month
        $this->assertSame([['month' => '2026-04', 'from' => -13.49, 'to' => -15.99]], $netflix['changes']);
        $this->assertSame([], $byLabel['PRLV FREE MOBILE']['changes']);
    }

    public function testDuplicates()
    {
        $duplicates = SpendingAnalysis::duplicates($this->statement(), self::NAMES);
        $this->assertSame([['date' => '2026-06-18', 'label' => 'PRLV EDF', 'amount' => -95.4, 'category' => 'Logement',
            'account' => '111@bnp', 'count' => 2]], $duplicates);

        // On two accounts, or two days: not duplicates
        $row = ['date' => '2026-06-18', 'label' => 'CB CAFE', 'amount' => -2.5, 'category' => 0];
        $this->assertSame([], SpendingAnalysis::duplicates([$row + ['account' => 'a'], $row + ['account' => 'b'],
            ['date' => '2026-06-19'] + $row + ['account' => 'a']], self::NAMES));
    }

    public function testOneOffTransactions()
    {
        $list = SpendingAnalysis::oneOff($this->statement(), self::NAMES, 'debit', 3);

        // The laptop, then the yearly insurance; the restaurant and Netflix keep their label once the numbers are left out
        $this->assertSame(['CB FNAC LAPTOP', 'PRLV ASSURANCE HABITATION'], array_column(array_slice($list, 0, 2), 'label'));
        $this->assertSame(['date' => '2026-03-15', 'label' => 'CB FNAC LAPTOP', 'amount' => -1200.0, 'category' => 'Alimentation › Courses'], $list[0]);
        $this->assertCount(2, $list);
        $this->assertNotContains('PRLV EDF', array_column($list, 'label'));

        // The salary is recurring: no one-off income; the savings are off-budget
        $this->assertSame([], SpendingAnalysis::oneOff($this->statement(), self::NAMES, 'credit', 20));
        $this->assertNotContains('VIR LIVRET A', array_column(SpendingAnalysis::oneOff($this->statement(), self::NAMES, null, 100), 'label'));
        $this->assertCount(1, SpendingAnalysis::oneOff($this->statement(), self::NAMES, null, 1));
    }

    public function testOneOffUnknownKind()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(400);
        SpendingAnalysis::oneOff([], self::NAMES, 'refund', 20);
    }
}
