---
title: "Development: contributing to Carbure"
description: "Contribute to Carbure: repository layout, Docker environment, PHPUnit and Playwright tests, PHPStan analysis, conventions and continuous integration."
icon: material/code-braces
---

# Development

## Repository layout { #organisation-du-depot }

| Path | Contents |
|---|---|
| `src/lib/` | `Carbure\Lib` namespace: technical foundation (configuration, database, logs, JWT, routing, woob, push notification service client, setup wizard, migrations, settings) |
| `src/lib/import/`, `src/lib/import/banks/` | `Carbure\Lib\Import` namespace: reading imported statements (OFX, QIF, CAMT, CSV) and bank CSV profiles |
| `src/resources/` | `Carbure\Resources` namespace: API resources |
| `portal/` | Vue 3 web portal |
| `swagger/` | OpenAPI generator and Swagger UI |
| `sql/` | Schema and migrations |
| `tests/unit/`, `tests/integration/` | PHPUnit tests |
| `tests/portal/` | Portal unit tests (`node:test`) |
| `tests/e2e/` | Portal end-to-end tests (Playwright) |
| `tools/migrate.php` | Schema migrations (`--status`, `--dry-run`, `--baseline`) |
| `tools/coverage-check.php` | Coverage threshold check |
| `composer.json`, `phpstan.neon`, `phpstan.tests.neon`, `phpunit.xml`, `tools/phpstan/` | Development tools (PHPStan and PHPUnit 11, installed by Composer in `vendor/`) and Carbure's rules; the API itself has no dependencies |
| `docker/` | `Dockerfile`, `docker-compose.yml` (with MariaDB), nginx and PHP-FPM configuration, `entrypoint.sh` |
| `data/` (not versioned) | Instance data: `conf/` (`prod.ini`), `logs/`, `cache/`, `woob/` (banks and credentials) |
| `VERSION` | Carbure version (`dev` in the repository, written by the release build) |
| `docs/`, `mkdocs.yml` | This documentation |

Detailed rules for contributors and coding agents: `AGENTS.md`.
Open tasks: `TODO.md`.

## Running locally { #demarrer-en-local }

```bash
./start.sh            # http://localhost:8000/portal/  (admin / admin-password, marie / marie-password)
./start.sh --reset    # start over from the sample data (also after changing sql/carbure.sql)
./start.sh --fresh    # start over from an empty database and configuration: setup wizard
./start.sh --build    # rebuild the image (changes to docker/ or to the PHP extensions)
./start.sh --fake-woob  # stubbed woob: sample accounts sync without a bank
./start.sh --stop     # stop (the database and /data are kept)
./start.sh --clean    # stop and delete the database and /data
./start.sh --help     # help
```

`start.sh` reproduces the production architecture: it runs `docker/docker-compose.yml`
(the production compose file) with the `docker/docker-compose.dev.yml` override. Same
containers as the target: the **Carbure Docker image** (`docker/Dockerfile`: nginx,
PHP-FPM, woob, an entrypoint that applies the migrations) and **MariaDB 13**, with a `/data`
volume and a `/data/conf/prod.ini`, just like an installed instance. The override only
changes:

- the code (`src/`, `portal/`, `swagger/`, `sql/`, `tools/`), **mounted** from the repository
  instead of being copied into the image: a change takes effect on the next request,
  without restarting or rebuilding;
- the ports, which only listen on `127.0.0.1`; scheduled sync, which is disabled;
- the database, `carbure_dev`, and `/data`, a Docker volume (the local `data/` folder is not
  mounted).

There is only one application image, the production one: you develop on what will be
shipped. Rebuilding the image (`--build`) is only needed after changing
`docker/` (Dockerfile, nginx, PHP).

- On first launch, the image is built (a few minutes for woob) and the database
  receives the end-to-end test dataset (`tests/e2e/server/seed.php`): a
  household with three months of transactions, budgets, rules and two accounts.
- `prod.ini` is rewritten on every start (`log_level=debug`, database `carbure_dev`).
  The real woob is used by default; `--fake-woob` replaces it with the tests' fake woob
  (`tests/fixtures/fake-woob.php`), which answers in place of the banks: the sample
  accounts sync (`fail@bank` fails on purpose), and adding a bank and
  discovering accounts work without credentials. Options can be combined
  (`./start.sh --reset --fake-woob`).
- `--fresh` reproduces a first installation: the database and `/data` are emptied, no
  `prod.ini` is written and no sample data is loaded; the portal shows the
  **setup wizard**, prefilled with the dev database (starter pack
  included). Subsequent starts keep the configuration and data created by
  the wizard (`/data/conf/.wizard` marker), until `--reset` or `--clean`.
- `PORT` (8000) and `DB_PORT` (3308, access to MariaDB from the host: `carbure` / `dev`)
  change the ports, which only listen on `127.0.0.1`. Logs: the portal's Logs tab,
  or `docker compose -f docker/docker-compose.yml -f docker/docker-compose.dev.yml exec carbure ls /data/logs`.

## Adding a route { #ajouter-une-route }

```php
namespace Carbure\Resources;

use Carbure\Lib\ApiRoute;

final class Transaction {

    /**
     * Search transactions by label.
     *
     * @param string $query The text to find in the label
     * @param int    $limit The maximum number of transactions
     * @return array The list of transactions
     */
    #[ApiRoute('/transaction/search', method: 'GET')]
    public static function search($query, $limit = 100)
    {
        // ...
    }
}
```

- The method must be **public and static**, in a class of `src/resources/`
  (`Carbure\Resources` namespace, file name = class name, case included):
  nothing else to declare, `ApiResolver` scans the folder.
- Classes are loaded on demand (PSR-4) by `Carbure\Lib\ClassLoader`, registered
  by `src/autoload.php`, without Composer: no `require_once` between classes, but a
  `use` at the top of the file for each class from another namespace, including PHP's
  global classes (`use Error;`, `use mysqli;`). A sub-namespace
  (`Carbure\Lib\Import`) maps to a lowercase subfolder (`src/lib/import/`).
- Request keys are passed **by name**: parameter names are part
  of the API contract.
- `public: true` disables the JWT check; `stream: true` produces an SSE response;
  `raw: true` returns the text returned by the method as is (MCP server);
  `quiet: true` writes the successful calls at the `debug` level only (`GET /health`,
  polled by the healthchecks; its errors keep their level). A unit test checks the list
  of public routes and of `quiet` routes.
- Administration route: declare it `admin: true` in its attribute; `Webservice` rejects it
  (`403`) for other users before calling the method. `User::requireAdmin()` is only
  for conditional checks inside the method (e.g. `PUT /user` on another
  user). Add the portal tab or action to the list reserved for
  administrators.
- A unit test (`tests/unit/ApiResolverTest.php`) pins the access level of each
  route (`public`, `user` or `admin`): add every new route to it.
- Client error: `throw new Error("message", 400)`; server error: `Exception`.
- Current user: `Jwt::getUserIdFromToken()`.

!!! warning "Compatibility"
    The API is used by the iOS app, which is updated independently of the server. Only add
    routes or optional parameters; never rename or remove anything.

## Adding a bank to the import { #ajouter-une-banque-a-limport }

A CSV statement is read with its **bank's profile** when one of its first lines is
exactly the header of a known export, and no other profile claims it; otherwise
its columns are guessed from their names (generic reader `CsvGeneric`, which keeps the
heuristics from before profiles). OFX, QIF and CAMT.053 are read the same way for
all banks (`OfxReader`, `QifReader`, `CamtReader`). `BankFile::parse()` remains the
entry point and reports in `bank` the profile that read the file.

To add a bank's CSV export:

1. **Profile**: `src/lib/import/banks/<id>.php` (lowercase identifier, `-` between
   words) returns an array whose schema is described and checked by `BankProfiles` (unknown
   or missing key, column missing from the header: exception, raised by the tests).
   Its comment gives the source of the format and its particularities.

    ```php
    <?php

    /**
     * Ma Banque: export CSV of the operations.
     *
     * Particularities: lines about the account before the header, ";", Windows-1252.
     * Sources: open source projects that read this format.
     * Fixture: tests/fixtures/import/ma-banque.csv. Schema: BankProfiles.
     */

    return [
        'name' => 'Ma Banque',            // shown by the preview, row of the documentation table
        'formats' => [[                   // one format per variant (old and new, language)
            'header' => ['Date', 'Libellé', 'Débit', 'Crédit'],  // the header written by the bank
            'delimiter' => ';',           // ; , tab or |
            'preamble' => 5,              // lines about the account before the header (0 by default)
            'encoding' => 'Windows-1252', // encoding of a file that is not UTF-8
            'day_first' => true,          // dd/mm; guessed from the dates if absent
            'columns' => [                // column => its name in header
                'date' => 'Date', 'label' => 'Libellé', 'debit' => 'Débit', 'credit' => 'Crédit',
            ],
            // 'skip_statuses' => ['PENDING'],  // values of the status column for ignored lines
            // 'filename' => '/^Historique/',   // file name required in addition to the header
        ]],
    ];
    ```

    Columns: `date` (booking date) and `label` are required; `operation` (transaction
    date), `detail` (appended to the label, unless one contains the other), `amount`
    (signed) **or** `debit` and `credit` (a debit is negative whatever its sign),
    `fee` (deducted from the amount), `status`. The header is compared ignoring case, accents and
    punctuation, without the empty cells at the end of the line.

2. **Fixture**: an **anonymized** export with the same structure (lines before the header,
   encoding, delimiter, line endings) in `tests/fixtures/import/<id>.csv`, added to
   `BankProfilesTest::bankFixtures()`. The tests check that each fixture is only
   recognized by its profile and that each format of a profile has its fixture.
3. **Snapshot**: `tests/fixtures/import/expected/<id>.csv.json`, the expected output of
   `BankFile::parse()` (without `bank`), checked transaction by transaction; existing
   snapshots do not change.
4. **Documentation**: a row named after the profile in the banks table of
   [Features](fonctionnalites.md#import-de-releves-bancaires) (a test compares the
   table with the profiles).

!!! warning "Labels and fingerprints"
    Changing the label read for a bank changes the fingerprint (UUID) of its transactions:
    an already imported file would show its lines as likely duplicates.

## Types { #types }

Every parameter, return value and property of the PHP code has a native type (checked by
PHP at runtime); arrays are detailed in the PHPDoc (`array<string, mixed>`).
PHPStan checks this (level 8, the `tools/phpstan/NativeTypesRule.php` rule and the
[deprecation rules](https://github.com/phpstan/phpstan-deprecation-rules)): a value that may
be `false` (result of a query, of `strtotime()`, of a file read) is not used as is, hence
`Db::select()`, `Db::result()`, `Month::timestamp()` and `File::read()`, which throw an
exception instead; a value that may be `null` (missing row, failed `preg_replace()`) gets its
default. The tests are analysed separately (`phpstan.tests.neon`, level 5,
without Carbure's rules):

```bash
composer install     # once: PHPStan and PHPUnit in vendor/ (development tools)
composer phpstan
```

No `declare(strict_types=1)`: request parameters arrive as strings
(query string) and are converted. A route flag is never typed as a plain `bool`
(`"false"` would become true).

## Complexity { #complexite }

The cyclomatic complexity of each function or method (1 + one per decision: `if`,
`elseif`, loop, `case`, `catch`, ternary, `&&`/`||`, `match` arm) is capped at
**10** (rule `tools/phpstan/CyclomaticComplexityRule.php`, threshold in `phpstan.neon`),
checked along with the types by `composer phpstan`, in pre-commit and in CI. A method that
grows gets split into small private methods.

## Tests { #tests }

```bash
composer install     # once: PHPUnit in vendor/

# Unit: no external dependency (vendor/bin/phpunit --testsuite unit)
composer test

# Unit + integration: MariaDB/MySQL required (vendor/bin/phpunit)
DB_HOST=127.0.0.1 DB_USER=root DB_PASSWORD=root composer test:all
```

- `APP_ENV=testing` (set in `phpunit.xml`) loads `conf/testing.ini`.
- Integration tests recreate the `carbure_test` database from `sql/carbure.sql`
  (`tests/bootstrap.php`).
- A run's temporary files (fake woob state, fake SMTP server
  certificate…) go into its own directory, `carbure-test-<pid>` in the temporary directory,
  deleted at the end and passed to the fake woob through the `CARBURE_TEST_TMP` variable: two
  runs on the same machine (two worktrees, the e2e suite) do not interfere with each other.
- Goal: **≥ 90% coverage** of the code in `src/`, measured in CI across all
  suites.

### Portal unit tests { #tests-unitaires-du-portail }

```bash
node --test 'tests/portal/*.test.mjs'
```

The portal modules that do not depend on the DOM (`utils/`, `services/apiService.js`
with a fake `fetch`, `stores/budgetStore.js`, the methods of the `modules/` mixins)
are tested with Node's test runner, with no dependencies. `tests/portal/setup.mjs`
provides the few browser objects they need (`window`, `CSS`, `Vue`).

### Portal end-to-end tests { #tests-de-bout-en-bout-du-portail }

The Playwright tests in `tests/e2e/specs/` drive the portal in Chromium, against the
**real API** and a MariaDB `carbure_e2e` database recreated before each test: they also check
that the portal and the API agree (routes, parameters, responses).

```bash
cd tests/e2e
npm ci && npx playwright install chromium   # only once
E2E_DB_HOST=127.0.0.1 E2E_DB_PASSWORD=root npx playwright test
npx playwright test specs/budget.spec.js     # a single file
npx playwright test --ui                     # interactive mode
npx playwright show-report                   # HTML report of the last run
```

- Playwright starts PHP's built-in server (`php -S`) with the test router
  `tests/e2e/server/router.php` (`APP_ENV=e2e`, `conf/e2e.ini`): it serves the portal,
  the API, and `/api/__e2e/reset`, which recreates the database with the dataset from
  `tests/e2e/server/seed.php` (household, categories, budgets, rules, two accounts). This
  router is never served by nginx and refuses to answer outside `APP_ENV=e2e`.
- You need a PHP with `mysqli` (`PHP_BIN` to pick one) and a MariaDB/MySQL database:
  `E2E_DB_HOST`, `E2E_DB_PORT` (3306), `E2E_DB_USER` (root), `E2E_DB_PASSWORD`. For example
  `docker run -d -e MARIADB_ROOT_PASSWORD=root -p 3306:3306 mariadb:10.11`.
- woob is replaced by `tests/fixtures/fake-woob.php`, logs go to
  `tests/e2e/.data/logs/`. The fake woob state is in `carbure-e2e-<port>` in the
  temporary directory (`CARBURE_TEST_TMP`, set by `playwright.config.js`): two
  parallel runs each take their own port (`E2E_PORT`) and database
  (`E2E_DB_NAME`, ending in `_e2e`).
- Tests run one after another (a single database). Expected texts are read
  from `portal/i18n/<language>.json` (`tr()` in `tests/e2e/fixtures.js`, which reuses the
  portal's translation, plurals included): changing a label does not break the tests.
- The setup wizard and the administration banners mock the API responses
  (`page.route`), since the test server is already installed and up to date.

## Lint { #lint }

```bash
find src tests swagger -name '*.php' -print0 | xargs -0 -n1 php -l
for f in $(find portal -name '*.js'); do cp "$f" /tmp/check.mjs && node --check /tmp/check.mjs; done
```

The portal files are ES modules: `node --check` must read them as `.mjs`.

## Git hooks { #hooks-git }

```bash
git config core.hooksPath .githooks
```

| Hook | Check |
|---|---|
| `pre-commit` | `php -l` and `node --check` on staged files, PHPStan if a PHP file is staged (`composer install` required) |
| `pre-push` | PHP unit tests (`composer install` required) and portal unit tests |

## Continuous integration and delivery { #integration-et-livraison-continues }

| Workflow | Trigger | Steps |
|---|---|---|
| CI | Pull request, push to `main` | PHP/JS/CSS lint, portal unit tests, unit and integration tests (MariaDB), coverage ≥ 90%, portal end-to-end tests (Playwright, report as an artifact on failure); Docker image: hadolint, build, Trivy scan, start with `docker compose`, installation through the wizard and smoke test |
| Release | Push of a tag (`v1.2.0` or `1.2.0`), or manual run with the tag | CI, `amd64`/`arm64` Docker image published to Docker Hub (`ltoinel/carbure`) (version passed through `CARBURE_VERSION`), with SBOM and provenance, and a provenance attestation signed by GitHub; then creation of the release with the application archive (`src`, `portal`, `swagger`, `sql`, `tools/migrate.php`, `VERSION` written from the tag) and the generated notes |
| Security | Pull request, push to `main`, every Monday, manual run | Secret scanning of the history (gitleaks), vulnerabilities in the repository's dependencies (Trivy); every Monday and on demand, vulnerabilities in the image published on Docker Hub (Trivy). Reports in the Security tab |
| Docs | Push to `main`, pull request (documentation changed) | MkDocs build; on `main`, GitHub Pages deployment |
| PR title | Pull request (opened, edited, pushed) | Title compliant with Conventional Commits (it becomes the `main` commit) |

Actions are pinned by their commit hash (`uses: owner/action@<sha> # vX.Y.Z`),
updated by Dependabot: to add one, resolve the tag's hash
(`gh api repos/<owner>/<action>/commits/<tag> --jq .sha`). Each job has a
`timeout-minutes`; a new push to a pull request cancels its previous run,
never those of `main`.

Publishing a version: push a tag on `main` (do **not** create the release in
the GitHub interface):

```bash
git switch main && git pull
git tag v1.2.0 && git push origin v1.2.0
```

The workflow runs the CI, publishes the `ltoinel/carbure` Docker Hub image (`1.2.0`, `1.2` and
`latest`), then creates the release with its archives and the generated notes (grouped by
label, `.github/release.yml`). A tag with a suffix (`v1.2.0-rc.1`) produces a
pre-release, without the `latest` tag. The repository's releases are **immutable**: no file
can be added once the release is published, hence this order (the workflow creates a
draft, attaches the archives to it, then publishes it). The image also receives a provenance
attestation (see [Security](securite.md#verifier-lorigine-de-limage)). A new release
(not a pre-release) is then announced on the **#changelog** channel of the
[Discord server](https://discord.gg/QsxYw4Rx9D): title, link, notes and `docker pull`
command, through the webhook of the secret `DISCORD_CHANGELOG_WEBHOOK` (without this
secret, the announcement is skipped).

**Actions → Release → Run workflow** (`tag` field) rebuilds the Docker image of an existing
tag; its release, being immutable, is not changed. The tag must be an existing semantic
version (`v1.2.0`, `1.2.0`, `v1.2.0-rc.1`). `latest` is only set on the highest
published version and `1.2` only on the highest `1.2.x`: rebuilding an old
version does not move them back.

Publishing to Docker Hub (`ltoinel/carbure`) uses the repository secrets
`DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` (read/write Docker Hub access token, in
**Settings → Secrets and variables → Actions**).

## Database migrations { #migrations-de-base }

The schema is versioned: the `schema_migrations` table lists the applied migrations, and the
schema version is the latest one. `tools/migrate.php` applies the missing ones, in
the order of their names; the Docker container runs it on every start, the setup
wizard migrates an existing database, and an administrator can apply them from the
portal banner (`POST /api/system/migrate`).

1. Create `sql/migrations/YYYY-MM-DD_description.sql`.
2. In it, you **must** declare a `-- applied-if: <query>` line that returns a non-zero
   number when the migration is already in place (for example a column present in
   `information_schema.COLUMNS`): a database created from `sql/carbure.sql`, or migrated by
   hand, is then recognized instead of failing. A test checks this.
3. Carry the change over into `sql/carbure.sql` (new installations and tests) and
   add the migration's version to the `schema_migrations` list at the end of the file.
4. As long as the migration may not be applied (an instance outside Docker not yet
   updated), the code that depends on it must remain tolerant (for example by
   catching the query error), or only be deployed after `php tools/migrate.php`; note this in
   `TODO.md`.

Once all known databases have gone through a set of migrations, those can be
removed: `sql/carbure.sql` already contains them (this is what was done for version 1.0,
see `sql/migrations/README.md`).

## Portal translations { #traductions-du-portail }

The portal texts are in one JSON file per language: `portal/i18n/fr.json`
(reference language, complete), `en.json`, `es.json`, `it.json`, `de.json` and `pt.json`
(European Portuguese: `Intl` uses `pt-PT` there for plurals, numbers, dates
and `<html lang>`). `portal/i18n.js` loads them before displaying the portal: the active
language (chosen in the profile, otherwise the browser's if the portal has it, French
otherwise) and French, which serves as the fallback for a missing key (then the key
itself). Files are requested as `i18n/<language>.json?v=<version>` (version
given by `/api/health`) so that those of a previous version are not kept in cache.
With `?debug=true`, a missing key is reported once in the console.

- A text is used by its key, `t('importTitle')`; nested keys with a dot
  (`months.1`); parameters in braces: `t('bankSetupTitle', { bank })`.
- **Plurals**: never "transaction(s)". Text that depends on a number has one
  form per plural category of the language (`Intl.PluralRules`, i18next convention),
  and the number is passed as the `count` parameter:

  ```json
  "rulesCount_one": "{count} règle",
  "rulesCount_other": "{count} règles"
  ```

  `t('rulesCount', { count: rules.length })` picks `rulesCount_one` or
  `rulesCount_other` (`_other` if the language's category is missing: `many`, `few`…).
  In French 0 and 1 are singular; in the other languages only 1 is.
- Every key exists in **all** languages, with the same `{parameters}`, with no
  empty text; every key used by the portal exists and no key is unused.
  `tests/portal/i18n.test.mjs` checks this (keys built by the code,
  `t('syncStep_' + étape)`, are recognized by their prefix).
- Files keep 4-space indentation and a final newline (checked
  by the same test).

### Adding a language { #ajouter-une-langue }

1. Copy `portal/i18n/en.json` to `portal/i18n/<code>.json` (BCP 47 code: `de`,
   `es`…) and translate each text; write the language's plural forms
   (`_one`, `_few`, `_many`, `_other`… according to `Intl.PluralRules`), `_other` being
   required.
2. Declare the language in `LOCALES` in `portal/i18n.js` (and in `INTL_LOCALES` if its
   `Intl` locale differs, like `pt` → `pt-PT`), add its `<option>` (name written in
   the language itself, with `lang`) to the language lists in `portal/index.html` (profile and
   setup wizard).
3. On the server side, add it to `Messages::LANGUAGES` (accepted by `PUT /user` and
   the installation) with its texts, its amount separators and its date format in
   `src/lib/Messages.php`, and its category and insight names in `sql/starter.json`.
4. Run the tests: `node --test 'tests/portal/*.test.mjs'` (the files in
   `portal/i18n/` are all checked) and `composer test` (`MessagesTest`, `StarterTest`).

## Documentation { #documentation }

```bash
pip install -r docs/requirements.txt
mkdocs serve      # http://127.0.0.1:8000
```

## Conventions { #conventions }

- PHP: PHPDoc on every method, prepared SQL statements only.
- Portal: texts through `t('clé')`, keys added in `portal/i18n/fr.json` **and**
  `portal/i18n/en.json`; plurals as `_one` / `_other` with `{ count }`.
- LF line endings (`.gitattributes`), commit messages in English.
