<?php
/**
 * mdi_save_pnote.php
 * Saves a new Patient File note to the pnotes table.
 * Uses groupname = 'Patient File - MDI' to isolate these from all other pnotes.
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

$pid   = (int)trim($_POST['pid']   ?? '');
$note  = trim($_POST['note']  ?? '');
$title = trim($_POST['title'] ?? '');

if (!$pid || $note === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing pid or note text']);
    exit;
}

$now  = date('Y-m-d H:i:s');
$user = $_SESSION['authUser'] ?? '';

sqlStatement(
    "INSERT INTO pnotes (pid, user, groupname, date, title, body, activity, deleted, assigned_to, message_status)
     VALUES (?, ?, 'Patient File - MDI', ?, ?, ?, 1, 0, ?, 'New')",
    [$pid, $user, $now, $title, $note, $user]
);

echo json_encode(['ok' => true]);
