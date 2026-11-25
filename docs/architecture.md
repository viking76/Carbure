---
title: "Architecture : portail web, API REST PHP et woob"
description: "Comment fonctionne Carbure : portail Vue 3, API REST PHP sans framework, MariaDB, synchronisation par woob, Carbure Push et serveur MCP."
icon: material/sitemap-outline
---

# Architecture

## Vue d'ensemble

```mermaid
flowchart LR
    ios[App iOS] -->|HTTPS + JWT| nginx
    portal["Portail web<br/>portal/"] -->|HTTPS + JWT| nginx
    agent[Agent IA] -->|MCP + jeton cbt_| nginx
    cron[Planificateur<br/>SYNC_INTERVAL / cron] -->|X-Sync-Token| nginx
    nginx[nginx<br/>fichiers statiques] -->|/api/ FastCGI| api
    subgraph serveur[PHP-FPM]
        api["src/api.php"] --> ws[Webservice]
        ws --> resolver["ApiResolver<br/>attributs ApiRoute"]
        resolver --> res["src/resources/*"]
        res --> db[(MariaDB / MySQL)]
        res --> woob[Woob<br/>commande shell]
        res --> relay[PushRelay<br/>HTTPS + clé d'API]
        res --> mailer[Mailer<br/>SMTP]
    end
    woob --> banque[(Banque)]
    relay --> push[Carbure Push<br/>service externe]
    push --> apple[APNs iOS]
    push --> fcm[FCM Android]
    mailer --> smtp[(Serveur SMTP)]
```

| Couche | Rôle |
|---|---|
| nginx | Sert le portail (`/portal/`) et Swagger UI (`/swagger/`) avec leurs en-têtes de sécurité, transmet `/api/` à PHP-FPM (sans tampon, pour les flux SSE) ; tout autre chemin renvoie `404` |
| `src/api.php` | Point d'entrée : sans configuration, ne sert que l'assistant d'installation (`Setup`) ; sinon toute URL sans point est traitée comme un appel d'API ; convertit les erreurs en réponse JSON avec code HTTP |
| `Webservice` | Lit le corps JSON et la query string, positionne les en-têtes (sécurité, JSON ou SSE), contrôle l'accès (JWT), appelle la ressource |
| `ApiResolver` | Découvre les routes par réflexion sur l'attribut `#[ApiRoute(path, method, public, stream, raw, admin)]`, avec cache APCu invalidé quand un fichier de ressource change ; `Webservice` vérifie le JWT et, pour `admin: true`, le profil administrateur avant d'appeler la méthode |
| `src/resources/` | Logique métier (espace de noms `Carbure\Resources`, classes chargées à la demande) : `User`, `Bank`, `Transaction`, `Budget`, `BudgetAlert` (alertes de budget), `Category`, `Insight`, `Notification` (canaux choisis par chaque utilisateur), `Device`, `ApiToken`, `Mcp`, `OAuth` (agents IA), `Settings` (Paramètres, Synchro), `System` |
| `Setup`, `Installer` | Assistant d'installation (`/api/setup`) : base, schéma, premier administrateur, `data/conf/prod.ini` |
| `Migrator` | Versionnement du schéma (table `schema_migrations`) et application des migrations |
| `Setting` | Réglages modifiés depuis le portail (table `settings`) |
| `Db` | Connexion `mysqli` unique, requêtes préparées |
| `Woob` | Exécution de woob (`proc_open`) avec envoi de *heartbeats* SSE pendant l'attente |
| `PushRelay` | Client du service externe **Carbure Push** (dépôt privé `ltoinel/Carbure-push`), qui détient les identifiants d'Apple (APNs) et de Firebase (FCM) : inscription de l'instance (preuve de travail, clé d'API `push_api_key`), envoi des notifications avec la plateforme de chaque token (`ios`, `android`), tokens morts signalés puis supprimés ; sans clé, aucun push |
| `Mailer` | Client SMTP intégré (sans dépendance) : envoi des notifications par email |
| `Messages` | Textes des notifications (push et email) dans la langue du destinataire |
| `BankFile`, `import/` | Lecture des relevés importés (OFX/QFX, QIF, CAMT.053, CSV avec un profil par banque) |
| `WoobRepository` | Dépôt des modules bancaires de woob : empreinte de sa clé, clé officielle épinglée (approuvée au premier démarrage), sinon approbation par un administrateur |
| `Logger` | Logs bufferisés dans `data/logs/carbure_AAAAMMJJ.log`, une entrée JSON par ligne : heure ISO 8601, niveau, identifiant de requête (`uid`), IP du client, utilisateur, méthode et chemin, message et contexte ; une ligne de fin par requête (statut, durée) ; purge après `log_retention_days` jours |

## Traitement d'une requête

```mermaid
sequenceDiagram
    participant C as Client (iOS / portail)
    participant A as api.php
    participant W as Webservice
    participant R as ApiResolver
    participant X as Ressource
    C->>A: GET /api/transaction?month=9&year=2026
    A->>W: exec()
    W->>R: findRoute("/transaction", "GET")
    R-->>W: Transaction::get (protégée)
    W->>W: vérifie le JWT (Authorization: Bearer)
    W->>X: Transaction::get(month: 9, year: 2026)
    X-->>W: tableau de transactions
    W-->>C: 200 JSON
    Note over A,C: En cas d'erreur : code HTTP + {"error", "code", "uid"}
```

Les paramètres sont passés **par nom** : la clé `month` de la requête alimente le
paramètre `$month` de la méthode. Un paramètre inconnu provoque une erreur 400.

## Synchronisation bancaire

```mermaid
sequenceDiagram
    participant P as Cron / client
    participant B as Bank::getSync
    participant W as Woob
    participant T as Transaction
    participant D as Notification<br/>(PushRelay, Mailer)
    P->>B: GET /api/bank/sync (JWT ou sync_token)
    B->>B: GET_LOCK('bank_sync') — une seule synchro à la fois
    loop Pour chaque compte (numéro@banque)
        B->>W: woob bank list -b <banque> -f json -s id
        W-->>B: comptes de la banque (le compte doit y figurer, sinon erreur)
        B->>W: woob bank coming <compte> -f json
        W-->>B: opérations à venir (type 12 conservé)
        B->>T: save() : nettoyage du libellé, UUID, INSERT … ON DUPLICATE KEY
        T-->>B: transactions nouvelles
        B->>W: woob bank history <compte> -f json
        W-->>B: historique
        B->>T: save()
        T-->>B: transactions nouvelles
        B->>B: date et résultat dans bank_account.last_sync_*
        B->>D: notification à chaque utilisateur du foyer<br/>(nombre de transactions non pointées)
        B->>D: alerte « dépense importante » pour chaque nouvelle dépense<br/>au-delà du seuil alert_threshold d'un utilisateur
        B->>D: notification des nouvelles transactions d'une règle « notify »
    end
    B->>T: updateMissingCategories() (mots-clés)
    B->>D: BudgetAlert::check() : budgets du mois atteints
    B-->>P: flux SSE « Synchronization complete »
```

- `?account=<numéro>@<banque>` limite la synchronisation à un compte (bouton de
  synchronisation de l'onglet Comptes).
- Les transactions importées sont rattachées au premier utilisateur ayant ajouté le compte
  (`MIN(user_id)` dans `bank_account`), sans restreindre leur visibilité.
- Le **libellé** est mis en majuscules puis nettoyé par les expressions régulières
  `regex_label` de la configuration.
- L'**UUID** d'une transaction est `md5(date réelle + libellé + montant)` (libellé tronqué
  à 24 caractères pour les paiements par carte) : il permet de dédoublonner les
  transactions entre deux synchronisations et de transformer une opération « en cours »
  en opération débitée. Des transactions identiques reçues ensemble (même date réelle,
  libellé et montant : deux cafés le même jour) sont distinguées par leur rang : la
  première garde cet UUID, les suivantes ont `md5(uuid + "#n")`
  (`Transaction::prepareAll()`).
- Les virements reçus (type 1, le salaire) datés à partir du jour `next_month_day` (25 par
  défaut, 0 : jamais) sont rattachés au 1er du mois suivant (`date`) ; `rdate` garde la date
  réelle, et donc l'UUID.
- La **catégorisation** recherche les mots-clés de `bank_transaction_category_keyword`
  dans le libellé des transactions sans catégorie du dernier mois (ou de tout
  l'historique avec `POST /category/keyword/apply`).
- Chaque notification passe par `Notification::notify()`, qui l'envoie sur les canaux
  choisis par le destinataire pour ce type (`sync`, `rule`, `expense`, `budget`) : push
  (`Apns`) et/ou email (`Mailer`, si un serveur SMTP est configuré). L'échec d'un canal
  n'empêche ni l'autre ni la synchronisation.
- Une transaction est **nouvelle** quand son UUID n'existait pas (l'`INSERT` crée une
  ligne). Seules les nouvelles dépenses déclenchent l'alerte de seuil : une
  resynchronisation ou le passage d'une opération « en cours » à « débitée » ne
  ré-alerte pas.

## Portail web

Le portail (`portal/`) est une application Vue 3 sans étape de build : `index.html`
charge Vue, `i18n.js` (chargement des textes `i18n/<langue>.json` avant l'affichage) et
le module `app.js`. L'état et les méthodes sont répartis en
*mixins* (`portal/modules/`), les appels HTTP sont centralisés dans
`portal/services/apiService.js`. Voir [Portail web](portail.md).

## Installation et mises à jour du schéma

```mermaid
sequenceDiagram
    participant N as Navigateur
    participant S as api.php / Setup
    participant D as MariaDB
    N->>S: GET /api/setup (pas de data/conf/prod.ini)
    S-->>N: valeurs par défaut (environnement Docker), code requis ?
    N->>S: POST /api/setup/database
    S->>D: connexion, table users ? migrations en attente ?
    S-->>N: state = none | current | outdated
    N->>S: POST /api/setup/install
    S->>D: sql/carbure.sql + baseline, ou migrations (sauvegarde confirmée)
    S->>D: premier administrateur (s'il n'y en a pas)
    S-->>N: data/conf/prod.ini écrit avec des secrets aléatoires
```

- Le schéma est **versionné** : `Migrator` liste les fichiers `sql/migrations/*.sql`, la
  table `schema_migrations` (créée au besoin) enregistre ceux appliqués, et la version du
  schéma est le dernier. Une installation neuve crée le schéma depuis `sql/carbure.sql` et
  enregistre toutes les migrations comme appliquées (*baseline*).
- Une migration peut déclarer `-- applied-if: <requête>` : si la requête renvoie un nombre
  non nul (migration déjà appliquée à la main), elle est seulement enregistrée.
- Les migrations en attente sont appliquées au démarrage du conteneur Docker
  (`tools/migrate.php`, le conteneur s'arrête si l'une échoue), dans l'assistant pour une
  base existante, ou par un administrateur depuis le bandeau du portail
  (`POST /api/system/migrate`).

## Serveur MCP

`POST /api/mcp` (`Mcp::handle`) est une route publique au sens du routeur, déclarée
`raw` : la méthode renvoie elle-même le corps JSON-RPC. Elle vérifie que le serveur est
activé (`Setting` `mcp_enabled`), l'en-tête `Origin`, puis authentifie le jeton d'accès
(`ApiToken::authenticate`, empreinte SHA-256, expiration) ou un JWT, et exécute les outils
en lecture seule en réutilisant les ressources (`Transaction`, `Budget`, `Category`) avec
l'identité de l'utilisateur du jeton. Voir [Agents IA (MCP)](mcp.md).

## Image Docker

| Élément | Rôle |
|---|---|
| `docker/Dockerfile` | `php:8.5.13-fpm-alpine` + nginx (image multi-étapes : woob est construit à part, sans compilateur dans l'image finale), extensions `mysqli` et APCu, woob et les paquets Python des modules bancaires (`curl_cffi`, `python-jose`, `chompjs`, `schwifty`, `pycryptodomex`) dans `/opt/woob`, `HEALTHCHECK` sur `/api/health` |
| `docker/nginx.conf` | Site nginx (portail, Swagger, API, en-têtes de sécurité, limitation des routes de connexion) ; `docker/nginx-api.conf` : réglages de l'API partagés par ses emplacements ; `docker/nginx-real-ip.sh` : adresse du client derrière les proxys de confiance (`TRUSTED_PROXIES`) |
| `docker/php.ini`, `docker/php-fpm.conf` | Réglages PHP et PHP-FPM |
| `docker/demo/Dockerfile`, `docker/demo/entrypoint.sh` | Image de démonstration (`ltoinel/carbure:demo`) : l'image habituelle avec MariaDB ; à chaque démarrage, base recréée, configuration à secrets aléatoires (`demo=true`), données de `tests/e2e/server/seed.php --demo` avec le compte `demo` / `carbure` et un mot de passe `admin` aléatoire affiché dans les journaux, puis l'entrypoint habituel ; `docker stop` arrête nginx, PHP-FPM et MariaDB proprement |
| `docker/entrypoint.sh` | Prépare `/data`, écrit les proxys de confiance de nginx (`TRUSTED_PROXIES`), attend la base et applique les migrations si Carbure est installé, lance la synchronisation périodique (`SYNC_INTERVAL`), puis PHP-FPM et nginx |
| Volume `/data` (le dossier `data/` du projet) | `conf/prod.ini`, `conf/setup.code`, `logs/`, `cache/`, configuration woob (`woob/`) |
