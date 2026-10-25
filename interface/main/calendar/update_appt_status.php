<?php
/**
 * update_appt_status.php
 *
 * Updates pc_apptstatus on openemr_postcalendar_events.
 * Place at: openemr/portal/update_appt_status.php
 *
 * POST params:
 *   pc_eid          - int, event ID
 *   pc_apptstatus   - string, new status ('?' = confirmed/scheduled, 'x' = cancelled)
 *   csrf_token_form - CSRF token
 */

// Bootstrap OpenEMR
$ignoreAuth = false;
require_once __DIR__ . '/../interface/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

// ── Auth ───────────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('patientportal', 'portal')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── CSRF ───────────────────────────────────────────────────────────────────
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ── Input validation ───────────────────────────────────────────────────────
$pc_eid = (int)($_POST['pc_eid'] ?? 0);
$newStatus = $_POST['pc_apptstatus'] ?? '';

// Only allow known safe status values
$allowedStatuses = ['?', 'x', '-', '^', '+', 'E', 'T', 'N'];
if ($pc_eid <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// ── Update ─────────────────────────────────────────────────────────────────
try {
    sqlStatement(
        "UPDATE openemr_postcalendar_events
         SET pc_apptstatus = ?
         WHERE pc_eid = ?",
        [$newStatus, $pc_eid]
    );

    echo json_encode(['success' => true, 'pc_eid' => $pc_eid, 'status' => $newStatus]);
} catch (Throwable $e) {
    error_log('update_appt_status.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
exit;
