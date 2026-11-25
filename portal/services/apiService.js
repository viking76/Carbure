/**
 * API Service Module
 * 
 * Centralizes all API communication logic for the Carbure application.
 * Provides methods for transactions, budgets, and insights with error handling.
 * 
 * @module apiService
 */

import { parseChangesHeader } from '../modules/changesModule.js';

/**
 * Error of a failed request: the message of the API ({"error": "…"}, in English), with
 * its stable code and values ({"error_code", "params"}, translated by apiErrorText() of
 * i18n.js) and the HTTP status; the default message when the body is not an error of the
 * API (proxy page, empty body)
 * @param {number} status - HTTP status
 * @param {string} text - Body of the answer
 * @param {string} fallback - Message when the body has none
 * @returns {Error} The error, with status, and code and params when the API gives a code
 */
export function responseError(status, text, fallback) {
    let body = null;
    try {
        body = JSON.parse(text);
    } catch (e) {
        // Not JSON: the default message
    }
    const valid = body !== null && typeof body === 'object';
    const error = new Error(valid && typeof body.error === 'string' && body.error ? body.error : fallback);
    error.status = status;
    if (valid && typeof body.error_code === 'string') {
        error.code = body.error_code;
        error.params = body.params && typeof body.params === 'object' ? body.params : {};
    }
    return error;
}

/**
 * Creates an API service instance
 * @param {string} baseUrl - Base URL for API endpoints
 * @param {string} authToken - JWT authentication token
 * @param {Function} onUnauthorized - Called when the API answers 401 (expired or invalid token)
 * @param {Function} onSetupRequired - Called when the API answers that Carbure is not
 *   installed (503 {setup: true}, e.g. reinstalled from scratch at the same address)
 * @param {Function} onChanges - Called with the versions of the data of each answer
 *   (X-Carbure-Changes header) and the kinds bumped by a write (X-Carbure-Changed), see
 *   changesModule
 * @returns {Object} API service methods
 */
export function createApiService(baseUrl, authToken = null, onUnauthorized = null, onSetupRequired = null, onChanges = null) {
    /**
     * fetch() that reports a session that cannot be used: expired (HTTP 401), or
     * Carbure not installed (HTTP 503 {setup: true})
     * @param {string} url - Request URL
     * @param {Object} options - fetch options
     * @returns {Promise<Response>}
     */
    async function authFetch(url, options) {
        const response = await window.fetch(url, options);
        if (!authToken) {
            return response;
        }
        const changes = response.headers?.get?.('X-Carbure-Changes');
        if (changes && onChanges) {
            const written = (response.headers.get('X-Carbure-Changed') || '').split(',').map(kind => kind.trim()).filter(Boolean);
            onChanges(parseChangesHeader(changes), written);
        }
        if (response.status === 401 && onUnauthorized) {
            onUnauthorized();
        } else if (response.status === 503 && onSetupRequired
            && (await response.clone().json().catch(() => ({}))).setup === true) {
            onSetupRequired();
        }
        return response;
    }

    /**
     * Active AbortControllers for cancellable requests
     */
    const controllers = {
        transactions: null,
        budget: null,
        insights: null
    };
    
    /**
     * Creates headers with authentication
     * @returns {Object} Headers object
     */
    function getHeaders() {
        const headers = {
            'Content-Type': 'application/json'
        };
        
        if (authToken) {
            headers['Authorization'] = `Bearer ${authToken}`;
        }
        
        return headers;
    }
    
    /**
     * Aborts all ongoing requests except the specified one
     * @param {string} except - Request type to keep active
     */
    function abortAllExcept(except) {
        for (const [key, controller] of Object.entries(controllers)) {
            if (key === except) continue;
            if (controller && typeof controller.abort === 'function') {
                try {
                    controller.abort();
                } catch (e) {
                    // Ignore abort errors
                }
                controllers[key] = null;
            }
        }
    }
    
    /**
     * Fetches transactions for a given month and year
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<Array>} Array of transaction objects
     * @throws {Error} If request fails
     */
    async function fetchTransactions(month, year) {
        return cancellable('transactions', `${baseUrl}/transaction?month=${month}&year=${year}`, 'Failed to fetch transactions');
    }
    
    /**
     * Fetches the change markers of the household data
     * @returns {Promise<Object|null>} {kind: {version, updated_at}}, null if the server has
     *   none (older version: 404)
     * @throws {Error} If request fails
     */
    async function fetchChanges() {
        const response = await authFetch(`${baseUrl}/changes`, { headers: getHeaders() });
        if (response.status === 404) {
            return null;
        }
        if (!response.ok) {
            throw new Error(`Failed to fetch the changes (${response.status})`);
        }
        return response.json();
    }

    /**
     * Sends a request and returns the decoded JSON, using the API error message on failure
     * @param {string} url - Request URL
     * @param {Object} options - fetch options
     * @param {string} errorLabel - Error message prefix
     * @returns {Promise<any>} Decoded JSON response
     * @throws {Error} If request fails
     */
    async function request(url, options, errorLabel) {
        const response = await authFetch(url, { ...options, headers: getHeaders() });
        const text = await response.text();

        if (!response.ok) {
            throw responseError(response.status, text, `${errorLabel} (${response.status})`);
        }

        return text ? JSON.parse(text) : null;
    }

    /**
     * GET request of a list that cancels the previous one of the same kind
     * (the user changed the month or the tab before the answer arrived)
     * @param {string} key - Kind of request: transactions, budget or insights
     * @param {string} url - Request URL
     * @param {string} errorLabel - Error message prefix
     * @returns {Promise<Array>} The list (empty if the answer is not one)
     * @throws {Error} If request fails (AbortError when cancelled)
     */
    async function cancellable(key, url, errorLabel) {
        controllers[key]?.abort();
        const controller = new AbortController();
        controllers[key] = controller;
        try {
            const data = await request(url, { signal: controller.signal }, errorLabel);
            return Array.isArray(data) ? data : [];
        } finally {
            if (controllers[key] === controller) {
                controllers[key] = null;
            }
        }
    }

    /**
     * Fetches the recurring transactions of a month (found about once a month)
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<Array>} Series: {label, category, amount, average, variable, day, months, last, ids, status}
     */
    async function fetchRecurring(month, year) {
        const data = await request(`${baseUrl}/transaction/recurring?month=${month}&year=${year}`, {}, 'Failed to fetch the recurring transactions');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Fetches the transactions of a category (sub-categories included) for a month
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @param {number} category - Category ID
     * @param {boolean} [own=false] - Only the transactions put on the category itself, not on its sub-categories
     * @returns {Promise<Array>} Array of transaction objects
     */
    async function fetchCategoryTransactions(month, year, category, own = false) {
        const url = `${baseUrl}/transaction?month=${month}&year=${year}&category=${encodeURIComponent(category)}${own ? '&own=1' : ''}`;
        const data = await request(url, {}, 'Failed to fetch transactions');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Searches transactions by label and by the criteria of the advanced search
     * @param {Object<string, string>} params - Parameters (searchParams() of searchModule.js):
     *                                          query, min, max, kind, from, to, categories, accounts
     * @returns {Promise<Array>} Array of transaction objects, most recent first
     * @throws {Error} If request fails
     */
    async function searchTransactions(params) {
        const url = `${baseUrl}/transaction/search?${new URLSearchParams(params)}`;
        const data = await request(url, {}, 'Failed to search transactions');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Reads a bank statement file and tells which transactions are new
     * @param {string} file - Content of the file, base64 encoded
     * @param {string} filename - Name of the file
     * @returns {Promise<Object>} {format, account, from, to, counts, rows}
     */
    async function previewImport(file, filename) {
        return request(`${baseUrl}/transaction/import/preview`, {
            method: 'POST',
            body: JSON.stringify({ file, filename })
        }, 'Failed to read the file');
    }

    /**
     * Imports transactions of a bank statement file
     * @param {string} file - Content of the file, base64 encoded
     * @param {string} filename - Name of the file
     * @param {Array<number>} selected - Indexes of the rows to import
     * @param {number|null} [account=null] - Account of the transactions (id of GET /bank); by default
     *                                       the account number read in the file
     * @returns {Promise<Object>} {imported, categorized, from, to}
     */
    async function importTransactions(file, filename, selected, account = null) {
        return request(`${baseUrl}/transaction/import`, {
            method: 'POST',
            body: JSON.stringify(account === null ? { file, filename, selected } : { file, filename, selected, account })
        }, 'Failed to import the file');
    }

    /**
     * Points (checks) or unpoints a transaction
     * @param {number} id - Transaction ID
     * @param {boolean} pointed - New pointed status
     * @returns {Promise<void>}
     * @throws {Error} If request fails
     */
    async function setTransactionPointed(id, pointed) {
        const url = `${baseUrl}/transaction/pointed`;
        await request(url, {
            method: 'PUT',
            body: JSON.stringify({ id, pointed })
        }, 'Failed to update transaction');
    }

    /**
     * Fetches the devices of the authenticated user
     * @returns {Promise<Array>} Array of device objects
     * @throws {Error} If request fails
     */
    async function fetchDevices() {
        const data = await request(`${baseUrl}/device`, {}, 'Failed to fetch devices');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Whether a newer release of Carbure is published (administrators)
     * @returns {Promise<Object>} {enabled, current, latest, url, available, checked_at}
     */
    async function fetchRelease() {
        return request(`${baseUrl}/system/release`, {}, 'Failed to read the latest release');
    }

    /**
     * Version of the database schema and migrations to apply (administrators)
     * @returns {Promise<Object>} {version, pending}
     */
    async function fetchSchemaStatus() {
        return request(`${baseUrl}/system/schema`, {}, 'Failed to read the schema version');
    }

    /**
     * Renews the secret of the sessions (administrators): every session ends
     * @returns {Promise<void>}
     */
    async function rotateJwtSecret() {
        await request(`${baseUrl}/system/jwt-secret`, { method: 'POST' }, 'Failed to renew the secret');
    }

    /**
     * Months that have transactions, newest first
     * @returns {Promise<Array>} Array of {year, month}
     */
    async function fetchPeriods() {
        const data = await request(`${baseUrl}/transaction/periods`, {}, 'Failed to load the periods');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Log files of the instance (administrators)
     * @returns {Promise<Array>} Array of {name, size, modified}
     */
    async function fetchLogFiles() {
        const data = await request(`${baseUrl}/system/logs`, {}, 'Failed to list the log files');
        return Array.isArray(data) ? data : [];
    }

    /**
     * How long the log files are kept (administrators)
     * @returns {Promise<Object>} {days} (0: kept forever)
     */
    async function fetchLogRetention() {
        return request(`${baseUrl}/system/logs/retention`, {}, 'Failed to read the retention of the logs');
    }

    /**
     * Entries of a log file, newest first (administrators)
     * @param {string} file - File name
     * @param {string} level - Minimum level
     * @param {string} search - Text or request uid
     * @param {number} limit - Maximum number of entries
     * @returns {Promise<Object>} {file, entries, truncated}
     */
    async function fetchLogEntries(file, level, search, limit) {
        const query = new URLSearchParams({ file, level, search, limit: String(limit) });
        return request(`${baseUrl}/system/logs/entries?${query}`, {}, 'Failed to read the log file');
    }

    /**
     * Applies the pending migrations (administrators)
     * @returns {Promise<Object>} {version, applied}
     */
    async function migrateSchema() {
        return request(`${baseUrl}/system/migrate`, { method: 'POST' }, 'Failed to update the database');
    }

    /**
     * Fetches the insights with their SQL (administrators)
     * @returns {Promise<Array>} Array of {id, name, color, sql}
     */
    async function fetchInsightDefinitions() {
        const data = await request(`${baseUrl}/insight`, {}, 'Failed to fetch the insights');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates or modifies an insight (the query is checked by the server)
     * @param {Object} insight - {id (null for a new one), name, color, icon ('' : chosen from the name), sql}
     * @returns {Promise<Object>} The insight with the amount of the current month
     */
    async function saveInsight(insight) {
        const { id, name, color, sql } = insight;
        const icon = insight.icon || null;
        return id
            ? request(`${baseUrl}/insight`, { method: 'PUT', body: JSON.stringify({ id, name, color, sql, icon }) }, 'Failed to modify the insight')
            : request(`${baseUrl}/insight`, { method: 'POST', body: JSON.stringify({ name, color, sql, icon }) }, 'Failed to create the insight');
    }

    /**
     * Checks an insight query (syntax, rules) and computes it for a month
     * @param {string} sql - The query
     * @param {number} month - Month
     * @param {number} year - Year
     * @returns {Promise<Object>} {valid, amount} or {valid: false, error}
     */
    async function checkInsight(sql, month, year) {
        return request(`${baseUrl}/insight/check`, { method: 'POST', body: JSON.stringify({ sql, month, year }) }, 'Failed to check the query');
    }

    /**
     * Fetches the insights disabled, offered by the + button of the Insights tab
     * @returns {Promise<Array>} Array of {id, name, color, icon}
     */
    async function fetchDisabledInsights() {
        const data = await request(`${baseUrl}/insight/disabled`, {}, 'Failed to fetch the insights');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Shows an insight in the Insights tab, or hides it (for the whole household)
     * @param {number} id - Insight ID
     * @param {boolean} enabled - True to show it
     * @returns {Promise<Object>} {id, enabled}
     */
    async function setInsightEnabled(id, enabled) {
        return request(`${baseUrl}/insight/enabled`, { method: 'PUT', body: JSON.stringify({ id, enabled }) }, 'Failed to change the insight');
    }

    /**
     * Saves the order of the insights shown in the Insights tab (for the whole household)
     * @param {Array<number>} ids - The insights, in their new order
     * @returns {Promise<Array<number>>} The ids of the shown insights, in their order
     */
    async function setInsightOrder(ids) {
        return request(`${baseUrl}/insight/order`, { method: 'PUT', body: JSON.stringify({ ids }) }, 'Failed to order the insights');
    }

    /**
     * Fetches the alerts of the user on the enabled insights
     * @returns {Promise<Array>} Array of {insight, direction, threshold}
     */
    async function fetchInsightAlerts() {
        const data = await request(`${baseUrl}/insight/alert`, {}, 'Failed to fetch the insight alerts');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Sets the alert of the user on an insight, replacing the previous one
     * @param {number} insight - Insight ID
     * @param {string} direction - above or below
     * @param {number} threshold - Amount compared with the absolute value of the month
     * @returns {Promise<Object>} {insight, direction, threshold}
     */
    async function setInsightAlert(insight, direction, threshold) {
        return request(`${baseUrl}/insight/alert`, { method: 'PUT', body: JSON.stringify({ insight, direction, threshold }) }, 'Failed to save the insight alert');
    }

    /**
     * Removes the alert of the user on an insight
     * @param {number} insight - Insight ID
     * @returns {Promise<void>}
     */
    async function deleteInsightAlert(insight) {
        await request(`${baseUrl}/insight/alert?insight=${encodeURIComponent(insight)}`, { method: 'DELETE' }, 'Failed to remove the insight alert');
    }

    /**
     * Deletes an insight
     * @param {number} id - Insight ID
     * @returns {Promise<void>}
     */
    async function deleteInsight(id) {
        await request(`${baseUrl}/insight?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete the insight');
    }

    /**
     * State of the MCP server
     * @returns {Promise<Object>} {enabled}
     */
    async function fetchMcpSettings() {
        return request(`${baseUrl}/mcp/settings`, {}, 'Failed to read the MCP server state');
    }

    /**
     * Enables or disables the MCP server (administrators)
     * @param {boolean} enabled - New state
     * @returns {Promise<Object>} {enabled}
     */
    async function updateMcpSettings(enabled) {
        return request(`${baseUrl}/mcp/settings`, { method: 'PUT', body: JSON.stringify({ enabled }) }, 'Failed to change the MCP server state');
    }

    /**
     * Unlocks a user locked after too many failed logins (administrators)
     * @param {number} id - User ID
     * @returns {Promise<void>}
     */
    async function unlockUser(id) {
        await request(`${baseUrl}/user/unlock`, { method: 'POST', body: JSON.stringify({ id }) }, 'Failed to unlock the user');
    }

    /**
     * Fetches the API tokens of the authenticated user (without the tokens themselves)
     * @returns {Promise<Array>} Array of {id, name, token_hint, created_at, last_used_at}
     */
    async function fetchApiTokens() {
        const data = await request(`${baseUrl}/token`, {}, 'Failed to fetch the API tokens');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates an API token (its value is returned only this time)
     * @param {string} name - Name to recognize it
     * @param {number} days - Lifetime in days (0: never expires)
     * @returns {Promise<Object>} {id, name, token, token_hint, expires_at}
     */
    async function createApiToken(name, days = 0) {
        return request(`${baseUrl}/token`, { method: 'POST', body: JSON.stringify({ name, days }) }, 'Failed to create the API token');
    }

    /**
     * Revokes an API token
     * @param {number} id - Token ID
     * @returns {Promise<void>}
     */
    async function deleteApiToken(id) {
        await request(`${baseUrl}/token?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to revoke the API token');
    }

    /**
     * Configuration file of the instance, by section (administrators)
     * @returns {Promise<Object>} {file, writable, sections}
     */
    async function fetchConfig() {
        return request(`${baseUrl}/system/config`, {}, 'Failed to read the configuration');
    }

    /**
     * Changes settings of the configuration file (administrators)
     * @param {Object} values - New values by key
     * @returns {Promise<Object>} The configuration
     */
    async function updateConfig(values) {
        return request(`${baseUrl}/system/config`, { method: 'PUT', body: JSON.stringify({ values }) }, 'Failed to save the configuration');
    }

    /**
     * Request that starts the bank synchronization (administrators)
     * @param {boolean} reveal - Return the token itself
     * @returns {Promise<Object>} {url, token, masked, accounts}
     */
    async function fetchSyncInfo(reveal = false) {
        return request(`${baseUrl}/system/sync?reveal=${reveal ? 'true' : 'false'}`, {}, 'Failed to read the synchronization settings');
    }

    /**
     * Replaces the synchronization token (administrators)
     * @returns {Promise<Object>} {token}
     */
    async function renewSyncToken() {
        return request(`${baseUrl}/system/sync-token`, { method: 'POST' }, 'Failed to renew the token');
    }

    /**
     * Sends a test email with the SMTP server of the configuration (administrators)
     * @param {string} to - Recipient ('' for the address of the administrator)
     * @returns {Promise<Object>} {sent, to}
     */
    async function sendTestEmail(to = '') {
        const body = to ? { to } : {};
        return request(`${baseUrl}/system/smtp-test`, { method: 'POST', body: JSON.stringify(body) }, 'Failed to send the test email');
    }

    /**
     * The relay of the push service and the consent of the administrators to it
     * @returns {Promise<Object>} {url, official, registered, api_key, consent: {at, url}|null, consent_valid, config_writable}
     */
    async function fetchPushInfo() {
        return request(`${baseUrl}/system/push`, {}, 'Failed to load the push service');
    }

    /**
     * Status of the push service: health of the relay and status of the API key
     * @returns {Promise<Object>} {relay: {reachable, status, version, error}, key: {state, key_id, devices, used_today, limits, error}}
     */
    async function fetchPushStatus() {
        return request(`${baseUrl}/system/push-status`, {}, 'Failed to load the status of the push service');
    }

    /**
     * Registers the instance on the push service and saves its API key, or renews the
     * key already saved (administrators)
     * @param {boolean} [consent] - The administrator accepts the push service (required to
     *                              register, and to renew for another relay)
     * @returns {Promise<Object>} {registered, renewed, key_id, limits}
     */
    async function registerPush(consent = false) {
        const options = consent ? { method: 'POST', body: JSON.stringify({ consent: true }) } : { method: 'POST' };
        return request(`${baseUrl}/system/push-register`, options, 'Failed to register on the push service');
    }

    /**
     * Sends a test push notification to the devices of the administrator
     * @returns {Promise<Object>} {sent, devices, results: [{device, sent, status, error}]}
     */
    async function sendTestPush() {
        return request(`${baseUrl}/system/push-test`, { method: 'POST' }, 'Failed to send the test notification');
    }

    /**
     * Channels of each kind of notification of the authenticated user
     * @returns {Promise<Object>} {preferences: {kind: {push, email}}, smtp, address, email_available,
     *          push_available, budget_threshold, budget_all, budget_thresholds: [{category, threshold}],
     *          alert_threshold, budget_category, budgets: [{id, name}]}
     */
    async function fetchNotifications() {
        return request(`${baseUrl}/user/notifications`, {}, 'Failed to read the notifications');
    }

    /**
     * Changes channels of kinds of notification of the authenticated user
     * @param {Object} preferences - {kind: {push?, email?}} (booleans)
     * @returns {Promise<Object>} The notifications, as fetchNotifications
     */
    async function updateNotifications(preferences) {
        return request(`${baseUrl}/user/notifications`, { method: 'PUT', body: JSON.stringify({ preferences }) }, 'Failed to save the notifications');
    }

    /**
     * Changes settings of the alerts of the authenticated user, the others kept
     * @param {Object} settings - alertThreshold (amount of a large expense, '' turns it
     *                            off) and/or budgetCategory (budget watched, '' for all)
     * @returns {Promise<Object>} The notifications, as fetchNotifications
     */
    async function updateAlertSettings(settings) {
        const body = {};
        for (const key of ['alertThreshold', 'budgetCategory']) {
            if (settings[key] !== undefined && settings[key] !== null) {
                body[key] = String(settings[key]);
            }
        }
        return request(`${baseUrl}/user/notifications`, { method: 'PUT', body: JSON.stringify(body) }, 'Failed to save the notifications');
    }

    /**
     * Changes the budget alert thresholds of the authenticated user, those not sent kept
     * @param {Object} settings - budgetAll (the global threshold is on, for all the budgets)
     *                            and/or budgetThresholds ([{category, threshold}], the
     *                            thresholds of categories, replacing the previous ones)
     * @returns {Promise<Object>} The notifications, as fetchNotifications
     */
    async function updateBudgetAlerts(settings) {
        const body = {};
        if (settings.budgetAll !== undefined) {
            body.budgetAll = Boolean(settings.budgetAll);
        }
        if (settings.budgetThresholds !== undefined) {
            body.budgetThresholds = settings.budgetThresholds.map(({ category, threshold }) => ({ category: String(category), threshold: String(threshold) }));
        }
        return request(`${baseUrl}/user/notifications`, { method: 'PUT', body: JSON.stringify(body) }, 'Failed to save the notifications');
    }

    /**
     * Changes the budget alert threshold of the authenticated user
     * @param {number} threshold - Percent of a budget whose spending alerts (50 to 200)
     * @returns {Promise<Object>} The notifications, as fetchNotifications
     */
    async function updateBudgetThreshold(threshold) {
        return request(`${baseUrl}/user/notifications`, { method: 'PUT', body: JSON.stringify({ budgetThreshold: String(threshold) }) }, 'Failed to save the notifications');
    }

    /**
     * Money flow of a month (income sources, expenses, savings)
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<Object>} {income, expenses, savings, totalIncome, totalExpenses, balance}
     */
    async function fetchBudgetFlow(month, year) {
        return request(`${baseUrl}/budget/flow?month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`, {}, 'Failed to fetch the money flow');
    }

    /**
     * Transactions behind a node of the money flow diagram
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @param {string} kind - income, expense or savings
     * @param {Array<number>} categories - Top-level category ids (empty: all)
     * @returns {Promise<Array>} Transactions, most recent first
     */
    async function fetchFlowTransactions(month, year, kind, categories = []) {
        const params = new URLSearchParams({ month, year, kind });
        if (categories.length) {
            params.set('categories', categories.join(','));
        }
        const data = await request(`${baseUrl}/budget/flow/transactions?${params}`, {}, 'Failed to fetch the transactions');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Fetches the monthly trends of the household
     * @param {number} months - Number of months
     * @param {number} offset - Months between the current month and the end of the period
     * @returns {Promise<Array>} Array of {month, debit, credit, offBudget, planned, savings}
     * @throws {Error} If request fails
     */
    async function fetchTrends(months = 12, offset = 0) {
        const data = await request(`${baseUrl}/budget/trends?months=${months}&offset=${offset}`, {}, 'Failed to fetch trends');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Fetches the monthly series of a category (sub-categories included) or of an insight
     * @param {string} type - 'category' or 'insight'
     * @param {number} id - Category or insight id
     * @param {number} months - Number of months
     * @param {number} offset - Months between the current month and the end of the period
     * @returns {Promise<Array>} Array of {month, amount, planned}, oldest first
     * @throws {Error} If request fails
     */
    async function fetchTrendSeries(type, id, months = 12, offset = 0) {
        const params = new URLSearchParams({ type, id: String(id), months: String(months), offset: String(offset) });
        const data = await request(`${baseUrl}/budget/trends/series?${params}`, {}, 'Failed to fetch the series');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Fetches the monthly amounts of the categories of a type, to compare them on one chart
     * @param {string} type - 'DEBIT', 'CREDIT' or 'HORS-BUDGET'
     * @param {string} level - 'top' (sub-categories counted in their parent) or 'sub'
     * @param {number} months - Number of months
     * @param {number} offset - Months between the current month and the end of the period
     * @returns {Promise<{months: Array<string>, categories: Array<Object>}>} The months (oldest
     *          first) and the categories {id, name, icon, color, parent_category, total, amounts}
     * @throws {Error} If request fails
     */
    async function fetchTrendCategories(type = 'DEBIT', level = 'top', months = 12, offset = 0) {
        const params = new URLSearchParams({ type, level, months: String(months), offset: String(offset) });
        const data = await request(`${baseUrl}/budget/trends/categories?${params}`, {}, 'Failed to fetch the categories');
        return {
            months: Array.isArray(data?.months) ? data.months : [],
            categories: Array.isArray(data?.categories) ? data.categories : []
        };
    }

    /**
     * Fetches the custom charts of the Trends tab
     * @returns {Promise<Array>} Array of {type, id, name, icon, color}
     * @throws {Error} If request fails
     */
    async function fetchTrendCharts() {
        const data = await request(`${baseUrl}/budget/trends/charts`, {}, 'Failed to fetch the charts');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Replaces the custom charts of the Trends tab (administrators)
     * @param {Array<{type: string, id: number}>} charts - Charts in display order
     * @returns {Promise<Array>} The saved charts: {type, id, name, icon, color}
     * @throws {Error} If request fails
     */
    async function saveTrendCharts(charts) {
        const data = await request(`${baseUrl}/budget/trends/charts`, { method: 'PUT', body: JSON.stringify({ charts }) }, 'Failed to save the charts');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Sets the category of a transaction (the transaction is checked too)
     * @param {number} id - Transaction ID
     * @param {number} category - Category ID
     * @returns {Promise<void>}
     */
    /**
     * Deletes a transaction (not brought back by the synchronization nor an import)
     * @param {number} id - Transaction id
     * @returns {Promise<void>}
     */
    async function deleteTransaction(id) {
        await request(`${baseUrl}/transaction?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete the transaction');
    }

    async function setTransactionCategory(id, category) {
        await request(`${baseUrl}/transaction/category`, {
            method: 'PUT',
            body: JSON.stringify({ id, category: Number(category) })
        }, 'Failed to update the category');
    }

    /**
     * Sets one category on several transactions at once, and checks them
     * @param {Array<number>} ids - Transaction ids
     * @param {number} category - Category id (0: uncategorized)
     * @returns {Promise<{updated: number}>}
     */
    async function setTransactionsCategory(ids, category) {
        return request(`${baseUrl}/transaction/categories`, {
            method: 'PUT',
            body: JSON.stringify({ ids, category: Number(category) })
        }, 'Failed to update the categories');
    }

    /**
     * Changes the date a transaction is counted on (its month); the real date of the
     * bank is kept, the parts of a split transaction follow it
     * @param {number} id - Transaction id
     * @param {string} date - New date (YYYY-MM-DD)
     * @returns {Promise<Object>} The transaction saved
     */
    async function setTransactionDate(id, date) {
        return request(`${baseUrl}/transaction/date`, {
            method: 'PUT',
            body: JSON.stringify({ id, date })
        }, 'Failed to change the date of the transaction');
    }

    /**
     * Sets the free-text comment of a transaction
     * @param {number} id - Transaction id
     * @param {string} comment - The comment (500 characters at most), '' removes it
     * @returns {Promise<Object>} The transaction saved
     * @throws {Error} If request fails (409 before the database migration)
     */
    async function setTransactionComment(id, comment) {
        return request(`${baseUrl}/transaction/comment`, {
            method: 'PUT',
            body: JSON.stringify({ id, comment })
        }, 'Failed to change the comment of the transaction');
    }

    /**
     * Enters a transaction by hand on a manual account
     * @param {{account: number, date: string, label: string, amount: number, category?: number, comment?: string}} transaction - The transaction
     * @returns {Promise<Object>} The transaction saved
     */
    async function createTransaction(transaction) {
        return request(`${baseUrl}/transaction`, { method: 'POST', body: JSON.stringify(transaction) }, 'Failed to add the transaction');
    }

    /**
     * Changes a transaction entered by hand
     * @param {{id: number, date: string, label: string, amount: number, comment?: string}} transaction - The new values ('' removes the comment, absent keeps it)
     * @returns {Promise<Object>} The transaction saved
     */
    async function updateTransaction(transaction) {
        return request(`${baseUrl}/transaction`, { method: 'PUT', body: JSON.stringify(transaction) }, 'Failed to modify the transaction');
    }

    /**
     * Tags of the household, the active ones first
     * @returns {Promise<Array<Object>>}
     */
    async function fetchTags() {
        const data = await request(`${baseUrl}/tag`, {}, 'Failed to fetch the tags');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates a tag
     * @param {{name: string, color: string, start_date: string, end_date: string|null}} tag - The tag
     * @returns {Promise<Object>} The tag
     */
    async function createTag(tag) {
        return request(`${baseUrl}/tag`, { method: 'POST', body: JSON.stringify(tag) }, 'Failed to create the tag');
    }

    /**
     * Changes a tag
     * @param {{id: number, name: string, color: string, start_date: string, end_date: string|null}} tag - The tag
     * @returns {Promise<Object>} The tag
     */
    async function updateTag(tag) {
        return request(`${baseUrl}/tag`, { method: 'PUT', body: JSON.stringify(tag) }, 'Failed to modify the tag');
    }

    /**
     * Deletes a tag (its transactions only lose it)
     * @param {number} id - Tag id
     * @returns {Promise<void>}
     */
    async function deleteTag(id) {
        await request(`${baseUrl}/tag?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete the tag');
    }

    /**
     * Summary of a tag: total, by category, transactions, day by day
     * @param {number} id - Tag id
     * @returns {Promise<Object>}
     */
    async function fetchTagSummary(id) {
        return request(`${baseUrl}/tag/summary?id=${encodeURIComponent(id)}`, {}, 'Failed to fetch the summary of the tag');
    }

    /**
     * Adds or removes tags on transactions
     * @param {Array<number>} ids - Transaction ids
     * @param {Array<number>} add - Tags to add
     * @param {Array<number>} remove - Tags to remove
     * @returns {Promise<{updated: number}>}
     */
    async function setTransactionTags(ids, add = [], remove = []) {
        return request(`${baseUrl}/transaction/tags`, { method: 'PUT', body: JSON.stringify({ ids, add, remove }) }, 'Failed to tag the transactions');
    }

    /**
     * Splits a transaction across several categories (replaces its previous parts)
     * @param {number} id - Transaction id
     * @param {Array<{category: number, amount: number}>} parts - Parts, with the sign of the transaction; none cancels the split
     * @returns {Promise<Array<Object>>} The transaction, then its parts
     */
    async function splitTransaction(id, parts) {
        return request(`${baseUrl}/transaction/split`, {
            method: 'PUT',
            body: JSON.stringify({ id, parts })
        }, 'Failed to split the transaction');
    }

    /**
     * Fetches the bank accounts of the household (administrators)
     * @returns {Promise<Array>} Array of {id, bankId, account_number, bank_name, user_id, username,
     *                           last_sync_at, last_sync_status, last_sync_message}
     */
    async function fetchBankAccounts() {
        const data = await request(`${baseUrl}/bank/accounts`, {}, 'Failed to fetch bank accounts');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Follows a bank account
     * @param {string} account_number - Account identifier in woob
     * @param {string} bank_name - woob backend name
     * @param {string} label - Name given to the account ('' for none)
     * @returns {Promise<Object>} The account
     */
    async function createBankAccount(account_number, bank_name, label = '') {
        return request(`${baseUrl}/bank`, { method: 'POST', body: JSON.stringify({ account_number, bank_name, label }) }, 'Failed to add the account');
    }

    /**
     * Accounts of the household (every user), the manual ones flagged
     * @returns {Promise<Array<{id: number, account_number: string, bank_name: string, label: string|null, manual: boolean}>>}
     */
    async function fetchAccounts() {
        const data = await request(`${baseUrl}/bank`, {}, 'Failed to fetch the accounts');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Adds a manual account (never synchronized, its transactions entered by hand)
     * @param {string} label - Name of the account
     * @param {string} [account_number] - Its number (generated when empty)
     * @returns {Promise<Object>} The account
     */
    async function createManualAccount(label, account_number = '') {
        return request(`${baseUrl}/bank/manual`, { method: 'POST', body: JSON.stringify({ label, account_number }) }, 'Failed to add the account');
    }

    /**
     * Modifies a followed bank account
     * @param {number} id - Account ID
     * @param {string} account_number - Account identifier in woob
     * @param {string} bank_name - woob backend name
     * @param {string} label - Name given to the account ('' removes it)
     * @returns {Promise<Object>} The account
     */
    async function updateBankAccount(id, account_number, bank_name, label = '') {
        return request(`${baseUrl}/bank`, { method: 'PUT', body: JSON.stringify({ id, account_number, bank_name, label }) }, 'Failed to modify the account');
    }

    /**
     * Stops following a bank account (its transactions are kept)
     * @param {number} id - Account ID
     * @returns {Promise<void>}
     */
    async function deleteBankAccount(id) {
        await request(`${baseUrl}/bank?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete the account');
    }

    /**
     * Bank backends configured in woob
     * @returns {Promise<Array>} Array of {name, module}
     */
    async function fetchBankBackends() {
        const data = await request(`${baseUrl}/bank/backends`, {}, 'Failed to list the woob banks');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Banks supported by woob
     * @returns {Promise<Array>} Array of {module, description}
     */
    async function fetchBankModules() {
        const data = await request(`${baseUrl}/bank/modules`, {}, 'Failed to list the banks supported by woob');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Settings asked by a woob bank module (to configure the bank from the portal)
     * @param {string} module - woob module
     * @returns {Promise<Object>} {module, description, fields}
     */
    async function fetchBankModuleFields(module) {
        return request(`${baseUrl}/bank/module?module=${encodeURIComponent(module)}`, {}, 'Failed to read the settings of the bank');
    }

    /**
     * Configures a bank in woob with its settings (credentials kept by woob only)
     * @param {string} module - woob module
     * @param {string} backend - Name of the bank in woob
     * @param {Object} settings - Settings of the module
     * @param {string} label - Name given to the bank connection (empty: none)
     * @returns {Promise<Object>} {backend, label, accounts}
     */
    async function createBankBackend(module, backend, settings, label = '') {
        return request(`${baseUrl}/bank/backend`, { method: 'POST', body: JSON.stringify({ module, backend, settings, label }) }, 'Failed to configure the bank');
    }

    /**
     * Names a bank connection (woob backend)
     * @param {string} backend - Name of the bank in woob
     * @param {string} label - Name given to the bank connection (empty: removed)
     * @returns {Promise<Object>} {name, label}
     */
    async function renameBankBackend(backend, label) {
        return request(`${baseUrl}/bank/backend`, { method: 'PUT', body: JSON.stringify({ backend, label }) }, 'Failed to rename the bank connection');
    }

    /**
     * Removes a bank connection (woob backend) with its followed accounts (transactions kept)
     * @param {string} backend - Name of the bank in woob
     * @returns {Promise<void>}
     */
    async function deleteBankBackend(backend) {
        await request(`${baseUrl}/bank/backend?backend=${encodeURIComponent(backend)}`, { method: 'DELETE' }, 'Failed to remove the bank connection');
    }

    /**
     * Accounts available in the banks configured in woob (can take a while)
     * @param {string|null} backend - Only the accounts of this bank connection (woob backend)
     * @returns {Promise<Array>} Array of {bankId, account_number, bank_name, label, balance, currency, followed}
     */
    async function discoverBankAccounts(backend = null) {
        const query = backend ? `?backend=${encodeURIComponent(backend)}` : '';
        const data = await request(`${baseUrl}/bank/discover${query}`, {}, 'Failed to query woob');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Accounts of one bank connection, with a two-factor authentication allowed: woob may
     * ask a validation in the bank app first (Server-Sent Events, can take minutes)
     * @param {string} backend - Bank connection (woob backend)
     * @param {Function} onMessage - Called with each message of the bank
     * @param {AbortSignal|null} signal - Stops the request (the modal is closed)
     * @returns {Promise<Array>} Array of {bankId, account_number, bank_name, label, balance, currency, followed}
     * @throws {Error} If the request fails, woob fails or the answer ends without the accounts
     */
    async function discoverBankAccountsInteractive(backend, onMessage, signal = null) {
        const response = await authFetch(`${baseUrl}/bank/discover/interactive?backend=${encodeURIComponent(backend)}`, { headers: getHeaders(), signal });
        if (!response.ok || !response.body) {
            throw responseError(response.status, await response.text().catch(() => ''), `Failed to query woob (${response.status})`);
        }

        let accounts = null;
        let failure = null;
        await readEvents(response, data => {
            const bankMessage = data.match(/^Bank message .+?: (.*)$/);
            if (data.startsWith('Accounts ')) {
                accounts = JSON.parse(data.slice('Accounts '.length));
            } else if (data.startsWith('Discovery failed: ')) {
                failure = data.slice('Discovery failed: '.length);
            } else if (bankMessage) {
                onMessage(bankMessage[1]);
            }
        });
        if (failure !== null) {
            throw new Error(failure);
        }
        if (!Array.isArray(accounts)) {
            throw new Error('The connection to the bank was interrupted');
        }
        return accounts;
    }

    /**
     * Starts a bank synchronization and reports each progress message.
     * The API answers with Server-Sent Events: "data: <message>" blocks, and
     * ": heartbeat" comments while woob is working.
     * @param {Function} onMessage - Called with each progress message
     * @param {string|null} account - Synchronize only this account (bankId)
     * @param {boolean} interactive - Allow the bank to ask a two-factor authentication (one account)
     * @returns {Promise<void>} Resolved when the synchronization ends
     * @throws {Error} If the request fails
     */
    async function syncBanks(onMessage, account = null, interactive = false) {
        let query = account ? `?account=${encodeURIComponent(account)}` : '';
        if (account && interactive) {
            query += '&interactive=true';
        }
        const response = await authFetch(`${baseUrl}/bank/sync${query}`, { headers: getHeaders() });

        if (!response.ok || !response.body) {
            throw responseError(response.status, await response.text().catch(() => ''), `Failed to start the synchronization (${response.status})`);
        }

        await readEvents(response, onMessage);
    }

    /**
     * Repository of the woob bank modules: trusted or not, fingerprint of its keyring
     * @returns {Promise<{repository: string, trusted: boolean, fingerprint: (string|null), error: (string|null)}>}
     */
    async function fetchWoobStatus() {
        return request(`${baseUrl}/bank/woob`, {}, 'Failed to read the woob repository');
    }

    /**
     * Updates the woob bank modules and reports each line of woob (Server-Sent Events).
     * The last message is "Woob updated", "Woob repository not trusted: <fingerprint>"
     * or "Woob update failed: <cause>".
     * @param {Function} onMessage - Called with each progress message
     * @param {string|null} fingerprint - Fingerprint of the keyring trusted by the administrator
     * @returns {Promise<void>} Resolved when the update ends
     * @throws {Error} If the request fails
     */
    async function updateWoob(onMessage, fingerprint = null) {
        const query = fingerprint ? `?fingerprint=${encodeURIComponent(fingerprint)}` : '';
        const response = await authFetch(`${baseUrl}/bank/woob/update${query}`, { headers: getHeaders() });
        if (!response.ok || !response.body) {
            throw responseError(response.status, await response.text().catch(() => ''), `Failed to update woob (${response.status})`);
        }
        await readEvents(response, onMessage);
    }

    /**
     * Reads a Server-Sent Events answer: "data: <message>" blocks, ": heartbeat"
     * comments ignored.
     * @param {Response} response - Answer of the API
     * @param {Function} onMessage - Called with each message
     * @returns {Promise<void>} Resolved at the end of the answer
     */
    async function readEvents(response, onMessage) {
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        for (;;) {
            const { value, done } = await reader.read();
            buffer += decoder.decode(value || new Uint8Array(), { stream: !done });

            // Events are separated by a blank line
            let end;
            while ((end = buffer.indexOf('\n\n')) >= 0) {
                const event = buffer.slice(0, end);
                buffer = buffer.slice(end + 2);
                const data = event.split('\n')
                    .filter(line => line.startsWith('data: '))
                    .map(line => line.slice(6))
                    .join('\n');
                if (data) {
                    onMessage(data);
                }
            }

            if (done) {
                return;
            }
        }
    }

    /**
     * Fetches all the categories
     * @returns {Promise<Array>} Array of categories
     */
    async function fetchCategories() {
        const data = await request(`${baseUrl}/category`, {}, 'Failed to fetch categories');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates a category
     * @param {Object} category - {name, type, parent_category, icon, color}
     * @returns {Promise<Object>} The category
     */
    async function createCategory(category) {
        return request(`${baseUrl}/category`, { method: 'POST', body: JSON.stringify(category) }, 'Failed to create the category');
    }

    /**
     * Modifies a category
     * @param {Object} category - {id, name, type, parent_category, icon, color}
     * @returns {Promise<Object>} The category
     */
    async function updateCategory(category) {
        return request(`${baseUrl}/category`, { method: 'PUT', body: JSON.stringify(category) }, 'Failed to modify the category');
    }

    /**
     * Deletes a category (its transactions become uncategorized)
     * @param {number} id - Category ID
     * @returns {Promise<void>}
     */
    async function deleteCategory(id) {
        await request(`${baseUrl}/category?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete the category');
    }

    /**
     * Fetches the automatic categorization rules
     * @returns {Promise<Array>} Array of {id, keyword, category, category_name, notify, amount (null: any amount)}
     */
    async function fetchRules() {
        const data = await request(`${baseUrl}/category/keyword`, {}, 'Failed to fetch rules');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Creates an automatic categorization rule
     * @param {string} keyword - Text to find in the labels
     * @param {number} category - Category to set
     * @param {boolean} notify - Notify the household when a new transaction matches
     * @param {string|number|null} [amount] - Only the transactions of this amount, in absolute value (empty: any amount)
     * @returns {Promise<Object>} Created rule
     */
    async function createRule(keyword, category, notify = false, amount = '') {
        return request(`${baseUrl}/category/keyword`, {
            method: 'POST',
            body: JSON.stringify({ keyword, category: Number(category), notify: !!notify, amount: ruleAmount(amount) })
        }, 'Failed to create rule');
    }

    /**
     * Modifies an automatic categorization rule
     * @param {number} id - Rule ID
     * @param {string} keyword - Text to find in the labels
     * @param {number} category - Category to set
     * @param {boolean} notify - Notify the household when a new transaction matches
     * @param {string|number|null} [amount] - Only the transactions of this amount, in absolute value (empty: any amount)
     * @returns {Promise<Object>} The rule
     */
    async function updateRule(id, keyword, category, notify, amount = '') {
        return request(`${baseUrl}/category/keyword`, {
            method: 'PUT',
            body: JSON.stringify({ id, keyword, category: Number(category), notify: !!notify, amount: ruleAmount(amount) })
        }, 'Failed to modify the rule');
    }

    /**
     * Amount of a rule as sent to the API: '' for any amount (a PUT without amount keeps it)
     * @param {string|number|null} amount - The amount typed ("10", "10,50"...)
     * @returns {string} The amount, or ''
     */
    function ruleAmount(amount) {
        return amount === null || amount === undefined ? '' : String(amount).trim();
    }

    /**
     * Number of transactions whose label contains the keyword of a rule
     * @param {number} id - Rule ID
     * @returns {Promise<Object>} {matching, categorized}
     */
    async function countRule(id) {
        return request(`${baseUrl}/category/keyword/count?id=${encodeURIComponent(id)}`, {}, 'Failed to count the transactions');
    }

    /**
     * Deletes an automatic categorization rule
     * @param {number} id - Rule ID
     * @returns {Promise<void>}
     */
    async function deleteRule(id) {
        await request(`${baseUrl}/category/keyword?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete rule');
    }

    /**
     * Applies the rules to the transactions of the database (the categorized ones
     * too, unless the rules keep them)
     * @returns {Promise<{updated: number}>}
     */
    async function applyRules() {
        return request(`${baseUrl}/category/keyword/apply`, { method: 'POST' }, 'Failed to apply rules');
    }

    /**
     * Reads the settings of the rules
     * @returns {Promise<{keep_categorized: boolean}>}
     */
    async function fetchRuleSettings() {
        return request(`${baseUrl}/category/keyword/settings`, {}, 'Failed to read the rule settings');
    }

    /**
     * Lets the rules recategorize the categorized transactions of the history or not (administrators)
     * @param {boolean} keepCategorized - True to leave the categorized transactions alone
     * @returns {Promise<{keep_categorized: boolean}>}
     */
    async function updateRuleSettings(keepCategorized) {
        return request(`${baseUrl}/category/keyword/settings`,
            { method: 'PUT', body: JSON.stringify({ keep_categorized: keepCategorized }) }, 'Failed to change the rule settings');
    }

    /**
     * Public address of the API given to the mobile apps (QR code of the devices)
     * @returns {Promise<{server: (string|null)}>} null without the setting public_url
     */
    async function fetchDeviceLink() {
        return request(`${baseUrl}/device/link`, {}, 'Failed to read the address of the apps');
    }

    /**
     * Deletes a device of the authenticated user
     * @param {number} id - Device ID
     * @returns {Promise<void>}
     * @throws {Error} If request fails
     */
    async function deleteDevice(id) {
        await request(`${baseUrl}/device?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to delete device');
    }

    /**
     * Web browsers from which the authenticated user logged in, the last used first
     * @returns {Promise<Array>} [{id, label, created_at, last_login, current}]
     */
    async function fetchBrowsers() {
        const data = await request(`${baseUrl}/user/browser`, {}, 'Failed to fetch browsers');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Forgets a web browser of the authenticated user (its session is not closed)
     * @param {number} id - Browser ID
     * @returns {Promise<void>}
     */
    async function deleteBrowser(id) {
        await request(`${baseUrl}/user/browser?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to remove the browser');
    }

    /**
     * Two-factor authentication of the authenticated user
     * @returns {Promise<Object>} {available, required, enabled, pending, recovery_codes_left, trusted_browsers}
     */
    async function fetchMfa() {
        return request(`${baseUrl}/user/mfa`, {}, 'Failed to read the two-factor authentication');
    }

    /**
     * Starts the registration of an authenticator app
     * @returns {Promise<{secret: string, otpauth: string}>}
     */
    async function setupMfa() {
        return request(`${baseUrl}/user/mfa/setup`, { method: 'POST' }, 'Failed to start the registration');
    }

    /**
     * Confirms the authenticator app with its first code
     * @param {string} code - Code of the app
     * @returns {Promise<{enabled: boolean, recovery_codes: string[]}>}
     */
    async function enableMfa(code) {
        return request(`${baseUrl}/user/mfa/enable`, { method: 'POST', body: JSON.stringify({ code }) }, 'Failed to enable the two-factor authentication');
    }

    /**
     * Disables the two-factor authentication (code of the app or recovery code, in the body)
     * @param {string} code - Code
     * @returns {Promise<{enabled: boolean}>}
     */
    async function disableMfa(code) {
        return request(`${baseUrl}/user/mfa`, { method: 'DELETE', body: JSON.stringify({ code }) }, 'Failed to disable the two-factor authentication');
    }

    /**
     * New recovery codes (the previous ones no longer work)
     * @param {string} code - Code of the app
     * @returns {Promise<{recovery_codes: string[]}>}
     */
    async function renewRecoveryCodes(code) {
        return request(`${baseUrl}/user/mfa/recovery-codes`, { method: 'POST', body: JSON.stringify({ code }) }, 'Failed to renew the recovery codes');
    }

    /**
     * Revokes a trusted browser of the authenticated user
     * @param {number} id - Trusted browser ID
     * @returns {Promise<void>}
     */
    async function deleteTrustedBrowser(id) {
        await request(`${baseUrl}/user/mfa/browser?id=${encodeURIComponent(id)}`, { method: 'DELETE' }, 'Failed to revoke the browser');
    }

    /**
     * Resets the two-factor authentication of a user (administrators)
     * @param {number} id - User ID
     * @returns {Promise<void>}
     */
    async function resetUserMfa(id) {
        await request(`${baseUrl}/user/mfa/reset`, { method: 'POST', body: JSON.stringify({ id }) }, 'Failed to reset the two-factor authentication');
    }

    /**
     * Agent asking for an OAuth authorization (consent page)
     * @param {string} oauthRequest - Authorization request (query string given to the portal)
     * @returns {Promise<{name: string, redirect_host: string}>}
     */
    async function fetchOAuthClient(oauthRequest) {
        return request(`${baseUrl}/oauth/client?request=${encodeURIComponent(oauthRequest)}`, {}, 'Invalid authorization request');
    }

    /**
     * Answers an OAuth authorization request
     * @param {string} oauthRequest - Authorization request
     * @param {boolean} approved - True if the user allows the agent
     * @returns {Promise<{redirect: string}>} Where the browser goes back to the agent
     */
    async function approveOAuth(oauthRequest, approved) {
        return request(`${baseUrl}/oauth/approve`, {
            method: 'POST',
            body: JSON.stringify({ request: oauthRequest, approved })
        }, 'Failed to answer the authorization request');
    }

    /**
     * Fetches budget data for a given month and year
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @param {number} [categoryId] - Optional category ID for children budgets; the list then also
     *                                has an item (own: true) for the transactions put on the category itself
     * @returns {Promise<Array>} Array of budget objects
     * @throws {Error} If request fails
     */
    async function fetchBudget(month, year, categoryId = null) {
        const category = categoryId !== null ? `&category=${encodeURIComponent(categoryId)}&own=1` : '';
        return cancellable('budget', `${baseUrl}/budget?month=${month}&year=${year}${category}`, 'Failed to fetch budget');
    }
    
    /**
     * Sets the budget of a category for a month (0 for a parent category: sum of its sub-categories)
     * @param {number} categoryId - Category ID
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @param {number} amount - Budget amount
     * @param {string} [note] - Reminder of what the budget covers ('' removes it, undefined keeps it)
     * @returns {Promise<void>}
     * @throws {Error} If request fails
     */
    async function updateBudget(categoryId, month, year, amount, note) {
        await request(`${baseUrl}/budget`, {
            method: 'POST',
            body: JSON.stringify({ category: categoryId, amount, month, year, note })
        }, 'Failed to update budget');
    }

    /**
     * Copies the budgets of the previous month to the given month
     * @param {number} month - Target month (1-12)
     * @param {number} year - Target year
     * @param {boolean} overwrite - True to replace the budgets already set in the target month
     * @returns {Promise<Object>} {month, source, copied, overwrite}
     * @throws {Error} If request fails
     */
    async function copyBudgets(month, year, overwrite = false) {
        return request(`${baseUrl}/budget/copy`, {
            method: 'POST',
            body: JSON.stringify({ month, year, overwrite })
        }, 'Failed to copy the budgets');
    }

    /**
     * Tells whether the copy of the budgets of the previous month is offered for a month:
     * the month has no budget defined and the previous month has some (GET /budget/copy)
     * @param {number} month - Target month (1-12)
     * @param {number} year - Target year
     * @returns {Promise<Object>} {month, source, defined, source_defined, copyable}
     * @throws {Error} If request fails
     */
    async function fetchBudgetCopyStatus(month, year) {
        return request(`${baseUrl}/budget/copy?month=${encodeURIComponent(month)}&year=${encodeURIComponent(year)}`, {}, 'Failed to fetch the budget copy status');
    }
    
    /**
     * Fetches insights data for a given month and year
     * @param {number} month - Month (1-12)
     * @param {number} year - Year
     * @returns {Promise<Array>} Array of insight objects
     * @throws {Error} If request fails
     */
    async function fetchInsights(month, year) {
        return cancellable('insights', `${baseUrl}/budget/insights?month=${month}&year=${year}`, 'Failed to fetch insights');
    }
    
    /**
     * Fetches all users
     * @returns {Promise<Array>} Array of user objects
     * @throws {Error} If request fails
     */
    async function fetchUsers() {
        const data = await request(`${baseUrl}/user`, {}, 'Failed to fetch users');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Fetches the authenticated user profile
     * @returns {Promise<Object>} User object
     * @throws {Error} If request fails
     */
    async function fetchMe() {
        return request(`${baseUrl}/user/me`, {}, 'Failed to fetch profile');
    }

    /**
     * Creates a new user
     * @param {string} username - Username
     * @param {string} password - Password
     * @param {string} email - Email
     * @param {string} firstname - First name (optional)
     * @param {string} lastname - Last name (optional)
     * @returns {Promise<Object>} Created user object
     * @throws {Error} If request fails
     */
    async function createUser(username, password, email, firstname, lastname, isAdmin = false) {
        return request(`${baseUrl}/user`, {
            method: 'POST',
            body: JSON.stringify({ username, password, email, firstname, lastname, is_admin: !!isAdmin })
        }, 'Failed to create user');
    }

    /**
     * Deletes a user by id
     * @param {number} userId - User ID to delete
     * @returns {Promise<void>}
     * @throws {Error} If request fails
     */
    async function deleteUser(userId) {
        return request(`${baseUrl}/user?id=${encodeURIComponent(userId)}`, { method: 'DELETE' }, 'Failed to delete user');
    }

    /**
     * Updates an existing user
     * @param {number} userId - User ID to update
     * @param {string} email - Email
     * @param {string} firstname - First name (optional)
     * @param {string} lastname - Last name (optional)
     * @param {string} password - New password (optional)
     * @param {string} language - Interface language (optional, 'fr' or 'en')
     * @param {number|string} alertThreshold - Large expense alert threshold (optional, '' disables)
     * @param {boolean} isAdmin - Administrator profile (optional, administrators only)
     * @param {string} theme - Display theme (optional, 'system', 'light' or 'dark')
     * @returns {Promise<Object>} Updated user object
     * @throws {Error} If request fails
     */
    async function updateUser(userId, email, firstname, lastname, password, language, alertThreshold, isAdmin, theme) {
        const url = `${baseUrl}/user?id=${userId}`;
        const body = { email, firstname, lastname };

        // Display theme of the portal (system, light, dark): sent only by the profile
        if (theme) {
            body.theme = theme;
        }

        if (isAdmin !== undefined && isAdmin !== null) {
            body.is_admin = !!isAdmin;
        }

        if (language) {
            body.language = language;
        }

        if (alertThreshold !== undefined && alertThreshold !== null) {
            body.alertThreshold = String(alertThreshold);
        }
        
        // Only include password if provided
        if (password && password.trim() !== '') {
            body.password = password;
        }

        return request(`${baseUrl}/user?id=${encodeURIComponent(userId)}`, { method: 'PUT', body: JSON.stringify(body) }, 'Failed to update user');
    }
    
    /**
     * Saves the display theme of a user alone (header toggle), the other fields unchanged
     * @param {number} userId - User ID
     * @param {string} theme - Display theme ('system', 'light' or 'dark')
     * @returns {Promise<Object>} Updated user object
     * @throws {Error} If request fails
     */
    async function updateUserTheme(userId, theme) {
        return request(`${baseUrl}/user?id=${encodeURIComponent(userId)}`, { method: 'PUT', body: JSON.stringify({ theme }) }, 'Failed to update user');
    }

    /**
     * Fetches the datasets of a backup and their number of rows (administrators)
     * @returns {Promise<Array>} [{name, count}]
     * @throws {Error} If request fails
     */
    async function fetchBackupInfo() {
        const data = await request(`${baseUrl}/backup`, {}, 'Failed to fetch the backup datasets');
        return Array.isArray(data) ? data : [];
    }

    /**
     * Exports datasets (administrators)
     * @param {Array<string>} datasets - Names of the datasets
     * @returns {Promise<Object>} {format, version, created_at, carbure, schema, counts, datasets}
     * @throws {Error} If request fails
     */
    async function exportBackup(datasets) {
        return request(`${baseUrl}/backup/export?datasets=${encodeURIComponent(datasets.join(','))}`, {}, 'Failed to export the backup');
    }

    /**
     * Imports datasets, which delete and replace their data (administrators)
     * @param {Object} datasets - Rows by dataset: {categories: [...], ...}
     * @returns {Promise<Object>} {imported: {name: count}}
     * @throws {Error} If request fails (nothing changed)
     */
    async function importBackup(datasets) {
        return request(`${baseUrl}/backup/import`, {
            method: 'POST',
            body: JSON.stringify({ datasets })
        }, 'Failed to import the backup');
    }

    /**
     * Fetches the datasets that can be purged, their number of rows and the datasets
     * purged with them (administrators)
     * @returns {Promise<Object>} {local, datasets: [{name, count, dependents}]}
     * @throws {Error} If request fails
     */
    async function fetchPurgeInfo() {
        const data = await request(`${baseUrl}/purge`, {}, 'Failed to fetch the purge datasets');
        return { local: data?.local !== false, datasets: Array.isArray(data?.datasets) ? data.datasets : [] };
    }

    /**
     * Deletes every row of datasets (administrators, from the local network)
     * @param {Array<string>} datasets - Names of the datasets, their dependents included
     * @returns {Promise<Object>} {purged: {name: count}}
     * @throws {Error} If request fails (nothing deleted)
     */
    async function purgeData(datasets) {
        return request(`${baseUrl}/purge`, {
            method: 'POST',
            body: JSON.stringify({ datasets })
        }, 'Failed to purge the data');
    }

    return {
        abortAllExcept,
        fetchChanges,
        fetchTransactions,
        fetchRecurring,
        fetchBudget,
        fetchOAuthClient,
        approveOAuth,
        updateBudget,
        copyBudgets,
        fetchBudgetCopyStatus,
        fetchInsights,
        fetchUsers,
        fetchMe,
        fetchDevices,
        fetchApiTokens,
        fetchBudgetFlow,
        fetchConfig,
        updateConfig,
        fetchSyncInfo,
        renewSyncToken,
        sendTestEmail,
        fetchPushInfo,
        fetchPushStatus,
        registerPush,
        sendTestPush,
        fetchNotifications,
        updateNotifications,
        updateAlertSettings,
        updateBudgetThreshold,
        updateBudgetAlerts,
        fetchFlowTransactions,
        unlockUser,
        fetchMcpSettings,
        updateMcpSettings,
        fetchSchemaStatus,
        fetchRelease,
        migrateSchema,
        fetchPeriods,
        fetchLogFiles,
        fetchLogRetention,
        fetchLogEntries,
        rotateJwtSecret,
        fetchInsightDefinitions,
        fetchDisabledInsights,
        setInsightEnabled,
        setInsightOrder,
        fetchInsightAlerts,
        setInsightAlert,
        deleteInsightAlert,
        saveInsight,
        deleteInsight,
        checkInsight,
        createApiToken,
        deleteApiToken,
        fetchBankAccounts,
        createBankAccount,
        updateBankAccount,
        deleteBankAccount,
        discoverBankAccounts,
        discoverBankAccountsInteractive,
        fetchBankBackends,
        fetchBankModules,
        fetchBankModuleFields,
        createBankBackend,
        renameBankBackend,
        deleteBankBackend,
        syncBanks,
        fetchWoobStatus,
        updateWoob,
        fetchCategories,
        createCategory,
        updateCategory,
        deleteCategory,
        setTransactionCategory,
        setTransactionsCategory,
        setTransactionDate,
        setTransactionComment,
        splitTransaction,
        createTransaction,
        updateTransaction,
        fetchAccounts,
        createManualAccount,
        fetchTags,
        createTag,
        updateTag,
        deleteTag,
        fetchTagSummary,
        setTransactionTags,
        deleteTransaction,
        fetchRules,
        createRule,
        updateRule,
        countRule,
        deleteRule,
        applyRules,
        fetchRuleSettings,
        updateRuleSettings,
        fetchTrends,
        fetchTrendSeries,
        fetchTrendCategories,
        fetchTrendCharts,
        saveTrendCharts,
        deleteDevice,
        fetchBrowsers,
        deleteBrowser,
        fetchDeviceLink,
        fetchMfa,
        setupMfa,
        enableMfa,
        disableMfa,
        renewRecoveryCodes,
        deleteTrustedBrowser,
        resetUserMfa,
        searchTransactions,
        fetchCategoryTransactions,
        setTransactionPointed,
        previewImport,
        importTransactions,
        createUser,
        updateUser,
        updateUserTheme,
        deleteUser,
        fetchBackupInfo,
        exportBackup,
        importBackup,
        fetchPurgeInfo,
        purgeData
    };
}
