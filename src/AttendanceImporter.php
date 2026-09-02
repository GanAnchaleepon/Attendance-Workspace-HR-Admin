<?php
declare(strict_types=1);

/**
 * นำเข้าไฟล์สแกนลายนิ้วมือ (เช่น "สม+พี่พูน.csv")
 * รูปแบบคอลัมน์: แผนก, ชื่อ, รหัสที่เครื่อง(ไม่ใช้), วัน/เวลา, หมายเลขเครื่อง(ไม่ใช้), รหัสพนักงาน, บันทึกโดย(ไม่ใช้), CardNo(ไม่ใช้)
 * ไฟล์เดียวมีข้อมูลหลายโปรเจครวมกัน จึงต้องระบุโปรเจคที่จะนำเข้า แล้วกรองเฉพาะแถวของโปรเจคนั้น
 */
final class AttendanceImporter
{
    private const HEADER_HINTS = ['แผนก', 'รหัสพนักงาน'];

    /**
     * @return array{
     *   inserted:int, duplicate:int, other_project:int, invalid_rows:int,
    *   auto_resolved:int,
    *   invalid_samples: array<int, array<string, string>>,
     *   employee_codes: string[]
     * }
     */
    public static function import(string $filePath, string $originalFilename, string $projectCode): array
    {
        $table = CsvTable::fromFile($filePath, self::HEADER_HINTS);

        $colDept = $table->findColumn(['แผนก', 'department']);
        $colName = $table->findColumn(['ชื่อ']);
        $colMachineCode = $table->findColumn(['รหัสที่เครื่อง', 'machinecode']);
        $colDateTime = $table->findColumn(['วัน/เวลา', 'วันเวลา', 'datetime']);
        $colEmpCode = $table->findColumn(['รหัสพนักงาน', 'empcode']);

        if ($colDept === null || $colDateTime === null || $colEmpCode === null) {
            throw new RuntimeException('รูปแบบไฟล์สแกนนิ้วไม่ตรงตามที่คาดไว้ (ต้องมีคอลัมน์ แผนก, วัน/เวลา, รหัสพนักงาน)');
        }

        $pdo = Database::pdo();
        $insertStmt = $pdo->prepare(
            'INSERT IGNORE INTO attendance_scans (project_code, employee_code, scan_name, scan_time, source_file)
             VALUES (:project_code, :employee_code, :scan_name, :scan_time, :source_file)'
        );

        $inserted = 0;
        $duplicate = 0;
        $otherProject = 0;
        $invalidRows = 0;
        $autoResolved = 0;
        $invalidSamples = [];
        $touchedEmployeeCodes = [];
        $employeeCodeMap = self::loadProjectEmployeeCodeMap($projectCode);

        foreach ($table->dataRows() as $row) {
            $dept = trim($row[$colDept] ?? '');
            if ($dept === '') {
                $invalidRows++;
                self::collectInvalidSample($invalidSamples, [
                    'reason' => 'empty_department',
                    'department' => $dept,
                    'machine_code' => $colMachineCode !== null ? trim($row[$colMachineCode] ?? '') : '',
                    'name' => $colName !== null ? trim($row[$colName] ?? '') : '',
                    'datetime' => trim($row[$colDateTime] ?? ''),
                    'employee_code_raw' => trim($row[$colEmpCode] ?? ''),
                ]);
                continue;
            }
            if (strcasecmp($dept, $projectCode) !== 0) {
                $otherProject++;
                continue;
            }

            $empCodeRaw = trim($row[$colEmpCode] ?? '');
            $empCode = self::resolveEmployeeCode(
                $empCodeRaw,
                $row,
                $colMachineCode,
                $colName,
                $employeeCodeMap
            );
            $rawDateTime = trim($row[$colDateTime] ?? '');
            if ($rawDateTime === '') {
                $invalidRows++;
                self::collectInvalidSample($invalidSamples, [
                    'reason' => 'missing_datetime',
                    'department' => $dept,
                    'machine_code' => $colMachineCode !== null ? trim($row[$colMachineCode] ?? '') : '',
                    'name' => $colName !== null ? trim($row[$colName] ?? '') : '',
                    'datetime' => $rawDateTime,
                    'employee_code_raw' => $empCodeRaw,
                ]);
                continue;
            }
            if ($empCode === '') {
                $invalidRows++;
                self::collectInvalidSample($invalidSamples, [
                    'reason' => 'missing_employee_code_after_fallback',
                    'department' => $dept,
                    'machine_code' => $colMachineCode !== null ? trim($row[$colMachineCode] ?? '') : '',
                    'name' => $colName !== null ? trim($row[$colName] ?? '') : '',
                    'datetime' => $rawDateTime,
                    'employee_code_raw' => $empCodeRaw,
                ]);
                continue;
            }
            if ($empCodeRaw === '' && $empCode !== '') {
                $autoResolved++;
            }

            $scanTime = self::parseDateTime($rawDateTime);
            if ($scanTime === null) {
                $invalidRows++;
                self::collectInvalidSample($invalidSamples, [
                    'reason' => 'invalid_datetime_format',
                    'department' => $dept,
                    'machine_code' => $colMachineCode !== null ? trim($row[$colMachineCode] ?? '') : '',
                    'name' => $colName !== null ? trim($row[$colName] ?? '') : '',
                    'datetime' => $rawDateTime,
                    'employee_code_raw' => $empCodeRaw,
                ]);
                continue;
            }

            $name = $colName !== null ? trim($row[$colName] ?? '') : null;

            $insertStmt->execute([
                'project_code' => $projectCode,
                'employee_code' => $empCode,
                'scan_name' => $name !== '' ? $name : null,
                'scan_time' => $scanTime->format('Y-m-d H:i:s'),
                'source_file' => $originalFilename,
            ]);

            if ($insertStmt->rowCount() > 0) {
                $inserted++;
            } else {
                $duplicate++;
            }

            $touchedEmployeeCodes[$empCode] = true;
        }

        return [
            'inserted' => $inserted,
            'duplicate' => $duplicate,
            'other_project' => $otherProject,
            'invalid_rows' => $invalidRows,
            'auto_resolved' => $autoResolved,
            'invalid_samples' => $invalidSamples,
            'employee_codes' => array_keys($touchedEmployeeCodes),
        ];
    }

    /**
     * @param array<int, array<string, string>> $samples
     * @param array<string, string> $sample
     */
    private static function collectInvalidSample(array &$samples, array $sample): void
    {
        if (count($samples) >= 25) {
            return;
        }
        $samples[] = $sample;
    }

    /**
     * @return array{exact: array<string, string>, suffix: array<string, string>}
     */
    private static function loadProjectEmployeeCodeMap(string $projectCode): array
    {
        $stmt = Database::pdo()->prepare('SELECT employee_code FROM employees WHERE project_code = :project_code');
        $stmt->execute(['project_code' => $projectCode]);

        $exact = [];
        $suffixBuckets = [];

        while (($code = $stmt->fetchColumn()) !== false) {
            $resolvedCode = strtoupper(trim((string) $code));
            if ($resolvedCode === '') {
                continue;
            }

            $exact[$resolvedCode] = $resolvedCode;

            $suffix = self::numericSuffix($resolvedCode);
            if ($suffix === null) {
                continue;
            }
            $suffixBuckets[$suffix][$resolvedCode] = true;
        }

        $suffix = [];
        foreach ($suffixBuckets as $digits => $codes) {
            if (count($codes) === 1) {
                $suffix[$digits] = (string) array_key_first($codes);
            }
        }

        return ['exact' => $exact, 'suffix' => $suffix];
    }

    /**
     * @param array<int,string> $row
     * @param array{exact: array<string, string>, suffix: array<string, string>} $employeeCodeMap
     */
    private static function resolveEmployeeCode(
        string $empCodeRaw,
        array $row,
        ?int $colMachineCode,
        ?int $colName,
        array $employeeCodeMap
    ): string {
        $normalized = self::normalizeCode($empCodeRaw);
        if ($normalized !== '') {
            return $normalized;
        }

        $candidates = [];
        if ($colMachineCode !== null) {
            $candidates[] = trim($row[$colMachineCode] ?? '');
        }
        if ($colName !== null) {
            $candidates[] = trim($row[$colName] ?? '');
        }

        foreach ($candidates as $candidateRaw) {
            $candidate = self::normalizeCode($candidateRaw);
            if ($candidate === '') {
                continue;
            }

            if (isset($employeeCodeMap['exact'][$candidate])) {
                return $employeeCodeMap['exact'][$candidate];
            }

            $suffix = self::numericSuffix($candidate);
            if ($suffix !== null && isset($employeeCodeMap['suffix'][$suffix])) {
                return $employeeCodeMap['suffix'][$suffix];
            }
        }

        return '';
    }

    private static function normalizeCode(string $raw): string
    {
        return strtoupper(trim($raw));
    }

    private static function numericSuffix(string $value): ?string
    {
        if (!preg_match('/(\d{4,})$/', $value, $m)) {
            return null;
        }
        return $m[1];
    }

    private static function parseDateTime(string $raw): ?DateTimeImmutable
    {
        // แยกด้วย regex เอง (ไม่พึ่ง createFromFormat) เพื่อรองรับทั้งกรณีมี/ไม่มีเลข 0 นำหน้า
        // เช่น "1/7/2026 7:24:00" หรือ "01/07/2026 07:24" ก็ต้องอ่านได้ถูกต้องเหมือนกัน
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})\s+(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($raw), $m)) {
            return null;
        }

        [, $day, $month, $year, $hour, $minute] = $m;
        $second = $m[6] ?? '0';

        $day = (int) $day;
        $month = (int) $month;
        $year = (int) $year;
        $hour = (int) $hour;
        $minute = (int) $minute;
        $second = (int) $second;

        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        return (new DateTimeImmutable())->setDate($year, $month, $day)->setTime($hour, $minute, $second);
    }
}
