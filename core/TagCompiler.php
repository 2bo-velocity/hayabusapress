<?php
/**
 * HayabusaPress - Tag cache compiler.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class TagCompiler
{
    private const CACHE_FILE = STORAGE_PATH . '/cache/tags.cache.php';

    public static function compile(): void
    {
        // Post_count is a maintained column (see core/Counters.php), not a live aggregate.
        // The old double LEFT JOIN counted every junction row on every save — and it counted rows
        // whose post was not published, because the status filter sat in the join condition of an
        // outer join rather than in a WHERE clause.
        $tags = DB::fetchAll(
            "SELECT id, type, lang, slug, name, post_count
             FROM tags
             WHERE type = 'post'
             ORDER BY lang, name ASC"
        );

        $export = var_export($tags, true);
        file_put_contents(
            self::CACHE_FILE,
            "<?php\n// HBP Compiled Tags\nreturn {$export};\n",
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
