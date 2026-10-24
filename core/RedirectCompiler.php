<?php
/**
 * HayabusaPress - 301 redirects from the old slugs of published posts.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * When a published post's slug changes, the old one is kept in slug_redirects. compile() bakes the
 * rows into a PHP array keyed "{type}/{lang}/{old_slug}" whose value is the post's current path, so
 * a chain of renames always lands on the latest URL and the public side needs no DB query.
 *
 * public/index.php consults the array only on the way to a 404 for a post or page, and never caches
 * the redirect in the FPC.
 */
final class RedirectCompiler
{
    public const CACHE_FILE = STORAGE_PATH . '/cache/redirects.cache.php';

    /** @var array<string,string>|null */
    private static ?array $map = null;

    /**
     * Record a slug change of a post that was public, and forget any old slug the post now uses
     * again. Call before compile().
     */
    public static function recordRename(int $postId, string $type, string $lang, string $oldSlug, string $newType, string $newLang, string $newSlug): void
    {
        // The live post owns its slug: drop a redirect that would shadow it (from any post)
        DB::execute('DELETE FROM slug_redirects WHERE type = ? AND lang = ? AND old_slug = ?', [$newType, $newLang, $newSlug]);
        if ($oldSlug === '' || ($type === $newType && $lang === $newLang && $oldSlug === $newSlug)) {
            return;
        }
        DB::execute('DELETE FROM slug_redirects WHERE type = ? AND lang = ? AND old_slug = ?', [$type, $lang, $oldSlug]);
        DB::execute(
            'INSERT INTO slug_redirects (type, lang, old_slug, post_id, created_at) VALUES (?, ?, ?, ?, ?)',
            [$type, $lang, $oldSlug, $postId, gmdate('Y-m-d H:i:s')]
        );
    }

    /** Old slugs of one post, for the editor. */
    public static function forPost(int $postId): array
    {
        return DB::fetchAll('SELECT id, type, lang, old_slug, created_at FROM slug_redirects WHERE post_id = ? ORDER BY created_at DESC', [$postId]);
    }

    public static function compile(): void
    {
        $default = Env::get('SITE_DEFAULT_LANG', 'en');
        $rows = DB::fetchAll(
            "SELECT r.type, r.lang, r.old_slug, p.type AS cur_type, p.lang AS cur_lang, p.slug AS cur_slug
               FROM slug_redirects r JOIN posts p ON p.id = r.post_id
              WHERE p.status = 'published' AND p.deleted_at IS NULL"
        );
        $map = [];
        foreach ($rows as $r) {
            $prefix = $r['cur_lang'] === $default ? '' : '/' . $r['cur_lang'];
            $map[$r['type'] . '/' . $r['lang'] . '/' . $r['old_slug']] = $prefix . '/' . $r['cur_type'] . '/' . $r['cur_slug'];
        }
        $tmp = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, "<?php\n// HBP Slug Redirects - " . date('Y-m-d H:i:s') . "\nreturn " . var_export($map, true) . ";\n", LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
        self::$map = $map;
    }

    /** Current path for an old slug, or null. Auto-Healing: a missing cache is rebuilt once. */
    public static function lookup(string $type, string $lang, string $slug): ?string
    {
        if (self::$map === null) {
            if (!is_file(self::CACHE_FILE)) {
                self::compile();
            }
            $loaded    = @include self::CACHE_FILE;
            self::$map = is_array($loaded) ? $loaded : [];
        }
        return self::$map["{$type}/{$lang}/{$slug}"] ?? null;
    }
}
