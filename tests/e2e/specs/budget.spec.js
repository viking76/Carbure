// Budget tab: money flow, budgets by category, sub-categories, editing a budget
const { test, expect, tr, openTab, euros, currentMonth, offerBudgetCopy, escapeRegExp } = require('../fixtures');

/**
 * Checks the amounts of a budget card: spent / budget
 * @param {import('@playwright/test').Locator} item - Card
 * @param {number} spent
 * @param {string} budget - Text of the budget part ("/ 300,00 €")
 */
async function expectAmounts(item, spent, budget) {
    await expect(item.locator('.budget-card-amounts .spent')).toHaveText(euros(spent));
    await expect(item.locator('.budget-card-amounts .of')).toHaveText(budget);
}

/** Card of a category on the budgets view */
const card = (page, name) => page.locator('.budget-card').filter({ has: page.locator('.budget-card-name', { hasText: new RegExp(`^${name}$`) }) });

/**
 * Opens the budgets view of the Budget tab
 * @param {import('@playwright/test').Page} page
 */
async function openBudgets(page) {
    await openTab(page, tr('tabBudget'));
    await page.getByRole('tab', { name: tr('budgetConfigTab') }).click();
    await expect(page.locator('.budget-card').first()).toBeVisible();
}

/**
 * Edits the budget of a category from its card
 * @param {import('@playwright/test').Page} page
 * @param {string} name - Category
 * @returns {Promise<import('@playwright/test').Locator>} The edit modal
 */
async function editBudget(page, name) {
    await card(page, name).getByRole('button', { name: tr('budgetEditTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('budgetEditTitle') });
    await expect(modal).toContainText(name);
    return modal;
}

/**
 * Budget of a category returned by the API for the current month
 * @param {import('@playwright/test').APIRequestContext} api
 * @param {string} name - Category
 * @param {number} parent - Parent category (0: top level)
 */
async function apiBudget(api, name, parent = 0) {
    const { month, year } = currentMonth();
    const items = await (await api.get(`/api/budget?month=${month}&year=${year}&category=${parent}`)).json();
    return items.find(item => item.name === name);
}

test('shows the money flow of the month', async ({ adminPage: page }) => {
    await openTab(page, tr('tabBudget'));

    await expect(page.getByRole('heading', { name: tr('flowTitle') })).toBeVisible();
    await page.getByRole('button', { name: tr('flowShowTable') }).click();
    const table = page.locator('.flow-table');
    await expect(table.getByRole('row').filter({ hasText: 'Salaire' })).toContainText(euros(3200).replace(' €', ''));
    await expect(table.getByRole('row').filter({ hasText: 'Logement' })).toContainText(euros(950).replace(' €', ''));
    await expect(table.getByRole('row').filter({ hasText: 'Alimentation' })).toContainText(euros(127.4).replace(' €', ''));
});

test('a click on a category of the money flow shows its transactions', async ({ adminPage: page }) => {
    await openTab(page, tr('tabBudget'));

    const node = page.locator('.flow-node[role="button"]').filter({ hasText: 'Alimentation' });
    await node.click();
    await expect(node).toHaveAttribute('aria-pressed', 'true');

    // Supermarché and Restaurant are grouped under Alimentation
    const list = page.locator('.flow-transactions');
    await expect(list.getByRole('heading')).toContainText('Alimentation');
    await expect(list.locator('.search-total strong')).toContainText('127,40');

    // A second click hides them
    await node.click();
    await expect(list).toHaveCount(0);
});

test('shows the budgets of the month by category', async ({ adminPage: page }) => {
    await openBudgets(page);

    await expect(page.locator('.budget-section-expenses .budget-card')).toHaveCount(2);
    await expectAmounts(card(page, 'Logement'), 950, `/ ${euros(1000)}`);
    await expect(card(page, 'Logement').locator('.budget-card-percent')).toHaveText('95 %');
    await expect(page.locator('.budget-section-incomes')).toContainText('Salaire');
    await expect(page.locator('.budget-section-others')).toContainText('Épargne');

    // Summary of the budgeted expenses: 950 + 127.40 of 1000 + 400
    const summary = page.locator('.budget-summary');
    await expect(summary).toContainText(euros(1077.4));
    await expect(summary).toContainText(euros(1400));
});

test('the budget of a parent category is the sum of its sub-categories', async ({ adminPage: page }) => {
    await openBudgets(page);

    const alimentation = card(page, 'Alimentation');
    await expect(alimentation.locator('.of')).toHaveText(`/ Σ ${euros(400)}`);
    await expect(alimentation.locator('.of')).toHaveAttribute('title', tr('budgetSumOfChildren'));
    await expect(alimentation.locator('.budget-card-percent')).toHaveText('32 %');

    // Its sub-categories and its transactions
    await alimentation.click();
    await expect(page.locator('.budget-breadcrumb')).toContainText('Alimentation');
    await expectAmounts(card(page, 'Supermarché'), 82.4, `/ ${euros(300)}`);
    await expectAmounts(card(page, 'Restaurant'), 45, `/ ${euros(100)}`);
    const transactions = page.locator('.budget-transactions');
    await expect(transactions).toContainText(tr('categoryTransactions', { category: 'Alimentation' }));
    await expect(transactions.locator('.transaction-item')).toHaveCount(2);

    await page.getByRole('button', { name: tr('allCategories') }).click();
    await expect(card(page, 'Logement')).toBeVisible();
});

test('edits the budget of a sub-category', async ({ adminPage: page, api }) => {
    await openBudgets(page);
    await card(page, 'Alimentation').click();

    const modal = await editBudget(page, 'Supermarché');
    // A category without sub-categories has no "sum" option, only "no budget"
    await expect(modal.getByRole('checkbox')).toHaveCount(1);
    await expect(modal.getByRole('checkbox', { name: tr('budgetNone') })).not.toBeChecked();
    await modal.getByLabel(tr('budgetEditLabel')).fill('350');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('budgetUpdatedToast'));
    // Still on the sub-categories, updated
    await expect(page.locator('.budget-breadcrumb')).toContainText('Alimentation');
    await expect(card(page, 'Supermarché').locator('.of')).toHaveText(`/ ${euros(350)}`);

    await page.getByRole('button', { name: tr('allCategories') }).click();
    await expect(card(page, 'Alimentation').locator('.of')).toHaveText(`/ Σ ${euros(450)}`);
    expect(Number((await apiBudget(api, 'Supermarché', 1)).budget)).toBe(350);
});

test('adds a note to a budget, shown on its card, then removes it (#468)', async ({ adminPage: page, api }) => {
    await openBudgets(page);

    let modal = await editBudget(page, 'Logement');
    await expect(modal.getByLabel(tr('budgetNoteLabel'))).toHaveValue('');
    await modal.getByLabel(tr('budgetNoteLabel')).fill('  Loyer + charges, régularisation en juin  ');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal).toHaveCount(0);

    // Shown on the card, trimmed; the amount is unchanged
    const note = card(page, 'Logement').locator('.budget-card-note');
    await expect(note).toContainText('Loyer + charges, régularisation en juin');
    await expect(note).toHaveAttribute('title', 'Loyer + charges, régularisation en juin');
    expect(await apiBudget(api, 'Logement')).toMatchObject({ note: 'Loyer + charges, régularisation en juin' });

    // Found again in the modal, then removed
    modal = await editBudget(page, 'Logement');
    await expect(modal.getByLabel(tr('budgetNoteLabel'))).toHaveValue('Loyer + charges, régularisation en juin');
    await modal.getByLabel(tr('budgetNoteLabel')).fill('');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal).toHaveCount(0);
    await expect(card(page, 'Logement').locator('.budget-card-note')).toHaveCount(0);
    expect((await apiBudget(api, 'Logement')).note).toBeNull();
});

test('overrides the budget of a parent category, then goes back to the sum', async ({ adminPage: page, api }) => {
    await openBudgets(page);

    let modal = await editBudget(page, 'Alimentation');
    const useChildren = modal.getByRole('checkbox', { name: tr('budgetUseChildren', { amount: euros(400) }) });
    await expect(useChildren).toBeChecked();
    await expect(modal.getByLabel(tr('budgetOverrideLabel'))).toHaveCount(0);

    await useChildren.uncheck();
    await modal.getByLabel(tr('budgetOverrideLabel')).fill('600');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal).toHaveCount(0);
    await expect(card(page, 'Alimentation').locator('.of')).toHaveText(`/ ${euros(600)}`);
    expect(await apiBudget(api, 'Alimentation')).toMatchObject({ budget_mode: 'own', children_budget: 400 });

    modal = await editBudget(page, 'Alimentation');
    await expect(modal.getByLabel(tr('budgetOverrideLabel'))).toHaveValue('600');
    await modal.getByRole('checkbox').check();
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(card(page, 'Alimentation').locator('.of')).toHaveText(`/ Σ ${euros(400)}`);
    expect((await apiBudget(api, 'Alimentation')).budget_mode).toBe('children');
});

test('stays green up to 105 % of the budget', async ({ adminPage: page }) => {
    await openBudgets(page);
    const logement = card(page, 'Logement');
    await expect(logement).toHaveClass(/status-ok/);

    // 950 of 910: 104 %
    let modal = await editBudget(page, 'Logement');
    await modal.getByLabel(tr('budgetEditLabel')).fill('910');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(logement.locator('.budget-card-percent')).toHaveText('104 %');
    await expect(logement).toHaveClass(/status-ok/);

    // 950 of 900: 106 %
    modal = await editBudget(page, 'Logement');
    await modal.getByLabel(tr('budgetEditLabel')).fill('900');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(logement.locator('.budget-card-percent')).toHaveText('106 %');
    await expect(logement).toHaveClass(/status-over/);
    await expect(logement.locator('.budget-card-foot')).toContainText(tr('overspentAmount', { amount: euros(50) }));
});

test('refuses an invalid amount', async ({ adminPage: page }) => {
    await openBudgets(page);

    const modal = await editBudget(page, 'Logement');
    await modal.getByLabel(tr('budgetEditLabel')).fill('');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('budgetInvalidAmount'));
    await expect(modal).toBeVisible();
    await modal.getByRole('button', { name: tr('cancel') }).click();
    await expect(modal).toHaveCount(0);
});

/**
 * Waits for the answer telling whether the copy of the previous month is offered
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<import('@playwright/test').Response>}
 */
const copyStatus = page => page.waitForResponse(response => response.url().includes('/api/budget/copy?'));

/** Name of a month inside a sentence ("octobre") */
const monthName = month => tr(`months.${month}`).toLowerCase();

/**
 * Lets the page notice the changes made through the API (back to the foreground)
 * @param {import('@playwright/test').Page} page
 */
const backToPage = page => page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));

test('offers the copy of the previous month only when the month has no budget and the previous one has some', async ({ adminPage: page, api }) => {
    // The current month has its budgets (seed.php): not offered
    let status = copyStatus(page);
    await openBudgets(page);
    expect((await (await status).json()).copyable).toBe(false);
    await expect(page.locator('.budget-copy-button')).toHaveCount(0);

    // The previous month has none, nor the month before it: not offered either
    status = copyStatus(page);
    await page.getByRole('button', { name: tr('periodPrevious'), exact: true }).click();
    expect(await (await status).json()).toMatchObject({ defined: false, source_defined: false, copyable: false });
    await expect(page.locator('.budget-copy-button')).toHaveCount(0);

    // Another member sets a budget the month before: offered without reloading the page
    const before = currentMonth(2);
    expect((await api.post('/api/budget', { data: { category: 4, amount: 900, ...before } })).ok()).toBeTruthy();
    await backToPage(page);
    const copyButton = page.getByRole('button', { name: tr('budgetCopyButton', { month: monthName(before.month) }), exact: true });
    await copyButton.click();
    await page.getByRole('dialog', { name: tr('budgetCopyTitle') }).getByRole('button', { name: tr('budgetCopyConfirm') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('budgetCopiedToast', { count: 1 }));
    await expectAmounts(card(page, 'Logement'), 950, `/ ${euros(900)}`);

    // Once copied, the month has its budget: no longer offered
    await expect(page.locator('.budget-copy-button')).toHaveCount(0);
});

test('copies the budgets of the previous month, completing or overwriting', async ({ adminPage: page, api }) => {
    const prev = currentMonth(1);
    const copyButton = () => page.getByRole('button', { name: tr('budgetCopyButton', { month: monthName(prev.month) }), exact: true });

    // The current month only has budgets at 0 (sum of the sub-categories: not defined);
    // the previous month has Supermarché 321, Restaurant 123 and Salaire 2000
    await offerBudgetCopy(api, [[2, 321], [3, 123], [5, 2000]]);

    await openBudgets(page);
    await copyButton().click();
    let modal = page.getByRole('dialog', { name: tr('budgetCopyTitle') });
    await expect(modal).toContainText(tr('budgetCopyHelp', { month: monthName(prev.month) }));
    // Completing only, by default: only Salaire is copied (the others are set, at 0)
    await expect(modal.getByRole('radio', { name: tr('budgetCopyMissing') })).toBeChecked();
    await modal.getByRole('button', { name: tr('budgetCopyConfirm') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('budgetCopiedToast', { count: 1 }));
    await expect(page.locator('.budget-section-incomes')).toContainText('Salaire');
    expect(Number((await apiBudget(api, 'Supermarché', 1)).budget)).toBe(0);
    // The month has a budget now: no longer offered
    await expect(copyButton()).toHaveCount(0);

    // Salaire put back to 0 by another member: offered again; overwriting, the budgets
    // of the previous month replace the current ones
    const { month, year } = currentMonth();
    expect((await api.post('/api/budget', { data: { category: 5, amount: 0, month, year } })).ok()).toBeTruthy();
    await backToPage(page);
    await copyButton().click();
    modal = page.getByRole('dialog', { name: tr('budgetCopyTitle') });
    await modal.getByRole('radio', { name: tr('budgetCopyOverwrite') }).check();
    await modal.getByRole('button', { name: tr('budgetCopyConfirm') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('budgetCopiedToast', { count: 3 }));
    await card(page, 'Alimentation').click();
    await expectAmounts(card(page, 'Supermarché'), 82.4, `/ ${euros(321)}`);
    expect(Number((await apiBudget(api, 'Supermarché', 1)).budget)).toBe(321);
});

test('a budget changed by another member of the household is shown without reloading the page', async ({ adminPage: page, api }) => {
    await openBudgets(page);
    await expectAmounts(card(page, 'Logement'), 950, `/ ${euros(1000)}`);

    // The answers of the API carry the versions of the data
    const answer = await api.get('/api/category');
    expect(answer.headers()['x-carbure-changes']).toMatch(/budgets=\d+/);

    // Another member changes the budget, then the page comes back to the foreground
    const { month, year } = currentMonth();
    const logement = await apiBudget(api, 'Logement');
    expect((await api.post('/api/budget', { data: { category: logement.id, amount: 1100, month, year } })).ok()).toBeTruthy();
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expectAmounts(card(page, 'Logement'), 950, `/ ${euros(1100)}`);
});

test('the sub-categories show the amounts of the displayed month after a change of month (#402)', async ({ adminPage: page }) => {
    await openBudgets(page);
    await card(page, 'Alimentation').click();
    await expect(card(page, 'Supermarché').locator('.spent')).toHaveText(euros(82.4));

    // Previous month: CB SUPERMARCHE CARREFOUR, not the amount of the current month
    await page.getByRole('button', { name: tr('periodPrevious'), exact: true }).click();
    await card(page, 'Alimentation').click();
    await expect(card(page, 'Supermarché').locator('.spent')).toHaveText(euros(120));
});

test('the sub-categories follow a transaction recategorized elsewhere (#402)', async ({ adminPage: page, api }) => {
    await openBudgets(page);
    await card(page, 'Alimentation').click();
    await expect(card(page, 'Supermarché').locator('.spent')).toHaveText(euros(82.4));
    await page.getByRole('button', { name: tr('allCategories') }).click();

    // Another member puts the bakery in Supermarché, then the page comes back to the foreground
    const { month, year } = currentMonth();
    const transactions = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    const bakery = transactions.find(t => t.label === 'CB BOULANGERIE PAUL');
    expect((await api.put('/api/transaction/category', { data: { id: bakery.id, category: 2 } })).ok()).toBeTruthy();
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));
    await expect(card(page, 'Alimentation').locator('.spent')).toHaveText(euros(134.2));

    // Its sub-category is fetched again, not taken from the cache
    await card(page, 'Alimentation').click();
    await expect(card(page, 'Supermarché').locator('.spent')).toHaveText(euros(89.2));
});

test('the transactions put on a parent category have their own card in its sub-categories (#492)', async ({ adminPage: page, api }) => {
    // The bakery put on Alimentation itself, not on one of its sub-categories
    const { month, year } = currentMonth();
    const transactions = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    const bakery = transactions.find(t => t.label === 'CB BOULANGERIE PAUL');
    expect((await api.put('/api/transaction/category', { data: { id: bakery.id, category: 1 } })).ok()).toBeTruthy();

    await openBudgets(page);
    await expect(card(page, 'Alimentation').locator('.spent')).toHaveText(euros(134.2));
    await card(page, 'Alimentation').click();
    await expect(card(page, 'Supermarché').locator('.spent')).toHaveText(euros(82.4));

    // Its card: what it spent, no budget to set, nor a gauge
    const name = tr('budgetOwnTransactions', { category: 'Alimentation' });
    const own = card(page, escapeRegExp(name));
    await expect(own.locator('.spent')).toHaveText(euros(6.8));
    await expect(own.locator('.of')).toHaveCount(0);
    await expect(own.getByRole('button', { name: tr('budgetEditTitle') })).toHaveCount(0);

    // A click lists only its transactions and stays on the level
    await own.click();
    const list = page.locator('.budget-transactions');
    await expect(list).toContainText(tr('categoryTransactions', { category: name }));
    await expect(list.locator('.transaction-item')).toHaveCount(1);
    await expect(list).toContainText('CB BOULANGERIE PAUL');
    await expect(own).toHaveClass(/selected/);
    await expect(card(page, 'Supermarché')).toBeVisible();
    await expect(page.locator('.budget-breadcrumb')).toContainText('Alimentation');
});

test('a category without budget shows what it spent, not an overspending (#491)', async ({ adminPage: page, api }) => {
    await openBudgets(page);

    const modal = await editBudget(page, 'Logement');
    await modal.getByRole('checkbox', { name: tr('budgetNone') }).check();
    await expect(modal.getByLabel(tr('budgetEditLabel'))).toHaveCount(0);
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('budgetUpdatedToast'));

    // Saved as 0, with the categories without budget: its spending, no gauge nor overspending
    expect(Number((await apiBudget(api, 'Logement')).budget)).toBe(0);
    const logement = page.locator('.budget-section-others .budget-card')
        .filter({ has: page.locator('.budget-card-name', { hasText: /^Logement$/ }) });
    await expect(logement.locator('.spent')).toHaveText(euros(950));
    await expect(logement.locator('.of')).toHaveCount(0);
    await expect(logement).not.toContainText(tr('overspentAmount', { amount: euros(950) }));
    await expect(page.locator('.budget-section-others .budget-section-title')).toHaveText(tr('othersSection'));

    // Out of the summary of the budgeted expenses: only Alimentation, 127.40 of 400
    await expect(page.locator('.budget-section-expenses .budget-card')).toHaveCount(1);
    const summary = page.locator('.budget-summary');
    await expect(summary).toContainText(euros(127.4));
    await expect(summary).toContainText(euros(400));
});
