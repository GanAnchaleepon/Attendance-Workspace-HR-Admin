-- ============================================================================
-- Migration: เพิ่มตาราง shift_month_settings
-- ใช้เก็บ "กะไหนเข้าเช้าในสัปดาห์แรกของเดือน" ที่ HR ระบุเองต่อโปรเจค+เดือน (ปฏิทินสัปดาห์ จันทร์-อาทิตย์)
-- สัปดาห์ถัดไปในเดือนเดียวกันจะสลับ A/B ให้อัตโนมัติ ไม่ต้องระบุทุกสัปดาห์
-- รันไฟล์นี้ผ่าน phpMyAdmin ครั้งเดียวถ้าฐานข้อมูลสร้างมาก่อนหน้านี้
-- ============================================================================

CREATE TABLE IF NOT EXISTS shift_month_settings (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_code          VARCHAR(20) NOT NULL,
    month                 CHAR(7) NOT NULL, -- รูปแบบ 'YYYY-MM'
    first_week_day_shift  ENUM('A','B') NOT NULL, -- กะที่เข้ากะเช้าในสัปดาห์แรกของเดือนนี้
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
