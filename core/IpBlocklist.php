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
    public static function block(string $ip, string $reason = 'manual', ?int $ttlSec = null, string $source = 'manual'): void
    {
        $expiresAt = $ttlSec !== null ? date('Y-m-d H:i:s', time() + $ttlSec) : null;
        $now       = date('Y-m-d H:i:s');
        $driver    = DB::driver();

        if ($driver === 'pgsql') {
            DB::execute(
                'INSERT INTO blocked_ips (cidr, reason, source, expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (cidr) DO UPDATE SET reason=EXCLUDED.reason, expires_at=EXCLUDED.expires_at',
                [$ip, $reason, $source, $expiresAt, $now]
            );
        } else {
            DB::execute(
                'INSERT INTO blocked_ips (cidr, reason, source, expires_at, created_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE reason=VALUES(reason), expires_at=VALUES(expires_at)',
                [$ip, $reason, $source, $expiresAt, $now]
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
     * Record one spam signal for an IP (honeypot hit, DNSBL/API hit, rate limit exceeded) and block it
     * temporarily once BLOCKLIST_AUTO_THRESHOLD signals arrive within BLOCKLIST_AUTO_WINDOW seconds.
     * BLOCKLIST_AUTO_THRESHOLD=0 turns automatic blocking off. Fails open on DB errors.
     *
     * Signals are counted under their own scope in auth_throttle ('spam'), apart from login failures.
     */
    public static function autoBlock(string $ip, string $signal): void
    {
        $threshold = Env::int('BLOCKLIST_AUTO_THRESHOLD', 5);
        if ($threshold <= 0 || $ip === '') {
            return;
        }
        try {
            $count = self::count('spam', $ip, max(60, Env::int('BLOCKLIST_AUTO_WINDOW', 3600)));
            if ($count >= $threshold) {
                self::block($ip, "auto:{$signal}", Env::int('BLOCKLIST_AUTO_TTL', 86400), 'auto');
            }
        } catch (Throwable) {
        }
    }

    /**
     * Per-IP submission limit. Counts this submission and returns true when it goes over the limit,
     * which the caller answers with 429. Going over is itself a spam signal.
     *
     * @param string $scope  'comment' or 'contact' (at most 8 characters: auth_throttle.scope)
     * @param string $envKey e.g. COMMENT_RATE_LIMIT, as "count/seconds" ("5/600"); "0" = no limit
     */
    public static function rateLimited(string $scope, string $ip, string $envKey, string $default): bool
    {
        $spec = trim(Env::get($envKey, $default));
        if (!preg_match('#^(\d+)\s*/\s*(\d+)$#', $spec, $m) || (int)$m[1] === 0 || (int)$m[2] === 0) {
            return false;
        }
        try {
            if (self::count($scope, $ip, (int)$m[2]) > (int)$m[1]) {
                self::autoBlock($ip, 'rate:' . $scope);
                return true;
            }
        } catch (Throwable) {
            // Fail open: a counter error never stops a legitimate post
        }
        return false;
    }

    /** Add one to a fixed-window counter in auth_throttle and return the new value. */
    private static function count(string $scope, string $key, int $windowSec): int
    {
        $pdo   = DB::getInstance();
        $now   = date('Y-m-d H:i:s');
        $key   = substr($key, 0, 64);
        // Low-frequency GC of submission counters left by IPs that never came back
        if (random_int(0, 99) === 0) {
            $pdo->prepare("DELETE FROM auth_throttle WHERE scope IN ('spam', 'comment', 'contact') AND first_fail_at < ?")
                ->execute([date('Y-m-d H:i:s', time() - 86400)]);
        }
        $pdo->prepare('DELETE FROM auth_throttle WHERE scope = ? AND scope_key = ? AND first_fail_at < ?')
            ->execute([$scope, $key, date('Y-m-d H:i:s', time() - $windowSec)]);
        $pdo->prepare(DB::driver() === 'pgsql'
            ? 'INSERT INTO auth_throttle (scope, scope_key, fail_count, first_fail_at) VALUES (?, ?, 1, ?)
               ON CONFLICT (scope, scope_key) DO UPDATE SET fail_count = auth_throttle.fail_count + 1'
            : 'INSERT INTO auth_throttle (scope, scope_key, fail_count, first_fail_at) VALUES (?, ?, 1, ?)
               ON DUPLICATE KEY UPDATE fail_count = fail_count + 1'
        )->execute([$scope, $key, $now]);
        $stmt = $pdo->prepare('SELECT fail_count FROM auth_throttle WHERE scope = ? AND scope_key = ?');
        $stmt->execute([$scope, $key]);
        return (int)$stmt->fetchColumn();
    }
}
