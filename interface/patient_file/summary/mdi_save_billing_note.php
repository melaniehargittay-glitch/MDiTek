<?php
/**
 * MDItek — Save Billing Note for an encounter.
 * Stores the note in the `billing_note` column of form_encounter (MDItek custom column).
 * Falls back to appending to form_encounter.reason if column doesn't exist.
 */

require_once("../../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

// CSRF check
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'CSRF token invalid']);
    exit;
}

// Auth check
if (!AclMain::aclCheckCore('patients', 'notes', '', 'write')) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pid       = (int)($_POST['pid']       ?? 0);
$encounter = (int)($_POST['encounter'] ?? 0);
$note      = trim($_POST['note']       ?? '');

if (!$pid || !$encounter) {
    echo json_encode(['ok' => false, 'error' => 'Missing pid or encounter']);
    exit;
}

// Try to use a dedicated mdi_billing_note column; create if not exists
// Check column existence
$col_check = sqlQuery(
    "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'form_encounter'
       AND COLUMN_NAME  = 'mdi_billing_note'"
);

if (empty($col_check['cnt'])) {
    // Add the column once
    sqlStatement("ALTER TABLE form_encounter ADD COLUMN mdi_billing_note TEXT DEFAULT NULL");
}

// Save the note
sqlStatement(
    "UPDATE form_encounter SET mdi_billing_note = ? WHERE pid = ? AND encounter = ?",
    [$note, $pid, $encounter]
);

echo json_encode(['ok' => true]);
