<?php
/**
 * mdi_payments_ledger.php
 *
 * MDItek unified Payments & Ledger panel.
 * Replaces the separate front_payment.php / pat_ledger.php popups with a
 * single iframe-friendly page that handles BOTH insurance and patient payments
 * and shows the full transaction ledger — all wired to the live OpenEMR DB.
 *
 * Drop in:  interface/patient_file/mdi_payments_ledger.php
 * Open via: dlgopen('../patient_file/mdi_payments_ledger.php?pid=<pid>','',1100,720,...)
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
if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
}

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
$save_msg = '';
$save_err = '';

function safe($row, $key, $default = 0) {
    return isset($row[$key]) ? $row[$key] : $default;
}

if (!empty($_POST['form_save'])) {

    $form_pid    = $_POST['form_pid'];
    $form_method = trim($_POST['form_method']);
    $form_source = trim($_POST['form_source'] ?? '');
    $form_coverage = trim($_POST['radio_type_of_coverage'] ?? 'patient');
    $timestamp   = date('Y-m-d H:i:s');

    // ── iPOSpays: callback already wrote ar_session but only for one encounter.
    // We reuse that session_id and post ar_activity lines for every encounter
    // that has a form_upay value, then skip the normal ar_session INSERT below.
    $ipos_session_id = intval($_POST['ipos_session_id'] ?? 0);
    if ($ipos_session_id > 0 && !empty($_POST['form_upay'])) {
        $timestamp = date('Y-m-d H:i:s');

        // Delete the single ar_activity the callback wrote so we can re-post
        // correctly across all encounters from the apply modal.
        sqlStatement(
            "DELETE FROM ar_activity WHERE session_id = ? AND pid = ?",
            [$ipos_session_id, $form_pid]
        );

        $total_applied = 0;
        foreach ($_POST['form_upay'] as $enc => $payment) {
            $payment = round(floatval($payment), 2);
            if ($payment <= 0) continue;
            $enc = intval($enc);
            if (!$enc) continue;

            // Fetch billing code for this encounter
            $brow = sqlQuery(
                "SELECT * FROM billing LEFT JOIN code_types ON billing.code_type=code_types.ct_key
                 WHERE code_types.ct_fee=1 AND billing.activity!=0 AND billing.pid=? AND billing.encounter=?
                 ORDER BY billing.code,billing.modifier LIMIT 1",
                [$form_pid, $enc]
            );
            if (!$brow) {
                $brow = sqlQuery(
                    "SELECT code_type, code, modifier FROM billing
                     WHERE activity!=0 AND pid=? AND encounter=? AND fee!=0
                     ORDER BY code,modifier LIMIT 1",
                    [$form_pid, $enc]
                );
            }
            $Codetype = $brow['code_type'] ?? 'COPAY';
            $Code     = $brow['code']      ?? 'PAYMENT';
            $Modifier = $brow['modifier']  ?? '';

            $remaining = $payment;
            // Distribute across billing lines (same logic as invoice_balance)
            $billing_rows = sqlStatement(
                "SELECT * FROM billing LEFT JOIN code_types ON billing.code_type=code_types.ct_key
                 WHERE code_types.ct_fee=1 AND billing.activity!=0 AND billing.pid=? AND billing.encounter=?
                 ORDER BY billing.code,billing.modifier",
                [$form_pid, $enc]
            );
            $has_billing = false;
            while ($br = sqlFetchArray($billing_rows)) {
                $has_billing = true;
                if ($remaining <= 0) break;
                $already = sqlQuery(
                    "SELECT COALESCE(SUM(pay_amount),0) AS p, COALESCE(SUM(adj_amount),0) AS a
                     FROM ar_activity WHERE pid=? AND encounter=? AND code_type=? AND code=? AND modifier=?
                     AND deleted IS NULL AND !(payer_type=0 AND account_code='PCP')",
                    [$form_pid, $enc, $br['code_type'], $br['code'], $br['modifier'] ?? '']
                );
                $line_rem = $br['fee'] - $already['p'] - $already['a'];
                if (round($line_rem, 2) <= 0) continue;
                $apply = min($remaining, $line_rem);
                $seq = sqlQuery(
                    "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
                    [$form_pid, $enc]
                );
                sqlBeginTrans();
                sqlStatement(
                    "INSERT INTO ar_activity SET pid=?,encounter=?,sequence_no=?,code_type=?,code=?,modifier=?,
                     payer_type=0,post_time=NOW(),post_user=?,session_id=?,pay_amount=?,adj_amount=0,account_code='PP'",
                    [$form_pid, $enc, $seq['n'], $br['code_type'], $br['code'], $br['modifier'] ?? '',
                     $_SESSION['authUserID'], $ipos_session_id, $apply]
                );
                sqlCommitTrans();
                $remaining = round($remaining - $apply, 2);
            }
            // If no billing lines or leftover, post as a single line
            if (!$has_billing || $remaining > 0) {
                $seq = sqlQuery(
                    "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
                    [$form_pid, $enc]
                );
                sqlBeginTrans();
                sqlStatement(
                    "INSERT INTO ar_activity SET pid=?,encounter=?,sequence_no=?,code_type=?,code=?,modifier=?,
                     payer_type=0,post_time=NOW(),post_user=?,session_id=?,pay_amount=?,adj_amount=0,account_code='PP'",
                    [$form_pid, $enc, $seq['n'], $Codetype, $Code, $Modifier,
                     $_SESSION['authUserID'], $ipos_session_id, $remaining]
                );
                sqlCommitTrans();
            }

            frontPayment($form_pid, $enc, 'credit_card', $form_source, 0, $payment, $timestamp);
            $total_applied += $payment;
        }

        // Update ar_session pay_total to match what was actually applied
        sqlStatement(
            "UPDATE ar_session SET pay_total=?, reference=? WHERE session_id=?",
            [round($total_applied, 2), $form_source ?: $_POST['form_source'] ?? '', $ipos_session_id]
        );

        $save_msg = xl('iPOSpays credit card payment of $') . number_format($total_applied, 2)
                  . xl(' applied across ') . count(array_filter($_POST['form_upay'], 'floatval'))
                  . xl(' encounter(s).');
        goto ipos_save_done;
    } elseif ($ipos_session_id > 0) {
        // No form_upay — session was recorded by callback, nothing more to post
        $save_msg = xl('Credit card payment recorded via iPOSpays.');
        goto ipos_save_done;
    }

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
                            "SELECT sum(adj_amount) as MoneyAdjusted FROM ar_activity where " .
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

    ipos_save_done: // iPOSpays jumps here when callback already recorded the payment
    unset($timestamp); // suppress unused-variable notice
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
                "SELECT COALESCE(SUM(pay_amount),0) AS p, COALESCE(SUM(adj_amount),0) AS a
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

// ── DELETE payment handler ────────────────────────────────────────────────────
if (!empty($_POST['delete_payment'])) {
    $del_session = intval($_POST['delete_session_id'] ?? 0);
    if ($del_session > 0) {
        // Soft-delete all ar_activity rows linked to this session
        sqlStatement(
            "UPDATE ar_activity SET deleted = NOW(), modified_time = NOW()
             WHERE session_id = ? AND deleted IS NULL",
            [$del_session]
        );
        // Remove the ar_session row
        sqlStatement("DELETE FROM ar_session WHERE session_id = ?", [$del_session]);
        $save_msg = xl('Payment deleted successfully.');
    }
}
// ── End delete handler ────────────────────────────────────────────────────────

// ── Search Payments handler ───────────────────────────────────────────────────
$search_results = [];
$search_ran     = false;
if (!empty($_POST['search_payments'])) {
    $search_ran  = true;
    $s_from      = !empty($_POST['s_from'])   ? DateToYYYYMMDD(trim($_POST['s_from']))   : '';
    $s_to        = !empty($_POST['s_to'])     ? DateToYYYYMMDD(trim($_POST['s_to']))     : '';
    $s_method    = trim($_POST['s_method']    ?? '');
    $s_ref       = trim($_POST['s_ref']       ?? '');
    $s_amount    = trim($_POST['s_amount']    ?? '');
    $s_entity    = trim($_POST['s_entity']    ?? '');
    $s_status    = trim($_POST['s_status']    ?? '');

    $sq  = "SELECT s.*, ic.name AS ins_name, pd.fname, pd.lname
            FROM ar_session s
            LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
            LEFT JOIN patient_data pd ON pd.pid = s.patient_id
            WHERE 1=1";
    $sb  = [];

    if ($s_from)   { $sq .= " AND s.check_date >= ?";        $sb[] = $s_from; }
    if ($s_to)     { $sq .= " AND s.check_date <= ?";        $sb[] = $s_to; }
    if ($s_method) { $sq .= " AND s.payment_method = ?";     $sb[] = $s_method; }
    if ($s_ref)    { $sq .= " AND s.reference LIKE ?";       $sb[] = '%' . $s_ref . '%'; }
    if ($s_amount) { $sq .= " AND s.pay_total = ?";          $sb[] = $s_amount; }
    if ($s_entity === 'insurance') { $sq .= " AND s.payer_id != '0'"; }
    if ($s_entity === 'patient')   { $sq .= " AND s.payer_id = '0'"; }

    if ($s_status) {
        // build fully-paid session id list
        $paid_ids = '';
        $rs = sqlStatement(
            "SELECT s2.session_id FROM ar_session s2
             LEFT JOIN ar_activity a ON a.session_id=s2.session_id AND a.deleted IS NULL
             GROUP BY s2.session_id
             HAVING s2.pay_total - s2.global_amount - COALESCE(SUM(a.pay_amount),0) <= 0"
        );
        while ($r = sqlFetchArray($rs)) $paid_ids .= ($paid_ids ? ',' : '') . intval($r['session_id']);
        if (!$paid_ids) $paid_ids = '0';
        if ($s_status === 'fully_paid')  $sq .= " AND s.session_id IN ($paid_ids)";
        if ($s_status === 'unapplied')   $sq .= " AND s.session_id NOT IN ($paid_ids)";
    }

    $sq .= " ORDER BY s.check_date DESC LIMIT 200";
    $sr = sqlStatement($sq, $sb);
    while ($row = sqlFetchArray($sr)) {
        // compute undistributed
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
$from_date = ($_GET['from'] ?? '2010-01-01') . ' 00:00:00';
$to_date   = ($_GET['to']   ?? date('Y-m-d')) . ' 23:59:59';

$total_chg = $total_pmt = $total_adj = $total_ins_bal = $total_pat_bal = $total_bal = 0;
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

$ledger_rows = [];
while ($enc = sqlFetchArray($enc_res)) {
    // Charges
    $chrgs = sqlStatement(
        "SELECT b.code, b.code_text, b.modifier, b.fee, b.units, b.bill_date,
                ic.name AS ins_name, b.payer_id
         FROM billing b
         LEFT JOIN insurance_companies ic ON ic.id=b.payer_id
         LEFT JOIN code_types ct ON ct.ct_key = b.code_type
         WHERE b.pid=? AND b.encounter=? AND b.activity>0
           AND (ct.ct_proc = '1' OR b.code_type NOT IN ('ICD9','ICD9CM','ICD10','COPAY'))
         ORDER BY b.id",
        [$pid, $enc['encounter']]
    );
    $enc_chg = 0;
    $charge_rows = [];
    while ($c = sqlFetchArray($chrgs)) {
        $enc_chg += $c['fee'];
        $total_chg += $c['fee'];
        $charge_rows[] = $c;
    }

    // Payments/adjustments
    $pmts = sqlStatement(
        "SELECT aa.*, aa.payer_type,
                ars.payment_method, ars.reference, ars.pay_total, ars.payer_id,
                ic.name AS ins_name
         FROM ar_activity aa
         JOIN ar_session ars ON ars.session_id=aa.session_id
         LEFT JOIN insurance_companies ic ON ic.id=ars.payer_id
         WHERE aa.pid=? AND aa.encounter=? AND aa.deleted IS NULL
         ORDER BY aa.sequence_no",
        [$pid, $enc['encounter']]
    );
    $enc_pmt = 0; $enc_adj = 0;
    $enc_ins_pmt = 0; $enc_ins_adj = 0;
    $enc_pat_pmt = 0; $enc_pat_adj = 0;
    $pmt_rows = [];
    while ($p = sqlFetchArray($pmts)) {
        $enc_pmt += $p['pay_amount'];
        $enc_adj += $p['adj_amount'];
        $total_pmt += $p['pay_amount'];
        $total_adj += $p['adj_amount'];
        $is_ins = (isset($p['payer_type']) ? (int)$p['payer_type'] !== 0 : !empty($p['payer_id']));
        if ($is_ins) {
            $enc_ins_pmt += $p['pay_amount'];
            $enc_ins_adj += $p['adj_amount'];
            $total_ins_pmt += $p['pay_amount'];
        } else {
            $enc_pat_pmt += $p['pay_amount'];
            $enc_pat_adj += $p['adj_amount'];
            $total_pat_pmt += $p['pay_amount'];
        }
        $pmt_rows[] = $p;
    }

    // ── Mirror pat_ledger.php balance logic exactly ──────────────────────────
    //
    // pat_ledger step 1 (lines ~2922-2929): charges start in ins_bal or pat_bal.
    // pat_ledger checks $patient_has_insurance (global) OR $erow['payer_id'] (billing row).
    // We check $_has_ins OR whether any charge row has a payer_id (billing-level insurance).
    $enc_has_ins_billing = false;
    foreach ($charge_rows as $cr) {
        if (!empty($cr['payer_id'])) { $enc_has_ins_billing = true; break; }
    }
    if ($_has_ins || $enc_has_ins_billing) {
        $enc_ins_bal = $enc_chg;   // all charges go to insurance first
        $enc_pat_bal = 0;
    } else {
        $enc_ins_bal = 0;          // self-pay: charges go straight to patient
        $enc_pat_bal = $enc_chg;
    }
    $enc_bal = $enc_chg;

    // pat_ledger step 2 (PrintCreditDetail): walk each payment row exactly as
    // pat_ledger does — ERA rows detect PR memos and shift balance ins→pat;
    // plain insurance rows subtract (pmt+adj) from ins_bal;
    // plain patient rows subtract pmt from pat_bal and adj from ins_bal.
    foreach ($pmt_rows as $p) {
        $rpmt     = (float)($p['pay_amount'] ?? 0);
        $radj     = (float)($p['adj_amount'] ?? 0);
        $memo_raw = strtolower($p['memo'] ?? '');
        $is_ins   = (isset($p['payer_type']) ? (int)$p['payer_type'] !== 0 : !empty($p['payer_id']));

        // Detect PR (patient responsibility) in ERA memo — shifts balance ins→pat
        // (mirrors pat_ledger lines ~460-477)
        $is_pr = $is_ins && (
            strpos($memo_raw, 'copay')       !== false ||
            strpos($memo_raw, 'deductible')  !== false ||
            strpos($memo_raw, 'coinsurance') !== false ||
            strpos($memo_raw, 'ins1')        !== false ||
            preg_match('/pr/i', $memo_raw)
        );

        if ($is_pr) {
            // Extract amount from memo or fall back to abs values
            if (preg_match('/(\d+\.\d{2})/', $memo_raw, $prm)) {
                $pr_total = (float)$prm[1];
            } else {
                $pr_total = abs($rpmt) + abs($radj);
            }
            $enc_ins_bal -= $pr_total;   // remove from insurance
            $enc_pat_bal += $pr_total;   // add to patient
        } elseif ($is_ins) {
            // Insurance payment + adjustment both reduce ins_bal
            // (mirrors pat_ledger lines ~481-483)
            $enc_ins_bal -= ($rpmt + $radj);
        } else {
            // Patient payment: pmt reduces pat_bal; adj reduces ins_bal
            // (mirrors pat_ledger lines ~671-677)
            $enc_pat_bal -= $rpmt;
            $enc_ins_bal -= $radj;
        }

        // enc_bal tracks total regardless of ins/pat split
        // (mirrors pat_ledger lines ~499-500 and ~746)
        $enc_bal -= ($rpmt + $radj);
    }

    $total_ins_bal += $enc_ins_bal;
    $total_pat_bal += $enc_pat_bal;
    $total_bal     += $enc_bal;

    $ledger_rows[] = [
        'enc'         => $enc,
        'charges'     => $charge_rows,
        'pmts'        => $pmt_rows,
        'enc_chg'     => $enc_chg,
        'enc_pmt'     => $enc_pmt,
        'enc_adj'     => $enc_adj,
        'enc_bal'     => $enc_bal,
        'enc_ins_bal' => $enc_ins_bal,
        'enc_pat_bal' => $enc_pat_bal,
    ];
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
$encs = [];
$enc_q = sqlStatement(
    "SELECT fe.encounter, LEFT(fe.date,10) AS encdate, fe.last_level_closed,
            COALESCE(SUM(b.fee),0) AS charges
     FROM form_encounter fe
     LEFT JOIN billing b ON b.pid=fe.pid AND b.encounter=fe.encounter
                         AND b.activity>0 AND b.code_type!='TAX' AND b.fee!=0
     WHERE fe.pid=?
     GROUP BY fe.encounter, fe.date, fe.last_level_closed
     ORDER BY fe.encounter DESC LIMIT 30",
    [$pid]
);
while ($er = sqlFetchArray($enc_q)) {
    // insurance & patient paid
    $dpmt = sqlQuery(
        "SELECT COALESCE(SUM(pay_amount),0) AS p, COALESCE(SUM(adj_amount),0) AS a
         FROM ar_activity WHERE deleted IS NULL AND pid=? AND encounter=?
         AND (payer_type!=0 OR payer_type IS NULL) AND account_code!='PCP'",
        [$pid, $er['encounter']]
    );
    $dpat = sqlQuery(
        "SELECT COALESCE(SUM(pay_amount),0) AS p FROM ar_activity
         WHERE deleted IS NULL AND pid=? AND encounter=? AND payer_type=0 AND account_code!='PCP'",
        [$pid, $er['encounter']]
    );
    $ins_cnt = sqlQuery(
        "SELECT COUNT(DISTINCT type) AS n FROM insurance_data WHERE pid=? AND provider>0",
        [$pid]
    );
    $duept = 0;
    if ($ins_cnt['n'] == 0 || $er['last_level_closed'] >= $ins_cnt['n']) {
        $tot = sqlQuery(
            "SELECT COALESCE(SUM(fee),0) AS t FROM billing WHERE pid=? AND encounter=? AND activity=1",
            [$pid, $er['encounter']]
        );
        $totd = sqlQuery(
            "SELECT COALESCE(SUM(pay_amount),0) AS p, COALESCE(SUM(adj_amount),0) AS a
             FROM ar_activity WHERE deleted IS NULL AND pid=? AND encounter=?",
            [$pid, $er['encounter']]
        );
        $duept = $tot['t'] - $totd['p'] - $totd['a'];
    }
    $er['ins_paid']   = $dpmt['p'] + $dpmt['a'];
    $er['pat_paid']   = $dpat['p'];
    $er['ins_bal']    = $er['charges'] - $er['ins_paid'];
    $er['duept']      = round($duept, 2);
    $encs[] = $er;
}

$csrf = CsrfUtils::collectCsrfToken();

// ── Unapplied credits for this patient ───────────────────────────────────────
$unapplied_credits = [];
$total_unapplied   = 0;
if ($pid) {
    $ucq = sqlStatement(
        "SELECT s.session_id, s.reference, s.check_date, s.pay_total,
                s.payment_method, s.adjustment_code,
                COALESCE(SUM(a.pay_amount),0) AS applied,
                (s.pay_total - COALESCE(SUM(a.pay_amount),0) - s.global_amount) AS available
         FROM ar_session s
         LEFT JOIN ar_activity a ON a.session_id=s.session_id AND a.deleted IS NULL
         WHERE s.patient_id=? AND s.payer_id=0
           AND (s.adjustment_code='pre_payment' OR s.payment_type='patient')
         GROUP BY s.session_id, s.reference, s.check_date, s.pay_total,
                  s.payment_method, s.adjustment_code, s.global_amount
         HAVING (s.pay_total - COALESCE(SUM(a.pay_amount),0) - s.global_amount) > 0.004
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
// Mirror pat_ledger prepayment adjustment to total_bal (pat_ledger lines ~3150-3151)
if ($total_unapplied > 0) {
    $total_bal -= $total_unapplied;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo xlt('Payments & Ledger'); ?></title>
<?php Header::setupHeader(['datetime-picker']); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
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
.nav-btns{display:flex;gap:8px;padding:10px 16px;background:var(--surface);border-bottom:1px solid var(--border);}
.nav-btn{flex:1;padding:9px 0;border-radius:6px;border:1.5px solid var(--purple);background:transparent;color:var(--purple);font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px;}
.nav-btn:hover{background:var(--purple-l);}
.nav-btn.active{background:var(--purple);color:#fff;}
/* panels */
.panel{display:none;flex:1;overflow-y:auto;padding:16px;}
.panel.active{display:block;}
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
.src-toggle{display:flex;border:1.5px solid var(--border2);border-radius:var(--r);overflow:hidden;background:var(--surface2);margin-bottom:14px;}
.src-btn{flex:1;padding:9px 16px;font-size:12px;font-weight:700;cursor:pointer;border:none;background:transparent;color:var(--text2);transition:all .15s;display:flex;align-items:center;justify-content:center;gap:6px;}
.src-btn.active{background:var(--purple);color:#fff;}
.src-btn:not(.active):hover{background:var(--purple-l);color:var(--purple);}
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

  <!-- Nav buttons -->
  <div class="nav-btns">
    <button type="button" class="nav-btn active" id="btn-payment" onclick="switchTab('payment')">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
      <?php echo xlt('Record Payment'); ?>
    </button>
    <button type="button" class="nav-btn" id="btn-ledger" onclick="switchTab('ledger')">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      <?php echo xlt('Transaction Ledger'); ?>
    </button>
    <button type="button" class="nav-btn" id="btn-search" onclick="switchTab('search')">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <?php echo xlt('Search Payments'); ?>
    </button>
  </div>

  <!-- ════════ PAYMENT PANEL ════════ -->
  <div class="panel active" id="panel-payment">

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

    <!-- Source selector -->
    <div class="src-toggle">
      <button type="button" class="src-btn active" id="src-ins" onclick="setSource('insurance')">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <?php echo xlt('Insurance Payment'); ?>
      </button>
      <button type="button" class="src-btn" id="src-pat" onclick="setSource('patient')">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        <?php echo xlt('Patient Payment'); ?>
      </button>
    </div>

    <!-- Payment details -->
    <div class="card">
      <div class="card-header">
        <span class="card-title"><?php echo xlt('Payment Details'); ?></span>
        <span class="badge bg-purple" id="ins-badge"><?php echo text($ins_name); ?></span>
      </div>
      <div class="card-body">
        <div class="form-grid-3" style="margin-bottom:14px;">
          <div class="form-group">
            <label class="form-label"><?php echo xlt('Payment Method'); ?></label>
            <select class="form-control" name="form_method" id="form_method" onchange="toggleCCButton(this.value)">
              <?php foreach ($pay_methods as $pm): ?>
              <option value="<?php echo attr($pm['option_id']); ?>"><?php echo text($pm['title']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label"><?php echo xlt('Check / Reference No.'); ?></label>
            <input type="text" class="form-control" name="form_source" id="form_source" placeholder="<?php echo xla('Optional'); ?>">
            <!-- iPOSpays: populated by postMessage after payment; tells POST handler the
                 callback already wrote ar_session so it must not double-post -->
            <input type="hidden" name="ipos_session_id" id="ipos_session_id" value="">
          </div>
          <div class="form-group">
            <label class="form-label"><?php echo xlt('Amount'); ?></label>
            <input type="text" class="amount-big form-control" id="main-amount" name="_display_amount" value="$0.00" oninput="syncAmount(this)" readonly>
          </div>
        </div>

        <!-- Coverage + payment type (Insurance) -->
        <div id="ins-options">
          <div class="form-group" style="margin-bottom:10px;">
            <label class="form-label"><?php echo xlt('Payment Type'); ?></label>
            <div class="radio-row">
              <label class="radio-label">
                <input type="radio" name="radio_type_of_coverage" value="insurance" checked onchange="updateCoverage(this)">
                <?php echo xlt('Insurance'); ?>
              </label>
              <label class="radio-label">
                <input type="radio" name="radio_type_of_coverage" value="self" onchange="updateCoverage(this)">
                <?php echo xlt('Self-Pay'); ?>
              </label>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label"><?php echo xlt('Post As'); ?></label>
            <div class="radio-row">
              <label class="radio-label"><input type="radio" name="radio_type_of_payment" value="copay" checked><?php echo xlt('Co-Pay'); ?></label>
              <label class="radio-label"><input type="radio" name="radio_type_of_payment" value="invoice_balance"><?php echo xlt('Invoice Balance'); ?></label>
            </div>
          </div>
        </div>

        <!-- Pre-pay (patient only) -->
        <div id="pat-options" style="display:none;">
          <div class="form-group" style="margin-bottom:10px;">
            <label class="form-label"><?php echo xlt('Post As'); ?></label>
            <div class="radio-row">
              <label class="radio-label"><input type="radio" name="radio_type_of_payment" value="invoice_balance" onchange="togglePrepay(this)"><?php echo xlt('Invoice Balance'); ?></label>
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
    </div>

    <!-- ── Unapplied Credits card ── -->
    <?php if (!empty($unapplied_credits)): ?>
    <div class="card" id="unapplied-credits-card" style="border:2px solid var(--amber);border-radius:var(--r);">
      <div class="card-header" style="background:var(--amber-l);border-bottom:1px solid var(--amber);">
        <span class="card-title" style="color:var(--amber);">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?php echo xlt('Unapplied Credits / Pre-Payments'); ?>
          <span style="font-weight:400;color:var(--amber);margin-left:6px;font-size:11px;">(<?php echo count($unapplied_credits); ?> <?php echo xlt('credit(s)'); ?> &mdash; <?php echo text(mdi_money($total_unapplied)); ?> <?php echo xlt('available'); ?>)</span>
        </span>
        <button type="button" class="btn btn-sm" style="background:var(--amber);color:#fff;border:none;"
          onclick="autoApplyCredits()"
          title="<?php echo xla('Distribute available credits across open encounter balances, oldest first'); ?>">
          <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          <?php echo xlt('Auto Apply Credits'); ?>
        </button>
      </div>
      <div style="overflow-x:auto;">
        <table class="dt">
          <thead>
            <tr>
              <th><?php echo xlt('Date'); ?></th>
              <th><?php echo xlt('Reference'); ?></th>
              <th><?php echo xlt('Method'); ?></th>
              <th><?php echo xlt('Type'); ?></th>
              <th class="r"><?php echo xlt('Original'); ?></th>
              <th class="r"><?php echo xlt('Applied'); ?></th>
              <th class="r" style="color:var(--amber);"><?php echo xlt('Available'); ?></th>
              <th><?php echo xlt('Action'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($unapplied_credits as $uc):
            $method_lbl = sqlQuery("SELECT title FROM list_options WHERE list_id='payment_method' AND option_id=? AND activity=1 LIMIT 1", [$uc['payment_method']]);
            $type_lbl = ($uc['adjustment_code'] === 'pre_payment') ? xl('Pre-Payment') : xl('Patient Payment');
          ?>
          <tr id="ucrow_<?php echo attr($uc['session_id']); ?>">
            <td class="mono"><?php echo text(substr($uc['check_date'],0,10)); ?></td>
            <td class="mono"><?php echo text($uc['reference'] ?: '—'); ?></td>
            <td><?php echo text($method_lbl['title'] ?? $uc['payment_method']); ?></td>
            <td><span class="badge bg-amber"><?php echo text($type_lbl); ?></span></td>
            <td class="r"><?php echo text(mdi_money($uc['pay_total'])); ?></td>
            <td class="r" style="color:var(--green);"><?php echo text(mdi_money($uc['applied'])); ?></td>
            <td class="r" style="color:var(--amber);font-weight:700;"><?php echo text(mdi_money($uc['available'])); ?></td>
            <td>
              <button type="button" class="btn btn-outline btn-sm"
                style="border-color:var(--amber);color:var(--amber);"
                onclick="applyOneCredit(<?php echo attr_js($uc['session_id']); ?>, <?php echo attr_js($uc['available']); ?>)">
                <?php echo xlt('Apply'); ?>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="6" style="text-align:right;font-weight:700;padding-right:10px;"><?php echo xlt('Total Available'); ?></td>
              <td class="r" style="color:var(--amber);font-weight:700;"><?php echo text(mdi_money($total_unapplied)); ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>

    </div>
    <?php endif; ?>

    <!-- Encounter table -->
    <div class="card" id="enc-table-card">
      <div class="card-header">
        <span class="card-title"><?php echo xlt('Apply to Encounters'); ?></span>
        <button type="button" class="btn btn-sm btn-outline" onclick="autoApply()"><?php echo xlt('Auto Apply'); ?></button>
      </div>
      <div style="overflow-x:auto;">
        <table class="dt">
          <thead>
            <tr>
              <th><?php echo xlt('DOS'); ?></th>
              <th><?php echo xlt('Encounter'); ?></th>
              <th class="r"><?php echo xlt('Charges'); ?></th>
              <th class="r"><?php echo xlt('Ins Paid'); ?></th>
              <th class="r"><?php echo xlt('Pt Paid'); ?></th>
              <th class="r"><?php echo xlt('Ins Bal'); ?></th>
              <th class="r"><?php echo xlt('Pt Due'); ?></th>
              <th class="r pay-col"><?php echo xlt('Paying'); ?></th>
            </tr>
          </thead>
          <tbody>
            <?php
            $var_idx = 0;
            foreach ($encs as $er):
                $var_idx++;
                $ins_bal = max(0, $er['charges'] - $er['ins_paid']);
            ?>
            <tr id="erow_<?php echo $var_idx; ?>">
              <td class="mono"><?php echo text($er['encdate']); ?></td>
              <td class="mono"><?php echo text($er['encounter']); ?></td>
              <td class="r"><?php echo text(number_format($er['charges'],2)); ?></td>
              <td class="r"><?php echo text(number_format($er['ins_paid'],2)); ?></td>
              <td class="r"><?php echo text(number_format($er['pat_paid'],2)); ?></td>
              <td class="r" style="<?php echo $ins_bal>0 ? 'color:var(--red);font-weight:700;' : ''; ?>">
                <?php echo text(number_format($ins_bal,2)); ?>
              </td>
              <td class="r" id="duept_<?php echo $var_idx; ?>" style="<?php echo $er['duept']>0 ? 'color:var(--amber);font-weight:700;' : ''; ?>">
                <?php echo text(number_format($er['duept'],2)); ?>
              </td>
              <td>
                <input class="pay-input" type="text" id="paying_<?php echo $var_idx; ?>"
                  name="form_upay[<?php echo attr($er['encounter']); ?>]"
                  value="" placeholder="0.00"
                  data-ins-bal="<?php echo attr($ins_bal); ?>"
                  data-pt-due="<?php echo attr($er['duept']); ?>"
                  oninput="calcTotal()">
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="7" style="text-align:right;padding-right:14px;"><?php echo xlt('Total Payment'); ?></td>
              <td style="text-align:right;font-family:'DM Mono',monospace;">
                <input type="text" name="form_paytotal" id="form_paytotal" value="0.00"
                  style="width:84px;font-family:'DM Mono',monospace;font-size:13px;font-weight:700;color:var(--purple);text-align:right;border:none;background:transparent;" readonly>
              </td>
            </tr>
          </tfoot>
        </table>
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
          <?php if ($GLOBALS['payment_gateway'] == 'iPOSpays'): ?>
          <!-- iPOSpays uses its own modal; openCCModal() is overridden below -->
          <?php endif; ?>
          <?php if (!empty($GLOBALS['cc_stripe_terminal'])): ?>
          <button type="button" id="pos-btn-wrap" class="btn btn-success" onclick="posDialog()" style="display:none;">
            <?php echo xlt('POS Payment'); ?>
          </button>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
      <button type="submit" id="post-payment-btn" class="btn btn-success">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo xlt('Post Payment'); ?>
      </button>
    </div>
    </form>

  <!-- Hidden auto-apply form — submitted by JS (must be OUTSIDE payment-form) -->
  <form id="auto-apply-form" method="post" action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>" style="display:none;">
    <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
    <input type="hidden" name="form_pid" value="<?php echo attr($pid); ?>">
    <input type="hidden" name="form_auto_apply_credits" value="1">
    <input type="hidden" name="aa_session_id" id="aa_session_id" value="">
    <input type="hidden" name="aa_credit_avail" id="aa_credit_avail" value="">
  </form>

  </div><!-- /panel-payment -->

  <!-- ════════ SEARCH PANEL ════════ -->
  <div class="panel" id="panel-search">

    <form method="post" id="search-form" action="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=search" style="margin-bottom:14px;">
      <input type="hidden" name="csrf_token_form" value="<?php echo attr($csrf); ?>">
      <input type="hidden" name="search_payments" value="1">

      <div class="card">
        <div class="card-header">
          <span class="card-title">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?php echo xlt('Search Criteria'); ?>
          </span>
        </div>
        <div class="card-body">
          <div class="form-grid" style="margin-bottom:12px;">
            <div class="form-group">
              <label class="form-label"><?php echo xlt('From Date'); ?></label>
              <input type="date" class="form-control" name="s_from"
                value="<?php echo attr($_POST['s_from'] ?? ''); ?>">
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('To Date'); ?></label>
              <input type="date" class="form-control" name="s_to"
                value="<?php echo attr($_POST['s_to'] ?? date('Y-m-d')); ?>">
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Payment Method'); ?></label>
              <select class="form-control" name="s_method">
                <option value=""><?php echo xlt('-- All --'); ?></option>
                <?php
                $pmr = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id='payment_method' AND activity=1 ORDER BY seq");
                while ($pmrow = sqlFetchArray($pmr)):
                ?>
                <option value="<?php echo attr($pmrow['option_id']); ?>"
                  <?php echo (($_POST['s_method'] ?? '') === $pmrow['option_id']) ? 'selected' : ''; ?>>
                  <?php echo text($pmrow['title']); ?>
                </option>
                <?php endwhile; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Check / Reference No.'); ?></label>
              <input type="text" class="form-control" name="s_ref"
                value="<?php echo attr($_POST['s_ref'] ?? ''); ?>"
                placeholder="<?php echo xla('Partial match OK'); ?>">
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Amount'); ?></label>
              <input type="text" class="form-control" name="s_amount"
                value="<?php echo attr($_POST['s_amount'] ?? ''); ?>"
                placeholder="<?php echo xla('Exact amount'); ?>">
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Paying Entity'); ?></label>
              <select class="form-control" name="s_entity">
                <option value=""><?php echo xlt('-- All --'); ?></option>
                <option value="insurance" <?php echo (($_POST['s_entity'] ?? '') === 'insurance') ? 'selected' : ''; ?>><?php echo xlt('Insurance'); ?></option>
                <option value="patient"   <?php echo (($_POST['s_entity'] ?? '') === 'patient')   ? 'selected' : ''; ?>><?php echo xlt('Patient'); ?></option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label"><?php echo xlt('Status'); ?></label>
              <select class="form-control" name="s_status">
                <option value=""><?php echo xlt('-- All --'); ?></option>
                <option value="fully_paid" <?php echo (($_POST['s_status'] ?? '') === 'fully_paid') ? 'selected' : ''; ?>><?php echo xlt('Fully Applied'); ?></option>
                <option value="unapplied"  <?php echo (($_POST['s_status'] ?? '') === 'unapplied')  ? 'selected' : ''; ?>><?php echo xlt('Has Unapplied'); ?></option>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary">
              <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              <?php echo xlt('Search'); ?>
            </button>
            <a href="mdi_payments_ledger.php?pid=<?php echo attr($pid); ?>&tab=search" class="btn btn-outline"><?php echo xlt('Clear'); ?></a>
          </div>
        </div>
      </div>
    </form>

    <?php if ($search_ran): ?>
    <div class="card">
      <div class="card-header">
        <span class="card-title"><?php echo xlt('Results'); ?> &nbsp;<span style="font-weight:400;color:var(--text3);">(<?php echo count($search_results); ?> <?php echo xlt('records'); ?>)</span></span>
      </div>
      <?php if (empty($search_results)): ?>
        <div style="padding:24px;text-align:center;color:var(--text3);"><?php echo xlt('No payments found matching your criteria.'); ?></div>
      <?php else: ?>
      <div style="overflow-x:auto;">
        <table class="dt">
          <thead>
            <tr>
              <th><?php echo xlt('ID'); ?></th>
              <th><?php echo xlt('Date'); ?></th>
              <th><?php echo xlt('Payer'); ?></th>
              <th><?php echo xlt('Method'); ?></th>
              <th><?php echo xlt('Reference'); ?></th>
              <th class="r"><?php echo xlt('Total'); ?></th>
              <th><?php echo xlt('Status'); ?></th>
              <th class="r"><?php echo xlt('Undistributed'); ?></th>
              <th><?php echo xlt('Action'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($search_results as $sr): ?>
          <?php
            $payer = '';
            if (!empty($sr['ins_name'])) {
                $payer = $sr['ins_name'];
            } elseif (!empty($sr['fname'])) {
                $payer = trim($sr['lname'] . ', ' . $sr['fname']);
            }
            $applied    = $sr['pay_total'] - $sr['undistributed'];
            $fully_paid = round($sr['undistributed'], 2) <= 0;
            $method_row = sqlQuery("SELECT title FROM list_options WHERE list_id='payment_method' AND option_id=? AND activity=1 LIMIT 1", [$sr['payment_method']]);
          ?>
          <tr id="sr-row-<?php echo attr($sr['session_id']); ?>">
            <td class="mono" style="color:var(--accent,#3b5bdb);">
              <span style="color:var(--purple);font-weight:700;"><?php echo text($sr['session_id']); ?></span>
            </td>
            <td class="mono"><?php echo text(substr($sr['check_date'], 0, 10)); ?></td>
            <td><?php echo text($payer ?: '—'); ?></td>
            <td><?php echo text($method_row['title'] ?? $sr['payment_method']); ?></td>
            <td class="mono"><?php echo text($sr['reference'] ?: '—'); ?></td>
            <td class="r" style="font-weight:700;"><?php echo text(mdi_money($sr['pay_total'])); ?></td>
            <td>
              <?php if ($fully_paid): ?>
                <span class="badge bg-green"><?php echo xlt('Applied'); ?></span>
              <?php else: ?>
                <span class="badge bg-amber"><?php echo xlt('Unapplied'); ?></span>
              <?php endif; ?>
            </td>
            <td class="r" style="color:<?php echo $fully_paid ? 'var(--green)' : 'var(--amber)'; ?>;">
              <?php echo text(mdi_money($sr['undistributed'])); ?>
            </td>
            <td>
              <button type="button" class="btn btn-outline btn-sm"
                onclick="mdiToggleEditDrawer(<?php echo attr_js($sr['session_id']); ?>, this)">
                <svg id="edit-chev-<?php echo attr($sr['session_id']); ?>" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="transition:transform .2s;"><polyline points="6 9 12 15 18 9"/></svg>
                <?php echo xlt('Edit'); ?>
              </button>
              <button type="button" class="btn btn-sm"
                style="background:var(--red-l);color:var(--red);border:1px solid #fca5a5;margin-top:4px;display:block;"
                onclick="mdiDeletePayment(<?php echo attr_js($sr['session_id']); ?>)">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                <?php echo xlt('Delete'); ?>
              </button>
            </td>
          </tr>
          <tr id="sr-drawer-<?php echo attr($sr['session_id']); ?>" style="display:none;">
            <td colspan="9" style="padding:0;background:#f8f7ff;border-top:2px solid var(--purple);">
              <iframe id="sr-iframe-<?php echo attr($sr['session_id']); ?>"
                src=""
                style="width:100%;height:0;border:none;display:block;transition:height .25s ease;"
                onload="mdiIframeLoaded(this)">
              </iframe>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="5" style="text-align:right;font-weight:700;padding:10px 12px;"><?php echo xlt('Totals'); ?></td>
              <td class="r" style="font-weight:700;"><?php echo text(mdi_money(array_sum(array_column($search_results,'pay_total')))); ?></td>
              <td></td>
              <td class="r" style="font-weight:700;"><?php echo text(mdi_money(array_sum(array_column($search_results,'undistributed')))); ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

  </div><!-- /panel-search -->

  <!-- ════════ LEDGER PANEL ════════ -->
  <div class="panel" id="panel-ledger">

    <!-- Summary strip -->
    <div class="summary-strip" style="grid-template-columns:repeat(6,1fr);">
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Total Charges'); ?></div>
        <div class="sum-val"><?php echo text(mdi_money($total_chg)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Ins Paid'); ?></div>
        <div class="sum-val" style="color:var(--green);"><?php echo text(mdi_money($total_ins_pmt)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Pat Paid'); ?></div>
        <div class="sum-val" style="color:var(--green);"><?php echo text(mdi_money($total_pat_pmt)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Adjustments'); ?></div>
        <div class="sum-val" style="color:var(--amber);"><?php echo text(mdi_money($total_adj)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Ins Balance'); ?></div>
        <div class="sum-val" style="color:<?php echo $total_ins_bal > 0 ? 'var(--red)' : 'var(--green)'; ?>;font-weight:700;"><?php echo text(mdi_money($total_ins_bal)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Pat Balance'); ?></div>
        <div class="sum-val" style="color:<?php echo $total_pat_bal > 0 ? 'var(--amber)' : 'var(--green)'; ?>;font-weight:700;"><?php echo text(mdi_money($total_pat_bal)); ?></div>
      </div>
    </div>

    <!-- Date filter -->
    <div class="card" style="margin-bottom:12px;">
      <div class="card-body" style="padding:10px 16px;">
        <form method="get" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <input type="hidden" name="pid" value="<?php echo attr($pid); ?>">
          <input type="hidden" name="tab" value="ledger">
          <div class="form-group" style="flex-direction:row;align-items:center;gap:6px;">
            <label class="form-label" style="white-space:nowrap;"><?php echo xlt('From'); ?></label>
            <input type="date" class="form-control" name="from" value="<?php echo attr($_GET['from'] ?? '2010-01-01'); ?>" style="width:140px;">
          </div>
          <div class="form-group" style="flex-direction:row;align-items:center;gap:6px;">
            <label class="form-label"><?php echo xlt('To'); ?></label>
            <input type="date" class="form-control" name="to" value="<?php echo attr($_GET['to'] ?? date('Y-m-d')); ?>" style="width:140px;">
          </div>
          <button type="submit" class="btn btn-primary btn-sm"><?php echo xlt('Filter'); ?></button>
          <button type="button" class="btn btn-outline btn-sm" onclick="switchTab('payment')"><?php echo xlt('+ Add Payment'); ?></button>
        </form>
      </div>
    </div>

    <!-- Encounter blocks -->
    <?php if (empty($ledger_rows)): ?>
    <div style="text-align:center;padding:40px;color:var(--text3);"><?php echo xlt('No transactions found for this date range.'); ?></div>
    <?php else: ?>
    <?php foreach ($ledger_rows as $idx => $lr): ?>
    <?php $enc_info = $lr['enc']; $enc_id = $enc_info['encounter']; ?>
    <div class="enc-block">
      <div class="enc-header" onclick="toggleEnc(<?php echo $idx; ?>)">
        <div>
          <div class="enc-title"><?php echo xlt('Svc Date'); ?>: <?php echo text(substr($enc_info['date'],0,10)); ?> &nbsp;|&nbsp; <?php echo xlt('Enc'); ?> #<?php echo text($enc_id); ?></div>
          <div style="font-size:11px;color:var(--text2);margin-top:2px;"><?php echo text($enc_info['reason'] ?: xl('Visit')); ?></div>
        </div>
        <div class="enc-pills">
          <?php if ($lr['enc_chg']): ?>
          <span class="badge bg-gray"><?php echo xlt('Chg'); ?>: <?php echo text(mdi_money($lr['enc_chg'])); ?></span>
          <?php endif; ?>
          <?php if ($lr['enc_pmt']): ?>
          <span class="badge bg-green"><?php echo xlt('Pmt'); ?>: <?php echo text(mdi_money($lr['enc_pmt'])); ?></span>
          <?php endif; ?>
          <?php if ($lr['enc_adj']): ?>
          <span class="badge bg-amber"><?php echo xlt('Adj'); ?>: <?php echo text(mdi_money($lr['enc_adj'])); ?></span>
          <?php endif; ?>
          <?php
            $bal = $lr['enc_bal'];
            $bal_cls = $bal > 0 ? 'bg-red' : ($bal < 0 ? 'bg-green' : 'bg-gray');
          ?>
          <span class="badge <?php echo $bal_cls; ?>"><?php echo xlt('Bal'); ?>: <?php echo text(mdi_money($bal)); ?></span>
          <?php if ($lr['enc_ins_bal'] != 0): ?>
          <span class="badge" style="background:#dbeafe;color:#1e40af;"><?php echo xlt('Ins'); ?>: <?php echo text(mdi_money($lr['enc_ins_bal'])); ?></span>
          <?php endif; ?>
          <?php if ($lr['enc_pat_bal'] != 0): ?>
          <span class="badge" style="background:#fef3c7;color:#92400e;"><?php echo xlt('Pat'); ?>: <?php echo text(mdi_money($lr['enc_pat_bal'])); ?></span>
          <?php endif; ?>
          <span style="color:var(--purple);font-size:14px;" id="chev-<?php echo $idx; ?>">&#9654;</span>
        </div>
      </div>
      <div class="enc-body" id="encbody-<?php echo $idx; ?>">
        <?php if ($lr['charges']): ?>
        <table class="dt">
          <thead><tr>
            <th><?php echo xlt('CPT'); ?></th>
            <th><?php echo xlt('Description'); ?></th>
            <th><?php echo xlt('Carrier'); ?></th>
            <th class="r"><?php echo xlt('Units'); ?></th>
            <th class="r"><?php echo xlt('Charge'); ?></th>
            <th class="r" style="color:#1d4ed8;"><?php echo xlt('Ins Bal'); ?></th>
            <th class="r" style="color:#b45309;"><?php echo xlt('Pat Bal'); ?></th>
          </tr></thead>
          <tbody>
          <?php
          // Per-line balance: compute from payments
          $line_pmts_by_code = [];
          foreach ($lr['pmts'] as $p) {
              $k = $p['code_type'] . '|' . $p['code'] . '|' . ($p['modifier'] ?? '');
              if (!isset($line_pmts_by_code[$k])) $line_pmts_by_code[$k] = ['pmt'=>0,'adj'=>0,'is_ins'=>false];
              $is_ins = ($p['payer_type'] > 0 || !empty($p['payer_id']));
              $line_pmts_by_code[$k]['pmt'] += $p['pay_amount'];
              $line_pmts_by_code[$k]['adj'] += $p['adj_amount'];
              if ($is_ins) $line_pmts_by_code[$k]['is_ins'] = true;
          }
          foreach ($lr['charges'] as $c):
              $k = ($c['code_type'] ?? '') . '|' . $c['code'] . '|' . ($c['modifier'] ?? '');
              $lp = $line_pmts_by_code[$k] ?? ['pmt'=>0,'adj'=>0,'is_ins'=>false];
              $line_bal = $c['fee'] - $lp['pmt'] - $lp['adj'];
              $l_ins_bal = ($_has_ins || !empty($c['payer_id'])) ? $line_bal : 0;
              $l_pat_bal = ($_has_ins || !empty($c['payer_id'])) ? 0 : $line_bal;
          ?>
          <tr>
            <td class="mono" style="font-weight:600;color:var(--purple);"><?php echo text($c['code']); ?><?php if($c['modifier']) echo ':'.text($c['modifier']); ?></td>
            <td><?php echo text(substr($c['code_text'],0,55)); ?></td>
            <td><span style="font-size:11px;background:var(--surface2);border:1px solid var(--border);padding:2px 7px;border-radius:10px;"><?php echo text($c['ins_name'] ?: xl('Self')); ?></span></td>
            <td class="r"><?php echo text($c['units']); ?></td>
            <td class="r" style="font-weight:600;"><?php echo text(mdi_money($c['fee'])); ?></td>
            <td class="r" style="color:<?php echo $l_ins_bal > 0 ? '#1d4ed8' : 'var(--green)'; ?>;font-weight:<?php echo $l_ins_bal > 0 ? '700' : '400'; ?>;"><?php echo text(mdi_money($l_ins_bal)); ?></td>
            <td class="r" style="color:<?php echo $l_pat_bal > 0 ? '#b45309' : 'var(--text3)'; ?>;"><?php echo text(mdi_money($l_pat_bal)); ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

        <?php if ($lr['pmts']): ?>
        <div style="border-top:1px solid var(--border);padding:8px 10px 4px;font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;">
          <?php echo xlt('Payments & Adjustments'); ?>
        </div>
        <table class="dt">
          <thead><tr>
            <th><?php echo xlt('Date'); ?></th>
            <th><?php echo xlt('Carrier'); ?></th>
            <th><?php echo xlt('CPT'); ?></th>
            <th><?php echo xlt('Method'); ?></th>
            <th><?php echo xlt('Type'); ?></th>
            <th class="r"><?php echo xlt('Ins Bal'); ?></th>
            <th class="r"><?php echo xlt('Pat Bal'); ?></th>
            <th class="r"><?php echo xlt('Tot Bal'); ?></th>
            <th class="r"><?php echo xlt('Insurance'); ?></th>
            <th class="r"><?php echo xlt('Patient'); ?></th>
          </tr></thead>
          <tbody>
          <?php foreach ($lr['pmts'] as $p):
            $is_ins = ($p['payer_type'] > 0 || !empty($p['payer_id']));
            $type_label = $is_ins ? xlt('Insurance') : xlt('Patient');
            $type_style = $is_ins ? 'background:#dbeafe;color:#1e40af;' : 'background:#fef3c7;color:#92400e;';
            $ins_col  = $is_ins  ? mdi_money($p['pay_amount'] + $p['adj_amount']) : '—';
            $pat_col  = !$is_ins ? mdi_money($p['pay_amount']) : '—';
            $tot_bal  = mdi_money(abs($p['pay_amount'] + $p['adj_amount']));
          ?>
          <tr style="background:<?php echo $p['pay_amount']>0 ? 'var(--green-l)' : ($p['adj_amount']!=0 ? 'var(--amber-l)' : 'var(--surface2)'); ?>;">
            <td class="mono"><?php echo text(substr($p['post_time'],0,10)); ?></td>
            <td><span style="font-size:11px;background:var(--surface2);border:1px solid var(--border);padding:2px 7px;border-radius:10px;"><?php echo text($p['ins_name'] ?: xl('Patient')); ?></span></td>
            <td class="mono" style="color:var(--purple);font-size:11px;"><?php echo text($p['code'] ?? ''); ?></td>
            <td><?php echo text(mdi_list_title($p['payment_method'],'payment_method')); ?></td>
            <td><span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;<?php echo $type_style; ?>"><?php echo $type_label; ?></span></td>
            <td class="r" style="color:#1d4ed8;font-weight:700;"><?php echo $is_ins  ? text($ins_col) : '—'; ?></td>
            <td class="r" style="color:#b45309;font-weight:<?php echo !$is_ins?'700':'400'; ?>;"><?php echo !$is_ins ? text($pat_col) : '—'; ?></td>
            <td class="r" style="font-weight:700;color:var(--red);">$<?php echo text(number_format(abs($p['pay_amount'] + $p['adj_amount']),2)); ?></td>
            <td class="r" style="color:var(--green);font-weight:700;">
              <?php echo $p['pay_amount'] && $is_ins ? text(mdi_money($p['pay_amount'])) : '—'; ?>
            </td>
            <td class="r" style="color:var(--amber);">
              <?php echo $p['adj_amount'] ? text(mdi_money($p['adj_amount'])) : ($p['pay_amount'] && !$is_ins ? text(mdi_money($p['pay_amount'])) : '—'); ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

        <div class="enc-footer">
          <span><span class="lbl"><?php echo xlt('Charges'); ?></span><?php echo text(mdi_money($lr['enc_chg'])); ?></span>
          <span><span class="lbl"><?php echo xlt('Payments'); ?></span><span style="color:var(--green);"><?php echo text(mdi_money($lr['enc_pmt'])); ?></span></span>
          <span><span class="lbl"><?php echo xlt('Adjustments'); ?></span><span style="color:var(--amber);"><?php echo text(mdi_money($lr['enc_adj'])); ?></span></span>
          <span><span class="lbl"><?php echo xlt('Tot Bal'); ?></span>
            <span style="color:<?php echo $lr['enc_bal']>0?'var(--red)':($lr['enc_bal']<0?'var(--green)':'var(--text)'); ?>;font-weight:700;">
              <?php echo text(mdi_money($lr['enc_bal'])); ?>
            </span>
          </span>
          <span><span class="lbl" style="color:#1d4ed8;"><?php echo xlt('Ins Bal'); ?></span>
            <span style="color:<?php echo $lr['enc_ins_bal']>0?'#1d4ed8':'var(--green)'; ?>;font-weight:700;">
              <?php echo text(mdi_money($lr['enc_ins_bal'])); ?>
            </span>
          </span>
          <span><span class="lbl" style="color:#b45309;"><?php echo xlt('Pat Bal'); ?></span>
            <span style="color:<?php echo $lr['enc_pat_bal']>0?'#b45309':'var(--text3)'; ?>;font-weight:700;">
              <?php echo text(mdi_money($lr['enc_pat_bal'])); ?>
            </span>
          </span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Grand total bar -->
    <div class="total-bar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
      <span class="total-bar-lbl"><?php echo xlt('Grand Totals'); ?></span>
      <div style="display:flex;gap:24px;align-items:center;flex-wrap:wrap;">
        <span style="display:flex;flex-direction:column;align-items:flex-end;">
          <span style="font-size:10px;color:#a78bfa;font-weight:600;text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('Tot Balance'); ?></span>
          <span style="font-family:'DM Mono',monospace;font-size:15px;font-weight:700;color:#f9a8d4;"><?php echo text(mdi_money($total_bal)); ?></span>
        </span>
        <span style="display:flex;flex-direction:column;align-items:flex-end;">
          <span style="font-size:10px;color:#93c5fd;font-weight:600;text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('Ins Balance'); ?></span>
          <span style="font-family:'DM Mono',monospace;font-size:15px;font-weight:700;color:#93c5fd;"><?php echo text(mdi_money($total_ins_bal)); ?></span>
        </span>
        <span style="display:flex;flex-direction:column;align-items:flex-end;">
          <span style="font-size:10px;color:#fcd34d;font-weight:600;text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('Pat Balance'); ?></span>
          <span style="font-family:'DM Mono',monospace;font-size:16px;font-weight:700;color:#fcd34d;"><?php echo text(mdi_money($total_pat_bal)); ?></span>
        </span>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /panel-ledger -->

</div><!-- /page -->

<script>
// ── Tab switching ──
function switchTab(name) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    var ids = ['btn-payment','btn-ledger','btn-search'];
    ids.forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        var isActive = id === 'btn-' + name;
        el.classList.toggle('active', isActive);
    });
}

// ── Source toggle ──
function setSource(src) {
    document.getElementById('src-ins').classList.toggle('active', src==='insurance');
    document.getElementById('src-pat').classList.toggle('active', src==='patient');
    document.getElementById('ins-options').style.display = src==='insurance' ? '' : 'none';
    document.getElementById('pat-options').style.display = src==='patient'  ? '' : 'none';
    document.getElementById('ins-badge').textContent = src==='insurance'
        ? '<?php echo addslashes(text($ins_name)); ?>'
        : '<?php echo addslashes(xlt('Patient')); ?>';
    // Set hidden coverage value
    document.querySelectorAll('input[name="radio_type_of_coverage"]').forEach(function(r){
        r.checked = r.value === src || (src==='patient' && r.value==='self');
    });
    // Default payment type
    if (src==='insurance') {
        var copay = document.querySelector('input[value="copay"]');
        if (copay) copay.checked = true;
    }
}

// ── Pre-pay toggle ──
function togglePrepay(radio) {
    var show = radio.checked && radio.value==='pre_payment';
    document.getElementById('prepay-row').style.display = show ? '' : 'none';
    document.getElementById('enc-table-card').style.display = show ? 'none' : '';
    // Re-evaluate Paying column visibility for iPOSpays CC mode
    <?php if ($GLOBALS['payment_gateway'] === 'iPOSpays'): ?>
    var isCC = (document.getElementById('form_method') || {}).value === 'credit_card';
    iposRefreshPayingCols(isCC);
    <?php endif; ?>
}

// ── Coverage change ──
function updateCoverage(radio) {
    // mirror to the src toggle visuals
    if (radio.value === 'self') setSource('patient');
}

// ── Calc total ──
function calcTotal() {
    var total = 0;
    document.querySelectorAll('input[name^="form_upay["]').forEach(function(inp){
        total += parseFloat(inp.value)||0;
    });
    document.getElementById('form_paytotal').value = total.toFixed(2);
    document.getElementById('main-amount').value = '$'+total.toFixed(2);
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
    var total = parseFloat(document.getElementById('form_paytotal').value)||0;
    var prepay = parseFloat(document.getElementById('form_prepayment')?.value||0)||0;
    if (total===0 && prepay===0) {
        alert('<?php echo addslashes(xlj('A payment amount is required.')); ?>');
        return false;
    }
    top.restoreSession && top.restoreSession();
    return confirm('<?php echo addslashes(xlj('Post this payment?')); ?>');
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

function mdiIframeLoaded(iframe) {
    // Auto-resize to iframe content height after load
    try {
        var h = iframe.contentWindow.document.body.scrollHeight;
        if (h && h > 200) iframe.style.height = Math.min(h + 20, 800) + 'px';
    } catch(e) {
        // Cross-origin fallback — keep preset height
    }
}

// Called from within the edit_payment iframe after a successful save
// to refresh search results and close the drawer
function mdiEditSaved(sessionId) {
    var drawer = document.getElementById('sr-drawer-' + sessionId);
    var iframe = document.getElementById('sr-iframe-' + sessionId);
    var chev   = document.getElementById('edit-chev-' + sessionId);
    if (drawer)  drawer.style.display = 'none';
    if (iframe)  { iframe.style.height = '0'; iframe.src = ''; }
    if (chev)    chev.style.transform = 'rotate(0deg)';
    mdiOpenDrawer = null;
    // Re-submit search form to refresh results
    var sf = document.getElementById('search-form');
    if (sf) sf.submit();
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
        delete_payment:    '1',
        delete_session_id: sessionId,
        search_payments:   '1'
    };
    for (var k in fields) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = fields[k];
        form.appendChild(inp);
    }
    // Re-include current search filter values so results reload after delete
    var sf = document.getElementById('search-form');
    if (sf) {
        new FormData(sf).forEach(function(v, k) {
            if (k === 'csrf_token_form') return; // already added above
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

// ── Show/hide CC button when payment method changes ──────────────────────────
function toggleCCButton(val) {
    var isCC   = (val === 'credit_card');
    var ccBtn  = document.getElementById('cc-btn-wrap');
    var posBtn = document.getElementById('pos-btn-wrap');
    var postBtn = document.getElementById('post-payment-btn');
    if (ccBtn)  ccBtn.style.display  = isCC ? 'inline-flex' : 'none';
    if (posBtn) posBtn.style.display = isCC ? 'inline-flex' : 'none';

    <?php if ($GLOBALS['payment_gateway'] === 'iPOSpays'): ?>
    iposRefreshPayingCols(isCC);
    // Collect encounter ids onto button for openCCModal
    if (isCC) {
        var encIds = [];
        document.querySelectorAll('input[name^="form_upay["]').forEach(function(inp) {
            var m = inp.name.match(/\[(\d+)\]/);
            if (m) encIds.push(m[1]);
        });
        if (ccBtn) ccBtn.dataset.encs = encIds.join(',');
    }
    <?php endif; ?>
}

<?php if ($GLOBALS['payment_gateway'] === 'iPOSpays'): ?>
// Hide/show the Paying column depending on CC mode AND payment type.
// Pre-payment: keep everything visible — amount typed into form_prepayment.
// Co-pay / Invoice balance: hide Paying column (amount from Pt Due balances).
function iposRefreshPayingCols(isCC) {
    // Always keep the Paying column visible — staff need to enter or confirm
    // the amount before clicking Credit Card Pay.
    document.querySelectorAll('.pay-input').forEach(function(inp) {
        inp.closest('td').style.display = '';
    });
    document.querySelectorAll('th.pay-col').forEach(function(th) {
        th.style.display = '';
    });
    // Hide the manual Post button when CC mode is active (iPOSpays handles posting)
    var postBtn = document.getElementById('post-payment-btn');
    //if (postBtn) postBtn.style.display = isCC ? 'none' : '';
}

// Re-evaluate column visibility whenever payment type radio changes
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="radio_type_of_payment"]').forEach(function(r) {
        r.addEventListener('change', function() {
            var isCC = (document.getElementById('form_method') || {}).value === 'credit_card';
            // Always refresh regardless of CC mode so switching back from
            // pre_payment restores the column correctly
            iposRefreshPayingCols(isCC);
        });
    });
});
<?php endif; ?>

// ── Open CC modal (pure JS — no Bootstrap needed) ────────────────────────────
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

// ── Auto-open tab from URL or after search POST ──────────────────────────────
(function(){
    var p = new URLSearchParams(window.location.search);
    var t = p.get('tab');
    if (t === 'ledger' || t === 'search') switchTab(t);
    <?php if ($search_ran): ?>
    switchTab('search');
    <?php endif; ?>
})();

document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('form_method');
    if (sel) {
        // Show/hide CC button based on current selection
        var isCC = (sel.value === 'credit_card');
        var ccBtn  = document.getElementById('cc-btn-wrap');
        var posBtn = document.getElementById('pos-btn-wrap');
        var postBtn = document.getElementById('post-payment-btn');
        if (ccBtn)  ccBtn.style.display  = isCC ? 'inline-flex' : 'none';
        if (posBtn) posBtn.style.display = isCC ? 'inline-flex' : 'none';
        <?php if ($GLOBALS['payment_gateway'] === 'iPOSpays'): ?>
        // For iPOSpays: always show Paying column on load so balances are visible.
        // The column hides only when user changes method TO credit_card.
        //if (postBtn) postBtn.style.display = isCC ? 'none' : '';
        <?php endif; ?>
    }
});

top.restoreSession && top.restoreSession();

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

<?php if ($GLOBALS['payment_gateway'] == 'iPOSpays'): ?>
<!-- ══ iPOSpays Hosted Payment Page Modal ══════════════════════════════════ -->
<style>
#ipos-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:10000;align-items:center;justify-content:center;padding:12px;}
#ipos-modal .ipos-dialog{background:#fff;border-radius:10px;width:min(780px,98vw);height:min(92vh,820px);max-height:92vh;box-shadow:0 8px 40px rgba(0,0,0,.3);overflow:hidden;display:flex;flex-direction:column;}
#ipos-modal .ipos-head{background:#1e0a3c;color:#fff;padding:12px 18px;font-weight:700;font-size:15px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;}
#ipos-modal .ipos-head button{background:transparent;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;padding:0 4px;}
#ipos-modal .ipos-meta{padding:8px 18px 0;font-size:13px;color:#5a5f7a;flex-shrink:0;display:flex;justify-content:space-between;align-items:center;}
#ipos-modal .ipos-amt{font-family:'DM Mono',monospace;font-size:18px;font-weight:700;color:#5b21b6;}
#ipos-modal .ipos-frame-wrap{position:relative;flex:1;min-height:0;}
#ipos-modal iframe{width:100%;height:100%;border:none;display:block;}
#ipos-spinner{position:absolute;inset:0;background:#fff;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;font-size:13px;color:#5a5f7a;}
#ipos-spinner svg{animation:spin 1s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
#ipos-success-banner{display:none;background:#ecfdf5;border:1.5px solid #6ee7b7;color:#065f46;border-radius:8px;padding:14px 18px;margin:16px 20px;font-size:14px;font-weight:600;align-items:center;gap:10px;}
</style>

<div id="ipos-modal">
  <div class="ipos-dialog">
    <div class="ipos-head">
      <span>
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:6px;vertical-align:-2px;"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        <?php echo xlt('Credit Card Payment — iPOSpays'); ?>
      </span>
      <button onclick="closeIposModal()" title="<?php echo xla('Close'); ?>">&times;</button>
    </div>
    <div class="ipos-meta">
      <span><?php echo text(($patrow['fname'] ?? '') . ' ' . ($patrow['lname'] ?? '')); ?> &nbsp;·&nbsp; PID <?php echo attr($pid); ?></span>
    </div>
    <span id="ipos-display-amt" style="display:none;">0.00</span>
    <div id="ipos-success-banner">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      <span id="ipos-success-msg"><?php echo xlt('Payment approved and recorded.'); ?></span>
    </div>
    <div class="ipos-frame-wrap">
      <div id="ipos-spinner">
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#5b21b6" stroke-width="2.5"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
        <?php echo xlt('Loading payment form…'); ?>
      </div>
      <iframe id="ipos-frame" src="about:blank" title="<?php echo xla('iPOSpays Payment'); ?>"
              onload="iposFrameLoaded()"></iframe>
    </div>
  </div>
</div>
<?php endif; // iPOSpays modal ?>

<?php if ($GLOBALS['payment_gateway'] === 'iPOSpays'): ?>
<!-- ══ iPOSpays Encounter Application Modal ══════════════════════════════════ -->
<style>
#ipos-apply-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:10001;align-items:center;justify-content:center;padding:12px;}
#ipos-apply-modal .iam-dialog{background:#fff;border-radius:10px;width:min(680px,97vw);max-height:90vh;box-shadow:0 8px 40px rgba(0,0,0,.3);display:flex;flex-direction:column;overflow:hidden;}
#ipos-apply-modal .iam-head{background:#1e0a3c;color:#fff;padding:12px 18px;font-weight:700;font-size:15px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;}
#ipos-apply-modal .iam-head button{background:transparent;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;}
#ipos-apply-modal .iam-body{padding:18px 20px;overflow-y:auto;flex:1;}
#ipos-apply-modal .iam-foot{padding:12px 20px;border-top:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:8px;flex-shrink:0;}
#ipos-apply-modal .iam-total{font-family:'DM Mono',monospace;font-size:14px;font-weight:700;}
#ipos-apply-modal .iam-remaining{font-family:'DM Mono',monospace;font-size:13px;padding:4px 10px;border-radius:6px;font-weight:700;}
#ipos-apply-modal table{width:100%;border-collapse:collapse;font-size:13px;}
#ipos-apply-modal th{font-size:11px;font-weight:700;color:#5a5f7a;text-transform:uppercase;letter-spacing:.04em;padding:6px 8px;border-bottom:2px solid #e5e7eb;text-align:left;}
#ipos-apply-modal th.r,#ipos-apply-modal td.r{text-align:right;}
#ipos-apply-modal td{padding:8px 8px;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
#ipos-apply-modal tr:last-child td{border-bottom:none;}
#ipos-apply-modal .enc-pay-input{width:80px;padding:5px 7px;border:1.5px solid #e2e6f0;border-radius:5px;font-family:'DM Mono',monospace;font-size:13px;text-align:right;}
#ipos-apply-modal .enc-pay-input:focus{border-color:#5b21b6;outline:none;}
#ipos-apply-modal .enc-pay-input.over{border-color:#ef4444;background:#fef2f2;}
.iam-remaining.ok{background:#ecfdf5;color:#065f46;}
.iam-remaining.over{background:#fef2f2;color:#991b1b;}
.iam-remaining.under{background:#fff7ed;color:#92400e;}
</style>

<div id="ipos-apply-modal">
  <div class="iam-dialog">
    <div class="iam-head">
      <span>
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:6px;vertical-align:-2px;"><path d="M12 2v20M2 12h20"/></svg>
        <?php echo xlt('Apply Payment to Encounters'); ?>
      </span>
      <button onclick="closeApplyModal()">&times;</button>
    </div>
    <div class="iam-body">
      <p style="font-size:13px;color:#5a5f7a;margin-bottom:14px;">
        <?php echo xlt('Distribute the payment across encounters. You can apply all to one encounter or split across multiple.'); ?>
      </p>
      <table id="iam-enc-table">
        <thead>
          <tr>
            <th><?php echo xlt('DOS'); ?></th>
            <th><?php echo xlt('Enc#'); ?></th>
            <th class="r"><?php echo xlt('Charges'); ?></th>
            <th class="r"><?php echo xlt('Pt Paid'); ?></th>
            <th class="r"><?php echo xlt('Pt Due'); ?></th>
            <th class="r"><?php echo xlt('Apply'); ?></th>
          </tr>
        </thead>
        <tbody id="iam-enc-body">
          <!-- Populated by JS -->
        </tbody>
      </table>
    </div>
    <div class="iam-foot">
      <div>
        <span style="font-size:12px;color:#5a5f7a;"><?php echo xlt('Paid'); ?>: </span>
        <span class="iam-total" id="iam-paid-total">$0.00</span>
        &nbsp;&nbsp;
        <span style="font-size:12px;color:#5a5f7a;"><?php echo xlt('Remaining'); ?>: </span>
        <span class="iam-remaining ok" id="iam-remaining">$0.00</span>
      </div>
      <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-outline" onclick="iamAutoDistribute()"><?php echo xlt('Auto Distribute'); ?></button>
        <button type="button" class="btn btn-outline" onclick="closeApplyModal()"><?php echo xlt('Cancel'); ?></button>
        <button type="button" class="btn btn-success" id="iam-save-btn" onclick="iamSave()"><?php echo xlt('Post Payment'); ?></button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

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

<?php if ($GLOBALS['payment_gateway'] == 'iPOSpays'): ?>
// ── iPOSpays HPP integration ─────────────────────────────────────────────────
var iposPid       = <?php echo (int)$pid; ?>;
var iposCsrf      = <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>;
var iposProxyUrl  = <?php echo json_encode($GLOBALS['webroot'] . '/interface/patient_file/ipos_hpp_proxy.php'); ?>;
var iposReturnUrl = <?php echo json_encode($GLOBALS['webroot'] . '/interface/patient_file/ipos_return.php'); ?>;

// Override the generic openCCModal() for iPOSpays
function openCCModal() {
    // Collect all encounter ids — amount is entered inside the hosted page
    var encIds = [];
    document.querySelectorAll('input[name^="form_upay["]').forEach(function(inp) {
        var m = inp.name.match(/\[(\d+)\]/);
        if (m) encIds.push(m[1]);
    });
    // Also check button's stored dataset (set by toggleCCButton)
    var ccBtn = document.getElementById('cc-btn-wrap');
    if (!encIds.length && ccBtn && ccBtn.dataset.encs) {
        encIds = ccBtn.dataset.encs.split(',').filter(Boolean);
    }

    document.getElementById('ipos-display-amt').textContent = '—';
    document.getElementById('ipos-success-banner').style.display = 'none';
    document.getElementById('ipos-frame').src = 'about:blank';
    document.getElementById('ipos-spinner').style.display = 'flex';
    document.getElementById('ipos-modal').style.display = 'flex';

    // Determine amount based on payment type
    var payType   = (document.querySelector('input[name="radio_type_of_payment"]:checked') || {}).value || '';
    var prepayEl  = document.getElementById('form_prepayment');
    var totalAmt  = 0;

    if (payType === 'pre_payment') {
        totalAmt = parseFloat(prepayEl ? prepayEl.value : 0) || 0;
        if (totalAmt < 0.01) {
            document.getElementById('ipos-spinner').style.display = 'none';
            document.getElementById('ipos-modal').style.display = 'none';
            alert(<?php echo xlj('Please enter a pre-payment amount first.'); ?>);
            return;
        }
    } else {
        // Use whatever is typed in the Paying column inputs first
        document.querySelectorAll('input[name^="form_upay["]').forEach(function(inp) {
            totalAmt += parseFloat(inp.value || 0);
        });
        // If nothing typed, fall back to the Pt Due balances
        if (totalAmt < 0.01) {
            document.querySelectorAll('input[name^="form_upay["]').forEach(function(inp) {
                totalAmt += parseFloat(inp.dataset.ptDue || 0);
            });
        }
        if (totalAmt < 0.01) {
            document.getElementById('ipos-spinner').style.display = 'none';
            document.getElementById('ipos-modal').style.display = 'none';
            alert(<?php echo xlj('Please enter a payment amount in the Paying column before charging a card.'); ?>);
            return;
        }
    }

    var amtCents = Math.round(totalAmt * 100);
    document.getElementById('ipos-display-amt').textContent = totalAmt.toFixed(2);

    var fd = new FormData();
    fd.append('csrf_token_form', iposCsrf);
    fd.append('pid',       iposPid);
    fd.append('amt_cents', amtCents);
    fd.append('encs',      encIds.join(','));
    fd.append('pat_name',  <?php echo json_encode(($patrow['fname'] ?? '') . ' ' . ($patrow['lname'] ?? '')); ?>);

    fetch(iposProxyUrl, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.error) {
                document.getElementById('ipos-spinner').style.display = 'none';
                alert('iPOSpays error: ' + data.error);
                closeIposModal();
                return;
            }
            var hppUrl = data.hppUrl || data.url || data.paymentUrl || data.redirectUrl || '';
            if (!hppUrl) {
                document.getElementById('ipos-spinner').style.display = 'none';
                alert('iPOSpays did not return a payment URL.\n' + JSON.stringify(data));
                closeIposModal();
                return;
            }
            document.getElementById('ipos-frame').src = hppUrl;
        })
        .catch(function(err) {
            document.getElementById('ipos-spinner').style.display = 'none';
            alert('Network error contacting iPOSpays proxy: ' + err);
            closeIposModal();
        });
}

function iposFrameLoaded() {
    var frame = document.getElementById('ipos-frame');
    if (frame.src && frame.src !== 'about:blank') {
        document.getElementById('ipos-spinner').style.display = 'none';
    }
}

function closeIposModal() {
    document.getElementById('ipos-modal').style.display = 'none';
    document.getElementById('ipos-frame').src = 'about:blank';
}

// ── iPOSpays encounter application modal state ──────────────────────────────
var iamPaidAmt   = 0;
var iamTxRef     = '';
var iamAuthCode  = '';
var iamSessionId = 0;
var iamPayType   = '';

// Listen for success/failure messages posted from ipos_return.php in the iframe
window.addEventListener('message', function(evt) {
    if (!evt.data) return;

    // ── Payment failed / cancelled ──────────────────────────────────────────
    if (evt.data.type === 'ipos_payment_failed') {
        closeIposModal();
        iposRefreshPayingCols(false);
        return;
    }

    if (evt.data.type !== 'ipos_payment_success') return;

    var d = evt.data;
    iamPaidAmt   = parseFloat(d.amount || 0);
    iamTxRef     = d.txRef     || d.transactionId || '';
    iamAuthCode  = d.authCode  || '';
    iamSessionId = parseInt(d.sessionId || 0);
    iamPayType   = (document.querySelector('input[name="radio_type_of_payment"]:checked') || {}).value || '';

    // Show success banner in payment modal briefly
    var banner = document.getElementById('ipos-success-banner');
    var msgEl  = document.getElementById('ipos-success-msg');
    if (msgEl) msgEl.textContent = <?php echo xlj('Payment approved'); ?> +
        ' — $' + iamPaidAmt.toFixed(2) +
        (iamAuthCode ? ' | Auth: ' + iamAuthCode : '') +
        (iamTxRef    ? ' | Ref: '  + iamTxRef    : '');
    if (banner) banner.style.display = 'flex';

    // Close payment iframe modal after a beat, then open apply modal
    setTimeout(function() {
        closeIposModal();
        if (iamPayType === 'pre_payment') {
            // Pre-payment: no encounter to apply — just set amount and save
            var prepEl = document.getElementById('form_prepayment');
            if (prepEl) prepEl.value = iamPaidAmt.toFixed(2);
            var src = document.getElementById('form_source');
            if (src) src.value = iamTxRef;
            var sid = document.getElementById('ipos_session_id');
            if (sid && iamSessionId) sid.value = iamSessionId;
            var saveBtn = document.querySelector("[name='form_save']");
            if (saveBtn) saveBtn.click();
        } else {
            // Copay / Invoice balance: open encounter application modal
            openApplyModal(iamPaidAmt, iamTxRef, iamSessionId);
        }
    }, 900);
});

// ── Encounter application modal ──────────────────────────────────────────────
function openApplyModal(paidAmt, txRef, sessionId) {
    iamPaidAmt   = paidAmt;
    iamTxRef     = txRef;
    iamSessionId = sessionId;

    // Build encounter rows from the payment form's encounter table
    var tbody = document.getElementById('iam-enc-body');
    tbody.innerHTML = '';
    var inputs = document.querySelectorAll('input[name^="form_upay["]');
    inputs.forEach(function(inp) {
        var row   = inp.closest('tr');
        var dos   = row ? (row.cells[0] ? row.cells[0].textContent.trim() : '') : '';
        var enc   = inp.name.match(/\[(\d+)\]/);
        var encId = enc ? enc[1] : '?';
        var due   = parseFloat(inp.dataset.ptDue || 0);
        var chg   = row ? (row.cells[2] ? row.cells[2].textContent.trim() : '0.00') : '0.00';
        var paid  = row ? (row.cells[4] ? row.cells[4].textContent.trim() : '0.00') : '0.00';

        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td class="mono">' + dos + '</td>' +
            '<td class="mono" style="color:var(--purple);">' + encId + '</td>' +
            '<td class="r">' + chg + '</td>' +
            '<td class="r">' + paid + '</td>' +
            '<td class="r" style="font-weight:700;color:var(--amber);">$' + due.toFixed(2) + '</td>' +
            '<td class="r"><input type="text" class="enc-pay-input" data-enc="' + encId +
            '" data-due="' + due + '" value="" placeholder="0.00" oninput="iamRecalc()"></td>';
        tbody.appendChild(tr);
    });

    document.getElementById('iam-paid-total').textContent = '$' + paidAmt.toFixed(2);
    iamRecalc();
    iamAutoDistribute();  // pre-fill sensibly
    document.getElementById('ipos-apply-modal').style.display = 'flex';
}

function closeApplyModal() {
    document.getElementById('ipos-apply-modal').style.display = 'none';
    // Restore paying column
    iposRefreshPayingCols(false);
}

function iamRecalc() {
    var applied = 0;
    document.querySelectorAll('.enc-pay-input').forEach(function(inp) {
        applied += parseFloat(inp.value || 0);
    });
    applied = Math.round(applied * 100) / 100;
    var remaining = Math.round((iamPaidAmt - applied) * 100) / 100;
    var remEl = document.getElementById('iam-remaining');
    remEl.textContent = '$' + remaining.toFixed(2);
    remEl.className = 'iam-remaining ' + (remaining < -0.005 ? 'over' : remaining > 0.005 ? 'under' : 'ok');
    // Highlight over-applied inputs
    document.querySelectorAll('.enc-pay-input').forEach(function(inp) {
        inp.classList.toggle('over', parseFloat(inp.value || 0) < 0);
    });
    document.getElementById('iam-save-btn').disabled = (remaining < -0.005);
}

function iamAutoDistribute() {
    var remaining = iamPaidAmt;
    document.querySelectorAll('.enc-pay-input').forEach(function(inp) {
        var due = parseFloat(inp.dataset.due || 0);
        if (remaining <= 0) { inp.value = ''; return; }
        var apply = due > 0 ? Math.min(due, remaining) : 0;
        inp.value = apply > 0 ? apply.toFixed(2) : '';
        remaining = Math.round((remaining - apply) * 100) / 100;
    });
    // Any remainder on first row
    if (remaining > 0.005) {
        var first = document.querySelector('.enc-pay-input');
        if (first) first.value = (parseFloat(first.value || 0) + remaining).toFixed(2);
    }
    iamRecalc();
}

function iamSave() {
    // Populate the main form's form_upay inputs with the applied amounts
    document.querySelectorAll('.enc-pay-input').forEach(function(inp) {
        var encId = inp.dataset.enc;
        var mainInp = document.querySelector('input[name="form_upay[' + encId + ']"]');
        if (mainInp) {
            mainInp.value = parseFloat(inp.value || 0) > 0 ? parseFloat(inp.value).toFixed(2) : '';
            mainInp.closest('td').style.display = '';
        }
    });
    document.querySelectorAll('th.pay-col').forEach(function(th) { th.style.display = ''; });
    calcTotal();

    // Set reference fields
    var src = document.getElementById('form_source');
    if (src) src.value = iamTxRef;
    var sid = document.getElementById('ipos_session_id');
    if (sid && iamSessionId) sid.value = iamSessionId;

    // Show post button and submit
    var postBtn = document.getElementById('post-payment-btn');
    if (postBtn) postBtn.style.display = '';

    document.getElementById('ipos-apply-modal').style.display = 'none';
    var saveBtn = document.querySelector("[name='form_save']");
    if (saveBtn) saveBtn.click();
}
<?php endif; // iPOSpays JS ?>
</script>
<?php endif; // cc_front_payments ?>

</body>
</html>
