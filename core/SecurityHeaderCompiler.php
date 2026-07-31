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
 * Computes the SHA-256 of inline CSS/JS at assets:rebuild time and
 * bakes it into security_headers.cache.php.
 * Delivery only does header() via emit_security_headers($profile) (does not touch the FPC fast path).
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
        $embedHosts = array_filter(array_map(
            'trim',
            explode(',', Env::get('EMBED_HOSTS', 'www.youtube-nocookie.com,player.vimeo.com'))
        ));

        $inlineCssHash = self::computeInlineAssetHash('style.css');
        $inlineJsHash  = self::computeInlineAssetHash('app.js');

        $frameSrc = empty($embedHosts) ? "'none'" : implode(' ', $embedHosts);

        // Public-screen profile
        if ($cssMode === 'external') {
            $styleSrc = "'self'";
        } elseif ($inlineCssHash) {
            $styleSrc = "'self' 'sha256-{$inlineCssHash}'";
        } else {
            $styleSrc = "'self' 'unsafe-inline'";
        }

        $scriptSrc = $inlineJsHash ? "'self' 'sha256-{$inlineJsHash}'" : "'self'";

        $frontendCsp = "default-src 'self'; "
            . "script-src {$scriptSrc}; "
            . "style-src {$styleSrc}; "
            . "img-src 'self' data:; "
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

    /** Compute the SHA-256 hash of theme assets. */
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
        return base64_encode(hash('sha256', $content, true));
    }
}
