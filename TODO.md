# TODO

Remaining tasks on Carbure. A finished task is removed from this list (the Git history
keeps track of what was done).

- [ ] **Production — push notifications moved to Carbure Push (#170), BEFORE upgrading
      Carbure**, otherwise the push notifications stop (Carbure no longer reads `[apns]` nor
      the `.p8` key):
      1. deploy the relay (private repo `ltoinel/Carbure-push`, README "Déploiement"): its
         own container with the APNs `.p8` key and, for Android later, the Firebase service
         account; serve it in HTTPS on `https://api.carbure.org` (the default relay from 1.6.0,
         the only one from 1.8.0, no `push_url` setting any more; with Carbure 1.5.0, whose default is
         `https://push.carbure.org`, set `push_url` to `https://api.carbure.org`);
      2. check `bin/push-admin check` and `GET /v1/health` on the relay;
      3. upgrade Carbure; the migration `2026-10-23_device_platform` (`devices.platform`,
         default `ios`) is applied at container start (the code works before it);
      4. **Administration → Settings → Push Notification Mobile**: click **Register the
         instance**, accept the consent dialog (#201;
         recorded in the `settings` table) (stores `push_api_key`), then
         **Send a test notification** to an iPhone;
      5. remove the `[apns]` section and `data/conf/certs/*.p8` from the NAS (no longer used);
      6. later: a release workflow publishing the image of Carbure Push to a registry.

- [ ] **Release 1.12.0 — changelog (#391)**: add the `1.12.0` section to `docs/changelog.md`
      and `docs/changelog.en.md` (features, fixes, migrations to apply: split, tags, insights
      shown, …); from now on, the upgrade notes of each release go there, not in
      `docs/installation.md`.

- [ ] **Deployment of the split of transactions (#335)**: the migration
      `2026-10-30_transaction_split` (`bank_transaction.split_of`) is applied at container
      start; `PUT /transaction/split` needs it. The migration
      `2026-11-04_insight_largest_expense` then makes the starter insight "Largest expense"
      count a split transaction once, with the amount of the bank (#409), unless its query
      was changed; the insights written by the users that count transactions (`COUNT(*)`)
      count each part: see the note of `docs/insights.md`.

- [ ] **Mobile apps — split transactions (#335)**: the apps already show the parts as
      transactions of their own (`split_of` set); show them under their transaction, with its
      bank amount, and offer the split (`PUT /transaction/split`).

- [ ] **Mobile apps — transactions entered by hand (#355)**: the apps show them already;
      offer to enter one (`POST /transaction`, on any account of `GET /bank`, the `manual`
      ones first) and to change it (`PUT /transaction`). Entered by hand = `uuid` starting
      with `manual`, or `bank_name` = `manual` (older entries, #431).

- [ ] **Deployment of the tags (#354)**: the migration `2026-10-31_tags` (tables `tag`,
      `transaction_tag`) is applied at container start; before it, the transactions are
      returned without tags.

- [ ] **Mobile apps — tags (#354)**: the transactions carry `tags` (ids of `GET /tag`); show
      their chips, filter by tag and offer to tag (`PUT /transaction/tags`); reload on the
      change marker `tags`.

- [ ] **Mobile apps — advanced search (#407)**: `GET /transaction/search` takes optional
      criteria (`min`, `max` without sign, `kind`, `categories`, `accounts`, `from`, `to`), the
      text being optional with a criterion; offer them like the portal's modal.

- [ ] **Deployment of the shown insights (#386)**: the migration `2026-11-01_insight_enabled`
      (column `budget_insight.enabled`) is applied at container start; the insights already
      installed stay shown. Before it, every insight is shown and `GET /insight/disabled` is empty.

- [ ] **Deployment of the origin of the categories (#341)**: the migration
      `2026-11-02_category_source` (column `bank_transaction.category_source`) is applied at
      container start; the existing categories have an unknown origin (`NULL`), handled as
      before (overwritten by the rules unless *Keep the existing categories* is on). Before
      it, the rules work as in 1.11.0. The mobile apps set a category through
      `PUT /transaction/category`, recorded as chosen by hand: nothing to change there.

- [ ] **Mobile apps — hidden insights (#386)**: `GET /budget/insights` already returns only the
      shown insights; offer to hide a card and to show a hidden one again
      (`GET /insight/disabled`, `PUT /insight/enabled`).

- [ ] **Deployment of the budget notes (#468)**: the migration `2026-11-06_budget_note`
      (column `budget.note`) is applied at container start. Before it, `GET /budget` returns
      `note: null` and `POST /budget` with a note answers 409 (`budget_note_migration`).

- [ ] **Deployment of the transaction comments**: the migration `2026-11-19_transaction_comment`
      (column `bank_transaction.comment`, nullable: the existing transactions get `NULL`, no
      rewrite of the rows) is applied at container start by `tools/migrate.php`. Before it,
      the transactions have no `comment` field and `PUT /transaction/comment` with a comment
      answers 409 (`transaction_comment_migration`); the rest works as before.

- [ ] **Mobile apps — transaction comments**: `GET /transaction` and `GET /transaction/search`
      return `comment` (`null` without one, absent before the migration); show it and offer it
      in the transaction details (`PUT /transaction/comment` with `id`, `comment`; `""` removes
      it, 500 characters at most) and in the form of a transaction entered by hand
      (`POST /transaction` and `PUT /transaction` take an optional `comment`; without it,
      `PUT /transaction` keeps the current one).

- [ ] **Mobile apps — budget notes (#468)**: `GET /budget` returns `note`; show it and offer it
      in the budget editor (`POST /budget` with `note`, `""` to remove it; without `note`
      the current one is kept).

- [ ] **Mobile apps — transactions put on a parent category (#492)**: in a sub-level of the
      budgets they are in no card. Ask `GET /budget?category=<parent>&own=1` and show the
      item with `own: true` (no budget, not to open again); its transactions:
      `GET /transaction?category=<parent>&own=1`.

- [ ] **Deployment of the order of the insights (#467)**: the migration
      `2026-11-07_insight_position` (column `budget_insight.position`) is applied at container
      start; the insights keep their order (by id) until the household orders them. Before it,
      `PUT /insight/order` answers 409 (`insight_migration_pending`).

- [ ] **Mobile apps — order of the insights (#467)**: `GET /budget/insights` already returns
      the cards in the order chosen in the portal; offer to reorder them
      (`PUT /insight/order` with the ids in their new order).

- [ ] **Deployment of the insight alerts (#387)**: the migration `2026-11-03_insight_alert`
      (table `insight_alert`) is applied at container start. Before it, `GET /insight/alert`
      is empty, `PUT /insight/alert` answers 409 and nothing alerts.

- [ ] **Deployment of the amount of the rules (#462)**: the migration `2026-11-05_rule_amount`
      (columns `bank_transaction_category_keyword.amount` and generated `amount_key`, unique
      key on `keyword` + `amount_key`) is applied at container start; the existing rules
      keep any amount. Before it, the rules work as before, `amount` is `null` and a rule
      with an amount is refused (409 `rule_amount_migration`).

- [ ] **Mobile apps — amount of the rules (#462)**: `GET /category/keyword` returns `amount`
      (`null`: any amount); show it next to the keyword and offer it in the rule form
      (optional `amount` of `POST`/`PUT /category/keyword`; a `PUT` without it keeps it).

- [ ] **Mobile apps — insight alerts (#387)**: a bell on each insight card to set the alert
      (`GET`/`PUT`/`DELETE /insight/alert`), and the kind `insight` in the notification
      preferences (`GET`/`PUT /user/notifications`, already returned by the server).

- [ ] **YunoHost package (#45, `ltoinel/carbure_ynh`)**: after the 1.10.0 release, merge the
      pull request of its autoupdate Action (version and sha256 of the archive), then
      install it on a test YunoHost server (Debian 12): install, upgrade, backup/restore,
      change of domain, a real bank synchronization, the mobile apps on the domain; then
      `package_check`, and propose it to the YunoHost-Apps organization.

- [ ] **Demo image (#219)**: `ltoinel/carbure:demo` and `ltoinel/carbure:<version>-demo` are
      pushed by the release workflow from the next release (1.6.0): until then the
      `docker run … ltoinel/carbure:demo` command of the README and of the docs fails. After
      the release, check `docker run --rm -p 8080:80 ltoinel/carbure:demo` on amd64 and
      arm64, and mention the demo tag in the overview of the Docker Hub repository
      (accounts: `demo` / `carbure`, `admin` with a random password printed in the logs).

- [ ] **Demo data reloaded each day (#481)**: after the 1.13.0 release, the day after the
      update of demo.carbure.org, check that `docker logs carbure-demo` shows
      `new day (…), demonstration data reloaded up to today` and that the current month
      has transactions up to today; the nightly `run.sh --reset` cron is then only
      needed to change the `admin` password.

- [ ] **Insights restricted to the budget tables (#226)**: after the upgrade, an insight
      whose query reads another table (or takes more than 5 s) shows 0: check the
      Insights tab of the instance and fix such queries.

- [ ] **Push relay — release the device bindings**: Carbure never calls
      `DELETE /v1/devices/{token}` of the relay. A device deleted from an instance (or
      moved to another instance) stays bound to the key of the first instance, so a second
      instance gets `forbidden` for it until `binding_ttl_days` (60 days) without push or
      an operator `devices:release`. Call it, best effort, when a device is deleted.
- [ ] **Push relay — key renewal answer lost**: `POST /system/push-register` with a key
      renews it; if the answer of `POST /v1/keys/rotate` is lost (timeout after the relay
      committed), the old key is revoked and the new one unknown: register again, and ask
      the operator to `devices:release` the devices (or wait 60 days).

- [ ] **Translated API errors (#366)**: the errors a user meets in the portal throw
      `Carbure\Lib\ApiError` (stable `error_code` + `params`, translated by the portal);
      the technical ones stay plain `Error` in English (pending migrations, woob/backup
      internals, notification kinds and channels, device platform, JWT/sync token, setup
      wizard). Give them a code when a user meets them; open tickets on the iOS and Android
      apps to translate `error_code` too (they show `error` until then).

- [ ] **Android app**: the backend is ready (devices with a `platform`, login
      `device.platform=android`, FCM provider in Carbure Push); to do: the app itself, the
      Firebase project and its service account on the relay.

- [ ] **Production** (device model, #218): the migration `2026-10-25_device_model`
      (nullable `devices.model`, `os_version`, `app_version`) is applied at container start;
      until it is, these fields are ignored at login and returned as `null`. It also sets
      `platform = 'android'` on the devices whose token is not an APNs one (Android devices
      stored as iOS before).
- [ ] **Android app** (#218): send `platform: "android"` in the `device` object of
      `POST /user/login` and `POST /user/login/mfa` (the server now deduces it from the FCM
      token for the released versions that do not), plus `model` (e.g. "Google Pixel 8"),
      `os_version` ("Android 15") and `app_version` ("1.0").
- [ ] **iOS app** (#218): send `model` (hardware model, e.g. "iPhone16,1"), `os_version`
      ("iOS 26.0") and `app_version` ("1.2") in the `device` object of `POST /user/login`
      and `POST /user/login/mfa` (optional: unknown when absent).

- [ ] **iOS and Android apps** (#427): offer to change the date a transaction is counted
      on from its detail (`PUT /transaction/date` with `id`, `date`; the real date `rdate`
      stays the one of the bank). No migration: the synchronization never updates the
      `date` of a known transaction.

- [ ] **Production**: test the connection of Claude to the MCP server from claude.ai
      (Settings → Connectors → Add custom connector). The Docker image applies the OAuth
      migration at startup and routes `/.well-known/oauth-*`; set `public_url`
      (**Administration → Settings** tab) if the HTTPS proxy of the NAS does not send
      `X-Forwarded-Proto`.

- [ ] **Production**: after the deployment, check on the Logs page that the IP of the
      entries is the client's, not the one of the DSM reverse proxy (it must send
      `X-Forwarded-For`). Set `log_retention_days` (missing from existing configurations:
      logs kept forever) in **Administration → Settings**.

- [ ] **Production** (sync token in the URL deprecated, #149): if a scheduled task of the
      NAS (DSM Task Scheduler, cron) calls `/api/bank/sync?token=…`, replace it by
      `curl -fsS -H "X-Sync-Token: <sync_token>" <url>/api/bank/sync > /dev/null` (the
      command of **Administration → Synchro**). Then check that the Logs page no longer shows
      the warning "The sync token passed in the URL is deprecated". Later: once no
      scheduler uses it, remove `?token=` from `GET /bank/sync` (the MCP server keeps its
      `?token=` for the agents that cannot send a header).

- [ ] **Production** (web browsers in the devices, #246): the migration
      `2026-10-26_user_browser` (table `user_browser`) is applied at container start (the
      code works before it: no browser listed). After the deployment, log in to the portal
      and check that **My profile → My devices → Web browsers** lists this browser with the
      "This browser" badge; behind the DSM reverse proxy over HTTPS the cookie
      `carbure_browser` must be Secure (`X-Forwarded-Proto: https` or `public_url`).

- [ ] **Production** (API sub-tab, #163): after the deployment, open **Administration → API**
      and check that Swagger UI is shown. A blank frame means the DSM reverse proxy adds its
      own `X-Frame-Options: DENY` (or `frame-ancestors 'none'`) to `/swagger/`: let it pass
      the headers of the container (`SAMEORIGIN`, `frame-ancestors 'self'`).

- [ ] **GitHub settings** (owner, after the merge of the workflow hardening, #125): in
      **Settings → Actions → General**, tick "Require actions to be pinned to a full-length
      commit SHA" and restrict the allowed actions to those of GitHub, verified creators
      and the list used by the workflows (`shivammathur/setup-php`, `hadolint/hadolint-action`,
      `aquasecurity/trivy-action`, `gitleaks/gitleaks-action`, `docker/*`); in the ruleset
      of `main`, add the check "Conventional Commits title" (workflow PR title) to the
      required checks and set **GitHub Actions** as the source of every required check (not
      "any source"). After the next release, check on the NAS
      `gh attestation verify oci://docker.io/ltoinel/carbure:<version> --owner ltoinel`.

- [ ] **Production**: the migration `2026-10-20_user_notification` (table of the
      notification channels chosen by each user) is applied at container start. To send the
      notifications by email, set the `[smtp]` settings in **Administration → Settings**
      (existing `prod.ini` files have no `[smtp]` section: it is added on save), then use
      **Send a test email**. Until then nothing changes: notifications are pushed only.

- [ ] **Production**: the migrations `2026-10-21_user_budget_threshold` (column
      `users.budget_threshold`, 100 for the existing users) and `2026-10-22_budget_alert`
      (alerts already sent) are applied at container start. Nothing changes until a user
      turns on "Budget of the month exceeded" in **My profile → Notifications** (opt-in).

- [ ] **Production**: the migration `2026-10-23_user_budget_alert_category` (column
      `users.budget_alert_category`, NULL = all the budgets for the existing users) is
      applied at container start. Until then the budget alerts watch every budget and
      choosing one in **My profile → Notifications** answers 409.

- [ ] Backup (Administration → Backup, #162): the import is limited to 32 MB of JSON (nginx
      `client_max_body_size` of `/api/backup/import`, PHP `post_max_size`) and decoded in
      memory (`memory_limit` 256M). Measured: 31 MB of JSON (95,000 transactions) reach
      about 230 MB of peak memory once decoded and checked, close to the limit; the usual
      household (10,000 to 20,000 transactions, 3 to 7 MB) is far below. If a household
      needs more, send the datasets in several requests (one transaction per request would
      then no longer cover the whole import) or stream the rows. The bank accounts (labels,
      owners) and connections are not in a backup: their credentials live in woob.

- [ ] Purge (Administration → Purge, #453): "local network only" trusts the addresses of
      the request (peer, `X-Forwarded-For`, `X-Real-IP`, `Forwarded`). A reverse proxy
      that forwards none of them makes a request from Internet look local: document that
      the NAS proxy must send `X-Forwarded-For` (passed to PHP by the nginx of the image), or add a
      setting that turns the purge off entirely.

- [ ] **Production** (two-factor authentication, #166): the migration `2026-10-24_user_mfa`
      (tables `user_mfa`, `mfa_recovery_code`, `mfa_trusted_browser`) is applied at
      container start; until it is, the second factor is never asked. Nothing changes until
      an administrator turns on `mfa_required` in **Administration → Settings** (off by
      default; existing `prod.ini` files get the line on save). Before turning it on: set up
      your own authenticator app from **My profile → Two-factor authentication** and keep the recovery codes (an
      administrator who loses both sets `mfa_required=false` in `data/conf/prod.ini`), and
      make sure every iPhone of the household has logged in once (a known device skips the
      code; a new one is refused, see the iOS item below).

- [ ] **iOS app** (two-factor authentication, #166): support the second step of the login.
      Send `mfa: true` to `POST /user/login`; on `{mfa: "verify"|"enroll", mfa_token, …}`
      ask the 6-digit code (or a recovery code; for `enroll`, show the `otpauth` URI /
      `secret` to add it to an authenticator app, then the `recovery_codes` returned) and
      call `POST /user/login/mfa` with `mfa_token`, `code` and the same `device` (registered
      only then). Until then, the current app logs in only from an iPhone already
      registered for that user: a new iPhone gets `403` "Two-factor authentication is
      required and this device is not known yet" while `mfa_required` is on.

- [ ] **Mobile apps** (login rate limit, #359): the Docker image answers `429`
      (`{"error", "code": 429}`, `Retry-After: 60`) after 30 logins per minute from one
      address (`POST /user/login`, `/user/login/mfa`, `/oauth/token`): show "too many
      attempts, wait a minute" instead of a wrong password.

- [ ] **Login rate limit behind a proxy** (#359): after the upgrade, check in the logs of
      the container (`docker logs`, line "client address taken from X-Forwarded-For") and
      in its access log that the address of the clients is theirs, not the one of the NAS
      reverse proxy; otherwise set `TRUSTED_PROXIES` (docs/securite.md).

- [ ] **Two-factor authentication, known devices** (#166 review): a device registered in
      `devices` skips the code, also one registered before `mfa_required` was turned on,
      and the administrator reset of the second factor does not remove the devices of the
      user (a lost iPhone stays known until the user deletes it in his profile). Once the
      iOS app supports the second step, consider removing the devices on reset (or letting
      the administrator remove them) and dropping the known-device bypass.

- [ ] Budget alerts only check the current month (an import of past months alerts
      nothing) and the spending as the Budgets screen computes it (absolute value of the
      sum of the month, refunds included): if alerts on past months are wanted, add a
      separate history check instead of widening `BudgetAlert::check()`.

- [ ] Strong authentication: handle the validations by **code** (SMS OTP, woob
      `BrowserQuestion`), which wait for input on stdin (closed today by
      `Woob::executeWithHeartbeat`), in the synchronization and in the listing of the
      accounts of a bank (`GET /bank/discover/interactive`, #299: validations in the bank app only).

- [ ] MySQL connection in `utf8mb4` (`Db` does not call `set_charset`: the connection uses
      the default charset of the server). Before adding it, check in production what PHP
      receives and how accents are stored:

      ```bash
      docker compose exec carbure php -r '$c = parse_ini_file("/data/conf/prod.ini");
        $m = new mysqli($c["db_hostname"], $c["db_username"], $c["db_password"], $c["db_name"], (int)($c["db_port"] ?? 3306));
        echo $m->character_set_name(), "\n";
        foreach ($m->query("SELECT name, HEX(name) AS h FROM bank_transaction_category WHERE name LIKE \"%pargne%\"") as $r) echo $r["name"], " ", $r["h"], "\n";'
      ```

      `utf8mb4` (and `É` = `C389`): `set_charset('utf8mb4')` changes nothing, add it.
      `latin1` with `É` = `C383E280B0`: accents are stored double-encoded; a conversion
      migration is needed first, otherwise the portal would show "Ã‰pargne".
      Symptom of a latin1 server: the API answers 500 ("cannot be encoded in JSON" in the
      logs) as soon as an accented text was written by a utf8mb4 connection.
      Account names (`bank_account.label`, often accented: « Compte chèques ») are also
      covered by such a conversion; the 100-character limit is checked with `mb_strlen`.
- [ ] Greedy `PRLV SEPA` regex (`ECH/…` is not removed from the label) and `addslashes()`
      applied before a prepared statement (double escaping). Fixing them changes the labels,
      hence the UUIDs: plan an SQL migration that recomputes labels and UUIDs exactly like
      PHP (amount in PHP float format `-42.5`, first 24 **bytes** of the label for cards,
      only the rows whose current UUID matches the formula), and handle UUID collisions.
      The regex is in the `prod.ini` of each instance: fixing it in `prod.sample.ini` is not
      enough. Repetitions of an identical transaction have the UUID `md5(uuid . '#n')`
      (`Transaction::prepareAll()`): the migration must recompute them from the base UUID,
      rank by rank.
- [ ] Bank profiles of the import (`src/lib/import/banks/`) kept the reading of the former
      heuristics: the French Qonto export is booked on its operation date (the English one
      on its settlement date), Boursorama ignores its value date and `comment`. Aligning
      them changes the dates (not the UUID, made of the real date): check with real files
      first. Variants without a fixture are still read by the generic reader: BNP Paribas
      with `,`, the former N26 export in English or German (no public sample yet).
- [ ] The account is not part of the UUID: the same operation (real date, label, amount) on
      two synchronized accounts is stored only once. Adding it changes every UUID (migration,
      or unique key `(account_number, uuid)`); only worth doing if accounts of the household
      can have identical operations.
- [ ] The PHP notifications and emails (push notifications, e-mails) read the same translation files
      as the portal (`portal/i18n/<locale>.json`) instead of their `$french ? … : …` strings
      (separate issue to create).
- [ ] Spanish, Italian, German and Portuguese (#167): the branches that add portal keys
      to `fr.json`/`en.json` (profile sub-tabs, backup/restore, admin API tab, MFA…) must
      also add them to `es.json`, `it.json`, `de.json` and `pt.json` (parity test of
      `tests/portal/i18n.test.mjs`). Have the four translations proofread by native
      speakers.
- [ ] iOS app: check that a profile language `es`, `it`, `de` or `pt` (returned by
      `GET /user/me`) is handled (the app only knew `fr`/`en`).
- [ ] Savings of the Trends tab: the default `savings_category` is `Epargne`, which matches
      only the French starter category; an installation in another language (`Savings`,
      `Ahorro`, `Risparmio`, `Sparen`, `Poupança`) has to set it in Settings. Use the
      starter category id (31) or a default per language.
- [ ] iOS app and MCP: the custom charts and the "By category" chart of the Trends tab
      (`GET /budget/trends/charts`, `GET /budget/trends/series`,
      `GET /budget/trends/categories`) and the periods up to 240 months are portal-only; show
      them in the iOS Trends screen, and expose the series to the MCP `get_trends` tool.
- [ ] Translations of the custom charts, custom range and "By category" chart of the Trends
      tab (`trendChart*`, `trendCategories*`, `trendsKind*`, `trendsChartKind`,
      `trendsCustomRange`, `trendsFrom`, `trendsTo`, `trendsApplyRange`, `trendsRangeInvalid`)
      in `de`, `es`, `it`, `pt`: to be proofread by native speakers.
