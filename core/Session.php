<?php
/**
 * HayabusaPress - Multi-backend custom session handler.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class DbSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function open(string $path, string $name): bool
    {
        $this->pdo = DB::getInstance();
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare('SELECT data FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return '';
        }
        // PostgreSQL BYTEA may be returned as a resource
        if (is_resource($row['data'])) {
            return stream_get_contents($row['data']);
        }
        return (string)$row['data'];
    }

    public function write(string $id, string $data): bool
    {
        $ts     = time();
        $driver = DB::driver();
        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO sessions (id, data, timestamp) VALUES (?, ?, ?)
                    ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, timestamp = EXCLUDED.timestamp';
        } else {
            $sql = 'INSERT INTO sessions (id, data, timestamp) VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE data = VALUES(data), timestamp = VALUES(timestamp)';
        }
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([$id, $data, $ts]);
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = ?');
        return $stmt->execute([$id]);
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE timestamp < ?');
        $stmt->execute([time() - $max_lifetime]);
        return $stmt->rowCount();
    }
}
