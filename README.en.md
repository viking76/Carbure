<p align="center">
  <img src="portal/icon.png" alt="Carbure" width="96">
</p>

<h1 align="center">Carbure</h1>

<p align="center">
  <a href="README.md">Français</a> · <strong>English</strong>
</p>

<p align="center">
  <strong>Take back control of your budget.</strong><br>
  Bank accounts synced automatically, expenses categorized, budgets tracked day by day —<br>
  on the web, on iPhone and on Android. Self-hosted, free and open source.
</p>

<p align="center">
  <strong>💸 Free, no subscription · 🏠 100% self-hosted · 🙅 No third-party cloud</strong>
</p>

<p align="center">
  <a href="https://demo.carbure.org/"><strong>▶ Try the online demo</strong></a>
</p>

<p align="center">
  <a href="https://apps.apple.com/fr/app/carbure/id6824057363"><img src="https://img.shields.io/badge/App_Store-iPhone-000000?style=for-the-badge&logo=apple&logoColor=white" alt="Download on the App Store (iPhone)"></a>
  <a href="https://play.google.com/store/apps/details?id=org.carbure.app"><img src="https://img.shields.io/badge/Google_Play-Android-01875F?style=for-the-badge&logo=googleplay&logoColor=white" alt="Get it on Google Play (Android)"></a><br>
  <sub>📱 Free mobile apps, no ads nor data collection, connected to <strong>your</strong> server</sub>
</p>

<p align="center">
  <a href="https://github.com/ltoinel/Carbure/actions/workflows/ci.yml"><img src="https://github.com/ltoinel/Carbure/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://github.com/ltoinel/Carbure/actions/workflows/security.yml"><img src="https://github.com/ltoinel/Carbure/actions/workflows/security.yml/badge.svg?branch=main" alt="Security"></a>
  <a href="https://github.com/ltoinel/Carbure/releases"><img src="https://img.shields.io/github/v/release/ltoinel/Carbure" alt="Release"></a>
  <a href="https://hub.docker.com/r/ltoinel/carbure"><img src="https://img.shields.io/docker/pulls/ltoinel/carbure?logo=docker&logoColor=white" alt="Docker pulls"></a>
  <a href="https://carbure.org/en/"><img src="https://img.shields.io/badge/docs-carbure.org-EF4860" alt="Docs"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue" alt="License: MIT"></a>
</p>

<p align="center">
  <a href="https://www.youtube.com/watch?v=PUCd27oJkig"><img src="docs/assets/teaser-youtube-play.jpg" alt="Watch the Carbure teaser (1 min, in French) on YouTube" width="100%"></a>
</p>

<p align="center">👉 <a href="https://carbure.org/en/fonctionnalites/"><b>All the features</b></a>, with every screen</p>

## Features

- 🔄 **No typing**: transactions arrive every day from your bank, directly through
  [woob](https://woob.tech/), with no aggregator; or import your statements (OFX, QIF, CSV,
  CAMT.053).
- 🏷️ **Automatic categorization** by simple rules ("CARREFOUR → Groceries"), suggested when
  you categorize a new transaction.
- 🎯 **Monthly budgets** per category, subcategories adding up in their parent, and the
  **monthly flow**: where the money comes from and where it goes.
- 📈 **Trends** over 3, 6 or 12 months, compared with the previous period or last year.
- ✅ **Check-off** of the transactions and 🔁 **recurring transactions** found on their own
  (salary, rent, subscriptions).
- 🔔 **Alerts** by push notification or email: large expense, chosen rule, budget nearly
  reached.
- 📊 **Insights**: your own monthly indicators (groceries, fuel, subscriptions…).
- 👨‍👩‍👧 **Built for the household**: several users on the same accounts, each with their
  language and devices; backup and restore in one ZIP.
- 🤖 **Your AI agent queries your accounts** ("how much on restaurants this year?") through a
  built-in, read-only MCP server: Claude, ChatGPT, Cursor, Copilot, Gemini…
- 🌗 **Light or dark, accessible** (WCAG 2.2 AA), in French, English, Spanish, Italian, German
  and Portuguese.

Carbure relies on the woob modules, which mainly cover **French banks**.

## Mobile apps

The [**iPhone**](https://apps.apple.com/fr/app/carbure/id6824057363) (iOS 26) and
[**Android**](https://play.google.com/store/apps/details?id=org.carbure.app) (Android 8.0) apps
connect to **your** server: transactions, check-off, budgets, trends, sync, Face ID or
fingerprint and push notifications. Free, no ads nor data collection, available in France for
now ([details](https://carbure.org/en/applications-mobiles/)).

<p align="center">
  <a href="https://www.youtube.com/watch?v=YqSUd7L9fPg"><img src="docs/assets/mobile-apps-youtube-play.jpg" alt="Watch the video “Carbure on iPhone and Android: your budget in your pocket” on YouTube" width="100%"></a>
</p>

## Your data stays with you

Carbure runs on your own server (a NAS is enough): no account to create, no aggregator, no
cloud. Your credentials and banking data stay in your database. Carbure only contacts your
bank (woob), the public GitHub API once a day to report a new version (can be turned off),
and, if you turn them on, the relay of the mobile notifications and your email server.

```mermaid
flowchart LR
    bank[(Banks)] -->|woob| sync[Sync]
    subgraph Carbure
        sync --> rules[Categorization<br/>rules] --> db[(MariaDB)]
        api[PHP REST API] <--> db
    end
    portal[Web portal] <--> api
    mobile[iPhone and<br/>Android apps] <--> api
    ai[AI agent] <-->|MCP| api
    sync -->|Carbure Push relay| push[Mobile<br/>notifications]
```

## Comparison

| | **Carbure** | **Firefly III** | **Actual Budget** | **Kresus** | **Sure** | **ezBookkeeping** |
|---|---|---|---|---|---|---|
| Bank connection | Direct, via woob | Third-party aggregators | Third-party aggregators | Direct, via woob | Third-party aggregators | File import |
| Sub-categories adding up to their parent | ✅ | ➖ | ⚠️ | ➖ | ✅ | ⚠️ |
| Several users sharing the same data | ✅ | ⚠️ | ✅ | ➖ | ✅ | ⚠️ |
| Native iPhone and Android apps, push notifications | ✅ | ⚠️ | ➖ | ➖ | ⚠️ | ➖ |
| Built-in MCP server for AI agents | ✅ | ➖ | ➖ | ➖ | ✅ | ✅ |
| File import (OFX, QIF, CSV, CAMT.053) | ✅ | ⚠️ | ✅ | ⚠️ | ⚠️ | ✅ |
| Several currencies | ➖ | ✅ | ➖ | ⚠️ | ✅ | ✅ |

✅ yes · ⚠️ partly · ➖ no — 👉 **[Full comparison](https://carbure.org/en/comparatif/)**:
24 criteria (hosting, bank data, budget, household and devices, API), with the strengths of
each tool.

## Installation

| | For | How |
|---|---|---|
| **Online demo** | see Carbure without installing anything | [demo.carbure.org](https://demo.carbure.org/) |
| **Demo at home** | try it with a sample household | `docker run --rm -p 8080:80 ltoinel/carbure:demo` |
| **Docker** (recommended) | manage the real household budget | see below |
| **Synology NAS** | a NAS at home | [step-by-step tutorial](https://carbure.org/en/synology/) |
| **YunoHost** | a YunoHost server | [`carbure_ynh`](https://github.com/ltoinel/carbure_ynh) package ([tutorial](https://carbure.org/en/yunohost/)) |
| **Web server** | PHP 8.2 and MariaDB/MySQL already in place | [installation without Docker](https://carbure.org/en/installation/#sur-un-serveur-web-existant) |

With Docker, the [`ltoinel/carbure`](https://hub.docker.com/r/ltoinel/carbure) image holds
nginx, PHP and woob; `docker/docker-compose.yml` adds MariaDB:

```bash
git clone https://github.com/ltoinel/Carbure.git && cd Carbure/docker
docker compose pull && docker compose up -d
```

Open `http://localhost:8080/`: the **setup wizard** creates the database and the
administrator account in three clicks. For the mobile apps, put Carbure behind an HTTPS
reverse proxy.

👉 **Detailed installation, upgrades, configuration, API and security:
[the documentation](https://carbure.org/en/installation/).**

## Development

```bash
git clone https://github.com/ltoinel/Carbure.git && cd Carbure
./start.sh
```

`./start.sh` runs Carbure with the code of the repository mounted live and a sample household
on `http://localhost:8000/portal/` (`admin` / `admin-password`); `./start.sh --help` lists its
options. Tests and architecture: [developer documentation](https://carbure.org/en/developpement/).

## Support

<p>
  <a href="https://discord.gg/QsxYw4Rx9D"><img src="https://img.shields.io/badge/Discord-Join%20the%20server-5865F2?style=for-the-badge&logo=discord&logoColor=white" alt="Join the Carbure Discord server"></a>
  <a href="https://www.youtube.com/@carbure-org"><img src="https://img.shields.io/badge/YouTube-Watch%20the%20videos-FF0000?style=for-the-badge&logo=youtube&logoColor=white" alt="Watch the Carbure videos on YouTube"></a>
</p>


Questions and help on [Discord](https://discord.gg/QsxYw4Rx9D), FAQ in
[support](https://carbure.org/en/assistance/). A bug?
[Open an issue](https://github.com/ltoinel/Carbure/issues); report a security flaw privately
([SECURITY.md](SECURITY.md)).

## Contributing

Contributions are welcome: read [CONTRIBUTING.md](CONTRIBUTING.md) (workflow, conventions,
tests) and [AGENTS.md](AGENTS.md) (code rules), and pick a task in [TODO.md](TODO.md). If
Carbure is useful to you, a ⭐ helps other people find it.

Thanks to everyone who contributed to Carbure, with their code, ideas or feedback:

<p>
  <a href="https://github.com/ltoinel" title="Ludovic Toinel"><img src="https://github.com/ltoinel.png?size=96" width="48" height="48" alt="Ludovic Toinel"></a>
  <a href="https://github.com/viking76" title="Gaël"><img src="https://github.com/viking76.png?size=96" width="48" height="48" alt="Gaël"></a>
  <a href="https://github.com/matlink" title="Matlink"><img src="https://github.com/matlink.png?size=96" width="48" height="48" alt="Matlink"></a>
  <a href="https://github.com/GILMorgan" title="GIL Morgan"><img src="https://github.com/GILMorgan.png?size=96" width="48" height="48" alt="GIL Morgan"></a>
  <a href="https://github.com/claneys" title="Romain Forlot"><img src="https://github.com/claneys.png?size=96" width="48" height="48" alt="Romain Forlot"></a>
  <a href="https://github.com/yanvigdev" title="yann vignolet"><img src="https://github.com/yanvigdev.png?size=96" width="48" height="48" alt="yann vignolet"></a>
  <a href="https://github.com/vgrillot" title="vgrillot"><img src="https://github.com/vgrillot.png?size=96" width="48" height="48" alt="vgrillot"></a>
  <a href="https://github.com/algol-sec" title="algol-sec"><img src="https://github.com/algol-sec.png?size=96" width="48" height="48" alt="algol-sec"></a>
</p>

And all the others, in the [list of contributors](https://github.com/ltoinel/Carbure/graphs/contributors)
and in the [issues](https://github.com/ltoinel/Carbure/issues).

## License

[MIT](LICENSE) © Ludovic Toinel. Bundled third-party software and their licenses:
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).
