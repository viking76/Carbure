// Date a transaction is counted on, changed from its detail (#427)
const { test, expect, tr, currentMonth } = require('../fixtures');

/** Row of a transaction, by its label */
const row = (page, label) => page.locator('.transaction-item').filter({ hasText: label });

/**
 * Last day of the previous month (YYYY-MM-DD)
 * @returns {string}
 */
function endOfPreviousMonth() {
    const { month, year } = currentMonth(1);
    const day = new Date(year, month, 0).getDate();
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

test('moves a synchronized transaction to the previous month', async ({ adminPage: page, api }) => {
    const label = 'CB BOULANGERIE PAUL';
    const { month, year } = currentMonth();
    const synced = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json()).find(t => t.label === label);
    await expect(page.locator('.transaction-item')).toHaveCount(6);

    await row(page, label).getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    const countedOn = detail.getByLabel(tr('transactionCountedOn'));
    await expect(countedOn).toHaveValue(synced.date);
    await countedOn.fill(endOfPreviousMonth());
    await detail.getByRole('button', { name: tr('save') }).click();
    await expect(detail).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));

    // Gone from the current month...
    await expect(page.locator('.transaction-item')).toHaveCount(5);
    await expect(row(page, label)).toHaveCount(0);

    // ...counted in the previous one, with its real date and its UUID: a synchronization merges it again
    const previous = currentMonth(1);
    await page.locator('#year').selectOption(String(previous.year));
    await page.locator('#month').selectOption(String(previous.month));
    await expect(page.locator('.transaction-item')).toHaveCount(4);
    await expect(row(page, label)).toBeVisible();
    const moved = (await (await api.get(`/api/transaction?month=${previous.month}&year=${previous.year}`)).json())
        .find(t => t.label === label);
    expect([moved.id, moved.date, moved.rdate, moved.uuid]).toEqual([synced.id, endOfPreviousMonth(), synced.rdate, synced.uuid]);
});
