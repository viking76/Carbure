/**
 * Trends Module
 *
 * Monthly trends of the household (incomes, expenses, off-budget, planned budget
 * and savings) over a period of up to 20 years, drawn as inline SVG charts, and the
 * custom charts of the household: the monthly series of a category or of an insight,
 * drawn as bars, a line or an area (kind chosen in the "By category" chart,
 * trendCategoriesModule.js).
 *
 * @module trendsModule
 */

/** Chart geometry (SVG user units) */
export const WIDTH = 960;
export const MARGIN = { top: 12, right: 12, bottom: 36, left: 68 };
const FLOWS_HEIGHT = 300;
const SAVINGS_HEIGHT = 200;
const RATE_HEIGHT = 200;
const SERIES_HEIGHT = 200;

/** Periods offered by the buttons, in months */
export const TREND_PERIODS = [3, 6, 12, 24, 36, 60];

/** Longest period and furthest end of a period, in months (as the API: 20 years) */
export const MAX_TREND_MONTHS = 240;

/** Most custom charts (as the API) */
export const MAX_TREND_CHARTS = 12;

/** Beyond this number of months, the savings rate line has no point markers */
export const MAX_RATE_MARKERS = 60;

/** Series of the flows chart, in the fixed categorical order (validated palette) */
const FLOW_SERIES = [
    { key: 'credit', color: '#2a78d6', label: 'trendsCredit' },
    { key: 'debit', color: '#eb6834', label: 'trendsDebit' },
    { key: 'offBudget', color: '#1baf7a', label: 'trendsOffBudget' }
];

/**
 * Totals and averages of a list of months
 * @param {Array} months - Months returned by the trends API
 * @returns {Object}
 */
function periodStats(months) {
    const sum = key => months.reduce((total, m) => total + Number(m[key] || 0), 0);
    const count = Math.max(1, months.length);
    const credit = sum('credit');
    const savings = sum('savings');
    return {
        credit,
        debit: sum('debit'),
        offBudget: sum('offBudget'),
        planned: sum('planned'),
        savings,
        averageSavings: savings / count,
        averageDebit: sum('debit') / count,
        averagePlanned: sum('planned') / count,
        savingsRate: credit > 0 ? Math.round(savings / credit * 100) : 0
    };
}

/**
 * Rounds a maximum up to a readable axis bound and returns the tick step
 * @param {number} max - Maximum value of the data
 * @param {number} ticks - Wanted number of intervals
 * @returns {{max: number, step: number}}
 */
export function niceScale(max, ticks = 4) {
    if (max <= 0) {
        return { max: ticks, step: 1 };
    }
    const raw = max / ticks;
    const magnitude = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].map(m => m * magnitude).find(s => s >= raw);
    return { max: step * ticks, step };
}

/**
 * SVG path of a bar with rounded data end (the end away from the baseline)
 * @param {number} x - Left
 * @param {number} y0 - Baseline
 * @param {number} y1 - Data end
 * @param {number} width - Bar width
 * @returns {string}
 */
function barPath(x, y0, y1, width) {
    const height = Math.abs(y1 - y0);
    if (height < 0.5) {
        return '';
    }
    const r = Math.min(3, width / 2, height);
    const up = y1 < y0;
    const end = up ? y1 + r : y1 - r;
    const corner = up ? -r : r;
    return `M${x},${y0} V${end} q0,${corner} ${r},${corner} H${x + width - r} q${r},0 ${r},${-corner} V${y0} Z`;
}

/**
 * Width of a bar in a slot of the chart: a share of the slot, never wider than the slot
 * even when the period has hundreds of months
 * @param {number} group - Width of the slot of a month
 * @param {number} ratio - Wanted share of the slot
 * @param {number} max - Widest bar
 * @returns {number}
 */
export function barWidthFor(group, ratio, max) {
    return Math.min(max, Math.max(Math.min(3, group * 0.8), group * ratio));
}

/**
 * Points of a line through the middle of the slots of the months; a single month gives a
 * flat line across its slot
 * @param {Array<number>} values - Value of each month
 * @param {number} group - Width of the slot of a month
 * @param {Function} y - Vertical position of a value
 * @returns {Array<{x: number, y: number}>}
 */
export function slotPoints(values, group, y) {
    const points = values.map((value, i) => ({ x: MARGIN.left + (i + 0.5) * group, y: y(value) }));
    return points.length === 1
        ? [{ x: MARGIN.left, y: points[0].y }, { x: MARGIN.left + group, y: points[0].y }]
        : points;
}

/**
 * Points attribute of an SVG polyline
 * @param {Array<{x: number, y: number}>} points - Points
 * @returns {string} "x,y x,y …"
 */
export function pointsAttribute(points) {
    return points.map(p => `${p.x},${p.y}`).join(' ');
}

/**
 * SVG path of the area between an upper and a lower line of the same months
 * @param {Array<{x: number, y: number}>} upper - Upper line, left to right
 * @param {Array<{x: number, y: number}>} lower - Lower line, left to right
 * @returns {string} Empty without points
 */
export function areaPath(upper, lower) {
    if (!upper.length) {
        return '';
    }
    const edge = points => points.map(p => `${p.x},${p.y}`).join(' L');
    return `M${edge(upper)} L${edge([...lower].reverse())} Z`;
}

/**
 * Index of a month: "2026-09" -> 2026 * 12 + 8
 * @param {string} value - Year and month (value of an <input type="month">)
 * @returns {number} NaN when it is not a month
 */
export function monthIndex(value) {
    const match = /^(\d{4})-(\d{2})$/.exec(value || '');
    const month = match ? Number(match[2]) : 0;
    return month >= 1 && month <= 12 ? Number(match[1]) * 12 + month - 1 : NaN;
}

/**
 * Month of an index: 2026 * 12 + 8 -> "2026-09"
 * @param {number} index - Index of monthIndex()
 * @returns {string}
 */
export function monthKey(index) {
    return `${Math.floor(index / 12)}-${String(index % 12 + 1).padStart(2, '0')}`;
}

/**
 * Months and offset of the API for a custom range of months
 * @param {string} from - First month (YYYY-MM)
 * @param {string} to - Last month (YYYY-MM), the current one at the latest
 * @param {Date} now - Today
 * @returns {{months: number, offset: number}|null} null when the range is invalid: a month
 *          missing, after the current one, the first after the last, or longer than 240 months
 */
export function rangeToPeriod(from, to, now = new Date()) {
    const start = monthIndex(from);
    const end = monthIndex(to);
    const current = now.getFullYear() * 12 + now.getMonth();
    if (Number.isNaN(start) || Number.isNaN(end) || start > end || end > current) {
        return null;
    }
    const months = end - start + 1;
    const offset = current - end;
    return months <= MAX_TREND_MONTHS && offset <= MAX_TREND_MONTHS ? { months, offset } : null;
}

/**
 * Range of months of a period of the API
 * @param {number} months - Number of months
 * @param {number} offset - Months between the current month and the last month of the period
 * @param {Date} now - Today
 * @returns {{from: string, to: string}} First and last months (YYYY-MM)
 */
export function periodToRange(months, offset, now = new Date()) {
    const end = now.getFullYear() * 12 + now.getMonth() - offset;
    return { from: monthKey(end - months + 1), to: monthKey(end) };
}

/**
 * Months between two labels of the x axis: at most 12 labels, one per year or more
 * beyond 6 years
 * @param {number} count - Number of months of the chart
 * @returns {number}
 */
export function labelStep(count) {
    if (count <= 12) {
        return count > 6 ? 2 : 1;
    }
    return [3, 6, 12, 24, 60, 120].find(step => Math.ceil(count / step) <= 12) || MAX_TREND_MONTHS;
}

/**
 * Key of a custom chart, unique in the list
 * @param {{type: string, id: number}} chart - Chart
 * @returns {string}
 */
export function trendChartKey(chart) {
    return `${chart.type}-${chart.id}`;
}

/**
 * Creates a trends module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with trends data, computed charts and methods
 */
export function createTrendsModule(getApiService) {
    return {
        data() {
            return {
                trends: [],
                trendsMonths: 12,
                // Months between the current month and the last month of the period
                trendsOffset: 0,
                // Custom range chosen with the month fields (else a period button)
                trendsCustom: false,
                trendsFrom: '',
                trendsTo: '',
                // 'none', 'previous' (the months just before) or 'lastYear' (same months a year before)
                trendsCompare: 'none',
                compareTrends: [],
                loadingTrends: false,
                trendsHover: null,
                // Chart showing its tooltip: 'flows' or 'rate'
                trendsHoverChart: 'flows',
                showTrendsTable: false,
                // Custom charts: {type, id, name, icon, color, key, series, loading}
                trendCharts: [],
                // Month under the pointer in a custom chart: {key, index}
                trendChartHover: null,
                // Last request of the series: the answers of an older period are ignored
                trendSeriesRequest: 0,
                // Modal adding a chart (administrators): {type, category, insight}
                trendChartForm: null,
                // Insights offered by the modal
                trendChartInsights: [],
                savingTrendCharts: false
            };
        },

        computed: {
            /**
             * Legend of the flows chart
             * @returns {Array}
             */
            trendsSeries() {
                return FLOW_SERIES.map(s => ({ ...s, label: this.t(s.label) }));
            },

            /**
             * Totals and averages over the period
             * @returns {Object}
             */
            trendsStats() {
                return periodStats(this.trends);
            },

            /**
             * Totals of the comparison period (null without comparison)
             * @returns {Object|null}
             */
            compareStats() {
                return this.trendsCompare !== 'none' && this.compareTrends.length ? periodStats(this.compareTrends) : null;
            },

            /**
             * Label of a period: "nov. 25 – oct. 26"
             * @returns {{current: string, compare: string}}
             */
            trendsPeriodLabels() {
                const label = list => list.length ? `${this.formatMonth(list[0].month)} – ${this.formatMonth(list[list.length - 1].month)}` : '';
                return { current: label(this.trends), compare: label(this.compareTrends) };
            },

            /**
             * Comparison of the period totals, one row per measure
             * @returns {Array<{key, label, current, compare, currentWidth, compareWidth}>}
             */
            trendsComparison() {
                if (!this.compareStats) {
                    return [];
                }
                const rows = [
                    { key: 'credit', label: this.t('trendsCredit') },
                    { key: 'debit', label: this.t('trendsDebit') },
                    { key: 'offBudget', label: this.t('trendsOffBudget') },
                    { key: 'planned', label: this.t('trendsPlanned') },
                    { key: 'savings', label: this.t('trendsSavings') }
                ].map(row => ({ ...row, current: this.trendsStats[row.key], compare: this.compareStats[row.key] }));
                const max = Math.max(1, ...rows.flatMap(r => [Math.abs(r.current), Math.abs(r.compare)]));
                return rows.map(r => ({
                    ...r,
                    currentWidth: Math.abs(r.current) / max * 100,
                    compareWidth: Math.abs(r.compare) / max * 100
                }));
            },

            /**
             * Geometry of the flows chart (bars + planned budget line)
             * @returns {Object}
             */
            flowsChart() {
                const plotWidth = WIDTH - MARGIN.left - MARGIN.right;
                const plotHeight = FLOWS_HEIGHT - MARGIN.top - MARGIN.bottom;
                const n = Math.max(1, this.trends.length);
                const max = Math.max(0, ...this.trends.flatMap(m =>
                    [m.credit, m.debit, Math.abs(m.offBudget), m.planned].map(Number)));
                const scale = niceScale(max);
                const y = value => MARGIN.top + plotHeight - (value / scale.max) * plotHeight;
                const group = plotWidth / n;
                // Thinner gaps and bars when the period has many months
                const gap = Math.min(2, group * 0.05);
                const barWidth = Math.min(28, Math.max(Math.min(2, group * 0.26), (group * 0.78 - gap * 2) / 3));

                const bars = [];
                this.trends.forEach((m, i) => {
                    const left = MARGIN.left + i * group + (group - (barWidth * 3 + gap * 2)) / 2;
                    FLOW_SERIES.forEach((s, j) => {
                        const value = Math.abs(Number(m[s.key]) || 0);
                        bars.push({
                            key: `${m.month}-${s.key}`,
                            color: s.color,
                            d: barPath(left + j * (barWidth + gap), y(0), y(value), barWidth)
                        });
                    });
                });

                const planned = this.trends
                    .map((m, i) => `${MARGIN.left + (i + 0.5) * group},${y(Number(m.planned) || 0)}`)
                    .join(' ');

                return {
                    width: WIDTH,
                    height: FLOWS_HEIGHT,
                    bars,
                    planned,
                    ticks: this.axisTicks(scale, y),
                    labels: this.monthLabels(group),
                    columns: this.hoverColumns(group, MARGIN.top, plotHeight)
                };
            },

            /**
             * Geometry of the savings chart (diverging bars around zero)
             * @returns {Object}
             */
            savingsChart() {
                const plotWidth = WIDTH - MARGIN.left - MARGIN.right;
                const plotHeight = SAVINGS_HEIGHT - MARGIN.top - MARGIN.bottom;
                const n = Math.max(1, this.trends.length);
                // The scale only covers the signs present in the data (no empty half)
                const values = this.trends.map(m => Number(m.savings) || 0);
                const step = niceScale(Math.max(1, ...values.map(Math.abs)), 2).step;
                const top = Math.ceil(Math.max(0, ...values) / step) * step;
                const bottom = Math.floor(Math.min(0, ...values) / step) * step;
                const range = (top - bottom) || step;
                const y = value => MARGIN.top + ((top - value) / range) * plotHeight;
                const group = plotWidth / n;
                const barWidth = barWidthFor(group, 0.5, 48);

                const bars = this.trends.map((m, i) => {
                    const value = Number(m.savings) || 0;
                    return {
                        key: m.month,
                        positive: value >= 0,
                        d: barPath(MARGIN.left + i * group + (group - barWidth) / 2, y(0), y(value), barWidth)
                    };
                });

                const ticks = [];
                for (let value = bottom; value <= top + 1e-9; value += step) {
                    ticks.push({ value, y: y(value), label: this.formatCompact(value) });
                }

                return {
                    width: WIDTH,
                    height: SAVINGS_HEIGHT,
                    bars,
                    zero: y(0),
                    ticks,
                    labels: this.monthLabels(group, SAVINGS_HEIGHT),
                    columns: this.hoverColumns(group, MARGIN.top, plotHeight)
                };
            },

            /**
             * Geometry of the monthly savings rate chart (line, in %)
             * @returns {Object}
             */
            savingsRateChart() {
                const plotWidth = WIDTH - MARGIN.left - MARGIN.right;
                const plotHeight = RATE_HEIGHT - MARGIN.top - MARGIN.bottom;
                const n = Math.max(1, this.trends.length);
                const group = plotWidth / n;
                const rates = this.trends.map(m => this.monthSavingsRate(m));
                const known = rates.filter(r => r !== null);
                const step = niceScale(Math.max(10, ...known.map(Math.abs)), 2).step;
                const top = Math.max(step, Math.ceil(Math.max(0, ...known) / step) * step);
                const bottom = Math.floor(Math.min(0, ...known) / step) * step;
                const y = value => MARGIN.top + ((top - value) / (top - bottom)) * plotHeight;

                const points = rates
                    .map((rate, i) => rate === null ? null : { key: this.trends[i].month, x: MARGIN.left + (i + 0.5) * group, y: y(rate), rate })
                    .filter(Boolean);
                const ticks = [];
                for (let value = bottom; value <= top + 1e-9; value += step) {
                    ticks.push({ value, y: y(value), label: `${value} %` });
                }

                return {
                    width: WIDTH,
                    height: RATE_HEIGHT,
                    points,
                    // Point markers only while they do not cover the line
                    markers: points.length <= MAX_RATE_MARKERS ? points : [],
                    line: points.map(p => `${p.x},${p.y}`).join(' '),
                    average: y(this.trendsStats.savingsRate),
                    zero: y(0),
                    ticks,
                    labels: this.monthLabels(group, RATE_HEIGHT),
                    columns: this.hoverColumns(group, MARGIN.top, plotHeight)
                };
            },

            /**
             * Month under the pointer, for the tooltip
             * @returns {Object|null}
             */
            trendsHoverMonth() {
                return this.trendsHover === null ? null : this.trends[this.trendsHover];
            },

            /**
             * Horizontal position of the tooltip, in % of the chart width
             * @returns {number}
             */
            trendsHoverLeft() {
                const group = (WIDTH - MARGIN.left - MARGIN.right) / Math.max(1, this.trends.length);
                const x = MARGIN.left + (this.trendsHover + 0.5) * group;
                return Math.min(85, Math.max(15, x / WIDTH * 100));
            },

            /**
             * Custom charts with the geometry of their series
             * @returns {Array<Object>} The charts, each with its geometry (seriesChart())
             */
            trendChartViews() {
                return this.trendCharts.map(chart => ({ ...chart, geometry: this.seriesChart(chart.series || [], this.trendsChartKind) }));
            }
        },

        methods: {
            /**
             * Loads the trends of the selected period
             * @returns {Promise<void>}
             */
            async loadTrends() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }

                this.loadingTrends = true;
                try {
                    const offset = { previous: this.trendsOffset + this.trendsMonths, lastYear: this.trendsOffset + 12 }[this.trendsCompare];
                    [[this.trends, this.compareTrends]] = await Promise.all([
                        Promise.all([
                            apiService.fetchTrends(this.trendsMonths, this.trendsOffset),
                            offset ? apiService.fetchTrends(this.trendsMonths, offset) : Promise.resolve([])
                        ]),
                        this.loadTrendCharts(),
                        this.loadTrendCategories()
                    ]);
                } catch (error) {
                    this.error = this.apiErrorText(error);
                    this.trends = [];
                } finally {
                    this.loadingTrends = false;
                }
            },

            /**
             * Changes the period and reloads
             * @param {number} months - Number of months
             */
            setTrendsMonths(months) {
                this.trendsCustom = false;
                this.trendsOffset = 0;
                this.trendsMonths = months;
                this.loadTrends();
            },

            /**
             * Shows the month fields of a custom range, filled with the current period
             */
            openTrendsRange() {
                const range = periodToRange(this.trendsMonths, this.trendsOffset);
                this.trendsFrom = range.from;
                this.trendsTo = range.to;
                this.trendsCustom = true;
            },

            /**
             * Applies the custom range of the month fields and reloads
             * @returns {Promise<void>}
             */
            async applyTrendsRange() {
                const period = rangeToPeriod(this.trendsFrom, this.trendsTo);
                if (!period) {
                    this.showToast(this.t('trendsRangeInvalid', { months: MAX_TREND_MONTHS }));
                    return;
                }
                this.trendsMonths = period.months;
                this.trendsOffset = period.offset;
                await this.loadTrends();
            },

            /**
             * Bounds of the month fields: from 240 months ago to the current month
             * @returns {{min: string, max: string}}
             */
            trendsRangeBounds() {
                return { min: periodToRange(1, MAX_TREND_MONTHS).to, max: periodToRange(1, 0).to };
            },

            /**
             * Savings rate of a month in % (null without income)
             * @param {Object} month - Month of the trends
             * @returns {number|null}
             */
            monthSavingsRate(month) {
                const credit = Number(month.credit) || 0;
                return credit > 0 ? Math.round((Number(month.savings) || 0) / credit * 100) : null;
            },

            /**
             * Changes the comparison period and reloads
             * @param {string} compare - 'none', 'previous' or 'lastYear'
             */
            setTrendsCompare(compare) {
                this.trendsCompare = compare;
                this.loadTrends();
            },

            /**
             * Variation against the comparison period, for a key figure
             * @param {string} key - Key of the statistic
             * @param {boolean} higherIsBetter - False for expenses
             * @returns {{text: string, good: boolean, icon: string}|null}
             */
            trendsDelta(key, higherIsBetter = true) {
                if (!this.compareStats) {
                    return null;
                }
                const current = this.trendsStats[key];
                const previous = this.compareStats[key];
                if (!previous) {
                    return null;
                }
                const percent = Math.round((current - previous) / Math.abs(previous) * 100);
                return {
                    text: `${percent > 0 ? '+' : ''}${percent} %`,
                    good: percent === 0 || (percent > 0) === higherIsBetter,
                    icon: percent > 0 ? 'arrow_upward' : percent < 0 ? 'arrow_downward' : 'remove'
                };
            },

            /**
             * Y axis ticks
             */
            axisTicks(scale, y) {
                const ticks = [];
                for (let value = 0; value <= scale.max + 1e-9; value += scale.step) {
                    ticks.push({ value, y: y(value), label: this.formatCompact(value) });
                }
                return ticks;
            },

            /**
             * X axis labels: at most 12, the last month always labelled; one per year or
             * more (the year, on January) for long periods
             * @param {number} group - Width of the slot of a month
             * @param {number} height - Height of the chart
             * @param {Array<{month: string}>} months - Months of the chart (the trends by default)
             * @returns {Array<{key, x, y, text}>}
             */
            monthLabels(group, height = FLOWS_HEIGHT, months = this.trends) {
                const step = labelStep(months.length);
                const last = months.length - 1;
                const years = step / 12;
                const lastYear = last >= 0 ? Number(months[last].month.slice(0, 4)) : 0;
                const shown = years >= 1
                    ? ({ month }) => month.endsWith('-01') && (lastYear - Number(month.slice(0, 4))) % years === 0
                    : ({ i }) => (last - i) % step === 0;
                return months
                    .map((m, i) => ({ month: m.month, i }))
                    .filter(shown)
                    .map(({ month, i }) => ({
                        key: month,
                        x: MARGIN.left + (i + 0.5) * group,
                        y: height - 12,
                        text: years >= 1 ? month.slice(0, 4) : this.formatMonth(month)
                    }));
            },

            /**
             * Invisible hover targets, one per month (bigger than the marks)
             * @param {number} group - Width of the slot of a month
             * @param {number} top - Top of the plot
             * @param {number} height - Height of the plot
             * @param {Array<{month: string}>} months - Months of the chart (the trends by default)
             * @returns {Array<{key, index, x, y, width, height}>}
             */
            hoverColumns(group, top, height, months = this.trends) {
                return months.map((m, i) => ({
                    key: m.month,
                    index: i,
                    x: MARGIN.left + i * group,
                    y: top,
                    width: group,
                    height
                }));
            },

            /**
             * Geometry of a custom chart: the amount of each month (bars around zero, a
             * line or an area down to zero), average line and dashed planned budget
             * (segments of the months that have one)
             * @param {Array<{month: string, amount: number, planned: number|null}>} series - Months of the series
             * @param {string} kind - bars (default), lines or areas
             * @returns {Object}
             */
            seriesChart(series, kind = 'bars') {
                const plotWidth = WIDTH - MARGIN.left - MARGIN.right;
                const plotHeight = SERIES_HEIGHT - MARGIN.top - MARGIN.bottom;
                const group = plotWidth / Math.max(1, series.length);
                const values = series.map(m => Number(m.amount) || 0);
                const planned = series.map(m => m.planned === null || m.planned === undefined ? null : Number(m.planned));
                const all = [...values, ...planned.filter(value => value !== null)];
                const step = niceScale(Math.max(1, ...all.map(Math.abs)), 2).step;
                const bottom = Math.floor(Math.min(0, ...all) / step) * step;
                const top = Math.max(bottom + step, Math.ceil(Math.max(0, ...all) / step) * step);
                const y = value => MARGIN.top + ((top - value) / (top - bottom)) * plotHeight;
                const barWidth = barWidthFor(group, 0.6, 48);
                const average = values.reduce((sum, value) => sum + value, 0) / Math.max(1, values.length);
                const line = kind === 'bars' ? [] : slotPoints(values, group, y);

                const ticks = [];
                for (let value = bottom; value <= top + 1e-9; value += step) {
                    ticks.push({ value, y: y(value), label: this.formatCompact(value) });
                }
                return {
                    width: WIDTH,
                    height: SERIES_HEIGHT,
                    kind,
                    bars: kind !== 'bars' ? [] : series.map((m, i) => ({
                        key: m.month,
                        positive: values[i] >= 0,
                        d: barPath(MARGIN.left + i * group + (group - barWidth) / 2, y(0), y(values[i]), barWidth)
                    })),
                    line: pointsAttribute(line),
                    // Point markers of a line while they do not cover it
                    markers: kind === 'lines' && series.length > 1 && series.length <= MAX_RATE_MARKERS
                        ? line.map((p, i) => ({ ...p, key: series[i].month })) : [],
                    area: kind === 'areas' ? areaPath(line, line.map(p => ({ x: p.x, y: y(0) }))) : '',
                    planned: this.plannedSegments(planned, group, y),
                    hasPlanned: planned.some(value => value !== null),
                    average,
                    averageY: y(average),
                    zero: y(0),
                    ticks,
                    labels: this.monthLabels(group, SERIES_HEIGHT, series),
                    columns: this.hoverColumns(group, MARGIN.top, plotHeight, series)
                };
            },

            /**
             * Polylines of a planned budget: one per run of months that have a budget
             * @param {Array<number|null>} planned - Budget of each month
             * @param {number} group - Width of the slot of a month
             * @param {Function} y - Vertical position of an amount
             * @returns {Array<string>} Points of each polyline
             */
            plannedSegments(planned, group, y) {
                const segments = [];
                let current = [];
                planned.forEach((value, i) => {
                    if (value === null) {
                        current = [];
                        return;
                    }
                    if (!current.length) {
                        segments.push(current);
                    }
                    current.push({ x: MARGIN.left + (i + 0.5) * group, y: y(value) });
                });
                // A single month: a short flat line over its slot
                return segments.map(points => (points.length > 1 ? points
                    : [{ x: points[0].x - group / 2, y: points[0].y }, { x: points[0].x + group / 2, y: points[0].y }])
                    .map(p => `${p.x},${p.y}`).join(' '));
            },

            /**
             * Month under the pointer of a custom chart, for its tooltip
             * @param {Object} chart - Chart of trendChartViews
             * @returns {Object|null} The month of the series
             */
            trendChartHoverMonth(chart) {
                const hover = this.trendChartHover;
                return hover && hover.key === chart.key ? (chart.series || [])[hover.index] || null : null;
            },

            /**
             * Horizontal position of the tooltip of a custom chart, in % of the chart width
             * @param {Object} chart - Chart of trendChartViews
             * @returns {number}
             */
            trendChartHoverLeft(chart) {
                const group = (WIDTH - MARGIN.left - MARGIN.right) / Math.max(1, (chart.series || []).length);
                const x = MARGIN.left + ((this.trendChartHover?.index || 0) + 0.5) * group;
                return Math.min(85, Math.max(15, x / WIDTH * 100));
            },

            /**
             * Title of a custom chart: name, icon and colors of its category or insight
             * @param {Object} chart - Chart: {type, name, icon, color}
             * @returns {{name: string, icon: string, style: Object}}
             */
            trendChartTitle(chart) {
                if (chart.type === 'category') {
                    return { name: chart.name, icon: this.categoryIcon(chart), style: this.categoryBadgeStyle(chart) };
                }
                return {
                    name: this.getInsightName(chart.name),
                    icon: chart.icon || this.getInsightIcon(chart.name),
                    style: this.badgeStyleFor(this.insightColorValues[chart.color] || 'var(--primary-color)')
                };
            },

            /**
             * Loads the custom charts and their series over the selected period (the series
             * already shown are kept until the new ones arrive)
             * @returns {Promise<void>}
             */
            async loadTrendCharts() {
                try {
                    this.setTrendCharts(await getApiService().fetchTrendCharts());
                    await this.loadTrendSeries(this.trendCharts);
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Replaces the list of custom charts, keeping the series of the charts still there
             * @param {Array<{type, id, name, icon, color}>} charts - Charts of the API
             * @returns {Array<Object>} The charts without series yet
             */
            setTrendCharts(charts) {
                const previous = new Map(this.trendCharts.map(chart => [chart.key, chart]));
                this.trendCharts = charts.map(chart => {
                    const key = trendChartKey(chart);
                    const old = previous.get(key);
                    // The same object: a series still loading for it is kept
                    return old ? Object.assign(old, chart) : { ...chart, key, series: [], loading: true };
                });
                return this.trendCharts.filter(chart => !previous.has(chart.key));
            },

            /**
             * Loads the series of custom charts over the selected period; the answers of a
             * previous period are ignored, a failing series shows its error
             * @param {Array<Object>} charts - Charts of trendCharts
             * @returns {Promise<void>}
             */
            async loadTrendSeries(charts) {
                const [months, offset] = [this.trendsMonths, this.trendsOffset];
                await Promise.all(charts.map(async chart => {
                    const request = ++this.trendSeriesRequest;
                    chart.request = request;
                    chart.loading = true;
                    try {
                        const series = await getApiService().fetchTrendSeries(chart.type, chart.id, months, offset);
                        if (chart.request === request) {
                            chart.series = series;
                        }
                    } catch (error) {
                        this.showToast(error.message);
                    } finally {
                        chart.loading = false;
                    }
                }));
            },

            /**
             * Saves the list of custom charts (administrators), then loads the series of the new ones
             * @param {Array<{type: string, id: number}>} charts - Charts in display order
             * @returns {Promise<boolean>} Whether it was saved
             */
            async saveTrendCharts(charts) {
                this.savingTrendCharts = true;
                try {
                    const added = this.setTrendCharts(await getApiService().saveTrendCharts(charts.map(c => ({ type: c.type, id: Number(c.id) }))));
                    if (added.length) {
                        await this.loadTrendSeries(added);
                    }
                    return true;
                } catch (error) {
                    this.showToast(error.message);
                    return false;
                } finally {
                    this.savingTrendCharts = false;
                }
            },

            /**
             * Opens the modal adding a custom chart, with the categories and the insights to choose from
             * @returns {Promise<void>}
             */
            async openTrendChartModal() {
                this.trendChartForm = { type: 'category', category: '', insight: '' };
                try {
                    const [, insights] = await Promise.all([this.ensureCategories(), getApiService().fetchInsightDefinitions()]);
                    this.trendChartInsights = insights;
                } catch (error) {
                    this.showToast(error.message);
                }
            },

            /**
             * Closes the modal adding a custom chart
             */
            closeTrendChartModal() {
                this.trendChartForm = null;
            },

            /**
             * Adds the chart of the modal at the end of the list
             * @returns {Promise<void>}
             */
            async submitTrendChart() {
                const form = this.trendChartForm;
                const id = Number(form.type === 'category' ? form.category : form.insight);
                if (!id) {
                    this.showToast(this.t(form.type === 'category' ? 'trendChartChooseCategory' : 'trendChartChooseInsight'));
                    return;
                }
                const chart = { type: form.type, id };
                if (this.trendCharts.some(c => c.key === trendChartKey(chart))) {
                    this.showToast(this.t('trendChartExists'));
                    return;
                }
                if (this.trendCharts.length >= MAX_TREND_CHARTS) {
                    this.showToast(this.t('trendChartMax', { count: MAX_TREND_CHARTS }));
                    return;
                }
                if (await this.saveTrendCharts([...this.trendCharts, chart])) {
                    this.trendChartForm = null;
                    this.showToast(this.t('trendChartAdded'));
                }
            },

            /**
             * Removes a custom chart from the list
             * @param {Object} chart - Chart of trendCharts
             * @returns {Promise<void>}
             */
            async removeTrendChart(chart) {
                if (await this.saveTrendCharts(this.trendCharts.filter(c => c.key !== chart.key))) {
                    this.showToast(this.t('trendChartRemoved'));
                }
            },

            /**
             * Moves a custom chart up or down the list
             * @param {Object} chart - Chart of trendCharts
             * @param {number} delta - -1 (up) or 1 (down)
             * @returns {Promise<void>}
             */
            async moveTrendChart(chart, delta) {
                const charts = [...this.trendCharts];
                const from = charts.findIndex(c => c.key === chart.key);
                const to = from + delta;
                if (from < 0 || to < 0 || to >= charts.length) {
                    return;
                }
                [charts[from], charts[to]] = [charts[to], charts[from]];
                await this.saveTrendCharts(charts);
            },

            /**
             * "2026-09" -> "sept. 26"
             * @param {string} month - Year and month
             * @returns {string}
             */
            formatMonth(month) {
                const [year, m] = month.split('-').map(Number);
                return new Intl.DateTimeFormat(this.intlLocale, { month: 'short', year: '2-digit' })
                    .format(new Date(year, m - 1, 1));
            },

            /**
             * Compact amount for the axes: 1 500 -> "1,5 k"
             * @param {number} value - Amount
             * @returns {string}
             */
            formatCompact(value) {
                return new Intl.NumberFormat(this.intlLocale, { notation: 'compact', maximumFractionDigits: 1 }).format(value);
            }
        }
    };
}
