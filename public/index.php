<?php
/**
 * HayabusaPress - Public front controller (FPC-integrated entry point).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

// method B (placement outside the public root) support
// If HBP_BASE_PATH is defined or in an environment variable, use it as the app base
// When unspecified, fall back to dirname(__DIR__) (one above public/) as before
$_hbpBase = defined('HBP_BASE_PATH') ? HBP_BASE_PATH : (getenv('HBP_BASE_PATH') ?: null);
define('ROOT_PATH',    $_hbpBase ?? dirname(__DIR__));
define('STORAGE_PATH', ROOT_PATH . '/storage');
define('PUBLIC_PATH',  dirname(__FILE__));  // public/ = the directory of this file
define('CORE_PATH',    ROOT_PATH . '/core');
unset($_hbpBase);

// C-series: Env single parser (parse_ini_file() removed)
require_once CORE_PATH . '/Env.php';

// Load .env (existence check and load combined into a single disk access)
$vars = Env::parseFile(ROOT_PATH . '/.env');

if ($vars === []) {
    // This block wakes only when not installed (first access)
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    if (str_starts_with($requestUri, '/install/initialize')) {
        require_once ROOT_PATH . '/core/Installer.php';
        Installer::initialize();
        exit;
    }
    if (file_exists(ROOT_PATH . '/core/Installer.php')) {
        require_once ROOT_PATH . '/core/Installer.php';
        Installer::renderForm();
    } else {
        http_response_code(503);
        // At this point neither .env nor the locale base is loaded, so display in the default language (English), fixed
        echo '<h1>Setup required</h1><p>Configure the .env file and run <code>php core/cli.php db:install</code>.</p>';
    }
    exit;
}

Env::hydrate($vars);
unset($vars);

// ============================================================
// Delegation to the admin screen (ADMIN_PATH is arbitrary, no .htaccess dependency)
// Requests under .env's ADMIN_PATH are handed off to the admin controller.
// This removes the need to write a fixed admin rewrite in public/.htaccess,
// and the admin remains reachable even if ADMIN_PATH is freely changed from 'admin'.
// admin/index.php re-validates the ADMIN_PATH match, closing the back door (physical directory name).
// ============================================================
$_adminPath = trim((string)Env::get('ADMIN_PATH', 'admin'), '/');
$_reqPath   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
if ($_adminPath !== ''
    && ($_reqPath === '/' . $_adminPath || str_starts_with($_reqPath, '/' . $_adminPath . '/'))) {
    unset($_adminPath, $_reqPath);
    require __DIR__ . '/admin/index.php';
    exit;
}
unset($_adminPath, $_reqPath);

require_once CORE_PATH . '/bootstrap.php';
require_once CORE_PATH . '/View.php';
require_once CORE_PATH . '/ViewCompiler.php';
require_once CORE_PATH . '/RouterCompiler.php';
require_once CORE_PATH . '/MarkdownCompiler.php';
require_once CORE_PATH . '/HtmlSanitizer.php';
require_once CORE_PATH . '/MenuCompiler.php';
require_once CORE_PATH . '/SidebarCompiler.php';
require_once CORE_PATH . '/CommentCompiler.php';
require_once CORE_PATH . '/CategoryCompiler.php';
require_once CORE_PATH . '/TagCompiler.php';
require_once CORE_PATH . '/SeoCompiler.php';
require_once CORE_PATH . '/PluginManager.php';
require_once CORE_PATH . '/AuthorCompiler.php';
require_once CORE_PATH . '/SiteDenylistCompiler.php';
require_once CORE_PATH . '/SecurityHeaderCompiler.php';
// CacheManager owns the single derivation of an FPC file's sharded path, so the fast-path
// reader below and renderWithFpc() cannot disagree about where a page lives.
require_once CORE_PATH . '/CacheManager.php';

// ============================================================
// B.11: public-page view deny check (zero DB, precompiled array)
// Complete no-op if SITE_IP_DENYLIST is empty (default = unrestricted viewing, zero performance impact)
// ============================================================
$clientIp = AccessControl::clientIp();
SiteDenylistCompiler::checkAndBlock($clientIp);

// ============================================================
// scheduled-publish / pseudo-cron check (does not pollute the FPC fast path)
// Load next_schedule.php via OPcache, just one integer comparison.
// Complete no-op for future / no schedule / cron/manual mode (zero DB).
// ============================================================
$_nextScheduleFile = STORAGE_PATH . '/cache/next_schedule.php';
if (Env::get('SCHEDULE_MODE', 'pseudo') === 'pseudo' && file_exists($_nextScheduleFile)) {
    $_scheduleData = @include $_nextScheduleFile;
    if (is_array($_scheduleData) && (int)($_scheduleData['next'] ?? PHP_INT_MAX) <= time()) {
        require_once CORE_PATH . '/Scheduler.php';
        require_once CORE_PATH . '/CacheManager.php';
        require_once CORE_PATH . '/SiteFileCompiler.php';
        require_once CORE_PATH . '/FeedCompiler.php';
        require_once CORE_PATH . '/SidebarCompiler.php';
        require_once CORE_PATH . '/TranslationCompiler.php';
        Scheduler::checkAndRunDue();
    }
    unset($_scheduleData);
}
unset($_nextScheduleFile);

// ============================================================
// load the tiny map + URL pattern matching
// ============================================================
$cfg = RouterCompiler::load();

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$uri        = rtrim(parse_url($requestUri, PHP_URL_PATH) ?? '/', '/') ?: '/';

// (1) Special endpoints (language-independent)
if (isset($cfg['special'][$uri])) {
    handleSpecial($cfg['special'][$uri]);
    exit;
}

// (1b) Managed links /go/{alias}: a 302 from a compiled array (language-independent, no DB, no FPC)
if (str_starts_with($uri, '/go/')) {
    require_once CORE_PATH . '/LinkCompiler.php';
    LinkCompiler::serve(substr($uri, 4));
    exit;
}

// (1): AUTO_LANG_REDIRECT — automatic Accept-Language 302 only on the root /
// Default false for SEO safety. Enabling it may hinder reaching all language versions
if ($uri === '/' && Env::bool('AUTO_LANG_REDIRECT', false)) {
    $acceptLang  = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $detected    = HbpLocale::negotiateFromAcceptLanguage($acceptLang);
    $_cfgDefault = $cfg['default'];
    if ($detected !== null && $detected !== $_cfgDefault) {
        header("Location: /{$detected}", true, 302);
        exit;
    }
    unset($_cfgDefault);
}

// (2) /page/1 -> 301 to the canonical URL (no suffix)
if (preg_match('#/page/1$#', $uri)) {
    header('Location: ' . (preg_replace('#/page/1$#', '', $uri) ?: '/'), true, 301);
    exit;
}

// (3) Strip the lang prefix
$defaultLang = $cfg['default'];
$lang        = $defaultLang;
$path        = $uri;
if (preg_match('#^/([a-z]{2,5})(/.*)?$#', $uri, $m)
    && in_array($m[1], $cfg['langs'], true)
    && $m[1] !== $defaultLang) {
    $lang = $m[1];
    $path = $m[2] ?? '/';
    if ($path === '') {
        $path = '/';
    }
}

// Fix the CURRENT_LANG constant
define('CURRENT_LANG', $lang);

// (4) Plugin routes
if (isset($cfg['plugins'][$path])) {
    $pluginId = $cfg['plugins'][$path];
    handlePlugin($pluginId, $lang, $cfg);
    exit;
}

// (5) Detail/listing pattern matching
$pageSize = Env::int('LIST_PAGE_SIZE', 10);
$key     = null;
$resolve = null;

if ($path === '/' || preg_match('#^/page/(\d{1,4})$#', $path, $m)) {
    $p       = (int)($m[1] ?? 1);
    $key     = $lang . '_home' . ($p > 1 ? "_p{$p}" : '');
    $resolve = ['kind' => 'home', 'lang' => $lang, 'page' => $p];
} elseif (preg_match('#^/post/category/([a-z0-9][a-z0-9-]{0,199})(?:/page/(\d{1,4}))?$#', $path, $m)) {
    $p       = (int)($m[2] ?? 1);
    $key     = $lang . '_list_category_' . $m[1] . ($p > 1 ? "_p{$p}" : '');
    $resolve = ['kind' => 'category', 'slug' => $m[1], 'lang' => $lang, 'page' => $p];
} elseif (preg_match('#^/post/tag/([a-z0-9][a-z0-9-]{0,199})(?:/page/(\d{1,4}))?$#', $path, $m)) {
    $p       = (int)($m[2] ?? 1);
    $key     = $lang . '_list_tag_' . $m[1] . ($p > 1 ? "_p{$p}" : '');
    $resolve = ['kind' => 'tag', 'slug' => $m[1], 'lang' => $lang, 'page' => $p];
} elseif (preg_match('#^/post/archive/(\d{4}-\d{2})(?:/page/(\d{1,4}))?$#', $path, $m)) {
    $p       = (int)($m[2] ?? 1);
    $key     = $lang . '_list_archive_' . $m[1] . ($p > 1 ? "_p{$p}" : '');
    $resolve = ['kind' => 'archive', 'ym' => $m[1], 'lang' => $lang, 'page' => $p];
} elseif (preg_match('#^/post/series/([a-z0-9][a-z0-9-]{0,199})(?:/page/(\d{1,4}))?$#', $path, $m)) {
    $p       = (int)($m[2] ?? 1);
    $key     = $lang . '_list_series_' . $m[1] . ($p > 1 ? "_p{$p}" : '');
    $resolve = ['kind' => 'series', 'slug' => $m[1], 'lang' => $lang, 'page' => $p];
} elseif (preg_match('#^/search$#', $path)) {
    // the search page is static (client-side search). Baked into FPC and served.
    $key     = $lang . '_search';
    $resolve = ['kind' => 'search', 'lang' => $lang];
} elseif (preg_match('#^/feed\.(atom\.xml|rss\.xml|json)$#', $path, $m)) {
    // serve precompiled feeds via readfile (with Auto-Healing)
    handleFeedFile($lang, explode('.', $m[1])[0], $cfg['default']);
    exit;
} elseif ($path === $uri
    && preg_match('#^/feeds/([a-z]{2,5})/(category|series)/([a-z0-9][a-z0-9-]{0,199})\.(atom\.xml|rss\.xml|json)$#', $path, $m)) {
    // per-category and per-series feeds (FeedCompiler::CATEGORY_DIR); the language is in the path itself
    handleListFeedFile($m[2], $m[1], $m[3], explode('.', $m[4])[0]);
    exit;
} elseif ($path === '/feed') {
    // Legacy URL backward compat: 301 redirect to /feed.atom.xml
    $feedPrefix = ($lang === $cfg['default']) ? '' : "/{$lang}";
    header('Location: ' . $feedPrefix . '/feed.atom.xml', true, 301);
    exit;
} elseif (preg_match('#^/(post|page)/([a-z0-9][a-z0-9-]{0,199})$#', $path, $m)) {
    $key     = $lang . '_' . $m[1] . '_' . $m[2];
    $resolve = ['kind' => $m[1], 'slug' => $m[2], 'lang' => $lang];
} elseif ($path === '/comment_post') {
    require_once PUBLIC_PATH . '/comment_post.php';
    exit;
} else {
    http_response_code(404);
    render404(['lang' => $lang, 'siteName' => Env::get('SITE_NAME', 'HBP')]);
    exit;
}

// (6) Out-of-range numbered pages return 404 immediately
if (isset($resolve['page']) && ($resolve['page'] < 1 || $resolve['page'] > listCap($resolve))) {
    http_response_code(404);
    render404(['lang' => $lang, 'siteName' => Env::get('SITE_NAME', 'HBP')]);
    exit;
}

// (7) Immediate FPC hit (zero references to the map or the DB)
// A query string does not change a cached page (themes do not read $_GET; only search does, and its
// result pages are never cached), so it does not bypass the cache either. Otherwise ?anything on
// every request would force a full render with DB queries — a cheap way to load the database — and
// tracking parameters (utm_*) would miss the cache for real readers too.
if ($key !== null && (($resolve['kind'] ?? '') !== 'search' || empty($_GET))) {
    $cacheFile = CacheManager::pagePath($key);
    if (is_file($cacheFile)) {
        SecurityHeaderCompiler::emit('frontend');
        if (!CacheManager::sendValidators($cacheFile)) {
            readfile($cacheFile);
        }
        exit;
    }
}

// (8) On miss: load common data and generate the page
SecurityHeaderCompiler::emit('frontend');

$siteName    = Env::get('SITE_NAME', 'HayabusaPress');
$siteUrl     = rtrim(Env::get('SITE_URL', ''), '/');
$menus       = MenuCompiler::load();
$seoHead     = SeoCompiler::loadHead();
$seoGlobal   = SeoCompiler::loadGlobal();
$__          = HbpLocale::loadFrontendDictionary($lang);

$commonData  = compact('siteName', 'siteUrl', 'menus', 'seoHead', 'seoGlobal', '__', 'lang', 'defaultLang');

match ($resolve['kind']) {
    'home'     => handleHome($resolve, $commonData, $key, $pageSize),
    'category' => handleCategory($resolve, $commonData, $key, $pageSize),
    'tag'      => handleTag($resolve, $commonData, $key, $pageSize),
    'archive'  => handleArchive($resolve, $commonData, $key, $pageSize),
    'series'   => handleSeries($resolve, $commonData, $key),
    'search'   => handleSearch($resolve, $commonData, $key, $pageSize),
    'post'     => handleDetail($resolve, $commonData, $key),
    'page'     => handleDetail($resolve, $commonData, $key),
    default    => handle404($commonData),
};

// ============================================================
// Handler functions
// ============================================================

function handleHome(array $resolve, array $common, ?string $fpcKey, int $pageSize): void
{
    $lang   = $resolve['lang'];
    $page   = $resolve['page'];
    $offset = ($page - 1) * $pageSize;

    $posts = DB::fetchAll(
        "SELECT id, user_id, title, slug, type, lang, content_html, tags_cache,
                category_id, comment_count, published_at, featured_image, featured_image_alt
         FROM posts WHERE status='published' AND type='post' AND lang=?
         ORDER BY published_at DESC LIMIT ? OFFSET ?",
        [$lang, $pageSize + 1, $offset]
    );

    $listing = listingViewData($resolve, $common, count($posts) > $pageSize);
    $posts   = resolveAuthors(array_slice($posts, 0, $pageSize));

    $sidebarRight = SidebarCompiler::loadSidebar('sidebar_right', $lang);
    $sidebarLeft  = SidebarCompiler::loadSidebar('sidebar_left', $lang);

    renderWithFpc($fpcKey, 'index', array_merge($common, $listing, compact('posts', 'sidebarRight', 'sidebarLeft')));
}

function handleDetail(array $resolve, array $common, ?string $fpcKey): void
{
    $lang = $resolve['lang'];
    $type = $resolve['kind'];
    $slug = $resolve['slug'];

    $post = DB::fetchOne(
        "SELECT * FROM posts WHERE type=? AND lang=? AND slug=? AND status='published' LIMIT 1",
        [$type, $lang, $slug]
    );

    if (!$post) {
        // An old slug of a published post: 301 to where it lives now (compiled map, no DB)
        require_once CORE_PATH . '/RedirectCompiler.php';
        $to = RedirectCompiler::lookup($type, $lang, $slug);
        if ($to !== null) {
            header('Location: ' . rtrim((string)($common['siteUrl'] ?? ''), '/') . $to, true, 301);
            exit;
        }
        handle404($common);
        return;
    }

    $post['tags'] = !empty($post['tags_cache'])
        ? (json_decode($post['tags_cache'], true) ?? [])
        : [];

    // the author is resolved from the singleton (author_*_cache removed)
    $post['author'] = AuthorCompiler::get($post['user_id'] ?? null);

    // related posts are computed on the fly at FPC generation time
    $related = [];
    if ($type === 'post' && !empty($post['show_related_posts']) && !empty($post['category_id'])) {
        $related = DB::fetchAll(
            "SELECT title, slug, published_at FROM posts
             WHERE category_id=? AND lang=? AND id!=? AND type='post' AND status='published'
             ORDER BY published_at DESC LIMIT 3",
            [(int)$post['category_id'], $lang, (int)$post['id']]
        );
    }
    $post['related_posts'] = $related;

    // Series: previous / next and the link to its table of contents (two one-row index ranges)
    require_once CORE_PATH . '/Series.php';
    $post['series'] = Series::forPost($post, (string)($common['defaultLang'] ?? ''));

    // hreflang_cache
    $post['hreflang'] = !empty($post['hreflang_cache'])
        ? (json_decode($post['hreflang_cache'], true) ?? null)
        : null;

    $comments     = $type === 'post' ? CommentCompiler::load((int)$post['id']) : [];
    $seoMeta      = SeoCompiler::buildMeta($post);
    $sidebarRight = SidebarCompiler::loadSidebar('sidebar_right', $lang);
    $sidebarLeft  = SidebarCompiler::loadSidebar('sidebar_left', $lang);

    $canonical = buildCanonical($resolve, (string)($common['siteUrl'] ?? ''), (string)($common['defaultLang'] ?? ''));

    // The theme layout this post asked for (falls back to the theme's default). Listings leave
    // $layout unset, which the templates read as the default layout.
    $layout = ThemeManager::layout(isset($post['layout']) && $post['layout'] !== '' ? (string)$post['layout'] : null);

    $data     = array_merge($common, compact('post', 'comments', 'seoMeta', 'sidebarRight', 'sidebarLeft', 'canonical', 'layout'));
    $viewName = $type === 'post' ? 'post' : 'page';

    renderWithFpc($fpcKey, $viewName, $data);
}

function handleCategory(array $resolve, array $common, ?string $fpcKey, int $pageSize): void
{
    $lang    = $resolve['lang'];
    $catSlug = $resolve['slug'];
    $page    = $resolve['page'];

    $cat = DB::fetchOne("SELECT * FROM categories WHERE slug=? AND type='post' AND lang=? LIMIT 1", [$catSlug, $lang]);
    if (!$cat) {
        handle404($common);
        return;
    }

    $offset = ($page - 1) * $pageSize;
    $posts  = DB::fetchAll(
        "SELECT id, user_id, title, slug, type, lang, content_html, tags_cache, category_id, comment_count, published_at, featured_image, featured_image_alt
         FROM posts WHERE status='published' AND type='post' AND lang=? AND category_id=?
         ORDER BY published_at DESC LIMIT ? OFFSET ?",
        [$lang, (int)$cat['id'], $pageSize + 1, $offset]
    );
    $listing = listingViewData($resolve, $common, count($posts) > $pageSize);
    $posts   = array_slice($posts, 0, $pageSize);
    if ($page > 1 && empty($posts)) {
        handle404($common);
        return;
    }
    $posts        = resolveAuthors($posts);
    $sidebarRight = SidebarCompiler::loadSidebar('sidebar_right', $lang);

    renderWithFpc($fpcKey, 'category', array_merge($common, $listing, compact('cat', 'posts', 'sidebarRight')));
}

/**
 * A series' table of contents: its published posts in series order, SERIES_PAGE_SIZE per page.
 * Ordered by position only — a "newest first" switch would be a query string, which the page cache
 * does not key on.
 */
function handleSeries(array $resolve, array $common, ?string $fpcKey): void
{
    $lang = $resolve['lang'];
    $page = $resolve['page'];

    $series = DB::fetchOne('SELECT * FROM series WHERE lang = ? AND slug = ? LIMIT 1', [$lang, $resolve['slug']]);
    if (!$series) {
        handle404($common);
        return;
    }

    $size   = max(1, Env::int('SERIES_PAGE_SIZE', 100));
    $offset = ($page - 1) * $size;
    $posts  = DB::fetchAll(
        "SELECT id, title, slug, published_at, char_count, reading_minutes, series_part, featured_image
           FROM posts WHERE series_id = ? AND status = 'published'
          ORDER BY series_order, id LIMIT ? OFFSET ?",
        [(int)$series['id'], $size + 1, $offset]
    );
    $listing = listingViewData($resolve, $common, count($posts) > $size);
    $posts   = array_slice($posts, 0, $size);
    if (empty($posts)) {
        // A series with nothing published yet has no public page
        handle404($common);
        return;
    }

    // Chapter headings: printed where series_part changes, and above the first row of every page
    $prevPart = null;
    foreach ($posts as $i => &$p) {
        $part = ($p['series_part'] ?? '') !== '' ? (string)$p['series_part'] : null;
        $p['part_heading'] = $part !== null && ($i === 0 || $part !== $prevPart) ? $part : null;
        $prevPart = $part;
    }
    unset($p);

    $series['status'] = in_array($series['status'], ['ongoing', 'completed', 'hiatus'], true) ? $series['status'] : 'ongoing';
    // No cover of its own: the first post's featured image stands in for og:image
    if ((string)($series['cover_image'] ?? '') === '' && $page === 1) {
        $series['cover_image'] = $posts[0]['featured_image'] ?? null;
    }
    // First post of this page: the reader's "start here" on page 1
    $firstUrl = (($lang === ($common['defaultLang'] ?? '')) ? '' : "/{$lang}") . '/post/' . $posts[0]['slug'];

    renderWithFpc($fpcKey, 'series', array_merge($common, $listing, compact('series', 'posts', 'firstUrl')));
}

function handleTag(array $resolve, array $common, ?string $fpcKey, int $pageSize): void
{
    $lang    = $resolve['lang'];
    $tagSlug = $resolve['slug'];
    $page    = $resolve['page'];

    $tag = DB::fetchOne("SELECT * FROM tags WHERE slug=? AND type='post' AND lang=? LIMIT 1", [$tagSlug, $lang]);
    if (!$tag) {
        handle404($common);
        return;
    }

    // Driven entirely by post_tags' denormalized listing keys, so idx_post_tags_list satisfies
    // both the filter and the ORDER BY. Previously the sort was on posts.published_at, which no index
    // could cover from this join — every page view filesorted the tag's whole post set to return ten
    // rows. p.status is still checked as a safety net: if the denormalized copy ever drifts, the
    // worst case is a missing row, never an unpublished one leaking out.
    $offset = ($page - 1) * $pageSize;
    $posts  = DB::fetchAll(
        "SELECT p.id, p.user_id, p.title, p.slug, p.type, p.lang, p.content_html,
                p.tags_cache, p.category_id, p.comment_count, p.published_at,
                p.featured_image, p.featured_image_alt
         FROM post_tags pt
         JOIN posts p ON p.id = pt.post_id
         WHERE pt.tag_id=? AND pt.type='post' AND pt.lang=? AND pt.status='published'
           AND p.status='published'
         ORDER BY pt.published_at DESC LIMIT ? OFFSET ?",
        [(int)$tag['id'], $lang, $pageSize + 1, $offset]
    );
    $listing = listingViewData($resolve, $common, count($posts) > $pageSize);
    $posts   = array_slice($posts, 0, $pageSize);
    if ($page > 1 && empty($posts)) {
        handle404($common);
        return;
    }
    $posts        = resolveAuthors($posts);
    $sidebarRight = SidebarCompiler::loadSidebar('sidebar_right', $lang);

    renderWithFpc($fpcKey, 'tag', array_merge($common, $listing, compact('tag', 'posts', 'sidebarRight')));
}

function handleArchive(array $resolve, array $common, ?string $fpcKey, int $pageSize): void
{
    $lang   = $resolve['lang'];
    $month  = $resolve['ym'];
    $page   = $resolve['page'];

    // archive is based on published_at (publish month).
    // Expressed as a half-open range instead of DATE_FORMAT(published_at) = ?. Wrapping the
    // column in a function makes the predicate non-sargable, so idx_posts_lang_list could not be
    // used and every archive page scanned the whole published set. The range form uses the index
    // and reads only the rows of that month. Comparison stays on the stored UTC value, so the
    // month boundaries are identical to the old behaviour.
    $range = monthRange($month);
    if ($range === null) {
        handle404($common);
        return;
    }
    [$monthStart, $monthEnd] = $range;

    $offset = ($page - 1) * $pageSize;
    $posts  = DB::fetchAll(
        "SELECT id, user_id, title, slug, type, lang, content_html, tags_cache, category_id, comment_count, published_at, featured_image, featured_image_alt
         FROM posts WHERE status='published' AND type='post' AND lang=?
           AND published_at >= ? AND published_at < ?
         ORDER BY published_at DESC LIMIT ? OFFSET ?",
        [$lang, $monthStart, $monthEnd, $pageSize + 1, $offset]
    );
    $listing = listingViewData($resolve, $common, count($posts) > $pageSize);
    $posts   = array_slice($posts, 0, $pageSize);
    if ($page > 1 && empty($posts)) {
        handle404($common);
        return;
    }
    $posts        = resolveAuthors($posts);
    $sidebarRight = SidebarCompiler::loadSidebar('sidebar_right', $lang);

    renderWithFpc($fpcKey, 'archive', array_merge($common, $listing, compact('month', 'posts', 'sidebarRight')));
}

/**
 * Public search runs server-side against the DB's own index (see core/PostSearch.php).
 *
 * Two distinct pages share this handler:
 *   - /search with no q: the bare search form. Static, so it is baked into the FPC as before.
 *   - /search?q=...:     a result page. Never cached — the query space is unbounded, so caching it
 *                        would let anyone fill the disk with one file per query string.
 *
 * The result page is the only public route that touches the DB on every request. That is deliberate
 * and is scoped to this branch alone; every other route keeps its zero-DB FPC fast path.
 */
function handleSearch(array $resolve, array $common, ?string $fpcKey, int $pageSize): void
{
    $lang = $resolve['lang'];

    require_once CORE_PATH . '/PostSearch.php';

    if (!PostSearch::enabled()) {
        handle404($common);   // sets the 404 status itself
        return;
    }

    $sidebarRight = SidebarCompiler::loadSidebar('sidebar_right', $lang);
    $rawQuery     = isset($_GET['q']) ? (string)$_GET['q'] : '';

    // No query: render (and cache) the static form. Passing $fpcKey through is safe here because
    // the output does not depend on any request parameter.
    if (trim($rawQuery) === '') {
        // The bare form is a real, indexable page, so it gets a canonical of its own.
        $prefix = $lang === ($common['defaultLang'] ?? '') ? '' : "/{$lang}";
        renderWithFpc($fpcKey, 'search', array_merge($common, [
            'sidebarRight' => $sidebarRight,
            'canonical'    => rtrim((string)($common['siteUrl'] ?? ''), '/') . $prefix . '/search',
            'searched'     => false,
            'query'        => '',
            'posts'        => [],
            'page'         => 1,
            'totalPages'   => 1,
            'hasNext'      => false,
            'tooShort'     => false,
            'minChars'     => PostSearch::minChars(),
        ]));
        return;
    }

    $page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $result = PostSearch::search($rawQuery, $lang, $page, $pageSize);

    $result['posts'] = resolveAuthors($result['posts']);

    // Keep crawlers out of the query space (they would otherwise enumerate it and each hit is a DB query).
    header('X-Robots-Tag: noindex, follow');

    // fpcKey is deliberately null: result pages are never written to the FPC.
    renderWithFpc(null, 'search', array_merge($common, [
        'sidebarRight' => $sidebarRight,
        // No canonical on a result page: it is noindex, and the URL space is unbounded.
        'canonical'    => '',
        'searched'     => true,
        'query'        => $result['query'],
        'posts'        => $result['posts'],
        'page'         => $result['page'],
        'totalPages'   => $result['totalPages'],
        'hasNext'      => $result['hasNext'],
        'tooShort'     => $result['tooShort'],
        'minChars'     => PostSearch::minChars(),
    ]));
}

/**
 * serve the precompiled feed file via readfile (with Auto-Healing)
 *
 * If the static file exists, serve it immediately with zero DB.
 * If it does not exist, generate it with FeedCompiler and then serve it (Auto-Healing).
 *
 * @param string $format 'atom' or 'rss'
 */
function handleFeedFile(string $lang, string $format, string $defaultLang): void
{
    require_once CORE_PATH . '/FeedCompiler.php';

    // FEED_ENABLED / FEED_FORMAT check (404 for formats that are not generated)
    if (!Env::bool('FEED_ENABLED', true) || !in_array($format, FeedCompiler::formats(), true)) {
        http_response_code(404);
        exit;
    }

    // Determine the physical file path
    $feedDir  = ($lang === $defaultLang) ? PUBLIC_PATH : PUBLIC_PATH . "/{$lang}";
    $filePath = "{$feedDir}/feed." . FeedCompiler::FORMATS[$format][0];

    // Auto-Healing: if the file does not exist, generate it with FeedCompiler
    if (!is_file($filePath)) {
        FeedCompiler::compile($lang);
    }
    serveFeedFile($filePath, $format);
}

/** A category feed (only categories with feed_enabled have one). */
/** @param string $kind 'category' or 'series' */
function handleListFeedFile(string $kind, string $lang, string $slug, string $format): void
{
    require_once CORE_PATH . '/FeedCompiler.php';
    if (!Env::bool('FEED_ENABLED', true) || !in_array($format, FeedCompiler::formats(), true)) {
        http_response_code(404);
        exit;
    }
    $filePath = FeedCompiler::listFile($kind, $lang, $slug, $format);
    if (!is_file($filePath)) {
        // Auto-Healing only for a real, enabled category or series: an unknown slug must not trigger a rebuild
        $owner = $kind === 'series'
            ? DB::fetchOne('SELECT id FROM series WHERE lang = ? AND slug = ? AND feed_enabled = TRUE LIMIT 1', [$lang, $slug])
            : DB::fetchOne("SELECT id FROM categories WHERE type = 'post' AND lang = ? AND slug = ? AND feed_enabled = TRUE LIMIT 1", [$lang, $slug]);
        if ($owner) {
            FeedCompiler::compile($lang);
        }
    }
    serveFeedFile($filePath, $format);
}

function serveFeedFile(string $filePath, string $format): void
{
    if (!is_file($filePath)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . FeedCompiler::FORMATS[$format][1] . '; charset=UTF-8');
    SecurityHeaderCompiler::emit('frontend');
    readfile($filePath);
    exit;
}

function handlePlugin(string $pluginId, string $lang, array $cfg): void
{
    // Get plugin info from the plugin cache
    $pluginRoutesFile = STORAGE_PATH . '/cache/plugin_routes.cache.php';
    if (!is_file($pluginRoutesFile)) {
        render404(['lang' => $lang, 'siteName' => Env::get('SITE_NAME', 'HBP')]);
        return;
    }
    $routes = require $pluginRoutesFile;

    // Resolve the plugin route definition by plugin_id
    $def = null;
    // Normal route: search by plugin_id in plugin_routes.cache.php
    foreach ($routes as $routePath => $routeDef) {
        if (str_starts_with((string)$routePath, '__template__')) continue;
        if (is_array($routeDef) && ($routeDef['plugin_id'] ?? '') === $pluginId) {
            $def = $routeDef;
            break;
        }
    }
    // page_template route: search the __template__{id} key
    if ($def === null && isset($routes['__template__' . $pluginId])) {
        $def = $routes['__template__' . $pluginId];
    }

    if (!$def || empty($def['file']) || empty($def['class']) || empty($def['method'])) {
        render404(['lang' => $lang, 'siteName' => Env::get('SITE_NAME', 'HBP')]);
        return;
    }

    if (!file_exists($def['file'])) {
        render404(['lang' => $lang, 'siteName' => Env::get('SITE_NAME', 'HBP')]);
        return;
    }

    require_once $def['file'];
    $class  = $def['class'];
    $method = $def['method'];

    if (!class_exists($class) || !method_exists($class, $method)) {
        render404(['lang' => $lang, 'siteName' => Env::get('SITE_NAME', 'HBP')]);
        return;
    }

    $context = ['lang' => $lang, 'plugin_id' => $pluginId];
    $class::$method($context);
}

function handleSpecial(string $endpoint): void
{
    if ($endpoint === 'csp_report') {
        // Since this is an unauthenticated endpoint, prevent an attack that fills the disk with huge POSTs:
        //   1) Limit the read to the first 8KB (CSP reports are a few hundred bytes to a few KB)
        //   2) Do not append if the log exceeds the limit (default 5MB) (simple rotation; rotation is on the operator)
        //   3) Strip newlines to guarantee one report = one line (prevents log pollution)
        $data    = (string)file_get_contents('php://input', false, null, 0, 8192);
        $data    = str_replace(["\r", "\n"], ' ', $data);
        $logFile = STORAGE_PATH . '/logs/csp.log';
        $maxSize = 5 * 1024 * 1024;
        if (!is_file($logFile) || filesize($logFile) < $maxSize) {
            @file_put_contents($logFile, date('Y-m-d H:i:s') . ' ' . $data . "\n", FILE_APPEND | LOCK_EX);
        }
        http_response_code(204);
        return;
    }

    // serve the default-language feed (registered as special since it is language-independent)
    if ($endpoint === 'feed_atom' || $endpoint === 'feed_rss') {
        $format      = $endpoint === 'feed_atom' ? 'atom' : 'rss';
        $defaultLang = Env::get('SITE_DEFAULT_LANG', 'en');
        handleFeedFile($defaultLang, $format, $defaultLang);
        return;
    }

    // the firing endpoint for SCHEDULE_MODE=manual (/cron)
    // Hit periodically from an external monitoring service (cron-job.org, etc.) to publish scheduled posts
    if ($endpoint === 'cron_run') {
        require_once CORE_PATH . '/Scheduler.php';
        require_once CORE_PATH . '/CacheManager.php';
        require_once CORE_PATH . '/SiteFileCompiler.php';
        require_once CORE_PATH . '/FeedCompiler.php';
        require_once CORE_PATH . '/SidebarCompiler.php';
        require_once CORE_PATH . '/TranslationCompiler.php';
        Scheduler::runDue();
        header('Content-Type: text/plain; charset=UTF-8');
        echo "OK\n";
        return;
    }

    http_response_code(404);
}

function handle404(array $common): void
{
    http_response_code(404);
    View::render('404', $common);
}

function render404(array $data): void
{
    View::render('404', $data);
}

/**
 * How many listing pages are reachable. Pages past this 404 (see step (6) above), so the "next"
 * link has to stop here too — otherwise the last page of a long listing links straight into a 404.
 * A series' table of contents has its own depth (Series::tocDepth()), so it can list a whole series.
 */
function listCap(array $resolve = []): int
{
    if (($resolve['kind'] ?? '') === 'series') {
        require_once CORE_PATH . '/Series.php';
        return Series::tocDepth();
    }
    return max(1, Env::int('LIST_FPC_DEPTH', 10));
}

/**
 * The relative path a route canonically lives at, without any /page/N suffix.
 * Returns null for routes that have no canonical URL of their own (search results, 404).
 *
 * This is the one place that knows a route's URL shape, so pagination links and the canonical tag
 * cannot drift apart.
 */
function routeBasePath(array $resolve, string $defaultLang): ?string
{
    $lang   = $resolve['lang'] ?? $defaultLang;
    $prefix = $lang === $defaultLang ? '' : "/{$lang}";

    return match ($resolve['kind'] ?? '') {
        'home'     => $prefix,
        'category' => $prefix . '/post/category/' . ($resolve['slug'] ?? ''),
        'tag'      => $prefix . '/post/tag/'      . ($resolve['slug'] ?? ''),
        'archive'  => $prefix . '/post/archive/'  . ($resolve['ym']   ?? ''),
        'series'   => $prefix . '/post/series/'   . ($resolve['slug'] ?? ''),
        'post'     => $prefix . '/post/'          . ($resolve['slug'] ?? ''),
        'page'     => $prefix . '/page/'          . ($resolve['slug'] ?? ''),
        default    => null,
    };
}

/**
 * Absolute self-referencing canonical URL, '' when the route should not advertise one.
 *
 * Page 2 canonicalizes to *itself*, never back to page 1: pointing deep pages at page 1 is the
 * classic way to get everything past page 1 dropped from the index. Search results get '' because
 * they are noindex and their URL space is unbounded.
 */
function buildCanonical(array $resolve, string $siteUrl, string $defaultLang): string
{
    $base = routeBasePath($resolve, $defaultLang);
    if ($base === null) {
        return '';
    }
    $page = (int)($resolve['page'] ?? 1);
    $path = $page > 1 ? $base . '/page/' . $page : $base;

    return rtrim($siteUrl, '/') . ($path === '' ? '/' : $path);
}

/**
 * View data every paginated listing needs.
 *
 * @param bool $rawHasNext whether the query found more rows than the page holds, before capping
 */
function listingViewData(array $resolve, array $common, bool $rawHasNext): array
{
    $page        = (int)($resolve['page'] ?? 1);
    $defaultLang = (string)($common['defaultLang'] ?? '');
    $hasNext     = $rawHasNext && $page < listCap($resolve);

    return [
        'page'     => $page,
        'hasNext'  => $hasNext,
        // Retained for themes that still read it. It was never a real total (counting one would
        // cost a COUNT(*) over the whole listing); it means "is there one more page after this".
        'totalPages' => $page + ($hasNext ? 1 : 0),
        'pageBase'   => routeBasePath($resolve, $defaultLang) ?? '',
        'canonical'  => buildCanonical($resolve, (string)($common['siteUrl'] ?? ''), $defaultLang),
    ];
}

/**
 * Turn a 'YYYY-MM' archive key into a half-open [start, end) datetime pair.
 * Returns null for a month the router's loose \d{4}-\d{2} pattern allows but that does not exist
 * (e.g. 2026-13), which the caller answers with a 404.
 *
 * @return array{0:string,1:string}|null
 */
function monthRange(string $ym): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
        return null;
    }
    $year  = (int)$m[1];
    $month = (int)$m[2];
    if ($month < 1 || $month > 12) {
        return null;
    }
    $start = (new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), new DateTimeZone('UTC')));
    return [$start->format('Y-m-d H:i:s'), $start->modify('+1 month')->format('Y-m-d H:i:s')];
}

/**
 * resolve the author profile for each post in a listing query result.
 * Zero JOIN, zero N+1 (only static array access into authors.cache.php).
 */
function resolveAuthors(array $posts): array
{
    foreach ($posts as &$post) {
        $post['author'] = AuthorCompiler::get($post['user_id'] ?? null);
    }
    return $posts;
}

function renderWithFpc(?string $fpcKey, string $viewName, array $data): void
{
    if ($fpcKey !== null) {
        // Both the page and its lock live under a two-level shard (see CacheManager::pagePath).
        $fpcFile  = CacheManager::pagePath($fpcKey);
        $lockPath = CacheManager::lockPath($fpcKey);

        // stampede lock
        CacheManager::ensureDirFor($lockPath);
        $fp = fopen($lockPath, 'c');

        if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
            // Winner: double-check
            if (is_file($fpcFile)) {
                flock($fp, LOCK_UN);
                fclose($fp);
                if (!CacheManager::sendValidators($fpcFile)) {
                    readfile($fpcFile);
                }
                exit;
            }
            ob_start();
            View::render($viewName, $data);
            $html = expandBlocks((string)ob_get_clean());

            // Atomic write (temp->rename)
            CacheManager::ensureDirFor($fpcFile);
            $tmp = $fpcFile . '.' . getmypid() . '.tmp';
            file_put_contents($tmp, $html, LOCK_EX);
            rename($tmp, $fpcFile);

            flock($fp, LOCK_UN);
            fclose($fp);
            if (!CacheManager::sendValidators($fpcFile)) {
                echo $html;
            }
        } else {
            // Loser: wait up to 3 seconds, then readfile
            if ($fp) {
                fclose($fp);
            }
            $deadline = microtime(true) + 3.0;
            while (microtime(true) < $deadline) {
                if (is_file($fpcFile)) {
                    if (!CacheManager::sendValidators($fpcFile)) {
                        readfile($fpcFile);
                    }
                    exit;
                }
                usleep(50000);
            }
            // On timeout, generate it ourselves
            ob_start();
            View::render($viewName, $data);
            echo expandBlocks((string)ob_get_clean());
        }
    } else {
        ob_start();
        View::render($viewName, $data);
        echo expandBlocks((string)ob_get_clean());
    }
    exit;
}

/** Replace reusable-block markers ({{ block:slug }}) in a generated page; see BlockCompiler. */
function expandBlocks(string $html): string
{
    if (!str_contains($html, '<!--hbp:block:')) {
        return $html;
    }
    require_once CORE_PATH . '/BlockCompiler.php';
    return BlockCompiler::expand($html, defined('CURRENT_LANG') ? CURRENT_LANG : '');
}
