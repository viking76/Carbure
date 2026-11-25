/**
 * Confirm Module
 *
 * Confirmation modal in the style of the portal, instead of the native confirm() of
 * the browser. With an action, the modal stays open while it runs (its button shows a
 * loader), closes once it succeeded, and shows its error otherwise.
 *
 * @module confirmModule
 */

/**
 * Creates the confirmation modal mixin for Vue components
 * @returns {Object} Vue mixin with the confirmation data and methods
 */
export function createConfirmModule() {
    return {
        data() {
            return {
                // Open confirmation: {title, message, confirmLabel, busyLabel, icon, danger,
                // busy, error, action, resolve, trigger}; null when closed
                confirmDialog: null
            };
        },

        methods: {
            /**
             * Asks a confirmation in a modal of the portal
             * @param {Object} options - What is asked
             * @param {string} options.title - Title of the modal
             * @param {string} options.message - Question
             * @param {string} [options.warning] - Consequence of the action, shown in a warning callout
             * @param {string} options.confirmLabel - Label of the confirm button
             * @param {string} [options.busyLabel] - Label of the confirm button while the action runs
             * @param {string} [options.icon] - Material icon of the title and the confirm button
             * @param {boolean} [options.danger] - Destructive action (red confirm button)
             * @param {Function} [options.action] - async () => {}: run on confirmation, the
             *                                      modal staying open (loader) until it ends
             * @returns {Promise<boolean>} True if confirmed (and the action succeeded), false if cancelled
             */
            askConfirm({ title, message, warning = null, confirmLabel, busyLabel = null, icon = 'help', danger = false, action = null }) {
                return new Promise(resolve => {
                    // The focus comes back to the button that asked, once closed
                    const trigger = typeof document !== 'undefined' ? document.activeElement : null;
                    this.confirmDialog = { title, message, warning, confirmLabel, busyLabel, icon, danger, busy: false, error: null, action, resolve, trigger };
                    // The safest choice has the focus: cancel
                    if (this.$nextTick) {
                        this.$nextTick(() => document.getElementById('confirm-dialog-cancel')?.focus());
                    }
                });
            },

            /**
             * Confirms: runs the action (modal kept open, loader), then closes; an error of
             * the action is shown in the modal, which can be confirmed again or cancelled
             * @returns {Promise<void>}
             */
            async acceptConfirm() {
                const dialog = this.confirmDialog;
                if (!dialog || dialog.busy) {
                    return;
                }
                if (!dialog.action) {
                    this.closeConfirm(true);
                    return;
                }
                this.confirmDialog = { ...dialog, busy: true, error: null };
                try {
                    await dialog.action();
                    this.closeConfirm(true);
                } catch (error) {
                    this.confirmDialog = { ...this.confirmDialog, busy: false, error: this.apiErrorText(error) || String(error) };
                }
            },

            /**
             * Cancels (not while the action runs)
             */
            cancelConfirm() {
                if (this.confirmDialog && !this.confirmDialog.busy) {
                    this.closeConfirm(false);
                }
            },

            /**
             * Closes the modal with its answer, the focus back on the button that asked
             * @param {boolean} confirmed - Answer
             */
            closeConfirm(confirmed) {
                const { resolve, trigger } = this.confirmDialog;
                this.confirmDialog = null;
                if (trigger && typeof trigger.focus === 'function' && trigger.isConnected) {
                    trigger.focus();
                }
                resolve(confirmed);
            }
        }
    };
}
