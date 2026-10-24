<?php
/**
 * HayabusaPress - robots.txt / sitemap.xml physical generator.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Physical generation of robots.txt / sitemap.xml
 *
 * large-scale split sitemap support
 * - Following the per-file limit of 50,000 URLs / 50MB, generate a sitemap index + split sub-sitemaps
 * - Listing pagination URLs are not included in the sitemap (only canonical URLs of posts and pages)
 *
 * Two changes for large sites.
 *
 * 1. Streaming. The generator used to SELECT every published post into one PHP array and build the
 *    whole XML as one string before writing. Both grew linearly with the post count, so a large site
 *    hit memory_limit rather than producing a sitemap. Rows are now read in keyset batches and each
 *    chunk file is written and released before the next one starts, so peak memory is bounded by
 *    URL_PER_CHUNK regardless of how many posts exist.
 *
 * 2. Throttling. It also ran on every save. A sitemap is crawler-facing, and no crawler notices the
 *    difference between "updated instantly" and "updated within a few minutes", so an automatic
 *    rebuild now happens at most once per SITEMAP_MIN_INTERVAL. A save that gets throttled marks the
 *    sitemap pending, and `php core/cli.php sitemap:rebuild --if-pending` (cron) catches up.
 *
 * .env:
 *   SITEMAP_MODE          auto|cron  (default auto; cron = never rebuild from a save, CLI only)
 *   SITEMAP_MIN_INTERVAL  seconds between automatic rebuilds (default 300)
 */
class SiteFileCompiler
{
    private const URL_PER_CHUNK = 50000;

    /** Rows pulled from the DB per round trip while streaming. */
    private const FETCH_BATCH = 5000;

    private const STATE_FILE = STORAGE_PATH . '/cache/sitemap.state.php';

    /**
     * Generate sitemap.xml.
     *
     * @param bool $force bypass the interval throttle and SITEMAP_MODE (CLI / system:init)
     * @return bool true when the sitemap was actually rebuilt
     */
    public static function compileSitemap(bool $force = false): bool
    {
        if (!$force && !self::isDue()) {
            self::markPending();
            return false;
        }

        $siteUrl     = rtrim(class_exists('Env') ? Env::get('SITE_URL', '') : ($_ENV['SITE_URL'] ?? ''), '/');
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');
        $now         = date('Y-m-d');

        $sitemapsDir = PUBLIC_PATH . '/sitemaps';
        $written     = [];   // sub-sitemap filenames, in index order
        $buffer      = [];   // <url> lines for the chunk currently being built
        $split       = false; // have we had to start splitting?

        foreach (['post', 'page'] as $type) {
            $chunkNo = 0;
            foreach (self::streamPublished($type) as $row) {
                $line = self::buildUrlLine($siteUrl, $defaultLang, $row, $now);
                if ($line === '') {
                    continue;
                }
                $buffer[] = $line;

                // +1 leaves room for the home URL that goes into the first file
                if (count($buffer) >= self::URL_PER_CHUNK - 1) {
                    $split = true;
                    self::ensureDir($sitemapsDir);
                    $chunkNo++;
                    $name = "{$type}s-{$chunkNo}.xml";
                    self::writeUrlSet($sitemapsDir . '/' . $name, $buffer, $chunkNo === 1 && $written === [] ? self::homeUrlLine($siteUrl, $now) : '');
                    $written[] = $name;
                    $buffer    = [];
                }
            }
            // Flush this type's remainder only if we already split; otherwise both types share
            // one buffer and may still fit in a single file.
            if ($split && $buffer !== []) {
                self::ensureDir($sitemapsDir);
                $chunkNo++;
                $name = "{$type}s-{$chunkNo}.xml";
                self::writeUrlSet($sitemapsDir . '/' . $name, $buffer, '');
                $written[] = $name;
                $buffer    = [];
            }
        }

        if (!$split) {
            // Small site: everything fit, so keep the traditional single sitemap.xml
            self::writeUrlSet(PUBLIC_PATH . '/sitemap.xml', $buffer, self::homeUrlLine($siteUrl, $now));
            self::clearDir($sitemapsDir);
        } else {
            self::writeSitemapIndex($siteUrl, $written, $now);
            self::clearDir($sitemapsDir, $written);
        }

        self::recordBuilt();
        return true;
    }

    /** Rebuild only if a throttled save left the sitemap pending. Returns true when it rebuilt. */
    public static function rebuildIfPending(): bool
    {
        $state = self::readState();
        if (empty($state['pending'])) {
            return false;
        }
        return self::compileSitemap(true);
    }

    // ------------------------------------------------------------
    // Streaming
    // ------------------------------------------------------------

    /**
     * Yield published rows of one type in keyset batches.
     * Keyset (id > last) rather than LIMIT/OFFSET: OFFSET makes the database re-walk and discard
     * every skipped row, so the last chunk of a large table costs the most.
     *
     * @return Generator<array>
     */
    private static function streamPublished(string $type): Generator
    {
        $lastId = 0;
        while (true) {
            $rows = DB::fetchAll(
                "SELECT id, type, lang, slug, updated_at
                   FROM posts
                  WHERE status = 'published' AND deleted_at IS NULL AND type = ? AND id > ?
                  ORDER BY id ASC
                  LIMIT " . self::FETCH_BATCH,
                [$type, $lastId]
            );
            if ($rows === []) {
                return;
            }
            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                yield $row;
            }
            if (count($rows) < self::FETCH_BATCH) {
                return;
            }
        }
    }

    // ------------------------------------------------------------
    // Writers
    // ------------------------------------------------------------

    /**
     * Write a <urlset> file from prepared <url> lines.
     *
     * @param string[] $lines
     * @param string   $prefixLine optional extra line written first (the home URL)
     */
    private static function writeUrlSet(string $filePath, array $lines, string $prefixLine): void
    {
        $tmp = $filePath . '.' . getmypid() . '.tmp';
        $fh  = @fopen($tmp, 'w');
        if ($fh === false) {
            return;
        }
        fwrite($fh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
        fwrite($fh, '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");
        if ($prefixLine !== '') {
            fwrite($fh, $prefixLine);
        }
        foreach ($lines as $line) {
            fwrite($fh, $line);
        }
        fwrite($fh, '</urlset>');
        fclose($fh);
        @rename($tmp, $filePath);
    }

    /**
     * Write the sitemap index that points at the sub-sitemaps.
     *
     * @param string[] $filenames
     */
    private static function writeSitemapIndex(string $siteUrl, array $filenames, string $now): void
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($filenames as $name) {
            $loc = htmlspecialchars($siteUrl . '/sitemaps/' . $name, ENT_QUOTES, 'UTF-8');
            $xml .= "  <sitemap><loc>{$loc}</loc><lastmod>{$now}</lastmod></sitemap>\n";
        }
        $xml .= '</sitemapindex>';

        $path = PUBLIC_PATH . '/sitemap.xml';
        $tmp  = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $xml, LOCK_EX);
        @rename($tmp, $path);
    }

    private static function homeUrlLine(string $siteUrl, string $now): string
    {
        $loc = htmlspecialchars($siteUrl . '/', ENT_QUOTES, 'UTF-8');
        return "  <url><loc>{$loc}</loc><lastmod>{$now}</lastmod><priority>1.0</priority></url>\n";
    }

    /** Build one <url> line, or '' when the slug is not a valid public slug. */
    private static function buildUrlLine(string $siteUrl, string $defaultLang, array $post, string $now): string
    {
        $url = self::buildPostUrl($siteUrl, $defaultLang, $post);
        if ($url === '') {
            return '';
        }
        $lastmod  = htmlspecialchars(substr((string)($post['updated_at'] ?? $now), 0, 10), ENT_QUOTES, 'UTF-8');
        $priority = ($post['type'] ?? '') === 'post' ? '0.8' : '0.6';
        return "  <url><loc>{$url}</loc><lastmod>{$lastmod}</lastmod><priority>{$priority}</priority></url>\n";
    }

    /** Build a post URL. Returns an empty string for an invalid slug. */
    private static function buildPostUrl(string $siteUrl, string $defaultLang, array $post): string
    {
        $postLang = $post['lang'] ?? $defaultLang;
        $slug     = $post['slug'] ?? '';
        if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9\-]{0,199}$/', $slug)) {
            return '';
        }
        $langPrefix = $postLang === $defaultLang ? '' : "/{$postLang}";
        $path       = $post['type'] === 'post'
            ? "{$langPrefix}/post/{$slug}"
            : "{$langPrefix}/page/{$slug}";
        return htmlspecialchars($siteUrl . $path, ENT_QUOTES, 'UTF-8');
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Delete stale sub-sitemaps so a shrinking site does not leave orphan files linked from nowhere.
     *
     * @param string[] $keep filenames to preserve
     */
    private static function clearDir(string $dir, array $keep = []): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $keep = array_flip($keep);
        foreach (glob($dir . '/*.xml') ?: [] as $f) {
            if (!isset($keep[basename($f)])) {
                @unlink($f);
            }
        }
    }

    // ------------------------------------------------------------
    // Throttle state
    // ------------------------------------------------------------

    private static function isDue(): bool
    {
        $mode = class_exists('Env') ? (string)Env::get('SITEMAP_MODE', 'auto') : 'auto';
        if ($mode === 'cron') {
            return false;
        }
        $interval = class_exists('Env') ? Env::int('SITEMAP_MIN_INTERVAL', 300) : 300;
        $state    = self::readState();
        return (time() - (int)($state['built_at'] ?? 0)) >= max(0, $interval);
    }

    private static function readState(): array
    {
        $state = @include self::STATE_FILE;
        return is_array($state) ? $state : [];
    }

    private static function writeState(array $state): void
    {
        $tmp = self::STATE_FILE . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, "<?php return " . var_export($state, true) . ";\n", LOCK_EX) !== false) {
            @rename($tmp, self::STATE_FILE);
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate(self::STATE_FILE, true);   // see SidebarCompiler::forgetCompiled()
            }
        }
    }

    private static function markPending(): void
    {
        $state = self::readState();
        if (!empty($state['pending'])) {
            return; // already flagged; skip the write
        }
        $state['pending'] = true;
        self::writeState($state);
    }

    private static function recordBuilt(): void
    {
        self::writeState(['built_at' => time(), 'pending' => false]);
    }

    public static function writeRobots(string $content): void
    {
        file_put_contents(PUBLIC_PATH . '/robots.txt', $content, LOCK_EX);
    }

    public static function defaultRobots(): string
    {
        $siteUrl   = rtrim(class_exists('Env') ? Env::get('SITE_URL', '') : ($_ENV['SITE_URL'] ?? ''), '/');
        $adminPath = class_exists('Env') ? Env::get('ADMIN_PATH', 'admin') : ($_ENV['ADMIN_PATH'] ?? 'admin');
        // /go/ answers with redirects to partner sites: nothing there to index.
        return "User-agent: *\nDisallow: /{$adminPath}/\nDisallow: /go/\nSitemap: {$siteUrl}/sitemap.xml\n";
    }
}
