<?php
/**
 * HayabusaPress - Detection of files changed outside the admin panel (plugins and the core).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Two checks, run at admin login, on rebuilds and with `system:verify`, never per request:
 *
 *   Plugins  Each active plugin's files against the SHA-256 recorded when it was last applied,
 *            activated or acknowledged (storage/plugins/integrity.php). This catches an update
 *            copied over plugins/{id}/ by FTP, which bypasses the review. By default a change is
 *            only reported; PLUGIN_MODIFIED_ACTION=disable keeps the plugin out of the registry
 *            until it is acknowledged. A changed file that does not parse is always kept out (it
 *            would answer 500 otherwise).
 *   Core     Every file of the distribution against core/release.files.php, written by the
 *            release script: changed or missing files, and .php files the release does not have
 *            under core/, public/ and themes/default/ (where a web shell would be put).
 *            Bundled plugins are checked here, against the release, not against a record.
 *
 * This lets the administrator notice; it does not prevent anything. Whoever can write these files
 * can also rewrite the records, the list and this class. Findings are shown on every admin page
 * and mailed once to the Super User (INTEGRITY_MAIL=false turns the mail off).
 *
 * The check must never get in the way: it runs after the login response is sent where PHP-FPM
 * allows it, every error is caught and shown as "could not check", and it stops at BUDGET seconds.
 */
class Integrity
{
    public const RELEASE_FILE   = CORE_PATH . '/release.files.php';
    private const DIR           = STORAGE_PATH . '/integrity';
    private const STATE_FILE    = STORAGE_PATH . '/integrity/state.php';
    /** Core files whose difference from the release the administrator acknowledged: rel => sha256 | 'missing' */
    private const CORE_ACKS     = STORAGE_PATH . '/integrity/core.php';
    /** Written when the plugin records are first made; its presence tells a deleted record from a first run. */
    private const MARKER        = STORAGE_PATH . '/integrity/initialized';
    private const PLUGIN_FILE   = STORAGE_PATH . '/plugins/integrity.php';
    private const BUDGET        = 2.0;
    /** Where a .php file that is not part of the release is reported. */
    private const SCAN_ROOTS    = ['core', 'public', 'themes/default'];
    private const MAIL_RETRY    = 86400;

    /**
     * Run the check after the response has been sent (PHP-FPM), so a login is not slowed down.
     * Without fastcgi_finish_request() it still runs at the end of the request, before the
     * response is complete.
     */
    public static function afterResponse(): void
    {
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            self::run(true);
        });
    }

    /**
     * Check plugins and the core, write the result, mail new findings. Never throws.
     *
     * @return array the state (see state())
     */
    public static function run(bool $notify = true): array
    {
        $start = microtime(true);
        $prev  = self::state();
        $state = [
            'checked_at'     => date('Y-m-d H:i:s'),
            'plugins'        => [],
            'core'           => ['list' => 'ok', 'version' => '', 'changed' => [], 'missing' => [], 'unknown' => []],
            'record_missing' => false,
            'incomplete'     => false,
            'error'          => null,
            'initialized_at' => $prev['initialized_at'] ?? null,
            'notified'       => $prev['notified'] ?? '',
            'notified_at'    => $prev['notified_at'] ?? '',
            'mail_failed_at' => $prev['mail_failed_at'] ?? '',
        ];

        try {
            require_once CORE_PATH . '/PluginManager.php';
            require_once CORE_PATH . '/PluginInstaller.php';
            $release = self::release();

            // ---- plugins
            $records = self::pluginRecords();
            if ($records === null) {
                if (is_file(self::MARKER)) {
                    // The records were deleted (or are unreadable) after they were first made: do
                    // not quietly make new ones, or deleting them would hide any change
                    $state['record_missing'] = true;
                    $records = [];
                } else {
                    $records = [];
                    foreach (PluginManager::activeIds() as $id) {
                        if (!self::isBundled($id, $release)) {
                            $records[$id] = self::recordOf($id);
                        }
                    }
                    self::writePhp(self::PLUGIN_FILE, $records);
                    @mkdir(self::DIR, 0755, true);
                    @file_put_contents(self::MARKER, date('Y-m-d H:i:s'));
                    $state['initialized_at'] = date('Y-m-d H:i:s');
                    Audit::log('plugin', 'integrity_initialized', ['plugins' => count($records)]);
                }
            }
            $action = strtolower(Env::get('PLUGIN_MODIFIED_ACTION', 'warn')) === 'disable' ? 'disable' : 'warn';
            foreach (PluginManager::activeIds() as $id) {
                if (self::isBundled($id, $release) || !is_dir(ROOT_PATH . '/plugins/' . $id)) {
                    continue;
                }
                $now  = PluginInstaller::fingerprint(ROOT_PATH . '/plugins/' . $id);
                $then = $records[$id]['files'] ?? null;
                if ($then === $now) {
                    continue;
                }
                $diff = self::diff($then ?? [], $now);
                $touched = array_merge($diff['added'], $diff['changed']);
                $syntax  = array_values(array_filter(PluginInstaller::syntaxErrors(ROOT_PATH . '/plugins/' . $id),
                    static fn($e) => in_array($e['file'], $touched, true)));
                require_once CORE_PATH . '/PluginScanner.php';
                $manifest = json_decode((string)@file_get_contents(ROOT_PATH . '/plugins/' . $id . '/manifest.json'), true);
                $scan     = array_values(array_filter(
                    PluginScanner::scan(ROOT_PATH . '/plugins/' . $id, is_array($manifest) ? $manifest : null),
                    static fn($f) => in_array($f['file'], $touched, true)
                ));
                $state['plugins'][$id] = [
                    'diff'      => $diff,
                    'scan'      => $scan,
                    'no_record' => $then === null,
                    'syntax'    => $syntax,
                    'disabled'  => $syntax !== [] ? 'parse_error' : ($action === 'disable' ? 'modified' : null),
                    'since'     => $prev['plugins'][$id]['since'] ?? date('Y-m-d H:i:s'),
                ];
                if (microtime(true) - $start > self::BUDGET) {
                    $state['incomplete'] = true;
                    break;
                }
            }

            // ---- core
            if (!$state['incomplete']) {
                self::checkCore($release, $state, $start);
            }
        } catch (Throwable $e) {
            $state['error'] = class_exists('PluginErrors') ? PluginErrors::clean($e->getMessage()) : $e->getMessage();
            // Keep what the last complete check knew, rather than reporting "nothing changed"
            $state['plugins'] = $prev['plugins'] ?? [];
            $state['core']    = $prev['core'] ?? $state['core'];
        }

        try {
            if (self::disabledIn($state) !== self::disabledIn($prev)) {
                self::writeState($state);   // rebuildCache() reads the disabled list from it
                PluginManager::rebuildCache();
                if (is_file(CORE_PATH . '/RouterCompiler.php')) {
                    require_once CORE_PATH . '/RouterCompiler.php';
                    RouterCompiler::compile();
                }
                foreach (array_diff(self::disabledIn($state), self::disabledIn($prev)) as $id) {
                    Audit::log('plugin', 'integrity_disabled', ['plugin' => $id, 'reason' => $state['plugins'][$id]['disabled']]);
                }
            }
            if ($notify) {
                self::notify($state);
            }
            self::writeState($state);
        } catch (Throwable $e) {
            error_log('[HBP WARNING] integrity check could not finish: ' . $e->getMessage());
        }
        return $state;
    }

    /**
     * The last result: checked_at, plugins (id => diff, syntax, disabled, since), core (list,
     * changed, missing, unknown), record_missing, incomplete, error, initialized_at.
     */
    public static function state(): array
    {
        if (!is_file(self::STATE_FILE)) {
            return [];
        }
        try {
            $s = require self::STATE_FILE;
        } catch (Throwable) {
            return [];
        }
        return is_array($s) ? $s : [];
    }

    /** Plugins kept out of the registry by the last check (PluginManager::rebuildCache() skips them). */
    public static function disabledPlugins(): array
    {
        return self::disabledIn(self::state());
    }

    /** Short lines for the banner on every admin page; empty when there is nothing to say. */
    public static function notices(): array
    {
        $s = self::state();
        if ($s === []) {
            return [];
        }
        $out = [];
        $plugins = $s['plugins'] ?? [];
        if ($plugins !== []) {
            $stopped = count(self::disabledIn($s));
            $out[] = HbpLocale::t('integrity_plugins_modified', 'Files of :count plugin(s) changed outside the admin panel (:stopped stopped).',
                ['count' => count($plugins), 'stopped' => $stopped]);
        }
        $core = $s['core'] ?? [];
        if (($core['list'] ?? 'ok') !== 'ok') {
            $out[] = HbpLocale::t('integrity_list_missing', 'The list of HayabusaPress files (core/release.files.php) is missing or unreadable, so the core files were not checked. The upload of HayabusaPress may be incomplete.');
        } elseif (($n = count($core['changed'] ?? []) + count($core['missing'] ?? []) + count($core['unknown'] ?? [])) > 0) {
            $out[] = HbpLocale::t('integrity_core_modified', ':count HayabusaPress file(s) differ from the release (changed, missing, or PHP files the release does not have).',
                ['count' => $n]);
        }
        if (!empty($s['record_missing'])) {
            $out[] = HbpLocale::t('integrity_record_missing', 'The record of plugin files (storage/plugins/integrity.php) is missing or unreadable. It was not recreated automatically: check the plugins and acknowledge them.');
        }
        if (!empty($s['error'])) {
            $out[] = HbpLocale::t('integrity_error', 'The check for changed files could not finish: :message', ['message' => $s['error']]);
        } elseif (!empty($s['incomplete'])) {
            $out[] = HbpLocale::t('integrity_incomplete', 'The check for changed files took too long and stopped early; the rest is checked next time.');
        }
        return $out;
    }

    /**
     * Record a plugin's files as approved (applied, activated, or acknowledged), and drop what the
     * last check said about it, so the rebuild that follows includes it again if it was stopped.
     */
    public static function recordPlugin(string $id): void
    {
        $records = self::pluginRecords() ?? [];
        $records[$id] = self::recordOf($id);
        self::writePhp(self::PLUGIN_FILE, $records);
        $state = self::state();
        if (isset($state['plugins'][$id])) {
            unset($state['plugins'][$id]);
            self::writeState($state);
        }
        if (!is_file(self::MARKER)) {
            @mkdir(self::DIR, 0755, true);
            @file_put_contents(self::MARKER, date('Y-m-d H:i:s'));
        }
    }

    public static function forgetPlugin(string $id): void
    {
        $records = self::pluginRecords();
        if ($records !== null && isset($records[$id])) {
            unset($records[$id]);
            self::writePhp(self::PLUGIN_FILE, $records);
        }
    }

    /**
     * The administrator checked the change: record the files as they are now, and check again.
     * Refused while a file does not parse: accepting would put code that answers 500 back in.
     *
     * @throws RuntimeException when the plugin has a PHP syntax error
     */
    public static function ackPlugin(string $id): void
    {
        require_once CORE_PATH . '/PluginInstaller.php';
        $errors = PluginInstaller::syntaxErrors(ROOT_PATH . '/plugins/' . $id);
        if ($errors !== []) {
            throw new RuntimeException(HbpLocale::t('err_integrity_ack_syntax', 'Fix the PHP syntax error first (:file line :line); a plugin that does not parse cannot be accepted.',
                ['file' => $errors[0]['file'], 'line' => $errors[0]['line']]));
        }
        $wasStopped = in_array($id, self::disabledPlugins(), true);
        self::recordPlugin($id);
        Audit::log('plugin', 'integrity_acknowledged', ['plugin' => $id]);
        if ($wasStopped) {
            // recordPlugin() already dropped it from the stopped list, so run() would see no change
            require_once CORE_PATH . '/PluginManager.php';
            PluginManager::rebuildCache();
            if (is_file(CORE_PATH . '/RouterCompiler.php')) {
                require_once CORE_PATH . '/RouterCompiler.php';
                RouterCompiler::compile();
            }
        }
        self::run(false);
    }

    /** Acknowledge every current difference of the core files (each stays acknowledged while it does not change again). */
    public static function ackCore(): void
    {
        $acks = self::coreAcks();
        $core = self::state()['core'] ?? [];
        foreach (array_merge($core['changed'] ?? [], $core['unknown'] ?? []) as $rel) {
            if (is_file(ROOT_PATH . '/' . $rel)) {
                $acks[$rel] = hash_file('sha256', ROOT_PATH . '/' . $rel);
            }
        }
        foreach ($core['missing'] ?? [] as $rel) {
            $acks[$rel] = 'missing';
        }
        self::writePhp(self::CORE_ACKS, $acks);
        Audit::log('admin', 'integrity_core_acknowledged', ['files' => count($acks)]);
        self::run(false);
    }

    // ----------------------------------------------------------------

    private static function checkCore(?array $release, array &$state, float $start): void
    {
        if ($release === null) {
            $state['core']['list'] = is_file(self::RELEASE_FILE) ? 'invalid' : 'missing';
            return;
        }
        $state['core']['version'] = (string)($release['version'] ?? '');
        $acks  = self::coreAcks();
        $files = $release['files'];
        foreach ($files as $rel => $hash) {
            // A bundled plugin that was deleted is not missing
            if (preg_match('#^plugins/([^/]+)/#', (string)$rel, $m) && !is_dir(ROOT_PATH . '/plugins/' . $m[1])) {
                continue;
            }
            $path = ROOT_PATH . '/' . $rel;
            if (!is_file($path)) {
                if (($acks[$rel] ?? null) !== 'missing') {
                    $state['core']['missing'][] = (string)$rel;
                }
                continue;
            }
            $now = hash_file('sha256', $path);
            if ($now !== $hash && ($acks[$rel] ?? null) !== $now) {
                $state['core']['changed'][] = (string)$rel;
            }
            if (microtime(true) - $start > self::BUDGET) {
                $state['incomplete'] = true;
                return;
            }
        }
        foreach (self::SCAN_ROOTS as $root) {
            $dir = ROOT_PATH . '/' . $root;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $rel = $root . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
                if ($rel === 'core/release.files.php' || isset($files[$rel])) {
                    continue;
                }
                if (($acks[$rel] ?? null) !== hash_file('sha256', $file->getPathname())) {
                    $state['core']['unknown'][] = $rel;
                }
            }
        }
    }

    /**
     * Mail the Super User about findings not mailed yet. A failed mail is not marked as sent and
     * is tried again on a later check, at most once a day for the same findings.
     */
    private static function notify(array &$state): void
    {
        $findings = [
            'plugins' => array_map(static fn($p) => $p['diff'], $state['plugins']),
            'core'    => [$state['core']['list'], $state['core']['changed'], $state['core']['missing'], $state['core']['unknown']],
            'record'  => $state['record_missing'],
        ];
        $empty = $state['plugins'] === [] && $state['core']['list'] === 'ok' && $state['core']['changed'] === []
            && $state['core']['missing'] === [] && $state['core']['unknown'] === [] && !$state['record_missing'];
        if ($empty) {
            $state['notified'] = '';   // a finding that comes back later is new again
            return;
        }
        $fp = sha1((string)json_encode($findings));
        if ($fp === $state['notified'] || !Env::bool('INTEGRITY_MAIL', true)) {
            return;
        }
        if ($state['mail_failed_at'] !== '' && strtotime($state['mail_failed_at']) > time() - self::MAIL_RETRY) {
            return;
        }
        $to = Env::get('SUPER_USER_EMAIL', '');
        if ($to === '' || !function_exists('mb_send_mail')) {
            return;
        }

        $site  = str_replace(["\r", "\n"], ' ', Env::get('SITE_NAME', 'HayabusaPress'));
        $lines = [HbpLocale::t('integrity_mail_intro', 'HayabusaPress found files that changed outside the admin panel on :site (:url).',
            ['site' => $site, 'url' => Env::get('SITE_URL', '')]), ''];
        foreach ($state['plugins'] as $id => $p) {
            $lines[] = "plugin {$id}" . ($p['disabled'] ? " (stopped: {$p['disabled']})" : '') . ':';
            foreach (['added' => '+', 'removed' => '-', 'changed' => '~'] as $k => $mark) {
                foreach ($p['diff'][$k] as $f) {
                    $lines[] = "  {$mark} {$f}";
                }
            }
        }
        foreach (['changed' => '~', 'missing' => '-', 'unknown' => '?'] as $k => $mark) {
            foreach ($state['core'][$k] as $f) {
                $lines[] = "core {$mark} {$f}";
            }
        }
        $lines[] = '';
        $lines[] = HbpLocale::t('integrity_mail_outro', 'If you made these changes, acknowledge them in the admin panel. If you did not, the server may have been tampered with.');

        $from    = str_replace(["\r", "\n"], '', Env::get('MAIL_FROM', ''));
        $headers = $from !== '' ? 'From: ' . $from : '';
        $ok = @mb_send_mail($to, '[' . $site . '] ' . HbpLocale::t('integrity_mail_subject', 'Changed files detected'), implode("\n", $lines), $headers);
        if ($ok) {
            $state['notified']       = $fp;
            $state['notified_at']    = date('Y-m-d H:i:s');
            $state['mail_failed_at'] = '';
        } else {
            $state['mail_failed_at'] = date('Y-m-d H:i:s');
        }
    }

    /** core/release.files.php as ['version' => …, 'files' => rel => sha256], or null if missing or broken. */
    private static function release(): ?array
    {
        if (!is_file(self::RELEASE_FILE)) {
            return null;
        }
        try {
            $r = require self::RELEASE_FILE;
        } catch (Throwable) {
            return null;
        }
        return is_array($r) && isset($r['files']) && is_array($r['files']) ? $r : null;
    }

    private static function isBundled(string $id, ?array $release): bool
    {
        return $release !== null && isset($release['files']['plugins/' . $id . '/manifest.json']);
    }

    /** @return array|null id => record; null when the file is missing or unreadable */
    private static function pluginRecords(): ?array
    {
        if (!is_file(self::PLUGIN_FILE)) {
            return null;
        }
        try {
            $r = require self::PLUGIN_FILE;
        } catch (Throwable) {
            return null;
        }
        return is_array($r) ? $r : null;
    }

    private static function recordOf(string $id): array
    {
        require_once CORE_PATH . '/PluginInstaller.php';
        return [
            'files'    => PluginInstaller::fingerprint(ROOT_PATH . '/plugins/' . $id),
            'acked_at' => date('Y-m-d H:i:s'),
            'acked_by' => PHP_SAPI === 'cli' ? 'cli' : (class_exists('Auth') && Auth::check() ? (string)Auth::id() : '-'),
        ];
    }

    private static function coreAcks(): array
    {
        if (!is_file(self::CORE_ACKS)) {
            return [];
        }
        try {
            $a = require self::CORE_ACKS;
        } catch (Throwable) {
            return [];
        }
        return is_array($a) ? $a : [];
    }

    /** @return array{added:string[],removed:string[],changed:string[]} */
    private static function diff(array $old, array $new): array
    {
        $changed = [];
        foreach (array_intersect_key($new, $old) as $rel => $hash) {
            if ($old[$rel] !== $hash) {
                $changed[] = (string)$rel;
            }
        }
        return [
            'added'   => array_map('strval', array_keys(array_diff_key($new, $old))),
            'removed' => array_map('strval', array_keys(array_diff_key($old, $new))),
            'changed' => $changed,
        ];
    }

    private static function disabledIn(array $state): array
    {
        $out = [];
        foreach ($state['plugins'] ?? [] as $id => $p) {
            if (!empty($p['disabled'])) {
                $out[] = (string)$id;
            }
        }
        sort($out);
        return $out;
    }

    private static function writeState(array $state): void
    {
        self::writePhp(self::STATE_FILE, $state);
    }

    /** Write a file read back with require: temporary file, rename, opcache_invalidate. */
    private static function writePhp(string $path, array $data): void
    {
        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
            throw new RuntimeException("Could not create " . dirname($path));
        }
        $tmp = $path . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, "<?php\nreturn " . var_export($data, true) . ";\n", LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Could not write {$path}");
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
