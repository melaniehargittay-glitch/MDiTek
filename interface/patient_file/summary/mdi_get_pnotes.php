<?php
/**
 * mdi_get_pnotes.php
 * Returns only Patient File notes (groupname = 'Patient File - MDI') as JSON.
 * Excludes visit summaries, encounter notes, and all other pnotes types.
 */

require_once(__DIR__ . "/../../globals.php");
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('patients', 'notes')) {
    echo json_encode(['ok' => false, 'error' => 'Not authorized', 'notes' => []]);
    exit;
}

$pid = (int)($_GET['pid'] ?? 0);
if (!$pid) {
    echo json_encode(['ok' => false, 'error' => 'Missing pid', 'notes' => []]);
    exit;
}

$rows = [];
$res = sqlStatement(
    "SELECT pn.id, pn.date, pn.title, pn.body, pn.user,
            CONCAT(u.fname, ' ', u.lname) AS user_name
     FROM pnotes pn
     LEFT JOIN users u ON u.username = pn.user
     WHERE pn.pid = ?
       AND pn.deleted != 1
       AND pn.activity = 1
       AND pn.groupname = 'Patient File - MDI'
     ORDER BY pn.date DESC",
    [$pid]
);
while ($row = sqlFetchArray($res)) {
    $rows[] = [
        'id'    => (int)$row['id'],
        'date'  => !empty($row['date']) ? date('m/d/Y g:i A', strtotime($row['date'])) : '',
        'user'  => trim($row['user_name']) ?: $row['user'],
        'title' => $row['title'] ?? '',
        'body'  => $row['body']  ?? '',
    ];
}

echo json_encode(['ok' => true, 'notes' => $rows]);
