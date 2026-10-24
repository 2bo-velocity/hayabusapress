<?php
/**
 * HayabusaPress - Approved-comment cache compiler.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class CommentCompiler
{
    /** Compile approved comments into a PHP array file + blow away the FPC */
    public static function compile(int $postId): void
    {
        // Save aliased to the field names the template (post.tpl.php) expects
        $comments = DB::fetchAll(
            "SELECT author_name  AS author,
                    author_url   AS url,
                    content,
                    created_at   AS date
             FROM comments
             WHERE post_id = ? AND status = 'approved'
             ORDER BY created_at ASC",
            [$postId]
        );

        // Sync comment_count with the actual number
        DB::execute(
            'UPDATE posts SET comment_count = ? WHERE id = ?',
            [count($comments), $postId]
        );

        $cacheFile = STORAGE_PATH . '/comments/' . $postId . '.cache.php';

        if (empty($comments)) {
            if (file_exists($cacheFile)) unlink($cacheFile);
        } else {
            $export = var_export($comments, true);
            file_put_contents(
                $cacheFile,
                "<?php\n// HBP Compiled Comments for Post #{$postId}\nreturn {$export};\n",
                LOCK_EX
            );
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($cacheFile, true);   // see SidebarCompiler::forgetCompiled()
            }
        }

        // Precise purge of the target post (linked to the dependency graph)
        $post = DB::fetchOne(
            'SELECT type, lang, slug, category_id, tags_cache, created_at FROM posts WHERE id = ? LIMIT 1',
            [$postId]
        );
        if ($post) {
            require_once CORE_PATH . '/CacheManager.php';
            $catSlug = '';
            if (!empty($post['category_id'])) {
                $cat     = DB::fetchOne('SELECT slug FROM categories WHERE id = ? LIMIT 1', [(int)$post['category_id']]);
                $catSlug = $cat['slug'] ?? '';
            }
            $tagSlugs = [];
            if (!empty($post['tags_cache'])) {
                $decodedTags = json_decode($post['tags_cache'], true) ?? [];
                $tagSlugs    = array_column($decodedTags, 'slug');
            }
            CacheManager::purgePost($post, $tagSlugs, $catSlug);
            // Recompile the recent-comments widget, then the areas that embed it
            SidebarCompiler::compileRecentCommentsWidget($post['lang'] ?? '');
            if (SidebarCompiler::compileAllLayouts($post['lang'] ?? '')) {
                CacheManager::purgeAll();
            }
        }
    }

    public static function load(int $postId): array
    {
        $cacheFile = STORAGE_PATH . '/comments/' . $postId . '.cache.php';
        if (!file_exists($cacheFile)) return [];
        return require $cacheFile;
    }

    /** Kept for backward compatibility. New code should use CacheManager::purgePost() */
    public static function invalidateFpc(string $type, string $slug, string $lang = ''): void
    {
        require_once CORE_PATH . '/CacheManager.php';
        CacheManager::purgePost(
            ['lang' => $lang, 'type' => $type, 'slug' => $slug, 'created_at' => date('Y-m-d H:i:s')]
        );
    }
}
