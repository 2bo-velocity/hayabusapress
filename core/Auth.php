<?php
/**
 * HayabusaPress - Super-User-first authentication (throttling, timing-safe, 2FA).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class Auth
{
    private static string $DUMMY_HASH = '$2y$10$abcdefghijklmnopqrstuuGOLEYpFO9BqYBxnlnWEBPRBt3R9hkGq';

    /**
     * Login handling. Checks the Super User first (no DB access).
     * Returns true on success, '2fa' when 2FA is required, false on failure.
     *
     * Throttle throttling + timing-attack mitigation + Crypto decryption
     */
    public static function login(string $email, string $password): bool|string
    {
        // Timing-attack mitigation: always validate the input
        if ($email === '' || $password === '') {
            Hash::verify('dummy', self::$DUMMY_HASH); // Consume constant time
            return false;
        }

        $ip = class_exists('AccessControl') ? AccessControl::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '');

        // Throttle check
        if (class_exists('Throttle')) {
            Throttle::assertAllowed($email, $ip);
        }

        // (1) Super User check (no DB access)
        $superEmail = Env::get('SUPER_USER_EMAIL', '');
        $superHash  = Env::get('SUPER_USER_PASSWORD_HASH', '');

        if ($superEmail !== '' && $superHash !== '' && $email === $superEmail) {
            if (!Hash::verify($password, $superHash)) {
                if (class_exists('Throttle')) {
                    Throttle::registerFailure($email, $ip);
                }
                return false;
            }
            if (class_exists('Throttle')) {
                Throttle::clear($email, $ip);
            }

            // 2FA check
            $twoFaSecret  = Env::get('SUPER_USER_2FA_SECRET', '');
            $twoFaCache   = STORAGE_PATH . '/cache/two_factor_global.cache.php';
            $twoFaEnabled = false;
            if ($twoFaSecret !== '' && file_exists($twoFaCache)) {
                $cfg = require $twoFaCache;
                $twoFaEnabled = (bool)($cfg['super_user_2fa_enabled'] ?? false);
            }

            if ($twoFaEnabled) {
                $_SESSION['auth_step']          = 1;
                $_SESSION['auth_temp_user_id']  = 0;
                $_SESSION['auth_temp_name']     = Env::get('SUPER_USER_NAME', 'Super User');
                $_SESSION['auth_temp_role']     = 'super_user';
                $_SESSION['auth_temp_email']    = $superEmail;
                $_SESSION['auth_temp_locale']   = Env::get('SUPER_USER_LOCALE', 'en');
                return '2fa';
            }

            self::establishSession(
                0,
                Env::get('SUPER_USER_NAME', 'Super User'),
                'super_user',
                $superEmail,
                Env::get('SUPER_USER_LOCALE', 'en')
            );
            return true;
        }

        // (2) Regular staff DB lookup
        $user = DB::fetchOne(
            'SELECT id, name, role, email, password_hash, two_factor_secret, locale FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        // Timing-attack mitigation: verify a dummy hash even when the user does not exist
        if ($user === false) {
            Hash::verify($password, self::$DUMMY_HASH);
            if (class_exists('Throttle')) {
                Throttle::registerFailure($email, $ip);
            }
            return false;
        }

        if (!Hash::verify($password, $user['password_hash'])) {
            if (class_exists('Throttle')) {
                Throttle::registerFailure($email, $ip);
            }
            return false;
        }

        if (class_exists('Throttle')) {
            Throttle::clear($email, $ip);
        }

        // Decrypt the 2FA secret (O-series: supports encrypted storage)
        $rawSecret = $user['two_factor_secret'] ?? '';
        if ($rawSecret !== '' && class_exists('Crypto')) {
            try {
                $rawSecret = Crypto::decrypt($rawSecret);
            } catch (Throwable) {
                $rawSecret = $user['two_factor_secret'];
            }
        }

        if ($rawSecret !== '') {
            $_SESSION['auth_step']          = 1;
            $_SESSION['auth_temp_user_id']  = (int)$user['id'];
            $_SESSION['auth_temp_name']     = $user['name'];
            $_SESSION['auth_temp_role']     = $user['role'];
            $_SESSION['auth_temp_email']    = $user['email'];
            $_SESSION['auth_temp_locale']   = $user['locale'] ?? 'en';
            $_SESSION['auth_temp_secret']   = $rawSecret; // Keep the decrypted secret in the session
            return '2fa';
        }

        self::establishSession((int)$user['id'], $user['name'], $user['role'], $user['email'], $user['locale'] ?? 'en');
        return true;
    }

    /** Verify the 2FA code -> promote to full login */
    public static function completeTwoFactor(string $code): bool
    {
        if (($_SESSION['auth_step'] ?? 0) !== 1) {
            return false;
        }

        $userId = $_SESSION['auth_temp_user_id'];
        $secret = '';

        if ($userId === 0) {
            $secret = Env::get('SUPER_USER_2FA_SECRET', '');
        } else {
            // Use the decrypted secret from the session if present
            $secret = $_SESSION['auth_temp_secret'] ?? '';
            if ($secret === '') {
                $user   = DB::fetchOne('SELECT two_factor_secret FROM users WHERE id = ? LIMIT 1', [$userId]);
                $raw    = $user['two_factor_secret'] ?? '';
                // Even if decryption fails due to a lost/rotated APP_KEY, do not fall to a 500;
                // handle the ciphertext as-is (the same fail-safe as login()). Verification fails later.
                if (class_exists('Crypto') && $raw !== '') {
                    try {
                        $secret = Crypto::decrypt($raw);
                    } catch (Throwable) {
                        $secret = $raw;
                    }
                } else {
                    $secret = $raw;
                }
            }
        }

        if ($secret === '') {
            return false;
        }

        // Constant-time comparison via hash_equals
        $step = TwoFactorAuth::matchStep($secret, $code);
        if ($step === null) {
            // Record the 2FA failure in Throttle
            if (class_exists('Throttle')) {
                Throttle::record2faFailure(session_id());

                // Section 24.2.7: discard the provisional auth session after 5 failures (90-second window x 5 attempts)
                if (!Throttle::is2faAllowed(session_id())) {
                    // Fully destroy the provisional auth session
                    unset(
                        $_SESSION['auth_step'],
                        $_SESSION['auth_temp_user_id'],
                        $_SESSION['auth_temp_name'],
                        $_SESSION['auth_temp_role'],
                        $_SESSION['auth_temp_email'],
                        $_SESSION['auth_temp_locale'],
                        $_SESSION['auth_temp_secret']
                    );
                }
            }
            return false;
        }

        // Replay prevention (RFC 6238): a step at or before the last one used is refused
        if (!self::consumeStep($userId, $step)) {
            return false;
        }

        self::establishSession(
            $userId,
            $_SESSION['auth_temp_name'],
            $_SESSION['auth_temp_role'],
            $_SESSION['auth_temp_email'],
            $_SESSION['auth_temp_locale'] ?? 'en'
        );
        return true;
    }

    /** Session-fixation defense + establish the session */
    private static function establishSession(int $id, string $name, string $role, string $email, string $locale = 'en'): void
    {
        session_regenerate_id(true);

        unset(
            $_SESSION['auth_step'],
            $_SESSION['auth_temp_user_id'],
            $_SESSION['auth_temp_name'],
            $_SESSION['auth_temp_role'],
            $_SESSION['auth_temp_email'],
            $_SESSION['auth_temp_locale'],
            $_SESSION['auth_temp_secret']
        );

        $_SESSION['logged_in']    = true;
        $_SESSION['user_id']      = $id;
        $_SESSION['user_name']    = $name;
        $_SESSION['user_role']    = $role;
        $_SESSION['user_email']   = $email;
        $_SESSION['user_locale']  = $locale;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['logged_in']);
    }

    public static function id(): int
    {
        return (int)($_SESSION['user_id'] ?? -1);
    }

    public static function user(): array
    {
        return [
            'id'    => self::id(),
            'name'  => $_SESSION['user_name']  ?? '',
            'role'  => $_SESSION['user_role']  ?? '',
            'email' => $_SESSION['user_email'] ?? '',
        ];
    }

    public static function isSuperUser(): bool
    {
        return self::id() === 0;
    }

    /** How long a confirmed password stays good for the operations that ask for it (seconds). */
    public const REAUTH_TTL = 600;

    /**
     * Whether the signed-in user confirmed their password within REAUTH_TTL.
     * Asked for before an admin-panel operation that changes code or the schema (plugin / theme
     * installation and deletion): it limits what a stolen session alone can do.
     */
    public static function reauthValid(): bool
    {
        return self::check()
            && ($_SESSION['reauth_uid'] ?? null) === self::id()
            && time() - (int)($_SESSION['reauth_at'] ?? 0) < self::REAUTH_TTL;
    }

    /** Seconds left before the confirmation has to be repeated (0 = not confirmed). */
    public static function reauthRemaining(): int
    {
        return self::reauthValid() ? self::REAUTH_TTL - (time() - (int)$_SESSION['reauth_at']) : 0;
    }

    /** Whether confirming asks for an authentication code too (the user has 2FA on). */
    public static function reauthNeedsCode(): bool
    {
        return self::twoFactorSecretFor(self::id()) !== '';
    }

    /**
     * Confirm the signed-in user's password (and authentication code when they use 2FA).
     * Counted by the same Throttle as login, so it cannot be used to guess the password.
     *
     * @return string|null null when confirmed, otherwise the message to show
     */
    public static function reauthenticate(string $password, string $code = ''): ?string
    {
        if (!self::check()) {
            return HbpLocale::t('err_reauth_failed', 'The password or authentication code is incorrect.');
        }
        $id    = self::id();
        $email = (string)($_SESSION['user_email'] ?? '');
        $ip    = class_exists('AccessControl') ? AccessControl::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '');

        if (class_exists('Throttle') && !Throttle::isAllowed($email, $ip)) {
            return HbpLocale::t('err_reauth_locked', 'Too many failed attempts. Try again in :seconds seconds.',
                ['seconds' => max(1, Throttle::secondsUntilUnlock($email, $ip))]);
        }

        if ($id === 0) {
            $hash = Env::get('SUPER_USER_PASSWORD_HASH', '');
        } else {
            $row  = DB::fetchOne('SELECT password_hash FROM users WHERE id = ? LIMIT 1', [$id]);
            $hash = (string)($row['password_hash'] ?? '');
        }
        if ($hash === '') {
            Hash::verify($password, self::$DUMMY_HASH); // Consume constant time
            $ok = false;
        } else {
            $ok = Hash::verify($password, $hash);
        }

        if ($ok) {
            $secret = self::twoFactorSecretFor($id);
            if ($secret !== '') {
                $step = preg_match('/^\d{6}$/', $code) === 1 ? TwoFactorAuth::matchStep($secret, $code) : null;
                $ok   = $step !== null && self::consumeStep($id, $step);
            }
        }

        if (!$ok) {
            if (class_exists('Throttle')) {
                Throttle::registerFailure($email, $ip);
            }
            return HbpLocale::t('err_reauth_failed', 'The password or authentication code is incorrect.');
        }

        if (class_exists('Throttle')) {
            Throttle::clear($email, $ip);
        }
        $_SESSION['reauth_uid'] = $id;
        $_SESSION['reauth_at']  = time();
        return null;
    }

    /**
     * The decrypted TOTP secret of a user with 2FA on, or '' (the same rules as login()).
     * The Super User's 2FA counts only while two_factor_global.cache.php has it switched on.
     */
    private static function twoFactorSecretFor(int $id): string
    {
        if ($id === 0) {
            $secret = Env::get('SUPER_USER_2FA_SECRET', '');
            $cache  = STORAGE_PATH . '/cache/two_factor_global.cache.php';
            if ($secret === '' || !is_file($cache)) {
                return '';
            }
            $cfg = require $cache;
            return !empty($cfg['super_user_2fa_enabled']) ? $secret : '';
        }
        $row = DB::fetchOne('SELECT two_factor_secret FROM users WHERE id = ? LIMIT 1', [$id]);
        $raw = (string)($row['two_factor_secret'] ?? '');
        if ($raw !== '' && class_exists('Crypto')) {
            try {
                return Crypto::decrypt($raw);
            } catch (Throwable) {
                return $raw;
            }
        }
        return $raw;
    }

    /**
     * Refuse a code whose time-step is not newer than the last one used (RFC 6238 replay
     * prevention), and record it. $step is the step the code matched, not the current one.
     * Staff: one conditional UPDATE, so two requests carrying the same code cannot both pass.
     * Super User (no row): a small file under storage/auth/, under an exclusive lock.
     */
    private static function consumeStep(int $id, int $step): bool
    {
        if ($id !== 0) {
            return DB::execute(
                'UPDATE users SET tfa_last_step = ? WHERE id = ? AND (tfa_last_step IS NULL OR tfa_last_step < ?)',
                [$step, $id, $step]
            ) === 1;
        }

        // Failing to record is let through: 2FA itself was verified, and refusing would lock the
        // Super User out on a site whose storage/ is not writable.
        $dir = STORAGE_PATH . '/auth';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            error_log('[HBP] Cannot create ' . $dir . '; Super User 2FA replay prevention is off.');
            return true;
        }
        $fp = @fopen($dir . '/super_user_tfa.step', 'c+');
        if ($fp === false || !flock($fp, LOCK_EX)) {
            error_log('[HBP] Cannot write ' . $dir . '/super_user_tfa.step; Super User 2FA replay prevention is off.');
            if ($fp !== false) {
                fclose($fp);
            }
            return true;
        }
        $last = (int)trim((string)stream_get_contents($fp));
        $ok   = $step > $last;
        if ($ok) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string)$step);
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        return $ok;
    }

    /** Redirect when not logged in */
    public static function requireLogin(string $adminPath): void
    {
        if (!self::check()) {
            header('Location: /' . ltrim($adminPath, '/') . '/login');
            exit;
        }
        // REQUIRE_ADMIN_2FA: when 2FA is not set, force-redirect to the profile screen
        if (Env::bool('REQUIRE_ADMIN_2FA', false) && !self::isSuperUser()) {
            $userId = self::id();
            $user   = DB::fetchOne('SELECT two_factor_secret FROM users WHERE id = ? LIMIT 1', [$userId]);
            if ($user && empty($user['two_factor_secret'])) {
                header('Location: /' . ltrim($adminPath, '/') . '/profile?require_2fa=1');
                exit;
            }
        }
    }
}
