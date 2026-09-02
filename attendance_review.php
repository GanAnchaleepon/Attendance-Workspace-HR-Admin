<?php
require_once __DIR__ . '/src/bootstrap.php';
Auth::requireLogin();

$pdo = Database::pdo();
$allowedProjectCode = Auth::scopedProjectCode();

$projectCode = $_GET['project'] ?? 'FTM-SE';
if (!in_array($projectCode, ['FTM-SE', 'AAT-SE'], true)) {
    $projectCode = 'FTM-SE';
}
if ($allowedProjectCode !== null) {
    $projectCode = $allowedProjectCode;
}

$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
}
$search = trim((string) ($_GET['q'] ?? ''));
$monthStart = $month . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$recomputeMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recompute_sessions') {
    Csrf::requireValid();

    // ใช้ตอนอัปเดต logic คำนวณกะ/OT ใหม่ (เช่น src/AttendanceSessionBuilder.php) แล้วต้องการให้
    // ข้อมูลที่นำเข้าไว้แล้วในอดีตถูกคำนวณใหม่ตาม logic ล่าสุดด้วย โดยไม่ต้องอัปโหลดไฟล์สแกนซ้ำ
    $codesStmt = $pdo->prepare('SELECT DISTINCT employee_code FROM attendance_scans WHERE project_code = :project');
    $codesStmt->execute(['project' => $projectCode]);
    $employeeCodes = array_column($codesStmt->fetchAll(), 'employee_code');

    $sessionCount = AttendanceSessionBuilder::rebuildForEmployees($employeeCodes, $projectCode);
    $recomputeMessage = "คำนวณกะ/OT ใหม่ให้พนักงาน " . count($employeeCodes) . " คน ({$sessionCount} กะ) เรียบร้อยแล้ว";
}

$shiftScheduleMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_shift_schedule') {
    Csrf::requireValid();

    $scheduleShift = (string) ($_POST['first_week_day_shift'] ?? '');
    if (!in_array($scheduleShift, ShiftSchedule::SHIFTS, true)) {
        $shiftScheduleMessage = ['type' => 'error', 'text' => 'กรุณาเลือกกะให้ถูกต้อง (A หรือ B)'];
    } else {
        ShiftSchedule::saveSetting($projectCode, $month, $scheduleShift, Auth::currentUserId());

        // ตั้งค่าเปลี่ยนแล้วต้องคำนวณกะ/OT ของเดือนนี้ใหม่ทันที ไม่งั้นข้อมูลเก่าจะยังค้างอยู่
        $codesStmt = $pdo->prepare('SELECT DISTINCT employee_code FROM attendance_scans WHERE project_code = :project');
        $codesStmt->execute(['project' => $projectCode]);
        $employeeCodes = array_column($codesStmt->fetchAll(), 'employee_code');
        AttendanceSessionBuilder::rebuildForEmployees($employeeCodes, $projectCode);

        $shiftScheduleMessage = ['type' => 'success', 'text' => "บันทึกกะประจำเดือน {$month} เรียบร้อยแล้ว และคำนวณ OT ใหม่ให้แล้ว"];
    }
}

$currentShiftSchedule = ShiftSchedule::getSetting($projectCode, $month);

$hasSortOrderStmt = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'employees'
            AND COLUMN_NAME = 'sort_order'");
$hasSortOrder = (bool) $hasSortOrderStmt->fetchColumn();

$orderBySql = $hasSortOrder
        ? "ORDER BY FIELD(e.list_type, 'manpower', 'qa'), e.sort_order ASC, e.id ASC"
        : "ORDER BY FIELD(e.list_type, 'manpower', 'qa'), e.id ASC";

$filterSql = '';
$params = [
    'project' => $projectCode,
    'from1' => $monthStart,
    'to1' => $monthEnd,
    'from2' => $monthStart,
    'to2' => $monthEnd,
];

if ($search !== '') {
    $filterSql = " AND (
        e.employee_code LIKE :q_code
        OR CONCAT(COALESCE(e.prefix, ''), COALESCE(e.first_name_th, ''), ' ', COALESCE(e.last_name_th, '')) LIKE :q_full_name
        OR CONCAT(COALESCE(e.first_name_th, ''), ' ', COALESCE(e.last_name_th, '')) LIKE :q_name
        OR e.first_name_th LIKE :q_first_name
        OR e.last_name_th LIKE :q_last_name
        OR e.position_name LIKE :q_position
        OR e.list_type LIKE :q_list_type
    )";
    $searchLike = '%' . $search . '%';
    $params['q_code'] = $searchLike;
    $params['q_full_name'] = $searchLike;
    $params['q_name'] = $searchLike;
    $params['q_first_name'] = $searchLike;
    $params['q_last_name'] = $searchLike;
    $params['q_position'] = $searchLike;
    $params['q_list_type'] = $searchLike;
}

$sql = "SELECT e.*,
        (SELECT COUNT(*) FROM attendance_sessions s
                        WHERE s.employee_code = e.employee_code
                            AND s.project_code = e.project_code
                            AND s.ot_flag = 1
              AND s.work_date BETWEEN :from1 AND :to1) AS ot_count,
        (SELECT COUNT(*) FROM attendance_sessions s
            WHERE s.employee_code = e.employee_code
                            AND s.project_code = e.project_code
              AND s.work_date BETWEEN :from2 AND :to2) AS scan_day_count
        FROM employees e
        WHERE e.project_code = :project
              {$filterSql}
                {$orderBySql}";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

$monthTs = strtotime($monthStart);
$thaiMonths = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
$monthLabel = $thaiMonths[(int) date('n', $monthTs)] . ' ' . (date('Y', $monthTs) + 543);

$pageTitle = 'ตรวจสอบ OT';
require __DIR__ . '/partials/header.php';
?>
<?php if ($recomputeMessage !== null): ?>
    <div class="alert alert-success"><?= h($recomputeMessage) ?></div>
<?php endif; ?>
<div class="card review-hero-card">
    <div class="review-hero">
        <div>
            <div class="eyebrow">Attendance Review</div>
            <h1>ตรวจสอบ OT รายบุคคล</h1>
            <p class="hint review-lead">
                รายชื่อเรียงตามลำดับในไฟล์ Manpower (Manpower หลักก่อน ตามด้วย Manpower QA) — คลิก "ดูรายงาน"
                เพื่อดูตารางเวลาเข้า-ออกงานรายวันของคนนั้นแบบเดียวกับใบแจ้ง OT กระดาษ ทั้งเดือน
                วันไหนไม่มีข้อมูลสแกนนิ้วจะระบุว่า "ไม่พบการสแกนนิ้ว" ไม่เอาไปรวมกับคนอื่น
            </p>
            <form method="post" style="margin-top:8px;" onsubmit="return confirm('คำนวณกะ/OT ใหม่ทั้งหมดของโปรเจคนี้จากข้อมูลสแกนที่มีอยู่? ใช้เวลาสักครู่');">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="recompute_sessions">
                <input type="hidden" name="project" value="<?= h($projectCode) ?>">
                <input type="hidden" name="month" value="<?= h($month) ?>">
                <button type="submit" class="btn btn-secondary">คำนวณกะ/OT ใหม่ทั้งหมด (โปรเจคนี้)</button>
            </form>
        </div>
        <div class="review-hero-meta">
            <div class="review-hero-month">ประจำเดือน <?= h($monthLabel) ?></div>
            <div class="review-hero-project"><?= $projectCode === 'AAT-SE' ? 'TTV AAT-SE' : 'TTV FTM-SE' ?></div>
        </div>
    </div>
    <div class="review-summary-grid">
        <div class="review-summary-card">
            <span>พนักงานทั้งหมด</span>
            <strong><?= count($employees) ?></strong>
        </div>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">ตั้งค่ากะประจำเดือน (<?= h($monthLabel) ?>)</h2>
    <p class="hint">
        ระบุว่า "สัปดาห์แรกของเดือนนี้" (สัปดาห์ปฏิทิน จันทร์-อาทิตย์) กะ A หรือ B เข้าเช้า
        สัปดาห์ถัดไปในเดือนเดียวกันระบบจะสลับ A/B ให้อัตโนมัติ แล้วนำไปจับคู่กับคอลัมน์ Shift ของพนักงานแต่ละคนในไฟล์ Manpower
        ถ้าไม่ตั้งค่าไว้ ระบบจะเดากะจากรูปแบบเวลาสแกนจริงแทน
    </p>
    <?php if ($shiftScheduleMessage !== null): ?>
        <div class="alert <?= $shiftScheduleMessage['type'] === 'error' ? 'alert-error' : 'alert-success' ?>">
            <?= h($shiftScheduleMessage['text']) ?>
        </div>
    <?php endif; ?>
    <?php if ($currentShiftSchedule !== null): ?>
        <p>ค่าที่ตั้งไว้ตอนนี้: กะ <strong><?= h($currentShiftSchedule['first_week_day_shift']) ?></strong> เข้าเช้าในสัปดาห์แรกของเดือนนี้</p>
    <?php else: ?>
        <p class="hint">ยังไม่ได้ตั้งค่าเดือนนี้</p>
    <?php endif; ?>
    <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="save_shift_schedule">
        <input type="hidden" name="project" value="<?= h($projectCode) ?>">
        <input type="hidden" name="month" value="<?= h($month) ?>">
        <div>
            <label for="first_week_day_shift">สัปดาห์แรกของเดือนนี้ กะเช้าคือ</label>
            <select id="first_week_day_shift" name="first_week_day_shift">
                <?php foreach (ShiftSchedule::SHIFTS as $shiftOption): ?>
                    <option value="<?= h($shiftOption) ?>" <?= ($currentShiftSchedule['first_week_day_shift'] ?? '') === $shiftOption ? 'selected' : '' ?>>กะ <?= h($shiftOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit">บันทึกและคำนวณ OT ใหม่</button>
    </form>
</div>

<form method="get" class="card review-filters">
    <div class="review-filter-search review-filter-field">
        <label for="q">ค้นหาพนักงาน</label>
        <input type="text" id="q" name="q" value="<?= h($search) ?>" placeholder="ชื่อ, รหัสพนักงาน, ตำแหน่ง, ประเภท">
    </div>
    <div class="review-filter-field">
        <label for="project">โปรเจค</label>
        <?php if ($allowedProjectCode !== null): ?>
            <input type="hidden" name="project" value="<?= h($allowedProjectCode) ?>">
            <select id="project" disabled>
                <option value="<?= h($allowedProjectCode) ?>" selected><?= h($allowedProjectCode === 'FTM-SE' ? 'TTV FTM-SE' : 'TTV AAT-SE') ?></option>
            </select>
        <?php else: ?>
            <select id="project" name="project">
                <option value="FTM-SE" <?= $projectCode === 'FTM-SE' ? 'selected' : '' ?>>TTV FTM-SE</option>
                <option value="AAT-SE" <?= $projectCode === 'AAT-SE' ? 'selected' : '' ?>>TTV AAT-SE</option>
            </select>
        <?php endif; ?>
    </div>
    <div class="review-filter-field">
        <label for="month">เดือน</label>
        <input type="month" id="month" name="month" value="<?= h($month) ?>">
    </div>
    <div class="review-filter-actions">
        <button type="submit" class="review-search-btn">ค้นหา</button>
        <?php if ($search !== ''): ?>
            <a class="btn btn-secondary review-clear-btn" href="attendance_review.php?project=<?= h(urlencode($projectCode)) ?>&month=<?= h(urlencode($month)) ?>">ล้างคำค้น</a>
        <?php endif; ?>
    </div>
</form>

<div class="card review-table-card">
    <div class="review-table-header">
        <div>
            <h2>รายชื่อพนักงาน</h2>
            <p>พบพนักงาน <?= count($employees) ?> คน</p>
        </div>
    </div>
    <div class="review-table-wrap">
    <table class="review-table">
        <thead>
        <tr>
            <th class="cell-nowrap col-report">รายงาน</th>
            <th class="cell-nowrap col-order">ลำดับ</th>
            <th class="cell-nowrap col-type">ประเภท</th>
            <th class="cell-nowrap col-empcode">รหัสพนักงาน</th>
            <th class="col-name">ชื่อ-สกุล</th>
            <th class="col-position">ตำแหน่ง</th>
            <th class="col-scan col-head-multiline">วันที่มีสแกน<br><span>(เดือนนี้)</span></th>
            <th class="col-ot col-head-multiline">วันที่ติดธง OT<br><span>(เดือนนี้)</span></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($employees as $index => $e): ?>
            <?php $fullName = trim(($e['prefix'] ?? '') . ($e['first_name_th'] ?? '') . ' ' . ($e['last_name_th'] ?? '')); ?>
            <?php $displayOrder = $hasSortOrder && isset($e['sort_order']) && $e['sort_order'] !== null ? $e['sort_order'] : ($index + 1); ?>
            <tr class="review-row">
                <td class="col-action col-report">
                    <a class="btn review-action-btn"
                       href="print_ot_form.php?employee_code=<?= h(urlencode($e['employee_code'])) ?>&project=<?= h(urlencode($e['project_code'] ?? $projectCode)) ?>&month=<?= h($month) ?>">
                       ดูรายงาน
                    </a>
                </td>
                <td class="cell-nowrap col-order"><?= h((string) $displayOrder) ?></td>
                <td class="cell-nowrap col-type"><?= $e['list_type'] === 'qa' ? 'Manpower QA' : 'Manpower' ?></td>
                <td class="cell-nowrap col-empcode"><?= h($e['employee_code']) ?></td>
                <td class="col-name"><?= h($fullName ?: '-') ?></td>
                <td class="col-position"><?= h($e['position_name']) ?></td>
                <td class="col-scan"><?= (int) $e['scan_day_count'] ?> วัน</td>
                <td class="col-ot">
                    <?php if ((int) $e['ot_count'] > 0): ?>
                        <span class="badge badge-ot">⚠ <?= (int) $e['ot_count'] ?> วัน</span>
                    <?php else: ?>
                        <span class="badge badge-ok">ปกติ</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (count($employees) === 0): ?>
            <tr><td colspan="8" class="hint">ไม่พบพนักงานในโปรเจคนี้ กรุณาอัปเดต Manpower ก่อน</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
