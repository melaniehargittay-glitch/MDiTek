<?php
/**
 * mdi_update_intake_date.php
 *
 * Updates the date column of form_questionnaire_assessments for a given
 * form row. Mirrors the pattern of mdi_update_enc_date.php and
 * mdi_update_vitals_date.php.
 *
 * Place at:
 *   /interface/patient_file/summary/mdi_update_intake_date.php
 *
 * Expects POST:
 *   form_id         int     — form_questionnaire_assessments.id
 *   pid             int     — patient pid (used for ACL and ownership check)
 *   new_date        string  — YYYY-MM-DD
 *   csrf_token_form string  — CSRF token
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

// ── CSRF check ────────────────────────────────────────────────────────────────
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token.']);
    exit;
}

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required.']);
    exit;
}

// ── ACL: must have patient demographics write access ─────────────────────────
if (!AclMain::aclCheckCore('patients', 'demo', '', 'write')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

// ── Input validation ──────────────────────────────────────────────────────────
$form_id  = intval($_POST['form_id']  ?? 0);
$pid      = intval($_POST['pid']      ?? 0);
$new_date = trim($_POST['new_date']   ?? '');

if ($form_id <= 0 || $pid <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing form_id or pid.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $new_date)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}

// Validate the date is a real calendar date
[$y, $m, $d] = explode('-', $new_date);
if (!checkdate((int)$m, (int)$d, (int)$y)) {
    echo json_encode(['ok' => false, 'error' => 'Date is not a valid calendar date.']);
    exit;
}

// ── Ownership check: confirm this form row belongs to the given pid ───────────
$row = sqlQuery(
    "SELECT id, pid FROM form_questionnaire_assessments WHERE id = ? AND pid = ? AND activity = 1",
    [$form_id, $pid]
);

if (empty($row)) {
    echo json_encode(['ok' => false, 'error' => 'Form record not found or does not belong to this patient.']);
    exit;
}

// ── Preserve existing time component if present, otherwise use midnight ───────
$existing = sqlQuery(
    "SELECT `date` FROM form_questionnaire_assessments WHERE id = ?",
    [$form_id]
);
$existing_time = '00:00:00';
if (!empty($existing['date']) && strlen($existing['date']) >= 19) {
    $existing_time = substr($existing['date'], 11, 8);
}

$new_datetime = $new_date . ' ' . $existing_time;

// ── Update ─────────────────────────────────────────────────────────────────────
sqlStatement(
    "UPDATE form_questionnaire_assessments SET `date` = ? WHERE id = ? AND pid = ?",
    [$new_datetime, $form_id, $pid]
);

// Also update the corresponding forms table entry so all OpenEMR views are consistent
sqlStatement(
    "UPDATE forms SET date = ? WHERE form_id = ? AND pid = ? AND formdir = 'questionnaire_assessments'",
    [$new_datetime, $form_id, $pid]
);

echo json_encode(['ok' => true, 'new_date' => $new_date, 'new_datetime' => $new_datetime]);
