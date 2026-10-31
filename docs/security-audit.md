# Security Audit: Carbure (PHP 8.2+ REST API + Vue 3 Budget/Bank Transaction Tracker)

**Generated:** 2026-10-31  
**Scope:** `src/`, `portal/`, `docker/`, `swagger/`, `tools/`, `sql/`  
**Excluded:** `tests/`, `docs/` (documentation only), `data/` (instance data, not in repo)

---

## Executive Summary

Carbure is a self-hosted household budget/bank transaction tracker with a PHP 8.2+ REST API (no framework) and Vue 3 portal (no bundler). It syncs bank data via **woob** (Python), stores bank credentials in woob's config, uses JWT + TOTP MFA for auth, and exposes a read-only MCP server with OAuth 2.1. The Docker image runs nginx + PHP-FPM + woob on Alpine.

This audit identifies **real attack vectors** for this specific architecture, configuration mistakes users might make (Synology, Docker, manual install), data exposure risks (bank credentials, transactions, PII), and supply chain risks (woob, Docker base images, PHP packages).

---

## 1. PHP-Specific Vulnerabilities

### 1.1 SQL Injection — **LOW RISK (Mitigated)**

**Finding:** All database queries use prepared statements via `Db::execute()` and `Db::select()` (`src/lib/Db.php:71-124`). No raw query concatenation found in resources.

**Evidence:**
- `Db::execute()` uses `mysqli::prepare()` + `bind_param()` (`src/lib/Db.php:80-105`)
- `Db::select()` wraps `execute()` or `query()` but only for parameterized queries (`src/lib/Db.php:117-124`)
- All resources (`User.php`, `Bank.php`, `Transaction.php`, `Mcp.php`, `OAuth.php`, etc.) use these helpers
- No `Db::query()` with user input found in resource files

**Gap:** `Db::query()` exists and executes raw SQL (`src/lib/Db.php:56-69`). If ever called with user input, it would be vulnerable. Currently only used for schema operations (no params).

### 1.2 XSS (Cross-Site Scripting) — **LOW RISK (Mitigated by Architecture)**

**Finding:** API returns JSON only (`Content-Type: application/json`; `src/lib/Webservice.php:227-245`). Portal consumes JSON and uses Vue 3's built-in escaping (`v-text`, `{{ }}`). No server-side HTML rendering.

**Evidence:**
- `Webservice::securityHeaders()` sets `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'` (`src/lib/Webservice.php:254-265`)
- Portal nginx CSP: `script-src 'self' 'unsafe-eval'` (for Vue template compilation), `style-src 'self' 'unsafe-inline'` (`docker/nginx.conf:51`)
- No `echo` of user input in PHP without JSON encoding
- `json_encode()` with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE` used throughout

**Residual Risk:** `'unsafe-eval'` required for Vue 3 global build (template compilation in browser). Mitigation: CSP restricts to `'self'` + `'unsafe-eval'` only.

### 1.3 Deserialization / Object Injection — **LOW RISK**

**Finding:** No `unserialize()` calls found. JSON input decoded via `json_decode($raw, true)` (associative arrays, not objects) in `Webservice::getPayload()` (`src/lib/Webservice.php:132-133`).

**Evidence:**
- `json_decode(..., true)` forces associative arrays
- No `phar://` or `unserialize()` in codebase
- `File::read()` used for file reads (`src/lib/File.php` not read but referenced)

### 1.4 File Inclusion (LFI/RFI) — **LOW RISK**

**Finding:** No dynamic `include`/`require` with user input. Class loading via PSR-4 `ClassLoader` (`src/lib/ClassLoader.php` not read but per AGENTS.md). Config files loaded via `parse_ini_file()` on fixed paths (`src/lib/Config.php:58`).

**Evidence:**
- `ClassLoader::register()` uses `spl_autoload_register` with fixed namespace-to-path mapping
- `Config::load()` only reads `data/conf/<env>.ini` or `conf/<env>.ini` (`src/lib/Config.php:32-42`)
- No user-supplied paths in file operations

### 1.5 Command Injection (PHP → Shell) — **MEDIUM RISK (Via woob)**

**Finding:** PHP executes woob via `proc_open()` with `escapeshellarg()` for arguments (`src/lib/Woob.php:172, 208, 372, 395, 737`). Bank credentials **never** passed on command line (written to woob config file instead: `Woob.php:454-492`).

**Evidence:**
- All woob commands use `escapeshellarg()` for user-controlled values (`Woob.php:172, 208, 372, 735`)
- Bank credentials written to `~/.config/woob/backends` file (INI format) via `Woob::addBackend()` (`Woob.php:454-492`)
- `UNSAFE_SETTING` regex rejects backticks and control chars in config values (`Woob.php:49`)
- `BACKEND_NAME` and `MODULE_NAME` regexes restrict to alphanum/underscore/hyphen (`Woob.php:40, 43`)

**Residual Risk:** `woob_path` config setting (`Config::get('woob_path')`) could contain shell metacharacters if admin sets malicious value. Default: `env HOME=/data/woob woob` (`docker/Dockerfile:84`). No validation of `woob_path` beyond `preg_match('/\bHOME=(\S+)/'`.

---

## 2. Authentication/Authorization Flaws

### 2.1 JWT Implementation — **MEDIUM RISK**

**Finding:** Custom JWT implementation (HS256, 30-day expiry) in `src/lib/Jwt.php`. Uses `hash_hmac('sha256', ...)` with `hash_equals()` for constant-time comparison (`Jwt.php:136-147`).

**Issues:**
| Issue | Location | Severity |
|-------|----------|----------|
| No `nbf` (not before) or `jti` (JWT ID) claims | `Jwt.php:52-56` | Low |
| 30-day expiry (long for JWT) | `Jwt.php:49-50` | Medium |
| No token revocation list (except password change revokes MFA trusted browsers) | `User.php:466-468` | Medium |
| `jwtsecret` generated once at install; if weak/default, all tokens forgeable | `docs/securite.md:333-338` | High if misconfigured |
| Accepts token without `Bearer` prefix as fallback | `Jwt.php:166-167` | Low |

**Mitigations present:**
- Constant-time signature verification via `hash_equals()` (`Jwt.php:146`)
- `jwtsecret` random at install (`Setup.php:356-360`)
- Admin banner warns if default `jwtsecret` used (`docs/securite.md:333-338`)

### 2.2 MFA (TOTP) — **LOW RISK (Well-Implemented)**

**Finding:** TOTP per RFC 6238, HMAC-SHA1, 30s step, 6 digits, 160-bit secret (`docs/securite.md:26-31`). Implemented in `src/lib/Totp.php` (not read but described).

**Strengths:**
- Constant-time code comparison
- Previous/next step accepted for clock skew
- One-time use per step (last accepted step stored)
- 5-min challenge flow: password step returns challenge, code sent to `/user/login/mfa` (`docs/securite.md:32-37`)
- Failed codes count toward account lockout (5 failures = 24h lock)
- Trusted browsers: 256-bit token in `HttpOnly; SameSite=Strict; Secure` cookie, SHA-256 stored in DB (`docs/securite.md:48-55`)
- Known devices (iOS APNs token): only after MFA success; revoked on password change/MFA reset (`docs/securite.md:56-68`)

**Gap:** TOTP secret stored **in plaintext** in `user_mfa` table (`docs/securite.md:77-79`). DB backups = secret exposure. No encryption at rest.

### 2.3 Account Lockout — **LOW RISK**

**Finding:** 5 failed logins → 24h lock (`User.php:207-248`). Counted in DB (`failed_logins` column) with atomic increment (`User.php:240`). Docker nginx adds rate limit: 30 req/min per client IP on login routes (`docker/nginx.conf:22, 113-118`).

**Gap:** Behind proxy, `TRUSTED_PROXIES` defaults to all private networks (`docs/securite.md:105`). A LAN device can spoof `X-Forwarded-For` to bypass rate limit (`docs/securite.md:115-120`).

### 2.4 Password Handling — **LOW RISK**

**Finding:** `password_hash()` (bcrypt) for new; legacy salted SHA-256 upgraded at login (`User.php:178-202`). Min 8 chars for admin at install (`Setup.php:250-252`).

**Gap:** No password complexity requirements beyond length. No breach checking (HaveIBeenPwned).

### 2.5 Authorization — **LOW RISK**

**Finding:** Route-level `admin: true` attribute checked in `Webservice::checkAccess()` before method call (`src/lib/Webservice.php:203-219`). Unit test `tests/unit/ApiResolverTest.php` pins access level of every route (`AGENTS.md`).

**Evidence:**
- Non-admins: `GET /user` returns only own profile (`User.php:296-315`)
- Admin actions: `User::requireAdmin()` called in methods for conditional checks (`User.php:284-289`)
- Last admin cannot be demoted/deleted (`User.php:494-498`)

### 2.6 Session Handling — **LOW RISK**

**Finding:** Stateless JWT (no server-side sessions). Trusted browser cookies are separate (MFA bypass only, not full auth). `sync_token` for scheduler: header preferred, URL param deprecated (`Bank.php:750-790`).

**Gap:** No JWT refresh token rotation (30-day static expiry). No concurrent session limit.

---

## 3. woob/Python Subprocess Risks

### 3.1 Credential Handling — **LOW RISK (Well-Designed)**

**Finding:** Bank credentials **never** on command line. Written to woob config file (`~/.config/woob/backends`) with `chmod 600` (`Woob.php:454-492, 515`). `UNSAFE_SETTING` rejects backticks (woob executes backtick values as shell commands) (`Woob.php:49`).

**Evidence:**
- `Woob::addBackend()` writes INI section to file, not CLI (`Woob.php:454-492`)
- `Woob::path()` enforces `chmod 700` on `.config/woob` and `chmod 600` on `backends` file (`Woob.php:75-87`)
- Rejects values with backticks, control chars, multi-line (`Woob.php:486-488`)

### 3.2 Command Injection via woob Args — **LOW RISK**

**Finding:** All user inputs to woob CLI escaped via `escapeshellarg()`:
- Backend name: `escapesharg($backend)` (`Woob.php:172, 208, 372`)
- Module name: `escapeshellarg($module)` (`Woob.php:372, 395`)
- Account ID: `escapeshellarg($bankId)` (`Woob.php:735`)
- Regex validation on backend/module/account names (`Woob.php:40, 43, 101-103`)

### 3.3 woob Repository Trust (Supply Chain) — **HIGH RISK IF MISCONFIGURED**

**Finding:** woob verifies module signatures via GPG keyring. **First run trusts keyring automatically** (`*** ASSUMING YES ***`) if no terminal (`WoobRepository.php:30-31, 81-86`). Carbure blocks woob until admin confirms fingerprint in portal (`WoobRepository.php:120-138`).

**Attack Vector:** If admin runs `woob config update` manually in container (as root or www-data) **before** trusting via portal, woob auto-trusts the keyring. Malicious keyring → malicious modules signed → RCE when module runs.

**Evidence:**
- `WoobRepository::requireTrustBeforeFirstRun()` throws 409 if `sources.list` missing and no trusted keyring (`WoobRepository.php:81-86`)
- Admin must click "Trust and update" in portal after verifying SHA-1 fingerprint (`docs/securite.md:378-389`)
- Container runs `woob config update` at startup **only after** trusted (`entrypoint.sh:35-47`)

**Gap:** If `woob_path` env var modified to point to malicious woob binary, or if `/opt/woob` compromised in image build.

### 3.4 woob Module Code Execution — **MEDIUM RISK**

**Finding:** woob modules are Python code downloaded from `https://updates.woob.tech/3/main/` (signed). Modules parse bank HTML/APIs. A compromised module = RCE as `www-data`.

**Mitigations:**
- GPG signature verification (enforced after admin trusts keyring)
- Modules run in woob venv (`/opt/woob`) with limited Python packages
- No browser automation modules (selenium) in image (`Dockerfile:21-22`)

### 3.5 Interactive 2FA (pty) — **LOW RISK**

**Finding:** `proc_open()` with `pty` for interactive 2FA (`Woob.php:807-808`). Bank sends challenge, user approves in app. Messages streamed via SSE to portal (`Woob.php:139, 159, 669-677`).

**Risk:** Pseudo-terminal could be abused if woob output not sanitized. `emitMessages()` strips ANSI codes (`Woob.php:782`).

---

## 4. Docker/Container Security

### 4.1 Base Image — **LOW RISK**

**Finding:** `php:8.5.12-fpm-alpine` (explicit version, not `latest`). Alpine = smaller attack surface. `apk upgrade --no-cache` at build (`Dockerfile:50`).

**Gap:** PHP 8.5.12 may have unpatched CVEs by deploy time. Weekly Trivy scan on published image (`docs/securite.md:362-365`).

### 4.2 Non-Root User — **PARTIAL**

**Finding:** PHP-FPM runs as `www-data` (UID 82). nginx master as root, workers as `www-data`. Entrypoint runs as root, then `su -s /bin/sh www-data` for woob update and migrate (`entrypoint.sh:36, 59`).

**Risk:** Entrypoint runs `php tools/migrate.php` as `www-data` (good). But `woob_update()` runs as `www-data` via `su` (good). Volume `/data` owned by `www-data` (`entrypoint.sh:10, 14`).

### 4.3 Filesystem Permissions — **GOOD**

**Finding:** `/data/woob` chmod `u+rwX,go-rwx` (`entrypoint.sh:14`). woob refuses to start if backends file group/other readable (`Woob.php:75-87`).

### 4.4 Network Exposure — **CONFIGURATION DEPENDENT**

**Finding:** Container exposes port 80 only. HTTPS expected at reverse proxy (Traefik, Caddy, NAS proxy). `TRUSTED_PROXIES` configures real IP detection (`docs/securite.md:96-126`).

**Misconfiguration Risks:**
| Scenario | Risk |
|----------|------|
| Published directly (no proxy) with `TRUSTED_PROXIES` unset | Private IPs trusted → LAN device can spoof `X-Forwarded-For` to bypass rate limit (`docs/securite.md:115-120`) |
| Proxy doesn't send `X-Forwarded-For` | All clients share rate limit; real IP not logged |
| `TRUSTED_PROXIES=none` but behind proxy | Real client IP not seen; rate limit ineffective |

### 4.5 Secrets in Image — **NONE FOUND**

**Finding:** `prod.ini` (contains `jwtsecret`, `push_api_key`, DB password) in `/data/conf/` volume, not image. `.dockerignore` excludes `data/` (`Dockerfile.dockerignore`). SBOM + provenance attestation published (`docs/securite.md:426-455`).

### 4.6 Healthcheck — **LOW RISK**

**Finding:** `curl -fsS http://localhost/api/health` every 5 min (`Dockerfile:90-91`). `/api/health` is public (`docs/securite.md:267`).

---

## 5. Setup/Install Wizard Exposure

### 5.1 First-Run Exposure — **MEDIUM RISK**

**Finding:** Without `data/conf/prod.ini`, API only serves `/api/setup` (`src/api.php:9-15`). Accessible from **local network without code** (`Setup.php:190-196`). From Internet (public IP) or if `setup.code` exists → requires code.

**Attack Vectors:**
| Vector | Description |
|--------|-------------|
| LAN attacker reaches uninstalled Carbure | Can run install wizard, set admin password, take over instance |
| `setup.code` not created, exposed to Internet | Same — but `Setup::codeRequired()` checks all `X-Forwarded-For` IPs; if any public → code required (`Setup.php:376-390`) |
| Proxy doesn't forward real IP | `REMOTE_ADDR` = proxy IP (private) → code not required → Internet attacker can install |

**Mitigation:** `docker/entrypoint.sh` prints code to logs (`Setup.php:366`). Admin can pre-create `data/conf/setup.code` (`docs/securite.md:198-204`).

### 5.2 Re-Triggering Setup — **LOW RISK**

**Finding:** Once `prod.ini` exists, setup routes return 503 (`src/api.php:9-15`). No "reinstall" endpoint. Deleting `prod.ini` requires filesystem access.

### 5.3 Default Credentials — **LOW RISK**

**Finding:** No defaults. Admin user/password created at install (`Setup.php:224-227`). Demo image has `demo/carbure` (`portal/app.js:51`) but separate image (`docker/demo/`).

---

## 6. Bank Credential Storage

### 6.1 Where Stored — **woob Config File**

**Location:** `/data/woob/.config/woob/backends` (INI format, `chmod 600`)

**Format:**
```ini
[backend_name]
_module = module_name
login = "username"
password = "password"
website = "url"
```

### 6.2 Encryption — **NONE (Plaintext)**

**Finding:** Credentials stored in plaintext in woob config file. woob requires plaintext to authenticate to banks.

**Risk:** If `/data` volume compromised (host access, backup leak, container escape), all bank credentials exposed.

**Mitigations:**
- File `chmod 600`, directory `chmod 700` (`Woob.php:75-87`, `entrypoint.sh:14`)
- Never logged (`Webservice::maskSensitive()` masks `settings`, `password`, `token` fields `src/lib/Webservice.php:397-400`)
- Never passed on CLI (not in `/proc/<pid>/cmdline`) (`docs/securite.md:321-327`)
- `data/` excluded from git (`.gitignore` implied by `docs/securite.md:329`)

### 6.3 Sync Token — **PLAINTEXT IN CONFIG**

**Finding:** `sync_token` in `prod.ini` (plaintext). Used in `X-Sync-Token` header (`Bank.php:750-790`). URL param `?token=` deprecated but accepted (logged in nginx access log unless masked).

**Risk:** If `prod.ini` leaked, scheduler can trigger sync (read-only mostly, but could cause DoS via repeated sync).

### 6.4 Push API Key — **PLAINTEXT IN CONFIG**

**Finding:** `push_api_key` in `prod.ini`. Used to authenticate to Carbure Push relay (`PushRelay.php:67-70`).

---

## 7. API Security

### 7.1 Rate Limiting — **PARTIAL**

| Endpoint | Limit | Implementation |
|----------|-------|----------------|
| `POST /user/login` | 30 req/min/IP | nginx `limit_req_zone` (`docker/nginx.conf:22, 113-118`) |
| `POST /user/login/mfa` | 30 req/min/IP | Same zone |
| `POST /oauth/token` | 30 req/min/IP | Same zone |
| All other API routes | **None** | — |

**Gap:** No rate limiting on:
- `GET /bank/sync` (public with `sync_token`) — could DoS woob/bank
- `POST /transaction/import` (large file upload, 2MB default, 32MB for backup)
- MCP `/api/mcp` (read-only but could enumerate data)
- OAuth endpoints (registration, authorize)

### 7.2 CORS — **NOT CONFIGURED (Same-Origin Only)**

**Finding:** No CORS headers in PHP. Portal and API same origin (nginx serves both). `Cross-Origin-Resource-Policy: same-origin` on API (`Webservice.php:264`).

**Risk:** If API ever served on different origin, would break. Current architecture: not needed.

### 7.3 Security Headers — **COMPREHENSIVE**

**API (PHP):** `Webservice::securityHeaders()` (`src/lib/Webservice.php:254-265`):
- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: no-referrer`
- `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`
- `Cross-Origin-Resource-Policy: same-origin`
- `Cache-Control: no-store`
- `X-Powered-By` removed

**Portal (nginx):** `docker/nginx.conf:51-67`:
- CSP allows `'self'`, `'unsafe-eval'` (Vue), `'unsafe-inline'` (styles), `data:` images
- `X-Frame-Options: DENY`
- `Permissions-Policy`: camera, mic, geo, payment, USB disabled
- `Cross-Origin-Opener-Policy: same-origin`

**Swagger:** Framed by portal only (`frame-ancestors 'self'`).

### 7.4 Input Validation — **GOOD (Attribute-Based)**

**Finding:** Route params injected by name into static methods. Unknown param → 400 (`AGENTS.md`). Type coercion from strings (no `strict_types`).

**Validation Layers:**
1. Route attribute declares method signature
2. `Webservice::call()` merges `$_GET` + JSON body, passes by name (`src/lib/Webservice.php:309-322`)
3. PHP coerces types (e.g., `"123"` → `int`)
4. Resources validate business logic (e.g., `Bank::validateAccount()` regex `Bank.php:98-103`)

**Gap:** No schema validation (OpenAPI only for docs). Boolean params: `"false"` string → `true` in PHP. AGENTS.md warns: "never a bare `bool`".

### 7.5 Error Handling — **GOOD**

**Finding:** `Error` (HTTP code) vs `Exception` (500). `ApiError` adds stable `error_code` + `params` for i18n (`src/lib/ApiError.php` not read but per `AGENTS.md`). Stack traces logged without args (`Webservice.php:45-49`). `zend.exception_ignore_args=On` in `php.ini` (`docker/php.ini` not read but per `docs/securite.md:283-284`).

---

## 8. MCP/OAuth Security

### 8.1 MCP Server — **READ-ONLY, TOKEN-GATED**

**Finding:** `/api/mcp` (POST only, JSON-RPC 2.0). Auth: API token (user-created, SHA-256 stored) or JWT (`Mcp.php:71-89, 211-234`). Tools: read-only (`list_categories`, `list_transactions`, `search_transactions`, `spending_by_category`, `get_budget`, `get_trends`, `list_rules`) (`Mcp.php:268-339`).

**Security Controls:**
- Disabled by default (`Setting::get('mcp_enabled')` `Mcp.php:162-165`)
- Origin check: rejects `Origin` header not matching server host or `claude.ai`/`claude.com` (`Mcp.php:189-201`) — DNS rebinding protection
- API tokens: 192-bit random, prefix `cbt_`, only SHA-256 stored, expiry options, revocable (`docs/securite.md:232-241`)
- No write tools

### 8.2 OAuth 2.1 for MCP — **STANDARD COMPLIANT**

**Finding:** Implements RFC 8414, 7591, 9728, PKCE S256 mandatory (`OAuth.php:99-101, 498-500`). Public client registration (no secret by default) (`OAuth.php:116-151`).

**Controls:**
- PKCE S256 required (`OAuth.php:425-428`)
- Auth codes: 5-min lifetime, single-use, SHA-256 hashed (`OAuth.php:415-428`)
- Refresh tokens: rotation (old revoked on use) (`OAuth.php:439-448`)
- Client registration limited: 100 max, unused purged after 1 day (`OAuth.php:193-198`)
- Redirect URIs: HTTPS only, or HTTP on localhost (`OAuth.php:577-588`)
- User consent required via portal (`OAuth.php:277-294`)

**Gap:** Client registration open (per MCP spec). No admin approval for new clients. Malicious agent could register, trick user into authorizing.

### 8.3 Public Routes — **MINIMAL & AUDITED**

**Finding:** Only these public (no JWT): `POST /user/login`, `POST /user/login/mfa`, `GET /bank/sync` (JWT or `sync_token`), `POST|GET /mcp` (token or JWT), OAuth metadata/endpoints, `GET /health` (`docs/securite.md:252-267`). Unit test fails on new public route (`AGENTS.md`).

---

## 9. Push Notification Relay Security

### 9.1 Architecture — **EXTERNAL SERVICE**

**Finding:** Push relay at `https://api.carbure.org` (private repo `ltoinel/Carbure-push`). Instance registers via proof-of-work (SHA-256 leading zeros, max 2^26 ~1 min) (`PushRelay.php:35, 166-177`). Gets `cpk_` API key (SHA-256 stored in `prod.ini`).

### 9.2 Data Sent to Relay — **MINIMAL**

**Finding:** `sendToMultiple()` sends: device tokens (APNs/FCM), title, body, badge, custom data (`PushRelay.php:84-102`). **No bank data, no PII** except what admin puts in notification title/body.

### 9.3 Transport Security — **HTTPS ONLY**

**Finding:** `curl` to `https://api.carbure.org` with 5s connect / 30s timeout (`PushRelay.php:329-335`). No cert pinning.

### 9.4 Relay Compromise Impact — **LIMITED**

**If relay compromised:**
- Attacker can send arbitrary push notifications to registered devices
- Cannot read bank data (relay doesn't have it)
- Cannot access Carbure API (different credentials)
- Instance can `rotate()` key (`PushRelay.php:134-142`)

**Risk:** Relay holds APNs/FCM credentials for all instances. High-value target.

---

## 10. Known CVEs in Dependencies

### 10.1 PHP — **8.5.12 (Current as of Build)**

**Base Image:** `php:8.5.12-fpm-alpine` (`Dockerfile:8, 33`)

**Action:** Weekly Trivy scan on published image (`docs/securite.md:362-365`). Rebuild on critical CVE.

**Notable PHP CVEs (2024-2025):**
- CVE-2024-XXXXX: Check PHP security announcements
- `Dockerfile:32` comment references "PHP2Shell (CVE-2026-109618, CVE-2026-109619)" — likely placeholder/future

### 10.2 nginx — **Alpine Package**

**Version:** From `apk upgrade --no-cache` at build (`Dockerfile:50`). Pinned to Alpine version.

### 10.3 MariaDB — **13 (Latest Major)**

**Image:** `mariadb:13` (`docker-compose.yml:40`). `MARIADB_AUTO_UPGRADE=1` (`docker-compose.yml:48`).

### 10.4 woob — **3.7 (Pinned)**

**Version:** `ARG WOOB_VERSION=3.7` (`Dockerfile:10`). Installed in venv with specific Python deps (`Dockerfile:24-28`).

**Python Dependencies (pinned in Dockerfile):**
| Package | Version Constraint | Purpose |
|---------|-------------------|---------|
| `curl_cffi` | `>=0.7` | BNP module |
| `python-jose` | (latest) | Banque Populaire, etc. |
| `chompjs` | (latest) | Banque Populaire, etc. |
| `schwifty` | (latest) | Société Générale |
| `pycryptodomex` | (latest) | HSBC, Esalia, La Nef |

**Risk:** `>=` and unpinned versions → supply chain attack if PyPI compromised. No `pip-audit` in CI.

### 10.5 PHP Dev Dependencies (Composer) — **PHPStan, PHPUnit Only**

**Finding:** Composer only for dev tools (`AGENTS.md`). No runtime PHP dependencies.

### 10.6 Portal JS Dependencies — **VENDORED**

**Finding:** All JS/CSS/fonts vendored in `portal/vendor/`, `swagger/vendor/` (`docs/securite.md:172-180`). No CDN. `tests/portal/localAssets.test.mjs` enforces.

**Vendored Libraries (from `portal/vendor/README.md` not read but per AGENTS.md):**
- Vue 3 global build
- CodeMirror (SQL editor)
- QR code generator
- Fonts

---

## Configuration Mistakes Users Might Make

| Mistake | Impact | Likelihood |
|---------|--------|------------|
| `TRUSTED_PROXIES` unset behind public proxy | Rate limit bypass, wrong client IP in logs | High (Synology, NAS default) |
| No HTTPS reverse proxy | JWT, passwords in cleartext | Medium (self-hosters) |
| Default `jwtsecret` not rotated | Token forgery | Low (banner warns) |
| `sync_token` in URL (`?token=`) | Logged in proxy/shell history | Medium (legacy schedulers) |
| `woob_path` modified to malicious binary | RCE as www-data | Low (requires host access) |
| `prod.ini` backed up to unencrypted location | All secrets exposed | High |
| MariaDB user not restricted to `carbure` DB | Lateral movement if DB compromised | Medium |
| `log_level=debug` in production | Bank data in logs | Low (admin action) |
| MCP enabled without need | Attack surface increase | Low (admin action) |
| OAuth client registered by malicious agent | User tricked into authorizing | Medium (social engineering) |

---

## Data Exposure Risks

| Data | Storage | Encryption | Access Control |
|------|---------|------------|----------------|
| Bank credentials | woob backends file (`/data/woob/.config/woob/backends`) | **None** (plaintext) | File perms 600, woob enforces |
| Transactions | MariaDB (`bank_transaction`) | None | JWT + household model (all users see all) |
| TOTP secrets | MariaDB (`user_mfa.secret`) | **None** (plaintext) | Admin + user only |
| Recovery codes | MariaDB (`user_mfa.recovery_codes` = SHA-256) | Hashed | Admin + user only |
| JWT secret | `prod.ini` (`jwtsecret`) | None | File perms 640 |
| Push API key | `prod.ini` (`push_api_key`) | None | File perms 640 |
| SMTP password | `prod.ini` (`smtp_password`) | None | Masked in logs, not returned to portal |
| Sync token | `prod.ini` (`sync_token`) | None | Header preferred over URL |
| OAuth client secrets | `oauth_clients.client_secret_hash` | SHA-256 | DB only |
| OAuth auth codes | `oauth_codes.code_hash` | SHA-256 | 5-min TTL, single-use |
| Refresh tokens | `api_tokens.refresh_hash` | SHA-256 | Rotation on use |

---

## Supply Chain Risks

| Dependency | Risk | Mitigation |
|------------|------|------------|
| woob modules (Python) | Malicious module = RCE | GPG signatures, admin must trust keyring fingerprint |
| Docker base image (`php:alpine`) | Base image compromise | Pinned version, weekly Trivy, SBOM + provenance |
| Python packages (`curl_cffi`, `python-jose`, etc.) | PyPI compromise | Pinned in Dockerfile (some `>=`), venv isolation |
| GitHub Actions | Compromised action | Commit SHA pinning, Dependabot 7-day delay (`docs/securite.md:459-463`) |
| Carbure Push relay | Centralized push credentials | Instance can rotate key; relay doesn't store bank data |

---

## Recommendations

### High Priority
1. **Encrypt TOTP secrets at rest** — Use envelope encryption (key from `jwtsecret` or separate `encryption_key` in `prod.ini`)
2. **Add rate limiting** on `/bank/sync`, `/transaction/import`, `/mcp`, OAuth endpoints
3. **Pin all Python dependencies** to exact versions in Dockerfile (replace `>=` with `==`)
4. **Add `pip-audit`** to CI for Python supply chain
5. **Document `TRUSTED_PROXIES` clearly** for Synology/NAS users; default to `none` with warning

### Medium Priority
6. **Shorten JWT expiry** (e.g., 15 min access + refresh token) or add token revocation list
7. **Add password strength meter** + optional HaveIBeenPwned check
8. **Validate `woob_path`** config against allowlist or hash
9. **Add CSP `report-uri`** for violation monitoring
10. **Implement concurrent session limit** per user

### Low Priority
11. **Add `jti` + `nbf` to JWT**
12. **Add admin approval** for OAuth client registration (optional setting)
13. **Cert pinning** for Carbure Push relay
14. **Automated dependabot** for Python packages (currently only GitHub Actions)

---

## References

- `src/api.php` — HTTP entry point
- `src/autoload.php` — Bootstrapping
- `src/lib/*.php` — Core libraries (Webservice, Jwt, Woob, Config, Db, Setup, PushRelay, WoobRepository, ApiResolver)
- `src/resources/*.php` — API resources (User, Bank, Transaction, Mcp, OAuth, etc.)
- `docker/Dockerfile` — Container image
- `docker/nginx.conf` — nginx config + rate limiting + CSP
- `docker/entrypoint.sh` — Container startup
- `docker/docker-compose.yml` — Compose stack
- `docs/securite.md` — Security documentation (source of many findings)
- `SECURITY.md` — Security policy
- `AGENTS.md` — Project conventions
- `portal/app.js`, `portal/services/apiService.js` — Portal entry + API client

---

*This audit covers the codebase as of the current commit. Run `composer phpstan`, `composer test`, and Trivy scans for ongoing validation.*