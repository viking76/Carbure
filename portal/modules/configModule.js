/**
 * Config Module
 *
 * Settings and Sync tabs (Administration menu): the configuration file of the
 * instance by section, the secrets masked and only the safe settings editable
 * (the server checks every value; the SMTP password and the push API key only
 * replaced) with a test email of the SMTP settings, the registration of the instance
 * on the push service (Carbure Push) and a test push; the request that starts the
 * bank synchronization for a scheduler (cron, Synology task...).
 *
 * @module configModule
 */

/**
 * curl command that starts the synchronization
 * @param {string} url - URL of /api/bank/sync
 * @param {string|null} token - Synchronization token (null: none configured)
 * @param {string} account - Account to synchronize ('' for all)
 * @returns {string}
 */
export function syncCommand(url, token, account = '') {
    const target = account ? `${url}?account=${encodeURIComponent(account)}` : url;
    const header = token ? ` -H "X-Sync-Token: ${token}"` : '';
    // -N: the progress is streamed (Server-Sent Events)
    return `curl -sN${header} "${target}"`;
}

/**
 * Message of a test push: how many devices were reached, or why none was
 * @param {Object} result - Answer of POST /system/push-test {sent, devices, results}
 * @returns {{ok: boolean, key: string, params: Object}} Translation key and its parameters
 */
export function pushTestOutcome(result) {
    if (result.sent > 0) {
        return { ok: true, key: 'pushTestSent', params: { count: result.sent, devices: result.devices } };
    }
    const errors = [...new Set(result.results.map(device => device.error || device.status))].join(', ');
    return { ok: false, key: 'pushTestFailed', params: { errors } };
}

/** Level of a state: its icon */
const STATE_ICONS = { ok: 'check_circle', warning: 'warning', error: 'error', unknown: 'help' };

/**
 * State of the relay as shown, from GET /system/push-status
 * @param {Object|null} relay - {reachable, status, version, error}
 * @returns {{level: string, icon: string, label: string}} Level, icon and translation key
 */
export function pushRelayState(relay) {
    const level = !relay ? 'unknown' : { ok: 'ok', error: 'error', unreachable: 'error' }[relay.status] || 'unknown';
    const label = !relay ? 'pushStatusLoading'
        : { ok: 'pushRelayOk', error: 'pushRelayError', unreachable: 'pushRelayUnreachable' }[relay.status] || 'pushRelayError';
    return { level, icon: STATE_ICONS[level], label };
}

/**
 * State of the API key as shown, from GET /system/push-status
 * @param {Object|null} key - {state, key_id, devices, used_today, limits, error}
 * @returns {{level: string, icon: string, label: string, params: Object}} Level, icon,
 *     translation key and its parameters
 */
export function pushKeyState(key) {
    if (!key) {
        return { level: 'unknown', icon: STATE_ICONS.unknown, label: 'pushStatusLoading', params: {} };
    }
    const limits = key.limits || {};
    const states = {
        valid: ['ok', 'pushKeyValid', { id: key.key_id, devices: key.devices, devicesLimit: limits.devices ?? '?',
            used: key.used_today, usedLimit: limits.notifications_per_day ?? '?' }],
        invalid: ['error', 'pushKeyInvalid', {}],
        none: ['warning', 'pushKeyNone', {}],
    };
    const [level, label, params] = states[key.state] || ['unknown', 'pushKeyUnknown', {}];
    return { level, icon: STATE_ICONS[level], label, params };
}

/**
 * Creates the config module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the config data and methods
 */
export function createConfigModule(getApiService) {
    return {
        data() {
            return {
                // Answer of GET /system/config
                configData: null,
                // Values being edited, by key
                configDraft: {},
                loadingConfig: false,
                savingConfig: false,
                configError: null,
                // Answer of GET /system/sync
                syncInfo: null,
                syncAccount: '',
                renewingSyncToken: false,
                // Test email: recipient ('' for the address of the administrator), result
                smtpTestTo: '',
                sendingTestEmail: false,
                smtpTestResult: null,
                // Push service: registration and test push in progress, their result
                registeringPush: false,
                pushRegisterResult: null,
                sendingTestPush: false,
                pushTestResult: null,
                // Answer of GET /system/push: the relay, the API key and the consent to it
                pushInfo: null,
                // Whether the API key of the push service is shown in full
                pushKeyRevealed: false,
                // Answer of GET /system/push-status: health of the relay, status of the key
                pushStatus: null,
                loadingPushStatus: false,
                pushStatusError: null,
                // Consent dialog of the push service: {accepted, trigger}; null when closed
                pushConsent: null
            };
        },

        computed: {
            /**
             * Editable settings whose value changed
             * @returns {Object} New values by key
             */
            configChanges() {
                const changes = {};
                for (const section of this.configData ? this.configData.sections : []) {
                    for (const setting of section.settings) {
                        if (setting.editable && this.configDraft[setting.key] !== this.configValue(setting)) {
                            changes[setting.key] = this.configDraft[setting.key];
                        }
                    }
                }
                return changes;
            },

            /**
             * Number of settings changed
             * @returns {number}
             */
            configChangeCount() {
                return Object.keys(this.configChanges).length;
            },

            /**
             * API key of the push service as shown: in full once revealed, else its public
             * id only (cpk_<id>_••••)
             * @returns {string}
             */
            pushKeyDisplay() {
                const key = this.pushInfo ? this.pushInfo.api_key || '' : '';
                if (this.pushKeyRevealed || !key.startsWith('cpk_')) {
                    return key;
                }
                return `${key.slice(0, 21)}${'•'.repeat(12)}`;
            },

            /**
             * State of the relay as shown: level (ok, warning, error), icon, text key
             * @returns {{level: string, icon: string, label: string}}
             */
            pushRelayState() {
                return pushRelayState(this.pushStatus ? this.pushStatus.relay : null);
            },

            /**
             * State of the API key as shown: level, icon, text key and its parameters
             * @returns {{level: string, icon: string, label: string, params: Object}}
             */
            pushKeyState() {
                return pushKeyState(this.pushStatus ? this.pushStatus.key : null);
            },

            /**
             * Whether an API key of the push service is saved
             * @returns {boolean}
             */
            pushKeySet() {
                if (this.pushInfo) {
                    return !!this.pushInfo.registered;
                }
                const push = this.configData ? this.configData.sections.find(section => section.name === 'push') : null;
                const key = push ? push.settings.find(setting => setting.key === 'push_api_key') : null;
                return !!(key && key.set);
            },

            /**
             * The curl command of the synchronization
             * @returns {string}
             */
            syncCurl() {
                return this.syncInfo ? syncCommand(this.syncInfo.url, this.syncInfo.token, this.syncAccount) : '';
            },

            /**
             * A crontab line: every day at 6 am
             * @returns {string}
             */
            syncCron() {
                return this.syncInfo ? `0 6 * * * ${this.syncCurl} > /dev/null` : '';
            }
        },

        methods: {
            /**
             * Loads the configuration file (Settings tab)
             * @returns {Promise<void>}
             */
            async loadConfig() {
                this.loadingConfig = true;
                this.configError = null;
                try {
                    this.setConfig(await getApiService().fetchConfig());
                } catch (error) {
                    this.configError = this.apiErrorText(error);
                } finally {
                    this.loadingConfig = false;
                }
                await this.loadPushInfo();
            },

            /**
             * Loads the relay of the push service and the consent to it
             * @returns {Promise<boolean>} Whether it could be loaded
             */
            async loadPushInfo() {
                this.pushKeyRevealed = false;
                try {
                    this.pushInfo = await getApiService().fetchPushInfo();
                    return true;
                } catch (error) {
                    this.pushInfo = null;
                    return false;
                }
            },

            /**
             * Loads the Push tab: the service and the consent, then its status
             * @returns {Promise<void>}
             */
            async loadPushTab() {
                await this.loadPushInfo();
                await this.loadPushStatus();
            },

            /**
             * Asks the relay for its health and the status of the API key
             * @returns {Promise<void>}
             */
            async loadPushStatus() {
                this.loadingPushStatus = true;
                this.pushStatusError = null;
                try {
                    this.pushStatus = await getApiService().fetchPushStatus();
                } catch (error) {
                    this.pushStatus = null;
                    this.pushStatusError = this.apiErrorText(error);
                } finally {
                    this.loadingPushStatus = false;
                }
            },

            /**
             * Date of the consent to the push service, in the language of the portal
             * @param {string} at - ISO 8601 date
             * @returns {string}
             */
            pushConsentDate(at) {
                const date = new Date(at);
                return isNaN(date) ? at : date.toLocaleString(this.intlLocale, { dateStyle: 'long', timeStyle: 'short' });
            },

            /**
             * Loads the request of the synchronization, the token masked (Sync tab)
             * @returns {Promise<void>}
             */
            async loadSyncInfo() {
                this.configError = null;
                try {
                    this.syncInfo = await getApiService().fetchSyncInfo(false);
                } catch (error) {
                    this.configError = this.apiErrorText(error);
                }
            },

            /**
             * Shows a configuration and resets the values being edited
             * @param {Object} config - Answer of GET /system/config
             */
            setConfig(config) {
                this.configData = config;
                const draft = {};
                for (const section of config.sections) {
                    for (const setting of section.settings) {
                        if (setting.editable) {
                            draft[setting.key] = this.configValue(setting);
                        }
                    }
                }
                this.configDraft = draft;
            },

            /**
             * Value of a setting in the form (the booleans as true/false)
             * @param {Object} setting - Setting
             * @returns {string|boolean}
             */
            configValue(setting) {
                if (setting.kind === 'bool') {
                    return setting.value === true;
                }
                // A password is never sent by the server: typed only to replace it
                if (setting.kind === 'password') {
                    return '';
                }
                return setting.value === null || setting.value === undefined ? '' : String(setting.value);
            },

            /** Cancels the changes */
            resetConfigDraft() {
                if (this.configData) {
                    this.setConfig(this.configData);
                }
                this.configError = null;
            },

            /**
             * Saves the settings changed
             * @returns {Promise<void>}
             */
            async saveConfig() {
                if (!this.configChangeCount) {
                    return;
                }
                this.savingConfig = true;
                this.configError = null;
                try {
                    const values = {};
                    for (const [key, value] of Object.entries(this.configChanges)) {
                        values[key] = typeof value === 'boolean' ? (value ? 'true' : 'false') : value;
                    }
                    this.setConfig(await getApiService().updateConfig(values));
                    this.showToast(this.t('configSaved'));
                    // The relay may have changed: the consent asked again for a new one
                    this.loadPushInfo();
                } catch (error) {
                    this.configError = this.apiErrorText(error);
                } finally {
                    this.savingConfig = false;
                }
            },

            /**
             * Name of a section of the file
             * @param {string} name - Section
             * @returns {string}
             */
            configSectionLabel(name) {
                const key = 'configSection_' + name;
                const label = this.t(key);
                return label === key ? name : label;
            },

            /**
             * Description of a setting ('' if there is none)
             * @param {string} key - Setting
             * @returns {string}
             */
            configHelp(key) {
                const name = 'configHelp_' + key;
                const help = this.t(name);
                return help === name ? '' : help;
            },

            /**
             * Warning of a setting with a risk: what it does and when it can go wrong ('' if none)
             * @param {string} key - Setting
             * @returns {string}
             */
            configWarning(key) {
                const name = 'configWarning_' + key;
                const warning = this.t(name);
                return warning === name ? '' : warning;
            },

            /**
             * Sends a test email with the saved SMTP settings, to the address typed or
             * to the one of the administrator
             * @returns {Promise<void>}
             */
            async sendTestEmail() {
                if (this.sendingTestEmail) {
                    return;
                }
                this.sendingTestEmail = true;
                this.smtpTestResult = null;
                try {
                    const result = await getApiService().sendTestEmail(this.smtpTestTo.trim());
                    this.smtpTestResult = { ok: true, message: this.t('smtpTestSent', { to: result.to }) };
                } catch (error) {
                    this.smtpTestResult = { ok: false, message: this.apiErrorText(error) };
                } finally {
                    this.sendingTestEmail = false;
                }
            },

            /**
             * Registers the instance on the push service, after the consent of the
             * administrator (asked in a dialog for a registration, and for a renewal when
             * it was given for another relay), or renews its key after confirmation
             * @returns {Promise<void>}
             */
            async registerPush() {
                if (this.registeringPush) {
                    return;
                }
                // The focus comes back to the button that asked, once the dialog is closed
                const trigger = typeof document !== 'undefined' ? document.activeElement : null;
                this.pushRegisterResult = null;
                if (!await this.loadPushInfo()) {
                    this.pushRegisterResult = { ok: false, message: this.t('pushInfoError') };
                    return;
                }
                if (!this.pushKeySet || !this.pushInfo.consent_valid) {
                    this.openPushConsent(trigger);
                    return;
                }
                if (window.confirm(this.t('pushRenewConfirm'))) {
                    await this.submitPushRegistration(false);
                }
            },

            /**
             * Opens the consent dialog of the push service, its title focused
             * @param {Element|null} trigger - Element focused again once closed
             */
            openPushConsent(trigger) {
                this.pushConsent = { accepted: false, trigger };
                if (this.$nextTick) {
                    this.$nextTick(() => document.getElementById('push-consent-title')?.focus());
                }
            },

            /**
             * Closes the consent dialog, the focus back on the button that opened it
             */
            closePushConsent() {
                const trigger = this.pushConsent ? this.pushConsent.trigger : null;
                this.pushConsent = null;
                if (trigger && typeof trigger.focus === 'function' && trigger.isConnected) {
                    trigger.focus();
                }
            },

            /**
             * Registers the instance once the consent is ticked
             * @returns {Promise<void>}
             */
            async acceptPushConsent() {
                if (!this.pushConsent || !this.pushConsent.accepted) {
                    return;
                }
                this.closePushConsent();
                await this.submitPushRegistration(true);
            },

            /**
             * Keeps the keyboard focus in the consent dialog (Tab and Shift+Tab loop)
             * @param {KeyboardEvent} event - keydown in the dialog
             */
            trapPushConsentFocus(event) {
                if (event.key !== 'Tab') {
                    return;
                }
                const focusable = [...event.currentTarget.querySelectorAll('button:not(:disabled), input:not(:disabled), a[href]')];
                if (!focusable.length) {
                    return;
                }
                const first = focusable[0];
                const last = focusable[focusable.length - 1];
                const active = document.activeElement;
                if (event.shiftKey && (active === first || !event.currentTarget.contains(active) || active.id === 'push-consent-title')) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && active === last) {
                    event.preventDefault();
                    first.focus();
                }
            },

            /**
             * Registers the instance on the push service (or renews its key) and shows the
             * saved configuration
             * @param {boolean} consent - The administrator has just given the consent
             * @returns {Promise<void>}
             */
            async submitPushRegistration(consent) {
                this.registeringPush = true;
                this.pushRegisterResult = null;
                try {
                    const result = await getApiService().registerPush(consent);
                    this.pushRegisterResult = { ok: true, message: this.t(result.renewed ? 'pushRenewed' : 'pushRegistered', { key: result.key_id }) };
                    this.setConfig(await getApiService().fetchConfig());
                } catch (error) {
                    this.pushRegisterResult = { ok: false, message: this.apiErrorText(error) };
                } finally {
                    this.registeringPush = false;
                }
                await this.loadPushInfo();
                await this.loadPushStatus();
            },

            /**
             * Sends a test push notification to the devices of the administrator
             * @returns {Promise<void>}
             */
            async sendTestPush() {
                if (this.sendingTestPush) {
                    return;
                }
                this.sendingTestPush = true;
                this.pushTestResult = null;
                try {
                    const outcome = pushTestOutcome(await getApiService().sendTestPush());
                    this.pushTestResult = { ok: outcome.ok, message: this.t(outcome.key, outcome.params) };
                } catch (error) {
                    this.pushTestResult = { ok: false, message: this.apiErrorText(error) };
                } finally {
                    this.sendingTestPush = false;
                }
                // The notifications of the day of the key changed
                if (this.pushStatus) {
                    await this.loadPushStatus();
                }
            },

            /**
             * Shows the synchronization token, or masks it again
             * @returns {Promise<void>}
             */
            async toggleSyncToken() {
                try {
                    this.syncInfo = await getApiService().fetchSyncInfo(!!(this.syncInfo && this.syncInfo.masked));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Copies the command with the real token
             * @param {boolean} cron - The crontab line instead of the command
             * @returns {Promise<void>}
             */
            async copySyncCommand(cron = false) {
                try {
                    const info = this.syncInfo.masked ? await getApiService().fetchSyncInfo(true) : this.syncInfo;
                    const command = syncCommand(info.url, info.token, this.syncAccount);
                    await this.copyText(cron ? `0 6 * * * ${command} > /dev/null` : command);
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                }
            },

            /**
             * Replaces the synchronization token after confirmation
             * @returns {Promise<void>}
             */
            async renewSyncToken() {
                const message = this.syncInfo && this.syncInfo.token ? this.t('syncTokenRenewConfirm') : this.t('syncTokenCreateConfirm');
                if (!window.confirm(message)) {
                    return;
                }
                this.renewingSyncToken = true;
                try {
                    await getApiService().renewSyncToken();
                    this.syncInfo = await getApiService().fetchSyncInfo(true);
                    this.showToast(this.t('syncTokenRenewed'));
                } catch (error) {
                    this.showToast(this.apiErrorText(error));
                } finally {
                    this.renewingSyncToken = false;
                }
            }
        }
    };
}
