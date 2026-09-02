<?php
declare(strict_types=1);

/**
 * อ่านไฟล์ Excel .xlsx (OOXML) แบบขั้นต่ำ โดยไม่พึ่ง library ภายนอก (ใช้ ext-zip + ext-xml ที่มากับ PHP)
 * แปลงชีตที่เลือกของไฟล์ให้เป็น array แถว/คอลัมน์แบบเดียวกับที่ CsvTable ใช้งาน รองรับไฟล์หลายชีต
 * หมายเหตุ: รองรับเฉพาะ .xlsx เท่านั้น ไม่รองรับไฟล์ .xls แบบเก่า (รูปแบบไบนารี BIFF)
 */
final class XlsxReader
{
    /**
     * คืนชื่อชีตทั้งหมดในไฟล์ เรียงตามลำดับในสมุดงาน (index ตรงกับ $sheetIndex ของ readRows)
     *
     * @return array<int, string>
     */
    public static function listSheetNames(string $filePath): array
    {
        return array_column(self::listSheets($filePath), 'name');
    }

    /**
     * คืนชื่อ + สถานะซ่อนของชีตทั้งหมด เรียงตามลำดับในสมุดงาน (index ตรงกับ $sheetIndex ของ readRows)
     * ไฟล์ Excel จริงมักมีชีตที่ถูกซ่อนไว้ (state="hidden"/"veryHidden") ปะปนกับชีตที่ใช้งานจริง
     *
     * @return array<int, array{name: string, hidden: bool}>
     */
    public static function listSheets(string $filePath): array
    {
        $zip = self::openZip($filePath);
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $zip->close();

        if ($workbookXml === false) {
            return [];
        }

        $workbook = @simplexml_load_string($workbookXml);
        if ($workbook === false || !isset($workbook->sheets->sheet)) {
            return [];
        }

        $sheets = [];
        foreach ($workbook->sheets->sheet as $sheet) {
            $state = (string) $sheet['state'];
            $sheets[] = [
                'name' => (string) $sheet['name'],
                'hidden' => $state === 'hidden' || $state === 'veryHidden',
            ];
        }
        return $sheets;
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function readRows(string $filePath, int $sheetIndex = 0): array
    {
        $zip = self::openZip($filePath);

        $sharedStrings = self::readSharedStrings($zip);
        $sheetPath = self::resolveSheetPath($zip, $sheetIndex);
        $sheetXml = $zip->getFromName($sheetPath);
        $zip->close();

        if ($sheetXml === false) {
            throw new RuntimeException('ไม่พบข้อมูลชีตในไฟล์ .xlsx');
        }

        $xml = @simplexml_load_string($sheetXml);
        if ($xml === false || !isset($xml->sheetData)) {
            throw new RuntimeException('ไม่สามารถอ่านโครงสร้างไฟล์ .xlsx ได้');
        }

        $rows = [];
        foreach ($xml->sheetData->row as $rowXml) {
            $rowIndex = isset($rowXml['r']) ? ((int) $rowXml['r'] - 1) : count($rows);
            $cells = [];
            foreach ($rowXml->c as $cellXml) {
                $colIndex = self::columnIndexFromRef((string) $cellXml['r']);
                $cells[$colIndex] = self::cellValue($cellXml, $sharedStrings);
            }
            if (empty($cells)) {
                continue;
            }
            $maxCol = max(array_keys($cells));
            $line = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $line[] = $cells[$c] ?? '';
            }
            $rows[$rowIndex] = $line;
        }

        ksort($rows);
        return array_values($rows);
    }

    private static function openZip(string $filePath): ZipArchive
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('เซิร์ฟเวอร์นี้ยังไม่รองรับการอ่านไฟล์ .xlsx (ต้องเปิดใช้งาน PHP extension: zip)');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('ไม่สามารถเปิดไฟล์ .xlsx ได้ (ไฟล์อาจเสียหายหรือไม่ใช่ไฟล์ Excel ที่ถูกต้อง)');
        }
        return $zip;
    }

    /**
     * @return array<int, string>
     */
    private static function readSharedStrings(ZipArchive $zip): array
    {
        $xmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($xmlContent === false) {
            return [];
        }

        $xml = @simplexml_load_string($xmlContent);
        if ($xml === false) {
            return [];
        }

        $strings = [];
        foreach ($xml->si as $si) {
            $strings[] = self::extractText($si);
        }
        return $strings;
    }

    private static function extractText(SimpleXMLElement $node): string
    {
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $text = '';
        foreach ($node->r as $run) {
            $text .= (string) $run->t;
        }
        return $text;
    }

    private static function cellValue(SimpleXMLElement $cellXml, array $sharedStrings): string
    {
        $type = (string) $cellXml['t'];

        if ($type === 'inlineStr' && isset($cellXml->is)) {
            return self::extractText($cellXml->is);
        }

        if (!isset($cellXml->v)) {
            return '';
        }

        $raw = (string) $cellXml->v;

        if ($type === 's') {
            return $sharedStrings[(int) $raw] ?? '';
        }

        if ($type === 'str') {
            return $raw;
        }

        // ค่าตัวเลข: ถ้ามีเศษทศนิยม มักเป็นวันที่/เวลาแบบ Excel serial number เช่น "45905.30833"
        // แปลงเป็นสตริงวันที่/เวลาให้ AttendanceImporter::parseDateTime อ่านต่อได้
        if ($type === '' && str_contains($raw, '.') && is_numeric($raw)) {
            return self::excelSerialToDateTimeString((float) $raw);
        }

        return $raw;
    }

    private static function excelSerialToDateTimeString(float $serial): string
    {
        // Excel epoch (Windows): 1899-12-30 เพื่อชดเชยบั๊กปีอธิกสุรทิน 1900 ในตัว Excel เอง
        $epoch = new DateTimeImmutable('1899-12-30');
        $seconds = (int) round($serial * 86400);
        $dt = $epoch->modify("+{$seconds} seconds");
        return $dt->format('d/m/Y H:i:s');
    }

    private static function columnIndexFromRef(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', $ref, $m)) {
            return 0;
        }
        $letters = $m[1];
        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }

    private static function resolveSheetPath(ZipArchive $zip, int $sheetIndex): string
    {
        $fallback = 'xl/worksheets/sheet' . ($sheetIndex + 1) . '.xml';

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            return $fallback;
        }

        $workbook = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if ($workbook === false || $rels === false || !isset($workbook->sheets->sheet[$sheetIndex])) {
            return $fallback;
        }

        $sheet = $workbook->sheets->sheet[$sheetIndex];
        $rId = (string) $sheet->attributes('r', true)->id;

        foreach ($rels->Relationship as $relationship) {
            if ((string) $relationship['Id'] === $rId) {
                $target = ltrim((string) $relationship['Target'], '/');
                return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }

        return $fallback;
    }
}
