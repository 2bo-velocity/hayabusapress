<?php
/**
 * HayabusaPress - Speakers of :::say speech bubbles.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * The speakers table compiled to slug => [name, avatar, side] for MarkdownCompiler. The bubble is
 * baked into each post when it is saved, so a changed name or avatar reaches existing posts with
 * php core/cli.php posts:recompile.
 */
final class SpeakerCompiler
{
    public const CACHE_FILE   = STORAGE_PATH . '/cache/speakers.cache.php';
    public const SLUG_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    public static function compile(): void
    {
        $map = [];
        foreach (DB::fetchAll('SELECT slug, name, avatar, side FROM speakers ORDER BY slug') as $r) {
            $map[(string)$r['slug']] = [
                'name'   => (string)$r['name'],
                'avatar' => (string)($r['avatar'] ?? ''),
                'side'   => $r['side'] === 'right' ? 'right' : 'left',
            ];
        }
        $tmp = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, "<?php\n// HBP Speakers - " . date('Y-m-d H:i:s') . "\nreturn " . var_export($map, true) . ";\n", LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
    }
}
