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
 * เป็นการหมุนเวียนที่ไม่มีตารางแน่นอนในระบบนี้ และอาจมีการสลับคนรายบุคคลได้ทุกเมื่อ)
 *
 * เมื่อวันไหนเดากะแบบวันเดียวไม่มั่นใจ (เวลาเข้างานก้ำกึ่งระหว่างกะเช้า/กะดึก หรือมีสแกนแค่ครั้งเดียว)
 * ระบบจะดูรูปแบบการสแกนของวันใกล้เคียงมาโหวตเสียงข้างมากแทน (ดู resolveAmbiguousShifts)
 * แต่ถ้า HR ตั้งค่ากะประจำเดือนไว้ผ่านหน้า attendance_review.php (ดู src/ShiftSchedule.php) จะยึดตามนั้น
 * เสมอโดยไม่เดา (authoritative) เพราะกะของพนักงานแต่ละคนที่ประกาศมาแล้วถือเป็นค่าตายตัว
 * ทั้งนี้ทุกหน้าที่แสดงกะ/OT (attendance_review.php, print_ot_form.php) ใช้ค่าที่คำนวณไว้ในตารางนี้
 * โดยตรง ไม่มีการคำนวณกะซ้ำที่หน้าอื่นอีก (single source of truth)
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

        $shiftCodeStmt = $pdo->prepare(
            'SELECT shift_code FROM employees WHERE employee_code = :code AND project_code = :project LIMIT 1'
        );
        $shiftCodeStmt->execute(['code' => $employeeCode, 'project' => $projectCode]);
        $employeeShiftLabel = ShiftSchedule::normalizeShiftLabel((string) ($shiftCodeStmt->fetchColumn() ?: ''));

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
        $computed = self::resolveAmbiguousShifts($computed, $config);
        $computed = self::applyShiftSchedule($computed, $projectCode, $employeeShiftLabel, $config);
        $computed = array_map(static function (array $s): array {
            unset($s['ambiguous'], $s['check_in_dt'], $s['check_out_dt']);
            return $s;
        }, $computed);

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
     *   check_in:string, check_out:?string, scan_count:int, ot_flag:bool, ot_minutes:int, incomplete_flag:bool,
     *   ambiguous:bool, check_in_dt: DateTimeImmutable, check_out_dt: DateTimeImmutable}
     */
    private static function computeSession(DateTimeImmutable $checkIn, DateTimeImmutable $checkOut, int $scanCount, array $config): array
    {
        [$shiftType, $ambiguous] = self::classifyShiftFromCheckIn($checkIn, $config);
        $isIncomplete = $checkIn->getTimestamp() === $checkOut->getTimestamp();

        $result = self::buildSessionForShiftType($checkIn, $checkOut, $scanCount, $shiftType, $isIncomplete, $config);
        $result['ambiguous'] = $ambiguous || $isIncomplete;
        $result['check_in_dt'] = $checkIn;
        $result['check_out_dt'] = $checkOut;
        return $result;
    }

    /**
     * เดาประเภทกะจาก "เวลาเข้างานจริง" อย่างเดียว (ใกล้เวลาเริ่มกะเช้าหรือกะดึกมากกว่ากัน)
     * ถือว่า "ก้ำกึ่ง" (ambiguous) เมื่อระยะห่างสองฝั่งต่างกันไม่ถึง 90 นาที เพื่อให้ resolveAmbiguousShifts
     * ไปเทียบกับวันใกล้เคียงแทนการเดาแบบวันเดียวโดดๆ (กันเคสมาถึงตอนก้ำกึ่งกลางวัน-กลางคืนพอดี)
     *
     * @return array{0:string, 1:bool}
     */
    private static function classifyShiftFromCheckIn(DateTimeImmutable $checkIn, array $config): array
    {
        $dayStart = self::minutesOfDay($config['day_shift_start']);
        $nightStart = self::minutesOfDay($config['night_shift_start']);

        $checkInMinutes = (int) $checkIn->format('H') * 60 + (int) $checkIn->format('i');
        $distDay = self::circularDistance($checkInMinutes, $dayStart);
        $distNight = self::circularDistance($checkInMinutes, $nightStart);

        $shiftType = $distDay <= $distNight ? 'day' : 'night';
        $ambiguous = abs($distDay - $distNight) <= 90;
        return [$shiftType, $ambiguous];
    }

    /**
     * เมื่อวันไหนเดากะแบบวันเดียวไม่มั่นใจ (ก้ำกึ่ง หรือมีสแกนแค่ครั้งเดียว) ให้ดูรูปแบบการสแกนจริง
     * ของ "วันใกล้เคียง" ที่ไม่ก้ำกึ่ง (ใช้ 5 วันที่ใกล้ที่สุดตามปฏิทิน) มาโหวตเสียงข้างมากแทน
     * วิธีนี้ปรับตามข้อมูลจริงเสมอ ไม่ต้องตั้งค่ารอบหมุนกะ/ยกเว้นรายบุคคลในโค้ดอีกต่อไป
     * (พนักงานสลับกะฉุกเฉินเป็นรายวันก็จะถูกจับได้ถูกต้อง เพราะเวลาสแกนของวันนั้นเองจะฟ้องอยู่แล้ว
     * ส่วนวันที่ข้อมูลก้ำกึ่ง/ไม่ครบ ค่อยอาศัยวันใกล้เคียงช่วยตัดสิน)
     *
     * @param array<int, array<string, mixed>> $sessions
     * @return array<int, array<string, mixed>>
     */
    private static function resolveAmbiguousShifts(array $sessions, array $config): array
    {
        $workDateTs = array_map(
            static fn (array $s): int => (int) strtotime((string) $s['work_date']),
            $sessions
        );

        foreach ($sessions as $i => $session) {
            if (!$session['ambiguous']) {
                continue;
            }

            $candidates = [];
            foreach ($sessions as $j => $other) {
                if ($i === $j || $other['ambiguous']) {
                    continue;
                }
                $dayDiff = abs($workDateTs[$j] - $workDateTs[$i]);
                $candidates[] = [$dayDiff, (string) $other['shift_type']];
            }

            if (count($candidates) === 0) {
                continue; // ไม่มีวันใกล้เคียงให้เทียบ ใช้ผลเดาแบบวันเดียวเดิมต่อไป
            }

            usort($candidates, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $nearest = array_slice($candidates, 0, self::sanitizedNeighborCount(count($candidates)));

            $votes = ['day' => 0, 'night' => 0];
            foreach ($nearest as [, $type]) {
                $votes[$type]++;
            }
            $majorityShift = $votes['day'] >= $votes['night'] ? 'day' : 'night';

            if ($majorityShift !== $session['shift_type']) {
                $rebuilt = self::buildSessionForShiftType(
                    $session['check_in_dt'],
                    $session['check_out_dt'],
                    (int) $session['scan_count'],
                    $majorityShift,
                    (bool) $session['incomplete_flag'],
                    $config
                );
                $sessions[$i] = $rebuilt + ['ambiguous' => $session['ambiguous'], 'check_in_dt' => $session['check_in_dt'], 'check_out_dt' => $session['check_out_dt']];
            }
        }

        return $sessions;
    }

    private static function sanitizedNeighborCount(int $available): int
    {
        return min($available, 5);
    }

    /**
     * ถ้า HR ตั้งค่ากะประจำเดือนไว้ (ShiftSchedule) ให้ยึดตามนั้นเสมอ (authoritative)
     * เพราะทีมงานตกลงกันว่าค่านี้ตายตัว ไม่ต้องเดาจากข้อมูลสแกนอีกเมื่อมีการตั้งค่าไว้แล้ว
     *
     * @param array<int, array<string, mixed>> $sessions
     * @return array<int, array<string, mixed>>
     */
    private static function applyShiftSchedule(array $sessions, string $projectCode, ?string $employeeShiftLabel, array $config): array
    {
        if ($employeeShiftLabel === null) {
            return $sessions;
        }

        foreach ($sessions as $i => $session) {
            $scheduledShift = ShiftSchedule::resolveShiftType($projectCode, (string) $session['work_date'], $employeeShiftLabel);
            if ($scheduledShift === null || $scheduledShift === $session['shift_type']) {
                continue;
            }

            $rebuilt = self::buildSessionForShiftType(
                $session['check_in_dt'],
                $session['check_out_dt'],
                (int) $session['scan_count'],
                $scheduledShift,
                (bool) $session['incomplete_flag'],
                $config
            );
            $sessions[$i] = $rebuilt + ['ambiguous' => false, 'check_in_dt' => $session['check_in_dt'], 'check_out_dt' => $session['check_out_dt']];
        }

        return $sessions;
    }

    /**
     * @return array{work_date:string, shift_type:string, expected_start:?string, expected_end:?string,
     *   check_in:string, check_out:?string, scan_count:int, ot_flag:bool, ot_minutes:int, incomplete_flag:bool}
     */
    private static function buildSessionForShiftType(
        DateTimeImmutable $checkIn,
        DateTimeImmutable $checkOut,
        int $scanCount,
        string $shiftType,
        bool $isIncomplete,
        array $config
    ): array {
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
