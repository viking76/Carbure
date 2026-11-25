// Order of the category types in the category picker (portal/components/CategoryPicker.js)
import './setup.mjs';
import { test } from 'node:test';
import assert from 'node:assert/strict';
import CategoryPicker, { typeOrder } from '../../portal/components/CategoryPicker.js';

test('typeOrder: the direction of the transaction first, then off-budget, then the other direction (#498)', () => {
    assert.deepEqual(typeOrder(-6.8), ['DEBIT', 'HORS-BUDGET', 'CREDIT']);
    assert.deepEqual(typeOrder('3200.00'), ['CREDIT', 'HORS-BUDGET', 'DEBIT']);
    // Unknown direction: the usual order
    for (const amount of [null, '', 0, 'abc']) {
        assert.deepEqual(typeOrder(amount), ['DEBIT', 'CREDIT', 'HORS-BUDGET']);
    }
});

test('the picker groups the categories in the order of the amount (#498)', () => {
    const categories = [
        { id: 1, name: 'Alimentation', parent_category: 0, type: 'DEBIT' },
        { id: 5, name: 'Salaire', parent_category: 0, type: 'CREDIT' },
        { id: 6, name: 'Épargne', parent_category: 0, type: 'HORS-BUDGET' },
    ];
    const groups = amount => CategoryPicker.computed.groups.call({
        categories, search: '', withDefault: false, amount,
        byId: new Map(categories.map(c => [c.id, c])),
    }).map(group => group.type);
    assert.deepEqual(groups(1500), ['CREDIT', 'HORS-BUDGET', 'DEBIT']);
    assert.deepEqual(groups(null), ['DEBIT', 'CREDIT', 'HORS-BUDGET']);
});
