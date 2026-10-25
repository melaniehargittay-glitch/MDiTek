<?php
/**
 * MDItek ICD-10 Code Search
 * Place at: /interface/patient_file/summary/mdi_icd_search.php
 */

// Catch any output (PHP notices/warnings) that would break JSON
ob_start();

$ignoreAuth = false;

// Try to load globals from multiple possible relative paths
$globalsLoaded = false;
$attempts = [
    __DIR__ . '/../../../globals.php',
    __DIR__ . '/../../globals.php',
    dirname(__DIR__) . '/../../globals.php',
];
foreach ($attempts as $path) {
    if (file_exists($path)) {
        require_once($path);
        $globalsLoaded = true;
        break;
    }
}

ob_end_clean(); // discard any noise from globals load

header('Content-Type: application/json');

if (!$globalsLoaded) {
    echo json_encode(['error' => 'globals.php not found. Tried: ' . implode(', ', $attempts)]);
    exit;
}

use OpenEMR\Common\Csrf\CsrfUtils;

// CSRF check
if (!CsrfUtils::verifyCsrfToken($_REQUEST['csrf_token_form'] ?? '')) {
    echo json_encode(['error' => 'CSRF token invalid']);
    exit;
}

$term  = trim($_GET['term'] ?? '');
$limit = min((int)($_GET['limit'] ?? 20), 50);

if (strlen($term) < 2) {
    echo json_encode([]);
    exit;
}

$results = [];

try {
    // Verify table exists
    $testRes = sqlStatement("SELECT * FROM icd10_dx_order_code LIMIT 1");
    $sample  = sqlFetchArray($testRes);

    if ($sample === false) {
        echo json_encode(['error' => 'Table icd10_dx_order_code is empty']);
        exit;
    }

    $cols = array_keys($sample);

    // Auto-detect code column
    $codeCol = null;
    foreach (['dx_code', 'code', 'icd_code', 'icd10_code'] as $c) {
        if (array_key_exists($c, $sample)) { $codeCol = $c; break; }
    }
    if (!$codeCol) {
        echo json_encode(['error' => 'No code column found. Columns: ' . implode(', ', $cols)]);
        exit;
    }

    // Auto-detect description column
    $descCol = null;
    foreach (['long_desc', 'description', 'short_desc', 'code_text', 'desc'] as $c) {
        if (array_key_exists($c, $sample)) { $descCol = $c; break; }
    }
    if (!$descCol) {
        echo json_encode(['error' => 'No description column found. Columns: ' . implode(', ', $cols)]);
        exit;
    }

    $res = sqlStatement(
        "SELECT `$codeCol`, `$descCol`
         FROM icd10_dx_order_code
         WHERE (`$codeCol` LIKE ? OR `$descCol` LIKE ?)
           AND (`$descCol` IS NULL OR `$descCol` NOT LIKE 'Unspecified diagnosis%')
         ORDER BY
           CASE WHEN `$codeCol` LIKE ? THEN 0 ELSE 1 END,
           `$codeCol`
         LIMIT ?",
        [
            $term . '%',
            '%' . $term . '%',
            $term . '%',
            $limit
        ]
    );

    while ($row = sqlFetchArray($res)) {
        $results[] = [
            'code'      => 'ICD10:' . $row[$codeCol],
            'code_text' => $row[$descCol] ?? '',
        ];
    }

} catch (Throwable $e) {
    echo json_encode(['error' => 'Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()]);
    exit;
}

echo json_encode($results);
