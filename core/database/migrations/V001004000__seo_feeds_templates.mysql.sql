-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- ============================================================
-- HayabusaPress V001004000 (1.4.0) — SEO, distribution feeds, templates, raw-HTML provenance
--
--   1. posts: auto description, social share text, raw-HTML provenance
--   2. categories: hashtags and per-category feeds
--   3. post_templates: starting points for new posts (copied, never linked)
--   4. slug_redirects: old slugs of published posts, served as 301
--   5. speakers: people shown in :::say speech bubbles
--
-- Every new column has a default that reproduces the 1.3.0 output. After applying this file run
--   php core/cli.php posts:recompile --reassess
-- once: raw_html_allowed starts FALSE everywhere, and --reassess sets it from each post's author
-- role (the permission tables live in PHP, so SQL cannot do it here).
--
-- Each ALTER adds one column on its own (see V001003000 for why).
-- ============================================================

-- ------------------------------------------------------------
-- 1. posts
--
-- meta_description_auto : excerpt of the body baked on save; used when meta_description is empty.
-- share_text            : social-post text typed by the editor. NULL = use share_text_auto.
-- share_text_auto       : text generated on save (title + excerpt + category hashtags), sized for X.
-- raw_html_allowed      : whether the person who last saved the body held post:raw_html.
--                         Recompiles use this, not anyone's current role.
-- content_saved_by      : who last saved the body. 0 = the Super User. No foreign key: the value
--                         must survive the user's deletion so --reassess can revoke it.
-- ------------------------------------------------------------
ALTER TABLE `posts` ADD COLUMN `meta_description_auto` VARCHAR(255) NULL DEFAULT NULL COMMENT 'Excerpt baked on save; used when meta_description is empty' AFTER `meta_keywords`;
ALTER TABLE `posts` ADD COLUMN `share_text`            VARCHAR(500) NULL DEFAULT NULL COMMENT 'Social-post text typed by the editor. NULL = share_text_auto' AFTER `meta_description_auto`;
ALTER TABLE `posts` ADD COLUMN `share_text_auto`       TEXT         NULL DEFAULT NULL COMMENT 'Social-post text generated on save' AFTER `share_text`;
ALTER TABLE `posts` ADD COLUMN `raw_html_allowed`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Last body save was by a holder of post:raw_html' AFTER `is_sponsored`;
ALTER TABLE `posts` ADD COLUMN `content_saved_by`      INT UNSIGNED NULL DEFAULT NULL COMMENT 'Who last saved the body. 0 = Super User. No FK on purpose' AFTER `raw_html_allowed`;

-- ------------------------------------------------------------
-- 2. categories
-- ------------------------------------------------------------
ALTER TABLE `categories` ADD COLUMN `hashtags`     VARCHAR(255) NULL DEFAULT NULL COMMENT 'Space-separated hashtags for share text' AFTER `name`;
ALTER TABLE `categories` ADD COLUMN `feed_enabled` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Publish /category/{slug}/feed.*' AFTER `hashtags`;

-- ------------------------------------------------------------
-- 3. post_templates
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `post_templates` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(255) NOT NULL COMMENT 'Label shown in the admin only',
  `type`         VARCHAR(10)  NOT NULL DEFAULT 'post' COMMENT 'post|page',
  `lang`         VARCHAR(5)   NOT NULL DEFAULT 'en' COMMENT 'Content language code',
  `title`        VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Title pattern; {date} is replaced',
  `body`         LONGTEXT     NOT NULL COMMENT 'Markdown; {date} is replaced',
  `category_id`  INT UNSIGNED NULL DEFAULT NULL,
  `tags`         TEXT         NULL DEFAULT NULL COMMENT 'Comma-separated tag names',
  `layout`       VARCHAR(50)  NULL DEFAULT NULL,
  `show_toc`     TINYINT(1)   NOT NULL DEFAULT 0,
  `is_sponsored` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_post_templates_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. slug_redirects
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `slug_redirects` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`       VARCHAR(10)  NOT NULL COMMENT 'post|page',
  `lang`       VARCHAR(5)   NOT NULL,
  `old_slug`   VARCHAR(255) NOT NULL,
  `post_id`    INT UNSIGNED NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug_redirects_old` (`type`, `lang`, `old_slug`),
  KEY `idx_slug_redirects_post` (`post_id`),
  CONSTRAINT `fk_slug_redirects_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. speakers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `speakers` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`       VARCHAR(64)  NOT NULL COMMENT '[a-z0-9-]; written as :::say slug',
  `name`       VARCHAR(255) NOT NULL,
  `avatar`     VARCHAR(255) NULL DEFAULT NULL COMMENT 'Image URL path',
  `side`       VARCHAR(5)   NOT NULL DEFAULT 'left' COMMENT 'left|right',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_speakers_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
