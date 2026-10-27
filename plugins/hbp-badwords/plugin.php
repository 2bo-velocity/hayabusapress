<?php
/**
 * HayabusaPress - NG-keyword censorship plugin.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class HbpBadwordsPlugin
{
    private static string $cacheFile = STORAGE_PATH . '/cache/badwords.cache.php';

    /**
     * Censorship entry point called via a hook (zero DB queries at all)
     */
    public static function interceptRequest(array &$context): bool
    {
        if (!file_exists(self::$cacheFile)) {
            self::rebuildCache();
        }

        $badwords = include self::$cacheFile;

        if (empty($badwords)) {
            return true;
        }

        $postData = $context['post_data'] ?? [];

        $combinedText = '';
        foreach ($postData as $value) {
            if (is_string($value)) {
                $combinedText .= ' ' . $value;
            }
        }

        $redirectBack = $postData['redirect_back']
            ?? $_SERVER['HTTP_REFERER']
            ?? '/';

        foreach ($badwords as $word) {
            if (mb_stripos($combinedText, $word) !== false) {
                self::renderBlockedError($redirectBack);
            }
        }

        return true;
    }

    /**
     * Bake the DB contents into a static PHP array cache on disk
     */
    public static function rebuildCache(): void
    {
        $pdo   = DB::getInstance();
        $words = $pdo->query("SELECT word FROM badwords ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);

        $cacheDir = dirname(self::$cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $code = "<?php\nreturn " . var_export($words, true) . ";\n";
        file_put_contents(self::$cacheFile, $code, LOCK_EX);
    }

    /**
     * The page shown instead of accepting the submission. Styled by the active theme's own
     * stylesheet (same origin, so a strict CSP allows it) with the markup of the theme's 404 page;
     * no inline style or script, nothing loaded from another server.
     */
    private static function renderBlockedError(string $redirectBack): void   // ends with exit (no "never": PHP 8.0)
    {
        http_response_code(400);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');

        $lang = defined('CURRENT_LANG') ? CURRENT_LANG : (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'en');
        $dict = class_exists('HbpLocale') ? HbpLocale::loadFrontendDictionary($lang) : [];
        $t    = static fn(string $key, string $en): string => htmlspecialchars($dict['hbp-badwords.' . $key] ?? $en, ENT_QUOTES, 'UTF-8');
        $css  = class_exists('ThemeManager') ? ThemeManager::assetBase() . '/style.css' : '/assets/themes/default/style.css';
        $back = htmlspecialchars(self::safeBack($redirectBack), ENT_QUOTES, 'UTF-8');
        $l    = htmlspecialchars($lang, ENT_QUOTES, 'UTF-8');
        $css  = htmlspecialchars($css, ENT_QUOTES, 'UTF-8');

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="{$l}">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex">
        <title>{$t('title', 'Your submission could not be sent')}</title>
        <link rel="stylesheet" href="{$css}">
        </head>
        <body>
        <div class="hbp-container">
        <main class="hbp-main">
            <div class="hbp-404">
                <h1 class="hbp-404-code">{$t('title', 'Your submission could not be sent')}</h1>
                <p class="hbp-404-text">{$t('text', 'It contains words this site does not accept. Change the text and send it again.')}</p>
                <a href="{$back}" class="hbp-404-link">{$t('back', 'Go back to the form')}</a>
            </div>
        </main>
        </div>
        </body>
        </html>
        HTML;
        exit;
    }

    /**
     * Where "go back" may lead: a path on this site only. The value comes from the request body or
     * the Referer header, so anything else (javascript:, another host, //host) falls back to "/".
     */
    private static function safeBack(string $value): string
    {
        $parts = parse_url($value);
        if ($parts === false) {
            return '/';
        }
        $host = $parts['host'] ?? null;
        if ($host !== null && strcasecmp($host, (string)($_SERVER['HTTP_HOST'] ?? '')) !== 0) {
            return '/';
        }
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return '/';
        }
        $path = (string)($parts['path'] ?? '/');
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) {
            return '/';
        }
        return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }
}
