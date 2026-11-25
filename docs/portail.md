---
title: "Portail web : transactions, budgets et administration"
description: "Les écrans du portail web de Carbure : transactions du mois, recherche, import de relevés, budgets, flux, tendances, insights, comptes, règles et utilisateurs."
icon: material/monitor-dashboard
---

# Portail web

Le portail (`portal/`) est une application Vue 3 servie en fichiers statiques, sous
`https://<serveur>/portal/` (l'adresse racine y redirige). Il utilise la même API que
les [applications mobiles](applications-mobiles.md). Cette page décrit les écrans ; les règles de fonctionnement (calculs, règles,
notifications, droits) sont dans [Fonctionnalités](fonctionnalites.md).

## Installation

Tant que Carbure n'est pas installé, le portail affiche l'**assistant d'installation**,
en trois étapes :

1. **Base de données** : serveur, port, nom de la base, utilisateur et mot de passe, déjà
   remplis avec Docker (laisser le mot de passe vide : celui de `docker/docker-compose.yml` est
   utilisé). Depuis Internet, un **code d'installation** est aussi demandé (journaux du
   conteneur ou fichier `data/conf/setup.code`).
2. **Installation** : l'assistant indique ce qu'il va faire — créer les tables d'une base
   vide, conserver une base Carbure à jour, ou mettre à jour une base Carbure existante
   (après avoir coché « J'ai une sauvegarde de ma base de données »). Il demande
   l'identifiant, le mot de passe (8 caractères minimum), l'e-mail et la langue du
   **compte administrateur** s'il n'en existe pas encore. Sur une base vide, il propose
   aussi, **pour bien démarrer**, des catégories courantes avec leurs règles et des insights
   (deux cases cochées par défaut ; noms dans la langue choisie ; tout reste modifiable).
3. **Terminé** : connexion avec ce compte.

Si le navigateur garde la session d'une installation précédente à la même adresse (Carbure
réinstallé de zéro), elle est fermée et l'assistant s'affiche.

## Connexion et profils

L'écran de connexion demande l'identifiant et le mot de passe : l'API est celle du serveur
qui sert le portail (`/api`, à côté de `/portal/`). Le JWT est conservé dans le navigateur
(`localStorage`) jusqu'à la déconnexion ou l'expiration de la session (30 jours). Après **5 échecs de connexion** d'affilée, le
compte est bloqué 24 heures ; un administrateur peut le débloquer.

Quand la **double authentification** est imposée (ou activée par l'utilisateur), un
second écran demande ensuite le **code à 6 chiffres** de l'application
d'authentification, ou un code de secours. À la première connexion, il affiche le
**QR code** à scanner et la clé en texte, puis, une fois le premier code accepté, les
**10 codes de secours** (à copier et conserver) avant d'ouvrir la session. La case
**Faire confiance à ce navigateur pendant 6 mois** évite de redemander le code dans ce
navigateur ; **Retour** revient au mot de passe. Un code faux est compté comme un échec
de connexion.

Deux profils existent :

| Profil | Onglets |
|---|---|
| **Utilisateur** | Transactions, Budget, Insights, Tendances, Tags ; **Agent IA** et **Mon profil** dans le menu utilisateur |
| **Administrateur** | En plus : Règles, Comptes, et le menu **Administration** (Utilisateurs, Catégories, Synchro, Push mobile, Agent IA, Logs, Paramètres, Sauvegarde, Purge, API) ; ajout et modification des insights ; activation du serveur MCP ; mise à jour de la base de données |

Les pages d'administration (Règles, Catégories, Comptes, Utilisateurs) et les onglets
Insights et Agent IA suivent la même présentation : un en-tête avec le titre et l'aide, et
un bouton rond **+** qui ouvre le formulaire dans une fenêtre modale, qui sert aussi à la
modification.

## Installer le portail comme une application

Le portail peut s'installer comme une **application** : il s'ouvre alors dans sa propre
fenêtre, sans barre d'adresse ni onglets, avec l'icône de Carbure dans le Dock, la barre des
tâches ou l'écran d'accueil.

| Navigateur | Installation |
|---|---|
| **Chrome, Edge** (Windows, macOS, Linux) | icône d'installation à droite de la barre d'adresse, ou menu **⋮** → **Installer Carbure** (Edge : **Applications** → **Installer cette application**) |
| **Safari** (macOS Sonoma et suivants) | menu **Fichier** → **Ajouter au Dock** |
| **Safari** (iPhone, iPad) | bouton **Partager** → **Sur l'écran d'accueil** |
| **Chrome** (Android) | menu **⋮** → **Ajouter à l'écran d'accueil** (ou **Installer l'application**) |

- **HTTPS obligatoire** : les navigateurs ne proposent l'installation que pour un site en
  HTTPS (proxy inversé devant le conteneur, voir [Installation](installation.md)), sauf
  sur `localhost`.
- La fenêtre de l'application est celle du portail : même connexion (la session est
  partagée avec le navigateur), même thème, déconnexion et reconnexion dans la fenêtre. La
  couleur de sa barre de titre suit le thème clair ou sombre. Les liens externes (site de
  Carbure, tickets woob) s'ouvrent dans le navigateur.
- **Pas de mode hors ligne** : le portail n'a pas de *service worker* ; rien n'est gardé en
  cache (ni les données bancaires ni le jeton de session), il faut le serveur pour
  l'utiliser.
- Le manifeste (`portal/manifest.webmanifest`) utilise des adresses relatives : il
  fonctionne aussi quand Carbure est servi sous un sous-chemin. Ses icônes
  (`portal/icons/`) sont tirées de `portal/icon.png`.

## En-tête

- Le titre **Carbure** ramène à l'accueil : transactions du mois en cours.
- En haut à droite, le bouton affiche le **login** de l'utilisateur connecté ; son menu
  donne accès à **Mon profil**, à **Agent IA** (utilisateurs non administrateurs) et à
  **Déconnexion**. Juste avant, l'icône de **thème** passe au thème clair ou sombre (voir
  [Thèmes](#themes-et-preferences-du-systeme)).
- Dans la barre du haut, le sélecteur de **mois** et d'**année** et le bouton rond
  **Actualiser** (icône seule) s'appliquent aux onglets Transactions, Budget et Insights.
  Ils ne proposent que les mois qui ont des transactions, plus le mois en cours ; une autre
  année sélectionne son dernier mois disponible. Les flèches **‹** et **›** passent au mois
  précédent ou suivant qui a des transactions. Les onglets Tendances et Règles ont leurs
  propres réglages.
- **Sur téléphone** (fenêtre de moins de 768 px), l'en-tête tient sur une ligne : le logo
  et la période. Les onglets Transactions, Budget, Insights et Tendances passent dans une
  **barre en bas de l'écran** ; son bouton **Plus** ouvre un menu avec les autres onglets
  (Règles, Comptes, Administration, Tags, ou Agent IA), **Mon profil**, le **thème** et la
  **Déconnexion**.

!!! note "Mise à jour de la base de données"
    Quand une nouvelle version de Carbure modifie la base (migrations en attente), un
    bandeau **Mise à jour de la base de données disponible** s'affiche pour les
    administrateurs. Après avoir sauvegardé la base, le bouton **Mettre à jour** applique
    les migrations (`POST /system/migrate`) ; les données sont conservées. Avec Docker,
    elles sont de toute façon appliquées à chaque démarrage du conteneur.

!!! note "Nouvelle version de Carbure"
    Quand une nouvelle version de Carbure est publiée, un bandeau **Carbure X.Y.Z est
    disponible** s'affiche pour les administrateurs, avec la version installée, le lien
    **Voir les nouveautés** (les notes de version sur GitHub) et le bouton **Masquer
    jusqu'à la prochaine version** (retenu dans ce navigateur). Le serveur vérifie au plus
    une fois par jour (réglage `update_check`, voir [Configuration](configuration.md#global)) ;
    pour mettre à jour, voir [Installation](installation.md#mettre-a-jour).

## Transactions

![Écran Transactions du portail web Carbure](assets/screenshot-transactions.webp)

- Statistiques du mois : revenus, dépenses, solde.
- **Sur téléphone**, les trois chiffres du mois tiennent sur une ligne compacte, la liste
  défile avec la page (sans défilement propre), les boutons de la
  barre d'outils (Calendrier, Sélectionner, Ajouter, Importer) n'affichent que leur icône
  (leur nom reste lu par les lecteurs d'écran et affiché au survol), et chaque transaction
  tient sur deux lignes : le libellé complet et le montant, puis la date, les badges, les
  tags et la catégorie.
- Liste compacte des transactions avec l'icône et la couleur de leur catégorie (celles du
  type d'opération si elles ne sont pas catégorisées), libellé, date et montant.
- **Pointage** : le bouton rond à gauche de chaque transaction la marque comme vérifiée
  (coche verte) ou non vérifiée. Les transactions non vérifiées sont en gras.
- **Filtres** : à côté du nombre de transactions, un interrupteur limite la liste aux
  transactions non vérifiées, un autre aux transactions **sans catégorie** du mois (chacun
  avec leur nombre) ; ils se combinent. Une transaction catégorisée quitte aussitôt la
  liste filtrée.
- **Transactions récurrentes** : celles retrouvées chaque mois (salaire, loyer,
  abonnements, factures, `GET /transaction/recurring`) portent le badge « Mensuelle ». Leur
  barre, en haut de la liste comme celle de la sélection, est repliée par défaut (leur
  nombre seul) ; son chevron la déplie, choix retenu par le navigateur : reçues et
  attendues, « Total des transactions récurrentes », et un interrupteur qui
  n'affiche qu'elles et liste celles qui ne sont pas encore arrivées (« Attendues ce
  mois-ci », avec leur jour habituel et leur montant, « ≈ » s'il varie ; « Absentes ce
  mois-ci » pour un mois passé). La replier retire ce filtre. La barre n'apparaît pas
  pendant une recherche.
- **Calendrier des récurrentes** : le bouton **Calendrier** ouvre le mois affiché en
  calendrier (semaines du lundi au dimanche, aujourd'hui en couleur). Chaque transaction
  récurrente y est placée au jour de son arrivée (« Reçue », un clic ouvre son détail) ou
  à son jour habituel (« Attendue », « En retard » une fois ce jour passé, « Absente » pour
  un mois passé), avec son montant (« ≈ » s'il varie). En haut, le nombre et le total des
  reçues et des attendues. Sur téléphone, le calendrier devient la liste des jours qui ont
  des récurrentes.
- **Recherche** : le champ de recherche (2 caractères minimum) interroge
  `GET /transaction/search` sur tous les mois et affiche le **total** des transactions
  trouvées ; les filtres (non vérifiées, sans catégorie) s'appliquent aussi aux résultats. La croix efface la recherche.
- **Catégorisation manuelle** : une transaction sans catégorie propose un sélecteur
  « Catégoriser… » (icônes, couleurs, recherche) ; la transaction est aussi pointée. Ce
  sélecteur, comme ceux du détail, de la saisie manuelle et de la répartition, présente
  d'abord les catégories du sens de la transaction (revenus pour un crédit, dépenses pour
  un débit), puis les catégories hors budget, puis les autres (un remboursement est un
  crédit sur une catégorie de dépense). Pour un administrateur, un bandeau
  propose ensuite de **créer une règle** pour classer automatiquement ce libellé : il ouvre
  l'onglet Règles avec le libellé et la catégorie pré-remplis, le mot-clé pouvant être
  raccourci (dates, numéros de carte…).
  En mode **Sélectionner**, chaque transaction cochée affiche ce sélecteur : la catégorie
  choisie sur l'une d'elles est appliquée à toutes les transactions cochées (elles sont
  aussi pointées ; pas de règle proposée), et la sélection reste.
- **Détail d'une transaction** : un clic (ou Entrée) sur le libellé ouvre une fenêtre avec
  le montant, le libellé complet, les dates, le type, la carte, la banque (compte synchronisé
  ou relevé importé) et la date d'import ; la
  catégorie s'y change avec le sélecteur visuel et la transaction s'y pointe. Le champ
  **Date comptée** change le mois où la transaction compte, synchronisée ou non (la date
  réelle de la banque reste affichée et ne change pas) : à l'enregistrement, la liste, les
  totaux et les mois proposés sont rechargés, la transaction passe dans son nouveau mois et
  les synchronisations suivantes gardent cette date. Le champ **Commentaire** (texte libre,
  500 caractères, vide pour le retirer), dernier champ de la fenêtre, est enregistré avec le reste ; un commentaire
  s'affiche sous la date de la transaction dans les listes (une ligne, texte complet au
  survol). Le bouton
  corbeille supprime la transaction, après confirmation : elle n'est plus réimportée, ni par
  la synchronisation ni par l'import d'un relevé (marquée « Supprimée » dans l'aperçu).
  Le bouton **Transactions similaires** ferme la fenêtre et lance la recherche avec le
  libellé débarrassé du type d'opération (CB, PRLV SEPA, VIR…) et de ce qui suit le
  commerçant (numéro de carte, date, référence de mandat) : « PRLV SEPA EDF MDT/123 »
  cherche « EDF ». La recherche s'efface ensuite avec la croix, comme une recherche saisie.
- **Saisir une transaction à la main** : le bouton **Ajouter** ouvre un formulaire
  (compte, dépense ou revenu, montant, date, libellé, catégorie facultative : sinon celle
  des règles ; commentaire facultatif, en dernier). Pratique pour une dépense en espèces ; tout utilisateur peut en saisir. Le
  compte se choisit parmi tous ceux du foyer : les **comptes manuels** d'abord (le premier
  est proposé), puis les comptes synchronisés. Sur un compte synchronisé, le formulaire
  rappelle que la banque peut envoyer plus tard la même transaction : supprimer alors le
  doublon. La transaction est pointée, n'est jamais écrasée par la synchronisation, et reste
  modifiable depuis son détail (bouton crayon **Modifier**, à côté de la poubelle : date, libellé, montant, commentaire).
- **Répartir une transaction** sur plusieurs catégories : dans son détail, le bouton
  icône **Répartir** ouvre une fenêtre avec une ligne par part (catégorie et montant, saisi
  sans signe : il prend celui de la transaction ; 10 parts au plus). Le **reste**, recalculé
  à chaque saisie, demeure dans la catégorie de la transaction. Exemple : un ticket
  Carrefour de 82,40 € dont 20 € de restaurant devient une transaction de 62,40 € en
  Supermarché et une **part** de 20 € en Restaurant. Chaque part s'affiche juste sous sa
  transaction, en retrait, avec le badge **Part** ; la transaction rappelle le **total**
  de la banque. Le total du mois ne change pas, et chaque part compte dans sa catégorie
  (budgets, tendances, flux, insights). Rouvrir **Répartir** (depuis la transaction ou une
  de ses parts) modifie les parts ; la corbeille rouge en bas à gauche (**Supprimer la
  répartition**) rend tout le montant à la transaction ; supprimer une part lui rend son montant. La synchronisation conserve la
  répartition et les règles ne la modifient pas.
- **Tags** : les transactions étiquetées portent leurs puces `#tag` sous leur libellé.
  - Dans le **détail**, la section Tags liste ceux de la transaction (la croix en retire
    un) ; son champ **Saisir un tag…** complète le nom tapé avec les tags actifs (flèches
    pour se déplacer, Entrée ou clic pour ajouter) et propose **Créer #nom** pour un nom
    nouveau. Le champ se vide aussitôt, prêt pour le tag suivant. Sans tag actif, un lien
    mène à l'onglet Tags.
  - **Sélectionner** (barre d'outils) affiche une case par transaction et une barre
    « n sélectionnées » avec le même champ : le tag choisi y reste affiché, puis
    **Appliquer le tag** l'ajoute à toutes les transactions cochées d'un coup.
  - La **recherche** `#voyage-italie` liste les transactions de ce tag, tous mois
    confondus.
- **Importer un relevé** : le bouton « Importer » ouvre une fenêtre où déposer le fichier
  téléchargé depuis l'espace client de la banque (OFX/QFX, QIF, CSV ou CAMT.053, 1 Mo au
  plus ; banques dont l'export CSV est vérifié : voir
  [Fonctionnalités](fonctionnalites.md#import-de-releves-bancaires)). Un aperçu classe chaque transaction : **nouvelle** (cochée), **doublon probable**
  (même montant qu'une transaction existante à 3 jours près, souvent la même synchronisée
  avec un autre libellé : non cochée) ou **déjà présente** (même identifiant : ignorée).
  Chaque transaction a sa case : décocher celles à ne pas importer (ou « Tout cocher » pour
  tout cocher ou décocher) ; le bouton indique le nombre de transactions importées.
  La liste **Compte des transactions** rattache le fichier à un compte du foyer (manuel ou
  synchronisé) ; elle propose d'office le compte dont le numéro est lu dans le fichier,
  sinon le numéro du fichier est gardé tel quel.
  Les transactions importées appartiennent à l'utilisateur connecté et passent par les
  règles de catégorisation ; la liste s'ouvre ensuite sur le dernier mois importé
  (`POST /transaction/import/preview`, `POST /transaction/import`). Aucun compte synchronisé
  n'est nécessaire : sur un mois vide (nouvelle installation), le bouton est proposé au
  centre de la page, ce qui permet d'utiliser Carbure uniquement avec des relevés.

## Budget

![Écran Budgets : flux du mois en diagramme de Sankey](assets/screenshot-flow.webp)

Deux sous-onglets : **Flux** (affiché par défaut) et **Budgets**.

- **Flux du mois** (sous-onglet Flux) : chiffres clés (revenus, dépenses, épargne et part
  des revenus épargnée, reste) puis diagramme de Sankey qui
  montre d'où vient l'argent du mois et où il va — revenus par catégorie → revenus du mois
  → dépenses par catégorie principale (les 8 plus importantes, le reste regroupé dans
  « Autres »), épargne et **Reste** (ou **Déficit**). Les virements internes (hors budget)
  sont exclus. « Voir le tableau » affiche les mêmes flux en tableau (`GET /budget/flow`).
  Un clic sur une catégorie (ou sur son flux) affiche ses transactions sous le diagramme
  (`GET /budget/flow/transactions`) ; un second clic les masque.
- **Synthèse du mois** (sous-onglet Budgets) : dépensé, budgété, reste (ou dépassement) et jauge globale des
  dépenses budgétées.
- **Cartes par catégorie** avec l'icône et la couleur de la catégorie, le montant
  dépensé sur le budget, le reste ou le dépassement ; le fond de la carte se remplit de
  gauche à droite à mesure que le budget est consommé (vert jusqu'à 105 % du budget, rouge
  au-delà). Le budget d'une catégorie parente précédé de « Σ » est la somme de ses
  sous-catégories.
- Sections séparées : **dépenses budgétées**, **revenus**, **sans budget et hors
  budget**. Une catégorie de dépense sans budget (montant à 0) y montre ce qu'elle a
  dépensé, sans jauge ni dépassement, et ne compte pas dans la synthèse.
- **Clic sur une catégorie** : liste de ses transactions du mois (sous-catégories
  comprises) avec leur total et le pointage ; si elle a des sous-catégories, leurs
  cartes s'affichent, avec un fil d'Ariane pour remonter. Les transactions rangées
  directement sur la catégorie parente ont leur propre carte, « *Catégorie* (hors
  sous-catégories) », sans budget : le total de la catégorie se retrouve dans ses cartes.
- Bouton de réglage d'une carte : modification du montant budgété pour le mois. Pour une
  catégorie sans sous-catégorie, la case **Pas de budget pour cette catégorie** enregistre
  un budget à 0. Pour une catégorie parente, la case **Somme des sous-catégories** utilise leur somme ; décochée,
  le montant saisi la remplace. Le champ **Note** garde un pense-bête de ce que couvre le
  budget (200 caractères au plus), affiché sous les montants de la carte et recopié avec
  le budget au mois suivant ; le vider supprime la note.
- **Recopier les budgets du mois précédent** : le bouton au-dessus de la synthèse reprend
  les budgets du mois précédent (`POST /budget/copy`), en complétant seulement les
  catégories sans budget ou en écrasant le mois affiché. Il n'apparaît que si le mois
  affiché n'a aucun budget et que le mois précédent en a (`GET /budget/copy`) ; il est
  réévalué au changement de mois, après un réglage, après la recopie et quand un autre
  membre du foyer modifie les budgets.

## Insights

Indicateurs du mois sélectionné, sous forme de cartes (icône, couleur, montant).

Seuls les insights **affichés** ont une carte : une nouvelle installation n'affiche que les
principaux. Le bouton **+** ouvre la liste des insights masqués, chacun affiché d'un clic
(**Afficher**) ; l'icône **œil barré** d'une carte la masque. Le choix vaut pour tout le
foyer, et un insight masqué n'est plus calculé. Le bouton **+** n'apparaît pour un
utilisateur que s'il y a des insights masqués.

Les cartes se **réorganisent par glisser-déposer** sur une grille aimantée : la poignée
(**⋮⋮**, en bas de chaque carte) se glisse à la souris, au stylet ou au doigt ; la carte se
soulève et se cale dans la case sous le pointeur, les autres glissent pour lui faire place.
Au clavier, la poignée déplace sa carte avec les **flèches** (case précédente ou suivante),
**Début** et **Fin** ; sa nouvelle place est annoncée aux lecteurs d'écran. L'ordre est
enregistré au lâcher, pour tout le foyer (`PUT /insight/order`) ; un nouvel insight se
place à la fin.

La **cloche** d'une carte règle l'alerte de l'utilisateur sur cet insight : **au-dessus** ou
**en dessous** d'un montant, comparé à la valeur du mois sans son signe. La carte rappelle
l'alerte posée et la cloche se remplit ; la corbeille rouge en bas à gauche (**Supprimer l'alerte**) la retire. Les canaux se
choisissent dans **Mon profil → Notifications** (« Alerte d'un insight », désactivée par
défaut). Voir [Alertes des insights](fonctionnalites.md#alertes-des-insights).

Un administrateur les crée (bouton **+**, puis **Créer un insight**) et les modifie (crayon de chaque carte ; la
suppression se fait dans la modale) : un nom (20 caractères au plus), une couleur parmi
13, une icône et une requête SQL `SELECT … AS amount` où `{month}` et `{year}` sont
remplacés par le mois affiché. L'éditeur colore la syntaxe SQL et fait vérifier la requête
par le serveur pendant la saisie : erreur (mot-clé interdit, syntaxe MariaDB…) ou résultat
pour le mois affiché. La requête est exécutée en lecture seule et ne peut pas lire les
tables `users`, `api_tokens` et `devices` (voir [Sécurité](securite.md#insights-sql-stocke)).
Des exemples de requêtes sont dans [Recettes d'insights](insights.md).

## Tendances

![Écran Tendances du portail web Carbure](assets/screenshot-trends.webp)

- Période de **3, 6, 12, 24, 36 ou 60 mois** se terminant au mois courant, ou
  **Personnalisée** : du mois choisi au mois choisi (mois courant au plus tard, 240 mois au
  plus). Au-delà de 12 mois, l'axe n'affiche qu'une partie des mois, puis les années.
- **Comparer à** : sans comparaison, la période précédente ou la même période l'an
  dernier.
- **Chiffres clés** : épargne cumulée, épargne moyenne par mois, taux d'épargne,
  dépenses moyennes par mois face au budget planifié ; en comparaison, l'écart (flèche,
  couleur et pourcentage) avec la période de référence.
- **Comparaison des totaux** (en mode comparaison) : crédit, débit, hors budget, budget
  planifié et épargne des deux périodes.
- **Revenus, dépenses et budget planifié** : barres mensuelles crédit / débit / hors
  budget et ligne du budget planifié, avec infobulle détaillée au survol.
- **Épargne mensuelle** : montants versés sur la catégorie **Epargne** et ses
  sous-catégories, moins les retraits (barres bleues, rouges pour un mois de retrait net).
  Le nom de la catégorie se règle avec `savings_category` (casse et accents ignorés). Le
  taux d'épargne est `épargne / revenus`.
- **Par catégorie** : toutes les catégories d'un type (sélecteur Dépenses, Revenus, Hors
  budget) sur un même graphique, catégories principales (sous-catégories incluses) ou
  sous-catégories. Une pastille par catégorie, de la plus grosse à la plus petite, la masque
  ou la réaffiche ; **Tout** et **Aucun** les affichent ou les masquent toutes. Boutons
  **Lignes**, **Barres** (empilées) et **Aires** (empilées) ; l'échelle couvre les
  catégories affichées, l'infobulle donne le montant de chacune et leur total. Ces choix
  sont retenus par le navigateur.
- **Graphiques personnalisés** : un graphique par catégorie (sous-catégories incluses),
  sous-catégorie ou insight choisi, en barres, lignes ou aires mensuelles (le choix de la
  carte Par catégorie) avec la moyenne de la période, le
  budget prévu (catégories) et une infobulle au survol. Les administrateurs les ajoutent
  (**Ajouter un graphique**, 12 au plus), les déplacent et les retirent ; la liste est
  partagée par le foyer.
- Vue **tableau** des données mois par mois.

## Tags

Un **tag** suit le coût global d'un projet (un voyage, des travaux, un mariage…) sans
toucher aux catégories : chaque transaction garde la sienne, les budgets et les tendances
ne changent pas. Tous les utilisateurs du foyer créent et utilisent les tags.

- **Liste** : les tags **actifs**, puis les **archivés** (avec leur nombre), une carte par
  tag : sa couleur en bordure, son nom, sa période, une barre du temps écoulé pour un tag
  actif qui a une date de fin, la mention **Archivé** sinon, son nombre de transactions et
  son coût net. Un clic sur la carte ouvre son bilan ; les boutons crayon et corbeille le
  modifient ou le suppriment (ses transactions perdent seulement ce tag). Sans aucun tag,
  la liste explique comment en créer un.
- **Nouveau tag** : un nom (`voyage-italie` : lettres, chiffres, `-` ou `_`, 40 au plus, le
  `#` est ajouté à l'affichage), une couleur, une date de début et une date de fin
  facultative. Pendant sa période, le tag est **actif** : il est proposé dans le détail
  des transactions et la sélection. Après sa fin, il est **archivé** : il n'est plus
  proposé, mais reste dans la liste avec son bilan.
- **Bilan** (un clic sur un tag) :
  - l'en-tête : le tag à sa couleur, son **statut** (actif ou archivé), sa **période**
    (début – fin, ou « en cours ») et les boutons **Modifier le tag** et
    **Voir dans Transactions** ;
  - quatre chiffres clés : le **coût net** du projet (remboursements déduits) et son nombre
    de transactions, les **dépenses** (avec le total des **remboursements**), le coût
    **par jour** sur la période (jusqu'à aujourd'hui tant qu'elle court) et la
    **plus grosse dépense** ;
  - la courbe du **coût cumulé jour par jour**, en marches depuis zéro, avec ses axes
    (montants, dates) : la valeur d'un jour s'affiche au survol, ou au clavier une fois
    la courbe sélectionnée (flèches gauche et droite, Début, Fin) ; le
    **tableau des valeurs** reste sous la courbe ;
  - une barre **par catégorie**, avec l'icône et la couleur de la catégorie, son montant,
    son nombre de transactions et sa **part** du total, les plus gros montants d'abord ;
  - la liste de ses **transactions**, comme dans l'onglet Transactions, avec leur
    catégorie habituelle ;
  - sur un écran large, la courbe et les catégories sont côte à côte ; sur un téléphone,
    l'une sous l'autre.
- **Voir dans Transactions** ouvre l'onglet Transactions sur la recherche `#nom` du tag.
- Les parts d'une transaction répartie reprennent ses tags, puis s'étiquettent à part :
  seule la part du restaurant d'un ticket peut appartenir au voyage.
- Les tables `tag` et `transaction_tag` sont lisibles par les
  [insights](insights.md), pour un indicateur sur un projet.

## Règles (administrateurs)

Catégorisation automatique : une transaction dont le libellé contient le mot-clé reçoit
la catégorie choisie.

- **+** : ajout d'une règle (mot-clé + catégorie ou sous-catégorie) dans une modale. Le
  sélecteur de catégorie affiche l'icône, la couleur et le parent de chaque catégorie,
  regroupées par type (dépenses, revenus, hors budget), avec une recherche.
- Liste des règles regroupées par catégorie, filtrable, avec suppression.
- **Resynchroniser** : applique toutes les règles à toutes les transactions de la base,
  déjà catégorisées comprises, et affiche le nombre de transactions recatégorisées. Créer ou
  modifier une règle l'applique aussi immédiatement à tout l'historique, sauf aux
  transactions dont la catégorie a été choisie à la main ; les nouvelles transactions
  sont catégorisées à chaque synchronisation.
- Interrupteur **Conserver les catégories existantes** : activé, la resynchronisation et
  la création ou la modification d'une règle ne touchent que les transactions sans
  catégorie ou catégorisées par une règle (l'aide du bouton, la fenêtre de la règle et le message affiché le
  rappellent).
- Un clic sur une règle ouvre sa fenêtre : modifier le mot-clé et la catégorie, voir combien
  de transactions contiennent le mot-clé (et combien sont déjà dans la catégorie), la
  supprimer (corbeille), et cocher **Envoyer une notification** : chaque nouvelle
  transaction synchronisée qui correspond à la règle envoie une notification à tous les
  utilisateurs du foyer (cloche sur la règle), en push ou par email selon les
  **Notifications** du profil de chacun.

## Catégories (administrateurs)

Arborescence des catégories (un niveau de sous-catégories) regroupée par type (dépenses,
revenus, hors budget). **+** ajoute une catégorie, le crayon la modifie (dans une modale),
la corbeille la supprime. Le **+** d'une ligne de catégorie principale ajoute une
sous-catégorie : la modale s'ouvre avec ce parent et son type déjà choisis. L'icône et la couleur se choisissent parmi celles que
les applications mobiles savent afficher, avec un aperçu. Supprimer une catégorie fait passer ses
transactions en « Non catégorisé » et supprime ses budgets et règles ; une catégorie qui a
des sous-catégories ne peut pas être supprimée. La catégorie « Non catégorisé » (0) est
protégée.

## Comptes (administrateurs)

La page a deux blocs : **Comptes synchronisés** (avec woob) et **Comptes manuels**. La
synchronisation, la mise à jour des modules bancaires et le bouton rond **+** (ajout d'une
banque ou de comptes) ne concernent que le premier.

Les comptes synchronisés sont organisés par banque : chaque **banque connectée** (en-tête avec son nom et
ses actions) présente juste en dessous, en retrait, les **comptes suivis** qui en
viennent ; une banque sans compte suivi l'indique (« Aucun compte suivi : ajoutez des
comptes de cette banque »). Les comptes d'une banque qui n'est plus connectée dans woob,
ou importés depuis un fichier, sont regroupés à la fin sous **Autres comptes**.

Le bouton rond **+** des comptes synchronisés (**Ajouter une banque ou des comptes**) ajoute
des comptes d'une **banque déjà connectée** : on choisit la banque (directement ses comptes
s'il n'y en a qu'une), puis on coche ses comptes à suivre. Le dernier choix de la liste,
**Connecter une nouvelle banque…**, ouvre la connexion d'une banque, aussi ouverte
directement quand aucune banque n'est connectée.

Les **comptes manuels** ont leur propre bloc : un compte jamais
synchronisé (espèces, banque que woob ne gère pas), dont les transactions sont saisies à
la main dans l'onglet Transactions. Son bouton rond **+** (**Ajouter un compte manuel**) demande un nom (« Espèces »)
et, en option, un numéro (par exemple l'IBAN sans espaces). Il se renomme et se supprime
comme les autres comptes ; il n'a pas de bouton de synchronisation.

- **Comptes du foyer** (partagés par tous les utilisateurs) : chaque compte suivi avec
  « Ajouté par » (l'utilisateur qui l'a ajouté), la date et le statut de sa dernière
  synchronisation (Synchronisé, Échec ou Jamais synchronisé ; le détail au survol), et
  trois actions : **synchroniser ce compte**, renommer, ne plus suivre (les transactions
  déjà importées sont conservées). Un compte s'affiche « BANQUE ···1234 » (banque et fin
  de l'identifiant), ou sous le **nom** qui lui a été donné (facultatif, ex. « Compte
  joint »), aussi dans la synchronisation et le détail d'une transaction.
- **Synchroniser tous les comptes** (icône de synchronisation en haut de la page) : lance
  la synchronisation de tous les comptes suivis, avec le même suivi en direct qu'un compte
  seul.
- **Ajout en deux étapes** :
    1. **Connecter une banque** (fenêtre modale, depuis le bouton **+**) : choisir la banque parmi celles que
       woob supporte ; le formulaire demande les identifiants attendus par son module woob
       (numéro client, code, type de compte…), que **Connecter la banque** confie à woob
       (Carbure ne les enregistre ni ne les journalise). Une banque déjà connectée reste
       proposée (« autre identifiant ») pour un second accès avec d'autres identifiants
       (compte joint, professionnel…). Le champ facultatif **Nom de la connexion** lui donne
       un nom lisible (« Compte joint », « BNP pro »…), affiché à la place du nom de la
       banque ; son nom technique dans woob (`bnp`, `bnp_2`…) est généré automatiquement.
       Si la banque refuse les identifiants, son message s'affiche et le formulaire reste
       ouvert. Si elle demande une **authentification forte** dès la connexion (Crédit
       Mutuel, CIC…), la banque reste connectée et la liste de ses comptes s'ouvre en
       attente : le message de la banque s'y affiche, on valide la connexion dans son
       application sans fermer la fenêtre, puis les comptes apparaissent.
    2. **Banques connectées** : chaque connexion (backend woob) affiche son nom (à défaut
       celui de la banque) et son nom dans woob ; le bouton crayon la **renomme** (un nom
       vide rétablit celui de la banque) ; la corbeille la **supprime**, après confirmation
       dans une fenêtre du portail : woob oublie ses identifiants et ses comptes suivis ne le
       sont plus (leurs transactions sont conservées). La fenêtre reste ouverte, son bouton
       indiquant « Suppression en cours… », jusqu'à ce que woob ait supprimé la connexion ;
       une erreur s'y affiche, la suppression peut alors être relancée ou annulée. Son bouton **Ajouter des comptes** ouvre la liste des comptes que woob trouve pour elle
       (`woob bank list -b <banque>` : libellé, solde) ; on coche ceux à suivre et
       **Suivre les comptes sélectionnés** les ajoute d'un coup, nommés d'après leur libellé
       woob. Les comptes déjà suivis sont cochés et grisés. Après une connexion, cette liste
       s'ouvre directement.
- **Mettre à jour les modules bancaires** (administrateur) : met à jour les modules woob
  des banques (`woob config update`), par exemple après la correction d'un module cassé,
  avec le journal de woob en direct. La première fois, la fenêtre affiche le dépôt de woob
  et l'empreinte de sa clé, à comparer avec celle publiée par les mainteneurs de woob avant
  **Faire confiance et mettre à jour** ; les fois suivantes, la mise à jour démarre
  directement (voir [Sécurité](securite.md)). Tant que le dépôt n'est pas approuvé, une
  banque dont le module n'est pas encore installé ne peut pas être connectée : la fenêtre
  de connexion le signale.

  Seul un compte listé par woob peut être suivi : il n'y a plus de saisie manuelle de
  l'identifiant. La modification d'un compte ne change que son nom.
- **Synchronisation** : progression en direct, statut OK/KO de chaque étape par compte
  (opérations à venir, historique, notifications), nombre de transactions reçues et
  nouvelles, journal détaillé. Un échec des opérations à venir est affiché sur leur étape
  sans faire échouer le compte : l'historique est quand même synchronisé. Avec le réglage `woob_debug`, le journal affiche aussi, en
  direct, les logs de débogage de woob (requêtes vers la banque, trace de l'erreur Python),
  à un administrateur uniquement. La commande système lancée pour chaque étape (`Woob command …`)
  y figure toujours. En cas d'échec woob, un lien ouvre la recherche des tickets
  woob sur le module concerné. Après une synchronisation en échec, quelle que soit l'erreur, le
  bouton **Mettre à jour les modules bancaires** est aussi proposé sous le journal (un
  module woob obsolète en est la cause la plus fréquente). Si aucun compte n'a pu être synchronisé, la catégorisation
  est sautée : seule l'erreur est notifiée. Pendant une synchronisation, l'icône de l'onglet tourne.

La synchronisation de tous les comptes est planifiée côté serveur (`SYNC_INTERVAL` avec
Docker, ou une tâche cron).

### Authentification forte (2FA)

Certaines banques (Crédit Mutuel, CIC…) demandent régulièrement de valider la connexion
dans leur application. La synchronisation planifiée ne déclenche jamais cette validation :
elle réessaie d'abord le compte après `woob_2fa_wait` secondes (`woob_2fa_retries` fois :
la validation a pu être faite entre-temps sur le téléphone), puis, si la banque la demande
toujours, elle échoue, le compte passe en **Authentification forte requise** et chaque utilisateur
reçoit la notification « Strong authentication needed » sur l'app mobile. Le bouton
**Valider l'authentification forte** (sur la ligne du compte ou sous l'erreur) relance la
synchronisation de ce compte en mode interactif : woob déclenche la demande, son message
s'affiche (« validez dans votre application… ») et la synchronisation attend la validation
sur le téléphone. La session obtenue reste valable plusieurs semaines (souvent 90 jours,
règle DSP2) ; les synchronisations planifiées fonctionnent de nouveau jusque-là.

Les authentifications qui demandent de **saisir un code** (SMS) ne sont pas encore gérées.

## Agent IA

Connexion d'un agent IA (Claude, ChatGPT, Cursor, Copilot, Gemini…) au serveur MCP de
Carbure, en lecture seule. Voir [Agents IA (MCP)](mcp.md). L'onglet est dans le menu
**Administration** pour un administrateur, dans le menu utilisateur sinon.

- **Serveur MCP activé** : interrupteur réservé aux administrateurs (désactivé par
  défaut) ; les utilisateurs voient seulement son état.
- **Connecter Claude (web, Desktop, mobile)** : l'URL du serveur MCP à coller dans Claude
  (Paramètres → Connecteurs → Ajouter un connecteur personnalisé), sans jeton : Claude
  ramène au portail, qui demande d'**autoriser** l'accès.
- **+** : création d'un jeton d'accès (nom et durée de validité : 30 jours, 90 jours, 1 an
  ou sans expiration), possible seulement quand le serveur est activé. Le jeton et la
  configuration prête à copier pour chaque agent ne sont affichés qu'une fois.
- **Mes jetons d'accès** : date de création, dernière utilisation, expiration (ou
  « Expiré ») ; la corbeille révoque un jeton.

## Utilisateurs (menu Administration)

Liste des utilisateurs avec leur **profil** (Utilisateur ou Administrateur) et leur
**dernière connexion** ; un compte bloqué après trop d'échecs de connexion affiche
« Bloqué jusqu'au … » et un bouton **Débloquer**. **+** crée un utilisateur ; le crayon le
modifie (dont son profil) ; la corbeille le supprime. Le bouton **Réinitialiser la double
authentification** (après une confirmation qui en rappelle les conséquences, bouton **Réinitialiser**) supprime l'application, les codes de secours et
les navigateurs de confiance d'un utilisateur qui a perdu son téléphone. Le dernier administrateur ne peut
pas perdre son rôle, et un utilisateur propriétaire de transactions ne peut pas être
supprimé.

## Logs (menu Administration)

Les entrées des fichiers de log du serveur (`data/logs/carbure_AAAAMMJJ.log`), les plus
récentes d'abord : choix du fichier, niveau minimal (DEBUG, INFO, WARN, ERROR) et recherche
de texte. Chaque entrée indique l'adresse IP du client et l'utilisateur connecté ; un clic sur
l'identifiant d'une requête affiche toutes ses entrées, jusqu'à la ligne de fin (statut HTTP
et durée). Seule la fin d'un gros fichier (2 Mo) est lue ; les jetons de session et des
agents IA sont masqués. La durée de conservation des fichiers est rappelée en haut de page.

## Paramètres (menu Administration)

Le fichier de configuration (`data/conf/prod.ini`) section par section,
avec une description de chaque réglage. Les secrets (`db_password`, `jwtsecret`,
`password_salt`, `sync_token`…) ne sont jamais affichés. Seuls les réglages sans risque
sont modifiables — `log_level`, `log_retention_days`, `savings_category`, `public_url`, `mfa_required` (double authentification imposée), `woob_transactions`,
`woob_logging`, `woob_debug`, `woob_auto_update`, `woob_relabel`, le service de notifications push
(`push_api_key`, avec la clé de l'instance affichée à la demande) et le serveur SMTP des emails — avec des
listes, des interrupteurs et des champs vérifiés par le serveur ; les autres portent un
cadenas. Les réglages modifiés sont surlignés ; la barre du bas les enregistre ou les
annule. Le fichier précédent est gardé en `prod.ini.bak` (`GET`/`PUT /system/config`).

Le mot de passe SMTP (`smtp_password`) n'est jamais affiché : on en saisit un nouveau pour
le remplacer. Dans la section **Emails (SMTP)**, **Envoyer un email de test** envoie un
email avec la configuration enregistrée, à votre adresse ou à celle saisie, et affiche le
résultat ou la réponse du serveur en cas d'échec (`POST /system/smtp-test`).

## Synchro (menu Administration)

La commande `curl` qui lance la synchronisation de tous les comptes (ou d'un seul) et la
ligne crontab correspondante, à copier pour une tâche planifiée (cron, tâche planifiée
Synology). Le `sync_token` y est masqué jusqu'à **Afficher** ; le bouton copier copie
toujours la commande complète. **Créer un jeton** / **Renouveler** en écrit un nouveau dans
la configuration (`GET /system/sync`, `POST /system/sync-token`).

## Push mobile (menu Administration)

Le service de notifications push des applications mobiles (Carbure Push,
`https://api.carbure.org`), en trois blocs :

- **État du service** : le relais (opérationnel avec sa version, en erreur ou injoignable)
  et la clé d'API de l'instance (valide, avec son identifiant, ses appareils et ses
  notifications du jour sur ses limites ; refusée si elle est révoquée ; absente). Le
  bouton **Vérifier** interroge de nouveau le relais (`GET /system/push-status`, qui
  appelle `GET /v1/health` et `GET /v1/keys/current`).
- **Inscription** : la clé d'API de l'instance, réduite à son identifiant public
  (`cpk_<id>_••••`) et affichée en entier avec **Afficher la clé d'API**, le bouton
  **Copier** ; **Inscrire l'instance** (ou **Renouveler la clé**), après le consentement,
  qui est rappelé avec sa date.
- **Notification de test**, envoyée aux appareils de l'administrateur.

Le champ `push_api_key` reste dans **Paramètres** (pour coller une clé), avec un lien vers
cet onglet ; voir [Configuration](configuration.md#push).

## Sauvegarde (menu Administration)

- **Exporter les données** : on coche les données à sauvegarder (catégories, règles de
  catégorisation, budgets, insights, alertes des insights, transactions, transactions supprimées, tags et
  tags des transactions), chacune avec son
  nombre de lignes, puis **Exporter** télécharge `carbure-backup-AAAA-MM-JJ.zip`. Le ZIP
  contient un fichier JSON par type de données et un `manifest.json` (version du format,
  version de Carbure et du schéma, date, nombre de lignes). Les utilisateurs, mots de passe,
  jetons, appareils, réglages, connexions et comptes bancaires n'y figurent jamais.
- **Importer une sauvegarde** : on dépose un ZIP exporté par Carbure, ou un seul de ses
  fichiers JSON. Pour un ZIP, ses fichiers sont listés avec leur nombre de lignes et on coche
  ceux à importer (un fichier non reconnu est signalé et ne peut pas être coché).
  **Importer et remplacer** demande une confirmation : **l'import supprime toutes les
  données de chaque type importé et les remplace** par celles du fichier ; les types non
  importés sont conservés.
- Le serveur vérifie tout le fichier avant de supprimer quoi que ce soit, puis remplace les
  données en une seule transaction : en cas d'erreur, rien n'est modifié et l'erreur
  s'affiche dans la confirmation. Une catégorie encore utilisée par des données conservées
  (transactions, règles, budgets non importés) ne peut pas disparaître : on importe alors ces
  données avec les catégories. Les identifiants (dont l'UUID des transactions) sont
  conservés ; le propriétaire d'une transaction est retrouvé par son identifiant de
  connexion, à défaut c'est l'administrateur qui importe. Les alertes des insights d'un
  utilisateur inconnu ne sont pas importées.

## Purge (menu Administration)

Pour repartir de zéro, l'onglet **Purge** supprime toutes les lignes des données cochées
(mêmes données que la sauvegarde), chacune avec son nombre de lignes. Les données qui
dépendent d'une donnée cochée sont cochées avec elle et ne peuvent pas être décochées :
purger les catégories purge aussi les règles, les budgets et les transactions (et leurs
tags) ; purger les transactions ou les tags purge les tags des transactions ; purger les
insights purge leurs alertes. La catégorie par défaut est conservée, ainsi que les comptes
bancaires, les utilisateurs et les réglages : une synchronisation récupère à nouveau les
transactions.

**Purger** demande une confirmation qui rappelle le nombre de lignes supprimées : la purge
est **définitive**, faites d'abord une sauvegarde (bouton **Sauvegarder d'abord**). La
purge n'est acceptée que **depuis le réseau local** : derrière un reverse proxy, une
adresse publique dans `X-Forwarded-For`, `X-Real-IP` ou `Forwarded` la refuse, et l'onglet
le signale.

## API (menu Administration)

La documentation de l'API REST (Swagger UI de `/swagger/`, décrite par
`swagger/swagger.php`) affichée dans le portail, dans le thème clair ou sombre de celui-ci.
Swagger UI reprend la session de l'administrateur connecté : **Try it out** appelle
l'API (`/api/...`) avec son jeton dans l'en-tête `Authorization`, jamais dans l'URL ; les
requêtes s'exécutent réellement (une route `POST`/`PUT`/`DELETE` modifie les données).
**Ouvrir Swagger UI** l'ouvre dans un nouvel onglet.
Si un proxy inverse interdit d'afficher `/swagger/` dans un cadre (en-tête
`X-Frame-Options: DENY` ou `frame-ancestors 'none'`), l'onglet le signale à la place du cadre
vide : laissez passer `X-Frame-Options: SAMEORIGIN` / `frame-ancestors 'self'`, ou utilisez
**Ouvrir Swagger UI**.

## Mon profil

Quatre sous-onglets, comme l'administration : **Profil**, **Double authentification**,
**Notifications** et **Mes appareils** (ouvert sur **Profil** depuis le menu utilisateur).

- **Profil** : modification de l'e-mail, du prénom, du nom et du mot de passe (laisser
  vide pour le conserver). L'identifiant n'est pas modifiable. **Langue** (Français,
  English, Español, Italiano, Deutsch, Português ; chacune écrite dans sa langue) : enregistrée sur le compte et appliquée à chaque connexion, sur tous les
  navigateurs. **Thème** (*Comme le système*, *Clair*, *Sombre*), comme le choix fait avec
  l'icône de l'en-tête (voir [Thèmes](#themes-et-preferences-du-systeme)).
- **Notifications** : un tableau type de notification × canal (**Push app mobile**, sur
  l'app iOS ou Android via le relais Carbure Push, et email)
  pour la synchronisation, les règles « Notifier », les dépenses importantes et les budgets
  dépassés (désactivés par défaut). Chaque case s'enregistre aussitôt ; les notifications
  sont envoyées dans la **langue** du profil. Les réglages d'une alerte sont sous son
  libellé, dans le tableau, et s'enregistrent à la validation ou en quittant le champ (une
  valeur invalide est signalée sous le champ et n'est pas envoyée) :
  - **Dépense importante** : montant **à partir duquel** une nouvelle dépense, détectée
    lors d'une synchronisation, est notifiée (vide = désactivé) ;
  - **Budget dépassé** : la case **Tous les budgets** (cochée par défaut) et le pourcentage
    du budget auquel **alerter** (entier de 50 à 200, 100 par défaut), désactivé avec la
    case ; puis les **Seuils par catégorie** : **Ajouter un seuil pour une catégorie** crée
    une ligne avec le premier budget du mois sans seuil (au seuil global), où choisir le
    budget parmi ceux de l'onglet **Budget**, dans son ordre (une sous-catégorie est
    présentée « Catégorie › Sous-catégorie »), et son pourcentage ; la corbeille retire la
    ligne. Le seuil d'une catégorie remplace le seuil global pour elle.

  Toutes les cases de la colonne push sont désactivées et grisées, avec la raison sous le
  tableau, tant que l'administrateur n'a pas inscrit l'instance au service Carbure Push
  (pas de `push_api_key`). Les cases email non cochées sont grisées tant qu'aucun serveur
  SMTP n'est configuré ou que le profil n'a pas d'adresse email (une case cochée reste
  modifiable, pour la décocher) (`GET`/`PUT /user/notifications`).
- **Double authentification** (sous-onglet dédié, absent tant que la migration n'est pas
  appliquée) : état (activée ou non, imposée ou non par
  l'administrateur). **Configurer une application** affiche le QR code (grand, sur fond
  blanc, adapté à la largeur d'un téléphone) et la clé, à
  confirmer par un premier code ; les 10 codes de secours s'affichent alors une seule
  fois. Une fois activée, un code de l'application permet d'obtenir de **nouveaux codes de
  secours**, et un code de l'application ou de secours de la **désactiver** (après
  confirmation). Les navigateurs de confiance sont dans **Mes appareils**.
- **Mes appareils** :
  - **Connecter l'application mobile** : QR code
    `https://carbure.org/app/?server=<adresse de l'API>` à scanner avec l'appareil photo du
    téléphone, qui ouvre l'app sur ce serveur (voir
    [Applications mobiles](applications-mobiles.md#lien-de-connexion)), l'adresse affichée
    et un bouton **Copier le lien** ; l'adresse est celle de `public_url` si elle est
    renseignée (`GET /device/link`), sinon celle du portail, et un avertissement s'affiche
    pour `localhost` ;
  - **Applications mobiles** : appareils enregistrés à la connexion depuis l'app, avec
    leur plateforme (icône et libellé **iOS** ou **Android**), la date de dernière connexion,
    le modèle, la version du système et celle de l'application quand l'app les envoie
    (« iPhone16,1 · iOS 26.0 · app 1.2 ») et le token de notification, masqué par défaut
    (« Notifications indisponibles » quand l'app n'en a pas envoyé). Les boutons permettent de l'afficher en
    entier, de le copier (HTTPS requis ; sinon le token est affiché) ou de **supprimer** un
    ancien appareil (`GET`/`DELETE /device`) ;
  - **Navigateurs web** : navigateurs depuis lesquels l'utilisateur s'est connecté au
    portail, avec un nom lisible tiré du User-Agent, le badge **Ce navigateur** pour celui
    en cours, la date de dernière et de première connexion, et un bouton **Supprimer** (après
    confirmation) qui l'oublie sans fermer sa session (`GET`/`DELETE /user/browser`) ;
  - **Navigateurs de confiance** : navigateurs web où le code de double authentification
    n'est pas redemandé pendant 6 mois, avec un nom lisible tiré du User-Agent (« Firefox
    sur Windows »), la date de dernière utilisation et l'échéance, et leur bouton
    **Révoquer** (`GET /user/mfa`, `DELETE /user/mfa/browser`).

## Pied de page

Lien vers le site de Carbure ([carbure.org](https://carbure.org/)), licence et **version**
de l'instance (`v1.2.0`, ou `dev` hors release), lue dans `GET /api/health`.

## Accessibilité

Le portail vise le niveau AA des WCAG (score Lighthouse « Accessibilité » de 100 sur les
onglets et les fenêtres) :

- contrastes suffisants (rouge de la marque assombri à 5:1 sur blanc), au repos **et au
  survol** des boutons (un bouton à fond clair garde son fond au survol), et focus clavier
  toujours visible ;
- lien « Aller au contenu », repères de page (bannière, navigation, contenu principal) et
  onglet courant annoncé ;
- icônes décoratives masquées aux lecteurs d'écran, boutons à icône seule nommés, champs
  associés à leur libellé, fenêtres modales annoncées comme dialogues et fermées avec
  **Échap** ;
- messages (toasts, erreurs) annoncés par les lecteurs d'écran, langue de la page suivant
  celle de l'utilisateur ;
- graphiques doublés d'un tableau (Tendances, Flux du mois) et animations coupées quand le
  système demande moins de mouvements.

### Thèmes et préférences du système

Le portail suit les réglages d'affichage déjà choisis dans le système, sans thème à
activer :

- **clair ou sombre** : par défaut, le thème suit la préférence du système (et ses
  changements) ; le profil permet de le fixer (**Thème** : *Comme le système*, *Clair*,
  *Sombre*), choix enregistré sur le serveur et retrouvé sur un autre navigateur. Le thème
  est appliqué avant l'affichage de la page (`portal/theme.js`), sans éclair de l'autre
  thème ;
- **bouton de thème** : l'icône à droite de l'en-tête, juste avant le menu utilisateur,
  passe d'un clic au thème opposé à celui affiché (lune `dark_mode` en thème clair :
  *Passer au thème sombre* ; soleil `light_mode` en thème sombre : *Passer au thème
  clair*). Le choix est enregistré dans le profil comme le sélecteur **Thème**, qui le
  reflète ; pour suivre de nouveau le système, choisir *Comme le système* dans le
  profil. L'écran de connexion n'a pas ce bouton (il applique le thème mémorisé par le
  navigateur, sinon celui du système) ;
- **contraste augmenté** (macOS, iOS, `prefers-contrast: more`) : textes secondaires et
  bordures plus foncés (plus clairs en sombre), anneau de focus plus épais ;
- **couleurs forcées** (mode contraste élevé de Windows, `forced-colors`) : les couleurs
  sont celles du système ; boutons, onglets, champs, bascules, barres de budget, cartes et
  focus gardent une bordure ou un contour visible.

![Écran Transactions en thème sombre](assets/screenshot-theme-dark.webp)

![Écran Transactions avec le contraste augmenté du système : textes secondaires et bordures plus foncés](assets/screenshot-theme-contrast.webp)

![Écran Transactions en couleurs forcées (contraste élevé de Windows) : couleurs du système, chaque bouton, onglet et carte gardant une bordure](assets/screenshot-theme-forced-colors.webp)

Le thème sombre respecte les mêmes règles que le thème clair (4,5:1 pour un texte, 3:1
pour une icône, au repos, au survol et au focus) : les couleurs d'accent y sont plus
claires et le texte posé dessus foncé ; les couleurs des catégories sont éclaircies
(`--category-ink`) pour rester lisibles sur fond sombre.

Les contrastes sont vérifiés par les tests e2e (`tests/e2e/specs/accessibility.spec.js`,
en thème clair et en thème sombre ; `themes.spec.js` pour le contraste augmenté et les
couleurs forcées) :
chaque texte de chaque onglet, des menus et des fenêtres, et chaque bouton au repos et au
survol doivent atteindre 4,5:1 (3:1 pour une icône ou un grand texte). Les couleurs
choisies par les utilisateurs (catégories) et les graphiques SVG ne sont pas mesurés.

## Mode debug

Ajouter `?debug=true` à l'URL affiche un panneau avec la dernière requête API et active
des logs dans la console du navigateur.
