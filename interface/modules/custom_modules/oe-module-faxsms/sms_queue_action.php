<?php
/**
 * SMS Queue Action Handler — MDItek
 */

ob_start();

$_SERVER['REQUEST_URI'] = $_SERVER['PHP_SELF'];
$_SERVER['SERVER_NAME'] = 'localhost';
$ignoreAuth = 1;

require_once(__DIR__ . "/../../../globals.php");

use OpenEMR\Modules\FaxSMS\Controller\AppDispatch;

// Init clientApp ONCE before session closes — mirrors rc_sms_notification.php
$_sms_clientApp = AppDispatch::getApiService('sms');
$_sms_cred      = [];
if ($_sms_clientApp && $_sms_clientApp->verifyAcl()) {
    $_sms_cred = $_sms_clientApp->getCredentials();
}
session_write_close();

// Phone validation matching rc_sms_notification.php (raw 10-digit, not +1xxx)
function isValidPhone($phone): string|false {
    $d = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strlen($d) === 11) $d = preg_replace('/^1/', '', $d);
    return strlen($d) === 10 ? $d : false;
}

function clean_and_json($data) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    $out = json_encode($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (!$out) { $out = json_encode(['success'=>(bool)($data['success']??false),'error'=>(string)($data['error']??'')]); }
    echo $out;
    exit;
}

register_shutdown_function(function () {
    $buffered = ob_get_level() > 0 ? ob_get_clean() : '';
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>$err['message'],'line'=>$err['line'],'type'=>'shutdown']);
    } elseif ($buffered && strpos(ltrim($buffered), '{') !== 0) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>substr(strip_tags($buffered),0,255),'type'=>'die_output']);
    }
});

set_exception_handler(function ($e) {
    clean_and_json([
        'success' => false,
        'error'   => $e->getMessage(),
        'type'    => 'exception'
    ]);
});

set_error_handler(function ($errno, $errstr, $errfile = null, $errline = null) {
    error_log("SMS Warning [$errno]: $errstr in $errfile:$errline");
    return true; // do NOT interrupt execution
});

function normalizePhone($phone): string {
    $digits = preg_replace('/\D/', '', (string)$phone);

    if (strlen($digits) === 11 && substr($digits, 0, 1) === '1') {
        return '+' . $digits;
    }

    if (strlen($digits) === 10) {
        return '+1' . $digits;
    }

    return '';
}

function getTwilioCredentials(): array {
    $clientApp = AppDispatch::getApiService('sms');

    if (!$clientApp || !$clientApp->verifyAcl()) {
        return [];
    }

    $cred = $clientApp->getCredentials();

    $sid   = trim($cred['username'] ?? '');
    $token = trim($cred['password'] ?? '');

    $fromRaw = $cred['phone'] ?? $cred['smsNumber'] ?? '';
    $from = normalizePhone($fromRaw);

    return [
        'sid'   => $sid,
        'token' => $token,
        'from'  => $from,
    ];
}

function sendViaOpenEMR($toPhone, $message): array {
    global $_sms_clientApp, $_sms_cred;

    if (!$_sms_clientApp || !$_sms_clientApp->verifyAcl()) {
        return ['success' => false, 'error' => 'SMS service not available or ACL failed'];
    }

    $validPhone = isValidPhone($toPhone);
    if (!$validPhone) {
        return ['success' => false, 'error' => 'Invalid phone: ' . $toPhone];
    }

    if (trim($message) === '') {
        return ['success' => false, 'error' => 'Empty message'];
    }

    try {
        ob_start();
        // Mirror rc_sms_notification exactly — pass cred subject/sender params
        $_sms_clientApp->sendSMS(
            $validPhone,
            $_sms_cred['email_subject'] ?? '',
            $message,
            $_sms_cred['email_sender'] ?? ''
        );
        $smsOut = ob_get_clean();
        if ($smsOut && stripos($smsOut, 'error') !== false && stripos($smsOut, 'success') === false) {
            return ['success' => false, 'error' => strip_tags(substr($smsOut, 0, 255))];
        }
        return ['success' => true, 'status' => 'sent'];
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


function saveSendResult($logId, array $result): void {
    sqlStatement(
        "UPDATE notification_log SET attempts = COALESCE(attempts, 0) + 1 WHERE iLogId = ?",
        [$logId]
    );

    if (!empty($result['success'])) {
        sqlStatement(
            "UPDATE notification_log
                SET status = 'sent',
                    dSentDateTime = NOW(),
                    last_error = NULL
              WHERE iLogId = ?",
            [$logId]
        );
    } else {
        sqlStatement(
            "UPDATE notification_log
                SET status = 'failed',
                    last_error = ?
              WHERE iLogId = ?",
            [
                substr($result['error'] ?? 'Unknown send failure', 0, 255),
                $logId
            ]
        );
    }
}

function sendLogRow($row): array {
    $parts = explode('|||', $row['patient_info'] ?? '');
    $phone = trim($parts[1] ?? '');
    $message = trim($row['message'] ?? '');

    if (!$phone) {
        $result = ['success' => false, 'error' => 'No phone number in patient_info'];
        saveSendResult($row['iLogId'], $result);
        return $result;
    }

    if (!$message) {
        $result = ['success' => false, 'error' => 'Empty message'];
        saveSendResult($row['iLogId'], $result);
        return $result;
    }

    $result = sendViaOpenEMR($phone, $message);
    saveSendResult($row['iLogId'], $result);

    return $result;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$id     = (int)($_GET['id'] ?? $_GET['log_id'] ?? $_POST['id'] ?? $_POST['log_id'] ?? 0);

if ($action === 'send' || $action === 'send_now' || $action === 'retry') {
    if (!$id) {
        clean_and_json(['success' => false, 'error' => 'Missing ID']);
    }

    $row = sqlQuery("SELECT * FROM notification_log WHERE iLogId = ?", [$id]);

    if (!$row) {
        clean_and_json(['success' => false, 'error' => 'Notification log row not found']);
    }

    clean_and_json(sendLogRow($row));
}

function sendViaOpenEMRFromEID($eid): array {
    $appt = sqlQuery("
    SELECT e.*,
           pd.pid,
           pd.phone_cell,
           pd.phone_home,
           CONCAT(pd.fname, ' ', pd.lname) AS patient_name,
           CONCAT(u.fname, ' ', u.lname) AS provider_name,
           f.name AS facility_name
    FROM openemr_postcalendar_events e
    LEFT JOIN patient_data pd ON pd.pid = e.pc_pid
    LEFT JOIN users u ON u.id = e.pc_aid
    LEFT JOIN facility f ON f.id = e.pc_facility
    WHERE e.pc_eid = ?
", [$eid]);

    if (!$appt) {
        return ['success' => false, 'error' => 'Appointment not found'];
    }

    $phone = $appt['phone_cell'] ?: $appt['phone_home'];
    if (!$phone) {
        return ['success' => false, 'error' => 'No phone number'];
    }

    $provider = trim($appt['provider_name'] ?? '');
    if ($provider === '') {
        $provider = 'your provider';
    }
    
    $facility = trim($appt['facility_name'] ?? '');
    if ($facility === '') {
        $facility = 'our office';
    }
    
    $timeFormatted = date('g:iA', strtotime($appt['pc_startTime']));

$msg = "This is a courtesy reminder for {$appt['patient_name']} for the appointment scheduled on {$appt['pc_eventDate']} at {$timeFormatted} with {$provider} at {$facility}. Please call if unable to attend.";

    $result = sendViaOpenEMR($phone, $msg);

    if (!empty($result['success'])) {
        sqlStatement(
            "UPDATE openemr_postcalendar_events 
             SET pc_sendalertsms = 'YES' 
             WHERE pc_eid = ?",
            [$eid]
        );
    }

    // ALSO log it (this is critical for consistency)
    $patientInfo = ' ' . $appt['patient_name'] . '|||' . $phone . '|||';

    if (!empty($result['success'])) {
        sqlStatement("
            INSERT INTO notification_log
                (pid, pc_eid, message, patient_info, pc_eventDate, pc_startTime, status, dSentDateTime, attempts)
            VALUES
                (?, ?, ?, ?, ?, ?, 'sent', NOW(), 1)
        ", [
            $appt['pc_pid'],
            $eid,
            $msg,
            $patientInfo,
            $appt['pc_eventDate'],
            $appt['pc_startTime']
        ]);
    } else {
        sqlStatement("
            INSERT INTO notification_log
                (pid, pc_eid, message, patient_info, pc_eventDate, pc_startTime, status, last_error, attempts)
            VALUES
                (?, ?, ?, ?, ?, ?, 'failed', ?, 1)
        ", [
            $appt['pc_pid'],
            $eid,
            $msg,
            $patientInfo,
            $appt['pc_eventDate'],
            $appt['pc_startTime'],
            substr($result['error'] ?? 'Unknown send failure', 0, 255)
        ]);
    }

    return $result;
}

if ($action === 'send_all' || $action === 'send_waiting' || $action === 'send_queue') {

    $sent = 0;
    $failed = 0;
    $errors = [];

    // 1. Send existing log rows
    $res = sqlStatement("
    SELECT *
FROM notification_log
WHERE (
    status IN ('pending','failed')
    OR (
        status = 'processing'
        AND dSentDateTime < NOW() - INTERVAL 10 MINUTE
        AND attempts < 3
    )
)
ORDER BY iLogId ASC
LIMIT 10
");

while ($row = sqlFetchArray($res)) {

    // 🔥 MARK AS PROCESSING FIRST
    $updated = sqlStatement(
        "UPDATE notification_log 
         SET status = 'processing', dSentDateTime = NOW()
         WHERE iLogId = ? 
         AND status IN ('pending','failed')",
        [$row['iLogId']]
    );
    
    // If another process already grabbed it, skip
    if (sqlAffectedRows() === 0) {
        continue;
    }

    $r = sendLogRow($row);

    usleep(200000);

    if (!empty($r['success'])) $sent++;
    else {
        $failed++;
        $errors[] = ['id' => $row['iLogId'], 'error' => $r['error']];
    }
}

    // 2. Appointments in next 24h not yet notified — matches dashboard window
    $now24  = date('Y-m-d H:i:s');
    $plus24 = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $apptRes = sqlStatement("
        SELECT e.pc_eid
        FROM openemr_postcalendar_events e
        LEFT JOIN patient_data pd ON pd.pid = e.pc_pid
        WHERE e.pc_eventDate BETWEEN DATE(?) AND DATE(?)
          AND e.pc_apptstatus != 'x'
          AND (pd.phone_cell != '' OR pd.phone_home != '')
          AND (e.pc_sendalertsms IS NULL OR e.pc_sendalertsms != 'YES')
          AND NOT EXISTS (
              SELECT 1 FROM notification_log nl
              WHERE nl.pc_eid = e.pc_eid
                AND nl.status IN ('sent','processing')
          )
        LIMIT 20",
        [$now24, $plus24]);

    while ($appt = sqlFetchArray($apptRes)) {
        $r = sendViaOpenEMRFromEID($appt['pc_eid']);
        usleep(300000);
        if (!empty($r['success'])) $sent++;
        else { $failed++; $errors[] = ['eid' => $appt['pc_eid'], 'error' => $r['error']]; }
    }

    clean_and_json([
        'success' => true,
        'sent'    => $sent,
        'failed'  => $failed,
        'errors'  => $errors
    ]);
}

if ($action === 'send_appt' || $action === 'send_eid') {
    $eid = (int)($_GET['eid'] ?? 0);

    if (!$eid) {
        clean_and_json(['success' => false, 'error' => 'Missing EID']);
    }

    clean_and_json(sendViaOpenEMRFromEID($eid));
}

if ($action === 'test_credentials') {
    clean_and_json([
        'success' => true,
        'credentials' => getTwilioCredentials()
    ]);
}

clean_and_json(['success' => false, 'error' => 'Unknown action']);