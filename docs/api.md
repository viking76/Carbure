---
title: "Référence de l'API REST"
description: "Référence de l'API REST de Carbure : authentification JWT, transactions, comptes, synchronisation bancaire, budgets, catégories, règles et administration."
icon: material/api
---

# Référence de l'API

- **Base** : `https://<serveur>/api`
- **Format** : JSON en entrée (corps) et en sortie. Les paramètres peuvent aussi être
  passés en query string ; corps et query string sont fusionnés.
- **Authentification** : en-tête `Authorization: Bearer <token>` (JWT obtenu par
  `POST /user/login`) sur toutes les routes, sauf les routes **publiques** :
  `POST /user/login`, `GET /bank/sync` (JWT ou `sync_token`), `POST /mcp` et `GET /mcp`
  (jeton d'accès ou JWT), `GET /health`, et les routes OAuth des agents IA (métadonnées
  `/.well-known/oauth-*`, `POST /oauth/register`, `GET /oauth/authorize`,
  `POST /oauth/token`). Un test unitaire vérifie cette liste.
- **Paramètres nommés** : chaque clé de la requête correspond à un paramètre de la
  méthode PHP. Une clé inconnue renvoie une erreur 400.
- **Profils** : un **utilisateur** consulte et pointe les transactions, les budgets, les
  insights et les tendances. Un **administrateur** gère en plus les règles, les
  catégories, les comptes, les insights, le serveur MCP, les utilisateurs, la configuration
  et les logs : les routes
  marquées « administrateur » renvoient `403` aux autres. Elles sont déclarées dans leur
  attribut (`#[ApiRoute(..., admin: true)]`) et vérifiées avant l'appel de la méthode ;
  un test unitaire fixe le niveau d'accès (public, utilisateur, administrateur) de chaque
  route, et Swagger l'affiche (extension `x-carbure-access`).

## Erreurs

| Code | Cas |
|---|---|
| 400 | Paramètre manquant, inconnu ou invalide ; JSON invalide |
| 401 | JWT absent, invalide ou expiré ; identifiants invalides ; jeton d'accès MCP invalide |
| 403 | Action réservée à un administrateur ; serveur MCP désactivé ; origine refusée ; code d'installation erroné |
| 404 | Route ou ressource introuvable |
| 405 | `GET /mcp` (le serveur MCP n'ouvre pas de flux) |
| 409 | Conflit (doublon, suppression d'un utilisateur propriétaire de transactions, dernier administrateur…) |
| 423 | Compte bloqué après trop d'échecs de connexion |
| 500 | Erreur serveur (base de données, woob, migration…) |
| 503 | Carbure n'est pas encore installé (voir [Assistant d'installation](#assistant-dinstallation)) |

```json
{ "error": "Unauthorized - Invalid or missing JWT token", "code": 401, "uid": "6ac106d3912d9" }
```

`uid` identifie la requête dans `data/logs/carbure_AAAAMMJJ.log`.

### Code stable d'une erreur

Les erreurs qu'un utilisateur rencontre (validation et conflits des règles, catégories,
insights, utilisateurs, connexion et double authentification, connexion d'une banque,
paramètres, seuils d'alerte…) ajoutent un **code stable** `error_code`, et `params` quand
le message porte des valeurs. Le message `error` reste en anglais, inchangé (journaux, apps
mobiles, clients de l'API, serveur MCP) ; `code` reste le statut HTTP.

```json
{ "error": "This keyword already exists", "code": 409, "uid": "6ac106d3912d9", "error_code": "keyword_exists" }
```

```json
{
  "error": "Account locked after too many failed logins, until 2026-10-27 18:30:00: ask an administrator to unlock it",
  "code": 423,
  "uid": "6ac106d3912d9",
  "error_code": "account_locked_until",
  "params": { "until": "2026-10-27 18:30:00" }
}
```

Un client traduit `error_code` (le portail : `apiErrors.<error_code>` de
`portal/i18n/<langue>.json`, où `{until}` reçoit la valeur de `params`) et affiche le
message `error` quand il ne connaît pas le code (serveur plus récent) ou que l'erreur n'en
a pas (erreur technique, serveur plus ancien). Les codes ne changent pas d'une version à
l'autre ; une erreur peut en recevoir un dans une version suivante. `POST /insight/check`
donne de même `error_code` et `params` à côté de `error`.

## Modifications des données

`GET /changes` (tout utilisateur connecté) donne un **marqueur de modification par type
de données**, incrémenté par chaque écriture. Un client (apps mobiles, portail) compare
les versions avec celles des données qu'il a chargées et ne recharge que les types qui
ont bougé : un appel léger (une lecture indexée de quelques lignes), à faire au retour au
premier plan ; ensuite, l'en-tête de chaque réponse suffit (voir ci-dessous).

```json
{
  "transactions": { "version": 1834, "updated_at": "2026-10-24 21:03:12.418" },
  "accounts":     { "version": 52,   "updated_at": "2026-10-24 21:03:12.377" },
  "categories":   { "version": 17,   "updated_at": "2026-10-20 08:15:40.002" },
  "budgets":      { "version": 96,   "updated_at": "2026-10-23 19:42:05.731" },
  "insights":     { "version": 3,    "updated_at": "2026-10-12 10:00:00.000" },
  "settings":     { "version": 0,    "updated_at": null }
}
```

| Type | Incrémenté par |
|---|---|
| `transactions` | synchronisation (transaction nouvelle ou modifiée, catégorie trouvée), `POST /transaction/import`, `POST /transaction`, `PUT /transaction`, `PUT /transaction/category`, `PUT /transaction/categories`, `PUT /transaction/comment`, `PUT /transaction/date`, `PUT /transaction/pointed`, `PUT /transaction/split`, `PUT /transaction/tags`, `DELETE /tag`, `DELETE /transaction`, règles appliquées à l'historique, `DELETE /category` |
| `accounts` | synchronisation (compte en erreur, réparé ou autre erreur), `POST`/`PUT`/`DELETE /bank`, `/bank/backend` |
| `categories` | `POST`/`PUT`/`DELETE /category`, `/category/keyword`, `PUT /category/keyword/settings` |
| `budgets` | `POST /budget`, `POST /budget/copy`, `DELETE /category` |
| `insights` | `POST`/`PUT`/`DELETE /insight`, `PUT /insight/enabled`, `PUT /insight/order` |
| `settings` | `PUT /system/config`, `PUT /budget/trends/charts` |
| `tags` | `POST`/`PUT`/`DELETE /tag` |

`POST /backup/import` incrémente tous les types ; `POST /purge` tous sauf `accounts` et `settings`. Les insights et les tendances sont
calculés à partir des transactions et des budgets : un client les recharge quand l'un de
ces types bouge.

- **Version**, pas seulement une date : pas d'égalité entre deux écritures de la même
  seconde, pas de dépendance aux horloges. `updated_at` est donné pour information ; un
  type jamais modifié a la version `0`.
- Une synchronisation qui ne change rien (compte inchangé) n'incrémente rien : elle ne
  réveille pas les appareils.
- **Chaque réponse authentifiée** porte les versions de tous les types dans l'en-tête
  `X-Carbure-Changes` (`accounts=52, budgets=97, … transactions=1835`) : le client
  vérifie à chaque appel qu'il fait de toute façon si ce qu'il a chargé est à jour, sans
  appel périodique. `GET /changes` sert au retour au premier plan, quand aucun appel
  n'est en cours. Pas d'en-tête sur les routes publiques ni sur les flux.
- Une écriture nomme aussi les types qu'elle a incrémentés dans `X-Carbure-Changed`
  (`budgets`) : si la version connue était la précédente, le client qui a écrit est à
  jour sans rien recharger ; si elle a bougé de plus, quelqu'un d'autre a écrit
  entre-temps.
- Chaque route d'écriture déclare les types qu'elle modifie dans son attribut
  (`#[ApiRoute('/budget', method: 'POST', changes: ['budgets'])]`), incrémentés après un
  appel réussi ; un test unitaire vérifie que chaque route d'écriture les déclare.
- Un serveur plus ancien répond `404` : le client recharge alors à chaque retour au
  premier plan.

Ces marqueurs ne sont pas dans `GET /health`, qui est publique : ils diraient à n'importe
qui quand le foyer utilise l'application et quand la synchronisation tourne.

## Utilisateurs

### `POST /user/login` — publique

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `username` | string | oui | Identifiant |
| `password` | string | oui | Mot de passe |
| `device` | objet `{name, token, platform?, model?, os_version?, app_version?}` | non | Appareil mobile à enregistrer pour les notifications (voir [l'objet `device`](#objet-device)) |
| `mfa` | booléen | non | Le client gère la double authentification (`true` pour le portail) |

```bash
curl -X POST https://exemple.fr/api/user/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"alice","password":"…","device":{"name":"iPhone16,1","token":"<token APNs>","platform":"ios","model":"iPhone16,1","os_version":"iOS 26.0","app_version":"1.2"}}'
# Android : "device":{"name":"Google Pixel 8","token":"<token FCM>","platform":"android","model":"Google Pixel 8","os_version":"Android 15","app_version":"1.0"}
```

```json
{ "exp": 1762000000, "sub": 1, "iat": 1759400000, "token": "eyJ0eXAiOiJKV1Qi…" }
```

Le jeton est valable 30 jours. La date de connexion est enregistrée (`last_login`).

`401` si les identifiants sont faux ; `423` si le compte est bloqué : après 5 échecs
d'affilée, le compte est bloqué 24 heures, même avec le bon mot de passe (le 5e échec
répond déjà `423`). Une connexion
réussie remet le compteur à zéro.

**Double authentification** (voir [Sécurité](securite.md#double-authentification-totp)) :
quand elle est imposée (`mfa_required`) ou activée par l'utilisateur, et que ni l'appareil
(`device.token` déjà enregistré pour cet utilisateur) ni le navigateur (cookie de
confiance) ne sont connus, la réponse n'est pas la session mais un défi valable 5 minutes :

```json
{ "mfa": "verify", "mfa_token": "eyJzdWIiOjIs…", "exp": 1759400300 }
```

`mfa` vaut `enroll` quand l'utilisateur n'a pas encore d'application : la réponse ajoute
`secret` (base32) et `otpauth` (URI du QR code). Un client qui n'envoie pas `mfa=true`
reçoit `403` (message explicite) au lieu du défi ; le format de réponse ne change pas pour
un utilisateur sans double authentification. Un nouvel appareil n'est enregistré
qu'après le code.

#### Objet `device`

Envoyé par les applications mobiles (le portail n'en envoie pas) ; l'appareil est créé ou
mis à jour (utilisateur, nom, plateforme, modèle et versions) à chaque connexion.

| Champ | Type | Requis | Description |
|---|---|---|---|
| `name` | string | non | Nom de l'appareil |
| `token` | string | oui | Jeton push (APNs pour iOS, FCM pour Android) ; sans jeton, aucun appareil n'est enregistré |
| `platform` | string | non | `ios` ou `android` (casse et espaces ignorés ; `400` pour une autre valeur). Absent ou vide : déduit du jeton — 64 caractères hexadécimaux (APNs) = `ios`, tout autre jeton (FCM : long, avec `:`, `-`, `_`) = `android` |
| `model` | string | non | Modèle (`iPhone16,1`, `Google Pixel 8`), 100 caractères au plus |
| `os_version` | string | non | Version du système (`iOS 26.0`, `Android 15`), 50 caractères au plus |
| `app_version` | string | non | Version de l'application (`1.2`), 20 caractères au plus |

Les textes sont débarrassés des espaces autour et tronqués à leur longueur maximale ; un
champ absent ou vide est enregistré à `null`. Tant que la migration
`2026-10-25_device_model` n'est pas appliquée, `model`, `os_version` et `app_version` sont
ignorés (la connexion n'échoue pas).

iOS (le jeton APNs fait 64 caractères hexadécimaux) :

```json
{ "name": "iPhone16,1", "token": "a1b2c3…", "platform": "ios",
  "model": "iPhone16,1", "os_version": "iOS 26.0", "app_version": "1.2" }
```

Android (jeton FCM) :

```json
{ "name": "Google Pixel 8", "token": "fXk9…:APA91b…", "platform": "android",
  "model": "Google Pixel 8", "os_version": "Android 15", "app_version": "1.0" }
```

### `POST /user/login/mfa` — publique

Seconde étape de la connexion : échange le défi et le code contre la session.

| Paramètre | Type | Requis | Description |
|---|---|---|---|
| `mfa_token` | string | oui | Défi renvoyé par `POST /user/login` |
| `code` | string | oui | Code à 6 chiffres de l'application, ou code de secours (`xxxxx-xxxxx`) |
| `trust` | booléen | non | Faire confiance à ce navigateur 6 mois (cookie `HttpOnly`) |
| `device` | objet `{name, token, platform?, model?, os_version?, app_version?}` | non | Appareil mobile à enregistrer, comme pour `POST /user/login` (voir [l'objet `device`](#objet-device)) |

Réponse : la session, comme `POST /user/login` (`exp`, `sub`, `iat`, `token`), plus
`recovery_codes` (10 codes de secours, affichés une seule fois) quand l'application vient
d'être configurée. `401` si le code est faux (compté dans le blocage), `423` si le compte
est bloqué, `403` si le défi a expiré.

### `GET /user/mfa`, `POST /user/mfa/setup`, `POST /user/mfa/enable`, `POST /user/mfa/recovery-codes`, `DELETE /user/mfa`

Double authentification de l'utilisateur connecté (Mon profil) :

- `GET /user/mfa` : `available` (migration appliquée), `required` (imposée par
  l'administrateur), `enabled`, `pending`, `recovery_codes_left`, `trusted_browsers`
  (`id`, `label` = User-Agent, `created_at`, `expires_at`, `last_used_at`) ;
- `POST /user/mfa/setup` : nouveau secret à scanner (`secret`, `otpauth`), `409` si déjà
  activée ;
- `POST /user/mfa/enable` (`code`) : confirme l'application, renvoie `recovery_codes` ;
- `POST /user/mfa/recovery-codes` (`code`) : nouveaux codes de secours ;
- `DELETE /user/mfa` (`code` de l'application ou de secours, dans le corps) : désactive
  la double authentification et révoque les navigateurs de confiance.

Un code faux répond `400` (et compte comme un échec de connexion).

### `DELETE /user/mfa/browser?id=`

Révoque un navigateur de confiance de l'utilisateur connecté (`404` sinon).

### `POST /user/mfa/reset` — administrateur

`id` : supprime l'application, les codes de secours et les navigateurs de confiance d'un
utilisateur qui a perdu son téléphone ; il configurera une nouvelle application à sa
prochaine connexion si la double authentification est imposée.

### `POST /user/unlock` — administrateur

`id` : débloque un compte bloqué après trop d'échecs de connexion (`404` si l'utilisateur
n'existe pas).

### `GET /user/me`

Profil de l'utilisateur connecté : `id`, `username`, `firstname`, `lastname`, `email`,
`is_admin`, `language`, `theme` (thème du portail : `system`, `light` ou `dark`),
`alert_threshold` (`null` si les alertes sont désactivées).

```bash
curl https://exemple.fr/api/user/me -H "Authorization: Bearer $TOKEN"
```

### `GET /user`

Liste des utilisateurs pour un administrateur ; l'utilisateur lui-même sinon. Mêmes champs
que `GET /user/me`, plus `last_login` (dernière connexion réussie, `null` si jamais) et
`locked_until` (fin du blocage, `null` si le compte n'est pas bloqué).

### `POST /user` — administrateur

`username`, `password`, `email` (requis), `firstname`, `lastname`, `is_admin` (facultatifs ;
profil utilisateur par défaut). `400` si l'identifiant ou l'e-mail existe déjà.

### `PUT /user`

| Paramètre | Requis | Description |
|---|---|---|
| `id` | oui | Utilisateur à modifier (soi-même, ou n'importe qui pour un administrateur) |
| `email`, `firstname`, `lastname` | non | Nouvelles valeurs |
| `password` | non | Nouveau mot de passe (ignoré si vide) |
| `language` | non | `fr`, `en`, `es`, `it`, `de` ou `pt` (portugais du Portugal) ; `400` pour une autre valeur |
| `theme` | non | Thème du portail : `system` (suit la préférence clair/sombre du système, par défaut), `light` ou `dark` ; `400` pour une autre valeur |
| `alertThreshold` | non | Seuil d'alerte en euros pour les nouvelles dépenses ; `""` ou `0` désactive les alertes |
| `is_admin` | non | Profil administrateur (`true`) ou utilisateur (`false`) ; administrateurs seulement, `409` pour retirer le rôle au dernier administrateur |

```bash
curl -X PUT "https://exemple.fr/api/user?id=1" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"language":"en"}'
```

Renvoie l'utilisateur mis à jour.

### `GET /user/notifications`

Canaux de chaque type de notification de l'utilisateur connecté :

```json
{
  "preferences": {
    "sync": {"push": true, "email": false},
    "rule": {"push": true, "email": false},
    "expense": {"push": true, "email": true},
    "budget": {"push": false, "email": false}
  },
  "smtp": true,
  "address": "alice@example.com",
  "email_available": true,
  "push_available": true,
  "budget_threshold": 100,
  "alert_threshold": 200.0,
  "budget_all": true,
  "budget_thresholds": [{"category": 2, "threshold": 80}],
  "budget_category": null,
  "budgets": [{"id": 1, "name": "Alimentation"}, {"id": 2, "name": "Alimentation › Supermarché"}]
}
```

Types : `sync` (résultat d'une synchronisation), `rule` (nouvelle transaction d'une règle
`notify`), `expense` (dépense au-delà du seuil d'alerte), `budget` (dépenses du mois d'une
catégorie budgétée au-delà du seuil des budgets). Sans choix de l'utilisateur, un type est
envoyé en push et pas par email, sauf `budget`, désactivé sur tous les canaux (à activer).
`smtp` indique qu'un serveur SMTP est configuré, `address` l'adresse du profil (`null` si
aucune), `email_available` que les emails peuvent partir (les deux), `push_available`
qu'une clé d'API du service de notifications push est configurée (sinon aucune
notification push n'est envoyée) et `budget_threshold`
le seuil global des alertes de budget, en pourcentage du budget (100 par défaut), appliqué à
tous les budgets quand `budget_all` est vrai. `budget_thresholds` donne les seuils des
catégories qui ont le leur, à la place du seuil global. `alert_threshold`
est le montant d'une dépense importante (type `expense`, `null` : désactivé, le même que
`GET /user/me`), `budget_category`, pour les clients plus anciens, la seule catégorie de
`budget_thresholds` quand le seuil global est désactivé (`null` sinon), et `budgets` les
catégories de dépenses qui ont un budget ce mois-ci (une sous-catégorie nommée « Catégorie ›
Sous-catégorie »), plus celles de `budget_thresholds` qui n'en ont pas. Les notifications (push et email) sont
rédigées dans la langue du profil (`language`, `fr` par défaut).

### `PUT /user/notifications`

| Paramètre | Obligatoire | Description |
|---|---|---|
| `preferences` | non | `{type: {push?, email?}}`, chaque canal `true`/`false` (ou `"true"`, `"false"`, `1`, `0`) |
| `budgetThreshold` | non | Seuil des alertes de budget (type `budget`) en pourcentage du budget : entier de 50 à 200 (nombre ou chaîne) |
| `alertThreshold` | non | Montant d'une dépense importante (type `expense`) en euros ; `""` ou `0` désactive l'alerte |
| `budgetAll` | non | Le seuil global (`budgetThreshold`) s'applique à tous les budgets : `true`/`false` (ou `"true"`, `"false"`, `1`, `0`) |
| `budgetThresholds` | non | Seuils des catégories, qui remplacent les précédents : `[{category, threshold}]`, une catégorie de dépenses chacun (une seule fois), un seuil de 50 à 200 ; `[]` les supprime tous |
| `budgetCategory` | non | Clients plus anciens : seul budget surveillé (le seuil global désactivé, cette catégorie à `budgetThreshold` ou au seuil actuel) ; `0` ou `""` : tous les budgets, sans seuil de catégorie. Pas avec `budgetThresholds` |

Les types, canaux et réglages absents sont conservés ; type, canal, valeur ou réglage
invalides (dont une catégorie inconnue ou de revenus) : `400`, sans rien enregistrer ;
`budgetCategory` avant la migration `2026-10-23_user_budget_alert_category`, `budgetAll` ou
`budgetThresholds` avant la migration `2026-10-27_budget_alert_threshold` : `409`. Renvoie le résultat de `GET /user/notifications`.

```bash
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"preferences":{"expense":{"email":true}}}'

# Alertes de budget par email, dès 90 % du budget
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"preferences":{"budget":{"email":true}},"budgetThreshold":"90"}'

# 100 % sur tous les budgets, 80 % sur les catégories 2 et 5
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"budgetAll":true,"budgetThreshold":"100","budgetThresholds":[{"category":"2","threshold":"80"},{"category":"5","threshold":"80"}]}'

# Seulement les catégories 2 (80 %) et 4 (100 %), et les dépenses à partir de 150 €
curl -X PUT "https://exemple.fr/api/user/notifications" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"budgetAll":false,"budgetThresholds":[{"category":"2","threshold":"80"},{"category":"4","threshold":"100"}],"alertThreshold":"150"}'
```

### `DELETE /user` — administrateur

`id` : impossible de se supprimer soi-même (400) ou de supprimer un utilisateur
propriétaire de transactions (409).

## Comptes et synchronisation

Les comptes bancaires appartiennent au **foyer** : tous les utilisateurs voient tous les
comptes et leurs transactions. `user_id` d'un compte indique seulement l'utilisateur qui
l'a ajouté.

### `GET /bank`

Comptes bancaires du foyer, visibles par tous les utilisateurs (un compte n'apparaît
qu'une fois) ; `label` est le nom donné au compte (`null` s'il n'en a pas), `manual` indique
un compte manuel (jamais synchronisé, ses transactions saisies à la main) :

```json
[{ "id": 1, "bankId": "DEMO0000001@bnp", "account_number": "DEMO0000001", "bank_name": "bnp", "label": "Compte joint", "manual": false }]
```

### `GET /bank/accounts` — administrateur

Tous les comptes du foyer avec l'utilisateur qui les a ajoutés et le résultat de leur
dernière synchronisation : `id`, `bankId`, `account_number`, `bank_name`, `label`, `user_id` et
`username` (qui l'a ajouté), `last_sync_at`, `last_sync_status` (`OK`, `ERROR` ou `null`),
`last_sync_message` (nombre de nouvelles transactions ou erreur), `manual` (compte manuel).

### `POST /bank` — administrateur

Suit un compte pour le foyer. Paramètres : `account_number` (identifiant du compte dans
woob), `bank_name` (nom du backend woob) et `label` (facultatif : nom donné au compte,
100 caractères maximum) ; `user_id` du compte est l'administrateur qui l'ajoute. `409` si le foyer suit déjà ce compte, `400` si un identifiant est invalide.

### `POST /bank/manual` — administrateur

Ajoute un compte manuel : espèces, ou banque que woob ne gère pas. Il n'est jamais
synchronisé ; ses transactions se saisissent à la main (`POST /transaction`). Paramètres :
`label` (obligatoire, 100 caractères maximum) et `account_number` (facultatif, par exemple un
IBAN sans espaces ; généré s'il est vide). Son `bank_name` est `manual`, nom réservé : refusé
pour une connexion woob ou un autre compte (`400 bank_name_reserved`). `409` si le numéro est
déjà suivi.

### `PUT /bank` — administrateur

Modifie un compte suivi : `id`, `account_number`, `bank_name` et `label` (facultatif :
absent, le nom actuel est conservé ; vide, il est supprimé). `404` si inconnu, `409` si le
foyer suit déjà le compte visé.

### `DELETE /bank?id=` — administrateur

Ne suit plus le compte ; ses transactions déjà importées sont conservées.

### `GET /bank/backends`, `GET /bank/modules` — administrateur

Banques configurées dans woob (`name`, `module`, `label` : nom donné à la connexion, `null`
si aucun) et banques supportées par woob (`module`, `description`). Une banque doit être configurée dans woob (identifiants bancaires) avant
que ses comptes puissent être synchronisés : depuis le portail (routes ci-dessous).

### `GET /bank/module?module=` — administrateur

Paramètres demandés par un module woob : `module`, `description`, `fields` (`key`, `label`,
`description`, `default`, `required`, `masked`, `choices`). Rien de secret n'est renvoyé.
Un module pas encore installé l'est d'abord ; `409` si le dépôt de woob n'est pas encore
approuvé (l'installer ferait confiance à sa clé, voir `GET /bank/woob/update`), comme
pour `POST /bank/backend` ; `422` avec la cause donnée par woob s'il ne peut pas charger le
module (un paquet Python manquant : « woob cannot load the bank module … »).

### `POST /bank/backend` — administrateur

Configure une banque dans woob : `module`, `backend` (nom, `[a-z0-9_-]`, sans `-` au
début), `settings` (objet des paramètres du module ; seules ses clés sont transmises,
valeurs sur une ligne sans espace, choix vérifiés), `label` (facultatif : nom donné à la
connexion, 100 caractères max, par exemple « Compte joint »). Les identifiants sont
confiés à woob, ni stockés ni journalisés par Carbure (`settings` est masqué dans les
logs), et jamais passés sur la ligne de commande de woob. Renvoie
`backend`, `label`, les `accounts` trouvés (même format que `/bank/discover`) et
`two_factor`. Si la banque demande une authentification forte dès la première connexion
(woob ne la lance qu'en mode interactif), la banque reste configurée : `accounts` est vide,
`two_factor` vaut `true`, et ses comptes se listent avec `GET /bank/discover/interactive`.
`409` si une banque de ce nom existe déjà dans woob ou si son module n'est pas installé et
le dépôt de woob pas encore approuvé, `400` si un paramètre manque ou si woob refuse.

### `PUT /bank/backend` — administrateur

Renomme une connexion bancaire : `backend` (nom dans woob), `label` (nom donné à la
connexion ; vide le supprime). Le nom dans woob, qui identifie les comptes et les
transactions, ne change pas. Renvoie `name` et `label`. `404` si la banque n'est pas
configurée dans woob, `400` si le nom est invalide ou trop long.

### `DELETE /bank/backend` — administrateur

Supprime une connexion bancaire : `backend` (nom dans woob, dans la query string). woob
l'oublie avec ses identifiants (`woob config remove`), son nom donné et ses comptes suivis
sont supprimés ; les transactions déjà importées sont conservées. Renvoie `true`. `404` si
la banque n'est pas configurée dans woob, `400` si le nom est invalide, `500` si woob ne
l'a pas supprimée.

### `GET /bank/woob` — administrateur

Sur une installation neuve (woob jamais lancé), toutes les routes qui lancent woob
répondent `409` tant que la clé de son dépôt n'est pas approuvée (`GET /bank/woob/update`) :
au premier lancement, woob ferait confiance à cette clé de lui-même.

État du dépôt des modules bancaires de woob : `repository` (URL), `trusted` (clé déjà
approuvée), `fingerprint` (empreinte SHA-1 de la clé approuvée, sinon de celle que woob
demandera d'approuver, à comparer avec celle des mainteneurs de woob ; `null` si le dépôt
est injoignable) et `error` (cause, `null` sinon).

### `GET /bank/woob/update` — administrateur (flux SSE)

Met à jour les dépôts et les modules bancaires (`woob config update`). Paramètre
facultatif `fingerprint` (empreinte SHA-1 confirmée par l'administrateur, `400` si ce
n'est pas 40 caractères hexadécimaux). Tant que la clé du dépôt n'est pas approuvée,
woob (qui lui ferait confiance de lui-même) n'est lancé que si `fingerprint` est
l'empreinte de la clé que Carbure télécharge juste avant, et la clé conservée par woob
doit être celle-là (sinon elle est supprimée). Chaque ligne de woob est envoyée
(`data: <ligne>`), puis le résultat : `Woob updated`, `Woob repository not trusted:
<empreinte>` (woob non lancé : à comparer avant de réessayer avec elle) ou `Woob update
failed: <cause>` (dont `synchronization in progress` : jamais pendant une
synchronisation).

### `GET /bank/discover` — administrateur

Interroge woob (`woob bank list`) et renvoie les comptes des banques configurées :
`bankId`, `account_number`, `bank_name`, `label`, `balance`, `currency`, `followed`. Peut
prendre plusieurs secondes (connexion aux banques).

| Paramètre | Description |
|---|---|
| `backend` (facultatif) | Seulement les comptes de cette connexion (backend woob, `woob bank list -b <backend>`) ; `400` si le nom est invalide |

### `GET /bank/discover/interactive` — administrateur (flux SSE)

Comptes d'une connexion (`backend`, obligatoire, `400` si le nom est invalide) quand la
banque demande une authentification forte : woob est lancé sur un pseudo-terminal, déclenche
la demande et attend la validation dans l'application de la banque (parfois plusieurs
minutes). Chaque message de la banque est envoyé (`data: Bank message <backend>:
<message>`), puis le résultat : `Accounts <liste JSON>` (même format que `/bank/discover`)
ou `Discovery failed: <cause>` (dont `synchronization in progress` : jamais pendant une
synchronisation). Le portail l'utilise après `POST /bank/backend` avec `two_factor`, ou
quand `GET /bank/discover` échoue sur une demande d'authentification forte.

### `GET /bank/sync` — flux SSE { #bank-sync }

Lance la synchronisation de **tous** les comptes, ou d'un seul avec
`?account=<account_number>@<bank_name>` (`404` s'il n'est pas suivi). La date et le résultat
sont enregistrés pour chaque compte. Accès autorisé avec :

- un JWT valide (n'importe quel utilisateur), **ou**
- le `sync_token` de la configuration, dans l'en-tête `X-Sync-Token`.

!!! warning "Paramètre `?token=` obsolète"
    Le `sync_token` passé dans l'URL (`?token=`) est encore accepté pour les tâches planifiées
    déjà configurées, mais il est **obsolète** : l'URL est écrite dans les logs du proxy, de
    nginx et l'historique du shell. La réponse porte alors les en-têtes
    `Deprecation: @1792108800` (obsolète depuis le 16/10/2026, [RFC 9745](https://www.rfc-editor.org/rfc/rfc9745))
    et `Link: <https://carbure.org/api/#bank-sync>; rel="deprecation"`, et un avertissement
    (sans le jeton) est journalisé. Remplacez-le par l'en-tête `X-Sync-Token` ; s'ils sont
    présents tous les deux, l'en-tête l'emporte.


Sans JWT ni jeton valide (en-tête ou URL) : `401`. `?interactive=true` (avec `account`, pour un utilisateur connecté seulement,
`400` sinon) autorise woob à déclencher une authentification forte : woob est lancé sur un
pseudo-terminal (`proc_open` de PHP) et ses messages sont relayés en
`data: Bank message <compte>: <message>` pendant qu'il attend la validation dans
l'application de la banque. Sans ce paramètre, une banque qui exige une authentification
forte échoue avec `…interactive mode to perform a two-factor authentication`.

Les opérations à venir sont facultatives : si leur appel à woob échoue (hors
authentification forte), l'historique est quand même synchronisé, l'échec est envoyé en
`data: Coming failed <compte>: <erreur>` et le compte reste `OK`, avec le résultat
`<n> new (coming failed: <erreur>)`.

Pour le planificateur (`sync_token`, jamais en mode interactif), un compte dont la banque
demande une validation dans son application est resynchronisé après `woob_2fa_wait`
secondes (15 par défaut), jusqu'à `woob_2fa_retries` fois (1 par défaut) : la validation a
pu être faite entre-temps. Seul ce compte attend (`data: Waiting for a validation <compte>:
new attempt in 15 s (1/1)`, puis des `: heartbeat`) ; seul le résultat de la dernière
tentative est enregistré et notifié.

La réponse est un flux `text/event-stream` : messages `data: …` (progression,
résultat de chaque étape) et commentaires `: heartbeat` toutes les 15 s pendant
l'exécution de woob. Une seule synchronisation peut tourner à la fois (sinon :
`data: Synchronization already in progress`).

```bash
curl -N -H "X-Sync-Token: $SYNC_TOKEN" https://exemple.fr/api/bank/sync
```

```text
data: Syncing DEMO0000001@bnp (coming)...
data: Done DEMO0000001@bnp (coming): 3 received, 1 new
data: Syncing DEMO0000001@bnp (history)...
data: Done DEMO0000001@bnp (history): 100 received, 4 new
data: Notifying users of DEMO0000001@bnp...
data: Updating missing categories...
data: Checking the budgets...
data: Synchronization complete
```

Après chaque compte, **tous les utilisateurs** du foyer reçoivent une notification (succès
ou échec, nombre de transactions à vérifier), chaque **nouvelle** dépense (transaction
absente jusque-là) dont le montant atteint le seuil `alert_threshold` d'un utilisateur lui
envoie une notification « Dépense importante à vérifier », et chaque nouvelle transaction
d'une règle `notify` est signalée au foyer. Une fois les transactions catégorisées, les
**budgets du mois en cours** sont vérifiés (alerte `budget`, aussi après un import). Chaque
notification part dans la langue de son destinataire, sur les canaux (push, email) qu'il a
choisis (voir [`GET /user/notifications`](#get-usernotifications)).

## Transactions

### `GET /transaction`

| Paramètre | Requis | Description |
|---|---|---|
| `month`, `year` | non | Mois des transactions (mois courant par défaut) |
| `category` | non | Limite aux transactions de cette catégorie **et de ses sous-catégories** |
| `own` | non | Avec `category` : `1` limite aux transactions rangées sur la catégorie elle-même, sans ses sous-catégories |

Les transactions sont triées par date réelle décroissante. Champs : `id`, `uuid`,
`imported`, `date`, `rdate`, `type`, `label`, `category`, `amount`, `card`, `bank_name`
et `account_number` (compte d'origine ; `null` pour les transactions antérieures à leur
ajout), `pointed`, `user`, `comment` (commentaire libre du foyer, `null` sans commentaire ;
absent tant que la migration `2026-11-19_transaction_comment` n'est pas appliquée).

```bash
curl "https://exemple.fr/api/transaction?month=9&year=2026&category=1" -H "Authorization: Bearer $TOKEN"
```

Types : 1 virement, 2 prélèvement, 3 chèque, 4 remise de chèque, 5 remboursement,
6 retrait DAB, 7 facture carte, 8 dépense, 9 commissions, 12 carte en cours.

### `GET /transaction/periods`

Mois qui ont des transactions, du plus récent au plus ancien : `[{"year": 2026, "month": 10}, …]`.
Le portail en tire les listes de mois et d'années.

### `GET /transaction/recurring`

Transactions récurrentes d'un mois : retrouvées environ une fois par mois vers le même jour
(salaire, loyer, abonnements, factures). Une série regroupe les transactions de même sens
(débit ou crédit) dont le libellé, chiffres retirés (références, dates), est le même. Elle
est récurrente si elle apparaît dans 3 mois au moins de la période, 1,5 fois par mois au
plus, 3 fois sur 4 à 5 jours près du même jour, et encore active (vue dans le mois ou le
mois précédent). Un paiement par carte doit en plus garder le même montant à 20 % près
(un abonnement, pas le restaurant) ; un prélèvement ou un virement peut varier.

| Paramètre | Requis | Description |
|---|---|---|
| `month`, `year` | non | Mois (mois courant par défaut) |
| `months` | non | Nombre de mois précédents examinés (2 à 24, défaut 6) |

Réponse, triée par jour : `[{"label", "category", "amount" (dernier montant), "average",
"variable" (montant changeant de plus de 10 %), "day" (jour habituel), "months" (nombre de
mois où elle apparaît), "last" (date de la dernière), "ids" (ses transactions du mois),
"date" (date de sa première transaction du mois, `null` si aucune), "status"}]`. `status` vaut `received`, ou `expected` si elle n'est pas encore dans le mois.

```bash
curl "https://exemple.fr/api/transaction/recurring?month=10&year=2026" -H "Authorization: Bearer $TOKEN"
```

### `GET /transaction/search`

Transactions de tous les mois, les plus récentes d'abord. Chaque critère est facultatif, il en
faut au moins un (`400 search_too_short` sinon).

| Paramètre | Requis | Description |
|---|---|---|
| `query` | non | Texte recherché dans le libellé ou le commentaire (2 caractères minimum, insensible à la casse) ; `#nom` : les transactions du tag |
| `min`, `max` | non | Montant minimal et maximal, sans signe : `min=50` trouve une dépense de -50 comme un revenu de 50 (`400 search_amount_invalid` si négatif ou `min` > `max`) |
| `in` | non | `label` : le texte de `query` dans le libellé seulement, pas dans le commentaire (transactions similaires à une autre) |
| `kind` | non | `debit` : dépenses seules, `credit` : revenus seuls |
| `categories` | non | Ids de catégories séparés par des virgules ; une catégorie inclut ses sous-catégories |
| `accounts` | non | Comptes séparés par des virgules, sous la forme `bankId` de `GET /bank` (`numéro@banque`) |
| `from`, `to` | non | Premier et dernier jour, inclus (`YYYY-MM-DD` ; `400 search_date_invalid`, `400 search_period_invalid` si `from` > `to`) |
| `limit` | non | Nombre maximal de résultats (1 à 500, défaut 100) |

Les parts d'une transaction répartie sont cherchées comme les autres lignes : avec `categories`,
une part de la catégorie est trouvée sans sa transaction d'origine.

```bash
curl "https://exemple.fr/api/transaction/search?query=amazon" -H "Authorization: Bearer $TOKEN"
curl "https://exemple.fr/api/transaction/search?kind=debit&min=100&categories=1&from=2026-01-01" -H "Authorization: Bearer $TOKEN"
```

### `POST /transaction/import/preview`

Lit un relevé bancaire sans rien enregistrer.

| Paramètre | Requis | Description |
|---|---|---|
| `file` | oui | Contenu du fichier encodé en base64 (1 Mo au plus avant encodage) ; jamais écrit dans les logs |
| `filename` | non | Nom du fichier (son extension aide à reconnaître le format) |

Formats : OFX/QFX (1.x SGML et 2.x XML), QIF, CAMT.053 (ISO 20022) et CSV : lu avec le
**profil de sa banque** quand son en-tête est celui d'un export connu (voir
[Fonctionnalités](fonctionnalites.md#import-de-releves-bancaires)), sinon séparateur détecté
et colonnes devinées d'après l'en-tête (date ou date d'opération, libellé, montant ou débit
et crédit). L'ordre jour/mois des dates est déduit du fichier ; le type d'opération,
du libellé (`CB`, `PRLV`, `VIR`, `RETRAIT`…) ou à défaut du fichier.

Renvoie `format` (`ofx`, `qif`, `camt`, `csv`), `bank` (banque dont le profil a lu un CSV :
`id` et `name`, par exemple `{"id": "boursorama", "name": "Boursorama / BoursoBank"}` ;
`null` pour un CSV aux colonnes devinées ou un autre format), `account` (numéro de compte ou IBAN lu dans
le fichier, sinon `null`), `from`, `to`, `counts` (`new`, `known`, `duplicate`) et `rows` :
`index`, `date`, `rdate`, `amount`, `label` (nettoyé comme à la synchronisation), `type`,
`status` et, pour un doublon probable, `match` (`id`, `date`, `label` de la transaction
existante).

| Statut | Signification |
|---|---|
| `new` | Nouvelle transaction |
| `known` | Même UUID qu'une transaction existante : elle serait fusionnée. Des lignes identiques du fichier sont autant de transactions (chacune son UUID) |
| `duplicate` | Même montant qu'une transaction existante à 3 jours près (date ou date réelle) : probablement la même, synchronisée avec un autre libellé |

Erreurs : `400` (base64 invalide, fichier vide), `413` (fichier trop gros, plus de 5 000
transactions), `422` (format non reconnu, colonnes CSV introuvables, XML invalide).

### `POST /transaction/import`

Mêmes paramètres, plus `selected` (facultatif) : les `index` des lignes à importer ; par
défaut, les lignes `new`. `account` (facultatif) : l'`id` d'un compte de `GET /bank`, auquel
les transactions sont rattachées (404 `account_not_found` s'il n'existe pas) ; par défaut,
le numéro de compte lu dans le fichier. Les lignes `known` ne sont jamais importées. Les transactions
appartiennent à l'utilisateur connecté, puis les règles de catégorisation s'appliquent
quelle que soit leur date. Renvoie `imported`, `categorized`, `from` et `to` (période des
lignes choisies).

```bash
curl -X POST https://exemple.fr/api/transaction/import -H "Authorization: Bearer $TOKEN" \
     -H "Content-Type: application/json" \
     -d "{\"file\": \"$(base64 -w0 releve.ofx)\", \"filename\": \"releve.ofx\"}"
```

### `PUT /transaction/category`

`id`, `category` : affecte la catégorie et pointe la transaction (`autoPointed=false`
pour ne pas la pointer). La catégorie est choisie à la main (`category_source` `manual`) :
les règles ne la changent plus.

### `PUT /transaction/categories`

`ids` (1 à 500 transactions), `category` : affecte la même catégorie à toutes, en une fois
(toutes ou aucune ; `400 tag_selection_limit`, `404 category_not_found`), et les pointe avec
les parts des transactions réparties (`autoPointed=false` pour ne pas les pointer). Comme
pour une seule, la catégorie est choisie à la main (`category_source` `manual`). Renvoie
`{updated}` : le nombre de transactions dont la catégorie a changé.

```bash
curl -X PUT https://exemple.fr/api/transaction/categories -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"ids":[42,43],"category":5}'
```

### `PUT /transaction/pointed`

`id`, `pointed` (booléen, défaut `true`) : marque la transaction comme vérifiée ou non.

```bash
curl -X PUT https://exemple.fr/api/transaction/pointed -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"id":42,"pointed":false}'
```

### `PUT /transaction/comment`

`id`, `comment` : commentaire libre de la transaction (synchronisée, importée, saisie à la
main ou part d'une transaction répartie), visible par tout le foyer. 500 caractères au plus
(`400 transaction_comment_length`, `params.max`), espaces de début et de fin retirés ; `""`
(ou `comment` absent) le supprime. La synchronisation et l'import ne le modifient jamais ;
les parts d'une transaction répartie ont chacune le leur. Renvoie la transaction de l'`id`.
`404 transaction_not_found` ; `409 transaction_comment_migration` si la migration
`2026-11-19_transaction_comment` n'est pas encore appliquée.

```bash
curl -X PUT https://exemple.fr/api/transaction/comment -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"id":42,"comment":"Remboursé par Paul en juin"}'
```

### `PUT /transaction/date`

`id`, `date` (`AAAA-MM-JJ`) : change la date à laquelle la transaction est comptée (son
mois, pour les totaux et les budgets), synchronisée ou non. La date réelle de la banque
(`rdate`) et l'UUID ne changent pas : la synchronisation et l'import la reconnaissent et
gardent la date choisie. Les parts d'une transaction répartie suivent sa date (l'`id` d'une
part déplace sa transaction avec toutes ses parts). Renvoie la transaction de l'`id`.
`400 transaction_date_invalid`, `404 transaction_not_found`.

```bash
curl -X PUT https://exemple.fr/api/transaction/date -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"id":42,"date":"2026-09-30"}'
```

### `POST /transaction`

Saisit une transaction à la main sur un compte du foyer, manuel ou synchronisé : `account`
(`id` du compte, voir `GET /bank`), `date` (`AAAA-MM-JJ`), `label`, `amount` (négatif pour
une dépense), `category` (facultative : sinon celle des règles, sinon aucune) et `comment`
(facultatif, voir `PUT /transaction/comment` ; vérifié avant l'enregistrement : refusé, rien
n'est saisi). Elle est
pointée, de type `8` (dépense), avec le `bank_name` et l'`account_number` du compte ; son
`uuid` commence par `manual` (jamais celui d'une banque), ce qui la distingue d'une
transaction synchronisée : la synchronisation ne l'écrase pas, mais si la banque envoie plus
tard la même transaction, elle s'ajoute en doublon. Renvoie la transaction. Erreurs `400` :
`transaction_date_invalid`, `transaction_label_invalid`, `transaction_amount_invalid`,
`transaction_comment_length` ; `404` si le compte ou la catégorie n'existe pas ;
`409 transaction_comment_migration` pour un commentaire avant la migration
`2026-11-19_transaction_comment`.

```bash
curl -X POST https://exemple.fr/api/transaction -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"account":7,"date":"2026-10-27","label":"Marché","amount":-12.5}'
```

### `PUT /transaction`

Modifie une transaction saisie à la main (sur un compte manuel, ou `uuid` commençant par
`manual`) : `id` et les valeurs à changer parmi `date`,
`label`, `amount` et `comment` (`""` le supprime ; sans `comment`, applications mobiles, le
commentaire existant est conservé). `400 transaction_not_manual` pour une transaction d'une banque ou une
part de répartition, `400 transaction_split_amount` pour le montant d'une transaction
répartie (annuler d'abord la répartition). Renvoie la transaction.

### `PUT /transaction/split`

`id`, `parts` (`[{category, amount}]`) : répartit la transaction sur plusieurs catégories.
Chaque part devient une transaction (même date, libellé et compte, `split_of` = l'`id` de
la transaction) ; la transaction garde le reste du montant de la banque. Les parts envoyées
remplacent les précédentes, `parts: []` annule la répartition. Renvoie la transaction puis
ses parts. Erreurs `400` : une part ne se répartit pas (`split_of_part`), montants du signe
de la transaction (`split_sign`), un reste doit subsister (`split_total`), 10 parts au plus
(`split_limit`) ; `404` si la transaction ou une catégorie n'existe pas.

```bash
curl -X PUT https://exemple.fr/api/transaction/split -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"id":42,"parts":[{"category":3,"amount":-20}]}'
```

Les transactions renvoyées par `GET /transaction` portent `split_of` (`null` hors part)
et `category_source`, l'origine de leur catégorie : `rule` (une règle), `manual` (choisie
à la main : portail, applications mobiles, répartition, saisie à la main) ou `null`
(inconnue : catégorie posée avant la migration `2026-11-02_category_source`, ou aucune).

### `DELETE /transaction`

`id` : supprime la transaction (`404` si elle n'existe pas). Son `uuid` est mémorisé
(table `bank_transaction_deleted`) : la synchronisation de son compte et l'import d'un
relevé l'ignorent, elle ne revient pas (dans l'aperçu d'un import, la ligne est `known`
avec `deleted: true`). Une transaction répartie est supprimée avec ses parts ; une part
supprimée rend son montant à sa transaction.

```bash
curl -X DELETE "https://exemple.fr/api/transaction?id=42" -H "Authorization: Bearer $TOKEN"
```

## Budgets et tendances

### `GET /budget`

`month`, `year`, `category` (catégorie parente, `0` = racine). Pour chaque catégorie :
`id`, `name`, `type`, `icon`, `color`, `budget`, `note` (note du budget du mois, `null` sans
note), `consummed` (transactions de la catégorie et de ses
sous-catégories, dans le sens de son type : dépense nette d'une catégorie `DEBIT`, revenu
net d'une catégorie `CREDIT`, jamais négatif — un remboursement plus grand que les dépenses
du mois donne 0 ; valeur absolue pour `HORS-BUDGET`), `progress` (%), `children`
(nombre de sous-catégories), `children_budget` (somme de leurs budgets) et `budget_mode` :
`own` (montant défini pour la catégorie) ou `children` (catégorie avec sous-catégories et
sans montant défini : `budget` est alors la somme des budgets des sous-catégories).

Avec `own=1` et une catégorie parente, la liste ajoute un élément pour les transactions
rangées sur la catégorie parente elle-même plutôt que sur une sous-catégorie : `own: true`,
`id` de la parente, sans budget (`budget` à 0), avec ce qu'elles consomment. Il n'apparaît
que si le mois en a ; sans `own`, la réponse est inchangée.

### `GET /budget/flow`

`month`, `year` (mois courant par défaut) : flux d'argent du mois, pour le diagramme
« Flux du mois ». Les montants sont regroupés par catégorie de premier niveau ; les
catégories `HORS-BUDGET` (virements internes) sont exclues, sauf la catégorie d'épargne
(`savings_category`) ; les transactions non catégorisées sont conservées.

| Champ | Description |
|---|---|
| `month` | `AAAA-MM` |
| `income`, `expenses` | Revenus et dépenses par catégorie de premier niveau : `id`, `name`, `color`, `icon`, `amount` (positif), du plus grand au plus petit |
| `savings` | Montant net versé sur la catégorie d'épargne et ses sous-catégories |
| `totalIncome`, `totalExpenses` | Totaux des revenus et des dépenses |
| `balance` | Reste : revenus − dépenses − épargne (si elle est positive) ; négatif en cas de déficit |

### `GET /budget/flow/transactions`

Transactions d'un nœud du diagramme de flux, de la plus récente à la plus ancienne, avec
le même classement que `GET /budget/flow`.

| Paramètre | Requis | Description |
|---|---|---|
| `kind` | oui | `income` (revenus), `expense` (dépenses) ou `savings` (épargne) |
| `categories` | non | Identifiants des catégories de premier niveau, séparés par des virgules (`0` = non catégorisées) ; toutes par défaut. Ignoré pour `savings` |
| `month`, `year` | non | Mois courant par défaut |

Renvoie les colonnes de `bank_transaction` et `top` (catégorie de premier niveau).

### `POST /budget`

`category`, `amount` (requis), `month`, `year` (mois courant par défaut) : crée ou met à
jour le budget de la catégorie pour le mois. Pour une catégorie avec sous-catégories, le
montant remplace la somme de leurs budgets ; `0` revient à cette somme. `note` (facultatif) :
pense-bête de ce que couvre le budget, 200 caractères au plus (`400`,
`budget_note_length` au-delà), espaces de début et de fin retirés ; `""` la supprime, et
sans `note` (applications mobiles) la note existante est conservée.

### `GET /budget/copy`

`month`, `year` (mois cible, mois courant par défaut) : indique si la recopie du mois
précédent est proposée, en lecture seule. Renvoie `month`, `source` (mois précédent,
`AAAA-MM`), `defined` (le mois cible a au moins un budget), `source_defined` (le mois
précédent en a un) et `copyable` (`true` quand le mois cible n'a aucun budget et que le
mois précédent en a). Un budget à `0` (somme des sous-catégories) ne compte pas comme
défini. Le portail n'affiche le bouton de recopie que si `copyable` vaut `true`.

### `POST /budget/copy`

`month`, `year` (mois cible, mois courant par défaut), `overwrite` (`false` par défaut) :
recopie les budgets du mois précédent vers le mois cible. Sans `overwrite`, seules les
catégories sans budget dans le mois cible sont recopiées ; avec `overwrite`, tous les
budgets du mois précédent remplacent ceux du mois cible. Les notes sont recopiées avec leur
budget. Renvoie `month`, `source` (mois
précédent, `AAAA-MM`), `copied` (budgets écrits) et `overwrite`.

### `GET /budget/trends`

| Paramètre | Requis | Description |
|---|---|---|
| `months` | non | Nombre de mois (1 à 240, défaut 24) |
| `offset` | non | Nombre de mois entre le mois courant et le dernier mois de la période (0 à 240, défaut 0). Sert aux comparaisons : `offset=months` pour la période précédente, `offset=12` pour la même période l'an dernier |

Renvoie un élément par mois (du plus ancien au plus récent, mois vides inclus) :

| Champ | Description |
|---|---|
| `month` | `AAAA-MM` |
| `debit` | Dépenses du mois, catégories hors budget exclues (montant positif) |
| `credit` | Revenus du mois, catégories hors budget exclues |
| `offBudget` | Solde des catégories `HORS-BUDGET` (ex. virements vers l'épargne) |
| `planned` | Somme des budgets des catégories de premier niveau (montant défini, ou somme des sous-catégories) |
| `savings` | Montant net versé sur la catégorie d'épargne (`savings_category`, `Epargne` par défaut) et ses sous-catégories, positif quand on épargne |

```bash
curl "https://exemple.fr/api/budget/trends?months=6&offset=12" -H "Authorization: Bearer $TOKEN"
```

```json
[{ "month": "2025-05", "debit": 2130.37, "credit": 5114, "offBudget": -500, "planned": 2330, "savings": 2983.63 }]
```

### `GET /budget/trends/series`

Évolution mensuelle d'une catégorie ou d'un insight, pour les graphiques personnalisés de
l'onglet Tendances.

| Paramètre | Requis | Description |
|---|---|---|
| `type` | oui | `category` ou `insight` |
| `id` | oui | Identifiant de la catégorie ou de l'insight (entier positif) |
| `months` | non | Nombre de mois (1 à 240, défaut 24) |
| `offset` | non | Nombre de mois entre le mois courant et le dernier mois de la période (0 à 240, défaut 0) |

Renvoie un élément par mois (du plus ancien au plus récent, mois vides à `0`) :

| Champ | Description |
|---|---|
| `month` | `AAAA-MM` |
| `amount` | Catégorie : total de ses transactions et de celles de ses sous-catégories, positif pour une dépense d'une catégorie `DEBIT` (un remboursement le diminue), brut pour les autres types. Insight : valeur de sa requête pour le mois (`0` en cas d'échec) |
| `planned` | Catégorie : budget du mois comme dans l'onglet Budget (montant défini, ou somme des sous-catégories), `null` sans budget. Insight : toujours `null` |

`400` pour un type ou un identifiant invalide, `404` pour une catégorie ou un insight inconnu.

```bash
curl "https://exemple.fr/api/budget/trends/series?type=category&id=1&months=36" -H "Authorization: Bearer $TOKEN"
```

```json
[{ "month": "2026-09", "amount": 412.5, "planned": 450 }, { "month": "2026-10", "amount": 127.4, "planned": null }]
```

### `GET /budget/trends/categories`

Évolution mensuelle de toutes les catégories d'un type, pour les comparer sur un même
graphique (carte « Par catégorie » de l'onglet Tendances).

| Paramètre | Requis | Description |
|---|---|---|
| `type` | non | `DEBIT` (défaut), `CREDIT` ou `HORS-BUDGET` |
| `level` | non | `top` (défaut) : catégories de premier niveau, sous-catégories comptées dans leur parent (le type est celui du parent) ; `sub` : chaque catégorie séparément |
| `months` | non | Nombre de mois (1 à 240, défaut 24) |
| `offset` | non | Nombre de mois entre le mois courant et le dernier mois de la période (0 à 240, défaut 0) |

Renvoie `months` (les mois `AAAA-MM` de la période, du plus ancien au plus récent) et
`categories` : `id`, `name`, `icon`, `color`, `parent_category`, `total` de la période et
`amounts` (un montant par mois de `months`, mois vides à `0`). Les montants sont positifs
pour les dépenses d'une catégorie `DEBIT` (un remboursement les diminue), bruts pour les
autres types. Seules les catégories ayant un montant sur la période sont renvoyées, de la
plus grosse à la plus petite ; la catégorie `0` (non catégorisé) est exclue.

`400` pour un type ou un niveau inconnu.

```bash
curl "https://exemple.fr/api/budget/trends/categories?type=DEBIT&level=top&months=3" -H "Authorization: Bearer $TOKEN"
```

```json
{
  "months": ["2026-08", "2026-09", "2026-10"],
  "categories": [
    { "id": 4, "name": "Logement", "icon": "home", "color": "blue", "parent_category": 0, "total": 2850, "amounts": [950, 950, 950] },
    { "id": 1, "name": "Alimentation", "icon": "restaurant", "color": "green", "parent_category": 0, "total": 247.4, "amounts": [0, 120, 127.4] }
  ]
}
```

### `GET /budget/trends/charts`

Graphiques personnalisés de l'onglet Tendances, partagés par le foyer, dans l'ordre
d'affichage : `type`, `id`, `name`, `icon`, `color` de la catégorie ou de l'insight. Les
graphiques dont la catégorie ou l'insight a été supprimé sont ignorés.

### `PUT /budget/trends/charts` (administrateur)

Remplace la liste. Corps : `{"charts": [{"type": "category", "id": 1}, {"type": "insight", "id": 2}]}`
(12 graphiques au plus, sans doublon, catégories et insights existants ; sinon `400`). Renvoie
la liste enregistrée, comme `GET`. Elle est stockée dans le réglage `trend_charts`
(ex. `["c1","i2"]`), sans migration.

## Tags

Tags d'un projet limités dans le temps, indépendants des catégories ; tout utilisateur les
gère. Un tag : `id`, `name` (sans le `#`), `color`, `start_date`, `end_date` (`null` : en
cours), `active` (sa fin n'est pas passée).

### `GET /tag`

Tous les tags, les actifs d'abord, avec `count` (nombre de transactions) et `total` (net).

### `POST /tag`, `PUT /tag`, `DELETE /tag`

`name` (1 à 40 lettres, chiffres, `-` ou `_` ; un `#` en tête est retiré, mis en minuscules),
`color`, `start_date` (aujourd'hui par défaut à la création), `end_date` (facultative) ;
`id` pour `PUT` et `DELETE`. Erreurs : `tag_name_invalid`, `tag_date_invalid`,
`tag_period_invalid` (`400`), `tag_exists` (`409`), `tag_not_found` (`404`). Supprimer un tag
le retire de ses transactions, sans les changer.

### `GET /tag/summary`

`id` : bilan du tag : `tag`, `total` (net), `count`, `categories` (`[{category, total,
count}]`, plus grosses dépenses d'abord), `transactions` (les plus récentes d'abord),
`days` (`[{date, total, cumulative}]`, du plus ancien au plus récent).

### `PUT /transaction/tags`

`ids` (1 à 500 transactions), `add` et `remove` (ids de tags) : ajoute ou retire des tags,
en une fois (`400 tag_selection_limit`). Renvoie `{updated}`. Les transactions renvoyées par
`GET /transaction` et `GET /transaction/search` portent `tags` (ids) ; une recherche
`#nom` renvoie les transactions du tag.

## Insights

### `GET /budget/insights`

`month`, `year` : valeur de chaque insight **affiché** pour le mois (`id`, `name`, `color`,
`icon`, `amount`). Une requête en échec donne `0` sans bloquer les autres ; les insights
masqués ne sont ni calculés ni renvoyés.

### `GET /budget/insights/history`

`year` : pour chaque insight affiché, la liste `history` des montants mois par mois.

### `GET /insight/disabled`

Les insights masqués, proposés par le bouton **+** de l'écran Insights :
`[{"id", "name", "color", "icon"}]` (liste vide avant la migration
`2026-11-01_insight_enabled`).

### `PUT /insight/enabled`

`id`, `enabled` (booléen, `true` par défaut) : affiche ou masque un insight pour tout le
foyer (tout utilisateur). Renvoie `{"id", "enabled"}` ; `404` (`insight_not_found`) si
l'insight est inconnu.

### `PUT /insight/order`

`ids` (liste d'identifiants d'insights) : ordre des cartes de l'écran Insights pour tout le
foyer (tout utilisateur), choisi par glisser-déposer dans le portail. Les insights listés
prennent les places 1, 2, 3… dans cet ordre ; les autres (masqués, ou créés entre-temps)
passent après eux, dans leur ordre. Renvoie les identifiants des insights affichés, dans
leur ordre. `400` (`insight_order_invalid`) si un identifiant n'est pas un entier positif
ou est répété, `404` (`insight_not_found`) si un insight est inconnu, `409`
(`insight_migration_pending`) avant la migration `2026-11-07_insight_position`.
`GET /budget/insights`, `GET /insight` et `GET /insight/disabled` suivent cet ordre (par
identifiant tant qu'aucun ordre n'est choisi) ; un nouvel insight se place à la fin.

### `GET /insight/alert`

Les alertes de l'utilisateur sur les insights affichés : `[{"insight", "direction",
"threshold"}]` (liste vide avant la migration `2026-11-03_insight_alert`).

### `PUT /insight/alert`

`insight`, `direction` (`above` ou `below`), `threshold` (montant positif, comparé à la
valeur du mois sans son signe) : pose ou remplace l'alerte de l'utilisateur sur un insight
affiché, réarmée pour le mois en cours. Renvoie `{"insight", "direction", "threshold"}`.
Erreurs : `404` `insight_not_found`, `400` `insight_alert_disabled` (insight masqué),
`insight_alert_direction_invalid`, `insight_alert_threshold_invalid`, `409`
`insight_migration_pending` (migration non appliquée).

L'alerte part avec les alertes de budget (après une synchronisation ou un import touchant
le mois en cours), une fois par mois, sur les canaux du type de notification `insight`
(`PUT /user/notifications`, désactivé par défaut). Masquer ou supprimer l'insight supprime
ses alertes.

### `DELETE /insight/alert?insight=`

Retire l'alerte de l'utilisateur sur un insight ; `404` (`insight_alert_not_found`) s'il
n'en a pas.

### `GET /insight`, `POST /insight`, `PUT /insight`, `DELETE /insight?id=` — administrateur

Gestion des insights, avec leur requête SQL (`GET` renvoie aussi `enabled`) :

| Paramètre | Requis | Description |
|---|---|---|
| `id` | `PUT`, `DELETE` | Insight à modifier ou supprimer (`404` si inconnu) |
| `name` | oui | 1 à 20 caractères |
| `color` | oui | `red`, `orange`, `amber`, `lime`, `green`, `teal`, `cyan`, `blue`, `indigo`, `purple`, `pink`, `brown` ou `gray` |
| `sql` | oui | Requête `SELECT … AS amount` (500 caractères au plus) ; `{month}` et `{year}` sont remplacés par le mois demandé |
| `icon` | non | Icône Material (`[a-z0-9_]`) ; choisie d'après le nom si vide |

La requête doit être un unique `SELECT` renvoyant une colonne `amount`, sans `;` ni
commentaire ni `\`, sans mot-clé d'écriture, d'administration, de fichier ou de
temporisation, et ne lit que les tables du budget (`bank_transaction`,
`bank_transaction_category`, `bank_transaction_category_keyword`, `budget`,
`bank_account`, `bank_backend`, sans nom de base). Elle est testée sur le mois courant avant
l'enregistrement (`400` avec la cause sinon) et exécutée dans une transaction en lecture
seule, en 5 secondes au plus (au-delà : `400` à l'enregistrement, `0` dans les listes). `POST` et `PUT` renvoient l'insight avec
le montant du mois courant.

### `POST /insight/check` — administrateur

`sql`, `month`, `year` (mois courant par défaut) : vérifie une requête sans l'enregistrer
(mêmes contrôles), pendant la saisie. Renvoie `{"valid": true, "amount": …}` ou
`{"valid": false, "error": "…"}` (toujours `200`).

## Catégories et règles

### `GET /category`

Toutes les catégories : `id`, `name`, `parent_category`, `type` (`DEBIT`, `CREDIT`,
`HORS-BUDGET`), `icon`, `color`.

### `POST /category`, `PUT /category`, `DELETE /category?id=` — administrateur

Crée ou modifie une catégorie (`name`, `type` : `DEBIT`, `CREDIT` ou `HORS-BUDGET`,
`parent_category`, `icon`, `color` ; `id` pour la modification) ou la supprime. `409` si le
nom existe déjà ou si la catégorie à supprimer a des sous-catégories, `400` pour la catégorie
par défaut (0) ou un parent qui n'est pas une catégorie principale. À la suppression, les
transactions passent en catégorie 0 et les budgets et règles de la catégorie sont supprimés.

### `GET /category/keyword`

Règles de catégorisation automatique : `id`, `keyword`, `category`, `category_name`,
`notify`, `amount` (montant en valeur absolue, `null` : tous les montants).

### `POST /category/keyword` — administrateur

`keyword` (1 à 60 caractères, enregistré en majuscules), `category` : ajoute une règle.
`amount` (facultatif, `"10"`, `"10,50"`, `-10`…) : la règle ne reconnaît que les transactions
de ce montant en valeur absolue, au centime près (une dépense de -10,00 comme un revenu
de 10,00), et l'emporte sur les règles sans montant ; vide ou absent : tous les montants.
La réponse contient `amount` (`"10.00"` ou `null`).
Erreurs : 400 mot-clé vide ou montant invalide (`rule_amount_invalid`), 404 catégorie
inconnue, 409 mot-clé déjà existant sans montant (`keyword_exists`) ou avec ce montant
(`rule_amount_exists`).

```bash
curl -X POST https://exemple.fr/api/category/keyword -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"keyword":"decathlon","category":9}'
```

### `PUT /category/keyword` — administrateur

`id`, `keyword`, `category`, `notify`, `amount` : modifie une règle (`409` si une règle a
déjà ce mot-clé et ce montant). `amount` absent ou `null` : le montant de la règle est
conservé (les applications qui ne l'envoient pas ne l'effacent pas) ; `""` : tous les montants.
`POST /category/keyword` accepte aussi `notify` : chaque nouvelle transaction synchronisée
qui contient le mot-clé envoie alors une notification à tous les utilisateurs du foyer.

### `GET /category/keyword/count?id=` — administrateur

Nombre de transactions reconnues par la règle (`matching` : libellé contenant le mot-clé,
de son montant si elle en a un, sans celles d'une règle avec un montant sinon), dont celles
déjà dans la catégorie de la règle (`categorized`).

### `DELETE /category/keyword` — administrateur

`id` : supprime une règle (404 si inconnue).

### `POST /category/keyword/apply` — administrateur

Applique toutes les règles à toutes les transactions de la base, déjà catégorisées
comprises, sauf celles dont la catégorie a été choisie à la main (`category_source`
`manual`) ; la première règle trouvée dans le libellé l'emporte. Renvoie
`{ "updated": <nombre de transactions dont la catégorie a changé> }`. Créer ou modifier une
règle (`POST`/`PUT /category/keyword`) l'applique aussi immédiatement à tout l'historique et
renvoie `applied`, le nombre de transactions recatégorisées. Avec le réglage
`keep_categorized` (ci-dessous), ces trois routes ne touchent que les transactions non
catégorisées ou catégorisées par une règle (`category_source` `rule`).

### `GET /category/keyword/settings`

`{ "keep_categorized": false }` : `true` si les règles laissent intactes les transactions
déjà catégorisées de l'historique (désactivé par défaut).

### `PUT /category/keyword/settings` — administrateur

`keep_categorized` (`true`/`false`) : active ou désactive le réglage, renvoie le nouvel état.
Activé, la resynchronisation et la création ou la modification d'une règle ne catégorisent
que les transactions sans catégorie ou catégorisées par une règle ; les catégories d'origine
inconnue sont alors conservées aussi.

## Appareils

### `GET /device`

Appareils de l'utilisateur connecté : `id`, `user_id`, `name`, `token`, `platform` (`ios` ou
`android` ; `ios` tant que la migration `2026-10-23_device_platform` n'est pas appliquée),
`lastLogin`, `model`, `os_version`, `app_version` (`null` si l'application ne les a pas
envoyés ou tant que la migration `2026-10-25_device_model` n'est pas appliquée). `token`
vaut `null` quand l'application s'est connectée sans jeton de notification (notifications
indisponibles dans l'app, refusées, ou jeton pas encore reçu) : l'appareil est listé, une
fois par nom et plateforme, mais aucune notification ne l'atteint ; il est remplacé par
l'appareil avec son jeton dès que l'app en envoie un (migration
`2026-10-28_device_without_token` ; avant, il n'est pas listé). Chaque connexion sans jeton
est journalisée.

### `POST /device`

Enregistre le jeton de notification de l'application mobile connectée, reçu après sa
connexion ou changé depuis (FCM) : `token` (requis, `400` sinon), `name`, `platform` (`ios`
ou `android` ; vide : celle du jeton, `400` si inconnue), `model`, `os_version`,
`app_version`, comme l'objet `device` de la connexion. L'appareil est celui de
l'utilisateur connecté ; il remplace l'appareil listé sans jeton du même nom et de la même
plateforme. Renvoie l'appareil enregistré (`id`, `user_id`, `name`, `token`, `platform`,
`model`, `os_version`, `app_version`).

### `GET /device/link`

Adresse de l'API donnée aux applications mobiles par le QR code de **Mes appareils** :
`{ "server": "https://carbure.exemple.fr/api/" }`, soit `public_url` suivi de `/api/`, ou
`null` sans ce paramètre (le portail utilise alors sa propre adresse).

### `DELETE /device`

`id` : supprime un appareil de l'utilisateur connecté (il ne recevra plus de
notifications). 404 si l'appareil n'existe pas ou appartient à un autre utilisateur.

### `GET /user/browser`, `DELETE /user/browser?id=`

Navigateurs web depuis lesquels l'utilisateur connecté s'est connecté au portail (une
connexion sans objet `device`), le dernier utilisé en premier : `id`, `label` (User-Agent),
`created_at` (première connexion), `last_login`, `current` (`true` pour le navigateur de la
requête). Un cookie HttpOnly `carbure_browser` (envoyé aux seules routes `/user`) reconnaît
le navigateur, la base n'en garde que l'empreinte SHA-256 ; un navigateur inutilisé depuis
6 mois est oublié. Ce n'est pas un appareil : il ne reçoit pas de notification et ne
dispense pas de la double authentification. `DELETE` l'oublie (`404` s'il n'existe pas ou
appartient à un autre utilisateur) sans fermer sa session : il réapparaît à sa prochaine
connexion. Liste vide tant que la migration `2026-10-26_user_browser` n'est pas appliquée.

### `POST /device/push`

Envoie une notification de test à tous les appareils de l'utilisateur connecté, par le
service Carbure Push. Renvoie le résultat de chaque token : `success`, `http_code`,
`status` (`sent`, `unregistered`, `invalid_token`, `forbidden`, `device_limit`, `error`),
`response` et, s'il n'est pas envoyé, `error` (sans clé d'API : `Push service not
configured (push_api_key)`). Un appareil `unregistered` (application supprimée, token
expiré) est supprimé.

## Agents IA (MCP)

### `GET /token`, `POST /token`, `DELETE /token?id=`

Jetons d'accès de l'utilisateur connecté, pour le serveur MCP. `POST` (`name` : 1 à 50
caractères ; `days` : `30`, `90`, `365`, ou `0`/vide pour sans expiration) renvoie le jeton
(`token`, préfixe `cbt_`) **une seule fois**, avec `id`, `name`, `token_hint` et
`expires_at` ; `409` au-delà de 20 jetons. La liste ne donne que `id`, `name`,
`token_hint`, `created_at`, `last_used_at`, `expires_at` (`null` = sans expiration) et
`expired`. `DELETE` révoque un jeton (`404` s'il n'existe pas ou appartient à un autre
utilisateur).

### `GET /mcp/settings`, `PUT /mcp/settings`

`{"enabled": true|false}` : état du serveur MCP (désactivé par défaut). `PUT` (`enabled`)
l'active ou le désactive — administrateur.

### `POST /mcp` — publique (jeton d'accès)

Serveur MCP (JSON-RPC 2.0, transport Streamable HTTP) en lecture seule, authentifié par
`Authorization: Bearer <jeton>` (ou `?token=`). `GET /mcp` répond `405`. Voir
[Agents IA (MCP)](mcp.md).

### OAuth 2.1 des agents IA — publiques (sauf indication)

Pour les agents qui ne prennent que l'URL du serveur MCP (Claude web, Desktop, mobile).
Réponses et erreurs au format OAuth (`{"error", "error_description"}`), pas au format de
l'API.

| Route | Rôle |
|---|---|
| `GET /.well-known/oauth-protected-resource` (et `…/api/mcp`) | Métadonnées du serveur MCP (RFC 9728) : `resource`, `authorization_servers` |
| `GET /.well-known/oauth-authorization-server` | Métadonnées OAuth (RFC 8414) : points d'accès, `S256`, méthodes d'authentification du client |
| `POST /api/oauth/register` | Enregistrement dynamique (RFC 7591) : `redirect_uris` (HTTPS, ou HTTP sur `localhost`), `client_name`, `token_endpoint_auth_method` (`none`, `client_secret_post`, `client_secret_basic`) ; `201` avec `client_id` (et `client_secret`) |
| `GET /api/oauth/authorize` | `response_type=code`, `client_id`, `redirect_uri`, `state`, `code_challenge` (`S256`) : `302` vers la page de consentement du portail, ou vers l'agent avec `error=` ; `400` si le client ou l'URI de retour est inconnu |
| `GET /api/oauth/client?request=` — JWT | Nom de l'agent et hôte de retour, pour la page de consentement |
| `POST /api/oauth/approve` — JWT | `request`, `approved` : `{"redirect"}` vers l'agent, avec le code (5 minutes, usage unique) ou `error=access_denied` |
| `POST /api/oauth/token` | Formulaire ou JSON : `grant_type=authorization_code` (`code`, `redirect_uri`, `client_id`, `code_verifier`) ou `refresh_token` ; `{"access_token", "token_type": "Bearer", "expires_in", "refresh_token"}` |

Toutes répondent `403` (`access_denied`) quand le serveur MCP est désactivé.

## Sauvegarde

Le portail fabrique le ZIP d'un export et lit celui d'un import : l'API n'échange que du
JSON. Données (dans l'ordre de leurs dépendances) : `categories`, `rules`, `budgets`,
`insights`, `insight_alerts`, `transactions`, `deleted_transactions`, `tags`,
`transaction_tags`. Le propriétaire d'une transaction (`user`), l'auteur d'une suppression
et l'utilisateur d'une alerte d'insight (`user_id`) sont écrits par leur identifiant de
connexion. `insight_alerts` (`user_id`, `insight_id`, `direction`, `threshold`,
`notified_month`) n'est proposé qu'une fois la migration `2026-11-03_insight_alert`
appliquée ; avant, il est ignoré à l'import. Jamais exportés : utilisateurs, mots de passe, jetons, OAuth, appareils,
réglages, connexions et comptes bancaires, alertes de budget envoyées.

### `GET /backup` — administrateur

Les données sauvegardables et leur nombre de lignes : `[{"name":"categories","count":12}, …]`.

### `GET /backup/export` — administrateur

Paramètre facultatif `datasets` : noms séparés par des virgules (toutes par défaut ; `400`
pour un nom inconnu). Réponse : `format` (`carbure-backup`), `version` (1), `created_at`,
`carbure` (version), `schema` (dernière migration), `counts` et `datasets` (les lignes de
chaque donnée, colonnes de sa table).

### `POST /backup/import` — administrateur

Corps : `{"datasets": {"rules": [{"id":1,"keyword":"EDF","category":3,"notify":false}], …}}`.
**Supprime et remplace** les lignes de chaque donnée fournie (une liste vide la vide) ; les
autres sont conservées. Toutes les lignes sont vérifiées avant toute suppression (types,
longueurs, dates, identifiants uniques, hiérarchie, icône et couleur des catégories,
requête, couleur et icône des insights comme à leur enregistrement), puis l'import se fait en une transaction : `400` avec le
détail (donnée, ligne, colonne) et rien n'est modifié si une ligne est invalide, si une
contrainte de la base est violée, ou si une donnée conservée ou importée renvoie à une
catégorie absente. La catégorie 0 (par défaut) est recréée si elle manque ; les alertes de
budget des catégories disparues sont supprimées. Un identifiant de connexion inconnu devient
l'administrateur qui importe ; une suppression sans auteur (`user_id` à `null`) le reste.
Les alertes d'insight d'un utilisateur inconnu, ou d'un insight absent ou masqué, ne sont
pas importées.
Le portail refuse un fichier d'une version du format plus récente que la sienne. Réponse : `{"imported": {"rules": 1}}`. Corps limité à 32 Mo
par nginx (2 Mo pour les autres routes).

## Purge

Vide des données de la sauvegarde (mêmes noms) pour repartir de zéro. Acceptée **depuis
le réseau local uniquement** : toutes les adresses de la requête (pair, `X-Forwarded-For`,
`X-Real-IP`, `Forwarded`) doivent être privées ou réservées.

### `GET /purge` — administrateur

`{"local": true, "datasets": [{"name":"categories","count":11,"dependents":["rules","budgets","transactions","transaction_tags"]}, …]}` :
`local` indique si la purge est acceptée depuis cette adresse, `count` le nombre de lignes
supprimées (sans la catégorie 0 pour `categories`), `dependents` les données purgées avec
elle (elles y renvoient).

### `POST /purge` — administrateur

Corps : `{"datasets": ["transactions", "transaction_tags"]}`. Supprime toutes les lignes de
chaque donnée en une transaction, les contraintes de la base appliquées (alertes de budget
des catégories, catégorie d'alerte des utilisateurs…). La catégorie 0 est conservée.
`403` (`purge_not_local`) depuis Internet ; `400` sans donnée, pour un nom inconnu, ou
(`purge_dependents`) si une donnée qui dépend d'une donnée choisie manque : rien n'est
supprimé. Réponse : `{"purged": {"transactions": 120, "transaction_tags": 4}}`.

## Système

### `GET /health` — publique

État de l'instance pour le `HEALTHCHECK` Docker et les outils de supervision, sans
authentification ni donnée : `{"status":"ok","version":"1.2.0","database":"ok","schema":"<version du schéma>","min_mobile_version":"1.0","currency":"EUR"}`,
`500` si la base ne répond pas, `{"status":"setup","version":"…","min_mobile_version":"1.0"}` tant que Carbure n'est
pas installé. `version` est la version de Carbure (fichier `VERSION`, `dev` hors release).
`currency` est la devise des montants (réglage `currency`, code ISO 4217, `EUR` par défaut).
`min_mobile_version` est la plus ancienne version de l'app iOS (son `MARKETING_VERSION`)
que le serveur prend en charge : une app plus ancienne invite son utilisateur à la mettre
à jour. Elle est fixée dans le code (`Carbure\Lib\MobileApp::MIN_VERSION`) et n'est relevée
que lorsqu'une évolution de l'API ne peut plus être lue par les anciennes versions de l'app,
une fois la nouvelle version publiée sur l'App Store.
Sur une instance de démonstration (réglage `demo`, image `ltoinel/carbure:demo`), la réponse
contient en plus `"demo":true` : le portail affiche alors le compte public (`demo` / `carbure`)
sur l'écran de connexion. Le champ est absent sur une instance normale.

### `GET /system/logs` — administrateur

Fichiers de log de l'instance, les plus récents d'abord : `[{name, size, modified}]`.

### `GET /system/logs/retention` — administrateur

Durée de conservation des fichiers de log : `{"days": 90}` (`0` : conservés indéfiniment).
Les fichiers plus anciens sont supprimés à la fin de la synchronisation bancaire
(paramètre `log_retention_days`).

### `GET /system/logs/entries` — administrateur

Entrées d'un fichier, les plus récentes d'abord. Paramètres : `file` (nom renvoyé par
`/system/logs`), `level` facultatif (niveau minimal : `DEBUG`, `INFO`, `WARN`, `ERROR`),
`search` facultatif (texte ou identifiant de requête), `limit` (1 à 1000, 200 par défaut).
Réponse : `{file, entries: [{time, level, uid, caller, message, ip, user, method, path}], truncated}`
(`ip`, `user`, `method` et `path` : entrées au format JSON, absents des fichiers plus
anciens ; le contexte d'une entrée suit son message) ; seuls les
2 derniers Mo sont lus (`truncated`) et les jetons sont masqués. 400 pour un nom de fichier
invalide, 404 s'il n'existe pas.

### `GET /system/schema` — administrateur

Version du schéma de la base et migrations à appliquer :
`{"version": "2026-10-13_base", "pending": []}`.

### `GET /system/release` — administrateur

Dernière version publiée de Carbure, demandée à l'API de GitHub au plus une fois par jour
(réglage `update_check`) : `{"enabled": true, "current": "1.9.1", "latest": "1.10.0",
"url": "https://github.com/ltoinel/Carbure/releases/tag/v1.10.0", "available": true,
"checked_at": "2026-11-01 10:00:00"}`. `available` : `latest` est plus récente que
`current` (jamais pour une version de développement) ; `latest`, `url` et `checked_at` sont
`null` tant qu'elles ne sont pas connues.

### `POST /system/migrate` — administrateur

Applique les migrations en attente (bouton **Mettre à jour** du portail) :
`{"version": "…", "applied": ["…"]}` ; `500` avec la cause si une migration échoue (les
suivantes ne sont pas exécutées).

### `POST /system/jwt-secret` — administrateur

Remplace le `jwtsecret` de la configuration par une valeur aléatoire : toutes les sessions
(portail et application iOS) prennent fin. `GET /system/schema` indique `weakJwtSecret`
quand le secret est celui de l'exemple (ou trop court) : le portail propose alors de le
renouveler.

### `GET /system/config` — administrateur

Le fichier de configuration, par section : `file` (chemin dans l'instance), `writable`, et
`sections` (`name`, `settings`). Chaque réglage : `key`, `value` (masquée pour les secrets ;
`true`/`false` pour un interrupteur ; une liste pour `regex_label`), `set`, `secret`,
`editable`, `kind` (`select`, `bool`, `number`, `text`, `url`, `email`, `password`, `list`) et, selon le type,
`options`, `min`, `max`. Les réglages modifiables absents du fichier sont listés avec
`set: false`.

### `PUT /system/config` — administrateur

`values` : les nouvelles valeurs par clé, uniquement parmi les réglages modifiables
(`log_level`, `log_retention_days`, `savings_category`, `public_url`, `currency`, `next_month_day`, `woob_transactions`, `woob_logging`,
`woob_debug`, `woob_auto_update`, `woob_relabel`, `woob_2fa_wait`, `woob_2fa_retries`, `push_api_key`, `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_auth`,
`smtp_username`, `smtp_password`, `smtp_from`, `smtp_from_name`). Toutes les valeurs sont vérifiées avant d'écrire quoi que
ce soit (`400` sinon, fichier inchangé) ; l'ancien fichier est gardé en `.bak`. Renvoie la
configuration comme `GET`.

### `GET /system/sync` — administrateur

`reveal` (facultatif, `false` par défaut) : `url` de `/api/bank/sync`, `token` (le
`sync_token`, masqué sauf avec `reveal=true` ; `null` s'il n'y en a pas), `masked` et
`accounts` (les comptes suivis, pour `?account=`) et `header` (`X-Sync-Token`, l'en-tête
qui porte le jeton). La requête à planifier est
`curl -sN -H "<header>: <token>" "<url>"` : le jeton n'est jamais dans l'URL.

### `POST /system/smtp-test` — administrateur

`to` (facultatif, l'adresse de l'administrateur par défaut) : envoie un email de test avec
le serveur SMTP de la configuration, dans la langue de l'utilisateur de cette adresse (sinon
celle de l'administrateur). Renvoie `{"sent": true, "to": "…"}` ; `400` sans
serveur SMTP configuré ou sans destinataire, `502` si le serveur est injoignable ou refuse
l'email (sa réponse est dans le message d'erreur).

### `GET /system/push` — administrateur

Le service de notifications push de l'instance, sa clé d'API et le consentement de
l'administrateur :
`{"url": "https://api.carbure.org", "official": true, "registered": true, "api_key": "cpk_…", "consent": {"at": "2026-10-17T08:00:00Z", "url": "https://api.carbure.org"}, "consent_valid": true, "config_writable": true}`.
`url` est le relais utilisé (toujours `https://api.carbure.org`), `official` s'il s'agit
du service officiel (toujours vrai), `registered` si une clé d'API est configurée,
`api_key` la clé (`""` sans inscription ; réservée aux administrateurs, jamais dans
`GET /system/config`), `consent` le dernier consentement enregistré (`null` s'il n'y en a
pas), `consent_valid` s'il a été donné pour ce relais et `config_writable` si le fichier
de configuration peut recevoir la clé.

### `GET /system/push-status` — administrateur

L'état du service de notifications push, vérifié auprès du relais à chaque appel :
`{"relay": {"reachable": true, "status": "ok", "version": "1.0.0", "error": null}, "key": {"state": "valid", "key_id": "0123456789abcdef", "devices": 3, "used_today": 12, "limits": {"notifications_per_day": 500, "devices": 20}}}`.

- `relay` : réponse de `GET /v1/health` ; `status` vaut `ok`, `error` (le relais répond
  mais pas « ok » : `error` donne la raison) ou `unreachable` (`reachable` à `false`).
- `key` : réponse de `GET /v1/keys/current` avec la clé de l'instance ; `state` vaut
  `valid` (avec `key_id`, `devices`, `used_today`, `limits`), `invalid` (refusée :
  révoquée ou inconnue), `none` (pas de clé) ou `unknown` (relais injoignable ou réponse
  inattendue), avec `error` pour ces deux derniers cas et `invalid`.

### `POST /system/push-register` — administrateur

Inscrit l'instance sur le service de notifications push (`https://api.carbure.org`) : résout sa preuve de travail, obtient une clé d'API et l'écrit dans
`push_api_key`. Avec une clé déjà configurée, la **renouvelle** (les appareils continuent
de recevoir les notifications). La clé n'est pas renvoyée ici (`GET /system/push` la donne) :
`{"registered": true, "renewed": false, "key_id": "…", "limits": {…}}`. `502` si le service
est injoignable ou refuse, `429` au-delà de ses limites d'inscription ; le fichier de
configuration doit être modifiable (vérifié avant l'appel).

Paramètre `consent` (`true`) : l'administrateur accepte que les notifications (jetons des
appareils, titre et texte) passent par ce relais. **Obligatoire** pour une inscription,
et pour un renouvellement si le consentement enregistré porte sur un autre relais (celui
d'une ancienne version) : sinon `400`. Il est enregistré (date et adresse du relais, table
`settings`) et renvoyé par `GET /system/push`.

### `POST /system/push-test` — administrateur

Envoie une notification de test aux appareils de l'administrateur, dans sa langue :
`{"sent": 1, "devices": 2, "results": [{"device": "iPhone", "sent": true, "status": "sent", "error": null}, …]}`
(sans les tokens). Un appareil sans jeton de notification a le statut `no_token`. `400` sans
clé d'API ou sans appareil.

### `POST /system/sync-token` — administrateur

Écrit un nouveau `sync_token` aléatoire dans la configuration et le renvoie : `{"token": "…"}`.
Les tâches planifiées doivent utiliser le nouveau.

## Assistant d'installation

Tant que `data/conf/prod.ini` n'existe pas, l'API ne répond qu'à ces routes (toutes les autres
renvoient `503` avec `"setup": true`) :

| Route | Rôle |
|---|---|
| `GET /setup` | État de l'assistant : `codeRequired`, `dbPasswordFromEnvironment` et valeurs par défaut (`db_host`, `db_port`, `db_name`, `db_user`, `admin_user`, `language`) issues de l'environnement Docker |
| `POST /setup/database` | Teste la connexion (`db_host`, `db_port`, `db_name`, `db_user`, `db_password`) et décrit l'installation : `state` (`none` = base vide, `current` = à jour, `outdated` = migrations à appliquer), `version`, `pending`, `hasAdmin` |
| `POST /setup/install` | Crée ou migre le schéma, crée le premier administrateur s'il n'y en a pas (`admin_user`, `admin_password` de 8 caractères minimum, `admin_email`, `language`) et écrit la configuration ; `409` sans `backup_confirmed` quand des migrations sont à appliquer. Sur une base vide, `starter_categories` (facultatif) ajoute les catégories et règles de `sql/starter.json`, et `starter_insights` ses insights |
| `GET /health` | `{"status":"setup","version":"…","min_mobile_version":"…"}` |

Depuis Internet, ou si `data/conf/setup.code` existe, les requêtes `POST` exigent le paramètre
`code` (code d'installation), sinon `403`. Voir [Sécurité](securite.md#assistant-dinstallation).

## Spécification OpenAPI

`swagger/swagger.php` génère une spécification OpenAPI 3 par réflexion sur les
attributs `#[ApiRoute]` ; `swagger/index.html` l'affiche avec Swagger UI, aussi dans le
portail (**Administration → API**), authentifié avec la session de l'administrateur. Les
chemins de la spécification commencent par `/api` (serveur `/`) : **Try it out** appelle
`/api/...` sur le serveur qui sert Swagger UI. `swagger/carbure.css` fonce les couleurs du
thème clair de Swagger UI (contraste WCAG AA) ; son thème sombre suit celui du portail, ou
la préférence du système quand Swagger UI est ouvert seul.
