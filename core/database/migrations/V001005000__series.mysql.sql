-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- ============================================================
-- HayabusaPress V001005000 (1.5.0) — series, character count, reading time
--
--   1. series: an ordered run of posts (a serialized story, a tutorial in parts)
--   2. posts: membership of a series, its position and chapter heading
--   3. posts: character count and reading time, baked on save
--
-- Every new column has a default that reproduces the 1.4.x output. After applying this file run
--   php core/cli.php posts:recompile
-- once, so that char_count / reading_minutes are filled in for existing posts.
--
-- Each ALTER adds one column on its own (see V001003000 for why). series_id is the exception: the
-- column, its index and its foreign key go in one statement, so a re-run fails as a whole on
-- "Duplicate column" (treated as applied) instead of tripping over an existing constraint name.
-- ============================================================

-- ------------------------------------------------------------
-- 1. series
--
-- description      : Markdown typed in the admin. description_html is its render, baked on save.
-- status           : ongoing | completed | hiatus (validated by the application).
-- post_count       : published posts in the series. Moved by deltas in core/Counters.php.
-- char_count       : sum of posts.char_count over those published posts. Moved the same way.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `series` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `lang`             VARCHAR(5)   NOT NULL DEFAULT 'en' COMMENT 'Content language code; every post in the series has it',
  `slug`             VARCHAR(255) NOT NULL,
  `title`            VARCHAR(255) NOT NULL,
  `description`      TEXT         NULL DEFAULT NULL COMMENT 'Markdown',
  `description_html` TEXT         NULL DEFAULT NULL COMMENT 'Rendered on save',
  `status`           VARCHAR(16)  NOT NULL DEFAULT 'ongoing' COMMENT 'ongoing|completed|hiatus',
  `cover_image`      VARCHAR(255) NULL DEFAULT NULL COMMENT 'Image URL path',
  `feed_enabled`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Publish /feeds/{lang}/series/{slug}.*',
  `post_count`       INT          NOT NULL DEFAULT 0 COMMENT 'Published posts. Moved by deltas in core/Counters.php',
  `char_count`       BIGINT       NOT NULL DEFAULT 0 COMMENT 'Characters of the published posts. Moved by deltas',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_series_lang_slug` (`lang`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. posts: series membership
--
-- series_id    : NULL = not in a series. Deleting the series sets it back to NULL.
-- series_order : position in the series. The application keeps it unique within a series and
--                spaced by 10 (10, 20, 30, ...), so a post can be slotted in without renumbering.
-- series_part  : chapter heading. The table of contents prints it where the value changes.
-- idx_posts_series answers "previous / next published post" with a one-row index range.
-- ------------------------------------------------------------
-- The index covers series_order, so that column has to exist first; each is placed after
-- category_id, which leaves them in the order series_id, series_order, series_part.
ALTER TABLE `posts` ADD COLUMN `series_part`  VARCHAR(100) NULL DEFAULT NULL COMMENT 'Chapter heading within the series' AFTER `category_id`;
ALTER TABLE `posts` ADD COLUMN `series_order` INT          NOT NULL DEFAULT 0 COMMENT 'Position in the series (spaced by 10)' AFTER `category_id`;
ALTER TABLE `posts`
  ADD COLUMN `series_id` INT UNSIGNED NULL DEFAULT NULL COMMENT 'Series this post belongs to' AFTER `category_id`,
  ADD KEY `idx_posts_series` (`series_id`, `status`, `series_order`, `id`),
  ADD CONSTRAINT `fk_posts_series` FOREIGN KEY (`series_id`) REFERENCES `series` (`id`) ON DELETE SET NULL;

-- ------------------------------------------------------------
-- 3. posts: character count and reading time
--
-- char_count      : non-whitespace characters of the rendered body, excluding ruby readings, code,
--                   the table of contents and the sponsored notice.
-- reading_minutes : by characters for CJK-heavy bodies, by words otherwise (see PostCompiler).
-- ------------------------------------------------------------
ALTER TABLE `posts` ADD COLUMN `char_count`      INT      NOT NULL DEFAULT 0 COMMENT 'Characters of the body, counted on save' AFTER `content_html`;
ALTER TABLE `posts` ADD COLUMN `reading_minutes` SMALLINT NOT NULL DEFAULT 0 COMMENT 'Reading time in minutes, computed on save' AFTER `char_count`;
