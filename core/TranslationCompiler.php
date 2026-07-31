<?php
/**
 * HayabusaPress - Translation group and hreflang_cache compiler.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * P-series: translation group management + hreflang_cache generation.
 * Posts with the same trans_group are treated as "translation siblings";
 * their hreflang_cache values are recomputed on save and on change.
 */
class TranslationCompiler
{
    /**
     * Recompute the hreflang_cache of all siblings in a translation group and update the DB.
     * Called on slug/trans_group change and on post save.
     *
     * @param string $transGroup translation group key in UUID format
     */
    public static function sync(string $transGroup): void
    {
        if ($transGroup === '') {
            return;
        }

        // Fetch all siblings
        $siblings = DB::fetchAll(
            "SELECT id, lang, type, slug FROM posts WHERE trans_group = ? AND status = 'published'",
            [$transGroup]
        );

        if (count($siblings) < 2) {
            // hreflang_cache is unneeded if there is one or fewer
            foreach ($siblings as $s) {
                DB::execute('UPDATE posts SET hreflang_cache = NULL WHERE id = ?', [(int)$s['id']]);
            }
            self::purgeSiblings($siblings);
            return;
        }

        $defaultLang = Env::get('SITE_DEFAULT_LANG', 'en');
        $siteUrl     = rtrim(Env::get('SITE_URL', ''), '/');

        // Build the hreflang map
        $hreflangMap = [];
        foreach ($siblings as $s) {
            $lang   = $s['lang'];
            $prefix = $lang === $defaultLang ? '' : "/{$lang}";
            $url    = $prefix . '/' . $s['type'] . '/' . $s['slug'];
            $hreflangMap[$lang] = $url;
        }
        // x-default is the default language or the first sibling
        $xDefault = $hreflangMap[$defaultLang] ?? reset($hreflangMap);
        $hreflangMap['x-default'] = $xDefault;

        $json = json_encode($hreflangMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Write to all siblings
        foreach ($siblings as $s) {
            DB::execute('UPDATE posts SET hreflang_cache = ? WHERE id = ?', [$json, (int)$s['id']]);
        }

        // Linked purge of siblings' detail FPC
        self::purgeSiblings($siblings);
    }

    /**
     * Called when a single post leaves a translation group (changed to trans_group=NULL).
     */
    public static function detach(int $postId, string $oldTransGroup): void
    {
        // Clear my own hreflang_cache
        DB::execute('UPDATE posts SET hreflang_cache = NULL WHERE id = ?', [$postId]);

        // Re-sync the remaining siblings
        if ($oldTransGroup !== '') {
            self::sync($oldTransGroup);
        }
    }

    /** Purge siblings' detail FPC. */
    private static function purgeSiblings(array $siblings): void
    {
        if (!class_exists('CacheManager')) {
            require_once CORE_PATH . '/CacheManager.php';
        }
        foreach ($siblings as $s) {
            // Go through CacheManager so this agrees with the sharded layout
            $file = CacheManager::pagePath($s['lang'] . '_' . $s['type'] . '_' . $s['slug']);
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }
}
