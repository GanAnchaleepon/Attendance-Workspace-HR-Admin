<?php
require_once __DIR__ . '/src/bootstrap.php';
Auth::requireLogin();

const ATT_MAX_UPLOAD_BYTES = 60 * 1024 * 1024; // 60MB
const ATT_PROCESSING_TIMEOUT_SECONDS = 600; // 10 minutes

$result = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // รองรับไฟล์ใหญ่/แถวจำนวนมาก: ขยายเวลาและหน่วยความจำให้รอประมวลผลได้นานขึ้น
    @ini_set('max_execution_time', (string) ATT_PROCESSING_TIMEOUT_SECONDS);
    @ini_set('max_input_time', (string) ATT_PROCESSING_TIMEOUT_SECONDS);
    @ini_set('memory_limit', '512M');
    if (function_exists('set_time_limit')) {
        @set_time_limit(ATT_PROCESSING_TIMEOUT_SECONDS);
    }

    Csrf::requireValid();

    $projectCode = $_POST['project_code'] ?? 'ALL';
    $validProjects = ['FTM-SE', 'AAT-SE'];

    if ($projectCode !== 'ALL' && !in_array($projectCode, $validProjects, true)) {
        $errors[] = 'กรุณาเลือกโปรเจคให้ถูกต้อง';
    }

    $file = $_FILES['attendance_file'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'กรุณาเลือกไฟล์สแกนนิ้วมือ';
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "อัปโหลดไฟล์ไม่สำเร็จ (รหัสข้อผิดพลาด {$file['error']})";
    } elseif ($file['size'] > ATT_MAX_UPLOAD_BYTES) {
        $errors[] = 'ไฟล์มีขนาดใหญ่เกิน 60MB';
    } elseif (strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $errors[] = 'ไฟล์ต้องเป็น .csv เท่านั้น';
    } elseif (!is_uploaded_file($file['tmp_name'])) {
        $errors[] = 'เกิดข้อผิดพลาดด้านความปลอดภัยของไฟล์ที่อัปโหลด';
    }

    if (empty($errors)) {
        try {
            $targets = $projectCode === 'ALL' ? $validProjects : [$projectCode];

            $result = [
                'inserted' => 0,
                'duplicate' => 0,
                'other_project' => 0,
                'invalid_rows' => 0,
                'auto_resolved' => 0,
                'session_count' => 0,
                'employee_codes_total' => 0,
                'invalid_samples' => [],
                'by_project' => [],
            ];

            foreach ($targets as $targetProject) {
                $import = AttendanceImporter::import($file['tmp_name'], $file['name'], $targetProject);
                $sessionCount = AttendanceSessionBuilder::rebuildForEmployees($import['employee_codes'], $targetProject);

                $result['inserted'] += (int) $import['inserted'];
                $result['duplicate'] += (int) $import['duplicate'];
                $result['other_project'] += (int) $import['other_project'];
                $result['invalid_rows'] += (int) $import['invalid_rows'];
                $result['auto_resolved'] += (int) ($import['auto_resolved'] ?? 0);
                if (!empty($import['invalid_samples']) && count($result['invalid_samples']) < 40) {
                    $remaining = 40 - count($result['invalid_samples']);
                    $result['invalid_samples'] = array_merge(
                        $result['invalid_samples'],
                        array_slice($import['invalid_samples'], 0, $remaining)
                    );
                }
                $result['session_count'] += (int) $sessionCount;
                $result['employee_codes_total'] += count($import['employee_codes']);
                $result['by_project'][$targetProject] = [
                    'inserted' => (int) $import['inserted'],
                    'duplicate' => (int) $import['duplicate'],
                    'invalid_rows' => (int) $import['invalid_rows'],
                    'auto_resolved' => (int) ($import['auto_resolved'] ?? 0),
                    'invalid_samples' => $import['invalid_samples'] ?? [],
                    'employee_count' => count($import['employee_codes']),
                    'session_count' => (int) $sessionCount,
                ];
            }
        } catch (Throwable $e) {
            $errors[] = 'ประมวลผลไฟล์ไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'อัปโหลดไฟล์สแกนนิ้ว';
require __DIR__ . '/partials/header.php';
?>
<h1>อัปโหลดไฟล์สแกนนิ้ว</h1>
<p class="hint">
    ไฟล์สแกนนิ้วมือ 1 ไฟล์สามารถมีข้อมูลหลายโปรเจครวมกันได้
    แนะนำให้เลือก "อัปโหลดรวมทุกโปรเจค" เพื่อให้ระบบแยกแถวเข้า TTV FTM-SE / TTV AAT-SE อัตโนมัติ
    แล้วคำนวณเวลาเข้า-ออกงานและติดธง OT ให้แต่ละโปรเจคในรอบเดียว
</p>
<p class="hint">
    รองรับไฟล์ขนาดใหญ่ได้มากขึ้น (สูงสุด 60MB) และขยายเวลาประมวลผลได้ถึงประมาณ 10 นาที
    ระหว่างประมวลผลให้รอหน้าเดิมจนเสร็จ ไม่ต้องปิดหน้าเว็บ
</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php if ($result): ?>
    <div class="alert alert-success">
        นำเข้าสำเร็จ: เพิ่มข้อมูลสแกนใหม่ <?= (int) $result['inserted'] ?> รายการ,
        ข้ามรายการซ้ำ <?= (int) $result['duplicate'] ?> รายการ,
        ข้ามแถวข้อมูลไม่ถูกต้อง <?= (int) $result['invalid_rows'] ?> แถว,
        เติมรหัสพนักงานอัตโนมัติ <?= (int) $result['auto_resolved'] ?> แถว
        — คำนวณกะการทำงานใหม่ให้พนักงาน <?= (int) $result['employee_codes_total'] ?> คน
        (<?= (int) $result['session_count'] ?> กะ)
    </div>

    <?php if (!empty($result['by_project'])): ?>
        <div class="card" style="margin-top:12px;">
            <h2 style="margin-top:0;">สรุปแยกตามโปรเจค</h2>
            <table>
                <thead>
                <tr>
                    <th>โปรเจค</th>
                    <th>เพิ่มใหม่</th>
                    <th>รายการซ้ำ</th>
                    <th>แถวไม่ถูกต้อง</th>
                    <th>เติมรหัสอัตโนมัติ</th>
                    <th>พนักงานที่คำนวณใหม่</th>
                    <th>กะที่สร้าง/อัปเดต</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['by_project'] as $code => $projectResult): ?>
                    <tr>
                        <td><?= h($code === 'FTM-SE' ? 'TTV FTM-SE' : ($code === 'AAT-SE' ? 'TTV AAT-SE' : $code)) ?></td>
                        <td><?= (int) $projectResult['inserted'] ?></td>
                        <td><?= (int) $projectResult['duplicate'] ?></td>
                        <td><?= (int) $projectResult['invalid_rows'] ?></td>
                        <td><?= (int) ($projectResult['auto_resolved'] ?? 0) ?></td>
                        <td><?= (int) $projectResult['employee_count'] ?></td>
                        <td><?= (int) $projectResult['session_count'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ((int) $result['invalid_rows'] > 0 && !empty($result['invalid_samples'])): ?>
        <?php
        $reasonLabels = [
            'empty_department' => 'แผนกว่าง',
            'missing_datetime' => 'วัน/เวลาว่าง',
            'missing_employee_code_after_fallback' => 'รหัสพนักงานว่าง และเติมอัตโนมัติไม่ได้',
            'invalid_datetime_format' => 'วัน/เวลาไม่ถูกต้อง',
        ];
        ?>
        <div class="card" style="margin-top:12px;">
            <h2 style="margin-top:0;">ดีบัคแถวไม่ถูกต้อง (ตัวอย่างสูงสุด 40 แถว)</h2>
            <table>
                <thead>
                <tr>
                    <th>เหตุผล</th>
                    <th>แผนก</th>
                    <th>รหัสที่เครื่อง</th>
                    <th>ชื่อ</th>
                    <th>วัน/เวลา</th>
                    <th>รหัสพนักงาน (ดิบ)</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($result['invalid_samples'] as $sample): ?>
                    <?php $reasonKey = (string) ($sample['reason'] ?? ''); ?>
                    <tr>
                        <td><?= h($reasonLabels[$reasonKey] ?? $reasonKey) ?></td>
                        <td><?= h((string) ($sample['department'] ?? '')) ?></td>
                        <td><?= h((string) ($sample['machine_code'] ?? '')) ?></td>
                        <td><?= h((string) ($sample['name'] ?? '')) ?></td>
                        <td><?= h((string) ($sample['datetime'] ?? '')) ?></td>
                        <td><?= h((string) ($sample['employee_code_raw'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <p><a class="btn" href="attendance_review.php">ไปหน้าตรวจสอบ OT</a></p>
<?php endif; ?>

<div class="card">
    <form id="attendance-upload-form" method="post" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label for="project_code">โปรเจค</label>
        <select id="project_code" name="project_code" required>
            <option value="ALL" selected>อัปโหลดรวมทุกโปรเจค (แนะนำ)</option>
            <option value="FTM-SE">TTV FTM-SE</option>
            <option value="AAT-SE">TTV AAT-SE</option>
        </select>

        <label for="attendance_file">ไฟล์สแกนนิ้วมือ (.csv)</label>
        <input type="file" id="attendance_file" name="attendance_file" accept=".csv" required>

        <button type="submit" id="attendance-upload-submit">อัปโหลดและประมวลผล</button>
    </form>
</div>

<div id="upload-progress-overlay" class="upload-progress-overlay">
    <div class="upload-progress-box">
        <h3 id="upload-progress-title">กำลังอัปโหลดไฟล์...</h3>
        <div class="upload-progress-track">
            <div id="upload-progress-bar" class="upload-progress-bar" style="width:0%;"></div>
        </div>
        <p id="upload-progress-percent">0%</p>
        <p id="upload-progress-hint" class="hint">กรุณาอย่าปิดหน้านี้จนกว่าจะเสร็จสิ้น</p>
    </div>
</div>

<style>
.upload-progress-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1000;
}
.upload-progress-overlay.is-visible {
    display: flex;
}
.upload-progress-box {
    background: #fff;
    border-radius: 12px;
    padding: 28px 32px;
    width: 320px;
    max-width: 90vw;
    text-align: center;
    box-shadow: 0 10px 30px rgba(0,0,0,0.25);
}
.upload-progress-box h3 { margin: 0 0 16px; font-size: 16px; }
.upload-progress-track {
    background: #e5e7eb;
    border-radius: 999px;
    height: 14px;
    overflow: hidden;
}
.upload-progress-bar {
    height: 100%;
    background: var(--color-primary, #2563eb);
    transition: width .2s ease;
}
.upload-progress-bar.indeterminate {
    width: 40% !important;
    animation: upload-progress-indeterminate 1.1s infinite ease-in-out;
}
@keyframes upload-progress-indeterminate {
    0% { margin-left: 0%; }
    50% { margin-left: 60%; }
    100% { margin-left: 0%; }
}
#upload-progress-percent { margin: 12px 0 4px; font-size: 20px; font-weight: 700; color: #0f172a; }
</style>

<script>
(() => {
    const form = document.getElementById('attendance-upload-form');
    const overlay = document.getElementById('upload-progress-overlay');
    const bar = document.getElementById('upload-progress-bar');
    const percentText = document.getElementById('upload-progress-percent');
    const title = document.getElementById('upload-progress-title');
    const hint = document.getElementById('upload-progress-hint');

    if (!form || !overlay || !window.XMLHttpRequest || !window.FormData) {
        return;
    }

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        const formData = new FormData(form);

        overlay.classList.add('is-visible');
        bar.classList.remove('indeterminate');
        bar.style.width = '0%';
        percentText.textContent = '0%';
        title.textContent = 'กำลังอัปโหลดไฟล์...';
        hint.textContent = 'กรุณาอย่าปิดหน้านี้จนกว่าจะเสร็จสิ้น';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', form.getAttribute('action') || window.location.pathname, true);

        xhr.upload.addEventListener('progress', (progressEvent) => {
            if (!progressEvent.lengthComputable) {
                return;
            }
            const percent = Math.round((progressEvent.loaded / progressEvent.total) * 100);
            bar.style.width = percent + '%';
            percentText.textContent = percent + '%';

            if (percent >= 100) {
                title.textContent = 'อัปโหลดเสร็จแล้ว กำลังประมวลผลข้อมูล...';
                hint.textContent = 'ไฟล์ใหญ่อาจใช้เวลาหลายนาที ระบบกำลังคำนวณเวลาเข้า-ออกงานและ OT ให้อยู่';
                bar.classList.add('indeterminate');
            }
        });

        xhr.addEventListener('load', () => {
            document.open();
            document.write(xhr.responseText);
            document.close();
        });

        xhr.addEventListener('error', () => {
            overlay.classList.remove('is-visible');
            alert('ไม่สามารถอัปโหลดไฟล์ได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง');
        });

        xhr.addEventListener('timeout', () => {
            overlay.classList.remove('is-visible');
            alert('การอัปโหลด/ประมวลผลใช้เวลานานเกินไป กรุณาลองใหม่อีกครั้ง หรือแบ่งไฟล์ให้เล็กลง');
        });

        xhr.timeout = 10 * 60 * 1000; // 10 นาที ให้สอดคล้องกับเวลาประมวลผลฝั่งเซิร์ฟเวอร์
        xhr.send(formData);
    });
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
