<?php
/**
 * HayabusaPress - Navigation menu HTML compiler (6 locations).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class MenuCompiler
{
    private const CACHE_FILE = STORAGE_PATH . '/cache/menus.cache.php';

    private const LOCATIONS = [
        'pc_header', 'pc_sidebar_left', 'pc_sidebar_right',
        'pc_footer', 'sp_header', 'sp_footer',
    ];

    /** Compile the menu HTML for all 6 locations at once */
    public static function compile(): void
    {
        $menus = [];
        foreach (self::LOCATIONS as $loc) {
            $items = DB::fetchAll(
                "SELECT title, url FROM navigation_menus WHERE location = ? ORDER BY sort_order ASC",
                [$loc]
            );
            $menus[$loc] = self::buildHtml($items, $loc);
        }

        $export = var_export($menus, true);
        file_put_contents(
            self::CACHE_FILE,
            "<?php\n// HBP Compiled Menus\nreturn {$export};\n",
            LOCK_EX
        );
    }

    private static function buildHtml(array $items, string $location): string
    {
        if (empty($items)) return '';
        $html = '<nav class="hbp-nav hbp-nav-' . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . '"><ul>';
        foreach ($items as $item) {
            $url   = htmlspecialchars($item['url'],   ENT_QUOTES, 'UTF-8');
            $title = htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8');
            $ext   = str_starts_with($item['url'], 'http') ? ' target="_blank" rel="noopener noreferrer"' : '';
            $html .= "<li><a href=\"{$url}\"{$ext}>{$title}</a></li>";
        }
        $html .= '</ul></nav>';
        return $html;
    }

    public static function load(): array
    {
        if (!file_exists(self::CACHE_FILE)) {
            self::compile();
        }
        return require self::CACHE_FILE;
    }

    /** Return per-location menu data (array) */
    public static function loadData(): array
    {
        $data = [];
        foreach (self::LOCATIONS as $loc) {
            $data[$loc] = DB::fetchAll(
                "SELECT id, title, url, sort_order FROM navigation_menus WHERE location = ? ORDER BY sort_order ASC",
                [$loc]
            );
        }
        return $data;
    }

    public static function save(string $location, array $items): void
    {
        DB::execute('DELETE FROM navigation_menus WHERE location = ?', [$location]);
        foreach ($items as $order => $item) {
            DB::execute(
                "INSERT INTO navigation_menus (location, title, url, sort_order, created_at) VALUES (?, ?, ?, ?, NOW())",
                [$location, $item['title'], $item['url'], $order]
            );
        }
        self::compile();
        // Site-wide nav change -> clear all FPC
        self::clearAllFpc();
    }

    private static function clearAllFpc(): void
    {
        // CacheManager walks the sharded tree; glob() on a flat directory no longer finds
        // anything and would silently leave the whole cache stale after a nav change.
        if (!class_exists('CacheManager')) {
            require_once CORE_PATH . '/CacheManager.php';
        }
        CacheManager::purgeAll();
    }
}
