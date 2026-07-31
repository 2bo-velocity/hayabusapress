<?php
/**
 * HayabusaPress - Sidebar widget HTML compiler (per language).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class SidebarCompiler
{
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
    }

    /** Compile the full sidebar HTML (per lang) */
    public static function compileLayout(string $location, string $lang = ''): void
    {
        $defaultLang = (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en'));
        $lang = $lang !== '' ? $lang : $defaultLang;
        $__  = class_exists('HbpLocale') ? HbpLocale::loadFrontendDictionary($lang) : [];

        $widgets = DB::fetchAll(
            "SELECT widget_key FROM sidebar_widgets WHERE sidebar_location = ? ORDER BY sort_order ASC",
            [$location]
        );

        $html = '<aside class="hbp-sidebar hbp-sidebar-' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '">';
        foreach ($widgets as $w) {
            $html .= self::renderWidget($w['widget_key'], $lang, $__);
        }
        $html .= '</aside>';

        file_put_contents(
            STORAGE_PATH . '/cache/sidebar_' . $location . '_' . $lang . '.cache.php',
            "<?php\nreturn " . var_export($html, true) . ";\n",
            LOCK_EX
        );
    }

    private static function renderWidget(string $key, string $lang, array $__): string
    {
        return match ($key) {
            'search'          => self::widgetSearch($lang, $__),
            'recent_comments' => self::widgetRecentComments($lang, $__),
            'archive'         => self::widgetArchive($lang, $__),
            'categories'      => self::widgetCategories($lang, $__),
            'tags'            => self::widgetTags($lang, $__),
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
        $cache = STORAGE_PATH . '/cache/sidebar_' . $location . '_' . $lang . '.cache.php';
        if (!file_exists($cache)) {
            self::compileLayout($location, $lang);
        }
        return require $cache;
    }
}
