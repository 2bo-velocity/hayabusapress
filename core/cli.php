#!/usr/bin/env php
<?php
/**
 * HayabusaPress - CLI tool (db:install, system:init, media GC, etc.).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('This command can only be run from the CLI.');
}

define('ROOT_PATH',    dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('PUBLIC_PATH',  ROOT_PATH . '/public');
define('CORE_PATH',    ROOT_PATH . '/core');

// Env parser (C-series)
require_once CORE_PATH . '/Env.php';
$vars = Env::parseFile(ROOT_PATH . '/.env');
Env::hydrate($vars);

require_once CORE_PATH . '/DB.php';
require_once CORE_PATH . '/Hash.php';
require_once CORE_PATH . '/Crypto.php';
require_once CORE_PATH . '/Locale.php';
require_once CORE_PATH . '/Gate.php';
require_once CORE_PATH . '/AuthorCompiler.php';
require_once CORE_PATH . '/Schema.php';
require_once CORE_PATH . '/Migrator.php';
require_once CORE_PATH . '/Media.php';
require_once CORE_PATH . '/Format.php';
require_once CORE_PATH . '/ThemeManager.php';

$command = $argv[1] ?? '';
$arg     = $argv[2] ?? '';

match ($command) {
    // Existing commands
    'tool:hash'        => cmd_hash($arg),
    'system:init'      => cmd_init(),
    'plugins:rebuild'  => cmd_plugins_rebuild(),
    'lang:rebuild'     => cmd_lang_rebuild($arg),
    'lang:check'       => cmd_lang_check(implode(' ', array_slice($argv, 2))),
    'lang:publish'     => cmd_lang_publish($arg),
    'assets:rebuild'   => cmd_assets_rebuild(),
    // new commands
    'tool:key'         => cmd_tool_key(),
    'db:install'       => cmd_db_install(),
    'db:migrate'       => cmd_db_migrate(),
    'db:status'        => cmd_db_status(),
    'posts:recompile'  => cmd_posts_recompile(),
    'authors:rebuild'  => cmd_authors_rebuild(),
    'cache:purge-category' => cmd_cache_purge_category($arg),
    'config:rebuild'   => cmd_config_rebuild(),
    'tfa:reencrypt'    => cmd_tfa_reencrypt(),
    // A-series
    'admin:ip-disable' => cmd_admin_ip_disable(),
    'admin:ip-show'    => cmd_admin_ip_show(),
    // B-series
    'comment:block-ip'   => cmd_comment_block_ip($arg),
    'comment:unblock-ip' => cmd_comment_unblock_ip($arg),
    'comment:blocklist'  => cmd_comment_blocklist(),
    // B.11
    'site:deny-ip'   => cmd_site_deny_ip($arg),
    'site:allow-ip'  => cmd_site_allow_ip($arg),
    'site:denylist'  => cmd_site_denylist(),
    // feeds
    'feed:rebuild'      => cmd_feed_rebuild(),
    // Scale maintenance
    'sitemap:rebuild'   => cmd_sitemap_rebuild($arg),
    'counters:rebuild'  => cmd_counters_rebuild(),
    'tags:reindex'      => cmd_tags_reindex(),
    // Reusable blocks / managed links
    'blocks:rebuild'    => cmd_blocks_rebuild(),
    'links:rebuild'     => cmd_links_rebuild(),
    // scheduled publishing
    'posts:publish-due' => cmd_posts_publish_due(),
    'schedule:recompile' => cmd_schedule_recompile(),
    // media lifecycle
    'media:gc'          => cmd_media_gc($arg),
    // media sharding migration
    'media:reshard'     => cmd_media_reshard($arg),
    // physical deletion of trash
    'content:purge-trash' => cmd_content_purge_trash($arg),
    // auto-cleanup of empty drafts
    'content:gc-empty-drafts' => cmd_content_gc_empty_drafts($arg),
    default             => cmd_help(),
};

// ============================================================
// Existing commands
// ============================================================

function cmd_help(): void
{
    echo <<<HELP
HayabusaPress CLI

Usage:
  # Setup
  php core/cli.php tool:key                      Generate APP_KEY
  php core/cli.php tool:hash "password"          Generate a password hash
  php core/cli.php db:install                    New install: full schema + seed data + baseline
  php core/cli.php db:migrate                    Existing: apply pending migrations
  php core/cli.php db:status                     Show migration status

  # Cache / build
  php core/cli.php system:init                   Regenerate all caches
  php core/cli.php plugins:rebuild               Recompile plugin registry + hook map
  php core/cli.php lang:rebuild [locale]         Cleanse per-locale view cache
  php core/cli.php lang:check [locale] [--strict] Verify catalogs against the English master
  php core/cli.php lang:publish {locale}         Scaffold a new locale from the English master
  php core/cli.php assets:rebuild                Regenerate all caches after theme CSS/JS changes
  php core/cli.php authors:rebuild               Rebuild author singleton + purge FPC
  php core/cli.php posts:recompile               Re-render Markdown + re-sanitize all posts
  php core/cli.php cache:purge-category {id}    Purge listing FPC for the given category
  php core/cli.php config:rebuild                Regenerate env.cache.php
  php core/cli.php media:gc [--days=N]           Collect orphan draft media (default 7 days)
  php core/cli.php media:reshard [--dry-run]     Migrate flat layout to {ab}/{cd}/ sharding
  php core/cli.php content:purge-trash [--days=N] Permanently delete long-trashed content (default 30 days)
  php core/cli.php content:gc-empty-drafts [--hours=N] Delete abandoned empty drafts (default 24 hours)

  # Security
  php core/cli.php tfa:reencrypt                 Encrypt TOTP secrets

  # Admin access protection (A)
  php core/cli.php admin:ip-disable              Disable the IP allowlist immediately (lockout escape)
  php core/cli.php admin:ip-show                 Show the current allowlist settings

  # Spam IP block (B)
  php core/cli.php comment:block-ip {ip}        Block an IP
  php core/cli.php comment:unblock-ip {ip}      Unblock an IP
  php core/cli.php comment:blocklist             List the blocklist

  # Public viewing deny (B.11)
  php core/cli.php site:deny-ip {ip}            Add an IP denied from viewing public pages
  php core/cli.php site:allow-ip {ip}           Remove a public-viewing deny entry
  php core/cli.php site:denylist                 Show the deny list

  # Feeds
  php core/cli.php feed:rebuild                  Regenerate Atom/RSS feeds for all locales

  # Scale maintenance
  php core/cli.php sitemap:rebuild [--if-pending] Rebuild sitemap.xml (--if-pending: only when a throttled save left it stale)
  php core/cli.php counters:rebuild              Recompute categories.post_count / tags.post_count
  php core/cli.php tags:reindex                  Rebuild post_tags' denormalized listing keys

  # Reusable blocks / managed links
  php core/cli.php blocks:rebuild                Rebuild blocks.cache.php ({{ block:slug }}) and clear all FPC
  php core/cli.php links:rebuild                 Rebuild links.cache.php (/go/{alias})

  # Scheduled publishing
  php core/cli.php posts:publish-due             Promote scheduled posts whose time has come
  php core/cli.php schedule:recompile            Recompute next_schedule.php (next firing time)

HELP;
}

function cmd_hash(string $password): void
{
    if ($password === '') {
        echo "Error: please specify a password.\n";
        exit(1);
    }
    echo Hash::make($password) . "\n";
}

function cmd_init(): void
{
    echo "Running all compilers...\n";

    $files = [
        'RouterCompiler', 'ViewCompiler', 'MarkdownCompiler', 'HtmlSanitizer',
        'MenuCompiler', 'SidebarCompiler', 'SeoCompiler',
        'SiteFileCompiler', 'CategoryCompiler', 'TagCompiler',
        'CommentCompiler', 'PluginManager', 'SecurityHeaderCompiler',
        'SiteDenylistCompiler', 'TranslationCompiler', 'FeedCompiler',
        'Scheduler', 'PostTagIndex', 'Counters',
    ];
    foreach ($files as $f) {
        $path = CORE_PATH . "/{$f}.php";
        if (file_exists($path)) {
            require_once $path;
        }
    }

    RouterCompiler::compile();
    echo "  RouterCompiler         [OK]\n";

    MenuCompiler::compile();
    echo "  MenuCompiler           [OK]\n";

    // The derived columns the compilers below read must be correct before they are read.
    PostTagIndex::rebuildAll();
    echo "  PostTagIndex           [OK]\n";

    Counters::rebuildAll();
    echo "  Counters               [OK]\n";

    CategoryCompiler::compile();
    echo "  CategoryCompiler       [OK]\n";

    TagCompiler::compile();
    echo "  TagCompiler            [OK]\n";

    $contentLocales = HbpLocale::getAvailableContentLocales();
    if (empty($contentLocales)) {
        $contentLocales = [(Env::get('SITE_DEFAULT_LANG', 'en')) => 'default'];
    }
    foreach (array_keys($contentLocales) as $lang) {
        SidebarCompiler::compileRecentCommentsWidget($lang);
        SidebarCompiler::compileArchiveWidget($lang);
        SidebarCompiler::compileAllLayouts($lang);
    }
    SidebarCompiler::compileHtmlSources();
    echo "  SidebarCompiler        [OK]\n";

    require_once CORE_PATH . '/BlockCompiler.php';
    require_once CORE_PATH . '/LinkCompiler.php';
    BlockCompiler::compile();
    LinkCompiler::compile();
    echo "  Block/LinkCompiler     [OK]\n";

    SeoCompiler::compile();
    echo "  SeoCompiler            [OK]\n";

    SiteFileCompiler::compileSitemap(true);
    echo "  SiteFileCompiler       [OK]\n";

    if (class_exists('FeedCompiler')) {
        FeedCompiler::rebuildAll();
        echo "  FeedCompiler           [OK]\n";
    }

    PluginManager::rebuildCache();
    echo "  PluginManager          [OK]\n";

    AuthorCompiler::rebuild();
    echo "  AuthorCompiler         [OK]\n";

    SiteDenylistCompiler::compile();
    echo "  SiteDenylistCompiler   [OK]\n";

    SecurityHeaderCompiler::compile();
    echo "  SecurityHeaderCompiler [OK]\n";

    // Publish every theme's assets to public/assets/themes/{name}/
    ThemeManager::publishAll();
    echo "  ThemeManager           [OK]\n";

    // recompute next_schedule.php (for pseudo-cron)
    if (class_exists('Scheduler')) {
        Scheduler::recompileNext();
        echo "  Scheduler              [OK]\n";
    }

    echo "\nsystem:init complete.\n";
}

function cmd_plugins_rebuild(): void
{
    echo "Rebuilding plugin registry...\n";

    foreach (['PluginManager', 'RouterCompiler', 'Locale', 'SecurityHeaderCompiler'] as $f) {
        $path = CORE_PATH . "/{$f}.php";
        if (file_exists($path)) {
            require_once $path;
        }
    }

    PluginManager::rebuildCache();
    echo "  hooks.cache.php / plugins.cache.php  [OK]\n";

    RouterCompiler::compile();
    echo "  routes.cache.php                     [OK]\n";

    echo "\nplugins:rebuild complete.\n";
}

function cmd_assets_rebuild(): void
{
    require_once CORE_PATH . '/commands/AssetsRebuild.php';
    AssetsRebuild::run();
}

function cmd_lang_rebuild(string $locale = ''): void
{
    require_once CORE_PATH . '/ViewCompiler.php';

    if ($locale !== '') {
        echo "Cleansing view cache for locale: {$locale}\n";
        ViewCompiler::clearAll($locale);
        echo "  Deleted view cache for {$locale}.\n";
    } else {
        echo "Cleansing view cache for all locales...\n";
        ViewCompiler::clearAll();
        echo "  Deleted view cache for all locales.\n";
    }
    echo "\nlang:rebuild complete. Caches are regenerated on next access via Auto-Healing.\n";
}

/**
 * lang:check — verify every message catalog against the English master.
 * Exits non-zero when a locale is missing keys or carries keys the master does not have,
 * so it can gate a release. See core/commands/LangCheck.php.
 */
function cmd_lang_check(string $arg): void
{
    require_once CORE_PATH . '/commands/LangCheck.php';
    exit(LangCheck::run($arg));
}

/**
 * lang:publish — scaffold a new locale's catalogs from the English master.
 * Never overwrites existing files. See core/commands/LangPublish.php.
 */
function cmd_lang_publish(string $code): void
{
    require_once CORE_PATH . '/commands/LangPublish.php';
    exit(LangPublish::run($code));
}

// ============================================================
// new commands
// ============================================================

function cmd_tool_key(): void
{
    $key = Crypto::generateAppKey();
    echo "Generated APP_KEY:\n";
    echo $key . "\n\n";
    echo "Set the following in your .env:\n";
    echo "APP_KEY=\"{$key}\"\n";
}

/**
 * Split SQL into statements with the migrator's splitter, the same one the browser installer uses.
 * It does not split on ';' inside comments, string literals or dollar-quoted bodies -- the full
 * schema has such ';' (the SPDX header line, column COMMENTs) -- and drops comment-only chunks while
 * keeping statements that follow a comment block (seed, baseline, etc.).
 */
function split_sql_statements(string $sql): array
{
    return Migrator::splitStatements($sql);
}

function cmd_db_install(): void
{
    $driver = Env::get('DB_DRIVER', 'mysql');
    $file   = CORE_PATH . "/database/schema.{$driver}.sql";

    if (!file_exists($file)) {
        echo "Error: schema file not found: {$file}\n";
        exit(1);
    }

    echo "Connecting to the database ({$driver})...\n";
    $pdo = DB::getInstance();

    $sql = file_get_contents($file);

    echo "Loading schema...\n";
    foreach (split_sql_statements($sql) as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists')
                && !str_contains($e->getMessage(), 'Duplicate')) {
                echo "Warning: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "Schema loaded.\n";

    // Sample post (Hello World)
    cmd_seed_hello_world($pdo, $driver);

    // Record all known migrations as baseline
    echo "Recording migration baseline...\n";
    Migrator::baseline();

    // generate the author singleton (required to resolve the Hello World post's author)
    echo "Generating author singleton...\n";
    AuthorCompiler::rebuild();
    echo "  authors.cache.php generated.\n";

    echo "db:install complete.\n";
}

function cmd_seed_hello_world(PDO $pdo, string $driver): void
{
    $slug  = 'hello-world';
    $check = $pdo->prepare("SELECT id FROM posts WHERE slug = ? LIMIT 1");
    $check->execute([$slug]);
    if ($check->fetch()) {
        echo "A sample post already exists. Skipping.\n";
        return;
    }

    require_once CORE_PATH . '/MarkdownCompiler.php';
    require_once CORE_PATH . '/HtmlSanitizer.php';

    $defaultLang = Env::get('SITE_DEFAULT_LANG', 'en');
    // The sample body follows the default language. Undefined languages fall back to English (default-English policy).
    $samples = [
        'en' => "# Hello World!\n\nWelcome to HayabusaPress.\n\nThis is your first sample post.\n",
        'ja' => "# Hello World!\n\nHayabusaPress へようこそ。\n\nこれは最初のサンプル記事です。\n",
    ];
    $markdown    = $samples[$defaultLang] ?? $samples['en'];
    $contentHtml = MarkdownCompiler::render($markdown, true);
    $now         = date('Y-m-d H:i:s');

    // Fetch the uncategorized category matching the default language (a fixed id would cause a language mismatch). NULL if not found.
    $catStmt = $pdo->prepare("SELECT id FROM categories WHERE type='post' AND lang=? AND slug='uncategorized' LIMIT 1");
    $catStmt->execute([$defaultLang]);
    $catId = $catStmt->fetchColumn();
    $catId = $catId !== false ? (int)$catId : null;

    $pdo->prepare(
        "INSERT INTO posts
          (type, lang, slug, title, content_markdown, content_html, status, category_id,
           user_id, allow_comment, show_related_posts, show_share, show_author,
           created_at, updated_at)
         VALUES
          ('post', ?, ?, 'Hello World!', ?, ?, 'published', ?,
           NULL, TRUE, TRUE, TRUE, TRUE, ?, ?)"   // TRUE, not 1: PostgreSQL rejects an integer for a BOOLEAN column
    )->execute([$defaultLang, $slug, $markdown, $contentHtml, $catId, $now, $now]);

    echo "Created a sample post.\n";

    // Initial generation of authors.cache.php
    AuthorCompiler::rebuild();
}

function cmd_db_migrate(): void
{
    echo "Checking migration status...\n";
    Migrator::ensureMigrationsTable();
    $count = Migrator::migrate();
    if ($count === 0) {
        echo "No migrations to apply (already up to date).\n";
    } else {
        echo "Applied {$count} migration(s).\n";
    }
    echo "db:migrate complete.\n";
}

function cmd_db_status(): void
{
    // environment diagnostics (run before the DB connection — results are visible even when the driver is missing)
    print_env_diagnostic();
    try {
        Migrator::status();
    } catch (Throwable $e) {
        echo "  [NG] Cannot connect to the database; the schema version cannot be checked.\n";
        echo "    Reason: " . $e->getMessage() . "\n";
        echo "    -> Check the environment diagnostics above (PDO driver) and the DB settings in .env.\n";
    }
}

/**
 * display PHP extension environment diagnostics.
 * gd is required for EXIF/metadata removal on image upload (upload rejected if missing).
 * finfo is required for MIME validation; dom for the sanitizer.
 * Called from the installer and db:status; clearly shows any that are missing.
 */
function print_env_diagnostic(): void
{
    $driver = class_exists('Env') ? (string)Env::get('DB_DRIVER', 'mysql') : 'mysql';
    $pdoExt = $driver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql';

    // [extension name, required, description]
    $checks = [
        [$pdoExt,    true,  'Database connection (PDO ' . $driver . ')'],
        ['mbstring', true,  'Multibyte string handling'],
        ['dom',      true,  'HTML sanitizer (stored-XSS defense)'],
        ['gd',       true,  'EXIF/metadata stripping for image uploads (uploads rejected if missing)'],
        ['fileinfo', true,  'MIME validation (anti extension spoofing)'],
        ['zip',      true,  'Plugin ZIP installation'],
        ['sodium',   false, 'Ed25519 signatures / 2FA secret encryption (optional)'],
    ];

    echo "PHP environment diagnostics (PHP " . PHP_VERSION . ")\n";
    echo "  ------------------------------------------------------------\n";
    $missingRequired = [];
    foreach ($checks as [$ext, $required, $desc]) {
        $ok   = extension_loaded($ext);
        $mark = $ok ? '[OK]' : ($required ? '[NG]' : '[--]');
        $tag  = $required ? '[required]' : '[optional]';
        printf("  %s %-9s %-6s %s\n", $mark, $ext, $tag, $desc);
        if (!$ok && $required) {
            $missingRequired[] = $ext;
        }
    }
    echo "  ------------------------------------------------------------\n";
    if ($missingRequired !== []) {
        echo "  [!] Missing required extensions: " . implode(', ', $missingRequired) . "\n";
        if (in_array('gd', $missingRequired, true)) {
            echo "    -> Without gd, image uploads are rejected (EXIF removal cannot be guaranteed).\n";
        }
        echo "    -> Ask your server administrator to enable the extensions above.\n";
    } else {
        echo "  [OK] All required extensions are enabled.\n";
    }
    echo "\n";

    print_media_dir_diagnostic();
}

/**
 * pre-validate the "create/write" capability of the three media parent directories.
 * If missing, try to create them with ensureDir() (default 0755, changeable via MEDIA_DIR_MODE),
 * and clearly show if creation/writing is not possible. Prevents attachments inconsistency at upload time.
 * Keep storage/upload outside the public root + fully denied by the web server (even if created, not web-reachable).
 */
function print_media_dir_diagnostic(): void
{
    $mode = sprintf('0%o', Media::dirMode());
    echo "Media directory diagnostics (MEDIA_DIR_MODE={$mode})\n";
    echo "  ------------------------------------------------------------\n";

    $dirs = [
        'storage/upload'      => Media::uploadBase(),
        'assets/articles'     => Media::articlesBase(),
        'assets/site/avatars' => Media::avatarsBase(),
    ];
    $problems = 0;
    foreach ($dirs as $label => $path) {
        $created = false;
        if (!is_dir($path)) {
            try {
                Media::ensureDir($path);
                $created = is_dir($path);
            } catch (Throwable) {
                $created = false;
            }
        }
        $exists   = is_dir($path);
        $writable = $exists && is_writable($path);
        if ($exists && $writable) {
            $note = $created ? '(newly created)' : '';
            printf("  [OK] %-20s writable %s\n", $label, $note);
        } else {
            $problems++;
            $reason = !$exists ? 'cannot create' : 'not writable';
            printf("  [NG] %-20s %s: %s\n", $label, $reason, $path);
        }
    }
    echo "  ------------------------------------------------------------\n";
    if ($problems > 0) {
        echo "  [!] There are problems with the media directories. Check permissions/disk space.\n";
        echo "    -> Until resolved, uploads will fail and no attachments rows are created.\n";
    } else {
        echo "  [OK] All three media directories exist and are writable.\n";
    }
    echo "\n";
}

function cmd_blocks_rebuild(): void
{
    require_once CORE_PATH . '/BlockCompiler.php';
    require_once CORE_PATH . '/CacheManager.php';
    BlockCompiler::compile();
    CacheManager::purgeAll();   // every cached page may embed a block
    echo "Rebuilt blocks.cache.php and cleared all FPC.\n";
}

function cmd_links_rebuild(): void
{
    require_once CORE_PATH . '/LinkCompiler.php';
    LinkCompiler::compile();
    echo "Rebuilt links.cache.php.\n";
}

function cmd_posts_recompile(): void
{
    echo "Recompiling all posts (re-render Markdown + apply HtmlSanitizer)...\n";

    require_once CORE_PATH . '/MarkdownCompiler.php';
    require_once CORE_PATH . '/HtmlSanitizer.php';
    require_once CORE_PATH . '/CacheManager.php';

    // Re-render with the author's own raw_html permission, as the editor's save would.
    // user_id NULL is the Super User, who bypasses the matrix.
    $matrix  = require CORE_PATH . '/permissions.php';
    $rawById = [];
    foreach (DB::fetchAll('SELECT id, role FROM users') as $u) {
        $rawById[(int)$u['id']] = !empty($matrix[(string)$u['role']]['post:raw_html']);
    }

    $posts = DB::fetchAll('SELECT id, lang, content_markdown, user_id, show_toc, is_sponsored FROM posts');
    $count = 0;
    foreach ($posts as $post) {
        $allowRaw    = $post['user_id'] === null || ($rawById[(int)$post['user_id']] ?? false);
        $contentHtml = MarkdownCompiler::render($post['content_markdown'], $allowRaw, [
            'toc'       => !empty($post['show_toc']),
            'sponsored' => !empty($post['is_sponsored']),
            'lang'      => (string)$post['lang'],
        ]);
        DB::execute('UPDATE posts SET content_html = ? WHERE id = ?', [$contentHtml, (int)$post['id']]);
        $count++;
    }

    // Purge all FPC (re-sanitization may change the content)
    CacheManager::purgeAll();

    echo "Recompiled {$count} post(s).\n";
    echo "Cleared all FPC (regenerated on next access via Auto-Healing).\n";
}

function cmd_authors_rebuild(): void
{
    echo "Regenerating author singleton...\n";
    require_once CORE_PATH . '/CacheManager.php';

    AuthorCompiler::rebuild();
    echo "  authors.cache.php generated.\n";

    // Author profile change: purge the FPC of all authors' posts
    CacheManager::purgeAll();
    echo "  Cleared all FPC.\n";
    echo "authors:rebuild complete.\n";
}

function cmd_cache_purge_category(string $categoryId): void
{
    if (!is_numeric($categoryId) || (int)$categoryId <= 0) {
        echo "Error: please specify a category ID.\n";
        exit(1);
    }
    require_once CORE_PATH . '/CacheManager.php';
    $catId = (int)$categoryId;

    $cat = DB::fetchOne('SELECT slug FROM categories WHERE id = ? LIMIT 1', [$catId]);
    if (!$cat) {
        echo "Category not found: id={$catId}\n";
        exit(1);
    }

    CacheManager::purgeListPages(Env::get('SITE_DEFAULT_LANG', 'en') . '_list_category_' . $cat['slug']);
    echo "Purged listing FPC for category ({$cat['slug']}).\n";
}

function cmd_config_rebuild(): void
{
    $cachePath = STORAGE_PATH . '/cache/env.cache.php';
    $envPath   = ROOT_PATH . '/.env';
    $vars      = Env::parseFile($envPath);
    Env::loadWithCache($envPath, $cachePath);
    echo "Regenerated env.cache.php.\n";
}

function cmd_tfa_reencrypt(): void
{
    $appKey = Env::get('APP_KEY', '');
    if ($appKey === '') {
        echo "Error: APP_KEY is not set. Run tool:key first.\n";
        exit(1);
    }

    $users = DB::fetchAll('SELECT id, two_factor_secret FROM users WHERE two_factor_secret IS NOT NULL');
    $count = 0;
    foreach ($users as $user) {
        $secret = $user['two_factor_secret'];
        // Encrypt only those without a prefix (plaintext)
        if (!str_starts_with($secret, '$na$') && !str_starts_with($secret, '$oc$')) {
            try {
                $encrypted = Crypto::encrypt($secret);
                DB::execute('UPDATE users SET two_factor_secret = ? WHERE id = ?', [$encrypted, (int)$user['id']]);
                $count++;
            } catch (Throwable $e) {
                echo "Failed to encrypt user {$user['id']}: " . $e->getMessage() . "\n";
            }
        }
    }
    echo "Encrypted {$count} TOTP secret(s).\n";
}

// ---- A-series ----

function cmd_admin_ip_disable(): void
{
    echo "To disable (empty) ADMIN_IP_ALLOWLIST, edit your .env manually:\n";
    echo "  ADMIN_IP_ALLOWLIST=\"\"\n";
    echo "Or change ADMIN_IP_MODE to log-only.\n";
    echo "\nCurrent settings:\n";
    echo "  ADMIN_IP_ALLOWLIST=" . Env::get('ADMIN_IP_ALLOWLIST', '(not set)') . "\n";
    echo "  ADMIN_IP_MODE=" . Env::get('ADMIN_IP_MODE', 'enforce') . "\n";
}

function cmd_admin_ip_show(): void
{
    $allowlist = Env::get('ADMIN_IP_ALLOWLIST', '');
    $mode      = Env::get('ADMIN_IP_MODE', 'enforce');
    echo "Admin IP allowlist settings:\n";
    echo "  ADMIN_IP_ALLOWLIST: " . ($allowlist ?: '(empty = unrestricted)') . "\n";
    echo "  ADMIN_IP_MODE: {$mode}\n";
    if ($allowlist !== '') {
        echo "\nAllowed CIDR/IP:\n";
        foreach (explode(',', $allowlist) as $cidr) {
            echo "  - " . trim($cidr) . "\n";
        }
    }
}

// ---- B-series ----

function cmd_comment_block_ip(string $ip): void
{
    if ($ip === '') {
        echo "Error: please specify an IP address.\n";
        exit(1);
    }
    require_once CORE_PATH . '/IpBlocklist.php';
    require_once CORE_PATH . '/AccessControl.php';
    IpBlocklist::block($ip, 'manual');
    echo "Blocked IP: {$ip}\n";
}

function cmd_comment_unblock_ip(string $ip): void
{
    if ($ip === '') {
        echo "Error: please specify an IP address.\n";
        exit(1);
    }
    require_once CORE_PATH . '/IpBlocklist.php';
    IpBlocklist::unblock($ip);
    echo "Unblocked IP: {$ip}\n";
}

function cmd_comment_blocklist(): void
{
    require_once CORE_PATH . '/IpBlocklist.php';
    $list = IpBlocklist::list();
    if (empty($list)) {
        echo "The blocklist is empty.\n";
        return;
    }
    echo str_pad('CIDR/IP', 22) . str_pad('Source', 8) . str_pad('Reason', 25) . str_pad('Expires', 22) . "Hits\n";
    echo str_repeat('-', 80) . "\n";
    foreach ($list as $row) {
        $expires = $row['expires_at'] ?? '(permanent)';
        echo str_pad($row['cidr'], 22)
            . str_pad($row['source'], 8)
            . str_pad($row['reason'] ?? '', 25)
            . str_pad($expires, 22)
            . $row['hit_count'] . "\n";
    }
}

// ---- B.11 ----

function cmd_site_deny_ip(string $ip): void
{
    if ($ip === '') {
        echo "Error: please specify an IP address or CIDR.\n";
        exit(1);
    }
    require_once CORE_PATH . '/SiteDenylistCompiler.php';
    $envPath = ROOT_PATH . '/.env';
    $current = Env::get('SITE_IP_DENYLIST', '');
    $list    = array_filter(array_map('trim', explode(',', $current)));
    if (!in_array($ip, $list, true)) {
        $list[] = $ip;
    }
    echo "Note: add SITE_IP_DENYLIST to your .env manually:\n";
    echo "  SITE_IP_DENYLIST=\"" . implode(',', $list) . "\"\n";
    echo "After adding it, run site:denylist to refresh the cache.\n";
}

function cmd_site_allow_ip(string $ip): void
{
    if ($ip === '') {
        echo "Error: please specify an IP address or CIDR.\n";
        exit(1);
    }
    $current = Env::get('SITE_IP_DENYLIST', '');
    $list    = array_filter(array_map('trim', explode(',', $current)));
    $list    = array_filter($list, fn($cidr) => $cidr !== $ip);
    echo "Note: remove SITE_IP_DENYLIST from your .env manually:\n";
    echo "  SITE_IP_DENYLIST=\"" . implode(',', $list) . "\"\n";
    echo "After editing, run site:denylist to refresh the cache.\n";
}

function cmd_site_denylist(): void
{
    require_once CORE_PATH . '/SiteDenylistCompiler.php';
    require_once CORE_PATH . '/AccessControl.php';

    // Regenerate the cache
    SiteDenylistCompiler::compile();

    $current = Env::get('SITE_IP_DENYLIST', '');
    $list    = array_filter(array_map('trim', explode(',', $current)));
    echo "Public page deny list (SITE_IP_DENYLIST):\n";
    if (empty($list)) {
        echo "  (empty = no viewing restrictions)\n";
    } else {
        foreach ($list as $cidr) {
            echo "  - {$cidr}\n";
        }
    }
    echo "\nUpdated site_denylist.cache.php.\n";
}

// ============================================================
// feeds
// ============================================================

function cmd_feed_rebuild(): void
{
    require_once CORE_PATH . '/FeedCompiler.php';
    require_once CORE_PATH . '/Locale.php';

    echo "Regenerating Atom/RSS feeds for all locales...\n";

    if (!Env::bool('FEED_ENABLED', true)) {
        echo "  Skipped because FEED_ENABLED=false.\n";
        return;
    }

    $locales = HbpLocale::getAvailableContentLocales();
    if (empty($locales)) {
        $locales = [Env::get('SITE_DEFAULT_LANG', 'en') => 'default'];
    }

    foreach (array_keys($locales) as $lang) {
        FeedCompiler::compile($lang);
        echo "  {$lang}  [OK]\n";
    }

    echo "\nfeed:rebuild complete.\n";
}

// ============================================================
// Scale maintenance
// ============================================================

/**
 * Rebuild sitemap.xml.
 * With --if-pending it is a no-op unless an automatic rebuild was throttled (SITEMAP_MIN_INTERVAL),
 * which makes it safe to run from cron every minute.
 */
function cmd_sitemap_rebuild(string $arg): void
{
    require_once CORE_PATH . '/SiteFileCompiler.php';

    if (str_contains($arg, '--if-pending')) {
        echo SiteFileCompiler::rebuildIfPending()
            ? "Rebuilt the pending sitemap.\n"
            : "Nothing pending; sitemap left as is.\n";
        return;
    }

    echo "Rebuilding sitemap.xml...\n";
    SiteFileCompiler::compileSitemap(true);
    echo "sitemap:rebuild complete.\n";
}

/**
 * Recompute categories.post_count / tags.post_count from the source of truth.
 * The counters are moved by deltas during normal operation, so run this if they ever look wrong
 * (after a manual SQL edit, an interrupted request, or a restore from backup).
 */
function cmd_counters_rebuild(): void
{
    require_once CORE_PATH . '/Counters.php';

    echo "Recomputing category / tag post counts...\n";
    Counters::rebuildAll();

    require_once CORE_PATH . '/CategoryCompiler.php';
    require_once CORE_PATH . '/TagCompiler.php';
    CategoryCompiler::compile();
    TagCompiler::compile();

    echo "counters:rebuild complete (categories.cache.php / tags.cache.php regenerated).\n";
}

/**
 * Rebuild post_tags' denormalized listing keys (type / lang / status / published_at) from posts.
 * The repair path for when the maintained columns ever drift from posts.
 */
function cmd_tags_reindex(): void
{
    require_once CORE_PATH . '/PostTagIndex.php';

    echo "Rebuilding post_tags listing keys...\n";
    $rows = PostTagIndex::rebuildAll();
    echo "  Updated {$rows} junction row(s).\n";

    // The tag counts are derived from those same columns, so refresh them together.
    cmd_counters_rebuild();
}

// ============================================================
// scheduled publishing
// ============================================================

function cmd_posts_publish_due(): void
{
    require_once CORE_PATH . '/Scheduler.php';
    require_once CORE_PATH . '/CacheManager.php';
    require_once CORE_PATH . '/SiteFileCompiler.php';
    require_once CORE_PATH . '/FeedCompiler.php';
    require_once CORE_PATH . '/SidebarCompiler.php';
    require_once CORE_PATH . '/TranslationCompiler.php';

    echo "Checking scheduled posts to publish...\n";

    $now = gmdate('Y-m-d H:i:s');
    $due = DB::fetchAll(
        "SELECT id, lang, type, slug FROM posts WHERE status = 'scheduled' AND published_at <= ?",
        [$now]
    );

    if (empty($due)) {
        echo "  Nothing due.\n";
    } else {
        foreach ($due as $post) {
            echo "  Publishing: [{$post['lang']}] {$post['slug']}\n";
        }
        Scheduler::runDue();
        echo "  Published " . count($due) . " post(s).\n";
    }

    echo "\nposts:publish-due complete.\n";
}

function cmd_schedule_recompile(): void
{
    require_once CORE_PATH . '/Scheduler.php';

    echo "Recomputing next_schedule.php...\n";
    Scheduler::recompileNext();
    echo "  Updated storage/cache/next_schedule.php.\n";
    echo "\nschedule:recompile complete.\n";
}

/**
 * garbage collection of orphan draft media.
 * Deletes files in storage/upload/ that are not linked to attachments and are older than N days.
 * Does not touch public/assets/articles/ at all (published media is protected).
 */
function cmd_media_gc(string $arg): void
{
    $days = 7;
    if (preg_match('/--days=(\d+)/', $arg, $m)) {
        $days = (int)$m[1];
    }
    $uploadDir = STORAGE_PATH . '/upload';
    if (!is_dir($uploadDir)) {
        echo "storage/upload/ not found.\n";
        return;
    }

    // Set of filenames already registered in attachments (= protected)
    $rows      = DB::fetchAll('SELECT filename FROM attachments');
    $referenced = array_flip(array_column($rows, 'filename'));

    // also protect files referenced by featured images (posts.featured_image).
    //       So that an in-use featured image is not orphan-deleted just because the body reference disappeared.
    //       featured_image is in the /assets/articles/X path form, so take its basename.
    $fiRows = DB::fetchAll("SELECT featured_image FROM posts WHERE featured_image IS NOT NULL AND featured_image <> ''");
    foreach ($fiRows as $fr) {
        $fi = (string)$fr['featured_image'];
        if (preg_match('#^https?://#', $fi)) {
            continue; // External URLs (site logo, etc.) are out of scope for storage/upload
        }
        $referenced[basename($fi)] = true;
    }

    $cutoff  = time() - ($days * 86400);
    $deleted = 0;
    $freed   = 0;
    $kept    = 0;

    echo "Running media:gc (deleting orphan files older than {$days} day(s))...\n";
    // storage/upload/ is a 2-level {ab}/{cd}/ hierarchy. A recursive scan picks up all files
    //        (legacy flat placement during migration is included too). The source of truth for matching is attachments.
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $path = $fileInfo->getPathname();
        $name = $fileInfo->getFilename();
        // Referenced by attachments -> protect
        if (isset($referenced[$name])) {
            $kept++;
            continue;
        }
        // Within N days -> may still be under edit, so protect
        if (filemtime($path) > $cutoff) {
            $kept++;
            continue;
        }
        $size = (int)@filesize($path);
        if (@unlink($path)) {
            $deleted++;
            $freed += $size;
            echo "  Deleted: {$name} (" . number_format($size) . " bytes)\n";
        }
    }

    echo "\nmedia:gc complete. Deleted {$deleted} / kept {$kept} / freed "
        . number_format($freed) . " bytes\n";
}

/**
 * move existing flat-placed media to the 2-level {ab}/{cd}/ hierarchy (for flat -> shard migration).
 * Renames files directly under the three media parents (storage/upload, public/assets/articles, public/assets/site/avatars)
 * to their shardPath() location, and rewrites the public URLs of bodies/featured images from
 * flat -> shard. Finally regenerates the listing FPC, sitemap, and authors.
 * Idempotent (already-sharded files/URLs are out of scope). --dry-run shows only what would be moved.
 */
function cmd_media_reshard(string $arg): void
{
    $dryRun = str_contains($arg, '--dry-run');
    echo "Running media:reshard" . ($dryRun ? " (dry run)" : "") . "...\n";

    // 1) Move flat files directly under the three media parents to their shard location
    $dirs = [
        'storage/upload'             => Media::uploadBase(),
        'assets/articles'            => Media::articlesBase(),
        'assets/site/avatars'        => Media::avatarsBase(),
    ];
    $moved = 0;
    foreach ($dirs as $label => $base) {
        if (!is_dir($base)) {
            continue;
        }
        foreach (glob($base . '/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue; // Skip existing shard subdirectories {ab}
            }
            $name = basename($path);
            $dst  = $base . '/' . Media::shardPath($name);
            if ($path === $dst || is_file($dst)) {
                continue;
            }
            echo "  Move: {$label}/{$name} -> {$label}/" . Media::shardPath($name) . "\n";
            if (!$dryRun) {
                Media::ensureDirForFile($dst);
                @rename($path, $dst);
            }
            $moved++;
        }
    }

    // 2) Rewrite the public URLs of bodies (content_markdown / content_html) and featured images from flat -> shard
    $rewritten = 0;
    $atts = DB::fetchAll('SELECT DISTINCT filename FROM attachments');
    $map  = [];
    foreach ($atts as $a) {
        $fn = basename((string)$a['filename']);
        if ($fn === '') {
            continue;
        }
        $flat  = '/assets/articles/' . $fn;
        $shard = Media::articleUrl($fn);
        if ($flat !== $shard) {
            $map[$flat] = $shard;
        }
    }
    if ($map !== []) {
        $search  = array_keys($map);
        $replace = array_values($map);
        $posts = DB::fetchAll('SELECT id, content_markdown, content_html, featured_image FROM posts');
        foreach ($posts as $p) {
            $md = (string)($p['content_markdown'] ?? '');
            $ht = (string)($p['content_html'] ?? '');
            $fi = (string)($p['featured_image'] ?? '');
            $nmd = str_replace($search, $replace, $md);
            $nht = str_replace($search, $replace, $ht);
            $nfi = $fi !== '' ? str_replace($search, $replace, $fi) : $fi;
            if ($nmd !== $md || $nht !== $ht || $nfi !== $fi) {
                $rewritten++;
                echo "  URL rewrite: post #{$p['id']}\n";
                if (!$dryRun) {
                    DB::execute(
                        'UPDATE posts SET content_markdown = ?, content_html = ?, featured_image = ? WHERE id = ?',
                        [$nmd, $nht, ($nfi === '' ? null : $nfi), (int)$p['id']]
                    );
                }
            }
        }
    }

    echo "\nMoved {$moved} / URLs rewritten in {$rewritten} post(s)"
        . ($dryRun ? " (dry run: no changes applied)\n" : "\n");

    if (!$dryRun && ($moved > 0 || $rewritten > 0)) {
        echo "Regenerating (clear all FPC -> authors, feeds, sitemap, views)...\n";
        // Clear all FPC and regenerate on next access (because URLs changed)
        require_once CORE_PATH . '/CacheManager.php';
        CacheManager::purgeAll();
        cmd_authors_rebuild();
        if (Env::bool('FEED_ENABLED', true)) {
            cmd_feed_rebuild();
        }
        require_once CORE_PATH . '/SiteFileCompiler.php';
        SiteFileCompiler::compileSitemap(true);
        echo "  Regenerated the sitemap.\n";
    }
    echo "\nmedia:reshard complete.\n";
}

/**
 * physically delete content that has stayed in the trash (deleted_at non-NULL) for N or more days.
 * Deletes the real files (both storage/public) + DB rows (comments/post_tags/attachments via FK CASCADE).
 * Same operational philosophy as media:gc. Cron recommended: 30 4 * * * php /path/core/cli.php content:purge-trash --days=30
 */
function cmd_content_purge_trash(string $arg): void
{
    $days = 30;
    if (preg_match('/--days=(\d+)/', $arg, $m)) {
        $days = (int)$m[1];
    }
    require_once CORE_PATH . '/AdminPostController.php';

    $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
    $rows   = DB::fetchAll(
        'SELECT id, title FROM posts WHERE deleted_at IS NOT NULL AND deleted_at <= ?',
        [$cutoff]
    );

    echo "Running content:purge-trash (permanently deleting content trashed more than {$days} day(s) ago)...\n";
    $purged = 0;
    foreach ($rows as $r) {
        AdminPostController::delete((int)$r['id']);
        $purged++;
        echo "  Deleted: #{$r['id']} {$r['title']}\n";
    }
    echo "\ncontent:purge-trash complete. Permanently deleted {$purged} item(s).\n";
}

/**
 * clean up empty drafts that were auto-generated and left behind.
 * Targets status='draft' with title='' and content_markdown='' created N hours ago (default 24) or more.
 * Prevents bloat from the case of uploading only an image and leaving ('s first-upload auto-draft).
 * The real files of the linked attachments + DB rows are physically deleted by AdminPostController::delete() (FK CASCADE).
 * Trash (deleted_at non-NULL) is out of scope (handled by content:purge-trash).
 */
function cmd_content_gc_empty_drafts(string $arg): void
{
    $hours = 24;
    if (preg_match('/--hours=(\d+)/', $arg, $m)) {
        $hours = (int)$m[1];
    }
    require_once CORE_PATH . '/AdminPostController.php';

    $cutoff = gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
    $rows   = DB::fetchAll(
        "SELECT id FROM posts
         WHERE status = 'draft' AND deleted_at IS NULL
           AND title = '' AND content_markdown = ''
           AND created_at <= ?",
        [$cutoff]
    );

    echo "Running content:gc-empty-drafts (deleting empty drafts created more than {$hours} hour(s) ago)...\n";
    $deleted = 0;
    foreach ($rows as $r) {
        AdminPostController::delete((int)$r['id']);
        $deleted++;
        echo "  Deleted empty draft #{$r['id']}\n";
    }
    echo "\ncontent:gc-empty-drafts complete. Deleted {$deleted} item(s).\n";
}
