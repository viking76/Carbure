// Accessibility beyond the contrasts (see accessibility.spec.js): the WCAG 2.2 A/AA rules
// of axe-core on every screen, the reflow at 320 CSS px (1.4.10), the zoom at 200 %
// (1.4.4) and the text spacing (1.4.12)
const { test, expect, tr, openTab, currentMonth, offerBudgetCopy, clickMainTab, trustWoobRepository, chooseLanguage, asDemoInstance } = require('../fixtures');
const { axeViolations, reflowProblems, clippedTexts, TEXT_SPACING, WCAG_TAGS } = require('../axe');
const { AxeBuilder } = require('@axe-core/playwright');

test.describe.configure({ timeout: 180_000 });

test.beforeEach(async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
});

/** Tabs of the main navigation (administrators), then those of the Administration menu */
const MAIN_TABS = ['tabTransactions', 'tabBudget', 'tabInsights', 'tabTrends', 'tabTags', 'tabRules', 'tabAccounts'];
const ADMIN_TABS = ['tabUsers', 'tabCategories', 'tabSync', 'tabPush', 'tabAgents', 'tabLogs', 'tabSettings', 'tabBackup', 'tabPurge'];
/** Tabs of a user who is not an administrator */
const USER_TABS = ['tabTransactions', 'tabBudget', 'tabInsights', 'tabTrends', 'tabTags'];

/**
 * Opens a tab and waits for its data
 * @param {import('@playwright/test').Page} page
 * @param {string} key - Translation key of the tab
 * @param {string} locale - Language of the portal
 */
async function showTab(page, key, locale = 'fr') {
    const name = tr(key, {}, locale);
    if (ADMIN_TABS.includes(key)) {
        await clickMainTab(page, tr('tabAdministration', {}, locale));
        await page.getByRole('tab', { name, exact: true }).click();
        await expect(page.getByRole('tab', { name, exact: true })).toHaveAttribute('aria-selected', 'true');
    } else if (locale === 'fr') {
        await openTab(page, name);
        await expect(page.locator('.tab-button.active')).toContainText(name);
    } else {
        await clickMainTab(page, name);
        await expect(page.locator('.tab-button.active')).toContainText(name);
    }
    await page.waitForLoadState('networkidle');
    // The tabs are drawn again once the data is loaded
    await page.waitForTimeout(300);
}

/**
 * Runs a check on every tab of a list
 * @param {import('@playwright/test').Page} page
 * @param {string[]} tabs - Translation keys of the tabs
 * @param {(page, where: string) => Promise<string[]>} check
 * @param {string} locale - Language of the portal
 * @returns {Promise<string[]>} The problems of every tab
 */
async function everyTab(page, tabs, check, locale = 'fr') {
    const problems = [];
    for (const key of tabs) {
        await showTab(page, key, locale);
        problems.push(...await check(page, `${tr(key, {}, locale)} (${locale})`));
    }
    return problems;
}

test('axe: the login screen', async ({ page }) => {
    await page.goto('/portal/');
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    expect(await axeViolations(page, 'login')).toEqual([]);
});

test('axe: the login screen of a demonstration instance', async ({ page }) => {
    await asDemoInstance(page);
    await page.goto('/portal/');
    await expect(page.locator('.login-demo')).toBeVisible();
    expect(await axeViolations(page, 'login demo')).toEqual([]);
});

test('axe: every tab of an administrator', async ({ adminPage: page }) => {
    expect(await everyTab(page, [...MAIN_TABS, ...ADMIN_TABS], axeViolations)).toEqual([]);
});

test('axe: every tab of a user', async ({ userPage: page }) => {
    expect(await everyTab(page, USER_TABS, axeViolations)).toEqual([]);
});

test('axe: the menus and the modals', async ({ adminPage: page, api }) => {
    const problems = [];

    await page.locator('.user-menu-toggle').click();
    problems.push(...await axeViolations(page, 'user menu'));
    await page.keyboard.press('Escape');

    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    problems.push(...await axeViolations(page, 'administration menu'));
    await openTab(page, tr('tabTransactions'));

    await page.locator('.transaction-item').first().getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(detail).toBeVisible();
    problems.push(...await axeViolations(page, tr('transactionDetail')));
    // Split of the transaction, with the amount left to allocate and why it cannot be saved yet
    await detail.getByRole('button', { name: tr('splitButton') }).click();
    const split = page.getByRole('dialog', { name: tr('splitTitle') });
    await expect(split).toBeVisible();
    await split.getByLabel(tr('splitPartAmount', { number: 1 })).fill('1');
    problems.push(...await axeViolations(page, tr('splitTitle')));
    await page.keyboard.press('Escape');
    await expect(split).toHaveCount(0);

    // A transaction entered by hand: without manual account, on the synchronized one (with its help)
    await page.locator('.transactions-toolbar').getByRole('button', { name: tr('manualTransactionTitle') }).click();
    const manual = page.getByRole('dialog', { name: tr('manualTransactionTitle') });
    await expect(manual.getByText(tr('manualSyncedHelp'))).toBeVisible();
    problems.push(...await axeViolations(page, tr('manualTransactionTitle')));
    await page.keyboard.press('Escape');
    await expect(manual).toHaveCount(0);

    // Advanced search: its modal (categories and accounts), then the chips of its criteria
    await page.locator('.transaction-search-main').getByRole('button', { name: tr('advancedSearch') }).click();
    const advanced = page.getByRole('dialog', { name: tr('advancedSearch') });
    await expect(advanced.getByRole('group', { name: tr('advancedSearchAccounts') })).toBeVisible();
    await advanced.getByRole('radio', { name: tr('advancedSearchExpenses') }).check();
    await advanced.getByLabel(tr('advancedSearchMin', { currency: '€' })).fill('100');
    problems.push(...await axeViolations(page, tr('advancedSearch')));
    await advanced.getByRole('button', { name: tr('advancedSearchApply') }).click();
    await expect(page.locator('.search-filter-chip')).toHaveCount(2);
    problems.push(...await axeViolations(page, tr('advancedSearchFilters')));
    await page.locator('.search-filters').getByRole('button', { name: tr('clearSearch') }).click();
    await expect(page.locator('.search-filters')).toHaveCount(0);

    // Offered when the month has no budget and the previous month has some
    await offerBudgetCopy(api);
    await openTab(page, tr('tabBudget'));
    await page.getByRole('tab', { name: tr('budgetConfigTab') }).click();
    await page.locator('.budget-copy-button').click();
    const copy = page.getByRole('dialog', { name: tr('budgetCopyTitle') });
    await expect(copy).toBeVisible();
    problems.push(...await axeViolations(page, tr('budgetCopyTitle')));
    await page.keyboard.press('Escape');
    await expect(copy).toHaveCount(0);

    // Alert of an insight, its amount refused to show the error
    await openTab(page, tr('tabInsights'));
    await page.getByRole('button', { name: tr('insightAlertOf', { name: 'Dépenses' }) }).click();
    const alert = page.getByRole('dialog', { name: tr('insightAlertOf', { name: 'Dépenses' }) });
    await alert.getByLabel(tr('insightAlertThreshold')).fill('-1');
    await alert.getByRole('button', { name: tr('save') }).click();
    await expect(alert.getByRole('alert')).toHaveText(tr('insightAlertThresholdInvalid'));
    problems.push(...await axeViolations(page, tr('insightAlertTitle')));
    await page.keyboard.press('Escape');
    await expect(alert).toHaveCount(0);

    // Insights to add: one hidden first, so that the list is not empty
    await page.getByRole('button', { name: tr('hideInsightOf', { name: 'Dépenses' }) }).click();
    await page.getByRole('button', { name: tr('addInsight') }).click();
    const catalog = page.getByRole('dialog', { name: tr('addInsight') });
    await expect(catalog.locator('.insight-catalog-item')).toHaveCount(1);
    problems.push(...await axeViolations(page, tr('addInsight')));
    await page.keyboard.press('Escape');
    await expect(catalog).toHaveCount(0);

    await openTab(page, tr('tabCategories'));
    await page.getByRole('button', { name: tr('addCategory') }).click();
    const category = page.getByRole('dialog', { name: tr('addCategory') });
    await expect(category).toBeVisible();
    problems.push(...await axeViolations(page, tr('addCategory')));
    await page.keyboard.press('Escape');
    await expect(category).toHaveCount(0);

    // Connecting a bank: its module installed once the woob repository is trusted
    trustWoobRepository();
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('addAccountsFromBank') }).click();
    await page.getByRole('dialog', { name: tr('addAccountsFromBank') }).getByLabel(tr('addAccountsBank')).selectOption({ label: tr('connectNewBank') });
    const connect = page.getByRole('dialog', { name: tr('connectBankTitle') });
    await connect.getByLabel(new RegExp(tr('connectBankModule'))).selectOption('boursorama');
    await expect(connect.locator('fieldset.bank-setup input').first()).toBeVisible();
    await expect(connect.getByRole('button', { name: tr('bankConnect') })).toBeEnabled();
    problems.push(...await axeViolations(page, tr('connectBankTitle')));
    await page.keyboard.press('Escape');
    await expect(connect).toHaveCount(0);

    await page.getByRole('button', { name: tr('addAccountsTo', { bank: 'BNP Paribas (bnp)' }) }).click();
    const add = page.getByRole('dialog', { name: tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' }) });
    await expect(add.getByRole('checkbox')).toHaveCount(2);
    problems.push(...await axeViolations(page, tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' })));
    await page.keyboard.press('Escape');
    await expect(add).toHaveCount(0);

    await page.getByRole('button', { name: tr('renameBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) }).click();
    const rename = page.getByRole('dialog', { name: tr('renameBankConnection') });
    await expect(rename).toBeVisible();
    problems.push(...await axeViolations(page, tr('renameBankConnection')));
    await page.keyboard.press('Escape');
    await expect(rename).toHaveCount(0);

    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    problems.push(...await axeViolations(page, 'sync'));

    expect(problems).toEqual([]);
});

test('axe: the notifications of the profile and the result of the test email', async ({ adminPage: page }) => {
    const problems = [];

    // Profile: its sub-tabs; the notifications table, the email channel disabled with
    // its reason, the settings of the alerts under their label
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    problems.push(...await axeViolations(page, tr('profileTabProfile')));
    await page.getByRole('tab', { name: tr('mfaTitle'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('mfaTitle') })).toBeVisible();
    problems.push(...await axeViolations(page, tr('mfaTitle')));
    await page.getByRole('button', { name: tr('mfaSetupButton') }).click();
    await expect(page.getByRole('img', { name: tr('mfaQrLabel') })).toBeVisible();
    problems.push(...await axeViolations(page, `${tr('mfaTitle')} (${tr('mfaQrLabel')})`));
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, `${tr('mfaTitle')} (${tr('mfaQrLabel')})`));
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.getByRole('tab', { name: tr('myDevices'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('myDevices') })).toBeVisible();
    // Model and versions of the seeded iPhone, in secondary text
    await expect(page.locator('.device-model').first()).toBeVisible();
    problems.push(...await axeViolations(page, tr('myDevices')));
    await page.getByRole('tab', { name: tr('notificationsTitle'), exact: true }).click();
    await expect(page.locator('#notifications-email-unavailable')).toBeVisible();
    problems.push(...await axeViolations(page, tr('notificationsTitle')));
    // At 320 CSS px wide too
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, tr('notificationsTitle')));
    await page.setViewportSize({ width: 1280, height: 720 });
    // The budget alert threshold refused: the field invalid, its error message
    await page.getByLabel(tr('budgetThreshold')).fill('250');
    await page.getByLabel(tr('budgetThreshold')).press('Enter');
    await expect(page.locator('#budget-threshold-error')).toBeVisible();
    problems.push(...await axeViolations(page, `${tr('budgetThreshold')} (error)`));
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, `${tr('budgetThreshold')} (error)`));
    await page.setViewportSize({ width: 1280, height: 720 });
    // A threshold of a category, refused: its field, its remove button, its error message
    await page.locator('#budget-threshold').fill('100');
    await page.locator('#budget-threshold').press('Enter');
    await page.getByRole('button', { name: tr('budgetThresholdAdd') }).click();
    await page.locator('#budget-row-threshold-0').fill('250');
    await page.locator('#budget-row-threshold-0').press('Enter');
    await expect(page.locator('#budget-rows-error')).toBeVisible();
    problems.push(...await axeViolations(page, `${tr('budgetCategoryThresholds')} (error)`));
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, `${tr('budgetCategoryThresholds')} (error)`));
    await page.setViewportSize({ width: 1280, height: 720 });

    // Settings: the test email failed (no SMTP server configured)
    await showTab(page, 'tabSettings');
    await page.getByRole('button', { name: tr('smtpTestButton') }).click();
    await expect(page.locator('.smtp-test-result[role="alert"]')).toBeVisible();
    problems.push(...await axeViolations(page, tr('smtpTestTitle')));
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, tr('smtpTestTitle')));

    expect(problems).toEqual([]);
});

test('axe: the import of a backup, its files, its confirmation, at 320 CSS px too', async ({ adminPage: page }) => {
    const problems = [];
    await showTab(page, 'tabBackup');
    const backup = { format: 'carbure-backup', version: 1, created_at: '2026-10-16T10:00:00+02:00', carbure: 'dev', datasets: { rules: [], budgets: [] } };
    await page.locator('#backup-file').setInputFiles({ name: 'backup.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(backup)) });
    await expect(page.locator('input[data-file]')).toHaveCount(2);
    problems.push(...await axeViolations(page, tr('backupImportChoose')));
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, tr('backupImportChoose')));
    await page.setViewportSize({ width: 1280, height: 720 });

    await page.locator('.backup-import-button').click();
    const dialog = page.getByRole('dialog', { name: tr('backupImportConfirmTitle') });
    await expect(dialog).toBeVisible();
    problems.push(...await axeViolations(page, tr('backupImportConfirmTitle')));
    await page.keyboard.press('Escape');

    expect(problems).toEqual([]);
});

test('axe: the purge, a dataset ticked with its dependents, its confirmation, refused from Internet', async ({ adminPage: page }) => {
    const problems = [];
    await showTab(page, 'tabPurge');
    await page.locator('input[data-dataset="categories"]').check();
    problems.push(...await axeViolations(page, tr('purgeTitle')));
    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, tr('purgeTitle')));
    await page.setViewportSize({ width: 1280, height: 720 });

    await page.locator('.purge-button').click();
    await expect(page.getByRole('dialog', { name: tr('purgeConfirmTitle') })).toBeVisible();
    problems.push(...await axeViolations(page, tr('purgeConfirmTitle')));
    await page.keyboard.press('Escape');

    await page.setExtraHTTPHeaders({ 'X-Forwarded-For': '203.0.113.7' });
    await showTab(page, 'tabBackup');
    await showTab(page, 'tabPurge');
    await expect(page.locator('.purge-not-local')).toBeVisible();
    problems.push(...await axeViolations(page, tr('purgeNotLocal')));

    expect(problems).toEqual([]);
});

test('reflow: every tab at 320 CSS px wide, without horizontal scrolling', async ({ adminPage: page }) => {
    // 1280 px at 400 % (WCAG 1.4.10)
    await page.setViewportSize({ width: 320, height: 256 });
    expect(await everyTab(page, [...MAIN_TABS, ...ADMIN_TABS], reflowProblems)).toEqual([]);
});

test('zoom: every tab at 200 %, nothing cut nor scrolling horizontally', async ({ adminPage: page }) => {
    // The sizes of the portal are in CSS px: a zoom of 200 % of the browser on a 1280 px
    // screen lays the page out in 640 CSS px (WCAG 1.4.4)
    await page.setViewportSize({ width: 640, height: 400 });
    const check = async (page, where) => [...await reflowProblems(page, where), ...await clippedTexts(page, where)];
    expect(await everyTab(page, [...MAIN_TABS, ...ADMIN_TABS], check)).toEqual([]);
});

test('text spacing: no text cut with the spacing of WCAG 1.4.12', async ({ adminPage: page }) => {
    await page.addStyleTag({ content: TEXT_SPACING });
    expect(await everyTab(page, [...MAIN_TABS, ...ADMIN_TABS], clippedTexts)).toEqual([]);
});

test('reflow: a failed synchronization at 320 CSS px, its update of the bank modules included', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'), { timeout: 15_000 });
    await page.getByRole('button', { name: tr('syncShowLog') }).click();

    await page.setViewportSize({ width: 320, height: 256 });
    const update = page.locator('.sync-woob-help').getByRole('button', { name: tr('woobUpdateButton') });
    await update.scrollIntoViewIfNeeded();
    await expect(update).toBeVisible();
    expect([...await reflowProblems(page, 'sync'), ...await clippedTexts(page, 'sync')]).toEqual([]);
    expect(await axeViolations(page, 'sync 320')).toEqual([]);
});

/**
 * Contrast violations inside Swagger UI, framed by the API tab. Its other rules are not
 * run: the markup of Swagger UI (a third-party bundle, swagger/vendor) nests buttons and
 * has small targets, which Carbure cannot change; its colors are fixed by swagger/carbure.css.
 * @param {import('@playwright/test').Page} page
 * @param {string} where - Name of the screen (for the report)
 * @returns {Promise<string[]>}
 */
async function swaggerContrastProblems(page, where) {
    const results = await new AxeBuilder({ page }).include('iframe.api-docs-frame').withRules(['color-contrast']).analyze();
    return results.violations.flatMap(v => v.nodes.map(n => `${where}: ${v.id} ${n.target.join(' ')}: ${n.failureSummary.split('\n').slice(1).join(' ').trim()}`));
}

test('the API tab: axe, reflow at 320 CSS px, zoom at 200 % and text spacing', async ({ adminPage: page }) => {
    // A test of its own: Swagger UI, framed by the tab, is long to load and to analyze
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabApi'), exact: true }).click();
    const swagger = page.frameLocator('iframe.api-docs-frame');
    await expect(swagger.locator('.opblock').first()).toBeVisible({ timeout: 15_000 });
    const where = tr('tabApi');
    // The portal (the name of the frame checked apart), then the colors of Swagger UI
    // in both themes of the portal
    const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).exclude('iframe.api-docs-frame').analyze();
    await expect(page.locator('iframe.api-docs-frame')).toHaveAccessibleName(tr('apiDocsFrameTitle'));
    const problems = results.violations.flatMap(v => v.nodes.map(n => `${where}: ${v.id} ${n.target.join(' ')}`));
    problems.push(...await swaggerContrastProblems(page, `${where} light`));
    await page.locator('.header-user > button').first().click();
    await expect(swagger.locator('html')).toHaveClass(/dark-mode/);
    problems.push(...await swaggerContrastProblems(page, `${where} dark`));

    await page.setViewportSize({ width: 320, height: 256 });
    problems.push(...await reflowProblems(page, `${where} 320`), ...await clippedTexts(page, `${where} 320`));
    await page.setViewportSize({ width: 640, height: 400 });
    problems.push(...await reflowProblems(page, `${where} 200 %`), ...await clippedTexts(page, `${where} 200 %`));
    await page.setViewportSize({ width: 1280, height: 720 });
    await page.addStyleTag({ content: TEXT_SPACING });
    problems.push(...await clippedTexts(page, `${where} spacing`));

    expect(problems).toEqual([]);
});

test('German, the longest texts: every tab at 320 CSS px and at 200 %, nothing cut', async ({ adminPage: page }) => {
    await chooseLanguage(page, 'fr', 'de');
    await expect(page.locator('html')).toHaveAttribute('lang', 'de');
    const tabs = [...MAIN_TABS, ...ADMIN_TABS];
    await page.setViewportSize({ width: 320, height: 256 });
    const problems = await everyTab(page, tabs, reflowProblems, 'de');
    await page.setViewportSize({ width: 640, height: 400 });
    const check = async (page, where) => [...await reflowProblems(page, where), ...await clippedTexts(page, where)];
    problems.push(...await everyTab(page, tabs, check, 'de'));
    await page.setViewportSize({ width: 1280, height: 720 });
    problems.push(...await everyTab(page, tabs, clippedTexts, 'de'));
    expect(problems).toEqual([]);
});

test('axe: every tab in Spanish, Italian and Portuguese', async ({ adminPage: page }) => {
    const problems = [];
    let from = 'fr';
    for (const locale of ['es', 'it', 'pt']) {
        await chooseLanguage(page, from, locale);
        problems.push(...await everyTab(page, MAIN_TABS, axeViolations, locale));
        from = locale;
    }
    expect(problems).toEqual([]);
});

test('axe: the tags, their form and a summary', async ({ adminPage: page, api }) => {
    // An active tag with transactions, an archived one
    const tag = await (await api.post('/api/tag', { data: { name: 'voyage', color: 'green', start_date: '2026-01-01' } })).json();
    await api.post('/api/tag', { data: { name: 'ete-2020', color: 'orange', start_date: '2020-06-01', end_date: '2020-08-31' } });
    const transactions = await (await api.get('/api/transaction')).json();
    await api.put('/api/transaction/tags', { data: { ids: transactions.slice(0, 3).map(t => t.id), add: [tag.id] } });
    const problems = [];

    await openTab(page, tr('tabTags'));
    await page.getByRole('button', { name: tr('tagNew') }).click();
    await expect(page.getByRole('dialog', { name: tr('tagNew') })).toBeVisible();
    problems.push(...await axeViolations(page, tr('tagNew')));
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: tr('tagSummaryOf', { name: 'voyage' }) }).click();
    await expect(page.locator('.tag-summary .tag-category').first()).toBeVisible();
    problems.push(...await axeViolations(page, tr('tagSummaryOf', { name: 'voyage' })));

    // The chips of the rows and the selection of the list
    await openTab(page, tr('tabTransactions'));
    await page.getByRole('button', { name: tr('tagSelect'), exact: true }).click();
    await expect(page.locator('.tag-selection-bar')).toBeVisible();
    problems.push(...await axeViolations(page, tr('tagSelect')));
    expect(problems).toEqual([]);
});

test('axe: the bar of the recurring transactions, folded and unfolded, at 320 CSS px too (#465)', async ({ adminPage: page }) => {
    const problems = [];
    const bar = page.getByRole('region', { name: tr('recurringSummary') });
    const unfold = bar.getByRole('button', { name: tr('recurringDetails') });
    await expect(unfold).toHaveAttribute('aria-expanded', 'false');
    problems.push(...await axeViolations(page, `${tr('recurringSummary')} (folded)`));

    await unfold.click();
    await expect(page.locator('#recurring-bar-details')).toBeVisible();
    problems.push(...await axeViolations(page, `${tr('recurringSummary')} (unfolded)`));
    await page.setViewportSize({ width: 320, height: 720 });
    problems.push(...await reflowProblems(page, `${tr('recurringSummary')} (unfolded, 320 px)`));
    expect(problems).toEqual([]);
});

test('axe: the note of a budget, on its card and in the budget editor (#468)', async ({ adminPage: page, api }) => {
    const { month, year } = currentMonth();
    await api.post('/api/budget', { data: { category: 4, amount: 1000, month, year, note: 'Loyer + charges, régularisation en juin' } });
    const problems = [];

    await openTab(page, tr('tabBudget'));
    await page.getByRole('tab', { name: tr('budgetConfigTab') }).click();
    const card = page.locator('.budget-card').filter({ has: page.locator('.budget-card-note') });
    await expect(card).toHaveCount(1);
    problems.push(...await axeViolations(page, tr('budgetNoteLabel')));

    await card.getByRole('button', { name: tr('budgetEditTitle') }).click();
    await expect(page.getByRole('dialog', { name: tr('budgetEditTitle') })).toBeVisible();
    problems.push(...await axeViolations(page, `${tr('budgetEditTitle')} (${tr('budgetNoteLabel')})`));
    expect(problems).toEqual([]);
});

test('axe: the cards of the insights with their move handles, one of them dragged (#467)', async ({ adminPage: page, api }) => {
    const sql = 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE MONTH(date) = {month} AND YEAR(date) = {year}';
    await api.post('/api/insight', { data: { name: 'Revenus', color: 'green', sql } });
    const problems = [];

    await openTab(page, tr('tabInsights'));
    await expect(page.getByRole('button', { name: tr('insightMoveOf', { name: 'Dépenses' }) })).toBeVisible();
    problems.push(...await axeViolations(page, tr('insightMove')));

    const box = await page.locator('.insight-card .insight-drag').first().boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 + 5, box.y + box.height / 2 + 5);
    await expect(page.locator('.insight-card.dragging')).toHaveCount(1);
    problems.push(...await axeViolations(page, `${tr('insightMove')} (dragging)`));
    await page.mouse.up();
    expect(problems).toEqual([]);
});
