<?php

use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Setting;
use Carbure\Resources\ApiToken;
use Carbure\Resources\Insight;
use Carbure\Resources\Mcp;

class McpTest extends DatabaseTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::set('mcp_enabled', '1');
        $this->loginAs(self::USER);
        $this->token = ApiToken::create('test')['token'];
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
    }

    /**
     * Send a JSON-RPC request and return the decoded response.
     */
    private function rpc($method, $params = null, $id = 1)
    {
        $response = Mcp::handle('2.0', $method, $id, $params);
        $this->assertNotSame('', $response);
        return json_decode($response, true);
    }

    /**
     * Call a tool and return its decoded data.
     */
    private function tool($name, $arguments = [])
    {
        $response = $this->rpc('tools/call', ['name' => $name, 'arguments' => $arguments]);
        $this->assertFalse($response['result']['isError'], $response['result']['content'][0]['text']);
        return json_decode($response['result']['content'][0]['text'], true);
    }

    public function testInitialize()
    {
        $response = $this->rpc('initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']], 'a1');
        $this->assertSame('a1', $response['id']);
        $this->assertSame('2025-06-18', $response['result']['protocolVersion']);
        $this->assertSame('carbure', $response['result']['serverInfo']['name']);
        $this->assertArrayHasKey('tools', $response['result']['capabilities']);
        $this->assertArrayHasKey('prompts', $response['result']['capabilities']);

        // Unknown version: the most recent one supported
        $this->assertSame('2025-11-25', $this->rpc('initialize', ['protocolVersion' => '1999-01-01'])['result']['protocolVersion']);
    }

    public function testStringIdsAreKept()
    {
        // A numeric string id must not become a number
        $this->assertSame('42', $this->rpc('ping', null, '42')['id']);
    }

    public function testNotificationIsAccepted()
    {
        $this->assertSame('', Mcp::handle('2.0', 'notifications/initialized'));
        $this->assertSame(202, http_response_code());
    }

    public function testToolsList()
    {
        $tools = array_column($this->rpc('tools/list')['result']['tools'], null, 'name');
        $this->assertEqualsCanonicalizing(
            ['list_categories', 'list_transactions', 'search_transactions', 'spending_by_category', 'get_budget', 'get_trends', 'list_rules',
                'get_insight_guide', 'check_insight_query',
                'spending_by_month', 'recurring_transactions', 'duplicate_transactions', 'one_off_transactions'],
            array_keys($tools)
        );
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
            $this->assertSame('object', $tool['inputSchema']['type']);
        }
        // An empty schema is a JSON object, not an array
        $this->assertStringContainsString('"properties":{}', Mcp::handle('2.0', 'tools/list', 1));
    }

    public function testTools()
    {
        $categories = array_column($this->tool('list_categories'), null, 'name');
        $this->assertSame('Alimentation', $categories['Supermarché']['parent']);

        $transactions = $this->tool('list_transactions');
        $this->assertNotEmpty($transactions);
        $this->assertSame(['date', 'label', 'amount', 'category', 'checked', 'comment'], array_keys($transactions[0]));

        $found = $this->tool('search_transactions', ['query' => 'edf']);
        $this->assertNotEmpty($found);
        $this->assertStringContainsString('EDF', $found[0]['label']);
        // Without text: by kind, amount and categories (Énergie and Salaire)
        $this->assertSame(['VIR SALAIRE'], array_column($this->tool('search_transactions', ['kind' => 'credit', 'min' => 1000, 'category_ids' => [3, 4]]), 'label'));

        $spending = $this->tool('spending_by_category');
        $this->assertSame(date('Y-m'), $spending['to']);
        $this->assertNotEmpty($spending['categories']);

        $this->assertNotEmpty($this->tool('get_budget'));
        $this->assertCount(3, $this->tool('get_trends', ['months' => 3]));
        $this->assertNotEmpty($this->tool('list_rules'));
    }

    /**
     * Add transactions to the data set: [date, label, amount, category, type, bank_name, account_number].
     */
    private function addTransactions(array $rows)
    {
        foreach ($rows as $i => [$date, $label, $amount, $category, $type, $bank, $account]) {
            Db::execute("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user, bank_name, account_number)
                VALUES (?, ?, ?, ?, ?, ?, ?, '', 0, 1, ?, ?)", "sssisidss", md5("mcp$i" . $date . $label . $amount), $date, $date, $type, $label,
                $category, $amount, $bank, $account);
        }
    }

    /** First day of a month before the current one (Y-m-d) */
    private function monthsAgo(int $months, int $day = 1)
    {
        return date('Y-m-d', strtotime(date('Y-m-01') . " -$months months +" . ($day - 1) . " days"));
    }

    public function testSpendingByMonth()
    {
        $month = date('Y-m');
        $result = $this->tool('spending_by_month', ['from' => $month, 'to' => $month]);
        $this->assertSame(['from' => $month, 'to' => $month], ['from' => $result['from'], 'to' => $result['to']]);
        // Data set: groceries 80, electricity 50, salary 2000; the uncategorized one (off-budget) is left out
        $this->assertEquals(['month' => $month, 'expenses' => 130, 'income' => 2000, 'balance' => 1870], $result['months'][0]);
        $this->assertEquals(['month' => $month, 'expenses' => 130], $result['highest']);

        // Food only (Alimentation and its sub-category Supermarché), then everything but the food
        $this->assertEquals(80, $this->tool('spending_by_month', ['from' => $month, 'category_ids' => [1]])['total']['expenses']);
        $this->assertEquals(50, $this->tool('spending_by_month', ['from' => $month, 'exclude_category_ids' => [1]])['total']['expenses']);

        // Last 12 months by default, months without transactions at 0
        $year = $this->tool('spending_by_month');
        $this->assertCount(12, $year['months']);
        $this->assertSame($month, $year['to']);
        $this->assertEquals(0, $year['months'][0]['expenses']);
    }

    public function testSpendingByMonthWithAnUnknownCategory()
    {
        $response = $this->rpc('tools/call', ['name' => 'spending_by_month', 'arguments' => ['category_ids' => [99]]]);
        $this->assertTrue($response['result']['isError']);
        // The model is given the valid ids to correct itself
        $this->assertStringContainsString('Valid ids: 0, 1, 2, 3, 4', $response['result']['content'][0]['text']);

        foreach ([['category_ids' => 'food'], ['from' => '2026-13'], ['from' => '2026-06', 'to' => '2026-01']] as $arguments) {
            $this->assertTrue($this->rpc('tools/call', ['name' => 'spending_by_month', 'arguments' => $arguments])['result']['isError']);
        }
    }

    public function testRecurringTransactions()
    {
        // A subscription of four months whose price goes up two months ago
        $rows = [];
        foreach ([3 => -13.49, 2 => -15.99, 1 => -15.99, 0 => -15.99] as $ago => $amount) {
            $rows[] = [$this->monthsAgo($ago, 8), 'CB NETFLIX.COM ' . (20 + $ago), $amount, 0, 7, 'bnp', '111'];
        }
        $this->addTransactions($rows);

        $result = $this->tool('recurring_transactions', ['from' => substr($this->monthsAgo(5), 0, 7)]);
        $netflix = array_column($result['series'], null, 'label')['CB NETFLIX.COM 20'];
        $this->assertSame(8, $netflix['day']);
        $this->assertSame(4, $netflix['months']);
        $this->assertSame('Aucune', $netflix['category']);
        $this->assertCount(4, $netflix['history']);
        $this->assertEquals([['month' => substr($this->monthsAgo(2), 0, 7), 'from' => -13.49, 'to' => -15.99]], $netflix['changes']);

        // A card subscription whose price rose by more than 20 % over two years: detected on the last months,
        // its history over the whole period
        $rows = [];
        for ($ago = 23; $ago >= 0; $ago--) {
            $rows[] = [$this->monthsAgo($ago, 12), 'CB SPOTIFY', $ago >= 12 ? -9.99 : -12.99, 0, 7, 'bnp', '111'];
        }
        $this->addTransactions($rows);
        $spotify = array_column($this->tool('recurring_transactions', ['from' => substr($this->monthsAgo(23), 0, 7)])['series'], null, 'label')['CB SPOTIFY'];
        $this->assertCount(24, $spotify['history']);
        $this->assertEquals([['month' => substr($this->monthsAgo(11), 0, 7), 'from' => -9.99, 'to' => -12.99]], $spotify['changes']);

        // Not running anymore at the end of the period: not given
        $this->assertSame([], $this->tool('recurring_transactions', ['from' => substr($this->monthsAgo(3), 0, 7), 'to' => substr($this->monthsAgo(3), 0, 7)])['series']);
    }

    public function testDuplicateTransactions()
    {
        $day = $this->monthsAgo(0, 4);
        $this->addTransactions([
            [$day, 'PRLV ASSURANCE', -42.10, 3, 2, 'bnp', '111'],
            [$day, 'PRLV ASSURANCE', -42.10, 3, 2, 'bnp', '111'],
            // Same payment on another account: not a duplicate
            [$day, 'PRLV ASSURANCE', -42.10, 3, 2, 'bank', 'fail'],
        ]);

        $duplicates = $this->tool('duplicate_transactions')['duplicates'];
        $this->assertEquals([['date' => $day, 'label' => 'PRLV ASSURANCE', 'amount' => -42.1, 'category' => 'Énergie',
            'account' => 'bnp ···111', 'count' => 2]], $duplicates);
        // The account by its name, never its whole number
        Db::query("UPDATE bank_account SET label = 'Compte joint' WHERE bank_name = 'bnp'");
        $this->assertSame('Compte joint', $this->tool('duplicate_transactions')['duplicates'][0]['account']);
    }

    public function testOneOffTransactions()
    {
        $this->addTransactions([[$this->monthsAgo(2, 15), 'CB FNAC LAPTOP 4521', -1200, 2, 7, 'bnp', '111']]);

        $result = $this->tool('one_off_transactions', ['from' => substr($this->monthsAgo(2), 0, 7), 'kind' => 'debit', 'limit' => 2]);
        $this->assertCount(2, $result['transactions']);
        $this->assertEquals(['date' => $this->monthsAgo(2, 15), 'label' => 'CB FNAC LAPTOP 4521', 'amount' => -1200,
            'category' => 'Alimentation › Supermarché'], $result['transactions'][0]);
        // The salary is the only income of the period, once
        $this->assertSame(['VIR SALAIRE'], array_column($this->tool('one_off_transactions', ['kind' => 'credit'])['transactions'], 'label'));

        $this->assertTrue($this->rpc('tools/call', ['name' => 'one_off_transactions', 'arguments' => ['kind' => 'refund']])['result']['isError']);
    }

    public function testInsightGuide()
    {
        $guide = $this->tool('get_insight_guide');
        $this->assertSame(Insight::COLORS, $guide['colors']);
        $this->assertArrayHasKey('bank_transaction', $guide['tables']);
        // Only the tables an insight can read are described
        $this->assertEqualsCanonicalizing(Insight::READABLE_TABLES, array_keys($guide['tables']));
        $this->assertNotEmpty($guide['insights']);
        // The queries of the insights are for the administrators
        $this->assertArrayNotHasKey('sql', $guide['insights'][0]);
        $this->loginAs(self::ADMIN);
        $this->assertArrayHasKey('sql', $this->tool('get_insight_guide')['insights'][0]);

        // Its examples follow the rules of the portal
        foreach ($guide['examples'] as $example) {
            $this->assertLessThanOrEqual(20, mb_strlen($example['name']), $example['name']);
            $this->assertContains($example['color'], Insight::COLORS);
            $this->assertTrue(Insight::check($example['sql'])['valid'], $example['name']);
        }
    }

    public function testCheckInsightQuery()
    {
        $sql = 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE MONTH(date) = {month} AND YEAR(date) = {year}';

        // Administrators only, as POST /insight/check
        $response = $this->rpc('tools/call', ['name' => 'check_insight_query', 'arguments' => ['sql' => $sql]]);
        $this->assertTrue($response['result']['isError']);

        $this->loginAs(self::ADMIN);
        $check = $this->tool('check_insight_query', ['sql' => $sql, 'months' => 3]);
        $this->assertTrue($check['valid']);
        $this->assertCount(3, $check['amounts']);
        // Oldest first, ending with the current month
        $this->assertSame(date('Y-m'), $check['amounts'][2]['month']);
        $this->assertEquals(Insight::check($sql)['amount'], $check['amounts'][2]['amount']);

        // An invalid query: the error of the portal, to fix (not a tool error)
        $check = $this->tool('check_insight_query', ['sql' => 'SELECT password AS amount FROM users']);
        $this->assertFalse($check['valid']);
        $this->assertNotEmpty($check['error']);
        $this->assertArrayHasKey('error_code', $check);
        $check = $this->tool('check_insight_query', ['sql' => 'SELECT 1 FROM bank_transaction']);
        $this->assertSame('insight_query_no_amount', $check['error_code']);

        // Invalid arguments: tool errors
        foreach ([['sql' => ''], ['sql' => $sql, 'months' => 7], ['sql' => $sql, 'month' => 13]] as $arguments) {
            $response = $this->rpc('tools/call', ['name' => 'check_insight_query', 'arguments' => $arguments]);
            $this->assertTrue($response['result']['isError'], json_encode($arguments));
        }
    }

    public function testProposeInsightPrompt()
    {
        $prompts = $this->rpc('prompts/list')['result']['prompts'];
        $this->assertSame(['propose_insight'], array_column($prompts, 'name'));
        $this->assertTrue($prompts[0]['arguments'][0]['required']);

        $prompt = $this->rpc('prompts/get', ['name' => 'propose_insight', 'arguments' => ['need' => 'mes restaurants le week-end']])['result'];
        $this->assertSame('user', $prompt['messages'][0]['role']);
        $text = $prompt['messages'][0]['content']['text'];
        $this->assertStringContainsString('mes restaurants le week-end', $text);
        $this->assertStringContainsString('get_insight_guide', $text);
        $this->assertStringContainsString('check_insight_query', $text);

        // Unknown prompt, missing need: JSON-RPC errors
        $this->assertSame(-32602, $this->rpc('prompts/get', ['name' => 'drop_database'])['error']['code']);
        $this->assertSame(-32602, $this->rpc('prompts/get', ['name' => 'propose_insight', 'arguments' => []])['error']['code']);
    }

    public function testToolErrors()
    {
        // Invalid argument: a tool error the model can correct
        $response = $this->rpc('tools/call', ['name' => 'search_transactions', 'arguments' => ['query' => 'a']]);
        $this->assertTrue($response['result']['isError']);
        $response = $this->rpc('tools/call', ['name' => 'search_transactions', 'arguments' => []]);
        $this->assertTrue($response['result']['isError']);

        $response = $this->rpc('tools/call', ['name' => 'spending_by_category', 'arguments' => ['from' => '2026-13']]);
        $this->assertTrue($response['result']['isError']);

        // Unknown tool and method: JSON-RPC errors
        $this->assertSame(-32602, $this->rpc('tools/call', ['name' => 'drop_database'])['error']['code']);
        $this->assertSame(-32601, $this->rpc('resources/list')['error']['code']);
        $this->assertSame(-32600, json_decode(Mcp::handle('1.0', 'ping', 1), true)['error']['code']);
    }

    public function testAuthentication()
    {
        // A JWT is also accepted
        $this->loginAs(self::ADMIN);
        $this->assertArrayHasKey('result', $this->rpc('ping'));

        foreach ([null, 'Bearer cbt_revoked', 'Bearer not-a-jwt'] as $header) {
            Jwt::actAs(null);
            if ($header === null) {
                unset($_SERVER['HTTP_AUTHORIZATION']);
            } else {
                $_SERVER['HTTP_AUTHORIZATION'] = $header;
            }
            try {
                Mcp::handle('2.0', 'ping', 1);
                $this->fail('Accepted ' . var_export($header, true));
            } catch (Error $e) {
                $this->assertSame(401, $e->getCode());
            }
        }
    }

    public function testOtherOriginIsRefused()
    {
        $_SERVER['HTTP_HOST'] = 'carbure.home';
        $_SERVER['HTTP_ORIGIN'] = 'https://carbure.home';
        $this->assertArrayHasKey('result', $this->rpc('ping'));

        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $this->expectException(Error::class);
        $this->expectExceptionCode(403);
        Mcp::handle('2.0', 'ping', 1);
    }

    public function testDisabledByDefaultAndSwitchedByAnAdministrator()
    {
        Db::query("DELETE FROM settings");
        $this->assertFalse(Mcp::settings()['enabled']);
        try {
            Mcp::handle('2.0', 'ping', 1);
            $this->fail('Answered while disabled');
        } catch (Error $e) {
            $this->assertSame(403, $e->getCode());
        }

        // A user cannot enable it
        $this->loginAs(self::USER);
        $this->assertAdminOnly(['PUT /mcp/settings']);
        $this->assertFalse(Mcp::settings()['enabled']);

        $this->loginAs(self::ADMIN);
        $this->assertTrue(Mcp::updateSettings(true)['enabled']);
        $this->assertTrue(Mcp::settings()['enabled']);
        $this->assertFalse(Mcp::updateSettings('false')['enabled']);
    }

    public function testTokenInTheUrl()
    {
        // For the agents that cannot send an Authorization header
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $response = json_decode(Mcp::handle('2.0', 'ping', 1, null, null, null, $this->token), true);
        $this->assertArrayHasKey('result', $response);
    }

    public function testGetIsNotAllowed()
    {
        $this->expectException(Error::class);
        $this->expectExceptionCode(405);
        Mcp::stream();
    }
}
