<?php
require_once __DIR__ . '/src/bootstrap.php';
Auth::requireLogin();

$pdo = Database::pdo();

$employeeCode = trim((string) ($_GET['employee_code'] ?? ''));
$projectCode = trim((string) ($_GET['project'] ?? ''));
$allowedProjectCode = Auth::scopedProjectCode();
$validProjects = ['FTM-SE', 'AAT-SE'];
if ($projectCode !== '' && !in_array($projectCode, $validProjects, true)) {
    $projectCode = '';
}
if ($allowedProjectCode !== null) {
    $projectCode = $allowedProjectCode;
}
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$monthStart = $month . '-01';
$monthStartTs = strtotime($monthStart);
$daysInMonth = (int) date('t', $monthStartTs);
$monthEnd = date('Y-m-d', strtotime("+$daysInMonth days -1 day", $monthStartTs));

$pageTitle = 'ใบแจ้งการทำงานล่วงเวลา';
require __DIR__ . '/partials/header.php';

if ($employeeCode === '' || !preg_match('/^[A-Za-z0-9\-]{1,20}$/', $employeeCode)) {
    echo '<div class="alert alert-error">ไม่พบรหัสพนักงานที่ต้องการ</div>';
    echo '<p><a class="btn" href="attendance_review.php">กลับไปหน้าตรวจสอบ OT</a></p>';
    require __DIR__ . '/partials/footer.php';
    return;
}

if ($projectCode !== '') {
    $empStmt = $pdo->prepare('SELECT * FROM employees WHERE employee_code = :code AND project_code = :project LIMIT 1');
    $empStmt->execute(['code' => $employeeCode, 'project' => $projectCode]);
    $employee = $empStmt->fetch();
} else {
    $empStmt = $pdo->prepare('SELECT * FROM employees WHERE employee_code = :code ORDER BY id ASC');
    $empStmt->execute(['code' => $employeeCode]);
    $matches = $empStmt->fetchAll();
    if (count($matches) > 1) {
        echo '<div class="alert alert-error">พบพนักงานรหัส ' . h($employeeCode) . ' มากกว่า 1 โปรเจค กรุณาเข้าเมนูตรวจสอบ OT แล้วกดปุ่มดูรายงานใหม่</div>';
        echo '<p><a class="btn" href="attendance_review.php">กลับไปหน้าตรวจสอบ OT</a></p>';
        require __DIR__ . '/partials/footer.php';
        return;
    }
    $employee = $matches[0] ?? false;
}

if (!$employee) {
    echo '<div class="alert alert-error">ไม่พบพนักงานรหัส ' . h($employeeCode) . ' ในทะเบียน Manpower</div>';
    echo '<p><a class="btn" href="attendance_review.php">กลับไปหน้าตรวจสอบ OT</a></p>';
    require __DIR__ . '/partials/footer.php';
    return;
}

if ($allowedProjectCode !== null && ($employee['project_code'] ?? '') !== $allowedProjectCode) {
    echo '<div class="alert alert-error">บัญชีนี้ไม่มีสิทธิ์ดูรายงานของโปรเจคอื่น</div>';
    echo '<p><a class="btn" href="attendance_review.php">กลับไปหน้าตรวจสอบ OT</a></p>';
    require __DIR__ . '/partials/footer.php';
    return;
}

$sessStmt = $pdo->prepare(
    'SELECT * FROM attendance_sessions
    WHERE employee_code = :code AND project_code = :project AND work_date BETWEEN :from AND :to
     ORDER BY work_date ASC, check_in ASC'
);
$sessStmt->execute(['code' => $employeeCode, 'project' => $employee['project_code'], 'from' => $monthStart, 'to' => $monthEnd]);

/** @var array<string, array> $sessionsByDate เก็บ session แรกสุดของแต่ละวัน (ปกติมีแค่ 1 กะ/วัน) */
$sessionsByDate = [];
foreach ($sessStmt->fetchAll() as $row) {
    if (!isset($sessionsByDate[$row['work_date']])) {
        $sessionsByDate[$row['work_date']] = $row;
    }
}

$thaiMonthsAbbr = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
    'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
$monthLabel = $thaiMonthsAbbr[(int) date('n', $monthStartTs)] . '-' . date('y', $monthStartTs);

$fullName = trim(($employee['prefix'] ?? '') . ($employee['first_name_th'] ?? '') . ' ' . ($employee['last_name_th'] ?? ''));
$employeeCodeUpper = strtoupper((string) ($employee['employee_code'] ?? ''));
$rotationExemptEmployees = ['TTV02055'];
$isRotationExemptEmployee = in_array($employeeCodeUpper, $rotationExemptEmployees, true);

$attendanceConfig = App::config('attendance');
$otGraceMinutes = (float) ($attendanceConfig['ot_grace_minutes'] ?? 0);
$dayPreShiftNonOtMinutes = (float) ($attendanceConfig['day_pre_shift_non_ot_minutes'] ?? 0);
$nightPreShiftNonOtMinutes = (float) ($attendanceConfig['night_pre_shift_non_ot_minutes'] ?? 0);
$otBlockMinutes = max(1.0, (float) ($attendanceConfig['ot_rounding_block_minutes'] ?? 30));
$dayShiftStart = (string) ($attendanceConfig['day_shift_start'] ?? '08:00');
$dayShiftEnd = (string) ($attendanceConfig['day_shift_end'] ?? '17:30');
$nightShiftStart = (string) ($attendanceConfig['night_shift_start'] ?? '22:30');
$nightShiftEnd = (string) ($attendanceConfig['night_shift_end'] ?? '08:00');

$normalizeSessionForExpectedShift = static function (
    array $session,
    string $dateStr,
    ?string $expectedByRule,
    bool $isRotationExemptEmployee,
    string $dayShiftStart,
    string $dayShiftEnd,
    string $nightShiftStart,
    string $nightShiftEnd,
    float $graceMinutes,
    float $dayPreShiftNonOtMinutes,
    float $nightPreShiftNonOtMinutes,
    float $otBlockMinutes
): array {
    $rawInTs = strtotime((string) $session['check_in']);
    $rawOutTs = strtotime((string) $session['check_out']);
    if ($rawInTs === false || $rawOutTs === false) {
        return [
            'display_in' => '-',
            'display_out' => '-',
            'swapped' => false,
            'swap_note' => '-',
            'ot_session' => $session,
            'resolved_shift_type' => null,
            'resolve_note' => '-',
        ];
    }

    $rawInText = date('H:i', $rawInTs);
    $rawOutText = date('H:i', $rawOutTs);
    $rawInMinutes = ((int) date('H', $rawInTs)) * 60 + (int) date('i', $rawInTs);
    $rawOutMinutes = ((int) date('H', $rawOutTs)) * 60 + (int) date('i', $rawOutTs);

    $otSession = $session;
    $displayInText = $rawInText;
    $displayOutText = $rawOutText;
    $swapped = false;
    $swapNote = '-';
    $resolvedShiftType = $expectedByRule;
    $resolveNote = '-';

    $buildCandidate = static function (string $candidateShift) use (
        $rawInMinutes,
        $rawOutMinutes,
        $rawInText,
        $rawOutText,
        $dateStr,
        $dayShiftStart,
        $dayShiftEnd,
        $nightShiftStart,
        $nightShiftEnd,
        $graceMinutes,
        $dayPreShiftNonOtMinutes,
        $nightPreShiftNonOtMinutes,
        $otBlockMinutes
    ): ?array {
        $candidateInText = $rawInText;
        $candidateOutText = $rawOutText;
        $candidateSwapped = false;
        $candidateSwapNote = '-';

        if ($candidateShift === 'night' && $rawInMinutes < $rawOutMinutes) {
            $candidateInText = $rawOutText;
            $candidateOutText = $rawInText;
            $candidateSwapped = true;
            $candidateSwapNote = 'night-rule swap';
        }
        if ($candidateShift === 'day' && $rawInMinutes > $rawOutMinutes) {
            $candidateInText = $rawOutText;
            $candidateOutText = $rawInText;
            $candidateSwapped = true;
            $candidateSwapNote = 'day-rule swap';
        }

        $candidateCheckInTs = strtotime($dateStr . ' ' . $candidateInText . ':00');
        $candidateCheckOutTs = strtotime($dateStr . ' ' . $candidateOutText . ':00');
        if ($candidateCheckInTs === false || $candidateCheckOutTs === false) {
            return null;
        }
        if ($candidateCheckOutTs <= $candidateCheckInTs) {
            $candidateCheckOutTs = strtotime('+1 day', $candidateCheckOutTs);
        }

        if ($candidateShift === 'night') {
            $expectedStartTs = strtotime($dateStr . ' ' . $nightShiftStart . ':00');
            $expectedEndTs = strtotime($dateStr . ' ' . $nightShiftEnd . ':00');
            if ($expectedEndTs !== false) {
                $expectedEndTs = strtotime('+1 day', $expectedEndTs);
            }
            $preShiftNonOt = $nightPreShiftNonOtMinutes;
        } else {
            $expectedStartTs = strtotime($dateStr . ' ' . $dayShiftStart . ':00');
            $expectedEndTs = strtotime($dateStr . ' ' . $dayShiftEnd . ':00');
            $preShiftNonOt = $dayPreShiftNonOtMinutes;
        }

        if ($expectedStartTs === false || $expectedEndTs === false) {
            return null;
        }

        $preShiftMinutes = max(0.0, ($expectedStartTs - $candidateCheckInTs) / 60);
        $preShiftOtMinutes = max(0.0, $preShiftMinutes - $preShiftNonOt);
        $postShiftMinutes = max(0.0, ($candidateCheckOutTs - $expectedEndTs) / 60);

        $qualifiedOtMinutes = 0.0;
        if ($preShiftOtMinutes > $graceMinutes) {
            $qualifiedOtMinutes += $preShiftOtMinutes;
        }
        if ($postShiftMinutes > $graceMinutes) {
            $qualifiedOtMinutes += $postShiftMinutes;
        }

        $otBlocks = (int) floor($qualifiedOtMinutes / $otBlockMinutes);
        $otMinutes = (int) ($otBlocks * $otBlockMinutes);
        $durationMinutes = (int) round(($candidateCheckOutTs - $candidateCheckInTs) / 60);

        return [
            'shift_type' => $candidateShift,
            'display_in' => $candidateInText,
            'display_out' => $candidateOutText,
            'swapped' => $candidateSwapped,
            'swap_note' => $candidateSwapNote,
            'check_in_ts' => $candidateCheckInTs,
            'check_out_ts' => $candidateCheckOutTs,
            'expected_start_ts' => $expectedStartTs,
            'expected_end_ts' => $expectedEndTs,
            'ot_minutes' => $otMinutes,
            'qualified_ot_minutes' => (int) round($qualifiedOtMinutes),
            'duration_delta' => abs($durationMinutes - 570),
        ];
    };

    if (empty($session['incomplete_flag'])) {
        if ($resolvedShiftType === null && $isRotationExemptEmployee) {
            $dayCandidate = $buildCandidate('day');
            $nightCandidate = $buildCandidate('night');
            if ($dayCandidate !== null && $nightCandidate !== null) {
                $pickDay = false;
                if ($dayCandidate['ot_minutes'] < $nightCandidate['ot_minutes']) {
                    $pickDay = true;
                } elseif ($dayCandidate['ot_minutes'] > $nightCandidate['ot_minutes']) {
                    $pickDay = false;
                } elseif ($dayCandidate['qualified_ot_minutes'] < $nightCandidate['qualified_ot_minutes']) {
                    $pickDay = true;
                } elseif ($dayCandidate['qualified_ot_minutes'] > $nightCandidate['qualified_ot_minutes']) {
                    $pickDay = false;
                } else {
                    $pickDay = $dayCandidate['duration_delta'] <= $nightCandidate['duration_delta'];
                }

                $selected = $pickDay ? $dayCandidate : $nightCandidate;
                $resolvedShiftType = (string) $selected['shift_type'];
                $resolveNote = 'auto-detected ' . $resolvedShiftType;
                $displayInText = (string) $selected['display_in'];
                $displayOutText = (string) $selected['display_out'];
                $swapped = (bool) $selected['swapped'];
                $swapNote = (string) $selected['swap_note'];
                $otSession['check_in'] = date('Y-m-d H:i:s', (int) $selected['check_in_ts']);
                $otSession['check_out'] = date('Y-m-d H:i:s', (int) $selected['check_out_ts']);
                $otSession['expected_start'] = date('Y-m-d H:i:s', (int) $selected['expected_start_ts']);
                $otSession['expected_end'] = date('Y-m-d H:i:s', (int) $selected['expected_end_ts']);
                $otSession['shift_type'] = $resolvedShiftType;
            }
        }

        if ($resolvedShiftType === null) {
            $resolvedShiftType = (string) ($session['shift_type'] ?? 'day');
        }

        if ($resolveNote === '-') {
            $fixedCandidate = $buildCandidate($resolvedShiftType);
            if ($fixedCandidate !== null) {
                $displayInText = (string) $fixedCandidate['display_in'];
                $displayOutText = (string) $fixedCandidate['display_out'];
                $swapped = (bool) $fixedCandidate['swapped'];
                $swapNote = (string) $fixedCandidate['swap_note'];
                $otSession['check_in'] = date('Y-m-d H:i:s', (int) $fixedCandidate['check_in_ts']);
                $otSession['check_out'] = date('Y-m-d H:i:s', (int) $fixedCandidate['check_out_ts']);
                $otSession['expected_start'] = date('Y-m-d H:i:s', (int) $fixedCandidate['expected_start_ts']);
                $otSession['expected_end'] = date('Y-m-d H:i:s', (int) $fixedCandidate['expected_end_ts']);
                $otSession['shift_type'] = $resolvedShiftType;
            }
        }
    }

    return [
        'display_in' => $displayInText,
        'display_out' => $displayOutText,
        'swapped' => $swapped,
        'swap_note' => $swapNote,
        'ot_session' => $otSession,
        'resolved_shift_type' => $resolvedShiftType,
        'resolve_note' => $resolveNote,
    ];
};

$classifySessionOt = static function (array $session, float $graceMinutes, float $dayPreShiftNonOtMinutes, float $nightPreShiftNonOtMinutes, float $otBlockMinutes): array {
    if (!empty($session['incomplete_flag']) || empty($session['check_in']) || empty($session['check_out']) || empty($session['expected_start']) || empty($session['expected_end'])) {
        return [
            'ot_minutes' => 0,
            'early_non_ot' => false,
            'short_ot' => false,
            'pre_shift_minutes' => 0,
            'pre_shift_ot_minutes' => 0,
            'post_shift_minutes' => 0,
            'qualified_ot_minutes' => 0,
            'ot_blocks' => 0,
        ];
    }

    $checkInTs = strtotime((string) $session['check_in']);
    $checkOutTs = strtotime((string) $session['check_out']);
    $expectedStartTs = strtotime((string) $session['expected_start']);
    $expectedEndTs = strtotime((string) $session['expected_end']);

    if ($checkInTs === false || $checkOutTs === false || $expectedStartTs === false || $expectedEndTs === false) {
        return [
            'ot_minutes' => 0,
            'early_non_ot' => false,
            'short_ot' => false,
            'pre_shift_minutes' => 0,
            'pre_shift_ot_minutes' => 0,
            'post_shift_minutes' => 0,
            'qualified_ot_minutes' => 0,
            'ot_blocks' => 0,
        ];
    }

    $shiftType = (string) ($session['shift_type'] ?? 'day');
    $preShiftNonOt = $shiftType === 'night' ? $nightPreShiftNonOtMinutes : $dayPreShiftNonOtMinutes;

    $preShiftMinutes = max(0.0, ($expectedStartTs - $checkInTs) / 60);
    $preShiftOtMinutes = max(0.0, $preShiftMinutes - $preShiftNonOt);
    $postShiftMinutes = max(0.0, ($checkOutTs - $expectedEndTs) / 60);

    $qualifiedOtMinutes = 0.0;
    if ($preShiftOtMinutes > $graceMinutes) {
        $qualifiedOtMinutes += $preShiftOtMinutes;
    }
    if ($postShiftMinutes > $graceMinutes) {
        $qualifiedOtMinutes += $postShiftMinutes;
    }

    $otBlocks = (int) floor($qualifiedOtMinutes / $otBlockMinutes);
    $otMinutes = (int) ($otBlocks * $otBlockMinutes);

    $isEarlyNonOt = $preShiftMinutes > 0 && $preShiftOtMinutes <= 0;
    $isShortOt = $qualifiedOtMinutes > 0 && $otMinutes <= 0;

    return [
        'ot_minutes' => $otMinutes,
        'early_non_ot' => $isEarlyNonOt,
        'short_ot' => $isShortOt,
        'pre_shift_minutes' => (int) round($preShiftMinutes),
        'pre_shift_ot_minutes' => (int) round($preShiftOtMinutes),
        'post_shift_minutes' => (int) round($postShiftMinutes),
        'qualified_ot_minutes' => (int) round($qualifiedOtMinutes),
        'ot_blocks' => $otBlocks,
    ];
};

$otDaysCount = 0;
$otMinutesTotal = 0;
$missingDays = 0;

$typicalInMinutes = [];
$typicalOutMinutes = [];
foreach ($sessionsByDate as $s) {
    if (!empty($s['incomplete_flag'])) {
        continue;
    }
    if (!empty($s['check_in'])) {
        $typicalInMinutes[] = ((int) date('H', strtotime($s['check_in']))) * 60 + (int) date('i', strtotime($s['check_in']));
    }
    if (!empty($s['check_out'])) {
        $typicalOutMinutes[] = ((int) date('H', strtotime($s['check_out']))) * 60 + (int) date('i', strtotime($s['check_out']));
    }
}

$median = static function (array $values): ?int {
    if (count($values) === 0) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $mid = (int) floor($n / 2);
    if ($n % 2 === 1) {
        return (int) $values[$mid];
    }
    return (int) round((((int) $values[$mid - 1]) + ((int) $values[$mid])) / 2);
};

$circularDistance = static function (int $a, int $b): int {
    $diff = abs($a - $b);
    return min($diff, 1440 - $diff);
};

$typicalInMedian = $median($typicalInMinutes);
$typicalOutMedian = $median($typicalOutMinutes);

$toMinutes = static function (string $dateTime): int {
    $ts = strtotime($dateTime);
    return ((int) date('H', $ts)) * 60 + (int) date('i', $ts);
};

$normalizeShiftCode = static function (?string $raw): ?string {
    $rawText = trim((string) $raw);
    if ($rawText === '') {
        return null;
    }

    $upper = strtoupper($rawText);

    // Explicit English patterns.
    if (
        str_contains($upper, 'SHIFT A')
        || str_contains($upper, 'SHIFT-A')
        || str_contains($upper, 'SHIFTA')
    ) {
        return 'A';
    }
    if (
        str_contains($upper, 'SHIFT B')
        || str_contains($upper, 'SHIFT-B')
        || str_contains($upper, 'SHIFTB')
    ) {
        return 'B';
    }

    // Token-based detection for mixed separators / Thai labels.
    $tokens = preg_split('/[^A-Z0-9ก-๙]+/u', $upper, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($tokens as $token) {
        if ($token === 'A' || $token === '1' || $token === 'เอ') {
            return 'A';
        }
        if ($token === 'B' || $token === '2' || $token === 'บี') {
            return 'B';
        }
    }

    // Fallback for compact forms like A01 / B2.
    if (preg_match('/(^|[^A-Z])A\d*/', $upper)) {
        return 'A';
    }
    if (preg_match('/(^|[^A-Z])B\d*/', $upper)) {
        return 'B';
    }

    return null;
};

$ftmDayShiftCodeByDate = static function (string $workDate): ?string {
    // User-defined FTM rule:
    // - Start calculating this pattern from 2026-01-01
    // - Anchor: 2026-07-26 day shift is A
    // - Then alternate A/B every 14 days in both directions (past/future)
    $patternStartTs = strtotime('2026-01-01');
    $anchorTs = strtotime('2026-07-26');
    $dateTs = strtotime($workDate);

    if ($dateTs < $patternStartTs) {
        return null;
    }

    $daysDiff = (int) floor(($dateTs - $anchorTs) / 86400);
    if ($daysDiff >= 0) {
        $periodIndex = (int) floor($daysDiff / 14);
    } else {
        // Keep 14-day buckets consistent for negative offsets.
        // Example: -1..-14 => -1, -15..-28 => -2
        $periodIndex = -((int) floor((abs($daysDiff) - 1) / 14)) - 1;
    }

    // periodIndex=0 at 2026-07-26 => day shift is A, then alternate every 14 days.
    return ($periodIndex % 2 === 0) ? 'A' : 'B';
};

$inferEmployeeShiftCodeForProject = static function (array $sessionsByDate) use ($ftmDayShiftCodeByDate, $toMinutes): ?string {
    $score = ['A' => 0, 'B' => 0];
    $hasEvidence = false;

    foreach ($sessionsByDate as $workDate => $session) {
        if (empty($session['check_in']) || empty($session['check_out']) || !empty($session['incomplete_flag'])) {
            continue;
        }

        $dayShiftCode = $ftmDayShiftCodeByDate((string) $workDate);
        if ($dayShiftCode === null) {
            continue;
        }

        $inMinutes = $toMinutes((string) $session['check_in']);
        $outMinutes = $toMinutes((string) $session['check_out']);
        $observedType = $inMinutes > $outMinutes ? 'night' : 'day';
        $hasEvidence = true;

        foreach (['A', 'B'] as $candidateShift) {
            $expectedType = ($candidateShift === $dayShiftCode) ? 'day' : 'night';
            $score[$candidateShift] += ($expectedType === $observedType) ? 1 : -1;
        }
    }

    if (!$hasEvidence || $score['A'] === $score['B']) {
        return null;
    }

    return $score['A'] > $score['B'] ? 'A' : 'B';
};

$inferredEmployeeShiftCode = null;
if (!$isRotationExemptEmployee && in_array(($employee['project_code'] ?? ''), ['FTM-SE', 'AAT-SE'], true)) {
    $inferredEmployeeShiftCode = $inferEmployeeShiftCodeForProject($sessionsByDate);
}

$expectedShiftType = static function (array $employeeRow, string $workDate) use ($normalizeShiftCode, $ftmDayShiftCodeByDate, $inferredEmployeeShiftCode, $isRotationExemptEmployee): ?string {
    if ($isRotationExemptEmployee) {
        // Employee is managed ad-hoc by supervisor; do not enforce A/B shift rotation swap rules.
        return null;
    }

    if (!in_array(($employeeRow['project_code'] ?? ''), ['FTM-SE', 'AAT-SE'], true)) {
        return null;
    }

    $empShiftCode = $normalizeShiftCode($employeeRow['shift_code'] ?? null);
    if ($empShiftCode === null) {
        $empShiftCode = $inferredEmployeeShiftCode;
    }
    if ($empShiftCode === null) {
        return null;
    }

    $dayShiftCode = $ftmDayShiftCodeByDate($workDate);
    if ($dayShiftCode === null) {
        return null;
    }
    return $empShiftCode === $dayShiftCode ? 'day' : 'night';
};

$employeeShiftCodeNormalized = $normalizeShiftCode($employee['shift_code'] ?? null);
$effectiveEmployeeShiftCode = $employeeShiftCodeNormalized ?? $inferredEmployeeShiftCode;
$sampleExpectedOnMonthStart = $expectedShiftType($employee, $monthStart);
$sampleDayShiftCodeOnMonthStart = in_array(($employee['project_code'] ?? ''), ['FTM-SE', 'AAT-SE'], true) ? $ftmDayShiftCodeByDate($monthStart) : null;
$debugRows = [];

foreach ($sessionsByDate as $workDate => $s) {
    $expectedByRule = $expectedShiftType($employee, (string) $workDate);
    $normalized = $normalizeSessionForExpectedShift(
        $s,
        (string) $workDate,
        $expectedByRule,
        $isRotationExemptEmployee,
        $dayShiftStart,
        $dayShiftEnd,
        $nightShiftStart,
        $nightShiftEnd,
        $otGraceMinutes,
        $dayPreShiftNonOtMinutes,
        $nightPreShiftNonOtMinutes,
        $otBlockMinutes
    );
    $sessionOt = $classifySessionOt((array) ($normalized['ot_session'] ?? $s), $otGraceMinutes, $dayPreShiftNonOtMinutes, $nightPreShiftNonOtMinutes, $otBlockMinutes);
    $sessionOtMinutes = (int) ($sessionOt['ot_minutes'] ?? 0);
    if ($sessionOtMinutes > 0) {
        $otDaysCount++;
        $otMinutesTotal += $sessionOtMinutes;
    }
}

$backQuery = http_build_query(['project' => $employee['project_code'], 'month' => $month]);
?>
<div class="no-print" style="margin-bottom:12px; display:flex; gap:10px;">
    <a class="btn btn-secondary" href="attendance_review.php?<?= h($backQuery) ?>">← กลับไปรายชื่อ</a>
    <button onclick="window.print()">🖨️ พิมพ์หน้านี้</button>
</div>

<div class="card no-print">
    สรุปเดือนนี้: มีข้อมูลสแกน <?= count($sessionsByDate) ?> วัน จากทั้งหมด <?= $daysInMonth ?> วัน,
    ติดธง OT <strong><?= $otDaysCount ?></strong> วัน (รวม≈<?= floor($otMinutesTotal / 60) ?> ชม. <?= $otMinutesTotal % 60 ?> นาที)
    <?php if (in_array(($employee['project_code'] ?? ''), ['FTM-SE', 'AAT-SE'], true)): ?>
        <br>
        <span class="hint" style="display:inline-block; margin-top:6px;">
            Debug Shift: shift_code ใน Manpower = <?= h((string) ($employee['shift_code'] ?? '-')) ?>,
                อ่านค่าได้ = <?= h($isRotationExemptEmployee ? 'ไม่บังคับ A/B (special case)' : ($effectiveEmployeeShiftCode ?? 'ไม่พบ (จึงไม่สลับตามกะ)')) ?><?= (!$isRotationExemptEmployee && $employeeShiftCodeNormalized === null && $inferredEmployeeShiftCode !== null) ? ' (inferred)' : '' ?>,
            กะเช้าตามรอบวันที่ <?= h($monthStart) ?> = <?= h((string) ($sampleDayShiftCodeOnMonthStart ?? '-')) ?>,
            ผลคาดการณ์ของพนักงานวันที่ <?= h($monthStart) ?> = <?= h((string) ($sampleExpectedOnMonthStart ?? '-')) ?>
        </span>
    <?php endif; ?>
</div>

<h2 class="print-form-title">ใบแจ้งการทำงานล่วงเวลา/ทำงานในวันหยุด/ทำงานล่วงเวลาในวันหยุด</h2>

<table class="print-meta-table">
    <tr>
        <td style="border:none; padding:2px 6px;"><strong>แผนก/ฝ่าย</strong> <?= h($employee['position_name'] ?: $employee['department'] ?: '-') ?></td>
        <td style="border:none; padding:2px 6px; text-align:right;"><strong>เดือน</strong> <?= h($monthLabel) ?></td>
    </tr>
    <tr>
        <td style="border:none; padding:2px 6px;"><strong>รหัสพนักงาน</strong> <?= h($employee['employee_code']) ?></td>
        <td style="border:none; padding:2px 6px; text-align:right;"><strong>ชื่อ-สกุล</strong> <?= h($fullName ?: '-') ?></td>
    </tr>
</table>

<table style="margin-top:10px;">
    <thead>
    <tr>
        <th>Date<br>วันที่</th>
        <th>Day<br>วัน</th>
        <th>In<br>(เข้างาน)</th>
        <th>Out<br>(สิ้นสุด)</th>
        <th>OT รวม (ระบบคำนวณ)</th>
        <th>OT Job Detail<br>ลักษณะการทำงานล่วงเวลา</th>
        <th colspan="3">Total OT Hours<br>รวมเวลาทำงานล่วงเวลา
            <br><span style="font-weight:normal;">(1 / 1.5 / 3)</span>
        </th>
        <th colspan="3">OT Acknowledge<br>รับทราบทำงานล่วงเวลา</th>
        <th>Remark<br>หมายเหตุ</th>
    </tr>
    </thead>
    <tbody>
    <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
        <?php
        $dateStr = sprintf('%s-%02d', $month, $d);
        $ts = strtotime($dateStr);
        $session = $sessionsByDate[$dateStr] ?? null;
        ?>
        <tr>
            <td><?= h(date('d-M-y', $ts)) ?></td>
            <td><?= h(date('l', $ts)) ?></td>
            <?php if ($session === null): ?>
                <?php
                $debugRows[] = [
                    'date' => $dateStr,
                    'expected_day_shift' => in_array(($employee['project_code'] ?? ''), ['FTM-SE', 'AAT-SE'], true) ? ($ftmDayShiftCodeByDate($dateStr) ?? '-') : '-',
                    'employee_shift' => $effectiveEmployeeShiftCode ?? '-',
                    'expected_type' => $expectedShiftType($employee, $dateStr) ?? '-',
                    'raw_in' => '-',
                    'raw_out' => '-',
                    'display_in' => '-',
                    'display_out' => '-',
                    'swapped' => '-',
                    'ot_shift_type' => '-',
                    'ot_expected_start' => '-',
                    'ot_expected_end' => '-',
                    'ot_pre' => '-',
                    'ot_pre_effective' => '-',
                    'ot_post' => '-',
                    'ot_qualified' => '-',
                    'ot_blocks' => '-',
                    'ot_final' => '-',
                    'note' => 'ไม่มีข้อมูลสแกน',
                ];
                ?>
                <td colspan="3" style="text-align:center; color:#9ca3af;">ไม่พบการสแกนนิ้ว</td>
            <?php else: ?>
                <?php
                $expectedByRule = $expectedShiftType($employee, $dateStr);
                $expectedDayShiftCode = in_array(($employee['project_code'] ?? ''), ['FTM-SE', 'AAT-SE'], true) ? ($ftmDayShiftCodeByDate($dateStr) ?? '-') : '-';

                $normalized = $normalizeSessionForExpectedShift(
                    $session,
                    $dateStr,
                    $expectedByRule,
                    $isRotationExemptEmployee,
                    $dayShiftStart,
                    $dayShiftEnd,
                    $nightShiftStart,
                    $nightShiftEnd,
                    $otGraceMinutes,
                    $dayPreShiftNonOtMinutes,
                    $nightPreShiftNonOtMinutes,
                    $otBlockMinutes
                );

                $rawInTs = strtotime((string) $session['check_in']);
                $rawOutTs = strtotime((string) $session['check_out']);
                $rawInText = $rawInTs === false ? '-' : date('H:i', $rawInTs);
                $rawOutText = $rawOutTs === false ? '-' : date('H:i', $rawOutTs);
                $displayInText = (string) ($normalized['display_in'] ?? $rawInText);
                $displayOutText = (string) ($normalized['display_out'] ?? $rawOutText);
                $swapped = (bool) ($normalized['swapped'] ?? false);
                $swapNote = (string) ($normalized['swap_note'] ?? '-');

                $inTime = h($displayInText);
                $outTime = h($displayOutText);
                $inCellHtml = $inTime;
                $outCellHtml = $outTime;
                $sessionOtSource = (array) ($normalized['ot_session'] ?? $session);
                $sessionOt = $classifySessionOt($sessionOtSource, $otGraceMinutes, $dayPreShiftNonOtMinutes, $nightPreShiftNonOtMinutes, $otBlockMinutes);
                $sessionOtMinutes = (int) ($sessionOt['ot_minutes'] ?? 0);
                $sessionEarlyNonOt = (bool) ($sessionOt['early_non_ot'] ?? false);
                $sessionShortOt = (bool) ($sessionOt['short_ot'] ?? false);

                if ($session['incomplete_flag']) {
                    $singleTimeMinutes = ((int) date('H', strtotime($session['check_in']))) * 60 + (int) date('i', strtotime($session['check_in']));
                    $likelyOut = false;

                    // Prefer project-specific shift rule (FTM/AAT) if employee has usable Shift A/B.
                    if ($expectedByRule === 'night') {
                        // Night: scans around afternoon-evening are likely check-in, morning likely check-out.
                        $likelyOut = $singleTimeMinutes < 12 * 60;
                    } elseif ($expectedByRule === 'day') {
                        // Day: scans around morning are likely check-in, late afternoon-evening likely check-out.
                        $likelyOut = $singleTimeMinutes >= 12 * 60;
                    } elseif ($typicalInMedian !== null && $typicalOutMedian !== null) {
                        $distIn = $circularDistance($singleTimeMinutes, $typicalInMedian);
                        $distOut = $circularDistance($singleTimeMinutes, $typicalOutMedian);
                        // กันเคสใกล้กันมากเกินไป: ต้องใกล้ฝั่ง Out มากกว่าอย่างมีนัย
                        $likelyOut = ($distOut + 30) < $distIn;
                    }

                    if ($likelyOut) {
                        $inCellHtml = '<span class="badge badge-incomplete">ไม่สแกน</span>';
                        $outCellHtml = $outTime;
                        $swapNote = $swapNote === '-' ? 'incomplete->likely out' : $swapNote . ', incomplete->likely out';
                    } else {
                        $inCellHtml = $inTime;
                        $outCellHtml = '<span class="badge badge-incomplete">ไม่สแกน</span>';
                        $swapNote = $swapNote === '-' ? 'incomplete->likely in' : $swapNote . ', incomplete->likely in';
                    }
                }

                $debugRows[] = [
                    'date' => $dateStr,
                    'expected_day_shift' => $expectedDayShiftCode,
                    'employee_shift' => $effectiveEmployeeShiftCode ?? '-',
                    'expected_type' => $expectedByRule ?? ((string) ($normalized['resolved_shift_type'] ?? '-')),
                    'raw_in' => $rawInText,
                    'raw_out' => $rawOutText,
                    'display_in' => $displayInText,
                    'display_out' => $displayOutText,
                    'swapped' => $swapped ? 'yes' : 'no',
                    'ot_shift_type' => (string) ($sessionOtSource['shift_type'] ?? '-'),
                    'ot_expected_start' => !empty($sessionOtSource['expected_start']) ? date('H:i', strtotime((string) $sessionOtSource['expected_start'])) : '-',
                    'ot_expected_end' => !empty($sessionOtSource['expected_end']) ? date('H:i', strtotime((string) $sessionOtSource['expected_end'])) : '-',
                    'ot_pre' => (string) ((int) ($sessionOt['pre_shift_minutes'] ?? 0)),
                    'ot_pre_effective' => (string) ((int) ($sessionOt['pre_shift_ot_minutes'] ?? 0)),
                    'ot_post' => (string) ((int) ($sessionOt['post_shift_minutes'] ?? 0)),
                    'ot_qualified' => (string) ((int) ($sessionOt['qualified_ot_minutes'] ?? 0)),
                    'ot_blocks' => (string) ((int) ($sessionOt['ot_blocks'] ?? 0)),
                    'ot_final' => floor($sessionOtMinutes / 60) . ':' . str_pad((string) ($sessionOtMinutes % 60), 2, '0', STR_PAD_LEFT),
                    'note' => (($normalized['resolve_note'] ?? '-') !== '-' ? ((string) $normalized['resolve_note']) . ', ' : '') . (($sessionEarlyNonOt && $swapNote === '-')
                        ? 'early-arrival (non-OT)'
                        : (($sessionEarlyNonOt ? $swapNote . ', early-arrival (non-OT)' : $swapNote) . ($sessionShortOt ? ', under OT block threshold' : ''))),
                ];
                ?>
                <td><?= $inCellHtml ?></td>
                <td><?= $outCellHtml ?></td>
                <td>
                    <?php if ($sessionOtMinutes > 0): ?>
                        <span class="badge badge-ot"><?= floor($sessionOtMinutes / 60) ?>:<?= str_pad((string) ($sessionOtMinutes % 60), 2, '0', STR_PAD_LEFT) ?></span>
                    <?php elseif ($sessionEarlyNonOt): ?>
                        <span class="badge badge-early">มาก่อนเวลา</span>
                    <?php elseif ($sessionShortOt): ?>
                        <span class="badge badge-short-ot">ไม่ถือเป็น OT (ไม่ครบ <?= (int) $otBlockMinutes ?> นาที)</span>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            <?php endif; ?>
            <td>&nbsp;</td>
            <td style="min-width:26px;">&nbsp;</td>
            <td style="min-width:26px;">&nbsp;</td>
            <td style="min-width:26px;">&nbsp;</td>
            <?php if ($d === 1): ?>
                <td rowspan="<?= $daysInMonth ?>" style="min-width:80px;">&nbsp;</td>
                <td rowspan="<?= $daysInMonth ?>" style="min-width:80px;">&nbsp;</td>
                <td rowspan="<?= $daysInMonth ?>" style="min-width:80px;">&nbsp;</td>
            <?php endif; ?>
            <td><?= h($session['reviewer_note'] ?? '') ?>&nbsp;</td>
        </tr>
    <?php endfor; ?>
    <tr>
        <td colspan="5" style="text-align:right;"><strong>Total</strong></td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
        <td>&nbsp;</td>
    </tr>
    </tbody>
</table>

<div class="no-print" style="margin-top:14px;">
    <button type="button" class="btn btn-secondary" id="toggle-shift-debug" style="margin-top:0;">
        แสดง Debug Shift Decision
    </button>
</div>

<div class="card no-print" id="shift-debug-panel" style="margin-top:10px;" hidden>
    <h3 style="margin-top:0; margin-bottom:8px;">Debug Shift Decision (FTM-SE / AAT-SE)</h3>
    <div class="hint" style="margin-bottom:8px;">
        ใช้ตรวจว่าระบบอ่านกะพนักงานและสลับ In/Out ถูกหรือไม่ในแต่ละวัน (กดปุ่มซ่อนเมื่อไม่ใช้งาน)
    </div>
    <div style="overflow:auto;">
        <table style="margin-top:0;">
            <thead>
            <tr>
                <th>Date</th>
                <th>Day Shift ตามรอบ</th>
                <th>Shift พนักงาน</th>
                <th>Expected Type</th>
                <th>Raw In</th>
                <th>Raw Out</th>
                <th>Display In</th>
                <th>Display Out</th>
                <th>Swapped</th>
                <th>OT Shift</th>
                <th>Expected Start</th>
                <th>Expected End</th>
                <th>Pre(min)</th>
                <th>Pre OT(min)</th>
                <th>Post(min)</th>
                <th>Qualified(min)</th>
                <th>Blocks</th>
                <th>OT Final</th>
                <th>Note</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($debugRows as $row): ?>
                <tr>
                    <td><?= h($row['date']) ?></td>
                    <td><?= h($row['expected_day_shift']) ?></td>
                    <td><?= h($row['employee_shift']) ?></td>
                    <td><?= h($row['expected_type']) ?></td>
                    <td><?= h($row['raw_in']) ?></td>
                    <td><?= h($row['raw_out']) ?></td>
                    <td><?= h($row['display_in']) ?></td>
                    <td><?= h($row['display_out']) ?></td>
                    <td><?= h($row['swapped']) ?></td>
                    <td><?= h($row['ot_shift_type']) ?></td>
                    <td><?= h($row['ot_expected_start']) ?></td>
                    <td><?= h($row['ot_expected_end']) ?></td>
                    <td><?= h($row['ot_pre']) ?></td>
                    <td><?= h($row['ot_pre_effective']) ?></td>
                    <td><?= h($row['ot_post']) ?></td>
                    <td><?= h($row['ot_qualified']) ?></td>
                    <td><?= h($row['ot_blocks']) ?></td>
                    <td><?= h($row['ot_final']) ?></td>
                    <td><?= h($row['note']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(() => {
    const toggleButton = document.getElementById('toggle-shift-debug');
    const panel = document.getElementById('shift-debug-panel');
    if (!toggleButton || !panel) {
        return;
    }

    toggleButton.addEventListener('click', () => {
        const isHidden = panel.hasAttribute('hidden');
        if (isHidden) {
            panel.removeAttribute('hidden');
            toggleButton.textContent = 'ซ่อน Debug Shift Decision';
        } else {
            panel.setAttribute('hidden', 'hidden');
            toggleButton.textContent = 'แสดง Debug Shift Decision';
        }
    });
})();
</script>

<div style="margin-top:40px;">
    ลงชื่อพนักงาน .................................................... &nbsp;&nbsp; วันที่ ....................
</div>

<div style="margin-top:30px; display:flex; justify-content:space-between; text-align:center;">
    <div style="border-top:1px solid #333; width:30%; padding-top:6px;">ผู้บังคับบัญชาเบื้องต้น<br>Date ..................</div>
    <div style="border-top:1px solid #333; width:30%; padding-top:6px;">ผู้มีอำนาจอนุมัติ<br>Date ..................</div>
    <div style="border-top:1px solid #333; width:30%; padding-top:6px;">ฝ่ายบุคคล<br>Date ..................</div>
</div>

<style>
    .print-form-title {
        text-align: center;
        margin: 8px 0 10px;
        font-size: 28px;
        line-height: 1.2;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .print-meta-table {
        border: none;
        margin-top: 18px;
        font-size: 17px;
        line-height: 1.45;
        width: 100%;
    }

    .print-meta-table td {
        border: none !important;
        padding: 4px 10px !important;
    }

    .print-meta-table strong {
        font-size: 17px;
    }

    table { font-size: 12px; }
    th, td { border: 1px solid #333 !important; padding: 4px 6px; text-align: center; }
</style>
<?php require __DIR__ . '/partials/footer.php'; ?>
