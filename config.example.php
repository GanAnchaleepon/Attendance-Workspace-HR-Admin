<?php
/**
 * คัดลอกไฟล์นี้เป็น config.php แล้วกรอกค่าจริงของฐานข้อมูล Hostinger
 * ห้าม commit / อัปโหลดไฟล์ config.php ที่มีรหัสผ่านจริงขึ้น public repository
 *
 * config.php ต้องวางไว้ "นอก" โฟลเดอร์ public_html เสมอ (ดูรายละเอียดใน README.md)
 */

return [
    'db' => [
        'host'     => '127.0.0.1',      // Hostinger: มักเป็น localhost หรือชื่อ host ที่ระบบให้มา
        'port'     => 3306,
        'name'     => 'u123456789_attendance',
        'user'     => 'u123456789_appuser',
        'password' => 'CHANGE_ME',
        'charset'  => 'utf8mb4',
    ],

    // ตั้งค่าเวลาเข้า-ออกงานตามกะ (นาทีที่ยอมผ่อนผันก่อนจะถือว่าเป็น OT)
    'attendance' => [
        'day_shift_start'   => '08:00',
        'day_shift_end'     => '17:30',
        'night_shift_start' => '22:30',
        'night_shift_end'   => '08:00', // ของวันถัดไป
        'day_pre_shift_non_ot_minutes' => 90,   // ช่วงมาก่อนเวลาของกะเช้าที่ไม่ถือเป็น OT (06:30-08:00)
        'night_pre_shift_non_ot_minutes' => 150, // ช่วงมาก่อนเวลาของกะดึกที่ไม่ถือเป็น OT (20:00-22:30)
        'ot_grace_minutes'  => 0,       // สแกนออกเกินเวลาเลิกงานกี่นาทีถึงจะเริ่มติดธง OT (0 = เกินแม้แต่นาทีเดียวก็ติดธง)
        'ot_rounding_block_minutes' => 30, // OT จะนับเป็นช่วงละ 30 นาที เศษไม่ครบช่วงไม่นับ
        'max_session_span_hours' => 20, // รองรับเคสเข้างานก่อนกะ/ออกสายมากขึ้น โดยยังอยู่ session เดียวกัน
    ],

    // ใช้ทำ cookie/session ให้ปลอดภัยเมื่อรันบน Hostinger ที่มี HTTPS
    'app' => [
        'force_https_cookie' => true,
        'session_name'       => 'attsys_session',
    ],
];
