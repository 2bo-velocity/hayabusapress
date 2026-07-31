<?php
/**
 * HayabusaPress - Incremental post counters for categories and tags.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * categories.post_count / tags.post_count
 *
 * The sidebar shows "Category (12)", so the counts have to exist. They used to be produced by a
 * COUNT(*) ... GROUP BY across every published post, recomputed for *all* categories and *all* tags
 * on every single save. The cost of saving one post therefore scaled with the size of the whole site.
 *
 * The counts are now columns, moved by deltas. Saving a post touches at most a handful of rows by
 * primary key, whatever the site's size.
 *
 * Delta bookkeeping can drift if a code path mutates posts without going through here (a manual SQL
 * fix, an interrupted request). It is a cache, so drift is cosmetic, and
 * `php core/cli.php counters:rebuild` recomputes both tables from scratch.
 *
 * Usage — snapshot before and after, and let this class diff them:
 *
 *     $before = Counters::snapshot($postId);   // null for a post that does not exist yet
 *     ... mutate the post ...
 *     Counters::applyPostChange($before, Counters::snapshot($postId));
 *
 * Snapshotting costs two primary-key lookups, which is cheaper and far harder to get wrong than
 * threading old/new state through every call site by hand.
 */
final class Counters
{
    /**
     * Capture the counter-relevant state of a post.
     * Returns null when the post does not exist (before creation / after deletion), which is exactly
     * the "contributes nothing" case.
     *
     * @return array{type:string,status:string,deleted_at:?string,category_id:?int,tag_ids:int[]}|null
     */
    public static function snapshot(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }
        try {
            $row = DB::fetchOne(
                'SELECT type, status, deleted_at, category_id FROM posts WHERE id = ? LIMIT 1',
                [$postId]
            );
            if (!$row) {
                return null;
            }
            $tagRows = DB::fetchAll('SELECT tag_id FROM post_tags WHERE post_id = ?', [$postId]);
        } catch (Throwable) {
            return null;
        }

        return [
            'type'        => (string)($row['type'] ?? ''),
            'status'      => (string)($row['status'] ?? ''),
            'deleted_at'  => $row['deleted_at'] ?? null,
            'category_id' => $row['category_id'] !== null ? (int)$row['category_id'] : null,
            'tag_ids'     => array_map('intval', array_column($tagRows, 'tag_id')),
        ];
    }

    /**
     * Apply the difference between two snapshots to the counter columns.
     * Either side may be null (creation / deletion). A no-op change issues no queries.
     */
    public static function applyPostChange(?array $before, ?array $after): void
    {
        $beforeVisible = self::isVisible($before);
        $afterVisible  = self::isVisible($after);

        // --- categories ---
        $oldCat = $beforeVisible ? ($before['category_id'] ?? null) : null;
        $newCat = $afterVisible  ? ($after['category_id']  ?? null) : null;
        if ($oldCat !== $newCat) {
            if ($oldCat !== null) {
                self::bump('categories', [$oldCat], -1);
            }
            if ($newCat !== null) {
                self::bump('categories', [$newCat], +1);
            }
        }

        // --- tags ---
        $oldTags = $beforeVisible ? ($before['tag_ids'] ?? []) : [];
        $newTags = $afterVisible  ? ($after['tag_ids']  ?? []) : [];
        $removed = array_values(array_diff($oldTags, $newTags));
        $added   = array_values(array_diff($newTags, $oldTags));
        if ($removed !== []) {
            self::bump('tags', $removed, -1);
        }
        if ($added !== []) {
            self::bump('tags', $added, +1);
        }
    }

    /**
     * Recompute both counter tables from the source of truth.
     * Set-based, so nothing is loaded into PHP memory regardless of the post count.
     */
    public static function rebuildAll(): void
    {
        // Categories count posts directly. Tags count via post_tags' denormalized listing keys,
        // which idx_post_tags_list covers (see PostTagIndex).
        DB::execute(
            "UPDATE categories SET post_count = (
                 SELECT COUNT(*) FROM posts p
                  WHERE p.category_id = categories.id
                    AND p.type = 'post' AND p.status = 'published' AND p.deleted_at IS NULL
             )"
        );
        DB::execute(
            "UPDATE tags SET post_count = (
                 SELECT COUNT(*) FROM post_tags pt
                  WHERE pt.tag_id = tags.id
                    AND pt.type = 'post' AND pt.status = 'published'
             )"
        );
    }

    // ------------------------------------------------------------

    /** Does this post contribute to the public listing counts? */
    private static function isVisible(?array $snap): bool
    {
        return $snap !== null
            && ($snap['type'] ?? '') === 'post'
            && ($snap['status'] ?? '') === 'published'
            && empty($snap['deleted_at']);
    }

    /**
     * Move a counter by $delta for the given ids.
     * GREATEST(..., 0) keeps a drifted counter from going negative and, on MySQL, from underflowing
     * the column if it is ever made UNSIGNED.
     *
     * @param int[] $ids
     */
    private static function bump(string $table, array $ids, int $delta): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || $delta === 0) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $expr = $delta > 0
            ? "post_count + {$delta}"
            : 'GREATEST(post_count - ' . abs($delta) . ', 0)';
        try {
            DB::execute("UPDATE {$table} SET post_count = {$expr} WHERE id IN ({$placeholders})", $ids);
        } catch (Throwable $e) {
            // A counter is a cache: never fail a save because it could not be nudged.
            error_log('[HBP COUNTERS] ' . $e->getMessage());
        }
    }
}
