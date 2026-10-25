<?php
/**
 * mdi_soap_encs.php — Returns encounter IDs that have a SOAP note for a patient.
 * Deploy to: /interface/main/tabs/mdi_soap_encs.php
 *
 * Returns JSON: { "encounter_ids": [123, 456, ...] }
 */
$sessionAllowWrite = false;
require_once(__DIR__ . '/../../globals.php');

use OpenEMR\Common\Csrf\CsrfUtils;

// Auth check
if (empty($_SESSION['authUser'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not authorized']);
    exit;
}

// CSRF check (GET token passed as query param)
if (!CsrfUtils::verifyCsrfToken($_GET['csrf_token_form'] ?? '')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'CSRF']);
    exit;
}

$pid = (int)($_GET['pid'] ?? 0);
if ($pid <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['encounter_ids' => []]);
    exit;
}

$ids = [];
try {
    $res = sqlStatement(
        "SELECT DISTINCT f.encounter
           FROM forms f
          WHERE f.pid = ?
            AND f.form_name LIKE 'SOAP%'
            AND f.deleted = 0
          ORDER BY f.encounter DESC",
        [$pid]
    );
    while ($row = sqlFetchArray($res)) {
        $ids[] = (int)$row['encounter'];
    }
} catch (Throwable $e) {
    error_log('mdi_soap_encs: ' . $e->getMessage());
}

header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['encounter_ids' => $ids]);
