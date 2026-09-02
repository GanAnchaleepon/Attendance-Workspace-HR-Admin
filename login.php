<?php
require_once __DIR__ . '/src/bootstrap.php';

if (Auth::check()) {
    redirect('dashboard.php');
}

if (!Auth::hasAnyUser()) {
    redirect('setup.php');
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $result = Auth::attemptLogin($username, $password);
        if ($result['ok']) {
            redirect('dashboard.php');
        }
        $error = $result['message'];
    }
}

$pageTitle = 'เข้าสู่ระบบ';
$bodyClass = 'login-page';
$extraStylesheets = ['assets/css/login.css'];
require __DIR__ . '/partials/header.php';
?>
<section class="login-scene">
    <div class="login-orb login-orb-a"></div>
    <div class="login-orb login-orb-b"></div>

    <div class="login-layout">
        <div class="login-story">
            <div class="login-kicker">Attendance Workspace</div>
            <h1>ระบบตรวจสอบข้อมูลเวลาการทำงานที่อ่านง่ายและพร้อมใช้งานจริง</h1>
            <p>
                ตรวจสอบเวลาสแกน, คัดกรองรายชื่อพนักงาน, และเปิดรายงาน OT รายเดือน
                จากหน้าเดียวที่ออกแบบให้ใช้งานคล่องทั้งในสำนักงานและบนโฮสต์จริง
            </p>

            <div class="login-story-grid">
                <article class="login-story-card">
                    <strong>ค้นหาเร็ว</strong>
                    <span>เปิดรายงานรายบุคคลและกรองตามชื่อหรือรหัสได้ทันที</span>
                </article>
                <article class="login-story-card">
                    <strong>รวมข้อมูลครบ</strong>
                    <span>เชื่อม Manpower และไฟล์สแกนนิ้วเพื่อทบทวน OT ในจุดเดียว</span>
                </article>
                <article class="login-story-card">
                    <strong>พร้อมใช้งานจริง</strong>
                    <span>ออกแบบให้เหมาะกับจอทำงานทั่วไปและรองรับการใช้งานบนมือถือ</span>
                </article>
            </div>
        </div>

        <div class="login-box">
            <div class="login-box-panel">
                <div class="login-box-head">
                    <span class="login-badge">Secure Access</span>
                    <h2>เข้าสู่ระบบ</h2>
                    <p>กรอกชื่อผู้ใช้และรหัสผ่านเพื่อเข้าสู่หน้าจัดการข้อมูล</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-error"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="post" novalidate class="login-form">
                    <?= Csrf::field() ?>

                    <div class="login-input-group">
                        <label for="username">ชื่อผู้ใช้</label>
                        <input type="text" id="username" name="username" required autofocus autocomplete="username" placeholder="กรอกชื่อผู้ใช้">
                    </div>

                    <div class="login-input-group">
                        <label for="password">รหัสผ่าน</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="กรอกรหัสผ่าน">
                    </div>

                    <button type="submit" class="login-submit-btn" id="login-submit-btn">เข้าสู่ระบบ</button>

                    <div id="login-status-message" class="login-status-message" aria-live="polite" hidden>
                        <span class="spinner-icon" aria-hidden="true"></span>
                        <span>กำลังตรวจสอบข้อมูล... กรุณารอสักครู่</span>
                    </div>
                </form>

                <div class="login-box-foot">เข้าสู่ระบบเพื่ออัปโหลดไฟล์และตรวจสอบ OT ประจำเดือน</div>
            </div>
        </div>
    </div>
</section>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var loginForm = document.querySelector('.login-form');
    var statusMessage = document.getElementById('login-status-message');
    var submitButton = document.getElementById('login-submit-btn');

    if (!loginForm || !statusMessage || !submitButton) {
        return;
    }

    loginForm.addEventListener('submit', function () {
        statusMessage.hidden = false;
        submitButton.disabled = true;
        submitButton.textContent = 'กำลังเข้าสู่ระบบ...';
    });
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
