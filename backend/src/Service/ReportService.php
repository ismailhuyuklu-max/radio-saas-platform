<?php

declare(strict_types=1);

namespace RadioSaaS\Service;

use ZipArchive;

/**
 * Dependency-free report generation in CSV, XLSX and PDF.
 *
 * - CSV: RFC 4180 with a UTF-8 BOM so Excel opens Turkish text correctly.
 * - XLSX: minimal OOXML package built with ZipArchive (inline strings).
 * - PDF: a compact, self-contained PDF writer (Helvetica, paginated rows).
 *
 * Row building and CSV formatting are pure and unit-tested; XLSX/PDF emit valid
 * binary containers (verified by their magic bytes in tests).
 */
final class ReportService
{
    // --- CSV ------------------------------------------------------------------

    /**
     * @param list<string> $headers
     * @param list<list<scalar|null>> $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $out = self::csvLine($headers);
        foreach ($rows as $row) {
            $out .= self::csvLine($row);
        }
        return "\xEF\xBB\xBF" . $out; // UTF-8 BOM
    }

    private static function csvLine(array $cells): string
    {
        return implode(',', array_map([self::class, 'csvCell'], $cells)) . "\r\n";
    }

    private static function csvCell(mixed $value): string
    {
        $s = (string) ($value ?? '');
        if (preg_match('/[",\r\n]/', $s)) {
            $s = '"' . str_replace('"', '""', $s) . '"';
        }
        return $s;
    }

    // --- XLSX -----------------------------------------------------------------

    /**
     * @param list<string> $headers
     * @param list<list<scalar|null>> $rows
     */
    public static function toXlsx(array $headers, array $rows, string $sheetName = 'Rapor'): string
    {
        $sheet = self::sheetXml($headers, $rows);

        $files = [
            '[Content_Types].xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '</Types>',
            '_rels/.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . self::xmlEscape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '</Relationships>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private static function sheetXml(array $headers, array $rows): string
    {
        $allRows = array_merge([$headers], $rows);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($allRows as $r => $cells) {
            $rowNum = $r + 1;
            $xml .= '<row r="' . $rowNum . '">';
            $c = 0;
            foreach ($cells as $cell) {
                $ref = self::colLetter($c) . $rowNum;
                if (is_int($cell) || is_float($cell)) {
                    $xml .= '<c r="' . $ref . '"><v>' . $cell . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                        . self::xmlEscape((string) ($cell ?? '')) . '</t></is></c>';
                }
                $c++;
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    public static function colLetter(int $index): string
    {
        $letter = '';
        $index += 1;
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $index = intdiv($index - 1, 26);
        }
        return $letter;
    }

    private static function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // --- PDF ------------------------------------------------------------------

    /**
     * @param list<string> $headers
     * @param list<list<scalar|null>> $rows
     */
    public static function toPdf(string $title, array $headers, array $rows): string
    {
        $rowsPerPage = 34;
        $lineHeight = 16;
        $startY = 770;
        $marginX = 40;

        // Build text lines: title + header + data, paginated.
        $allRows = array_merge([$headers], $rows);
        $pagesLines = array_chunk($allRows, $rowsPerPage);
        if ($pagesLines === []) {
            $pagesLines = [[]];
        }

        $objects = [];
        $pageObjIds = [];

        // Reserve: 1=catalog, 2=pages, 3=font. Pages start at 4.
        $nextId = 4;
        foreach ($pagesLines as $pageIndex => $lines) {
            $content = "BT /F1 9 Tf\n";
            $y = $startY;
            if ($pageIndex === 0) {
                $content .= "/F1 15 Tf 1 0 0 1 {$marginX} 805 Tm (" . self::pdfText($title) . ") Tj\n/F1 9 Tf\n";
            }
            foreach ($lines as $cells) {
                $text = self::pdfRowText($cells);
                $content .= "1 0 0 1 {$marginX} {$y} Tm (" . self::pdfText($text) . ") Tj\n";
                $y -= $lineHeight;
            }
            $content .= "ET";

            $contentId = $nextId++;
            $pageId = $nextId++;
            $pageObjIds[] = $pageId;

            $objects[$contentId] = "<</Length " . strlen($content) . ">>\nstream\n" . $content . "\nendstream";
            $objects[$pageId] = "<</Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] "
                . "/Resources <</Font <</F1 3 0 R>>>> /Contents {$contentId} 0 R>>";
        }

        $kids = implode(' ', array_map(static fn ($id) => "{$id} 0 R", $pageObjIds));
        $objects[1] = "<</Type /Catalog /Pages 2 0 R>>";
        $objects[2] = "<</Type /Pages /Kids [{$kids}] /Count " . count($pageObjIds) . ">>";
        $objects[3] = "<</Type /Font /Subtype /Type1 /BaseFont /Helvetica>>";

        ksort($objects);

        // Assemble with a correct xref table.
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $pdf .= "trailer\n<</Size {$count} /Root 1 0 R>>\nstartxref\n{$xrefPos}\n%%EOF";
        return $pdf;
    }

    private static function pdfRowText(array $cells): string
    {
        $padded = array_map(static function ($c): string {
            $s = (string) ($c ?? '');
            return mb_strimwidth($s, 0, 26, '…');
        }, $cells);
        return implode('  |  ', $padded);
    }

    /** Escape for PDF literal strings + transliterate Turkish to ASCII (Helvetica WinAnsi). */
    private static function pdfText(string $s): string
    {
        $map = [
            'ş' => 's', 'Ş' => 'S', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I',
            'ç' => 'c', 'Ç' => 'C', 'ö' => 'o', 'Ö' => 'O', 'ü' => 'u', 'Ü' => 'U',
            '₺' => 'TL', '–' => '-', '—' => '-', '…' => '...',
        ];
        $s = strtr($s, $map);
        $s = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        // Drop any remaining non-ASCII to keep the stream WinAnsi-safe.
        return preg_replace('/[^\x20-\x7E]/', '', $s) ?? '';
    }
}
