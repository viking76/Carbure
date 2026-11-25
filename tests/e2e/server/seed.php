<?php

/**
 * seed.php
 *
 * Data set of the end-to-end tests and of the development stack: the database is
 * dropped, recreated from sql/carbure.sql, then filled with a household whose dates
 * follow the current month. Only for a database named *_e2e, *_dev or *_demo (the
 * demonstration Docker image, docker/demo/Dockerfile, loads it with --demo at each start
 * and each day).
 *
 * From the command line (development stack, see start.sh):
 *   php tests/e2e/server/seed.php           the data set of the tests
 *   php tests/e2e/server/seed.php --demo    and a year of household life on top of it
 *
 * Users (password = username + "-password"):
 *   - admin  : administrator, French
 *   - marie  : user, French
 *
 * Bank accounts: DEMO0000001@bnp (synchronized by the fake woob) and fail@bank (woob fails).
 * Categories: Alimentation (Supermarché, Restaurant), Logement, Salaire, Épargne (Livret A)
 * and the default category 0. Budgets of the month: Supermarché 300, Restaurant 100
 * (Alimentation = their sum), Logement 1000. The tests rely on these amounts: the
 * demonstration data (--demo, carbure_demo_data()) comes on top of them, never instead.
 *
 * @author     Ludovic Toinel
 * @copyright  2026 Carbure App
 */

use Carbure\Lib\Config;

/** Users of the data set: username => [password, is_admin, firstname, lastname] */
const E2E_USERS = [
    'admin' => ['admin-password', 1, 'Ada', 'Admin'],
    'marie' => ['marie-password', 0, 'Marie', 'Martin'],
];

/**
 * Recreate the e2e database with the data set.
 *
 * @param bool $demo Also add a year of household life (development stack)
 * @return void
 * @throws Exception If the schema cannot be imported
 */
function carbure_e2e_seed($demo = false)
{
    $name = Config::get('db_name');
    if (!preg_match('/_(e2e|dev|demo)$/', $name)) {
        throw new Exception("Refusing to reset the database $name: not an e2e, dev or demo database");
    }

    $conn = new mysqli(Config::get('db_hostname'), Config::get('db_username'), Config::get('db_password'), null, (int)Config::get('db_port'));
    $conn->set_charset('utf8mb4');
    $conn->query("DROP DATABASE IF EXISTS `$name`");
    $conn->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
    $conn->select_db($name);

    $conn->multi_query(file_get_contents(dirname(__DIR__, 3) . '/sql/carbure.sql'));
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    if ($conn->error) {
        throw new Exception("Schema import failed: " . $conn->error);
    }

    $exec = function ($sql, $types = '', ...$params) use ($conn) {
        $stmt = $conn->prepare($sql);
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $stmt->close();
    };

    $conn->query("SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO'");

    $id = 1;
    foreach (E2E_USERS as $username => [$password, $admin, $firstname, $lastname]) {
        $exec("INSERT INTO users (id, username, password, firstname, lastname, email, is_admin, language) VALUES (?, ?, ?, ?, ?, ?, ?, 'fr')",
            "isssssi", $id++, $username, password_hash($password, PASSWORD_DEFAULT), $firstname, $lastname, "$username@example.com", $admin);
    }

    $conn->query("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES
        (0, 'Non catégorisé', 0, 'HORS-BUDGET', 'help', '#9e9e9e'),
        (1, 'Alimentation', 0, 'DEBIT', 'restaurant', 'green'),
        (2, 'Supermarché', 1, 'DEBIT', 'shopping_cart', 'green'),
        (3, 'Restaurant', 1, 'DEBIT', 'local_dining', 'green'),
        (4, 'Logement', 0, 'DEBIT', 'home', 'blue'),
        (5, 'Salaire', 0, 'CREDIT', 'payments', 'green'),
        (6, 'Épargne', 0, 'HORS-BUDGET', 'savings', 'purple'),
        (7, 'Livret A', 6, 'HORS-BUDGET', 'account_balance', 'purple')");

    $conn->query("INSERT INTO bank_transaction_category_keyword (keyword, category, notify) VALUES
        ('SUPERMARCHE', 2, 0), ('RESTAURANT', 3, 0), ('LOYER', 4, 1), ('SALAIRE', 5, 0)");

    // The fake woob fails for "fail@bank"
    $conn->query("INSERT INTO bank_account (bank_name, account_number, user_id, last_sync_at, last_sync_status, last_sync_message) VALUES
        ('bnp', 'DEMO0000001', 1, NOW() - INTERVAL 1 DAY, 'OK', '3 new'),
        ('bank', 'fail', 1, NULL, NULL, NULL)");

    // Transactions of the current month and of the two previous months
    $transactions = [
        // [months ago, day, type, label, category, amount, pointed]
        [0, 2, 7, 'CB SUPERMARCHE CASINO', 2, -82.40, 0],
        [0, 3, 2, 'PRLV LOYER AGENCE', 4, -950.00, 1],
        [0, 1, 1, 'VIR SALAIRE ACME', 5, 3200.00, 1],
        [0, 4, 7, 'CB RESTAURANT LE PETIT ZINC', 3, -45.00, 0],
        [0, 5, 7, 'CB BOULANGERIE PAUL', 0, -6.80, 0],
        [0, 6, 1, 'VIR LIVRET A', 7, -500.00, 1],
        [1, 2, 7, 'CB SUPERMARCHE CARREFOUR', 2, -120.00, 1],
        [1, 3, 2, 'PRLV LOYER AGENCE', 4, -950.00, 1],
        [1, 1, 1, 'VIR SALAIRE ACME', 5, 3200.00, 1],
        [2, 3, 2, 'PRLV LOYER AGENCE', 4, -950.00, 1],
        [2, 1, 1, 'VIR SALAIRE ACME', 5, 3100.00, 1],
    ];
    foreach ($transactions as [$ago, $day, $type, $label, $category, $amount, $pointed]) {
        $date = date('Y-m-d', strtotime(date('Y-m-01') . " -$ago months +" . ($day - 1) . ' days'));
        // Synchronized from the account of the household (see bank_account)
        $exec("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, bank_name, account_number, pointed, user) VALUES (?, ?, ?, ?, ?, ?, ?, '', 'bnp', 'DEMO0000001', ?, 1)",
            "sssisidi", md5($date . $label . $amount), $date, $date, $type, $label, $category, $amount, $pointed);
    }

    $month = date('Y-m-01');
    $exec("INSERT INTO budget (category, amount, date) VALUES (2, 300, ?), (3, 100, ?), (4, 1000, ?)", "sss", $month, $month, $month);

    $conn->query("INSERT INTO budget_insight (id, name, color, `sql`) VALUES
        (1, 'Dépenses', 'red', 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE amount < 0 AND MONTH(date)={month} AND YEAR(date)={year}')");

    $exec("INSERT INTO devices (name, token, model, os_version, app_version, user_id)
        VALUES ('iPhone de Ada', ?, 'iPhone16,1', 'iOS 26.0', '1.2', 1)", "s", str_repeat('ab12', 16));

    if ($demo) {
        carbure_demo_data($exec);
    }

    $conn->close();
}

/**
 * A year of life of the household, on top of the data set of the tests, so that every
 * screen of the portal shows something representative: two salaries, about twenty
 * sub-categories with their monthly budgets and rules, seasonal expenses (electricity in
 * winter, school canteen out of the summer, holidays in July), savings, a few purchases
 * left uncategorized and checked transactions except for the current month.
 * The amounts vary from month to month but are the same at each reset.
 *
 * @param callable $exec Prepared statement runner: ($sql, $types, ...$params)
 * @return void
 */
function carbure_demo_data(callable $exec)
{
    // Sub-categories and new categories (ids after the ones of the data set)
    $categories = [
        // id => [name, parent, type, icon, color]
        8 => ['Boulangerie', 1, 'DEBIT', 'bakery_dining', 'green'],
        9 => ['Électricité', 4, 'DEBIT', 'bolt', 'blue'],
        10 => ['Internet et mobile', 4, 'DEBIT', 'wifi', 'blue'],
        11 => ['Transport', 0, 'DEBIT', 'directions_car', 'red'],
        12 => ['Carburant', 11, 'DEBIT', 'local_gas_station', 'red'],
        13 => ['Transports en commun', 11, 'DEBIT', 'train', 'red'],
        14 => ['Santé', 0, 'DEBIT', 'local_hospital', 'pink'],
        15 => ['Pharmacie', 14, 'DEBIT', 'medication', 'pink'],
        16 => ['Médecin', 14, 'DEBIT', 'medical_services', 'pink'],
        17 => ['Loisirs', 0, 'DEBIT', 'sports_esports', 'cyan'],
        18 => ['Abonnements', 17, 'DEBIT', 'subscriptions', 'cyan'],
        19 => ['Sorties', 17, 'DEBIT', 'theater_comedy', 'cyan'],
        20 => ['Voyages', 17, 'DEBIT', 'flight', 'cyan'],
        21 => ['Enfants', 0, 'DEBIT', 'child_care', 'mint'],
        22 => ['Cantine', 21, 'DEBIT', 'restaurant_menu', 'mint'],
        23 => ['Activités', 21, 'DEBIT', 'sports_soccer', 'mint'],
        24 => ['Impôts', 0, 'DEBIT', 'account_balance', 'brown'],
        25 => ['Revenus divers', 0, 'CREDIT', 'redeem', 'teal'],
        26 => ['Assurance vie', 6, 'HORS-BUDGET', 'savings', 'purple'],
        27 => ['Virements internes', 0, 'HORS-BUDGET', 'sync_alt', 'gray'],
    ];
    foreach ($categories as $id => [$name, $parent, $type, $icon, $color]) {
        $exec("INSERT INTO bank_transaction_category (id, name, parent_category, type, icon, color) VALUES (?, ?, ?, ?, ?, ?)",
            "isisss", $id, $name, $parent, $type, $icon, $color);
    }

    // Categorization rules of the usual shops and companies
    $rules = ['CARREFOUR' => 2, 'LIDL' => 2, 'BOULANGERIE' => 8, 'EDF' => 9, 'FREE MOBILE' => 10, 'FREEBOX' => 10,
              'TOTAL ENERGIES' => 12, 'NAVIGO' => 13, 'PHARMACIE' => 15, 'DOCTOLIB' => 16, 'NETFLIX' => 18,
              'SPOTIFY' => 18, 'CINEMA' => 19, 'AIR FRANCE' => 20, 'CANTINE' => 22, 'DGFIP' => 24, 'CAF' => 25,
              'ASSURANCE VIE' => 26, 'LIVRET A' => 7];
    foreach ($rules as $keyword => $category) {
        $exec("INSERT INTO bank_transaction_category_keyword (keyword, category, notify) VALUES (?, ?, 0)", "si", $keyword, $category);
    }

    mt_srand(2026);
    $today = (int)date('j');
    for ($ago = 11; $ago >= 0; $ago--) {
        $first = date('Y-m-01', strtotime(date('Y-m-01') . " -$ago months"));
        $month = (int)date('n', strtotime($first));
        $days = (int)date('t', strtotime($first));
        $rows = [];
        $add = function ($day, $type, $label, $category, $amount) use (&$rows) {
            $rows[] = [$day, $type, $label, $category, round($amount, 2)];
        };
        $vary = fn($min, $max) => mt_rand((int)($min * 100), (int)($max * 100)) / 100;

        // Incomes and fixed charges (the data set already has salary and rent of the last 3 months)
        if ($ago >= 3) {
            $add(1, 1, 'VIR SALAIRE ACME', 5, 3200);
            $add(3, 2, 'PRLV LOYER AGENCE', 4, -950);
        }
        $add(1, 1, 'VIR SALAIRE BETA CONSEIL', 5, 2150);
        $add(5, 1, 'VIR CAF ALLOCATIONS', 25, 141.99);
        $add(2, 7, 'CB NAVIGO MOIS', 13, -86.40);
        $add(5, 2, 'PRLV EDF CLIENTS PARTICULIERS', 9, in_array($month, [11, 12, 1, 2, 3], true) ? -$vary(120, 160) : -$vary(70, 95));
        $add(8, 2, 'PRLV FREE MOBILE', 10, -19.99);
        $add(8, 2, 'PRLV FREEBOX', 10, -39.99);
        $add(3, 7, 'CB NETFLIX.COM', 18, -13.49);
        $add(12, 7, 'CB SPOTIFY', 18, -10.99);
        $add(15, 2, 'PRLV DGFIP IMPOT PAS', 24, -245);
        $add(20, 1, 'VIR ASSURANCE VIE', 26, -150);
        if ($ago >= 1) {
            $add(6, 1, 'VIR LIVRET A', 7, -$vary(300, 500));
        }
        if (!in_array($month, [7, 8], true)) {
            $add(10, 2, 'PRLV CANTINE SCOLAIRE', 22, -$vary(62, 98));
        }
        if ($month === 9) {
            $add(15, 7, 'CB CLUB DE FOOT LICENCE', 23, -180);
        }
        if ($month === 7) {
            $add(4, 7, 'CB AIR FRANCE', 20, -624.80);
            $add(18, 7, 'CB HOTEL LES PINS', 20, -486);
        }

        // Day-to-day spending
        foreach (['CB CARREFOUR MARKET', 'CB LIDL', 'CB CARREFOUR MARKET', 'CB LIDL', 'CB CARREFOUR MARKET'] as $i => $label) {
            $add(3 + $i * 6, 7, $label, 2, -$vary(35, 140));
        }
        for ($i = 0; $i < 8; $i++) {
            $add(2 + $i * 3, 7, 'CB BOULANGERIE DU MARCHE', 8, -$vary(2.5, 12));
        }
        for ($i = 0, $n = mt_rand(2, 4); $i < $n; $i++) {
            $add(5 + $i * 7, 7, ['CB LE PETIT BISTROT', 'CB SUSHI SHOP', 'CB PIZZERIA NAPOLI', 'CB CREPERIE'][$i], 3, -$vary(18, 75));
        }
        $add(9, 7, 'CB TOTAL ENERGIES', 12, -$vary(55, 80));
        $add(23, 7, 'CB TOTAL ENERGIES', 12, -$vary(55, 80));
        for ($i = 0, $n = mt_rand(0, 2); $i < $n; $i++) {
            $add(11 + $i * 9, 7, 'CB PHARMACIE CENTRALE', 15, -$vary(8, 35));
        }
        if (mt_rand(0, 2) === 0) {
            $add(16, 7, 'CB DOCTOLIB DR LEROY', 16, -26.50);
        }
        for ($i = 0, $n = mt_rand(1, 3); $i < $n; $i++) {
            $add(13 + $i * 5, 7, ['CB CINEMA PATHE', 'CB FNAC', 'CB BOWLING'][$i], 19, -$vary(12, 45));
        }
        // Left uncategorized, as new shops are
        $add(21, 7, 'CB AMAZON MARKETPLACE', 0, -$vary(15, 90));
        if ($month % 3 === 0) {
            $add(25, 7, 'CB LEROY MERLIN', 0, -$vary(40, 180));
        }
        $add(27, 1, 'VIR COMPTE JOINT', 27, -400);
        $add(27, 1, 'VIR COMPTE JOINT', 27, 400);

        foreach ($rows as $index => [$day, $type, $label, $category, $amount]) {
            // The current month stops today; the older months are checked
            if (($ago === 0 && $day > $today) || $day > $days) {
                continue;
            }
            $date = date('Y-m-d', strtotime("$first +" . ($day - 1) . ' days'));
            $pointed = $ago === 0 ? mt_rand(0, 1) : 1;
            $exec("INSERT INTO bank_transaction (uuid, date, rdate, type, label, category, amount, card, pointed, user) VALUES (?, ?, ?, ?, ?, ?, ?, '', ?, 1)",
                "sssisidi", md5("demo $date $index $label $amount"), $date, $date, $type, $label, $category, $amount, $pointed);
        }

        // Budgets of the month (the data set has the ones of the current month)
        $budgets = [2 => 450, 3 => 120, 8 => 60, 4 => 1200, 9 => 120, 10 => 60, 12 => 150, 13 => 90, 14 => 80,
                    18 => 30, 19 => 80, 20 => $month === 7 ? 1200 : 0, 22 => 100, 23 => 20, 24 => 250];
        foreach ($budgets as $category => $amount) {
            if ($amount > 0) {
                $exec("INSERT IGNORE INTO budget (category, amount, date) VALUES (?, ?, ?)", "ids", $category, $amount, $first);
            }
        }
    }

    // Insights of the month
    $insights = [
        ['Revenus', 'green', 'trending_up', 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE amount > 0 AND category NOT IN (27) AND MONTH(date)={month} AND YEAR(date)={year}'],
        ['Courses', 'teal', 'shopping_cart', 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE category IN (2, 8) AND MONTH(date)={month} AND YEAR(date)={year}'],
        ['Épargne', 'purple', 'savings', 'SELECT -SUM(amount) AS amount FROM bank_transaction WHERE category IN (6, 7, 26) AND MONTH(date)={month} AND YEAR(date)={year}'],
        ['Abonnements', 'indigo', 'subscriptions', 'SELECT SUM(amount) AS amount FROM bank_transaction WHERE category IN (10, 18) AND MONTH(date)={month} AND YEAR(date)={year}'],
    ];
    foreach ($insights as [$name, $color, $icon, $sql]) {
        $exec("INSERT INTO budget_insight (name, color, icon, `sql`) VALUES (?, ?, ?, ?)", "ssss", $name, $color, $icon, $sql);
    }

    // The AI agent tab is ready to use
    $exec("INSERT INTO settings (name, value) VALUES ('mcp_enabled', '1')");
}

// Command line: loads the data set into the database of the configuration
if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    require_once dirname(__DIR__, 3) . '/src/autoload.php';
    $demo = in_array('--demo', $_SERVER['argv'], true);
    carbure_e2e_seed($demo);
    echo "Carbure: " . ($demo ? 'demonstration' : 'sample') . " data loaded into " . Config::get('db_name') . "\n";
}
