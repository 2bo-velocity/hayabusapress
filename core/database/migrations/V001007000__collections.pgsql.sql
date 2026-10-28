-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
-- ============================================================
-- HayabusaPress V001007000 (1.7.0) — collections
--
-- The same two changes as the MySQL version; see its comments for the reasoning.
--
-- PostgreSQL has no ON UPDATE CURRENT_TIMESTAMP: the application sets updated_at on every UPDATE.
-- ============================================================

-- ------------------------------------------------------------
-- 1. collections
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS collections (
  id               INT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  lang             VARCHAR(5)   NOT NULL DEFAULT 'en',      -- Every series in the collection has this language
  slug             VARCHAR(255) NOT NULL,
  title            VARCHAR(255) NOT NULL,
  description      TEXT         NULL,                       -- Markdown
  description_html TEXT         NULL,                       -- Rendered on save
  cover_image      VARCHAR(255) NULL,                       -- Image URL path
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT uk_collections_lang_slug UNIQUE (lang, slug)
);

-- ------------------------------------------------------------
-- 2. series: collection membership
-- ------------------------------------------------------------
ALTER TABLE series ADD COLUMN IF NOT EXISTS collection_id    INT NULL REFERENCES collections (id) ON DELETE SET NULL; -- Collection this series belongs to
ALTER TABLE series ADD COLUMN IF NOT EXISTS collection_order INT NOT NULL DEFAULT 0;  -- Position in the collection (spaced by 10)
