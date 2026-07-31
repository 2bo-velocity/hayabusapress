<?php
/**
 * HayabusaPress - Template rendering with Auto-Healing.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class View
{
    /**
     * Render a template.
     * Resolves the locale automatically and uses a per-language cache.
     * If no cache exists, compile automatically via Auto-Healing.
     */
    public static function render(string $viewName, array $data = []): void
    {
        $locale    = self::resolveCurrentLocale($viewName);
        $cachePath = self::getCachePath($viewName, $locale);

        if (!file_exists($cachePath)) {
            $cachePath = ViewCompiler::compile($viewName, $locale);
        }

        extract($data, EXTR_SKIP);

        require $cachePath;
    }

    /** Return the rendered result as a string (for FPC generation) */
    public static function renderToString(string $viewName, array $data = []): string
    {
        ob_start();
        self::render($viewName, $data);
        return (string)ob_get_clean();
    }

    /**
     * Resolve the current locale.
     *
     * Admin: $_SESSION['user_locale'] -> SUPER_USER_LOCALE -> 'en'
     * Public: CURRENT_LANG constant -> SITE_DEFAULT_LANG -> 'en'
     */
    public static function resolveCurrentLocale(string $viewName = ''): string
    {
        if (str_starts_with($viewName, 'admin/')) {
            return $_SESSION['user_locale']
                ?? (class_exists('Env') ? Env::get('SUPER_USER_LOCALE', 'en') : ($_ENV['SUPER_USER_LOCALE'] ?? 'en'));
        }

        if (defined('CURRENT_LANG')) {
            return CURRENT_LANG;
        }

        return class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');
    }

    private static function getCachePath(string $viewName, string $locale): string
    {
        $subDir    = str_starts_with($viewName, 'admin/') ? 'admin' : 'frontend';
        $cacheName = md5($viewName) . '.cache.php';
        return STORAGE_PATH . '/cache/views/' . $subDir . '/' . $locale . '/' . $cacheName;
    }
}
