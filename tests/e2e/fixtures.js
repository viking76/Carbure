// Fixtures shared by the specs:
// - the database is recreated before each test (data set: server/seed.php);
// - an uncaught error in the page fails the test;
// - the confirmation dialogs (delete...) are accepted;
// - adminPage / userPage: the portal opened by a logged-in user (token from the API);
// - api: requests to the API as the administrator, to check what was saved.
const base = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { translate, resolveText, LOCALES } = require('../../portal/i18n.js');

/** Translations of the portal (portal/i18n/<locale>.json), by language */
const i18n = Object.fromEntries(LOCALES.map(locale => [
    locale,
    JSON.parse(fs.readFileSync(path.join(__dirname, `../../portal/i18n/${locale}.json`), 'utf8')),
]));

/**
 * Text of the portal for a translation key, with the t() of the portal (portal/i18n.js):
 * same {placeholders} and plural forms (count chooses <key>_one / <key>_other)
 * @param {string} key - Key (nested keys with dots)
 * @param {Object} params - Values of the {placeholders}
 * @param {string} locale - Language of the portal (fr, en, es, it, de, pt)
 * @returns {string}
 */
function tr(key, params = {}, locale = 'fr') {
    if (typeof resolveText(i18n, [locale], key, params) !== 'string') {
        throw new Error(`Unknown translation key: ${key} (${locale})`);
    }
    return translate(i18n, [locale], key, params);
}

/** Users of the data set (server/seed.php) */
const USERS = {
    admin: { username: 'admin', password: 'admin-password' },
    marie: { username: 'marie', password: 'marie-password' },
};

/**
 * Logs in through the API
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} user - Key of USERS
 * @returns {Promise<string>} JWT
 */
async function apiLogin(request, user) {
    const response = await request.post('/api/user/login', { data: USERS[user] });
    base.expect(response.ok()).toBeTruthy();
    return (await response.json()).token;
}

/**
 * Opens the portal as a logged-in user (token stored like the login screen does)
 * @param {import('@playwright/test').Page} page
 * @param {string} token
 * @param {string} username
 */
async function openPortal(page, token, username) {
    await page.addInitScript(([token, username]) => {
        // Runs in every frame too: a framed page blocked by X-Frame-Options has no storage
        if (window !== window.top) {
            return;
        }
        if (!sessionStorage.getItem('e2e-init')) {
            sessionStorage.setItem('e2e-init', '1');
            localStorage.setItem('authToken', token);
            localStorage.setItem('username', username);
        }
    }, [token, username]);
    await page.goto('/portal/');
    await base.expect(page.locator('.user-menu-name')).toHaveText(username);
}

const test = base.test.extend({
    resetDatabase: [async ({ request }, use) => {
        const response = await request.get('/api/__e2e/reset');
        base.expect(response.ok(), await response.text()).toBeTruthy();
        await use();
    }, { auto: true }],

    page: async ({ page }, use) => {
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('dialog', dialog => dialog.accept());
        await use(page);
        base.expect(errors, 'Uncaught errors in the page').toEqual([]);
    },

    adminPage: async ({ page, request }, use) => {
        await openPortal(page, await apiLogin(request, 'admin'), 'admin');
        await use(page);
    },

    userPage: async ({ page, request }, use) => {
        await openPortal(page, await apiLogin(request, 'marie'), 'marie');
        await use(page);
    },

    api: async ({ playwright, baseURL }, use) => {
        const anonymous = await playwright.request.newContext({ baseURL });
        const token = await apiLogin(anonymous, 'admin');
        await anonymous.dispose();
        const api = await playwright.request.newContext({ baseURL, extraHTTPHeaders: { Authorization: `Bearer ${token}` } });
        await use(api);
        await api.dispose();
    },
});

/** Tabs grouped under "Administration" (see ADMINISTRATION_TABS in portal/app.js) */
const ADMINISTRATION_TABS = ['tabUsers', 'tabCategories', 'tabLogs', 'tabAgents'];

/**
 * Opens a tab of the main navigation; the tabs of the Administration menu through it
 * (administrators) or, for the AI agent tab of the other users, through the user menu
 * @param {import('@playwright/test').Page} page
 * @param {string} name - Label of the tab
 */
async function openTab(page, name) {
    if (!ADMINISTRATION_TABS.map(key => tr(key)).includes(name)) {
        await clickMainTab(page, name);
        return;
    }
    // The Administration tab exists for the administrators only (in the DOM, even hidden on a phone)
    if (await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).count()) {
        await clickMainTab(page, tr('tabAdministration'));
        await page.getByRole('tab', { name, exact: true }).click();
    } else if (await page.locator('.user-menu-toggle').isVisible()) {
        await page.locator('.user-menu-toggle').click();
        await page.getByRole('menuitem', { name }).click();
    } else {
        // Phone: the AI agents of a user in the menu "More" of the bottom navigation
        await clickMainTab(page, name);
    }
}

/**
 * Clicks a tab of the main navigation: the bar of tabs, or on a phone (narrow window)
 * the bottom navigation and its menu "More"
 * @param {import('@playwright/test').Page} page
 * @param {string} name - Label of the tab
 */
async function clickMainTab(page, name) {
    // One of the two navigations, once the portal is drawn (isVisible() does not wait)
    await page.locator('.tabs, .bottom-nav').filter({ visible: true }).first().waitFor();
    if (await page.locator('.tabs').isVisible()) {
        await page.locator('.tabs').getByRole('button', { name, exact: true }).click();
        return;
    }
    const bar = page.locator('.bottom-nav').getByRole('button', { name, exact: true });
    if (await bar.count()) {
        await bar.click();
        return;
    }
    await page.locator('.bottom-nav-more').click();
    await page.locator('.nav-drawer').getByRole('button', { name, exact: true }).click();
}

/**
 * Chooses a category in a category picker (components/CategoryPicker.js)
 * @param {import('@playwright/test').Locator} scope - Element containing the picker
 * @param {string} name - Name of the category
 */
async function pickCategory(scope, name) {
    await scope.locator('.category-picker-button').click();
    await scope.locator('.category-picker-search input').fill(name);
    await scope.locator('.category-picker-option').filter({ has: scope.page().locator('.category-picker-name', { hasText: new RegExp(`(^|›)\\s*${name}\\s*$`) }) }).first().click();
}

/**
 * Amount as shown by the portal in French (narrow no-break space, comma)
 * @param {number} value
 * @returns {string}
 */
function euros(value) {
    return new Intl.NumberFormat('fr', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) + ' €';
}

/**
 * Month and year of the current month, or of a previous one, as the API expects them
 * @param {number} ago - Number of months before the current one
 * @returns {{month: number, year: number}}
 */
function currentMonth(ago = 0) {
    const date = new Date();
    date.setDate(1);
    date.setMonth(date.getMonth() - ago);
    return { month: date.getMonth() + 1, year: date.getFullYear() };
}

/**
 * Budgets set through the API so that the copy of the previous month is offered on the
 * current month: its budgets of seed.php (Supermarché, Restaurant, Logement) put back to 0
 * (not a defined budget), and the previous month given the ones of the seed
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {Array<[number, number]>} previous - [category, amount] of the previous month
 */
async function offerBudgetCopy(api, previous = [[2, 300], [3, 100], [4, 1000]]) {
    const budgets = [
        ...[2, 3, 4].map(category => ({ category, amount: 0, ...currentMonth() })),
        ...previous.map(([category, amount]) => ({ category, amount, ...currentMonth(1) })),
    ];
    for (const data of budgets) {
        const response = await api.post('/api/budget', { data });
        if (!response.ok()) {
            throw new Error(`POST /api/budget failed (${response.status()})`);
        }
    }
}

/**
 * Text to match literally in a regular expression (every special character escaped)
 * @param {string} text
 * @returns {string}
 */
function escapeRegExp(text) {
    return String(text).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * A file of the state of the fake woob, in the temporary directory of the run
 * (CARBURE_TEST_TMP, set by playwright.config.js and shared with the server)
 * @param {string} name
 * @returns {string}
 */
function woobFile(name) {
    return path.join(process.env.CARBURE_TEST_TMP, name);
}

/**
 * The portal as served by the demonstration Docker image (docker/demo/Dockerfile): GET
 * /api/health says demo, the rest is the real API
 * @param {import('@playwright/test').Page} page
 */
async function asDemoInstance(page) {
    await page.route('**/api/health', async route => {
        const response = await route.fetch();
        await route.fulfill({ response, json: { ...await response.json(), demo: true } });
    });
}

/**
 * The repository of the fake woob trusted, as after an update confirmed by an
 * administrator (the keyrings of the fake woob are in the temporary directory of the run,
 * CARBURE_TEST_TMP set by playwright.config.js and shared with the server; the reset of the
 * database forgets them)
 */
function trustWoobRepository() {
    const keyrings = woobFile('carbure-fake-woob-keyrings');
    fs.mkdirSync(keyrings, { recursive: true });
    fs.copyFileSync(path.join(__dirname, '../fixtures/woob-repository/trusted.gpg'), path.join(keyrings, 'https___updates_woob_tech_3_main_.gpg'));
}

/**
 * Chooses the language of the profile and saves it: the page reloads in that language
 * @param {import('@playwright/test').Page} page
 * @param {string} from - Language shown
 * @param {string} to - Language chosen
 */
async function chooseLanguage(page, from, to) {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile', {}, from) }).click();
    await page.getByLabel(tr('language', {}, from)).selectOption(to);
    await page.getByRole('button', { name: tr('save', {}, from) }).click();
    await base.expect(page.locator('.tabs').getByRole('button', { name: tr('tabTransactions', {}, to), exact: true })).toBeVisible();
}

module.exports = { test, expect: base.expect, USERS, openTab, clickMainTab, tr, pickCategory, euros, currentMonth, offerBudgetCopy, escapeRegExp, woobFile, trustWoobRepository, chooseLanguage, asDemoInstance };
