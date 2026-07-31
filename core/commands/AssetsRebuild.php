<?php
/**
 * HayabusaPress - assets:rebuild command (theme asset re-inline).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * assets:rebuild command
 *
 * Run when a theme's CSS/JS (themes/default/assets/) has been edited.
 * Because the already-inlined content becomes stale:
 *   1. CacheManager::purgeAll() clears all FPC
 *   2. ViewCompiler::clearAll()  discards the front view cache
 * On the next access, Auto-Healing re-inlines the latest minified assets.
 */
class AssetsRebuild
{
    public static function run(): void
    {
        echo "Starting theme asset rebuild...\n";

        require_once CORE_PATH . '/CacheManager.php';
        require_once CORE_PATH . '/ViewCompiler.php';
        require_once CORE_PATH . '/SecurityHeaderCompiler.php';

        // Clear all FPC (because the inlined CSS is stale)
        CacheManager::purgeAll();
        echo "  Cleared all FPC (pages/).\n";

        // Clear the entire front view cache
        ViewCompiler::clearAll();
        echo "  Cleared all view cache (views/).\n";

        // re-bake the CSP header cache (recompute SHA-256 of inline CSS/JS)
        SecurityHeaderCompiler::compile();
        echo "  Regenerated security_headers.cache.php.\n";

        echo "\nassets:rebuild complete. Caches are regenerated on next access via Auto-Healing.\n";
    }
}
