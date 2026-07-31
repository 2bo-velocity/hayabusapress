<?php
/**
 * HayabusaPress - Multi-locale engine (language-pack auto-scan + fallback).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Multi-locale engine (auto-scans language packs + fallback)
 *
 * removed the reader UI language session (frontend_locale) on the public side.
 * Because the FPC is keyed by content language (URL), the session selection was
 * dead code that never reflected into the cache.
 * The public-side UI language matches the content language (CURRENT_LANG = URL).
 */
class HbpLocale
{
    private static string $langDir = CORE_PATH . '/lang';

    /** Scan core/lang/admin_*.php and return the available language codes */
    public static function getAvailableLocales(): array
    {
        $locales = [];
        $files   = glob(self::$langDir . '/admin_*.php') ?: [];
        foreach ($files as $file) {
            if (preg_match('/admin_([a-z]{2,5})\.php$/', basename($file), $m)) {
                $locales[$m[1]] = strtoupper($m[1]);
            }
        }
        ksort($locales);
        return $locales;
    }

    /** Return content language codes for the front end (based on the existence of frontend_*.php) */
    public static function getAvailableContentLocales(): array
    {
        $locales = [];
        $files   = glob(self::$langDir . '/frontend_*.php') ?: [];
        foreach ($files as $file) {
            if (preg_match('/frontend_([a-z]{2,5})\.php$/', basename($file), $m)) {
                $locales[$m[1]] = strtoupper($m[1]);
            }
        }
        ksort($locales);
        return $locales;
    }

    /** Per-locale memoization cache of the admin dictionary (prevents repeated require within the same request). */
    private static array $adminDictCache = [];

    /**
     * Translate admin exception/error messages.
     * A static helper to be called from deep in controllers (at the throw site).
     * The locale is determined as current admin user ($_SESSION['user_locale']) -> SITE_DEFAULT_LANG.
     * If the key is not in the dictionary, returns $fallback (the default English wording).
     * -> An English admin sees English (fallback); a Japanese admin sees the translation in admin_ja.php.
     *
     * @param string               $key      dictionary key (e.g. 'err_title_required')
     * @param string               $fallback the default English wording returned when the key is missing (required)
     * @param array<string,scalar> $replace  values for ':name' placeholders, e.g. ['max' => 100]
     */
    public static function t(string $key, string $fallback = '', array $replace = []): string
    {
        $locale = '';
        if (!empty($_SESSION['user_locale'])) {
            $locale = (string)$_SESSION['user_locale'];
        }
        if ($locale === '') {
            $locale = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'en';
        }
        if (!isset(self::$adminDictCache[$locale])) {
            self::$adminDictCache[$locale] = self::loadAdminDictionary($locale);
        }
        $message = self::$adminDictCache[$locale][$key] ?? ($fallback !== '' ? $fallback : $key);

        return $replace === [] ? $message : self::interpolate($message, $replace);
    }

    /**
     * Substitute ':name' placeholders in a message.
     *
     * Public so the public-side dictionary (loadFrontendDictionary()) and plugins can reuse the
     * same substitution rules rather than inventing their own.
     *
     *   interpolate(':field must be :max characters or fewer.', ['field' => 'Name', 'max' => 100])
     *   => 'Name must be 100 characters or fewer.'
     *
     * Notes:
     * - Substitution happens in a single pass (strtr), so a value that itself contains ':something'
     *   is never re-scanned. Longer placeholder names win, meaning ':maximum' is not clipped by ':max'.
     * - Placeholders with no matching key are left untouched; extra keys are ignored. A missing
     *   translation therefore degrades to a partly-filled sentence instead of an exception.
     * - The result is plain text. Escaping stays the caller's job -- templates output it through
     *   '{{ }}', which escapes -- so no HTML encoding is applied here.
     *
     * @param array<string,scalar> $replace
     */
    public static function interpolate(string $message, array $replace): string
    {
        if ($replace === [] || $message === '') {
            return $message;
        }
        $pairs = [];
        foreach ($replace as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $pairs[':' . $name] = (string)$value;
            }
        }
        return $pairs === [] ? $message : strtr($message, $pairs);
    }

    /**
     * Load the admin dictionary
     * Fallback order: $userLocale -> SITE_DEFAULT_LANG -> 'en' -> []
     */
    public static function loadAdminDictionary(string $userLocale): array
    {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');
        foreach ([$userLocale, $defaultLang, 'en'] as $lang) {
            $file = self::$langDir . "/admin_{$lang}.php";
            if (file_exists($file)) {
                return self::withPluginCatalog(require $file, $lang);
            }
        }
        return [];
    }

    /**
     * Overlay the compiled plugin catalog for the same language.
     *
     * The catalog is produced by PluginManager::rebuildCache() -- nothing is scanned here, this is a
     * single require of an already-merged array, and only when at least one enabled plugin ships a
     * pack for that language. Core keys are applied last so a plugin can never redefine a core
     * message; plugin keys are namespaced ('{plugin-id}.key') and so do not collide in practice.
     *
     * @param array<string,string> $core
     * @return array<string,string>
     */
    private static function withPluginCatalog(array $core, string $lang): array
    {
        if (!defined('STORAGE_PATH')) {
            return $core;
        }
        $file = STORAGE_PATH . '/cache/plugin_lang_' . $lang . '.cache.php';
        if (!file_exists($file)) {
            return $core;
        }
        $plugin = require $file;
        return is_array($plugin) ? array_merge($plugin, $core) : $core;
    }

    /**
     * Load the public-side dictionary (URL-unified)
     *
     * The public-side UI language matches the content language (URL).
     * Session / Accept-Language are not used for display branching (they conflict with the FPC).
     *
     * Fallback order: $forceLang -> SITE_DEFAULT_LANG -> 'en' -> []
     */
    public static function loadFrontendDictionary(string $forceLang = ''): array
    {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');

        foreach ([$forceLang, $defaultLang, 'en'] as $lang) {
            if ($lang === '') {
                continue;
            }
            $file = self::$langDir . "/frontend_{$lang}.php";
            if (file_exists($file)) {
                return self::withPluginCatalog(require $file, $lang);
            }
        }
        return [];
    }

    /**
     * Extract a language code from the Accept-Language header.
     * For the redirect on the root / when AUTO_LANG_REDIRECT=true.
     * Not used for dictionary selection at display time (P-series).
     */
    public static function negotiateFromAcceptLanguage(string $header): ?string
    {
        if ($header === '') {
            return null;
        }
        $available = self::getAvailableContentLocales();
        foreach (explode(',', $header) as $part) {
            $lang = strtolower(trim(explode(';', $part)[0]));
            if (preg_match('/^([a-z]{2,5})/', $lang, $m)) {
                $code = $m[1];
                if (isset($available[$code])) {
                    return $code;
                }
            }
        }
        return null;
    }
}
