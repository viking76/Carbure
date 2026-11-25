// Insights shown or hidden (portal/modules/insightsModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createInsightsModule, moveInsight } from '../../portal/modules/insightsModule.js';
import { createApiService } from '../../portal/services/apiService.js';

/** An insights module bound to a fake component */
function insightsContext(api) {
    const module = createInsightsModule(() => api);
    const context = {
        ...module.data(),
        selectedMonth: 10, selectedYear: 2026, isDebugMode: false,
        toasts: [],
        t: (key, params) => params ? `${key} ${JSON.stringify(params)}` : key,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
        getInsightName: name => name,
        $nextTick() {},
    };
    Object.assign(context, module.methods);
    return context;
}

/** A fake API: the shown insights and the hidden ones, from a list with enabled */
function fakeApi(all) {
    return {
        fetchInsights: async () => all.filter(i => i.enabled).map(({ enabled, ...i }) => ({ ...i, amount: 10 })),
        fetchDisabledInsights: async () => all.filter(i => !i.enabled).map(({ enabled, ...i }) => i),
        setInsightEnabled: async (id, enabled) => { all.find(i => i.id === id).enabled = enabled; return { id, enabled }; },
        setInsightOrder: async ids => {
            all.sort((a, b) => ids.indexOf(a.id) - ids.indexOf(b.id));
            return ids;
        },
    };
}

test('loads the shown insights and the hidden ones', async () => {
    const context = insightsContext(fakeApi([{ id: 1, name: 'Dépenses', enabled: true }, { id: 2, name: 'Retraits', enabled: false }]));
    await context.loadInsights(10, 2026);
    assert.deepEqual(context.insights.map(i => i.id), [1]);
    assert.deepEqual(context.disabledInsights.map(i => i.id), [2]);
});

test('hides an insight, then shows it again from the + list', async () => {
    const context = insightsContext(fakeApi([{ id: 1, name: 'Dépenses', enabled: true }, { id: 2, name: 'Retraits', enabled: false }]));
    await context.loadInsights(10, 2026);

    await context.setInsightEnabled(context.insights[0], false);
    assert.deepEqual(context.insights, []);
    assert.deepEqual(context.disabledInsights.map(i => i.id), [1, 2]);
    assert.deepEqual(context.toasts, ['insightHidden {"name":"Dépenses"}']);

    context.openInsightCatalog();
    await context.setInsightEnabled(context.disabledInsights[1], true);
    // Another hidden one: the list stays open
    assert.equal(context.showInsightCatalog, true);
    await context.setInsightEnabled(context.disabledInsights[0], true);
    // The last one shown: the list closes
    assert.equal(context.showInsightCatalog, false);
    assert.deepEqual(context.insights.map(i => i.id), [1, 2]);
    assert.equal(context.togglingInsight, null);
});

test('a refused change is shown, a failed list of the hidden ones gives none', async () => {
    const context = insightsContext({
        setInsightEnabled: async () => { throw new Error('Insight not found'); },
        fetchDisabledInsights: async () => { throw new Error('offline'); },
    });
    await context.setInsightEnabled({ id: 9, name: 'X' }, true);
    assert.deepEqual(context.toasts, ['Insight not found']);
    assert.deepEqual(await context.fetchDisabledInsights(), []);
});

test('the administrators create an insight from the + list', async () => {
    const context = insightsContext({});
    context.showInsightCatalog = true;
    await context.createInsightFromCatalog();
    assert.equal(context.showInsightCatalog, false);
    assert.equal(context.insightForm.id, null);
    context.closeInsightCatalog();
});

test('the API of the hidden insights', async () => {
    const base = 'https://carbure.example.com/api';
    const calls = mockFetch(() => json([{ id: 2, name: 'Retraits', color: 'gray', icon: null }]));
    const api = createApiService(base, 't');

    assert.deepEqual(await api.fetchDisabledInsights(), [{ id: 2, name: 'Retraits', color: 'gray', icon: null }]);
    await api.setInsightEnabled(2, true);
    assert.deepEqual(calls.map(c => [c.url, c.options.method || 'GET']), [[`${base}/insight/disabled`, 'GET'], [`${base}/insight/enabled`, 'PUT']]);
    assert.deepEqual(JSON.parse(calls[1].options.body), { id: 2, enabled: true });
});

test('the API of the insight alerts', async () => {
    const base = 'https://carbure.example.com/api';
    const calls = mockFetch(() => json([{ insight: 1, direction: 'above', threshold: 2000 }]));
    const api = createApiService(base, 't');

    assert.deepEqual(await api.fetchInsightAlerts(), [{ insight: 1, direction: 'above', threshold: 2000 }]);
    await api.setInsightAlert(1, 'below', 150.5);
    await api.deleteInsightAlert(1);
    assert.deepEqual(calls.map(c => [c.url, c.options.method || 'GET']),
        [[`${base}/insight/alert`, 'GET'], [`${base}/insight/alert`, 'PUT'], [`${base}/insight/alert?insight=1`, 'DELETE']]);
    assert.deepEqual(JSON.parse(calls[1].options.body), { insight: 1, direction: 'below', threshold: 150.5 });
});

test('the alerts of the user on the insights', async () => {
    const { insightAlertThresholdError, insightAlertsById } = await import('../../portal/modules/insightsModule.js');
    assert.equal(insightAlertThresholdError('2000'), '');
    assert.equal(insightAlertThresholdError('12,5'), '');
    assert.equal(insightAlertThresholdError(0), '');
    for (const value of ['', ' ', '-1', 'abc', '1e12', null]) {
        assert.equal(insightAlertThresholdError(value), 'insightAlertThresholdInvalid', String(value));
    }
    assert.deepEqual(insightAlertsById([{ insight: 3, direction: 'below', threshold: '200.5' }]), { 3: { direction: 'below', threshold: 200.5 } });
    assert.deepEqual(insightAlertsById(null), {});
});

test('sets, changes and removes the alert of an insight', async () => {
    const calls = [];
    const context = insightsContext({
        ...fakeApi([{ id: 1, name: 'Dépenses', enabled: true }]),
        fetchInsightAlerts: async () => [{ insight: 1, direction: 'above', threshold: 2000 }],
        setInsightAlert: async (insight, direction, threshold) => { calls.push(['set', insight, direction, threshold]); return { insight, direction, threshold }; },
        deleteInsightAlert: async insight => { calls.push(['delete', insight]); },
    });
    Object.assign(context, { formatAmount: amount => amount.toFixed(2), currencySymbol: '€' });
    await context.loadInsights(10, 2026);
    assert.deepEqual(context.insightAlerts, { 1: { direction: 'above', threshold: 2000 } });
    assert.equal(context.insightAlertText(context.insights[0]), 'insightAlert_above {"amount":"2000.00 €"}');
    assert.equal(context.insightAlertText({ id: 9 }), '');

    context.openInsightAlert(context.insights[0]);
    assert.deepEqual(context.insightAlertForm, { insight: 1, name: 'Dépenses', direction: 'above', threshold: '2000', exists: true });

    // A wrong amount is refused before the call
    context.insightAlertForm.threshold = '-5';
    await context.saveInsightAlert();
    assert.equal(context.insightAlertErrorKey, 'insightAlertThresholdInvalid');
    assert.deepEqual(calls, []);

    context.insightAlertForm.direction = 'below';
    context.insightAlertForm.threshold = '150,5';
    await context.saveInsightAlert();
    assert.deepEqual(calls, [['set', 1, 'below', 150.5]]);
    assert.deepEqual(context.insightAlerts, { 1: { direction: 'below', threshold: 150.5 } });
    assert.equal(context.insightAlertForm, null);

    context.openInsightAlert(context.insights[0]);
    await context.removeInsightAlert();
    assert.deepEqual(calls[1], ['delete', 1]);
    assert.deepEqual(context.insightAlerts, {});
    assert.deepEqual(context.toasts, ['insightAlertSaved {"name":"Dépenses"}', 'insightAlertRemoved {"name":"Dépenses"}']);

    // A new alert starts above, empty
    context.openInsightAlert(context.insights[0]);
    assert.deepEqual(context.insightAlertForm, { insight: 1, name: 'Dépenses', direction: 'above', threshold: '', exists: false });
    context.closeInsightAlert();
});

test('a refused alert is shown, a failed list of the alerts gives none', async () => {
    const context = insightsContext({
        fetchInsightAlerts: async () => { throw new Error('offline'); },
        setInsightAlert: async () => { throw new Error('Only an enabled insight can alert'); },
    });
    assert.deepEqual(await context.fetchInsightAlerts(), {});
    context.openInsightAlert({ id: 1, name: 'X' });
    context.insightAlertForm.threshold = '10';
    await context.saveInsightAlert();
    assert.deepEqual(context.toasts, ['Only an enabled insight can alert']);
    assert.notEqual(context.insightAlertForm, null);
    assert.equal(context.savingInsightAlert, false);
});

test('moves an item of a list, clamped to its bounds (#467)', () => {
    const list = ['a', 'b', 'c', 'd'];
    assert.deepEqual(moveInsight(list, 0, 2), ['b', 'c', 'a', 'd']);
    assert.deepEqual(moveInsight(list, 3, 0), ['d', 'a', 'b', 'c']);
    assert.deepEqual(moveInsight(list, 1, 99), ['a', 'c', 'd', 'b']);
    assert.deepEqual(moveInsight(list, 1, -5), ['b', 'a', 'c', 'd']);
    // Nothing moves: the same list
    assert.equal(moveInsight(list, 2, 2), list);
    assert.equal(moveInsight(list, 7, 0), list);
    assert.deepEqual(list, ['a', 'b', 'c', 'd']);
});

test('moves a card with the keyboard from its handle, saves and announces the order (#467)', async () => {
    const all = [{ id: 1, name: 'Dépenses', enabled: true }, { id: 2, name: 'Retraits', enabled: true }, { id: 3, name: 'Loyer', enabled: true }];
    const context = insightsContext(fakeApi(all));
    await context.loadInsights(10, 2026);
    const key = k => ({ key: k, prevented: false, preventDefault() { this.prevented = true; } });

    let event = key('ArrowRight');
    await context.moveInsightByKey(event, context.insights[0]);
    assert.equal(event.prevented, true);
    assert.deepEqual(context.insights.map(i => i.id), [2, 1, 3]);
    assert.deepEqual(all.map(i => i.id), [2, 1, 3]);
    assert.equal(context.insightOrderAnnouncement, 'insightMoved {"name":"Dépenses","position":2,"count":3}');

    await context.moveInsightByKey(key('Home'), context.insights[2]);
    assert.deepEqual(context.insights.map(i => i.id), [3, 2, 1]);
    await context.moveInsightByKey(key('End'), context.insights[0]);
    assert.deepEqual(context.insights.map(i => i.id), [2, 1, 3]);

    // Already first, or another key: nothing moves nor is saved
    const before = context.insights;
    await context.moveInsightByKey(key('ArrowUp'), context.insights[0]);
    event = key('Tab');
    await context.moveInsightByKey(event, context.insights[1]);
    assert.equal(event.prevented, false);
    assert.equal(context.insights, before);
});

test('a refused order is shown and the saved order comes back (#467)', async () => {
    const all = [{ id: 1, name: 'Dépenses', enabled: true }, { id: 2, name: 'Retraits', enabled: true }];
    const api = { ...fakeApi(all), setInsightOrder: async () => { throw new Error('refused'); } };
    const context = insightsContext(api);
    await context.loadInsights(10, 2026);

    await context.moveInsightByKey({ key: 'ArrowDown', preventDefault() {} }, context.insights[0]);
    assert.deepEqual(context.toasts, ['refused']);
    assert.deepEqual(context.insights.map(i => i.id), [1, 2]);
});

test('a card dropped where it was is not saved (#467)', async () => {
    let saved = 0;
    const all = [{ id: 1, name: 'Dépenses', enabled: true }, { id: 2, name: 'Retraits', enabled: true }];
    const context = insightsContext({ ...fakeApi(all), setInsightOrder: async ids => { saved++; return ids; } });
    await context.loadInsights(10, 2026);

    context.draggingInsight = 1;
    context.insightOrderBefore = [1, 2];
    await context.endInsightDrag();
    assert.equal(saved, 0);
    assert.equal(context.draggingInsight, null);

    // Moved while dragged: saved once dropped
    context.draggingInsight = 1;
    context.insightOrderBefore = [1, 2];
    context.insights = moveInsight(context.insights, 0, 1);
    await context.endInsightDrag();
    assert.equal(saved, 1);
});

test('the API of the order of the insights (#467)', async () => {
    const base = 'https://carbure.example.com/api';
    const calls = mockFetch(() => json([2, 1]));
    const api = createApiService(base, 't');
    assert.deepEqual(await api.setInsightOrder([2, 1]), [2, 1]);
    assert.equal(calls[0].url, `${base}/insight/order`);
    assert.equal(calls[0].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[0].options.body), { ids: [2, 1] });
});
