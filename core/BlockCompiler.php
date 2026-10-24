<?php
/**
 * HayabusaPress - Reusable content blocks ({{ block:slug }}).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * A post keeps only the marker <!--hbp:block:slug--> (MarkdownCompiler). The marker is replaced
 * with the block's HTML when the full-page cache is generated, from blocks.cache.php -- no DB query.
 * Editing a block therefore rebuilds this cache and purges the page cache; the posts that use it
 * are not recompiled.
 *
 * Blocks are managed by blocks:manage (Administrator), so their Markdown is compiled with the
 * raw-HTML profile. A block cannot contain another block: markers inside a block are removed.
 */
class BlockCompiler
{
    public const CACHE_FILE = STORAGE_PATH . '/cache/blocks.cache.php';

    /** Slug format, shared with MarkdownCompiler's {{ block:slug }} syntax. */
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /** Upper bound for a block body (characters; blocks.body is a MySQL TEXT). */
    public const BODY_MAX = 15000;

    /** @var array<string, array<string,string>>|null request-local memo: lang => slug => HTML */
    private static ?array $blocks = null;

    /** Compile every block into blocks.cache.php. */
    public static function compile(): void
    {
        require_once CORE_PATH . '/HtmlSanitizer.php';
        require_once CORE_PATH . '/MarkdownCompiler.php';
        $out = [];
        foreach (DB::fetchAll('SELECT slug, lang, body FROM blocks ORDER BY lang, slug') as $row) {
            $html = MarkdownCompiler::render((string)$row['body'], true);
            $out[(string)$row['lang']][(string)$row['slug']] = (string)preg_replace('/<!--hbp:block:[a-z0-9-]+-->/', '', $html);
        }
        file_put_contents(self::CACHE_FILE, "<?php\n// HBP Blocks - " . date('Y-m-d H:i:s') . "\nreturn " . var_export($out, true) . ";\n", LOCK_EX);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
        self::$blocks = $out;
    }

    /**
     * Replace block markers in a rendered page. Called while a page is generated, so on the public
     * side only on a full-page-cache miss. An unknown slug becomes '' (the page still renders).
     */
    public static function expand(string $html, string $lang): string
    {
        if (!str_contains($html, '<!--hbp:block:')) {
            return $html;
        }
        if (self::$blocks === null) {
            if (!is_file(self::CACHE_FILE)) {
                self::compile();   // Auto-Healing
            }
            $data = is_file(self::CACHE_FILE) ? require self::CACHE_FILE : [];
            self::$blocks = is_array($data) ? $data : [];
        }
        $byLang = self::$blocks[$lang] ?? [];
        return (string)preg_replace_callback(
            '/<!--hbp:block:([a-z0-9-]+)-->/',
            static fn(array $m): string => $byLang[$m[1]] ?? '',
            $html
        );
    }
}
