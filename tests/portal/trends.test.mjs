// Trends tab (portal/modules/trendsModule.js): periods, axis labels and custom charts
import { mockFetch, json } from './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    createTrendsModule, rangeToPeriod, periodToRange, labelStep, monthIndex, monthKey, trendChartKey,
    TREND_PERIODS, MAX_TREND_MONTHS
} from '../../portal/modules/trendsModule.js';
import { createTrendCategoriesModule } from '../../portal/modules/trendCategoriesModule.js';
import { createApiService } from '../../portal/services/apiService.js';

const BASE = 'https://carbure.example.com/api';
const NOW = new Date(2026, 9, 23);

/** Months of a period ending in October 2026 */
function months(count) {
    const end = monthIndex('2026-10');
    return Array.from({ length: count }, (_, i) => ({ month: monthKey(end - count + 1 + i) }));
}

/**
 * `this` of the portal for the trends methods (the "By category" chart included, with no
 * category unless the fake API gives some)
 * @param {Object} api - Fake API service
 */
function context(api = {}) {
    const fake = { fetchTrendCategories: async () => ({ months: [], categories: [] }), ...api };
    const module = createTrendsModule(() => fake);
    const categories = createTrendCategoriesModule(() => fake, () => null);
    const toasts = [];
    const self = {
        ...module.data(),
        ...categories.data(),
        ...module.methods,
        ...categories.methods,
        intlLocale: 'fr-FR',
        toasts,
        showToast: message => toasts.push(message),
        t: (key, params) => params ? `${key} ${JSON.stringify(params)}` : key,
    };
    return self;
}

test('the period buttons go up to 5 years, the API up to 20 years', () => {
    assert.deepEqual(TREND_PERIODS, [3, 6, 12, 24, 36, 60]);
    assert.equal(MAX_TREND_MONTHS, 240);
});

test('a custom range gives the months and the offset of the API', () => {
    assert.deepEqual(rangeToPeriod('2026-01', '2026-10', NOW), { months: 10, offset: 0 });
    assert.deepEqual(rangeToPeriod('2024-07', '2025-06', NOW), { months: 12, offset: 16 });
    assert.deepEqual(rangeToPeriod('2026-10', '2026-10', NOW), { months: 1, offset: 0 });
    // 240 months: the longest range
    assert.deepEqual(rangeToPeriod('2006-11', '2026-10', NOW), { months: 240, offset: 0 });
});

test('an invalid custom range is refused', () => {
    // 241 months
    assert.equal(rangeToPeriod('2006-10', '2026-10', NOW), null);
    // After the current month
    assert.equal(rangeToPeriod('2026-01', '2026-11', NOW), null);
    // First month after the last one
    assert.equal(rangeToPeriod('2026-05', '2026-04', NOW), null);
    // Not months
    assert.equal(rangeToPeriod('', '2026-04', NOW), null);
    assert.equal(rangeToPeriod('2026-13', '2026-12', NOW), null);
    assert.equal(rangeToPeriod('2026-04', 'avril', NOW), null);
    // Ends more than 240 months ago
    assert.equal(rangeToPeriod('2005-01', '2006-09', NOW), null);
});

test('a period of the API gives back its range', () => {
    assert.deepEqual(periodToRange(12, 0, NOW), { from: '2025-11', to: '2026-10' });
    assert.deepEqual(periodToRange(3, 10, NOW), { from: '2025-10', to: '2025-12' });
    assert.deepEqual(rangeToPeriod(periodToRange(36, 5, NOW).from, periodToRange(36, 5, NOW).to, NOW), { months: 36, offset: 5 });
});

test('at most 12 labels on the x axis, yearly beyond 6 years', () => {
    assert.equal(labelStep(3), 1);
    assert.equal(labelStep(6), 1);
    assert.equal(labelStep(12), 2);
    assert.equal(labelStep(36), 3);
    assert.equal(labelStep(60), 6);
    assert.equal(labelStep(120), 12);
    assert.equal(labelStep(240), 24);
    for (const count of [1, 13, 37, 73, 145, 239, 240]) {
        assert.ok(Math.ceil(count / labelStep(count)) <= 12, `${count} months`);
    }
});

test('the labels of a long period are years, on January', () => {
    const self = context();
    const labels = self.monthLabels(10, 200, months(240));
    assert.ok(labels.length <= 12);
    assert.ok(labels.every(label => label.key.endsWith('-01') && label.text === label.key.slice(0, 4)));
    // Every second year, the one of the last month included
    assert.ok(labels.some(label => label.text === '2026'));
    assert.ok(!labels.some(label => label.text === '2025'));

    const short = self.monthLabels(80, 200, months(12));
    assert.equal(short.length, 6);
    assert.equal(short.at(-1).key, '2026-10');
});

test('a custom chart has a bar per month, the average and the planned budget of the months that have one', () => {
    const self = context();
    const chart = self.seriesChart([
        { month: '2026-08', amount: 100, planned: null },
        { month: '2026-09', amount: -20, planned: 150 },
        { month: '2026-10', amount: 160, planned: 150 },
    ]);
    assert.equal(chart.bars.length, 3);
    assert.deepEqual(chart.bars.map(bar => bar.positive), [true, false, true]);
    assert.equal(chart.average, 80);
    assert.equal(chart.hasPlanned, true);
    // One segment: September and October
    assert.equal(chart.planned.length, 1);
    assert.equal(chart.planned[0].split(' ').length, 2);
    assert.ok(chart.zero > chart.ticks.at(-1).y && chart.zero < chart.ticks[0].y);
    assert.equal(chart.columns.length, 3);

    const empty = self.seriesChart([]);
    assert.deepEqual([empty.bars, empty.planned, empty.hasPlanned, empty.average], [[], [], false, 0]);
});

test('a custom chart is drawn as a line or an area through the middle of the months', () => {
    const self = context();
    const series = [
        { month: '2026-08', amount: 100, planned: null },
        { month: '2026-09', amount: -20, planned: 150 },
        { month: '2026-10', amount: 160, planned: 150 },
    ];
    const bars = self.seriesChart(series);
    assert.deepEqual([bars.kind, bars.line, bars.area, bars.markers], ['bars', '', '', []]);

    const lines = self.seriesChart(series, 'lines');
    assert.deepEqual(lines.bars, []);
    assert.equal(lines.area, '');
    const points = lines.line.split(' ').map(point => point.split(',').map(Number));
    const group = (960 - 68 - 12) / 3;
    assert.deepEqual(points.map(([x]) => x), [68 + group / 2, 68 + 1.5 * group, 68 + 2.5 * group]);
    // A negative month goes below zero, the highest month is the highest point
    assert.ok(points[1][1] > lines.zero);
    assert.ok(points[2][1] < points[0][1]);
    assert.deepEqual(lines.markers.map(marker => marker.key), ['2026-08', '2026-09', '2026-10']);
    // The kind does not change the scale, the average nor the planned budget
    assert.deepEqual([lines.ticks, lines.average, lines.planned], [bars.ticks, bars.average, bars.planned]);

    const areas = self.seriesChart(series, 'areas');
    assert.deepEqual([areas.bars, areas.markers], [[], []]);
    assert.equal(areas.line, lines.line);
    // Closed on the zero line, right to left
    assert.match(areas.area, new RegExp(`^M${points[0][0]},${points[0][1]} L.* L${points[0][0]},${areas.zero} Z$`));

    // A single month: a flat line across its slot, without marker
    const single = self.seriesChart([series[0]], 'lines');
    assert.match(single.line, /^68,([\d.]+) 948,\1$/);
    assert.deepEqual(single.markers, []);
    // Beyond 60 months, no marker: they would cover the line
    const long = months(61).map(month => ({ ...month, amount: 1, planned: null }));
    assert.equal(self.seriesChart(long, 'lines').markers.length, 0);
    assert.equal(self.seriesChart(long.slice(1), 'lines').markers.length, 60);
    assert.equal(self.seriesChart([], 'areas').area, '');
});

test('the kind of chart chosen applies to every custom chart', () => {
    const self = context();
    self.trendCharts = [{ key: 'category-1', series: [{ month: '2026-10', amount: 5, planned: null }] }, { key: 'search-x' }];
    const views = () => createTrendsModule(() => ({})).computed.trendChartViews.call(self);
    assert.deepEqual(views().map(view => view.geometry.kind), ['bars', 'bars']);
    self.trendsChartKind = 'areas';
    assert.deepEqual(views().map(view => view.geometry.kind), ['areas', 'areas']);
    assert.notEqual(views()[0].geometry.area, '');
});

test('a planned budget of a single month is a flat line over its slot', () => {
    const segments = context().plannedSegments([null, 50, null, 60, 70], 10, value => value);
    assert.deepEqual(segments, ['78,50 88,50', '103,60 113,70']);
});

test('a custom range is applied, an invalid one is refused with a message', async () => {
    const calls = [];
    const self = context({
        fetchTrends: async (m, offset) => { calls.push(['trends', m, offset]); return []; },
        fetchTrendCharts: async () => [],
    });
    self.openTrendsRange();
    assert.equal(self.trendsCustom, true);
    assert.equal(rangeToPeriod(self.trendsFrom, self.trendsTo).months, 12);

    self.trendsCompare = 'previous';
    self.trendsFrom = '2020-01';
    self.trendsTo = '2021-12';
    await self.applyTrendsRange();
    const offset = rangeToPeriod('2020-01', '2021-12').offset;
    assert.deepEqual(calls, [['trends', 24, offset], ['trends', 24, offset + 24]]);

    self.trendsTo = '2019-01';
    await self.applyTrendsRange();
    assert.equal(calls.length, 2);
    assert.match(self.toasts[0], /^trendsRangeInvalid/);

    // A button goes back to the last months
    self.setTrendsMonths(6);
    assert.deepEqual([self.trendsCustom, self.trendsOffset, self.trendsMonths], [false, 0, 6]);
});

test('the custom charts load with their series over the period', async () => {
    const asked = [];
    const self = context({
        fetchTrends: async () => [],
        fetchTrendCharts: async () => [{ type: 'category', id: 1, name: 'Alimentation' }, { type: 'insight', id: 2, name: 'Dépenses' }],
        fetchTrendSeries: async (type, id, m, offset) => {
            asked.push([type, id, m, offset]);
            if (type === 'insight') {
                throw new Error('Insight not found');
            }
            return [{ month: '2026-10', amount: 12, planned: null }];
        },
    });
    self.trendsMonths = 36;
    self.trendsOffset = 2;
    await self.loadTrends();
    assert.deepEqual(asked, [['category', 1, 36, 2], ['insight', 2, 36, 2]]);
    assert.deepEqual(self.trendCharts.map(c => [c.key, c.series.length, c.loading]), [['category-1', 1, false], ['insight-2', 0, false]]);
    assert.deepEqual(self.toasts, ['Insight not found']);
});

test('the answer of a previous period does not replace the series', async () => {
    let release;
    const answers = [new Promise(resolve => { release = resolve; }), Promise.resolve([{ month: 'new' }])];
    const self = context({ fetchTrendSeries: () => answers.shift() });
    self.setTrendCharts([{ type: 'category', id: 1 }]);
    const slow = self.loadTrendSeries(self.trendCharts);
    await self.loadTrendSeries(self.trendCharts);
    release([{ month: 'old' }]);
    await slow;
    assert.deepEqual(self.trendCharts[0].series, [{ month: 'new' }]);
});

test('an administrator adds, moves and removes custom charts', async () => {
    const saved = [];
    const self = context({
        saveTrendCharts: async charts => { saved.push(charts); return charts.map(c => ({ ...c, name: `n${c.id}` })); },
        fetchTrendSeries: async () => [],
    });
    self.trendChartForm = { type: 'category', category: '', insight: '' };
    await self.submitTrendChart();
    assert.deepEqual([saved.length, self.toasts.at(-1)], [0, 'trendChartChooseCategory']);

    self.trendChartForm.category = 3;
    await self.submitTrendChart();
    self.trendChartForm = { type: 'insight', category: '', insight: '2' };
    await self.submitTrendChart();
    assert.deepEqual(saved.at(-1), [{ type: 'category', id: 3 }, { type: 'insight', id: 2 }]);
    assert.equal(self.trendChartForm, null);
    assert.equal(self.toasts.at(-1), 'trendChartAdded');

    // Twice the same chart
    self.trendChartForm = { type: 'insight', category: '', insight: 2 };
    await self.submitTrendChart();
    assert.equal(self.toasts.at(-1), 'trendChartExists');
    assert.equal(saved.length, 2);

    await self.moveTrendChart(self.trendCharts[1], -1);
    assert.deepEqual(self.trendCharts.map(c => c.key), ['insight-2', 'category-3']);
    // Already first: nothing saved
    await self.moveTrendChart(self.trendCharts[0], -1);
    assert.equal(saved.length, 3);

    await self.removeTrendChart(self.trendCharts[0]);
    assert.deepEqual(self.trendCharts.map(c => c.key), ['category-3']);
    assert.equal(self.toasts.at(-1), 'trendChartRemoved');
});

test('at most twelve custom charts, and a refused save keeps the list', async () => {
    const self = context({ saveTrendCharts: async () => { throw new Error('Unknown category: 99'); } });
    self.setTrendCharts(Array.from({ length: 12 }, (_, i) => ({ type: 'category', id: i + 1 })));
    self.trendChartForm = { type: 'category', category: 99, insight: '' };
    await self.submitTrendChart();
    assert.equal(self.toasts.at(-1), 'trendChartMax {"count":12}');

    self.setTrendCharts([{ type: 'category', id: 1 }]);
    await self.submitTrendChart();
    assert.equal(self.toasts.at(-1), 'Unknown category: 99');
    assert.deepEqual(self.trendCharts.map(c => c.key), ['category-1']);
    assert.notEqual(self.trendChartForm, null);
    assert.equal(self.savingTrendCharts, false);
});

test('the title of a custom chart is the one of its category or insight', () => {
    const self = context();
    Object.assign(self, {
        categoryIcon: item => item.icon,
        categoryBadgeStyle: item => ({ color: item.color }),
        getInsightName: name => `«${name}»`,
        getInsightIcon: () => 'analytics',
        badgeStyleFor: color => ({ color }),
        insightColorValues: { red: '#ef4444' },
    });
    assert.deepEqual(self.trendChartTitle({ type: 'category', name: 'Alimentation', icon: 'restaurant', color: 'green' }),
        { name: 'Alimentation', icon: 'restaurant', style: { color: 'green' } });
    assert.deepEqual(self.trendChartTitle({ type: 'insight', name: 'Dépenses', icon: '', color: 'red' }),
        { name: '«Dépenses»', icon: 'analytics', style: { color: '#ef4444' } });
    assert.equal(trendChartKey({ type: 'insight', id: 4 }), 'insight-4');
});

test('the API service calls the series and the charts routes', async () => {
    const calls = mockFetch(() => json([{ type: 'category', id: 1 }]));
    const api = createApiService(BASE, 'jwt');
    await api.fetchTrendSeries('category', 1, 36, 2);
    await api.fetchTrendCharts();
    await api.saveTrendCharts([{ type: 'category', id: 1 }]);
    assert.equal(calls[0].url, `${BASE}/budget/trends/series?type=category&id=1&months=36&offset=2`);
    assert.equal(calls[1].url, `${BASE}/budget/trends/charts`);
    assert.equal(calls[2].options.method, 'PUT');
    assert.deepEqual(JSON.parse(calls[2].options.body), { charts: [{ type: 'category', id: 1 }] });
});

test('an error of the charts routes is thrown with the message of the API', async () => {
    mockFetch(() => json({ error: 'The same chart is given twice' }, 400));
    await assert.rejects(createApiService(BASE).saveTrendCharts([]), /The same chart is given twice/);
    mockFetch(() => json({ error: 'Category not found' }, 404));
    await assert.rejects(createApiService(BASE).fetchTrendSeries('category', 99), /Category not found/);
});
