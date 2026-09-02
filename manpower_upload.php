<?php
require_once __DIR__ . '/src/bootstrap.php';
Auth::requireLogin();

const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10MB

$validProjects = ['FTM-SE', 'AAT-SE'];
$allowedProjectCode = Auth::scopedProjectCode();
if ($allowedProjectCode !== null) {
    $validProjects = [$allowedProjectCode];
}

$results = [];
$errors = [];
$allEmployees = [];

$projectOptions = [];
$typeOptions = ['manpower', 'qa'];
$codeOptions = [];
$nameOptions = [];
$positionOptions = [];
$departmentOptions = [];
$employeeTypeOptions = [];
$startDateOptions = [];

$filterProject = trim((string) ($_GET['filter_project'] ?? ''));
if ($filterProject !== '' && !in_array($filterProject, $validProjects, true)) {
    $filterProject = '';
}
if ($allowedProjectCode !== null) {
    $filterProject = $allowedProjectCode;
}

$filterType = trim((string) ($_GET['filter_type'] ?? ''));
if ($filterType !== '' && !in_array($filterType, $typeOptions, true)) {
    $filterType = '';
}

$filterCode = trim((string) ($_GET['filter_code'] ?? ''));
$filterName = trim((string) ($_GET['filter_name'] ?? ''));
$filterPosition = trim((string) ($_GET['filter_position'] ?? ''));
$filterDepartment = trim((string) ($_GET['filter_department'] ?? ''));
$filterEmployeeType = trim((string) ($_GET['filter_employee_type'] ?? ''));
$filterStartDate = trim((string) ($_GET['filter_start_date'] ?? ''));
$filterText = trim((string) ($_GET['filter_text'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::requireValid();

    $projectCode = $allowedProjectCode ?? ($_POST['project_code'] ?? '');

    if (!in_array($projectCode, $validProjects, true)) {
        $errors[] = 'กรุณาเลือกโปรเจคให้ถูกต้อง';
    } else {
        $uploads = [
            'manpower_file' => 'manpower',
            'qa_file' => 'qa',
        ];

        $anyFileProvided = false;

        foreach ($uploads as $fieldName => $listType) {
            $file = $_FILES[$fieldName] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $anyFileProvided = true;

            if ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = "อัปโหลดไฟล์ {$fieldName} ไม่สำเร็จ (รหัสข้อผิดพลาด {$file['error']})";
                continue;
            }

            if ($file['size'] > MAX_UPLOAD_BYTES) {
                $errors[] = "ไฟล์ {$file['name']} มีขนาดใหญ่เกิน 10MB";
                continue;
            }

            $extension = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($extension !== 'csv') {
                $errors[] = "ไฟล์ {$file['name']} ต้องเป็นไฟล์ .csv เท่านั้น";
                continue;
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                $errors[] = 'เกิดข้อผิดพลาดด้านความปลอดภัยของไฟล์ที่อัปโหลด';
                continue;
            }

            try {
                $result = ManpowerImporter::import(
                    $file['tmp_name'],
                    $file['name'],
                    $projectCode,
                    $listType,
                    Auth::currentUserId()
                );
                $results[] = ['list_type' => $listType, 'file_name' => $file['name']] + $result;
            } catch (Throwable $e) {
                $errors[] = "ประมวลผลไฟล์ {$file['name']} ไม่สำเร็จ: " . $e->getMessage();
            }
        }

        if (!$anyFileProvided) {
            $errors[] = 'กรุณาเลือกไฟล์อย่างน้อย 1 ไฟล์ (Manpower หรือ Manpower QA)';
        }
    }
}

try {
    $pdo = Database::pdo();
    $nameExpr = 'COALESCE(
                    NULLIF(TRIM(CONCAT_WS(" ", prefix, first_name_th, last_name_th)), ""),
                    NULLIF(TRIM(CONCAT_WS(" ", first_name_en, last_name_en)), ""),
                    "-"
                )';

    $sql = 'SELECT
                project_code,
                list_type,
                employee_code,
                sort_order,
                prefix,
                first_name_th,
                last_name_th,
                first_name_en,
                last_name_en,
                position_name,
                department,
                employee_type,
                start_date_text
            FROM employees
            WHERE 1=1';

    $params = [];

    if ($allowedProjectCode !== null) {
        $sql .= ' AND project_code = :allowed_project';
        $params['allowed_project'] = $allowedProjectCode;
    }

    if ($filterProject !== '') {
        $sql .= ' AND project_code = :filter_project';
        $params['filter_project'] = $filterProject;
    }

    if ($filterType !== '') {
        $sql .= ' AND list_type = :filter_type';
        $params['filter_type'] = $filterType;
    }

    if ($filterCode !== '') {
        $sql .= ' AND employee_code = :filter_code';
        $params['filter_code'] = $filterCode;
    }

    if ($filterName !== '') {
        $sql .= " AND {$nameExpr} = :filter_name";
        $params['filter_name'] = $filterName;
    }

    if ($filterPosition !== '') {
        $sql .= ' AND COALESCE(position_name, "") = :filter_position';
        $params['filter_position'] = $filterPosition;
    }

    if ($filterDepartment !== '') {
        $sql .= ' AND COALESCE(department, "") = :filter_department';
        $params['filter_department'] = $filterDepartment;
    }

    if ($filterEmployeeType !== '') {
        $sql .= ' AND COALESCE(employee_type, "") = :filter_employee_type';
        $params['filter_employee_type'] = $filterEmployeeType;
    }

    if ($filterStartDate !== '') {
        $sql .= ' AND COALESCE(start_date_text, "") = :filter_start_date';
        $params['filter_start_date'] = $filterStartDate;
    }

    if ($filterText !== '') {
        $sql .= ' AND (
                    employee_code LIKE :q_code
                    OR ' . $nameExpr . ' LIKE :q_name
                    OR COALESCE(position_name, "") LIKE :q_position
                    OR COALESCE(department, "") LIKE :q_department
                    OR COALESCE(employee_type, "") LIKE :q_employee_type
                    OR COALESCE(start_date_text, "") LIKE :q_start_date
                 )';
        $params['q_code'] = '%' . $filterText . '%';
        $params['q_name'] = '%' . $filterText . '%';
        $params['q_position'] = '%' . $filterText . '%';
        $params['q_department'] = '%' . $filterText . '%';
        $params['q_employee_type'] = '%' . $filterText . '%';
        $params['q_start_date'] = '%' . $filterText . '%';
    }

    $sql .= ' ORDER BY project_code ASC, list_type ASC,
                (sort_order IS NULL) ASC, sort_order ASC, employee_code ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allEmployees = $stmt->fetchAll();

    $projectSql = 'SELECT DISTINCT project_code FROM employees WHERE project_code IS NOT NULL AND project_code <> ""';
    $projectParams = [];
    if ($allowedProjectCode !== null) {
        $projectSql .= ' AND project_code = :allowed_project';
        $projectParams['allowed_project'] = $allowedProjectCode;
    }
    $projectSql .= ' ORDER BY project_code';
    $projectStmt = $pdo->prepare($projectSql);
    $projectStmt->execute($projectParams);
    $projectOptions = $projectStmt->fetchAll(PDO::FETCH_COLUMN);

    $codeOptions = $pdo->query(
        'SELECT DISTINCT employee_code FROM employees WHERE employee_code IS NOT NULL AND employee_code <> "" ORDER BY employee_code'
    )->fetchAll(PDO::FETCH_COLUMN);

    $nameOptions = $pdo->query(
        "SELECT DISTINCT {$nameExpr} AS full_name
         FROM employees
         WHERE {$nameExpr} <> '-'
         ORDER BY full_name"
    )->fetchAll(PDO::FETCH_COLUMN);

    $positionOptions = $pdo->query(
        'SELECT DISTINCT position_name FROM employees WHERE position_name IS NOT NULL AND position_name <> "" ORDER BY position_name'
    )->fetchAll(PDO::FETCH_COLUMN);

    $departmentOptions = $pdo->query(
        'SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department <> "" ORDER BY department'
    )->fetchAll(PDO::FETCH_COLUMN);

    $employeeTypeOptions = $pdo->query(
        'SELECT DISTINCT employee_type FROM employees WHERE employee_type IS NOT NULL AND employee_type <> "" ORDER BY employee_type'
    )->fetchAll(PDO::FETCH_COLUMN);

    $startDateOptions = $pdo->query(
        'SELECT DISTINCT start_date_text FROM employees WHERE start_date_text IS NOT NULL AND start_date_text <> "" ORDER BY start_date_text'
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $errors[] = 'ไม่สามารถดึงข้อมูลพนักงานที่อัปโหลดล่าสุดได้: ' . $e->getMessage();
}

$pageTitle = 'อัปเดตข้อมูล Manpower';
require __DIR__ . '/partials/header.php';
?>
<h1>อัปเดตข้อมูล Manpower</h1>
<?php if ($allowedProjectCode !== null): ?>
    <p class="hint">บัญชีนี้ถูกจำกัดสิทธิ์ให้จัดการเฉพาะโปรเจค <strong><?= h($allowedProjectCode === 'FTM-SE' ? 'TTV FTM-SE' : 'TTV AAT-SE') ?></strong></p>
<?php endif; ?>
<p class="hint">อัปโหลดไฟล์ Manpower หลัก และ/หรือ Manpower QA ของโปรเจคที่เลือก
ระบบจะเทียบกับข้อมูลครั้งล่าสุด หากรายชื่อไม่เปลี่ยนแปลงจะไม่แก้ไขฐานข้อมูล
แต่หากมีการเปลี่ยนแปลง ระบบจะแทนที่รายชื่อเดิมทั้งหมดด้วยไฟล์ใหม่ (คนที่ออกแล้วจะถูกลบออกอัตโนมัติ)</p>

<?php foreach ($errors as $error): ?>
    <div class="alert alert-error"><?= h($error) ?></div>
<?php endforeach; ?>

<?php foreach ($results as $result): ?>
    <div class="alert <?= $result['changed'] ? 'alert-success' : 'alert-info' ?>">
        <strong><?= h($result['file_name']) ?></strong>
        (<?= $result['list_type'] === 'qa' ? 'Manpower QA' : 'Manpower' ?>):
        <?= h($result['message']) ?>
        — พบพนักงาน <?= (int) $result['employee_count'] ?> คน
        <?php if ($result['skipped_rows'] > 0): ?>
            (ข้าม <?= (int) $result['skipped_rows'] ?> แถวที่ไม่มีรหัสพนักงานที่ถูกต้อง)
        <?php endif; ?>
    </div>
<?php endforeach; ?>

<div class="card manpower-data-card">
    <form id="manpower-upload-form" method="post" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label for="project_code">โปรเจค</label>
        <?php if ($allowedProjectCode !== null): ?>
            <input type="hidden" name="project_code" value="<?= h($allowedProjectCode) ?>">
            <select id="project_code" disabled>
                <option value="<?= h($allowedProjectCode) ?>" selected><?= h($allowedProjectCode === 'FTM-SE' ? 'TTV FTM-SE' : 'TTV AAT-SE') ?></option>
            </select>
        <?php else: ?>
            <select id="project_code" name="project_code" required>
                <option value="FTM-SE">TTV FTM-SE</option>
                <option value="AAT-SE">TTV AAT-SE</option>
            </select>
        <?php endif; ?>

        <label for="manpower_file">ไฟล์ Manpower หลัก (.csv)</label>
        <input type="file" id="manpower_file" name="manpower_file" accept=".csv">

        <label for="qa_file">ไฟล์ Manpower QA (.csv)</label>
        <input type="file" id="qa_file" name="qa_file" accept=".csv">

        <p class="hint">สามารถอัปโหลดไฟล์เดียว หรือทั้งสองไฟล์พร้อมกันก็ได้</p>
        <p class="hint">หากเจอ 403 Forbidden ให้เลี่ยงชื่อไฟล์ที่มีอักขระพิเศษ เช่น ' ( ) [ ] หรือช่องว่างหลายตัว ระบบหน้านี้จะแปลงชื่อไฟล์ให้อัตโนมัติก่อนส่ง</p>

        <button type="submit">อัปโหลดและประมวลผล</button>
    </form>
</div>

<div class="card">
    <h2>ข้อมูล Manpower ทั้งหมดที่อยู่ในระบบ</h2>
    <p class="hint">แสดงข้อมูลพนักงานทั้งหมดที่ถูกนำเข้าล่าสุด (ทั้ง Manpower และ Manpower QA)</p>

    <form id="manpower-table-filter-form" method="get"></form>

    <p class="hint">ผลลัพธ์ทั้งหมด <?= count($allEmployees) ?> รายการ</p>
    <p><a href="manpower_upload.php" class="btn btn-secondary" style="margin-top:0;">ล้างตัวกรอง</a></p>

    <?php if (empty($allEmployees)): ?>
        <p class="hint">ยังไม่มีข้อมูลพนักงานในระบบ กรุณาอัปโหลดไฟล์ก่อน</p>
    <?php else: ?>
        <div class="manpower-table-wrap">
            <table class="manpower-data-table">
                <thead>
                <tr>
                    <th>โปรเจค</th>
                    <th>ประเภท</th>
                    <th>รหัสพนักงาน</th>
                    <th>ชื่อ-นามสกุล</th>
                    <th>ตำแหน่ง</th>
                    <th>แผนก</th>
                    <th>ประเภทพนักงาน</th>
                    <th>วันที่เริ่มงาน</th>
                </tr>
                <tr class="manpower-filter-row">
                    <th>
                        <?php if ($allowedProjectCode !== null): ?>
                            <input type="hidden" name="filter_project" value="<?= h($allowedProjectCode) ?>" form="manpower-table-filter-form">
                            <select id="filter_project" disabled>
                                <option value="<?= h($allowedProjectCode) ?>" selected><?= h($allowedProjectCode) ?></option>
                            </select>
                        <?php else: ?>
                            <select id="filter_project" name="filter_project" form="manpower-table-filter-form" onchange="this.form.submit()">
                                <option value="">ทุกโปรเจค</option>
                                <?php foreach ($projectOptions as $option): ?>
                                    <option value="<?= h((string) $option) ?>" <?= $filterProject === (string) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </th>
                    <th>
                        <select id="filter_type" name="filter_type" form="manpower-table-filter-form" onchange="this.form.submit()">
                            <option value="">ทุกประเภท</option>
                            <?php foreach ($typeOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $filterType === $option ? 'selected' : '' ?>><?= h($option === 'qa' ? 'Manpower QA' : 'Manpower') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th>
                        <select id="filter_code" name="filter_code" form="manpower-table-filter-form" onchange="this.form.submit()">
                            <option value="">รหัสทั้งหมด</option>
                            <?php foreach ($codeOptions as $option): ?>
                                <option value="<?= h((string) $option) ?>" <?= $filterCode === (string) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th>
                        <select id="filter_name" name="filter_name" form="manpower-table-filter-form" onchange="this.form.submit()">
                            <option value="">ชื่อทั้งหมด</option>
                            <?php foreach ($nameOptions as $option): ?>
                                <option value="<?= h((string) $option) ?>" <?= $filterName === (string) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th>
                        <select id="filter_position" name="filter_position" form="manpower-table-filter-form" onchange="this.form.submit()">
                            <option value="">ตำแหน่งทั้งหมด</option>
                            <?php foreach ($positionOptions as $option): ?>
                                <option value="<?= h((string) $option) ?>" <?= $filterPosition === (string) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th>
                        <select id="filter_department" name="filter_department" form="manpower-table-filter-form" onchange="this.form.submit()">
                            <option value="">แผนกทั้งหมด</option>
                            <?php foreach ($departmentOptions as $option): ?>
                                <option value="<?= h((string) $option) ?>" <?= $filterDepartment === (string) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th>
                        <select id="filter_employee_type" name="filter_employee_type" form="manpower-table-filter-form" onchange="this.form.submit()">
                            <option value="">ประเภทพนักงานทั้งหมด</option>
                            <?php foreach ($employeeTypeOptions as $option): ?>
                                <option value="<?= h((string) $option) ?>" <?= $filterEmployeeType === (string) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                    <th>
                        <select id="filter_start_date" name="filter_start_date" form="manpower-table-filter-form" onchange="this.form.submit()">
                            <option value="">วันที่เริ่มงานทั้งหมด</option>
                            <?php foreach ($startDateOptions as $option): ?>
                                <option value="<?= h((string) $option) ?>" <?= $filterStartDate === (string) $option ? 'selected' : '' ?>><?= h((string) $option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </th>
                </tr>
                <tr class="manpower-filter-search-row">
                    <th colspan="8">
                        <div class="manpower-filter-search-wrap">
                            <input
                                type="text"
                                id="filter_text"
                                name="filter_text"
                                form="manpower-table-filter-form"
                                value="<?= h($filterText) ?>"
                                placeholder="พิมพ์ค้นหา: รหัสพนักงาน, ชื่อ, ตำแหน่ง, แผนก, ประเภทพนักงาน, วันที่เริ่มงาน"
                            >
                            <button type="submit" form="manpower-table-filter-form">ค้นหา</button>
                        </div>
                    </th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($allEmployees as $emp): ?>
                    <?php
                    $nameTh = trim((string) (($emp['prefix'] ?? '') . ' ' . ($emp['first_name_th'] ?? '') . ' ' . ($emp['last_name_th'] ?? '')));
                    $nameEn = trim((string) (($emp['first_name_en'] ?? '') . ' ' . ($emp['last_name_en'] ?? '')));
                    $fullName = $nameTh !== '' ? $nameTh : ($nameEn !== '' ? $nameEn : '-');
                    ?>
                    <tr>
                        <td><?= h((string) $emp['project_code']) ?></td>
                        <td><?= h($emp['list_type'] === 'qa' ? 'Manpower QA' : 'Manpower') ?></td>
                        <td><?= h((string) $emp['employee_code']) ?></td>
                        <td><?= h($fullName) ?></td>
                        <td><?= h((string) ($emp['position_name'] ?? '-')) ?></td>
                        <td><?= h((string) ($emp['department'] ?? '-')) ?></td>
                        <td><?= h((string) ($emp['employee_type'] ?? '-')) ?></td>
                        <td><?= h((string) ($emp['start_date_text'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
(() => {
    const form = document.getElementById('manpower-upload-form');
    if (!form || !window.FormData || !window.fetch || !window.File) {
        return;
    }

    const sanitizeFilename = (name) => {
        const dotIndex = name.lastIndexOf('.');
        const base = dotIndex > 0 ? name.slice(0, dotIndex) : name;
        const ext = dotIndex > 0 ? name.slice(dotIndex) : '';

        const safeBase = base
            .normalize('NFKC')
            .replace(/\s+/g, '_')
            .replace(/[^A-Za-z0-9._-]/g, '_')
            .replace(/_+/g, '_')
            .replace(/^_+|_+$/g, '');

        const finalBase = safeBase || 'upload_file';
        return `${finalBase}${ext.toLowerCase()}`;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const formData = new FormData(form);
        const fileInputNames = ['manpower_file', 'qa_file'];

        for (const inputName of fileInputNames) {
            const input = form.querySelector(`input[name="${inputName}"]`);
            if (!input || !input.files || input.files.length === 0) {
                continue;
            }

            const originalFile = input.files[0];
            const safeName = sanitizeFilename(originalFile.name);
            const safeFile = new File([originalFile], safeName, {
                type: originalFile.type || 'text/csv',
                lastModified: originalFile.lastModified,
            });

            formData.delete(inputName);
            formData.append(inputName, safeFile, safeName);
        }

        try {
            const response = await fetch(form.getAttribute('action') || window.location.pathname, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
            });

            const html = await response.text();
            document.open();
            document.write(html);
            document.close();
        } catch (error) {
            alert('ไม่สามารถอัปโหลดไฟล์ได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง');
        }
    });
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
