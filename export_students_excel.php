<?php
// Export student_list to true XLSX with the bulk-import template format:
// student_number,lastname,firstname,middlename,name_extension,birthdate,
// gender,contact_number,email,barangay,municipality,province,zipcode
// Optional ?section=<grade - section> filter (same value as students.php sectionFilter).

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['admin_id']) && !isset($_SESSION['username'])) {
    header('Location: login.php');
    exit;
}

require_once("DBConnection.php");

if (!isset($conn) || !($conn instanceof SQLite3)) {
    $conn = new DBConnection();
}

$section = isset($_GET['section']) ? trim($_GET['section']) : '';

// Detect which of the expected columns actually exist (older DBs may lack
// firstname/lastname/name_extension). Missing columns export as ''.
$existingCols = [];
if ($res = $conn->query("PRAGMA table_info('student_list')")) {
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (isset($row['name'])) {
            $existingCols[] = $row['name'];
        }
    }
}
$hasCol = function ($col) use ($existingCols) {
    return in_array($col, $existingCols, true);
};

// Build query with optional section filter (grade || ' - ' || section)
$sql = "SELECT ss.* FROM `student_list` ss
        INNER JOIN `class_list` c ON ss.class_id = c.class_id";
if ($section !== '') {
    $sql .= " WHERE (c.grade || ' - ' || c.section) = '" . $conn->escapeString($section) . "'";
}
$sql .= " ORDER BY TRIM(ss.name) COLLATE NOCASE";

$rows = [];
$q = $conn->query($sql);
if ($q) {
    while ($r = $q->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $r;
    }
}

// Fallback: derive firstname/lastname/middlename from `name` ("Last, First Middle Ext")
// when the split columns are empty (legacy rows).
$parseName = function ($fullName) {
    $firstname = '';
    $lastname = '';
    $middlename = '';
    $fullName = trim((string)$fullName);
    if ($fullName === '') {
        return [$firstname, $lastname, $middlename];
    }
    if (strpos($fullName, ',') !== false) {
        $parts = explode(',', $fullName, 2);
        $lastname = trim($parts[0] ?? '');
        $rest = preg_split('/\s+/', trim($parts[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($rest)) {
            $firstname = array_shift($rest);
        }
        if (!empty($rest)) {
            $middlename = implode(' ', $rest);
        }
    } else {
        $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
        if (!empty($parts)) {
            $firstname = array_shift($parts);
        }
        if (!empty($parts)) {
            $lastname = array_pop($parts);
        }
        if (!empty($parts)) {
            $middlename = implode(' ', $parts);
        }
    }
    return [$firstname, $lastname, $middlename];
};

// ONLY these 13 columns, in this exact order (matches bulk import template)
$columns = [
    'student_number',
    'lastname',
    'firstname',
    'middlename',
    'name_extension',
    'birthdate',
    'gender',
    'contact_number',
    'email',
    'barangay',
    'municipality',
    'province',
    'zipcode',
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
$sheetRowsXml = '';
$colCount = count($columns);
// Generous widths so headers (e.g. student_number, name_extension,
// contact_number, municipality) display in full instead of clipping.
$widths = [18,20,20,18,16,14,10,18,28,20,20,18,12]; // per column width

// Header row r=1 (taller + wrapped so long labels fit on narrow screens)
$sheetRowsXml .= '    <row r="1" ht="30" customHeight="1">' . "\n";
$ci = 0;
foreach ($columns as $label) {
    $cellRef = xlsx_col($ci) . '1';
    $sheetRowsXml .= '      <c r="' . $cellRef . '" t="inlineStr" s="1"><is><t>' . xlsx_escape($label) . '</t></is></c>' . "\n";
    $ci++;
}
$sheetRowsXml .= '    </row>' . "\n";

// Data rows (collected once; shared by the XLSX output and the CSV fallback)
$exportRows = [];
foreach ($rows as $row) {
    $firstname = $hasCol('firstname') ? trim((string)($row['firstname'] ?? '')) : '';
    $lastname = $hasCol('lastname') ? trim((string)($row['lastname'] ?? '')) : '';
    $middlename = trim((string)($row['middlename'] ?? ''));
    if (($firstname === '' || $lastname === '') && !empty($row['name'])) {
        [$pf, $pl, $pm] = $parseName($row['name']);
        if ($firstname === '') {
            $firstname = $pf;
        }
        if ($lastname === '') {
            $lastname = $pl;
        }
        if ($middlename === '') {
            $middlename = $pm;
        }
    }
    $exportRows[] = [
        'student_number' => $row['student_number'] ?? '',
        'lastname'       => $lastname,
        'firstname'      => $firstname,
        'middlename'     => $middlename,
        'name_extension' => ($hasCol('name_extension') ? ($row['name_extension'] ?? '') : ''),
        'birthdate'      => $row['birthdate'] ?? '',
        'gender'         => $row['gender'] ?? '',
        'contact_number' => $row['contact_number'] ?? '',
        'email'          => $row['email'] ?? '',
        'barangay'       => $row['barangay'] ?? '',
        'municipality'   => $row['municipality'] ?? '',
        'province'       => $row['province'] ?? '',
        'zipcode'        => $row['zipcode'] ?? '',
    ];
}
$rowNum = 2;
foreach ($exportRows as $vals) {
    $sheetRowsXml .= '    <row r="' . $rowNum . '">' . "\n";
    $ci = 0;
    foreach ($columns as $colKey) {
        $cellRef = xlsx_col($ci) . $rowNum;
        $sheetRowsXml .= '      <c r="' . $cellRef . '" t="inlineStr" s="2"><is><t>' . xlsx_escape($vals[$colKey]) . '</t></is></c>' . "\n";
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
'<sheets><sheet name="Students" sheetId="1" r:id="rId1"/></sheets>' .
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
'<xf numFmtId="0" fontId="1" fillId="1" borderId="0" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' .
'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyBorder="1"><alignment vertical="center"/></xf>' .
'</cellXfs>' .
'</styleSheet>';

$lastDataRow = max(1, $rowNum - 1);
$lastColLetter = xlsx_col($colCount - 1);
$sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/></sheetView></sheetViews>' .
'<sheetFormatPr defaultRowHeight="15"/>' .
'<cols>' . "\n" . $colsXml . '</cols>' .
'<sheetData>' . "\n" . $sheetRowsXml . '</sheetData>' .
'<autoFilter ref="A1:' . $lastColLetter . $lastDataRow . '"/>' .
'</worksheet>';

$core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
'<dc:creator>Grading System</dc:creator><cp:lastModifiedBy>Grading System</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>' .
'</cp:coreProperties>';

$app = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" .
'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
'<Application>Grading System</Application><HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>1</vt:i4></vt:variant></vt:vector></HeadingPairs>' .
'<TitlesOfParts><vt:vector size="1" baseType="lpstr"><vt:lpstr>Students</vt:lpstr></vt:vector></TitlesOfParts>' .
'</Properties>';

// Create true XLSX (ZIP). Many budget hosts lack the php-zip extension or
// block the system temp dir, either of which used to end in HTTP 500.
// So: try XLSX first, and fall back to an Excel-compatible CSV download
// (same 13 columns, same order) so the button always produces a file.
$safeSection = $section !== '' ? ('_' . preg_replace('/[^a-zA-Z0-9]+/', '_', $section)) : '';
$stamp = date('Y-m-d_His');

function export_tmp_file($prefix) {
    $candidates = [];
    $sys = sys_get_temp_dir();
    if (is_string($sys) && $sys !== '') {
        $candidates[] = $sys;
    }
    $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . 'tmp';
    foreach ($candidates as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $tmp = tempnam($dir, $prefix);
            if ($tmp !== false) {
                return $tmp;
            }
        }
    }
    return false;
}

$xlsxTmp = false;
if (class_exists('ZipArchive')) {
    $tmp = export_tmp_file('students_xlsx_');
    if ($tmp !== false) {
        @unlink($tmp);
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE) === true) {
            $zip->addFromString('[Content_Types].xml', $contentTypes);
            $zip->addFromString('_rels/.rels', $rels);
            $zip->addFromString('xl/workbook.xml', $workbook);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
            $zip->addFromString('xl/styles.xml', $styles);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
            $zip->addFromString('docProps/core.xml', $core);
            $zip->addFromString('docProps/app.xml', $app);
            if ($zip->close() === true && is_file($tmp) && filesize($tmp) > 0) {
                $xlsxTmp = $tmp;
            } else {
                @unlink($tmp);
            }
        } else {
            @unlink($tmp);
        }
    }
}

// Drop any stray output (warnings/whitespace) so downloads aren't corrupted.
while (ob_get_level() > 0) {
    ob_end_clean();
}

if ($xlsxTmp !== false) {
    $filename = 'Students' . $safeSection . '_' . $stamp . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($xlsxTmp));
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');

    readfile($xlsxTmp);
    @unlink($xlsxTmp);
    exit;
}

// CSV fallback (no ZipArchive / no writable temp dir on this host).
$filename = 'Students' . $safeSection . '_' . $stamp . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');
header('Cache-Control: must-revalidate');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel detects encoding
fputcsv($out, $columns);
foreach ($exportRows as $vals) {
    $line = [];
    foreach ($columns as $colKey) {
        $line[] = (string)($vals[$colKey] ?? '');
    }
    fputcsv($out, $line);
}
fclose($out);
exit;
