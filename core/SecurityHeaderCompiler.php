<?php
/**
 * HayabusaPress - Security header precompiler (CSP / HSTS).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Section 24.4: security header precompiler (CSP / HSTS / auxiliary headers).
 * Computes the SHA-256 of every inline <script>/<style> the public pages can carry and bakes the
 * policy into security_headers.cache.php:
 *   - the theme's app.js / style.css, hashed as AssetCompiler embeds them (minified)
 *   - static page snippets of enabled plugins (plugin_snippets.cache.php)
 *   - 'html' widgets (widget_html_sources.cache.php, written by SidebarCompiler)
 * Delivery only does header() via emit($profile) (does not touch the FPC fast path).
 *
 * Recompile whenever one of those sources changes: assets:rebuild, theme switch, plugin
 * enable/disable (PluginManager does it when the snippets change), or a CSP_* / EMBED_HOSTS edit
 * in .env (assets:rebuild or system:init).
 */
class SecurityHeaderCompiler
{
    private const CACHE_FILE = STORAGE_PATH . '/cache/security_headers.cache.php';

    /**
     * Recompile the security header cache.
     * Called at assets:rebuild time or on CSS/JS change.
     */
    public static function compile(): void
    {
        $cspMode    = Env::get('CSP_MODE', 'enforce');
        $cssMode    = Env::get('CSS_DELIVERY', 'inline');
        $embedHosts = self::hostList('EMBED_HOSTS', 'www.youtube-nocookie.com,player.vimeo.com');
        // Off-site images (e.g. partner banners) and scripts. Both empty by default: every host added
        // here is an outbound request from the public pages.
        $imgHosts    = self::hostList('CSP_IMG_HOSTS', '');
        $scriptHosts = self::hostList('CSP_SCRIPT_HOSTS', '');

        $inlineCssHash = self::computeInlineAssetHash('style.css');
        $inlineJsHash  = self::computeInlineAssetHash('app.js');
        $snippetHashes = self::inlineSourceHashes();

        $frameSrc = empty($embedHosts) ? "'none'" : implode(' ', $embedHosts);

        // Public-screen profile
        if ($cssMode === 'external') {
            $styleSrc = self::sources(["'self'"], $snippetHashes['style']);
        } elseif ($inlineCssHash) {
            $styleSrc = self::sources(["'self'", "'sha256-{$inlineCssHash}'"], $snippetHashes['style']);
        } else {
            // No hashes here: a browser that sees a hash ignores 'unsafe-inline'.
            $styleSrc = "'self' 'unsafe-inline'";
        }

        $scriptSrc = self::sources(
            ["'self'"],
            $inlineJsHash ? ["'sha256-{$inlineJsHash}'"] : [],
            $snippetHashes['script'],
            $scriptHosts
        );
        $imgSrc = self::sources(["'self'", 'data:'], $imgHosts);

        $frontendCsp = "default-src 'self'; "
            . "script-src {$scriptSrc}; "
            . "style-src {$styleSrc}; "
            . "img-src {$imgSrc}; "
            . "frame-src {$frameSrc}; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'";

        // Admin-screen profile (assumes a local Tailwind build: style-src 'self')
        $adminCsp = "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; "
            . "img-src 'self' data:; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'none'";

        $headers = [
            'frontend' => [
                'csp'     => $frontendCsp,
                'csp_mode' => $cspMode,
                'inline_css_hash' => $inlineCssHash,
                'inline_js_hash'  => $inlineJsHash,
            ],
            'admin' => [
                'csp'     => $adminCsp,
                'csp_mode' => $cspMode,
            ],
        ];

        $export = var_export($headers, true);
        $php    = "<?php\n// HBP Security Headers Cache - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n";
        $tmp    = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    /**
     * Emit security headers (placed ahead of the FPC fast path).
     * If no cache exists, emit only the default headers.
     *
     * @param string $profile 'frontend' or 'admin'
     */
    public static function emit(string $profile = 'frontend'): void
    {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (((int)($_SERVER['SERVER_PORT'] ?? 80)) === 443);

        $headers = null;
        if (is_file(self::CACHE_FILE)) {
            $data = @include self::CACHE_FILE;
            if (is_array($data) && isset($data[$profile])) {
                $headers = $data[$profile];
            }
        }

        // CSP
        if ($headers && !empty($headers['csp'])) {
            $cspMode   = $headers['csp_mode'] ?? 'enforce';
            $headerName = $cspMode === 'report-only'
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $cspValue = $headers['csp'];
            if ($cspMode === 'report-only') {
                // report-uri is the front controller's special route /csp-report (no auth required, language-independent).
                // Pointing under ADMIN_PATH would be blocked by auth and reports would not arrive, so it is not used.
                $siteUrl   = rtrim(Env::get('SITE_URL', ''), '/');
                $cspValue .= "; report-uri {$siteUrl}/csp-report";
            }
            header("{$headerName}: {$cspValue}");
        }

        // Auxiliary headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

        if ($isHttps) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    /**
     * SHA-256 of a theme asset exactly as {# inline_js #} / {# inline_css #} embeds it.
     * The browser hashes the text between the tags, which is the minified output, not the file.
     */
    private static function computeInlineAssetHash(string $filename): string
    {
        $theme     = class_exists('ThemeManager') ? ThemeManager::active() : 'default';
        $assetPath = ROOT_PATH . '/themes/' . $theme . '/assets/' . $filename;
        if (!is_file($assetPath)) {
            return '';
        }
        $content = file_get_contents($assetPath);
        if ($content === false) {
            return '';
        }
        require_once CORE_PATH . '/AssetCompiler.php';
        $embedded = str_ends_with($filename, '.css')
            ? AssetCompiler::minifyCss($content)
            : AssetCompiler::minifyJs($content);
        return self::hash($embedded);
    }

    /**
     * Hashes of the inline <script> / <style> bodies that pages can carry besides the theme assets:
     * the enabled plugins' static snippets and the 'html' widgets. External scripts (<script src>)
     * are not hashed: they need their host in CSP_SCRIPT_HOSTS.
     *
     * @return array{script: string[], style: string[]} CSP source expressions ('sha256-...')
     */
    private static function inlineSourceHashes(): array
    {
        $out = ['script' => [], 'style' => []];
        require_once CORE_PATH . '/PluginManager.php';
        require_once CORE_PATH . '/SidebarCompiler.php';
        $chunks = [];
        foreach ([PluginManager::SNIPPETS_CACHE, SidebarCompiler::HTML_SOURCES_CACHE] as $file) {
            $data = is_file($file) ? require $file : [];
            if (is_array($data)) {
                $chunks = array_merge($chunks, array_map('strval', array_values($data)));
            }
        }
        $html = implode("\n", $chunks);
        if ($html === '') {
            return $out;
        }
        if (preg_match_all('#<script\b([^>]*)>(.*?)</script\s*>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $tag) {
                if (preg_match('/\bsrc\s*=/i', $tag[1]) || $tag[2] === '') {
                    continue;
                }
                $out['script'][] = "'sha256-" . self::hash($tag[2]) . "'";
            }
        }
        if (preg_match_all('#<style\b[^>]*>(.*?)</style\s*>#is', $html, $m)) {
            foreach ($m[1] as $body) {
                if ($body !== '') {
                    $out['style'][] = "'sha256-" . self::hash($body) . "'";
                }
            }
        }
        return $out;
    }

    /**
     * Read a comma-separated host list from .env. Entries that are not a plain host source
     * (optionally with scheme, leading *. and port) are dropped: a stray ';' or quote would
     * otherwise inject a directive into the header.
     *
     * @return string[]
     */
    private static function hostList(string $key, string $default): array
    {
        $hosts = [];
        foreach (explode(',', (string)Env::get($key, $default)) as $host) {
            $host = trim($host);
            if ($host === '') {
                continue;
            }
            if (!preg_match('#^(https?://)?(\*\.)?[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*(:\d{1,5})?$#', $host)) {
                error_log("[HBP WARNING] {$key}: '{$host}' is not a valid CSP host source -- ignored.");
                continue;
            }
            $hosts[] = $host;
        }
        return array_values(array_unique($hosts));
    }

    /** Join source expressions, dropping duplicates while keeping order. */
    private static function sources(array ...$lists): string
    {
        return implode(' ', array_values(array_unique(array_merge(...$lists))));
    }

    private static function hash(string $content): string
    {
        return base64_encode(hash('sha256', $content, true));
    }
}
