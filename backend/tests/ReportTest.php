<?php

declare(strict_types=1);

/** ReportService tests. Run: php backend/tests/ReportTest.php */

require __DIR__ . '/../src/Service/ReportService.php';

use RadioSaaS\Service\ReportService;

$passed = 0;
$failed = 0;
function check(bool $c, string $m): void
{
    global $passed, $failed;
    if ($c) {
        $passed++;
    } else {
        $failed++;
        fwrite(STDERR, "  FAIL: {$m}\n");
    }
}

// CSV
$csv = ReportService::toCsv(['Ad', 'Tutar'], [['Marka, A.Ş.', 1000], ['Çift"tırnak', 50]]);
check(str_starts_with($csv, "\xEF\xBB\xBF"), 'csv has UTF-8 BOM');
check(str_contains($csv, '"Marka, A.Ş."'), 'csv quotes cells with commas');
check(str_contains($csv, '"Çift""tırnak"'), 'csv escapes quotes');
check(str_contains($csv, "Ad,Tutar\r\n"), 'csv header line with CRLF');

// column letters
check(ReportService::colLetter(0) === 'A', 'colLetter 0 = A');
check(ReportService::colLetter(25) === 'Z', 'colLetter 25 = Z');
check(ReportService::colLetter(26) === 'AA', 'colLetter 26 = AA');

// XLSX magic + zip entries
$xlsx = ReportService::toXlsx(['Ad', 'Tutar'], [['Test', 5]]);
check(str_starts_with($xlsx, "PK\x03\x04"), 'xlsx starts with zip magic (PK)');
check(strlen($xlsx) > 200, 'xlsx has content');
$tmp = tempnam(sys_get_temp_dir(), 'xt');
file_put_contents($tmp, $xlsx);
$zip = new ZipArchive();
check($zip->open($tmp) === true, 'xlsx opens as a valid zip');
check($zip->locateName('xl/worksheets/sheet1.xml') !== false, 'xlsx contains sheet1');
check($zip->locateName('[Content_Types].xml') !== false, 'xlsx contains content types');
$zip->close();
@unlink($tmp);

// PDF magic + structure
$pdf = ReportService::toPdf('Gelir Raporu', ['Bölge', 'Gelir'], [['Marmara', '₺170.000'], ['Ege', '₺50.000']]);
check(str_starts_with($pdf, '%PDF-1.4'), 'pdf starts with %PDF-1.4');
check(str_contains($pdf, 'startxref'), 'pdf has xref');
check(str_ends_with(trim($pdf), '%%EOF'), 'pdf ends with EOF');
check(str_contains($pdf, '/Type /Catalog'), 'pdf has catalog');
check(str_contains($pdf, 'Gelir Raporu'), 'pdf contains title text');
// Turkish transliteration in the stream
check(str_contains($pdf, 'Bolge') || str_contains($pdf, 'Marmara'), 'pdf transliterates Turkish');

// PDF pagination (many rows → multiple pages)
$rows = [];
for ($i = 0; $i < 80; $i++) {
    $rows[] = ["Satır {$i}", (string) $i];
}
$bigPdf = ReportService::toPdf('Büyük', ['A', 'B'], $rows);
check(substr_count($bigPdf, '/Type /Page ') >= 2 || substr_count($bigPdf, '/Type /Page/') >= 2 || substr_count($bigPdf, '/MediaBox') >= 2, 'pdf paginates large row sets');

echo "Report tests: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
