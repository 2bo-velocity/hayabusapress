-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- ============================================================
-- HayabusaPress V001005000 (1.5.0) — series, character count, reading time
--
-- The same three changes as the MySQL version; see its comments for the reasoning.
--
-- After applying this file run  php core/cli.php posts:recompile  once, so that char_count /
-- reading_minutes are filled in for existing posts.
--
-- PostgreSQL has no ON UPDATE CURRENT_TIMESTAMP: the application sets updated_at on every UPDATE.
-- ============================================================

-- ------------------------------------------------------------
-- 1. series
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS series (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  lang             VARCHAR(5)   NOT NULL DEFAULT 'en',      -- Every post in the series has this language
  slug             VARCHAR(255) NOT NULL,
  title            VARCHAR(255) NOT NULL,
  description      TEXT         NULL,                       -- Markdown
  description_html TEXT         NULL,                       -- Rendered on save
  status           VARCHAR(16)  NOT NULL DEFAULT 'ongoing', -- ongoing|completed|hiatus
  cover_image      VARCHAR(255) NULL,                       -- Image URL path
  feed_enabled     BOOLEAN      NOT NULL DEFAULT FALSE,     -- Publish /feeds/{lang}/series/{slug}.*
  post_count       INT          NOT NULL DEFAULT 0,         -- Published posts. Moved by deltas in core/Counters.php
  char_count       BIGINT       NOT NULL DEFAULT 0,         -- Characters of the published posts. Moved by deltas
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_series_lang_slug UNIQUE (lang, slug)
);

-- ------------------------------------------------------------
-- 2. posts: series membership
-- ------------------------------------------------------------
ALTER TABLE posts ADD COLUMN IF NOT EXISTS series_id    INT          NULL REFERENCES series (id) ON DELETE SET NULL; -- Series this post belongs to
ALTER TABLE posts ADD COLUMN IF NOT EXISTS series_order INT          NOT NULL DEFAULT 0;  -- Position in the series (spaced by 10)
ALTER TABLE posts ADD COLUMN IF NOT EXISTS series_part  VARCHAR(100) NULL;                -- Chapter heading within the series
CREATE INDEX IF NOT EXISTS idx_posts_series ON posts (series_id, status, series_order, id);

-- ------------------------------------------------------------
-- 3. posts: character count and reading time
-- ------------------------------------------------------------
ALTER TABLE posts ADD COLUMN IF NOT EXISTS char_count      INT      NOT NULL DEFAULT 0; -- Characters of the body, counted on save
ALTER TABLE posts ADD COLUMN IF NOT EXISTS reading_minutes SMALLINT NOT NULL DEFAULT 0; -- Reading time in minutes, computed on save
