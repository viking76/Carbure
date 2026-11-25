-- Carbure: complete database schema
--
-- A new database is created from this file alone: the installation wizard runs it,
-- or import it with phpMyAdmin into an empty database (utf8mb4).
--
-- A change of schema goes into this file AND into a migration
-- sql/migrations/YYYY-MM-DD_name.sql for the existing databases (see its README),
-- with its version added below.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

-- --------------------------------------------------------
-- Table `api_tokens`

CREATE TABLE `api_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `name` varchar(50) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the token, the token itself is never stored',
  `token_hint` varchar(16) NOT NULL COMMENT 'Start of the token, to recognize it',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL: never expires',
  `client_id` varchar(64) DEFAULT NULL COMMENT 'OAuth client that got the token (NULL: created in the portal)',
  `refresh_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 of the OAuth refresh token',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  UNIQUE KEY `refresh_hash` (`refresh_hash`),
  KEY `fk_api_token_user` (`user_id`),
  CONSTRAINT `fk_api_token_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `bank_account`

CREATE TABLE `bank_account` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(50) NOT NULL,
  `account_number` varchar(100) NOT NULL,
  `label` varchar(100) DEFAULT NULL COMMENT 'Name given to the account',
  `user_id` int(10) unsigned NOT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `last_sync_status` enum('OK','ERROR') DEFAULT NULL,
  `last_sync_message` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_account_user` (`account_number`,`bank_name`,`user_id`),
  KEY `FK_USER_ID` (`user_id`) USING BTREE,
  CONSTRAINT `fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `bank_backend`

CREATE TABLE `bank_backend` (
  `name` varchar(50) NOT NULL COMMENT 'Name of the woob backend',
  `label` varchar(100) NOT NULL COMMENT 'Name given to the bank connection',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `bank_transaction`

CREATE TABLE `bank_transaction` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Unique ID for a transaction',
  `split_of` bigint(20) unsigned DEFAULT NULL COMMENT 'Transaction this part was split from (NULL: not a part)',
  `uuid` varchar(32) NOT NULL COMMENT 'Unique identifier for a transaction',
  `imported` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Importation date from the bank',
  `date` date NOT NULL COMMENT 'Transaction date',
  `rdate` date NOT NULL COMMENT 'Transaction real date',
  `type` tinyint(3) unsigned NOT NULL COMMENT 'Type of transaction : Cheque, virement ...',
  `label` varchar(300) NOT NULL COMMENT 'Label for the transaction',
  `comment` varchar(500) DEFAULT NULL COMMENT 'Free-text comment of the household (NULL: none)',
  `category` tinyint(3) unsigned NOT NULL DEFAULT 0 COMMENT 'Category of the transaction',
  `category_source` varchar(10) DEFAULT NULL COMMENT 'Origin of the category: rule, manual (NULL: unknown)',
  `amount` decimal(10,2) NOT NULL COMMENT 'Amount in currency for this transaction',
  `card` varchar(19) DEFAULT NULL COMMENT 'Card number used for this transaction',
  `bank_name` varchar(50) DEFAULT NULL COMMENT 'Bank (woob backend) of the synchronized account',
  `account_number` varchar(100) DEFAULT NULL COMMENT 'Number of the synchronized or imported account',
  `pointed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Pointed status for thjs transaction',
  `user` int(10) unsigned NOT NULL COMMENT 'The user of this transaction',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_uuid` (`uuid`),
  KEY `transaction_category` (`category`),
  KEY `transaction_user` (`user`),
  KEY `transaction_date` (`date`),
  KEY `transaction_split_of` (`split_of`),
  CONSTRAINT `fk_transaction_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`),
  CONSTRAINT `fk_transaction_user` FOREIGN KEY (`user`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_transaction_split_of` FOREIGN KEY (`split_of`) REFERENCES `bank_transaction` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tables `tag` and `transaction_tag`: tags of a project limited in time, a second axis apart
-- from the categories

CREATE TABLE `tag` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL COMMENT 'Name of the tag, without its #',
  `color` varchar(30) NOT NULL DEFAULT '' COMMENT 'Color (SwiftUI name or hex, may be empty)',
  `start_date` date NOT NULL COMMENT 'First day of the project',
  `end_date` date DEFAULT NULL COMMENT 'Last day of the project (NULL: still running); archived after it',
  `created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_tag_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transaction_tag` (
  `transaction_id` bigint(20) unsigned NOT NULL,
  `tag_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`transaction_id`, `tag_id`),
  KEY `transaction_tag_tag` (`tag_id`),
  CONSTRAINT `fk_transaction_tag_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `bank_transaction` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_transaction_tag_tag` FOREIGN KEY (`tag_id`) REFERENCES `tag` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `bank_transaction_deleted`: transactions deleted by a user, skipped by the
-- synchronization and the import of a file (same UUID)

CREATE TABLE `bank_transaction_deleted` (
  `uuid` varchar(32) NOT NULL COMMENT 'UUID of the deleted transaction',
  `deleted_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Date of the deletion',
  `user_id` int(10) unsigned DEFAULT NULL COMMENT 'User who deleted it',
  PRIMARY KEY (`uuid`),
  KEY `fk_transaction_deleted_user` (`user_id`),
  CONSTRAINT `fk_transaction_deleted_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `bank_transaction_category`

CREATE TABLE `bank_transaction_category` (
  `id` tinyint(3) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `parent_category` tinyint(3) unsigned DEFAULT 0,
  `type` enum('DEBIT','CREDIT','HORS-BUDGET') NOT NULL,
  `icon` varchar(50) NOT NULL,
  `color` varchar(50) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  KEY `parent_category` (`parent_category`),
  CONSTRAINT `fk_parent_category` FOREIGN KEY (`parent_category`) REFERENCES `bank_transaction_category` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `bank_transaction_category_keyword`

CREATE TABLE `bank_transaction_category_keyword` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `keyword` varchar(60) NOT NULL,
  `category` tinyint(3) unsigned NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL COMMENT 'Absolute amount of the transactions matched (NULL: any amount)',
  `notify` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Push to the household when a new transaction matches',
  `amount_key` decimal(10,2) AS (IFNULL(`amount`, 0)) PERSISTENT COMMENT 'Amount of the rule for its unique key (0: any amount)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `keyword` (`keyword`, `amount_key`),
  KEY `keyword_category` (`category`),
  CONSTRAINT `fk_keyword_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `budget`

CREATE TABLE `budget` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category` tinyint(3) unsigned NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `date` date NOT NULL,
  `note` varchar(200) DEFAULT NULL COMMENT 'Reminder of what the budget covers (NULL: none)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `category` (`category`,`date`),
  CONSTRAINT `fk_budget_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `budget_insight`

CREATE TABLE `budget_insight` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(20) NOT NULL,
  `color` varchar(20) NOT NULL,
  `icon` varchar(50) DEFAULT NULL COMMENT 'Material icon (default: chosen from the name)',
  `sql` varchar(500) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Shown in the Insights tab (0: offered by its + button)',
  `position` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Place in the Insights tab (sorted by position, then id)',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `devices`

CREATE TABLE `devices` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `token` varchar(200) DEFAULT NULL COMMENT 'Push token (APNs or FCM), NULL when the app sent none',
  `platform` varchar(10) NOT NULL DEFAULT 'ios' COMMENT 'ios (APNs) or android (FCM)',
  `model` varchar(100) DEFAULT NULL COMMENT 'Model of the device (e.g. iPhone16,1, Google Pixel 8)',
  `os_version` varchar(50) DEFAULT NULL COMMENT 'Version of its system (e.g. iOS 26.0, Android 15)',
  `app_version` varchar(20) DEFAULT NULL COMMENT 'Version of the mobile app (e.g. 1.2)',
  `lastLogin` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `fk_device_user` (`user_id`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `settings`

CREATE TABLE `settings` (
  `name` varchar(50) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `users`

CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(20) NOT NULL,
  `password` varchar(100) NOT NULL,
  `firstname` varchar(50) DEFAULT NULL,
  `lastname` varchar(50) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `language` varchar(5) NOT NULL DEFAULT 'fr',
  `theme` varchar(10) NOT NULL DEFAULT 'system' COMMENT 'Display theme of the portal: system, light or dark',
  `alert_threshold` decimal(10,2) DEFAULT NULL,
  `budget_threshold` smallint(5) unsigned NOT NULL DEFAULT 100 COMMENT 'Percent of a budget whose spending of the month alerts (50 to 200)',
  `budget_alert_category` tinyint(3) unsigned DEFAULT NULL COMMENT 'Category whose budget alerts (NULL: all the budgets)',
  `budget_alert_all` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Global budget alert threshold on (all the budgets)',
  `last_login` datetime DEFAULT NULL,
  `failed_logins` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_budget_alert_category` (`budget_alert_category`),
  CONSTRAINT `fk_users_budget_alert_category` FOREIGN KEY (`budget_alert_category`) REFERENCES `bank_transaction_category` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Tables of the two-factor authentication at login (authenticator app, TOTP): the
-- authenticator of each user, his recovery codes and the browsers he trusts for 6 months

CREATE TABLE `user_mfa` (
  `user_id` int(10) unsigned NOT NULL,
  `secret` varchar(64) NOT NULL COMMENT 'TOTP secret (base32), pending until enabled by a first code',
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `last_step` bigint(20) unsigned DEFAULT NULL COMMENT 'Time step of the last code accepted (a code works once)',
  `created_at` datetime NOT NULL,
  `enabled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_user_mfa_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mfa_recovery_code` (
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) NOT NULL COMMENT 'SHA-256 of the recovery code (each one works once)',
  PRIMARY KEY (`user_id`, `code_hash`),
  CONSTRAINT `fk_mfa_recovery_code_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `mfa_trusted_browser` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the token of the cookie',
  `label` varchar(200) NOT NULL DEFAULT '' COMMENT 'User-Agent of the browser',
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `fk_mfa_trusted_browser_user` (`user_id`),
  CONSTRAINT `fk_mfa_trusted_browser_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `user_browser`: web browsers from which each user logged in to the portal (an
-- HttpOnly cookie identifies the browser, only its SHA-256 is kept)

CREATE TABLE `user_browser` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the token of the cookie',
  `label` varchar(200) NOT NULL DEFAULT '' COMMENT 'User-Agent of the browser',
  `created_at` datetime NOT NULL,
  `last_login` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_token` (`user_id`, `token_hash`),
  CONSTRAINT `fk_user_browser_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `user_notification`: channels of each kind of notification chosen by each user
-- (no row for a kind: push on, email off)

CREATE TABLE `user_notification` (
  `user_id` int(10) unsigned NOT NULL,
  `kind` varchar(20) NOT NULL COMMENT 'sync (synchronization result), rule (rule marked notify), expense (large expense)',
  `push` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Push to the devices of the user (iOS app)',
  `email` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Email to the address of the user',
  PRIMARY KEY (`user_id`, `kind`),
  CONSTRAINT `fk_user_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `budget_alert`: budget alerts sent, one per user, category and month (alerted
-- again when the budget is raised above the amount alerted and reached again)

CREATE TABLE `budget_alert` (
  `user_id` int(10) unsigned NOT NULL,
  `category` tinyint(3) unsigned NOT NULL,
  `month` date NOT NULL COMMENT 'First day of the month',
  `budget` decimal(10,2) NOT NULL COMMENT 'Budget of the month when alerted',
  `spent` decimal(10,2) NOT NULL COMMENT 'Spending of the month when alerted',
  `percent` smallint(5) unsigned NOT NULL COMMENT 'Spending in percent of the budget',
  `sent_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`, `category`, `month`),
  KEY `budget_alert_category` (`category`),
  CONSTRAINT `fk_budget_alert_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_budget_alert_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `budget_alert_threshold`: budget alert threshold of a user for one category, used
-- instead of his global threshold (users.budget_threshold)

CREATE TABLE `budget_alert_threshold` (
  `user_id` int(10) unsigned NOT NULL,
  `category` tinyint(3) unsigned NOT NULL,
  `threshold` smallint(5) unsigned NOT NULL COMMENT 'Percent of the budget of the category that alerts (50 to 200)',
  PRIMARY KEY (`user_id`, `category`),
  KEY `budget_alert_threshold_category` (`category`),
  CONSTRAINT `fk_budget_alert_threshold_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_budget_alert_threshold_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `insight_alert`: alert of a user when the value of the month of an enabled insight
-- goes above or below his threshold (once per month, again when the threshold changes)

CREATE TABLE `insight_alert` (
  `user_id` int(10) unsigned NOT NULL,
  `insight_id` int(10) unsigned NOT NULL,
  `direction` enum('above','below') NOT NULL COMMENT 'Alert when the value goes above or below the threshold',
  `threshold` decimal(12,2) NOT NULL COMMENT 'Amount compared with the absolute value of the month',
  `notified_month` date DEFAULT NULL COMMENT 'First day of the month last alerted (NULL: not yet, or threshold changed)',
  PRIMARY KEY (`user_id`, `insight_id`),
  KEY `insight_alert_insight` (`insight_id`),
  CONSTRAINT `fk_insight_alert_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_insight_alert_insight` FOREIGN KEY (`insight_id`) REFERENCES `budget_insight` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `data_change`: change markers of the household data, one version per kind of
-- data bumped by every write, so that the clients reload only what changed (GET /changes)

CREATE TABLE `data_change` (
  `kind` varchar(20) NOT NULL COMMENT 'Kind of data: transactions, accounts, categories, budgets, insights, settings',
  `version` bigint(20) unsigned NOT NULL COMMENT 'Bumped by every write of the kind',
  `updated_at` datetime(3) NOT NULL COMMENT 'Date of the last write, for information',
  PRIMARY KEY (`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- Table `schema_migrations`: version of this schema (base of version 1.0, then the
-- migrations included since)

-- --------------------------------------------------------
-- OAuth 2.1 of the MCP server (clients registered by the AI agents, authorization codes)

CREATE TABLE `oauth_clients` (
  `client_id` varchar(64) NOT NULL,
  `client_secret_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 of the secret, NULL for a public client (PKCE only)',
  `name` varchar(100) NOT NULL,
  `redirect_uris` varchar(2000) NOT NULL COMMENT 'JSON array',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`client_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oauth_codes` (
  `code_hash` char(64) NOT NULL COMMENT 'SHA-256 of the authorization code',
  `client_id` varchar(64) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `redirect_uri` varchar(500) NOT NULL,
  `code_challenge` varchar(128) NOT NULL COMMENT 'PKCE, S256',
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`code_hash`),
  KEY `fk_oauth_code_client` (`client_id`),
  KEY `fk_oauth_code_user` (`user_id`),
  CONSTRAINT `fk_oauth_code_client` FOREIGN KEY (`client_id`) REFERENCES `oauth_clients` (`client_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_oauth_code_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schema_migrations` (
  `version` varchar(100) NOT NULL,
  `applied_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `schema_migrations` (`version`) VALUES
('2026-10-13_base'),
('2026-10-14_oauth'),
('2026-10-15_transaction_bank'),
('2026-10-16_bank_account_label'),
('2026-10-17_bank_backend_label'),
('2026-10-18_transaction_deleted'),
('2026-10-19_user_theme'),
('2026-10-20_user_notification'),
('2026-10-21_user_budget_threshold'),
('2026-10-22_budget_alert'),
('2026-10-23_device_platform'),
('2026-10-23_user_budget_alert_category'),
('2026-10-24_user_mfa'),
('2026-10-25_device_model'),
('2026-10-26_user_browser'),
('2026-10-27_budget_alert_threshold'),
('2026-10-28_device_without_token'),
('2026-10-29_data_change'),
('2026-10-30_transaction_split'),
('2026-10-31_tags'),
('2026-11-01_insight_enabled'),
('2026-11-02_category_source'),
('2026-11-03_insight_alert'),
('2026-11-04_insight_largest_expense'),
('2026-11-05_rule_amount'),
('2026-11-06_budget_note'),
('2026-11-07_insight_position'),
('2026-11-19_transaction_comment');

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
