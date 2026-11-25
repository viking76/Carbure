---
title: "Insights : recettes de requêtes SQL"
description: "Des recettes d'insights pour Carbure : dépenses, comparaisons, épargne, budget et qualité des données, avec leur requête SQL."
icon: material/chart-box-outline
---

# Recettes d'insights

Un **insight** est une carte de l'écran Insights : un nom, une couleur, une icône et une
**requête SQL** qui renvoie **un montant pour le mois affiché** (voir
[Fonctionnalités](fonctionnalites.md#insights) et [Portail web](portail.md#insights)). Cette
page rassemble des **recettes** prêtes à copier dans la fenêtre d'ajout d'un insight
(administrateurs) : pour chacune, ce qu'elle montre, la requête et la lecture du résultat.

Toutes les requêtes de cette page sont vérifiées par les tests de Carbure : elles respectent
les règles ci-dessous et s'exécutent sur une base de test, avec le résultat attendu.

![Fenêtre d'ajout d'un insight remplie avec la recette « Panier moyen des courses » : nom, couleur, icône et requête SQL, vérifiée pendant la saisie avec son résultat pour le mois affiché](assets/screenshot-insight-recipe.webp)

## Règles d'une requête

Le serveur refuse une requête qui ne les respecte pas (la fenêtre d'ajout l'indique pendant
la saisie) :

- **une seule instruction `SELECT`**, de **500 caractères au plus** (espaces et retours à la
  ligne compris), sans `;` au milieu, ni commentaire (`--`, `#`, `/* */`), ni barre oblique
  inverse (`\`) ; `WITH` n'est pas accepté, une sous-requête dans le `FROM` l'est ;
- elle renvoie une **colonne nommée `amount`** (`… AS amount`) : seule la **première ligne**
  est lue ;
- `{month}` et `{year}` sont remplacés par le **mois** (1 à 12) et l'**année** affichés,
  sous forme de nombres ;
- **mots interdits**, même dans un texte entre apostrophes : écriture (`INSERT`, `UPDATE`,
  `DELETE`, `CREATE`, `DROP`…), `SET`, `DO`, `INTO`, `LOAD`, `SLEEP`, `BENCHMARK`, `SHOW`,
  `CALL`, `LOCK`, `USER`, `PASSWORD`… (liste complète dans `src/resources/Insight.php`) ;
  la colonne `user` des transactions ne peut donc pas être lue ;
- **seules les tables du budget sont lisibles** : `bank_transaction`,
  `bank_transaction_category`, `bank_transaction_category_keyword` (règles de
  catégorisation), `budget`, `bank_account` et `bank_backend` (connexions bancaires), `tag` et
  `transaction_tag` (tags de projet), sans
  nom de base devant (`carbure.bank_transaction` est refusé). Toute autre table est refusée :
  utilisateurs, double authentification, jetons, appareils, OAuth, paramètres, insights,
  migrations… ainsi que les schémas système (`mysql`, `information_schema`,
  `performance_schema`, `sys`). Le nom d'une table refusée est interdit partout dans la
  requête, même dans un texte entre apostrophes ;
- la requête s'exécute dans une **transaction en lecture seule**, en **5 secondes au plus** :
  au-delà, le serveur l'interrompt (la fenêtre d'ajout l'indique, la carte affiche
  0,00 €). Elle est exécutée à chaque affichage de l'onglet Insights, et douze fois pour
  l'historique d'une année : évitez les produits cartésiens.

Le **nom** compte de 1 à **20 caractères** ; la **couleur** est l'une des 13 couleurs :
`red`, `orange`, `amber`, `lime`, `green`, `teal`, `cyan`, `blue`, `indigo`, `purple`,
`pink`, `brown`, `gray`. L'icône (facultative) est une icône Material choisie dans la
fenêtre d'ajout.

## Affichage du résultat

La carte affiche **toujours la valeur absolue du montant, avec deux décimales et le symbole
`€`**, en **rouge si le montant est négatif**. Il n'y a pas d'autre format :

- un **pourcentage** ou un **nombre** (de transactions, de mois…) s'affiche aussi avec `€` :
  un taux d'épargne de 32,5 % apparaît « 32,50 € ». Les recettes concernées l'indiquent
  dans leur nom (`%`, « Nb ») pour lever l'ambiguïté ;
- un montant négatif s'affiche avec son **signe** (« -1 584,20 € ») et en rouge, une
  rentrée ou un écart favorable sans signe ni rouge. Les recettes suivent la convention des
  transactions : **négatif = argent qui sort** ;
- une requête qui ne renvoie rien (`NULL`, par exemple aucune transaction ce mois-là) ou qui
  échoue affiche **0,00 €** ;
- l'historique mois par mois (`GET /budget/insights/history`, utilisé par l'app iPhone)
  exécute la même requête pour chaque mois de l'année.

## Repères sur les données

Les tables utiles (voir [Modèle de données](modele-donnees.md)) :

| Table | Colonnes utiles |
|---|---|
| `bank_transaction` | `date` (date de débit, celle du mois affiché), `rdate` (date réelle de l'opération : jour d'un paiement par carte), `type`, `label`, `category`, `amount` (négatif = débit), `pointed` (1 = vérifiée), `bank_name`, `account_number`, `split_of` (une part d'une transaction répartie : l'id de la transaction d'origine, sinon `NULL`) |
| `bank_transaction_category` | `id`, `name`, `parent_category` (0 = catégorie principale), `type` : `DEBIT`, `CREDIT` ou `HORS-BUDGET` |
| `budget` | `category`, `amount` (montant positif prévu), `date` (premier jour du mois), `note` |
| `bank_account` | `bank_name`, `account_number`, `label` (nom donné au compte) |
| `tag` | `id`, `name` (sans le `#`), `start_date`, `end_date` |
| `transaction_tag` | `transaction_id` (`bank_transaction.id`), `tag_id` (`tag.id`) |

**Types de transaction** (`bank_transaction.type`, codes de woob, aussi déduits du libellé
pour un relevé importé) :

| Code | Type | Code | Type |
|---|---|---|---|
| 0 | Inconnu | 6 | Retrait DAB |
| 1 | Virement | 7 | Carte |
| 2 | Prélèvement | 8 | Dépense (remboursement de prêt) |
| 3 | Chèque | 9 | Commissions, frais bancaires |
| 4 | Remise de chèque | 12 | Carte à débit différé |
| 5 | Remboursement | | |

Quelques expressions reviennent dans les recettes :

- **dépenses du budget** : `amount < 0 AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)`
  écarte les catégories hors budget (épargne, virements internes) mais garde les
  transactions non catégorisées (catégorie 0) ;
- **premier jour du mois affiché** : `MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH` ;
  `- 2 MONTH` donne le mois précédent, `MONTH` le mois suivant. Un filtre
  `date >= … AND date < …` couvre une période de plusieurs mois, même à cheval sur deux
  années ;
- **une catégorie par son nom** plutôt que par son numéro (qui change d'une instance à
  l'autre) : `(SELECT id FROM bank_transaction_category WHERE name = 'Courses')`. La
  comparaison ignore la casse et les accents. Les noms utilisés ici sont ceux du pack de
  départ en français : adaptez-les aux vôtres.

!!! note "Transactions réparties"
    Une transaction répartie sur plusieurs catégories occupe plusieurs lignes : la
    transaction d'origine, qui garde le reste du montant, et une ligne par part
    (`split_of` = l'id de l'origine), avec la même date, le même libellé et le même type.
    Une **somme** reste donc juste. Pour **compter des paiements** (un retrait, un passage
    en caisse), comptez `COUNT(DISTINCT COALESCE(split_of, id))` plutôt que `COUNT(*)` ;
    pour le **montant de la banque** d'un paiement, partez des lignes `split_of IS NULL` et
    ajoutez leurs parts :
    `amount + IFNULL((SELECT SUM(p.amount) FROM bank_transaction p WHERE p.split_of = t.id), 0)`.

## Dépenses

### Une catégorie et ses sous-catégories

Les dépenses d'une catégorie principale, sous-catégories comprises (ici « Alimentation » :
courses, boulangerie, restaurants). Remplacez le nom pour suivre le logement, les
transports, les loisirs…

```sql
SELECT SUM(t.amount) AS amount
FROM bank_transaction t
JOIN bank_transaction_category c ON c.id = t.category
WHERE 'Alimentation' IN (c.name, (SELECT p.name FROM bank_transaction_category p WHERE p.id = c.parent_category))
  AND MONTH(t.date) = {month} AND YEAR(t.date) = {year}
```

- **Nom** : `Alimentation` — couleur `green`, icône `restaurant`
- **Lecture** : négatif (rouge), en euros ; les remboursements de la catégorie viennent en
  déduction.

### Panier moyen des courses

Le montant moyen d'un passage en caisse : utile pour voir l'effet de l'inflation ou d'un
changement d'enseigne, indépendamment du nombre de passages.

```sql
SELECT SUM(amount) / COUNT(DISTINCT COALESCE(split_of, id)) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category = (SELECT id FROM bank_transaction_category WHERE name = 'Courses')
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Panier moyen courses` — couleur `green`, icône `shopping_cart`
- **Lecture** : négatif, en euros par achat. Un ticket réparti compte pour un achat, avec sa
  part de courses.

### Une enseigne

Tout ce qui a été payé à un commerçant, quelle que soit sa catégorie, par un mot du
libellé (ici Amazon). `LIKE` ignore la casse ; `%` remplace n'importe quelle suite de
caractères.

```sql
SELECT SUM(amount) AS amount
FROM bank_transaction
WHERE label LIKE '%AMAZON%'
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Amazon` — couleur `orange`, icône `shopping_cart`
- **Lecture** : négatif, en euros, net des remboursements de l'enseigne.

### Livraison de repas

Plusieurs enseignes d'un même usage, ici la livraison à domicile : un poste qui grossit
vite sans qu'on s'en rende compte.

```sql
SELECT SUM(amount) AS amount
FROM bank_transaction
WHERE (label LIKE '%UBER EATS%' OR label LIKE '%DELIVEROO%')
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Livraison repas` — couleur `pink`, icône `restaurant`
- **Lecture** : négatif, en euros.

### Prélèvements récurrents

Les prélèvements du mois dont le même libellé était déjà prélevé le mois précédent :
abonnements, énergie, assurances, téléphone. C'est le « coût fixe » du foyer, à revoir de
temps en temps.

```sql
SELECT SUM(t.amount) AS amount
FROM bank_transaction t
WHERE t.type = 2 AND t.amount < 0
  AND MONTH(t.date) = {month} AND YEAR(t.date) = {year}
  AND EXISTS (SELECT 1 FROM bank_transaction p WHERE p.type = 2 AND p.label = t.label
    AND p.date >= MAKEDATE({year}, 1) + INTERVAL {month} - 2 MONTH
    AND p.date < MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH)
```

- **Nom** : `Prélèv. récurrents` — couleur `indigo`, icône `receipt_long`
- **Lecture** : négatif, en euros. Un prélèvement dont le libellé change chaque mois (numéro
  de facture, date) n'est pas reconnu comme récurrent.

### Dépenses du week-end

Les dépenses faites un samedi ou un dimanche, d'après la date réelle de l'opération
(`rdate`) : sorties, courses du samedi, achats d'impulsion.

```sql
SELECT SUM(amount) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND DAYOFWEEK(rdate) IN (1, 7)
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Dépenses week-end` — couleur `cyan`, icône `sports_esports`
- **Lecture** : négatif, en euros (`DAYOFWEEK` : 1 = dimanche, 7 = samedi).

### Part des dépenses du week-end

La même chose en **pourcentage** des dépenses du mois : un week-end compte pour 2 jours sur
7, soit environ 29 % ; une part bien au-dessus signale des week-ends coûteux.

```sql
SELECT 100 * SUM(IF(DAYOFWEEK(rdate) IN (1, 7), amount, 0)) / SUM(amount) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Part week-end %` — couleur `cyan`, icône `percent`
- **Lecture** : positif, en **pourcentage** (affiché « 27,55 € » pour 27,55 %).

### Part payée par carte

Le pourcentage des dépenses payées par carte (immédiate ou à débit différé), le reste étant
prélevé, viré ou retiré : les dépenses « du quotidien » face aux charges fixes.

```sql
SELECT 100 * SUM(IF(type IN (7, 12), amount, 0)) / SUM(amount) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Part carte %` — couleur `blue`, icône `credit_card`
- **Lecture** : positif, en **pourcentage**.

### Nombre de retraits

Le nombre de retraits d'espèces du mois (le pack de départ en donne déjà le montant) :
beaucoup de petits retraits, c'est autant de dépenses que Carbure ne pourra pas classer.

```sql
SELECT COUNT(DISTINCT COALESCE(split_of, id)) AS amount
FROM bank_transaction
WHERE type = 6
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Nb retraits` — couleur `gray`, icône `account_balance_wallet`
- **Lecture** : positif, un **nombre** (affiché « 3,00 € » pour 3 retraits).

### Plus gros poste du mois

Le montant de la catégorie (ou sous-catégorie) la plus coûteuse du mois, hors non
catégorisé. Un insight ne renvoie qu'un nombre : le nom du poste se lit dans l'onglet
Budget.

```sql
SELECT MIN(total) AS amount
FROM (SELECT SUM(amount) AS total FROM bank_transaction
  WHERE category <> 0
    AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET')
    AND MONTH(date) = {month} AND YEAR(date) = {year}
  GROUP BY category) x
```

- **Nom** : `Plus gros poste` — couleur `red`, icône `pie_chart`
- **Lecture** : négatif, en euros.

### Dépenses d'un compte

Les dépenses d'un seul compte, désigné par le nom qui lui a été donné dans l'onglet Comptes
(ici « Compte joint ») : utile quand le foyer a un compte commun et des comptes personnels.

```sql
SELECT SUM(amount) AS amount
FROM bank_transaction
WHERE amount < 0
  AND account_number IN (SELECT account_number FROM bank_account WHERE label = 'Compte joint')
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Compte joint` — couleur `teal`, icône `home`
- **Lecture** : négatif, en euros, épargne et virements compris (ajoutez le filtre des
  dépenses du budget pour les écarter).

## Comparaisons

### Variation par rapport au mois précédent

L'évolution des dépenses en **pourcentage** (le pack de départ donne l'écart en euros) :
+10 % signifie 10 % de dépenses en plus que le mois précédent.

```sql
SELECT 100 * (SUM(IF(MONTH(date) = {month}, amount, 0)) / SUM(IF(MONTH(date) = {month}, 0, amount)) - 1) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND date >= MAKEDATE({year}, 1) + INTERVAL {month} - 2 MONTH
  AND date < MAKEDATE({year}, 1) + INTERVAL {month} MONTH
```

- **Nom** : `Variation vs M-1 %` — couleur `cyan`, icône `analytics`
- **Lecture** : en **pourcentage** ; positif (couleur normale) = plus de dépenses, négatif (rouge) =
  moins de dépenses. Attention, la couleur est ici inversée par rapport au sens « bon /
  mauvais ».

### Écart avec le même mois l'an dernier

Les dépenses du mois moins celles du même mois de l'année précédente : la bonne comparaison
pour les dépenses saisonnières (chauffage, rentrée, vacances).

```sql
SELECT SUM(IF(YEAR(date) = {year}, amount, -amount)) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND MONTH(date) = {month} AND YEAR(date) IN ({year}, {year} - 1)
```

- **Nom** : `Écart vs N-1` — couleur `purple`, icône `show_chart`
- **Lecture** : en euros ; négatif (rouge) = plus de dépenses que l'an dernier, positif =
  moins.

### Écart avec la moyenne des 3 derniers mois

Les dépenses du mois moins la moyenne des 3 mois précédents : moins sensible qu'une
comparaison à un seul mois exceptionnel. Pour 6 mois, remplacez `/ 3` par `/ 6` et
`- 4 MONTH` par `- 7 MONTH`.

```sql
SELECT SUM(IF(date >= MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH, amount, -amount / 3)) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND date >= MAKEDATE({year}, 1) + INTERVAL {month} - 4 MONTH
  AND date < MAKEDATE({year}, 1) + INTERVAL {month} MONTH
```

- **Nom** : `Écart vs moy. 3 mois` — couleur `amber`, icône `analytics`
- **Lecture** : en euros ; négatif (rouge) = mois plus dépensier que d'habitude.

### Cumul depuis janvier

Les dépenses de l'année jusqu'au mois affiché inclus : à rapprocher d'un budget annuel.

```sql
SELECT SUM(amount) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND YEAR(date) = {year} AND MONTH(date) <= {month}
```

- **Nom** : `Cumul depuis janvier` — couleur `red`, icône `trending_down`
- **Lecture** : négatif, en euros.

### Moyenne mensuelle sur 12 mois

La dépense moyenne par mois sur les 12 mois se terminant au mois affiché : le train de vie
du foyer, lissé des dépenses exceptionnelles.

```sql
SELECT SUM(amount) / 12 AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND date >= MAKEDATE({year}, 1) + INTERVAL {month} - 12 MONTH
  AND date < MAKEDATE({year}, 1) + INTERVAL {month} MONTH
```

- **Nom** : `Moyenne 12 mois` — couleur `teal`, icône `show_chart`
- **Lecture** : négatif, en euros par mois. Pendant la première année d'utilisation, les mois
  sans données comptent pour zéro et font baisser la moyenne.

## Épargne et matelas de sécurité

### Taux d'épargne

La part des revenus du mois qui n'a pas été dépensée : (revenus + dépenses) / revenus, hors
catégories hors budget. Un repère courant est de viser 10 à 20 %.

```sql
SELECT 100 * SUM(amount) / SUM(IF(amount > 0, amount, 0)) AS amount
FROM bank_transaction
WHERE category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Taux d'épargne %` — couleur `purple`, icône `savings`
- **Lecture** : en **pourcentage** ; négatif (rouge) = mois dépensé au-delà des revenus.
  Vide (0,00) sans revenu ce mois-là.

### Épargne versée

Les versements du mois vers l'épargne : catégorie « Épargne » et ses sous-catégories
(livrets, assurance vie). Le pack de départ propose la même chose par numéros de catégorie.

```sql
SELECT -SUM(t.amount) AS amount
FROM bank_transaction t
JOIN bank_transaction_category c ON c.id = t.category
WHERE 'Épargne' IN (c.name, (SELECT p.name FROM bank_transaction_category p WHERE p.id = c.parent_category))
  AND MONTH(t.date) = {month} AND YEAR(t.date) = {year}
```

- **Nom** : `Épargne versée` — couleur `purple`, icône `savings`
- **Lecture** : **positif** = argent mis de côté (le signe est inversé par le `-SUM`) ;
  négatif (rouge) = plus de retraits que de versements.

### Matelas de sécurité (6 mois)

Les dépenses des 6 mois complets précédant le mois affiché, en montant positif : l'ordre de
grandeur de l'épargne de précaution à garder disponible. Le mois en cours n'est pas compté,
pour que la valeur ne bouge pas au fil du mois.

```sql
SELECT -SUM(amount) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND date >= MAKEDATE({year}, 1) + INTERVAL {month} - 7 MONTH
  AND date < MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH
```

- **Nom** : `Matelas 6 mois` — couleur `lime`, icône `savings`
- **Lecture** : positif, en euros.

### Mois de dépenses couverts

Combien de mois de dépenses l'épargne versée **depuis le début des données** couvrirait, au
rythme moyen des 6 derniers mois complets. Carbure ne connaît pas le **solde** des comptes
d'épargne (ni les intérêts, ni l'épargne antérieure à la première synchronisation) : c'est
un indicateur de tendance, pas un patrimoine.

```sql
SELECT SUM(IF('Épargne' IN (c.name, p.name), -t.amount, 0)) / SUM(IF(t.amount < 0 AND (c.type <> 'HORS-BUDGET' OR c.id = 0)
  AND t.date >= MAKEDATE({year}, 1) + INTERVAL {month} - 7 MONTH, -t.amount / 6, 0)) AS amount
FROM bank_transaction t
JOIN bank_transaction_category c ON c.id = t.category
JOIN bank_transaction_category p ON p.id = c.parent_category
WHERE t.date < MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH
```

- **Nom** : `Matelas en mois` — couleur `lime`, icône `star`
- **Lecture** : positif, un **nombre de mois** (« 3,60 € » = 3,6 mois). Un repère courant
  est de 3 à 6 mois.

### Dépenses projetées en fin de mois

Les dépenses du mois extrapolées au rythme actuel : 600 € dépensés au 10 d'un mois de
30 jours donnent 1 800 €. Pour un mois passé, c'est simplement la dépense du mois.

```sql
SELECT SUM(amount) * DAY(LAST_DAY(MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH))
  / IF(YEAR(CURDATE()) = {year} AND MONTH(CURDATE()) = {month}, DAY(CURDATE()), DAY(LAST_DAY(MAKEDATE({year}, 1) + INTERVAL {month} - 1 MONTH))) AS amount
FROM bank_transaction
WHERE amount < 0
  AND category NOT IN (SELECT id FROM bank_transaction_category WHERE type = 'HORS-BUDGET' AND id <> 0)
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Dépenses projetées` — couleur `orange`, icône `trending_down`
- **Lecture** : négatif, en euros. Les prélèvements fixes en début de mois gonflent la
  projection des premiers jours.

## Budget

Les budgets se saisissent dans l'onglet Budget, par catégorie et par mois ; la dépense d'une
catégorie comprend celle de ses sous-catégories. Si vous fixez un budget à la fois à une
catégorie et à ses sous-catégories, leurs dépenses sont comptées deux fois dans ces recettes.

### Budget restant

Ce qu'il reste à dépenser sur l'ensemble des budgets de dépenses du mois (budgets moins
dépenses des catégories budgétées).

```sql
SELECT SUM(b.amount + (SELECT IFNULL(SUM(t.amount), 0) FROM bank_transaction t
    JOIN bank_transaction_category c ON c.id = t.category
    WHERE (c.id = b.category OR c.parent_category = b.category)
      AND MONTH(t.date) = {month} AND YEAR(t.date) = {year})) AS amount
FROM budget b
JOIN bank_transaction_category bc ON bc.id = b.category
WHERE bc.type = 'DEBIT' AND MONTH(b.date) = {month} AND YEAR(b.date) = {year}
```

- **Nom** : `Budget restant` — couleur `blue`, icône `account_balance_wallet`
- **Lecture** : en euros ; positif = marge restante, négatif (rouge) = budgets dépassés au
  total (un dépassement peut être compensé par une catégorie sous son budget).

### Catégories hors budget

Le nombre de catégories dont la dépense du mois dépasse le budget (le pack de départ donne
le montant total des dépassements).

```sql
SELECT COUNT(*) AS amount
FROM budget b
WHERE MONTH(b.date) = {month} AND YEAR(b.date) = {year}
  AND b.amount + (SELECT IFNULL(SUM(t.amount), 0) FROM bank_transaction t
    JOIN bank_transaction_category c ON c.id = t.category
    WHERE (c.id = b.category OR c.parent_category = b.category)
      AND MONTH(t.date) = {month} AND YEAR(t.date) = {year}) < 0
```

- **Nom** : `Catégories dépassées` — couleur `red`, icône `warning`
- **Lecture** : positif, un **nombre** de catégories.

### Budget consommé

Le pourcentage de l'ensemble des budgets de dépenses déjà consommé : à comparer avec
l'avancement du mois (50 % le 15).

```sql
SELECT -100 * SUM((SELECT IFNULL(SUM(t.amount), 0) FROM bank_transaction t
    JOIN bank_transaction_category c ON c.id = t.category
    WHERE (c.id = b.category OR c.parent_category = b.category)
      AND MONTH(t.date) = {month} AND YEAR(t.date) = {year})) / SUM(b.amount) AS amount
FROM budget b
JOIN bank_transaction_category bc ON bc.id = b.category
WHERE bc.type = 'DEBIT' AND MONTH(b.date) = {month} AND YEAR(b.date) = {year}
```

- **Nom** : `Budget consommé %` — couleur `amber`, icône `pie_chart`
- **Lecture** : positif, en **pourcentage** ; au-delà de 100, les budgets sont dépassés.

## Qualité des données

### Transactions non catégorisées

Le nombre de transactions du mois sans catégorie (le pack de départ en donne le montant) :
chacune est une règle de catégorisation à créer.

```sql
SELECT COUNT(DISTINCT COALESCE(split_of, id)) AS amount
FROM bank_transaction
WHERE category = 0
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Nb non catégorisées` — couleur `orange`, icône `warning`
- **Lecture** : positif, un **nombre** de transactions ; l'objectif est 0.

### Transactions non vérifiées

Le nombre de transactions du mois que personne n'a encore pointées (case « vérifiée » de
l'onglet Transactions).

```sql
SELECT COUNT(DISTINCT COALESCE(split_of, id)) AS amount
FROM bank_transaction
WHERE pointed = 0
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Nb non vérifiées` — couleur `amber`, icône `receipt_long`
- **Lecture** : positif, un **nombre** de transactions.

### Frais bancaires de l'année

Les frais bancaires depuis janvier (cotisations de carte, commissions, agios), par type ou
par catégorie : à comparer aux offres des autres banques.

```sql
SELECT SUM(amount) AS amount
FROM bank_transaction
WHERE (type = 9 OR category = (SELECT id FROM bank_transaction_category WHERE name = 'Frais bancaires'))
  AND YEAR(date) = {year} AND MONTH(date) <= {month}
```

- **Nom** : `Frais de l'année` — couleur `brown`, icône `account_balance_wallet`
- **Lecture** : négatif, en euros.

### Remboursements reçus

Les remboursements du mois (santé, achats retournés…), par type ou par catégorie : pour
vérifier qu'un remboursement attendu est bien arrivé.

```sql
SELECT SUM(amount) AS amount
FROM bank_transaction
WHERE amount > 0
  AND (type = 5 OR category = (SELECT id FROM bank_transaction_category WHERE name = 'Remboursements'))
  AND MONTH(date) = {month} AND YEAR(date) = {year}
```

- **Nom** : `Remboursements` — couleur `green`, icône `payments`
- **Lecture** : positif, en euros.

### Doublons possibles

Le nombre de paires de transactions du mois au même libellé et au même montant, à moins de
3 jours d'écart : un paiement débité deux fois, ou un relevé importé en plus de la
synchronisation.

```sql
SELECT COUNT(*) AS amount
FROM bank_transaction a
JOIN bank_transaction b ON b.id > a.id AND b.amount = a.amount AND b.label = a.label
  AND ABS(DATEDIFF(b.date, a.date)) <= 3
  AND a.split_of IS NULL AND b.split_of IS NULL
WHERE MONTH(a.date) = {month} AND YEAR(a.date) = {year}
```

- **Nom** : `Doublons possibles` — couleur `red`, icône `warning`
- **Lecture** : positif, un **nombre** de paires ; deux vrais achats identiques (deux
  baguettes le même jour) sont aussi comptés, mais pas les parts d'une transaction
  répartie.

## Ce qu'un insight ne peut pas montrer

- **Un texte** : un insight renvoie un nombre, pas le nom d'un commerçant ou d'une catégorie
  (le « plus gros poste » donne son montant, pas son nom).
- **Plusieurs valeurs** : une seule carte, une seule valeur par mois ; pour une répartition,
  voir les onglets Budget et Tendances.
- **Un pourcentage ou un nombre sans `€`** : l'unité est toujours l'euro à l'affichage
  (voir [Affichage du résultat](#affichage-du-resultat)).
- **Les dépenses d'une personne du foyer** : le modèle est commun au foyer et le mot `USER`
  est interdit ; filtrez plutôt par compte (voir [Dépenses d'un compte](#depenses-dun-compte)).
- **Le solde des comptes** : Carbure enregistre les transactions, pas les soldes.
