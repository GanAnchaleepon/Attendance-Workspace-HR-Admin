<?php
declare(strict_types=1);

/**
 * จัดการ CSRF token สำหรับทุกฟอร์มที่เป็น POST (OWASP A01/A05)
 */
final class Csrf
{
    private const SESSION_KEY = 'csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . h(self::token()) . '">';
    }

    public static function verify(?string $submittedToken): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if ($expected === '' || $submittedToken === null) {
            return false;
        }

        return hash_equals($expected, $submittedToken);
    }

    public static function requireValid(): void
    {
        $submitted = $_POST['csrf_token'] ?? null;
        if (!self::verify($submitted)) {
            http_response_code(400);
            die('คำขอไม่ถูกต้อง (CSRF token ไม่ตรงกันหรือหมดอายุ) กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง');
        }
    }
}
