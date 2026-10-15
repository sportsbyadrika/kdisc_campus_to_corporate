<?php
declare(strict_types=1);

/**
 * Schema migrations for databases created by an earlier version of database/schema.sql.
 *
 * ensure_schema() runs on every request but only does work when settings.schema_version
 * is behind SCHEMA_VERSION. Every step checks the current structure first, so it is safe
 * to run against a fresh install (where schema.sql already has the change) or to re-run.
 */
const SCHEMA_VERSION = 3;

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
        // v3: public institution enrollment, password set/reset tokens, mail log, rate limiting, institution head
        3 => function (): void {
            foreach ([ENROLLMENT_DDL, PASSWORD_TOKENS_DDL, MAIL_LOG_DDL, THROTTLE_DDL] as $ddl) {
                db()->exec($ddl);
            }
            foreach ([
                'head_name' => "VARCHAR(160) NULL COMMENT 'Head of the institution' AFTER history",
                'head_designation' => 'VARCHAR(160) NULL AFTER head_name',
                'head_email' => 'VARCHAR(160) NULL AFTER head_designation',
                'head_phone' => 'VARCHAR(20) NULL AFTER head_email',
            ] as $col => $def) {
                if (!column_exists('institutions', $col)) {
                    db()->exec("ALTER TABLE institutions ADD COLUMN {$col} {$def}");
                }
            }
            if (!column_exists('institution_officers', 'photo')) {
                db()->exec('ALTER TABLE institution_officers ADD COLUMN photo VARCHAR(255) NULL AFTER alt_phone');
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

const ENROLLMENT_DDL = "CREATE TABLE IF NOT EXISTS enrollment_requests (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  district_id       TINYINT UNSIGNED NOT NULL,
  institution_id    INT UNSIGNED NOT NULL,
  head_name         VARCHAR(160) NOT NULL,
  head_designation  VARCHAR(160) NOT NULL,
  head_email        VARCHAR(160) NOT NULL,
  head_phone        VARCHAR(20)  NOT NULL,
  po_name           VARCHAR(160) NOT NULL,
  po_email          VARCHAR(160) NOT NULL,
  po_phone          VARCHAR(20)  NOT NULL,
  status            ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  open_flag         TINYINT AS (IF(status IN ('pending','approved'), 1, NULL)) STORED COMMENT 'Enforces one open request per institution',
  review_comment    VARCHAR(1000) NULL,
  reviewed_by       INT UNSIGNED NULL,
  reviewed_at       DATETIME NULL,
  user_id           INT UNSIGNED NULL COMMENT 'Login created on approval',
  ip                VARCHAR(45) NULL,
  user_agent        VARCHAR(255) NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_enroll_open (institution_id, open_flag),
  KEY idx_enroll_district (district_id, status),
  CONSTRAINT fk_enroll_inst FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
  CONSTRAINT fk_enroll_district FOREIGN KEY (district_id) REFERENCES districts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

const PASSWORD_TOKENS_DDL = "CREATE TABLE IF NOT EXISTS password_tokens (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  token_hash  CHAR(64)     NOT NULL COMMENT 'SHA-256 of the token; the token itself is only ever emailed',
  purpose     ENUM('setup','reset') NOT NULL DEFAULT 'reset',
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token_hash (token_hash),
  KEY idx_token_user (user_id),
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

const MAIL_LOG_DDL = "CREATE TABLE IF NOT EXISTS mail_log (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient   VARCHAR(160) NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  template    VARCHAR(40)  NOT NULL,
  status      ENUM('sent','failed','logged') NOT NULL,
  error       VARCHAR(500) NULL,
  body        MEDIUMTEXT   NULL COMMENT 'Only stored with the log driver (development)',
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mail_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

const THROTTLE_DDL = "CREATE TABLE IF NOT EXISTS throttle (
  id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket    VARCHAR(120) NOT NULL,
  hit_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_throttle (bucket, hit_at)
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
