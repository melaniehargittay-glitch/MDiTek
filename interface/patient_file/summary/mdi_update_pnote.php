<?php
/**
 * mdi_update_pnote.php
 * Updates an existing Patient File note (title + body).
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

$id    = (int)trim($_POST['note_id'] ?? '');
$pid   = (int)trim($_POST['pid']     ?? '');
$note  = trim($_POST['note']  ?? '');
$title = trim($_POST['title'] ?? '');

if (!$id || !$pid || $note === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Verify this note belongs to this patient and is a Patient File note
$existing = sqlQuery(
    "SELECT id FROM pnotes WHERE id = ? AND pid = ? AND groupname = 'Patient File - MDI' AND deleted != 1 LIMIT 1",
    [$id, $pid]
);
if (empty($existing)) {
    echo json_encode(['ok' => false, 'error' => 'Note not found or not editable']);
    exit;
}

sqlStatement(
    "UPDATE pnotes SET title = ?, body = ? WHERE id = ? AND pid = ?",
    [$title, $note, $id, $pid]
);

echo json_encode(['ok' => true]);
