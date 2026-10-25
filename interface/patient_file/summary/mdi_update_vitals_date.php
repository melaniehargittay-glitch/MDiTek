<?php
/**
 * mdi_update_vitals_date.php
 * Updates the date of a vitals record from the demographics vitals card.
 * Called via POST from demographics.php inline vitals date editor.
 */

require_once(__DIR__ . "/../../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
    exit;
}
if (!AclMain::aclCheckCore('patients', 'med')) {
    echo json_encode(['ok' => false, 'error' => 'Not authorized']);
    exit;
}

$vitals_id = (int)($_POST['vitals_id'] ?? 0);
$pid       = (int)($_POST['pid']       ?? 0);
$new_date  = trim($_POST['new_date']   ?? '');

if (!$vitals_id || !$pid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify the vitals record belongs to this patient
$row = sqlQuery(
    "SELECT id FROM form_vitals WHERE id = ? AND pid = ? LIMIT 1",
    [$vitals_id, $pid]
);
if (empty($row)) {
    echo json_encode(['ok' => false, 'error' => 'Vitals record not found']);
    exit;
}

$new_datetime = $new_date . ' 00:00:00';

// Update form_vitals.date
sqlStatement(
    "UPDATE form_vitals SET date = ? WHERE id = ? AND pid = ?",
    [$new_datetime, $vitals_id, $pid]
);

// Also update forms.date for the matching encounter form entry
sqlStatement(
    "UPDATE forms SET date = ?
      WHERE pid = ? AND formdir = 'vitals'
        AND form_id = ?",
    [$new_datetime, $pid, $vitals_id]
);

echo json_encode(['ok' => true]);
