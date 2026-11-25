/**
 * Main Application Entry Point
 * 
 * This is the refactored main application file that uses modular Vue.js components
 * and services following best practices.
 * 
 * @module app
 */

import CategoryPicker from './components/CategoryPicker.js';
import TagInput from './components/TagInput.js';
import SqlEditor from './components/SqlEditor.js';
import FlowChart from './components/FlowChart.js';
import { createApiService } from './services/apiService.js';
import { createBudgetStore } from './stores/budgetStore.js';
import { createTransactionModule } from './modules/transactionModule.js';
import { createSearchModule } from './modules/searchModule.js';
import { createSplitModule } from './modules/splitModule.js';
import { createManualModule } from './modules/manualModule.js';
import { createTagsModule } from './modules/tagsModule.js';
import { createBudgetModule } from './modules/budgetModule.js';
import { createInsightsModule } from './modules/insightsModule.js';
import { createUserModule } from './modules/userModule.js';
import { createProfileModule } from './modules/profileModule.js';
import { createTrendsModule } from './modules/trendsModule.js';
import { createTrendCategoriesModule } from './modules/trendCategoriesModule.js';
import { createRulesModule } from './modules/rulesModule.js';
import { createSyncModule } from './modules/syncModule.js';
import { createAccountsModule } from './modules/accountsModule.js';
import { createCategoriesModule } from './modules/categoriesModule.js';
import { createSetupModule } from './modules/setupModule.js';
import { createAgentsModule } from './modules/agentsModule.js';
import { createLogsModule } from './modules/logsModule.js';
import { createOAuthModule } from './modules/oauthModule.js';
import { createImportModule } from './modules/importModule.js';
import { createConfigModule } from './modules/configModule.js';
import { createConfirmModule } from './modules/confirmModule.js';
import { createNotificationsModule } from './modules/notificationsModule.js';
import { createMfaModule } from './modules/mfaModule.js';
import { createBackupModule } from './modules/backupModule.js';
import { createPurgeModule } from './modules/purgeModule.js';
import { createChangesModule } from './modules/changesModule.js';

/**
 * Base URL of the API: the portal is served at <server>/portal/ and the API at
 * <server>/api, so it comes from the address of the page (also under a sub-path)
 */
const API_BASE_URL = new URL('../api', new URL('.', window.location.href)).href;

/**
 * Public account of the demonstration instance (Docker image carbure:demo, data of
 * docker/demo/entrypoint.sh), shown on its login screen
 */
const DEMO_ACCOUNT = { username: 'demo', password: 'carbure' };

/** Tabs reserved to administrators */
const ADMIN_TABS = ['rules', 'categories', 'accounts', 'users', 'logs', 'config', 'sync', 'push', 'backup', 'purge', 'api'];

/**
 * Tabs grouped under "Administration" in the navigation of the administrators (the
 * other users reach "agents", their own AI agent tokens, from the user menu)
 */
const ADMINISTRATION_TABS = [
    { key: 'users', icon: 'people', label: 'tabUsers' },
    { key: 'categories', icon: 'category', label: 'tabCategories' },
    { key: 'sync', icon: 'sync', label: 'tabSync' },
    { key: 'push', icon: 'notifications_active', label: 'tabPush' },
    { key: 'agents', icon: 'smart_toy', label: 'tabAgents' },
    { key: 'logs', icon: 'receipt_long', label: 'tabLogs' },
    { key: 'config', icon: 'tune', label: 'tabSettings' },
    { key: 'backup', icon: 'backup', label: 'tabBackup' },
    { key: 'purge', icon: 'delete_sweep', label: 'tabPurge' },
    // Swagger UI of the API (/swagger/), framed by the portal
    { key: 'api', icon: 'api', label: 'tabApi' }
];
import * as formatters from './utils/formatters.js';
import { badgeStyle } from './utils/categoryIcons.js';
import { swaggerFramed } from './utils/apiDocs.js';

const { createApp } = Vue;

// Initialize services (will be passed to modules)
let apiService = null;
let budgetStore = null;

/**
 * Main Vue Application
 * 
 * Manages the overall application state and coordinates between
 * different views (transactions, budgets, insights).
 */
const app = createApp({
    // Register imported components
    components: {
        CategoryPicker,
        TagInput,
        SqlEditor,
        FlowChart
    },

    // Apply mixins from modules
    mixins: [
        createTransactionModule(() => apiService),
        createSearchModule(() => apiService),
        createSplitModule(() => apiService),
        createManualModule(() => apiService),
        createTagsModule(() => apiService),
        createBudgetModule(() => budgetStore, () => apiService),
        createInsightsModule(() => apiService),
        createUserModule(() => apiService),
        createProfileModule(() => apiService),
        createTrendsModule(() => apiService),
        createTrendCategoriesModule(() => apiService),
        createRulesModule(() => apiService),
        createSyncModule(() => apiService),
        createAccountsModule(() => apiService),
        createCategoriesModule(() => apiService),
        createSetupModule(),
        createAgentsModule(() => apiService),
        createLogsModule(() => apiService),
        createOAuthModule(() => apiService),
        createImportModule(() => apiService),
        createConfigModule(() => apiService),
        createConfirmModule(),
        createNotificationsModule(() => apiService),
        createMfaModule(() => apiService),
        createBackupModule(() => apiService),
        createPurgeModule(() => apiService),
        createChangesModule(() => apiService)
    ],

    data() {
        return {
            // Core state
            activeTab: 'transactions',
            selectedMonth: new Date().getMonth() + 1,
            selectedYear: new Date().getFullYear(),
            // Months that have transactions, newest first: [{year, month}]
            periods: [],
            // Menu of the bottom navigation on a phone: the tabs and actions not in the bar
            showNavDrawer: false,
            // Language of the texts loaded by loadLocale() (i18n.js)
            locale: currentLocale(),
            apiBaseUrl: API_BASE_URL,
            // Version of Carbure (GET /api/health, asked by loadLocale()), shown in the footer
            appVersion: serverVersion(),
            // Demonstration instance (GET /api/health): its public account, null otherwise
            demoAccount: serverDemo() ? DEMO_ACCOUNT : null,
            // Currency of the amounts of the instance (GET /api/health: setting currency)
            currency: serverCurrency(),
            // Money flow of the selected month (Budget tab)
            budgetFlow: null,
            // Node of the flow diagram whose transactions are shown, and these transactions
            flowSelection: null,
            flowTransactions: [],
            loadingFlowTransactions: false,
            flowRequest: 0,
            // Budget tab: 'flow' (money flow) or 'budgets' (budget of each category)
            budgetView: 'flow',
            // Tabs of the Administration menu, and the last one opened
            administrationTabs: ADMINISTRATION_TABS,
            administrationTab: ADMINISTRATION_TABS[0].key,
            // API sub-tab: Swagger UI refused in a frame (by a reverse proxy)
            apiDocsBlocked: false,
            
            // Authentication state
            isAuthenticated: false,
            authToken: null,
            username: localStorage.getItem('username'),
            loginForm: {
                username: '',
                password: ''
            },
            loggingIn: false,
            loginError: null,
            
            // Shared state
            error: null,
            toastMessage: null,
            
            // Debug info
            debugInfo: {
                url: null,
                status: null,
                ok: null,
                bodyPreview: null
            }
        };
    },

    computed: {
        /**
         * Locale of the number and date formats (Intl) of the language: pt -> pt-PT
         * @returns {string}
         */
        intlLocale() {
            return intlLocale(this.locale);
        },

        /**
         * Symbol of the currency of the instance in the language (€, $, £...; CHF)
         * @returns {string}
         */
        currencySymbol() {
            return formatters.currencySymbol(this.currency, this.intlLocale);
        },

        /**
         * Sum of the transactions of the node selected in the flow diagram
         * @returns {number}
         */
        flowTransactionsTotal() {
            return this.flowTransactions.reduce((sum, t) => sum + Number(t.amount), 0);
        },

        /**
         * Periods offered, as year * 100 + month, newest first: the months that have
         * transactions, and the current month (where the new ones arrive)
         * @returns {Array<number>}
         */
        periodKeys() {
            const now = new Date();
            const keys = [now.getFullYear() * 100 + now.getMonth() + 1, ...this.periods.map(p => p.year * 100 + Number(p.month))];
            return [...new Set(keys)].sort((a, b) => b - a);
        },

        /**
         * Months of the selected year that have transactions, with localized labels
         * @returns {Array<{value: number, label: string}>}
         */
        months() {
            return this.periodKeys.filter(key => Math.floor(key / 100) === Number(this.selectedYear))
                .map(key => key % 100).reverse()
                .map(value => ({ value, label: t(`months.${value}`) }));
        },

        /**
         * Years that have transactions, newest first
         * @returns {Array<number>}
         */
        years() {
            return [...new Set(this.periodKeys.map(key => Math.floor(key / 100)))];
        },

        /**
         * Checks if debug mode is enabled via query parameter
         * @returns {boolean}
         */
        isDebugMode() {
            return formatters.isDebugMode();
        },

        /**
         * Checks if the tab shown is one of the Administration menu (administrators)
         * @returns {boolean}
         */
        isAdministrationTab() {
            return this.isAdmin && ADMINISTRATION_TABS.some(tab => tab.key === this.activeTab);
        },

        /**
         * The open tab is one of the menu of the bottom navigation (phone), not of its bar
         * @returns {boolean}
         */
        isDrawerTab() {
            return !['transactions', 'budget', 'insights', 'trends'].includes(this.activeTab);
        },

        /**
         * Period before the selected one that has transactions (null: the oldest)
         * @returns {number|null} year * 100 + month
         */
        previousPeriod() {
            return this.periodKeys.find(key => key < this.selectedYear * 100 + Number(this.selectedMonth)) || null;
        },

        /**
         * Period after the selected one that has transactions (null: the newest)
         * @returns {number|null} year * 100 + month
         */
        nextPeriod() {
            return [...this.periodKeys].reverse().find(key => key > this.selectedYear * 100 + Number(this.selectedMonth)) || null;
        },

        /**
         * Safe access to current budget list (prevents undefined errors)
         * @returns {Array}
         */
        safeCurrentBudgetList() {
            return this.currentBudgetList || [];
        },

        /**
         * Safe access to breadcrumb stack (prevents undefined errors)
         * @returns {Array}
         */
        safeBudgetStack() {
            return this.breadcrumbStack || [];
        }
    },

    methods: {
        /**
         * API sub-tab: tells when the frame does not show Swagger UI (refused by the
         * X-Frame-Options or frame-ancestors header of a reverse proxy)
         * @param {Event} event - load of the frame
         */
        onApiDocsLoad(event) {
            const framed = swaggerFramed(event.target);
            if (framed !== null) {
                this.apiDocsBlocked = !framed;
            }
        },

        // === Localization Methods ===
        
        /**
         * Translates a key using the global i18n function
         * @param {string} key - Translation key
         * @param {Object} params - Translation parameters
         * @returns {string}
         */
        t(key, params = {}) {
            return t(key, params);
        },

        /**
         * Text of an error to show: the translation of the code of an API error, its
         * message otherwise (see apiErrorText() in i18n.js)
         * @param {Error|null} error - Error
         * @returns {string}
         */
        apiErrorText(error) {
            return apiErrorText(error);
        },

        /**
         * Changes the application locale
         * @param {string} newLocale - New locale code (one of LOCALES, i18n.js)
         */
        changeLocale(newLocale) {
            setLocale(newLocale);
            this.locale = newLocale;
            // Screen readers pronounce the page in its language
            document.documentElement.lang = intlLocale(newLocale);
        },

        /**
         * Skip link: moves the focus to the content of the tab
         */
        focusMain() {
            document.getElementById('main-content')?.focus();
        },

        /**
         * Loads the money flow of the selected month (Budget tab)
         * @param {boolean} keepSelection - Keeps the selected node and reloads its transactions
         * @returns {Promise<void>}
         */
        async loadBudgetFlow(keepSelection = false) {
            // The nodes depend on the month
            if (!keepSelection) {
                this.selectFlowNode(null);
            }
            try {
                this.budgetFlow = await apiService.fetchBudgetFlow(this.selectedMonth, this.selectedYear);
            } catch (error) {
                this.budgetFlow = null;
            }
            if (keepSelection && this.flowSelection) {
                this.selectFlowNode(this.flowSelection);
            }
        },

        /**
         * Reloads the Budget tab where it stands (displayed level, listed transactions,
         * money flow and its selected node): a transaction changed category or was deleted
         */
        refreshBudgetTab() {
            this.reloadBudgetLevel(this.selectedMonth, this.selectedYear);
            this.loadBudgetFlow(true);
        },

        /**
         * Shows the transactions of a node of the flow diagram (null: hides them)
         * @param {Object|null} node - {key, name, color, icon, kind, categories}
         * @returns {Promise<void>}
         */
        async selectFlowNode(node) {
            // Number of this request: an answer arriving after another click is ignored
            // (flowSelection holds a reactive proxy: it is never === node)
            const request = ++this.flowRequest;
            this.flowSelection = node;
            this.flowTransactions = [];
            if (!node) {
                this.loadingFlowTransactions = false;
                return;
            }
            this.loadingFlowTransactions = true;
            try {
                const transactions = await apiService.fetchFlowTransactions(this.selectedMonth, this.selectedYear, node.kind, node.categories);
                if (request === this.flowRequest) {
                    this.flowTransactions = transactions;
                    // Icons of the rows: the categories of the transactions
                    this.ensureCategories();
                }
            } catch (err) {
                this.showToast(this.apiErrorText(err));
            } finally {
                if (request === this.flowRequest) {
                    this.loadingFlowTransactions = false;
                }
            }
            this.$nextTick(() => {
                document.querySelector('.flow-transactions')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        },

        /**
         * Colors of an icon badge
         * @param {string} color - CSS color
         * @returns {{color: string, background: string}}
         */
        badgeStyleFor(color) {
            return badgeStyle(color);
        },

        // === Tab Navigation Methods ===

        /**
         * Another year: its last month that has transactions if the selected month has none
         */
        changeYear() {
            const months = this.months.map(m => m.value);
            if (!months.includes(Number(this.selectedMonth)) && months.length) {
                this.selectedMonth = months[months.length - 1];
            }
            this.refreshCurrentTab();
        },

        /**
         * Previous or next month that has transactions (arrows of the period)
         * @param {number|null} key - year * 100 + month, as given by previousPeriod / nextPeriod
         */
        goToPeriod(key) {
            if (!key) {
                return;
            }
            this.selectedYear = Math.floor(key / 100);
            this.selectedMonth = key % 100;
            this.refreshCurrentTab();
        },

        /**
         * Opens the menu of the bottom navigation (phone), its first entry focused
         */
        openNavDrawer() {
            this.showNavDrawer = true;
            this.$nextTick(() => {
                const first = document.querySelector('.nav-drawer button:not(.modal-close)');
                if (first) {
                    first.focus();
                }
            });
        },

        /**
         * Closes the menu of the bottom navigation, the focus back on its button
         */
        closeNavDrawer() {
            this.showNavDrawer = false;
            this.$nextTick(() => {
                const more = document.querySelector('.bottom-nav-more');
                if (more) {
                    more.focus();
                }
            });
        },

        /**
         * A tab chosen in the menu of the bottom navigation
         * @param {string} tab - Tab name
         */
        navigateFromDrawer(tab) {
            this.showNavDrawer = false;
            this.setActiveTab(tab);
        },

        /**
         * Loads the months that have transactions (period selector)
         * @returns {Promise<void>}
         */
        async loadPeriods() {
            try {
                this.periods = await apiService.fetchPeriods();
            } catch (error) {
                // The current month stays available
                this.periods = [];
            }
        },

        /**
         * Back to the home page: transactions of the current month
         */
        goHome() {
            const now = new Date();
            this.selectedMonth = now.getMonth() + 1;
            this.selectedYear = now.getFullYear();
            this.setActiveTab('transactions');
        },
        
        /**
         * Sets the active tab and loads corresponding data
         * @param {string} tab - Tab name ('transactions', 'budget', 'insights', 'users')
         */
        setActiveTab(tab) {
            // Rules, categories, accounts and users are managed by administrators
            if (ADMIN_TABS.includes(tab) && !this.isAdmin) {
                tab = 'transactions';
            }
            this.abortAllExcept(tab);
            this.activeTab = tab;
            if (ADMINISTRATION_TABS.some(t => t.key === tab)) {
                this.administrationTab = tab;
            }
            
            if (this.isDebugMode) {
                console.log('Switching to tab:', tab);
            }
            
            this.refreshCurrentTab();
        },

        /**
         * Refreshes data for the currently active tab
         */
        refreshCurrentTab() {
            switch (this.activeTab) {
                case 'transactions':
                    this.loadTransactions(this.selectedMonth, this.selectedYear);
                    break;
                case 'budget':
                    this.loadBudget(this.selectedMonth, this.selectedYear);
                    this.loadBudgetFlow();
                    break;
                case 'insights':
                    this.loadInsights(this.selectedMonth, this.selectedYear);
                    break;
                case 'trends':
                    this.loadTrends();
                    break;
                case 'tags':
                    this.tagSummary = null;
                    this.loadTags();
                    break;
                case 'rules':
                    this.loadRules();
                    break;
                case 'logs':
                    this.loadLogs();
                    break;
                case 'config':
                    this.loadConfig();
                    break;
                case 'sync':
                    this.loadSyncInfo();
                    break;
                case 'push':
                    this.loadPushTab();
                    break;
                case 'backup':
                    this.loadBackupInfo();
                    break;
                case 'purge':
                    this.loadPurgeInfo();
                    break;
                case 'agents':
                    this.loadAgents();
                    break;
                case 'categories':
                    this.loadCategories();
                    break;
                case 'accounts':
                    this.loadBankAccounts();
                    this.loadBankBackends();
                    break;
                case 'users':
                    this.loadUsers();
                    break;
                case 'profile':
                    // Profile form is filled by openProfile()
                    break;
            }
        },

        /**
         * Aborts all pending requests except for specified tab
         * @param {string} tab - Tab to keep active
         */
        abortAllExcept(tab) {
            // Abort budget store requests if not on budget tab
            if (tab !== 'budget') {
                this.abortBudget();
            }

            // Abort transaction requests if not on transactions tab
            if (tab !== 'transactions') {
                this.abortTransactions();
            }

            // Abort insights requests if not on insights tab
            if (tab !== 'insights') {
                this.abortInsights();
            }
        },

        // === Formatting Methods (delegated to utils) ===
        
        formatAmount(amount) {
            return formatters.formatAmount(amount, this.intlLocale);
        },

        formatDate(dateString) {
            return formatters.formatDate(dateString, this.intlLocale);
        },

        getTransactionIcon(type) {
            return formatters.getTransactionIcon(type);
        },

        getTransactionType(type) {
            return this.t(`transactionTypes.${type}`);
        },

        getInsightName(name) {
            const translationKey = `insightNames.${name}`;
            const translated = this.t(translationKey);
            return translated !== translationKey ? translated : name;
        },

        getInsightIcon(name) {
            return formatters.getInsightIcon(name);
        },

        // === Budget Navigation Aliases (for template compatibility) ===
        
        /**
         * Navigates to budget item (alias for handleBudgetClick)
         * @param {Object} item - Budget item
         */
        navigateToBudget(item) {
            this.handleBudgetClick(item, this.selectedMonth, this.selectedYear);
        },

        /**
         * Navigates to breadcrumb (alias for handleBreadcrumbClick)
         * @param {number} index - Breadcrumb index
         */
        goToBreadcrumb(index) {
            this.handleBreadcrumbClick(index, this.selectedMonth, this.selectedYear);
        },

        /**
         * Opens budget modal (alias for handleBudgetEdit)
         * @param {Object} item - Budget item
         */
        openBudgetModal(item) {
            this.handleBudgetEdit(item);
        },

        // === Authentication Methods ===
        
        /**
         * Logs in the user and retrieves JWT token
         * @returns {Promise<void>}
         */
        async login() {
            if (!this.loginForm.username || !this.loginForm.password) {
                this.loginError = this.t('loginRequired');
                return;
            }

            this.loggingIn = true;
            this.loginError = null;

            try {
                const url = `${this.apiBaseUrl}/user/login`;
                const response = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    // The portal supports the second factor: a challenge may come instead of the
                    // session; the cookie of a trusted browser is sent
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        username: this.loginForm.username,
                        password: this.loginForm.password,
                        mfa: true
                    })
                });

                if (!response.ok) {
                    // Locked after too many failed logins (423), too many logins from this
                    // address (429, rate limit of nginx), or wrong credentials
                    throw new Error(this.loginFailure(response.status));
                }

                const data = await response.json();

                // Second factor (mfaModule): the code of the authenticator app is asked
                if (data && data.mfa) {
                    this.startMfaLogin(data);
                    return;
                }

                if (!data || !data.token) {
                    throw new Error('Token non reçu');
                }

                await this.openSession(data.token);

            } catch (error) {
                console.error('Login error:', error);
                this.loginError = error.message || (this.t('loginError'));
            } finally {
                this.loggingIn = false;
            }
        },

        /**
         * Message of a refused login
         * @param {number} status - HTTP status of POST /user/login
         * @returns {string} Account locked (423), too many logins from this address (429,
         *                   rate limit of nginx), else wrong credentials
         */
        loginFailure(status) {
            if (status === 423) {
                return this.t('accountLocked');
            }
            return status === 429 ? this.t('tooManyLogins') : this.t('invalidCredentials');
        },

        /**
         * Opens the session of the user of the login form with his JWT
         * @param {string} token - JWT
         * @returns {Promise<void>}
         */
        async openSession(token) {
            // Store authentication data
            this.authToken = token;
            this.isAuthenticated = true;

            // Save to localStorage
            localStorage.setItem('authToken', token);
            localStorage.setItem('username', this.loginForm.username);
            this.username = this.loginForm.username;

            // Clear password
            this.loginForm.password = '';

            await this.startSession();
        },

        /**
         * Expired or invalid token: back to the login screen with a message
         */
        sessionExpired() {
            if (!this.isAuthenticated) {
                return;
            }
            this.logout();
            this.loginError = this.t('sessionExpired');
        },

        /**
         * Logs out the user
         */
        logout() {
            this.closeUserMenu();
            this.showNavDrawer = false;
            this.isAuthenticated = false;
            this.authToken = null;
            this.currentUser = null;
            this.activeTab = 'transactions';
            this.loginForm.password = '';
            this.mfaLogin = null;
            this.stopChangeWatch();
            localStorage.removeItem('authToken');
            localStorage.removeItem('username');
            this.username = null;
            
            // Reinitialize services without token
            apiService = createApiService(this.apiBaseUrl, null);
            budgetStore = createBudgetStore(apiService);
        },

        /**
         * Starts the session of the logged-in user: API services, profile, periods and data
         * @returns {Promise<void>}
         */
        async startSession() {
            apiService = createApiService(this.apiBaseUrl, this.authToken, () => this.sessionExpired(), () => this.setupRequired(),
                (changes, written) => this.serverChanges(changes, written));
            budgetStore = createBudgetStore(apiService);

            // Versions of the data: with the answers of the API, and asked when the page
            // comes back
            this.startChangeWatch();
            this.loadPeriods();
            this.refreshCurrentTab();
            // Name in header, language, admin rights
            await this.loadCurrentUser();
        },

        /**
         * Carbure is not installed (any more) at this address: the session of a previous
         * installation is closed and the installation wizard is shown
         */
        setupRequired() {
            if (!this.isAuthenticated) {
                return;
            }
            this.logout();
            this.checkSetup();
        },

        /**
         * Checks if user is already authenticated from localStorage
         */
        checkAuthentication() {
            // URL of the API typed by older versions of the login screen
            localStorage.removeItem('apiUrl');

            const token = localStorage.getItem('authToken');
            if (token) {
                this.authToken = token;
                this.isAuthenticated = true;
            }
        },

        // === UI Helper Methods ===
        
        /**
         * Shows a temporary toast message
         * @param {string} message - Message to display
         * @param {number} duration - Duration in milliseconds
         */
        showToast(message, duration = 2500) {
            this.toastMessage = message;
            setTimeout(() => {
                this.toastMessage = null;
            }, duration);
        }
    },

    /**
     * Lifecycle hook: Called after component is mounted
     * Initializes services, stores, and loads initial data
     */
    mounted() {
        // Close the user menu when clicking anywhere else
        document.addEventListener('click', () => this.closeUserMenu());

        // Escape closes the open modal (its close button), then the user menu
        document.addEventListener('keydown', event => {
            if (event.key !== 'Escape') {
                return;
            }
            const close = [...document.querySelectorAll('.modal-overlay .modal-close')].pop();
            if (close) {
                close.click();
            } else {
                this.closeUserMenu();
            }
        });
        document.documentElement.lang = this.intlLocale;

        // Check if already authenticated
        this.checkAuthentication();

        // Not installed yet: the installation wizard replaces the login screen (a session
        // kept from a previous installation is closed by the API answer, see startSession)
        if (!this.isAuthenticated) {
            this.checkSetup();
        }

        if (this.isDebugMode) {
            console.log('apiBaseUrl =', this.apiBaseUrl);
            console.log('isAuthenticated =', this.isAuthenticated);
        }

        if (this.isAuthenticated) {
            this.startSession();
        }
    }
});

// The texts of the language (i18n/<locale>.json) are loaded before the first render: the
// page stays hidden until then (v-cloak), a minimal message replaces it if they cannot be
loadLocale().then(() => app.mount('#app'), error => {
    console.error('Error loading the translations:', error);
    showLocaleError();
});
