<?php
/**
 * HayabusaPress - Public-page view deny list compiler.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * B.11: compiler for the public-page view deny list.
 * Compiles SITE_IP_DENYLIST (.env) + CIDRs registered from the admin UI into
 * site_denylist.cache.php so the decision can be made with zero DB.
 * Placed ahead of the FPC fast path (before readfile), so it performs no DB queries at all.
 */
class SiteDenylistCompiler
{
    private const CACHE_FILE = STORAGE_PATH . '/cache/site_denylist.cache.php';

    /** Compile (regenerate) the cache. */
    public static function compile(): void
    {
        $cidrs = [];

        // SITE_IP_DENYLIST from .env
        $envList = Env::get('SITE_IP_DENYLIST', '');
        if ($envList !== '') {
            foreach (explode(',', $envList) as $cidr) {
                $cidr = trim($cidr);
                if ($cidr !== '') {
                    $cidrs[] = $cidr;
                }
            }
        }

        // Entries from the blocked_ips table flagged to also deny GET (for future extension)
        // Currently only SITE_IP_DENYLIST

        $cidrs  = array_unique($cidrs);
        $export = var_export($cidrs, true);
        $php    = "<?php\n// HBP Site Denylist Cache - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n";
        $tmp    = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    /**
     * Determine whether the current request IP is denied from viewing.
     * If the cache is empty (= list not set), does nothing (default empty = unrestricted viewing).
     */
    public static function checkAndBlock(string $ip): void
    {
        $cidrs = self::load();
        if (empty($cidrs)) {
            return; // Default: unrestricted (zero performance impact)
        }

        if (AccessControl::isInCidrList($ip, $cidrs)) {
            http_response_code(403);
            exit('403 Forbidden');
        }
    }

    /** Load the CIDR list from the cache. */
    private static function load(): array
    {
        if (!is_file(self::CACHE_FILE)) {
            return [];
        }
        $data = @include self::CACHE_FILE;
        return is_array($data) ? $data : [];
    }
}
