<?php

/**
 * Mcp.php
 *
 * MCP server (Model Context Protocol, Streamable HTTP transport) to let Claude
 * analyse the household data: JSON-RPC 2.0 messages on POST /api/mcp, one JSON
 * response per request, no session. The tools are read-only: the agent can also
 * propose insights from a need in natural language (prompt propose_insight, tools
 * get_insight_guide and check_insight_query), added by an administrator in the portal.
 *
 * Authentication: "Authorization: Bearer <API token>" (created in the AI agents
 * tab of the portal, or obtained with OAuth 2.1 by the agents that only take an
 * URL, see OAuth.php), the token in ?token= for the agents that cannot send a
 * header, or a JWT. An administrator enables or disables the server.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;
use Carbure\Lib\Db;
use Carbure\Lib\Jwt;
use Carbure\Lib\Month;
use Carbure\Lib\Setting;
use Carbure\Lib\Webservice;
use Error;
use stdClass;

final class Mcp {

    /**
     * Protocol versions supported, most recent first
     */
    private const PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * Version of the server
     */
    private const SERVER_VERSION = '1.2.0';

    /**
     * Web sites allowed to call the server from a browser, besides the server itself:
     * the web clients of Claude
     */
    private const ALLOWED_ORIGINS = ['claude.ai', 'claude.com'];

    /**
     * JSON-RPC error codes
     */
    private const INVALID_REQUEST = -32600;
    private const METHOD_NOT_FOUND = -32601;
    private const INVALID_PARAMS = -32602;

    /**
     * Most months a query is checked on by check_insight_query (each run has a time limit)
     */
    private const CHECK_MAX_MONTHS = 6;

    /**
     * Handle a JSON-RPC message of an MCP client.
     *
     * The members of the message are those sent by the client, of any JSON type: they
     * are checked here (an invalid one gets a JSON-RPC error, not an HTTP one).
     *
     * @param mixed       $jsonrpc Always "2.0"
     * @param mixed       $method  The method (initialize, tools/list, tools/call...)
     * @param mixed       $id      The request id: int or string (none for a notification)
     * @param mixed       $params  The parameters of the method (an object)
     * @param mixed       $result  Response of the client to a server request (unused)
     * @param mixed       $error   Error of the client to a server request (unused)
     * @param string|null $token   API token, for the agents that cannot send a header
     * @return string The JSON-RPC response, or '' (HTTP 202) for a notification
     * @throws Error If disabled (403), unauthenticated (401) or from another site (403)
     */
    #[ApiRoute('/mcp', method: 'POST', public: true, raw: true, changes: [])]
    public static function handle(mixed $jsonrpc = null, mixed $method = null, mixed $id = null, mixed $params = null, mixed $result = null, mixed $error = null, ?string $token = null): string
    {
        if (!self::enabled()) {
            throw new Error("The MCP server is disabled: an administrator enables it in the AI agents tab", 403);
        }
        self::checkOrigin();
        self::authenticate($token);

        // Notifications and responses of the client: accepted, nothing to answer
        if ($method === null || $id === null) {
            return self::acknowledge($method, $result, $error);
        }

        if ($jsonrpc !== '2.0' || !is_string($method) || !(is_int($id) || is_string($id))) {
            return self::error($id, self::INVALID_REQUEST, "Invalid JSON-RPC request");
        }
        return self::dispatch($id, $method, is_array($params) ? $params : []);
    }

    /**
     * Answer a message without request: a notification or a response of the client is
     * accepted (HTTP 202, no body), a message with none of them is invalid.
     *
     * @param mixed $method The method of a notification
     * @param mixed $result Response of the client to a server request
     * @param mixed $error  Error of the client to a server request
     * @return string The JSON-RPC error, or '' (HTTP 202)
     */
    private static function acknowledge(mixed $method, mixed $result, mixed $error): string
    {
        if ($method === null && $result === null && $error === null) {
            return self::error(null, self::INVALID_REQUEST, "Invalid JSON-RPC message");
        }
        http_response_code(202);
        return '';
    }

    /**
     * Run a valid JSON-RPC request.
     *
     * @param int|string   $id     The request id
     * @param string       $method The method (initialize, tools/list, tools/call...)
     * @param array<mixed> $params The parameters of the method
     * @return string The JSON-RPC response
     */
    private static function dispatch(int|string $id, string $method, array $params): string
    {
        switch ($method) {
            case 'initialize':
                return self::result($id, self::initialize($params));
            case 'ping':
                return self::result($id, new stdClass());
            case 'tools/list':
                return self::result($id, ['tools' => self::tools()]);
            case 'tools/call':
                return self::callTool($id, $params);
            case 'prompts/list':
                return self::result($id, ['prompts' => self::prompts()]);
            case 'prompts/get':
                return self::getPrompt($id, $params);
            default:
                return self::error($id, self::METHOD_NOT_FOUND, "Method not found: $method");
        }
    }

    /**
     * State of the MCP server, for the AI agents tab.
     *
     * @return array{enabled: bool} enabled
     */
    #[ApiRoute('/mcp/settings', method: 'GET')]
    public static function settings(): array
    {
        return ['enabled' => self::enabled()];
    }

    /**
     * Enable or disable the MCP server (administrators).
     *
     * @param bool|int|string|null $enabled True to enable it (or "true", "1"...)
     * @return array{enabled: bool} enabled
     */
    #[ApiRoute('/mcp/settings', method: 'PUT', admin: true, changes: [])]
    public static function updateSettings(bool|int|string|null $enabled): array
    {
        Setting::set('mcp_enabled', filter_var($enabled, FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        return ['enabled' => self::enabled()];
    }

    /**
     * Check if the MCP server is enabled (disabled until an administrator enables it).
     *
     * @return bool True if enabled
     */
    public static function enabled(): bool
    {
        return Setting::get('mcp_enabled', '0') === '1';
    }

    /**
     * The server does not open SSE streams: GET is not allowed (MCP specification).
     *
     * @return never
     * @throws Error Always (405)
     */
    #[ApiRoute('/mcp', method: 'GET', public: true)]
    public static function stream(): never
    {
        if (!headers_sent()) {
            header('Allow: POST');
        }
        throw new Error("Method not allowed: use POST", 405);
    }

    /**
     * Refuse the requests of another web site (DNS rebinding): an Origin header,
     * sent by browsers, must be the host of the server or a web client of Claude.
     *
     * @return void
     * @throws Error If the origin is another site
     */
    private static function checkOrigin(): void
    {
        $origin = Webservice::getHeader('Origin');
        if ($origin === null || $origin === '' || $origin === 'null') {
            return;
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $host = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
        $allowed = parse_url($origin, PHP_URL_SCHEME) === 'https' && in_array(strtolower((string)$originHost), self::ALLOWED_ORIGINS, true);
        if (!$allowed && (!$originHost || !$host || strcasecmp($originHost, $host) !== 0)) {
            throw new Error("Forbidden origin", 403);
        }
    }

    /**
     * Authenticate the client with an API token or a JWT; the tools then run as
     * this user.
     *
     * @param string|null $queryToken API token given in the URL
     * @return int The user id
     * @throws Error If the token is missing or invalid (401)
     */
    private static function authenticate(?string $queryToken = null): int
    {
        $token = Jwt::getTokenFromHeader() ?: $queryToken;
        $userId = null;
        if ($token) {
            if (str_starts_with($token, ApiToken::PREFIX)) {
                $userId = ApiToken::authenticate($token);
            } else {
                $payload = Jwt::parseJwt($token);
                $userId = $payload === false || !isset($payload->sub) ? null : (int)$payload->sub;
            }
        }

        if ($userId === null) {
            if (!headers_sent()) {
                // Where the agents that only take an URL learn how to get a token (OAuth 2.1)
                header('WWW-Authenticate: ' . OAuth::challenge());
            }
            throw new Error("Unauthorized - Invalid or missing API token", 401);
        }

        Jwt::actAs($userId);
        return $userId;
    }

    /**
     * Answer to initialize: the protocol version and the capabilities of the server.
     *
     * @param array<mixed> $params The parameters of the client
     * @return array<string, mixed> The result
     */
    private static function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        $version = in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0];

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false], 'prompts' => ['listChanged' => false]],
            'serverInfo' => ['name' => 'carbure', 'title' => 'Carbure', 'version' => self::SERVER_VERSION],
            'instructions' => "Carbure holds the bank transactions, budgets and categories of a household (amounts in euros; "
                . "negative amounts are expenses). Use list_categories to know the categories, then the other tools to "
                . "analyse spending, budgets and savings: for a period of months, spending_by_month gives each month and the most "
                . "expensive one, recurring_transactions the subscriptions and their price changes, duplicate_transactions the "
                . "payments charged twice and one_off_transactions the one-off purchases. To propose an insight (a card of the Insights tab computed by an SQL "
                . "query) for a need of the user, read get_insight_guide, then check the query with check_insight_query. "
                . "All the tools are read-only: an administrator adds the insight proposed in the portal.",
        ];
    }

    /**
     * Definition of the tools (JSON Schema of their arguments).
     *
     * @return list<array<string, mixed>> The tools
     */
    private static function tools(): array
    {
        $month = ['type' => 'integer', 'minimum' => 1, 'maximum' => 12, 'description' => 'Month (1-12), current month by default'];
        $year = ['type' => 'integer', 'minimum' => 2000, 'maximum' => 2100, 'description' => 'Year, current year by default'];
        $readOnly = ['readOnlyHint' => true, 'openWorldHint' => false];

        return [
            [
                'name' => 'list_categories',
                'title' => 'Categories',
                'description' => 'List the categories (id, name, parent, type: DEBIT = expense, CREDIT = income, HORS-BUDGET = off-budget such as internal transfers or savings).',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'list_transactions',
                'title' => 'Transactions of a month',
                'description' => 'List the transactions of a month, optionally of one category and its sub-categories.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'month' => $month, 'year' => $year,
                    'category_id' => ['type' => 'integer', 'description' => 'Only this category and its sub-categories'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'search_transactions',
                'title' => 'Search transactions',
                'description' => 'Search the transactions of every period, most recent first: by a text of the label (e.g. a shop or a company) or of the comment written by the household and/or by amount, kind, categories and dates. Give at least one criterion.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 2, 'description' => 'Text to find in the label or the comment'],
                    'min' => ['type' => 'number', 'minimum' => 0, 'description' => 'Smallest amount, without sign (50 finds an expense of -50)'],
                    'max' => ['type' => 'number', 'minimum' => 0, 'description' => 'Largest amount, without sign'],
                    'kind' => ['type' => 'string', 'enum' => ['debit', 'credit'], 'description' => 'debit: expenses only, credit: incomes only'],
                    'category_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Only these categories and their sub-categories'],
                    'from' => ['type' => 'string', 'format' => 'date', 'description' => 'First day (YYYY-MM-DD)'],
                    'to' => ['type' => 'string', 'format' => 'date', 'description' => 'Last day (YYYY-MM-DD)'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Maximum number of transactions (100 by default)'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'spending_by_category',
                'title' => 'Totals by category',
                'description' => 'Total amount and number of transactions per category over a period of months (both included), e.g. to compare spending between categories.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'from' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$', 'description' => 'First month (YYYY-MM), 11 months ago by default'],
                    'to' => ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}$', 'description' => 'Last month (YYYY-MM), current month by default'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_budget',
                'title' => 'Budget of a month',
                'description' => 'Budget of a month: for each top-level category (or the sub-categories of category_id), the amount spent and the budget planned.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'month' => $month, 'year' => $year,
                    'category_id' => ['type' => 'integer', 'description' => 'Parent category to detail (top-level categories by default)'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_trends',
                'title' => 'Monthly trends',
                'description' => 'Monthly totals (oldest first): debit (expenses), credit (income), offBudget, planned budget and savings (net amount moved to the savings category).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 60, 'description' => 'Number of months (12 by default)'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 120, 'description' => 'Months between the current month and the last month of the period (0 by default)'],
                ]],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'list_rules',
                'title' => 'Categorization rules',
                'description' => 'Automatic categorization rules: a transaction whose label contains the keyword (and whose absolute amount equals the amount of the rule, when it has one) gets the category.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'get_insight_guide',
                'title' => 'How to write an insight',
                'description' => 'Everything needed to turn a need into an insight (a card of the Insights tab: name, color, icon and an SQL query returning an amount for a month): the rules of the query, the tables and columns it can read, common expressions, examples and the insights already defined. Read it before writing an insight query.',
                'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'check_insight_query',
                'title' => 'Check an insight query',
                'description' => 'Check an insight query with the rules of the portal and run it read-only (administrators): its amount for a month and the months before it, or the error to fix. Nothing is saved.',
                'inputSchema' => ['type' => 'object', 'required' => ['sql'], 'properties' => [
                    'sql' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500, 'description' => 'The query: one SELECT returning a column named amount, with {month} and {year}'],
                    'month' => $month, 'year' => $year,
                    'months' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::CHECK_MAX_MONTHS, 'description' => 'Number of months to run it on, ending with month/year (1 by default)'],
                ]],
                'annotations' => $readOnly,
            ],
            ...McpAnalysis::tools($readOnly),
        ];
    }

    /**
     * Call a tool: its result is returned as JSON text; an invalid argument is
     * reported as a tool error so that the model can correct it.
     *
     * @param int|string   $id     The request id
     * @param array<mixed> $params name and arguments
     * @return string The JSON-RPC response
     */
    private static function callTool(int|string $id, array $params): string
    {
        $name = $params['name'] ?? null;
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $known = array_column(self::tools(), 'name');
        if (!in_array($name, $known, true)) {
            return self::error($id, self::INVALID_PARAMS, "Unknown tool: " . (is_string($name) ? $name : ''));
        }

        try {
            $data = self::runTool($name, $arguments);
            $text = json_encode(Webservice::normalizeNumbers($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return self::result($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => false]);
        } catch (Error $e) {
            if ($e->getCode() < 400 || $e->getCode() >= 500) {
                throw $e;
            }
            return self::result($id, ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true]);
        }
    }

    /**
     * The tool search_transactions: the criteria of GET /transaction/search.
     *
     * @param array<mixed> $arguments Its arguments
     * @param int          $limit     Maximum number of transactions
     * @return list<array<string, mixed>> The transactions
     * @throws Error If there is no criterion or one is invalid
     */
    private static function searchTransactions(array $arguments, int $limit): array
    {
        $text = fn($key) => isset($arguments[$key]) && is_scalar($arguments[$key]) ? (string)$arguments[$key] : null;
        $categories = is_array($arguments['category_ids'] ?? null)
            ? implode(',', array_map(fn($id) => is_scalar($id) ? (string)$id : '', $arguments['category_ids'])) : null;
        return Transaction::search($text('query'), $limit, $text('min'), $text('max'), $text('kind'), $categories,
            null, $text('from'), $text('to'));
    }

    /**
     * Run a tool.
     *
     * @param string       $name      The tool
     * @param array<mixed> $arguments Its arguments
     * @return mixed The data
     * @throws Error If an argument is invalid
     */
    private static function runTool(string $name, array $arguments): mixed
    {
        $int = fn($key, $default = null) => isset($arguments[$key]) && $arguments[$key] !== '' ? (int)$arguments[$key] : $default;

        switch ($name) {
            case 'list_categories':
                return self::categories();

            case 'list_transactions':
                return self::compact(Transaction::get($int('month'), $int('year'), $int('category_id')));

            case 'search_transactions':
                return self::compact(self::searchTransactions($arguments, $int('limit', 100)));

            case 'spending_by_category':
                return self::spendingByCategory($arguments['from'] ?? null, $arguments['to'] ?? null);

            case 'get_budget':
                return Budget::get($int('month'), $int('year'), $int('category_id', 0));

            case 'get_trends':
                return Budget::getTrends($int('months', 12), $int('offset', 0));

            case 'list_rules':
                return array_map(fn($rule) => ['keyword' => $rule['keyword'], 'category' => $rule['category_name'] ?? $rule['category'] ?? null,
                    'amount' => $rule['amount'] ?? null],
                    Category::getKeywords());
        }
        return self::runInsightTool($name, $arguments);
    }

    /**
     * Run a tool helping to propose an insight, or an analysis tool (McpAnalysis).
     *
     * @param string       $name      The tool
     * @param array<mixed> $arguments Its arguments
     * @return mixed The data
     * @throws Error If an argument is invalid
     */
    private static function runInsightTool(string $name, array $arguments): mixed
    {
        $int = fn($key, $default = null) => isset($arguments[$key]) && $arguments[$key] !== '' ? (int)$arguments[$key] : $default;

        if (in_array($name, McpAnalysis::TOOLS, true)) {
            return McpAnalysis::run($name, $arguments);
        }
        if ($name === 'get_insight_guide') {
            return self::insightGuide();
        }
        if ($name === 'check_insight_query') {
            return self::checkInsightQuery($arguments['sql'] ?? null, $int('month'), $int('year'), $int('months', 1));
        }
        return null;
    }

    /**
     * The tool get_insight_guide: what an agent needs to write an insight query (the
     * rules checked by Insight::validateQuery(), the data, examples of docs/insights.md)
     * and the insights already defined, their query for an administrator.
     *
     * @return array<string, mixed> The guide
     */
    private static function insightGuide(): array
    {
        $admin = User::isAdmin();
        $insights = array_map(fn(array $insight): array => [
            'name' => $insight['name'],
            'color' => $insight['color'],
            'icon' => $insight['icon'],
            'enabled' => (bool)($insight['enabled'] ?? true),
        ] + ($admin ? ['sql' => $insight['sql']] : []), Insight::get());

        return [
            'insight' => 'A card of the Insights tab: a name (1 to 20 characters), a color, an optional Material icon '
                . '(e.g. restaurant, savings, shopping_cart) and an SQL query returning an amount for the month displayed. '
                . 'An administrator adds it in the portal (Insights tab, + button, then "Create an insight").',
            'colors' => Insight::COLORS,
            'query_rules' => [
                'A single SELECT statement of 500 characters at most: no ; inside, no comment (--, #, /* */), no backslash. WITH is refused; a subquery in FROM is accepted.',
                'It returns a column named amount (... AS amount): only the first row is read.',
                '{month} (1 to 12) and {year} are replaced by the month and year displayed, as numbers.',
                'Forbidden words, even inside a quoted text: INSERT, UPDATE, DELETE, REPLACE, CREATE, DROP, ALTER, SET, DO, INTO, LOAD, SLEEP, BENCHMARK, SHOW, DESCRIBE, EXPLAIN, CALL, LOCK, USER, PASSWORD... (so the user column of bank_transaction cannot be read).',
                'Readable tables only, without a database name: ' . implode(', ', Insight::READABLE_TABLES) . '.',
                'Run in a read-only transaction in ' . Insight::$maxSeconds . ' seconds at most, at each display of the Insights tab and twelve times for the history of a year: avoid Cartesian products.',
            ],
            'display' => 'The card shows the absolute value of the amount with two decimals and the currency symbol, in red when negative. '
                . 'A percentage or a count is shown the same way: say it in the name (%, Nb). Negative = money going out. '
                . 'NULL (no transaction) or a failing query shows 0.',
            'tables' => [
                'bank_transaction' => 'date (debit date: the month displayed), rdate (actual date of the payment), type (see transaction_types), label, '
                    . 'category (bank_transaction_category.id, 0 = uncategorized), amount (negative = expense), pointed (1 = checked), '
                    . 'bank_name, account_number, split_of (a part of a split transaction: id of the original one, otherwise NULL)'
                    // The column exists once the migration 2026-11-19_transaction_comment is applied
                    . (Transaction::commentReady() ? ', comment (free text written by the household, NULL if none)' : ''),
                'bank_transaction_category' => 'id, name, parent_category (0 = main category), type: DEBIT (expense), CREDIT (income) or HORS-BUDGET (off-budget: savings, internal transfers)',
                'bank_transaction_category_keyword' => 'keyword, category, notify: categorization rules (a label containing the keyword gets the category)',
                'budget' => 'category, amount (planned, positive), date (first day of the month)',
                'bank_account' => 'bank_name, account_number, label (name given to the account)',
                'bank_backend' => 'name (woob backend), label: bank connections',
                'tag' => 'id, name (without its #), start_date, end_date',
                'transaction_tag' => 'transaction_id (bank_transaction.id), tag_id (tag.id)',
            ],
            'transaction_types' => [0 => 'unknown', 1 => 'transfer', 2 => 'direct debit', 3 => 'check', 4 => 'check deposit',
                5 => 'refund', 6 => 'ATM withdrawal', 7 => 'card', 8 => 'loan repayment', 9 => 'bank fees', 12 => 'deferred debit card'],
            'expressions' => [
                'budget expenses' => "amount < 0 AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)",
                'first day of the month displayed' => 'MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH (- 2 MONTH: the previous month); filter a period with date >= ... AND date < ...',
                'a category by its name' => "(SELECT id FROM bank_transaction_category WHERE name = 'Courses'): numbers change between instances, use the names of list_categories",
                'a category and its sub-categories' => "JOIN bank_transaction_category c ON c.id = t.category WHERE 'Alimentation' IN (c.name, (SELECT p.name FROM bank_transaction_category p WHERE p.id = c.parent_category))",
                'count payments' => 'COUNT(DISTINCT COALESCE(split_of, id)): a split transaction has one row per part, a SUM stays right',
            ],
            'examples' => [
                ['need' => 'How much do I spend on food, sub-categories included?', 'name' => 'Alimentation', 'color' => 'green', 'icon' => 'restaurant',
                    'sql' => "SELECT SUM(t.amount) AS amount FROM bank_transaction t JOIN bank_transaction_category c ON c.id = t.category "
                        . "WHERE 'Alimentation' IN (c.name, (SELECT p.name FROM bank_transaction_category p WHERE p.id = c.parent_category)) "
                        . "AND MONTH(t.date) = {month} AND YEAR(t.date) = {year}"],
                ['need' => 'What do I pay to Amazon?', 'name' => 'Amazon', 'color' => 'orange', 'icon' => 'shopping_cart',
                    'sql' => "SELECT SUM(amount) AS amount FROM bank_transaction WHERE label LIKE '%AMAZON%' AND MONTH(date) = {month} AND YEAR(date) = {year}"],
                ['need' => 'What share of my income do I save?', 'name' => "Taux d'épargne %", 'color' => 'purple', 'icon' => 'savings',
                    'sql' => "SELECT 100 * SUM(amount) / SUM(IF(amount > 0, amount, 0)) AS amount FROM bank_transaction "
                        . "WHERE category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0) "
                        . "AND MONTH(date) = {month} AND YEAR(date) = {year}"],
                ['need' => 'Do I spend more than last month?', 'name' => 'Variation vs M-1 %', 'color' => 'cyan', 'icon' => 'analytics',
                    'sql' => "SELECT 100 * (SUM(IF(MONTH(date) = {month}, amount, 0)) / SUM(IF(MONTH(date) = {month}, 0, amount)) - 1) AS amount "
                        . "FROM bank_transaction WHERE amount < 0 "
                        . "AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0) "
                        . "AND date >= MAKEDATE({year}, 1) + INTERVAL {month} - 2 MONTH AND date < MAKEDATE({year}, 1) + INTERVAL {month} MONTH"],
            ],
            'more_examples' => 'https://carbure.org/insights.en/',
            'insights' => $insights,
        ];
    }

    /**
     * The tool check_insight_query: the checks and the read-only run of POST /insight/check
     * (administrators), on a month and the months before it.
     *
     * @param mixed    $sql    The query, as sent by the client
     * @param int|null $month  The last month (current month by default)
     * @param int|null $year   Its year (current year by default)
     * @param int      $months Number of months
     * @return array<string, mixed> valid, the amount of each month, or the error to fix
     * @throws Error If not an administrator, or an argument is invalid
     */
    private static function checkInsightQuery(mixed $sql, ?int $month, ?int $year, int $months): array
    {
        User::requireAdmin();
        if (!is_string($sql) || trim($sql) === '') {
            throw new Error("Give the query to check (sql)", 400);
        }
        if ($months < 1 || $months > self::CHECK_MAX_MONTHS) {
            throw new Error("months must be between 1 and " . self::CHECK_MAX_MONTHS, 400);
        }
        [$month, $year] = Month::resolve($month, $year);
        if ($month < 1 || $month > 12) {
            throw new Error("month must be between 1 and 12", 400);
        }

        $amounts = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $first = Month::timestamp(sprintf('%04d-%02d-01', $year, $month) . " -$i months");
            $check = Insight::check($sql, (int)date('n', $first), (int)date('Y', $first));
            if (!$check['valid']) {
                // The same error as the portal: the agent fixes the query
                return ['valid' => false, 'month' => date('Y-m', $first)] + array_diff_key($check, ['valid' => true]);
            }
            $amounts[] = ['month' => date('Y-m', $first), 'amount' => $check['amount'] ?? null];
        }
        return ['valid' => true, 'amounts' => $amounts];
    }

    /**
     * Definition of the prompts, offered by the agents as commands.
     *
     * @return list<array<string, mixed>> The prompts
     */
    private static function prompts(): array
    {
        return [[
            'name' => 'propose_insight',
            'title' => 'Propose an insight',
            'description' => 'Turn a need expressed in natural language into an insight of the Insights tab: name, color, icon and checked SQL query.',
            'arguments' => [
                ['name' => 'need', 'description' => 'What to follow each month, e.g. "my restaurant spending at the weekend"', 'required' => true],
            ],
        ]];
    }

    /**
     * Answer to prompts/get: the messages of a prompt, its arguments filled in.
     *
     * @param int|string   $id     The request id
     * @param array<mixed> $params name and arguments
     * @return string The JSON-RPC response
     */
    private static function getPrompt(int|string $id, array $params): string
    {
        $name = $params['name'] ?? null;
        if ($name !== 'propose_insight') {
            return self::error($id, self::INVALID_PARAMS, "Unknown prompt: " . (is_string($name) ? $name : ''));
        }
        $need = $params['arguments']['need'] ?? null;
        if (!is_string($need) || trim($need) === '') {
            return self::error($id, self::INVALID_PARAMS, "Missing argument: need");
        }

        $text = "Propose an insight for the Insights tab of Carbure answering this need:\n\n" . trim($need) . "\n\n"
            . "1. Read get_insight_guide (rules of the query, tables, examples, insights already defined: do not propose one that exists), "
            . "and list_categories to use the real names of the categories.\n"
            . "2. If the need is ambiguous (which categories, expenses or incomes, an amount or a percentage), state the choice you make.\n"
            . "3. Write the query, then run check_insight_query on the last 3 months: fix it until it is valid, and check that the amounts "
            . "are plausible (search_transactions or list_transactions to compare).\n"
            . "4. Answer with the name (20 characters at most), the color, the icon, the SQL query, how to read the amount "
            . "(sign, unit) and the amounts of the months checked. Nothing is saved: an administrator adds the insight in the portal "
            . "(Insights tab, + button, then \"Create an insight\"), where the query is checked again.";

        return self::result($id, [
            'description' => 'Propose an insight',
            'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]],
        ]);
    }

    /**
     * Categories with the name of their parent.
     *
     * @return list<array{id: int, name: mixed, parent: mixed, type: mixed}> id, name, parent (name or null), type
     */
    private static function categories(): array
    {
        $categories = Category::get();
        $names = array_column($categories, 'name', 'id');
        return array_map(fn($c) => [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'parent' => (int)$c['parent_category'] !== 0 && (int)$c['parent_category'] !== (int)$c['id'] ? ($names[$c['parent_category']] ?? null) : null,
            'type' => $c['type'],
        ], $categories);
    }

    /**
     * Transactions reduced to what an analysis needs, with the category name.
     *
     * @param array<array<string, mixed>> $transactions Rows of bank_transaction
     * @return array<array<string, mixed>> date, label, amount, category, checked, comment (null
     *                                      without one, or before the migration 2026-11-19_transaction_comment)
     */
    private static function compact(array $transactions): array
    {
        $names = [];
        foreach (self::categories() as $category) {
            $names[$category['id']] = $category['parent'] ? $category['parent'] . ' › ' . $category['name'] : $category['name'];
        }
        return array_map(fn($t) => [
            'date' => $t['date'],
            'label' => $t['label'],
            'amount' => (float)$t['amount'],
            'category' => $names[(int)$t['category']] ?? null,
            'checked' => (bool)$t['pointed'],
            'comment' => $t['comment'] ?? null,
        ], $transactions);
    }

    /**
     * Total and number of transactions per category over a period.
     *
     * @param mixed $from First month (YYYY-MM), as sent by the client
     * @param mixed $to   Last month (YYYY-MM), as sent by the client
     * @return array<string, mixed> The period and the categories, largest expenses first
     * @throws Error If a month is invalid or the period is reversed
     */
    private static function spendingByCategory(mixed $from, mixed $to): array
    {
        $to = $to ?: date('Y-m');
        $from = $from ?: date('Y-m', Month::timestamp(date('Y-m-01') . ' -11 months'));
        foreach ([$from, $to] as $month) {
            if (!is_string($month) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                throw new Error("Months must be in the YYYY-MM format", 400);
            }
        }
        if ($from > $to) {
            throw new Error("'from' must not be after 'to'", 400);
        }

        $start = "$from-01";
        $end = date('Y-m-d', Month::timestamp("$to-01 +1 month"));
        $sql = "SELECT t.category, SUM(t.amount) AS total, COUNT(*) AS transactions
                FROM bank_transaction t
                WHERE t.date >= ? AND t.date < ?
                GROUP BY t.category
                ORDER BY total";
        $rows = Db::select($sql, "ss", $start, $end)->fetch_all(MYSQLI_ASSOC);

        $categories = array_column(self::categories(), null, 'id');
        $result = [];
        foreach ($rows as $row) {
            $category = $categories[(int)$row['category']] ?? null;
            $result[] = [
                'category' => $category ? ($category['parent'] ? $category['parent'] . ' › ' . $category['name'] : $category['name']) : null,
                'type' => $category['type'] ?? null,
                'total' => round((float)$row['total'], 2),
                'transactions' => (int)$row['transactions'],
            ];
        }

        return ['from' => $from, 'to' => $to, 'categories' => $result];
    }

    /**
     * JSON-RPC success response.
     *
     * @param int|string $id     The request id
     * @param mixed      $result The result
     * @return string The response
     */
    private static function result(int|string $id, mixed $result): string
    {
        return self::encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * JSON-RPC error response.
     *
     * @param mixed  $id      The request id (as sent, even if invalid)
     * @param int    $code    The error code
     * @param string $message The message
     * @return string The response
     */
    private static function error(mixed $id, int $code, string $message): string
    {
        return self::encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /**
     * Encode a message (ids and texts are kept as they are).
     *
     * @param array<string, mixed> $message The message
     * @return string The JSON
     */
    private static function encode(array $message): string
    {
        return json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
