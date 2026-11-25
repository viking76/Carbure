---
title: "Server configuration (prod.ini file)"
description: "Reference of the Carbure configuration: database, tokens, woob, scheduled synchronization, push notifications and logs."
icon: material/cog-outline
---

# Configuration

The configuration is read from `conf/<env>.ini`, where `<env>` is the value of the
`APP_ENV` environment variable (`prod` by default, `testing` for tests). Comments start
with `;` (`#` is not accepted by PHP).

`data/conf/prod.ini` is written by the **setup wizard** from `conf/prod.sample.ini`, with
the database settings and random secrets (`jwtsecret`, `password_salt`, `sync_token`);
with Docker, it is located in `/data/conf/prod.ini`. It contains secrets: it is ignored by
Git and must never be published. After a manual change, nothing needs to be restarted
(the file is re-read on every request), except the Docker container for `SYNC_INTERVAL`.

!!! tip "View and change settings from the portal"
    No need to open the file: an administrator can view it in the portal, under the
    **Administration → Settings** tab. Settings are presented by section, with their
    current value and help, secrets masked.

    The keys marked ✏️ in the tables below can be changed there directly: `log_level`,
    `log_retention_days`, `savings_category`, `public_url`, `currency`, `next_month_day`, `update_check`, `woob_transactions`,
    `woob_logging`, `woob_debug`, `woob_auto_update`, `woob_relabel`, `woob_2fa_wait`, `woob_2fa_retries`,
    `push_api_key` and the
    SMTP settings (`smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_auth`, `smtp_username`,
    `smtp_password`, `smtp_from`, `smtp_from_name`). Each value is
    checked (list of values, bounded number, format) before writing, and the previous file
    is kept as `prod.ini.bak`. The web server must be able to write the file; otherwise
    the page is read-only.

    The other settings — database, secrets, `woob_path` (command run by the server),
    `regex_label` (which changes transaction identifiers), file paths — are displayed but
    can only be changed on the server.

Other settings, such as enabling the MCP server, are stored in the database and not in
this file (see [Settings in the database](#reglages-en-base)).

## `[global]` { #global }

| Key | Values | Description |
|---|---|---|
| `log_level` ✏️ | `debug`, `info`, `warning`, `error` | Minimum log level (`data/logs/carbure_YYYYMMDD.log`). In `debug`, PHP errors are displayed and JSON responses are indented, and household data is logged (see [Security](securite.md#donnees-sensibles)). |
| `log_retention_days` ✏️ | `0` to `3650` | Number of days log files are kept; older ones are deleted at the end of the bank sync. `0` (or missing): kept forever. `90` in a new installation. |
| `development` | `true` / `false` | Development mode flag, written as `false` by the wizard (currently has no effect) |
| `demo` | `true` / `false` | Demo instance (written by the `ltoinel/carbure:demo` image): the login screen shows the public demo account. Missing by default; never on an instance with real data |
| `currency` ✏️ | ISO 4217 code | Currency of the amounts of the instance (`EUR`, `USD`, `GBP`, `CHF`, `CAD`…), shown in the portal (its symbol: €, $, £… or its code), the notifications and the emails; `EUR` by default or when the value is not a 3-letter code. Only the display changes: no amount is converted, choose the currency of your accounts |
| `update_check` ✏️ | `true` / `false` | Banner of the administrators when a new release of Carbure is published: the server asks the public API of GitHub (`api.github.com`) for the latest release at most once a day, sending nothing about the instance (an anonymous request, like a download), and keeps the answer in the database. `true` by default (or when the key is missing); `false`: never asked. Never for a development version |
| `next_month_day` ✏️ | `0` to `31` | Day of the month from which a **received transfer** (the salary) counts for the next month: dated on this day or later, it is attached to the 1st of the next month, the month it pays for; its actual date remains visible in the transaction details. `25` by default (or when the value is not a number); `0`: never moved. Applies to the transactions imported or synchronized afterwards, not to those already saved. See [Month of attribution](fonctionnalites.md#deroulement-dune-synchronisation) |
| `savings_category` ✏️ | name | Category whose transactions (including its subcategories) make up the savings in the Trends tab; `Epargne` by default, case and accents ignored |
| `public_url` ✏️ | URL | Optional: public address of Carbure (`https://carbure.exemple.fr`), advertised to AI agents that connect through OAuth and to the mobile apps by the QR code of **My devices**. By default, inferred from the request: set it if the HTTPS proxy does not send `X-Forwarded-Proto` |

## `[database]` { #database }

| Key | Description |
|---|---|
| `db_hostname` | MariaDB/MySQL host |
| `db_port` | Port (`3306` by default; `3307` for MariaDB 10 on Synology) |
| `db_username`, `db_password` | Credentials |
| `db_name` | Database name |

## `[cleansing]` { #cleansing }

```ini
regex_label["/FACTURE CARTE DU \d{6}\s*/"]="CB "
regex_label["/(PRLV SEPA.*)(ECH.*|MDT.*)$/"]="$1"
```

Regular expressions applied, in order, to the uppercase label of each imported
transaction.

!!! warning "Do not change without a migration"
    The cleaned label is part of the transaction UUID. Changing these rules makes existing
    transactions get re-imported under another UUID (duplicates).
    Instances installed before 1.5.0 may still have the rule
    `regex_label["/\s(CARTE.*|CARTE|CART|CAR|CA|C)$/"]=""` (end of card labels), removed
    from the sample: keep it on an instance that already has transactions.

## `[auth]` { #auth }

| Key | Description |
|---|---|
| `jwtsecret` | HMAC secret of the JWTs (validity: 30 days), generated by the setup wizard. An administrator renews it from the portal (a banner is shown if the secret is weak); changing it logs out all clients. |
| `password_salt` | Salt of the old SHA-256 passwords, used only to migrate them to bcrypt at login |
| `mfa_required` | `true`: two-factor authentication (code from a TOTP app) required at login for all users, except from an already registered mobile device (iOS, Android) or a trusted browser (6 months); `false` by default. Can be changed in **Administration › Settings**; setting it back to `false` in the file is the fallback for an administrator who lost their phone and recovery codes. See [Security](securite.md#double-authentification-totp). |
| `sync_token` | Secret that lets the scheduler call `/api/bank/sync` without a JWT, in the `X-Sync-Token` header (`?token=` in the URL: deprecated). Empty = only a logged-in user can start the sync. |

## `[woob]` { #woob }

| Key | Description |
|---|---|
| `woob_path` | woob command: local binary (`/usr/bin/woob`), container (`docker run -v …:/root/ ltoinel/woob:3.7 woob`) or, in the Docker image, `env HOME=/data/woob woob` (`CARBURE_WOOB_PATH` variable) |
| `woob_transactions` ✏️ | Number of operations requested per call (`--count`) |
| `woob_logging` ✏️ | woob log level, on its error output (never in a file): `debug` (`--debug`), `info` (`--verbose`), `warning` (default), `error`/`critical` (`--quiet`) |
| `woob_debug` ✏️ | `true` to run woob with `--debug` (takes precedence over `woob_logging`), log its full output and show it in the portal sync log (administrators) |
| `woob_auto_update` ✏️ | `true` to let woob update its modules (`--auto-update`, once the woob repository is trusted, see [Security](securite.md)) |
| `woob_relabel` ✏️ | `false` by default. `true`: when a woob module changes the label of transactions already synchronized (a fix of the module), the synchronization gives the new label to the saved transaction instead of saving it a second time. A transaction received with an unknown label replaces the one transaction of the same account, real date and amount that the bank no longer sends; category, tags and checking are kept. If there are several (two payments of the same amount the same day), nothing is guessed. Risk: two real distinct transactions of the same amount the same day, only one of which is still sent, would be merged |
| `woob_2fa_wait` ✏️ | Scheduled sync: seconds to wait (0 to 300, 15 by default) before re-syncing an account whose bank asks for approval in its app |
| `woob_2fa_retries` ✏️ | Scheduled sync: number of retries after this request (0 to 5, 1 by default; 0 to not retry) |

## `[push]` { #push }

Push notifications of the mobile apps (iOS and Android, see [Mobile apps](applications-mobiles.md)) go
through **Carbure Push** (`https://api.carbure.org`, always this service: there is no
address setting), an external service that holds the providers' credentials (Apple APNs
key, Firebase service account): the instance needs none of them. It registers with it once
(**Register the instance** button in **Administration → Mobile push**, which solves a
proof of work and saves the key obtained here) then sends it each notification with its
API key.

**Consent**: before registration, a dialog explains what the instance entrusts to the
relay (its address; the tokens of the iOS and Android mobile devices and the content of
the notifications: title and text, for example amounts and labels of operations, budget
alerts; the instance name at registration), that it forwards them to Apple (APNs) and to
Google (Firebase Cloud Messaging), and what it keeps of them according to its
documentation: it does not log the text of notifications and only keeps the hash
(SHA-256) of the tokens. The administrator must check "I understand and accept…" to
register the instance; the API rejects registration without this consent. It is recorded
with its date and the relay address (shown under the button). To stop, empty `push_api_key`: no more notifications
are sent to the relay.

| Key | Description |
|---|---|
| `push_api_key` ✏️ | API key of the instance (`cpk_<id>_<secret>`): a secret, never logged; masked in its field, shown to administrators in **Administration → Mobile push** (public identifier only, in full with **Show the API key**, **Copy** button); filled in by **Register the instance** (or pasted). Empty: **no push notification is sent** (logged as info, the users' profile says so) |

An old file that still contains `push_url` remains valid: this setting is no longer read
or displayed.

The **Mobile push** tab also shows the **service status**: whether the relay responds
(`GET /v1/health`, with its version) and whether it accepts the instance key
(`GET /v1/keys/current`: valid with its devices and its notifications of the day, rejected
if it is revoked). The **Renew the key** button replaces a key (leaked, for example) with
a new one, while devices keep receiving notifications.
**Send a test notification** sends one to the administrator's devices. Devices that the service reports as
unregistered (app deleted, token expired) are forgotten.

An old `[apns]` section is ignored (shown as read-only): it can be deleted, as can the
`.p8` key in `data/conf/certs/`.

## `[smtp]` { #smtp }

Server used to send email notifications (each user then chooses, in their profile, the
notifications they receive by email). Without `smtp_host` or without `smtp_from`, no email
is sent. The SMTP client is built into Carbure (no dependency).

| Key | Description |
|---|---|
| `smtp_host` ✏️ | SMTP server name (empty = emails disabled) |
| `smtp_port` ✏️ | Port: `587` (STARTTLS), `465` (TLS) or `25` |
| `smtp_encryption` ✏️ | `none`, `starttls` (upgrade to TLS after connecting) or `tls` (TLS from the start of the connection); the server certificate is always verified |
| `smtp_auth` ✏️ | `none`, `plain` or `login` |
| `smtp_username` ✏️ | SMTP account username |
| `smtp_password` ✏️ | SMTP account password: a secret, never displayed or logged; in the portal, you only enter a new password to replace it (without `"`, `\`, `$` or line breaks) |
| `smtp_from` ✏️ | Sender address of the emails |
| `smtp_from_name` ✏️ | Sender name (`Carbure` by default) |

The **Send a test email** button in **Administration → Settings** checks the saved
configuration: the email goes to the administrator's address (or the one entered) and the
server response is displayed if it fails.

## Settings in the database { #reglages-en-base }

These settings, changed from the portal, are stored in the `settings` table (`name`,
`value`) and not in the file:

| Setting | Values | Changed in |
|---|---|---|
| `mcp_enabled` | `1` / `0` (disabled by default) | **AI agent** tab, **MCP server enabled** switch (administrator) |

## Environment variables { #variables-denvironnement }

| Variable | Role |
|---|---|
| `APP_ENV` | Name of the configuration file (`prod` by default) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `LANGUAGE`, `CARBURE_WOOB_PATH` | Values suggested by the setup wizard (Docker); the password from the environment is only used for this database |
| `SYNC_INTERVAL` | Docker: interval of the automatic sync, in seconds |
| `TRUSTED_PROXIES` | Docker: trusted reverse proxies for the client's address (`X-Forwarded-For`), see [Login rate limit](securite.md#limitation-des-connexions) |
