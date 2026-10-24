<?php
/**
 * HayabusaPress - Category cache compiler.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class CategoryCompiler
{
    private const CACHE_FILE = STORAGE_PATH . '/cache/categories.cache.php';

    public static function compile(): void
    {
        // Post_count is a maintained column (see core/Counters.php), not a live aggregate.
        // The old LEFT JOIN + GROUP BY recounted every published post on every save, so the cost of
        // saving one post grew with the size of the site. This is now O(number of categories).
        $cats = DB::fetchAll(
            "SELECT id, type, lang, slug, name, post_count
             FROM categories
             WHERE type = 'post'
             ORDER BY lang, name ASC"
        );

        $export = var_export($cats, true);
        file_put_contents(
            self::CACHE_FILE,
            "<?php\n// HBP Compiled Categories\nreturn {$export};\n",
            LOCK_EX
        );
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    public static function load(): array
    {
        if (!file_exists(self::CACHE_FILE)) {
            self::compile();
        }
        return require self::CACHE_FILE;
    }
}
