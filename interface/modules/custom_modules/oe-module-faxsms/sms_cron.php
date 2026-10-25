<?php
/**
 * SMS Reminder Cron — MDItek
 * Sends appointment reminders automatically
 */

$ignoreAuth = true;

require_once(__DIR__ . "/../../../globals.php");

use OpenEMR\Modules\FaxSMS\Controller\AppDispatch;

// 🔥 Get SMS service
$clientApp = AppDispatch::getApiService('sms');

if (!$clientApp || !$clientApp->verifyAcl()) {
    error_log("SMS CRON: SMS service not available");
    exit;
}

// ⏰ Set date range (next day appointments)
$start = date('Y-m-d 00:00:00', strtotime('+1 day'));
$end   = date('Y-m-d 23:59:59', strtotime('+1 day'));

// 🔍 Pull appointments
$res = sqlStatement("
    SELECT 
        e.pc_eid,
        e.pc_pid,
        e.pc_eventDate,
        e.pc_startTime,
        pd.fname,
        pd.lname,
        pd.phone_cell,
        pd.phone_home,
        u.fname AS ufname,
        u.lname AS ulname,
        f.name AS facility_name
    FROM openemr_postcalendar_events e
    LEFT JOIN patient_data pd ON pd.pid = e.pc_pid
    LEFT JOIN users u ON u.id = e.pc_aid
    LEFT JOIN facility f ON f.id = e.pc_facility
    WHERE e.pc_eventDate BETWEEN ? AND ?
", [$start, $end]);

$sent = 0;
$failed = 0;

while ($row = sqlFetchArray($res)) {

    // 📞 Get phone
    $phone = $row['phone_cell'] ?: $row['phone_home'];
    if (!$phone) {
        $failed++;
        continue;
    }

    // 📅 Format date/time
    $dt   = strtotime($row['pc_eventDate'] . ' ' . $row['pc_startTime']);
    $date = date('n/j/Y', $dt);
    $time = date('g:i A', $dt);

    // 👤 Names
    $patient  = trim($row['fname'] . ' ' . $row['lname']);
    $provider = trim($row['ufname'] . ' ' . $row['ulname']);
    $facility = $row['facility_name'] ?? 'our office';

    // 💬 Message
    $msg = "This is a courtesy reminder for {$appt['name']} for the appointment scheduled on {$appt['pc_eventDate']} at {$appt['pc_startTime']} with {$provider} at {$facility}. Please call if unable to attend." ;

    // 🚫 Prevent duplicates (already sent)
    $exists = sqlQuery("
        SELECT iLogId FROM notification_log
        WHERE pc_eid = ?
        AND status = 'sent'
        LIMIT 1
    ", [$row['pc_eid']]);

    if ($exists) {
        continue;
    }

    // 📤 Send SMS
    $result = $clientApp->sendSMS($phone, '', $msg, '');

    if ($result) {
        $sent++;

        sqlStatement("
            INSERT INTO notification_log 
            (pid, pc_eid, message, patient_info, pc_eventDate, pc_startTime, status, dSentDateTime)
            VALUES (?, ?, ?, ?, ?, ?, 'sent', NOW())
        ", [
            $row['pc_pid'],
            $row['pc_eid'],
            $msg,
            $patient . '|||' . $phone,
            $row['pc_eventDate'],
            $row['pc_startTime']
        ]);

    } else {
        $failed++;

        sqlStatement("
            INSERT INTO notification_log 
            (pid, pc_eid, message, patient_info, pc_eventDate, pc_startTime, status, last_error)
            VALUES (?, ?, ?, ?, ?, ?, 'failed', 'Send failed')
        ", [
            $row['pc_pid'],
            $row['pc_eid'],
            $msg,
            $patient . '|||' . $phone,
            $row['pc_eventDate'],
            $row['pc_startTime']
        ]);
    }
}

// 🧾 Log summary
error_log("SMS CRON: Sent={$sent}, Failed={$failed}");