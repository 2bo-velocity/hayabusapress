<?php
/**
 * HayabusaPress - Safe plugin ZIP extraction (zip-slip / signature / audit).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * G-series: safe ZIP extraction (zip-slip countermeasure, Ed25519 signature, audit).
 * ZipArchive::extractTo() is forbidden because it is a zip-slip vector.
 * Each entry is validated and manually extracted to staging, and after validation,
 * moved to plugins/{slug} via an atomic rename.
 */
class PluginInstaller
{
    private const MAX_FILES      = 500;
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
    private const STAGE_DIR      = STORAGE_PATH . '/upload/plugin_stage';

    /** Allowed extensions */
    private const ALLOWED_EXTS = ['php', 'json', 'md', 'txt', 'css', 'js', 'svg', 'png', 'jpg', 'webp'];

    /**
     * Safely extract a ZIP and install the plugin.
     *
     * @throws RuntimeException on validation failure
     */
    public static function install(string $zipPath): string
    {
        // A: authorization check
        if (!Env::bool('ALLOW_PLUGIN_INSTALL', false)) {
            throw new RuntimeException(HbpLocale::t('err_plugin_install_disabled', 'Web-based plugin installation is disabled (ALLOW_PLUGIN_INSTALL=false).'));
        }

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
        $topDir   = array_key_first($topDirs);
        $stageDir = self::STAGE_DIR . '/' . $topDir . '.' . getmypid();

        // Manual extraction to staging
        @mkdir($stageDir, 0755, true);
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
            if (!in_array($ext, self::ALLOWED_EXTS, true)) {
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

        // C: integrity - manifest.json existence check
        $manifestPath = $stageDir . '/manifest.json';
        if (!is_file($manifestPath)) {
            self::cleanStage($stageDir);
            throw new RuntimeException(HbpLocale::t('err_manifest_missing', 'manifest.json does not exist.'));
        }
        $manifest = json_decode(file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['id'])) {
            self::cleanStage($stageDir);
            throw new RuntimeException(HbpLocale::t('err_manifest_invalid', 'manifest.json is invalid (the id field is required).'));
        }
        $pluginId = preg_replace('/[^a-z0-9\-]/', '', strtolower($manifest['id']));
        if ($pluginId === '') {
            self::cleanStage($stageDir);
            throw new RuntimeException(HbpLocale::t('err_manifest_id_invalid', 'The id in manifest.json is invalid.'));
        }

        // Ed25519 signature verification (optional)
        if (Env::bool('REQUIRE_SIGNED_PLUGINS', false)) {
            self::verifySodiumSignature($stageDir, $manifest);
        }

        // Move to plugins/{id} via an atomic rename
        $dest = ROOT_PATH . '/plugins/' . $pluginId;
        if (is_dir($dest)) {
            self::rrmdir($dest);
        }
        rename($stageDir, $dest);

        // D: audit log
        self::audit("installed plugin={$pluginId}");

        return $pluginId;
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

    private static function verifySodiumSignature(string $stageDir, array $manifest): void
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
        $sig     = base64_decode(file_get_contents($sigFile), true);
        $message = file_get_contents($stageDir . '/manifest.json');
        foreach ($trustedKeys as $pubKeyB64) {
            $pubKey = base64_decode($pubKeyB64, true);
            if ($pubKey && sodium_crypto_sign_verify_detached($sig, $message, $pubKey)) {
                return;
            }
        }
        throw new RuntimeException(HbpLocale::t('err_sig_verify_failed', 'Signature verification failed (no trusted key matched).'));
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
        $logFile = STORAGE_PATH . '/logs/plugin-audit.log';
        @file_put_contents(
            $logFile,
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
