/**
 * Categories Module
 *
 * Categories of the household: list (with sub-categories), create, modify and
 * delete. Icons and colors are those of the iOS app (SF Symbols, SwiftUI colors).
 *
 * @module categoriesModule
 */

import { CATEGORY_ICONS, CATEGORY_COLORS } from '../utils/categoryIcons.js';

/**
 * Empty category form
 * @returns {Object}
 */
function emptyForm() {
    return { id: null, name: '', parent_category: 0, type: 'DEBIT', icon: 'cart', color: 'blue' };
}

/**
 * Creates a categories module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with categories data and methods
 */
export function createCategoriesModule(getApiService) {
    return {
        data() {
            return {
                categories: [],
                loadingCategories: false,
                categoryForm: emptyForm(),
                showCategoryModal: false,
                savingCategory: false,
                categoryIcons: CATEGORY_ICONS,
                categoryColors: CATEGORY_COLORS
            };
        },

        computed: {
            /**
             * Top-level categories, each with its sub-categories, sorted by name
             * @returns {Array<{category: Object, children: Array}>}
             */
            categoryTree() {
                const byName = (a, b) => a.name.localeCompare(b.name, this.intlLocale);
                return this.categories
                    .filter(c => Number(c.parent_category) === 0 || Number(c.id) === 0)
                    .sort((a, b) => Number(a.id) === 0 ? -1 : Number(b.id) === 0 ? 1 : byName(a, b))
                    .map(category => ({
                        category,
                        // The default category (0) is its own parent: it has no sub-category
                        children: Number(category.id) === 0 ? [] : this.categories
                            .filter(c => Number(c.parent_category) === Number(category.id) && Number(c.id) !== Number(category.id))
                            .sort(byName)
                    }));
            },

            /**
             * Categories grouped by type (expenses, income, off-budget): each top-level
             * category of the type with its sub-categories
             * @returns {Array<{type: string, count: number, nodes: Array}>}
             */
            categoryGroups() {
                return ['DEBIT', 'CREDIT', 'HORS-BUDGET']
                    .map(type => {
                        const nodes = this.categoryTree.filter(node => node.category.type === type);
                        const count = nodes.reduce((sum, node) => sum + 1 + node.children.length, 0);
                        return { type, count, nodes };
                    })
                    .filter(group => group.nodes.length);
            },

            /**
             * Possible parents: top-level categories (except the default one and the edited one)
             * @returns {Array}
             */
            parentOptions() {
                return this.categoryTree
                    .map(node => node.category)
                    .filter(c => Number(c.id) !== 0 && Number(c.id) !== Number(this.categoryForm.id));
            }
        },

        methods: {
            /**
             * Loads the categories
             * @returns {Promise<void>}
             */
            async loadCategories() {
                this.loadingCategories = true;
                try {
                    this.categories = await getApiService().fetchCategories();
                    // Shared with the rules and the category pickers
                    this.ruleCategories = this.categories;
                } catch (error) {
                    this.error = this.apiErrorText(error);
                } finally {
                    this.loadingCategories = false;
                }
            },

            /**
             * Opens the category modal to add a category, or to modify the given one
             * @param {Object|null} category - Category to modify
             */
            openCategoryModal(category = null) {
                this.categoryForm = !category ? emptyForm() : {
                    id: Number(category.id),
                    name: category.name,
                    parent_category: Number(category.parent_category) || 0,
                    type: category.type,
                    icon: category.icon || '',
                    color: category.color || ''
                };
                this.showCategoryModal = true;
                this.$nextTick(() => document.getElementById('category-name')?.focus());
            },

            /**
             * Opens the category modal to add a sub-category of the given top-level
             * category: its parent and its type are already chosen
             * @param {Object} parent - Top-level category
             */
            openSubcategoryModal(parent) {
                this.openCategoryModal();
                this.categoryForm.parent_category = Number(parent.id);
                this.categoryForm.type = parent.type;
            },

            /**
             * Creates or modifies the category of the form
             * @returns {Promise<void>}
             */
            async saveCategory() {
                const form = this.categoryForm;
                if (!form.name.trim()) {
                    this.showToast(this.t('categoryNameRequired'));
                    return;
                }
                this.savingCategory = true;
                try {
                    const fields = { name: form.name.trim(), type: form.type, parent_category: Number(form.parent_category), icon: form.icon, color: form.color };
                    if (form.id === null) {
                        await getApiService().createCategory(fields);
                        this.showToast(this.t('categoryAdded'));
                    } else {
                        await getApiService().updateCategory({ id: form.id, ...fields });
                        this.showToast(this.t('categoryUpdated'));
                    }
                    this.showCategoryModal = false;
                    await this.loadCategories();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.savingCategory = false;
                }
            },

            /**
             * Deletes a category after confirmation
             * @param {Object} category - Category
             * @returns {Promise<void>}
             */
            async removeCategory(category) {
                if (!confirm(this.t('confirmDeleteCategory', { name: category.name }))) {
                    return;
                }
                try {
                    await getApiService().deleteCategory(category.id);
                    this.showToast(this.t('categoryDeleted'));
                    await this.loadCategories();
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            }
        }
    };
}
