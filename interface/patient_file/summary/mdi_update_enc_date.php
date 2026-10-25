<?php
/**
 * mdi_update_enc_date.php
 * Updates the date of any encounter from the demographics quick-charges panel.
 * Called via POST from demographics.php inline date editor.
 */

require_once(__DIR__ . "/../../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
    exit;
}
if (!AclMain::aclCheckCore('encounters', 'auth')) {
    echo json_encode(['ok' => false, 'error' => 'Not authorized']);
    exit;
}

$encounter = (int)($_POST['encounter'] ?? 0);
$pid       = (int)($_POST['pid']       ?? 0);
$new_date  = trim($_POST['new_date']   ?? '');

if (!$encounter || !$pid || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

// Verify the encounter belongs to this patient
$enc = sqlQuery(
    "SELECT encounter FROM form_encounter WHERE encounter = ? AND pid = ? LIMIT 1",
    [$encounter, $pid]
);
if (empty($enc)) {
    echo json_encode(['ok' => false, 'error' => 'Encounter not found']);
    exit;
}

// Update both form_encounter.date and forms.date
$new_datetime = $new_date . ' 00:00:00';
sqlStatement(
    "UPDATE form_encounter SET date = ? WHERE encounter = ? AND pid = ?",
    [$new_datetime, $encounter, $pid]
);
sqlStatement(
    "UPDATE forms SET date = ? WHERE encounter = ? AND pid = ? AND formdir = 'newpatient'",
    [$new_datetime, $encounter, $pid]
);

echo json_encode(['ok' => true]);
