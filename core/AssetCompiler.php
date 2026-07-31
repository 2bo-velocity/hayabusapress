<?php
/**
 * HayabusaPress - CSS/JS inline packing and lightweight minifier.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * CSS/JS inline packing + lightweight minifier (zero external dependencies)
 *
 * Bakes a theme's CSS/JS into HTML <style>/<script> tags as raw strings at View compile time,
 * compressing readers' HTTP requests into a single HTML hit.
 *
 * - Minify happens only once at compile time. Zero runtime cost.
 * - Not applied to the admin screen (front public theme only)
 */
class AssetCompiler
{
    /**
     * Minify theme CSS and return it as <style>...</style>
     * Corresponds to {# inline_css 'file.css' #} at ViewCompiler::compile() time
     */
    public static function inlineCss(string $filename): string
    {
        $theme = class_exists('ThemeManager') ? ThemeManager::active() : 'default';
        $path  = ROOT_PATH . '/themes/' . $theme . '/assets/' . basename($filename);
        if (!file_exists($path)) {
            return '';
        }
        $css = (string)file_get_contents($path);
        return '<style>' . self::minifyCss($css) . '</style>';
    }

    /**
     * Minify theme JS and return it as <script>...</script>
     * Corresponds to {# inline_js 'file.js' #} at ViewCompiler::compile() time
     */
    public static function inlineJs(string $filename): string
    {
        $theme = class_exists('ThemeManager') ? ThemeManager::active() : 'default';
        $path  = ROOT_PATH . '/themes/' . $theme . '/assets/' . basename($filename);
        if (!file_exists($path)) {
            return '';
        }
        $js = (string)file_get_contents($path);
        return '<script>' . self::minifyJs($js) . '</script>';
    }

    /**
     * Lightweight CSS minifier
     * - Remove comments
     * - Collapse consecutive whitespace/newlines into a single space
     * - Remove whitespace around { } : ; ,
     * - Optimize the } just before a trailing semicolon
     */
    public static function minifyCss(string $css): string
    {
        // Remove comments
        $css = preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css;
        // Collapse consecutive whitespace/newlines into a single space
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        // Remove whitespace around { } : ; , > ~ +
        $css = preg_replace('/\s*([{}:;,>~+])\s*/', '$1', $css) ?? $css;
        // Remove unnecessary trailing semicolons (the ; just before })
        $css = str_replace(';}', '}', $css);

        return trim($css);
    }

    /**
     * Lightweight JS minifier
     * - Remove block comments
     * - Remove line comments (//)
     * - Trim leading/trailing whitespace per line + remove blank lines
     * Note: limited to a conservative process that does not break string/regex literals
     */
    public static function minifyJs(string $js): string
    {
        // Remove block comments
        $js = preg_replace('/\/\*.*?\*\//s', '', $js) ?? $js;
        // Remove line comments
        $js = preg_replace('/\/\/[^\n]*/', '', $js) ?? $js;
        // Trim leading/trailing whitespace per line + remove blank lines
        $lines = array_filter(
            array_map('trim', explode("\n", $js)),
            static fn(string $l): bool => $l !== ''
        );
        return implode("\n", $lines);
    }
}
