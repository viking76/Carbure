-- Free-text comment of a transaction, written by the household (NULL: none). The existing
-- transactions get NULL (nullable column: no rewrite of the rows, instant on MariaDB 10.4+);
-- the synchronization and the import of a statement never write it.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bank_transaction' AND COLUMN_NAME = 'comment'

ALTER TABLE `bank_transaction`
  ADD COLUMN `comment` varchar(500) DEFAULT NULL COMMENT 'Free-text comment of the household (NULL: none)' AFTER `label`;
