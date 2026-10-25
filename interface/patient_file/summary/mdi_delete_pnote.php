<?php
/**
 * mdi_delete_pnote.php
 * Soft-deletes a Patient File note (sets deleted = 1).
 */

require_once(__DIR__ . "/../../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'CSRF mismatch']);
    exit;
}
if (!AclMain::aclCheckCore('patients', 'notes', '', 'write')) {
    echo json_encode(['ok' => false, 'error' => 'Not authorized']);
    exit;
}

$id  = (int)trim($_POST['id']  ?? '');
$pid = (int)trim($_POST['pid'] ?? '');

if (!$id || !$pid) {
    echo json_encode(['ok' => false, 'error' => 'Missing id or pid']);
    exit;
}

// Verify ownership and type before deleting
$existing = sqlQuery(
    "SELECT id FROM pnotes WHERE id = ? AND pid = ? AND groupname = 'Patient File - MDI' AND deleted != 1 LIMIT 1",
    [$id, $pid]
);
if (empty($existing)) {
    echo json_encode(['ok' => false, 'error' => 'Note not found']);
    exit;
}

sqlStatement(
    "UPDATE pnotes SET deleted = 1, activity = 0 WHERE id = ? AND pid = ?",
    [$id, $pid]
);

echo json_encode(['ok' => true]);
