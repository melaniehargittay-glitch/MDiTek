<?php
/**
 * MDItek - Get a single medical issue as JSON for the custom diagnosis editor.
 * Reads directly from the openemr `lists` table.
 * Place in: /interface/patient_file/summary/mdi_get_issue.php
 */

require_once("../../globals.php");
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');


$id = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo json_encode(['error' => 'Missing id']);
    exit;
}

$row = sqlQuery(
    "SELECT id, pid, type, title, activity, diagnosis,
            DATE_FORMAT(begdate, '%Y-%m-%d') AS begdate,
            DATE_FORMAT(enddate, '%Y-%m-%d') AS enddate,
            verification, comments, occurrence, classification
     FROM lists
     WHERE id = ?",
    [$id]
);

if (!$row) {
    echo json_encode(['error' => 'Issue not found', 'id' => $id]);
    exit;
}

echo json_encode([
    'id'             => (int)$row['id'],
    'pid'            => (int)$row['pid'],
    'type'           => $row['type'],
    'title'          => $row['title'] ?? '',
    'activity'       => $row['activity'],
    'diagnosis'      => $row['diagnosis'] ?? '',
    'begdate'        => $row['begdate'] ?? '',
    'enddate'        => $row['enddate'] ?? '',
    'verification'   => $row['verification'] ?? '',
    'comments'       => $row['comments'] ?? '',
    'occurrence'     => $row['occurrence'],
    'classification' => $row['classification'],
]);
