// Two-factor authentication: enforced by the administrator (Settings), registered at
// login (QR code) or from the profile, trusted browser for 6 months, reset by an
// administrator
const { test, expect, tr, openTab, USERS } = require('../fixtures');
const { totp } = require('../totp');

/**
 * Enforces the second factor for every user (setting mfa_required)
 * @param {import('@playwright/test').APIRequestContext} api - As the administrator
 */
async function enforce(api) {
    const response = await api.put('/api/system/config', { data: { values: { mfa_required: true } } });
    expect(response.ok(), await response.text()).toBeTruthy();
}

/**
 * Registers an authenticator app for Marie through the API (before it is enforced)
 * @param {import('@playwright/test').APIRequestContext} request
 * @returns {Promise<string>} The secret
 */
async function enrollMarie(request) {
    const token = (await (await request.post('/api/user/login', { data: USERS.marie })).json()).token;
    const headers = { Authorization: `Bearer ${token}` };
    const { secret } = await (await request.post('/api/user/mfa/setup', { headers })).json();
    const response = await request.post('/api/user/mfa/enable', { headers, data: { code: totp(secret) } });
    expect(response.ok(), await response.text()).toBeTruthy();
    return secret;
}

/**
 * Types the username and the password on the login screen
 * @param {import('@playwright/test').Page} page
 * @param {string} user - Key of USERS
 */
async function typePassword(page, user) {
    await page.goto('/portal/');
    await page.getByLabel(tr('username')).fill(USERS[user].username);
    await page.getByLabel(tr('password')).fill(USERS[user].password);
    await page.getByRole('button', { name: tr('loginButton') }).click();
}

test('the administrator enforces it in the settings', async ({ adminPage: page, api }) => {
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabSettings'), exact: true }).click();

    const toggle = page.locator('#config-mfa_required');
    await expect(toggle).not.toBeChecked();
    await expect(page.locator('.config-row', { has: toggle })).toContainText(tr('configHelp_mfa_required'));
    // The switch is drawn over its input: its label checks it
    await page.locator('label[for="config-mfa_required"]').click();
    await expect(toggle).toBeChecked();
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('configSaved'));

    // Asked at the next login
    const login = await (await api.post('/api/user/login', { data: { ...USERS.marie, mfa: true } })).json();
    expect(login.mfa).toBe('enroll');
    expect(login.token).toBeUndefined();
});

test('a user registers his app at login, then trusts the browser for 6 months', async ({ page, api }) => {
    await enforce(api);
    await typePassword(page, 'marie');

    // QR code drawn in the page, and the secret in text
    await expect(page.getByRole('heading', { name: tr('mfaEnrollTitle') })).toBeVisible();
    await expect(page.getByRole('img', { name: tr('mfaQrLabel') })).toBeVisible();
    // Large enough to be scanned (its modules drawn over the whole square, not in a
    // corner), never wider than a phone screen
    await expect(page.locator('.mfa-qr')).toHaveAttribute('viewBox', /^0 0 \d+ \d+$/);
    const box = await page.locator('.mfa-qr').boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(240);
    expect((await page.locator('.mfa-qr path').boundingBox()).width).toBeGreaterThan(box.width * 0.6);
    await page.setViewportSize({ width: 320, height: 640 });
    const small = await page.locator('.mfa-qr').boundingBox();
    expect(small.x).toBeGreaterThanOrEqual(0);
    expect(small.x + small.width).toBeLessThanOrEqual(320);
    await page.setViewportSize({ width: 1280, height: 720 });
    const secret = (await page.locator('.mfa-secret').textContent()).trim();
    expect(secret.replace(/\s/g, '')).toMatch(/^[A-Z2-7]{32}$/);
    await expect(page.getByLabel(tr('mfaCode'))).toBeFocused();

    await page.getByLabel(tr('mfaCode')).fill(totp(secret));
    await page.getByLabel(tr('mfaTrustBrowser')).check();
    await page.getByRole('button', { name: tr('mfaVerifyButton') }).click();

    // The recovery codes, shown once before the session
    await expect(page.getByRole('heading', { name: tr('mfaRecoveryTitle') })).toBeVisible();
    await expect(page.locator('.mfa-recovery-codes li')).toHaveCount(10);
    await page.getByRole('button', { name: tr('mfaRecoveryContinue') }).click();
    await expect(page.locator('.user-menu-name')).toHaveText('marie');

    // The cookie of the trusted browser: out of reach of the scripts, sent to the login only
    const cookie = (await page.context().cookies()).find(c => c.name.startsWith('carbure_mfa_trust_'));
    expect(cookie).toMatchObject({ httpOnly: true, sameSite: 'Strict', path: '/api/user/login' });
    expect(await page.evaluate(() => document.cookie)).toBe('');

    // Next login in this browser: no code asked
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('logout') }).click();
    await typePassword(page, 'marie');
    await expect(page.locator('.user-menu-name')).toHaveText('marie');

    // The devices of the profile list the trusted browser; revoked, the code is asked again
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await page.getByRole('tab', { name: tr('myDevices'), exact: true }).click();
    const browsers = page.getByRole('list', { name: tr('mfaBrowsersTitle') }).locator('.device-item');
    await expect(browsers).toHaveCount(1);
    await expect(browsers.first()).toContainText('Chrome');
    await browsers.first().getByRole('button', { name: new RegExp(tr('mfaRevokeBrowser')) }).click();
    await expect(page.locator('.toast')).toHaveText(tr('mfaBrowserRevoked'));
    await expect(page.locator('.mfa-no-browsers')).toHaveText(tr('mfaNoBrowsers'));

    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('logout') }).click();
    await typePassword(page, 'marie');
    await expect(page.getByRole('heading', { name: tr('mfaVerifyTitle') })).toBeVisible();
});

test('the code is asked at each login, a wrong one is refused', async ({ page, api, request }) => {
    const secret = await enrollMarie(request);
    await enforce(api);
    await typePassword(page, 'marie');

    await expect(page.getByRole('heading', { name: tr('mfaVerifyTitle') })).toBeVisible();
    await expect(page.getByRole('img', { name: tr('mfaQrLabel') })).toHaveCount(0);
    await page.getByLabel(tr('mfaCode')).fill('000000');
    await page.getByRole('button', { name: tr('mfaVerifyButton') }).click();
    await expect(page.getByRole('alert')).toContainText(tr('mfaInvalidCode'));
    await expect(page.locator('.user-menu-name')).toHaveCount(0);

    // The code of the next step (the one of this step registered the app)
    await page.getByLabel(tr('mfaCode')).fill(totp(secret, 30));
    await page.getByRole('button', { name: tr('mfaVerifyButton') }).click();
    await expect(page.locator('.user-menu-name')).toHaveText('marie');
    // Not trusted: no cookie
    expect((await page.context().cookies()).filter(c => c.name.startsWith('carbure_mfa_trust_'))).toEqual([]);
});

test('the code step stays open when nginx limits the logins of this address (429)', async ({ page, api, request }) => {
    const secret = await enrollMarie(request);
    await enforce(api);
    await typePassword(page, 'marie');
    await expect(page.getByRole('heading', { name: tr('mfaVerifyTitle') })).toBeVisible();

    // The rate limit of the Docker image (nginx): not in the server of the tests, simulated
    await page.route('**/api/user/login/mfa', route => route.fulfill({
        status: 429,
        contentType: 'application/json',
        body: JSON.stringify({ error: 'Too many login attempts, try again in a minute', code: 429 })
    }));
    await page.getByLabel(tr('mfaCode')).fill(totp(secret, 30));
    await page.getByRole('button', { name: tr('mfaVerifyButton') }).click();
    await expect(page.getByRole('alert')).toContainText(tr('tooManyLogins'));
    // Still the code step (the challenge of the password is kept), to try again later
    await expect(page.getByRole('heading', { name: tr('mfaVerifyTitle') })).toBeVisible();

    await page.unroute('**/api/user/login/mfa');
    await page.getByLabel(tr('mfaCode')).fill(totp(secret, 30));
    await page.getByRole('button', { name: tr('mfaVerifyButton') }).click();
    await expect(page.locator('.user-menu-name')).toHaveText('marie');
});

test('back to the password from the second step', async ({ page, request }) => {
    await enrollMarie(request);
    await typePassword(page, 'marie');
    await expect(page.getByRole('heading', { name: tr('mfaVerifyTitle') })).toBeVisible();

    await page.getByRole('button', { name: tr('mfaBack') }).click();
    await expect(page.getByRole('button', { name: tr('loginButton') })).toBeVisible();
    await expect(page.getByLabel(tr('password'))).toHaveValue('');
});

test('a user sets up, renews and disables his app from his profile', async ({ userPage: page }) => {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await page.getByRole('tab', { name: tr('mfaTitle'), exact: true }).click();
    const block = page.locator('.profile-mfa');
    await expect(block.getByRole('heading', { name: tr('mfaTitle') })).toBeVisible();
    await expect(block).toContainText(tr('mfaOff'));

    await block.getByRole('button', { name: tr('mfaSetupButton') }).click();
    await expect(block.getByRole('img', { name: tr('mfaQrLabel') })).toBeVisible();
    // Large enough to be scanned
    expect((await block.locator('.mfa-qr').boundingBox()).width).toBeGreaterThanOrEqual(240);
    const secret = (await block.locator('.mfa-secret').textContent()).trim();
    await block.getByLabel(tr('mfaCode')).fill(totp(secret));
    await block.getByRole('button', { name: tr('mfaEnable') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('mfaEnabledToast'));
    await expect(block).toContainText(tr('mfaOn'));
    await expect(block.locator('.mfa-recovery-codes li')).toHaveCount(10);
    const recovery = (await block.locator('.mfa-recovery-codes li').first().textContent()).trim();
    await expect(block).toContainText(tr('mfaRecoveryLeft', { count: 10 }));

    // New recovery codes with the code of the next step
    await block.getByLabel(tr('mfaCodeOrRecovery')).fill(totp(secret, 30));
    await block.getByRole('button', { name: tr('mfaRenewRecovery') }).click();
    await expect(block.locator('.mfa-recovery-codes li').first()).not.toHaveText(recovery);

    // Disabled with a recovery code, after a confirmation
    const code = (await block.locator('.mfa-recovery-codes li').nth(1).textContent()).trim();
    await block.getByLabel(tr('mfaCodeOrRecovery')).fill(code);
    await block.getByRole('button', { name: tr('mfaDisable') }).click();
    const disable = page.getByRole('dialog', { name: tr('mfaDisableTitle') });
    await expect(disable.locator('#confirm-dialog-message')).toHaveText(tr('mfaDisableConfirm'));
    await expect(disable.locator('#confirm-dialog-warning')).toHaveText(tr('mfaDisableWarning'));
    await disable.getByRole('button', { name: tr('mfaDisable') }).click();
    await expect(block).toContainText(tr('mfaOff'));
    await expect(block.getByRole('button', { name: tr('mfaSetupButton') })).toBeVisible();
});

test('an administrator resets the second factor of a user who lost his phone', async ({ adminPage: page, request, api }) => {
    await enrollMarie(request);
    await enforce(api);

    await openTab(page, tr('tabUsers'));
    await page.getByRole('button', { name: `${tr('mfaReset')} marie` }).click();
    // The question naming the user, its consequences in a warning, cancel focused
    const dialog = page.getByRole('dialog', { name: tr('mfaReset') });
    await expect(dialog.locator('#confirm-dialog-message')).toHaveText(tr('mfaResetConfirm', { username: 'marie' }));
    await expect(dialog.locator('#confirm-dialog-warning')).toHaveText(tr('mfaResetWarning'));
    await expect(dialog).toHaveAccessibleDescription(`${tr('mfaResetConfirm', { username: 'marie' })} ${tr('mfaResetWarning')}`);
    await expect(dialog.getByRole('button', { name: tr('cancel') })).toBeFocused();
    // Cancel and the action side by side on one line
    const cancelBox = await dialog.getByRole('button', { name: tr('cancel') }).boundingBox();
    const resetBox = await dialog.getByRole('button', { name: tr('mfaResetButton') }).boundingBox();
    expect(Math.abs(cancelBox.y - resetBox.y)).toBeLessThan(2);
    await dialog.getByRole('button', { name: tr('mfaResetButton') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('mfaResetDone', { username: 'marie' }));

    // She registers a new app at her next login
    const login = await (await request.post('/api/user/login', { data: { ...USERS.marie, mfa: true } })).json();
    expect(login.mfa).toBe('enroll');
});
