<?php
/**
 * HayabusaPress - Native TOTP implementation (RFC 6238).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Native TOTP implementation (RFC 6238 compliant)
 * Uses only hash_hmac / bitwise ops / pack(). Zero external dependencies.
 */
class TwoFactorAuth
{
    private const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Randomly generate a 16-character Base32 secret */
    public static function generateSecret(): string
    {
        $secret = '';
        for ($i = 0; $i < 16; $i++) {
            $secret .= self::BASE32_CHARS[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Verify a 6-digit code (allows a ±30-second time window).
     * Constant-time comparison via hash_equals (timing-attack mitigation).
     */
    public static function verifyCode(string $secret, string $code): bool
    {
        return self::matchStep($secret, $code) !== null;
    }

    /**
     * The time-step the code belongs to, or null when it matches none in the ±30-second window.
     * Replay prevention must compare this step, not the current one: a code from step t is still
     * accepted during step t+1.
     */
    public static function matchStep(string $secret, string $code): ?int
    {
        if (!ctype_digit($code) || strlen($code) !== 6) {
            return null;
        }
        $timestamp = (int)floor(time() / 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals(self::generateCode($secret, $timestamp + $offset), $code)) {
                return $timestamp + $offset;
            }
        }
        return null;
    }

    /**
     * Return the current time-step (for replay prevention).
     * Compared against users.tfa_last_step; rejected if equal or earlier.
     */
    public static function currentTimeStep(): int
    {
        return (int)floor(time() / 30);
    }

    /** Generate a 6-digit code from a timestamp */
    private static function generateCode(string $secret, int $timestamp): string
    {
        $key  = self::base32Decode($secret);
        $time = pack('N*', 0) . pack('N*', $timestamp);
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $code   = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % 1_000_000;
        return str_pad((string)$code, 6, '0', STR_PAD_LEFT);
    }

    /** Base32 decode */
    private static function base32Decode(string $secret): string
    {
        $secret  = strtoupper($secret);
        $chars   = self::BASE32_CHARS;
        $binary  = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos($chars, $char);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $chunks = str_split($binary, 8);
        $decoded = '';
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }
        return $decoded;
    }

    /** Generate the Google Authenticator registration URI from a secret */
    public static function getQrUri(string $secret, string $email, string $issuer = 'HBP'): string
    {
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
        ]);
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $email) . '?' . $params;
    }
}
