<?php
require __DIR__ . '/../../src/auth.php';
require __DIR__ . '/../../src/helpers.php';
require_admin();

$headers = ['Sr. No.', 'Student ID', 'Name', 'Email', 'Nationality', 'Research Area'];
$example = ['1', 'INTL-2026-001', 'Jane Doe', 'jane@example.com', 'Nepal', 'Operations Research'];

function xml_esc($s) { return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function row_xml(array $cells, int $rowIdx): string {
    $xml = '<row r="' . $rowIdx . '">';
    foreach ($cells as $i => $val) {
        $col = chr(ord('A') + $i) . $rowIdx;
        $xml .= '<c r="' . $col . '" t="inlineStr"><is><t>' . xml_esc((string)$val) . '</t></is></c>';
    }
    return $xml . '</row>';
}

$sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetData>'
    . row_xml($headers, 1)
    . row_xml($example, 2)
    . '</sheetData></worksheet>';

$workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '</Relationships>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '</Types>';

$tmp = tempnam(sys_get_temp_dir(), 'intlsample');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Failed to build sample file.');
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $workbookXml);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="intl_candidates_sample.xlsx"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
@unlink($tmp);
