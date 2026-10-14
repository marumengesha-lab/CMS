-- =====================================================================
--  Senbet Maderaja  |  Sabbath School Management System
--  Multi-level Dynamic RBAC  --  Integration Schema
--  Target: MySQL 5.7+ / MariaDB 10.2+   (XAMPP)
--  Charset: utf8mb4 (full Amharic/Ethiopic support)
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '+03:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. INSTITUTIONS  (created only by the Main / System Admin)
--    type allows a conference -> church -> sabbath_school hierarchy
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `institutions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(50)  NOT NULL,
  `name`        VARCHAR(191) NOT NULL,
  `name_am`     VARCHAR(191) DEFAULT NULL,
  `type`        ENUM('conference','church','sabbath_school','other') NOT NULL DEFAULT 'sabbath_school',
  `parent_id`   INT UNSIGNED DEFAULT NULL,
  `email`       VARCHAR(191) DEFAULT NULL,
  `phone`       VARCHAR(50)  DEFAULT NULL,
  `address`     VARCHAR(255) DEFAULT NULL,
  `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`  INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_institutions_code` (`code`),
  KEY `idx_institutions_parent` (`parent_id`),
  KEY `idx_institutions_status` (`status`),
  CONSTRAINT `fk_institutions_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `institutions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. DEPARTMENTS  (self-referencing tree: department -> division -> team)
--    `path` is a materialized path ('/1/5/9/') so an entire sub-tree can
--    be selected with a single indexed LIKE, without recursive CTEs.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `parent_id`      INT UNSIGNED DEFAULT NULL,
  `code`           VARCHAR(50)  NOT NULL,
  `name`           VARCHAR(191) NOT NULL,
  `name_am`        VARCHAR(191) DEFAULT NULL,
  `type`           ENUM('department','division','sub_department','team') NOT NULL DEFAULT 'department',
  `depth`          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `path`           VARCHAR(255) NOT NULL DEFAULT '',
  `description`    VARCHAR(255) DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_inst_code` (`institution_id`,`code`),
  KEY `idx_departments_institution` (`institution_id`),
  KEY `idx_departments_parent` (`parent_id`),
  KEY `idx_departments_path` (`path`),
  KEY `idx_departments_status` (`status`),
  CONSTRAINT `fk_departments_institution` FOREIGN KEY (`institution_id`)
    REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_departments_parent` FOREIGN KEY (`parent_id`)
    REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. USERS
--    A user belongs to at most one institution and (optionally) to one
--    department. `is_super_admin` marks the Main / System Admin.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED DEFAULT NULL,
  `department_id`  INT UNSIGNED DEFAULT NULL,
  `username`       VARCHAR(60)  NOT NULL,
  `email`          VARCHAR(191) DEFAULT NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `full_name`      VARCHAR(191) NOT NULL,
  `name_am`        VARCHAR(191) DEFAULT NULL,
  `phone`          VARCHAR(50)  DEFAULT NULL,
  `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0,
  `status`         ENUM('active','inactive','pending','suspended') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login_at`  DATETIME DEFAULT NULL,
  `last_login_ip`  VARCHAR(45) DEFAULT NULL,
  `login_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_institution` (`institution_id`),
  KEY `idx_users_department` (`department_id`),
  KEY `idx_users_status` (`status`),
  CONSTRAINT `fk_users_institution` FOREIGN KEY (`institution_id`)
    REFERENCES `institutions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`)
    REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. ROLES
--    scope_level tells the system the widest scope a role may grant:
--      global      -> Main Admin (all institutions)
--      institution -> Institution Admin (whole institution)
--      department  -> Department Admin / staff (one department sub-tree)
--    `institution_id` NULL means a reusable global template role.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`           VARCHAR(60)  NOT NULL,
  `name`           VARCHAR(191) NOT NULL,
  `name_am`        VARCHAR(191) DEFAULT NULL,
  `scope_level`    ENUM('global','institution','department') NOT NULL DEFAULT 'department',
  `institution_id` INT UNSIGNED DEFAULT NULL,
  `is_system`      TINYINT(1) NOT NULL DEFAULT 0,
  `description`    VARCHAR(255) DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Generated key so the UNIQUE index also protects global (NULL) templates:
  -- MySQL treats NULLs as distinct, IFNULL(...,0) closes that loophole.
  `institution_key` INT UNSIGNED AS (IFNULL(`institution_id`,0)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code_inst` (`code`,`institution_key`),
  KEY `idx_roles_scope` (`scope_level`),
  KEY `idx_roles_institution` (`institution_id`),
  CONSTRAINT `fk_roles_institution` FOREIGN KEY (`institution_id`)
    REFERENCES `institutions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. PERMISSIONS (atomic "verb" catalogue, grouped by module)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permissions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(80)  NOT NULL,
  `name`        VARCHAR(191) NOT NULL,
  `module`      VARCHAR(50)  NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`),
  KEY `idx_permissions_module` (`module`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `idx_rp_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. USER_ROLES  (the assignment that binds a scope to a user)
--    The heart of the multi-level model: the SAME role code can be granted
--    at different scopes, e.g. department_admin @ department 5.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `role_id`     INT UNSIGNED NOT NULL,
  `scope_type`  ENUM('global','institution','department') NOT NULL DEFAULT 'department',
  `scope_id`    INT UNSIGNED DEFAULT NULL,
  `assigned_by` INT UNSIGNED DEFAULT NULL,
  `expires_at`  DATETIME DEFAULT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Same trick as roles: keeps the unique index effective for global scope
  -- assignments where scope_id is NULL.
  `scope_key` INT UNSIGNED AS (IFNULL(`scope_id`,0)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_role_scope` (`user_id`,`role_id`,`scope_type`,`scope_key`),
  KEY `idx_ur_user` (`user_id`),
  KEY `idx_ur_role` (`role_id`),
  KEY `idx_ur_scope` (`scope_type`,`scope_id`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`)
    REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. AUDIT LOG + LOGIN ATTEMPTS
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED DEFAULT NULL,
  `username`       VARCHAR(60)  DEFAULT NULL,
  `action`         VARCHAR(80)  NOT NULL,
  `entity_type`    VARCHAR(60)  DEFAULT NULL,
  `entity_id`      INT UNSIGNED DEFAULT NULL,
  `institution_id` INT UNSIGNED DEFAULT NULL,
  `department_id`  INT UNSIGNED DEFAULT NULL,
  `scope_type`     ENUM('global','institution','department','none') NOT NULL DEFAULT 'none',
  `scope_id`       INT UNSIGNED DEFAULT NULL,
  `details`        TEXT DEFAULT NULL,
  `ip_address`     VARCHAR(45)  DEFAULT NULL,
  `user_agent`     VARCHAR(255) DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_institution` (`institution_id`),
  KEY `idx_audit_department` (`department_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`)
    REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`   VARCHAR(60)  NOT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `successful` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_login_username` (`username`,`created_at`),
  KEY `idx_login_ip` (`ip_address`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. REPORTING VIEWS
--    These views are scope-agnostic: the application still appends its
--    own WHERE clause, but they remove the need to repeat COUNT/join logic.
-- ---------------------------------------------------------------------

-- Per-institution roll-up (used when the Main Admin or an Institution
-- Admin asks for "aggregated reports"). One row per institution.
CREATE OR REPLACE VIEW `v_institution_reports` AS
SELECT
  i.`id`                AS `institution_id`,
  i.`code`,
  i.`name`,
  i.`type`,
  i.`status`,
  (SELECT COUNT(*) FROM `departments` d
     WHERE d.`institution_id` = i.`id` AND d.`status` = 'active')      AS `active_departments`,
  (SELECT COUNT(*) FROM `users` u
     WHERE u.`institution_id` = i.`id` AND u.`status` = 'active')      AS `active_users`,
  (SELECT COUNT(*) FROM `user_roles` ur
     JOIN `users` u2 ON u2.`id` = ur.`user_id`
    WHERE u2.`institution_id` = i.`id`)                                AS `role_assignments`
FROM `institutions` i;

-- Per-department roll-up. `path` is exposed so the caller can restrict
-- the result to a department sub-tree with a single LIKE clause.
CREATE OR REPLACE VIEW `v_department_reports` AS
SELECT
  d.`id`             AS `department_id`,
  d.`institution_id`,
  d.`parent_id`,
  d.`code`,
  d.`name`,
  d.`type`,
  d.`depth`,
  d.`path`,
  d.`status`,
  p.`name`           AS `parent_name`,
  i.`name`           AS `institution_name`,
  (SELECT COUNT(*) FROM `departments` c WHERE c.`parent_id` = d.`id`)  AS `child_departments`,
  (SELECT COUNT(*) FROM `users` u
     WHERE u.`department_id` = d.`id` AND u.`status` = 'active')      AS `active_members`
FROM `departments` d
LEFT JOIN `departments` p ON p.`id` = d.`parent_id`
LEFT JOIN `institutions` i ON i.`id` = d.`institution_id`;

-- Effective access of every user: one row per role assignment, with the
-- human-readable scope target name. Handy for the audit screen.
CREATE OR REPLACE VIEW `v_user_access` AS
SELECT
  ur.`id`            AS `assignment_id`,
  u.`id`             AS `user_id`,
  u.`username`,
  u.`full_name`,
  u.`is_super_admin`,
  r.`id`             AS `role_id`,
  r.`code`           AS `role_code`,
  r.`name`           AS `role_name`,
  r.`scope_level`,
  ur.`scope_type`,
  ur.`scope_id`,
  CASE ur.`scope_type`
    WHEN 'department'  THEN (SELECT d.`name` FROM `departments` d  WHERE d.`id` = ur.`scope_id`)
    WHEN 'institution' THEN (SELECT i.`name` FROM `institutions` i WHERE i.`id` = ur.`scope_id`)
    ELSE 'Global'
  END                AS `scope_name`
FROM `user_roles` ur
JOIN `users` u ON u.`id` = ur.`user_id`
JOIN `roles` r ON r.`id` = ur.`role_id`;

-- =====================================================================
-- 9. SEED DATA - PERMISSION CATALOGUE
--    Re-runnable: INSERT IGNORE keeps existing rows untouched.
-- =====================================================================
INSERT IGNORE INTO `permissions` (`code`,`name`,`module`,`description`) VALUES
-- Institution / organisation management
('institution.view',        'View institutions',              'institution', 'List and open institutions'),
('institution.create',      'Create institutions',            'institution', 'Create a new institution (Main Admin only)'),
('institution.update',      'Update institutions',            'institution', 'Edit institution details'),
('institution.delete',      'Delete institutions',            'institution', 'Remove an institution'),
('institution.assign_admin','Assign institution admin',       'institution', 'Bind an Institution Admin to an institution'),
-- Department / division management
('department.view',         'View departments',               'department',  'List departments inside the allowed scope'),
('department.create',       'Create departments',             'department',  'Create departments / divisions / teams'),
('department.update',       'Update departments',             'department',  'Edit a department'),
('department.delete',       'Delete departments',             'department',  'Remove a department'),
('department.assign_admin', 'Assign department admin',        'department',  'Bind a Department Admin to a department'),
-- Users
('user.view',               'View users',                     'user',        'List users inside the allowed scope'),
('user.create',             'Create users',                   'user',        'Create a user account'),
('user.update',             'Update users',                   'user',        'Edit a user account'),
('user.delete',             'Delete users',                   'user',        'Remove a user account'),
('user.assign_role',        'Assign roles to users',          'user',        'Grant / revoke a scoped role'),
-- Roles & permissions
('role.view',               'View roles',                     'role',        'List roles'),
('role.create',             'Create roles',                   'role',        'Create a custom role'),
('role.update',             'Update roles',                   'role',        'Edit a role'),
('role.delete',             'Delete roles',                   'role',        'Remove a custom role'),
('role.assign_permission',  'Assign permissions to roles',    'role',        'Attach / detach permissions on a role'),
('permission.view',         'View permission catalogue',      'permission',  'Read the list of permissions'),
-- Audit
('audit.view',              'View audit trail',               'audit',       'Read audit log entries'),
-- Reports
('report.view',             'View own-scope reports',         'report',      'Reports restricted to the current scope'),
('report.view_aggregate',   'View aggregated reports',        'report',      'Institution-wide / cross-department reports'),
('report.export',           'Export reports',                 'report',      'Download reports as CSV/PDF'),
-- Core modules
('members.view',            'View members',                   'members',     'Sabbath school member register'),
('members.create',          'Create members',                 'members',     NULL),
('members.update',          'Update members',                 'members',     NULL),
('members.delete',          'Delete members',                 'members',     NULL),
('attendance.view',         'View attendance',                'attendance',  NULL),
('attendance.create',       'Record attendance',              'attendance',  NULL),
('attendance.update',       'Update attendance',              'attendance',  NULL),
('attendance.delete',       'Delete attendance',              'attendance',  NULL),
('finance.view',            'View finance',                   'finance',     NULL),
('finance.create',          'Record finance entries',         'finance',     NULL),
('finance.update',          'Update finance entries',         'finance',     NULL),
('finance.delete',          'Delete finance entries',         'finance',     NULL),
('finance.approve',         'Approve finance entries',        'finance',     NULL);

INSERT IGNORE INTO `permissions` (`code`,`name`,`module`,`description`) VALUES
('library.view',                  'View library',                 'library',    'Library catalogue and loans'),
('library.create',                'Create library records',       'library',    NULL),
('library.update',                'Update library records',       'library',    NULL),
('library.delete',                'Delete library records',       'library',    NULL),
('property.view',                 'View property',                'property',   'Assets / property register'),
('property.create',               'Create property records',      'property',   NULL),
('property.update',               'Update property records',      'property',   NULL),
('property.delete',               'Delete property records',      'property',   NULL),
('book_sales.view',               'View book sales',              'book_sales', 'Literature / book sales'),
('book_sales.create',             'Record book sales',            'book_sales', NULL),
('book_sales.update',             'Update book sales',            'book_sales', NULL),
('book_sales.delete',             'Delete book sales',            'book_sales', NULL),
-- Academics
('academics.view',                'View academics module',        'academics',  'Open the academics section'),
('academics.setup',               'Run academic setup',           'academics',  'Configure the academic module'),
('academics.year.manage',         'Manage academic years',        'academics',  NULL),
('academics.level.manage',        'Manage levels',                'academics',  NULL),
('academics.classroom.manage',    'Manage classrooms',            'academics',  NULL),
('academics.course.manage',       'Manage courses',               'academics',  NULL),
('academics.department.manage',   'Manage academic departments',  'academics',  NULL),
('academics.teacher.assign',      'Assign teachers',              'academics',  NULL),
('academics.student.view',        'View students',                'academics',  NULL),
('academics.student.create',      'Enrol / create students',      'academics',  NULL),
('academics.student.update',      'Update students',              'academics',  NULL),
('academics.student.delete',      'Delete students',              'academics',  NULL),
('academics.enrollment.manage',   'Manage enrolment',             'academics',  NULL),
('academics.assessment.manage',   'Manage assessments',           'academics',  NULL),
('academics.grade.manage',        'Manage grades',                'academics',  NULL),
('academics.certificate.manage',  'Manage certificates',          'academics',  NULL),
('academics.reportcard.view',     'View report cards',            'academics',  NULL);

-- =====================================================================
-- 10. SEED DATA - SYSTEM ROLES
--     scope_level caps how wide an assignment of this role may be.
-- =====================================================================
INSERT IGNORE INTO `roles`
  (`code`,`name`,`name_am`,`scope_level`,`institution_id`,`is_system`,`description`) VALUES
('main_admin',           'Main System Admin',   'ዋና ሲስተም አድሚን',        'global',      NULL, 1, 'Full control of every institution'),
('institution_admin',    'Institution Admin',   'የተቋም አድሚን',          'institution', NULL, 1, 'Manages one institution and all of its departments'),
('department_admin',     'Department Admin',    'የክፍል አድሚን',          'department',  NULL, 1, 'Manages one department and its sub-departments'),
('department_supervisor','Department Supervisor','የክፍል ተቆጣጣሪ',        'department',  NULL, 1, 'Assists the department admin, read + limited write'),
('teacher',              'Teacher',             'አስተማሪ',              'department',  NULL, 1, 'Academics: assessments and grades'),
('secretary',            'Secretary',           'ጸሐፊ',                'department',  NULL, 1, 'Members and attendance records'),
('treasurer',            'Treasurer',           'ገንዘብ ያዥ',            'department',  NULL, 1, 'Finance and book sales'),
('librarian',            'Librarian',           'ቤተ መጻሕፍት ሰራተኛ',      'department',  NULL, 1, 'Library records'),
('member',               'Member',              'አባል',                'department',  NULL, 1, 'Read-only access to the own department');

-- =====================================================================
-- 11. SEED DATA - ROLE -> PERMISSION MATRIX
--     Assignment is by (role code, module) or explicit permission codes so
--     the file stays readable and easy to extend.
-- =====================================================================

-- Main Admin: every permission there is.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.`code` = 'main_admin' AND r.`institution_id` IS NULL;

-- Institution Admin: everything except creating/deleting institutions.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r CROSS JOIN `permissions` p
WHERE r.`code` = 'institution_admin' AND r.`institution_id` IS NULL
  AND p.`code` NOT IN ('institution.create','institution.delete');

-- Department Admin: full control of the business modules + scoped admin.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p
  ON p.`module` IN ('members','attendance','finance','library','property','book_sales','academics')
  OR p.`code` IN (
      'department.view','department.create','department.update','department.delete','department.assign_admin',
      'user.view','user.create','user.update','user.delete','user.assign_role',
      'role.view','role.create','role.assign_permission',
      'report.view','report.view_aggregate','audit.view'
  )
WHERE r.`code` = 'department_admin' AND r.`institution_id` IS NULL;

-- Department Supervisor: read-mostly inside a department sub-tree.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p
  ON p.`code` LIKE '%.view'
  OR p.`code` IN ('department.create','members.create','attendance.create','academics.grade.manage')
WHERE r.`code` = 'department_supervisor' AND r.`institution_id` IS NULL;

-- Teacher
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p
  ON p.`code` IN (
      'academics.view','academics.student.view','academics.assessment.manage','academics.grade.manage',
      'academics.reportcard.view','academics.enrollment.manage',
      'attendance.view','attendance.create','members.view','department.view','report.view'
  )
WHERE r.`code` = 'teacher' AND r.`institution_id` IS NULL;

-- Secretary: members + attendance modules
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p
  ON p.`module` IN ('members','attendance') OR p.`code` IN ('academics.student.view','department.view','report.view')
WHERE r.`code` = 'secretary' AND r.`institution_id` IS NULL;

-- Treasurer: finance + book sales modules
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p
  ON p.`module` IN ('finance','book_sales') OR p.`code` IN ('department.view','report.view','report.export')
WHERE r.`code` = 'treasurer' AND r.`institution_id` IS NULL;

-- Librarian: library module
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p
  ON p.`module` = 'library' OR p.`code` IN ('department.view','report.view')
WHERE r.`code` = 'librarian' AND r.`institution_id` IS NULL;

-- Member: read-only inside the own department.
INSERT IGNORE INTO `role_permissions` (`role_id`,`permission_id`)
SELECT r.`id`, p.`id`
FROM `roles` r JOIN `permissions` p
  ON p.`code` LIKE '%.view'
  AND p.`module` IN ('members','attendance','academics','report','department')
WHERE r.`code` = 'member' AND r.`institution_id` IS NULL;

-- =====================================================================
-- 12. BUSINESS MODULE TABLES
--     Every table carries `institution_id` + `department_id`. Those two
--     columns are the anchor points the Scope class filters on, so data
--     isolation is enforced at the row level for every module.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `members` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `member_code`    VARCHAR(40) NOT NULL,
  `full_name`      VARCHAR(191) NOT NULL,
  `gender`         ENUM('male','female','other') DEFAULT NULL,
  `phone`          VARCHAR(50) DEFAULT NULL,
  `birth_date`     DATE DEFAULT NULL,
  `joined_on`      DATE DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_members_code` (`institution_id`,`member_code`),
  KEY `idx_members_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_members_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_members_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `attendance_records` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `member_id`      INT UNSIGNED DEFAULT NULL,
  `service_date`   DATE NOT NULL,
  `service_type`   ENUM('sabbath_school','divine_service','prayer_meeting','other') NOT NULL DEFAULT 'sabbath_school',
  `present`        TINYINT(1) NOT NULL DEFAULT 1,
  `note`           VARCHAR(255) DEFAULT NULL,
  `recorded_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attendance_scope` (`institution_id`,`department_id`),
  KEY `idx_attendance_date` (`service_date`),
  CONSTRAINT `fk_attendance_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_member`      FOREIGN KEY (`member_id`)      REFERENCES `members`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `finance_entries` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `entry_date`     DATE NOT NULL,
  `type`           ENUM('income','expense') NOT NULL DEFAULT 'income',
  `category`       VARCHAR(80) NOT NULL,
  `amount`         DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `description`    VARCHAR(255) DEFAULT NULL,
  `status`         ENUM('draft','approved') NOT NULL DEFAULT 'draft',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `approved_by`    INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_finance_scope` (`institution_id`,`department_id`),
  KEY `idx_finance_date` (`entry_date`),
  CONSTRAINT `fk_finance_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_finance_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `library_items` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `title`          VARCHAR(191) NOT NULL,
  `author`         VARCHAR(191) DEFAULT NULL,
  `item_type`      ENUM('book','magazine','dvd','other') NOT NULL DEFAULT 'book',
  `copies`         INT UNSIGNED NOT NULL DEFAULT 1,
  `available`      INT UNSIGNED NOT NULL DEFAULT 1,
  `status`         ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_library_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_library_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_library_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `property_assets` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `name`           VARCHAR(191) NOT NULL,
  `category`       VARCHAR(80) DEFAULT NULL,
  `quantity`       INT UNSIGNED NOT NULL DEFAULT 1,
  `asset_condition` ENUM('new','good','fair','poor','damaged') NOT NULL DEFAULT 'good',
  `acquired_date`  DATE DEFAULT NULL,
  `value`          DECIMAL(14,2) DEFAULT NULL,
  `status`         ENUM('in_use','stored','disposed') NOT NULL DEFAULT 'in_use',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_property_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_property_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_property_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `book_sales` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `sale_date`      DATE NOT NULL,
  `title`          VARCHAR(191) NOT NULL,
  `quantity`       INT UNSIGNED NOT NULL DEFAULT 1,
  `unit_price`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_amount`   DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  `buyer_name`     VARCHAR(191) DEFAULT NULL,
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sales_scope` (`institution_id`,`department_id`),
  KEY `idx_sales_date` (`sale_date`),
  CONSTRAINT `fk_sales_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sales_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 13. ACADEMICS MODULE TABLES
--     Same scoping contract as the business modules: institution_id +
--     department_id on every row.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `academic_years` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `name`           VARCHAR(80) NOT NULL,
  `start_date`     DATE DEFAULT NULL,
  `end_date`       DATE DEFAULT NULL,
  `is_current`     TINYINT(1) NOT NULL DEFAULT 0,
  `status`         ENUM('active','closed') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_academic_year` (`institution_id`,`department_id`,`name`),
  KEY `idx_ay_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_ay_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ay_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `levels` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `code`           VARCHAR(40) NOT NULL,
  `name`           VARCHAR(191) NOT NULL,
  `sort_order`     INT NOT NULL DEFAULT 0,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_levels_code` (`institution_id`,`code`),
  KEY `idx_levels_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_levels_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_levels_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `classrooms` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `level_id`       INT UNSIGNED DEFAULT NULL,
  `name`           VARCHAR(191) NOT NULL,
  `capacity`       INT UNSIGNED NOT NULL DEFAULT 0,
  `room`           VARCHAR(60) DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_classrooms_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_classrooms_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_classrooms_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_classrooms_level`       FOREIGN KEY (`level_id`)       REFERENCES `levels`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `courses` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `level_id`       INT UNSIGNED DEFAULT NULL,
  `code`           VARCHAR(40) NOT NULL,
  `title`          VARCHAR(191) NOT NULL,
  `credit_hours`   DECIMAL(4,1) NOT NULL DEFAULT 0.0,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_courses_code` (`institution_id`,`code`),
  KEY `idx_courses_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_courses_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_courses_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_courses_level`       FOREIGN KEY (`level_id`)       REFERENCES `levels`       (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `academic_departments` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `code`           VARCHAR(40) NOT NULL,
  `name`           VARCHAR(191) NOT NULL,
  `head_user_id`   INT UNSIGNED DEFAULT NULL,
  `status`         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_acad_dept_code` (`institution_id`,`code`),
  KEY `idx_acad_dept_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_acad_dept_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_acad_dept_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `students` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `level_id`       INT UNSIGNED DEFAULT NULL,
  `classroom_id`   INT UNSIGNED DEFAULT NULL,
  `student_code`   VARCHAR(40) NOT NULL,
  `full_name`      VARCHAR(191) NOT NULL,
  `gender`         ENUM('male','female','other') DEFAULT NULL,
  `birth_date`     DATE DEFAULT NULL,
  `guardian_name`  VARCHAR(191) DEFAULT NULL,
  `guardian_phone` VARCHAR(50) DEFAULT NULL,
  `enrolled_on`    DATE DEFAULT NULL,
  `status`         ENUM('active','graduated','withdrawn') NOT NULL DEFAULT 'active',
  `created_by`     INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_students_code` (`institution_id`,`student_code`),
  KEY `idx_students_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_students_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_students_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_students_level`       FOREIGN KEY (`level_id`)       REFERENCES `levels`       (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_students_classroom`   FOREIGN KEY (`classroom_id`)   REFERENCES `classrooms`   (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `teacher_assignments` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id`   INT UNSIGNED NOT NULL,
  `department_id`    INT UNSIGNED NOT NULL,
  `teacher_id`       INT UNSIGNED NOT NULL,
  `course_id`        INT UNSIGNED DEFAULT NULL,
  `classroom_id`     INT UNSIGNED DEFAULT NULL,
  `academic_year_id` INT UNSIGNED DEFAULT NULL,
  `status`           ENUM('active','ended') NOT NULL DEFAULT 'active',
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ta_scope` (`institution_id`,`department_id`),
  KEY `idx_ta_teacher` (`teacher_id`),
  CONSTRAINT `fk_ta_institution` FOREIGN KEY (`institution_id`)   REFERENCES `institutions`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ta_department`  FOREIGN KEY (`department_id`)    REFERENCES `departments`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ta_teacher`     FOREIGN KEY (`teacher_id`)       REFERENCES `users`          (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ta_course`      FOREIGN KEY (`course_id`)        REFERENCES `courses`        (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ta_classroom`   FOREIGN KEY (`classroom_id`)     REFERENCES `classrooms`     (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ta_year`        FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `assessments` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id`   INT UNSIGNED NOT NULL,
  `department_id`    INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED DEFAULT NULL,
  `course_id`        INT UNSIGNED DEFAULT NULL,
  `classroom_id`     INT UNSIGNED DEFAULT NULL,
  `title`            VARCHAR(191) NOT NULL,
  `assessment_type`  ENUM('quiz','midterm','final','assignment','project') NOT NULL DEFAULT 'quiz',
  `max_score`        DECIMAL(6,2) NOT NULL DEFAULT 100.00,
  `weight`           DECIMAL(5,2) NOT NULL DEFAULT 100.00,
  `assessment_date`  DATE DEFAULT NULL,
  `status`           ENUM('open','closed') NOT NULL DEFAULT 'open',
  `created_by`       INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_assess_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_assess_institution` FOREIGN KEY (`institution_id`)   REFERENCES `institutions`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assess_department`  FOREIGN KEY (`department_id`)    REFERENCES `departments`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assess_year`        FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assess_course`      FOREIGN KEY (`course_id`)        REFERENCES `courses`        (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_assess_classroom`   FOREIGN KEY (`classroom_id`)     REFERENCES `classrooms`     (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `grades` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id` INT UNSIGNED NOT NULL,
  `department_id`  INT UNSIGNED NOT NULL,
  `assessment_id`  INT UNSIGNED NOT NULL,
  `student_id`     INT UNSIGNED NOT NULL,
  `score`          DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  `letter_grade`   VARCHAR(5) DEFAULT NULL,
  `remark`         VARCHAR(255) DEFAULT NULL,
  `graded_by`      INT UNSIGNED DEFAULT NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade` (`assessment_id`,`student_id`),
  KEY `idx_grades_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_grades_institution` FOREIGN KEY (`institution_id`) REFERENCES `institutions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grades_department`  FOREIGN KEY (`department_id`)  REFERENCES `departments`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grades_assessment`  FOREIGN KEY (`assessment_id`)  REFERENCES `assessments`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grades_student`     FOREIGN KEY (`student_id`)     REFERENCES `students`     (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `certificates` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `institution_id`   INT UNSIGNED NOT NULL,
  `department_id`    INT UNSIGNED NOT NULL,
  `student_id`       INT UNSIGNED NOT NULL,
  `academic_year_id` INT UNSIGNED DEFAULT NULL,
  `certificate_type` ENUM('completion','promotion','excellence','participation') NOT NULL DEFAULT 'completion',
  `title`            VARCHAR(191) NOT NULL,
  `serial_no`        VARCHAR(60) NOT NULL,
  `issue_date`       DATE NOT NULL,
  `status`           ENUM('draft','issued','revoked') NOT NULL DEFAULT 'draft',
  `issued_by`        INT UNSIGNED DEFAULT NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_certificates_serial` (`institution_id`,`serial_no`),
  KEY `idx_cert_scope` (`institution_id`,`department_id`),
  CONSTRAINT `fk_cert_institution` FOREIGN KEY (`institution_id`)   REFERENCES `institutions`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cert_department`  FOREIGN KEY (`department_id`)    REFERENCES `departments`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cert_student`     FOREIGN KEY (`student_id`)       REFERENCES `students`       (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cert_year`        FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- 14. DEMO DATA
--     Two institutions, four departments (incl. one sub-department) and
--     six users so the isolation rules can be demonstrated immediately.
--     Default passwords:
--       main_admin / bole_admin / hawassa_admin  -> Admin@123
--       ss_admin / youth_admin / primary_teacher -> Senbet@123
--     >>> CHANGE THESE BEFORE GOING LIVE <<<
-- =====================================================================
INSERT IGNORE INTO `institutions` (`id`,`code`,`name`,`name_am`,`type`,`status`) VALUES
(1, 'BOLE-SDA',    'Bole SDA Church',    'ቦሌ ሰባተኛው ቀን ቤተ ክርስቲያን', 'church', 'active'),
(2, 'HAWASSA-SDA', 'Hawassa SDA Church', 'ሀዋሳ ሰባተኛው ቀን ቤተ ክርስቲያን', 'church', 'active');

INSERT IGNORE INTO `departments`
  (`id`,`institution_id`,`parent_id`,`code`,`name`,`type`,`depth`,`path`,`status`) VALUES
(1, 1, NULL, 'SS',          'Sabbath School',   'department',     0, '/1/',   'active'),
(2, 1, NULL, 'YTH',         'Youth Ministry',   'department',     0, '/2/',   'active'),
(3, 1, 1,    'SS-PRIMARY',  'Primary Division', 'sub_department', 1, '/1/3/', 'active'),
(4, 2, NULL, 'SS',          'Sabbath School',   'department',     0, '/4/',   'active');

INSERT IGNORE INTO `users`
  (`id`,`institution_id`,`department_id`,`username`,`email`,`password_hash`,`full_name`,`name_am`,`is_super_admin`,`status`) VALUES
(1, NULL, NULL, 'main_admin',      'main@senbet.local',      '$2y$10$..cQtLp9BPzsvlY2cmfAluRgzh9ERKGsCaT.Nlkr1WJjSuGr/io.2', 'Main System Admin', 'ዋና ሲስተም አድሚን', 1, 'active'),
(2, 1,    NULL, 'bole_admin',      'bole@senbet.local',      '$2y$10$..cQtLp9BPzsvlY2cmfAluRgzh9ERKGsCaT.Nlkr1WJjSuGr/io.2', 'Bole Institution Admin', 'የቦሌ ተቋም አድሚን', 0, 'active'),
(3, 2,    NULL, 'hawassa_admin',   'hawassa@senbet.local',   '$2y$10$..cQtLp9BPzsvlY2cmfAluRgzh9ERKGsCaT.Nlkr1WJjSuGr/io.2', 'Hawassa Institution Admin', 'የሀዋሳ ተቋም አድሚን', 0, 'active'),
(4, 1,    1,    'ss_admin',        'ss@senbet.local',        '$2y$10$U2RQjofztCYgeXropPQSzev1AR6afyCB2.jZ4eNof/qhV6YgQDLCG', 'Sabbath School Admin', 'የሰንበት ትምህርት ቤት አድሚን', 0, 'active'),
(5, 1,    2,    'youth_admin',     'youth@senbet.local',     '$2y$10$U2RQjofztCYgeXropPQSzev1AR6afyCB2.jZ4eNof/qhV6YgQDLCG', 'Youth Ministry Admin', 'የወጣቶች ክፍል አድሚን', 0, 'active'),
(6, 1,    3,    'primary_teacher', 'teacher@senbet.local',   '$2y$10$U2RQjofztCYgeXropPQSzev1AR6afyCB2.jZ4eNof/qhV6YgQDLCG', 'Primary Teacher', 'የመጀመሪያ ደረጃ አስተማሪ', 0, 'active');

-- Role assignments. The scope_type / scope_id pair is what makes the
-- same role behave differently for each user:
--   main_admin     -> global      (no scope_id)
--   *_admin        -> institution (scope_id = institution id)
--   department admins / staff -> department (scope_id = department id)
INSERT IGNORE INTO `user_roles` (`user_id`,`role_id`,`scope_type`,`scope_id`)
SELECT u.`id`, r.`id`, m.`scope_type`, m.`scope_id`
FROM (
  SELECT 'main_admin'      AS `username`, 'main_admin'        AS `role_code`, 'global'      AS `scope_type`, NULL AS `scope_id`
  UNION ALL SELECT 'bole_admin',      'institution_admin',  'institution', 1
  UNION ALL SELECT 'hawassa_admin',   'institution_admin',  'institution', 2
  UNION ALL SELECT 'ss_admin',        'department_admin',   'department',  1
  UNION ALL SELECT 'youth_admin',     'department_admin',   'department',  2
  UNION ALL SELECT 'primary_teacher', 'teacher',            'department',  3
) m
JOIN `users` u ON u.`username` = m.`username`
JOIN `roles` r ON r.`code` = m.`role_code` AND r.`institution_id` IS NULL;

-- A little sample business data so the scope filtering is visible.
INSERT IGNORE INTO `members`
  (`id`,`institution_id`,`department_id`,`member_code`,`full_name`,`gender`,`status`) VALUES
(1, 1, 1, 'SS-001', 'Abebe Kebede',    'male',   'active'),
(2, 1, 1, 'SS-002', 'Hanna Tesfaye',   'female', 'active'),
(3, 1, 3, 'PR-001', 'Samuel Girma',    'male',   'active'),
(4, 1, 2, 'YT-001', 'Meron Alemu',     'female', 'active'),
(5, 2, 4, 'HW-001', 'Dawit Bekele',    'male',   'active');

INSERT IGNORE INTO `finance_entries`
  (`id`,`institution_id`,`department_id`,`entry_date`,`type`,`category`,`amount`,`description`,`status`) VALUES
(1, 1, 1, '2026-01-10', 'income',  'Offering',      1500.00, 'Weekly Sabbath school offering', 'approved'),
(2, 1, 1, '2026-01-17', 'expense', 'Teaching aids',  350.00, 'Chalk and charts',               'approved'),
(3, 1, 2, '2026-01-17', 'income',  'Donation',       800.00, 'Youth ministry donation',        'draft'),
(4, 2, 4, '2026-01-17', 'income',  'Offering',       600.00, 'Weekly offering',                'approved');

INSERT IGNORE INTO `academic_years`
  (`id`,`institution_id`,`department_id`,`name`,`start_date`,`end_date`,`is_current`,`status`) VALUES
(1, 1, 1, '2025/2026', '2025-09-01', '2026-06-30', 1, 'active'),
(2, 1, 3, '2025/2026', '2025-09-01', '2026-06-30', 1, 'active');

INSERT IGNORE INTO `levels`
  (`id`,`institution_id`,`department_id`,`code`,`name`,`sort_order`,`status`) VALUES
(1, 1, 1, 'L1', 'Beginner',     1, 'active'),
(2, 1, 1, 'L2', 'Primary',      2, 'active'),
(3, 1, 1, 'L3', 'Junior',       3, 'active');

INSERT IGNORE INTO `classrooms`
  (`id`,`institution_id`,`department_id`,`level_id`,`name`,`capacity`,`room`,`status`) VALUES
(1, 1, 1, 1, 'Beginner Class', 30, 'Room 1', 'active'),
(2, 1, 3, 2, 'Primary Class',  40, 'Room 2', 'active');

INSERT IGNORE INTO `students`
  (`id`,`institution_id`,`department_id`,`level_id`,`classroom_id`,`student_code`,`full_name`,`gender`,`status`) VALUES
(1, 1, 1, 1, 1, 'STU-001', 'Liya Tadesse', 'female', 'active'),
(2, 1, 1, 1, 1, 'STU-002', 'Yonas Hailu',  'male',   'active'),
(3, 1, 3, 2, 2, 'STU-003', 'Bethel Getu',  'female', 'active');

-- =====================================================================
-- End of schema.
--
-- Quick verification queries (see tests/scope_test.php for the PHP version):
--
--   -- 1. Institution Admin (bole_admin, institution 1) sees BOTH departments:
--   SELECT d.* FROM departments d WHERE d.institution_id = 1;
--
--   -- 2. Department Admin (ss_admin, department 1) sees only its sub-tree:
--   SELECT d.* FROM departments d
--    WHERE d.institution_id = 1 AND d.path LIKE '/1/%';
--
--   -- 3. Aggregated report for institution 1:
--   SELECT * FROM v_institution_reports WHERE institution_id = 1;
--
--   -- 4. Effective access of every user:
--   SELECT * FROM v_user_access ORDER BY user_id;
-- =====================================================================














