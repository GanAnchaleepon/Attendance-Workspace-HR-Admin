<?php
declare(strict_types=1);

/**
 * ไฟล์เริ่มต้นระบบ: ทุกหน้าใน public/ ต้อง require ไฟล์นี้เป็นบรรทัดแรก
 * ทำหน้าที่: ตั้งค่า error reporting, โหลดคลาสหลัก, เริ่ม session อย่างปลอดภัย
 */

error_reporting(E_ALL);
ini_set('display_errors', '0'); // ห้ามโชว์ error ตรง ๆ บนหน้าเว็บ production (ป้องกันข้อมูลรั่วไหล)

date_default_timezone_set('Asia/Bangkok');

/**
 * helper ตรวจ substring แบบรองรับหลายเวอร์ชัน PHP
 */
function contains_text(string $haystack, string $needle): bool
{
    if ($needle === '') {
        return true;
    }

    if (function_exists('str_contains')) {
        return str_contains($haystack, $needle);
    }

    return strpos($haystack, $needle) !== false;
}

/**
 * แสดงหน้า error มาตรฐานเพื่อแทนหน้าขาว 500
 */
function render_error_page(string $hint, ?string $technicalMessage = null): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');

    $detailHtml = '';
    if ($technicalMessage !== null && trim($technicalMessage) !== '') {
        $safe = htmlspecialchars($technicalMessage, ENT_QUOTES, 'UTF-8');
        $detailHtml = '<details style="margin-top:14px;"><summary>รายละเอียดเทคนิค</summary>'
            . '<pre style="white-space:pre-wrap;background:#f8fafc;border:1px solid #e5e7eb;padding:10px;border-radius:8px;">'
            . $safe
            . '</pre></details>';
    }

    echo '<!DOCTYPE html><html lang="th"><head><meta charset="UTF-8">'
        . '<title>เกิดข้อผิดพลาด</title></head><body style="font-family:sans-serif;padding:40px;max-width:640px;margin:0 auto;">'
        . '<h1 style="color:#991b1b;">ระบบขัดข้องชั่วคราว</h1>'
        . '<p>' . htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="color:#6b7280;font-size:13px;">รายละเอียดทางเทคนิคถูกบันทึกไว้ใน error log ของเซิร์ฟเวอร์แล้ว</p>'
        . $detailHtml
        . '</body></html>';
}

/**
 * วิเคราะห์ข้อความ error แล้วคืน hint ที่อ่านง่าย
 */
function guess_error_hint(string $msg): string
{
    $hint = 'กรุณาตรวจสอบไฟล์ config.php และการตั้งค่าฐานข้อมูล';
    if (contains_text($msg, "doesn't exist") || contains_text($msg, 'Base table')) {
        $hint = 'ดูเหมือนยังไม่ได้ import โครงสร้างตาราง กรุณา import ไฟล์ sql/schema.sql ผ่าน phpMyAdmin ก่อนใช้งาน';
    } elseif (contains_text($msg, 'Access denied')) {
        $hint = 'ชื่อผู้ใช้/รหัสผ่านฐานข้อมูลใน config.php ไม่ถูกต้อง กรุณาตรวจสอบกับ hPanel > Databases อีกครั้ง';
    } elseif (contains_text($msg, 'Unknown database')) {
        $hint = 'ไม่พบชื่อฐานข้อมูลนี้ กรุณาตรวจสอบชื่อฐานข้อมูลใน config.php ให้ตรงกับ hPanel > Databases';
    } elseif (contains_text($msg, 'could not find driver')) {
        $hint = 'เซิร์ฟเวอร์ยังไม่ได้เปิดใช้งาน PHP extension "pdo_mysql" กรุณาติดต่อฝ่ายซัพพอร์ตของ Hostinger หรือเปิดใน hPanel > PHP Configuration';
    } elseif (contains_text($msg, 'Class') && contains_text($msg, 'PDO') && contains_text($msg, 'not found')) {
        $hint = 'PHP ของโฮสต์ยังไม่เปิด extension PDO/PDO_MYSQL กรุณาเปิดใน hPanel > Advanced > PHP Configuration';
    } elseif (contains_text($msg, 'mb_strtolower') || contains_text($msg, 'Call to undefined function mb_')) {
        $hint = 'PHP ของโฮสต์ยังไม่เปิด extension mbstring กรุณาเปิดใน hPanel > Advanced > PHP Configuration';
    } elseif (contains_text($msg, 'str_contains')) {
        $hint = 'เวอร์ชัน PHP ต่ำเกินไปสำหรับฟังก์ชัน str_contains กรุณาตั้ง PHP เป็น 8.0 ขึ้นไปใน hPanel';
    } elseif (contains_text($msg, 'unexpected') && contains_text($msg, '?') && contains_text($msg, 'expecting')) {
        $hint = 'โค้ดนี้ต้องใช้ PHP 7.4+ (แนะนำ PHP 8.1/8.2) กรุณาอัปเดตเวอร์ชัน PHP ใน hPanel';
    } elseif (contains_text($msg, 'SQLSTATE[HY000] [2002]') || contains_text($msg, 'Connection refused')) {
        $hint = 'เชื่อมต่อฐานข้อมูลไม่ได้ (host ผิดหรือฐานข้อมูลไม่พร้อมใช้งาน) กรุณาตรวจสอบค่า host ใน config.php';
    }

    return $hint;
}

register_shutdown_function(static function (): void {
    $last = error_get_last();
    if (!is_array($last)) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($last['type'] ?? 0, $fatalTypes, true)) {
        return;
    }

    $message = (string) ($last['message'] ?? 'Unknown fatal error');
    $file = (string) ($last['file'] ?? 'unknown');
    $line = (int) ($last['line'] ?? 0);
    error_log('[attendance-system] Fatal: ' . $message . ' @ ' . $file . ':' . $line);

    $hint = guess_error_hint($message);
    render_error_page($hint, $message . ' @ ' . $file . ':' . $line);
});

/**
 * ดักข้อผิดพลาดที่ไม่คาดคิดทั้งหมด (เช่น เชื่อมต่อฐานข้อมูลไม่ได้ / ยังไม่ได้ import schema.sql)
 * แล้วแสดงหน้าอธิบายปัญหาแบบอ่านง่ายแทนหน้าขาว "500 Internal Server Error"
 * รายละเอียดเชิงลึกจริงจะถูกบันทึกไว้ใน PHP error log ของโฮสต์เท่านั้น ไม่แสดงต่อผู้ใช้ทั่วไป
 */
set_exception_handler(static function (Throwable $e): void {
    error_log('[attendance-system] Uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    $hint = guess_error_hint($e->getMessage());
    render_error_page($hint, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit;
});

require_once __DIR__ . '/App.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/CsvTable.php';
require_once __DIR__ . '/ManpowerImporter.php';
require_once __DIR__ . '/AttendanceImporter.php';
require_once __DIR__ . '/AttendanceSessionBuilder.php';

$appConfig = App::config('app') ?? [];

// ตั้งค่า session cookie ให้ปลอดภัยก่อนเริ่ม session (OWASP: session management)
$sessionName = $appConfig['session_name'] ?? 'attsys_session';
$isHttps = $appConfig['force_https_cookie'] ?? false;
if (!$isHttps) {
    // ตรวจจับ https อัตโนมัติจาก request จริง เผื่อ config ปิดไว้
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

session_name($sessionName);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => (bool) $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** ป้องกัน HTML injection ทุกครั้งที่พิมพ์ข้อมูลจากผู้ใช้ออกหน้าจอ */
function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** helper: redirect แล้วหยุดการทำงานทันที */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}
