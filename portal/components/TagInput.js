/**
 * TagInput Component
 *
 * Text field choosing a tag by typing it: the tags matching the text are listed (the
 * matching part highlighted), the arrow keys move in the list, Enter or a click chooses
 * at once. A name matching no tag is offered to be created. The field is emptied after
 * each choice, to add another tag; with `keep`, it shows the tag chosen instead (applied
 * by a button of the parent), and changing the text drops the choice (`clear`).
 *
 * @component
 * @example
 * <tag-input :tags="tagsToOffer(transaction)" :all-tags="tags" :t="t" :color-for="categoryColor"
 *   input-id="detail-tag" @select="id => addDetailTag(transaction, id)"
 *   @create="name => createDetailTag(transaction, name)"></tag-input>
 */

import { tagName } from '../modules/tagsModule.js';

/** Most tags listed at once */
const MAX_OPTIONS = 50;

/** Valid name of a tag, once in lower case (Tag::NAME_PATTERN) */
const NAME_PATTERN = /^[\p{Ll}\p{Lo}\p{N}_-]{1,40}$/u;

/** Number of fields created, for unique DOM ids */
let instances = 0;

/**
 * Tags whose name contains the text typed: those starting with it first, then by name
 * @param {Array<{id: number, name: string}>} tags - Tags offered
 * @param {string} query - Text typed (with or without its #)
 * @returns {Array<Object>}
 */
export function matchingTags(tags, query) {
    const search = tagName(query);
    const byName = (a, b) => a.name.localeCompare(b.name);
    if (!search) {
        return [...tags].sort(byName);
    }
    const found = tags.filter(t => t.name.toLowerCase().includes(search));
    const starts = found.filter(t => t.name.toLowerCase().startsWith(search)).sort(byName);
    return [...starts, ...found.filter(t => !starts.includes(t)).sort(byName)];
}

/**
 * Name of the tag to create for the text typed: none when it is not a valid name or
 * already the name of a tag (an archived one included: names are unique)
 * @param {Array<{name: string}>} allTags - Every tag
 * @param {string} query - Text typed
 * @returns {string|null}
 */
export function tagToCreate(allTags, query) {
    const name = tagName(query);
    if (!NAME_PATTERN.test(name) || allTags.some(t => t.name.toLowerCase() === name)) {
        return null;
    }
    return name;
}

export default {
    name: 'TagInput',

    props: {
        /** Tags that can be chosen: {id, name, color} */
        tags: { type: Array, required: true },
        /** Every tag (archived included), to tell whether a name is free */
        allTags: { type: Array, default: () => [] },
        /** Translation function of the app */
        t: { type: Function, required: true },
        /** CSS color of a tag */
        colorFor: { type: Function, required: true },
        /** DOM id of the field (for its label) */
        inputId: { type: String, default: null },
        /** Offer to create a tag from a name matching none */
        creatable: { type: Boolean, default: true },
        /** Field disabled */
        disabled: { type: Boolean, default: false },
        /** Keep the tag chosen in the field (it is applied by the parent) instead of emptying it */
        keep: { type: Boolean, default: false }
    },

    emits: ['select', 'create', 'clear'],

    data() {
        return { query: '', open: false, active: -1, uid: ++instances, picked: null, settingQuery: false };
    },

    computed: {
        /** Tags matching the text typed */
        matches() {
            return matchingTags(this.tags, this.query).slice(0, MAX_OPTIONS);
        },

        /** Name of the tag offered to be created, or null */
        newName() {
            return this.creatable ? tagToCreate(this.allTags.length ? this.allTags : this.tags, this.query) : null;
        },

        /**
         * Options of the list in display order: the tags, then the creation
         * @returns {Array<{key: string, tag: Object|null, create: string|null}>}
         */
        options() {
            const options = this.matches.map(tag => ({ key: `tag-${tag.id}`, tag, create: null }));
            if (this.newName) {
                options.push({ key: 'create', tag: null, create: this.newName });
            }
            return options;
        },

        /** Id of the list */
        listId() {
            return `tag-input-list-${this.uid}`;
        },

        /** Id of the option highlighted with the keyboard (aria-activedescendant) */
        activeId() {
            const option = this.open ? this.options[this.active] : null;
            return option ? this.optionId(option) : null;
        },

        /** Whether the list is shown */
        expanded() {
            return this.open && (this.options.length > 0 || this.query.trim() !== '');
        }
    },

    watch: {
        /** While typing, the first option is ready to be chosen with Enter */
        query(value) {
            if (this.settingQuery) {
                this.settingQuery = false;
                return;
            }
            if (this.picked !== null && value !== `#${this.picked}`) {
                this.picked = null;
                this.$emit('clear');
            }
            this.open = true;
            this.active = value.trim() && this.options.length ? 0 : -1;
        }
    },

    mounted() {
        this.onDocumentClick = event => {
            if (this.open && !this.$el.contains(event.target)) {
                this.open = false;
            }
        };
        document.addEventListener('click', this.onDocumentClick);
    },

    beforeUnmount() {
        document.removeEventListener('click', this.onDocumentClick);
    },

    methods: {
        /**
         * DOM id of an option
         * @param {{key: string}} option - Option
         * @returns {string}
         */
        optionId(option) {
            return `tag-input-${this.uid}-${option.key}`;
        },

        /**
         * Moves the keyboard highlight in the list (opens it first)
         * @param {number} step - 1 (down) or -1 (up)
         */
        move(step) {
            if (!this.open) {
                this.open = true;
            }
            const count = this.options.length;
            if (!count) {
                return;
            }
            this.active = this.active < 0 ? (step > 0 ? 0 : count - 1) : (this.active + step + count) % count;
            this.$nextTick(() => document.getElementById(this.activeId)?.scrollIntoView({ block: 'nearest' }));
        },

        /** Chooses the highlighted option (Enter) */
        chooseActive() {
            const option = this.options[this.active];
            if (option) {
                this.choose(option);
            }
        },

        /**
         * Chooses a tag, or the creation of one, then empties the field for the next one
         * (with `keep`, shows the tag chosen)
         * @param {{tag: Object|null, create: string|null}} option - Option
         */
        choose(option) {
            if (option.tag) {
                this.$emit('select', Number(option.tag.id));
            } else if (option.create) {
                this.$emit('create', option.create);
            }
            const name = option.tag ? option.tag.name : option.create;
            const query = this.keep ? `#${name}` : '';
            this.picked = this.keep ? name : null;
            this.settingQuery = this.query !== query;
            this.query = query;
            this.$nextTick(() => {
                this.open = false;
                this.active = -1;
                this.$refs.input?.focus();
            });
        },

        /**
         * Name split around the text typed, to highlight it
         * @param {string} name - Tag name
         * @returns {Array<{text: string, match: boolean}>}
         */
        parts(name) {
            const search = tagName(this.query);
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
         * Closes the list (Escape); when it is closed, Escape is left to the modal
         * @param {KeyboardEvent} event - Escape key
         */
        close(event) {
            if (this.expanded) {
                event?.stopPropagation();
                this.open = false;
                this.active = -1;
            }
        }
    },

    template: `
        <div class="tag-input" :class="{ open: expanded }" @keydown.esc="close">
            <span class="material-icons tag-input-icon" aria-hidden="true">sell</span>
            <input ref="input" :id="inputId" type="text" v-model="query" :disabled="disabled"
                   :placeholder="t('tagType')" role="combobox" aria-autocomplete="list"
                   :aria-expanded="expanded ? 'true' : 'false'" :aria-controls="listId"
                   :aria-activedescendant="activeId" autocomplete="off" spellcheck="false" autocapitalize="off"
                   @focus="open = true" @click="open = true" @keydown.tab="open = false"
                   @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)" @keydown.enter.prevent="chooseActive" />
            <div v-show="expanded" class="tag-input-list" role="listbox" :id="listId" :aria-label="t('tagsOfTransaction')">
                <div v-for="(option, index) in options" :key="option.key" role="option" :id="optionId(option)"
                     class="tag-input-option" :class="{ active: index === active, create: option.create }" aria-selected="false"
                     @mousedown.prevent @click="choose(option)" @mousemove="active = index">
                    <template v-if="option.tag">
                        <span class="tag-dot" :style="{ background: colorFor(option.tag) }" aria-hidden="true"></span>
                        <span class="tag-input-name">#<template v-for="(part, i) in parts(option.tag.name)" :key="i"><mark v-if="part.match">{{ part.text }}</mark><template v-else>{{ part.text }}</template></template></span>
                    </template>
                    <template v-else>
                        <span class="material-icons" aria-hidden="true">add</span>
                        <span class="tag-input-name">{{ t('tagCreateNamed', { name: option.create }) }}</span>
                    </template>
                </div>
                <div v-if="!options.length" class="tag-input-empty">{{ t('tagNoMatch') }}</div>
            </div>
        </div>
    `
};
