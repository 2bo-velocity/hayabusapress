<?php
/**
 * HayabusaPress - Denormalized listing keys on the post_tags junction table.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * post_tags listing keys
 *
 * The tag listing used to join posts and sort by posts.published_at. post_tags carried only
 * (post_id, tag_id), so no index could satisfy that ORDER BY: the database had to materialize every
 * post carrying the tag and filesort it, just to return ten rows. On a popular tag that is hundreds
 * of thousands of rows per page view.
 *
 * The fix is the same non-normalized-cache idea posts.tags_cache already uses, pointed the other
 * way: copy the columns the listing filters and sorts on (type / lang / status / published_at) onto
 * the junction row, and index them together with tag_id. The listing then walks a single index range
 * in already-sorted order and stops after LIMIT rows.
 *
 * These columns are a cache, not the source of truth — posts remains authoritative. The public tag
 * query still re-checks posts.status so drift can never publish something that is not published,
 * and `php core/cli.php tags:reindex` rebuilds them from posts.
 */
final class PostTagIndex
{
    /**
     * Replace a post's tag links, stamping the post's current listing keys onto each row.
     *
     * @param int[] $tagIds
     * @param array{type:string,lang:string,status:string,published_at:?string} $meta
     */
    public static function sync(int $postId, array $tagIds, array $meta): void
    {
        DB::execute('DELETE FROM post_tags WHERE post_id = ?', [$postId]);

        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($tagIds === []) {
            return;
        }

        foreach ($tagIds as $tagId) {
            DB::execute(
                'INSERT INTO post_tags (post_id, tag_id, type, lang, status, published_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $postId,
                    $tagId,
                    (string)($meta['type'] ?? 'post'),
                    (string)($meta['lang'] ?? ''),
                    (string)($meta['status'] ?? 'draft'),
                    $meta['published_at'] ?? null,
                ]
            );
        }
    }

    /**
     * Refresh only the denormalized columns of a post's existing links.
     * Call whenever a post's status / lang / type / published_at changes without its tag set changing
     * (trash, restore, scheduled publish).
     *
     * @param array{type:string,lang:string,status:string,published_at:?string} $meta
     */
    public static function touch(int $postId, array $meta): void
    {
        DB::execute(
            'UPDATE post_tags SET type = ?, lang = ?, status = ?, published_at = ? WHERE post_id = ?',
            [
                (string)($meta['type'] ?? 'post'),
                (string)($meta['lang'] ?? ''),
                (string)($meta['status'] ?? 'draft'),
                $meta['published_at'] ?? null,
                $postId,
            ]
        );
    }

    /**
     * Rebuild every row's denormalized columns from posts (repair path / post-migration backfill).
     * A single set-based UPDATE, so it does not load anything into PHP memory.
     *
     * @return int rows updated
     */
    public static function rebuildAll(): int
    {
        if (DB::driver() === 'pgsql') {
            return DB::execute(
                'UPDATE post_tags pt
                    SET type = p.type, lang = p.lang, status = p.status, published_at = p.published_at
                   FROM posts p
                  WHERE p.id = pt.post_id'
            );
        }
        return DB::execute(
            'UPDATE post_tags pt
               JOIN posts p ON p.id = pt.post_id
                SET pt.type = p.type, pt.lang = p.lang, pt.status = p.status, pt.published_at = p.published_at'
        );
    }
}
