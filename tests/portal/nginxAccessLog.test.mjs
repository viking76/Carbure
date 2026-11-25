// The access log of nginx never writes the secrets of the URL: the sync token (?token=,
// deprecated), the token of the MCP server and the OAuth secrets are replaced by ***
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = fileURLToPath(new URL('../..', import.meta.url));
const conf = readFileSync(join(ROOT, 'docker', 'nginx.conf'), 'utf8');

/**
 * Applies the masking maps of nginx.conf (map $request ... -> $carbure_request) to a
 * request line, as nginx does: the first regex that matches, else the default
 * @param {string} request - Request line ("GET /path?query HTTP/1.1")
 * @returns {string} The request line written in the access log
 */
function loggedRequest(request) {
    const maps = [...conf.matchAll(/^map \$(\S+) \$(\S+) \{\n {4}"~\*([^"]+)" "\$\{pre\}\*\*\*\$\{post\}";\n {4}default \$(\S+);\n\}/gm)];
    assert.equal(maps.length, 2, 'two masking passes');
    let value = request;
    for (const [, , , regex] of maps) {
        // nginx regexes (PCRE) of the conf are also valid JavaScript regexes
        const match = new RegExp(regex, 'i').exec(value);
        if (match) {
            value = `${match.groups.pre}***${match.groups.post}`;
        }
    }
    return value;
}

test('the access log of nginx uses the masked request line', () => {
    assert.match(conf, /^log_format carbure '\$remote_addr - \$remote_user \[\$time_local\] "\$carbure_request" '/m);
    assert.match(conf, /^ {4}access_log \/var\/log\/nginx\/access\.log carbure if=\$carbure_loggable;$/m);
    // Never the raw request line in the format
    assert.doesNotMatch(conf.match(/^log_format carbure[^;]+;/m)[0], /\$request\b/);
});

test('the tokens of the synchronization and of the MCP server are masked', () => {
    assert.equal(loggedRequest('GET /api/bank/sync?token=0123abcd HTTP/1.1'), 'GET /api/bank/sync?token=*** HTTP/1.1');
    assert.equal(loggedRequest('GET /api/bank/sync?account=1%40bnp&token=0123abcd HTTP/1.1'),
        'GET /api/bank/sync?account=1%40bnp&token=*** HTTP/1.1');
    assert.equal(loggedRequest('GET /api/bank/sync?TOKEN=0123abcd&account=1 HTTP/1.1'), 'GET /api/bank/sync?TOKEN=***&account=1 HTTP/1.1');
    assert.equal(loggedRequest('POST /api/mcp?token=cbt_secret HTTP/1.1'), 'POST /api/mcp?token=*** HTTP/1.1');
    // Two secrets in one URL
    assert.equal(loggedRequest('GET /api/x?code=abc&access_token=def HTTP/1.1'), 'GET /api/x?code=***&access_token=*** HTTP/1.1');
});

test('the other requests are logged as they are', () => {
    for (const request of ['GET /api/bank/sync HTTP/1.1', 'GET /api/bank/sync?account=1%40bnp HTTP/1.1',
        'GET /api/transaction?mytoken=1 HTTP/1.1', 'GET /portal/ HTTP/2.0']) {
        assert.equal(loggedRequest(request), request);
    }
});

/**
 * Whether nginx writes a request in its access log (map "$uri:$status" $carbure_loggable)
 * @param {string} uri - Normalized path of the request
 * @param {number} status - HTTP status of the response
 * @returns {boolean} True if the request is written
 */
function logged(uri, status) {
    const map = conf.match(/^map "\$uri:\$status" \$carbure_loggable \{\n {4}"~([^"]+)" 0;\n {4}default 1;\n\}/m);
    assert.ok(map, 'map of the requests written in the access log');
    return !new RegExp(map[1]).test(`${uri}:${status}`);
}

test('the successful health checks are not written in the access log of nginx', () => {
    assert.equal(logged('/api/health', 200), false);
    assert.equal(logged('/api/health', 304), false);
    // Its errors are written, like every other request
    assert.equal(logged('/api/health', 500), true);
    assert.equal(logged('/api/health', 404), true);
    assert.equal(logged('/api/health/x', 200), true);
    assert.equal(logged('/api/healthz', 200), true);
    assert.equal(logged('/api/transaction', 200), true);
    assert.equal(logged('/portal/', 200), true);
});
