/**
 * CategoryPicker Component
 *
 * Category selector showing each category with its icon, color, parent and
 * type, grouped by type (expenses, income, off-budget), with a search field: the
 * matching text is highlighted, the arrow keys move in the list and Enter chooses.
 * Given the amount of the transaction, the categories of its direction come first
 * (income for a credit, expenses for a debit), then the off-budget ones (transfers),
 * then the others (a refund is a credit on an expense category).
 *
 * @component
 * @example
 * <category-picker v-model="rule.category" :categories="ruleCategories" :t="t"
 *   :icon-for="categoryIcon" :color-for="categoryColor"></category-picker>
 */

import { badgeStyle } from '../utils/categoryIcons.js';

/** Display order of the category types */
const TYPES = ['DEBIT', 'CREDIT', 'HORS-BUDGET'];

/**
 * Display order of the category types for the amount of a transaction: its direction
 * first, then the off-budget categories, then the other direction
 * @param {number|string|null} amount - Amount of the transaction (null: unknown)
 * @returns {string[]}
 */
export function typeOrder(amount) {
    const value = Number(amount);
    if (amount === null || amount === '' || !Number.isFinite(value) || value === 0) {
        return TYPES;
    }
    return value > 0 ? ['CREDIT', 'HORS-BUDGET', 'DEBIT'] : ['DEBIT', 'HORS-BUDGET', 'CREDIT'];
}

/** Number of pickers created, for unique DOM ids */
let instances = 0;

export default {
    name: 'CategoryPicker',

    props: {
        /** Category id (number), or '' when none is chosen */
        modelValue: { type: [Number, String], default: '' },
        /** All the categories: {id, name, parent_category, type, icon, color} */
        categories: { type: Array, required: true },
        /** Translation function of the app */
        t: { type: Function, required: true },
        /** Material icon of a category */
        iconFor: { type: Function, required: true },
        /** CSS color of a category */
        colorFor: { type: Function, required: true },
        /** Offer the default category "uncategorized" (0) */
        withDefault: { type: Boolean, default: false },
        /** Text of the button when no category is chosen (default: "Choose a category") */
        placeholder: { type: String, default: null },
        /**
         * Small button for a row of a list: the list floats over the page (it is not cut
         * by a scrolling container) and closes when the page scrolls
         */
        compact: { type: Boolean, default: false },
        /** Amount of the transaction: the categories of its direction come first (null: usual order) */
        amount: { type: [Number, String], default: null }
    },

    emits: ['update:modelValue'],

    data() {
        return { open: false, search: '', panelStyle: null, active: -1, uid: ++instances };
    },

    computed: {
        /** Categories by id */
        byId() {
            return new Map(this.categories.map(c => [Number(c.id), c]));
        },

        /** Chosen category */
        selected() {
            return this.modelValue === '' || this.modelValue === null ? null : this.byId.get(Number(this.modelValue)) || null;
        },

        /** Parent of the chosen category (null for a top-level category) */
        selectedParent() {
            const parent = this.selected ? Number(this.selected.parent_category) : 0;
            return parent !== 0 && parent !== Number(this.selected.id) ? this.byId.get(parent) || null : null;
        },

        /**
         * Categories matching the search, grouped by type: each parent followed by its sub-categories
         * @returns {Array<{type: string, items: Array<{category: Object, parent: Object|null}>}>}
         */
        groups() {
            const search = this.search.trim().toLowerCase();
            const byName = (a, b) => a.name.localeCompare(b.name);
            const isChild = c => Number(c.parent_category) !== 0 && Number(c.parent_category) !== Number(c.id);
            const usable = this.categories.filter(c => this.withDefault || Number(c.id) !== 0);
            const matches = (c, parent) => !search || c.name.toLowerCase().includes(search)
                || (parent && parent.name.toLowerCase().includes(search));

            return typeOrder(this.amount).map(type => {
                const items = [];
                for (const parent of usable.filter(c => !isChild(c) && c.type === type).sort(byName)) {
                    if (matches(parent, null)) {
                        items.push({ category: parent, parent: null });
                    }
                    usable.filter(c => isChild(c) && Number(c.parent_category) === Number(parent.id))
                        .sort(byName)
                        .filter(child => matches(child, parent))
                        .forEach(child => items.push({ category: child, parent }));
                }
                // Sub-categories whose type differs from their parent's
                usable.filter(c => isChild(c) && c.type === type && this.byId.get(Number(c.parent_category))?.type !== type)
                    .sort(byName)
                    .forEach(child => {
                        const parent = this.byId.get(Number(child.parent_category)) || null;
                        if (matches(child, parent)) {
                            items.push({ category: child, parent });
                        }
                    });
                return { type, items };
            }).filter(group => group.items.length);
        },

        /** Categories of the list in display order (keyboard navigation) */
        options() {
            return this.groups.flatMap(group => group.items.map(item => item.category));
        },

        /** Id of the option highlighted with the keyboard (aria-activedescendant) */
        activeId() {
            const category = this.options[this.active];
            return category ? this.optionId(category) : null;
        }
    },

    watch: {
        /** While searching, the first match is ready to be chosen with Enter */
        search(value) {
            this.active = value.trim() && this.options.length ? 0 : -1;
        }
    },

    mounted() {
        this.onDocumentClick = event => {
            if (this.open && !this.$el.contains(event.target)) {
                this.open = false;
            }
        };
        // Compact: the floating list would no longer be next to its button. A scroll that
        // does not move the button is ignored: the scroll event comes a frame later, so the
        // end of a scroll bringing the button into view would close the list just opened
        this.onViewportChange = event => {
            if (!this.open || (event.target instanceof Node && this.$el.contains(event.target))) {
                return;
            }
            if (event.type !== 'scroll' || this.buttonAnchor() !== this.anchor) {
                this.open = false;
            }
        };
        document.addEventListener('click', this.onDocumentClick);
        if (this.compact) {
            window.addEventListener('scroll', this.onViewportChange, true);
            window.addEventListener('resize', this.onViewportChange);
        }
    },

    beforeUnmount() {
        document.removeEventListener('click', this.onDocumentClick);
        window.removeEventListener('scroll', this.onViewportChange, true);
        window.removeEventListener('resize', this.onViewportChange);
    },

    methods: {
        /** Opens or closes the list */
        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.search = '';
                this.active = -1;
                this.panelStyle = this.compact ? this.floatingPosition() : null;
                this.anchor = this.buttonAnchor();
                this.$nextTick(() => this.$refs.search?.focus());
            }
        },

        /**
         * Position of the button in the window, to tell whether a scroll moved it
         * @returns {string} "top,left"
         */
        buttonAnchor() {
            const { top, left } = this.$refs.button.getBoundingClientRect();
            return `${top},${left}`;
        },

        /**
         * Position of the floating list of a compact picker: under its button (above when
         * there is no room below), at least 300 px wide, inside the window
         * @returns {Object} Style of the list
         */
        floatingPosition() {
            const button = this.$refs.button.getBoundingClientRect();
            const width = Math.min(Math.max(button.width, 300), window.innerWidth - 16);
            const left = Math.max(8, Math.min(button.right - width, window.innerWidth - width - 8));
            const below = window.innerHeight - button.bottom;
            const style = { position: 'fixed', left: `${left}px`, right: 'auto', width: `${width}px` };
            if (below < 340 && button.top > below) {
                style.top = 'auto';
                style.bottom = `${window.innerHeight - button.top + 4}px`;
            } else {
                style.top = `${button.bottom + 4}px`;
            }
            return style;
        },

        /**
         * Moves the keyboard highlight in the list
         * @param {number} step - 1 (down) or -1 (up)
         */
        move(step) {
            const count = this.options.length;
            if (!count) {
                return;
            }
            this.active = this.active < 0 ? (step > 0 ? 0 : count - 1) : (this.active + step + count) % count;
            this.$nextTick(() => document.getElementById(this.activeId)?.scrollIntoView({ block: 'nearest' }));
        },

        /** Chooses the highlighted category (Enter) */
        chooseActive() {
            const category = this.options[this.active];
            if (category) {
                this.choose(category);
            }
        },

        /** Empties the search and gives the focus back to the field */
        clearSearch() {
            this.search = '';
            this.$refs.search?.focus();
        },

        /**
         * Name split around the searched text, to highlight it
         * @param {string} name - Category name
         * @returns {Array<{text: string, match: boolean}>}
         */
        parts(name) {
            const search = this.search.trim().toLowerCase();
            const index = search ? name.toLowerCase().indexOf(search) : -1;
            if (index < 0) {
                return [{ text: name, match: false }];
            }
            return [
                { text: name.slice(0, index), match: false },
                { text: name.slice(index, index + search.length), match: true },
                { text: name.slice(index + search.length), match: false }
            ].filter(part => part.text);
        },

        /**
         * DOM id of an option
         * @param {Object} category - Category
         * @returns {string}
         */
        optionId(category) {
            return `category-picker-${this.uid}-${category.id}`;
        },

        /**
         * Chooses a category
         * @param {Object} category - Category
         */
        choose(category) {
            this.$emit('update:modelValue', Number(category.id));
            this.open = false;
            this.$refs.button?.focus();
        },

        /**
         * Style of the icon badge of a category
         * @param {Object} category - Category
         * @returns {Object}
         */
        badgeStyle(category) {
            return badgeStyle(this.colorFor(category));
        },

        /**
         * Closes the list (Escape); when it is closed, Escape is left to the modal
         * @param {KeyboardEvent} event - Escape key
         */
        close(event) {
            if (this.open) {
                event?.stopPropagation();
                this.open = false;
                this.$refs.button?.focus();
            }
        }
    },

    template: `
        <div class="category-picker" :class="{ open, compact }" @keydown.esc="close">
            <button ref="button" type="button" class="category-picker-button" @click="toggle"
                    aria-haspopup="listbox" :aria-expanded="open ? 'true' : 'false'">
                <!-- Named by its content (WCAG 2.5.3 Label in Name): the visible text, after a hidden prefix -->
                <span class="visually-hidden">{{ t('categoryLabel') }} : </span>
                <template v-if="selected">
                    <span class="category-badge" :style="badgeStyle(selected)">
                        <span class="material-icons" aria-hidden="true">{{ iconFor(selected) }}</span>
                    </span>
                    <span class="category-picker-name">
                        <small v-if="selectedParent">{{ selectedParent.name }} ›</small>
                        {{ selected.name }}
                    </span>
                    <span class="category-type-chip" :class="'type-' + selected.type.toLowerCase()">{{ t('categoryType_' + selected.type) }}</span>
                </template>
                <span v-else class="category-picker-placeholder">{{ placeholder || t('ruleCategoryPlaceholder') }}</span>
                <span class="material-icons category-picker-arrow" aria-hidden="true">expand_more</span>
            </button>
            <div v-if="open" class="category-picker-panel" :style="panelStyle">
                <div class="category-picker-search">
                    <span class="material-icons" aria-hidden="true">search</span>
                    <input ref="search" type="text" v-model="search" :placeholder="t('searchCategory')" :aria-label="t('searchCategory')"
                           role="combobox" aria-autocomplete="list" aria-expanded="true" :aria-controls="'category-picker-list-' + uid"
                           :aria-activedescendant="activeId" autocomplete="off" spellcheck="false"
                           @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="chooseActive" />
                    <button v-if="search" type="button" class="category-picker-clear" @click="clearSearch" :aria-label="t('clearSearch')" :title="t('clearSearch')">
                        <span class="material-icons" aria-hidden="true">close</span>
                    </button>
                </div>
                <div class="category-picker-list" role="listbox" :id="'category-picker-list-' + uid">
                    <div v-for="group in groups" :key="group.type" class="category-picker-group">
                        <div class="category-picker-group-title" :class="'type-' + group.type.toLowerCase()">{{ t('categoryTypes_' + group.type) }}</div>
                        <button v-for="item in group.items" :key="item.category.id" type="button" role="option" :id="optionId(item.category)"
                                class="category-picker-option" :class="{ child: item.parent, active: activeId === optionId(item.category), selected: selected && Number(selected.id) === Number(item.category.id) }"
                                :aria-selected="selected && Number(selected.id) === Number(item.category.id) ? 'true' : 'false'"
                                @click="choose(item.category)" @mousemove="active = options.indexOf(item.category)">
                            <span class="category-badge" :style="badgeStyle(item.category)">
                                <span class="material-icons" aria-hidden="true">{{ iconFor(item.category) }}</span>
                            </span>
                            <span class="category-picker-name">
                                <small v-if="item.parent">{{ item.parent.name }} ›</small> <template v-for="(part, i) in parts(item.category.name)" :key="i"><mark v-if="part.match">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template>
                            </span>
                            <span v-if="selected && Number(selected.id) === Number(item.category.id)" class="material-icons category-picker-check" aria-hidden="true">check</span>
                        </button>
                    </div>
                    <div v-if="!groups.length" class="category-picker-empty">
                        <span class="material-icons" aria-hidden="true">search_off</span>{{ t('noCategoryFound') }}
                    </div>
                </div>
            </div>
        </div>
    `
};
