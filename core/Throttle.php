<?php
/**
 * HayabusaPress - Authentication throttling (exponential backoff / lockout).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Section 24.2: authentication throttling (exponential backoff / lockout).
 * Counts on both the IP axis and the account axis in the auth_throttle table.
 */
class Throttle
{
    private const WINDOW_SEC     = 900;
    private const ACCOUNT_LIMIT  = 5;
    private const IP_LIMIT       = 30;
    private const MAX_LOCK_SEC   = 3600;

    /**
     * Call before a login attempt.
     * If locked, return 429 and exit (for API use).
     * For the UI, use isAllowed() instead.
     */
    public static function assertAllowed(string $email, string $ip): void
    {
        if (!self::isAllowed($email, $ip)) {
            http_response_code(429);
            exit('Too Many Requests');
        }
    }

    /**
     * Return the lock state as a bool (for showing an error on the login form).
     * true = access allowed / false = locked
     */
    public static function isAllowed(string $email, string $ip): bool
    {
        return !self::isLocked('id', self::emailKey($email))
            && !self::isLocked('ip', $ip);
    }

    /**
     * Return the per-2FA-session lock state.
     * Determined by scope='2fa', key=session_id.
     * true = still allowed / false = locked (the provisional auth should be discarded)
     */
    public static function is2faAllowed(string $sessionId): bool
    {
        return !self::isLocked('2fa', substr($sessionId, 0, 64));
    }

    /**
     * Return the seconds remaining until unlock (for UI display). 0 = not locked.
     */
    public static function secondsUntilUnlock(string $email, string $ip): int
    {
        try {
            $pdo = DB::getInstance();
            $now = date('Y-m-d H:i:s');
            foreach ([['id', self::emailKey($email)], ['ip', $ip]] as [$scope, $key]) {
                $row = $pdo->prepare('SELECT locked_until FROM auth_throttle WHERE scope=? AND scope_key=? LIMIT 1');
                $row->execute([$scope, $key]);
                $data = $row->fetch(PDO::FETCH_ASSOC);
                if ($data && $data['locked_until'] !== null) {
                    $remaining = strtotime($data['locked_until']) - time();
                    if ($remaining > 0) {
                        return $remaining;
                    }
                }
            }
        } catch (Throwable $e) {
            self::warnDegraded('secondsUntilUnlock', $e);
        }
        return 0;
    }

    /** Record a failed login. */
    public static function registerFailure(string $email, string $ip): void
    {
        self::record('id', self::emailKey($email), self::ACCOUNT_LIMIT);
        self::record('ip', $ip,                    self::IP_LIMIT);
    }

    /** Reset the counter on successful login. */
    public static function clear(string $email, string $ip): void
    {
        try {
            $pdo = DB::getInstance();
            $pdo->prepare('DELETE FROM auth_throttle WHERE scope=? AND scope_key=?')
                ->execute(['id', self::emailKey($email)]);
            $pdo->prepare('DELETE FROM auth_throttle WHERE scope=? AND scope_key=?')
                ->execute(['ip', $ip]);
        } catch (Throwable $e) {
            self::warnDegraded('clear', $e);
        }
    }

    /** Record a 2FA failure (scope='2fa', key=session ID). */
    public static function record2faFailure(string $sessionId): void
    {
        self::record('2fa', substr($sessionId, 0, 64), 5);
    }

    private static function emailKey(string $email): string
    {
        $appKey = Env::get('APP_KEY', '');
        return hash_hmac('sha256', $email, $appKey ?: $email);
    }

    private static function isLocked(string $scope, string $key): bool
    {
        try {
            $pdo  = DB::getInstance();
            $row  = $pdo->prepare('SELECT locked_until FROM auth_throttle WHERE scope=? AND scope_key=? LIMIT 1');
            $row->execute([$scope, $key]);
            $data = $row->fetch(PDO::FETCH_ASSOC);
            if ($data && $data['locked_until'] !== null) {
                return strtotime($data['locked_until']) > time();
            }
        } catch (Throwable $e) {
            // Fail open (treat as unlocked). This silently disables brute-force protection,
            // so it must never be invisible -- see warnDegraded().
            self::warnDegraded('isLocked', $e);
        }
        return false;
    }

    private static function record(string $scope, string $key, int $limit): void
    {
        try {
            $pdo    = DB::getInstance();
            $driver = DB::driver();
            $now    = date('Y-m-d H:i:s');

            // Reset old windows (delete expired records)
            $reset = date('Y-m-d H:i:s', time() - self::WINDOW_SEC);
            $pdo->prepare(
                'DELETE FROM auth_throttle WHERE scope=? AND scope_key=? AND first_fail_at < ?'
            )->execute([$scope, $key, $reset]);

            // Atomic UPSERT (MySQL: ON DUPLICATE KEY / PostgreSQL: ON CONFLICT)
            if ($driver === 'pgsql') {
                $pdo->prepare(
                    'INSERT INTO auth_throttle (scope, scope_key, fail_count, first_fail_at)
                     VALUES (?, ?, 1, ?)
                     ON CONFLICT (scope, scope_key)
                     DO UPDATE SET fail_count = auth_throttle.fail_count + 1'
                )->execute([$scope, $key, $now]);
            } else {
                $pdo->prepare(
                    'INSERT INTO auth_throttle (scope, scope_key, fail_count, first_fail_at)
                     VALUES (?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE fail_count = fail_count + 1'
                )->execute([$scope, $key, $now]);
            }

            // Fetch the latest count and decide the lock
            $row = $pdo->prepare('SELECT fail_count FROM auth_throttle WHERE scope=? AND scope_key=? LIMIT 1');
            $row->execute([$scope, $key]);
            $data  = $row->fetch(PDO::FETCH_ASSOC);
            $count = (int)($data['fail_count'] ?? 1);

            // Lock when the threshold is exceeded (exponential backoff, capped at 3600 seconds)
            if ($count >= $limit) {
                $excess    = max(0, $count - $limit);
                $lockSec   = min(60 * (int)(2 ** $excess), self::MAX_LOCK_SEC);
                $lockUntil = date('Y-m-d H:i:s', time() + $lockSec);
                $pdo->prepare(
                    'UPDATE auth_throttle SET locked_until=? WHERE scope=? AND scope_key=?'
                )->execute([$lockUntil, $scope, $key]);
            }
        } catch (Throwable $e) {
            // Fail open on DB error (do not lock)
            self::warnDegraded('record', $e);
        }
    }

    /**
     * Report that throttling is degraded.
     *
     * Every DB failure in this class fails open: no lockout is applied. Super User login is
     * authenticated from .env alone and needs no database, so a broken/missing auth_throttle
     * table would otherwise leave the highest-privilege account with unlimited password
     * attempts and no signal at all. Logging is the only thing that makes that state visible.
     *
     * Logged once per request per operation to keep a hard-down database from flooding the log.
     */
    private static function warnDegraded(string $op, Throwable $e): void
    {
        static $seen = [];
        if (isset($seen[$op])) {
            return;
        }
        $seen[$op] = true;
        error_log(
            '[HBP SECURITY] Auth throttling is DEGRADED (failing open, no lockout enforced) at '
            . $op . '(): ' . $e->getMessage()
            . ' -- check the auth_throttle table and the database connection.'
        );
    }
}
