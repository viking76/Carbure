/**
 * Changes Module
 *
 * Reloads the open tab when the data it shows changed on the server (another member
 * of the household, a synchronization). Every answer of the API carries the versions
 * of the data (X-Carbure-Changes header, one version per kind): they are compared at
 * each call the portal makes anyway, without polling. When the page comes back to the
 * foreground, GET /api/changes asks them once. Only the tab whose kinds moved is
 * reloaded; the other tabs reload their data when they are opened. A write of the
 * portal names the kinds it bumped (X-Carbure-Changed): it reloads nothing more.
 *
 * @module changesModule
 */

/** Delay before reloading: the answers of the same loading arrive together (ms) */
export const RELOAD_DELAY = 300;

/**
 * Kinds of data shown by each tab: the insights and the trends are computed from the
 * transactions and the budgets; the custom charts of the trends are a setting
 */
export const TAB_KINDS = {
    transactions: ['transactions', 'categories', 'accounts'],
    budget: ['budgets', 'transactions', 'categories'],
    insights: ['insights', 'transactions', 'budgets', 'categories'],
    trends: ['transactions', 'budgets', 'categories', 'settings'],
    rules: ['categories'],
    categories: ['categories'],
    accounts: ['accounts'],
    sync: ['accounts'],
    config: ['settings']
};

/**
 * Versions of the X-Carbure-Changes header ("budgets=1835, transactions=1836")
 * @param {string|null} header - Value of the header
 * @returns {Object<string, number>} Version by kind (empty without the header)
 */
export function parseChangesHeader(header) {
    const versions = {};
    for (const part of (header || '').split(',')) {
        const [kind, version] = part.trim().split('=');
        if (kind && /^\d+$/.test(version || '')) {
            versions[kind] = Number(version);
        }
    }
    return versions;
}

/**
 * Kinds whose version moved
 * @param {Object<string, number>} known - Versions of the data loaded
 * @param {Object<string, number>} current - Versions of the server
 * @returns {string[]} The kinds that changed since they were loaded
 */
export function movedKinds(known, current) {
    return Object.keys(current).filter(kind => known[kind] !== current[kind]);
}

/**
 * Versions known after a write of the portal: a kind it bumped by one is up to date
 * (the portal reloads it after its write); a kind bumped more was also changed by
 * someone else meanwhile, and stays to be reloaded
 * @param {Object<string, number>} known - Versions of the data loaded
 * @param {Object<string, number>} current - Versions of the server
 * @param {string[]} written - Kinds bumped by the write (X-Carbure-Changed)
 * @returns {Object<string, number>} The versions known
 */
export function afterOwnWrite(known, current, written) {
    const versions = { ...known };
    for (const kind of written) {
        if (versions[kind] === current[kind] - 1) {
            versions[kind] = current[kind];
        }
    }
    return versions;
}

/**
 * Creates the changes module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the data and methods of the change markers
 */
export function createChangesModule(getApiService) {
    // Not reactive: nothing is displayed from them
    let known = null;
    let onVisible = null;
    let reload = null;

    return {
        methods: {
            /**
             * Starts watching the changes of the session: the versions come with the
             * answers of the API, and are asked when the page comes back
             */
            startChangeWatch() {
                this.stopChangeWatch();
                onVisible = () => {
                    if (!document.hidden) {
                        this.checkChanges();
                    }
                };
                document.addEventListener('visibilitychange', onVisible);
            },

            /**
             * Stops watching the changes (logout)
             */
            stopChangeWatch() {
                if (onVisible) {
                    document.removeEventListener('visibilitychange', onVisible);
                }
                clearTimeout(reload);
                known = null;
                onVisible = null;
                reload = null;
            },

            /**
             * Versions of an answer of the API: the open tab is reloaded if the data it
             * shows moved. Not while a modal is open (a form being filled): the versions
             * stay to be compared with the next answer
             * @param {Object<string, number>} current - Versions of the server (X-Carbure-Changes)
             * @param {string[]} written - Kinds bumped by this request (X-Carbure-Changed)
             */
            serverChanges(current, written = []) {
                if (!this.isAuthenticated || !Object.keys(current).length) {
                    return;
                }
                if (known === null) {
                    known = current;
                    return;
                }
                if (document.querySelector('.modal-overlay')) {
                    known = afterOwnWrite(known, current, written);
                    return;
                }
                const moved = movedKinds(afterOwnWrite(known, current, written), current);
                known = current;
                if (moved.includes('transactions')) {
                    this.loadPeriods();
                }
                if (moved.some(kind => (TAB_KINDS[this.activeTab] || []).includes(kind))) {
                    // Once the answers of the loading in progress are read
                    clearTimeout(reload);
                    reload = setTimeout(() => this.refreshCurrentTab(), RELOAD_DELAY);
                }
            },

            /**
             * Asks the versions (back to the page): GET /changes, its answer carries them
             * @returns {Promise<void>}
             */
            async checkChanges() {
                if (!this.isAuthenticated) {
                    return;
                }
                try {
                    if (await getApiService().fetchChanges() === null) {
                        // Server without change markers (older version): nothing to watch
                        this.stopChangeWatch();
                    }
                } catch (error) {
                    // Offline, server restarting: the next answer
                }
            }
        }
    };
}
