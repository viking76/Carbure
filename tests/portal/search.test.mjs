// Advanced search of the transactions (portal/modules/searchModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createSearchModule, filterCount, searchParams, EMPTY_FILTERS } from '../../portal/modules/searchModule.js';
import { createTransactionModule } from '../../portal/modules/transactionModule.js';
import { createApiService } from '../../portal/services/apiService.js';

const CATEGORIES = [
    { id: 0, name: 'Aucune', parent_category: 0 },
    { id: 2, name: 'Supermarché', parent_category: 1 },
    { id: 4, name: 'Logement', parent_category: 0 },
    { id: 1, name: 'Alimentation', parent_category: 0 },
    { id: 3, name: 'Restaurant', parent_category: 1 },
];

const ACCOUNTS = [
    { id: 1, bankId: '0001@bnp', account_number: '0001', bank_name: 'bnp', label: 'Compte joint' },
    { id: 2, bankId: '0002@bnp', account_number: '0002', bank_name: 'bnp', label: null },
];

/** Search and transaction modules bound to a fake component */
function searchContext(api = {}) {
    const search = createSearchModule(() => api);
    const transactions = createTransactionModule(() => api);
    const context = {
        ...transactions.data(), ...search.data(),
        ruleCategories: CATEGORIES, intlLocale: 'fr', currencySymbol: '€', toasts: [], searches: [],
        t: (key, params) => params ? `${key}:${Object.values(params).join('|')}` : key,
        formatAmount: value => value.toFixed(2),
        formatDate: value => value.split('-').reverse().join('/'),
        accountName: account => `${account.bank_name.toUpperCase()} ···${account.account_number.slice(-4)}`,
        bankIdName: bankId => `id:${bankId}`,
        ensureCategories() {},
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
    };
    Object.assign(context, transactions.methods, search.methods);
    for (const [name, compute] of Object.entries({ ...transactions.computed, ...search.computed })) {
        Object.defineProperty(context, name, { get: () => compute.call(context) });
    }
    return context;
}

test('filterCount: each criterion, each category and each account', () => {
    assert.equal(filterCount(EMPTY_FILTERS), 0);
    assert.equal(filterCount({ ...EMPTY_FILTERS, min: ' ' }), 0);
    assert.equal(filterCount({ ...EMPTY_FILTERS, min: '10', kind: 'debit', categories: [1, 2], accounts: ['0001@bnp'] }), 5);
    assert.equal(filterCount(undefined), 0);
});

test('searchParams: the text from 2 characters, the criteria set, a comma as decimal separator', () => {
    assert.deepEqual(searchParams('a', EMPTY_FILTERS), {});
    assert.deepEqual(searchParams(' loyer ', { ...EMPTY_FILTERS, min: '10,5', to: '2026-10-31', categories: [1, 4], accounts: ['0001@bnp', '0002@bnp'] }),
        { query: 'loyer', min: '10.5', to: '2026-10-31', categories: '1,4', accounts: '0001@bnp,0002@bnp' });
    // Without criteria (an older caller): the text only
    assert.deepEqual(searchParams('edf'), { query: 'edf' });
    // The transactions similar to one: the text in the label only (nothing without a text)
    assert.deepEqual(searchParams('edf', EMPTY_FILTERS, true), { query: 'edf', in: 'label' });
    assert.deepEqual(searchParams('e', EMPTY_FILTERS, true), {});
});

test('the categories of the modal: chosen in the list, shown in their order, removed one by one', () => {
    const context = searchContext();
    context.ruleCategories = CATEGORIES;
    context.searchDraft = { query: '', ...EMPTY_FILTERS, categories: [], accounts: [] };
    assert.equal(context.searchCategoryChoices.length, 5);

    context.addSearchCategory(3);
    context.addSearchCategory('1');
    context.addSearchCategory(3);
    assert.deepEqual(context.searchDraft.categories, [3, 1]);
    assert.deepEqual(context.searchDraftCategories.map(c => c.name), ['Restaurant', 'Alimentation']);
    // The chosen ones are no more offered
    assert.deepEqual(context.searchCategoryChoices.map(c => c.id), [0, 2, 4]);

    context.removeSearchCategory('3');
    assert.deepEqual(context.searchDraft.categories, [1]);
    // The default category (uncategorized) can be searched too
    context.addSearchCategory(0);
    assert.deepEqual(context.searchDraftCategories.map(c => c.name), ['Alimentation', 'Aucune']);
});

test('isSearching: from 2 characters, or with a criterion and no text', () => {
    const context = searchContext();
    assert.equal(context.isSearching, false);
    context.searchFilters = { ...EMPTY_FILTERS, kind: 'credit' };
    assert.equal(context.isSearching, true);
});

test('the modal starts from the search shown, and searches with its text and criteria', async () => {
    const calls = [];
    const api = {
        async fetchAccounts() { return ACCOUNTS; },
        async searchTransactions(params) { calls.push(params); return [{ id: 1, label: 'LOYER', amount: '-950' }]; },
    };
    const context = searchContext(api);
    context.searchQuery = 'loy';
    await context.openAdvancedSearch();
    assert.equal(context.searchDraft.query, 'loy');
    assert.deepEqual(context.searchAccounts, ACCOUNTS);

    Object.assign(context.searchDraft, { kind: 'debit', min: '900', categories: [4], accounts: ['0001@bnp'] });
    // Cancelled: nothing changes
    context.closeAdvancedSearch();
    assert.equal(context.searchDraft, null);
    assert.equal(context.searchFilterCount, 0);

    await context.openAdvancedSearch();
    Object.assign(context.searchDraft, { kind: 'debit', min: '900', categories: [4], accounts: ['0001@bnp'] });
    await context.applyAdvancedSearch();
    assert.equal(context.searchDraft, null);
    assert.deepEqual(calls, [{ query: 'loy', min: '900', kind: 'debit', categories: '4', accounts: '0001@bnp' }]);
    assert.equal(context.searchResults.length, 1);
    assert.deepEqual(context.searchFilterChips.map(chip => chip.label),
        ['advancedSearchExpenses', 'advancedSearchAmountMin:900.00 €', 'Logement', 'Compte joint']);
});

test('removing the chips searches again, the last one goes back to the month', async () => {
    const calls = [];
    const context = searchContext({ async searchTransactions(params) { calls.push(params); return []; } });
    context.searchFilters = { ...EMPTY_FILTERS, min: '10', max: '20', from: '2026-01-01', categories: [1, 4], accounts: ['9@x'] };
    const chips = context.searchFilterChips;
    assert.deepEqual(chips.map(chip => chip.label),
        ['advancedSearchAmountBetween:10.00 €|20.00 €', 'advancedSearchPeriodFrom:01/01/2026', 'Alimentation', 'Logement', 'id:9@x']);

    await context.removeSearchFilter(chips[0]);
    assert.deepEqual(calls.at(-1), { from: '2026-01-01', categories: '1,4', accounts: '9@x' });
    await context.removeSearchFilter(chips[2]);
    assert.deepEqual(calls.at(-1), { from: '2026-01-01', categories: '4', accounts: '9@x' });
    for (const chip of context.searchFilterChips) {
        await context.removeSearchFilter(chip);
    }
    assert.equal(context.isSearching, false);
    assert.deepEqual(context.searchResults, []);
});

test('reset then apply without criteria clears the search; clearSearch empties the criteria', async () => {
    const context = searchContext({ async fetchAccounts() { return []; }, async searchTransactions() { return []; } });
    context.searchQuery = 'edf';
    context.searchFilters = { ...EMPTY_FILTERS, kind: 'debit' };
    await context.openAdvancedSearch();
    context.resetAdvancedSearch();
    await context.applyAdvancedSearch();
    assert.equal(context.searchQuery, '');
    assert.equal(context.searchFilterCount, 0);

    context.searchFilters = { ...EMPTY_FILTERS, kind: 'credit' };
    context.clearSearch();
    assert.equal(context.searchFilterCount, 0);
});

test('a failed loading of the accounts is shown, the modal stays usable', async () => {
    const context = searchContext({ async fetchAccounts() { throw new Error('Failed to fetch the accounts'); } });
    await context.openAdvancedSearch();
    assert.deepEqual(context.toasts, ['Failed to fetch the accounts']);
    assert.deepEqual(context.searchAccounts, []);
    assert.notEqual(context.searchDraft, null);
});

test('apiService.searchTransactions: the parameters in the query string', async () => {
    const fetched = mockFetch(() => json([]));
    const api = createApiService('/api');
    await api.searchTransactions({ query: 'loyer & co', categories: '1,4', accounts: '0001@bnp' });
    assert.match(fetched.at(-1).url, /\/api\/transaction\/search\?query=loyer\+%26\+co&categories=1%2C4&accounts=0001%40bnp$/);
});
