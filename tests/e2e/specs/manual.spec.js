// Manual accounts (Accounts tab) and transactions entered by hand on any account (Transactions tab)
const { test, expect, tr, openTab, pickCategory, euros, currentMonth } = require('../fixtures');

/** Row of a transaction, by its label */
const row = (page, label) => page.locator('.transaction-item').filter({ hasText: label });

test('adds a manual account, then enters and changes a cash expense', async ({ adminPage: page, api }) => {
    await openTab(page, tr('tabAccounts'));
    await page.getByRole('button', { name: tr('manualAccountAdd') }).click();
    const accountModal = page.getByRole('dialog', { name: tr('manualAccountAdd') });
    await accountModal.getByLabel(tr('accountLabel')).fill('Espèces');
    await accountModal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('manualAccountAdded'));
    const manual = page.locator('.manual-accounts .account-item');
    await expect(manual).toHaveCount(1);
    await expect(manual.locator('.account-name')).toHaveText('Espèces');
    // Not synchronized: no synchronization button
    await expect(manual.getByRole('button', { name: tr('syncAccount') })).toHaveCount(0);

    await openTab(page, tr('tabTransactions'));
    await page.locator('.transactions-toolbar').getByRole('button', { name: tr('manualTransactionTitle') }).click();
    const modal = page.getByRole('dialog', { name: tr('manualTransactionTitle') });
    await expect(modal.getByLabel(tr('manualAccount'))).toHaveValue(/\d+/);
    await modal.getByLabel(tr('manualAmount', { currency: '€' })).fill('12.50');
    await modal.getByLabel(tr('manualLabel')).fill('Marché du dimanche');
    await pickCategory(modal, 'Alimentation');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(modal).toHaveCount(0);
    await expect(page.locator('.toast')).toHaveText(tr('manualTransactionAdded'));

    const added = row(page, 'MARCHÉ DU DIMANCHE');
    await expect(added.locator('.transaction-amount')).toHaveText(euros(-12.5));
    await expect(added.locator('.category-chip')).toHaveText('Alimentation');

    // Changed from its detail (once the message is gone: it covers the buttons of the modal)
    await expect(page.locator('.toast')).toHaveCount(0);
    await added.getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    // The edit button: the pencil only (named by its label), the size of the trash next to it
    const manualDetail = page.getByRole('dialog', { name: tr('transactionDetail') });
    const editButton = manualDetail.getByRole('button', { name: tr('manualTransactionEdit') });
    await expect(editButton).toHaveAttribute('title', tr('manualTransactionEdit'));
    expect((await editButton.innerText()).replace('edit', '').trim()).toBe('');
    const editBox = await editButton.boundingBox();
    const deleteBox = await manualDetail.getByRole('button', { name: tr('deleteTransaction') }).boundingBox();
    expect([Math.round(editBox.width), Math.round(editBox.height)]).toEqual([Math.round(deleteBox.width), Math.round(deleteBox.height)]);
    await editButton.click();
    const edit = page.getByRole('dialog', { name: tr('manualTransactionEditTitle') });
    await expect(edit.getByLabel(tr('manualAmount', { currency: '€' }))).toHaveValue('12.50');
    await edit.getByLabel(tr('manualAmount', { currency: '€' })).fill('15');
    await edit.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));
    await expect(added.locator('.transaction-amount')).toHaveText(euros(-15));

    const { month, year } = currentMonth();
    const saved = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json())
        .find(t => t.label === 'MARCHÉ DU DIMANCHE');
    expect([saved.bank_name, Number(saved.amount), Number(saved.pointed)]).toEqual(['manual', -15, 1]);

    // A transaction of a bank is not changed
    await row(page, 'PRLV LOYER AGENCE').getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    const detail = page.getByRole('dialog', { name: tr('transactionDetail') });
    await expect(detail.getByRole('button', { name: tr('splitButton') })).toBeVisible();
    await expect(detail.getByRole('button', { name: tr('manualTransactionEdit') })).toHaveCount(0);
});

test('a user enters an income by hand on the synchronized account, then changes it', async ({ userPage: page, api }) => {
    // No manual account: the form is on the account of the bank, with the risk of a duplicate
    await page.locator('.transactions-toolbar').getByRole('button', { name: tr('manualTransactionTitle') }).click();
    let modal = page.getByRole('dialog', { name: tr('manualTransactionTitle') });
    const account = modal.getByLabel(tr('manualAccount'));
    // Synchronized accounts in the order of GET /bank, the first one chosen
    await expect(account.locator('option')).toHaveText(['BANK ···fail', 'BNP ···0001']);
    await expect(account.locator('option:checked')).toHaveText('BANK ···fail');
    await account.selectOption({ label: 'BNP ···0001' });
    await expect(account).toHaveAccessibleDescription(tr('manualSyncedHelp'));
    await modal.getByLabel(tr('manualIncome')).check();
    await modal.getByLabel(tr('manualAmount', { currency: '€' })).fill('30');
    await modal.getByLabel(tr('manualLabel')).fill('Vente vide-grenier');
    await modal.getByLabel(tr('manualLabel')).press('Enter');
    await expect(page.locator('.toast')).toHaveText(tr('manualTransactionAdded'));
    const added = row(page, 'VENTE VIDE-GRENIER');
    await expect(added.locator('.transaction-amount')).toHaveText(euros(30));

    // On the synchronized account, still told apart as entered by hand
    const { month, year } = currentMonth();
    const saved = (await (await api.get(`/api/transaction?month=${month}&year=${year}`)).json())
        .find(t => t.label === 'VENTE VIDE-GRENIER');
    expect([saved.bank_name, saved.account_number, saved.uuid.startsWith('manual')]).toEqual(['bnp', 'DEMO0000001', true]);

    // So it can be changed from its detail
    await expect(page.locator('.toast')).toHaveCount(0);
    await added.getByRole('button', { name: new RegExp(tr('transactionDetail')) }).click();
    await page.getByRole('dialog', { name: tr('transactionDetail') }).getByRole('button', { name: tr('manualTransactionEdit') }).click();
    modal = page.getByRole('dialog', { name: tr('manualTransactionEditTitle') });
    await modal.getByLabel(tr('manualAmount', { currency: '€' })).fill('35');
    await modal.getByRole('button', { name: tr('save') }).click();
    await expect(page.locator('.toast')).toHaveText(tr('transactionSaved'));
    await expect(added.locator('.transaction-amount')).toHaveText(euros(35));
});
