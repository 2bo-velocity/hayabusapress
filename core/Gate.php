<?php
/**
 * HayabusaPress - Static permission gate (RBAC).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class Gate
{
    private static ?array $matrix = null;

    private static function matrix(): array
    {
        if (self::$matrix === null) {
            self::$matrix = require CORE_PATH . '/permissions.php';
        }
        return self::$matrix;
    }

    public static function can(string $action): bool
    {
        // Super User unconditionally passes all permissions
        if (($_SESSION['user_id'] ?? null) === 0) {
            return true;
        }
        $role = $_SESSION['user_role'] ?? '';
        return self::matrix()[$role][$action] ?? false;
    }

    public static function cannot(string $action): bool
    {
        return !self::can($action);
    }

    /** Return 403 and exit when not permitted */
    public static function authorize(string $action): void
    {
        if (!self::can($action)) {
            http_response_code(403);
            exit('403 Forbidden');
        }
    }
}
