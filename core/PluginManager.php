<?php
/**
 * HayabusaPress - Manifest-driven static hook-registry plugin manager.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Manifest-driven, static hook-registry plugin manager
 *
 * manifest.json declares metadata + hooks. rebuildCache() precompiles
 * plugins.cache.php (the master registry) and hooks.cache.php (the sorted hook map).
 *
 * Static page snippets ("snippets": {"head": ..., "body_end": ...}) are concatenated into
 * plugin_snippets.cache.php at the same time. Themes print them with snippet(), so they are baked
 * into the full-page cache and cost nothing per request.
 */
class PluginManager
{
    private const ACTIVE_FILE    = STORAGE_PATH . '/plugins/active.php';
    private const PLUGINS_CACHE  = STORAGE_PATH . '/cache/plugins.cache.php';
    private const HOOKS_CACHE    = STORAGE_PATH . '/cache/hooks.cache.php';
    private const ROUTES_CACHE   = STORAGE_PATH . '/cache/plugin_routes.cache.php';
    private const PLUGINS_DIR    = ROOT_PATH    . '/plugins';
    /** Compiled plugin catalogs, one file per locale: plugin_lang_{code}.cache.php */
    private const LANG_CACHE_FMT = STORAGE_PATH . '/cache/plugin_lang_%s.cache.php';
    /** Concatenated static page snippets of enabled plugins. Also read by SecurityHeaderCompiler. */
    public const SNIPPETS_CACHE  = STORAGE_PATH . '/cache/plugin_snippets.cache.php';
    /** Where a snippet may be printed. The theme decides the exact position. */
    public const SNIPPET_SLOTS   = ['head', 'body_end'];
    /** Upper bound per snippet file: a snippet is a fragment, not a page. */
    private const SNIPPET_MAX_BYTES = 65536;

    /** @var array<string,string>|null request-local memo for snippet() */
    private static ?array $snippets = null;

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Scan all manifests and recompile the registry + hook map.
     * Called when a plugin is added, enabled, disabled, or its manifest changes.
     */
    public static function rebuildCache(): void
    {
        $active       = self::loadActive();
        $plugins      = [];
        $hooks        = [];
        $pluginRoutes = [];
        $snippets     = array_fill_keys(self::SNIPPET_SLOTS, []);

        $dirs = glob(self::PLUGINS_DIR . '/*/manifest.json') ?: [];

        foreach ($dirs as $manifestFile) {
            $dir  = dirname($manifestFile);
            $name = basename($dir);

            $json = file_get_contents($manifestFile);
            if ($json === false) continue;
            $meta = json_decode($json, true);
            if (!is_array($meta)) continue;

            $isActive = in_array($name, $active, true);

            $plugins[$name] = [
                'id'            => $meta['id']            ?? $name,
                'name'          => $meta['name']           ?? $name,
                'version'       => $meta['version']        ?? '0.0.0',
                'author'        => $meta['author']         ?? '',
                'description'   => $meta['description']    ?? '',
                'license'       => $meta['license']        ?? '',
                'status'        => $isActive ? 'enabled' : 'disabled',
                'dir_name'      => $name,
                'active'        => $isActive,
                'hooks'         => $meta['hooks']          ?? [],
                'routes'        => $meta['routes']         ?? [],
                'page_template' => $meta['page_template']  ?? null,
                'snippets'      => $meta['snippets']       ?? [],
            ];

            // Compile only enabled plugins
            if (!$isActive) continue;

            // Hook compilation
            foreach ($meta['hooks'] ?? [] as $eventName => $hookDef) {
                $relFile = ltrim($hookDef['file'] ?? '', '/');
                $absFile = ROOT_PATH . '/' . $relFile;

                if (!file_exists($absFile)) {
                    continue;
                }

                $hooks[$eventName][] = [
                    'file'     => $absFile,
                    'class'    => $hookDef['class']    ?? '',
                    'method'   => $hookDef['method']   ?? '',
                    'priority' => (int)($hookDef['priority'] ?? 10),
                ];
            }

            // Static route compilation (from the routes declaration in manifest.json)
            foreach ($meta['routes'] ?? [] as $routePath => $routeDef) {
                $relFile = ltrim($routeDef['file'] ?? '', '/');
                $absFile = ROOT_PATH . '/' . $relFile;

                if (!file_exists($absFile)) {
                    continue;
                }

                $pluginRoutes[$routePath] = [
                    'plugin_id' => $name,      // Referenced by RouterCompiler and handlePlugin()
                    'file'      => $absFile,
                    'class'     => $routeDef['class']  ?? '',
                    'method'    => $routeDef['method'] ?? '',
                ];
            }

            // Page-template route info (RouterCompiler matches it against the page_template column)
            if (!empty($meta['page_template'])) {
                $templateKey = $meta['page_template'];
                // Save the route definition under the page_template key
                if (isset($meta['template_route'])) {
                    $routeDef = $meta['template_route'];
                    $relFile  = ltrim($routeDef['file'] ?? '', '/');
                    $absFile  = ROOT_PATH . '/' . $relFile;
                    if (file_exists($absFile)) {
                        $pluginRoutes['__template__' . $templateKey] = [
                            'file'   => $absFile,
                            'class'  => $routeDef['class']  ?? '',
                            'method' => $routeDef['method'] ?? '',
                        ];
                    }
                }
            }

            // Static page snippets (read now, so nothing is touched at request time)
            foreach ((array)($meta['snippets'] ?? []) as $slot => $relFile) {
                if (!isset($snippets[$slot]) || !is_string($relFile)) {
                    continue;
                }
                $html = self::readSnippet($dir, $relFile);
                if ($html !== null) {
                    $snippets[$slot][] = $html;
                }
            }
        }

        // Sort each event's hooks by ascending priority
        foreach ($hooks as $eventName => &$eventHooks) {
            usort($eventHooks, fn($a, $b) => $a['priority'] <=> $b['priority']);
        }
        unset($eventHooks);

        // plugins.cache.php (master registry: for the admin UI)
        $export = var_export($plugins, true);
        file_put_contents(
            self::PLUGINS_CACHE,
            "<?php\n// HBP Plugin Registry - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n",
            LOCK_EX
        );
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::PLUGINS_CACHE, true);   // see SidebarCompiler::forgetCompiled()
        }

        // hooks.cache.php (sorted static hook registry: for runtime)
        $export = var_export($hooks, true);
        file_put_contents(
            self::HOOKS_CACHE,
            "<?php\n// HBP Hook Registry - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n",
            LOCK_EX
        );
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::HOOKS_CACHE, true);   // see SidebarCompiler::forgetCompiled()
        }

        // plugin_routes.cache.php (static plugin routes + template route definitions)
        $export = var_export($pluginRoutes, true);
        file_put_contents(
            self::ROUTES_CACHE,
            "<?php\n// HBP Plugin Routes - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n",
            LOCK_EX
        );
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::ROUTES_CACHE, true);   // see SidebarCompiler::forgetCompiled()
        }

        self::compileSnippets($snippets);
        self::compileLanguagePacks($active);
    }

    /**
     * Static page snippet for a slot ('head' / 'body_end'), for themes to print.
     *
     * Called while a page is rendered, which on the public side means only when the full-page cache
     * is generated: the result is baked into the cached HTML. Auto-Healing if the cache is missing.
     */
    public static function snippet(string $slot): string
    {
        if (self::$snippets === null) {
            if (!is_file(self::SNIPPETS_CACHE)) {
                self::rebuildCache();
            }
            $data = is_file(self::SNIPPETS_CACHE) ? require self::SNIPPETS_CACHE : [];
            self::$snippets = is_array($data) ? $data : [];
        }
        return self::$snippets[$slot] ?? '';
    }

    /**
     * Read one snippet file declared in a manifest. Returns null (and logs) when the path leaves the
     * plugin directory, the file is missing, or it is too large.
     *
     * Line endings are normalized to LF: the browser hashes an inline script after the HTML parser
     * has done the same, so a CRLF file would otherwise never match its CSP hash.
     */
    private static function readSnippet(string $pluginDir, string $relFile): ?string
    {
        $base = realpath($pluginDir);
        $path = realpath($pluginDir . '/' . ltrim($relFile, '/'));
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            error_log("[HBP WARNING] plugin snippet '{$relFile}' in " . basename($pluginDir) . ' is missing or outside the plugin directory -- skipped.');
            return null;
        }
        if (filesize($path) > self::SNIPPET_MAX_BYTES) {
            error_log("[HBP WARNING] plugin snippet '{$relFile}' in " . basename($pluginDir) . ' exceeds ' . self::SNIPPET_MAX_BYTES . ' bytes -- skipped.');
            return null;
        }
        $html = file_get_contents($path);
        if ($html === false) {
            return null;
        }
        return trim(str_replace(["\r\n", "\r"], "\n", $html));
    }

    /**
     * Write plugin_snippets.cache.php. When the output changed, every cached page carries the old
     * snippets and the CSP carries the old hashes, so both are refreshed here.
     *
     * @param array<string,string[]> $bySlot snippets per slot, in plugin directory order
     */
    private static function compileSnippets(array $bySlot): void
    {
        $new = [];
        foreach (self::SNIPPET_SLOTS as $slot) {
            $new[$slot] = implode("\n", $bySlot[$slot] ?? []);
        }

        $old = is_file(self::SNIPPETS_CACHE) ? require self::SNIPPETS_CACHE : null;
        if (!is_array($old)) {
            $old = array_fill_keys(self::SNIPPET_SLOTS, '');
        }

        $export = var_export($new, true);
        $tmp    = self::SNIPPETS_CACHE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, "<?php\n// HBP Plugin Snippets - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n", LOCK_EX);
        rename($tmp, self::SNIPPETS_CACHE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::SNIPPETS_CACHE, true);   // see SidebarCompiler::forgetCompiled()
        }
        self::$snippets = $new;

        if ($old === $new) {
            return;
        }
        require_once CORE_PATH . '/CacheManager.php';
        require_once CORE_PATH . '/SecurityHeaderCompiler.php';
        CacheManager::purgeAll();
        SecurityHeaderCompiler::compile();
    }

    /**
     * Merge the language packs of enabled plugins into one compiled catalog per locale.
     *
     * Reading plugins/{id}/lang/{code}.php at request time would be exactly the runtime directory
     * scan the plugin contract forbids, so the merge happens here and HbpLocale only ever requires
     * the resulting file. Locales are discovered from the packs themselves, so a plugin may ship a
     * language the core does not have.
     *
     * Keys are expected to be namespaced as '{plugin-id}.key'. A key that is not namespaced, or that
     * collides with another plugin, is skipped: a plugin must not be able to silently redefine
     * another plugin's wording. Collisions with core keys are resolved at load time (core wins).
     *
     * @param string[] $active enabled plugin directory names
     */
    private static function compileLanguagePacks(array $active): void
    {
        $byLocale = [];
        $owner    = [];

        foreach ($active as $dirName) {
            if (!is_string($dirName) || $dirName === '' || str_contains($dirName, '/') || str_contains($dirName, '..')) {
                continue;
            }
            foreach (glob(self::PLUGINS_DIR . '/' . $dirName . '/lang/*.php') ?: [] as $file) {
                if (!preg_match('/([a-z]{2,5})\.php$/', basename($file), $m)) {
                    continue;
                }
                $locale = $m[1];
                $data   = require $file;
                if (!is_array($data)) {
                    continue;
                }
                foreach ($data as $key => $value) {
                    if (!is_string($key) || !is_string($value)) {
                        continue;
                    }
                    if (!str_starts_with($key, $dirName . '.')) {
                        error_log("[HBP WARNING] plugin lang key '{$key}' in {$dirName} is not prefixed with '{$dirName}.' -- skipped.");
                        continue;
                    }
                    if (isset($owner[$key]) && $owner[$key] !== $dirName) {
                        error_log("[HBP WARNING] plugin lang key '{$key}' is already defined by {$owner[$key]} -- {$dirName} skipped.");
                        continue;
                    }
                    $owner[$key]            = $dirName;
                    $byLocale[$locale][$key] = $value;
                }
            }
        }

        // Remove stale catalogs so a disabled/deleted plugin stops contributing strings.
        foreach (glob(sprintf(self::LANG_CACHE_FMT, '*')) ?: [] as $old) {
            @unlink($old);
        }
        foreach ($byLocale as $locale => $dict) {
            ksort($dict);
            $export = var_export($dict, true);
            file_put_contents(
                sprintf(self::LANG_CACHE_FMT, $locale),
                "<?php\n// HBP Plugin Language Pack [{$locale}] - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n",
                LOCK_EX
            );
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate(sprintf(self::LANG_CACHE_FMT, $locale), true);   // see SidebarCompiler::forgetCompiled()
            }
        }

        // {@ key @} is resolved at view-compile time, so a view compiled before this catalog
        // existed would keep rendering '[plugin-id.key]' forever. Drop the compiled views and
        // let Auto-Healing rebuild them against the catalog we just wrote.
        $viewCompiler = CORE_PATH . '/ViewCompiler.php';
        if (is_file($viewCompiler)) {
            require_once $viewCompiler;
            ViewCompiler::clearAll();
        }
    }

    /**
     * Get the plugin static route map (for RouterCompiler).
     * Auto-Healing if plugin_routes.cache.php is missing.
     */
    public static function getPluginRoutes(): array
    {
        if (!file_exists(self::ROUTES_CACHE)) {
            self::rebuildCache();
        }
        return require self::ROUTES_CACHE;
    }

    /**
     * O(1) retrieval of the sorted hook list for the given event.
     * Auto-rebuild via Auto-Healing if hooks.cache.php is missing.
     */
    public static function getHooks(string $eventName): array
    {
        if (!file_exists(self::HOOKS_CACHE)) {
            self::rebuildCache();
        }
        $hooks = require self::HOOKS_CACHE;
        return $hooks[$eventName] ?? [];
    }

    /**
     * Get the plugin master list (for the admin UI).
     * Auto-Healing if plugins.cache.php is missing.
     */
    public static function getList(): array
    {
        if (!file_exists(self::PLUGINS_CACHE)) {
            self::rebuildCache();
        }
        return require self::PLUGINS_CACHE;
    }

    /** Alias of getList() (backward compatibility) */
    public static function scan(): array
    {
        return self::getList();
    }

    /**
     * Whether a plugin is currently activated.
     *
     * Reads storage/plugins/active.php, which is the single truth for activation
     * (manifest.json's "status" is only an initial value). Use this before showing
     * UI or serving routes that depend on something a plugin installs, such as a
     * table created by its database/install.{driver}.sql.
     */
    public static function isActive(string $dirName): bool
    {
        return in_array($dirName, self::loadActive(), true);
    }

    /** Alias of compile() (backward compatibility) */
    public static function compile(): void
    {
        self::rebuildCache();
    }

    // ----------------------------------------------------------------
    // Enable / disable
    // ----------------------------------------------------------------

    public static function activate(string $dirName): void
    {
        self::runLifecycleSql($dirName, 'install');
        $active = self::loadActive();
        if (!in_array($dirName, $active, true)) {
            $active[] = $dirName;
            self::saveActive($active);
        }
        self::rebuildCache();
    }

    public static function deactivate(string $dirName): void
    {
        $active = array_values(array_filter(self::loadActive(), fn($n) => $n !== $dirName));
        self::saveActive($active);
        self::rebuildCache();
    }

    public static function delete(string $dirName): void
    {
        if ($dirName === '' || str_contains($dirName, '..') || str_contains($dirName, '/')) {
            return;
        }
        self::deactivate($dirName);
        self::runLifecycleSql($dirName, 'uninstall');
        $pluginDir = ROOT_PATH . '/plugins/' . $dirName;
        if (is_dir($pluginDir)) {
            self::removeDir($pluginDir);
        }
        self::rebuildCache();
    }

    private static function runLifecycleSql(string $dirName, string $type): void
    {
        $driver  = class_exists('Env') ? Env::get('DB_DRIVER', 'mysql') : ($_ENV['DB_DRIVER'] ?? 'mysql');
        $sqlFile = ROOT_PATH . '/plugins/' . $dirName . '/database/' . $type . '.' . $driver . '.sql';
        if (!file_exists($sqlFile)) {
            return;
        }
        try {
            DB::getInstance()->exec((string) file_get_contents($sqlFile));
        } catch (Throwable) {
        }
    }

    private static function removeDir(string $dir): void
    {
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ----------------------------------------------------------------
    // ZIP auto-install (the three big checks)
    // ----------------------------------------------------------------

    public static function installFromZip(array $uploadedFile): bool
    {
        // (1) MIME / extension check
        $ext = strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION));
        if ($ext !== 'zip') return false;

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        if ($finfo->file($uploadedFile['tmp_name']) !== 'application/zip') return false;

        $zip = new ZipArchive();
        if ($zip->open($uploadedFile['tmp_name']) !== true) return false;

        // (2) Path-traversal attack check
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, '..') || str_contains($name, "\0")) {
                $zip->close();
                return false;
            }
        }

        // (3) manifest.json existence check (decided without extracting a single file)
        $hasManifest = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts = explode('/', $zip->getNameIndex($i));
            if (end($parts) === 'manifest.json' && count($parts) === 2) {
                $hasManifest = true;
                break;
            }
        }
        if (!$hasManifest) {
            $zip->close();
            return false;
        }

        $zip->extractTo(self::PLUGINS_DIR);
        $zip->close();
        self::rebuildCache();
        return true;
    }

    // ----------------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------------

    private static function loadActive(): array
    {
        if (!file_exists(self::ACTIVE_FILE)) return [];
        return require self::ACTIVE_FILE;
    }

    private static function saveActive(array $active): void
    {
        $export = var_export($active, true);
        file_put_contents(self::ACTIVE_FILE, "<?php\nreturn {$export};\n", LOCK_EX);
        // rebuildCache() requires this file right after; without this it may get the cached old list
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::ACTIVE_FILE, true);
        }
    }
}
