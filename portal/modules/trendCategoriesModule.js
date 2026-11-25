/**
 * Trend Categories Module
 *
 * "By category" chart of the Trends tab: the categories of a type (expenses, incomes or
 * off-budget) compared month by month on one chart, as lines, stacked bars or stacked
 * areas, filtered with a legend of toggle chips. The kind of chart also applies to the
 * custom charts (trendsModule.js). The choices are kept in this browser (localStorage).
 *
 * @module trendCategoriesModule
 */

import { cssColorFor } from '../utils/categoryIcons.js';
import { WIDTH, MARGIN, niceScale, barWidthFor, slotPoints, pointsAttribute, areaPath } from './trendsModule.js';

/** Kinds of chart */
export const CHART_KINDS = ['lines', 'bars', 'areas'];

/** Category types compared (as the API) */
export const CATEGORY_TYPES = ['DEBIT', 'CREDIT', 'HORS-BUDGET'];

/** Levels compared: top-level categories (sub-categories included) or every category */
export const CATEGORY_LEVELS = ['top', 'sub'];

/** Key of the choices in localStorage */
export const CATEGORY_CHART_KEY = 'trendsCategoryChart';

/** Most hidden categories kept */
const MAX_HIDDEN = 500;

/** Height of the chart (SVG user units) */
const CATEGORIES_HEIGHT = 300;

/** Colors of the categories without a color of their own */
const PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#8e5bd0', '#d4a20b', '#e34948', '#1597a8', '#a0673a'];

/** Buttons of the kinds of chart: Material icon and text */
const KIND_BUTTONS = [
    { key: 'lines', icon: 'show_chart', label: 'trendsKindLines' },
    { key: 'bars', icon: 'bar_chart', label: 'trendsKindBars' },
    { key: 'areas', icon: 'area_chart', label: 'trendsKindAreas' }
];

/** Options of the type of categories */
const TYPE_OPTIONS = [
    { value: 'DEBIT', label: 'trendCategoriesDebit' },
    { value: 'CREDIT', label: 'trendCategoriesCredit' },
    { value: 'HORS-BUDGET', label: 'trendCategoriesOffBudget' }
];

/**
 * localStorage of the browser, null when it is not reachable (private mode, tests)
 * @returns {Storage|null}
 */
function browserStorage() {
    try {
        return globalThis.localStorage || null;
    } catch (e) {
        return null;
    }
}

/**
 * Choices of the chart kept in a storage, each one checked (a wrong value gives its default)
 * @param {Storage|null} storage - Storage holding them
 * @returns {{kind: string, type: string, level: string, hidden: Array<number>}} kind (bars by
 *          default), type (DEBIT), level (top) and the ids of the hidden categories
 */
export function readCategoryChartPrefs(storage) {
    let saved = null;
    try {
        saved = JSON.parse(storage?.getItem(CATEGORY_CHART_KEY) || 'null');
    } catch (e) {
        saved = null;
    }
    const value = saved && typeof saved === 'object' && !Array.isArray(saved) ? saved : {};
    const hidden = Array.isArray(value.hidden) ? value.hidden.filter(id => Number.isInteger(id) && id > 0) : [];
    return {
        kind: CHART_KINDS.includes(value.kind) ? value.kind : 'bars',
        type: CATEGORY_TYPES.includes(value.type) ? value.type : 'DEBIT',
        level: CATEGORY_LEVELS.includes(value.level) ? value.level : 'top',
        hidden: [...new Set(hidden)].slice(0, MAX_HIDDEN)
    };
}

/**
 * Keeps the choices of the chart in a storage (a refused storage keeps them for this page only)
 * @param {Storage|null} storage - Storage
 * @param {{kind: string, type: string, level: string, hidden: Array<number>}} prefs - Choices
 */
export function writeCategoryChartPrefs(storage, prefs) {
    try {
        storage?.setItem(CATEGORY_CHART_KEY, JSON.stringify(prefs));
    } catch (e) {
        // Storage refused (private mode): the choice still applies to this page
    }
}

/**
 * Color of a category in the chart: its own color, else one of the palette
 * @param {{color: string|null}} category - Category
 * @param {number} index - Its position in the list
 * @returns {string} CSS color
 */
export function categoryChartColor(category, index) {
    return cssColorFor(category.color) || PALETTE[index % PALETTE.length];
}

/**
 * Lower and upper values of each series for each month: stacked (positive amounts
 * above the previous series, negative ones below) or each from zero
 * @param {Array<{amounts: Array<number>}>} series - Series in drawing order
 * @param {number} count - Number of months
 * @param {boolean} stacked - Whether the series are stacked
 * @returns {Array<Object>} The series with their low and high values
 */
export function stackSeries(series, count, stacked) {
    const above = new Array(count).fill(0);
    const below = new Array(count).fill(0);
    return series.map(s => {
        const low = [];
        const high = [];
        for (let i = 0; i < count; i++) {
            const value = Number(s.amounts[i]) || 0;
            const base = !stacked ? null : value >= 0 ? above : below;
            low.push(base ? base[i] : 0);
            if (base) {
                base[i] += value;
            }
            high.push(base ? base[i] : value);
        }
        return { ...s, low, high };
    });
}

/**
 * Creates the "By category" chart mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @param {Function} getStorage - Function that returns the storage of the choices
 * @returns {Object} Vue mixin
 */
export function createTrendCategoriesModule(getApiService, getStorage = browserStorage) {
    return {
        data() {
            const prefs = readCategoryChartPrefs(getStorage());
            return {
                // Kind of the charts: lines, bars or areas (also the custom charts)
                trendsChartKind: prefs.kind,
                // Categories compared: type and level of the API
                trendCategoryType: prefs.type,
                trendCategoryLevel: prefs.level,
                // Ids of the categories hidden by their chip
                trendCategoryHidden: prefs.hidden,
                // Answer of the API: {months, categories}
                trendCategories: { months: [], categories: [] },
                loadingTrendCategories: false,
                // Last request: the answers of an older one are ignored
                trendCategoriesRequest: 0,
                // Index of the month under the pointer
                trendCategoryHover: null,
                trendChartKinds: KIND_BUTTONS,
                trendCategoryTypes: TYPE_OPTIONS
            };
        },

        computed: {
            /**
             * Categories of the legend, with their color and whether they are shown
             * @returns {Array<Object>}
             */
            trendCategoryItems() {
                return this.trendCategories.categories.map((category, i) => ({
                    ...category,
                    color: categoryChartColor(category, i),
                    visible: !this.trendCategoryHidden.includes(category.id)
                }));
            },

            /**
             * Categories shown on the chart
             * @returns {Array<Object>}
             */
            trendCategoryVisible() {
                return this.trendCategoryItems.filter(category => category.visible);
            },

            /**
             * Geometry of the chart of the shown categories
             * @returns {Object}
             */
            trendCategoryChart() {
                return this.categoryChartGeometry(this.trendCategories.months, this.trendCategoryVisible, this.trendsChartKind);
            },

            /**
             * Tooltip of the month under the pointer: the shown categories, largest
             * amount first, and their total
             * @returns {{month: string, rows: Array<Object>, total: number}|null}
             */
            trendCategoryHoverMonth() {
                const index = this.trendCategoryHover;
                const month = index === null ? undefined : this.trendCategories.months[index];
                if (month === undefined) {
                    return null;
                }
                const rows = this.trendCategoryVisible
                    .map(category => ({ id: category.id, name: category.name, color: category.color, amount: Number(category.amounts[index]) || 0 }))
                    .sort((a, b) => b.amount - a.amount);
                return { month, rows, total: rows.reduce((sum, row) => sum + row.amount, 0) };
            },

            /**
             * Horizontal position of the tooltip, in % of the chart width
             * @returns {number}
             */
            trendCategoryHoverLeft() {
                const group = (WIDTH - MARGIN.left - MARGIN.right) / Math.max(1, this.trendCategories.months.length);
                const x = MARGIN.left + ((this.trendCategoryHover || 0) + 0.5) * group;
                return Math.min(85, Math.max(15, x / WIDTH * 100));
            }
        },

        methods: {
            /**
             * Geometry of the categories chart: a polyline per category (lines), or the
             * categories stacked month by month (bars, areas); the scale covers the shown
             * categories only
             * @param {Array<string>} months - Months (YYYY-MM)
             * @param {Array<{id: number, color: string, amounts: Array<number>}>} categories - Shown categories
             * @param {string} kind - lines, bars or areas
             * @returns {Object}
             */
            categoryChartGeometry(months, categories, kind) {
                const plotWidth = WIDTH - MARGIN.left - MARGIN.right;
                const plotHeight = CATEGORIES_HEIGHT - MARGIN.top - MARGIN.bottom;
                const group = plotWidth / Math.max(1, months.length);
                const series = stackSeries(categories, months.length, kind !== 'lines');
                const values = series.flatMap(s => s.high);
                const step = niceScale(Math.max(1, ...values.map(Math.abs)), 4).step;
                const bottom = Math.floor(Math.min(0, ...values) / step) * step;
                const top = Math.max(bottom + step, Math.ceil(Math.max(0, ...values) / step) * step);
                const y = value => MARGIN.top + ((top - value) / (top - bottom)) * plotHeight;
                const slots = months.map(month => ({ month }));

                const ticks = [];
                for (let value = bottom; value <= top + 1e-9; value += step) {
                    ticks.push({ value, y: y(value), label: this.formatCompact(value) });
                }
                return {
                    width: WIDTH,
                    height: CATEGORIES_HEIGHT,
                    kind,
                    top,
                    bottom,
                    bars: kind === 'bars' ? this.stackedBars(series, months, group, y) : [],
                    areas: kind === 'areas' ? series.map(s => ({
                        key: s.id,
                        color: s.color,
                        d: areaPath(slotPoints(s.high, group, y), slotPoints(s.low, group, y))
                    })) : [],
                    lines: kind === 'lines' ? series.map(s => ({ key: s.id, color: s.color, points: pointsAttribute(slotPoints(s.high, group, y)) })) : [],
                    zero: y(0),
                    ticks,
                    labels: this.monthLabels(group, CATEGORIES_HEIGHT, slots),
                    columns: this.hoverColumns(group, MARGIN.top, plotHeight, slots)
                };
            },

            /**
             * Segments of the stacked bars: one per category and month with an amount
             * @param {Array<Object>} series - Series of stackSeries()
             * @param {Array<string>} months - Months (YYYY-MM)
             * @param {number} group - Width of the slot of a month
             * @param {Function} y - Vertical position of an amount
             * @returns {Array<{key: string, color: string, d: string}>}
             */
            stackedBars(series, months, group, y) {
                const width = barWidthFor(group, 0.6, 48);
                return series.flatMap(s => months.flatMap((month, i) => {
                    const [y0, y1] = [y(s.low[i]), y(s.high[i])];
                    const x = MARGIN.left + i * group + (group - width) / 2;
                    // A month without amount has no segment
                    return Math.abs(y1 - y0) < 0.1 ? []
                        : [{ key: `${s.id}-${month}`, color: s.color, d: `M${x},${y0} V${y1} H${x + width} V${y0} Z` }];
                }));
            },

            /**
             * Loads the categories compared over the selected period; the answer of an
             * older request is ignored
             * @returns {Promise<void>}
             */
            async loadTrendCategories() {
                const request = ++this.trendCategoriesRequest;
                this.loadingTrendCategories = true;
                try {
                    const data = await getApiService().fetchTrendCategories(this.trendCategoryType, this.trendCategoryLevel, this.trendsMonths, this.trendsOffset);
                    if (request === this.trendCategoriesRequest) {
                        this.trendCategories = data;
                        this.trendCategoryHover = null;
                    }
                } catch (error) {
                    if (request === this.trendCategoriesRequest) {
                        this.showToast(error.message);
                    }
                } finally {
                    if (request === this.trendCategoriesRequest) {
                        this.loadingTrendCategories = false;
                    }
                }
            },

            /**
             * Keeps the choices of the chart in this browser
             */
            saveTrendCategoryPrefs() {
                writeCategoryChartPrefs(getStorage(), {
                    kind: this.trendsChartKind,
                    type: this.trendCategoryType,
                    level: this.trendCategoryLevel,
                    hidden: this.trendCategoryHidden.slice(0, MAX_HIDDEN)
                });
            },

            /**
             * Changes the kind of the charts (categories and custom charts)
             * @param {string} kind - lines, bars or areas
             */
            setTrendsChartKind(kind) {
                if (CHART_KINDS.includes(kind)) {
                    this.trendsChartKind = kind;
                    this.saveTrendCategoryPrefs();
                }
            },

            /**
             * Changes the type of the categories compared and reloads them
             * @param {string} type - DEBIT, CREDIT or HORS-BUDGET
             * @returns {Promise<void>}
             */
            async setTrendCategoryType(type) {
                if (CATEGORY_TYPES.includes(type)) {
                    this.trendCategoryType = type;
                    this.saveTrendCategoryPrefs();
                    await this.loadTrendCategories();
                }
            },

            /**
             * Changes the level of the categories compared and reloads them
             * @param {string} level - top or sub
             * @returns {Promise<void>}
             */
            async setTrendCategoryLevel(level) {
                if (CATEGORY_LEVELS.includes(level)) {
                    this.trendCategoryLevel = level;
                    this.saveTrendCategoryPrefs();
                    await this.loadTrendCategories();
                }
            },

            /**
             * Shows or hides a category
             * @param {number} id - Category
             */
            toggleTrendCategory(id) {
                this.trendCategoryHidden = this.trendCategoryHidden.includes(id)
                    ? this.trendCategoryHidden.filter(hidden => hidden !== id)
                    : [...this.trendCategoryHidden, id];
                this.saveTrendCategoryPrefs();
            },

            /**
             * Shows every category of the chart
             */
            showAllTrendCategories() {
                const ids = this.trendCategories.categories.map(category => category.id);
                this.trendCategoryHidden = this.trendCategoryHidden.filter(id => !ids.includes(id));
                this.saveTrendCategoryPrefs();
            },

            /**
             * Hides every category of the chart
             */
            hideAllTrendCategories() {
                const ids = this.trendCategories.categories.map(category => category.id);
                this.trendCategoryHidden = [...new Set([...this.trendCategoryHidden, ...ids])];
                this.saveTrendCategoryPrefs();
            }
        }
    };
}
