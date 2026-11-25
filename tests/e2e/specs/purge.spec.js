// Administration > Purge: deletes every row of the chosen datasets (their dependents
// with them), after a confirmation, from the local network only
const { test, expect, tr } = require('../fixtures');

/**
 * Opens Administration > Purge
 * @param {import('@playwright/test').Page} page
 */
async function openPurge(page) {
    await page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true }).click();
    await page.getByRole('tab', { name: tr('tabPurge'), exact: true }).click();
    await expect(page.getByRole('heading', { name: tr('purgeTitle') })).toBeVisible();
    await expect(page.locator('input[data-dataset="transactions"]')).toBeVisible();
}

/**
 * Number of rows of each dataset that a purge deletes, asked to the API
 * @param {import('@playwright/test').APIRequestContext} api
 * @returns {Promise<Object>} {name: count}
 */
async function counts(api) {
    const response = await api.get('/api/purge');
    expect(response.ok()).toBeTruthy();
    return Object.fromEntries((await response.json()).datasets.map(dataset => [dataset.name, dataset.count]));
}

test('an administrator purges the transactions, their tags purged with them', async ({ adminPage: page, api }) => {
    const before = await counts(api);
    expect(before.transactions).toBeGreaterThan(0);
    await openPurge(page);
    const purge = page.locator('.purge-button');
    await expect(purge).toBeDisabled();

    await page.locator('input[data-dataset="transactions"]').check();
    // The tags of the transactions refer to them: ticked, and cannot be unticked
    const tags = page.locator('input[data-dataset="transaction_tags"]');
    await expect(tags).toBeChecked();
    await expect(tags).toBeDisabled();
    await expect(page.locator('.backup-dataset').filter({ has: tags })).toContainText(tr('purgeWithParent'));
    await expect(page.locator('input[data-dataset="categories"]')).not.toBeChecked();

    await purge.click();
    const dialog = page.getByRole('dialog', { name: tr('purgeConfirmTitle') });
    await expect(dialog).toBeVisible();
    const rows = before.transactions + before.transaction_tags;
    await expect(dialog).toContainText(tr('purgeConfirmWarning', { count: rows }));
    // Cancel: nothing deleted
    await dialog.getByRole('button', { name: tr('cancel') }).click();
    await expect(dialog).toHaveCount(0);
    expect((await counts(api)).transactions).toBe(before.transactions);

    await purge.click();
    await dialog.getByRole('button', { name: tr('purgeButton') }).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('purgeDone', { count: rows }));

    const after = await counts(api);
    expect(after.transactions).toBe(0);
    expect(after.transaction_tags).toBe(0);
    // The other data is kept
    expect(after.categories).toBe(before.categories);
    expect(after.rules).toBe(before.rules);
    await expect(page.locator('.backup-dataset').filter({ has: page.locator('input[data-dataset="transactions"]') }))
        .toContainText(tr('backupRows', { count: 0 }));
});

test('purging the categories also purges the rules, the budgets and the transactions', async ({ adminPage: page, api }) => {
    await openPurge(page);
    await page.locator('input[data-dataset="categories"]').check();
    for (const name of ['rules', 'budgets', 'transactions', 'transaction_tags']) {
        await expect(page.locator(`input[data-dataset="${name}"]`)).toBeChecked();
    }
    await expect(page.locator('input[data-dataset="insights"]')).not.toBeChecked();
    // Unticking the category frees its dependents
    await page.locator('input[data-dataset="categories"]').uncheck();
    await expect(page.locator('input[data-dataset="rules"]')).not.toBeChecked();
    await expect(page.locator('input[data-dataset="rules"]')).toBeEnabled();

    await page.locator('input[data-dataset="categories"]').check();
    await page.locator('.purge-button').click();
    await page.getByRole('dialog', { name: tr('purgeConfirmTitle') }).getByRole('button', { name: tr('purgeButton') }).click();
    await expect(page.locator('.toast')).toBeVisible();

    const after = await counts(api);
    expect([after.categories, after.rules, after.budgets, after.transactions]).toEqual([0, 0, 0, 0]);
    // The default category is kept
    const categories = await (await api.get('/api/category')).json();
    expect(categories.map(category => Number(category.id))).toEqual([0]);
});

test('the purge is refused from Internet, behind a reverse proxy', async ({ adminPage: page, api }) => {
    await page.setExtraHTTPHeaders({ 'X-Forwarded-For': '203.0.113.7' });
    await openPurge(page);
    await expect(page.locator('.purge-not-local')).toContainText(tr('purgeNotLocal'));
    await expect(page.locator('input[data-dataset="transactions"]')).toBeDisabled();
    await expect(page.locator('.purge-button')).toBeDisabled();

    const response = await api.post('/api/purge', { data: { datasets: ['deleted_transactions'] }, headers: { 'X-Forwarded-For': '203.0.113.7' } });
    expect(response.status()).toBe(403);
    expect((await response.json()).error_code).toBe('purge_not_local');
});

test('a user does not see the purge', async ({ userPage: page }) => {
    await expect(page.locator('.tabs').getByRole('button', { name: tr('tabAdministration'), exact: true })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: tr('tabPurge') })).toHaveCount(0);
});
