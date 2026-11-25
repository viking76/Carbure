// modules/changesModule.js: reload of the open tab when its data changed on the server
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApiService } from '../../portal/services/apiService.js';
import { afterOwnWrite, createChangesModule, movedKinds, parseChangesHeader, RELOAD_DELAY, TAB_KINDS } from '../../portal/modules/changesModule.js';

const BASE = 'https://carbure.example.com/api';

/** document of the browser: no modal open by default, the page visible */
function fakeDocument({ modal = false } = {}) {
    const listeners = {};
    globalThis.document = {
        hidden: false,
        querySelector: selector => (modal && selector === '.modal-overlay' ? {} : null),
        addEventListener: (type, listener) => { listeners[type] = listener; },
        removeEventListener: type => { delete listeners[type]; }
    };
    return listeners;
}

/** Body of GET /changes */
function changes(versions) {
    return Object.fromEntries(Object.entries(versions).map(([kind, version]) => [kind, { version, updated_at: null }]));
}

/** Answer of the API with the versions of the server */
function answer(versions, body = [], changed = null) {
    const headers = { 'Content-Type': 'application/json', 'X-Carbure-Changes': Object.entries(versions).map(([k, v]) => `${k}=${v}`).join(', ') };
    if (changed) {
        headers['X-Carbure-Changed'] = changed;
    }
    return new Response(JSON.stringify(body), { headers });
}

/** A component with the changes mixin and its API service, recording the reloads */
function component(activeTab = 'budget') {
    let api = null;
    const mixin = createChangesModule(() => api);
    const vm = { isAuthenticated: true, activeTab, reloads: [], periods: 0 };
    vm.refreshCurrentTab = () => vm.reloads.push(vm.activeTab);
    vm.loadPeriods = () => vm.periods++;
    for (const [name, method] of Object.entries(mixin.methods)) {
        vm[name] = method.bind(vm);
    }
    api = createApiService(BASE, 't', null, null, (current, written) => vm.serverChanges(current, written));
    vm.api = api;
    return vm;
}

const afterReload = () => new Promise(resolve => setTimeout(resolve, RELOAD_DELAY + 20));

test('the header gives the versions of the server', () => {
    assert.deepEqual(parseChangesHeader('budgets=1835, transactions=1836'), { budgets: 1835, transactions: 1836 });
    assert.deepEqual(parseChangesHeader(null), {});
    assert.deepEqual(parseChangesHeader('budgets=x, =3, accounts'), {});
});

test('only the kinds whose version moved are reloaded', () => {
    assert.deepEqual(movedKinds({ budgets: 3, transactions: 8 }, { budgets: 4, transactions: 8 }), ['budgets']);
    assert.deepEqual(movedKinds({ budgets: 3 }, { budgets: 3, settings: 1 }), ['settings']);
});

test('a write of the portal is up to date, unless someone else wrote meanwhile', () => {
    assert.deepEqual(afterOwnWrite({ budgets: 3, transactions: 8 }, { budgets: 4, transactions: 10 }, ['budgets', 'transactions']),
        { budgets: 4, transactions: 8 });
    // Not written by the portal: to reload
    assert.deepEqual(afterOwnWrite({ budgets: 3 }, { budgets: 4 }, []), { budgets: 3 });
});

test('the insights and the trends follow the transactions and the budgets', () => {
    for (const tab of ['insights', 'trends']) {
        assert.ok(TAB_KINDS[tab].includes('transactions') && TAB_KINDS[tab].includes('budgets'), tab);
    }
});

test('the trends follow the settings: their custom charts are shared by the household', () => {
    assert.ok(TAB_KINDS.trends.includes('settings'));
});

test('each answer tells whether the open tab is still up to date, without polling', async () => {
    fakeDocument();
    let versions = { transactions: 1, budgets: 1, settings: 1 };
    const calls = mockFetch(() => answer(versions));
    const vm = component();
    vm.startChangeWatch();

    // The loading of the tab gives the versions of its data
    await vm.api.fetchCategories();
    assert.deepEqual(vm.reloads, []);

    // The settings changed: the budget tab does not show them
    versions = { ...versions, settings: 2 };
    await vm.api.fetchCategories();
    await afterReload();
    assert.deepEqual(vm.reloads, []);

    // A synchronization brought transactions: the budgets and the periods are reloaded, once
    versions = { ...versions, transactions: 2 };
    await vm.api.fetchCategories();
    await vm.api.fetchCategories();
    await afterReload();
    assert.deepEqual(vm.reloads, ['budget']);
    assert.equal(vm.periods, 1);
    assert.ok(calls.every(call => !call.url.endsWith('/changes')));
    vm.stopChangeWatch();
});

test('back to the page, the versions are asked once', async () => {
    const listeners = fakeDocument();
    let versions = { budgets: 1 };
    const calls = mockFetch(() => answer(versions, changes(versions)));
    const vm = component();
    vm.startChangeWatch();
    await vm.api.fetchCategories();

    versions = { budgets: 2 };
    await listeners.visibilitychange();
    await afterReload();
    assert.equal(calls.at(-1).url, `${BASE}/changes`);
    assert.deepEqual(vm.reloads, ['budget']);
    vm.stopChangeWatch();
    assert.equal(listeners.visibilitychange, undefined);
});

test('the own writes of the portal reload nothing more', async () => {
    fakeDocument();
    let versions = { budgets: 1 };
    mockFetch((url, options) => (options.method === 'POST' ? answer(versions, true, 'budgets') : answer(versions)));
    const vm = component();
    vm.startChangeWatch();
    await vm.api.fetchCategories();

    versions = { budgets: 2 };
    await vm.api.updateBudget(3, 10, 2026, 120);
    await vm.api.fetchCategories();
    await afterReload();
    assert.deepEqual(vm.reloads, []);
    vm.stopChangeWatch();
});

test('not while a modal is open, nor against a server without markers', async () => {
    fakeDocument({ modal: true });
    let versions = { budgets: 1 };
    mockFetch(() => answer(versions));
    const vm = component();
    vm.startChangeWatch();
    await vm.api.fetchCategories();
    versions = { budgets: 2 };
    await vm.api.fetchCategories();
    await afterReload();
    assert.deepEqual(vm.reloads, []);

    // Closed: the next answer reloads
    fakeDocument();
    versions = { budgets: 3 };
    await vm.api.fetchCategories();
    await afterReload();
    assert.deepEqual(vm.reloads, ['budget']);

    const listeners = fakeDocument();
    vm.startChangeWatch();
    mockFetch(() => json({ error: 'Route not found: GET /changes' }, 404));
    await listeners.visibilitychange();
    await afterReload();
    assert.equal(listeners.visibilitychange, undefined);
});
