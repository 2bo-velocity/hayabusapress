<?php
/**
 * HayabusaPress - Safe theme ZIP extraction (zip-slip / validation / audit).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Safe theme ZIP extraction.
 *
 * A theme bundles executable templates (*.tpl.php), so uploading one is as sensitive as a plugin
 * upload: it is gated behind ALLOW_THEME_INSTALL and protected with the same zip-slip / size /
 * extension / single-top-directory checks as PluginInstaller. Each entry is validated and manually
 * extracted to staging, then moved to themes/{name} via an atomic rename.
 */
class ThemeInstaller
{
    private const MAX_FILES      = 1000;
    private const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB (themes may bundle images)
    private const STAGE_DIR      = STORAGE_PATH . '/upload/theme_stage';

    /** Allowed extensions (.tpl.php resolves to "php"). */
    private const ALLOWED_EXTS = [
        'php', 'json', 'md', 'txt', 'css', 'js', 'svg',
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'woff', 'woff2', 'ttf',
    ];

    /** A valid theme must provide these templates. */
    private const REQUIRED_TEMPLATES = ['index', 'post', 'page', 'header', 'footer'];

    /**
     * Safely extract a theme ZIP and install it.
     *
     * @return string installed theme name (directory slug)
     * @throws RuntimeException on validation failure
     */
    public static function install(string $zipPath): string
    {
        if (!Env::bool('ALLOW_THEME_INSTALL', false)) {
            throw new RuntimeException(HbpLocale::t('err_theme_install_disabled', 'Web-based theme installation is disabled (ALLOW_THEME_INSTALL=false).'));
        }
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

        $stageDir  = self::STAGE_DIR . '/' . $themeName . '.' . getmypid();
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

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ALLOWED_EXTS, true)) {
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

        // Move to themes/{name} via an atomic rename (overwrite allowed for updates).
        $dest = ROOT_PATH . '/themes/' . $themeName;
        if (is_dir($dest)) {
            self::rrmdir($dest);
        }
        rename($stageDir, $dest);

        // Publish assets so the theme is immediately previewable/selectable.
        ThemeManager::publishAssets($themeName);

        self::audit("installed theme={$themeName}");

        return $themeName;
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

    private static function cleanStage(string $dir): void
    {
        if (is_dir($dir)) {
            self::rrmdir($dir);
        }
    }

    private static function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private static function audit(string $message): void
    {
        @file_put_contents(
            STORAGE_PATH . '/logs/theme-audit.log',
            date('Y-m-d H:i:s') . ' ' . $message . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /** Render a byte limit for messages, e.g. 10485760 -> "10MB". */
    private static function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? (string)intdiv($bytes, 1024 * 1024) . 'MB'
            : (string)intdiv($bytes, 1024) . 'KB';
    }
}
