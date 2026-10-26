<?php
/**
 * HayabusaPress - Precise FPC purge mechanism (linked cache invalidation).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Precise FPC purge mechanism (linked cache invalidation)
 *
 * On post update / comment approval, pinpoint-blows away only the FPC of pages that
 * depend on that post. Uses neither a full rebuild nor timed expiry.
 * Regeneration is left to Auto-Healing on the next access.
 */
class CacheManager
{
    private static string $pagesDir = STORAGE_PATH . '/cache/pages';

    /** Stampede locks live outside the pages tree so purging pages never yanks a held lock. */
    private static string $locksDir = STORAGE_PATH . '/cache/fpc_locks';

    /**
     * The single derivation of an FPC key's file path.
     *
     * FPC files used to sit flat in one directory. One file per post, per listing page, per language
     * means a large site puts millions of entries in a single directory: lookups slow down, and
     * purgeAll()'s glob() built an array of every filename in memory before deleting anything.
     * Two levels of 256 buckets, derived from the key's hash exactly like Media::shardPath() does
     * for uploads, keeps each directory small.
     *
     * Callers must never build this path themselves — reader and writer have to agree.
     */
    public static function pagePath(string $key): string
    {
        return self::$pagesDir . '/' . self::shard($key) . '/' . self::safeKey($key) . '.html';
    }

    /** Stampede-lock path for an FPC key. Sharded for the same reason as the pages themselves. */
    public static function lockPath(string $key): string
    {
        return self::$locksDir . '/' . self::shard($key) . '/' . self::safeKey($key) . '.lock';
    }

    /** Two-level bucket derived from the key's hash. */
    private static function shard(string $key): string
    {
        $h = md5($key);
        return substr($h, 0, 2) . '/' . substr($h, 2, 2);
    }

    /**
     * Keys are already built from router-validated segments, so this only guards against a future
     * caller passing something looser. It must stay deterministic: the reader and the writer derive
     * the same path through it.
     */
    private static function safeKey(string $key): string
    {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $key) ?? $key;
    }

    /** Create the parent directory of an FPC/lock file. */
    public static function ensureDirFor(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Pinpoint-purge all FPC that depend on a single post
     *
     * @param array  $post          posts record (includes lang, type, slug, created_at)
     * @param array  $tagSlugs      array of tag slugs linked to the post (from posts.tags_cache)
     * @param string $categorySlug  slug of the owning category
     */
    public static function purgePost(array $post, array $tagSlugs = [], string $categorySlug = ''): void
    {
        $lang = $post['lang'] ?? '';

        // Pages do not appear in listings, so only the page itself
        if ($post['type'] === 'page') {
            self::unlinkIfExists("{$lang}_page_{$post['slug']}.html");
            return;
        }

        // (1) The post itself
        self::unlinkIfExists("{$lang}_post_{$post['slug']}.html");
        // (2) The top page for that language (all pages: home, home_p2, home_p3 ...)
        self::purgeListPages("{$lang}_home");
        // (3) The owning category listing (all pages)
        if ($categorySlug !== '') {
            self::purgeListPages("{$lang}_list_category_{$categorySlug}");
        }
        // (4) Each linked tag listing (all pages)
        foreach ($tagSlugs as $tagSlug) {
            if ($tagSlug !== '') {
                self::purgeListPages("{$lang}_list_tag_{$tagSlug}");
            }
        }
        // (5) The relevant month archive (all pages)
        // archive is based on published_at. Falls back to created_at if published_at is missing
        $dateCol = !empty($post['published_at']) ? $post['published_at'] : ($post['created_at'] ?? '');
        if ($dateCol !== '') {
            $ym = date('Y-m', strtotime($dateCol));
            self::purgeListPages("{$lang}_list_archive_{$ym}");
        }
    }

    /**
     * purge the per-page FPC of listing pages (page 1 + page N).
     * Used because publish/unpublish/delete/category-change of a post shifts the page distribution.
     *
     * @param string   $prefix cache file prefix (e.g. 'ja_home', 'ja_list_category_tech')
     * @param int|null $depth  pages that can exist; null = LIST_FPC_DEPTH (a series' table of
     *                         contents passes its own, see Series::tocDepth())
     */
    public static function purgeListPages(string $prefix, ?int $depth = null): void
    {
        // Page 1 (no suffix)
        self::unlinkIfExists("{$prefix}.html");
        // Page 2 onward
        $cap = $depth ?? (class_exists('Env') ? Env::int('LIST_FPC_DEPTH', 10) : (int)(($_ENV['LIST_FPC_DEPTH'] ?? 10) ?: 10));
        for ($p = 2; $p <= $cap; $p++) {
            self::unlinkIfExists("{$prefix}_p{$p}.html");
        }
    }

    /**
     * purge an author's published-post FPC (uses idx_posts_user).
     * Called on profile edits and staff deletion.
     *
     * @param int|null $userId NULL = Super User (id 0)
     */
    public static function purgeAuthor(int|null $userId): void
    {
        $uid = $userId ?? 0;
        try {
            $posts = DB::fetchAll(
                "SELECT lang, type, slug FROM posts WHERE user_id " . ($uid === 0 ? 'IS NULL' : "= {$uid}") . " AND status='published'"
            );
        } catch (Throwable) {
            return;
        }

        $showInList = class_exists('Env') ? Env::bool('THEME_SHOWS_AUTHOR_IN_LIST', false) : filter_var($_ENV['THEME_SHOWS_AUTHOR_IN_LIST'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $langs      = [];
        foreach ($posts as $post) {
            self::unlinkIfExists("{$post['lang']}_{$post['type']}_{$post['slug']}.html");
            if ($showInList) {
                $langs[$post['lang']] = true;
            }
        }
        if ($showInList) {
            foreach (array_keys($langs) as $lang) {
                self::purgeListPages("{$lang}_home");
            }
        }
    }

    /** @param string $file FPC filename in '{key}.html' form (the historic call convention) */
    /**
     * Purge one cached page by its FPC key (e.g. 'ja_post_hello'). For pages whose dependency is not
     * covered by purgePost(), such as the neighbours of a post in a series (see Series).
     */
    public static function purgePage(string $key): void
    {
        self::unlinkIfExists($key . '.html');
    }

    private static function unlinkIfExists(string $file): void
    {
        $path = self::pagePath((string)preg_replace('/\.html$/', '', $file));
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Full purge, called when site-wide settings change.
     *
     * Walks the tree instead of glob()ing it. glob() materializes every matching path into one
     * array first, so on a large site the purge itself could exhaust memory. The iterator streams,
     * and it also reaches files left flat by a pre-1.1 install.
     */
    public static function purgeAll(): void
    {
        if (!is_dir(self::$pagesDir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::$pagesDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $fileInfo) {
            if ($fileInfo->isFile() && str_ends_with($fileInfo->getFilename(), '.html')) {
                @unlink($fileInfo->getPathname());
            }
        }
    }

    /**
     * Send ETag / Last-Modified for a cached page and answer a matching conditional request.
     *
     * Both validators come from the file itself (one stat), so a purge-and-regenerate changes them
     * and nothing else has to track versions. Cache-Control: no-cache lets a browser or crawler keep
     * the copy but makes it ask every time; without it, a Last-Modified alone invites heuristic
     * caching that could hide an edit for hours.
     *
     * @return bool true when a 304 was sent (the caller must not send a body)
     */
    public static function sendValidators(string $file): bool
    {
        $st = @stat($file);
        if ($st === false) {
            return false;
        }
        $etag     = 'W/"' . dechex((int)$st['mtime']) . '-' . dechex((int)$st['size']) . '"';
        $modified = gmdate('D, d M Y H:i:s', (int)$st['mtime']) . ' GMT';
        header('Cache-Control: no-cache');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . $modified);

        $inm = (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '');
        if ($inm !== '') {
            // Weak comparison (RFC 9110 13.1.2): ignore the W/ prefix on either side
            $want = substr($etag, 2);
            foreach (explode(',', $inm) as $tag) {
                $tag = trim($tag);
                if ($tag === '*' || preg_replace('#^W/#', '', $tag) === $want) {
                    http_response_code(304);
                    return true;
                }
            }
            return false;   // If-None-Match present: If-Modified-Since is ignored
        }
        $ims = (string)($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($ims !== '') {
            $t = strtotime($ims);
            if ($t !== false && (int)$st['mtime'] <= $t) {
                http_response_code(304);
                return true;
            }
        }
        return false;
    }
}
