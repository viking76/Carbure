---
title: "Installation avec Docker : budget familial auto-hébergé"
description: "Installer Carbure avec Docker : image nginx, PHP-FPM et woob, base MariaDB, assistant d'installation dans le navigateur et mises à jour automatiques de la base."
icon: material/download-outline
---

# Installation

Carbure s'installe **entièrement depuis le navigateur** : au premier lancement, le portail
affiche un assistant qui vérifie la base de données, crée (ou met à jour) ses tables et le
compte administrateur. Aucune commande n'est nécessaire.

- **Docker** (recommandé) : tout est inclus (nginx, PHP, woob) et la base de données est
  préparée par `docker/docker-compose.yml`.
- **NAS Synology** : le [tutoriel Synology](synology.md), sans ligne de commande, avec
  Container Manager et MariaDB 10.
- **YunoHost** : le [tutoriel YunoHost](yunohost.md), avec le paquet `carbure_ynh`, sans
  Docker ni assistant.
- **Serveur web existant** (PHP + MariaDB/MySQL), sans Docker.

Avant d'installer, découvrez les [fonctionnalités](fonctionnalites.md) de Carbure et son
[comparatif](comparatif.md) avec les autres gestionnaires de budget à héberger soi-même.

## Essayer Carbure (démo)

Le plus simple : la **démo en ligne** sur [demo.carbure.org](https://demo.carbure.org/),
avec le compte `demo` / `carbure` et des données fictives.

Pour la lancer chez vous, en une commande, sans base de données à préparer ni installation :

```bash
docker run --rm -p 8080:80 ltoinel/carbure:demo
```

Ouvrez `http://localhost:8080/portal/` et connectez-vous avec `demo` / `carbure`
(utilisateur), rappelés sur l'écran de connexion. Le compte administrateur `admin` reçoit un
mot de passe aléatoire, nouveau à chaque démarrage, affiché dans la sortie du conteneur
(`docker logs <conteneur>` s'il tourne en arrière-plan) :

```text
 User:          demo / carbure
 Administrator: admin / 3f9c1a7e5b2d8046   (new password at each start)
```

Un foyer fictif y vit depuis un an : deux salaires, une vingtaine de catégories avec leurs budgets et leurs règles, des
dépenses saisonnières, de l'épargne.

L'image de démonstration est l'image habituelle avec MariaDB et les données de démonstration
à l'intérieur : à chaque démarrage, la base est recréée, la configuration est écrite avec des
secrets aléatoires et les données sont rechargées. Elles couvrent les 12 mois qui finissent
aujourd'hui et sont rechargées chaque nuit (au changement de date du conteneur, en UTC par
défaut) : une démo qui tourne depuis des jours garde un mois courant rempli jusqu'au jour
même, sans redémarrage. Le mot de passe `admin` ne change qu'au démarrage. `Ctrl+C` (ou
`docker stop`) l'arrête.

!!! warning "Uniquement pour essayer"
    **Toutes les données sont effacées à chaque démarrage et chaque nuit** et le mot de passe du compte `demo` est public :
    n'y saisissez aucune donnée réelle et ne la publiez sur Internet qu'avec les précautions
    ci-dessous. La synchronisation
    bancaire et les notifications ne sont pas configurées. Pour votre budget, installez
    Carbure avec l'image `ltoinel/carbure` (ci-dessous). Par sécurité, l'image de démonstration
    refuse de démarrer sur un volume `/data` d'une installation réelle.

Les utilisateurs de la démonstration sont en français ; `-e LANGUAGE=en` (ou `es`, `it`,
`de`, `pt`) choisit une autre langue. Chaque version a aussi son image de démonstration :
`ltoinel/carbure:<version>-demo` (par exemple `1.6.0-demo`).

### Publier une démo

Pour une démo en ligne, `docker/demo/docker-compose.yml` lance l'image durcie : système de
fichiers en lecture seule (tout ce qui est écrit va en mémoire, effacé à chaque démarrage),
aucune élévation de privilèges, seules les capacités nécessaires à MariaDB, PHP-FPM et nginx,
port publié sur `127.0.0.1` uniquement :

```bash
docker compose -f docker/demo/docker-compose.yml up -d
docker compose -f docker/demo/docker-compose.yml logs carbure | grep Administrator
```

`CARBURE_DEMO_PORT` (8080 par défaut) et `CARBURE_DEMO_LANGUAGE` (`fr` par défaut) se règlent
dans l'environnement ou un fichier `.env` à côté. Placez devant un proxy inverse en HTTPS
qui transmet tout au conteneur, sans réécrire ses en-têtes de sécurité (ils dépendent du
chemin : portail, Swagger, API), et sans tampon pour l'API (suivi en direct) :

```nginx
location / {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
}
location /api/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto https;
    proxy_buffering off;
    proxy_read_timeout 3600s;
}
```

Les visiteurs peuvent modifier les données : le rechargement de chaque nuit les remet à neuf,
un redémarrage régulier change aussi le mot de passe `admin`. Le script `docker/demo/run.sh` (à copier à côté du fichier
compose sur le serveur) démarre la démo, la met à jour quand une nouvelle image est publiée
(le conteneur n'est recréé que si l'image a changé), avec `--reset` la recrée avec des
données neuves, et affiche le mot de passe `admin`. Par exemple dans la crontab :

```cron
0 * * * * /srv/carbure-demo/run.sh >> /var/log/carbure-demo.log 2>&1
0 4 * * * /srv/carbure-demo/run.sh --reset >> /var/log/carbure-demo.log 2>&1
```

Le journal contient le mot de passe `admin` : gardez-le lisible par vous seul.

## Avec Docker (recommandé)

```bash
git clone https://github.com/ltoinel/Carbure.git && cd Carbure/docker
docker compose pull && docker compose up -d
```

`docker compose pull` télécharge l'image publiée de la
[dernière release](#recuperer-limage) : sans lui, Compose construirait l'image à partir du code
du dépôt (`docker/docker-compose.yml` sert aussi aux tests).

Ouvrez `http://<hôte>:8080/` : l'assistant d'installation s'affiche, déjà rempli avec la
base de données de `docker/docker-compose.yml`. Cliquez sur **Continuer**, choisissez le mot de
passe administrateur, puis **Installer**. C'est tout.

Le port `8080` est déjà pris (NAS, autre service) ? Choisissez-en un autre dans `docker/.env`,
par exemple `CARBURE_PORT=8090`, puis relancez `docker compose up -d`.

Sur une base neuve, l'assistant propose aussi, pour bien démarrer, des **catégories** courantes
avec leurs **règles** de catégorisation (enseignes, fournisseurs d'énergie, opérateurs…) et des
**insights** (dépenses, revenus, reste du mois, épargne, dépassement du budget…). Ces options
sont cochées par défaut ; tout reste modifiable ensuite. Leur contenu est dans
`sql/starter.json`.

Ajoutez ensuite vos banques depuis l'onglet **Comptes** du portail (bouton **+**) :
choisissez la banque, saisissez les identifiants demandés, puis les comptes à suivre. Les
identifiants sont confiés à woob, qui les conserve sur votre serveur ; Carbure ne les
enregistre pas.

Les réglages de l'instance (niveau et conservation des logs, woob, notifications push mobiles, serveur
SMTP des emails…) se consultent et se modifient ensuite dans le portail, onglet **Administration →
Paramètres** : voir [Configuration](configuration.md).

Pour choisir vous-même le mot de passe de la base, créez un fichier `docker/.env` à côté de
`docker/docker-compose.yml` **avant** le premier démarrage :

```bash
DB_PASSWORD=un-mot-de-passe-solide
```

| Variable | Rôle | Défaut |
|---|---|---|
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | Base de données proposée par l'assistant (le mot de passe n'a pas à être ressaisi) | `db`, `3306`, `carbure`, `carbure` |
| `LANGUAGE` | Langue proposée pour l'administrateur (`fr`, `en`, `es`, `it`, `de`, `pt`) | `fr` |
| `SYNC_INTERVAL` | Synchronisation automatique de tous les comptes toutes les N secondes (`86400` = une fois par jour) | désactivée |
| `TRUSTED_PROXIES` | Proxys inversés de confiance (adresses ou réseaux CIDR) dont l'en-tête `X-Forwarded-For` donne l'adresse du client, pour la [limitation des connexions](securite.md#limitation-des-connexions) et les journaux ; `none` : aucun (Carbure publié sans proxy) | réseaux privés et boucle locale |
| `CARBURE_WOOB_PATH` | Commande woob écrite dans la configuration par l'assistant ; l'image la définit déjà (`env HOME=/data/woob woob`) | `woob` |

Le dossier `data/` du projet, monté sur `/data` dans le conteneur, conserve tout ce qui est
propre à votre instance : la configuration écrite par l'assistant (`data/conf/prod.ini`, avec
des secrets aléatoires), les logs (`data/logs`) et la
configuration woob avec vos banques (`data/woob`). C'est le seul dossier à sauvegarder, avec
la base de données. Aucun mot de passe administrateur n'est passé par
l'environnement : il est choisi dans l'assistant.

L'image contient nginx (portail, Swagger et API sur le port 80), PHP-FPM et woob. Son
`HEALTHCHECK` interroge `GET /api/health` toutes les 5 minutes (toutes les 10 secondes
pendant les 2 premières minutes, avec Docker 25 ou plus) : `docker ps` affiche `healthy`
quand la base répond.

!!! note "Accès depuis Internet"
    Depuis votre réseau local, l'assistant est directement accessible. Si Carbure est
    appelé depuis une adresse publique avant d'être installé, l'assistant demande un
    **code d'installation**, affiché dans les journaux du conteneur
    (`docker compose logs carbure`) et écrit dans `/data/conf/setup.code`.

    Derrière un proxy inversé (proxy du NAS, Traefik, Caddy…), les requêtes semblent
    venir du proxy, donc du réseau local : **installez Carbure avant de l'exposer sur
    Internet**, ou imposez le code en créant au préalable `/data/conf/setup.code`
    contenant un code de votre choix.

### Récupérer l'image

L'image est publiée sur [Docker Hub](https://hub.docker.com/r/ltoinel/carbure) à chaque release, pour `amd64` et `arm64`
(NAS Synology Intel comme ARM) :

```bash
docker pull ltoinel/carbure:latest
```

| Tag | Contenu |
|---|---|
| `latest` | Dernière release |
| `1.2.0` | Une version précise (recommandé en production, pour maîtriser les mises à jour) |
| `1.2` | Dernière version corrective de la 1.2 |

Sans `docker compose`, avec une base MariaDB/MySQL existante (la base et son utilisateur
doivent déjà exister ; l'assistant les demande au premier lancement) :

```bash
docker run -d --name carbure --restart unless-stopped -p 8080:80 \
  -e SYNC_INTERVAL=86400 -v carbure-data:/data \
  ltoinel/carbure:latest
```

### Mettre à jour

```bash
docker compose pull && docker compose up -d
```

Le volume `/data` est conservé. À chaque démarrage, le conteneur met la base de données à
jour automatiquement (les migrations de la nouvelle version sont appliquées) et s'arrête
avec un message explicite si l'une d'elles échoue. Pensez à sauvegarder la base avant une
mise à jour.

Quand `docker compose pull` apporte une nouvelle version majeure de MariaDB (par exemple de
11 à 13), le conteneur `db` met à niveau la base existante à son démarrage
(`MARIADB_AUTO_UPGRADE`). Ce passage est sans retour : la base ne peut plus être ouverte par
l'ancienne version, d'où l'importance de la sauvegarde. Pour garder une version donnée,
fixez-la dans `docker/docker-compose.yml` (`image: mariadb:11`).

Ce qui change d'une version à l'autre, et ce qu'il faut faire en mettant à jour
(migrations, réglages), est dans le [changelog](changelog.md).

### Notifications push (Carbure Push)

Les notifications des applications iOS et Android passent par le service externe Carbure Push :
dans **Administration → Push mobile**, vérifiez que le relais est opérationnel,
cliquez sur **Inscrire l'instance**, lisez et acceptez le consentement (les jetons des
appareils et le contenu des notifications passent par ce relais, qui les transmet à Apple
et à Google ; la clé d'API obtenue est enregistrée dans `/data/conf/prod.ini`), puis sur
**Envoyer une notification de test** (l'état de la clé passe à « Valide »). Sans clé, aucune
notification push n'est envoyée. Voir [Configuration](configuration.md#push).

!!! warning "Mise à jour depuis une version qui envoyait elle-même les notifications (APNs)"
    La section `[apns]` et la clé `.p8` ne sont plus utilisées. **Inscrivez l'instance
    (ou renseignez `push_api_key`) avant ou juste après la mise à jour**, sinon les
    notifications push s'arrêtent. La migration `2026-10-23_device_platform` (plateforme
    des appareils) est appliquée au démarrage du conteneur.

### Notifications par email (SMTP)

Pour envoyer aussi les notifications par email, renseignez le serveur SMTP (hôte, port,
chiffrement, identifiants, expéditeur) dans **Administration → Paramètres**, section
**Emails (SMTP)**, puis vérifiez-le avec **Envoyer un email de test** (voir
[Configuration](configuration.md#smtp)). Chaque utilisateur choisit ensuite, dans **Mon
profil → Notifications**, les notifications qu'il reçoit par email ; son profil doit avoir
une adresse email.

### Maintenance

```bash
docker compose logs -f carbure                                         # journaux nginx, PHP et démarrage
docker compose exec carbure ls /data/logs                              # logs de Carbure
docker compose exec -u www-data carbure php tools/migrate.php --status # version du schéma de la base
```

!!! note "Modules woob"
    woob télécharge ses modules (dont celui de votre banque) dans `/data/woob` et les met
    à jour automatiquement. Un correctif de module pas encore publié (par exemple pour la
    BNP) peut être copié dans `/data/woob/.local/share/woob/modules/3.7/woob_modules/` ;
    woob n'installe jamais les paquets Python dont un module a besoin (il refuse de le
    charger) : l'image fournit ceux des modules bancaires, `curl_cffi` (BNP),
    `python-jose` et `chompjs` (Banque Populaire, Caisse d'Épargne, BTP Banque, Crédit
    Coopératif), `schwifty` (Société Générale) et `pycryptodomex` (épargne salariale HSBC,
    Esalia, La Nef). Les modules qui pilotent un vrai navigateur (American Express,
    Sogecartenet, HSBC Hong Kong) ne sont pas pris en charge : utilisez l'import de relevés.

## Sur un NAS Synology

Le [tutoriel NAS Synology](synology.md) installe Carbure avec **Container Manager** et la
base **MariaDB 10** du NAS, entièrement depuis l'interface de DSM, sans ligne de commande :
préparation de la base, conteneur, assistant, banques, synchronisation quotidienne avec le
**Planificateur de tâches**, accès HTTPS par le proxy inversé, sécurisation, mises à jour et
sauvegardes Hyper Backup.

## Avec YunoHost

Le [tutoriel YunoHost](yunohost.md) installe Carbure avec le paquet
[`carbure_ynh`](https://github.com/ltoinel/carbure_ynh), depuis l'interface
d'administration de YunoHost : un domaine dédié, l'administrateur créé à l'installation
(sans assistant), woob et la synchronisation quotidienne inclus, les mises à jour et les
sauvegardes de YunoHost.

## Sur un serveur web existant

### Prérequis

- PHP **8.2** ou plus avec `mysqli`, `curl`, `openssl` et `json` ; APCu recommandé (cache
  des routes).
- MariaDB ≥ 10.3 ou MySQL 8, avec une base et un utilisateur pour Carbure.
- Un serveur web capable de transmettre `/api/` à PHP-FPM, par exemple nginx (exemple
  ci-dessous). Carbure ne fournit pas de fichier `.htaccess` : la protection des dossiers
  sensibles est à configurer dans le serveur.
- [woob](https://woob.tech/) installé pour l'utilisateur du serveur web, avec les paquets
  Python des modules bancaires, que woob n'installe pas lui-même :
  `pip install "curl_cffi>=0.7" python-jose chompjs schwifty pycryptodomex` (BNP, groupe
  BPCE, Société Générale, épargne salariale).
- Pour les notifications push : un accès HTTPS sortant vers le service Carbure Push
  (`https://api.carbure.org` ; aucune clé Apple ni Firebase n'est nécessaire). Facultatif : un serveur SMTP pour les
  notifications par email.

### 1. Copier Carbure

Copiez l'archive d'une release (`carbure-vX.Y.Z.tar.gz` : `src`, `portal`, `swagger`,
`sql`, `tools/migrate.php`, `conf/prod.sample.ini` et le fichier
`VERSION` affiché dans le pied de page du portail) ou le contenu du dépôt dans le dossier
du site, par exemple `/var/www/carbure`. Le dossier `conf/` doit être **modifiable par le serveur web** :
l'assistant y écrit la configuration.

### 2. Configurer le serveur web

- Les URL `/api/…` sont envoyées à `src/api.php` (le préfixe `/api` est retiré par l'API).
- `portal/` est servi en fichiers statiques (par exemple sous `/portal/`).
- `swagger/` peut être servi pour consulter la spécification OpenAPI.
- `data/`, `conf/`, `sql/`, `tests/` et `tools/` ne doivent **pas** être exposés.
- Utiliser HTTPS (nécessaire aussi pour [installer le portail comme une application](portail.md#installer-le-portail-comme-une-application)).
  Servir `.webmanifest` avec le type `application/manifest+json` (voir `docker/nginx.conf`).

Exemple nginx (celui de l'image Docker est dans `docker/nginx.conf`) :

```nginx
location = / { return 302 /portal/; }
location /portal/ { root /var/www/carbure; }
location /api/ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /var/www/carbure/src/api.php;
    fastcgi_pass unix:/run/php/php-fpm.sock;
    fastcgi_read_timeout 3600;   # la synchronisation peut être longue
    fastcgi_buffering off;       # flux SSE
}
location ~ ^/(data|conf|sql|tests|tools|docker)/ { deny all; }
```

### 3. Lancer l'assistant

Ouvrez le portail (`https://<votre-serveur>/portal/`) : l'assistant demande la base de
données, crée ses tables (ou met à jour une base Carbure existante, après confirmation
d'une sauvegarde), le compte administrateur et, sur une base neuve, le pack de départ
facultatif (catégories, règles, insights), puis écrit `data/conf/prod.ini` avec des
secrets aléatoires (`jwtsecret`, `sync_token`…). La commande woob y est `woob` : adaptez
`woob_path` si woob est installé ailleurs (voir [Configuration](configuration.md#woob)).

Depuis une adresse publique, l'assistant demande le code d'installation écrit dans
`data/conf/setup.code` (et dans le journal d'erreurs PHP). Voir
[Sécurité](securite.md#assistant-dinstallation).

!!! tip "Installer sans l'assistant (script)"
    `tools/install.php` fait la même installation en ligne de commande, pour un script
    (paquet d'une distribution, YunoHost…). Les mots de passe passent par l'environnement,
    jamais par la ligne de commande (visible dans la liste des processus) :

    ```bash
    CARBURE_DB_PASSWORD='…' CARBURE_ADMIN_PASSWORD='…' \
      php tools/install.php --db-name=carbure --db-user=carbure --admin=marie \
        --email=marie@example.org --language=fr --starter
    ```

    Lancez-le avec l'utilisateur du serveur web, qui doit pouvoir écrire dans `data/`.
    Code de sortie : `0` installé, `1` échec (rien n'est écrit), `2` déjà installé
    (`data/conf/prod.ini` existe : rien ne change). `php tools/install.php --help` liste
    les options (`--db-host`, `--db-port`, `--woob-path`, `--public-url`, `--backup-confirmed` pour migrer
    une base existante…).

### 4. Ajouter une banque

Depuis l'onglet **Comptes** du portail (bouton **+**) : choisissez la banque parmi celles
que woob supporte, saisissez les identifiants demandés (confiés à woob, qui les conserve ;
Carbure ne les enregistre pas), puis les comptes à suivre. woob doit être installé pour
l'utilisateur du serveur web.

### 5. Planifier la synchronisation

La clé `sync_token` de `data/conf/prod.ini` permet d'appeler la synchronisation sans compte.
L'onglet **Administration → Synchro** du portail donne la commande complète à copier
(et crée le jeton s'il manque) :

```bash
# crontab : tous les jours à 7h
0 7 * * * curl -sN -H "X-Sync-Token: <sync_token>" https://exemple.fr/api/bank/sync > /dev/null
# ou avec wget
0 7 * * * wget -qO /dev/null --header="X-Sync-Token: <sync_token>" https://exemple.fr/api/bank/sync
```

!!! warning "Migration : le jeton dans l'URL est obsolète"
    Une tâche planifiée qui appelle encore `https://exemple.fr/api/bank/sync?token=<sync_token>`
    fonctionne toujours, mais le jeton passé dans l'URL est **obsolète** (il finit dans les
    logs des proxys et l'historique du shell) : un avertissement est journalisé à chaque
    appel. Retirez `?token=…` de l'URL et passez le jeton dans l'en-tête `X-Sync-Token`,
    comme ci-dessus.

### Mettre à jour

Remplacez les fichiers de Carbure en gardant le dossier `data/` (configuration, logs, banques). Si la nouvelle version
modifie la base de données, un bandeau **Mise à jour de la base de données** s'affiche pour
l'administrateur dans le portail : sauvegardez la base, puis cliquez sur **Mettre à jour**.
Les migrations déjà appliquées à la main (phpMyAdmin) sont reconnues.

En ligne de commande, `php tools/migrate.php --status` affiche la version du schéma et
`php tools/migrate.php` applique les migrations.

Après un déploiement qui modifie les routes, le cache APCu est invalidé automatiquement
(signature des fichiers de `src/resources/`, version de la clé du cache).

## En cas d'échec de la synchronisation

Dans l'onglet **Comptes**, chaque étape en échec affiche la cause renvoyée par woob, y compris
quand woob ne renvoie aucun compte ni opération mais signale une erreur (par exemple
`Bug(bnp): 403 Client Error: Forbidden` : le site de la banque refuse woob) : le compte est
alors en erreur, pas « 0 nouvelle opération ». Les sites
des banques changent régulièrement : un module woob peut cesser de fonctionner du jour au
lendemain. Avant toute chose, vérifiez si le problème est connu ou en cours de correction dans
les tickets woob, en cherchant le nom du module de votre banque (le portail propose
directement ce lien) :

```
https://gitlab.com/search?group_id=11540390&project_id=25520182&scope=work_items&search=<module>&sort=created_desc
```

Remplacez `<module>` par le nom du module woob (par exemple `bnp`, `creditmutuel`,
`boursorama`). Une fois le correctif publié, mettez à jour les modules bancaires depuis
l'onglet **Comptes** (bouton **Mettre à jour les modules bancaires**, administrateur ; la
première fois, il fait approuver la clé du dépôt de woob si ce n'est pas la clé officielle,
approuvée d'elle-même au premier démarrage du conteneur, voir [Sécurité](securite.md)),
ou redémarrez le conteneur Docker : une fois le dépôt approuvé, `woob config update` est
lancé à chaque démarrage et met à jour tous les modules bancaires. L'option
`woob_auto_update=true` ne suffit pas : woob ne propose une nouvelle version d'un module
qu'après un plantage de ce module, et Carbure ne peut pas répondre à sa question.

## Besoin d'aide ?

Une question sur l'installation, la configuration ou l'utilisation ? Voyez d'abord la
[FAQ](faq.md), puis posez-la sur le [serveur Discord](https://discord.gg/QsxYw4Rx9D)
(voir [Assistance](assistance.md)). Réservez les [issues](https://github.com/ltoinel/Carbure/issues) aux bugs
avérés, et signalez une faille de sécurité en privé (voir [Sécurité](securite.md)).
