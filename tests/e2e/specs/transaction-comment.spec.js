// Free-text comment of a transaction, written from its detail
const { test, expect, tr, currentMonth } = require('../fixtures');

/** Row of a transaction, by its label */
const row = (page, label) => page.locator('.transaction-item').filter({ hasText: label });

/**
 * A transaction of the current month, by its label, as the API returns it
 * @param {import('@playwright/test').APIRequestContext} api - Logged-in API client
 * @param {string} label - Its label
 * @returns {Promise<Object>}
 */
async function apiTransaction(api, label) {
    const { month, year } = currentMonth();
    return (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json()).find(t => t.label === label);
}

/**
 * Opens the detail of a transaction from its row
 * @param {import('@playwright/test').Page} page - The portal
 * @param {string} label - Its label
 * @returns {Promise<import('@playwright/test').Locator>} The dialog
 */
async function openDetail(page, label) {
    await row(page, label).getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(detail).toBeVisible();
    return detail;
}

test('adds a comment to a transaction, shown in the list, then removes it', async ({ adminPage: page, api }) => {
    const label = 'CB BOULANGERIE PAUL';
    const comment = 'Gâteau d’anniversaire de Léa';
    const before = await apiTransaction(api, label);
    expect(before.comment).toBeNull();
    await expect(row(page, label).locator('.transaction-comment')).toHaveCount(0);

    // Written in the detail, trimmed
    let detail = await openDetail(page, label);
    const field = detail.getByLabel(tr('transactionComment'));
    await expect(field).toHaveValue('');
    await expect(field).toHaveAttribute('maxlength', '500');
    await field.fill(`  ${comment}  `);
    await detail.getByRole('button', { name: tr('save') }).click();
    await expect(detail).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));

    // Shown under the row, whole text in the title; the rest of the transaction unchanged
    const shown = row(page, label).locator('.transaction-comment');
    await expect(shown).toContainText(comment);
    await expect(shown).toHaveAttribute('title', comment);
    const saved = await apiTransaction(api, label);
    expect(saved.comment).toBe(comment);
    expect([saved.id, saved.uuid, saved.date, saved.amount, saved.category, saved.pointed])
        .toEqual([before.id, before.uuid, before.date, before.amount, before.category, before.pointed]);

    // Still there after a reload, and found by the search
    await page.reload();
    await expect(row(page, label).locator('.transaction-comment')).toContainText(comment);
    const search = await (await api.get(`/api/transaction/search?query=${encodeURIComponent('anniversaire')}`)).json();
    expect(search.map(t => t.id)).toEqual([before.id]);

    // Found again in the detail, then removed
    detail = await openDetail(page, label);
    await expect(detail.getByLabel(tr('transactionComment'))).toHaveValue(comment);
    await detail.getByLabel(tr('transactionComment')).fill('');
    await detail.getByRole('button', { name: tr('save') }).click();
    await expect(detail).toHaveCount(0);
    await expect(row(page, label).locator('.transaction-comment')).toHaveCount(0);
    expect((await apiTransaction(api, label)).comment).toBeNull();
});

test('a comment too long is refused with its stable error code', async ({ api }) => {
    const { id } = await apiTransaction(api, 'CB BOULANGERIE PAUL');

    const response = await api.put('/api/transaction/comment', { data: { id, comment: 'x'.repeat(501) } });
    expect(response.status()).toBe(400);
    expect(await response.json()).toMatchObject({ error_code: 'transaction_comment_length', params: { max: 500 } });
    expect((await apiTransaction(api, 'CB BOULANGERIE PAUL')).comment).toBeNull();
});

test('the comment is the last field of the detail of a transaction', async ({ adminPage: page }) => {
    const detail = await openDetail(page, 'CB BOULANGERIE PAUL');
    await expect(detail.locator('.modal-body > .form-group').last().locator('textarea')).toHaveAttribute('id', 'detail-comment');
});

test('enters a transaction by hand with its comment, then changes and removes it', async ({ adminPage: page, api }) => {
    const label = 'MARCHÉ DU DIMANCHE';
    await page.locator('.transactions-toolbar').getByRole('button', { name: tr('manualTransactionTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('manualTransactionTitle') });
    // The comment: the last field of the form
    await expect(modal.locator('.modal-body > .form-group').last().getByLabel(tr('transactionComment'))).toBeVisible();
    await expect(modal.getByLabel(tr('transactionComment'))).toHaveAccessibleDescription(tr('transactionCommentHelp'));
    await modal.getByLabel(tr('manualAmount', { currency: '€' })).fill('12.50');
    await modal.getByLabel(tr('manualLabel')).fill('Marché du dimanche');
    await modal.getByLabel(tr('transactionComment')).fill('  Légumes pour la semaine  ');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('manualTransactionAdded'));

    // Saved with the transaction, shown in the list
    await expect(row(page, label).locator('.transaction-comment')).toContainText('Légumes pour la semaine');
    expect((await apiTransaction(api, label)).comment).toBe('Légumes pour la semaine');

    // Found again in the form of the change (the comment last there too), changed
    await expect(page.locator('.toast')).toHaveCount(0);
    let detail = await openDetail(page, label);
    await detail.getByRole('button', { name: tr('manualTransactionEdit') }).click();
    let edit = page.getByRole('dialog', { name: tr('manualTransactionEditTitle') });
    await expect(edit.locator('.modal-body > .form-group').last().getByLabel(tr('transactionComment'))).toHaveValue('Légumes pour la semaine');
    await edit.getByLabel(tr('transactionComment')).fill('Fruits et légumes');
    await edit.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));
    await expect(row(page, label).locator('.transaction-comment')).toContainText('Fruits et légumes');
    expect((await apiTransaction(api, label)).comment).toBe('Fruits et légumes');

    // Removed from the same form
    await expect(page.locator('.toast')).toHaveCount(0);
    detail = await openDetail(page, label);
    await detail.getByRole('button', { name: tr('manualTransactionEdit') }).click();
    edit = page.getByRole('dialog', { name: tr('manualTransactionEditTitle') });
    await edit.getByLabel(tr('transactionComment')).fill('');
    await edit.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));
    await expect(row(page, label).locator('.transaction-comment')).toHaveCount(0);
    expect((await apiTransaction(api, label)).comment).toBeNull();
});
