-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- ============================================================
-- HayabusaPress V001003000 (1.3.0) — widgets, layouts, reusable blocks, managed links
--
--   1. sidebar_widgets: per-widget heading and settings (text / image / HTML / recent posts / ...)
--   2. posts: theme layout, table of contents, sponsored-content notice
--   3. blocks: reusable content fragments referenced from posts as {{ block:slug }}
--   4. links: managed outbound links served as /go/{alias}
--
-- Every new column has a default that reproduces the 1.0.0 behaviour, so existing sites render
-- exactly as before until an editor opts in. No backfill is needed.
--
-- Each ALTER adds one column on its own: MySQL has no ADD COLUMN IF NOT EXISTS, and the migrator
-- treats "Duplicate column" as already applied. Splitting the statements keeps a re-run converging
-- column by column instead of stopping at the first one that already exists.
-- ============================================================

-- ------------------------------------------------------------
-- 1. sidebar_widgets
--
-- title    : NULL keeps the localized default heading (the 1.0.0 output).
-- settings : JSON whose shape depends on widget_key, e.g. {"count":5} for recent_posts or
--            {"body":"..."} for text/html. NULL for widgets that take no settings.
-- ------------------------------------------------------------
ALTER TABLE `sidebar_widgets` ADD COLUMN `title`    VARCHAR(255) NULL DEFAULT NULL COMMENT 'Heading override. NULL = localized default' AFTER `widget_key`;
ALTER TABLE `sidebar_widgets` ADD COLUMN `settings` TEXT         NULL DEFAULT NULL COMMENT 'JSON. Shape depends on widget_key'          AFTER `title`;
-- Comment only: list the new widget keys so the column documents itself as in the full schema.
ALTER TABLE `sidebar_widgets` MODIFY COLUMN `widget_key` VARCHAR(50) NOT NULL COMMENT 'search|recent_comments|archive|categories|tags|recent_posts|posts_pick|text|image|html|menu';

-- ------------------------------------------------------------
-- 2. posts
--
-- layout       : a layout ID declared in the active theme's theme.json. NULL = "default".
--                Independent of page_template, which lets a plugin replace the body.
-- show_toc     : bake a table of contents from H2/H3 at save time. Off by default so existing
--                posts do not change when recompiled.
-- is_sponsored : prepend the sponsored-content notice and mark outbound links rel="sponsored".
-- ------------------------------------------------------------
ALTER TABLE `posts` ADD COLUMN `layout`       VARCHAR(50) NULL DEFAULT NULL COMMENT 'Theme layout ID. NULL = default' AFTER `page_template`;
ALTER TABLE `posts` ADD COLUMN `show_toc`     TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'Bake a table of contents on save' AFTER `show_author`;
ALTER TABLE `posts` ADD COLUMN `is_sponsored` TINYINT(1)  NOT NULL DEFAULT 0 COMMENT 'Sponsored-content notice + rel=sponsored on outbound links' AFTER `show_toc`;

-- ------------------------------------------------------------
-- 3. blocks
--
-- The body is Markdown. Posts keep only a marker; the compiled HTML is substituted when the
-- full-page cache is generated, so editing a block purges the page cache instead of recompiling
-- every post that uses it.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `blocks` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `slug`       VARCHAR(64)  NOT NULL COMMENT '[a-z0-9-]',
  `lang`       VARCHAR(5)   NOT NULL DEFAULT 'en' COMMENT 'Content language code',
  `title`      VARCHAR(255) NOT NULL COMMENT 'Label shown in the admin only',
  `body`       TEXT         NOT NULL COMMENT 'Markdown',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_blocks_slug_lang` (`slug`, `lang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 4. links
--
-- Compiled to a PHP array on save; /go/{alias} answers with a 302 from that array without touching
-- the database. Only https:// targets registered here are redirected to, so the endpoint cannot be
-- used as an open redirector.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `links` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `alias`      VARCHAR(64)   NOT NULL COMMENT '[a-z0-9-]',
  `target_url` VARCHAR(2048) NOT NULL COMMENT 'https:// only',
  `note`       VARCHAR(255)  NULL DEFAULT NULL COMMENT 'Admin-only memo',
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_links_alias` (`alias`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
