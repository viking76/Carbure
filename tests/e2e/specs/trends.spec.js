// Trends tab: key figures, period, comparison, charts and table
const { test, expect, tr, openTab, euros, pickCategory } = require('../fixtures');

test('shows the trends of the last 12 months', async ({ userPage: page }) => {
    await openTab(page, tr('tabTrends'));

    await expect(page.getByRole('heading', { name: tr('trendsTitle', { months: 12 }) })).toBeVisible();
    // 500 put aside on the Livret A (sub-category of Épargne) this month
    const savings = page.locator('.trends-stat').filter({ hasText: tr('trendsTotalSavings') });
    await expect(savings.locator('.summary-value')).toHaveText(euros(500));
    await expect(page.getByRole('img', { name: tr('trendsFlowsTitle') })).toBeVisible();
    await expect(page.getByRole('img', { name: tr('trendsSavingsTitle') })).toBeVisible();

    await page.getByRole('button', { name: tr('trendsShowTable') }).click();
    await expect(page.locator('.trends-table tbody tr')).toHaveCount(12);
});

test('changes the period and compares it', async ({ userPage: page }) => {
    await openTab(page, tr('tabTrends'));

    await page.getByRole('button', { name: tr('monthsShort', { months: 3 }) }).click();
    await expect(page.getByRole('heading', { name: tr('trendsTitle', { months: 3 }) })).toBeVisible();
    await expect(page.getByRole('button', { name: tr('monthsShort', { months: 3 }) })).toHaveAttribute('aria-pressed', 'true');

    await page.getByRole('combobox', { name: tr('compareWith') }).selectOption('previous');
    await expect(page.getByRole('heading', { name: tr('comparisonTitle') })).toBeVisible();
    await expect(page.locator('.comparison-row').first()).toBeVisible();

    await page.getByRole('button', { name: tr('trendsShowTable') }).click();
    await expect(page.locator('.trends-table tbody tr')).toHaveCount(3);
});

test('chooses a custom range of months, an invalid one is refused', async ({ userPage: page }) => {
    await openTab(page, tr('tabTrends'));

    await page.getByRole('button', { name: tr('trendsCustomRange') }).click();
    await expect(page.getByRole('button', { name: tr('trendsCustomRange') })).toHaveAttribute('aria-pressed', 'true');
    const now = new Date();
    const month = offset => {
        const date = new Date(now.getFullYear(), now.getMonth() - offset, 1);
        return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
    };
    // 36 months ending last month
    await page.getByLabel(tr('trendsFrom'), { exact: true }).fill(month(36));
    await page.getByLabel(tr('trendsTo'), { exact: true }).fill(month(1));
    await page.getByRole('button', { name: tr('trendsApplyRange') }).click();
    await expect(page.getByRole('heading', { name: tr('trendsTitle', { months: 36 }) })).toBeVisible();
    await page.getByRole('button', { name: tr('trendsShowTable') }).click();
    await expect(page.locator('.trends-table tbody tr')).toHaveCount(36);
    // Newest first: from last month back to 36 months ago
    const label = offset => new Intl.DateTimeFormat('fr', { month: 'short', year: '2-digit' })
        .format(new Date(now.getFullYear(), now.getMonth() - offset, 1));
    await expect(page.locator('.trends-table tbody tr').first()).toContainText(label(1));
    await expect(page.locator('.trends-table tbody tr').last()).toContainText(label(36));

    // The first month after the last one
    await page.getByLabel(tr('trendsFrom'), { exact: true }).fill(month(0));
    await page.getByRole('button', { name: tr('trendsApplyRange') }).click();
    await expect(page.locator('.toast')).toContainText(tr('trendsRangeInvalid', { months: 240 }));
    await expect(page.locator('.trends-table tbody tr')).toHaveCount(36);
});

test('an administrator adds a category chart and an insight chart, moves and removes them', async ({ adminPage: page }) => {
    await openTab(page, tr('tabTrends'));

    await page.getByRole('button', { name: tr('trendChartAdd') }).click();
    const dialog = page.getByRole('dialog', { name: tr('trendChartAdd') });
    await pickCategory(dialog, 'Alimentation');
    await dialog.getByRole('button', { name: tr('trendChartAddButton') }).click();
    await expect(page.locator('.toast')).toContainText(tr('trendChartAdded'));
    const food = page.getByRole('img', { name: 'Alimentation', exact: true });
    await expect(food).toBeVisible();
    // 82.40 + 45 this month: the hover tooltip of the last month
    const chart = page.locator('.trend-custom-chart', { has: food });
    // Scaled to the width of the card like the built-in charts (viewBox kept by the template)
    await expect(chart.locator('svg[role="img"]')).toHaveAttribute('viewBox', /^0 0 960 \d+$/);
    await chart.locator('.hit').last().hover();
    await expect(chart.locator('.trends-tooltip')).toContainText(euros(127.4));
    // Its budget is the one of its sub-categories (300 + 100)
    await expect(chart.locator('.trends-tooltip')).toContainText(euros(400));

    await page.getByRole('button', { name: tr('trendChartAdd') }).click();
    await dialog.getByRole('combobox', { name: tr('trendChartType') }).selectOption('insight');
    await dialog.getByRole('combobox', { name: tr('trendChartInsight') }).selectOption({ label: 'Dépenses' });
    await dialog.getByRole('button', { name: tr('trendChartAddButton') }).click();
    await expect(page.locator('.trend-custom-chart')).toHaveCount(2);
    await expect(page.locator('.trend-custom-chart').nth(1)).toContainText('Dépenses');

    // The same chart twice
    await page.getByRole('button', { name: tr('trendChartAdd') }).click();
    await pickCategory(dialog, 'Alimentation');
    await dialog.getByRole('button', { name: tr('trendChartAddButton') }).click();
    await expect(page.locator('.toast')).toContainText(tr('trendChartExists'));
    await dialog.getByRole('button', { name: tr('cancel') }).click();

    await page.getByRole('button', { name: `${tr('trendChartUp')} Dépenses` }).click();
    await expect(page.locator('.trend-custom-chart').first()).toContainText('Dépenses');
    await page.reload();
    await openTab(page, tr('tabTrends'));
    await expect(page.locator('.trend-custom-chart').first()).toContainText('Dépenses');

    await page.getByRole('button', { name: `${tr('trendChartRemove')} Alimentation` }).click();
    await expect(page.locator('.trend-custom-chart')).toHaveCount(1);
    await expect(food).toHaveCount(0);
});

test('the custom charts are shown to the members, without the administration buttons', async ({ userPage: page, api }) => {
    await api.put('/api/budget/trends/charts', { data: { charts: [{ type: 'category', id: 1 }] } });
    await openTab(page, tr('tabTrends'));
    await expect(page.getByRole('img', { name: 'Alimentation', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: tr('trendChartAdd') })).toHaveCount(0);
    await expect(page.getByRole('button', { name: `${tr('trendChartRemove')} Alimentation` })).toHaveCount(0);
});

test('compares the categories on one chart, as bars, lines or areas', async ({ userPage: page }) => {
    await openTab(page, tr('tabTrends'));
    const card = page.locator('.trend-categories-card');
    await expect(card.getByRole('heading', { name: tr('trendCategoriesTitle') })).toBeVisible();
    // Expenses of the top-level categories, largest first: rent 3 months, food 2 months
    const chips = card.getByRole('list', { name: tr('trendCategoriesLegend') }).getByRole('button');
    await expect(chips).toHaveText(['Logement', 'Alimentation']);
    await expect(card.getByRole('button', { name: tr('trendsKindBars') })).toHaveAttribute('aria-pressed', 'true');
    await expect(card.locator('.category-bar')).toHaveCount(5);

    await card.getByRole('button', { name: tr('trendsKindLines') }).click();
    await expect(card.getByRole('button', { name: tr('trendsKindLines') })).toHaveAttribute('aria-pressed', 'true');
    await expect(card.getByRole('button', { name: tr('trendsKindBars') })).toHaveAttribute('aria-pressed', 'false');
    await expect(card.locator('.category-line')).toHaveCount(2);
    await expect(card.locator('.category-bar')).toHaveCount(0);

    await card.getByRole('button', { name: tr('trendsKindAreas') }).click();
    await expect(card.locator('.category-area')).toHaveCount(2);
    await expect(card.locator('.category-line')).toHaveCount(0);

    // The tooltip of the current month: largest amount first, then the total
    await card.locator('.hit').last().hover();
    const tooltip = card.locator('.trends-tooltip');
    await expect(tooltip.locator('div').first()).toContainText('Logement');
    await expect(tooltip.locator('.total')).toContainText(euros(1077.40));

    // Sub-categories and income
    await card.getByRole('combobox', { name: tr('trendCategoriesLevel') }).selectOption('sub');
    await expect(chips).toHaveText(['Logement', 'Supermarché', 'Restaurant']);
    await card.getByRole('combobox', { name: tr('trendCategoriesType') }).selectOption('CREDIT');
    await expect(chips).toHaveText(['Salaire']);
});

test('filters the categories of the chart, the choices are kept', async ({ userPage: page, api }) => {
    await api.put('/api/budget/trends/charts', { data: { charts: [{ type: 'category', id: 1 }] } });
    await openTab(page, tr('tabTrends'));
    const card = page.locator('.trend-categories-card');
    const rent = card.getByRole('button', { name: 'Logement' });

    await rent.click();
    await expect(rent).toHaveAttribute('aria-pressed', 'false');
    // Alimentation only: this month and the previous one
    await expect(card.locator('.category-bar')).toHaveCount(2);

    await card.getByRole('button', { name: tr('trendCategoriesNone') }).click();
    await expect(card.getByText(tr('trendCategoriesNoneShown'))).toBeVisible();
    await expect(card.getByRole('img', { name: tr('trendCategoriesTitle') })).toHaveCount(0);
    await expect(card.getByRole('button', { name: tr('trendCategoriesNone') })).toBeDisabled();

    await card.getByRole('button', { name: tr('trendCategoriesAll') }).click();
    await expect(card.locator('.category-bar')).toHaveCount(5);
    await rent.click();
    await card.getByRole('button', { name: tr('trendsKindLines') }).click();
    // The kind applies to the custom charts too
    await expect(page.locator('.trend-custom-chart .series-line')).toHaveCount(1);
    await expect(page.locator('.trend-custom-chart .series-bar')).toHaveCount(0);

    await page.reload();
    await openTab(page, tr('tabTrends'));
    await expect(card.getByRole('button', { name: tr('trendsKindLines') })).toHaveAttribute('aria-pressed', 'true');
    await expect(rent).toHaveAttribute('aria-pressed', 'false');
    await expect(card.locator('.category-line')).toHaveCount(1);
});
