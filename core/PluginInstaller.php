<?php
/**
 * HayabusaPress - Plugin installation and updates: staging, review, apply, roll back, repair.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

require_once __DIR__ . '/FileTree.php';

/**
 * A new version of a plugin never overwrites the running one directly. It goes through three steps:
 *
 *   1. stage   A ZIP upload, or a folder put in plugins/_incoming/{id}/ over FTP or SSH, is checked
 *              (entries, extensions, size, signature, syntax) and copied into
 *              storage/upload/plugin_stage/{id}.{token}/. What would change is written next to it
 *              as {id}.{token}.json: the review.
 *   2. review  The admin panel or the CLI shows the review. Changes of author or signing key and a
 *              lower version have to be accepted explicitly.
 *   3. apply   The running version is moved to storage/plugins/backup/, the staged one into
 *              plugins/{id}/, and the registry is rebuilt. If the install SQL or the rebuild fails,
 *              the previous version is put back.
 *
 * Every apply, roll back and repair holds an exclusive lock and keeps a journal
 * (storage/plugins/.journal/{id}.json) of how far it got, so an operation cut short by a timeout or
 * a killed process can be finished or undone later with repair().
 *
 * ZipArchive::extractTo() is never used (zip-slip): each entry is validated and written by hand.
 * Symbolic links are never followed or copied.
 */
class PluginInstaller
{
    private const MAX_FILES      = 500;
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
    private const STAGE_DIR      = STORAGE_PATH . '/upload/plugin_stage';
    private const PLUGINS_DIR    = ROOT_PATH . '/plugins';
    /** Where a new version is put over FTP or SSH. "_" keeps it out of the plugin scan. */
    public const INCOMING_DIR    = ROOT_PATH . '/plugins/_incoming';
    private const BACKUP_DIR     = STORAGE_PATH . '/plugins/backup';
    private const JOURNAL_DIR    = STORAGE_PATH . '/plugins/.journal';
    private const LOCK_FILE      = STORAGE_PATH . '/plugins/.lock';
    /** id => [version, signer]: what was applied, so the next update can be compared with it. */
    private const INSTALLED_FILE = STORAGE_PATH . '/plugins/installed.php';
    /** A staged version not applied within this many seconds is discarded. */
    private const STAGE_TTL      = 3600;
    private const ID_PATTERN     = '/^[a-z0-9][a-z0-9-]*$/';
    /** What a review can ask the administrator to accept before applying. */
    public const CONSENTS        = ['author', 'signer', 'version'];

    /** Allowed extensions */
    private const ALLOWED_EXTS = ['php', 'json', 'md', 'txt', 'html', 'css', 'js', 'svg', 'png', 'jpg', 'webp'];

    /** Extension-less files accepted by exact name (licence and notice files of the package). */
    private const ALLOWED_BARE_NAMES = ['LICENSE', 'LICENCE', 'COPYING', 'NOTICE', 'README', 'CHANGELOG', 'AUTHORS'];

    /**
     * Whether a file (path relative to the plugin folder) may be in a package: the allowed
     * extensions, the licence files by name, and two files only at their exact place: the
     * signature (manifest.json.sig) and the database scripts (database/{install,uninstall}.{mysql,pgsql}.sql).
     */
    private static function allowedFile(string $rel): bool
    {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        return in_array($ext, self::ALLOWED_EXTS, true)
            || ($ext === '' && in_array(strtoupper(basename($rel)), self::ALLOWED_BARE_NAMES, true))
            || $rel === 'manifest.json.sig'
            || preg_match('#^database/(install|uninstall)\.(mysql|pgsql)\.sql$#', $rel) === 1;
    }

    // ----------------------------------------------------------------
    // 1. Stage
    // ----------------------------------------------------------------

    /**
     * Stage an uploaded ZIP.
     *
     * @return string the review token
     * @throws RuntimeException when the package is refused (nothing is left behind)
     */
    public static function stageZip(string $zipPath): string
    {
        require_once CORE_PATH . '/PluginScanner.php';
        self::cleanupExpired();
        $token = bin2hex(random_bytes(8));
        [$folder, $stageDir] = self::extractZip($zipPath, $token);
        return self::finishStage($folder, $stageDir, $token, 'zip');
    }

    /**
     * Stage the folder plugins/_incoming/{id}/. The folder itself is left as it is until the
     * staged copy is applied, so what is applied is exactly what was reviewed.
     *
     * @return string the review token
     */
    public static function stageIncoming(string $id): string
    {
        require_once CORE_PATH . '/PluginScanner.php';
        self::cleanupExpired();
        $src = self::INCOMING_DIR . '/' . $id;
        if (!preg_match(self::ID_PATTERN, $id) || is_link($src) || !is_dir($src)) {
            throw new RuntimeException(HbpLocale::t('err_incoming_missing', 'There is no such folder in plugins/_incoming/: ') . $id);
        }
        $token    = bin2hex(random_bytes(8));
        $stageDir = self::STAGE_DIR . '/' . $id . '.' . $token;
        if (!is_dir(self::STAGE_DIR) && !@mkdir(self::STAGE_DIR, 0755, true) && !is_dir(self::STAGE_DIR)) {
            throw new RuntimeException(HbpLocale::t('err_stage_dir_failed', 'Could not create the staging directory.'));
        }
        try {
            $count = 0;
            $bytes = 0;
            self::copyChecked($src, $stageDir, '', $count, $bytes);
        } catch (Throwable $e) {
            self::cleanStage($stageDir);
            throw $e;
        }
        return self::finishStage($id, $stageDir, $token, 'incoming');
    }

    /**
     * The folders waiting in plugins/_incoming/, with what their manifest says.
     *
     * @return array<string,array{name:string,version:string}>
     */
    public static function incoming(): array
    {
        $out = [];
        foreach (glob(self::INCOMING_DIR . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (is_link($dir) || !preg_match(self::ID_PATTERN, $id)) {
                continue;
            }
            $m = json_decode((string)@file_get_contents($dir . '/manifest.json'), true);
            $out[$id] = [
                'name'    => is_array($m) ? (string)($m['name'] ?? $id) : $id,
                'version' => is_array($m) ? (string)($m['version'] ?? '') : '',
            ];
        }
        return $out;
    }

    /** The review of a staged version, or null when there is none (expired, applied or discarded). */
    public static function review(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            return null;
        }
        $files = glob(self::STAGE_DIR . '/*.' . $token . '.json') ?: [];
        if (count($files) !== 1 || filemtime($files[0]) < time() - self::STAGE_TTL) {
            return null;
        }
        $review = json_decode((string)file_get_contents($files[0]), true);
        return is_array($review) ? $review : null;
    }

    public static function discard(string $token): void
    {
        $review = self::review($token);
        if ($review === null) {
            return;
        }
        self::cleanStage(self::STAGE_DIR . '/' . $review['id'] . '.' . $token);
        @unlink(self::STAGE_DIR . '/' . $review['id'] . '.' . $token . '.json');
        Audit::log('plugin', 'discarded', ['plugin' => $review['id'], 'version' => $review['version']]);
    }

    /** Remove staged versions (and kept failed ones) older than STAGE_TTL. No cron needed: called on use. */
    public static function cleanupExpired(): void
    {
        foreach (glob(self::STAGE_DIR . '/*') ?: [] as $path) {
            if (@filemtime($path) < time() - self::STAGE_TTL) {
                is_dir($path) && !is_link($path) ? self::cleanStage($path) : @unlink($path);
            }
        }
    }

    /**
     * Check a staged copy and write its review. Refuses (and removes the copy) when the folder
     * name and the ID differ, the ID is reserved, or a required signature is missing.
     */
    private static function finishStage(string $folder, string $stageDir, string $token, string $source): string
    {
        try {
            $manifest = json_decode((string)@file_get_contents($stageDir . '/manifest.json'), true);
            if (!is_file($stageDir . '/manifest.json')) {
                throw new RuntimeException(HbpLocale::t('err_manifest_missing', 'manifest.json does not exist.'));
            }
            if (!is_array($manifest) || empty($manifest['id']) || !is_string($manifest['id'])) {
                throw new RuntimeException(HbpLocale::t('err_manifest_invalid', 'manifest.json is invalid (the id field is required).'));
            }
            $id = $manifest['id'];
            if ($id !== $folder) {
                throw new RuntimeException(HbpLocale::t('err_plugin_id_folder', 'The folder name (:folder) and the id in manifest.json (:id) must be the same.',
                    ['folder' => $folder, 'id' => $id]));
            }
            if (str_starts_with($id, 'hbp-')) {
                throw new RuntimeException(HbpLocale::t('err_plugin_id_reserved', 'IDs starting with "hbp-" are reserved for the official plugins bundled with HayabusaPress, which are updated with HayabusaPress itself.'));
            }
            $signature = self::signatureStatus($stageDir, $manifest);
        } catch (Throwable $e) {
            self::cleanStage($stageDir);
            throw $e;
        }

        $current      = self::PLUGINS_DIR . '/' . $id;
        $hasCurrent   = is_dir($current) && !is_link($current);
        $curManifest  = $hasCurrent ? json_decode((string)@file_get_contents($current . '/manifest.json'), true) : null;
        $curManifest  = is_array($curManifest) ? $curManifest : null;
        $installed    = self::installed()[$id] ?? null;
        $stageFiles   = self::fingerprint($stageDir);
        $baseFiles    = $hasCurrent ? self::fingerprint($current) : null;

        // #3: what the administrator has to accept explicitly
        $requires = [];
        if ($curManifest !== null && (string)($curManifest['author'] ?? '') !== (string)($manifest['author'] ?? '')) {
            $requires[] = 'author';
        }
        if (!empty($installed['signer']) && ($signature['state'] !== 'signed' || $signature['key'] !== $installed['signer'])) {
            $requires[] = 'signer';
        }
        if ($curManifest !== null && version_compare((string)($manifest['version'] ?? '0'), (string)($curManifest['version'] ?? '0'), '<=')) {
            $requires[] = 'version';
        }

        $review = [
            'token'         => $token,
            'id'            => $id,
            'source'        => $source,
            'created_at'    => date('Y-m-d H:i:s'),
            'name'          => (string)($manifest['name'] ?? $id),
            'version'       => (string)($manifest['version'] ?? ''),
            'author'        => (string)($manifest['author'] ?? ''),
            'license'       => (string)($manifest['license'] ?? ''),
            'current'       => $curManifest === null ? null : [
                'version' => (string)($curManifest['version'] ?? ''),
                'author'  => (string)($curManifest['author'] ?? ''),
                'signer'  => (string)($installed['signer'] ?? ''),
            ],
            'active'        => PluginManager::isActive($id),
            'signature'     => $signature,
            'syntax_errors' => self::syntaxErrors($stageDir),
            // What the code does (a guide, never a block): new findings since the running version marked
            'scan'          => PluginScanner::markNew(PluginScanner::scan($stageDir, $manifest),
                                   $hasCurrent ? PluginScanner::scan($current, $curManifest) : []),
            'requires'      => $requires,
            'manifest_diff' => $mdiff = self::diffLists(self::declarations($curManifest, $current), self::declarations($manifest, $stageDir)),
            // A content_render filter added, removed or replaced: stored posts follow only when re-rendered
            'content_render' => (bool)preg_grep('/^hook content_render /', array_merge($mdiff['added'], $mdiff['removed'])),
            'file_diff'     => self::diffFiles($baseFiles ?? [], $stageFiles),
            'stage_files'   => $stageFiles,
            'base_files'    => $baseFiles,
        ];
        $json = json_encode($review, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents(self::STAGE_DIR . '/' . $id . '.' . $token . '.json', $json, LOCK_EX) === false) {
            self::cleanStage($stageDir);
            throw new RuntimeException(HbpLocale::t('err_stage_dir_failed', 'Could not create the staging directory.'));
        }
        Audit::log('plugin', 'staged', ['plugin' => $id, 'version' => $review['version'], 'source' => $source]);
        return $token;
    }

    // ----------------------------------------------------------------
    // 2-3. Apply, roll back, repair
    // ----------------------------------------------------------------

    /**
     * Apply a staged version. Checks first that neither the staged copy nor the running version
     * changed since the review was written, that every required consent is given, and that the
     * code parses.
     *
     * @param string[] $consents the CONSENTS the administrator accepted
     * @return string the plugin ID
     * @throws RuntimeException when nothing was applied, or when the previous version was put back
     */
    public static function apply(string $token, array $consents): string
    {
        $review = self::review($token);
        if ($review === null) {
            throw new RuntimeException(HbpLocale::t('err_review_expired', 'This review has expired or was already used. Upload or stage it again.'));
        }
        $id    = $review['id'];
        $stage = self::STAGE_DIR . '/' . $id . '.' . $token;

        self::withLock(static function () use ($review, $id, $stage, $consents, $token): void {
            if (!is_dir($stage) || self::fingerprint($stage) !== $review['stage_files']) {
                throw new RuntimeException(HbpLocale::t('err_review_changed', 'The files changed after the review was made. Stage it again and review it.'));
            }
            $current = self::PLUGINS_DIR . '/' . $id;
            $base    = is_dir($current) && !is_link($current) ? self::fingerprint($current) : null;
            if ($base !== $review['base_files']) {
                throw new RuntimeException(HbpLocale::t('err_review_changed', 'The files changed after the review was made. Stage it again and review it.'));
            }
            if ($review['syntax_errors'] !== []) {
                throw new RuntimeException(HbpLocale::t('err_review_syntax', 'It has PHP syntax errors and cannot be applied.'));
            }
            $missing = array_diff($review['requires'], $consents);
            if ($missing !== []) {
                throw new RuntimeException(HbpLocale::t('err_review_consent', 'Confirm the warnings on the review before applying.'));
            }

            self::swapIn($id, $stage, 'apply', ['version' => $review['version'], 'signer' => $review['signature']['key'] ?? '']);

            @unlink(self::STAGE_DIR . '/' . $id . '.' . $token . '.json');
            if ($review['source'] === 'incoming') {
                self::cleanStage(self::INCOMING_DIR . '/' . $id);
            }
            Audit::log('plugin', 'applied', ['plugin' => $id, 'from' => $review['current']['version'] ?? '', 'to' => $review['version']]);
        });
        return $id;
    }

    /** The kept previous version of a plugin: [version, backed_up_at], or null. */
    public static function backup(string $id): ?array
    {
        $dir = self::BACKUP_DIR . '/' . $id;
        if (!preg_match(self::ID_PATTERN, $id) || !is_dir($dir)) {
            return null;
        }
        $m    = json_decode((string)@file_get_contents($dir . '/manifest.json'), true);
        $meta = json_decode((string)@file_get_contents(self::BACKUP_DIR . '/' . $id . '.json'), true);
        return [
            'version'      => is_array($m) ? (string)($m['version'] ?? '') : '',
            'backed_up_at' => is_array($meta) ? (string)($meta['backed_up_at'] ?? '') : '',
        ];
    }

    /** Swap the running version and the kept previous one. The database is not rolled back. */
    public static function rollback(string $id): void
    {
        if (self::backup($id) === null) {
            throw new RuntimeException(HbpLocale::t('err_no_backup', 'There is no previous version of this plugin to go back to.'));
        }
        self::withLock(static function () use ($id): void {
            $meta = json_decode((string)@file_get_contents(self::BACKUP_DIR . '/' . $id . '.json'), true);
            $from = self::installed()[$id]['version'] ?? '';
            self::swapIn($id, self::BACKUP_DIR . '/' . $id, 'rollback', [
                'version' => self::backup($id)['version'] ?? '',
                'signer'  => is_array($meta) ? (string)($meta['signer'] ?? '') : '',
            ]);
            Audit::log('plugin', 'rolled_back', ['plugin' => $id, 'from' => $from, 'to' => self::installed()[$id]['version'] ?? '']);
        });
    }

    /**
     * Operations that were cut short (a journal is left), for the screen to offer repair().
     *
     * @return array<string,array> id => journal
     */
    public static function interrupted(): array
    {
        $out = [];
        foreach (glob(self::JOURNAL_DIR . '/*.json') ?: [] as $file) {
            $j = json_decode((string)file_get_contents($file), true);
            if (is_array($j) && isset($j['id'])) {
                $out[(string)$j['id']] = $j;
            }
        }
        return $out;
    }

    /**
     * Finish or undo an operation that was cut short, from its journal:
     *   begin      nothing was moved yet               -> drop the journal
     *   backed_up  the running version was moved away   -> put it back
     *   placed     the new version is in place          -> move it back where it came from, put the old one back
     *   restored   the old version was put back, but the rebuild after it failed -> only rebuild
     *   committed  everything worked but the clean-up   -> finish the clean-up
     *
     * Once the files are where they belong the journal moves to "restored", so that repairing
     * again after a failed rebuild never moves them a second time.
     *
     * @return string what was done, for the screen or the terminal
     */
    public static function repair(string $id): string
    {
        $journal = self::interrupted()[$id] ?? null;
        if ($journal === null) {
            return HbpLocale::t('repair_nothing', 'There was nothing to repair.');
        }
        $message = '';
        self::withLock(static function () use ($journal, $id, &$message): void {
            $current   = self::PLUGINS_DIR . '/' . $id;
            $backupNew = self::BACKUP_DIR . '/' . $id . '.new';
            switch ($journal['step']) {
                case 'backed_up':
                    if (!is_dir($current) && is_dir($backupNew)) {
                        self::moveDir($backupNew, $current);
                    }
                    self::writeJournal(['step' => 'restored'] + $journal);
                    break;
                case 'placed':
                    if (is_dir($current)) {
                        self::moveDir($current, $journal['source']);
                    }
                    if (!empty($journal['had_current']) && is_dir($backupNew)) {
                        self::moveDir($backupNew, $current);
                    }
                    self::writeJournal(['step' => 'restored'] + $journal);
                    self::writeInstalled($id, $journal['previous'] ?? null);
                    break;
                case 'committed':
                    self::rotateBackup($id, $journal);
                    break;
            }
            if (is_dir($current)) {
                require_once CORE_PATH . '/Integrity.php';
                Integrity::recordPlugin($id);   // the version put back is the one that was approved before
            }
            self::invalidateOpcache($current);
            PluginManager::rebuildCache();
            if (class_exists('RouterCompiler')) {
                RouterCompiler::compile();
            }
            @unlink(self::JOURNAL_DIR . '/' . $id . '.json');
            Audit::log('plugin', 'repaired', ['plugin' => $id, 'op' => $journal['op'], 'step' => $journal['step']]);
            $message = match ($journal['step']) {
                'committed' => HbpLocale::t('repair_finished', 'The interrupted operation had already taken effect; its clean-up was finished.'),
                'restored'  => HbpLocale::t('repair_rebuilt', 'The previous version was already back in place; the caches were rebuilt.'),
                default     => HbpLocale::t('repair_undone', 'The interrupted operation was undone; the version before it is back in place.'),
            };
        });
        return $message;
    }

    /**
     * Put $source (a staged copy, or the kept backup) in place of plugins/{id}/, keeping the
     * running version as the backup. On failure after the files are moved, the previous version
     * is put back; if even that fails, the journal is left for repair().
     *
     * @param array{version:string,signer:string} $record what to remember about the version put in place
     */
    private static function swapIn(string $id, string $source, string $op, array $record): void
    {
        if (is_file(self::JOURNAL_DIR . '/' . $id . '.json')) {
            throw new RuntimeException(HbpLocale::t('err_plugin_interrupted', 'An earlier operation on this plugin was interrupted. Repair it first.'));
        }
        foreach ([self::PLUGINS_DIR, self::BACKUP_DIR, self::JOURNAL_DIR] as $dir) {
            if ((!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) || !is_writable($dir)) {
                throw new RuntimeException(HbpLocale::t('err_plugin_not_writable', 'Cannot write to :dir. Make it writable for PHP (for example over FTP) and try again.',
                    ['dir' => str_replace(ROOT_PATH . '/', '', $dir) . '/']));
            }
        }
        @ignore_user_abort(true);
        @set_time_limit(300);

        $current   = self::PLUGINS_DIR . '/' . $id;
        $backupNew = self::BACKUP_DIR . '/' . $id . '.new';
        if (is_dir($backupNew)) {
            self::cleanStage($backupNew);   // left by an operation that finished but crashed before cleaning up
        }
        $journal = [
            'op'          => $op,
            'id'          => $id,
            'step'        => 'begin',
            'source'      => $source,
            'had_current' => is_dir($current),
            'previous'    => self::installed()[$id] ?? null,
            'started_at'  => date('Y-m-d H:i:s'),
        ];
        self::writeJournal($journal);

        if ($journal['had_current']) {
            self::moveDir($current, $backupNew);
            self::writeJournal($journal = ['step' => 'backed_up'] + $journal);
        }
        try {
            self::moveDir($source, $current);
        } catch (Throwable $e) {
            if ($journal['had_current']) {
                self::moveDir($backupNew, $current);
            }
            @unlink(self::JOURNAL_DIR . '/' . $id . '.json');
            throw $e;
        }
        self::writeJournal($journal = ['step' => 'placed'] + $journal);

        try {
            if (PluginManager::isActive($id)) {
                PluginManager::installSql($id);
            }
            self::writeInstalled($id, $record);
            require_once CORE_PATH . '/Integrity.php';
            Integrity::recordPlugin($id);   // what was reviewed and applied is the approved state
            self::invalidateOpcache($current);
            PluginManager::rebuildCache();
            if (class_exists('RouterCompiler')) {
                RouterCompiler::compile();
            }
        } catch (Throwable $e) {
            Audit::log('plugin', 'apply_failed', ['plugin' => $id, 'op' => $op]);
            try {
                self::moveDir($current, $source);
                if ($journal['had_current']) {
                    self::moveDir($backupNew, $current);
                }
                // The files are back: from here on, repair() must only rebuild, never move them again
                self::writeJournal($journal = ['step' => 'restored'] + $journal);
                self::writeInstalled($id, $journal['previous']);
                require_once CORE_PATH . '/Integrity.php';
                $journal['had_current'] ? Integrity::recordPlugin($id) : Integrity::forgetPlugin($id);
                self::invalidateOpcache($current);
                PluginManager::rebuildCache();
                if (class_exists('RouterCompiler')) {
                    RouterCompiler::compile();
                }
                @unlink(self::JOURNAL_DIR . '/' . $id . '.json');
            } catch (Throwable $e2) {
                throw new RuntimeException(HbpLocale::t('err_plugin_restore_failed',
                    'Applying failed (:message) and putting the previous version back failed too (:second). Use "Repair" on the plugins screen, or php core/cli.php plugins:repair :id.',
                    ['message' => $e->getMessage(), 'second' => $e2->getMessage(), 'id' => $id]), 0, $e);
            }
            throw new RuntimeException(HbpLocale::t('err_plugin_apply_restored', 'The new version was not applied, and the previous version is back in place: :message',
                ['message' => $e->getMessage()]), 0, $e);
        }

        self::writeJournal($journal = ['step' => 'committed'] + $journal);
        self::rotateBackup($id, $journal);
        @unlink(self::JOURNAL_DIR . '/' . $id . '.json');
    }

    /** After a successful swap: the version that was running becomes the one kept backup. */
    private static function rotateBackup(string $id, array $journal): void
    {
        $backupNew = self::BACKUP_DIR . '/' . $id . '.new';
        $backup    = self::BACKUP_DIR . '/' . $id;
        if (!is_dir($backupNew)) {
            return;
        }
        if (is_dir($backup)) {
            self::cleanStage($backup);   // the new backup exists already, so dropping the old one loses nothing
        }
        self::moveDir($backupNew, $backup);
        @file_put_contents(self::BACKUP_DIR . '/' . $id . '.json', (string)json_encode([
            'backed_up_at' => date('Y-m-d H:i:s'),
            'signer'       => (string)($journal['previous']['signer'] ?? ''),
        ]), LOCK_EX);
    }

    /** Run $fn holding the plugin-operation lock, or refuse at once when another one is running. */
    private static function withLock(callable $fn): void
    {
        if (!is_dir(dirname(self::LOCK_FILE))) {
            @mkdir(dirname(self::LOCK_FILE), 0755, true);
        }
        $fh = @fopen(self::LOCK_FILE, 'c');
        if ($fh === false) {
            throw new RuntimeException(HbpLocale::t('err_plugin_not_writable', 'Cannot write to :dir. Make it writable for PHP (for example over FTP) and try again.',
                ['dir' => 'storage/plugins/']));
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new RuntimeException(HbpLocale::t('err_plugin_busy', 'Another plugin operation is running. Try again in a moment.'));
        }
        try {
            $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function writeJournal(array $journal): void
    {
        $file = self::JOURNAL_DIR . '/' . $journal['id'] . '.json';
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, (string)json_encode($journal, JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException(HbpLocale::t('err_plugin_not_writable', 'Cannot write to :dir. Make it writable for PHP (for example over FTP) and try again.',
                ['dir' => 'storage/plugins/.journal/']));
        }
    }

    /** @return array<string,array{version:string,signer:string}> */
    private static function installed(): array
    {
        if (!is_file(self::INSTALLED_FILE)) {
            return [];
        }
        $data = require self::INSTALLED_FILE;
        return is_array($data) ? $data : [];
    }

    /** Remember (or, with null, forget) what is installed under $id. */
    private static function writeInstalled(string $id, ?array $record): void
    {
        $all = self::installed();
        if ($record === null) {
            unset($all[$id]);
        } else {
            $all[$id] = ['version' => (string)($record['version'] ?? ''), 'signer' => (string)($record['signer'] ?? '')];
        }
        $tmp = self::INSTALLED_FILE . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, "<?php\nreturn " . var_export($all, true) . ";\n", LOCK_EX) === false || !rename($tmp, self::INSTALLED_FILE)) {
            @unlink($tmp);
            throw new RuntimeException(HbpLocale::t('err_plugin_not_writable', 'Cannot write to :dir. Make it writable for PHP (for example over FTP) and try again.',
                ['dir' => 'storage/plugins/']));
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::INSTALLED_FILE, true);
        }
    }

    /** Forget a deleted plugin: its record and its kept backup. */
    public static function forget(string $id): void
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return;
        }
        if (isset(self::installed()[$id])) {
            self::writeInstalled($id, null);
        }
        self::cleanStage(self::BACKUP_DIR . '/' . $id);
        @unlink(self::BACKUP_DIR . '/' . $id . '.json');
    }

    // ----------------------------------------------------------------
    // Checks and comparisons
    // ----------------------------------------------------------------

    /**
     * PHP syntax errors, found without running anything: PhpToken::tokenize(TOKEN_PARSE) parses
     * the file the way PHP would. (php -l needs exec(), which shared hosting usually disables.)
     *
     * @return array<int,array{file:string,line:int,message:string}>
     */
    public static function syntaxErrors(string $dir): array
    {
        $errors = [];
        foreach (self::fingerprint($dir) as $rel => $hash) {
            if (!str_ends_with($rel, '.php')) {
                continue;
            }
            try {
                PhpToken::tokenize((string)file_get_contents($dir . '/' . $rel), TOKEN_PARSE);
            } catch (ParseError|CompileError $e) {
                $errors[] = ['file' => $rel, 'line' => $e->getLine(), 'message' => $e->getMessage()];
            }
        }
        return $errors;
    }

    /**
     * What a manifest makes the plugin hook into, as comparable strings (for the review).
     *
     * @return string[]
     */
    private static function declarations(?array $m, string $dir): array
    {
        if ($m === null) {
            return [];
        }
        $out = [];
        foreach ((array)($m['hooks'] ?? []) as $event => $h) {
            $out[] = "hook {$event} -> " . ($h['class'] ?? '') . '::' . ($h['method'] ?? '');
        }
        foreach (array_keys((array)($m['routes'] ?? [])) as $path) {
            $out[] = "route {$path}";
        }
        if (!empty($m['page_template'])) {
            $out[] = 'page_template ' . $m['page_template'];
        }
        foreach ((array)($m['snippets'] ?? []) as $slot => $file) {
            $out[] = "snippet {$slot}: " . (is_string($file) ? $file : '');
        }
        foreach (glob($dir . '/database/*.sql') ?: [] as $sql) {
            $out[] = 'database/' . basename($sql);
        }
        sort($out);
        return $out;
    }

    /** @return array{added:string[],removed:string[]} */
    private static function diffLists(array $old, array $new): array
    {
        return ['added' => array_values(array_diff($new, $old)), 'removed' => array_values(array_diff($old, $new))];
    }

    /** @return array{added:string[],removed:string[],changed:string[]} */
    private static function diffFiles(array $old, array $new): array
    {
        $changed = [];
        foreach (array_intersect_key($new, $old) as $rel => $hash) {
            if ($old[$rel] !== $hash) {
                $changed[] = $rel;
            }
        }
        return [
            'added'   => array_keys(array_diff_key($new, $old)),
            'removed' => array_keys(array_diff_key($old, $new)),
            'changed' => $changed,
        ];
    }

    /**
     * The signature of a staged copy. With REQUIRE_SIGNED_PLUGINS=true anything but a valid
     * signature is refused (RuntimeException). Otherwise the state is only reported.
     *
     * @return array{state:string,key:string} state: signed | unsigned | untrusted; key: the first 8 characters of the trusted key
     */
    private static function signatureStatus(string $dir, array $manifest): array
    {
        if (Env::bool('REQUIRE_SIGNED_PLUGINS', false)) {
            return ['state' => 'signed', 'key' => self::verifySodiumSignature($dir, $manifest)];
        }
        if (!is_file($dir . '/manifest.json.sig')) {
            return ['state' => 'unsigned', 'key' => ''];
        }
        try {
            return ['state' => 'signed', 'key' => self::verifySodiumSignature($dir, $manifest)];
        } catch (RuntimeException) {
            return ['state' => 'untrusted', 'key' => ''];
        }
    }

    /**
     * Validate every entry of a ZIP and extract it by hand into a new staging directory.
     * The ZIP must hold one top-level directory, named after the plugin ID.
     *
     * @return array{0:string,1:string} [the top-level directory name, the staging directory]
     * @throws RuntimeException on validation failure (nothing is left behind)
     */
    private static function extractZip(string $zipPath, string $token): array
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException(HbpLocale::t('err_zip_ext_required', 'The ZipArchive extension is required.'));
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(HbpLocale::t('err_zip_open_failed', 'Could not open the ZIP file.'));
        }

        // B: containment - entry validation
        $count      = $zip->numFiles;
        $totalSize  = 0;
        $topDirs    = [];

        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            self::assertSafeEntry($name);
            $totalSize += $stat['size'];
            if ($totalSize > self::MAX_SIZE_BYTES) {
                $zip->close();
                throw new RuntimeException(HbpLocale::t('err_zip_too_large', 'The ZIP extraction size exceeds the limit (:limit) (zip-bomb protection).',
                    ['limit' => self::formatBytes(self::MAX_SIZE_BYTES)]));
            }
            if ($count > self::MAX_FILES) {
                $zip->close();
                throw new RuntimeException(HbpLocale::t('err_zip_too_many_files', 'The number of files in the ZIP exceeds the limit (:max).',
                    ['max' => self::MAX_FILES]));
            }
            // Collect the top directory
            $parts = explode('/', ltrim($name, '/'), 2);
            if ($parts[0] !== '') {
                $topDirs[$parts[0]] = true;
            }
        }

        if (count($topDirs) !== 1) {
            $zip->close();
            throw new RuntimeException(HbpLocale::t('err_zip_single_topdir', 'The ZIP must contain a single top-level directory.'));
        }
        $topDir = (string)array_key_first($topDirs);
        if (!preg_match(self::ID_PATTERN, $topDir)) {
            $zip->close();
            throw new RuntimeException(HbpLocale::t('err_plugin_folder_name', 'The folder name must be the plugin ID (lowercase letters, digits and hyphens): ') . $topDir);
        }
        // A new directory per staging (random token): nothing of an earlier attempt can be mixed in
        $stageDir = self::STAGE_DIR . '/' . $topDir . '.' . $token;
        if (!is_dir(self::STAGE_DIR) && !@mkdir(self::STAGE_DIR, 0755, true) && !is_dir(self::STAGE_DIR)) {
            $zip->close();
            throw new RuntimeException(HbpLocale::t('err_stage_dir_failed', 'Could not create the staging directory.'));
        }
        @mkdir($stageDir, 0755);
        $stageReal = realpath($stageDir);
        if ($stageReal === false) {
            $zip->close();
            throw new RuntimeException(HbpLocale::t('err_stage_dir_failed', 'Could not create the staging directory.'));
        }
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            // Resolve the relative path inside the top directory
            $rel = ltrim(str_replace($topDir . '/', '', $name, $replaced), '/');
            if ($rel === '') {
                continue; // The top directory's own entry
            }
            $destAbs = $stageDir . '/' . $rel;

            if (str_ends_with($name, '/')) {
                // Directory entry: create it, then validate containment with realpath
                if (!@mkdir($destAbs, 0755, true) && !is_dir($destAbs)) {
                    $zip->close();
                    self::cleanStage($stageDir);
                    throw new RuntimeException(HbpLocale::t('err_dir_create', 'Failed to create directory: ') . $name);
                }
                $real = realpath($destAbs);
                if ($real === false || !str_starts_with($real . '/', $stageReal . '/')) {
                    $zip->close();
                    self::cleanStage($stageDir);
                    throw new RuntimeException(HbpLocale::t('err_path_traversal', 'Path traversal detected: ') . $name);
                }
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!self::allowedFile($rel)) {
                $zip->close();
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_ext_not_allowed', 'Disallowed extension: .') . $ext);
            }
            // Create the parent directory first, then validate containment with realpath
            // (realpath returns false for a nonexistent path, so the create-then-resolve order is required).
            $parent = dirname($destAbs);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                $zip->close();
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_dir_create', 'Failed to create directory: ') . $name);
            }
            $parentReal = realpath($parent);
            if ($parentReal === false || !str_starts_with($parentReal . '/', $stageReal . '/')) {
                $zip->close();
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_path_traversal', 'Path traversal detected: ') . $name);
            }
            // Do not write a corrupt entry (getFromIndex returning false) as an empty file
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                $zip->close();
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_zip_entry_read', 'Could not read ZIP entry: ') . $name);
            }
            $finalPath = $parentReal . '/' . basename($destAbs);
            if (file_put_contents($finalPath, $data) === false) {
                $zip->close();
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_file_write_failed', 'Failed to write file: ') . $name);
            }
        }
        $zip->close();
        return [$topDir, $stageDir];
    }

    private static function assertSafeEntry(string $name): void
    {
        if ($name[0] === '/' || $name[0] === '\\') {
            throw new RuntimeException(HbpLocale::t('err_abs_path_entry', 'Absolute path entry: ') . $name);
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $name)) {
            throw new RuntimeException(HbpLocale::t('err_dir_traversal', 'Directory traversal: ') . $name);
        }
        if (str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new RuntimeException(HbpLocale::t('err_invalid_chars', 'Invalid characters: ') . $name);
        }
    }

    /** @return string the first 8 characters of the trusted key that verified the signature */
    private static function verifySodiumSignature(string $stageDir, array $manifest): string
    {
        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            throw new RuntimeException(HbpLocale::t('err_sodium_required', 'Signature verification requires ext-sodium.'));
        }
        $trustedKeys = array_filter(array_map(
            'trim',
            explode(',', Env::get('PLUGIN_TRUSTED_KEYS', ''))
        ));
        if (empty($trustedKeys)) {
            throw new RuntimeException(HbpLocale::t('err_trusted_keys_missing', 'PLUGIN_TRUSTED_KEYS is not configured.'));
        }
        $sigFile = $stageDir . '/manifest.json.sig';
        if (!is_file($sigFile)) {
            throw new RuntimeException(HbpLocale::t('err_sig_missing', 'manifest.json.sig does not exist (signature required).'));
        }
        $sig     = base64_decode(trim((string)file_get_contents($sigFile)), true);
        $message = file_get_contents($stageDir . '/manifest.json');
        $signed  = null;
        foreach ($trustedKeys as $pubKeyB64) {
            $pubKey = base64_decode($pubKeyB64, true);
            if ($sig !== false && strlen($sig) === SODIUM_CRYPTO_SIGN_BYTES
                && $pubKey !== false && strlen($pubKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($sig, $message, $pubKey)) {
                $signed = substr($pubKeyB64, 0, 8);
                break;
            }
        }
        if ($signed === null) {
            throw new RuntimeException(HbpLocale::t('err_sig_verify_failed', 'Signature verification failed (no trusted key matched).'));
        }
        // The signature covers manifest.json only, so it vouches for the rest of the package
        // through the SHA-256 of every file listed in manifest.json's "files".
        self::verifyChecksums($stageDir, $manifest);
        return $signed;
    }

    /**
     * Check every extracted file against manifest.json's "files" map ({relative path: sha256 hex}).
     * A file that is not listed, a hash that differs and a listed file that is missing all fail.
     */
    private static function verifyChecksums(string $stageDir, array $manifest): void
    {
        $listed = $manifest['files'] ?? null;
        if (!is_array($listed) || $listed === []) {
            throw new RuntimeException(HbpLocale::t('err_checksums_missing', 'manifest.json has no "files" checksum list (required for signed plugins).'));
        }
        $actual = self::checksums($stageDir);
        foreach ($actual as $rel => $hash) {
            if (!isset($listed[$rel]) || !is_string($listed[$rel]) || !hash_equals(strtolower($listed[$rel]), $hash)) {
                throw new RuntimeException(HbpLocale::t('err_checksum_mismatch', 'File is not covered by the signature: ') . $rel);
            }
        }
        foreach (array_keys($listed) as $rel) {
            if (!isset($actual[$rel])) {
                throw new RuntimeException(HbpLocale::t('err_checksum_missing_file', 'File listed in manifest.json is missing: ') . $rel);
            }
        }
    }

    /**
     * SHA-256 of every file in a plugin directory, keyed by path relative to it (with "/").
     * manifest.json and its signature are left out: they carry the list rather than being listed.
     *
     * @return array<string,string>
     */
    public static function checksums(string $dir): array
    {
        $base  = rtrim($dir, '/') . '/';
        $files = [];
        $it    = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base)));
            if ($rel === 'manifest.json' || $rel === 'manifest.json.sig') {
                continue;
            }
            $files[$rel] = hash_file('sha256', $file->getPathname());
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * SHA-256 of every file, manifest.json and its signature included: what the review compares,
     * and what apply() checks has not changed since.
     *
     * @return array<string,string>
     */
    public static function fingerprint(string $dir): array
    {
        return FileTree::fingerprint($dir);
    }

    /**
     * Copy a folder from plugins/_incoming/ with the same rules as a ZIP: allowed extensions and
     * names only, limits on count and size, no symbolic links.
     */
    private static function copyChecked(string $src, string $dst, string $rel, int &$count, int &$bytes): void
    {
        if (!@mkdir($dst, 0755) && !is_dir($dst)) {
            throw new RuntimeException(HbpLocale::t('err_dir_create', 'Failed to create directory: ') . $rel);
        }
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $src . '/' . $item;
            $r    = ltrim($rel . '/' . $item, '/');
            if (is_link($path)) {
                throw new RuntimeException(HbpLocale::t('err_symlink', 'Symbolic links are not allowed: ') . $r);
            }
            if (is_dir($path)) {
                self::copyChecked($path, $dst . '/' . $item, $r, $count, $bytes);
                continue;
            }
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (!self::allowedFile($r)) {
                throw new RuntimeException(HbpLocale::t('err_ext_not_allowed', 'Disallowed extension: .') . $ext);
            }
            if (++$count > self::MAX_FILES) {
                throw new RuntimeException(HbpLocale::t('err_zip_too_many_files', 'The number of files in the ZIP exceeds the limit (:max).', ['max' => self::MAX_FILES]));
            }
            $bytes += (int)filesize($path);
            if ($bytes > self::MAX_SIZE_BYTES) {
                throw new RuntimeException(HbpLocale::t('err_zip_too_large', 'The ZIP extraction size exceeds the limit (:limit) (zip-bomb protection).',
                    ['limit' => self::formatBytes(self::MAX_SIZE_BYTES)]));
            }
            if (!copy($path, $dst . '/' . $item)) {
                throw new RuntimeException(HbpLocale::t('err_file_write_failed', 'Failed to write file: ') . $r);
            }
        }
    }

    private static function moveDir(string $src, string $dst): void
    {
        FileTree::move($src, $dst);
    }

    private static function invalidateOpcache(string $dir): void
    {
        FileTree::invalidateOpcache($dir);
    }

    /** Remove a directory tree, never following a symbolic link (FileTree::remove()). */
    private static function cleanStage(string $dir): void
    {
        FileTree::remove($dir);
    }

    /** Render a byte limit for messages, e.g. 10485760 -> "10MB". */
    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? (string)intdiv($bytes, 1024 * 1024) . 'MB'
            : (string)intdiv($bytes, 1024) . 'KB';
    }
}
