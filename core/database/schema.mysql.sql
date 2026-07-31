-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- HayabusaPress (HBP) - MySQL 8.x Schema (HBP 1.0.0 / V001000000)
-- InnoDB / utf8mb4_unicode_ci
--
-- The complete, current form of the schema, used for new installs. The schema_migrations INSERT at
-- the end of this file stamps the same version as SCHEMA_VERSION in core/Schema.php, so this file is
-- self-contained: importing it directly through phpMyAdmin does not leave the admin reporting a
-- schema version mismatch.
--
-- 1.0.0 is the first release, so there are no incremental migrations: migrations/ is empty and the
-- definitions below are the whole schema. That includes the indexing work for large installations:
--   1. Public search: ft_posts_title (FULLTEXT + the ngram parser; fails on MariaDB, which then
--      falls back to LIKE)
--   2. Denormalized listing keys on post_tags (type/lang/status/published_at + idx_post_tags_list)
--   3. categories.post_count / tags.post_count counter columns

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- ============================================================
-- users
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `email`            VARCHAR(255)     NOT NULL,
  `password_hash`    VARCHAR(255)     NOT NULL,
  `name`             VARCHAR(255)     NOT NULL,
  `role`             VARCHAR(20)      NOT NULL COMMENT 'administrator|editor|author|contributor',
  `locale`           VARCHAR(5)       NOT NULL DEFAULT 'en' COMMENT 'Admin UI language code',
  `avatar_filename`  VARCHAR(64)      NULL DEFAULT NULL,
  `biography`        TEXT             NULL DEFAULT NULL,
  `sns_links`        TEXT             NULL DEFAULT NULL COMMENT 'JSON: {"x":"...","github":"..."}',
  `two_factor_secret` VARCHAR(255)    NULL DEFAULT NULL COMMENT 'Base32, encrypted / NULL means 2FA is not enabled',
  `tfa_last_step`    BIGINT           NULL DEFAULT NULL COMMENT 'Replay prevention: most recent successful time-step',
  `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- categories
-- ============================================================
CREATE TABLE IF NOT EXISTS `categories` (
  `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`  VARCHAR(10)  NOT NULL COMMENT 'post|page',
  `lang`  VARCHAR(5)   NOT NULL DEFAULT 'en' COMMENT 'Language code',
  `slug`  VARCHAR(255) NOT NULL,
  `name`  VARCHAR(255) NOT NULL,
  `post_count` INT NOT NULL DEFAULT 0 COMMENT 'Number of published posts. Moved by deltas in core/Counters.php',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_lang_slug` (`type`, `lang`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- tags
-- ============================================================
CREATE TABLE IF NOT EXISTS `tags` (
  `id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`  VARCHAR(10)  NOT NULL COMMENT 'post|page',
  `lang`  VARCHAR(5)   NOT NULL DEFAULT 'en' COMMENT 'Language code',
  `slug`  VARCHAR(255) NOT NULL,
  `name`  VARCHAR(255) NOT NULL,
  `post_count` INT NOT NULL DEFAULT 0 COMMENT 'Number of published posts. Moved by deltas in core/Counters.php',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_lang_slug` (`type`, `lang`, `slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- posts (articles and static pages unified)
-- ============================================================
CREATE TABLE IF NOT EXISTS `posts` (
  `id`                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `type`                 VARCHAR(10)   NOT NULL COMMENT 'post|page',
  `lang`                 VARCHAR(5)    NOT NULL DEFAULT 'en' COMMENT 'Content language code',
  `slug`                 VARCHAR(255)  NOT NULL,
  `title`                VARCHAR(255)  NOT NULL,
  `content_markdown`     LONGTEXT      NOT NULL,
  `content_html`         LONGTEXT      NOT NULL,
  `status`               VARCHAR(20)   NOT NULL COMMENT 'published|draft|scheduled',
  `category_id`          INT UNSIGNED  NULL DEFAULT NULL,
  `tags_cache`           TEXT          NULL DEFAULT NULL COMMENT 'JSON',
  `allow_comment`        TINYINT(1)    NOT NULL DEFAULT 1,
  `show_related_posts`   TINYINT(1)    NOT NULL DEFAULT 1,
  `show_share`           TINYINT(1)    NOT NULL DEFAULT 1,
  `meta_description`     VARCHAR(255)  NULL DEFAULT NULL,
  `meta_keywords`        VARCHAR(255)  NULL DEFAULT NULL,
  `user_id`              INT UNSIGNED  NULL DEFAULT NULL COMMENT 'NULL means the Super User',
  `show_author`          TINYINT(1)    NOT NULL DEFAULT 1,
  `page_template`        VARCHAR(50)   NULL DEFAULT NULL COMMENT 'Plugin page-template ID',
  `trans_group`          VARCHAR(36)   NULL DEFAULT NULL COMMENT 'Translation group key (UUID)',
  `hreflang_cache`       TEXT          NULL DEFAULT NULL COMMENT 'JSON cache of sibling-language URLs',
  `featured_image`       VARCHAR(255)  NULL DEFAULT NULL COMMENT 'Featured image, relative path. When NULL, falls back to the first body image, then the site logo (resolved on save)',
  `featured_image_alt`   VARCHAR(255)  NULL DEFAULT NULL COMMENT 'Alt text for the featured image (a11y/SEO)',
  `comment_count`        INT UNSIGNED  NOT NULL DEFAULT 0,
  `published_at`         DATETIME      NULL DEFAULT NULL COMMENT 'Publication time (the truth for post date). NULL while draft. Stored as UTC',
  `deleted_at`           DATETIME      NULL DEFAULT NULL COMMENT 'Soft delete = trash. NULL = alive. Non-NULL = time it was trashed. The public side excludes it via Method A (unpublishing through status); the admin listings switch between normal and trash on deleted_at',
  `created_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_type_lang_slug`    (`type`, `lang`, `slug`),
  UNIQUE KEY `uk_trans_group_lang`  (`trans_group`, `lang`),
  KEY `idx_posts_type_list`         (`type`, `status`, `published_at` DESC),
  KEY `idx_posts_lang_list`         (`type`, `lang`, `status`, `published_at` DESC),
  KEY `idx_posts_cat_list`          (`category_id`, `lang`, `status`, `published_at` DESC),
  KEY `idx_posts_user`              (`user_id`, `status`),
  KEY `idx_posts_trans_group`       (`trans_group`),
  KEY `idx_posts_schedule`          (`status`, `published_at`),
  KEY `idx_posts_admin_list`        (`type`, `deleted_at`, `updated_at` DESC),
  KEY `idx_posts_created`           (`type`, `created_at`),
  CONSTRAINT `fk_posts_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_posts_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`      (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- post_tags (join table)
-- ============================================================
-- Type/lang/status/published_at are copies of the posts columns (denormalized cache).
-- They live here so that filtering and ordering a tag listing is satisfied by idx_post_tags_list alone.
-- posts is always the source of truth. If these drift, rebuild with: php core/cli.php tags:reindex
CREATE TABLE IF NOT EXISTS `post_tags` (
  `post_id`      INT UNSIGNED NOT NULL,
  `tag_id`       INT UNSIGNED NOT NULL,
  `type`         VARCHAR(10)  NOT NULL DEFAULT 'post'  COMMENT 'Copy of posts.type',
  `lang`         VARCHAR(5)   NOT NULL DEFAULT ''      COMMENT 'Copy of posts.lang',
  `status`       VARCHAR(20)  NOT NULL DEFAULT 'draft' COMMENT 'Copy of posts.status',
  `published_at` DATETIME     NULL DEFAULT NULL        COMMENT 'Copy of posts.published_at (the sort key for listings)',
  PRIMARY KEY (`post_id`, `tag_id`),
  KEY `idx_tag_id` (`tag_id`),
  KEY `idx_post_tags_list` (`tag_id`, `type`, `lang`, `status`, `published_at` DESC, `post_id`),
  CONSTRAINT `fk_post_tags_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_post_tags_tag`  FOREIGN KEY (`tag_id`)  REFERENCES `tags`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full-text index for public search. The ngram parser is essential for tokenizing Japanese.
-- It is a separate statement from CREATE TABLE so that creating the posts table itself still
-- succeeds on MariaDB, which has no WITH PARSER ngram (when it fails, PostSearch falls back to LIKE).
ALTER TABLE `posts` ADD FULLTEXT INDEX `ft_posts_title` (`title`) WITH PARSER ngram;

-- ============================================================
-- attachments
-- ============================================================
CREATE TABLE IF NOT EXISTS `attachments` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `post_id`       INT UNSIGNED  NOT NULL,
  `filename`      VARCHAR(64)   NOT NULL COMMENT 'md5(uniqid()) + extension',
  `original_name` VARCHAR(255)  NOT NULL,
  `mime_type`     VARCHAR(100)  NOT NULL,
  `file_size`     INT UNSIGNED  NOT NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_post_id` (`post_id`),
  CONSTRAINT `fk_attachments_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`        VARCHAR(128) NOT NULL,
  `data`      BLOB         NOT NULL,
  `timestamp` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- comments
-- ============================================================
CREATE TABLE IF NOT EXISTS `comments` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `post_id`      INT UNSIGNED  NOT NULL,
  `author_name`  VARCHAR(100)  NOT NULL,
  `author_email` VARCHAR(255)  NULL DEFAULT NULL COMMENT 'made optional (nullable). Not required, since the address is never used (no notifications, no Gravatar)',
  `author_url`   VARCHAR(255)  NULL DEFAULT NULL,
  `content`      TEXT          NOT NULL,
  `status`       VARCHAR(20)   NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|spam|trash',
  `ip_address`   VARCHAR(45)   NOT NULL,
  `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comments_post`    (`post_id`, `status`, `created_at`),
  KEY `idx_status_created`   (`status`, `created_at`),
  CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- navigation_menus
-- ============================================================
CREATE TABLE IF NOT EXISTS `navigation_menus` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `location`   VARCHAR(30)   NOT NULL COMMENT 'pc_header|pc_sidebar_left|pc_sidebar_right|pc_footer|sp_header|sp_footer',
  `title`      VARCHAR(255)  NOT NULL,
  `url`        VARCHAR(255)  NOT NULL,
  `sort_order` INT           NOT NULL DEFAULT 0,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_location_sort` (`location`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- sidebar_widgets
-- ============================================================
CREATE TABLE IF NOT EXISTS `sidebar_widgets` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sidebar_location` VARCHAR(30)  NOT NULL COMMENT 'sidebar_left|sidebar_right',
  `widget_key`       VARCHAR(50)  NOT NULL COMMENT 'search|recent_comments|archive|categories|tags',
  `sort_order`       INT          NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_location_sort` (`sidebar_location`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- auth_throttle (rate limiting for login and 2FA)
-- ============================================================
CREATE TABLE IF NOT EXISTS `auth_throttle` (
  `scope`         VARCHAR(8)   NOT NULL COMMENT 'ip|id|2fa',
  `scope_key`     VARCHAR(64)  NOT NULL COMMENT 'The IP as a string, or hash_hmac(email, APP_KEY)',
  `fail_count`    INT          NOT NULL DEFAULT 0,
  `first_fail_at` DATETIME     NOT NULL,
  `locked_until`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`scope`, `scope_key`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- blocked_ips (blocklist of spam IPs for submissions)
-- ============================================================
CREATE TABLE IF NOT EXISTS `blocked_ips` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `cidr`       VARCHAR(50)   NOT NULL COMMENT 'A single IP or a CIDR range (IPv4/IPv6)',
  `reason`     VARCHAR(255)  NULL DEFAULT NULL COMMENT 'manual|auto:dnsbl|auto:honeypot, etc.',
  `source`     VARCHAR(16)   NOT NULL COMMENT 'manual|auto',
  `hit_count`  INT UNSIGNED  NOT NULL DEFAULT 0,
  `expires_at` DATETIME      NULL DEFAULT NULL COMMENT 'NULL = permanent',
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cidr` (`cidr`),
  KEY `idx_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- schema_migrations (migration bookkeeping)
-- ============================================================
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version`    VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(255) NOT NULL,
  `checksum`   CHAR(64)     NOT NULL COMMENT 'SHA-256 of the SQL file',
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Initial master data
-- ============================================================
INSERT IGNORE INTO `categories` (`id`, `type`, `lang`, `slug`, `name`) VALUES
  (1, 'post', 'ja', 'uncategorized',  '未分類'),
  (2, 'post', 'en', 'uncategorized', 'Uncategorized');

INSERT IGNORE INTO `tags` (`id`, `type`, `lang`, `slug`, `name`) VALUES
  (1, 'post', 'ja', 'news', 'お知らせ'),
  (2, 'post', 'en', 'news', 'News');

INSERT IGNORE INTO `sidebar_widgets` (`sidebar_location`, `widget_key`, `sort_order`) VALUES
  ('sidebar_right', 'search',          0),
  ('sidebar_right', 'recent_comments', 1),
  ('sidebar_right', 'categories',      2),
  ('sidebar_right', 'archive',         3),
  ('sidebar_right', 'tags',            4);

INSERT IGNORE INTO `navigation_menus` (`location`, `title`, `url`, `sort_order`) VALUES
  ('pc_header', 'ホーム', '/', 0);

-- ============================================================
-- Pinning the schema version (baseline + any migrations already folded in / M-series)
-- A version identifier is V + zero-padded major(3)+minor(3)+patch(3), a fixed width: 1.0.0 = V001000000.
--
-- First row = the baseline. The origin marker meaning "this database came from the full schema",
--   pinned to V001000000. In 1.0.0 it is the only row: being the first release, there is no earlier
--   version to upgrade from and therefore no incremental migration to record.
-- Later rows = migrations already folded into this file. The table definitions above are the current
--   complete form, so the corresponding migrations must count as applied.
-- The checksum 'BASELINE' is a sentinel meaning "arrived via the full schema, not applied
--   incrementally" (db:status / db:migrate exclude such rows from checksum verification).
--
-- This file is written so that the bookkeeping is complete on its own up to the current version:
-- importing the SQL directly through phpMyAdmin does not leave the admin reporting a version
-- mismatch (it does not rely on the baseline stamping done in PHP by db:install).
--
-- NOTE: when adding a migration, fold the change into the definitions above AND add a row here.
--   The highest version must match SCHEMA_VERSION in core/Schema.php.
-- ============================================================
INSERT IGNORE INTO `schema_migrations` (`version`, `name`, `checksum`, `applied_at`) VALUES
  ('V001000000', 'baseline: full schema (HBP 1.0.0)', 'BASELINE', NOW());
