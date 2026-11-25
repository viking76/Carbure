# AGENTS.md

Rules for coding agents and contributors on Carbure. Pending work: [TODO.md](TODO.md).

## Project

Household budget/bank-transaction tracker: **portal** `portal/` (Vue 3 global build, no
bundler) + **PHP 8.2+ REST API** `src/` (no framework, no runtime dependency; Composer only
for dev tools: PHPStan, PHPUnit), also used by the
**iOS app**. Transactions synced via woob, categorized by keywords; push notifications
through the external relay Carbure Push (private repo `ltoinel/Carbure-push`: APNs, FCM);
read-only MCP server at `/api/mcp`. Production = Docker image (nginx + PHP-FPM + woob)
built by the GitHub release workflow; committing/pushing is not deploying.

## Layout

| Path | Content |
|---|---|
| `src/api.php` | HTTP entry (`/api/*`); without `conf/<env>.ini` serves only `/api/setup` |
| `src/autoload.php` | Registers `ClassLoader` (PSR-4, on demand, no Composer), then `Config::load(getenv('APP_ENV') ?: 'prod')` |
| `src/lib/` | Namespace `Carbure\Lib`: `ClassLoader`, `Config`, `Db` (mysqli), `Logger`, `Jwt`, `Webservice` (+ security headers), `ApiError` (error with a stable code), `ApiResolver`, `ApiRoute`, `Woob`, `PushRelay` (client of Carbure Push), `Mailer` (SMTP), `Messages` (fr/en texts of the notifications), `Setup`/`Installer`, `Migrator`, `Setting`, `SqlTables` (tables read by an insight query), `Network` (request from the local network) |
| `src/lib/import/` | Namespace `Carbure\Lib\Import`: statement import behind `BankFile::parse()`: `OfxReader`, `QifReader`, `CamtReader`, `CsvReader` (+ `CsvGeneric` fallback), `BankProfiles` (schema) |
| `src/lib/import/banks/` | One CSV profile per bank (`<id>.php` returns an array, no class: loaded by path by `BankProfiles`); fixtures `tests/fixtures/import/`, snapshots `…/expected/` |
| `src/resources/` | Namespace `Carbure\Resources`: `User`, `Bank`, `Transaction`, `Budget`, `Category`, `Insight`, `Device`, `Notification`, `ApiToken`, `Mcp`, `OAuth`, `System`, `Backup`, `Purge` |
| `portal/` | Mixins in `modules/`, API calls in `services/apiService.js`, texts in `i18n/<locale>.json` (loader `i18n.js`) |
| `swagger/` | OpenAPI via reflection + Swagger UI |
| `sql/carbure.sql`, `sql/migrations/` | Full schema; dated migrations (`Migrator`, table `schema_migrations`) |
| `tools/migrate.php` | CLI migrations (run by the Docker entrypoint) |
| `tools/woob-trust.php` | CLI run by the Docker entrypoint while woob trusts no keyring: trusts the official woob repository when its keyring is the pinned one (`WoobRepository::OFFICIAL_KEYRINGS`), then installs the modules |
| `tools/install.php` | CLI installation without the web wizard (`Carbure\Lib\InstallCommand`, same path as `Setup`): scripted installs, YunoHost package |
| `docker/` | `Dockerfile`, `nginx.conf`, `docker-compose.yml`, `docker-compose.dev.yml`; `demo/` (`Dockerfile`, `entrypoint.sh`, `docker-compose.yml`, `run.sh`) = demo image (MariaDB + `seed.php --demo` inside) |
| `data/` | Untracked instance data: `conf/prod.ini`, `logs/`, `cache/`, `woob/` |
| `conf/*.sample.ini`, `testing.ini`, `e2e.ini` | Sample/test config (`prod.ini` never tracked) |
| `tests/unit/`, `tests/integration/` | PHPUnit (integration needs MariaDB) |
| `tests/portal/` | Portal unit tests (`node:test`) |
| `tests/e2e/` | Playwright, real API, DB recreated per test; `server/seed.php` = also `start.sh` data |
| `docs/`, `mkdocs.yml` | MkDocs site carbure.org, French `page.md` + English `page.en.md` (mkdocs-static-i18n, English under `/en/`); `VERSION` = `dev` in repo |

## Commands

```bash
./start.sh   # dev stack http://localhost:8000/portal/ (admin/admin-password); --reset --build --stop --clean
find src tests swagger -name '*.php' -not -path '*/node_modules/*' -print0 | xargs -0 -n1 php -l
composer install && composer phpstan   # dev tools (PHPStan, PHPUnit) + PHP types
for f in $(find portal -name '*.js' -not -path '*/vendor/*'); do cp "$f" /tmp/c.mjs && node --check /tmp/c.mjs; done
composer test                          # PHPUnit unit suite (vendor/bin/phpunit --testsuite unit)
DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root composer test:all   # + integration (recreates carbure_test)
node --test 'tests/portal/*.test.mjs'
cd tests/e2e && npm ci && npx playwright install chromium               # once
E2E_DB_HOST=127.0.0.1 E2E_DB_PASSWORD=root PHP_BIN=/path/to/php npx playwright test   # PHP_BIN defaults to php
git config core.hooksPath .githooks   # pre-commit lint, pre-push unit tests
```

## Local environment

- LF line endings (`.gitattributes`).
- Machine-specific notes (shell, how PHP/Composer are installed) go in an untracked
  `CLAUDE.local.md`, not here.

## API conventions

- Flow: `api.php` → `Webservice::exec()` → `ApiResolver::findRoute()` → static method in
  `src/resources/`. Route cache in APCu (fully qualified class names), auto-invalidated
  on file mtime changes (key versioned: bump it when the cached `ApiRoute` or class
  names change).
- **Namespaces**: `src/lib/X.php` = `Carbure\Lib\X`, `src/lib/import/X.php` =
  `Carbure\Lib\Import\X`, `src/resources/X.php` = `Carbure\Resources\X` (PSR-4: class name
  = file name, case-exact for Linux; a StudlyCase sub-namespace = a lowercase
  sub-directory). Classes are loaded on demand by `Carbure\Lib\ClassLoader`
  (`spl_autoload_register`, never Composer at runtime): no `require_once` between classes.
  Import every other class with `use` at the top, PHP's global ones included (`use Error;`,
  `use mysqli;`, `use Throwable;`); scripts outside `src/` (tests, `tools/`, `swagger/`)
  `use` the classes and load `src/autoload.php`.
- New resource: `src/resources/Foo.php` with `namespace Carbure\Resources;`, `final class Foo`
  and its routes: nothing to register (`ApiResolver` scans the directory). Swagger tags
  and logs use the short class name.
- Route = **public static** method with
  `#[ApiRoute('/path', method: 'GET', public: false, stream: false, raw: false, admin: false)]`.
  `public: true` = no JWT; `stream: true` = SSE (`Webservice::sendProgress()`/`sendHeartbeat()`);
  `admin: true` = administrators only, checked by `Webservice` before the method is called
  (403 of `User::requireAdmin()`; invalid with `public: true`); `quiet: true` = successful
  calls logged at DEBUG, errors unchanged (only `GET /health`, pinned by `ApiResolverTest`).
- Request params (JSON body + query string) are injected **by name** into method params:
  renaming one breaks the API; unknown param → 400.
- **Errors**:
  - `throw new Error(msg, 4xx)` (code = HTTP status); `Exception` = 500.
    Response: `{"error","code","uid"}`.
  - An error a user meets in the portal is a
    `new ApiError(msg, 4xx, 'stable_code', ['name' => $value])` (`Carbure\Lib\ApiError`,
    still an `Error`): the response adds `error_code` (+ `params`), the English `error`
    unchanged.
  - Add `apiErrors.<stable_code>` (`{name}` placeholders) to the 6 `portal/i18n/*.json`
    (`tests/portal/apiErrors.test.mjs`).
  - The portal shows errors through `this.apiErrorText(error)` (its translation, else
    its message).
- SQL: prepared statements only (`Db::execute`, `Db::queryOne`).
- Current user: `Jwt::getUserIdFromToken()`; headers: `Webservice::getHeader()`.
- Admin actions (`users.is_admin`): use `admin: true` above; call `User::requireAdmin()`
  in the method only for a conditional check (e.g. `PUT /user` on another user or
  `is_admin`). Portal shows them only if `isAdmin`. Non-admins read/edit
  only their own profile. `tests/unit/ApiResolverTest.php` pins the access level
  (public/user/admin) of every route: add each new route there.
- Passwords: `password_hash()`; legacy salted SHA-256 migrated at login (`User::verifyPassword`).
- `Logger` masks `password`/`token`; never log secrets otherwise.
- PHPDoc on every method; match existing style.
- **Types**:
  - Every parameter, return value and property has a native type (no
    `declare(strict_types=1)`: request params are coerced); arrays typed in the PHPDoc
    (`array<string, mixed>`, `list<…>`).
  - PHPStan checks it in pre-commit and CI: `phpstan.neon` (level 8 + rule
    `tools/phpstan/NativeTypesRule.php` + deprecation rules), tests `phpstan.tests.neon`
    (level 5). No `@phpstan-ignore`, no baseline.
  - Use the typed helpers: rows through `Db::select()`/`Db::result()` (never a `false`
    result), dates through `Month::timestamp()`, files through `File::read()`.
  - Route params come as strings from the query string: never a bare `bool` (`"false"`
    would be `true`); keep what the iOS app sends valid.
- **Complexity**: cyclomatic complexity ≤ 10 per function/method (rule
  `tools/phpstan/CyclomaticComplexityRule.php`, threshold in `phpstan.neon`): split a
  growing method into small private ones, use lookup tables instead of if/else chains.

## Domain rules

- **Household model**: all users see the same data; budgets/categories have no user
  column; a transaction belongs to the first owner (`MIN(user_id)`) of the synced account.
- **Transaction UUID** = `md5(rdate . label . amount)` (card labels truncated to 24
  chars), computed after `Transaction::cleanLabel()` + config `regex_label`. Identical
  rows in one batch: `md5(uuid . '#n')` (`Transaction::prepareAll()`). Changing label
  cleaning creates duplicates → needs a migration.
- **Statement import**: a bank's CSV format = a profile in `src/lib/import/banks/` with its
  anonymized fixture, its snapshot (`tests/fixtures/import/expected/`) and its row in the
  bank table of `docs/fonctionnalites.md` (tests check all three); no bank-specific rule in
  `CsvGeneric`. Existing snapshots must not change (labels feed the UUID).

## Compatibility & safety

- **Backward compatibility**: iOS app isn't updated with the server. Only add routes or
  optional params; never rename/remove or change an existing response format.
  `GET /health` publishes `min_mobile_version` (`Carbure\Lib\MobileApp::MIN_VERSION`): the
  oldest app still supported. Raise it only for an unavoidable break, once the new app is
  on the App Store.
- **Schema**: change via `sql/migrations/YYYY-MM-DD_name.sql` (mandatory
  `-- applied-if: …` line, test-checked) **and** `sql/carbure.sql`. Applied at container
  start, by setup wizard, or portal admin banner. Code must not depend on an unapplied
  migration; flag it as a deployment action.
- **Public routes** limited to: `POST /user/login`, `POST /user/login/mfa` (second step:
  needs the 5-minute challenge given by the password step), `GET /bank/sync` (JWT or
  `sync_token`), `GET|POST /mcp` (token or JWT), `GET /health`, MCP OAuth
  (`/.well-known/oauth-*`, `POST /oauth/register`, `GET /oauth/authorize`,
  `POST /oauth/token`). A unit test fails on any new public route.
- **Secrets**: nothing secret/personal/logs in the repo; samples use fake values. Never
  print or copy `data/conf/prod.ini`. Tests must not write to `data/logs/`.
- Security headers: API via PHP; portal via nginx (`docker/nginx.conf`, no `.htaccess`).
- **No external CDN**: every script, style, font and image is vendored and served locally
  (`portal/vendor`, `swagger/vendor`, docs: Material `privacy` plugin); the CSP of the
  portal and Swagger allows only `'self'` (+ `data:` images). `tests/portal/localAssets.test.mjs`
  checks it.

## Portal

- Every string via `t('key')`; add each key in **all 6** `portal/i18n/*.json`
  (fr, en, de, es, it, pt; same `{placeholders}`). Plurals: `<key>_one` / `<key>_other`,
  called with `t('key', { count })`, never "(s)". `tests/portal/i18n.test.mjs` checks
  parity, used and unused keys.
- Any added/changed feature gets a Playwright spec in `tests/e2e/specs/` (data: `seed.php`).
- Accessibility (WCAG 2.2 AA) is mandatory: text contrast ≥ 4.5:1 (icons 3:1) at rest,
  on hover and on focus — never lower it with `opacity` or a grey label on a colored
  background; a button class that sets its own background keeps it on hover.
  `specs/accessibility.spec.js` checks every tab: extend it when adding a screen or modal.
- A new library, font or image goes in `portal/vendor/` (listed in its `README.md` with
  version, source and license): see **No external CDN** above.

## Issues, branches, PRs and commits

- Every change starts from an issue (assigned, in the next milestone); the PR says `Closes #<n>`.
  No open milestone (the last one is released): create the next patch one (`v1.11.0` → `v1.11.1`).
- Never push to `main`: branch from `origin/main` as `<type>/<issue>-<short-topic>`
  (e.g. `fix/26-codeql-alerts`).
- PRs are squash-merged and the **PR title becomes the commit of `main`**: it follows
  [Conventional Commits](https://www.conventionalcommits.org/), checked by CI:
  `type(scope): description` — English, imperative, lowercase start, no final period.
  - types: `feat`, `fix`, `security`, `perf`, `refactor`, `docs`, `test`, `ci`, `chore`
    (`build`, `revert` also accepted);
  - optional scopes: `api`, `portal`, `sync`, `import`, `mcp`, `oauth`, `docker`, `db`, `deps`…;
  - breaking change: `type(scope)!: …` (exceptional: the iOS app must keep working).
- Commit messages inside a branch: same format; body = bullet list of changes.
- A PR that takes up someone else's work (a contributor's PR, a patch from an issue) credits
  them: the squash commit ends with `Co-authored-by: Name <id+login@users.noreply.github.com>`
  (`gh pr merge <n> --squash --body "…"`, GitHub drops it otherwise), and the PR body says
  where the work comes from (`from #329`).
- Label the PR with its kind (`enhancement`, `bug`, `security`, `documentation`,
  `dependencies`): `.github/release.yml` groups the release notes by label.

## Definition of done

- PHP/JS lint clean, PHPStan clean; unit tests green, new code tested.
- Portal change: e2e spec added, e2e suite green.
- PHP coverage ≥ 90 % in CI (unit + integration).
- SQL migration if schema changed, flagged as deployment action.
- `TODO.md` updated (check done, add discovered debt/manual steps).
- `README.md` (French) and `README.en.md` / `docs/` updated if behavior or API changes, in French **and** English: a
  `docs/<page>.md` changed without its `docs/<page>.en.md` fails the docs CI
  (`tools/check-docs-english.sh`); English headings keep the French anchors (`## Title { #titre }`).
