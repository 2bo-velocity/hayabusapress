<?php
/**
 * HayabusaPress - Audit log for operations that change code or the schema.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * One line per event in storage/logs/{channel}-audit.log (plugin / theme):
 *   2026-10-27 21:00:00 actor=0 ip=203.0.113.9 event=installed plugin=acme-x
 * actor is the user ID (0 = Super User) or "cli".
 *
 * Writing it must never break the operation it records: failures are ignored, and the file stops
 * growing at MAX_BYTES rather than filling the disk (rotation is up to the operator).
 */
class Audit
{
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** @param array<string,scalar|null> $fields */
    public static function log(string $channel, string $event, array $fields = []): void
    {
        $file = STORAGE_PATH . '/logs/' . preg_replace('/[^a-z]/', '', $channel) . '-audit.log';
        if (is_file($file) && (int)@filesize($file) >= self::MAX_BYTES) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            $actor = 'cli';
            $ip    = '-';
        } else {
            $actor = class_exists('Auth') && Auth::check() ? (string)Auth::id() : '-';
            $ip    = class_exists('AccessControl') ? AccessControl::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '-');
        }

        $line = date('Y-m-d H:i:s') . " actor={$actor} ip={$ip} event=" . self::clean($event);
        foreach ($fields as $k => $v) {
            $line .= ' ' . self::clean((string)$k) . '=' . self::clean((string)$v);
        }
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * The last $limit lines of a channel, newest first (the file is read only up to its last 256 KB).
     *
     * @return string[]
     */
    public static function recent(string $channel, int $limit = 50): array
    {
        $file = STORAGE_PATH . '/logs/' . preg_replace('/[^a-z]/', '', $channel) . '-audit.log';
        $size = is_file($file) ? (int)@filesize($file) : 0;
        if ($size === 0) {
            return [];
        }
        $tail  = (string)@file_get_contents($file, false, null, max(0, $size - 262144));
        $lines = array_values(array_filter(explode("\n", $tail), static fn($l) => $l !== ''));
        if ($size > 262144) {
            array_shift($lines);   // the first line may be cut in the middle
        }
        return array_reverse(array_slice($lines, -$limit));
    }

    /** One event per line: no line breaks or control characters, and a bounded length. */
    private static function clean(string $s): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s) ?? '';
        return mb_substr($s, 0, 300);
    }
}
