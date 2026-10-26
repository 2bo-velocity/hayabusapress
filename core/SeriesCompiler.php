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
 * feed_enabled], plus a lang => slug => id index. A post page resolves its series from here, so
 * generating it costs no query for the series row; the table of contents and the widget read it too.
 *
 * Rebuilt whenever a series is created, edited or deleted, and whenever its counters move (a post
 * of the series published, unpublished, moved or deleted). The description is not kept here: only
 * the table-of-contents page shows it, and that page reads the row itself.
 */
final class SeriesCompiler
{
    public const CACHE_FILE = STORAGE_PATH . '/cache/series.cache.php';

    /** @var array{byId:array<int,array>,bySlug:array<string,array<string,int>>}|null */
    private static ?array $map = null;

    public static function compile(): void
    {
        require_once CORE_PATH . '/Series.php';
        $byId   = [];
        $bySlug = [];
        foreach (DB::fetchAll('SELECT id, lang, slug, title, status, cover_image, feed_enabled, post_count, char_count FROM series ORDER BY lang, title') as $r) {
            $id = (int)$r['id'];
            $byId[$id] = [
                'id'           => $id,
                'lang'         => (string)$r['lang'],
                'slug'         => (string)$r['slug'],
                'title'        => (string)$r['title'],
                'status'       => in_array($r['status'], Series::STATUSES, true) ? (string)$r['status'] : 'ongoing',
                'cover'        => (string)($r['cover_image'] ?? ''),
                'feed_enabled' => !empty($r['feed_enabled']),
                'post_count'   => (int)$r['post_count'],
                'char_count'   => (int)$r['char_count'],
            ];
            $bySlug[(string)$r['lang']][(string)$r['slug']] = $id;
        }
        $map = ['byId' => $byId, 'bySlug' => $bySlug];

        $tmp = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, "<?php\n// HBP Series - " . date('Y-m-d H:i:s') . "\nreturn " . var_export($map, true) . ";\n", LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
        self::$map = $map;
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

    /** Auto-Healing: a missing cache file is rebuilt on first read. */
    private static function load(): array
    {
        if (self::$map !== null) {
            return self::$map;
        }
        $map = is_file(self::CACHE_FILE) ? (require self::CACHE_FILE) : null;
        if (!is_array($map) || !isset($map['byId'], $map['bySlug'])) {
            try {
                self::compile();
                return self::$map ?? ['byId' => [], 'bySlug' => []];
            } catch (Throwable $e) {
                error_log('[HBP SERIES] ' . $e->getMessage());
                return self::$map = ['byId' => [], 'bySlug' => []];
            }
        }
        return self::$map = $map;
    }
}
