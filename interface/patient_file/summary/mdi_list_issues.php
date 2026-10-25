<?php
/**
 * MDItek - List all active medical_problem issues for a patient as JSON.
 * Place in: /interface/patient_file/summary/mdi_list_issues.php
 */

require_once("../../globals.php");
use OpenEMR\Common\Acl\AclMain;

header('Content-Type: application/json');

if (!AclMain::aclCheckCore('patients', 'med')) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$pid = (int)($_GET['pid'] ?? 0);
if (!$pid) {
    echo json_encode([]);
    exit;
}

$res = sqlStatement(
    "SELECT id, title, diagnosis,
            DATE_FORMAT(begdate, '%Y-%m-%d') AS begdate,
            activity, verification
     FROM lists
     WHERE pid = ? AND type = 'medical_problem' AND activity = 1
     ORDER BY date DESC",
    [$pid]
);

$issues = [];
while ($row = sqlFetchArray($res)) {
    $label = $row['title'];
    if (empty($label) || strtolower(trim($label)) === 'default diagnosis') {
        if (!empty($row['diagnosis'])) {
            $codes = array_filter(array_map(function($entry) {
                $parts = explode(':', trim($entry));
                if (count($parts) >= 3 && preg_match('/^\d{1,2}$/', end($parts))) {
                    return $parts[0] . ':' . $parts[1];
                }
                return count($parts) >= 2 ? $parts[0] . ':' . $parts[1] : trim($entry);
            }, explode(';', $row['diagnosis'])));
            $label = !empty($codes) ? implode(', ', $codes) : 'Unspecified diagnosis';
        } else {
            $label = 'Unspecified diagnosis (no codes)';
        }
    }
    $issues[] = [
        'id'           => (int)$row['id'],
        'title'        => $label,
        'diagnosis'    => $row['diagnosis'] ?? '',
        'begdate'      => $row['begdate']   ?? '',
        'activity'     => $row['activity'],
        'verification' => $row['verification'],
    ];
}

echo json_encode($issues);
