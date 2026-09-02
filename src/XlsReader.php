<?php
declare(strict_types=1);

/**
 * อ่านไฟล์ Excel .xls แบบเก่า (BIFF8 / OLE2 Compound File) โดยเขียนเองทั้งหมด ไม่พึ่ง library ภายนอก
 * รองรับเฉพาะเซลล์ข้อมูลพื้นฐาน (ข้อความ/ตัวเลข/วันที่) รองรับหลายชีต
 * ไม่รองรับ: สูตรคำนวณ (จะได้ค่าว่าง), ไฟล์ BIFF5 รุ่นเก่ามาก, ไฟล์ที่เข้ารหัส/ป้องกันด้วยรหัสผ่าน
 */
final class XlsReader
{
    private const SECTOR_FREE = 0xFFFFFFFF;
    private const SECTOR_ENDOFCHAIN = 0xFFFFFFFE;
    private const SECTOR_FAT = 0xFFFFFFFD;
    private const SECTOR_DIF = 0xFFFFFFFC;

    /**
     * คืนชื่อชีตทั้งหมดในไฟล์ เรียงตามลำดับในสมุดงาน (index ตรงกับ $sheetIndex ของ readRows)
     *
     * @return array<int, string>
     */
    public static function listSheetNames(string $filePath): array
    {
        $workbookStream = self::loadWorkbookStream($filePath);
        return array_column(self::readBoundSheets($workbookStream), 'name');
    }

    /**
     * @return array<int, array<int, string>>
     */
    public static function readRows(string $filePath, int $sheetIndex = 0): array
    {
        $workbookStream = self::loadWorkbookStream($filePath);
        $boundSheets = self::readBoundSheets($workbookStream);
        $sheetOffset = $boundSheets[$sheetIndex]['offset'] ?? null;
        if ($sheetOffset === null) {
            throw new RuntimeException('ไม่พบชีตลำดับที่ ' . ($sheetIndex + 1) . ' ในไฟล์ .xls');
        }

        $sst = self::readGlobalsSst($workbookStream);
        return self::parseSheetRecords($workbookStream, $sheetOffset, $sst);
    }

    private static function loadWorkbookStream(string $filePath): string
    {
        $data = file_get_contents($filePath);
        if ($data === false || strlen($data) < 512) {
            throw new RuntimeException('ไม่สามารถอ่านไฟล์ .xls ได้ (ไฟล์อาจเสียหาย)');
        }

        if (substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            throw new RuntimeException('ไฟล์นี้ไม่ใช่ไฟล์ .xls แบบ OLE2 ที่รองรับ (อาจเป็นไฟล์ .xlsx ที่เปลี่ยนนามสกุล หรือไฟล์เสียหาย)');
        }

        $cfb = self::parseCompoundFile($data);
        $workbookStream = $cfb['streams']['workbook'] ?? $cfb['streams']['book'] ?? null;
        if ($workbookStream === null) {
            throw new RuntimeException('ไม่พบข้อมูล Workbook ภายในไฟล์ .xls');
        }

        return $workbookStream;
    }

    /**
     * @return array{streams: array<string, string>}
     */
    private static function parseCompoundFile(string $data): array
    {
        $sectorShift = self::readUInt16($data, 30);
        $miniSectorShift = self::readUInt16($data, 32);
        $numFatSectors = self::readUInt32($data, 44);
        $firstDirSector = self::readUInt32($data, 48);
        $miniStreamCutoff = self::readUInt32($data, 56);
        $firstMiniFatSector = self::readUInt32($data, 60);
        $numMiniFatSectors = self::readUInt32($data, 64);
        $firstDifatSector = self::readUInt32($data, 68);
        $numDifatSectors = self::readUInt32($data, 72);

        $sectorSize = 1 << $sectorShift;
        $miniSectorSize = 1 << $miniSectorShift;

        // อ่านตำแหน่ง FAT sector ทั้งหมดจาก DIFAT (109 ค่าแรกอยู่ใน header เอง ที่เหลือต่อจาก DIFAT sector ถ้ามี)
        $fatSectorLocations = [];
        for ($i = 0; $i < 109; $i++) {
            $loc = self::readUInt32($data, 76 + $i * 4);
            if ($loc === self::SECTOR_FREE) {
                continue;
            }
            $fatSectorLocations[] = $loc;
        }
        $difatSector = $firstDifatSector;
        for ($i = 0; $i < $numDifatSectors && $difatSector !== self::SECTOR_ENDOFCHAIN; $i++) {
            $sector = self::readSectorRaw($data, $difatSector, $sectorSize);
            for ($j = 0; $j < ($sectorSize / 4) - 1; $j++) {
                $loc = self::readUInt32($sector, $j * 4);
                if ($loc !== self::SECTOR_FREE) {
                    $fatSectorLocations[] = $loc;
                }
            }
            $difatSector = self::readUInt32($sector, $sectorSize - 4);
        }

        // ประกอบ FAT ทั้งหมดเป็นอาเรย์เดียว (แต่ละช่องคือ sector ถัดไปในเชน)
        $fat = [];
        foreach (array_slice($fatSectorLocations, 0, $numFatSectors) as $loc) {
            $sector = self::readSectorRaw($data, $loc, $sectorSize);
            $count = intdiv($sectorSize, 4);
            for ($j = 0; $j < $count; $j++) {
                $fat[] = self::readUInt32($sector, $j * 4);
            }
        }

        $directoryStream = self::readChain($data, $firstDirSector, $fat, $sectorSize);

        // Directory entries: 128 ไบต์ต่อรายการ
        $entries = [];
        $entryCount = intdiv(strlen($directoryStream), 128);
        for ($i = 0; $i < $entryCount; $i++) {
            $entry = substr($directoryStream, $i * 128, 128);
            $nameLen = self::readUInt16($entry, 64);
            $name = $nameLen > 2 ? mb_convert_encoding(substr($entry, 0, $nameLen - 2), 'UTF-8', 'UTF-16LE') : '';
            $objectType = ord($entry[66]);
            $startSector = self::readUInt32($entry, 116);
            $sizeLow = self::readUInt32($entry, 120);
            $entries[] = [
                'name' => $name,
                'type' => $objectType,
                'start' => $startSector,
                'size' => $sizeLow,
            ];
        }

        // Root entry (แรกสุด) เก็บ sector เริ่มต้นของ mini stream (ใช้กับสตรีมขนาดเล็กกว่า cutoff)
        $rootEntry = $entries[0] ?? null;
        $miniStreamData = '';
        if ($rootEntry !== null && $rootEntry['start'] !== self::SECTOR_ENDOFCHAIN) {
            $miniStreamData = self::readChain($data, $rootEntry['start'], $fat, $sectorSize);
        }

        $miniFat = [];
        if ($numMiniFatSectors > 0) {
            $miniFatStream = self::readChain($data, $firstMiniFatSector, $fat, $sectorSize);
            $count = intdiv(strlen($miniFatStream), 4);
            for ($j = 0; $j < $count; $j++) {
                $miniFat[] = self::readUInt32($miniFatStream, $j * 4);
            }
        }

        $streams = [];
        foreach ($entries as $entry) {
            if ($entry['type'] !== 2) { // 2 = stream
                continue;
            }
            $key = strtolower(trim($entry['name']));
            if ($key !== 'workbook' && $key !== 'book') {
                continue;
            }
            if ($entry['size'] >= $miniStreamCutoff) {
                $streams[$key] = substr(
                    self::readChain($data, $entry['start'], $fat, $sectorSize),
                    0,
                    $entry['size']
                );
            } else {
                $streams[$key] = substr(
                    self::readMiniChain($miniStreamData, $entry['start'], $miniFat, $miniSectorSize),
                    0,
                    $entry['size']
                );
            }
        }

        return ['streams' => $streams];
    }

    private static function readSectorRaw(string $data, int $sectorIndex, int $sectorSize): string
    {
        $offset = 512 + $sectorIndex * $sectorSize;
        return substr($data, $offset, $sectorSize);
    }

    /** @param int[] $fat */
    private static function readChain(string $data, int $startSector, array $fat, int $sectorSize): string
    {
        $out = '';
        $sector = $startSector;
        $guard = 0;
        while ($sector !== self::SECTOR_ENDOFCHAIN && $sector !== self::SECTOR_FREE && isset($fat[$sector]) && $guard < 200000) {
            $out .= self::readSectorRaw($data, $sector, $sectorSize);
            $sector = $fat[$sector];
            $guard++;
        }
        return $out;
    }

    /** @param int[] $miniFat */
    private static function readMiniChain(string $miniStreamData, int $startSector, array $miniFat, int $miniSectorSize): string
    {
        $out = '';
        $sector = $startSector;
        $guard = 0;
        while ($sector !== self::SECTOR_ENDOFCHAIN && $sector !== self::SECTOR_FREE && isset($miniFat[$sector]) && $guard < 200000) {
            $out .= substr($miniStreamData, $sector * $miniSectorSize, $miniSectorSize);
            $sector = $miniFat[$sector];
            $guard++;
        }
        return $out;
    }

    /**
     * อ่าน globals substream (ตั้งแต่ต้นสตรีมจนกว่าเจอ EOF แรก) เก็บมาเฉพาะ SST
     *
     * @return array<int, string>
     */
    private static function readGlobalsSst(string $stream): array
    {
        $len = strlen($stream);
        $pos = 0;
        $sst = [];

        while ($pos + 4 <= $len) {
            $type = self::readUInt16($stream, $pos);
            $recLen = self::readUInt16($stream, $pos + 2);
            $recData = substr($stream, $pos + 4, $recLen);
            $nextPos = $pos + 4 + $recLen;

            if ($type === 0x00FC) { // SST
                $sst = self::readSst($stream, $nextPos, $recData);
            } elseif ($type === 0x000A) { // EOF ของ globals substream
                break;
            }

            $pos = $nextPos;
        }

        return $sst;
    }

    /**
     * อ่าน BOUNDSHEET record ใน globals substream เพื่อหารายชื่อ/ตำแหน่ง offset ของแต่ละชีต
     *
     * @return array<int, array{name: string, offset: int}>
     */
    private static function readBoundSheets(string $stream): array
    {
        $len = strlen($stream);
        $pos = 0;
        $sheets = [];

        while ($pos + 4 <= $len) {
            $type = self::readUInt16($stream, $pos);
            $recLen = self::readUInt16($stream, $pos + 2);
            $recData = substr($stream, $pos + 4, $recLen);
            $pos += 4 + $recLen;

            if ($type === 0x0085) { // BOUNDSHEET
                $offset = self::readUInt32($recData, 0);
                $charCount = ord($recData[6] ?? "\0");
                $flags = ord($recData[7] ?? "\0");
                $isWide = ($flags & 0x01) !== 0;
                $raw = substr($recData, 8, $isWide ? $charCount * 2 : $charCount);
                $name = $isWide
                    ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                    : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
                $sheets[] = ['name' => $name, 'offset' => $offset];
            } elseif ($type === 0x000A) { // EOF ของ globals substream
                break;
            }
        }

        return $sheets;
    }

    /**
     * แกะ BIFF record ตั้งแต่ตำแหน่ง BOF ของชีตที่ต้องการ จนถึง EOF ของชีตนั้น
     *
     * @param array<int, string> $sst
     * @return array<int, array<int, string>>
     */
    private static function parseSheetRecords(string $stream, int $sheetOffset, array $sst): array
    {
        $len = strlen($stream);
        $pos = $sheetOffset;
        $rows = [];

        while ($pos + 4 <= $len) {
            $type = self::readUInt16($stream, $pos);
            $recLen = self::readUInt16($stream, $pos + 2);
            $recData = substr($stream, $pos + 4, $recLen);
            $pos += 4 + $recLen;

            switch ($type) {
                case 0x000A: // EOF ของชีตนี้
                    break 2;

                case 0x0204: // LABEL (BIFF8 unicode string inline)
                    [$r, $c, $text] = self::readLabel($recData);
                    $rows[$r][$c] = $text;
                    break;

                case 0x00FD: // LABELSST
                    $r = self::readUInt16($recData, 0);
                    $c = self::readUInt16($recData, 2);
                    $sstIndex = self::readUInt32($recData, 6);
                    $rows[$r][$c] = $sst[$sstIndex] ?? '';
                    break;

                case 0x0203: // NUMBER
                    $r = self::readUInt16($recData, 0);
                    $c = self::readUInt16($recData, 2);
                    $rows[$r][$c] = self::formatNumericCell(self::readDouble($recData, 6));
                    break;

                case 0x027E: // RK
                    $r = self::readUInt16($recData, 0);
                    $c = self::readUInt16($recData, 2);
                    $rows[$r][$c] = self::formatNumericCell(self::decodeRk(substr($recData, 6, 4)));
                    break;

                case 0x00BD: // MULRK
                    $r = self::readUInt16($recData, 0);
                    $firstCol = self::readUInt16($recData, 2);
                    $blockLen = strlen($recData);
                    $col = $firstCol;
                    for ($off = 4; $off + 6 <= $blockLen; $off += 6) {
                        $rows[$r][$col] = self::formatNumericCell(self::decodeRk(substr($recData, $off + 2, 4)));
                        $col++;
                    }
                    break;

                default:
                    // ข้าม record ประเภทอื่น (ฟอร์แมต, สไตล์, สูตร ฯลฯ) ไม่ต้องแปลผล
                    break;
            }
        }

        ksort($rows);
        $result = [];
        foreach ($rows as $r => $cols) {
            ksort($cols);
            $maxCol = max(array_keys($cols));
            $line = [];
            for ($c = 0; $c <= $maxCol; $c++) {
                $line[] = $cols[$c] ?? '';
            }
            $result[] = $line;
        }
        return $result;
    }

    /**
     * @return array<int, string>
     */
    private static function readSst(string $stream, int $posAfterHeader, string $recData): array
    {
        $uniqueCount = self::readUInt32($recData, 4);

        // ถ้าข้อมูล SST ยาวเกิน record เดียว ตัวไฟล์จะต่อด้วย CONTINUE record (type 0x003C) ทันที
        // เก็บเป็น "chunk" แยกกันไว้ (ห้ามต่อ buffer ตรง ๆ) เพราะสตริงที่ถูกตัดคาบเกี่ยว CONTINUE
        // ต้องอ่าน flag byte ใหม่ (compressed/uncompressed) ที่ต้น chunk ถัดไปเสมอตามสเปก BIFF8
        $chunks = [substr($recData, 8)];
        $len = strlen($stream);
        $pos = $posAfterHeader;
        while ($pos + 4 <= $len) {
            $peekType = self::readUInt16($stream, $pos);
            if ($peekType !== 0x003C) {
                break;
            }
            $peekLen = self::readUInt16($stream, $pos + 2);
            $chunks[] = substr($stream, $pos + 4, $peekLen);
            $pos += 4 + $peekLen;
        }

        $cursor = self::newChunkCursor($chunks);
        $strings = [];
        for ($i = 0; $i < $uniqueCount && !self::chunkCursorEof($cursor); $i++) {
            $charCount = self::readUInt16(self::chunkCursorRead($cursor, 2), 0);
            $flags = ord(self::chunkCursorRead($cursor, 1));
            $isWide = ($flags & 0x01) !== 0;
            $hasRichText = ($flags & 0x08) !== 0;
            $hasPhonetic = ($flags & 0x04) !== 0;

            $richCount = 0;
            if ($hasRichText) {
                $richCount = self::readUInt16(self::chunkCursorRead($cursor, 2), 0);
            }
            $phoneticSize = 0;
            if ($hasPhonetic) {
                $phoneticSize = self::readUInt32(self::chunkCursorRead($cursor, 4), 0);
            }

            $strings[] = self::readSstCharacters($cursor, $charCount, $isWide);

            if ($richCount > 0) {
                self::chunkCursorRead($cursor, $richCount * 4);
            }
            if ($phoneticSize > 0) {
                self::chunkCursorRead($cursor, $phoneticSize);
            }
        }

        return $strings;
    }

    /**
     * อ่านตัวอักษรของสตริง SST หนึ่งตัว โดยรองรับกรณีที่ตัวอักษรถูกตัดคาบเกี่ยวหลาย CONTINUE chunk
     * เมื่อข้าม chunk ต้องอ่าน flag byte ใหม่เสมอ (การบีบอัดของส่วนที่เหลืออาจเปลี่ยนไปจากเดิม)
     *
     * @param array{chunks: array<int,string>, index:int, offset:int} $cursor
     */
    private static function readSstCharacters(array &$cursor, int $charCount, bool $wide): string
    {
        $text = '';
        $remaining = $charCount;

        while ($remaining > 0 && !self::chunkCursorEof($cursor)) {
            $chunk = $cursor['chunks'][$cursor['index']];
            $availBytes = strlen($chunk) - $cursor['offset'];
            $bytesPerChar = $wide ? 2 : 1;
            $availChars = intdiv($availBytes, $bytesPerChar);
            $takeChars = min($availChars, $remaining);
            $takeBytes = $takeChars * $bytesPerChar;

            $raw = self::chunkCursorRead($cursor, $takeBytes);
            $text .= $wide
                ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
                : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
            $remaining -= $takeChars;

            if ($remaining > 0 && !self::chunkCursorEof($cursor)) {
                // ข้าม chunk ใหม่: ต้องอ่าน flag byte ของส่วนที่เหลือใหม่เสมอตามสเปก BIFF8
                $flags = ord(self::chunkCursorRead($cursor, 1));
                $wide = ($flags & 0x01) !== 0;
            }
        }

        return $text;
    }

    /**
     * @param array<int, string> $chunks
     * @return array{chunks: array<int,string>, index:int, offset:int}
     */
    private static function newChunkCursor(array $chunks): array
    {
        return ['chunks' => array_values($chunks), 'index' => 0, 'offset' => 0];
    }

    /** @param array{chunks: array<int,string>, index:int, offset:int} $cursor */
    private static function chunkCursorEof(array $cursor): bool
    {
        return $cursor['index'] >= count($cursor['chunks']);
    }

    /**
     * อ่าน $n ไบต์จากลำดับ chunk ปัจจุบัน ข้าม chunk ถัดไปอัตโนมัติถ้าไบต์ในชิ้นปัจจุบันไม่พอ
     *
     * @param array{chunks: array<int,string>, index:int, offset:int} $cursor
     */
    private static function chunkCursorRead(array &$cursor, int $n): string
    {
        $result = '';
        while ($n > 0 && !self::chunkCursorEof($cursor)) {
            $chunk = $cursor['chunks'][$cursor['index']];
            $avail = strlen($chunk) - $cursor['offset'];
            $take = min($avail, $n);
            $result .= substr($chunk, $cursor['offset'], $take);
            $cursor['offset'] += $take;
            $n -= $take;
            if ($cursor['offset'] >= strlen($chunk)) {
                $cursor['index']++;
                $cursor['offset'] = 0;
            }
        }
        return $result;
    }


    /**
     * @return array{0:int,1:int,2:string}
     */
    private static function readLabel(string $recData): array
    {
        $r = self::readUInt16($recData, 0);
        $c = self::readUInt16($recData, 2);
        $charCount = self::readUInt16($recData, 6);
        $flags = ord($recData[8] ?? "\0");
        $isWide = ($flags & 0x01) !== 0;
        $raw = substr($recData, 9, $isWide ? $charCount * 2 : $charCount);
        $text = $isWide
            ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE')
            : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1');
        return [$r, $c, $text];
    }

    /** ค่า RK เข้ารหัสตัวเลขแบบย่อ (int หรือ double ที่คูณ 100) ของ BIFF */
    private static function decodeRk(string $bytes4): float
    {
        $value = self::readUInt32($bytes4, 0);
        $isInt = ($value & 0x02) !== 0;
        $is100 = ($value & 0x01) !== 0;
        if ($isInt) {
            $intVal = $value >> 2;
            if (($value & 0x80000000) !== 0) {
                $intVal -= (1 << 30); // sign-extend คร่าว ๆ สำหรับช่วงตัวเลขที่ใช้งานจริง
            }
            $num = (float) $intVal;
        } else {
            $bits = ($value & 0xFFFFFFFC) << 32;
            $packed = pack('J', $bits);
            $num = unpack('d', $packed)[1] ?? 0.0;
        }
        return $is100 ? $num / 100 : $num;
    }

    /** ตัวเลขที่ดูเหมือน Excel date serial (มีเศษทศนิยม) จะถูกแปลงเป็นวันที่/เวลาให้ AttendanceImporter อ่านต่อได้ */
    private static function formatNumericCell(float $value): string
    {
        if (fmod($value, 1.0) !== 0.0 && $value > 0) {
            $epoch = new DateTimeImmutable('1899-12-30');
            $seconds = (int) round($value * 86400);
            return $epoch->modify("+{$seconds} seconds")->format('d/m/Y H:i:s');
        }
        return (string) (int) $value === (string) $value ? (string) $value : rtrim(rtrim((string) $value, '0'), '.');
    }

    private static function readUInt16(string $data, int $offset): int
    {
        if ($offset + 2 > strlen($data)) {
            return 0;
        }
        return unpack('v', substr($data, $offset, 2))[1];
    }

    private static function readUInt32(string $data, int $offset): int
    {
        if ($offset + 4 > strlen($data)) {
            return 0;
        }
        return unpack('V', substr($data, $offset, 4))[1];
    }

    private static function readDouble(string $data, int $offset): float
    {
        if ($offset + 8 > strlen($data)) {
            return 0.0;
        }
        return unpack('d', substr($data, $offset, 8))[1];
    }
}
