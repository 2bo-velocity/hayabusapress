<?php
/**
 * HayabusaPress - CSRF token generation and validation helper.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Lightweight security helper
 *
 * Handles only CSRF token generation/validation. Zero external dependencies.
 */
class Security
{
    private const TOKEN_KEY = 'hbp_csrf_token';

    /** Generate a CSRF token, save it to the session, and return it */
    public static function generateCsrfToken(): string
    {
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * Soft-validate a CSRF token (true = match / false = mismatch).
     * Used on paths like login/2FA where we want to redraw the screen with an error rather than exit with 403.
     */
    public static function checkCsrfToken(string $token): bool
    {
        $stored = $_SESSION[self::TOKEN_KEY] ?? '';
        // Do not let an empty token through to an ungenerated session (stored='')
        return $stored !== '' && hash_equals($stored, $token);
    }

    /** Validate the CSRF token. On mismatch, exit immediately with 403 */
    public static function verifyCsrfToken(string $token): void
    {
        $stored = $_SESSION[self::TOKEN_KEY] ?? '';
        if (!hash_equals($stored, $token)) {
            http_response_code(403);
            exit('403 CSRF token mismatch.');
        }
    }

    /**
     * Return the hidden input field for the CSRF token
     * Use in templates as echo Security::csrfField();
     */
    public static function csrfField(): string
    {
        $token = self::generateCsrfToken();
        $token = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }

    /**
     * Shortcut to validate the CSRF token of a POST request
     * Called at the start of every admin POST handler
     */
    public static function verifyPostRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::verifyCsrfToken($_POST['_csrf_token'] ?? '');
        }
    }
}
