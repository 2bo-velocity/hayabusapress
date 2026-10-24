<?php
/**
 * HayabusaPress - Front theme manager (scan / activate / delete / publish assets).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Manages the active public theme.
 *
 * - A theme is a self-contained directory at themes/{name}/ with *.tpl.php + assets/ + theme.json.
 * - The active theme name is persisted in storage/themes/active.php (one theme at a time).
 * - On activation, the theme's assets are published to public/assets/themes/{name}/ and all FPC /
 *   front view caches are purged so Auto-Healing regenerates pages with the new theme.
 * - Templates reference assets via ThemeManager::assetBase() => /assets/themes/{name}.
 * - theme.json may declare "layouts": which widget areas a page shows, plus a body class. A post
 *   picks one (posts.layout); themes without the key get a single "default" layout showing every
 *   area, which is the pre-1.3.0 behavior.
 */
class ThemeManager
{
    public  const DEFAULT_THEME = 'default';
    private const ACTIVE_FILE   = STORAGE_PATH . '/themes/active.php';
    private const THEMES_DIR    = ROOT_PATH . '/themes';
    private const PUBLISH_DIR   = PUBLIC_PATH . '/assets/themes';

    /** Minimum templates a theme must provide to be activatable. */
    private const REQUIRED_TEMPLATES = ['index', 'post', 'page', 'header', 'footer'];

    /** Layout used when a post names none, or names one the active theme does not declare. */
    public const DEFAULT_LAYOUT = 'default';

    private static ?string $active = null;
    /** @var array<string,array>|null request-local memo for layouts() */
    private static ?array $layouts = null;

    /** The active theme name (falls back to 'default' when unset/invalid/missing). */
    public static function active(): string
    {
        if (self::$active !== null) {
            return self::$active;
        }
        $name = self::DEFAULT_THEME;
        if (is_file(self::ACTIVE_FILE)) {
            $v = require self::ACTIVE_FILE;
            if (is_string($v) && self::isValidName($v) && is_dir(self::THEMES_DIR . '/' . $v)) {
                $name = $v;
            }
        }
        return self::$active = $name;
    }

    /**
     * Layouts declared by the active theme, keyed by ID. Always contains DEFAULT_LAYOUT.
     *
     * theme.json:
     *   "layouts": {
     *     "wide": { "label": {"en": "Single column", "ja": "1カラム"},
     *               "areas": ["header_below", "content_before", "content_after", "footer"],
     *               "body_class": "hbp-layout-wide" }
     *   }
     * Unknown area names are dropped; a layout without "areas" shows every area.
     *
     * @return array<string, array{id:string,label:string|array,areas:string[],body_class:string}>
     */
    public static function layouts(): array
    {
        if (self::$layouts !== null) {
            return self::$layouts;
        }
        require_once CORE_PATH . '/SidebarCompiler.php';
        $all  = SidebarCompiler::LOCATIONS;
        $meta = [];
        $manifest = self::dir() . '/theme.json';
        if (is_file($manifest)) {
            $decoded = json_decode((string)file_get_contents($manifest), true);
            $meta    = is_array($decoded) ? $decoded : [];
        }

        $out = [];
        foreach ((array)($meta['layouts'] ?? []) as $id => $def) {
            if (!is_string($id) || !self::isValidName($id) || !is_array($def)) {
                continue;
            }
            $areas = isset($def['areas']) ? array_values(array_intersect((array)$def['areas'], $all)) : $all;
            $label = $def['label'] ?? $id;
            $out[$id] = [
                'id'         => $id,
                'label'      => is_array($label) || is_string($label) ? $label : $id,
                'areas'      => $areas,
                // Class names only: the value lands in an attribute of every page.
                'body_class' => trim((string)preg_replace('/[^A-Za-z0-9_\- ]/', '', (string)($def['body_class'] ?? ''))),
            ];
        }
        if (!isset($out[self::DEFAULT_LAYOUT])) {
            $out = [self::DEFAULT_LAYOUT => [
                'id' => self::DEFAULT_LAYOUT, 'label' => 'Default', 'areas' => $all, 'body_class' => '',
            ]] + $out;
        }
        return self::$layouts = $out;
    }

    /** Resolve a layout ID (posts.layout) to its definition, falling back to the default layout. */
    public static function layout(?string $id): array
    {
        $all = self::layouts();
        return $all[$id ?? self::DEFAULT_LAYOUT] ?? $all[self::DEFAULT_LAYOUT];
    }

    /** Display label of a layout for a locale (label may be a string or {"en": ..., "ja": ...}). */
    public static function layoutLabel(array $layout, string $locale): string
    {
        $label = $layout['label'] ?? $layout['id'] ?? '';
        if (is_array($label)) {
            return (string)($label[$locale] ?? $label['en'] ?? reset($label) ?: ($layout['id'] ?? ''));
        }
        return (string)$label;
    }

    /** Web base URL for the active theme's published assets, e.g. /assets/themes/default */
    public static function assetBase(): string
    {
        return '/assets/themes/' . self::active();
    }

    /** Absolute path to a theme directory (active theme when $name is empty). */
    public static function dir(string $name = ''): string
    {
        $name = $name !== '' ? $name : self::active();
        return self::THEMES_DIR . '/' . $name;
    }

    /** Slug guard (also blocks path traversal). */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,49}$/', $name) === 1;
    }

    /** A theme is usable only if it has all required templates. */
    public static function isComplete(string $name): bool
    {
        if (!self::isValidName($name) || !is_dir(self::dir($name))) {
            return false;
        }
        foreach (self::REQUIRED_TEMPLATES as $t) {
            if (!is_file(self::dir($name) . '/' . $t . '.tpl.php')) {
                return false;
            }
        }
        return true;
    }

    /**
     * List every theme directory with its manifest metadata.
     * @return array<string, array{name:string,version:string,author:string,description:string,screenshot:string,active:bool,complete:bool}>
     */
    public static function scan(): array
    {
        $themes = [];
        $dirs   = glob(self::THEMES_DIR . '/*', GLOB_ONLYDIR) ?: [];
        $active = self::active();
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (!self::isValidName($name)) {
                continue;
            }
            $meta = [];
            $manifest = $dir . '/theme.json';
            if (is_file($manifest)) {
                $decoded = json_decode((string)file_get_contents($manifest), true);
                if (is_array($decoded)) {
                    $meta = $decoded;
                }
            }
            $screenshot = '';
            if (is_file($dir . '/assets/screenshot.png')) {
                $screenshot = '/assets/themes/' . $name . '/screenshot.png';
            }
            $themes[$name] = [
                'name'        => (string)($meta['name']        ?? $name),
                'version'     => (string)($meta['version']     ?? '0.0.0'),
                'author'      => (string)($meta['author']      ?? ''),
                'description' => (string)($meta['description'] ?? ''),
                'screenshot'  => $screenshot,
                'active'      => $name === $active,
                'complete'    => self::isComplete($name),
            ];
        }
        return $themes;
    }

    /** Switch the active theme: persist, publish assets, and purge dependent caches. */
    public static function activate(string $name): void
    {
        if (!self::isValidName($name) || !is_dir(self::dir($name))) {
            throw new RuntimeException(HbpLocale::t('err_theme_not_found', 'The specified theme was not found.'));
        }
        if (!self::isComplete($name)) {
            throw new RuntimeException(HbpLocale::t('err_theme_incomplete', 'The theme is missing required templates (index/post/page/header/footer).'));
        }

        self::save($name);
        self::$active = $name;

        self::publishAssets($name);
        self::purgeForSwitch();
    }

    /** Delete an uploaded theme. The active theme and 'default' cannot be deleted. */
    public static function delete(string $name): void
    {
        if (!self::isValidName($name)) {
            return;
        }
        if ($name === self::DEFAULT_THEME) {
            throw new RuntimeException(HbpLocale::t('err_theme_delete_default', 'The default theme cannot be deleted.'));
        }
        if ($name === self::active()) {
            throw new RuntimeException(HbpLocale::t('err_theme_delete_active', 'The active theme cannot be deleted. Switch to another theme first.'));
        }
        self::removeDir(self::dir($name));
        self::removeDir(self::PUBLISH_DIR . '/' . $name);
    }

    /** Copy themes/{name}/assets/* into public/assets/themes/{name}/ (clean republish). */
    public static function publishAssets(string $name): void
    {
        if (!self::isValidName($name)) {
            return;
        }
        $src = self::dir($name) . '/assets';
        $dst = self::PUBLISH_DIR . '/' . $name;
        self::removeDir($dst);
        if (is_dir($src)) {
            self::copyDir($src, $dst);
        }
    }

    /** Publish the assets of every installed theme (used by system:init). */
    public static function publishAll(): void
    {
        foreach (array_keys(self::scan()) as $name) {
            self::publishAssets($name);
        }
    }

    /**
     * Re-apply the active theme without changing it: republish its assets and purge dependent
     * caches. Use after editing the active theme's templates/assets directly on the server.
     */
    public static function rebuild(): void
    {
        self::publishAssets(self::active());
        self::purgeForSwitch();
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    private static function save(string $name): void
    {
        self::$layouts = null;
        $dir = dirname(self::ACTIVE_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents(self::ACTIVE_FILE, "<?php\nreturn " . var_export($name, true) . ";\n", LOCK_EX);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::ACTIVE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    /** After a switch: drop all FPC + front view cache, recompute CSP hashes. */
    private static function purgeForSwitch(): void
    {
        if (class_exists('CacheManager') || is_file(CORE_PATH . '/CacheManager.php')) {
            require_once CORE_PATH . '/CacheManager.php';
            CacheManager::purgeAll();
        }
        require_once CORE_PATH . '/ViewCompiler.php';
        ViewCompiler::clearAll();
        if (is_file(CORE_PATH . '/SecurityHeaderCompiler.php')) {
            require_once CORE_PATH . '/SecurityHeaderCompiler.php';
            SecurityHeaderCompiler::compile();
        }
    }

    private static function copyDir(string $src, string $dst): void
    {
        @mkdir($dst, 0755, true);
        $items = scandir($src) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $from = $src . '/' . $item;
            $to   = $dst . '/' . $item;
            if (is_dir($from)) {
                self::copyDir($from, $to);
            } else {
                @copy($from, $to);
            }
        }
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
