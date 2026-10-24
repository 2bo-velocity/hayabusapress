<?php
/**
 * HayabusaPress - Sidebar widget HTML compiler (per language).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Widget areas: the two classic sidebars plus header_below / content_before / content_after /
 * footer. Each area is compiled to one HTML string per content language at save time; pages read
 * it through loadSidebar() / areaFor(), so the full-page cache carries it and nothing is queried
 * per request.
 *
 * compileLayout() reports whether the HTML changed. Every cached page embeds the areas, so a caller
 * that sees a change purges the whole page cache; an unchanged area costs nothing.
 */
class SidebarCompiler
{
    /** All widget areas. The single source of truth for the admin screen and the themes. */
    public const LOCATIONS = ['sidebar_right', 'sidebar_left', 'header_below', 'content_before', 'content_after', 'footer'];

    /** All widget types (sidebar_widgets.widget_key). */
    public const WIDGET_KEYS = [
        'search', 'recent_comments', 'archive', 'categories', 'tags',
        'recent_posts', 'posts_pick', 'text', 'image', 'html', 'menu',
    ];

    /** Upper bound for recent_posts.count and the number of posts_pick ids. */
    public const LIST_MAX = 20;

    /**
     * Upper bound (characters) for text / html bodies. settings is a MySQL TEXT (65,535 bytes):
     * 15,000 characters of 3-byte text plus JSON escaping stays under it.
     */
    public const BODY_MAX = 15000;

    /** Bodies of every 'html' widget, read by SecurityHeaderCompiler to hash their inline scripts. */
    public const HTML_SOURCES_CACHE = STORAGE_PATH . '/cache/widget_html_sources.cache.php';

    /** The 1.0.0 sidebars keep their always-present <aside> wrapper; the newer areas print nothing when empty. */
    private const CLASSIC = ['sidebar_right', 'sidebar_left'];

    /** Compile the recent-comments widget (latest 5 approved, per lang) */
    public static function compileRecentCommentsWidget(string $lang = ''): void
    {
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $lang = $lang !== '' ? $lang : $defaultLang;

        $rows = DB::fetchAll(
            "SELECT c.author_name, c.content, p.title AS post_title, p.type, p.slug, p.lang
             FROM comments c
             JOIN posts p ON p.id = c.post_id
             WHERE c.status = 'approved' AND p.lang = ?
             ORDER BY c.created_at DESC
             LIMIT 5",
            [$lang]
        );
        $export = var_export($rows, true);
        file_put_contents(
            STORAGE_PATH . "/cache/widget_comments_{$lang}.cache.php",
            "<?php\nreturn {$export};\n",
            LOCK_EX
        );
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(STORAGE_PATH . "/cache/widget_comments_{$lang}.cache.php", true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    /** Compile the archive widget (per-month post counts, per lang) */
    public static function compileArchiveWidget(string $lang = ''): void
    {
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $lang = $lang !== '' ? $lang : $defaultLang;

        // archive is based on published_at (publish month).
        // Bounded to the most recent ARCHIVE_WIDGET_MONTHS months (default 24). The widget only
        // ever renders that many entries, but the unbounded form still had to aggregate every
        // published row to find them — and it ran on every save. The lower bound turns the
        // aggregation into an index range scan over just those months.
        $months = class_exists('Env') ? Env::int('ARCHIVE_WIDGET_MONTHS', 24) : 24;
        $months = max(1, min($months, 600));
        $since  = (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('UTC')))
            ->modify('-' . ($months - 1) . ' month')
            ->format('Y-m-d H:i:s');

        $expr = DB::driver() === 'pgsql'
            ? "TO_CHAR(published_at, 'YYYY-MM')"
            : "DATE_FORMAT(published_at, '%Y-%m')";
        $sql  = "SELECT {$expr} AS month, COUNT(*) AS cnt
                 FROM posts
                 WHERE status = 'published' AND type = 'post' AND lang = ?
                   AND published_at >= ?
                 GROUP BY {$expr} ORDER BY month DESC LIMIT {$months}";

        $rows = DB::fetchAll($sql, [$lang, $since]);
        $export = var_export($rows, true);
        file_put_contents(
            STORAGE_PATH . "/cache/widget_archive_{$lang}.cache.php",
            "<?php\nreturn {$export};\n",
            LOCK_EX
        );
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(STORAGE_PATH . "/cache/widget_archive_{$lang}.cache.php", true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    /**
     * Compile one area's HTML (per lang).
     *
     * @return bool true when the compiled HTML differs from what was cached before
     */
    public static function compileLayout(string $location, string $lang = ''): bool
    {
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $lang = $lang !== '' ? $lang : $defaultLang;
        $__  = class_exists('HbpLocale') ? HbpLocale::loadFrontendDictionary($lang) : [];

        $widgets = DB::fetchAll(
            "SELECT widget_key, title, settings FROM sidebar_widgets WHERE sidebar_location = ? ORDER BY sort_order ASC, id ASC",
            [$location]
        );

        $inner = '';
        foreach ($widgets as $w) {
            $settings = json_decode((string)($w['settings'] ?? ''), true);
            $inner   .= self::renderWidget(
                (string)$w['widget_key'],
                isset($w['title']) && $w['title'] !== '' ? (string)$w['title'] : null,
                is_array($settings) ? $settings : [],
                $lang,
                $__
            );
        }

        $loc = htmlspecialchars($location, ENT_QUOTES, 'UTF-8');
        if (in_array($location, self::CLASSIC, true)) {
            $html = '<aside class="hbp-sidebar hbp-sidebar-' . $loc . '">' . $inner . '</aside>';
        } else {
            $html = $inner === '' ? '' : '<div class="hbp-area hbp-area-' . $loc . '">' . $inner . '</div>';
        }

        $cache = self::cachePath($location, $lang);
        $old   = is_file($cache) ? require $cache : null;
        file_put_contents($cache, "<?php\nreturn " . var_export($html, true) . ";\n", LOCK_EX);
        self::forgetCompiled($cache);
        return $old !== $html;
    }

    /**
     * Compile every area for one language.
     *
     * @return bool true when any area changed (the caller should purge the whole page cache)
     */
    public static function compileAllLayouts(string $lang = ''): bool
    {
        $changed = false;
        foreach (self::LOCATIONS as $location) {
            $changed = self::compileLayout($location, $lang) || $changed;
        }
        return $changed;
    }

    /**
     * Collect the bodies of all 'html' widgets for SecurityHeaderCompiler (language independent).
     * Call before SecurityHeaderCompiler::compile() whenever widgets are saved.
     */
    public static function compileHtmlSources(): void
    {
        $bodies = [];
        foreach (DB::fetchAll("SELECT settings FROM sidebar_widgets WHERE widget_key = 'html' ORDER BY id") as $row) {
            $settings = json_decode((string)($row['settings'] ?? ''), true);
            $body     = is_array($settings) ? self::lf((string)($settings['body'] ?? '')) : '';
            if ($body !== '') {
                $bodies[] = $body;
            }
        }
        file_put_contents(self::HTML_SOURCES_CACHE, "<?php\nreturn " . var_export($bodies, true) . ";\n", LOCK_EX);
        self::forgetCompiled(self::HTML_SOURCES_CACHE);
    }

    /**
     * Area HTML for a theme, honoring the page's layout: '' when the layout does not show the area.
     * Self-contained (themes call it from the template), so plugin page templates that include the
     * theme header/footer get the areas without the caller passing them in.
     *
     * @param array|null $layout from ThemeManager::layout(); null = the theme's default layout
     */
    public static function areaFor(string $location, string $lang = '', ?array $layout = null): string
    {
        return self::layoutHas($location, $layout) ? self::loadSidebar($location, $lang) : '';
    }

    /** Whether a layout shows an area. null = the active theme's default layout. */
    public static function layoutHas(string $location, ?array $layout = null): bool
    {
        if ($layout === null) {
            $layout = class_exists('ThemeManager') ? ThemeManager::layout(null) : ['areas' => self::LOCATIONS];
        }
        return in_array($location, (array)($layout['areas'] ?? []), true);
    }

    /**
     * @param string|null $title   heading override (sidebar_widgets.title); null = the localized default
     * @param array       $settings decoded sidebar_widgets.settings
     */
    private static function renderWidget(string $key, ?string $title, array $settings, string $lang, array $__): string
    {
        // The classic widgets read their heading from the dictionary; an override just replaces that entry.
        $classicHeading = ['recent_comments' => 'recent_comments', 'archive' => 'archive', 'categories' => 'categories', 'tags' => 'tags'];
        if ($title !== null && isset($classicHeading[$key])) {
            $__[$classicHeading[$key]] = $title;
        }

        return match ($key) {
            'search'          => self::widgetSearch($lang, $__),
            'recent_comments' => self::widgetRecentComments($lang, $__),
            'archive'         => self::widgetArchive($lang, $__),
            'categories'      => self::widgetCategories($lang, $__),
            'tags'            => self::widgetTags($lang, $__),
            'recent_posts'    => self::widgetRecentPosts($title ?? ($__['recent_posts'] ?? 'Recent Posts'), $settings, $lang),
            'posts_pick'      => self::widgetPostsPick($title, $settings, $lang),
            'text'            => self::widgetText($title, $settings),
            'image'           => self::widgetImage($title, $settings),
            'html'            => self::widgetHtml($title, $settings),
            'menu'            => self::widgetMenu($title, $settings),
            default           => '',
        };
    }

    private static function widgetSearch(string $lang, array $__): string
    {
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $action = $lang === $defaultLang ? '/search' : "/{$lang}/search";
        $placeholder = htmlspecialchars($__['search_placeholder'] ?? 'Search...', ENT_QUOTES, 'UTF-8');
        $button      = htmlspecialchars($__['search_button']      ?? 'Search',   ENT_QUOTES, 'UTF-8');
        return '<div class="widget widget-search">'
            . '<form method="get" action="' . $action . '">'
            . '<input type="search" name="q" placeholder="' . $placeholder . '">'
            . '<button type="submit">' . $button . '</button>'
            . '</form></div>';
    }

    private static function widgetRecentComments(string $lang, array $__): string
    {
        $cache = STORAGE_PATH . "/cache/widget_comments_{$lang}.cache.php";
        $rows  = file_exists($cache) ? require $cache : [];
        if (empty($rows)) return '';
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $heading = htmlspecialchars($__['recent_comments'] ?? 'Recent Comments', ENT_QUOTES, 'UTF-8');
        $html = '<div class="widget widget-recent-comments"><h3>' . $heading . '</h3><ul>';
        foreach ($rows as $r) {
            $rowLang = $r['lang'] ?? $lang;
            $prefix  = $rowLang === $defaultLang ? '' : "/{$rowLang}";
            $href    = htmlspecialchars("{$prefix}/post/{$r['slug']}", ENT_QUOTES, 'UTF-8');
            $name    = htmlspecialchars($r['author_name'], ENT_QUOTES, 'UTF-8');
            $title   = htmlspecialchars($r['post_title'],  ENT_QUOTES, 'UTF-8');
            $html   .= "<li><a href=\"{$href}\">{$name} - {$title}</a></li>";
        }
        $html .= '</ul></div>';
        return $html;
    }

    private static function widgetArchive(string $lang, array $__): string
    {
        $cache = STORAGE_PATH . "/cache/widget_archive_{$lang}.cache.php";
        $rows  = file_exists($cache) ? require $cache : [];
        if (empty($rows)) return '';
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $prefix  = $lang === $defaultLang ? '' : "/{$lang}";
        $heading = htmlspecialchars($__['archive'] ?? 'Archive', ENT_QUOTES, 'UTF-8');
        $html = '<div class="widget widget-archive"><h3>' . $heading . '</h3><ul>';
        foreach ($rows as $r) {
            $slug  = htmlspecialchars($r['month'], ENT_QUOTES, 'UTF-8');                    // URL slug: raw YYYY-MM
            $label = htmlspecialchars(Format::yearMonth($r['month']), ENT_QUOTES, 'UTF-8'); // display: ARCHIVE_MONTH_FORMAT
            $cnt   = (int)$r['cnt'];
            $html .= "<li><a href=\"{$prefix}/post/archive/{$slug}\">{$label} ({$cnt})</a></li>";
        }
        $html .= '</ul></div>';
        return $html;
    }

    private static function widgetCategories(string $lang, array $__): string
    {
        $cache = STORAGE_PATH . '/cache/categories.cache.php';
        $cats  = file_exists($cache) ? require $cache : [];
        if (empty($cats)) return '';
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $prefix  = $lang === $defaultLang ? '' : "/{$lang}";
        $heading = htmlspecialchars($__['categories'] ?? 'Categories', ENT_QUOTES, 'UTF-8');
        $html = '<div class="widget widget-categories"><h3>' . $heading . '</h3><ul>';
        foreach ($cats as $c) {
            if (($c['lang'] ?? '') !== $lang) continue;
            if ((int)($c['post_count'] ?? 0) === 0) continue;
            $slug = htmlspecialchars("{$prefix}/post/category/{$c['slug']}", ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($c['name'],                        ENT_QUOTES, 'UTF-8');
            $cnt  = (int)($c['post_count'] ?? 0);
            $html .= "<li><a href=\"{$slug}\">{$name} ({$cnt})</a></li>";
        }
        $html .= '</ul></div>';
        return $html;
    }

    private static function widgetTags(string $lang, array $__): string
    {
        $cache = STORAGE_PATH . '/cache/tags.cache.php';
        $tags  = file_exists($cache) ? require $cache : [];
        if (empty($tags)) return '';
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $prefix  = $lang === $defaultLang ? '' : "/{$lang}";
        $heading = htmlspecialchars($__['tags'] ?? 'Tags', ENT_QUOTES, 'UTF-8');
        $html = '<div class="widget widget-tags"><h3>' . $heading . '</h3><ul>';
        foreach ($tags as $t) {
            if (($t['lang'] ?? '') !== $lang) continue;
            if ((int)($t['post_count'] ?? 0) === 0) continue;
            $slug = htmlspecialchars("{$prefix}/post/tag/{$t['slug']}", ENT_QUOTES, 'UTF-8');
            $name = htmlspecialchars($t['name'],                   ENT_QUOTES, 'UTF-8');
            $html .= "<li><a href=\"{$slug}\">{$name}</a></li>";
        }
        $html .= '</ul></div>';
        return $html;
    }

    public static function loadSidebar(string $location, string $lang = ''): string
    {
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $lang  = $lang !== '' ? $lang : $defaultLang;
        $cache = self::cachePath($location, $lang);
        if (!file_exists($cache)) {
            self::compileLayout($location, $lang);
        }
        return require $cache;
    }

    /**
     * Drop a rewritten cache file from OPcache. With timestamp revalidation (revalidate_freq, 2 s by
     * default) a page generated right after a save would otherwise still read the old area and bake
     * it into the full-page cache, where it stays until the next purge.
     */
    public static function forgetCompiled(string $path): void
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    private static function cachePath(string $location, string $lang): string
    {
        return STORAGE_PATH . '/cache/sidebar_' . $location . '_' . $lang . '.cache.php';
    }

    // ----------------------------------------------------------------
    // Widgets added in 1.3.0. Settings are validated on save (admin) and again here, because the
    // row is the only input: a hand-edited or stale row must not produce broken or unsafe markup.
    // ----------------------------------------------------------------

    private static function heading(?string $title): string
    {
        return $title === null ? '' : '<h3>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h3>';
    }

    /** Public URL of a post or page. */
    private static function postHref(string $type, string $slug, string $lang): string
    {
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $prefix = $lang === $defaultLang ? '' : "/{$lang}";
        return $prefix . ($type === 'page' ? '/page/' : '/post/') . $slug;
    }

    /** Latest published posts of this language (idx_posts_lang_list). */
    private static function widgetRecentPosts(string $title, array $settings, string $lang): string
    {
        $count = max(1, min(self::LIST_MAX, (int)($settings['count'] ?? 5)));
        $rows  = DB::fetchAll(
            "SELECT type, slug, title FROM posts
             WHERE type = 'post' AND lang = ? AND status = 'published'
             ORDER BY published_at DESC LIMIT {$count}",
            [$lang]
        );
        return self::postList('widget-recent-posts', $title, $rows, $lang);
    }

    /** Hand-picked posts/pages, in the order given. Unpublished or other-language ids are skipped. */
    private static function widgetPostsPick(?string $title, array $settings, string $lang): string
    {
        $ids = array_slice(array_values(array_unique(array_filter(
            array_map('intval', (array)($settings['ids'] ?? [])),
            static fn(int $id): bool => $id > 0
        ))), 0, self::LIST_MAX);
        if (!$ids) {
            return '';
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $found = [];
        foreach (DB::fetchAll(
            "SELECT id, type, slug, title FROM posts WHERE id IN ({$marks}) AND lang = ? AND status = 'published'",
            array_merge($ids, [$lang])
        ) as $row) {
            $found[(int)$row['id']] = $row;
        }
        $rows = [];
        foreach ($ids as $id) {
            if (isset($found[$id])) {
                $rows[] = $found[$id];
            }
        }
        return self::postList('widget-posts-pick', $title, $rows, $lang);
    }

    private static function postList(string $class, ?string $title, array $rows, string $lang): string
    {
        if (!$rows) {
            return '';
        }
        $html = '<div class="widget ' . $class . '">' . self::heading($title) . '<ul>';
        foreach ($rows as $r) {
            $href  = htmlspecialchars(self::postHref((string)$r['type'], (string)$r['slug'], $lang), ENT_QUOTES, 'UTF-8');
            $label = htmlspecialchars((string)$r['title'], ENT_QUOTES, 'UTF-8');
            $html .= "<li><a href=\"{$href}\">{$label}</a></li>";
        }
        return $html . '</ul></div>';
    }

    /** Markdown, compiled with the low-trust profile (no raw HTML) and sanitized. */
    private static function widgetText(?string $title, array $settings): string
    {
        $body = trim((string)($settings['body'] ?? ''));
        if ($body === '') {
            return '';
        }
        require_once CORE_PATH . '/HtmlSanitizer.php';
        require_once CORE_PATH . '/MarkdownCompiler.php';
        return '<div class="widget widget-text">' . self::heading($title)
            . '<div class="widget-text-body">' . MarkdownCompiler::render($body, false) . '</div></div>';
    }

    private static function widgetImage(?string $title, array $settings): string
    {
        $src  = self::safeUrl((string)($settings['src'] ?? ''), false);
        if ($src === '') {
            return '';
        }
        $href = self::safeUrl((string)($settings['href'] ?? ''), true);
        $alt  = htmlspecialchars((string)($settings['alt'] ?? ''), ENT_QUOTES, 'UTF-8');
        $img  = '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="' . $alt . '" loading="lazy" decoding="async">';
        if ($href !== '') {
            $ext = str_starts_with($href, 'http') ? ' target="_blank" rel="noopener"' : '';
            $img = '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $ext . '>' . $img . '</a>';
        }
        return '<div class="widget widget-image">' . self::heading($title) . $img . '</div>';
    }

    /**
     * Raw HTML, printed as is. Only roles with post:raw_html may save it (checked by the admin
     * handler). Inline scripts are allowed by hash: see compileHtmlSources().
     */
    private static function widgetHtml(?string $title, array $settings): string
    {
        // Same LF normalization as compileHtmlSources(), so the printed script matches its hash.
        $body = self::lf((string)($settings['body'] ?? ''));
        return $body === '' ? '' : '<div class="widget widget-html">' . self::heading($title) . $body . '</div>';
    }

    private static function widgetMenu(?string $title, array $settings): string
    {
        require_once CORE_PATH . '/MenuCompiler.php';
        $location = (string)($settings['location'] ?? '');
        if (!in_array($location, MenuCompiler::LOCATIONS, true)) {
            return '';
        }
        $menu = MenuCompiler::load()[$location] ?? '';
        return $menu === '' ? '' : '<div class="widget widget-menu">' . self::heading($title) . $menu . '</div>';
    }

    private static function lf(string $s): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $s));
    }

    /** Default settings for a newly added widget. */
    public static function defaultSettings(string $key): ?array
    {
        return match ($key) {
            'recent_posts' => ['count' => 5],
            'menu'         => ['location' => 'pc_footer'],
            default        => null,
        };
    }

    /**
     * Validate admin form input for one widget and return the settings to store (null = none).
     * The renderer re-validates, so this is about keeping the row clean and the form round-tripping.
     *
     * @param array $in raw form fields for this widget
     */
    public static function normalizeSettings(string $key, array $in): ?array
    {
        // Line endings are stored as LF: a textarea posts CRLF, while the browser hashes an inline
        // script after normalizing to LF, so a CRLF body would never match its CSP hash.
        $str = static fn(string $k, int $max): string => mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string)($in[$k] ?? ''))), 0, $max);
        return match ($key) {
            'recent_posts' => ['count' => max(1, min(self::LIST_MAX, (int)($in['count'] ?? 5)))],
            'posts_pick'   => ['ids' => array_slice(array_values(array_unique(array_filter(
                                  array_map('intval', preg_split('/[\s,]+/', (string)($in['ids'] ?? '')) ?: []),
                                  static fn(int $id): bool => $id > 0
                              ))), 0, self::LIST_MAX)],
            'text'         => ['body' => $str('body', self::BODY_MAX)],
            'html'         => ['body' => $str('body', self::BODY_MAX)],
            'image'        => [
                'src'  => self::safeUrl((string)($in['src'] ?? ''), false),
                'alt'  => $str('alt', 255),
                'href' => self::safeUrl((string)($in['href'] ?? ''), true),
            ],
            'menu'         => (static function () use ($in): array {
                require_once CORE_PATH . '/MenuCompiler.php';
                $loc = (string)($in['location'] ?? '');
                return ['location' => in_array($loc, MenuCompiler::LOCATIONS, true) ? $loc : 'pc_footer'];
            })(),
            default        => null,
        };
    }

    /**
     * Accept a site-relative path ("/...", not "//...") or an https:// URL; with $allowHttp, also
     * http:// and "#..." (link targets). Anything else, javascript: included, becomes ''.
     */
    public static function safeUrl(string $url, bool $allowHttp): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20"<>]/', $url)) {
            return '';
        }
        if ($url[0] === '/' && !str_starts_with($url, '//')) {
            return $url;
        }
        if (preg_match('#^https://#i', $url) || ($allowHttp && (preg_match('#^http://#i', $url) || $url[0] === '#'))) {
            return $url;
        }
        return '';
    }
}
