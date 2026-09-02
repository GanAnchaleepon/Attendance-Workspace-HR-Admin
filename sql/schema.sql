-- ============================================================================
-- ระบบตรวจสอบข้อมูลเวลาการทำงานเบื้องต้น
-- Database schema (MySQL 5.7+ / MariaDB 10.3+ ตามที่ Hostinger ให้บริการ)
-- นำไฟล์นี้ไปรันใน phpMyAdmin ของ Hostinger (หรือ mysql client) ครั้งแรกที่ตั้งระบบ
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';

-- ----------------------------------------------------------------------------
-- ผู้ใช้งานระบบ (ผู้ดูแลระบบ/ทีมงาน) - เข้าสู่ระบบด้วย username/password
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username        VARCHAR(50)  NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(100) NULL,
    failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
    locked_until    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- โปรเจค/ไซต์งาน เช่น TTV FTM-SE, TTV AAT-SE (เผื่อขยายในอนาคต)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS projects (
    code        VARCHAR(20) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO projects (code, name, is_active) VALUES
    ('FTM-SE', 'TTV FTM-SE', 1),
    ('AAT-SE', 'TTV AAT-SE', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ----------------------------------------------------------------------------
-- ประวัติการอัปโหลดไฟล์ Manpower / Manpower QA แต่ละโปรเจค
-- ใช้เพื่อเทียบว่าไฟล์ใหม่ที่อัปโหลดมามีการเปลี่ยนแปลงจากครั้งก่อนหรือไม่ (content_hash)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS manpower_import_batches (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_code      VARCHAR(20) NOT NULL,
    list_type         ENUM('manpower','qa') NOT NULL,
    original_filename VARCHAR(255) NULL,
    content_hash      CHAR(64) NOT NULL,
    employee_count    INT UNSIGNED NOT NULL DEFAULT 0,
    imported_by       INT UNSIGNED NULL,
    imported_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_project_list (project_code, list_type, imported_at),
    CONSTRAINT fk_batch_project FOREIGN KEY (project_code) REFERENCES projects(code)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_batch_user FOREIGN KEY (imported_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- รายชื่อพนักงานปัจจุบัน (Manpower / Manpower QA) ต่อโปรเจค
-- ทุกครั้งที่อัปไฟล์ใหม่และพบว่าข้อมูลเปลี่ยน ระบบจะ "แทนที่ทั้งหมด" ของ project_code + list_type นั้น
-- (ยึดตามไฟล์ล่าสุดเสมอ คนที่ออกแล้วจะถูกลบออกจากตารางนี้โดยอัตโนมัติ)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS employees (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_code   VARCHAR(20) NOT NULL,
    project_code    VARCHAR(20) NOT NULL,
    list_type       ENUM('manpower','qa') NOT NULL,
    sort_order      INT UNSIGNED NULL,
    prefix          VARCHAR(20)  NULL,
    first_name_th   VARCHAR(100) NULL,
    last_name_th    VARCHAR(100) NULL,
    first_name_en   VARCHAR(100) NULL,
    last_name_en    VARCHAR(100) NULL,
    position_name   VARCHAR(150) NULL,
    department      VARCHAR(100) NULL,
    shift_code      VARCHAR(20)  NULL,
    employee_type   VARCHAR(30)  NULL,
    start_date_text VARCHAR(50)  NULL,
    remark          VARCHAR(500) NULL,
    batch_id        INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_employee_code (employee_code),
    KEY idx_project_list (project_code, list_type, sort_order),
    CONSTRAINT fk_employee_project FOREIGN KEY (project_code) REFERENCES projects(code)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_employee_batch FOREIGN KEY (batch_id) REFERENCES manpower_import_batches(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- ข้อมูลดิบจากไฟล์สแกนลายนิ้วมือ (เก็บทุกแถวที่นำเข้า กันการนำเข้าซ้ำด้วย unique key)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_scans (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_code  VARCHAR(20) NOT NULL,
    employee_code VARCHAR(20) NOT NULL,
    scan_name     VARCHAR(150) NULL,
    scan_time     DATETIME NOT NULL,
    source_file   VARCHAR(255) NULL,
    imported_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_scan (project_code, employee_code, scan_time),
    KEY idx_employee_time (employee_code, scan_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- Session การทำงานที่ประมวลผลแล้ว (กลุ่มสแกนเข้า-ออกต่อกะ) พร้อมผลตรวจ OT
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance_sessions (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_code   VARCHAR(20) NOT NULL,
    project_code    VARCHAR(20) NOT NULL,
    work_date       DATE NOT NULL,
    shift_type      ENUM('day','night','unknown') NOT NULL DEFAULT 'unknown',
    expected_start  DATETIME NULL,
    expected_end    DATETIME NULL,
    check_in        DATETIME NULL,
    check_out       DATETIME NULL,
    scan_count      INT UNSIGNED NOT NULL DEFAULT 0,
    ot_flag         TINYINT(1) NOT NULL DEFAULT 0,
    ot_minutes      INT NOT NULL DEFAULT 0,
    incomplete_flag TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('pending','reviewed') NOT NULL DEFAULT 'pending',
    reviewer_note   VARCHAR(255) NULL,
    reviewed_by     INT UNSIGNED NULL,
    reviewed_at     DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_session (employee_code, work_date, shift_type),
    KEY idx_project_date (project_code, work_date),
    KEY idx_ot_flag (project_code, ot_flag),
    CONSTRAINT fk_session_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- HR ระบุเองว่ากะ A/B ไหนเข้าเช้าในสัปดาห์แรกของแต่ละเดือน (ปฏิทินสัปดาห์ จันทร์-อาทิตย์)
-- สัปดาห์ถัดไปในเดือนเดียวกันสลับ A/B ให้อัตโนมัติ ใช้แทนการเดาจากข้อมูลสแกนเมื่อมีการตั้งค่าไว้
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS shift_month_settings (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_code          VARCHAR(20) NOT NULL,
    month                 CHAR(7) NOT NULL,
    first_week_day_shift  ENUM('A','B') NOT NULL,
    updated_by            INT UNSIGNED NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_project_month (project_code, month),
    CONSTRAINT fk_shift_setting_project FOREIGN KEY (project_code) REFERENCES projects(code)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_shift_setting_user FOREIGN KEY (updated_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
