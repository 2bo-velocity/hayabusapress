-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- ============================================================
-- HayabusaPress V001003000 (1.3.0) — widgets, layouts, reusable blocks, managed links
--
-- The same four changes as the MySQL version; see its comments for the reasoning.
--
-- Every new column has a default that reproduces the 1.0.0 behaviour, so existing sites render
-- exactly as before until an editor opts in. No backfill is needed.
--
-- PostgreSQL has no ON UPDATE CURRENT_TIMESTAMP: the application sets updated_at on every UPDATE of
-- blocks and links, as it already does for posts.
-- ============================================================

-- ------------------------------------------------------------
-- 1. sidebar_widgets
-- ------------------------------------------------------------
ALTER TABLE sidebar_widgets ADD COLUMN IF NOT EXISTS title    VARCHAR(255) NULL;  -- Heading override. NULL = localized default
ALTER TABLE sidebar_widgets ADD COLUMN IF NOT EXISTS settings TEXT         NULL;  -- JSON. Shape depends on widget_key

-- ------------------------------------------------------------
-- 2. posts
-- ------------------------------------------------------------
ALTER TABLE posts ADD COLUMN IF NOT EXISTS layout       VARCHAR(50) NULL;                -- Theme layout ID. NULL = default
ALTER TABLE posts ADD COLUMN IF NOT EXISTS show_toc     BOOLEAN     NOT NULL DEFAULT FALSE;  -- Bake a table of contents on save
ALTER TABLE posts ADD COLUMN IF NOT EXISTS is_sponsored BOOLEAN     NOT NULL DEFAULT FALSE;  -- Sponsored-content notice + rel=sponsored

-- ------------------------------------------------------------
-- 3. blocks
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- 4. links
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS links (
  id         INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  alias      VARCHAR(64)   NOT NULL,                 -- [a-z0-9-]
  target_url VARCHAR(2048) NOT NULL,                 -- https:// only
  note       VARCHAR(255)  NULL,                     -- Admin-only memo
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_links_alias UNIQUE (alias)
);
