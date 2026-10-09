<?php
declare(strict_types=1);

/**
 * Schema migrations for databases created by an earlier version of database/schema.sql.
 *
 * ensure_schema() runs on every request but only does work when settings.schema_version
 * is behind SCHEMA_VERSION. Every step checks the current structure first, so it is safe
 * to run against a fresh install (where schema.sql already has the change) or to re-run.
 */
const SCHEMA_VERSION = 1;

function ensure_schema(): void
{
    try {
        $current = (int) setting('schema_version', '0');
        if ($current >= SCHEMA_VERSION) {
            return;
        }
        foreach (schema_migrations() as $version => $migrate) {
            if ($version > $current) {
                $migrate();
                db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (\'schema_version\', ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
                    ->execute([(string) $version]);
            }
        }
    } catch (Throwable $e) {
        error_log('C2C schema migration failed: ' . $e->getMessage());
        if (config('debug')) {
            throw $e;
        }
    }
}

/** @return array<int, callable> */
function schema_migrations(): array
{
    return [
        // v1: DWMS institution id, campus-placed students, vendor-reported test counts
        1 => function (): void {
            if (!column_exists('institutions', 'dwms_id')) {
                db()->exec("ALTER TABLE institutions ADD COLUMN dwms_id VARCHAR(40) NULL COMMENT 'DWMS institution id' AFTER code");
            }
            if (!index_exists('institutions', 'uq_inst_dwms')) {
                db()->exec('ALTER TABLE institutions ADD UNIQUE KEY uq_inst_dwms (dwms_id)');
            }
            if (!column_exists('institution_cohorts', 'campus_placed')) {
                db()->exec("ALTER TABLE institution_cohorts ADD COLUMN campus_placed INT UNSIGNED NULL COMMENT 'Students placed through campus recruitment' AFTER academic_year");
            }
            db()->exec(VENDOR_COUNTS_DDL);
        },
    ];
}

const VENDOR_COUNTS_DDL = "CREATE TABLE IF NOT EXISTS assessment_vendor_counts (
  institution_id      INT UNSIGNED NOT NULL,
  academic_year       VARCHAR(9)   NOT NULL,
  assessment_test_id  INT UNSIGNED NOT NULL,
  tests_conducted     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Value reported by the test vendor software',
  uploaded_by         INT UNSIGNED NULL,
  uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (institution_id, academic_year, assessment_test_id),
  KEY idx_vendor_test (assessment_test_id),
  CONSTRAINT fk_vendor_inst FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
  CONSTRAINT fk_vendor_test FOREIGN KEY (assessment_test_id) REFERENCES assessment_tests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

function column_exists(string $table, string $column): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (int) $st->fetchColumn() > 0;
}

function index_exists(string $table, string $index): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $st->execute([$table, $index]);
    return (int) $st->fetchColumn() > 0;
}
