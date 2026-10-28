-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- ============================================================
-- HayabusaPress V001007000 (1.7.0) — collections
--
--   1. collections: an ordered run of series (the parts of a larger story, the courses of a
--      curriculum, the areas of a region)
--   2. series: membership of a collection and its position
--
-- Every new column has a default that reproduces the 1.6.x output (no series is in a collection).
-- Nothing has to be rebuilt after applying this file beyond the usual  php core/cli.php system:init.
--
-- collection_id: the column and its foreign key go in one statement, so a re-run fails as a whole
-- on "Duplicate column" (treated as applied) instead of tripping over an existing constraint name.
-- ============================================================

-- ------------------------------------------------------------
-- 1. collections
--
-- description : Markdown typed in the admin. description_html is its render, baked on save.
-- There is no status and no counter: the collection page shows each series' own status and adds up
-- the series' counters, which are already compiled into series.cache.php.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `collections` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lang`             VARCHAR(5)   NOT NULL DEFAULT 'en' COMMENT 'Content language code; every series in the collection has it',
  `slug`             VARCHAR(255) NOT NULL,
  `title`            VARCHAR(255) NOT NULL,
  `description`      TEXT         NULL DEFAULT NULL COMMENT 'Markdown',
  `description_html` TEXT         NULL DEFAULT NULL COMMENT 'Rendered on save',
  `cover_image`      VARCHAR(255) NULL DEFAULT NULL COMMENT 'Image URL path',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_collections_lang_slug` (`lang`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. series: collection membership
--
-- collection_id    : NULL = not in a collection. Deleting the collection sets it back to NULL.
-- collection_order : position in the collection, spaced by 10 like posts.series_order.
-- No index of its own beyond the one the foreign key needs: membership is read from
-- series.cache.php, never queried on a public page.
-- ------------------------------------------------------------
ALTER TABLE `series` ADD COLUMN `collection_order` INT NOT NULL DEFAULT 0 COMMENT 'Position in the collection (spaced by 10)' AFTER `char_count`;
ALTER TABLE `series`
  ADD COLUMN `collection_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Collection this series belongs to' AFTER `char_count`,
  ADD CONSTRAINT `fk_series_collection` FOREIGN KEY (`collection_id`) REFERENCES `collections` (`id`) ON DELETE SET NULL;
