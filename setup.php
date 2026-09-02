<?php
require_once __DIR__ . '/src/bootstrap.php';

// หน้านี้ใช้ครั้งแรกสุดของการติดตั้งเท่านั้น เพื่อสร้างบัญชีผู้ดูแลระบบบัญชีแรก
// เมื่อมีผู้ใช้ในระบบแล้ว หน้านี้จะใช้งานไม่ได้อีก (redirect ไป login เสมอ)
if (Auth::hasAnyUser()) {
    redirect('login.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $displayName = trim($_POST['display_name'] ?? '') ?: null;

    if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $username)) {
        $error = 'ชื่อผู้ใช้ต้องมีความยาว 3-50 ตัวอักษร ใช้ได้เฉพาะ a-z, 0-9, . _ -';
    } elseif (mb_strlen($password) < 8) {
        $error = 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร';
    } elseif ($password !== $confirm) {
        $error = 'รหัสผ่านยืนยันไม่ตรงกัน';
    } else {
        Auth::createUser($username, $password, $displayName);
        redirect('login.php');
    }
}

$pageTitle = 'ตั้งค่าบัญชีผู้ดูแลระบบครั้งแรก';
require __DIR__ . '/partials/header.php';
?>
<div class="login-wrap">
    <div class="card">
        <h1>ตั้งค่าบัญชีผู้ดูแลระบบ (ครั้งแรก)</h1>
        <p class="hint">ยังไม่มีบัญชีผู้ใช้ในระบบ กรุณาสร้างบัญชีผู้ดูแลระบบก่อนเริ่มใช้งาน</p>
        <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" novalidate>
            <?= Csrf::field() ?>
            <label for="username">ชื่อผู้ใช้</label>
            <input type="text" id="username" name="username" required autofocus>

            <label for="display_name">ชื่อที่แสดง (ไม่บังคับ)</label>
            <input type="text" id="display_name" name="display_name">

            <label for="password">รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label>
            <input type="password" id="password" name="password" required autocomplete="new-password">

            <label for="confirm_password">ยืนยันรหัสผ่าน</label>
            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">

            <button type="submit">สร้างบัญชี</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
