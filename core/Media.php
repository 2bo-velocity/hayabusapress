<?php
/**
 * HayabusaPress - Single source of truth for media storage paths (sharding).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Media — single source of truth for the media storage location ( sharding / automatic directory creation)
 *
 * media is distributed across a 2-level {ab}/{cd}/ hierarchy (65,536 buckets) based on the filename prefix.
 *   Prevents file concentration in a single directory (scan degradation at millions of files, inode limits).
 *   The DB (attachments.filename) holds only the filename; the save/serve/move/GC paths are all
 *   derived from shardPath() (no schema change).
 *
 * before writing, ensureDir() recursively creates the destination (parent + shard intermediate {ab}/{cd}/).
 *   Default permission 0755 (changeable via .env MEDIA_DIR_MODE). chmod is explicit after creation as a umask countermeasure.
 *   On failure it throws RuntimeException so the caller does not create an attachments row.
 */
final class Media
{
    /** Derive the shard-relative path 'ab/cd/filename' from a filename (the single derivation function). */
    public static function shardPath(string $filename): string
    {
        $filename = basename($filename); // Path-traversal defense
        // Abnormal case under 4 chars is parked in a single bucket (normal hash/avatar names are long enough)
        if (strlen($filename) < 4) {
            return '__/__/' . $filename;
        }
        return substr($filename, 0, 2) . '/' . substr($filename, 2, 2) . '/' . $filename;
    }

    /** Return MEDIA_DIR_MODE (default 0755) as an octal int. */
    public static function dirMode(): int
    {
        $raw = class_exists('Env') ? trim((string)Env::get('MEDIA_DIR_MODE', '0755')) : '0755';
        if ($raw === '' || !preg_match('/^0?[0-7]{3,4}$/', $raw)) {
            $raw = '0755';
        }
        return intval($raw, 8);
    }

    /**
     * recursively create a directory (idempotent).
     * Does nothing if it already exists. After creation, chmod is explicit as a umask countermeasure. Creation failure throws.
     * Concurrent-upload races (TOCTOU) are absorbed by re-checking.
     */
    public static function ensureDir(string $dir, ?int $mode = null): void
    {
        if ($dir === '' || is_dir($dir)) {
            return;
        }
        $mode = $mode ?? self::dirMode();
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            // Media may be called from contexts without HbpLocale (CLI, etc.), so guard it
            $msg = class_exists('HbpLocale')
                ? HbpLocale::t('err_media_dir_create', 'Failed to create the media storage directory: ')
                : 'Failed to create the media storage directory: ';
            throw new RuntimeException($msg . $dir);
        }
        @chmod($dir, $mode);
    }

    /** Ensure the parent directory of the given file path (including the shard intermediate). */
    public static function ensureDirForFile(string $filePath): void
    {
        self::ensureDir(dirname($filePath));
    }

    // ------------------------------------------------------------
    // The three media parent directories
    // ------------------------------------------------------------

    public static function uploadBase(): string  { return STORAGE_PATH . '/upload'; }
    public static function articlesBase(): string { return PUBLIC_PATH  . '/assets/articles'; }
    public static function avatarsBase(): string  { return PUBLIC_PATH  . '/assets/site/avatars'; }

    /** List of physical paths of the three media parent directories (for diagnostics/ensuring). */
    public static function baseDirs(): array
    {
        return [self::uploadBase(), self::articlesBase(), self::avatarsBase()];
    }

    // ------------------------------------------------------------
    // Shard-applied physical paths
    // ------------------------------------------------------------

    public static function uploadPath(string $filename): string
    {
        return self::uploadBase() . '/' . self::shardPath($filename);
    }

    public static function articlePath(string $filename): string
    {
        return self::articlesBase() . '/' . self::shardPath($filename);
    }

    public static function avatarPath(string $filename): string
    {
        return self::avatarsBase() . '/' . self::shardPath($filename);
    }

    // ------------------------------------------------------------
    // Public URL / relative path
    // ------------------------------------------------------------

    /** Public URL of published article media (/assets/articles/{ab}/{cd}/{file}). */
    public static function articleUrl(string $filename): string
    {
        return '/assets/articles/' . self::shardPath($filename);
    }

    /** Avatar shard-relative path (the template prepends /assets/site/avatars/). */
    public static function avatarRel(string $filename): string
    {
        return $filename === '' ? '' : self::shardPath($filename);
    }

    /** Public URL of an avatar (/assets/site/avatars/{ab}/{cd}/{file}). */
    public static function avatarUrl(string $filename): string
    {
        return '/assets/site/avatars/' . self::shardPath($filename);
    }

    // ------------------------------------------------------------
    // Compatibility resolution at serve time (prefer shard, fall back to legacy flat)
    // ------------------------------------------------------------

    /**
     * Resolve the real file path inside storage/upload/ (shard -> flat order).
     * Returns null if not found. For compatibility with legacy flat placement during the migration period.
     */
    public static function resolveUploadPath(string $filename): ?string
    {
        $filename = basename($filename);
        $shard = self::uploadPath($filename);
        if (is_file($shard)) {
            return $shard;
        }
        $flat = self::uploadBase() . '/' . $filename;
        return is_file($flat) ? $flat : null;
    }

    /** Whether the file exists in storage/upload/ (either shard or flat). */
    public static function uploadExists(string $filename): bool
    {
        return self::resolveUploadPath($filename) !== null;
    }
}
