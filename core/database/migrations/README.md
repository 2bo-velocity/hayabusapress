[![日本語](https://img.shields.io/badge/lang-%E6%97%A5%E6%9C%AC%E8%AA%9E-green)](README.ja.md)

# Database Migrations

This directory holds **schema diffs between released versions** as forward-only migrations.

## Conventions
- Filename: `V{major}{minor}{patch}__{description}.{driver}.sql`, each number zero-padded to 3 digits
  - e.g. `V001003000__widgets_layouts_links.mysql.sql` / `V001003000__widgets_layouts_links.pgsql.sql` (1.3.0)
- `core/Migrator.php` applies them in ascending version order, idempotently. There is no `down` (rollback) — an iron rule. A mistake is corrected by **adding a further migration**, never by editing or reversing an old one.
- Write every SQL file idempotently (`IF NOT EXISTS` and friends). MySQL DDL is non-transactional, so a partially applied file must converge when it is run again.

## Current diffs

| Version | File | Changes | After applying |
|---|---|---|---|
| 1.3.0 | `V001003000__widgets_layouts_links` | `sidebar_widgets.title` / `settings`; `posts.layout` / `show_toc` / `is_sponsored`; new tables `blocks` and `links` | Nothing to rebuild. Every new column defaults to the 1.0.0 behaviour |
| 1.4.0 | `V001004000__seo_feeds_templates` | `posts.meta_description_auto` / `share_text` / `share_text_auto` / `raw_html_allowed` / `content_saved_by`; `categories.hashtags` / `feed_enabled`; new tables `post_templates`, `slug_redirects` and `speakers` | Run `php core/cli.php posts:recompile --reassess` once: it records, for every post, whether its body keeps raw HTML (from the author's current role) |
| 1.5.0 | `V001005000__series` | New table `series`; `posts.series_id` / `series_order` / `series_part` (with `idx_posts_series`) / `char_count` / `reading_minutes` | Run `php core/cli.php posts:recompile` once: it fills in the character count and reading time of every existing post |
| 1.7.0 | `V001007000__collections` | New table `collections`; `series.collection_id` / `collection_order` | Nothing beyond the usual `php core/cli.php system:init` |

1.0.0 itself has no diff file: it is the baseline (`V001000000`), and its schema is the starting point
that the diffs above move forward.

## How this directory divides work with schema.*.sql
The **complete table definitions for a new install are the single source of truth in the parent
directory**: `schema.mysql.sql` / `schema.pgsql.sql`. They already contain every diff in this
directory and stamp each one as applied, so a new install never runs the diffs.

The split is: **`schema.*.sql` = the current complete form (for new installs)**, **this directory =
the diff history (for upgrading existing installs)**. **When you change the schema, update both.**
Updating only one makes new installs and existing installs diverge.

## Checklist when adding a diff (4 places)

1. `core/database/migrations/V{nnn}__{description}.{mysql,pgsql}.sql` — the diff itself (written idempotently)
2. The table definitions in `core/database/schema.{mysql,pgsql}.sql` — **fold the same change in** (the complete form for new installs)
3. The `schema_migrations` INSERT at the end of `core/database/schema.{mysql,pgsql}.sql` — **record that version as one already-applied row** (checksum `'BASELINE'`)
4. `SCHEMA_VERSION` in `core/Schema.php` — raise it to the newest `V…` (leave `BASELINE_VERSION` alone)

**Skip step 3 and a database imported straight from SQL is misdiagnosed.** The full schema may be
current in content, but if the recorded version is still `V001000000`,
`Migrator::schemaDriftWarning()` reports a mismatch against `SCHEMA_VERSION` and the admin keeps
showing "Schema version mismatch". `db:install` hides the problem, because `Migrator::baseline()`
stamps every known migration after applying the SQL — but **importing `schema.*.sql` directly through
phpMyAdmin and the like is a legitimate installation route on shared hosting**, and that route has no
PHP-side correction. The full schema alone has to be complete, bookkeeping included.

> `'BASELINE'` marks a version as "arrived via the full schema, not applied incrementally".
> `db:status` and `db:migrate` exclude rows carrying this sentinel from checksum verification, so
> there is no need to hand-write a file's real SHA-256 here (doing so would go stale the moment
> anyone touched the migration file).

## The two version constants

`core/Schema.php` defines two. Confusing them makes `db:status` misreport.

| Constant | Meaning | How it moves |
|---|---|---|
| `SCHEMA_VERSION` | **The version the code expects** | Raised to the newest `V…` every time a diff is added. While there are no diffs it equals `BASELINE_VERSION` (`V001000000`) |
| `BASELINE_VERSION` | The origin marker meaning "this came from the full schema" | Pinned to `V001000000`. **Never raised** (raising it would collide with the diff row of the same version on the primary key) |

`db:status` compares **the highest version in `schema_migrations`** against `SCHEMA_VERSION`.

## Commands
- `php core/cli.php db:install` — new install: apply `schema.{driver}.sql`, seed content, baseline every known migration
- `php core/cli.php db:migrate` — existing install: apply pending migrations in ascending order, idempotently
- `php core/cli.php db:status` — check what has been applied

### Rebuilding derived data (sometimes required after applying a diff)
- `php core/cli.php tags:reindex` — rebuild `post_tags`' denormalized columns (and the counter columns) from `posts`
- `php core/cli.php counters:rebuild` — rebuild only `categories.post_count` / `tags.post_count`
- `php core/cli.php sitemap:rebuild` — regenerate `sitemap.xml` immediately (`--if-pending` only when a rebuild is pending)
