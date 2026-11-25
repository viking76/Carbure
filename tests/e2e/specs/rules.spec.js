// Rules tab (administrators): categorization rules by keyword
const { test, expect, tr, openTab, pickCategory, currentMonth } = require('../fixtures');
const { contrastProblems } = require('../a11y');

/** Button of a rule, by its keyword */
const rule = (page, keyword) => page.getByRole('button', { name: `${tr('editRule')} ${keyword}` });

/** Click the button applying the rules, named by its visible text and described by its mode */
async function applyRules(page, help) {
    const button = page.getByRole('button', { name: tr('applyRules'), exact: true });
    await expect(button).toHaveAccessibleDescription(tr(help));
    await button.click();
}

test('lists the rules grouped by category, with a filter', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));

    await expect(page.getByRole('heading', { name: tr('rulesCount', { count: 4 }) })).toBeVisible();
    await expect(page.locator('.rules-group')).toHaveCount(4);
    // LOYER notifies the household: bell and light red background
    await expect(rule(page, 'LOYER').locator('.rule-notify')).toBeVisible();
    await expect(rule(page, 'LOYER')).toHaveClass(/rule-chip-notify/);

    await page.getByRole('searchbox', { name: tr('ruleFilterPlaceholder') }).fill('super');
    await expect(page.locator('.rule-chip')).toHaveCount(1);
    await expect(rule(page, 'SUPERMARCHE')).toBeVisible();
});

test('adds a rule, applied at once to the existing transactions', async ({ adminPage: page, api }) => {
    await openTab(page, tr('tabRules'));
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();

    const modal = page.getByRole('dialog', { name: tr('addRuleTitle') });
    await modal.getByLabel(new RegExp(tr('ruleKeyword'))).fill('BOULANGERIE');
    await pickCategory(modal, 'Alimentation');
    await modal.getByRole('button', { name: tr('save') }).click();

    await expect(page.locator('.toast')).toHaveText(tr('ruleSavedApplied', { count: 1 }));
    await expect(rule(page, 'BOULANGERIE')).toBeVisible();

    const { month, year } = currentMonth();
    const transactions = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    expect(Number(transactions.find(t => t.label === 'CB BOULANGERIE PAUL').category)).toBe(1);
});

test('refuses a rule without keyword', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();

    await page.getByRole('dialog').getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('ruleRequired'));
    await expect(page.getByRole('dialog')).toBeVisible();
});

test('refuses a rule already existing, with a message readable above the modal', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();

    const modal = page.getByRole('dialog', { name: tr('addRuleTitle') });
    await modal.getByLabel(new RegExp(tr('ruleKeyword'))).fill('LOYER');
    await pickCategory(modal, 'Alimentation');
    await modal.getByRole('button', { name: tr('save') }).click();

    const toast = page.locator('.toast');
    // In the language of the portal (French), not the English message of the API (#366)
    await expect(toast).toHaveText(tr('apiErrors.keyword_exists'));
    await expect(modal).toBeVisible();
    // In front of the modal and its blurred background (#364), not only in the page
    // (elementFromPoint skips it otherwise: a message does not catch the clicks)
    const inFront = await toast.evaluate(element => {
        const box = element.getBoundingClientRect();
        element.style.pointerEvents = 'auto';
        const found = element.contains(document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2));
        element.style.pointerEvents = '';
        return found;
    });
    expect(inFront).toBe(true);
});

test('modifies a rule, showing the transactions it matches', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await rule(page, 'LOYER').click();

    const modal = page.getByRole('dialog', { name: tr('editRule') });
    await expect(modal.locator('.rule-count')).toContainText(tr('ruleCount', { count: 3, categorized: 3 }));
    await expect(modal.getByLabel(tr('ruleNotifyLabel'))).toBeChecked();
    // Push or email, as the user chose: the option does not say push only
    await expect(modal.getByLabel('Envoyer une notification', { exact: true })).toBeVisible();

    await modal.getByLabel(tr('ruleNotifyLabel')).uncheck();
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal).toHaveCount(0);
    await expect(rule(page, 'LOYER').locator('.rule-notify')).toHaveCount(0);
    await expect(rule(page, 'LOYER')).not.toHaveClass(/rule-chip-notify/);
});

test('deletes a rule', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await rule(page, 'SALAIRE').click();

    await page.getByRole('dialog').getByRole('button', { name: tr('deleteRule') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('ruleDeleted'));
    await expect(rule(page, 'SALAIRE')).toHaveCount(0);
    await expect(page.getByRole('heading', { name: tr('rulesCount', { count: 3 }) })).toBeVisible();
});

test('applies all the rules to the whole history', async ({ adminPage: page }) => {
    await openTab(page, tr('tabRules'));
    await applyRules(page, 'applyRulesHelp');

    await expect(page.locator('.toast')).toHaveText(tr('rulesApplied', { count: 0 }));
});

test('a category chosen by hand is never overwritten by the rules (#341)', async ({ adminPage: page, api }) => {
    // Uncategorized, put by hand in Restaurant
    const transaction = page.locator('.transaction-item').filter({ hasText: 'CB BOULANGERIE PAUL' });
    await pickCategory(transaction, 'Restaurant');
    await expect(transaction.locator('.category-chip')).toHaveText('Restaurant');

    // A rule that matches it then leaves it there, as the resynchronization does
    const created = await (await api.post('/api/category/keyword', { data: { keyword: 'BOULANGERIE', category: 1 } })).json();
    expect(created.applied).toBe(0);
    await openTab(page, tr('tabRules'));
    await applyRules(page, 'applyRulesHelp');
    await expect(page.locator('.toast')).toHaveText(tr('rulesApplied', { count: 0 }));

    const { month, year } = currentMonth();
    const transactions = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    const bakery = transactions.find(t => t.label === 'CB BOULANGERIE PAUL');
    expect([Number(bakery.category), bakery.category_source]).toEqual([3, 'manual']);
});

test('keeps the existing categories: the rules only categorize the uncategorized transactions', async ({ adminPage: page, api }) => {
    await openTab(page, tr('tabRules'));
    const toggle = page.getByRole('switch', { name: tr('rulesKeepCategorized') });
    await expect(toggle).not.toBeChecked();
    await expect(page.getByText(tr('rulesKeepCategorizedOffHelp'))).toBeVisible();

    await page.locator('.rules-switch-row label.toggle-switch').click();
    await expect(page.locator('.toast')).toHaveText(tr('rulesKeepCategorizedOn'));
    await expect(toggle).toBeChecked();
    await expect(page.getByText(tr('rulesKeepCategorizedOnHelp'))).toBeVisible();

    // "CB" is in every card label: only "CB BOULANGERIE PAUL", uncategorized, takes the rule
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('addRuleTitle') });
    await expect(modal.getByText(tr('ruleAppliesToUncategorized'))).toBeVisible();
    await modal.getByLabel(new RegExp(tr('ruleKeyword'))).fill('CB');
    await pickCategory(modal, 'Alimentation');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('ruleSavedApplied', { count: 1 }));

    const { month, year } = currentMonth();
    const transactions = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    const category = label => Number(transactions.find(t => t.label === label).category);
    expect([category('CB BOULANGERIE PAUL'), category('CB SUPERMARCHE CASINO'), category('CB RESTAURANT LE PETIT ZINC')]).toEqual([1, 2, 3]);

    await applyRules(page, 'applyRulesHelpUncategorized');
    await expect(page.locator('.toast')).toHaveText(tr('rulesAppliedUncategorized', { count: 0 }));

    // Kept after a reload
    await page.reload();
    await openTab(page, tr('tabRules'));
    await expect(page.getByRole('switch', { name: tr('rulesKeepCategorized') })).toBeChecked();
});

test('a refused change of the switch shows it again as it was', async ({ adminPage: page }) => {
    await page.route('**/api/category/keyword/settings', route => (route.request().method() === 'PUT'
        ? route.fulfill({ status: 403, json: { error: 'Forbidden - Administrator only' } })
        : route.continue()));
    await openTab(page, tr('tabRules'));
    const toggle = page.getByRole('switch', { name: tr('rulesKeepCategorized') });
    await expect(toggle).not.toBeChecked();

    await page.locator('.rules-switch-row label.toggle-switch').click();
    // An answer without error_code (older server): its English message, as is (#366)
    await expect(page.locator('.toast')).toHaveText('Forbidden - Administrator only');
    // Back to its position, not left where it was clicked (#342)
    await expect(toggle).not.toBeChecked();
    await expect(page.getByText(tr('rulesKeepCategorizedOffHelp'))).toBeVisible();
});

test('two rules with the same keyword, one with an amount: PAYPAL of 10 € goes to Spotify', async ({ adminPage: page, api }) => {
    // Two PayPal payments of the month, uncategorized, and a Spotify category
    const now = new Date();
    const date = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    for (const [label, amount] of [['PAYPAL SPOTIFY', -10], ['PAYPAL EBAY', -42.5]]) {
        expect((await api.post('/api/transaction', { data: { account: 1, date, label, amount } })).ok()).toBe(true);
    }
    expect((await api.post('/api/category', { data: { name: 'Spotify', type: 'DEBIT' } })).ok()).toBe(true);
    await openTab(page, tr('tabRules'));

    // PAYPAL, any amount: Alimentation
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();
    let modal = page.getByRole('dialog', { name: tr('addRuleTitle') });
    await modal.getByLabel(new RegExp(tr('ruleKeyword'))).fill('PAYPAL');
    await expect(modal.getByLabel(tr('ruleAmount'))).toHaveAccessibleDescription(tr('ruleAmountHelp'));
    await pickCategory(modal, 'Alimentation');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('ruleSavedApplied', { count: 2 }));

    // PAYPAL of 10 €: Spotify, it wins over the rule without amount
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();
    modal = page.getByRole('dialog', { name: tr('addRuleTitle') });
    await modal.getByLabel(new RegExp(tr('ruleKeyword'))).fill('PAYPAL');
    await modal.getByLabel(tr('ruleAmount')).fill('10');
    await pickCategory(modal, 'Spotify');
    expect(await contrastProblems(page, tr('addRuleTitle'))).toEqual([]);
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('ruleSavedApplied', { count: 1 }));

    // Listed with its amount, readable at rest and on hover
    const withAmount = page.getByRole('button', { name: `${tr('editRule')} PAYPAL · 10,00 €`, exact: true });
    await expect(withAmount).toHaveText(/PAYPAL\s*· 10,00 €/);
    await expect(page.getByRole('button', { name: `${tr('editRule')} PAYPAL`, exact: true })).toBeVisible();
    expect(await contrastProblems(page, tr('tabRules'))).toEqual([]);

    const { month, year } = currentMonth();
    const transactions = await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json();
    const spotify = (await (await api.get('/api/category')).json()).find(c => c.name === 'Spotify');
    const category = label => Number(transactions.find(t => t.label === label).category);
    expect([category('PAYPAL SPOTIFY'), category('PAYPAL EBAY')]).toEqual([Number(spotify.id), 1]);

    // The same keyword and amount again: refused, with its message
    await page.getByRole('button', { name: tr('addRuleTitle') }).click();
    modal = page.getByRole('dialog', { name: tr('addRuleTitle') });
    await modal.getByLabel(new RegExp(tr('ruleKeyword'))).fill('paypal');
    await modal.getByLabel(tr('ruleAmount')).fill('10,00');
    await pickCategory(modal, 'Restaurant');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('apiErrors.rule_amount_exists', { amount: '10' }));
    await modal.getByRole('button', { name: tr('cancel') }).click();

    // Opened again: its amount in the field, the transactions it matches
    await withAmount.click();
    modal = page.getByRole('dialog', { name: tr('editRule') });
    await expect(modal.getByLabel(tr('ruleAmount'))).toHaveValue('10.00');
    await expect(modal.locator('.rule-count')).toContainText(tr('ruleCount', { count: 1, categorized: 1 }));
});
