<?php
/**
 * HayabusaPress - Series: ordering, neighbours and the precise purge of what a change touches.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * A series is an ordered run of ordinary posts (posts.series_id / series_order / series_part). Its
 * posts keep their own URLs and every other feature; what the series adds is the previous / next
 * links on each post and a table of contents at /post/series/{slug}.
 *
 * Both are built when the page is generated (two one-row index ranges on idx_posts_series), never
 * stored on the posts. A change therefore has to purge the pages that show it, and only those:
 *
 *     $before = Series::snapshot($postId);   // null when the post is not in a series
 *     ... mutate the post ...
 *     Series::applyPostChange($before, Series::snapshot($postId));
 *
 * The neighbours are taken on both sides: moving, publishing or hiding a post changes the links of
 * the posts that were next to it and of the posts that are next to it now. The same shape as
 * Counters::snapshot(), and called right after it.
 */
final class Series
{
    public const STATUSES     = ['ongoing', 'completed', 'hiatus'];
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,199}$/';

    /** Gap between consecutive positions, so a post can be slotted in without renumbering. */
    public const ORDER_STEP = 10;

    /**
     * How many pages a table of contents may have (SERIES_FPC_DEPTH). Its own limit, not
     * LIST_FPC_DEPTH: a listing can drop its oldest entries past the last page, but a table of
     * contents that stops early hides the rest of the series. 100 pages x SERIES_PAGE_SIZE 100 =
     * 10,000 posts; a change purges at most this many files per series.
     */
    public static function tocDepth(): int
    {
        return max(1, Env::int('SERIES_FPC_DEPTH', 100));
    }

    /**
     * The published posts right before and after a position. Ties on series_order (which the admin
     * never writes, but a hand-edited row could hold) are broken by id, so the order is total.
     *
     * @return array{prev:?array,next:?array} each {id, title, slug} or null
     */
    public static function neighbours(int $seriesId, int $order, int $postId): array
    {
        $prev = DB::fetchOne(
            "SELECT id, title, slug FROM posts
              WHERE series_id = ? AND status = 'published'
                AND (series_order < ? OR (series_order = ? AND id < ?))
              ORDER BY series_order DESC, id DESC LIMIT 1",
            [$seriesId, $order, $order, $postId]
        );
        $next = DB::fetchOne(
            "SELECT id, title, slug FROM posts
              WHERE series_id = ? AND status = 'published'
                AND (series_order > ? OR (series_order = ? AND id > ?))
              ORDER BY series_order ASC, id ASC LIMIT 1",
            [$seriesId, $order, $order, $postId]
        );
        return ['prev' => $prev ?: null, 'next' => $next ?: null];
    }

    /**
     * What a post shows of its series and which pages show it.
     * Null when the post does not exist, is not a post, or is in no series.
     *
     * @return array{series_id:int,lang:string,slug:string,visible:bool,prev:?string,next:?string}|null
     */
    public static function snapshot(int $postId): ?array
    {
        if ($postId <= 0) {
            return null;
        }
        try {
            $row = DB::fetchOne(
                'SELECT id, type, lang, slug, status, deleted_at, series_id, series_order FROM posts WHERE id = ? LIMIT 1',
                [$postId]
            );
            if (!$row || $row['series_id'] === null || $row['type'] !== 'post') {
                return null;
            }
            $n = self::neighbours((int)$row['series_id'], (int)$row['series_order'], $postId);
        } catch (Throwable) {
            return null;
        }
        return [
            'series_id' => (int)$row['series_id'],
            'lang'      => (string)$row['lang'],
            'slug'      => (string)$row['slug'],
            'visible'   => $row['status'] === 'published' && $row['deleted_at'] === null,
            'prev'      => $n['prev']['slug'] ?? null,
            'next'      => $n['next']['slug'] ?? null,
        ];
    }

    /**
     * Rebuild series.cache.php and purge the pages a change of one post touched: the post itself
     * (under its old and new slug), its neighbours before and after, and the tables of contents of
     * its old and new series. A post that was not public before and is not public now changed
     * nothing a reader can see, so nothing is purged.
     */
    public static function applyPostChange(?array $before, ?array $after): void
    {
        if (!($before['visible'] ?? false) && !($after['visible'] ?? false)) {
            return;
        }
        require_once CORE_PATH . '/CacheManager.php';
        require_once CORE_PATH . '/SeriesCompiler.php';
        SeriesCompiler::compile();   // the counters moved

        $series = [];
        foreach ([$before, $after] as $snap) {
            if ($snap === null) {
                continue;
            }
            foreach ([$snap['slug'], $snap['prev'], $snap['next']] as $slug) {
                if ($slug !== null && $slug !== '') {
                    CacheManager::purgePage("{$snap['lang']}_post_{$slug}");
                }
            }
            $series[$snap['series_id']] = true;
        }
        foreach (array_keys($series) as $id) {
            self::purgeToc((int)$id);
        }
    }

    /** Purge every page of a series' table of contents. */
    public static function purgeToc(int $seriesId): void
    {
        require_once CORE_PATH . '/SeriesCompiler.php';
        $s = SeriesCompiler::get($seriesId);
        if ($s !== null) {
            require_once CORE_PATH . '/CacheManager.php';
            CacheManager::purgeListPages("{$s['lang']}_list_series_{$s['slug']}", self::tocDepth());
        }
    }

    /**
     * Purge the table of contents and every published post of a series: each post page shows the
     * series' title and status, and its neighbours' titles. For edits of the series itself and for
     * reordering. One unlink per post, never a full purge.
     */
    public static function purgeAll(int $seriesId, ?string $lang = null, ?string $slug = null): void
    {
        require_once CORE_PATH . '/CacheManager.php';
        $rows = DB::fetchAll(
            "SELECT lang, slug FROM posts WHERE series_id = ? AND status = 'published'",
            [$seriesId]
        );
        foreach ($rows as $r) {
            CacheManager::purgePage("{$r['lang']}_post_{$r['slug']}");
        }
        if ($lang !== null && $slug !== null) {
            CacheManager::purgeListPages("{$lang}_list_series_{$slug}", self::tocDepth());
        } else {
            self::purgeToc($seriesId);
        }
    }

    /** The position for a post added at the end of a series. */
    public static function nextOrder(int $seriesId): int
    {
        $row = DB::fetchOne('SELECT MAX(series_order) AS m FROM posts WHERE series_id = ?', [$seriesId]);
        return (int)($row['m'] ?? 0) + self::ORDER_STEP;
    }

    /**
     * Write a new order (and chapter headings) for a series in one transaction: positions become
     * 10, 20, 30, ... in the given order. Ids that are not in the series are ignored; posts of the
     * series missing from the list keep their relative order after the listed ones.
     *
     * @param int[]              $ids   post ids in the new order
     * @param array<int,string>  $parts post id => chapter heading ('' = none)
     */
    public static function reorder(int $seriesId, array $ids, array $parts): void
    {
        $current = array_map('intval', array_column(
            DB::fetchAll('SELECT id FROM posts WHERE series_id = ? ORDER BY series_order, id', [$seriesId]),
            'id'
        ));
        $ids   = array_values(array_intersect(array_unique(array_map('intval', $ids)), $current));
        $order = array_merge($ids, array_values(array_diff($current, $ids)));

        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            $pos = 0;
            foreach ($order as $id) {
                $pos += self::ORDER_STEP;
                $part = array_key_exists($id, $parts) ? self::cleanPart($parts[$id]) : false;
                if ($part === false) {
                    DB::execute('UPDATE posts SET series_order = ? WHERE id = ? AND series_id = ?', [$pos, $id, $seriesId]);
                } else {
                    DB::execute('UPDATE posts SET series_order = ?, series_part = ? WHERE id = ? AND series_id = ?', [$pos, $part, $id, $seriesId]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** A chapter heading as stored: trimmed, at most 100 characters (VARCHAR(100)), null when empty. */
    public static function cleanPart(?string $part): ?string
    {
        $part = trim(str_replace(["\r", "\n"], ' ', (string)$part));
        return $part === '' ? null : mb_substr($part, 0, 100);
    }

    /** Path of a series' table of contents, with the language prefix for non-default languages. */
    public static function path(array $series, string $defaultLang): string
    {
        $prefix = $series['lang'] === $defaultLang ? '' : '/' . $series['lang'];
        return $prefix . '/post/series/' . $series['slug'];
    }

    /**
     * What a post page shows of its series, built when the page is generated (FPC miss only).
     *
     * collection (1.7.0): the name and link of the series' collection, or null.
     *
     * @return array{id:int,title:string,slug:string,url:string,status:string,part:?string,prev:?array,next:?array,collection:?array}|null
     */
    public static function forPost(array $post, string $defaultLang): ?array
    {
        if (empty($post['series_id']) || ($post['type'] ?? '') !== 'post') {
            return null;
        }
        require_once CORE_PATH . '/SeriesCompiler.php';
        require_once CORE_PATH . '/Collection.php';
        $s = SeriesCompiler::get((int)$post['series_id']);
        if ($s === null) {
            return null;
        }
        $prefix = (string)$post['lang'] === $defaultLang ? '' : '/' . $post['lang'];
        $n      = self::neighbours($s['id'], (int)$post['series_order'], (int)$post['id']);
        $link   = static fn(?array $p): ?array => $p ? ['title' => (string)$p['title'], 'url' => $prefix . '/post/' . $p['slug']] : null;
        return [
            'id'     => $s['id'],
            'title'  => $s['title'],
            'slug'   => $s['slug'],
            'url'    => self::path($s, $defaultLang),
            'status' => $s['status'],
            'part'   => ($post['series_part'] ?? '') !== '' ? (string)$post['series_part'] : null,
            'prev'   => $link($n['prev']),
            'next'   => $link($n['next']),
            'collection' => Collection::forPost($s, $defaultLang),
        ];
    }
}
