// "By category" chart of the Trends tab (portal/modules/trendCategoriesModule.js)
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createTrendsModule } from '../../portal/modules/trendsModule.js';
import {
    createTrendCategoriesModule, readCategoryChartPrefs, writeCategoryChartPrefs, stackSeries,
    categoryChartColor, CATEGORY_CHART_KEY
} from '../../portal/modules/trendCategoriesModule.js';
import { createApiService } from '../../portal/services/apiService.js';

const BASE = 'https://carbure.example.com/api';
const MONTHS = ['2026-08', '2026-09', '2026-10'];

/** Storage of the tests (localStorage) */
function memoryStorage(initial = {}) {
    const items = { ...initial };
    return {
        items,
        getItem: key => key in items ? items[key] : null,
        setItem: (key, value) => { items[key] = String(value); }
    };
}

/**
 * `this` of the portal: data, methods and computed properties (as getters) of both modules
 * @param {Object} api - Fake API service
 * @param {Object|null} storage - Storage of the choices
 */
function context(api = {}, storage = null) {
    const trends = createTrendsModule(() => api);
    const categories = createTrendCategoriesModule(() => api, () => storage);
    const toasts = [];
    const self = {
        ...trends.data(),
        ...categories.data(),
        ...trends.methods,
        ...categories.methods,
        intlLocale: 'fr-FR',
        toasts,
        showToast: message => toasts.push(message),
        t: key => key,
    };
    for (const [name, getter] of Object.entries(categories.computed)) {
        Object.defineProperty(self, name, { get: () => getter.call(self) });
    }
    return self;
}

/** Two categories over three months */
function withCategories(self) {
    self.trendCategories = {
        months: MONTHS,
        categories: [
            { id: 1, name: 'Alimentation', color: 'tomato', total: 150, amounts: [100, 50, 0] },
            { id: 3, name: 'Énergie', color: null, total: 90, amounts: [20, 30, 40] },
        ]
    };
    return self;
}

test('the choices are read from the storage, a wrong value gives its default', () => {
    assert.deepEqual(readCategoryChartPrefs(null), { kind: 'bars', type: 'DEBIT', level: 'top', hidden: [] });
    assert.deepEqual(readCategoryChartPrefs(memoryStorage({ [CATEGORY_CHART_KEY]: '{not json' })).kind, 'bars');
    assert.deepEqual(readCategoryChartPrefs(memoryStorage({ [CATEGORY_CHART_KEY]: '[1, 2]' })).hidden, []);
    const saved = JSON.stringify({ kind: 'pie', type: 'SAVINGS', level: 'deep', hidden: [3, 3, -1, 0, 'x', 2.5, 7] });
    assert.deepEqual(readCategoryChartPrefs(memoryStorage({ [CATEGORY_CHART_KEY]: saved })),
        { kind: 'bars', type: 'DEBIT', level: 'top', hidden: [3, 7] });
    const good = JSON.stringify({ kind: 'areas', type: 'HORS-BUDGET', level: 'sub', hidden: [] });
    assert.deepEqual(readCategoryChartPrefs(memoryStorage({ [CATEGORY_CHART_KEY]: good })),
        { kind: 'areas', type: 'HORS-BUDGET', level: 'sub', hidden: [] });
    // At most 500 hidden categories
    const many = JSON.stringify({ hidden: Array.from({ length: 600 }, (_, i) => i + 1) });
    assert.equal(readCategoryChartPrefs(memoryStorage({ [CATEGORY_CHART_KEY]: many })).hidden.length, 500);
});

test('a refused storage keeps the choice for the page only', () => {
    const refusing = { setItem: () => { throw new Error('QuotaExceededError'); } };
    assert.doesNotThrow(() => writeCategoryChartPrefs(refusing, { kind: 'lines' }));
    assert.doesNotThrow(() => writeCategoryChartPrefs(null, { kind: 'lines' }));
});

test('the kind, the type, the level and the hidden categories are kept in the storage', async () => {
    const storage = memoryStorage();
    const calls = [];
    const self = withCategories(context({
        fetchTrendCategories: async (...args) => { calls.push(args); return { months: [], categories: [] }; }
    }, storage));
    self.trendsMonths = 24;
    self.trendsOffset = 0;
    self.setTrendsChartKind('lines');
    self.setTrendsChartKind('pie');
    assert.equal(self.trendsChartKind, 'lines');
    self.toggleTrendCategory(3);
    await self.setTrendCategoryType('CREDIT');
    await self.setTrendCategoryType('SAVINGS');
    await self.setTrendCategoryLevel('sub');
    await self.setTrendCategoryLevel('deep');
    // Only the valid choices reload the categories
    assert.deepEqual(calls, [['CREDIT', 'top', 24, 0], ['CREDIT', 'sub', 24, 0]]);
    assert.deepEqual(JSON.parse(storage.items[CATEGORY_CHART_KEY]), { kind: 'lines', type: 'CREDIT', level: 'sub', hidden: [3] });
    // A new page starts with them
    const next = context({}, storage);
    assert.deepEqual([next.trendsChartKind, next.trendCategoryType, next.trendCategoryLevel, next.trendCategoryHidden],
        ['lines', 'CREDIT', 'sub', [3]]);
});

test('the chips show or hide a category, All and None only change the categories of the list', () => {
    const self = withCategories(context({}, memoryStorage()));
    self.trendCategoryHidden = [99];
    assert.deepEqual(self.trendCategoryItems.map(item => [item.id, item.visible]), [[1, true], [3, true]]);
    // Its own color, else one of the palette
    assert.deepEqual(self.trendCategoryItems.map(item => item.color), ['tomato', '#eb6834']);
    self.toggleTrendCategory(1);
    assert.deepEqual(self.trendCategoryVisible.map(item => item.id), [3]);
    self.toggleTrendCategory(1);
    assert.deepEqual(self.trendCategoryVisible.map(item => item.id), [1, 3]);
    self.hideAllTrendCategories();
    assert.deepEqual(self.trendCategoryVisible, []);
    assert.deepEqual(self.trendCategoryHidden, [99, 1, 3]);
    self.showAllTrendCategories();
    assert.deepEqual(self.trendCategoryHidden, [99]);
    assert.equal(self.trendCategoryVisible.length, 2);
});

test('the series are stacked up for the positive amounts, down for the negative ones', () => {
    const series = [{ amounts: [10, -5] }, { amounts: [20, -15] }];
    assert.deepEqual(stackSeries(series, 2, true).map(s => [s.low, s.high]), [[[0, 0], [10, -5]], [[10, -5], [30, -20]]]);
    assert.deepEqual(stackSeries(series, 2, false).map(s => [s.low, s.high]), [[[0, 0], [10, -5]], [[0, 0], [20, -15]]]);
    // A missing month counts zero
    assert.deepEqual(stackSeries([{ amounts: [] }], 1, true)[0].high, [0]);
    assert.equal(categoryChartColor({ color: '#123456' }, 0), '#123456');
    assert.equal(categoryChartColor({ color: 'unknown' }, 9), '#eb6834');
});

test('the chart is drawn as stacked bars, stacked areas or lines', () => {
    const self = withCategories(context());
    const bars = self.trendCategoryChart;
    assert.equal(bars.kind, 'bars');
    // A segment per category and month with an amount: Alimentation has none in October
    assert.equal(bars.bars.length, 5);
    assert.deepEqual(bars.bars.map(bar => bar.key), ['1-2026-08', '1-2026-09', '3-2026-08', '3-2026-09', '3-2026-10']);
    assert.deepEqual([bars.areas, bars.lines], [[], []]);
    // The scale covers the stack: 100 + 20 in August
    assert.ok(bars.top >= 120 && bars.bottom === 0);
    assert.equal(bars.labels.length, 3);
    assert.equal(bars.columns.length, 3);

    self.trendsChartKind = 'lines';
    const lines = self.trendCategoryChart;
    assert.deepEqual(lines.lines.map(line => [line.key, line.color, line.points.split(' ').length]), [[1, 'tomato', 3], [3, '#eb6834', 3]]);
    assert.deepEqual([lines.bars, lines.areas], [[], []]);
    // Not stacked: the scale covers the largest amount only
    assert.ok(lines.top >= 100 && lines.top < bars.top);

    self.trendsChartKind = 'areas';
    const areas = self.trendCategoryChart;
    assert.equal(areas.areas.length, 2);
    assert.ok(areas.areas.every(area => /^M.* Z$/.test(area.d)));
    // Énergie lies on Alimentation: its lower edge is the upper edge of Alimentation
    const upperOfFirst = areas.areas[0].d.slice(1).split(' L').slice(0, 3);
    const lowerOfSecond = areas.areas[1].d.replace(/ Z$/, '').split(' L').slice(3).reverse();
    assert.deepEqual(lowerOfSecond, upperOfFirst);
    assert.equal(areas.top, bars.top);
});

test('hiding a category computes the scale again on the shown ones', () => {
    const self = withCategories(context());
    const all = self.trendCategoryChart.top;
    self.toggleTrendCategory(1);
    const energy = self.trendCategoryChart;
    assert.ok(energy.top >= 40 && energy.top < all);
    assert.equal(energy.bars.length, 3);
    self.toggleTrendCategory(3);
    const none = self.trendCategoryChart;
    assert.deepEqual([none.bars.length, none.bottom], [0, 0]);
    assert.ok(none.top > 0);
});

test('a single month is drawn across its slot, no month gives an empty chart', () => {
    const self = context();
    self.trendCategories = { months: ['2026-10'], categories: [{ id: 1, name: 'A', color: null, total: 5, amounts: [5] }] };
    self.trendsChartKind = 'lines';
    assert.match(self.trendCategoryChart.lines[0].points, /^68,([\d.]+) 948,\1$/);
    self.trendCategories = { months: [], categories: [] };
    const empty = self.trendCategoryChart;
    assert.deepEqual([empty.lines, empty.labels, empty.columns], [[], [], []]);
});

test('the tooltip lists the shown categories, largest amount first, and their total', () => {
    const self = withCategories(context());
    assert.equal(self.trendCategoryHoverMonth, null);
    self.trendCategoryHover = 2;
    assert.deepEqual(self.trendCategoryHoverMonth.rows.map(row => [row.name, row.amount]), [['Énergie', 40], ['Alimentation', 0]]);
    self.trendCategoryHover = 1;
    assert.deepEqual(self.trendCategoryHoverMonth, {
        month: '2026-09',
        rows: [{ id: 1, name: 'Alimentation', color: 'tomato', amount: 50 }, { id: 3, name: 'Énergie', color: '#eb6834', amount: 30 }],
        total: 80
    });
    self.toggleTrendCategory(1);
    assert.equal(self.trendCategoryHoverMonth.total, 30);
    self.trendCategoryHover = 3;
    assert.equal(self.trendCategoryHoverMonth, null);
    // Kept inside the chart
    self.trendCategoryHover = 0;
    assert.ok(self.trendCategoryHoverLeft >= 15 && self.trendCategoryHoverLeft <= 85);
    self.trendCategories = { months: Array.from({ length: 240 }, () => '2026-01'), categories: [] };
    assert.equal(self.trendCategoryHoverLeft, 15);
    self.trendCategoryHover = 239;
    assert.equal(self.trendCategoryHoverLeft, 85);
});

test('the answer of an older request is ignored, an error is shown', async () => {
    const answers = [];
    const self = context({ fetchTrendCategories: () => new Promise((resolve, reject) => answers.push({ resolve, reject })) });
    const first = self.loadTrendCategories();
    const second = self.loadTrendCategories();
    assert.equal(self.loadingTrendCategories, true);
    answers[1].resolve({ months: ['2026-10'], categories: [{ id: 2, amounts: [1] }] });
    await second;
    answers[0].resolve({ months: ['2020-01'], categories: [] });
    await first;
    assert.deepEqual(self.trendCategories.months, ['2026-10']);
    assert.equal(self.loadingTrendCategories, false);

    const failing = context({ fetchTrendCategories: async () => { throw new Error('Unknown type'); } });
    await failing.loadTrendCategories();
    assert.deepEqual([failing.toasts, failing.loadingTrendCategories], [['Unknown type'], false]);
});

test('the trends of a period load the categories of the same period', async () => {
    const calls = [];
    const self = context({
        fetchTrends: async () => [],
        fetchTrendCharts: async () => [],
        fetchTrendCategories: async (...args) => { calls.push(args); return { months: [], categories: [] }; }
    });
    await self.setTrendsMonths(36);
    assert.deepEqual(calls.at(-1), ['DEBIT', 'top', 36, 0]);
});

test('the API service calls the categories route and checks its answer', async () => {
    const calls = mockFetch(() => json({ months: ['2026-10'], categories: [{ id: 1 }] }));
    const api = createApiService(BASE, 'jwt');
    assert.deepEqual(await api.fetchTrendCategories('CREDIT', 'sub', 36, 2), { months: ['2026-10'], categories: [{ id: 1 }] });
    assert.equal(calls[0].url, `${BASE}/budget/trends/categories?type=CREDIT&level=sub&months=36&offset=2`);
    await api.fetchTrendCategories();
    assert.equal(calls[1].url, `${BASE}/budget/trends/categories?type=DEBIT&level=top&months=12&offset=0`);

    mockFetch(() => json({ months: 'x' }));
    assert.deepEqual(await api.fetchTrendCategories(), { months: [], categories: [] });
    mockFetch(() => json({ error: 'Unknown level: expected top or sub' }, 400));
    await assert.rejects(api.fetchTrendCategories('DEBIT', 'deep'), /Unknown level/);
});
