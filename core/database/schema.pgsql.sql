-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- HayabusaPress (HBP) - PostgreSQL 13-16 Schema (HBP 1.3.0 / V001003000)
--
-- The complete, current form of the schema, used for new installs. The schema_migrations INSERT at
-- the end of this file stamps the same version as SCHEMA_VERSION in core/Schema.php, so this file is
-- self-contained: importing it directly through psql / pgAdmin does not leave the admin reporting a
-- schema version mismatch.
--
-- The definitions below already include every migration in migrations/ (each is stamped as applied
-- at the end of this file). Notable parts of the schema:
--   1. Public search: idx_posts_title_trgm (pg_trgm GIN, because the default parser cannot tokenize
--      CJK)
--   2. Denormalized listing keys on post_tags (type/lang/status/published_at + idx_post_tags_list)
--   3. categories.post_count / tags.post_count counter columns
--   4. Widget settings, theme layouts, reusable blocks and managed links (V001003000)
--
-- [IMPORTANT - CHARACTER ENCODING] This schema assumes a database whose encoding is UTF8.
--   A PostgreSQL database's encoding is fixed at CREATE DATABASE time and cannot be changed
--   afterwards (it requires a dump and re-create). HBP creates only the tables, never the database
--   itself, so the operator must create it as UTF8 beforehand. Recommended:
--     CREATE DATABASE hayabusapress
--       ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0;
--   client_encoding is SET to UTF8 automatically by HBP (core/DB.php) on connect.
--   Connecting to a non-UTF8 database shows a warning banner when the admin starts up.

-- ============================================================
-- users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
  id                INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  email             VARCHAR(255) NOT NULL,
  password_hash     VARCHAR(255) NOT NULL,
  name              VARCHAR(255) NOT NULL,
  role              VARCHAR(20)  NOT NULL,
  locale            VARCHAR(5)   NOT NULL DEFAULT 'en',
  avatar_filename   VARCHAR(64)  NULL,
  biography         TEXT         NULL,
  sns_links         TEXT         NULL,
  two_factor_secret VARCHAR(255) NULL,
  tfa_last_step     BIGINT       NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_users_email UNIQUE (email)
);

-- ============================================================
-- categories
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
  id   INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  type VARCHAR(10)  NOT NULL,
  lang VARCHAR(5)   NOT NULL DEFAULT 'en',
  slug VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  post_count INT NOT NULL DEFAULT 0,   -- Number of published posts. Moved by deltas in core/Counters.php
  CONSTRAINT uk_categories_type_lang_slug UNIQUE (type, lang, slug)
);

-- ============================================================
-- tags
-- ============================================================
CREATE TABLE IF NOT EXISTS tags (
  id   INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  type VARCHAR(10)  NOT NULL,
  lang VARCHAR(5)   NOT NULL DEFAULT 'en',
  slug VARCHAR(255) NOT NULL,
  name VARCHAR(255) NOT NULL,
  post_count INT NOT NULL DEFAULT 0,   -- Number of published posts. Moved by deltas in core/Counters.php
  CONSTRAINT uk_tags_type_lang_slug UNIQUE (type, lang, slug)
);

-- ============================================================
-- posts
-- ============================================================
CREATE TABLE IF NOT EXISTS posts (
  id                   INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  type                 VARCHAR(10)  NOT NULL,
  lang                 VARCHAR(5)   NOT NULL DEFAULT 'en',
  slug                 VARCHAR(255) NOT NULL,
  title                VARCHAR(255) NOT NULL,
  content_markdown     TEXT         NOT NULL,
  content_html         TEXT         NOT NULL,
  status               VARCHAR(20)  NOT NULL,
  category_id          INT          NULL REFERENCES categories(id) ON DELETE SET NULL,
  tags_cache           TEXT         NULL,
  allow_comment        BOOLEAN      NOT NULL DEFAULT TRUE,
  show_related_posts   BOOLEAN      NOT NULL DEFAULT TRUE,
  show_share           BOOLEAN      NOT NULL DEFAULT TRUE,
  meta_description     VARCHAR(255) NULL,
  meta_keywords        VARCHAR(255) NULL,
  user_id              INT          NULL REFERENCES users(id) ON DELETE RESTRICT,
  show_author          BOOLEAN      NOT NULL DEFAULT TRUE,
  show_toc             BOOLEAN      NOT NULL DEFAULT FALSE,  -- Bake a table of contents on save
  is_sponsored         BOOLEAN      NOT NULL DEFAULT FALSE,  -- Sponsored-content notice + rel=sponsored on outbound links
  page_template        VARCHAR(50)  NULL,
  layout               VARCHAR(50)  NULL,        -- Theme layout ID. NULL = default
  trans_group          VARCHAR(36)  NULL,
  hreflang_cache       TEXT         NULL,
  featured_image       VARCHAR(255) NULL,        -- Featured image, relative path. When NULL, falls back to the first body image, then the site logo (resolved on save)
  featured_image_alt   VARCHAR(255) NULL,        -- Alt text for the featured image (a11y/SEO)
  comment_count        INT          NOT NULL DEFAULT 0,
  published_at         TIMESTAMP    NULL,        -- Publication time (the truth for post date). NULL while draft. Stored as UTC
  deleted_at           TIMESTAMP    NULL,        -- Soft delete = trash. NULL = alive / non-NULL = time it was trashed
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_posts_type_lang_slug   UNIQUE (type, lang, slug),
  CONSTRAINT uk_trans_group_lang       UNIQUE (trans_group, lang)
);
-- partial indexes built around published_at (a PostgreSQL-specific optimization)
CREATE INDEX IF NOT EXISTS idx_posts_type_list
    ON posts (type, published_at DESC) WHERE status = 'published';
CREATE INDEX IF NOT EXISTS idx_posts_lang_list
    ON posts (type, lang, published_at DESC) WHERE status = 'published';
CREATE INDEX IF NOT EXISTS idx_posts_cat_list
    ON posts (category_id, lang, published_at DESC) WHERE status = 'published';
CREATE INDEX IF NOT EXISTS idx_posts_user                 ON posts (user_id, status);
CREATE INDEX IF NOT EXISTS idx_posts_trans_group          ON posts (trans_group);
-- Firing scheduled publication: a partial index over the scheduled rows only
CREATE INDEX IF NOT EXISTS idx_posts_schedule
    ON posts (published_at) WHERE status = 'scheduled';
-- admin listings (both views: normal = deleted_at IS NULL / trash = IS NOT NULL)
CREATE INDEX IF NOT EXISTS idx_posts_admin_list ON posts (type, deleted_at, updated_at DESC);
-- date-range search on creation time
CREATE INDEX IF NOT EXISTS idx_posts_created    ON posts (type, created_at);

-- ============================================================
-- post_tags
-- ============================================================
-- Type/lang/status/published_at are copies of the posts columns (denormalized cache).
-- They live here so that filtering and ordering a tag listing is satisfied by idx_post_tags_list alone.
-- posts is always the source of truth. If these drift, rebuild with: php core/cli.php tags:reindex
CREATE TABLE IF NOT EXISTS post_tags (
  post_id      INT NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  tag_id       INT NOT NULL REFERENCES tags(id)  ON DELETE CASCADE,
  type         VARCHAR(10) NOT NULL DEFAULT 'post',
  lang         VARCHAR(5)  NOT NULL DEFAULT '',
  status       VARCHAR(20) NOT NULL DEFAULT 'draft',
  published_at TIMESTAMP   NULL,
  PRIMARY KEY (post_id, tag_id)
);
CREATE INDEX IF NOT EXISTS idx_post_tags_tag_id ON post_tags (tag_id);
-- Partial index over published rows only (the same approach as the other listing indexes)
CREATE INDEX IF NOT EXISTS idx_post_tags_list
    ON post_tags (tag_id, type, lang, published_at DESC, post_id)
 WHERE status = 'published';

-- Public search. PostgreSQL's default parser cannot segment CJK, so instead of to_tsvector
-- this accelerates LIKE '%term%' with a pg_trgm GIN index. CREATE EXTENSION needs privileges, and
-- if it fails search still works as a plain LIKE (it just becomes a seq scan).
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE INDEX IF NOT EXISTS idx_posts_title_trgm
    ON posts USING gin (lower(title) gin_trgm_ops);

-- ============================================================
-- attachments
-- ============================================================
CREATE TABLE IF NOT EXISTS attachments (
  id            INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  post_id       INT          NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  filename      VARCHAR(64)  NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,
  file_size     INT          NOT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_attachments_post_id ON attachments (post_id);

-- ============================================================
-- sessions
-- ============================================================
CREATE TABLE IF NOT EXISTS sessions (
  id        VARCHAR(128) NOT NULL PRIMARY KEY,
  data      BYTEA        NOT NULL,
  timestamp INT          NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sessions_timestamp ON sessions (timestamp);

-- ============================================================
-- comments
-- ============================================================
CREATE TABLE IF NOT EXISTS comments (
  id           INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  post_id      INT          NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
  author_name  VARCHAR(100) NOT NULL,
  author_email VARCHAR(255) NULL,        -- made optional (nullable). Not required, since the address is never used
  author_url   VARCHAR(255) NULL,
  content      TEXT         NOT NULL,
  status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
  ip_address   VARCHAR(45)  NOT NULL,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_comments_post        ON comments (post_id, status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_comments_status_created ON comments (status, created_at DESC);

-- ============================================================
-- navigation_menus
-- ============================================================
CREATE TABLE IF NOT EXISTS navigation_menus (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  location   VARCHAR(30)  NOT NULL,
  title      VARCHAR(255) NOT NULL,
  url        VARCHAR(255) NOT NULL,
  sort_order INT          NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_navigation_menus_location_sort ON navigation_menus (location, sort_order);

-- ============================================================
-- sidebar_widgets
-- ============================================================
CREATE TABLE IF NOT EXISTS sidebar_widgets (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  sidebar_location VARCHAR(30) NOT NULL,
  widget_key       VARCHAR(50) NOT NULL,
  title            VARCHAR(255) NULL,          -- Heading override. NULL = localized default
  settings         TEXT        NULL,           -- JSON. Shape depends on widget_key
  sort_order       INT         NOT NULL DEFAULT 0,
  created_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_sidebar_widgets_location_sort ON sidebar_widgets (sidebar_location, sort_order);

-- ============================================================
-- blocks (reusable content fragments, referenced from posts as {{ block:slug }})
-- No ON UPDATE in PostgreSQL: the application sets updated_at on every UPDATE.
-- ============================================================
CREATE TABLE IF NOT EXISTS blocks (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  slug       VARCHAR(64)  NOT NULL,                  -- [a-z0-9-]
  lang       VARCHAR(5)   NOT NULL DEFAULT 'en',     -- Content language code
  title      VARCHAR(255) NOT NULL,                  -- Label shown in the admin only
  body       TEXT         NOT NULL,                  -- Markdown
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_blocks_slug_lang UNIQUE (slug, lang)
);

-- ============================================================
-- links (managed outbound links, served as /go/{alias})
-- ============================================================
CREATE TABLE IF NOT EXISTS links (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  alias      VARCHAR(64)   NOT NULL,                 -- [a-z0-9-]
  target_url VARCHAR(2048) NOT NULL,                 -- https:// only
  note       VARCHAR(255)  NULL,                     -- Admin-only memo
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_links_alias UNIQUE (alias)
);

-- ============================================================
-- auth_throttle (rate limiting for login and 2FA)
-- ============================================================
CREATE TABLE IF NOT EXISTS auth_throttle (
  scope         VARCHAR(8)   NOT NULL,
  scope_key     VARCHAR(64)  NOT NULL,
  fail_count    INT          NOT NULL DEFAULT 0,
  first_fail_at TIMESTAMP    NOT NULL,
  locked_until  TIMESTAMP    NULL,
  PRIMARY KEY (scope, scope_key)
);
CREATE INDEX IF NOT EXISTS idx_auth_throttle_locked ON auth_throttle (locked_until);

-- ============================================================
-- blocked_ips (blocklist of spam IPs for submissions)
-- ============================================================
CREATE TABLE IF NOT EXISTS blocked_ips (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  cidr       VARCHAR(50)  NOT NULL,
  reason     VARCHAR(255) NULL,
  source     VARCHAR(16)  NOT NULL,
  hit_count  INT          NOT NULL DEFAULT 0,
  expires_at TIMESTAMP    NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_blocked_ips_cidr UNIQUE (cidr)
);
CREATE INDEX IF NOT EXISTS idx_blocked_ips_expires ON blocked_ips (expires_at);

-- ============================================================
-- schema_migrations (migration bookkeeping)
-- ============================================================
CREATE TABLE IF NOT EXISTS schema_migrations (
  version    VARCHAR(20)  NOT NULL PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,
  checksum   CHAR(64)     NOT NULL,
  applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- Initial master data
-- ============================================================
INSERT INTO categories (type, lang, slug, name)
  SELECT 'post', 'ja', 'uncategorized', '未分類'
  WHERE NOT EXISTS (SELECT 1 FROM categories WHERE type='post' AND lang='ja' AND slug='uncategorized');

INSERT INTO categories (type, lang, slug, name)
  SELECT 'post', 'en', 'uncategorized', 'Uncategorized'
  WHERE NOT EXISTS (SELECT 1 FROM categories WHERE type='post' AND lang='en' AND slug='uncategorized');

INSERT INTO tags (type, lang, slug, name)
  SELECT 'post', 'ja', 'news', 'お知らせ'
  WHERE NOT EXISTS (SELECT 1 FROM tags WHERE type='post' AND lang='ja' AND slug='news');

INSERT INTO tags (type, lang, slug, name)
  SELECT 'post', 'en', 'news', 'News'
  WHERE NOT EXISTS (SELECT 1 FROM tags WHERE type='post' AND lang='en' AND slug='news');

INSERT INTO sidebar_widgets (sidebar_location, widget_key, sort_order)
  SELECT 'sidebar_right', 'search', 0
  WHERE NOT EXISTS (SELECT 1 FROM sidebar_widgets WHERE widget_key='search');
INSERT INTO sidebar_widgets (sidebar_location, widget_key, sort_order)
  SELECT 'sidebar_right', 'recent_comments', 1
  WHERE NOT EXISTS (SELECT 1 FROM sidebar_widgets WHERE widget_key='recent_comments');
INSERT INTO sidebar_widgets (sidebar_location, widget_key, sort_order)
  SELECT 'sidebar_right', 'categories', 2
  WHERE NOT EXISTS (SELECT 1 FROM sidebar_widgets WHERE widget_key='categories');
INSERT INTO sidebar_widgets (sidebar_location, widget_key, sort_order)
  SELECT 'sidebar_right', 'archive', 3
  WHERE NOT EXISTS (SELECT 1 FROM sidebar_widgets WHERE widget_key='archive');
INSERT INTO sidebar_widgets (sidebar_location, widget_key, sort_order)
  SELECT 'sidebar_right', 'tags', 4
  WHERE NOT EXISTS (SELECT 1 FROM sidebar_widgets WHERE widget_key='tags');

INSERT INTO navigation_menus (location, title, url, sort_order)
  SELECT 'pc_header', 'ホーム', '/', 0
  WHERE NOT EXISTS (SELECT 1 FROM navigation_menus WHERE location='pc_header');

-- ============================================================
-- Pinning the schema version (baseline + any migrations already folded in / M-series)
-- A version identifier is V + zero-padded major(3)+minor(3)+patch(3), a fixed width: 1.0.0 = V001000000.
--
-- First row = the baseline. The origin marker meaning "this database came from the full schema",
--   pinned to V001000000.
-- Later rows = migrations already folded into this file. The table definitions above are the current
--   complete form, so the corresponding migrations must count as applied.
-- The checksum 'BASELINE' is a sentinel meaning "arrived via the full schema, not applied
--   incrementally" (db:status / db:migrate exclude such rows from checksum verification).
--
-- This file is written so that the bookkeeping is complete on its own up to the current version:
-- importing the SQL directly through psql / pgAdmin does not leave the admin reporting a version
-- mismatch (it does not rely on the baseline stamping done in PHP by db:install).
--
-- NOTE: when adding a migration, fold the change into the definitions above AND add a row here.
--   The highest version must match SCHEMA_VERSION in core/Schema.php.
-- ============================================================
INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES
  ('V001000000', 'baseline: full schema (HBP 1.0.0)', 'BASELINE', CURRENT_TIMESTAMP),
  ('V001003000', 'widgets_layouts_links',             'BASELINE', CURRENT_TIMESTAMP)
  ON CONFLICT (version) DO NOTHING;
