/**
 * Budget Module
 * 
 * Manages budget-related state and methods.
 * Provides functionality for budget navigation, editing, and display.
 * 
 * @module budgetModule
 */

import { materialIconFor, cssColorFor, badgeStyle } from '../utils/categoryIcons.js';

/**
 * Known Material icon names (true) or unknown ones (false), measured once
 * @type {Map<string, boolean>}
 */
const materialIcons = new Map();

/**
 * Checks that a name is rendered as a Material icon: a known ligature is
 * about one em wide, an unknown name is rendered as plain (wide) text
 * @param {string} name - Icon name stored for the category
 * @returns {boolean}
 */
function isMaterialIcon(name) {
    if (!/^[a-z0-9_]+$/.test(name)) {
        return false;
    }
    if (materialIcons.has(name)) {
        return materialIcons.get(name);
    }
    const probe = document.createElement('span');
    probe.className = 'material-icons';
    probe.style.cssText = 'position:absolute;visibility:hidden;font-size:24px;white-space:nowrap';
    probe.textContent = name;
    document.body.appendChild(probe);
    const known = probe.offsetWidth <= 30;
    probe.remove();
    // Only cache once the icon font is loaded (before, every name is plain text)
    if (!document.fonts || document.fonts.status === 'loaded') {
        materialIcons.set(name, known);
    }
    return known;
}

/**
 * Creates a budget module mixin for Vue components
 * @param {Function} getBudgetStore - Function that returns the budget store instance
 * @returns {Object} Vue mixin with budget methods and computed properties
 */
export function createBudgetModule(getBudgetStore, getApiService) {
    return {
        data() {
            return {
                showBudgetModal: false,
                // Category whose transactions are listed under the cards: {id, name}
                budgetCategory: null,
                budgetTransactions: [],
                loadingBudgetTransactions: false,
                iconFontReady: !document.fonts || document.fonts.status === 'loaded',
                budgetToEdit: null,
                // Edit modal: amount, and for a parent category the sum of its sub-categories
                budgetNewValue: '',
                budgetNote: '',
                budgetUseChildren: false,
                // Edit modal of a category without sub-categories: no budget (saved as 0)
                budgetNone: false,
                savingBudget: false,
                // Copy of the previous month: modal, missing (default) or all, ongoing request
                showCopyModal: false,
                copyMode: 'missing',
                copyingBudget: false
            };
        },

        mounted() {
            if (!this.iconFontReady) {
                document.fonts.ready.then(() => { this.iconFontReady = true; });
            }
        },

        computed: {
            /**
             * Safe access to current budget list from store
             * @returns {Array}
             */
            currentBudgetList() {
                const store = getBudgetStore();
                return store?.state?.currentList || [];
            },

            /**
             * Safe access to breadcrumb stack from store
             * @returns {Array}
             */
            breadcrumbStack() {
                const store = getBudgetStore();
                return store?.state?.breadcrumbStack || [];
            },

            /**
             * Budget items grouped for display: budgeted expenses, incomes, others
             * (off-budget categories, or expense categories without a budget: what they
             * spent is shown, not as an overspending)
             * @returns {{expenses: Array, incomes: Array, others: Array}}
             */
            budgetGroups() {
                const groups = { expenses: [], incomes: [], others: [] };
                for (const item of this.currentBudgetList) {
                    const budget = Number(item.budget) || 0;
                    if (item.type === 'CREDIT') {
                        groups.incomes.push(item);
                    } else if (item.type === 'HORS-BUDGET' || budget === 0) {
                        groups.others.push(item);
                    } else {
                        groups.expenses.push(item);
                    }
                }
                return groups;
            },

            /**
             * Totals of the budgeted expenses of the displayed level
             * @returns {{budget: number, consumed: number, remaining: number, progress: number}}
             */
            budgetSummary() {
                const items = this.budgetGroups.expenses;
                const budget = items.reduce((sum, i) => sum + (Number(i.budget) || 0), 0);
                const consumed = items.reduce((sum, i) => sum + (Number(i.consummed) || 0), 0);
                return {
                    budget,
                    consumed,
                    remaining: budget - consumed,
                    progress: budget > 0 ? Math.round(consumed / budget * 100) : 0
                };
            },

            /**
             * Month and year the copy button copies from (month before the selected one)
             * @returns {{month: number, year: number}}
             */
            copySource() {
                const month = Number(this.selectedMonth) || new Date().getMonth() + 1;
                const year = Number(this.selectedYear) || new Date().getFullYear();
                const date = new Date(year, month - 2, 1);
                return { month: date.getMonth() + 1, year: date.getFullYear() };
            },

            /**
             * Localized name of the month the copy button copies from (lowercase inside a
             * sentence, except in English and German)
             * @returns {string}
             */
            copySourceName() {
                const name = String(this.t(`months.${this.copySource.month}`));
                return ['en', 'de'].includes(this.locale) ? name : name.toLowerCase();
            },

            /**
             * The copy of the previous month is offered: the selected month has no budget
             * defined and the previous month has some (hidden while unknown)
             * @returns {boolean}
             */
            budgetCopyOffered() {
                const status = getBudgetStore()?.state?.copyStatus;
                const month = Number(this.selectedMonth) || new Date().getMonth() + 1;
                const year = Number(this.selectedYear) || new Date().getFullYear();
                return status?.copyable === true && status.month === `${year}-${String(month).padStart(2, '0')}`;
            },

            /**
             * Label of the copy button, with the name of the source month
             * @returns {string}
             */
            copyButtonLabel() {
                return this.t('budgetCopyButton', { month: this.copySourceName });
            },

            /**
             * Loading state from budget store
             * @returns {boolean}
             */
            loadingBudget() {
                const store = getBudgetStore();
                return store?.state?.isLoading || false;
            },

            /**
             * Budget store error message
             * @returns {string|null}
             */
            budgetError() {
                const store = getBudgetStore();
                return store?.state?.error || null;
            }
        },

        methods: {
            /**
             * Loads root budgets using budget store
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async loadBudget(month, year) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                this.clearBudgetTransactions();

                try {
                    await store.loadRootBudgets(month, year);

                    // Show debug toast in dev mode
                    if (this.isDebugMode) {
                        this.showToast(`Budget loaded: ${this.currentBudgetList.length} items`);
                    }
                    
                } catch (err) {
                    console.error('Budget loading error:', err);
                    this.error = this.apiErrorText(err);
                }
            },

            /**
             * Material icon of a category (falls back when the stored icon is not a Material name)
             * @param {Object} item - Budget item
             * @returns {string}
             */
            categoryIcon(item) {
                // Re-evaluated once the icon font is loaded
                if (!this.iconFontReady) {
                    return 'category';
                }
                // Material name, or SF Symbol of the iOS app mapped to Material
                return materialIconFor(item.icon, isMaterialIcon);
            },

            /**
             * Color of a category (falls back to the primary color)
             * @param {Object} item - Budget item
             * @returns {string}
             */
            categoryColor(item) {
                // CSS color, hex, or SwiftUI color name of the iOS app
                return cssColorFor(item.color) || 'var(--primary-color)';
            },

            /**
             * Style of the icon badge of a category (its color on a light tint)
             * @param {Object} item - Category or budget item
             * @returns {{color: string, background: string}}
             */
            categoryBadgeStyle(item) {
                return badgeStyle(this.categoryColor(item));
            },

            /**
             * Budget status of an item: ok (green up to 105 %, a small overrun is tolerated)
             * or over (> 105 %)
             * @param {Object} item - Budget item
             * @returns {string}
             */
            budgetStatus(item) {
                const progress = Number(item.progress) || 0;
                return progress > 105 ? 'over' : 'ok';
            },

            /**
             * Name of a budget item: the item of the transactions put on a parent
             * category itself (own) says that they are in none of its sub-categories
             * @param {Object} item - Budget item
             * @returns {string}
             */
            budgetItemName(item) {
                return item.own ? this.t('budgetOwnTransactions', { category: item.name }) : item.name;
            },

            /**
             * The budget item is the one whose transactions are listed under the cards
             * @param {Object} item - Budget item
             * @returns {boolean}
             */
            isBudgetCategorySelected(item) {
                return !!this.budgetCategory && this.budgetCategory.id === item.id && this.budgetCategory.own === !!item.own;
            },

            /**
             * Loads the transactions of a category (sub-categories included, or only those
             * put on the category itself for the own item of a parent category)
             * @param {Object} category - Category or budget item {id, name, own?}
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async loadBudgetTransactions(category, month, year) {
                const own = !!category.own;
                this.budgetCategory = { id: category.id, name: this.budgetItemName(category), own };
                this.loadingBudgetTransactions = true;
                try {
                    this.budgetTransactions = await getApiService().fetchCategoryTransactions(month, year, category.id, own);
                    // Icons of the rows: the categories of the transactions
                    this.ensureCategories();
                } catch (err) {
                    this.budgetTransactions = [];
                    this.showToast(this.apiErrorText(err));
                } finally {
                    this.loadingBudgetTransactions = false;
                }
                this.$nextTick(() => {
                    const list = document.querySelector('.budget-transactions');
                    if (list) {
                        list.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    }
                });
            },

            /**
             * Hides the transactions of the selected category
             */
            clearBudgetTransactions() {
                this.budgetCategory = null;
                this.budgetTransactions = [];
            },

            /**
             * Reloads the budgets of the displayed level and the transactions listed under
             * the cards (after a transaction changed category or was deleted)
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async reloadBudgetLevel(month, year) {
                const store = getBudgetStore();
                if (!store) {
                    return;
                }
                const category = this.budgetCategory;
                try {
                    await store.reloadBudgets(month, year);
                } catch (err) {
                    this.showToast(err.message);
                }
                if (category) {
                    this.loadBudgetTransactions(category, month, year);
                }
            },

            /**
             * Navigates to child budgets for a given item
             * @param {Object} item - Budget item to navigate to
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async handleBudgetClick(item, month, year) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                try {
                    // List the transactions of the category, then go into its sub-categories if any
                    this.loadBudgetTransactions(item, month, year);
                    // The own item of a parent: its transactions only (its children are the current level)
                    if (!item.own) {
                        await store.navigateToChildren(item, month, year);
                    }
                } catch (err) {
                    console.error('Navigate to budget error:', err);
                    this.showToast(this.apiErrorText(err) || this.t('navigationError'));
                }
            },

            /**
             * Navigates to a specific breadcrumb level
             * @param {number} index - Breadcrumb index (-1 for root)
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @returns {Promise<void>}
             */
            async handleBreadcrumbClick(index, month, year) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                try {
                    await store.navigateToBreadcrumb(index, month, year);
                    const crumb = index >= 0 ? store.state.breadcrumbStack[index] : null;
                    if (crumb) {
                        this.loadBudgetTransactions(crumb, month, year);
                    } else {
                        this.clearBudgetTransactions();
                    }
                } catch (err) {
                    console.error('Breadcrumb navigation error:', err);
                    this.showToast(this.apiErrorText(err) || this.t('navigationError'));
                }
            },

            /**
             * Opens budget edit modal
             * @param {Object} item - Budget item to edit
             */
            handleBudgetEdit(item) {
                this.budgetToEdit = item;
                this.budgetUseChildren = item.budget_mode === 'children';
                this.budgetNone = false;
                this.budgetNewValue = this.budgetUseChildren ? '' : (Number(item.budget) || '');
                this.budgetNote = item.note || '';
                this.showBudgetModal = true;
                this.$nextTick(() => document.getElementById('budget-edit-amount')?.focus());
            },

            /**
             * Saves the budget of the edit modal: the amount, or 0 for a parent category
             * whose budget is the sum of its sub-categories or for a category without budget
             * @returns {Promise<void>}
             */
            async submitBudgetEdit() {
                const noAmount = this.budgetUseChildren || this.budgetNone;
                const amount = noAmount ? 0 : Number(this.budgetNewValue);
                if (!Number.isFinite(amount) || amount < 0 || (!noAmount && this.budgetNewValue === '')) {
                    this.showToast(this.t('budgetInvalidAmount'));
                    return;
                }
                this.savingBudget = true;
                try {
                    await this.handleBudgetSave(amount, this.selectedMonth, this.selectedYear, this.budgetNote.trim());
                } finally {
                    this.savingBudget = false;
                }
            },

            /**
             * Closes budget edit modal
             */
            closeBudgetModal() {
                this.showBudgetModal = false;
                this.budgetToEdit = null;
            },

            /**
             * Opens the modal copying the budgets of the previous month
             */
            openCopyModal() {
                this.copyMode = 'missing';
                this.showCopyModal = true;
            },

            /**
             * Closes the modal copying the budgets of the previous month
             */
            closeCopyModal() {
                this.showCopyModal = false;
            },

            /**
             * Copies the budgets of the previous month, then reloads the list
             * @param {number} month - Target month (1-12)
             * @param {number} year - Target year
             * @returns {Promise<void>}
             */
            async submitBudgetCopy(month, year) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                this.copyingBudget = true;
                try {
                    const result = await store.copyBudgets(month, year, this.copyMode === 'all');
                    const count = Number(result?.copied) || 0;
                    this.showToast(count > 0
                        ? this.t('budgetCopiedToast', { count })
                        : this.t('budgetCopyEmpty', { month: this.copySourceName }));
                    this.closeCopyModal();
                } catch (err) {
                    console.error('Copy budgets error:', err);
                    this.showToast(this.apiErrorText(err));
                } finally {
                    this.copyingBudget = false;
                }
            },

            /**
             * Saves edited budget value
             * @param {number} newValue - New budget amount
             * @param {number} month - Month (1-12)
             * @param {number} year - Year
             * @param {string} [note] - Reminder of what the budget covers ('' removes it)
             * @returns {Promise<void>}
             */
            async handleBudgetSave(newValue, month, year, note) {
                const store = getBudgetStore();
                if (!store) {
                    console.error('Budget store not initialized');
                    return;
                }

                try {
                    await store.updateBudget(
                        this.budgetToEdit.id,
                        month,
                        year,
                        newValue,
                        note
                    );

                    this.showToast(this.t('budgetUpdatedToast'));
                    this.closeBudgetModal();
                } catch (err) {
                    console.error('Edit budget error:', err);
                    this.showToast(this.apiErrorText(err));
                }
            },

            /**
             * Aborts any pending budget request
             */
            abortBudget() {
                const store = getBudgetStore();
                if (store && typeof store.abortAll === 'function') {
                    store.abortAll();
                }
            }
        }
    };
}
