<?php
/**
 * HayabusaPress - Author profile singleton aggregation (authors.cache.php).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Q-series: singleton aggregation of author profiles.
 * Removes per-post baking (author_*_cache) and aggregates all authors into a single
 * authors.cache.php. The front end makes only one lookup by user_id.
 */
class AuthorCompiler
{
    private const CACHE_FILE = STORAGE_PATH . '/cache/authors.cache.php';

    private static ?array $authors = null;

    /**
     * Get an author profile (from the singleton).
     * @param int|null $userId NULL or 0 = Super User
     */
    public static function get(int|null $userId): array
    {
        if (self::$authors === null) {
            self::$authors = self::load();
        }
        $uid = $userId ?? 0;
        return self::$authors[$uid] ?? self::getDefault();
    }

    /** Regenerate authors.cache.php. */
    public static function rebuild(): void
    {
        $authors = [];

        // id=0: Super User (from .env)
        $authors[0] = [
            'name'   => Env::get('SUPER_USER_NAME', 'Super User'),
            // avatar is baked as a shard-relative path ({ab}/{cd}/{file}) because the
            //        template prepends /assets/site/avatars/.
            'avatar' => self::avatarRel((string)Env::get('SUPER_USER_AVATAR', '')),
            'bio'    => Env::get('SUPER_USER_BIO', ''),
            'sns'    => [
                'x'      => Env::get('SUPER_USER_X', ''),
                'github' => Env::get('SUPER_USER_GITHUB', ''),
            ],
        ];

        // Regular staff (from the DB)
        try {
            $rows = DB::fetchAll(
                'SELECT id, name, avatar_filename, biography, sns_links FROM users'
            );
            foreach ($rows as $row) {
                $authors[(int)$row['id']] = [
                    'name'   => $row['name'],
                    'avatar' => self::avatarRel((string)($row['avatar_filename'] ?? '')),
                    'bio'    => $row['biography'] ?? '',
                    'sns'    => json_decode($row['sns_links'] ?? '{}', true) ?: [],
                ];
            }
        } catch (Throwable) {
        }

        $export = var_export($authors, true);
        $php    = "<?php\n// HBP Authors Cache - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n";
        $tmp    = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }

        self::$authors = $authors;
    }

    /** Load the cache file (auto-regenerate if missing). */
    private static function load(): array
    {
        if (is_file(self::CACHE_FILE)) {
            $data = require self::CACHE_FILE;
            if (is_array($data)) {
                return $data;
            }
        }
        // Auto-Healing
        self::rebuild();
        return self::$authors ?? [];
    }

    private static function getDefault(): array
    {
        return ['name' => '', 'avatar' => '', 'bio' => '', 'sns' => []];
    }

    /**
     * convert an avatar filename to a shard-relative path ({ab}/{cd}/{file}).
     * Baked as relative because the template prepends /assets/site/avatars/.
     * Empty -> empty string. When Media is not loaded, keep the filename as-is (fallback).
     */
    private static function avatarRel(string $filename): string
    {
        $filename = trim($filename);
        if ($filename === '') {
            return '';
        }
        return class_exists('Media') ? Media::avatarRel($filename) : $filename;
    }
}
