// Purge of Administration > Purge: datasets purged with their dependents
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { purgeSelection } from '../../portal/modules/purgeModule.js';

const DATASETS = [
    { name: 'categories', count: 4, dependents: ['rules', 'budgets', 'transactions', 'transaction_tags'] },
    { name: 'rules', count: 3, dependents: [] },
    { name: 'budgets', count: 2, dependents: [] },
    { name: 'transactions', count: 5, dependents: ['transaction_tags'] },
    { name: 'deleted_transactions', count: 1, dependents: [] },
    { name: 'transaction_tags', count: 0, dependents: [] }
];

test('purgeSelection: the datasets ticked and the ones that refer to them', () => {
    assert.deepEqual([...purgeSelection(DATASETS, {})], []);
    assert.deepEqual([...purgeSelection(DATASETS, { deleted_transactions: true })], ['deleted_transactions']);
    assert.deepEqual([...purgeSelection(DATASETS, { transactions: true })].sort(), ['transaction_tags', 'transactions']);
    assert.deepEqual([...purgeSelection(DATASETS, { categories: true, rules: false })].sort(),
        ['budgets', 'categories', 'rules', 'transaction_tags', 'transactions']);
    // A dataset unticked, or missing its dependents list
    assert.deepEqual([...purgeSelection([{ name: 'tags', count: 1 }], { tags: true, transactions: false })], ['tags']);
});
