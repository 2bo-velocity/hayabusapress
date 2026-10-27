<?php
/**
 * HayabusaPress - Log of errors raised by plugins at run time, for the admin panel.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Hook listeners and plugin routes that throw, and fatal errors in a plugin's files, are written to
 * storage/logs/plugin-errors.log (one JSON object per line) and summarised on the plugins screen:
 * many sites cannot read the PHP error log. Nothing is written unless an error happens, so a
 * healthy site pays nothing.
 *
 * Recording runs inside catch blocks and shutdown handlers, so it must never throw: every failure
 * here is ignored. Messages are shortened, kept to one line, and have secret settings masked
 * (Env::secretValues()) before they are written, since they are shown on screen.
 */
class PluginErrors
{
    private const FILE        = STORAGE_PATH . '/logs/plugin-errors.log';
    private const MAX_BYTES   = 5 * 1024 * 1024;
    private const MAX_MESSAGE = 500;

    /** @param string $where the event, "route" or "fatal" */
    public static function record(string $plugin, string $where, string $message): void
    {
        try {
            if (is_file(self::FILE) && (int)@filesize(self::FILE) >= self::MAX_BYTES) {
                return;
            }
            $line = json_encode([
                't'       => date('Y-m-d H:i:s'),
                'plugin'  => $plugin,
                'where'   => $where,
                'message' => self::clean($message),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line !== false) {
                @file_put_contents(self::FILE, $line . "\n", FILE_APPEND | LOCK_EX);
            }
        } catch (Throwable) {
            // Never let the log turn a handled error into an unhandled one
        }
    }

    /** The plugin a file belongs to (plugins/{id}/...), or null for a file outside plugins/. */
    public static function pluginOfFile(string $file): ?string
    {
        $base = realpath(ROOT_PATH . '/plugins');
        $real = realpath($file) ?: $file;
        if ($base === false || !str_starts_with($real, $base . '/')) {
            return null;
        }
        $id = strstr(substr($real, strlen($base) + 1), '/', true);
        return $id !== false && $id !== '' && $id[0] !== '_' ? $id : null;
    }

    /**
     * Per plugin: how many errors are in the log, and the latest one.
     *
     * @return array<string,array{count:int,last_at:string,last_where:string,last_message:string}>
     */
    public static function summary(): array
    {
        $out = [];
        foreach (@file(self::FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $e = json_decode($line, true);
            if (!is_array($e) || !isset($e['plugin'])) {
                continue;
            }
            $p = (string)$e['plugin'];
            $out[$p] = [
                'count'        => ($out[$p]['count'] ?? 0) + 1,
                'last_at'      => (string)($e['t'] ?? ''),
                'last_where'   => (string)($e['where'] ?? ''),
                'last_message' => (string)($e['message'] ?? ''),
            ];
        }
        return $out;
    }

    public static function clear(): void
    {
        @unlink(self::FILE);
    }

    /** One line, bounded, with the values of secret settings replaced by ***. */
    public static function clean(string $message): string
    {
        $message = str_replace(class_exists('Env') ? Env::secretValues() : [], '***', $message);
        $message = preg_replace('/\s+/u', ' ', $message) ?? '';
        return mb_substr(trim($message), 0, self::MAX_MESSAGE);
    }
}
