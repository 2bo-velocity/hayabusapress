<?php
/**
 * HayabusaPress - Collections: ordered runs of series (1.7.0).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

require_once __DIR__ . '/Series.php';
require_once __DIR__ . '/SeriesCompiler.php';

/**
 * A collection is to series what a series is to posts: an ordered run of them (series.collection_id
 * / collection_order), with a page of its own at /post/collection/{slug}. One level only, and a
 * series belongs to at most one collection.
 *
 * Everything public is read from series.cache.php, so the collection page, the "part of" line and
 * the previous / next series on a table of contents cost no query beyond the collection's own row.
 * Purging is done by SeriesCompiler::compile(), which compares the map before and after: callers
 * write the rows, then compile.
 */
final class Collection
{
    /** Gap between consecutive positions, as in Series::ORDER_STEP. */
    public const ORDER_STEP = 10;

    /** Most series a collection page lists (COLLECTION_MAX_SERIES). The page has no pagination. */
    public static function maxSeries(): int
    {
        return max(1, Env::int('COLLECTION_MAX_SERIES', 200));
    }

    /** Path of a collection page, with the language prefix for non-default languages. */
    public static function path(array $collection, string $defaultLang): string
    {
        $prefix = $collection['lang'] === $defaultLang ? '' : '/' . $collection['lang'];
        return $prefix . '/post/collection/' . $collection['slug'];
    }

    /**
     * The series a collection page lists: those with published posts, in collection order, each with
     * the URL of its table of contents.
     *
     * @return array<int,array>
     */
    public static function listing(array $collection, string $defaultLang): array
    {
        $out = [];
        foreach ($collection['series'] as $sid) {
            $s = SeriesCompiler::get((int)$sid);
            if ($s !== null && $s['post_count'] > 0) {
                $out[] = $s + ['url' => Series::path($s, $defaultLang)];
                if (count($out) >= self::maxSeries()) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * What a series' table of contents shows of its collection: the collection's name and link, and
     * the previous / next series of the collection that have published posts. Null when the series
     * is not in a collection.
     *
     * @return array{title:string,url:string,prev:?array,next:?array}|null
     */
    public static function forSeries(array $series, string $defaultLang): ?array
    {
        $c = SeriesCompiler::collection($series['collection_id'] ?? null);
        if ($c === null) {
            return null;
        }
        $visible = self::listing($c, $defaultLang);
        $ids     = array_column($visible, 'id');
        $at      = array_search((int)$series['id'], $ids, true);
        $link    = static fn(?array $s): ?array => $s === null ? null : ['title' => $s['title'], 'url' => $s['url']];
        return [
            'title' => $c['title'],
            'url'   => self::path($c, $defaultLang),
            'prev'  => $at !== false && $at > 0 ? $link($visible[$at - 1]) : null,
            'next'  => $at !== false && isset($visible[$at + 1]) ? $link($visible[$at + 1]) : null,
        ];
    }

    /** What a post page shows of its series' collection: name and link, or null. */
    public static function forPost(array $series, string $defaultLang): ?array
    {
        $c = SeriesCompiler::collection($series['collection_id'] ?? null);
        return $c === null ? null : ['title' => $c['title'], 'url' => self::path($c, $defaultLang)];
    }

    /** The position for a series added at the end of a collection. */
    public static function nextOrder(int $collectionId): int
    {
        $row = DB::fetchOne('SELECT MAX(collection_order) AS m FROM series WHERE collection_id = ?', [$collectionId]);
        return (int)($row['m'] ?? 0) + self::ORDER_STEP;
    }

    /**
     * Write a new order for the series of a collection in one transaction: positions become 10, 20,
     * 30, ... in the given order. Ids that are not in the collection are ignored; series of the
     * collection missing from the list keep their relative order after the listed ones.
     *
     * @param int[] $ids series ids in the new order
     */
    public static function reorder(int $collectionId, array $ids): void
    {
        $current = array_map('intval', array_column(
            DB::fetchAll('SELECT id FROM series WHERE collection_id = ? ORDER BY collection_order, id', [$collectionId]),
            'id'
        ));
        $ids   = array_values(array_intersect(array_unique(array_map('intval', $ids)), $current));
        $order = array_merge($ids, array_values(array_diff($current, $ids)));

        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            foreach ($order as $i => $sid) {
                DB::execute('UPDATE series SET collection_order = ? WHERE id = ?', [($i + 1) * self::ORDER_STEP, $sid]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
