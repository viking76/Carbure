/**
 * Manual Module
 *
 * Transactions entered by hand on any account: a manual one (cash, a bank woob does not
 * support, added by an administrator in the Accounts tab) or a synchronized one (a
 * transaction the bank does not send yet). Added from the Transactions tab by any user,
 * changed later (date, label, amount, comment).
 *
 * @module manualModule
 */

/** bank_name of the manual accounts (Bank::MANUAL) */
export const MANUAL = 'manual';

/** Start of the UUID of a transaction entered by hand (Transaction::MANUAL_UUID) */
export const MANUAL_UUID = 'manual';

/**
 * Whether a transaction was entered by hand, and can be changed (not a part of a split
 * one): its UUID starts with MANUAL_UUID, or it is on a manual account (entered before)
 * @param {Object} transaction - Transaction
 * @returns {boolean}
 */
export function isManual(transaction) {
    return !!transaction && transaction.split_of == null
        && (transaction.bank_name === MANUAL || String(transaction.uuid ?? '').startsWith(MANUAL_UUID));
}

/**
 * Accounts a transaction can be entered on: the manual ones first, then the
 * synchronized ones (each list in its order)
 * @param {Array<{manual?: boolean}>} accounts - Followed accounts
 * @returns {Array<Object>}
 */
export function entryAccounts(accounts) {
    const all = accounts || [];
    return [...all.filter(a => a.manual), ...all.filter(a => !a.manual)];
}

/**
 * Date of a day as the API takes it (YYYY-MM-DD), in local time
 * @param {Date} date - The day
 * @returns {string}
 */
export function isoDay(date) {
    const pad = n => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/**
 * Values sent to the API for the form: the amount typed without sign takes the one of
 * the direction (expense or income); a comma is a decimal separator. The comment is sent
 * trimmed when there is one
 * @param {{account: string|number, date: string, label: string, amount: string|number, direction: string, category: string|number, comment?: string}} form - The form
 * @returns {{account: number, date: string, label: string, amount: number, category?: number, comment?: string}}
 */
export function manualPayload(form) {
    const amount = Math.abs(Number(String(form.amount ?? '').trim().replace(',', '.'))) || 0;
    const payload = {
        account: Number(form.account),
        date: form.date,
        label: String(form.label || '').trim(),
        amount: form.direction === 'income' ? amount : -amount
    };
    if (form.category !== '' && form.category !== null && form.category !== undefined) {
        payload.category = Number(form.category);
    }
    const comment = String(form.comment ?? '').trim();
    if (comment !== '') {
        payload.comment = comment;
    }
    return payload;
}

/** Form of a transaction entered by hand, empty */
const EMPTY_FORM = { id: null, account: '', date: '', label: '', amount: '', direction: 'expense', category: '', comment: '' };

/**
 * Creates the manual module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin
 */
export function createManualModule(getApiService) {
    return {
        data() {
            return {
                // Transaction being entered or changed (modal): its id is null for a new one
                showManualModal: false,
                manualForm: { ...EMPTY_FORM },
                // Accounts of the form: the manual ones first
                manualAccounts: [],
                savingManual: false
            };
        },

        computed: {
            /**
             * Whether the account chosen for a new transaction is a synchronized one: the
             * bank may send the same transaction later
             * @returns {boolean}
             */
            manualAccountSynced() {
                const account = this.manualAccounts.find(a => a.id === this.manualForm.account);
                return !!account && !account.manual;
            },

            /**
             * Whether the form can be sent: an account (new one), a date, a label and an amount
             * @returns {boolean}
             */
            manualValid() {
                const form = this.manualForm;
                return (form.id !== null || form.account !== '') && /^\d{4}-\d{2}-\d{2}$/.test(form.date)
                    && String(form.label).trim() !== '' && manualPayload(form).amount !== 0;
            }
        },

        methods: {
            isManual,

            /**
             * Loads the accounts of the household, the manual ones first
             * @returns {Promise<void>}
             */
            async loadManualAccounts() {
                try {
                    this.manualAccounts = entryAccounts(await getApiService().fetchAccounts());
                } catch (error) {
                    this.manualAccounts = [];
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Opens the form of a new transaction: today, on the first manual account (else
             * the first account)
             * @returns {Promise<void>}
             */
            async openManualTransaction() {
                this.manualForm = { ...EMPTY_FORM, date: isoDay(new Date()) };
                this.showManualModal = true;
                await this.loadManualAccounts();
                if (this.manualAccounts.length) {
                    this.manualForm.account = this.manualAccounts[0].id;
                }
                this.$nextTick?.(() => document.querySelector('.modal-manual input, .modal-manual button.btn-add')?.focus());
            },

            /**
             * Opens the form of a transaction entered by hand, from its detail
             * @param {Object} transaction - Transaction
             */
            editManualTransaction(transaction) {
                const amount = Number(transaction.amount);
                this.manualForm = {
                    ...EMPTY_FORM,
                    id: transaction.id,
                    date: transaction.date,
                    label: transaction.label,
                    amount: Math.abs(amount).toFixed(2),
                    direction: amount < 0 ? 'expense' : 'income',
                    comment: transaction.comment || ''
                };
                this.transactionDetail = null;
                this.showManualModal = true;
                this.$nextTick?.(() => document.querySelector('.modal-manual input')?.focus());
            },

            /** Closes the form */
            closeManualModal() {
                this.showManualModal = false;
            },

            /**
             * Saves the transaction of the form, then reloads the transactions
             * @returns {Promise<void>}
             */
            async saveManualTransaction() {
                const form = this.manualForm;
                const payload = manualPayload(form);
                this.savingManual = true;
                try {
                    if (form.id === null) {
                        await getApiService().createTransaction(payload);
                    } else {
                        // The comment is always sent: '' removes it
                        await getApiService().updateTransaction({ id: form.id, date: payload.date, label: payload.label, amount: payload.amount,
                            comment: payload.comment ?? '' });
                    }
                    this.showManualModal = false;
                    this.showToast(this.t(form.id === null ? 'manualTransactionAdded' : 'transactionSaved'));
                    this.loadPeriods();
                    this.loadTransactions(this.selectedMonth, this.selectedYear);
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingManual = false;
                }
            }
        }
    };
}
