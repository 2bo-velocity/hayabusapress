<?php
/**
 * HayabusaPress - Theme installation and updates: staging, review, apply, roll back, repair.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

require_once __DIR__ . '/FileTree.php';

/**
 * A theme bundles executable templates (*.tpl.php), so it is installed the way a plugin is
 * (PluginInstaller): a ZIP is checked and extracted into storage/upload/theme_stage/{name}.{token}/,
 * a review lists what would change (files, PHP syntax errors, what the code does), and applying
 * moves the installed version to storage/themes/backup/ before putting the new one in place, under
 * a lock and with a journal so an interrupted update can be repaired. "default" is reserved: it is
 * part of HayabusaPress and updated with it.
 *
 * Unlike plugins there is no plugins/_incoming/ equivalent and no change detection: a theme edited
 * over FTP is the documented way to customise it (ThemeManager::rebuild()).
 */
class ThemeInstaller
{
    private const MAX_FILES      = 1000;
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB (themes may bundle images)
    private const STAGE_DIR      = STORAGE_PATH . '/upload/theme_stage';
    private const THEMES_DIR     = ROOT_PATH . '/themes';
    private const BACKUP_DIR     = STORAGE_PATH . '/themes/backup';
    private const JOURNAL_DIR    = STORAGE_PATH . '/themes/.journal';
    private const LOCK_FILE      = STORAGE_PATH . '/themes/.lock';
    private const STAGE_TTL      = 3600;

    /** Allowed extensions (.tpl.php resolves to "php"). */
    private const ALLOWED_EXTS = [
        'php', 'json', 'md', 'txt', 'css', 'js', 'svg',
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'ttf',
    ];

    /** Extension-less files accepted by exact name (licence and notice files of the package). */
    private const ALLOWED_BARE_NAMES = ['LICENSE', 'LICENCE', 'COPYING', 'NOTICE', 'README', 'CHANGELOG', 'AUTHORS'];

    /** A valid theme must provide these templates. */
    private const REQUIRED_TEMPLATES = ['index', 'post', 'page', 'header', 'footer'];

    /**
     * Stage an uploaded theme ZIP and write its review.
     *
     * @return string the review token
     * @throws RuntimeException when the package is refused (nothing is left behind)
     */
    public static function stageZip(string $zipPath): string
    {
        require_once CORE_PATH . '/PluginInstaller.php';
        require_once CORE_PATH . '/PluginScanner.php';
        self::cleanupExpired();
        $token = bin2hex(random_bytes(8));
        [$name, $stageDir] = self::extractZip($zipPath, $token);

        $current    = self::THEMES_DIR . '/' . $name;
        $hasCurrent = is_dir($current) && !is_link($current);
        $meta       = self::meta($stageDir);
        $curMeta    = $hasCurrent ? self::meta($current) : null;
        $stageFiles = FileTree::fingerprint($stageDir);
        $baseFiles  = $hasCurrent ? FileTree::fingerprint($current) : null;

        $review = [
            'token'         => $token,
            'name'          => $name,
            'created_at'    => date('Y-m-d H:i:s'),
            'title'         => (string)($meta['name'] ?? $name),
            'version'       => (string)($meta['version'] ?? ''),
            'author'        => (string)($meta['author'] ?? ''),
            'current'       => $curMeta === null ? null : ['version' => (string)($curMeta['version'] ?? ''), 'author' => (string)($curMeta['author'] ?? '')],
            'active'        => $name === ThemeManager::active(),
            'syntax_errors' => PluginInstaller::syntaxErrors($stageDir),
            'scan'          => PluginScanner::markNew(PluginScanner::scan($stageDir), $hasCurrent ? PluginScanner::scan($current) : []),
            'file_diff'     => self::diffFiles($baseFiles ?? [], $stageFiles),
            'stage_files'   => $stageFiles,
            'base_files'    => $baseFiles,
        ];
        $json = json_encode($review, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents(self::STAGE_DIR . '/' . $name . '.' . $token . '.json', $json, LOCK_EX) === false) {
            self::cleanStage($stageDir);
            throw new RuntimeException(HbpLocale::t('err_stage_dir_failed', 'Could not create the staging directory.'));
        }
        Audit::log('theme', 'staged', ['theme' => $name, 'version' => $review['version']]);
        return $token;
    }

    /** The review of a staged theme, or null when there is none (expired, applied or discarded). */
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
        self::cleanStage(self::STAGE_DIR . '/' . $review['name'] . '.' . $token);
        @unlink(self::STAGE_DIR . '/' . $review['name'] . '.' . $token . '.json');
        Audit::log('theme', 'discarded', ['theme' => $review['name']]);
    }

    public static function cleanupExpired(): void
    {
        foreach (glob(self::STAGE_DIR . '/*') ?: [] as $path) {
            if (@filemtime($path) < time() - self::STAGE_TTL) {
                is_dir($path) && !is_link($path) ? self::cleanStage($path) : @unlink($path);
            }
        }
    }

    /**
     * Apply a staged theme: refuses when the staged copy or the installed theme changed since the
     * review, or when a template does not parse.
     *
     * @return string the theme name
     */
    public static function apply(string $token): string
    {
        $review = self::review($token);
        if ($review === null) {
            throw new RuntimeException(HbpLocale::t('err_review_expired', 'This review has expired or was already used. Upload or stage it again.'));
        }
        $name  = $review['name'];
        $stage = self::STAGE_DIR . '/' . $name . '.' . $token;
        self::withLock(static function () use ($review, $name, $stage, $token): void {
            $current = self::THEMES_DIR . '/' . $name;
            if (!is_dir($stage) || FileTree::fingerprint($stage) !== $review['stage_files']
                || (is_dir($current) && !is_link($current) ? FileTree::fingerprint($current) : null) !== $review['base_files']) {
                throw new RuntimeException(HbpLocale::t('err_review_changed', 'The files changed after the review was made. Stage it again and review it.'));
            }
            if ($review['syntax_errors'] !== []) {
                throw new RuntimeException(HbpLocale::t('err_review_syntax', 'It has PHP syntax errors and cannot be applied.'));
            }
            self::swapIn($name, $stage, 'apply');
            @unlink(self::STAGE_DIR . '/' . $name . '.' . $token . '.json');
            Audit::log('theme', 'applied', ['theme' => $name, 'from' => $review['current']['version'] ?? '', 'to' => $review['version']]);
        });
        return $name;
    }

    /** The kept previous version of a theme: [version, backed_up_at], or null. */
    public static function backup(string $name): ?array
    {
        $dir = self::BACKUP_DIR . '/' . $name;
        if (!ThemeManager::isValidName($name) || !is_dir($dir)) {
            return null;
        }
        $meta = json_decode((string)@file_get_contents(self::BACKUP_DIR . '/' . $name . '.json'), true);
        return [
            'version'      => (string)(self::meta($dir)['version'] ?? ''),
            'backed_up_at' => is_array($meta) ? (string)($meta['backed_up_at'] ?? '') : '',
        ];
    }

    /** Swap the installed theme and the kept previous one. */
    public static function rollback(string $name): void
    {
        if (self::backup($name) === null) {
            throw new RuntimeException(HbpLocale::t('err_theme_no_backup', 'There is no previous version of this theme to go back to.'));
        }
        self::withLock(static function () use ($name): void {
            $from = (string)(self::meta(self::THEMES_DIR . '/' . $name)['version'] ?? '');
            self::swapIn($name, self::BACKUP_DIR . '/' . $name, 'rollback');
            Audit::log('theme', 'rolled_back', ['theme' => $name, 'from' => $from, 'to' => (string)(self::meta(self::THEMES_DIR . '/' . $name)['version'] ?? '')]);
        });
    }

    /** @return array<string,array> name => journal of an interrupted operation */
    public static function interrupted(): array
    {
        $out = [];
        foreach (glob(self::JOURNAL_DIR . '/*.json') ?: [] as $file) {
            $j = json_decode((string)file_get_contents($file), true);
            if (is_array($j) && isset($j['name'])) {
                $out[(string)$j['name']] = $j;
            }
        }
        return $out;
    }

    /** Finish or undo an interrupted operation from its journal (the same steps as PluginInstaller::repair()). */
    public static function repair(string $name): string
    {
        $journal = self::interrupted()[$name] ?? null;
        if ($journal === null) {
            return HbpLocale::t('repair_nothing', 'There was nothing to repair.');
        }
        $message = '';
        self::withLock(static function () use ($journal, $name, &$message): void {
            $current   = self::THEMES_DIR . '/' . $name;
            $backupNew = self::BACKUP_DIR . '/' . $name . '.new';
            switch ($journal['step']) {
                case 'backed_up':
                    if (!is_dir($current) && is_dir($backupNew)) {
                        FileTree::move($backupNew, $current);
                    }
                    self::writeJournal(['step' => 'restored'] + $journal);
                    break;
                case 'placed':
                    if (is_dir($current)) {
                        FileTree::move($current, $journal['source']);
                    }
                    if (!empty($journal['had_current']) && is_dir($backupNew)) {
                        FileTree::move($backupNew, $current);
                    }
                    self::writeJournal(['step' => 'restored'] + $journal);
                    break;
                case 'committed':
                    self::rotateBackup($name);
                    break;
            }
            self::refresh($name);
            @unlink(self::JOURNAL_DIR . '/' . $name . '.json');
            Audit::log('theme', 'repaired', ['theme' => $name, 'op' => $journal['op'], 'step' => $journal['step']]);
            $message = match ($journal['step']) {
                'committed' => HbpLocale::t('repair_finished', 'The interrupted operation had already taken effect; its clean-up was finished.'),
                'restored'  => HbpLocale::t('repair_rebuilt', 'The previous version was already back in place; the caches were rebuilt.'),
                default     => HbpLocale::t('repair_undone', 'The interrupted operation was undone; the version before it is back in place.'),
            };
        });
        return $message;
    }

    /** Forget a deleted theme's kept backup. */
    public static function forget(string $name): void
    {
        if (ThemeManager::isValidName($name)) {
            self::cleanStage(self::BACKUP_DIR . '/' . $name);
            @unlink(self::BACKUP_DIR . '/' . $name . '.json');
        }
    }

    /**
     * Put $source in place of themes/{name}/, keeping the installed version as the backup. On
     * failure after the files moved, the previous version is put back (journal step "restored");
     * if even that fails, the journal is left for repair().
     */
    private static function swapIn(string $name, string $source, string $op): void
    {
        if (is_file(self::JOURNAL_DIR . '/' . $name . '.json')) {
            throw new RuntimeException(HbpLocale::t('err_theme_interrupted', 'An earlier operation on this theme was interrupted. Repair it first.'));
        }
        foreach ([self::THEMES_DIR, self::BACKUP_DIR, self::JOURNAL_DIR] as $dir) {
            if ((!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) || !is_writable($dir)) {
                throw new RuntimeException(HbpLocale::t('err_plugin_not_writable', 'Cannot write to :dir. Make it writable for PHP (for example over FTP) and try again.',
                    ['dir' => str_replace(ROOT_PATH . '/', '', $dir) . '/']));
            }
        }
        @ignore_user_abort(true);
        @set_time_limit(300);

        $current   = self::THEMES_DIR . '/' . $name;
        $backupNew = self::BACKUP_DIR . '/' . $name . '.new';
        if (is_dir($backupNew)) {
            self::cleanStage($backupNew);
        }
        $journal = ['op' => $op, 'name' => $name, 'step' => 'begin', 'source' => $source,
                    'had_current' => is_dir($current), 'started_at' => date('Y-m-d H:i:s')];
        self::writeJournal($journal);

        if ($journal['had_current']) {
            FileTree::move($current, $backupNew);
            self::writeJournal($journal = ['step' => 'backed_up'] + $journal);
        }
        try {
            FileTree::move($source, $current);
        } catch (Throwable $e) {
            if ($journal['had_current']) {
                FileTree::move($backupNew, $current);
            }
            @unlink(self::JOURNAL_DIR . '/' . $name . '.json');
            throw $e;
        }
        self::writeJournal($journal = ['step' => 'placed'] + $journal);

        try {
            self::refresh($name);
        } catch (Throwable $e) {
            Audit::log('theme', 'apply_failed', ['theme' => $name, 'op' => $op]);
            try {
                FileTree::move($current, $source);
                if ($journal['had_current']) {
                    FileTree::move($backupNew, $current);
                }
                self::writeJournal($journal = ['step' => 'restored'] + $journal);
                self::refresh($name);
                @unlink(self::JOURNAL_DIR . '/' . $name . '.json');
            } catch (Throwable $e2) {
                throw new RuntimeException(HbpLocale::t('err_theme_restore_failed',
                    'Applying failed (:message) and putting the previous version back failed too (:second). Use "Repair" on the themes screen, or php core/cli.php themes:repair :name.',
                    ['message' => $e->getMessage(), 'second' => $e2->getMessage(), 'name' => $name]), 0, $e);
            }
            throw new RuntimeException(HbpLocale::t('err_plugin_apply_restored', 'The new version was not applied, and the previous version is back in place: :message',
                ['message' => $e->getMessage()]), 0, $e);
        }

        self::writeJournal($journal = ['step' => 'committed'] + $journal);
        self::rotateBackup($name);
        @unlink(self::JOURNAL_DIR . '/' . $name . '.json');
    }

    /**
     * After the files of a theme changed: publish its assets, and when it is the active theme, drop
     * the compiled views and every cached page (they were made from the old templates).
     */
    private static function refresh(string $name): void
    {
        FileTree::invalidateOpcache(self::THEMES_DIR . '/' . $name);
        if ($name === ThemeManager::active()) {
            ThemeManager::rebuild();
        } else {
            ThemeManager::publishAssets($name);
        }
    }

    private static function rotateBackup(string $name): void
    {
        $backupNew = self::BACKUP_DIR . '/' . $name . '.new';
        if (!is_dir($backupNew)) {
            return;
        }
        self::cleanStage(self::BACKUP_DIR . '/' . $name);   // the new backup exists already
        FileTree::move($backupNew, self::BACKUP_DIR . '/' . $name);
        @file_put_contents(self::BACKUP_DIR . '/' . $name . '.json', (string)json_encode(['backed_up_at' => date('Y-m-d H:i:s')]), LOCK_EX);
    }

    private static function withLock(callable $fn): void
    {
        if (!is_dir(dirname(self::LOCK_FILE))) {
            @mkdir(dirname(self::LOCK_FILE), 0755, true);
        }
        $fh = @fopen(self::LOCK_FILE, 'c');
        if ($fh === false) {
            throw new RuntimeException(HbpLocale::t('err_plugin_not_writable', 'Cannot write to :dir. Make it writable for PHP (for example over FTP) and try again.',
                ['dir' => 'storage/themes/']));
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new RuntimeException(HbpLocale::t('err_theme_busy', 'Another theme operation is running. Try again in a moment.'));
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
        $file = self::JOURNAL_DIR . '/' . $journal['name'] . '.json';
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, (string)json_encode($journal, JSON_UNESCAPED_SLASHES), LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException(HbpLocale::t('err_plugin_not_writable', 'Cannot write to :dir. Make it writable for PHP (for example over FTP) and try again.',
                ['dir' => 'storage/themes/.journal/']));
        }
    }

    /** theme.json of a theme folder, or []. */
    private static function meta(string $dir): array
    {
        $m = json_decode((string)@file_get_contents($dir . '/theme.json'), true);
        return is_array($m) ? $m : [];
    }

    /** @return array{added:string[],removed:string[],changed:string[]} */
    private static function diffFiles(array $old, array $new): array
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

    /**
     * Validate every entry of a theme ZIP and extract it by hand into a new staging directory.
     *
     * @return array{0:string,1:string} [the theme name (top directory), the staging directory]
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

        // Validate entries (zip-slip, size, count, single top directory)
        $count     = $zip->numFiles;
        $totalSize = 0;
        $topDirs   = [];
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
            $parts = explode('/', ltrim($name, '/'), 2);
            if ($parts[0] !== '') {
                $topDirs[$parts[0]] = true;
            }
        }

        if (count($topDirs) !== 1) {
            $zip->close();
            throw new RuntimeException(HbpLocale::t('err_zip_single_topdir', 'The ZIP must contain a single top-level directory.'));
        }
        $topDir = array_key_first($topDirs);

        // The top directory name is the theme slug.
        $themeName = strtolower($topDir);
        if (!ThemeManager::isValidName($themeName)) {
            $zip->close();
            throw new RuntimeException(HbpLocale::t('err_theme_name_invalid', 'Invalid theme directory name (use lowercase letters, digits, - and _).'));
        }
        if ($themeName === ThemeManager::DEFAULT_THEME) {
            $zip->close();
            throw new RuntimeException(HbpLocale::t('err_theme_name_reserved', 'The "default" theme is reserved and cannot be overwritten by upload.'));
        }

        // A new directory per staging (random token): nothing of an earlier attempt can be mixed in
        $stageDir  = self::STAGE_DIR . '/' . $themeName . '.' . $token;
        @mkdir($stageDir, 0755, true);
        $stageReal = realpath($stageDir);
        if ($stageReal === false) {
            $zip->close();
            throw new RuntimeException(HbpLocale::t('err_stage_dir_failed', 'Could not create the staging directory.'));
        }

        // Manual extraction to staging (no extractTo()).
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            $name = $stat['name'];
            $rel  = ltrim(str_replace($topDir . '/', '', $name, $replaced), '/');
            if ($rel === '') {
                continue;
            }
            $destAbs = $stageDir . '/' . $rel;

            if (str_ends_with($name, '/')) {
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

            $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $bare = $ext === '' && in_array(strtoupper(basename($name)), self::ALLOWED_BARE_NAMES, true);
            if (!$bare && !in_array($ext, self::ALLOWED_EXTS, true)) {
                $zip->close();
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_ext_not_allowed', 'Disallowed extension: .') . $ext);
            }
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
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                $zip->close();
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_zip_entry_read', 'Could not read ZIP entry: ') . $name);
            }
            if (file_put_contents($parentReal . '/' . basename($destAbs), $data) === false) {
                $zip->close();
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_file_write_failed', 'Failed to write file: ') . $name);
            }
        }
        $zip->close();

        // Validate it is a real theme (required templates present).
        foreach (self::REQUIRED_TEMPLATES as $t) {
            if (!is_file($stageDir . '/' . $t . '.tpl.php')) {
                self::cleanStage($stageDir);
                throw new RuntimeException(HbpLocale::t('err_theme_incomplete', 'The theme is missing required templates (index/post/page/header/footer).'));
            }
        }

        return [$themeName, $stageDir];
    }

    private static function assertSafeEntry(string $name): void
    {
        if ($name === '' || $name[0] === '/' || $name[0] === '\\') {
            throw new RuntimeException(HbpLocale::t('err_abs_path_entry', 'Absolute path entry: ') . $name);
        }
        if (preg_match('#(^|/)\.\.(/|$)#', $name)) {
            throw new RuntimeException(HbpLocale::t('err_dir_traversal', 'Directory traversal: ') . $name);
        }
        if (str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new RuntimeException(HbpLocale::t('err_invalid_chars', 'Invalid characters: ') . $name);
        }
    }

    /** Remove a directory tree, never following a symbolic link. */
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
