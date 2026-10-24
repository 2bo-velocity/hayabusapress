<?php
/**
 * HayabusaPress - Single source of truth for the schema version.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * M-series: single source of truth for the schema version (the version the code expects).
 *
 * The version identifier is V + zero-padded major(3), minor(3), patch(3): a fixed 10-digit width.
 * Lexicographic string order matches semantic version order.
 *   1.0.0 -> V001000000 / 1.2.5 -> V001002005 / 2.0.0 -> V002000000
 *
 * db:status compares this constant against the version recorded by the baseline INSERT at the end of
 * the full schema (schema.{driver}.sql) to detect drift between the code and the DB.
 */
class Schema
{
    /**
     * Schema version the code expects. HBP 1.3.0.
     * Must equal the highest migration in core/database/migrations, because db:status compares this
     * against the newest row in schema_migrations to detect code/DB drift.
     */
    public const SCHEMA_VERSION = 'V001003000';

    /**
     * Version recorded for the "this came from the full schema" baseline row.
     * Pinned to 1.0.0 and never moved: it is the origin marker, not the current version. Bumping it
     * with SCHEMA_VERSION would make the baseline row collide with the migration row of the same
     * version. The full schema files carry the same literal in their closing INSERT.
     */
    public const BASELINE_VERSION = 'V001000000';

    /** Sentinel checksum for the baseline row (denotes full-schema origin, not incremental application). */
    public const BASELINE_CHECKSUM = 'BASELINE';

    /** semver (major, minor, patch) -> fixed-width version ID. */
    public static function versionId(int $major, int $minor, int $patch): string
    {
        return sprintf('V%03d%03d%03d', $major, $minor, $patch);
    }

    /** Version ID (V001002005) -> display semver (1.2.5). Invalid formats are returned as-is. */
    public static function toSemantic(string $versionId): string
    {
        if (!preg_match('/^V(\d{3})(\d{3})(\d{3})$/', $versionId, $m)) {
            return $versionId;
        }
        return (int)$m[1] . '.' . (int)$m[2] . '.' . (int)$m[3];
    }
}
