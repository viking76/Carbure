---
title: "Features: expense tracking, budgets and trends"
description: "Everything Carbure does: bank synchronization, rule-based categorization, budgets per category, money flow of the month, trends, notifications and AI agent."
icon: material/star-shooting-outline
---

# Features

This page describes **what Carbure does** and **by which rules**: where transactions come
from, how they are classified, how budgets, the money flow of the month and trends are
computed, who receives which notification and what each person can do. For screen-by-screen
usage, see [Web portal](portail.md) and
[Mobile apps](applications-mobiles.md).

![List of the transactions of the month in the Carbure portal, with their category](assets/en/screenshot-transactions.webp)

## The household { #le-foyer }

Carbure manages **one household**: one installation, shared bank accounts and people
who view the same data.

- **Shared data**: bank accounts, transactions, categories, rules, budgets and
  insights are shared by all users. A transaction technically belongs to the
  first user who follows the account, but everyone sees it and checks it off.
- **Personal data**: each user has their own login, password,
  language (French, English, Spanish, Italian, German or Portuguese), display theme, alert thresholds, the channels of their
  notifications (push, email), their mobile devices (iOS, Android), their web
  browsers, their trusted browsers and their access tokens for AI agents.
- **Two profiles**:

| | User | Administrator |
|---|---|---|
| View transactions, budgets, flow, insights, trends | ✅ | ✅ |
| Show or hide the insights | ✅ | ✅ |
| Set one's alerts on the insights | ✅ | ✅ |
| Check off and categorize transactions | ✅ | ✅ |
| Change budget amounts | ✅ | ✅ |
| Manage their profile, devices, access tokens | ✅ | ✅ |
| Create and edit categorization rules | ➖ | ✅ |
| Create and edit categories | ➖ | ✅ |
| Follow bank accounts, configure banks, synchronize from the portal, update woob modules | ➖ | ✅ |
| Create and edit insights (SQL queries) | ➖ | ✅ |
| Enable the MCP server for AI agents | ➖ | ✅ |
| Manage users and server settings (including emails), view logs, update the database | ➖ | ✅ |

The last administrator cannot lose their role.

## Bank accounts and synchronization { #comptes-bancaires-et-synchronisation }

### Connecting to banks { #connexion-aux-banques }

Carbure connects **directly** to each bank with [woob](https://woob.tech/), a piece of
free software that queries the bank's website just as you would: no third-party banking
aggregator, no cloud. woob modules mostly cover **French banks**.

- **Configure a bank**: an administrator picks the bank among those woob
  supports; the portal shows the fields its module asks for (customer number, secret
  code, account type…). These credentials are handed to woob, which keeps them in its
  own file, readable only by itself; **Carbure does not store them in the database and does not
  write them to the logs**.
- **Follow accounts**: first **connect the bank**, then, for this connection,
  **add accounts** among those woob lists (`woob bank list -b <bank>`, with their
  label and balance): tick the ones to follow and they are all added at once. Only an
  account found by woob can be followed.
- **Several connections** to the same bank (joint account, business account…), each with
  its own credentials; a connection and a followed account can be given a **readable name**
  ("Joint account"). **Deleting a connection** makes woob forget its credentials and stops
  following its accounts; their transactions are kept.
- **Bank modules**: when a bank's website changes, woob publishes a fix for its
  module; an administrator updates the modules from the Accounts tab (also offered
  under the log of a failed synchronization), after approving the key of woob's
  repository the first time (see [Security](securite.md)).
- **Followed accounts** are shared by the household: each one shows who added it, and the date and
  result of its last synchronization (Synchronized, Failed with the message, or Never
  synchronized). Unfollowing an account keeps its already imported transactions.
- **Manual accounts**: for cash, or a bank woob does not support, an administrator adds a
  manual account (a name, and an optional number). It is never synchronized: its
  transactions are entered by hand in the Transactions tab.

### How a synchronization runs { #deroulement-dune-synchronisation }

For each followed account:

1. **Upcoming transactions**, then **history**: woob returns the latest transactions
   (100 by default, setting `woob_transactions`). A failure on upcoming transactions
   does not prevent the history: the account stays synchronized, with the failure noted in its
   result and in the log.
2. **Label cleaning**: converted to uppercase, removal of the bank's technical prefixes and
   suffixes ("FACTURE CARTE DU 040126" becomes "CB", card number removed,
   mandate references of direct debits…), according to the `regex_label` expressions of the
   configuration.
3. **Deduplication**: each transaction gets a fingerprint (actual date, cleaned label,
   amount); a transaction already known is never imported twice, even when synchronized
   several times or by two users. **Identical transactions received together**
   (two coffees on the same day, at the same price) are indeed both kept: they are
   told apart by their rank.
4. **Month of attribution**: a **received transfer dated on the 25th or later** is attributed
   to the **1st of the following month** (a salary paid on the 28th counts for the month it
   funds); the actual date remains visible in the transaction details, where the date it is
   counted on can also be changed by hand (the next synchronizations no longer change it). The day is set in
   **Administration → Settings** (`next_month_day`, 1 to 31, 0 to never move a transfer, see
   [Configuration](configuration.md#global)).
5. **Notifications**: see [Notifications](#notifications).

Then, once all accounts are processed, the **categorization rules** classify the
transactions of the last month still without a category.

- **When**: every day with Docker (`SYNC_INTERVAL`), through a scheduled task (cron with
  the `sync_token`: the **Administration → Sync** tab gives the ready-made `curl` command and
  crontab line), or on demand from the Accounts tab, for all
  accounts or a single one.
- **Without synchronization** (bank not covered by woob, importing past history): the
  statements downloaded from the bank are **imported** from the Transactions tab (see
  below).
- **Live tracking**: steps per account (upcoming, history, notifications), number
  of transactions received and new, detailed log (with woob's debug logs if
  `woob_debug` is enabled); on a woob error, a link to the issues of the module concerned.
- **One at a time**: a synchronization started while another is running is refused
  ("already in progress").

## Transactions { #transactions }

- **List of the selected month**, most recent first: icon and color of the category
  (or of the transaction type if it is not categorized), label, date, category and amount.
- **Checking off**: each transaction is "checked" or not, in one click. Unchecked
  transactions are in bold; a toggle shows only those, with their count. Categorizing
  a transaction also marks it as checked. The number of transactions to review
  is shown in the notifications and in the iPhone app badge.
- **Month picker** in the header, limited to months that have transactions.
- **Search** across all months, from the bar at the top of the page, from two characters,
  with the total of the transactions found. The **advanced search** (button inside the
  field) adds criteria:
  expenses or incomes, smallest and largest amount (without sign), period, categories (with
  their sub-categories) and accounts. Each criterion is shown right below the search bar and
  removed in one click.
- **Details**: amount, full label, date and actual date, type (card, transfer,
  direct debit…), card, originating bank, import date; the category and the checked status can be
  changed there. **Similar transactions** runs a search on the merchant's name
  ("PRLV SEPA EDF MDT/123" searches for "EDF").
- **Comment** a transaction (from its details, **Comment** field, 500 characters): a free
  text seen by the whole household ("paid back by Paul", "Léa's present"), shown under the
  date in the lists and found by the search. The synchronization and a statement import do
  not change it.
- **Change the date a transaction is counted on**, synchronized or not (from its details,
  **Counted on** field): it moves to the previous or the next month for the totals, the
  budgets and the trends (a bill paid on the 1st that belongs to the month before, a salary
  received early). The real date of the bank does not change and stays shown: the
  synchronization and a statement import recognize the transaction and keep the chosen date.
  The parts of a split transaction follow its date.
- **Delete** a transaction (from its details, or several at once: **Select**, tick the
  transactions, **Delete**; after confirmation): it disappears
  for the whole household and is never imported again, neither by synchronization nor by importing
  a statement.
- **Change the category** of several transactions at once: **Select**, tick the
  transactions, then choose the category in the list, on one of the ticked transactions
  ("Uncategorized" included): every ticked transaction gets it, and the selection stays. As
  for a single one, they are checked off and the rules no longer change this category chosen
  by hand.
- **Selection mode**: **Select all** ticks every transaction shown (filter and search applied,
  500 at most), then becomes **Unselect all**; the cross at the right of the bar closes the
  selection mode.
- **Enter a transaction by hand** (**+** button), for example a cash expense, on any
  account: a manual account, or a synchronized one (a transaction the bank does not send
  yet; if it sends it later, delete the duplicate): expense or income, amount, label, date,
  category (otherwise the one of the rules) and comment. Any user can enter one; it counts like the
  others, is never overwritten by the synchronization and stays editable (label, date,
  amount, comment) from its details.
- **Split** a transaction across several categories (from its details): a supermarket
  receipt partly food and partly home, a cash withdrawal spent on groceries and transport.
  The first line is the transaction itself (its category, which may change, and the amount
  it keeps), the next ones its parts (category and amount, 10 at most); the whole amount must
  be allocated, without exceeding it: **Save** is possible only once the **amount left to
  allocate** is 0, and the window says what is missing otherwise. Each part becomes a
  transaction shown right below the original one, which shows the bank amount. The total
  stays the bank's one: budgets, trends, flow and insights count each part in its category.
  Synchronization keeps the split, rules do not change it, and checking the transaction
  checks its parts. Deleting a part gives its amount back to the transaction; **Cancel the
  split** gives it back the whole amount.
- **Categorize by hand** a transaction without a category: a picker with search,
  icons and colors. For an administrator, Carbure then offers to **create the matching
  rule**, so that the next ones are classified automatically.
- **Recurring transactions** detected automatically and **bank statement import**:
  see below.

### Bank statement import { #import-de-releves-bancaires }

For a bank that woob does not cover, or to bring in the history from before Carbure:

1. Download the statement from the bank's website, in one of the formats offered:
   **OFX/QFX**, **QIF**, **CSV** or **CAMT.053** (XML ISO 20022).
2. In the **Transactions** tab, click **Import** and drop the file (1 MB at
   most).
3. The **preview** classifies each row: **new** (ticked), **already known** (same fingerprint
   as an existing transaction, ignored) or **probable duplicate** (same amount within 3
   days, often the same transaction already synchronized with a different label: unticked by
   default, it is up to you to decide).
4. Confirm: the ticked transactions are saved (the details show "imported
   statement" as their origin), then the **rules** categorize them; the list opens on the
   last imported month.

![Preview of the import of a Boursorama CSV statement: new transactions ticked, probable duplicates unticked, with the matching existing transaction](assets/screenshot-import.webp)

A CSV exported by one of the banks below is read with the **profile of its bank**
(its columns, its encoding, its rows before the header): the preview says so, for example
"Recognized format: Boursorama / BoursoBank". For any other bank, the preview shows
"Generic format (columns guessed)": the columns are recognized from their header
(date, label, amount, or debit and credit), including French formats (`;`,
`1 234,56`, Windows-1252 encoding) and the account rows before the header; in that case, check
the preview before confirming. Non-posted rows from neobanks (pending,
cancelled, declined) and zero amounts (card authorizations) are ignored; fees
given separately (Revolut) are deducted. The label is cleaned as during synchronization. The
file's content is never written to the logs.

Recognized CSV exports (formats taken from open source projects that import them, then
tested on an anonymized file with the same structure; one profile per bank, see
[Add a bank to the import](developpement.md#ajouter-une-banque-a-limport)):

| Bank | File to choose | Specifics read |
|---|---|---|
| Boursorama / BoursoBank | CSV export of transactions | signed `amount` column |
| Crédit Agricole | CSV export | account rows before the header, debit and credit |
| La Banque Postale | "Téléchargement des opérations", CSV | account rows, `Montant(EUROS)`, Windows-1252 |
| Société Générale | "Télécharger les opérations", CSV | account row, label and entry details |
| Crédit Mutuel / CIC | CSV export | negative debit and credit, ISO-8859-15 |
| Caisse d'Épargne / Banque Populaire | CSV export | transaction label and additional information |
| BNP Paribas / Hello bank! | CSV export | summary rows before the header, `jj-mm-aaaa` dates |
| Fortuneo | `HistoriqueOperations….csv` | debit and credit, ISO-8859-1 |
| N26 | CSV export (old and new format) | payee name and payment reference |
| Revolut | "Excel" statement (a CSV) | dates with time, fees, transaction state |
| Qonto | CSV export (English or French) | status, counterparty name and reference |

For any other bank, or if the CSV export is not recognized, choose **OFX** (often
called "Money" or "Quicken"): it is the most consistent format from one bank to another.
LCL, Shine, Lydia/Sumeria, BforBank and Monabanq could not be verified for lack of a
public example of their exports.

### Recurring transactions { #transactions-recurrentes }

Carbure **automatically detects** the transactions that come back every month: salary, rent,
subscriptions, bills, reimbursements…

- **"Monthly" badge** on these transactions in the list of the month.
- A **bar** at the top of the list, like the selection one, sums them up. Folded by
  default, it shows only their number; its chevron unfolds it (choice remembered by the
  browser): how many were received and expected, and the **recurring transactions total**
  (net: incomes minus expenses, the ones still expected counted at their usual amount).
- In this unfolded bar, a **toggle** shows only those, and lists the ones **expected this month** that have
  not arrived yet, with their usual day and amount ("≈" if it varies); for a
  past month, the ones that were **missed** ("Missing this month"). Handy to check that
  the salary came in or that a direct debit was not forgotten.
- A **calendar of the month** (**Calendar** button) places them day by day: received,
  expected or late, with the total of what already came and of what is still to come.

**Detection rules**, over the last 6 months:

- transactions are grouped by **direction** (debit or credit) and by **label without its
  digits** (references, dates, invoice numbers);
- a series is recurring if it appears in **at least 3 months**, **about once a
  month** (1.5 times at most), **around the same day** (3 times out of 4 within 5 days), and if it
  is **still active** (seen this month or the previous one);
- a **card payment** must also keep the same amount within 20% (a subscription,
  not the local restaurant); a direct debit or a transfer may vary (electricity, water).

## Tags { #tags }

A **tag** follows the whole cost of a project (a trip, works…) without touching the
categories: each transaction keeps its own, the budgets and the trends do not change.

- **Tags tab**: a tag has a name (`#trip-italy`), a color and a **period** (start, optional
  end). During its period it is **active** and offered; after its end it is **archived**: no
  longer offered, its summary still readable.
- **Tag by hand**: from the details of a transaction, or on a **selection** of the list
  (**Select**, tick the transactions). **Type** the tag: the matching active tags are listed,
  Enter or a click chooses it; a new name offers to **create** the tag (from today, default
  color). In the details, the tag chosen is added at once; on a selection, **Apply the tag**
  adds it to the ticked transactions. A transaction may have several tags; no automatic rule.
- **Summary of a tag**: its net cost (refunds deducted), its expenses and refunds, its cost
  per day and its largest expense, the running cost chart day by day (on hover or with the
  keyboard, with the table of the values), the split by category (amount and share) and its
  transactions.
- **Search** `#trip-italy` in Transactions: the transactions of the tag, every month. The
  parts of a split transaction get its tags, then are tagged apart.
- The `tag` and `transaction_tag` tables can be read by the [insights](insights.md).

## Categories { #categories }

Reserved for administrators: **Administration** menu → **Categories**. The API refuses
the creation, modification and deletion of a category to other users
(`403`); everyone can read the list (transactions, budgets, iOS app).

![Hierarchical expense and income categories, with their icon and color](assets/screenshot-categories.webp)

- **Two levels**: categories (Food) and their subcategories (Supermarket,
  Restaurant). The transactions of a subcategory also count for its parent.
- **Three types**, which drive the calculations:

| Type | Meaning | Examples |
|---|---|---|
| Expense | Money spent, tracked by budgets | Food, Housing |
| Income | Money received | Salary, Benefits |
| Off-budget | Movements that are neither expenses nor income: transfers between one's own accounts, savings | Internal transfers, Savings |

- **Icon and color**, chosen among those the mobile apps can display: the same
  category looks the same everywhere (transactions, budgets, flow, pickers).
- The **"Uncategorized"** category receives everything no rule recognizes; it
  can be neither edited nor deleted.
- **Deleting** a category sends its transactions back to "Uncategorized" and deletes
  its budgets and rules; a category that has subcategories cannot be
  deleted.

## Categorization rules { #regles-de-categorisation }

A rule associates a **keyword** with a category: any transaction whose label
**contains** this keyword receives the category ("CARREFOUR" → Supermarket).

- The keyword is stored in uppercase, like the labels.
- **Amount (optional)**: a rule with an amount only applies to the transactions of this
  amount, **in absolute value and to the cent** ("10" matches an expense of -10.00 as well
  as an income of 10.00). Two rules can thus share a keyword: "PAYPAL" → Shopping and
  "PAYPAL · 10.00" → Spotify. A keyword has only one rule without amount, and one rule
  per amount.
- When several rules match a transaction, **a rule with an amount wins** over the rules
  without amount; otherwise **the rule created first** wins.
- **Creating or editing a rule applies it immediately to the whole history**, including
  transactions already categorized; the rule's dialog shows how many
  transactions it matches and how many are already in the category. A rule without
  amount leaves the transactions matched by a rule with an amount.
- **A category chosen by hand is never overwritten by a rule**: Carbure remembers the
  origin of the category of each transaction (a rule, or a choice by hand from the portal,
  the mobile apps, a split or a transaction entered by hand). The categories set before
  version 1.12.0 have an unknown origin.
- At each synchronization, the rules classify the new transactions without a category.
- **Resynchronize** applies all the rules to the whole database and shows the number of
  recategorized transactions.
- **Keep the existing categories** (switch of the Rules tab, off by default): creating or
  editing a rule and resynchronizing only categorize the transactions without a category
  and the ones a rule categorized. Correcting a rule (EDF moved from Énergie to Logement)
  therefore moves the transactions it had classified, but leaves the categories of unknown
  origin alone.
- **Send a notification** on a rule: each new matching transaction
  is reported to the whole household, by push or by email according to each person's
  preferences (see [Notifications](#notifications); a bell marks these rules). Useful for
  keeping an eye on rent, a reimbursement or a subscription.

## Budgets { #budgets }

![Budgets of the month per category, with the amount left to spend or the overspend](assets/en/screenshot-budget.webp)

A budget is an **amount per category and per month**.

- **Spent**: the sum of the month's transactions of the category and its
  subcategories, in absolute value. **Progress**: spent ÷ budget.
- **Subcategories**: budget the subcategories, and the parent category equals **their
  sum** ("Σ" before the amount); or give the parent category **its own amount**,
  which replaces the sum. Setting its amount back to zero returns to the sum.
- **Card colors**: the background fills up as the budget is spent; it stays
  **green up to 105%** of the budget and turns **red beyond that**. Each card shows the
  amount left or the overspend.
- **Month summary**: total spent, total budgeted and amount left (or overspend) on budgeted
  expenses, with a gauge.
- **Three sections**: budgeted expenses; income; off-budget and the
  categories with neither a budget nor any expense this month.
- **Explore**: clicking a card shows the category's transactions for the month, and
  its subcategories with their own budget.
- Amounts are set month by month from the card (settings button), by all
  users.
- **Copy from the previous month**: the button above the summary copies the budgets
  of the previous month, either filling in only the categories without a budget or overwriting
  the displayed month. It is offered only when the displayed month has no budget yet and
  the previous month has some (a budget at 0, sum of the subcategories, does not count).

## Money flow of the month { #flux-du-mois }

![Money flow of the month: Sankey diagram from income to expenses, savings and what is left](assets/en/screenshot-flow.webp)

A (Sankey) diagram shows **where the month's money comes from and where it goes**:

- on the left, **income** by category;
- in the center, the month's total income;
- on the right, **expenses** by main category (the eight largest, the others
  grouped), **savings** and what is **left** (or the **deficit** when expenses
  exceed income).

Above it, four **key figures**: income, expenses, savings (with the share of income
saved) and what is left. Each flow takes the color of its category; **clicking a
category** (or its flow, also with the keyboard) highlights it and shows its
**transactions** below the diagram, with their total — "Others" lists those of the
grouped categories.

Internal transfers (off-budget categories) are left out, except savings;
uncategorized transactions remain visible. The same flows are available as a table.

## Trends { #tendances }

![Trends of expenses, income and savings over several months](assets/screenshot-trends.webp)

Over **3, 6, 12, 24, 36 or 60 months** up to the current month, or over a **custom period**
(from a chosen month to a chosen month, 20 years at most), optionally **compared** with the previous
period or with the same period last year:

| Measure | Calculation |
|---|---|
| Debit | Expenses of the month, excluding off-budget categories |
| Credit | Income of the month, excluding off-budget categories |
| Off-budget | Balance of off-budget categories |
| Planned budget | Sum of the budgets of the main categories (their amount, or the sum of their subcategories) |
| Savings | Net amount paid into the savings category and its subcategories (deposits minus withdrawals) |
| Savings rate | Savings ÷ income |

The savings category is "Épargne" (case and accents ignored), or the one set in the
`savings_category` setting. The key figures (total and average savings, savings rate, average
spending compared with the planned budget) show the difference from the compared period. Each chart
is also available as a table.

The **By category** card compares every category of a type (expenses, income or
off-budget) on one chart, main categories (subcategories included) or subcategories: a
click on the chip of a category hides or shows it again (**All** / **None**), the scale
fitting the categories shown. The chart is drawn as **lines**, stacked **bars** or stacked
**areas**; this choice, the hidden categories, the type and the level are remembered by the
browser, and the kind of chart also applies to the custom charts.

Administrators add **custom charts** (12 at most, shared by the household): the month by month
trend of a category (with its subcategories), of a subcategory or of an insight, with its
average over the period and, for a category, its planned budget.

## Insights { #insights }

![Insights: monthly indicators of the household budget and their history](assets/screenshot-insights.webp)

**Indicators of the month** that the household defines itself: groceries, fuel, subscriptions,
income, savings… Each insight has a name, a color, an icon and an **SQL query** that
returns an amount for the displayed month.

- An administrator creates them in the portal; the query is checked while typing and
  its result for the displayed month is shown.
- The query runs **read-only**: a single `SELECT` statement, with no writes,
  no access to user accounts or tokens (see [Security](securite.md)).
- The month-by-month history of each insight is available through the API, for the mobile apps.
- Each insight is **shown or hidden** for the whole household. A new installation shows
  only the main insights of the starter pack (expenses, income, left this month, savings,
  uncategorized, over budget): the others are installed but hidden, and the **+** button of
  the Insights screen offers them in one click. Any user can hide a useless card; a hidden
  insight is no longer computed.
- Each user can set an **alert** on a shown insight, with the **bell** of its card: be told
  when the value of the month goes **above** or **below** an amount ("Expenses above
  2,000 €", "Left this month below 200 €"). See [Insight alerts](#alertes-des-insights).

**Ready-to-use recipes** (a category and its subcategories, subscriptions,
comparison with last year, savings rate, emergency fund, remaining budget,
duplicates…) are gathered in [Insight recipes](insights.md), with the query and how
to read the result of each.

### Emergency fund { #matelas-de-securite }

The starter pack includes the **Emergency fund** insight: the expenses of the **6 full
months preceding the displayed month** (debits, excluding "off-budget" categories such as
savings), as a positive amount. It is the order of magnitude of the precautionary savings to
keep aside to cover 6 months of expenses; the current month is not counted,
so that the value does not change during the month. On an existing instance, an
administrator adds it from the **Insights** screen with this query:

```sql
SELECT -SUM(amount) AS amount FROM bank_transaction WHERE amount < 0 AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0) AND date >= MAKEDATE({year}, 1) + INTERVAL {month} - 7 MONTH AND date < MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH
```

## Notifications { #notifications }

Notifications are sent by **push** to the [**iPhone and
Android apps**](applications-mobiles.md) (Apple Push Notification service, Firebase Cloud
Messaging) and, if an administrator has configured an SMTP server, by **email** to the
profile's address. Push notifications go through the **Carbure Push** relay, with which an
administrator registers the instance after accepting its consent terms (see
[Configuration](configuration.md#push)):

!!! info "Mobile apps"
    The Carbure apps are available on the [App Store](https://apps.apple.com/fr/app/carbure/id6824057363)
    (iPhone) and on [Google Play](https://play.google.com/store/apps/details?id=org.carbure.app) (Android).

| When | Who receives it | Content |
|---|---|---|
| After each account is synchronized | The whole household | Success or failure, and number of transactions to review |
| A new expense exceeds the alert threshold | Each user whose threshold is exceeded | "Large expense to check", label and amount |
| A new transaction matches a "Send a notification" rule | The whole household | The keyword, the label and the amount (only one notification per transaction) |
| The month's expenses of a budgeted category reach the budget threshold | Each user who has enabled these alerts | "Budget exceeded: Groceries", "312,40 € of 300,00 € (104%)" |
| The value of the month of an insight goes above or below the amount of its alert | The user who set the alert, if they have enabled these alerts | "Insight above its threshold: Expenses", "2,140.00 € this month (threshold: 2,000.00 €)" |

The app **badge** shows the number of transactions to review. The alert threshold is
set in **My profile → Notifications**, under "Large expense" (empty = no alert).

Each notification is written in the **profile language** of its recipient (French,
English, Spanish, Italian, German or Portuguese; any other value gives French),
by push as well as by email: title, text, subject and signature of the email. Amounts and
dates follow this language (`-1 234,56 €` and `15/10/2026` in French and Portuguese,
`-1,234.56 €` and `Oct 15, 2026` in English, `-1.234,56 €` and `15/10/2026` in Spanish and
Italian, `-1.234,56 €` and `15.10.2026` in German);
the error of a failed synchronization is shown as the bank (woob) returns it.
The server's texts are gathered in `src/lib/Messages.php`.

Each user chooses, in **My profile → Notifications**, the channels for each type
of notification (result of a synchronization, "Notify" rule, large expense,
budget exceeded, insight alert): push, email, both or none. By default, everything is sent
by push and nothing by email, except the budget and insight alerts, which are disabled
until the user enables them.
Email is offered only if an SMTP server is configured (**Administration →
Settings**, `[smtp]` section) and the profile has an email address; the failure of one channel
prevents neither the other nor the synchronization.

### Budget alerts { #alertes-de-budget }

- **When**: after each synchronization and each statement import, for the **current
  month** only (importing a statement of past months does not alert on old
  budgets).
- **What**: the month's expenses of each expense category that has a budget,
  subcategories included, calculated like the **Budgets** screen; a subcategory that has
  its own budget is checked too.
- **Thresholds**, chosen by each person in **My profile → Notifications**, as a percentage
  of the budget, from 50 to 200% (80% to be warned ahead of time). Below 100%, the title
  becomes "Budget almost reached".
    - **All the budgets**: a global threshold (100% by default), which can be turned off;
    - **Thresholds per category**: a category with a budget gets its own threshold, which
      replaces the global one for it. For instance 100% everywhere and 80% on groceries and
      leisure; or, with the global threshold off, only groceries at 80% and housing at 100%.
      Deleting the category deletes its threshold.
- **Once** per category, month and user, not at every synchronization. If the budget
  is then raised and reached again, the alert fires again for the new amount.

### Insight alerts { #alertes-des-insights }

- **Setting**: the **bell** of each card of the **Insights** screen opens the user's alert
  on this insight: the direction (**above** or **below**) and an amount. The card reminds
  the alert set ("Alert above 2,000.00 €"). Each user has their own alerts; the channels are
  chosen in **My profile → Notifications**, under "Insight alert" (off by default).
- **Comparison**: with the value of the month **without its sign**, as on the card:
  expenses of -2,140 € are above 2,000 €. Strictly above or below: a value equal to the
  amount does not alert.
- **When**: with the budget alerts, after each synchronization and each import of a
  statement, for the **current month** only.
- **Once** per insight, month and user. Changing the direction or the amount arms the
  alert again for the current month.
- Only a **shown** insight alerts: hiding or deleting it deletes its alerts.

## AI agents { #agents-ia }

![AI agent connected to the Carbure MCP server to query the household budget](assets/screenshot-agents.webp)

A built-in **MCP server** lets an AI agent answer questions about the household's
budget: "How much did we spend on restaurants this year?", "Which categories
are over budget this month?", "How are our savings evolving?".

- **Read-only**: the agent can list categories, the transactions of a month,
  search transactions, total expenses by category over a period, read the
  budget of a month, the trends and the rules; it cannot change anything.
- **Disabled by default**: an administrator enables it.
- **Claude (web, Desktop, mobile)** connects with just the server URL: Claude opens
  the portal, you sign in and **authorize** access (OAuth).
- **Claude Code, ChatGPT, Cursor, Copilot, Gemini** connect with an **access token**
  created in the AI agent tab (30 days, 90 days, one year or no expiration), with the
  ready-to-copy configuration for each agent.
- Each access appears in **My access tokens** (creation, last use,
  expiration) and can be **revoked** in one click.

!!! warning "Privacy"
    The data an agent looks at (labels, amounts, categories) is sent to its
    provider to be analyzed. Details: [AI agents (MCP)](mcp.md).

## Profile and account security { #profil-et-securite-des-comptes }

- **My profile**, in four sub-tabs: **Profile** (email, first name, last name, password,
  language applied at each sign-in on every browser, theme), **Two-factor
  authentication** (authenticator app, backup codes),
  **Notifications** (push channels of the mobile app and email, alert threshold, watched budget
  and its threshold) and **My devices** (iOS and Android mobile apps, which can be removed;
  web browsers of the portal logins, the current one marked, which can be removed;
  trusted web browsers, which can be revoked).
- **Passwords** hashed (bcrypt); session valid for 30 days.
- **Lockout**: after 5 failed sign-in attempts in a row, the account is locked for 24 hours; an
  administrator can unlock it.
- **Two-factor authentication**: the administrator can require at sign-in the code
  from an authenticator app (Google Authenticator…), set up with a QR code
  at the first sign-in or from the profile, with 10 backup codes. The code is
  not asked for from an already registered mobile device (iOS, Android), nor in a browser that
  the user has trusted for 6 months. An administrator resets it for
  a user who has lost their phone (see [Security](securite.md#double-authentification-totp)).

## Display and accessibility { #affichage-et-accessibilite }

- **Light or dark theme**: by default, the system's; the icon in the header switches in one
  click to the other theme, and the choice is saved in the profile (applied on another
  browser too).
- **System preferences** respected: increased contrast (macOS, iOS) and forced colors
  (Windows high contrast), reduced motion.
- **Accessibility** at WCAG level AA, verified by automated tests on each
  screen, in light theme and in dark theme.
- **Installed app**: the portal can be installed as an app (Chrome, Edge,
  Safari, Android), over HTTPS, without offline mode.

Details: [Web portal](portail.md#accessibilite).

## Administration { #administration }

The **Rules** and **Accounts** tabs, and the **Administration** menu
(**Users**, **Categories**, **Logs**, **Settings**, **Sync**, **Mobile push**, **AI agent**, **Backup**, **Purge**, **API**) are reserved for administrators; other
users open **AI agent** from their menu.

- **Users**: creation, editing (including the profile), deletion, last
  sign-in, unlocking a locked account. A user who owns transactions
  cannot be deleted.
- **Logs**: the server logs, filtered by level or by text; clicking
  a request's identifier shows all its lines. Secrets (passwords, tokens)
  are masked there.
- **Settings**: the server configuration file, section by section, with secrets
  masked. Safe settings (log level, savings category, public address,
  woob options, mobile push notifications, SMTP server for emails, with a **test email**)
  can be changed in the portal, each value being validated; the database, the secrets, the woob command and label cleaning remain
  read-only.
- **Sync**: the `curl` command (for all accounts or a single one) and the crontab line that
  start the synchronization, with the token masked until "Show"; the tab creates or
  renews this token.
- **Backup**: export of the chosen data (categories, rules, budgets, insights,
  insight alerts, transactions, deleted transactions, tags) into a ZIP, one JSON file per type; import of a
  ZIP (choosing its files) or of a single file, which **deletes and replaces** the
  data of each imported type, after confirmation, all or nothing. No users, no
  passwords, no tokens and no bank connections in a backup.
- **Purge**: to start again from scratch, deletion of every row of the chosen data
  (with the data that depends on it), after confirmation, from the local network
  only; bank accounts, users and settings kept.
- **API**: the API documentation (Swagger UI) in the portal; "Try it out" calls
  the API with the administrator's session.
- **Database update**: when a new version changes the database, a banner
  reports it and applies it in one click (automatic with Docker).
- **New release**: a banner reports that a new release of Carbure is published, with a
  link to its notes (checked once a day by the server, `update_check` setting).
- **Session key**: a banner reports a key that is too weak and renews it in one click
  (everyone signs in again).

## Installation and updates { #installation-et-mises-a-jour }

- **Setup wizard** in the browser: database connection, creation of the
  tables (or update of an existing database), administrator account, language. On a new
  database, it offers a **starter pack**: about thirty categories, about a hundred
  rules (retailers, energy suppliers, telecom operators…) and insights (expenses,
  income, what is left this month, savings, emergency fund, budget overspend, bank
  fees…). When exposed to
  the Internet, it also asks for a setup code.
- **All-in-one Docker image** (nginx, PHP, woob), with MariaDB: see
  [Installation](installation.md) and the [Synology tutorial](synology.md).
- **Updates**: each new version brings its database migrations, applied at
  container start or from the portal banner.

## Known limitations { #limites-connues }

- **One currency** per instance (`currency` setting, euro by default), only displayed:
  no conversion, no multiple currencies; the mobile apps always show euros.
- The banks covered are those of **woob**, mostly French.
- Recurring transactions are **detected**, not **scheduled**: no bill
  schedule and no balance forecast.
- No **savings goals**.
- The [iPhone and Android apps](applications-mobiles.md) are for day-to-day tracking;
  administration and statement import are done in the web portal, which is used in
  the browser or can be installed as an app
  ([over HTTPS](portail.md#installer-le-portail-comme-une-application)). Neither the apps nor the
  portal have an offline mode.

See also the [comparison](comparatif.md) with Firefly III, Actual Budget, Kresus, Sure and ezBookkeeping.
