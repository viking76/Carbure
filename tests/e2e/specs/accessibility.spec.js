// Accessibility: every text of the portal is readable (WCAG 2.2 AA contrast), the
// buttons at rest and on hover, on every tab and in the modals (see a11y.js)
const { test, expect, tr, openTab, currentMonth, offerBudgetCopy, trustWoobRepository, asDemoInstance, woobFile } = require('../fixtures');
const { contrastProblems } = require('../a11y');
const { startFakeSmtp } = require('../fake-smtp');
const { startFakePushRelay } = require('../fake-push-relay');
const path = require('path');
const fs = require('fs');

// Hovering every button of every screen takes time
test.describe.configure({ timeout: 180_000 });

test.beforeEach(async ({ page }) => {
    // No transition: the colors are measured once the hover is applied
    await page.emulateMedia({ reducedMotion: 'reduce' });
});

test('the login screen is readable', async ({ page }) => {
    await page.goto('/portal/');
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    expect(await contrastProblems(page, 'login')).toEqual([]);
});

test('the login screen of a demonstration instance is readable', async ({ page }) => {
    await asDemoInstance(page);
    await page.goto('/portal/');
    await expect(page.locator('.login-demo')).toBeVisible();
    expect(await contrastProblems(page, 'login demo')).toEqual([]);
});

test('every tab is readable, buttons on hover included', async ({ adminPage: page }) => {
    const problems = [];
    const tabs = ['tabTransactions', 'tabBudget', 'tabInsights', 'tabTrends', 'tabTags', 'tabRules', 'tabAccounts'];
    for (const tab of tabs) {
        await openTab(page, tr(tab));
        await expect(page.locator('.tab-button.active')).toContainText(tr(tab));
        await page.waitForLoadState('networkidle');
        // The tabs are drawn again once the data is loaded
        await page.waitForTimeout(300);
        problems.push(...await contrastProblems(page, tr(tab)));
    }

    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    for (const tab of ['tabUsers', 'tabCategories', 'tabSync', 'tabPush', 'tabAgents', 'tabLogs', 'tabSettings', 'tabBackup', 'tabPurge']) {
        await page.getByRole('tab', { name: tr(tab), exact: true }).click();
        await expect(page.getByRole('tab', { name: tr(tab), exact: true })).toHaveAttribute('aria-selected', 'true');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(300);
        problems.push(...await contrastProblems(page, tr(tab)));
    }

    expect(problems).toEqual([]);
});

test('the chart of the categories is readable: kinds, chips shown and hidden, hint', async ({ userPage: page }) => {
    const problems = [];
    await openTab(page, tr('tabTrends'));
    const card = page.locator('.trend-categories-card');
    await card.getByRole('button', { name: tr('trendsKindLines') }).click();
    await card.getByRole('button', { name: 'Logement' }).click();
    await expect(card.getByRole('button', { name: 'Logement' })).toHaveAttribute('aria-pressed', 'false');
    problems.push(...await contrastProblems(page, `${tr('trendCategoriesTitle')} (filtered)`));

    await card.getByRole('button', { name: tr('trendCategoriesNone') }).click();
    await expect(card.getByText(tr('trendCategoriesNoneShown'))).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('trendCategoriesTitle')} (none)`));
    expect(problems).toEqual([]);
});

test('the import of a backup is readable: its files, an invalid one, the confirmation', async ({ adminPage: page }) => {
    const problems = [];
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabBackup'), exact: true }).click();
    await expect(page.locator('#backup-file')).toBeAttached();

    // A whole export saved as JSON (its datasets listed) is accepted like a ZIP
    const rules = { format: 'carbure-backup', version: 1, datasets: { rules: [], budgets: [] } };
    await page.locator('#backup-file').setInputFiles({ name: 'backup.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(rules)) });
    await expect(page.locator('input[data-file]')).toHaveCount(2);
    problems.push(...await contrastProblems(page, tr('backupImportChoose')));

    await page.locator('.backup-import-button').click();
    await expect(page.getByRole('dialog', { name: tr('backupImportConfirmTitle') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('backupImportConfirmTitle')));
    await page.getByRole('dialog').getByRole('button', { name: tr('cancel') }).click();

    await page.getByRole('button', { name: tr('backupOtherFile') }).click();
    await page.locator('#backup-file').setInputFiles({ name: 'notes.json', mimeType: 'application/json', buffer: Buffer.from('not json') });
    await expect(page.locator('.backup-dataset.invalid')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('backupFileInvalid')));

    expect(problems).toEqual([]);
});

test('the notifications of the profile and the test email are readable', async ({ adminPage: page }) => {
    const problems = [];

    // Profile: its sub-tabs, the notifications without SMTP server (email disabled, its
    // reason) with the settings of the alerts under their label
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    problems.push(...await contrastProblems(page, tr('profileTabProfile')));
    await page.getByRole('tab', { name: tr('mfaTitle'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('mfaTitle') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('mfaTitle')));
    await page.getByRole('tab', { name: tr('myDevices'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('myDevices') })).toBeVisible();
    // Model and versions of the seeded iPhone, in secondary text
    await expect(page.locator('.device-model').first()).toBeVisible();
    problems.push(...await contrastProblems(page, tr('myDevices')));
    await page.getByRole('tab', { name: tr('notificationsTitle'), exact: true }).click();
    await expect(page.locator('#notifications-email-unavailable')).toBeVisible();
    await expect(page.locator('#notifications-push-unavailable')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('notificationsTitle')));
    // The large expense threshold refused: its error message
    await page.getByLabel(tr('alertThreshold')).fill('-1');
    await page.getByLabel(tr('alertThreshold')).press('Enter');
    await expect(page.locator('#alert-threshold-error')).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('alertThreshold')} (error)`));
    // The budget alert threshold refused: its error message
    await page.getByLabel(tr('budgetThreshold')).fill('20');
    await page.getByLabel(tr('budgetThreshold')).press('Enter');
    await expect(page.locator('#budget-threshold-error')).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('budgetThreshold')} (error)`));

    // Settings: the test email failed (no SMTP server), then sent
    const smtp = await startFakeSmtp();
    try {
        await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
        await page.getByRole('tab', { name: tr('tabSettings'), exact: true }).click();
        // The warning of a setting with a risk (woob_relabel)
        await expect(page.locator('.config-warning').first()).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('configWarningLabel')} (woob_relabel)`));
        await page.getByRole('button', { name: tr('smtpTestButton') }).click();
        await expect(page.locator('.smtp-test-result[role="alert"]')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('smtpTestTitle')} (error)`));

        await page.locator('#config-smtp_host').fill('127.0.0.1');
        await page.locator('#config-smtp_port').fill(String(smtp.port));
        await page.locator('#config-smtp_from').fill('carbure@example.com');
        // A setting changed: the note of the test email
        await expect(page.locator('.smtp-test-note')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('smtpTestTitle')} (unsaved)`));
        await page.getByRole('button', { name: tr('save') }).click();
        await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
        await page.getByRole('button', { name: tr('smtpTestButton') }).click();
        await expect(page.locator('.smtp-test-ok')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('smtpTestTitle')} (sent)`));
    } finally {
        await smtp.close();
    }

    expect(problems).toEqual([]);
});

test('the registration on the push service and the test push are readable', async ({ adminPage: page }) => {
    const problems = [];
    const relay = await startFakePushRelay();
    try {
        await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
        await page.getByRole('tab', { name: tr('tabPush'), exact: true }).click();
        // Not registered yet: the status, the note, the disabled test button
        await expect(page.locator('.push-status-item').nth(1)).toContainText(tr('pushKeyNone'));
        await expect(page.getByText(tr('pushUnavailableAdmin'))).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('pushRegisterTitle')} (no key)`));

        await page.getByRole('button', { name: tr('pushRegisterButton') }).click();
        // The consent: at rest, then accepted (the register button enabled)
        const consent = page.getByRole('dialog', { name: tr('pushConsentTitle') });
        await expect(consent).toBeVisible();
        problems.push(...await contrastProblems(page, tr('pushConsentTitle')));
        await consent.getByLabel(tr('pushConsentAccept')).check();
        await expect(consent.getByRole('button', { name: tr('pushConsentSubmit') })).toBeEnabled();
        problems.push(...await contrastProblems(page, `${tr('pushConsentTitle')} (accepted)`));
        await consent.getByRole('button', { name: tr('pushConsentSubmit') }).click();
        await expect(page.locator('.push-register .push-ok')).toBeVisible();
        await expect(page.locator('.push-consent-given')).toBeVisible();
        await page.getByRole('button', { name: tr('pushTestButton') }).click();
        await expect(page.locator('.push-test .push-ok')).toBeVisible();
        await expect(page.locator('.push-status-item').nth(1)).toContainText(tr('pushKeyValid', { id: relay.keys[0].slice(4, 20), devices: 1, devicesLimit: 20, used: 1, usedLimit: 500 }));
        problems.push(...await contrastProblems(page, `${tr('pushTestTitle')} (sent)`));
        // The API key of the instance, masked then shown
        await expect(page.locator('.push-key-value')).toBeVisible();
        problems.push(...await contrastProblems(page, tr('pushKeyTitle')));
        await page.getByRole('button', { name: tr('pushKeyShow') }).click();
        problems.push(...await contrastProblems(page, `${tr('pushKeyTitle')} (shown)`));

        // The push service unreachable: the status and the error of the renewal
        await relay.close();
        await page.getByRole('button', { name: tr('pushStatusRefresh') }).click();
        await expect(page.locator('.push-status-item').nth(0)).toContainText(tr('pushRelayUnreachable'));
        await page.getByRole('button', { name: tr('pushRenewButton') }).click();
        await expect(page.locator('.push-register .push-result[role="alert"]')).toBeVisible();
        problems.push(...await contrastProblems(page, `${tr('pushRegisterTitle')} (error)`));
    } finally {
        await relay.close();
    }

    expect(problems).toEqual([]);
});

test('the API tab is readable, its link on hover included', async ({ adminPage: page }) => {
    // A test of its own: Swagger UI, framed by the tab, is long to load
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabApi'), exact: true }).click();
    await expect(page.getByRole('tab', { name: tr('tabApi'), exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(page.frameLocator('iframe.api-docs-frame').locator('.opblock').first()).toBeVisible({ timeout: 15_000 });
    expect(await contrastProblems(page, tr('tabApi'))).toEqual([]);
});

test('the accounts grouped under their bank are readable', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    // A bank with an account, a bank without (its hint), the other accounts
    await expect(page.locator('.bank-group')).toHaveCount(3);
    await expect(page.locator('.bank-group-empty')).toBeVisible();
    await expect(page.locator('.bank-group-others')).toBeVisible();
    expect(await contrastProblems(page, tr('tabAccounts'))).toEqual([]);
});

test('the menus, modals and the synchronization log are readable', async ({ adminPage: page, api }) => {
    const problems = [];
    // A transaction with a comment: shown under its date in the list
    const [commented] = await (await api.get('/api/transaction')).json();
    await api.put('/api/transaction/comment', { data: { id: commented.id, comment: 'Remboursé par Paul en juin, la moitié du montant' } });
    await page.reload();
    await expect(page.locator('.transaction-comment')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('transactionComment')));

    await page.locator('.user-menu-toggle').click();
    problems.push(...await contrastProblems(page, 'user menu'));
    await page.keyboard.press('Escape');

    await page.locator('.transaction-item').first().getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(detail).toBeVisible();
    problems.push(...await contrastProblems(page, tr('transactionDetail')));
    // The date it is counted on: a labelled field, with its help, readable on focus
    const countedOn = detail.getByLabel(tr('transactionCountedOn'));
    await expect(countedOn).toHaveAccessibleDescription(tr('transactionCountedOnHelp'));
    await countedOn.focus();
    problems.push(...await contrastProblems(page, `${tr('transactionCountedOn')} (focus)`));
    // The comment: a labelled field, with its help, readable on focus
    const comment = detail.getByLabel(tr('transactionComment'));
    await expect(comment).toHaveAccessibleDescription(tr('transactionCommentHelp'));
    await comment.focus();
    problems.push(...await contrastProblems(page, `${tr('transactionComment')} (focus)`));
    // Split of the transaction, with the amount left to allocate and why it cannot be saved yet
    await detail.getByRole('button', { name: tr('splitButton') }).click();
    const split = page.getByRole('dialog', { name: tr('splitTitle') });
    await expect(split).toBeVisible();
    await split.getByLabel(tr('splitPartAmount', { number: 1 })).fill('1');
    problems.push(...await contrastProblems(page, tr('splitTitle')));
    await page.keyboard.press('Escape');
    await expect(split).toHaveCount(0);

    // A transaction entered by hand: without manual account, on the synchronized one (with its help)
    await page.locator('.transactions-toolbar').getByRole('button', { name: tr('manualTransactionTitle') }).click();
    const manual = page.getByRole('dialog', { name: tr('manualTransactionTitle') });
    await expect(manual.getByText(tr('manualSyncedHelp'))).toBeVisible();
    problems.push(...await contrastProblems(page, tr('manualTransactionTitle')));
    await page.keyboard.press('Escape');
    await expect(manual).toHaveCount(0);

    // Advanced search: its modal (categories and accounts), then the chips of its criteria
    await page.locator('.transaction-search-main').getByRole('button', { name: tr('advancedSearch') }).click();
    const advanced = page.getByRole('dialog', { name: tr('advancedSearch') });
    await expect(advanced.getByRole('group', { name: tr('advancedSearchAccounts') })).toBeVisible();
    await advanced.getByRole('radio', { name: tr('advancedSearchExpenses') }).check();
    await advanced.getByLabel(tr('advancedSearchMin', { currency: '€' })).fill('100');
    problems.push(...await contrastProblems(page, tr('advancedSearch')));
    await advanced.getByRole('button', { name: tr('advancedSearchApply') }).click();
    await expect(page.locator('.search-filter-chip')).toHaveCount(2);
    problems.push(...await contrastProblems(page, tr('advancedSearchFilters')));
    await page.locator('.search-filters').getByRole('button', { name: tr('clearSearch') }).click();
    await expect(page.locator('.search-filters')).toHaveCount(0);

    // Preview of the import of a statement file read with the profile of its bank
    await page.getByRole('button', { name: tr('importButton') }).click();
    const importModal = page.getByRole('dialog', { name: tr('importTitle') });
    await importModal.locator('input[type="file"]').setInputFiles(path.join(__dirname, '../../fixtures/import/boursorama.csv'));
    await expect(importModal.locator('.import-bank')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('importTitle')));
    await page.keyboard.press('Escape');
    await expect(importModal).toHaveCount(0);

    // Copy of the budgets of the previous month (Budgets view)
    // Offered when the month has no budget and the previous month has some
    await offerBudgetCopy(api);
    await openTab(page, tr('tabBudget'));
    await page.getByRole('tab', { name: tr('budgetConfigTab') }).click();
    await page.locator('.budget-copy-button').click();
    const copy = page.getByRole('dialog', { name: tr('budgetCopyTitle') });
    await expect(copy).toBeVisible();
    problems.push(...await contrastProblems(page, tr('budgetCopyTitle')));
    await page.keyboard.press('Escape');
    await expect(copy).toHaveCount(0);

    await openTab(page, tr('tabCategories'));
    await page.getByRole('button', { name: tr('addCategory') }).click();
    const category = page.getByRole('dialog', { name: tr('addCategory') });
    await expect(category).toBeVisible();
    problems.push(...await contrastProblems(page, tr('addCategory')));
    await page.keyboard.press('Escape');
    await expect(category).toHaveCount(0);

    // Accounts: update of the bank modules, the fingerprint of the woob repository to compare
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('woobUpdateButton') }).click();
    const woobUpdate = page.getByRole('dialog', { name: tr('woobUpdateTitle') });
    await expect(woobUpdate.getByRole('button', { name: tr('woobTrustAndUpdate') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('woobUpdateTitle')));
    await page.keyboard.press('Escape');
    await expect(woobUpdate).toHaveCount(0);

    // Connecting a bank (its settings asked, its module installed once the woob repository
    // is trusted), then the accounts woob lists for one
    trustWoobRepository();
    await page.getByRole('button', { name: tr('addAccountsFromBank') }).click();
    await page.getByRole('dialog', { name: tr('addAccountsFromBank') }).getByLabel(tr('addAccountsBank')).selectOption({ label: tr('connectNewBank') });
    const connect = page.getByRole('dialog', { name: tr('connectBankTitle') });
    await connect.getByLabel(new RegExp(tr('connectBankModule'))).selectOption('boursorama');
    await expect(connect.locator('fieldset.bank-setup input').first()).toBeVisible();
    // Measured once the settings are loaded: the connect button is enabled (not mid-change)
    await expect(connect.getByRole('button', { name: tr('bankConnect') })).toBeEnabled();
    problems.push(...await contrastProblems(page, tr('connectBankTitle')));
    await page.keyboard.press('Escape');
    await expect(connect).toHaveCount(0);

    await page.getByRole('button', { name: tr('addAccountsTo', { bank: 'BNP Paribas (bnp)' }) }).click();
    const add = page.getByRole('dialog', { name: tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' }) });
    await expect(add.getByRole('checkbox')).toHaveCount(2);
    problems.push(...await contrastProblems(page, tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' })));
    await page.keyboard.press('Escape');
    await expect(add).toHaveCount(0);

    // A bank that asks a strong authentication: woob waits for the approval in its app (#299)
    fs.writeFileSync(woobFile('carbure-fake-woob-2fa-wait'), '');
    const discoverBnp = url => url.pathname.endsWith('/api/bank/discover') && url.searchParams.get('backend') === 'bnp';
    const interactiveBnp = url => url.pathname.endsWith('/api/bank/discover/interactive') && url.searchParams.get('backend') === 'bnp';
    await page.route(discoverBnp, route => route.fulfill({
        status: 500,
        json: { error: 'Error(bnp): You have to run bank in interactive mode to perform a two-factor authentication', code: 500 }
    }));
    await page.route(interactiveBnp, route => route.continue({ url: route.request().url().replace('backend=bnp', 'backend=2fa') }));
    await page.getByRole('button', { name: tr('addAccountsTo', { bank: 'BNP Paribas (bnp)' }) }).click();
    await expect(add.getByText(tr('discover2faWaiting'))).toBeVisible();
    await expect(add.getByRole('status').filter({ hasText: 'Veuillez valider' })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('addAccountsTitle', { bank: 'BNP Paribas (bnp)' })));
    // Approved: woob ends (and frees the synchronization lock)
    fs.writeFileSync(woobFile('carbure-fake-woob-validated'), '');
    await expect(add.getByRole('checkbox')).toHaveCount(2);
    await page.keyboard.press('Escape');
    await expect(add).toHaveCount(0);
    await page.unroute(discoverBnp);
    await page.unroute(interactiveBnp);

    await page.getByRole('button', { name: tr('renameBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) }).click();
    const rename = page.getByRole('dialog', { name: tr('renameBankConnection') });
    await expect(rename).toBeVisible();
    problems.push(...await contrastProblems(page, tr('renameBankConnection')));
    await page.keyboard.press('Escape');
    await expect(rename).toHaveCount(0);

    // Confirmation of the removal of a bank: at rest, then its action running (the
    // request held, its button with a loader), then failed (the error in the modal)
    let release;
    const held = new Promise(resolve => { release = resolve; });
    await page.route('**/api/bank/backend?backend=bnp', async route => {
        await held;
        await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ error: 'woob could not remove the bank bnp', code: 500 }) });
    });
    await page.getByRole('button', { name: tr('deleteBankConnectionOf', { bank: 'BNP Paribas (bnp)' }) }).click();
    const confirmRemoval = page.getByRole('dialog', { name: tr('deleteBankConnection') });
    await expect(confirmRemoval).toBeVisible();
    problems.push(...await contrastProblems(page, tr('deleteBankConnection')));
    await confirmRemoval.getByRole('button', { name: tr('bankConnectionDeleteButton') }).click();
    await expect(confirmRemoval.getByRole('button', { name: tr('bankConnectionDeleting') })).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('deleteBankConnection')} (busy)`));
    release();
    await expect(confirmRemoval.getByRole('alert')).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('deleteBankConnection')} (error)`));
    await page.keyboard.press('Escape');
    await expect(confirmRemoval).toHaveCount(0);
    await page.unroute('**/api/bank/backend?backend=bnp');

    // A synchronization in error, with its log open
    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'));
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    problems.push(...await contrastProblems(page, 'sync'));

    expect(problems).toEqual([]);
});

test('the update of the bank modules offered after a failed synchronization is readable', async ({ adminPage: page }) => {
    await openTab(page, tr('tabAccounts'));
    await page.locator('.account-item').filter({ hasText: 'fail@bank' }).getByRole('button', { name: tr('syncAccount') }).click();
    await expect(page.locator('.sync-status')).toContainText(tr('syncStatus_error'), { timeout: 15_000 });

    const update = page.locator('.sync-woob-help').getByRole('button', { name: tr('woobUpdateButton') });
    // Log collapsed, then open: the help and its button at rest and on hover
    await expect(update).toBeVisible();
    const problems = [...await contrastProblems(page, 'sync help')];
    await page.getByRole('button', { name: tr('syncShowLog') }).click();
    await expect(page.locator('.sync-log')).toBeVisible();
    problems.push(...await contrastProblems(page, 'sync help (log open)'));

    // On keyboard focus: a ring of 2px at least, the text still readable (the log before
    // it is focusable too, to scroll it with the keyboard)
    await update.focus();
    await page.keyboard.press('Shift+Tab');
    await expect(page.getByRole('list', { name: tr('syncLogLabel') })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(update).toBeFocused();
    const outline = await update.evaluate(el => {
        const style = getComputedStyle(el);
        return { style: style.outlineStyle, width: parseFloat(style.outlineWidth) };
    });
    expect(outline.style).not.toBe('none');
    expect(outline.width).toBeGreaterThanOrEqual(2);
    problems.push(...await contrastProblems(page, 'sync help (focus)'));
    expect(problems).toEqual([]);

    // In forced colors: a visible border, like the other buttons
    await page.emulateMedia({ forcedColors: 'active' });
    const border = await update.evaluate(el => {
        const style = getComputedStyle(el);
        return { style: style.borderTopStyle, width: parseFloat(style.borderTopWidth) };
    });
    expect(border.style).not.toBe('none');
    expect(border.width).toBeGreaterThanOrEqual(1);
});

test('the two-factor authentication is readable: login steps and profile', async ({ page, api }) => {
    const { totp } = require('../totp');
    const problems = [];
    await api.put('/api/system/config', { data: { values: { mfa_required: true } } });

    // Second step of the login: registration of the app (QR code), wrong code
    await page.goto('/portal/');
    await page.getByLabel(tr('username')).fill('marie');
    await page.getByLabel(tr('password')).fill('marie-password');
    await page.getByRole('button', { name: tr('loginButton') }).click();
    await expect(page.getByRole('img', { name: tr('mfaQrLabel') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('mfaEnrollTitle')));
    const secret = (await page.locator('.mfa-secret').textContent()).trim();
    await page.getByLabel(tr('mfaCode')).fill('000000');
    await page.getByRole('button', { name: tr('mfaVerifyButton') }).click();
    await expect(page.getByRole('alert')).toContainText(tr('mfaInvalidCode'));
    problems.push(...await contrastProblems(page, `${tr('mfaEnrollTitle')} (error)`));

    // Recovery codes, then the profile block (app enabled, trusted browser)
    await page.getByLabel(tr('mfaCode')).fill(totp(secret));
    await page.getByLabel(tr('mfaTrustBrowser')).check();
    await page.getByRole('button', { name: tr('mfaVerifyButton') }).click();
    await expect(page.getByRole('heading', { name: tr('mfaRecoveryTitle') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('mfaRecoveryTitle')));
    await page.getByRole('button', { name: tr('mfaRecoveryContinue') }).click();
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await page.getByRole('tab', { name: tr('mfaTitle'), exact: true }).click();
    await expect(page.locator('.profile-mfa')).toContainText(tr('mfaOn'));
    problems.push(...await contrastProblems(page, tr('mfaTitle')));
    await page.getByRole('tab', { name: tr('myDevices'), exact: true }).click();
    const browser = page.getByRole('list', { name: tr('mfaBrowsersTitle') }).locator('.device-item');
    await expect(browser).toHaveCount(1);
    problems.push(...await contrastProblems(page, `${tr('myDevices')} (${tr('mfaBrowsersTitle')})`));

    // Verification step of a later login (trusted browser revoked)
    await browser.getByRole('button').click();
    await expect(page.locator('.mfa-no-browsers')).toHaveText(tr('mfaNoBrowsers'));
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('logout') }).click();
    await page.getByLabel(tr('username')).fill('marie');
    await page.getByLabel(tr('password')).fill('marie-password');
    await page.getByRole('button', { name: tr('loginButton') }).click();
    await expect(page.getByRole('heading', { name: tr('mfaVerifyTitle') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('mfaVerifyTitle')));

    expect(problems).toEqual([]);
});

test('the registration of an app in the profile is readable', async ({ userPage: page }) => {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await page.getByRole('tab', { name: tr('mfaTitle'), exact: true }).click();
    await page.locator('.profile-mfa').getByRole('button', { name: tr('mfaSetupButton') }).click();
    await expect(page.locator('.profile-mfa').getByRole('img', { name: tr('mfaQrLabel') })).toBeVisible();

    expect(await contrastProblems(page, tr('mfaSetupButton'))).toEqual([]);
});

test('the confirmation resetting the two-factor authentication of a user is readable', async ({ adminPage: page }) => {
    await openTab(page, tr('tabUsers'));
    await page.getByRole('button', { name: `${tr('mfaReset')} marie` }).click();
    const dialog = page.getByRole('dialog', { name: tr('mfaReset') });
    await expect(dialog.locator('#confirm-dialog-warning')).toHaveText(tr('mfaResetWarning'));
    const problems = await contrastProblems(page, tr('mfaReset'));
    await dialog.getByRole('button', { name: tr('cancel') }).click();
    await expect(dialog).toHaveCount(0);

    expect(problems).toEqual([]);
});

test('the tags, their form and a summary are readable', async ({ adminPage: page, api }) => {
    // An active tag with transactions, an archived one
    const tag = await (await api.post('/api/tag', { data: { name: 'voyage', color: 'green', start_date: '2026-01-01' } })).json();
    await api.post('/api/tag', { data: { name: 'ete-2020', color: 'orange', start_date: '2020-06-01', end_date: '2020-08-31' } });
    const transactions = await (await api.get('/api/transaction')).json();
    await api.put('/api/transaction/tags', { data: { ids: transactions.slice(0, 3).map(t => t.id), add: [tag.id] } });
    const problems = [];

    await openTab(page, tr('tabTags'));
    await page.getByRole('button', { name: tr('tagNew') }).click();
    await expect(page.getByRole('dialog', { name: tr('tagNew') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('tagNew')));
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: tr('tagSummaryOf', { name: 'voyage' }) }).click();
    await expect(page.locator('.tag-summary .tag-category').first()).toBeVisible();
    problems.push(...await contrastProblems(page, tr('tagSummaryOf', { name: 'voyage' })));
    // The tooltip of its chart, opened with the keyboard
    await page.getByRole('group', { name: tr('tagChartExplore') }).focus();
    await expect(page.locator('.tag-chart-tooltip')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('tagChartExplore')));
    await page.getByRole('group', { name: tr('tagChartExplore') }).blur();

    // The chips of the rows and the selection of the list
    await openTab(page, tr('tabTransactions'));
    await page.getByRole('button', { name: tr('tagSelect'), exact: true }).click();
    await expect(page.locator('.tag-selection-bar')).toBeVisible();
    problems.push(...await contrastProblems(page, tr('tagSelect')));
    // Out of the selection: the button of the toolbar is "Done" while selecting
    await page.getByRole('button', { name: tr('tagSelectStop'), exact: true }).click();
    await expect(page.locator('.tag-selection-bar')).toHaveCount(0);

    // The tag field of a transaction detail, its list open: a tag highlighted, a creation offered
    await page.locator('.transaction-item:not(:has(.tag-chip))').first().getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    const field = detail.getByRole('combobox', { name: tr('tagsOfTransaction') });
    await field.fill('v');
    await expect(detail.getByRole('option').locator('.tag-input-name')).toHaveText(['#voyage', tr('tagCreateNamed', { name: 'v' })]);
    await field.press('ArrowDown');
    problems.push(...await contrastProblems(page, `${tr('transactionDetail')} (tags)`));
    expect(problems).toEqual([]);
});

test('the bar of the recurring transactions is readable folded and unfolded, its focus visible (#465)', async ({ adminPage: page }) => {
    const problems = [];
    const bar = page.getByRole('region', { name: tr('recurringSummary') });
    const unfold = bar.getByRole('button', { name: tr('recurringDetails') });
    await expect(unfold).toHaveAttribute('aria-expanded', 'false');
    problems.push(...await contrastProblems(page, `${tr('recurringSummary')} (folded)`));

    // The chevron reached with the keyboard: its focus visible, the bar still readable
    await unfold.focus();
    await expect(unfold).toHaveCSS('outline-style', 'solid');
    problems.push(...await contrastProblems(page, `${tr('recurringSummary')} (focus)`));

    await page.keyboard.press('Enter');
    await expect(page.locator('#recurring-bar-details')).toBeVisible();
    problems.push(...await contrastProblems(page, `${tr('recurringSummary')} (unfolded)`));
    // The filter switched on with the keyboard, then the calendar reached with Tab
    const filter = bar.getByRole('switch', { name: tr('recurringOnly') });
    await filter.focus();
    await page.keyboard.press('Space');
    await expect(filter).toBeChecked();
    await page.keyboard.press('Tab');
    const calendar = bar.getByRole('button', { name: tr('recurringCalendarButton') });
    await expect(calendar).toBeFocused();
    await expect(calendar).toHaveCSS('outline-style', 'solid');
    problems.push(...await contrastProblems(page, `${tr('recurringSummary')} (filtered, focus)`));
    expect(problems).toEqual([]);
});

test('the note of a budget is readable: on its card and in the budget editor (#468)', async ({ adminPage: page, api }) => {
    const { month, year } = currentMonth();
    await api.post('/api/budget', { data: { category: 4, amount: 1000, month, year, note: 'Loyer + charges, régularisation en juin' } });
    const problems = [];

    await openTab(page, tr('tabBudget'));
    await page.getByRole('tab', { name: tr('budgetConfigTab') }).click();
    const card = page.locator('.budget-card').filter({ has: page.locator('.budget-card-note') });
    await expect(card).toHaveCount(1);
    problems.push(...await contrastProblems(page, tr('budgetNoteLabel')));

    await card.getByRole('button', { name: tr('budgetEditTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('budgetEditTitle') });
    await modal.getByLabel(tr('budgetNoteLabel')).focus();
    problems.push(...await contrastProblems(page, `${tr('budgetEditTitle')} (${tr('budgetNoteLabel')})`));
    expect(problems).toEqual([]);
});

test('the cards of the insights are readable while ordered: handles, focus and the card dragged (#467)', async ({ adminPage: page, api }) => {
    const sql = 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE MONTH(date) = {month} AND YEAR(date) = {year}';
    await api.post('/api/insight', { data: { name: 'Revenus', color: 'green', sql } });
    const problems = [];

    await openTab(page, tr('tabInsights'));
    const handle = page.getByRole('button', { name: tr('insightMoveOf', { name: 'Dépenses' }) });
    await expect(handle).toBeVisible();
    problems.push(...await contrastProblems(page, tr('insightMove')));

    // The handle focused by the keyboard, after a move
    await handle.focus();
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('.insight-card[data-insight-id] .insight-drag:focus')).toHaveCount(1);
    problems.push(...await contrastProblems(page, `${tr('insightMove')} (focus)`));

    // The card lifted while it is dragged
    const box = await page.locator('.insight-card .insight-drag').first().boundingBox();
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 + 5, box.y + box.height / 2 + 5);
    await expect(page.locator('.insight-card.dragging')).toHaveCount(1);
    problems.push(...await contrastProblems(page, `${tr('insightMove')} (dragging)`));
    await page.mouse.up();
    expect(problems).toEqual([]);
});

test('the custom charts of the trends, their range and their modal are readable', async ({ adminPage: page, api }) => {
    const problems = [];
    await api.put('/api/budget/trends/charts', { data: { charts: [{ type: 'category', id: 1 }, { type: 'insight', id: 1 }] } });
    await openTab(page, tr('tabTrends'));
    await expect(page.locator('.trend-custom-chart')).toHaveCount(2);
    await page.getByRole('button', { name: tr('trendsCustomRange') }).click();
    await expect(page.getByLabel(tr('trendsFrom'), { exact: true })).toBeVisible();
    await page.waitForLoadState('networkidle');
    problems.push(...await contrastProblems(page, tr('trendsCustomRange')));

    await page.getByRole('button', { name: tr('trendChartAdd') }).click();
    const dialog = page.getByRole('dialog', { name: tr('trendChartAdd') });
    await expect(dialog).toBeVisible();
    problems.push(...await contrastProblems(page, tr('trendChartAdd')));
    await dialog.getByRole('combobox', { name: tr('trendChartType') }).selectOption('insight');
    await expect(dialog.getByRole('combobox', { name: tr('trendChartInsight') })).toBeVisible();
    problems.push(...await contrastProblems(page, tr('trendChartInsight')));

    expect(problems).toEqual([]);
});
