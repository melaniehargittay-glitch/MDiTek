<?php
/**
 * MDItek – AJAX endpoint: return upcoming appointments for a patient as JSON.
 * Used by the "All Upcoming Appointments" popup on the patient dashboard.
 *
 * GET params:
 *   pid  – patient ID (required)
 */

require_once("../../globals.php");
require_once(__DIR__ . "/../../../library/appointments.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// ACL check
if (!AclMain::aclCheckCore('patients', 'appt')) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$pid = (int)($_GET['pid'] ?? 0);
if (!$pid) {
    echo json_encode([]);
    exit;
}

header('Content-Type: application/json');

// Fetch next 50 upcoming appointments
$current_date = date('Y-m-d');
$events = fetchNextXAppts($current_date, $pid, 50, true);

$result = [];
if ($events) {
    foreach ($events as $row) {
        $disphour = (int)substr($row['pc_startTime'], 0, 2);
        $dispmin  = substr($row['pc_startTime'], 3, 2);
        $meridiem = '';
        if ($GLOBALS['time_display_format'] == 1) {
            $meridiem = $disphour >= 12 ? ' PM' : ' AM';
            if ($disphour > 12) $disphour -= 12;
            if ($disphour == 0) $disphour = 12;
        }
        $time_str = sprintf("%02d", $disphour) . ':' . $dispmin . $meridiem;

        $result[] = [
            'date'     => $row['pc_eventDate'],
            'time'     => $time_str,
            'type'     => $row['pc_catname'] ?: ($row['pc_title'] ?? xl('Appointment')),
            'provider' => trim(($row['ufname'] ?? '') . ' ' . ($row['ulname'] ?? '')),
            'eid'      => $row['pc_eid'],
        ];
    }
}

echo json_encode($result);
