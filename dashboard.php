<?php
require_once __DIR__ . '/src/bootstrap.php';
Auth::requireLogin();

$pdo = Database::pdo();
$allowedProjectCode = Auth::scopedProjectCode();

if ($allowedProjectCode !== null) {
    $pendingStmt = $pdo->prepare(
        "SELECT COUNT(*) AS c FROM attendance_sessions WHERE ot_flag = 1 AND status = 'pending' AND project_code = :project"
    );
    $pendingStmt->execute(['project' => $allowedProjectCode]);
    $pendingOtCount = (int) $pendingStmt->fetch()['c'];

    $employeeStmt = $pdo->prepare('SELECT COUNT(*) AS c FROM employees WHERE project_code = :project');
    $employeeStmt->execute(['project' => $allowedProjectCode]);
    $employeeCount = (int) $employeeStmt->fetch()['c'];
} else {
    $pendingOtCount = (int) $pdo->query(
        "SELECT COUNT(*) AS c FROM attendance_sessions WHERE ot_flag = 1 AND status = 'pending'"
    )->fetch()['c'];
    $employeeCount = (int) $pdo->query('SELECT COUNT(*) AS c FROM employees')->fetch()['c'];
}

$projects = [
    'FTM-SE' => 'TTV FTM-SE',
    'AAT-SE' => 'TTV AAT-SE',
];

if ($allowedProjectCode !== null) {
    $projects = [$allowedProjectCode => $projects[$allowedProjectCode] ?? $allowedProjectCode];
}

$employeeByProject = array_fill_keys(array_keys($projects), 0);
$empSql = 'SELECT project_code, COUNT(*) AS c FROM employees';
$empParams = [];
if ($allowedProjectCode !== null) {
    $empSql .= ' WHERE project_code = :project';
    $empParams['project'] = $allowedProjectCode;
}
$empSql .= ' GROUP BY project_code';
$empStmt = $pdo->prepare($empSql);
$empStmt->execute($empParams);
foreach ($empStmt->fetchAll() as $row) {
    $code = (string) $row['project_code'];
    if (isset($employeeByProject[$code])) {
        $employeeByProject[$code] = (int) $row['c'];
    }
}

$pendingOtByProject = array_fill_keys(array_keys($projects), 0);
$otSql = "SELECT project_code, COUNT(*) AS c
    FROM attendance_sessions
    WHERE ot_flag = 1 AND status = 'pending'";
$otParams = [];
if ($allowedProjectCode !== null) {
    $otSql .= ' AND project_code = :project';
    $otParams['project'] = $allowedProjectCode;
}
$otSql .= ' GROUP BY project_code';
$otStmt = $pdo->prepare($otSql);
$otStmt->execute($otParams);
foreach ($otStmt->fetchAll() as $row) {
    $code = (string) $row['project_code'];
    if (isset($pendingOtByProject[$code])) {
        $pendingOtByProject[$code] = (int) $row['c'];
    }
}

$pageTitle = 'หน้าหลัก';
require __DIR__ . '/partials/header.php';
?>
<h1>หน้าหลัก</h1>
<?php if ($allowedProjectCode !== null): ?>
    <p class="hint">บัญชีนี้ถูกจำกัดสิทธิ์ให้ดูได้เฉพาะโปรเจค <strong><?= h($projects[$allowedProjectCode] ?? $allowedProjectCode) ?></strong></p>
<?php else: ?>
    <p class="hint">โปรเจคที่รองรับในระบบ: <strong>TTV FTM-SE</strong> และ <strong>TTV AAT-SE</strong></p>
<?php endif; ?>

<div class="grid-cards">
    <a class="menu-card" href="manpower_upload.php">
        <div class="icon">📋</div>
        <h3>1. อัปเดตข้อมูล Manpower</h3>
        <p>อัปโหลดไฟล์ Manpower และ Manpower QA ล่าสุดจาก HR (อัปเมื่อรายชื่อมีการเปลี่ยนแปลงเท่านั้น)</p>
    </a>
    <a class="menu-card" href="attendance_upload.php">
        <div class="icon">🖐️</div>
        <h3>2. อัปโหลดไฟล์สแกนนิ้ว</h3>
        <p>นำเข้าไฟล์สแกนลายนิ้วมือ ระบบจะแยกตามโปรเจคและคำนวณเวลาเข้า-ออกงานให้อัตโนมัติ</p>
    </a>
    <a class="menu-card" href="attendance_review.php<?= $allowedProjectCode !== null ? '?project=' . urlencode($allowedProjectCode) : '' ?>">
        <div class="icon">⚠️</div>
        <h3>3. ตรวจสอบ OT</h3>
        <p>ดูรายการที่ระบบติดธงว่าอาจเป็น OT (พบ <strong><?= $pendingOtCount ?></strong> รายการที่รอตรวจสอบ) พร้อมพิมพ์ใบแจ้ง OT</p>
    </a>
</div>

<div class="card" style="margin-top:20px">
    <h2>สรุปข้อมูลปัจจุบัน</h2>
    <p>จำนวนพนักงานทั้งหมดในระบบ (ทุกโปรเจค/ทุกประเภท): <strong><?= $employeeCount ?></strong> คน</p>
    <p>แยกจำนวนพนักงานตามโปรเจค:</p>
    <ul>
        <?php foreach ($projects as $code => $name): ?>
            <li><?= h($name) ?>: <strong><?= (int) $employeeByProject[$code] ?></strong> คน</li>
        <?php endforeach; ?>
    </ul>
    <p>รายการที่ติดธง OT และยังไม่ถูกตรวจสอบ: <strong><?= $pendingOtCount ?></strong> รายการ</p>
    <p>แยก OT ที่รอตรวจสอบตามโปรเจค:</p>
    <ul>
        <?php foreach ($projects as $code => $name): ?>
            <li><?= h($name) ?>: <strong><?= (int) $pendingOtByProject[$code] ?></strong> รายการ</li>
        <?php endforeach; ?>
    </ul>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
