<?php
/**
 * Save Claim Dates (Initial Treatment, Injury, Last X-Ray, Onset)
 * to patient_data table.
 *
 * Called via fetch() POST from the Claim Dates card in demographics.php
 *
 * @package   OpenEMR (Custom)
 */

require_once("../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

// ── CSRF check ──────────────────────────────────────────────────────
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// ── ACL check ───────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('patients', 'demo', '', 'write')) {
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

// ── Input ───────────────────────────────────────────────────────────
$pid = intval($_POST['pid'] ?? 0);
if ($pid <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid patient ID']);
    exit;
}

// Helper: validate and format date input (accepts YYYY-MM-DD or MM/DD/YYYY)
function sanitize_date_input(string $val): ?string
{
    $val = trim($val);
    if ($val === '') {
        return null;
    }
    // Already YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        $d = DateTime::createFromFormat('Y-m-d', $val);
        return ($d && $d->format('Y-m-d') === $val) ? $val : null;
    }
    // MM/DD/YYYY
    if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $val)) {
        $d = DateTime::createFromFormat('m/d/Y', $val);
        return $d ? $d->format('Y-m-d') : null;
    }
    return null;
}

$initial_treatment_date = sanitize_date_input($_POST['initial_treatment_date'] ?? '');
$injury_date            = sanitize_date_input($_POST['injury_date']            ?? '');
$last_xray_date         = sanitize_date_input($_POST['last_xray_date']         ?? '');

// ── Save ─────────────────────────────────────────────────────────────
sqlStatement(
    "UPDATE patient_data SET
        initial_treatment_date = ?,
        injury_date            = ?,
        last_xray_date         = ?
     WHERE pid = ?",
    [
        $initial_treatment_date,
        $injury_date,
        $last_xray_date,
        $pid
    ]
);

echo json_encode(['success' => true]);
exit;
