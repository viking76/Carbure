// Transactions tab: month view, totals, search, check, categorization, detail modal
const { test, expect, tr, openTab, pickCategory, euros, currentMonth } = require('../fixtures');
const path = require('path');

/** Row of a transaction, by its label */
const row = (page, label) => page.locator('.transaction-item').filter({ hasText: label });

test('lists the transactions of the month under the search bar (#413)', async ({ adminPage: page }) => {
    await expect(page.locator('.transaction-item')).toHaveCount(6);
    await expect(page.locator('.transaction-count')).toHaveText(tr('transactionCount', { count: 6 }));

    // No figures of the month above the list: the search bar in their place, with its advanced search
    await expect(page.locator('.stat-card')).toHaveCount(0);
    const search = page.getByRole('search');
    await expect(search.getByRole('searchbox', { name: tr('searchPlaceholder') })).toBeVisible();
    await expect(search.getByRole('button', { name: tr('advancedSearch') })).toBeVisible();

    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.transaction-amount')).toHaveText(euros(-82.4));
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.category-chip')).toHaveText('Supermarché');
});

test('shows the icon and the color of the category of each transaction', async ({ adminPage: page }) => {
    // Icon of the category itself (not of its parent), as on the categories page
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.transaction-icon .material-icons')).toHaveText('shopping_cart');
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC').locator('.transaction-icon .material-icons')).toHaveText('local_dining');
    await expect(row(page, 'PRLV LOYER AGENCE').locator('.transaction-icon .material-icons')).toHaveText('home');
    // Uncategorized: the icon of the type of operation (card)
    await expect(row(page, 'CB BOULANGERIE PAUL').locator('.transaction-icon')).toHaveClass(/type-7/);

    await openTab(page, tr('tabCategories'));
    const category = page.locator('.category-row').filter({ has: page.locator('.category-row-name', { hasText: /^Restaurant$/ }) });
    await expect(category.locator('.material-icons').first()).toHaveText('local_dining');
});

test('shows another month', async ({ adminPage: page }) => {
    const previous = currentMonth(1);
    await page.locator('#year').selectOption(String(previous.year));
    await page.locator('#month').selectOption(String(previous.month));

    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(row(page, 'CB SUPERMARCHE CARREFOUR')).toBeVisible();
});

test('offers only the months that have transactions', async ({ adminPage: page }) => {
    // The data set: the current month and the two previous ones
    const periods = [0, 1, 2].map(ago => currentMonth(ago));
    const years = [...new Set(periods.map(p => String(p.year)))];
    await expect(page.locator('#year option')).toHaveText(years);

    const { year } = currentMonth();
    const months = periods.filter(p => p.year === year).map(p => p.month).sort((a, b) => a - b);
    await expect(page.locator('#month option')).toHaveCount(months.length);
    await expect(page.locator('#month option').first()).toHaveAttribute('value', String(months[0]));
});

test('searches the transactions of every month by label', async ({ adminPage: page }) => {
    await page.getByRole('searchbox', { name: tr('searchPlaceholder') }).fill('LOYER');

    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(page.locator('.search-total')).toContainText(tr('searchTotal', { count: 3 }));
    await expect(page.locator('.search-total strong')).toHaveText(euros(-2850));

    await page.getByRole('button', { name: tr('clearSearch') }).click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);

    await page.getByRole('searchbox', { name: tr('searchPlaceholder') }).fill('INTROUVABLE');
    await expect(page.locator('.transactions-hint')).toHaveText(tr('noSearchResults'));
});

test('searches with the criteria of the advanced search, removable one by one', async ({ adminPage: page }) => {
    const button = page.locator('.transaction-search-main').getByRole('button', { name: tr('advancedSearch') });
    await button.click();
    const modal = page.getByRole('dialog', { name: tr('advancedSearch') });
    await expect(modal.getByLabel(tr('advancedSearchLabel'))).toBeFocused();

    // Expenses of 100 or more in Alimentation and its sub-categories, of every month
    await modal.getByRole('radio', { name: tr('advancedSearchExpenses') }).check();
    await modal.getByLabel(tr('advancedSearchMin', { currency: '€' })).fill('100');
    // Chosen in the list of the portal (#423): a chip with its icon, no more offered in the list
    const categories = modal.getByRole('group', { name: tr('advancedSearchCategories') });
    await pickCategory(categories, 'Restaurant');
    await pickCategory(categories, 'Alimentation');
    const chosen = categories.locator('.advanced-search-category-name');
    await expect(chosen).toHaveText(['Restaurant', 'Alimentation']);
    await expect(categories.locator('.advanced-search-category').first().locator('.category-badge .material-icons')).toHaveText('local_dining');
    await categories.getByRole('button', { name: tr('advancedSearchRemove', { filter: 'Restaurant' }) }).click();
    await expect(chosen).toHaveText(['Alimentation']);
    await categories.locator('.category-picker-button').click();
    const option = name => categories.locator('.category-picker-option')
        .filter({ has: page.locator('.category-picker-name', { hasText: new RegExp(`^\\s*${name}\\s*$`) }) });
    await expect(option('Logement')).toHaveCount(1);
    await expect(option('Alimentation')).toHaveCount(0);
    await page.keyboard.press('Escape');
    await modal.getByRole('button', { name: tr('advancedSearchApply') }).click();

    await expect(modal).toHaveCount(0);
    await expect(page.locator('.transactions-header h2')).toHaveText(tr('searchResultsTitle'));
    await expect(page.locator('.transaction-item')).toHaveCount(1);
    await expect(page.locator('.transaction-item')).toContainText('CB SUPERMARCHE CARREFOUR');
    await expect(button).toHaveAccessibleName(tr('advancedSearchActive', { count: 3 }));
    const chips = page.locator('.search-filter-chip-label');
    await expect(chips).toHaveText([tr('advancedSearchExpenses'), tr('advancedSearchAmountMin', { amount: euros(100) }), 'Alimentation']);
    // Right below the search bar, above the list (#441)
    const bar = await page.locator('.transaction-search-main').boundingBox();
    const filters = await page.locator('.search-filters').boundingBox();
    const list = await page.locator('.transactions-container').boundingBox();
    expect(filters.y).toBeGreaterThanOrEqual(bar.y + bar.height);
    expect(filters.y - (bar.y + bar.height)).toBeLessThan(24);
    expect(filters.y + filters.height).toBeLessThanOrEqual(list.y);

    // Without the amount: the restaurant and the supermarket of this month too
    await page.getByRole('button', { name: tr('advancedSearchRemove', { filter: tr('advancedSearchAmountMin', { amount: euros(100) }) }) }).click();
    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(page.locator('.search-total strong')).toHaveText(euros(-247.4));

    // The modal starts from the search shown; a text narrows it
    await button.click();
    await expect(modal.locator('.advanced-search-category-name')).toHaveText(['Alimentation']);
    await modal.getByLabel(tr('advancedSearchLabel')).fill('casino');
    await modal.getByRole('button', { name: tr('advancedSearchApply') }).click();
    await expect(page.getByRole('searchbox', { name: tr('searchPlaceholder') })).toHaveValue('casino');
    await expect(page.locator('.transaction-item')).toHaveCount(1);

    await page.locator('.search-filters').getByRole('button', { name: tr('clearSearch') }).click();
    await expect(page.locator('.search-filters')).toHaveCount(0);
    await expect(page.getByRole('searchbox', { name: tr('searchPlaceholder') })).toHaveValue('');
    await expect(page.locator('.transaction-item')).toHaveCount(6);
});

test('filters the transactions not checked yet', async ({ adminPage: page }) => {
    const toggle = page.locator('.toggle-unchecked');
    await expect(page.locator('.toggle-unchecked .toggle-label')).toHaveText(tr('uncheckedCount', { count: 3 }));

    await toggle.click();
    await expect(toggle.getByRole('switch')).toBeChecked();
    await expect(page.locator('.transaction-item')).toHaveCount(3);

    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);
});

test('filters the transactions without a category', async ({ adminPage: page }) => {
    const toggle = page.locator('.toggle-uncategorized');
    await expect(toggle.locator('.toggle-label')).toHaveText(tr('uncategorizedCount', { count: 1 }));

    await toggle.click();
    await expect(toggle.getByRole('switch')).toBeChecked();
    await expect(page.locator('.transaction-item')).toHaveCount(1);
    await expect(row(page, 'CB BOULANGERIE PAUL')).toBeVisible();

    // Once categorized, it leaves the filtered list
    await pickCategory(row(page, 'CB BOULANGERIE PAUL'), 'Alimentation');
    await expect(page.locator('.transaction-item')).toHaveCount(0);
    await expect(page.locator('.transactions-hint')).toHaveText(tr('allCategorized'));
    await expect(toggle.locator('.toggle-label')).toHaveText(tr('uncategorizedCount', { count: 0 }));

    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);
});

test('checks and unchecks a transaction', async ({ adminPage: page, api }) => {
    const transaction = row(page, 'CB SUPERMARCHE CASINO');
    await transaction.getByRole('button', { name: tr('checkTransaction') }).click();
    await expect(transaction.getByRole('button', { name: tr('uncheckTransaction') })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('.toggle-unchecked .toggle-label')).toHaveText(tr('uncheckedCount', { count: 2 }));

    const { month, year } = currentMonth();
    const saved = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    expect(Number(saved.find(t => t.label === 'CB SUPERMARCHE CASINO').pointed)).toBe(1);

    await page.reload();
    await expect(row(page, 'CB SUPERMARCHE CASINO').getByRole('button', { name: tr('uncheckTransaction') })).toBeVisible();
});

test('categorizes a transaction and proposes a rule', async ({ adminPage: page }) => {
    const transaction = row(page, 'CB BOULANGERIE PAUL');
    await pickCategory(transaction, 'Alimentation');

    await expect(transaction.locator('.category-chip')).toHaveText('Alimentation');
    const suggestion = page.locator('.rule-suggestion');
    await expect(suggestion).toContainText(tr('ruleSuggestion', { label: 'CB BOULANGERIE PAUL', category: 'Alimentation' }));

    // The rule modal opens on the rules tab, filled in
    await suggestion.getByRole('button', { name: tr('createRule') }).click();
    await expect(page.locator('.tabs .tab-button.active')).toHaveAccessibleName(tr('tabRules'));
    await expect(page.locator('#rule-keyword')).toHaveValue('CB BOULANGERIE PAUL');
    await page.getByRole('button', { name: tr('save') }).click();
    await expect(page.getByRole('button', { name: `${tr('editRule')} CB BOULANGERIE PAUL` })).toBeVisible();
});

test('a user categorizes without being offered a rule', async ({ userPage: page }) => {
    const transaction = row(page, 'CB BOULANGERIE PAUL');
    await pickCategory(transaction, 'Alimentation');

    await expect(transaction.locator('.category-chip')).toHaveText('Alimentation');
    await expect(page.locator('.rule-suggestion')).toHaveCount(0);
});

test('the category list of a row closes when the page scrolls, not on a late scroll event', async ({ userPage: page }) => {
    await page.setViewportSize({ width: 1280, height: 500 });
    const transaction = row(page, 'CB BOULANGERIE PAUL');
    await transaction.locator('.category-picker-button').click();
    const search = transaction.locator('.category-picker-search input');
    await expect(search).toBeVisible();

    // A scroll event that did not move the button (end of the scroll bringing it into view)
    await page.evaluate(() => window.dispatchEvent(new Event('scroll')));
    await expect(search).toBeVisible();

    // Towards where the page can scroll: the click may have scrolled it to the bottom
    await page.evaluate(() => window.scrollBy(0, window.scrollY > 0 ? -100 : 100));
    await expect(search).toHaveCount(0);
});

test('changes the category and the checked state in the detail modal', async ({ adminPage: page, api }) => {
    await row(page, 'CB RESTAURANT LE PETIT ZINC').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();

    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(modal.locator('.transaction-detail-amount')).toHaveText(euros(-45));
    // Bank at the origin of the transaction
    await expect(modal.locator('.transaction-detail-bank')).toHaveText('BNP ···0001');
    await pickCategory(modal, 'Supermarché');
    await modal.getByLabel(tr('transactionChecked')).check();
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(modal).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC').locator('.category-chip')).toHaveText('Supermarché');

    const { month, year } = currentMonth();
    const saved = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json())
        .find(t => t.label === 'CB RESTAURANT LE PETIT ZINC');
    expect(Number(saved.category)).toBe(2);
    expect(Number(saved.pointed)).toBe(1);
});

test('splits a transaction across several categories, then cancels the split', async ({ adminPage: page, api }) => {
    await row(page, 'CB SUPERMARCHE CASINO').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    await page.getByRole('dialog', { name: tr('transactionDetail') }).getByRole('button', { name: tr('splitButton') }).click();

    const modal = page.getByRole('dialog', { name: tr('splitTitle') });
    const save = modal.getByRole('button', { name: tr('save') });
    const rest = modal.locator('.split-rest');
    // First line: the transaction itself, in its category, not removable; all its amount to allocate
    const lines = modal.locator('.split-part');
    await expect(lines).toHaveCount(2);
    await expect(lines.first().getByRole('button', { name: tr('splitRemovePart') })).toHaveCount(0);
    await expect(rest).toContainText(tr('splitToAllocate'));
    await expect(rest.locator('strong')).toHaveText(euros(82.4));
    await expect(save).toBeDisabled();
    await expect(modal.locator('.split-hint-text')).toHaveText(tr('splitHintAllocate'));

    const part = lines.nth(1);
    await pickCategory(part, 'Restaurant');
    await part.getByLabel(tr('splitPartAmount', { number: 2 })).fill('20');
    // More than the amount: refused, by how much
    await lines.first().getByLabel(tr('splitPartAmount', { number: 1 })).fill('70');
    await expect(modal.locator('.split-hint-text')).toHaveText(tr('splitExceeded', { amount: euros(7.6) }));
    await expect(save).toBeDisabled();
    // The whole amount: saved
    await lines.first().getByLabel(tr('splitPartAmount', { number: 1 })).fill('62.40');
    await expect(rest.locator('strong')).toHaveText(euros(0));
    await expect(modal.locator('.split-hint')).toHaveCount(0);
    await save.click();
    await expect(modal).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('splitSaved'));

    // The part right after its transaction, which keeps the rest
    const rows = row(page, 'CB SUPERMARCHE CASINO');
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(0).locator('.transaction-amount')).toHaveText(euros(-62.4));
    await expect(rows.nth(0).locator('.category-chip')).toHaveText('Supermarché');
    await expect(rows.nth(0).locator('.split-badge')).toContainText(tr('splitBankAmount', { amount: euros(-82.4) }));
    await expect(rows.nth(1)).toHaveClass(/transaction-split-part/);
    await expect(rows.nth(1).locator('.transaction-amount')).toHaveText(euros(-20));
    await expect(rows.nth(1).locator('.category-chip')).toHaveText('Restaurant');

    const { month, year } = currentMonth();
    const saved = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json())
        .filter(t => t.label === 'CB SUPERMARCHE CASINO');
    const origin = saved.find(t => t.split_of === null);
    const savedPart = saved.find(t => t.split_of !== null);
    expect([savedPart.split_of, Number(savedPart.amount), Number(savedPart.category)]).toEqual([origin.id, -20, 3]);

    // Cancelled from the part: the transaction gets back the amount of the bank
    await rows.nth(1).getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    await page.getByRole('dialog', { name: tr('transactionDetail') }).getByRole('button', { name: tr('splitButton') }).click();
    await expect(modal.getByLabel(tr('splitPartAmount', { number: 1 }))).toHaveValue('62.40');
    await expect(modal.getByLabel(tr('splitPartAmount', { number: 2 }))).toHaveValue('20.00');
    // The small red trash button at the bottom left, as in a transaction detail
    const remove = modal.getByRole('button', { name: tr('splitCancel') });
    await expect(remove).toHaveClass(/btn-danger-icon/);
    await expect(remove.locator('.material-icons')).toHaveText('delete');
    await remove.click();
    await expect(page.locator('.toast')).toHaveText(tr('splitCancelled'));
    await expect(rows).toHaveCount(1);
    await expect(rows.locator('.transaction-amount')).toHaveText(euros(-82.4));
});

test('deletes the selected transactions (#421)', async ({ adminPage: page, api }) => {
    // No tag: the selection still offers the deletion
    await page.getByRole('button', { name: tr('tagSelect'), exact: true }).click();
    const bar = page.getByRole('region', { name: tr('tagSelect') });
    // No tag yet: the tag can be typed, nothing to apply
    await expect(bar.getByRole('button', { name: tr('tagApply') })).toBeDisabled();
    const remove = bar.getByRole('button', { name: tr('selectionDelete') });
    await expect(remove).toBeDisabled();

    await page.getByLabel(tr('tagSelectTransaction', { label: 'CB SUPERMARCHE CASINO' })).check();
    await page.getByLabel(tr('tagSelectTransaction', { label: 'CB RESTAURANT LE PETIT ZINC' })).check();
    await expect(bar.locator('.tag-selection-count')).toHaveText(tr('tagSelected', { count: 2 }));

    // The confirmation is accepted (fixtures)
    await remove.click();
    await expect(page.locator('.toast')).toHaveText(tr('selectionDeleted', { count: 2 }));
    await expect(bar).toHaveCount(0);
    await expect(page.locator('.transaction-item')).toHaveCount(4);
    await expect(row(page, 'CB SUPERMARCHE CASINO')).toHaveCount(0);

    const { month, year } = currentMonth();
    const labels = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json()).map(t => t.label);
    expect(labels).not.toContain('CB RESTAURANT LE PETIT ZINC');
});

test('changes the category of the selected transactions from one of them, and checks them', async ({ adminPage: page, api }) => {
    await page.getByRole('button', { name: tr('tagSelect'), exact: true }).click();
    const bar = page.getByRole('region', { name: tr('tagSelect') });
    // Not selected: a categorized transaction keeps its chip, no picker in the bar
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC').locator('.category-picker')).toHaveCount(0);
    await expect(bar.locator('.category-picker')).toHaveCount(0);

    await page.getByLabel(tr('tagSelectTransaction', { label: 'CB BOULANGERIE PAUL' })).check();
    await page.getByLabel(tr('tagSelectTransaction', { label: 'CB RESTAURANT LE PETIT ZINC' })).check();
    // Selected: its category is a picker, which changes the whole selection
    const restaurant = row(page, 'CB RESTAURANT LE PETIT ZINC');
    await expect(restaurant.locator('.transaction-category')).toHaveAttribute('title', tr('selectionCategoryHelp', { count: 2 }));
    await expect(restaurant.locator('.category-picker-name')).toContainText('Restaurant');
    await pickCategory(restaurant, 'Alimentation');

    await expect(page.locator('.toast')).toHaveText(tr('selectionCategorized', { count: 2, name: 'Alimentation' }));
    // The selection stays, both show the new category
    await expect(bar.locator('.tag-selection-count')).toHaveText(tr('tagSelected', { count: 2 }));
    for (const label of ['CB BOULANGERIE PAUL', 'CB RESTAURANT LE PETIT ZINC']) {
        await expect(row(page, label).locator('.category-picker-name')).toContainText('Alimentation');
    }
    await bar.getByRole('button', { name: tr('selectionClose') }).click();
    for (const label of ['CB BOULANGERIE PAUL', 'CB RESTAURANT LE PETIT ZINC']) {
        await expect(row(page, label).locator('.category-chip')).toHaveText('Alimentation');
    }
    // The others did not change
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.category-chip')).toHaveText('Supermarché');

    const { month, year } = currentMonth();
    const saved = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json())
        .filter(t => ['CB BOULANGERIE PAUL', 'CB RESTAURANT LE PETIT ZINC'].includes(t.label));
    expect(new Set(saved.map(t => Number(t.category))).size).toBe(1);
    expect(saved.map(t => [Number(t.pointed), t.category_source])).toEqual([[1, 'manual'], [1, 'manual']]);
});

test('selects all the transactions shown, then closes the selection (#430)', async ({ adminPage: page }) => {
    await page.getByRole('button', { name: tr('tagSelect'), exact: true }).click();
    const bar = page.getByRole('region', { name: tr('tagSelect') });
    const all = bar.getByRole('button', { name: tr('selectionSelectAll') });

    await all.click();
    await expect(bar.locator('.tag-selection-count')).toHaveText(tr('tagSelected', { count: 6 }));
    await expect(page.locator('.transaction-select:checked')).toHaveCount(6);
    // Once all are ticked, the same button unticks them
    const none = bar.getByRole('button', { name: tr('selectionUnselectAll') });
    await expect(none).toHaveAttribute('aria-pressed', 'true');
    await none.click();
    await expect(bar.locator('.tag-selection-count')).toHaveText(tr('tagSelected', { count: 0 }));
    await expect(page.locator('.transaction-select:checked')).toHaveCount(0);

    // Only the ones shown: the filter of the unchecked transactions applied
    await page.locator('.toggle-unchecked').click();
    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await bar.getByRole('button', { name: tr('selectionSelectAll') }).click();
    await expect(bar.locator('.tag-selection-count')).toHaveText(tr('tagSelected', { count: 3 }));

    // The close button is the last one of the bar, at its far right
    const close = bar.getByRole('button', { name: tr('selectionClose') });
    await expect(bar.getByRole('button').last()).toHaveAccessibleName(tr('selectionClose'));
    const [barBox, closeBox] = [await bar.boundingBox(), await close.boundingBox()];
    expect(barBox.x + barBox.width - (closeBox.x + closeBox.width)).toBeLessThan(40);
    await close.click();
    await expect(bar).toHaveCount(0);
    await expect(page.locator('.transaction-select')).toHaveCount(0);
});

test('deletes a transaction from its detail', async ({ adminPage: page, api }) => {
    await row(page, 'CB RESTAURANT LE PETIT ZINC').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });

    // The confirmation is accepted (fixtures)
    await modal.getByRole('button', { name: tr('deleteTransaction') }).click();

    await expect(modal).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('transactionDeleted'));
    await expect(row(page, 'CB RESTAURANT LE PETIT ZINC')).toHaveCount(0);
    await expect(page.locator('.transaction-item')).toHaveCount(5);

    // Deleted on the server
    const { month, year } = currentMonth();
    const labels = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json()).map(t => t.label);
    expect(labels).not.toContain('CB RESTAURANT LE PETIT ZINC');
});

test('searches the transactions similar to the one of the detail', async ({ adminPage: page }) => {
    await row(page, 'PRLV LOYER AGENCE').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });
    await modal.getByRole('button', { name: tr('searchSimilarTransactions') }).click();

    // The detail closes, the label search runs with the label without "PRLV"
    await expect(modal).toHaveCount(0);
    const search = page.getByRole('searchbox', { name: tr('searchPlaceholder') });
    await expect(search).toHaveValue('LOYER AGENCE');
    await expect(search).toBeFocused();
    await expect(page.locator('.transaction-item')).toHaveCount(3);
    await expect(page.locator('.search-total')).toContainText(tr('searchTotal', { count: 3 }));

    // Cleared like a typed search
    await page.getByRole('button', { name: tr('clearSearch') }).click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);
});

test('keeps the search of the similar transactions compact, down to 320 px', async ({ adminPage: page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await row(page, 'PRLV LOYER AGENCE').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });
    const similar = modal.getByRole('button', { name: tr('searchSimilarTransactions') });
    const body = modal.locator('.modal-body');

    for (const width of [1280, 320]) {
        await page.setViewportSize({ width, height: 800 });
        await expect(similar).toBeVisible();

        // A small button (not a full-size action), still a target of 24 x 24 px at least
        const box = await similar.boundingBox();
        expect(box.height).toBeGreaterThanOrEqual(24);
        expect(box.height).toBeLessThanOrEqual(32);
        expect(box.width).toBeGreaterThanOrEqual(24);
        // Inside the modal, without a horizontal scroll
        const area = await body.boundingBox();
        expect(box.x + box.width).toBeLessThanOrEqual(area.x + area.width);
        expect(await body.evaluate(el => el.scrollWidth <= el.clientWidth)).toBe(true);

        // Reached with the keyboard, its focus visible
        await modal.getByRole('button', { name: tr('close') }).focus();
        await page.keyboard.press('Tab');
        await expect(similar).toBeFocused();
        await expect(similar).toHaveCSS('outline-style', 'solid');
    }
});

test('closes the detail modal with Escape', async ({ adminPage: page }) => {
    await row(page, 'PRLV LOYER AGENCE').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    await expect(page.getByRole('dialog')).toBeVisible();

    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('shows the recurring transactions of the month in a bar folded by default (#465)', async ({ adminPage: page }) => {
    // Salary and rent: in each of the 3 months of the data set, counted in their bar, inside the list
    const bar = page.locator('.transactions-container').getByRole('region', { name: tr('recurringSummary') });
    await expect(bar.locator('.recurring-bar-title')).toHaveText(tr('recurringCount', { count: 2 }));
    const unfold = bar.getByRole('button', { name: tr('recurringDetails') });
    await expect(unfold).toHaveAttribute('aria-expanded', 'false');
    await expect(unfold).toHaveAttribute('aria-controls', 'recurring-bar-details');
    // Folded: only their number, the filter and the calendar hidden
    const details = page.locator('#recurring-bar-details');
    await expect(details).toBeHidden();
    await expect(bar.getByRole('switch', { name: tr('recurringOnly') })).toHaveCount(0);

    await unfold.click();
    await expect(unfold).toHaveAttribute('aria-expanded', 'true');
    await expect(details).toBeVisible();
    await expect(bar.locator('.recurring-bar-detail')).toHaveText(tr('recurringCalendarReceived', { count: 2 }));
    await expect(bar.locator('.recurring-bar-total-label')).toHaveText(tr('recurringTotal'));
    await expect(bar.locator('.recurring-bar-total strong')).toHaveText(euros(2250));
    await expect(bar.locator('.recurring-bar-total strong')).toHaveClass(/positive/);
    const toggle = bar.locator('.toggle-recurring');
    await expect(toggle).toContainText(tr('recurringOnly'));
    await expect(row(page, 'PRLV LOYER AGENCE').locator('.recurring-badge')).toBeVisible();
    await expect(row(page, 'CB SUPERMARCHE CASINO').locator('.recurring-badge')).toHaveCount(0);

    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(2);
    await expect(row(page, 'VIR SALAIRE ACME')).toBeVisible();
    await expect(row(page, 'PRLV LOYER AGENCE')).toBeVisible();

    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(6);

    // Unfolded: remembered in this browser
    await page.reload();
    await expect(unfold).toHaveAttribute('aria-expanded', 'true');
    await expect(details).toBeVisible();

    // Folded with the keyboard while filtered: the whole list is back, folded after a reload
    await toggle.click();
    await expect(page.locator('.transaction-item')).toHaveCount(2);
    await unfold.focus();
    await page.keyboard.press('Enter');
    await expect(unfold).toHaveAttribute('aria-expanded', 'false');
    await expect(details).toBeHidden();
    await expect(page.locator('.transaction-item')).toHaveCount(6);
    await page.reload();
    await expect(unfold).toHaveAttribute('aria-expanded', 'false');
    await expect(details).toBeHidden();
});

test('shows the recurring transactions of the month in a calendar', async ({ adminPage: page }) => {
    const bar = page.getByRole('region', { name: tr('recurringSummary') });
    await bar.getByRole('button', { name: tr('recurringDetails') }).click();
    await bar.getByRole('button', { name: tr('recurringCalendarButton') }).click();
    const modal = page.locator('.modal-recurring-calendar');
    await expect(modal).toBeVisible();

    // Salary and rent, both received this month, on the day of their transaction
    await expect(modal.locator('.recurring-calendar-total.received')).toContainText(tr('recurringCalendarReceived', { count: 2 }));
    await expect(modal.locator('.recurring-calendar-item.status-received')).toHaveCount(2);
    await expect(modal.locator('.recurring-calendar-today')).toHaveCount(1);

    // A received one opens its transaction
    await modal.getByRole('button', { name: /PRLV LOYER AGENCE/ }).click();
    await expect(modal).toHaveCount(0);
    await expect(page.getByRole('dialog', { name: tr('transactionDetail') })).toContainText('PRLV LOYER AGENCE');
    await page.keyboard.press('Escape');

    await page.getByRole('button', { name: tr('recurringCalendarButton') }).click();
    await expect(modal).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('imports a bank statement file', async ({ adminPage: page }) => {
    const day = d => {
        const date = new Date();
        date.setDate(d);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    };
    const csv = 'Date;Libellé;Montant\n'
        // Same transaction as the data set: already there
        + `${day(1)};VIR SALAIRE ACME;3 200,00\n`
        // Same amount as CB SUPERMARCHE CASINO, one day later: probable duplicate
        + `${day(3)};CARTE SUPERMARCHE CASINO;-82,40\n`
        + `${day(7)};CB FLEURISTE ROSE;-25,00\n`
        // Twice the same day: two transactions
        + `${day(7)};CB FLEURISTE ROSE;-25,00\n`;

    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    await modal.locator('input[type="file"]').setInputFiles({ name: 'releve.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });

    await expect(modal.locator('.import-count.status-new strong')).toHaveText('2');
    await expect(modal.locator('.import-count.status-duplicate strong')).toHaveText('1');
    await expect(modal.locator('.import-count.status-known strong')).toHaveText('1');
    // No bank writes this header: its columns were guessed
    await expect(modal.locator('.import-bank')).toHaveText(tr('importBankGeneric'));
    await expect(modal.locator('.import-row.status-duplicate')).toContainText('CB SUPERMARCHE CASINO');
    // Only the new transaction is checked
    await expect(modal.locator('.import-row.status-duplicate input')).not.toBeChecked();
    await expect(modal.locator('.import-row.status-known input')).toBeDisabled();

    await modal.getByRole('button', { name: tr('importConfirm', { count: 2 }) }).click();
    await expect(modal).toHaveCount(0);
    await expect(row(page, 'CB FLEURISTE ROSE')).toHaveCount(2);
    await expect(page.locator('.transaction-item')).toHaveCount(8);
});

test('shows the bank whose export was recognized in the import preview', async ({ adminPage: page }) => {
    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });

    // An export of BoursoBank: read with its profile
    await modal.locator('input[type="file"]').setInputFiles(path.join(__dirname, '../../fixtures/import/boursorama.csv'));
    await expect(modal.locator('.import-bank')).toHaveText(tr('importBankRecognized', { bank: 'Boursorama / BoursoBank' }));
    await expect(modal.locator('.import-row')).toHaveCount(1);
    await expect(modal.locator('.import-row')).toContainText('CARTE 06/01/26 SNCF');

    // An OFX file is read the same for every bank: no line about the bank
    await modal.getByRole('button', { name: tr('importOtherFile') }).click();
    await modal.locator('input[type="file"]').setInputFiles(path.join(__dirname, '../../fixtures/import/releve.ofx'));
    await expect(modal.locator('.import-count.status-new strong')).toHaveText('4');
    await expect(modal.locator('.import-bank')).toHaveCount(0);
});

test('imports only the transactions left checked', async ({ adminPage: page }) => {
    const day = d => {
        const date = new Date();
        date.setDate(d);
        return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    };
    const csv = 'Date;Libellé;Montant\n'
        // Same amount as CB SUPERMARCHE CASINO, one day later: probable duplicate
        + `${day(3)};CARTE SUPERMARCHE CASINO;-82,40\n`
        + `${day(6)};CB LIBRAIRIE PAGE;-18,90\n`
        + `${day(7)};CB FLEURISTE ROSE;-25,00\n`;

    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    await modal.locator('input[type="file"]').setInputFiles({ name: 'releve.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    const checkbox = label => modal.getByRole('checkbox', { name: new RegExp(label) });
    const all = modal.getByRole('checkbox', { name: tr('importSelectAll') });

    // The two new transactions are checked: one is unchecked
    await expect(checkbox('CB LIBRAIRIE PAGE')).toBeChecked();
    await checkbox('CB LIBRAIRIE PAGE').uncheck();
    await expect(modal.getByText(tr('importSelectedCount', { count: 1 }))).toBeVisible();
    await expect(modal.getByRole('button', { name: tr('importConfirm', { count: 1 }) })).toBeEnabled();

    // Check all (the probable duplicate too), then uncheck all: nothing to import
    await all.check();
    await expect(modal.getByRole('button', { name: tr('importConfirm', { count: 3 }) })).toBeEnabled();
    await all.uncheck();
    await expect(modal.getByRole('button', { name: tr('importConfirm', { count: 0 }) })).toBeDisabled();

    // Only the florist is imported
    await checkbox('CB FLEURISTE ROSE').check();
    await modal.getByRole('button', { name: tr('importConfirm', { count: 1 }) }).click();
    await expect(modal).toHaveCount(0);
    await expect(row(page, 'CB FLEURISTE ROSE')).toHaveCount(1);
    await expect(row(page, 'CB LIBRAIRIE PAGE')).toHaveCount(0);
    await expect(row(page, 'CARTE SUPERMARCHE CASINO')).toHaveCount(0);
});

test('imports a statement file without any account to synchronize', async ({ adminPage: page }) => {
    // No followed account anymore
    await openTab(page, tr('tabAccounts'));
    for (const bankId of ['DEMO0000001@bnp', 'fail@bank']) {
        const item = page.locator('.account-item').filter({ has: page.locator('code', { hasText: bankId }) });
        await item.getByRole('button', { name: tr('deleteAccount') }).click();
        await expect(item).toHaveCount(0);
    }

    // Empty month (as on a new installation): the import is offered by the empty state
    await page.route('**/api/transaction?month=*', route => route.fulfill({ json: [] }));
    await page.reload();
    await expect(page.locator('.empty-state')).toContainText(tr('emptyImportHint'));
    await page.locator('.empty-state').getByRole('button', { name: tr('importButton') }).click();
    await page.unroute('**/api/transaction?month=*');

    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    const csv = 'Date;Libellé;Montant\n15/03/2020;CB LIBRAIRIE;-18,90\n20/03/2020;VIR REMBOURSEMENT;40,00\n';
    await modal.locator('input[type="file"]').setInputFiles({ name: 'releve.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    await expect(modal.locator('.import-count.status-new strong')).toHaveText('2');
    await modal.getByRole('button', { name: tr('importConfirm', { count: 2 }) }).click();

    // The portal shows the month of the imported transactions
    await expect(modal).toHaveCount(0);
    await expect(row(page, 'CB LIBRAIRIE')).toHaveCount(1);
    await expect(page.locator('.transaction-item')).toHaveCount(2);
    // Other files are imported from the toolbar of the list
    await expect(page.locator('.empty-state')).toHaveCount(0);
    await expect(page.getByRole('button', { name: tr('importButton') })).toBeVisible();
});

test('explains why a file cannot be imported', async ({ adminPage: page }) => {
    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    await modal.locator('input[type="file"]').setInputFiles({ name: 'notes.csv', mimeType: 'text/csv', buffer: Buffer.from('a;b\n1;2\n') });
    await expect(modal.getByRole('alert')).toContainText('Columns not found');
});

test('the category list shows first the categories of the direction of the transaction (#498)', async ({ adminPage: page }) => {
    const groups = scope => scope.locator('.category-picker-group-title');

    // An expense: expenses, then off-budget (transfers), then incomes
    await row(page, 'CB BOULANGERIE PAUL').locator('.category-picker-button').click();
    await expect(groups(row(page, 'CB BOULANGERIE PAUL'))).toHaveText([tr('categoryTypes_DEBIT'), tr('categoryTypes_HORS-BUDGET'), tr('categoryTypes_CREDIT')]);
    await page.keyboard.press('Escape');

    // An income: incomes first; the expenses are still offered (a refund)
    await row(page, 'VIR SALAIRE ACME').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const modal = page.getByRole('dialog', { name: tr('transactionDetail') });
    await modal.locator('.category-picker-button').click();
    await expect(groups(modal)).toHaveText([tr('categoryTypes_CREDIT'), tr('categoryTypes_HORS-BUDGET'), tr('categoryTypes_DEBIT')]);
    await expect(modal.locator('.category-picker-option').first()).toContainText('Salaire');
});

test('imports a statement on the account chosen (#485)', async ({ adminPage: page, api }) => {
    const accounts = await (await api.get('/api/bank')).json();
    const account = accounts.find(a => a.account_number === 'DEMO0000001');
    const date = new Date();
    date.setDate(8);
    const csv = 'Date;Libellé;Montant\n' + `${date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' })};CB CORDONNERIE;-14,00\n`;

    await page.getByRole('button', { name: tr('importButton') }).click();
    const modal = page.getByRole('dialog', { name: tr('importTitle') });
    await modal.locator('input[type="file"]').setInputFiles({ name: 'releve.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });

    // No account number in the file: none by default, any account of the household offered
    const select = modal.getByLabel(tr('importAccountLabel'));
    await expect(select.locator('option').first()).toHaveText(tr('importAccountNone'));
    await expect(select).toHaveValue('');
    await select.selectOption(String(account.id));
    await modal.getByRole('button', { name: tr('importConfirm', { count: 1 }) }).click();
    await expect(modal).toHaveCount(0);

    const { month, year } = currentMonth();
    const imported = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json()).find(t => t.label === 'CB CORDONNERIE');
    expect(imported.bank_name).toBe('bnp');
    expect(imported.account_number).toBe('DEMO0000001');
});
