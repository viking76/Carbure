// Banners of the administrators: database update, weak session secret and new release of
// Carbure. The e2e database is up to date and the server a development version: the state
// of the schema and the latest release are simulated.
const { test, expect, tr } = require('../fixtures');
const { startFakeSmtp } = require('../fake-smtp');
const { startFakePushRelay } = require('../fake-push-relay');

/**
 * Simulates a database with a pending migration and the example session secret
 * @param {import('@playwright/test').Page} page
 */
async function outdated(page) {
    await page.route('**/api/system/schema', route => route.fulfill({
        json: { version: '2026-10-13_base', pending: ['2026-11-01_example'], weakJwtSecret: true },
    }));
    await page.route('**/api/system/migrate', route => route.fulfill({
        json: { version: '2026-11-01_example', applied: ['2026-11-01_example'] },
    }));
}

test('an administrator is told about a new release, until he hides it', async ({ page, adminPage }) => {
    const release = { enabled: true, current: '1.9.1', latest: '1.10.0', url: 'https://github.com/ltoinel/Carbure/releases/tag/v1.10.0', available: true, checked_at: '2026-11-01 10:00:00' };
    await page.route('**/api/system/release', route => route.fulfill({ json: release }));
    await page.reload();

    const banner = page.locator('.release-banner');
    await expect(banner).toContainText(tr('releaseAvailable', { version: '1.10.0', current: '1.9.1' }));
    const notes = banner.getByRole('link', { name: tr('releaseNotes') });
    await expect(notes).toHaveAttribute('href', release.url);
    await expect(notes).toHaveAttribute('target', '_blank');

    // Hidden for this version, also after a reload
    await banner.getByRole('button', { name: tr('releaseDismiss') }).click();
    await expect(banner).toHaveCount(0);
    await page.reload();
    await expect(page.locator('.schema-banner')).toHaveCount(0);

    // A newer release: shown again
    release.latest = '1.11.0';
    release.url = 'https://github.com/ltoinel/Carbure/releases/tag/v1.11.0';
    await page.reload();
    await expect(page.locator('.release-banner')).toBeVisible();
});

test('a user is never told about the releases', async ({ page, userPage }) => {
    let asked = false;
    await page.route('**/api/system/release', route => { asked = true; return route.fulfill({ status: 403, json: { error: 'Forbidden', code: 403 } }); });
    await page.reload();
    await expect(page.locator('.tabs')).toBeVisible();
    await expect(page.locator('.release-banner')).toHaveCount(0);
    expect(asked).toBe(false);
});

test('an administrator updates the database from the banner', async ({ page, adminPage }) => {
    await outdated(page);
    await page.reload();

    const banner = page.locator('.schema-banner').filter({ hasText: tr('schemaUpdateAvailable', { count: 1 }) });
    await expect(banner).toBeVisible();
    await expect(page.locator('.schema-banner').filter({ hasText: tr('weakJwtSecret') })).toBeVisible();

    await banner.getByRole('button', { name: tr('schemaUpdate') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('migrationDone', { version: '2026-11-01_example' }));
    await expect(banner).toHaveCount(0);
});

test('a user does not see the banners of the administrators', async ({ page, userPage }) => {
    await outdated(page);
    await page.reload();

    await expect(page.locator('.transaction-item').first()).toBeVisible();
    await expect(page.locator('.schema-banner')).toHaveCount(0);
});

test('the up-to-date e2e database shows no banner', async ({ adminPage: page }) => {
    await expect(page.locator('.transaction-item').first()).toBeVisible();
    await expect(page.locator('.schema-banner')).toHaveCount(0);
});

/**
 * Opens a tab of the Administration menu
 * @param {import('@playwright/test').Page} page
 * @param {string} tab - Translation key of the tab
 */
async function openAdministration(page, tab) {
    await page.getByRole('button', { name: tr('tabAdministration') }).click();
    await page.getByRole('tab', { name: tr(tab) }).click();
}

test('an administrator sees the configuration and changes the safe settings', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSettings');
    await expect(page.getByRole('heading', { name: tr('configTitle') })).toBeVisible();

    // Secrets masked, the woob command read-only
    await expect(page.locator('.tab-panel')).not.toContainText('e2e-tests-only');
    const woobPath = page.locator('.config-row').filter({ has: page.locator('code', { hasText: /^woob_path$/ }) });
    await expect(woobPath.locator('.config-readonly')).toHaveText('woob');
    await expect(woobPath.locator('input, select')).toHaveCount(0);

    await page.locator('#config-log_level').selectOption('info');
    await page.locator('#config-woob_transactions').fill('250');
    await expect(page.locator('.config-actions')).toContainText(tr('configChanges', { count: 2 }));
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
    await expect(page.locator('.config-actions')).toContainText(tr('configNoChange'));

    // Saved in the file
    await page.getByRole('tab', { name: tr('tabLogs') }).click();
    await page.getByRole('tab', { name: tr('tabSettings') }).click();
    await expect(page.locator('#config-log_level')).toHaveValue('info');
    await expect(page.locator('#config-woob_transactions')).toHaveValue('250');
});

test('the relabeling of the synchronized transactions is off by default, with its warning (#501)', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSettings');
    const row = page.locator('.config-row').filter({ has: page.locator('code', { hasText: /^woob_relabel$/ }) });
    await expect(row.locator('.config-help')).toHaveText(tr('configHelp_woob_relabel'));
    await expect(row.locator('.config-warning')).toContainText(tr('configWarning_woob_relabel'));
    await expect(page.locator('#config-woob_relabel')).not.toBeChecked();

    // Switched on and saved
    await row.locator('.toggle-switch').click();
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
    await expect(page.locator('#config-woob_relabel')).toBeChecked();
});

test('an administrator gets the request that starts the synchronization', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSync');
    await expect(page.getByRole('heading', { name: tr('syncCommandTitle') })).toBeVisible();

    // No token in the e2e configuration: one is created (the fixture accepts the confirmation)
    await expect(page.getByRole('alert')).toHaveText(tr('syncTokenMissing'));
    await page.getByRole('button', { name: tr('syncTokenCreate') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('syncTokenRenewed'));

    const curl = page.locator('.copy-field code').first();
    await expect(curl).toContainText(/curl -sN -H "X-Sync-Token: [0-9a-f]{48}" "http:\/\/127\.0\.0\.1:\d+\/api\/bank\/sync"/);

    // Masked again, one account
    await page.getByRole('button', { name: tr('syncTokenHide') }).click();
    await expect(curl).toContainText('X-Sync-Token: ••••');
    await page.locator('#sync-account').selectOption('DEMO0000001@bnp');
    await expect(curl).toContainText('/api/bank/sync?account=DEMO0000001%40bnp');
});

test('the scheduler passes the sync token in a header, the URL token is deprecated', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSync');
    await page.getByRole('button', { name: tr('syncTokenCreate') }).click();
    const curl = page.locator('.copy-field code').first();
    await expect(curl).toContainText(/X-Sync-Token: [0-9a-f]{48}/);
    // Only the header form is shown: never the token in the URL
    await expect(curl).not.toContainText('token=');
    const token = (await curl.textContent()).match(/X-Sync-Token: ([0-9a-f]{48})/)[1];

    // An account that is not followed: the access is checked, then 404 (no synchronization)
    const sync = '/api/bank/sync?account=unknown%40bank';

    // The header: no deprecation
    let response = await page.request.get(sync, { headers: { 'X-Sync-Token': token } });
    expect(response.status()).toBe(404);
    expect(response.headers()['deprecation']).toBeUndefined();

    // The query parameter still works, with the Deprecation and Link headers (RFC 9745)
    response = await page.request.get(`${sync}&token=${token}`);
    expect(response.status()).toBe(404);
    expect(response.headers()['deprecation']).toBe('@1792108800');
    expect(response.headers()['link']).toBe('<https://carbure.org/api/#bank-sync>; rel="deprecation"; type="text/html"');

    // A wrong token: refused, in the header or in the URL
    expect((await page.request.get(sync, { headers: { 'X-Sync-Token': 'wrong' } })).status()).toBe(401);
    expect((await page.request.get(`${sync}&token=wrong`)).status()).toBe(401);
});

test('an administrator configures the SMTP server and sends a test email', async ({ adminPage: page }) => {
    const smtp = await startFakeSmtp();
    try {
        await openAdministration(page, 'tabSettings');
        await page.locator('#config-smtp_host').fill('127.0.0.1');
        await page.locator('#config-smtp_port').fill(String(smtp.port));
        await page.locator('#config-smtp_from').fill('carbure@example.com');
        await page.locator('#config-smtp_password').fill('mail-secret');
        // The test uses the saved configuration
        await expect(page.locator('.smtp-test-note')).toHaveText(tr('smtpTestUnsaved'));
        await page.getByRole('button', { name: tr('save') }).click();
        await expect(page.locator('.toast')).toHaveText(tr('configSaved'));
        await expect(page.locator('.smtp-test-note')).toHaveCount(0);

        // The password is never sent back: only replaced
        await expect(page.locator('#config-smtp_password')).toHaveValue('');
        await expect(page.locator('#config-smtp_password')).toHaveAttribute('placeholder', tr('configPasswordKeep'));
        await expect(page.locator('.tab-panel')).not.toContainText('mail-secret');

        // To the address of the administrator
        await page.getByRole('button', { name: tr('smtpTestButton') }).click();
        await expect(page.getByRole('status').filter({ hasText: tr('smtpTestSent', { to: 'admin@example.com' }) })).toBeVisible();
        expect(smtp.messages).toHaveLength(1);
        expect(smtp.messages[0].from).toBe('carbure@example.com');
        expect(smtp.messages[0].to).toEqual(['admin@example.com']);
        expect(smtp.messages[0].data).toContain('Subject: [Carbure] Email de test');

        // The reply of a server that refuses the email
        await page.getByLabel(tr('smtpTestTitle')).fill('refused@example.com');
        await page.getByRole('button', { name: tr('smtpTestButton') }).click();
        await expect(page.locator('.smtp-test-result[role="alert"]')).toContainText('550 5.1.1 Mailbox unavailable');
        expect(smtp.messages).toHaveLength(1);
    } finally {
        await smtp.close();
    }
});

test('the Settings tab keeps the API key field and sends to the Mobile push tab', async ({ adminPage: page }) => {
    await openAdministration(page, 'tabSettings');
    // No push_url setting: the relay is always the one of Carbure
    await expect(page.locator('#config-push_url')).toHaveCount(0);
    await expect(page.locator('#config-push_api_key')).toBeVisible();
    await expect(page.getByRole('button', { name: tr('pushRegisterButton') })).toHaveCount(0);
    await page.getByRole('button', { name: tr('pushOpenTab') }).click();
    await expect(page.getByRole('heading', { name: tr('pushStatusTitle') })).toBeVisible();
});

test('an administrator registers the instance on the push service and sends a test push', async ({ adminPage: page }) => {
    const relay = await startFakePushRelay();
    try {
        await openAdministration(page, 'tabPush');
        // The relay answers (the fake one here, CARBURE_PUSH_URL of the server); no key yet
        const relayStatus = page.locator('.push-status-item').nth(0);
        const keyStatus = page.locator('.push-status-item').nth(1);
        await expect(relayStatus).toContainText(tr('pushRelayOk', { version: 'e2e' }));
        await expect(relayStatus).toContainText(relay.url);
        await expect(keyStatus).toContainText(tr('pushKeyNone'));
        // No API key: the test push cannot be sent, the registration is offered
        await expect(page.getByText(tr('pushUnavailableAdmin'))).toBeVisible();
        await expect(page.getByRole('button', { name: tr('pushTestButton') })).toBeDisabled();
        await expect(page.locator('.push-key')).toHaveCount(0);

        // The consent first: what goes through the relay, the register button disabled
        // until it is accepted; cancelled, nothing is registered
        const register = page.getByRole('button', { name: tr('pushRegisterButton') });
        await register.click();
        let consent = page.getByRole('dialog', { name: tr('pushConsentTitle') });
        await expect(consent).toBeVisible();
        await expect(consent.locator('.push-consent-url')).toHaveText(relay.url);
        await expect(consent).toContainText(tr('pushConsentForwarded'));
        await expect(consent.getByRole('button', { name: tr('pushConsentSubmit') })).toBeDisabled();
        // The keyboard focus stays in the dialog
        await expect(consent.getByRole('heading', { name: tr('pushConsentTitle') })).toBeFocused();
        for (let i = 0; i < 5; i++) {
            await page.keyboard.press('Tab');
            expect(await consent.evaluate(dialog => dialog.contains(document.activeElement))).toBe(true);
        }
        await page.keyboard.press('Escape');
        await expect(consent).toHaveCount(0);
        await expect(register).toBeFocused();
        expect(relay.keys).toHaveLength(0);

        await register.click();
        consent = page.getByRole('dialog', { name: tr('pushConsentTitle') });
        await consent.getByLabel(tr('pushConsentAccept')).check();
        await consent.getByRole('button', { name: tr('pushConsentSubmit') }).click();
        await expect(consent).toHaveCount(0);
        await expect.poll(() => relay.keys.length).toBe(1);
        await expect(page.getByRole('status').filter({ hasText: tr('pushRegistered', { key: relay.keys[0].slice(4, 20) }) })).toBeVisible();
        // The key accepted by the relay
        await expect(keyStatus).toContainText(tr('pushKeyValid', { id: relay.keys[0].slice(4, 20), devices: 1, devicesLimit: 20, used: 0, usedLimit: 500 }));
        // The key: its public id only until revealed
        const key = page.locator('.push-key-value');
        await expect(key).toHaveText(`${relay.keys[0].slice(0, 21)}${'•'.repeat(12)}`);
        await expect(page.locator('.tab-panel')).not.toContainText(relay.keys[0]);
        await page.getByRole('button', { name: tr('pushKeyShow') }).click();
        await expect(key).toHaveText(relay.keys[0]);
        await expect(page.getByRole('button', { name: tr('pushKeyHide') })).toHaveAttribute('aria-pressed', 'true');
        await page.getByRole('button', { name: tr('pushKeyHide') }).click();
        await expect(page.locator('.tab-panel')).not.toContainText(relay.keys[0]);
        // The consent recorded, for this relay
        await expect(page.locator('.push-consent-given')).toContainText(relay.url);

        // To the iOS device of the administrator (data set)
        await page.getByRole('button', { name: tr('pushTestButton') }).click();
        await expect(page.getByRole('status').filter({ hasText: tr('pushTestSent', { count: 1, devices: 1 }) })).toBeVisible();
        expect(relay.notifications).toHaveLength(1);
        expect(relay.notifications[0].title).toBe('Notification de test');
        expect(relay.notifications[0].key).toBe(relay.keys[0]);
        // The notification of the day counted
        await expect(keyStatus).toContainText(tr('pushKeyValid', { id: relay.keys[0].slice(4, 20), devices: 1, devicesLimit: 20, used: 1, usedLimit: 500 }));

        // Renewed after confirmation (accepted by the fixtures), the consent already given
        // for this relay: the new key is used
        await page.getByRole('button', { name: tr('pushRenewButton') }).click();
        await expect(page.getByRole('dialog', { name: tr('pushConsentTitle') })).toHaveCount(0);
        await expect.poll(() => relay.keys.length).toBe(2);
        await expect(page.getByRole('status').filter({ hasText: tr('pushRenewed', { key: relay.keys[1].slice(4, 20) }) })).toBeVisible();
        await expect(page.locator('.push-key-value')).toContainText(relay.keys[1].slice(0, 21));
        await page.getByRole('button', { name: tr('pushTestButton') }).click();
        await expect.poll(() => relay.notifications.length).toBe(2);
        expect(relay.notifications[1].key).toBe(relay.keys[1]);
    } finally {
        await relay.close();
    }
});

test('the push service unreachable: the status and the registration tell why', async ({ adminPage: page }) => {
    // No fake push service started: nothing listens where the server sends
    await openAdministration(page, 'tabPush');
    await expect(page.locator('.push-status-item').nth(0)).toContainText(tr('pushRelayUnreachable'));
    await page.getByRole('button', { name: tr('pushRegisterButton') }).click();
    const consent = page.getByRole('dialog', { name: tr('pushConsentTitle') });
    await consent.getByLabel(tr('pushConsentAccept')).check();
    await consent.getByRole('button', { name: tr('pushConsentSubmit') }).click();
    await expect(page.locator('.push-register .push-result[role="alert"]')).toContainText('Push service unreachable');
});
