/**
 * Accounts Module
 *
 * Bank accounts of the household (administrators), added in two steps:
 * 1. connect a bank (a woob backend): its credentials are given to woob, never
 *    stored by Carbure; several connections of the same bank are allowed, each
 *    with a name given in Carbure (its woob name, bnp_2..., is generated);
 * 2. add accounts to a connected bank: only the accounts woob lists for it
 *    (bank list -b <backend>) can be followed. A bank that asks a two-factor
 *    authentication is listed interactively: the administrator approves the
 *    connection in the bank app while woob waits (#299).
 * Followed accounts: list with the result of the last synchronization, label
 * modified in a modal, delete.
 *
 * @module accountsModule
 */

/**
 * Followed accounts grouped under their bank connection, in the order of the
 * connections, then the accounts of no configured connection (removed connection,
 * imported file) in a last group; a connection without followed account keeps its group.
 * The manual accounts are listed apart (manualAccountsOf()).
 * @param {Array<{name: string}>} backends - Banks connected in woob
 * @param {Array<{bank_name: string}>} accounts - Followed accounts (their order is kept)
 * @returns {Array<{key: string, backend: Object|null, accounts: Array}>} The groups
 */
export function accountsByBank(backends, accounts) {
    const groups = (backends || []).map(backend => ({ key: `bank-${backend.name}`, backend, accounts: [] }));
    const byName = new Map(groups.map(group => [group.backend.name, group]));
    const others = { key: 'others', backend: null, accounts: [] };
    for (const account of (accounts || []).filter(a => !a.manual)) {
        (byName.get(account.bank_name) || others).accounts.push(account);
    }
    return others.accounts.length ? [...groups, others] : groups;
}

/**
 * Manual accounts among the followed ones: never synchronized, their transactions
 * entered by hand
 * @param {Array<{manual?: boolean}>} accounts - Followed accounts
 * @returns {Array<Object>}
 */
export function manualAccountsOf(accounts) {
    return (accounts || []).filter(a => a.manual);
}

/** Configuration in woob of a bank being connected, empty */
// Last choice of the bank list of the modal adding accounts: the connection of a new bank
// (never a woob backend name, made of letters, digits and _)
export const NEW_BANK = '+new';

const EMPTY_SETUP = { module: '', backend: '', label: '', description: '', fields: [], values: {}, loading: false, busy: false, error: null };

/** Update of the woob bank modules, closed */
const EMPTY_WOOB_UPDATE = { open: false, loading: false, status: null, running: false, lines: [], error: null, done: false };

/**
 * Creates an accounts module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with accounts data and methods
 */
export function createAccountsModule(getApiService) {
    return {
        data() {
            return {
                // Label of a followed account, modified in a modal
                showAccountModal: false,
                savingAccount: false,
                // Account of the modal: {id, account_number, bank_name, label, manual}; a new
                // manual account has no id
                accountForm: { id: null, account_number: '', bank_name: '', label: '', manual: false },
                // Banks connected in woob (backends): [{name, module, label}]
                bankBackends: [],
                // Name of a bank connection, modified in a modal: {name, module, label}
                showBackendModal: false,
                savingBackend: false,
                backendForm: { name: '', module: '', label: '' },
                // Banks supported by woob: [{module, description}], or why woob gave none
                bankModules: [],
                bankModulesError: null,
                // Step 1: connect a bank (woob module chosen in the modal)
                showConnectModal: false,
                connectModule: '',
                bankSetup: { ...EMPTY_SETUP },
                // Step 2: accounts woob lists for one connected bank, to follow
                showAddAccountsModal: false,
                addAccountsBackend: '',
                // Opened from the + of the synchronized accounts: the bank is chosen in the modal
                addAccountsPicking: false,
                discoveredAccounts: [],
                selectedAccounts: [],
                discovering: false,
                discoverError: null,
                // The bank asks a two-factor authentication, and its last message
                discoverTwoFactor: false,
                discoverMessage: null,
                // Interactive listing of the accounts, stopped when the modal is closed
                discoverAbort: null,
                followingAccounts: false,
                // Update of the woob bank modules: repository {repository, trusted, fingerprint,
                // error}, its keyring trusted by the administrator, lines written by woob
                woobUpdate: { ...EMPTY_WOOB_UPDATE }
            };
        },

        computed: {
            /**
             * Followed accounts under their bank connection (Accounts page)
             * @returns {Array<{key: string, backend: Object|null, accounts: Array}>}
             */
            bankGroups() {
                return accountsByBank(this.bankBackends, this.bankAccounts);
            },

            /**
             * Manual accounts of the household (never synchronized)
             * @returns {Array<Object>}
             */
            manualBankAccounts() {
                return manualAccountsOf(this.bankAccounts);
            },

            /**
             * Whether an account can be synchronized (not a manual one)
             * @returns {boolean}
             */
            hasSyncedAccount() {
                return this.bankAccounts.some(a => !a.manual);
            }
        },

        watch: {
            /**
             * A bank chosen in the connection modal: load the settings it asks for
             */
            connectModule() {
                if (this.showConnectModal && this.connectModule) {
                    this.loadBankSetup(this.connectModule);
                } else {
                    this.bankSetup = { ...EMPTY_SETUP };
                }
            }
        },

        methods: {
            /**
             * Loads the settings asked by a woob module (login, password...)
             * @param {string} module - woob module
             * @returns {Promise<void>}
             */
            async loadBankSetup(module) {
                this.bankSetup = { module, backend: this.freeBackendName(module), description: '', fields: [], values: {}, loading: true, busy: false, error: null };
                try {
                    const info = await getApiService().fetchBankModuleFields(module);
                    if (this.bankSetup.module !== module) {
                        return;
                    }
                    const values = {};
                    info.fields.forEach(f => { values[f.key] = f.default || (f.choices.length ? f.choices[0].value : ''); });
                    this.bankSetup = { ...this.bankSetup, description: info.description, fields: info.fields, values, loading: false };
                } catch (error) {
                    this.bankSetup = { ...this.bankSetup, loading: false, error: this.apiErrorText(error) };
                }
            },

            /**
             * Opens the modal connecting a bank (step 1)
             */
            openConnectModal() {
                this.connectModule = '';
                this.bankSetup = { ...EMPTY_SETUP };
                this.showConnectModal = true;
            },

            /**
             * Closes the modal connecting a bank (the credentials typed are forgotten)
             */
            closeConnectModal() {
                this.showConnectModal = false;
                this.connectModule = '';
                this.bankSetup = { ...EMPTY_SETUP };
            },

            /**
             * Configures the bank in woob, then shows its accounts to follow (step 2)
             * @returns {Promise<void>}
             */
            async connectBank() {
                const setup = this.bankSetup;
                const missing = setup.fields.find(f => f.required && !String(setup.values[f.key] || '').trim());
                if (missing) {
                    this.bankSetup = { ...setup, error: this.t('bankSettingRequired', { field: missing.label }) };
                    return;
                }
                // Format expected by the bank module (e.g. a 6-digit code)
                const invalid = setup.fields.find(f => {
                    const value = String(setup.values[f.key] || '');
                    try {
                        return f.regexp && value !== '' && !new RegExp(f.regexp).test(value);
                    } catch (e) {
                        return false;
                    }
                });
                if (invalid) {
                    this.bankSetup = { ...setup, error: this.t('bankSettingFormat', { field: invalid.label }) };
                    return;
                }
                this.bankSetup = { ...setup, busy: true, error: null };
                try {
                    const result = await getApiService().createBankBackend(setup.module, setup.backend, setup.values, String(setup.label || '').trim());
                    // The credentials leave the page as soon as woob has them
                    this.closeConnectModal();
                    await this.loadBankBackends();
                    // Configured now, even if woob lists it a bit later
                    if (!this.bankBackends.some(b => b.name === result.backend)) {
                        this.bankBackends = [...this.bankBackends, { name: result.backend, module: setup.module, label: result.label || null }];
                    }
                    if (result.two_factor) {
                        // Its accounts once the connection is approved in the bank app
                        this.showToast(this.t('bankConnected2fa'));
                        this.openAddAccounts(result.backend, null, true);
                        return;
                    }
                    this.showToast(this.t(result.accounts.length ? 'bankConnected' : 'bankConnectedNoAccount'));
                    this.openAddAccounts(result.backend, result.accounts);
                } catch (error) {
                    this.bankSetup = { ...this.bankSetup, busy: false, error: this.apiErrorText(error) };
                }
            },

            /**
             * Loads the banks configured in woob (for the bank selector)
             * @returns {Promise<void>}
             */
            async loadBankBackends() {
                const api = getApiService();
                // Each list stays empty if woob does not answer
                const [backends, modules] = await Promise.allSettled([api.fetchBankBackends(), api.fetchBankModules()]);
                this.bankBackends = backends.status === 'fulfilled' ? backends.value : [];
                this.bankModules = modules.status === 'fulfilled' ? modules.value : [];
                // Why no bank can be chosen (e.g. woob repository to trust first)
                this.bankModulesError = modules.status === 'rejected' ? this.apiErrorText(modules.reason) : null;
            },

            /**
             * Supported banks, to connect a login: every one, even if already configured
             * (another login of the same bank, e.g. a joint or professional one)
             * @returns {Array<{module: string, description: string, configured: boolean}>}
             */
            otherBankModules() {
                const configured = new Set(this.bankBackends.map(b => b.module));
                return this.bankModules.map(m => ({ ...m, configured: configured.has(m.module) }));
            },

            /**
             * First backend name not used in woob for a module: the module itself, then
             * module_2, module_3...
             * @param {string} module - woob module
             * @returns {string}
             */
            freeBackendName(module) {
                const used = new Set(this.bankBackends.map(b => b.name));
                let name = module;
                for (let n = 2; used.has(name); n++) {
                    name = `${module}_${n}`;
                }
                return name;
            },

            /**
             * Connected bank of a woob backend name (unknown: named after its module)
             * @param {string} backend - Backend name
             * @returns {{name: string, module: string, label: (string|null)}}
             */
            findBackend(backend) {
                return (this.bankBackends || []).find(b => b.name === backend) || { name: backend, module: backend, label: null };
            },

            /**
             * Module of a woob backend (the backend is named after it unless renamed)
             * @param {string} backend - Backend name
             * @returns {string}
             */
            backendModule(backend) {
                return this.findBackend(backend).module;
            },

            /**
             * Opens the modal naming a connected bank
             * @param {{name: string, module: string, label: (string|null)}} backend - Backend of woob
             */
            openBackendModal(backend) {
                this.backendForm = { name: backend.name, module: backend.module, label: backend.label || '' };
                this.showBackendModal = true;
            },

            /**
             * Closes the modal naming a connected bank
             */
            closeBackendModal() {
                this.showBackendModal = false;
            },

            /**
             * Saves the name of the bank connection of the modal (empty: removed)
             * @returns {Promise<void>}
             */
            async submitBackendForm() {
                const form = this.backendForm;
                this.savingBackend = true;
                try {
                    const result = await getApiService().renameBankBackend(form.name, String(form.label || '').trim());
                    this.bankBackends = this.bankBackends.map(b => b.name === form.name ? { ...b, label: result.label } : b);
                    this.showToast(this.t('bankConnectionRenamed'));
                    this.showBackendModal = false;
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingBackend = false;
                }
            },

            /**
             * Removes a connected bank after confirmation: woob forgets its credentials, its
             * followed accounts are no longer followed (their transactions are kept). The
             * confirmation modal stays open, with a loader, until woob has removed it; an
             * error is shown in the modal
             * @param {{name: string, module: string, label: (string|null)}} backend - Backend of woob
             * @returns {Promise<void>}
             */
            async removeBackend(backend) {
                const count = this.bankAccounts.filter(a => a.bank_name === backend.name).length;
                const removed = await this.askConfirm({
                    title: this.t('deleteBankConnection'),
                    message: this.t('confirmDeleteBankConnection', { bank: this.backendName(backend), count }),
                    confirmLabel: this.t('bankConnectionDeleteButton'),
                    busyLabel: this.t('bankConnectionDeleting'),
                    icon: 'delete',
                    danger: true,
                    action: () => getApiService().deleteBankBackend(backend.name)
                });
                if (!removed) {
                    return;
                }
                this.showToast(this.t('bankConnectionDeleted'));
                await Promise.all([this.loadBankBackends(), this.loadBankAccounts()]);
            },

            /**
             * Opens the update of the woob bank modules: started at once if the repository
             * of woob is trusted, else its fingerprint is shown to be trusted first
             * @returns {Promise<void>}
             */
            async openWoobUpdate() {
                this.woobUpdate = { ...EMPTY_WOOB_UPDATE, open: true, loading: true };
                try {
                    const status = await getApiService().fetchWoobStatus();
                    this.woobUpdate = { ...this.woobUpdate, loading: false, status };
                    if (status.trusted) {
                        await this.runWoobUpdate();
                    }
                } catch (error) {
                    this.woobUpdate = { ...this.woobUpdate, loading: false, error: this.apiErrorText(error) };
                }
            },

            /**
             * Updates the woob bank modules, trusting the keyring of the repository whose
             * fingerprint the administrator compared (woob must show exactly this one)
             * @param {string|null} fingerprint - Fingerprint trusted, null if already trusted
             * @returns {Promise<void>}
             */
            async runWoobUpdate(fingerprint = null) {
                this.woobUpdate = { ...this.woobUpdate, running: true, lines: [], error: null, done: false };
                try {
                    await getApiService().updateWoob(line => this.onWoobUpdateLine(line), fingerprint);
                } catch (error) {
                    this.woobUpdate = { ...this.woobUpdate, error: this.apiErrorText(error) };
                }
                this.woobUpdate = { ...this.woobUpdate, running: false };
                if (this.woobUpdate.done) {
                    this.showToast(this.t('woobUpdated'));
                    // The banks woob supports, once its repository is trusted
                    await this.loadBankBackends();
                }
            },

            /**
             * A line of the update of woob: shown, its end understood
             * @param {string} line - Line written by woob, or the end of the update
             */
            onWoobUpdateLine(line) {
                const update = { ...this.woobUpdate, lines: [...this.woobUpdate.lines, line] };
                const untrusted = line.match(/^Woob repository not trusted: ([0-9a-f]{40})$/);
                if (line === 'Woob updated') {
                    update.done = true;
                    update.status = { ...(update.status || {}), trusted: true };
                } else if (untrusted) {
                    // The keyring woob shows: to compare before trusting it
                    update.status = { ...(update.status || {}), trusted: false, fingerprint: untrusted[1] };
                    update.error = this.t('woobNotTrusted');
                } else if (line.startsWith('Woob update failed: ')) {
                    update.error = line.slice('Woob update failed: '.length);
                }
                this.woobUpdate = update;
            },

            /**
             * Closes the update of woob (not while woob runs)
             */
            closeWoobUpdate() {
                if (!this.woobUpdate.running) {
                    this.woobUpdate = { ...EMPTY_WOOB_UPDATE };
                }
            },

            /**
             * Checks if an account found by woob is followed (from the current list)
             * @param {Object} discovered - Account found by woob
             * @returns {boolean}
             */
            isFollowed(discovered) {
                return Boolean(discovered.followed) || this.bankAccounts.some(a => a.bankId === discovered.bankId);
            },

            /**
             * Name of a connection: the name given to it, else the name of its bank,
             * without its woob name (shown next to it)
             * @param {{name: string, module: string, label: (string|null)}} backend - Backend of woob
             * @returns {string}
             */
            bankDescription(backend) {
                if (backend.label) {
                    return backend.label;
                }
                const module = this.bankModules.find(m => m.module === backend.module);
                return module && module.description ? module.description : backend.name;
            },

            /**
             * Name of a connected bank with its woob name, to tell two logins apart
             * ("Compte joint (bnp_2)", "BNP Paribas (bnp_2)" if not named)
             * @param {{name: string, module: string, label: (string|null)}} backend - Backend of woob
             * @returns {string}
             */
            backendName(backend) {
                if (backend.label) {
                    return `${backend.label} (${backend.name})`;
                }
                const module = this.bankModules.find(m => m.module === backend.module);
                return module && module.description ? `${module.description} (${backend.name})` : backend.name;
            },

            /**
             * Short display of an account: the name given to the followed account, else
             * "BNP ···0001" ("···0001" without bank: imported file)
             * @param {Object} account - Account, or transaction: {bank_name, account_number}
             *                           (the label of a transaction is not the account's)
             * @returns {string}
             */
            accountName(account) {
                const followed = (this.bankAccounts || []).find(a =>
                    a.bank_name === account.bank_name && String(a.account_number) === String(account.account_number));
                if (followed && followed.label) {
                    return followed.label;
                }
                return [
                    account.bank_name ? String(account.bank_name).toUpperCase() : null,
                    account.account_number ? `···${String(account.account_number).slice(-4)}` : null
                ].filter(Boolean).join(' ');
            },

            /**
             * Short display of an account from its bank id ("account_number@bank_name")
             * @param {string} bankId - Bank id
             * @returns {string}
             */
            bankIdName(bankId) {
                const id = String(bankId);
                const at = id.lastIndexOf('@');
                return this.accountName({ account_number: id.slice(0, at), bank_name: id.slice(at + 1) });
            },

            /**
             * Opens the modal adding accounts to a connected bank (step 2), with the
             * accounts woob lists for it. When the bank asks a two-factor authentication,
             * they are listed interactively, while the connection is approved in its app
             * @param {string} backend - woob backend (bank connection)
             * @param {Array|null} accounts - Accounts already listed (just connected), else asked to woob
             * @param {boolean} twoFactor - The bank asks a two-factor authentication
             * @returns {Promise<void>}
             */
            async openAddAccounts(backend, accounts = null, twoFactor = false) {
                this.addAccountsBackend = backend;
                this.selectedAccounts = [];
                this.discoverError = null;
                this.discoverTwoFactor = false;
                this.discoverMessage = null;
                this.showAddAccountsModal = true;
                if (accounts) {
                    this.showDiscovered(accounts);
                    return;
                }
                this.discoveredAccounts = [];
                this.discovering = true;
                try {
                    let found;
                    try {
                        found = twoFactor ? await this.discoverWithTwoFactor(backend) : await getApiService().discoverBankAccounts(backend);
                    } catch (error) {
                        // woob only starts a two-factor authentication with the administrator waiting for it
                        if (twoFactor || !this.needsTwoFactor(error.message)) {
                            throw error;
                        }
                        found = await this.discoverWithTwoFactor(backend);
                    }
                    if (this.showAddAccountsModal && this.addAccountsBackend === backend) {
                        this.showDiscovered(found);
                    }
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        this.discoverError = this.apiErrorText(error);
                    }
                } finally {
                    this.discovering = false;
                    this.discoverAbort = null;
                }
            },

            /**
             * Accounts of a bank that asks a two-factor authentication: woob waits for the
             * approval in the bank app, its messages are shown in the modal
             * @param {string} backend - woob backend (bank connection)
             * @returns {Promise<Array>} Accounts found by woob
             */
            discoverWithTwoFactor(backend) {
                this.discoverTwoFactor = true;
                this.discoverAbort = new AbortController();
                return getApiService().discoverBankAccountsInteractive(backend, message => {
                    this.discoverMessage = message;
                }, this.discoverAbort.signal);
            },

            /**
             * Shows the accounts found by woob, the followed ones ticked (and disabled)
             * @param {Array} accounts - Accounts found by woob
             */
            showDiscovered(accounts) {
                this.discoveredAccounts = accounts;
                this.selectedAccounts = accounts.filter(d => this.isFollowed(d)).map(d => d.bankId);
            },

            /**
             * Adds accounts (the + of the synchronized accounts): the connection of a bank when
             * none is connected, otherwise the modal adding accounts, its bank chosen among those
             * already connected (straight to its accounts when there is one) or a new bank
             * @returns {Promise<void>|void}
             */
            openAddAccountsPicker() {
                const backends = this.bankBackends || [];
                if (backends.length === 0) {
                    this.openConnectModal();
                    return;
                }
                this.addAccountsPicking = true;
                this.addAccountsBackend = '';
                this.discoveredAccounts = [];
                this.selectedAccounts = [];
                this.discoverError = null;
                this.discovering = false;
                this.showAddAccountsModal = true;
                this.$nextTick?.(() => document.querySelector('#add-accounts-bank')?.focus());
                if (backends.length === 1) {
                    return this.openAddAccounts(backends[0].name);
                }
            },

            /**
             * Bank chosen in the modal adding accounts: lists its accounts (the listing of
             * the bank chosen before is stopped)
             * @param {string} backend - woob backend (bank connection), '' for none, NEW_BANK
             *                           for the connection of a new bank
             * @returns {Promise<void>|void}
             */
            chooseAddAccountsBackend(backend) {
                if (this.discoverAbort) {
                    this.discoverAbort.abort();
                }
                // Last choice of the list: the connection of a new bank
                if (backend === NEW_BANK) {
                    this.closeAddAccounts();
                    this.openConnectModal();
                    return;
                }
                if (backend) {
                    return this.openAddAccounts(backend);
                }
                this.addAccountsBackend = '';
                this.discoveredAccounts = [];
                this.selectedAccounts = [];
            },

            /**
             * Closes the modal adding accounts
             */
            closeAddAccounts() {
                // woob stops waiting for the approval of the bank
                if (this.discoverAbort) {
                    this.discoverAbort.abort();
                }
                this.showAddAccountsModal = false;
                this.addAccountsPicking = false;
                this.discoveredAccounts = [];
                this.selectedAccounts = [];
            },

            /**
             * Accounts ticked in the modal that are not followed yet
             * @returns {Array} Accounts found by woob
             */
            accountsToFollow() {
                return this.discoveredAccounts.filter(d => this.selectedAccounts.includes(d.bankId) && !this.isFollowed(d));
            },

            /**
             * Follows the accounts ticked in the modal, named after their label in woob
             * @returns {Promise<void>}
             */
            async followSelectedAccounts() {
                const chosen = this.accountsToFollow();
                if (!chosen.length) {
                    return;
                }
                this.followingAccounts = true;
                try {
                    for (const d of chosen) {
                        await getApiService().createBankAccount(String(d.account_number), d.bank_name, d.label ? String(d.label).slice(0, 100) : '');
                    }
                    this.showToast(this.t('accountsFollowedToast', { count: chosen.length }));
                    this.closeAddAccounts();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.followingAccounts = false;
                    await this.loadBankAccounts();
                }
            },

            /**
             * Opens the modal naming a followed account (its number and bank come from woob)
             * @param {Object} account - Account to modify
             */
            openAccountModal(account) {
                this.accountForm = { id: account.id, account_number: account.account_number, bank_name: account.bank_name, label: account.label || '', manual: !!account.manual };
                this.showAccountModal = true;
            },

            /**
             * Opens the modal adding a manual account (cash, a bank woob does not support)
             */
            openManualAccountModal() {
                this.accountForm = { id: null, account_number: '', bank_name: 'manual', label: '', manual: true };
                this.showAccountModal = true;
            },

            /**
             * Closes the account modal
             */
            closeAccountModal() {
                this.showAccountModal = false;
            },

            /**
             * Saves the name of the account of the modal
             * @returns {Promise<void>}
             */
            async submitAccountForm() {
                const form = this.accountForm;
                this.savingAccount = true;
                try {
                    if (form.id === null) {
                        await getApiService().createManualAccount(String(form.label || '').trim(), String(form.account_number || '').trim());
                        this.showToast(this.t('manualAccountAdded'));
                    } else {
                        await getApiService().updateBankAccount(form.id, String(form.account_number), form.bank_name, String(form.label || '').trim());
                        this.showToast(this.t('accountUpdated'));
                    }
                    this.showAccountModal = false;
                    await this.loadBankAccounts();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingAccount = false;
                }
            },

            /**
             * Date of the last synchronization: "today 07:02", or the short date
             * @param {string} value - "YYYY-MM-DD HH:MM:SS" (server time)
             * @returns {string}
             */
            formatSyncDate(value) {
                const date = new Date(String(value).replace(' ', 'T'));
                if (isNaN(date)) {
                    return value;
                }
                const time = date.toLocaleTimeString(this.intlLocale, { hour: '2-digit', minute: '2-digit' });
                if (date.toDateString() === new Date().toDateString()) {
                    return this.t('todayAt', { time });
                }
                return `${date.toLocaleDateString(this.intlLocale, { day: 'numeric', month: 'short', year: 'numeric' })} ${time}`;
            },

            /**
             * Stops following an account after confirmation (transactions are kept)
             * @param {Object} account - Account
             * @returns {Promise<void>}
             */
            async removeAccount(account) {
                if (!confirm(this.t('confirmDeleteAccount', { account: this.accountName(account) }))) {
                    return;
                }
                try {
                    await getApiService().deleteBankAccount(account.id);
                    this.showToast(this.t('accountDeleted'));
                    await this.loadBankAccounts();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            }
        }
    };
}
