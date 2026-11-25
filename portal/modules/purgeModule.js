/**
 * Purge Module
 *
 * Administration > Purge (administrators): deletes every row of the chosen datasets
 * (POST /purge) to start again from scratch, after a confirmation. The datasets that
 * refer to a chosen one are ticked with it and cannot be unticked (GET /purge gives
 * them). The server refuses the purge from Internet: the tab then says it.
 *
 * @module purgeModule
 */

/**
 * Datasets chosen with their dependents: each chosen dataset adds the ones that refer to it
 * @param {Array<{name: string, dependents: Array<string>}>} datasets - Datasets of GET /purge
 * @param {Object} chosen - {name: true} chosen by the administrator
 * @returns {Set<string>} Names of the datasets purged
 */
export function purgeSelection(datasets, chosen) {
    const names = new Set();
    for (const dataset of datasets) {
        if (chosen[dataset.name]) {
            names.add(dataset.name);
            (dataset.dependents || []).forEach(name => names.add(name));
        }
    }
    return names;
}

/**
 * Creates the purge mixin for Vue components
 * @param {Function} getApiService - Returns the API service
 * @returns {Object} Vue mixin
 */
export function createPurgeModule(getApiService) {
    return {
        data() {
            return {
                // Datasets {name, count, dependents} and origin of the request (GET /purge)
                purgeDatasets: [],
                purgeLocal: true,
                loadingPurge: false,
                // Datasets ticked by the administrator: {name: true}
                purgeChosen: {},
                purgeError: null
            };
        },

        computed: {
            /**
             * Names of the datasets purged: the ones ticked and their dependents
             * @returns {Set<string>}
             */
            purgeSelected() {
                return purgeSelection(this.purgeDatasets, this.purgeChosen);
            },

            /**
             * Datasets ticked because a chosen one refers to them (cannot be unticked)
             * @returns {Set<string>}
             */
            purgeForced() {
                const chosen = Object.fromEntries(Object.entries(this.purgeChosen).filter(([, value]) => value));
                const forced = new Set();
                for (const dataset of this.purgeDatasets) {
                    if (chosen[dataset.name]) {
                        (dataset.dependents || []).forEach(name => forced.add(name));
                    }
                }
                return forced;
            },

            /**
             * Number of rows the purge deletes
             * @returns {number}
             */
            purgeRowCount() {
                return this.purgeDatasets.filter(dataset => this.purgeSelected.has(dataset.name))
                    .reduce((sum, dataset) => sum + dataset.count, 0);
            }
        },

        methods: {
            /**
             * Loads the datasets and their number of rows
             * @returns {Promise<void>}
             */
            async loadPurgeInfo() {
                this.loadingPurge = true;
                this.purgeError = null;
                try {
                    const info = await getApiService().fetchPurgeInfo();
                    this.purgeDatasets = info.datasets;
                    this.purgeLocal = info.local;
                } catch (err) {
                    this.purgeError = this.apiErrorText(err);
                } finally {
                    this.loadingPurge = false;
                }
            },

            /**
             * Ticks or unticks a dataset (not a dependent of a chosen one)
             * @param {string} name - The dataset
             * @param {boolean} value - Ticked
             */
            togglePurgeDataset(name, value) {
                this.purgeChosen = { ...this.purgeChosen, [name]: value };
            },

            /**
             * Asks the confirmation, then purges the selected datasets
             * @returns {Promise<void>}
             */
            async confirmPurge() {
                const names = this.purgeDatasets.map(dataset => dataset.name).filter(name => this.purgeSelected.has(name));
                if (!names.length || !this.purgeLocal) {
                    return;
                }
                await this.askConfirm({
                    title: this.t('purgeConfirmTitle'),
                    message: this.t('purgeConfirm', { datasets: names.map(name => this.backupDatasetLabel(name)).join(', ') }),
                    warning: this.t('purgeConfirmWarning', { count: this.purgeRowCount }),
                    confirmLabel: this.t('purgeButton'),
                    busyLabel: this.t('purging'),
                    icon: 'delete_forever',
                    danger: true,
                    action: () => this.runPurge(names)
                });
            },

            /**
             * Sends the purge, then reloads what depends on it
             * @param {Array<string>} names - Datasets to purge
             * @returns {Promise<void>}
             * @throws {Error} If the server refuses it (shown in the confirmation)
             */
            async runPurge(names) {
                const result = await getApiService().purgeData(names);
                const total = Object.values(result.purged || {}).reduce((sum, count) => sum + count, 0);
                this.purgeChosen = {};
                this.showToast(this.t('purgeDone', { count: total }), 4000);
                this.loadPurgeInfo();
                this.loadCategories();
                this.loadPeriods();
            }
        }
    };
}
