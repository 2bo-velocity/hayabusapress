<?php
/**
 * HayabusaPress - Directory operations shared by plugin and theme installation.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * None of these follows a symbolic link. A plugin or theme folder put on the server over FTP can
 * hold one, and following it would copy the target into public/assets/ or delete the target's
 * contents along with the folder.
 */
class FileTree
{
    /**
     * SHA-256 of every file under $dir, keyed by path relative to it (with "/"). Links are left out.
     *
     * @return array<string,string>
     */
    public static function fingerprint(string $dir): array
    {
        $base  = rtrim($dir, '/') . '/';
        $files = [];
        if (!is_dir($base)) {
            return $files;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->isFile() && !$file->isLink()) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($base)))] = hash_file('sha256', $file->getPathname());
            }
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    /**
     * Move a directory. rename() is atomic but fails across filesystems; then copy into a
     * temporary sibling ("_tmp_" keeps it out of the plugin and theme scans), rename that into
     * place and remove the original, so the destination is never half there.
     */
    public static function move(string $src, string $dst): void
    {
        if (@rename($src, $dst)) {
            return;
        }
        $tmp = dirname($dst) . '/_tmp_' . basename($dst) . '.' . getmypid();
        try {
            self::copy($src, $tmp);
            if (!@rename($tmp, $dst)) {
                throw new RuntimeException(HbpLocale::t('err_file_write_failed', 'Failed to write file: ') . $dst);
            }
        } catch (Throwable $e) {
            self::remove($tmp);
            throw $e;
        }
        self::remove($src);
    }

    /**
     * Copy a directory tree, skipping links.
     *
     * @param bool          $strict throw when a file cannot be copied (false: skip it and go on)
     * @param string[]|null $exts   when given, only files with one of these (lowercase) extensions are copied
     */
    public static function copy(string $src, string $dst, bool $strict = true, ?array $exts = null): void
    {
        if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
            if ($strict) {
                throw new RuntimeException(HbpLocale::t('err_dir_create', 'Failed to create directory: ') . $dst);
            }
            return;
        }
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..' || is_link($src . '/' . $item)) {
                continue;
            }
            $from = $src . '/' . $item;
            if (is_dir($from)) {
                self::copy($from, $dst . '/' . $item, $strict, $exts);
            } elseif ($exts !== null && !in_array(strtolower(pathinfo($item, PATHINFO_EXTENSION)), $exts, true)) {
                continue;
            } elseif (!@copy($from, $dst . '/' . $item) && $strict) {
                throw new RuntimeException(HbpLocale::t('err_file_write_failed', 'Failed to write file: ') . $from);
            }
        }
    }

    /** Remove a directory tree. A symbolic link is removed as a link, never followed. */
    public static function remove(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) && !is_link($path) ? self::remove($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /** Make PHP forget the cached compiled code of the files under $dir (opcache keys by path). */
    public static function invalidateOpcache(string $dir): void
    {
        if (!function_exists('opcache_invalidate')) {
            return;
        }
        foreach (array_keys(self::fingerprint($dir)) as $rel) {
            if (str_ends_with($rel, '.php')) {
                @opcache_invalidate($dir . '/' . $rel, true);
            }
        }
    }
}
