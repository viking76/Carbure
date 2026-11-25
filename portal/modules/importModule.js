/**
 * Import Module
 *
 * Import of the statement files downloaded from the banks (OFX/QFX, QIF, CAMT.053,
 * CSV), from the transactions tab: the file is read by the server, which tells for
 * each transaction whether it is new, already known or a probable duplicate; the
 * user chooses the transactions to import.
 *
 * @module importModule
 */

/** Largest file sent (bytes): the limit of the server (BankFile::MAX_SIZE) */
export const IMPORT_MAX_SIZE = 1000000;

/** Extensions offered by the file picker */
export const IMPORT_ACCEPT = '.ofx,.qfx,.qif,.csv,.txt,.xml';

/**
 * Base64 of the bytes of a file
 * @param {ArrayBuffer} buffer - Content of the file
 * @returns {string}
 */
export function toBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    // By chunks: String.fromCharCode cannot take a whole file as arguments
    for (let i = 0; i < bytes.length; i += 0x8000) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    }
    return btoa(binary);
}

/**
 * Account offered for the transactions of a file: the account of the household whose
 * number is the one read in the file, else none ('': the number of the file is kept)
 * @param {{account?: string|null}|null} preview - Answer of POST /transaction/import/preview
 * @param {Array<{id: number, account_number: string}>} accounts - Accounts of the household
 * @returns {number|string} Id of the account, or ''
 */
export function importAccountOf(preview, accounts) {
    const number = preview && preview.account ? String(preview.account).replace(/\s/g, '') : '';
    const account = number ? (accounts || []).find(a => String(a.account_number).replace(/\s/g, '') === number) : null;
    return account ? account.id : '';
}

/**
 * Creates the import module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the import data and methods
 */
export function createImportModule(getApiService) {
    return {
        data() {
            return {
                showImport: false,
                importAccept: IMPORT_ACCEPT,
                // File chosen: {name, content (base64)}
                importFile: null,
                // Answer of POST /transaction/import/preview
                importPreview: null,
                // Rows to import, by index
                importSelection: {},
                // Account of the imported transactions (id of GET /bank), '' for the one of the file
                importAccountId: '',
                importLoading: false,
                importing: false,
                importError: null,
                importDragOver: false
            };
        },

        computed: {
            /**
             * Rows of the preview, the most recent first
             * @returns {Array}
             */
            importRows() {
                return this.importPreview ? [...this.importPreview.rows].sort((a, b) => b.date.localeCompare(a.date) || a.index - b.index) : [];
            },

            /**
             * Number of rows selected
             * @returns {number}
             */
            importSelectedCount() {
                return Object.values(this.importSelection).filter(Boolean).length;
            },

            /**
             * Rows that can be selected (the known ones would be merged)
             * @returns {Array}
             */
            importSelectableRows() {
                return this.importRows.filter(row => row.status !== 'known');
            },

            /**
             * Whether all the rows that can be selected are
             * @returns {boolean}
             */
            importAllSelected() {
                return this.importSelectableRows.length > 0 && this.importSelectableRows.every(row => this.importSelection[row.index]);
            }
        },

        methods: {
            /** Opens the import window, with the accounts of the household to choose from */
            openImport() {
                this.resetImport();
                this.showImport = true;
                this.loadManualAccounts();
            },

            /** Closes the import window */
            closeImport() {
                if (!this.importing) {
                    this.showImport = false;
                    this.resetImport();
                }
            },

            /** Back to the choice of the file */
            resetImport() {
                this.importFile = null;
                this.importPreview = null;
                this.importSelection = {};
                this.importAccountId = '';
                this.importError = null;
                this.importDragOver = false;
            },

            /**
             * File chosen with the picker
             * @param {Event} event - change event of the file input
             */
            onImportPick(event) {
                const file = event.target.files && event.target.files[0];
                event.target.value = '';
                if (file) {
                    this.readImportFile(file);
                }
            },

            /**
             * File dropped on the drop zone
             * @param {DragEvent} event - drop event
             */
            onImportDrop(event) {
                this.importDragOver = false;
                const file = event.dataTransfer && event.dataTransfer.files[0];
                if (file) {
                    this.readImportFile(file);
                }
            },

            /**
             * Reads a file and asks the server for the preview of its import
             * @param {File} file - Statement file
             * @returns {Promise<void>}
             */
            async readImportFile(file) {
                this.resetImport();
                if (file.size > IMPORT_MAX_SIZE) {
                    this.importError = this.t('importTooLarge', { size: Math.round(IMPORT_MAX_SIZE / 1000) });
                    return;
                }
                this.importLoading = true;
                try {
                    const content = toBase64(await file.arrayBuffer());
                    this.importFile = { name: file.name, content };
                    this.importPreview = await getApiService().previewImport(content, file.name);
                    // The new transactions are selected; the probable duplicates are not
                    const selection = {};
                    for (const row of this.importPreview.rows) {
                        selection[row.index] = row.status === 'new';
                    }
                    this.importSelection = selection;
                    // The accounts asked when the window opened may still be loading
                    if (!this.manualAccounts.length) {
                        await this.loadManualAccounts();
                    }
                    this.importAccountId = importAccountOf(this.importPreview, this.manualAccounts);
                } catch (err) {
                    this.importFile = null;
                    this.importError = this.apiErrorText(err);
                } finally {
                    this.importLoading = false;
                }
            },

            /**
             * Selects or unselects a row
             * @param {Object} row - Row of the preview
             */
            toggleImportRow(row) {
                if (row.status !== 'known') {
                    this.importSelection = { ...this.importSelection, [row.index]: !this.importSelection[row.index] };
                }
            },

            /** Selects all the rows that can be, or none */
            toggleImportAll() {
                const value = !this.importAllSelected;
                const selection = { ...this.importSelection };
                for (const row of this.importSelectableRows) {
                    selection[row.index] = value;
                }
                this.importSelection = selection;
            },

            /**
             * Imports the selected rows, then shows the transactions of the last month imported
             * @returns {Promise<void>}
             */
            async confirmImport() {
                if (!this.importFile || !this.importSelectedCount) {
                    return;
                }
                this.importing = true;
                this.importError = null;
                try {
                    const selected = Object.keys(this.importSelection).filter(index => this.importSelection[index]).map(Number);
                    const account = this.importAccountId === '' ? null : Number(this.importAccountId);
                    const result = await getApiService().importTransactions(this.importFile.content, this.importFile.name, selected, account);
                    this.importing = false;
                    this.closeImport();
                    this.showToast(this.t('importDone', { count: result.imported, categorized: result.categorized }), 4000);
                    if (result.to) {
                        const [year, month] = result.to.split('-').map(Number);
                        this.selectedYear = year;
                        this.selectedMonth = month;
                    }
                    this.loadPeriods();
                    this.loadTransactions(this.selectedMonth, this.selectedYear);
                } catch (err) {
                    this.importError = this.apiErrorText(err);
                } finally {
                    this.importing = false;
                }
            },

            /**
             * Label of the format of a file
             * @param {string} format - ofx, qif, camt or csv
             * @returns {string}
             */
            importFormatLabel(format) {
                return { ofx: 'OFX', qif: 'QIF', camt: 'CAMT.053', csv: 'CSV' }[format] || format;
            },

            /**
             * How a CSV file was read: with the profile of its bank, or its columns guessed
             * @param {Object} preview - Answer of POST /transaction/import/preview
             * @returns {string|null} The text, null for another format
             */
            importBankLabel(preview) {
                if (!preview || preview.format !== 'csv') {
                    return null;
                }
                return preview.bank
                    ? this.t('importBankRecognized', { bank: preview.bank.name })
                    : this.t('importBankGeneric');
            }
        }
    };
}
