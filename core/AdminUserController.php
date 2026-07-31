<?php
/**
 * HayabusaPress - Staff user, profile, avatar, and 2FA management controller.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class AdminUserController
{
    public static function list(): array
    {
        return DB::fetchAll(
            "SELECT id, name, email, role, two_factor_secret, created_at FROM users ORDER BY created_at ASC"
        );
    }

    /** Allow only http(s) absolute URLs; normalize invalid/empty to '' (excludes javascript: etc. on href output). */
    private static function sanitizeUrl(string $url): string
    {
        if ($url === '') return '';
        if (!preg_match('#^https?://#i', $url)) return '';
        if (filter_var($url, FILTER_VALIDATE_URL) === false) return '';
        return mb_substr($url, 0, 255);
    }

    public static function create(array $input): void
    {
        Gate::authorize('user:manage');

        $email    = trim($input['email'] ?? '');
        $name     = trim($input['name']  ?? '');
        $password = $input['password'] ?? '';
        $role     = $input['role'] ?? 'author';

        if ($email === '' || $name === '' || $password === '') {
            throw new InvalidArgumentException(HbpLocale::t('err_user_fields_required', 'Email, name, and password are required.'));
        }
        // Truncate to the schema limit (users.email / users.name VARCHAR(255))
        $email = mb_substr($email, 0, 255);
        $name  = mb_substr($name,  0, 255);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(HbpLocale::t('err_email_invalid', 'The email address format is invalid.'));
        }
        if (!in_array($role, ['administrator', 'editor', 'author', 'contributor'], true)) {
            throw new InvalidArgumentException(HbpLocale::t('err_role_invalid', 'Invalid role.'));
        }
        self::assertPasswordStrength($password);

        $exists = DB::fetchOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($exists) throw new RuntimeException(HbpLocale::t('err_email_taken', 'This email address is already in use.'));

        DB::execute(
            'INSERT INTO users (email, password_hash, name, role, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$email, Hash::make($password), $name, $role]
        );
    }

    public static function update(int $userId, array $input): void
    {
        // Only the user's own profile, or holders of the user:manage permission
        if (Auth::id() !== $userId && Gate::cannot('user:manage')) {
            http_response_code(403);
            exit('403 Forbidden');
        }

        $name      = trim($input['name']      ?? '');
        $biography = trim($input['biography'] ?? '');
        // SNS is output as href on the public author profile, so allow only http(s) (exclude javascript: etc.); normalize invalid/empty to empty
        $snsX      = self::sanitizeUrl(trim($input['sns_x']     ?? ''));
        $snsGh     = self::sanitizeUrl(trim($input['sns_github'] ?? ''));
        $snsLinks  = json_encode(['x' => $snsX, 'github' => $snsGh], JSON_UNESCAPED_UNICODE);
        $locale    = trim($input['locale'] ?? 'en');
        if (!preg_match('/^[a-z]{2,5}$/', $locale)) $locale = 'en';

        if ($name === '') throw new InvalidArgumentException(HbpLocale::t('err_name_required', 'Name is required.'));
        // Truncate to the schema limit (users.name VARCHAR(255))
        $name = mb_substr($name, 0, 255);

        DB::execute(
            'UPDATE users SET name=?, biography=?, sns_links=?, locale=? WHERE id=?',
            [$name, $biography, $snsLinks, $locale, $userId]
        );

        // Password change
        if (!empty($input['new_password'])) {
            self::assertPasswordStrength((string)$input['new_password']);
            DB::execute(
                'UPDATE users SET password_hash=? WHERE id=?',
                [Hash::make($input['new_password']), $userId]
            );
        }

        // Role change (administrators only)
        if (isset($input['role']) && Gate::can('user:manage')) {
            $role = $input['role'];
            if (in_array($role, ['administrator', 'editor', 'author', 'contributor'], true)) {
                DB::execute('UPDATE users SET role=? WHERE id=?', [$role, $userId]);
            }
        }

        // Avatar upload
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            self::uploadAvatar($userId, $_FILES['avatar']);
        }

        // on profile change, regenerate the author singleton + purge the relevant posts' FPC
        if (class_exists('AuthorCompiler')) {
            AuthorCompiler::rebuild();
        }
        if (class_exists('CacheManager')) {
            CacheManager::purgeAuthor($userId);
        }
    }

    public static function delete(int $userId): void
    {
        Gate::authorize('user:manage');
        if (Auth::id() === $userId) {
            throw new RuntimeException(HbpLocale::t('err_cannot_delete_self', 'You cannot delete yourself.'));
        }

        // due to ON DELETE RESTRICT, staff who own posts must be reassigned before deletion
        $postCount = DB::fetchOne('SELECT COUNT(*) AS cnt FROM posts WHERE user_id = ?', [$userId]);
        if ((int)($postCount['cnt'] ?? 0) > 0) {
            throw new RuntimeException(
                HbpLocale::t('err_user_has_posts', 'This user has posts assigned. Before deleting, reassign the posts to another user, or change them to the Super User (user_id=NULL).')
            );
        }

        DB::execute('DELETE FROM users WHERE id=?', [$userId]);

        // after deletion, regenerate the author singleton (remove the deleted user's entry)
        if (class_exists('AuthorCompiler')) {
            AuthorCompiler::rebuild();
        }
    }

    /** Super User force-controls another staff member's 2FA (callable only by an authenticated Super User) */
    public static function manageStaffTwoFactor(int $targetUserId, string $action): string
    {
        // Strict Super-User-only check
        if ($_SESSION['user_id'] !== 0) {
            http_response_code(403);
            exit('403 Forbidden');
        }

        if ($action === 'force_off') {
            DB::execute('UPDATE users SET two_factor_secret=NULL WHERE id=?', [$targetUserId]);
            return HbpLocale::t('msg_2fa_disabled', 'Two-factor authentication has been disabled.');
        }

        if ($action === 'force_on') {
            $secret    = TwoFactorAuth::generateSecret();
            $encrypted = class_exists('Crypto') ? Crypto::encrypt($secret) : $secret;
            DB::execute('UPDATE users SET two_factor_secret=? WHERE id=?', [$encrypted, $targetUserId]);
            return HbpLocale::t('msg_2fa_enabled_secret', 'Two-factor authentication has been enabled. Share this initial secret with the staff member securely: ') . $secret;
        }

        throw new InvalidArgumentException(HbpLocale::t('err_action_invalid', 'Invalid action.'));
    }

    /** Enable 2FA from the profile screen */
    public static function enableTwoFactor(int $userId, string $secret, string $code): void
    {
        if (!TwoFactorAuth::verifyCode($secret, $code)) {
            throw new RuntimeException(HbpLocale::t('err_code_invalid', 'The code is incorrect.'));
        }
        // encrypt with Crypto at save time
        $encrypted = class_exists('Crypto') ? Crypto::encrypt($secret) : $secret;
        DB::execute('UPDATE users SET two_factor_secret=? WHERE id=?', [$encrypted, $userId]);
    }

    /** Disable 2FA from the profile screen */
    public static function disableTwoFactor(int $userId): void
    {
        if (Auth::id() !== $userId) {
            http_response_code(403);
            exit('403 Forbidden');
        }
        DB::execute('UPDATE users SET two_factor_secret=NULL WHERE id=?', [$userId]);
    }

    /**
     * save the avatar image with the same preprocessing as body images and return the generated filename.
     * Called from the canonical route shared by regular users and the Super User (the dedicated avatar API / profile form).
     * Extension allowlist + finfo MIME validation -> GD re-encode to strip all metadata including EXIF (required)
     * -> hash name (same as body images: bin2hex(random_bytes(16))) -> ensureDir() + shardPath() to save to
     * public/assets/site/avatars/{ab}/{cd}/{file}. Environments without GD are rejected (same policy as).
     * Touches neither the DB nor .env (only saving and filename generation).
     */
    public static function processAvatarUpload(array $file): string
    {
        // Accept only genuine HTTP uploads (do not pass local-path spoofing to finfo/GD; defense in depth)
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException(HbpLocale::t('err_invalid_upload', 'Invalid upload.'));
        }

        $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $allowedExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        $ext   = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);

        if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
            throw new RuntimeException(HbpLocale::t('err_image_type_not_allowed', 'This image format is not allowed.'));
        }

        ///GD required. Do not create a path that cannot guarantee EXIF/metadata removal (closes the manual direct-upload hole).
        if (!extension_loaded('gd')) {
            throw new RuntimeException(
                HbpLocale::t('err_gd_required_avatar', 'Avatar upload is unavailable because the PHP GD extension is disabled (EXIF/metadata removal cannot be guaranteed). Please ask your server administrator to enable gd.')
            );
        }

        // Hash name (same as body images). shard placement ensureDir.
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest     = Media::avatarPath($filename);
        Media::ensureDirForFile($dest);

        // GD re-encode to strip all metadata including EXIF (+ neutralize image-spoofing payloads)
        AdminPostController::reencodeImage($file['tmp_name'], $dest, $mime);
        @unlink($file['tmp_name']);

        return $filename;
    }

    /**
     * replace the avatar of a regular user (with a DB row).
     * Physically delete the old avatar (prefer shard, fall back to legacy flat) -> update avatar_filename
     * -> regenerate the author singleton + purge the relevant posts' FPC.
     */
    public static function setUserAvatar(int $userId, string $filename): void
    {
        $old = DB::fetchOne('SELECT avatar_filename FROM users WHERE id=? LIMIT 1', [$userId]);
        if ($old && $old['avatar_filename']) {
            $oldFn = basename((string)$old['avatar_filename']);
            foreach ([Media::avatarPath($oldFn), Media::avatarsBase() . '/' . $oldFn] as $oldPath) {
                if (is_file($oldPath)) @unlink($oldPath);
            }
        }
        DB::execute('UPDATE users SET avatar_filename=? WHERE id=?', [basename($filename), $userId]);

        if (class_exists('AuthorCompiler')) {
            AuthorCompiler::rebuild();
        }
        if (class_exists('CacheManager')) {
            CacheManager::purgeAuthor($userId);
        }
    }

    /** Avatar upload via the profile form (shares the same canonical route as the dedicated API). */
    private static function uploadAvatar(int $userId, array $file): void
    {
        $filename = self::processAvatarUpload($file);
        self::setUserAvatar($userId, $filename);
    }

    /** Minimum staff password length. Short enough not to annoy, long enough to matter. */
    public const PASSWORD_MIN = 12;

    /**
     * Reject passwords that are too short.
     *
     * There was no length check at all, so a single character was accepted for an account that can
     * reach the admin panel. bcrypt also silently ignores anything past 72 bytes, so cap the input
     * rather than letting the user believe a longer passphrase is fully used.
     */
    private static function assertPasswordStrength(string $password): void
    {
        $len = mb_strlen($password);
        if ($len < self::PASSWORD_MIN) {
            throw new InvalidArgumentException(HbpLocale::t(
                'valid_min_length', ':field must be at least :min characters.',
                ['field' => HbpLocale::t('field_password', 'Password'), 'min' => self::PASSWORD_MIN]
            ));
        }
        if (strlen($password) > 72) {
            throw new InvalidArgumentException(HbpLocale::t(
                'valid_max_length', ':field must be :max characters or fewer.',
                ['field' => HbpLocale::t('field_password', 'Password'), 'max' => 72]
            ));
        }
    }
}
