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

// ── One-time correction: normalise any adj_amount rows stored as negative ────
// Earlier versions of this page stored adj_amount as -abs(value). This global
// UPDATE flips ALL negative adj rows so the balance walk always subtracts correctly.
sqlStatement(
    "UPDATE ar_activity
     SET adj_amount = ABS(adj_amount)
     WHERE adj_amount < 0
       AND deleted IS NULL
       AND account_code IN ('WO','AJ','PP','CM')"
);
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
        // Remove the ar_session row entirely
        sqlStatement("DELETE FROM ar_session WHERE session_id = ?", [$del_session]);
        $save_msg = xl('Payment deleted successfully.');
    }
}
// ── End delete handler ────────────────────────────────────────────────────────

// ── Adjustments / Write-Offs handler ─────────────────────────────────────────
if (!empty($_POST['post_adjustment'])) {
    $adj_pid     = intval($_POST['adj_pid']        ?? $pid);
    $adj_enc     = intval($_POST['adj_encounter']   ?? 0);
    $adj_type    = trim($_POST['adj_type']          ?? 'write_off');
    $adj_reason  = trim($_POST['adj_reason']        ?? '');
    $adj_note    = trim($_POST['adj_note']          ?? '');
    $adj_post_as = trim($_POST['adj_post_as']       ?? 'insurance');
    $adj_level   = trim($_POST['adj_level']         ?? 'line');

    // Mirror the native payment handler exactly:
    // - Amount goes in pay_amount (NOT adj_amount — adj_amount stays 0)
    // - account_code: 'WO' for write-off, 'AJ' for adjustment
    // - payer_type: 1=insurance, 0=patient
    // - adj_amount is always 0 (native convention for write-offs posted this way)

    $adj_payer_type = ($adj_post_as === 'insurance') ? 1 : 0;
    $adj_account_code = ($adj_type === 'write_off') ? 'WO' : 'AJ';

    $adj_memo = $adj_reason ?: ($adj_type === 'write_off' ? 'Write-Off' : 'Adjustment');
    $adj_desc = ($adj_type === 'write_off' ? xl('Write-Off') : xl('Adjustment'));
    if ($adj_reason) $adj_desc .= ' [' . $adj_reason . ']';
    if ($adj_note)   $adj_desc .= ': ' . substr($adj_note, 0, 60);

    // Look up primary insurance payer
    $adj_ins_row  = sqlQuery(
        "SELECT id AS payer_id FROM insurance_data WHERE pid=? AND type='primary' AND provider>0 ORDER BY date DESC LIMIT 1",
        [$adj_pid]
    );
    $adj_payer_id = ($adj_post_as === 'insurance' && !empty($adj_ins_row['payer_id']))
                  ? intval($adj_ins_row['payer_id']) : 0;

    // Collect lines first so pay_total is known before creating ar_session
    $lines_to_post = [];

    if ($adj_level === 'line' && !empty($_POST['adj_lines'])) {
        foreach ($_POST['adj_lines'] as $billing_id => $line_data) {
            $billing_id = intval($billing_id);
            $line_amt   = round(floatval($line_data['amount'] ?? 0), 2);
            if ($line_amt < 0.005) continue;
            $brow = sqlQuery(
                "SELECT code_type, code, modifier FROM billing WHERE id=? AND pid=? LIMIT 1",
                [$billing_id, $adj_pid]
            );
            if (!$brow) continue;
            $lines_to_post[] = [
                'code_type' => $brow['code_type'],
                'code'      => $brow['code'],
                'modifier'  => $brow['modifier'] ?? '',
                'amount'    => $line_amt,
                'memo'      => trim($line_data['reason'] ?? $adj_memo) ?: $adj_memo,
            ];
        }
    } elseif ($adj_level === 'invoice' && !empty($_POST['adj_invoice_amount'])) {
        $inv_amt = round(floatval($_POST['adj_invoice_amount']), 2);
        if ($inv_amt >= 0.005) {
            $brow = sqlQuery(
                "SELECT code_type, code, modifier FROM billing WHERE pid=? AND encounter=? AND activity!=0 ORDER BY id LIMIT 1",
                [$adj_pid, $adj_enc]
            );
            if ($brow) {
                $lines_to_post[] = [
                    'code_type' => $brow['code_type'],
                    'code'      => $brow['code'],
                    'modifier'  => $brow['modifier'] ?? '',
                    'amount'    => $inv_amt,
                    'memo'      => $adj_memo,
                ];
            }
        }
    }

    if (!empty($lines_to_post)) {
        $adj_total = round(array_sum(array_column($lines_to_post, 'amount')), 2);

        // ar_session — mirrors native invoice_balance handler structure
        $session_id = sqlInsert(
            "INSERT INTO ar_session
                (payer_id, user_id, reference, check_date, deposit_date, pay_total,
                 global_amount, payment_type, description, patient_id,
                 payment_method, adjustment_code, post_to_date)
             VALUES (?,?,?,now(),now(),?,'',?,?,?,?,?,now())",
            [
                $adj_payer_id,
                $_SESSION['authUserID'],
                $adj_memo,
                $adj_total,
                $adj_post_as === 'insurance' ? 'insurance' : 'patient',
                $adj_desc,
                $adj_pid,
                $adj_post_as === 'insurance' ? 'insurance_payment' : 'patient_payment',
                $adj_type === 'write_off' ? 'insurance_adjust' : 'adjustment',
            ]
        );

        // ar_activity — amount in pay_amount, adj_amount=0 (native convention)
        foreach ($lines_to_post as $l) {
            sqlBeginTrans();
            $seq = sqlQuery(
                "SELECT IFNULL(MAX(sequence_no),0)+1 AS n FROM ar_activity WHERE pid=? AND encounter=?",
                [$adj_pid, $adj_enc]
            );
            sqlStatement(
                "INSERT INTO ar_activity
                    (pid, encounter, sequence_no, code_type, code, modifier,
                     payer_type, post_time, post_user, session_id,
                     pay_amount, adj_amount, memo, account_code)
                 VALUES (?,?,?,?,?,?,?,now(),?,?,?,0,?,?)",
                [
                    $adj_pid, $adj_enc, intval($seq['n']),
                    $l['code_type'], $l['code'], $l['modifier'],
                    $adj_payer_type,
                    $_SESSION['authUserID'],
                    $session_id,
                    $l['amount'],   // amount in pay_amount, adj_amount stays 0
                    $l['memo'],
                    $adj_account_code,
                ]
            );
            sqlCommitTrans();
        }

        $save_msg = xl('Adjustment posted successfully.');
    } else {
        $save_err = xl('No adjustment amounts were entered.');
    }
}
// ── End adjustments handler ──────────────────────────────────────────────────

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

$ledger_rows = [];
while ($enc = sqlFetchArray($enc_res)) {
    // Charges
    $chrgs = sqlStatement(
        "SELECT b.code, b.code_text, b.modifier, b.fee, b.units, b.bill_date,
                ic.name AS ins_name, b.payer_id
         FROM billing b LEFT JOIN insurance_companies ic ON ic.id=b.payer_id
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
        $p_ac      = strtoupper(trim($p['account_code'] ?? ''));
        $p_is_wo   = in_array($p_ac, ['WO','AJ','CO']); // write-off/adj: pay_amount = adj amount
        $p_is_ins  = !empty($p['payer_id']);

        if ($p_is_wo) {
            // Write-off rows: pay_amount holds the adj value; route to adj buckets
            $enc_adj   += $p['pay_amount'];
            $total_adj += $p['pay_amount'];
            $enc_ins_adj   += $p['pay_amount'];
            // Not counted in pmt totals
        } else {
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
        }
        $pmt_rows[] = $p;
    }

    // ── Mirror pat_ledger.php balance walk exactly ───────────────────────────
    // Step 1 (pat_ledger lines ~2922-2929): charges start in ins_bal or pat_bal
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
    $enc_bal = $enc_chg;

    // Step 2 (pat_ledger PrintCreditDetail): walk each payment row.
    // ERA insurance rows detected via payer_id; PR memo rows shift balance ins→pat.
    // Plain insurance: subtract (pmt+adj) from ins_bal.
    // Plain patient:   subtract pmt from pat_bal, adj from ins_bal.
    foreach ($pmt_rows as $p) {
        $rpmt     = (float)($p['pay_amount'] ?? 0);
        $radj     = abs((float)($p['adj_amount'] ?? 0));
        $memo_raw = strtolower($p['memo'] ?? '');
        $ac       = strtoupper(trim($p['account_code'] ?? ''));
        $is_ins   = !empty($p['payer_id']); // session payer_id

        // Write-off and adjustment rows use pay_amount (adj_amount=0).
        // They always reduce ins_bal regardless of payer_type.
        $is_writeoff = in_array($ac, ['WO','AJ','CO']);

        // Detect PR (patient responsibility) memo — shifts balance ins→pat
        $is_pr = $is_ins && !$is_writeoff && (
            strpos($memo_raw, 'copay')       !== false ||
            strpos($memo_raw, 'deductible')  !== false ||
            strpos($memo_raw, 'coinsurance') !== false ||
            strpos($memo_raw, 'ins1')        !== false ||
            preg_match('/\bpr\b/i', $memo_raw)
        );

        if ($is_writeoff) {
            // Write-offs always reduce insurance balance (mirrors sl_eob_search balance calc)
            $enc_ins_bal -= ($rpmt + $radj);
        } elseif ($is_pr) {
            if (preg_match('/(\d+\.\d{2})/', $memo_raw, $prm)) {
                $pr_total = (float)$prm[1];
            } else {
                $pr_total = abs($rpmt) + $radj;
            }
            $enc_ins_bal -= $pr_total;
            $enc_pat_bal += $pr_total;
        } elseif ($is_ins) {
            $enc_ins_bal -= ($rpmt + $radj);
        } else {
            $enc_pat_bal -= $rpmt;
            $enc_ins_bal -= $radj;
        }

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
        'enc_ins_pmt' => $enc_ins_pmt,
        'enc_ins_adj' => $enc_ins_adj,
        'enc_pat_adj' => $enc_pat_adj,
        'enc_pat_pmt' => $enc_pat_pmt,
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
        "SELECT COALESCE(SUM(pay_amount),0) AS p, COALESCE(SUM(ABS(adj_amount)),0) AS a
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
            "SELECT COALESCE(SUM(pay_amount),0) AS p, COALESCE(SUM(ABS(adj_amount)),0) AS a
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
/* Adjustment panel */
#panel-adjust .adj-amount-inp:not(:disabled) { border-color: var(--amber); background: var(--amber-l); }
#panel-adjust .adj-amount-inp:disabled { background: var(--surface2); color: var(--text3); }
#panel-adjust .adj-reason-inp:disabled { background: var(--surface2); color: var(--text3); }
#panel-adjust #adj-lines-table tbody tr:has(.adj-line-check:checked) { background: #fffbf0; }
#panel-adjust #adj-lines-table tbody tr:has(.adj-line-check:checked) td { border-color: #fde68a; }
.adj-type-badge { display:inline-flex;align-items:center;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;gap:4px; }
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
    <button type="button" class="nav-btn" id="btn-adjust" onclick="switchTab('adjust')">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
      <?php echo xlt('Adjustments / Write-Offs'); ?>
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
            <input type="text" class="form-control" name="form_source" placeholder="<?php echo xla('Optional'); ?>">
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
              <label class="radio-label"><input type="radio" name="radio_type_of_payment" value="invoice_balance"><?php echo xlt('Invoice Balance'); ?></label>
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
              <th class="r"><?php echo xlt('Paying'); ?></th>
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
          <?php if (!empty($GLOBALS['cc_stripe_terminal'])): ?>
          <button type="button" id="pos-btn-wrap" class="btn btn-success" onclick="posDialog()" style="display:none;">
            <?php echo xlt('POS Payment'); ?>
          </button>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
      <button type="submit" class="btn btn-success">
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
                style="background:var(--red-l);color:var(--red);border:1px solid #fca5a5;margin-top:4px;"
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
        <div class="sum-lbl"><?php echo xlt('Insurance Paid'); ?></div>
        <div class="sum-val" style="color:var(--green);"><?php echo text(mdi_money($total_ins_pmt)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Applied Patient Payments'); ?></div>
        <div class="sum-val" style="color:var(--green);"><?php echo text(mdi_money($total_pat_pmt)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Adjustments'); ?></div>
        <div class="sum-val" style="color:var(--amber);"><?php echo $total_adj != 0 ? text('-' . mdi_money($total_adj)) : text(mdi_money(0)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Open Insurance Balance'); ?></div>
        <div class="sum-val" style="color:<?php echo $total_ins_bal > 0 ? 'var(--red)' : 'var(--green)'; ?>;font-weight:700;"><?php echo text(mdi_money($total_ins_bal)); ?></div>
      </div>
      <div class="sum-cell">
        <div class="sum-lbl"><?php echo xlt('Patient Credit'); ?></div>
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
          <?php
            $ep_ins_pmt = $lr['enc_ins_pmt'];
            $ep_ins_adj = $lr['enc_ins_adj'];
            $ep_pat_pmt = $lr['enc_pat_pmt'];
            $ep_pat_adj = $lr['enc_pat_adj'] ?? 0;
            $ep_bal     = $lr['enc_bal'];
            $ep_chg     = $lr['enc_chg'];

            // Status badge — mirrors pat_ledger.php status logic exactly
            if ($ep_chg > 0 && $ep_bal <= 0.005 && $ep_bal >= -0.005) {
                $ep_status_cls = 'bg-green'; $ep_status_lbl = xlt('Paid In Full');
            } elseif ($ep_ins_pmt > 0 && $ep_bal > 0.005) {
                $ep_status_cls = 'bg-amber'; $ep_status_lbl = xlt('Partially Paid');
            } elseif ($ep_bal > 0.005) {
                $ep_status_cls = 'bg-red'; $ep_status_lbl = xlt('Balance Due');
            } elseif ($ep_bal < -0.005) {
                $ep_status_cls = 'bg-green'; $ep_status_lbl = xlt('Overpaid');
            } else {
                $ep_status_cls = 'bg-red'; $ep_status_lbl = xlt('Balance Due');
            }
          ?>
          <?php if ($ep_ins_pmt > 0 || $ep_ins_adj > 0 || $ep_pat_pmt > 0 || $ep_pat_adj > 0): ?>
          <div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap;">
            <?php if ($ep_ins_pmt > 0): ?>
            <span class="badge" style="background:#dbeafe;color:#1e40af;">
              <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#1e40af;margin-right:4px;flex-shrink:0;"></span>
              INS <?php echo text(mdi_money($ep_ins_pmt)); ?>
            </span>
            <?php endif; ?>
            <?php if ($ep_ins_adj > 0): ?>
            <span class="badge" style="background:#fef3c7;color:#92400e;">
              <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-right:4px;flex-shrink:0;"></span>
              ADJ <?php echo $ep_ins_adj ? text('-' . mdi_money($ep_ins_adj)) : text(mdi_money(0)); ?>
            </span>
            <?php endif; ?>
            <?php if ($ep_pat_pmt > 0): ?>
            <span class="badge" style="background:#ede9fe;color:#5b21b6;">
              <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#5b21b6;margin-right:4px;flex-shrink:0;"></span>
              PAT <?php echo text(mdi_money($ep_pat_pmt)); ?>
            </span>
            <?php endif; ?>
            <?php if ($ep_pat_adj > 0): ?>
            <span class="badge" style="background:#fef3c7;color:#92400e;">
              <span style="display:inline-block;width:7px;height:7px;border-radius:50%;background:#f59e0b;margin-right:4px;flex-shrink:0;"></span>
              ADJ <?php echo text('-' . mdi_money($ep_pat_adj)); ?>
            </span>
            <?php endif; ?>
          </div>
          <?php else: ?>
          <div style="display:flex;align-items:center;gap:5px;color:var(--text3);font-size:11px;">
            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
            <?php echo xlt('No Payments'); ?>
          </div>
          <?php endif; ?>
          <span class="badge <?php echo attr($ep_status_cls); ?>"><?php echo $ep_status_lbl; ?></span>
          <div style="display:flex;gap:14px;font-size:11px;">
            <div style="display:flex;flex-direction:column;align-items:flex-end;">
              <span style="font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:.04em;"><?php echo xlt('Charge'); ?></span>
              <span style="font-family:'DM Mono',monospace;font-weight:600;"><?php echo text(mdi_money($ep_chg)); ?></span>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;">
              <span style="font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:.04em;"><?php echo xlt('Ins Paid'); ?></span>
              <span style="font-family:'DM Mono',monospace;font-weight:600;color:<?php echo $ep_ins_pmt > 0 ? 'var(--green)' : 'inherit'; ?>;"><?php echo text(mdi_money($ep_ins_pmt)); ?></span>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;">
              <span style="font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:.04em;"><?php echo xlt('Pat Paid'); ?></span>
              <span style="font-family:'DM Mono',monospace;font-weight:600;"><?php echo text(mdi_money($ep_pat_pmt)); ?></span>
            </div>
            <div style="display:flex;flex-direction:column;align-items:flex-end;">
              <span style="font-size:10px;color:var(--text3);text-transform:uppercase;letter-spacing:.04em;"><?php echo xlt('Balance'); ?></span>
              <span style="font-family:'DM Mono',monospace;font-weight:700;color:<?php echo $ep_bal > 0.005 ? 'var(--red)' : ($ep_bal < -0.005 ? 'var(--green)' : 'inherit'); ?>;"><?php echo text(mdi_money($ep_bal)); ?></span>
            </div>
          </div>
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
              $is_ins = !empty($p['payer_id']); // session payer_id — mirrors pat_ledger.php
              $line_pmts_by_code[$k]['pmt'] += $p['pay_amount'];
              $line_pmts_by_code[$k]['adj'] += abs($p['adj_amount']); // always positive reduction
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
            $is_ins = !empty($p['payer_id']); // session payer_id — mirrors pat_ledger.php
            $type_label = $is_ins ? xlt('Insurance') : xlt('Patient');
            $type_style = $is_ins ? 'background:#dbeafe;color:#1e40af;' : 'background:#fef3c7;color:#92400e;';
            $p_is_wo_row = in_array(strtoupper(trim($p['account_code'] ?? '')), ['WO','AJ','CO']);
            $ins_col  = ($is_ins || $p_is_wo_row) ? mdi_money($p['pay_amount'] + abs($p['adj_amount'])) : '—';
            $pat_col  = !$is_ins ? mdi_money($p['pay_amount']) : '—';
            $tot_bal  = mdi_money($p['pay_amount'] + abs($p['adj_amount']));
          ?>
          <tr style="background:<?php echo $p['pay_amount']>0 ? 'var(--green-l)' : ($p['adj_amount']!=0 ? 'var(--amber-l)' : 'var(--surface2)'); ?>;">
            <td class="mono"><?php echo text(substr($p['post_time'],0,10)); ?></td>
            <td><span style="font-size:11px;background:var(--surface2);border:1px solid var(--border);padding:2px 7px;border-radius:10px;"><?php echo text($p['ins_name'] ?: xl('Patient')); ?></span></td>
            <td class="mono" style="color:var(--purple);font-size:11px;"><?php echo text($p['code'] ?? ''); ?></td>
            <td><?php echo text(mdi_list_title($p['payment_method'],'payment_method')); ?></td>
            <td><span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;<?php echo $type_style; ?>"><?php echo $type_label; ?></span></td>
            <td class="r" style="color:#1d4ed8;font-weight:700;"><?php echo $is_ins  ? text($ins_col) : '—'; ?></td>
            <td class="r" style="color:#b45309;font-weight:<?php echo !$is_ins?'700':'400'; ?>;"><?php echo !$is_ins ? text($pat_col) : '—'; ?></td>
            <td class="r" style="font-weight:700;color:var(--red);">$<?php echo text(number_format($p['pay_amount'] + abs($p['adj_amount']),2)); ?></td>
            <td class="r" style="color:var(--green);font-weight:700;">
              <?php echo $p['pay_amount'] && $is_ins ? text(mdi_money($p['pay_amount'])) : '—'; ?>
            </td>
            <td class="r" style="color:var(--amber);">
              <?php echo $p['adj_amount'] ? text('-' . mdi_money(abs($p['adj_amount']))) : ($p['pay_amount'] && !$is_ins ? text(mdi_money($p['pay_amount'])) : '—'); ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>

        <div class="enc-footer">
          <span><span class="lbl"><?php echo xlt('Charges'); ?></span><?php echo text(mdi_money($lr['enc_chg'])); ?></span>
          <span><span class="lbl"><?php echo xlt('Payments'); ?></span><span style="color:var(--green);"><?php echo text(mdi_money($lr['enc_pmt'])); ?></span></span>
          <span><span class="lbl"><?php echo xlt('Adjustments'); ?></span><span style="color:var(--amber);"><?php echo $lr['enc_adj'] != 0 ? text('-' . mdi_money($lr['enc_adj'])) : text(mdi_money(0)); ?></span></span>
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
          <span style="font-size:10px;color:#a78bfa;font-weight:600;text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('NET RECEIVABLE'); ?></span>
          <span style="font-family:'DM Mono',monospace;font-size:15px;font-weight:700;color:#f9a8d4;"><?php echo text(mdi_money($total_chg - $total_pmt - $total_adj)); ?></span>
        </span>
        <span style="display:flex;flex-direction:column;align-items:flex-end;">
          <span style="font-size:10px;color:#93c5fd;font-weight:600;text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('OPEN INSURANCE A/R'); ?></span>
          <span style="font-family:'DM Mono',monospace;font-size:15px;font-weight:700;color:#93c5fd;"><?php echo text(mdi_money($total_ins_bal)); ?></span>
        </span>
        <span style="display:flex;flex-direction:column;align-items:flex-end;">
          <span style="font-size:10px;color:#fcd34d;font-weight:600;text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('AVAILABLE PATIENT CREDIT'); ?></span>
          <span style="font-family:'DM Mono',monospace;font-size:16px;font-weight:700;color:#fcd34d;"><?php echo text(mdi_money($total_pat_bal)); ?></span>
        </span>
      </div>
    </div>
    <?php endif; ?>

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
            <div class="card-body" style="display:flex;flex-direction:column;gap:12px;">

              <div class="form-group">
                <label class="form-label"><?php echo xlt('Adjustment Type'); ?> <span style="color:var(--red);">*</span></label>
                <select class="form-control" name="adj_type" id="adj_type_sel" onchange="adjTypeChanged(this.value)">
                  <option value="write_off"><?php echo xlt('Write-Off'); ?></option>
                  <option value="adjustment"><?php echo xlt('Adjustment'); ?></option>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label"><?php echo xlt('Reason Code'); ?></label>
                <select class="form-control" name="adj_reason" id="adj_reason_sel">
                  <option value=""><?php echo xlt('-- Select --'); ?></option>
                  <?php foreach ($adj_reason_codes as $arc): ?>
                  <option value="<?php echo attr($arc['option_id']); ?>"><?php echo text($arc['title']); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label"><?php echo xlt('Adjustment Reason'); ?></label>
                <input type="text" class="form-control" name="adj_reason_text" id="adj_reason_text"
                  placeholder="<?php echo xla('e.g. Insurance contracted allowance'); ?>">
              </div>

              <div class="form-group">
                <label class="form-label"><?php echo xlt('Adjustment Note'); ?></label>
                <textarea class="form-control" name="adj_note" rows="3"
                  placeholder="<?php echo xla('e.g. Per EOB: Payment adjusted per plan contracted agreement.'); ?>"
                  style="resize:vertical;font-size:12px;line-height:1.5;"></textarea>
              </div>

              <hr style="border:none;border-top:1px solid var(--border);margin:2px 0;">

              <div class="form-group">
                <label class="form-label"><?php echo xlt('Apply To'); ?> <span style="color:var(--red);">*</span></label>
                <select class="form-control" name="adj_encounter" id="adj_enc_sel" onchange="loadAdjLines(this.value)">
                  <option value=""><?php echo xlt('-- Select Encounter --'); ?></option>
                  <?php foreach ($encs as $er):
                    $bal_left = $er['charges'] - $er['ins_paid'] - $er['pat_paid'];
                  ?>
                  <option value="<?php echo attr($er['encounter']); ?>"
                    data-charges="<?php echo attr(number_format($er['charges'],2)); ?>"
                    data-bal="<?php echo attr(number_format(max(0,$bal_left),2)); ?>">
                    <?php echo text($er['encdate']); ?> &mdash; Enc #<?php echo text($er['encounter']); ?>
                    (Bal: <?php echo text(number_format(max(0,$bal_left),2)); ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>

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

              <!-- Invoice-level amount (shown when "Invoice Total" selected) -->
              <div id="adj-invoice-row" style="display:none;">
                <div class="form-group">
                  <label class="form-label"><?php echo xlt('Write-Off / Adjustment Amount'); ?></label>
                  <input type="text" class="form-control" name="adj_invoice_amount" id="adj_invoice_amount"
                    placeholder="0.00" oninput="updateAdjTotals()" style="max-width:150px;">
                </div>
              </div>

              <hr style="border:none;border-top:1px solid var(--border);margin:2px 0;">

              <div class="form-group">
                <label class="form-label"><?php echo xlt('Post As'); ?></label>
                <div class="radio-row">
                  <label class="radio-label">
                    <input type="radio" name="adj_post_as" value="insurance" checked>
                    <?php echo xlt('Insurance Adjustment'); ?>
                  </label>
                  <label class="radio-label">
                    <input type="radio" name="adj_post_as" value="patient">
                    <?php echo xlt('Patient Adjustment'); ?>
                  </label>
                </div>
              </div>

              <div class="form-group">
                <label class="radio-label" style="font-size:12px;cursor:pointer;">
                  <input type="checkbox" name="adj_lock" id="adj_lock" style="accent-color:var(--purple);" checked>
                  <span><?php echo xlt('Save Level (lock this adjustment)'); ?></span>
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
              <button type="button" class="btn btn-sm btn-outline"
                onclick="adjFillFromPayments()"
                title="<?php echo xla('Auto-fill write-off from balance remaining after payments'); ?>">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo xlt('Auto-Fill Balances'); ?>
              </button>
            </div>
            <div style="overflow-x:auto;">
              <table class="dt" id="adj-lines-table">
                <thead>
                  <tr>
                    <th style="width:30px;">
                      <input type="checkbox" id="adj-check-all" onchange="adjToggleAll(this.checked)"
                        style="accent-color:var(--purple);cursor:pointer;" title="<?php echo xla('Select all'); ?>">
                    </th>
                    <th><?php echo xlt('Code'); ?></th>
                    <th><?php echo xlt('Description'); ?></th>
                    <th class="r"><?php echo xlt('Charge'); ?></th>
                    <th class="r"><?php echo xlt('Payment'); ?></th>
                    <th class="r" style="color:var(--amber);"><?php echo xlt('Adjustment'); ?></th>
                    <th><?php echo xlt('Reason'); ?></th>
                    <th style="width:20px;"><?php echo xlt('Write-Off'); ?></th>
                    <th><?php echo xlt('Apply'); ?></th>
                  </tr>
                </thead>
                <tbody id="adj-lines-tbody">
                  <tr id="adj-lines-empty">
                    <td colspan="9" style="text-align:center;color:var(--text3);padding:20px;">
                      <?php echo xlt('Select an encounter above to load line items.'); ?>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Totals footer -->
            <div style="background:var(--surface2);border-top:2px solid var(--border);padding:10px 16px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px;">
              <div style="text-align:center;">
                <div class="form-label"><?php echo xlt('Total Charge'); ?></div>
                <div style="font-family:'DM Mono',monospace;font-size:15px;font-weight:700;" id="adj-tot-charge">$0.00</div>
              </div>
              <div style="text-align:center;">
                <div class="form-label"><?php echo xlt('Total Payment'); ?></div>
                <div style="font-family:'DM Mono',monospace;font-size:15px;font-weight:700;color:var(--green);" id="adj-tot-payment">$0.00</div>
              </div>
              <div style="text-align:center;">
                <div class="form-label" style="color:var(--amber);"><?php echo xlt('Total Adjustments'); ?></div>
                <div style="font-family:'DM Mono',monospace;font-size:15px;font-weight:700;color:var(--amber);" id="adj-tot-adj">-$0.00</div>
              </div>
              <div style="text-align:center;">
                <div class="form-label"><?php echo xlt('Patient Balance (After)'); ?></div>
                <div style="font-family:'DM Mono',monospace;font-size:15px;font-weight:700;" id="adj-tot-bal">$0.00</div>
              </div>
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
function switchTab(name) {
    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
    document.getElementById('panel-' + name).classList.add('active');
    var ids = ['btn-payment','btn-ledger','btn-search','btn-adjust'];
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

// ── Show/hide CC button when payment method changes ──────────────────────────
function toggleCCButton(val) {
    var show   = (val === 'credit_card');
    var ccBtn  = document.getElementById('cc-btn-wrap');
    var posBtn = document.getElementById('pos-btn-wrap');
    if (ccBtn)  ccBtn.style.display  = show ? 'inline-flex' : 'none';
    if (posBtn) posBtn.style.display = show ? 'inline-flex' : 'none';
}

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
    if (sel) toggleCCButton(sel.value);
});

top.restoreSession && top.restoreSession();

// ── Auto-open adjust tab ──────────────────────────────────────────────────────
<?php if (!empty($_POST['post_adjustment']) || ($_GET['tab'] ?? '') === 'adjust'): ?>
switchTab('adjust');
<?php endif; ?>

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

var _adjLines = [];       // current encounter's billing lines loaded via XHR
var _adjEncId = null;

function adjTypeChanged(val) {
    // Could update UI labels if needed
}

function adjLevelChanged(level) {
    var lineCard   = document.getElementById('adj-lines-card');
    var invoiceRow = document.getElementById('adj-invoice-row');
    if (level === 'line') {
        if (lineCard)   lineCard.style.display   = (_adjEncId ? '' : 'none');
        if (invoiceRow) invoiceRow.style.display  = 'none';
    } else {
        if (lineCard)   lineCard.style.display   = 'none';
        if (invoiceRow) invoiceRow.style.display  = '';
    }
}

function loadAdjLines(encId) {
    _adjEncId = encId ? parseInt(encId) : null;
    var summaryEl  = document.getElementById('adj-enc-summary');
    var linesCard  = document.getElementById('adj-lines-card');
    var emptyState = document.getElementById('adj-empty-state');
    var levelLine  = document.querySelector('input[name="adj_level"]:checked');

    if (!_adjEncId) {
        if (summaryEl)  summaryEl.style.display  = 'none';
        if (linesCard)  linesCard.style.display   = 'none';
        if (emptyState) emptyState.style.display  = '';
        return;
    }

    // Show encounter summary
    var ed = _adjEncData[_adjEncId] || {};
    if (summaryEl) {
        document.getElementById('adj-summary-inv').textContent =
            (<?php echo json_encode($pat_pubid); ?>) + '-' + _adjEncId;
        document.getElementById('adj-summary-dos').textContent = ed.date || '—';
        summaryEl.style.display = '';
    }
    if (emptyState) emptyState.style.display = 'none';

    // Fetch billing lines for this encounter via AJAX
    var pid = <?php echo (int)$pid; ?>;
    fetch('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/mdi_adj_lines.php?pid=' + pid + '&enc=' + _adjEncId + '&csrf=<?php echo urlencode(CsrfUtils::collectCsrfToken()); ?>')
        .then(function(r){ return r.json(); })
        .catch(function(){ return null; })
        .then(function(data) {
            if (data && data.lines) {
                _adjLines = data.lines;
            } else {
                // Build lines from DOM data we have
                _adjLines = buildFallbackLines(_adjEncId);
            }
            renderAdjLines();

            var level = (document.querySelector('input[name="adj_level"]:checked') || {}).value || 'line';
            if (level === 'line') {
                if (linesCard) linesCard.style.display = '';
            } else {
                if (linesCard) linesCard.style.display = 'none';
            }
        });
}

// Fallback: build approximate line data from ledger rows already on page
function buildFallbackLines(encId) {
    var lines = [];
    // Scan ledger DOM for this encounter's charge rows
    document.querySelectorAll('.enc-block').forEach(function(block) {
        var title = block.querySelector('.enc-title');
        if (!title || title.textContent.indexOf('#' + encId) === -1) return;
        block.querySelectorAll('.dt tbody tr').forEach(function(row) {
            var cells = row.querySelectorAll('td');
            if (cells.length < 4) return;
            lines.push({
                billing_id: 0,
                code: cells[0] ? cells[0].textContent.trim() : '',
                description: cells[1] ? cells[1].textContent.trim() : '',
                charge: parseFloat((cells[3] || cells[4] || {textContent:'0'}).textContent.replace(/[^0-9.]/g,'')) || 0,
                paid: 0,
                adj: 0
            });
        });
    });
    return lines;
}

function renderAdjLines() {
    var tbody = document.getElementById('adj-lines-tbody');
    if (!tbody) return;
    if (!_adjLines.length) {
        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:var(--text3);padding:20px;"><?php echo addslashes(xlt("No billing lines found for this encounter.")); ?></td></tr>';
        updateAdjTotals();
        return;
    }

    var reasonHtml = '<option value=""><?php echo addslashes(xlt("Select")); ?></option>';
    <?php foreach ($adj_reason_codes as $arc): ?>
    reasonHtml += '<option value="<?php echo addslashes(attr($arc['option_id'])); ?>"><?php echo addslashes(text($arc['title'])); ?></option>';
    <?php endforeach; ?>

    var html = '';
    _adjLines.forEach(function(line, i) {
        var charge  = parseFloat(line.charge) || 0;
        var paid    = parseFloat(line.paid)   || 0;
        var adjPrev = parseFloat(line.adj)    || 0;
        var balAfter = charge - paid - adjPrev;
        var bid = line.billing_id || 0;

        html += '<tr id="adjrow-' + i + '" style="' + (balAfter <= 0 ? 'opacity:.55;' : '') + '">';
        html += '<td style="text-align:center;">';
        html += '<input type="checkbox" class="adj-line-check" data-idx="' + i + '"';
        html += ' style="accent-color:var(--purple);cursor:pointer;"';
        html += ' onchange="adjLineCheckChanged(this)">';
        html += '</td>';
        html += '<td class="mono" style="color:var(--purple);font-weight:700;">' + escH(line.code) + '</td>';
        html += '<td style="font-size:11px;">' + escH(line.description) + '</td>';
        html += '<td class="r">$' + charge.toFixed(2) + '</td>';
        html += '<td class="r" style="color:var(--green);">$' + paid.toFixed(2) + '</td>';
        html += '<td class="r" style="color:var(--amber);">';
        if (bid) {
            html += '<input type="text" name="adj_lines[' + bid + '][amount]" class="pay-input adj-amount-inp"';
            html += ' data-idx="' + i + '" data-charge="' + charge + '" data-paid="' + paid + '" data-adj="' + adjPrev + '"';
            html += ' placeholder="0.00" oninput="updateAdjTotals()" disabled';
            html += ' style="width:80px;text-align:right;">';
        } else {
            html += '<span style="color:var(--text3);font-size:11px;">' + (adjPrev ? '-$'+adjPrev.toFixed(2) : '—') + '</span>';
        }
        html += '</td>';
        html += '<td>';
        if (bid) {
            html += '<select name="adj_lines[' + bid + '][reason]" class="form-control adj-reason-inp" disabled';
            html += ' style="font-size:11px;padding:4px 6px;min-width:120px;">';
            html += reasonHtml;
            html += '</select>';
        } else {
            html += '—';
        }
        html += '</td>';
        html += '<td style="text-align:center;">';
        if (bid) {
            html += '<input type="checkbox" class="adj-wo-check" data-idx="' + i + '"';
            html += ' data-bid="' + bid + '" data-bal="' + balAfter.toFixed(2) + '"';
            html += ' style="accent-color:var(--purple);cursor:pointer;" disabled';
            html += ' onchange="adjWOCheck(this)">';
        }
        html += '</td>';
        html += '<td style="text-align:center;">';
        html += '<input type="checkbox" class="adj-apply-check" data-idx="' + i + '"';
        html += ' style="accent-color:var(--purple);cursor:pointer;" disabled>';
        html += '</td>';
        html += '</tr>';
    });
    tbody.innerHTML = html;
    updateAdjTotals();
}

function adjLineCheckChanged(cb) {
    var idx  = parseInt(cb.dataset.idx);
    var row  = document.getElementById('adjrow-' + idx);
    var inp  = row ? row.querySelector('.adj-amount-inp') : null;
    var rea  = row ? row.querySelector('.adj-reason-inp') : null;
    var wo   = row ? row.querySelector('.adj-wo-check')   : null;
    var app  = row ? row.querySelector('.adj-apply-check') : null;
    var en   = cb.checked;
    if (inp) { inp.disabled  = !en; if (!en) inp.value = ''; }
    if (rea) { rea.disabled  = !en; }
    if (wo)  { wo.disabled   = !en; if (!en) wo.checked = false; }
    if (app) { app.disabled  = !en; }
    updateAdjTotals();
}

function adjWOCheck(cb) {
    var idx = parseInt(cb.dataset.idx);
    var bal = parseFloat(cb.dataset.bal) || 0;
    var row = document.getElementById('adjrow-' + idx);
    var inp = row ? row.querySelector('.adj-amount-inp') : null;
    if (inp) {
        inp.value = cb.checked && bal > 0 ? bal.toFixed(2) : '';
    }
    updateAdjTotals();
}

function adjToggleAll(checked) {
    document.querySelectorAll('.adj-line-check').forEach(function(cb) {
        cb.checked = checked;
        adjLineCheckChanged(cb);
    });
}

function adjFillFromPayments() {
    // Check all rows and fill remaining balance into adjustment amount
    document.querySelectorAll('.adj-line-check').forEach(function(cb) {
        if (!cb.checked) { cb.checked = true; adjLineCheckChanged(cb); }
    });
    document.querySelectorAll('.adj-wo-check').forEach(function(cb) {
        if (!cb.disabled) {
            var bal = parseFloat(cb.dataset.bal) || 0;
            var idx = parseInt(cb.dataset.idx);
            var row = document.getElementById('adjrow-' + idx);
            var inp = row ? row.querySelector('.adj-amount-inp') : null;
            if (inp && bal > 0.005) { inp.value = bal.toFixed(2); }
        }
    });
    // Also fill reason from global dropdown
    var globalReason = document.getElementById('adj_reason_sel').value;
    if (globalReason) {
        document.querySelectorAll('.adj-reason-inp').forEach(function(sel) {
            if (!sel.disabled) sel.value = globalReason;
        });
    }
    updateAdjTotals();
}

function updateAdjTotals() {
    var totCharge = 0, totPaid = 0, totAdj = 0;

    _adjLines.forEach(function(line, i) {
        totCharge += parseFloat(line.charge) || 0;
        totPaid   += parseFloat(line.paid)   || 0;
    });

    // Sum entered adjustment amounts
    document.querySelectorAll('.adj-amount-inp').forEach(function(inp) {
        if (!inp.disabled) totAdj += parseFloat(inp.value) || 0;
    });
    // Invoice-level
    var invInp = document.getElementById('adj_invoice_amount');
    if (invInp && invInp.value) totAdj += parseFloat(invInp.value) || 0;

    var balAfter = totCharge - totPaid - totAdj;

    var fc  = document.getElementById('adj-tot-charge');
    var fp  = document.getElementById('adj-tot-payment');
    var fa  = document.getElementById('adj-tot-adj');
    var fb  = document.getElementById('adj-tot-bal');

    if (fc) fc.textContent = '$' + totCharge.toFixed(2);
    if (fp) fp.textContent = '$' + totPaid.toFixed(2);
    if (fa) fa.textContent = totAdj > 0 ? '-$' + totAdj.toFixed(2) : '$0.00';
    if (fb) {
        fb.textContent = '$' + Math.max(0, balAfter).toFixed(2);
        fb.style.color = balAfter > 0.005 ? 'var(--amber)' : 'var(--green)';
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
    return (s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
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
</script>
<?php endif; // cc_front_payments ?>

</body>
</html>
