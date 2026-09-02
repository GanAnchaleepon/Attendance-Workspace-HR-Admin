<?php
require_once __DIR__ . '/src/bootstrap.php';
Auth::requireLogin();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $username = trim((string) ($_POST['username'] ?? ''));
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $username)) {
        $error = 'ชื่อผู้ใช้ต้องมีความยาว 3-50 ตัวอักษร ใช้ได้เฉพาะ a-z, 0-9, . _ -';
    } elseif (mb_strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านยืนยันไม่ตรงกัน';
    } else {
        try {
            Auth::createUser($username, $password, $displayName !== '' ? $displayName : null);
            $success = 'สร้างผู้ใช้ใหม่เรียบร้อยแล้ว';
        } catch (PDOException $e) {
            $isDuplicate = ($e->getCode() === '23000');
            $error = $isDuplicate
                ? 'ชื่อผู้ใช้นี้มีอยู่แล้ว กรุณาใช้ชื่ออื่น'
                : 'ไม่สามารถสร้างผู้ใช้ได้: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'เพิ่มผู้ใช้ (ชั่วคราว)';
require __DIR__ . '/partials/header.php';
?>

<div class="card" style="max-width:720px; margin:0 auto;">
    <h1>เพิ่มผู้ใช้ใหม่ (ไฟล์ชั่วคราว)</h1>
    <div class="alert alert-info">
        หน้านี้ใช้สำหรับเพิ่มผู้ใช้ใหม่เท่านั้น หลังใช้งานเสร็จสามารถลบไฟล์ <strong>temp_create_user.php</strong> ได้ทันที
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= h($success) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
        <?= Csrf::field() ?>

        <label for="username">ชื่อผู้ใช้</label>
        <input type="text" id="username" name="username" required maxlength="50" autocomplete="off">

        <label for="display_name">ชื่อที่แสดง (ไม่บังคับ)</label>
        <input type="text" id="display_name" name="display_name" maxlength="100" autocomplete="off">

        <label for="password">รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label>
        <input type="password" id="password" name="password" required autocomplete="new-password">

        <label for="confirm_password">ยืนยันรหัสผ่าน</label>
        <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">

        <button type="submit">สร้างผู้ใช้ใหม่</button>
        <a class="btn btn-secondary" href="dashboard.php" style="margin-left:8px;">กลับหน้า Dashboard</a>
    </form>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
