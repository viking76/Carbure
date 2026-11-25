---
title: "Agents IA (MCP) : votre budget dans Claude ou ChatGPT"
description: "Connecter un agent IA (Claude, ChatGPT, Cursor, Copilot, Gemini) au budget du foyer grâce au serveur MCP de Carbure, en lecture seule, par OAuth ou jeton."
icon: material/robot-outline
---

# Agents IA (MCP)

Carbure embarque un **serveur MCP** ([Model Context Protocol](https://modelcontextprotocol.io/))
qui permet à un agent IA (Claude, ChatGPT, Cursor, Copilot, Gemini…) de consulter les
données du foyer et de répondre à des questions comme « Combien avons-nous dépensé en
restaurants cette année ? », « Quelles catégories dépassent leur budget ce mois-ci ? » ou
« Comment évolue notre épargne depuis 6 mois ? ».

- **Lecture seule** : aucun outil ne modifie les données.
- **Rien à installer** : le serveur est une route de l'API (`/api/mcp`), servie par le même
  serveur web que le portail.
- **Sous contrôle** : désactivé par défaut, un administrateur l'active ; chaque utilisateur
  crée ses jetons d'accès et les révoque quand il veut.

!!! warning "Confidentialité"
    Carbure reste hébergé chez vous, mais les données qu'un agent IA consulte (libellés,
    montants, catégories) sont envoyées à son fournisseur pour être analysées. N'activez cet
    accès que si cela vous convient.

## 1. Activer le serveur MCP

Onglet **Agent IA** du portail (menu **Administration**) : un administrateur active l'interrupteur **Serveur MCP
activé** (le réglage `mcp_enabled` est enregistré en base). Tant qu'il est désactivé,
`/api/mcp` répond `403`, même avec un jeton valide, et les utilisateurs ne peuvent pas créer
de jeton.

## 2. Créer un jeton d'accès

Dans l'onglet **Agent IA** (menu **Administration** pour un administrateur, menu utilisateur sinon), bouton **+** : donnez un nom au jeton (par exemple
« Claude Code sur mon portable ») et choisissez sa **durée de validité** (30 jours, 90 jours,
1 an ou sans expiration ; un jeton expiré est refusé et signalé « Expiré » dans la
liste). Le jeton (`cbt_…`) n'est affiché **qu'une seule fois**, avec la configuration prête
à copier pour chaque agent. La liste **Mes jetons d'accès** montre leur date de création,
de dernière utilisation et d'expiration ; la corbeille révoque un jeton immédiatement.

Chaque utilisateur peut avoir 20 jetons au plus. Seule l'empreinte SHA-256 du jeton est
conservée en base.

## 3. Connecter son agent IA

### Claude (web, Desktop, mobile) : sans jeton, par OAuth

Dans Claude : **Paramètres → Connecteurs → Ajouter un connecteur personnalisé**, collez
l'URL du serveur MCP (`https://<votre-serveur>/api/mcp`, affichée dans l'onglet **Agent IA**)
puis cliquez sur **Se connecter**. Claude ouvre le portail de Carbure : connectez-vous si
besoin, puis **Autoriser**. L'accès apparaît dans **Mes jetons d'accès**, au nom de l'agent
(« Claude »), et se révoque comme les autres jetons. Carbure doit être accessible en HTTPS
depuis Internet (voir [Synology](synology.md), étape 8).

### Les autres agents : avec un jeton

| Agent | Configuration (proposée par le portail) |
|---|---|
| Claude Code | `claude mcp add --transport http carbure <url> --header "Authorization: Bearer <jeton>"` |
| ChatGPT | connecteur en mode développeur, URL `<url>?token=<jeton>` (HTTPS public requis) |
| Cursor | bloc `mcpServers` de `~/.cursor/mcp.json` avec l'en-tête `Authorization` |
| VS Code (Copilot) | bloc `servers` de `mcp.json` (type `http`) avec l'en-tête `Authorization` |
| Gemini CLI | `gemini mcp add --transport http --header "Authorization: Bearer <jeton>" carbure <url>` |

`<url>` est `https://<votre-serveur>/api/mcp`. Tout client MCP compatible avec le transport
**Streamable HTTP** fonctionne de la même façon.

!!! note "Jeton dans l'URL"
    Pour les agents qui ne savent pas envoyer d'en-tête (ChatGPT), le jeton peut être passé
    en paramètre `?token=`. Il n'est pas écrit dans les logs de Carbure ni dans le log
    d'accès de nginx de l'image Docker (masqué en `token=***`), mais peut l'être par un proxy
    placé devant : préférez l'en-tête quand l'agent le permet.

## Outils disponibles

| Outil | Rôle |
|---|---|
| `list_categories` | Catégories : nom, parent, type (dépense, revenu, hors budget) |
| `list_transactions` | Transactions d'un mois, éventuellement d'une catégorie |
| `search_transactions` | Transactions de tous les mois : par texte du libellé, montant, dépenses ou revenus, catégories, période |
| `spending_by_category` | Total et nombre de transactions par catégorie sur une période (`YYYY-MM`) |
| `get_budget` | Dépensé et budget prévu par catégorie pour un mois |
| `get_trends` | Débit, crédit, hors budget, budget prévu et épargne mois par mois |
| `spending_by_month` | Dépenses, revenus et solde de chaque mois d'une période explicite (`from`, `to` : `YYYY-MM`), avec le total, la moyenne mensuelle et le mois le plus dépensier ; catégories retenues (`category_ids`) ou écartées (`exclude_category_ids`), sous-catégories comprises. Un identifiant inconnu est refusé avec la liste des identifiants valides. Hors budget exclu, comme `get_trends` |
| `recurring_transactions` | Séries récurrentes de la détection du portail encore actives en fin de période : jour habituel, montant moyen et dernier, montant de chaque mois et ses changements (une hausse de tarif et son mois) |
| `duplicate_transactions` | Doublons possibles : transactions du même compte, du même jour, de même libellé et de même montant (parfois deux vrais paiements identiques) |
| `one_off_transactions` | Transactions dont le libellé (sans ses chiffres) n'apparaît qu'une fois sur la période, de la plus grosse à la plus petite : achats ponctuels, paiements annuels (`kind`, `limit`) |
| `list_rules` | Règles de catégorisation automatique |
| `get_insight_guide` | Tout ce qu'il faut pour écrire un insight : règles de la requête, tables et colonnes lisibles, expressions courantes, couleurs, exemples et insights déjà définis (leur requête pour un administrateur) |
| `check_insight_query` | Vérifie une requête d'insight avec les règles du portail et l'exécute en lecture seule sur un mois et jusqu'à 5 mois avant (administrateurs) : son montant par mois, ou l'erreur à corriger. Rien n'est enregistré |

## Proposer un insight en langage naturel { #proposer-un-insight }

L'agent peut transformer un besoin exprimé en langage naturel (« combien je dépense au
restaurant le week-end ? ») en un [insight](insights.md) prêt à ajouter : nom, couleur,
icône et requête SQL vérifiée. Le serveur propose pour cela le **prompt** `propose_insight`
(argument `need`, le besoin), que les agents présentent comme une commande (dans Claude Code :
`/mcp__carbure__propose_insight`). Il demande à l'agent de lire `get_insight_guide` et les
catégories, d'écrire la requête, de la vérifier avec `check_insight_query` sur les trois
derniers mois et de contrôler que les montants sont plausibles. Sans le prompt, il suffit de
demander à l'agent de proposer un insight : ses instructions l'orientent vers ces outils.

Le serveur reste en **lecture seule** : l'agent propose, un **administrateur** ajoute
l'insight dans l'onglet Insights (bouton **+**, puis **Créer un insight**), où la requête
est vérifiée à nouveau. `check_insight_query` est réservé aux administrateurs, comme la
vérification du portail : avec le jeton d'un autre utilisateur, l'agent propose une requête
sans pouvoir l'exécuter.

## Détails techniques

- Transport **Streamable HTTP** sans session : `POST /api/mcp` reçoit un message JSON-RPC 2.0
  et répond en JSON ; une notification reçoit `202` sans corps ; `GET /api/mcp` répond `405`
  (pas de flux SSE).
- Versions du protocole : `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`.
- Authentification : jeton d'accès (`cbt_…`, en-tête `Authorization` ou `?token=`) ou JWT du
  portail ; sinon `401` avec `WWW-Authenticate: Bearer resource_metadata="…"`, qui indique à
  l'agent où trouver comment obtenir un jeton. Une requête portant un en-tête `Origin` d'un
  autre site que Carbure, `claude.ai` ou `claude.com` est refusée (`403`), comme toute
  requête quand le serveur est désactivé.
- **OAuth 2.1** (agents qui ne prennent qu'une URL), selon la spécification MCP :
  métadonnées `/.well-known/oauth-protected-resource` (RFC 9728) et
  `/.well-known/oauth-authorization-server` (RFC 8414) ; enregistrement dynamique du client
  `POST /api/oauth/register` (RFC 7591, URI de retour en HTTPS ou sur `localhost`) ;
  autorisation `GET /api/oauth/authorize` (PKCE `S256` obligatoire), qui renvoie vers la page
  de consentement du portail ; `POST /api/oauth/token` (code d'autorisation à usage unique,
  valable 5 minutes, ou jeton de rafraîchissement, avec rotation). Le jeton d'accès est un
  jeton `cbt_` valable 30 jours ; une nouvelle autorisation remplace les jetons précédents du
  même agent. L'URL publique est déduite de la requête (`X-Forwarded-Proto` du proxy) ou du
  réglage `public_url`.
- État du serveur : `GET /api/mcp/settings`, `PUT /api/mcp/settings` (`enabled`,
  administrateurs), table `settings`.
- Les outils s'exécutent avec les droits de l'utilisateur du jeton.
- Code : `src/resources/Mcp.php` (protocole et outils), `src/resources/ApiToken.php` (jetons),
  `src/resources/OAuth.php` (OAuth 2.1), `portal/modules/oauthModule.js` (consentement).
