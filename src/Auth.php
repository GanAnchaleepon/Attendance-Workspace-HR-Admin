<?php
declare(strict_types=1);

/**
 * จัดการการยืนยันตัวตน (login/logout) และการป้องกัน brute-force เบื้องต้น
 */
final class Auth
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;
    private const PROJECT_SCOPE_BY_USER = [
        'TTV01927' => 'FTM-SE',
        'TTV00196' => 'AAT-SE',
    ];

    public static function hasAnyUser(): bool
    {
        $stmt = Database::pdo()->query('SELECT COUNT(*) AS c FROM users');
        return (int) $stmt->fetch()['c'] > 0;
    }

    public static function createUser(string $username, string $password, ?string $displayName = null): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (username, password_hash, display_name) VALUES (:u, :p, :d)'
        );
        $stmt->execute([
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'd' => $displayName,
        ]);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public static function attemptLogin(string $username, string $password): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $user = $stmt->fetch();

        // ใช้ข้อความ error แบบเดียวกันเสมอไม่ว่าจะผิดที่ username หรือ password
        // เพื่อไม่ให้ผู้โจมตีรู้ว่า username นี้มีอยู่จริงหรือไม่ (OWASP A07)
        $genericError = ['ok' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];

        if (!$user) {
            // หน่วงเวลาเล็กน้อยให้ใกล้เคียงกรณี user มีจริงแต่ password ผิด ลดการเดา username จาก timing
            usleep(150000);
            return $genericError;
        }

        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            $minutesLeft = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            return ['ok' => false, 'message' => "บัญชีถูกล็อกชั่วคราวเนื่องจากใส่รหัสผ่านผิดหลายครั้ง กรุณาลองใหม่ในอีก {$minutesLeft} นาที"];
        }

        if (!password_verify($password, $user['password_hash'])) {
            self::registerFailedAttempt((int) $user['id'], (int) $user['failed_attempts']);
            return $genericError;
        }

        // login สำเร็จ: reset ตัวนับ, สร้าง session ใหม่กันการโจมตีแบบ session fixation
        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id')
            ->execute(['id' => $user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['login_time'] = time();

        return ['ok' => true, 'message' => 'เข้าสู่ระบบสำเร็จ'];
    }

    private static function registerFailedAttempt(int $userId, int $currentAttempts): void
    {
        $newAttempts = $currentAttempts + 1;
        $lockUntil = null;
        if ($newAttempts >= self::MAX_FAILED_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_MINUTES * 60);
            $newAttempts = 0;
        }

        Database::pdo()->prepare(
            'UPDATE users SET failed_attempts = :a, locked_until = :l WHERE id = :id'
        )->execute(['a' => $newAttempts, 'l' => $lockUntil, 'id' => $userId]);
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            redirect('login.php');
        }
    }

    public static function currentUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function currentDisplayName(): string
    {
        return $_SESSION['display_name'] ?: ($_SESSION['username'] ?? '');
    }

    public static function scopedProjectCode(): ?string
    {
        $candidates = [
            strtoupper(trim((string) ($_SESSION['username'] ?? ''))),
            strtoupper(trim((string) ($_SESSION['display_name'] ?? ''))),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && isset(self::PROJECT_SCOPE_BY_USER[$candidate])) {
                return self::PROJECT_SCOPE_BY_USER[$candidate];
            }
        }

        return null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }
}
