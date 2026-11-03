<?php
// Export landing registrations (landing_db.db) to true XLSX
// Admin-only. Exports ONLY the requested 13 columns in exact order.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$__xlr = ($_SESSION['role'] ?? '');
$__xlp = [];
if (!empty($_SESSION['allowed_pages'])) { $__d = json_decode($_SESSION['allowed_pages'], true); if (is_array($__d)) $__xlp = $__d; }
if ($__xlr !== 'admin' && !in_array('landing_registrations', $__xlp, true)) {
    http_response_code(403);
    echo 'Forbidden: admin access only.';
    exit;
}

require_once("landing_db.php");

$conn = landing_db();

// Optional filters from query string — export only what the table shows (server-side)
$filterYear = trim($_GET['year'] ?? $_GET['filterYear'] ?? '');
$filterSection = trim($_GET['section'] ?? $_GET['filterSection'] ?? '');
$filterIds = trim($_GET['ids'] ?? '');
$filterSearch = trim($_GET['search'] ?? $_GET['search_value'] ?? '');

$where = [];
// College Dean / Program Head export only their own department's courses.
$scopeSql = null;
if (!function_exists('session_course_scope')) {
    require_once("departments.php");
}
$scopeSql = session_course_scope_sql($conn);
if ($scopeSql !== null) {
    $where[] = $scopeSql;
}
if ($filterIds !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $filterIds)), fn($v) => $v > 0));
    $ids = array_values(array_unique($ids));
    if (!empty($ids)) $where[] = 'id IN (' . implode(',', $ids) . ')';
}
if ($filterYear !== '') $where[] = "year = '" . $conn->escapeString($filterYear) . "'";
if ($filterSection !== '') $where[] = "section = '" . $conn->escapeString($filterSection) . "'";
if ($filterSearch !== '') {
    $esc = $conn->escapeString($filterSearch);
    $where[] = "(student_number LIKE '%{$esc}%' OR name LIKE '%{$esc}%' OR course LIKE '%{$esc}%' OR section LIKE '%{$esc}%' OR contact_number LIKE '%{$esc}%' OR email LIKE '%{$esc}%')";
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

// Fetch registrations (filtered if requested)
$rows = [];
try {
    $q = $conn->query("SELECT * FROM `landing_registrations`" . $whereSql . " ORDER BY created_at DESC");
    if ($q) {
        while ($r = $q->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $r;
        }
    }
} catch (Exception $e) {
    http_response_code(500);
    echo 'Failed to fetch registrations: ' . htmlspecialchars($e->getMessage());
    exit;
}

// ONLY these 13 columns, in this exact order
$columns = [
    'student_number' => 'student_number',
    'lastname'       => 'lastname',
    'firstname'      => 'firstname',
    'middlename'     => 'middlename',
    'name_extension' => 'name_extension',
    'birthdate'      => 'birthdate',
    'gender'         => 'gender',
    'contact_number' => 'contact_number',
    'email'          => 'email',
    'barangay'       => 'barangay',
    'municipality'   => 'municipality',
    'province'       => 'province',
    'zipcode'        => 'zipcode',
];

// Helper: column letter (0 => A, 12 => M)
function xlsx_col($i) {
    $letters = '';
    while ($i >= 0) {
        $letters = chr($i % 26 + 65) . $letters;
        $i = intdiv($i, 26) - 1;
    }
    return $letters;
}
function xlsx_escape($s) {
    $s = (string)$s;
    // Strip invalid XML 1.0 control chars (Excel will reject the sheet otherwise)
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    return htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

// Build sheet data rows (header + data)
$headerLabels = array_values($columns); // already lowercase with underscores - matches DB col names
// For nicer header display, use the raw column name as header (as requested: exact label)
// The spec shows lower_snake headers, so we keep them as-is per request
$sheetRowsXml = '';
$colCount = count($columns);
$widths = [14,16,16,14,10,12,10,14,22,16,16,14,10]; // per column width

// Header row r=1
$sheetRowsXml .= '    <row r="1" ht="20" customHeight="1">' . "\n";
$ci = 0;
foreach ($headerLabels as $label) {
    $cellRef = xlsx_col($ci) . '1';
    $sheetRowsXml .= '      <c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . xlsx_escape($label) . '</t></is></c>' . "\n";
    $ci++;
}
$sheetRowsXml .= '    </row>' . "\n";

// Data rows
$rowNum = 2;
foreach ($rows as $row) {
    $sheetRowsXml .= '    <row r="' . $rowNum . '">' . "\n";
    $ci = 0;
    foreach (array_keys($columns) as $colKey) {
        $cellRef = xlsx_col($ci) . $rowNum;
        $val = $row[$colKey] ?? '';
        // Birthdate may be DATE, ensure Y-m-d
        $sheetRowsXml .= '      <c r="' . $cellRef . '" t="inlineStr" s="2"><is><t>' . xlsx_escape($val) . '</t></is></c>' . "\n";
        $ci++;
    }
    $sheetRowsXml .= '    </row>' . "\n";
    $rowNum++;
}

// cols XML
$colsXml = '';
for ($i = 0; $i < $colCount; $i++) {
    $colsXml .= '    <col min="' . ($i+1) . '" max="' . ($i+1) . '" width="' . $widths[$i] . '" customWidth="1"/>' . "\n";
}

// XML templates
$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
'<Default Extension="xml" ContentType="application/xml"/>' .
'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
'</Types>';

$rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
'</Relationships>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
'<sheets><sheet name="Landing Registrations" sheetId="1" r:id="rId1"/></sheets>' .
'</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
'</Relationships>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
'<numFmts count="0"/>' .
'<fonts count="2"><font><sz val="10"/><color theme="1"/><name val="Calibri"/><family val="2"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font></fonts>' .
'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1A4D2E"/><bgColor indexed="64"/></patternFill></fill></fills>' .
'<borders count="1"><border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border></borders>' .
'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
'<cellXfs count="3">' .
'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' .
'<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="0"/></xf>' .
'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyBorder="1"><alignment vertical="center"/></xf>' .
'</cellXfs>' .
'</styleSheet>';

$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
'<sheetViews><sheetView workbookViewId="0"/></sheetViews>' .
'<sheetFormatPr defaultRowHeight="15"/>' .
'<cols>' . "\n" . $colsXml . '</cols>' .
'<sheetData>' . "\n" . $sheetRowsXml . '</sheetData>' .
'</worksheet>';

$core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
'<dc:creator>Grading System</dc:creator><cp:lastModifiedBy>Grading System</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>' .
'</cp:coreProperties>';

$app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
'<Application>Grading System</Application><HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>' .
'<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Landing Registrations</vt:lpstr></vt:vector></TitlesOfParts>' .
'</Properties>';

// Flush any prior output that would corrupt the download / cause 500
if (ob_get_level()) { @ob_end_clean(); }

// Fallback to CSV if ZipArchive missing (some shared hosts disable it)
if (!class_exists('ZipArchive')) {
    $filename = 'Landing_Registrations_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, array_values($columns));
    foreach ($rows as $row) {
        $csvRow = [];
        foreach (array_keys($columns) as $colKey) $csvRow[] = $row[$colKey] ?? '';
        fputcsv($out, $csvRow);
    }
    fclose($out);
    exit;
}

// Create true XLSX (ZIP) — robust temp handling for restricted hosts (e.g. essuc-academic.yzz.me where sys_get_temp_dir() is not writable)
$filename = 'Landing_Registrations_' . date('Y-m-d_His') . '.xlsx';
$tmp = false;
$tmpCandidates = [sys_get_temp_dir(), ini_get('upload_tmp_dir'), __DIR__ . '/tmp', __DIR__ . '/uploads', __DIR__ . '/db', __DIR__];
foreach ($tmpCandidates as $candidate) {
    if (empty($candidate)) continue;
    $candidate = rtrim($candidate, '/\\');
    if (!is_dir($candidate)) { @mkdir($candidate, 0755, true); }
    if (is_dir($candidate) && is_writable($candidate)) {
        $tmp = @tempnam($candidate, 'landing_xlsx_');
        if ($tmp !== false) break;
    }
}
if ($tmp === false) {
    $fallbackDir = __DIR__ . '/tmp';
    if (!is_dir($fallbackDir)) @mkdir($fallbackDir, 0755, true);
    if (is_dir($fallbackDir) && is_writable($fallbackDir)) {
        $tmp = $fallbackDir . '/landing_' . uniqid('', true) . '.xlsx';
        if (@touch($tmp) === false) $tmp = false;
        else @unlink($tmp);
    }
}
if ($tmp === false) {
    // Last resort: stream CSV instead of 500 (host temp not writable)
    $filename = 'Landing_Registrations_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, array_values($columns));
    foreach ($rows as $row) {
        $csvRow = [];
        foreach (array_keys($columns) as $colKey) $csvRow[] = $row[$colKey] ?? '';
        fputcsv($out, $csvRow);
    }
    fclose($out);
    exit;
}
@unlink($tmp);
try {
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) {
        throw new Exception('ZipArchive open failed for ' . $tmp);
    }
} catch (Throwable $e) {
    if (file_exists($tmp)) @unlink($tmp);
    $filename = 'Landing_Registrations_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, array_values($columns));
    foreach ($rows as $row) {
        $csvRow = [];
        foreach (array_keys($columns) as $colKey) $csvRow[] = $row[$colKey] ?? '';
        fputcsv($out, $csvRow);
    }
    fclose($out);
    exit;
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rels);
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
$zip->addFromString('docProps/core.xml', $core);
$zip->addFromString('docProps/app.xml', $app);
$zip->close();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp));
header('Pragma: no-cache');
header('Expires: 0');
header('Cache-Control: must-revalidate');

readfile($tmp);
@unlink($tmp);
exit;
