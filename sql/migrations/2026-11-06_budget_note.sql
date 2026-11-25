-- Note of a budget: a reminder of what the planned amount covers (#468), copied with the
-- budget to the next month.
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget' AND COLUMN_NAME = 'note'

ALTER TABLE `budget`
  ADD COLUMN `note` varchar(200) DEFAULT NULL COMMENT 'Reminder of what the budget covers (NULL: none)' AFTER `date`;
