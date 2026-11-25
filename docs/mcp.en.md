---
title: "AI agents (MCP): your budget in Claude or ChatGPT"
description: "Connect an AI agent (Claude, ChatGPT, Cursor, Copilot, Gemini) to the household budget through Carbure's MCP server, read-only, with OAuth or a token."
icon: material/robot-outline
---

# AI agents (MCP)

Carbure ships an **MCP server** ([Model Context Protocol](https://modelcontextprotocol.io/))
that lets an AI agent (Claude, ChatGPT, Cursor, Copilot, Gemini…) look up the
household data and answer questions such as “How much did we spend on
restaurants this year?”, “Which categories are over budget this month?” or
“How have our savings evolved over the last 6 months?”.

- **Read-only**: no tool modifies the data.
- **Nothing to install**: the server is an API route (`/api/mcp`), served by the same
  web server as the portal.
- **Under control**: disabled by default, an administrator enables it; each user
  creates their access tokens and revokes them whenever they want.

!!! warning "Privacy"
    Carbure stays hosted at home, but the data an AI agent looks up (labels,
    amounts, categories) is sent to its provider to be analyzed. Only enable this
    access if you are fine with that.

## 1. Enable the MCP server { #1-activer-le-serveur-mcp }

**AI agent** tab of the portal (**Administration** menu): an administrator turns on the **MCP server
enabled** switch (the `mcp_enabled` setting is stored in the database). As long as it is disabled,
`/api/mcp` responds `403`, even with a valid token, and users cannot create
tokens.

## 2. Create an access token { #2-creer-un-jeton-dacces }

In the **AI agent** tab (**Administration** menu for an administrator, user menu otherwise), **+** button: give the token a name (for example
“Claude Code on my laptop”) and choose its **validity period** (30 days, 90 days,
1 year or no expiration; an expired token is rejected and flagged “Expired” in the
list). The token (`cbt_…`) is shown **only once**, with the configuration ready
to copy for each agent. The **My access tokens** list shows their creation date,
last use and expiration; the trash can revokes a token immediately.

Each user can have at most 20 tokens. Only the SHA-256 hash of the token is
kept in the database.

## 3. Connect your AI agent { #3-connecter-son-agent-ia }

### Claude (web, Desktop, mobile): no token, through OAuth { #claude-web-desktop-mobile-sans-jeton-par-oauth }

In Claude: **Settings → Connectors → Add custom connector**, paste
the MCP server URL (`https://<your-server>/api/mcp`, shown in the **AI agent** tab)
then click **Connect**. Claude opens the Carbure portal: sign in if
needed, then **Allow**. The access appears in **My access tokens**, under the agent's name
(“Claude”), and is revoked like the other tokens. Carbure must be reachable over HTTPS
from the Internet (see [Synology](synology.md), step 8).

### Other agents: with a token { #les-autres-agents-avec-un-jeton }

| Agent | Configuration (suggested by the portal) |
|---|---|
| Claude Code | `claude mcp add --transport http carbure <url> --header "Authorization: Bearer <token>"` |
| ChatGPT | connector in developer mode, URL `<url>?token=<token>` (public HTTPS required) |
| Cursor | `mcpServers` block of `~/.cursor/mcp.json` with the `Authorization` header |
| VS Code (Copilot) | `servers` block of `mcp.json` (type `http`) with the `Authorization` header |
| Gemini CLI | `gemini mcp add --transport http --header "Authorization: Bearer <token>" carbure <url>` |

`<url>` is `https://<your-server>/api/mcp`. Any MCP client that supports the
**Streamable HTTP** transport works the same way.

!!! note "Token in the URL"
    For agents that cannot send a header (ChatGPT), the token can be passed
    as a `?token=` parameter. It is not written to Carbure's logs nor to the nginx access
    log of the Docker image (masked as `token=***`), but it may be by a proxy
    placed in front: prefer the header when the agent allows it.

## Available tools { #outils-disponibles }

| Tool | Role |
|---|---|
| `list_categories` | Categories: name, parent, type (expense, income, off-budget) |
| `list_transactions` | Transactions of a month, optionally of a category |
| `search_transactions` | Transactions of every month: by text of the label, amount, expenses or incomes, categories, period |
| `spending_by_category` | Total and number of transactions per category over a period (`YYYY-MM`) |
| `get_budget` | Spent and planned budget per category for a month |
| `get_trends` | Debit, credit, off-budget, planned budget and savings month by month |
| `spending_by_month` | Expenses, income and balance of each month of an explicit period (`from`, `to`: `YYYY-MM`), with the total, the monthly average and the month that spent the most; categories kept (`category_ids`) or left out (`exclude_category_ids`), sub-categories included. An unknown id is refused with the list of the valid ids. Off-budget left out, as in `get_trends` |
| `recurring_transactions` | Recurring series of the detection of the portal still running at the end of the period: usual day, average and last amount, amount of each month and its changes (a price increase and its month) |
| `duplicate_transactions` | Possible duplicates: transactions of the same account, day, label and amount (sometimes two real identical payments) |
| `one_off_transactions` | Transactions whose label (without its numbers) appears only once in the period, largest first: one-off purchases, yearly payments (`kind`, `limit`) |
| `list_rules` | Automatic categorization rules |
| `get_insight_guide` | Everything needed to write an insight: rules of the query, readable tables and columns, common expressions, colors, examples and the insights already defined (their query for an administrator) |
| `check_insight_query` | Checks an insight query with the rules of the portal and runs it read-only on a month and up to 5 months before it (administrators): its amount per month, or the error to fix. Nothing is saved |

## Proposing an insight in natural language { #proposer-un-insight }

The agent can turn a need expressed in natural language ("how much do I spend at
restaurants at the weekend?") into an [insight](insights.md) ready to add: name, color,
icon and checked SQL query. For this the server offers the **prompt** `propose_insight`
(argument `need`, the need), which agents show as a command (in Claude Code:
`/mcp__carbure__propose_insight`). It asks the agent to read `get_insight_guide` and the
categories, write the query, check it with `check_insight_query` on the last three months
and make sure the amounts are plausible. Without the prompt, just ask the agent to propose
an insight: its instructions point it to these tools.

The server stays **read-only**: the agent proposes, an **administrator** adds the insight
in the Insights tab (**+** button, then **Create an insight**), where the query is checked
again. `check_insight_query` is for administrators only, like the check of the portal: with
the token of another user, the agent proposes a query without being able to run it.

## Technical details { #details-techniques }

- Sessionless **Streamable HTTP** transport: `POST /api/mcp` receives a JSON-RPC 2.0 message
  and responds in JSON; a notification gets `202` with no body; `GET /api/mcp` responds `405`
  (no SSE stream).
- Protocol versions: `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`.
- Authentication: access token (`cbt_…`, `Authorization` header or `?token=`) or the portal
  JWT; otherwise `401` with `WWW-Authenticate: Bearer resource_metadata="…"`, which tells
  the agent where to find how to obtain a token. A request carrying an `Origin` header from
  a site other than Carbure, `claude.ai` or `claude.com` is rejected (`403`), like any
  request when the server is disabled.
- **OAuth 2.1** (agents that only take a URL), following the MCP specification:
  metadata `/.well-known/oauth-protected-resource` (RFC 9728) and
  `/.well-known/oauth-authorization-server` (RFC 8414); dynamic client registration
  `POST /api/oauth/register` (RFC 7591, redirect URIs over HTTPS or on `localhost`);
  authorization `GET /api/oauth/authorize` (PKCE `S256` mandatory), which redirects to the portal's
  consent page; `POST /api/oauth/token` (single-use authorization code,
  valid 5 minutes, or refresh token, with rotation). The access token is a
  `cbt_` token valid for 30 days; a new authorization replaces the previous tokens of the
  same agent. The public URL is derived from the request (`X-Forwarded-Proto` from the proxy) or from the
  `public_url` setting.
- Server state: `GET /api/mcp/settings`, `PUT /api/mcp/settings` (`enabled`,
  administrators), `settings` table.
- Tools run with the rights of the token's user.
- Code: `src/resources/Mcp.php` (protocol and tools), `src/resources/ApiToken.php` (tokens),
  `src/resources/OAuth.php` (OAuth 2.1), `portal/modules/oauthModule.js` (consent).
