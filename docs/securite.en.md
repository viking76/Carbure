---
title: "Security: authentication and bank data"
description: "How Carbure protects household data: passwords, JWT, sign-in lockout, bank credentials kept by woob and Docker image scanning."
icon: material/shield-lock-outline
---

# Security

## Authentication { #authentification }

- `POST /user/login` checks the password and returns an **HS256 JWT** signed with
  `jwtsecret`, valid for **30 days** (`sub` = user ID).
- The other routes require `Authorization: Bearer <token>`; the signature is compared
  in constant time and the expiration is checked.
- Passwords are hashed with `password_hash()` (bcrypt). Legacy salted SHA-256
  hashes are verified one last time, then replaced at sign-in.

### Two-factor authentication (TOTP) { #double-authentification-totp }

An administrator can **require** a second factor at sign-in for all users:
the 6-digit code of an authenticator app (Google
Authenticator, Microsoft Authenticator, 2FAS, Aegis…), `mfa_required` setting in
**Administration › Settings** (disabled by default). A user can also
enable it for themselves only from **My profile**.

- **TOTP** (RFC 6238) implemented in PHP with no dependency: HMAC-SHA1, 30 s step,
  6 digits, random 160-bit secret (`random_bytes`) shared in base32 through a QR code
  (`otpauth://`, drawn in the browser by a bundled library, never by an
  external service). The code of the previous and next step is accepted (phone clock
  drift), compared in constant time; a code can be used **only once**
  (the last accepted step is remembered).
- **Two-step sign-in**: with the right password, `POST /user/login` returns,
  instead of the session, a **challenge** valid for **5 minutes** that only allows sending the
  code to `POST /user/login/mfa`. This challenge is not a JWT (2 parts, signing key
  derived from `jwtsecret` for this sole purpose): it opens no route. **Wrong codes
  count toward the lockout** of the account (5 failures, 24 h), and succeeding with the
  password alone does not reset the counter until the code is given.
- **Enrollment**: a user without an app sets theirs up at this step
  (QR code and text key, confirmed by a first code) or in advance from their
  profile. Each enrollment challenge carries a **new** key (never the one of an
  enrollment already started): someone who obtained it earlier with the password does not keep a
  valid second factor once the user is enrolled, and an already activated app
  is never replaced. The user then receives **10 single-use backup codes** (only their SHA-256
  hash is stored), which replace the code if the phone is lost. An
  administrator **resets** a user's two-factor authentication (Users
  tab); if they lose their own phone without a backup code, they set
  `mfa_required=false` back in `data/conf/prod.ini` on the server.
- **Trusted browser (6 months)**: the “Trust this browser for 6 months” checkbox
  of the code step stores a random 256-bit token in an
  `HttpOnly` cookie (unreadable by the portal's scripts), `SameSite=Strict`, `Secure`
  over HTTPS, restricted to the sign-in routes (`/api/user/login`); the database only keeps
  its SHA-256 hash, with the browser (User-Agent) and the expiry. The user sees
  and revokes their trusted browsers in their profile; they are all revoked when
  their password changes, or when their two-factor authentication is disabled or
  reset.
- **Already known device**: at sign-in, the iOS app sends the device's APNs token
  (64 hexadecimal characters, assigned by Apple, not guessable). If it is already
  registered **for this user** (`devices` table), the code is not requested. This is
  a deliberate trade-off: this token is not a secret in the strict sense (it is known to
  the device, to Apple, to the server, and visible to the user in their profile); an
  attacker who has the password must **also** obtain it, which requires access to the
  phone, the database or an already open session. A new device is only
  registered **after** the second factor: a made-up token bypasses nothing. Limits:
  a device registered **before** the second factor was enabled (for example by an
  attacker who already had the password) stays known, and the reset by
  the administrator does not delete devices: before requiring two-factor
  authentication, and after losing an iPhone, each user checks and
  deletes unknown or lost devices in their profile.
- **Clients without two-factor authentication**: only clients that announce it
  (`mfa=true`, the portal) receive the challenge; the others receive `403` with an explicit
  message, rather than a response in a format they do not know. The current **iOS app**
  does not handle it: it signs in from an already registered iPhone, but a
  **new** iPhone is rejected until the app handles this step (see TODO).
- As long as the `2026-10-24_user_mfa` migration is not applied, the second factor
  is not requested (warning in the logs): the administrator must be able to sign
  in to apply it from the portal banner.
- The **TOTP secret** is stored in clear text in `user_mfa` (it must be known to
  verify a code): database backups are therefore as sensitive as the configuration
  file.
- Not affected (no interactive sign-in): the MCP server and its access tokens,
  OAuth tokens (authorizing an agent goes through signing in to the portal, hence through
  the second factor), and the scheduler's `sync_token`.

### Login rate limit { #limitation-des-connexions }

The Docker image limits in nginx the sign-in routes (`POST /api/user/login`,
`/api/user/login/mfa`, `/api/oauth/token`) to **30 requests per minute per client
address**, with a burst of 10 (a password then the code of the second factor, a
typo…): beyond that, nginx answers `429` (`{"error": …, "code": 429}`,
`Retry-After: 60`) without calling PHP, and the portal shows "Too many login attempts
from this address". It complements the [lockout of an account](#autorisations) after 5
failures, which does not slow down attempts spread over several accounts. The rest of the
API is not limited. Installed on an existing web server (YunoHost included), Carbure does
not have this limit: your web server can add it (nginx `limit_req`).

**Client address behind a reverse proxy.** Behind the NAS proxy, Caddy or Traefik, every
connection comes from the proxy's address: without care, the whole household (and the
mobile apps) would share the same limit. nginx therefore takes the client's address from
the `X-Forwarded-For` header added by the proxy (`realip` module), but only when the
connection comes from a **trusted proxy**, `TRUSTED_PROXIES` environment variable
(addresses or CIDR networks separated by spaces or commas):

| `TRUSTED_PROXIES` | Trusted proxies |
|---|---|
| unset (default) | the private networks and the loopback: `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `::1/128`, `fc00::/7` (the NAS proxy, a Caddy or Traefik container, the host's proxy) |
| `172.18.0.0/16 192.168.1.10` | only these addresses or networks (a proxy on a public address: add it) |
| empty or `none` | none: the connection's address (Carbure published directly, without a proxy) |

The address used is the last one of `X-Forwarded-For` that is not a trusted proxy
(`real_ip_recursive`): the proxy adds the one it sees, and the addresses written before by
the client are ignored. It is also used by the nginx access log and by PHP
(`REMOTE_ADDR`: logs, setup wizard). An invalid entry stops the container at startup
(message in `docker logs`), which also prints the trusted proxies.

!!! warning "Trade-off of the default"
    The default works without any setting with the usual proxies, all on a private
    network. In return, a device of the **local network** (or another container) can
    choose the address nginx uses by sending `X-Forwarded-For` itself, and so get around
    the limit or fake the address in the logs; from the Internet, through the proxy, this
    is not possible. If Carbure is published **without a proxy** and connections from the
    Internet arrive with a private address (port forwarding of the router or of Docker
    hiding the original address), set `TRUSTED_PROXIES=none`; with a proxy, you can
    restrict `TRUSTED_PROXIES` to its address only. A proxy that does not add
    `X-Forwarded-For` (Caddy and Traefik add it by default, nginx with
    `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for`) makes all its clients
    share the limit: it stays generous for a household.

## Permissions { #autorisations }

| Role | Rights |
|---|---|
| User | Household data (accounts, transactions, budgets, insights, trends), checking and category of transactions, budget amounts, synchronization through the API, their profile, their devices, their MCP access tokens |
| Administrator (`users.is_admin = 1`) | In addition: categorization rules, categories, bank connections and followed accounts (and their synchronization from the portal), woob module updates, insights (SQL queries), enabling the MCP server, server settings (including SMTP) and logs, database update, users (profile, unlocking, two-factor authentication reset), required two-factor authentication |

Restricted routes are declared in their attribute (`#[ApiRoute(..., admin: true)]`) and
return `403` before the method is called; a unit test pins the access level of
each route. The portal hides the corresponding tabs.

After **5 failed sign-in attempts** in a row, the account is **locked for 24 hours**
(`423`, from the 5th failure on: the sign-in screen shows it right away), even with the right
password; a successful sign-in resets the counter. An
administrator unlocks it from the **Users** tab (`POST /user/unlock`). With the Docker
image, sign-ins are also [limited per address](#limitation-des-connexions).

An administrator cannot delete their own account; a user who owns
transactions cannot be deleted; the last administrator cannot lose
their role.

## HTTP headers { #en-tetes-http }

| Header | API (PHP, any installation) | Portal (nginx) |
|---|---|---|
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | server resources only; `'unsafe-eval'` (Vue compiles templates in the browser) and inline styles |
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `X-Frame-Options` | `DENY` | `DENY` |
| `Referrer-Policy` | `no-referrer` | `no-referrer` |
| `Cache-Control` | `no-store` (bank data) | — |
| `Cross-Origin-Resource-Policy` | `same-origin` | — |
| `Permissions-Policy`, `Cross-Origin-Opener-Policy` | — | camera, microphone, geolocation, payment, USB disabled; `same-origin` |

The API headers are set by PHP (`Webservice::securityHeaders`, and the setup
wizard), whatever the web server. Those of the portal and Swagger UI are set
by nginx (`docker/nginx.conf`); Carbure does not provide a `.htaccess`.

Swagger UI (`/swagger/`) has the same headers as the portal, except for display in a
frame: `frame-ancestors 'self'` and `X-Frame-Options: SAMEORIGIN`, for the portal's
**Administration → API** tab; no other site can display it in a frame, and
the portal itself keeps `frame-ancestors 'none'` / `DENY`. Swagger UI reads the portal's session
token from the browser storage (same origin) and only sends it in
the `Authorization` header of calls to `/api`.

**No external CDN**: the portal and Swagger UI load nothing from another site.
Vue, CodeMirror, the fonts (`portal/vendor/`) and Swagger UI (`swagger/vendor/`) are
served with Carbure; their versions and licenses are listed in the `README.md` of each
folder. Their CSP therefore only allows the server itself (`'self'`, plus `data:` for
images) for scripts, styles, fonts, images and network calls: Swagger UI has no
inline script nor external validation badge. A test (`tests/portal/localAssets.test.mjs`)
fails if a page or a stylesheet loads an external resource, or if the CSP
allows another site. The documentation site (carbure.org) also hosts its own fonts,
downloaded at build time (`privacy` plugin of Material for MkDocs).

The API does not reveal the PHP version (`X-Powered-By` removed) and nginx does not reveal its own
(`server_tokens off`). **HSTS** must be enabled on the HTTPS proxy placed in front of Carbure (NAS
reverse proxy, Traefik, Caddy…), since the container serves HTTP. On an nginx server without
Docker, copy the headers from `docker/nginx.conf`.

## Setup wizard { #assistant-dinstallation }

As long as `data/conf/prod.ini` does not exist, the API only responds to `/api/setup` (everything else returns
`503`). From the local network, the wizard is reachable without a code, like most
self-hosted applications; from the Internet (public address), or as soon as the file
`data/conf/setup.code` exists, each step requires the **setup code** from this file, also
written to the server logs (`docker logs`). A wrong code is slowed down. The
database password provided by Docker (`DB_PASSWORD`) is only used for the
environment's database, never for another server entered in the wizard. Once the
configuration is written, the wizard disappears and the code is deleted.

!!! warning "Reverse proxy"
    The addresses taken into account are the one connecting to the PHP server
    (`REMOTE_ADDR`) and those of the proxy headers (`X-Forwarded-For`, `X-Real-IP`,
    `Forwarded`). A reverse proxy that does not send them, or only puts local addresses
    in them, makes the client look local: the code is then not requested. Install Carbure before exposing it to the Internet, or create
    `data/conf/setup.code` (Docker: `/data/conf/setup.code`) with a code of your choice to
    enforce it.

Later schema updates happen without the wizard: migrations applied
when the Docker container starts or by an administrator from the portal
(`POST /system/migrate`).

## Insights (stored SQL) { #insights-sql-stocke }

Insights are SQL queries stored in the database and run by the server. Only an
administrator can manage them, and each query is guarded:

- when saved: a single `SELECT`, with no `;` nor comment, no write,
  administration, file (`INTO OUTFILE`, `LOAD_FILE`) or delay (`SLEEP`,
  `BENCHMARK`) keyword, no backslash; the query is tested before being saved;
- **table allowlist**: only the budget tables are readable
  (`bank_transaction`, `bank_transaction_category`, `bank_transaction_category_keyword`,
  `budget`, `bank_account`, `bank_backend`), without a database name. The other tables of the schema
  (`users`, `user_mfa` and its TOTP secrets, backup codes, tokens, devices, OAuth,
  settings…) and the system schemas are rejected, even when referenced in a subquery, a
  `UNION` or a provoked error message (`EXTRACTVALUE`): the error message returned by
  `POST /insight/check` can therefore only contain budget data. A unit test
  requires classifying each new table of `sql/carbure.sql`;
- when run: the query is **checked again** (a query saved before these
  rules is not run), in a **read-only transaction** (`START TRANSACTION
  READ ONLY`) and with a **5-second limit** (`SET STATEMENT max_statement_time` on
  MariaDB, `MAX_EXECUTION_TIME` hint on MySQL); a failing or too long query
  gives `0` without blocking the others.

## Access tokens (MCP) { #jetons-dacces-mcp }

The MCP server (`/api/mcp`) is **disabled by default**; an administrator enables it in
the **AI agent** tab. It accepts access tokens created by each user in this
tab: 192 random bits, `cbt_` prefix, **only the SHA-256 hash is stored**,
validity period of your choice (30, 90 or 365 days, or no expiration), revocable, with the
date of last use. They only give access to the
read-only tools of the MCP server, not to the rest of the API. The server rejects requests
carrying an `Origin` header from a site other than Carbure or Claude's web clients
(protection against DNS rebinding).

Agents that only take a URL (Claude web, Desktop, mobile) obtain such a token
through **OAuth 2.1**: the user signs in to the portal and explicitly authorizes the agent;
**PKCE `S256` mandatory**, single-use authorization codes valid for 5 minutes, redirect
URIs registered and compared exactly (HTTPS, or HTTP on `localhost`), rotating refresh
tokens, only the SHA-256 hashes of codes, tokens and client
secrets are stored. Client registration is open (as the
MCP specification provides) but limited to 100 clients, unused clients being purged after
one day; everything is rejected when the MCP server is disabled.

## Public routes { #routes-publiques }

Only these routes do not require a JWT (a unit test checks it):

- `POST /user/login`, and `POST /user/login/mfa`, the second step of sign-in, which
  requires the 5-minute challenge given by the first one (password verified) and the
  app's code;
- `GET /bank/sync`, which requires **either** a JWT **or** the configuration's
  `sync_token` (`X-Sync-Token` header; `?token=` is still accepted but deprecated, see
  [API](api.md#bank-sync)). Without a configured
  `sync_token`, only a signed-in user can start the synchronization;
- `POST /mcp` and `GET /mcp` (MCP server), which require an access token or a JWT;
- the OAuth routes of AI agents (`/.well-known/oauth-*` metadata, registration,
  authorization and tokens), disabled along with the MCP server; the authorization itself
  (`POST /oauth/approve`) requires the user signed in to the portal;
- `GET /health` (monitoring), which only returns the database state and the schema version.

## Sensitive data { #donnees-sensibles }

- Logs mask the `password` and `token` fields of requests, the OAuth secrets
  (`code`, `code_verifier`, `refresh_token`, `access_token`, `client_secret`), and the
  `token=` parameter of URLs (synchronization, MCP), as well as the challenge, the secret, the
  `otpauth` URI and the backup codes of two-factor authentication (requests and responses
  logged at `debug` level). The body of responses from the MCP server and OAuth is
  not logged.
- The nginx access log (Docker image, `docker logs`) also replaces with `***` the
  `token=`, `access_token=` and `code=` parameters of URLs (`carbure` format of
  `docker/nginx.conf`): the token of a scheduled task not yet migrated to the header
  does not appear there. A proxy placed in front (NAS reverse proxy) may however
  log the full URL. The successful calls of `GET /api/health` (Docker healthcheck,
  monitoring, mobile apps) are not written there; its errors (4xx, 5xx) are.
- The call stack of an error is logged **without the function arguments** (which
  contain the request parameters: password, token…), and the Docker image
  enables `zend.exception_ignore_args`.
- The SMTP password (`smtp_password`) is never returned to the portal nor logged
  (field masked in the logs); it does not appear in sending errors. Emails
  are plain text, addresses and subject reject line breaks (no
  header injection) and the SMTP server's certificate is verified with STARTTLS/TLS.
- Error messages return neither hash nor secret; the `uid` lets you find
  the details in the server logs.
- **Log format**: one JSON entry per line (JSON Lines), with the ISO 8601 time
  (milliseconds, time zone), the level, the request ID (`uid`, taken from a valid
  `X-Request-Id` header), the **client IP address**, the user, the method and the path
  (URL tokens masked), the caller, the message and its context. Each request
  ends with a line with its HTTP status and duration (INFO, WARNING for 4xx, ERROR
  for 5xx). `GET /health`, polled every few minutes by the healthchecks, writes its call
  and this line at the `debug` level only (`quiet` option of its route); its errors keep
  their level. A message cannot fake another entry: its line breaks
  are escaped.
- **Client address**: behind a trusted proxy (private or local address: the image's nginx,
  the NAS reverse proxy), it is the last public address of
  `X-Forwarded-For` (or `X-Real-IP`); otherwise the connection address, because these headers
  can then be forged. In the Docker image, nginx has already replaced the connection address
  with the client's one given by a trusted proxy (`TRUSTED_PROXIES`, see
  [Login rate limit](#limitation-des-connexions)), also written in its access log.
- An IP address is **personal data**: log files are deleted after
  `log_retention_days` days (90 in a new installation, see
  [Configuration](configuration.md)).
- **Personal data depending on the log level** (`log_level`):

    | Level | What is written |
    |---|---|
    | `warning`, `error` (default) | IP address and (numeric) user ID on each line; errors, with only the last error line from woob |
    | `info` | In addition: each API call with the **name** of its parameters, never their value (labels, amounts, emails stay out of the logs); emails sent, address masked (`j***@example.org`) |
    | `debug` | **Everything**: API parameters and responses (transactions, balances…, secrets always masked) |

    `woob_debug` also writes the full output of woob (accounts, transactions, bank
    pages). `debug` and `woob_debug` are for one-off troubleshooting: afterwards set back
    `warning` and delete the affected log files (`data/logs/`).
- Logs refer to a user by their ID, never by their name (locked
  account, sign-in rejected for lack of two-factor authentication).
- Bank credentials entered in the portal **never go on the command
  line** of woob (visible to every process in the container: `ps`,
  `/proc/<pid>/cmdline`): Carbure writes the bank directly to woob's configuration
  file (`.config/woob/backends`, readable by its owner only), in the
  format `woob config add` would write. A value between backticks (`` `…` ``) is
  rejected: woob would run it as a command to get the value. An account or bank name starting with `-`
  is rejected (`400`): woob would read it as an option.
- `data/`: `conf/prod.ini` (including `push_api_key`, the API key of the push notification service), `logs/`, `woob/` (woob bank credentials)
  are excluded from the Git repository.

## Deployment recommendations { #recommandations-de-deploiement }

!!! danger "JWT secret"
    The setup wizard generates a random `jwtsecret`; if the configuration
    still contains the sample value, a banner offers administrators to
    renew it in one click (`POST /system/jwt-secret`). A weak value or the sample
    value makes it possible to forge a token for any user. Changing the
    secret invalidates all sessions (iOS app and portal).

- Serve the API and the portal **over HTTPS only** (JWTs and passwords travel
  in the requests; copying the token in the portal requires HTTPS).
- Do not expose `data/`, `conf/`, `sql/`, `tests/` or `tools/` through the
  web server.
- Generate a long `sync_token` (the **Administration → Sync** tab creates one) and
  pass it in the `X-Sync-Token` header, never in the URL (`?token=`, deprecated: URLs
  are logged by proxies and stay in the shell history).
- Restrict the MySQL user to the `carbure` database.
- Monitor `data/logs/carbure_YYYYMMDD.log` (synchronization errors, denied access: the WARNING end-of-request lines in 401 or 403, with the client IP). In JSON Lines format, the file can also be read with `jq` or a log collector.

## Security scans { #analyses-de-securite }

The **Security** workflow (README badge) groups the security scans:

- **gitleaks** looks for secrets (passwords, tokens, keys) in the whole git
  history, on each pull request and each push to `main`;
- **Trivy** scans the repository's dependencies (npm and pip lock files) at the
  same times;
- **Semgrep** analyzes the PHP code (`src/`, `tools/`, `swagger/`) at the same times, with
  the PHP rules of its public registry (injections, dangerous functions, cryptography,
  deserialization): CodeQL, which analyzes the JavaScript of the portal and the workflows,
  does not support PHP. A finding fails the scan;
- **Trivy** scans **every Monday** the image published on Docker Hub
  (`ltoinel/carbure:latest`): new vulnerabilities are published without Carbure
  changing. A failure signals that a new release (rebuilt image) is needed.
  The scan can also be started manually (**Actions → Security → Run workflow**).

A **critical vulnerability for which a fix exists** makes the scan fail;
the full reports (critical, high, medium) are published in the repository's **Security**
tab.

## Docker image security { #securite-de-limage-docker }

woob's bank modules (Python code that reads the credentials) are downloaded
from woob's repository: the image contains `gpgv`, with which woob verifies the signature
of each installed or updated module and rejects a module whose signature is
invalid.

The key of woob's repository (`https://updates.woob.tech/3/main/`) is only approved
once: the following keys are verified by woob with the previous one. But **any
woob command that refreshes the repositories while no key is approved trusts it
on its own**: without a terminal, woob displays the question then
`*** ASSUMING YES ***`. Carbure therefore does not run woob until an **administrator** has
approved this key, from the **Accounts** tab (**Update the bank modules**
button): the portal displays the repository and the SHA-1 fingerprint of its key, to compare with
the one published or announced by woob's maintainers, then **Trust and
update**. Carbure downloads the key itself just before: woob is only run if its
fingerprint is exactly the confirmed one; after the update, the key woob has
kept (`/data/woob/.local/share/woob/keyrings/`) must also be that one, otherwise it
is deleted and the update fails.

**The key of the official repository is pinned in Carbure**: its SHA-256
fingerprint (`a1755b379793ff4922a7bfb5f566c019205f805b96f3420aee95938cd0d1634a`, i.e. the SHA-1
`b5fc136c2d500b8483696a0ee722865842a4cc45` displayed by woob; keys of woob's maintainers,
unchanged since February 2023) is checked once and for all by Carbure's maintainers
(`WoobRepository::OFFICIAL_KEYRINGS`). On the first start of the container
(`tools/woob-trust.php`), Carbure downloads the key of the official repository and, if its
fingerprint is **exactly** the pinned one, approves it without asking and installs the
modules, with the same checks as a manual approval: banks can be connected at once. Another
key (changed, or served by an attacker), another repository (`woob_repository`) or a
repository not reachable at start still wait for the approval of an administrator, as below. The pin is a SHA-256, not a SHA-1: SHA-1 is broken against
chosen-prefix collisions, a GPG keyring can carry filler packets, and this approval happens
with no human looking.

As long as the repository is not approved, Carbure also refuses (`409`, “Trust the woob
repository first”):

- **any woob command** on a fresh installation: on first launch (no
  `~/.config/woob/sources.list` yet), any woob command creates the list of
  repositories and refreshes them first, trusting the key on its own; the
  update approved by the administrator is then woob's first launch;
- to **install a bank module** (connect a bank whose module is not yet
  installed): woob verifies its signature with the repository's key;
- the `--auto-update` option (`woob_auto_update`) is only passed to woob once the repository
  is approved: after a module crashes, it refreshes the repositories.

So approve the repository before connecting a bank. A woob command run manually
in the container (`woob config update`, or any command on first launch) would
trust the key on its own.

Once the repository is approved, the container also runs `woob config update` at each
start (repositories, key and installed modules), without ever answering a woob
question; as long as it is not, it says so in its logs and updates nothing. An update
failure is written to the container logs. A woob command run manually in the
container must be run as `www-data` (`docker exec -u www-data …`): run as root, it
would create files in `/data/woob` that Carbure could no longer modify (restarting
the container gives them back to it).

Each CI build also scans the built image:

- **hadolint** checks the best practices of `docker/Dockerfile`;
- **Trivy** looks for known vulnerabilities in Alpine packages and PHP dependencies
  and for secrets left in the image. woob and its Python dependencies (`/opt/woob`) are
  not scanned: they follow woob's versions (`WOOB_VERSION`). A **critical
  vulnerability for which a fix exists** makes the build fail (and therefore the
  release); the full report (critical, high, medium) is published in the repository's
  **Security** tab.

A vulnerability with no impact on Carbure can be accepted in `.trivyignore`, with its
justification. Published images embed their SBOM and their build provenance
(`docker buildx imagetools inspect ltoinel/carbure:latest --format '{{ json .SBOM }}'`).
To scan an image locally:

```bash
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy image ltoinel/carbure:latest
```

### Verify the image's origin { #verifier-lorigine-de-limage }

Since version 1.4.0, the **Release** workflow also publishes a **provenance
attestation** signed by GitHub (Sigstore) for the image pushed to Docker Hub: it proves
that the image (identified by its `sha256` digest) was built by the
`release.yml` workflow of the `ltoinel/Carbure` repository, from the given tag. Before updating the
NAS, verify it with the [GitHub CLI](https://cli.github.com/) (`gh auth login`
first), from the NAS or any machine:

```bash
gh attestation verify oci://docker.io/ltoinel/carbure:1.4.0 --owner ltoinel
# Stricter: the repository and the workflow that signed
gh attestation verify oci://docker.io/ltoinel/carbure:1.4.0 \
  --repo ltoinel/Carbure \
  --signer-workflow ltoinel/Carbure/.github/workflows/release.yml
```

The command fails if the image has no valid attestation (modified image, pushed by
other means than the workflow, or version earlier than 1.4.0). The attestation is also
visible in the repository's **Actions → Attestations** tab. To pin exactly the verified
image, then reference it by its digest (`ltoinel/carbure@sha256:…`, displayed
by the command) rather than by its tag.

### Build chain { #chaine-de-build }

GitHub Actions workflows reference each action by the **full hash of its
commit** (and not by a tag, which can be moved), kept up to date by Dependabot with a
7-day delay after each new version; `actions/checkout` does not keep the
git token in the cloned repository, each job has a maximum duration, and the title of each pull
request (the commit message on `main`) is checked.

## Report a vulnerability { #signaler-une-vulnerabilite }

Please do not open a public issue: contact the maintainer through GitHub
(<https://github.com/ltoinel>).
