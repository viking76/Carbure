// Methods of the portal mixins (portal/modules) that do not need the DOM
import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createBudgetModule } from '../../portal/modules/budgetModule.js';
import { createTransactionModule, recurringCalendar, similarLabelQuery } from '../../portal/modules/transactionModule.js';
import { createRulesModule, ruleAmountText } from '../../portal/modules/rulesModule.js';
import { agentConfigurations, createAgentsModule } from '../../portal/modules/agentsModule.js';
import { createRequire } from 'node:module';

/** Text of an error shown by the modules (this.apiErrorText of the portal): without translations loaded, its message */
const { apiErrorText } = createRequire(import.meta.url)('../../portal/i18n.js');

const budget = createBudgetModule(() => null, () => null);
const transactions = createTransactionModule(() => null);
const rules = createRulesModule(() => null);

const CATEGORIES = [
    { id: 0, name: 'Non catégorisé', parent_category: 0, icon: 'help', color: '#9e9e9e' },
    { id: 1, name: 'Alimentation', parent_category: 0, icon: 'restaurant', color: 'green' },
    { id: 2, name: 'Supermarché', parent_category: 1, icon: 'shopping_cart', color: 'teal' },
    { id: 3, name: 'Restaurant', parent_category: 1, icon: '', color: '' },
    { id: 4, name: 'Logement', parent_category: 0, icon: 'home', color: 'blue' },
];

/** `this` of the portal for the transaction methods */
function transactionContext() {
    const context = {
        ...budget.methods,
        ruleCategories: CATEGORIES,
        // Icon font loaded: the stored names are kept, '' gives the default icon
        categoryIcon: item => item.icon || 'category',
        getTransactionIcon: type => `type-${type}`,
    };
    for (const [name, method] of Object.entries(transactions.methods)) {
        context[name] = method.bind(context);
    }
    context.categoryColor = budget.methods.categoryColor.bind(context);
    context.categoryBadgeStyle = budget.methods.categoryBadgeStyle.bind(context);
    return context;
}

test('budgetStatus: green up to 105 %, red beyond', () => {
    const status = progress => budget.methods.budgetStatus({ progress });
    assert.equal(status(0), 'ok');
    assert.equal(status(95), 'ok');
    assert.equal(status(105), 'ok');
    assert.equal(status(106), 'over');
    assert.equal(status('abc'), 'ok');
});

test('budgetGroups: expenses, incomes, off-budget or empty', () => {
    const groups = budget.computed.budgetGroups.call({ currentBudgetList: [
        { name: 'Logement', type: 'DEBIT', budget: '1000.00', consummed: 950 },
        { name: 'Vacances', type: 'DEBIT', budget: '0.00', consummed: 0 },
        // #491: an expense without budget is not an overspending
        { name: 'Cadeaux', type: 'DEBIT', budget: '0.00', consummed: 45 },
        { name: 'Salaire', type: 'CREDIT', budget: '0.00', consummed: 3200 },
        { name: 'Épargne', type: 'HORS-BUDGET', budget: '0.00', consummed: 500 },
    ] });
    assert.deepEqual(groups.expenses.map(i => i.name), ['Logement']);
    assert.deepEqual(groups.incomes.map(i => i.name), ['Salaire']);
    assert.deepEqual(groups.others.map(i => i.name), ['Vacances', 'Cadeaux', 'Épargne']);
});

test('submitBudgetEdit: "no budget" saves 0 without an amount (#491)', async () => {
    const saved = [];
    const context = {
        budgetUseChildren: false, budgetNone: true, budgetNewValue: '', budgetNote: ' ',
        selectedMonth: 11, selectedYear: 2026, savingBudget: false,
        showToast(message) { saved.push(['toast', message]); },
        t: key => key,
        async handleBudgetSave(amount, month, year, note) { saved.push([amount, month, year, note]); },
    };
    await budget.methods.submitBudgetEdit.call(context);
    assert.deepEqual(saved, [[0, 11, 2026, '']]);

    // Without it, an empty amount is still refused
    saved.length = 0;
    await budget.methods.submitBudgetEdit.call({ ...context, budgetNone: false });
    assert.deepEqual(saved, [['toast', 'budgetInvalidAmount']]);
});

test('budgetSummary: totals of the budgeted expenses', () => {
    const summary = budget.computed.budgetSummary.call({ budgetGroups: { expenses: [
        { budget: '1000.00', consummed: 950 }, { budget: '400.00', consummed: 127.4 },
    ] } });
    assert.equal(summary.budget, 1400);
    assert.equal(Math.round(summary.consumed * 100) / 100, 1077.4);
    assert.equal(summary.progress, 77);
});

test('copySource: the month before the selected one, named in the button', () => {
    const t = (key, params = {}) => {
        const months = { 10: 'Octobre', 12: 'Décembre' };
        const text = key.startsWith('months.') ? months[key.slice(7)] : 'Recopier les budgets du mois précédent ({month})';
        return text.replace(/\{(\w+)\}/g, (match, name) => String(params[name]));
    };
    const context = { selectedMonth: 11, selectedYear: 2026, locale: 'fr', t };

    assert.deepEqual(budget.computed.copySource.call(context), { month: 10, year: 2026 });
    assert.equal(budget.computed.copySourceName.call({ ...context, copySource: { month: 10, year: 2026 } }), 'octobre');
    assert.equal(budget.computed.copyButtonLabel.call({ ...context, copySourceName: 'octobre' }),
        'Recopier les budgets du mois précédent (octobre)');

    // January is copied from December of the previous year
    const january = { ...context, selectedMonth: 1, selectedYear: 2026 };
    assert.deepEqual(budget.computed.copySource.call(january), { month: 12, year: 2025 });
    assert.equal(budget.computed.copySourceName.call({ ...january, copySource: { month: 12, year: 2025 } }), 'décembre');
});

test('budgetCopyOffered: only when the status of the selected month says so', () => {
    const offered = (copyStatus, selectedMonth = 3, selectedYear = 2026) =>
        createBudgetModule(() => ({ state: { copyStatus } }), () => null).computed.budgetCopyOffered.call({ selectedMonth, selectedYear });

    assert.equal(offered({ month: '2026-03', copyable: true }), true);
    assert.equal(offered({ month: '2026-03', copyable: false }), false);
    // Unknown, or the status of another month (the answer not arrived yet)
    assert.equal(offered(null), false);
    assert.equal(offered({ month: '2026-02', copyable: true }), false);
    assert.equal(offered({ month: '2026-12', copyable: true }, '12', '2026'), true);
});

test('categoryColor: color of the category, the primary color by default', () => {
    assert.equal(budget.methods.categoryColor({ color: 'teal' }), '#30b0c7');
    assert.equal(budget.methods.categoryColor({ color: '' }), 'var(--primary-color)');
});

test('the icon of a transaction is the one of its own category', () => {
    const context = transactionContext();
    assert.equal(context.transactionIcon({ category: '2', type: 7 }), 'shopping_cart');
    // A sub-category without icon: its own (default) icon, as on the categories page, not its parent's
    assert.equal(context.transactionIcon({ category: 3, type: 7 }), 'category');
    // Uncategorized: icon of the operation type, without category colors
    assert.equal(context.transactionIcon({ category: 0, type: 7 }), 'type-7');
    assert.equal(context.transactionIconStyle({ category: 0, type: 7 }), null);
    assert.deepEqual(context.transactionIconStyle({ category: 3 }), { color: 'color-mix(in srgb, var(--primary-color) var(--category-ink, 100%), white)', background: 'color-mix(in srgb, var(--primary-color) 14%, transparent)' });
});

test('categoryName and isUncategorized', () => {
    const context = transactionContext();
    assert.equal(context.categoryName({ category: 4 }), 'Logement');
    assert.equal(context.categoryName({ category: 0 }), '');
    assert.equal(context.isUncategorized({ category: '0' }), true);
    assert.equal(context.isUncategorized({ category: 2 }), false);
});

test('displayedTransactions and totals', () => {
    const list = [
        { id: 1, amount: '3200.00', pointed: '1' },
        { id: 2, amount: '-82.40', pointed: '0' },
        { id: 3, amount: '-950.00', pointed: '1' },
    ];
    const context = { transactions: list, searchResults: [], isSearching: false, transactionFilter: 'unchecked' };
    const displayed = transactions.computed.displayedTransactions.call(context);
    assert.deepEqual(displayed.map(t => t.id), [2]);
    assert.equal(transactions.computed.displayedTotal.call({ displayedTransactions: displayed }), -82.4);
    assert.equal(transactions.computed.uncheckedCount.call(context), 1);
});

test('uncategorized filter: the transactions without a category, combined with the other filters', () => {
    const list = [
        { id: 1, amount: '3200.00', pointed: '1', category: 4 },
        { id: 2, amount: '-82.40', pointed: '0', category: '0' },
        { id: 3, amount: '-950.00', pointed: '1', category: null },
    ];
    const context = { transactions: list, searchResults: [list[0]], isSearching: false, transactionFilter: 'all', uncategorizedOnly: true };
    context.isUncategorized = transactions.methods.isUncategorized;
    assert.deepEqual(transactions.computed.displayedTransactions.call(context).map(t => t.id), [2, 3]);
    assert.equal(transactions.computed.uncategorizedCount.call(context), 2);
    assert.deepEqual(transactions.computed.displayedTransactions.call({ ...context, transactionFilter: 'unchecked' }).map(t => t.id), [2]);
    // Also applied to the search results, counted among them
    assert.deepEqual(transactions.computed.displayedTransactions.call({ ...context, isSearching: true }), []);
    assert.equal(transactions.computed.uncategorizedCount.call({ ...context, isSearching: true }), 0);
    // Off: every transaction
    assert.equal(transactions.computed.displayedTransactions.call({ ...context, uncategorizedOnly: false }).length, 3);
});

/** `this` of the portal saving the transaction modal in a tab (date unchanged), the API calls recorded */
function detailContext(activeTab, transaction, category) {
    const calls = [];
    const api = {
        async setTransactionCategory(id, value) { calls.push(['category', id, value]); },
        async setTransactionPointed(id, value) { calls.push(['pointed', id, value]); },
        async setTransactionDate(id, value) { calls.push(['date', id, value]); },
    };
    const module = createTransactionModule(() => api);
    const context = {
        activeTab, isAdmin: false, transactionDetail: { date: '2026-10-05', ...transaction }, transactionDetailCategory: category,
        transactionDetailPointed: !!Number(transaction.pointed), transactionDetailDate: '2026-10-05',
        transactions: [], searchResults: [], t: key => key, apiErrorText, showToast() {},
        refreshBudgetTab() { calls.push(['refreshBudgetTab']); },
        loadPeriods() {}, loadTransactions() {},
    };
    for (const [name, method] of Object.entries(module.methods)) {
        context[name] = method.bind(context);
    }
    return { context, calls };
}

test('saveTransactionDetail: a new category or date reloads the Budget tab where the modal was opened', async () => {
    const moved = detailContext('budget', { id: 7, label: 'CB PAUL', category: 2, pointed: 1 }, 4);
    await moved.context.saveTransactionDetail();
    assert.deepEqual(moved.calls, [['category', 7, 4], ['refreshBudgetTab']]);
    assert.equal(moved.context.transactionDetail, null);

    // Only checked: the amounts of the categories did not change
    const checked = detailContext('budget', { id: 7, label: 'CB PAUL', category: 2, pointed: 0 }, 2);
    checked.context.transactionDetailPointed = true;
    await checked.context.saveTransactionDetail();
    assert.deepEqual(checked.calls, [['pointed', 7, true]]);

    // Moved to another month: the amounts of the month shown changed
    const dated = detailContext('budget', { id: 7, label: 'CB PAUL', category: 2, pointed: 1 }, 2);
    dated.context.transactionDetailDate = '2026-09-30';
    await dated.context.saveTransactionDetail();
    assert.deepEqual(dated.calls, [['date', 7, '2026-09-30'], ['refreshBudgetTab']]);

    // Transactions tab: the row is updated in place
    const listed = detailContext('transactions', { id: 7, label: 'CB PAUL', category: 0, pointed: 1 }, 4);
    await listed.context.saveTransactionDetail();
    assert.deepEqual(listed.calls, [['category', 7, 4]]);
});

test('recurring transactions: ids, filter and expected series', () => {
    const recurring = [
        { label: 'VIR SALAIRE ACME', ids: [1], status: 'received' },
        { label: 'PRLV LOYER AGENCE', ids: ['3'], status: 'received' },
        { label: 'CB NETFLIX.COM', ids: [], status: 'expected' },
    ];
    const recurringIds = transactions.computed.recurringIds.call({ recurring });
    assert.deepEqual([...recurringIds], [1, 3]);
    assert.deepEqual(transactions.computed.recurringExpected.call({ recurring }).map(s => s.label), ['CB NETFLIX.COM']);

    const list = [
        { id: 1, amount: '3200.00', pointed: '1' },
        { id: 2, amount: '-82.40', pointed: '0' },
        { id: 3, amount: '-950.00', pointed: '0' },
    ];
    const context = { transactions: list, searchResults: list, isSearching: false, transactionFilter: 'all', recurringOnly: true, recurringIds };
    context.isRecurring = transactions.methods.isRecurring.bind(context);
    assert.deepEqual(transactions.computed.displayedTransactions.call(context).map(t => t.id), [1, 3]);
    // Combined with the unchecked filter
    assert.deepEqual(transactions.computed.displayedTransactions.call({ ...context, transactionFilter: 'unchecked' }).map(t => t.id), [3]);
    // Not applied to the search results (other months)
    assert.equal(transactions.computed.displayedTransactions.call({ ...context, isSearching: true }).length, 3);

    // Net total of the month: the received ones, plus the expected ones at their usual amount
    const recurringCalendar = { received: { count: 2, total: 2250 }, expected: { count: 1, total: -13.49 } };
    assert.equal(transactions.computed.recurringTotal.call({ recurringCalendar }), 2236.51);
});

test('recurring transactions: their bar folded by default, unfolded remembered, folded without its filter (#465)', () => {
    const stored = {};
    globalThis.localStorage = { getItem: key => stored[key] ?? null, setItem: (key, value) => { stored[key] = value; } };
    try {
        const context = { ...transactions.data(), ...transactions.methods };
        assert.equal(context.recurringExpanded, false);

        context.toggleRecurringBar();
        assert.equal(context.recurringExpanded, true);
        assert.equal(stored.recurringExpanded, '1');
        assert.equal(createTransactionModule(() => null).data().recurringExpanded, true);

        // Folded: the list is no longer limited by a switch out of sight
        context.recurringOnly = true;
        context.toggleRecurringBar();
        assert.equal(context.recurringExpanded, false);
        assert.equal(context.recurringOnly, false);
        assert.equal(stored.recurringExpanded, '0');

        // Storage refused (private browsing): folded, and the bar still folds and unfolds
        globalThis.localStorage = { getItem: () => { throw new Error('denied'); }, setItem: () => { throw new Error('denied'); } };
        assert.equal(createTransactionModule(() => null).data().recurringExpanded, false);
        context.toggleRecurringBar();
        assert.equal(context.recurringExpanded, true);
    } finally {
        delete globalThis.localStorage;
    }
});

test('similarLabelQuery: the merchant, without the kind of operation nor the references', () => {
    // Labels of the data set (tests/e2e/server/seed.php) and as woob gives them
    assert.equal(similarLabelQuery('CB BOULANGERIE PAUL'), 'BOULANGERIE PAUL');
    assert.equal(similarLabelQuery('CB BOULANGERIE DU MARCHE'), 'BOULANGERIE DU MARCHE');
    assert.equal(similarLabelQuery('CB NETFLIX.COM'), 'NETFLIX.COM');
    assert.equal(similarLabelQuery('PRLV EDF CLIENTS PARTICULIERS'), 'EDF CLIENTS PARTICULIERS');
    assert.equal(similarLabelQuery('VIR SALAIRE ACME'), 'SALAIRE ACME');
    assert.equal(similarLabelQuery('VIR LIVRET A'), 'LIVRET A');
    assert.equal(similarLabelQuery('FACTURE CARTE DU 040126 BOULANGERIE CARTE 4974'), 'BOULANGERIE');
    assert.equal(similarLabelQuery('PRLV SEPA EDF MDT/123'), 'EDF');
    assert.equal(similarLabelQuery('VIR SEPA INST LOYER 12/01 REF 998877'), 'LOYER');
    assert.equal(similarLabelQuery('  cb   sushi shop  '), 'SUSHI SHOP');
    // Nothing meaningful left: the label itself
    assert.equal(similarLabelQuery('CB 123456'), 'CB 123456');
    assert.equal(similarLabelQuery('LOYER'), 'LOYER');
    assert.equal(similarLabelQuery(''), '');
});

test('searchSimilarTransactions: closes the detail and searches like a typed search', async () => {
    const searched = [];
    const context = {
        activeTab: 'transactions',
        transactionDetail: { id: 1, label: 'CB BOULANGERIE PAUL' },
        searchQuery: '',
        searchTimer: null,
        runSearch() { searched.push(this.searchQuery); },
    };
    await transactions.methods.searchSimilarTransactions.call(context);
    assert.equal(context.transactionDetail, null);
    assert.equal(context.searchQuery, 'BOULANGERIE PAUL');
    assert.deepEqual(searched, ['BOULANGERIE PAUL']);
    // Same merchant: in the label only, until the user types
    assert.equal(context.searchLabelOnly, true);
    context.isSearching = true;
    transactions.methods.onSearchInput.call(context);
    clearTimeout(context.searchTimer);
    assert.equal(context.searchLabelOnly, false);
});

test('searchSimilarTransactions: from the Budget tab, the results are shown in the Transactions tab', async () => {
    const context = {
        activeTab: 'budget',
        transactionDetail: { id: 1, label: 'CB BOULANGERIE PAUL' },
        searchQuery: '',
        searchTimer: null,
        setActiveTab(tab) { this.activeTab = tab; },
        runSearch() {},
    };
    await transactions.methods.searchSimilarTransactions.call(context);
    assert.equal(context.activeTab, 'transactions');
    assert.equal(context.searchQuery, 'BOULANGERIE PAUL');
});

test('reloadBudgetLevel: reloads the budgets, then the transactions of the selected category', async () => {
    const calls = [];
    const store = { async reloadBudgets(month, year) { calls.push(['reloadBudgets', month, year]); } };
    const api = { async fetchCategoryTransactions(month, year, category) { calls.push(['transactions', month, year, category]); return [{ id: 9 }]; } };
    const module = createBudgetModule(() => store, () => api);
    const context = { budgetCategory: { id: 4, name: 'Logement' }, ensureCategories() {}, showToast() {}, $nextTick() {} };
    context.loadBudgetTransactions = module.methods.loadBudgetTransactions.bind(context);
    context.budgetItemName = module.methods.budgetItemName.bind(context);
    await module.methods.reloadBudgetLevel.call(context, 10, 2026);
    await new Promise(resolve => setTimeout(resolve));
    assert.deepEqual(calls, [['reloadBudgets', 10, 2026], ['transactions', 10, 2026, 4]]);
    assert.deepEqual(context.budgetTransactions, [{ id: 9 }]);

    // No category selected: only the budgets
    calls.length = 0;
    await module.methods.reloadBudgetLevel.call({ ...context, budgetCategory: null }, 10, 2026);
    assert.deepEqual(calls, [['reloadBudgets', 10, 2026]]);
});

test('isSearching: from 2 characters', () => {
    assert.equal(transactions.computed.isSearching.call({ searchQuery: ' a ' }), false);
    assert.equal(transactions.computed.isSearching.call({ searchQuery: 'ed' }), true);
});

test('ruleCategoryOptions: each parent followed by its sub-categories, without the default one', () => {
    const options = rules.computed.ruleCategoryOptions.call({ ruleCategories: CATEGORIES });
    assert.deepEqual(options.map(o => o.label), ['Alimentation', 'Alimentation › Restaurant', 'Alimentation › Supermarché', 'Logement']);
});

test('agentConfigurations: the MCP URL and the token in each configuration', () => {
    const url = 'https://carbure.example.com/api/mcp';
    const configs = agentConfigurations(url, 'tok-123');
    assert.deepEqual(configs.map(c => c.id), ['claude-code', 'chatgpt', 'cursor', 'vscode', 'gemini']);
    // Counted, not searched with includes(): a test, not a check of a URL
    const occurrences = (text, part) => text.split(part).length - 1;
    for (const config of configs) {
        assert.ok(occurrences(config.code, url) > 0, config.id);
        assert.ok(occurrences(config.code, 'tok-123') > 0, config.id);
    }
    assert.deepEqual(JSON.parse(configs.find(c => c.id === 'cursor').code), { mcpServers: { carbure: { url, headers: { Authorization: 'Bearer tok-123' } } } });
});

test('import: base64 of a file, by chunks', async () => {
    const { toBase64 } = await import('../../portal/modules/importModule.js');
    const text = 'Date;Libellé;Montant\n'.repeat(5000);
    const bytes = new TextEncoder().encode(text);
    assert.equal(toBase64(bytes.buffer), Buffer.from(bytes).toString('base64'));
    assert.equal(toBase64(new ArrayBuffer(0)), '');
});

test('import: how a CSV file was read, with the profile of its bank or not', async () => {
    const { createImportModule } = await import('../../portal/modules/importModule.js');
    const context = { t: (key, params) => `${key}${params ? ':' + params.bank : ''}` };
    const label = createImportModule(() => null).methods.importBankLabel.bind(context);
    assert.equal(label({ format: 'csv', bank: { id: 'boursorama', name: 'Boursorama / BoursoBank' } }), 'importBankRecognized:Boursorama / BoursoBank');
    assert.equal(label({ format: 'csv', bank: null }), 'importBankGeneric');
    // A server without the bank field: the columns were guessed
    assert.equal(label({ format: 'csv' }), 'importBankGeneric');
    // Other formats are read the same for every bank
    assert.equal(label({ format: 'ofx', bank: null }), null);
    assert.equal(label(null), null);
});

test('config: curl command of the synchronization', async () => {
    const { syncCommand } = await import('../../portal/modules/configModule.js');
    assert.equal(syncCommand('https://x.fr/api/bank/sync', 'abc'), 'curl -sN -H "X-Sync-Token: abc" "https://x.fr/api/bank/sync"');
    assert.equal(syncCommand('https://x.fr/api/bank/sync', 'abc', '123@bnp'), 'curl -sN -H "X-Sync-Token: abc" "https://x.fr/api/bank/sync?account=123%40bnp"');
    assert.equal(syncCommand('https://x.fr/api/bank/sync', null), 'curl -sN "https://x.fr/api/bank/sync"');
});

test('accountName: the name given to the account, else the bank and the end of the number', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { methods } = createAccountsModule(() => null);
    const context = {
        bankAccounts: [
            { bankId: 'DEMO0000001@bnp', account_number: 'DEMO0000001', bank_name: 'bnp', label: 'Compte joint' },
            { bankId: '999@bnp', account_number: '999', bank_name: 'bnp', label: null },
        ],
    };
    context.accountName = methods.accountName.bind(context);
    context.bankIdName = methods.bankIdName.bind(context);

    assert.equal(context.accountName(context.bankAccounts[0]), 'Compte joint');
    assert.equal(context.accountName(context.bankAccounts[1]), 'BNP ···999');
    // A transaction of a followed account: the name of the account, not its own label
    assert.equal(context.accountName({ bank_name: 'bnp', account_number: 'DEMO0000001', label: 'CB PAUL' }), 'Compte joint');
    assert.equal(context.accountName({ bank_name: 'lcl', account_number: '11112222', label: 'CB PAUL' }), 'LCL ···2222');
    // Imported statement file: no bank
    assert.equal(context.accountName({ bank_name: null, account_number: 'FR761234' }), '···1234');
    assert.equal(context.bankIdName('DEMO0000001@bnp'), 'Compte joint');
    assert.equal(context.bankIdName('fail@bank'), 'BANK ···fail');
});

test('sync: the debug logs of woob are not steps nor transactions', async () => {
    const { createSyncModule } = await import('../../portal/modules/syncModule.js');
    const { syncSteps } = createSyncModule(() => null).computed;
    const steps = syncSteps.call({
        syncStatus: 'error',
        syncLog: [
            'Syncing 1@bnp (coming)...',
            'Woob debug 1@bnp: DEBUG:woob.browser:Request: GET https://bank.example/accounts',
            'Woob debug 1@bnp: Error while calling woob',
            'Error syncing 1@bnp: Error while calling woob (exit code: 1): 500 Server Error',
            'Notifying users of 1@bnp...',
            'Synchronization complete',
        ],
    });
    assert.deepEqual(steps.global, []);
    assert.deepEqual(steps.accounts[0].steps.map(s => [s.kind, s.status, s.count]), [['coming', 'error', 0], ['notify', 'ok', 0]]);
});

test('sync: a failure of the coming transactions does not fail the account', async () => {
    const { createSyncModule } = await import('../../portal/modules/syncModule.js');
    const { syncSteps, syncErrors } = createSyncModule(() => null).computed;
    const context = {
        syncStatus: 'success',
        syncLog: [
            'Syncing 1@bnp (accounts)...',
            'Syncing 1@bnp (coming)...',
            'Coming failed 1@bnp: Error while calling woob (exit code: 1): NotImplementedError',
            'Syncing 1@bnp (history)...',
            'Done 1@bnp (history): 3 received, 2 new',
            'Waiting for a validation 1@bnp: new attempt in 15 s (1/1)',
            'Notifying users of 1@bnp...',
            'Synchronization complete',
        ],
    };
    const steps = syncSteps.call(context);
    assert.deepEqual(steps.accounts[0].steps.map(s => [s.kind, s.status, s.count]),
        [['accounts', 'ok', 0], ['coming', 'error', 0], ['history', 'ok', 3], ['notify', 'ok', 0]]);
    assert.equal(steps.accounts[0].steps[1].error, 'Error while calling woob (exit code: 1): NotImplementedError');
    assert.equal(steps.accounts[0].steps[2].message, 'new attempt in 15 s (1/1)');
    // Not an error of the synchronization
    assert.deepEqual(syncErrors.call(context), []);
});

test('accounts: a bank already configured stays offered, with a free backend name', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { methods } = createAccountsModule(() => null);
    const context = {
        bankBackends: [{ name: 'bnp', module: 'bnp' }, { name: 'bnp_2', module: 'bnp' }, { name: 'cic_pro', module: 'cic' }],
        bankModules: [{ module: 'bnp', description: 'BNP Paribas' }, { module: 'boursorama', description: 'Boursorama' }],
    };
    context.findBackend = methods.findBackend.bind(context);
    assert.deepEqual(methods.otherBankModules.call(context).map(m => [m.module, m.configured]), [['bnp', true], ['boursorama', false]]);
    assert.equal(methods.freeBackendName.call(context, 'bnp'), 'bnp_3');
    assert.equal(methods.freeBackendName.call(context, 'boursorama'), 'boursorama');
    assert.equal(methods.backendModule.call(context, 'cic_pro'), 'cic');
    assert.equal(methods.backendModule.call(context, 'unknown'), 'unknown');
    assert.deepEqual(context.findBackend('unknown'), { name: 'unknown', module: 'unknown', label: null });
});

test('accounts: a connected bank shown by the name given to it, else by its bank', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { methods } = createAccountsModule(() => null);
    const context = { bankModules: [{ module: 'bnp', description: 'BNP Paribas' }] };

    assert.equal(methods.bankDescription.call(context, { name: 'bnp_2', module: 'bnp', label: 'Compte joint' }), 'Compte joint');
    assert.equal(methods.bankDescription.call(context, { name: 'bnp_2', module: 'bnp', label: null }), 'BNP Paribas');
    assert.equal(methods.bankDescription.call(context, { name: 'cic_pro', module: 'cic' }), 'cic_pro');
    assert.equal(methods.backendName.call(context, { name: 'bnp_2', module: 'bnp', label: 'Compte joint' }), 'Compte joint (bnp_2)');
});

/** `this` of the portal for the methods of the accounts module, with the given API service */
async function accountsContext(api) {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { createSyncModule } = await import('../../portal/modules/syncModule.js');
    const accounts = createAccountsModule(() => api);
    const context = { ...accounts.data(), bankAccounts: [] };
    for (const [name, method] of Object.entries({ ...createSyncModule(() => api).methods, ...accounts.methods })) {
        context[name] = method.bind(context);
    }
    return context;
}

test('accounts: a bank asking a strong authentication is listed again interactively, its messages shown', async () => {
    const shown = [];
    const context = await accountsContext({
        discoverBankAccounts: async () => {
            throw new Error('Error while calling woob (exit code: 1): Error(cm): You have to run bank in interactive mode to perform a two-factor authentication');
        },
        discoverBankAccountsInteractive: async (backend, onMessage, signal) => {
            assert.equal(backend, 'cm');
            assert.ok(signal instanceof AbortSignal);
            onMessage('Validez dans votre application');
            shown.push([context.discoverTwoFactor, context.discoverMessage, context.discovering]);
            return [{ bankId: '1@cm', followed: false }];
        },
    });

    await context.openAddAccounts('cm');
    assert.deepEqual(shown, [[true, 'Validez dans votre application', true]]);
    assert.deepEqual(context.discoveredAccounts, [{ bankId: '1@cm', followed: false }]);
    assert.equal(context.discoverError, null);
    assert.equal(context.discovering, false);

    // Just connected with two_factor: straight to the interactive listing
    let plain = 0;
    context.discoverBankAccounts = async () => plain++;
    await context.openAddAccounts('cm', null, true);
    assert.equal(plain, 0);
    assert.equal(context.discoveredAccounts.length, 1);
});

test('accounts: closing the modal stops woob waiting for the approval of the bank', async () => {
    let signal = null;
    const context = await accountsContext({
        discoverBankAccountsInteractive: (backend, onMessage, abort) => new Promise((resolve, reject) => {
            signal = abort;
            abort.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
        }),
    });

    const opening = context.openAddAccounts('cm', null, true);
    context.closeAddAccounts();
    await opening;
    assert.equal(signal.aborted, true);
    // Not an error to show
    assert.equal(context.discoverError, null);
    assert.equal(context.showAddAccountsModal, false);
});

test('accounts: the + of the synchronized accounts goes to the banks already connected, or a new one', async () => {
    const { NEW_BANK } = await import('../../portal/modules/accountsModule.js');
    const listed = [];
    const context = await accountsContext({ discoverBankAccounts: async backend => { listed.push(backend); return [{ bankId: `1@${backend}`, followed: false }]; } });
    let connecting = 0;
    context.openConnectModal = () => connecting++;

    // No bank connected: its connection
    context.bankBackends = [];
    context.openAddAccountsPicker();
    assert.equal(connecting, 1);
    assert.equal(context.showAddAccountsModal, false);

    // One bank: straight to its accounts, the list still offering a new bank
    context.bankBackends = [{ name: 'bnp', module: 'bnp' }];
    await context.openAddAccountsPicker();
    assert.deepEqual(listed, ['bnp']);
    assert.equal(context.addAccountsBackend, 'bnp');
    assert.equal(context.addAccountsPicking, true);
    context.closeAddAccounts();

    // Several: the bank is chosen first, nothing listed before
    context.bankBackends = [{ name: 'bnp', module: 'bnp' }, { name: 'cic_pro', module: 'cic' }];
    context.openAddAccountsPicker();
    assert.equal(context.showAddAccountsModal, true);
    assert.equal(context.addAccountsPicking, true);
    assert.equal(context.addAccountsBackend, '');
    assert.deepEqual(listed, ['bnp']);
    await context.chooseAddAccountsBackend('cic_pro');
    assert.equal(context.addAccountsBackend, 'cic_pro');
    assert.deepEqual(context.discoveredAccounts, [{ bankId: '1@cic_pro', followed: false }]);
    assert.equal(context.addAccountsPicking, true);
    // Back to no bank: nothing shown
    context.chooseAddAccountsBackend('');
    assert.equal(context.addAccountsBackend, '');
    assert.deepEqual(context.discoveredAccounts, []);
    // Last choice of the list: the connection of a new bank, instead of the modal
    context.chooseAddAccountsBackend(NEW_BANK);
    assert.equal(connecting, 2);
    assert.equal(context.showAddAccountsModal, false);
    assert.equal(context.addAccountsPicking, false);
});

test('accounts: renaming a connected bank updates it in the list', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const calls = [];
    const api = { renameBankBackend: async (backend, label) => { calls.push([backend, label]); return { name: backend, label: label || null }; } };
    const { methods } = createAccountsModule(() => api);
    const toasts = [];
    const context = {
        bankBackends: [{ name: 'bnp', module: 'bnp', label: null }, { name: 'cic_pro', module: 'cic', label: 'Pro' }],
        showToast: message => toasts.push(message),
        t: key => key,
    };
    for (const name of ['openBackendModal', 'submitBackendForm']) {
        context[name] = methods[name].bind(context);
    }

    context.openBackendModal(context.bankBackends[1]);
    assert.deepEqual(context.backendForm, { name: 'cic_pro', module: 'cic', label: 'Pro' });
    assert.equal(context.showBackendModal, true);
    context.backendForm.label = '  CIC pro  ';
    await context.submitBackendForm();
    assert.deepEqual(calls, [['cic_pro', 'CIC pro']]);
    assert.deepEqual(context.bankBackends.map(b => b.label), [null, 'CIC pro']);
    assert.equal(context.showBackendModal, false);
    assert.deepEqual(toasts, ['bankConnectionRenamed']);
});

test('accounts: removing a connected bank asks first in a modal, with the number of its followed accounts', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const removed = [];
    const api = { deleteBankBackend: async backend => { removed.push(backend); } };
    const { methods } = createAccountsModule(() => api);
    const toasts = [];
    const asked = [];
    let answer = false;
    let reloaded = 0;
    const context = {
        bankAccounts: [{ bank_name: 'bnp' }, { bank_name: 'bnp' }, { bank_name: 'cic_pro' }],
        bankModules: [{ module: 'bnp', description: 'BNP Paribas' }],
        showToast: message => toasts.push(message),
        t: (key, params) => `${key} ${JSON.stringify(params || {})}`,
        loadBankBackends: async () => { reloaded++; },
        loadBankAccounts: async () => { reloaded++; },
        // The confirmation modal: the action (removal) runs only when confirmed
        askConfirm: async options => {
            asked.push(options);
            if (answer) {
                await options.action();
            }
            return answer;
        },
    };
    for (const name of ['removeBackend', 'backendName']) {
        context[name] = methods[name].bind(context);
    }
    const backend = { name: 'bnp', module: 'bnp', label: null };

    // Cancelled: nothing removed
    await context.removeBackend(backend);
    assert.deepEqual(removed, []);
    assert.equal(asked[0].message, 'confirmDeleteBankConnection {"bank":"BNP Paribas (bnp)","count":2}');
    assert.equal(asked[0].busyLabel, 'bankConnectionDeleting {}');
    assert.equal(asked[0].danger, true);
    assert.equal(reloaded, 0);

    // Confirmed: removed by the action of the modal, then the lists reloaded
    answer = true;
    await context.removeBackend(backend);
    assert.deepEqual(removed, ['bnp']);
    assert.equal(reloaded, 2);
    assert.deepEqual(toasts, ['bankConnectionDeleted {}']);
});

test('confirm: answered by the buttons, the action kept running and its error shown in the modal', async () => {
    const { createConfirmModule } = await import('../../portal/modules/confirmModule.js');
    const { data, methods } = createConfirmModule();
    const context = { ...data(), apiErrorText };
    for (const [name, method] of Object.entries(methods)) {
        context[name] = method.bind(context);
    }

    // Cancelled
    let asked = context.askConfirm({ title: 'T', message: 'M', confirmLabel: 'OK' });
    assert.equal(context.confirmDialog.message, 'M');
    assert.equal(context.confirmDialog.warning, null);
    context.cancelConfirm();
    assert.equal(await asked, false);

    // With a warning: shown apart from the question
    asked = context.askConfirm({ title: 'T', message: 'M', warning: 'W', confirmLabel: 'OK' });
    assert.equal(context.confirmDialog.warning, 'W');
    context.cancelConfirm();
    assert.equal(await asked, false);
    assert.equal(context.confirmDialog, null);

    // Without action: confirmed at once
    asked = context.askConfirm({ title: 'T', message: 'M', confirmLabel: 'OK' });
    await context.acceptConfirm();
    assert.equal(await asked, true);

    // With an action: busy while it runs (no cancel, no second run), then closed
    let finish;
    let runs = 0;
    asked = context.askConfirm({ title: 'T', message: 'M', confirmLabel: 'OK', action: () => { runs++; return new Promise(resolve => { finish = resolve; }); } });
    const accepting = context.acceptConfirm();
    assert.equal(context.confirmDialog.busy, true);
    context.cancelConfirm();
    await context.acceptConfirm();
    assert.equal(runs, 1);
    assert.equal(context.confirmDialog.busy, true);
    finish();
    await accepting;
    assert.equal(await asked, true);
    assert.equal(context.confirmDialog, null);

    // A failed action: its error in the modal, which stays open and can be cancelled
    asked = context.askConfirm({ title: 'T', message: 'M', confirmLabel: 'OK', action: async () => { throw new Error('woob could not remove the bank'); } });
    await context.acceptConfirm();
    assert.equal(context.confirmDialog.busy, false);
    assert.equal(context.confirmDialog.error, 'woob could not remove the bank');
    context.cancelConfirm();
    assert.equal(await asked, false);
});

test('accounts: the woob repository is trusted only with the fingerprint shown, then updated', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const fingerprint = 'b5fc136c2d500b8483696a0ee722865842a4cc45';
    const calls = [];
    let trusted = false;
    const api = {
        fetchWoobStatus: async () => ({ repository: 'https://updates.woob.tech/3/main/', trusted, fingerprint, error: null }),
        updateWoob: async (onMessage, confirmed) => {
            calls.push(confirmed);
            if (!trusted && confirmed !== fingerprint) {
                onMessage(`Woob repository not trusted: ${fingerprint}`);
                return;
            }
            trusted = true;
            onMessage('100% All modules are up-to-date.');
            onMessage('Woob updated');
        },
    };
    const { methods, data } = createAccountsModule(() => api);
    const toasts = [];
    let reloaded = 0;
    const context = { ...data(), showToast: message => toasts.push(message), t: key => key, loadBankBackends: async () => { reloaded++; } };
    for (const name of ['openWoobUpdate', 'runWoobUpdate', 'onWoobUpdateLine', 'closeWoobUpdate']) {
        context[name] = methods[name].bind(context);
    }

    // Not trusted: shown, nothing run until the administrator trusts it
    await context.openWoobUpdate();
    assert.equal(context.woobUpdate.status.trusted, false);
    assert.deepEqual(calls, []);

    await context.runWoobUpdate(fingerprint);
    assert.deepEqual(calls, [fingerprint]);
    assert.equal(context.woobUpdate.done, true);
    assert.equal(context.woobUpdate.error, null);
    assert.deepEqual(toasts, ['woobUpdated']);
    // The banks woob supports are read again
    assert.equal(reloaded, 1);
    context.closeWoobUpdate();
    assert.equal(context.woobUpdate.open, false);

    // Trusted: started at once, without fingerprint
    await context.openWoobUpdate();
    assert.deepEqual(calls, [fingerprint, null]);
    assert.equal(context.woobUpdate.done, true);

    // A fingerprint refused by the server: shown again to compare, with the error
    context.onWoobUpdateLine(`Woob repository not trusted: ${'a'.repeat(40)}`);
    assert.equal(context.woobUpdate.status.trusted, false);
    assert.equal(context.woobUpdate.status.fingerprint, 'a'.repeat(40));
    assert.equal(context.woobUpdate.error, 'woobNotTrusted');
    context.onWoobUpdateLine('Woob update failed: network down');
    assert.equal(context.woobUpdate.error, 'network down');
});

test('accounts: the accounts found by woob, the followed ones ticked and not followed again', async () => {
    const { createAccountsModule } = await import('../../portal/modules/accountsModule.js');
    const { methods } = createAccountsModule(() => null);
    const context = {
        bankAccounts: [{ bankId: '111@bnp_2' }],
        bankModules: [{ module: 'bnp', description: 'BNP Paribas' }],
        discoveredAccounts: [],
        selectedAccounts: [],
    };
    for (const name of ['isFollowed', 'showDiscovered', 'accountsToFollow', 'backendName']) {
        context[name] = methods[name].bind(context);
    }

    context.showDiscovered([
        { bankId: '111@bnp_2', followed: false },
        { bankId: '222@bnp_2', followed: true },
        { bankId: '333@bnp_2', followed: false },
    ]);
    // Followed (in Carbure, or according to the API): ticked
    assert.deepEqual(context.selectedAccounts, ['111@bnp_2', '222@bnp_2']);
    assert.deepEqual(context.accountsToFollow(), []);
    context.selectedAccounts.push('333@bnp_2');
    assert.deepEqual(context.accountsToFollow().map(d => d.bankId), ['333@bnp_2']);

    assert.equal(context.backendName({ name: 'bnp_2', module: 'bnp' }), 'BNP Paribas (bnp_2)');
    assert.equal(context.backendName({ name: 'cic_pro', module: 'cic' }), 'cic_pro');
});

test('accountsByBank: the followed accounts under their bank, then the others', async () => {
    const { accountsByBank } = await import('../../portal/modules/accountsModule.js');
    const backends = [{ name: 'bnp', module: 'bnp' }, { name: 'cic_pro', module: 'cic' }, { name: 'bnp_2', module: 'bnp' }];
    const accounts = [
        { id: 1, bank_name: 'bank', bankId: 'fail@bank' },
        { id: 2, bank_name: 'bnp', bankId: '1@bnp' },
        { id: 3, bank_name: 'bnp_2', bankId: '2@bnp_2' },
        { id: 4, bank_name: 'bnp', bankId: '3@bnp' },
    ];
    const groups = accountsByBank(backends, accounts);
    // In the order of the connections, a connection without account kept, the others last
    assert.deepEqual(groups.map(g => g.key), ['bank-bnp', 'bank-cic_pro', 'bank-bnp_2', 'others']);
    assert.deepEqual(groups.map(g => g.accounts.map(a => a.id)), [[2, 4], [], [3], [1]]);
    assert.equal(groups[0].backend.name, 'bnp');
    assert.equal(groups[3].backend, null);

    // No account of an unknown bank: no "others" group; nothing at all: no group
    assert.deepEqual(accountsByBank(backends, accounts.slice(1)).map(g => g.key), ['bank-bnp', 'bank-cic_pro', 'bank-bnp_2']);
    assert.deepEqual(accountsByBank([], []), []);
    assert.deepEqual(accountsByBank(undefined, [{ id: 9, bank_name: 'x' }]).map(g => g.key), ['others']);
});

test('profile: sub-tabs, the two-factor one hidden before its migration; platform, model and versions of a device', async () => {
    const { createProfileModule, PROFILE_TABS } = await import('../../portal/modules/profileModule.js');
    assert.deepEqual(PROFILE_TABS.map(tab => tab.key), ['profile', 'security', 'notifications', 'devices']);
    const module = createProfileModule(() => null);
    const tabs = mfaStatus => module.computed.visibleProfileTabs.call({ profileTabs: PROFILE_TABS, mfaStatus }).map(tab => tab.key);
    assert.deepEqual(tabs(null), ['profile', 'security', 'notifications', 'devices']);
    assert.deepEqual(tabs({ available: true }), ['profile', 'security', 'notifications', 'devices']);
    assert.deepEqual(tabs({ available: false }), ['profile', 'notifications', 'devices']);

    const { devicePlatform } = module.methods;
    assert.deepEqual(devicePlatform({ platform: 'android' }), { icon: 'android', label: 'devicePlatform_android' });
    assert.deepEqual(devicePlatform({ platform: 'ios' }), { icon: 'phone_iphone', label: 'devicePlatform_ios' });
    // A server older than the platform column: iOS
    assert.deepEqual(devicePlatform({}), { icon: 'phone_iphone', label: 'devicePlatform_ios' });

    // Model and versions sent by the app, only those known
    const context = { t: (key, params) => `${key}:${params.version}` };
    const details = device => module.methods.deviceDetails.call(context, device);
    assert.equal(details({ model: 'iPhone16,1', os_version: 'iOS 26.0', app_version: '1.2' }), 'iPhone16,1 · iOS 26.0 · deviceAppVersion:1.2');
    assert.equal(details({ model: 'Google Pixel 8', os_version: null, app_version: ' ' }), 'Google Pixel 8');
    assert.equal(details({ os_version: 'Android 15', app_version: '1.0' }), 'Android 15 · deviceAppVersion:1.0');
    // A server older than these columns, or an app that does not send them
    assert.equal(details({ model: null, os_version: null, app_version: null }), '');
    assert.equal(details({}), '');
});

test('profile: QR code of the mobile apps, with the public address of the API when there is one', async () => {
    const { createProfileModule, appLinkOf, isLoopbackUrl } = await import('../../portal/modules/profileModule.js');
    assert.equal(appLinkOf('https://carbure.example.com/api'), 'https://carbure.org/app/?server=https%3A%2F%2Fcarbure.example.com%2Fapi%2F');
    assert.equal(appLinkOf('https://carbure.example.com/api//'), 'https://carbure.org/app/?server=https%3A%2F%2Fcarbure.example.com%2Fapi%2F');
    assert.equal(appLinkOf('http://192.168.1.10:8080/api/'), 'https://carbure.org/app/?server=http%3A%2F%2F192.168.1.10%3A8080%2Fapi%2F');

    for (const url of ['http://localhost/api', 'http://127.0.0.1:8000/api', 'http://[::1]/api', 'http://carbure.localhost/api']) {
        assert.equal(isLoopbackUrl(url), true, url);
    }
    for (const url of ['https://carbure.example.com/api', 'http://192.168.1.10/api', 'not a url', '']) {
        assert.equal(isLoopbackUrl(url), false, url);
    }

    const module = createProfileModule(() => null);
    const computed = context => Object.fromEntries(['appServerUrl', 'appServerUnreachable']
        .map(name => [name, module.computed[name].call({ ...context, appServerUrl: module.computed.appServerUrl.call(context) })]));
    // Without public_url: the address of the portal
    assert.deepEqual(computed({ appServer: null, apiBaseUrl: 'http://localhost:8000/api' }),
        { appServerUrl: 'http://localhost:8000/api', appServerUnreachable: true });
    assert.deepEqual(computed({ appServer: 'https://budget.example.org/api/', apiBaseUrl: 'http://localhost:8000/api' }),
        { appServerUrl: 'https://budget.example.org/api/', appServerUnreachable: false });
    assert.equal(module.computed.appLink.call({ appServerUrl: 'https://budget.example.org/api/' }),
        'https://carbure.org/app/?server=https%3A%2F%2Fbudget.example.org%2Fapi%2F');

    // The public address read from the API; on failure, the one of the portal
    let answer = { server: 'https://budget.example.org/api/' };
    const api = { fetchDeviceLink: async () => { if (answer instanceof Error) { throw answer; } return answer; } };
    const loading = createProfileModule(() => api);
    const context = { appServer: 'old' };
    await loading.methods.loadAppServer.call(context);
    assert.equal(context.appServer, 'https://budget.example.org/api/');
    answer = { server: null };
    await loading.methods.loadAppServer.call(context);
    assert.equal(context.appServer, null);
    answer = new Error('offline');
    context.appServer = 'x';
    const error = console.error;
    console.error = () => {};
    try {
        await loading.methods.loadAppServer.call(context);
    } finally {
        console.error = error;
    }
    assert.equal(context.appServer, null);
});

test('profile: the header toggle switches the theme at once and saves it in the profile', async () => {
    const { createProfileModule } = await import('../../portal/modules/profileModule.js');
    let choice = 'system';
    window.carbureTheme = {
        choice: () => choice,
        opposite: () => (choice === 'dark' ? 'light' : 'dark'),
        apply: value => { choice = value; return value; },
    };
    const saved = [];
    let failure = null;
    const api = {
        updateUserTheme: async (id, theme) => {
            saved.push([id, theme]);
            if (failure) {
                throw failure;
            }
            return { id, theme, email: 'marie@example.com' };
        },
    };
    const module = createProfileModule(() => api);
    const toasts = [];
    const context = {
        ...module.data(),
        currentUser: { id: 2, username: 'marie', theme: 'system' },
        t: key => key,
        apiErrorText,
        showToast: message => toasts.push(message),
    };
    context.toggleTheme = module.methods.toggleTheme.bind(context);
    try {
        // Light displayed (system): dark chosen, the profile select in sync
        await context.toggleTheme();
        assert.equal(choice, 'dark');
        assert.deepEqual(saved, [[2, 'dark']]);
        assert.equal(context.currentUser.theme, 'dark');
        assert.equal(context.currentUser.username, 'marie');
        assert.equal(context.profileForm.theme, 'dark');
        assert.equal(context.savingTheme, false);

        // Not saved: back to the previous choice, the error shown
        failure = new Error('Network down');
        await context.toggleTheme();
        assert.deepEqual(saved[1], [2, 'light']);
        assert.equal(choice, 'dark');
        assert.equal(context.profileForm.theme, 'dark');
        assert.deepEqual(toasts, ['Network down']);

        // Label and icon: the action of the button
        const label = displayedTheme => module.computed.themeToggleLabel.call({ displayedTheme, t: key => key });
        const icon = displayedTheme => module.computed.themeToggleIcon.call({ displayedTheme });
        assert.equal(label('light'), 'switchToDarkTheme');
        assert.equal(icon('light'), 'dark_mode');
        assert.equal(label('dark'), 'switchToLightTheme');
        assert.equal(icon('dark'), 'light_mode');
    } finally {
        delete window.carbureTheme;
    }
});

test('config: a password is never shown, only typed to replace it', async () => {
    const { createConfigModule } = await import('../../portal/modules/configModule.js');
    const { methods, computed } = createConfigModule(() => null);
    const context = { ...methods };
    context.setConfig({ sections: [{ name: 'smtp', settings: [
        { key: 'smtp_host', value: 'smtp.example.com', editable: true, kind: 'text' },
        { key: 'smtp_password', value: '••••••••••••', set: true, secret: true, editable: true, kind: 'password' },
    ] }] });
    assert.deepEqual(context.configDraft, { smtp_host: 'smtp.example.com', smtp_password: '' });
    assert.deepEqual(computed.configChanges.call(context), {});

    context.configDraft.smtp_password = 'new-secret';
    assert.deepEqual(computed.configChanges.call(context), { smtp_password: 'new-secret' });
});

test('config: the test email shows the recipient, or the error of the server', async () => {
    const { createConfigModule } = await import('../../portal/modules/configModule.js');
    let answer = () => ({ sent: true, to: 'admin@example.com' });
    const calls = [];
    const api = { sendTestEmail: async to => { calls.push(to); return answer(); } };
    const { methods, data } = createConfigModule(() => api);
    const context = { ...data(), ...methods, apiErrorText, t: (key, params) => `${key} ${JSON.stringify(params || {})}` };

    context.smtpTestTo = '  ';
    await context.sendTestEmail();
    assert.deepEqual(calls, ['']);
    assert.deepEqual(context.smtpTestResult, { ok: true, message: 'smtpTestSent {"to":"admin@example.com"}' });
    assert.equal(context.sendingTestEmail, false);

    answer = () => { throw new Error('The SMTP server refused the recipient: 550'); };
    context.smtpTestTo = 'refused@example.com';
    await context.sendTestEmail();
    assert.deepEqual(calls, ['', 'refused@example.com']);
    assert.deepEqual(context.smtpTestResult, { ok: false, message: 'The SMTP server refused the recipient: 550' });
});

test('config: registration on the push service after consent, renewal after confirmation, test push', async () => {
    const { createConfigModule, pushTestOutcome } = await import('../../portal/modules/configModule.js');
    const configWithKey = set => ({ file: 'conf/prod.ini', writable: true, sections: [{ name: 'push', settings: [
        { key: 'push_api_key', value: set ? '••••••••••••' : '', set, secret: true, editable: true, kind: 'password' },
    ] }] });
    const KEY = `cpk_0123456789abcdef_${'ab'.repeat(32)}`;
    const calls = [];
    let registered = false;
    let consent = null;
    let relay = 'https://relay.example.org';
    let testAnswer = () => ({ sent: 1, devices: 2, results: [] });
    const api = {
        registerPush: async given => {
            calls.push(given ? 'register+consent' : 'register');
            if (given) {
                consent = { at: '2026-10-17T08:00:00Z', url: relay };
            }
            const renewed = registered;
            registered = true;
            return { registered: true, renewed, key_id: 'abc' };
        },
        fetchPushInfo: async () => ({ url: relay, official: false, registered, api_key: registered ? KEY : '', consent, consent_valid: !!consent && consent.url === relay }),
        fetchConfig: async () => configWithKey(registered),
        fetchPushStatus: async () => ({ relay: { reachable: true, status: 'ok', version: '1.0.0', error: null }, key: { state: registered ? 'valid' : 'none' } }),
        sendTestPush: async () => testAnswer(),
    };
    const { methods, data, computed } = createConfigModule(() => api);
    const context = { ...data(), ...methods, apiErrorText, t: (key, params) => `${key} ${JSON.stringify(params || {})}` };
    Object.defineProperty(context, 'pushKeySet', { get: () => computed.pushKeySet.call(context) });
    Object.defineProperty(context, 'pushKeyDisplay', { get: () => computed.pushKeyDisplay.call(context) });
    context.setConfig(configWithKey(false));
    assert.equal(context.pushKeySet, false);

    // First registration: the consent dialog, nothing sent until it is ticked
    await context.registerPush();
    assert.deepEqual(calls, []);
    assert.equal(context.pushConsent.accepted, false);
    assert.equal(context.pushInfo.url, 'https://relay.example.org');
    await context.acceptPushConsent();
    assert.deepEqual(calls, []);
    context.closePushConsent();
    assert.equal(context.pushConsent, null);

    await context.registerPush();
    context.pushConsent.accepted = true;
    await context.acceptPushConsent();
    assert.equal(context.pushConsent, null);
    assert.deepEqual(calls, ['register+consent']);
    assert.deepEqual(context.pushRegisterResult, { ok: true, message: 'pushRegistered {"key":"abc"}' });
    assert.equal(context.pushKeySet, true);
    assert.equal(context.registeringPush, false);
    assert.equal(context.pushInfo.consent_valid, true);
    // The API key: its public id only, in full once revealed, hidden again on reload
    assert.equal(context.pushKeyDisplay, `cpk_0123456789abcdef_${'•'.repeat(12)}`);
    context.pushKeyRevealed = true;
    assert.equal(context.pushKeyDisplay, KEY);
    await context.loadPushInfo();
    assert.equal(context.pushKeyRevealed, false);

    // A key already saved, the consent given for this relay: renewed only after confirmation
    window.confirm = () => false;
    await context.registerPush();
    assert.deepEqual(calls, ['register+consent']);
    window.confirm = () => true;
    await context.registerPush();
    assert.deepEqual(calls, ['register+consent', 'register']);
    assert.equal(context.pushRegisterResult.message, 'pushRenewed {"key":"abc"}');

    // Another relay: the consent asked again
    relay = 'https://other.example.org';
    await context.registerPush();
    assert.equal(context.pushConsent.accepted, false);
    assert.equal(calls.length, 2);
    context.closePushConsent();

    // The push service unreadable: nothing asked, the error shown
    const fetchPushInfo = api.fetchPushInfo;
    api.fetchPushInfo = async () => { throw new Error('down'); };
    await context.registerPush();
    assert.deepEqual(context.pushRegisterResult, { ok: false, message: 'pushInfoError {}' });
    assert.equal(context.pushConsent, null);
    api.fetchPushInfo = fetchPushInfo;

    relay = 'https://relay.example.org';
    api.registerPush = async () => { throw new Error('Push service registration refused'); };
    await context.registerPush();
    assert.deepEqual(context.pushRegisterResult, { ok: false, message: 'Push service registration refused' });

    await context.sendTestPush();
    assert.deepEqual(context.pushTestResult, { ok: true, message: 'pushTestSent {"count":1,"devices":2}' });
    testAnswer = () => { throw new Error('No device'); };
    await context.sendTestPush();
    assert.deepEqual(context.pushTestResult, { ok: false, message: 'No device' });
    assert.equal(context.sendingTestPush, false);

    assert.deepEqual(pushTestOutcome({ sent: 0, devices: 2, results: [
        { device: 'a', sent: false, status: 'unregistered', error: 'Unregistered' },
        { device: 'b', sent: false, status: 'error', error: null },
    ] }), { ok: false, key: 'pushTestFailed', params: { errors: 'Unregistered, error' } });
});

test('notifications: why the push channel is disabled', async () => {
    const { pushUnavailableReason, createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    assert.equal(pushUnavailableReason(null), '');
    assert.equal(pushUnavailableReason({ push_available: true }), '');
    // A server older than the push service: available
    assert.equal(pushUnavailableReason({}), '');
    assert.equal(pushUnavailableReason({ push_available: false }), 'notificationsPushUnavailable');

    const { methods } = createNotificationsModule(() => null);
    const context = { ...methods, pushUnavailable: 'notificationsPushUnavailable', emailUnavailable: '',
        notifications: { preferences: { sync: { push: true, email: false }, rule: { push: false, email: false } } } };
    // Push unavailable: every push channel disabled, on or off
    assert.equal(context.notificationDisabled('sync', 'push'), true);
    assert.equal(context.notificationDisabled('rule', 'push'), true);
    assert.equal(context.notificationDisabled('rule', 'email'), false);
    assert.equal(context.notificationUnavailableId('push'), 'notifications-push-unavailable');
    assert.equal(context.notificationUnavailableId('email'), null);
});

test('notifications: why the email channel is disabled', async () => {
    const { emailUnavailableReason } = await import('../../portal/modules/notificationsModule.js');
    assert.equal(emailUnavailableReason(null), '');
    assert.equal(emailUnavailableReason({ smtp: true, address: 'a@example.com', email_available: true }), '');
    assert.equal(emailUnavailableReason({ smtp: false, address: 'a@example.com', email_available: false }), 'notificationsEmailNoSmtp');
    assert.equal(emailUnavailableReason({ smtp: true, address: null, email_available: false }), 'notificationsEmailNoAddress');
});

test('notifications: the budget alert threshold is an integer from 50 to 200', async () => {
    const { budgetThresholdError, NOTIFICATION_KINDS } = await import('../../portal/modules/notificationsModule.js');
    assert.ok(NOTIFICATION_KINDS.includes('budget'));
    for (const value of [50, 100, '120', ' 200 ']) {
        assert.equal(budgetThresholdError(value), '', String(value));
    }
    for (const value of ['', null, undefined, 49, 201, '80.5', 'abc', -100]) {
        assert.equal(budgetThresholdError(value), 'budgetThresholdInvalid', String(value));
    }
});

test('notifications: the budget alert threshold is loaded, checked and saved', async () => {
    const { createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    const sent = [];
    let refuse = false;
    const api = {
        fetchNotifications: async () => ({ preferences: {}, smtp: true, address: 'a@example.com', email_available: true, budget_threshold: 90 }),
        updateBudgetThreshold: async threshold => {
            sent.push(threshold);
            if (refuse) {
                throw new Error('Refused');
            }
            return { preferences: {}, smtp: true, address: 'a@example.com', email_available: true, budget_threshold: threshold };
        },
    };
    const { methods, data } = createNotificationsModule(() => api);
    const toasts = [];
    const context = { ...data(), ...methods, apiErrorText, t: key => key, showToast: message => toasts.push(message) };
    assert.equal(context.budgetThreshold, 100);

    await context.loadNotifications();
    assert.equal(context.budgetThreshold, 90);

    // Invalid: not sent, the error shown
    context.budgetThreshold = 300;
    await context.saveBudgetThreshold();
    assert.deepEqual(sent, []);
    assert.equal(context.budgetThresholdErrorKey, 'budgetThresholdInvalid');

    context.budgetThreshold = '80';
    await context.saveBudgetThreshold();
    assert.deepEqual(sent, [80]);
    assert.equal(context.budgetThresholdErrorKey, '');
    assert.equal(context.notifications.budget_threshold, 80);
    assert.deepEqual(toasts, ['notificationsSaved']);
    assert.equal(context.savingBudgetThreshold, false);

    refuse = true;
    await context.saveBudgetThreshold();
    assert.equal(toasts.at(-1), 'Refused');
    assert.equal(context.savingBudgetThreshold, false);
});

test('notifications: the large expense threshold is empty or a positive amount', async () => {
    const { alertThresholdError } = await import('../../portal/modules/notificationsModule.js');
    for (const value of ['', null, undefined, 0, '150', 99.5, ' 20 ']) {
        assert.equal(alertThresholdError(value), '', String(value));
    }
    for (const value of [-1, 'abc', '-0.5']) {
        assert.equal(alertThresholdError(value), 'alertThresholdInvalid', String(value));
    }
});

test('notifications: the large expense threshold is saved at once', async () => {
    const { createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    const sent = [];
    let refuse = false;
    let stored = { alert_threshold: 150 };
    const answer = () => ({ preferences: {}, smtp: true, address: 'a@example.com', email_available: true, budget_threshold: 100, budgets: [], ...stored });
    const api = {
        fetchNotifications: async () => answer(),
        updateAlertSettings: async settings => {
            sent.push(settings);
            if (refuse) {
                throw new Error('Refused');
            }
            stored = { alert_threshold: Number(settings.alertThreshold) || null };
            return answer();
        },
    };
    const { methods, data } = createNotificationsModule(() => api);
    const toasts = [];
    const context = { ...data(), ...methods, apiErrorText, t: key => key, showToast: message => toasts.push(message) };

    await context.loadNotifications();
    assert.equal(context.alertThreshold, '150');

    // Invalid amount: not sent
    context.alertThreshold = '-5';
    await context.saveAlertThreshold();
    assert.deepEqual(sent, []);
    assert.equal(context.alertThresholdErrorKey, 'alertThresholdInvalid');

    // Emptied: turned off
    context.alertThreshold = '';
    await context.saveAlertThreshold();
    assert.deepEqual(sent, [{ alertThreshold: '' }]);
    assert.equal(context.alertThreshold, '');
    assert.equal(context.alertThresholdErrorKey, '');
    assert.deepEqual(toasts, ['notificationsSaved']);

    refuse = true;
    context.alertThreshold = '80';
    await context.saveAlertThreshold();
    assert.equal(toasts.at(-1), 'Refused');
    assert.equal(context.savingAlertThreshold, false);
});

test('notifications: the global budget threshold and the thresholds of categories', async () => {
    const { createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    const sent = [];
    let refuse = false;
    let stored = { budget_all: true, budget_thresholds: [] };
    const budgets = [{ id: 3, name: 'Logement' }, { id: 4, name: 'Alimentation' }, { id: 5, name: 'Loisirs' }];
    const answer = () => ({ preferences: {}, smtp: true, address: null, email_available: false, budget_threshold: 100, alert_threshold: null, budgets, ...stored });
    const api = {
        fetchNotifications: async () => answer(),
        updateBudgetAlerts: async settings => {
            sent.push(settings);
            if (refuse) {
                throw new Error('Refused');
            }
            stored = {
                budget_all: 'budgetAll' in settings ? settings.budgetAll : stored.budget_all,
                budget_thresholds: 'budgetThresholds' in settings ? settings.budgetThresholds.map(({ category, threshold }) => ({ category: Number(category), threshold })) : stored.budget_thresholds,
            };
            return answer();
        },
    };
    const { methods, data, computed } = createNotificationsModule(() => api);
    const toasts = [];
    const context = { ...data(), ...methods, apiErrorText, t: key => key, showToast: message => toasts.push(message) };
    Object.defineProperty(context, 'freeBudgets', { get: computed.freeBudgets.bind(context) });

    await context.loadNotifications();
    assert.equal(context.budgetAll, true);
    assert.deepEqual(context.budgetRows, []);

    // A threshold for the first budget without one, at the global threshold
    await context.addBudgetRow();
    assert.deepEqual(sent.at(-1), { budgetThresholds: [{ category: '3', threshold: 100 }] });
    await context.addBudgetRow();
    assert.deepEqual(sent.at(-1).budgetThresholds.map(row => row.category), ['3', '4']);
    assert.deepEqual(context.freeBudgets.map(budget => budget.id), [5]);
    // A category offers its own budget and those of no other threshold
    assert.deepEqual(context.budgetRowOptions(context.budgetRows[0]).map(budget => budget.id), [3, 5]);
    assert.equal(context.budgetRowName(context.budgetRows[1]), 'Alimentation');

    // 80 % on groceries
    context.budgetRows[1].threshold = '80';
    await context.saveBudgetRows();
    assert.deepEqual(sent.at(-1), { budgetThresholds: [{ category: '3', threshold: 100 }, { category: '4', threshold: 80 }] });
    assert.equal(context.budgetRows[1].threshold, '80');

    // Out of range: not sent, error shown
    const count = sent.length;
    context.budgetRows[0].threshold = '30';
    await context.saveBudgetRows();
    assert.equal(sent.length, count);
    assert.equal(context.budgetRowsErrorKey, 'budgetThresholdInvalid');
    assert.equal(context.budgetRowInvalid(context.budgetRows[0]), true);
    assert.equal(context.budgetRowInvalid(context.budgetRows[1]), false);
    context.budgetRows[0].threshold = '100';

    // Only these two categories: the global threshold off
    context.budgetAll = false;
    await context.saveBudgetAll();
    assert.deepEqual(sent.at(-1), { budgetAll: false });
    assert.equal(context.budgetAll, false);

    // Removed: its budget follows the global threshold again
    await context.removeBudgetRow(context.budgetRows[0]);
    assert.deepEqual(sent.at(-1), { budgetThresholds: [{ category: '4', threshold: 80 }] });
    assert.equal(context.budgetRows.length, 1);

    // Refused: back to the settings saved
    refuse = true;
    context.budgetAll = true;
    await context.saveBudgetAll();
    assert.equal(context.budgetAll, false);
    assert.equal(toasts.at(-1), 'Refused');
    assert.equal(context.savingBudgetRules, false);
});

test('notifications: a change is saved at once, undone if refused', async () => {
    const { createNotificationsModule } = await import('../../portal/modules/notificationsModule.js');
    const preferences = () => ({ sync: { push: true, email: false }, rule: { push: true, email: false }, expense: { push: true, email: false } });
    const sent = [];
    let refuse = false;
    const api = {
        fetchNotifications: async () => ({ preferences: preferences(), smtp: false, address: 'a@example.com', email_available: false }),
        updateNotifications: async change => {
            sent.push(change);
            if (refuse) {
                throw new Error('Invalid value');
            }
            const result = { preferences: preferences(), smtp: true, address: 'a@example.com', email_available: true };
            result.preferences.sync.push = false;
            return result;
        },
    };
    const { methods, computed, data } = createNotificationsModule(() => api);
    const toasts = [];
    const context = { ...data(), ...methods, apiErrorText, t: key => key, showToast: message => toasts.push(message) };
    Object.defineProperty(context, 'emailUnavailable', { get: () => computed.emailUnavailable.call(context) });

    await context.loadNotifications();
    assert.equal(context.emailUnavailable, 'notificationsEmailNoSmtp');
    // Email off and unavailable: disabled; push always changeable
    assert.equal(context.notificationDisabled('sync', 'email'), true);
    assert.equal(context.notificationDisabled('sync', 'push'), false);
    // An email channel already on stays changeable, to turn it off
    context.notifications.preferences.rule.email = true;
    assert.equal(context.notificationDisabled('rule', 'email'), false);

    await context.setNotification('sync', 'push', false);
    assert.deepEqual(sent, [{ sync: { push: false } }]);
    assert.equal(context.notifications.preferences.sync.push, false);
    assert.deepEqual(toasts, ['notificationsSaved']);
    assert.equal(context.savingNotification, null);

    refuse = true;
    await context.setNotification('expense', 'email', true);
    assert.equal(context.notifications.preferences.expense.email, false);
    assert.deepEqual(toasts, ['notificationsSaved', 'Invalid value']);

    // Unreadable: no table, the error shown
    api.fetchNotifications = async () => { throw new Error('Failed to read the notifications (500)'); };
    await context.loadNotifications();
    assert.equal(context.notifications, null);
    assert.equal(toasts.at(-1), 'Failed to read the notifications (500)');
});

test('config: status of the push service, the relay and the API key as shown', async () => {
    const { createConfigModule, pushRelayState, pushKeyState } = await import('../../portal/modules/configModule.js');
    assert.deepEqual(pushRelayState({ status: 'ok', version: '1.0.0' }), { level: 'ok', icon: 'check_circle', label: 'pushRelayOk' });
    assert.deepEqual(pushRelayState({ status: 'unreachable' }), { level: 'error', icon: 'error', label: 'pushRelayUnreachable' });
    assert.deepEqual(pushRelayState({ status: 'error' }), { level: 'error', icon: 'error', label: 'pushRelayError' });
    assert.equal(pushRelayState(null).label, 'pushStatusLoading');

    assert.deepEqual(pushKeyState({ state: 'valid', key_id: 'abc', devices: 2, used_today: 7, limits: { devices: 20, notifications_per_day: 500 } }),
        { level: 'ok', icon: 'check_circle', label: 'pushKeyValid', params: { id: 'abc', devices: 2, devicesLimit: 20, used: 7, usedLimit: 500 } });
    assert.equal(pushKeyState({ state: 'valid', key_id: 'abc', devices: 0, used_today: 0, limits: null }).params.devicesLimit, '?');
    assert.deepEqual(pushKeyState({ state: 'invalid', error: 'revoked' }), { level: 'error', icon: 'error', label: 'pushKeyInvalid', params: {} });
    assert.deepEqual(pushKeyState({ state: 'none' }), { level: 'warning', icon: 'warning', label: 'pushKeyNone', params: {} });
    assert.equal(pushKeyState({ state: 'unknown' }).label, 'pushKeyUnknown');
    assert.equal(pushKeyState(null).level, 'unknown');

    let answer = async () => ({ relay: { status: 'ok' }, key: { state: 'none' } });
    const api = {
        fetchPushInfo: async () => ({ url: 'https://api.carbure.org', registered: false, api_key: '', config_writable: true }),
        fetchPushStatus: () => answer(),
    };
    const { methods, data } = createConfigModule(() => api);
    const context = { ...data(), ...methods, apiErrorText, t: key => key };
    await context.loadPushTab();
    assert.equal(context.pushInfo.url, 'https://api.carbure.org');
    assert.deepEqual(context.pushStatus.key, { state: 'none' });
    assert.equal(context.loadingPushStatus, false);

    answer = async () => { throw new Error('Failed to load the status of the push service'); };
    await context.loadPushStatus();
    assert.equal(context.pushStatus, null);
    assert.equal(context.pushStatusError, 'Failed to load the status of the push service');
});

test('rules: keep the existing categories, loaded, switched and reflected in the resynchronization message', async () => {
    let keep = true;
    let refuse = false;
    const sent = [];
    const api = {
        fetchRules: async () => [{ id: 1, keyword: 'EDF', category: 3, category_name: 'Énergie', notify: 0 }],
        fetchCategories: async () => CATEGORIES,
        fetchRuleSettings: async () => ({ keep_categorized: keep }),
        updateRuleSettings: async value => {
            sent.push(value);
            if (refuse) {
                throw new Error('Forbidden - Administrator only');
            }
            keep = value;
            return { keep_categorized: keep };
        },
        applyRules: async () => ({ updated: 2 }),
    };
    const module = createRulesModule(() => api);
    const toasts = [];
    const context = { ...module.data(), ...module.methods, apiErrorText, t: (key, params) => (params ? `${key}:${params.count}` : key), showToast: message => toasts.push(message) };

    await context.loadRules();
    assert.equal(context.keepCategorized, true);
    await context.applyRules();
    assert.equal(toasts.at(-1), 'rulesAppliedUncategorized:2');

    await context.toggleKeepCategorized();
    assert.deepEqual(sent, [false]);
    assert.equal(context.keepCategorized, false);
    assert.equal(toasts.at(-1), 'rulesKeepCategorizedOff');
    await context.applyRules();
    assert.equal(toasts.at(-1), 'rulesApplied:2');

    await context.toggleKeepCategorized();
    assert.equal(context.keepCategorized, true);
    assert.equal(toasts.at(-1), 'rulesKeepCategorizedOn');

    // Refused: the switch is shown again as it was (#342), the error shown
    refuse = true;
    const input = { checked: false };
    await context.toggleKeepCategorized({ target: input });
    assert.deepEqual(sent, [false, true, false]);
    assert.equal(context.keepCategorized, true);
    assert.equal(input.checked, true);
    assert.equal(context.switchingKeepCategorized, false);
    assert.equal(toasts.at(-1), 'Forbidden - Administrator only');
});

test('profile: banner of a new release for the administrators, hidden for that version', async () => {
    const { createProfileModule } = await import('../../portal/modules/profileModule.js');
    let answer = { enabled: true, current: '1.9.1', latest: '1.10.0', url: 'https://github.com/ltoinel/Carbure/releases/tag/v1.10.0', available: true };
    const api = { fetchRelease: async () => { if (!answer) { throw new Error('Forbidden'); } return answer; } };
    const stored = {};
    globalThis.localStorage = { getItem: key => stored[key] ?? null, setItem: (key, value) => { stored[key] = value; } };
    try {
        const { methods, data, computed } = createProfileModule(() => api);
        const context = { ...data(), ...methods };
        Object.defineProperty(context, 'releaseBanner', { get: computed.releaseBanner.bind(context) });

        assert.equal(context.releaseBanner, false);
        await context.loadRelease();
        assert.equal(context.releaseBanner, true);

        // Hidden for 1.10.0, in this browser
        context.dismissRelease();
        assert.equal(context.releaseBanner, false);
        assert.equal(stored.releaseDismissed, '1.10.0');
        assert.equal(createProfileModule(() => api).data().releaseDismissed, '1.10.0');

        // A newer one: shown again
        answer = { ...answer, latest: '1.11.0' };
        await context.loadRelease();
        assert.equal(context.releaseBanner, true);

        // Nothing newer, or the route refused: no banner
        answer = { ...answer, available: false };
        await context.loadRelease();
        assert.equal(context.releaseBanner, false);
        answer = null;
        await context.loadRelease();
        assert.equal(context.releaseStatus, null);
        assert.equal(context.releaseBanner, false);
    } finally {
        delete globalThis.localStorage;
    }
});

test('recurring calendar: weeks from Monday, received on their day, expected, late or missing on the usual one', () => {
    const series = [
        { label: 'SALAIRE', amount: 2500, average: 2500, variable: false, day: 28, status: 'expected', ids: [] },
        { label: 'PRLV EDF', amount: -120, average: -90, variable: true, day: 5, date: '2026-10-06', status: 'received', ids: [7] },
        { label: 'CB NETFLIX', amount: -13.49, average: -13.49, variable: false, day: 20, status: 'expected', ids: [] },
        { label: 'PRLV LOYER', amount: -800, average: -800, variable: false, day: 31, status: 'expected', ids: [] },
        { label: 'PRLV ASSURANCE', amount: -40, average: -42, variable: true, day: 3, status: 'expected', ids: [] },
    ];
    const october = recurringCalendar(series, 2026, 10, new Date(2026, 9, 25));

    // October 2026 starts on a Thursday: 3 empty days, 5 weeks
    assert.equal(october.weeks.length, 5);
    assert.deepEqual(october.weeks[0].slice(0, 4).map(cell => cell && cell.day), [null, null, null, 1]);
    assert.ok(october.weeks.every(week => week.length === 7));
    const cells = october.weeks.flat().filter(Boolean);
    const at = day => cells[day - 1].items.map(item => [item.series.label, item.status, item.amount, item.approx]);
    assert.equal(cells.length, 31);
    assert.equal(cells.find(cell => cell.today).day, 25);

    // Received on the day of its transaction, its real amount
    assert.deepEqual(at(6), [['PRLV EDF', 'received', -120, false]]);
    assert.deepEqual(at(5), []);
    // Expected, its day past in the current month: late, its average when it varies
    assert.deepEqual(at(3), [['PRLV ASSURANCE', 'late', -42, true]]);
    assert.deepEqual(at(20), [['CB NETFLIX', 'late', -13.49, false]]);
    assert.deepEqual(at(28), [['SALAIRE', 'expected', 2500, false]]);
    assert.deepEqual(october.received, { count: 1, total: -120 });
    assert.deepEqual(october.expected, { count: 4, total: 1644.51 });

    // A past month: missing; the 31st on the last day of a shorter month, no today
    const september = recurringCalendar(series, 2026, 9, new Date(2026, 9, 25));
    const septemberCells = september.weeks.flat().filter(Boolean);
    assert.equal(septemberCells.length, 30);
    assert.ok(!septemberCells.some(cell => cell.today));
    assert.deepEqual(septemberCells[29].items.map(item => [item.series.label, item.status]), [['PRLV LOYER', 'missing']]);
    // A received series whose date is not in the month (never: its usual day)
    assert.deepEqual(septemberCells[4].items.map(item => item.status), ['received']);

    // A future month: every one expected
    const november = recurringCalendar(series, 2026, 11, new Date(2026, 9, 25));
    assert.ok(november.weeks.flat().filter(Boolean).flatMap(cell => cell.items)
        .filter(item => item.status !== 'received').every(item => item.status === 'expected'));
});

test('recurring calendar: opened, the transaction of a received series shown from it', () => {
    const module = createTransactionModule(() => ({}));
    const opened = [];
    const context = {
        ...module.data(), ...module.methods,
        transactions: [{ id: 7, label: 'PRLV EDF' }],
        openTransactionModal: transaction => opened.push(transaction),
    };

    context.openRecurringCalendar();
    assert.equal(context.showRecurringCalendar, true);
    context.openRecurringTransaction({ series: { ids: [8] } });
    assert.equal(context.showRecurringCalendar, true);
    assert.deepEqual(opened, []);
    context.openRecurringTransaction({ series: { ids: ['7'] } });
    assert.equal(context.showRecurringCalendar, false);
    assert.deepEqual(opened, [{ id: 7, label: 'PRLV EDF' }]);

    context.openRecurringCalendar();
    context.closeRecurringCalendar();
    assert.equal(context.showRecurringCalendar, false);
});

test('a refused change of the MCP server shows its switch again as it was', async () => {
    const api = { updateMcpSettings: async () => { throw new Error('Forbidden - Administrator only'); } };
    const module = createAgentsModule(() => api);
    const toasts = [];
    const context = { ...module.data(), ...module.methods, apiErrorText, t: key => key, showToast: message => toasts.push(message), mcpEnabled: false };

    // The browser already moved the switch: Vue would not move it back, the value being unchanged (#342)
    const input = { checked: true };
    await context.toggleMcp({ target: input });
    assert.equal(context.mcpEnabled, false);
    assert.equal(input.checked, false);
    assert.equal(context.switchingMcp, false);
    assert.equal(toasts.at(-1), 'Forbidden - Administrator only');
});

test('deleteSelectedTransactions deletes the parts first, keeps the selection of the ones not deleted', async () => {
    const deleted = [];
    let failOn = null;
    const module = createTransactionModule(() => ({
        deleteTransaction: async id => {
            if (id === failOn) {
                throw new Error('Transaction not found');
            }
            deleted.push(id);
        },
    }));
    const context = {
        ...module.data(),
        transactions: [
            { id: 1, amount: '-62.40', split_of: null },
            { id: 9, amount: '-20.00', split_of: 1 },
            { id: 2, amount: '3200.00', split_of: null },
        ],
        searchResults: [], isSearching: false, selectedMonth: 10, selectedYear: 2026,
        selectingTransactions: true, selectedTransactionIds: [1, 9],
        toasts: [], reloads: 0,
        t: (key, params) => `${key} ${JSON.stringify(params ?? {})}`,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
        loadPeriods() {},
        loadTransactions() { this.reloads++; },
    };

    // Not confirmed: nothing deleted
    globalThis.confirm = () => false;
    await module.methods.deleteSelectedTransactions.call(context);
    assert.deepEqual(deleted, []);

    // The part before its transaction, then the selection ends
    globalThis.confirm = () => true;
    await module.methods.deleteSelectedTransactions.call(context);
    assert.deepEqual(deleted, [9, 1]);
    assert.deepEqual(context.toasts, ['selectionDeleted {"count":2}']);
    assert.equal(context.selectingTransactions, false);
    assert.equal(context.reloads, 1);

    // A refusal: the ones deleted stay so, the others stay selected
    context.selectingTransactions = true;
    context.selectedTransactionIds = [2, 5];
    failOn = 5;
    await module.methods.deleteSelectedTransactions.call(context);
    assert.deepEqual(context.selectedTransactionIds, [5]);
    assert.equal(context.selectingTransactions, true);
    assert.equal(context.toasts.at(-1), 'Transaction not found');
    assert.equal(context.reloads, 2);
    assert.equal(context.deletingSelection, false);
    delete globalThis.confirm;
});

test('categorizeSelectedTransactions sets the category chosen on the whole selection, uncategorized included', async () => {
    const sent = [];
    let refuse = false;
    const module = createTransactionModule(() => ({
        setTransactionsCategory: async (ids, category) => {
            if (refuse) {
                throw new Error('Category not found');
            }
            sent.push([ids, category]);
        },
    }));
    const context = {
        ...module.data(),
        transactions: [
            { id: 1, category: 2, pointed: 0, split_of: null },
            { id: 9, category: 3, pointed: 0, split_of: 1 },
            { id: 2, category: 4, pointed: 0, split_of: null },
        ],
        searchResults: [{ id: 2, category: 4, pointed: 0, split_of: null }],
        ruleCategories: [{ id: 0, name: 'Défaut' }, { id: 5, name: 'Courses' }],
        selectingTransactions: true, selectedTransactionIds: [1, 2],
        toasts: [],
        t: (key, params) => `${key} ${JSON.stringify(params ?? {})}`,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
    };

    // No category chosen: nothing sent
    await module.methods.categorizeSelectedTransactions.call(context, '');
    assert.deepEqual(sent, []);

    // The selection gets it and is checked, the part of a split one is checked only; the selection stays
    await module.methods.categorizeSelectedTransactions.call(context, 5);
    assert.deepEqual(sent, [[[1, 2], 5]]);
    assert.deepEqual(context.transactions.map(t => [t.id, t.category, t.pointed]), [[1, 5, 1], [9, 3, 1], [2, 5, 1]]);
    assert.deepEqual(context.searchResults.map(t => [t.category, t.pointed]), [[5, 1]]);
    assert.deepEqual(context.toasts, ['selectionCategorized {"count":2,"name":"Courses"}']);
    assert.deepEqual([context.selectingTransactions, context.selectedTransactionIds], [true, [1, 2]]);

    // Uncategorized (0) is a choice
    context.selectedTransactionIds = [2];
    await module.methods.categorizeSelectedTransactions.call(context, 0);
    assert.deepEqual(sent.at(-1), [[2], 0]);
    assert.equal(context.transactions[2].category, 0);

    // A change already being saved: another one is ignored
    context.categorizingSelection = true;
    await module.methods.categorizeSelectedTransactions.call(context, 5);
    assert.equal(sent.length, 2);
    context.categorizingSelection = false;

    // A refusal: nothing changes, the selection stays
    refuse = true;
    context.selectedTransactionIds = [1];
    await module.methods.categorizeSelectedTransactions.call(context, 99);
    assert.deepEqual([context.selectingTransactions, context.selectedTransactionIds, context.transactions[0].category], [true, [1], 5]);
    assert.equal(context.toasts.at(-1), 'Category not found');
    assert.equal(context.categorizingSelection, false);
});

test('toggleSelectAll selects the transactions shown, then none', () => {
    const module = createTransactionModule(() => ({}));
    const context = {
        ...module.data(),
        transactions: [
            { id: 1, amount: '-10', pointed: 1, split_of: null },
            { id: 2, amount: '-20', pointed: 0, split_of: null },
            { id: 3, amount: '-30', pointed: 0, split_of: null },
        ],
        searchResults: [], isSearching: false, recurringOnly: false, transactionFilter: 'all',
        selectedTransactionIds: [2],
    };
    Object.assign(context, module.methods);
    for (const name of ['displayedTransactions', 'allDisplayedSelected']) {
        Object.defineProperty(context, name, { get: () => module.computed[name].call(context) });
    }

    assert.equal(context.allDisplayedSelected, false);
    context.toggleSelectAll();
    assert.deepEqual(context.selectedTransactionIds, [1, 2, 3]);
    assert.equal(context.allDisplayedSelected, true);
    context.toggleSelectAll();
    assert.deepEqual(context.selectedTransactionIds, []);

    // Only the transactions shown: the filter applied
    context.transactionFilter = 'unchecked';
    context.toggleSelectAll();
    assert.deepEqual(context.selectedTransactionIds, [2, 3]);
    assert.equal(context.allDisplayedSelected, true);

    // Nothing shown: nothing to select
    context.transactions = [];
    assert.equal(context.allDisplayedSelected, false);
});

test('toggleSelectAll stops at the selection limit', () => {
    const module = createTransactionModule(() => ({}));
    const context = {
        ...module.data(),
        transactions: Array.from({ length: 520 }, (_, i) => ({ id: i + 1, amount: '-1', pointed: 1, split_of: null })),
        searchResults: [], isSearching: false, recurringOnly: false, transactionFilter: 'all',
        selectedTransactionIds: [],
    };
    Object.assign(context, module.methods);
    for (const name of ['displayedTransactions', 'allDisplayedSelected']) {
        Object.defineProperty(context, name, { get: () => module.computed[name].call(context) });
    }
    context.toggleSelectAll();
    assert.equal(context.selectedTransactionIds.length, 500);
    assert.equal(context.allDisplayedSelected, true);
});

test('rules: the optional amount, shown with 2 decimals, sent with the rule and read by the screen readers', async () => {
    assert.equal(ruleAmountText(null), '');
    assert.equal(ruleAmountText(undefined), '');
    assert.equal(ruleAmountText(10), '10.00');
    assert.equal(ruleAmountText('9.5'), '9.50');

    const sent = [];
    const api = {
        createRule: async (...args) => { sent.push(['create', ...args]); return { applied: 1 }; },
        updateRule: async (...args) => { sent.push(['update', ...args]); return { applied: 0 }; },
        fetchRules: async () => [],
        countRule: async () => ({ matching: 1, categorized: 1 }),
    };
    const module = createRulesModule(() => api);
    const context = {
        ...module.data(), ...module.methods, apiErrorText, t: key => key, showToast: () => {}, $nextTick: () => {},
        formatAmount: amount => Number(amount).toFixed(2).replace('.', ','), currencySymbol: '€',
    };
    assert.equal(context.ruleLabel({ keyword: 'PAYPAL', amount: null }), 'PAYPAL');
    assert.equal(context.ruleLabel({ keyword: 'PAYPAL', amount: 10 }), 'PAYPAL · 10,00 €');

    context.openRuleModal({ id: 4, keyword: 'PAYPAL', category: 3, notify: 0, amount: 10 });
    assert.equal(context.newRule.amount, '10.00');
    await context.addRule();
    assert.deepEqual(sent.at(-1), ['update', 4, 'PAYPAL', 3, false, '10.00']);

    context.openRuleModal({ keyword: 'PAYPAL', category: 1 });
    assert.equal(context.newRule.amount, '');
    await context.addRule();
    assert.deepEqual(sent.at(-1), ['create', 'PAYPAL', 1, false, '']);
});

test('budget: the own item of a parent lists only its transactions, without going into it again (#492)', async () => {
    const navigated = [];
    const fetched = [];
    const store = { navigateToChildren: async (item) => { navigated.push(item.id); return true; } };
    const api = { fetchCategoryTransactions: async (month, year, category, own) => { fetched.push([category, own]); return [{ id: 9 }]; } };
    const module = createBudgetModule(() => store, () => api);
    const context = {
        budgetCategory: null,
        budgetTransactions: [],
        t: (key, params) => `${key}:${params.category}`,
        ensureCategories() {},
        showToast() {},
        $nextTick() {},
    };
    for (const [name, method] of Object.entries(module.methods)) {
        context[name] = method.bind(context);
    }

    await context.handleBudgetClick({ id: 1, name: 'Alimentation', own: true }, 11, 2026);
    assert.deepEqual(navigated, []);
    assert.deepEqual(fetched, [[1, true]]);
    assert.deepEqual(context.budgetCategory, { id: 1, name: 'budgetOwnTransactions:Alimentation', own: true });
    assert.equal(context.isBudgetCategorySelected({ id: 1, own: true }), true);
    assert.equal(context.isBudgetCategorySelected({ id: 1 }), false);

    await context.handleBudgetClick({ id: 1, name: 'Alimentation' }, 11, 2026);
    assert.deepEqual(navigated, [1]);
    assert.deepEqual(fetched[1], [1, false]);
    assert.equal(context.budgetItemName({ id: 2, name: 'Supermarché' }), 'Supermarché');
});

test('importAccountOf: the account of the household with the number read in the file, else none (#485)', async () => {
    const { importAccountOf } = await import('../../portal/modules/importModule.js');
    const accounts = [{ id: 3, account_number: 'FR76 3000 1234' }, { id: 5, account_number: 'cash' }];
    assert.equal(importAccountOf({ account: 'FR7630001234' }, accounts), 3);
    assert.equal(importAccountOf({ account: 'cash' }, accounts), 5);
    assert.equal(importAccountOf({ account: 'other' }, accounts), '');
    assert.equal(importAccountOf({ account: null }, accounts), '');
    assert.equal(importAccountOf(null, []), '');
});

test('confirmImport sends the account chosen, none for the one of the file (#485)', async () => {
    const { createImportModule } = await import('../../portal/modules/importModule.js');
    const calls = [];
    const api = { importTransactions: async (...args) => { calls.push(args.slice(2)); return { imported: 1, categorized: 0, to: null }; } };
    const module = createImportModule(() => api);
    const context = {
        importFile: { name: 'releve.csv', content: 'eA==' }, importSelection: { 0: true, 1: false }, importAccountId: 3,
        importing: false, t: key => key, showToast() {}, loadPeriods() {}, loadTransactions() {}, closeImport() {},
        selectedMonth: 11, selectedYear: 2026,
    };
    context.importSelectedCount = 1;
    await module.methods.confirmImport.call(context);
    await module.methods.confirmImport.call({ ...context, importAccountId: '' });
    assert.deepEqual(calls, [[[0], 3], [[0], null]]);
});
