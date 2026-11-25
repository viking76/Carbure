---
title: "Frequently asked questions"
description: "Carbure FAQ: price, supported banks, synchronization, import, budgets, mobile apps, installation, security and backup."
icon: material/frequently-asked-questions
---

# Frequently asked questions

A question that isn't here? Ask it on the
[Discord server](https://discord.gg/QsxYw4Rx9D) (see [Support](assistance.md)).

## Discover Carbure { #decouvrir-carbure }

??? question "Is Carbure free?"
    Yes. Carbure is **free and open source** (MIT license): the code is on
    [GitHub](https://github.com/ltoinel/Carbure). There is no subscription, no ads and no
    paid version, and the mobile apps are free too. You host it yourself: the only cost is
    the machine that runs it (a NAS, a mini-PC, a server).

??? question "Can I try it without installing anything?"
    Yes, on the **online demo** [demo.carbure.org](https://demo.carbure.org/) with the
    `demo` / `carbure` account and fictitious data, or at home in one command:
    `docker run --rm -p 8080:80 ltoinel/carbure:demo` (see
    [Installation](installation.md#essayer-carbure-demo)). The demo image wipes its data at
    each start: never use it for real data.

??? question "How is it different from other budgeting apps?"
    Your data stays **at home**: Carbure goes through no commercial bank aggregator, and
    the publisher has access neither to your accounts nor to your transactions. The
    [Comparison](comparatif.md) places it against banking apps, aggregators and other
    self-hosted tools (Firefly III, Actual Budget, Kresus).

??? question "Who is Carbure for?"
    For **a household**: one installation, shared bank accounts and the people who follow
    them together. Accounts, transactions, categories, rules and budgets are shared; each
    person keeps their own login, language, theme, alerts and devices. For two separate
    households, make two installations. See [The household](fonctionnalites.md#le-foyer).

??? question "In which languages?"
    The portal and the notifications exist in **French, English, Spanish, Italian, German
    and Portuguese**, chosen by each user. The mobile apps are in French and English (the
    language of the phone).

??? question "Does Carbure handle a currency other than the euro?"
    One currency per installation: the `currency` setting (**Administration → Settings**,
    ISO 4217 code: `EUR`, `CHF`, `USD`…) changes how amounts are shown in the portal, the
    notifications and the emails. No amount is converted, and the mobile apps always show
    euros. Several currencies at once are not supported.

## Banks and synchronization { #banques-et-synchronisation }

??? question "Which banks are supported?"
    The banks supported by [woob](https://woob.tech/), the open source project Carbure
    uses to fetch the transactions: mostly **French banks**. Carbure connects directly to
    the website of your bank, with no third-party aggregator. Your bank is missing? Import
    its statements (next question).

??? question "My bank is not supported, what can I do?"
    Download the statement from the website of the bank (**OFX/QFX**, **QIF**, **CSV** or
    **CAMT.053**) and import it in the **Transactions** tab. The preview flags the
    transactions already known and the probable duplicates before saving. The CSV exports
    of about ten banks (BoursoBank, Crédit Agricole, La Banque Postale, Société Générale,
    BNP Paribas, N26, Revolut, Qonto…) are recognized; for another bank, prefer OFX. See
    [Statement import](fonctionnalites.md#import-de-releves-bancaires). The import also
    brings back the history from before Carbure.

??? question "Where are my bank credentials kept?"
    By **woob**, on your server, in its own file that only it can read: Carbure does not
    save them in its database and never writes them to the logs. Deleting a bank connection
    makes woob forget them.

??? question "Can I follow several accounts or a joint account?"
    Yes: a bank can have **several connections** (personal, joint, business account…), each
    with its own credentials and a readable name, and each connection as many followed
    accounts as woob finds. Unfollowing an account keeps its transactions already
    imported.

??? question "My bank asks for an approval on my phone, what do I do?"
    Start the synchronization from the portal: the approval request (strong
    authentication) is shown there, and the synchronization waits for you to approve in
    your banking app or to type the code you received. The scheduled synchronization never
    triggers it: it tries again later (settings `woob_2fa_wait` and `woob_2fa_retries`).
    When a bank is connected, the list of its accounts waits for the approval.

??? question "I connected my bank with woob in the container's terminal, the portal does not see it"
    In the Docker image, Carbure runs woob as `www-data` with its configuration in
    `/data/woob`. A plain `docker exec` runs as root with another configuration, which
    Carbure does not see. Run woob as Carbure does:
    `docker compose exec -u www-data carbure env HOME=/data/woob woob bank list`. The
    simplest is still to connect the bank from the portal, which handles the strong
    authentication.

??? question "Are the accounts synchronized automatically?"
    Yes, if you schedule the synchronization: with Docker, the `SYNC_INTERVAL` variable
    (`86400` = once a day); otherwise, the `curl` command or the crontab line shown in
    **Administration → Sync**. An administrator can also synchronize from the **Accounts**
    tab at any time. See
    [Schedule the synchronization](installation.md#5-planifier-la-synchronisation).

??? question "My bank shows no field when connecting it"
    The portal then shows "woob cannot load the bank module …": a Python package the
    module needs is missing, and woob never installs it itself. The Docker image ships the
    ones of the bank modules since 1.10.1: update it. On a manual installation, install
    the package named in the environment of woob (`pip install <package>`). The modules
    driving a real browser (American Express, Sogecartenet) are not supported: import your
    statements.

??? question "The synchronization fails, what should I do?"
    The **Accounts** tab shows the cause returned by woob. Bank websites change often and
    break their woob module: check the woob tickets (the portal gives the link) to see
    whether the issue is known, then update the bank modules from the **Accounts** tab once
    the fix is published. See
    [If the synchronization fails](installation.md#en-cas-dechec-de-la-synchronisation).

??? question "My synchronized transactions have no label"
    Some woob modules (Banque Populaire with the new API of the bank) only fill the
    simplified label of the transaction: before 1.10.1, Carbure only read the raw label and
    saved these transactions without a label. Since 1.10.1, the synchronization reads either
    one and completes the transactions already saved without a label (same account, same
    date and same amount), without duplicates: they keep their category and their pointing.
    Those older than the transactions returned by the bank stay without a label: recategorize
    or delete them from the **Transactions** tab.

## Transactions, categories and budgets { #transactions-categories-et-budgets }

??? question "How are the transactions categorized?"
    By **rules**: a keyword linked to a category ("CARREFOUR" → Supermarket). At each
    synchronization, the rules classify the new transactions; creating or changing a rule
    also applies it to the whole history. A transaction can also be recategorized by hand.
    The installation offers a starter pack of about thirty categories and a hundred rules.
    See [Categorization rules](fonctionnalites.md#regles-de-categorisation).

??? question "What does checking off a transaction mean?"
    Marking it as **verified**, with a click on the round button on the left of the
    transaction. Unverified transactions are in bold in the portal, which can show only
    them, and their number is shown on the badge of the iPhone app: you spot at a glance
    what you have not looked at yet.

??? question "Do transfers between my own accounts distort my expenses?"
    No, if they are classified in an **Off-budget** category (internal transfers,
    savings): these movements count neither as expenses nor as income in the budgets, the
    monthly flow and the trends. Savings remain visible on their own.

??? question "Can a transaction be imported twice?"
    No. Each transaction gets a fingerprint (actual date, cleaned label, amount): a known
    transaction is never saved twice, even when synchronized several times, by two users or
    imported from a statement. Two truly identical transactions on the same day (two
    coffees at the same price) are both kept.

??? question "My salary arrives at the end of the month: which month does it count for?"
    For the **next month**, the one it pays for: a received transfer dated on the **25th or
    later** is attached to the 1st of the next month (its actual date remains visible in
    the details). The day is set in **Administration → Settings** (`next_month_day`, 1 to
    31, `0` to never move a transfer); it applies to the transactions imported or
    synchronized afterwards. See [Configuration](configuration.md#global).

??? question "Does Carbure spot subscriptions and direct debits?"
    Yes: the transactions that come back every month (salary, rent, subscriptions, bills)
    get the **Monthly** badge, and a switch lists those expected this month that have not
    arrived yet. They are detected, not scheduled: no bill calendar and no balance
    forecast. See [Recurring transactions](fonctionnalites.md#transactions-recurrentes).

??? question "How do budgets work?"
    A budget is an **amount per category and per month**; a parent category is the sum of
    its subcategories, or its own amount. Each card shows what is left or the overrun, and
    a button copies the budgets of the previous month when the month has none yet. Every user can set the amounts. See
    [Budgets](fonctionnalites.md#budgets).

??? question "Can I create my own indicators?"
    Yes, with **insights**: an indicator of the month (groceries, fuel, savings rate,
    emergency fund…) defined by a read-only SQL query, created by an administrator. The
    [Insight recipes](insights.md) offer nearly thirty of them, ready to copy.

??? question "Does Carbure handle savings goals?"
    Not yet. The **Trends** tab follows the savings paid in and the savings rate over 3, 6
    or 12 months ("Épargne" category, or the one of the `savings_category` setting), and the
    **Emergency fund** insight gives the expenses of 6 months. See also the
    [Known limitations](fonctionnalites.md#limites-connues).

## Notifications and alerts { #notifications-et-alertes }

??? question "Which notifications can I receive?"
    The result of each synchronization, a **large expense** above your threshold, a
    transaction matching a "Send a notification" rule (rent, refund…) and a **budget
    reached**. In **My profile → Notifications**, each person chooses the channel of each
    kind: push on the mobile app, email, both or none. See
    [Notifications](fonctionnalites.md#notifications).

??? question "How do I get warned about a large expense or a budget overrun?"
    In **My profile → Notifications**: the **Large expense** threshold (an amount; empty =
    no alert), and for budgets a threshold from 50 to 200% (100% by default, 80% to be
    warned before) on all the budgets, which can be turned off, plus **thresholds per
    category** that replace it (for instance 80% on groceries). A budget alert is sent only
    once per category and per month. See [Budget alerts](fonctionnalites.md#alertes-de-budget).

??? question "Can I receive the notifications by email?"
    Yes, if an administrator configures an SMTP server (**Administration → Settings**,
    `[smtp]` section, with a test email) and your profile has an email address. The emails
    leave from your server. See [Configuration](configuration.md#smtp).

??? question "Why do push notifications go through Carbure Push?"
    Apple and Google only accept the notifications of an app from the credentials of its
    publisher: the **Carbure Push** relay (`api.carbure.org`) holds them, so you have no
    Apple key nor Firebase account to create. It forwards the title and text of the
    notifications without storing them, and knows neither your name nor your email
    address. An administrator enables it in **Administration → Mobile push**, after
    accepting its consent. See [Privacy](confidentialite.md).

## Mobile apps { #applications-mobiles }

??? question "Is there a mobile app?"
    Yes, native **iPhone and Android** apps, free, with no ads nor data collection, on the
    [App Store](https://apps.apple.com/fr/app/carbure/id6824057363) and on
    [Google Play](https://play.google.com/store/apps/details?id=org.carbure.app):
    transactions, check-off, budgets, overviews, trends, synchronization, Face ID or
    fingerprint login and push notifications. They need iOS 26 or Android 8.0 at least.
    See [Mobile apps](applications-mobiles.md).

??? question "Are the apps available outside France?"
    Not for now: they are published in France on the App Store and Google Play. The web
    portal works everywhere, and installs as an app (see below).

??? question "My phone does not receive the notifications"
    Open **My profile → My devices**: if the phone is marked "Notifications unavailable",
    the app logged in without a notification token. Allow the notifications of Carbure in
    the settings of the phone, then log out and in again in the app. If it is not listed at
    all, log in again in the app with your account. An administrator also checks that the
    instance is registered and sends a test notification in **Administration → Mobile
    push**.

??? question "How do I connect the app to my server?"
    In the portal, **My profile → My devices** shows a QR code: scan it with the phone's
    camera, and the app opens with the address of your server already filled in. You can
    also type the address of the API on the login screen. Your server must be reachable
    from the phone, preferably over HTTPS; set its public address (`public_url`) if it is
    behind a proxy.

??? question "What can I do in the app, and what stays in the portal?"
    The app is made for **day-to-day tracking**: viewing, checking off, categorizing,
    adjusting a budget, starting a synchronization. Administration (bank accounts, rules,
    categories, insights, users, settings) and statement import are done in the portal.
    Neither the app nor the portal works offline. See the
    [limitations of the app](applications-mobiles.md#limites).

??? question "Does two-factor authentication work in the app?"
    Yes: the app asks for the code of your authenticator app (or a recovery code), and can
    even set up two-factor authentication at the first login. The code is not asked again
    from that phone afterwards. See
    [Two-factor authentication](applications-mobiles.md#double-authentification).

??? question "Can I install the portal as an app?"
    Yes, from Chrome or Edge (**Install** button), Safari on Mac (**File → Add to Dock**) or
    on iPhone and Android (**Add to Home Screen**): it opens in its own window, with the
    Carbure icon. The portal must be served over HTTPS. See
    [Install the portal as an app](portail.md#installer-le-portail-comme-une-application).

## Installation and updates { #installation-et-mises-a-jour }

??? question "What do I need to install it?"
    The simplest: a machine with **Docker** (server, mini-PC, NAS). The
    `docker/docker-compose.yml` file starts the production image `ltoinel/carbure` and
    MariaDB; a wizard in the browser creates the database and the administrator account in
    three clicks. Without Docker: PHP 8.2 or later, MariaDB ≥ 10.3 or MySQL 8, a web server
    and woob. See [Installation](installation.md).

??? question "Does it run on a Synology NAS?"
    Yes, with Container Manager and MariaDB 10, on Intel and ARM NAS: the
    [Synology NAS tutorial](synology.md) details each step, up to HTTPS access.

??? question "Can it be installed on YunoHost?"
    Yes: the [`carbure_ynh`](https://github.com/ltoinel/carbure_ynh) package installs Carbure
    in a few clicks on a [YunoHost](https://yunohost.org) server, on a domain of its own, with
    woob and a daily synchronization of the accounts. It is not in the YunoHost catalog yet: it is installed from
    its repository, see [the YunoHost tutorial](yunohost.md). A problem with the
    package? Open an issue in [its repository](https://github.com/ltoinel/carbure_ynh/issues).

??? question "How do I reach it from outside or from my phone?"
    Put Carbure behind an **HTTPS reverse proxy** (the one of the NAS, Caddy, Traefik,
    NGINX…) with a domain name, then set its public address (`public_url`) in
    **Administration → Settings**. Install Carbure **before** exposing it on the Internet:
    from a public address, the wizard asks for an installation code. See
    [Installation](installation.md).

??? question "How do I update Carbure?"
    With Docker: `docker compose pull && docker compose up -d`. The database updates are
    applied when the container starts, or in one click from the banner shown to
    administrators. Back up the database and read the release notes before a major update
    (see [Update](installation.md#mettre-a-jour)).

??? question "Which version of the Docker image should I choose?"
    `ltoinel/carbure:latest` follows the latest release; a precise version
    (`ltoinel/carbure:1.9.1`) is recommended in production, to choose when to update.
    `ltoinel/carbure:demo` is the demo, with its sample data: never for your real data.
    See [Getting the image](installation.md#recuperer-limage).

## Data, security and privacy { #donnees-securite-et-confidentialite }

??? question "Where is my data stored?"
    In **your** database, on your machine. The bank credentials are kept by woob on your
    server. Nothing is sent to the publisher: only the push notifications, if an
    administrator enables them, go through Carbure Push, and the questions asked to an AI
    agent through its provider (see below).

??? question "How do I protect my installation?"
    Serve Carbure over **HTTPS**, enable **two-factor authentication** (authenticator app,
    recovery codes; an administrator can enforce it for everyone), and follow the
    [deployment recommendations](securite.md#recommandations-de-deploiement). After 5 failed
    logins in a row, an account is locked for 24 hours (an administrator can unlock it).

??? question "I lost my phone with two-factor authentication, what do I do?"
    Log in with one of your **10 recovery codes**. Without a code, an administrator resets
    your two-factor authentication (**Users** tab); an administrator alone in this case sets
    `mfa_required=false` back in `data/conf/prod.ini` on the server. Then remove the lost
    phone from **My profile → My devices**. See
    [Two-factor authentication](securite.md#double-authentification-totp).

??? question "How do I back up my data?"
    Back up the database and the `data/` folder (configuration and woob with your banks)
    regularly. The **Administration → Backup** menu also exports the chosen data
    (categories, rules, budgets, insights, transactions) to a file you can import again; it
    holds no users, passwords nor bank connections. See
    [Web portal](portail.md#sauvegarde-menu-administration).

??? question "Can several people in the household use it?"
    Yes: each member has their own account (login, password, language, theme,
    notifications, devices), with a **user** or **administrator** profile. The followed bank
    accounts are shared by the household; only administrators manage banks, rules,
    categories, insights and settings.

??? question "What does the mobile app do with my data?"
    It only connects to the server you give it, with no ads nor audience measurement. The
    banking data stays in memory and disappears when the app is closed; only the server
    address, the last login, the theme and, if you enable it, the password protected by
    biometrics are kept on the phone. See the [Privacy policy](confidentialite.md).

## AI agents { #agents-ia }

??? question "Can I query my budget with Claude or ChatGPT?"
    Yes, thanks to the built-in **MCP server**, read-only, that an administrator enables.
    Claude connects through OAuth, with no token to copy; the other agents (Claude Code,
    ChatGPT, Cursor, Copilot, Gemini…) with an access token, revocable in one click. See
    [AI agents (MCP)](mcp.md).

??? question "Can an AI agent change my data?"
    No: it can list the categories and transactions, search, total the expenses, read the
    budgets, trends and rules, but change nothing. The data it reads (labels, amounts,
    categories) is however sent to its provider to be analysed.

## Help { #aide }

??? question "Where can I get help or report a bug?"
    On the [Discord server](https://discord.gg/QsxYw4Rx9D); a bug is reported with an
    [issue](https://github.com/ltoinel/Carbure/issues/new/choose) and a vulnerability
    privately. Videos are also on the [YouTube channel](https://www.youtube.com/@carbure-org).
    See [Support](assistance.md).
