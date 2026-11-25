/**
 * Budget State Management Module
 * 
 * Manages budget navigation, hierarchy, and children caching.
 * Provides methods for navigating budget categories and maintaining breadcrumb state.
 * 
 * @module budgetStore
 */

/**
 * Creates a budget store instance
 * @param {Object} apiService - API service instance
 * @returns {Object} Budget store state and methods
 */
export function createBudgetStore(apiService) {
    /**
     * Reactive state: Vue must track it, otherwise the components reading it
     * (computed properties) are never refreshed when the data arrives
     */
    const state = Vue.reactive({
        /**
         * Root budget items
         */
        budgets: [],
        
        /**
         * Currently displayed budget list (root or children)
         */
        currentList: [],
        
        /**
         * Navigation breadcrumb stack
         * @type {Array<{id: number, name: string}>}
         */
        breadcrumbStack: [],
        
        /**
         * Cached children budgets by month and parent ID ("2026-10:4"), emptied at each load
         * of the budgets: amounts change with the month and with the transactions
         * @type {Object<string, Array>}
         */
        childrenCache: {},
        
        /**
         * Loading states for children requests
         * @type {Object<number, boolean>}
         */
        loadingChildren: {},
        
        /**
         * Copy of the previous month for the displayed month (GET /budget/copy):
         * {month, source, defined, source_defined, copyable}, null while unknown
         * @type {Object|null}
         */
        copyStatus: null,

        /**
         * Main loading state
         */
        isLoading: false,
        
        /**
         * Error message
         */
        error: null
    });
    
    /**
     * Filters and sorts budget items
     * @param {Array} budgets - Raw budget array
     * @returns {Array} Filtered and sorted budget array
     */
    function filterBudgets(budgets) {
        if (!Array.isArray(budgets)) {
            console.warn('filterBudgets received non-array:', budgets);
            return [];
        }
        
        return budgets
            .filter(b => b && b.name) // Only filter out invalid items
            .sort((a, b) => parseFloat(b.progress || 0) - parseFloat(a.progress || 0));
    }

    /**
     * Key of the children of a category in the cache
     * @param {number} categoryId - Parent category
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {string}
     */
    function cacheKey(categoryId, month, year) {
        return `${year}-${month}:${categoryId}`;
    }
    
    /**
     * Number of the last request of the copy status: an older answer is ignored
     * (the month changed meanwhile)
     */
    let copyStatusRequest = 0;

    /**
     * Key of a month as returned by the API ("2026-03")
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {string}
     */
    function monthKey(month, year) {
        return `${year}-${String(month).padStart(2, '0')}`;
    }

    /**
     * Loads whether the copy of the previous month is offered for a month. The status
     * of another month is forgotten at once; a failure leaves it unknown (no button)
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<void>}
     */
    async function loadCopyStatus(month, year) {
        const request = ++copyStatusRequest;
        if (state.copyStatus?.month !== monthKey(month, year)) {
            state.copyStatus = null;
        }
        let status = null;
        try {
            status = await apiService.fetchBudgetCopyStatus(month, year);
        } catch (error) {
            console.error('Budget copy status error:', error);
        }
        if (request === copyStatusRequest) {
            state.copyStatus = status;
        }
    }

    /**
     * Loads root budget data, and whether the copy of the previous month is offered
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<void>}
     */
    async function loadRootBudgets(month, year) {
        const copyStatus = loadCopyStatus(month, year);
        state.isLoading = true;
        state.error = null;
        // The sub-categories are fetched again: their amounts may have changed (#402)
        state.childrenCache = {};
        
        try {
            const data = await apiService.fetchBudget(month, year);
            state.budgets = data;
            
            // Reset to root when loading fresh data
            state.breadcrumbStack = [];
            state.currentList = filterBudgets(data);
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error('Budget loading error:', error);
            state.error = error.message;
            state.budgets = [];
            state.currentList = [];
        } finally {
            state.isLoading = false;
        }
        await copyStatus;
    }
    
    /**
     * Navigates to budget children
     * @param {Object} item - Budget item
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<boolean>} True if navigation occurred, false if no children
     */
    async function navigateToChildren(item, month, year) {
        const categoryId = item.id;
        const key = cacheKey(categoryId, month, year);
        
        // Check cache first
        if (Array.isArray(state.childrenCache[key])) {
            const cachedList = state.childrenCache[key];
            
            if (cachedList.length === 0) {
                // No children: stay on current page
                return false;
            }
            
            state.breadcrumbStack.push({ id: categoryId, name: item.name });
            state.currentList = cachedList;
            return true;
        }
        
        // Fetch children from API
        try {
            state.loadingChildren[categoryId] = true;
            
            const children = await apiService.fetchBudget(month, year, categoryId);
            state.childrenCache[key] = children;
            
            if (children.length === 0) {
                // No children: stay on current page
                return false;
            }
            
            state.breadcrumbStack.push({ id: categoryId, name: item.name });
            state.currentList = children;
            return true;
        } catch (error) {
            console.error('Load budget children error', error);
            throw error;
        } finally {
            state.loadingChildren[categoryId] = false;
        }
    }
    
    /**
     * Navigates to a specific breadcrumb level
     * @param {number} index - Breadcrumb index (-1 for root)
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     */
    async function navigateToBreadcrumb(index, month, year) {
        // Navigate to root
        if (index < 0) {
            state.breadcrumbStack = [];
            state.currentList = filterBudgets(state.budgets);
            return;
        }
        
        // Navigate to specific breadcrumb level
        state.breadcrumbStack = state.breadcrumbStack.slice(0, index + 1);
        const target = state.breadcrumbStack[state.breadcrumbStack.length - 1];
        
        if (!target) {
            state.currentList = filterBudgets(state.budgets);
            return;
        }
        
        const cached = state.childrenCache[cacheKey(target.id, month, year)];
        if (Array.isArray(cached)) {
            state.currentList = cached;
        } else {
            // Fetch if not cached (navigateToChildren pushes the level back)
            state.breadcrumbStack.pop();
            await navigateToChildren({ id: target.id, name: target.name }, month, year);
        }
    }
    
    /**
     * Updates a budget item
     * @param {number} budgetId - Budget ID
     * @param {number} month - Month
     * @param {number} year - Year
     * @param {number} newAmount - New budget amount
     * @param {string} [note] - Reminder of what the budget covers ('' removes it)
     * @returns {Promise<void>}
     */
    async function updateBudget(budgetId, month, year, newAmount, note) {
        await apiService.updateBudget(budgetId, month, year, newAmount, note);
        // A parent depends on its sub-categories
        await reloadBudgets(month, year);
    }

    /**
     * Reloads the budgets (amounts spent included), staying on the displayed level
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<void>}
     */
    async function reloadBudgets(month, year) {
        const stack = state.breadcrumbStack.slice();
        await loadRootBudgets(month, year);
        for (const crumb of stack) {
            await navigateToChildren(crumb, month, year);
        }
    }

    /**
     * Copies the budgets of the previous month to the given month
     * @param {number} month - Target month (1-12)
     * @param {number} year - Target year
     * @param {boolean} overwrite - True to replace the budgets already set
     * @returns {Promise<Object>} {month, source, copied, overwrite}
     */
    async function copyBudgets(month, year, overwrite = false) {
        const result = await apiService.copyBudgets(month, year, overwrite);
        // A parent depends on its sub-categories
        await reloadBudgets(month, year);
        return result;
    }
    
    /**
     * Aborts all pending requests
     */
    function abortAll() {
        apiService.abortAllExcept('none');
    }
    
    return {
        state,
        loadRootBudgets,
        loadCopyStatus,
        navigateToChildren,
        navigateToBreadcrumb,
        updateBudget,
        reloadBudgets,
        copyBudgets,
        filterBudgets,
        abortAll
    };
}
