<?php
/**
 * AJAX endpoint — Change encounter service date.
 *
 * POST params:
 *   csrf_token_form  string
 *   encounter        int
 *   pid              int
 *   new_date         string  YYYY-MM-DD
 *
 * Returns JSON { success: true } or { error: "message" }
 */

 require_once dirname(__FILE__, 3) . '/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

// CSRF
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode(['error' => 'Invalid CSRF token.']);
    exit;
}

// ACL — require clinical write access
if (!AclMain::aclCheckCore('encounters', 'auth')) {
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$enc      = (int)($_POST['encounter'] ?? 0);
$pid      = (int)($_POST['pid']      ?? 0);
$new_date = trim($_POST['new_date']  ?? '');

if (!$enc || !$pid) {
    echo json_encode(['error' => 'Missing encounter or pid.']);
    exit;
}

// Validate date format YYYY-MM-DD
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}

// Confirm the encounter belongs to this patient
$check = sqlQuery(
    "SELECT encounter FROM form_encounter WHERE encounter = ? AND pid = ? LIMIT 1",
    [$enc, $pid]
);
if (!$check) {
    echo json_encode(['error' => 'Encounter not found.']);
    exit;
}

// Update date in form_encounter (preserves time portion)
sqlStatement(
    "UPDATE form_encounter SET date = CONCAT(?, ' ', TIME(date)) WHERE encounter = ? AND pid = ?",
    [$new_date, $enc, $pid]
);

// Also update the date on associated billing rows so service date matches
sqlStatement(
    "UPDATE billing SET date = CONCAT(?, ' ', TIME(date)) WHERE encounter = ? AND pid = ? AND activity = 1",
    [$new_date, $enc, $pid]
);

echo json_encode(['success' => true]);
exit;
