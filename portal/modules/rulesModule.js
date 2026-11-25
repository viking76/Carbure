/**
 * Rules Module
 *
 * Manages the automatic categorization rules: a keyword found in a transaction
 * label sets its category.
 *
 * @module rulesModule
 */

/**
 * Amount of a rule as shown in its field: 2 decimals, '' for any amount
 * @param {string|number|null|undefined} amount - The amount of the rule (null: any amount)
 * @returns {string} "10.00" or ''
 */
export function ruleAmountText(amount) {
    return amount === null || amount === undefined || amount === '' || Number.isNaN(Number(amount)) ? '' : Number(amount).toFixed(2);
}

/**
 * Creates a rules module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with rules data and methods
 */
export function createRulesModule(getApiService) {
    return {
        data() {
            return {
                rules: [],
                ruleCategories: [],
                loadingRules: false,
                ruleFilter: '',
                // Rule of the modal: {id (null for a new one), keyword, category, notify, amount ('' for any amount)}
                newRule: { id: null, keyword: '', category: '', notify: false, amount: '' },
                // Transactions matching the rule of the modal: {matching, categorized}
                ruleCount: null,
                showRuleModal: false,
                savingRule: false,
                applyingRules: false,
                // The rules leave the categorized transactions of the history alone
                keepCategorized: false,
                switchingKeepCategorized: false
            };
        },

        computed: {
            /**
             * Categories as options: parents first, each followed by its sub-categories
             * @returns {Array<{id: number, label: string}>}
             */
            ruleCategoryOptions() {
                const parents = this.ruleCategories.filter(c => Number(c.parent_category) === 0 && Number(c.id) !== 0);
                const options = [];
                const byName = (a, b) => a.name.localeCompare(b.name);
                for (const parent of parents.sort(byName)) {
                    options.push({ id: parent.id, label: parent.name });
                    this.ruleCategories
                        .filter(c => Number(c.parent_category) === Number(parent.id) && Number(c.id) !== Number(parent.id))
                        .sort(byName)
                        .forEach(child => options.push({ id: child.id, label: `${parent.name} › ${child.name}` }));
                }
                return options;
            },

            /**
             * Rules matching the filter (keyword or category), grouped by category
             * @returns {Array<{category: string, rules: Array}>}
             */
            groupedRules() {
                const filter = this.ruleFilter.trim().toUpperCase();
                const groups = new Map();
                for (const rule of this.rules) {
                    if (filter && !rule.keyword.includes(filter) && !rule.category_name.toUpperCase().includes(filter)) {
                        continue;
                    }
                    if (!groups.has(rule.category_name)) {
                        groups.set(rule.category_name, []);
                    }
                    groups.get(rule.category_name).push(rule);
                }
                return [...groups]
                    .map(([category, rules]) => ({ category, rules }))
                    .sort((a, b) => a.category.localeCompare(b.category, this.intlLocale));
            }
        },

        methods: {
            /**
             * Text of a rule for the screen readers: its keyword, then its amount if it has one
             * @param {Object} rule - {keyword, amount}
             * @returns {string} "PAYPAL" or "PAYPAL · 10,00 €"
             */
            ruleLabel(rule) {
                return rule.amount === null || rule.amount === undefined
                    ? rule.keyword
                    : `${rule.keyword} · ${this.formatAmount(rule.amount)} ${this.currencySymbol}`;
            },

            /**
             * Loads the rules and the categories
             * @returns {Promise<void>}
             */
            async loadRules() {
                const apiService = getApiService();
                if (!apiService) {
                    return;
                }

                this.loadingRules = true;
                try {
                    let settings;
                    [this.rules, this.ruleCategories, settings] = await Promise.all([
                        apiService.fetchRules(),
                        apiService.fetchCategories(),
                        apiService.fetchRuleSettings()
                    ]);
                    this.keepCategorized = !!settings.keep_categorized;
                } catch (error) {
                    this.error = this.apiErrorText(error);
                } finally {
                    this.loadingRules = false;
                }
            },

            /**
             * Opens the rule modal, optionally pre-filled
             * @param {Object|null} rule - {keyword, category, notify, amount}
             */
            openRuleModal(rule = null) {
                this.newRule = {
                    id: rule?.id ? Number(rule.id) : null,
                    keyword: rule?.keyword || '',
                    category: rule?.category !== undefined && rule?.category !== '' ? Number(rule.category) : '',
                    notify: !!Number(rule?.notify || 0),
                    amount: ruleAmountText(rule?.amount)
                };
                this.ruleCount = null;
                if (this.newRule.id) {
                    getApiService().countRule(this.newRule.id).then(count => { this.ruleCount = count; }).catch(() => {});
                }
                this.showRuleModal = true;
                // Let the user shorten the keyword (dates, card numbers...)
                this.$nextTick(() => {
                    const input = document.getElementById('rule-keyword');
                    if (input) {
                        input.focus();
                        input.select();
                    }
                });
            },

            /**
             * Adds the rule of the form
             * @returns {Promise<void>}
             */
            async addRule() {
                if (!this.newRule.keyword.trim() || this.newRule.category === '') {
                    this.showToast(this.t('ruleRequired'));
                    return;
                }

                this.savingRule = true;
                try {
                    // The rule applies at once to the history (its uncategorized transactions only if set so)
                    const saved = this.newRule.id
                        ? await getApiService().updateRule(this.newRule.id, this.newRule.keyword, this.newRule.category, this.newRule.notify, this.newRule.amount)
                        : await getApiService().createRule(this.newRule.keyword, this.newRule.category, this.newRule.notify, this.newRule.amount);
                    this.showRuleModal = false;
                    this.showToast(this.t('ruleSavedApplied', { count: Number(saved && saved.applied) || 0 }));
                    this.rules = await getApiService().fetchRules();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingRule = false;
                }
            },

            /**
             * Deletes a rule after confirmation
             * @param {Object} rule - Rule to delete
             * @returns {Promise<void>}
             */
            async removeRule(rule) {
                if (!confirm(this.t('confirmDeleteRule', { keyword: rule.keyword }))) {
                    return;
                }

                try {
                    await getApiService().deleteRule(rule.id);
                    this.rules = this.rules.filter(r => Number(r.id) !== Number(rule.id));
                    this.showRuleModal = false;
                    this.showToast(this.t('ruleDeleted'));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Applies the rules to the transactions of the database
             * @returns {Promise<void>}
             */
            async applyRules() {
                this.applyingRules = true;
                try {
                    const result = await getApiService().applyRules();
                    this.showToast(this.t(this.keepCategorized ? 'rulesAppliedUncategorized' : 'rulesApplied', { count: result.updated }));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.applyingRules = false;
                }
            },

            /**
             * Lets the rules recategorize the categorized transactions of the history or not
             * @param {Event} [event] - Change of the switch: shown again as saved when refused
             * @returns {Promise<void>}
             */
            async toggleKeepCategorized(event) {
                this.switchingKeepCategorized = true;
                try {
                    this.keepCategorized = !!(await getApiService().updateRuleSettings(!this.keepCategorized)).keep_categorized;
                    this.showToast(this.t(this.keepCategorized ? 'rulesKeepCategorizedOn' : 'rulesKeepCategorizedOff'));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.switchingKeepCategorized = false;
                    // Vue does not patch a value that did not change (#342)
                    if (event && event.target) {
                        event.target.checked = this.keepCategorized;
                    }
                }
            }
        }
    };
}
