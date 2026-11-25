/**
 * Insights Module
 * 
 * Manages insights-related state and methods.
 * Provides functionality for loading and displaying financial insights.
 * 
 * @module insightsModule
 */

/** Directions of an insight alert: the value goes above or below the threshold */
export const INSIGHT_ALERT_DIRECTIONS = ['above', 'below'];

/**
 * Why an insight alert threshold is refused ('' when it is valid): a positive amount
 * @param {number|string} value - Amount typed
 * @returns {string} Translation key of the error, or ''
 */
export function insightAlertThresholdError(value) {
    const text = String(value ?? '').trim().replace(',', '.');
    const number = Number(text);
    return text !== '' && Number.isFinite(number) && number >= 0 && number < 1e10 ? '' : 'insightAlertThresholdInvalid';
}

/**
 * The alerts of the user by insight
 * @param {Array} alerts - Answer of GET /insight/alert: [{insight, direction, threshold}]
 * @returns {Object} {insightId: {direction, threshold}}
 */
export function insightAlertsById(alerts) {
    return Object.fromEntries((alerts || []).map(alert => [alert.insight, { direction: alert.direction, threshold: Number(alert.threshold) }]));
}

/**
 * A list with one of its items moved to another place
 * @param {Array} list - The items
 * @param {number} from - Index of the item moved
 * @param {number} to - Its new index (clamped to the list)
 * @returns {Array} A new list (the same one when nothing moves)
 */
export function moveInsight(list, from, to) {
    const target = Math.max(0, Math.min(list.length - 1, to));
    if (from < 0 || from >= list.length || from === target) {
        return list;
    }
    const moved = list.slice();
    const [item] = moved.splice(from, 1);
    moved.splice(target, 0, item);
    return moved;
}

/** Keys moving an insight from its drag handle: offset, or 'first' / 'last' */
const INSIGHT_MOVE_KEYS = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1, Home: 'first', End: 'last' };

/**
 * Creates an insights module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with insights methods
 */
export function createInsightsModule(getApiService) {
    return {
        data() {
            return {
                insights: [],
                loadingInsights: false,
                // Insights hidden, offered by the + button: {id, name, color, icon}
                disabledInsights: [],
                showInsightCatalog: false,
                togglingInsight: null,
                // Alerts of the user by insight: {id: {direction, threshold}}
                insightAlerts: {},
                insightAlertDirections: INSIGHT_ALERT_DIRECTIONS,
                // Alert of the modal: {insight, name, direction, threshold, exists}, and its error (translation key)
                insightAlertForm: null,
                insightAlertErrorKey: '',
                savingInsightAlert: false,
                // Insight of the modal: {id, name, color, sql}
                insightForm: null,
                savingInsight: false,
                insightError: null,
                insightColors: ['red', 'orange', 'amber', 'lime', 'green', 'teal', 'cyan', 'blue', 'indigo', 'purple', 'pink', 'brown', 'gray'],
                // Colors of the cards (--insight-color)
                insightColorValues: { red: '#ef4444', orange: '#f59e0b', amber: '#eab308', lime: '#84cc16', green: '#10b981', teal: '#14b8a6', cyan: '#06b6d4', blue: '#3b82f6', indigo: '#6366f1', purple: '#a855f7', pink: '#ec4899', brown: '#a16207', gray: '#6b7280' },
                insightIcons: ['trending_down', 'trending_up', 'savings', 'account_balance_wallet', 'payments', 'credit_card',
                    'shopping_cart', 'restaurant', 'local_gas_station', 'directions_car', 'home', 'bolt', 'local_hospital',
                    'school', 'flight', 'sports_esports', 'pets', 'receipt_long', 'percent', 'analytics', 'pie_chart',
                    'show_chart', 'warning', 'star'],
                // Check of the query while it is typed: idle, checking, valid, invalid
                insightCheck: { state: 'idle', amount: null, error: null },
                insightsController: null,
                // Drag and drop of the cards: id of the card dragged (null: none), order before the
                // drag, and what the screen readers announce after a move
                draggingInsight: null,
                insightOrderBefore: [],
                // Last place of the pointer while dragging, checked again once the cards stopped sliding
                insightPointer: null,
                insightSettleTimer: null,
                insightOrderAnnouncement: ''
            };
        },

        watch: {
            'insightForm.sql'() {
                this.scheduleInsightCheck();
            }
        },

        methods: {
            /**
             * Loads insights for selected month/year
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async loadInsights(month, year) {
                const apiService = getApiService();
                if (!apiService) {
                    console.error('API service not initialized');
                    return;
                }

                this.loadingInsights = true;
                this.error = null;

                // Cancel previous request
                if (this.insightsController) {
                    this.insightsController.abort();
                }
                const controller = new AbortController();
                this.insightsController = controller;

                try {
                    // The hidden insights and the alerts (the same for every month) at the same time
                    const [data, disabled, alerts] = await Promise.all([
                        apiService.fetchInsights(month, year, controller.signal),
                        this.fetchDisabledInsights(),
                        this.fetchInsightAlerts()
                    ]);

                    this.insights = Array.isArray(data) ? data : [];
                    this.disabledInsights = disabled;
                    this.insightAlerts = alerts;

                    // Show debug toast in dev mode
                    if (this.isDebugMode) {
                        this.showToast(`Insights loaded: ${this.insights.length} items`);
                    }
                } catch (err) {
                    if (err.name === 'AbortError') {
                        return;
                    }
                    
                    console.error('Insights loading error:', err);
                    this.error = this.apiErrorText(err);
                    this.insights = [];
                } finally {
                    // A newer request keeps its loading state and its controller
                    if (this.insightsController === controller) {
                        this.loadingInsights = false;
                        this.insightsController = null;
                    }
                }
            },

            /**
             * Fetches the insights hidden, offered by the + button (none on a failure:
             * the button then only creates an insight)
             * @returns {Promise<Array>} The hidden insights
             */
            async fetchDisabledInsights() {
                try {
                    return await getApiService().fetchDisabledInsights();
                } catch (error) {
                    return [];
                }
            },

            /**
             * Fetches the alerts of the user by insight (none on a failure, or on a server
             * older than the alerts)
             * @returns {Promise<Object>} {insightId: {direction, threshold}}
             */
            async fetchInsightAlerts() {
                try {
                    return insightAlertsById(await getApiService().fetchInsightAlerts());
                } catch (error) {
                    return {};
                }
            },

            /**
             * Text of the alert of an insight under its value ('' without alert)
             * @param {Object} insight - Insight {id}
             * @returns {string}
             */
            insightAlertText(insight) {
                const alert = this.insightAlerts[insight.id];
                if (!alert) {
                    return '';
                }
                return this.t('insightAlert_' + alert.direction, { amount: `${this.formatAmount(alert.threshold)} ${this.currencySymbol}` });
            },

            /**
             * Opens the alert modal of an insight
             * @param {Object} insight - Insight {id, name}
             * @returns {void}
             */
            openInsightAlert(insight) {
                const alert = this.insightAlerts[insight.id];
                this.insightAlertErrorKey = '';
                this.insightAlertForm = {
                    insight: insight.id,
                    name: this.getInsightName(insight.name),
                    direction: alert ? alert.direction : 'above',
                    threshold: alert ? String(alert.threshold) : '',
                    exists: Boolean(alert)
                };
                this.$nextTick(() => document.getElementById('insight-alert-threshold')?.focus());
            },

            /**
             * Closes the alert modal, the focus back on the bell of its insight
             * @returns {void}
             */
            closeInsightAlert() {
                const insight = this.insightAlertForm?.insight;
                this.insightAlertForm = null;
                this.$nextTick(() => document.getElementById('insight-alert-button-' + insight)?.focus());
            },

            /**
             * Saves the alert of the modal
             * @returns {Promise<void>}
             */
            async saveInsightAlert() {
                const form = this.insightAlertForm;
                this.insightAlertErrorKey = insightAlertThresholdError(form.threshold);
                if (this.insightAlertErrorKey) {
                    return;
                }
                this.savingInsightAlert = true;
                try {
                    const saved = await getApiService().setInsightAlert(form.insight, form.direction, Number(String(form.threshold).trim().replace(',', '.')));
                    this.insightAlerts = { ...this.insightAlerts, [saved.insight]: { direction: saved.direction, threshold: Number(saved.threshold) } };
                    this.showToast(this.t('insightAlertSaved', { name: form.name }));
                    this.closeInsightAlert();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingInsightAlert = false;
                }
            },

            /**
             * Removes the alert of the modal
             * @returns {Promise<void>}
             */
            async removeInsightAlert() {
                const form = this.insightAlertForm;
                this.savingInsightAlert = true;
                try {
                    await getApiService().deleteInsightAlert(form.insight);
                    const { [form.insight]: removed, ...alerts } = this.insightAlerts;
                    this.insightAlerts = alerts;
                    this.showToast(this.t('insightAlertRemoved', { name: form.name }));
                    this.closeInsightAlert();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingInsightAlert = false;
                }
            },

            /**
             * Opens the list of the insights to add (the hidden ones; administrators also create one)
             * @returns {void}
             */
            openInsightCatalog() {
                this.showInsightCatalog = true;
                // The first insight to show, else the first button of the footer
                this.$nextTick(() => document.querySelector('.insight-catalog button, .insight-catalog-dialog .modal-footer button')?.focus());
            },

            /**
             * Closes the list of the insights to add
             * @returns {void}
             */
            closeInsightCatalog() {
                this.showInsightCatalog = false;
            },

            /**
             * Creates an insight from the list of the insights to add (administrators)
             * @returns {Promise<void>}
             */
            async createInsightFromCatalog() {
                this.showInsightCatalog = false;
                await this.openInsightModal();
            },

            /**
             * Shows a hidden insight, or hides a shown one, for the whole household
             * @param {Object} insight - Insight {id, name}
             * @param {boolean} enabled - True to show it
             * @returns {Promise<void>}
             */
            async setInsightEnabled(insight, enabled) {
                this.togglingInsight = insight.id;
                try {
                    await getApiService().setInsightEnabled(insight.id, enabled);
                    const name = this.getInsightName(insight.name);
                    this.showToast(this.t(enabled ? 'insightShown' : 'insightHidden', { name }));
                    if (enabled && this.disabledInsights.length <= 1) {
                        this.showInsightCatalog = false;
                    }
                    await this.loadInsights(this.selectedMonth, this.selectedYear);
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.togglingInsight = null;
                }
            },

            /**
             * Starts dragging a card by its handle (mouse, pen or finger): the card follows the
             * pointer from cell to cell of the grid, and the order is saved when it is dropped
             * @param {PointerEvent} event - pointerdown on the handle
             * @param {Object} insight - The insight dragged
             */
            startInsightDrag(event, insight) {
                if (event.button > 0 || this.draggingInsight !== null) {
                    return;
                }
                event.preventDefault();
                this.draggingInsight = insight.id;
                this.insightOrderBefore = this.insights.map(i => i.id);
                const move = e => this.dragInsightTo(e.clientX, e.clientY);
                const end = () => {
                    window.removeEventListener('pointermove', move);
                    window.removeEventListener('pointerup', end);
                    window.removeEventListener('pointercancel', end);
                    this.endInsightDrag();
                };
                window.addEventListener('pointermove', move);
                window.addEventListener('pointerup', end);
                window.addEventListener('pointercancel', end);
            },

            /**
             * Snaps the dragged card to the cell of the card under the pointer
             * @param {number} x - Pointer abscissa (client)
             * @param {number} y - Pointer ordinate (client)
             */
            dragInsightTo(x, y) {
                this.insightPointer = { x, y };
                const target = document.elementFromPoint(x, y)?.closest('.insight-card[data-insight-id]');
                const targetId = target ? Number(target.dataset.insightId) : null;
                if (targetId === null || targetId === this.draggingInsight) {
                    return;
                }
                const from = this.insights.findIndex(i => i.id === this.draggingInsight);
                const to = this.insights.findIndex(i => i.id === targetId);
                this.insights = moveInsight(this.insights, from, to);
                // While the cards slide, the one under the pointer may be passing by: the cell
                // is checked again once they stopped, even if the pointer does not move
                clearTimeout(this.insightSettleTimer);
                this.insightSettleTimer = setTimeout(() => {
                    if (this.draggingInsight !== null && this.insightPointer) {
                        this.dragInsightTo(this.insightPointer.x, this.insightPointer.y);
                    }
                }, 250);
            },

            /**
             * Drops the dragged card: saves the order when it changed
             * @returns {Promise<void>}
             */
            async endInsightDrag() {
                clearTimeout(this.insightSettleTimer);
                this.insightPointer = null;
                const insight = this.insights.find(i => i.id === this.draggingInsight);
                this.draggingInsight = null;
                const order = this.insights.map(i => i.id);
                if (insight && order.join(',') !== this.insightOrderBefore.join(',')) {
                    await this.saveInsightOrder(insight);
                }
            },

            /**
             * Moves a card with the keyboard from its handle: arrows to the previous or next
             * cell, Home / End to the first or last one; the focus stays on its handle
             * @param {KeyboardEvent} event - keydown on the handle
             * @param {Object} insight - The insight moved
             * @returns {Promise<void>}
             */
            async moveInsightByKey(event, insight) {
                const step = INSIGHT_MOVE_KEYS[event.key];
                if (step === undefined || this.draggingInsight !== null) {
                    return;
                }
                event.preventDefault();
                const from = this.insights.findIndex(i => i.id === insight.id);
                const to = step === 'first' ? 0 : step === 'last' ? this.insights.length - 1 : from + step;
                const moved = moveInsight(this.insights, from, to);
                if (moved === this.insights) {
                    return;
                }
                this.insights = moved;
                this.$nextTick(() => document.querySelector(`.insight-card[data-insight-id="${insight.id}"] .insight-drag`)?.focus());
                await this.saveInsightOrder(insight);
            },

            /**
             * Saves the order of the cards for the whole household, and announces the new
             * place of the card moved; on a failure, the saved order is shown again
             * @param {Object} insight - The insight moved
             * @returns {Promise<void>}
             */
            async saveInsightOrder(insight) {
                try {
                    await getApiService().setInsightOrder(this.insights.map(i => i.id));
                    this.insightOrderAnnouncement = this.t('insightMoved', {
                        name: this.getInsightName(insight.name),
                        position: this.insights.findIndex(i => i.id === insight.id) + 1,
                        count: this.insights.length
                    });
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                    await this.loadInsights(this.selectedMonth, this.selectedYear);
                }
            },

            /**
             * Opens the insight modal to add an insight, or to modify the given one
             * (its SQL is fetched: the insights of the month do not carry it)
             * @param {Object|null} insight - Insight to modify
             * @returns {Promise<void>}
             */
            async openInsightModal(insight = null) {
                this.insightError = null;
                if (!insight) {
                    this.insightForm = {
                        id: null,
                        name: '',
                        color: 'blue',
                        icon: '',
                        sql: 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE category = 1 AND MONTH(date) = {month} AND YEAR(date) = {year}'
                    };
                } else {
                    try {
                        const definitions = await getApiService().fetchInsightDefinitions();
                        const definition = definitions.find(d => Number(d.id) === Number(insight.id));
                        this.insightForm = { id: Number(insight.id), name: insight.name, color: insight.color, icon: insight.icon || '', sql: definition ? definition.sql : '' };
                    } catch (error) {
                        this.showToast(this.apiErrorText(error));
                        return;
                    }
                }
                this.$nextTick(() => document.getElementById('insight-name')?.focus());
            },

            /**
             * Closes the insight modal
             */
            closeInsightModal() {
                this.insightForm = null;
                clearTimeout(this.insightCheckTimer);
                this.insightCheck = { state: 'idle', amount: null, error: null };
            },

            /**
             * Checks the query of the modal on the server, a moment after the last key
             * @returns {void}
             */
            scheduleInsightCheck() {
                clearTimeout(this.insightCheckTimer);
                if (!this.insightForm || !this.insightForm.sql.trim()) {
                    this.insightCheck = { state: 'idle', amount: null, error: null };
                    return;
                }
                this.insightCheck = { ...this.insightCheck, state: 'checking' };
                this.insightCheckTimer = setTimeout(async () => {
                    const sql = this.insightForm?.sql;
                    try {
                        const result = await getApiService().checkInsight(sql, this.selectedMonth, this.selectedYear);
                        // Ignore the answer to an older version of the query
                        if (!this.insightForm || this.insightForm.sql !== sql) {
                            return;
                        }
                        // The cause of an invalid query: translated by its code, like an API error
                        this.insightCheck = result.valid
                            ? { state: 'valid', amount: result.amount, error: null }
                            : { state: 'invalid', amount: null, error: this.apiErrorText({ message: result.error, code: result.error_code, params: result.params }) };
                    } catch (error) {
                        this.insightCheck = { state: 'invalid', amount: null, error: this.apiErrorText(error) };
                    }
                }, 600);
            },

            /**
             * Saves the insight of the modal (the server tests the query)
             * @returns {Promise<void>}
             */
            async submitInsight() {
                this.savingInsight = true;
                this.insightError = null;
                try {
                    await getApiService().saveInsight(this.insightForm);
                    this.showToast(this.t(this.insightForm.id ? 'insightUpdated' : 'insightAdded'));
                    this.insightForm = null;
                    await this.loadInsights(this.selectedMonth, this.selectedYear);
                } catch (error) {
                    // Shown in the modal, next to the query to correct
                    this.insightError = this.apiErrorText(error);
                } finally {
                    this.savingInsight = false;
                }
            },

            /**
             * Deletes an insight after confirmation
             * @param {Object} insight - Insight
             * @returns {Promise<void>}
             */
            async removeInsight(insight) {
                if (!confirm(this.t('confirmDeleteInsight', { name: this.getInsightName(insight.name) }))) {
                    return;
                }
                try {
                    await getApiService().deleteInsight(insight.id);
                    this.insights = this.insights.filter(i => Number(i.id) !== Number(insight.id));
                    this.insightForm = null;
                    this.showToast(this.t('insightDeleted'));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Aborts any pending insights request
             */
            abortInsights() {
                if (this.insightsController && typeof this.insightsController.abort === 'function') {
                    try {
                        this.insightsController.abort();
                    } catch (e) {
                        // Ignore abort errors
                    }
                    this.insightsController = null;
                }
            }
        }
    };
}
