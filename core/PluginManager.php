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
    /** Per-plugin state for the admin panel (compile warnings), keyed by directory name. */
    private const STATE_FILE     = STORAGE_PATH . '/plugins/state.php';
    /** The events the core and the bundled plugins fire. A hook on any other name never runs. */
    public const EVENTS          = ['before_comment_create', 'before_contact_send', 'post_saved', 'content_render'];

    /**
     * Warnings found by the rebuild in progress: dir name => [[message key, params], ...].
     * Messages are stored as keys so the admin panel shows them in the viewer's language.
     */
    private static array $warnings = [];

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
        self::$warnings = [];
        // Plugins whose files changed outside the admin panel and that the last check stopped
        // (a file that does not parse, or PLUGIN_MODIFIED_ACTION=disable). active.php is not touched:
        // acknowledging the change brings them back.
        require_once CORE_PATH . '/Integrity.php';
        $stopped = Integrity::disabledPlugins();

        $dirs = glob(self::PLUGINS_DIR . '/*/manifest.json') ?: [];

        foreach ($dirs as $manifestFile) {
            $dir  = dirname($manifestFile);
            $name = basename($dir);
            if ($name[0] === '_') {
                continue;   // plugins/_incoming/ and work directories are not plugins
            }

            $json = file_get_contents($manifestFile);
            $meta = $json === false ? null : json_decode($json, true);
            if (!is_array($meta)) {
                self::warn($name, 'pw_manifest_invalid', [], 'manifest.json is not valid JSON; the plugin was not loaded.');
                continue;
            }

            $isActive = in_array($name, $active, true) && !in_array($name, $stopped, true);

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
                if (!in_array($eventName, self::EVENTS, true)) {
                    self::warn($name, 'pw_unknown_event', ['event' => $eventName], "hook '{$eventName}' is not an event HayabusaPress fires; it never runs.");
                }
                $absFile = self::resolveCodeFile($dir, $hookDef['file'] ?? '');
                if ($absFile === null) {
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
                $absFile = self::resolveCodeFile($dir, $routeDef['file'] ?? '');
                if ($absFile === null) {
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
                    $absFile  = self::resolveCodeFile($dir, $routeDef['file'] ?? '');
                    if ($absFile !== null) {
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
        self::writeCache(self::PLUGINS_CACHE, 'HBP Plugin Registry', $export);

        // hooks.cache.php (sorted static hook registry: for runtime)
        $export = var_export($hooks, true);
        self::writeCache(self::HOOKS_CACHE, 'HBP Hook Registry', $export);

        // plugin_routes.cache.php (static plugin routes + template route definitions)
        $export = var_export($pluginRoutes, true);
        self::writeCache(self::ROUTES_CACHE, 'HBP Plugin Routes', $export);

        self::compileSnippets($snippets);
        self::compileLanguagePacks($active);
        self::writeState();
    }

    /**
     * Per-plugin state for the admin panel: dir name => ['warnings' => [[key, params], ...]].
     * Rebuilt by every rebuildCache(); empty before the first one.
     */
    public static function state(): array
    {
        if (!is_file(self::STATE_FILE)) {
            return [];
        }
        $state = require self::STATE_FILE;
        return is_array($state) ? $state : [];
    }

    /** Note a problem the rebuild skipped over: for the error log and for the plugins screen. */
    private static function warn(string $plugin, string $key, array $params, string $logMessage): void
    {
        error_log("[HBP WARNING] plugin {$plugin}: {$logMessage}");
        self::$warnings[$plugin][] = [$key, $params];
    }

    private static function writeState(): void
    {
        $state = [];
        foreach (self::$warnings as $plugin => $list) {
            $state[$plugin]['warnings'] = $list;
        }
        self::writeCache(self::STATE_FILE, 'HBP Plugin State', var_export($state, true));
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
    /**
     * Resolve the "file" of a hook / route / template_route to an absolute path, or null.
     *
     * The manifest writes it relative to the install root ("/plugins/{id}/plugin.php"). It must
     * be a .php file inside the plugin's own directory: the entry is require'd at request time,
     * so a path to another plugin, the core or a non-PHP file such as .env (whose contents
     * require would print to the visitor) is refused.
     */
    private static function resolveCodeFile(string $pluginDir, mixed $relFile): ?string
    {
        if (!is_string($relFile) || $relFile === '') {
            return null;
        }
        $base = realpath($pluginDir);
        $path = realpath(ROOT_PATH . '/' . ltrim($relFile, '/'));
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)
            || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            self::warn(basename($pluginDir), 'pw_file_skipped', ['file' => $relFile], "plugin file '{$relFile}' is missing, not a .php file or outside the plugin directory -- skipped.");
            return null;
        }
        return $path;
    }

    private static function readSnippet(string $pluginDir, string $relFile): ?string
    {
        $base = realpath($pluginDir);
        $path = realpath($pluginDir . '/' . ltrim($relFile, '/'));
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            self::warn(basename($pluginDir), 'pw_snippet_skipped', ['file' => $relFile], "plugin snippet '{$relFile}' is missing or outside the plugin directory -- skipped.");
            return null;
        }
        if (filesize($path) > self::SNIPPET_MAX_BYTES) {
            self::warn(basename($pluginDir), 'pw_snippet_too_big', ['file' => $relFile, 'max' => self::SNIPPET_MAX_BYTES], "plugin snippet '{$relFile}' exceeds " . self::SNIPPET_MAX_BYTES . ' bytes -- skipped.');
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
        self::writeCache(self::SNIPPETS_CACHE, 'HBP Plugin Snippets', $export);
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
                        self::warn($dirName, 'pw_lang_prefix', ['key' => $key], "plugin lang key '{$key}' is not prefixed with '{$dirName}.' -- skipped.");
                        continue;
                    }
                    if (isset($owner[$key]) && $owner[$key] !== $dirName) {
                        self::warn($dirName, 'pw_lang_taken', ['key' => $key, 'owner' => $owner[$key]], "plugin lang key '{$key}' is already defined by {$owner[$key]} -- skipped.");
                        continue;
                    }
                    $owner[$key]            = $dirName;
                    $byLocale[$locale][$key] = $value;
                }
            }
        }

        $written = [];
        foreach ($byLocale as $locale => $dict) {
            ksort($dict);
            $path = sprintf(self::LANG_CACHE_FMT, $locale);
            self::writeCache($path, "HBP Plugin Language Pack [{$locale}]", var_export($dict, true));
            $written[$path] = true;
        }
        // Remove the catalogs of locales no active plugin provides any more, so a disabled or
        // deleted plugin stops contributing strings (only those: the others were just replaced).
        foreach (glob(sprintf(self::LANG_CACHE_FMT, '*')) ?: [] as $old) {
            if (!isset($written[$old])) {
                @unlink($old);
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
    /**
     * Whether a plugin declares a content_render filter. Turning such a plugin on or off, or
     * changing its version, changes nothing in stored posts until they are re-rendered.
     */
    public static function declaresContentRender(string $dirName): bool
    {
        $m = json_decode((string)@file_get_contents(self::PLUGINS_DIR . '/' . basename($dirName) . '/manifest.json'), true);
        return is_array($m) && isset($m['hooks']['content_render']);
    }

    /** @return string[] the plugins activated in active.php (whether or not a check stopped them) */
    public static function activeIds(): array
    {
        return self::loadActive();
    }

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

    /**
     * @throws RuntimeException when the plugin's install SQL fails; the plugin is then not activated
     */
    public static function activate(string $dirName): void
    {
        self::runLifecycleSql($dirName, 'install');
        // Activating is approving the files as they are now
        require_once CORE_PATH . '/Integrity.php';
        Integrity::recordPlugin($dirName);
        $active = self::loadActive();
        if (!in_array($dirName, $active, true)) {
            $active[] = $dirName;
            self::saveActive($active);
        }
        self::rebuildCache();
    }

    /**
     * Run the plugin's install SQL again (PluginInstaller, after putting a new version of an
     * active plugin in place). Install SQL is required to be idempotent.
     *
     * @throws RuntimeException as activate()
     */
    public static function installSql(string $dirName): void
    {
        self::runLifecycleSql($dirName, 'install');
    }

    public static function deactivate(string $dirName): void
    {
        $active = array_values(array_filter(self::loadActive(), fn($n) => $n !== $dirName));
        self::saveActive($active);
        self::rebuildCache();
    }

    /**
     * Deactivate, run the uninstall SQL and remove the files. A failing uninstall SQL does not stop
     * the deletion (the files are going anyway); its message is returned so the screen can say
     * that tables the plugin created may remain.
     *
     * @return string|null the uninstall SQL's error, or null
     */
    public static function delete(string $dirName): ?string
    {
        if ($dirName === '' || str_contains($dirName, '..') || str_contains($dirName, '/')) {
            return null;
        }
        self::deactivate($dirName);
        $sqlError = null;
        try {
            self::runLifecycleSql($dirName, 'uninstall');
        } catch (RuntimeException $e) {
            $sqlError = $e->getMessage();
        }
        $pluginDir = ROOT_PATH . '/plugins/' . $dirName;
        if (is_link($pluginDir)) {
            unlink($pluginDir);
        } elseif (is_dir($pluginDir)) {
            self::removeDir($pluginDir);
        }
        // The kept previous version and the install record go too: nothing of the plugin stays
        if (class_exists('PluginInstaller') || is_file(CORE_PATH . '/PluginInstaller.php')) {
            require_once CORE_PATH . '/PluginInstaller.php';
            PluginInstaller::forget($dirName);
        }
        require_once CORE_PATH . '/Integrity.php';
        Integrity::forgetPlugin($dirName);
        self::rebuildCache();
        return $sqlError;
    }

    /**
     * Run plugins/{id}/database/{install|uninstall}.{driver}.sql, if the plugin has one.
     * The same runner as migrations: on PostgreSQL a failure rolls the whole file back; on MySQL
     * the statements before the failing one stay applied. "Already exists" errors are skipped, so
     * re-activating a plugin works even where MySQL has no IF NOT EXISTS.
     *
     * @throws RuntimeException naming the file, the statement and whether the database was left unchanged
     */
    private static function runLifecycleSql(string $dirName, string $type): void
    {
        $driver  = class_exists('Env') ? Env::get('DB_DRIVER', 'mysql') : ($_ENV['DB_DRIVER'] ?? 'mysql');
        $file    = "{$type}.{$driver}.sql";
        $sqlFile = ROOT_PATH . '/plugins/' . $dirName . '/database/' . $file;
        if (!file_exists($sqlFile)) {
            return;
        }
        require_once CORE_PATH . '/Migrator.php';
        try {
            Migrator::runScript((string)file_get_contents($sqlFile), "plugin {$dirName} {$file}");
        } catch (MigrationFailed $e) {
            if (class_exists('Audit')) {
                Audit::log('plugin', 'sql_failed', ['plugin' => $dirName, 'file' => $file, 'statement' => $e->statementNo]);
            }
            $detail = $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : $e->getMessage();
            throw new RuntimeException(HbpLocale::t('err_plugin_sql_failed', ':file failed at statement :no of :count: :message',
                    ['file' => $file, 'no' => $e->statementNo, 'count' => $e->statementCount,
                     'message' => class_exists('PluginErrors') ? PluginErrors::clean($detail) : $detail])
                . ' ' . ($e->rolledBack
                    ? HbpLocale::t('err_plugin_sql_rolled_back', 'Nothing was changed in the database.')
                    : HbpLocale::t('err_plugin_sql_partial', 'The statements before it stay applied (MySQL commits each one).')),
                0, $e);
        }
    }

    /** Remove a directory tree. A symbolic link inside is removed as a link, never followed. */
    private static function removeDir(string $dir): void
    {
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ----------------------------------------------------------------
    // Internal helpers
    // ----------------------------------------------------------------

    /**
     * Write a cache file that is read back with require.
     *
     * Written to a temporary file and renamed into place, so a request that requires it at the
     * same moment sees the old file or the new one, never a half-written one (which would be a
     * parse error, and a 500 on every page that loads it). If the write fails (disk full,
     * permissions) the old file is left as it was and the error is raised: a registry where some
     * caches are new and some are old must not pass for a successful rebuild.
     */
    private static function writeCache(string $path, string $label, string $export): void
    {
        $tmp = $path . '.' . getmypid() . '.tmp';
        $php = "<?php\n// {$label} - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n";
        if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not write {$path}");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    private static function loadActive(): array
    {
        if (!file_exists(self::ACTIVE_FILE)) return [];
        return require self::ACTIVE_FILE;
    }

    private static function saveActive(array $active): void
    {
        // rebuildCache() requires this file right after; writeCache() invalidates it in opcache,
        // without which it may get the cached old list
        self::writeCache(self::ACTIVE_FILE, 'HBP Active Plugins', var_export($active, true));
    }
}
