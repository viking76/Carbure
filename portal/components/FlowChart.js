/**
 * FlowChart Component
 *
 * Money flow of a month as a Sankey diagram (inline SVG): the income sources on
 * the left feed the month's income in the middle, which goes to the expense
 * categories, the savings and what is left on the right. The links take the
 * color of their category and fade towards the middle. A click on a category
 * (or on its link) selects it: the parent shows its transactions. A table gives
 * the same figures (accessibility).
 *
 * @component
 * @example
 * <flow-chart :flow="budgetFlow" :t="t" :color-for="categoryColor" :icon-for="categoryIcon"
 *             :format-amount="formatAmount" :currency-symbol="currencySymbol" :selected="flowSelection && flowSelection.key"
 *             @select="selectFlowNode"></flow-chart>
 */

/** Width of the drawing (it scales with the card) */
const WIDTH = 960;
/** Room for the labels on each side */
const LABEL = 220;
/** Width of a node */
const NODE = 16;
/** Width of the middle node */
const MIDDLE = 24;
/** Space between two nodes of a column */
const GAP = 12;
/** Height given to the amounts (the drawing grows with the spaces between nodes) */
const FLOW_HEIGHT = 340;
/** Minimum room of a node, for its two lines of label */
const SLOT = 40;
/** Room above the middle node for its label */
const TOP = 64;
/** Expense categories shown before grouping the rest in "Others" */
const MAX_EXPENSES = 8;
/** Longest label before it is shortened */
const MAX_NAME = 22;

/** Colors of the computed nodes */
const COLORS = { income: '#10b981', savings: '#3b82f6', balance: '#94a3b8', deficit: '#ef4444', others: '#a3a3a3', month: '#d12c48' };
/** Icons of the computed nodes */
const ICONS = { income: 'payments', savings: 'savings', balance: 'account_balance_wallet', deficit: 'warning', others: 'more_horiz' };

export default {
    name: 'FlowChart',

    props: {
        /** Answer of GET /budget/flow */
        flow: { type: Object, required: true },
        /** Translation function of the app */
        t: { type: Function, required: true },
        /** CSS color of a category */
        colorFor: { type: Function, required: true },
        /** Material icon of a category */
        iconFor: { type: Function, default: null },
        /** Amount formatter of the app */
        formatAmount: { type: Function, required: true },
        /** Symbol of the currency of the instance */
        currencySymbol: { type: String, default: '€' },
        /** Key of the selected node (null: none) */
        selected: { type: String, default: null }
    },

    emits: ['select'],

    data() {
        return { hover: null, showTable: false };
    },

    computed: {
        /** Nodes of the left column: income sources (and the deficit) */
        sources() {
            const nodes = this.flow.income.map(c => ({
                key: 'in-' + c.id,
                name: c.name || this.t('uncategorized'),
                amount: c.amount,
                color: c.color ? this.colorFor(c) : COLORS.income,
                icon: this.icon(c, ICONS.income),
                kind: 'income',
                categories: [c.id]
            }));
            if (this.flow.balance < 0) {
                nodes.push({ key: 'deficit', name: this.t('flowDeficit'), amount: -this.flow.balance, color: COLORS.deficit, icon: ICONS.deficit });
            }
            return nodes;
        },

        /** Nodes of the right column: expenses (the smallest grouped), savings, what is left */
        targets() {
            const expenses = this.flow.expenses.map(c => ({
                key: 'out-' + c.id,
                name: c.name || this.t('uncategorized'),
                amount: c.amount,
                color: this.colorFor(c),
                icon: this.icon(c, 'category'),
                kind: 'expense',
                categories: [c.id]
            }));
            const nodes = expenses.slice(0, MAX_EXPENSES);
            const rest = expenses.slice(MAX_EXPENSES);
            if (rest.length) {
                nodes.push({
                    key: 'others',
                    name: this.t('flowOthers', { count: rest.length }),
                    amount: rest.reduce((s, n) => s + n.amount, 0),
                    color: COLORS.others,
                    icon: ICONS.others,
                    kind: 'expense',
                    categories: rest.flatMap(n => n.categories)
                });
            }
            if (this.flow.savings > 0) {
                nodes.push({ key: 'savings', name: this.t('trendsSavings'), amount: this.flow.savings, color: COLORS.savings, icon: ICONS.savings, kind: 'savings', categories: [] });
            }
            if (this.flow.balance > 0) {
                nodes.push({ key: 'balance', name: this.t('flowBalance'), amount: this.flow.balance, color: COLORS.balance, icon: ICONS.balance });
            }
            return nodes;
        },

        /** Total going through the middle node */
        total() {
            return Math.max(this.sources.reduce((s, n) => s + n.amount, 0), this.targets.reduce((s, n) => s + n.amount, 0));
        },

        /** Key figures shown above the diagram */
        kpis() {
            const savings = Math.max(this.flow.savings, 0);
            const rate = this.flow.totalIncome > 0 ? Math.round(savings / this.flow.totalIncome * 100) : null;
            return [
                { key: 'income', label: this.t('flowKpiIncome'), value: this.flow.totalIncome, icon: 'south_east', tone: 'positive' },
                { key: 'expenses', label: this.t('flowKpiExpenses'), value: this.flow.totalExpenses, icon: 'north_east', tone: 'negative' },
                { key: 'savings', label: this.t('trendsSavings'), value: savings, icon: 'savings', tone: 'savings',
                  note: rate !== null ? this.t('flowSavingsRate', { rate }) : '' },
                { key: 'balance', label: this.t(this.flow.balance < 0 ? 'flowDeficit' : 'flowBalance'), value: this.flow.balance,
                  icon: this.flow.balance < 0 ? 'warning' : 'account_balance_wallet', tone: this.flow.balance < 0 ? 'negative' : 'neutral' }
            ];
        },

        /**
         * Geometry: nodes with their position and the links as SVG paths
         * @returns {{nodes: Array, left: Array, right: Array, middle: Object, links: Array, height: number}}
         */
        layout() {
            const scale = this.total > 0 ? FLOW_HEIGHT / this.total : 0;
            const xLeft = LABEL;
            const xMiddle = WIDTH / 2 - MIDDLE / 2;
            const xRight = WIDTH - LABEL - NODE;
            // Each node takes at least SLOT pixels so that the labels never overlap
            const room = list => list.reduce((s, n) => s + Math.max(n.amount * scale, SLOT), 0) + GAP * Math.max(list.length - 1, 0);
            const h = TOP + Math.max(room(this.sources), room(this.targets), FLOW_HEIGHT) + 12;

            const column = (list, x) => {
                let y = TOP + (h - TOP - 12 - room(list)) / 2;
                return list.map(n => {
                    const height = Math.max(n.amount * scale, 3);
                    const slot = Math.max(height, SLOT);
                    // The bar is centered in its slot
                    const node = { ...n, x, y: y + (slot - height) / 2, height, selectable: !!n.kind };
                    y += slot + GAP;
                    return node;
                });
            };
            const left = column(this.sources, xLeft);
            const right = column(this.targets, xRight);
            const middleHeight = Math.max(this.total * scale, 3);
            const middle = { key: 'month', name: this.t('flowIncome'), amount: this.total, color: COLORS.month, x: xMiddle, y: TOP + (h - TOP - 12 - middleHeight) / 2, height: middleHeight };

            const band = (x0, y0, x1, y1, size) => {
                const curve = (x1 - x0) / 2;
                return `M${x0},${y0} C${x0 + curve},${y0} ${x1 - curve},${y1} ${x1},${y1}`
                    + ` L${x1},${y1 + size} C${x1 - curve},${y1 + size} ${x0 + curve},${y0 + size} ${x0},${y0 + size} Z`;
            };

            const links = [];
            let inY = middle.y;
            for (const n of left) {
                const size = n.amount * scale;
                links.push({ key: n.key, node: n, d: band(n.x + NODE, n.y, middle.x, inY, size),
                             x1: n.x + NODE, x2: middle.x, from: 0.9, to: 0.3 });
                inY += size;
            }
            let outY = middle.y;
            for (const n of right) {
                const size = n.amount * scale;
                links.push({ key: n.key, node: n, d: band(middle.x + MIDDLE, outY, n.x, n.y, size),
                             x1: middle.x + MIDDLE, x2: n.x, from: 0.3, to: 0.9 });
                outY += size;
            }
            return { nodes: [...left, ...right], left, right, middle, links, height: h };
        },

        /** Node highlighted by the pointer, or else the selected one */
        focus() {
            return this.hover || this.selected;
        },

        /** Short description for screen readers */
        summary() {
            return this.t('flowSummary', {
                income: this.money(this.flow.totalIncome),
                expenses: this.money(this.flow.totalExpenses),
                savings: this.money(Math.max(this.flow.savings, 0)),
                balance: this.money(this.flow.balance)
            });
        }
    },

    methods: {
        /**
         * Amount with the currency
         * @param {number} value - Amount
         * @returns {string}
         */
        money(value) {
            return `${this.formatAmount(value)} ${this.currencySymbol}`;
        },

        /**
         * Share of the month's income
         * @param {number} value - Amount
         * @returns {string}
         */
        percent(value) {
            return this.total > 0 ? `${Math.round(value / this.total * 100)} %` : '';
        },

        /**
         * Material icon of a category
         * @param {Object} category - Category {icon}
         * @param {string} fallback - Icon when the category has none
         * @returns {string}
         */
        icon(category, fallback) {
            return (category.icon && this.iconFor) ? this.iconFor(category) : fallback;
        },

        /**
         * Label shortened to fit beside the diagram
         * @param {string} name
         * @returns {string}
         */
        short(name) {
            return name.length > MAX_NAME ? name.slice(0, MAX_NAME - 1) + '…' : name;
        },

        /**
         * Accessible name of a node: its text exactly as shown (WCAG 2.5.3 Label in Name),
         * the name shortened like on screen, the full one given as its description. No <title>
         * in a node: axe reads it as visible text. The name ends with a space in its <text>,
         * else axe joins it to the amount
         * @param {Object} node - Node of the chart
         * @returns {string} The name, amount and, on the right, share of the income
         */
        label(node) {
            const amount = node.x < WIDTH / 2 ? this.money(node.amount) : this.money(node.amount) + ' · ' + this.percent(node.amount);
            return this.short(node.name) + ' ' + amount;
        },

        /**
         * Gradient id of a link (the keys only use letters, digits and dashes)
         * @param {string} key - Key of the link
         * @returns {string}
         */
        gradient(key) {
            return 'flow-gradient-' + key;
        },

        /**
         * Class of a link or a node: dimmed when another one is highlighted
         * @param {Object} node
         * @returns {Object}
         */
        state(node) {
            return {
                dim: this.focus && this.focus !== node.key,
                active: this.focus === node.key,
                selected: this.selected === node.key,
                selectable: node.selectable
            };
        },

        /**
         * Selects a node (a second click on it clears the selection)
         * @param {Object} node
         */
        select(node) {
            if (!node.selectable) {
                return;
            }
            this.$emit('select', this.selected === node.key ? null : {
                key: node.key, name: node.name, color: node.color, icon: node.icon, kind: node.kind, categories: node.categories, amount: node.amount
            });
        }
    },

    template: `
        <div class="flow-chart">
            <ul class="flow-kpis">
                <li v-for="kpi in kpis" :key="kpi.key" class="flow-kpi" :class="'tone-' + kpi.tone">
                    <span class="flow-kpi-icon material-icons" aria-hidden="true">{{ kpi.icon }}</span>
                    <span class="flow-kpi-text">
                        <span class="flow-kpi-label">{{ kpi.label }}</span>
                        <strong class="flow-kpi-value">{{ money(kpi.value) }}</strong>
                        <span v-if="kpi.note" class="flow-kpi-note">{{ kpi.note }}</span>
                    </span>
                </li>
            </ul>

            <div class="flow-canvas">
                <svg :viewBox="'0 0 ${WIDTH} ' + layout.height" preserveAspectRatio="xMidYMid meet" role="group" :aria-label="summary"
                     @mouseleave="hover = null">
                    <defs>
                        <linearGradient v-for="link in layout.links" :key="'g-' + link.key" :id="gradient(link.key)"
                                        gradientUnits="userSpaceOnUse" :x1="link.x1" :x2="link.x2" y1="0" y2="0">
                            <stop offset="0%" :style="{ stopColor: link.node.color, stopOpacity: link.from }" />
                            <stop offset="100%" :style="{ stopColor: link.node.color, stopOpacity: link.to }" />
                        </linearGradient>
                        <linearGradient id="flow-middle" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#f47287" />
                            <stop offset="100%" stop-color="#b3233c" />
                        </linearGradient>
                    </defs>

                    <path v-for="(link, i) in layout.links" :key="link.key" :d="link.d" class="flow-link" :class="state(link.node)"
                          :style="{ fill: 'url(#' + gradient(link.key) + ')', animationDelay: (i * 40) + 'ms' }"
                          @mouseenter="hover = link.key" @click="select(link.node)">
                        <title>{{ link.node.name }} : {{ money(link.node.amount) }} ({{ percent(link.node.amount) }})</title>
                    </path>

                    <rect class="flow-middle" :x="layout.middle.x" :y="layout.middle.y" width="${MIDDLE}" :height="layout.middle.height" rx="6"
                          fill="url(#flow-middle)" />
                    <text :x="layout.middle.x + ${MIDDLE / 2}" :y="Math.min(layout.middle.y, ${TOP}) - 34" text-anchor="middle" class="flow-middle-name">{{ layout.middle.name }}</text>
                    <text :x="layout.middle.x + ${MIDDLE / 2}" :y="Math.min(layout.middle.y, ${TOP}) - 12" text-anchor="middle" class="flow-middle-amount">{{ money(flow.totalIncome) }}</text>

                    <g v-for="node in layout.nodes" :key="node.key" class="flow-node" :class="[state(node), node.x < ${WIDTH / 2} ? 'left' : 'right']"
                       :role="node.selectable ? 'button' : null" :tabindex="node.selectable ? 0 : null"
                       :aria-pressed="node.selectable ? (selected === node.key ? 'true' : 'false') : null"
                       :aria-label="label(node)" :aria-description="short(node.name) === node.name ? null : node.name"
                       @mouseenter="hover = node.key" @focus="hover = node.key" @blur="hover = null"
                       @click="select(node)" @keydown.enter.prevent="select(node)" @keydown.space.prevent="select(node)">
                        <!-- Invisible area: the whole label is clickable -->
                        <rect class="flow-hit" :x="node.x < ${WIDTH / 2} ? node.x - ${LABEL - 8} : node.x" :y="node.y + node.height / 2 - ${SLOT / 2}"
                              width="${LABEL - 8 + NODE}" height="${SLOT}" rx="8" />
                        <rect class="flow-bar" :x="node.x" :y="node.y" width="${NODE}" :height="node.height" rx="4" :style="{ fill: node.color }" />
                        <template v-if="node.x < ${WIDTH / 2}">
                            <circle class="flow-badge" :cx="node.x - 22" :cy="node.y + node.height / 2" r="14" :style="{ fill: node.color }" />
                            <text :x="node.x - 44" :y="node.y + node.height / 2 - 3" text-anchor="end" class="flow-name">{{ short(node.name) + ' ' }}</text>
                            <text :x="node.x - 44" :y="node.y + node.height / 2 + 14" text-anchor="end" class="flow-amount">{{ money(node.amount) }}</text>
                        </template>
                        <template v-else>
                            <circle class="flow-badge" :cx="node.x + ${NODE + 22}" :cy="node.y + node.height / 2" r="14" :style="{ fill: node.color }" />
                            <text :x="node.x + ${NODE + 44}" :y="node.y + node.height / 2 - 3" class="flow-name">{{ short(node.name) + ' ' }}</text>
                            <text :x="node.x + ${NODE + 44}" :y="node.y + node.height / 2 + 14" class="flow-amount">{{ money(node.amount) }} · {{ percent(node.amount) }}</text>
                        </template>
                    </g>
                    <!-- The icons out of the nodes: axe 4.14 reads an icon ligature as visible text, which
                         is not in the name of the node (WCAG 2.5.3 Label in Name); clicks go through them -->
                    <text v-for="node in layout.nodes" :key="'i-' + node.key" class="flow-icon" :class="state(node)" aria-hidden="true"
                          :x="node.x < ${WIDTH / 2} ? node.x - 22 : node.x + ${NODE + 22}" :y="node.y + node.height / 2 + 8" text-anchor="middle">{{ node.icon }}</text>
                </svg>
            </div>

            <div class="flow-footer">
                <p class="flow-hint">
                    <span class="material-icons" aria-hidden="true">touch_app</span>{{ t('flowClickHint') }}
                </p>
                <button type="button" class="btn-secondary flow-table-toggle" @click="showTable = !showTable" :aria-expanded="showTable ? 'true' : 'false'">
                    <span class="material-icons" aria-hidden="true">table_rows</span>
                    {{ showTable ? t('flowHideTable') : t('flowShowTable') }}
                </button>
            </div>
            <table v-if="showTable" class="flow-table">
                <caption class="visually-hidden">{{ summary }}</caption>
                <thead>
                    <tr><th scope="col">{{ t('flowFrom') }}</th><th scope="col">{{ t('flowTo') }}</th><th scope="col" class="num">{{ t('flowAmount') }}</th><th scope="col" class="num">%</th></tr>
                </thead>
                <tbody>
                    <tr v-for="n in sources" :key="'t-' + n.key"><td>{{ n.name }}</td><td>{{ t('flowIncome') }}</td><td class="num">{{ money(n.amount) }}</td><td class="num">{{ percent(n.amount) }}</td></tr>
                    <tr v-for="n in targets" :key="'t-' + n.key"><td>{{ t('flowIncome') }}</td><td>{{ n.name }}</td><td class="num">{{ money(n.amount) }}</td><td class="num">{{ percent(n.amount) }}</td></tr>
                </tbody>
            </table>
        </div>
    `
};
