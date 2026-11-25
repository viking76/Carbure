// Display preferences: the theme of the profile (system / light / dark), and the high
// contrast settings of the system (prefers-contrast, forced colors). The contrasts of
// every screen in the dark theme are measured by accessibility.spec.js (chromium-dark).
const { test, expect, tr, openTab, USERS } = require('../fixtures');
const { contrastProblems } = require('../a11y');

/**
 * Opens the profile page from the user menu
 * @param {import('@playwright/test').Page} page
 */
async function openProfile(page) {
    await page.locator('.user-menu-toggle').click();
    await page.getByRole('menuitem', { name: tr('myProfile') }).click();
    await expect(page.getByRole('heading', { name: tr('myProfile') })).toBeVisible();
}

/** Theme displayed by the page: light or dark */
const theme = page => page.locator('html');

test('follows the light/dark preference of the system by default', async ({ userPage: page }) => {
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    await expect(theme(page)).toHaveAttribute('data-theme-choice', 'system');

    // A change of the system preference applies at once
    await page.emulateMedia({ colorScheme: 'light' });
    await expect(theme(page)).toHaveAttribute('data-theme', 'light');
});

test('a theme chosen in the profile wins over the system and is kept', async ({ userPage: page }) => {
    await page.emulateMedia({ colorScheme: 'light' });
    await openProfile(page);
    await expect(page.getByLabel(tr('theme'), { exact: true })).toHaveValue('system');

    await page.getByLabel(tr('theme'), { exact: true }).selectOption('dark');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    // Dark surfaces, light text
    const colors = await page.locator('body').evaluate(el => getComputedStyle(el).color);
    expect(colors).toBe('rgb(226, 232, 240)');

    // Saved on the server: applied again on another browser (nothing cached)
    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.reload();
    await expect(page.locator('.user-menu-name')).toHaveText('marie');
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    await openProfile(page);
    await expect(page.getByLabel(tr('theme'), { exact: true })).toHaveValue('dark');

    // "Light" wins over a dark system
    await page.emulateMedia({ colorScheme: 'dark' });
    await page.getByLabel(tr('theme'), { exact: true }).selectOption('light');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));
    await expect(theme(page)).toHaveAttribute('data-theme', 'light');
});

test('the header toggle switches the theme in one click and saves it in the profile', async ({ userPage: page }) => {
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    await expect(theme(page)).toHaveAttribute('data-theme', 'light');

    // On the right of the header, just before the user menu; the icon names the theme to come
    const toggle = page.locator('.header-user > button').first();
    await expect(toggle).toHaveAccessibleName(tr('switchToDarkTheme'));
    await expect(toggle).toHaveAttribute('title', tr('switchToDarkTheme'));
    await expect(toggle.locator('.material-icons')).toHaveText('dark_mode');
    await expect(toggle.locator('.material-icons')).toHaveAttribute('aria-hidden', 'true');
    // A 44x44 target, its icon drawn by the icon font (a ligature, not the name as text)
    const box = await toggle.boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(44);
    expect(box.height).toBeGreaterThanOrEqual(44);
    expect((await toggle.locator('.material-icons').boundingBox()).width).toBeLessThan(30);

    // With the keyboard: switched to dark at once, the focus kept on the button
    const saved = page.waitForResponse(response => response.url().includes('/api/user?id=') && response.request().method() === 'PUT');
    await toggle.focus();
    await page.keyboard.press('Enter');
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    await expect(theme(page)).toHaveAttribute('data-theme-choice', 'dark');
    expect((await saved).ok()).toBe(true);
    await expect(toggle).toBeFocused();
    await expect(toggle).toHaveAccessibleName(tr('switchToLightTheme'));
    await expect(toggle.locator('.material-icons')).toHaveText('light_mode');

    // Saved on the server: applied again on another browser (nothing cached), shown by the profile
    await page.evaluate(() => localStorage.removeItem('theme'));
    await page.reload();
    await expect(page.locator('.user-menu-name')).toHaveText('marie');
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    await openProfile(page);
    await expect(page.getByLabel(tr('theme'), { exact: true })).toHaveValue('dark');

    // Back to light: the profile select follows; it still offers "Same as the system"
    await page.getByRole('button', { name: tr('switchToLightTheme') }).click();
    await expect(theme(page)).toHaveAttribute('data-theme', 'light');
    await expect(page.getByLabel(tr('theme'), { exact: true })).toHaveValue('light');
    await page.getByLabel(tr('theme'), { exact: true }).selectOption('system');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('profileUpdated'));
    await expect(theme(page)).toHaveAttribute('data-theme-choice', 'system');

    // Following the system again: the toggle offers the other theme than the system one
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(page.getByRole('button', { name: tr('switchToLightTheme') })).toBeVisible();
});

test('the header toggle is readable and its focus visible, in both themes', async ({ userPage: page }) => {
    await page.emulateMedia({ colorScheme: 'light', reducedMotion: 'reduce' });
    for (const [name, next] of [['light', 'switchToDarkTheme'], ['dark', 'switchToLightTheme']]) {
        const toggle = page.getByRole('button', { name: tr(next) });
        // At rest and on hover (every button of the header: see a11y.js)
        expect(await contrastProblems(page, `header ${name}`)).toEqual([]);

        // On keyboard focus: a ring of 2px at least, and the icon still readable
        await toggle.focus();
        await page.keyboard.press('Shift+Tab');
        await page.keyboard.press('Tab');
        await expect(toggle).toBeFocused();
        const outline = await toggle.evaluate(el => {
            const style = getComputedStyle(el);
            return { style: style.outlineStyle, width: parseFloat(style.outlineWidth) };
        });
        expect(outline.style).not.toBe('none');
        expect(outline.width).toBeGreaterThanOrEqual(2);
        expect(await contrastProblems(page, `header ${name} (focus)`)).toEqual([]);

        await page.keyboard.press('Enter');
        await expect(theme(page)).toHaveAttribute('data-theme', name === 'light' ? 'dark' : 'light');
    }
});

test('on a phone, the theme toggle is in the menu of the bottom navigation, without horizontal scroll', async ({ userPage: page }) => {
    await page.setViewportSize({ width: 375, height: 740 });
    // The header keeps the period only (#353): the user menu and the toggle are in "More"
    await expect(page.locator('.user-menu-toggle')).toBeHidden();
    await page.locator('.bottom-nav').getByRole('button', { name: tr('navMore') }).click();
    const menu = page.getByRole('dialog', { name: USERS.marie.username });
    await menu.getByRole('button', { name: tr('switchToDarkTheme') }).click();
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    await expect(menu.getByRole('button', { name: tr('switchToLightTheme') })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('the "increase contrast" setting of the system darkens the secondary texts', async ({ userPage: page }) => {
    await page.emulateMedia({ contrast: 'more', reducedMotion: 'reduce' });
    const secondary = await page.locator('html').evaluate(el => getComputedStyle(el).getPropertyValue('--text-secondary').trim());
    expect(secondary).toBe('#334155');
    expect(await contrastProblems(page, tr('tabTransactions'))).toEqual([]);

    await openTab(page, tr('tabBudget'));
    await page.waitForLoadState('networkidle');
    expect(await contrastProblems(page, tr('tabBudget'))).toEqual([]);

    // The same in the dark theme
    await page.emulateMedia({ colorScheme: 'dark' });
    await expect(theme(page)).toHaveAttribute('data-theme', 'dark');
    expect(await contrastProblems(page, `${tr('tabBudget')} dark`)).toEqual([]);
    await openTab(page, tr('tabTransactions'));
    await page.waitForLoadState('networkidle');
    expect(await contrastProblems(page, `${tr('tabTransactions')} dark`)).toEqual([]);
});

test('in forced colors, the controls keep visible borders and focus', async ({ adminPage: page }) => {
    await page.emulateMedia({ forcedColors: 'active' });
    await openTab(page, tr('tabAccounts'));

    // Buttons with a colored background only (no border otherwise), tabs, fields
    const bordered = [
        page.locator('.tab-button.active'),
        page.locator('.tab-button:not(.active)').first(),
        page.getByRole('button', { name: tr('woobUpdateButton') }),
        page.getByRole('button', { name: tr('syncAllAccounts') }),
        page.locator('.header-user > button').first(),
    ];
    for (const control of bordered) {
        const border = await control.evaluate(el => {
            const style = getComputedStyle(el);
            return { style: style.borderTopStyle, width: parseFloat(style.borderTopWidth) };
        });
        expect(border.style, await control.evaluate(el => el.outerHTML.slice(0, 80))).not.toBe('none');
        expect(border.width).toBeGreaterThanOrEqual(1);
    }

    // The keyboard focus is drawn
    await page.keyboard.press('Tab');
    const outline = await page.evaluate(() => {
        const style = getComputedStyle(document.activeElement);
        return { style: style.outlineStyle, width: parseFloat(style.outlineWidth) };
    });
    expect(outline.style).not.toBe('none');
    expect(outline.width).toBeGreaterThanOrEqual(2);
});
