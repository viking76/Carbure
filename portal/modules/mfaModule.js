/**
 * MFA Module
 *
 * Two-factor authentication (code of an authenticator app, TOTP):
 * - the second step of the login screen: the code (or a recovery code), the choice to
 *   trust this browser for 6 months, the registration of the app (QR code) when the
 *   administrator enforces it and the user has none yet, then his recovery codes;
 * - the "Two-factor authentication" sub-tab of the profile: registration, recovery codes,
 *   disabling; the trusted browsers are listed (and revoked) in the "My devices" sub-tab;
 * - the reset of the second factor of a user by an administrator (Users tab).
 * The QR code is drawn here (vendor/qrcode-generator), never by an external service.
 *
 * @module mfaModule
 */

/**
 * SVG path of the dark modules of a QR code, one square per module, with a quiet
 * zone of 4 modules
 * @param {{getModuleCount: Function, isDark: Function}} qr - QR code made by qrcode-generator
 * @returns {{size: number, path: string}} Size of the viewBox and the path
 */
export function qrPath(qr) {
    const count = qr.getModuleCount();
    const parts = [];
    for (let row = 0; row < count; row++) {
        for (let col = 0; col < count; col++) {
            if (qr.isDark(row, col)) {
                parts.push(`M${col + 4} ${row + 4}h1v1h-1z`);
            }
        }
    }
    return { size: count + 8, path: parts.join('') };
}

/**
 * QR code of a text, with the library loaded by the page (window.qrcode)
 * @param {string} text - Text (otpauth:// URI)
 * @returns {{size: number, path: string}|null} null if the library is missing
 */
export function qrCodeOf(text) {
    const factory = typeof window !== 'undefined' ? window.qrcode : null;
    if (!text || typeof factory !== 'function') {
        return null;
    }
    const qr = factory(0, 'M');
    qr.addData(text);
    qr.make();
    return qrPath(qr);
}

/**
 * Secret shown in groups of 4 characters, easier to type in an app
 * @param {string} secret - Secret (base32)
 * @returns {string}
 */
export function groupSecret(secret) {
    return (secret || '').replace(/(.{4})(?=.)/g, '$1 ');
}

/**
 * Browser and system of a User-Agent
 * @param {string} userAgent - User-Agent
 * @returns {{browser: string, system: string}} '' for a part not recognized
 */
export function browserParts(userAgent) {
    const ua = userAgent || '';
    const browser = [['Edg/', 'Edge'], ['OPR/', 'Opera'], ['Firefox/', 'Firefox'], ['Chrome/', 'Chrome'], ['Safari/', 'Safari']]
        .find(([token]) => ua.includes(token));
    const system = [['iPhone', 'iOS'], ['iPad', 'iPadOS'], ['Android', 'Android'], ['Windows', 'Windows'], ['Mac OS X', 'macOS'], ['Macintosh', 'macOS'], ['Linux', 'Linux']]
        .find(([token]) => ua.includes(token));
    return { browser: browser ? browser[1] : '', system: system ? system[1] : '' };
}

/**
 * Short name of a browser from its User-Agent: "Firefox · Windows"
 * @param {string} userAgent - User-Agent
 * @returns {string} '' if unknown
 */
export function browserLabel(userAgent) {
    const { browser, system } = browserParts(userAgent);
    return [browser, system].filter(Boolean).join(' · ');
}

/**
 * Creates the MFA module mixin for Vue components
 * @param {Function} getApiService - Function that returns the API service instance
 * @returns {Object} Vue mixin with the two-factor authentication data and methods
 */
export function createMfaModule(getApiService) {
    return {
        data() {
            return {
                // Second step of the login: {mode: 'verify'|'enroll', token, secret, otpauth}
                mfaLogin: null,
                mfaCode: '',
                mfaTrust: false,
                mfaVerifying: false,
                // Session opened at the registration, kept until the recovery codes are read
                mfaPendingSession: null,
                mfaLoginRecoveryCodes: [],
                // Profile: answer of GET /user/mfa, registration in progress, code typed
                mfaStatus: null,
                mfaSetup: null,
                mfaProfileCode: '',
                mfaBusy: false,
                mfaError: null,
                mfaRecoveryCodes: []
            };
        },

        computed: {
            /**
             * QR code of the registration in progress (login screen or profile)
             * @returns {{size: number, path: string}|null}
             */
            mfaQr() {
                const source = this.mfaSetup || (this.mfaLogin && this.mfaLogin.mode === 'enroll' ? this.mfaLogin : null);
                return source ? qrCodeOf(source.otpauth) : null;
            }
        },

        methods: {
            groupSecret,
            browserLabel,

            /**
             * Readable name of a trusted browser from its User-Agent: "Firefox sur Windows"
             * @param {string} userAgent - User-Agent
             * @returns {string} The name, or "Unknown browser"
             */
            browserName(userAgent) {
                const { browser, system } = browserParts(userAgent);
                if (browser && system) {
                    return this.t('browserOnSystem', { browser, system });
                }
                return browser || system || this.t('mfaUnknownBrowser');
            },

            /**
             * Answer of the login asking for the second factor: shows its step
             * @param {Object} challenge - {mfa, mfa_token, exp, secret?, otpauth?}
             */
            startMfaLogin(challenge) {
                this.mfaLogin = {
                    mode: challenge.mfa,
                    token: challenge.mfa_token,
                    secret: challenge.secret || '',
                    otpauth: challenge.otpauth || ''
                };
                this.mfaCode = '';
                this.mfaTrust = false;
                this.loginError = null;
                this.$nextTick(() => document.getElementById('mfaCode')?.focus());
            },

            /**
             * Back to the password (the challenge is dropped)
             */
            cancelMfaLogin() {
                this.mfaLogin = null;
                this.mfaCode = '';
                this.mfaPendingSession = null;
                this.mfaLoginRecoveryCodes = [];
                this.loginForm.password = '';
            },

            /**
             * Sends the code of the second step; opens the session, or first shows the
             * recovery codes of an app registered at this step
             * @returns {Promise<void>}
             */
            async submitMfaLogin() {
                if (!this.mfaCode.trim()) {
                    this.loginError = this.t('mfaCodeRequired');
                    return;
                }
                this.mfaVerifying = true;
                this.loginError = null;
                try {
                    const response = await fetch(`${this.apiBaseUrl}/user/login/mfa`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        // The cookie of a trusted browser is set by the answer
                        credentials: 'same-origin',
                        body: JSON.stringify({ mfa_token: this.mfaLogin.token, code: this.mfaCode.trim(), trust: this.mfaTrust })
                    });
                    if (!response.ok) {
                        this.mfaLoginFailed(response.status);
                        return;
                    }
                    const data = await response.json();
                    if (Array.isArray(data.recovery_codes) && data.recovery_codes.length) {
                        this.mfaPendingSession = data;
                        this.mfaLoginRecoveryCodes = data.recovery_codes;
                        this.$nextTick(() => document.getElementById('mfa-recovery-title')?.focus());
                        return;
                    }
                    await this.finishMfaLogin(data);
                } catch (error) {
                    this.loginError = this.t('loginError');
                } finally {
                    this.mfaVerifying = false;
                }
            },

            /**
             * A code refused: wrong code or too many logins (try again), account locked or
             * login expired (back to the password)
             * @param {number} status - HTTP status
             */
            mfaLoginFailed(status) {
                this.mfaCode = '';
                // Too many logins from this address (rate limit of nginx): the same code
                // step, to try again in a minute
                if (status === 401 || status === 429) {
                    this.loginError = this.t(status === 429 ? 'tooManyLogins' : 'mfaInvalidCode');
                    this.$nextTick(() => document.getElementById('mfaCode')?.focus());
                    return;
                }
                this.cancelMfaLogin();
                this.loginError = this.t(status === 423 ? 'accountLocked' : 'mfaExpired');
            },

            /**
             * The recovery codes were read: opens the session
             * @returns {Promise<void>}
             */
            async continueAfterRecoveryCodes() {
                const session = this.mfaPendingSession;
                this.mfaLoginRecoveryCodes = [];
                this.mfaPendingSession = null;
                await this.finishMfaLogin(session);
            },

            /**
             * Opens the session given by the second step
             * @param {Object} session - {token, ...}
             * @returns {Promise<void>}
             */
            async finishMfaLogin(session) {
                this.mfaLogin = null;
                this.mfaCode = '';
                await this.openSession(session.token);
            },

            /**
             * Copies the recovery codes to the clipboard
             * @param {string[]} codes - Recovery codes
             * @returns {Promise<void>}
             */
            async copyRecoveryCodes(codes) {
                try {
                    await navigator.clipboard.writeText(codes.join('\n'));
                    this.showToast(this.t('mfaRecoveryCopied'));
                } catch (error) {
                    // Clipboard API needs HTTPS (or localhost): the codes stay shown
                    this.showToast(this.t('copyUnavailable'));
                }
            },

            /**
             * Loads the two-factor authentication of the profile
             * @returns {Promise<void>}
             */
            async loadMfaStatus() {
                this.mfaError = null;
                try {
                    this.mfaStatus = await getApiService().fetchMfa();
                } catch (error) {
                    this.mfaStatus = null;
                }
            },

            /**
             * Runs an action of the profile block, with its error shown in the block
             * @param {Function} action - Async function
             * @returns {Promise<void>}
             */
            async runMfaAction(action) {
                this.mfaBusy = true;
                this.mfaError = null;
                try {
                    await action();
                } catch (error) {
                    this.mfaError = this.apiErrorText(error);
                } finally {
                    this.mfaBusy = false;
                }
            },

            /**
             * Starts the registration of an authenticator app (QR code)
             * @returns {Promise<void>}
             */
            startMfaSetup() {
                return this.runMfaAction(async () => {
                    this.mfaRecoveryCodes = [];
                    this.mfaProfileCode = '';
                    this.mfaSetup = await getApiService().setupMfa();
                    this.$nextTick(() => document.getElementById('mfa-profile-code')?.focus());
                });
            },

            /**
             * Confirms the app with its first code: the recovery codes are shown
             * @returns {Promise<void>}
             */
            confirmMfaSetup() {
                return this.runMfaAction(async () => {
                    const result = await getApiService().enableMfa(this.mfaProfileCode.trim());
                    this.mfaSetup = null;
                    this.mfaProfileCode = '';
                    this.mfaRecoveryCodes = result.recovery_codes || [];
                    this.showToast(this.t('mfaEnabledToast'));
                    await this.loadMfaStatus();
                });
            },

            /**
             * Gives up the registration in progress
             */
            cancelMfaSetup() {
                this.mfaSetup = null;
                this.mfaProfileCode = '';
                this.mfaError = null;
            },

            /**
             * New recovery codes, with a code of the app
             * @returns {Promise<void>}
             */
            renewMfaRecoveryCodes() {
                return this.runMfaAction(async () => {
                    const result = await getApiService().renewRecoveryCodes(this.mfaProfileCode.trim());
                    this.mfaProfileCode = '';
                    this.mfaRecoveryCodes = result.recovery_codes || [];
                    await this.loadMfaStatus();
                });
            },

            /**
             * Disables the two-factor authentication, with a code of the app or a
             * recovery code (asked in a confirmation, its error shown there)
             * @returns {Promise<void>}
             */
            async disableMfa() {
                const code = this.mfaProfileCode.trim();
                const disabled = await this.askConfirm({
                    title: this.t('mfaDisableTitle'),
                    message: this.t('mfaDisableConfirm'),
                    warning: this.t('mfaDisableWarning'),
                    confirmLabel: this.t('mfaDisable'),
                    icon: 'lock_open',
                    danger: true,
                    action: () => getApiService().disableMfa(code)
                });
                if (!disabled) {
                    return;
                }
                this.mfaProfileCode = '';
                this.mfaRecoveryCodes = [];
                this.showToast(this.t('mfaDisabledToast'));
                await this.loadMfaStatus();
            },

            /**
             * Revokes a trusted browser: the code will be asked there again
             * @param {Object} browser - {id, label}
             * @returns {Promise<void>}
             */
            revokeTrustedBrowser(browser) {
                return this.runMfaAction(async () => {
                    await getApiService().deleteTrustedBrowser(browser.id);
                    this.showToast(this.t('mfaBrowserRevoked'));
                    await this.loadMfaStatus();
                });
            },

            /**
             * Resets the two-factor authentication of a user who lost his phone (administrators)
             * @param {Object} user - User
             * @returns {Promise<void>}
             */
            async resetUserMfa(user) {
                const reset = await this.askConfirm({
                    title: this.t('mfaReset'),
                    message: this.t('mfaResetConfirm', { username: user.username }),
                    warning: this.t('mfaResetWarning'),
                    confirmLabel: this.t('mfaResetButton'),
                    icon: 'phonelink_erase',
                    danger: true,
                    action: () => getApiService().resetUserMfa(user.id)
                });
                if (reset) {
                    this.showToast(this.t('mfaResetDone', { username: user.username }));
                }
            }
        }
    };
}
