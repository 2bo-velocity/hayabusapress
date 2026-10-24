<?php
/**
 * HayabusaPress - Route precompiler (tiny structural route map).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * route precompiler (de-O(N)).
 *
 * Stores only "structural, finite, irregular routes (plugin/language/special)" as a tiny map
 * in routes.cache.php.
 * "Detailed, numerous, regular routes (posts/pages/categories/tags)" are not triggered on post save;
 * they are resolved by URL pattern matching at request time (public/index.php).
 *
 * As a result, even with a million posts, routes.cache.php stays O(plugins + languages + specials) in size.
 */
class RouterCompiler
{
    private const CACHE_FILE = STORAGE_PATH . '/cache/routes.cache.php';

    /**
     * Recompile the tiny route cache.
     *
     * Trigger timing:
     * - When a plugin is added, enabled, disabled, or its manifest changes (plugins:rebuild)
     * - When a page's plugin-template assignment changes
     * - When SITE_DEFAULT_LANG changes
     * - When a new language-pack file is added or removed
     * - CLI: system:init
     *
     * NOTE: not triggered on post save (the core of).
     */
    public static function compile(): void
    {
        $defaultLang = Env::get('SITE_DEFAULT_LANG', 'en');
        $langs       = array_keys(
            class_exists('HbpLocale') ? HbpLocale::getAvailableLocales() : [$defaultLang => strtoupper($defaultLang)]
        );
        if (!in_array($defaultLang, $langs, true)) {
            $langs[] = $defaultLang;
        }

        $plugins = [];
        $special = ['/csp-report' => 'csp_report'];

        // when FEED_ENABLED=true, register feed endpoints as special endpoints
        // Serve as a static file, or readfile via PHP (shared hosting, etc.)
        // Note: per-language feeds (/{lang}/feed.*.xml) are handled by pattern matching after the lang prefix is stripped
        if (class_exists('Env') && Env::bool('FEED_ENABLED', true)) {
            $special['/feed.atom.xml'] = 'feed_atom';
            $special['/feed.rss.xml']  = 'feed_rss';
        }

        // when SCHEDULE_MODE=manual, a /cron endpoint for firing from external monitoring/manually
        if (class_exists('Env') && Env::get('SCHEDULE_MODE', 'pseudo') === 'manual') {
            $special['/cron'] = 'cron_run';
        }

        // Collect plugin routes (from the routes declaration in manifest.json)
        $pluginRoutesFile = STORAGE_PATH . '/cache/plugin_routes.cache.php';
        if (file_exists($pluginRoutesFile)) {
            $pluginRoutes = require $pluginRoutesFile;
            if (is_array($pluginRoutes)) {
                foreach ($pluginRoutes as $routePath => $routeDef) {
                    if (str_starts_with($routePath, '__template__')) {
                        continue; // Skip template definitions
                    }
                    $plugins[$routePath] = $routeDef['plugin_id'] ?? $routeDef;
                }
            }
        }

        // Page plugin-template assignment (pages where page_template is set)
        try {
            $pages = DB::fetchAll(
                "SELECT lang, slug, page_template FROM posts
                 WHERE type='page' AND status='published' AND page_template IS NOT NULL"
            );
            foreach ($pages as $page) {
                // public/index.php strips the lang prefix before this lookup, and a page detail is
                // served at /page/{slug} for every language. Register the lang-independent
                // /page/{slug} key so the assigned plugin template renders on the real page URL
                // (the plugin renderer is language-aware via CURRENT_LANG).
                $plugins['/page/' . $page['slug']] = $page['page_template'];
            }
        } catch (Throwable) {
        }

        $cfg = [
            'default' => $defaultLang,
            'langs'   => $langs,
            'plugins' => $plugins,
            'special' => $special,
        ];

        $export = var_export($cfg, true);
        $php    = "<?php\n// HBP Compiled Routes ( minimal map) - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n";
        $tmp    = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    /** Load the cache (auto-compile if missing = Auto-Healing). */
    public static function load(): array
    {
        if (!file_exists(self::CACHE_FILE)) {
            self::compile();
        }
        $cfg = require self::CACHE_FILE;
        return is_array($cfg) ? $cfg : ['default' => 'en', 'langs' => ['en'], 'plugins' => [], 'special' => []];
    }
}
