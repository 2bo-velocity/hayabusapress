<?php
/**
 * HayabusaPress - Compiled map of series (series.cache.php).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * The series table compiled to id => [lang, slug, title, status, cover, post_count, char_count,
 * feed_enabled, collection_id, collection_order], plus a lang => slug => id index. A post page
 * resolves its series from here, so generating it costs no query for the series row; the table of
 * contents and the widget read it too.
 *
 * Collections (1.7.0) live in the same file: id => [lang, slug, title, cover, series => ids in
 * collection order], plus their own lang => slug => id index. A post page names its series'
 * collection from here, and the collection page lists its series from here, with no query.
 *
 * Rebuilt whenever a series or a collection is created, edited or deleted, and whenever a series'
 * counters move (a post of the series published, unpublished, moved or deleted). Descriptions are
 * not kept here: only the series' table of contents and the collection page show them, and those
 * pages read the row themselves.
 *
 * compile() also purges what a collection change shows on the public side, by comparing the map
 * before and after (callers that move counters or edit a series need not know about collections):
 *   - the collection page, when its title, slug, cover or any of its series' entries changed;
 *   - the tables of contents of its series, when the "part of" link or the previous / next series
 *     changed (a series renamed, reordered, added, removed, or gaining its first published post);
 *   - the published posts of a series, when the collection named on them changed (the series moved
 *     in or out, or its collection was renamed). One unlink per post, never a full purge.
 */
final class SeriesCompiler
{
    public const CACHE_FILE = STORAGE_PATH . '/cache/series.cache.php';

    /** @var array{byId:array<int,array>,bySlug:array<string,array<string,int>>,collections:array<int,array>,collectionsBySlug:array<string,array<string,int>>}|null */
    private static ?array $map = null;

    /**
     * @return int[] ids of the collections whose page changed (already purged)
     */
    public static function compile(): array
    {
        require_once CORE_PATH . '/Series.php';
        $before = self::$map ?? (is_file(self::CACHE_FILE) ? (@include self::CACHE_FILE) : null);

        $byId   = [];
        $bySlug = [];
        foreach (DB::fetchAll(
            'SELECT id, lang, slug, title, status, cover_image, feed_enabled, post_count, char_count, collection_id, collection_order
               FROM series ORDER BY lang, title'
        ) as $r) {
            $id = (int)$r['id'];
            $byId[$id] = [
                'id'               => $id,
                'lang'             => (string)$r['lang'],
                'slug'             => (string)$r['slug'],
                'title'            => (string)$r['title'],
                'status'           => in_array($r['status'], Series::STATUSES, true) ? (string)$r['status'] : 'ongoing',
                'cover'            => (string)($r['cover_image'] ?? ''),
                'feed_enabled'     => !empty($r['feed_enabled']),
                'post_count'       => (int)$r['post_count'],
                'char_count'       => (int)$r['char_count'],
                'collection_id'    => $r['collection_id'] !== null ? (int)$r['collection_id'] : null,
                'collection_order' => (int)$r['collection_order'],
            ];
            $bySlug[(string)$r['lang']][(string)$r['slug']] = $id;
        }

        $collections       = [];
        $collectionsBySlug = [];
        foreach (DB::fetchAll('SELECT id, lang, slug, title, cover_image FROM collections ORDER BY lang, title') as $r) {
            $id = (int)$r['id'];
            $collections[$id] = [
                'id'     => $id,
                'lang'   => (string)$r['lang'],
                'slug'   => (string)$r['slug'],
                'title'  => (string)$r['title'],
                'cover'  => (string)($r['cover_image'] ?? ''),
                'series' => [],
            ];
            $collectionsBySlug[(string)$r['lang']][(string)$r['slug']] = $id;
        }
        $members = array_filter($byId, static fn($s) => $s['collection_id'] !== null && isset($collections[$s['collection_id']]));
        uasort($members, static fn($a, $b) => [$a['collection_order'], $a['id']] <=> [$b['collection_order'], $b['id']]);
        foreach ($members as $sid => $s) {
            $collections[$s['collection_id']]['series'][] = $sid;
        }

        $map = ['byId' => $byId, 'bySlug' => $bySlug, 'collections' => $collections, 'collectionsBySlug' => $collectionsBySlug];

        $tmp = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, "<?php\n// HBP Series - " . date('Y-m-d H:i:s') . "\nreturn " . var_export($map, true) . ";\n", LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
        self::$map = $map;

        return self::purgeCollectionChanges(is_array($before) ? $before : [], $map);
    }

    /**
     * Purge the pages a collection change reaches (see the class comment). Both the old and the new
     * slugs are purged, so a renamed collection or series leaves no stale page behind.
     *
     * @return int[] ids of the collections whose page changed
     */
    private static function purgeCollectionChanges(array $before, array $after): array
    {
        // No previous map (the file was missing, or written before 1.7.0 when no collection existed):
        // nothing to compare against, and nothing on the public side can name a collection yet
        if (!isset($before['collections'], $before['byId'])) {
            return [];
        }
        require_once CORE_PATH . '/CacheManager.php';
        $changed = [];
        foreach (array_unique(array_merge(array_keys($before['collections'] ?? []), array_keys($after['collections'] ?? []))) as $id) {
            $id = (int)$id;
            if (self::collectionView($before, $id) !== self::collectionView($after, $id)) {
                foreach ([$before, $after] as $map) {
                    if (($c = $map['collections'][$id] ?? null) !== null) {
                        CacheManager::purgePage("{$c['lang']}_list_collection_{$c['slug']}");
                    }
                }
                $changed[] = $id;
            }
            if (self::navView($before, $id) !== self::navView($after, $id)) {
                foreach ([$before, $after] as $map) {
                    foreach ($map['collections'][$id]['series'] ?? [] as $sid) {
                        if (($s = $map['byId'][$sid] ?? null) !== null) {
                            CacheManager::purgeListPages("{$s['lang']}_list_series_{$s['slug']}", Series::tocDepth());
                        }
                    }
                }
            }
        }

        // Posts name their series' collection: purge a series' posts when that name or link changed
        foreach (array_keys($after['byId']) as $sid) {
            if (self::postView($before, $sid) !== self::postView($after, $sid)) {
                foreach (DB::fetchAll("SELECT lang, slug FROM posts WHERE series_id = ? AND status = 'published'", [$sid]) as $r) {
                    CacheManager::purgePage("{$r['lang']}_post_{$r['slug']}");
                }
            }
        }
        return $changed;
    }

    /** Everything the page of collection $id shows that comes from this file. */
    private static function collectionView(array $map, int $id): ?array
    {
        $c = $map['collections'][$id] ?? null;
        if ($c === null) {
            return null;
        }
        $series = [];
        foreach ($c['series'] as $sid) {
            $s = $map['byId'][$sid] ?? null;
            if ($s !== null) {
                $series[] = [$sid, $s['slug'], $s['title'], $s['status'], $s['cover'], $s['post_count'], $s['char_count']];
            }
        }
        return [$c['lang'], $c['slug'], $c['title'], $c['cover'], $series];
    }

    /**
     * What the tables of contents of collection $id's series show of it: the collection's name and
     * link, and the order of its series that have published posts (their previous / next links).
     */
    private static function navView(array $map, int $id): ?array
    {
        $c = $map['collections'][$id] ?? null;
        if ($c === null) {
            return null;
        }
        $series = [];
        foreach ($c['series'] as $sid) {
            $s = $map['byId'][$sid] ?? null;
            if ($s !== null && $s['post_count'] > 0) {
                $series[] = [$sid, $s['slug'], $s['title']];
            }
        }
        return [$c['lang'], $c['slug'], $c['title'], $series];
    }

    /** What the posts of series $sid show of its collection (its name and link), or null. */
    private static function postView(array $map, int $sid): ?array
    {
        $cid = $map['byId'][$sid]['collection_id'] ?? null;
        $c   = $cid !== null ? ($map['collections'][$cid] ?? null) : null;
        return $c === null ? null : [$c['lang'], $c['slug'], $c['title']];
    }

    /** @return array|null the compiled entry for a series id */
    public static function get(?int $id): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        return self::load()['byId'][$id] ?? null;
    }

    /** @return array|null the compiled entry for a series slug in a language */
    public static function find(string $lang, string $slug): ?array
    {
        $id = self::load()['bySlug'][$lang][$slug] ?? null;
        return $id !== null ? self::get((int)$id) : null;
    }

    /**
     * Every series, optionally only those of one language, in title order.
     *
     * @return array<int,array>
     */
    public static function all(?string $lang = null): array
    {
        $all = self::load()['byId'];
        return $lang === null ? array_values($all) : array_values(array_filter($all, static fn($s) => $s['lang'] === $lang));
    }

    /** @return array|null the compiled entry for a collection id */
    public static function collection(?int $id): ?array
    {
        if ($id === null || $id <= 0) {
            return null;
        }
        return self::load()['collections'][$id] ?? null;
    }

    /** @return array|null the compiled entry for a collection slug in a language */
    public static function findCollection(string $lang, string $slug): ?array
    {
        $id = self::load()['collectionsBySlug'][$lang][$slug] ?? null;
        return $id !== null ? self::collection((int)$id) : null;
    }

    /**
     * Every collection, optionally only those of one language, in title order.
     *
     * @return array<int,array>
     */
    public static function collections(?string $lang = null): array
    {
        $all = self::load()['collections'];
        return $lang === null ? array_values($all) : array_values(array_filter($all, static fn($c) => $c['lang'] === $lang));
    }

    /** Auto-Healing: a missing cache file is rebuilt on first read. */
    private static function load(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }
        $map = is_file(self::CACHE_FILE) ? (require self::CACHE_FILE) : null;
        $empty = ['byId' => [], 'bySlug' => [], 'collections' => [], 'collectionsBySlug' => []];
        // A file written before 1.7.0 has no collections: rebuild it like a missing one
        if (!is_array($map) || !isset($map['byId'], $map['bySlug'], $map['collections'], $map['collectionsBySlug'])) {
            try {
                self::compile();
                return self::$map ?? $empty;
            } catch (Throwable $e) {
                error_log('[HBP SERIES] ' . $e->getMessage());
                return self::$map = $empty;
            }
        }
        return self::$map = $map;
    }
}
