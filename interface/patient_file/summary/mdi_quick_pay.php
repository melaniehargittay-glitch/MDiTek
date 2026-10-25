<?php
/**
 * MDItek Quick Patient Payment Endpoint
 * File: interface/patient_file/summary/mdi_quick_pay.php
 *
 * Accepts POST from the Quick Checkout modal and inserts
 * an ar_session + ar_activity row (account_code = 'PP').
 * Returns JSON {ok:true} or {ok:false, error:'...'}.
 */
require_once("../../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

if (empty($_SESSION['authUser'])) {
    echo json_encode(['ok' => false, 'error' => 'Session expired']);
    exit;
}
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'CSRF error']);
    exit;
}

$pid    = (int)($_POST['pid']    ?? 0);
$amount = round((float)($_POST['amount'] ?? 0), 2);
$method = preg_replace('/[^a-z]/', '', strtolower($_POST['method'] ?? 'cash'));
$ref    = trim($_POST['ref'] ?? '');

if (!$pid || $amount <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid pid or amount']);
    exit;
}

// Map to OpenEMR payment_method list values
$method_map = ['cash' => 'cash', 'check' => 'check', 'cc' => 'cc'];
$pay_method = $method_map[$method] ?? 'cash';

// Get most recent encounter
$enc_row = sqlQuery(
    "SELECT encounter FROM form_encounter WHERE pid=? ORDER BY date DESC, encounter DESC LIMIT 1",
    [$pid]
);
$enc_id = (int)($enc_row['encounter'] ?? 0);

// Get first billing row for code info
$bill_row = $enc_id ? sqlQuery(
    "SELECT code_type, code, modifier FROM billing
     WHERE pid=? AND encounter=? AND activity>0 ORDER BY id LIMIT 1",
    [$pid, $enc_id]
) : [];

// Create ar_session
$session_id = sqlInsert(
    "INSERT INTO ar_session SET
     payer_id    = 0,
     patient_id  = ?,
     user_id     = ?,
     closed      = 0,
     reference   = ?,
     check_date  = NOW(),
     deposit_date= NOW(),
     pay_total   = ?,
     payment_type= 'patient',
     description = 'Quick Checkout Payment',
     adjustment_code = '',
     payment_method  = ?,
     post_to_date    = NOW()",
    [
        $pid,
        $_SESSION['authId'] ?? $_SESSION['authUserID'] ?? 1,
        $ref,
        $amount,
        $pay_method,
    ]
);

if (!$session_id) {
    echo json_encode(['ok' => false, 'error' => 'Failed to create payment session']);
    exit;
}

// Get next sequence number
$seq = sqlQuery(
    "SELECT IFNULL(MAX(sequence_no), 0) + 1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
    [$pid, $enc_id ?: 0]
);

// Insert ar_activity with account_code = 'PP' (patient payment)
sqlStatement(
    "INSERT INTO ar_activity SET
     pid          = ?,
     encounter    = ?,
     sequence_no  = ?,
     code_type    = ?,
     code         = ?,
     modifier     = ?,
     payer_type   = 0,
     post_time    = NOW(),
     post_user    = ?,
     session_id   = ?,
     pay_amount   = ?,
     adj_amount   = 0,
     memo         = ?,
     account_code = 'PP'",
    [
        $pid,
        $enc_id ?: 0,
        (int)($seq['n'] ?? 1),
        $bill_row['code_type'] ?? 'CPT4',
        $bill_row['code']      ?? '',
        $bill_row['modifier']  ?? '',
        $_SESSION['authId'] ?? $_SESSION['authUserID'] ?? 1,
        $session_id,
        $amount,
        ucfirst($pay_method) . ($ref ? ' #' . $ref : '') . ' — Quick Checkout',
    ]
);

echo json_encode(['ok' => true, 'session_id' => $session_id]);
