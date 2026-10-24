<?php
/**
 * HayabusaPress - Managed outbound links (/go/{alias}).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Posts link to /go/{alias}; the target lives in one place (the links table), so changing it never
 * means editing posts. The table is compiled to links.cache.php and /go/ answers from that array:
 * no DB query, no page cache involved.
 *
 * Only https:// targets registered here are ever redirected to, so /go/ cannot be turned into an
 * open redirector. Clicks are not counted: that would be a write on every request.
 */
class LinkCompiler
{
    public const CACHE_FILE = STORAGE_PATH . '/cache/links.cache.php';

    public const ALIAS_PATTERN = '/^[a-z0-9][a-z0-9-]{0,63}$/';

    /** Compile alias => target into links.cache.php. */
    public static function compile(): void
    {
        $out = [];
        foreach (DB::fetchAll('SELECT alias, target_url FROM links ORDER BY alias') as $row) {
            $url = (string)$row['target_url'];
            if (self::isValidTarget($url)) {
                $out[(string)$row['alias']] = $url;
            }
        }
        file_put_contents(self::CACHE_FILE, "<?php\n// HBP Links - " . date('Y-m-d H:i:s') . "\nreturn " . var_export($out, true) . ";\n", LOCK_EX);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    /** https:// only, no whitespace/control characters, within the column length. */
    public static function isValidTarget(string $url): bool
    {
        return strlen($url) <= 2048
            && preg_match('#^https://[^\s\x00-\x1f"<>]+$#i', $url) === 1
            && (string)(parse_url($url, PHP_URL_HOST) ?? '') !== '';
    }

    /** Answer /go/{alias}: 302 to the target, or 404. */
    public static function serve(string $alias): void
    {
        if (!is_file(self::CACHE_FILE)) {
            self::compile();   // Auto-Healing
        }
        $links = is_file(self::CACHE_FILE) ? require self::CACHE_FILE : [];
        $url   = is_array($links) && preg_match(self::ALIAS_PATTERN, $alias) ? ($links[$alias] ?? null) : null;

        // Never indexed, never cached by a shared cache (the target can change at any time).
        header('X-Robots-Tag: noindex, nofollow');
        header('Cache-Control: private, max-age=0');
        if ($url === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Not Found\n";
            return;
        }
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Location: ' . $url, true, 302);
    }
}
