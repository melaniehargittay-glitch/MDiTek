<?php
ini_set('display_errors',1);
error_reporting(E_ALL);
/**
 * mdi_payments_ledger.php
 *
 * MDItek unified Payments & Ledger panel.
 * Replaces the separate front_payment.php / pat_ledger.php popups with a
 * single iframe-friendly page that handles BOTH insurance and patient payments
 * and shows the full transaction ledger — all wired to the live OpenEMR DB.
 *
 * Drop in:  interface/patient_file/mdi_payments_ledger.php
 * Open via: dlgopen('../patient_file/mdi_payments_ledger.php?pid=<pid>','',1200,800,...)
 *
 * @package   OpenEMR / MDItek
 * @license   GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/payment.inc.php");
require_once("$srcdir/options.inc.php");
require_once("../../custom/code_types.inc.php");

use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\PaymentProcessing\Sphere\SpherePayment;

// ── Access check ────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('acct', 'bill', '', 'write')) {
    die(xlt('Unauthorized'));
}

// ── CSRF ────────────────────────────────────────────────────────────────────
// AJAX action handlers (post_adjustment_ajax, post_refund_ajax, transfer_balance_ajax)
// each verify CSRF themselves and return JSON — skip the global check for those
// so csrfNotVerified() never emits HTML before the JSON header is set.
$_is_ajax_action = !empty($_POST['action']);
if (!empty($_POST) && !$_is_ajax_action) {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
}

// ── One-time correction: normalise any adj_amount rows stored as negative ────
// Skip during AJAX action requests so it never corrupts JSON output.
if (empty($_POST['action']) && empty($_GET['action'])) {
    sqlStatement(
        "UPDATE ar_activity
         SET adj_amount = ABS(adj_amount)
         WHERE adj_amount < 0
           AND deleted IS NULL
           AND account_code IN ('WO','AJ','PP','CM')"
    );
}
// ── End correction ────────────────────────────────────────────────────────────

// ── PID ─────────────────────────────────────────────────────────────────────
$pid = intval(
    $_REQUEST['hidden_patient_code'] ??
    $_REQUEST['pid'] ??
    $pid ??
    0
);

// ── Helpers ─────────────────────────────────────────────────────────────────
function mdi_money($n) {
    return '$' . number_format((float)$n, 2);
}

function mdi_list_title($option_id, $list_id) {
    if (!$option_id) return '';
    $row = sqlQuery(
        "SELECT title FROM list_options WHERE list_id=? AND option_id=? AND activity=1 LIMIT 1",
        [$list_id, $option_id]
    );
    return $row['title'] ?? $option_id;
}



// ── POST: save payment (logic ported verbatim from front_payment.php) ─────────
// ── AJAX: post adjustment (returns JSON, never touches form_save) ─────────────
if (!empty($_POST['action']) && $_POST['action'] === 'post_adjustment_ajax') {
    header('Content-Type: application/json');

    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success' => false, 'error' => xl('Invalid CSRF token.')]);
        exit;
    }

    $adj_pid      = intval($_POST['adj_pid']      ?? $pid);
    $adj_type     = trim($_POST['adj_type']       ?? 'write_off');
    $adj_reason   = trim($_POST['adj_reason']     ?? '');
    $adj_post_as  = trim($_POST['adj_post_as']    ?? 'insurance');
    $adj_billing_id = intval($_POST['adj_billing_id'] ?? 0);
    $adj_amount   = round(floatval($_POST['adj_amount'] ?? 0), 2);

    if (!$adj_billing_id)      { echo json_encode(['success'=>false,'error'=>xl('No billing line selected.')]); exit; }
    if ($adj_amount < 0.005)   { echo json_encode(['success'=>false,'error'=>xl('Adjustment amount must be greater than zero.')]); exit; }
    if (!$adj_reason)          { echo json_encode(['success'=>false,'error'=>xl('Please select a reason code.')]); exit; }

    $adj_payer_type   = ($adj_post_as === 'insurance') ? 1 : 0;
    $adj_account_code = ($adj_type === 'write_off') ? 'WO' : 'AJ';
    $adj_memo         = $adj_reason;
    $adj_desc         = ($adj_type === 'write_off' ? xl('Write-Off') : xl('Adjustment')) . ' [' . $adj_reason . ']';

    $brow = sqlQuery("SELECT code_type,code,modifier,encounter FROM billing WHERE id=? AND pid=? LIMIT 1", [$adj_billing_id, $adj_pid]);
    if (!$brow) { echo json_encode(['success'=>false,'error'=>xl('Billing line not found.')]); exit; }

    $adj_enc = intval($brow['encounter']);

    $ins_row = sqlQuery("SELECT id AS payer_id FROM insurance_data WHERE pid=? AND type='primary' AND provider>0 ORDER BY date DESC LIMIT 1", [$adj_pid]);
    $adj_payer_id = ($adj_post_as === 'insurance' && !empty($ins_row['payer_id'])) ? intval($ins_row['payer_id']) : 0;

    sqlBeginTrans();
    try {
        $session_id = sqlInsert(
            "INSERT INTO ar_session (payer_id,user_id,reference,check_date,deposit_date,pay_total,global_amount,payment_type,description,patient_id,payment_method,adjustment_code,post_to_date,modified_time)
             VALUES (?,?,?,NOW(),NOW(),?,'',?,?,?,?,?,NOW(),NOW())",
            [$adj_payer_id, $_SESSION['authUserID'], $adj_memo, $adj_amount,
            ($adj_post_as==='insurance')
            ? 'insurance_adjustment'
            : 'patient_adjustment',
             $adj_desc, $adj_pid,
             ($adj_post_as==='insurance') ? 'insurance_payment' : 'patient_payment',
             ($adj_type==='write_off') ? 'insurance_adjust' : 'adjustment']
        );
        $seq = sqlQuery("SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?", [$adj_pid, $adj_enc]);
        sqlStatement(
            "INSERT INTO ar_activity (pid,encounter,sequence_no,code_type,code,modifier,payer_type,post_time,post_user,session_id,pay_amount,adj_amount,memo,account_code,follow_up)
             VALUES (?,?,?,?,?,?,?,NOW(),?,?,0,?,?,?,'0')",
            [$adj_pid,$adj_enc,intval($seq['n']),$brow['code_type'],$brow['code'],$brow['modifier']??'',$adj_payer_type,$_SESSION['authUserID'],$session_id,$adj_amount,$adj_memo,$adj_account_code]
        );
        sqlCommitTrans();
        echo json_encode(['success'=>true,'message'=>xl('Adjustment posted successfully.')]);
    } catch (Exception $e) {
        sqlRollbackTrans();
        error_log('MDI adj error: '.$e->getMessage());
        echo json_encode(['success'=>false,'error'=>xl('Failed: ').$e->getMessage()]);
    }
    exit;
}

// ── AJAX: issue refund ────────────────────────────────────────────────────────
if (!empty($_POST['action']) && $_POST['action'] === 'post_refund_ajax') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>xl('Invalid CSRF token.')]); exit;
    }
    $r_pid     = intval($_POST['r_pid']        ?? $pid);
    $r_session = intval($_POST['r_session_id'] ?? 0);
    $r_amount  = round(floatval($_POST['r_amount'] ?? 0), 2);
    $r_reason  = trim($_POST['r_reason'] ?? xl('Refund'));
    if (!$r_session)         { echo json_encode(['success'=>false,'error'=>xl('No payment session selected.')]); exit; }
    if ($r_amount < 0.005)   { echo json_encode(['success'=>false,'error'=>xl('Refund amount must be greater than zero.')]); exit; }
    $sess = sqlQuery(
        "SELECT s.*, COALESCE(SUM(CASE WHEN a.pay_amount < 0 THEN ABS(a.pay_amount) ELSE 0 END),0) AS already_refunded
           FROM ar_session s LEFT JOIN ar_activity a ON a.session_id=s.session_id AND a.deleted IS NULL AND a.pay_amount<0
          WHERE s.session_id=? AND s.patient_id=? GROUP BY s.session_id LIMIT 1",
        [$r_session, $r_pid]
    );
    if (!$sess) { echo json_encode(['success'=>false,'error'=>xl('Payment session not found.')]); exit; }
    $avail = round($sess['pay_total'] - $sess['already_refunded'], 2);
    if ($r_amount > $avail + 0.005) { echo json_encode(['success'=>false,'error'=>xl('Refund exceeds available balance of $').number_format($avail,2).'.']); exit; }
    sqlBeginTrans();
    try {
        $enc_row = sqlQuery("SELECT encounter FROM ar_activity WHERE session_id=? AND encounter>0 LIMIT 1", [$r_session]);
        $enc = $enc_row ? intval($enc_row['encounter']) : 0;
        $seq = sqlQuery("SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?", [$r_pid,$enc]);
        sqlStatement(
            "INSERT INTO ar_activity
                (pid, encounter, sequence_no, code_type, code, modifier,
                 payer_type, post_time, post_user, session_id,
                 pay_amount, adj_amount, memo, account_code, follow_up)
             VALUES (?, ?, ?, '', '', '', 0, NOW(), ?, ?, ?, 0, ?, 'RFND', '0')",
            [$r_pid, $enc, intval($seq['n']), $_SESSION['authUserID'], $r_session, -$r_amount, $r_reason]
        );
        sqlStatement("UPDATE ar_session SET global_amount = global_amount + ? WHERE session_id=?", [$r_amount, $r_session]);
        sqlCommitTrans();
        echo json_encode(['success'=>true,'message'=>xl('Refund of $').number_format($r_amount,2).xl(' posted successfully.')]);
    } catch (Exception $e) {
        sqlRollbackTrans();
        error_log('MDI refund error: '.$e->getMessage());
        echo json_encode(['success'=>false,'error'=>xl('Refund failed: ').$e->getMessage()]);
    }
    exit;
}


// ── AJAX GET: get patient available credit ────────────────────────────────────
if (!empty($_GET['action']) && $_GET['action'] === 'get_patient_credit') {
    header('Content-Type: application/json');
    $credit_pid = intval($_GET['pid'] ?? 0);
    $row = sqlQuery(
        "SELECT COALESCE(SUM(s.pay_total - COALESCE(a_used.used,0) - s.global_amount), 0) AS avail
           FROM ar_session s
           LEFT JOIN (SELECT session_id, SUM(pay_amount) AS used FROM ar_activity WHERE deleted IS NULL GROUP BY session_id) a_used
                ON a_used.session_id = s.session_id
          WHERE s.patient_id=? AND s.pay_total > 0
            AND LOWER(COALESCE(s.payment_method,'')) IN ('patient_payment','cash','check','credit_card','eft','ach')",
        [$credit_pid]
    );
    echo json_encode(['credit' => round((float)($row['avail'] ?? 0), 2)]);
    exit;
}

// ── AJAX GET: search patients for transfer ────────────────────────────────────
if (!empty($_GET['action']) && $_GET['action'] === 'search_patients') {
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['patients'=>[]]); exit; }
    $like = '%' . addslashes($q) . '%';
    $results = [];
    // Search by name or PID
    $sq = sqlStatement(
        "SELECT pid, fname, lname FROM patient_data
          WHERE (CONCAT(fname,' ',lname) LIKE ? OR pid LIKE ?) AND pid != ?
          ORDER BY lname, fname LIMIT 20",
        [$like, $like, intval($_GET['pid'] ?? 0)]
    );
    while ($sr = sqlFetchArray($sq)) {
        $results[] = ['pid' => (int)$sr['pid'], 'name' => $sr['fname'] . ' ' . $sr['lname']];
    }
    echo json_encode(['patients' => $results]);
    exit;
}

// ── AJAX: get encounter billing lines + payments (replaces mdi_adj_lines.php) ─
if (!empty($_GET['action']) && $_GET['action'] === 'get_enc_lines') {
    header('Content-Type: application/json');
    $enc_pid = intval($_GET['pid'] ?? 0);
    $enc_id  = intval($_GET['enc'] ?? 0);
    if (!$enc_pid || !$enc_id) { echo json_encode(['lines'=>[], 'payments'=>[]]); exit; }

    // Billing lines
    $lq = sqlStatement(
        "SELECT b.id AS billing_id, b.code_type, b.code, b.modifier,
                b.code_text AS description, b.fee AS charge,
                COALESCE((SELECT SUM(a.pay_amount) FROM ar_activity a
                   WHERE a.pid=b.pid AND a.encounter=b.encounter
                     AND a.code=b.code AND a.deleted IS NULL
                     AND a.account_code NOT IN ('WO','AJ','CO')),0) AS paid,
                COALESCE((SELECT SUM(ABS(a.adj_amount))+SUM(a.pay_amount)
                   FROM ar_activity a
                   WHERE a.pid=b.pid AND a.encounter=b.encounter
                     AND a.code=b.code AND a.deleted IS NULL
                     AND a.account_code IN ('WO','AJ','CO')),0) AS adj
           FROM billing b
          WHERE b.pid=? AND b.encounter=? AND b.activity=1
          ORDER BY b.id",
        [$enc_pid, $enc_id]
    );
    $lines = [];
    while ($lr = sqlFetchArray($lq)) { $lines[] = $lr; }

    // Payment activities with session data + resolved adjreason title
    $pq = sqlStatement(
        "SELECT a.pid, a.encounter, a.code, a.payer_type, a.post_time,
                a.pay_amount, a.adj_amount, a.memo, a.account_code, a.sequence_no,
                s.payment_method, s.reference, s.payment_type,
                ic.name AS ins_name,
                COALESCE(lo.title, a.memo) AS adj_reason_label
           FROM ar_activity a
           JOIN ar_session s ON s.session_id = a.session_id
           LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
           LEFT JOIN list_options lo ON lo.list_id = 'adjreason'
                AND lo.option_id = a.memo AND lo.activity = 1
          WHERE a.pid=? AND a.encounter=? AND a.deleted IS NULL
          ORDER BY a.post_time DESC",
        [$enc_pid, $enc_id]
    );
    $payments = [];
    while ($pr = sqlFetchArray($pq)) { $payments[] = $pr; }

    echo json_encode(['lines' => $lines, 'payments' => $payments]);
    exit;
}

// ── AJAX: transfer balance to next payer ─────────────────────────────────────
if (!empty($_POST['action']) && $_POST['action'] === 'transfer_balance_ajax') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>xl('Invalid CSRF token.')]); exit;
    }
    $tr_pid  = intval($_POST['tr_pid']  ?? $pid);
    $tr_enc  = intval($_POST['tr_enc']  ?? 0);
    $tr_to   = trim($_POST['tr_to']     ?? '');   // 'secondary','tertiary','patient'
    $tr_amount = round(floatval($_POST['tr_amount'] ?? 0), 2);
    if (!$tr_enc || !$tr_to) { echo json_encode(['success'=>false,'error'=>xl('Missing parameters.')]); exit; }

    // Map destination to last_level_closed value
    $level_map = ['primary'=>1, 'secondary'=>2, 'tertiary'=>3, 'patient'=>4];
    $new_level = $level_map[$tr_to] ?? 0;
    if (!$new_level) { echo json_encode(['success'=>false,'error'=>xl('Invalid transfer destination.')]); exit; }

    $level_labels = ['primary'=>'Primary Insurance','secondary'=>'Secondary Insurance','tertiary'=>'Tertiary Insurance','patient'=>'Patient'];
    $tr_memo = 'Transfer to ' . ($level_labels[$tr_to] ?? $tr_to);

    sqlBeginTrans();
    try {
        // 1. Update encounter billing level
        sqlStatement(
            "UPDATE form_encounter SET last_level_billed=?, last_level_closed=? WHERE pid=? AND encounter=?",
            [$new_level, $new_level - 1, $tr_pid, $tr_enc]
        );

        // 2. Get or create an ar_session to anchor the activity row
        $tr_sess = sqlQuery(
            "SELECT session_id FROM ar_session
              WHERE patient_id=? AND payment_method='transfer' AND reference=?
              LIMIT 1",
            [$tr_pid, $tr_memo]
        );
        if ($tr_sess) {
            $tr_session_id = intval($tr_sess['session_id']);
        } else {
            $tr_session_id = intval(sqlInsert(
                "INSERT INTO ar_session
                    (payer_id, user_id, reference, check_date, deposit_date,
                     pay_total, global_amount, payment_type, description,
                     patient_id, payment_method, post_to_date, modified_time)
                 VALUES (0, ?, ?, CURDATE(), CURDATE(),
                         0, 0, 'transfer', ?,
                         ?, 'transfer', CURDATE(), NOW())",
                [$_SESSION['authUserID'], $tr_memo, $tr_memo, $tr_pid]
            ));
        }

        // 3. Look up the billing code for this encounter to populate code columns
        $tr_brow = sqlQuery(
            "SELECT code_type, code, modifier FROM billing
              WHERE pid=? AND encounter=? AND activity=1
              ORDER BY id LIMIT 1",
            [$tr_pid, $tr_enc]
        );

        // 4. Insert ar_activity row recording the transfer
        $tr_seq = sqlQuery(
            "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
            [$tr_pid, $tr_enc]
        );
        sqlStatement(
          "INSERT INTO ar_activity
              (pid, encounter, sequence_no, code_type, code, modifier,
               payer_type, post_time, post_user, session_id,
               pay_amount, adj_amount, memo, account_code, follow_up)
           VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), ?, ?, 0, ?, ?, 'TRANS', '0')",
          [
              $tr_pid,
              $tr_enc,
              intval($tr_seq['n']),
              $tr_brow['code_type'] ?? '',
              $tr_brow['code'] ?? '',
              $tr_brow['modifier'] ?? '',
              $_SESSION['authUserID'],
              $tr_session_id,
      
              $tr_amount,      // <-- transfer amount goes into adj_amount
              $tr_memo
          ]
      );
        sqlCommitTrans();
        echo json_encode(['success'=>true,'message'=>xl('Balance transferred to ').$tr_to.'.']);
    } catch (\Throwable $e) {
        sqlRollbackTrans();
        error_log('MDI transfer error: '.$e->getMessage());
        echo json_encode(['success'=>false,'error'=>xl('Transfer failed: ').$e->getMessage()]);
    }
    exit;
}


// ── AJAX: transfer credit to another patient or insurance ─────────────────────
if (!empty($_POST['action']) && $_POST['action'] === 'post_transfer_credit_ajax') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>xl('Invalid CSRF token.')]); exit;
    }

    // This is a BALANCE TRANSFER — moving outstanding liability from one payer
    // to another (e.g. insurance→patient, insurance→secondary, patient→insurance).
    // It posts adj_amount entries to ar_activity to reduce the source payer's
    // balance and increase the destination payer's balance on the selected line.
    $tc_pid         = intval($_POST['tc_pid']         ?? $pid);
    $tc_amount      = round(floatval($_POST['tc_amount'] ?? 0), 2);
    $tc_transfer_to = trim($_POST['tc_transfer_to']   ?? 'patient'); // 'patient' or 'insurance'
    $tc_dest_ins_id = intval($_POST['tc_dest_ins_id'] ?? 0);
    $tc_notes       = trim($_POST['tc_notes']         ?? '');
    $tc_billing_id  = intval($_POST['tc_billing_id']  ?? 0);
    $tc_encounter   = intval($_POST['tc_encounter']   ?? 0);

    if ($tc_amount < 0.005) {
        echo json_encode(['success'=>false,'error'=>xl('Transfer amount must be greater than zero.')]); exit;
    }

    // Resolve encounter and billing line
    if (!$tc_encounter && $tc_billing_id) {
        $b_enc = sqlQuery("SELECT encounter FROM billing WHERE id=? LIMIT 1", [$tc_billing_id]);
        $tc_encounter = intval($b_enc['encounter'] ?? 0);
    }
    if (!$tc_encounter) {
        echo json_encode(['success'=>false,'error'=>xl('No encounter selected. Click a line item first.')]); exit;
    }

    // Get current outstanding balance of the selected line to validate amount
    $line_bal_row = sqlQuery(
        "SELECT b.fee,
                COALESCE(SUM(CASE WHEN a.deleted IS NULL THEN a.pay_amount ELSE 0 END),0) AS paid,
                COALESCE(SUM(CASE WHEN a.deleted IS NULL THEN ABS(a.adj_amount) ELSE 0 END),0) AS adj
           FROM billing b
           LEFT JOIN ar_activity a ON a.pid=b.pid AND a.encounter=b.encounter AND a.code=b.code
          WHERE b.id=? AND b.pid=?",
        [$tc_billing_id, $tc_pid]
    );
    $line_balance = $tc_billing_id
        ? round(max(0, floatval($line_bal_row['fee'] ?? 0)
                     - floatval($line_bal_row['paid'] ?? 0)
                     - floatval($line_bal_row['adj']  ?? 0)), 2)
        : 999999; // no line selected — allow (notes-only transfer)

    if ($tc_amount > $line_balance + 0.005) {
        echo json_encode(['success'=>false,'error'=>xl('Transfer amount ($').number_format($tc_amount,2).xl(') exceeds current line balance ($').number_format($line_balance,2).').']); exit;
    }

    // Resolve billing details for ar_activity rows
    $brow = $tc_billing_id
        ? (sqlQuery("SELECT code_type,code,modifier FROM billing WHERE id=? LIMIT 1", [$tc_billing_id]) ?: [])
        : [];
    $code_type = $brow['code_type'] ?? '';
    $code      = $brow['code']      ?? '';
    $modifier  = $brow['modifier']  ?? '';

    // Resolve destination insurance name for memo
    $dest_ins_name = '';
    if ($tc_transfer_to === 'insurance' && $tc_dest_ins_id) {
        $ins_r = sqlQuery("SELECT name FROM insurance_companies WHERE id=?", [$tc_dest_ins_id]);
        $dest_ins_name = $ins_r['name'] ?? ('Insurance #'.$tc_dest_ins_id);
    }

    // memo_transfer built just before sqlBeginTrans below

    // Get next sequence_no
    $seq_r = sqlQuery(
        "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
        [$tc_pid, $tc_encounter]
    );
    $seq_no = intval($seq_r['n'] ?? 1);

    // Get or create a system session for adjustments
    $sys_sess = sqlQuery(
        "SELECT session_id FROM ar_session
          WHERE patient_id=? AND payment_type='adjustment' AND payment_method='transfer'
          ORDER BY check_date DESC LIMIT 1",
        [$tc_pid]
    );
    if (!$sys_sess) {
        $sys_sess_id = sqlInsert(
            "INSERT INTO ar_session (payer_id,user_id,reference,check_date,deposit_date,pay_total,
              global_amount,payment_type,description,patient_id,payment_method,post_to_date,modified_time)
             VALUES (0,?,?,CURDATE(),CURDATE(),0,0,'adjustment','Balance transfer session',?,'transfer',CURDATE(),NOW())",
            [$_SESSION['authUserID'], 'TRANSFER-BAL-PID-'.$tc_pid, $tc_pid]
        );
    } else {
        $sys_sess_id = intval($sys_sess['session_id']);
    }

    // Build memo
    $dest_label = ($tc_transfer_to === 'insurance') ? $dest_ins_name : xl('Patient');
    $memo_transfer = 'Transfer to ' . $dest_label . ($tc_notes ? ': ' . $tc_notes : '');

    sqlBeginTrans();
    try {
        // Single ar_activity row: payer_type = SOURCE (the side we move FROM).
        // adj_amount = positive amount reduces that payer's outstanding balance.
        // "To Patient"   → source = insurance (payer_type=1), adj reduces ins_bal
        // "To Insurance" → source = patient   (payer_type=0), adj reduces pat_bal
        $src_payer_type = ($tc_transfer_to === 'insurance') ? 0 : 1;

        sqlStatement(
            "INSERT INTO ar_activity
               (pid,encounter,sequence_no,code_type,code,modifier,payer_type,
                post_time,post_user,session_id,pay_amount,adj_amount,memo,account_code,follow_up)
             VALUES (?,?,?,?,?,?,?,NOW(),?,?,0,?,?,'TRANS','0')",
            [$tc_pid, $tc_encounter, $seq_no, $code_type, $code, $modifier,
             $src_payer_type, $_SESSION['authUserID'], $sys_sess_id,
             $tc_amount,   // positive adj_amount reduces source-payer balance
             $memo_transfer]
        );

        sqlCommitTrans();
        echo json_encode([
            'success'       => true,
            'message'       => xl('Balance of $').number_format($tc_amount,2)
                             . xl(' transferred to ')
                             . ($tc_transfer_to === 'insurance' ? text($dest_ins_name) : xl('Patient'))
                             . '.',
            'line_balance'  => $line_balance,
            'new_balance'   => round(max(0, $line_balance - $tc_amount), 2),
        ]);
    } catch (\Throwable $e) {
        sqlRollbackTrans();
        error_log('MDI balance_transfer error: '.$e->getMessage());
        echo json_encode(['success'=>false,'error'=>xl('Transfer failed: ').$e->getMessage()]);
    }
    exit;
}


// ── AJAX: delete ar_activity row (composite key: pid+encounter+sequence_no) ───
if (!empty($_POST['action']) && $_POST['action'] === 'delete_activity_ajax') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>xl('Invalid CSRF token.')]); exit;
    }
    // ar_activity has no id column — use composite primary key
    $del_pid      = intval($_POST['del_pid']      ?? $pid);
    $del_enc      = intval($_POST['del_enc']      ?? 0);
    $del_seq      = intval($_POST['del_seq']      ?? 0);

    if (!$del_enc || !$del_seq) {
        echo json_encode(['success'=>false,'error'=>xl('Missing row key.')]); exit;
    }

    // Confirm the row belongs to this patient and get session info
    $del_row = sqlQuery(
        "SELECT aa.session_id, aa.account_code, ars.payment_method
           FROM ar_activity aa
           JOIN ar_session ars ON ars.session_id = aa.session_id
          WHERE aa.pid=? AND aa.encounter=? AND aa.sequence_no=?
            AND aa.deleted IS NULL LIMIT 1",
        [$del_pid, $del_enc, $del_seq]
    );
    if (!$del_row) {
        echo json_encode(['success'=>false,'error'=>xl('Row not found or already deleted.')]); exit;
    }

    sqlBeginTrans();
    try {
        sqlStatement(
            "UPDATE ar_activity SET deleted=NOW(), modified_time=NOW()
              WHERE pid=? AND encounter=? AND sequence_no=?",
            [$del_pid, $del_enc, $del_seq]
        );

        // Clean up transfer session if it has no remaining rows
        if (strtoupper($del_row['account_code']) === 'TRANS' && strtolower($del_row['payment_method']) === 'transfer') {
            $remaining = sqlQuery(
                "SELECT COUNT(*) AS n FROM ar_activity WHERE session_id=? AND deleted IS NULL",
                [$del_row['session_id']]
            );
            if (intval($remaining['n'] ?? 0) === 0) {
                sqlStatement("DELETE FROM ar_session WHERE session_id=?", [$del_row['session_id']]);
            }
        }

        sqlCommitTrans();
        echo json_encode(['success'=>true,'message'=>xl('Transaction deleted.')]);
    } catch (\Throwable $e) {
        sqlRollbackTrans();
        error_log('MDI delete_activity error: '.$e->getMessage());
        echo json_encode(['success'=>false,'error'=>xl('Delete failed: ').$e->getMessage()]);
    }
    exit;
}

// ── AJAX: save MBO (modifier + DX codes) ──────────────────────────────────────
if (!empty($_POST['action']) && $_POST['action'] === 'save_mbo_ajax') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>xl('Invalid CSRF token.')]); exit;
    }
    $mbo_pid      = intval($_POST['mbo_pid']      ?? $pid);
    $mbo_enc      = intval($_POST['mbo_enc']       ?? 0);
    $mbo_billing  = intval($_POST['mbo_billing_id'] ?? 0);
    $mbo_modifier = trim($_POST['mbo_modifier']    ?? '');
    $mbo_dx_raw   = trim($_POST['mbo_dx_codes']    ?? '');   // colon-separated

    if (!$mbo_billing || !$mbo_pid) {
        echo json_encode(['success'=>false,'error'=>xl('Missing billing ID.')]); exit;
    }
    // Verify billing row belongs to this patient
    $mbo_check = sqlQuery(
        "SELECT id FROM billing WHERE id=? AND pid=? AND activity=1 LIMIT 1",
        [$mbo_billing, $mbo_pid]
    );
    if (!$mbo_check) {
        echo json_encode(['success'=>false,'error'=>xl('Billing row not found.')]); exit;
    }

    $set  = ['modifier = ?', 'justify = ?'];
    $vals = [$mbo_modifier, $mbo_dx_raw, $mbo_billing, $mbo_pid];
    sqlStatement(
        "UPDATE billing SET modifier=?, justify=? WHERE id=? AND pid=?",
        $vals
    );
    echo json_encode(['success'=>true,'message'=>xl('Saved successfully.')]);
    exit;
}
// ── End MBO save handler ───────────────────────────────────────────────────────

// ── AJAX: post EOB (Insurance EOB manual posting) ────────────────────────────
if (!empty($_POST['action']) && $_POST['action'] === 'post_eob_ajax') {
    header('Content-Type: application/json');

    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>xl('Invalid CSRF token.')]);
        exit;
    }

    $eob_pid      = intval($_POST['eob_pid'] ?? $pid);
    $eob_amount   = round(floatval($_POST['eob_amount'] ?? 0), 2);
    $eob_method   = trim($_POST['eob_method'] ?? 'eft');
    $eob_ref      = trim($_POST['eob_ref'] ?? '');
    $eob_deposit  = trim($_POST['eob_deposit_date'] ?? '');
    $eob_nonce    = trim($_POST['eob_nonce'] ?? '');
    $eob_claim    = trim($_POST['eob_claim_number'] ?? '');
    $eob_trace    = trim($_POST['eob_trace'] ?? '');
    $eob_adj_reason = trim($_POST['eob_adj_reason'] ?? '');
    $eob_memo     = substr(trim($_POST['eob_memo'] ?? ''), 0, 120);
    // Default deposit date to today if not provided or invalid
    if (!$eob_deposit || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eob_deposit)) {
        $eob_deposit = date('Y-m-d');
    }
    $eob_lines    = $_POST['eob_lines'] ?? [];

    // $0 is valid for a denial (insurance processes the claim but pays nothing).
    // Require either a non-zero amount OR at least one line with an adjustment/PR.
    $has_adj_lines = false;
    foreach ($eob_lines as $line) {
        if (round(floatval($line['adjustment'] ?? 0), 2) > 0 ||
            round(floatval($line['patient_resp'] ?? 0), 2) > 0) {
            $has_adj_lines = true;
            break;
        }
    }
    if ($eob_amount < 0.005 && empty($eob_lines)) {
        echo json_encode([
            'success'=>false,
            'error'=>xl('At least one line is required.')
        ]);
        exit;
    }
    if ($eob_amount < 0.005 && !$has_adj_lines) {
        echo json_encode([
            'success'=>false,
            'error'=>xl('For a $0 payment (denial), please enter an adjustment or patient responsibility amount on at least one line.')
        ]);
        exit;
    }

    // --------------------------------------------------
    // Prevent duplicate EOB posting
    // 1. Reference-based dedup (if ref provided)
    // 2. Time-window dedup: same pid+amount+method within 30s
    // 3. Nonce-based dedup: same one-time token
    // --------------------------------------------------
    if ($eob_ref !== '') {
        $dup = sqlQuery(
            "SELECT session_id FROM ar_session
              WHERE patient_id = ? AND reference = ? AND payment_type = 'insurance'
              LIMIT 1",
            [$eob_pid, $eob_ref]
        );
        if (!empty($dup['session_id'])) {
            echo json_encode(['success'=>false,'error'=>xl('An EOB with this reference number has already been posted.')]);
            exit;
        }
    }

    // Time-window dedup: block identical submission within 30 seconds.
    // Skip for $0 denials — a patient can have multiple encounters denied in sequence.
    if ($eob_amount >= 0.005) {
        $recent_dup = sqlQuery(
            "SELECT session_id FROM ar_session
              WHERE patient_id = ? AND pay_total = ? AND payment_method = ?
                AND payment_type = 'insurance'
                AND modified_time >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
              LIMIT 1",
            [$eob_pid, $eob_amount, $eob_method]
        );
        if (!empty($recent_dup['session_id'])) {
            echo json_encode(['success'=>false,'error'=>xl('This EOB appears to have just been posted (duplicate detected within 30 seconds). Refresh the page to verify.')]);
            exit;
        }
    }

    // Nonce-based dedup: store nonce in session to catch double-clicks
    if ($eob_nonce !== '') {
        $nonce_key = 'eob_nonce_' . $eob_pid;
        if (!empty($_SESSION[$nonce_key]) && $_SESSION[$nonce_key] === $eob_nonce) {
            echo json_encode(['success'=>false,'error'=>xl('Duplicate submission detected. The EOB was already posted.')]);
            exit;
        }
        $_SESSION[$nonce_key] = $eob_nonce;
    }

    // Validate lines
    $total_paid = 0;
    $total_adj  = 0;
   

foreach ($eob_lines as $line) {
    $total_paid += round(floatval($line['paid'] ?? 0), 2);
    $total_adj  += round(floatval($line['adjustment'] ?? 0), 2);
}

    // Look up primary insurance payer
    $ins_row = sqlQuery(
        "SELECT id AS payer_id FROM insurance_data WHERE pid=? AND type='primary' AND provider>0 ORDER BY date DESC LIMIT 1",
        [$eob_pid]
    );
    $eob_payer_id = !empty($ins_row['payer_id']) ? intval($ins_row['payer_id']) : 0;

    sqlBeginTrans();
    try {
        // Build reference string: prefer claim # or check #, then trace
        $eob_session_ref = $eob_ref ?: ($eob_claim ?: 'EOB');
        // Build description with all metadata
        $eob_desc_parts = ['EOB Manual Post'];
        if ($eob_ref)        $eob_desc_parts[] = 'Check:'  . $eob_ref;
        if ($eob_claim)      $eob_desc_parts[] = 'Claim:'  . $eob_claim;
        if ($eob_trace)      $eob_desc_parts[] = 'Trace:'  . $eob_trace;
        if ($eob_adj_reason) $eob_desc_parts[] = 'AdjRsn:' . $eob_adj_reason;
        if ($eob_memo)       $eob_desc_parts[] = $eob_memo;
        $eob_description = substr(implode(' | ', $eob_desc_parts), 0, 255);

        // Create one ar_session for the whole EOB
        $session_id = sqlInsert(
            "INSERT INTO ar_session
                (payer_id, user_id, reference, check_date, deposit_date,
                 pay_total, global_amount, payment_type, description,
                 patient_id, payment_method, adjustment_code, post_to_date, modified_time)
             VALUES (?, ?, ?, ?, ?, ?, '', 'insurance', ?, ?, ?, 'insurance_payment', NOW(), NOW())",
            [$eob_payer_id, $_SESSION['authUserID'], $eob_session_ref,
             $eob_deposit, $eob_deposit,
             $eob_amount,
             $eob_description, $eob_pid, $eob_method]
        );

        foreach ($eob_lines as $line) {
            $enc        = intval($line['enc']         ?? 0);
            $billing_id = intval($line['billing_id']  ?? 0);
            $paid_amt   = round(floatval($line['paid']         ?? 0), 2);
            $adj_amt    = round(floatval($line['adjustment']   ?? 0), 2);
            $pr_amt     = round(floatval($line['patient_resp'] ?? 0), 2);

            if (!$enc || !$billing_id) continue;

            $brow = sqlQuery(
                "SELECT code_type, code, modifier, encounter FROM billing WHERE id=? AND pid=? LIMIT 1",
                [$billing_id, $eob_pid]
            );
            if (!$brow) continue;

            // 1. Insurance payment line
            if ($paid_amt > 0) {
                $seq = sqlQuery("SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?", [$eob_pid, $enc]);
                sqlStatement(
                    "INSERT INTO ar_activity
                        (pid, encounter, sequence_no, code_type, code, modifier,
                         payer_type, post_time, post_user, session_id,
                         pay_amount, adj_amount, memo, account_code, follow_up)
                     VALUES (?,?,?,?,?,?, 1, NOW(), ?, ?, ?, 0, 'INS1', 'ins1', '0')",
                    [$eob_pid, $enc, intval($seq['n']),
                     $brow['code_type'], $brow['code'], $brow['modifier'] ?? '',
                     $_SESSION['authUserID'], $session_id, $paid_amt]
                );
            }

            // 2. Contractual adjustment line
            if ($adj_amt > 0) {
                $line_adj_reason = trim($line['adj_reason'] ?? '') ?: $eob_adj_reason ?: 'CONTRACT_ADJUSTMENT';
                $seq = sqlQuery("SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?", [$eob_pid, $enc]);
                sqlStatement(
                    "INSERT INTO ar_activity
                        (pid, encounter, sequence_no, code_type, code, modifier,
                         payer_type, post_time, post_user, session_id,
                         pay_amount, adj_amount, memo, account_code, follow_up)
                     VALUES (?,?,?,?,?,?, 1, NOW(), ?, ?, 0, ?, ?, 'WO', '0')",
                    [$eob_pid, $enc, intval($seq['n']),
                     $brow['code_type'], $brow['code'], $brow['modifier'] ?? '',
                     $_SESSION['authUserID'], $session_id, $adj_amt,
                     $line_adj_reason]
                );
            }
          // 3. Patient responsibility transfer (insurance -> patient)
          if ($pr_amt > 0) {

            $seq = sqlQuery(
                "SELECT IFNULL(MAX(sequence_no),0)+1 AS n
                  FROM ar_activity
                  WHERE pid=? AND encounter=?",
                [$eob_pid, $enc]
            );

            sqlStatement(
                "INSERT INTO ar_activity
                    (pid, encounter, sequence_no, code_type, code, modifier,
                    payer_type, post_time, post_user, session_id,
                    pay_amount, adj_amount, memo, account_code, follow_up)
                VALUES (?,?,?,?,?,?, 1, NOW(), ?, ?, 0, ?, 'Transfer to Patient', 'TRANS', '0')",
                [
                    $eob_pid,
                    $enc,
                    intval($seq['n']),
                    $brow['code_type'],
                    $brow['code'],
                    $brow['modifier'] ?? '',
                    $_SESSION['authUserID'],
                    $session_id,
                    $pr_amt
                ]
            );
          }
        }
        sqlCommitTrans();
        echo json_encode(['success'=>true, 'message'=>xl('EOB posted successfully. Session ID: ').$session_id]);
    } catch (\Throwable $e) {
        sqlRollbackTrans();
        error_log('MDI EOB post error: '.$e->getMessage());
        echo json_encode(['success'=>false,'error'=>xl('Failed to post EOB: ').$e->getMessage()]);
    }
    exit;
}
// ── End EOB AJAX ──────────────────────────────────────────────────────────────

// ── AJAX: void (reverse) a posted EOB session ────────────────────────────────
if (!empty($_POST['action']) && $_POST['action'] === 'void_eob_ajax') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>xl('Invalid CSRF token.')]); exit;
    }
    $void_session_id = intval($_POST['void_session_id'] ?? 0);
    $void_pid        = intval($_POST['void_pid'] ?? $pid);
    if (!$void_session_id) {
        echo json_encode(['success'=>false,'error'=>xl('No session ID provided.')]); exit;
    }
    // Verify the session belongs to this patient
    $sess_row = sqlQuery(
        "SELECT session_id, pay_total, payment_type FROM ar_session
          WHERE session_id=? AND patient_id=? LIMIT 1",
        [$void_session_id, $void_pid]
    );
    if (!$sess_row) {
        echo json_encode(['success'=>false,'error'=>xl('Session not found or does not belong to this patient.')]); exit;
    }
    sqlBeginTrans();
    try {
        // Soft-delete all ar_activity rows for this session
        sqlStatement(
            "UPDATE ar_activity SET deleted=NOW(), modified_time=NOW()
              WHERE session_id=? AND deleted IS NULL",
            [$void_session_id]
        );
        // Remove the ar_session record
        sqlStatement("DELETE FROM ar_session WHERE session_id=?", [$void_session_id]);
        sqlCommitTrans();
        echo json_encode(['success'=>true,'message'=>xl('EOB session voided successfully.')]);
    } catch (\Throwable $e) {
        sqlRollbackTrans();
        error_log('MDI void EOB error: '.$e->getMessage());
        echo json_encode(['success'=>false,'error'=>xl('Failed to void EOB: ').$e->getMessage()]);
    }
    exit;
}
// ── End void EOB AJAX ─────────────────────────────────────────────────────────

$save_msg = '';
$save_err = '';

// ============================================
// ADJUSTMENT HANDLER (post_adjustment_hidden)
// ============================================

if (!empty($_POST['post_adjustment_hidden'])) {

    $adj_pid     = intval($_POST['adj_pid']     ?? $pid);
    $adj_type    = trim($_POST['adj_type']      ?? 'write_off');
    $adj_reason  = trim($_POST['adj_reason']    ?? '');
    $adj_post_as = trim($_POST['adj_post_as']   ?? 'insurance');
    $adj_lines   = $_POST['adj_lines']          ?? [];   // [billing_id => ['amount'=>.., 'reason'=>..]]

    // ── Also support single-line submission via adj_billing_id + adj_amount ──
    $single_bid = intval($_POST['adj_billing_id'] ?? 0);
    $single_amt = round(floatval($_POST['adj_amount'] ?? 0), 2);
    if ($single_bid > 0 && $single_amt > 0.005 && empty($adj_lines)) {
        $adj_lines[$single_bid] = ['amount' => $single_amt, 'reason' => $adj_reason];
    }

    if (empty($adj_lines)) {
        $save_err = xl('No line items selected for adjustment.');
    } elseif (empty($adj_reason)) {
        $save_err = xl('Please select a reason code.');
    } else {

        $adj_payer_type   = ($adj_post_as === 'insurance') ? 1 : 0;
        $adj_account_code = ($adj_type === 'write_off') ? 'WO' : 'AJ';
        $adj_memo         = $adj_reason ?: ($adj_type === 'write_off' ? 'Write-Off' : 'Adjustment');
        $adj_desc         = ($adj_type === 'write_off') ? xl('Write-Off') : xl('Adjustment');
        if ($adj_reason) { $adj_desc .= ' [' . $adj_reason . ']'; }

        // Total for ar_session pay_total
        $total_adj = 0;
        foreach ($adj_lines as $lineData) {
            $total_adj += round(floatval($lineData['amount'] ?? 0), 2);
        }

        if ($total_adj < 0.005) {
            $save_err = xl('Adjustment amount must be greater than zero.');
        } else {

            // Lookup primary insurance payer once
            $adj_ins_row = sqlQuery(
                "SELECT id AS payer_id FROM insurance_data
                  WHERE pid=? AND type='primary' AND provider>0
                  ORDER BY date DESC LIMIT 1",
                [$adj_pid]
            );
            $adj_payer_id = ($adj_post_as === 'insurance' && !empty($adj_ins_row['payer_id']))
                ? intval($adj_ins_row['payer_id']) : 0;

            sqlBeginTrans();
            try {

                // One ar_session for the whole batch
                $session_id = sqlInsert(
                    "INSERT INTO ar_session
                        (payer_id, user_id, reference, check_date, deposit_date,
                         pay_total, global_amount, payment_type, description,
                         patient_id, payment_method, adjustment_code, post_to_date, modified_time)
                     VALUES (?, ?, ?, NOW(), NOW(), ?, '', ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $adj_payer_id,
                        $_SESSION['authUserID'],
                        $adj_memo,
                        $total_adj,
                        ($adj_post_as === 'insurance') ? 'insurance_adjustment' : 'patient_adjustment',
                        $adj_desc,
                        $adj_pid,
                        ($adj_post_as === 'insurance') ? 'insurance_adjustment' : 'patient_adjustment',
                        ($adj_type === 'write_off') ? 'insurance_adjust' : 'adjustment',
                    ]
                );

                // One ar_activity per billing line
                foreach ($adj_lines as $bid => $lineData) {
                    $line_amt    = round(floatval($lineData['amount'] ?? 0), 2);
                    $line_reason = trim($lineData['reason'] ?? $adj_reason);
                    if ($line_amt < 0.005) { continue; }

                    $brow = sqlQuery(
                        "SELECT code_type, code, modifier, encounter
                           FROM billing WHERE id=? AND pid=? LIMIT 1",
                        [intval($bid), $adj_pid]
                    );
                    if (!$brow) { continue; }

                    $adj_enc = intval($brow['encounter']);
                    $seq = sqlQuery(
                        "SELECT IFNULL(MAX(sequence_no),0)+1 AS n
                           FROM ar_activity WHERE pid=? AND encounter=?",
                        [$adj_pid, $adj_enc]
                    );

                    sqlStatement(
                        "INSERT INTO ar_activity
                            (pid, encounter, sequence_no, code_type, code, modifier,
                             payer_type, post_time, post_user, session_id,
                             pay_amount, adj_amount, memo, account_code, follow_up)
                         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 0, ?, ?, ?, '0')",
                        [
                            $adj_pid, $adj_enc, intval($seq['n']),
                            $brow['code_type'], $brow['code'], $brow['modifier'] ?? '',
                            $adj_payer_type,
                            $_SESSION['authUserID'],
                            $session_id,
                            $line_amt,
                            $line_reason ?: $adj_memo,
                            $adj_account_code,
                        ]
                    );
                }

                sqlCommitTrans();
                $save_msg = xl('Adjustment posted successfully.');

            } catch (Exception $e) {
                sqlRollbackTrans();
                $save_err = xl('Failed to post adjustment: ') . $e->getMessage();
                error_log('MDI Adjustment error: ' . $e->getMessage());
            }
        }
    }
}

function safe($row, $key, $default = 0) {
    return isset($row[$key]) ? $row[$key] : $default;
}



if (!empty($_POST['form_save']) && empty($_POST['post_adjustment_hidden'])) {

    $form_pid    = $_POST['form_pid'];
    $form_method = trim($_POST['form_method']);
    $form_source = trim($_POST['form_source'] ?? '');
    $form_coverage = trim($_POST['radio_type_of_coverage'] ?? 'patient');
    $timestamp   = date('Y-m-d H:i:s');

    // (iPOSpays payment gateway removed)

    $patdata = getPatientData($form_pid, 'fname,mname,lname,pubpid');
    $NameNew = $patdata['fname'] . " " . $patdata['lname'] . " " . $patdata['mname'];

    // Look up the patient's primary insurance payer_id
    $ins_row = sqlQuery(
        "SELECT id AS payer_id, provider FROM insurance_data WHERE pid = ? AND type = 'primary' ORDER BY date DESC LIMIT 1",
        array($form_pid)
    );
    $ins_payer_id = (!empty($ins_row['payer_id'])) ? $ins_row['payer_id'] : 0;
    $is_insurance = ($form_coverage === 'insurance' && $ins_payer_id > 0);

    // Derive $encounter from first form_upay key that has a value
    $encounter = 0;
    if (!empty($_POST['form_upay'])) {
        foreach ($_POST['form_upay'] as $enc_key => $enc_val) {
            if (floatval($enc_val) != 0 && intval($enc_key) > 0) {
                $encounter = intval($enc_key);
                break;
            }
        }
    }

    // Update invoice_refno
    if ($encounter > 0) {
        $invoice_refno = BillingUtilities::updateInvoiceRefNumber();
        sqlStatement(
            "update form_encounter set invoice_refno=? where encounter=? and pid=?",
            array($invoice_refno, $encounter, $form_pid)
        );
    }

    // ── Pre-payment ──────────────────────────────────────────────────────────
    if ($_REQUEST['radio_type_of_payment'] == 'pre_payment') {
        $payment_id = sqlInsert(
            "insert into ar_session set " .
            "payer_id = ?" .
            ", patient_id = ?" .
            ", user_id = ?" .
            ", closed = ?" .
            ", reference = ?" .
            ", check_date = now() , deposit_date = now() " .
            ", pay_total = ?" .
            ", payment_type = 'patient'" .
            ", description = ?" .
            ", adjustment_code = 'pre_payment'" .
            ", post_to_date = now() " .
            ", payment_method = ?",
            array(0, $form_pid, $_SESSION['authUserID'], 0, $form_source, $_REQUEST['form_prepayment'], $NameNew, $form_method)
        );
        frontPayment($form_pid, 0, $form_method, $form_source, $_REQUEST['form_prepayment'], 0, $timestamp);
        $save_msg = xl('Pre-payment posted successfully.');
    }

    // ── Encounter payments ───────────────────────────────────────────────────
    if ($_POST['form_upay'] && $_REQUEST['radio_type_of_payment'] != 'pre_payment') {
        foreach ($_POST['form_upay'] as $enc => $payment) {
            $payment = floatval($payment);
            if ($amount = $payment) {

                if ($_REQUEST['radio_type_of_payment'] == 'invoice_balance') {
                    if (!$enc) { $enc = calendar_arrived($form_pid); }
                } else {
                    if (!$enc) { $enc = calendar_arrived($form_pid); }
                }

                // Fetch billing code + modifier (ct_fee=1 first, fallback to billing direct)
                $ResultSearchNew = sqlStatement(
                    "SELECT * FROM billing LEFT JOIN code_types ON billing.code_type=code_types.ct_key " .
                    "WHERE code_types.ct_fee=1 AND billing.activity!=0 AND billing.pid =? AND encounter=? ORDER BY billing.code,billing.modifier",
                    array($form_pid, $enc)
                );
                if ($RowSearch = sqlFetchArray($ResultSearchNew)) {
                    $Codetype = $RowSearch['code_type'];
                    $Code     = $RowSearch['code'];
                    $Modifier = $RowSearch['modifier'] ?? '';
                } else {
                    $ResultSearchFallback = sqlStatement(
                        "SELECT code_type, code, modifier FROM billing " .
                        "WHERE activity != 0 AND pid = ? AND encounter = ? AND fee != 0 " .
                        "ORDER BY code, modifier LIMIT 1",
                        array($form_pid, $enc)
                    );
                    if ($RowFallback = sqlFetchArray($ResultSearchFallback)) {
                        $Codetype = $RowFallback['code_type'];
                        $Code     = $RowFallback['code'];
                        $Modifier = $RowFallback['modifier'] ?? '';
                    } else {
                        $Codetype = 'COPAY';
                        $Code     = 'PAYMENT';
                        $Modifier = '';
                    }
                }

                // ── Co-Pay ───────────────────────────────────────────────────
                if ($_REQUEST['radio_type_of_payment'] == 'copay') {
                    $session_id = sqlInsert(
                        "INSERT INTO ar_session (payer_id,user_id,reference,check_date,deposit_date,pay_total," .
                        " global_amount,payment_type,description,patient_id,payment_method,adjustment_code,post_to_date) " .
                        " VALUES (?,?,?,now(),now(),?,'',?,?,?,?,'patient_payment',now())",
                        array(
                            $is_insurance ? $ins_payer_id : 0,
                            $_SESSION['authUserID'],
                            $form_source,
                            $amount,
                            $is_insurance ? 'insurance' : 'patient',
                            $is_insurance ? $NameNew : 'COPAY',
                            $form_pid,
                            $form_method
                        )
                    );
                    sqlBeginTrans();
                    $sequence_no = sqlQuery("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?", array($form_pid, $enc));
                    sqlInsert(
                        "INSERT INTO ar_activity (pid,encounter,sequence_no,code_type,code,modifier,payer_type,post_time,post_user,session_id,pay_amount,account_code)" .
                        " VALUES (?,?,?,?,?,?,?,now(),?,?,?,'PCP')",
                        array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier,
                              $is_insurance ? 1 : 0,
                              $_SESSION['authUserID'], $session_id, $amount)
                    );
                    sqlCommitTrans();
                    frontPayment($form_pid, $enc, $form_method, $form_source, $amount, 0, $timestamp);
                }

                // ── Invoice Balance ──────────────────────────────────────────
                if ($_REQUEST['radio_type_of_payment'] == 'invoice_balance' || $_REQUEST['radio_type_of_payment'] == 'cash') {
                    if ($_REQUEST['radio_type_of_payment'] == 'cash') {
                        sqlStatement("update form_encounter set last_level_closed=? where encounter=? and pid=?", array(4, $enc, $form_pid));
                        sqlStatement("update billing set billed=? where encounter=? and pid=?", array(1, $enc, $form_pid));
                    }

                    $adjustment_code = $is_insurance ? 'insurance_payment' : 'patient_payment';
                    $payment_id = sqlInsert(
                        "insert into ar_session set " .
                        "payer_id = ?" .
                        ", patient_id = ?" .
                        ", user_id = ?" .
                        ", closed = ?" .
                        ", reference = ?" .
                        ", check_date = now() , deposit_date = now() " .
                        ", pay_total = ?" .
                        ", payment_type = ?" .
                        ", description = ?" .
                        ", adjustment_code = ?" .
                        ", post_to_date = now() " .
                        ", payment_method = ?",
                        array(
                            $is_insurance ? $ins_payer_id : 0,
                            $form_pid,
                            $_SESSION['authUserID'],
                            0,
                            $form_source,
                            $amount,
                            $is_insurance ? 'insurance' : 'patient',
                            $NameNew,
                            $adjustment_code,
                            $form_method
                        )
                    );

                    frontPayment($form_pid, $enc, $form_method, $form_source, 0, $amount, $timestamp);

                    $resMoneyGot = sqlStatement(
                        "SELECT sum(pay_amount) as PatientPay FROM ar_activity where pid =? and " .
                        "encounter = ? and payer_type = 0 and account_code = 'PCP' AND deleted IS NULL",
                        array($form_pid, $enc)
                    );
                    $rowMoneyGot = sqlFetchArray($resMoneyGot);
                    $Copay = safe($rowMoneyGot, 'PatientPay');

                    // Re-fetch billing rows to distribute payment
                    $ResultSearchNew = sqlStatement(
                        "SELECT * FROM billing LEFT JOIN code_types ON billing.code_type=code_types.ct_key WHERE code_types.ct_fee=1 " .
                        "AND billing.activity!=0 AND billing.pid =? AND encounter=? ORDER BY billing.code,billing.modifier",
                        array($form_pid, $enc)
                    );
                    $firstFeeRow = sqlFetchArray($ResultSearchNew);
                    if (!$firstFeeRow) {
                        $ResultSearchNew = sqlStatement(
                            "SELECT b.* FROM billing AS b " .
                            "WHERE b.activity != 0 AND b.pid = ? AND b.encounter = ? AND b.fee != 0 " .
                            "ORDER BY b.code, b.modifier",
                            array($form_pid, $enc)
                        );
                    } else {
                        $ResultSearchNew = sqlStatement(
                            "SELECT * FROM billing LEFT JOIN code_types ON billing.code_type=code_types.ct_key WHERE code_types.ct_fee=1 " .
                            "AND billing.activity!=0 AND billing.pid =? AND encounter=? ORDER BY billing.code,billing.modifier",
                            array($form_pid, $enc)
                        );
                    }

                    while ($RowSearch = sqlFetchArray($ResultSearchNew)) {
                        $Codetype = $RowSearch['code_type'];
                        $Code     = $RowSearch['code'];
                        $Modifier = $RowSearch['modifier'] ?? '';
                        $Fee      = $RowSearch['fee'];

                        $resMoneyGot = sqlStatement(
                            "SELECT sum(pay_amount) as MoneyGot FROM ar_activity where pid = ? AND deleted IS NULL " .
                            "and code_type=? and code=? and modifier=? and encounter =? and !(payer_type=0 and account_code='PCP')",
                            array($form_pid, $Codetype, $Code, $Modifier, $enc)
                        );
                        $rowMoneyGot    = sqlFetchArray($resMoneyGot);
                        $MoneyGot       = safe($rowMoneyGot, 'MoneyGot');

                        $resMoneyAdjusted = sqlStatement(
                            "SELECT sum(ABS(adj_amount)) as MoneyAdjusted FROM ar_activity where " .
                            "pid = ? and code_type = ? and code = ? and modifier = ? and encounter = ? AND deleted IS NULL",
                            array($form_pid, $Codetype, $Code, $Modifier, $enc)
                        );
                        $rowMoneyAdjusted = sqlFetchArray($resMoneyAdjusted);
                        $MoneyAdjusted    = safe($rowMoneyAdjusted, 'MoneyAdjusted');

                        $Remainder = $Fee - $Copay - $MoneyGot - $MoneyAdjusted;
                        $Copay = 0;

                        if (round($Remainder, 2) != 0 && $amount != 0) {
                            if ($amount - $Remainder >= 0) {
                                $insert_value = $Remainder;
                                $amount       = $amount - $Remainder;
                            } else {
                                $insert_value = $amount;
                                $amount       = 0;
                            }
                            sqlBeginTrans();
                            $sequence_no = sqlQuery("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?", array($form_pid, $enc));
                            sqlStatement(
                                "insert into ar_activity set " .
                                "pid = ?, encounter = ?, sequence_no = ?, code_type = ?, code = ?, modifier = ?, " .
                                "payer_type = ?, post_time = now(), post_user = ?, session_id = ?, " .
                                "pay_amount = ?, adj_amount = ?, account_code = 'PP'",
                                array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier,
                                      $is_insurance ? 1 : 0,
                                      $_SESSION['authUserID'], $payment_id, $insert_value, 0)
                            );
                            sqlCommitTrans();
                        }
                    } // while billing rows

                    // Post any remaining excess
                    if ($amount != 0) {
                        sqlBeginTrans();
                        $sequence_no = sqlQuery("SELECT IFNULL(MAX(sequence_no),0) + 1 AS increment FROM ar_activity WHERE pid = ? AND encounter = ?", array($form_pid, $enc));
                        sqlStatement(
                            "insert into ar_activity set " .
                            "pid = ?, encounter = ?, sequence_no = ?, code_type = ?, code = ?, modifier = ?, " .
                            "payer_type = ?, post_time = now(), post_user = ?, session_id = ?, " .
                            "pay_amount = ?, adj_amount = ?, account_code = 'PP'",
                            array($form_pid, $enc, $sequence_no['increment'], $Codetype, $Code, $Modifier,
                                  $is_insurance ? 1 : 0,
                                  $_SESSION['authUserID'], $payment_id, $amount, 0)
                        );
                        sqlCommitTrans();
                    }
                } // invoice_balance / cash
            } // if amount
        } // foreach form_upay
    $save_msg = xl('Payment posted successfully.');
    } // if form_upay

    // ── Post adjustments (form_adj) ──────────────────────────────────────────
    if (!empty($_POST['form_adj'])) {
        foreach ($_POST['form_adj'] as $adj_enc => $adj_val) {
            $adj_enc = intval($adj_enc);
            $adj_amt = round(floatval($adj_val), 2);
            if ($adj_amt <= 0 || !$adj_enc) continue;

            $adj_reason = trim($_POST['form_adj_reason'][$adj_enc] ?? 'Write-Off');

            // Fetch billing code
            $adj_brow = sqlQuery(
                "SELECT code_type,code,modifier FROM billing
                  WHERE activity!=0 AND pid=? AND encounter=? AND fee!=0
                  ORDER BY code,modifier LIMIT 1",
                [$form_pid, $adj_enc]
            );
            $adj_code_type = $adj_brow['code_type'] ?? 'CPT4';
            $adj_code      = $adj_brow['code']      ?? '00';
            $adj_modifier  = $adj_brow['modifier']  ?? '';

            // Get/create adj session
            $adj_sess = sqlQuery(
                "SELECT session_id FROM ar_session
                  WHERE patient_id=? AND payment_type='patient' AND payment_method='adjustment'
                  ORDER BY check_date DESC LIMIT 1",
                [$form_pid]
            );
            if (!$adj_sess) {
                $adj_sess_id = sqlInsert(
                    "INSERT INTO ar_session (payer_id,user_id,reference,check_date,deposit_date,pay_total,
                      global_amount,payment_type,description,patient_id,payment_method,post_to_date,modified_time)
                     VALUES (0,?,?,CURDATE(),CURDATE(),0,0,'patient',?,?,'adjustment',CURDATE(),NOW())",
                    [$_SESSION['authUserID'],
                     'PT-ADJ-' . $form_pid,
                     xl('Patient Adjustment') . ' - ' . $adj_reason,
                     $form_pid]
                );
            } else {
                $adj_sess_id = intval($adj_sess['session_id']);
            }

            $adj_seq = sqlQuery(
                "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
                [$form_pid, $adj_enc]
            );
            sqlBeginTrans();
            sqlStatement(
                "INSERT INTO ar_activity
                   (pid,encounter,sequence_no,code_type,code,modifier,payer_type,
                    post_time,post_user,session_id,pay_amount,adj_amount,memo,account_code,follow_up)
                 VALUES (?,?,?,?,?,?,0,NOW(),?,?,0,?,?,'WO','0')",
                [$form_pid, $adj_enc, intval($adj_seq['n']),
                 $adj_code_type, $adj_code, $adj_modifier,
                 $_SESSION['authUserID'], $adj_sess_id,
                 $adj_amt, $adj_reason]
            );
            sqlCommitTrans();
        }
    }

    // ── Post transfers to insurance (form_xfer checkboxes) ───────────────────
    if (!empty($_POST['form_xfer'])) {
        foreach ($_POST['form_xfer'] as $xfer_enc => $xfer_checked) {
            if (!$xfer_checked) continue;
            $xfer_enc = intval($xfer_enc);
            if (!$xfer_enc) continue;

            // Transfer amount = adj for this enc if set, else pt_remaining from adj input
            $xfer_amt = round(floatval($_POST['form_adj'][$xfer_enc] ?? 0), 2);
            if ($xfer_amt <= 0) {
                // Fall back to full patient balance
                $bal_r = sqlQuery(
                    "SELECT GREATEST(0, b.fee
                        - COALESCE(SUM(CASE WHEN a.deleted IS NULL THEN a.pay_amount ELSE 0 END),0)
                        - COALESCE(SUM(CASE WHEN a.deleted IS NULL THEN ABS(a.adj_amount) ELSE 0 END),0)
                     ) AS bal
                       FROM billing b
                       LEFT JOIN ar_activity a ON a.pid=b.pid AND a.encounter=b.encounter
                      WHERE b.pid=? AND b.encounter=? AND b.activity=1",
                    [$form_pid, $xfer_enc]
                );
                $xfer_amt = round(floatval($bal_r['bal'] ?? 0), 2);
            }
            if ($xfer_amt <= 0) continue;

            $xfer_brow = sqlQuery(
                "SELECT code_type,code,modifier FROM billing
                  WHERE activity!=0 AND pid=? AND encounter=? AND fee!=0
                  ORDER BY code,modifier LIMIT 1",
                [$form_pid, $xfer_enc]
            );
            $xfer_code_type = $xfer_brow['code_type'] ?? 'CPT4';
            $xfer_code      = $xfer_brow['code']      ?? '00';
            $xfer_modifier  = $xfer_brow['modifier']  ?? '';

            // Reuse/create transfer session
            $xfer_sess = sqlQuery(
                "SELECT session_id FROM ar_session
                  WHERE patient_id=? AND payment_type='patient' AND payment_method='transfer'
                  ORDER BY check_date DESC LIMIT 1",
                [$form_pid]
            );
            if (!$xfer_sess) {
                $xfer_sess_id = sqlInsert(
                    "INSERT INTO ar_session (payer_id,user_id,reference,check_date,deposit_date,pay_total,
                      global_amount,payment_type,description,patient_id,payment_method,post_to_date,modified_time)
                     VALUES (0,?,?,CURDATE(),CURDATE(),0,0,'patient',?,?,'transfer',CURDATE(),NOW())",
                    [$_SESSION['authUserID'],
                     'PT-XFER-INS-' . $form_pid,
                     xl('Transfer to Insurance') . ' PID#' . $form_pid,
                     $form_pid]
                );
            } else {
                $xfer_sess_id = intval($xfer_sess['session_id']);
            }

            $xfer_seq = sqlQuery(
                "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
                [$form_pid, $xfer_enc]
            );
            sqlBeginTrans();
            sqlStatement(
                "INSERT INTO ar_activity
                   (pid,encounter,sequence_no,code_type,code,modifier,payer_type,
                    post_time,post_user,session_id,pay_amount,adj_amount,memo,account_code,follow_up)
                 VALUES (?,?,?,?,?,?,0,NOW(),?,?,0,?,?,'TRANS','0')",
                [$form_pid, $xfer_enc, intval($xfer_seq['n']),
                 $xfer_code_type, $xfer_code, $xfer_modifier,
                 $_SESSION['authUserID'], $xfer_sess_id,
                 $xfer_amt,
                 xl('Transfer to Primary Insurance')]
            );
            sqlCommitTrans();
        }
    }

} // if form_save

// ── POST: auto-apply unapplied credits ──────────────────────────────────────
if (!empty($_POST['form_auto_apply_credits'])) {
    $aa_pid      = intval($_POST['form_pid'] ?? $pid);
    $aa_session  = intval($_POST['aa_session_id'] ?? 0);
    $aa_amount   = round(floatval($_POST['aa_credit_avail'] ?? 0), 2);
    $timestamp   = date('Y-m-d H:i:s');

    if ($aa_pid && $aa_session && $aa_amount > 0) {
        // Get all open patient-due encounters oldest-first
        $open_encs = sqlStatement(
            "SELECT fe.encounter, fe.date,
                    COALESCE(SUM(b.fee),0) AS charges
             FROM form_encounter fe
             LEFT JOIN billing b ON b.pid=fe.pid AND b.encounter=fe.encounter
                                 AND b.activity>0 AND b.fee!=0
             WHERE fe.pid=?
             GROUP BY fe.encounter, fe.date
             HAVING charges > 0
             ORDER BY fe.date ASC",
            [$aa_pid]
        );

        $remaining = $aa_amount;
        while ($oe = sqlFetchArray($open_encs)) {
            if ($remaining <= 0) break;
            $enc = $oe['encounter'];

            // Compute patient-due for this encounter
            $tot = sqlQuery(
                "SELECT COALESCE(SUM(fee),0) AS t FROM billing WHERE pid=? AND encounter=? AND activity=1",
                [$aa_pid, $enc]
            );
            $paid = sqlQuery(
                "SELECT COALESCE(SUM(pay_amount),0) AS p, COALESCE(SUM(ABS(adj_amount)),0) AS a
                 FROM ar_activity WHERE deleted IS NULL AND pid=? AND encounter=?",
                [$aa_pid, $enc]
            );
            $due = round($tot['t'] - $paid['p'] - $paid['a'], 2);
            if ($due <= 0) continue;

            $apply = min($remaining, $due);
            $remaining = round($remaining - $apply, 2);

            // Get first billing code for this encounter
            $brow = sqlQuery(
                "SELECT code_type, code, modifier FROM billing
                 WHERE pid=? AND encounter=? AND activity!=0 AND fee!=0
                 ORDER BY code, modifier LIMIT 1",
                [$aa_pid, $enc]
            );
            $Codetype = $brow['code_type'] ?? 'COPAY';
            $Code     = $brow['code']      ?? 'PAYMENT';
            $Modifier = $brow['modifier']  ?? '';

            $seq = sqlQuery(
                "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
                [$aa_pid, $enc]
            );
            sqlStatement(
                "INSERT INTO ar_activity
                    (pid,encounter,sequence_no,code_type,code,modifier,payer_type,
                     post_time,post_user,session_id,pay_amount,adj_amount,account_code,post_date)
                 VALUES (?,?,?,?,?,?,0,?,?,?,?,0,'PP',CURDATE())",
                [$aa_pid, $enc, $seq['n'], $Codetype, $Code, $Modifier,
                 $timestamp, $_SESSION['authUserID'], $aa_session, $apply]
            );
        }

        // If still remaining, it stays as unapplied credit
        $save_msg = xl('Credits applied successfully.');
    }
}
// ── End auto-apply handler ────────────────────────────────────────────────────

// ── POST UNAPPLIED CREDIT (Search Payments panel) ────────────────────────────
if (!empty($_POST['spr_apply_unapplied']) && !empty($_POST['spr_session_id'])) {
    if (CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        $su_sid = intval($_POST['spr_session_id']);
        $su_enc = intval($_POST['spr_enc']    ?? 0);
        $su_amt = round(floatval($_POST['spr_amount'] ?? 0), 2);

        if ($su_sid > 0 && $su_enc > 0 && $su_amt > 0.004) {
            $su_sess = sqlQuery(
                "SELECT s.session_id, s.pay_total, s.global_amount,
                        COALESCE(SUM(a.pay_amount),0) AS applied
                   FROM ar_session s
                   LEFT JOIN ar_activity a ON a.session_id=s.session_id AND a.deleted IS NULL
                  WHERE s.session_id=? AND s.patient_id=?
                  GROUP BY s.session_id LIMIT 1",
                [$su_sid, $pid]
            );
            if ($su_sess) {
                $su_avail = round($su_sess['pay_total'] - $su_sess['applied'] - $su_sess['global_amount'], 2);
                $su_apply = min($su_amt, max(0, $su_avail));
                if ($su_apply > 0.004) {
                    $su_brow = sqlQuery(
                        "SELECT code_type, code, modifier FROM billing
                          WHERE pid=? AND encounter=? AND activity!=0 AND fee!=0
                          ORDER BY code, modifier LIMIT 1",
                        [$pid, $su_enc]
                    );
                    $su_seq = sqlQuery(
                        "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
                        [$pid, $su_enc]
                    );
                    sqlBeginTrans();
                    sqlStatement(
                        "INSERT INTO ar_activity
                            (pid, encounter, sequence_no, code_type, code, modifier,
                             payer_type, post_time, post_user, session_id,
                             pay_amount, adj_amount, account_code, follow_up)
                         VALUES (?,?,?,?,?,?,0,NOW(),?,?,?,0,'PP','0')",
                        [$pid, $su_enc, intval($su_seq['n']),
                         $su_brow['code_type'] ?? 'COPAY',
                         $su_brow['code']      ?? 'PAYMENT',
                         $su_brow['modifier']  ?? '',
                         $_SESSION['authUserID'], $su_sid, $su_apply]
                    );
                    sqlCommitTrans();
                }
            }
        }
    }
    header("Location: mdi_payments_ledger.php?pid=" . urlencode($pid) . "&tab=search");
    exit;
}
// ── End post unapplied credit handler ─────────────────────────────────────────

// ── SAVE SESSION handler (inline edit in Search Payments) ────────────────────
if (!empty($_POST['save_session']) && !empty($_POST['edit_session_id'])) {
    if (CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        $upd_sid    = intval($_POST['edit_session_id']);
        $upd_date   = trim($_POST['edit_check_date']   ?? '');
        $upd_dep    = trim($_POST['edit_deposit_date'] ?? '');
        $upd_method = trim($_POST['edit_payment_method'] ?? '');
        $upd_ref    = trim($_POST['edit_reference']    ?? '');
        $upd_total  = round(floatval($_POST['edit_pay_total'] ?? 0), 2);
        $upd_type   = trim($_POST['edit_payment_type'] ?? '');

        // Validate the session belongs to this patient
        $chk = sqlQuery("SELECT session_id FROM ar_session WHERE session_id=? AND patient_id=? LIMIT 1", [$upd_sid, $pid]);
        if ($chk) {
            $set  = [];
            $vals = [];
            if ($upd_date)   { $set[] = "check_date=?";      $vals[] = $upd_date; }
            if ($upd_dep)    { $set[] = "deposit_date=?";    $vals[] = $upd_dep; }
            if ($upd_method) { $set[] = "payment_method=?";  $vals[] = $upd_method; }
            $set[]  = "reference=?";    $vals[] = $upd_ref;
            $set[]  = "pay_total=?";    $vals[] = $upd_total;
            if ($upd_type) {
                $set[] = "payment_type=?"; $vals[] = $upd_type;
            }
            $set[]  = "modified_time=NOW()";
            $vals[] = $upd_sid;
            sqlStatement("UPDATE ar_session SET " . implode(', ', $set) . " WHERE session_id=?", $vals);
        }
    }
    // Redirect back to search tab to refresh
    header("Location: mdi_payments_ledger.php?pid=" . urlencode($pid) . "&tab=search");
    exit;
}
// ── End save session handler ──────────────────────────────────────────────────

// ── DELETE payment handler ────────────────────────────────────────────────────
if (!empty($_POST['delete_payment'])) {
    if (CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        $del_session = intval($_POST['delete_session_id'] ?? 0);
        if ($del_session > 0) {
            // Verify session belongs to this patient
            $del_chk = sqlQuery("SELECT session_id FROM ar_session WHERE session_id=? AND patient_id=? LIMIT 1", [$del_session, $pid]);
            if ($del_chk) {
                sqlStatement(
                    "UPDATE ar_activity SET deleted = NOW(), modified_time = NOW()
                      WHERE session_id = ? AND deleted IS NULL",
                    [$del_session]
                );
                sqlStatement("DELETE FROM ar_session WHERE session_id = ?", [$del_session]);
            }
        }
    }
    header("Location: mdi_payments_ledger.php?pid=" . urlencode($pid) . "&tab=search");
    exit;
}
// ── End delete handler ────────────────────────────────────────────────────────

// ── AJAX: edit payment detail (from ledger detail panel) ─────────────────────
if (!empty($_POST['action']) && $_POST['action'] === 'edit_payment_detail_ajax') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success' => false, 'error' => xl('Invalid CSRF token.')]); exit;
    }
    $epd_pid       = intval($_POST['epd_pid']        ?? $pid);
    $epd_session   = intval($_POST['epd_session_id'] ?? 0);
    $epd_total     = round(floatval($_POST['epd_pay_total']    ?? 0), 2);
    $epd_applied   = round(floatval($_POST['epd_applied']      ?? 0), 2);
    $epd_ref       = trim($_POST['epd_reference']    ?? '');
    $epd_check_date = trim($_POST['epd_check_date']  ?? '');
    $epd_method    = trim($_POST['epd_method']       ?? '');

    if (!$epd_session) { echo json_encode(['success'=>false,'error'=>xl('No payment session specified.')]); exit; }

    // Verify session belongs to this patient
    $epd_sess = sqlQuery(
        "SELECT session_id, pay_total FROM ar_session WHERE session_id=? AND patient_id=? LIMIT 1",
        [$epd_session, $epd_pid]
    );
    if (!$epd_sess) { echo json_encode(['success'=>false,'error'=>xl('Payment session not found.')]); exit; }

    // Build update
    $epd_set  = ['pay_total=?', 'reference=?', 'modified_time=NOW()'];
    $epd_vals = [$epd_total, $epd_ref];
    if ($epd_check_date) { $epd_set[] = 'check_date=?';    $epd_vals[] = $epd_check_date; }
    if ($epd_method)     { $epd_set[] = 'payment_method=?'; $epd_vals[] = $epd_method; }
    $epd_vals[] = $epd_session;

    sqlBeginTrans();
    try {
        sqlStatement("UPDATE ar_session SET " . implode(', ', $epd_set) . " WHERE session_id=?", $epd_vals);

        // If applied amount changed, update the matching ar_activity pay_amount for patient payments
        if ($epd_applied > 0) {
            // Update the most recent non-deleted patient pay_amount row for this session
            sqlStatement(
                "UPDATE ar_activity SET pay_amount=?, modified_time=NOW()
                  WHERE session_id=? AND deleted IS NULL AND pay_amount > 0
                  ORDER BY sequence_no DESC LIMIT 1",
                [$epd_applied, $epd_session]
            );
        }

        sqlCommitTrans();
        echo json_encode(['success'=>true,'message'=>xl('Payment updated successfully.'),'new_total'=>$epd_total,'new_applied'=>$epd_applied,'new_ref'=>$epd_ref,'new_check_date'=>$epd_check_date]);
    } catch (Exception $e) {
        sqlRollbackTrans();
        echo json_encode(['success'=>false,'error'=>xl('Failed: ').$e->getMessage()]);
    }
    exit;
}
// ── End edit payment detail handler ──────────────────────────────────────────

// ── Adjustments / Write-Offs handler ─────────────────────────────────────────
if (!empty($_POST['post_adjustment'])) {

  $adj_pid     = intval($_POST['adj_pid'] ?? $pid);
  $adj_enc     = intval($_POST['adj_encounter'] ?? 0);

  $adj_type    = trim($_POST['adj_type'] ?? 'write_off');
  $adj_reason  = trim($_POST['adj_reason'] ?? '');
  $adj_note    = trim($_POST['adj_note'] ?? '');

  $adj_post_as = trim($_POST['adj_post_as'] ?? 'insurance');

  // NEW SINGLE-LINE MODEL
  $adj_billing_id = intval($_POST['adj_billing_id'] ?? 0);
  $adj_amount     = round(floatval($_POST['adj_amount'] ?? 0), 2);

  // Validation
  if ($adj_billing_id <= 0) {
      $save_err = xl('Please select an encounter line.');
  }

  elseif ($adj_amount < 0.005) {
      $save_err = xl('Please enter an adjustment amount.');
  }

  else {

      // Native accounting conventions
      $adj_payer_type  = ($adj_post_as === 'insurance') ? 1 : 0;
      $adj_account_code = ($adj_type === 'write_off')
          ? 'WO'
          : 'AJ';

      $adj_memo = $adj_reason
          ?: ($adj_type === 'write_off'
              ? 'Write-Off'
              : 'Adjustment');

      $adj_desc = ($adj_type === 'write_off')
          ? xl('Write-Off')
          : xl('Adjustment');

      if ($adj_reason) {
          $adj_desc .= ' [' . $adj_reason . ']';
      }

      if ($adj_note) {
          $adj_desc .= ': ' . substr($adj_note, 0, 60);
      }

      // Lookup billing line
      $brow = sqlQuery(
          "SELECT
              code_type,
              code,
              modifier,
              encounter
           FROM billing
           WHERE id=? AND pid=?
           LIMIT 1",
          [
              $adj_billing_id,
              $adj_pid
          ]
      );

      if (!$brow) {

          $save_err = xl('Selected billing line not found.');

      } else {

          $adj_enc = intval($brow['encounter']);

          // Lookup primary insurance payer
          $adj_ins_row = sqlQuery(
              "SELECT id AS payer_id
               FROM insurance_data
               WHERE pid=?
                 AND type='primary'
                 AND provider > 0
               ORDER BY date DESC
               LIMIT 1",
              [$adj_pid]
          );

          $adj_payer_id =
              ($adj_post_as === 'insurance'
                  && !empty($adj_ins_row['payer_id']))
              ? intval($adj_ins_row['payer_id'])
              : 0;

          sqlBeginTrans();

          try {

              // Create ar_session
              $session_id = sqlInsert(
                  "INSERT INTO ar_session
                      (
                          payer_id,
                          user_id,
                          reference,
                          check_date,
                          deposit_date,
                          pay_total,
                          global_amount,
                          payment_type,
                          description,
                          patient_id,
                          payment_method,
                          adjustment_code,
                          post_to_date
                      )
                   VALUES
                      (
                          ?,
                          ?,
                          ?,
                          now(),
                          now(),
                          ?,
                          '',
                          ?,
                          ?,
                          ?,
                          ?,
                          ?,
                          now()
                      )",
                  [
                      $adj_payer_id,
                      $_SESSION['authUserID'],
                      $adj_memo,
                      $adj_amount,

                      $adj_post_as === 'insurance'
                          ? 'insurance'
                          : 'patient',

                      $adj_desc,

                      $adj_pid,

                      $adj_post_as === 'insurance'
                          ? 'insurance_adjustment'
                          : 'patient_adjustment',

                          $adj_type === 'write_off'
                          ? 'writeoff'
                          : 'adjustment',

                          
                  ]
              );

              // Next sequence
              $seq = sqlQuery(
                  "SELECT
                      IFNULL(MAX(sequence_no),0)+1 AS n
                   FROM ar_activity
                   WHERE pid=?
                     AND encounter=?",
                  [
                      $adj_pid,
                      $adj_enc
                  ]
              );

// Insert adjustment activity
sqlStatement(
  "INSERT INTO ar_activity
      (
          pid,
          encounter,
          sequence_no,
          code_type,
          code,
          modifier,
          payer_type,
          post_time,
          post_user,
          session_id,
          pay_amount,
          adj_amount,
          memo,
          account_code
      )
   VALUES
      (
          ?,
          ?,
          ?,
          ?,
          ?,
          ?,
          ?,
          now(),
          ?,
          ?,
          0,
          ?,
          ?,
          ?
      )",
  [
      $adj_pid,
      $adj_enc,

      intval($seq['n']),

      $brow['code_type'],
      $brow['code'],
      $brow['modifier'] ?? '',

      $adj_payer_type,

      $_SESSION['authUserID'],

      $session_id,

      // THIS NOW MAPS TO adj_amount
      $adj_amount,

      $adj_memo,

      $adj_account_code,
  ]
);          

              sqlCommitTrans();

              $save_msg = xl('Adjustment posted successfully.');

          } catch (Exception $e) {

              sqlRollbackTrans();

              $save_err =
        'Failed to post adjustment: '
        . $e->getMessage();

    error_log($e->getMessage());
          }
      }
  }
}
// ── End adjustments handler ─────────────────────────────────────────────────

// ── Search Payments handler ───────────────────────────────────────────────────
$search_results = [];
$search_ran     = true; // Always load results for this patient
{
    $s_from      = !empty($_POST['s_from'])   ? DateToYYYYMMDD(trim($_POST['s_from']))   : '';
    $s_to        = !empty($_POST['s_to'])     ? DateToYYYYMMDD(trim($_POST['s_to']))     : '';
    $s_method    = trim($_POST['s_method']    ?? '');
    $s_ref       = trim($_POST['s_ref']       ?? '');
    $s_amount    = trim($_POST['s_amount']    ?? '');
    $s_entity    = trim($_POST['s_entity']    ?? '');
    $s_status    = trim($_POST['s_status']    ?? '');

    $sq = "SELECT s.*, ic.name AS ins_name, pd.fname, pd.lname
           FROM ar_session s
           LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
           LEFT JOIN patient_data pd ON pd.pid = s.patient_id
           WHERE s.patient_id = ?
             AND LOWER(COALESCE(s.payment_method,'')) NOT IN (
                 'patient_adjustment', 'insurance_adjustment'
             )";
    $sb = [$pid];

    if ($s_from)   { $sq .= " AND s.check_date >= ?";    $sb[] = $s_from; }
    if ($s_to)     { $sq .= " AND s.check_date <= ?";    $sb[] = $s_to; }
    if ($s_method) { $sq .= " AND s.payment_method = ?"; $sb[] = $s_method; }
    if ($s_ref)    { $sq .= " AND s.reference LIKE ?";   $sb[] = '%' . $s_ref . '%'; }
    if ($s_amount) { $sq .= " AND s.pay_total = ?";      $sb[] = $s_amount; }

    // Insurance vs patient: use payment_type as authoritative field
    if ($s_entity === 'insurance') {
        $sq .= " AND LOWER(COALESCE(s.payment_type,'')) LIKE 'insurance%'";
    }
    if ($s_entity === 'patient') {
        $sq .= " AND LOWER(COALESCE(s.payment_type,'')) NOT LIKE 'insurance%'";
    }

    if ($s_status) {
        $rs = sqlStatement(
            "SELECT s2.session_id FROM ar_session s2
             LEFT JOIN ar_activity a ON a.session_id=s2.session_id AND a.deleted IS NULL
             WHERE s2.patient_id=?
             GROUP BY s2.session_id
             HAVING s2.pay_total - s2.global_amount - COALESCE(SUM(a.pay_amount),0) <= 0",
            [$pid]
        );
        $paid_ids = '';
        while ($r = sqlFetchArray($rs)) { $paid_ids .= ($paid_ids ? ',' : '') . intval($r['session_id']); }
        if (!$paid_ids) $paid_ids = '0';
        if ($s_status === 'fully_paid') $sq .= " AND s.session_id IN ($paid_ids)";
        if ($s_status === 'unapplied')  $sq .= " AND s.session_id NOT IN ($paid_ids)";
    }

    $sq .= " ORDER BY s.check_date DESC, s.session_id DESC LIMIT 500";
    $sr = sqlStatement($sq, $sb);
    while ($row = sqlFetchArray($sr)) {
        $udrow = sqlQuery(
            "SELECT COALESCE(SUM(pay_amount),0) AS applied FROM ar_activity
              WHERE deleted IS NULL AND session_id=?",
            [$row['session_id']]
        );
        $row['undistributed'] = $row['pay_total'] - ($udrow['applied'] ?? 0) - $row['global_amount'];
        $search_results[] = $row;
    }
}

// ── Load patient data ────────────────────────────────────────────────────────
$patrow = $pid ? sqlQuery(
    "SELECT p.fname,p.lname,p.mname,p.DOB,p.pubpid,i.copay
     FROM patient_data p
     LEFT JOIN insurance_data i ON i.pid=p.pid AND i.type='primary'
     WHERE p.pid=? ORDER BY i.date DESC LIMIT 1",
    [$pid]
) : [];

$pat_name  = trim(($patrow['fname'] ?? '') . ' ' . ($patrow['lname'] ?? ''));
$pat_dob   = $patrow['DOB'] ?? '';
$pat_pubid = $patrow['pubpid'] ?? '';

// Primary insurance
$ins_primary = sqlQuery(
    "SELECT ic.name FROM insurance_data id LEFT JOIN insurance_companies ic ON ic.id=id.provider
     WHERE id.pid=? AND id.type='primary' ORDER BY id.date DESC LIMIT 1",
    [$pid]
);
$ins_name = $ins_primary['name'] ?? xl('None');

// ── Ledger data ──────────────────────────────────────────────────────────────
$active_tab   = $_GET['tab'] ?? 'ledger';
// Always default to ledger on fresh open — only switch to payment if enc= param present
if ($active_tab === 'payment' && empty($_GET['enc'])) {
    $active_tab = 'ledger';
}
// Always load ledger data so the Transaction Ledger tab is ready on load
$load_ledger  = true;
$preselect_enc = intval($_GET['enc'] ?? 0);  // enc param: pre-open this encounter in EOB

$ledger_rows = [];

if ($load_ledger) {
$from_date = ($_GET['from'] ?? date('Y-m-d', strtotime('-2 years'))) . ' 00:00:00';
$to_date   = ($_GET['to']   ?? date('Y-m-d')) . ' 23:59:59';

$total_chg = $total_pmt = $total_adj = $total_ins_bal = $total_pat_bal = 0;
$total_ins_pmt = $total_pat_pmt = 0;

// Does patient have active primary insurance?
$_has_ins = false;
if ($pid) {
    $_ins_chk = sqlQuery(
        "SELECT id FROM insurance_data WHERE pid=? AND type='primary'
         AND (date IS NULL OR date <= CURDATE()) ORDER BY date DESC LIMIT 1",
        [$pid]
    );
    $_has_ins = !empty($_ins_chk['id']);
}

// Charges + credits grouped by encounter
$enc_res = sqlStatement(
    "SELECT fe.encounter, fe.date, fe.reason, fe.provider_id
     FROM form_encounter fe
     WHERE fe.pid=? AND fe.date>=? AND fe.date<=?
     ORDER BY fe.date DESC, fe.encounter DESC",
    [$pid, $from_date, $to_date]
);


while ($enc = sqlFetchArray($enc_res)) {
    // Charges
    $chrgs = sqlStatement(
        "SELECT b.id AS billing_id, b.code, b.code_text, b.modifier, b.fee, b.units, b.bill_date,
                b.justify, b.billed, b.provider_id, b.notecodes,
                COALESCE(fe.pos_code, '11') AS pos_code,
                ic.name AS ins_name, b.payer_id
         FROM billing b
         LEFT JOIN insurance_companies ic ON ic.id=b.payer_id
         LEFT JOIN form_encounter fe ON fe.pid=b.pid AND fe.encounter=b.encounter
         WHERE b.pid=? AND b.encounter=? AND b.activity>0
         ORDER BY b.id",
        [$pid, $enc['encounter']]
    );
    $enc_chg = 0; $enc_ins_chg = 0; $enc_pat_chg = 0;
    $charge_rows = [];
    while ($c = sqlFetchArray($chrgs)) {
        $enc_chg += $c['fee'];
        $total_chg += $c['fee'];
        if ($_has_ins || !empty($c['payer_id'])) {
            $enc_ins_chg += $c['fee'];
        } else {
            $enc_pat_chg += $c['fee'];
        }
        $charge_rows[] = $c;
    }


    // Payments/adjustments
    $pmts = sqlStatement(
        "SELECT CONCAT(aa.pid,'-',aa.encounter,'-',aa.sequence_no) AS activity_id,
        aa.pid, aa.encounter, aa.sequence_no,
        aa.code, aa.modifier, aa.payer_type,
        aa.post_time, aa.post_user, aa.session_id,
        aa.pay_amount, aa.adj_amount, aa.memo, aa.account_code,
        aa.reason_code, aa.follow_up, aa.deleted, aa.modified_time,
        ars.payment_method,
        ars.payment_type,
        ars.reference,
        ars.pay_total,
        ars.payer_id,
        ars.check_date,
        ars.deposit_date,
        ars.description AS session_description,
        ic.name AS ins_name,
        COALESCE(lo.title, aa.memo) AS adj_reason_label
         FROM ar_activity aa
         LEFT JOIN ar_session ars ON ars.session_id=aa.session_id
         LEFT JOIN insurance_companies ic ON ic.id=ars.payer_id
         LEFT JOIN list_options lo ON lo.list_id='adjreason'
              AND lo.option_id=aa.memo AND lo.activity=1
         WHERE aa.pid=? AND aa.encounter=? AND aa.deleted IS NULL
         ORDER BY aa.sequence_no",
        [$pid, $enc['encounter']]
    );
    $enc_pmt = 0; $enc_adj = 0;
    $enc_ins_pmt = 0; $enc_ins_adj = 0;
    $enc_pat_pmt = 0; $enc_pat_adj = 0;
    $pmt_rows = [];
    $activity_rows = [];
    while ($p = sqlFetchArray($pmts)) {

        $activity_rows[] = $p;
        $p_type   = strtolower(trim((string)($p['payment_type'] ?? '')));
        $p_ac     = strtoupper(trim($p['account_code'] ?? ''));
        $p_method = strtolower(trim($p['payment_method'] ?? ''));
        $p_memo   = strtoupper(trim($p['memo'] ?? ''));
    
        $p_is_writeoff =
            $p_ac === 'WO'
            || strpos($p_memo, 'CONTRACT') !== false
            || strpos($p_memo, 'WRITE') !== false
            || strpos($p_memo, 'BAD_DEBT') !== false;
    
        $p_is_adj_session = in_array($p_method, [
            'patient_adjustment',
            'insurance_adjustment'
        ]);
    
        $p_is_adjustment =
            !$p_is_writeoff
            && (
                $p_ac === 'AJ'
                || $p_is_adj_session
            );

        // account_code='PR' = Patient Responsibility shift — insurance→patient transfer.
        // These have pay_amount=0 and adj_amount=0; the amount is encoded in the memo ("PR 55.00").
        // Treat as Transfer rows so they appear in the ledger with a T badge.
        $p_is_pr_transfer =
            $p_ac === 'PR'
            || strpos($p_memo, 'PR ') === 0;

        // account_code='IPP' = Insurance Payment Posted (legacy OpenEMR)
        // account_code='IA'  = Insurance Adjustment (legacy)
        // account_code='ins1'/'INS1' = Insurance (some versions)
        $p_is_ins =
              str_contains($p_type, 'insurance')
            || str_contains($p_method, 'insurance')
            || !empty($p['payer_id'])
            || intval($p['payer_type'] ?? 0) === 1
            || $p_ac === 'IPP'
            || $p_ac === 'IA'
            || $p_ac === 'INS1'
            || strtolower($p_ac) === 'ins1';
            // TRANS rows are balance transfers, not payments
            if ($p_ac === 'TRANS') {
              $p_is_ins = false;
            }

        // Determine transaction row type for display
        $p_is_transfer = ($p_ac === 'TRANS') || $p_is_pr_transfer;

        // Count writeoffs for totals — AND include in pmt_rows for display
        if ($p_is_writeoff) {
            $wo_amt = abs($p['adj_amount']);
            if ($wo_amt <= 0) {
                $wo_amt = abs($p['pay_amount']);
            }
    
            $enc_adj   += $wo_amt;
            $total_adj += $wo_amt;
    
            if ($p_is_ins) {
                $enc_ins_adj += $wo_amt;
            } else {
                $enc_pat_adj += $wo_amt;
            }

            $p['ledger_row_type']  = 'writeoff';
            $p['display_type']     = mdi_list_title($p['payment_method'], 'payment_method');
            $p['is_ins']           = $p_is_ins;
            $pmt_rows[] = $p;
            continue;
        }

        // Include adjustments in display
        if ($p_is_adjustment) {
            $adj_amt = abs($p['adj_amount']);
            if ($adj_amt <= 0) $adj_amt = abs($p['pay_amount']);
            $enc_adj   += $adj_amt;
            $total_adj += $adj_amt;
            $p['ledger_row_type'] = 'adjustment';
            $p['display_type']    = mdi_list_title($p['payment_method'], 'payment_method');
            $p['is_ins']          = $p_is_ins;
            $pmt_rows[] = $p;
            continue;
        }

        // Balance transfer — TRANS rows (new) or PR rows (legacy OpenEMR)
        if ($p_is_transfer) {
            // Extract transfer amount:
            // - TRANS rows: adj_amount holds the value
            // - PR rows: pay_amount=0, adj_amount=0, amount is in memo "PR 55.00"
            $xfer_amt = abs((float)($p['adj_amount'] ?? 0));
            if ($xfer_amt <= 0) $xfer_amt = abs((float)($p['pay_amount'] ?? 0));
            if ($xfer_amt <= 0 && $p_is_pr_transfer) {
                // Parse "PR 55.00" from memo
                if (preg_match('/PR\s+([\d]+(?:\.[\d]{1,2})?)/', $p['memo'] ?? '', $m_pr)) {
                    $xfer_amt = (float)$m_pr[1];
                }
            }

            // Build a readable display memo
            $display_memo = $p['memo'] ?? '';
            if ($p_is_pr_transfer && $xfer_amt > 0) {
                $display_memo = xl('Transfer to Patient') . ' ($' . number_format($xfer_amt, 2) . ')';
            }
            $p['memo']            = $display_memo;
            $p['ledger_row_type'] = 'transfer';
            $p['display_type']    = 'Transfer';
            $p['payment_method']  = 'transfer';
            $p['is_ins']          = (intval($p['payer_type'] ?? 0) === 1); // PR = from insurance side
            $p['_xfer_amt']       = $xfer_amt;
            $pmt_rows[] = $p;

            // Balance adjustments
            $xfer_memo = strtolower(trim((string)($p['memo'] ?? '')));

            $pmt_rows[] = $p;
            continue;

            
        }

        // Note: PR rows (p_is_pr_transfer) now go through the transfer block above
        // and are added to pmt_rows there — they do NOT fall through to here.

        // Actual payment / writeoff accounting
        $enc_pmt   += $p['pay_amount'];
        $enc_adj   += abs($p['adj_amount']);
    
        $total_pmt += $p['pay_amount'];
        $total_adj += abs($p['adj_amount']);
    
        if ($p_is_ins) {
            $enc_ins_pmt   += $p['pay_amount'];
            $enc_ins_adj   += abs($p['adj_amount']);
            $total_ins_pmt += $p['pay_amount'];
        } else {
            $enc_pat_pmt   += $p['pay_amount'];
            $enc_pat_adj   += abs($p['adj_amount']);
            $total_pat_pmt += $p['pay_amount'];
        }
    
        $p['display_type'] = mdi_list_title(
            $p['payment_method'],
            'payment_method'
        );
        $p['ledger_row_type'] = 'payment';
        $p['is_ins']          = $p_is_ins;
    
        $pmt_rows[] = $p;
    }

    // ── Mirror pat_ledger.php balance walk exactly ───────────────────────────
    // Step 1: charges start in ins_bal or pat_bal depending on insurance status
    $enc_has_ins_billing = false;
    foreach ($charge_rows as $cr) {
        if (!empty($cr['payer_id'])) { $enc_has_ins_billing = true; break; }
    }
    if ($_has_ins || $enc_has_ins_billing) {
        $enc_ins_bal = $enc_chg;
        $enc_pat_bal = 0;
    } else {
        $enc_ins_bal = 0;
        $enc_pat_bal = $enc_chg;
    }

    // Step 2: walk activity rows to update ins_bal / pat_bal only.
    // enc_bal is NOT computed here — it is derived from totals below.
    // PR rows only shift money between ins_bal and pat_bal — never touch enc_bal.
    // WO rows reduce ins_bal (or pat_bal if ins already 0) — do NOT double-count.
    foreach ($activity_rows as $p) {

        $rpmt     = (float)($p['pay_amount'] ?? 0);
        $radj     = abs((float)($p['adj_amount'] ?? 0));
        $memo_raw = strtolower(trim($p['memo'] ?? ''));
        $ac       = strtoupper(trim($p['account_code'] ?? ''));

        // Insurance activity — payment_type is the authoritative signal;
        // fall back to payer_id / payer_type for legacy rows.
        $pmt_type = strtolower(trim($p['payment_type'] ?? ''));

        $is_ins =
            strpos($pmt_type, 'insurance') !== false
            || !empty($p['payer_id'])
            || intval($p['payer_type'] ?? 0) === 1;

        // Writeoffs / contractual adjustments
        $is_writeoff =
            $ac === 'WO'
            || strpos($memo_raw, 'contract') !== false
            || strpos($memo_raw, 'write') !== false
            || strpos($memo_raw, 'bad_debt') !== false;

        // TRANS rows (new) and PR rows (legacy OpenEMR "PR 55.00" memo format)
        // Both represent insurance→patient balance transfers for ledger purposes.
        $is_pr_bal = ($ac === 'PR');
        if ($ac === 'TRANS' || $is_pr_bal) {

            if ($is_pr_bal) {
                // PR rows: amount encoded in memo as "PR 55.00"
                $xfer_amt = 0;
                if (preg_match('/PR\s+([\d]+(?:\.[\d]{1,2})?)/', $p['memo'] ?? '', $m_pr)) {
                    $xfer_amt = (float)$m_pr[1];
                }
                if ($xfer_amt > 0) {
                    $enc_ins_bal -= $xfer_amt;
                    $enc_pat_bal += $xfer_amt;
                }
                continue;
            }

            $xfer_amt  = $radj;
            if ($xfer_amt <= 0) $xfer_amt = abs($rpmt);

            $memo_lc = strtolower(trim($p['memo'] ?? ''));

            if (strpos($memo_lc, 'transfer to patient') !== false) {
                $enc_ins_bal -= $xfer_amt;
                $enc_pat_bal += $xfer_amt;
            } elseif (strpos($memo_lc, 'transfer from patient') !== false) {
                $enc_pat_bal -= $xfer_amt;
                $enc_ins_bal += $xfer_amt;
            } elseif (
                strpos($memo_lc, 'secondary') !== false ||
                strpos($memo_lc, 'tertiary')  !== false
            ) {
                $enc_ins_bal -= $xfer_amt;
            } elseif (strpos($memo_lc, 'primary insurance') !== false) {
                $enc_pat_bal -= $xfer_amt;
                $enc_ins_bal += $xfer_amt;
            } elseif (strpos($memo_lc, 'patient') !== false) {
                $enc_ins_bal -= $xfer_amt;
                $enc_pat_bal += $xfer_amt;
            }

            continue;
        }

        // Patient Responsibility transfer row — legacy fallback (already handled above if ac=PR)
        $is_pr = false; // no longer handled separately

        if ($is_writeoff) {
            $wo_amt = $rpmt + $radj;
            if ($enc_ins_bal > 0) {
                $enc_ins_bal -= $wo_amt;
            } else {
                $enc_pat_bal -= $wo_amt;
            }

        } elseif ($is_ins) {
            $enc_ins_bal -= ($rpmt + $radj);

        }  else {
          // Patient payment
          $enc_pat_bal -= $rpmt;
      
          // Patient adjustment/writeoff reduces patient balance
          $enc_pat_bal -= $radj;
      }
    }

    // Step 3: derive enc_bal from the authoritative totals accumulated in the
    // pmt_rows loop above (enc_pmt, enc_adj) — never go negative.
    // Formula: charge − insurance_paid − patient_paid − adjustments ≥ 0
    $enc_ins_bal = max(0, $enc_ins_bal);
    $enc_pat_bal = max(0, $enc_pat_bal);
    $enc_bal = max(0, $enc_chg - $enc_pmt - $enc_adj);
    $total_ins_bal += $enc_ins_bal;
    $total_pat_bal += $enc_pat_bal;
    $total_bal     += $enc_bal;

    $ledger_rows[] = [
        'enc'         => $enc,
        'charges'     => $charge_rows,
        'pmts'        => $pmt_rows,
        'activity' => $activity_rows, // math/full ledger
        'enc_chg'     => $enc_chg,
        'enc_pmt'     => $enc_pmt,
        'enc_adj'     => $enc_adj,
        'enc_bal'     => $enc_bal,
        'enc_ins_bal' => $enc_ins_bal,
        'enc_pat_bal' => $enc_pat_bal,
        'enc_ins_pmt' => $enc_ins_pmt,
        'enc_ins_adj' => $enc_ins_adj,
        'enc_pat_adj' => $enc_pat_adj,
        'enc_pat_pmt' => $enc_pat_pmt,
    ];
}

}

// Payment methods for dropdown
$pmeth_res = sqlStatement(
    "SELECT option_id, title FROM list_options WHERE list_id='payment_method' AND activity=1 ORDER BY seq"
);
$pay_methods = [];
while ($r = sqlFetchArray($pmeth_res)) {
    if (in_array($r['option_id'], ['electronic', 'bank_draft'])) continue;
    $pay_methods[] = $r;
}

// Encounter list for payment form
// ── Build $encs from $ledger_rows so patient payment panel uses IDENTICAL
// numbers to the ledger — single source of truth, no separate SQL queries ──────
$encs = [];
foreach ($ledger_rows as $lr) {
    $enc       = $lr['enc'];
    $enc_no    = intval($enc['encounter']);
    $enc_date  = substr($enc['date'] ?? '', 0, 10);

    // Resolve first billing code for description
    $first_code = '';
    if (!empty($lr['charges'])) {
        $first_charge = reset($lr['charges']);
        $first_code = $first_charge['code'] ?? '';
    }

    $encs[] = [
        'encounter'         => $enc_no,
        'encdate'           => $enc_date,
        'charges'           => $lr['enc_chg'],
        'ins_paid'          => $lr['enc_ins_pmt'],
        'pat_paid'          => $lr['enc_pat_pmt'],
        'ins_adj'           => $lr['enc_ins_adj'],
        'pat_adj'           => $lr['enc_pat_adj'],
        // duept = patient balance outstanding (from ledger walk)
        'duept'             => max(0, $lr['enc_pat_bal']),
        // balance = same as duept — used by pt payment table
        'balance'           => max(0, $lr['enc_pat_bal']),
        // ins_bal from ledger walk
        'ins_bal'           => max(0, $lr['enc_ins_bal']),
        'code'              => $first_code,
        'last_level_closed' => intval($enc['last_level_closed'] ?? 0),
        'last_pay_method'   => '',
    ];
}

// Sort encs by encounter DESC (same order as ledger)
usort($encs, function($a, $b) { return $b['encounter'] - $a['encounter']; });

$csrf = CsrfUtils::collectCsrfToken();

// ── Adjustment reason codes from list_options ────────────────────────────────
$adj_reason_codes = [];
$arq = sqlStatement(
    "SELECT option_id, title FROM list_options WHERE list_id='adjreason' AND activity=1 ORDER BY seq,title"
);
while ($arr = sqlFetchArray($arq)) {
    $adj_reason_codes[] = $arr;
}
// Fallback built-in codes if list is empty
if (empty($adj_reason_codes)) {
    $adj_reason_codes = [
        ['option_id'=>'WO-CONT',  'title'=>xl('WO - Contractual Write-Off')],
        ['option_id'=>'WO-BAD',   'title'=>xl('WO - Bad Debt Write-Off')],
        ['option_id'=>'WO-SMALL', 'title'=>xl('WO - Small Balance Write-Off')],
        ['option_id'=>'ADJ-CORR', 'title'=>xl('ADJ - Billing Correction')],
        ['option_id'=>'ADJ-ERA',  'title'=>xl('ADJ - ERA Adjustment')],
        ['option_id'=>'ADJ-PROM', 'title'=>xl('ADJ - Professional Courtesy')],
    ];
}

// ── Unapplied credits for this patient ───────────────────────────────────────
$unapplied_credits = [];
$total_unapplied   = 0;
if ($pid) {
  $ucq = sqlStatement(
    "SELECT
        s.session_id,
        s.reference,
        s.check_date,
        s.pay_total,
        s.payment_method,
        s.adjustment_code,

        COALESCE(SUM(a.pay_amount),0) AS applied,

        (
            s.pay_total
            - COALESCE(SUM(a.pay_amount),0)
            - s.global_amount
        ) AS available

    FROM ar_session s

    LEFT JOIN ar_activity a
        ON a.session_id = s.session_id
       AND a.deleted IS NULL

    WHERE s.patient_id = ?
      AND s.payer_id = 0
      AND s.pay_total > 0

      /* ONLY REAL PAYMENTS */
      AND LOWER(COALESCE(s.payment_method,'')) IN (
            'patient_payment',
            'insurance_payment',
            'cash',
            'check',
            'credit_card',
            'eft',
            'ach'
      )

    GROUP BY
        s.session_id,
        s.reference,
        s.check_date,
        s.pay_total,
        s.payment_method,
        s.adjustment_code,
        s.global_amount

    HAVING (
        s.pay_total
        - COALESCE(SUM(a.pay_amount),0)
        - s.global_amount
    ) > 0.004

    ORDER BY s.check_date DESC",
    [$pid]
);
  
    
    while ($ucr = sqlFetchArray($ucq)) {
        $ucr['available'] = round((float)$ucr['available'], 2);
        $unapplied_credits[] = $ucr;
        $total_unapplied += $ucr['available'];
    }
    $total_unapplied = round($total_unapplied, 2);
}
// ── End unapplied credits query ───────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo xlt('Payments & Ledger'); ?></title>
<?php Header::setupHeader(['datetime-picker']); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<!-- Early function definitions — must be before any iframe onload fires -->
<script>
function mdiIframeLoaded(iframe) {
    try {
        var h = iframe.contentWindow.document.body.scrollHeight;
        if (h && h > 200) iframe.style.height = Math.min(h + 20, 800) + 'px';
    } catch(e) {}
}
function mdiEditSaved(sessionId) {
    var drawer = document.getElementById('sr-drawer-' + sessionId);
    var iframe = document.getElementById('sr-iframe-' + sessionId);
    var chev   = document.getElementById('edit-chev-' + sessionId);
    if (drawer) drawer.style.display = 'none';
    if (iframe) { iframe.style.height = '0'; iframe.src = ''; }
    if (chev)   chev.style.transform = 'rotate(0deg)';
    var sf = document.getElementById('search-form');
    if (sf) sf.submit();
}
</script>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#f0f2f7;--surface:#fff;--surface2:#f7f8fc;--border:#e2e6f0;--border2:#d0d5e8;
  --text:#1a1d2e;--text2:#5a5f7a;--text3:#9197b3;
  --accent:#3b5bdb;--accent-l:#eef1fd;
  --green:#0ca678;--green-l:#e6f9f3;
  --red:#e03131;--red-l:#fff0f0;
  --amber:#e67700;--amber-l:#fff8e6;
  --purple:#5b21b6;--purple-l:#ede9fe;
  --r:8px;--r-sm:5px;--shadow:0 1px 4px rgba(0,0,0,.07),0 4px 16px rgba(0,0,0,.05);
}
body{font-family:'DM Sans',sans-serif;background:#fff!important;background-image:none!important;color:var(--text);font-size:13px;min-height:100vh;}
html,body{height:100%;}
body::before{content:'';position:fixed;inset:0;background:#fff;z-index:-1;}
/* top bar */
.topbar{background:var(--purple);color:#fff;padding:0 16px;height:50px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
.topbar-left{display:flex;flex-direction:column;}
.topbar-name{font-weight:600;font-size:14px;letter-spacing:-.2px;}
.topbar-meta{font-size:11px;opacity:.75;}
.topbar-right{display:flex;gap:8px;align-items:center;}
/* layout */
.page{display:flex;flex-direction:column;height:calc(100vh - 50px);overflow:hidden;}
/* nav buttons */
.mono {font-family:'DM Mono', monospace;
    font-variant-numeric: tabular-nums;
}
.dt td,
.dt th {
    vertical-align:middle;
}
.dt {
    table-layout:fixed;
    width:100%;
}
.nav-btns{display:flex;gap:8px;padding:10px 16px;background:var(--surface);border-bottom:1px solid var(--border);}
.nav-btn{flex:1;padding:9px 0;border-radius:6px;border:1.5px solid var(--purple);background:transparent;color:var(--purple);font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px;}
.nav-btn:hover{background:var(--purple-l);}
.nav-btn.active{background:var(--purple);color:#fff;}
/* panels */
.panel{display:none;}
.panel.active{display:flex;flex-direction:column;flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:16px;}
#panel-ledger.active{display:flex;flex-direction:row!important;flex:1;min-height:0;overflow:hidden;padding:0;}
#panel-search.active{display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;padding:0!important;}
#ledger-detail-panel{border-left:2px solid var(--border);}
.ledger-row{transition:background .08s;}
.ledger-row.selected{background:#ddd6fe!important;outline:2px solid #7c3aed;outline-offset:-2px;}
.ldp-detail-row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--border);font-size:12px;}
.ldp-detail-row:last-child{border-bottom:none;}
.ldp-detail-lbl{color:var(--text3);font-size:11px;}
.ldp-detail-val{font-weight:600;text-align:right;}
/* card */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r);box-shadow:var(--shadow);margin-bottom:14px;}
.card-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.card-title{font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:var(--text2);display:flex;align-items:center;gap:6px;}
.card-body{padding:16px;}
/* form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.form-grid-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.form-group{display:flex;flex-direction:column;gap:4px;}
.form-label{font-size:11px;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:.04em;}
.form-control{padding:8px 10px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-family:inherit;font-size:13px;color:var(--text);background:var(--surface);transition:border-color .15s;outline:none;}
.form-control:focus{border-color:var(--purple);}
select.form-control{cursor:pointer;}
/* source toggle */
.src-toggle{
    display:flex;
    gap:4px;
    padding:4px;
    background:#f7f7fc;
    border:1px solid var(--border);
    border-radius:12px;
    margin-bottom:14px;
}

.src-btn{
    flex:1;
    display:flex;
    align-items:center;
    justify-content:center;
    gap:8px;

    padding:10px 14px;

    border:none;
    border-radius:9px;

    background:transparent;

    color:var(--text2);
    font-size:12px;
    font-weight:600;

    cursor:pointer;
    transition:.15s ease;
}

.src-btn:hover{
    background:#f0edff;
    color:var(--purple);
}

.src-btn.active{
    background:linear-gradient(135deg,var(--purple),#4f46e5);
    color:#fff;
    box-shadow:0 4px 12px rgba(99,102,241,.25);
}
/* amount big */
.amount-big{font-family:'DM Mono',monospace;font-size:18px;font-weight:500;color:var(--purple);text-align:right;padding:9px 12px;border:2px solid var(--purple);border-radius:var(--r-sm);background:var(--purple-l);}
.amount-big:focus{outline:none;box-shadow:0 0 0 3px rgba(91,33,182,.12);}
/* radio group */
.radio-row{display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.radio-label{display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;}
.radio-label input{accent-color:var(--purple);cursor:pointer;}
/* table */
.dt{width:100%;border-collapse:collapse;}
.dt th{text-align:left;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;padding:7px 10px;border-bottom:2px solid var(--border);white-space:nowrap;}
.dt th.r{text-align:right;}
.dt td{padding:9px 10px;border-bottom:1px solid var(--border);font-size:12px;vertical-align:middle;}
.dt td.mono{font-family:'DM Mono',monospace;font-size:11px;}
.dt td.r{text-align:right;font-family:'DM Mono',monospace;font-size:11px;}
.dt tbody tr:hover td{background:var(--surface2);}
.dt tfoot td{font-weight:700;font-size:12px;background:var(--surface2)!important;border-top:2px solid var(--border);}
.pay-input{width:84px;padding:5px 7px;border:1.5px solid var(--border);border-radius:var(--r-sm);font-family:'DM Mono',monospace;font-size:12px;text-align:right;outline:none;}
.pay-input:focus{border-color:var(--purple);}
/* badge */
.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:10px;font-weight:700;}
.bg-green{background:var(--green-l);color:#0a5c44;}
.bg-red{background:var(--red-l);color:#9b1c1c;}
.bg-amber{background:var(--amber-l);color:#92400e;}
.bg-purple{background:var(--purple-l);color:var(--purple);}
.bg-gray{background:var(--surface2);color:var(--text2);}
/* unapplied credits */
#unapplied-credits-card .dt tbody tr:hover td{background:#fff8e6;}
#unapplied-credits-card tfoot td{background:var(--amber-l)!important;color:var(--amber);}
/* btn */
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:var(--r-sm);border:none;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap;}
.btn-primary{background:var(--purple);color:#fff;}
.btn-primary:hover{background:#4c1d95;}
.btn-success{background:var(--green);color:#fff;}
.btn-success:hover{background:#0a7a5a;}
.btn-outline{background:transparent;border:1.5px solid var(--border2);color:var(--text2);}
.btn-outline:hover{border-color:var(--purple);color:var(--purple);}
.btn-sm{padding:5px 10px;font-size:11px;}
/* summary strip */
.summary-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--border);border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:14px;}
.sum-cell{background:var(--surface);padding:12px 14px;}
.sum-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text3);}
.sum-val{font-family:'DM Mono',monospace;font-size:16px;font-weight:500;margin-top:3px;}
/* ledger encounter block */
.enc-block{border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-bottom:10px;}
.enc-header{background:var(--purple-l);padding:9px 14px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;user-select:none;}
.enc-header:hover{background:#ddd6fe;}
.enc-title{font-weight:700;font-size:12px;color:var(--purple);}
.enc-pills{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
.enc-body{display:none;}
.enc-body.open{display:block;}
.enc-footer{background:var(--surface2);padding:7px 14px;display:flex;justify-content:flex-end;gap:24px;font-size:11px;font-weight:700;}
.enc-footer span{display:flex;flex-direction:column;align-items:flex-end;}
.enc-footer .lbl{font-size:10px;font-weight:400;color:var(--text3);}
/* total bar */
.total-bar{background:var(--purple);border-radius:0 0 var(--r) var(--r);padding:10px 16px;display:flex;align-items:center;justify-content:space-between;}
.total-bar-lbl{font-size:12px;font-weight:700;color:#e9d5ff;}
.total-bar-val{font-family:'DM Mono',monospace;font-size:16px;font-weight:700;color:#fff;}
/* alert */
.alert{padding:10px 14px;border-radius:var(--r-sm);font-size:12px;margin-bottom:12px;}
.alert-success{background:var(--green-l);border:1px solid var(--green);color:#0a5c44;}
.alert-error{background:var(--red-l);border:1px solid var(--red);color:#9b1c1c;}
.alert-info{background:var(--accent-l,#eef1fd);border:1px solid var(--accent,#3b5bdb);color:#1e3a8a;}
/* scrollbar */
::-webkit-scrollbar{width:4px;height:4px;}
::-webkit:scrollbar-thumb{background:var(--border2);border-radius:2px;}
/* Adjustment panel */
.adj-amount-inp:not(:disabled) { border-color: var(--amber); background: var(--amber-l); }
.adj-amount-inp:disabled { background: var(--surface2); color: var(--text3); }
.adj-reason-inp:disabled { background: var(--surface2); color: var(--text3); }
#adj-lines-table tbody tr:has(.adj-line-check:checked) { background: #fffbf0; }
#adj-lines-table tbody tr:has(.adj-line-check:checked) td { border-color: #fde68a; }
.adj-type-badge { display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;gap:4px; }
/* ── Mode Buttons ── */

div.payment-mode-tabs{
    display:flex;
    gap:10px;
    margin-bottom:18px;
    border-bottom:1px solid #ddd;
    padding-bottom:12px!important;
     text-align:center !important;
}

button.mode-tab{
    border:none;
    background:#f3f3f3;
    color:#444;
    padding:10px 18px;
    border-radius:8px;
    font-size:13px;
    font-weight:600;
    cursor:pointer;
    margin-right:10px;
}

button.mode-tab.active{
    background:#6d28d9;
    color:white;
}
/* ── Adjustment Lines ── */
.adj-line-row {
    cursor: pointer;
    transition: all .15s ease;
}

.adj-line-row:hover {
    background: rgba(124, 58, 237, 0.06);
}

.adj-line-row.selected {
    background: rgba(124, 58, 237, 0.12);
    border-left: 4px solid #7c3aed;
}

.adj-line-row.selected td {
    font-weight: 600;
}

/* billing-ui.css */

/* TABLES */
.tbl {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
}

.tbl-head {
  padding: 8px 10px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .06em;
  color: var(--text2);
}

.tbl-cell {
  padding: 8px 10px;
}

.tbl-right {
  text-align: right;
}

.tbl-center {
  text-align: center;
}

/* TYPOGRAPHY */
.mono {
  font-family: 'DM Mono', monospace;
}

.text-green {
  color: var(--green);
}

.text-red {
  color: var(--red);
}

.text-amber {
  color: var(--amber);
}

.text-purple {
  color: var(--purple);
}

/* BUTTONS */
.btn {
  border: none;
  border-radius: var(--r-sm);
  padding: 6px 12px;
  font-size: 11px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-family: inherit;
}

.btn-primary {
  background: var(--primary);
  color: #fff;
}

/* CARDS */
.card {
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  overflow: hidden;
}

.card-header {
  background: var(--surface2);
  border-bottom: 2px solid var(--border);
}
/* print */
@media print{.topbar,.nav-btns,.btn,.src-toggle,.form-group,.post-bar{display:none!important;}.panel{display:block!important;overflow:visible;}}
</style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
  <div class="topbar-left">
    <span class="topbar-name"><?php echo text(strtoupper($pat_name)); ?></span>
    <span class="topbar-meta">
      PID #<?php echo text($pat_pubid); ?>
      &nbsp;·&nbsp; DOB: <?php echo text($pat_dob); ?>
      &nbsp;·&nbsp; <?php echo xlt('Primary Insurance'); ?>: <?php echo text($ins_name); ?>
    </span>
  </div>
  <div class="topbar-right">
    <button style="display:none" class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.4);"
      onclick="mdiOpenBillingPage('<?php echo $GLOBALS['webroot']; ?>/interface/billing/search_payments.php');">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <?php echo xlt('Search Payments'); ?>
    </button>
    <button class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.4);"
      onclick="switchTab('search')">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <?php echo xlt('Search Payments'); ?>
    </button>
    <button class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.4);"
      onclick="mdiOpenBillingPage('<?php echo $GLOBALS['webroot']; ?>/interface/billing/new_payment.php');">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="12" y1="15" x2="12" y2="20"/><line x1="9" y1="17" x2="15" y2="17"/></svg>
      <?php echo xlt('Batch Payments'); ?>
    </button>
    <button class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.4);" onclick="window.print()">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      <?php echo xlt('Print'); ?>
    </button>
    <button class="btn btn-outline btn-sm" style="color:#fff;border-color:rgba(255,255,255,.4);" onclick="if(opener)dlgclose();else window.close();">
      <?php echo xlt('Close'); ?>
    </button>
  </div>
</div>

<div class="page">

  <!-- ════════ PAYMENT PANEL ════════ -->
  <div class="panel<?php echo $active_tab === 'payment' ? ' active' : ''; ?>" id="panel-payment">

    <?php if ($save_msg): ?>
    <div class="alert alert-success"><?php echo text($save_msg); ?></div>
    <?php endif; ?>
    <?php if ($save_err): ?>
    <div class="alert alert-error"><?php echo text($save_err); ?></div>
    <?php endif; ?>

    <form method="post" action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>" id="payment-form" onsubmit="return validatePayment();">
    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
    <input type="hidden" name="form_pid" value="<?php echo attr($pid); ?>">
    <input type="hidden" name="form_save" value="1">

<!-- Payment Mode Details -->
<div class="payment-mode-tabs" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
    <!-- Transaction Ledger back button -->
    <button type="button" id="btn-ledger" onclick="switchTab('ledger')"
      style="display:inline-flex;align-items:center;gap:5px;background:#ede9fe;color:#5b21b6;border:1.5px solid #c4b5fd;border-radius:var(--r-sm);padding:6px 13px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0;">
      <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      <?php echo xlt('Transaction Ledger'); ?>
    </button>
    <span style="color:var(--border);font-size:16px;font-weight:300;">|</span>
    <span class="card-title" style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Payment Mode'); ?></span>
    <button type="button" class="mode-tab active" data-mode="payment" style="display:inline-flex;align-items:center;gap:5px;"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg><?php echo xlt('Payment'); ?></button>
    <button type="button" class="mode-tab" data-mode="adjustment" style="display:inline-flex;align-items:center;gap:5px;"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg><?php echo xlt('Adjustments/Writeoffs/Transfers'); ?></button>

  </div>
  <button type="button" id="btn-refund" onclick="switchTab('refund')"
    style="display:flex;align-items:center;gap:6px;padding:6px 14px;background:#fff0f0;border:1.5px solid #dc2626;border-radius:var(--r-sm);color:#dc2626;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;">
    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.86"/></svg>
    <?php echo xlt('Refund'); ?>
  </button>
</div>
    <div id="payment-mode-section">
    <!-- Source selector -->
    <div class="src-toggle">
      <button type="button" class="src-btn active" id="src-ins" onclick="setSource('insurance')">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <?php echo xlt('Insurance EOB'); ?>
      </button>
      <button type="button" class="src-btn" id="src-pat" onclick="setSource('patient')">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?php echo xlt('Patient Payment'); ?>
      </button>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         INSURANCE EOB MANUAL POSTING PANEL
         Lives OUTSIDE the payment <form> to prevent accidental
         form submission when Enter is pressed in EOB inputs.
         ══════════════════════════════════════════════════════════ -->
    </div><!-- close payment-mode-section temporarily for EOB panel -->
    </form><!-- close payment-form so EOB inputs don't belong to it -->
    <div id="eob-panel">


      <input type="hidden" id="eob-nonce" value="">
      <div id="eob-result-banner" style="display:none;" class="alert"></div>

      <!-- ── Row 1: Carrier / Method / Date / Check No / Amount ── -->
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--r-sm);padding:12px 16px;margin-bottom:8px;">
        <div style="display:grid;grid-template-columns:2fr 1.1fr 1.1fr 1.1fr 1.1fr 1.1fr;gap:10px;align-items:end;">

          <div>
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-bottom:3px;"><?php echo xlt('Apply To Carrier'); ?></div>
            <div style="font-weight:700;font-size:13px;color:var(--text);"><?php echo text($ins_name ?: xl('No Insurance on File')); ?></div>
            <div style="font-size:11px;color:var(--text3);"><?php echo xlt('Primary Insurance'); ?></div>
          </div>

          <div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Method'); ?></label>
            <select id="eob-method" style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;" onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
              <option value="eft"><?php echo xlt('EFT'); ?></option>
              <option value="check"><?php echo xlt('Check'); ?></option>
              <option value="ach"><?php echo xlt('ACH'); ?></option>
              <option value="credit_card"><?php echo xlt('Credit Card'); ?></option>
              <option value="cash"><?php echo xlt('Cash'); ?></option>
            </select>
          </div>

          <div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Date Paid'); ?></label>
            <input type="date" id="eob-deposit-date" value="<?php echo attr(date('Y-m-d')); ?>"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;" onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>

          <div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Check No.'); ?></label>
            <input type="text" id="eob-ref" placeholder="<?php echo xla('Optional'); ?>"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;" onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>

          <div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Claim #'); ?></label>
            <input type="text" id="eob-claim-number" placeholder="<?php echo xla('Optional'); ?>"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;" onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>

          <div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Amount'); ?></label>
            <input type="text" id="eob-total-amount" placeholder="$0.00"
              style="width:100%;border:1.5px solid var(--purple);border-radius:var(--r-sm);padding:6px 8px;font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:var(--text);outline:none;background:var(--purple-l);" oninput="eobRecalc()" onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--purple)'">
          </div>

        </div>

        <!-- ── Row 2: Trace # / Memo / Upload EOB ── -->
        <div style="display:grid;grid-template-columns:1fr 2fr 1.4fr;gap:10px;align-items:end;margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">

          <div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Trace #'); ?></label>
            <input type="text" id="eob-trace" placeholder="<?php echo xla('Optional'); ?>"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;" onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>

          <div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Memo'); ?></label>
            <input type="text" id="eob-memo" placeholder="<?php echo xla('e.g. Paid per EOB, Corrected claim ...'); ?>" maxlength="120"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;" onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>

          <div>
            <label style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);display:block;margin-bottom:3px;">
              <?php echo xlt('Upload EOB'); ?>
              <span style="font-weight:400;color:var(--text3);font-size:9px;text-transform:none;"> (<?php echo xlt('optional'); ?>)</span>
            </label>
            <div style="position:relative;">
              <input type="file" id="eob-file-input" accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff"
                style="position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;"
                onchange="eobFileSelected(this)">
              <div id="eob-file-label"
                style="width:100%;border:1.5px dashed var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:11px;color:var(--text3);background:#fff;display:flex;align-items:center;gap:5px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                <span id="eob-file-name"><?php echo xlt('Choose file...'); ?></span>
              </div>
            </div>
          </div>

        </div>
      </div>


<!-- ──────────────────────────────── -->
<!-- AVAILABLE INSURANCE CREDITS     -->
<!-- ──────────────────────────────── -->
<div id="insuranceCreditsCard" style="margin-bottom:12px;border:1px solid #c7d2fe;border-radius:10px;background:#f8faff;overflow:hidden;">

  <!-- Header row — always visible, clicking toggles table -->
  <div onclick="toggleCreditsTable('ins')" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#eef2ff;cursor:pointer;user-select:none;gap:12px;flex-wrap:wrap;">

    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <svg id="ins-credits-chevron" width="14" height="14" fill="none" stroke="#4f46e5" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;transition:transform .2s;"><polyline points="9 18 15 12 9 6"/></svg>
      <div>
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#4f46e5;letter-spacing:.05em;"><?php echo xlt('Available Insurance Credits'); ?></div>
        <div style="font-size:11px;color:var(--text2);margin-top:1px;"><?php echo xlt('Check/EFT money received but not yet attached to charges'); ?></div>
      </div>
      <span id="insCreditSummary" style="background:#fff;color:#4f46e5;border:1px solid #c7d2fe;border-radius:999px;padding:3px 10px;font-size:11px;font-weight:600;white-space:nowrap;">0 Credits • $0.00 Available</span>
    </div>

    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;" onclick="event.stopPropagation()">
      <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text2);cursor:pointer;white-space:nowrap;font-weight:500;">
        <input type="checkbox" id="includeFamilyCreditsIns" onchange="reloadInsuranceCredits()" style="accent-color:#4f46e5;cursor:pointer;">
        <?php echo xlt('Include Family Balances'); ?>
      </label>
      <button type="button" onclick="autoApplyInsuranceCredits()" style="background:#4f46e5;color:#fff;border:none;border-radius:7px;padding:5px 11px;font-size:11px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:4px;">
        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo xlt('Auto Apply'); ?>
      </button>
    </div>

  </div>

  <!-- Table — hidden by default -->
  <div id="ins-credits-table-wrap" style="display:none;overflow-x:auto;">
    <table style="width:100%;border-collapse:collapse;font-size:11.5px;">
      <thead>
        <tr style="background:#f0f0ff;border-bottom:2px solid #c7d2fe;">
          <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#4f46e5;white-space:nowrap;"><?php echo xlt('Date'); ?></th>
          <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#4f46e5;white-space:nowrap;"><?php echo xlt('Carrier'); ?></th>
          <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#4f46e5;white-space:nowrap;"><?php echo xlt('Check / EFT'); ?></th>
          <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#4f46e5;white-space:nowrap;"><?php echo xlt('Original'); ?></th>
          <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--green);white-space:nowrap;"><?php echo xlt('Applied'); ?></th>
          <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#4f46e5;white-space:nowrap;"><?php echo xlt('Available'); ?></th>
          <th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#4f46e5;white-space:nowrap;"><?php echo xlt('Action'); ?></th>
        </tr>
      </thead>
      <tbody id="insuranceCreditsBody">
        <tr id="ins-credits-empty"><td colspan="7" style="padding:14px;text-align:center;color:var(--text3);font-size:12px;"><?php echo xlt('No unapplied insurance credits found.'); ?></td></tr>
      </tbody>
      <tfoot id="ins-credits-foot" style="display:none;">
        <tr style="background:#f0f0ff;border-top:2px solid #c7d2fe;">
          <td colspan="5" style="padding:7px 10px;text-align:right;font-size:11px;font-weight:700;color:#4f46e5;"><?php echo xlt('Total Available'); ?></td>
          <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#4f46e5;" id="ins-credits-total">$0.00</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

</div>

      
      <!-- ── Lines table ── -->
      <div style="border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;margin-bottom:0;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
          <thead>
            <tr style="background:var(--purple);color:#fff;">
              <th style="padding:8px 10px;text-align:left;  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;width:88px;"><?php echo xlt('Date'); ?></th>
              <th style="padding:8px 10px;text-align:left;  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;min-width:90px;"><?php echo xlt('Carrier'); ?></th>
              <th style="padding:8px 10px;text-align:left;  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;width:80px;"><?php echo xlt('CPT'); ?></th>
              <th style="padding:8px 10px;text-align:right; font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;width:72px;"><?php echo xlt('Charge'); ?></th>
              <th style="padding:8px 8px; text-align:right; font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6ee7b7;width:88px;"><?php echo xlt('Payment'); ?></th>
              <th style="padding:8px 8px; text-align:right; font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fcd34d;width:82px;"><?php echo xlt('Adj'); ?></th>
              <th style="padding:8px 6px; text-align:left;  font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fcd34d;min-width:110px;"><?php echo xlt('Adj Reason'); ?></th>
              <th style="padding:8px 8px; text-align:right; font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#c4b5fd;width:78px;"><?php echo xlt('PR'); ?></th>
              <th style="padding:8px 8px; text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fbbf24;width:96px;" title="<?php echo xla('Transfer remaining balance to Patient Responsibility'); ?>"><?php echo xlt('Xfer'); ?></th>
              <th style="padding:8px 8px; text-align:right; font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6ee7b7;width:56px;" title="<?php echo xla('Unallocated: Charge − Payment − Adj − PR'); ?>"><?php echo xlt('Left'); ?></th>
              <th style="padding:8px 6px; text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;width:32px;"></th>
            </tr>
          </thead>
          <tbody id="eob-lines-tbody">
            <?php
            if ($preselect_enc > 0) {
                $eob_preload = array_filter($encs, function($e) use ($preselect_enc) {
                    return intval($e['encounter']) === $preselect_enc;
                });
            } else {
                $eob_preload = array_slice($encs, 0, 10);
            }
            $eob_charge_total = 0;
            $eob_open_total   = 0;
            foreach ($eob_preload as $eob_er):
                $eob_billing = sqlStatement(
                    "SELECT b.id, b.code_type, b.code, b.modifier, b.fee,
                            LEFT(fe.date,10) AS encdate,
                            ic.name AS ins_name
                       FROM billing b
                       JOIN form_encounter fe ON fe.pid=b.pid AND fe.encounter=b.encounter
                       LEFT JOIN insurance_data id2 ON id2.pid=b.pid AND id2.type='primary'
                       LEFT JOIN insurance_companies ic ON ic.id=id2.provider
                      WHERE b.pid=? AND b.encounter=? AND b.activity=1 AND b.fee > 0
                      ORDER BY b.id LIMIT 10",
                    [$pid, $eob_er['encounter']]
                );
                while ($eb = sqlFetchArray($eob_billing)):
                    $line_paid = (float)(sqlQuery(
                        "SELECT IFNULL(SUM(pay_amount),0) AS s FROM ar_activity
                          WHERE pid=? AND encounter=? AND code=? AND deleted IS NULL",
                        [$pid, $eob_er['encounter'], $eb['code']]
                    )['s'] ?? 0);
                    $line_adj = (float)(sqlQuery(
                        "SELECT IFNULL(SUM(adj_amount),0) AS s FROM ar_activity
                          WHERE pid=? AND encounter=? AND code=? AND deleted IS NULL",
                        [$pid, $eob_er['encounter'], $eb['code']]
                    )['s'] ?? 0);
                    $line_bal = max(0, $eb['fee'] - $line_paid - abs($line_adj));
                    $eob_open_total   += $line_bal;
                    $eob_charge_total += $line_bal;  // show balance owed, not gross charge
                    $cpt_str = $eb['code'] . ($eb['modifier'] ? ' - ' . $eb['modifier'] : '');
                    $row_bg = ($line_bal > 0.005) ? '#fff' : '#f8fdf8';
                    $carrier_name = !empty($eb['ins_name']) ? $eb['ins_name'] : ($ins_name ?: xl('Self'));
            ?>
            <tr class="eob-line-row"
              data-enc="<?php echo attr($eob_er['encounter']); ?>"
              data-billing="<?php echo attr($eb['id']); ?>"
              data-charge="<?php echo attr(number_format($line_bal, 2, '.', '')); ?>"
              data-balance="<?php echo attr(number_format($line_bal, 2, '.', '')); ?>"
              style="background:<?php echo attr($row_bg); ?>;border-bottom:1px solid var(--border);"
              onmouseover="this.style.background='#f5f3ff'" onmouseout="this.style.background='<?php echo attr($row_bg); ?>'">
              <td style="padding:7px 10px;font-family:'DM Mono',monospace;font-size:11px;color:var(--text2);white-space:nowrap;"><?php echo text($eb['encdate']); ?></td>
              <td style="padding:7px 10px;font-size:11px;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo attr($carrier_name); ?>"><?php echo text($carrier_name); ?></td>
              <td style="padding:7px 10px;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--purple);"><?php echo text($cpt_str); ?></td>
              <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:var(--text2);">
                $<?php echo text(number_format($eb['fee'], 2)); ?>
              </td>
              <td style="padding:5px 7px;text-align:right;">
                <input type="text" class="eob-paid-input" placeholder="0.00"
                  style="width:78px;border:1.5px solid var(--green);border-radius:var(--r-sm);padding:5px 6px;font-family:'DM Mono',monospace;font-size:12px;text-align:right;background:#f0fdf4;outline:none;"
                  oninput="eobLineChanged(this)"
                  onfocus="this.style.borderColor='var(--green)'" onblur="this.style.borderColor='var(--green)'">
              </td>
              <td style="padding:5px 6px;text-align:right;">
                <input type="text" class="eob-adj-input" placeholder="0.00"
                  style="width:74px;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:5px 6px;font-family:'DM Mono',monospace;font-size:12px;text-align:right;outline:none;"
                  oninput="eobLineChanged(this)"
                  onfocus="this.style.borderColor='var(--amber)'" onblur="this.style.borderColor='var(--border)'">
              </td>
              <td style="padding:5px 5px;">
                <!-- Adj reason dropdown — Non-Covered reveals free-text sub-fields -->
                <select class="eob-adj-reason-input"
                  style="width:100%;min-width:105px;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:5px 4px;font-size:11px;font-family:inherit;outline:none;background:#fff;"
                  onchange="eobAdjReasonChange(this)"
                  onfocus="this.style.borderColor='var(--amber)'" onblur="this.style.borderColor='var(--border)'">
                  <option value=""><?php echo xlt('—'); ?></option>
                  <option value="Contractual"><?php echo xlt('Contractual'); ?></option>
                  <option value="Copay"><?php echo xlt('Copay'); ?></option>
                  <option value="Coinsurance"><?php echo xlt('Coinsurance'); ?></option>
                  <option value="Deductible"><?php echo xlt('Deductible'); ?></option>
                  <option value="Non-Covered"><?php echo xlt('Non-Covered'); ?></option>
                  <option value="Write-Off"><?php echo xlt('Write-Off'); ?></option>
                  <option value="Other"><?php echo xlt('Other'); ?></option>
                </select>
                <!-- Free-entry fields shown only for Non-Covered -->
                <div class="eob-noncovered-fields" style="display:none;margin-top:4px;display:none;">
                  <input type="text" class="eob-nc-code" placeholder="<?php echo xla('Code'); ?>"
                    style="width:100%;margin-bottom:3px;border:1.5px solid #f59e0b;border-radius:var(--r-sm);padding:4px 5px;font-size:11px;font-family:'DM Mono',monospace;outline:none;background:#fffbeb;"
                    oninput="eobLineChanged(this)" onfocus="this.style.borderColor='var(--amber)'" onblur="this.style.borderColor='#f59e0b'" placeholder="<?php echo xla('Reason code'); ?>">
                  <input type="text" class="eob-nc-desc" placeholder="<?php echo xla('Description (optional)'); ?>"
                    style="width:100%;border:1.5px solid #f59e0b;border-radius:var(--r-sm);padding:4px 5px;font-size:11px;font-family:inherit;outline:none;background:#fffbeb;"
                    oninput="eobLineChanged(this)" onfocus="this.style.borderColor='var(--amber)'" onblur="this.style.borderColor='#f59e0b'">
                </div>
              </td>
              <td style="padding:5px 6px;text-align:right;">
                <input type="text" class="eob-pr-input" placeholder="0.00"
                  style="width:70px;border:1.5px solid #c4b5fd;border-radius:var(--r-sm);padding:5px 6px;font-family:'DM Mono',monospace;font-size:12px;text-align:right;background:#faf5ff;outline:none;"
                  oninput="eobPrManualChange(this)"
                  onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='#c4b5fd'">
              </td>
              <!-- Transfer to Patient checkbox -->
              <td style="padding:5px 8px;text-align:center;width:96px;vertical-align:top;">
                <label title="<?php echo xla('Transfer remaining balance to Patient Responsibility'); ?>" style="cursor:pointer;display:inline-flex;align-items:center;justify-content:center;">
                  <input type="checkbox" class="eob-xfer-chk"
                    style="accent-color:#f59e0b;width:16px;height:16px;cursor:pointer;"
                    onchange="eobXferChange(this)">
                </label>
              </td>
              <!-- Per-line unallocated indicator -->
              <td style="padding:5px 8px;text-align:right;">
                <span class="eob-line-left" style="font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:var(--text3);">—</span>
              </td>
              <!-- Delete row -->
              <td style="padding:5px 4px;text-align:center;">
                <button type="button" onclick="eobRemoveLine(this)" style="background:none;border:none;cursor:pointer;color:var(--red);padding:3px;border-radius:3px;" title="<?php echo xla('Remove line'); ?>">
                  <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </td>
            </tr>
            <?php endwhile; endforeach; ?>
          </tbody>
        </table>

        <!-- ── Totals footer: Charge / Paid / Adj / PR / Unallocated ── -->
        <div id="eob-totals-footer" style="background:#1e1b4b;border-top:2px solid var(--border);padding:10px 16px;">
          <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0;border:1px solid rgba(255,255,255,.12);border-radius:var(--r-sm);overflow:hidden;">

            <div style="padding:10px 14px;border-right:1px solid rgba(255,255,255,.12);">
              <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Balance Owed'); ?></div>
              <div id="eob-charge-total" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#e2e8f0;">$<?php echo text(number_format($eob_charge_total, 2)); ?></div>
            </div>

            <div style="padding:10px 14px;border-right:1px solid rgba(255,255,255,.12);">
              <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Insurance Paid'); ?></div>
              <div id="eob-paid-total" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#6ee7b7;">$0.00</div>
            </div>

            <div style="padding:10px 14px;border-right:1px solid rgba(255,255,255,.12);">
              <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Adjustment'); ?></div>
              <div id="eob-adj-total" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#fcd34d;">$0.00</div>
            </div>

            <div style="padding:10px 14px;border-right:1px solid rgba(255,255,255,.12);">
              <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Patient Resp'); ?></div>
              <div id="eob-pr-total" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#c4b5fd;">$0.00</div>
            </div>

            <div style="padding:10px 14px;">
              <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Unallocated'); ?></div>
              <div style="display:flex;align-items:center;gap:6px;">
                <div id="eob-unalloc" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#fca5a5;">$<?php echo text(number_format($eob_charge_total, 2)); ?></div>
                <span id="eob-balanced-badge" style="display:none;font-size:11px;font-weight:700;color:#6ee7b7;">✓ <?php echo xlt('Balanced'); ?></span>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- ── Action bar ── -->
      <div style="display:flex;justify-content:flex-end;align-items:center;gap:10px;padding:10px 0 4px;">
        <?php if ($preselect_enc > 0): ?>
        <a href="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=payment"
          style="color:var(--text3);font-size:12px;text-decoration:none;margin-right:auto;">
          ← <?php echo xlt('Show all encounters'); ?>
        </a>
        <?php endif; ?>
        <button type="button" class="btn btn-outline" onclick="if(opener)dlgclose();else history.back();"><?php echo xlt('Cancel'); ?></button>
        <button type="button" id="eob-post-btn" onclick="eobPost()"
          style="background:var(--purple);border:none;border-radius:var(--r-sm);padding:9px 32px;font-size:13px;font-weight:700;color:#fff;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:8px;">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          <?php echo xlt('Post'); ?>
        </button>
      </div>

    </div><!-- /#eob-panel -->

    <!-- Re-open the payment form for the Patient Payment panel -->
    <form method="post" action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>" id="payment-form" onsubmit="return validatePayment();">
    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
    <input type="hidden" name="form_pid" value="<?php echo attr($pid); ?>">
    <input type="hidden" name="form_save" value="1">
    <div id="payment-mode-section-pat">

    <!-- ══════════════════════════════════════════════════════════
         PATIENT PAYMENT PANEL (unchanged)
         ══════════════════════════════════════════════════════════ -->
    <div id="patient-pay-panel" style="display:none;">

        <div class="card" id="payment-details-card">
      <div class="card-header">
        <span class="card-title"><?php echo xlt('Payment Details'); ?></span>
        <span class="badge bg-purple" id="ins-badge"><?php echo xlt('Patient'); ?></span>
      </div>

      <div class="card-body">

        <!-- Row 1: Method + Amount -->
        <div class="form-grid-3" style="margin-bottom:12px;">
          <div class="form-group">
            <label class="form-label"><?php echo xlt('Payment Method'); ?></label>
            <select class="form-control" name="form_method" id="form_method" onchange="onMethodChange(this.value)">
              <?php foreach ($pay_methods as $pm): ?>
              <option value="<?php echo attr($pm['option_id']); ?>"><?php echo text($pm['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Check/Reference — hidden for Cash -->
          <div class="form-group" id="ref-field-wrap">
            <label class="form-label"><?php echo xlt('Check / Reference No.'); ?></label>
            <input type="text" class="form-control" name="form_source" id="form_source" placeholder="<?php echo xla('Optional'); ?>">


          </div>

          <div class="form-group">
            <label class="form-label"><?php echo xlt('Amount'); ?></label>
            <input type="text" class="amount-big form-control" id="main-amount" name="_display_amount" value="$0.00" readonly>
          </div>
        </div>

        <!-- Credit Card fields — only shown when method=credit_card -->
        <div id="cc-fields-wrap" style="display:none;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-sm);padding:12px;margin-bottom:12px;">
          <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text2);margin-bottom:10px;display:flex;align-items:center;gap:6px;">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            <?php echo xlt('Credit Card Information'); ?>
          </div>
          <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:10px;margin-bottom:10px;">
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Card Number'); ?></label>
              <input type="text" class="form-control" name="cc_number" id="cc_number" placeholder="•••• •••• •••• ••••" maxlength="19" oninput="fmtCard(this)">
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Expiry (MM/YY)'); ?></label>
              <input type="text" class="form-control" name="cc_expiry" id="cc_expiry" placeholder="MM/YY" maxlength="5" oninput="fmtExpiry(this)">
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('CVV'); ?></label>
              <input type="text" class="form-control" name="cc_cvv" id="cc_cvv" placeholder="•••" maxlength="4">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Name on Card'); ?></label>
              <input type="text" class="form-control" name="cc_name" id="cc_name" value="<?php echo attr($pat_name); ?>">
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Zip Code'); ?></label>
              <input type="text" class="form-control" name="cc_zip" id="cc_zip" placeholder="12345" maxlength="10">
            </div>
          </div>
          <div style="margin-top:10px;display:flex;gap:8px;">
            <button type="button" class="btn btn-primary btn-sm" onclick="openCCModal()">
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
              <?php echo xlt('Card Swipe / Process'); ?>
            </button>
          </div>
        </div>

        <!-- Coverage radios hidden; patient payment only -->
        <input type="hidden" name="radio_type_of_coverage" value="self">

        <!-- Pre-pay (patient only) -->
        <div id="pat-options">
          <div class="form-group" style="margin-bottom:10px;">
            <label class="form-label"><?php echo xlt('Post As'); ?></label>
            <div class="radio-row">
              <label class="radio-label"><input type="radio" name="radio_type_of_payment" value="invoice_balance" checked><?php echo xlt('Invoice Balance'); ?></label>
              <label class="radio-label"><input type="radio" name="radio_type_of_payment" value="pre_payment" onchange="togglePrepay(this)"><?php echo xlt('Pre-Payment'); ?></label>
            </div>
          </div>
          <div id="prepay-row" style="display:none;">
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Pre-Payment Amount'); ?></label>
              <input type="text" class="form-control" name="form_prepayment" id="form_prepayment" value="" style="max-width:150px;">
            </div>
          </div>
        </div>

      </div>
   

    <!-- ── Unapplied Patient Credits card ── -->
    <?php if (!empty($unapplied_credits)): ?>
    <div class="card" id="unapplied-credits-card" style="margin-bottom:12px;border:1px solid #fde68a;border-radius:10px;background:#fffbeb;overflow:hidden;">

      <!-- Header row — always visible, clicking toggles table -->
      <div onclick="toggleCreditsTable('pat')" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;background:#fef3c7;cursor:pointer;user-select:none;gap:12px;flex-wrap:wrap;">

        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <svg id="pat-credits-chevron" width="14" height="14" fill="none" stroke="#d97706" stroke-width="2.5" viewBox="0 0 24 24" style="flex-shrink:0;transition:transform .2s;"><polyline points="9 18 15 12 9 6"/></svg>
          <div>
            <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#d97706;letter-spacing:.05em;"><?php echo xlt('Available Patient Credits'); ?></div>
            <div style="font-size:11px;color:var(--text2);margin-top:1px;"><?php echo xlt('Unapplied patient payments and pre-payments'); ?></div>
          </div>
          <span style="background:#fff;color:#d97706;border:1px solid #fcd34d;border-radius:999px;padding:3px 10px;font-size:11px;font-weight:600;white-space:nowrap;">
            <?php echo count($unapplied_credits); ?> <?php echo xlt('Credits'); ?> • <?php echo text(mdi_money($total_unapplied)); ?> <?php echo xlt('Available'); ?>
          </span>
        </div>

        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;" onclick="event.stopPropagation()">
          <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text2);cursor:pointer;white-space:nowrap;font-weight:500;">
            <input type="checkbox" id="includeFamilyCredits" onchange="reloadPatientCredits()">
            <?php echo xlt('Include Family Credits'); ?>
          </label>
          <button type="button" onclick="autoApplyCredits()" style="background:#d97706;color:#fff;border:none;border-radius:7px;padding:5px 11px;font-size:11px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:4px;">
            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <?php echo xlt('Auto Apply'); ?>
          </button>
        </div>

      </div>

      <!-- Table — hidden by default -->
      <div id="pat-credits-table-wrap" style="display:none;overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:11.5px;">
          <thead>
            <tr style="background:#fef9e7;border-bottom:2px solid #fde68a;">
              <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;white-space:nowrap;"><?php echo xlt('Date'); ?></th>
              <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;white-space:nowrap;"><?php echo xlt('Reference'); ?></th>
              <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;white-space:nowrap;"><?php echo xlt('Method'); ?></th>
              <th style="padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;white-space:nowrap;"><?php echo xlt('Type'); ?></th>
              <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;white-space:nowrap;"><?php echo xlt('Original'); ?></th>
              <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--green);white-space:nowrap;"><?php echo xlt('Applied'); ?></th>
              <th style="padding:8px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;white-space:nowrap;"><?php echo xlt('Available'); ?></th>
              <th style="padding:8px 10px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#d97706;white-space:nowrap;"><?php echo xlt('Action'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($unapplied_credits as $uc):
            $method_lbl = sqlQuery("SELECT title FROM list_options WHERE list_id='payment_method' AND option_id=? AND activity=1 LIMIT 1", [$uc['payment_method']]);
            $type_lbl = ($uc['adjustment_code'] === 'pre_payment') ? xl('Pre-Payment') : xl('Patient Payment');
          ?>
          <tr id="ucrow_<?php echo attr($uc['session_id']); ?>" style="border-bottom:1px solid #fde68a;" onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background=''">
            <td style="padding:8px 10px;font-family:'DM Mono',monospace;font-size:11px;white-space:nowrap;"><?php echo text(substr($uc['check_date'],0,10)); ?></td>
            <td style="padding:8px 10px;font-family:'DM Mono',monospace;font-size:11px;white-space:nowrap;"><?php echo text($uc['reference'] ?: '—'); ?></td>
            <td style="padding:8px 10px;font-size:11px;white-space:nowrap;"><?php echo text($method_lbl['title'] ?? $uc['payment_method']); ?></td>
            <td style="padding:8px 10px;"><span class="badge bg-amber" style="font-size:10px;"><?php echo text($type_lbl); ?></span></td>
            <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-size:11px;white-space:nowrap;"><?php echo text(mdi_money($uc['pay_total'])); ?></td>
            <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-size:11px;color:var(--green);white-space:nowrap;"><?php echo text(mdi_money($uc['applied'])); ?></td>
            <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-size:11px;font-weight:700;color:#d97706;white-space:nowrap;"><?php echo text(mdi_money($uc['available'])); ?></td>
            <td style="padding:8px 10px;text-align:center;">
              <button type="button" class="btn btn-outline btn-sm" style="border-color:#d97706;color:#d97706;font-size:11px;padding:3px 10px;"
                onclick="applyOneCredit(<?php echo attr_js($uc['session_id']); ?>, <?php echo attr_js($uc['available']); ?>)">
                <?php echo xlt('Apply'); ?>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:#fef9e7;border-top:2px solid #fde68a;">
              <td colspan="6" style="padding:7px 10px;text-align:right;font-size:11px;font-weight:700;color:#d97706;"><?php echo xlt('Total Available'); ?></td>
              <td style="padding:7px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#d97706;white-space:nowrap;"><?php echo text(mdi_money($total_unapplied)); ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

    </div>
    <?php endif; ?>
   
    <!-- Encounter table — fixed scrolling window -->
    
    <div class="card" id="enc-table-card">
      <div class="card-header" style="align-items:center;">
        <div style="display:flex;align-items:center;gap:10px;">
          <span class="card-title"><?php echo xlt('Apply to Encounters'); ?></span>
          <?php if ($preselect_enc > 0): ?>
          <a href="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=payment"
            style="font-size:11px;color:var(--purple);text-decoration:none;">← <?php echo xlt('Show all'); ?></a>
          <?php endif; ?>
        </div>
        <?php if (!$preselect_enc): ?>
        <div style="display:flex;gap:6px;">
          <button type="button" class="btn btn-sm btn-outline" onclick="collapseAllEncs()"><?php echo xlt('Collapse All'); ?></button>
          <button type="button" class="btn btn-sm btn-outline" onclick="autoApply()"><?php echo xlt('Auto Apply'); ?></button>
        </div>
        <?php endif; ?>
      </div>
      <!-- Fixed-height scrollable container -->
      <div style="max-height:380px;overflow-y:auto;border-bottom:1px solid var(--border);">
        <table class="dt" style="margin:0;width:100%;">
        <thead>
        <tr style="background:var(--purple);color:#fff;">
          <th style="width:90px;padding:8px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('DOS'); ?></th>
          <th style="width:72px;padding:8px 8px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Enc'); ?></th>
          <th style="min-width:90px;padding:8px 8px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Description'); ?></th>
          <th style="width:100px;padding:8px 8px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fcd34d;"><?php echo xlt('Patient Balance'); ?></th>
          <th style="width:90px;padding:8px 8px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fcd34d;"><?php echo xlt('Adjustment'); ?></th>
          <th style="min-width:120px;padding:8px 6px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fcd34d;"><?php echo xlt('Reason'); ?></th>
          <th style="width:80px;padding:8px 8px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fbbf24;" title="<?php echo xla('Transfer balance back to Insurance'); ?>"><?php echo xlt('Xfer→Ins'); ?></th>
          <th style="width:100px;padding:8px 8px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6ee7b7;"><?php echo xlt('Payment'); ?></th>
          <th style="width:80px;padding:8px 8px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('To Collect'); ?></th>
        </tr>
        </thead>
          <tbody>
          <?php
$var_idx = 0;

foreach ($encs as $er):

    // If opened from a specific encounter, only show that one
    if ($preselect_enc > 0 && intval($er['encounter']) !== $preselect_enc) continue;

    $var_idx++;

    // insurance AR
    $ins_due = max(
        0,
        floatval($er['charges'])
        - floatval($er['ins_paid'])
        - floatval($er['adj'])
    );

    // patient responsibility
    $pt_due = max(
        0,
        floatval($er['duept'])
    );

    // current patient remaining balance
    $pt_remaining = max(
        0,
        floatval($er['balance'])
    );

    // credits/payments already applied
    $applied_amt = max(
        0,
        $pt_due - $pt_remaining
    );
?>
            <tr id="erow_<?php echo $var_idx; ?>"
                style="border-bottom:1px solid var(--border);"
                onmouseover="this.style.background='#f5f3ff'" onmouseout="this.style.background=''">

              <!-- DOS -->
              <td class="mono" style="padding:8px 10px;font-size:11px;white-space:nowrap;"><?php echo text($er['encdate']); ?></td>
              <!-- Enc # -->
              <td class="mono" style="padding:7px 8px;color:var(--purple);font-weight:700;font-size:11px;"><?php echo text($er['encounter']); ?></td>
              <!-- Description (first billing code description or "Office Visit") -->
              <td style="padding:7px 8px;font-size:11px;color:var(--text2);">
                <?php
                  $desc_r = sqlQuery("SELECT code_text FROM codes WHERE code=? LIMIT 1", [$er['code'] ?? '']);
                  echo text($desc_r['code_text'] ?? xl('Office Visit'));
                ?>
              </td>
              <!-- Patient Balance — prominent amber/red -->
              <td class="r mono" style="padding:7px 10px;font-size:13px;font-weight:700;<?php echo $pt_remaining > 0 ? 'color:var(--red);' : 'color:var(--green);'; ?>"
                data-pt-remaining="1">
                $<?php echo text(number_format($pt_remaining, 2)); ?>
              </td>
              <!-- Adjustment input -->
              <td style="padding:5px 6px;" onclick="event.stopPropagation()">
                <input class="pt-adj-input" type="text" placeholder="0.00"
                  name="form_adj[<?php echo attr($er['encounter']); ?>]"
                  data-enc="<?php echo attr($er['encounter']); ?>"
                  style="width:82px;border:1.5px solid var(--border);border-radius:4px;padding:5px 6px;font-family:'DM Mono',monospace;font-size:12px;text-align:right;outline:none;"
                  onfocus="this.style.borderColor='var(--amber)'" onblur="this.style.borderColor='var(--border)'"
                  oninput="ptLineCalc(this)">
              </td>
              <!-- Adj Reason -->
              <td style="padding:5px 5px;" onclick="event.stopPropagation()">
                <select class="pt-adj-reason-input"
                  name="form_adj_reason[<?php echo attr($er['encounter']); ?>]"
                  data-enc="<?php echo attr($er['encounter']); ?>"
                  style="width:100%;min-width:110px;border:1.5px solid var(--border);border-radius:4px;padding:5px 4px;font-size:11px;font-family:inherit;outline:none;background:#fff;"
                  onfocus="this.style.borderColor='var(--amber)'" onblur="this.style.borderColor='var(--border)'"
                  onchange="ptAdjReasonChange(this)">
                  <option value=""><?php echo xlt('-- Select Reason --'); ?></option>
                  <option value="Contractual"><?php echo xlt('Contractual'); ?></option>
                  <option value="Copay"><?php echo xlt('Copay'); ?></option>
                  <option value="Coinsurance"><?php echo xlt('Coinsurance'); ?></option>
                  <option value="Deductible"><?php echo xlt('Deductible'); ?></option>
                  <option value="Write-Off"><?php echo xlt('Write-Off'); ?></option>
                  <option value="Courtesy"><?php echo xlt('Courtesy'); ?></option>
                  <option value="Small Balance"><?php echo xlt('Small Balance'); ?></option>
                  <option value="Other"><?php echo xlt('Other'); ?></option>
                </select>
              </td>
              <!-- Transfer → Insurance checkbox -->
              <td style="padding:5px 8px;text-align:center;width:80px;vertical-align:middle;" onclick="event.stopPropagation()">
                <input type="checkbox" class="pt-xfer-ins-chk"
                  name="form_xfer[<?php echo attr($er['encounter']); ?>]"
                  value="1"
                  data-enc="<?php echo attr($er['encounter']); ?>"
                  data-ins-due="<?php echo attr($ins_due); ?>"
                  style="accent-color:#f59e0b;width:16px;height:16px;cursor:pointer;"
                  onchange="ptXferInsChange(this)">
              </td>
              <!-- Payment input (green, to collect) -->
              <td style="padding:5px 6px;" onclick="event.stopPropagation()">
                <input class="pay-input" type="text" id="paying_<?php echo $var_idx; ?>"
                  name="form_upay[<?php echo attr($er['encounter']); ?>]"
                  value="" placeholder="0.00"
                  data-pt-due="<?php echo attr($er['duept']); ?>"
                  data-pt-remaining="<?php echo attr($pt_remaining); ?>"
                  style="width:88px;border:1.5px solid var(--green);border-radius:4px;padding:5px 6px;font-family:'DM Mono',monospace;font-size:12px;text-align:right;background:#f0fdf4;outline:none;"
                  oninput="calcTotal();ptLineCalc(this)">
              </td>
              <!-- To Collect — remaining after payment -->
              <td class="r mono" style="padding:7px 8px;font-size:12px;font-weight:700;" onclick="event.stopPropagation()">
                <span class="pt-to-collect" style="color:<?php echo $pt_remaining > 0 ? 'var(--red)' : 'var(--green)'; ?>;">
                  $<?php echo text(number_format($pt_remaining, 2)); ?>
                </span>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div id="pt-totals-footer" style="background:#1e1b4b;border-top:2px solid var(--border);padding:10px 16px;">
        <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:0;border:1px solid rgba(255,255,255,.12);border-radius:var(--r-sm);overflow:hidden;">

          <div style="padding:10px 14px;border-right:1px solid rgba(255,255,255,.12);">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Balance Owed'); ?></div>
            <div id="pt-bal-total" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#e2e8f0;">$<?php
              $pt_bal_total = array_sum(array_map(function($e){ return max(0, floatval($e['balance'])); }, $encs));
              echo text(number_format($pt_bal_total, 2));
            ?></div>
          </div>

          <div style="padding:10px 14px;border-right:1px solid rgba(255,255,255,.12);">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Patient Paying'); ?></div>
            <div id="pt-pay-total" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#6ee7b7;">$0.00</div>
          </div>

          <div style="padding:10px 14px;border-right:1px solid rgba(255,255,255,.12);">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Adj / Writeoff'); ?></div>
            <div id="pt-adj-total-footer" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#fcd34d;">$0.00</div>
          </div>

          <div style="padding:10px 14px;border-right:1px solid rgba(255,255,255,.12);">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Xfer → Ins'); ?></div>
            <div id="pt-xfer-total" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#fbbf24;">$0.00</div>
          </div>

          <div style="padding:10px 14px;">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.5);margin-bottom:4px;"><?php echo xlt('Unallocated'); ?></div>
            <div style="display:flex;align-items:center;gap:6px;">
              <div id="pt-unalloc" style="font-family:'DM Mono',monospace;font-size:14px;font-weight:700;color:#fca5a5;">$<?php echo text(number_format($pt_bal_total, 2)); ?></div>
              <span id="pt-balanced-badge" style="display:none;font-size:11px;font-weight:700;color:#6ee7b7;">✓ <?php echo xlt('Balanced'); ?></span>
            </div>
          </div>

        </div>
        <!-- Legacy hidden field for form submission -->
        <input type="hidden" name="form_paytotal" id="form_paytotal" value="0.00">
      </div>
    </div>

    <div class="post-bar" style="display:flex;justify-content:flex-end;gap:8px;padding-bottom:8px;">
      <button type="button" class="btn btn-outline" onclick="if(opener)dlgclose();else window.close();"><?php echo xlt('Cancel'); ?></button>
      <?php if (!empty($GLOBALS['cc_front_payments']) && $GLOBALS['payment_gateway'] != 'InHouse'): ?>
        <?php if ($GLOBALS['payment_gateway'] == 'Sphere'): ?>
          <span id="cc-btn-wrap" style="display:none;"><?php echo SpherePayment::renderSphereHtml('clinic'); ?></span>
        <?php else: ?>
          <button type="button" id="cc-btn-wrap" class="btn btn-success" onclick="openCCModal()" style="display:none;">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <?php echo xlt('Credit Card Pay'); ?>
          </button>
          <?php if (!empty($GLOBALS['cc_stripe_terminal'])): ?>
          <button type="button" id="pos-btn-wrap" class="btn btn-success" onclick="posDialog()" style="display:none;">
            <?php echo xlt('POS Payment'); ?>
          </button>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
      <button type="submit" class="btn btn-success" id="post-payment-btn">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo xlt('Post Payment'); ?>
      </button>
    </div>
    </div><!-- /#patient-pay-panel -->
    </div><!-- /#payment-mode-section-pat -->
    </form><!-- /#payment-form -->

  <!-- Hidden auto-apply form — submitted by JS (must be OUTSIDE payment-form) -->
  <form id="auto-apply-form" method="post" action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>" style="display:none;">
    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
    <input type="hidden" name="form_pid" value="<?php echo attr($pid); ?>">
    <input type="hidden" name="form_auto_apply_credits" value="1">
    <input type="hidden" name="aa_session_id" id="aa_session_id" value="">
    <input type="hidden" name="aa_credit_avail" id="aa_credit_avail" value="">
  </form>

  </div><!-- /panel-payment -->


  <!-- ════════ TRANSFER MODE SECTION ════════ -->
  <div id="transfer-mode-section" style="display:none;">
    <?php if ($preselect_enc > 0): ?>
    <div style="background:#ede9fe;border:1.5px solid #c4b5fd;border-radius:var(--r-sm);padding:8px 14px;margin-bottom:10px;display:flex;align-items:center;gap:10px;">
      <svg width="13" height="13" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span style="font-size:12px;font-weight:700;color:#5b21b6;"><?php echo xlt('Encounter'); ?> #<?php echo text($preselect_enc); ?></span>
      <a href="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=ledger" style="margin-left:auto;color:#7c3aed;font-size:11px;text-decoration:none;font-weight:600;">← <?php echo xlt('Back to all encounters'); ?></a>
    </div>
    <?php endif; ?>
    <div id="transfer-panel" style="padding:4px 0 14px; display:block !important" >

      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--r-sm);padding:12px 16px;margin-bottom:16px;font-size:12px;color:#1d4ed8;display:flex;align-items:flex-start;gap:10px;">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><div style="font-weight:700;margin-bottom:3px;"><?php echo xlt('Transfer Encounters'); ?></div>
        <div style="opacity:.85;"><?php echo xlt('Transfer will close the current level and move the balance to the selected destination.'); ?></div></div>
      </div>

      <div id="transfer-result" style="display:none;margin-bottom:10px;" class="alert"></div>

      <div style="border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;margin-bottom:16px;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
          <thead>
            <tr style="background:var(--surface2);border-bottom:2px solid var(--border);">
              <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('DOS'); ?></th>
              <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('ENC #'); ?></th>
              <th style="padding:10px 14px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('CHARGES'); ?></th>
              <th style="padding:10px 14px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('INS BALANCE'); ?></th>
              <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('CURRENT LEVEL'); ?></th>
              <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('TRANSFER TO'); ?></th>
              <th style="padding:10px 14px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('ACTION'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php
          $level_labels = [0=>'Unbilled', 1=>'Primary', 2=>'Secondary', 3=>'Tertiary', 4=>'Patient'];
          $level_colors = [0=>'#94a3b8', 1=>'#3b82f6', 2=>'#f59e0b', 3=>'#8b5cf6', 4=>'#22c55e'];
          foreach ($encs as $ter):
              if ($preselect_enc > 0 && intval($ter['encounter']) !== $preselect_enc) continue;

            $t_ins_bal = max(
                0,
                $ter['charges'] - $ter['ins_paid']
            );
        
            // last_level_billed is where the encounter currently sits;
            // last_level_closed is what has already been passed/closed.
            $t_cur = intval($ter['last_level_billed'] ?? $ter['last_level_closed'] ?? 0);
        
            // ALL possible destinations
            $t_opts = [
                'primary'   => xl('Primary Insurance'),
                'secondary' => xl('Secondary Insurance'),
                'tertiary'  => xl('Tertiary Insurance'),
                'patient'   => xl('Patient'),
            ];
        
            // REMOVE current level option
            if ($t_cur == 1) {
                unset($t_opts['primary']);
            }
        
            if ($t_cur == 2) {
                unset($t_opts['secondary']);
            }
        
            if ($t_cur == 3) {
                unset($t_opts['tertiary']);
            }
        
            if ($t_cur == 4) {
                unset($t_opts['patient']);
            }
              $tenc = (int)$ter['encounter'];
              $level_lbl   = $level_labels[$t_cur] ?? 'Unbilled';
              $level_color = $level_colors[$t_cur] ?? '#94a3b8';
          ?>
            <tr style="border-bottom:1px solid var(--border);">
              <td style="padding:12px 14px;font-family:'DM Mono',monospace;font-size:12px;font-weight:600;"><?php echo text($ter['encdate']); ?></td>
              <td style="padding:12px 14px;font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:var(--purple);"><?php echo text($ter['encounter']); ?></td>
              <td style="padding:12px 14px;text-align:right;font-family:'DM Mono',monospace;font-weight:600;">$<?php echo text(number_format($ter['charges'],2)); ?></td>
              <td style="padding:12px 14px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:<?php echo $t_ins_bal>0?'#ef4444':'#22c55e'; ?>;">$<?php echo text(number_format($t_ins_bal,2)); ?></td>
              <td style="padding:12px 14px;">
                <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;color:#fff;background:<?php echo attr($level_color); ?>;">
                  <?php echo text(xl($level_lbl)); ?>
                </span>
              </td>
              <td style="padding:8px 14px;">
                <select id="tr-dest-<?php echo $tenc; ?>" class="form-control" style="font-size:12px;padding:6px 10px;min-width:170px;">
                  <?php foreach ($t_opts as $tv => $tl): ?>
                  <option value="<?php echo attr($tv); ?>"><?php echo text($tl); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td style="padding:8px 14px;">
                <button type="button" id="tr-btn-<?php echo $tenc; ?>" onclick="doTransfer(<?php echo attr_js($tenc); ?>)"
                  style="background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:7px 16px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;white-space:nowrap;">
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/></svg>
                  <?php echo xlt('Transfer'); ?>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px;">
        <?php foreach ($level_labels as $lv => $ll): ?>
        <span style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text2);">
          <span style="width:10px;height:10px;border-radius:50%;background:<?php echo attr($level_colors[$lv]); ?>;display:inline-block;"></span>
          <?php echo text(xl($ll)); ?>
        </span>
        <?php endforeach; ?>
      </div>

      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-sm);padding:14px 16px;margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:7px;font-size:12px;font-weight:700;color:var(--green);margin-bottom:8px;">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
          <?php echo xlt('Transfer Rules'); ?>
        </div>
        <ul style="margin:0;padding:0 0 0 18px;font-size:12px;color:var(--text2);line-height:1.8;">
          <li><?php echo xlt('You can only transfer to the next available level.'); ?></li>
          <li><?php echo xlt('Patient is available once all insurance levels are closed.'); ?></li>
          <li><?php echo xlt('Balances must be greater than $0.00 to transfer.'); ?></li>
        </ul>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-sm);">
        <div style="font-size:12px;color:var(--text2);display:flex;align-items:center;gap:7px;">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          <?php echo xlt('Select a destination and click Transfer to move the balance.'); ?>
        </div>
        <button type="button" onclick="location.reload()"
          style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;color:var(--text2);">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.86"/></svg>
          <?php echo xlt('Refresh List'); ?>
        </button>
      </div>

    </div>
  </div>

  <div id="adjustment-mode-section" style="display:none;">
    <?php if ($preselect_enc > 0): ?>
    <div style="background:#ede9fe;border:1.5px solid #c4b5fd;border-radius:var(--r-sm);padding:8px 14px;margin:14px 0 -4px;display:flex;align-items:center;gap:10px;">
      <svg width="13" height="13" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span style="font-size:12px;font-weight:700;color:#5b21b6;"><?php echo xlt('Encounter'); ?> #<?php echo text($preselect_enc); ?></span>
      <a href="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=ledger" style="margin-left:auto;color:#7c3aed;font-size:11px;text-decoration:none;font-weight:600;">← <?php echo xlt('Back to all encounters'); ?></a>
    </div>
    <?php endif; ?>


   <!-- ── Adjustments / Write-Offs ── -->
   <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:0;">

<!-- Toggle header -->


<div id="adj-inline-section" style="display:block;border:1px solid var(--border);border-top:none;border-radius:0 0 var(--r-sm) var(--r-sm);background:#fff;">
  <input type="hidden" name="post_adjustment" id="post_adjustment_hidden" value="0">
  <input type="hidden" name="adj_pid" value="<?php echo attr($pid); ?>">

  <!-- ── Header Defaults Bar ── -->
  <div style="padding:16px 20px 14px;border-bottom:1px solid var(--border);display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;">

   <!-- Post As + action buttons -->
   <div style="min-width:200px;flex:1.5;">
        
        <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Post As'); ?></span>
        <select class="form-control" name="adj_post_as" style="min-width:185px;">
          <option value="insurance"><?php echo xlt('Insurance Payment'); ?></option>
          <option value="patient"><?php echo xlt('Patient Adjustment'); ?></option>
        </select>
      </div>

    <!-- Adjustment Type -->
    <div style="min-width:160px;flex:1;">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:5px;"><?php echo xlt('Adjustment Type'); ?></div>
      <select class="form-control" name="adj_type" id="adj_type_sel" onchange="adjTypeOrTransferChanged(this.value)">
        <option value="write_off"><?php echo xlt('Write-Off'); ?></option>
        <option value="adjustment"><?php echo xlt('Adjustment'); ?></option>
        <option value="transfer"><?php echo xlt('Transfer'); ?></option>
      </select>
    </div>

    <!-- Reason — repopulated by Post As -->
    <div style="min-width:200px;flex:1.5;">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:5px;"><?php echo xlt('Reason'); ?></div>
      <div style="display:flex;gap:6px;align-items:center;">
        <select class="form-control" name="adj_reason" id="adj_reason_sel" onchange="adjApplyDefaultsToSelected()" style="flex:1;">
          <option value=""><?php echo xlt('-- Select --'); ?></option>
        </select>
        <button type="button" title="<?php echo xla('Add custom reason'); ?>" onclick="adjAddCustomReason()"
          style="flex-shrink:0;width:32px;height:32px;background:var(--surface2);border:1.5px solid var(--border2);border-radius:var(--r-sm);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;color:var(--purple);font-weight:700;line-height:1;">+</button>
      </div>
    </div>

    <!-- Amount -->
    <div style="min-width:120px;">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:5px;"><?php echo xlt('Amount'); ?></div>
      <input type="text" class="form-control" id="adj_invoice_amount" name="adj_invoice_amount"
        placeholder="0.00" oninput="updateAdjTotals(); adjSyncAmountToTransfer(this.value);"
        style="font-family:'DM Mono',monospace;font-size:14px;">
      <input type="hidden" id="adj_billing_id" name="billing_id">
      <input type="hidden" id="adj_encounter"  name="encounter">
      <input type="hidden" id="adj_amount"     name="adj_amount">
    </div>

    <!-- Selected + Remaining Balance (centred) -->
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-sm);padding:10px 14px;display:flex;align-items:center;gap:16px;flex-grow:1;justify-content:center;">
      <div style="text-align:center;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:3px;"><?php echo xlt('Selected Balance'); ?></div>
        <div id="selected-balance" style="font-family:'DM Mono',monospace;font-size:22px;font-weight:700;color:var(--green);">$0.00</div>
      </div>
      <div style="width:1px;height:40px;background:var(--border);"></div>
      <div style="text-align:center;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:3px;"><?php echo xlt('Remaining Balance'); ?></div>
        <div id="remaining-balance" style="font-family:'DM Mono',monospace;font-size:22px;font-weight:700;color:var(--green);">$0.00</div>
      </div>
    </div>

  </div><!-- /header bar -->

  
  <!-- ── Transfer Sub-Panel removed ── -->
  

  <!-- ── Line Items Table — all encounters, all lines ── -->
  <div id="adj-lines-card" style="padding:16px 20px 0;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
      <div style="font-size:14px;font-weight:700;color:var(--text);"><?php echo xlt('Line Items'); ?></div>
      <div style="display:flex;align-items:center;gap:10px;">
        <label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--text2);cursor:pointer;">
          <input type="checkbox" id="adj-show-zero" onchange="renderAdjLines()" style="accent-color:var(--purple);">
          <?php echo xlt('Show zero balances'); ?>
        </label>
        <span id="adj-transfer-note" style="display:none;font-size:11px;color:var(--text3);font-style:italic;">
          <?php echo xlt('Line items are not required for transfers.'); ?>
        </span>
      </div>
    </div>

    <div style="border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;">
      <!-- Fixed 300px scrollable line items -->
      <div style="max-height:300px;overflow-y:auto;overflow-x:auto;">
      <table id="adj-lines-table" style="width:100%;border-collapse:collapse;font-size:12px;">
        <thead>
          <tr style="background:var(--surface2);border-bottom:2px solid var(--border);">
            <th style="padding:9px 10px;width:12px;"></th><!-- expand arrow -->
            <th style="padding:9px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('DOS / Enc'); ?></th>
            <th style="padding:9px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('CPT / Description'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Charge'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--green);"><?php echo xlt('Insurance Paid'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Patient Paid'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--amber);"><?php echo xlt('Existing Adjustments'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Adjustment Reason'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Current Balance'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Status'); ?></th>
           
          </tr>
        </thead>
        <tbody id="adj-lines-tbody">
          <tr><td colspan="9" style="text-align:center;padding:32px;color:var(--text3);">
            <div style="font-size:13px;"><?php echo xlt('Loading encounters…'); ?></div>
          </td></tr>
        </tbody>
      </table>
      </div><!-- /300px scroll wrapper -->
    </div>

    <!-- Totals row -->
    <div id="adj-totals-row" style="display:none;margin-top:1px;border:1px solid var(--border);border-top:2px solid var(--border);border-radius:0 0 var(--r-sm) var(--r-sm);background:var(--surface2);display:flex;align-items:center;">
      <div style="flex:0 0 42px;"></div>
      <div style="flex:0 0 12px;"></div>
      <div style="padding:10px 10px;font-size:11px;font-weight:700;color:var(--text2);flex:1;">
        <?php echo xlt('TOTALS'); ?>
        <span id="adj-selected-count" style="font-size:10px;font-weight:400;color:var(--text3);margin-left:4px;"></span>
      </div>
      <div style="flex:1;"></div><!-- CPT col -->
      <div style="padding:10px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;min-width:80px;" id="adj-tot-charge">$0.00</div>
      <div style="padding:10px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:var(--green);min-width:110px;" id="adj-tot-payment">$0.00</div>
      <div style="padding:10px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;min-width:100px;" id="adj-tot-pat-payment">$0.00</div>
      <div style="padding:10px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:var(--amber);min-width:90px;" id="adj-tot-adj">$0.00</div>
      <div style="padding:10px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;min-width:100px;" id="adj-tot-bal">$0.00</div>
    </div>
  </div><!-- /adj-lines-card -->

 
    <div style="display:flex;gap:8px;">
      <button type="button" class="btn btn-outline" onclick="toggleAdjSection()"><?php echo xlt('Cancel'); ?></button>
      <button type="button" id="adj-save-btn" onclick="adjSaveOrTransfer()"
        style="background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:10px 20px;font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:7px;font-family:inherit;">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" id="adj-save-icon"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span id="adj-save-label"><?php echo xlt('Save Adjustment'); ?></span>
      </button>
    </div>
  </div>

</div><!-- /adj-inline-section -->
</div><!-- /adj wrapper -->

</div>


  <!-- ════════ SEARCH PANEL ════════ -->
  <div class="panel" id="panel-search" style="padding:0!important;flex-direction:column;">

    <!-- ══ Page Header ══ -->
    <div style="flex-shrink:0;background:var(--purple);padding:11px 18px;display:flex;align-items:center;justify-content:space-between;">
      <div style="display:flex;align-items:center;gap:10px;">
        <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span style="font-size:15px;font-weight:700;color:#fff;letter-spacing:.01em;"><?php echo xlt('Search Payments'); ?></span>
        <?php if (!empty($search_results)): ?>
        <span style="background:rgba(255,255,255,.2);color:#fff;font-size:11px;font-weight:700;padding:2px 10px;border-radius:12px;">
          <?php echo count($search_results); ?> <?php echo xlt('records'); ?>
        </span>
        <?php endif; ?>
      </div>
      <button type="button" onclick="switchTab('ledger')"
        style="display:flex;align-items:center;gap:5px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:var(--r-sm);padding:5px 12px;font-size:11px;font-weight:600;color:#fff;cursor:pointer;font-family:inherit;">
        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
        <?php echo xlt('Transaction Ledger'); ?>
      </button>
    </div>

    <!-- ══ Filter Bar ══ -->
    <div style="flex-shrink:0;background:var(--surface);border-bottom:1px solid var(--border);padding:10px 18px;">
      <form method="post" id="search-form"
        action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=search"
        style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0;">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
        <input type="hidden" name="search_payments" value="1">

        <svg width="12" height="12" fill="none" stroke="var(--text3)" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <input type="date" name="s_from" value="<?php echo attr($_POST['s_from'] ?? ''); ?>"
          style="border:1.5px solid var(--border);border-radius:var(--r-sm);padding:5px 8px;font-size:12px;font-family:inherit;outline:none;"
          onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
        <span style="color:var(--text3);font-size:11px;">to</span>
        <input type="date" name="s_to" value="<?php echo attr($_POST['s_to'] ?? ''); ?>"
          style="border:1.5px solid var(--border);border-radius:var(--r-sm);padding:5px 8px;font-size:12px;font-family:inherit;outline:none;"
          onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">

        <select name="s_entity" style="border:1.5px solid var(--border);border-radius:var(--r-sm);padding:5px 8px;font-size:12px;font-family:inherit;outline:none;">
          <option value=""><?php echo xlt('All Types'); ?></option>
          <option value="insurance" <?php echo (($_POST['s_entity'] ?? '') === 'insurance') ? 'selected' : ''; ?>><?php echo xlt('Insurance'); ?></option>
          <option value="patient"   <?php echo (($_POST['s_entity'] ?? '') === 'patient')   ? 'selected' : ''; ?>><?php echo xlt('Patient'); ?></option>
        </select>

        <input type="text" name="s_ref" value="<?php echo attr($_POST['s_ref'] ?? ''); ?>"
          placeholder="<?php echo xla('Check / Ref #'); ?>"
          style="border:1.5px solid var(--border);border-radius:var(--r-sm);padding:5px 8px;font-size:12px;font-family:inherit;outline:none;width:130px;"
          onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">

        <button type="submit"
          style="background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:5px 16px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
          <?php echo xlt('Filter'); ?>
        </button>
        <a href="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=search"
          style="color:var(--text3);font-size:12px;text-decoration:none;"><?php echo xlt('Clear'); ?></a>
      </form>
    </div>

    <!-- ══ Results ══ -->
    <?php if (empty($search_results)): ?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:var(--text3);">
      <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24" style="opacity:.25;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <div style="font-size:13px;"><?php echo xlt('No payments found for this patient.'); ?></div>
      <div style="font-size:11px;color:var(--text3);"><?php echo xlt('Use the filter above to narrow by date, type, or reference number.'); ?></div>
    </div>
    <?php else: ?>

    <div style="flex:1;min-height:0;overflow-y:auto;overflow-x:auto;">
      <?php
      $pay_methods_list = [];
      $pmr2 = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id='payment_method' AND activity=1 ORDER BY seq");
      while ($pmrow2 = sqlFetchArray($pmr2)) { $pay_methods_list[$pmrow2['option_id']] = $pmrow2['title']; }

      // Encounter list for apply-unapplied dropdown
      $su_encs = [];
      $su_enc_q = sqlStatement(
          "SELECT fe.encounter, LEFT(fe.date,10) AS encdate,
                  COALESCE(SUM(b.fee),0) AS charges
             FROM form_encounter fe
             LEFT JOIN billing b ON b.pid=fe.pid AND b.encounter=fe.encounter AND b.activity!=0 AND b.fee!=0
            WHERE fe.pid=?
            GROUP BY fe.encounter, fe.date
            ORDER BY fe.date DESC LIMIT 60",
          [$pid]
      );
      while ($su_er = sqlFetchArray($su_enc_q)) { $su_encs[] = $su_er; }

      // Totals for footer
      $total_amount     = array_sum(array_column($search_results, 'pay_total'));
      $total_unapplied2 = array_sum(array_map(function($r){ return max(0, round($r['undistributed'] ?? 0, 2)); }, $search_results));
      ?>
      <table style="width:100%;border-collapse:collapse;font-size:12px;min-width:860px;">
        <thead style="position:sticky;top:0;z-index:2;">
          <tr style="background:#2d1f6e;color:#fff;">
            <th style="padding:9px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;"><?php echo xlt('ID'); ?></th>
            <th style="padding:9px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Date'); ?></th>
            <th style="padding:9px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Type'); ?></th>
            <th style="padding:9px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Payer / Patient'); ?></th>
            <th style="padding:9px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Method'); ?></th>
            <th style="padding:9px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Check / Ref'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6ee7b7;"><?php echo xlt('Amount'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fcd34d;"><?php echo xlt('Unapplied'); ?></th>
            <th style="padding:9px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Deposit Date'); ?></th>
            <th style="padding:9px 8px;text-align:center;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;width:110px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($search_results as $idx => $sr):
            $row_bg        = ($idx % 2 === 0) ? '#fff' : 'var(--surface2)';
            $sr_type       = strtolower(trim($sr['payment_type'] ?? ''));
            $is_ins_row    = strpos($sr_type, 'insurance') !== false || !empty($sr['ins_name']);
            $payer_display = $is_ins_row
                ? ($sr['ins_name'] ?: xl('Insurance'))
                : (trim(($sr['lname'] ?? '') . ', ' . ($sr['fname'] ?? '')) ?: xl('Patient'));
            $unapplied_amt = round($sr['undistributed'] ?? 0, 2);
            $has_unapplied = $unapplied_amt > 0.004;
            $method_title  = $pay_methods_list[$sr['payment_method']] ?? ucwords(str_replace('_', ' ', $sr['payment_method'] ?? ''));
        ?>
        <!-- ── Display row ── -->
        <tr id="spr-row-<?php echo attr($sr['session_id']); ?>"
          style="background:<?php echo attr($row_bg); ?>;border-bottom:1px solid var(--border);"
          onmouseover="this.style.background='#f5f3ff'" onmouseout="this.style.background='<?php echo attr($row_bg); ?>'">
          <td style="padding:8px 10px;font-family:'DM Mono',monospace;font-size:11px;color:var(--purple);font-weight:700;">#<?php echo text($sr['session_id']); ?></td>
          <td style="padding:8px 10px;font-family:'DM Mono',monospace;font-size:11px;"><?php echo text(substr($sr['check_date'], 0, 10)); ?></td>
          <td style="padding:8px 10px;">
            <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;<?php echo $is_ins_row ? 'background:#dbeafe;color:#1e40af;' : 'background:#ede9fe;color:#5b21b6;'; ?>">
              <?php echo $is_ins_row ? xlt('Insurance') : xlt('Patient'); ?>
            </span>
          </td>
          <td style="padding:8px 10px;font-size:12px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo text($payer_display); ?></td>
          <td style="padding:8px 10px;font-size:12px;"><?php echo text($method_title); ?></td>
          <td style="padding:8px 10px;font-family:'DM Mono',monospace;font-size:11px;color:var(--text2);"><?php echo text($sr['reference'] ?: '—'); ?></td>
          <!-- Amount -->
          <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:var(--green);"><?php echo text(mdi_money($sr['pay_total'])); ?></td>
          <!-- Unapplied -->
          <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;">
            <?php if ($has_unapplied): ?>
              <span style="color:var(--amber);background:#fffbeb;border:1px solid #fed7aa;border-radius:4px;padding:1px 8px;font-size:11px;"><?php echo text(mdi_money($unapplied_amt)); ?></span>
            <?php else: ?>
              <span style="color:var(--text3);font-size:11px;">—</span>
            <?php endif; ?>
          </td>
          <td style="padding:8px 10px;text-align:right;font-family:'DM Mono',monospace;font-size:11px;color:var(--text2);"><?php echo text(substr($sr['deposit_date'] ?? '', 0, 10) ?: '—'); ?></td>
          <td style="padding:6px 8px;text-align:center;white-space:nowrap;">
            <button type="button" onclick="sprToggleEdit(<?php echo (int)$sr['session_id']; ?>)"
              style="background:var(--purple-l);color:var(--purple);border:1px solid var(--purple);border-radius:var(--r-sm);padding:3px 10px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;">
              ✎ <?php echo xlt('Edit'); ?>
            </button>
            <button type="button" onclick="sprDelete(<?php echo (int)$sr['session_id']; ?>)"
              style="background:#fff0f0;color:var(--red);border:1px solid #fca5a5;border-radius:var(--r-sm);padding:3px 8px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;margin-left:3px;">🗑</button>
          </td>
        </tr>

        <!-- ── Inline edit + apply unapplied ── -->
        <tr id="spr-edit-<?php echo attr($sr['session_id']); ?>"
          style="display:none;background:#f5f0ff;border-bottom:2px solid var(--purple);">
          <td colspan="10" style="padding:16px 18px;">

            <!-- Edit form -->
            <form method="post" action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=search"
              onsubmit="sprSave(event, <?php echo (int)$sr['session_id']; ?>)">
              <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
              <input type="hidden" name="edit_session_id" value="<?php echo attr($sr['session_id']); ?>">
              <input type="hidden" name="save_session" value="1">

              <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <?php echo xlt('Edit Payment'); ?> #<?php echo text($sr['session_id']); ?>
              </div>

              <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:12px;align-items:end;">
                <div>
                  <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Date Paid'); ?></label>
                  <input type="date" name="edit_check_date" value="<?php echo attr(substr($sr['check_date'], 0, 10)); ?>"
                    style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;"
                    onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
                </div>
                <div>
                  <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Deposit Date'); ?></label>
                  <input type="date" name="edit_deposit_date" value="<?php echo attr(substr($sr['deposit_date'] ?? '', 0, 10)); ?>"
                    style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;"
                    onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
                </div>
                <div>
                  <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Method'); ?></label>
                  <select name="edit_payment_method" style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;">
                    <?php foreach ($pay_methods_list as $pm_id => $pm_title): ?>
                    <option value="<?php echo attr($pm_id); ?>" <?php echo ($sr['payment_method'] === $pm_id) ? 'selected' : ''; ?>><?php echo text($pm_title); ?></option>
                    <?php endforeach; ?>
                    <option value="eft"         <?php echo ($sr['payment_method'] === 'eft')         ? 'selected' : ''; ?>>EFT</option>
                    <option value="check"       <?php echo ($sr['payment_method'] === 'check')       ? 'selected' : ''; ?>>Check</option>
                    <option value="ach"         <?php echo ($sr['payment_method'] === 'ach')         ? 'selected' : ''; ?>>ACH</option>
                    <option value="credit_card" <?php echo ($sr['payment_method'] === 'credit_card') ? 'selected' : ''; ?>>Credit Card</option>
                    <option value="cash"        <?php echo ($sr['payment_method'] === 'cash')        ? 'selected' : ''; ?>>Cash</option>
                  </select>
                </div>
                <div>
                  <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Check / Ref #'); ?></label>
                  <input type="text" name="edit_reference" value="<?php echo attr($sr['reference'] ?? ''); ?>"
                    style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;"
                    onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
                </div>
                <div>
                  <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Amount'); ?></label>
                  <input type="text" name="edit_pay_total" value="<?php echo attr(number_format((float)$sr['pay_total'], 2)); ?>"
                    style="width:100%;border:1.5px solid var(--purple);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:'DM Mono',monospace;font-weight:700;outline:none;background:var(--purple-l);">
                </div>
                <div>
                  <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Type'); ?></label>
                  <select name="edit_payment_type" style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;">
                    <option value="insurance" <?php echo ($is_ins_row)  ? 'selected' : ''; ?>><?php echo xlt('Insurance'); ?></option>
                    <option value="patient"   <?php echo (!$is_ins_row) ? 'selected' : ''; ?>><?php echo xlt('Patient'); ?></option>
                  </select>
                </div>
              </div>

              <div style="display:flex;gap:8px;align-items:center;">
                <button type="submit"
                  style="background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:7px 20px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;">
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                  <?php echo xlt('Save Changes'); ?>
                </button>
                <button type="button" onclick="sprToggleEdit(<?php echo (int)$sr['session_id']; ?>)"
                  style="background:#fff;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:7px 14px;font-size:12px;cursor:pointer;font-family:inherit;color:var(--text2);">
                  <?php echo xlt('Cancel'); ?>
                </button>
                <div id="spr-save-msg-<?php echo attr($sr['session_id']); ?>" style="font-size:12px;margin-left:8px;color:var(--green);"></div>
              </div>
            </form>

            <?php if ($has_unapplied): ?>
            <!-- ── Apply Unapplied Balance ── -->
            <div style="margin-top:16px;padding:14px 16px;background:#fffbeb;border:1.5px solid var(--amber);border-radius:var(--r-sm);">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <svg width="14" height="14" fill="none" stroke="var(--amber)" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span style="font-size:12px;font-weight:700;color:#92400e;"><?php echo xlt('Unapplied Balance:'); ?></span>
                <span style="font-family:'DM Mono',monospace;font-size:14px;font-weight:800;color:var(--amber);"><?php echo text(mdi_money($unapplied_amt)); ?></span>
                <span style="font-size:11px;color:var(--text3);margin-left:4px;">— <?php echo xlt('post this credit to an encounter'); ?></span>
              </div>
              <form method="post" action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=search">
                <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
                <input type="hidden" name="spr_session_id" value="<?php echo attr($sr['session_id']); ?>">
                <input type="hidden" name="spr_apply_unapplied" value="1">
                <div style="display:grid;grid-template-columns:2fr 1fr auto;gap:10px;align-items:end;">
                  <div>
                    <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Apply to Encounter'); ?></label>
                    <select name="spr_enc"
                      style="width:100%;border:1.5px solid var(--amber);border-radius:var(--r-sm);padding:7px 8px;font-size:12px;font-family:inherit;outline:none;background:#fff;"
                      onfocus="this.style.borderColor='#ea580c'" onblur="this.style.borderColor='var(--amber)'">
                      <option value=""><?php echo xlt('— Select encounter —'); ?></option>
                      <?php foreach ($su_encs as $su_enc_opt): ?>
                      <option value="<?php echo attr($su_enc_opt['encounter']); ?>">
                        <?php echo text($su_enc_opt['encdate']); ?> — Enc #<?php echo text($su_enc_opt['encounter']); ?>
                        (<?php echo text(mdi_money($su_enc_opt['charges'])); ?>)
                      </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);display:block;margin-bottom:3px;"><?php echo xlt('Amount to Post'); ?></label>
                    <input type="text" name="spr_amount"
                      value="<?php echo attr(number_format($unapplied_amt, 2)); ?>"
                      style="width:100%;border:1.5px solid var(--amber);border-radius:var(--r-sm);padding:7px 8px;font-size:13px;font-family:'DM Mono',monospace;font-weight:700;outline:none;background:#fff;text-align:right;color:#92400e;"
                      onfocus="this.style.borderColor='#ea580c'" onblur="this.style.borderColor='var(--amber)'">
                  </div>
                  <div>
                    <button type="submit"
                      style="width:100%;background:var(--amber);color:#fff;border:none;border-radius:var(--r-sm);padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap;">
                      <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                      <?php echo xlt('Post Credit'); ?>
                    </button>
                  </div>
                </div>
              </form>
            </div>
            <?php endif; ?>

          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:var(--purple);">
            <td colspan="6" style="padding:9px 12px;text-align:right;font-weight:700;color:#e9d5ff;font-size:11px;text-transform:uppercase;letter-spacing:.04em;"><?php echo xlt('Totals'); ?></td>
            <td style="padding:9px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:#6ee7b7;"><?php echo text(mdi_money($total_amount)); ?></td>
            <td style="padding:9px 10px;text-align:right;font-family:'DM Mono',monospace;font-weight:700;color:<?php echo $total_unapplied2 > 0 ? '#fcd34d' : 'rgba(255,255,255,.35)'; ?>;">
              <?php echo $total_unapplied2 > 0 ? text(mdi_money($total_unapplied2)) : '—'; ?>
            </td>
            <td colspan="2" style="padding:9px 10px;color:#e9d5ff;font-size:11px;"><?php echo count($search_results); ?> <?php echo xlt('records'); ?></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <?php endif; ?>

  </div><!-- /panel-search -->
  <!-- ════════ ISSUE REFUND PANEL ════════ -->
  <div class="panel" id="panel-refund">
    <?php if ($preselect_enc > 0): ?>
    <div style="background:#ede9fe;border:1.5px solid #c4b5fd;border-radius:var(--r-sm);padding:8px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;">
      <svg width="13" height="13" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      <span style="font-size:12px;font-weight:700;color:#5b21b6;"><?php echo xlt('Showing payments for Encounter'); ?> #<?php echo text($preselect_enc); ?></span>
      <a href="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=ledger" style="margin-left:auto;color:#7c3aed;font-size:11px;text-decoration:none;font-weight:600;">← <?php echo xlt('Back to all encounters'); ?></a>
    </div>
    <?php endif; ?>
    <div style="padding:16px 20px 20px;">

      <div style="display:flex;align-items:flex-start;gap:12px;margin-bottom:16px;">
        <button type="button" onclick="switchTab('payment')"
          style="background:none;border:none;cursor:pointer;color:var(--text2);padding:4px;display:flex;align-items:center;">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
        </button>
        <div>
          <div style="font-size:18px;font-weight:800;color:var(--text);margin-bottom:3px;"><?php echo xlt('Issue Refund'); ?></div>
          <div style="font-size:12px;color:var(--text2);"><?php echo xlt('Use this option to refund unapplied patient or insurance credits while preserving the original payment record.'); ?></div>
        </div>
      </div>

      <div id="reverse-result-banner" style="display:none;margin-bottom:12px;" class="alert"></div>

      <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">

        <!-- Left: Steps -->
        <div style="flex:1;min-width:0;">

          <!-- Step 1 -->
          <div style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);margin-bottom:14px;overflow:hidden;">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
              <span style="width:24px;height:24px;border-radius:50%;background:var(--purple);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</span>
              <span style="font-size:14px;font-weight:700;"><?php echo xlt('Select Payment / Credit'); ?></span>
            </div>
            <div style="overflow-x:auto;">
              <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead>
                  <tr style="background:var(--surface2);border-bottom:1px solid var(--border);">
                    <th style="padding:9px 12px;width:32px;"></th>
                    <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Payment Date'); ?></th>
                    <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Reference / Check #'); ?></th>
                    <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Payer'); ?></th>
                    <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Method'); ?></th>
                    <th style="padding:9px 12px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--green);"><?php echo xlt('Amount Posted'); ?></th>
                    <th style="padding:9px 12px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo xlt('Unapplied'); ?></th>
                    <th style="padding:9px 12px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--green);"><?php echo xlt('Status'); ?></th>
                  </tr>
                </thead>
                <tbody id="refund-payment-list">
                <?php
                $reverse_pmts = sqlStatement(
            
                    "SELECT
                    s.session_id,
                    s.reference,
                    s.check_date,
                    s.pay_total,
                    s.payment_method,
                    s.payment_type,
                    ic.name AS ins_name,
                
                    COALESCE(SUM(
                        CASE
                            WHEN a.pay_amount < 0
                            THEN ABS(a.pay_amount)
                            ELSE 0
                        END
                    ),0) AS already_refunded,
                
                    (
                        s.pay_total
                        - COALESCE(SUM(
                            CASE
                                WHEN a.pay_amount > 0
                                THEN a.pay_amount
                                ELSE 0
                            END
                        ),0)
                    ) AS unapplied_amount
                
                FROM ar_session s
                
                LEFT JOIN ar_activity a
                    ON a.session_id = s.session_id
                   AND a.deleted IS NULL
                
                LEFT JOIN insurance_companies ic
                    ON ic.id = s.payer_id
                
                WHERE s.patient_id = ?
                  AND s.pay_total > 0
                  " . ($preselect_enc > 0 ? "AND s.session_id IN (
                      SELECT DISTINCT session_id FROM ar_activity
                      WHERE pid=? AND encounter=? AND deleted IS NULL
                  )" : "") . "

                  AND LOWER(COALESCE(s.payment_method,'')) IN (
                      'patient_payment',
                      'insurance_payment',
                      'cash',
                      'check',
                      'credit_card',
                      'eft',
                      'ach'
                  )

                  GROUP BY
                  s.session_id,
                  s.reference,
                  s.check_date,
                  s.pay_total,
                  s.payment_method,
                  s.payment_type,
                  ic.name

                  HAVING unapplied_amount > 0

                ORDER BY s.check_date DESC
                LIMIT 50",
                    $preselect_enc > 0 ? [$pid, $pid, $preselect_enc] : [$pid]
                );
                $has_rfnd = false; $rfnd_row = 0;
                $refund_rows = [];
                while ($rp = sqlFetchArray($reverse_pmts)):
                  $avail = round(
                    max(
                        0,
                        ($rp['unapplied_amount'] ?? 0)
                        - ($rp['already_refunded'] ?? 0)
                    ),
                    2
                );
                  if ($avail < 0.005) {
                    continue;
                     }
                    $has_rfnd = true; $rfnd_row++;
                    $ml = sqlQuery("SELECT title FROM list_options WHERE list_id='payment_method' AND option_id=? AND activity=1 LIMIT 1", [$rp['payment_method']]);
                    $method_lbl = $ml['title'] ?? $rp['payment_method'];
                    $payer_lbl  = $rp['ins_name'] ?: xl('Patient');
                    $unapplied = round(
                      $rp['unapplied_amount'] ?? 0,
                      2
                  );
                    $sid = (int)$rp['session_id'];
                    $refund_rows[] = $rp;
                ?>
                  <tr style="border-bottom:1px solid var(--border);cursor:pointer;" id="rfnd-row-<?php echo $sid; ?>"
                    onclick="selectReversePayment(<?php echo $sid; ?>)">
                    <td style="padding:10px 12px;text-align:center;">
                      <input type="radio" name="refund_session_sel" value="<?php echo attr($sid); ?>"
                        id="rfnd-radio-<?php echo $sid; ?>" style="accent-color:var(--purple);cursor:pointer;"
                        <?php echo $rfnd_row===1?'checked':''; ?>
                        data-amount="<?php echo attr(number_format($avail,2)); ?>"
                        data-reference="<?php echo attr($rp['reference']??''); ?>"
                        data-method="<?php echo attr($method_lbl); ?>"
                        data-date="<?php echo attr(substr($rp['check_date'],0,10)); ?>"
                        data-payer="<?php echo attr($payer_lbl); ?>"
                        onchange="selectReversePayment(<?php echo $sid; ?>)">
                    </td>
                    <td style="padding:10px 12px;font-family:'DM Mono',monospace;font-size:11px;"><?php echo text(substr($rp['check_date'],0,10)); ?></td>
                    <td style="padding:10px 12px;font-family:'DM Mono',monospace;font-size:11px;color:var(--purple);font-weight:600;"><?php echo text($rp['reference']?:'—'); ?></td>
                    <td style="padding:10px 12px;font-size:12px;"><?php echo text($payer_lbl); ?></td>
                    <td style="padding:10px 12px;font-size:12px;"><?php echo text($method_lbl?:'—'); ?></td>
                    <td style="padding:10px 12px;text-align:right;font-family:'DM Mono',monospace;color:var(--green);font-weight:700;">$<?php echo text(number_format($rp['pay_total'],2)); ?></td>
                    <td style="padding:10px 12px;text-align:right;font-family:'DM Mono',monospace;color:var(--text2);">$<?php echo text(number_format($unapplied,2)); ?></td>
                    <td style="padding:10px 12px;"><span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:#dcfce7;color:#15803d;"><?php echo xlt('Posted'); ?></span></td>
                  </tr>
                <?php endwhile; ?>
                <?php if (!$has_rfnd): ?>
                  <tr><td colspan="8" style="text-align:center;padding:28px;color:var(--text3);font-size:13px;"><?php echo xlt('No posted payments available for reversal.'); ?></td></tr>
                <?php endif; ?>
                </tbody>
              </table>
            </div>
            <?php if ($has_rfnd): ?><div style="padding:8px 12px;border-top:1px solid var(--border);font-size:11px;color:var(--text3);"><?php echo xlt('Showing'); ?> <?php echo $rfnd_row; ?> <?php echo xlt('payments'); ?></div><?php endif; ?>
          </div>

          <!-- Step 2 -->
          <div style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);margin-bottom:14px;overflow:hidden;">
            <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
              <span style="width:24px;height:24px;border-radius:50%;background:var(--purple);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</span>
              <span style="font-size:14px;font-weight:700;"><?php echo xlt('Refund Details'); ?></span>
            </div>
            <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;">
              <div>
                <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);display:block;margin-bottom:5px;"><?php echo xlt('Refund Date'); ?> <span style="color:var(--red);">*</span></label>
                <input type="text" id="rfnd-date" class="form-control" value="<?php echo attr(date('m/d/Y')); ?>" style="font-family:'DM Mono',monospace;">
              </div>
              <div>
                <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);display:block;margin-bottom:5px;"><?php echo xlt('Refund Reason'); ?> <span style="color:var(--red);">*</span></label>
                <div style="display:flex;gap:6px;align-items:center;">
                  <select id="rfnd-reason-sel" class="form-control" style="flex:1;">
                    <option value="Posted in Error"><?php echo xlt('Posted in Error'); ?></option>
                    <option value="Duplicate Payment"><?php echo xlt('Duplicate Payment'); ?></option>
                    <option value="Wrong Encounter"><?php echo xlt('Wrong Encounter'); ?></option>
                    <option value="Patient Request"><?php echo xlt('Patient Request'); ?></option>
                    <option value="Insurance Adjustment"><?php echo xlt('Insurance Adjustment'); ?></option>
                    <option value="Other"><?php echo xlt('Other'); ?></option>
                  </select>
                  <button type="button" onclick="rfndAddReason()" title="<?php echo xla('Add custom reason'); ?>"
                    style="flex-shrink:0;width:32px;height:32px;border:1.5px solid var(--purple);border-radius:8px;background:#fff;color:var(--purple);font-size:18px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;line-height:1;">+</button>
                </div>
              </div>
              <div>
                <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);display:block;margin-bottom:5px;"><?php echo xlt('Category'); ?></label>
                <select id="rfnd-category-sel" class="form-control">
                  <option value="Clerical / Data Entry Error"><?php echo xlt('Clerical / Data Entry Error'); ?></option>
                  <option value="Patient Overpayment"><?php echo xlt('Patient Overpayment'); ?></option>
                  <option value="Insurance Overpayment"><?php echo xlt('Insurance Overpayment'); ?></option>
                  <option value="Billing Correction"><?php echo xlt('Billing Correction'); ?></option>
                  <option value="Other"><?php echo xlt('Other'); ?></option>
                </select>
              </div>
            </div>
            <!-- Note / Explanation removed -->
          </div>

          <!-- Step 3 -->
          <div style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">

            <div>
              <label class="form-label">
                <?php echo xlt('Refund Method'); ?>
              </label>

              <select id="refund-method" class="form-control">
                <option value="check"><?php echo xlt('Check'); ?></option>
                <option value="cash"><?php echo xlt('Cash'); ?></option>
                <option value="credit_card"><?php echo xlt('Credit Card'); ?></option>
                <option value="eft"><?php echo xlt('EFT'); ?></option>
              </select>
            </div>

            <div>
              <label class="form-label">
                <?php echo xlt('Refund Amount'); ?>
              </label>

              <input
                type="number"
                id="refund-amount"
                class="form-control"
                min="0.01"
                step="0.01">
            </div>

          </div>

          <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:14px;border-top:1px solid var(--border);">
            <button type="button" class="btn btn-outline" onclick="switchTab('payment')"><?php echo xlt('Cancel'); ?></button>
            <button type="button" id="rfnd-confirm-btn" onclick="submitReverseRow(_rfndSelectedSession)"
              style="background:#dc2626;color:#fff;border:none;border-radius:var(--r-sm);padding:10px 24px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:7px;">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.86"/></svg>
              <?php echo xlt('Issue Refund'); ?>
            </button>
          </div>
        </div>

        <!-- Right: Summary -->
        <div style="width:260px;flex-shrink:0;">
          <div style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);margin-bottom:12px;overflow:hidden;">
            <div style="padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;"><?php echo xlt('Refund Summary'); ?></div>
            <div id="rfnd-summary-card" style="padding:14px;">
              <div style="font-size:11px;color:var(--text3);text-align:center;padding:10px 0;"><?php echo xlt('Select a payment above.'); ?></div>
            </div>
          </div>
          <div style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden;">
            <div style="padding:12px 14px;border-bottom:1px solid var(--border);font-size:13px;font-weight:700;"><?php echo xlt('Balance Impact'); ?></div>
            <div style="padding:14px;">
              <?php
             $rfnd_cur_bal = 0;

             foreach ($refund_rows as $tmp_rp) {
             
                 $tmp_avail = round(
                     max(
                         0,
                         ($tmp_rp['unapplied_amount'] ?? 0)
                         - ($tmp_rp['already_refunded'] ?? 0)
                     ),
                     2
                 );
             
                 $rfnd_cur_bal += $tmp_avail;
             }
             
             $rfnd_cur_bal = round($rfnd_cur_bal, 2);
             
              ?>
              <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0;border-bottom:1px solid var(--border);margin-bottom:6px;">
                <span style="color:var(--text2);"><?php echo xlt('Available Credit'); ?></span>
                <span style="font-family:'DM Mono',monospace;font-weight:600;">$<?php echo text(number_format(abs($rfnd_cur_bal),2)); ?></span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:12px;padding:4px 0;border-bottom:1px solid var(--border);margin-bottom:6px;">
                <span style="color:var(--text2);"><?php echo xlt('Refund Amount'); ?></span>
                <span id="rfnd-impact-amount" style="font-family:'DM Mono',monospace;font-weight:600;color:#dc2626;">+$0.00</span>
              </div>
              <div style="display:flex;justify-content:space-between;font-size:13px;font-weight:700;background:var(--surface2);border-radius:6px;padding:8px 10px;margin-top:4px;">
                <span><?php echo xlt('Remaining Credit'); ?></span>
                <span id="rfnd-impact-after" style="font-family:'DM Mono',monospace;color:#dc2626;">$<?php echo text(number_format(abs($rfnd_cur_bal),2)); ?></span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div><!-- /panel-reverse -->

  <!-- ════════ LEDGER PANEL ════════ -->
  <div class="panel<?php echo $active_tab === 'ledger' ? ' active' : ''; ?>" id="panel-ledger"
    style="flex-direction:row!important;padding:0!important;overflow:hidden;gap:0;">

<?php
// ── Shared cell style constants ──────────────────────────────────────────────
$TH   = 'padding:6px 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text3);white-space:nowrap;border-bottom:2px solid var(--border);border-right:1px solid var(--border);background:var(--surface2);';
$TD   = 'padding:5px 8px;font-size:11px;border-bottom:1px solid var(--border);border-right:1px solid var(--border);vertical-align:middle;';
$TD_R = 'padding:5px 8px;font-size:11px;border-bottom:1px solid var(--border);border-right:1px solid var(--border);vertical-align:middle;text-align:right;font-family:\'DM Mono\',monospace;';

// ── Build JS data payload for detail panel ──────────────────────────────────
$ledger_js_data = [];
foreach ($ledger_rows as $lr) {
    $enc_id   = $lr['enc']['encounter'];
    $enc_date = substr($lr['enc']['date'], 0, 10);
    foreach ($lr['charges'] as $c) {
        $dx_list = [];
        $raw_just = $c['justify'] ?? $c['diagnosis'] ?? '';
        if ($raw_just) {
            $dx_list = array_values(array_filter(array_map('trim', explode(':', $raw_just))));
        }
        $ledger_js_data[] = [
            'row_key'   => 'charge_' . $enc_id . '_' . ($c['billing_id'] ?? $c['code']),
            'type'      => 'charge',
            'billing_id'=> (int)($c['billing_id'] ?? 0),
            'enc_id'    => $enc_id,
            'enc_date'  => $enc_date,
            'code'      => $c['code'] ?? '',
            'code_text' => $c['code_text'] ?? '',
            'modifier'  => $c['modifier'] ?? '',
            'fee'       => (float)($c['fee'] ?? 0),
            'units'     => (int)($c['units'] ?? 1),
            'pos_code'  => $c['pos_code'] ?? '11',
            'ins_name'  => $c['ins_name'] ?? '',
            'bill_date' => substr($c['bill_date'] ?? '', 0, 10),
            'billed'    => !empty($c['billed']),
            'dx_codes'  => $dx_list,
        ];
    }
    foreach ($lr['pmts'] as $p) {
        $row_type = $p['ledger_row_type'] ?? 'payment';
        $pay_amt  = abs((float)($p['pay_amount'] ?? 0));
        $adj_amt  = abs((float)($p['adj_amount'] ?? 0));
        $posted_by = trim(($p['posted_fname'] ?? '') . ' ' . ($p['posted_lname'] ?? ''));
        // For writeoffs/adjustments the amount may be in adj_amount
        $display_amt = ($pay_amt > 0.004) ? $pay_amt : $adj_amt;
        $ledger_js_data[] = [
            'row_key'        => 'txn_' . $enc_id . '_' . ($p['sequence_no'] ?? mt_rand()),
            'type'           => $row_type,
            'enc_id'         => $enc_id,
            'enc_date'       => $enc_date,
            'code'           => $p['code'] ?? '',
            'procedure_name' => $p['code'] ?? '',
            'post_date'      => substr($p['post_time'] ?? '', 0, 10),
            'check_date'     => substr($p['check_date'] ?? '', 0, 10),
            'ins_name'       => $p['ins_name'] ?? '',
            'pay_total'      => (float)($p['pay_total'] ?? 0),
            'amount'         => $display_amt,
            'pay_amount'     => $pay_amt,
            'adj_amount'     => $adj_amt,
            'memo'           => $p['memo'] ?? '',
            'reference'      => $p['reference'] ?? '',
            'payment_method' => $p['payment_method'] ?? '',
            'method_label'   => ucwords(str_replace('_', ' ', $p['payment_method'] ?? '')),
            'is_ins'         => !empty($p['is_ins']),
            'posted_by'      => $posted_by ?: xl('Administrator'),
            'session_id'     => (int)($p['session_id'] ?? 0),
        ];
    }
}
?>

<!-- ── LEFT: Transaction Table ────────────────────────────────────────────── -->
<div style="flex:1;min-width:0;display:flex;flex-direction:column;overflow:hidden;border-right:2px solid var(--border);">

    <!-- Toolbar -->
    <div style="flex-shrink:0;display:flex;align-items:center;gap:8px;padding:7px 12px;border-bottom:1px solid var(--border);background:var(--surface);">
      <form method="get" style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;flex:1;margin:0;">
        <input type="hidden" name="pid" value="<?php echo attr($pid); ?>">
        <input type="hidden" name="tab" value="ledger">
        <svg width="13" height="13" fill="none" stroke="var(--purple)" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <span style="font-size:11px;color:var(--text2);font-weight:600;"><?php echo xlt('Service'); ?></span>
        <input type="date" name="from" value="<?php echo attr($_GET['from'] ?? date('Y-m-d', strtotime('-2 years'))); ?>"
          style="border:1.5px solid var(--border);border-radius:var(--r-sm);padding:3px 7px;font-size:11px;font-family:inherit;outline:none;"
          onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
        <span style="font-size:11px;color:var(--text3);">to</span>
        <input type="date" name="to" value="<?php echo attr($_GET['to'] ?? date('Y-m-d')); ?>"
          style="border:1.5px solid var(--border);border-radius:var(--r-sm);padding:3px 7px;font-size:11px;font-family:inherit;outline:none;"
          onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
        <button type="submit" style="background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:5px 14px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
          <?php echo xlt('Filter'); ?>
        </button>
        <button type="button" onclick="openEobForEnc(0)"
          style="background:#fff;border:1.5px solid var(--purple);border-radius:var(--r-sm);padding:5px 14px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;color:var(--purple);display:inline-flex;align-items:center;gap:5px;">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          <?php echo xlt('Add Payments'); ?>
        </button>
      </form>
    </div>

    <?php if (empty($ledger_rows)): ?>
    <div style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:10px;color:var(--text3);font-size:13px;">
      <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.3" viewBox="0 0 24 24" style="opacity:.3;"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
      <?php echo xlt('No transactions found for this date range.'); ?>
    </div>
    <?php else: ?>

    <!-- Scrollable table -->
    <div style="flex:1;min-height:0;overflow-y:auto;overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;min-width:660px;">
        <thead>
          <tr style="position:sticky;top:0;z-index:3;">
            <th style="<?php echo $TH; ?> min-width:80px;"><?php echo xlt('Date'); ?></th>
            <th style="<?php echo $TH; ?> min-width:110px;"><?php echo xlt('CPT'); ?></th>
            <th style="<?php echo $TH; ?> min-width:120px;"><?php echo xlt('Carrier'); ?></th>
            <th style="<?php echo $TH; ?> text-align:right;min-width:80px;"><?php echo xlt('Ins Bal'); ?></th>
            <th style="<?php echo $TH; ?> text-align:right;min-width:80px;"><?php echo xlt('Pat Bal'); ?></th>
            <th style="<?php echo $TH; ?> text-align:right;min-width:72px;"><?php echo xlt('Tot Bal'); ?></th>
            <th style="<?php echo $TH; ?> text-align:right;min-width:80px;"><?php echo xlt('Insurance'); ?></th>
            <th style="<?php echo $TH; ?> text-align:right;min-width:72px;border-right:none;"><?php echo xlt('Patient'); ?></th>
            <th style="<?php echo $TH; ?> width:32px;padding:2px;"></th>
          </tr>
        </thead>
        <tbody>
        <?php
        $rendered_void_sessions_flat = [];
        $row_parity = 0;

        foreach ($ledger_rows as $lr):
          $enc_info   = $lr['enc'];
          $enc_id     = $enc_info['encounter'];
          $enc_date   = substr($enc_info['date'], 0, 10);
          $enc_reason = $enc_info['reason'] ?: xl('Visit');

          $ep_bal = $lr['enc_bal'];
          $ep_chg = $lr['enc_chg'];
          if      ($ep_chg > 0 && abs($ep_bal) < 0.005)       { $sb_bg='#ede9fe';$sb_c='#5b21b6';$sb_t=xl('Paid In Full'); }
          elseif  ($lr['enc_ins_pmt'] > 0 && $ep_bal > 0.005) { $sb_bg='#fef3c7';$sb_c='#92400e';$sb_t=xl('Partially Paid'); }
          elseif  ($ep_bal > 0.005)                            { $sb_bg='#fee2e2';$sb_c='#991b1b';$sb_t=xl('Balance Due'); }
          else                                                  { $sb_bg='#dcfce7';$sb_c='#166534';$sb_t=xl('Paid In Full'); }
        ?>

          <!-- Encounter header row -->
          <tr style="background:#f5f3ff;border-top:2px solid #c4b5fd;border-bottom:1px solid #c4b5fd;">
            <td colspan="8" style="padding:6px 12px;">
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <!-- Calendar icon + date -->
                <svg width="14" height="14" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span style="font-family:'DM Mono',monospace;font-size:13px;font-weight:800;color:#5b21b6;">
                  <?php echo text($enc_date); ?>
                </span>
                <!-- Transaction count badge -->
                <?php $txn_count = count($lr['pmts']); ?>
                <span style="background:#ede9fe;color:#5b21b6;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;">
                  <?php echo $txn_count; ?> <?php echo xlt('transaction'); ?><?php echo $txn_count !== 1 ? 's' : ''; ?>
                </span>
                <!-- Enc# subtle -->
                <span style="font-size:10px;color:var(--text3);">Enc #<?php echo text($enc_id); ?></span>
                <!-- Summary amounts -->
                <span style="font-family:'DM Mono',monospace;font-size:11px;color:var(--text2);display:flex;gap:12px;flex-wrap:wrap;">
                  <span><?php echo xlt('Chg'); ?>: <strong><?php echo text(mdi_money($ep_chg)); ?></strong></span>
                  <span><?php echo xlt('Pd'); ?>: <strong style="color:var(--green);"><?php echo text(mdi_money($lr['enc_pmt'])); ?></strong></span>
                  <span><?php echo xlt('Adj'); ?>: <strong style="color:var(--amber);"><?php echo text(mdi_money($lr['enc_adj'])); ?></strong></span>
                  <span><?php echo xlt('Bal'); ?>: <strong style="color:<?php echo $ep_bal > 0.005 ? 'var(--red)' : 'var(--green)'; ?>;"><?php echo text(mdi_money($ep_bal)); ?></strong></span>
                </span>
                <!-- Record Payment button -->
                <button type="button"
                  onclick="openEobForEnc(<?php echo (int)$enc_id; ?>)"
                  style="margin-left:auto;display:inline-flex;align-items:center;gap:4px;background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:4px 12px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;flex-shrink:0;">
                  <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                  <?php echo xlt('Record Payment'); ?>
                </button>
              </div>
            </td>
          </tr>

          <!-- Charge line rows -->
          <?php foreach ($lr['charges'] as $c):
            $row_parity++;
            $bg = ($row_parity % 2 === 0) ? 'var(--surface2)' : '#fff';
            $l_pmt = 0; $l_adj = 0;
            foreach ($lr['pmts'] as $pp) {
                if (($pp['code'] ?? '') === $c['code'] && ($pp['modifier'] ?? '') === ($c['modifier'] ?? '')) {
                    $l_pmt += (float)($pp['pay_amount'] ?? 0);
                    $l_adj += abs((float)($pp['adj_amount'] ?? 0));
                }
            }
            $l_ins_bal = ($lr['enc_ins_bal'] > 0) ? max(0, $c['fee'] - $l_pmt - $l_adj) : 0;
            $l_pat_bal = ($lr['enc_pat_bal'] > 0) ? max(0, $c['fee'] - $l_pmt - $l_adj) : 0;
            $l_tot_bal = max(0, $c['fee'] - $l_pmt - $l_adj);
            $carrier   = $c['ins_name'] ?: xl('Self');
            $row_key   = 'charge_' . $enc_id . '_' . ($c['billing_id'] ?? $c['code']);
          ?>
          <tr id="lrow-<?php echo attr($row_key); ?>"
            onclick="ledgerSelectRow('<?php echo attr(addslashes($row_key)); ?>')"
            class="ledger-row"
            style="background:<?php echo attr($bg); ?>;cursor:pointer;"
            onmouseover="this.style.background='#ede9fe'" onmouseout="ledgerRowHoverOut(this,'<?php echo attr($row_key); ?>')">
            <td style="<?php echo $TD; ?> font-family:'DM Mono',monospace;font-size:11px;color:var(--text2);"><?php echo text($enc_date); ?></td>
            <td style="<?php echo $TD; ?> font-size:11px;">
              <span style="color:var(--purple);font-weight:700;font-family:'DM Mono',monospace;"><?php echo text($c['code']); ?></span>
              <?php if (!empty($c['modifier'])): ?><span style="color:var(--text3);font-size:10px;"> (<?php echo text($c['modifier']); ?>)</span><?php endif; ?>
              <?php if (!empty($c['code_text'])): ?><div style="font-size:10px;color:var(--text3);margin-top:1px;"><?php echo text(substr($c['code_text'],0,32)); ?></div><?php endif; ?>
            </td>
            <td style="<?php echo $TD; ?> font-size:11px;">
              <span style="display:inline-block;max-width:115px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo attr($carrier); ?>"><?php echo text($carrier); ?></span>
            </td>
            <td style="<?php echo $TD_R; ?> color:<?php echo $l_ins_bal > 0.005 ? '#1e40af' : 'var(--text3)'; ?>;font-weight:<?php echo $l_ins_bal > 0.005 ? '700' : '400'; ?>;"><?php echo text(mdi_money($l_ins_bal)); ?></td>
            <td style="<?php echo $TD_R; ?> color:<?php echo $l_pat_bal > 0.005 ? '#b45309' : 'var(--text3)'; ?>;"><?php echo text(mdi_money($l_pat_bal)); ?></td>
            <td style="<?php echo $TD_R; ?> font-weight:700;color:<?php echo $l_tot_bal > 0.005 ? 'var(--red)' : 'var(--text3)'; ?>;"><?php echo text(mdi_money($l_tot_bal)); ?></td>
            <td style="<?php echo $TD_R; ?> color:var(--text3);">&mdash;</td>
            <td style="<?php echo $TD_R; ?> border-right:none;color:var(--text3);">&mdash;</td>
          </tr>
          <?php endforeach; ?>

          <!-- Transaction rows: payments, writeoffs, adjustments, transfers -->
          <?php foreach ($lr['pmts'] as $p):
            $row_parity++;
            $row_type  = $p['ledger_row_type'] ?? 'payment';
            $p_is_ins  = !empty($p['is_ins']);
            $p_ac      = strtoupper(trim($p['account_code'] ?? ''));
            $p_memo_u  = strtoupper(trim($p['memo'] ?? ''));
            $pay_amt   = abs((float)($p['pay_amount'] ?? 0));
            $adj_amt_v = abs((float)($p['adj_amount'] ?? 0));
            // For transfers (inc. legacy PR rows): use _xfer_amt if set; else prefer adj, then pay
            $tot_amt   = isset($p['_xfer_amt']) && $p['_xfer_amt'] > 0.004
                ? $p['_xfer_amt']
                : (($adj_amt_v > 0.004) ? $adj_amt_v : (($pay_amt > 0.004) ? $pay_amt : 0));
            $seq_no    = $p['sequence_no'] ?? mt_rand();
            $txn_key   = 'txn_' . $enc_id . '_' . $seq_no;
            // Use ins_name when available; otherwise fall back based on payer_type
            // so insurance adjustments with no linked payer still show correctly
            $carrier_lbl = !empty($p['ins_name'])
                ? $p['ins_name']
                : ($p_is_ins ? xl('Insurance') : xl('Patient'));

            // Row type styling
            switch ($row_type) {
              case 'writeoff':
                $row_bg='#fff7ed'; $type_lbl='W'; $type_bg='#fed7aa'; $type_c='#ea580c';
                $type_title = xl('Write-Off / Contractual Adjustment');
                // Show in the correct column based on who owns the adjustment
                $ins_col = $p_is_ins ? mdi_money($tot_amt) : '&mdash;';
                $pat_col = !$p_is_ins ? mdi_money($tot_amt) : '&mdash;';
                break;
              case 'adjustment':
                $row_bg='#fef3f2'; $type_lbl='A'; $type_bg='#fee2e2'; $type_c='#991b1b';
                $type_title = xl('Adjustment');
                $ins_col = $p_is_ins ? mdi_money($tot_amt) : '&mdash;';
                $pat_col = !$p_is_ins ? mdi_money($tot_amt) : '&mdash;';
                break;
              case 'transfer':
                $row_bg='#eff6ff'; $type_lbl='T'; $type_bg='#dbeafe'; $type_c='#1e40af';
                $trans_memo = ucwords(strtolower(trim($p['memo'] ?? '')));
                $type_title = $trans_memo ?: xl('Transfer');
                // p_is_ins = source payer. Show amount in DESTINATION column.
                // ins→pat transfer (p_is_ins=true): show in PAT col
                // pat→ins transfer (p_is_ins=false): show in INS col
                $ins_col = !$p_is_ins ? mdi_money($tot_amt) : '&mdash;';
                $pat_col = $p_is_ins  ? mdi_money($tot_amt) : '&mdash;';
                break;
              default: // payment
                if ($p_is_ins) {
                    $row_bg='#f0fdf4'; $type_lbl='I'; $type_bg='#dbeafe'; $type_c='#1e40af';
                    $type_title = xl('Insurance Payment');
                    $ins_col = mdi_money($pay_amt); $pat_col = '&mdash;';
                } else {
                    $row_bg='#faf5ff'; $type_lbl='P'; $type_bg='#ede9fe'; $type_c='#5b21b6';
                    $type_title = xl('Patient Payment');
                    $ins_col = '&mdash;'; $pat_col = mdi_money($pay_amt);
                }
            }

            // Void button — once per insurance session
            $show_void_flat = false;
            $void_sess = intval($p['session_id'] ?? 0);
            if ($p_is_ins && $row_type === 'payment' && $void_sess > 0 && !in_array($void_sess, $rendered_void_sessions_flat)) {
                $show_void_flat = true;
                $rendered_void_sessions_flat[] = $void_sess;
            }
          ?>
          <tr id="lrow-<?php echo attr($txn_key); ?>"
            onclick="ledgerSelectRow('<?php echo attr(addslashes($txn_key)); ?>')"
            class="ledger-row"
            data-activity-id="<?php echo attr($p['activity_id'] ?? ''); ?>"
            data-pid="<?php echo attr($p['pid']); ?>"
            data-enc="<?php echo attr($p['encounter']); ?>"
            data-seq="<?php echo attr($p['sequence_no']); ?>"
            data-row-type="<?php echo attr($row_type); ?>"
            style="background:<?php echo attr($row_bg); ?>;cursor:pointer;"
            onmouseover="this.style.background='#e0e7ff'" onmouseout="ledgerRowHoverOut(this,'<?php echo attr($txn_key); ?>')">
            <td style="<?php echo $TD; ?> color:var(--text3);padding-left:20px;">
              <span style="display:inline-block;width:8px;height:1px;background:var(--border);vertical-align:middle;margin-right:2px;"></span>
            </td>
            <td style="<?php echo $TD; ?> font-size:11px;">
              <span title="<?php echo attr($type_title); ?>"
                style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:3px;font-size:9px;font-weight:800;background:<?php echo attr($type_bg); ?>;color:<?php echo attr($type_c); ?>;margin-right:4px;flex-shrink:0;vertical-align:middle;">
                <?php echo $type_lbl; ?>
              </span>
              <span style="font-size:10px;color:var(--text2);">
                <?php if ($row_type === 'writeoff'):
                  echo text(ucwords(strtolower(substr($p_memo_u, 0, 30))));
                elseif ($row_type === 'transfer'):
                  // Show "Transfer to X" from memo, then append reason if notes present
                  $t_memo = $p['memo'] ?? xl('Transfer');
                  echo text(substr($t_memo, 0, 35));
                elseif ($row_type === 'adjustment'):
                  echo text(ucwords(strtolower(substr($p_memo_u, 0, 30))));
                else:
                  echo text($p['code'] ?? '');
                  if (!empty($p['memo'])): ?><span style="color:var(--text3);"> — <?php echo text(substr($p['memo'],0,20)); ?></span><?php endif;
                endif; ?>
              </span>
            </td>
            <td style="<?php echo $TD; ?> font-size:11px;">
              <span style="display:inline-block;max-width:115px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo attr($carrier_lbl); ?>"><?php echo text($carrier_lbl); ?></span>
            </td>
            <td style="<?php echo $TD_R; ?> color:var(--text3);">&mdash;</td>
            <td style="<?php echo $TD_R; ?> color:var(--text3);">&mdash;</td>
            <td style="<?php echo $TD_R; ?> font-weight:700;color:<?php echo $row_type !== 'transfer' ? 'var(--text2)' : '#1e40af'; ?>;">
              <?php echo $tot_amt > 0.004 ? text(mdi_money($tot_amt)) : '<span style="color:var(--text3);">&mdash;</span>'; ?>
              <?php if ($show_void_flat): ?>
                <button type="button" onclick="event.stopPropagation();voidEobSession(<?php echo attr_js($void_sess); ?>, <?php echo attr_js($carrier_lbl); ?>)"
                  style="background:none;border:1px solid var(--red);border-radius:3px;color:var(--red);font-size:9px;font-weight:700;padding:1px 4px;cursor:pointer;font-family:inherit;margin-left:4px;">
                  <?php echo xlt('Void'); ?>
                </button>
              <?php endif; ?>
            </td>
            <td style="<?php echo $TD_R; ?> font-weight:700;color:<?php echo ($row_type === 'writeoff') ? '#ea580c' : '#1e40af'; ?>;border-right:none;">
              <?php echo $ins_col; ?>
            </td>
            <td style="<?php echo $TD_R; ?> font-weight:700;color:#5b21b6;border-right:none;">
              <?php echo $pat_col; ?>
            </td>
            <td style="<?php echo $TD; ?> text-align:center;width:28px;padding:2px 4px;">
              <?php if (!empty($p['sequence_no'])): ?>
              <button type="button"
                data-pid="<?php echo attr($p['pid']); ?>"
                data-enc="<?php echo attr($p['encounter']); ?>"
                data-seq="<?php echo attr($p['sequence_no']); ?>"
                data-row-type="<?php echo attr($row_type); ?>"
                onclick="event.stopPropagation(); ledgerDeleteRow(this)"
                title="<?php echo xla('Delete this transaction'); ?>"
                style="background:none;border:1px solid var(--red);border-radius:3px;color:var(--red);font-size:9px;font-weight:700;padding:2px 5px;cursor:pointer;font-family:inherit;line-height:1.3;">
                ✕
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>

        <?php endforeach; ?>
        </tbody>
      </table>
    </div><!-- /scroll -->

    <!-- Pinned totals footer -->
    <div style="flex-shrink:0;background:var(--purple);border-top:2px solid #7c3aed;">
      <div style="display:flex;align-items:stretch;justify-content:flex-end;">
        <span style="display:flex;align-items:center;padding:0 14px;font-size:10px;font-weight:700;color:#e9d5ff;text-transform:uppercase;letter-spacing:.05em;margin-right:auto;"><?php echo xlt('Totals'); ?></span>
        <?php
        $footer_cells = [
          ['lbl'=> xlt('Ins Bal'), 'val'=> mdi_money($total_ins_bal),                          'c'=>'#93c5fd'],
          ['lbl'=> xlt('Pat Bal'), 'val'=> mdi_money($total_pat_bal),                          'c'=>'#fcd34d'],
          ['lbl'=> xlt('Tot Bal'), 'val'=> mdi_money(max(0,$total_chg-$total_pmt-$total_adj)), 'c'=>'#fca5a5'],
          ['lbl'=> xlt('Ins Pd'),  'val'=> mdi_money($total_ins_pmt),                          'c'=>'#6ee7b7'],
          ['lbl'=> xlt('Pat Pd'),  'val'=> mdi_money($total_pat_pmt),                          'c'=>'#c4b5fd'],
        ];
        foreach ($footer_cells as $fc):
        ?>
        <div style="padding:7px 14px;border-left:1px solid rgba(255,255,255,.15);text-align:right;min-width:80px;">
          <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:rgba(255,255,255,.5);margin-bottom:2px;"><?php echo $fc['lbl']; ?></div>
          <div style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:<?php echo $fc['c']; ?>;"><?php echo $fc['val']; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php endif; // !empty($ledger_rows) ?>

</div><!-- /left table pane -->

<!-- ── RIGHT: Detail Panel ───────────────────────────────────────────────── -->
<div id="ledger-detail-panel" style="width:310px;flex-shrink:0;display:flex;flex-direction:column;border-left:none;background:var(--surface2);overflow-y:auto;">

  <!-- Empty state -->
  <div id="ldp-empty" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:20px;color:var(--text3);">
    <svg width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="opacity:.3;"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
    <div style="font-size:11px;text-align:center;line-height:1.5;"><?php echo xlt('Click any row to view details'); ?></div>
  </div>

  <!-- ── MBO Detail (Charge row) ── -->
  <div id="ldp-charge" style="display:none;padding:0;">
    <div style="background:var(--purple);padding:9px 12px;display:flex;align-items:center;justify-content:space-between;">
      <span style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Procedure Detail'); ?></span>
      <button onclick="ledgerCloseDetail()" style="background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:18px;line-height:1;padding:0;">×</button>
    </div>
    <div style="padding:10px 12px;overflow-y:auto;">

      <!-- Save banner -->
      <div id="ldp-save-banner" style="display:none;margin-bottom:8px;" class="alert"></div>

      <!-- CPT + Modifier (modifier editable) -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:10px 12px;margin-bottom:8px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:8px;">
          <div>
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);margin-bottom:3px;"><?php echo xlt('CPT Code'); ?></div>
            <div id="ldp-cpt-code" style="font-family:'DM Mono',monospace;font-size:20px;font-weight:800;color:var(--purple);"></div>
          </div>
          <div style="text-align:right;min-width:90px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);margin-bottom:3px;display:flex;align-items:center;justify-content:flex-end;gap:4px;">
              <?php echo xlt('Modifier'); ?>
              <svg width="9" height="9" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </div>
            <input type="text" id="ldp-modifier-input" maxlength="12" placeholder="—"
              style="width:100%;font-family:'DM Mono',monospace;font-size:14px;font-weight:700;text-align:right;border:1.5px solid var(--border);border-radius:4px;padding:4px 6px;outline:none;background:#faf5ff;"
              onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>
        </div>
        <div id="ldp-code-text" style="font-size:11px;color:var(--text2);border-top:1px solid var(--border);padding-top:6px;"></div>
      </div>

      <!-- Service Info -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:10px 12px;margin-bottom:8px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);margin-bottom:8px;border-bottom:1px solid var(--border);padding-bottom:4px;"><?php echo xlt('Service Info'); ?></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:11px;">
          <div><div style="color:var(--text3);font-size:10px;"><?php echo xlt('Date of Service'); ?></div><div id="ldp-dos" style="font-family:'DM Mono',monospace;font-weight:600;"></div></div>
          <div><div style="color:var(--text3);font-size:10px;"><?php echo xlt('POS'); ?></div><div id="ldp-pos" style="font-family:'DM Mono',monospace;font-weight:600;"></div></div>
          <div><div style="color:var(--text3);font-size:10px;"><?php echo xlt('Units'); ?></div><div id="ldp-units" style="font-family:'DM Mono',monospace;font-weight:600;"></div></div>
          <div><div style="color:var(--text3);font-size:10px;"><?php echo xlt('Fee'); ?></div><div id="ldp-fee" style="font-family:'DM Mono',monospace;font-weight:600;color:var(--purple);"></div></div>
          <div style="grid-column:1/-1;"><div style="color:var(--text3);font-size:10px;"><?php echo xlt('Carrier'); ?></div><div id="ldp-carrier" style="font-weight:600;"></div></div>
          <div style="grid-column:1/-1;">
            <div style="color:var(--text3);font-size:10px;"><?php echo xlt('Billed Date'); ?></div>
            <div id="ldp-billed-date" style="font-family:'DM Mono',monospace;font-weight:600;color:var(--green);"></div>
          </div>
        </div>
      </div>

      <!-- DX Codes (editable) -->
      <div style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:10px 12px;margin-bottom:8px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);margin-bottom:8px;border-bottom:1px solid var(--border);padding-bottom:4px;display:flex;align-items:center;justify-content:space-between;">
          <span><?php echo xlt('DX Codes'); ?></span>
          <svg width="9" height="9" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        </div>
        <div id="ldp-dx-list" style="font-size:11px;"></div>
        <!-- Add DX input -->
        <div style="margin-top:6px;display:flex;gap:5px;align-items:center;">
          <input type="text" id="ldp-dx-new-input" placeholder="<?php echo xla('Add DX code...'); ?>" maxlength="20"
            style="flex:1;border:1.5px solid var(--border);border-radius:4px;padding:5px 7px;font-family:'DM Mono',monospace;font-size:11px;outline:none;"
            onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'"
            onkeydown="if(event.key==='Enter'){event.preventDefault();ldpAddDxCode();}">
          <button type="button" onclick="ldpAddDxCode()"
            style="background:var(--purple);color:#fff;border:none;border-radius:4px;padding:5px 8px;font-size:11px;font-weight:700;cursor:pointer;font-family:inherit;">
            +
          </button>
        </div>
      </div>

      <!-- Save + Record Payment buttons -->
      <div style="display:flex;gap:6px;margin-bottom:4px;">
        <button type="button" id="ldp-save-btn" onclick="ldpSaveMbo()"
          style="flex:1;background:#0ca678;color:#fff;border:none;border-radius:var(--r-sm);padding:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:5px;">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          <?php echo xlt('Save MBO'); ?>
        </button>
        <button type="button" id="ldp-add-pmt-btn" onclick=""
          style="flex:1;background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:8px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:5px;">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          <?php echo xlt('Record Payment'); ?>
        </button>
      </div>
    </div>
  </div><!-- /ldp-charge -->

  <!-- ── Insurance Payment Detail ── -->
  <div id="ldp-payment" style="display:none;padding:0;">
    <div id="ldp-pmt-hdr" style="padding:8px 12px;display:flex;align-items:center;justify-content:space-between;">
      <span style="font-size:11px;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.05em;" id="ldp-pmt-hdr-label"><?php echo xlt('Payment Detail'); ?></span>
      <div style="display:flex;align-items:center;gap:6px;">
        <button id="ldp-edit-btn" onclick="ldpToggleEdit()" title="<?php echo xla('Edit payment'); ?>"
          style="background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);color:#fff;cursor:pointer;border-radius:4px;padding:3px 8px;font-size:11px;font-weight:600;display:none;">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" style="vertical-align:-1px;margin-right:3px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?php echo xlt('Edit'); ?></button>
        <button onclick="ledgerCloseDetail()" style="background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:16px;line-height:1;padding:0;">×</button>
      </div>
    </div>
    <div style="padding:12px;">
      <!-- Read-only detail rows -->
      <div id="ldp-pmt-view" style="background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:12px;margin-bottom:8px;">
        <div id="ldp-pmt-rows" style="font-size:12px;"></div>
      </div>

      <!-- ── Inline Edit Form (hidden by default) ── -->
      <div id="ldp-pmt-edit-form" style="display:none;background:#fff;border:2px solid var(--purple);border-radius:var(--r-sm);padding:14px;margin-bottom:8px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--purple);margin-bottom:10px;letter-spacing:.06em;"><?php echo xlt('Edit Payment'); ?></div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
          <div>
            <label style="display:block;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;"><?php echo xlt('Total Payment'); ?></label>
            <input type="number" id="ldp-edit-pay-total" step="0.01" min="0"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:13px;font-family:'DM Mono',monospace;font-weight:700;color:var(--purple);outline:none;box-sizing:border-box;"
              onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>
          <div>
            <label style="display:block;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;"><?php echo xlt('Applied to Patient'); ?></label>
            <input type="number" id="ldp-edit-applied" step="0.01" min="0"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:13px;font-family:'DM Mono',monospace;font-weight:700;color:var(--purple);outline:none;box-sizing:border-box;"
              onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
          <div>
            <label style="display:block;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;"><?php echo xlt('Check No.'); ?></label>
            <input type="text" id="ldp-edit-reference" maxlength="40" placeholder="—"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:'DM Mono',monospace;outline:none;box-sizing:border-box;"
              onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>
          <div>
            <label style="display:block;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;"><?php echo xlt('Check Date'); ?></label>
            <input type="date" id="ldp-edit-check-date"
              style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:'DM Mono',monospace;outline:none;box-sizing:border-box;"
              onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
          </div>
        </div>

        <div style="margin-bottom:12px;">
          <label style="display:block;font-size:10px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:3px;"><?php echo xlt('Payment Method'); ?></label>
          <select id="ldp-edit-method"
            style="width:100%;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:6px 8px;font-size:12px;font-family:inherit;outline:none;box-sizing:border-box;"
            onfocus="this.style.borderColor='var(--purple)'" onblur="this.style.borderColor='var(--border)'">
            <option value=""><?php echo xlt('— Select —'); ?></option>
            <option value="check"><?php echo xlt('Check'); ?></option>
            <option value="cash"><?php echo xlt('Cash'); ?></option>
            <option value="cc"><?php echo xlt('Credit Card'); ?></option>
            <option value="eft"><?php echo xlt('EFT'); ?></option>
            <option value="other"><?php echo xlt('Other'); ?></option>
          </select>
        </div>

        <div id="ldp-edit-banner" style="display:none;margin-bottom:8px;font-size:12px;padding:7px 10px;border-radius:var(--r-sm);"></div>

        <div style="display:flex;gap:8px;">
          <button type="button" onclick="ldpSaveEdit()"
            style="flex:1;background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:8px 0;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px;"
            id="ldp-edit-save-btn">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <?php echo xlt('Save Changes'); ?>
          </button>
          <button type="button" onclick="ldpToggleEdit()"
            style="background:var(--surface2);color:var(--text2);border:1px solid var(--border);border-radius:var(--r-sm);padding:8px 14px;font-size:12px;font-weight:600;cursor:pointer;">
            <?php echo xlt('Cancel'); ?>
          </button>
        </div>
      </div>
      <!-- ── End Edit Form ── -->

      <!-- Payment Details box (line items) -->
      <div id="ldp-pmt-details-wrap" style="display:none;background:#fff;border:1px solid var(--border);border-radius:var(--r-sm);padding:10px 12px;margin-bottom:8px;">
        <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text3);margin-bottom:6px;border-bottom:1px solid var(--border);padding-bottom:4px;"><?php echo xlt('Payment Details'); ?></div>
        <div id="ldp-pmt-details" style="font-size:11px;font-family:'DM Mono',monospace;white-space:pre-wrap;color:var(--text2);max-height:120px;overflow-y:auto;"></div>
      </div>
    </div>
  </div><!-- /ldp-payment -->

</div><!-- /detail panel -->

<?php
// Output JS data
echo '<script>var _ledgerRows = ' . json_encode($ledger_js_data) . ";\n";
echo 'var _ledgerRowBg = {};</script>';
?>

  </div><!-- /panel-ledger -->


  <!-- ════════ ADJUSTMENTS / WRITE-OFFS PANEL ════════ -->
  <div class="panel" id="panel-adjust">

    <?php if ($save_msg && (($_POST['post_adjustment'] ?? '') || ($_GET['tab'] ?? '') === 'adjust')): ?>
    <div class="alert alert-success"><?php echo text($save_msg); ?></div>
    <?php endif; ?>
    <?php if ($save_err && !empty($_POST['post_adjustment'])): ?>
    <div class="alert alert-error"><?php echo text($save_err); ?></div>
    <?php endif; ?>

    <div class="alert alert-info" style="margin-bottom:14px;">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;vertical-align:-2px;margin-right:4px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?php echo xlt('Use this tab to post adjustments or write-offs from an EOB. Enter amounts below to reduce charges or patient balances.'); ?>
    </div>

    <form method="post" id="adjust-form"
          action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=adjust"
          onsubmit="return validateAdjustment();">
      <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
      <input type="hidden" name="post_adjustment" value="1">
      <input type="hidden" name="adj_pid" value="<?php echo attr($pid); ?>">

      <div style="display:grid;grid-template-columns:340px 1fr;gap:14px;align-items:start;">

        <!-- ── LEFT COLUMN: Adjustment Details ── -->
        <div>
          <div class="card">
            <div class="card-header">
              <span class="card-title">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <?php echo xlt('Adjustment Details'); ?>
              </span>
            </div>
            <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">

              <!-- Row 1: Type + Reason + Encounter -->
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                <div class="form-group">
                  <label class="form-label"><?php echo xlt('Adjustment Type'); ?> <span style="color:var(--red);">*</span></label>
                  <select class="form-control" name="adj_type" id="adj_type_sel" onchange="adjTypeChanged(this.value)">
                    <option value="write_off"><?php echo xlt('Write-Off'); ?></option>
                    <option value="adjustment"><?php echo xlt('Adjustment'); ?></option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label"><?php echo xlt('Reason'); ?> <span style="color:var(--red);">*</span></label>
                  <select class="form-control" name="adj_reason" id="adj_reason_sel" onchange="adjSyncReasonToLines()">
                    <option value=""><?php echo xlt('-- Select --'); ?></option>
                    <option value="CONTRACT_ADJUSTMENT"><?php echo xlt('Contract Adjustment'); ?></option>
                    <option value="LIABILITY"><?php echo xlt('Liability'); ?></option>
                    <option value="LIABILITY_UNAPPROVED"><?php echo xlt('Liability and Unapproved'); ?></option>
                    <option value="OTHER"><?php echo xlt('Other'); ?></option>
                    <option value="SANCTIONED_FEE"><?php echo xlt('Sanctioned Fee'); ?></option>
                    <option value="REJECTED_BY_INS"><?php echo xlt('Services Rejected by Insurance'); ?></option>
                    <?php foreach ($adj_reason_codes as $arc): ?>
                    <option value="<?php echo attr($arc['option_id']); ?>"><?php echo text($arc['title']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label"><?php echo xlt('Apply to Encounter'); ?> <span style="color:var(--red);">*</span></label>
                  <select class="form-control" name="adj_encounter" id="adj_enc_sel" onchange="loadAdjLines(this.value)">
                    <option value=""><?php echo xlt('-- Select --'); ?></option>
                    <?php foreach ($encs as $er):
                      $bal_left = $er['charges'] - $er['ins_paid'] - $er['pat_paid'];
                    ?>
                    <option value="<?php echo attr($er['encounter']); ?>"
                      data-charges="<?php echo attr(number_format($er['charges'],2)); ?>"
                      data-bal="<?php echo attr(number_format(max(0,$bal_left),2)); ?>"
                      data-dos="<?php echo attr($er['encdate']); ?>">
                      <?php echo text($er['encdate']); ?> &mdash; Enc #<?php echo text($er['encounter']); ?>
                      (Bal: $<?php echo text(number_format(max(0,$bal_left),2)); ?>)
                    </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <!-- Row 2: Today's Payment + Adj Amount + Remaining Balance + Write-Off button -->
              <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;align-items:end;">
                <div class="form-group">
                  <label class="form-label"><?php echo xlt('Today\'s Payment'); ?></label>
                  <input type="text" class="form-control" id="adj-todays-pmt" value="$0.00" readonly
                    style="font-family:'DM Mono',monospace;background:var(--surface2);">
                </div>
                <div class="form-group">
                  <label class="form-label"><?php echo xlt('Adjustment Amount'); ?> <span style="color:var(--red);">*</span></label>
                  <input type="text" class="form-control" name="adj_invoice_amount" id="adj_invoice_amount"
                    placeholder="0.00" oninput="updateAdjTotals()"
                    style="font-family:'DM Mono',monospace;">
                </div>
                <div style="background:var(--surface2);border:1px solid var(--border);border-radius:var(--r-sm);padding:12px;display:flex;align-items:center;justify-content:space-between;gap:10px;">
                  <div>
                    <div class="form-label" style="margin-bottom:4px;"><?php echo xlt('Remaining Balance (After Adjustment)'); ?></div>
                    <div style="font-family:'DM Mono',monospace;font-size:20px;font-weight:700;color:var(--green);" id="adj-remaining-bal">$0.00</div>
                  </div>
                  <button type="button" id="adj-writeoff-btn"
                    onclick="adjWriteOffToZero()"
                    style="background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:10px 14px;font-size:12px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;white-space:nowrap;flex-shrink:0;">
                    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <?php echo xlt('Write-Off (Set to $0.00)'); ?>
                  </button>
                </div>
              </div>

              <!-- Apply Level (line / invoice) -->
              <div class="form-group">
                <label class="form-label"><?php echo xlt('Apply To Level'); ?> <span style="color:var(--red);">*</span></label>
                <div class="radio-row">
                  <label class="radio-label">
                    <input type="radio" name="adj_level" value="line" checked onchange="adjLevelChanged('line')">
                    <?php echo xlt('Line Item(s)'); ?>
                  </label>
                  <label class="radio-label">
                    <input type="radio" name="adj_level" value="invoice" onchange="adjLevelChanged('invoice')">
                    <?php echo xlt('Invoice Total'); ?>
                  </label>
                </div>
              </div>

              <hr style="border:none;border-top:1px solid var(--border);margin:2px 0;">

              <!-- Post As + Lock -->
              <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div class="form-group" style="margin:0;">
                  <label class="form-label"><?php echo xlt('Post As'); ?></label>
                  <select class="form-control" name="adj_post_as" style="min-width:180px;">
                    <option value="insurance"><?php echo xlt('Insurance Payment'); ?></option>
                    <option value="patient"><?php echo xlt('Patient Adjustment'); ?></option>
                  </select>
                </div>
                <label class="radio-label" style="font-size:12px;cursor:pointer;margin:0;">
                  <input type="checkbox" name="adj_lock" id="adj_lock" style="accent-color:var(--purple);" checked>
                  <span><?php echo xlt('Lock after save'); ?></span>
                </label>
              </div>

            </div>
          </div>
        </div>

        <!-- ── RIGHT COLUMN: Invoice / Encounter Summary + Line Items ── -->
        <div>

          <!-- Encounter Summary -->
          <div class="card" id="adj-enc-summary" style="display:none;">
            <div class="card-header">
              <span class="card-title"><?php echo xlt('Invoice / Encounter Summary'); ?></span>
            </div>
            <div class="card-body" style="padding:12px 16px;">
              <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;">
                <div>
                  <div class="form-label"><?php echo xlt('Invoice #'); ?></div>
                  <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:700;" id="adj-summary-inv">—</div>
                </div>
                <div>
                  <div class="form-label"><?php echo xlt('Service Date'); ?></div>
                  <div style="font-family:'DM Mono',monospace;font-size:13px;" id="adj-summary-dos">—</div>
                </div>
                <div>
                  <div class="form-label"><?php echo xlt('Insurance'); ?></div>
                  <div style="font-size:13px;" id="adj-summary-ins"><?php echo text($ins_name); ?></div>
                </div>
                <div>
                  <div class="form-label"><?php echo xlt('Provider'); ?></div>
                  <div style="font-size:13px;" id="adj-summary-prov">—</div>
                </div>
                <div>
                  <div class="form-label"><?php echo xlt('Patient'); ?></div>
                  <div style="font-size:13px;"><?php echo text($pat_name); ?></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Line Items table (for line-level mode) -->
          <div class="card" id="adj-lines-card" style="display:none;">
            <div class="card-header">
              <span class="card-title"><?php echo xlt('Line Items'); ?></span>
              <div style="display:flex;align-items:center;gap:10px;">
                <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text2);cursor:pointer;">
                  <input type="checkbox" id="adj-show-zero" onchange="adjFilterZero(this.checked)"
                    style="accent-color:var(--purple);">
                  <?php echo xlt('Show zero balances'); ?>
                </label>
                <button type="button" class="btn btn-sm btn-outline"
                  onclick="adjCollapseAll()"
                  title="<?php echo xla('Collapse all expanded CPTs'); ?>">
                  <?php echo xlt('Collapse All'); ?>
                </button>
              </div>
            </div>
            <div style="overflow-x:auto;">
              <table class="dt" id="adj-lines-table" style="table-layout:fixed;width:100%;">
                <thead>
                  <tr>
                    <th style="width:30px;">
                      <input type="checkbox" id="adj-check-all" onchange="adjToggleAll(this.checked)"
                        style="accent-color:var(--purple);cursor:pointer;">
                    </th>
                    <th style="min-width:200px;"><?php echo xlt('CPT / Description'); ?></th>
                    <th class="r" style="width:90px;"><?php echo xlt('Fee'); ?></th>
                    <th class="r" style="width:140px;color:var(--green);"><?php echo xlt('Insurance Payment'); ?></th>
                    <th class="r" style="width:130px;"><?php echo xlt('Patient Payment'); ?></th>
                    <th class="r" style="width:110px;color:var(--amber);"><?php echo xlt('Adjustment'); ?></th>
                    <th class="r" style="width:120px;"><?php echo xlt('Patient Balance'); ?></th>
                  </tr>
                </thead>
                <tbody id="adj-lines-tbody">
                  <tr id="adj-lines-empty">
                    <td colspan="7" style="text-align:center;color:var(--text3);padding:20px;">
                      <?php echo xlt('Select an encounter above to load line items.'); ?>
                    </td>
                  </tr>
                </tbody>
            
              </table>
            </div>
          </div>

          <!-- Empty state when no enc selected -->
          <div id="adj-empty-state" style="text-align:center;padding:50px 20px;color:var(--text3);">
            <svg width="40" height="40" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 10px;display:block;opacity:.4;"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
            <div style="font-size:13px;"><?php echo xlt('Select an encounter from the left panel to load line items and post an adjustment.'); ?></div>
          </div>

        </div>
      </div><!-- /grid -->

      <!-- Action buttons -->
      <div style="display:flex;justify-content:flex-end;gap:8px;padding:8px 0 4px;">
        <button type="button" class="btn btn-outline" onclick="adjReset()"><?php echo xlt('Cancel'); ?></button>
        <button type="button" class="btn btn-outline" onclick="switchTab('ledger')">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          <?php echo xlt('View Transaction Ledger'); ?>
        </button>
        <button type="submit" class="btn btn-primary" id="adj-post-btn">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          <?php echo xlt('Post Adjustment'); ?>
        </button>
        <button type="submit" class="btn btn-success" name="adj_close_after" value="1" id="adj-post-close-btn">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          <?php echo xlt('Post & Close'); ?>
        </button>
      </div>

    </form>
  </div><!-- /panel-adjust -->


</div><!-- /page -->

<script>
// ── Tab switching ──
// ── Ledger Detail Panel JS ────────────────────────────────────────────────────
var _ldpSelectedKey = null;

function ledgerRowHoverOut(el, key) {
    if (key !== _ldpSelectedKey) {
        var bg = _ledgerRowBg[key] || '#fff';
        el.style.background = bg;
    }
}

function setSource(source)
{
    document.getElementById('payment_source').value = source;

    document.getElementById('src-ins').classList.toggle('active', source === 'insurance');
    document.getElementById('src-pat').classList.toggle('active', source === 'patient');

    document.querySelectorAll('[data-source-only]').forEach(function(el) {
        el.style.display = (el.dataset.sourceOnly === source) ? '' : 'none';
    });

    var paymentLabel = document.getElementById('paymentAmountLabel');
    if (paymentLabel) {
        paymentLabel.textContent = source === 'insurance'
            ? '<?php echo xlt('Amount Paid'); ?>'
            : '<?php echo xlt('Patient Payment'); ?>';
    }

    document.querySelectorAll('.insurance-only-col').forEach(function(el) {
        el.style.display = source === 'insurance' ? '' : 'none';
    });

    document.querySelectorAll('.patient-only-col').forEach(function(el) {
        el.style.display = source === 'patient' ? '' : 'none';
    });

    if (typeof eobRecalc === 'function') {
        eobRecalc();
    }
}

function setSource(type)
{
    document.getElementById('src-ins').classList.remove('active');
    document.getElementById('src-pat').classList.remove('active');

    if (type === 'insurance') {
        document.getElementById('src-ins').classList.add('active');

        document.getElementById('insuranceFields').style.display = '';
        document.getElementById('patientFields').style.display   = 'none';
    } else {
        document.getElementById('src-pat').classList.add('active');

        document.getElementById('insuranceFields').style.display = 'none';
        document.getElementById('patientFields').style.display   = '';
    }
}

function ledgerCloseDetail() {
    document.getElementById('ldp-empty').style.display   = '';
    document.getElementById('ldp-charge').style.display  = 'none';
    document.getElementById('ldp-payment').style.display = 'none';
    if (_ldpSelectedKey) {
        var prev = document.getElementById('lrow-' + _ldpSelectedKey);
        if (prev) {
            prev.classList.remove('selected');
            prev.style.background = _ledgerRowBg[_ldpSelectedKey] || '#fff';
        }
    }
    _ldpSelectedKey = null;
}

function ledgerDeleteRow(btn) {
    var msg = <?php echo json_encode(xlt('Are you sure you want to delete this payment, including any transfers, adjustments, etc.?')); ?>;
    if (!confirm(msg)) return;

    var pid = btn.dataset.pid || <?php echo (int)$pid; ?>;
    var enc = btn.dataset.enc || '';
    var seq = btn.dataset.seq || '';

    btn.disabled = true;
    btn.textContent = '...';

    var fd = new FormData();
    fd.append('action',          'delete_activity_ajax');
    fd.append('csrf_token_form', <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>);
    fd.append('del_pid',         pid);
    fd.append('del_enc',         enc);
    fd.append('del_seq',         seq);

    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.success) {
                // Remove the row visually
                var tr = btn.closest('tr');
                if (tr) {
                    tr.style.transition = 'opacity .3s';
                    tr.style.opacity = '0';
                    setTimeout(function(){ tr.remove(); }, 320);
                }
            } else {
                btn.disabled = false;
                btn.textContent = <?php echo json_encode(xl('Del')); ?>;
                alert(d.error || <?php echo json_encode(xlt('Delete failed.')); ?>);
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = <?php echo json_encode(xl('Del')); ?>;
            alert(<?php echo json_encode(xlt('Network error.')); ?>);
        });
}

function ledgerSelectRow(key) {
    // Deselect previous
    if (_ldpSelectedKey && _ldpSelectedKey !== key) {
        var prev = document.getElementById('lrow-' + _ldpSelectedKey);
        if (prev) {
            prev.classList.remove('selected');
            prev.style.background = _ledgerRowBg[_ldpSelectedKey] || '#fff';
        }
    }
    _ldpSelectedKey = key;

    // Highlight selected row
    var el = document.getElementById('lrow-' + key);
    if (el) {
        if (!_ledgerRowBg[key]) _ledgerRowBg[key] = el.style.background;
        el.classList.add('selected');
        el.style.background = '#ddd6fe';
    }

    // Find data
    var row = null;
    for (var i = 0; i < _ledgerRows.length; i++) {
        if (_ledgerRows[i].row_key === key) { row = _ledgerRows[i]; break; }
    }
    if (!row) return;

    document.getElementById('ldp-empty').style.display = 'none';
    document.getElementById('ldp-charge').style.display = 'none';
    document.getElementById('ldp-payment').style.display = 'none';

    if (row.type === 'charge') {
        ldpShowCharge(row);
    } else {
        ldpShowTransaction(row);
    }
}

function ldpShowCharge(row) {
    document.getElementById('ldp-charge').style.display = 'block';

    // Store current row data on panel for save
    document.getElementById('ldp-charge').dataset.billingId  = row.billing_id || '';
    document.getElementById('ldp-charge').dataset.encId      = row.enc_id || '';
    document.getElementById('ldp-charge').dataset.pid        = <?php echo (int)$pid; ?>;

    document.getElementById('ldp-cpt-code').textContent  = row.code || '—';
    document.getElementById('ldp-modifier-input').value  = row.modifier || '';
    document.getElementById('ldp-code-text').textContent = row.code_text || '';
    document.getElementById('ldp-dos').textContent       = row.enc_date || '—';
    document.getElementById('ldp-pos').textContent       = row.pos_code || '11';
    document.getElementById('ldp-units').textContent     = row.units || '1';
    document.getElementById('ldp-fee').textContent       = '$' + parseFloat(row.fee || 0).toFixed(2);
    document.getElementById('ldp-carrier').textContent   = row.ins_name || <?php echo json_encode(xl('Self Pay')); ?>;
    var billedEl = document.getElementById('ldp-billed-date');
    billedEl.textContent = row.bill_date || <?php echo json_encode(xl('Not Billed')); ?>;
    billedEl.style.color = row.bill_date ? 'var(--green)' : 'var(--text3)';

    // Hide save banner
    var banner = document.getElementById('ldp-save-banner');
    banner.style.display = 'none';

    // DX codes (editable)
    _ldpCurrentDxCodes = row.dx_codes ? row.dx_codes.slice() : [];
    ldpRenderDxList();

    // Wire up add payment button
    var btn = document.getElementById('ldp-add-pmt-btn');
    if (btn) {
        btn.onclick = function() { openEobForEnc(row.enc_id); };
    }
}

var _ldpCurrentDxCodes = [];

function ldpRenderDxList() {
    var dxEl = document.getElementById('ldp-dx-list');
    dxEl.innerHTML = '';
    if (_ldpCurrentDxCodes.length > 0) {
        _ldpCurrentDxCodes.forEach(function(dx, i) {
            var d = document.createElement('div');
            d.style.cssText = 'display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid #f0f0f0;';
            d.innerHTML = '<span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:3px;font-size:9px;font-weight:800;background:#dbeafe;color:#1e40af;flex-shrink:0;">'+(i+1)+'</span>'
                        + '<span style="font-family:\'DM Mono\',monospace;font-size:11px;font-weight:600;flex:1;">'+escHtml(dx)+'</span>'
                        + '<button type="button" onclick="ldpRemoveDx('+i+')" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:14px;line-height:1;padding:0 2px;" title="Remove">×</button>';
            dxEl.appendChild(d);
        });
    } else {
        dxEl.innerHTML = '<span style="color:var(--text3);font-size:11px;"><?php echo addslashes(xlt('No DX codes — add one below')); ?></span>';
    }
}

function ldpAddDxCode() {
    var inp = document.getElementById('ldp-dx-new-input');
    var val = inp.value.trim().toUpperCase().replace(/[^A-Z0-9.]/g, '');
    if (!val) return;
    if (_ldpCurrentDxCodes.indexOf(val) === -1) {
        _ldpCurrentDxCodes.push(val);
        ldpRenderDxList();
    }
    inp.value = '';
    inp.focus();
}

function ldpRemoveDx(idx) {
    _ldpCurrentDxCodes.splice(idx, 1);
    ldpRenderDxList();
}

function ldpSaveMbo() {
    var panel    = document.getElementById('ldp-charge');
    var billingId = panel.dataset.billingId;
    var encId    = panel.dataset.encId;
    var pidVal   = panel.dataset.pid;
    var modifier = document.getElementById('ldp-modifier-input').value.trim().toUpperCase();
    var dxStr    = _ldpCurrentDxCodes.join(':');
    var banner   = document.getElementById('ldp-save-banner');
    var saveBtn  = document.getElementById('ldp-save-btn');

    if (!billingId) {
        banner.className = 'alert alert-error';
        banner.textContent = <?php echo json_encode(xlt('No billing ID — cannot save.')); ?>;
        banner.style.display = 'block';
        return;
    }

    saveBtn.disabled = true;
    saveBtn.textContent = <?php echo json_encode(xlt('Saving...')); ?>;

    var fd = new FormData();
    fd.append('action',          'save_mbo_ajax');
    fd.append('csrf_token_form', <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>);
    fd.append('mbo_pid',         pidVal);
    fd.append('mbo_enc',         encId);
    fd.append('mbo_billing_id',  billingId);
    fd.append('mbo_modifier',    modifier);
    fd.append('mbo_dx_codes',    dxStr);

    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            banner.style.display = 'block';
            if (d.success) {
                banner.className  = 'alert alert-success';
                banner.textContent = d.message || <?php echo json_encode(xlt('Saved.')); ?>;
                // Update local data cache
                for (var i = 0; i < _ledgerRows.length; i++) {
                    if (_ledgerRows[i].billing_id == billingId) {
                        _ledgerRows[i].modifier  = modifier;
                        _ledgerRows[i].dx_codes  = _ldpCurrentDxCodes.slice();
                    }
                }
                setTimeout(function(){ banner.style.display = 'none'; }, 2500);
            } else {
                banner.className  = 'alert alert-error';
                banner.textContent = d.error || <?php echo json_encode(xlt('Save failed.')); ?>;
            }
        })
        .catch(function() {
            banner.className  = 'alert alert-error';
            banner.textContent = <?php echo json_encode(xlt('Network error.')); ?>;
            banner.style.display = 'block';
        })
        .finally(function() {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?php echo addslashes(xlt('Save MBO')); ?>';
        });
}

function ldpShowTransaction(row) {
    document.getElementById('ldp-payment').style.display = 'block';

    // Store current row for edit
    document.getElementById('ldp-payment').dataset.row = JSON.stringify(row);

    // Show/hide edit button — only for payment types that have a session
    var editBtn = document.getElementById('ldp-edit-btn');
    var canEdit = (row.type === 'payment') && (parseInt(row.session_id) > 0);
    editBtn.style.display = canEdit ? '' : 'none';

    // Reset edit form visibility when switching rows
    document.getElementById('ldp-pmt-edit-form').style.display = 'none';
    document.getElementById('ldp-pmt-view').style.display = '';
    document.getElementById('ldp-edit-banner').style.display = 'none';
    var hdr = document.getElementById('ldp-pmt-hdr');
    var lbl = document.getElementById('ldp-pmt-hdr-label');
    var typeColors = {
        'payment':    { bg: '#166534', label: <?php echo json_encode(xl('Insurance Payment')); ?> },
        'writeoff':   { bg: '#ea580c', label: <?php echo json_encode(xl('Insurance Write-Off')); ?> },
        'adjustment': { bg: '#991b1b', label: <?php echo json_encode(xl('Adjustment')); ?> },
        'transfer':   { bg: '#1e40af', label: <?php echo json_encode(xl('Transfer to Patient')); ?> },
    };
    var tc = typeColors[row.type] || typeColors['payment'];
    if (!row.is_ins && row.type === 'payment') { tc = { bg: '#5b21b6', label: <?php echo json_encode(xl('Patient Payment')); ?> }; }
    hdr.style.background = tc.bg;
    lbl.textContent = tc.label;

    // Build detail rows
    var rows = document.getElementById('ldp-pmt-rows');
    rows.innerHTML = '';

    function addRow(label, value, mono, bold) {
        var d = document.createElement('div');
        d.className = 'ldp-detail-row';
        d.innerHTML = '<span class="ldp-detail-lbl">' + escHtml(label) + '</span>'
                    + '<span class="ldp-detail-val" style="'+(mono?'font-family:\'DM Mono\',monospace;':'')+(bold?'font-weight:700;':'')+'">'
                    + escHtml(String(value || '—')) + '</span>';
        rows.appendChild(d);
    }

    if (row.type === 'writeoff') {
        addRow(<?php echo json_encode(xlt('Procedure')); ?>,      row.code || row.procedure_name || '—');
        addRow(<?php echo json_encode(xlt('Carrier')); ?>,        row.ins_name || row.carrier || '—');
        addRow(<?php echo json_encode(xlt('Transaction Date')); ?>, row.post_date || '—', true);
        addRow(<?php echo json_encode(xlt('Write-off Amount')); ?>, '$'+parseFloat(row.amount||0).toFixed(2), true, true);
        addRow(<?php echo json_encode(xlt('Type')); ?>,           row.memo || <?php echo json_encode(xl('Contract Adjustment')); ?>);
    } else if (row.type === 'transfer') {
        addRow(<?php echo json_encode(xlt('Procedure')); ?>,      row.code || '—');
        addRow(<?php echo json_encode(xlt('Transaction Date')); ?>, row.post_date || '—', true);
        addRow(<?php echo json_encode(xlt('Transfer Amount')); ?>, '$'+parseFloat(row.amount||0).toFixed(2), true, true);
        addRow(<?php echo json_encode(xlt('To')); ?>,             row.memo || <?php echo json_encode(xl('Patient')); ?>);
    } else if (row.type === 'adjustment') {
        addRow(<?php echo json_encode(xlt('Procedure')); ?>,      row.code || '—');
        addRow(<?php echo json_encode(xlt('Transaction Date')); ?>, row.post_date || '—', true);
        addRow(<?php echo json_encode(xlt('Adjustment Amount')); ?>, '$'+parseFloat(row.amount||0).toFixed(2), true, true);
        addRow(<?php echo json_encode(xlt('Reason')); ?>,         row.memo || '—');
    } else {
        // Payment (insurance or patient)
        addRow(<?php echo json_encode(xlt('Carrier')); ?>,        row.ins_name || (row.is_ins ? '—' : <?php echo json_encode(xl('Patient')); ?>));
        addRow(<?php echo json_encode(xlt('Date')); ?>,           row.post_date || '—', true);
        addRow(<?php echo json_encode(xlt('Total Payment')); ?>,  '$'+parseFloat(row.pay_total||0).toFixed(2), true);
        addRow(<?php echo json_encode(xlt('Applied to Patient')); ?>, '$'+parseFloat(row.amount||0).toFixed(2), true, true);
        if (row.type === 'payment') {
            addRow(<?php echo json_encode(xlt('Type')); ?>,       '');
        }
        addRow(<?php echo json_encode(xlt('Check No.')); ?>,      row.reference || '—', true);
        addRow(<?php echo json_encode(xlt('Check Date')); ?>,     row.check_date || '—', true);
        addRow(<?php echo json_encode(xlt('User')); ?>,           row.posted_by || <?php echo json_encode(xl('Administrator')); ?>);
    }

    // Payment details (line items from ledger context — same enc)
    var detailsWrap = document.getElementById('ldp-pmt-details-wrap');
    var detailsEl   = document.getElementById('ldp-pmt-details');
    // Build details: find all payment rows for same enc + session
    if (row.type === 'payment' && row.session_id > 0) {
        var lines = _ledgerRows.filter(function(r) {
            return r.type === 'payment' && r.enc_id === row.enc_id;
        });
        if (lines.length > 0) {
            var txt = lines.map(function(l) {
                return (l.post_date || '—') + ' : ' + (l.code || '—') + '   $' + parseFloat(l.amount||0).toFixed(2);
            }).join('\n');
            detailsEl.textContent = txt;
            detailsWrap.style.display = 'block';
        } else {
            detailsWrap.style.display = 'none';
        }
    } else {
        detailsWrap.style.display = 'none';
    }
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Payment Detail Edit ───────────────────────────────────────────────────────
function ldpToggleEdit() {
    var formEl  = document.getElementById('ldp-pmt-edit-form');
    var viewEl  = document.getElementById('ldp-pmt-view');
    var isOpen  = formEl.style.display !== 'none';

    if (isOpen) {
        formEl.style.display = 'none';
        viewEl.style.display = '';
        return;
    }

    // Pre-populate from stored row
    var panel = document.getElementById('ldp-payment');
    var row;
    try { row = JSON.parse(panel.dataset.row || '{}'); } catch(e) { row = {}; }

    document.getElementById('ldp-edit-pay-total').value  = parseFloat(row.pay_total || 0).toFixed(2);
    document.getElementById('ldp-edit-applied').value    = parseFloat(row.amount    || 0).toFixed(2);
    document.getElementById('ldp-edit-reference').value  = row.reference   || '';
    document.getElementById('ldp-edit-check-date').value = row.check_date  || '';
    document.getElementById('ldp-edit-method').value     = row.pay_method  || '';
    document.getElementById('ldp-edit-banner').style.display = 'none';

    viewEl.style.display  = 'none';
    formEl.style.display  = '';
}

function ldpSaveEdit() {
    var panel   = document.getElementById('ldp-payment');
    var row;
    try { row = JSON.parse(panel.dataset.row || '{}'); } catch(e) { row = {}; }

    var sessionId  = parseInt(row.session_id || 0);
    if (!sessionId) { ldpEditBanner('error', <?php echo json_encode(xlt('No session ID — cannot save.')); ?>); return; }

    var payTotal   = parseFloat(document.getElementById('ldp-edit-pay-total').value  || 0);
    var applied    = parseFloat(document.getElementById('ldp-edit-applied').value    || 0);
    var reference  = document.getElementById('ldp-edit-reference').value.trim();
    var checkDate  = document.getElementById('ldp-edit-check-date').value.trim();
    var method     = document.getElementById('ldp-edit-method').value;

    if (isNaN(payTotal) || payTotal < 0) { ldpEditBanner('error', <?php echo json_encode(xlt('Total payment must be a valid amount.')); ?>); return; }

    var saveBtn = document.getElementById('ldp-edit-save-btn');
    saveBtn.disabled = true;
    saveBtn.innerHTML = '<?php echo addslashes(xlt('Saving...')); ?>';

    var fd = new FormData();
    fd.append('action',           'edit_payment_detail_ajax');
    fd.append('csrf_token_form',  <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>);
    fd.append('epd_pid',          <?php echo (int)$pid; ?>);
    fd.append('epd_session_id',   sessionId);
    fd.append('epd_pay_total',    payTotal.toFixed(2));
    fd.append('epd_applied',      applied.toFixed(2));
    fd.append('epd_reference',    reference);
    fd.append('epd_check_date',   checkDate);
    fd.append('epd_method',       method);

    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.success) {
                // Update cached row
                row.pay_total    = parseFloat(d.new_total   || payTotal);
                row.amount       = parseFloat(d.new_applied || applied);
                row.reference    = d.new_ref       || reference;
                row.check_date   = d.new_check_date || checkDate;
                panel.dataset.row = JSON.stringify(row);

                // Update _ledgerRows cache so panel re-renders correctly
                for (var i = 0; i < _ledgerRows.length; i++) {
                    if (_ledgerRows[i].session_id == sessionId) {
                        _ledgerRows[i].pay_total   = row.pay_total;
                        _ledgerRows[i].amount      = row.amount;
                        _ledgerRows[i].reference   = row.reference;
                        _ledgerRows[i].check_date  = row.check_date;
                    }
                }

                ldpEditBanner('success', d.message || <?php echo json_encode(xlt('Saved.')); ?>);
                // Refresh the read-only view
                setTimeout(function() {
                    document.getElementById('ldp-pmt-edit-form').style.display = 'none';
                    document.getElementById('ldp-pmt-view').style.display = '';
                    ldpShowTransaction(row);
                }, 1200);
            } else {
                ldpEditBanner('error', d.error || <?php echo json_encode(xlt('Save failed.')); ?>);
            }
        })
        .catch(function() {
            ldpEditBanner('error', <?php echo json_encode(xlt('Network error.')); ?>);
        })
        .finally(function() {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?php echo addslashes(xlt('Save Changes')); ?>';
        });
}

function ldpEditBanner(type, msg) {
    var el = document.getElementById('ldp-edit-banner');
    el.style.display      = '';
    el.style.background   = (type === 'success') ? '#dcfce7' : '#fee2e2';
    el.style.color        = (type === 'success') ? '#166534' : '#991b1b';
    el.style.border       = '1px solid ' + ((type === 'success') ? '#86efac' : '#fca5a5');
    el.textContent        = msg;
}
// ── End Payment Detail Edit ───────────────────────────────────────────────────

function switchTab(name) {
    // Reset ledger detail panel when switching away
    if (name !== 'ledger' && typeof ledgerCloseDetail === 'function') {
        ledgerCloseDetail();
    }
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    var panel = document.getElementById('panel-' + name);
    if (panel) panel.classList.add('active');
    var rb = document.getElementById('btn-refund');
    if (rb) {
        rb.style.background = (name === 'refund') ? '#dc2626' : '#fff0f0';
        rb.style.color      = (name === 'refund') ? '#fff'    : '#dc2626';
    }
}

// ── Reason codes per Post As ──────────────────────────────────────────────────
var _insReasons = <?php echo json_encode([
    ['v'=>'CONTRACT_ADJUSTMENT',  'l'=>xl('Contract Adjustment')],
    ['v'=>'LIABILITY',            'l'=>xl('Liability')],
    ['v'=>'LIABILITY_UNAPPROVED', 'l'=>xl('Liability and Unapproved')],
    ['v'=>'SANCTIONED_FEE',       'l'=>xl('Sanctioned Fee')],
    ['v'=>'REJECTED_BY_INS',      'l'=>xl('Services Rejected by Insurance')],
    ['v'=>'OTHER',                'l'=>xl('Other')],
]); ?>;
var _patReasons = <?php echo json_encode([
    ['v'=>'BAD_DEBT',          'l'=>xl('Bad Debt')],
    ['v'=>'BK_DISCOUNT',       'l'=>xl('BK Discount')],
    ['v'=>'COURTESY_DISCOUNT', 'l'=>xl('Courtesy Discount')],
    ['v'=>'COURTESY_WRITEOFF', 'l'=>xl('Courtesy Write-Off')],
    ['v'=>'PROMOTIONAL',       'l'=>xl('Promotional')],
    ['v'=>'SHOULDNT_BILL',     'l'=>xl("Shouldn't Have Been Billed At All")],
    ['v'=>'UNAPPROVED_AMOUNT', 'l'=>xl('Unapproved Amount')],
    ['v'=>'OTHER',             'l'=>xl('Other')],
]); ?>;
// Merge any list_options adjreason codes
<?php
$extra = [];
foreach ($adj_reason_codes as $arc) {
    $extra[] = ['v' => $arc['option_id'], 'l' => $arc['title']];
}
echo 'var _extraReasons = ' . json_encode($extra) . ';';
?>
var _customReasons = [];

function populateReasonDropdown() {
    var postAs = (document.querySelector('[name="adj_post_as"]') || {}).value || 'insurance';
    var sel = document.getElementById('adj_reason_sel');
    if (!sel) return;
    var cur = sel.value;
    sel.innerHTML = '<option value="">' + <?php echo json_encode(xlt('-- Select --')); ?> + '</option>';
    var list = (postAs === 'patient' ? _patReasons : _insReasons)
                   .concat(_extraReasons)
                   .concat(_customReasons);
    list.forEach(function(r) {
        var opt = document.createElement('option');
        opt.value = r.v; opt.textContent = r.l;
        if (r.v === cur) opt.selected = true;
        sel.appendChild(opt);
    });
}

function adjAddCustomReason() {
    var txt = prompt(<?php echo json_encode(xlt('Enter custom reason:')); ?>);
    if (!txt || !txt.trim()) return;
    _customReasons.push({v: 'CUSTOM_' + Date.now(), l: txt.trim()});
    populateReasonDropdown();
    var sel = document.getElementById('adj_reason_sel');
    if (sel) sel.selectedIndex = sel.options.length - 1;
}

document.addEventListener('change', function(e) {
    if (e.target && e.target.name === 'adj_post_as') populateReasonDropdown();
});
document.addEventListener('DOMContentLoaded', function() {
    populateReasonDropdown();
    // Auto-select first row in refund panel
    var firstRadio = document.querySelector('#refund-payment-list input[type="radio"]');
    if (firstRadio) {
        var sid = parseInt(firstRadio.value);
        if (sid) {
            _rfndSelectedSession = sid;
            _rfndSelectedAmount  = parseFloat(firstRadio.dataset.amount) || 0;
            updateRefundSummaryCard(
                firstRadio.dataset.date      || '—',
                firstRadio.dataset.reference || '—',
                firstRadio.dataset.payer     || '—',
                firstRadio.dataset.method    || '—',
                _rfndSelectedAmount
            );
            var row = document.getElementById('rfnd-row-' + sid);
            if (row) row.style.background = '#faf7ff';
        }
    }
});

// ── Refund / Issue Refund ──────────────────────────────────────────────────
var _rfndSelectedSession = 0;
var _rfndSelectedAmount  = 0;

function selectReversePayment(sessionId) {
    _rfndSelectedSession = sessionId;
    document.querySelectorAll('[id^="rfnd-row-"]').forEach(function(r){ r.style.background = ''; });
    var row = document.getElementById('rfnd-row-' + sessionId);
    if (row) row.style.background = '#faf7ff';
    var radio = document.getElementById('rfnd-radio-' + sessionId);
    if (radio) {
        radio.checked = true;
        _rfndSelectedAmount = parseFloat(radio.dataset.amount) || 0;
        updateRefundSummaryCard(
            radio.dataset.date      || '—',
            radio.dataset.reference || '—',
            radio.dataset.payer     || '—',
            radio.dataset.method    || '—',
            _rfndSelectedAmount
        );
    }
}

function updateRefundSummaryCard(date, ref, payer, meth, amt) {
    var card = document.getElementById('rfnd-summary-card');
    if (!card) return;
    card.innerHTML =
        '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">'
        + '<div><div style="font-size:10px;color:var(--text3);margin-bottom:2px;"><?php echo addslashes(xlt("Payment Date")); ?></div><div style="font-size:12px;font-weight:600;font-family:\'DM Mono\',monospace;">' + escH(date) + '</div></div>'
        + '<div><div style="font-size:10px;color:var(--text3);margin-bottom:2px;"><?php echo addslashes(xlt("Reference #")); ?></div><div style="font-size:12px;font-weight:600;font-family:\'DM Mono\',monospace;color:var(--purple);">' + escH(ref) + '</div></div>'
        + '<div><div style="font-size:10px;color:var(--text3);margin-bottom:2px;"><?php echo addslashes(xlt("Payer")); ?></div><div style="font-size:12px;font-weight:600;">' + escH(payer) + '</div></div>'
        + '<div><div style="font-size:10px;color:var(--text3);margin-bottom:2px;"><?php echo addslashes(xlt("Method")); ?></div><div style="font-size:12px;font-weight:600;">' + escH(meth) + '</div></div>'
        + '<div style="grid-column:span 2;"><div style="font-size:10px;color:var(--text3);margin-bottom:2px;"><?php echo addslashes(xlt("Amount Posted")); ?></div><div style="font-size:16px;font-weight:700;color:var(--green);font-family:\'DM Mono\',monospace;">$' + parseFloat(amt).toFixed(2) + '</div></div>'
        + '</div>';
    var impAmt   = document.getElementById('rfnd-impact-amount');
    var impAfter = document.getElementById('rfnd-impact-after');
    var curBal   = <?php echo json_encode(abs($rfnd_cur_bal ?? 0)); ?>;
    if (impAmt)   impAmt.textContent  = '+$' + parseFloat(amt).toFixed(2);
    if (impAfter) impAfter.textContent = '$' + (curBal + parseFloat(amt)).toFixed(2);
}

function submitReverseRow(sessionId) {
    if (!sessionId) { alert(<?php echo json_encode(xlt('Please select a payment to reverse.')); ?>); return; }
    var reason   = (document.getElementById('rfnd-reason-sel')  || {}).value || 'Posted in Error';
    var category = (document.getElementById('rfnd-category-sel') || {}).value || '';
    if (!confirm(<?php echo json_encode(xlt('Reverse this payment? This cannot be undone.')); ?>)) return;
    var fullReason = reason + (category ? ' – ' + category : '');
    var btn = document.getElementById('rfnd-confirm-btn');
    if (btn) { btn.disabled = true; btn.textContent = '<?php echo addslashes(xlt("Processing...")); ?>'; }
    var fd = new FormData();
    fd.append('action',          'post_refund_ajax');
    fd.append('csrf_token_form', <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>);
    fd.append('r_pid',           <?php echo (int)$pid; ?>);
    fd.append('r_session_id',    String(sessionId));
    fd.append('r_amount',        _rfndSelectedAmount > 0 ? _rfndSelectedAmount.toFixed(2) : '0');
    fd.append('r_reason',        fullReason);
    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            var banner = document.getElementById('reverse-result-banner');
            if (banner) {
                banner.className = 'alert ' + (d.success ? 'alert-success' : 'alert-error');
                banner.textContent = d.success ? d.message : (d.error || '<?php echo addslashes(xlt("Unknown error.")); ?>');
                banner.style.display = '';
                banner.scrollIntoView({behavior:'smooth', block:'nearest'});
            }
            if (d.success) { setTimeout(function(){ location.reload(); }, 1800); }
            else if (btn) { btn.disabled = false; btn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.86"/></svg> <?php echo addslashes(xlt("Issue Refund")); ?>'; }
        })
        .catch(function(e){ alert(<?php echo json_encode(xlt('Network error.')); ?>); console.error(e); if(btn){btn.disabled=false;} });
}

// ── Add custom refund reason ──────────────────────────────────────────────
function rfndAddReason() {
    var val = prompt(<?php echo json_encode(xlt('Enter a custom refund reason:')); ?>, '');
    if (!val || !val.trim()) return;
    val = val.trim().substring(0, 100);
    var sel = document.getElementById('rfnd-reason-sel');
    if (!sel) return;
    // Check if it already exists
    for (var i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === val) { sel.value = val; return; }
    }
    var opt = document.createElement('option');
    opt.value = val;
    opt.text  = val;
    sel.appendChild(opt);
    sel.value = val;
}

// Legacy stubs
function openRefundModal()  {}
function closeRefundModal() {}
function submitRefund()     {}

// ── Source toggle ──
function setSource(src) {
    document.getElementById('src-ins').classList.toggle('active', src==='insurance');
    document.getElementById('src-pat').classList.toggle('active', src==='patient');
    var trBtn = null; // Transfer tab removed — now inside Adjustments/Writeoffs/Transfers
    if (trBtn) trBtn.classList.toggle('active', src==='transfer');

    // Update hidden field
    var hidSrc = document.getElementById('form_source_type');
    if (hidSrc) hidSrc.value = src;

    // Update badge label
    var badge = document.getElementById('ins-badge');
    if (badge) badge.textContent = src === 'insurance'
        ? '<?php echo addslashes(text($ins_name)); ?>'
        : '<?php echo addslashes(xlt('Patient')); ?>';

    // Show/hide sub-panels via the single source of truth
    if (src === 'transfer') {
        activatePaymentMode('transfer');
    } else {
        activatePaymentMode('payment');
    }
}

function doTransfer(encId) {
    var sel = document.getElementById('tr-dest-' + encId);
    var btn = document.getElementById('tr-btn-' + encId);
    if (!sel || !sel.value) { alert(<?php echo json_encode(xlt('Please select a transfer destination.')); ?>); return; }
    var dest     = sel.value;
    var destLabel = sel.options[sel.selectedIndex].text;
    if (!confirm(<?php echo json_encode(xlt('Transfer this encounter balance?')); ?> + ' → ' + destLabel)) return;

    if (btn) { btn.disabled = true; btn.textContent = '<?php echo addslashes(xlt("Transferring...")); ?>'; }

    var fd = new FormData();
    fd.append('action',          'transfer_balance_ajax');
    fd.append('csrf_token_form', <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>);
    fd.append('tr_pid',          <?php echo (int)$pid; ?>);
    fd.append('tr_enc',          String(encId));
    fd.append('tr_to',           dest);

    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            var res = document.getElementById('transfer-result');
            if (res) {
                res.className = 'alert ' + (d.success ? 'alert-success' : 'alert-error');
                res.textContent = d.success ? d.message : (d.error || '<?php echo addslashes(xlt("Error")); ?>');
                res.style.display = '';
            }
            if (d.success) { setTimeout(function(){ location.reload(); }, 1200); }
            else if (btn) { btn.disabled = false; btn.textContent = '<?php echo addslashes(xlt("Transfer")); ?>'; }
        })
        .catch(function(e) {
            alert(<?php echo json_encode(xlt('Network error.')); ?>);
            console.error(e);
            if (btn) { btn.disabled = false; btn.textContent = '<?php echo addslashes(xlt("Transfer")); ?>'; }
        });
}

// ── Pre-pay toggle ──
function togglePrepay(radio) {
    var show = radio.checked && radio.value==='pre_payment';
    document.getElementById('prepay-row').style.display = show ? '' : 'none';
    document.getElementById('enc-table-card').style.display = show ? 'none' : '';
}


// ── Coverage change ──
function updateCoverage(radio) {
    // mirror to the src toggle visuals
    if (radio.value === 'self') setSource('patient');
}

// ── Calc total ──
function calcTotal() {
    ptRecalcAll();
}

// ── Full patient payment recalculation ────────────────────────────────────────
// Reads all pay-input, pt-adj-input, pt-xfer-ins-chk rows.
// Updates per-row Remaining display and the footer summary bar.
function ptRecalcAll() {
    var totalPay  = 0;
    var totalAdj  = 0;
    var totalXfer = 0;
    var totalBal  = 0;

    document.querySelectorAll('[id^="erow_"]').forEach(function(mainRow) {
        // skip hidden rows (focus mode)
        if (mainRow.style.display === 'none') return;

        var payInp  = mainRow.querySelector('.pay-input');
        var adjInp  = mainRow.querySelector('.pt-adj-input');
        var xferChk = mainRow.querySelector('.pt-xfer-ins-chk');
        var remainEl = mainRow.querySelector('.pt-remaining-display');

        var ptDue   = parseFloat((payInp && payInp.dataset.ptDue)  || 0);
        var paying  = parseFloat((payInp && payInp.value)          || 0);
        var adj     = parseFloat((adjInp && adjInp.value)          || 0);
        var xfer    = 0;

        if (xferChk && xferChk.checked) {
            // Transfer amount = what the adj field says, or the leftover if blank
            if (adj > 0) {
                xfer = adj;
                // Don't double-count adj and xfer — xfer uses the adj amount
                adj  = 0;
            } else {
                xfer = Math.max(0, ptDue - paying);
            }
        }

        totalBal  += ptDue;
        totalPay  += paying;
        totalAdj  += adj;
        totalXfer += xfer;

        // Per-row remaining
        var remaining = Math.max(0, Math.round((ptDue - paying - adj - xfer) * 100) / 100);
        if (remainEl) {
            remainEl.textContent = '$' + remaining.toFixed(2);
            remainEl.style.color = remaining < 0.01 ? 'var(--green)' : 'var(--red)';
        }

        // Also update the Remaining column cell (4th numeric cell in the row)
        var remainCell = mainRow.querySelector('[data-pt-remaining]');
        if (remainCell) {
            remainCell.textContent = remaining.toFixed(2);
            remainCell.style.color = remaining < 0.01 ? 'var(--green)' : 'var(--red)';
            remainCell.style.fontWeight = remaining > 0 ? '700' : '400';
        }
    });

    totalPay  = Math.round(totalPay  * 100) / 100;
    totalAdj  = Math.round(totalAdj  * 100) / 100;
    totalXfer = Math.round(totalXfer * 100) / 100;
    totalBal  = Math.round(totalBal  * 100) / 100;

    var unalloc = Math.round((totalBal - totalPay - totalAdj - totalXfer) * 100) / 100;
    var isBalanced = Math.abs(unalloc) < 0.01 && (totalPay > 0 || totalAdj > 0 || totalXfer > 0);

    // Update footer elements
    var setEl = function(id, val, prefix) {
        var el = document.getElementById(id);
        if (el) el.textContent = (prefix||'$') + Math.abs(val).toFixed(2);
    };
    setEl('pt-pay-total',        totalPay);
    setEl('pt-adj-total-footer', totalAdj);
    setEl('pt-xfer-total',       totalXfer);

    var unallocEl = document.getElementById('pt-unalloc');
    if (unallocEl) {
        unallocEl.textContent = (unalloc < 0 ? '-$' : '$') + Math.abs(unalloc).toFixed(2);
        unallocEl.style.color = isBalanced ? '#6ee7b7' : (unalloc > 0 ? '#fca5a5' : '#fcd34d');
    }

    var badge = document.getElementById('pt-balanced-badge');
    if (badge) badge.style.display = isBalanced ? 'inline' : 'none';

    // Keep legacy form_paytotal for POST submission
    var legacyTotal = document.getElementById('form_paytotal');
    if (legacyTotal) legacyTotal.value = totalPay.toFixed(2);

    // Keep main-amount display in sync
    var mainAmt = document.getElementById('main-amount');
    if (mainAmt) mainAmt.value = '$' + totalPay.toFixed(2);
}

// Called from paying input or adj input oninput
function ptLineCalc(inp) {
    var mainRow = inp.closest('tr');
    if (!mainRow) { ptRecalcAll(); return; }

    var payInp  = mainRow.querySelector('.pay-input');
    var adjInp  = mainRow.querySelector('.pt-adj-input');
    var xferChk = mainRow.querySelector('.pt-xfer-ins-chk');
    var toCollect = mainRow.querySelector('.pt-to-collect');

    var ptRemaining = parseFloat((payInp && payInp.dataset.ptRemaining) || 0);
    var paying  = parseFloat((payInp && payInp.value) || 0);
    var adj     = parseFloat((adjInp && adjInp.value) || 0);
    var xfer    = (xferChk && xferChk.checked) ? (adj > 0 ? adj : Math.max(0, ptRemaining - paying)) : 0;

    var toCollectAmt = Math.max(0, Math.round((ptRemaining - paying - adj - xfer) * 100) / 100);

    if (toCollect) {
        toCollect.textContent = '$' + toCollectAmt.toFixed(2);
        toCollect.style.color = toCollectAmt < 0.01 ? 'var(--green)' : 'var(--red)';
    }

    // If xfer is checked, update the xfer amount display
    var amtDiv = mainRow.querySelector('.pt-xfer-ins-amt');
    if (xferChk && xferChk.checked && amtDiv) {
        var xferShow = adj > 0 ? adj : Math.max(0, ptRemaining - paying);
        amtDiv.textContent = '$' + xferShow.toFixed(2);
    }

    ptRecalcAll();
}

// ── Transfer → Insurance checkbox ─────────────────────────────────────────────
function ptXferInsChange(chk) {
    var mainRow = chk.closest('tr');
    if (!mainRow) return;

    var amtDiv  = mainRow.querySelector('.pt-xfer-ins-amt');
    var adjInp  = mainRow.querySelector('.pt-adj-input');
    var rsnSel  = mainRow.querySelector('.pt-adj-reason-input');
    var payInp  = mainRow.querySelector('.pay-input');

    if (chk.checked) {
        var ptDue   = parseFloat((payInp && payInp.dataset.ptDue) || 0);
        var paying  = parseFloat((payInp && payInp.value) || 0);
        var adj     = parseFloat((adjInp && adjInp.value) || 0);
        var xferAmt = adj > 0 ? adj : Math.max(0, ptDue - paying);

        // Show transfer amount badge
        if (amtDiv) {
            amtDiv.textContent = '$' + xferAmt.toFixed(2);
            amtDiv.style.display = 'block';
        }
        // Pre-fill adj if empty
        if (adjInp && !adjInp.value) {
            adjInp.value = xferAmt.toFixed(2);
            adjInp.style.borderColor = '#f59e0b';
            adjInp.style.background  = '#fffbeb';
        }
        // Default reason
        if (rsnSel && !rsnSel.value) rsnSel.value = 'Contractual';

    } else {
        if (amtDiv) { amtDiv.style.display = 'none'; amtDiv.textContent = ''; }
        if (adjInp) { adjInp.value = ''; adjInp.style.borderColor = 'var(--border)'; adjInp.style.background = ''; }
        if (rsnSel) rsnSel.value = '';
    }

    ptRecalcAll();
}

// Reason dropdown change — auto-fill adj if blank based on remaining, then recalc
function ptAdjReasonChange(sel) {
    var mainRow = sel.closest('tr');
    if (!mainRow) { ptRecalcAll(); return; }

    var adjInp = mainRow.querySelector('.pt-adj-input');
    var payInp = mainRow.querySelector('.pay-input');

    if (sel.value && adjInp && !adjInp.value) {
        var ptDue     = parseFloat((payInp && payInp.dataset.ptDue) || 0);
        var paying    = parseFloat((payInp && payInp.value) || 0);
        var remaining = Math.max(0, Math.round((ptDue - paying) * 100) / 100);
        if (remaining > 0) {
            adjInp.value = remaining.toFixed(2);
            adjInp.style.borderColor = '#f59e0b';
            adjInp.style.background  = '#fffbeb';
        }
    }
    if (!sel.value && adjInp) {
        adjInp.style.borderColor = 'var(--border)';
        adjInp.style.background  = '';
    }

    ptRecalcAll();
}

function syncAmount(el) {
    // main-amount is readonly — only for display
}

// ── Auto apply ──
function autoApply() {
    var isSelfPay = document.getElementById('src-pat').classList.contains('active');
    document.querySelectorAll('input[name^="form_upay["]').forEach(function(inp){
        var bal = parseFloat(isSelfPay ? inp.dataset.ptDue : inp.dataset.insBal)||0;
        inp.value = bal>0 ? bal.toFixed(2) : '';
    });
    calcTotal();
}

// ── Validate ──
function validatePayment() {

var total  = parseFloat(document.getElementById('form_paytotal')?.value || 0);
var prepay = parseFloat(document.getElementById('form_prepayment')?.value || 0);

var xfer = parseFloat(
    (document.getElementById('pt-xfer-total')?.textContent || '0')
        .replace(/[^0-9.-]/g,'')
) || 0;

if (total === 0 && prepay === 0 && xfer === 0) {
    alert('Enter a payment or transfer amount.');
    return false;
}

top.restoreSession && top.restoreSession();
return confirm('Post this payment?');
}

// ── Encounter toggle ──
function toggleEnc(idx) {
    var body = document.getElementById('encbody-'+idx);
    var chev = document.getElementById('chev-'+idx);
    var open = body.classList.toggle('open');
    chev.style.transform = open ? 'rotate(90deg)' : 'rotate(0deg)';
}

// ── Edit payment inline iframe drawer ────────────────────────────────────────
var mdiEditBase = '<?php echo $GLOBALS['webroot']; ?>/interface/billing/edit_payment.php';
var mdiOpenDrawer = null; // track currently open session_id

function mdiToggleEditDrawer(sessionId, btn) {
    top.restoreSession && top.restoreSession();

    var drawer  = document.getElementById('sr-drawer-' + sessionId);
    var iframe  = document.getElementById('sr-iframe-' + sessionId);
    var chev    = document.getElementById('edit-chev-' + sessionId);
    var isOpen  = drawer.style.display !== 'none';

    // Close any previously open drawer
    if (mdiOpenDrawer && mdiOpenDrawer !== sessionId) {
        var prev = document.getElementById('sr-drawer-' + mdiOpenDrawer);
        var prevIframe = document.getElementById('sr-iframe-' + mdiOpenDrawer);
        var prevChev = document.getElementById('edit-chev-' + mdiOpenDrawer);
        if (prev)   prev.style.display = 'none';
        if (prevIframe) { prevIframe.style.height = '0'; prevIframe.src = ''; }
        if (prevChev) prevChev.style.transform = 'rotate(0deg)';
    }

    if (isOpen) {
        // Close this drawer
        drawer.style.display = 'none';
        iframe.style.height  = '0';
        iframe.src           = '';
        chev.style.transform = 'rotate(0deg)';
        mdiOpenDrawer = null;
    } else {
        // Open this drawer
        drawer.style.display = '';
        chev.style.transform = 'rotate(180deg)';
        mdiOpenDrawer = sessionId;
        // Load the edit page — ParentPage=mdi so it knows to skip the outer nav
        iframe.src = mdiEditBase + '?payment_id=' + encodeURIComponent(sessionId) + '&ParentPage=mdi&embedded=1';
        iframe.style.height = '520px';
    }
}

// ── Delete payment ────────────────────────────────────────────────────────────
function mdiDeletePayment(sessionId) {
    if (!confirm('<?php echo addslashes(xlj('Are you sure you want to permanently delete this payment? This action cannot be undone.')); ?>')) {
        return;
    }
    top.restoreSession && top.restoreSession();
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = window.location.href;
    var fields = {
        csrf_token_form: '<?php echo addslashes(CsrfUtils::collectCsrfToken()); ?>',
        delete_payment: '1',
        delete_session_id: sessionId,
        tab: 'search',
        search_payments: '1'
    };
    for (var k in fields) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = fields[k];
        form.appendChild(inp);
    }
    // Preserve current search params so results reload
    var sf = document.getElementById('search-form');
    if (sf) {
        var sfData = new FormData(sf);
        sfData.forEach(function(v, k) {
            var inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = k; inp.value = v;
            form.appendChild(inp);
        });
    }
    document.body.appendChild(form);
    form.submit();
}

// ── Navigate parent frame then close popup ───────────────────────────────────
function mdiOpenBillingPage(url) {
    top.restoreSession && top.restoreSession();
    if (top && top.left_nav && typeof top.left_nav.loadFrame === 'function') {
        top.left_nav.loadFrame('pay1', 'pay', url.replace(/.*\/interface\//, ''));
    } else if (top !== window) {
        top.location.href = url;
    } else if (opener) {
        opener.location.href = url;
    } else {
        window.open(url, '_blank');
    }
    setTimeout(function() {
        try { if (typeof dlgclose === 'function') dlgclose(); else window.close(); } catch(e) { window.close(); }
    }, 150);
}

// ── Payment method changes — cash hides reference, CC shows CC fields ──────────
function onMethodChange(val) {
    var refWrap = document.getElementById('ref-field-wrap');
    var ccWrap  = document.getElementById('cc-fields-wrap');
    var isCash  = (val === 'cash');
    var isCC    = (val === 'credit_card');

    // Reference field: visible for Check and Credit Card, hidden for Cash
    if (refWrap) refWrap.style.display = isCash ? 'none' : '';

    // Credit card fields: only for credit_card
    if (ccWrap)  ccWrap.style.display  = isCC   ? '' : 'none';

    // Legacy CC button support (Sphere / Stripe)
    var ccBtn  = document.getElementById('cc-btn-wrap');
    var posBtn = document.getElementById('pos-btn-wrap');
    if (ccBtn)  ccBtn.style.display  = isCC ? 'inline-flex' : 'none';
    if (posBtn) posBtn.style.display = isCC ? 'inline-flex' : 'none';
}

// Keep legacy alias for any existing calls
function toggleCCButton(val) { onMethodChange(val); }

// ── Encounter row expand — shows payment detail inline ─────────────────────────
var _encDetailLoaded = {};
var _encDetailOpen   = {};

// ── Focus a single encounter row (hide others) — mirrors insurance EOB behavior ─
var _encFocusIdx = null;
function focusEncRow(idx) {
    // If already focused on this one, toggle off (show all)
    if (_encFocusIdx === idx) {
        _encFocusIdx = null;
        document.querySelectorAll('[id^="erow_"]').forEach(function(r){ r.style.display = ''; });
        document.querySelectorAll('[id^="enc-detail-"]').forEach(function(r){
            if (!_encDetailOpen[r.id.replace('enc-detail-','')])
                r.style.display = 'none';
        });
        return;
    }
    _encFocusIdx = idx;
    // Hide all encounter rows except the focused one and its detail row
    document.querySelectorAll('[id^="erow_"]').forEach(function(r){
        var rowIdx = r.id.replace('erow_','');
        r.style.display = (rowIdx == idx) ? '' : 'none';
    });
    document.querySelectorAll('[id^="enc-detail-"]').forEach(function(r){
        var rowIdx = r.id.replace('enc-detail-','');
        if (rowIdx != idx) r.style.display = 'none';
    });
}

function toggleEncDetail(idx, encId) {
    var detailRow   = document.getElementById('enc-detail-' + idx);
    var detailInner = document.getElementById('enc-detail-inner-' + idx);
    var chev        = document.getElementById('enc-chev-' + idx);
    if (!detailRow) return;

    var isOpen = _encDetailOpen[idx];
    if (isOpen) {
        detailRow.style.display = 'none';
        if (chev) chev.style.transform = 'rotate(0deg)';
        _encDetailOpen[idx] = false;
        return;
    }

    detailRow.style.display = '';
    if (chev) chev.style.transform = 'rotate(90deg)';
    _encDetailOpen[idx] = true;

    if (_encDetailLoaded[idx]) return;
    _encDetailLoaded[idx] = true;

    var pid = <?php echo (int)$pid; ?>;
    // Use inline endpoint — no dependency on mdi_adj_lines.php
    fetch('mdi_payments_ledger.php?pid=' + pid + '&action=get_enc_lines&enc=' + encId + '&csrf=<?php echo urlencode(CsrfUtils::collectCsrfToken()); ?>')
        .then(function(r){ return r.json(); })
        .catch(function(){ return null; })
        .then(function(data) {
            if (!data) {
                detailInner.innerHTML = '<div style="color:var(--red);font-size:12px;padding:8px 0;">Failed to load.</div>';
                return;
            }
            var lines = data.lines   || [];
            var pmts  = data.payments|| [];

            if (!lines.length && !pmts.length) {
                detailInner.innerHTML = '<div style="color:var(--text3);font-size:12px;padding:8px 0;"><?php echo addslashes(xlt('No line items found for this encounter.')); ?></div>';
                return;
            }

            // Build per-code maps from payments
            var codeMap = {};   // {code: {insPaid, insMethod, insName, patPaid, patMethod, adj, adjMemo, adjType, adjRows:[]}}
            pmts.forEach(function(p) {
                var code = (p.code || '_').trim();
                if (!codeMap[code]) codeMap[code] = {insPaid:0,insMethod:'',insName:'',patPaid:0,patMethod:'',adj:0,adjMemo:'',adjType:'',adjRows:[]};
                var ac    = (p.account_code || '').toUpperCase();
                var isAdj = (ac==='WO'||ac==='AJ'||ac==='CO'||ac==='RFND');
                var amt   = parseFloat(p.pay_amount) || 0;
                var aamt  = parseFloat(p.adj_amount) || 0;
                if (isAdj) {
                    var adjAmt = Math.abs(aamt) + (amt > 0 ? 0 : 0); // adj_amount holds the value
                    if (Math.abs(aamt) > 0) adjAmt = Math.abs(aamt);
                    else adjAmt = Math.abs(amt);
                    codeMap[code].adj += adjAmt;
                    if (!codeMap[code].adjMemo && p.memo) codeMap[code].adjMemo = p.memo;
                    if (!codeMap[code].adjType) codeMap[code].adjType = ac;
                    codeMap[code].adjRows.push(p);
                } else if (parseInt(p.payer_type) > 0) {
                    codeMap[code].insPaid += amt;
                    if (!codeMap[code].insMethod && p.payment_method) codeMap[code].insMethod = p.payment_method;
                    if (!codeMap[code].insName   && p.ins_name)       codeMap[code].insName   = p.ins_name;
                } else {
                    codeMap[code].patPaid += amt;
                    if (!codeMap[code].patMethod && p.payment_method) codeMap[code].patMethod = p.payment_method;
                }
            });

            var html = '<div style="overflow-x:auto;">';

            if (lines.length) {
                html += '<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:6px;">';
                html += '<thead><tr style="background:var(--surface2);border-bottom:2px solid var(--border);">'
                      + '<th style="width:20px;padding:7px 8px;"></th>'
                      + '<th style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo addslashes(xlt('CPT')); ?></th>'
                      + '<th style="padding:7px 10px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo addslashes(xlt('Description')); ?></th>'
                      + '<th style="padding:7px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo addslashes(xlt('Fee')); ?></th>'
                      + '<th style="padding:7px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--green);"><?php echo addslashes(xlt('Ins Payment')); ?></th>'
                      + '<th style="padding:7px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo addslashes(xlt('Pt Payment')); ?></th>'
                      + '<th style="padding:7px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--amber);"><?php echo addslashes(xlt('Adjustment')); ?></th>'
                      + '<th style="padding:7px 10px;text-align:right;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);"><?php echo addslashes(xlt('Balance')); ?></th>'
                      + '</tr></thead><tbody>';

                var lineIdx = 0;
                lines.forEach(function(l) {
                    var charge  = parseFloat(l.charge) || 0;
                    var paid    = parseFloat(l.paid)   || 0;
                    var adj     = parseFloat(l.adj)    || 0;
                    var lp      = codeMap[l.code] || codeMap['_'] || {};
                    var insPaid = lp.insPaid || 0;
                    var patPaid = lp.patPaid || 0;
                    var adjAmt  = lp.adj > 0 ? lp.adj : adj;
                    var insM    = lp.insMethod || '';
                    var patM    = lp.patMethod || '';
                    if (insPaid + patPaid === 0 && paid > 0) insPaid = paid;
                    var bal = Math.max(0, charge - insPaid - patPaid - adjAmt);
                    var hasAdj = adjAmt > 0.005;
                    var detId = 'enc-adj-det-' + idx + '-' + lineIdx;

                    // Main line row
                    html += '<tr style="border-bottom:1px solid var(--border);">'
                          + '<td style="padding:7px 8px;text-align:center;">'
                          + (hasAdj ? '<span style="cursor:pointer;color:var(--purple);font-size:10px;" onclick="toggleAdjDetail(\'' + detId + '\',this)">&#9654;</span>' : '')
                          + '</td>'
                          + '<td style="padding:7px 10px;font-family:\'DM Mono\',monospace;font-weight:700;color:var(--purple);">' + escH(l.code) + '</td>'
                          + '<td style="padding:7px 10px;font-size:11px;color:var(--text2);">' + escH(l.description) + '</td>'
                          + '<td style="padding:7px 10px;text-align:right;font-family:\'DM Mono\',monospace;font-weight:700;">$' + charge.toFixed(2) + '</td>'
                          + '<td style="padding:7px 10px;text-align:right;">'
                          +   '<div style="font-family:\'DM Mono\',monospace;font-size:12px;font-weight:600;color:' + (insPaid>0?'var(--green)':'var(--text3)') + ';">$' + insPaid.toFixed(2) + '</div>'
                          +   (insM ? '<div style="font-size:10px;color:var(--text3);">' + escH(insM) + '</div>' : '')
                          + '</td>'
                          + '<td style="padding:7px 10px;text-align:right;">'
                          +   '<div style="font-family:\'DM Mono\',monospace;font-size:12px;font-weight:600;color:' + (patPaid>0?'var(--green)':'var(--text3)') + ';">$' + patPaid.toFixed(2) + '</div>'
                          +   (patM ? '<div style="font-size:10px;color:var(--text3);">' + escH(patM) + '</div>' : '')
                          + '</td>'
                          + '<td style="padding:7px 10px;text-align:right;font-family:\'DM Mono\',monospace;color:' + (adjAmt>0?'var(--amber)':'var(--text3)') + ';">$' + adjAmt.toFixed(2) + '</td>'
                          + '<td style="padding:7px 10px;text-align:right;font-family:\'DM Mono\',monospace;font-weight:700;color:' + (bal>0.005?'var(--red)':'var(--green)') + ';">$' + bal.toFixed(2) + '</td>'
                          + '</tr>';

                    // Adj detail row (collapsed)
                    if (hasAdj && lp.adjRows && lp.adjRows.length) {
                        html += '<tr id="' + detId + '" style="display:none;background:#fffbeb;">';
                        html += '<td colspan="8" style="padding:0 10px 10px 30px;">';
                        html += '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--amber);margin:8px 0 6px;"><?php echo addslashes(xlt('Adjustment Detail')); ?></div>';
                        html += '<table style="width:100%;border-collapse:collapse;font-size:11px;border:1px solid #fde68a;border-radius:6px;overflow:hidden;">';
                        html += '<thead><tr style="background:#fef9c3;">'
                              + '<th style="padding:5px 8px;text-align:left;font-size:10px;font-weight:700;color:var(--amber);"><?php echo addslashes(xlt('Date')); ?></th>'
                              + '<th style="padding:5px 8px;text-align:left;font-size:10px;font-weight:700;color:var(--text2);"><?php echo addslashes(xlt('Type')); ?></th>'
                              + '<th style="padding:5px 8px;text-align:left;font-size:10px;font-weight:700;color:var(--text2);"><?php echo addslashes(xlt('Carrier')); ?></th>'
                              + '<th style="padding:5px 8px;text-align:right;font-size:10px;font-weight:700;color:var(--amber);"><?php echo addslashes(xlt('Write-Off Amt')); ?></th>'
                              + '<th style="padding:5px 8px;text-align:right;font-size:10px;font-weight:700;color:var(--text2);"><?php echo addslashes(xlt('Other Adj')); ?></th>'
                              + '<th style="padding:5px 8px;text-align:left;font-size:10px;font-weight:700;color:var(--text2);"><?php echo addslashes(xlt('Reason')); ?></th>'
                              + '</tr></thead><tbody>';

                        lp.adjRows.forEach(function(ar) {
                            var ac2    = (ar.account_code || '').toUpperCase();
                            var isIns  = parseInt(ar.payer_type) > 0;
                            var isWO   = (ac2 === 'WO');
                            var woAmt  = isWO ? (Math.abs(parseFloat(ar.adj_amount)||0) || Math.abs(parseFloat(ar.pay_amount)||0)) : 0;
                            var othAmt = !isWO ? (Math.abs(parseFloat(ar.adj_amount)||0) || Math.abs(parseFloat(ar.pay_amount)||0)) : 0;
                            var typeLabel = isIns
                                ? (isWO ? '<?php echo addslashes(xlt("Insurance Write-Off")); ?>' : '<?php echo addslashes(xlt("Insurance Adjustment")); ?>')
                                : (isWO ? '<?php echo addslashes(xlt("Patient Write-Off")); ?>'   : '<?php echo addslashes(xlt("Patient Adjustment")); ?>');
                            html += '<tr style="border-top:1px solid #fde68a;">'
                                  + '<td style="padding:5px 8px;font-family:\'DM Mono\',monospace;font-size:11px;">' + escH((ar.post_time||'').substring(0,10)) + '</td>'
                                  + '<td style="padding:5px 8px;"><span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:' + (isIns?'#dbeafe':'#fce7f3') + ';color:' + (isIns?'#1d4ed8':'#9d174d') + ';">' + typeLabel + '</span></td>'
                                  + '<td style="padding:5px 8px;font-size:11px;color:var(--text2);">' + escH(ar.ins_name || (isIns ? '—' : '<?php echo addslashes(xlt("Patient")); ?>')) + '</td>'
                                  + '<td style="padding:5px 8px;text-align:right;font-family:\'DM Mono\',monospace;color:var(--amber);font-weight:700;">' + (woAmt>0 ? '$'+woAmt.toFixed(2) : '—') + '</td>'
                                  + '<td style="padding:5px 8px;text-align:right;font-family:\'DM Mono\',monospace;color:var(--amber);">' + (othAmt>0 ? '$'+othAmt.toFixed(2) : '—') + '</td>'
                                  + '<td style="padding:5px 8px;font-size:11px;color:var(--text2);">' + escH(ar.adj_reason_label || ar.memo || '—') + '</td>'
                                  + '</tr>';
                        });
                        html += '</tbody></table></td></tr>';
                    }
                    lineIdx++;
                });
                html += '</tbody></table>';
            }

            // Payments summary (non-adjustment, non-PR-memo)
            var regPmts = pmts.filter(function(p){ var ac=(p.account_code||'').toUpperCase(); return ac!=='WO'&&ac!=='AJ'&&ac!=='CO'&&ac!=='RFND'&&ac!=='PR'; });
            if (regPmts.length) {
                html += '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text3);margin:8px 0 4px;"><?php echo addslashes(xlt('Payment History')); ?></div>';
                html += '<table style="width:100%;border-collapse:collapse;font-size:11px;border:1px solid var(--border);border-radius:6px;overflow:hidden;">';
                html += '<thead><tr style="background:var(--surface2);">'
                      + '<th style="padding:6px 8px;text-align:left;font-size:10px;color:var(--text2);"><?php echo addslashes(xlt('Date')); ?></th>'
                      + '<th style="padding:6px 8px;text-align:left;font-size:10px;color:var(--text2);"><?php echo addslashes(xlt('Type')); ?></th>'
                      + '<th style="padding:6px 8px;text-align:left;font-size:10px;color:var(--text2);"><?php echo addslashes(xlt('Method')); ?></th>'
                      + '<th style="padding:6px 8px;text-align:left;font-size:10px;color:var(--text2);"><?php echo addslashes(xlt('Reference')); ?></th>'
                      + '<th style="padding:6px 8px;text-align:right;font-size:10px;color:var(--green);"><?php echo addslashes(xlt('Amount')); ?></th>'
                      + '</tr></thead><tbody>';
                regPmts.forEach(function(p) {
                    var isIns = parseInt(p.payer_type) > 0;
                    html += '<tr style="border-top:1px solid var(--border);">'
                          + '<td style="padding:5px 8px;font-family:\'DM Mono\',monospace;">' + escH((p.post_time||'').substring(0,10)) + '</td>'
                          + '<td style="padding:5px 8px;"><span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:' + (isIns?'#dbeafe':'#f0fdf4') + ';color:' + (isIns?'#1d4ed8':'#15803d') + ';">' + (isIns?'<?php echo addslashes(xlt("Insurance")); ?>':'<?php echo addslashes(xlt("Patient")); ?>') + '</span></td>'
                          + '<td style="padding:5px 8px;">' + escH(p.payment_method || '—') + '</td>'
                          + '<td style="padding:5px 8px;font-family:\'DM Mono\',monospace;">' + escH(p.reference || '—') + '</td>'
                          + '<td style="padding:5px 8px;text-align:right;font-family:\'DM Mono\',monospace;color:var(--green);font-weight:700;">$' + (parseFloat(p.pay_amount)||0).toFixed(2) + '</td>'
                          + '</tr>';
                });
                html += '</tbody></table>';
            }

            html += '</div>';
            detailInner.innerHTML = html;
        });
}

function toggleAdjDetail(detId, chev) {
    var row = document.getElementById(detId);
    if (!row) return;
    var open = row.style.display !== 'none';
    row.style.display = open ? 'none' : '';
    chev.innerHTML = open ? '&#9654;' : '&#9660;';
}

function collapseAllEncs() {
    Object.keys(_encDetailOpen).forEach(function(idx) {
        if (_encDetailOpen[idx]) {
            var dr = document.getElementById('enc-detail-' + idx);
            var ch = document.getElementById('enc-chev-' + idx);
            if (dr) dr.style.display = 'none';
            if (ch) ch.style.transform = 'rotate(0deg)';
            _encDetailOpen[idx] = false;
        }
    });
}

// ── Adjustment inline section toggle ──────────────────────────────────────────
function toggleAdjSection() {
    var sec  = document.getElementById('adj-inline-section');
    var chev = document.getElementById('adj-toggle-chev');
    var open = sec.style.display !== 'none';
    sec.style.display = open ? 'none' : '';
    if (chev) chev.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    // Load all encounter lines when first opened
    if (!open && !_adjAllLoaded) {
        loadAllAdjLines();
    }
}

// ── Submit inline adjustment ──────────────────────────────────────────────────

// ── Sync Amount header field → transfer amount ────────────────────────────────
function adjSyncAmountToTransfer(val) {
    var hidden = document.getElementById('tc-transfer-amount');
    if (hidden) hidden.value = val;
    adjUpdateTransferSummary();
}

// ── Transfer type toggle (patient / insurance) ────────────────────────────────
function adjTransferTypeChanged(val) {
    var insWrap = document.getElementById('tc-insurance-wrap');
    var toLbl   = document.getElementById('tc-sum-to-label');
    if (val === 'insurance') {
        if (insWrap) insWrap.style.display = 'block';
        if (toLbl)   toLbl.textContent = '<?php echo addslashes(xlt("To Insurance")); ?>';
    } else {
        if (insWrap) insWrap.style.display = 'none';
        if (toLbl)   toLbl.textContent = '<?php echo addslashes(xlt("To Patient")); ?>';
    }
}

// ── adj_type dropdown change — show/hide transfer subpanel ────────────────────
function adjTypeOrTransferChanged(val) {
    var transferPanel  = document.getElementById('transferSlideout');
    var linesCard      = document.getElementById('adj-lines-card');
    var reasonWrap     = document.getElementById('adj_reason_sel') ? document.getElementById('adj_reason_sel').closest('.form-group') || document.getElementById('adj_reason_sel').parentNode : null;
    var amtNote        = document.getElementById('adj-transfer-note');
    var saveLabel      = document.getElementById('adj-save-label');
    var saveIcon       = document.getElementById('adj-save-icon');

    if (val === 'transfer') {
        if (transferPanel) transferPanel.style.display = 'block';
        if (amtNote) amtNote.style.display = 'inline';
        if (saveLabel) saveLabel.textContent = '<?php echo addslashes(xlt("Save Transfer")); ?>';
        // Sync current Amount field value into transfer summary
        var curAmt = document.getElementById('adj_invoice_amount');
        if (curAmt) adjSyncAmountToTransfer(curAmt.value);
        adjLoadAvailableCredit();
    } else {
        if (transferPanel) transferPanel.style.display = 'none';
        if (amtNote) amtNote.style.display = 'none';
        if (saveLabel) saveLabel.textContent = '<?php echo addslashes(xlt("Save Adjustment")); ?>';
    }
    // Still run defaults for adjustment/writeoff
    if (val !== 'transfer') { adjApplyDefaultsToSelected(); }
}

// ── Load available patient credit ────────────────────────────────────────────
function adjLoadAvailableCredit() {
    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&action=get_patient_credit')
        .then(function(r){ return r.json(); })
        .then(function(d) {
            var avail = d.credit || 0;
            var el = document.getElementById('tc-available-credit');
            if (el) {
                el.dataset.raw = avail; // store silently — display removed
            }
            // "From" current balance = selected line balance, or credit pool if none
            adjSyncTransferFromBalance();
            adjUpdateTransferSummary();
        })
        .catch(function(){});
}

// ── Sync the "From Patient" balance in transfer summary ────────────────────────
// Uses the selected encounter line balance (if one is selected), otherwise
// the total available credit pool.
function adjSyncTransferFromBalance() {
    var creditEl = document.getElementById('tc-available-credit');
    var avail    = parseFloat((creditEl ? creditEl.dataset.raw : 0) || 0);

    // Check if a line is selected and read its outstanding balance
    var selRow = document.querySelector('.adj-line-row.selected');
    var lineBalance = selRow ? parseFloat(selRow.dataset.balance || 0) : 0;

    // "Current balance" shown = line balance when selected, else credit pool
    var displayBal = lineBalance > 0.004 ? lineBalance : avail;

    var fromCur = document.getElementById('tc-sum-from-cur');
    if (fromCur) fromCur.textContent = '$' + displayBal.toFixed(2);

    // Available credit label stays as credit pool
    if (creditEl) creditEl.dataset.displayBal = displayBal;
}

// ── Update transfer balance summary in real time ──────────────────────────────
function adjUpdateTransferSummary() {
    var creditEl = document.getElementById('tc-available-credit');
    var avail    = parseFloat((creditEl ? creditEl.dataset.raw : 0) || 0);
    // Read from the header Amount field (primary); fall back to hidden proxy
    var mainAmt  = document.getElementById('adj_invoice_amount');
    var hiddenAmt = document.getElementById('tc-transfer-amount');
    var amt = parseFloat((mainAmt && mainAmt.value) || (hiddenAmt && hiddenAmt.value) || 0) || 0;

    // Use line balance as "current" when a line is selected
    var forcedBalance =
    parseFloat(
        document.getElementById('adj_invoice_amount')?.value || 0
    ) || 0;

    var fromCurrent = forcedBalance > 0.004
    ? forcedBalance
    : avail;
    var newBal  = Math.max(0, fromCurrent - amt);

    var fromCur = document.getElementById('tc-sum-from-cur');
    var fromAmt = document.getElementById('tc-sum-from-amt');
    var fromNew = document.getElementById('tc-sum-from-new');
    var toAmt   = document.getElementById('tc-sum-to-amt');
    var toNew   = document.getElementById('tc-sum-to-new');

    if (fromCur) fromCur.textContent = '$' + fromCurrent.toFixed(2);
    if (fromAmt) { fromAmt.textContent = '-$' + amt.toFixed(2); fromAmt.style.color = amt > 0 ? 'var(--red)' : 'var(--text2)'; }
    if (fromNew) { fromNew.textContent = '$' + newBal.toFixed(2); fromNew.style.color = newBal > 0.004 ? 'var(--amber)' : 'var(--green)'; }
    if (toAmt)   toAmt.textContent = '+$' + amt.toFixed(2);
    if (toNew)   toNew.textContent = '$' + amt.toFixed(2);
}

// ── Patient search for transfer ───────────────────────────────────────────────
var _tcSearchTimer = null;
function adjSearchTransferPatient(q) {
    clearTimeout(_tcSearchTimer);
    if (!q || q.length < 2) { document.getElementById('tc-patient-results').style.display='none'; return; }
    _tcSearchTimer = setTimeout(function() {
        fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&action=search_patients&q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(d) {
                var box = document.getElementById('tc-patient-results');
                if (!box) return;
                box.innerHTML = '';
                (d.patients || []).forEach(function(p) {
                    var item = document.createElement('div');
                    item.style.cssText = 'padding:8px 12px;cursor:pointer;font-size:12px;border-bottom:1px solid var(--border);';
                    item.textContent = p.name + ' (PID #' + p.pid + ')';
                    item.onmouseover = function(){ this.style.background='var(--purple-l)'; };
                    item.onmouseout  = function(){ this.style.background='#fff'; };
                    item.onclick = function() {
                        /* tc-patient-search removed from UI */
                        document.getElementById('tc-dest-pid').value = p.pid;
                        box.style.display = 'none';
                    };
                    box.appendChild(item);
                });
                box.style.display = (d.patients && d.patients.length) ? 'block' : 'none';
            }).catch(function(){});
    }, 300);
}

function adjClearTransferPatient() {
    /* tc-patient-search removed from UI */
    document.getElementById('tc-dest-pid').value = '';
    document.getElementById('tc-patient-results').style.display = 'none';
}

// ── Save or Transfer dispatcher ───────────────────────────────────────────────
function adjSaveOrTransfer() {
    var adjType = (document.getElementById('adj_type_sel') || {}).value || 'write_off';
    if (adjType === 'transfer') {
        submitTransferCredit();
    } else {
        submitAdjustment();
    }
}

// ── Submit transfer credit ────────────────────────────────────────────────────
function submitTransferCredit() {
    // Read from the main Amount field (same as the header bar input)
    var mainAmtEl = document.getElementById('adj_invoice_amount');
    var amt = parseFloat((mainAmtEl && mainAmtEl.value) || 0) || 0;
    if (!amt || amt <= 0) { alert('<?php echo addslashes(xlt("Enter a transfer amount in the Amount field.")); ?>'); return; }

    var transferType = (document.querySelector('input[name="tc_transfer_type"]:checked') || {}).value || 'patient';
    var destPid      = parseInt(document.getElementById('tc-dest-pid').value || 0);
    var destIns      = parseInt((document.getElementById('tc-dest-ins') || {}).value || 0);

    if (transferType === 'insurance' && !destIns) { alert('<?php echo addslashes(xlt("Please select an insurance to transfer to.")); ?>'); return; }

    // Credit availability validated server-side only

    if (!confirm('<?php echo addslashes(xlt("Post this transfer?")); ?>')) return;

    var btn = document.getElementById('adj-save-btn');
    if (btn) { btn.disabled = true; }

    var selectedRow = document.querySelector('.adj-line-row.selected');
    var billingId   = document.getElementById('adj_billing_id');
    var encounter   = document.getElementById('adj_encounter');

    var fd = new FormData();
    fd.append('action',           'post_transfer_credit_ajax');
    fd.append('csrf_token_form',  '<?php echo addslashes(CsrfUtils::collectCsrfToken()); ?>');
    fd.append('tc_pid',           '<?php echo (int)$pid; ?>');
    fd.append('tc_amount',        amt.toFixed(2)); // from header Amount field
    fd.append('tc_transfer_to',   transferType === 'insurance' ? 'insurance' : 'patient'); // patient covers 'patient' radio
    fd.append('tc_dest_pid',      destPid.toString());
    fd.append('tc_dest_ins_id',   destIns.toString());
    fd.append('tc_notes',         (document.getElementById('tc-notes') || {}).value || '');
    fd.append('tc_billing_id',    billingId ? billingId.value : '');
    fd.append('tc_encounter',     encounter ? encounter.value : '');

    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            var banner = document.getElementById('adj-transfer-result');
            if (!banner) return;
            banner.style.display = 'block';
            if (d.success) {
                banner.className = 'alert alert-success';
                banner.textContent = d.message || '<?php echo addslashes(xlt("Transfer posted.")); ?>';
                // Reset form
                document.getElementById('tc-transfer-amount').value = '';
                /* tc-patient-search removed from UI */
                document.getElementById('tc-dest-pid').value = '';
                document.getElementById('tc-notes').value = '';
                adjLoadAvailableCredit();
            } else {
                banner.className = 'alert alert-error';
                banner.textContent = d.error || '<?php echo addslashes(xlt("Transfer failed.")); ?>';
            }
            if (btn) { btn.disabled = false; }
        })
        .catch(function(e) {
            if (btn) { btn.disabled = false; }
            alert('<?php echo addslashes(xlt("Network error.")); ?>');
        });
}

function submitAdjustment() {
    var reason = (document.getElementById('adj_reason_sel') || {}).value;
    if (!reason) { alert(<?php echo json_encode(xlt('Please select a reason code.')); ?>); return; }

    var selectedRow = document.querySelector('.adj-line-row.selected');
    if (!selectedRow) { alert(<?php echo json_encode(xlt('Please select a line item.')); ?>); return; }

    var billingId = document.getElementById('adj_billing_id');
    if (!billingId || !billingId.value) { alert(<?php echo json_encode(xlt('Missing billing item. Click a line to select it.')); ?>); return; }

    var amountField = document.getElementById('adj_invoice_amount');
    var amount = parseFloat(amountField ? amountField.value : 0);
    if (!amount || amount <= 0) { alert(<?php echo json_encode(xlt('Enter a valid adjustment amount.')); ?>); return; }

    var maxBalance = parseFloat(selectedRow.dataset.balance || 0);
    if (amount > maxBalance + 0.005) { alert(<?php echo json_encode(xlt('Adjustment exceeds remaining balance.')); ?>); return; }

    if (!confirm(<?php echo json_encode(xlt('Post this adjustment?')); ?>)) return;

    var adjType  = (document.getElementById('adj_type_sel')  || {}).value || 'write_off';
    var postAs   = (document.querySelector('[name="adj_post_as"]') || {}).value || 'insurance';

    var btn = document.getElementById('adj-save-btn');
    if (btn) { btn.disabled = true; btn.textContent = '<?php echo addslashes(xlt("Posting…")); ?>'; }

    var fd = new FormData();
    fd.append('action',           'post_adjustment_ajax');
    fd.append('csrf_token_form',  '<?php echo addslashes(CsrfUtils::collectCsrfToken()); ?>');
    fd.append('adj_pid',          '<?php echo (int)$pid; ?>');
    fd.append('adj_billing_id',   billingId.value);
    fd.append('adj_amount',       amount.toFixed(2));
    fd.append('adj_type',         adjType);
    fd.append('adj_reason',       reason);
    fd.append('adj_post_as',      postAs);

    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            var banner = document.getElementById('adj-result-banner');
            if (!banner) {
                banner = document.createElement('div');
                banner.id = 'adj-result-banner';
                banner.style.cssText = 'margin:10px 0;padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;';
                var section = document.getElementById('adj-inline-section');
                if (section) section.insertBefore(banner, section.firstChild);
            }
            if (d.success) {
                banner.style.background = 'var(--green-l)';
                banner.style.border = '1px solid var(--green)';
                banner.style.color = '#0a5c44';
                banner.textContent = d.message;
                // Reset selection and reload lines
                document.querySelectorAll('.adj-line-row').forEach(function(r){ r.classList.remove('selected'); });
                var bidF = document.getElementById('adj_billing_id');  if (bidF) bidF.value = '';
                var amtF = document.getElementById('adj_invoice_amount'); if (amtF) amtF.value = '';
                var amtH = document.getElementById('adj_amount');       if (amtH) amtH.value = '';
                _adjAllLoaded = false;
                loadAllAdjLines();
            } else {
                banner.style.background = 'var(--red-l)';
                banner.style.border = '1px solid var(--red)';
                banner.style.color = '#9b1c1c';
                banner.textContent = d.error || '<?php echo addslashes(xlt("Unknown error.")); ?>';
            }
        })
        .catch(function(e) {
            alert('<?php echo addslashes(xlt("Network error. Please try again.")); ?>');
            console.error(e);
        })
        .finally(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> <?php echo addslashes(xlt("Save Adjustment")); ?>';
            }
        });
}

// ── Credit card field formatting ──────────────────────────────────────────────
function fmtCard(inp) {
    var v = inp.value.replace(/\D/g,'').substring(0,16);
    inp.value = v.replace(/(.{4})/g,'$1 ').trim();
}
function fmtExpiry(inp) {
    var v = inp.value.replace(/\D/g,'').substring(0,4);
    if (v.length >= 3) v = v.substring(0,2) + '/' + v.substring(2);
    inp.value = v;
}

// ── Override calcTotal to also sync adj today's payment display ───────────────
var _origCalcTotal = window.calcTotal;
function calcTotal() {
    var total = 0;
    document.querySelectorAll('input[name^="form_upay["]').forEach(function(inp){
        total += parseFloat(inp.value)||0;
    });
    document.getElementById('form_paytotal').value = total.toFixed(2);
    document.getElementById('main-amount').value = '$'+total.toFixed(2);
    // Sync to adj today's payment display
    var adjPmt = document.getElementById('adj-todays-pmt');
    if (adjPmt) adjPmt.value = '$' + total.toFixed(2);
    // Update adj line totals if open
    updateAdjTotals();
}
function openCCModal() {
    var total  = parseFloat(document.getElementById('form_paytotal').value) || 0;
    var prepEl = document.getElementById('form_prepayment');
    var prepay = prepEl ? parseFloat(prepEl.value) || 0 : 0;
    if (total < 0.01 && prepay < 0.01) {
        alert(<?php echo xlj('Please enter a payment amount before charging a card.'); ?>);
        return;
    }
    var amt = total >= 0.01 ? total.toFixed(2) : prepay.toFixed(2);
    var ptEl = document.getElementById('payTotal');
    var paEl = document.getElementById('paymentAmount');
    if (ptEl) ptEl.textContent = amt;
    if (paEl) paEl.value = amt;
    var modal = document.getElementById('mdi-cc-modal');
    if (modal) modal.style.display = 'flex';
}
function closeCCModal() {
    var modal = document.getElementById('mdi-cc-modal');
    if (modal) modal.style.display = 'none';
}

// ── Active panel is server-side driven via PHP $active_tab ───────────────────
// No JS override needed on initial load.

document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('form_method');
    if (sel) onMethodChange(sel.value);
});

top.restoreSession && top.restoreSession();

// ── Adjustments / Write-Offs JS ──────────────────────────────────────────────

// Data passed from PHP for live encounter billing info
var _adjEncData = {};
<?php foreach ($encs as $er):
    $enc_bal = $er['charges'] - $er['ins_paid'] - $er['pat_paid'];
?>
_adjEncData[<?php echo (int)$er['encounter']; ?>] = {
    date: <?php echo json_encode($er['encdate']); ?>,
    encid: <?php echo (int)$er['encounter']; ?>,
    charges: <?php echo (float)$er['charges']; ?>,
    ins_paid: <?php echo (float)$er['ins_paid']; ?>,
    pat_paid: <?php echo (float)$er['pat_paid']; ?>,
    bal: <?php echo round(max(0,(float)$enc_bal),2); ?>
};
<?php endforeach; ?>

// ── Adj State ──────────────────────────────────────────────────────────────────
var _adjAllLines  = [];   // flat array of all billing lines across all encounters
var _adjAllLoaded = false;
var _adjLines = {};
var _preselectEnc = <?php echo (int)$preselect_enc; ?>; // 0 = show all, >0 = only this encounter

// Load adjustment lines — if a specific encounter was selected, only load that one
function loadAllAdjLines() {

var tbody = document.getElementById('adj-lines-tbody');

if (!tbody) return;

tbody.innerHTML =
    '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text3);">' +
    '<?php echo addslashes(xlt("Loading…")); ?>' +
    '</td></tr>';

var pid = <?php echo (int)$pid; ?>;

var allEncIds = <?php
    $encIdList = array_map(function($e){ return (int)$e['encounter']; }, $encs);
    echo json_encode($encIdList);
?>;

// Only load the selected encounter if coming from a specific enc click
var encIds = (_preselectEnc > 0)
    ? allEncIds.filter(function(id){ return id === _preselectEnc; })
    : allEncIds;

if (!encIds.length) {

    tbody.innerHTML =
        '<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text3);">' +
        '<?php echo addslashes(xlt("No encounters found.")); ?>' +
        '</td></tr>';

    return;
}

var fetches = encIds.map(function(encId){

    return fetch(
        'mdi_payments_ledger.php'
        + '?pid=' + pid
        + '&action=get_enc_lines'
        + '&enc=' + encId
        + '&csrf=<?php echo urlencode(CsrfUtils::collectCsrfToken()); ?>'
    )

    .then(function(r){
        return r.json();
    })

    .catch(function(){
        return null;
    })

    .then(function(data){

        var lines = (data && data.lines)
            ? data.lines
            : [];

        var pmts = (data && data.payments)
            ? data.payments
            : [];

        lines.forEach(function(l){

            l._enc_id = encId;

            var ed = _adjEncData[encId] || {};

            l._enc_dos = ed.date || '';
            l._enc_num = encId;

            var insPmt = null;
            var patPmt = null;

            pmts.forEach(function(p){

                if (parseFloat(p.pay_amount) > 0) {

                    if (parseInt(p.payer_type) > 0 && !insPmt) {
                        insPmt = p;
                    }

                    if (parseInt(p.payer_type) === 0 && !patPmt) {
                        patPmt = p;
                    }
                }
            });

            l.ins_pay_method = insPmt ? (insPmt.payment_method || '') : '';
            l.pat_pay_method = patPmt ? (patPmt.payment_method || '') : '';

            // Extract memo from most recent WO/AJ adjustment activity
            var lastAdjMemo = '';
            pmts.forEach(function(p) {
                var ac = (p.account_code || '').toUpperCase();
                if ((ac === 'WO' || ac === 'AJ' || ac === 'CO') && !lastAdjMemo) {
                    lastAdjMemo = p.adj_reason_label || p.memo || '';
                }
            });
            l.last_adj_memo = lastAdjMemo;

            // Detect balance transfers on this encounter
            var transPmts = pmts.filter(function(p) {
                return (p.account_code || '').toUpperCase() === 'TRANS';
            });
            l.has_transfer  = transPmts.length > 0;
            l.transfer_memos = transPmts.map(function(p) { return p.memo || ''; });
            l.transfer_total = transPmts.reduce(function(sum, p) {
                return sum + Math.abs(parseFloat(p.adj_amount || 0) + parseFloat(p.pay_amount || 0));
            }, 0);

            // Attach adj payment rows for this line's expand detail
            l._adj_pmts = pmts.filter(function(p) {
                var ac = (p.account_code || '').toUpperCase();
                return (ac === 'WO' || ac === 'AJ' || ac === 'CO');
            });
        });

        return lines;
    });
});

Promise.all(fetches).then(function(results){

    _adjAllLines = [];

    results.forEach(function(lines){

        if(lines){
            _adjAllLines =
                _adjAllLines.concat(lines);
        }
    });

    _adjAllLoaded = true;

    renderAdjLines();
});
}

function toggleAdjLineDetail(idx) {
    var row  = document.getElementById('adj-detail-row-' + idx);
    var chev = document.getElementById('adj-chev-' + idx);
    if (!row) return;
    var open = row.style.display !== 'none';
    row.style.display = open ? 'none' : '';
    if (chev) chev.innerHTML = open ? '&#9654;' : '&#9660;';
}

function renderAdjLines() {

var tbody = document.getElementById('adj-lines-tbody');

if (!tbody) return;

var lines = _adjAllLines || [];

if (!lines.length) {

    tbody.innerHTML =
        '<tr><td colspan="7" style="text-align:center;color:var(--text3);padding:24px;">' +
        '<?php echo addslashes(xlt("No billing lines found.")); ?>' +
        '</td></tr>';

    return;
}

var html = '';

lines.forEach(function(line, i) {

    var charge  = parseFloat(line.charge) || 0;

    var insPaid = parseFloat(
        line.ins_paid !== undefined
            ? line.ins_paid
            : line.paid
    ) || 0;

    var patPaid = parseFloat(line.pat_paid) || 0;

    var adjPrev = parseFloat(line.adj) || 0;

    var patBal =
        Math.max(
            0,
            charge - insPaid - patPaid - adjPrev
        );

    var isZero = patBal <= 0.005;

    // hide zero balances unless enabled
    if (
        isZero
        &&
        !(document.getElementById('adj-show-zero')
            && document.getElementById('adj-show-zero').checked)
    ) {
        return;
    }

    var adjReason =
    line.last_adj_memo || line.memo || '—';

    var hasTransfer = line.has_transfer && line.transfer_total > 0.004;

    var statusLabel =
        patBal <= 0.005
            ? 'Resolved'
            : hasTransfer
                ? 'Transferred'
                : adjPrev > 0
                    ? 'Adjusted'
                    : 'Open';

    var statusClass =
        patBal <= 0.005
            ? 'badge-paid'
            : hasTransfer
                ? 'badge-transfer'
                : adjPrev > 0
                    ? 'badge-partial'
                    : 'badge-open';

    var rowBg = hasTransfer ? 'background:#f0f7ff;' : '';
html +=
'<tr class="adj-line-row" '

+ 'data-zero="' + (isZero ? '1' : '0') + '" '

+ 'data-billing-id="' + escH(String(line.billing_id || 0)) + '" '

+ 'data-encounter="' + escH(String(line._enc_num || 0)) + '" '

+ 'data-balance="' + patBal.toFixed(2) + '" '

+ 'data-code="' + escH(String(line.code || "")) + '" '

+ 'data-modifier="' + escH(String(line.modifier || "")) + '" '

+ 'data-dos="' + escH(String(line.dos || "")) + '" '

+ 'style="border-bottom:1px solid var(--border);cursor:pointer;' + rowBg + '">';

html +=
    '<td style="padding:10px;width:16px;text-align:center;">'
    + (adjPrev > 0
        ? '<span style="cursor:pointer;color:var(--purple);font-size:11px;" id="adj-chev-' + i + '" onclick="event.stopPropagation();toggleAdjLineDetail(' + i + ')">&#9654;</span>'
        : '')
    + '</td>';

   // DOS / ENC
html +=
    '<td style="padding:10px;">'
    + '<div style="font-family:\'DM Mono\',monospace;font-size:11px;font-weight:600;">'
    + escH(line._enc_dos)
    + '</div>'
    + '<div style="font-size:10px;color:var(--text3);">'
    + 'Enc #'
    + escH(String(line._enc_num))
    + '</div>'
    + '</td>';

// CPT / DESCRIPTION
html +=
    '<td style="padding:10px;">'
    + '<div style="font-family:\'DM Mono\',monospace;font-size:12px;font-weight:700;color:var(--purple);">'
    + escH(String(line.code || ''))
    + '</div>'
    + '<div style="font-size:11px;color:var(--text2);">'
    + escH(line.description)
    + '</div>'
    + '</td>';

// CHARGE
html +=
    '<td style="padding:10px;text-align:right;font-family:\'DM Mono\',monospace;font-weight:700;">'
    + '$' + charge.toFixed(2)
    + '</td>';

// INSURANCE PAID
html +=
    '<td style="padding:10px;text-align:right;">'
    + '<div style="font-family:\'DM Mono\',monospace;font-size:12px;font-weight:600;color:'
    + (insPaid > 0 ? 'var(--green)' : 'var(--text3)')
    + ';">'
    + '$' + insPaid.toFixed(2)
    + '</div>'
    + '</td>';

// PATIENT PAID
html +=
    '<td style="padding:10px;text-align:right;">'
    + '<div style="font-family:\'DM Mono\',monospace;font-size:12px;font-weight:600;color:'
    + (patPaid > 0 ? 'var(--green)' : 'var(--text3)')
    + ';">'
    + '$' + patPaid.toFixed(2)
    + '</div>'
    + '</td>';

// EXISTING ADJUSTMENTS (REAL POSTED ADJUSTMENTS)
html +=
    '<td style="padding:10px;text-align:right;">'
    + '<div style="font-family:\'DM Mono\',monospace;'
    + 'font-size:12px;font-weight:700;color:var(--amber);">'
    + '$' + adjPrev.toFixed(2)
    + '</div>'
    + '</td>';

    // ADJUSTMENT REASON
html +=
    '<td style="padding:10px;">'
    + '<span style="font-size:11px;'
    + 'background:rgba(245,158,11,.08);'
    + 'color:var(--amber);'
    + 'border:1px solid rgba(245,158,11,.15);'
    + 'padding:4px 8px;'
    + 'border-radius:999px;'
    + 'font-weight:600;'
    + 'display:inline-block;">'
    + escH(adjReason)
    + '</span>'
    + '</td>';

// CURRENT BALANCE (REAL BALANCE ONLY)
html +=
    '<td class="patient-balance-cell" '
    + 'style="padding:10px;text-align:right;'
    + 'font-family:\'DM Mono\',monospace;'
    + 'font-weight:700;color:'
    + (patBal > 0.005 ? 'var(--red)' : 'var(--green)')
    + ';">'
    + '$' + patBal.toFixed(2)
    + '</td>';


// STATUS + transfer indicator
var transferTitle = hasTransfer
    ? line.transfer_memos.filter(Boolean).join(' | ')
    : '';
// Inline style for badge-transfer since it may not be in CSS
var badgeStyle = (statusClass === 'badge-transfer')
    ? ' style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;"' 
    : '';
html +=
    '<td style="padding:10px;text-align:center;">'
    + '<span class="' + statusClass + '"' + badgeStyle
    + (transferTitle ? ' title="' + escH(transferTitle) + '"' : '')
    + '>'
    + statusLabel
    + '</span>';
if (hasTransfer) {
    html +=
        '<div style="font-size:9px;color:#1d4ed8;margin-top:3px;font-weight:600;white-space:nowrap;" title="' + escH(transferTitle) + '">';
    line.transfer_memos.slice(0,2).forEach(function(m) {
        if (m) html += '<div>' + escH(m.substring(0,28)) + '</div>';
    });
    html += '</div>';
}
html += '</td>';



html += '</tr>';

    // ── Expandable adj detail sub-row ──
    if (adjPrev > 0) {
        // Collect adj-type payments for this line from pmts
        var adjDetails = [];
        if (line._adj_pmts && line._adj_pmts.length) {
            adjDetails = line._adj_pmts;
        }

        html += '<tr id="adj-detail-row-' + i + '" style="display:none;background:#fffbeb;">';
        html += '<td colspan="10" style="padding:0 12px 12px 36px;">';
        html += '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--amber);margin:8px 0 6px;"><?php echo addslashes(xlt('Adjustment Detail')); ?></div>';

        if (adjDetails.length) {
            html += '<table style="width:100%;border-collapse:collapse;font-size:11px;border:1px solid #fde68a;border-radius:6px;overflow:hidden;">';
            html += '<thead><tr style="background:#fef9c3;">'
                  + '<th style="padding:5px 8px;font-size:10px;font-weight:700;color:var(--text2);"><?php echo addslashes(xlt('Date')); ?></th>'
                  + '<th style="padding:5px 8px;font-size:10px;font-weight:700;color:var(--text2);"><?php echo addslashes(xlt('Type')); ?></th>'
                  + '<th style="padding:5px 8px;font-size:10px;font-weight:700;color:var(--text2);"><?php echo addslashes(xlt('Carrier')); ?></th>'
                  + '<th style="padding:5px 8px;font-size:10px;font-weight:700;color:var(--amber);text-align:right;"><?php echo addslashes(xlt('Write-Off Amt')); ?></th>'
                  + '<th style="padding:5px 8px;font-size:10px;font-weight:700;color:var(--text2);text-align:right;"><?php echo addslashes(xlt('Other Adj')); ?></th>'
                  + '<th style="padding:5px 8px;font-size:10px;font-weight:700;color:var(--text2);"><?php echo addslashes(xlt('Reason')); ?></th>'
                  + '</tr></thead><tbody>';
            adjDetails.forEach(function(ad) {
                var isIns = parseInt(ad.payer_type) > 0;
                var ac3   = (ad.account_code||'').toUpperCase();
                var isWO  = (ac3 === 'WO');
                var woAmt = isWO ? Math.abs(parseFloat(ad.adj_amount)||0) || Math.abs(parseFloat(ad.pay_amount)||0) : 0;
                var othAmt= !isWO ? Math.abs(parseFloat(ad.adj_amount)||0) || Math.abs(parseFloat(ad.pay_amount)||0) : 0;
                var typeLabel = isIns
                    ? (isWO ? '<?php echo addslashes(xlt("Insurance Write-Off")); ?>' : '<?php echo addslashes(xlt("Insurance Adjustment")); ?>')
                    : (isWO ? '<?php echo addslashes(xlt("Patient Write-Off")); ?>' : '<?php echo addslashes(xlt("Patient Adjustment")); ?>');
                html += '<tr style="border-top:1px solid #fde68a;">'
                      + '<td style="padding:5px 8px;font-family:\'DM Mono\',monospace;">' + escH((ad.post_time||'').substring(0,10)) + '</td>'
                      + '<td style="padding:5px 8px;"><span style="font-size:10px;font-weight:700;padding:2px 7px;border-radius:10px;background:' + (isIns?'#dbeafe':'#fce7f3') + ';color:' + (isIns?'#1d4ed8':'#9d174d') + ';">' + typeLabel + '</span></td>'
                      + '<td style="padding:5px 8px;font-size:11px;color:var(--text2);">' + escH(ad.ins_name || (isIns ? '—' : '<?php echo addslashes(xlt("Patient")); ?>')) + '</td>'
                      + '<td style="padding:5px 8px;text-align:right;font-family:\'DM Mono\',monospace;color:var(--amber);font-weight:700;">' + (woAmt>0?'$'+woAmt.toFixed(2):'—') + '</td>'
                      + '<td style="padding:5px 8px;text-align:right;font-family:\'DM Mono\',monospace;color:var(--amber);">' + (othAmt>0?'$'+othAmt.toFixed(2):'—') + '</td>'
                      + '<td style="padding:5px 8px;font-size:11px;color:var(--text2);">' + escH(ad.adj_reason_label || ad.memo || '—') + '</td>'
                      + '</tr>';
            });
            html += '</tbody></table>';
        } else {
            // No per-line detail yet — show summary from last_adj_memo
            html += '<div style="font-size:11px;color:var(--text2);padding:4px 0;">'
                  + '<?php echo addslashes(xlt("Reason")); ?>: <strong>' + escH(adjReason !== '—' ? adjReason : '<?php echo addslashes(xlt("No reason recorded")); ?>') + '</strong>'
                  + ' &nbsp;|&nbsp; <?php echo addslashes(xlt("Amount")); ?>: <strong style="color:var(--amber);">$' + adjPrev.toFixed(2) + '</strong>'
                  + '</div>';
        }

        html += '</td></tr>';
    }

}); // end lines.forEach

tbody.innerHTML = html;

} // end renderAdjLines

// CLICKABLE ROWS (AJAX SAFE)
document.addEventListener('click', function(e){

const row = e.target.closest('.adj-line-row');

if (!row) {
    return;
}

// clear existing selections
document.querySelectorAll('.adj-line-row').forEach(function(r){
    r.classList.remove('selected');
});

// activate selected row
row.classList.add('selected');

// dataset values
const billingId =
    row.dataset.billingId || '';

const encounter =
    row.dataset.encounter || '';

const balance =
    parseFloat(row.dataset.balance || 0);

// hidden fields
const billingField =
    document.getElementById('adj_billing_id');

if (billingField) {
    billingField.value = billingId;
}

const encounterField =
    document.getElementById('adj_encounter');

if (encounterField) {
    encounterField.value = encounter;
}

// amount fields
const ledgerAmountField =
    document.getElementById('adj_amount');

const visibleAmountField =
    document.getElementById('adj_invoice_amount');

const typeSel =
    document.getElementById('adj_type_sel');

// WRITE OFF
if (
    typeSel &&
    typeSel.value === 'write_off'
) {

    if (ledgerAmountField) {
        ledgerAmountField.value =
            balance.toFixed(2);
    }

    if (visibleAmountField) {

        visibleAmountField.value =
            balance.toFixed(2);

        visibleAmountField.readOnly = true;
    }

// ADJUSTMENT
} else {

    if (ledgerAmountField) {
        ledgerAmountField.value = '';
    }

    if (visibleAmountField) {

        visibleAmountField.value = '';

        visibleAmountField.readOnly = false;

        visibleAmountField.focus();
    }
}

// balance display
const balEl =
    document.getElementById('selected-balance');

if (balEl) {
    balEl.innerText =
        '$' + balance.toFixed(2);
}

// encounter display
const encEl =
    document.getElementById('selected-encounter');

if (encEl) {
    encEl.innerText =
        encounter;
}

// remaining calc
updateRemainingBalance(balance);

// If in transfer mode, refresh the transfer balance summary
var adjTypeSel = document.getElementById('adj_type_sel');
if (adjTypeSel && adjTypeSel.value === 'transfer') {
    adjSyncTransferFromBalance();
    adjUpdateTransferSummary();
}

});


function updateRemainingBalance(originalBalance) {

const amountField =
    document.getElementById('adj_invoice_amount');

const remainingEl =
    document.getElementById('remaining-balance');

if (!amountField || !remainingEl) {
    return;
}

const entered =
    parseFloat(amountField.value || 0);

const remaining =
    originalBalance - entered;

remainingEl.innerText =
    '$' + remaining.toFixed(2);

}
// Apply header defaults to all checked lines
function adjApplyDefaultsToSelected() {
    var adjType   = (document.getElementById('adj_type_sel')   || {}).value || 'write_off';
    var adjAmt    = parseFloat((document.getElementById('adj_invoice_amount') || {}).value) || 0;
    var rSel      = document.getElementById('adj_reason_sel');
    var adjReason = rSel && rSel.selectedIndex > 0 ? rSel.options[rSel.selectedIndex].text : '';

    _adjAllLines.forEach(function(line) {
        if (!line._selected) return;
        var charge  = parseFloat(line.charge)  || 0;
        var insPaid = parseFloat(line.ins_paid !== undefined ? line.ins_paid : line.paid) || 0;
        var patPaid = parseFloat(line.pat_paid) || 0;
        var adjPrev = parseFloat(line.adj)     || 0;
        var outstanding = Math.max(0, charge - insPaid - patPaid - adjPrev);
        line._adj_applied = adjAmt > 0 ? Math.min(adjAmt, outstanding) : outstanding;
        line._adj_type    = adjType;
        line._adj_reason  = adjReason;
    });
    renderAdjLines();
}

// Write-Off: zero out balance for selected (or all with balance if none selected)
function adjWriteOffToZero() {
    var adjType   = (document.getElementById('adj_type_sel')  || {}).value || 'write_off';
    var rSel      = document.getElementById('adj_reason_sel');
    var adjReason = rSel && rSel.selectedIndex > 0 ? rSel.options[rSel.selectedIndex].text : '';
    var anySelected = _adjAllLines.some(function(l){ return l._selected; });

    _adjAllLines.forEach(function(line) {
        var outstanding = Math.max(0,
            (parseFloat(line.charge)||0)
          - (parseFloat(line.ins_paid !== undefined ? line.ins_paid : line.paid)||0)
          - (parseFloat(line.pat_paid)||0)
          - (parseFloat(line.adj)||0)
        );
        if (!anySelected || line._selected) {
            if (outstanding > 0.005) {
                line._selected    = true;
                line._adj_applied = outstanding;
                line._adj_type    = adjType;
                line._adj_reason  = adjReason;
            }
        }
    });
    renderAdjLines();
    updateAdjTotals();
}

function adjLineCheckChanged(cb) {
    var idx = parseInt(cb.dataset.idx);
    if (_adjAllLines[idx]) {
        _adjAllLines[idx]._selected = cb.checked;
        if (!cb.checked) { _adjAllLines[idx]._adj_applied=0; _adjAllLines[idx]._adj_type=''; _adjAllLines[idx]._adj_reason=''; }
    }
    var allChecked = true;
    document.querySelectorAll('.adj-line-check').forEach(function(c){ if(!c.checked) allChecked=false; });
    var ca = document.getElementById('adj-check-all');
    if (ca) ca.checked = allChecked;
    updateAdjTotals();
}

function adjToggleAll(checked) {
    _adjAllLines.forEach(function(line) {
        var outstanding = Math.max(0,(parseFloat(line.charge)||0)-(parseFloat(line.ins_paid!==undefined?line.ins_paid:line.paid)||0)-(parseFloat(line.pat_paid)||0)-(parseFloat(line.adj)||0));
        if (outstanding>0.005 || !checked) {
            line._selected = checked;
            if (!checked){ line._adj_applied=0; }
        }
    });
    renderAdjLines();
}



// Sync global reason dropdown → all per-line reason selects
function adjSyncReasonToLines() {
    var val = (document.getElementById('adj_reason_sel') || {}).value || '';
    document.querySelectorAll('.adj-reason-inp').forEach(function(sel) {
        if (val) sel.value = val;
    });
}


function adjCollapseAll() { /* placeholder */ }
function adjExpandLine(i) { /* placeholder */ }
function adjFilterZero(showZero) {
    document.querySelectorAll('.adj-line-row').forEach(function(row) {
        if (row.dataset.zero === '1' && !showZero) row.style.display = 'none';
        else row.style.display = '';
    });
}

function adjFillFromPayments() { adjWriteOffToZero(); } // legacy alias

function updateAdjTotals() {
    // Only count selected lines in totals
    var totCharge=0, totInsPaid=0, totPatPaid=0, totAdj=0, totPatBal=0, selCount=0;

    _adjAllLines.forEach(function(line) {
        if (!line._selected) return;
        selCount++;
        var charge  = parseFloat(line.charge)  || 0;
        var insPaid = parseFloat(line.ins_paid !== undefined ? line.ins_paid : line.paid) || 0;
        var patPaid = parseFloat(line.pat_paid) || 0;
        var adjPrev = parseFloat(line.adj)     || 0;
        var adjAmt  = parseFloat(line._adj_applied || 0);
        totCharge  += charge;
        totInsPaid += insPaid;
        totPatPaid += patPaid;
        totAdj     += adjAmt;
        totPatBal  += Math.max(0, charge - insPaid - patPaid - adjPrev - adjAmt);
    });

    // Remaining balance = total of ALL outstanding balances minus adj applied
    var totalOutstanding = 0;
    _adjAllLines.forEach(function(line) {
        totalOutstanding += Math.max(0,
            (parseFloat(line.charge)||0)
          - (parseFloat(line.ins_paid !== undefined ? line.ins_paid : line.paid)||0)
          - (parseFloat(line.pat_paid)||0)
          - (parseFloat(line.adj)||0)
          - (parseFloat(line._adj_applied||0))
        );
    });
    var remEl = document.getElementById('adj-remaining-bal');
    if (remEl) {
        remEl.textContent = '$' + totalOutstanding.toFixed(2);
        remEl.style.color = totalOutstanding > 0.005 ? 'var(--amber)' : 'var(--green)';
    }

}

function adjReset() {
    document.getElementById('adjust-form').reset();
    _adjLines = [];
    _adjEncId = null;
    var els = ['adj-enc-summary','adj-lines-card'];
    els.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    var es = document.getElementById('adj-empty-state');
    if (es) es.style.display = '';
    updateAdjTotals();
}

function validateAdjustment() {
    var enc = document.getElementById('adj_enc_sel').value;
    if (!enc) {
        alert('<?php echo addslashes(xlj("Please select an encounter.")); ?>');
        return false;
    }
    var level = (document.querySelector('input[name="adj_level"]:checked') || {}).value || 'line';
    var hasAmt = false;
    if (level === 'line') {
        document.querySelectorAll('.adj-amount-inp').forEach(function(inp) {
            if (!inp.disabled && parseFloat(inp.value) > 0) hasAmt = true;
        });
    } else {
        var inv = parseFloat(document.getElementById('adj_invoice_amount')?.value || 0);
        if (inv > 0) hasAmt = true;
    }
    if (!hasAmt) {
        alert('<?php echo addslashes(xlj("Please enter at least one adjustment amount.")); ?>');
        return false;
    }
    top.restoreSession && top.restoreSession();
    return confirm('<?php echo addslashes(xlj("Post this adjustment? This cannot be undone.")); ?>');
}

function escH(s) {

s = String(s || '');

return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// ── End Adjustments JS ────────────────────────────────────────────────────────

// ── Unapplied Credits: Apply All (auto-distribute oldest first) ──────────────
function autoApplyCredits() {
    // Collect all available credits
    var credits = [];
    <?php foreach ($unapplied_credits as $uc): ?>
    credits.push({ session_id: <?php echo (int)$uc['session_id']; ?>, available: <?php echo (float)$uc['available']; ?> });
    <?php endforeach; ?>

    if (!credits.length) { return; }

    var totalAvail = credits.reduce(function(s, c){ return s + c.available; }, 0);
    if (!confirm('<?php echo addslashes(xlj('Apply')); ?> $' + totalAvail.toFixed(2) + ' <?php echo addslashes(xlj('of available credits to open encounter balances, oldest first?')); ?>')) {
        return;
    }

    // Apply each credit session one by one via sequential form POSTs
    // For simplicity, apply the largest single credit — or chain if multiple
    // Use the first credit entry to kick off (server applies across encounters)
    var c = credits[0];
    applyCreditSession(c.session_id, c.available);
}

// ── Apply a single credit session ───────────────────────────────────────────
function applyOneCredit(sessionId, available) {
    if (!confirm('<?php echo addslashes(xlj('Apply')); ?> $' + parseFloat(available).toFixed(2) + ' <?php echo addslashes(xlj('from this credit to open encounter balances?')); ?>')) {
        return;
    }
    applyCreditSession(sessionId, available);
}

function applyCreditSession(sessionId, available) {
    var form = document.getElementById('auto-apply-form');
    if (!form) { return; }
    document.getElementById('aa_session_id').value  = sessionId;
    document.getElementById('aa_credit_avail').value = parseFloat(available).toFixed(2);
    top.restoreSession && top.restoreSession();
    form.submit();
}
// ── End unapplied credits JS ─────────────────────────────────────────────────
</script>

<?php if (!empty($GLOBALS['cc_front_payments']) && $GLOBALS['payment_gateway'] != 'InHouse'): ?>
<?php if ($GLOBALS['payment_gateway'] == 'Stripe'): ?>
<script src="https://js.stripe.com/v3/"></script>
<?php endif; ?>
<?php if ($GLOBALS['payment_gateway'] == 'AuthorizeNet'):
    $anet_script = !empty($GLOBALS['gateway_mode_production'])
        ? 'https://js.authorize.net/v1/Accept.js'
        : 'https://jstest.authorize.net/v1/Accept.js'; ?>
<script src="<?php echo $anet_script; ?>" charset="utf-8"></script>
<?php endif; ?>
<script src="<?php echo $GLOBALS['assets_static_relative']; ?>/jquery-creditcardvalidator/jquery.creditCardValidator.js"></script>

<style>
/* ── Edit drawer ── */
tr[id^="sr-drawer-"] td { transition: none; }
tr[id^="sr-drawer-"] iframe { background: #f8f7ff; }
#mdi-cc-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;}
#mdi-cc-modal .mcc-dialog{background:#fff;border-radius:10px;width:500px;max-width:96vw;box-shadow:0 8px 40px rgba(0,0,0,.28);overflow:hidden;}
#mdi-cc-modal .mcc-head{background:#1e0a3c;color:#fff;padding:14px 20px;font-weight:700;font-size:15px;display:flex;justify-content:space-between;align-items:center;}
#mdi-cc-modal .mcc-head button{background:transparent;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;}
#mdi-cc-modal .mcc-body{padding:20px;}
#mdi-cc-modal .mcc-foot{padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:8px;}
#mdi-cc-modal .form-group{margin-bottom:13px;}
#mdi-cc-modal .form-label{font-size:11px;font-weight:700;color:#5a5f7a;text-transform:uppercase;letter-spacing:.04em;display:block;margin-bottom:4px;}
#mdi-cc-modal .form-control{width:100%;padding:8px 10px;border:1.5px solid #e2e6f0;border-radius:5px;font-size:13px;font-family:inherit;color:#1a1d2e;outline:none;}
#mdi-cc-modal .form-control:focus{border-color:#5b21b6;}
#mdi-cc-modal .cc-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
#mdi-cc-modal .cc-row{display:flex;gap:10px;}
#mdi-cc-modal .amt-display{font-family:'DM Mono',monospace;font-size:22px;font-weight:700;color:#5b21b6;padding:6px 0;}

</style>

<div id="mdi-cc-modal">
  <div class="mcc-dialog">
    <div class="mcc-head">
      <span><?php echo xlt('Credit Card Payment'); ?></span>
      <button onclick="closeCCModal()" title="<?php echo xla('Close'); ?>">&times;</button>
    </div>
    <div class="mcc-body">

      <?php if ($GLOBALS['payment_gateway'] == 'AuthorizeNet'): ?>
      <form id="paymentForm" method="post" action="./front_payment_cc.php">
        <div class="form-group">
          <label class="form-label"><?php echo xlt('Name on Card'); ?></label>
          <input name="cardHolderName" id="cardHolderName" type="text" class="form-control"
            value="<?php echo attr(($patrow['fname'] ?? '') . ' ' . ($patrow['lname'] ?? '')); ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><?php echo xlt('Card Number'); ?></label>
          <div style="display:flex;gap:10px;align-items:center;">
            <input name="cardNumber" id="cardNumber" type="text" class="form-control"
              autocomplete="off" maxlength="19" onchange="validateCC()" style="flex:1;">
            <span id="cardtype" style="font-size:12px;font-weight:700;color:#cc0000;white-space:nowrap;min-width:80px;"><?php echo xlt('Enter Card'); ?></span>
          </div>
        </div>
        <div class="cc-grid">
          <div class="form-group">
            <label class="form-label"><?php echo xlt('Month'); ?></label>
            <select name="month" id="expMonth" class="form-control">
              <option value="">--</option>
              <?php foreach(['01'=>'Jan','02'=>'Feb','03'=>'Mar','04'=>'Apr','05'=>'May','06'=>'Jun','07'=>'Jul','08'=>'Aug','09'=>'Sep','10'=>'Oct','11'=>'Nov','12'=>'Dec'] as $mv=>$ml): ?>
              <option value="<?php echo $mv; ?>"><?php echo $mv.' '.xlt($ml); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label"><?php echo xlt('Year'); ?></label>
            <select name="year" id="expYear" class="form-control">
              <option value="">--</option>
              <?php for($cy=(int)date('Y');$cy<=(int)date('Y')+10;$cy++): ?>
              <option value="<?php echo $cy; ?>"><?php echo $cy; ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label"><?php echo xlt('CVV'); ?></label>
            <input name="cardCode" id="cardCode" type="text" class="form-control" autocomplete="off" maxlength="4">
          </div>
        </div>
        <div class="cc-row">
          <div class="form-group" style="flex:1;">
            <label class="form-label"><?php echo xlt('Cardholder Zip'); ?></label>
            <input name="zip" id="cczip" type="text" class="form-control"
              value="<?php echo attr($patrow['postal_code'] ?? ''); ?>">
          </div>
          <div class="form-group" style="flex:1;">
            <label class="form-label"><?php echo xlt('Charge Amount'); ?></label>
            <div class="amt-display">$<span id="payTotal">0.00</span></div>
          </div>
        </div>
        <input type="hidden" name="pid"            value="<?php echo attr($pid); ?>">
        <input type="hidden" name="mode"           id="anet_mode"      value="">
        <input type="hidden" name="cc_type"        id="cc_type"        value="">
        <input type="hidden" name="payment"        id="paymentAmount"  value="">
        <input type="hidden" name="invValues"      id="invValues"      value="">
        <input type="hidden" name="dataValue"      id="dataValue">
        <input type="hidden" name="dataDescriptor" id="dataDescriptor">
      </form>
      <?php endif; ?>

      <?php if ($GLOBALS['payment_gateway'] == 'Stripe'): ?>
      <form method="post" name="stripe-cc-form" id="stripe-cc-form">
        <div class="form-group">
          <label class="form-label"><?php echo xlt('Name on Card'); ?></label>
          <input name="cardHolderName" id="cardHolderName" type="text" class="form-control"
            value="<?php echo attr(($patrow['fname'] ?? '') . ' ' . ($patrow['lname'] ?? '')); ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><?php echo xlt('Card Details'); ?></label>
          <div id="card-element" style="padding:10px;border:1.5px solid #e2e6f0;border-radius:5px;"></div>
          <div id="card-errors" style="color:#e03131;font-size:12px;margin-top:4px;"></div>
        </div>
        <div style="font-size:13px;margin-bottom:8px;"><?php echo xlt('Charge Amount'); ?>: <span class="amt-display" style="font-size:18px;">$<span id="payTotal">0.00</span></span></div>
        <input type="hidden" name="mode"      id="stripe_mode"   value="">
        <input type="hidden" name="cc_type"   id="stripe_cctype" value="">
        <input type="hidden" name="payment"   id="paymentAmount" value="">
        <input type="hidden" name="invValues" id="invValues"     value="">
        <input type="hidden" name="encs"      id="encs"          value="">
      </form>
      <?php endif; ?>

    </div>
    <div class="mcc-foot">
      <button type="button" class="btn btn-outline" onclick="closeCCModal()"><?php echo xlt('Cancel'); ?></button>
      <?php if ($GLOBALS['payment_gateway'] == 'AuthorizeNet'): ?>
      <button type="button" class="btn btn-success" onclick="sendPaymentDataToAnet(event)"><?php echo xlt('Pay Now'); ?></button>
      <?php endif; ?>
      <?php if ($GLOBALS['payment_gateway'] == 'Stripe'): ?>
      <button type="button" id="stripeSubmit" class="btn btn-success"><?php echo xlt('Pay Now'); ?></button>
      <?php endif; ?>
    </div>
  </div>
</div>






<script>
var chargeMsg = <?php echo xlj('Payment was successfully authorized and charged. Thank You.'); ?>;
var publicKey = <?php echo json_encode($cryptoGen->decryptStandard($GLOBALS['gateway_public_key'])); ?>;
var apiKey    = <?php echo json_encode($cryptoGen->decryptStandard($GLOBALS['gateway_api_key'])); ?>;

<?php if ($GLOBALS['payment_gateway'] == 'AuthorizeNet'): ?>
var ccerr = <?php echo xlj('Invalid Credit Card Number'); ?>;
$(document).on('change', '#cardNumber', function() {
    var r = $(this).validateCreditCard();
    var t = r.card_type ? r.card_type.name.toUpperCase() : '';
    $('#cardtype').css('color', r.valid ? '#00aa00' : '#cc0000').text(t + (r.valid ? ' ✓' : ' …'));
});
function validateCC() {
    var r = $('#cardNumber').validateCreditCard();
    var t = r.card_type ? r.card_type.name.toUpperCase() : '';
    $('#cardtype').css('color', r.valid ? '#00aa00' : '#cc0000').text(t + (r.valid ? ' ✓' : ' Invalid'));
    $('#cc_type').val(t);
    if (!r.valid) { alert(ccerr); return false; }
    return true;
}
function sendPaymentDataToAnet(e) {
    e.preventDefault();
    var mo = document.getElementById('expMonth').value;
    var yr = document.getElementById('expYear').value;
    var cn = document.getElementById('cardNumber').value;
    var cv = document.getElementById('cardCode').value;
    if (!mo || !yr || !cn || !cv) { alert(<?php echo xlj('Please fill in all card fields.'); ?>); return; }
    Accept.dispatchData({
        authData: { clientKey: publicKey, apiLoginID: apiKey },
        cardData: {
            cardNumber: cn, month: mo, year: yr, cardCode: cv,
            fullName:   document.getElementById('cardHolderName').value,
            zip:        document.getElementById('cczip').value
        }
    }, function(response) {
        if (response.messages.resultCode === 'Error') {
            alert(response.messages.message.map(function(m){ return m.code+': '+m.text; }).join('\n'));
        } else {
            paymentFormUpdate(response.opaqueData);
        }
    });
}
function paymentFormUpdate(opaqueData) {
    document.getElementById('dataDescriptor').value = opaqueData.dataDescriptor;
    document.getElementById('dataValue').value      = opaqueData.dataValue;
    var oForm = document.forms['paymentForm'];
    oForm.elements['anet_mode'].value = 'AuthorizeNet';
    ['cardNumber','cardCode'].forEach(function(id){ document.getElementById(id).value=''; });
    fetch('./front_payment_cc.php', { method:'POST', body: new FormData(oForm) })
        .then(function(r){ if (!r.ok) throw Error(r.statusText); return r.json(); })
        .then(function(data) {
            if (data.status !== 'ok') { alert(JSON.stringify(data)); return; }
            var s = document.getElementById('form_source'); if (s) s.value = data.authCode;
            closeCCModal();
            alert(chargeMsg+'\nAuth: '+data.authCode+' TransId: '+data.transId);
            document.querySelector("[name='form_save']").click();
        })
        .catch(function(err){ alert(err); });
}
<?php endif; ?>

<?php if ($GLOBALS['payment_gateway'] == 'Stripe'): ?>
var stripeObj  = Stripe(publicKey);
var stripeEls  = stripeObj.elements();
var stripeCard = stripeEls.create('card', {
    style:{ base:{ color:'#1a1d2e', fontSize:'14px', '::placeholder':{ color:'#9197b3' } }, invalid:{ color:'#e03131' } }
});
stripeCard.mount('#card-element');
stripeCard.addEventListener('change', function(e) {
    var el = document.getElementById('card-errors'); if (el) el.textContent = e.error ? e.error.message : '';
});
document.getElementById('stripeSubmit').addEventListener('click', function(e) {
    e.preventDefault();
    stripeObj.createToken(stripeCard).then(function(result) {
        if (result.error) { var el=document.getElementById('card-errors'); if(el) el.textContent=result.error.message; }
        else stripeTokenHandler(result.token);
    });
});
function stripeTokenHandler(token) {
    var oForm = document.forms['stripe-cc-form'];
    oForm.elements['stripe_mode'].value = 'Stripe';
    var invDates='', i=0;
    document.querySelectorAll('input[name^="form_upay["]').forEach(function(inp) {
        if (i>=5||!inp.value||parseFloat(inp.value)<=0) return;
        var row=inp.closest('tr'); if (row&&row.cells[0]) invDates+='item'+(++i)+': '+row.cells[0].innerText+' ';
    });
    oForm.elements['encs'].value = invDates;
    var hi=document.createElement('input'); hi.type='hidden'; hi.name='stripeToken'; hi.value=token.id; oForm.appendChild(hi);
    fetch('./front_payment_cc.php', { method:'POST', body: new FormData(oForm) })
        .then(function(r){ if (!r.ok) throw Error(r.statusText); return r.json(); })
        .then(function(data) {
            if (data.status!=='ok') { alert(JSON.stringify(data)); return; }
            var s=document.getElementById('form_source'); if(s) s.value=data.transId;
            closeCCModal();
            alert(chargeMsg+'\nAuth: '+data.authCode+' TransId: '+data.transId);
            document.querySelector("[name='form_save']").click();
        })
        .catch(function(err){ alert(err.message); });
}
<?php if (!empty($GLOBALS['cc_stripe_terminal'])): ?>
function posDialog() {
    var total=parseFloat(document.getElementById('form_paytotal').value)||0;
    var prepEl=document.getElementById('form_prepayment');
    var prepay=prepEl?parseFloat(prepEl.value)||0:0;
    if (total<0.01&&prepay<0.01){alert(<?php echo xlj('Please enter a payment amount'); ?>);return;}
    var amt=total>=0.01?total.toFixed(2):prepay.toFixed(2);
    dlgopen('./front_payment_terminal.php?total='+encodeURIComponent(amt),'terminal','modal-md',550,'',
        <?php echo xlj('POS Payment'); ?>,{buttons:[{text:<?php echo xlj('Cancel'); ?>,close:false,id:'closeBtn',style:'secondary btn-cancel'}]});
}
<?php endif; ?>
<?php endif; ?>

<?php if ($GLOBALS['payment_gateway'] == 'Sphere'): ?>
<?php echo (new SpherePayment('clinic', $pid))->renderSphereJs(); ?>
<?php endif; ?>


<?php endif; // cc_front_payments ?>
<script>
// ── activatePaymentMode(mode) ─────────────────────────────────────────────────
// Single source of truth for showing/hiding sub-panels inside panel-payment.
// mode: 'payment' | 'adjustment' | 'transfer'
// When mode='payment', also respects the current Insurance/Patient src toggle.
function activatePaymentMode(mode) {
    var eobDiv = document.getElementById('eob-panel');
    var patDiv = document.getElementById('patient-pay-panel');
    var patSec = document.getElementById('payment-mode-section-pat');
    var adjDiv = document.getElementById('adjustment-mode-section');
    var trDiv  = document.getElementById('transfer-mode-section');
    var encTbl = document.getElementById('enc-table-card');

    // Hide everything first
    [eobDiv, patDiv, patSec, adjDiv, trDiv].forEach(function(el){ if (el) el.style.display = 'none'; });

    if (mode === 'adjustment') {
        if (adjDiv) adjDiv.style.display = 'block';
        if (typeof _adjAllLoaded !== 'undefined' && !_adjAllLoaded) loadAllAdjLines();

    } else if (mode === 'transfer') {
        if (trDiv) trDiv.style.display = 'block';

    } else {
        // 'payment' — check src toggle
        var srcIns = document.getElementById('src-ins');
        var isIns  = srcIns && srcIns.classList.contains('active');
        if (isIns) {
            if (eobDiv) eobDiv.style.display = 'block';
        } else {
            if (patDiv) patDiv.style.display = 'block';
            if (patSec) patSec.style.display = 'block';
            if (encTbl) encTbl.style.display = 'block';
        }
    }
}

document.querySelectorAll('.mode-tab').forEach(function(tab){
tab.addEventListener('click', function(e){
    e.preventDefault();
    document.querySelectorAll('.mode-tab').forEach(function(t){ t.classList.remove('active'); });
    tab.classList.add('active');
    activatePaymentMode(tab.dataset.mode);
});
});

// ── EOB Manual Posting JS ─────────────────────────────────────────────────────

var _eobEncounters = <?php
    $enc_js = [];
    foreach ($encs as $e) {
        $bills = [];
        $bq = sqlStatement(
            "SELECT b.id, b.code, b.modifier, b.fee, LEFT(fe.date,10) AS encdate
               FROM billing b JOIN form_encounter fe ON fe.pid=b.pid AND fe.encounter=b.encounter
              WHERE b.pid=? AND b.encounter=? AND b.activity=1 AND b.fee>0 ORDER BY b.id",
            [$pid, $e['encounter']]
        );
        while ($br = sqlFetchArray($bq)) {
            // Calculate outstanding balance for this billing line
            $b_paid = (float)(sqlQuery(
                "SELECT IFNULL(SUM(pay_amount),0) AS s FROM ar_activity
                  WHERE pid=? AND encounter=? AND code=? AND deleted IS NULL",
                [$pid, $e['encounter'], $br['code']]
            )['s'] ?? 0);
            $b_adj = (float)(sqlQuery(
                "SELECT IFNULL(SUM(adj_amount),0) AS s FROM ar_activity
                  WHERE pid=? AND encounter=? AND code=? AND deleted IS NULL",
                [$pid, $e['encounter'], $br['code']]
            )['s'] ?? 0);
            $br['balance'] = round(max(0, $br['fee'] - $b_paid - abs($b_adj)), 2);
            $bills[] = $br;
        }
        $enc_js[] = [
            'encounter' => $e['encounter'],
            'encdate'   => $e['encdate'],
            'charges'   => floatval($e['charges']),
            'ins_name'  => $ins_name ?: xl('Self'),
            'billing'   => $bills,
        ];
    }
    echo json_encode($enc_js);
?>;

// ── EOB Calculation helpers ───────────────────────────────────────────────────

// Simple HTML escape for use inside JS-built innerHTML strings
function escH(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Lightweight toast notification — reuses the file's existing .alert CSS classes.
// Creates a fixed-position banner at the top of the modal, auto-dismisses after 3s.
function showToast(msg, type) {
    var existing = document.getElementById('mdi-toast-banner');
    if (existing) existing.remove();
    var div = document.createElement('div');
    div.id = 'mdi-toast-banner';
    div.className = 'alert ' + (type === 'error' ? 'alert-error' : 'alert-success');
    div.textContent = msg;
    div.style.cssText = 'position:fixed;top:14px;left:50%;transform:translateX(-50%);z-index:99999;'
        + 'min-width:260px;max-width:520px;text-align:center;padding:10px 20px;'
        + 'border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 4px 18px rgba(0,0,0,.18);'
        + 'transition:opacity .3s;';
    document.body.appendChild(div);
    setTimeout(function() {
        div.style.opacity = '0';
        setTimeout(function() { if (div.parentNode) div.remove(); }, 320);
    }, 2800);
}

// Called when Payment or Adjustment changes — auto-fills PR and updates Left
function eobLineChanged(inputEl) {
    var row     = inputEl.closest('tr');
    var charge  = parseFloat(row.dataset.charge || 0);
    var paidEl  = row.querySelector('.eob-paid-input');
    var adjEl   = row.querySelector('.eob-adj-input');
    var prEl    = row.querySelector('.eob-pr-input');
    var rsnEl   = row.querySelector('.eob-adj-reason-input');

    var paid = parseFloat((paidEl && paidEl.value) || 0);
    var adj  = parseFloat((adjEl  && adjEl.value)  || 0);
    var rsn  = rsnEl ? rsnEl.value : '';

    // Reasons where the leftover is a write-off (balance goes to $0, NOT to patient)
    var writeOffReasons = ['Write-Off','Contractual','Non-Covered'];
    var isWriteOff = writeOffReasons.indexOf(rsn) !== -1;

    var leftover = Math.max(0, Math.round((charge - paid - adj) * 100) / 100);

    if (isWriteOff) {
        // Auto-fill Adjustment with leftover; clear PR
        if (row.dataset.adjManual !== '1' && adjEl && adj === 0) {
            adjEl.value = leftover > 0 ? leftover.toFixed(2) : '';
        }
        if (prEl && row.dataset.prManual !== '1') {
            prEl.value = '';
            prEl.style.borderColor = '#c4b5fd';
            prEl.style.background  = '#faf5ff';
        }
    } else if (['Deductible','Copay','Coinsurance','Other'].indexOf(rsn) !== -1) {
        // Patient-facing reason explicitly chosen — carry leftover to PR
        if (row.dataset.prManual !== '1' && prEl) {
            prEl.value = leftover > 0 ? leftover.toFixed(2) : '';
            prEl.style.borderColor = '#c4b5fd';
            prEl.style.background  = '#faf5ff';
        }
    }
    // No reason selected: never auto-fill PR — the user only entered a payment amount

    eobRecalc();
}

// ── Adj reason changed — show/hide Non-Covered free-entry fields ──────────────
function eobAdjReasonChange(sel) {
    var row        = sel.closest('tr');
    var ncFields   = row.querySelector('.eob-noncovered-fields');
    var isNonCov   = sel.value === 'Non-Covered';

    if (ncFields) {
        ncFields.style.display = isNonCov ? 'block' : 'none';
        // When Non-Covered: highlight the adj input to prompt entry
        var adjEl = row.querySelector('.eob-adj-input');
        if (adjEl) {
            adjEl.style.borderColor = isNonCov ? '#f59e0b' : 'var(--border)';
            adjEl.style.background  = isNonCov ? '#fffbeb' : '';
            if (isNonCov) adjEl.focus();
        }
    }
    eobLineChanged(sel);
}

// ── Transfer checkbox changed — open transfer panel pre-filled with row data ──
function eobXferChange(chk) {
    var row      = chk.closest('tr');
    var xferCell = chk.closest('td');

    // Remove any existing destination select in this cell
    var existingSel = xferCell.querySelector('.eob-xfer-dest');
    if (existingSel) existingSel.remove();

    if (chk.checked) {
        var sel = document.createElement('select');
        sel.className = 'eob-xfer-dest';
        // Use min-content width and let the cell wrap — no fixed px width
        sel.style.cssText = 'display:block;width:100%;max-width:96px;margin-top:4px;'
            + 'border:1.5px solid #f59e0b;border-radius:4px;padding:4px 2px;'
            + 'font-size:10.5px;font-family:inherit;outline:none;'
            + 'background:#fffbeb;color:var(--text);cursor:pointer;';
        sel.innerHTML =
            '<option value="patient">'   + <?php echo json_encode(xlt('→ Patient'));   ?> + '<\/option>'
          + '<option value="secondary">' + <?php echo json_encode(xlt('→ Secondary')); ?> + '<\/option>'
          + '<option value="tertiary">'  + <?php echo json_encode(xlt('→ Tertiary'));  ?> + '<\/option>';
        sel.addEventListener('change', function() { eobXferDestChanged(sel, row); });
        xferCell.appendChild(sel);

        // Defer so the select is fully in the DOM before we read row structure
        setTimeout(function() { eobXferDestChanged(sel, row); }, 0);
    } else {
        // Unchecked — clear transfer, reset PR styling, recalc
        row.dataset.xferDest = '';
        var prEl = row.querySelector('.eob-pr-input');
        if (prEl) {
            prEl.value = '';
            prEl.style.borderColor = '#c4b5fd';
            prEl.style.background  = '#faf5ff';
        }
        var leftEl = row.querySelector('.eob-line-left');
        if (leftEl) { leftEl.textContent = '—'; leftEl.style.color = 'var(--text3)'; }
        eobRecalc();
    }
}

// Called when the Xfer destination dropdown changes value
// Pass `row` explicitly so we never rely on sel.closest('tr') before DOM settles
function eobXferDestChanged(sel, row) {
    if (!row) row = sel.closest('tr');
    if (!row) return;

    var dest    = sel.value;
    var charge  = parseFloat(row.dataset.charge || 0);
    var paidEl  = row.querySelector('.eob-paid-input');
    var adjEl   = row.querySelector('.eob-adj-input');
    var prEl    = row.querySelector('.eob-pr-input');
    var leftEl  = row.querySelector('.eob-line-left');

    var paid = parseFloat((paidEl && paidEl.value.replace(/[^0-9.-]/g,'')) || 0);
    var adj  = parseFloat((adjEl  && adjEl.value.replace(/[^0-9.-]/g,''))  || 0);
    var remaining = Math.max(0, Math.round((charge - paid - adj) * 100) / 100);

    // Store destination on the row so eobRecalc can use it
    row.dataset.xferDest = dest;

    if (dest === 'patient') {
        // Transfer to patient responsibility: PR = remaining balance
        if (prEl) {
            prEl.value = remaining.toFixed(2);
            prEl.style.borderColor = '#f59e0b';
            prEl.style.background  = '#fffbeb';
        }
    } else {
        // Secondary / Tertiary: balance moves to next payer, PR = 0
        if (prEl) {
            prEl.value = '';
            prEl.style.borderColor = '#c4b5fd';
            prEl.style.background  = '#faf5ff';
        }
    }

    // Left = 0 whenever any transfer destination is active
    if (leftEl) {
        leftEl.textContent = '$0.00';
        leftEl.style.color = 'var(--green)';
    }

    eobRecalc();
}


function eobPrManualChange(prEl) {
    var row    = prEl.closest('tr');
    var charge = parseFloat(row.dataset.charge || 0);
    var paidEl = row.querySelector('.eob-paid-input');
    var adjEl  = row.querySelector('.eob-adj-input');
    var paid   = parseFloat((paidEl && paidEl.value) || 0);
    var adj    = parseFloat((adjEl  && adjEl.value)  || 0);
    var pr     = parseFloat(prEl.value || 0);

    var expectedPr = Math.max(0, Math.round((charge - paid - adj) * 100) / 100);

    // If a transfer destination is active, always auto-update PR
    var dest = row.dataset.xferDest || '';
    if (dest === 'patient') {
        var xferSel = row.querySelector('.eob-xfer-dest');
        if (xferSel) eobXferDestChanged(xferSel, row);
        else { if (prEl) prEl.value = expectedPr.toFixed(2); eobRecalc(); }
        return;
    } else if (dest === 'secondary' || dest === 'tertiary') {
        if (prEl) prEl.value = '';
        eobRecalc();
        return;
    }

    // Mark as manually set so auto-fill won't override
    row.dataset.prManual = '1';

    // Visual warning if PR diverges from expected by more than $0.01
    if (Math.abs(pr - expectedPr) > 0.01 && (paid > 0 || adj > 0)) {
        prEl.style.borderColor = '#f97316';  // orange warning
        prEl.style.background  = '#fff7ed';
        prEl.title = 'Expected PR: $' + expectedPr.toFixed(2) + ' (Charge - Paid - Adj)';
    } else {
        prEl.style.borderColor = '#c4b5fd';
        prEl.style.background  = '#faf5ff';
        prEl.title = '';
        if (Math.abs(pr - expectedPr) <= 0.01) row.dataset.prManual = '0';
    }

    eobRecalc();
}

function eobRecalc() {
    var chargeTotal = 0;
    document.querySelectorAll('#eob-lines-tbody .eob-line-row').forEach(function(row) {
        chargeTotal += parseFloat(row.dataset.charge || 0);
    });
    chargeTotal = Math.round(chargeTotal * 100) / 100;

    var chargeTotalEl = document.getElementById('eob-charge-total');
    if (chargeTotalEl) chargeTotalEl.textContent = '$' + chargeTotal.toFixed(2);

    var totalPaid = 0, totalAdj = 0, totalPr = 0, totalXfer = 0;

    document.querySelectorAll('#eob-lines-tbody .eob-line-row').forEach(function(row) {
        var charge   = parseFloat(row.dataset.charge || 0);
        var dest     = row.dataset.xferDest || '';   // 'patient' | 'secondary' | 'tertiary' | ''
        var paidEl   = row.querySelector('.eob-paid-input');
        var adjEl    = row.querySelector('.eob-adj-input');
        var prEl     = row.querySelector('.eob-pr-input');
        var leftEl   = row.querySelector('.eob-line-left');

        var paid = parseFloat((paidEl && paidEl.value) || 0);
        var adj  = parseFloat((adjEl  && adjEl.value)  || 0);
        var pr   = parseFloat((prEl   && prEl.value)   || 0);

        var remaining = Math.max(0, Math.round((charge - paid - adj) * 100) / 100);

        // When transferring to patient, PR = remaining (already set by eobXferDestChanged,
        // but re-read here in case payment or adj changed afterwards)
        if (dest === 'patient') {
            pr = remaining;
            if (prEl) { prEl.value = pr > 0 ? pr.toFixed(2) : '0.00'; }
        } else if (dest === 'secondary' || dest === 'tertiary') {
            // Balance moves to another payer bucket — not patient PR
            pr = 0;
            if (prEl) { prEl.value = ''; }
            totalXfer += remaining;
        }

        totalPaid += paid;
        totalAdj  += adj;
        totalPr   += pr;

        // Per-line Left display
        if (leftEl) {
            var left;
            if (dest === 'patient') {
                // Charge = paid + adj + PR (all accounted for)
                left = Math.round((charge - paid - adj - pr) * 100) / 100;
            } else if (dest === 'secondary' || dest === 'tertiary') {
                // Remaining balance moves to next payer — left = 0
                left = 0;
            } else {
                left = Math.round((charge - paid - adj - pr) * 100) / 100;
            }

            var hasActivity = paid > 0 || adj > 0 || pr > 0 || dest !== '';
            if (hasActivity) {
                leftEl.textContent = '$' + Math.max(0, left).toFixed(2);
                leftEl.style.color = Math.abs(left) < 0.01 ? 'var(--green)' : '#f97316';
            } else {
                leftEl.textContent = '—';
                leftEl.style.color = 'var(--text3)';
            }
        }
    });

    totalPaid = Math.round(totalPaid * 100) / 100;
    totalAdj  = Math.round(totalAdj  * 100) / 100;
    totalPr   = Math.round(totalPr   * 100) / 100;
    totalXfer = Math.round(totalXfer * 100) / 100;

    var paidTotalEl = document.getElementById('eob-paid-total');
    var adjTotalEl  = document.getElementById('eob-adj-total');
    var prTotalEl   = document.getElementById('eob-pr-total');
    var unallocEl   = document.getElementById('eob-unalloc');
    var badgeEl     = document.getElementById('eob-balanced-badge');

    if (paidTotalEl) paidTotalEl.textContent = '$' + totalPaid.toFixed(2);
    if (adjTotalEl)  adjTotalEl.textContent  = '$' + totalAdj.toFixed(2);
    if (prTotalEl)   prTotalEl.textContent   = '$' + totalPr.toFixed(2);

    // Unallocated = Charge − Paid − Adj − PR − Xfer(secondary/tertiary)
    // When everything is properly distributed, this should be $0.00
    var unalloc = Math.round(
        (chargeTotal - totalPaid - totalAdj - totalPr - totalXfer) * 100
    ) / 100;

    if (unallocEl) {
        unallocEl.textContent = (unalloc < 0 ? '-$' : '$') + Math.abs(unalloc).toFixed(2);
        var isBalanced = Math.abs(unalloc) < 0.01 && (totalPaid > 0 || totalAdj > 0 || totalPr > 0 || totalXfer > 0);
        unallocEl.style.color = isBalanced ? '#6ee7b7' : (unalloc > 0 ? '#fca5a5' : '#fcd34d');
        if (badgeEl) badgeEl.style.display = isBalanced ? 'inline' : 'none';
    }

    // Auto-sync the Amount header field to total paid
    var amtEl = document.getElementById('eob-total-amount');
    if (amtEl && totalPaid > 0 && !amtEl.dataset.userEdited) amtEl.value = totalPaid.toFixed(2);

    // Null-safe legacy element updates
    var hdrAmountEl = document.getElementById('eob-hdr-amount');
    var remEl       = document.getElementById('eob-remaining');
    if (hdrAmountEl) hdrAmountEl.textContent = '$' + totalPaid.toFixed(2);
    if (remEl) { remEl.textContent = '$0.00'; remEl.style.color = 'var(--green)'; }
}

function eobFileSelected(input) {
    var nameEl  = document.getElementById('eob-file-name');
    var labelEl = document.getElementById('eob-file-label');
    if (input.files && input.files[0]) {
        var fname = input.files[0].name;
        if (nameEl)  nameEl.textContent = fname;
        if (labelEl) { labelEl.style.borderColor = 'var(--green)'; labelEl.style.borderStyle = 'solid'; labelEl.style.color = 'var(--green)'; }
    } else {
        if (nameEl)  nameEl.textContent = <?php echo json_encode(xlt('Choose file...')); ?>;
        if (labelEl) { labelEl.style.borderColor = 'var(--border)'; labelEl.style.borderStyle = 'dashed'; labelEl.style.color = 'var(--text3)'; }
    }
}

function eobAutoFillLine(allowedInput) {
    var row = allowedInput.closest('tr');
    var charge = parseFloat(row.dataset.charge || 0);
    var allowed = parseFloat(allowedInput.value || 0);
    if (allowed > 0) {
        var adj = Math.max(0, Math.round((charge - allowed) * 100) / 100);
        row.querySelector('.eob-adj-input').value = adj > 0 ? adj.toFixed(2) : '';
        // Leave paid blank for manual entry
    }
    eobRecalc();
}

function eobMoney(v) {
    v = String(v || '').replace(/[^0-9.-]/g, '');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
}

function eobSet(input, amount) {
    if (!input) return;
    input.value = amount > 0 ? amount.toFixed(2) : '';
}

function eobAutoCalc() {
    var paidTotal = 0;
    var adjTotal  = 0;
    var prTotal   = 0;

    document.querySelectorAll('#eob-lines-tbody .eob-line-row').forEach(function(row) {
        var charge = eobMoney(row.dataset.charge);

        var allowedInput = row.querySelector('.eob-allowed-input');
        var paidInput    = row.querySelector('.eob-paid-input');
        var adjInput     = row.querySelector('.eob-adj-input');
        var prInput      = row.querySelector('.eob-pr-input');

        var allowed = eobMoney(allowedInput.value);

        // Only auto-calculate rows where the user has entered an allowed amount
        // Rows with no allowed value are skipped entirely — don't touch them
        if (allowed <= 0) {
            // Still sum up any values already manually entered
            paidTotal += eobMoney(paidInput.value);
            adjTotal  += eobMoney(adjInput.value);
            prTotal   += eobMoney(prInput.value);
            return;
        }

        var paid = eobMoney(paidInput.value);
        var adj  = eobMoney(adjInput.value);
        var pr   = eobMoney(prInput.value);

        // If allowed is entered, calculate contractual adjustment
        if (charge > 0) {
            adj = Math.max(0, Math.round((charge - allowed) * 100) / 100);
            eobSet(adjInput, adj);
        }

        // If allowed + paid entered, calculate patient responsibility
        if (paid > 0) {
            pr = Math.max(0, Math.round((allowed - paid) * 100) / 100);
            eobSet(prInput, pr);
        }

        // If allowed + PR entered but paid blank, calculate paid
        if (pr > 0 && paid <= 0) {
            paid = Math.max(0, Math.round((allowed - pr) * 100) / 100);
            eobSet(paidInput, paid);
        }

        // If only allowed entered (no paid, no pr), default paid = allowed
        if (paid <= 0 && pr <= 0) {
            paid = allowed;
            eobSet(paidInput, paid);
        }

        paidTotal += paid;
        adjTotal  += adj;
        prTotal   += pr;
    });

    // Auto-fill EOB amount from total paid if not already set
    var eobAmountInput = document.getElementById('eob-total-amount');
    if (eobAmountInput && (!eobAmountInput.value || parseFloat(eobAmountInput.value) <= 0)) {
        eobAmountInput.value = paidTotal > 0 ? paidTotal.toFixed(2) : '';
    }

    eobRecalc();
}

function voidEobSession(sessionId, payerName) {
    if (!confirm(<?php echo json_encode(xlt('Void this entire EOB session for')); ?> + ' ' + payerName + '?\n\n' + <?php echo json_encode(xlt('This will reverse ALL transactions in the session and cannot be undone.')); ?>)) {
        return;
    }

    var fd = new FormData();
    fd.append('action',          'void_eob_ajax');
    fd.append('csrf_token_form', <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>);
    fd.append('void_session_id', sessionId);
    fd.append('void_pid',        <?php echo (int)$pid; ?>);

    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.success) {
                // Reload the page to reflect voided session
                location.reload();
            } else {
                alert(<?php echo json_encode(xlt('Error voiding session:')); ?> + ' ' + (d.error || <?php echo json_encode(xlt('Unknown error')); ?>));
            }
        })
        .catch(function(e) {
            console.error(e);
            alert(<?php echo json_encode(xlt('Network error. Please try again.')); ?>);
        });
}

/**
 * openEobForEnc(encId)
 * Called when the user clicks "Record Payment" on an encounter row in the
 * Transaction Ledger.  Navigates to the Insurance EOB panel, clears any
 * existing lines, and pre-populates the table with every billing line that
 * belongs to the selected encounter — exactly like the Clinic Pro screenshot:
 * one row per CPT, charge pulled from billing, all amount fields blank and
 * ready for the user to enter Allowed / Paid / Adj / PR.
 */
function openEobForEnc(encId) {
    // 1. Switch to payment panel
    switchTab('payment');

    // 2. Force Insurance EOB source active
    var srcIns = document.getElementById('src-ins');
    var srcPat = document.getElementById('src-pat');
    if (srcIns) srcIns.classList.add('active');
    if (srcPat) srcPat.classList.remove('active');

    // 3. Activate the Payment mode tab
    document.querySelectorAll('.mode-tab').forEach(function(t){ t.classList.remove('active'); });
    var payTab = document.querySelector('.mode-tab[data-mode="payment"]');
    if (payTab) payTab.classList.add('active');

    // 4. Show EOB panel, hide everything else
    activatePaymentMode('payment');

    // 5. Scroll to top of payment panel
    var pp = document.getElementById('panel-payment');
    if (pp) pp.scrollTop = 0;

    // If encId = 0 (Add Payment button) — just open with default preloaded rows
    if (!encId) {
        var ctxB = document.getElementById('eob-context-banner');
        if (ctxB) ctxB.style.display = 'none';
        return;
    }

    // Find this encounter's billing data
    var enc = null;
    for (var i = 0; i < _eobEncounters.length; i++) {
        if (parseInt(_eobEncounters[i].encounter) === parseInt(encId)) {
            enc = _eobEncounters[i]; break;
        }
    }
    if (!enc || !enc.billing || enc.billing.length === 0) {
        alert(<?php echo json_encode(xlt('No active billing lines with charges found for this encounter.')); ?>);
        return;
    }

    // Clear and repopulate EOB lines table
    var tbody = document.getElementById('eob-lines-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';

    // Reset header fields
    var amtEl = document.getElementById('eob-total-amount');
    var refEl = document.getElementById('eob-ref');
    var banner = document.getElementById('eob-result-banner');
    if (amtEl) amtEl.value = '';
    if (refEl) refEl.value = '';
    if (banner) { banner.style.display = 'none'; banner.textContent = ''; }

    // Default deposit date to today if blank
    var depEl = document.getElementById('eob-deposit-date');
    if (depEl && !depEl.value) {
        var t = new Date();
        depEl.value = t.getFullYear() + '-' + String(t.getMonth()+1).padStart(2,'0') + '-' + String(t.getDate()).padStart(2,'0');
    }

    // Re-enable post button
    _eobPosting = false;
    var postBtn = document.getElementById('eob-post-btn');
    if (postBtn) {
        postBtn.disabled = false;
        postBtn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?php echo addslashes(xlt('Post EOB & Create Transactions')); ?>';
    }

    // Build input rows
    var rs    = 'border:1.5px solid var(--border);border-radius:4px;padding:5px 6px;font-family:\'DM Mono\',monospace;font-size:12px;text-align:right;outline:none;box-sizing:border-box;';
    var rsGrn = rs + 'border-color:var(--green);background:#f0fdf4;width:78px;';
    var rsAmb = rs + 'width:74px;';
    var rsPrp = rs + 'border-color:#c4b5fd;background:#faf5ff;width:70px;';
        var adjRsnOpts = '<option value="">—<\/option>'
            + '<option value="Contractual"><?php echo addslashes(xlt('Contractual')); ?><\/option>'
            + '<option value="Copay"><?php echo addslashes(xlt('Copay')); ?><\/option>'
            + '<option value="Coinsurance"><?php echo addslashes(xlt('Coinsurance')); ?><\/option>'
            + '<option value="Deductible"><?php echo addslashes(xlt('Deductible')); ?><\/option>'
            + '<option value="Non-Covered"><?php echo addslashes(xlt('Non-Covered')); ?><\/option>'
            + '<option value="Write-Off"><?php echo addslashes(xlt('Write-Off')); ?><\/option>'
            + '<option value="Other"><?php echo addslashes(xlt('Other')); ?><\/option>';
        var trash = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/><\/svg>';

    enc.billing.forEach(function(b) {
        var tr = document.createElement('tr');
        tr.className = 'eob-line-row';
        tr.dataset.enc      = enc.encounter;
        tr.dataset.billing  = b.id;
        var lineBalance = (b.balance !== undefined) ? parseFloat(b.balance) : parseFloat(b.fee);
        tr.dataset.charge   = lineBalance.toFixed(2);
        tr.dataset.prManual = '0';
        var cpt          = b.code + (b.modifier ? ' (' + b.modifier + ')' : '');
        var carrierName  = enc.ins_name || '—';

        // Column order must EXACTLY match thead: Date | Carrier | CPT | Charge | Payment | Adj | Adj Reason | PR | Xfer | Left | Delete
        tr.innerHTML =
            // Date
            '<td style="padding:6px 10px;font-family:\'DM Mono\',monospace;font-size:11px;color:var(--text2);white-space:nowrap;width:88px;">' + enc.encdate + '<\/td>'
            // Carrier — actual name, not encounter number
          + '<td style="padding:6px 10px;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:90px;" title="' + escH(carrierName) + '">' + escH(carrierName) + '<\/td>'
            // CPT
          + '<td style="padding:6px 10px;font-family:\'DM Mono\',monospace;font-size:11px;font-weight:700;color:var(--purple);width:80px;">' + escH(cpt) + '<\/td>'
            // Charge
          + '<td style="padding:6px 10px;text-align:right;font-family:\'DM Mono\',monospace;font-weight:700;color:var(--text2);width:72px;">$' + lineBalance.toFixed(2) + '<\/td>'
            // Payment (green)
          + '<td style="padding:5px 8px;width:88px;"><input type="text" class="eob-paid-input" placeholder="0.00" style="width:100%;border:1.5px solid var(--green);border-radius:4px;padding:5px 6px;font-family:\'DM Mono\',monospace;font-size:12px;text-align:right;background:#f0fdf4;outline:none;" oninput="eobLineChanged(this)" onfocus="this.style.borderColor=\'var(--green)\'" onblur="this.style.borderColor=\'var(--green)\'"><\/td>'
            // Adjustment (amber)
          + '<td style="padding:5px 8px;width:82px;"><input type="text" class="eob-adj-input" placeholder="0.00" style="width:100%;border:1.5px solid var(--border);border-radius:4px;padding:5px 6px;font-family:\'DM Mono\',monospace;font-size:12px;text-align:right;outline:none;" oninput="eobLineChanged(this)" onfocus="this.style.borderColor=\'var(--amber)\'" onblur="this.style.borderColor=\'var(--border)\'"><\/td>'
            // Adj Reason dropdown + Non-Covered free fields
          + '<td style="padding:5px 6px;min-width:110px;">'
          +   '<select class="eob-adj-reason-input" style="width:100%;border:1.5px solid var(--border);border-radius:4px;padding:5px 4px;font-size:11px;font-family:inherit;outline:none;background:#fff;" onchange="eobAdjReasonChange(this)" onfocus="this.style.borderColor=\'var(--amber)\'" onblur="this.style.borderColor=\'var(--border)\'">'+adjRsnOpts+'<\/select>'
          +   '<div class="eob-noncovered-fields" style="display:none;margin-top:4px;">'
          +     '<input type="text" class="eob-nc-code" placeholder="<?php echo addslashes(xla('Reason code')); ?>" style="width:100%;margin-bottom:3px;border:1.5px solid #f59e0b;border-radius:4px;padding:4px 5px;font-size:11px;font-family:\'DM Mono\',monospace;outline:none;background:#fffbeb;" oninput="eobLineChanged(this)">'
          +     '<input type="text" class="eob-nc-desc" placeholder="<?php echo addslashes(xla('Description (optional)')); ?>" style="width:100%;border:1.5px solid #f59e0b;border-radius:4px;padding:4px 5px;font-size:11px;font-family:inherit;outline:none;background:#fffbeb;" oninput="eobLineChanged(this)">'
          +   '<\/div>'
          + '<\/td>'
            // PR (purple)
          + '<td style="padding:5px 8px;width:78px;"><input type="text" class="eob-pr-input" placeholder="0.00" style="width:100%;border:1.5px solid #c4b5fd;border-radius:4px;padding:5px 6px;font-family:\'DM Mono\',monospace;font-size:12px;text-align:right;background:#faf5ff;outline:none;" oninput="eobPrManualChange(this)" onfocus="this.style.borderColor=\'var(--purple)\'" onblur="this.style.borderColor=\'#c4b5fd\'"><\/td>'
            // Xfer checkbox (amber) — matches PHP pre-rendered row exactly
          + '<td style="padding:5px 8px;text-align:center;width:96px;vertical-align:top;">'
          + '<label title="<?php echo addslashes(xla('Transfer remaining balance to Patient Responsibility')); ?>" style="cursor:pointer;">'
          + '<input type="checkbox" class="eob-xfer-chk" style="accent-color:#f59e0b;width:16px;height:16px;cursor:pointer;" onchange="eobXferChange(this)">'
          + '<\/label>'
          + '<\/td>'
            // Left (unallocated)
          + '<td style="padding:5px 8px;text-align:right;width:56px;"><span class="eob-line-left" style="font-family:\'DM Mono\',monospace;font-size:11px;font-weight:700;color:var(--text3);">—<\/span><\/td>'
            // Delete
          + '<td style="padding:5px 4px;text-align:center;width:32px;"><button type="button" onclick="eobRemoveLine(this)" style="background:none;border:none;cursor:pointer;color:var(--red);padding:3px;border-radius:3px;" title="<?php echo addslashes(xla('Remove line')); ?>">'+trash+'<\/button><\/td>';
        tbody.appendChild(tr);
    });

    // Show context banner
    var ctxBanner = document.getElementById('eob-context-banner');
    var ctxText = document.getElementById('eob-context-text');
    if (ctxBanner && ctxText) {
        ctxText.textContent = <?php echo json_encode(xlt('Posting payment for')); ?>
            + ' ' + enc.encdate + '  |  Enc #' + enc.encounter
            + '  —  ' + enc.billing.length + ' ' + <?php echo json_encode(xlt('line(s)')); ?>;
        ctxBanner.style.display = 'flex';
    }

    eobRecalc();
}

/**
 * eobClearContext — clears the encounter context, resets the lines table to
 * the default pre-populated state (all encounters, most recent 5).
 */
function eobClearContext() {
    var ctxBanner = document.getElementById('eob-context-banner');
    if (ctxBanner) ctxBanner.style.display = 'none';
    // Reload the page to restore the default pre-populated rows
    location.reload();
}

function eobRemoveLine(btn) {
    var row = btn.closest('tr');
    if (document.querySelectorAll('#eob-lines-tbody .eob-line-row').length <= 1) {
        alert(<?php echo json_encode(xlt('At least one line is required.')); ?>);
        return;
    }
    row.remove();
    eobRecalc();
}

function eobAddLine() {
    // Build a picker dialog (inline dropdown)
    var tbody = document.getElementById('eob-lines-tbody');

    // Build encounter + billing options
    var opts = '<option value=""><?php echo addslashes(xlt('-- Select line --')); ?></option>';
    _eobEncounters.forEach(function(enc) {
        enc.billing.forEach(function(b) {
            var bBal = (b.balance !== undefined) ? parseFloat(b.balance) : parseFloat(b.fee);
            opts += '<option value="' + enc.encounter + '|' + b.id + '|' + bBal.toFixed(2) + '|' + enc.encdate + '|' + b.code + '">'
                  + enc.encdate + ' — Enc #' + enc.encounter + ' — ' + b.code + ' (Bal: $' + bBal.toFixed(2) + ')'
                  + '</option>';
        });
    });

    var picker = document.createElement('tr');
    picker.className = 'eob-add-picker';
    picker.innerHTML = '<td colspan="9" style="padding:10px 14px;">'
        + '<div style="display:flex;align-items:center;gap:10px;">'
        + '<select id="eob-add-sel" style="flex:1;border:1.5px solid var(--purple);border-radius:var(--r-sm);padding:7px 10px;font-size:13px;">' + opts + '</select>'
        + '<button type="button" onclick="eobConfirmAddLine()" style="background:var(--purple);color:#fff;border:none;border-radius:var(--r-sm);padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;">'
        + '<?php echo addslashes(xlt('Add')); ?>'
        + '</button>'
        + '<button type="button" onclick="this.closest(\'tr\').remove()" style="background:#fff;border:1.5px solid var(--border);border-radius:var(--r-sm);padding:7px 10px;font-size:12px;cursor:pointer;font-family:inherit;">'
        + '<?php echo addslashes(xlt('Cancel')); ?>'
        + '</button>'
        + '</div></td>';
    tbody.appendChild(picker);
    document.getElementById('eob-add-sel').focus();
}

function eobConfirmAddLine() {
    var sel = document.getElementById('eob-add-sel');
    if (!sel || !sel.value) return;
    var parts   = sel.value.split('|');
    var enc     = parts[0], bid = parts[1], charge = parts[2], encdate = parts[3], code = parts[4];

    var tbody = document.getElementById('eob-lines-tbody');
    // Remove picker row
    var picker = tbody.querySelector('.eob-add-picker');
    if (picker) picker.remove();

    var tr = document.createElement('tr');
    tr.className = 'eob-line-row';
    tr.dataset.enc     = enc;
    tr.dataset.billing = bid;
    tr.dataset.charge  = charge;
    tr.dataset.prManual = '0';
    var rsB   = 'border:1.5px solid var(--border);border-radius:4px;padding:5px 6px;font-family:\'DM Mono\',monospace;font-size:12px;text-align:right;outline:none;box-sizing:border-box;';
    var adjOpts = '<option value="">—<\/option><option value="Contractual"><?php echo addslashes(xlt('Contractual')); ?><\/option><option value="Copay"><?php echo addslashes(xlt('Copay')); ?><\/option><option value="Coinsurance"><?php echo addslashes(xlt('Coinsurance')); ?><\/option><option value="Deductible"><?php echo addslashes(xlt('Deductible')); ?><\/option><option value="Non-Covered"><?php echo addslashes(xlt('Non-Covered')); ?><\/option><option value="Write-Off"><?php echo addslashes(xlt('Write-Off')); ?><\/option><option value="Other"><?php echo addslashes(xlt('Other')); ?><\/option>';
    tr.innerHTML =
        '<td style="padding:6px 8px;font-family:\'DM Mono\',monospace;font-size:11px;color:var(--text2);white-space:nowrap;">'+encdate+'<\/td>'
      + '<td style="padding:6px 8px;font-size:11px;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">'+enc+'<\/td>'
      + '<td style="padding:6px 8px;font-family:\'DM Mono\',monospace;font-size:11px;font-weight:700;color:var(--purple);">'+code+'<\/td>'
      + '<td style="padding:6px 8px;text-align:right;font-family:\'DM Mono\',monospace;font-weight:700;color:var(--text2);">$'+parseFloat(charge).toFixed(2)+'<\/td>'
      + '<td style="padding:5px 6px;"><input type="text" class="eob-paid-input" placeholder="0.00" style="'+rsB+'border-color:var(--green);background:#f0fdf4;width:78px;" oninput="eobLineChanged(this)" onfocus="this.style.borderColor=\'var(--green)\'" onblur="this.style.borderColor=\'var(--green)\'"><\/td>'
      + '<td style="padding:5px 5px;"><input type="text" class="eob-adj-input"  placeholder="0.00" style="'+rsB+'width:74px;" oninput="eobLineChanged(this)" onfocus="this.style.borderColor=\'var(--amber)\'" onblur="this.style.borderColor=\'var(--border)\'"><\/td>'
      + '<td style="padding:5px 4px;"><select class="eob-adj-reason-input" style="width:100%;min-width:92px;border:1.5px solid var(--border);border-radius:4px;padding:5px 4px;font-size:11px;font-family:inherit;outline:none;background:#fff;">'+adjOpts+'<\/select><\/td>'
      + '<td style="padding:5px 5px;"><input type="text" class="eob-pr-input"   placeholder="0.00" style="'+rsB+'border-color:#c4b5fd;background:#faf5ff;width:70px;" oninput="eobPrManualChange(this)" onfocus="this.style.borderColor=\'var(--purple)\'" onblur="this.style.borderColor=\'#c4b5fd\'"><\/td>'
      + '<td style="padding:5px 8px;text-align:right;"><span class="eob-line-left" style="font-family:\'DM Mono\',monospace;font-size:11px;font-weight:700;color:var(--text3);">—<\/span><\/td>'
      + '<td style="padding:5px 4px;text-align:center;"><button type="button" onclick="eobRemoveLine(this)" style="background:none;border:none;cursor:pointer;color:var(--red);padding:3px;border-radius:3px;" title="Remove"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/><\/svg><\/button><\/td>';
    tbody.appendChild(tr);
    eobRecalc();
}

function eobCollectLines() {
    var lines = [];
    document.querySelectorAll('#eob-lines-tbody .eob-line-row').forEach(function(row) {
        var paidEl      = row.querySelector('.eob-paid-input');
        var adjEl       = row.querySelector('.eob-adj-input');
        var prEl        = row.querySelector('.eob-pr-input');
        var adjRsnEl    = row.querySelector('.eob-adj-reason-input');
        var paid = paidEl ? parseFloat(paidEl.value || 0) : 0;
        var adj  = adjEl  ? parseFloat(adjEl.value  || 0) : 0;
        var pr   = prEl   ? parseFloat(prEl.value   || 0) : 0;
        if (paid === 0 && adj === 0 && pr === 0) return;
        lines.push({
            enc:          row.dataset.enc,
            billing_id:   row.dataset.billing,
            paid:         paid.toFixed(2),
            adjustment:   adj.toFixed(2),
            patient_resp: pr.toFixed(2),
            adj_reason:   adjRsnEl ? adjRsnEl.value : '',
        });
    });
    return lines;
}

// Prevent Enter key inside EOB panel from triggering any form submission
document.addEventListener('keydown', function(e) {
    if (e.key !== 'Enter') return;
    var eobPanel = document.getElementById('eob-panel');
    if (eobPanel && eobPanel.contains(e.target)) {
        e.preventDefault();
    }
});

var _eobPosting = false;

function eobPost() {
    if (_eobPosting) return;

    var lines = eobCollectLines();

    if (lines.length === 0) {
        alert(<?php echo json_encode(xlt('Please enter a payment amount for at least one line.')); ?>);
        return;
    }

    var totalPaid = lines.reduce(function(s, l) { return s + parseFloat(l.paid); }, 0);
    var totalAdj  = lines.reduce(function(s, l) { return s + parseFloat(l.adjustment); }, 0);
    var totalPR   = lines.reduce(function(s, l) { return s + parseFloat(l.patient_resp || 0); }, 0);

    // Allow $0 paid as long as there is an adjustment or patient responsibility entered.
    // This covers the denial / contractual write-off workflow where insurance pays nothing.
    if (totalPaid < 0.005 && totalAdj < 0.005 && totalPR < 0.005) {
        alert(<?php echo json_encode(xlt('Please enter a payment, adjustment, or patient responsibility amount on at least one line.')); ?>);
        return;
    }

    // Use total paid as the EOB amount ($0 is valid for a denial)
    var eobAmt = Math.round(totalPaid * 100) / 100;
    var amtEl = document.getElementById('eob-total-amount');
    if (amtEl) amtEl.value = eobAmt.toFixed(2);

    // Lock out further submissions
    _eobPosting = true;
    var btn = document.getElementById('eob-post-btn');
    btn.disabled = true;
    btn.textContent = <?php echo json_encode(xlt('Posting...')); ?>;

    // Generate a one-time nonce for server-side dedup
    var nonce = Date.now().toString(36) + Math.random().toString(36).slice(2);
    var nonceEl = document.getElementById('eob-nonce');
    if (nonceEl) nonceEl.value = nonce;

    var fd = new FormData();
    fd.append('action',           'post_eob_ajax');
    fd.append('csrf_token_form',  <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>);
    fd.append('eob_pid',          <?php echo (int)$pid; ?>);
    fd.append('eob_amount',       eobAmt.toFixed(2));
    fd.append('eob_method',       document.getElementById('eob-method').value);
    fd.append('eob_ref',          document.getElementById('eob-ref').value);
    fd.append('eob_deposit_date', document.getElementById('eob-deposit-date').value || '');
    fd.append('eob_claim_number', document.getElementById('eob-claim-number').value || '');
    fd.append('eob_trace',        document.getElementById('eob-trace').value || '');
    fd.append('eob_memo',         document.getElementById('eob-memo').value || '');
    fd.append('eob_nonce',        nonce);
    // Attach EOB file if selected
    var fileInput = document.getElementById('eob-file-input');
    if (fileInput && fileInput.files && fileInput.files[0]) {
        fd.append('eob_file', fileInput.files[0]);
    }
    lines.forEach(function(line, i) {
        Object.keys(line).forEach(function(k) {
            fd.append('eob_lines[' + i + '][' + k + ']', line[k]);
        });
    });

    fetch('mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            var banner = document.getElementById('eob-result-banner');
            banner.style.display = '';
            if (d.success) {
                banner.className = 'alert alert-success';
                banner.textContent = d.message || <?php echo json_encode(xlt('EOB posted successfully.')); ?>;
                // Keep button disabled — page is reloading
                setTimeout(function() { location.reload(); }, 1800);
            } else {
                banner.className = 'alert alert-error';
                banner.textContent = d.error || <?php echo json_encode(xlt('Failed to post EOB.')); ?>;
                // Re-enable on error
                _eobPosting = false;
                btn.disabled = false;
                btn.innerHTML = '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?php echo addslashes(xlt('Post EOB & Create Transactions')); ?>';
            }
        })
        .catch(function(e) {
            console.error(e);
            alert(<?php echo json_encode(xlt('Network error. Please try again.')); ?>);
            _eobPosting = false;
            btn.disabled = false;
        });
}

function eobSaveDraft() {
    // Save draft: store EOB state in sessionStorage for recovery
    var state = {
        amount:     document.getElementById('eob-total-amount').value,
        method:     document.getElementById('eob-method').value,
        ref:        document.getElementById('eob-ref').value,
        deposit:    document.getElementById('eob-deposit-date').value,
        claim:      document.getElementById('eob-claim-number').value,
        trace:      document.getElementById('eob-trace').value,
        memo:       document.getElementById('eob-memo').value,
        lines:      eobCollectLines(),
    };
    try {
        sessionStorage.setItem('eob_draft_<?php echo (int)$pid; ?>', JSON.stringify(state));
        var banner = document.getElementById('eob-result-banner');
        banner.className = 'alert alert-success';
        banner.textContent = <?php echo json_encode(xlt('Draft saved. It will be restored if you reload this page.')); ?>;
        banner.style.display = '';
        setTimeout(function() { banner.style.display = 'none'; }, 3500);
    } catch(e) {}
}

// Restore EOB draft on load
(function() {
    try {
        var saved = sessionStorage.getItem('eob_draft_<?php echo (int)$pid; ?>');
        if (!saved) return;
        var state = JSON.parse(saved);
        if (state.amount)     document.getElementById('eob-total-amount').value   = state.amount;
        if (state.method)     document.getElementById('eob-method').value         = state.method;
        if (state.ref)        document.getElementById('eob-ref').value            = state.ref;
        if (state.deposit)    document.getElementById('eob-deposit-date').value   = state.deposit;
        if (state.claim)      document.getElementById('eob-claim-number').value   = state.claim;
        if (state.trace)      document.getElementById('eob-trace').value          = state.trace;
        if (state.memo)       document.getElementById('eob-memo').value           = state.memo;
        eobRecalc();
    } catch(e) {}
})();
// ── End EOB JS ────────────────────────────────────────────────────────────────

// ── Search Payments inline edit JS ───────────────────────────────────────────
var _sprOpenId = null;

function sprToggleEdit(sessionId) {
    var editRow = document.getElementById('spr-edit-' + sessionId);
    var displayRow = document.getElementById('spr-row-' + sessionId);
    if (!editRow) return;

    // Close previously open editor
    if (_sprOpenId && _sprOpenId !== sessionId) {
        var prev = document.getElementById('spr-edit-' + _sprOpenId);
        if (prev) prev.style.display = 'none';
    }

    var isOpen = editRow.style.display !== 'none';
    editRow.style.display = isOpen ? 'none' : '';
    _sprOpenId = isOpen ? null : sessionId;
}

function sprDelete(sessionId) {
    if (!confirm(<?php echo json_encode(xlt('Permanently delete this payment? This cannot be undone.')); ?>)) return;
    top.restoreSession && top.restoreSession();
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=search';
    var fields = {
        csrf_token_form:   '<?php echo addslashes(CsrfUtils::collectCsrfToken()); ?>',
        delete_payment:    '1',
        delete_session_id: sessionId
    };
    for (var k in fields) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = fields[k];
        form.appendChild(inp);
    }
    document.body.appendChild(form);
    form.submit();
}

function sprSave(e, sessionId) {
    // Standard form submit — PHP handles it and redirects back
    // Just show a brief saving indicator
    var msgEl = document.getElementById('spr-save-msg-' + sessionId);
    if (msgEl) msgEl.textContent = <?php echo json_encode(xlt('Saving...')); ?>;
    // Allow normal form submit to proceed
}
// ── End Search Payments JS ────────────────────────────────────────────────────

// ── Auto-activate correct panel on load ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    <?php if ($preselect_enc > 0): ?>
    // enc= param present — open Insurance EOB panel for that encounter
    switchTab('payment');
    var srcIns = document.getElementById('src-ins');
    var srcPat = document.getElementById('src-pat');
    if (srcIns) srcIns.classList.add('active');
    if (srcPat) srcPat.classList.remove('active');
    document.querySelectorAll('.mode-tab').forEach(function(t){ t.classList.remove('active'); });
    var payTab = document.querySelector('.mode-tab[data-mode="payment"]');
    if (payTab) payTab.classList.add('active');
    activatePaymentMode('payment');
    <?php else: ?>
    // Default — always open Transaction Ledger
    switchTab('<?php echo addslashes($active_tab); ?>');
    <?php endif; ?>
    eobRecalc();
});
// ─────────────────────────────────────────────────────────────────────────────

// ── Toggle credits tables open/closed ──────────────────────────────────────────
function toggleCreditsTable(which) {
    var wrap    = document.getElementById(which === 'ins' ? 'ins-credits-table-wrap' : 'pat-credits-table-wrap');
    var chevron = document.getElementById(which === 'ins' ? 'ins-credits-chevron'    : 'pat-credits-chevron');
    if (!wrap) return;
    var open = wrap.style.display !== 'none';
    wrap.style.display = open ? 'none' : '';
    if (chevron) chevron.style.transform = open ? '' : 'rotate(90deg)';
}
// ── Toggle transfer sidebar ──────────────────────────────────────────
function toggleTransferPanel(show)
{
    var panel = document.getElementById('transferSlideout');

    if (!panel) return;

    panel.style.display = show ? 'block' : 'none';

    if (show) {
        adjUpdateTransferSummary();
    }
}

// ── Refresh insurance credits display pill + footer ────────────────────────────
function refreshInsuranceCreditDisplay() {
    var rows  = document.querySelectorAll('#insuranceCreditsBody tr:not(#ins-credits-empty)');
    var count = rows.length;
    var total = 0;
    rows.forEach(function(row) { total += parseFloat(row.dataset.available || 0); });

    // Update pill
    var pill = document.getElementById('insCreditSummary');
    if (pill) pill.textContent = count + ' Credit' + (count === 1 ? '' : 's') + ' • $' + fmt2(total) + ' Available';

    // Show/hide empty row
    var emptyRow = document.getElementById('ins-credits-empty');
    if (emptyRow) emptyRow.style.display = count > 0 ? 'none' : '';

    // Show/hide footer total
    var foot = document.getElementById('ins-credits-foot');
    var footTotal = document.getElementById('ins-credits-total');
    if (foot) foot.style.display = count > 0 ? '' : 'none';
    if (footTotal) footTotal.textContent = '$' + fmt2(total);
}

// ── Render a single insurance credit row into #insuranceCreditsBody ─────────────
// Called by reloadInsuranceCredits() AJAX handler
function renderInsuranceCreditsRow(cr) {
    // cr = { session_id, check_date, carrier, reference, pay_total, applied, available }
    var avail = parseFloat(cr.available || 0);
    var tr = document.createElement('tr');
    tr.id = 'ins-cr-' + cr.session_id;
    tr.dataset.available = avail;
    tr.style.borderBottom = '1px solid #e0e7ff';
    tr.onmouseover = function(){ this.style.background = '#eef2ff'; };
    tr.onmouseout  = function(){ this.style.background = ''; };

    var date     = (cr.check_date || '').substring(0, 10);
    var carrier  = cr.carrier  || '—';
    var ref      = cr.reference || '—';
    var original = parseFloat(cr.pay_total || 0);
    var applied  = parseFloat(cr.applied   || 0);

    tr.innerHTML =
        '<td style="padding:8px 10px;font-family:\'DM Mono\',monospace;font-size:11px;white-space:nowrap;">' + escHtmlCr(date)    + '</td>' +
        '<td style="padding:8px 10px;font-size:11px;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + escHtmlCr(carrier) + '">' + escHtmlCr(carrier) + '</td>' +
        '<td style="padding:8px 10px;font-family:\'DM Mono\',monospace;font-size:11px;white-space:nowrap;">' + escHtmlCr(ref) + '</td>' +
        '<td style="padding:8px 10px;text-align:right;font-family:\'DM Mono\',monospace;font-size:11px;white-space:nowrap;">$' + fmt2(original) + '</td>' +
        '<td style="padding:8px 10px;text-align:right;font-family:\'DM Mono\',monospace;font-size:11px;color:var(--green);white-space:nowrap;">$' + fmt2(applied) + '</td>' +
        '<td style="padding:8px 10px;text-align:right;font-family:\'DM Mono\',monospace;font-size:11px;font-weight:700;color:#4f46e5;white-space:nowrap;">$' + fmt2(avail) + '</td>' +
        '<td style="padding:8px 10px;text-align:center;">' +
          '<button type="button" onclick="applyOneInsCredit(' + cr.session_id + ',' + avail + ')" ' +
          'style="background:none;border:1.5px solid #4f46e5;color:#4f46e5;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:600;cursor:pointer;">' +
          <?php echo json_encode(xlt('Apply')); ?> +
          '</button>' +
        '</td>';
    return tr;
}

function transferDestinationChanged(sel) {

var row = sel.closest('tr');
if (!row) return;

var dest = sel.value;

var leftCell = row.querySelector('.left-bal');
var prInput  = row.querySelector('.pr-input');
var adjInput = row.querySelector('.adj-input');

var balance = parseFloat(row.dataset.balance || 0);

if (dest === 'patient') {

    if (prInput) {
        prInput.value = balance.toFixed(2);
        prInput.dispatchEvent(new Event('input'));
    }

    if (adjInput) {
        adjInput.value = '0.00';
        adjInput.dispatchEvent(new Event('input'));
    }

} else if (dest === 'secondary' || dest === 'tertiary') {

    if (prInput) {
        prInput.value = '0.00';
        prInput.dispatchEvent(new Event('input'));
    }

    // leave balance for transfer processing
}


}



function escHtmlCr(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

</script>
</body>
</html>
