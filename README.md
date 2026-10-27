[![日本語](https://img.shields.io/badge/lang-%E6%97%A5%E6%9C%AC%E8%AA%9E-green)](README.ja.md)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3_with_Plugin_Exception-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4)](https://www.php.net/)

# HayabusaPress

**A blazing-fast, ultra-lightweight, multi-locale, plugin-extensible, and secure blogging engine — driven by raw SQL and a pre-compiled architecture.**

> · PHP 8.0+ · MySQL 8 / PostgreSQL 13–16 · Zero Composer dependencies · AGPL-3.0-or-later WITH Plugin Exception

---

## The Concept

HayabusaPress (HBP) is a radical antithesis to WordPress.  
It dismantles WordPress's weight, lax security, and accumulated technical debt by rebuilding from scratch on modern PHP 8.0+.

> **The Aesthetics of Subtraction.** Strip away everything unnecessary. Define clearly what will never be built.

Every design decision answers one question: *can we pre-compute this so the frontend costs nothing?*

---

## Feature Highlights

| Feature | Details |
|---|---|
| **Zero Composer dependencies** | Backend built entirely on native PHP. No vendor lock-in. |
| **Raw SQL supremacy** | ORM fully eliminated. Plain PDO + pure SQL only. |
| **Pre-compiled architecture** | Routes, menus, sidebars, categories, tags, and author profiles all compiled at write time. Zero runtime computation. |
| **On-demand Full Page Cache (FPC)** | HTML saved to disk on first hit. All subsequent hits are `readfile()` — zero DB queries. |
| **Stampede lock** | `flock + LOCK_NB` + double-check prevents thundering herd on simultaneous cache misses. |
| **Precise FPC invalidation** | A single post edit purges only the post itself + home + category + tag + archive pages (and all their paginated variants). No full rebuild. No TTL. |
| **CSS/JS inline packing** | Theme CSS and JS minified and inlined into HTML at compile time — single HTTP round-trip delivery. |
| **Auto-Healing** | All caches auto-regenerate on next access if deleted. |
| **Multi-locale (2-axis)** | Content language (`lang`) and admin UI language (`locale`) are fully independent. Default language occupies `/`; others get `/{lang}/` prefix. |
| **Translation groups + hreflang** | Link post translations together. `hreflang` tags and language switcher generated from a single source of truth — `posts.hreflang_cache`. |
| **Author singleton** | All author profiles compiled to `authors.cache.php`. Posts reference by `user_id` only — no JOIN, no N+1. |
| **Pre-compiled CSP** | Hash-based CSP (nonce-free, FPC-compatible). `report-only` → `enforce` staged rollout. |
| **Auth throttling** | `auth_throttle` table: per-IP + per-account exponential backoff and lockout. |
| **HTML sanitizer** | DOM allowlist sanitizer (`ext-dom`) — second line of defense against stored XSS. |
| **Furigana & emphasis dots** | Novel-style markup — `｜漢字《かんじ》` ruby and `《《文字》》` emphasis dots (bouten) — compiled to `<ruby>` / `<em class="hbp-bouten">` at save time. Works for both posts and pages. |
| **Split-pane Markdown editor** | Separate title/body inputs; plain-text body (no in-place rich conversion) with a side-by-side live preview sharing the server-side `MarkdownCompiler`. Drag-and-drop image upload, autosave, ruby/emphasis toolbar. |
| **Attachment list & delete** | A thumbnail list of the editing content's attachments (`attachments` rows) below the body field. Insert any image into the body at the cursor, or **physically delete** it (confirmation required; warning is strengthened if the file is still referenced in the body). The body `![]()` is **never auto-rewritten** (you remove broken links yourself). Delete API needs `media:manage` + CSRF; own content only without `post:edit_other`. |
| **Character count** | Live character count at the top-left of the title and body fields, counted by **code point** (`[...str].length` — CJK and half-width each count as 1). Title has a hard 255-char cap warning (caution past 230); body shows a soft length guide (light / standard / comprehensive). Client-side only — zero server or FPC impact. |
| **Widgets & areas** | 12 widget types (search, recent comments, archive, categories, tags, recent posts, hand-picked posts, Markdown text, image banner, raw HTML, menu, series) placed in 6 areas: right/left sidebar, below the header, before/after the body, and the footer. Each widget can take its own title. All compiled to per-area, per-language HTML at save time — an empty area emits nothing. |
| **Per-post layouts** | A theme declares its layouts in `theme.json` (which areas each one shows, plus a `<body>` class); the editor offers them as a select. The bundled theme ships *Standard*, *Single column* and *Minimal*. |
| **Table of contents** | Per-post toggle. Built from H2/H3 at save time and inserted before the first H2 (needs at least two H2s). Heading ids are de-duplicated. |
| **Reusable blocks** | Write a block once in **Admin → Blocks**, place it in any post with a line `{{ block:slug }}`. Posts keep only a marker; the block is expanded when the page cache is written, so editing a block updates every post that uses it immediately — no re-save. |
| **Buttons** | A line `[[button:Label\|URL\|note]]` renders a call-to-action button with an optional note above it. Unsafe URL schemes are dropped. |
| **Managed links (`/go/`)** | Register `alias → https://…` in **Admin → Links** and link to `/go/alias`. Served as a 302 from a compiled array — no DB query, no page cache. Only registered `https://` targets; responses carry `X-Robots-Tag: noindex, nofollow`, and the generated `robots.txt` disallows `/go/`. |
| **Sponsored disclosure** | Per-post "contains promotional content" toggle: a notice is printed at the top of the body (text configurable via `SPONSORED_NOTICE_TEXT`) and external links get `rel="sponsored"`. Links to `/go/` always carry `rel="sponsored nofollow"`. |
| **Publishing slots & schedule** | Declare the times you publish every day (e.g. `07:00, 12:00, 21:00`) in the SEO settings. The editor offers them as buttons plus a *next free slot* shortcut, and **Admin → Schedule** lays scheduled/published posts out as days × slots with free slots highlighted. Admin-only — nothing runs on public pages. |
| **Titles, descriptions & slugs** | A page-title format (`{title} \| {site}`). An empty description is filled at save time with a ~120-character excerpt of the body (`meta_description_auto`; the TOC and sponsored notice are skipped). An empty slug is generated from the title, the date/time (`20261024-0700`) or the post ID (`p123`); a title with no ASCII left falls back to date/time. |
| **Series** | An ordered run of ordinary posts — a serialized story, a tutorial in parts. Each post shows its series and chapter above the body and *previous / contents / next* below it; the series gets a table of contents at `/post/series/{slug}` (chapter headings, characters per post, ongoing / completed / on hiatus, an optional cover and its own feed; `SERIES_PAGE_SIZE` × `SERIES_FPC_DEPTH` = 10,000 posts by default). Posts keep their own URLs and every other feature. Neighbours are looked up when the page cache is written (a one-row index range each), and a change purges only the post, its old and new neighbours and the table of contents. Managed in **Admin → Series** (order and chapters) and on each post's edit screen. |
| **Character count & reading time** | Counted at save time from the rendered body — ruby readings, code, the TOC and the sponsored notice are left out — and stored on the post (`char_count`, `reading_minutes`). CJK characters are timed by characters per minute and the rest by words per minute, added together, so mixed-language posts get a sensible figure (`READING_SPEED_CJK` / `READING_SPEED_WORDS`). The bundled theme prints "N min read" (`SHOW_READING_TIME`). |
| **Structured data (JSON-LD)** | `BlogPosting` + `BreadcrumbList` on posts (with `isPartOf` and the series in the breadcrumbs for a post of a series), `CreativeWorkSeries` on a series' table of contents, `WebPage` on pages, `WebSite` on the top page — built while the page cache is written. `application/ld+json` is a data block, so the CSP needs no hash for it. |
| **Distribution feeds** | JSON Feed 1.1 (`/feed.json`) next to Atom/RSS, and per-category and per-series feeds (`/feeds/{lang}/category/{slug}.*`, `/feeds/{lang}/series/{slug}.*`) for categories and series with *feed* switched on. JSON items carry `_hbp.share_text`: a social-post text you type, or one generated at save time from title + description + the category's hashtags and sized to **X's weighted count** (CJK = 2, URL = 23) with room left for the link. |
| **301 for renamed slugs** | Changing the slug of a published post keeps the old one; `/post/{old}` answers 301 to the current URL from a compiled array (no DB, never cached). Chains collapse, and a slug reused by another post wins over the redirect. |
| **Post templates** | Starting points for new posts (title, body, category, tags, layout, TOC, sponsored flag; `{date}` → today). Using one **copies** it — editing the template later changes no existing post. |
| **Callouts, speech bubbles & post cards** | `:::quote Source\|URL`, `:::box summary Title` and `:::say speaker` … `:::` wrap ordinary Markdown in a cited quote, a box or a speech bubble (speakers are registered in the admin). `{{ card:slug }}` bakes a card linking another published post. Wrappers are built after the sanitizer from validated arguments; the content inside is sanitized as usual. |
| **WebP & image dimensions** | Uploaded JPEG/PNG also get a WebP copy (`{file}.webp`, kept only when smaller) served through `<picture>`; body images get their real `width`/`height` so nothing shifts while they load. The copy is an `attachments` row of its own, so it moves, is deleted and garbage-collected with the original. `IMAGE_WEBP=off` disables it. |
| **Conditional GET** | Cached pages carry `ETag` / `Last-Modified` (from the cache file itself — one `stat`) and `Cache-Control: no-cache`; a matching `If-None-Match` / `If-Modified-Since` gets **304**. |
| **Raw-HTML trust per post** | Whether a post's embeds survive the sanitizer is recorded on the post: did the person who last saved its **body** hold `post:raw_html`? Saving only metadata keeps it, and `posts:recompile` honours it — so a lower role fixing a category never strips an admin's embed. Revoke on purpose with `posts:recompile --reassess` or the "also turn off embeds" option when downgrading a role. |
| **Featured image / OGP** | `posts.featured_image` + `featured_image_alt`. Set via the right-side drawer; fallback chain (post's first image → site logo) resolved **at save time** and baked — zero runtime body scan. Drives `og:image`. |
| **Responsive & fluid typography** | Theme guideline: reader-side body column ~720px, `clamp()` 16→18px; admin-side liquid full-width, labels 14px, **inputs ≥16px** (prevents iOS zoom). |
| **Physical media isolation** | Draft media lives in `storage/upload/` (web-inaccessible). Moved to `public/assets/articles/` atomically on publish, and **moved back on unpublish**. |
| **Media lifecycle** | `attachments` table is the source of truth for which files move on publish/unpublish (body regex is a secondary check). Orphan draft files are reclaimed by `media:gc`. |
| **Directory sharding** | Media is spread across a `{ab}/{cd}/` two-level fan-out (65,536 buckets) keyed on the filename prefix, avoiding single-directory file pile-up (scan degradation / inode limits at millions of files). `attachments.filename` still holds the bare filename; every store/serve path is derived through a single `shardPath()` (no schema change). Existing flat layouts migrate via `media:reshard`. |
| **Auto-provisioned storage dirs** | Before any write, a single `ensureDir()` helper recursively creates the destination (parent + shard intermediates `{ab}/{cd}/`) — default mode `0755`, override with `.env` `MEDIA_DIR_MODE`. Install/environment diagnostics pre-verify that the three media parents are creatable and writable; on failure a clear error is returned and **no `attachments` row is written** (order: ensure dir → file saved → DB record). |
| **Unified avatar upload** | Both regular users and the Super User upload avatars through a dedicated API that shares the same **EXIF stripping, `finfo` MIME check, `shardPath()` and `ensureDir()`** as body images, storing to `public/assets/site/avatars/{ab}/{cd}/`. Closes the manual-upload hole where EXIF passed through untouched. The Super User is DB-independent, so only the reference is recorded by hand in `.env` `SUPER_USER_AVATAR`. |
| **Email-optional comments** | Comments require only name + body; **email and website are optional** (`comments.author_email` is nullable). HBP ships no reply-notification mail and no external Gravatar, so it never collects email it cannot use. Commenter avatars are a **bundled SVG silhouette** (`public/assets/site/avatar-default.svg`, `fill="currentColor"` to track the theme) — zero external requests. Per-content acceptance is the `posts.allow_comment` toggle in the editor's right drawer. |
| **Comment moderation filters** | The comment admin shares the content-list design (PC filter bar + table, mobile card list, pagination). Filter by status (with count badges), **created-date range**, name / email / website (`LIKE`), and linked content (`post_id`, reached from the content list). The comment **body is intentionally not searchable** (long-text = index-inefficient). No new indexes — `status`/date narrow via `idx_status_created`, `post_id` via `idx_comments_post`. No bulk actions; delete still requires confirmation. Needs `comment:moderate`. |
| **Super User** | Highest-privilege admin authenticated via `.env` only — never stored in DB. A DB wipe cannot lock you out. |
| **Role-based access control** | Static RBAC matrix (`permissions.php`, zero DB queries). 4 staff roles (Administrator / Editor / Author / Contributor) + Super User, mapped across 18 fine-grained permissions (posts, comments, media, taxonomy, navigation, blocks, templates, links, maintenance, IP, plugins, themes, users, settings). |
| **Native TOTP 2FA** | RFC 6238-compliant. Replay prevention (`tfa_last_step`), 5-strike session destruction, time-step reuse detection. |
| **Manifest-based plugins** | `manifest.json` declares hooks. Hook registry pre-compiled and priority-sorted. No full plugin load per request. Static HTML snippets for `<head>` and the end of `<body>` are baked into the page cache — zero runtime cost. |
| **zip-slip-safe installer** | `PluginInstaller` validates every ZIP entry, blocks symlinks + zip-bombs, optionally enforces Ed25519 signatures. |
| **Dual IP blocklist** | `blocked_ips` table blocks POST-type spam. `SITE_IP_DENYLIST` blocks page views via compiled array — zero DB hit. |
| **Atom 1.0 + RSS 2.0 + JSON Feed** | Pre-compiled per-language feeds (`feed.atom.xml`, `feed.rss.xml`, `feed.json` — chosen by `FEED_FORMAT`). Generated at post publish — zero DB on serving. Autodiscovery `<link>` in every `<head>`. |
| **Scheduled publishing** | `status='scheduled'` + `published_at` (UTC). Fires via pseudo-cron (default, **zero impact on the FPC fast path** — one integer compare), OS cron, or a manual `/cron` endpoint. `published_at` is the single source of truth for post date. |
| **Sitemap index** | Auto-splits into `sitemaps/posts-N.xml` (50 k URL chunks) when site grows large. |
| **Browser maintenance** | Admin → Maintenance page rebuilds sitemap / feeds / plugins / all caches from the browser — no SSH required (equivalent to `system:init`). |
| **Configurable admin URL** | `ADMIN_PATH` can be any value (front-controller routing, no `.htaccess` edits). Reserved public routes fall back to `admin`. |
| **Shared hosting support** | Method B (app root outside web root + `HBP_BASE_PATH`) or Method A (`.htaccess` rewrite). `.env` exposure self-diagnosed at install time. |
| **MySQL 8 + PostgreSQL 13–16** | Full dual-driver support. FK behavior, partial indexes, and UPSERT syntax all properly branched. |
| **No XML-RPC** | Not a single line. Attack surface eliminated. |

---

## Requirements

| Component | Requirement |
|---|---|
| **PHP** | 8.0 or later (`declare(strict_types=1)` strictly enforced) |
| **Database** | MySQL 8.x **or** PostgreSQL 13–16 |
| **Web server** | Nginx (recommended) · Apache (`mod_rewrite`) |
| **PHP extensions** | `pdo_mysql` or `pdo_pgsql`, `mbstring`, `hash`, `ZipArchive`, `dom` |
| **Optional** | `sodium` (Ed25519 plugin signing + 2FA secret encryption) |

---

## Quick Start

### Option A — Browser Installer

Set your web server document root to `public/` and open the site.  
If `.env` is missing, the embedded installer launches automatically and collects:

1. Site name, URL, and default content language
2. Database driver and connection details
3. Super User email, password, and admin UI language
4. Admin panel path (change from `admin` to obscure the login URL)

The installer generates `.env`, applies the schema, seeds a Hello World post, compiles all caches, and redirects to the login page.

> **Shared hosting users**: read the [Shared Hosting Deployment](#shared-hosting-deployment) section first.

### Option B — CLI (headless / Docker)

```bash
# 1. Clone
git clone https://github.com/2bo-velocity/hayabusapress.git && cd hayabusapress

# 2. Configure
cp .env.example .env
# Edit .env: DB credentials, SITE_URL, SUPER_USER_EMAIL, etc.

# 3. Generate secrets
php core/cli.php tool:key              # → paste APP_KEY into .env
php core/cli.php tool:hash "password"  # → paste hash as SUPER_USER_PASSWORD_HASH

# 4. Apply schema + seed sample post + record migration baseline
php core/cli.php db:install

# 5. Compile all caches
php core/cli.php system:init
php core/cli.php plugins:rebuild
php core/cli.php authors:rebuild
```

**Docker / container entrypoint:**

```bash
php core/cli.php db:install && php core/cli.php system:init && php core/cli.php plugins:rebuild
```

### Option C — Upgrade an existing installation

Releases that change the schema ship a forward-only migration in `core/database/migrations/`
(see [Diff migrations](#diff-migrations)). The procedure is the same for every release:

```bash
# 1. Back up the database and files first
mysqldump ...

# 2. Deploy the new code — the new classes must exist before the schema changes

# 3. Apply pending migrations (idempotent, forward-only)
php core/cli.php db:status    # DB version vs code version, and what is pending
php core/cli.php db:migrate

# 4. Rebuild derived data, then every cache
php core/cli.php system:init && php core/cli.php plugins:rebuild
```

Upgrading to **1.4.0** (`V001004000`) adds one step between 3 and 4: run
`php core/cli.php posts:recompile --reassess` once. It records, for every post, whether its body keeps
raw HTML (from the author's current role) and fills the generated description and share text.

Upgrading to **1.5.0** (`V001005000`) likewise adds `php core/cli.php posts:recompile` between 3 and 4: it
fills in the character count and reading time of every existing post (series start empty, so nothing
else needs backfilling).

> **Step 4 varies by release.** Some schema changes need denormalized data backfilled before the site
> reads correctly again — `tags:reindex` (post_tags listing keys) and `counters:rebuild`
> (`categories.post_count` / `tags.post_count`) are the usual ones. Whichever a release needs is
> spelled out in its release notes.
>
> If you imported `core/database/schema.{mysql,pgsql}.sql` by hand (through phpMyAdmin and the like),
> you are already at the current version — that file records it — and `db:migrate` has nothing to do.

---

## Generating the Initial Keys (`APP_KEY` and `SUPER_USER_PASSWORD_HASH`)

At install time, `.env` needs these two values. **Neither is a raw password — both are formatted strings.**

```dotenv
# 🔑 Application key (encryption & signing key)
APP_KEY="base64:CHANGE_ME"

# 🛡️ Super User (top admin) password hash
SUPER_USER_PASSWORD_HASH='$2y$10$...'
```

The bundled CLI (`tool:key` / `tool:hash`) is fastest when you have SSH, but **you can generate both without SSH** too.

> ⚠️ **Important**
> - **Do not generate the password hash on an "online generator" site** — your password may be sent to a third-party server. **Always generate it locally on your own machine.**
> - **Never change `APP_KEY` once set.** Doing so makes encrypted data (e.g. 2FA secrets) undecryptable.
> - Never send the generated values or your raw password to anyone via chat, email, or screenshots.

### 1. Generating `APP_KEY`

It is **32 random bytes, base64-encoded**, prefixed with `base64:`. Use any of these:

| Method | Command | Environment |
|---|---|---|
| A. Bundled CLI (fastest) | `php core/cli.php tool:key` | SSH |
| B. PHP on your PC | `php -r "echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;"` | No SSH |
| C. OpenSSL | `echo "base64:$(openssl rand -base64 32)"` | Mac/Linux, no SSH |
| D. Browser console | `"base64:" + btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))` | No PHP/OpenSSL |

> For method D, open DevTools (`F12`) → **Console**, paste the line, and use the contents **inside the double quotes** of the printed `"base64:…"`. `crypto.getRandomValues` runs entirely in-browser with no external transmission.

Writing it to `.env` (double quotes are fine — a `base64:` value contains no `$`):

```dotenv
APP_KEY="base64:k7Xp2v9Qw…(your generated value)…="
```

### 2. Generating `SUPER_USER_PASSWORD_HASH`

This is **not the login password itself, but its bcrypt hash** (`$2y$10$...`, ~60 chars). At login your typed password is verified against this hash; the raw password is never stored in `.env`.

| Method | Command / steps | Environment |
|---|---|---|
| A. Bundled CLI (fastest) | `php core/cli.php tool:hash "your-password"` | SSH |
| B. PHP on your PC (recommended) | `php -r "echo password_hash('your-password', PASSWORD_BCRYPT) . PHP_EOL;"` | No SSH |
| C. Temporary PHP file | see below (for hosts with PHP but no SSH) | No SSH |

> 💡 **Avoid leaving it in shell history (Mac/Linux, optional)** — typing the password on the command line records it in history. If that concerns you:
> ```bash
> php -r 'fwrite(STDOUT, "Password: "); system("stty -echo"); $p = trim(fgets(STDIN)); system("stty echo"); echo PHP_EOL . password_hash($p, PASSWORD_BCRYPT) . PHP_EOL;'
> ```

**Method C (temporary PHP file)** — when PHP runs but SSH is unavailable:

1. Upload this `makehash.php` to your web area (`public_html`, etc.):
   ```php
   <?php
   // makehash.php — DELETE immediately after generating the hash
   if (!empty($_GET['p'])) {
       echo '<pre>' . htmlspecialchars(password_hash($_GET['p'], PASSWORD_BCRYPT)) . '</pre>';
   } else {
       echo '<form>Password: <input name="p" autocomplete="off"><button>Generate</button></form>';
   }
   ```
2. Open `https://your-site/makehash.php` (over **HTTPS**), enter the password → **Generate**.
3. Copy the printed `$2y$10$...`.
4. **Delete `makehash.php`** — leaving it lets anyone generate hashes through it.

#### Writing it to `.env` (single quotes are mandatory)

A bcrypt hash contains `$`. In HBP's `.env`, **single quotes `'...'` read the value literally**, while double quotes `"..."` may treat `$` specially and corrupt it. **Always wrap the hash in single quotes.**

```dotenv
SUPER_USER_PASSWORD_HASH='$2y$10$abcdefghijklmnopqrstuv0123456789ABCDEFGHIJKLMNOPQRSTU'
```

- ✅ Correct: single quotes `'$2y$10$...'`
- ❌ Wrong: double quotes `"$2y$10$..."` (may break on `$2y`) / no quotes

### 3. After Setting Them

- Confirm `APP_KEY` is no longer `base64:CHANGE_ME`.
- Log into the admin panel with the Super User email (`SUPER_USER_EMAIL` in `.env`) and the raw password you hashed.
- If login fails, check: (1) the hash is single-quoted, (2) the hashed password matches what you typed, (3) nothing was truncated on copy (bcrypt is ~60 chars).
- **(Optional) Super User avatar.** `SUPER_USER_AVATAR` takes a **filename only** (no path). **Upload it through the dedicated avatar API** so it gets EXIF-stripped and placed via `shardPath()`, then record the resulting filename in `.env` by hand (manual direct upload is discouraged — EXIF would pass through untouched). Served from `public/assets/site/avatars/{ab}/{cd}/{file}`. Leave it empty for no avatar. Run `php core/cli.php authors:rebuild` after changing it.

### 4. FAQ

- **Can `APP_KEY` be changed later?** Generally no — encrypted data becomes unreadable. If unavoidable, follow the re-encryption steps in the migration guide.
- **Want to change the password?** Re-hash the new password and replace `SUPER_USER_PASSWORD_HASH`.
- **Why not store the raw password in `.env`?** Even if `.env` leaks, a one-way hash is hard to reverse into the original password.
- **Online bcrypt generators?** Not recommended — your password may be transmitted externally. Always generate locally (or via a temporary file on your own server).

---

## Shared Hosting Deployment

Many shared hosting providers fix the public directory (`public_html`, `htdocs`, `www`) and do not let you change it. Placing the entire HBP repository there exposes `core/`, `.env`, and `storage/` to the web — leaking DB credentials and the Super User password hash via `https://example.com/.env`.

HBP provides two deployment methods. **Method B is recommended.**

### Method B — App Root Outside Web Root (Recommended)

Place only the contents of `public/` in the web root; everything else lives outside.

```
/home/user/
├── hbp-app/                ← core/, storage/, plugins/, themes/, .env (NOT web-accessible)
└── public_html/            ← contents of public/ only
    ├── index.php           ← references hbp-app via HBP_BASE_PATH
    ├── .htaccess
    └── admin/
        └── index.php
```

Add this line near the top of `public/index.php` (and `public/admin/index.php`):

```php
define('HBP_BASE_PATH', '/home/user/hbp-app');
```

Or set the server environment variable `HBP_BASE_PATH=/home/user/hbp-app`.

### Method A — `.htaccess` Rewrite (Apache / LiteSpeed only)

Place `deploy/htaccess.root` as `.htaccess` in the public root directory. It rewrites all requests to `public/` and denies direct access to `core/`, `storage/`, `.env`.

> **Important**: This only works when `AllowOverride` is enabled on the server. If it is disabled, use Method B.

> **503 "Dangerous placement detected"?** With Method A the app root sits inside the web root, so the bootstrap self-defense halts by default. After confirming `https://your-site/.env` returns 403/404 (see below), set `HBP_ALLOW_NESTED_ROOT=true` in `.env` to clear the halt. Never set it true if `.env` is still reachable (200) — switch to Method B instead.

### Verify After Deployment

After either method, confirm these URLs return **403 or 404** (not 200):

```bash
curl -o /dev/null -s -w "%{http_code}\n" https://example.com/.env
curl -o /dev/null -s -w "%{http_code}\n" https://example.com/core/
curl -o /dev/null -s -w "%{http_code}\n" https://example.com/storage/
```

If any returns 200, stop immediately and re-check your deployment method.

See `deploy/README-shared-hosting.md` for step-by-step instructions.

On Nginx, `deploy/nginx.sample.conf` serves the feed files directly and falls back to `index.php` when one is
missing (Auto-Healing): `/feed.atom.xml`, `/feed.rss.xml`, `/feed.json`, their `/{lang}/…` variants, and the
per-category feeds under `/feeds/{lang}/category/`.

---

## CLI Reference

### Setup & Migration

| Command | Description |
|---|---|
| `tool:key` | Generate a `base64:`-encoded `APP_KEY` for HMAC and encryption |
| `tool:hash "pw"` | Generate a bcrypt/argon2id hash for `SUPER_USER_PASSWORD_HASH` |
| `db:install` | **New installs.** Apply `schema.{driver}.sql` (full schema + seed) whose trailing baseline INSERT records `V001000000` — the DB is immediately "up to date" |
| `db:migrate` | **Upgrades.** Apply only `V…` not yet in `schema_migrations`, ascending, forward-only; record each with real SHA-256 |
| `db:status` | Report: **DB version vs. `SCHEMA_VERSION` (code) vs. driver**, list pending migrations + the command to run, verify checksums (skips `BASELINE`) |

### Cache & Build

| Command | Description |
|---|---|
| `system:init` | Compile all caches: routes, menus, categories, tags, sidebars, SEO, plugins, author singleton, security headers |
| `plugins:rebuild` | Recompile plugin registry + priority-sorted hook map; sync routes |
| `plugins:checksums {dir\|id}` | Write the SHA-256 of every file into `manifest.json` `"files"`, for a package to be signed (`REQUIRE_SIGNED_PLUGINS`) |
| `lang:rebuild [locale]` | Delete MUI view caches for a locale (or all locales) to force re-bake |
| `assets:rebuild` | Purge all FPC + view caches + recompile CSP hashes after theme CSS/JS edits |
| `authors:rebuild` | Rebuild `authors.cache.php` + purge affected post FPCs |
| `posts:recompile [--reassess [--user=ID]]` | Re-run Markdown → HTML + sanitizer on all posts with the raw-HTML trust recorded on each (use after updating sanitizer rules, `SPONSORED_NOTICE_TEXT`, a speaker, or a post a card points to). Also refreshes the generated description and share text, and recounts characters and reading time. `--reassess` first resets that trust from the current role of whoever last saved each body (`--user=ID` limits it to one saver; `0` = Super User) |
| `blocks:rebuild` | Recompile `blocks.cache.php` (reusable blocks) and purge all FPC |
| `links:rebuild` | Recompile `links.cache.php` (the `/go/` alias table) |
| `media:gc [--days=N]` | Garbage-collect orphan draft media in `storage/upload/` not referenced by any `attachments` row, older than N days (default 7). Walks the `{ab}/{cd}/` shard tree |
| `media:reshard [--dry-run]` | Migrate an existing flat media layout into the `{ab}/{cd}/` two-level shards: move files, rewrite body/featured-image public URLs flat→shard, then regenerate search index, list FPC, sitemap & feeds. Idempotent |
| `cache:purge-category {id}` | Purge FPC for all posts in a category (precision cleanup) |
| `config:rebuild` | Explicitly regenerate `env.cache.php` |
| `feed:rebuild` | Regenerate the feeds of every language and every feed-enabled category and series (`FEED_ENABLED=true`) |
| `posts:publish-due` | Promote `scheduled` posts whose `published_at` has arrived (cron / manual trigger) |
| `schedule:recompile` | Recompute `next_schedule.php` (the next pseudo-cron fire time) |

> **No SSH?** The **Admin → Maintenance** page runs the same regeneration from the browser — rebuild sitemap, feeds, plugins, or **all caches** (equivalent to `system:init`). Requires the `maintenance:run` permission (Administrator or Super User; see Roles & Permissions). Ideal for shared hosting where the CLI is unavailable.

### Security Operations

| Command | Description |
|---|---|
| `tfa:reencrypt` | Encrypt existing plaintext TOTP secrets with `Crypto::encrypt()` |
| `admin:ip-show` | Display current admin IP allowlist configuration |
| `admin:ip-disable` | Clear allowlist immediately (SSH escape hatch if locked out) |
| `comment:block-ip {ip}` | Manually add an IP/CIDR to the POST blocklist |
| `comment:unblock-ip {ip}` | Remove an IP from the blocklist |
| `comment:blocklist` | List all blocked IPs with reason, expiry, and hit count |
| `site:deny-ip {ip}` | Add an IP/CIDR to the page-view denylist |
| `site:allow-ip {ip}` | Remove from page-view denylist |
| `site:denylist` | Recompile and display `site_denylist.cache.php` |

---

## Architecture

### Pre-Compiled Architecture

All expensive computation happens at **write time**. Frontend requests pay nothing.

| What | When compiled | Where stored |
|---|---|---|
| Markdown → HTML | Post save | `posts.content_html` |
| Route map | Plugin / lang / config change *(not post save)* | `cache/routes.cache.php` |
| Menus | Menu save | `cache/menus.cache.php` |
| Sidebars | Widget save or trigger | `cache/sidebar_{loc}_{lang}.cache.php` |
| Author profiles | Profile edit or `authors:rebuild` | `cache/authors.cache.php` |
| hreflang links | Post save (translation group change) | `posts.hreflang_cache` |
| CSP hashes | `assets:rebuild` | `cache/security_headers.cache.php` |
| Theme CSS/JS | View compile | Inlined into view cache HTML |
| Sitemap | Post publish / unpublish | `public/sitemap.xml` (+ `sitemaps/`) |
| Feeds (Atom/RSS/JSON) | Post publish / unpublish, category save | `public/feed.{atom.xml,rss.xml,json}`, `public/{lang}/…`, `public/feeds/{lang}/category/{slug}.*` |
| Description excerpt + share text | Post save | `posts.meta_description_auto`, `posts.share_text_auto` |
| Slug redirects | Post save / trash / restore / delete | `cache/redirects.cache.php` |
| Next schedule fire time | Post save / publish-due | `cache/next_schedule.php` (one integer) |

### On-Demand FPC

```
GET /post/hello-world
  ↓ cache hit?
  YES → emit security headers → readfile() → exit        ← zero DB
  NO  → acquire flock → DB fetch → render → atomic rename → emit → readfile
```

FPC file naming: `{lang}_{type}_{slug}.html` · paginated: `{lang}_home_p2.html`

A cache hit also sends `ETag` / `Last-Modified` derived from the cache file and `Cache-Control: no-cache`, so a
browser or crawler revalidates and gets a bodiless **304** while the page is unchanged. A purge (an edit) makes a
new file and therefore new validators.

### Router: O(1) Pattern Matching

The route cache stores only a **minimal map** — plugins, special endpoints, available langs, default lang. It never lists individual posts or categories. Post saves do **not** trigger `RouterCompiler::compile()`.

```
URL → strip lang prefix → match plugin map → pattern match (post|page|category|tag|archive|home)
                                                       ↓
                                            build FPC key → cache hit? → readfile
                                                                       → miss: DB + render + save
```

### Precise FPC Invalidation

`CacheManager::purgePost()` invalidates only what depends on the changed post:

| Purged | Condition |
|---|---|
| `{lang}_post_{slug}.html` | Always |
| `{lang}_home.html` + `_p{n}.html` | Post type |
| `{lang}_list_category_{cat}.html` + pages | Has category |
| `{lang}_list_tag_{tag}.html` + pages | Has tags |
| `{lang}_list_archive_{YYYY-MM}.html` + pages | Has date |

No full rebuild. No TTL. Stale reads: structurally impossible.

### Author Singleton

```php
// Compile time (profile save / CLI)
// authors.cache.php → [0 => SuperUser, 3 => YamadaTaro, ...]

// Render time (zero JOIN)
$author = AuthorCompiler::get($post['user_id'] ?? 0);
```

Changing a bio updates every post page the moment the next request hits — no per-post cache to invalidate one by one.

### Multi-Locale (2-Axis)

```
Content language (lang)    → URL structure  → FPC key
Admin UI language (locale) → .env / users.locale → ViewCompiler cache dir
```

Default language occupies `/`; all others get `/{lang}/`.  
Adding a language: drop `admin_{code}.php` + `frontend_{code}.php` in `core/lang/` → `system:init`. No code changes needed.

### Scheduled Publishing

A post is one of three states — `published` (now), `draft`, or `scheduled` (future `published_at`). `published_at` (UTC) is the **single source of truth** for the post date: lists, related posts, feeds, sitemap, and archives all sort and display by it. It's fixed at first publish and never changes on re-edit (`updated_at` tracks edits).

Firing is chosen via `.env` `SCHEDULE_MODE` and **never touches the FPC fast path**:

```
SCHEDULE_MODE=pseudo  (default) — index.php reads cache/next_schedule.php (one int),
                                  compares to time() once. Future/none → no-op, zero DB.
                                  Due → Scheduler::runDue() promotes the post and joins
                                  the normal publish pipeline (FPC purge, feeds, sitemap).
SCHEDULE_MODE=cron             — OS cron runs `php core/cli.php posts:publish-due`.
SCHEDULE_MODE=manual           — external monitor hits GET /cron (e.g. cron-job.org).
```

When a scheduled post is saved or fires, `Scheduler::recompileNext()` bakes the next due timestamp into `cache/next_schedule.php` — so the hot path stays a single integer comparison.

For exact publishing slots (e.g. on the hour), use `SCHEDULE_MODE=cron` with a per-minute OS cron: pseudo-cron fires on the next page view, which can be late on a quiet morning.

### Feeds

```ini
FEED_ENABLED=true
FEED_FORMAT=both     # both (= atom,rss) | all (= atom,rss,json) | atom | rss | json, or a comma list
```

`json` is JSON Feed 1.1 (`/feed.json`). Each item carries an `_hbp` extension — `share_text`, `category`,
`hashtags` — so an automation that posts new entries elsewhere can use the text as is. A category with
*Publish a feed for this category* (Admin → Categories) also gets `/feeds/{lang}/category/{slug}.{atom.xml,rss.xml,json}`;
its hashtags are appended to the generated share text of its posts.

---

## Roles & Permissions

HBP ships a **static, pre-compiled RBAC** defined in `core/permissions.php` and enforced by `core/Gate.php` — **zero DB queries** at check time (consistent with the pre-compiled architecture). Staff accounts created in **Admin → Users** are assigned exactly one role.

### Roles

| Role | Intended for | Summary |
|---|---|---|
| **Super User** | Site owner | `.env`-only. `Gate::can()` always returns `true` — every permission, unconditionally. Not stored in the DB. |
| **Administrator** | Trusted operator | Full site operation: users, settings, plugins, IP control, maintenance, all content. |
| **Editor** | Editorial lead | All content (any author), comment moderation, media, categories/tags. No site/user/plugin administration. |
| **Author** | Regular writer | Create, publish, schedule, and delete **their own** posts; manage **their own** media. |
| **Contributor** | Guest / junior writer | Create and edit **their own drafts** only. Cannot publish — submissions await Editor/Admin approval. |

### Permission Matrix

Each capability is a permission key checked via `Gate::can('post:publish')`. `✅ (own)` means limited to records owned by the user (`posts.user_id`).

| Permission key | Meaning | Administrator | Editor | Author | Contributor |
|---|---|:--:|:--:|:--:|:--:|
| `post:create` | Create a new post/page | ✅ | ✅ | ✅ | ✅ |
| `post:publish` | Publish or schedule (incl. `scheduled` + `published_at`) | ✅ | ✅ | ✅ (own) | ❌ |
| `post:edit_other` | Edit posts owned by others | ✅ | ✅ | ❌ | ❌ |
| `post:delete` | Delete posts | ✅ | ✅ | ✅ (own) | ✅ (own drafts) |
| `post:raw_html` | Pass raw HTML through the sanitizer (embeds) | ✅ | ❌ | ❌ | ❌ |
| `comment:moderate` | Approve / spam / delete comments | ✅ | ✅ | ❌ | ❌ |
| `media:manage` | Upload / delete media | ✅ | ✅ | ✅ (own) | ❌ |
| `taxonomy:manage` | Create / edit / delete categories & tags | ✅ | ✅ | ❌ | ❌ |
| `nav:manage` | Edit navigation menus & widgets (a raw-HTML widget also needs `post:raw_html`) | ✅ | ❌ | ❌ | ❌ |
| `blocks:manage` | Create / edit / delete reusable blocks and speech-bubble speakers | ✅ | ❌ | ❌ | ❌ |
| `templates:manage` | Create / edit / delete post templates | ✅ | ✅ | ❌ | ❌ |
| `links:manage` | Manage `/go/` links | ✅ | ❌ | ❌ | ❌ |
| `maintenance:run` | Rebuild caches/sitemap/feeds (Admin → Maintenance) | ✅ | ❌ | ❌ | ❌ |
| `ip:manage` | Manage admin allowlist & public blocklists (A/B) | ✅ | ❌ | ❌ | ❌ |
| `plugin:manage` | Install / enable / disable / delete plugins | ✅ | ❌ | ❌ | ❌ |
| `theme:manage` | Switch / upload / delete / rebuild the front theme | ✅ | ❌ | ❌ | ❌ |
| `user:manage` | Create / edit / delete staff accounts | ✅ | ❌ | ❌ | ❌ |
| `site:setting` | Edit global site settings | ✅ | ❌ | ❌ | ❌ |

### Notes

- **Single source of truth.** `core/permissions.php` is the only place the matrix lives; `Gate::can()` reads it. No capability is checked against the DB.
- **Ownership.** `✅ (own)` permissions resolve ownership via `posts.user_id` (and `attachments` ownership for media). Super User content is `user_id = NULL` (id `0`).
- **Scheduling is publishing.** Reserving a future `published_at` (`status='scheduled'`) requires `post:publish`; no separate permission. Contributors therefore cannot schedule.
- **Contributor workflow.** Without `post:publish`, a Contributor's post stays `draft`; an Editor/Administrator reviews and publishes it.
- **Raw HTML is recorded per post.** `post:raw_html` is checked when a post's body is saved and the answer is stored on the post (`raw_html_allowed`, `content_saved_by`). A save that does not change the body keeps it; `posts:recompile` reuses it. Losing the permission does not change existing posts until you revoke it: tick *also turn off embeds in posts this user saved* when changing the role in **Admin → Users**, or run `posts:recompile --reassess --user=ID`.
- **The schedule lists everyone's posts**, so **Admin → Schedule** needs `post:edit_other`.
- **Maintenance from the browser.** The **Admin → Maintenance** page (SSH-free cache rebuild) requires `maintenance:run`.
- **Super User is not a role row.** It is authenticated via `.env` and bypasses the matrix entirely; never create it in the `users` table.

---

## Directory Structure

```
hayabusapress/
├── .env                        # Runtime config (git-ignored)
├── .env.example                # Config template
├── tailwind.config.js          # Tailwind config for admin CSS build
│
├── deploy/                     # Deployment helpers
│   ├── htaccess.root           # Root .htaccess for shared hosting (Method A)
│   ├── nginx.sample.conf       # Nginx reference config
│   └── README-shared-hosting.md  # Shared hosting placement guide (Method A/B)
│
├── core/                       # System kernel — not web-accessible
│   ├── bootstrap.php           # Env init, dangerous-placement self-defense, session start
│   ├── Env.php                 # Custom .env parser (bcrypt-safe, no parse_ini_file)
│   ├── DB.php                  # PDO singleton (MySQL + PostgreSQL)
│   ├── Hash.php                # bcrypt / argon2id variable-cost hashing
│   ├── Crypto.php              # APP_KEY-based symmetric encryption (sodium / OpenSSL)
│   ├── Auth.php                # Super User-first auth + throttle + 2FA replay guard
│   ├── TwoFactorAuth.php       # Native TOTP — hash_hmac + pack() only
│   ├── Throttle.php            # auth_throttle: exponential backoff + lockout
│   ├── Session.php             # Multi-backend session handler (file/DB/Redis/Memcached)
│   ├── Gate.php                # Static RBAC — zero DB queries (Gate::can(); Super User always true)
│   ├── Security.php            # CSRF token generation + verification
│   ├── HtmlSanitizer.php       # DOM allowlist sanitizer (ext-dom; allows ruby/rt/rp + em.class=hbp-bouten)
│   ├── Locale.php              # Language pack scanner + frontend dictionary loader
│   ├── RouterCompiler.php      # Minimal-map route compiler (O(1), never on post save)
│   ├── ViewCompiler.php        # Template → PHP compiler (MUI bake-in + asset inline)
│   ├── View.php                # Template renderer with Auto-Healing
│   ├── AssetCompiler.php       # CSS/JS minifier and inline packer
│   ├── MarkdownCompiler.php    # Markdown → HTML (save-time; role-based raw HTML; ruby/bouten)
│   ├── CacheManager.php        # Precise FPC invalidation (post + pagination variants)
│   ├── AuthorCompiler.php      # Author singleton (authors.cache.php)
│   ├── TranslationCompiler.php # hreflang_cache sync for translation groups
│   ├── SecurityHeaderCompiler.php  # CSP + HSTS + auxiliary headers (pre-compiled)
│   ├── MenuCompiler.php        # 6-location navigation HTML compiler
│   ├── SidebarCompiler.php     # Widget HTML compiler (6 areas, per-language)
│   ├── BlockCompiler.php       # Reusable blocks → blocks.cache.php, expanded when the FPC is written
│   ├── LinkCompiler.php        # /go/{alias} → links.cache.php, served as a 302 without a DB query
│   ├── RedirectCompiler.php    # Old slugs → redirects.cache.php, served as a 301 without a DB query
│   ├── SpeakerCompiler.php     # :::say speakers → speakers.cache.php
│   ├── Series.php              # Series: order, previous / next, precise purge of what a change touches
│   ├── SeriesCompiler.php      # Series → series.cache.php (titles, status, counters)
│   ├── PostCompiler.php        # Re-rendering with the raw-HTML trust recorded per post; share text
│   ├── ShareText.php           # Social-post text sized for X's weighted character count
│   ├── PublishSlots.php        # Daily publishing slots, next free slot, the weekly schedule grid
│   ├── CommentCompiler.php     # Approved comment cache + FPC + sidebar sync
│   ├── SeoCompiler.php         # SEO/analytics tag compiler
│   ├── SiteFileCompiler.php    # sitemap.xml (index + split) / robots.txt generator
│   ├── FeedCompiler.php        # Atom 1.0 / RSS 2.0 / JSON Feed 1.1 per language and per category
│   ├── Scheduler.php           # Scheduled publishing: runDue + next_schedule.php
│   ├── CategoryCompiler.php    # Category metadata cache
│   ├── TagCompiler.php         # Tag metadata cache
│   ├── PluginManager.php       # Plugin registry + priority-sorted hook map compiler
│   ├── PluginInstaller.php     # zip-slip-safe ZIP extractor (+ Ed25519 signature verify)
│   ├── AccessControl.php       # Client IP resolution (trusted proxy aware) + IP allowlist
│   ├── IpBlocklist.php         # blocked_ips table: POST-type spam IP management
│   ├── SiteDenylistCompiler.php  # Compiled page-view denylist (zero DB on check)
│   ├── Migrator.php            # Schema migration runner (db:install / db:migrate / db:status; baseline V001000000)
│   ├── Installer.php           # Embedded installer (hidden; activates only when .env missing)
│   ├── AdminPostController.php # Post save orchestrator
│   ├── AdminUserController.php # Staff + 2FA management
│   ├── permissions.php         # Static RBAC matrix (18 permissions × 4 roles)
│   ├── cli.php                 # CLI entry point
│   │
│   ├── controllers/
│   │   └── CommentController.php   # Comment receipt: IpBlocklist → hook pipeline → double-lock → DB
│   ├── commands/
│   │   ├── AssetsRebuild.php
│   │   ├── LangRebuild.php
│   │   └── PluginsRebuild.php
│   ├── database/
│   │   ├── schema.mysql.sql        # full schema
│   │   ├── schema.pgsql.sql
│   │   └── migrations/             # post-release diffs only (V<major><minor><patch>__*.{driver}.sql)
│   ├── lang/
│   │   ├── admin_ja.php            # Admin UI — Japanese
│   │   ├── admin_en.php            # Admin UI — English
│   │   ├── frontend_ja.php         # Public theme UI — Japanese
│   │   └── frontend_en.php         # Public theme UI — English
│   ├── admin_views/                # Admin panel templates (Tailwind + Lucide inline SVG)
│   │   ├── admin.src.css           # Tailwind source (build → public/admin/assets/admin.css)
│   │   ├── login.tpl.php
│   │   ├── two_factor.tpl.php
│   │   ├── index.tpl.php
│   │   ├── edit.tpl.php
│   │   ├── profile.tpl.php
│   │   ├── users.tpl.php
│   │   ├── comments.tpl.php
│   │   ├── menu.tpl.php
│   │   ├── sidebar.tpl.php
│   │   ├── blocks.tpl.php
│   │   ├── links.tpl.php
│   │   ├── schedule.tpl.php        # Days × publishing slots
│   │   ├── templates.tpl.php       # Post templates
│   │   ├── speakers.tpl.php        # Speech-bubble speakers
│   │   ├── series.tpl.php          # Series: settings, order and chapters
│   │   ├── categories.tpl.php
│   │   ├── tags.tpl.php
│   │   ├── plugins.tpl.php
│   │   ├── seo.tpl.php
│   │   └── badwords.tpl.php
│
├── themes/
│   └── default/                    # Public-facing theme (hand-written CSS, no frameworks)
│       ├── header.tpl.php
│       ├── footer.tpl.php
│       ├── index.tpl.php
│       ├── post.tpl.php
│       ├── page.tpl.php
│       ├── category.tpl.php
│       ├── tag.tpl.php
│       ├── archive.tpl.php
│       ├── search.tpl.php
│       ├── series.tpl.php          # A series' table of contents (optional; other themes fall back to this one)
│       ├── series_nav.tpl.php      # Series title / previous · contents · next, included by post.tpl.php
│       ├── 404.tpl.php
│       └── assets/
│           ├── style.css           # {# inline_css 'style.css' #} → baked into FPC
│           └── app.js              # {# inline_js 'app.js' #}  → baked into FPC
│
├── plugins/                        # Plugin directory (web-inaccessible)
│   ├── hbp-helloworld/             # Bundled: minimal route plugin tutorial
│   ├── hbp-contact/                # Bundled: contact form (no DB INSERT, direct mail)
│   ├── hbp-antispam/               # Bundled: honeypot + DNS-BL + external API (3-layer)
│   ├── hbp-badwords/               # Bundled: comment bad-word filter
│   ├── sample-related-posts/       # Bundled: related posts implementation sample
│   └── DEVELOPER.md                # Plugin API guide: 3 rules, hook protocol, manifest spec
│
├── storage/                        # Non-public area — deny all in web server config
│   ├── upload/                     # Draft media (never web-accessible)
│   ├── logs/                       # spam.log, csp.log, plugin-audit.log
│   ├── comments/                   # {post_id}.cache.php — compiled approved comments
│   └── cache/
│       ├── routes.cache.php
│       ├── plugins.cache.php
│       ├── hooks.cache.php
│       ├── menus.cache.php
│       ├── categories.cache.php
│       ├── tags.cache.php
│       ├── authors.cache.php       # Author singleton
│       ├── security_headers.cache.php
│       ├── site_denylist.cache.php
│       ├── seo_head.cache.php
│       ├── seo_global.cache.php
│       ├── two_factor_global.cache.php
│       ├── sidebar_{location}_{lang}.cache.php
│       ├── widget_archive_{lang}.cache.php
│       ├── widget_comments_{lang}.cache.php
│       ├── blocks.cache.php        # Reusable blocks
│       ├── links.cache.php         # /go/ alias table
│       ├── redirects.cache.php     # Old slug → current URL (301)
│       ├── speakers.cache.php      # :::say speakers
│       ├── plugin_snippets.cache.php
│       ├── pages/                  # FPC: {lang}_{type}_{slug}.html (+ _p{n} variants)
│       │   └── .locks/             # Stampede lock files
│       └── views/                  # MUI-baked view caches
│           ├── admin/{locale}/{md5}.cache.php
│           └── frontend/{locale}/{md5}.cache.php
│
└── public/                         # Web server document root
    ├── index.php                   # Frontend entry point (HBP_BASE_PATH aware)
    ├── sitemap.xml                 # Sitemap index (or single file for small sites)
    ├── sitemaps/                   # Split sub-sitemaps (posts-1.xml, pages.xml, …)
    ├── robots.txt
    ├── feed.atom.xml · feed.rss.xml · feed.json   # Default-language feeds ({lang}/ for the others)
    ├── feeds/{lang}/category/      # Per-category feeds of feed-enabled categories
    ├── .htaccess                   # Apache rewrite rules (clean URLs)
    ├── comment_post.php
    ├── assets/
    │   ├── site/avatars/           # Author avatars (always public), {ab}/{cd}/ sharded & EXIF-stripped via the unified upload API. Super User avatar goes here too — set SUPER_USER_AVATAR to the filename only
    │   ├── site/avatar-default.svg # Bundled commenter avatar silhouette (fill="currentColor"); no external Gravatar
    │   ├── articles/               # Published post media (moved from storage on publish)
    │   └── theme/                  # External CSS (CSS_DELIVERY=external mode)
    └── admin/
        ├── index.php               # Admin panel entry point (HBP_BASE_PATH aware)
        └── assets/                 # Admin static assets (Tailwind local build)
            └── admin.css           # Build: ./tailwindcss -i core/admin_views/admin.src.css -o ...
```

---

## Database Schema

### Schema Versioning & Migrations (M-series)

HBP tracks schema state in the `schema_migrations` table so the system always knows **exactly which schema version a database is at** — and therefore what (if anything) still needs to be applied. There is a single source of truth, established three ways.

#### Version identifier format

Versions are `V` + zero-padded **major(3)·minor(3)·patch(3)** = a fixed-width 10-char string that sorts lexicographically in semantic order:

| Semantic version | Migration id |
|---|---|
| 1.0.0 | `V001000000` |
| 1.2.5 | `V001002005` |
| 2.0.0 | `V002000000` |

#### The baseline: full schema, floor fixed at 1.0.0

The full schema files (`schema.mysql.sql` / `schema.pgsql.sql`) always describe the **current** schema — every diff migration is folded into them as well. The floor is fixed at **`V001000000` (version 1.0.0)**, the first release. Each full schema file ends with a **baseline INSERT** that records the floor **and every diff the file already contains**:

```sql
-- end of schema.{driver}.sql
INSERT ... INTO schema_migrations (version, name, checksum, applied_at)
  VALUES ('V001000000', 'baseline: full schema (HBP 1.0.0)', 'BASELINE', <now>),
         ('V001003000', 'widgets_layouts_links',             'BASELINE', <now>),
         ('V001004000', 'seo_feeds_templates',               'BASELINE', <now>),
         ('V001005000', 'series',                            'BASELINE', <now>);
```

- `checksum = 'BASELINE'` is a sentinel meaning "this row came from a full schema, not a diff migration" — checksum verification skips `BASELINE` rows.
- Because `db:install` applies the full schema **including this INSERT**, a fresh database immediately reports itself as the current version (`V001005000`) = fully up to date. `db:migrate` then correctly finds **nothing pending** (it will never try to re-add existing columns).

#### The three guarantees

| Mechanism | Role |
|---|---|
| **Baseline INSERT** in each full schema | A freshly installed DB records its version (the `V001000000` floor plus every folded diff) atomically with the schema — no "unknown version" gap. |
| **`SCHEMA_VERSION` constant** (`core/Schema.php`) | The version the *code* expects. Startup/CLI compares it with the latest applied row and warns on mismatch (code vs. DB drift). |
| **`db:status` report** | Prints DB version, code-expected version, driver, and any pending migrations with the exact command to run — answers "how far do I need to migrate?" at a glance. |

<a id="diff-migrations"></a>
#### Diff migrations

When a release changes the schema, diff files are added under `migrations/` named **`V<major><minor><patch>__description.<driver>.sql`** (e.g. `V001003000__widgets_layouts_links.mysql.sql`), applied in ascending `V…` order, forward-only. `db:migrate` applies any `V…` not present in `schema_migrations`, recording each with the file's real SHA-256 (so later tampering is detectable). The fixed `V001000000` baseline remains the floor.

The full schema files always describe the latest version and record every diff as a `BASELINE` row, so a fresh install never replays them.

| Version | Migration | Changes |
|---|---|---|
| 1.3.0 | `V001003000__widgets_layouts_links` | `sidebar_widgets.title` / `settings`; `posts.layout` / `show_toc` / `is_sponsored`; new tables `blocks` and `links` |
| 1.4.0 | `V001004000__seo_feeds_templates` | `posts.meta_description_auto` / `share_text` / `share_text_auto` / `raw_html_allowed` / `content_saved_by`; `categories.hashtags` / `feed_enabled`; new tables `post_templates`, `slug_redirects` and `speakers`. Then run `posts:recompile --reassess` once |
| 1.5.0 | `V001005000__series` | New table `series`; `posts.series_id` / `series_order` / `series_part` (`idx_posts_series`) / `char_count` / `reading_minutes`. Then run `posts:recompile` once |

> This stays within HBP's constitution: pure PHP + PDO, raw SQL, zero external tooling (no Flyway/Liquibase).

### Tables

| # | Table | Purpose |
|---|---|---|
| 1 | `users` | Staff accounts (Super User is `.env`-only) |
| 2 | `categories` | Categories (`type` = post \| page, `lang` column, `hashtags` for share text, `feed_enabled` for a per-category feed) |
| 3 | `tags` | Tags (`type` = post \| page, `lang` column) |
| 4 | `posts` | All content — posts and pages unified via `type` |
| 5 | `post_tags` | Tag-to-post join table |
| 6 | `attachments` | Media files (hashed filenames). **Source of truth for publish/unpublish moves and `media:gc`** |
| 7 | `sessions` | DB-backed session storage |
| 8 | `comments` | Comments with approval workflow (`author_email` nullable — optional since) |
| 9 | `navigation_menus` | Navigation items for 6 locations |
| 10 | `sidebar_widgets` | Widget configuration: area, order, optional `title`, per-type `settings` (JSON) |
| 11 | `auth_throttle` | Login / 2FA rate limiting |
| 12 | `blocked_ips` | POST-type spam IP blocklist |
| 13 | `schema_migrations` | Migration tracking with checksum verification |
| 14 | `blocks` | Reusable content blocks (`slug` + `lang` unique, Markdown body) |
| 15 | `links` | Managed outbound links (`alias` unique → `target_url`) |
| 16 | `post_templates` | Starting points for new posts (copied into the post, never linked) |
| 17 | `slug_redirects` | Old slugs of published posts → `post_id`, served as 301 |
| 18 | `speakers` | Speakers of `:::say` speech bubbles (`slug` unique, name, avatar, side) |
| 19 | `series` | Series (`lang` + `slug` unique, description, status, cover, `feed_enabled`, `post_count` / `char_count` counters) |

### Key `posts` columns

| Column | Purpose |
|---|---|
| `type` | `post` or `page` |
| `lang` | Content language code (`ja`, `en`, …) |
| `status` | `published` / `draft` / `scheduled` |
| `published_at` | Post date — single source of truth (UTC). `NOW()` on publish, future time when scheduled, `NULL` for drafts. Drives all sort/display |
| `content_html` | Sanitized HTML compiled from Markdown at save time |
| `tags_cache` | JSON — eliminates `post_tags` JOIN at render time |
| `trans_group` | UUID linking translation siblings |
| `hreflang_cache` | JSON of sibling URLs, baked at save time |
| `featured_image` | Featured image relative path. `NULL` → fallback (first body image → site logo) resolved **at save time**; drives `og:image` |
| `featured_image_alt` | Featured image alt text (a11y/SEO) |
| `layout` | Theme layout id (`NULL` = the theme's default; unknown ids fall back to it) |
| `show_toc` | Insert a table of contents at save time |
| `is_sponsored` | Print the sponsored disclosure and mark external links `rel="sponsored"` |
| `meta_description_auto` | Excerpt of the body baked at save time; used when `meta_description` is empty |
| `share_text` / `share_text_auto` | Social-post text typed by the editor / generated at save time (JSON Feed `_hbp.share_text`) |
| `raw_html_allowed` / `content_saved_by` | Whether the last body save was by a holder of `post:raw_html`, and who (`0` = Super User). Recompiles use this, not anyone's current role |
| `series_id` / `series_order` / `series_part` | Series membership, position (spaced by 10) and chapter heading. Deleting the series sets `series_id` to `NULL` |
| `char_count` / `reading_minutes` | Characters of the rendered body and the reading time, computed at save time |
| `user_id` | `NULL` = Super User. `ON DELETE RESTRICT` — reassignment required before staff deletion |

> Index `idx_posts_schedule (status, published_at)` drives the `WHERE status='scheduled' AND published_at <= NOW()` lookup; all list indexes sort by `published_at DESC`.

---

## Security

| Threat | Defense |
|---|---|
| SQL injection | Parameterized PDO queries (no ORM, no string interpolation) |
| Stored XSS | DOM allowlist sanitizer (`ext-dom`) at save time + `{{ }}` auto-escape in templates. Ruby/bouten markup compiles to `<ruby>`/`<em class=hbp-bouten>` with attribute values allowlisted |
| CSRF | `Security::csrfField()` in **every** admin POST form — including the login and 2FA screens (soft-verified: a token mismatch re-renders the form instead of a hard 403) + `SameSite=Lax` cookies |
| Input validation | Server-side validation on all admin save paths, derived from the schema's own constraints: enum allowlists (`type`, `status`, `role`, menu/sidebar locations, widget keys), `VARCHAR(n)` length caps, required `NOT NULL` fields, and `UNIQUE` collisions caught instead of surfacing a 500. Menu/SNS URLs are restricted to `http(s)`/relative schemes (no `javascript:`), and emails are format-checked |
| Brute-force login | `auth_throttle`: exponential backoff per IP + per account; UI shows lockout countdown |
| 2FA replay | `users.tfa_last_step` prevents same time-step reuse (RFC 6238) |
| 2FA brute-force | 5 failures destroy the temporary auth session |
| Session fixation | `session_regenerate_id(true)` on every successful login |
| Timing attacks | Dummy-hash verification when user not found; uniform response time |
| Path traversal (media) | `basename()` + extension allowlist on all uploads |
| Path traversal (plugin ZIP) | `PluginInstaller` validates every entry — `../`, absolute paths, symlinks, and zip-bombs rejected |
| Comment spam | Honeypot field + `before_comment_create` hook pipeline + IP blocklist |
| CSP bypass | Pre-compiled hash-based CSP; `report-only` → `enforce` staged rollout |
| Admin panel discovery | `ADMIN_PATH` in `.env` to obscure the login URL — any value works (front-controller routing); the physical directory name is not exposed as a back door |
| Admin access (optional) | `ADMIN_IP_ALLOWLIST` + `REQUIRE_ADMIN_2FA` |
| Draft media leakage | `storage/upload/` — web server denies all access; PHP-mediated delivery only. Uploads follow the same isolation + `media:manage` gate. **Unpublishing moves media back to `storage/upload/`** so non-public posts' media is never web-reachable |
| `.env` leakage (shared hosting) | Method B (app outside web root) / Method A (`.htaccess`) + bootstrap self-defense + installer self-diagnosis |
| XML-RPC attacks | File does not exist — not a single line of code |
| Privilege escalation | Static RBAC (`Gate::can()`, `permissions.php`) — 18 permissions × 4 roles; ownership-scoped checks; Super User via `.env` only |
| Super User DB exposure | Super User is `.env`-only; DB compromise cannot yield root credentials |

### Content Security Policy

HBP uses a **pre-compiled, hash-based CSP** compatible with FPC (no per-request nonce generation).

```ini
# .env — staged rollout recommended
CSP_MODE=report-only   # Step 1: monitor violations at /csp-report → storage/logs/csp.log
CSP_MODE=enforce       # Step 2: after logs are clean
```

After theme CSS/JS changes:

```bash
php core/cli.php assets:rebuild   # recomputes SHA-256 hashes + purges FPC
```

Off-site sources are opt-in, comma-separated host lists (malformed entries are ignored with a warning in the error log):

```ini
CSP_IMG_HOSTS="pics.example.com,*.cdn.example.net"   # img-src — e.g. partner banners
CSP_SCRIPT_HOSTS=""                                  # script-src for <script src>; keep empty unless needed
EMBED_HOSTS="www.youtube-nocookie.com,player.vimeo.com"  # frame-src
```

Inline `<script>` / `<style>` bodies in plugin snippets and raw-HTML widgets are hashed automatically when they are compiled — nothing to list by hand. **Inline `style="…"` attributes are not covered by hashes and are blocked under `enforce`**, so themes and snippets should use classes instead.

### Admin Panel CSS (Production)

In production, build Tailwind locally for a strict `style-src 'self'` CSP:

```bash
./tailwindcss -i core/admin_views/admin.src.css -o public/admin/assets/admin.css --minify
```

Without this file, the admin panel falls back to Tailwind Play CDN (development only).

---

## Plugin Development

### Manifest (`manifest.json`)

```json
{
  "id": "my-plugin",
  "name": "My Plugin",
  "version": "1.0.0",
  "author": "Your Name",
  "description": "Does something useful.",
  "license": "AGPL-3.0-or-later",
  "status": "enabled",
  "hooks": {
    "before_comment_create": {
      "file": "plugins/my-plugin/plugin.php",
      "class": "MyPlugin",
      "method": "checkComment",
      "priority": 10
    }
  }
}
```

The `file` of a hook, a route or a `template_route` must be a `.php` file inside the plugin's own directory. Any other path is skipped at `plugins:rebuild` (with a warning in the PHP error log).

### Static Snippets

A plugin can add fixed HTML to the `<head>` or the end of `<body>` on every public page:

```json
"snippets": {
  "head": "snippets/head.html",
  "body_end": "snippets/body_end.html"
}
```

The files are read once at `plugins:rebuild` (64 KiB per file, must stay inside the plugin directory) and baked into the page cache — no PHP runs per request. Inline `<script>` / `<style>` inside a snippet is added to the CSP hashes automatically.

Themes must print the slots and the widget areas themselves:

```php
<?php echo PluginManager::snippet('head'); ?>        <!-- just before </head> -->
<?php echo PluginManager::snippet('body_end'); ?>    <!-- just before </body> -->
{{{ SidebarCompiler::areaFor('content_before', $lang ?? '', $layout ?? null) }}}
```

The same goes for series. A theme without `series.tpl.php` gets the bundled theme's table of contents (inside its own header and footer, under its own CSS — style the `hbp-series-*` classes), but the previous / next links on a post appear only where the theme's `post.tpl.php` prints them: `{% include 'series_nav' %}`, or its own markup from `$post['series']` (`title`, `url`, `status`, `part`, `prev` / `next` = `{title, url}` or null).

### The 3 Rules

1. **No runtime I/O scans** — `glob()` / `scandir()` must not run per request. All discovery happens at compile time (`plugins:rebuild`).
2. **No DB queries for config** — Read from `.env` or static caches only.
3. **No global state or core patching** — Operate only within your plugin directory and the declared URI space.

### Hook Protocol (`&$context`)

```php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

class MyPlugin
{
    // Core passes a reference — your plugin can intercept or filter
    public static function checkComment(array &$context): void
    {
        $ip = $context['ip_address'];
        if (isSpam($ip)) {
            http_response_code(403);
            exit;  // Interceptor: abort before DB is touched
        }
        // Filter: rewrite context and pass to next hook
        $context['post_data']['content'] = censor($context['post_data']['content']);
    }
}
```

### Available Hooks

| Hook | Fires at |
|---|---|
| `before_comment_create` | Comment controller, before DB insert |
| `before_contact_send` | Contact form plugin, before `mb_send_mail()` |
| `post_saved` | End of a post/page save, after every cache is rebuilt (notification only — cannot cancel the save) |

### Bundled Plugins

| Plugin | License | Description |
|---|---|---|
| `hbp-helloworld` | AGPL-3.0-or-later | Minimal route plugin — 3-minute tutorial and scaffold |
| `hbp-contact` | AGPL-3.0-or-later | Contact form: no DB INSERT, direct `mb_send_mail()` to Super User email |
| `hbp-antispam` | AGPL-3.0-or-later | 3-layer defense: honeypot → DNS-BL (UDP, timeout-controlled, fail-open) → external API |
| `hbp-badwords` | AGPL-3.0-or-later | Bad-word filter hooked into `before_comment_create` and `before_contact_send` |
| `sample-related-posts` | AGPL-3.0-or-later | Related posts implementation sample (demonstrates FPC hook integration) |

After installing or toggling a plugin:

```bash
php core/cli.php plugins:rebuild
```

---

## Template Syntax

HBP compiles its own lightweight template syntax to plain PHP — no external engine.

| Syntax | Compiled to |
|---|---|
| `{{ $var }}` | `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')` |
| `{{{ $var }}}` | `echo $var` (raw HTML output) |
| `{@ key @}` | Language string baked in at compile time — zero runtime lookup |
| `{% if $cond %}` … `{% endif %}` | `if / endif` |
| `{% foreach $arr as $v %}` … `{% endforeach %}` | `foreach / endforeach` |
| `{% include 'name' %}` | `View::render('name', get_defined_vars())` |
| `{# inline_css 'file.css' #}` | `<style>` + minified CSS content |
| `{# inline_js 'file.js' #}` | `<script>` + minified JS content |

### Markdown extensions (resolved at save time)

| Write (on its own line) | Result |
|---|---|
| `{{ block:slug }}` | Reusable block, expanded when the page cache is written |
| `[[button:Label\|URL\|note]]` | Call-to-action button with an optional note above it |
| `{{ card:slug }}` | Card linking another published post of the same language (baked; a missing slug is reported in the editor) |
| `:::quote Source\|URL` … `:::` | `<figure>` with a `<blockquote cite>` and a `<figcaption>` citing the source |
| `:::box note\|summary\|warning\|conclusion Title` … `:::` | Box with an optional title (other types fall back to `note`) |
| `:::say speaker [left\|right]` … `:::` | Speech bubble of a speaker registered in **Admin → Speakers** |

Containers do not nest: the first `:::` line closes the open one. The editor toolbar inserts each of them.

The `{@ key @}` MUI system bakes language strings directly into the compiled PHP cache at `ViewCompiler::compile()` time. At render time the template is already translated — no array lookup, no function call.

---

## Furigana (Ruby) & Emphasis Dots

For Japanese novels and prose, HBP supports **ruby (furigana)** and **emphasis dots (bouten / 傍点)** using the markup conventions popularized by Japanese novel platforms (Kakuyomu, Shōsetsuka ni Narō). The markup is **compiled to HTML once at save time** inside `MarkdownCompiler::compile()` and baked into `posts.content_html` — **zero runtime cost** on the FPC fast path. It applies to **both posts and pages** (the editor is unified; `type` only selects post vs. page — the body pipeline is identical).

### Input Markup

| Purpose | Markup | Notes |
|---|---|---|
| Ruby (explicit base) | `｜漢字《かんじ》` | `｜` (full-width) or `\|` (half-width) marks the start of the base text |
| Ruby (auto base) | `漢字《かんじ》` | With `｜` omitted, the immediately preceding run of kanji is taken as the base (Narō-compatible) |
| Emphasis dots (bouten) | `《《文字》》` | Double angle brackets put emphasis dots over the enclosed text |
| Literal `《` | `\《` | Backslash-escape to output a literal bracket |

**Parsing order (important):** code spans/blocks are excluded first → `《《 》》` (bouten) → `《 》` (ruby). Bouten is matched before ruby so the double-bracket form is never mis-parsed as a ruby. Markup inside fenced code/inline code is left untouched.

### Compiled HTML

```html
<!-- ｜漢字《かんじ》 -->
<ruby>漢字<rp>(</rp><rt>かんじ</rt><rp>)</rp></ruby>

<!-- 《《文字》》 -->
<em class="hbp-bouten">文字</em>
```

- `<rp>` parentheses provide a graceful fallback in readers that do not support ruby.
- Bouten uses a **class, never an inline `style`** — inline `style` is stripped by the sanitizer (stored-XSS defense) and would conflict with CSP. The visual is supplied by theme CSS.

### Sanitizer Allowlist (required)

`HtmlSanitizer` must allow the generated markup, or it would be stripped:

- Allowed tags: `ruby`, `rt`, `rp` (text only, no attributes)
- `em` may carry a `class` attribute, but **the value is allowlisted** (`hbp-bouten` only) — arbitrary class values are rejected, closing the class-based injection vector.

This "allow the tag, strictly restrict the attribute" rule is what lets the feature coexist with the XSS defense. Because the result is frozen into `content_html`, running `posts:recompile` re-applies the markup after any rule change.

### Theme CSS

```css
.hbp-bouten {
  font-style: normal;                 /* not italic */
  text-emphasis: filled sesame;       /* goma-ten; use 'filled dot' for round dots */
  -webkit-text-emphasis: filled sesame;
  text-emphasis-position: over right; /* vertical-writing themes may use 'right' */
}
rt { font-size: 0.5em; }              /* ruby text size, theme-tunable */
```

### Editor & Preview (posts and pages)

- The **new-post and edit screens** (same unified editor for `type = post | page`) gain a toolbar with **Ruby** and **Emphasis** buttons that wrap the current selection (`｜sel《》` / `《《sel》》`), plus a short inline help line.
- The **live preview** runs the **same** `MarkdownCompiler::compile()` (via a preview endpoint) so what you see matches the saved output exactly — no separate client-side ruby parser to drift out of sync. The preview pane loads the theme's `.hbp-bouten` / `rt` CSS so it looks identical to the published page.

### Public Page

The published article/page renders the already-baked `<ruby>` / `<em class="hbp-bouten">` from `content_html` — no runtime processing. Theme CSS provides the visuals.

### Scope & Limitations

- Nesting (e.g. bouten inside a ruby base) is **not supported** — kept simple by design.
- In feeds: when full-content feeds are enabled (`FEED_FULL_CONTENT=true`), `<ruby>` is included and renders in most readers; **bouten depends on CSS and will typically not show dots in feed readers** — accepted as a known limitation.

---

## Post Editor

The new-post and edit screens share **one unified editor**; `type` (post | page) selects the content type but the body pipeline is identical. The design follows a deliberate philosophy: **plain text in, no surprises.**

### Title / Body Separation

Title and body are **separate input fields**, persisted to separate columns (`posts.title`, `posts.content_markdown`). This keeps the data model clean and lets the template own semantics:

- The **title** field renders as the page `<title>` and the single `<h1>`.
- The **body** is authored in Markdown starting at `##` (`<h2>`), so there is never more than one `<h1>` — an SEO-correct structure by construction.
- The title input is large/bold (≈24–30px) to echo the published `<h1>`; pressing **Enter** in the title moves focus to the body (never submits the form).

### Plain-Text Body + Separated Live Preview

HBP deliberately **does not** use note/Notion-style in-place rich conversion (where typing `##` instantly morphs the text). The body stays a **monospace, visually stable plain-text area** — what you type never reflows under you. A **separate preview pane** renders the result, optionally with **synchronized scrolling**.

- The preview calls the **same server-side `MarkdownCompiler::compile()`** (via a preview endpoint) — identical output to what is saved, including ruby/bouten. No client-side parser to drift out of sync.
- This matches HBP's transparency principle: the author always sees exactly the Markdown they wrote.

### Layout (PC)

A 3-column "cockpit": collapsible left nav (240px ↔ 60px) · center editor (constrained to ~720px, matching the published body width so line breaks preview accurately) · right settings drawer (status, schedule, category/tags, featured image, SEO) that opens/closes. On tablet the left nav collapses to icons; on phones nav becomes a drawer and the settings panel becomes a bottom sheet, with a formatting toolbar floating above the soft keyboard.

### Image Drag-and-Drop

Dropping an image onto the body:

1. Uploads it asynchronously to `POST /admin/api/upload` (gated by `media:manage` + CSRF). The server validates the extension allowlist, generates a hashed filename (`bin2hex(random_bytes(16)).ext`), saves it to `storage/upload/` (web-inaccessible), and **records a row in `attachments`** (the source of truth — see Media Lifecycle).
2. Inserts the **production URL** `![alt](/assets/articles/{file})` at the cursor. **The Markdown stored in the DB always carries the production URL** — it is never rewritten on publish.
3. Offers the first uploaded image as the featured-image candidate (right drawer).

> Because the body always holds the production path, publishing never has to touch the post text — only the files move. This keeps `content_html` (and therefore the FPC) stable.

### Live Preview of Draft Images

A freshly uploaded image is still in `storage/upload/` (not yet under `public/`), so the production URL would 404 in preview. The **preview compiler (admin-only)** therefore rewrites `/assets/articles/{file}` → `/admin/media/draft-image?file={file}` for files not yet published, and that admin-only endpoint `readfile()`s them from `storage/upload/`. This rewrite happens **only in the admin preview path** — the public FPC fast path is never involved, and the DB Markdown stays clean.

### Autosave

The draft is autosaved periodically (server-side `status='draft'`, plus optional browser local storage as a transient buffer). The save endpoint must be allowed by CSP `connect-src`.

### Attachment List & Delete

Directly below the body textarea, the editor shows a thumbnail list of **the images attached to the content being edited** — the list's source of truth is `attachments` (the rows for this `post_id`). Removing an `![]()` from the body by hand leaves the file and its `attachments` row behind, so the editor provides an active way to manage them (without waiting for `media:gc`).

- **Display:** thumbnail + filename per item. Draft images are served from the admin-only secure route (`storage/upload/`), published ones from `public/` (shard path resolved by `shardPath()`). Mobile uses a grid.
- **Insert into body:** inserts `![alt](/assets/articles/{ab}/{cd}/{file})` at the cursor, reusing the same image without re-uploading.
- **Physical delete (confirmation required):** deletes the `attachments` row **and** the actual files (`storage`/`public`, shard path). A confirm dialog is mandatory. If the target is still referenced in the body (`content_markdown` is scanned client-side), the warning is strengthened. The body `![]()` is **not auto-rewritten** — removing broken links is left to the author (safe by design). API: `POST /admin/api/media/delete`, `media:manage` + CSRF; own content only without `post:edit_other`.
- **Relation to `media:gc`:** manual delete is immediate; abandoned orphans are reclaimed later by `media:gc`. Both treat `attachments` as the source of truth.

### Character Count

The current length is shown live at the top-left of both the title and body fields.

- **Counting:** by **code point** (`[...str].length`) so CJK (multibyte) and half-width each count as 1. `str.length` (UTF-16 units) is deliberately avoided (it counts emoji as 2). Computed client-side only — zero server / FPC impact.
- **Title hard cap:** `posts.title` is `VARCHAR(255)`. 255 code points is the hard cap (`maxlength`), with a caution color past 230 and a warning color at 255.
- **Body soft guide:** no hard cap; instead a recommended-range hint (light under ~1,000 / standard ~2,000–3,000 / comprehensive ~3,000–5,000). This is guidance, not a limit.

---

## Media Lifecycle

Media moves between the web-inaccessible draft area and the public area in lockstep with a post's status. The **`attachments` table is the single source of truth** for which files belong to a post; a body regex over `content_markdown` is used only as a secondary cross-check.

### Source of Truth

- Every uploaded file has an `attachments` row (`post_id`, `filename`, …). Publish/unpublish moves are driven by **querying `attachments` for the post**, not by parsing the body. This is robust against body edits, tampering, and copy-paste.
- A secondary regex (`!\[..\]\(/assets/articles/([a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp|gif))\)`) may validate that referenced files are accounted for, but it never overrides the table.

### On Publish (`draft`/`scheduled` → `published`)

For each `attachments` file currently in `storage/upload/`, **atomically `rename()`** it to `public/assets/articles/` (same partition → O(1), no corrupt intermediate state). Idempotent: files already public are skipped (`file_exists` guard). The featured-image fallback (first body image → site logo) is **resolved before the move** so the baked path matches the final public location.

### On Unpublish (`published` → `draft`/`scheduled`)

To preserve strict physical isolation, the reverse move is performed: for each of the post's `attachments` files in `public/assets/articles/`, **atomically `rename()`** it back to `storage/upload/`. This guarantees that media belonging to a non-public post is never web-reachable. (Shared files are owned per-post via `attachments`; a file is moved back only when no other *published* post references it.)

### Orphan Garbage Collection (`media:gc`)

Draft files can be orphaned (uploaded, then removed from the body before publish; or abandoned drafts). `media:gc [--days=N]` deletes files in `storage/upload/` that **no `attachments` row references** and that are older than N days (default 7), reclaiming space. It never touches `public/assets/articles/` and never deletes files still referenced by `attachments`. Since the scan recurses the `{ab}/{cd}/` shard tree (and any leftover flat files), with `attachments` remaining the source of truth.

### Directory Sharding & Auto-Provisioning

All three media parents — `storage/upload/`, `public/assets/articles/`, `public/assets/site/avatars/` — fan out into `{ab}/{cd}/` subdirectories derived from the filename prefix via a single `shardPath()` (`shardPath("abcd….jpg") → "ab/cd/abcd….jpg"`). The DB never stores the path — only the bare filename — so save, serve, atomic publish/unpublish moves, GC and the search index are all derived. Public URLs become `/assets/articles/{ab}/{cd}/{file}`.

Before each write, `ensureDir()` recursively provisions the destination (parent + shard intermediates), mode `MEDIA_DIR_MODE` (default `0755`). The order is **ensure dir → file saved → DB record**, so a permission/space failure returns a clear error and leaves no orphan `attachments` row. `php core/cli.php db:status` and the browser installer pre-verify the three parents are creatable and writable.

To migrate an existing flat install, run `php core/cli.php media:reshard` (use `--dry-run` first): it moves files into the shards, rewrites flat→shard public URLs in post bodies and featured images, then regenerates the search index, list FPCs, sitemap and feeds. It is idempotent.

### Ordering & Atomicity

The publish sequence is fixed: **(1) resolve featured-image fallback → (2) move files per `attachments` → (3) save post row → (4) precise FPC purge**. All file moves are same-partition `rename()` (atomic). Nothing in this lifecycle runs on the public FPC fast path.

---

## Featured Image & OGP

Each post/page may carry a **featured image** (`posts.featured_image` + `featured_image_alt`), used for the social share card (`og:image` / `twitter:image`) and list thumbnails.

### Setting & Storage

- Set in the **right-side settings drawer**: drag-and-drop upload, thumbnail preview, and an **OGP preview** showing how the share card will look.
- Stored as a relative path in `featured_image` (distinct from the general `attachments` table — featured image is a single, role-specific slot).

### Fallback Chain (resolved at save time)

When `featured_image` is `NULL`, HBP resolves a fallback **once at save time** and bakes the result — it never scans the body at request time (preserving the zero-runtime FPC fast path):

1. The post's **first body image**, else
2. the **site logo** (a sensible default so the share card is never empty).

### OGP Output

`<meta property="og:image">`, `og:title`, `og:description`, and `twitter:card` are emitted into `<head>` from baked values (no runtime DB lookup), consistent with the pre-compiled architecture.

---

## Responsive Layout & Typography

This is a **theme-CSS guideline** (no schema impact). HBP already inline-packs or externally serves theme CSS; this section defines the intent. Public and admin surfaces have opposite goals — *comfortable consumption* vs. *dense production*.

### Public Site (reader-facing)

| Aspect | Guideline |
|---|---|
| Container | max 1100–1200px, centered; headers/hero may span 100% |
| Body column | **~720px** max (visual comfort; eyes track short lines) |
| Body font | `clamp(1rem, 1vw + 0.75rem, 1.125rem)` → 16→18px, line-height ~1.8 |
| Headings | H1 32–36px on PC; 24–28px on phones |
| Breakpoints | Two anchors (~768px, ~1024px); fluid (`%`/`vw`/`clamp()`) in between — avoid hard device cutoffs |

### Admin Panel (operator-facing)

| Aspect | Guideline |
|---|---|
| Width | Liquid full-width (use the whole screen) |
| Layout | Fixed left nav 240px ↔ collapsible 60px; phone = hidden drawer |
| Density | Labels/text **14px**, table data 12px — high information density |
| Editor input | The body textarea stays ~16px for comfortable writing |
| Mobile inputs | **All form inputs ≥16px** — below 16px iOS auto-zooms and wrecks usability |

Fluid typography is pure CSS — no CSP impact. When CSS is inline-packed, the existing `SecurityHeaderCompiler` re-hashes it.

---

## Favicon

Favicon images live in **`public/assets/site/`** (served at `/assets/site/…`, same place as avatars). The `<link rel="icon">` / `<link rel="apple-touch-icon">` tags are declared in the header templates:

- Public theme: `themes/default/header.tpl.php`
- Admin: `core/admin_views/_layout_head.tpl.php`, `login.tpl.php`, `two_factor.tpl.php`

**Check those templates for the exact filenames referenced, and place matching files in `public/assets/site/`.** As shipped, the templates expect:

```
public/assets/site/favicon-16x16.png
public/assets/site/favicon-32x32.png
public/assets/site/favicon-96x96.png
public/assets/site/apple-touch-icon-180x180.png
```

> An **HayabusaPress favicon is bundled provisionally** so the project works out of the box — replace these files with your own (keep the same filenames, or update the `<link>` tags in the templates above to match). After editing templates, run `php core/cli.php assets:rebuild` (public) / `php core/cli.php lang:rebuild` (admin) so the cached views/FPC pick up the change.

---

## Multi-Locale

### URL Structure

```
SITE_DEFAULT_LANG=ja

Japanese: /post/hello-world             (default — no prefix)
English:  /en/post/hello-world          (non-default — /en/ prefix)
```

### Adding a Language

Drop two files in `core/lang/` and run `system:init`. No code changes needed.

```
core/lang/
├── admin_ja.php       ← bundled
├── admin_en.php       ← bundled
├── frontend_ja.php    ← bundled
├── frontend_en.php    ← bundled
├── admin_es.php       ← add this to unlock Spanish
└── frontend_es.php    ←
```

### Translation Groups & hreflang

Link translated posts together in the editor (`trans_group` UUID). HBP then:

- Generates `posts.hreflang_cache` (JSON of sibling URLs) at save time
- Outputs `<link rel="alternate" hreflang="...">` tags in `<head>`
- Shows a visible language switcher linking only to *existing* translations

Both `hreflang` tags and switcher links share one source of truth — no duplicated configuration.

---

## Intentional Omissions

HBP deliberately excludes:

- ORM (Eloquent, Doctrine, etc.)
- Composer packages (backend)
- Heavy template engines (Twig, Smarty, Blade)
- XML-RPC (not a single line)
- Public user registration (no front-end sign-up; RBAC governs **staff** accounts only)
- Full plugin load on every request
- Runtime Markdown parsing
- External 2FA libraries (Google2FA, etc.)
- reCAPTCHA / external CAPTCHA
- Session-based language selection for public pages (FPC-incompatible)
- WordPress-style generic meta tables (EAV pattern)

---

---

## License

HayabusaPress is open-source software licensed under the **[AGPLv3 with Plugin Exception](./LICENSE)**.

```
SPDX-License-Identifier: AGPL-3.0-or-later
```

Every source file carries this SPDX identifier.

### 🟢 Core & Official Plugins — GNU AGPL v3

The core, the official plugins (`hbp-*`, "HayabusaPress Official") and the official themes are all licensed under the GNU Affero General Public License v3.0 or later.

- **Network copyleft:** AGPLv3 Section 13 closes the "ASP loophole" of the GPL. If you modify HayabusaPress and offer it to users over a network, you must make your modified source available to those users.
- **Unmodified use:** Running HayabusaPress as-is to publish your own site triggers no disclosure obligation.

### 🔵 Third-Party Plugins & Themes — your choice of license

As a special exception, third-party extensions that merely interact with HayabusaPress through the standard hooks, APIs, or class extensions are **not** required to be licensed under the AGPLv3.

- **Freedom of licensing:** Distribute and sell your extensions under MIT, Apache 2.0, GPLv3, or a proprietary/commercial license — whatever you choose.
- **No disclosure obligation** for such independent extensions.
- **Branding:** The exception does not grant the right to use the "HayabusaPress Official" name or the `hbp-` prefix for third-party extensions.

> ⚠️ **The exception covers independent extensions only.** Patched core files, forks, and derivatives of the official plugins or themes remain fully subject to the AGPLv3.

### 🟡 Commercial Licensing — when the AGPLv3 does not fit

If the AGPLv3 — in particular Section 13 — is not compatible with how you intend to deploy HayabusaPress, a separate commercial license is available from the copyright holder. Contact **support@2bo-velocity.com**.

- **You need it for:** modifying the core and not publishing the changes, embedding HayabusaPress in a product you distribute, or operating a modified version as a network service.
- **You do not need it for:** running HayabusaPress unmodified for your own sites, or developing and selling proprietary third-party plugins and themes — the exception above already covers that at no cost.

Full text and the exact wording of the exception: [LICENSE](LICENSE). Explanation and FAQ: [hayabusapress.org/docs/legal/license](https://hayabusapress.org/docs/legal/license).

### Contributing

Pull requests are welcome — please read **[CONTRIBUTING.md](CONTRIBUTING.md)** first. Because HayabusaPress is also offered under a commercial licence, contributing carries an explicit licence grant, and the document states plainly what you agree to.

### Third-Party Notices

| Library | License | Usage |
|---|---|---|
| [Tailwind CSS](https://tailwindcss.com/) | MIT | Admin panel CSS |
| [Lucide Icons](https://lucide.dev/) | ISC | Admin panel icons (inline SVG) |
| [marked.js](https://marked.js.org/) *(optional)* | MIT | Admin Markdown live preview (CDN, admin only) |

Full third-party copyright notices: [COPYING.THIRD-PARTY](COPYING.THIRD-PARTY)
