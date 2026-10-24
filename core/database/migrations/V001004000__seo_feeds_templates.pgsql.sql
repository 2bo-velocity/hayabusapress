-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- ============================================================
-- HayabusaPress V001004000 (1.4.0) — SEO, distribution feeds, templates, raw-HTML provenance
--
-- The same five changes as the MySQL version; see its comments for the reasoning.
--
-- After applying this file run  php core/cli.php posts:recompile --reassess  once, so that
-- raw_html_allowed is set from each post's author role.
--
-- PostgreSQL has no ON UPDATE CURRENT_TIMESTAMP: the application sets updated_at on every UPDATE.
-- ============================================================

-- ------------------------------------------------------------
-- 1. posts
-- ------------------------------------------------------------
ALTER TABLE posts ADD COLUMN IF NOT EXISTS meta_description_auto VARCHAR(255) NULL;                  -- Excerpt baked on save
ALTER TABLE posts ADD COLUMN IF NOT EXISTS share_text            VARCHAR(500) NULL;                  -- Typed by the editor. NULL = share_text_auto
ALTER TABLE posts ADD COLUMN IF NOT EXISTS share_text_auto       TEXT         NULL;                  -- Generated on save
ALTER TABLE posts ADD COLUMN IF NOT EXISTS raw_html_allowed      BOOLEAN      NOT NULL DEFAULT FALSE; -- Last body save was by a holder of post:raw_html
ALTER TABLE posts ADD COLUMN IF NOT EXISTS content_saved_by      INT          NULL;                  -- Who last saved the body. 0 = Super User. No FK on purpose

-- ------------------------------------------------------------
-- 2. categories
-- ------------------------------------------------------------
ALTER TABLE categories ADD COLUMN IF NOT EXISTS hashtags     VARCHAR(255) NULL;                  -- Space-separated hashtags
ALTER TABLE categories ADD COLUMN IF NOT EXISTS feed_enabled BOOLEAN      NOT NULL DEFAULT FALSE; -- Publish /category/{slug}/feed.*

-- ------------------------------------------------------------
-- 3. post_templates
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS post_templates (
  id           INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name         VARCHAR(255) NOT NULL,                 -- Admin-only label
  type         VARCHAR(10)  NOT NULL DEFAULT 'post',  -- post|page
  lang         VARCHAR(5)   NOT NULL DEFAULT 'en',
  title        VARCHAR(255) NOT NULL DEFAULT '',      -- {date} is replaced
  body         TEXT         NOT NULL,                 -- Markdown; {date} is replaced
  category_id  INT          NULL REFERENCES categories (id) ON DELETE SET NULL,
  tags         TEXT         NULL,                     -- Comma-separated tag names
  layout       VARCHAR(50)  NULL,
  show_toc     BOOLEAN      NOT NULL DEFAULT FALSE,
  is_sponsored BOOLEAN      NOT NULL DEFAULT FALSE,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- 4. slug_redirects
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS slug_redirects (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  type       VARCHAR(10)  NOT NULL,
  lang       VARCHAR(5)   NOT NULL,
  old_slug   VARCHAR(255) NOT NULL,
  post_id    INT          NOT NULL REFERENCES posts (id) ON DELETE CASCADE,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_slug_redirects_old UNIQUE (type, lang, old_slug)
);
CREATE INDEX IF NOT EXISTS idx_slug_redirects_post ON slug_redirects (post_id);

-- ------------------------------------------------------------
-- 5. speakers
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS speakers (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  slug       VARCHAR(64)  NOT NULL,                  -- [a-z0-9-]; written as :::say slug
  name       VARCHAR(255) NOT NULL,
  avatar     VARCHAR(255) NULL,                      -- Image URL path
  side       VARCHAR(5)   NOT NULL DEFAULT 'left',   -- left|right
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_speakers_slug UNIQUE (slug)
);
