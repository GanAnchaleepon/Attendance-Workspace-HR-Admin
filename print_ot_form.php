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

// เซสชันที่มีสแกนแค่ครั้งเดียว (incomplete_flag) ไม่รู้ว่าเป็นเวลาเข้าหรือออก
// ให้เทียบกับ expected_start/expected_end ที่ AttendanceSessionBuilder คำนวณไว้แล้วว่าใกล้ฝั่งไหนมากกว่า
$circularDistance = static function (int $a, int $b): int {
    $diff = abs($a - $b);
    return min($diff, 1440 - $diff);
};
$toMinutesOfDay = static function (string $dateTime): int {
    $ts = strtotime($dateTime);
    return ((int) date('H', $ts)) * 60 + (int) date('i', $ts);
};

$otDaysCount = 0;
$otMinutesTotal = 0;
foreach ($sessionsByDate as $s) {
    $minutes = (int) ($s['ot_minutes'] ?? 0);
    if ($minutes > 0) {
        $otDaysCount++;
        $otMinutesTotal += $minutes;
    }
}

$debugRows = [];
$backQuery = http_build_query(['project' => $employee['project_code'], 'month' => $month]);
?>
<div class="no-print" style="margin-bottom:12px; display:flex; gap:10px;">
    <a class="btn btn-secondary" href="attendance_review.php?<?= h($backQuery) ?>">← กลับไปรายชื่อ</a>
    <button onclick="window.print()">🖨️ พิมพ์หน้านี้</button>
</div>

<div class="card no-print">
    สรุปเดือนนี้: มีข้อมูลสแกน <?= count($sessionsByDate) ?> วัน จากทั้งหมด <?= $daysInMonth ?> วัน,
    ติดธง OT <strong><?= $otDaysCount ?></strong> วัน (รวม≈<?= floor($otMinutesTotal / 60) ?> ชม. <?= $otMinutesTotal % 60 ?> นาที)
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
                    'shift_type' => '-',
                    'check_in' => '-',
                    'check_out' => '-',
                    'expected_start' => '-',
                    'expected_end' => '-',
                    'ot_final' => '-',
                    'note' => 'ไม่มีข้อมูลสแกน',
                ];
                ?>
                <td colspan="3" style="text-align:center; color:#9ca3af;">ไม่พบการสแกนนิ้ว</td>
            <?php else: ?>
                <?php
                // ประเภทกะ/เวลาเข้า-ออก/OT ทั้งหมดคำนวณไว้แล้วที่ AttendanceSessionBuilder (single source of truth)
                // หน้านี้แค่นำมาแสดง ไม่มีการคำนวณซ้ำหรือสลับเวลาเข้า-ออกอีก
                $inText = h(date('H:i', strtotime((string) $session['check_in'])));
                $outText = h(date('H:i', strtotime((string) $session['check_out'])));
                $inCellHtml = $inText;
                $outCellHtml = $outText;
                $note = '-';

                if ($session['incomplete_flag']) {
                    // มีสแกนแค่ครั้งเดียว: เทียบเวลาที่สแกนได้กับเวลาที่ควรเข้า/ควรออกของกะนั้น ว่าใกล้ฝั่งไหนกว่า
                    $scanMinutes = $toMinutesOfDay((string) $session['check_in']);
                    $distStart = !empty($session['expected_start']) ? $circularDistance($scanMinutes, $toMinutesOfDay((string) $session['expected_start'])) : null;
                    $distEnd = !empty($session['expected_end']) ? $circularDistance($scanMinutes, $toMinutesOfDay((string) $session['expected_end'])) : null;
                    $likelyOut = $distStart !== null && $distEnd !== null && $distEnd < $distStart;

                    if ($likelyOut) {
                        $inCellHtml = '<span class="badge badge-incomplete">ไม่สแกน</span>';
                        $note = 'สแกนครั้งเดียว: เข้าใจว่าเป็นเวลาออก';
                    } else {
                        $outCellHtml = '<span class="badge badge-incomplete">ไม่สแกน</span>';
                        $note = 'สแกนครั้งเดียว: เข้าใจว่าเป็นเวลาเข้า';
                    }
                }

                $sessionOtMinutes = (int) ($session['ot_minutes'] ?? 0);

                $debugRows[] = [
                    'date' => $dateStr,
                    'shift_type' => (string) ($session['shift_type'] ?? '-'),
                    'check_in' => $inText,
                    'check_out' => $outText,
                    'expected_start' => !empty($session['expected_start']) ? date('H:i', strtotime((string) $session['expected_start'])) : '-',
                    'expected_end' => !empty($session['expected_end']) ? date('H:i', strtotime((string) $session['expected_end'])) : '-',
                    'ot_final' => floor($sessionOtMinutes / 60) . ':' . str_pad((string) ($sessionOtMinutes % 60), 2, '0', STR_PAD_LEFT),
                    'note' => $note,
                ];
                ?>
                <td><?= $inCellHtml ?></td>
                <td><?= $outCellHtml ?></td>
                <td>
                    <?php if ($sessionOtMinutes > 0): ?>
                        <span class="badge badge-ot"><?= floor($sessionOtMinutes / 60) ?>:<?= str_pad((string) ($sessionOtMinutes % 60), 2, '0', STR_PAD_LEFT) ?></span>
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
    <h3 style="margin-top:0; margin-bottom:8px;">Debug Shift Decision</h3>
    <div class="hint" style="margin-bottom:8px;">
        ค่าที่แสดงมาจาก attendance_sessions โดยตรง (คำนวณครั้งเดียวตอนนำเข้าไฟล์สแกน ที่ AttendanceSessionBuilder
        ไม่มีการคำนวณซ้ำที่หน้านี้อีก) ใช้ตรวจว่าระบบตัดสินกะเช้า/กะดึกของแต่ละวันถูกหรือไม่ (กดปุ่มซ่อนเมื่อไม่ใช้งาน)
    </div>
    <div style="overflow:auto;">
        <table style="margin-top:0;">
            <thead>
            <tr>
                <th>Date</th>
                <th>Shift</th>
                <th>Check-in</th>
                <th>Check-out</th>
                <th>Expected Start</th>
                <th>Expected End</th>
                <th>OT Final</th>
                <th>Note</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($debugRows as $row): ?>
                <tr>
                    <td><?= h($row['date']) ?></td>
                    <td><?= h($row['shift_type']) ?></td>
                    <td><?= h($row['check_in']) ?></td>
                    <td><?= h($row['check_out']) ?></td>
                    <td><?= h($row['expected_start']) ?></td>
                    <td><?= h($row['expected_end']) ?></td>
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
