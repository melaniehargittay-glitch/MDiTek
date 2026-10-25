<?php
/**
 * MDItek — Get Billing Note for an encounter.
 * Returns JSON: { note: "..." }
 */

require_once("../../globals.php");
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('patients', 'notes')) {
    echo json_encode(['note' => '']);
    exit;
}

$pid       = (int)($_GET['pid']       ?? 0);
$encounter = (int)($_GET['encounter'] ?? 0);

if (!$pid || !$encounter) {
    echo json_encode(['note' => '']);
    exit;
}

// Check if mdi_billing_note column exists
$col_check = sqlQuery(
    "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'form_encounter'
       AND COLUMN_NAME  = 'mdi_billing_note'"
);

if (empty($col_check['cnt'])) {
    echo json_encode(['note' => '']);
    exit;
}

$row = sqlQuery(
    "SELECT mdi_billing_note FROM form_encounter WHERE pid = ? AND encounter = ?",
    [$pid, $encounter]
);

echo json_encode(['note' => $row['mdi_billing_note'] ?? '']);
