<?php
declare(strict_types=1);

/**
 * แปลงข้อมูลสแกนดิบ (attendance_scans) เป็น "Session" ต่อกะ พร้อมตรวจว่าเข้าข่าย OT หรือไม่
 *
 * วิธีจัดกลุ่มกะ: เรียงเวลาสแกนของพนักงานแต่ละคน แล้วเริ่ม session ใหม่เมื่อเวลาสแกนห่างจาก
 * "เวลาเข้างานของ session ปัจจุบัน" เกิน max_session_span_hours (ค่าเริ่มต้น 16 ชม.)
 * ซึ่งครอบคลุมทั้งกะเช้า (8-17:30) และกะดึกข้ามคืน (22:30-08:00) โดยไม่ทับซ้อนกับกะถัดไป
 *
 * ประเภทกะ (day/night) ของแต่ละ session พิจารณาจาก "เวลาเข้างานจริง" ว่าใกล้เวลาเริ่มกะเช้า
 * หรือกะดึกมากกว่ากัน (ระบบไม่ได้ยึดตามคอลัมน์ Shift ในไฟล์ Manpower เพราะ ShiftA/ShiftB
 * เป็นการหมุนเวียนรายสัปดาห์ที่ไม่มีตารางแน่นอนในระบบนี้)
 */
final class AttendanceSessionBuilder
{
    /**
     * สร้าง/อัปเดต session ใหม่ทั้งหมดของพนักงานที่ระบุ จากข้อมูลสแกนทั้งหมดที่มีอยู่
     *
     * @param string[] $employeeCodes
     */
    public static function rebuildForEmployees(array $employeeCodes, string $projectCode): int
    {
        $count = 0;
        foreach ($employeeCodes as $employeeCode) {
            $count += self::rebuildForEmployee($employeeCode, $projectCode);
        }
        return $count;
    }

    private static function rebuildForEmployee(string $employeeCode, string $projectCode): int
    {
        $pdo = Database::pdo();
        $config = App::config('attendance');

        $stmt = $pdo->prepare(
            'SELECT scan_time FROM attendance_scans
             WHERE employee_code = :code AND project_code = :project
             ORDER BY scan_time ASC'
        );
        $stmt->execute(['code' => $employeeCode, 'project' => $projectCode]);
        $scanTimes = array_map(
            static fn ($r) => new DateTimeImmutable($r['scan_time']),
            $stmt->fetchAll()
        );

        $sessions = self::groupIntoSessions($scanTimes, (float) $config['max_session_span_hours']);

        $computed = [];
        foreach ($sessions as $session) {
            $computed[] = self::computeSession($session['check_in'], $session['check_out'], $session['scan_count'], $config);
        }

        // ลบ session เก่าที่ไม่ตรงกับผลคำนวณใหม่ (เช่น จัดกลุ่มใหม่ทำให้วันที่/ประเภทกะเปลี่ยน)
        $newKeys = array_map(static fn ($s) => $s['work_date'] . '|' . $s['shift_type'], $computed);

        $existingStmt = $pdo->prepare(
            'SELECT id, work_date, shift_type FROM attendance_sessions
             WHERE employee_code = :code AND project_code = :project'
        );
        $existingStmt->execute(['code' => $employeeCode, 'project' => $projectCode]);
        foreach ($existingStmt->fetchAll() as $row) {
            $key = $row['work_date'] . '|' . $row['shift_type'];
            if (!in_array($key, $newKeys, true)) {
                $pdo->prepare('DELETE FROM attendance_sessions WHERE id = :id')->execute(['id' => $row['id']]);
            }
        }

        $upsert = $pdo->prepare(
            'INSERT INTO attendance_sessions
                (employee_code, project_code, work_date, shift_type, expected_start, expected_end,
                 check_in, check_out, scan_count, ot_flag, ot_minutes, incomplete_flag)
             VALUES
                (:employee_code, :project_code, :work_date, :shift_type, :expected_start, :expected_end,
                 :check_in, :check_out, :scan_count, :ot_flag, :ot_minutes, :incomplete_flag)
             ON DUPLICATE KEY UPDATE
                expected_start = VALUES(expected_start), expected_end = VALUES(expected_end),
                check_in = VALUES(check_in), check_out = VALUES(check_out),
                scan_count = VALUES(scan_count), ot_flag = VALUES(ot_flag),
                ot_minutes = VALUES(ot_minutes), incomplete_flag = VALUES(incomplete_flag)'
        );

        foreach ($computed as $s) {
            $upsert->execute([
                'employee_code' => $employeeCode,
                'project_code' => $projectCode,
                'work_date' => $s['work_date'],
                'shift_type' => $s['shift_type'],
                'expected_start' => $s['expected_start'],
                'expected_end' => $s['expected_end'],
                'check_in' => $s['check_in'],
                'check_out' => $s['check_out'],
                'scan_count' => $s['scan_count'],
                'ot_flag' => $s['ot_flag'] ? 1 : 0,
                'ot_minutes' => $s['ot_minutes'],
                'incomplete_flag' => $s['incomplete_flag'] ? 1 : 0,
            ]);
        }

        return count($computed);
    }

    /**
     * @param DateTimeImmutable[] $scanTimes
     * @return array<int, array{check_in: DateTimeImmutable, check_out: DateTimeImmutable, scan_count: int}>
     */
    private static function groupIntoSessions(array $scanTimes, float $maxSpanHours): array
    {
        $sessions = [];
        $currentCheckIn = null;
        $currentCheckOut = null;
        $currentCount = 0;

        foreach ($scanTimes as $time) {
            if ($currentCheckIn === null) {
                $currentCheckIn = $time;
                $currentCheckOut = $time;
                $currentCount = 1;
                continue;
            }

            $spanHours = ($time->getTimestamp() - $currentCheckIn->getTimestamp()) / 3600;
            if ($spanHours <= $maxSpanHours) {
                $currentCheckOut = $time;
                $currentCount++;
            } else {
                $sessions[] = ['check_in' => $currentCheckIn, 'check_out' => $currentCheckOut, 'scan_count' => $currentCount];
                $currentCheckIn = $time;
                $currentCheckOut = $time;
                $currentCount = 1;
            }
        }

        if ($currentCheckIn !== null) {
            $sessions[] = ['check_in' => $currentCheckIn, 'check_out' => $currentCheckOut, 'scan_count' => $currentCount];
        }

        return $sessions;
    }

    /**
     * @return array{work_date:string, shift_type:string, expected_start:?string, expected_end:?string,
     *   check_in:string, check_out:?string, scan_count:int, ot_flag:bool, ot_minutes:int, incomplete_flag:bool}
     */
    private static function computeSession(DateTimeImmutable $checkIn, DateTimeImmutable $checkOut, int $scanCount, array $config): array
    {
        $dayStart = self::minutesOfDay($config['day_shift_start']);
        $nightStart = self::minutesOfDay($config['night_shift_start']);

        $checkInMinutes = (int) $checkIn->format('H') * 60 + (int) $checkIn->format('i');
        $distDay = self::circularDistance($checkInMinutes, $dayStart);
        $distNight = self::circularDistance($checkInMinutes, $nightStart);

        $shiftType = $distDay <= $distNight ? 'day' : 'night';
        $workDate = $checkIn->format('Y-m-d');

        if ($shiftType === 'day') {
            $expectedStart = $checkIn->setTime((int) explode(':', $config['day_shift_start'])[0], (int) explode(':', $config['day_shift_start'])[1]);
            $expectedEnd = $checkIn->setTime((int) explode(':', $config['day_shift_end'])[0], (int) explode(':', $config['day_shift_end'])[1]);
        } else {
            $expectedStart = $checkIn->setTime((int) explode(':', $config['night_shift_start'])[0], (int) explode(':', $config['night_shift_start'])[1]);
            $expectedEnd = $checkIn
                ->modify('+1 day')
                ->setTime((int) explode(':', $config['night_shift_end'])[0], (int) explode(':', $config['night_shift_end'])[1]);
        }

        $isIncomplete = $checkIn->getTimestamp() === $checkOut->getTimestamp();

        $otFlag = false;
        $otMinutes = 0;
        if (!$isIncomplete) {
            $grace = (float) $config['ot_grace_minutes'];
            $dayPreShiftNonOt = (float) ($config['day_pre_shift_non_ot_minutes'] ?? 0);
            $nightPreShiftNonOt = (float) ($config['night_pre_shift_non_ot_minutes'] ?? 0);
            $otBlockMinutes = max(1.0, (float) ($config['ot_rounding_block_minutes'] ?? 30));
            $preShiftNonOt = $shiftType === 'night' ? $nightPreShiftNonOt : $dayPreShiftNonOt;

            // OT ก่อนเวลาเริ่มกะ (เช่น กะดึกสแกนเข้า 20:00 แต่กะเริ่ม 22:30)
            $preShiftMinutes = max(0.0, ($expectedStart->getTimestamp() - $checkIn->getTimestamp()) / 60);
            // ช่วงมาก่อนเวลาเล็กน้อยที่ไม่ถือเป็น OT
            $preShiftOtMinutes = max(0.0, $preShiftMinutes - $preShiftNonOt);
            // OT หลังเลิกกะ (เช่น กะเช้าเลิก 17:30 แต่สแกนออก 20:00)
            $postShiftMinutes = max(0.0, ($checkOut->getTimestamp() - $expectedEnd->getTimestamp()) / 60);

            $qualifiedOtMinutes = 0.0;
            if ($preShiftOtMinutes > $grace) {
                $qualifiedOtMinutes += $preShiftOtMinutes;
            }
            if ($postShiftMinutes > $grace) {
                $qualifiedOtMinutes += $postShiftMinutes;
            }

            // OT นับเป็นช่วงละ X นาที (เช่น 30 นาที) เศษที่ไม่ครบไม่นับ
            $otBlocks = (int) floor($qualifiedOtMinutes / $otBlockMinutes);
            $otMinutes = (int) ($otBlocks * $otBlockMinutes);
            $otFlag = $otMinutes > 0;
        }

        return [
            'work_date' => $workDate,
            'shift_type' => $shiftType,
            'expected_start' => $expectedStart->format('Y-m-d H:i:s'),
            'expected_end' => $expectedEnd->format('Y-m-d H:i:s'),
            'check_in' => $checkIn->format('Y-m-d H:i:s'),
            'check_out' => $checkOut->format('Y-m-d H:i:s'),
            'scan_count' => $scanCount,
            'ot_flag' => $otFlag,
            'ot_minutes' => $otMinutes,
            'incomplete_flag' => $isIncomplete,
        ];
    }

    private static function minutesOfDay(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);
        return ((int) $h) * 60 + (int) $m;
    }

    private static function circularDistance(int $a, int $b): int
    {
        $diff = abs($a - $b);
        return min($diff, 1440 - $diff);
    }
}
