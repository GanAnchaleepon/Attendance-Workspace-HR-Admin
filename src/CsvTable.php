<?php
declare(strict_types=1);

/**
 * ตัวช่วยอ่านไฟล์ CSV ที่หัวตารางไม่ตรงกันทุกไฟล์ (Manpower / Manpower QA)
 * - หา "แถวหัวตาราง" อัตโนมัติ (ข้ามแถว title/สรุปยอดด้านบน)
 * - รองรับหัวตาราง 2 แถวซ้อนกัน (merged header) โดยรวมข้อความของ 2 แถวเข้าด้วยกัน
 * - จับคู่ชื่อคอลัมน์ด้วยรายการคำใกล้เคียง (alias) แบบไม่สนตัวพิมพ์เล็ก-ใหญ่/ช่องว่าง
 */
final class CsvTable
{
    /** @var array<int, array<int, string>> */
    private array $rows;
    private int $headerRowIndex;
    /** @var array<int, string> รวมข้อความหัวตาราง (อาจมาจาก 1-2 แถว) ต่อคอลัมน์ */
    private array $normalizedHeaders;

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function __construct(array $rows, int $headerRowIndex, array $normalizedHeaders)
    {
        $this->rows = $rows;
        $this->headerRowIndex = $headerRowIndex;
        $this->normalizedHeaders = $normalizedHeaders;
    }

    public static function fromFile(string $path, array $headerHints): self
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('ไม่สามารถอ่านไฟล์ที่อัปโหลดได้');
        }

        // ตัด BOM (UTF-8) ออกถ้ามี เพราะ Excel มักแทรกมาให้
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = preg_split("/\r\n|\r|\n/", $content);
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $parsed = str_getcsv($line);
            $rows[] = array_map(static fn ($v) => trim((string) $v), $parsed);
        }

        return self::fromRows($rows, $headerHints);
    }

    /**
     * สร้างตารางจากแถวข้อมูลที่แปลงมาแล้ว (เช่นจาก XlsxReader) แทนการอ่านไฟล์ CSV โดยตรง
     *
     * @param array<int, array<int, string>> $rows
     */
    public static function fromRows(array $rows, array $headerHints): self
    {
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            return count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) > 0;
        }));

        if (count($rows) === 0) {
            throw new RuntimeException('ไฟล์ว่างเปล่าหรืออ่านไม่ได้');
        }

        [$headerRowIndex, $normalizedHeaders] = self::detectHeader($rows, $headerHints);

        return new self($rows, $headerRowIndex, $normalizedHeaders);
    }

    /**
     * หาแถวหัวตาราง: สแกน 15 แถวแรก หาแถวที่มีคำใน $headerHints อย่างน้อย 1 คำ
     * แล้วดูว่าแถวถัดไปเป็น "หัวตารางย่อย" หรือไม่ (มีข้อความแต่ไม่ตรง data pattern)
     * โดยรวมข้อความแถวถัดไปเข้ากับแถวหัวหลักแบบ per-column
     *
     * @return array{0:int, 1: array<int,string>}
     */
    private static function detectHeader(array $rows, array $headerHints): array
    {
        $maxScan = min(count($rows), 15);
        for ($i = 0; $i < $maxScan; $i++) {
            $normalized = array_map([self::class, 'normalize'], $rows[$i]);
            foreach ($headerHints as $hint) {
                $hintNorm = self::normalize($hint);
                foreach ($normalized as $cell) {
                    if ($cell !== '' && str_contains($cell, $hintNorm)) {
                        // พบแถวหัวตารางแล้ว มองหาแถวหัวตารางย่อยถัดไป (ข้ามแถวว่างที่อาจคั่นอยู่ก่อน)
                        $merged = $normalized;
                        $subHeaderRow = null;
                        $subHeaderOffset = $i;
                        for ($k = $i + 1; $k < min($i + 4, count($rows)); $k++) {
                            $candidate = $rows[$k];
                            $nonEmpty = array_filter($candidate, static fn ($v) => trim((string) $v) !== '');
                            if (count($nonEmpty) === 0) {
                                continue; // ข้ามแถวว่าง
                            }
                            $subHeaderRow = $candidate;
                            $subHeaderOffset = $k;
                            break;
                        }

                        if ($subHeaderRow !== null && self::looksLikeSubHeader($subHeaderRow)) {
                            foreach ($subHeaderRow as $idx => $cellVal) {
                                $sub = self::normalize($cellVal);
                                if ($sub !== '') {
                                    $merged[$idx] = trim(($merged[$idx] ?? '') . ' ' . $sub);
                                }
                            }
                            return [$subHeaderOffset, $merged]; // ข้อมูลจริงเริ่มหลังแถว sub-header
                        }

                        return [$i, $merged];
                    }
                }
            }
        }

        throw new RuntimeException('ไม่พบแถวหัวตารางที่รู้จักในไฟล์ กรุณาตรวจสอบรูปแบบไฟล์');
    }

    /** แถวรอง ๆ ถือเป็น sub-header ถ้าไม่มีคอลัมน์ไหนดูเหมือนรหัสพนักงาน (ตัวอักษร+ตัวเลขติดกัน) และมีข้อความบางส่วน */
    private static function looksLikeSubHeader(array $row): bool
    {
        $nonEmpty = array_filter($row, static fn ($v) => trim((string) $v) !== '');
        if (count($nonEmpty) === 0) {
            return false;
        }
        foreach ($nonEmpty as $cell) {
            if (preg_match('/^[A-Za-z]{2,4}\d{3,}/', trim((string) $cell))) {
                return false; // ดูเหมือนรหัสพนักงานแล้ว แสดงว่านี่คือแถวข้อมูลจริง ไม่ใช่ sub-header
            }
        }
        return true;
    }

    public static function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        return preg_replace('/\s+/u', '', $text) ?? $text;
    }

    /**
     * หา index ของคอลัมน์จากรายการ alias (เรียงจากเจาะจงที่สุดไปกว้างที่สุด)
     * $excludeIndices ใช้กันไม่ให้จับคอลัมน์ที่ถูกใช้ไปกับ field อื่นแล้วซ้ำกัน
     * (เช่น หัวตารางย่อยของบางไฟล์ที่คอลัมน์ "นามสกุลไทย" กับ "นามสกุลอังกฤษ" ปรากฏเป็นคำว่า "Lastname" เหมือนกันทั้งคู่)
     *
     * @param int[] $excludeIndices
     */
    public function findColumn(array $aliases, array $excludeIndices = []): ?int
    {
        foreach ($aliases as $alias) {
            $aliasNorm = self::normalize($alias);
            foreach ($this->normalizedHeaders as $idx => $header) {
                if (in_array($idx, $excludeIndices, true)) {
                    continue;
                }
                if ($header !== '' && str_contains($header, $aliasNorm)) {
                    return $idx;
                }
            }
        }
        return null;
    }

    /**
     * คืนแถวข้อมูลทั้งหมด (หลังแถวหัวตาราง) เป็น array ของแถว (index ตามตำแหน่งคอลัมน์เดิม)
     * @return array<int, array<int, string>>
     */
    public function dataRows(): array
    {
        return array_slice($this->rows, $this->headerRowIndex + 1);
    }
}
