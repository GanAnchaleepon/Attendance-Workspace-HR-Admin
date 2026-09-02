<?php
declare(strict_types=1);

/**
 * ตัวโหลด config.php (คอนฟิกไม่ควรอยู่ใน public/ เพื่อความปลอดภัย)
 */
final class App
{
    private static ?array $config = null;

    public static function config(?string $key = null)
    {
        if (self::$config === null) {
            $path = dirname(__DIR__) . '/config.php';
            if (!is_file($path)) {
                http_response_code(500);
                die('ไม่พบไฟล์ config.php กรุณาคัดลอกจาก config.example.php แล้วกรอกค่าฐานข้อมูลก่อนใช้งาน');
            }
            self::$config = require $path;
        }

        if ($key === null) {
            return self::$config;
        }

        return self::$config[$key] ?? null;
    }
}
