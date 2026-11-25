-- Optional amount of a categorization rule: the rule then matches only the transactions
-- whose absolute amount equals it (NULL: any amount). Two rules may share a keyword with
-- different amounts; amount_key (0 when no amount) keeps a single rule without amount per
-- keyword (a unique index accepts several NULLs).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction_category_keyword' AND COLUMN_NAME = 'amount'

ALTER TABLE `bank_transaction_category_keyword`
  ADD COLUMN `amount` decimal(10,2) DEFAULT NULL COMMENT 'Absolute amount of the transactions matched (NULL: any amount)' AFTER `category`,
  ADD COLUMN `amount_key` decimal(10,2) AS (IFNULL(`amount`, 0)) PERSISTENT COMMENT 'Amount of the rule for its unique key (0: any amount)' AFTER `notify`,
  DROP INDEX `keyword`,
  ADD UNIQUE KEY `keyword` (`keyword`, `amount_key`);
