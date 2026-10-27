<?php
/**
 * HayabusaPress - DB migration manager (schema_migrations).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * M-series: DB migration management.
 * Applies core/database/migrations/V{nnn}__*.{driver}.sql in ascending, idempotent order.
 * Tracks applied state and checksum (SHA-256) in the schema_migrations table.
 */
class Migrator
{
    private const MIGRATIONS_DIR = CORE_PATH . '/database/migrations';

    /** Sentinel checksum for the baseline row originating from the full schema (comparison is skipped). */
    private const BASELINE = 'BASELINE';

    /**
     * Errors that mean "this change is already in place", which is the idempotent outcome of
     * re-running a migration (MySQL has no ADD COLUMN / ADD INDEX IF NOT EXISTS). Matched by code,
     * never by message text: the message is translated with the server's locale.
     * MySQL by driver error number (its SQLSTATE for a duplicate index is the generic 42000):
     *   1050 table exists · 1060 duplicate column · 1061 duplicate key name ·
     *   1091 can't drop (already gone) · 1826 duplicate foreign key name
     * PostgreSQL by SQLSTATE: 42P07 duplicate_table (also indexes) · 42701 duplicate_column ·
     *   42710 duplicate_object (constraints)
     */
    private const ALREADY_APPLIED_MYSQL = [1050, 1060, 1061, 1091, 1826];
    private const ALREADY_APPLIED_PGSQL = ['42P07', '42701', '42710'];

    /**
     * Split SQL into statements. Unlike a naive explode(';'),
     * it does not split on ; inside string literals ('...' / "..."), line comments (--), block comments,
     * or PostgreSQL dollar-quoting ($$ ... $$ / $tag$ ... $tag$).
     * This lets migrations whose function bodies or INSERT values contain ; run safely as individual statements.
     * Statements that are entirely comment/whitespace are excluded.
     */
    public static function splitStatements(string $sql): array
    {
        $out = [];
        $buf = '';
        $len = strlen($sql);

        $inSingle = false; // '…'
        $inDouble = false; // "…"
        $inLine   = false; // -- line comment
        $inBlock  = false; // block comment

        for ($i = 0; $i < $len; $i++) {
            $ch   = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            // --- Inside a comment ---
            if ($inLine) {
                if ($ch === "\n") { $inLine = false; $buf .= $ch; }
                continue;
            }
            if ($inBlock) {
                if ($ch === '*' && $next === '/') { $inBlock = false; $i++; }
                continue;
            }

            // --- Inside a string ---
            if ($inSingle) {
                $buf .= $ch;
                if ($ch === "'") {
                    if ($next === "'") { $buf .= $next; $i++; } // '' escape
                    else { $inSingle = false; }
                } elseif ($ch === '\\' && $next !== '') { $buf .= $next; $i++; }
                continue;
            }
            if ($inDouble) {
                $buf .= $ch;
                if ($ch === '"') {
                    if ($next === '"') { $buf .= $next; $i++; }
                    else { $inDouble = false; }
                } elseif ($ch === '\\' && $next !== '') { $buf .= $next; $i++; }
                continue;
            }

            // --- Normal state ---
            // Comment start
            if ($ch === '-' && $next === '-') { $inLine = true; continue; }
            if ($ch === '/' && $next === '*') { $inBlock = true; $i++; continue; }

            // Dollar-quoting (PostgreSQL function bodies, etc.). Take in $$ ... $$ / $tag$ ... $tag$ whole
            if ($ch === '$' && preg_match('/^\$[A-Za-z_0-9]*\$/', substr($sql, $i), $mm)) {
                $tag = $mm[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                if ($end === false) {
                    $buf .= substr($sql, $i); // No terminator: take in all the rest
                    break;
                }
                $buf .= substr($sql, $i, $end + strlen($tag) - $i);
                $i = $end + strlen($tag) - 1; // -1 to account for the loop's ++
                continue;
            }

            // String start
            if ($ch === "'") { $inSingle = true; $buf .= $ch; continue; }
            if ($ch === '"') { $inDouble = true; $buf .= $ch; continue; }

            // Statement separator
            if ($ch === ';') {
                $clean = trim($buf);
                if ($clean !== '') { $out[] = $clean; }
                $buf = '';
                continue;
            }

            $buf .= $ch;
        }

        $clean = trim($buf);
        if ($clean !== '') { $out[] = $clean; }

        return $out;
    }

    /**
     * Detect drift between the version the code expects (Schema::SCHEMA_VERSION) and the latest applied version in the DB,
     * returning a warning message on mismatch. Returns null on match, fetch failure, or exception (never breaks the admin screen).
     * For the admin startup banner. Not called on the public FPC fast path (does not add a DB query).
     */
    public static function schemaDriftWarning(): ?string
    {
        if (!class_exists('Schema')) {
            return null;
        }
        try {
            $db = self::currentVersion();
            if ($db === null) {
                return 'Schema not applied (schema_migrations is empty). Run php core/cli.php db:install.';
            }
            $code = Schema::SCHEMA_VERSION;
            if ($db !== $code) {
                return sprintf(
                    'Schema version mismatch: DB=%s (%s) / code=%s (%s). Check with php core/cli.php db:status, then run db:migrate.',
                    $db, Schema::toSemantic($db), $code, Schema::toSemantic($code)
                );
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    /** Return the latest (= current) version ID in schema_migrations. null if none. */
    public static function currentVersion(): ?string
    {
        try {
            $row = DB::fetchOne('SELECT version FROM schema_migrations ORDER BY version DESC LIMIT 1');
            return $row['version'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Create the schema_migrations table if it does not exist. */
    public static function ensureMigrationsTable(): void
    {
        $pdo    = DB::getInstance();
        $driver = DB::driver();

        if ($driver === 'pgsql') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS schema_migrations (
                  version    VARCHAR(20)  NOT NULL PRIMARY KEY,
                  name       VARCHAR(255) NOT NULL,
                  checksum   CHAR(64)     NOT NULL,
                  applied_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                )
            ");
        } else {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `schema_migrations` (
                  `version`    VARCHAR(20)  NOT NULL,
                  `name`       VARCHAR(255) NOT NULL,
                  `checksum`   CHAR(64)     NOT NULL,
                  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`version`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }

    /** Return the list of applied migrations. */
    public static function appliedVersions(): array
    {
        try {
            $rows = DB::fetchAll('SELECT version, name, checksum, applied_at FROM schema_migrations ORDER BY version');
            // checksum is CHAR(64): PostgreSQL returns the 'BASELINE' sentinel padded with spaces,
            // which would never equal the sentinel and report every baseline row as tampered.
            foreach ($rows as &$r) {
                $r['checksum'] = rtrim((string)$r['checksum']);
            }
            unset($r);
            return array_column($rows, null, 'version');
        } catch (Throwable) {
            return [];
        }
    }

    /** Return the list of available migration files (per driver). */
    public static function available(): array
    {
        $driver = DB::driver();
        // The naming convention is V{nnn}__{description}.{driver}.sql, so the description sits
        // between '__' and the driver suffix. Keep the '*' on both sides: "V*__{$driver}.sql" would
        // require the driver to come straight after '__', match no real file, and make every
        // migration silently look like "nothing to apply".
        $files = glob(self::MIGRATIONS_DIR . "/V*__*.{$driver}.sql") ?: [];
        $migrations = [];
        foreach ($files as $file) {
            $base = basename($file);
            if (preg_match('/^(V\d+)__(.+)\.(?:mysql|pgsql)\.sql$/', $base, $m)) {
                $migrations[$m[1]] = [
                    'version'  => $m[1],
                    'name'     => $m[2],
                    'file'     => $file,
                    'checksum' => hash_file('sha256', $file),
                ];
            }
        }
        ksort($migrations);
        return $migrations;
    }

    /** One migration at a time: db:migrate and the admin panel's upgrade take the same lock. */
    private const LOCK_FILE = STORAGE_PATH . '/.migrate.lock';

    /**
     * Apply pending migrations in ascending order.
     *
     * @param callable|null $out receives each progress line (default: echo, for the CLI)
     * @return int the number of migrations applied
     * @throws MigrationFailed when a statement fails (that migration is not recorded)
     * @throws RuntimeException when another migration is running
     */
    public static function migrate(?callable $out = null): int
    {
        $out ??= static function (string $line): void { echo $line, "\n"; };
        $lock = @fopen(self::LOCK_FILE, 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException(class_exists('HbpLocale')
                ? HbpLocale::t('err_migrate_busy', 'Another migration is running. Wait for it to finish.')
                : 'Another migration is running. Wait for it to finish.');
        }
        @ignore_user_abort(true);   // a closed browser tab must not cut a migration short
        @set_time_limit(0);

        try {
            self::ensureMigrationsTable();
            $applied   = self::appliedVersions();
            $available = self::available();
            $count     = 0;

            foreach ($available as $version => $meta) {
                if (isset($applied[$version])) {
                    // checksum comparison (tamper detection).
                    // Skip rows whose checksum is the BASELINE sentinel: those changes arrived folded into
                    // the full schema (schema.{driver}.sql stamps them), so there is no file checksum to
                    // match. Comparing them would report tampering on every correctly installed database.
                    if ($applied[$version]['checksum'] !== self::BASELINE
                        && $applied[$version]['checksum'] !== $meta['checksum']) {
                        $out("Warning: checksum for migration {$version} has changed.");
                    }
                    continue;
                }

                $out("Applying: {$version} ({$meta['name']})...");
                self::applyOne($version, $meta);
                $out("  Done: {$version}");
                $count++;
            }
            return $count;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Migrations not applied yet, in order.
     *
     * @return array<string,array{version:string,name:string,file:string,checksum:string}>
     */
    public static function pending(): array
    {
        return array_diff_key(self::available(), self::appliedVersions());
    }

    /**
     * Apply one migration file and record it. Throws MigrationFailed, without recording, when a
     * statement fails for a reason other than "already applied", so the next db:migrate retries it.
     *
     * @param array{name:string,checksum:string,file:string} $meta
     */
    private static function applyOne(string $version, array $meta): void
    {
        self::runScript((string)file_get_contents($meta['file']), $version, static function () use ($version, $meta): void {
            DB::execute(
                'INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (?,?,?,?)',
                [$version, $meta['name'], $meta['checksum'], date('Y-m-d H:i:s')]
            );
        });
    }

    /**
     * Run a SQL script statement by statement: a migration, or a plugin's install / uninstall SQL.
     * Statements that fail only because their change is already in place are skipped (see the
     * constants); any other failure throws MigrationFailed naming the statement.
     *
     * PostgreSQL runs DDL inside transactions, so the whole script (and $finally, which records it)
     * is one transaction: a failure leaves the database exactly as it was. Each statement gets a
     * savepoint, because an error aborts a PostgreSQL transaction and an "already applied" error
     * must not take the rest of the script with it. MySQL commits every DDL statement on its own and
     * cannot do this: the statements before the failing one stay applied, which scripts are written
     * to tolerate (a re-run skips what is already there).
     *
     * @param string        $label   what the script is, for the error (a version, or "plugin x install")
     * @param callable|null $finally run after the last statement, inside the same transaction
     * @throws MigrationFailed
     */
    public static function runScript(string $sql, string $label, ?callable $finally = null): void
    {
        $pdo        = DB::getInstance();
        $driver     = (string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $transacted = $driver === 'pgsql';
        $statements = self::splitStatements($sql);

        if ($transacted) {
            $pdo->beginTransaction();
        }
        try {
            foreach ($statements as $i => $stmt) {
                if ($transacted) {
                    $pdo->exec('SAVEPOINT hbp_stmt');
                }
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    if (!self::isAlreadyApplied($e, $driver)) {
                        throw new MigrationFailed($label, $i + 1, count($statements), $stmt, $e, $transacted);
                    }
                    if ($transacted) {
                        $pdo->exec('ROLLBACK TO SAVEPOINT hbp_stmt');
                    }
                }
            }
            if ($finally !== null) {
                $finally();
            }
            if ($transacted) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($transacted && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Whether a failed statement only reports that its change is already in place (see the constants). */
    public static function isAlreadyApplied(PDOException $e, string $driver): bool
    {
        $info = $e->errorInfo;
        if ($driver === 'mysql') {
            return in_array((int)($info[1] ?? 0), self::ALREADY_APPLIED_MYSQL, true);
        }
        $state = (string)($info[0] ?? $e->getCode());
        return in_array($state, self::ALREADY_APPLIED_PGSQL, true);
    }

    /**
     * Idempotently ensure the baseline row originating from the full schema (Schema::SCHEMA_VERSION).
     * Normally the INSERT at the end of the full schema records it, but ensure it doubly just in case.
     */
    private static function recordBaselineRow(): void
    {
        $version  = class_exists('Schema') ? Schema::BASELINE_VERSION : 'V001000000';
        $checksum = class_exists('Schema') ? Schema::BASELINE_CHECKSUM : self::BASELINE;
        $name     = 'baseline: full schema (HBP ' . (class_exists('Schema') ? Schema::toSemantic($version) : '1.0.0') . ')';
        $now      = date('Y-m-d H:i:s');
        try {
            DB::execute(
                'INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE version = version',
                [$version, $name, $checksum, $now]
            );
        } catch (Throwable) {
            try {
                DB::execute(
                    'INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (?,?,?,?)
                     ON CONFLICT (version) DO NOTHING',
                    [$version, $name, $checksum, $now]
                );
            } catch (Throwable $e2) {
                // Both dialect variants failed: the migration ran but was not recorded, so the next
                // db:migrate would try to apply it again. Never silent -- this needs an operator.
                error_log('[HBP MIGRATE] failed to record migration ' . $version . ': ' . $e2->getMessage());
            }
        }
    }

    /**
     * For a fresh install: record the baseline row + all known incremental migrations as applied.
     */
    public static function baseline(): void
    {
        self::ensureMigrationsTable();
        self::recordBaselineRow();
        $available = self::available();

        foreach ($available as $version => $meta) {
            try {
                DB::execute(
                    'INSERT INTO schema_migrations (version, name, checksum, applied_at) VALUES (?,?,?,?)
                     ON DUPLICATE KEY UPDATE checksum=VALUES(checksum)',
                    [$version, $meta['name'], $meta['checksum'], date('Y-m-d H:i:s')]
                );
            } catch (Throwable) {
                // PostgreSQL ON CONFLICT
                try {
                    DB::execute(
                        'INSERT INTO schema_migrations (version, name, checksum, applied_at)
                         VALUES (?,?,?,?)
                         ON CONFLICT (version) DO UPDATE SET checksum=EXCLUDED.checksum',
                        [$version, $meta['name'], $meta['checksum'], date('Y-m-d H:i:s')]
                    );
                } catch (Throwable) {
                }
            }
        }
    }

    /**
     * Display the application status (for db:status).
     * Shows the DB version, the version the code expects (Schema::SCHEMA_VERSION), the driver, and the pending list.
     * "How far you need to apply" is answered directly by this output.
     */
    public static function status(): void
    {
        self::ensureMigrationsTable();
        $applied   = self::appliedVersions();
        $available = self::available();

        $dbVersion   = self::currentVersion();
        $codeVersion = class_exists('Schema') ? Schema::SCHEMA_VERSION : null;
        $driver      = DB::driver();

        $fmt = static function (?string $v): string {
            if ($v === null) return '(none)';
            return class_exists('Schema') ? $v . '   (' . Schema::toSemantic($v) . ')' : $v;
        };

        echo "\n";
        echo "  DB schema version : " . $fmt($dbVersion) . "   (latest row in schema_migrations)\n";
        echo "  Code expects      : " . $fmt($codeVersion) . "   (SCHEMA_VERSION in core/Schema.php)\n";
        echo "  Driver            : {$driver}\n\n";

        // Pending (present in available but not in applied)
        $pending = [];
        foreach ($available as $version => $meta) {
            if (!isset($applied[$version])) {
                $pending[] = $meta;
            }
        }

        // checksum verification (skip the BASELINE row)
        $tampered = [];
        foreach ($available as $version => $meta) {
            if (isset($applied[$version])
                && $applied[$version]['checksum'] !== self::BASELINE
                && $applied[$version]['checksum'] !== $meta['checksum']) {
                $tampered[] = $version;
            }
        }

        if (empty($pending)) {
            if ($dbVersion !== null && $dbVersion === $codeVersion) {
                echo "  [OK] Schema is up to date. Nothing to apply.\n";
            } elseif ($dbVersion === null) {
                echo "  [!] schema_migrations has no rows. Run db:install.\n";
            } else {
                echo "  [!] Code and DB versions differ (DB={$dbVersion} / code={$codeVersion}).\n";
                echo "    Update the code, or reload the full schema.\n";
            }
        } else {
            echo "  Pending migrations (" . count($pending) . "):\n";
            foreach ($pending as $m) {
                echo "    - {$m['version']}  " . mb_substr($m['name'], 0, 50) . "\n";
            }
            echo "\n  Run: php core/cli.php db:migrate\n";
        }

        if (!empty($tampered)) {
            echo "\n  [!] Checksum mismatch (possible tampering): " . implode(', ', $tampered) . "\n";
        }
        echo "\n";
    }
}

/**
 * A statement of a migration (or of a plugin's install / uninstall SQL) failed for a reason other
 * than "already applied". A migration is then not recorded, so fixing the cause and running
 * db:migrate again retries it. $version holds what was being run (Migrator::runScript()'s label).
 */
class MigrationFailed extends RuntimeException
{
    public function __construct(
        public string $version,
        public int $statementNo,
        public int $statementCount,
        public string $statement,
        PDOException $previous,
        public bool $rolledBack,
    ) {
        parent::__construct(sprintf(
            '%s failed at statement %d of %d: %s',
            $version,
            $statementNo,
            $statementCount,
            $previous->getMessage()
        ), 0, $previous);
    }
}
