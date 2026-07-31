<?php
/**
 * HayabusaPress - APP_KEY-based symmetric encryption helper (sodium / OpenSSL).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * O-series: APP_KEY-based symmetric encryption helper.
 * Uses XSalsa20-Poly1305 when ext-sodium is available, otherwise OpenSSL AES-256-GCM.
 * Used to encrypt 2FA secrets at rest.
 */
class Crypto
{
    private const SODIUM_PREFIX  = '$na$';
    private const OPENSSL_PREFIX = '$oc$';
    private const CIPHER         = 'aes-256-gcm';

    /**
     * Derive a 32-byte encryption key from APP_KEY.
     * APP_KEY format: "base64:..." or a raw base64 string.
     */
    private static function deriveKey(): string
    {
        $appKey = Env::get('APP_KEY', '');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7), true) ?: $appKey;
        }
        if (strlen($appKey) < 1) {
            throw new RuntimeException(self::msg('err_app_key_missing',
                'APP_KEY is not set. Generate one with: php core/cli.php tool:key'));
        }
        return hash('sha256', $appKey, true);
    }

    /**
     * Encrypt plaintext and return a portable string.
     * When APP_KEY is unset, returns the plaintext as-is (for backward compatibility).
     */
    public static function encrypt(string $plaintext): string
    {
        $appKey = Env::get('APP_KEY', '');
        if ($appKey === '') {
            return $plaintext;
        }

        $key = self::deriveKey();

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce      = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);
            return self::SODIUM_PREFIX . base64_encode($nonce . $ciphertext);
        }

        // OpenSSL fallback
        $iv         = random_bytes(12);
        $tag        = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($ciphertext === false) {
            throw new RuntimeException(self::msg('err_encrypt_failed', 'Encryption failed.'));
        }
        return self::OPENSSL_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt an encrypted string.
     * When there is no prefix (plaintext), returns it as-is (backward compatibility).
     */
    public static function decrypt(string $ciphertext): string
    {
        if (str_starts_with($ciphertext, self::SODIUM_PREFIX)) {
            $key  = self::deriveKey();
            $data = base64_decode(substr($ciphertext, strlen(self::SODIUM_PREFIX)), true);
            if ($data === false) {
                throw new RuntimeException(self::msg('err_decrypt_malformed', 'The encrypted data is malformed.'));
            }
            $nonce  = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain  = sodium_crypto_secretbox_open($cipher, $nonce, $key);
            if ($plain === false) {
                throw new RuntimeException(self::msg('err_decrypt_failed', 'Decryption failed.'));
            }
            return $plain;
        }

        if (str_starts_with($ciphertext, self::OPENSSL_PREFIX)) {
            $key  = self::deriveKey();
            $data = base64_decode(substr($ciphertext, strlen(self::OPENSSL_PREFIX)), true);
            if ($data === false) {
                throw new RuntimeException(self::msg('err_decrypt_malformed', 'The encrypted data is malformed.'));
            }
            $iv     = substr($data, 0, 12);
            $tag    = substr($data, 12, 16);
            $cipher = substr($data, 28);
            $plain  = openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
            if ($plain === false) {
                throw new RuntimeException(self::msg('err_decrypt_failed', 'Decryption failed.'));
            }
            return $plain;
        }

        // No prefix = plaintext (backward compatibility)
        return $ciphertext;
    }

    /** Generate a random base64-encoded 32-byte key for APP_KEY. */
    public static function generateAppKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    /**
     * Resolve a message through the catalog when it is available.
     *
     * Crypto runs from the CLI and from bootstrap paths where HbpLocale may not be loaded yet,
     * so fall back to the English wording rather than requiring the class.
     */
    private static function msg(string $key, string $english): string
    {
        return class_exists('HbpLocale') ? HbpLocale::t($key, $english) : $english;
    }
}
