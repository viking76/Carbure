---
title: "Questions fréquentes"
description: "FAQ de Carbure : prix, banques prises en charge, synchronisation, import, budgets, applications mobiles, installation, sécurité et sauvegarde."
icon: material/frequently-asked-questions
---

# Questions fréquentes

Une question qui n'est pas ici ? Posez-la sur le
[serveur Discord](https://discord.gg/QsxYw4Rx9D) (voir [Assistance](assistance.md)).

## Découvrir Carbure

??? question "Carbure est-il gratuit ?"
    Oui. Carbure est **gratuit et open source** (licence MIT) : le code est sur
    [GitHub](https://github.com/ltoinel/Carbure). Il n'y a ni abonnement, ni publicité, ni
    version payante, et les applications mobiles sont gratuites elles aussi. Vous
    l'hébergez vous-même : le seul coût est celui de la machine qui le fait tourner (un
    NAS, un mini-PC, un serveur).

??? question "Puis-je l'essayer sans rien installer ?"
    Oui, sur la **démo en ligne** [demo.carbure.org](https://demo.carbure.org/) avec le
    compte `demo` / `carbure` et des données fictives, ou chez vous en une commande :
    `docker run --rm -p 8080:80 ltoinel/carbure:demo` (voir
    [Installation](installation.md#essayer-carbure-demo)). L'image de démonstration efface
    ses données à chaque démarrage : elle ne sert jamais pour de vraies données.

??? question "Quelle différence avec les autres applications de budget ?"
    Vos données restent **chez vous** : Carbure ne passe par aucun agrégateur bancaire
    commercial, et l'éditeur n'a accès ni à vos comptes ni à vos transactions. Le
    [Comparatif](comparatif.md) le situe face aux applications bancaires, aux agrégateurs
    et aux autres outils auto-hébergés (Firefly III, Actual Budget, Kresus).

??? question "Pour qui est fait Carbure ?"
    Pour **un foyer** : une installation, des comptes bancaires communs et les personnes
    qui les suivent ensemble. Les comptes, les transactions, les catégories, les règles et
    les budgets sont partagés ; chacun garde son identifiant, sa langue, son thème, ses
    alertes et ses appareils. Pour deux foyers séparés, faites deux installations. Voir
    [Le foyer](fonctionnalites.md#le-foyer).

??? question "En quelles langues ?"
    Le portail et les notifications existent en **français, anglais, espagnol, italien,
    allemand et portugais**, au choix de chaque utilisateur. Les applications mobiles sont en
    français et en anglais (la langue du téléphone).

??? question "Carbure gère-t-il une autre devise que l'euro ?"
    Une seule devise par installation : le réglage `currency` (**Administration →
    Paramètres**, code ISO 4217 : `EUR`, `CHF`, `USD`…) change son affichage dans le
    portail, les notifications et les e-mails. Aucun montant n'est converti, et les
    applications mobiles affichent toujours des euros. Plusieurs devises à la fois ne sont
    pas gérées.

## Banques et synchronisation

??? question "Quelles banques sont prises en charge ?"
    Les banques prises en charge par [woob](https://woob.tech/), le projet open source que
    Carbure utilise pour récupérer les opérations : surtout les **banques françaises**.
    Carbure se connecte directement au site de votre banque, sans agrégateur tiers. Une
    banque absente ? Importez ses relevés (question suivante).

??? question "Ma banque n'est pas couverte, comment faire ?"
    Téléchargez le relevé sur le site de la banque (**OFX/QFX**, **QIF**, **CSV** ou
    **CAMT.053**) et importez-le dans l'onglet **Transactions**. L'aperçu signale les
    transactions déjà connues et les doublons probables avant l'enregistrement. Les exports
    CSV d'une dizaine de banques (BoursoBank, Crédit Agricole, La Banque Postale, Société
    Générale, BNP Paribas, N26, Revolut, Qonto…) sont reconnus ; pour une autre banque,
    préférez l'OFX. Voir [Import de relevés](fonctionnalites.md#import-de-releves-bancaires).
    L'import sert aussi à reprendre l'historique d'avant Carbure.

??? question "Où sont gardés mes identifiants bancaires ?"
    Chez **woob**, sur votre serveur, dans son propre fichier lisible par lui seul :
    Carbure ne les enregistre pas dans sa base et ne les écrit jamais dans les logs.
    Supprimer une connexion bancaire les fait oublier à woob.

??? question "Puis-je suivre plusieurs comptes ou un compte joint ?"
    Oui : une banque peut avoir **plusieurs connexions** (compte personnel, joint,
    professionnel…), chacune avec ses identifiants et un nom lisible, et chaque connexion
    autant de comptes suivis que woob en trouve. Ne plus suivre un compte conserve ses
    transactions déjà importées.

??? question "Ma banque demande une validation sur mon téléphone, comment faire ?"
    Lancez la synchronisation depuis le portail : la demande de validation
    (authentification forte) s'y affiche, et la synchronisation attend que vous validiez
    dans l'application de votre banque ou saisissiez le code reçu. La synchronisation
    planifiée ne la déclenche jamais : elle réessaie plus tard (réglages `woob_2fa_wait` et
    `woob_2fa_retries`). À la connexion d'une banque, c'est la liste de ses comptes qui
    attend la validation.

??? question "J'ai connecté ma banque avec woob dans le terminal du conteneur, le portail ne la voit pas"
    Dans l'image Docker, Carbure lance woob en `www-data` avec sa configuration dans
    `/data/woob`. Un `docker exec` lancé tel quel tourne en root avec une autre
    configuration, invisible de Carbure. Lancez woob comme Carbure :
    `docker compose exec -u www-data carbure env HOME=/data/woob woob bank list`. Le plus
    simple reste de connecter la banque depuis le portail, qui gère l'authentification
    forte.

??? question "Les comptes se synchronisent-ils tout seuls ?"
    Oui, si vous planifiez la synchronisation : avec Docker, la variable `SYNC_INTERVAL`
    (`86400` = une fois par jour) ; sinon, la commande `curl` ou la ligne crontab affichée
    dans **Administration → Synchro**. Un administrateur peut aussi synchroniser depuis
    l'onglet **Comptes** à tout moment. Voir
    [Planifier la synchronisation](installation.md#5-planifier-la-synchronisation).

??? question "Ma banque n'affiche aucun champ à la connexion"
    Le portail affiche alors « woob cannot load the bank module … » : il manque un paquet
    Python dont le module a besoin, que woob n'installe jamais lui-même. L'image Docker
    fournit ceux des modules bancaires depuis la 1.10.1 : mettez-la à jour. Sur une
    installation manuelle, installez le paquet cité dans l'environnement de woob (`pip
    install <paquet>`). Les modules qui pilotent un vrai navigateur (American Express,
    Sogecartenet) ne sont pas pris en charge : importez vos relevés.

??? question "La synchronisation échoue, que faire ?"
    L'onglet **Comptes** affiche la cause renvoyée par woob. Les sites des banques changent
    souvent et cassent leur module woob : vérifiez dans les tickets woob (le portail propose
    le lien) si le problème est connu, puis mettez à jour les modules bancaires depuis
    l'onglet **Comptes** une fois le correctif publié. Voir
    [En cas d'échec de la synchronisation](installation.md#en-cas-dechec-de-la-synchronisation).

??? question "Mes transactions synchronisées n'ont pas de libellé"
    Certains modules woob (Banque Populaire avec la nouvelle API de la banque) ne
    remplissent que le libellé simplifié de la transaction : avant la 1.10.1, Carbure ne
    lisait que le libellé brut et enregistrait ces transactions sans libellé. Depuis la
    1.10.1, la synchronisation lit l'un ou l'autre et complète les transactions déjà
    enregistrées sans libellé (même compte, même date et même montant), sans doublon : elles
    gardent leur catégorie et leur pointage. Celles plus anciennes que les transactions
    renvoyées par la banque restent sans libellé : recatégorisez-les ou supprimez-les depuis
    l'onglet **Transactions**.

## Transactions, catégories et budgets

??? question "Comment les transactions sont-elles catégorisées ?"
    Par des **règles** : un mot-clé associé à une catégorie (« CARREFOUR » → Supermarché).
    À chaque synchronisation, les règles classent les nouvelles transactions ; créer ou
    modifier une règle l'applique aussi à tout l'historique. Une transaction se
    recatégorise aussi à la main. L'installation propose un pack de départ d'une trentaine
    de catégories et d'une centaine de règles. Voir
    [Règles de catégorisation](fonctionnalites.md#regles-de-categorisation).

??? question "Que veut dire « pointer » une transaction ?"
    La marquer comme **vérifiée**, d'un clic sur le bouton rond à gauche de la
    transaction. Les transactions non vérifiées sont en gras dans le portail, qui peut
    n'afficher qu'elles, et leur nombre s'affiche sur le badge de l'application iPhone :
    vous repérez d'un coup d'œil ce que vous n'avez pas encore regardé.

??? question "Les virements entre mes comptes faussent-ils mes dépenses ?"
    Non, s'ils sont classés dans une catégorie **Hors budget** (virements internes,
    épargne) : ces mouvements ne comptent ni comme dépenses ni comme revenus dans les
    budgets, le flux du mois et les tendances. L'épargne reste visible à part.

??? question "Une opération peut-elle être importée deux fois ?"
    Non. Chaque opération reçoit une empreinte (date réelle, libellé nettoyé, montant) : une
    opération déjà connue n'est jamais enregistrée deux fois, même synchronisée plusieurs
    fois, par deux utilisateurs ou importée d'un relevé. Deux opérations vraiment identiques
    du même jour (deux cafés au même prix) sont bien gardées toutes les deux.

??? question "Mon salaire arrive en fin de mois : pour quel mois compte-t-il ?"
    Pour le **mois suivant**, celui qu'il finance : un virement reçu daté du **25 ou
    après** est rattaché au 1er du mois suivant (sa date réelle reste visible dans le
    détail). Le jour se règle dans **Administration → Paramètres** (`next_month_day`, de 1
    à 31, `0` pour ne jamais déplacer un virement) ; il s'applique aux transactions
    importées ou synchronisées ensuite. Voir
    [Configuration](configuration.md#global).

??? question "Carbure repère-t-il les abonnements et les prélèvements ?"
    Oui : les opérations qui reviennent chaque mois (salaire, loyer, abonnements,
    factures) portent le badge **Mensuelle**, et un interrupteur liste celles attendues ce
    mois-ci qui ne sont pas encore arrivées. Elles sont détectées, pas planifiées : pas
    d'échéancier ni de prévision du solde. Voir
    [Transactions récurrentes](fonctionnalites.md#transactions-recurrentes).

??? question "Comment fonctionnent les budgets ?"
    Un budget est un **montant par catégorie et par mois** ; une catégorie parente vaut la
    somme de ses sous-catégories, ou son propre montant. Chaque carte montre le reste ou le
    dépassement, et un bouton recopie les budgets du mois précédent quand le mois n'en a pas encore. Tous les utilisateurs
    peuvent régler les montants. Voir [Budgets](fonctionnalites.md#budgets).

??? question "Puis-je créer mes propres indicateurs ?"
    Oui, avec les **insights** : un indicateur du mois (courses, carburant, taux d'épargne,
    matelas de sécurité…) défini par une requête SQL en lecture seule, créé par un
    administrateur. Les [Recettes d'insights](insights.md) en proposent près de trente,
    prêtes à copier.

??? question "Carbure gère-t-il des objectifs d'épargne ?"
    Pas encore. L'onglet **Tendances** suit l'épargne versée et le taux d'épargne sur 3, 6
    ou 12 mois (catégorie « Épargne », ou celle du réglage `savings_category`), et l'insight
    **Matelas de sécurité** donne les dépenses de 6 mois. Voir aussi les
    [Limites connues](fonctionnalites.md#limites-connues).

## Notifications et alertes

??? question "Quelles notifications puis-je recevoir ?"
    Le résultat de chaque synchronisation, une **dépense importante** au-dessus de votre
    seuil, une transaction qui correspond à une règle « Envoyer une notification » (loyer,
    remboursement…) et un **budget atteint**. Chacun choisit, dans **Mon profil →
    Notifications**, le canal de chaque type : push sur l'application mobile, e-mail, les
    deux ou aucun. Voir [Notifications](fonctionnalites.md#notifications).

??? question "Comment être prévenu d'une grosse dépense ou d'un budget dépassé ?"
    Dans **Mon profil → Notifications** : le seuil **Dépense importante** (un montant ;
    vide = pas d'alerte), et pour les budgets un seuil de 50 à 200 % (100 % par défaut, 80 %
    pour être prévenu avant) sur tous les budgets, désactivable, plus des **seuils par
    catégorie** qui le remplacent (par exemple 80 % sur les courses). Une alerte de budget
    part une seule fois par catégorie et par mois. Voir
    [Alertes de budget](fonctionnalites.md#alertes-de-budget).

??? question "Puis-je recevoir les notifications par e-mail ?"
    Oui, si un administrateur configure un serveur SMTP (**Administration → Paramètres**,
    section `[smtp]`, avec un e-mail de test) et que votre profil a une adresse e-mail.
    Les e-mails partent de votre serveur. Voir [Configuration](configuration.md#smtp).

??? question "Pourquoi les notifications push passent-elles par Carbure Push ?"
    Apple et Google n'acceptent les notifications d'une application que depuis les
    identifiants de son éditeur : le relais **Carbure Push** (`api.carbure.org`) les
    détient, vous n'avez ni clé Apple ni compte Firebase à créer. Il transmet le titre et
    le texte des notifications sans les enregistrer, et ne connaît ni votre nom ni votre
    adresse e-mail. Un administrateur l'active dans **Administration → Push mobile**,
    après avoir accepté son consentement. Voir [Confidentialité](confidentialite.md).

## Applications mobiles

??? question "Y a-t-il une application mobile ?"
    Oui, des applications natives **iPhone et Android**, gratuites, sans publicité ni
    collecte de données, sur l'[App Store](https://apps.apple.com/fr/app/carbure/id6824057363)
    et sur [Google Play](https://play.google.com/store/apps/details?id=org.carbure.app) :
    transactions, pointage, budgets, aperçus, tendances, synchronisation, connexion par
    Face ID ou empreinte et notifications push. Il faut iOS 26 ou Android 8.0 au minimum.
    Voir [Applications mobiles](applications-mobiles.md).

??? question "Les applications sont-elles disponibles hors de France ?"
    Pas pour le moment : elles sont publiées en France sur l'App Store et Google Play. Le
    portail web, lui, s'utilise partout, et s'installe comme une application (voir plus
    bas).

??? question "Mon téléphone ne reçoit pas les notifications"
    Ouvrez **Mon profil → Mes appareils** : si le téléphone y est marqué « Notifications
    indisponibles », l'application s'est connectée sans jeton de notification. Autorisez
    les notifications de Carbure dans les réglages du téléphone, puis déconnectez-vous et
    reconnectez-vous dans l'application. S'il n'apparaît pas du tout, reconnectez-vous dans
    l'application avec votre compte. Un administrateur vérifie aussi que l'instance est
    enregistrée et envoie une notification de test dans **Administration → Push mobile**.

??? question "Comment connecter l'application à mon serveur ?"
    Dans le portail, **Mon profil → Mes appareils** affiche un QR code : scannez-le avec
    l'appareil photo du téléphone, l'application s'ouvre avec l'adresse de votre serveur
    déjà remplie. Vous pouvez aussi saisir l'adresse de l'API dans l'écran de connexion.
    Votre serveur doit être joignable depuis le téléphone, en HTTPS de préférence ;
    renseignez son adresse publique (`public_url`) s'il est derrière un proxy.

??? question "Que puis-je faire dans l'application, et que reste-t-il au portail ?"
    L'application sert au **suivi au quotidien** : consulter, pointer, catégoriser, ajuster
    un budget, lancer une synchronisation. L'administration (comptes bancaires, règles,
    catégories, insights, utilisateurs, paramètres) et l'import de relevés se font dans le
    portail. Ni l'application ni le portail n'ont de mode hors ligne. Voir les
    [limites de l'application](applications-mobiles.md#limites).

??? question "La double authentification fonctionne-t-elle dans l'application ?"
    Oui : l'application demande le code de votre application d'authentification (ou un
    code de secours), et peut même configurer la double authentification à la première
    connexion. Le code n'est plus demandé ensuite depuis ce téléphone. Voir
    [Double authentification](applications-mobiles.md#double-authentification).

??? question "Puis-je installer le portail comme une application ?"
    Oui, depuis Chrome ou Edge (bouton **Installer**), Safari sur Mac (**Fichier → Ajouter
    au Dock**) ou sur iPhone et Android (**Sur l'écran d'accueil**) : il s'ouvre dans sa
    propre fenêtre, avec l'icône de Carbure. Il faut que le portail soit servi en HTTPS.
    Voir [Installer le portail comme une application](portail.md#installer-le-portail-comme-une-application).

## Installation et mises à jour

??? question "De quoi ai-je besoin pour l'installer ?"
    Le plus simple : une machine avec **Docker** (serveur, mini-PC, NAS). Le fichier
    `docker/docker-compose.yml` lance l'image de production `ltoinel/carbure` et MariaDB ;
    un assistant dans le navigateur crée la base et le compte administrateur en trois
    clics. Sans Docker : PHP 8.2 ou plus, MariaDB ≥ 10.3 ou MySQL 8, un serveur web et
    woob. Voir [Installation](installation.md).

??? question "Fonctionne-t-il sur un NAS Synology ?"
    Oui, avec Container Manager et MariaDB 10, sur les NAS Intel comme ARM : le
    [tutoriel NAS Synology](synology.md) détaille chaque étape, jusqu'à l'accès HTTPS.

??? question "Peut-on l'installer sur YunoHost ?"
    Oui : le paquet [`carbure_ynh`](https://github.com/ltoinel/carbure_ynh) installe Carbure
    en quelques clics sur un serveur [YunoHost](https://yunohost.org), sur un domaine dédié,
    avec woob et une synchronisation quotidienne des comptes. Il n'est pas encore au catalogue de YunoHost : il
    s'installe depuis son dépôt, voir [le tutoriel YunoHost](yunohost.md). Un
    problème avec le paquet ? Ouvrez un ticket sur
    [son dépôt](https://github.com/ltoinel/carbure_ynh/issues).

??? question "Comment y accéder depuis l'extérieur ou depuis mon téléphone ?"
    Placez Carbure derrière un **proxy inverse en HTTPS** (celui du NAS, Caddy, Traefik,
    NGINX…) avec un nom de domaine, puis renseignez son adresse publique (`public_url`)
    dans **Administration → Paramètres**. Installez Carbure **avant** de l'exposer sur
    Internet : depuis une adresse publique, l'assistant demande un code d'installation.
    Voir [Installation](installation.md).

??? question "Comment mettre Carbure à jour ?"
    Avec Docker : `docker compose pull && docker compose up -d`. Les mises à jour de la
    base de données s'appliquent au démarrage du conteneur, ou en un clic depuis le
    bandeau affiché aux administrateurs. Sauvegardez la base et lisez les notes de la
    version avant une mise à jour majeure (voir
    [Mettre à jour](installation.md#mettre-a-jour)).

??? question "Quelle version de l'image Docker choisir ?"
    `ltoinel/carbure:latest` suit la dernière version ; une version précise
    (`ltoinel/carbure:1.9.1`) est recommandée en production pour choisir le moment des
    mises à jour. `ltoinel/carbure:demo` est la démonstration, avec ses données d'exemple :
    jamais pour vos vraies données. Voir [Récupérer l'image](installation.md#recuperer-limage).

## Données, sécurité et confidentialité

??? question "Où sont stockées mes données ?"
    Dans **votre** base de données, sur votre machine. Les identifiants bancaires sont
    gardés par woob sur votre serveur. Rien n'est envoyé à l'éditeur : seules les
    notifications push, si un administrateur les active, transitent par Carbure Push, et
    les questions posées à un agent IA par son fournisseur (voir plus bas).

??? question "Comment protéger mon installation ?"
    Servez Carbure en **HTTPS**, activez la **double authentification** (application
    d'authentification, codes de secours ; un administrateur peut l'imposer à tous), et
    suivez les [recommandations de déploiement](securite.md#recommandations-de-deploiement).
    Après 5 échecs de connexion d'affilée, un compte est bloqué 24 heures (un
    administrateur peut le débloquer).

??? question "J'ai perdu mon téléphone avec la double authentification, que faire ?"
    Connectez-vous avec l'un de vos **10 codes de secours**. Sans code, un administrateur
    réinitialise votre double authentification (onglet **Utilisateurs**) ; un
    administrateur seul dans ce cas repasse `mfa_required=false` dans `data/conf/prod.ini`
    sur le serveur. Supprimez ensuite le téléphone perdu de **Mon profil → Mes appareils**.
    Voir [Double authentification](securite.md#double-authentification-totp).

??? question "Comment sauvegarder mes données ?"
    Sauvegardez régulièrement la base de données et le dossier `data/` (configuration et
    woob avec vos banques). Le menu **Administration → Sauvegarde** exporte aussi les
    données choisies (catégories, règles, budgets, insights, transactions) dans un fichier
    à réimporter ; il ne contient ni utilisateurs, ni mots de passe, ni connexions
    bancaires. Voir [Portail web](portail.md#sauvegarde-menu-administration).

??? question "Plusieurs personnes du foyer peuvent-elles l'utiliser ?"
    Oui : chaque membre a son compte (identifiant, mot de passe, langue, thème,
    notifications, appareils), avec un profil **utilisateur** ou **administrateur**. Les
    comptes bancaires suivis sont partagés par le foyer ; seuls les administrateurs gèrent
    les banques, les règles, les catégories, les insights et les paramètres.

??? question "Que fait l'application mobile de mes données ?"
    Elle ne se connecte qu'au serveur que vous indiquez, sans publicité ni mesure
    d'audience. Les données bancaires restent en mémoire et disparaissent à la fermeture de
    l'application ; seuls l'adresse du serveur, le dernier identifiant, le thème et, si
    vous l'activez, le mot de passe protégé par la biométrie sont gardés sur le téléphone.
    Voir la [Politique de confidentialité](confidentialite.md).

## Agents IA

??? question "Puis-je interroger mon budget avec Claude ou ChatGPT ?"
    Oui, grâce au **serveur MCP** intégré, en lecture seule, qu'un administrateur active.
    Claude se connecte par OAuth, sans jeton à copier ; les autres agents (Claude Code,
    ChatGPT, Cursor, Copilot, Gemini…) avec un jeton d'accès, révocable d'un clic. Voir
    [Agents IA (MCP)](mcp.md).

??? question "Un agent IA peut-il modifier mes données ?"
    Non : il peut lister les catégories et les transactions, chercher, totaliser les
    dépenses, lire les budgets, les tendances et les règles, mais rien modifier. Les
    données qu'il consulte (libellés, montants, catégories) sont en revanche envoyées à
    son fournisseur pour être analysées.

## Aide

??? question "Où obtenir de l'aide ou signaler un bug ?"
    Sur le [serveur Discord](https://discord.gg/QsxYw4Rx9D) ; un bug se
    signale par une [issue](https://github.com/ltoinel/Carbure/issues/new/choose) et une
    vulnérabilité en privé. Des vidéos sont aussi sur la
    [chaîne YouTube](https://www.youtube.com/@carbure-org). Voir [Assistance](assistance.md).
