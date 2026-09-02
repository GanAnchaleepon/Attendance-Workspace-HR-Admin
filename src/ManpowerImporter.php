<?php
declare(strict_types=1);

/**
 * นำเข้าไฟล์ Manpower / Manpower QA
 * - อ่าน CSV แบบยืดหยุ่นเรื่องหัวตาราง (ดู CsvTable.php)
 * - เทียบ "ลายเซ็นข้อมูล" (hash) กับการนำเข้าครั้งล่าสุดของโปรเจค+ประเภทเดียวกัน
 *   ถ้าไม่มีอะไรเปลี่ยน จะไม่แตะฐานข้อมูล
 * - ถ้าเปลี่ยน จะ "แทนที่ทั้งหมด" ของ project_code+list_type นั้น (ยึดไฟล์ใหม่เสมอ)
 */
final class ManpowerImporter
{
    private const HEADER_HINTS = ['รหัสพนักงาน', 'empcode', 'emp.code', 'employeecode', 'emp code'];

    /**
     * @return array{changed: bool, message: string, employee_count: int, skipped_rows: int}
     */
    public static function import(string $filePath, string $originalFilename, string $projectCode, string $listType, ?int $userId): array
    {
        $table = CsvTable::fromFile($filePath, self::HEADER_HINTS);
        return self::importFromTable($table, $originalFilename, $projectCode, $listType, $userId);
    }

    /**
     * นำเข้าจากแถวข้อมูลที่แปลงมาแล้ว (เช่นชีตหนึ่งของไฟล์ .xlsx ที่มีหลายชีต) แทนการอ่านไฟล์ CSV โดยตรง
     *
     * @param array<int, array<int, string>> $rows
     * @return array{changed: bool, message: string, employee_count: int, skipped_rows: int}
     */
    public static function importFromRows(array $rows, string $originalFilename, string $projectCode, string $listType, ?int $userId): array
    {
        $table = CsvTable::fromRows($rows, self::HEADER_HINTS);
        return self::importFromTable($table, $originalFilename, $projectCode, $listType, $userId);
    }

    /**
     * @return array{changed: bool, message: string, employee_count: int, skipped_rows: int}
     */
    private static function importFromTable(CsvTable $table, string $originalFilename, string $projectCode, string $listType, ?int $userId): array
    {
        // resolve ทีละ field แล้วกันคอลัมน์ที่ถูกใช้ไปแล้วไม่ให้ field อื่นจับซ้ำ
        // (บางไฟล์ เช่น Manpower QA คอลัมน์ "นามสกุลไทย" และ "นามสกุลอังกฤษ" มีหัวตารางย่อยเป็นคำว่า "Lastname" เหมือนกัน)
        $used = [];
        $resolve = function (array $aliases) use ($table, &$used): ?int {
            $idx = $table->findColumn($aliases, $used);
            if ($idx !== null) {
                $used[] = $idx;
            }
            return $idx;
        };

        $colSeq       = $resolve(['ลำดับ', 'no.']);
        $colCode      = $resolve(['รหัสพนักงาน', 'empcode', 'emp.code', 'employeecode']);
        $colPrefix    = $resolve(['คำนำหน้า']);
        $colFirstTh   = $resolve(['thainame', 'ชื่อ']);
        $colLastTh    = $resolve(['lastname', 'สกุล']);
        $colFirstEn   = $resolve(['englishname', 'name']);
        $colLastEn    = $resolve(['surename', 'surname', 'lastname']);
        $colPosition  = $resolve(['ตำแหน่ง', 'position']);
        $colDept      = $resolve(['แผนก', 'department']);
        $colShift     = $resolve(['shift']);
        $colEmpType   = $resolve(['employeetype']);
        $colStartDate = $resolve(['เข้างานวันที่', 'startdate']);
        $colRemark    = $resolve(['หมายเหตุ', 'remark']);

        if ($colCode === null) {
            throw new RuntimeException('ไม่พบคอลัมน์รหัสพนักงานในไฟล์ กรุณาตรวจสอบรูปแบบไฟล์');
        }

        $employees = [];
        $skipped = 0;
        $rowPosition = 0;

        foreach ($table->dataRows() as $row) {
            $code = trim($row[$colCode] ?? '');
            if ($code === '' || !preg_match('/^[A-Za-z0-9\-]{3,20}$/', $code)) {
                $skipped++;
                continue;
            }

            // ไฟล์ไม่มีข้อมูลซ้ำโดยปกติ แต่กันไว้: รหัสซ้ำใช้แถวแรกที่เจอ
            if (isset($employees[$code])) {
                continue;
            }

            $rowPosition++;
            $seqRaw = self::cell($row, $colSeq);
            $sortOrder = ($seqRaw !== null && preg_match('/^\d+$/', $seqRaw)) ? (int) $seqRaw : $rowPosition;

            $employees[$code] = [
                'employee_code'   => $code,
                'sort_order'      => $sortOrder,
                'prefix'          => self::cell($row, $colPrefix),
                'first_name_th'   => self::cell($row, $colFirstTh),
                'last_name_th'    => self::cell($row, $colLastTh),
                'first_name_en'   => self::cell($row, $colFirstEn),
                'last_name_en'    => self::cell($row, $colLastEn),
                'position_name'   => self::cell($row, $colPosition),
                'department'      => self::cell($row, $colDept),
                'shift_code'      => self::cell($row, $colShift),
                'employee_type'   => self::cell($row, $colEmpType),
                'start_date_text' => self::cell($row, $colStartDate),
                'remark'          => mb_substr(self::cell($row, $colRemark) ?? '', 0, 500),
            ];
        }

        if (count($employees) === 0) {
            return ['changed' => false, 'message' => 'ไม่พบแถวข้อมูลพนักงานที่ถูกต้องในไฟล์', 'employee_count' => 0, 'skipped_rows' => $skipped];
        }

        $hash = self::computeHash($employees);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT content_hash FROM manpower_import_batches
             WHERE project_code = :p AND list_type = :l
             ORDER BY imported_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['p' => $projectCode, 'l' => $listType]);
        $lastHash = $stmt->fetchColumn();

        if ($lastHash !== false && hash_equals((string) $lastHash, $hash)) {
            return [
                'changed' => false,
                'message' => 'ข้อมูลในไฟล์เหมือนกับครั้งล่าสุดที่อัปโหลด ระบบจะไม่อัปเดตฐานข้อมูล',
                'employee_count' => count($employees),
                'skipped_rows' => $skipped,
            ];
        }

        $pdo->beginTransaction();
        try {
            $batchStmt = $pdo->prepare(
                'INSERT INTO manpower_import_batches (project_code, list_type, original_filename, content_hash, employee_count, imported_by)
                 VALUES (:p, :l, :f, :h, :c, :u)'
            );
            $batchStmt->execute([
                'p' => $projectCode,
                'l' => $listType,
                'f' => $originalFilename,
                'h' => $hash,
                'c' => count($employees),
                'u' => $userId,
            ]);
            $batchId = (int) $pdo->lastInsertId();

            // แทนที่รายชื่อเดิมทั้งหมดของโปรเจค+ประเภทนี้ (คนออกแล้วจะหายไปเพราะไม่อยู่ในไฟล์ใหม่)
            $pdo->prepare('DELETE FROM employees WHERE project_code = :p AND list_type = :l')
                ->execute(['p' => $projectCode, 'l' => $listType]);

            $insertStmt = $pdo->prepare(
                'INSERT INTO employees
                    (employee_code, project_code, list_type, sort_order, prefix, first_name_th, last_name_th,
                     first_name_en, last_name_en, position_name, department, shift_code,
                     employee_type, start_date_text, remark, batch_id)
                 VALUES
                    (:employee_code, :project_code, :list_type, :sort_order, :prefix, :first_name_th, :last_name_th,
                     :first_name_en, :last_name_en, :position_name, :department, :shift_code,
                     :employee_type, :start_date_text, :remark, :batch_id)
                 ON DUPLICATE KEY UPDATE
                    project_code = VALUES(project_code), list_type = VALUES(list_type),
                    sort_order = VALUES(sort_order),
                    prefix = VALUES(prefix), first_name_th = VALUES(first_name_th),
                    last_name_th = VALUES(last_name_th), first_name_en = VALUES(first_name_en),
                    last_name_en = VALUES(last_name_en), position_name = VALUES(position_name),
                    department = VALUES(department), shift_code = VALUES(shift_code),
                    employee_type = VALUES(employee_type), start_date_text = VALUES(start_date_text),
                    remark = VALUES(remark), batch_id = VALUES(batch_id)'
            );

            foreach ($employees as $emp) {
                $emp['project_code'] = $projectCode;
                $emp['list_type'] = $listType;
                $emp['batch_id'] = $batchId;
                $insertStmt->execute($emp);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [
            'changed' => true,
            'message' => 'อัปเดตรายชื่อพนักงานเรียบร้อย (แทนที่ข้อมูลเดิมทั้งหมดด้วยไฟล์ใหม่)',
            'employee_count' => count($employees),
            'skipped_rows' => $skipped,
        ];
    }

    private static function cell(array $row, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }
        $value = trim($row[$index] ?? '');
        return $value === '' ? null : $value;
    }

    private static function computeHash(array $employees): string
    {
        ksort($employees);
        $parts = [];
        foreach ($employees as $code => $emp) {
            $parts[] = implode('|', [
                $code, (string) $emp['sort_order'],
                $emp['prefix'], $emp['first_name_th'], $emp['last_name_th'],
                $emp['first_name_en'], $emp['last_name_en'],
                $emp['position_name'], $emp['department'], $emp['shift_code'],
                $emp['employee_type'], $emp['start_date_text'],
            ]);
        }
        return hash('sha256', implode("\n", $parts));
    }
}
