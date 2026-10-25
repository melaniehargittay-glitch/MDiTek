<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once(__DIR__ . "/../../../globals.php");

use OpenEMR\Modules\FaxSMS\Controller\AppDispatch;

// Get SMS service
$clientApp = AppDispatch::getApiService('sms');

if (!$clientApp->verifyAcl()) {
    die("Not Authorized\n");
}

// Get pending messages (limit to avoid overload)
$res = sqlStatement("
    SELECT * FROM notification_log
    WHERE status='pending'
    AND attempts < 3
    ORDER BY iLogId ASC
    LIMIT 10
");

while ($row = sqlFetchArray($res)) {

    $id = $row['iLogId'];
    $phone = extractPhone($row['patient_info']);
    $message = $row['message'];

    try {
        $clientApp->sendSMS(
            $phone,
            "Appointment Reminder",
            $message,
            ''
        );

        // ✅ Success
        sqlStatement("
            UPDATE notification_log 
            SET status='sent',
                last_error=NULL,
                dSentDateTime=NOW()
            WHERE id=?
        ", [$id]);

    } catch (\Throwable $e) {

        // ❌ Failure
        sqlStatement("
            UPDATE notification_log 
            SET status='failed',
                attempts = attempts + 1,
                last_error = ?
            WHERE id=?
        ", [$e->getMessage(), $id]);
    }
}

// Helper
function extractPhone($patient_info)
{
    $parts = explode("|||", $patient_info);
    return $parts[1] ?? '';
}