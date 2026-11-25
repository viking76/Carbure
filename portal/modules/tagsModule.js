/**
 * Tags Module
 *
 * Tags of a project (a trip, works...) limited in time: a second axis, apart from the
 * categories. A transaction keeps its category and gets tags by hand, from its detail
 * or on a selection of the list. A tag is active until its end date, then archived:
 * no more offered, its summary (net cost, by category, day by day) still readable.
 *
 * @module tagsModule
 */

import { isoDay } from './manualModule.js';

/** Most transactions tagged at once (Tag::MAX_SELECTION) */
export const MAX_TAG_SELECTION = 500;

/**
 * Name of a tag as stored: without its #, in lower case, without spaces around
 * @param {string} input - Name typed
 * @returns {string}
 */
export function tagName(input) {
    return String(input ?? '').trim().replace(/^#+/, '').toLowerCase();
}

/**
 * Whether a tag is still offered: its end date is not past
 * @param {{end_date: string|null}} tag - Tag
 * @param {string} today - Today (YYYY-MM-DD)
 * @returns {'active'|'archived'}
 */
export function tagStatus(tag, today) {
    return !tag.end_date || tag.end_date >= today ? 'active' : 'archived';
}

/**
 * Share of the period of a tag already gone, for the bar of its card: none without an end
 * date or once archived
 * @param {{start_date: string, end_date: string|null, active: boolean}} tag - Tag
 * @param {string} today - Today (YYYY-MM-DD)
 * @returns {number|null} Percent, 0 to 100
 */
export function tagProgress(tag, today) {
    if (!tag.end_date || !tag.active) {
        return null;
    }
    const day = 86400000;
    const length = Math.round((dayTime(tag.end_date) - dayTime(tag.start_date)) / day) + 1;
    const gone = Math.round((dayTime(today) - dayTime(tag.start_date)) / day) + 1;
    return Math.min(100, Math.max(0, Math.round(gone / Math.max(1, length) * 100)));
}

/**
 * Tags to add and to remove to go from a list of tags to another one
 * @param {Array<number>} before - Ids of the tags before
 * @param {Array<number>} after - Ids of the tags wanted
 * @returns {{add: Array<number>, remove: Array<number>}}
 */
export function tagChanges(before, after) {
    const was = new Set((before || []).map(Number));
    const wanted = new Set((after || []).map(Number));
    return {
        add: [...wanted].filter(id => !was.has(id)),
        remove: [...was].filter(id => !wanted.has(id))
    };
}

/** Sum of amounts, computed in cents (no floating error on a long list) */
const sumCents = amounts => amounts.reduce((sum, a) => sum + Math.round(Number(a) * 100), 0) / 100;

/** Time of a day (YYYY-MM-DD) at local midnight */
const dayTime = day => new Date(`${day}T00:00:00`).getTime();

/** Rounds a coordinate to a tenth of a pixel */
const px = value => Math.round(value * 10) / 10;

/**
 * Readable bounds and ticks of a vertical axis: steps of 1, 2, 2.5 or 5 × 10ⁿ, zero always
 * a tick (the bounds include it)
 * @param {number} min - Lowest value
 * @param {number} max - Highest value
 * @param {number} count - Wanted number of intervals
 * @returns {{min: number, max: number, step: number, values: Array<number>}}
 */
export function niceTicks(min, max, count = 4) {
    let low = Math.min(0, min);
    let high = Math.max(0, max);
    if (low === high) {
        high = low + 1;
    }
    const raw = (high - low) / count;
    const magnitude = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].map(m => m * magnitude).find(s => s >= raw - 1e-9);
    low = Math.floor(low / step + 1e-9) * step || 0;
    high = Math.ceil(high / step - 1e-9) * step || 0;
    const values = [];
    for (let i = 0; low + i * step <= high + step / 1e6; i++) {
        values.push(Number((low + i * step).toFixed(10)) || 0);
    }
    return { min: low, max: high, step, values };
}

/**
 * Labels of the time axis: at most one every `gap` pixels, the first and the last day kept
 * @param {Array<{x: number, date: string}>} points - Points, from left to right
 * @param {number} gap - Least space between two labels
 * @returns {Array<{x: number, date: string, anchor: string}>}
 */
export function dateLabels(points, gap = 96) {
    if (points.length === 0) {
        return [];
    }
    if (points.length === 1) {
        return [{ x: points[0].x, date: points[0].date, anchor: 'middle' }];
    }
    const last = points[points.length - 1];
    const kept = [points[0]];
    for (const point of points.slice(1, -1)) {
        if (point.x - kept[kept.length - 1].x >= gap && last.x - point.x >= gap) {
            kept.push(point);
        }
    }
    // The first day is on the left of the plot and the last one on its right
    kept.push(last);
    return kept.map((p, i) => ({ x: p.x, date: p.date, anchor: i === 0 ? 'start' : p === last ? 'end' : 'middle' }));
}

/**
 * Running total of a tag drawn as steps (the total holds until the next day with a
 * transaction): the first day on the left of the plot, the last one on its right, zero in
 * the vertical scale. The line starts from zero, so the first day reads as a jump.
 * @param {Array<{date: string, total: number, cumulative: number}>} days - Days, oldest first
 * @param {number} width - Width of the chart, in pixels
 * @param {number} height - Height of the chart, in pixels
 * @param {{top: number, right: number, bottom: number, left: number}} pad - Room of the axes
 * @returns {{plot: Object, points: Array<Object>, line: string, area: string, zero: number, extreme: number, ticks: Array<{value: number, y: number}>, labels: Array<Object>}}
 */
export function cumulativeChart(days, width, height, pad = { top: 12, right: 12, bottom: 28, left: 56 }) {
    const plot = { left: pad.left, right: Math.max(pad.left + 1, width - pad.right), top: pad.top, bottom: Math.max(pad.top + 1, height - pad.bottom) };
    if (!days || days.length === 0) {
        return { plot, points: [], line: '', area: '', zero: plot.bottom, extreme: plot.top, ticks: [], labels: [] };
    }
    const values = days.map(d => Number(d.cumulative));
    const scale = niceTicks(Math.min(...values), Math.max(...values));
    const y = value => px(plot.bottom - ((value - scale.min) / (scale.max - scale.min)) * (plot.bottom - plot.top));
    const first = dayTime(days[0].date);
    const range = dayTime(days[days.length - 1].date) - first;
    const points = days.map(d => ({
        x: range ? px(plot.left + ((dayTime(d.date) - first) / range) * (plot.right - plot.left)) : px((plot.left + plot.right) / 2),
        y: y(Number(d.cumulative)),
        date: d.date,
        total: Number(d.total),
        cumulative: Number(d.cumulative)
    }));
    const zero = y(0);
    const last = points[points.length - 1];
    let line = `M${plot.left},${zero} H${points[0].x} V${points[0].y}`;
    for (const p of points.slice(1)) {
        line += ` H${p.x} V${p.y}`;
    }
    if (last.x < plot.right) {
        line += ` H${plot.right}`;
    }
    const far = values.reduce((a, b) => (Math.abs(b) > Math.abs(a) ? b : a), 0);
    return {
        plot,
        points,
        line,
        area: `${line} V${zero} Z`,
        zero,
        extreme: y(far),
        ticks: scale.values.map(value => ({ value, y: y(value) })),
        labels: dateLabels(points)
    };
}

/**
 * Index of the point nearest to a horizontal position (hover of the chart)
 * @param {Array<{x: number}>} points - Points
 * @param {number} x - Position
 * @returns {number|null}
 */
export function nearestPoint(points, x) {
    if (!points || points.length === 0) {
        return null;
    }
    let best = 0;
    points.forEach((p, i) => {
        if (Math.abs(p.x - x) < Math.abs(points[best].x - x)) {
            best = i;
        }
    });
    return best;
}

/**
 * Figures of a tag summary: expenses and refunds apart, the largest expense, the length of
 * the period (until today while it runs) and the average cost per day
 * @param {{tag: Object, total: number, transactions: Array<{amount: number}>}} summary - Summary
 * @param {string} today - Today (YYYY-MM-DD)
 * @returns {{expenses: number, refunds: number, largest: Object|null, days: number, perDay: number|null}}
 */
export function tagFigures(summary, today) {
    const transactions = summary?.transactions || [];
    const amounts = transactions.map(t => Number(t.amount));
    const largest = transactions.reduce((min, t) => (Number(t.amount) < 0 && (!min || Number(t.amount) < Number(min.amount)) ? t : min), null);
    const tag = summary?.tag || {};
    const end = tag.end_date && tag.end_date < today ? tag.end_date : today;
    const days = tag.start_date ? Math.max(0, Math.round((dayTime(end) - dayTime(tag.start_date)) / 86400000) + 1) : 0;
    return {
        expenses: sumCents(amounts.filter(a => a < 0)),
        refunds: sumCents(amounts.filter(a => a > 0)),
        largest,
        days,
        perDay: days ? Math.round((Number(summary?.total) || 0) / days * 100) / 100 : null
    };
}

/**
 * Categories of a tag, largest amount first, with their share of the amounts moved and the
 * length of their bar (the largest one is full)
 * @param {Array<{category: number, total: number, count: number}>} categories - By category
 * @returns {Array<{category: number, total: number, count: number, share: number, width: number}>}
 */
export function categoryShares(categories) {
    const list = (categories || []).map(c => ({ ...c, total: Number(c.total), count: Number(c.count) }));
    const sum = list.reduce((s, c) => s + Math.abs(c.total), 0);
    const max = Math.max(0, ...list.map(c => Math.abs(c.total)));
    return list
        .sort((a, b) => Math.abs(b.total) - Math.abs(a.total))
        .map(c => ({
            ...c,
            share: sum ? Math.round(Math.abs(c.total) / sum * 100) : 0,
            width: max ? Math.max(2, Math.round(Math.abs(c.total) / max * 1000) / 10) : 0
        }));
}

/** Height of the chart of a tag summary, in pixels (its width follows its card) */
export const TAG_CHART_HEIGHT = 220;

/** Form of a tag, empty */
const EMPTY_TAG = { id: null, name: '', color: 'blue', start_date: '', end_date: '' };

/**
 * Creates the tags module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin
 */
export function createTagsModule(getApiService) {
    return {
        data() {
            return {
                // Every tag: [{id, name, color, start_date, end_date, active, count, total}]
                tags: [],
                loadingTags: false,
                // Tag being created or changed (modal)
                showTagModal: false,
                tagForm: { ...EMPTY_TAG },
                savingTag: false,
                // Summary of the tag opened in the Tags tab
                tagSummary: null,
                // Width of its chart (measured on its card) and the day pointed in it
                tagChartWidth: 640,
                tagChartHeight: TAG_CHART_HEIGHT,
                tagChartActive: null,
                // Selection of transactions of the list, to tag, categorize or delete them at once
                selectingTransactions: false,
                selectedTransactionIds: [],
                // Tag chosen in the selection bar, applied by its button
                selectionTag: ''
            };
        },

        computed: {
            /**
             * Tags still offered (their end is not past), by name
             * @returns {Array<Object>}
             */
            activeTags() {
                return this.tags.filter(t => t.active).sort((a, b) => a.name.localeCompare(b.name));
            },

            /**
             * Tags archived, most recent first
             * @returns {Array<Object>}
             */
            archivedTags() {
                return this.tags.filter(t => !t.active);
            },

            /**
             * Tags by id
             * @returns {Map<number, Object>}
             */
            tagsById() {
                return new Map(this.tags.map(t => [Number(t.id), t]));
            },

            /**
             * Running total of the tag opened, drawn at the width of its card
             * @returns {Object}
             */
            tagSummaryChart() {
                return cumulativeChart(this.tagSummary?.days || [], this.tagChartWidth, this.tagChartHeight);
            },

            /**
             * Day pointed in the chart of the tag opened (hover or arrow keys)
             * @returns {Object|null}
             */
            tagChartPoint() {
                return this.tagChartActive === null ? null : this.tagSummaryChart.points[this.tagChartActive] || null;
            },

            /**
             * Figures of the tag opened: expenses, refunds, largest expense, cost per day
             * @returns {Object}
             */
            tagSummaryFigures() {
                return tagFigures(this.tagSummary, isoDay(new Date()));
            },

            /**
             * Categories of the tag opened, largest amount first, with their share
             * @returns {Array<Object>}
             */
            tagSummaryCategories() {
                return categoryShares(this.tagSummary?.categories);
            }
        },

        methods: {
            /**
             * Loads the tags
             * @returns {Promise<void>}
             */
            async loadTags() {
                if (!getApiService()) {
                    return;
                }
                this.loadingTags = true;
                try {
                    this.tags = await getApiService().fetchTags();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.loadingTags = false;
                }
            },

            /**
             * Share of the period of a tag already gone (bar of its card), null without one
             * @param {Object} tag - Tag
             * @returns {number|null}
             */
            tagProgress(tag) {
                return tagProgress(tag, isoDay(new Date()));
            },

            /**
             * Tags of a transaction
             * @param {Object} transaction - Transaction (tags: ids)
             * @returns {Array<Object>}
             */
            transactionTags(transaction) {
                return (transaction.tags || []).map(id => this.tagsById.get(Number(id))).filter(Boolean);
            },

            /**
             * Active tags not on a transaction yet, offered in its detail
             * @param {Object} transaction - Transaction
             * @returns {Array<Object>}
             */
            tagsToOffer(transaction) {
                const on = new Set((transaction.tags || []).map(Number));
                return this.activeTags.filter(t => !on.has(Number(t.id)));
            },

            /**
             * Opens the form of a tag: a new one (starting today), or one to change
             * @param {Object|null} tag - Tag to change
             */
            openTagModal(tag = null) {
                this.tagForm = tag
                    ? { id: tag.id, name: tag.name, color: tag.color || '', start_date: tag.start_date, end_date: tag.end_date || '' }
                    : { ...EMPTY_TAG, start_date: isoDay(new Date()) };
                this.showTagModal = true;
                this.$nextTick?.(() => document.querySelector('#tag-name')?.focus());
            },

            /** Closes the form of a tag */
            closeTagModal() {
                this.showTagModal = false;
            },

            /**
             * Saves the tag of the form, then reloads the tags (and the summary opened)
             * @returns {Promise<void>}
             */
            async saveTag() {
                const form = this.tagForm;
                const tag = { name: tagName(form.name), color: form.color, start_date: form.start_date, end_date: form.end_date || null };
                this.savingTag = true;
                try {
                    if (form.id === null) {
                        await getApiService().createTag(tag);
                    } else {
                        await getApiService().updateTag({ id: form.id, ...tag });
                    }
                    this.showTagModal = false;
                    this.showToast(this.t('tagSaved'));
                    await this.loadTags();
                    if (this.tagSummary && Number(this.tagSummary.tag.id) === Number(form.id)) {
                        await this.openTagSummary(this.tagSummary.tag);
                    }
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingTag = false;
                }
            },

            /**
             * Deletes a tag after confirmation: its transactions only lose it
             * @param {Object} tag - Tag
             * @returns {Promise<void>}
             */
            async removeTag(tag) {
                if (!confirm(this.t('confirmDeleteTag', { name: tag.name }))) {
                    return;
                }
                try {
                    await getApiService().deleteTag(tag.id);
                    this.showToast(this.t('tagDeleted'));
                    if (this.tagSummary && Number(this.tagSummary.tag.id) === Number(tag.id)) {
                        this.tagSummary = null;
                    }
                    await this.loadTags();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /** Closes the detail of a transaction and opens the Tags tab (link of its tags) */
            goToTagsTab() {
                this.transactionDetail = null;
                this.setActiveTab('tags');
            },

            /**
             * Opens the summary of a tag
             * @param {Object} tag - Tag
             * @returns {Promise<void>}
             */
            async openTagSummary(tag) {
                this.ensureCategories?.();
                try {
                    this.tagSummary = await getApiService().fetchTagSummary(tag.id);
                    this.tagChartActive = null;
                    this.$nextTick?.(() => document.querySelector('.tag-summary h3')?.focus());
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * A category, by its id (bars of the summary)
             * @param {number} id - Category id
             * @returns {Object}
             */
            categoryOf(id) {
                return (this.ruleCategories || []).find(c => Number(c.id) === Number(id)) || { id, name: '', color: '' };
            },

            /** Back to the list of the tags */
            closeTagSummary() {
                this.tagSummary = null;
                this.tagChartActive = null;
            },

            /**
             * Follows the width of the chart of a summary (function ref of its frame)
             * @param {Element|null} element - Frame of the chart, null once removed
             */
            observeTagChart(element) {
                if (element === this.tagChartElement) {
                    return;
                }
                this.tagChartObserver?.disconnect();
                this.tagChartElement = element;
                if (!element || typeof ResizeObserver === 'undefined') {
                    return;
                }
                this.tagChartObserver = new ResizeObserver(([entry]) => {
                    const width = Math.round(entry.contentRect.width);
                    if (width > 0 && width !== this.tagChartWidth) {
                        this.tagChartWidth = width;
                    }
                });
                this.tagChartObserver.observe(element);
            },

            /** Points the last day when the chart gets the focus (the arrow keys move from it) */
            focusTagChart() {
                if (this.tagChartActive === null && this.tagSummaryChart.points.length) {
                    this.tagChartActive = this.tagSummaryChart.points.length - 1;
                }
            },

            /**
             * Points the day nearest to the pointer in the chart of a summary
             * @param {PointerEvent} event - Move over the chart
             */
            hoverTagChart(event) {
                const box = event.currentTarget.getBoundingClientRect();
                const scale = box.width ? this.tagChartWidth / box.width : 1;
                this.tagChartActive = nearestPoint(this.tagSummaryChart.points, (event.clientX - box.left) * scale);
            },

            /**
             * Moves along the chart of a summary with the keyboard: arrows, Home, End
             * @param {KeyboardEvent} event - Key pressed on the chart
             */
            moveTagChart(event) {
                const count = this.tagSummaryChart.points.length;
                const current = this.tagChartActive ?? count - 1;
                const moves = { ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: count - 1 };
                if (!count || !(event.key in moves)) {
                    return;
                }
                event.preventDefault();
                this.tagChartActive = Math.min(count - 1, Math.max(0, moves[event.key]));
            },

            /**
             * Short date of an axis or a tooltip: "3 juil."
             * @param {string} day - Day (YYYY-MM-DD)
             * @returns {string}
             */
            formatShortDay(day) {
                return new Intl.DateTimeFormat(this.intlLocale, { day: 'numeric', month: 'short' }).format(new Date(`${day}T00:00:00`));
            },

            /**
             * Share in percent of the locale: "42 %"
             * @param {number} percent - Share, 0 to 100
             * @returns {string}
             */
            formatShare(percent) {
                return new Intl.NumberFormat(this.intlLocale, { style: 'percent', maximumFractionDigits: 0 }).format(percent / 100);
            },

            /**
             * Shows the transactions of a tag in the Transactions tab (search "#name")
             * @param {Object} tag - Tag
             */
            showTagTransactions(tag) {
                this.setActiveTab('transactions');
                this.searchQuery = `#${tag.name}`;
                this.runSearch();
            },

            /**
             * Sets the tags of a transaction and updates it in place
             * @param {Object} transaction - Transaction
             * @param {Array<number>} wanted - Ids of its tags
             * @returns {Promise<void>}
             */
            async setTransactionTags(transaction, wanted) {
                const { add, remove } = tagChanges(transaction.tags, wanted);
                if (!add.length && !remove.length) {
                    return;
                }
                try {
                    await getApiService().setTransactionTags([transaction.id], add, remove);
                    transaction.tags = wanted.map(Number);
                    this.loadTags();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Creates a tag typed in a tag field: starting today, with the default color
             * @param {string} name - Name of the tag
             * @returns {Promise<Object|null>} The tag, or null when it could not be created
             */
            async createTypedTag(name) {
                try {
                    const tag = await getApiService().createTag({ name: tagName(name), color: EMPTY_TAG.color, start_date: isoDay(new Date()) });
                    this.tags = [...this.tags, tag];
                    this.showToast(this.t('tagCreated', { name: tag.name }));
                    return tag;
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                    return null;
                }
            },

            /**
             * Adds a tag chosen in the detail of a transaction
             * @param {Object} transaction - Transaction
             * @param {number} id - Tag id
             * @returns {Promise<void>}
             */
            async addDetailTag(transaction, id) {
                if (!id || (transaction.tags || []).map(Number).includes(Number(id))) {
                    return;
                }
                await this.setTransactionTags(transaction, [...(transaction.tags || []), Number(id)]);
            },

            /**
             * Creates a tag typed in the detail of a transaction, then adds it
             * @param {Object} transaction - Transaction
             * @param {string} name - Name of the tag
             * @returns {Promise<void>}
             */
            async createDetailTag(transaction, name) {
                const tag = await this.createTypedTag(name);
                if (tag) {
                    await this.addDetailTag(transaction, Number(tag.id));
                }
            },

            /**
             * Removes a tag from a transaction
             * @param {Object} transaction - Transaction
             * @param {Object} tag - Tag
             * @returns {Promise<void>}
             */
            async removeDetailTag(transaction, tag) {
                await this.setTransactionTags(transaction, (transaction.tags || []).filter(id => Number(id) !== Number(tag.id)));
            },

            /** Starts or stops the selection of transactions of the list */
            toggleTransactionSelection() {
                this.selectingTransactions = !this.selectingTransactions;
                this.selectedTransactionIds = [];
                this.selectionTag = '';
            },

            /**
             * Selects or unselects a transaction of the list
             * @param {Object} transaction - Transaction
             */
            toggleSelectedTransaction(transaction) {
                const id = Number(transaction.id);
                this.selectedTransactionIds = this.selectedTransactionIds.includes(id)
                    ? this.selectedTransactionIds.filter(i => i !== id)
                    : [...this.selectedTransactionIds, id].slice(0, MAX_TAG_SELECTION);
            },

            /**
             * Adds a tag to the selected transactions, then ends the selection
             * @param {number} id - Tag id
             * @returns {Promise<void>}
             */
            async tagSelectedTransactions(id) {
                const tag = Number(id);
                const ids = this.selectedTransactionIds;
                if (!tag || !ids.length) {
                    return;
                }
                try {
                    await getApiService().setTransactionTags(ids, [tag], []);
                    const list = this.isSearching ? this.searchResults : this.transactions;
                    for (const transaction of list.filter(t => ids.includes(Number(t.id)))) {
                        transaction.tags = [...new Set([...(transaction.tags || []).map(Number), tag])];
                    }
                    this.showToast(this.t('tagAddedToSelection', { count: ids.length, name: this.tagsById.get(tag)?.name || '' }));
                    this.selectingTransactions = false;
                    this.selectedTransactionIds = [];
                    this.selectionTag = '';
                    this.loadTags();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Creates a tag typed in the selection bar: it becomes the tag chosen, applied by
             * the button of the bar
             * @param {string} name - Name of the tag
             * @returns {Promise<void>}
             */
            async createSelectionTag(name) {
                const tag = await this.createTypedTag(name);
                this.selectionTag = tag ? Number(tag.id) : '';
            }
        }
    };
}
