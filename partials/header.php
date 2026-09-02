<?php
/** @var string $pageTitle */
$bodyClass = isset($bodyClass) ? trim((string) $bodyClass) : '';
$extraStylesheets = isset($extraStylesheets) && is_array($extraStylesheets) ? $extraStylesheets : [];
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle ?? 'ระบบตรวจสอบเวลาการทำงาน') ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Crect width='100' height='100' rx='20' fill='%232563eb'/%3E%3Ctext x='50' y='68' font-size='58' font-family='Arial, sans-serif' font-weight='700' fill='%23ffffff' text-anchor='middle'%3EOT%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/css/style.css">
<?php foreach ($extraStylesheets as $stylesheet): ?>
<link rel="stylesheet" href="<?= h($stylesheet) ?>">
<?php endforeach; ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . h($bodyClass) . '"' : '' ?>>
<?php if (Auth::check()): ?>
<header class="topbar">
    <div class="topbar-title">ระบบตรวจสอบข้อมูลเวลาการทำงานเบื้องต้น</div>
    <nav class="topbar-nav">
        <a href="dashboard.php">หน้าหลัก</a>
        <a href="manpower_upload.php">อัปเดต Manpower</a>
        <a href="attendance_upload.php">อัปโหลดไฟล์สแกนนิ้ว</a>
        <a href="attendance_review.php">ตรวจสอบ OT</a>
    </nav>
    <div class="topbar-user">
        <span><?= h(Auth::currentDisplayName()) ?></span>
        <a href="logout.php" class="btn-link">ออกจากระบบ</a>
    </div>
</header>
<?php endif; ?>
<main class="container">
