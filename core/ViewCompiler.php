<?php
/**
 * HayabusaPress - Template compiler (regex to raw PHP, MUI-capable).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Ultra-lightweight template compiler that bulk-converts to raw PHP via regex (MUI-capable).
 *
 * - Compiled only once. Zero parse cost at runtime.
 * - Bakes {@ key @} into fixed strings from the language dictionary (MUI: Multilingual User Interface)
 * - Per-language physical cache: storage/cache/views/{admin|frontend}/{locale}/{md5}.cache.php
 */
class ViewCompiler
{
    /**
     * Compile a template and return the cache path.
     *
     * @param string $viewName template name (e.g. 'admin/index', 'post')
     * @param string $locale   language code (e.g. 'ja', 'en')
     */
    public static function compile(string $viewName, string $locale = 'en'): string
    {
        if (str_starts_with($viewName, 'admin/')) {
            $name    = substr($viewName, 6);
            $srcFile = CORE_PATH . '/admin_views/' . $name . '.tpl.php';
            $subDir  = 'admin';
        } elseif (str_starts_with($viewName, 'plugin/')) {
            // plugin/{id}/{template} → plugins/{id}/{template}.tpl.php
            $parts    = explode('/', $viewName, 3);
            $pluginId = $parts[1] ?? '';
            $tplName  = $parts[2] ?? '';
            $srcFile  = ROOT_PATH . '/plugins/' . $pluginId . '/' . $tplName . '.tpl.php';
            $subDir   = 'frontend';
        } else {
            $theme   = class_exists('ThemeManager') ? ThemeManager::active() : 'default';
            $srcFile = ROOT_PATH . '/themes/' . $theme . '/' . $viewName . '.tpl.php';
            // Resilience: fall back to the default theme's template for any view the active theme omits.
            if (!is_file($srcFile) && $theme !== 'default') {
                $srcFile = ROOT_PATH . '/themes/default/' . $viewName . '.tpl.php';
            }
            $subDir  = 'frontend';
        }

        $cacheDir  = STORAGE_PATH . '/cache/views/' . $subDir . '/' . $locale;
        $cacheName = md5($viewName) . '.cache.php';
        $cachePath = $cacheDir . '/' . $cacheName;

        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        if (!file_exists($srcFile)) {
            throw new RuntimeException(class_exists('HbpLocale')
                ? HbpLocale::t('err_view_not_found', 'Template not found: :file', ['file' => $srcFile])
                : "Template not found: {$srcFile}");
        }

        $source   = file_get_contents($srcFile);
        $compiled = self::transform($source);

        // Asset inline packing: {# inline_css 'file.css' #} / {# inline_js 'file.js' #}
        // Applied to front themes only (not to admin or plugins)
        if ($subDir === 'frontend') {
            $compiled = self::compileAssets($compiled);
        }

        // MUI: bake {@ key @} into fixed strings from the language dictionary
        $compiled = self::compileMui($compiled, $viewName, $locale);

        file_put_contents($cachePath, $compiled, LOCK_EX);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cachePath, true);   // see SidebarCompiler::forgetCompiled()
        }

        return $cachePath;
    }

    /** Bulk-convert template syntax -> PHP code */
    private static function transform(string $source): string
    {
        // {{{ $raw }}} -> raw HTML output (no escaping)
        $source = preg_replace(
            '/\{\{\{\s*(.+?)\s*\}\}\}/',
            '<?php echo $1 ?? \'\'; ?>',
            $source
        );

        // {{ $var }} -> htmlspecialchars-escaped output
        $source = preg_replace(
            '/\{\{\s*(.+?)\s*\}\}/',
            '<?php echo htmlspecialchars((string)($1 ?? \'\'), ENT_QUOTES, \'UTF-8\'); ?>',
            $source
        );

        // {% if $cond %}
        // Note: a trailing ?? false is appended, so a single variable ($x / $x['k']) is safe even if undefined.
        // However, for compound expressions with operators (e.g. $_GET['k'] === 'v'), ?? false binds only to the
        // right-hand side, leaving an undefined-key warning. Guard compound expressions in the template, e.g.
        // ($_GET['k'] ?? '') === 'v' (regex cannot make compound expressions safe).
        $source = preg_replace(
            '/\{%\s*if\s+(.+?)\s*%\}/',
            '<?php if ($1 ?? false): ?>',
            $source
        );

        // {% elseif $cond %}
        $source = preg_replace(
            '/\{%\s*elseif\s+(.+?)\s*%\}/',
            '<?php elseif ($1 ?? false): ?>',
            $source
        );

        // {% else %}
        $source = str_replace('{% else %}', '<?php else: ?>', $source);

        // {% endif %}
        $source = str_replace('{% endif %}', '<?php endif; ?>', $source);

        // {% foreach $arr as $v %}
        $source = preg_replace(
            '/\{%\s*foreach\s+(.+?)\s+as\s+(.+?)\s*%\}/',
            '<?php foreach ($1 ?? [] as $2): ?>',
            $source
        );

        // {% endforeach %}
        $source = str_replace('{% endforeach %}', '<?php endforeach; ?>', $source);

        // {% include 'name' %}
        $source = preg_replace(
            '/\{%\s*include\s+[\'"](.+?)[\'"]\s*%\}/',
            '<?php View::render(\'$1\', get_defined_vars()); ?>',
            $source
        );

        return $source;
    }

    /**
     * Asset inline packing
     * {# inline_css 'style.css' #} -> <style>minified CSS</style> (statically embedded at compile time)
     * {# inline_js 'app.js' #}   -> <script>minified JS</script> (statically embedded at compile time)
     */
    private static function compileAssets(string $content): string
    {
        if (!str_contains($content, '{#')) {
            return $content;
        }

        // Lazy-load AssetCompiler
        if (!class_exists('AssetCompiler') && file_exists(CORE_PATH . '/AssetCompiler.php')) {
            require_once CORE_PATH . '/AssetCompiler.php';
        }

        if (!class_exists('AssetCompiler')) {
            return $content;
        }

        $content = preg_replace_callback(
            '/\{#\s*inline_css\s+[\'"]([^\'"]+)[\'"]\s*#\}/',
            static fn(array $m): string => AssetCompiler::inlineCss($m[1]),
            $content
        ) ?? $content;

        $content = preg_replace_callback(
            '/\{#\s*inline_js\s+[\'"]([^\'"]+)[\'"]\s*#\}/',
            static fn(array $m): string => AssetCompiler::inlineJs($m[1]),
            $content
        ) ?? $content;

        return $content;
    }

    /**
     * MUI: bake {@ key @} into fixed strings from the language dictionary.
     * Keys not in the dictionary remain as [key], making missing translations obvious at a glance.
     */
    private static function compileMui(string $content, string $viewName, string $locale): string
    {
        // Skip if there is no translation placeholder
        if (!str_contains($content, '{@')) {
            return $content;
        }

        $dict = str_starts_with($viewName, 'admin/')
            ? HbpLocale::loadAdminDictionary($locale)
            : HbpLocale::loadFrontendDictionary($locale);

        // '.' and '-' are allowed so plugin keys ({plugin-id}.key, e.g. hbp-contact.subscribe)
        // resolve here too. An unknown key renders as [key], which makes the gap visible on screen.
        return preg_replace_callback(
            '/\{@\s*([a-z0-9_.\-]+)\s*@\}/',
            static function (array $m) use ($dict): string {
                $key = $m[1];
                return isset($dict[$key])
                    ? htmlspecialchars($dict[$key], ENT_QUOTES, 'UTF-8')
                    : '[' . $key . ']';
            },
            $content
        );
    }

    /**
     * Delete all caches (on dictionary update / template update).
     * Pass $locale to delete only that language.
     */
    public static function clearAll(?string $locale = null): void
    {
        foreach (['admin', 'frontend'] as $sub) {
            $baseDir = STORAGE_PATH . '/cache/views/' . $sub;
            if (!is_dir($baseDir)) continue;

            if ($locale !== null) {
                // Delete only the specified language
                $dir = $baseDir . '/' . $locale;
                if (is_dir($dir)) {
                    foreach (glob($dir . '/*.cache.php') ?: [] as $f) {
                        unlink($f);
                    }
                }
            } else {
                // Delete all languages
                foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                    foreach (glob($dir . '/*.cache.php') ?: [] as $f) {
                        unlink($f);
                    }
                }
                // Also delete legacy-format caches (without the language subdirectory)
                foreach (glob($baseDir . '/*.cache.php') ?: [] as $f) {
                    unlink($f);
                }
            }
        }
    }
}
