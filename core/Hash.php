<?php
/**
 * HayabusaPress - Password hashing (bcrypt / argon2id).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class Hash
{
    public static function make(string $password): string
    {
        $algo = class_exists('Env') ? Env::get('PASSWORD_ALGO', 'bcrypt') : ($_ENV['PASSWORD_ALGO'] ?? 'bcrypt');
        if ($algo === 'argon2id') {
            return password_hash($password, PASSWORD_ARGON2ID);
        }
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
