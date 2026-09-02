<?php
declare(strict_types=1);

/**
 * จัดการ "กะไหน (A/B) เข้าเช้าในสัปดาห์แรกของเดือน" ที่ HR ตั้งเองต่อโปรเจค+เดือน
 * สัปดาห์ถัดไปในเดือนเดียวกันสลับ A/B ให้อัตโนมัติ (สัปดาห์ปฏิทิน จันทร์-อาทิตย์)
 * ถ้าไม่มีการตั้งค่าไว้สำหรับเดือนนั้น ระบบจะไม่ยืนยันกะ (คืน null) แล้วปล่อยให้
 * AttendanceSessionBuilder ใช้วิธีเดาจากข้อมูลสแกนจริงแทน (fallback)
 */
final class ShiftSchedule
{
    public const SHIFTS = ['A', 'B'];

    /**
     * แปลงค่า shift_code จากไฟล์ Manpower ให้เหลือแค่ 'A' หรือ 'B' (คืน null ถ้าอ่านไม่ออก)
     */
    public static function normalizeShiftLabel(?string $raw): ?string
    {
        $rawText = trim((string) $raw);
        if ($rawText === '') {
            return null;
        }

        $upper = strtoupper($rawText);

        if (str_contains($upper, 'SHIFT A') || str_contains($upper, 'SHIFT-A') || str_contains($upper, 'SHIFTA')) {
            return 'A';
        }
        if (str_contains($upper, 'SHIFT B') || str_contains($upper, 'SHIFT-B') || str_contains($upper, 'SHIFTB')) {
            return 'B';
        }

        $tokens = preg_split('/[^A-Z0-9ก-๙]+/u', $upper, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if ($token === 'A' || $token === '1' || $token === 'เอ') {
                return 'A';
            }
            if ($token === 'B' || $token === '2' || $token === 'บี') {
                return 'B';
            }
        }

        if (preg_match('/(^|[^A-Z])A\d*/', $upper)) {
            return 'A';
        }
        if (preg_match('/(^|[^A-Z])B\d*/', $upper)) {
            return 'B';
        }

        return null;
    }

    /**
     * คืนค่าที่ตั้งไว้ของ (project_code, month) เช่น ['first_week_day_shift' => 'A'] หรือ null ถ้ายังไม่ตั้ง
     *
     * @return array{first_week_day_shift: string}|null
     */
    public static function getSetting(string $projectCode, string $month): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT first_week_day_shift FROM shift_month_settings WHERE project_code = :p AND month = :m LIMIT 1'
        );
        $stmt->execute(['p' => $projectCode, 'm' => $month]);
        $row = $stmt->fetch();
        return $row === false ? null : ['first_week_day_shift' => (string) $row['first_week_day_shift']];
    }

    /**
     * @return array<string, array{first_week_day_shift: string}> เดือน => ค่าที่ตั้งไว้ ของโปรเจคนี้ทั้งหมด
     */
    public static function listSettings(string $projectCode): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT month, first_week_day_shift FROM shift_month_settings WHERE project_code = :p ORDER BY month DESC'
        );
        $stmt->execute(['p' => $projectCode]);

        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $result[(string) $row['month']] = ['first_week_day_shift' => (string) $row['first_week_day_shift']];
        }
        return $result;
    }

    public static function saveSetting(string $projectCode, string $month, string $firstWeekDayShift, ?int $userId): void
    {
        if (!in_array($firstWeekDayShift, self::SHIFTS, true)) {
            throw new InvalidArgumentException('ค่ากะไม่ถูกต้อง (ต้องเป็น A หรือ B)');
        }

        Database::pdo()->prepare(
            'INSERT INTO shift_month_settings (project_code, month, first_week_day_shift, updated_by)
             VALUES (:p, :m, :s, :u)
             ON DUPLICATE KEY UPDATE first_week_day_shift = VALUES(first_week_day_shift), updated_by = VALUES(updated_by)'
        )->execute(['p' => $projectCode, 'm' => $month, 's' => $firstWeekDayShift, 'u' => $userId]);
    }

    /**
     * ตัดสินว่าวันที่ระบุนี้เป็นกะเช้าหรือกะดึกสำหรับพนักงานที่ถือ shift label นี้
     * คืน null ถ้าไม่มีการตั้งค่าไว้สำหรับเดือนนั้น หรือ shift label อ่านไม่ออก (ให้ fallback ไปใช้วิธีเดาจากข้อมูลสแกน)
     */
    public static function resolveShiftType(string $projectCode, string $workDate, ?string $employeeShiftLabel): ?string
    {
        if ($employeeShiftLabel === null) {
            return null;
        }

        $month = substr($workDate, 0, 7);
        $setting = self::getSetting($projectCode, $month);
        if ($setting === null) {
            return null;
        }

        $dayShiftOfWeek1 = self::dayShiftForWeek($workDate, $setting['first_week_day_shift']);
        return $employeeShiftLabel === $dayShiftOfWeek1 ? 'day' : 'night';
    }

    /**
     * หา shift label (A/B) ที่เป็นกะเช้าของสัปดาห์ที่ครอบคลุมวันที่ $workDate
     * โดยนับจากสัปดาห์แรกของเดือนนั้น (สัปดาห์ที่มีวันที่ 1 ของเดือนอยู่) แล้วสลับ A/B ทุกสัปดาห์
     */
    private static function dayShiftForWeek(string $workDate, string $firstWeekDayShift): string
    {
        $monthStart = substr($workDate, 0, 7) . '-01';
        $mondayOfWeek1 = self::mondayOf($monthStart);
        $mondayOfTargetWeek = self::mondayOf($workDate);

        $weeksDiff = (int) round((strtotime($mondayOfTargetWeek) - strtotime($mondayOfWeek1)) / (7 * 86400));
        $isEvenWeek = ($weeksDiff % 2 + 2) % 2 === 0;

        if ($isEvenWeek) {
            return $firstWeekDayShift;
        }
        return $firstWeekDayShift === 'A' ? 'B' : 'A';
    }

    /** คืนวันที่ของวันจันทร์ในสัปดาห์ปฏิทินที่ครอบคลุมวันที่ระบุ (รูปแบบ Y-m-d) */
    private static function mondayOf(string $dateStr): string
    {
        $ts = strtotime($dateStr);
        $isoWeekday = (int) date('N', $ts); // 1=จันทร์ ... 7=อาทิตย์
        return date('Y-m-d', strtotime('-' . ($isoWeekday - 1) . ' days', $ts));
    }
}
