-- Campus to Corporate (C2C) - Institutional Tracking Portal
-- MySQL 8.0+ / MariaDB 10.6+   (utf8mb4)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- Masters
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS districts (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(60)  NOT NULL,
  code          VARCHAR(6)   NOT NULL,
  sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  tce_name      VARCHAR(120) NULL COMMENT 'Talent Connect Executive',
  tce_email     VARCHAR(160) NULL,
  tce_phone     VARCHAR(30)  NULL,
  rpm_name      VARCHAR(120) NULL COMMENT 'Regional Programme Manager',
  rpm_email     VARCHAR(160) NULL,
  rh_name       VARCHAR(120) NULL COMMENT 'Regional Head',
  rh_email      VARCHAR(160) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_district_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS universities (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(200) NOT NULL,
  short_name  VARCHAR(40)  NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_university_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_categories (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_category_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(120) NOT NULL,
  level       VARCHAR(20)  NOT NULL DEFAULT 'UG' COMMENT 'UG / PG / Diploma / Other',
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_course_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment tests are defined per university (NULL university = applies to all)
CREATE TABLE IF NOT EXISTS assessment_tests (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  university_id  INT UNSIGNED NULL,
  name           VARCHAR(160) NOT NULL,
  description    VARCHAR(500) NULL,
  is_mandatory   TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Gateway assessment (e.g. English Score)',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_test_university (university_id),
  CONSTRAINT fk_test_university FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS dwms_services (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name         VARCHAR(160) NOT NULL,
  description  VARCHAR(500) NULL,
  tag          VARCHAR(40)  NULL,
  tag_color    VARCHAR(20)  NOT NULL DEFAULT 'sky',
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  `key`       VARCHAR(60)  NOT NULL,
  `value`     TEXT         NULL,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Institutions
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS institutions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(255) NOT NULL,
  code           VARCHAR(40)  NULL COMMENT 'Affiliation code',
  email          VARCHAR(160) NULL,
  phone          VARCHAR(30)  NULL,
  website        VARCHAR(200) NULL,
  address        VARCHAR(400) NULL,
  pincode        VARCHAR(10)  NULL,
  district_id    TINYINT UNSIGNED NOT NULL,
  university_id  INT UNSIGNED NULL,
  category_id    INT UNSIGNED NULL,
  latitude       DECIMAL(10,7) NULL,
  longitude      DECIMAL(10,7) NULL,
  logo           VARCHAR(255) NULL,
  photo          VARCHAR(255) NULL,
  history        TEXT NULL,
  established_year SMALLINT UNSIGNED NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_inst_district (district_id),
  KEY idx_inst_university (university_id),
  KEY idx_inst_category (category_id),
  CONSTRAINT fk_inst_district   FOREIGN KEY (district_id)   REFERENCES districts (id),
  CONSTRAINT fk_inst_university FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE SET NULL,
  CONSTRAINT fk_inst_category   FOREIGN KEY (category_id)   REFERENCES institution_categories (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- New-institution requests raised by district users, approved by State / Admin
CREATE TABLE IF NOT EXISTS institution_requests (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  district_id    TINYINT UNSIGNED NOT NULL,
  name           VARCHAR(255) NOT NULL,
  code           VARCHAR(40)  NULL,
  email          VARCHAR(160) NULL,
  address        VARCHAR(400) NULL,
  university_id  INT UNSIGNED NULL,
  category_id    INT UNSIGNED NULL,
  remarks        VARCHAR(1000) NULL,
  status         ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  review_remarks VARCHAR(1000) NULL,
  institution_id INT UNSIGNED NULL,
  requested_by   INT UNSIGNED NOT NULL,
  reviewed_by    INT UNSIGNED NULL,
  reviewed_at    DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_req_status (status),
  CONSTRAINT fk_req_district FOREIGN KEY (district_id) REFERENCES districts (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS institution_officers (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id  INT UNSIGNED NOT NULL,
  name            VARCHAR(160) NOT NULL,
  designation     VARCHAR(160) NULL,
  email           VARCHAR(160) NULL,
  phone           VARCHAR(20)  NULL,
  alt_phone       VARCHAR(30)  NULL,
  is_primary      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Nodal officer',
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_officer_inst (institution_id),
  CONSTRAINT fk_officer_inst FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Course / department-wise student strength, per academic year
CREATE TABLE IF NOT EXISTS institution_departments (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id   INT UNSIGNED NOT NULL,
  academic_year    VARCHAR(9)   NOT NULL,
  course_id        INT UNSIGNED NULL,
  department       VARCHAR(160) NOT NULL,
  total_students   INT UNSIGNED NOT NULL DEFAULT 0,
  final_year_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_dept_inst_year (institution_id, academic_year),
  CONSTRAINT fk_dept_inst   FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
  CONSTRAINT fk_dept_course FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student cohort for the academic year
CREATE TABLE IF NOT EXISTS institution_cohorts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id    INT UNSIGNED NOT NULL,
  academic_year     VARCHAR(9)   NOT NULL,
  total_final_year  INT UNSIGNED NOT NULL DEFAULT 0,
  dwms_registered   INT UNSIGNED NOT NULL DEFAULT 0,
  job_seekers       INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Immediate job seekers - primary target cohort',
  higher_studies    INT UNSIGNED NOT NULL DEFAULT 0,
  remarks           VARCHAR(1000) NULL,
  updated_by        INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cohort_inst_year (institution_id, academic_year),
  CONSTRAINT fk_cohort_inst FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Assessment progress for the academic year
CREATE TABLE IF NOT EXISTS institution_assessments (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id      INT UNSIGNED NOT NULL,
  academic_year       VARCHAR(9)   NOT NULL,
  assessment_test_id  INT UNSIGNED NOT NULL,
  students_completed  INT UNSIGNED NOT NULL DEFAULT 0,
  status              VARCHAR(40)  NOT NULL DEFAULT 'Pending Allocation',
  drive_date          DATE NULL,
  updated_by          INT UNSIGNED NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_assess (institution_id, academic_year, assessment_test_id),
  CONSTRAINT fk_assess_inst FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
  CONSTRAINT fk_assess_test FOREIGN KEY (assessment_test_id) REFERENCES assessment_tests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DWMS services availed by the institution
CREATE TABLE IF NOT EXISTS institution_services (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id  INT UNSIGNED NOT NULL,
  service_id      INT UNSIGNED NOT NULL,
  status          ENUM('Requested','Scheduled','In Progress','Completed') NOT NULL DEFAULT 'Requested',
  beneficiaries   INT UNSIGNED NOT NULL DEFAULT 0,
  availed_on      DATE NULL,
  remarks         VARCHAR(500) NULL,
  updated_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_inst_service (institution_id, service_id),
  CONSTRAINT fk_isvc_inst    FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE,
  CONSTRAINT fk_isvc_service FOREIGN KEY (service_id) REFERENCES dwms_services (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Point-in-time snapshots so changes in the field can be tracked over time
CREATE TABLE IF NOT EXISTS cohort_snapshots (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  institution_id     INT UNSIGNED NOT NULL,
  academic_year      VARCHAR(9)   NOT NULL,
  total_final_year   INT UNSIGNED NOT NULL DEFAULT 0,
  dwms_registered    INT UNSIGNED NOT NULL DEFAULT 0,
  job_seekers        INT UNSIGNED NOT NULL DEFAULT 0,
  gateway_completed  INT UNSIGNED NOT NULL DEFAULT 0,
  recorded_by        INT UNSIGNED NULL,
  recorded_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_snap_inst (institution_id, academic_year, recorded_at),
  CONSTRAINT fk_snap_inst FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Users & access
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(160) NOT NULL,
  username       VARCHAR(60)  NOT NULL,
  email          VARCHAR(160) NULL,
  phone          VARCHAR(20)  NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role           ENUM('superadmin','admin','state','district','institution') NOT NULL,
  district_id    TINYINT UNSIGNED NULL COMMENT 'District users, and owning district of institution users',
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at  DATETIME NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username),
  KEY idx_user_role (role),
  CONSTRAINT fk_user_district FOREIGN KEY (district_id) REFERENCES districts (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_institutions (
  user_id         INT UNSIGNED NOT NULL,
  institution_id  INT UNSIGNED NOT NULL,
  assigned_by     INT UNSIGNED NULL,
  assigned_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, institution_id),
  KEY idx_ui_inst (institution_id),
  CONSTRAINT fk_ui_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_ui_inst FOREIGN KEY (institution_id) REFERENCES institutions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username    VARCHAR(60) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempt (username, attempted_at),
  KEY idx_attempt_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail of every change made in the portal
CREATE TABLE IF NOT EXISTS activity_log (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         INT UNSIGNED NULL,
  institution_id  INT UNSIGNED NULL,
  district_id     TINYINT UNSIGNED NULL,
  action          VARCHAR(40)  NOT NULL,
  entity          VARCHAR(40)  NOT NULL,
  entity_id       INT UNSIGNED NULL,
  summary         VARCHAR(500) NOT NULL,
  changes         TEXT NULL COMMENT 'JSON: {field: [old, new]}',
  ip              VARCHAR(45) NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_log_inst (institution_id, created_at),
  KEY idx_log_district (district_id, created_at),
  KEY idx_log_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- Seed data (idempotent)
-- ---------------------------------------------------------------------------
INSERT IGNORE INTO districts (id, name, code, sort_order) VALUES
 (1,'Thiruvananthapuram','TVM',1),(2,'Kollam','KLM',2),(3,'Pathanamthitta','PTA',3),
 (4,'Alappuzha','ALP',4),(5,'Kottayam','KTM',5),(6,'Idukki','IDK',6),(7,'Ernakulam','EKM',7),
 (8,'Thrissur','TSR',8),(9,'Palakkad','PKD',9),(10,'Malappuram','MLP',10),(11,'Kozhikode','KKD',11),
 (12,'Wayanad','WYD',12),(13,'Kannur','KNR',13),(14,'Kasaragod','KSD',14);

INSERT IGNORE INTO universities (id, name, short_name) VALUES
 (1,'APJ Abdul Kalam Technological University','KTU'),
 (2,'University of Kerala','KU'),
 (3,'Mahatma Gandhi University','MGU'),
 (4,'University of Calicut','CU'),
 (5,'Kannur University','KNU'),
 (6,'Cochin University of Science and Technology','CUSAT'),
 (7,'Autonomous / Other','Other');

INSERT IGNORE INTO institution_categories (id, name) VALUES
 (1,'Government'),(2,'Government-Aided'),(3,'Self-Financing'),(4,'Autonomous');

INSERT IGNORE INTO courses (id, name, level) VALUES
 (1,'B.Tech','UG'),(2,'B.Sc','UG'),(3,'B.Com','UG'),(4,'BA','UG'),(5,'BBA','UG'),(6,'BCA','UG'),
 (7,'M.Tech','PG'),(8,'MCA','PG'),(9,'MBA','PG'),(10,'M.Sc','PG'),(11,'M.Com','PG'),(12,'MA','PG'),
 (13,'Diploma','Diploma');

INSERT IGNORE INTO assessment_tests (id, university_id, name, description, is_mandatory) VALUES
 (1, NULL, 'English Score Assessment', 'Mandatory first-level gateway diagnostic for all immediate job seekers.', 1),
 (2, NULL, 'Aptitude & Reasoning Test', 'Quantitative, logical and verbal reasoning screening.', 0),
 (3, 1, 'KTU Technical Proficiency Test', 'Branch-specific technical assessment for KTU engineering students.', 0),
 (4, 6, 'CUSAT Coding Assessment', 'Programming and problem-solving assessment.', 0);

INSERT IGNORE INTO dwms_services (id, name, description, tag, tag_color, sort_order) VALUES
 (1,'Career Curation Services','Resume curation, portfolio standardisation, and DWMS profile verification drives.','High Impact','sky',1),
 (2,'Mock Interview Drives','Simulated technical & HR panel interviews evaluated by corporate practitioners.','Interview Gateway','emerald',2),
 (3,'Targeted Skilling Modules','Domain bootcamps, soft-skills upskilling, and DWMS micro-credentials.','Skill Enhancement','indigo',3),
 (4,'Career Ambassador Selection & Orientation','Nominate peer student ambassadors to steer peer updates and drive test completion.','Peer Driven','purple',4),
 (5,'Industry Interactions & CXO Talks','Direct virtual & on-prem interactions with industry hiring managers and alumni.','Networking','amber',5),
 (6,'Exclusive DWMS Placement Drives','Corporate pool recruitment drives exclusively for qualified C2C immediate job seekers.','Final Placement','rose',6);

INSERT IGNORE INTO settings (`key`, `value`) VALUES
 ('app_name','Campus to Corporate'),
 ('app_tagline','Institutional Registration & Activity Tracking Layer'),
 ('academic_year','2026-27'),
 ('cohort_policy','The "Immediate Job-Seeker" strength is adopted as the primary operational target group for C2C interventions. Mandatory English Score Assessment completion acts as the gateway filter for student eligibility.'),
 ('footer_left','Campus to Corporate • Institutional Data & Tracking Layer • Integrated with DWMS'),
 ('footer_right','Government of Kerala • Head Office Operations'),
 ('show_rpm','0'),
 ('show_regional_head','0');
