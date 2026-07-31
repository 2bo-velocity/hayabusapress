<?php
/**
 * HayabusaPress - Spam IP blocklist for write POSTs.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * B-series: spam IP blocklist for posting (blocked_ips table).
 * Checked at the very front of comment/contact write POSTs.
 * Does not stop viewing (GET); view denial is handled by the SiteDenylistCompiler family.
 * Fails open on DB error (lets the post through).
 */
class IpBlocklist
{
    /**
     * Determine whether the IP is registered in the blocklist.
     * Always false when BLOCKLIST_ENABLED=false.
     */
    public static function isBlocked(string $ip): bool
    {
        if (!Env::bool('BLOCKLIST_ENABLED', true)) {
            return false;
        }

        try {
            $pdo = DB::getInstance();
            $now = date('Y-m-d H:i:s');

            // GC expired entries (low frequency)
            if (random_int(0, 99) === 0) {
                $pdo->prepare('DELETE FROM blocked_ips WHERE expires_at IS NOT NULL AND expires_at < ?')
                    ->execute([$now]);
            }

            $stmt = $pdo->prepare(
                'SELECT id, hit_count FROM blocked_ips
                 WHERE cidr = ? AND (expires_at IS NULL OR expires_at > ?)
                 LIMIT 1'
            );
            $stmt->execute([$ip, $now]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row !== false) {
                // Update the hit count (async is fine, so ignore exceptions)
                try {
                    $pdo->prepare('UPDATE blocked_ips SET hit_count = hit_count + 1 WHERE id = ?')
                        ->execute([(int)$row['id']]);
                } catch (Throwable) {
                }
                return true;
            }
        } catch (Throwable) {
            // Fail open
        }
        return false;
    }

    /**
     * Manually add an IP to the blocklist.
     * @param string      $ip      IPv4/IPv6 or CIDR
     * @param string|null $reason  reason (e.g. 'manual')
     * @param int|null    $ttlSec  NULL = permanent, integer = temporary block in seconds
     */
    public static function block(string $ip, string $reason = 'manual', ?int $ttlSec = null): void
    {
        $expiresAt = $ttlSec !== null ? date('Y-m-d H:i:s', time() + $ttlSec) : null;
        $now       = date('Y-m-d H:i:s');
        $driver    = DB::driver();

        if ($driver === 'pgsql') {
            DB::execute(
                'INSERT INTO blocked_ips (cidr, reason, source, expires_at, created_at)
                 VALUES (?, ?, \'manual\', ?, ?)
                 ON CONFLICT (cidr) DO UPDATE SET reason=EXCLUDED.reason, expires_at=EXCLUDED.expires_at',
                [$ip, $reason, $expiresAt, $now]
            );
        } else {
            DB::execute(
                'INSERT INTO blocked_ips (cidr, reason, source, expires_at, created_at)
                 VALUES (?, ?, \'manual\', ?, ?)
                 ON DUPLICATE KEY UPDATE reason=VALUES(reason), expires_at=VALUES(expires_at)',
                [$ip, $reason, $expiresAt, $now]
            );
        }
    }

    /** Remove a block. */
    public static function unblock(string $ip): void
    {
        DB::execute('DELETE FROM blocked_ips WHERE cidr = ?', [$ip]);
    }

    /** Fetch all blocklist entries (for the admin screen / CLI). */
    public static function list(): array
    {
        return DB::fetchAll(
            'SELECT id, cidr, reason, source, hit_count, expires_at, created_at
             FROM blocked_ips ORDER BY created_at DESC'
        );
    }

    /**
     * Automatic blocking by aggregated signals.
     * When BLOCKLIST_AUTO_THRESHOLD=0, no automatic blocking is performed.
     */
    public static function autoBlock(string $ip, string $signal): void
    {
        $threshold = Env::int('BLOCKLIST_AUTO_THRESHOLD', 5);
        if ($threshold === 0) {
            return;
        }

        // Reuse auth_throttle for the signal count (scope='ip')
        // Temporary block when the threshold is exceeded
        try {
            $pdo = DB::getInstance();
            $stmt = $pdo->prepare(
                'SELECT fail_count FROM auth_throttle WHERE scope=\'ip\' AND scope_key=? LIMIT 1'
            );
            $stmt->execute([$ip]);
            $row  = $stmt->fetch(PDO::FETCH_ASSOC);
            $count = (int)($row['fail_count'] ?? 0);

            if ($count >= $threshold) {
                $ttl = Env::int('BLOCKLIST_AUTO_TTL', 86400);
                self::block($ip, "auto:{$signal}", $ttl);
            }
        } catch (Throwable) {
        }
    }
}
