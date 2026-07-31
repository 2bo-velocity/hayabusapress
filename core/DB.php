<?php
/**
 * HayabusaPress - PDO singleton (multi-driver MySQL / PostgreSQL).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class DB
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $driver   = class_exists('Env') ? Env::get('DB_DRIVER', 'mysql')   : ($_ENV['DB_DRIVER']   ?? 'mysql');
            $host     = class_exists('Env') ? Env::get('DB_HOST', '127.0.0.1') : ($_ENV['DB_HOST']     ?? '127.0.0.1');
            $port     = class_exists('Env') ? Env::get('DB_PORT', '3306')      : ($_ENV['DB_PORT']     ?? '3306');
            $dbname   = class_exists('Env') ? Env::get('DB_DATABASE', '')      : ($_ENV['DB_DATABASE'] ?? '');
            $username = class_exists('Env') ? Env::get('DB_USERNAME', '')      : ($_ENV['DB_USERNAME'] ?? '');
            $password = class_exists('Env') ? Env::get('DB_PASSWORD', '')      : ($_ENV['DB_PASSWORD'] ?? '');

            $charset = self::charset();
            if ($driver === 'pgsql') {
                // client_encoding is SET after connecting (more robust across environments than DSN options)
                $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";
            } else {
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            }

            self::$instance = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            // PostgreSQL sets client_encoding explicitly after connecting. Through automatic conversion against the
            // server DB encoding, data is always sent/received as UTF-8 to prevent mojibake of Japanese/emoji.
            // $charset is restricted to [A-Za-z0-9_-] by self::charset(), so it is safe to embed in the SET statement.
            if ($driver === 'pgsql') {
                self::$instance->exec("SET client_encoding TO '{$charset}'");
            }
        }
        return self::$instance;
    }

    /**
     * Resolve the connection charset (with a safe per-driver default).
     * Default is utf8mb4 for mysql and UTF8 for pgsql.
     * If DB_CHARSET is set it is used, but invalid characters (anything other than [A-Za-z0-9_-]) fall back to the default.
     * For pgsql, the notations utf8mb4/utf8/utf-8 are normalized to the canonical name UTF8 (to avoid connection failures from misconfiguration).
     */
    public static function charset(): string
    {
        $driver  = self::driver();
        $default = $driver === 'pgsql' ? 'UTF8' : 'utf8mb4';
        $cs = class_exists('Env') ? trim(Env::get('DB_CHARSET', '')) : '';
        if ($cs === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $cs)) {
            return $default;
        }
        if ($driver === 'pgsql' && in_array(strtolower($cs), ['utf8mb4', 'utf8', 'utf-8'], true)) {
            return 'UTF8';
        }
        return $cs;
    }

    /**
     * Inspect the actual DB encoding and return a warning string if it is not in the UTF-8 family (null on match or fetch failure).
     * PostgreSQL's server_encoding is fixed at CREATE DATABASE time and cannot be changed at runtime,
     * so non-UTF8 is reported to the operator early. For the admin startup banner (never called on the public FPC fast path).
     */
    public static function encodingWarning(): ?string
    {
        try {
            if (self::driver() === 'pgsql') {
                $enc = (string)self::getInstance()->query('SHOW server_encoding')->fetchColumn();
                if (strtoupper($enc) !== 'UTF8') {
                    return sprintf(
                        'PostgreSQL server_encoding is %s. Japanese and emoji will be garbled. '
                        . 'Recreate the database with CREATE DATABASE ... ENCODING \'UTF8\' (it cannot be changed after creation).',
                        $enc
                    );
                }
            } else {
                $enc = (string)self::getInstance()->query('SELECT @@character_set_database')->fetchColumn();
                if (stripos($enc, 'utf8') !== 0) {
                    return sprintf(
                        'The MySQL database character set is %s. utf8mb4 is recommended for emoji and multilingual content.',
                        $enc
                    );
                }
            }
        } catch (Throwable) {
        }
        return null;
    }

    /** Prepared-statement execution shorthand */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): array|false
    {
        return self::query($sql, $params)->fetch();
    }

    public static function execute(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    public static function driver(): string
    {
        return class_exists('Env') ? Env::get('DB_DRIVER', 'mysql') : ($_ENV['DB_DRIVER'] ?? 'mysql');
    }
}
