---
title: "Sécurité : authentification et données bancaires"
description: "Comment Carbure protège les données du foyer : mots de passe, JWT, blocage des connexions, identifiants bancaires gardés par woob et analyse de l'image Docker."
icon: material/shield-lock-outline
---

# Sécurité

## Authentification

- `POST /user/login` vérifie le mot de passe et renvoie un **JWT HS256** signé avec
  `jwtsecret`, valable **30 jours** (`sub` = identifiant de l'utilisateur).
- Les autres routes exigent `Authorization: Bearer <token>` ; la signature est comparée
  en temps constant et l'expiration est contrôlée.
- Les mots de passe sont hachés avec `password_hash()` (bcrypt). Les anciens hachages
  SHA-256 salés sont vérifiés une dernière fois puis remplacés à la connexion.

### Double authentification (TOTP)

Un administrateur peut **imposer** à tous les utilisateurs un second facteur à la
connexion : le code à 6 chiffres d'une application d'authentification (Google
Authenticator, Microsoft Authenticator, 2FAS, Aegis…), réglage `mfa_required` de
**Administration › Paramètres** (désactivé par défaut). Un utilisateur peut aussi
l'activer pour lui seul depuis **Mon profil**.

- **TOTP** (RFC 6238) implémenté en PHP sans dépendance : HMAC-SHA1, pas de 30 s,
  6 chiffres, secret aléatoire de 160 bits (`random_bytes`) partagé en base32 par QR code
  (`otpauth://`, dessiné dans le navigateur par une bibliothèque embarquée, jamais par un
  service externe). Le code du pas précédent et du suivant est accepté (décalage
  d'horloge du téléphone), comparé en temps constant ; un code ne sert **qu'une fois**
  (le dernier pas accepté est mémorisé).
- **Connexion en deux étapes** : avec le bon mot de passe, `POST /user/login` renvoie,
  au lieu de la session, un **défi** valable **5 minutes** qui ne permet que d'envoyer le
  code à `POST /user/login/mfa`. Ce défi n'est pas un JWT (2 parties, clé de signature
  dérivée de `jwtsecret` pour ce seul usage) : il n'ouvre aucune route. Les **codes
  faux comptent dans le blocage** du compte (5 échecs, 24 h), et la réussite du mot de
  passe seul ne remet pas le compteur à zéro tant que le code n'est pas donné.
- **Enrôlement** : un utilisateur sans application configure la sienne à cette étape
  (QR code et clé en texte, confirmés par un premier code) ou à l'avance depuis son
  profil. Chaque défi d'enrôlement porte une **nouvelle** clé (jamais celle d'un
  enrôlement commencé) : qui l'a obtenue plus tôt avec le mot de passe ne garde pas un
  second facteur valable une fois l'utilisateur enrôlé, et une application déjà activée
  n'est jamais remplacée. Il reçoit alors **10 codes de secours** à usage unique (seule leur empreinte
  SHA-256 est stockée), qui remplacent le code si le téléphone est perdu. Un
  administrateur **réinitialise** la double authentification d'un utilisateur (onglet
  Utilisateurs) ; s'il perd lui-même son téléphone sans code de secours, il repasse
  `mfa_required=false` dans `data/conf/prod.ini` sur le serveur.
- **Navigateur de confiance (6 mois)** : la case « Faire confiance à ce navigateur
  pendant 6 mois » de l'étape du code enregistre un jeton aléatoire de 256 bits dans un
  cookie `HttpOnly` (illisible par les scripts du portail), `SameSite=Strict`, `Secure`
  en HTTPS, limité aux routes de connexion (`/api/user/login`) ; la base n'en garde que
  l'empreinte SHA-256, avec le navigateur (User-Agent) et l'échéance. L'utilisateur voit
  et révoque ses navigateurs de confiance dans son profil ; ils sont tous révoqués quand
  son mot de passe change, ou que sa double authentification est désactivée ou
  réinitialisée.
- **Appareil déjà connu** : l'app iOS envoie à la connexion le token APNs de l'appareil
  (64 caractères hexadécimaux, attribué par Apple, non devinable). S'il est déjà
  enregistré **pour cet utilisateur** (table `devices`), le code n'est pas demandé. C'est
  un compromis assumé : ce token n'est pas un secret au sens strict (il est connu de
  l'appareil, d'Apple, du serveur, et visible par l'utilisateur dans son profil) ; un
  attaquant qui a le mot de passe doit **aussi** l'obtenir, ce qui suppose l'accès au
  téléphone, à la base ou à une session déjà ouverte. Un nouvel appareil n'est
  enregistré qu'**après** le second facteur : un token inventé ne contourne rien. Limites :
  un appareil enregistré **avant** l'activation du second facteur (par exemple par un
  attaquant qui avait déjà le mot de passe) reste connu, et la réinitialisation par
  l'administrateur ne supprime pas les appareils : avant d'imposer la double
  authentification, et après la perte d'un iPhone, chaque utilisateur vérifie et
  supprime les appareils inconnus ou perdus dans son profil.
- **Clients sans double authentification** : seuls les clients qui l'annoncent
  (`mfa=true`, le portail) reçoivent le défi ; les autres reçoivent `403` avec un message
  explicite, plutôt qu'une réponse d'un format qu'ils ne connaissent pas. L'**app iOS**
  actuelle ne le gère pas : elle se connecte depuis un iPhone déjà enregistré, mais un
  **nouvel** iPhone est refusé tant que l'app ne gère pas cette étape (voir TODO).
- Tant que la migration `2026-10-24_user_mfa` n'est pas appliquée, le second facteur
  n'est pas demandé (avertissement dans les logs) : l'administrateur doit pouvoir se
  connecter pour l'appliquer depuis le bandeau du portail.
- Le **secret TOTP** est stocké en clair dans `user_mfa` (il faut le connaître pour
  vérifier un code) : les sauvegardes de la base sont donc aussi sensibles que le fichier
  de configuration.
- Non concernés (pas de connexion interactive) : le serveur MCP et ses jetons d'accès,
  les jetons OAuth (l'autorisation d'un agent passe par la connexion au portail, donc par
  le second facteur), et le `sync_token` du planificateur.

### Limitation des connexions

L'image Docker limite dans nginx les routes de connexion (`POST /api/user/login`,
`/api/user/login/mfa`, `/api/oauth/token`) à **30 requêtes par minute par adresse du
client**, avec une rafale de 10 (un mot de passe puis le code du second facteur, une
faute de frappe…) : au-delà, nginx répond `429` (`{"error": …, "code": 429}`,
`Retry-After: 60`) sans appeler PHP, et le portail affiche « Trop de tentatives de
connexion depuis cette adresse ». Elle complète le [blocage d'un compte](#autorisations)
après 5 échecs, qui ne freine pas des essais répartis sur plusieurs comptes. Le reste de
l'API n'est pas limité. Installé sur un serveur web existant (YunoHost compris), Carbure
n'a pas cette limite : votre serveur web peut l'ajouter (`limit_req` de nginx).

**Adresse du client derrière un proxy inversé.** Derrière le proxy du NAS, Caddy ou
Traefik, toutes les connexions viennent de l'adresse du proxy : sans précaution, tout le
foyer (et les applications mobiles) partagerait la même limite. nginx prend donc l'adresse
du client dans l'en-tête `X-Forwarded-For` ajouté par le proxy (module `realip`), mais
seulement quand la connexion vient d'un **proxy de confiance**, variable d'environnement
`TRUSTED_PROXIES` (adresses ou réseaux CIDR séparés par des espaces ou des virgules) :

| `TRUSTED_PROXIES` | Proxys de confiance |
|---|---|
| absente (défaut) | les réseaux privés et la boucle locale : `127.0.0.0/8`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `::1/128`, `fc00::/7` (le proxy du NAS, un conteneur Caddy ou Traefik, le proxy de l'hôte) |
| `172.18.0.0/16 192.168.1.10` | seulement ces adresses ou réseaux (un proxy sur une adresse publique : ajoutez-la) |
| vide ou `none` | aucun : l'adresse de la connexion (Carbure publié directement, sans proxy) |

L'adresse retenue est la dernière de `X-Forwarded-For` qui n'est pas un proxy de
confiance (`real_ip_recursive`) : le proxy ajoute celle qu'il voit, et les adresses
écrites avant par le client sont ignorées. Elle sert aussi au journal d'accès de nginx et
à PHP (`REMOTE_ADDR` : logs, assistant d'installation). Une entrée invalide arrête le
conteneur au démarrage (message dans `docker logs`), qui affiche aussi les proxys retenus.

!!! warning "Compromis du réglage par défaut"
    Le défaut fonctionne sans réglage avec les proxys usuels, tous sur un réseau privé.
    En contrepartie, un appareil du **réseau local** (ou un autre conteneur) peut choisir
    l'adresse que nginx retient en envoyant lui-même `X-Forwarded-For`, et ainsi
    contourner la limite ou fausser l'adresse des journaux ; depuis Internet, à travers le
    proxy, ce n'est pas possible. Si Carbure est publié **sans proxy** et que les
    connexions d'Internet arrivent avec une adresse privée (redirection de port de la box
    ou de Docker qui masque l'adresse d'origine), mettez `TRUSTED_PROXIES=none` ; avec un
    proxy, vous pouvez restreindre `TRUSTED_PROXIES` à sa seule adresse. Un proxy qui
    n'ajoute pas `X-Forwarded-For` (Caddy et Traefik l'ajoutent par défaut, nginx avec
    `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for`) fait partager la limite
    à tous ses clients : elle reste large pour un foyer.

## Autorisations

| Rôle | Droits |
|---|---|
| Utilisateur | Données du foyer (comptes, transactions, budgets, insights, tendances), pointage et catégorie des transactions, montants des budgets, synchronisation par l'API, son profil, ses appareils, ses jetons d'accès MCP |
| Administrateur (`users.is_admin = 1`) | En plus : règles de catégorisation, catégories, connexions bancaires et comptes suivis (et leur synchronisation depuis le portail), mise à jour des modules woob, insights (requêtes SQL), activation du serveur MCP, paramètres du serveur (dont SMTP) et logs, mise à jour de la base de données, utilisateurs (profil, déblocage, réinitialisation de la double authentification), double authentification imposée |

Les routes réservées sont déclarées dans leur attribut (`#[ApiRoute(..., admin: true)]`) et
renvoient `403` avant l'appel de la méthode ; un test unitaire fixe le niveau d'accès de
chaque route. Le portail masque les onglets correspondants.

Après **5 tentatives de connexion échouées** d'affilée, le compte est **bloqué 24 heures**
(`423`, dès le 5e échec : la mire de connexion l'indique aussitôt), même avec le bon mot
de passe ; une connexion réussie remet le compteur à zéro. Un
administrateur le débloque depuis l'onglet **Utilisateurs** (`POST /user/unlock`). Avec
l'image Docker, les connexions sont aussi [limitées par adresse](#limitation-des-connexions).

Un administrateur ne peut pas supprimer son propre compte ; un utilisateur propriétaire
de transactions ne peut pas être supprimé ; le dernier administrateur ne peut pas perdre
son rôle.

## En-têtes HTTP

| En-tête | API (PHP, toute installation) | Portail (nginx) |
|---|---|---|
| `Content-Security-Policy` | `default-src 'none'; frame-ancestors 'none'` | ressources du serveur uniquement ; `'unsafe-eval'` (Vue compile les modèles dans le navigateur) et styles en ligne |
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `X-Frame-Options` | `DENY` | `DENY` |
| `Referrer-Policy` | `no-referrer` | `no-referrer` |
| `Cache-Control` | `no-store` (données bancaires) | — |
| `Cross-Origin-Resource-Policy` | `same-origin` | — |
| `Permissions-Policy`, `Cross-Origin-Opener-Policy` | — | caméra, micro, géolocalisation, paiement, USB désactivés ; `same-origin` |

Les en-têtes de l'API sont posés par PHP (`Webservice::securityHeaders`, et l'assistant
d'installation), quel que soit le serveur web. Ceux du portail et de Swagger UI sont posés
par nginx (`docker/nginx.conf`) ; Carbure ne fournit pas de `.htaccess`.

Swagger UI (`/swagger/`) a les mêmes en-têtes que le portail, sauf l'affichage dans un
cadre : `frame-ancestors 'self'` et `X-Frame-Options: SAMEORIGIN`, pour l'onglet
**Administration → API** du portail ; aucun autre site ne peut l'afficher dans un cadre, et
le portail lui-même garde `frame-ancestors 'none'` / `DENY`. Swagger UI lit le jeton de
session du portail dans le stockage du navigateur (même origine) et ne l'envoie que dans
l'en-tête `Authorization` des appels à `/api`.

**Aucun CDN externe** : le portail et Swagger UI ne chargent rien depuis un autre site.
Vue, CodeMirror, les polices (`portal/vendor/`) et Swagger UI (`swagger/vendor/`) sont
servis avec Carbure ; leurs versions et licences sont listées dans le `README.md` de chaque
dossier. Leur CSP n'autorise donc que le serveur lui-même (`'self'`, plus `data:` pour les
images) pour les scripts, styles, polices, images et appels réseau : Swagger UI n'a pas de
script en ligne ni de badge de validation externe. Un test (`tests/portal/localAssets.test.mjs`)
échoue si une page ou une feuille de style charge une ressource externe, ou si la CSP
autorise un autre site. Le site de documentation (carbure.org) héberge lui aussi ses polices,
téléchargées à la construction (plugin `privacy` de Material for MkDocs).

L'API ne révèle pas la version de PHP (`X-Powered-By` retiré) et nginx pas la sienne
(`server_tokens off`). **HSTS** est à activer sur le proxy HTTPS placé devant Carbure (proxy
inversé du NAS, Traefik, Caddy…), le conteneur servant du HTTP. Sur un serveur nginx sans
Docker, reprendre les en-têtes de `docker/nginx.conf`.

## Assistant d'installation

Tant que `data/conf/prod.ini` n'existe pas, l'API ne répond qu'à `/api/setup` (le reste renvoie
`503`). Depuis le réseau local, l'assistant est accessible sans code, comme la plupart des
applications auto-hébergées ; depuis Internet (adresse publique), ou dès que le fichier
`data/conf/setup.code` existe, chaque étape exige le **code d'installation** de ce fichier, aussi
écrit dans les journaux du serveur (`docker logs`). Une erreur de code est ralentie. Le mot
de passe de la base fourni par Docker (`DB_PASSWORD`) n'est utilisé que pour la base de
l'environnement, jamais pour un autre serveur saisi dans l'assistant. Une fois la
configuration écrite, l'assistant disparaît et le code est supprimé.

!!! warning "Proxy inversé"
    L'adresse prise en compte est celle qui se connecte au serveur PHP (`REMOTE_ADDR`), et
    celles des en-têtes du proxy (`X-Forwarded-For`, `X-Real-IP`, `Forwarded`). Un proxy
    inversé qui ne les envoie pas, ou n'y met que des adresses locales, fait paraître le
    client local : le code n'est alors pas demandé. Installez Carbure avant de l'exposer sur Internet, ou créez
    `data/conf/setup.code` (Docker : `/data/conf/setup.code`) avec un code de votre choix pour
    l'imposer.

Les mises à jour de schéma ultérieures se font sans l'assistant : migrations appliquées
au démarrage du conteneur Docker ou par un administrateur depuis le portail
(`POST /system/migrate`).

## Insights (SQL stocké)

Les insights sont des requêtes SQL stockées en base et exécutées par le serveur. Seul un
administrateur peut les gérer, et chaque requête est encadrée :

- à l'enregistrement : un unique `SELECT`, sans `;` ni commentaire, sans mot-clé d'écriture,
  d'administration, de fichier (`INTO OUTFILE`, `LOAD_FILE`) ou de temporisation (`SLEEP`,
  `BENCHMARK`), sans barre oblique inverse ; la requête est testée avant d'être enregistrée ;
- **liste blanche de tables** : seules les tables du budget sont lisibles
  (`bank_transaction`, `bank_transaction_category`, `bank_transaction_category_keyword`,
  `budget`, `bank_account`, `bank_backend`), sans nom de base. Les autres tables du schéma
  (`users`, `user_mfa` et ses secrets TOTP, codes de secours, jetons, appareils, OAuth,
  paramètres…) et les schémas système sont refusés, même cités dans une sous-requête, une
  `UNION` ou un message d'erreur provoqué (`EXTRACTVALUE`) : le message d'erreur renvoyé par
  `POST /insight/check` ne peut donc contenir que des données du budget. Un test unitaire
  impose de classer chaque nouvelle table de `sql/carbure.sql` ;
- à l'exécution : la requête est **vérifiée à nouveau** (une requête enregistrée avant ces
  règles n'est pas exécutée), dans une **transaction en lecture seule** (`START TRANSACTION
  READ ONLY`) et avec une **limite de 5 secondes** (`SET STATEMENT max_statement_time` sous
  MariaDB, indication `MAX_EXECUTION_TIME` sous MySQL) ; une requête en échec ou trop longue
  donne `0` sans bloquer les autres.

## Jetons d'accès (MCP)

Le serveur MCP (`/api/mcp`) est **désactivé par défaut** ; un administrateur l'active dans
l'onglet **Agent**. Il accepte des jetons d'accès créés par chaque utilisateur dans cet
onglet : 192 bits aléatoires, préfixe `cbt_`, **seule l'empreinte SHA-256 est stockée**,
durée de validité au choix (30, 90 ou 365 jours, ou sans expiration), révocables, avec la
date de dernière utilisation. Ils ne donnent accès qu'aux outils en
lecture seule du serveur MCP, pas au reste de l'API. Le serveur refuse les requêtes
portant un en-tête `Origin` d'un autre site que Carbure ou les clients web de Claude
(protection contre le DNS rebinding).

Les agents qui ne prennent qu'une URL (Claude web, Desktop, mobile) obtiennent un tel jeton
par **OAuth 2.1** : l'utilisateur se connecte au portail et autorise explicitement l'agent ;
**PKCE `S256` obligatoire**, codes d'autorisation à usage unique valables 5 minutes, URI de
retour enregistrées et comparées à l'identique (HTTPS, ou HTTP sur `localhost`), jetons de
rafraîchissement à rotation, seules les empreintes SHA-256 des codes, jetons et secrets
clients sont stockées. L'enregistrement des clients est ouvert (comme le prévoit la
spécification MCP) mais limité à 100 clients, les clients inutilisés étant purgés après
un jour ; tout est refusé quand le serveur MCP est désactivé.

## Routes publiques

Seules ces routes ne demandent pas de JWT (un test unitaire le vérifie) :

- `POST /user/login`, et `POST /user/login/mfa`, la seconde étape de la connexion, qui
  exige le défi de 5 minutes donné par la première (mot de passe vérifié) et le code de
  l'application ;
- `GET /bank/sync`, qui exige **soit** un JWT, **soit** le `sync_token` de la
  configuration (en-tête `X-Sync-Token` ; `?token=` est encore accepté mais obsolète, voir
  [API](api.md#bank-sync)). Sans `sync_token`
  configuré, seul un utilisateur connecté peut lancer la synchronisation ;
- `POST /mcp` et `GET /mcp` (serveur MCP), qui exigent un jeton d'accès ou un JWT ;
- les routes OAuth des agents IA (métadonnées `/.well-known/oauth-*`, enregistrement,
  autorisation et jetons), désactivées avec le serveur MCP ; l'autorisation elle-même
  (`POST /oauth/approve`) exige l'utilisateur connecté au portail ;
- `GET /health` (supervision), qui ne renvoie que l'état de la base et la version du schéma.

## Données sensibles

- Les logs masquent les champs `password` et `token` des requêtes, les secrets OAuth
  (`code`, `code_verifier`, `refresh_token`, `access_token`, `client_secret`), et le
  paramètre `token=` des URL (synchronisation, MCP), ainsi que le défi, le secret, l'URI
  `otpauth` et les codes de secours de la double authentification (requêtes et réponses
  journalisées en niveau `debug`). Le corps des réponses du serveur MCP et d'OAuth n'est
  pas journalisé.
- Le log d'accès de nginx (image Docker, `docker logs`) remplace aussi par `***` les
  paramètres `token=`, `access_token=` et `code=` des URL (format `carbure` de
  `docker/nginx.conf`) : le jeton d'une tâche planifiée pas encore migrée vers l'en-tête
  n'y apparaît pas. Un proxy placé devant (proxy inversé du NAS) peut en revanche
  journaliser l'URL complète. Les appels réussis de `GET /api/health` (healthcheck
  Docker, supervision, applications mobiles) n'y sont pas écrits ; ses erreurs (4xx,
  5xx) le sont.
- La pile d'appels d'une erreur est journalisée **sans les arguments** des fonctions (qui
  contiennent les paramètres de la requête : mot de passe, jeton…), et l'image Docker
  active `zend.exception_ignore_args`.
- Le mot de passe SMTP (`smtp_password`) n'est jamais renvoyé au portail ni journalisé
  (champ masqué dans les logs) ; il n'apparaît pas dans les erreurs d'envoi. Les emails
  sont en texte brut, les adresses et le sujet refusent les retours à la ligne (pas
  d'injection d'en-têtes) et le certificat du serveur SMTP est vérifié en STARTTLS/TLS.
- Les messages d'erreur ne renvoient ni hachage ni secret ; l'`uid` permet de retrouver
  le détail dans les logs serveur.
- **Format des logs** : une entrée par ligne JSON (JSON Lines), avec l'heure ISO 8601
  (millisecondes, fuseau), le niveau, l'identifiant de la requête (`uid`, repris d'un en-tête
  `X-Request-Id` valide), l'**adresse IP du client**, l'utilisateur, la méthode et le chemin
  (jetons de l'URL masqués), l'appelant, le message et son contexte. Chaque requête se
  termine par une ligne avec son statut HTTP et sa durée (INFO, WARNING pour 4xx, ERROR
  pour 5xx). `GET /health`, interrogé toutes les quelques minutes par les healthchecks,
  n'écrit son appel et cette ligne qu'en niveau `debug` (option `quiet` de sa route) ;
  ses erreurs gardent leur niveau. Un message ne peut pas simuler une autre entrée : ses retours à la ligne
  sont échappés.
- **Adresse du client** : derrière un proxy de confiance (adresse privée ou locale : nginx
  de l'image, proxy inversé du NAS), c'est la dernière adresse publique de
  `X-Forwarded-For` (ou `X-Real-IP`) ; sinon l'adresse de connexion, car ces en-têtes
  peuvent alors être forgés. Dans l'image Docker, nginx a déjà remplacé l'adresse de
  connexion par celle du client donnée par un proxy de confiance (`TRUSTED_PROXIES`, voir
  [Limitation des connexions](#limitation-des-connexions)), aussi écrite dans son journal
  d'accès.
- Une adresse IP est une **donnée personnelle** : les fichiers de log sont supprimés après
  `log_retention_days` jours (90 dans une nouvelle installation, voir
  [Configuration](configuration.md)).
- **Données personnelles selon le niveau des logs** (`log_level`) :

    | Niveau | Ce qui est écrit |
    |---|---|
    | `warning`, `error` (défaut) | Adresse IP et identifiant (numérique) de l'utilisateur sur chaque ligne ; erreurs, avec la seule dernière ligne d'erreur de woob |
    | `info` | En plus : chaque appel de l'API avec le **nom** de ses paramètres, jamais leur valeur (libellés, montants, emails restent hors des logs) ; les emails envoyés, adresse masquée (`j***@example.org`) |
    | `debug` | **Tout** : paramètres et réponses de l'API (transactions, soldes…, secrets toujours masqués) |

    `woob_debug` écrit en plus la sortie complète de woob (comptes, opérations, pages de la
    banque). `debug` et `woob_debug` servent à un dépannage ponctuel : remettez ensuite
    `warning` et supprimez les fichiers de log concernés (`data/logs/`).
- Les journaux nomment un utilisateur par son identifiant, jamais par son nom (compte
  bloqué, connexion refusée faute de double authentification).
- Les identifiants bancaires saisis dans le portail ne passent **jamais sur la ligne de
  commande** de woob (visible de tous les processus du conteneur : `ps`,
  `/proc/<pid>/cmdline`) : Carbure écrit la banque directement dans le fichier de
  configuration de woob (`.config/woob/backends`, lisible par son seul propriétaire), au
  format qu'écrirait `woob config add`. Une valeur entre accents graves (`` `…` ``) est
  refusée : woob l'exécuterait comme une commande pour obtenir la valeur. Un compte ou un nom de banque commençant par `-`
  est refusé (`400`) : woob le lirait comme une option.
- `data/` : `conf/prod.ini` (dont `push_api_key`, la clé d'API du service de notifications push), `logs/`, `woob/` (identifiants bancaires woob)
  sont exclus du dépôt Git.

## Recommandations de déploiement

!!! danger "Secret JWT"
    L'assistant d'installation génère un `jwtsecret` aléatoire ; si la configuration
    contient encore la valeur d'exemple, un bandeau propose aux administrateurs de le
    renouveler en un clic (`POST /system/jwt-secret`). Une valeur faible ou la valeur
    d'exemple permet de forger un jeton pour n'importe quel utilisateur. Changer le
    secret invalide toutes les sessions (app iOS et portail).

- Servir l'API et le portail **uniquement en HTTPS** (JWT et mots de passe transitent
  dans les requêtes ; la copie du token dans le portail exige HTTPS).
- Ne pas exposer `data/`, `conf/`, `sql/`, `tests/` ni `tools/` par le serveur
  web.
- Générer un `sync_token` long (l'onglet **Administration → Synchro** en crée un) et le
  passer dans l'en-tête `X-Sync-Token`, jamais dans l'URL (`?token=`, obsolète : les URL
  sont journalisées par les proxys et restent dans l'historique du shell).
- Restreindre l'utilisateur MySQL à la base `carbure`.
- Surveiller `data/logs/carbure_AAAAMMJJ.log` (erreurs de synchronisation, accès refusés : les lignes WARNING de fin de requête en 401 ou 403, avec l'IP du client). Au format JSON Lines, le fichier se lit aussi avec `jq` ou un collecteur de logs.

## Analyses de sécurité

Le workflow **Security** (badge du README) regroupe les analyses de sécurité :

- **gitleaks** cherche des secrets (mots de passe, jetons, clés) dans tout l'historique
  git, à chaque pull request et à chaque push sur `main` ;
- **Trivy** analyse les dépendances du dépôt (fichiers de verrouillage npm et pip) aux
  mêmes moments ;
- **Semgrep** analyse le code PHP (`src/`, `tools/`, `swagger/`) aux mêmes moments, avec
  les règles PHP de son registre public (injections, fonctions dangereuses, cryptographie,
  désérialisation) : CodeQL, qui analyse le JavaScript du portail et les workflows, ne
  prend pas en charge le PHP. Un résultat fait échouer l'analyse ;
- **Trivy** analyse **chaque lundi** l'image publiée sur Docker Hub
  (`ltoinel/carbure:latest`) : de nouvelles vulnérabilités sont publiées sans que Carbure
  change. Un échec signale qu'une nouvelle release (image reconstruite) est nécessaire.
  L'analyse se lance aussi à la main (**Actions → Security → Run workflow**).

Une vulnérabilité **critique pour laquelle un correctif existe** fait échouer l'analyse ;
les rapports complets (critique, haute, moyenne) sont publiés dans l'onglet **Security**
du dépôt.

## Sécurité de l'image Docker

Les modules bancaires de woob (code Python qui lit les identifiants) sont téléchargés
depuis le dépôt de woob : l'image contient `gpgv`, avec lequel woob vérifie la signature
de chaque module installé ou mis à jour et refuse un module dont la signature est
invalide.

La clé du dépôt de woob (`https://updates.woob.tech/3/main/`) n'est approuvée qu'une
fois : les clés suivantes sont vérifiées par woob avec la précédente. Mais **toute
commande woob qui rafraîchit les dépôts alors qu'aucune clé n'est approuvée lui fait
confiance d'elle-même** : sans terminal, woob affiche la question puis
`*** ASSUMING YES ***`. Carbure ne lance donc pas woob tant qu'un **administrateur** n'a
pas approuvé cette clé, depuis l'onglet **Comptes** (bouton **Mettre à jour les modules
bancaires**) : le portail affiche le dépôt et l'empreinte SHA-1 de sa clé, à comparer avec
celle publiée ou annoncée par les mainteneurs de woob, puis **Faire confiance et mettre à
jour**. Carbure télécharge lui-même la clé juste avant : woob n'est lancé que si son
empreinte est exactement celle confirmée ; après la mise à jour, la clé que woob a
conservée (`/data/woob/.local/share/woob/keyrings/`) doit aussi être celle-là, sinon elle
est supprimée et la mise à jour échoue.

**La clé du dépôt officiel est épinglée dans Carbure** : son empreinte
SHA-256 (`a1755b379793ff4922a7bfb5f566c019205f805b96f3420aee95938cd0d1634a`, soit le SHA-1
`b5fc136c2d500b8483696a0ee722865842a4cc45` affiché par woob ; clés des mainteneurs de woob,
inchangée depuis février 2023) est vérifiée une fois pour toutes par les mainteneurs de Carbure
(`WoobRepository::OFFICIAL_KEYRINGS`). Au premier démarrage du conteneur
(`tools/woob-trust.php`), Carbure télécharge la clé du dépôt officiel et, si son empreinte
est **exactement** celle épinglée, l'approuve sans rien demander et installe les modules,
avec les mêmes contrôles qu'une approbation manuelle : les banques se connectent tout de
suite. Une autre clé (changée, ou servie par un attaquant), un autre dépôt
(`woob_repository`) ou un dépôt injoignable au démarrage attendent toujours l'approbation
d'un administrateur, comme ci-dessous. L'épinglage se fait en SHA-256 et non en SHA-1 :
SHA-1 n'est plus sûr face aux collisions à préfixe choisi, une clé GPG peut porter des
paquets de remplissage, et cette approbation-là se fait sans regard humain.

Tant que le dépôt n'est pas approuvé, Carbure refuse aussi (`409`, « Trust the woob
repository first ») :

- **toute commande woob** sur une installation neuve : au premier lancement (pas encore de
  `~/.config/woob/sources.list`), n'importe quelle commande de woob crée la liste des
  dépôts et les rafraîchit d'abord, faisant confiance à la clé d'elle-même ; la mise à
  jour approuvée par l'administrateur est alors le premier lancement de woob ;
- d'**installer un module bancaire** (connecter une banque dont le module n'est pas encore
  installé) : woob vérifie sa signature avec la clé du dépôt ;
- l'option `--auto-update` (`woob_auto_update`) n'est passée à woob qu'une fois le dépôt
  approuvé : après le plantage d'un module, elle rafraîchit les dépôts.

Approuvez donc le dépôt avant de connecter une banque. Une commande woob lancée à la main
dans le conteneur (`woob config update`, ou n'importe laquelle au premier lancement) ferait
confiance à la clé d'elle-même.

Une fois le dépôt approuvé, le conteneur lance aussi `woob config update` à chaque
démarrage (dépôts, clé et modules installés), sans jamais répondre à une question de
woob ; tant qu'il ne l'est pas, il l'indique dans ses logs et ne met rien à jour. Un échec
de la mise à jour est écrit dans les logs du conteneur. Une commande woob lancée à la main dans le
conteneur doit l'être en `www-data` (`docker exec -u www-data …`) : lancée en root, elle
créerait dans `/data/woob` des fichiers que Carbure ne pourrait plus modifier (le
redémarrage du conteneur les lui rend).

Chaque build de la CI analyse aussi l'image construite :

- **hadolint** vérifie les bonnes pratiques du `docker/Dockerfile` ;
- **Trivy** recherche les vulnérabilités connues des paquets Alpine et des dépendances PHP
  et les secrets restés dans l'image. woob et ses dépendances Python (`/opt/woob`) ne sont
  pas analysés : ils suivent les versions de woob (`WOOB_VERSION`). Une vulnérabilité
  **critique pour laquelle un correctif existe** fait échouer le build (et donc la
  release) ; le rapport complet (critique, haute, moyenne) est publié dans l'onglet
  **Security** du dépôt.

Une vulnérabilité sans impact sur Carbure peut être acceptée dans `.trivyignore`, avec sa
justification. Les images publiées embarquent leur SBOM et leur provenance de build
(`docker buildx imagetools inspect ltoinel/carbure:latest --format '{{ json .SBOM }}'`).
Pour analyser une image localement :

```bash
docker run --rm -v /var/run/docker.sock:/var/run/docker.sock aquasec/trivy image ltoinel/carbure:latest
```

### Vérifier l'origine de l'image

Depuis la version 1.4.0, le workflow **Release** publie aussi une **attestation de
provenance** signée par GitHub (Sigstore) pour l'image poussée sur Docker Hub : elle prouve
que l'image (identifiée par son empreinte `sha256`) a été construite par le workflow
`release.yml` du dépôt `ltoinel/Carbure`, à partir du tag indiqué. Avant de mettre à jour le
NAS, on la vérifie avec la [CLI GitHub](https://cli.github.com/) (`gh auth login` au
préalable), depuis le NAS ou n'importe quel poste :

```bash
gh attestation verify oci://docker.io/ltoinel/carbure:1.4.0 --owner ltoinel
# Plus strict : le dépôt et le workflow qui ont signé
gh attestation verify oci://docker.io/ltoinel/carbure:1.4.0 \
  --repo ltoinel/Carbure \
  --signer-workflow ltoinel/Carbure/.github/workflows/release.yml
```

La commande échoue si l'image n'a pas d'attestation valide (image modifiée, poussée par un
autre moyen que le workflow, ou version antérieure à 1.4.0). L'attestation est aussi
visible dans l'onglet **Actions → Attestations** du dépôt. Pour figer exactement l'image
vérifiée, la référencer ensuite par son empreinte (`ltoinel/carbure@sha256:…`, affichée
par la commande) plutôt que par son tag.

### Chaîne de build

Les workflows GitHub Actions référencent chaque action par l'**empreinte complète de son
commit** (et non par un tag, qui peut être déplacé), tenue à jour par Dependabot avec un
délai de 7 jours après chaque nouvelle version ; `actions/checkout` ne conserve pas le
jeton git dans le dépôt cloné, chaque job a une durée maximale, et le titre de chaque pull
request (le message du commit sur `main`) est vérifié.

## Signaler une vulnérabilité

Merci de ne pas ouvrir d'issue publique : contacter le mainteneur via GitHub
(<https://github.com/ltoinel>).
