// Categories tab (administrators): tree by type, add, modify, delete
const { test, expect, tr, openTab } = require('../fixtures');

/** Row of a category, by its name */
const category = (page, name) => page.locator('.category-row').filter({ has: page.locator('.category-row-name', { hasText: new RegExp(`^${name}$`) }) });

/**
 * Categories returned by the API, by name
 * @param {import('@playwright/test').APIRequestContext} api
 */
async function apiCategories(api) {
    return Object.fromEntries((await (await api.get('/api/category')).json()).map(c => [c.name, c]));
}

test('lists the categories by type, with their sub-categories', async ({ adminPage: page }) => {
    await openTab(page, tr('tabCategories'));

    await expect(page.getByRole('heading', { name: tr('categoriesTitle') })).toBeVisible();
    const expenses = page.locator('section').filter({ has: page.getByRole('heading', { name: tr('categoryTypes_DEBIT') }) });
    await expect(expenses.locator('li.category-row.child')).toHaveCount(2);
    await expect(category(page, 'Supermarché')).toBeVisible();
    // The default category cannot be modified
    await expect(category(page, 'Non catégorisé')).toContainText(tr('categoryDefault'));
    await expect(category(page, 'Non catégorisé').getByRole('button')).toHaveCount(0);
});

test('adds a sub-category with its color and icon', async ({ adminPage: page, api }) => {
    await openTab(page, tr('tabCategories'));
    await page.getByRole('button', { name: tr('addCategory') }).click();

    const modal = page.getByRole('dialog', { name: tr('addCategory') });
    await modal.getByLabel(new RegExp(tr('categoryName'))).fill('Électricité');
    await modal.getByLabel(tr('categoryParent')).selectOption({ label: 'Logement' });
    await modal.getByRole('radio', { name: 'orange', exact: true }).click();
    const icon = modal.locator('.icon-picker [role="radio"]').nth(3);
    const iconName = await icon.getAttribute('aria-label');
    await icon.click();
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('categoryAdded'));
    await expect(category(page, 'Électricité')).toBeVisible();
    expect(await apiCategories(api)).toMatchObject({ 'Électricité': { parent_category: 4, type: 'DEBIT', color: 'orange', icon: iconName } });
});

test('adds a sub-category from the row of its parent, with the type of the parent', async ({ adminPage: page, api }) => {
    await openTab(page, tr('tabCategories'));
    // Only the top-level categories offer it: not their sub-categories
    await expect(category(page, 'Livret A').getByRole('button', { name: tr('addSubcategory', { name: 'Livret A' }) })).toHaveCount(0);
    await category(page, 'Épargne').getByRole('button', { name: tr('addSubcategory', { name: 'Épargne' }) }).click();

    const modal = page.getByRole('dialog', { name: tr('addCategory') });
    await expect(modal.getByLabel(tr('categoryParent'))).toHaveValue('6');
    await modal.getByLabel(new RegExp(tr('categoryName'))).fill('Livret jeune');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('categoryAdded'));
    await expect(category(page, 'Livret jeune')).toBeVisible();
    expect(await apiCategories(api)).toMatchObject({ 'Livret jeune': { parent_category: 6, type: 'HORS-BUDGET' } });
});

test('modifies a category',async ({ adminPage: page, api }) => {
    await openTab(page, tr('tabCategories'));
    await category(page, 'Restaurant').getByRole('button', { name: tr('editCategory') }).click();

    const modal = page.getByRole('dialog', { name: tr('editCategory') });
    await expect(modal.getByLabel(new RegExp(tr('categoryName')))).toHaveValue('Restaurant');
    await modal.getByLabel(new RegExp(tr('categoryName'))).fill('Restaurants');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('categoryUpdated'));
    await expect(category(page, 'Restaurants')).toBeVisible();
    expect((await apiCategories(api)).Restaurants.parent_category).toBe(1);
});

test('deletes a category, its transactions become uncategorized', async ({ adminPage: page, api }) => {
    await openTab(page, tr('tabCategories'));
    await category(page, 'Livret A').getByRole('button', { name: tr('deleteCategory') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('categoryDeleted'));
    await expect(category(page, 'Livret A')).toHaveCount(0);
    const transactions = await (await api.get('/api/transaction/search?query=LIVRET')).json();
    expect(Number(transactions[0].category)).toBe(0);
});

test('refuses to delete a category that has sub-categories', async ({ adminPage: page }) => {
    await openTab(page, tr('tabCategories'));
    await category(page, 'Alimentation').getByRole('button', { name: tr('deleteCategory') }).click();

    await expect(page.locator('.toast')).toBeVisible();
    await expect(category(page, 'Alimentation')).toBeVisible();
});
