-- Position of an insight in the Insights tab, chosen by drag and drop (#467): the cards
-- are sorted by position then id. The insights already installed keep their order (all at 0,
-- sorted by id).
-- applied-if: SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'budget_insight' AND COLUMN_NAME = 'position'

ALTER TABLE `budget_insight`
  ADD COLUMN `position` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Place in the Insights tab (sorted by position, then id)' AFTER `enabled`;
