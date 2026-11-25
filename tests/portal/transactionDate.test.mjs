// Date a transaction is counted on, changed from its detail modal (portal/modules/transactionModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createTransactionModule, isIsoDate } from '../../portal/modules/transactionModule.js';
import { createApiService } from '../../portal/services/apiService.js';

const SYNCED = { id: 1, label: 'CB CARREFOUR', category: 2, amount: '-42.40', pointed: 1, split_of: null,
    date: '2026-10-02', rdate: '2026-10-02', bank_name: 'bnp' };
const PART = { id: 9, label: 'CB CARREFOUR', category: 3, amount: '-20.00', pointed: 1, split_of: 1,
    date: '2026-10-02', rdate: '2026-10-02', bank_name: 'bnp' };
const OTHER = { id: 2, label: 'VIR SALAIRE', category: 5, amount: '3200.00', pointed: 1, split_of: null,
    date: '2026-10-01', rdate: '2026-09-30', bank_name: 'bnp' };

/** A transaction module bound to a fake component, the detail modal open on a transaction */
function detailContext(api, opened = SYNCED) {
    const module = createTransactionModule(() => api);
    const context = Object.assign({ ...module.data() }, module.methods, {
        transactions: [SYNCED, PART, OTHER].map(t => ({ ...t })),
        selectedMonth: 10, selectedYear: 2026, isAdmin: false, ruleCategories: [{ id: 2 }],
        toasts: [], reloads: [],
        t: key => key,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
        loadPeriods() { this.reloads.push('periods'); },
        loadTransactions(month, year) { this.reloads.push(`transactions ${month}/${year}`); },
    });
    globalThis.document = { querySelector: () => null };
    context.$nextTick = callback => callback();
    context.openTransactionModal(context.transactions.find(t => t.id === opened.id));
    return context;
}

test('isIsoDate accepts the real days in the YYYY-MM-DD format only', () => {
    assert.equal(isIsoDate('2026-09-30'), true);
    assert.equal(isIsoDate('2024-02-29'), true);
    assert.equal(isIsoDate('2026-02-30'), false);
    assert.equal(isIsoDate('2026-9-30'), false);
    assert.equal(isIsoDate('30/09/2026'), false);
    assert.equal(isIsoDate(''), false);
    assert.equal(isIsoDate(null), false);
});

test('setTransactionDate sends PUT /transaction/date', async () => {
    const calls = mockFetch(() => json({ id: 1, date: '2026-09-30' }));
    const api = createApiService('https://carbure.example.com/api', 'jwt-token');
    assert.deepEqual(await api.setTransactionDate(1, '2026-09-30'), { id: 1, date: '2026-09-30' });
    assert.match(calls[0].url, /\/transaction\/date$/);
    assert.equal(calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[0].options.body), { id: 1, date: '2026-09-30' });
});

test('the detail modal starts with the date the transaction is counted on', () => {
    const context = detailContext({});
    assert.equal(context.transactionDetailDate, '2026-10-02');
});

test('a new date is saved, moves the split transaction and reloads the month and the periods', async () => {
    const sent = [];
    const context = detailContext({ setTransactionDate: async (id, date) => { sent.push([id, date]); } }, PART);
    context.transactionDetailDate = '2026-09-30';
    await context.saveTransactionDetail();

    assert.deepEqual(sent, [[9, '2026-09-30']]);
    // The part, its transaction: the new date; the other one is unchanged
    assert.deepEqual(context.transactions.map(t => t.date), ['2026-09-30', '2026-09-30', '2026-10-01']);
    assert.equal(context.transactionDetail, null);
    assert.deepEqual(context.toasts, ['transactionSaved']);
    assert.deepEqual(context.reloads, ['periods', 'transactions 10/2026']);
});

test('an unchanged date calls nothing and reloads nothing', async () => {
    const context = detailContext({ setTransactionDate: async () => assert.fail('date sent') });
    await context.saveTransactionDetail();
    assert.deepEqual(context.reloads, []);
    assert.deepEqual(context.toasts, ['transactionSaved']);
});

test('an invalid date is not sent and keeps the modal open', async () => {
    const context = detailContext({ setTransactionDate: async () => assert.fail('date sent') });
    context.transactionDetailDate = '';
    await context.saveTransactionDetail();
    assert.deepEqual(context.toasts, ['apiErrors.transaction_date_invalid']);
    assert.notEqual(context.transactionDetail, null);
});

test('an error of the API is shown and keeps the date of the list', async () => {
    const context = detailContext({ setTransactionDate: async () => { throw new Error('Invalid date (YYYY-MM-DD)'); } });
    context.transactionDetailDate = '2026-09-30';
    await context.saveTransactionDetail();
    assert.deepEqual(context.toasts, ['Invalid date (YYYY-MM-DD)']);
    assert.equal(context.transactions[0].date, '2026-10-02');
    assert.deepEqual(context.reloads, []);
    assert.equal(context.savingTransaction, false);
});
