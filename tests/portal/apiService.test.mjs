// services/apiService.js, with a fake fetch
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApiService } from '../../portal/services/apiService.js';

const BASE = 'https://carbure.example.com/api';

test('sends the token and decodes the JSON', async () => {
    const calls = mockFetch(() => json([{ id: 1 }]));
    const api = createApiService(BASE, 'jwt-token');

    assert.deepEqual(await api.fetchCategories(), [{ id: 1 }]);
    assert.equal(calls[0].url, `${BASE}/category`);
    assert.equal(calls[0].options.headers.Authorization, 'Bearer jwt-token');
    assert.equal(calls[0].options.headers['Content-Type'], 'application/json');
});

test('without token, no Authorization header', async () => {
    const calls = mockFetch(() => json({}));
    await createApiService(BASE).fetchMe();
    assert.equal(calls[0].options.headers.Authorization, undefined);
});

test('the error message of the API is the one of the exception', async () => {
    mockFetch(() => json({ error: 'The name must contain 1 to 50 characters', code: 400, uid: 'abc' }, 400));
    await assert.rejects(createApiService(BASE, 't').createCategory({ name: '' }), { message: 'The name must contain 1 to 50 characters' });

    mockFetch(() => new Response('<html>Bad gateway</html>', { status: 502 }));
    await assert.rejects(createApiService(BASE, 't').fetchUsers(), { message: 'Failed to fetch users (502)' });
});

test('the stable code and the values of an API error are kept on the exception, to be translated', async () => {
    mockFetch(() => json({ error: 'This keyword already exists', code: 409, uid: 'abc', error_code: 'keyword_exists' }, 409));
    await assert.rejects(createApiService(BASE, 't').createRule('LOYER', 1),
        { message: 'This keyword already exists', status: 409, code: 'keyword_exists', params: {} });

    mockFetch(() => json({ error: 'Invalid query: Unknown column', code: 400, uid: 'abc', error_code: 'insight_query_failed', params: { cause: 'Unknown column' } }, 400));
    await assert.rejects(createApiService(BASE, 't').saveInsight({ name: 'X', color: 'red', sql: 'SELECT x AS amount' }),
        { code: 'insight_query_failed', params: { cause: 'Unknown column' } });

    // An older server: the message only, no code
    mockFetch(() => json({ error: 'This keyword already exists', code: 409, uid: 'abc' }, 409));
    const error = await createApiService(BASE, 't').createRule('LOYER', 1).catch(e => e);
    assert.equal(error.message, 'This keyword already exists');
    assert.equal(error.code, undefined);
});

test('the streamed requests keep the stable code of an API error too', async () => {
    mockFetch(() => json({ error: 'Invalid bank name (lowercase letters, digits, - and _)', code: 400, uid: 'abc', error_code: 'bank_name_invalid' }, 400));
    await assert.rejects(createApiService(BASE, 't').discoverBankAccountsInteractive('BAD NAME', () => {}), { code: 'bank_name_invalid' });
    mockFetch(() => new Response('<html>Bad gateway</html>', { status: 502 }));
    await assert.rejects(createApiService(BASE, 't').syncBanks(() => {}), { message: 'Failed to start the synchronization (502)', status: 502 });
});

test('responseError: the message of the API, or the default one for a body that is not an API error', async () => {
    const { responseError } = await import('../../portal/services/apiService.js');
    assert.deepEqual({ ...responseError(423, '{"error":"Locked","error_code":"account_locked_until","params":{"until":"2026-10-26 18:30:00"}}', 'Failed') },
        { status: 423, code: 'account_locked_until', params: { until: '2026-10-26 18:30:00' } });
    assert.equal(responseError(500, '', 'Failed (500)').message, 'Failed (500)');
    assert.equal(responseError(500, 'null', 'Failed (500)').message, 'Failed (500)');
    assert.equal(responseError(400, '{"error":{"nested":true}}', 'Failed (400)').message, 'Failed (400)');
    assert.equal(responseError(400, '{"error":"x","error_code":"c","params":"bad"}', 'F').params.constructor, Object);
});

test('a 401 reports the expired session', async () => {
    mockFetch(() => json({ error: 'Unauthorized' }, 401));
    let expired = 0;
    const api = createApiService(BASE, 'old-token', () => expired++);

    await assert.rejects(api.fetchTransactions(1, 2026));
    assert.equal(expired, 1);
});

test('a 503 of a Carbure not installed reports it, not another 503', async () => {
    let setup = 0;
    mockFetch(() => json({ error: 'Carbure is not installed yet', code: 503, setup: true }, 503));
    await assert.rejects(createApiService(BASE, 'old-token', null, () => setup++).fetchTransactions(1, 2026));
    assert.equal(setup, 1);

    // A proxy answering while the server restarts
    mockFetch(() => new Response('<html>Service unavailable</html>', { status: 503 }));
    await assert.rejects(createApiService(BASE, 'old-token', null, () => setup++).fetchTransactions(1, 2026));
    assert.equal(setup, 1);
});

test('the lists are always arrays', async () => {
    mockFetch(() => json({ unexpected: true }));
    const api = createApiService(BASE, 't');
    assert.deepEqual(await api.fetchTransactions(10, 2026), []);
    assert.deepEqual(await api.fetchBudget(10, 2026), []);
    assert.deepEqual(await api.fetchInsights(10, 2026), []);
    assert.deepEqual(await api.fetchUsers(), []);
});

test('a new list request cancels the previous one of the same kind', async () => {
    let release;
    const calls = mockFetch((url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
        release = () => resolve(json([{ id: 2 }]));
    }));
    const api = createApiService(BASE, 't');

    const first = api.fetchBudget(9, 2026);
    const second = api.fetchBudget(10, 2026, 4);
    await assert.rejects(first, { name: 'AbortError' });
    release();
    assert.deepEqual(await second, [{ id: 2 }]);
    assert.equal(calls[1].url, `${BASE}/budget?month=10&year=2026&category=4&own=1`);
});

test('abortAllExcept cancels the other pending lists', async () => {
    mockFetch((url, options) => new Promise((resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new DOMException('aborted', 'AbortError')));
    }));
    const api = createApiService(BASE, 't');
    const insights = api.fetchInsights(10, 2026);
    api.abortAllExcept('transactions');
    await assert.rejects(insights, { name: 'AbortError' });
});

test('updateBudget sets the budget of a category with POST /budget', async () => {
    const calls = mockFetch(() => json(true));
    await createApiService(BASE, 't').updateBudget(4, 10, 2026, 350);

    assert.equal(calls[0].url, `${BASE}/budget`);
    assert.equal(calls[0].options.method, 'POST');
    assert.deepEqual(JSON.parse(calls[0].options.body), { category: 4, amount: 350, month: 10, year: 2026 });
});

test('copyBudgets copies the previous month with POST /budget/copy', async () => {
    const calls = mockFetch(() => json({ month: '2026-11', source: '2026-10', copied: 3, overwrite: true }));
    const api = createApiService(BASE, 't');

    assert.deepEqual(await api.copyBudgets(11, 2026, true), { month: '2026-11', source: '2026-10', copied: 3, overwrite: true });
    assert.equal(calls[0].url, `${BASE}/budget/copy`);
    assert.equal(calls[0].options.method, 'POST');
    assert.deepEqual(JSON.parse(calls[0].options.body), { month: 11, year: 2026, overwrite: true });
});

test('fetchBudgetCopyStatus asks GET /budget/copy for the month', async () => {
    const status = { month: '2026-11', source: '2026-10', defined: false, source_defined: true, copyable: true };
    const calls = mockFetch(() => json(status));
    const api = createApiService(BASE, 't');

    assert.deepEqual(await api.fetchBudgetCopyStatus(11, 2026), status);
    assert.equal(calls[0].url, `${BASE}/budget/copy?month=11&year=2026`);
    assert.equal(calls[0].options.method, undefined);
});

test('saveInsight sends the chosen icon, null for the default one', async () => {
    const calls = mockFetch(() => json({ id: 3 }));
    const api = createApiService(BASE, 't');

    await api.saveInsight({ id: null, name: 'Loyer', color: 'blue', icon: 'home', sql: 'SELECT 1 AS amount' });
    assert.equal(calls[0].options.method, 'POST');
    assert.equal(JSON.parse(calls[0].options.body).icon, 'home');

    await api.saveInsight({ id: 3, name: 'Loyer', color: 'blue', icon: '', sql: 'SELECT 1 AS amount' });
    assert.equal(calls[1].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[1].options.body), { id: 3, name: 'Loyer', color: 'blue', sql: 'SELECT 1 AS amount', icon: null });
});

test('updateUser only sends the fields given', async () => {
    const calls = mockFetch(() => json({ id: 2 }));
    const api = createApiService(BASE, 't');

    await api.updateUser(2, 'm@example.com', 'Marie', 'Martin', '   ', null, undefined);
    assert.equal(calls[0].url, `${BASE}/user?id=2`);
    assert.equal(calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[0].options.body), { email: 'm@example.com', firstname: 'Marie', lastname: 'Martin' });

    await api.updateUser(2, 'm@example.com', 'Marie', 'Martin', 'secret-1', 'en', 150, 1);
    assert.deepEqual(JSON.parse(calls[1].options.body), {
        email: 'm@example.com', firstname: 'Marie', lastname: 'Martin', is_admin: true, language: 'en', alertThreshold: '150', password: 'secret-1',
    });
});

test('syncBanks reads the progress events of one account', async () => {
    const stream = 'data: Syncing 1@bnp (coming)...\n\n: heartbeat\n\ndata: Done 1@bnp (coming): 1 received, 1 new\n\ndata: Synchronization complete\n\n';
    const calls = mockFetch(() => new Response(new Blob([stream]).stream(), { status: 200 }));
    const messages = [];

    await createApiService(BASE, 't').syncBanks(message => messages.push(message), '1@bnp');
    assert.equal(calls[0].url, `${BASE}/bank/sync?account=1%40bnp`);
    assert.deepEqual(messages, ['Syncing 1@bnp (coming)...', 'Done 1@bnp (coming): 1 received, 1 new', 'Synchronization complete']);
});

test('syncBanks asks the interactive mode for one account only', async () => {
    const calls = mockFetch(() => new Response(new Blob(['data: Synchronization complete\n\n']).stream(), { status: 200 }));
    const api = createApiService(BASE, 't');

    await api.syncBanks(() => {}, '1@bnp', true);
    await api.syncBanks(() => {}, null, true);
    assert.equal(calls[0].url, `${BASE}/bank/sync?account=1%40bnp&interactive=true`);
    assert.equal(calls[1].url, `${BASE}/bank/sync`);
});

test('syncBanks reports a refused synchronization', async () => {
    mockFetch(() => json({ error: 'Account not found' }, 404));
    await assert.rejects(createApiService(BASE, 't').syncBanks(() => {}, 'x@y'), { message: 'Account not found' });
});

test('the accounts of one bank connection are asked to woob with its name', async () => {
    const calls = mockFetch(() => json([{ bankId: '1@bnp_2' }]));
    const api = createApiService(BASE, 't');

    assert.deepEqual(await api.discoverBankAccounts('bnp_2'), [{ bankId: '1@bnp_2' }]);
    assert.equal(calls[0].url, `${BASE}/bank/discover?backend=bnp_2`);
    await api.discoverBankAccounts();
    assert.equal(calls[1].url, `${BASE}/bank/discover`);
});

test('the accounts of a bank asking a strong authentication: its messages, then the accounts', async () => {
    const stream = 'data: Bank message cm: Validez dans votre application\n\n: heartbeat\n\n'
        + 'data: Accounts [{"bankId":"1@cm","label":"Compte chèques"}]\n\n';
    const calls = mockFetch(() => new Response(new Blob([stream]).stream(), { status: 200 }));
    const messages = [];

    const accounts = await createApiService(BASE, 't').discoverBankAccountsInteractive('cm', message => messages.push(message));
    assert.equal(calls[0].url, `${BASE}/bank/discover/interactive?backend=cm`);
    assert.deepEqual(messages, ['Validez dans votre application']);
    assert.deepEqual(accounts, [{ bankId: '1@cm', label: 'Compte chèques' }]);
});

test('the interactive listing of the accounts reports the failure of woob, or an answer cut short', async () => {
    const answer = body => () => new Response(new Blob([body]).stream(), { status: 200 });
    const api = createApiService(BASE, 't');

    mockFetch(answer('data: Discovery failed: synchronization in progress\n\n'));
    await assert.rejects(api.discoverBankAccountsInteractive('cm', () => {}), { message: 'synchronization in progress' });
    mockFetch(answer('data: Bank message cm: Validez\n\n'));
    await assert.rejects(api.discoverBankAccountsInteractive('cm', () => {}), { message: 'The connection to the bank was interrupted' });
    mockFetch(() => json({ error: 'Forbidden - Administrator only' }, 403));
    await assert.rejects(api.discoverBankAccountsInteractive('cm', () => {}), { message: 'Forbidden - Administrator only' });
});

test('notifications and test email requests', async () => {
    const calls = mockFetch(() => json({}));
    const api = createApiService(BASE, 't');

    await api.fetchNotifications();
    await api.updateNotifications({ sync: { email: true } });
    await api.sendTestEmail();
    await api.sendTestEmail('other@example.com');

    assert.equal(calls[0].url, `${BASE}/user/notifications`);
    assert.equal(calls[1].url, `${BASE}/user/notifications`);
    assert.equal(calls[1].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[1].options.body), { preferences: { sync: { email: true } } });
    assert.equal(calls[2].url, `${BASE}/system/smtp-test`);
    assert.equal(calls[2].options.method, 'POST');
    assert.deepEqual(JSON.parse(calls[2].options.body), {});
    assert.deepEqual(JSON.parse(calls[3].options.body), { to: 'other@example.com' });

    await api.registerPush();
    await api.sendTestPush();
    assert.equal(calls.at(-2).url, `${BASE}/system/push-register`);
    assert.equal(calls.at(-2).options.method, 'POST');
    assert.equal(calls.at(-2).options.body, undefined);
    assert.equal(calls.at(-1).url, `${BASE}/system/push-test`);
    assert.equal(calls.at(-1).options.method, 'POST');
    await api.registerPush(true);
    assert.deepEqual(JSON.parse(calls.at(-1).options.body), { consent: true });
    await api.fetchPushInfo();
    assert.equal(calls.at(-1).url, `${BASE}/system/push`);
    assert.equal(calls.at(-1).options.method, undefined);
    calls.splice(4);

    await api.updateBudgetThreshold(80);
    assert.equal(calls[4].url, `${BASE}/user/notifications`);
    assert.equal(calls[4].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[4].options.body), { budgetThreshold: '80' });

    // Settings of the alerts: only those sent, as strings ('' turns a setting off)
    await api.updateAlertSettings({ alertThreshold: 150.5 });
    await api.updateAlertSettings({ budgetCategory: '' });
    assert.equal(calls[5].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[5].options.body), { alertThreshold: '150.5' });
    assert.deepEqual(JSON.parse(calls[6].options.body), { budgetCategory: '' });

    // Budget alerts: the global threshold on or off, the thresholds of categories as strings
    await api.updateBudgetAlerts({ budgetAll: false });
    assert.deepEqual(JSON.parse(calls.at(-1).options.body), { budgetAll: false });
    await api.updateBudgetAlerts({ budgetThresholds: [{ category: '4', threshold: 80 }] });
    assert.equal(calls.at(-1).options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls.at(-1).options.body), { budgetThresholds: [{ category: '4', threshold: '80' }] });
});

test('rule settings: read with GET, keep_categorized changed with PUT /category/keyword/settings', async () => {
    const calls = mockFetch(() => json({ keep_categorized: true }));
    const api = createApiService(BASE, 't');

    assert.deepEqual(await api.fetchRuleSettings(), { keep_categorized: true });
    assert.equal(calls[0].url, `${BASE}/category/keyword/settings`);
    assert.equal(calls[0].options.method, undefined);

    assert.deepEqual(await api.updateRuleSettings(true), { keep_categorized: true });
    assert.equal(calls[1].url, `${BASE}/category/keyword/settings`);
    assert.equal(calls[1].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[1].options.body), { keep_categorized: true });
});

test('rules: the optional amount sent with the rule, empty for any amount', async () => {
    const calls = mockFetch(() => json({ id: 1 }));
    const api = createApiService(BASE, 't');
    await api.createRule('PAYPAL', '3', false, ' 10,50 ');
    assert.deepEqual(JSON.parse(calls[0].options.body), { keyword: 'PAYPAL', category: 3, notify: false, amount: '10,50' });
    await api.createRule('PAYPAL', 1);
    assert.deepEqual(JSON.parse(calls[1].options.body), { keyword: 'PAYPAL', category: 1, notify: false, amount: '' });
    await api.updateRule(1, 'PAYPAL', 3, true, null);
    assert.equal(calls[2].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[2].options.body), { id: 1, keyword: 'PAYPAL', category: 3, notify: true, amount: '' });
});

test('fetchCategoryTransactions asks only the transactions of the category itself with own (#492)', async () => {
    const calls = mockFetch(() => json([]));
    const api = createApiService(BASE, 't');
    await api.fetchCategoryTransactions(11, 2026, 1);
    await api.fetchCategoryTransactions(11, 2026, 1, true);
    assert.equal(calls[0].url, `${BASE}/transaction?month=11&year=2026&category=1`);
    assert.equal(calls[1].url, `${BASE}/transaction?month=11&year=2026&category=1&own=1`);
});

test('importTransactions sends the account only when one is chosen (#485)', async () => {
    const calls = mockFetch(() => json({ imported: 0 }));
    const api = createApiService(BASE, 't');
    await api.importTransactions('eA==', 'releve.csv', [0]);
    await api.importTransactions('eA==', 'releve.csv', [0], 3);
    assert.deepEqual(JSON.parse(calls[0].options.body), { file: 'eA==', filename: 'releve.csv', selected: [0] });
    assert.deepEqual(JSON.parse(calls[1].options.body), { file: 'eA==', filename: 'releve.csv', selected: [0], account: 3 });
});
