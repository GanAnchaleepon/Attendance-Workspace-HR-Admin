<?php
declare(strict_types=1);

/**
 * ตัวจัดการการเชื่อมต่อฐานข้อมูล (PDO) แบบ singleton
 * ใช้ prepared statement เสมอเพื่อป้องกัน SQL Injection (OWASP A03)
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $config = App::config('db');

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['name'],
                $config['charset'] ?? 'utf8mb4'
            );

            self::$pdo = new PDO($dsn, $config['user'], $config['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
            ]);
        }

        return self::$pdo;
    }
}
