---
title: "Configuration du serveur (fichier prod.ini)"
description: "Référence de la configuration de Carbure : base de données, jetons, woob, synchronisation planifiée, notifications push et journaux."
icon: material/cog-outline
---

# Configuration

La configuration est lue dans `conf/<env>.ini`, où `<env>` vaut la variable
d'environnement `APP_ENV` (`prod` par défaut, `testing` pour les tests). Les
commentaires commencent par `;` (le `#` n'est pas accepté par PHP).

`data/conf/prod.ini` est écrit par l'**assistant d'installation** à partir de
`conf/prod.sample.ini`, avec les paramètres de la base et des secrets aléatoires
(`jwtsecret`, `password_salt`, `sync_token`) ; avec Docker, il se trouve dans
`/data/conf/prod.ini`. Il contient des secrets : il est ignoré par Git et ne doit jamais
être publié. Après une modification à la main, rien n'est à redémarrer (le fichier est
relu à chaque requête), sauf le conteneur Docker pour `SYNC_INTERVAL`.

!!! tip "Voir et modifier les réglages depuis le portail"
    Inutile d'ouvrir le fichier : un administrateur le consulte dans le portail, onglet
    **Administration → Paramètres**. Les réglages y sont présentés par section, avec leur
    valeur actuelle et une aide, les secrets masqués.

    Les clés marquées ✏️ dans les tableaux ci-dessous s'y modifient directement : `log_level`,
    `log_retention_days`, `savings_category`, `public_url`, `currency`, `next_month_day`, `update_check`, `woob_transactions`,
    `woob_logging`, `woob_debug`, `woob_auto_update`, `woob_relabel`, `woob_2fa_wait`, `woob_2fa_retries`,
    `push_api_key` et les réglages
    SMTP (`smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_auth`, `smtp_username`,
    `smtp_password`, `smtp_from`, `smtp_from_name`). Chaque valeur est
    vérifiée (liste de valeurs, nombre borné, format) avant l'écriture, et le fichier
    précédent est gardé en `prod.ini.bak`. Le serveur web doit pouvoir écrire le fichier ;
    sinon la page est en lecture seule.

    Les autres réglages — base de données, secrets, `woob_path` (commande exécutée par le
    serveur), `regex_label` (qui change les identifiants des transactions), chemins de
    fichiers — sont affichés mais ne se changent que sur le serveur.

D'autres réglages, comme l'activation du serveur MCP, sont enregistrés en base et non dans
ce fichier (voir [Réglages en base](#reglages-en-base)).

## `[global]`

| Clé | Valeurs | Description |
|---|---|---|
| `log_level` ✏️ | `debug`, `info`, `warning`, `error` | Niveau minimal des logs (`data/logs/carbure_AAAAMMJJ.log`). En `debug`, les erreurs PHP sont affichées et les réponses JSON indentées, et les données du foyer sont journalisées (voir [Sécurité](securite.md#donnees-sensibles)). |
| `log_retention_days` ✏️ | `0` à `3650` | Nombre de jours de conservation des fichiers de log ; les plus anciens sont supprimés à la fin de la synchronisation bancaire. `0` (ou absent) : conservés indéfiniment. `90` dans une nouvelle installation. |
| `development` | `true` / `false` | Indicateur de mode développement, écrit à `false` par l'assistant (sans effet actuellement) |
| `demo` | `true` / `false` | Instance de démonstration (écrit par l'image `ltoinel/carbure:demo`) : l'écran de connexion affiche le compte public de la démo. Absent par défaut ; jamais sur une instance avec de vraies données |
| `currency` ✏️ | code ISO 4217 | Devise des montants de l'instance (`EUR`, `USD`, `GBP`, `CHF`, `CAD`…), affichée dans le portail (son symbole : €, $, £… ou son code), les notifications et les e-mails ; `EUR` par défaut ou si la valeur n'est pas un code de 3 lettres. Seul l'affichage change : aucun montant n'est converti, choisissez la devise de vos comptes |
| `update_check` ✏️ | `true` / `false` | Bandeau des administrateurs quand une nouvelle version de Carbure est publiée : le serveur demande la dernière version à l'API publique de GitHub (`api.github.com`) au plus une fois par jour, sans rien envoyer sur l'instance (une requête anonyme, comme un téléchargement), et garde la réponse en base. `true` par défaut (ou si la clé est absente) ; `false` : jamais demandé. Jamais pour une version de développement |
| `next_month_day` ✏️ | `0` à `31` | Jour du mois à partir duquel un **virement reçu** (le salaire) compte pour le mois suivant : daté de ce jour ou après, il est rattaché au 1er du mois suivant, le mois qu'il finance ; sa date réelle reste visible dans le détail de la transaction. `25` par défaut (ou si la valeur n'est pas un nombre) ; `0` : jamais déplacé. S'applique aux transactions importées ou synchronisées ensuite, pas à celles déjà enregistrées. Voir [Mois de rattachement](fonctionnalites.md#deroulement-dune-synchronisation) |
| `savings_category` ✏️ | nom | Catégorie dont les transactions (avec ses sous-catégories) constituent l'épargne de l'onglet Tendances ; `Epargne` par défaut, casse et accents ignorés |
| `public_url` ✏️ | URL | Facultatif : adresse publique de Carbure (`https://carbure.exemple.fr`), annoncée aux agents IA qui se connectent par OAuth et aux applications mobiles par le QR code de **Mes appareils**. Par défaut, déduite de la requête : à renseigner si le proxy HTTPS n'envoie pas `X-Forwarded-Proto` |

## `[database]`

| Clé | Description |
|---|---|
| `db_hostname` | Hôte MariaDB/MySQL |
| `db_port` | Port (`3306` par défaut ; `3307` pour MariaDB 10 sur Synology) |
| `db_username`, `db_password` | Identifiants |
| `db_name` | Nom de la base |

## `[cleansing]`

```ini
regex_label["/FACTURE CARTE DU \d{6}\s*/"]="CB "
regex_label["/(PRLV SEPA.*)(ECH.*|MDT.*)$/"]="$1"
```

Expressions régulières appliquées, dans l'ordre, au libellé en majuscules de chaque
transaction importée.

!!! warning "Ne pas modifier sans migration"
    Le libellé nettoyé entre dans l'UUID des transactions. Changer ces règles fait
    réimporter les transactions existantes sous un autre UUID (doublons).
    Les instances installées avant la 1.5.0 peuvent encore avoir la règle
    `regex_label["/\s(CARTE.*|CARTE|CART|CAR|CA|C)$/"]=""` (fin des libellés de carte),
    retirée de l'exemple : la garder sur une instance qui a déjà des transactions.

## `[auth]`

| Clé | Description |
|---|---|
| `jwtsecret` | Secret HMAC des JWT (durée de validité : 30 jours), généré par l'assistant d'installation. Un administrateur le renouvelle depuis le portail (bandeau affiché si le secret est faible) ; le changer déconnecte tous les clients. |
| `password_salt` | Sel des anciens mots de passe SHA-256, utilisé seulement pour les migrer vers bcrypt à la connexion |
| `mfa_required` | `true` : double authentification (code d'une application TOTP) imposée à la connexion de tous les utilisateurs, sauf depuis un appareil mobile (iOS, Android) déjà enregistré ou un navigateur de confiance (6 mois) ; `false` par défaut. Modifiable dans **Administration › Paramètres** ; le repasser à `false` dans le fichier est le recours d'un administrateur qui a perdu son téléphone et ses codes de secours. Voir [Sécurité](securite.md#double-authentification-totp). |
| `sync_token` | Secret permettant au planificateur d'appeler `/api/bank/sync` sans JWT, dans l'en-tête `X-Sync-Token` (`?token=` dans l'URL : obsolète). Vide = seul un utilisateur connecté peut lancer la synchro. |

## `[woob]`

| Clé | Description |
|---|---|
| `woob_path` | Commande woob : binaire local (`/usr/bin/woob`), conteneur (`docker run -v …:/root/ ltoinel/woob:3.7 woob`) ou, dans l'image Docker, `env HOME=/data/woob woob` (variable `CARBURE_WOOB_PATH`) |
| `woob_transactions` ✏️ | Nombre d'opérations demandées par appel (`--count`) |
| `woob_logging` ✏️ | Niveau de log de woob, sur sa sortie d'erreur (jamais dans un fichier) : `debug` (`--debug`), `info` (`--verbose`), `warning` (par défaut), `error`/`critical` (`--quiet`) |
| `woob_debug` ✏️ | `true` pour lancer woob en `--debug` (prioritaire sur `woob_logging`), journaliser sa sortie complète et l'afficher dans le journal de synchronisation du portail (administrateurs) |
| `woob_auto_update` ✏️ | `true` pour laisser woob mettre à jour ses modules (`--auto-update`, une fois le dépôt de woob approuvé, voir [Sécurité](securite.md)) |
| `woob_relabel` ✏️ | `false` par défaut. `true` : quand un module woob change le libellé de transactions déjà synchronisées (une correction du module), la synchronisation donne le nouveau libellé à la transaction enregistrée au lieu de l'enregistrer une seconde fois. Une transaction reçue au libellé inconnu remplace la seule transaction du même compte, de même date réelle et de même montant que la banque n'envoie plus ; catégorie, tags et pointage sont gardés. S'il y en a plusieurs (deux paiements du même montant le même jour), rien n'est deviné. Risque : deux vraies transactions distinctes du même montant le même jour, dont une seule est encore envoyée, seraient confondues |
| `woob_2fa_wait` ✏️ | Synchronisation planifiée : secondes d'attente (0 à 300, 15 par défaut) avant de resynchroniser un compte dont la banque demande une validation dans son application |
| `woob_2fa_retries` ✏️ | Synchronisation planifiée : nombre de nouvelles tentatives après cette demande (0 à 5, 1 par défaut ; 0 pour ne pas réessayer) |

## `[push]`

Les notifications push des applications mobiles (iOS et Android, voir [Applications mobiles](applications-mobiles.md)) passent
par **Carbure Push** (`https://api.carbure.org`, toujours ce service : pas de réglage
d'adresse), un service externe qui détient les identifiants des fournisseurs (clé
APNs d'Apple, compte de service Firebase) : l'instance n'en a besoin d'aucun. Elle s'y
inscrit une fois (bouton **Inscrire l'instance** de **Administration → Push mobile**, qui
résout une preuve de travail et enregistre la clé obtenue ici) puis lui envoie chaque
notification avec sa clé d'API.

**Consentement** : avant l'inscription, une fenêtre explique ce que l'instance confie au
relais (son adresse ; les jetons des appareils mobiles iOS et Android et le contenu des
notifications : titre et texte, par exemple montants et libellés des opérations, alertes
de budget ; le nom de l'instance à l'inscription), qu'il les transmet à Apple (APNs) et à
Google (Firebase Cloud Messaging), et ce qu'il en garde selon sa documentation : il ne
journalise pas le texte des notifications et ne conserve que l'empreinte (SHA-256) des
jetons. L'administrateur doit cocher « J'ai compris et j'accepte… » pour inscrire
l'instance ; l'API refuse l'inscription sans ce consentement. Il est enregistré avec sa
date et l'adresse du relais (affichés sous le bouton). Pour arrêter, videz `push_api_key` : plus aucune notification
n'est envoyée au relais.

| Clé | Description |
|---|---|
| `push_api_key` ✏️ | Clé d'API de l'instance (`cpk_<id>_<secret>`) : un secret, jamais journalisé ; masquée dans son champ, affichée aux administrateurs dans **Administration → Push mobile** (identifiant public seul, en entier avec **Afficher la clé d'API**, bouton **Copier**) ; renseignée par **Inscrire l'instance** (ou collée). Vide : **aucune notification push n'est envoyée** (journalisé en info, le profil des utilisateurs l'indique) |

Un ancien fichier qui contient encore `push_url` reste valable : ce réglage n'est plus lu
ni affiché.

L'onglet **Push mobile** affiche aussi l'**état du service** : le relais répond-il
(`GET /v1/health`, avec sa version) et accepte-t-il la clé de l'instance
(`GET /v1/keys/current` : valide avec ses appareils et ses notifications du jour, refusée
si elle est révoquée). Le bouton **Renouveler la clé** remplace une clé (fuitée par
exemple) par une nouvelle, les appareils continuant de recevoir les notifications.
**Envoyer une notification de test** l'envoie aux appareils de l'administrateur. Les appareils que le service déclare
désinscrits (application supprimée, jeton expiré) sont oubliés.

Une ancienne section `[apns]` est ignorée (affichée en lecture seule) : elle peut être
supprimée, comme la clé `.p8` de `data/conf/certs/`.

## `[smtp]`

Serveur d'envoi des notifications par email (chaque utilisateur choisit ensuite, dans son
profil, les notifications qu'il reçoit par email). Sans `smtp_host` ou sans `smtp_from`,
aucun email n'est envoyé. Le client SMTP est intégré à Carbure (aucune dépendance).

| Clé | Description |
|---|---|
| `smtp_host` ✏️ | Nom du serveur SMTP (vide = emails désactivés) |
| `smtp_port` ✏️ | Port : `587` (STARTTLS), `465` (TLS) ou `25` |
| `smtp_encryption` ✏️ | `none`, `starttls` (passage en TLS après la connexion) ou `tls` (TLS dès la connexion) ; le certificat du serveur est toujours vérifié |
| `smtp_auth` ✏️ | `none`, `plain` ou `login` |
| `smtp_username` ✏️ | Identifiant du compte SMTP |
| `smtp_password` ✏️ | Mot de passe du compte SMTP : secret, jamais affiché ni journalisé ; dans le portail, on saisit seulement un nouveau mot de passe pour le remplacer (sans `"`, `\`, `$` ni retour à la ligne) |
| `smtp_from` ✏️ | Adresse d'expédition des emails |
| `smtp_from_name` ✏️ | Nom de l'expéditeur (`Carbure` par défaut) |

Le bouton **Envoyer un email de test** de **Administration → Paramètres** vérifie la
configuration enregistrée : l'email part vers l'adresse de l'administrateur (ou celle
saisie) et la réponse du serveur s'affiche en cas d'échec.

## Réglages en base

Ces réglages, modifiés depuis le portail, sont enregistrés dans la table `settings`
(`name`, `value`) et non dans le fichier :

| Réglage | Valeurs | Modifié dans |
|---|---|---|
| `mcp_enabled` | `1` / `0` (désactivé par défaut) | Onglet **Agent IA**, interrupteur **Serveur MCP activé** (administrateur) |

## Variables d'environnement

| Variable | Rôle |
|---|---|
| `APP_ENV` | Nom du fichier de configuration (`prod` par défaut) |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `LANGUAGE`, `CARBURE_WOOB_PATH` | Valeurs proposées par l'assistant d'installation (Docker) ; le mot de passe de l'environnement n'est utilisé que pour cette base |
| `SYNC_INTERVAL` | Docker : intervalle de la synchronisation automatique, en secondes |
| `TRUSTED_PROXIES` | Docker : proxys inversés de confiance pour l'adresse du client (`X-Forwarded-For`), voir [Limitation des connexions](securite.md#limitation-des-connexions) |
