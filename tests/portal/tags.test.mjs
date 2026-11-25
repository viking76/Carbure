// Tags of a project limited in time (portal/modules/tagsModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    createTagsModule, categoryShares, cumulativeChart, dateLabels, nearestPoint, niceTicks, tagChanges, tagFigures, tagName, tagProgress, tagStatus, MAX_TAG_SELECTION
} from '../../portal/modules/tagsModule.js';
import { createApiService } from '../../portal/services/apiService.js';
import { matchingTags, tagToCreate } from '../../portal/components/TagInput.js';

const TAGS = [
    { id: 1, name: 'voyage', color: 'green', start_date: '2026-07-01', end_date: null, active: true, count: 2, total: -120 },
    { id: 2, name: 'ete-2020', color: 'orange', start_date: '2020-06-01', end_date: '2020-08-31', active: false, count: 0, total: 0 },
    { id: 3, name: 'anniversaire', color: 'pink', start_date: '2026-10-01', end_date: '2026-12-31', active: true, count: 0, total: 0 },
];

/** A tags module bound to a fake component */
function tagsContext(api = {}) {
    const module = createTagsModule(() => api);
    const context = {
        ...module.data(),
        tags: TAGS.map(t => ({ ...t })),
        transactions: [], searchResults: [], isSearching: false, searchQuery: '',
        ruleCategories: [{ id: 2, name: 'Supermarché', color: 'teal' }],
        toasts: [], tabs: [], searches: 0,
        t: (key, params) => params ? `${key} ${JSON.stringify(params)}` : key,
        showToast(text) { this.toasts.push(text); },
        apiErrorText: error => error.message,
        setActiveTab(tab) { this.tabs.push(tab); },
        runSearch() { this.searches++; },
    };
    Object.assign(context, module.methods);
    for (const [name, compute] of Object.entries(module.computed)) {
        Object.defineProperty(context, name, { get: () => compute.call(context) });
    }
    return context;
}

test('tagName: without its #, in lower case', () => {
    assert.equal(tagName('  #Voyage-Italie '), 'voyage-italie');
    assert.equal(tagName('##Été'), 'été');
    assert.equal(tagName(null), '');
});

test('tagStatus: active until its end date', () => {
    assert.equal(tagStatus({ end_date: null }, '2026-10-27'), 'active');
    assert.equal(tagStatus({ end_date: '2026-10-27' }, '2026-10-27'), 'active');
    assert.equal(tagStatus({ end_date: '2026-10-26' }, '2026-10-27'), 'archived');
});

test('tagProgress: the share of the period gone, none without an end or once archived', () => {
    const tag = { start_date: '2026-07-01', end_date: '2026-07-10', active: true };
    assert.equal(tagProgress(tag, '2026-07-03'), 30);
    assert.equal(tagProgress(tag, '2026-06-20'), 0);
    assert.equal(tagProgress(tag, '2026-07-10'), 100);
    assert.equal(tagProgress({ ...tag, end_date: null }, '2026-07-03'), null);
    assert.equal(tagProgress({ ...tag, active: false }, '2026-07-12'), null);
});

test('tagChanges: the tags to add and to remove', () => {
    assert.deepEqual(tagChanges([1, 2], [2, 3]), { add: [3], remove: [1] });
    assert.deepEqual(tagChanges(undefined, ['4']), { add: [4], remove: [] });
    assert.deepEqual(tagChanges([1], [1]), { add: [], remove: [] });
});

test('niceTicks: readable steps, zero always a tick', () => {
    assert.deepEqual(niceTicks(-100, 100), { min: -100, max: 100, step: 50, values: [-100, -50, 0, 50, 100] });
    assert.deepEqual(niceTicks(-134.2, -20).values, [-150, -100, -50, 0]);
    assert.deepEqual(niceTicks(12, 1830).values, [0, 500, 1000, 1500, 2000]);
    // Nothing moved: a scale of one unit
    assert.deepEqual(niceTicks(0, 0).values, [0, 0.25, 0.5, 0.75, 1]);
});

/** Room of the axes of the tests: a plot of 400 x 100 from x = 56 */
const PAD = { top: 0, right: 0, bottom: 40, left: 56 };

test('cumulativeChart: steps from zero, the days from left to right, zero in the scale', () => {
    const chart = cumulativeChart([
        { date: '2026-07-01', total: -50, cumulative: -50 },
        { date: '2026-07-03', total: -50, cumulative: -100 },
        { date: '2026-07-05', total: 200, cumulative: 100 },
    ], 456, 140, PAD);
    assert.deepEqual(chart.plot, { left: 56, right: 456, top: 0, bottom: 100 });
    assert.deepEqual(chart.points.map(p => [p.x, p.y, p.total]), [[56, 75, -50], [256, 100, -50], [456, 0, 200]]);
    assert.equal(chart.line, 'M56,50 H56 V75 H256 V100 H456 V0');
    assert.equal(chart.area, 'M56,50 H56 V75 H256 V100 H456 V0 V50 Z');
    assert.equal(chart.zero, 50);
    // The gradient runs from the value farthest from zero
    assert.equal(chart.extreme, 100);
    assert.deepEqual(chart.ticks.map(t => [t.value, t.y]), [[-100, 100], [-50, 75], [0, 50], [50, 25], [100, 0]]);
    assert.deepEqual(chart.labels.map(l => [l.date, l.anchor]), [['2026-07-01', 'start'], ['2026-07-03', 'middle'], ['2026-07-05', 'end']]);
});

test('cumulativeChart: one day in the middle, held to the right; no day, nothing drawn', () => {
    const one = cumulativeChart([{ date: '2026-07-01', total: -10, cumulative: -10 }], 456, 140, PAD);
    assert.equal(one.points[0].x, 256);
    assert.equal(one.line, 'M56,0 H256 V100 H456');
    assert.deepEqual(one.labels, [{ x: 256, date: '2026-07-01', anchor: 'middle' }]);
    const none = cumulativeChart([], 456, 140, PAD);
    assert.deepEqual([none.points, none.line, none.area, none.ticks, none.labels], [[], '', '', [], []]);
    assert.equal(none.zero, 100);
});

test('dateLabels: one label every gap at most, the first and the last kept', () => {
    const points = [0, 30, 100, 150, 260, 300].map((x, i) => ({ x, date: `d${i}` }));
    assert.deepEqual(dateLabels(points, 96).map(l => [l.date, l.anchor]), [['d0', 'start'], ['d2', 'middle'], ['d5', 'end']]);
    assert.deepEqual(dateLabels([], 96), []);
});

test('nearestPoint: the day nearest to the pointer', () => {
    const points = [{ x: 0 }, { x: 10 }, { x: 30 }];
    assert.equal(nearestPoint(points, 18), 1);
    assert.equal(nearestPoint(points, 25), 2);
    assert.equal(nearestPoint(points, -40), 0);
    assert.equal(nearestPoint([], 10), null);
});

test('tagFigures: expenses and refunds apart, the largest expense, the cost per day', () => {
    const transactions = [{ amount: -100.1, label: 'HOTEL' }, { amount: '-54.3', label: 'TRAIN' }, { amount: 20.2, label: 'REFUND' }];
    const ended = tagFigures({ tag: { start_date: '2026-07-01', end_date: '2026-07-10' }, total: -134.2, transactions }, '2026-10-31');
    assert.deepEqual({ ...ended, largest: ended.largest.label }, { expenses: -154.4, refunds: 20.2, largest: 'HOTEL', days: 10, perDay: -13.42 });
    // Running: until today
    const running = tagFigures({ tag: { start_date: '2026-07-01', end_date: null }, total: -134.2, transactions }, '2026-07-05');
    assert.deepEqual([running.days, running.perDay], [5, -26.84]);
    // Not started yet, no expense
    const later = tagFigures({ tag: { start_date: '2026-08-01', end_date: null }, total: 20, transactions: [{ amount: 20 }] }, '2026-07-05');
    assert.deepEqual(later, { expenses: 0, refunds: 20, largest: null, days: 0, perDay: null });
});

test('categoryShares: largest amount first, its share and the length of its bar', () => {
    assert.deepEqual(categoryShares([
        { category: 2, total: '-40', count: '1' },
        { category: 3, total: -120, count: 2 },
        { category: null, total: 40, count: 1 },
    ]), [
        { category: 3, total: -120, count: 2, share: 60, width: 100 },
        { category: 2, total: -40, count: 1, share: 20, width: 33.3 },
        { category: null, total: 40, count: 1, share: 20, width: 33.3 },
    ]);
    assert.deepEqual(categoryShares(undefined), []);
});

test('the active tags are offered by name, the archived ones apart', () => {
    const context = tagsContext();
    assert.deepEqual(context.activeTags.map(t => t.name), ['anniversaire', 'voyage']);
    assert.deepEqual(context.archivedTags.map(t => t.name), ['ete-2020']);

    const transaction = { id: 9, tags: [1, 2] };
    assert.deepEqual(context.transactionTags(transaction).map(t => t.name), ['voyage', 'ete-2020']);
    assert.deepEqual(context.tagsToOffer(transaction).map(t => t.name), ['anniversaire']);
    assert.deepEqual(context.categoryOf(2).name, 'Supermarché');
    assert.deepEqual(context.categoryOf(7), { id: 7, name: '', color: '' });
});

test('adds and removes the tags of a transaction from its detail', async () => {
    const sent = [];
    const context = tagsContext({ setTransactionTags: async (...args) => { sent.push(args); }, fetchTags: async () => TAGS });
    const transaction = { id: 9, tags: [1] };

    await context.addDetailTag(transaction, 3);
    assert.deepEqual(transaction.tags, [1, 3]);
    // Already on it, or none: nothing sent
    await context.addDetailTag(transaction, 3);
    await context.addDetailTag(transaction, 0);
    await context.removeDetailTag(transaction, TAGS[0]);
    assert.deepEqual(transaction.tags, [3]);
    assert.deepEqual(sent, [[[9], [3], []], [[9], [], [1]]]);
});

test('tags a selection of the list at once', async () => {
    const sent = [];
    const context = tagsContext({ setTransactionTags: async (...args) => { sent.push(args); }, fetchTags: async () => TAGS });
    context.transactions = [{ id: 4, tags: [] }, { id: 5, tags: [3] }, { id: 6, tags: [] }];

    context.toggleTransactionSelection();
    assert.equal(context.selectingTransactions, true);
    context.toggleSelectedTransaction(context.transactions[0]);
    context.toggleSelectedTransaction(context.transactions[1]);
    context.toggleSelectedTransaction(context.transactions[2]);
    context.toggleSelectedTransaction(context.transactions[2]);
    assert.deepEqual(context.selectedTransactionIds, [4, 5]);

    await context.tagSelectedTransactions(3);
    assert.deepEqual(sent, [[[4, 5], [3], []]]);
    assert.deepEqual(context.transactions.map(t => t.tags), [[3], [3], []]);
    assert.equal(context.selectingTransactions, false);
    assert.equal(context.toasts.length, 1);
    assert.equal(MAX_TAG_SELECTION, 500);
});

test('matchingTags: the tags containing the text typed, those starting with it first', () => {
    const tags = [{ id: 1, name: 'voyage' }, { id: 2, name: 'travaux' }, { id: 3, name: 'anniversaire' }, { id: 4, name: 'avion' }];
    assert.deepEqual(matchingTags(tags, '').map(t => t.name), ['anniversaire', 'avion', 'travaux', 'voyage']);
    assert.deepEqual(matchingTags(tags, '#A').map(t => t.name), ['anniversaire', 'avion', 'travaux', 'voyage']);
    assert.deepEqual(matchingTags(tags, 'av').map(t => t.name), ['avion', 'travaux']);
    assert.deepEqual(matchingTags(tags, 'zz'), []);
});

test('tagToCreate: a valid name that no tag has, an archived one included', () => {
    assert.equal(tagToCreate(TAGS, ' #Noël '), 'noël');
    assert.equal(tagToCreate(TAGS, 'Voyage'), null);
    assert.equal(tagToCreate(TAGS, 'ete-2020'), null);
    assert.equal(tagToCreate(TAGS, ''), null);
    assert.equal(tagToCreate(TAGS, 'deux mots'), null);
    assert.equal(tagToCreate(TAGS, 'x'.repeat(41)), null);
});

test('creates a tag typed in the detail of a transaction, then adds it', async () => {
    const sent = [];
    const created = [];
    const context = tagsContext({
        createTag: async t => { created.push(t); return { id: 7, ...t, active: true }; },
        setTransactionTags: async (...args) => { sent.push(args); },
        fetchTags: async () => context.tags,
    });
    const transaction = { id: 9, tags: [1] };

    await context.createDetailTag(transaction, '#Noël');
    assert.equal(created[0].name, 'noël');
    assert.equal(created[0].color, 'blue');
    assert.match(created[0].start_date, /^\d{4}-\d{2}-\d{2}$/);
    assert.deepEqual(transaction.tags, [1, 7]);
    assert.ok(context.tagsById.has(7));
    assert.deepEqual(sent, [[[9], [7], []]]);
    assert.deepEqual(context.toasts, ['tagCreated {"name":"noël"}']);
});

test('a tag that cannot be created is not added', async () => {
    const context = tagsContext({ createTag: async () => { throw new Error('A tag named #voyage already exists'); } });
    const transaction = { id: 9, tags: [] };
    await context.createDetailTag(transaction, 'voyage');
    assert.deepEqual(transaction.tags, []);
    assert.deepEqual(context.toasts, ['A tag named #voyage already exists']);
});

test('creates a tag typed in the selection bar, then applies it to the selection', async () => {
    const sent = [];
    const context = tagsContext({
        createTag: async t => ({ id: 8, ...t, active: true }),
        setTransactionTags: async (...args) => { sent.push(args); },
        fetchTags: async () => context.tags,
    });
    context.transactions = [{ id: 4, tags: [] }];
    context.toggleTransactionSelection();
    context.toggleSelectedTransaction(context.transactions[0]);

    // Created and chosen, not applied yet: the button of the bar applies it
    await context.createSelectionTag('noel');
    assert.equal(context.selectionTag, 8);
    assert.deepEqual(sent, []);
    await context.tagSelectedTransactions(context.selectionTag);
    assert.deepEqual(sent, [[[4], [8], []]]);
    assert.deepEqual(context.transactions[0].tags, [8]);
    assert.equal(context.selectionTag, '');
});

test('saves a new tag, then reloads the tags', async () => {
    const created = [];
    const context = tagsContext({ createTag: async t => { created.push(t); return t; }, fetchTags: async () => TAGS });
    context.openTagModal();
    assert.equal(context.showTagModal, true);
    assert.match(context.tagForm.start_date, /^\d{4}-\d{2}-\d{2}$/);
    context.tagForm.name = '#Noël';
    context.tagForm.end_date = '';

    await context.saveTag();
    assert.deepEqual(created, [{ name: 'noël', color: 'blue', start_date: context.tagForm.start_date, end_date: null }]);
    assert.equal(context.showTagModal, false);
    assert.deepEqual(context.toasts, ['tagSaved']);
});

test('opens the summary of a tag and its transactions in the Transactions tab', async () => {
    const context = tagsContext({ fetchTagSummary: async id => ({ tag: TAGS[0], total: -120, count: 2, categories: [{ category: 2, total: -80, count: 1 }, { category: 3, total: -40, count: 1 }], transactions: [], days: [] }) });
    await context.openTagSummary(TAGS[0]);
    assert.equal(context.tagSummary.total, -120);
    assert.deepEqual(context.tagSummaryCategories.map(c => [c.category, c.share, c.width]), [[2, 67, 100], [3, 33, 50]]);
    context.showTagTransactions(TAGS[0]);
    assert.deepEqual(context.tabs, ['transactions']);
    assert.equal(context.searchQuery, '#voyage');
    assert.equal(context.searches, 1);
    context.closeTagSummary();
    assert.equal(context.tagSummary, null);
});

test('the chart of a summary: its width, the day pointed by the pointer or the keyboard', async () => {
    const days = [
        { date: '2026-07-01', total: -50, cumulative: -50 },
        { date: '2026-07-02', total: -20, cumulative: -70 },
        { date: '2026-07-04', total: 10, cumulative: -60 },
    ];
    const context = tagsContext({ fetchTagSummary: async () => ({ tag: TAGS[0], total: -60, count: 3, categories: [], transactions: [], days }) });
    context.intlLocale = 'en-US';
    await context.openTagSummary(TAGS[0]);
    assert.equal(context.tagChartPoint, null);

    // The width of its frame, followed while it changes
    const observers = [];
    globalThis.ResizeObserver = class {
        constructor(callback) { this.callback = callback; this.disconnected = false; observers.push(this); }
        observe(element) { this.element = element; }
        disconnect() { this.disconnected = true; }
    };
    try {
        const frame = {};
        context.observeTagChart(frame);
        context.observeTagChart(frame);
        assert.equal(observers.length, 1);
        observers[0].callback([{ contentRect: { width: 500.4 } }]);
        assert.equal(context.tagChartWidth, 500);
        assert.equal(context.tagSummaryChart.plot.right, 488);
        context.observeTagChart(null);
        assert.equal(observers[0].disconnected, true);
    } finally {
        delete globalThis.ResizeObserver;
    }

    // The pointer: the nearest day (the frame drawn at half its width)
    const right = context.tagSummaryChart.points[2].x;
    context.hoverTagChart({ clientX: 10 + right / 2 - 5, currentTarget: { getBoundingClientRect: () => ({ left: 10, width: 250 }) } });
    assert.equal(context.tagChartPoint.date, '2026-07-04');

    // The keyboard: from the last day, arrows, Home and End, held in the chart
    context.tagChartActive = null;
    context.focusTagChart();
    assert.equal(context.tagChartActive, 2);
    const key = name => {
        const event = { key: name, prevented: false, preventDefault() { this.prevented = true; } };
        context.moveTagChart(event);
        return event.prevented;
    };
    assert.equal(key('ArrowLeft'), true);
    assert.equal(context.tagChartPoint.date, '2026-07-02');
    key('Home');
    key('ArrowLeft');
    assert.equal(context.tagChartActive, 0);
    key('End');
    key('ArrowRight');
    assert.equal(context.tagChartActive, 2);
    assert.equal(key('Tab'), false);

    assert.equal(context.formatShortDay('2026-07-04'), 'Jul 4');
    assert.equal(context.formatShare(42), '42%');
    context.closeTagSummary();
    assert.equal(context.tagChartActive, null);
});

test('the API of the tags', async () => {
    const base = 'https://carbure.example.com/api';
    const calls = mockFetch(() => json({ id: 1 }));
    const api = createApiService(base, 't');

    await api.createTag({ name: 'voyage', color: 'green', start_date: '2026-07-01', end_date: null });
    await api.updateTag({ id: 1, name: 'voyage', color: 'green', start_date: '2026-07-01', end_date: null });
    await api.deleteTag(1);
    await api.fetchTagSummary(1);
    await api.setTransactionTags([4, 5], [1], [2]);
    assert.deepEqual(calls.map(c => [c.url, c.options.method || 'GET']), [
        [`${base}/tag`, 'POST'], [`${base}/tag`, 'PUT'], [`${base}/tag?id=1`, 'DELETE'], [`${base}/tag/summary?id=1`, 'GET'],
        [`${base}/transaction/tags`, 'PUT']]);
    assert.deepEqual(JSON.parse(calls[4].options.body), { ids: [4, 5], add: [1], remove: [2] });

    mockFetch(() => json([{ id: 1 }]));
    assert.deepEqual(await api.fetchTags(), [{ id: 1 }]);
});
