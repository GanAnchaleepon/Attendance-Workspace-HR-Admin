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
 * ระบบจะลองใช้ตารางกะที่ HR ตั้งไว้ผ่านหน้า attendance_review.php ก่อน (ดู src/ShiftSchedule.php)
 * ถ้าไม่มีตั้งค่าไว้ จึงค่อยดูรูปแบบการสแกนของวันใกล้เคียงมาโหวตเสียงข้างมากแทน (resolveAmbiguousShifts)
 * ทั้งสองกรณีนี้ใช้เฉพาะวันที่ข้อมูลสแกนเองไม่ชัดเจนเท่านั้น — วันที่ข้อมูลชัดเจนอยู่แล้วจะไม่ถูกบังคับทับ
 * แม้มีตารางกะตั้งไว้ก็ตาม เพื่อกันกรณีมีการสลับคนจริงที่ตารางกะยังไม่ได้อัปเดตตาม
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

        $sessions = self::groupScans($scanTimes, $projectCode, $employeeShiftLabel, $config);

        $computed = [];
        foreach ($sessions as $session) {
            $computed[] = self::computeSession(
                $session['check_in'],
                $session['check_out'],
                $session['scan_count'],
                $config,
                $session['forced_shift'],
                $session['work_date']
            );
        }
        $computed = self::resolveAmbiguousShifts($computed, $config, $projectCode, $employeeShiftLabel);
        $computed = array_map(static function (array $s): array {
            unset($s['ambiguous'], $s['check_in_dt'], $s['check_out_dt'], $s['work_date_override']);
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
     * จับกลุ่มสแกนเป็นกะ
     *
     * ถ้ารู้กะที่พนักงานควรเข้าในแต่ละวัน (มี shift_code A/B + HR ตั้งตารางกะประจำเดือนไว้)
     * จะจับสแกนเข้า "ช่องกะ" ตามกรอบเวลาจริงของกะวันนั้น ๆ ซึ่งแม่นกว่ามาก
     * เพราะวิธีหน้าต่างเลื่อน (max_session_span_hours) จะพังทันทีที่วันไหนขาดสแกนไปหนึ่งครั้ง
     * โดยจะดูดสแกนของวันถัดไปเข้ามารวมเป็นกะเดียวกัน แล้วเลื่อนผิดต่อกันเป็นทอด ๆ ทั้งสัปดาห์
     *
     * @param DateTimeImmutable[] $scanTimes
     * @return array<int, array{check_in: DateTimeImmutable, check_out: DateTimeImmutable, scan_count: int, forced_shift: ?string, work_date: ?string}>
     */
    private static function groupScans(array $scanTimes, string $projectCode, ?string $employeeShiftLabel, array $config): array
    {
        if ($employeeShiftLabel === null) {
            return array_map(
                static fn (array $s): array => $s + ['forced_shift' => null, 'work_date' => null],
                self::groupIntoSessions($scanTimes, (float) $config['max_session_span_hours'])
            );
        }

        $slots = [];
        $leftover = [];
        $currentKey = null;

        foreach ($scanTimes as $time) {
            $candidates = self::scheduledSlotCandidates($time, $projectCode, $employeeShiftLabel, $config);
            if (count($candidates) === 0) {
                $leftover[] = $time;
                $currentKey = null;
                continue;
            }

            $chosen = null;
            foreach ($candidates as $candidate) {
                if ($candidate['key'] === $currentKey) {
                    $chosen = $candidate;
                    break;
                }
            }
            if ($chosen === null) {
                // เปิดช่องกะใหม่: เลือกกะที่เวลาเริ่มงานใกล้กับเวลาสแกนนี้ที่สุด
                $scanTs = $time->getTimestamp();
                usort(
                    $candidates,
                    static fn (array $a, array $b): int => abs($a['expected_start'] - $scanTs) <=> abs($b['expected_start'] - $scanTs)
                );
                $chosen = $candidates[0];
            }

            $key = $chosen['key'];
            if (!isset($slots[$key])) {
                $slots[$key] = ['date' => $chosen['date'], 'shift' => $chosen['shift'], 'times' => []];
            }
            $slots[$key]['times'][] = $time;
            $currentKey = $key;
        }

        $result = [];
        foreach ($slots as $slot) {
            $times = $slot['times'];
            $result[] = [
                'check_in' => $times[0],
                'check_out' => $times[count($times) - 1],
                'scan_count' => count($times),
                'forced_shift' => $slot['shift'],
                'work_date' => $slot['date'],
            ];
        }
        foreach (self::groupIntoSessions($leftover, (float) $config['max_session_span_hours']) as $session) {
            $result[] = $session + ['forced_shift' => null, 'work_date' => null];
        }

        usort($result, static fn (array $a, array $b): int => $a['check_in'] <=> $b['check_in']);
        return $result;
    }

    /**
     * หา "ช่องกะ" ที่เวลาสแกนนี้เข้าข่าย โดยดูกะของวันนั้นและวันก่อนหน้า (กะดึกคาบเกี่ยวข้ามคืน)
     * กรอบของแต่ละกะ = เวลาเข้างาน -5 ชม. ถึง เวลาเลิกงาน +6 ชม. (เผื่อมาก่อน/อยู่ OT ต่อ)
     *
     * @return array<int, array{key:string, date:string, shift:string, expected_start:int, expected_end:int}>
     */
    private static function scheduledSlotCandidates(DateTimeImmutable $time, string $projectCode, string $employeeShiftLabel, array $config): array
    {
        $scanTs = $time->getTimestamp();
        $candidates = [];

        foreach (['-1 day', '+0 day'] as $offset) {
            $date = $time->modify($offset)->format('Y-m-d');
            $shift = ShiftSchedule::resolveShiftType($projectCode, $date, $employeeShiftLabel);
            if ($shift === null) {
                continue;
            }

            [$expectedStart, $expectedEnd] = self::expectedBounds($date, $shift, $config);
            if ($scanTs >= $expectedStart - 5 * 3600 && $scanTs <= $expectedEnd + 6 * 3600) {
                $candidates[] = [
                    'key' => $date . '|' . $shift,
                    'date' => $date,
                    'shift' => $shift,
                    'expected_start' => $expectedStart,
                    'expected_end' => $expectedEnd,
                ];
            }
        }

        return $candidates;
    }

    /** @return array{0:int, 1:int} timestamp ของเวลาเข้างาน/เลิกงานตามกะของวันที่ระบุ */
    private static function expectedBounds(string $date, string $shiftType, array $config): array
    {
        $base = new DateTimeImmutable($date . ' 00:00:00');

        if ($shiftType === 'day') {
            $start = $base->setTime((int) explode(':', $config['day_shift_start'])[0], (int) explode(':', $config['day_shift_start'])[1]);
            $end = $base->setTime((int) explode(':', $config['day_shift_end'])[0], (int) explode(':', $config['day_shift_end'])[1]);
        } else {
            $start = $base->setTime((int) explode(':', $config['night_shift_start'])[0], (int) explode(':', $config['night_shift_start'])[1]);
            $end = $base->modify('+1 day')->setTime((int) explode(':', $config['night_shift_end'])[0], (int) explode(':', $config['night_shift_end'])[1]);
        }

        return [$start->getTimestamp(), $end->getTimestamp()];
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
    private static function computeSession(
        DateTimeImmutable $checkIn,
        DateTimeImmutable $checkOut,
        int $scanCount,
        array $config,
        ?string $forcedShiftType = null,
        ?string $workDateOverride = null
    ): array {
        if ($forcedShiftType !== null) {
            $shiftType = $forcedShiftType;
            $ambiguous = false; // มาจากตารางกะที่ตั้งไว้ ไม่ต้องเดาซ้ำ
        } else {
            [$shiftType, $ambiguous] = self::classifyShiftFromCheckIn($checkIn, $config);
        }

        $isIncomplete = $checkIn->getTimestamp() === $checkOut->getTimestamp();

        $result = self::buildSessionForShiftType($checkIn, $checkOut, $scanCount, $shiftType, $isIncomplete, $config, $workDateOverride);
        $result['ambiguous'] = $ambiguous || ($forcedShiftType === null && $isIncomplete);
        $result['check_in_dt'] = $checkIn;
        $result['check_out_dt'] = $checkOut;
        $result['work_date_override'] = $workDateOverride;
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
     * เมื่อวันไหนเดากะแบบวันเดียวไม่มั่นใจ (ก้ำกึ่ง หรือมีสแกนแค่ครั้งเดียว) เท่านั้นที่จะมาลองอีก 2 ชั้น:
     * 1) ถ้า HR ตั้งค่ากะประจำเดือนไว้ (ShiftSchedule) และพนักงานมี shift_code ที่อ่านออก ให้ยึดตามนั้น
     * 2) ถ้าไม่มีการตั้งค่า ให้ดูรูปแบบการสแกนของ "วันใกล้เคียง" ที่ไม่ก้ำกึ่ง (5 วันที่ใกล้ที่สุด) มาโหวตแทน
     *
     * หมายเหตุสำคัญ: วันที่ข้อมูลสแกนเองชัดเจนอยู่แล้ว (ไม่ก้ำกึ่ง) จะไม่ถูกบังคับทับด้วยตารางกะ แม้ตั้งค่าไว้ก็ตาม
     * เพราะถ้าข้อมูลจริงขัดกับตารางกะ (เช่น มีการสลับคนรายบุคคลที่ไม่ได้อัปเดตตารางกะ) การบังคับทับจะทำให้
     * expected_start/end ผิดเพี้ยนจนคำนวณ OT ออกมาไร้สาระ (เช่น ได้ OT หลายสิบชั่วโมง) ข้อมูลจริงจึงควรชนะเสมอ
     *
     * @param array<int, array<string, mixed>> $sessions
     * @return array<int, array<string, mixed>>
     */
    private static function resolveAmbiguousShifts(array $sessions, array $config, string $projectCode, ?string $employeeShiftLabel): array
    {
        $workDateTs = array_map(
            static fn (array $s): int => (int) strtotime((string) $s['work_date']),
            $sessions
        );

        foreach ($sessions as $i => $session) {
            if (!$session['ambiguous']) {
                continue;
            }

            $resolvedShift = $employeeShiftLabel !== null
                ? ShiftSchedule::resolveShiftType($projectCode, (string) $session['work_date'], $employeeShiftLabel)
                : null;

            if ($resolvedShift === null) {
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
                $resolvedShift = $votes['day'] >= $votes['night'] ? 'day' : 'night';
            }

            if ($resolvedShift !== $session['shift_type']) {
                $rebuilt = self::buildSessionForShiftType(
                    $session['check_in_dt'],
                    $session['check_out_dt'],
                    (int) $session['scan_count'],
                    $resolvedShift,
                    (bool) $session['incomplete_flag'],
                    $config,
                    $session['work_date_override'] ?? null
                );
                $sessions[$i] = $rebuilt + [
                    'ambiguous' => $session['ambiguous'],
                    'check_in_dt' => $session['check_in_dt'],
                    'check_out_dt' => $session['check_out_dt'],
                    'work_date_override' => $session['work_date_override'] ?? null,
                ];
            }
        }

        return $sessions;
    }

    private static function sanitizedNeighborCount(int $available): int
    {
        return min($available, 5);
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
        array $config,
        ?string $workDateOverride = null
    ): array {
        // ถ้ารู้วันที่ของกะจากตารางกะ ให้ยึดวันนั้นเป็นหลัก ไม่ใช่วันของสแกนแรก
        // (กรณีขาดสแกนเข้า เหลือแต่สแกนออกตอนเช้าวันถัดไป วันที่ของกะยังต้องเป็นวันที่เริ่มกะ)
        $anchor = $workDateOverride !== null
            ? new DateTimeImmutable($workDateOverride . ' 00:00:00')
            : $checkIn;
        $workDate = $anchor->format('Y-m-d');

        if ($shiftType === 'day') {
            $expectedStart = $anchor->setTime((int) explode(':', $config['day_shift_start'])[0], (int) explode(':', $config['day_shift_start'])[1]);
            $expectedEnd = $anchor->setTime((int) explode(':', $config['day_shift_end'])[0], (int) explode(':', $config['day_shift_end'])[1]);
        } else {
            $expectedStart = $anchor->setTime((int) explode(':', $config['night_shift_start'])[0], (int) explode(':', $config['night_shift_start'])[1]);
            $expectedEnd = $anchor
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
            // มาก่อนเวลาเกินกว่าช่วงผ่อนผัน (เช่น กะดึกมาก่อน 20:00 = มาก่อน "ช่วงยอมรับ" 150 นาที)
            // ถือว่ามาทำงานล่วงเวลาจริง จ่าย OT เต็มช่วงผ่อนผันนั้นคงที่ (ไม่ใช่ตามจำนวนนาทีที่มาก่อนจริง)
            // ถ้ามาภายในช่วงผ่อนผัน (เช่น 20:00-22:30) ถือเป็นมาก่อนเวลาปกติ ไม่นับ OT เลย
            $preShiftOtMinutes = $preShiftMinutes > $preShiftNonOt ? $preShiftNonOt : 0.0;
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
