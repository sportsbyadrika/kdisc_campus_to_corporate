<?php
declare(strict_types=1);

/**
 * Schema migrations for databases created by an earlier version of database/schema.sql.
 *
 * ensure_schema() runs on every request but only does work when settings.schema_version
 * is behind SCHEMA_VERSION. Every step checks the current structure first, so it is safe
 * to run against a fresh install (where schema.sql already has the change) or to re-run.
 */
const SCHEMA_VERSION = 2;

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
        // v2: institution types master (seeded), on institutions and new-institution requests
        2 => function (): void {
            db()->exec(INSTITUTION_TYPES_DDL);
            if ((int) db()->query('SELECT COUNT(*) FROM institution_types')->fetchColumn() === 0) {
                $ins = db()->prepare('INSERT IGNORE INTO institution_types (name, sort_order) VALUES (?, ?)');
                foreach (INSTITUTION_TYPES_SEED as [$name, $order]) {
                    $ins->execute([$name, $order]);
                }
            }
            if (!column_exists('institutions', 'type_id')) {
                db()->exec('ALTER TABLE institutions ADD COLUMN type_id INT UNSIGNED NULL AFTER category_id, ADD KEY idx_inst_type (type_id)');
            }
            if (!constraint_exists('institutions', 'fk_inst_type')) {
                db()->exec('ALTER TABLE institutions ADD CONSTRAINT fk_inst_type FOREIGN KEY (type_id) REFERENCES institution_types (id) ON DELETE SET NULL');
            }
            if (!column_exists('institution_requests', 'type_id')) {
                db()->exec('ALTER TABLE institution_requests ADD COLUMN type_id INT UNSIGNED NULL AFTER category_id');
            }
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

const INSTITUTION_TYPES_DDL = "CREATE TABLE IF NOT EXISTS institution_types (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_type_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

/** Default institution types (also seeded by database/schema.sql). Editable afterwards under Masters. */
const INSTITUTION_TYPES_SEED = [
    ['Engineering College', 1],
    ['Arts & Science College', 2],
    ['Polytechnic College', 3],
    ['Management Institute (MBA)', 4],
    ['Computer Applications Institute (MCA / BCA)', 5],
    ['Medical College', 6],
    ['Nursing & Paramedical College', 7],
    ['Pharmacy College', 8],
    ['Teacher Training College (B.Ed / D.El.Ed)', 9],
    ['Law College', 10],
    ['Architecture College', 11],
    ['ITI / Vocational Training Institute', 12],
    ['University Department / Centre', 13],
    ['Other', 99],
];

function constraint_exists(string $table, string $name): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?');
    $st->execute([$table, $name]);
    return (int) $st->fetchColumn() > 0;
}

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
