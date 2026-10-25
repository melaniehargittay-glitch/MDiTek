<?php

/**
 * This is a report to create a patient ledger of charges with payments
 * applied.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    WMT
 * @author    Terry Hill <terry@lillysystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2015 Rich Genandt <rgenandt@gmail.com>
 * @copyright Copyright (c) 2017-2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once('../globals.php');
require_once($GLOBALS['srcdir'] . '/patient.inc.php');
require_once($GLOBALS['srcdir'] . '/options.inc.php');
require_once($GLOBALS['srcdir'] . '/appointments.inc.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Menu\PatientMenuRole;
use OpenEMR\OeUI\OemrUI;
use OpenEMR\Services\FacilityService;

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}
if (!empty($_GET['encounter'])) {
    $_SESSION['encounter'] = (int) $_GET['encounter'];
}

if (!empty($_GET['pid'])) {
    $_SESSION['pid'] = (int) $_GET['pid'];
}

$facilityService = new FacilityService();

$enc_units = $total_units = 0;
$enc_chg = $total_chg = 0;
$enc_pmt = $total_pmt = 0;
$enc_adj = $total_adj = 0;
$enc_bal = $total_bal = 0;
$enc_ins_bal = $total_ins_bal = 0;
$enc_pat_bal = $total_pat_bal = 0;
$bgcolor = "#FFFFDD";
$orow = 0;

$pat_pid = $_GET['patient_id'] ?? null;
$type_form = $_GET['form'];

if (! AclMain::aclCheckCore('acct', 'rep')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Patient Ledger by Date")]);
    exit;
}

function GetAllUnapplied($pat = '', $from_dt = '', $to_dt = '')
{
    $all = array();
    if (!$pat) {
        return($all);
    }

    $sql = "SELECT ar_session.*, ins.name,
        pat.lname, pat.fname, pat.mname,

        (SELECT SUM(ar_activity.pay_amount)
           FROM ar_activity
          WHERE ar_activity.session_id = ar_session.session_id
            AND ar_activity.deleted IS NULL
        ) AS applied,

        (SELECT COUNT(*)
           FROM ar_activity
          WHERE ar_activity.session_id = ar_session.session_id
            AND ar_activity.deleted IS NULL
            AND (ar_activity.encounter IS NULL
                 OR ar_activity.encounter = 0)
        ) AS unapplied_count

        FROM ar_session

        LEFT JOIN insurance_companies AS ins
               ON ar_session.payer_id = ins.id

        LEFT JOIN patient_data AS pat
               ON ar_session.patient_id = pat.pid

        WHERE ar_session.created_time >= ?
          AND ar_session.created_time <= ?
          AND ar_session.patient_id = ?

          AND LOWER(COALESCE(ar_session.payment_method,'')) IN (
                'cash',
                'check',
                'check payment',
                'credit card',
                'patient_payment',
                'insurance_payment',
                'money_order',
                'eft',
                'ach'
          )";
    $result = sqlStatement($sql, array($from_dt, $to_dt, $pat));
    $iter = 0;
    while ($row = sqlFetchArray($result)) {

        // ─────────────────────────────────────
        // Skip adjustment/writeoff sessions
        // ─────────────────────────────────────
    
        $pmethod = strtolower(trim($row['payment_method'] ?? ''));
        $adjcode = strtolower(trim($row['adjustment_code'] ?? ''));
        $ref     = strtolower(trim($row['reference'] ?? ''));
        $desc    = strtolower(trim($row['description'] ?? ''));
    
        $is_adjustment =
            strpos($pmethod, 'adjustment') !== false
            || strpos($pmethod, 'write') !== false
            || strpos($adjcode, 'adjust') !== false
            || strpos($adjcode, 'write') !== false
            || strpos($adjcode, 'bad') !== false
            || strpos($adjcode, 'contract') !== false
            || strpos($ref, 'bad_debt') !== false
            || strpos($ref, 'contract_adjustment') !== false
            || strpos($desc, 'bad debt') !== false
            || strpos($desc, 'writeoff') !== false;
    
        if ($is_adjustment) {
            continue;
        }
    
        if (!$row['applied']) {
            continue;
        }
    
        // Only show if there is actual unallocated balance remaining
        $remaining =
            (float)($row['pay_total'] ?? 0)
            - (float)($row['applied'] ?? 0);
    
        if ($remaining <= 0 && !$GLOBALS['show_payment_history']) {
            continue;
        }
    
        // Skip sessions where ALL activity is tied to specific encounters
        if ($row['unapplied_count'] == 0 && !$GLOBALS['show_payment_history']) {
            continue;
        }
    
        $all[$iter] = $row;
        $iter++;
    }

    return($all);
}

function User_Id_Look($thisField)
{
    if (!$thisField) {
        return '';
    }

    $ret = '';
    $rlist = sqlStatement("SELECT lname, fname, mname FROM users WHERE id=?", array($thisField));
    $rrow = sqlFetchArray($rlist);
    if ($rrow) {
        $ret = $rrow['lname'] . ', ' . $rrow['fname'] . ' ' . $rrow['mname'];
    }

    return $ret;
}

function List_Look($thisData, $thisList)
{
    if ($thisList == 'occurrence') {
        if (!$thisData || $thisData == '') {
            return xl('Unknown or N/A');
        }
    }

    if ($thisData == '') {
        return '';
    }

    $fres = sqlStatement("SELECT title FROM list_options WHERE list_id = ? " .
        "AND option_id = ? AND activity = 1", array($thisList, $thisData));
    if ($fres) {
        $rret = sqlFetchArray($fres);
        $dispValue = xl_list_label($rret['title']);
        if ($thisList == 'occurrence' && $dispValue == '') {
            $dispValue = xl('Unknown or N/A');
        }
    } else {
        $dispValue = xl('Not Found');
    }

    return $dispValue;
}

function GetAllCredits($enc = '', $pat = '')
{
    $all = array();
    if (!$enc || !$pat) {
        return($all);
    }

    $sql = "SELECT activity.*, session.*, ins.name FROM ar_activity AS " .
    "activity LEFT JOIN ar_session AS session USING (session_id) " .
    "LEFT JOIN insurance_companies AS ins ON session.payer_id = " .
    "ins.id WHERE encounter = ? AND pid = ? AND activity.deleted IS NULL " .
    "ORDER BY sequence_no";
    $result = sqlStatement($sql, array($enc, $pat));
    $iter = 0;
    while ($row = sqlFetchArray($result)) {
        $all[$iter] = $row;
        $iter++;
    }

    return($all);
}
function PrintEncHeader($dt, $rsn, $dr, $enc = 0, $pid = 0, $enc_chg_preview = 0, $enc_pmt_preview = 0, $enc_bal_preview = 0, $enc_ins_bal_preview = 0, $enc_pat_bal_preview = 0)
{
    global $bgcolor, $orow, $mdiPmtUrl;
    $svcDate = substr($dt, 0, 10);

    // Use CPT description/carrier as subtitle instead of encounter reason
    // Fetch first billing row's code_text and carrier name
    $subtitle = '';
    if ($enc && $pid) {
        $sub_row = sqlQuery(
            "SELECT b.code, b.modifier, b.code_text, ins.name AS carrier
             FROM billing b
             LEFT JOIN insurance_companies ins ON ins.id = b.payer_id
             WHERE b.encounter=? AND b.pid=? AND b.activity>0
               AND b.code_type NOT IN ('ICD9','ICD9CM','ICD10','COPAY')
             ORDER BY b.id LIMIT 1",
            [(int)$enc, (int)$pid]
        );
        if ($sub_row) {
            $cpt_mod = $sub_row['code'] . ($sub_row['modifier'] ? ' ' . $sub_row['modifier'] : '');
            $desc    = $sub_row['code_text'] ?: '';
            $carrier = $sub_row['carrier']   ?: '';
            $subtitle = $cpt_mod;
            if ($desc)    $subtitle .= ' — ' . $desc;
            if ($carrier) $subtitle .= ' · ' . $carrier;
        } else {
            // Fallback to encounter reason if no billing row
            $subtitle = $rsn;
        }
    } else {
        $subtitle = $rsn;
    }
    if (strlen($subtitle) > 80) { $subtitle = substr($subtitle, 0, 80) . '...'; }

    // Fee-sheet link
    $encLink = text($enc);
    if ($enc && $pid) {
        $feeUrl     = $GLOBALS['webroot'] . '/interface/patient_file/summary/mdi_fee_sheet_bridge.php'
                    . '?encounter=' . (int)$enc . '&pid=' . (int)$pid;
        $feeOnclick = "top.restoreSession(); dlgopen('" . $feeUrl . "','',1400,750,'','" . xla('Fee Sheet') . "',{allowResize:true,allowDrag:true,type:'iframe'});";
        $encLink    = '<a href="#" onclick="' . attr($feeOnclick) . '" style="color:var(--purple);text-decoration:none;font-weight:700;">#' . text($enc) . '</a>';
    }
// ============================================================
// STATUS + PAYMENT / ADJUSTMENT ACTIVITY
// ============================================================

// Legacy preview values
$bal_net  = $enc_ins_bal_preview + $enc_pat_bal_preview;
$ins_paid = $enc_pmt_preview;
$pat_paid = abs($enc_pat_bal_preview < 0 ? $enc_pat_bal_preview : 0);

// Activity totals
$ins_pmt_amt = 0.0;
$ins_adj_amt = 0.0;
$pat_pmt_amt = 0.0;
$adj_total   = 0.0;
$billing_adj_amt = 0.0;
$billing_wo_amt  = 0.0;

// ============================================================
// FETCH PAYMENT / ADJUSTMENT BREAKDOWN
// ============================================================

$ins_pmt_amt = 0.0;
$ins_adj_amt = 0.0;
$writeoff_amt = 0.0;
$writeoff_rows = [];
$pat_pmt_amt = 0.0;
$adj_total   = 0.0;

if ($enc && $pid) {

    $credits_for_pills = GetAllCredits($enc, $pid);

    foreach ($credits_for_pills as $cp) {

        $is_ins = !empty($cp['payer_id']);

        $pay_amt = (float)($cp['pay_amount'] ?? 0);
        $adj_amt = abs((float)($cp['adj_amount'] ?? 0));

        $memo_raw = strtolower(trim(
            ($cp['memo'] ?? '') . ' ' .
            ($cp['description'] ?? '') . ' ' .
            ($cp['reference'] ?? '') . ' ' .
            ($cp['account_code'] ?? '') . ' ' .
            ($cp['payment_method'] ?? '') . ' ' .
            ($cp['adjustment_code'] ?? '')
        ));
        
        $is_writeoff =
            strpos($memo_raw, 'write') !== false ||
            strpos($memo_raw, 'writeoff') !== false ||
            strpos($memo_raw, 'bad_debt') !== false ||
            strpos($memo_raw, 'bad debt') !== false ||
            strpos($memo_raw, 'contract') !== false ||
            strpos($memo_raw, 'wo') !== false;

      // ====================================================
// INSURANCE
// ====================================================

if ($is_ins) {

    if ($pay_amt > 0) {
        $ins_pmt_amt += $pay_amt;
    }

    if ($adj_amt > 0) {

        $acct_code = strtoupper(
            trim((string)($cp['account_code'] ?? ''))
        );

        if ($acct_code === 'WO') {

            $writeoff_amt += $adj_amt;

            $writeoff_rows[] = [
                'memo' => $memo_raw,
                'amt'  => $adj_amt
            ];

        } else {

            $ins_adj_amt += $adj_amt;
        }

        $adj_total += $adj_amt;
    }

// ====================================================
// PATIENT
// ====================================================

} else {

    if ($pay_amt > 0) {
        $pat_pmt_amt += $pay_amt;
    }

    if ($adj_amt > 0) {

        $acct_code = strtoupper(
            trim((string)($cp['account_code'] ?? ''))
        );

        if ($acct_code === 'WO') {

            $writeoff_amt += $adj_amt;

            $writeoff_rows[] = [
                'memo' => $memo_raw,
                'amt'  => $adj_amt
            ];

        } else {

            $ins_adj_amt += $adj_amt;
        }

        $adj_total += $adj_amt;
    }
}
    }
}

// ============================================================
// TRUE ENCOUNTER BALANCE
// ============================================================

$balance =
    (float)$enc_chg_preview
    - $ins_pmt_amt
    - $pat_pmt_amt
    - $adj_total;

// ============================================================
// STATUS LOGIC
// ============================================================

if (
    $enc_chg_preview > 0
    && abs($balance) <= 0.005
) {

    $status_cls = 'enc-status-paid';
    $status_lbl = xl('Paid In Full');

} elseif (

    ($ins_pmt_amt + $pat_pmt_amt + $adj_total) > 0
    && $balance > 0.005

) {

    $status_cls = 'enc-status-partial';
    $status_lbl = xl('Partially Paid');

} elseif ($balance > 0.005) {

    $status_cls = 'enc-status-due';
    $status_lbl = xl('Balance Due');

} elseif ($balance < -0.005) {

    $status_cls = 'enc-status-partial';
    $status_lbl = xl('Overpaid');

} else {

    $status_cls = 'enc-status-due';
    $status_lbl = xl('Balance Due');
}

// ============================================================
// COLOR HELPERS
// ============================================================

$ins_paid_clr =
    ($ins_pmt_amt > 0)
    ? ' green'
    : '';

$bal_clr =
    ($balance > 0.005)
    ? ' red'
    : (($balance < -0.005)
        ? ' green'
        : '');

// ============================================================
// BUILD PAYMENT / ACTIVITY PILLS
// ============================================================

$total_adj_pill =
    $ins_adj_amt + $billing_adj_amt;

$total_wo_pill =
    $writeoff_amt + $billing_wo_amt;

$pmt_group_html = '';

if (
    $ins_pmt_amt > 0
    || $total_adj_pill > 0
    || $total_wo_pill > 0
    || $pat_pmt_amt > 0
) {

    $pills_row = "<div class='enc-pmt-pills'>";

    // ========================================================
    // INSURANCE PAYMENT
    // ========================================================

    if ($ins_pmt_amt > 0) {

        $pills_row .=
            "<span class='enc-pmt-pill pill-ins'>
                <span class='ppp-dot dot-ins'></span>
                INS $" . oeFormatMoney($ins_pmt_amt) . "
            </span>";
    }

    // ========================================================
    // STANDARD ADJUSTMENTS
    // ========================================================

    if ($total_adj_pill > 0) {

        $pills_row .=
            "<span class='enc-pmt-pill pill-adj'>
                <span class='ppp-dot dot-adj'></span>
                ADJ $" . oeFormatMoney($total_adj_pill) . "
            </span>";
    }

    // ========================================================
    // WRITEOFFS
    // ========================================================

    if ($total_wo_pill > 0) {

        $pills_row .=
            "<span class='enc-pmt-pill pill-wo'>
                <span class='ppp-dot dot-wo'></span>
                WO $" . oeFormatMoney($total_wo_pill) . "
            </span>";
    }

    // ========================================================
    // PATIENT PAYMENTS
    // ========================================================

    if ($pat_pmt_amt > 0) {

        $pills_row .=
            "<span class='enc-pmt-pill pill-pat'>
                <span class='ppp-dot dot-pat'></span>
                PAT $" . oeFormatMoney($pat_pmt_amt) . "
            </span>";
    }

    $pills_row .= "</div>";
 // ========================================================
// DETAIL LINE
// ========================================================

$detail_line = '';

if ($enc && $pid) {

    // ====================================================
    // BILLING ADJUSTMENTS / WRITEOFFS
    // ====================================================

    $bres = sqlStatement(
        "SELECT fee, code_text
           FROM billing
          WHERE encounter = ?
            AND pid = ?
            AND activity = 1
            AND fee < 0",
        [$enc, $pid]
    );

    while ($brow = sqlFetchArray($bres)) {

        $fee_amt = abs((float)$brow['fee']);
        $txt     = strtolower($brow['code_text'] ?? '');

        $is_wo =
            strpos($txt, 'write') !== false ||
            strpos($txt, 'writeoff') !== false ||
            strpos($txt, 'bad_debt') !== false ||
            strpos($txt, 'bad debt') !== false ||
            strpos($txt, 'contract') !== false;

        if ($is_wo) {

            $billing_wo_amt += $fee_amt;

        } else {

            $billing_adj_amt += $fee_amt;
        }

        $adj_total += $fee_amt;
    }

    // ====================================================
    // CREDIT DETAIL LINE
    // ====================================================

    $det_credits = GetAllCredits($enc, $pid);

    $seen_sess = [];
    $det_parts = [];

    foreach ($det_credits as $dc) {

        $sid = $dc['session_id'] ?? 0;

        if ($sid && in_array($sid, $seen_sess)) {
            continue;
        }

        if ($sid) {
            $seen_sess[] = $sid;
        }

        $era_payer = $dc['name'] ?? '';
        $era_ref   = $dc['reference'] ?? '';

        if (
            !$era_payer
            && empty($dc['payer_id'])
        ) {
            $era_payer = xl('Patient');
        }

        $p = [];

        if ($era_payer) {
            $p[] = $era_payer;
        }

        if ($era_ref) {
            $p[] = 'Check/EFT: ' . $era_ref;
        }

        if ($p) {
            $det_parts[] =
                implode(' &bull; ', $p);
        }
    }

    if ($det_parts) {

        $detail_line =
            "<div class='enc-pmt-detail-inline'>"
            . implode(' &nbsp;·&nbsp; ', $det_parts)
            . "</div>";
    }
}

    $pmt_group_html =
        $pills_row . $detail_line;
}
     else {
        $pmt_group_html = "<div class='enc-pmt-group'><div class='enc-pmt-pills enc-no-pmt'><svg width='11' height='11' fill='none' stroke='currentColor' stroke-width='1.5' viewBox='0 0 24 24'><rect x='2' y='5' width='20' height='14' rx='2'/><line x1='2' y1='10' x2='22' y2='10'/></svg> <span>" . xlt('No Payments') . "</span></div></div>";
    }
    $pmt_pills_html = $pmt_group_html; // kept for compatibility

    // Header HTML
    $hdr_id = 'enc-hdr-' . (int)$enc;
    echo "<div class='enc-block' id='enc-block-" . (int)$enc . "' data-enc='" . (int)$enc . "'>";
    echo "<div class='enc-header' id='" . $hdr_id . "' onclick='encToggle(this)'>";
    echo "  <div class='enc-header-left'>";
    echo "    <div class='enc-cal-icon'>";
    echo "      <svg width='15' height='15' fill='none' stroke='var(--purple)' stroke-width='2' viewBox='0 0 24 24'><rect x='3' y='4' width='18' height='18' rx='2'/><line x1='16' y1='2' x2='16' y2='6'/><line x1='8' y1='2' x2='8' y2='6'/><line x1='3' y1='10' x2='21' y2='10'/></svg>";
    echo "    </div>";
    echo "    <div>";
    echo "      <div class='enc-title'>" . xlt('Svc Date') . ": <strong>" . text($svcDate) . "</strong> &nbsp;|&nbsp; " . xlt('Enc') . " " . $encLink . "</div>";
    echo "      <div class='enc-sub'>" . text($subtitle) . "</div>";
    echo "    </div>";
    echo "  </div>";
    echo "  <div class='enc-header-right'>";
    echo $pmt_group_html;
    echo "    <span class='enc-status-pill " . attr($status_cls) . "'>" . $status_lbl . "</span>";
    echo "    <div class='enc-stat'><div class='enc-stat-lbl'>" . xlt('Charge') . "</div><div class='enc-stat-val'>$" . text(oeFormatMoney($enc_chg_preview)) . "</div></div>";
    echo "    <div class='enc-stat'><div class='enc-stat-lbl'>" . xlt('Ins Paid') . "</div><div class='enc-stat-val" . $ins_paid_clr . "'>$" . text(oeFormatMoney($ins_pmt_amt)) . "</div></div>";
    echo "    <div class='enc-stat'><div class='enc-stat-lbl'>" . xlt('Pat Paid') . "</div><div class='enc-stat-val'>$" . text(oeFormatMoney($pat_pmt_amt)) . "</div></div>";
    echo "    <div class='enc-stat'><div class='enc-stat-lbl'>" . xlt('Balance') . "</div><div class='enc-stat-val" . $bal_clr . "'>$" . text(oeFormatMoney($balance)) . "</div></div>";
    echo "    <span class='enc-chev'>&#8964;</span>";
    echo "  </div>";
    echo "</div>"; // .enc-header
    echo "<div class='enc-body'>";
    echo "  <div class='enc-body-top'>";
    echo "    <div class='enc-timeline-section'>";
    echo "      <div class='enc-timeline-hdr'>";
    echo "        <span class='enc-timeline-hdr-icon'><svg width='12' height='12' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><circle cx='12' cy='12' r='10'/><polyline points='12 6 12 12 16 14'/></svg></span>";
    echo "        " . xlt('Financial Activity Timeline');
    echo "        <span style='font-size:9px;font-weight:400;color:var(--text3);margin-left:6px;'>&#x2139;</span>";
    echo "      </div>";
    echo "      <table class='enc-timeline-table'><thead><tr>";
    echo "        <th style='width:110px;'>" . xlt('Date') . "</th>";
    echo "        <th>" . xlt('Type') . "</th>";
    echo "        <th>" . xlt('Description') . "</th>";
    echo "        <th>" . xlt('Reference') . "</th>";
    echo "        <th class='r'>" . xlt('Charge') . "</th>";
    echo "        <th class='r'>" . xlt('Payment/Adj') . "</th>";
    echo "        <th class='r'>" . xlt('Balance') . "</th>";
    echo "      </tr></thead><tbody id='enc-charge-rows-" . (int)$enc . "'>";
    // Charge row injected by charge loop below
    // Full activity rows (payments, adj, etc.) added in PrintCreditDetail

    $GLOBALS['current_hdr_enc']   = (int)$enc;
    $GLOBALS['current_hdr_pid']   = (int)$pid;
    $GLOBALS['charge_col_closed'] = false;
    $GLOBALS['tl_running_bal']    = 0.0;
    $orow++;
}
function PrintEncChargeClose()
{
    if (!empty($GLOBALS['charge_col_closed'])) return;
    $GLOBALS['charge_col_closed'] = true;
    // Leave tbody open — payment rows will be appended as additional tr rows
}
function PrintEncFooter()
{
    PrintEncChargeClose();

    $pid    = (int)($GLOBALS['current_hdr_pid'] ?? 0);
    $pmtUrl = htmlspecialchars($GLOBALS['webroot'] . '/interface/patient_file/mdi_payments_ledger.php?pid=' . $pid, ENT_QUOTES);

    // Close timeline table
    echo "      </tbody></table>";
    echo "    </div>"; // .enc-timeline-section

   

// ─────────────────────────────
// Quick Actions
// ─────────────────────────────
echo "<div class='enc-actions-column'>";

echo "<div class='enc-summary-card'>";

echo "<div class='enc-summary-title'>"
    . xlt('Quick Actions')
    . "</div>";

echo "<div class='enc-summary-actions'>";

echo "<button class='enc-side-btn enc-side-btn--purple' onclick=\"top.restoreSession(); dlgopen('" . $pmtUrl . "&action=payment','',1100,720,'','" . xla('Add Payment') . "',{allowResize:true,allowDrag:false,type:'iframe'}); return false;\">"
    . xlt('Add Payment')
    . "</button>";

echo "<button class='enc-side-btn enc-side-btn--purple' onclick=\"top.restoreSession(); dlgopen('" . $pmtUrl . "&action=adjustment','',1100,720,'','" . xla('Adjustment / Writeoff') . "',{allowResize:true,allowDrag:false,type:'iframe'}); return false;\">"
    . xlt('Adjustment / Writeoff')
    . "</button>";

echo "<button class='enc-side-btn enc-side-btn--purple' onclick=\"top.restoreSession(); dlgopen('" . $pmtUrl . "&action=transfer','',1100,720,'','" . xla('Transfer') . "',{allowResize:true,allowDrag:false,type:'iframe'}); return false;\">"
    . xlt('Transfer')
    . "</button>";

echo "<button class='enc-side-btn enc-side-btn--purple' onclick=\"top.restoreSession(); dlgopen('" . $pmtUrl . "&action=refund','',1100,720,'','" . xla('Refund') . "',{allowResize:true,allowDrag:false,type:'iframe'}); return false;\">"
    . xlt('Refund')
    . "</button>";

echo "</div>";

echo "</div>"; // enc-summary-card

echo "</div>"; // enc-actions-column

  

    echo "  </div>"; // .enc-body-top

    // Flush MBO bottom panel
    if (!empty($GLOBALS['pending_mbo_bottom'])) {
        echo $GLOBALS['pending_mbo_bottom'];
        $GLOBALS['pending_mbo_bottom'] = '';
    }
    echo "  </div><!-- /enc-body -->";
    echo "</div><!-- /enc-block -->\n";
    $GLOBALS['charge_col_closed'] = false;
}
function ClosePaymentTable() {
    // Payment rows are now cards (divs), no table to close
    // This function is kept for compatibility but does nothing
}
function CloseLedgerPaymentTable() {
    global $ledger_pmt_hdr_enc;
    // Just reset the flag — PrintEncFooter handles all div closing and MBO flushing
    $ledger_pmt_hdr_enc = -1;
}
function PrintCreditDetail($detail, $pat, $unassigned = false)
{
    global $enc_pmt, $total_pmt, $enc_adj, $total_adj, $enc_bal, $total_bal;
    global $enc_ins_bal, $enc_pat_bal, $total_ins_bal, $total_pat_bal;
    global $bgcolor, $orow, $enc_units, $enc_chg;
    global $patient_has_insurance;

    // ── Pre-pass: group insurance ERA rows by payer_id into summary blocks.
    // Patient rows (payer_id=NULL, including copay sessions) are NEVER grouped —
    // they always render as individual plain rows so PAT badge / PAT PAID show correctly.
    $era_groups   = [];  // gkey => [ rows ]
    $render_queue = [];  // ordered list of items to render

    if (!$unassigned) {
        foreach ($detail as $pmt) {
            $method  = List_Look($pmt['payment_method'], 'payment_method');
            $ref     = $pmt['reference'] ?? '';
            $pid_ins = $pmt['payer_id']  ?? null;   // NULL for patient sessions incl. copay

            $is_electronic = (stripos($method, 'electronic') !== false
                           || stripos($method, 'ePay')       !== false
                           || stripos($ref,    'ePay')       !== false);

            // Only group if BOTH electronic AND has an insurance payer_id
            // Group by session_id so each ERA check/remit gets its own card
            if ($is_electronic && !empty($pid_ins)) {
                $gkey = 'ins_' . ($pmt['session_id'] ?? $pid_ins);
                if (!isset($era_groups[$gkey])) {
                    $era_groups[$gkey] = [];
                    $render_queue[] = ['type' => 'era', 'gkey' => $gkey];
                }
                $era_groups[$gkey][] = $pmt;
            } else {
                // Patient payment, copay, manual entry, or any non-ERA row → plain
                $render_queue[] = ['type' => 'plain', 'pmt' => $pmt];
            }
        }
    } else {
        foreach ($detail as $pmt) {
            $render_queue[] = ['type' => 'plain', 'pmt' => $pmt];
        }
    }

    foreach ($render_queue as $item) {

        // ════════════════════════════════════════════════════════════════════
        // ERA SUMMARY ROW (insurance-only)
        // ════════════════════════════════════════════════════════════════════
        if ($item['type'] === 'era') {
            $rows       = $era_groups[$item['gkey']];
            $first      = $rows[0];
            $mbo_enc_id_local = (int)($first['encounter'] ?? 0);
            $payer_name = ($first['name'] ?? '') ?: xl('Insurance');
            $ref_raw    = $first['reference'] ?? '';
            // Clean ref: strip bracketed duplicates AND "ePay - " prefix
            $era_ref = trim(preg_replace('/\s*\[.*?\]/', '', $ref_raw));
            $era_ref = preg_replace('/^e[Pp]ay\s*[-–]\s*/i', '', $era_ref);
            $era_ref_short = $era_ref ? substr($era_ref, -10) : '';
            // Use check_date from ar_session; fall back to post_time
            $pmt_date    = !empty($first['check_date'])
                ? substr($first['check_date'], 0, 10)
                : substr($first['post_time'], 0, 10);

            $era_ins_paid = 0.0;
            $era_patient_resp = 0.0;
            //$era_pat_paid = 0.0;   // ✅ ADD THIS LINE
            $era_adjs = [];

            foreach ($rows as $r) {

                $rpmt = (float)($r['pay_amount'] ?? 0);
                $radj = (float)($r['adj_amount'] ?? 0);

                $memo_raw = strtolower($r['memo'] ?? '');

                // Detect writeoff rows stored as payments
                $is_writeoff_payment =
                    $rpmt > 0 &&
                    (
                        strpos($memo_raw, 'writeoff') !== false ||
                        strpos($memo_raw, 'write off') !== false ||
                        strpos($memo_raw, 'contractual') !== false
                    );

                if ($is_writeoff_payment) {
                    $radj += $rpmt;
                    $rpmt = 0;
                }
                $memo_raw = strtolower($r['memo'] ?? '');
            
                if (
                    strpos($memo_raw, 'copay') !== false ||
                    strpos($memo_raw, 'deductible') !== false ||
                    strpos($memo_raw, 'coinsurance') !== false ||
                    strpos($memo_raw, 'ins1') !== false ||
                    preg_match('/\bpr\b/i', $memo_raw)
                ) {
                
                    // Patient responsibility from ERA
                    // transfers balance from insurance -> patient
                
                    if (preg_match('/(\d+\.\d{2})/', $memo_raw, $m)) {
                        $pr_total = (float)$m[1];
                    } else {
                        $pr_total = abs($rpmt) + abs($radj);
                    }
                
                    $era_patient_resp += $pr_total;
                
                    // REMOVE from insurance balance
                    $enc_ins_bal   -= $pr_total;
                    $total_ins_bal -= $pr_total;
                
                    // ADD to patient balance
                    $enc_pat_bal   += $pr_total;
                    $total_pat_bal += $pr_total;
                
                } else {
                    $era_ins_paid += $rpmt;
            
                    $enc_ins_bal   -= ($rpmt + $radj);
                    $total_ins_bal -= ($rpmt + $radj);
                }
            
                if ($radj != 0) {
                    $code_key = 'ADJ';
                    if (preg_match('/adjust\s+code\s+(\S+)/i', $memo_raw, $mc)) {
                        $code_key = $mc[1];
                    }
                    $era_adjs[$code_key] = ($era_adjs[$code_key] ?? 0) + $radj;
                }
            
                $enc_pmt   += $rpmt;
                $total_pmt += $rpmt;
                $enc_adj   += $radj;
                $total_adj += $radj;
            
                $enc_bal   -= ($rpmt + $radj);
                $total_bal -= ($rpmt + $radj);
            }
               

            // Build detail lines
            $detail_lines = [];

            // 🟢 Insurance Paid
            if ($era_ins_paid > 0) {
                $detail_lines[] =
                    "<span style='display:inline-flex;gap:6px;align-items:baseline;'>"
                    . "<span style='color:#6b7280;font-size:11px;min-width:140px;'>Insurance Paid:</span>"
                    . "<span style='color:var(--green);font-weight:600;'>$" . text(oeFormatMoney($era_ins_paid)) . "</span>"
                    . "</span>";
            }
         
            // 🟠 Adjustments
            foreach ($era_adjs as $code => $amt) {
                $detail_lines[] =
                    "<span style='display:inline-flex;gap:6px;align-items:baseline;'>"
                    . "<span style='color:#6b7280;font-size:11px;min-width:140px;'>Adjustment (" . text($code) . "):</span>"
                    . "<span style='color:var(--amber);'>-$" . text(oeFormatMoney(abs($amt))) . "</span>"
                    . "</span>";
            }

            // 🟡 Patient Responsibility
            if ($era_patient_resp > 0) {
                $detail_lines[] =
                    "<span style='display:inline-flex;gap:6px;align-items:baseline;'>"
                    . "<span style='color:#6b7280;font-size:11px;min-width:140px;'>Patient Responsibility:</span>"
                    . "<span style='color:#b45309;font-weight:600;'>$" . text(oeFormatMoney($era_patient_resp)) . "</span>"
                    . "</span>";
            }

            $ins_bal_color = $enc_ins_bal > 0 ? '#1e40af' : 'var(--green)';
            $pat_bal_color = $enc_pat_bal > 0 ? '#b45309' : 'var(--green)';
            $ins_badge = "<span style='display:inline-block;font-size:10px;font-weight:700;"
                       . "padding:1px 7px;border-radius:10px;background:#dbeafe;color:#1e40af;'>INS</span>";

            // ── ERA PAYMENT ROW — timeline format ────────────────────────────
            global $ledger_pmt_hdr_enc;
            $enc_id_era = (int)($first['encounter'] ?? 0);
            if (($ledger_pmt_hdr_enc ?? -1) !== $enc_id_era) {
                PrintEncChargeClose();
                $ledger_pmt_hdr_enc = $enc_id_era;
            }

            // Update running balance
            $GLOBALS['tl_running_bal'] = ($GLOBALS['tl_running_bal'] ?? 0) - $era_ins_paid;
            foreach ($era_adjs as $adj_v) { $GLOBALS['tl_running_bal'] -= abs($adj_v); }
            $tl_bal     = $GLOBALS['tl_running_bal'];
            $tl_bal_cls = $tl_bal > 0.005 ? 'tl-bal-pos' : 'tl-bal-zero';
            $tl_pmt_disp = '$' . oeFormatMoney($era_ins_paid);
            if (!empty($era_adjs)) {
                $tl_pmt_disp .= ' <span style="color:var(--amber);font-size:10px;"> / ADJ -$' . oeFormatMoney(array_sum(array_map('abs', $era_adjs))) . '</span>';
            }

            echo "<tr>";
            echo "<td class='mono' style='font-size:11px;color:var(--text2);'>" . text($pmt_date) . "<div style='font-size:9.5px;color:var(--text3);'>" . text($pmt_dt_obj->format('g:i A')) . "</div></td>";
            echo "<td><span class='tl-type-lbl'><span class='tl-type-dot tl-type-payment'></span>" . xlt('Payment') . "</span></td>";
            echo "<td>";
            echo "  <div style='font-weight:600;font-size:12px;'>Insurance Payment</div>";
            echo "  <div style='color:var(--text2);font-size:11px;'>" . text($payer_name) . "</div>";
            echo "</td>";
            echo "<td style='font-family:\"DM Mono\",monospace;font-size:11px;color:var(--text2);'>" . text($era_ref_short ?: '—') . "</td>";
            echo "<td class='r' style='color:var(--text3);'>&mdash;</td>";
            echo "<td class='r tl-pmt-amt'>$" . text(oeFormatMoney($era_ins_paid)) . (!empty($era_adjs) ? "<div style='color:var(--amber);font-size:10px;'>ADJ -$" . text(oeFormatMoney(array_sum(array_map('abs', $era_adjs)))) . "</div>" : "") . "</td>";
            echo "<td class='r " . $tl_bal_cls . "'>$" . text(oeFormatMoney(max(0, $tl_bal))) . "</td>";
            echo "</tr>";
       // ─────────────────────────────────────────────
// SEPARATE ADJUSTMENT / WRITEOFF ROWS
// ─────────────────────────────────────────────

foreach ($era_adjs as $code => $amt) {

    // -----------------------------------------
    // Determine adjustment label
    // -----------------------------------------

    $adj_label = 'Adjustment';

    $acct_code = strtoupper(
        trim((string)($pmt['account_code'] ?? ''))
    );

    $memo_text = strtolower(
        trim((string)($memo ?? ''))
    );

    $search_blob =
        $acct_code . ' ' . $memo_text;

    if (

        $acct_code === 'WO'
        || strpos($search_blob, 'writeoff') !== false
        || strpos($search_blob, 'write off') !== false
        || strpos($search_blob, 'bad_debt') !== false
        || strpos($search_blob, 'bad debt') !== false

    ) {

        $adj_label = 'Writeoff';

    }
    elseif (

        $acct_code === 'AJ'
        || strpos($search_blob, 'contract') !== false

    ) {

        $adj_label = 'Contractual Adjustment';
    }

    // -----------------------------------------
    // Running balance
    // -----------------------------------------

    $GLOBALS['tl_running_bal'] =
        ($GLOBALS['tl_running_bal'] ?? 0)
        - abs($amt);

    $tl_adj_bal = $GLOBALS['tl_running_bal'];

    // -----------------------------------------
    // Row output
    // -----------------------------------------

    echo "<tr>";

    echo "<td class='mono'
            style='font-size:11px;color:var(--text2);'>"
            . text($pmt_date) .
        "</td>";

    echo "<td>
            <span class='tl-type-lbl'>
            <span class='tl-type-dot "
            . (
                $adj_label === 'Writeoff'
                    ? 'tl-type-writeoff'
                    : (
                        $adj_label === 'Contractual Adjustment'
                            ? 'tl-type-contract'
                            : 'tl-type-adjustment'
                    )
            )
            . "'></span>"
                . text($adj_label) .
            "</span>
        </td>";

    echo "<td>";

    echo "<div style='font-weight:600;font-size:12px;'>"
        . text($adj_label)
        . "</div>";

    echo "<div style='color:var(--text2);font-size:11px;'>"
        . text($code)
        . "</div>";

    echo "</td>";

    echo "<td style='font-family:monospace;'>"
        . text($era_ref_short ?: '—')
        . "</td>";

    echo "<td class='r'>&mdash;</td>";

    echo "<td class='r'
            style='color:var(--amber);font-weight:700;'>"
            . "-$" . text(oeFormatMoney(abs($amt))) .
        "</td>";

    echo "<td class='r "
        . ($tl_adj_bal > 0 ? 'tl-bal-pos' : 'tl-bal-zero')
        . "'>$" . text(oeFormatMoney(max(0, $tl_adj_bal)))
        . "</td>";

    echo "</tr>";
}

$orow++;
continue;
        }

        // ════════════════════════════════════════════════════════════════════
        // PLAIN ROW — patient payments, copays, manual entries, unassigned
        // (original per-row logic, unchanged)
        // ════════════════════════════════════════════════════════════════════
        $pmt = $item['pmt'];
        $uap_flag = false;
        if ($unassigned) {
            if (($pmt['pay_total'] - $pmt['applied']) == 0) {
                if (!$GLOBALS['show_payment_history']) {
                    continue;
                }
                $uap_flag = true;
            }
        }

        $bgcolor = (($bgcolor == "#FFFFDD") ? "#FFDDDD" : "#FFFFDD");
        $rowType = $unassigned ? 'uac' : 'payment';
        $method = List_Look($pmt['payment_method'], 'payment_method');
        $desc = $pmt['description'];
        $ref = $pmt['reference'];
        if ($unassigned) {
            $memo = List_Look($pmt['adjustment_code'], 'payment_adjustment_code');
        } else {
            $memo = $pmt['memo'];
        }

        $description = $method;
        if ($ref) {
            if ($description) { $description .= ' - '; }
            $description .= $ref;
        }
        if ($desc) {
            if ($description) { $description .= ': '; }
            $description .= $desc;
        }
        if ($memo) {
            if ($description) { $description .= ' '; }
            $description .= '[' . $memo . ']';
        }
        if ($uap_flag === true) {
            if ($description) { $description .= ' '; }
            $description .= '{Pay History}';
        }

        // payer_type=0 → patient/copay; otherwise insurance
        $is_insurance = $unassigned
            ? !empty($pmt['payer_id'])
            : (isset($pmt['payer_type']) ? (int)$pmt['payer_type'] !== 0 : !empty($pmt['payer_id']));
        $type_badge = $is_insurance
            ? "<span style='display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;background:#dbeafe;color:#1e40af;'>INS</span>"
            : "<span style='display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;background:#fef3c7;color:#92400e;'>PAT</span>";

        // Clean up description for patient electronic rows (copay etc.)
        if (!$is_insurance && !$unassigned) {
            if (preg_match('/copay[:\s]+([\d.]+)/i', $memo, $cm)) {
                $description = 'Copay: $' . number_format((float)$cm[1], 2);
            } elseif (stripos($method, 'electronic') !== false || stripos($method, 'ePay') !== false) {
                $description = xl('Electronic Payment');
            }
        }

        $formatted_description = "<span style='color:var(--text2);'>" . text($description) . "</span>";

        $payer = ($pmt['name'] == '') ? xl('Patient') : $pmt['name'];
        // Use check_date from ar_session; fall back to post_time / post_to_date
        if ($unassigned) {
            $pmt_date = !empty($pmt['check_date'])
                ? substr($pmt['check_date'], 0, 10)
                : substr($pmt['post_to_date'], 0, 10);
        } else {
            $pmt_date = !empty($pmt['check_date'])
                ? substr($pmt['check_date'], 0, 10)
                : substr($pmt['post_time'], 0, 10);
        }

        if ($unassigned) {
            $pmt_amt   = $pmt['pay_total'] - $pmt['applied'];
            $uac_bal   = $pmt_amt * -1;
            $uac_appl  = $pmt['applied'];
            $uac_total = $pmt['pay_total'];
            $pmt_amt   = $pmt['pay_total'];
            $total_pmt = $total_pmt - $uac_bal;
        } else {
            $uac_total = '';
            $uac_bal   = '';
            $uac_appl  = '';
            $pmt_amt   = $pmt['pay_amount'];
            $adj_amt   = $pmt['adj_amount'];
            $enc_pmt   = $enc_pmt  + $pmt['pay_amount'];
            $total_pmt = $total_pmt + $pmt['pay_amount'];
            $enc_adj   = $enc_adj  + $pmt['adj_amount'];
            $total_adj = $total_adj + $pmt['adj_amount'];
        }

        $row_pmt = $unassigned ? 0 : (float)($pmt['pay_amount'] ?? 0);
        $row_adj = $unassigned ? 0 : (float)($pmt['adj_amount'] ?? 0);
        $is_adjustment_only = (
            abs($row_adj) > 0.009 &&
            abs($row_pmt) < 0.009
        );
        if (!$unassigned) {
            if ($is_insurance) {
                $enc_ins_bal   -= ($row_pmt + $row_adj);
                $total_ins_bal -= ($row_pmt + $row_adj);
            } else {
                $enc_pat_bal   -= $row_pmt;
                $total_pat_bal -= $row_pmt;
                $enc_ins_bal   -= $row_adj;
                $total_ins_bal -= $row_adj;
            }
        }

        $ins_bal_color = $enc_ins_bal > 0 ? '#1e40af' : 'var(--green)';
        $pat_bal_color = $enc_pat_bal > 0 ? '#b45309' : 'var(--green)';
        $ins_paid_disp = ($is_insurance  && $row_pmt != 0) ? oeFormatMoney($row_pmt) : '';
        $pat_paid_disp = (!$is_insurance && $row_pmt != 0) ? oeFormatMoney($row_pmt) : '';
        $row_bg = $is_insurance ? '#e8f0fe' : '#fefce8';

        // Determine MBO details for patient payment rows
        $plain_mbo_type = $is_insurance ? xl('Insurance Payment') : xl('Patient Payment');
        $plain_mbo_method = $method;
        $plain_mbo_ref = $ref ?: '—';
        $plain_is_copay = (!$is_insurance && preg_match('/copay/i', $memo));

        // ── PLAIN PAYMENT CARD ──────────────────────────────────────────────
        global $ledger_pmt_hdr_enc;
        $enc_for_pmt = !$unassigned ? (int)($pmt['encounter'] ?? 0) : 0;
        if (($ledger_pmt_hdr_enc ?? -1) !== $enc_for_pmt && !$unassigned) {
            PrintEncChargeClose();
            $ledger_pmt_hdr_enc = $enc_for_pmt;
        }
        if ($unassigned) {
            // UAC rows — plain table row (handled in UAC section)
            $print  = "<tr style='background:var(--amber-l);'>";
            $print .= "<td class='mono'>" . text($pmt_date) . "</td>";
            $print .= "<td>" . $type_badge . " " . xlt('Unapplied') . "</td>";
            $print .= "<td style='font-weight:600;'>" . text($payer) . "</td>";
            $print .= "<td style='color:var(--text2);font-size:11px;'>" . text($description) . "</td>";
            $uac_remain = (float)($pmt['pay_total'] ?? 0) - (float)($pmt['applied'] ?? 0);
            $print .= "<td class='r' style='color:var(--green);font-weight:700;'>\$" . text(oeFormatMoney((float)($pmt['pay_total'] ?? 0))) . "</td>";
            $print .= "<td class='r' style='color:var(--text2);'>\$" . text(oeFormatMoney((float)($pmt['applied'] ?? 0))) . "</td>";
            $print .= "<td class='r' style='color:var(--amber);font-weight:700;'>\$" . text(oeFormatMoney($uac_remain)) . "</td>";
            $print .= "<td class='r'>&mdash;</td>";
            $print .= "</tr>";
            echo $print;
        } else {
            // ── PLAIN PAYMENT ROW — timeline format ──────────────────────────
            $pmt_dt_obj = new DateTime($pmt_date ?: date('Y-m-d'));
            $tl_pmt_val = abs($row_pmt);
            $tl_adj_val = abs($row_adj);
            $GLOBALS['tl_running_bal'] = ($GLOBALS['tl_running_bal'] ?? 0) - $tl_pmt_val - $tl_adj_val;
            $tl_bal     = $GLOBALS['tl_running_bal'];
            $tl_bal_cls = $tl_bal > 0.005 ? 'tl-bal-pos' : 'tl-bal-zero';

            if ($is_adjustment_only) {

                $adj_label = 'Adjustment';

                $acct_code = strtoupper(
                    trim((string)($pmt['account_code'] ?? ''))
                );

                $memo_blob = strtolower(
                    ($pmt['memo'] ?? '') . ' ' .
                    ($pmt['reference'] ?? '') . ' ' .
                    ($pmt['description'] ?? '')
                );
                
                if (
                    $acct_code === 'WO'
                    || $acct_code === 'BAD_DEBT'
                    || strpos($memo_blob, 'bad_debt') !== false
                    || strpos($memo_blob, 'bad debt') !== false
                    || strpos($memo_blob, 'writeoff') !== false
                    || strpos($memo_blob, 'write off') !== false
                ) {
                
                    $adj_label = 'Writeoff';
                
                }
                elseif (
                    $acct_code === 'AJ'
                    || strpos($acct_code, 'CONTRACT') !== false
                    || strpos($memo_blob, 'contract') !== false
                ) {
                
                    $adj_label = 'Contractual Adjustment';
                }
            
                $GLOBALS['tl_running_bal'] =
                    ($GLOBALS['tl_running_bal'] ?? 0) - abs($row_adj);
            
                $tl_bal = $GLOBALS['tl_running_bal'];
            
                echo "<tr>";
            
                echo "<td class='mono' style='font-size:11px;color:var(--text2);'>"
                    . text($pmt_date)
                    . "</td>";
            
                $plain_dot_cls = ($adj_label === 'Writeoff')
                    ? 'tl-type-writeoff'
                    : (($adj_label === 'Contractual Adjustment')
                        ? 'tl-type-contract'
                        : 'tl-type-adjustment');

                echo "<td>
                        <span class='tl-type-lbl'>
                            <span class='tl-type-dot " . $plain_dot_cls . "'></span>"
                            . text($adj_label) .
                        "</span>
                      </td>";
            
                echo "<td>";
            
                echo "<div style='font-weight:600;font-size:12px;'>"
                    . text($adj_label)
                    . "</div>";
            
                if ($memo) {
                    echo "<div style='color:var(--text2);font-size:11px;'>"
                        . text($memo)
                        . "</div>";
                }
            
                echo "</td>";
            
                echo "<td style='font-family:monospace;'>"
                    . text($ref ?: '—')
                    . "</td>";
            
                echo "<td class='r'>&mdash;</td>";
            
                echo "<td class='r' style='color:var(--amber);font-weight:700;'>"
                    . "-$" . text(oeFormatMoney(abs($row_adj)))
                    . "</td>";
            
                echo "<td class='r " .
                    ($tl_bal > 0 ? 'tl-bal-pos' : 'tl-bal-zero')
                    . "'>$" . text(oeFormatMoney(max(0, $tl_bal)))
                    . "</td>";
            
                echo "</tr>";
            
                continue;
            }

            $tl_type_dot = $is_insurance ? 'tl-type-payment' : 'tl-type-payment';
            $tl_type_lbl = $is_insurance ? xlt('Payment') : xlt('Payment');
            $tl_payer    = $is_insurance ? xl('Insurance Payment') : xl('Patient Payment');

            echo "<tr>";
            echo "<td class='mono' style='font-size:11px;color:var(--text2);'>" . text($pmt_date) . "<div style='font-size:9.5px;color:var(--text3);'>" . text($pmt_dt_obj->format('g:i A')) . "</div></td>";
            echo "<td><span class='tl-type-lbl'><span class='tl-type-dot " . ($is_insurance ? 'tl-type-payment' : 'tl-type-payment') . "'></span>" . text($is_insurance ? xl('Insurance') : xl('Patient')) . "</span></td>";
            echo "<td>";
            echo "  <div style='font-weight:600;font-size:12px;'>" . text($is_insurance ? xl('Insurance Payment') : xl('Patient Payment')) . "</div>";
            echo "  <div style='color:var(--text2);font-size:11px;'>" . text($payer) . "</div>";
            if ($description) echo "  <div style='color:var(--text3);font-size:10.5px;'>" . text($description) . "</div>";
            if ($row_adj != 0) echo "  <div style='color:var(--amber);font-size:10.5px;'>ADJ -$" . text(oeFormatMoney(abs($row_adj))) . "</div>";
            echo "</td>";
            // Reference
            $tl_ref = $ref ?: ($row_pmt > 0 ? '' : '');
            echo "<td style='font-family:\"DM Mono\",monospace;font-size:11px;color:var(--text2);'>" . text($tl_ref ?: '—') . "</td>";
            // Charge col — dash
            echo "<td class='r' style='color:var(--text3);'>&mdash;</td>";
            // Payment/Adj
            $tl_pmt_str = $row_pmt != 0 ? '$' . text(oeFormatMoney(abs($row_pmt))) : '';
            echo "<td class='r tl-pmt-amt'>" . $tl_pmt_str . ($row_adj != 0 ? "<div style='color:var(--amber);font-size:10px;'>ADJ -$" . text(oeFormatMoney(abs($row_adj))) . "</div>" : "") . "</td>";
            // Balance
            echo "<td class='r " . $tl_bal_cls . "'>$" . text(oeFormatMoney(max(0, $tl_bal))) . "</td>";
            echo "</tr>";
        }

        if (!empty($pmt['follow_up_note'])) {
            $print  = "<tr style='background:var(--surface2);font-style:italic;'>";
            $print .= "<td colspan='9' style='padding:4px 10px;color:var(--text2);font-size:11px;'>"
                   . xlt('Follow Up Note') . ": " . text($pmt['follow_up_note']) . "</td></tr>";
            echo $print;
        }

        if ($unassigned) {
            $total_bal = $total_bal + $uac_bal;
        } else {
            $enc_bal   = $enc_bal   - $pmt_amt - $adj_amt;
            $total_bal = $total_bal - $pmt_amt - $adj_amt;
        }

        $orow++;
    }

    $bgcolor = (($bgcolor == "#FFFFDD") ? "#FFDDDD" : "#FFFFDD");
}


if (!isset($_REQUEST['form_facility'])) {
    $_REQUEST['form_facility'] = '';
}

if (!isset($_REQUEST['form_provider'])) {
    $_REQUEST['form_provider'] = '';
}

if ($type_form == '0') {
    if (!isset($_REQUEST['form_patient'])) {
        $_REQUEST['form_patient'] = '';
    }

    if (!isset($_REQUEST['form_pid'])) {
        $_REQUEST['form_pid'] = '';
    }
} else {
    if (!isset($_REQUEST['form_patient'])) {
        $_REQUEST['form_patient'] = $pat_pid;
    }

    if (!isset($_REQUEST['form_pid'])) {
        $_REQUEST['form_pid'] = $pat_pid;
    }
}

if (!isset($_REQUEST['form_csvexport'])) {
    $_REQUEST['form_csvexport'] = '';
}

if (!isset($_REQUEST['form_refresh'])) {
    // For patient context (type_form=1), auto-load on first GET — no JS submit needed
    $_REQUEST['form_refresh'] = ($type_form == '1') ? true : '';
}

if (!isset($_REQUEST['$form_dob'])) {
    $_REQUEST['$form_dob'] = '';
}

$prefix = strtoupper(substr($GLOBALS['ledger_begin_date'], 0, 1));
$ledger_time = (int) substr($GLOBALS['ledger_begin_date'], 1);

if ($ledger_time <= 0) {
    $ledger_time = 1; // fallback safety
}

if ($prefix == 'Y') {
    $last_year = mktime(0, 0, 0, date('m'), date('d'), date('Y') - $ledger_time);

} elseif ($prefix == 'M') {
    $last_year = mktime(0, 0, 0, date('m') - $ledger_time, date('d'), date('Y'));

} elseif ($prefix == 'D') {
    $last_year = mktime(0, 0, 0, date('m'), date('d') - $ledger_time, date('Y'));

} else {
    // fallback if config is bad
    $last_year = strtotime('-1 year');
}

// Default to last 90 days
$form_from_date = date('Y-m-d', strtotime('-90 days')); // 90-day default
if (!empty($_REQUEST['form_from_date'])) {
    $raw = $_REQUEST['form_from_date'];
    $form_from_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : DateToYYYYMMDD($raw);
}

$form_to_date = date('Y-m-d');
if (!empty($_REQUEST['form_to_date'])) {
    $raw = $_REQUEST['form_to_date'];
    $form_to_date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : DateToYYYYMMDD($raw);
}
$form_facility  = $_REQUEST['form_facility'];
$form_provider  = $_REQUEST['form_provider'];
$form_patient   = $_REQUEST['form_patient'];
$form_pid       = $_REQUEST['form_pid'];
$form_dob       = $_REQUEST['form_dob'] ?? null;

if ($_REQUEST['form_csvexport']) {
    header("Pragma: public");
    header("Expires: 0");
    header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
    header("Content-Type: application/force-download");
    header("Content-Disposition: attachment; filename=svc_financial_report_" . attr($form_from_date) . "--" . attr($form_to_date) . ".csv");
    header("Content-Description: File Transfer");
} else {
    ?>
<html>
<head>

    <title><?php echo xlt('Patient Ledger by Date'); ?></title>
    <meta name="csrf" content="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">

    <?php
    // ── MBO inline-save handler ──────────────────────────────────────────
    if (!empty($_GET['mbo_save'])) {
        header('Content-Type: application/json');
        $mbo = json_decode(file_get_contents('php://input'), true);
        $mbo_enc = (int)($mbo['enc'] ?? 0);
        $mbo_pid = (int)($mbo['pid'] ?? 0);
        $ok = false;
        if ($mbo_enc && $mbo_pid && CsrfUtils::verifyCsrfToken($mbo['csrf_token_form'] ?? '')) {

            // Save encounter reason/notes
            $note = trim($mbo['enc_note'] ?? '');
            if ($note !== '') {
                sqlStatement("UPDATE form_encounter SET reason=? WHERE encounter=? AND pid=?",
                    [$note, $mbo_enc, $mbo_pid]);
            }
            // Save billing_note → Additional Notes field in MBO / form_encounter
            if (isset($mbo['billing_note'])) {
                $billing_note = trim($mbo['billing_note']);
                sqlStatement("UPDATE form_encounter SET billing_note=? WHERE encounter=? AND pid=?",
                    [$billing_note, $mbo_enc, $mbo_pid]);
            }

            // Save modifier on the CPT billing row
            $billing_id = (int)($mbo['billing_id'] ?? 0);
            if (isset($mbo['modifier']) && $billing_id > 0) {
                // Modifier is colon-separated (e.g. '25:59') — allow colons, strip everything else
                $mod = strtoupper(trim(preg_replace('/[^A-Za-z0-9:]/', '', $mbo['modifier'])));
                $mod = implode(':', array_filter(array_map('trim', explode(':', $mod))));
                sqlStatement("UPDATE billing SET modifier=? WHERE id=? AND pid=? AND encounter=?",
                    [$mod, $billing_id, $mbo_pid, $mbo_enc]);
            }

            // Save Dx codes — data-dx-code is "ICD10:M542" or "ICD9:M542" format from lists
            if (isset($mbo['dx_codes']) && is_array($mbo['dx_codes'])) {
                // Deactivate existing ICD dx rows for this encounter
                sqlStatement(
                    "UPDATE billing SET activity=0 WHERE encounter=? AND pid=? AND code_type IN ('ICD10','ICD9','ICD9CM') AND activity=1",
                    [$mbo_enc, $mbo_pid]
                );
                foreach ($mbo['dx_codes'] as $dx) {
                    // code comes in as "ICD10:M542" — split into type + code
                    $raw_code  = trim($dx['code'] ?? '');
                    if (!$raw_code) continue;
                    $parts     = explode(':', $raw_code);
                    if (count($parts) >= 2) {
                        $dx_type  = strtoupper($parts[0]);  // ICD10
                        $dx_code  = $parts[1];              // M542
                    } else {
                        $dx_type  = 'ICD10';
                        $dx_code  = $raw_code;
                    }
                    $dx_text = trim($dx['code_text'] ?? $dx['dx_text'] ?? '');
                    // Try to reactivate existing row first
                    $existing = sqlQuery(
                        "SELECT id FROM billing WHERE encounter=? AND pid=? AND code=? AND code_type=? LIMIT 1",
                        [$mbo_enc, $mbo_pid, $dx_code, $dx_type]
                    );
                    if ($existing) {
                        sqlStatement("UPDATE billing SET activity=1, code_text=? WHERE id=?",
                            [$dx_text, $existing['id']]);
                    } else {
                        sqlStatement(
                            "INSERT INTO billing (date,code_type,code,code_text,pid,encounter,provider_id,billed,activity,fee,units)
                             VALUES (NOW(),?,?,?,?,?,0,0,1,0,1)",
                            [$dx_type, $dx_code, $dx_text, $mbo_pid, $mbo_enc]
                        );
                    }
                }
            }

            $ok = true;
        }
        echo json_encode(['ok' => $ok]);
        exit;
    }

    // ── Charge line inline-save handler ─────────────────────────────────
    if (!empty($_GET['charge_save'])) {
        header('Content-Type: application/json');
        $ch     = json_decode(file_get_contents('php://input'), true);
        $ch_bid = (int)($ch['billing_id'] ?? 0);
        $ch_pid = (int)($ch['pid'] ?? 0);
        $ok = false;
        if ($ch_bid && $ch_pid && CsrfUtils::verifyCsrfToken($ch['csrf_token_form'] ?? '')) {
            $ch_code  = strtoupper(trim(preg_replace('/[^A-Za-z0-9]/', '', $ch['code'] ?? '')));
            $ch_desc  = trim($ch['code_text'] ?? '');
            $ch_fee   = round((float)($ch['fee']   ?? 0), 2);
            $ch_units = max(1, (int)($ch['units']  ?? 1));
            if ($ch_code) {
                sqlStatement(
                    "UPDATE billing SET code=?, code_text=?, fee=?, units=? WHERE id=? AND pid=? AND activity=1",
                    [$ch_code, $ch_desc, $ch_fee, $ch_units, $ch_bid, $ch_pid]
                );
                $ok = true;
            }
        }
        echo json_encode(['ok' => $ok]);
        exit;
    }
    // ── End charge save handler ───────────────────────────────────────────
    ?>

    <?php Header::setupHeader('datetime-picker'); ?>

    <script>
        var pt_name;
        var pt_id;
        // ── Modifier tag helpers ──────────────────────────────────────
        function mboSyncModHidden(tagContainer) {
            var tags = tagContainer.querySelectorAll('.mbo-mod-tag');
            var vals = Array.from(tags).map(function(t) {
                return t.childNodes[0].textContent.trim();
            });
            var hidden = tagContainer.querySelector('.mbo-mod-value');
            if (hidden) hidden.value = vals.join(':');
            // Show/hide add-wrap based on count
            var addWrap = tagContainer.querySelector('.mbo-mod-add-wrap');
            if (addWrap) addWrap.style.display = vals.length >= 4 ? 'none' : '';
        }

        function mboAddMod(input) {
            var val = input.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
            if (!val) return;
            var tagContainer = input.closest('.mbo-mod-tags');
            var existing = tagContainer.querySelectorAll('.mbo-mod-tag');
            if (existing.length >= 4) return;
            // No duplicates
            var already = Array.from(existing).some(function(t) {
                return t.childNodes[0].textContent.trim() === val;
            });
            if (already) { input.value = ''; return; }
            // Build tag
            var tag = document.createElement('span');
            tag.className = 'mbo-mod-tag';
            tag.appendChild(document.createTextNode(val));
            var rm = document.createElement('button');
            rm.type = 'button';
            rm.className = 'mbo-mod-tag-remove';
            rm.innerHTML = '&#215;';
            rm.title = 'Remove';
            rm.onclick = function() { mboRemoveMod(rm); };
            tag.appendChild(rm);
            // Insert before the add-wrap span
            var addWrap = tagContainer.querySelector('.mbo-mod-add-wrap');
            tagContainer.insertBefore(tag, addWrap);
            input.value = '';
            mboSyncModHidden(tagContainer);
        }

        function mboRemoveMod(btn) {
            var tag = btn.closest('.mbo-mod-tag');
            var tagContainer = tag.closest('.mbo-mod-tags');
            tag.remove();
            mboSyncModHidden(tagContainer);
        }


        function checkSubmit() {
            var frm = document.getElementById('theform');
            if (frm && frm.elements['form_patient']) {
                var pat = frm.elements['form_patient'].value;
                if (!pat || pat == 0) {
                    alert(<?php echo xlj('A Patient Must Be Selected to Generate This Report') ?>);
                    return false;
                }
            }
            var r = document.getElementById('form_refresh');
            if (r) r.value = 'true';
            var c = document.getElementById('form_csvexport');
            if (c) c.value = '';
            top.restoreSession();
            // Use getElementById — NOT forms[0] which may be OpenEMR's session
            // form, causing type_form to be lost and filter to switch to report mode.
            document.getElementById('theform').submit();
        }

        // ── Date range preset dropdown ─────────────────────────────────────────
        function mdiFormatDate(d) {
            var mm = String(d.getMonth() + 1).padStart(2, '0');
            var dd = String(d.getDate()).padStart(2, '0');
            return d.getFullYear() + '-' + mm + '-' + dd;
        }
        function mdiRangeChange(sel) {
            var val       = sel.value;
            var today     = new Date();
            var fromDate  = new Date();
            var customDiv = document.getElementById('mdi_custom_dates');
            if (val === 'custom') {
                customDiv.classList.add('visible');
                return; // wait for Apply button
            }
            customDiv.classList.remove('visible');
            if      (val === 'current_month') { fromDate = new Date(today.getFullYear(), today.getMonth(), 1); }
            else if (val === 'last_30')        { fromDate = new Date(today); fromDate.setDate(today.getDate() - 30); }
            else if (val === 'last_90')        { fromDate = new Date(today); fromDate.setDate(today.getDate() - 90); }
            else if (val === 'ytd')            { fromDate = new Date(today.getFullYear(), 0, 1); }
            else if (val === 'all')            { fromDate = new Date('2000-01-01'); }
            document.getElementById('form_from_date').value = mdiFormatDate(fromDate);
            document.getElementById('form_to_date').value   = mdiFormatDate(today);
            checkSubmit();
        }
        function mdiApplyCustomRange() {
            var from = document.getElementById('mdi_custom_from').value;
            var to   = document.getElementById('mdi_custom_to').value;
            if (!from || !to) { alert('<?php echo xlt("Please select both dates."); ?>'); return; }
            if (from > to)    { alert('<?php echo xlt("From date must be before To date."); ?>'); return; }
            document.getElementById('form_from_date').value = from;
            document.getElementById('form_to_date').value   = to;
            checkSubmit();
        }
        function setpatient(pid, lname, fname, dob) {
          var frm = document.getElementById('theform');
          frm.elements['form_patient'].value = lname + ', ' + fname;
          frm.elements['form_pid'].value = pid;
          frm.elements['form_dob'].value = dob;
        }
        function sel_patient() {
            dlgopen('../main/calendar/find_patient_popup.php?pflag=0', '_blank', 500, 400);
        }

        // ── Helper: rows belonging to an encounter header ────────────────
        // ── Enc-block toggle (matches mdi_payments_ledger pattern) ──────────
        // ── Encounter card toggle ─────────────────────────────────────────
        function encToggle(hdr) {
            var block = hdr.parentElement;
            var body  = block.querySelector('.enc-body');
            var chev  = hdr.querySelector('.enc-chev');
            if (!body) return;
            var open = body.classList.toggle('open');
            hdr.classList.toggle('open', open);
            if (chev) chev.classList.toggle('open', open);
        }
        function mdiExpandAll() {
            document.querySelectorAll('.enc-block').forEach(function(block) {
                var body = block.querySelector('.enc-body');
                var hdr  = block.querySelector('.enc-header');
                var chev = block.querySelector('.enc-chev');
                if (body) body.classList.add('open');
                if (hdr)  hdr.classList.add('open');
                if (chev) chev.classList.add('open');
            });
        }
        function mdiCollapseAll() {
            document.querySelectorAll('.enc-block').forEach(function(block) {
                var body = block.querySelector('.enc-body');
                var hdr  = block.querySelector('.enc-header');
                var chev = block.querySelector('.enc-chev');
                if (body) body.classList.remove('open');
                if (hdr)  hdr.classList.remove('open');
                if (chev) chev.classList.remove('open');
            });
        }
        function mdiLedgerFilter(query) {
            query = (query || '').toLowerCase().trim();

            const encBlocks = document.querySelectorAll('.enc-block');

            encBlocks.forEach(function(block) {
                let matchFound = false;

                // Search inside ALL rows of this encounter
                const rows = block.querySelectorAll('tbody tr');

                rows.forEach(function(row) {
                    const text = row.innerText.toLowerCase();

                    if (!query || text.includes(query)) {
                        row.style.display = '';
                        matchFound = true;
                    } else {
                        row.style.display = 'none';
                    }
                });

                // Show/hide entire encounter if nothing matches
                block.style.display = matchFound ? '' : 'none';
            });
        }
        // ── View filter (Billed / Payments / All) ────────────────────────
        function mdiSetView(view, btn) {
            document.querySelectorAll('.mdi-view-tab[data-view]').forEach(function(b) {
                b.classList.remove('active');
            });
            btn.classList.add('active');

            document.querySelectorAll('.enc-block').forEach(function(block) {
                if (view === 'all') { block.style.display = ''; return; }

                // Check which section labels exist in this block
                var labels = Array.from(block.querySelectorAll('.enc-section-lbl'))
                                  .map(function(l){ return l.textContent.trim().toUpperCase(); });
                var hasBilling  = labels.some(function(l){ return l.includes('CHARGE'); });
                var hasPayments = labels.some(function(l){ return l.includes('PAYMENT'); });

                if (view === 'billed')   block.style.display = hasBilling  ? '' : 'none';
                if (view === 'payments') block.style.display = hasPayments ? '' : 'none';
            });
        }

        // ── MBO Panel helpers ────────────────────────────────────────────
        function mboToggle(el) {
            // nextElementSibling of the header is the body div
            var body = el.nextElementSibling;
            if (!body || !body.classList.contains('mbo-section-body')) return;
            var chev = el.querySelector('.mbo-chevron');
            var isOpen = body.classList.contains('open');
            body.classList.toggle('open', !isOpen);
            if (chev) chev.classList.toggle('open', !isOpen);
        }

        function mboGetMeta(k) {
            var m = document.querySelector('meta[name="'+k+'"]');
            return m ? m.content : '';
        }

        // ── Dx Code inline modal (mirrors demographics.php) ────────────
        window._mboActiveDxPanel = null;
        var _mboIcdTimer = null, _mboIcdSelected = -1;

        function mboOpenDxPopup(btn) {
            window._mboActiveDxPanel = btn.closest('.mbo-panel') || btn.closest('.mbo-bottom');
            // Pre-populate modal with existing codes from the tag list
            var list = window._mboActiveDxPanel.querySelector('.mbo-dx-list');
            var area = document.getElementById('mbo-diag-codes-area');
            area.innerHTML = '';
            if (list) {
                list.querySelectorAll('[data-dx-code]').forEach(function(tag) {
                    var code  = tag.dataset.dxCode;
                    var label = tag.dataset.dxText || '';
                    mboAppendCodeTag(code, label);
                });
            }
            // Clear search
            document.getElementById('mbo-icd-search-input').value = '';
            var dd = document.getElementById('mbo-icd-dropdown');
            dd.style.display = 'none'; dd.innerHTML = '';
            // Show modal
            document.getElementById('mbo-diag-overlay').style.display = 'flex';
        }

        function mboCloseDiagModal() {
            document.getElementById('mbo-diag-overlay').style.display = 'none';
            window._mboActiveDxPanel = null;
        }

        // ── Edit MBO popup — opens HCFA-1500 misc billing options ────
        function mboOpenMboPopup(btn, encId, pid) {
            // URL is pre-built in PHP and stored as a data attribute to avoid quote conflicts
            var url = btn.dataset.mboUrl;
            top.restoreSession();
            dlgopen(url, 'mbo_edit_' + encId, 900, 650, '', 'Miscellaneous Billing Options', {
                allowResize: true,
                allowDrag:   true,
                dialogId:    'mbo_edit_dlg',
                type:        'iframe'
            });
        }

        function mboConfirmDxCodes() {
            if (!window._mboActiveDxPanel) { mboCloseDiagModal(); return; }
            var list = window._mboActiveDxPanel.querySelector('.mbo-dx-list');
            if (!list) { mboCloseDiagModal(); return; }
            // Clear existing tags and rebuild from modal selection
            list.innerHTML = '';
            document.querySelectorAll('#mbo-diag-codes-area [data-code]').forEach(function(el) {
                var code  = el.dataset.code;
                var parts = code.replace('ICD10:', '').replace('ICD9:', '');
                var label = el.querySelector('span') ? el.querySelector('span').textContent.replace(code + ' — ', '') : '';
                var tag = document.createElement('div');
                tag.className = 'mbo-dx-tag';
                tag.dataset.dxCode     = code;
                tag.dataset.dxText     = label;
                tag.dataset.dxCodeType = code.indexOf('ICD9') === 0 ? 'ICD9' : 'ICD10';
                tag.innerHTML =
                    '<span class="mbo-dx-code">' + parts + '</span>' +
                    (label ? '<span class="mbo-dx-desc">' + label + '</span>' : '') +
                    '<button type="button" class="mbo-dx-remove" onclick="this.parentElement.remove()" title="Remove">&#10005;</button>';
                list.appendChild(tag);
            });
            mboCloseDiagModal();
        }

        function mboAppendCodeTag(codeVal, label) {
            var area = document.getElementById('mbo-diag-codes-area');
            var existing = Array.from(area.querySelectorAll('[data-code]')).map(function(e){ return e.dataset.code; });
            if (existing.indexOf(codeVal) !== -1) return;
            var tag = document.createElement('div');
            tag.dataset.code = codeVal;
            tag.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#eef2ff;'
                + 'border:1px solid #c7d2fe;border-radius:20px;padding:4px 10px;font-size:12px;'
                + 'color:#1a2540;font-family:monospace;margin:2px;width:fit-content;';
            var displayText = label ? codeVal + ' \u2014 ' + label : codeVal;
            tag.innerHTML = '<span>' + displayText + '</span>'
                + '<button type="button" onclick="this.parentElement.remove()" '
                + 'style="background:none;border:none;color:#7c3aed;font-size:14px;cursor:pointer;'
                + 'padding:0;line-height:1;font-weight:700;" title="Remove">\xd7</button>';
            area.appendChild(tag);
        }

        function mboIcdSearch(term) {
            clearTimeout(_mboIcdTimer);
            var dd = document.getElementById('mbo-icd-dropdown');
            if (!term || term.length < 2) { dd.style.display = 'none'; dd.innerHTML = ''; return; }
            _mboIcdTimer = setTimeout(function() {
                dd.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#8a9bb5;">Searching\u2026</div>';
                dd.style.display = 'block';
                top.restoreSession && top.restoreSession();
                var csrf = mboGetMeta('csrf');
                var url = '<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_icd_search.php'
                    + '?term=' + encodeURIComponent(term) + '&limit=25&csrf_token_form=' + encodeURIComponent(csrf);
                fetch(url, { credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(data) {
                        _mboIcdSelected = -1;
                        if (!data || !data.length) { dd.style.display='none'; dd.innerHTML=''; return; }
                        var html = '';
                        data.forEach(function(item, idx) {
                            var code  = item.code || '';
                            var label = item.code_text || item.label || '';
                            var full  = code.indexOf('ICD10:') === 0 ? code : 'ICD10:' + code;
                            html += '<div class="mbo-icd-item" data-code="' + full + '" data-label="' + label.replace(/"/g,'&quot;') + '"'
                                + ' onclick="mboIcdSelect(this)" onmouseenter="mboIcdHover(this)"'
                                + ' style="padding:8px 12px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f2f7;display:flex;gap:8px;align-items:baseline;">'
                                + '<span style="font-family:monospace;color:#5b21b6;font-weight:600;white-space:nowrap;">' + full + '</span>'
                                + '<span style="color:#1a2540;flex:1;">' + label + '</span></div>';
                        });
                        dd.innerHTML = html; dd.style.display = 'block';
                    }).catch(function(){ dd.style.display='none'; });
            }, 300);
        }

        function mboIcdSelect(el) {
            mboAppendCodeTag(el.dataset.code, el.dataset.label);
            document.getElementById('mbo-icd-search-input').value = '';
            var dd = document.getElementById('mbo-icd-dropdown');
            dd.style.display = 'none'; dd.innerHTML = ''; _mboIcdSelected = -1;
        }

        function mboIcdHover(el) {
            document.querySelectorAll('.mbo-icd-item').forEach(function(i, idx){
                i.style.background = i === el ? '#f5f3ff' : '';
                if (i === el) _mboIcdSelected = idx;
            });
        }

        function mboIcdKeydown(e) {
            var dd = document.getElementById('mbo-icd-dropdown');
            var items = Array.from(dd.querySelectorAll('.mbo-icd-item'));
            if (!items.length) return;
            if (e.key==='ArrowDown'){e.preventDefault();_mboIcdSelected=Math.min(_mboIcdSelected+1,items.length-1);}
            else if(e.key==='ArrowUp'){e.preventDefault();_mboIcdSelected=Math.max(_mboIcdSelected-1,0);}
            else if(e.key==='Enter'&&_mboIcdSelected>=0){e.preventDefault();mboIcdSelect(items[_mboIcdSelected]);return;}
            else if(e.key==='Escape'){dd.style.display='none';dd.innerHTML='';_mboIcdSelected=-1;return;}
            items.forEach(function(i,idx){i.style.background=idx===_mboIcdSelected?'#f5f3ff':'';});
            if(items[_mboIcdSelected]) items[_mboIcdSelected].scrollIntoView({block:'nearest'});
        }

        function mboIcdClear() {
            document.getElementById('mbo-icd-search-input').value = '';
            var dd = document.getElementById('mbo-icd-dropdown');
            dd.style.display = 'none'; dd.innerHTML = ''; _mboIcdSelected = -1;
        }

        document.addEventListener('click', function(e) {
            var wrap = document.getElementById('mbo-icd-search-wrap');
            var dd   = document.getElementById('mbo-icd-dropdown');
            if (wrap && dd && !wrap.contains(e.target)) dd.style.display = 'none';
        });

        // ── Extended mboSave — also collects dx tags and billing_id ────
        function mboSave(btn, encId, pid, billingId) {
            var panel = btn.closest('.mbo-panel') || btn.closest('.mbo-bottom');
            var data  = { csrf_token_form: mboGetMeta('csrf'), enc: encId, pid: pid };
            if (billingId) data.billing_id = billingId;

            // Text/textarea fields
            panel.querySelectorAll('[data-mbo-field]').forEach(function(inp) {
                data[inp.dataset.mboField] = inp.value;
            });

            // Dx codes from tag list — send code + dx_text for save handler
            var dxList = panel.querySelector('.mbo-dx-list');
            if (dxList) {
                data.dx_codes = [];
                dxList.querySelectorAll('[data-dx-code]').forEach(function(tag) {
                    data.dx_codes.push({
                        code:      tag.dataset.dxCode,
                        code_text: tag.dataset.dxText  || '',
                        dx_text:   tag.dataset.dxText  || '',
                        code_type: tag.dataset.dxCodeType || 'ICD10'
                    });
                });
            }

            btn.classList.add('saving');
            btn.innerHTML = '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-dasharray="32" stroke-dashoffset="32"><animate attributeName="stroke-dashoffset" dur=".6s" values="32;0" repeatCount="indefinite"/></circle></svg> Saving…';
            fetch(window.location.href.split('?')[0] + '?mbo_save=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            }).then(function(r) { return r.json(); })
              .then(function(res) {
                btn.classList.remove('saving');
                btn.innerHTML = '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Saved';
                setTimeout(function(){ btn.innerHTML = '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Save'; }, 2500);
                var ok = panel.querySelector('.mbo-save-ok');
                if (ok) { ok.style.display='block'; setTimeout(function(){ok.style.display='none';},2500); }
              }).catch(function() {
                btn.classList.remove('saving');
                btn.innerHTML = 'Save';
                alert('Save failed. Please try again.');
              });
        }

        // ── Editable service line ─────────────────────────────────────────────
        function chargeEditOpen(btn) {
            var row   = btn.closest('tr');
            var editRow = row.nextElementSibling;
            if (!editRow || !editRow.classList.contains('charge-row-edit')) return;
            editRow.classList.add('open');
            var first = editRow.querySelector('.charge-edit-inp');
            if (first) first.focus();
        }
        function chargeEditClose(btn) {
            var editRow = btn.closest('.charge-row-edit');
            if (editRow) editRow.classList.remove('open');
        }
        function chargeEditSave(btn, billingId, pid) {
            var editRow = btn.closest('.charge-row-edit');
            var dataRow = editRow ? editRow.previousElementSibling : null;
            if (!editRow || !billingId) return;

            var data = {
                csrf_token_form: mboGetMeta('csrf'),
                billing_id: billingId,
                pid: pid
            };
            editRow.querySelectorAll('[data-ce-field]').forEach(function(inp) {
                data[inp.dataset.ceField] = inp.value;
            });

            btn.disabled = true;
            btn.textContent = '...';

            fetch(window.location.href.split('?')[0] + '?charge_save=1', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            }).then(function(r) { return r.json(); })
              .then(function(res) {
                btn.disabled = false;
                btn.innerHTML = '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?php echo xlt("Save"); ?>';
                if (res.ok) {
                    // Update the visible charge row with new values
                    if (dataRow) {
                        var codeCell = dataRow.querySelector('td:first-child');
                        var descCell = dataRow.querySelector('td:nth-child(2)');
                        var feeCell  = dataRow.querySelector('td:nth-child(3)');
                        var unitCell = dataRow.querySelector('td:nth-child(4)');
                        if (codeCell) {
                            // Keep modifier pills, update code text node
                            var codeText = dataRow.querySelector('td:first-child');
                            codeText.firstChild.textContent = data.code;
                        }
                        if (descCell) descCell.firstChild.textContent = data.code_text;
                        if (feeCell)  feeCell.textContent  = '$' + parseFloat(data.fee).toFixed(2);
                        if (unitCell) unitCell.textContent = data.units;
                    }
                    var ok = editRow.querySelector('.charge-edit-ok');
                    if (ok) { ok.style.display='inline'; setTimeout(function(){ ok.style.display='none'; editRow.classList.remove('open'); }, 1500); }
                } else {
                    alert('<?php echo xlt("Save failed. Please try again."); ?>');
                }
              }).catch(function() {
                btn.disabled = false;
                btn.textContent = '<?php echo xlt("Save"); ?>';
                alert('<?php echo xlt("Save failed. Please try again."); ?>');
              });
        }

        // ── Ledger section (UAC / Prepayments) toggle ──────────────
        function ledgerSectionToggle(hdr) {
            var body = hdr.nextElementSibling;
            var chev = hdr.querySelector('.ls-chev');
            if (!body) return;
            var isOpen = body.classList.contains('open');
            body.classList.toggle('open', !isOpen);
            if (chev) chev.classList.toggle('open', !isOpen);
        }

        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('mdi-ready');
        });

        // ── MBO Notes toggle ─────────────────────────────────────────────────
        function mboToggleNotes(btn) {
            var svcInfo = btn.closest('.mbo-bottom-svcinfo');
            if (!svcInfo) return;
            var wrap   = svcInfo.querySelector('.mbo-notes-wrap');
            if (!wrap) return;
            var isOpen = wrap.style.display !== 'none';
            wrap.style.display = isOpen ? 'none' : 'block';
            btn.classList.toggle('active', !isOpen);
            if (!isOpen) {
                var ta = wrap.querySelector('.mbo-notes-textarea');
                if (ta) { ta.focus(); ta.select(); }
            }
        }

        // ── mboBottomReset: clear modifier tags + dx list ────────────
        function mboBottomReset(btn, encId, billingId) {
            var bottom = btn.closest('.mbo-bottom');
            if (!bottom) return;
            var modTags = bottom.querySelector('.mbo-mod-tags');
            if (modTags) {
                Array.from(modTags.querySelectorAll('.mbo-mod-tag')).forEach(function(t){ t.remove(); });
                var hid = modTags.querySelector('.mbo-mod-value');
                if (hid) hid.value = '';
                var aw = modTags.querySelector('.mbo-mod-add-wrap');
                if (aw) aw.style.display = '';
            }
            var dxList = bottom.querySelector('.mbo-dx-list');
            if (dxList) dxList.innerHTML = '';
        }
    </script>
    <script>
    // ── Donut chart + Quick Filters ──────────────────────────────────────────
    (function() {
        function mdiInitDashboard() {
            var blocks = document.querySelectorAll('.enc-block');
            var paid = 0, partial = 0, due = 0;
            blocks.forEach(function(b) {
                var pill = b.querySelector('.enc-status-pill');
                if (!pill) return;
                if (pill.classList.contains('enc-status-paid'))    paid++;
                else if (pill.classList.contains('enc-status-partial')) partial++;
                else due++;
            });
            var total = paid + partial + due;

            // Update quick filter counts
            var setCount = function(id, n) { var el = document.getElementById(id); if (el) el.textContent = n; };
            setCount('qf-all',     total);
            setCount('qf-paid',    paid);
            setCount('qf-partial', partial);
            setCount('qf-due',     due);

            // Draw donut
            var canvas = document.getElementById('mdi-donut-canvas');
            if (!canvas || !canvas.getContext) return;
            var ctx = canvas.getContext('2d');
            var W = canvas.width, H = canvas.height, cx = W/2, cy = H/2, r = 36, ir = 22;
            ctx.clearRect(0,0,W,H);
            var segments = [
                { count:paid,    color:'#0ca678', label:'Paid in Full' },
                { count:partial, color:'#e67700', label:'Partially Paid' },
                { count:due,     color:'#e03131', label:'Balance Due' }
            ];
            var start = -Math.PI/2;
            segments.forEach(function(seg) {
                if (!seg.count) return;
                var sweep = (seg.count / (total||1)) * 2 * Math.PI;
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.arc(cx, cy, r, start, start + sweep);
                ctx.closePath();
                ctx.fillStyle = seg.color;
                ctx.fill();
                start += sweep;
            });
            // Donut hole
            ctx.beginPath();
            ctx.arc(cx, cy, ir, 0, 2*Math.PI);
            ctx.fillStyle = '#fff';
            ctx.fill();

            // Legend
            var leg = document.getElementById('mdi-donut-legend');
            if (!leg) return;
            leg.innerHTML = '';
            segments.forEach(function(seg) {
                if (!total) return;
                var pct = Math.round((seg.count/(total||1))*100);
                var item = document.createElement('div');
                item.className = 'mdi-donut-leg-item';
                item.innerHTML = '<span class="mdi-donut-dot" style="background:'+seg.color+'"></span>'
                    + '<span>'+seg.label+'</span>'
                    + '<span class="mdi-donut-leg-pct">'+seg.count+' ('+pct+'%)</span>';
                leg.appendChild(item);
            });
        }

        // Run after page content loads
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', mdiInitDashboard);
        } else {
            setTimeout(mdiInitDashboard, 100);
        }
    })();

    function mdiQuickFilter(filter, btn) {
        // Update active button
        document.querySelectorAll('.mdi-qf-btn').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        // Filter encounter blocks
        document.querySelectorAll('.enc-block').forEach(function(block) {
            if (filter === 'all') { block.style.display = ''; return; }
            var pill = block.querySelector('.enc-status-pill');
            if (!pill) { block.style.display = 'none'; return; }
            var match = false;
            if (filter === 'paid'    && pill.classList.contains('enc-status-paid'))    match = true;
            if (filter === 'partial' && pill.classList.contains('enc-status-partial')) match = true;
            if (filter === 'due'     && pill.classList.contains('enc-status-due'))     match = true;
            block.style.display = match ? '' : 'none';
        });
    }
    </script>
    <?php // Auto-submit removed: form_refresh is set in PHP on first load, no JS reload needed ?>

    <style>
        /* specifically include & exclude from printing */
        @media print {
            #report_parameters {
                visibility: hidden;
                display: none;
            }
            #report_parameters_daterange {
                visibility: visible;
                display: inline;
            }
            #report_results {
                margin-top: 30px;
            }
            #report_header {
                visibility: visible;
                display: inline;
            }
            #title {
                visibility: hidden;
                display: none;
            }
        }
        /* specifically exclude some from the screen */
        @media screen {
            #report_parameters_daterange {
                visibility: hidden;
                display: none;
            }
            #report_header {
                visibility: hidden;
                display: none;
            }
            #title {
                visibility: visible;
                display: inline;
            }
        }

        #report_results table {
        table-layout: fixed;
        width: 100%;
        }

        #report_results td:nth-child(2),
        #report_results th:nth-child(2) {
            white-space: normal !important;
            word-break: break-word;
        }

        #report_results td,
        #report_results th {
            white-space: normal !important;
        }

    /* ── Dashboard summary row ──────────────────────────────────────────── */
    .mdi-dash-row {
        display:grid;
        grid-template-columns:minmax(260px,1fr) minmax(220px,1fr) 160px 200px;
        gap:12px;
        padding:14px 16px 10px;
        background:#fff;
        border-bottom:1px solid var(--border);
    }
    @media(max-width:960px){ .mdi-dash-row{ grid-template-columns:1fr 1fr; } }
    @media(max-width:600px){ .mdi-dash-row{ grid-template-columns:1fr; } }
    .mdi-dash-card {
        background:#fff;
        border:1px solid var(--border);
        border-radius:10px;
        padding:12px 14px;
    }
    .mdi-dash-card-title {
        font-size:9.5px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.08em;
        color:var(--text3);
        margin-bottom:10px;
    }
    .mdi-dash-card-sub { font-weight:600; font-size:9px; color:var(--text3); }
    .mdi-dash-balance-grid {
        display:flex;
        gap:14px;
        flex-wrap:wrap;
    }
    .mdi-dash-stat { display:flex; flex-direction:column; gap:2px; }
    .mdi-dash-stat-val {
        font-family:'DM Mono',monospace;
        font-size:17px;
        font-weight:800;
        color:var(--text);
        line-height:1.1;
    }
    .mdi-dash-stat-val.red-val   { color:var(--red); }
    .mdi-dash-stat-val.green-val { color:var(--green); }
    .mdi-dash-stat-val.blue-val  { color:#1d4ed8; }
    .mdi-dash-stat-lbl { font-size:10px; font-weight:600; color:var(--text3); }
    /* Donut */
    .mdi-dash-donut-wrap { display:flex; align-items:center; gap:10px; }
    .mdi-dash-donut-legend { display:flex; flex-direction:column; gap:4px; font-size:10.5px; }
    .mdi-donut-leg-item { display:flex; align-items:center; gap:5px; color:var(--text2); }
    .mdi-donut-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
    .mdi-donut-leg-pct { font-weight:700; margin-left:auto; padding-left:8px; }
    /* Quick filters */
    .mdi-dash-qf-wrap { display:flex; flex-direction:column; gap:6px; }
    .mdi-qf-btn {
        display:flex;
        align-items:center;
        justify-content:space-between;
        padding:5px 10px;
        border-radius:20px;
        border:1.5px solid var(--border);
        background:#fff;
        font-size:11px;
        font-weight:700;
        cursor:pointer;
        color:var(--text2);
        transition:all .12s;
        white-space:nowrap;
    }
    .mdi-qf-btn:hover, .mdi-qf-btn.active { border-color:currentColor; }
    .mdi-qf-btn.active     { background:#ede9fe; color:var(--purple); border-color:var(--purple); }
    .mdi-qf-btn.green.active { background:var(--green-l); color:#065f46; border-color:var(--green); }
    .mdi-qf-btn.amber.active { background:var(--amber-l); color:#92400e; border-color:var(--amber); }
    .mdi-qf-btn.red.active   { background:var(--red-l); color:var(--red); border-color:var(--red); }
    .mdi-qf-count { font-size:10px; background:rgba(0,0,0,.07); border-radius:10px; padding:0 6px; margin-left:6px; }

    /* ── Date range preset dropdown ── */
    .mdi-date-range-wrap { display:inline-flex; align-items:center; gap:6px; }
    .mdi-range-select {
        height:32px; padding:0 28px 0 10px;
        border:1.5px solid var(--border2); border-radius:var(--r-sm);
        font-size:11px; font-weight:600; color:var(--text);
        background:#fff; -webkit-appearance:none; appearance:none; cursor:pointer;
        font-family:inherit; transition:border-color .15s; min-width:155px;
    }
    .mdi-range-select:focus { outline:none; border-color:var(--purple); }
    .mdi-custom-dates {
        display:none; align-items:center; gap:5px;
        background:var(--purple-l); border:1.5px solid #c4b5fd;
        border-radius:var(--r-sm); padding:3px 8px;
    }
    .mdi-custom-dates.visible { display:inline-flex; }
    .mdi-custom-dates label { font-size:10px; font-weight:700; color:var(--purple); white-space:nowrap; }
    .mdi-custom-date-input {
        height:26px; width:96px; padding:0 6px;
        border:1.5px solid #c4b5fd; border-radius:4px;
        font-size:11px; font-family:inherit; color:var(--text); background:#fff;
    }
    .mdi-custom-date-input:focus { outline:none; border-color:var(--purple); }
    .mdi-range-apply-btn {
        height:26px; padding:0 10px; border-radius:4px;
        background:var(--purple); color:#fff; border:none;
        font-size:11px; font-weight:700; cursor:pointer; font-family:inherit;
    }
    .mdi-range-apply-btn:hover { background:#4c1d95; }

     /* ── Writeoff/adjustments ── */

    .tl-type-adjustment{
    background:#f59e0b;
}
.tl-type-writeoff{
    background:#9ca3af;
}
.tl-type-contract{
    background:#f59e0b;
}

.enc-body-top{
    display:grid;

    grid-template-columns:
        minmax(0,1fr)
       300px;

    gap:16px;

    align-items:start;
}

.enc-timeline-section{
    min-width:0;
}

.enc-summary-section{
    min-width:0;

    display:flex;
  
    gap:14px;

    position:sticky;
    top:12px;
}
.enc-summary-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 1px 2px rgba(0,0,0,.03);
}

.enc-summary-title{
    padding:11px 14px;
    border-bottom:1px solid var(--border);
    background:var(--surface2);

    font-size:11px;
    font-weight:700;
    letter-spacing:.04em;
    text-transform:uppercase;
    color:var(--text2);
}

.enc-summary-grid{
    padding:14px;
    display:flex;
    flex-direction:column;
    gap:10px;
}

.enc-summary-row{
    display:flex;
    justify-content:space-between;
    align-items:center;

    font-size:12px;
}

.enc-summary-row span{
    color:var(--text2);
}

.enc-summary-row strong{
    font-family:'DM Mono',monospace;
    font-size:12px;
    font-weight:700;
}

.enc-summary-row.balance{
    margin-top:4px;
    padding-top:10px;
    border-top:1px solid var(--border);
}

.enc-summary-row.balance strong{
    font-size:16px;
    font-weight:800;
}

.enc-summary-divider{
    height:1px;
    background:var(--border);
    margin:4px 0;
}

.enc-summary-actions{
    padding:12px;
    display:flex;
    flex-direction:column;
    gap:8px;
}

.enc-side-btn{
    height:34px;
    border:1px solid var(--border);
    border-radius:10px;
    background:#fff;

    font-size:12px;
    font-weight:600;
    color:var(--text);

    cursor:pointer;
    transition:.15s ease;
}

.enc-side-btn:hover{
    background:var(--surface2);
    border-color:var(--purple);
}

.enc-side-btn.danger:hover{
    border-color:#dc2626;
    color:#dc2626;
}
.enc-side-btn--purple{
    background:#fff;
    border:1.5px solid #7c3aed;
    color:#7c3aed;
    font-weight:700;
}
.enc-side-btn--purple:hover{
    background:#f5f3ff;
    border-color:#5b21b6;
    color:#5b21b6;
}
.enc-summary-column,
.enc-actions-column{
    display:flex;
    flex-direction:column;
    gap:14px;
}

.enc-summary-column{
    min-width:0;
}

.enc-actions-column{
    min-width:0;
}
.enc-summary-actions{
    display:flex;
    flex-direction:column;
    gap:10px;
    padding:12px;
}
.pill-wo{
    background:#f3f4f6;
    color:#374151;
    border:1px solid #d1d5db;
}

.dot-wo{
    background:#6b7280;
}
    </style>
    <script>
        $(function () {
            var win = top.printLogSetup ? top : opener.top;
            win.printLogSetup(document.getElementById('printbutton'));

            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
            });
        });
    </script>
    <script>
    <?php require_once("$include_root/patient_file/erx_patient_portal_js.php"); // jQuery for popups for eRx and patient portal?>
    </script>
    <?php
    if ($type_form == '0') {
        $arrOeUiSettings = array(
        'heading_title' => xl('Report') . " - " . xl('Patient Ledger by Date'),
        'include_patient_name' => false,
        'expandable' => false,
        'expandable_files' => array("patient_ledger_report_xpd"),//all file names need suffix _xpd
        'action' => "conceal",//conceal, reveal, search, reset, link or back
        'action_title' => "",
        'action_href' => "",//only for actions - reset, link and back
        'show_help_icon' => false,
        'help_file_name' => ""
        );
    } else {
        $arrOeUiSettings = array(
        'heading_title' => xl('Patient Ledger'),
        'include_patient_name' => true,
        'expandable' => true,
        'expandable_files' => array("patient_ledger_patient_xpd", "stats_full_patient_xpd", "external_data_patient_xpd"),//all file names need suffix _xpd
        'action' => "conceal",//conceal, reveal, search, reset, link or back
        'action_title' => "",
        'action_href' => "",//only for actions - reset, link and back
        'show_help_icon' => true,
        'help_file_name' => "ledger_dashboard_help.php"
        );
    }
    $oemr_ui = new OemrUI($arrOeUiSettings);
    ?>

    <!-- MDI Report Styles -->
    <style>
    /* ── CSS variables matching mdi_payments_ledger exactly ── */
    :root{
      --bg:#f0f2f7;--surface:#fff;--surface2:#f7f8fc;--border:#e2e6f0;--border2:#d0d5e8;
      --text:#1a1d2e;--text2:#5a5f7a;--text3:#9197b3;
      --r:8px;--r-sm:5px;--shadow:0 1px 4px rgba(0,0,0,.07);
      --green:#0ca678;--green-l:#e6f9f3;
      --red:#e03131;--red-l:#fff0f0;
      --amber:#e67700;--amber-l:#fff8e6;
      --purple:#5b21b6;--purple-l:#ede9fe;
    }
    html, body {
        background: #fff !important;
        background-image: none !important;
        margin: 0 !important; padding: 0 !important; width: 100% !important;
        font-family: 'DM Sans', sans-serif !important;
        font-size: 13px !important;
        color: var(--text) !important;
    }
    body::before { content:''; position:fixed; inset:0; background:#fff; z-index:-1; }
    [class*="chat-widget"],[class*="chatbot"],[id*="chat-widget"],[id*="chatbot"],
    iframe[src*="chat"] { display:none !important; }
    nav.navbar.navbar-expand-md.navbar-light.bg-light { display:none; }
    nav.navbar.navbar-light.navbar-expand-sm.bg-light { display:none;}

    /* ── Layout ── */
    .mdi-report-header { background:#1e0a3c; color:#fff; padding:12px 20px; font-size:14px; font-weight:700; display:flex; align-items:center; justify-content:space-between; width:100%; box-sizing:border-box; gap:12px; }
    .mdi-filter-bar { background:#fff; border-bottom:1px solid var(--border); padding:8px 16px; display:flex; align-items:center; flex-wrap:wrap; gap:8px 14px; width:100%; box-sizing:border-box; }
    .mdi-filter-group { display:flex; align-items:center; gap:5px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--purple); white-space:nowrap; }
    .mdi-filter-group .form-control { height:30px; font-size:12.5px; padding:3px 8px; border-radius:5px; border:1px solid var(--border2); background:#fff; color:var(--text); min-width:110px; }
    .mdi-filter-group select.form-control { min-width:130px; }
    .mdi-btn-submit { display:inline-flex; align-items:center; gap:5px; padding:5px 14px; border-radius:6px; background:var(--purple); color:#fff !important; border:none; font-size:12px; font-weight:700; cursor:pointer; text-decoration:none; }
    .mdi-btn-submit:hover { background:#4c1d95; }
    .mdi-btn-secondary { display:inline-flex; align-items:center; gap:5px; padding:5px 12px; border-radius:6px; background:#fff; color:var(--purple) !important; border:1px solid var(--border2); font-size:12px; font-weight:600; cursor:pointer; text-decoration:none; }
    .mdi-btn-secondary:hover { background:var(--purple); color:#fff !important; }
    .mdi-results-wrap { padding:0; background:#fff; width:100%; }
    #report_results { background:#fff; }

    /* ── View tabs + Add Payment button ── */
    .mdi-view-tab { padding:5px 16px; border-radius:20px; border:1.5px solid var(--border2); background:#fff; color:var(--purple); font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap; transition:all .15s; }
    .mdi-view-tab.active { background:var(--purple); color:#fff; border-color:var(--purple); }
    .mdi-view-tab:hover:not(.active) { background:var(--purple-l); }
    .mdi-btn-add-payment { display:inline-flex; align-items:center; gap:6px; padding:6px 16px; border-radius:6px; background:#059669; color:#fff; border:none; font-size:12px; font-weight:700; cursor:pointer; }
    .mdi-btn-add-payment:hover { background:#047857; }

    /* ── Summary strip — 6 cells matching transaction ledger ── */
    .mdi-summary-strip { display:grid; grid-template-columns:repeat(6,1fr); gap:1px; background:var(--border2); overflow:hidden; margin-bottom:8px; }
    .mdi-sum-cell { background:var(--surface); padding:12px 14px; }
    .mdi-sum-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--text3); margin-bottom:4px; }
    .mdi-sum-val { font-family:'DM Mono',monospace; font-size:16px; font-weight:700; color:var(--text); }
    @media(max-width:700px){ .mdi-summary-strip{ grid-template-columns:repeat(3,1fr); } }

    /* ── Badges — identical to mdi_payments_ledger ── */
    .badge { display:inline-flex; align-items:center; padding:2px 8px; border-radius:20px; font-size:10px; font-weight:700; }
    .bg-green { background:var(--green-l); color:#0a5c44; }
    .bg-red   { background:var(--red-l);   color:#9b1c1c; }
    .bg-amber { background:var(--amber-l); color:#92400e; }
    .bg-gray  { background:var(--surface2);color:var(--text2); }

    /* ── Enc-block cards ── */
    .enc-block { background:#fff; border:1.5px solid var(--border); border-radius:12px; overflow:hidden; margin-bottom:14px; box-shadow:0 2px 8px rgba(30,10,60,.06); }
    .enc-header { background:#fff; padding:13px 18px; display:flex; align-items:center; justify-content:space-between; cursor:pointer; user-select:none; transition:background .15s; border-bottom:1px solid transparent; gap:12px; }
    .enc-header:hover { background:#fafaff; }
    .enc-header.open { border-bottom-color:var(--border); }
    .enc-header-left { display:flex; align-items:center; gap:10px; flex:1; min-width:0; }
    .enc-cal-icon { width:32px; height:32px; background:var(--purple-l); border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .enc-title { font-weight:700; font-size:13px; color:var(--purple); }
    .enc-sub   { font-size:11px; color:var(--text2); margin-top:1px; }
    .enc-header-right { display:flex; align-items:center; gap:12px; flex-shrink:0; }
    .enc-stat { display:flex; flex-direction:column; align-items:flex-end; gap:1px; }
    .enc-stat-lbl { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text3); }
    .enc-stat-val { font-size:12px; font-weight:700; font-family:'DM Mono',monospace; color:var(--text); }
    .enc-stat-val.green { color:var(--green); }
    .enc-stat-val.red   { color:var(--red); }
    .enc-stat-val.amber { color:var(--amber); }
    .enc-stat-val.blue  { color:#1e40af; }
    .enc-status-pill { font-size:10px; font-weight:800; padding:3px 10px; border-radius:20px; text-transform:uppercase; letter-spacing:.04em; white-space:nowrap; }
    /* ── Payment activity pills in encounter header ── */
    /* ── Payment pills + detail group ── */
    .enc-pmt-group {
        display:flex; flex-direction:column; gap:4px;
        align-items:flex-start;
        margin-right:16px;
        flex-shrink:0;
        min-width:160px;
    }
    .enc-pmt-pills { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
    .enc-pmt-pill {
        display:inline-flex; align-items:center; gap:4px;
        padding:3px 8px; border-radius:20px;
        font-size:10.5px; font-weight:700;
        white-space:nowrap; border:1.5px solid transparent;
    }
    .ppp-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
    .pill-ins  { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
    .dot-ins   { background:#1d4ed8; }
    .pill-adj  { background:var(--amber-l); color:#92400e; border-color:#fcd34d; }
    .dot-adj   { background:var(--amber); }
    .pill-pat  { background:#fdf4ff; color:#7e22ce; border-color:#e9d5ff; }
    .dot-pat   { background:#7c3aed; }
    .enc-no-pmt { display:flex; align-items:center; font-size:10.5px; color:var(--text3); gap:5px; }
    /* Detail line directly under the pills */
    .enc-pmt-detail-inline {
        font-size:10px; color:var(--text3);
        white-space:nowrap; overflow:hidden;
        text-overflow:ellipsis;
        max-width:260px;
        padding-left:1px;
    }
    .enc-status-due     { background:var(--red-l); color:var(--red); border:1.5px solid #fca5a5; }
    .enc-status-paid    { background:var(--green-l); color:#065f46; border:1.5px solid #6ee7b7; }
    .enc-status-partial { background:var(--amber-l); color:#92400e; border:1.5px solid #fcd34d; }
    .enc-chev { font-size:18px; color:var(--border2); transition:transform .22s; flex-shrink:0; line-height:1; }
    .enc-chev.open { transform:rotate(180deg); color:var(--purple); }
    .enc-body  { display:none; }
    .enc-body.open { display:block; }
    .enc-pills { display:none; } /* replaced by enc-stat */

    /* ── Financial Activity Timeline (replaces two-column layout) ── */
    .enc-body-top { border-bottom:1px solid var(--border); }
    .enc-timeline-section { padding:0 0 10px; }
    .enc-timeline-hdr {
        display:flex; align-items:center; gap:7px;
        font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.07em;
        color:var(--text2); padding:14px 20px 10px;
        border-bottom:1px solid var(--border);
    }
    .enc-timeline-hdr-icon { width:22px; height:22px; border-radius:5px; display:flex; align-items:center; justify-content:center; flex-shrink:0; background:#ede9fe; color:var(--purple); }
    .enc-timeline-table { width:100%; border-collapse:collapse; font-size:12px; }
    .enc-timeline-table th {
        font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em;
        color:var(--text3); padding:6px 12px; border-bottom:1px solid var(--border);
        text-align:left; white-space:nowrap; background:var(--surface2);
    }
    .enc-timeline-table th.r { text-align:right; }
    .enc-timeline-table td { padding:9px 12px; font-size:12px; vertical-align:middle; border-bottom:1px solid #f3f0ff; }
    .enc-timeline-table td.r { text-align:right; font-family:'DM Mono',monospace; }
    .enc-timeline-table .mono { font-family:'DM Mono',monospace; }
    .enc-timeline-table tr:last-child td { border-bottom:none; }
    .enc-timeline-table tr:hover td { background:#fafaff; }
    /* Type dot */
    .tl-type-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; display:inline-block; margin-right:5px; vertical-align:middle; }
    .tl-type-charge    { background:#7c3aed; }
    .tl-type-payment   { background:#16a34a; }
    .tl-type-adj       { background:#d97706; }
    .tl-type-writeoff  { background:#9ca3af; }
    .tl-type-contract  { background:#f59e0b; }
    .tl-type-transfer  { background:#2563eb; }
    .tl-type-refund    { background:#7c3aed; }
    /* Type labels */
    .tl-type-lbl { font-size:11px; font-weight:700; white-space:nowrap; display:inline-flex; align-items:center; }
    /* Amount colors */
    .tl-charge-amt  { color:var(--text); font-weight:700; }
    .tl-pmt-amt     { color:var(--green); font-weight:700; }
    .tl-adj-amt     { color:var(--amber); }
    .tl-wo-amt      { color:var(--red); }
    .tl-bal-pos     { color:var(--red); font-weight:700; }
    .tl-bal-zero    { color:var(--green); font-weight:700; }
    /* Add payment link at bottom */
    .pmt-add-link { display:inline-flex; align-items:center; gap:5px; margin:10px 20px 14px; padding:6px 14px; background:#fff; border:1.5px dashed var(--border2); border-radius:7px; font-size:11px; font-weight:700; color:var(--purple); cursor:pointer; font-family:inherit; transition:all .15s; }
    .pmt-add-link:hover { background:var(--purple-l); border-color:var(--purple); }

    /* ── Editable service line ── */
    .charge-row-edit { display:none; background:var(--purple-l); }
    .charge-row-edit.open { display:table-row; }
    .charge-edit-cell { padding:10px 12px 12px; border-bottom:1px solid var(--border); }
    .charge-edit-grid {
        display:grid; grid-template-columns:100px 1fr 90px 60px;
        gap:8px; align-items:end;
    }
    .charge-edit-field { display:flex; flex-direction:column; gap:3px; }
    .charge-edit-lbl {
        font-size:9px; font-weight:700; text-transform:uppercase;
        letter-spacing:.06em; color:var(--text3);
    }
    .charge-edit-inp {
        height:30px; padding:0 8px;
        border:1.5px solid var(--border2); border-radius:5px;
        font-size:12px; font-family:inherit; color:var(--text);
        background:#fff; width:100%;
        transition:border-color .15s;
    }
    .charge-edit-inp:focus { outline:none; border-color:var(--purple); box-shadow:0 0 0 2px rgba(91,33,182,.1); }
    .charge-edit-actions { display:flex; gap:6px; align-items:center; padding-top:14px; }
    .charge-edit-save {
        height:28px; padding:0 14px; border-radius:5px;
        background:var(--purple); color:#fff; border:none;
        font-size:11px; font-weight:700; cursor:pointer; font-family:inherit;
        display:inline-flex; align-items:center; gap:4px; transition:background .15s;
    }
    .charge-edit-save:hover { background:var(--purple-mid, #7c3aed); }
    .charge-edit-cancel {
        height:28px; padding:0 10px; border-radius:5px;
        background:#fff; color:var(--text2); border:1.5px solid var(--border2);
        font-size:11px; font-weight:600; cursor:pointer; font-family:inherit; transition:all .15s;
    }
    .charge-edit-cancel:hover { border-color:var(--text2); }
    .charge-edit-ok { font-size:11px; color:var(--green); font-weight:600; display:none; }
    .charge-row-edit-btn {
        display:inline-flex; align-items:center; justify-content:center;
        width:18px; height:18px; border-radius:3px; border:none; background:none;
        color:var(--text3); cursor:pointer; padding:0; margin-left:4px;
        vertical-align:middle; transition:color .15s;
        opacity:0;
    }
    tr:hover .charge-row-edit-btn { opacity:1; }
    .charge-row-edit-btn:hover { color:var(--purple); }
    .enc-charge-table th { font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--text3); padding:0 10px 7px 0; border-bottom:1px solid var(--border); text-align:left; white-space:nowrap; }
    .enc-charge-table th:last-child { padding-right:0; }
    .enc-charge-table td { padding:9px 10px 0 0; font-size:12px; vertical-align:top; }
    .enc-charge-table td:last-child { padding-right:0; }
    .enc-charge-table .mono { font-family:'DM Mono',monospace; font-size:11.5px; }

    /* Payment cards inside right col */
    .pmt-card { display:flex; align-items:center; gap:12px; padding:10px 12px; background:var(--surface2); border-radius:8px; margin-bottom:8px; border:1px solid var(--border); }
    .pmt-date-badge { display:flex; flex-direction:column; align-items:center; background:var(--purple-l); border-radius:6px; padding:4px 9px; min-width:38px; flex-shrink:0; }
    .pmt-date-mon { font-size:9px; font-weight:800; text-transform:uppercase; color:var(--purple); letter-spacing:.06em; }
    .pmt-date-day { font-size:16px; font-weight:800; color:var(--purple); line-height:1.1; }
    .pmt-card-body { flex:1; min-width:0; }
    .pmt-card-type { font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.05em; }
    .pmt-card-type.ins  { color:#1e40af; }
    .pmt-card-type.pat  { color:#9d174d; }
    .pmt-card-payer { font-size:11.5px; font-weight:600; color:var(--text2); }
    .pmt-card-desc  { font-size:10.5px; color:var(--text3); margin-top:1px; }
    .pmt-card-amt { font-size:13px; font-weight:800; font-family:'DM Mono',monospace; flex-shrink:0; text-align:right; }
    .pmt-card-amt.ins  { color:var(--green); }
    .pmt-card-amt.pat  { color:#9d174d; }
    .pmt-card-amt.adj  { color:var(--amber); }
    .pmt-add-link { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; color:var(--purple); background:none; border:none; cursor:pointer; padding:6px 0 2px; font-family:inherit; opacity:.75; transition:opacity .15s; text-decoration:none; }
    .pmt-add-link:hover { opacity:1; }
    .no-payments-msg { font-size:11px; color:var(--text3); font-style:italic; padding:4px 0; }

    /* ── MBO Notes button + textarea ── */
    .mbo-notes-btn {
        display:inline-flex; align-items:center; gap:4px;
        height:22px; padding:0 9px; border-radius:5px;
        background:var(--purple-l); border:1.5px solid #c4b5fd;
        color:var(--purple); font-size:10px; font-weight:700;
        cursor:pointer; font-family:inherit; transition:all .15s;
        flex-shrink:0;
    }
    .mbo-notes-btn:hover       { background:var(--purple); color:#fff; }
    .mbo-notes-btn.active      { background:var(--purple); color:#fff; border-color:var(--purple); }
    .mbo-notes-textarea {
        width:100%; box-sizing:border-box;
        border:1.5px solid #c4b5fd; border-radius:6px;
        padding:8px 10px; font-size:12px; font-family:inherit;
        color:var(--text); background:#fff; resize:vertical;
        min-height:62px;
        transition:border-color .15s;
    }
    .mbo-notes-textarea:focus { outline:none; border-color:var(--purple); box-shadow:0 0 0 2px rgba(91,33,182,.1); }
    .mbo-notes-textarea::placeholder { color:var(--text3); font-style:italic; }

    /* ── DEPRECATED table section labels — hidden in new layout ── */
    .enc-section-lbl { display:none; }
    .enc-footer { display:none; }
    /* ── Inner dt tables ── */
    .dt { width:100%; border-collapse:collapse; font-size:12px; }
    .dt th { text-align:left; font-size:11px; font-weight:700; color:var(--text3); text-transform:uppercase; letter-spacing:.05em; padding:7px 10px; border-bottom:2px solid var(--border); white-space:nowrap; }
    .dt td { padding:8px 10px; border-bottom:1px solid var(--border); font-size:12px; vertical-align:top; }
    .dt td.mono { font-family:'DM Mono',monospace; font-size:11px; }
    .dt td.r    { font-family:'DM Mono',monospace; font-size:11px; }
    .dt tbody tr:hover td { background:var(--surface2); }

    /* ── Grand total bar — matches transaction ledger ── */
    .mdi-grand-total-bar { background:var(--purple); padding:12px 20px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:0; border-radius:0 0 var(--r) var(--r); }
    .mdi-grand-total-bar-lbl { font-size:13px; font-weight:700; color:#e9d5ff; }
    .mdi-grand-total-figures { display:flex; gap:28px; align-items:center; flex-wrap:wrap; }
    .mdi-grand-total-fig { display:flex; flex-direction:column; align-items:flex-end; }
    .mdi-grand-total-fig-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#e9d5ff; margin-bottom:2px; }
    .mdi-grand-total-fig-val { font-family:'DM Mono',monospace; font-size:17px; font-weight:700; }

    /* ── Fixed scroll ledger layout ── */
    .ledger-shell {
        display:flex; flex-direction:column;
        height:calc(100vh - 54px); /* full height minus filter bar */
        overflow:hidden;
        background:var(--bg);
    }
    .ledger-sticky-top {
        flex-shrink:0;
        background:#fff;
        border-bottom:1px solid var(--border2);
        z-index:10;
    }
    .ledger-scroll-area {
        flex:1; overflow-y:auto; overflow-x:hidden;
        padding:12px 14px 0;
        scroll-behavior:smooth;
    }
    .ledger-scroll-area::-webkit-scrollbar { width:6px; }
    .ledger-scroll-area::-webkit-scrollbar-track { background:transparent; }
    .ledger-scroll-area::-webkit-scrollbar-thumb { background:var(--border2); border-radius:3px; }
    .ledger-scroll-area::-webkit-scrollbar-thumb:hover { background:var(--purple); }
    .ledger-footer {
        flex-shrink:0;
        background:var(--purple);
    }

    /* ── Collapsible sections (UAC / Prepayments) ── */
    .ledger-section {
        border:1px solid var(--border);
        border-radius:var(--r);
        overflow:hidden;
        margin-bottom:10px;
    }
    .ledger-section-hdr {
        display:flex; align-items:center; justify-content:space-between;
        padding:10px 14px; cursor:pointer; user-select:none;
        font-weight:700; font-size:12px;
        transition:background .15s;
        gap:8px;
    }
    .ledger-section-hdr:hover { filter:brightness(.97); }
    .ledger-section-hdr .ls-title { display:flex; align-items:center; gap:7px; }
    .ledger-section-hdr .ls-right { display:flex; align-items:center; gap:10px; }
    .ledger-section-hdr .ls-amt {
        font-family:'DM Mono',monospace; font-size:13px; font-weight:700;
    }
    .ledger-section-hdr .ls-chev {
        font-size:11px; transition:transform .2s; display:inline-block; opacity:.7;
    }
    .ledger-section-hdr .ls-chev.open { transform:rotate(90deg); }
    .ledger-section-body { display:none; }
    .ledger-section-body.open { display:block; }

    /* UAC colour scheme */
    .ledger-section.ls-uac .ledger-section-hdr { background:var(--amber-l); color:#92400e; border-bottom:2px solid #fdba74; }
    .ledger-section.ls-uac .ls-amt { color:#b45309; }
    /* Prepayments colour scheme */
    .ledger-section.ls-prepay .ledger-section-hdr { background:var(--purple-l); color:var(--purple); border-bottom:2px solid #c4b5fd; }
    .ledger-section.ls-prepay .ls-amt { color:var(--purple); }

    /* ── Unapplied credits section header (legacy fallback) ── */
    .uac-section-header { background:var(--amber-l); color:#92400e; font-weight:700; font-size:12px; padding:9px 14px; border-top:2px solid #fdba74; border-radius:var(--r); margin-bottom:4px; }


    /* ══════════════════════════════════════════════════════
       Layout — right MBO column hidden, bottom panel used
    ══════════════════════════════════════════════════════ */
    .mbo-col, .mbo-th { display:none !important; }
    table.dt.has-mbo  { table-layout:auto; }
    .mbo-panel        { display:none !important; }

    /* ── Section sub-label (CHARGE LINE / PAYMENT DETAILS / MBO) ── */
    .enc-section-lbl {
        font-size:10px; font-weight:800; text-transform:uppercase;
        letter-spacing:.08em; color:var(--text3);
        padding:6px 14px 5px; background:var(--surface2);
        border-top:1px solid var(--border); border-bottom:1px solid var(--border);
    }

    /* ── Inner tables ── */
    .dt { width:100%; border-collapse:collapse; font-size:12px; }
    .dt th {
        text-align:left; font-size:10px; font-weight:700; color:var(--text3);
        text-transform:uppercase; letter-spacing:.05em;
        padding:5px 10px; background:#fff;
        border-bottom:1px solid var(--border); white-space:nowrap;
    }
    .dt th.r { text-align:right; }
    .dt td { padding:7px 10px; border-bottom:1px solid var(--border); font-size:12px; vertical-align:middle; }
    .dt td.mono { font-family:'DM Mono',monospace; font-size:11px; }
    .dt td.r    { font-family:'DM Mono',monospace; font-size:11px; text-align:right; }
    .dt tbody tr:last-child td { border-bottom:none; }
    .dt tbody tr:hover td { background:var(--surface2); }

    /* ── MBO bottom panel ── */
    .mbo-bottom {
        background:#faf9ff; border-top:2px solid #e0d9f7;
        padding:12px 16px 14px;
    }
    .mbo-bottom-lbl {
        font-size:10.5px; font-weight:800; text-transform:uppercase;
        letter-spacing:.07em; color:var(--purple);
        display:flex; align-items:center; gap:6px; margin-bottom:10px;
    }
    .mbo-bottom-grid {
        display:flex; gap:0; align-items:flex-start; flex-wrap:wrap;
    }
    .mbo-bottom-modifier {
        flex:0 0 200px; padding-right:18px;
        border-right:1px solid var(--border2); margin-right:18px;
        min-width:0;
    }
    .mbo-bottom-dx {
        flex:0 0 300px; padding-right:18px;
        border-right:1px solid var(--border2); margin-right:18px;
        min-width:0;
    }
    .mbo-bottom-svcinfo { flex:1; min-width:300px; }
    .mbo-bottom-col-lbl {
        font-size:9.5px; font-weight:700; text-transform:uppercase;
        letter-spacing:.06em; color:var(--text3); margin-bottom:6px;
    }
    .mbo-svc-grid { display:flex; flex-wrap:wrap; gap:0; }
    .mbo-svc-col {
        display:flex; flex-direction:column; gap:2px;
        padding-right:16px; margin-bottom:6px; min-width:70px;
    }
    .mbo-svc-col-lbl {
        font-size:9px; font-weight:700; text-transform:uppercase;
        letter-spacing:.05em; color:var(--text3);
    }
    .mbo-svc-col-val {
        font-size:12px; font-weight:600; color:var(--text);
        font-family:'DM Mono',monospace;
    }
    .mbo-bottom-actions {
        display:flex; flex-direction:column; gap:6px;
        margin-left:auto; padding-left:16px; flex-shrink:0; align-self:flex-start;
    }
    .mbo-save-btn {
        display:inline-flex; align-items:center; justify-content:center; gap:5px;
        padding:7px 16px; border-radius:6px; background:var(--purple); color:#fff;
        border:none; font-size:11px; font-weight:700; cursor:pointer;
        min-width:110px; transition:background .15s; font-family:inherit;
    }
    .mbo-save-btn:hover  { background:#4c1d95; }
    .mbo-save-btn.saving { background:#9ca3af; cursor:wait; }
    .mbo-edit-mbo-btn {
        display:inline-flex; align-items:center; justify-content:center; gap:5px;
        padding:6px 16px; border-radius:6px;
        background:var(--purple-l); color:var(--purple);
        border:1.5px solid var(--purple); font-size:11px; font-weight:700;
        cursor:pointer; min-width:110px; transition:all .15s; font-family:inherit;
    }
    .mbo-edit-mbo-btn:hover { background:var(--purple); color:#fff; }
    .mbo-save-ok {
        color:var(--green); font-size:10.5px; text-align:center;
        font-weight:600; display:none; margin-top:2px;
    }

    /* DX tag list (for bottom panel) */
    .mbo-dx-list { display:flex; flex-direction:column; gap:3px; margin-bottom:6px; min-height:4px; }
    .mbo-dx-tag {
        display:flex; align-items:center; gap:5px;
        background:#f0fdf4; border:1px solid #bbf7d0; border-radius:5px;
        padding:3px 7px; font-size:11px;
    }
    .mbo-dx-code { font-weight:700; color:#166534; font-family:monospace; flex-shrink:0; }
    .mbo-dx-desc { color:var(--text2); flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .mbo-dx-remove {
        flex-shrink:0; background:none; border:none; cursor:pointer;
        color:#9ca3af; font-size:11px; padding:0 2px; line-height:1;
    }
    .mbo-dx-remove:hover { color:var(--red); }
    .mbo-dx-add-btn {
        display:inline-flex; align-items:center; gap:4px;
        padding:3px 9px; border-radius:5px;
        background:#fff; border:1.5px dashed var(--purple);
        color:var(--purple); font-size:11px; font-weight:600;
        cursor:pointer; width:100%; justify-content:center;
        transition:background .12s;
    }
    .mbo-dx-add-btn:hover { background:var(--purple-l); }

    /* Modifier tags */
    .mbo-mod-tags {
        display:flex; flex-wrap:wrap; gap:4px; align-items:center; min-height:26px;
    }
    .mbo-mod-tag {
        display:inline-flex; align-items:center; gap:3px;
        background:var(--purple-l); border:1.5px solid #c4b5fd;
        border-radius:5px; padding:2px 7px;
        font-size:11px; font-weight:700; color:var(--purple);
        font-family:monospace; text-transform:uppercase;
    }
    .mbo-mod-tag-remove {
        cursor:pointer; color:#a78bfa; font-size:13px; line-height:1;
        border:none; background:none; padding:0; margin-left:2px;
    }
    .mbo-mod-tag-remove:hover { color:var(--red); }
    .mbo-mod-add-wrap { display:inline-flex; align-items:center; gap:3px; margin-top:2px; }
    .mbo-mod-input {
        width:54px; height:24px; border:1.5px solid var(--border2); border-radius:4px;
        padding:0 5px; font-size:11px; font-weight:700; color:var(--purple);
        background:#fff; font-family:monospace; text-transform:uppercase;
    }
    .mbo-mod-input:focus { outline:none; border-color:var(--purple); }
    .mbo-mod-input::placeholder { font-weight:400; color:var(--text3); text-transform:none; font-family:inherit; }
    .mbo-mod-add-btn {
        height:24px; width:24px; border-radius:4px; border:1.5px solid #c4b5fd;
        background:var(--purple-l); color:var(--purple); cursor:pointer;
        font-size:15px; font-weight:700; display:flex; align-items:center; justify-content:center;
        line-height:1;
    }
    .mbo-mod-add-btn:hover { background:var(--purple); color:#fff; }

    /* misc tags still used for ERA */
    .mbo-tag { display:inline-flex; align-items:center; padding:1px 7px; border-radius:10px; font-size:10.5px; font-weight:700; }
    .mbo-tag-ins  { background:#dbeafe; color:#1e40af; }
    .mbo-tag-adj  { background:var(--amber-l); color:#92400e; }
    .mbo-tag-pr   { background:#fce7f3; color:#9d174d; }
    .mbo-chevron  { font-size:10px; color:var(--text3); transition:transform .2s; display:inline-block; }
    .mbo-chevron.open { transform:rotate(90deg); }

    @media print {
        .mdi-report-header,.mdi-filter-bar,#mdi-ledger-toolbar,#mdi-totals-bar { display:none; }
        .mdi-results-wrap { padding:0; }
        .ledger-shell { height:auto !important; overflow:visible !important; }
        .ledger-scroll-area { overflow:visible !important; max-height:none !important; }
        .ledger-sticky-top { position:static !important; }
        .ledger-footer { position:static !important; }
        .ledger-section-body { display:block !important; } /* expand all sections for print */
        #mdi-ledger-scroll { max-height:none; overflow:visible; border:none; }
        #report_parameters_daterange { visibility:visible; display:inline; }
    }
    @media screen { #report_parameters_daterange { visibility:hidden; display:none; } }
    </style>
</head>
<body>

    <?php
    if ($type_form != '0') {
        require_once("$include_root/patient_file/summary/dashboard_header.php");
    }
    if ($type_form != '0') {
        $list_id = "ledger";
        $menuPatient = new PatientMenuRole();
        $menuPatient->displayHorizNavBarMenu();
    }
    ?>

    <!-- Page Title Bar -->
    <div class="mdi-report-header">
        <!-- Left: Patient name + demographics -->
        <div style="display:flex;align-items:center;gap:14px;flex-shrink:0;">
            <div>
                <div style="font-size:11px;font-weight:600;color:#a78bfa;text-transform:uppercase;letter-spacing:.07em;"><?php echo xlt('Patient Ledger'); ?></div>



                <?php
$hdr_pat = null;
$hdr_name = '';
$hdr_dob = '';
$hdr_phone = '';
$hdr_age = '';

if (!empty($form_pid)) {
    $hdr_pat = sqlQuery(
        "SELECT fname,lname,DOB,phone_cell,phone_home 
         FROM patient_data 
         WHERE pid = ?",
        [$form_pid]
    );

    $hdr_name = text(trim(
        ($hdr_pat['fname'] ?? '') . ' ' .
        ($hdr_pat['lname'] ?? '')
    ));

    $hdr_dob = !empty($hdr_pat['DOB'])
        ? oeFormatShortDate($hdr_pat['DOB'])
        : '';

    $hdr_phone = $hdr_pat['phone_cell']
        ?: $hdr_pat['phone_home']
        ?: '';

    if (!empty($hdr_pat['DOB'])) {
        $hdr_age = (int) date_diff(
            date_create($hdr_pat['DOB']),
            date_create('today')
        )->y;
    }
}
?>

<div style="display:flex;align-items:baseline;gap:10px;">
    <span style="font-size:17px;font-weight:800;color:#fff;">
        <?php echo $hdr_name ?: xlt('Patient Ledger'); ?>
    </span>

    <?php if (!empty($form_pid)): ?>
        <span style="font-size:11px;color:#a78bfa;">
            (<?php echo (int)$form_pid; ?>)
        </span>
    <?php endif; ?>
</div>

<?php if ($hdr_dob || $hdr_phone): ?>
    <div style="
        font-size:11px;
        color:#c4b5fd;
        margin-top:2px;
        display:flex;
        gap:14px;
    ">
        <?php if ($hdr_dob): ?>
            <span>
                DOB:
                <strong style="color:#e9d5ff;">
                    <?php echo $hdr_dob; ?>
                </strong>

                <?php if ($hdr_age): ?>
                    <span style="color:#a78bfa;">
                        (<?php echo $hdr_age; ?>)
                    </span>
                <?php endif; ?>
            </span>
        <?php endif; ?>

        <?php if ($hdr_phone): ?>
            <span>
                &nbsp;|&nbsp; Phone:
                <strong style="color:#e9d5ff;">
                    <?php echo text($hdr_phone); ?>
                </strong>
            </span>
        <?php endif; ?>
    </div>
<?php endif; ?>

            </div>
        </div>



        <!-- Balance Overview strip — always visible in patient context -->
        <?php if (!empty($form_pid)): ?>

        <div style="display:flex;gap:12px;align-items:center;background:rgba(255,255,255,.07);border-radius:10px;padding:6px 14px;flex:1;max-width:900px;margin:0 10px;overflow:hidden;">
            <div style="font-size:8px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:#a78bfa;white-space:nowrap;flex-shrink:0;"><?php echo xlt('BALANCE OVERVIEW'); ?></div>
            <div style="display:flex;gap:14px;flex-wrap:nowrap;align-items:flex-start;overflow:hidden;">
                <div style="display:flex;flex-direction:column;gap:1px;flex-shrink:0;">
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:800;color:#f87171;white-space:nowrap;" id="hdr-bal-due">–</div>
                    <div style="font-size:8px;color:#a78bfa;font-weight:600;white-space:nowrap;"><?php echo xlt('Net Account Balance'); ?></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:1px;flex-shrink:0;">
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:800;color:#e9d5ff;white-space:nowrap;" id="hdr-total-chg">–</div>
                    <div style="font-size:8px;color:#a78bfa;font-weight:600;white-space:nowrap;"><?php echo xlt('Total Charges'); ?></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:1px;flex-shrink:0;">
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:800;color:#6ee7b7;white-space:nowrap;" id="hdr-ins-paid">–</div>
                    <div style="font-size:8px;color:#a78bfa;font-weight:600;white-space:nowrap;"><?php echo xlt('Insurance Paid'); ?></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:1px;flex-shrink:0;">
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:800;color:#93c5fd;white-space:nowrap;" id="hdr-pat-paid">–</div>
                    <div style="font-size:8px;color:#a78bfa;font-weight:600;white-space:nowrap;"><?php echo xlt('Applied Patient Payments'); ?></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:1px;flex-shrink:0;">
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:800;color:#fcd34d;white-space:nowrap;" id="hdr-pat-credit">–</div>
                    <div style="font-size:8px;color:#a78bfa;font-weight:600;white-space:nowrap;"><?php echo xlt('Patient Credit'); ?></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:1px;flex-shrink:0;">
                    <div style="font-family:'DM Mono',monospace;font-size:13px;font-weight:800;color:#f87171;white-space:nowrap;" id="hdr-open-ins">–</div>
                    <div style="font-size:8px;color:#a78bfa;font-weight:600;white-space:nowrap;"><?php echo xlt('Open Insurance Balance'); ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:8px;align-items:center;">
        <?php if (!empty($form_pid)): ?>
            <button type="button" class="mdi-btn-add-payment"
                onclick="top.restoreSession(); dlgopen('<?php echo htmlspecialchars($GLOBALS['webroot'].'/interface/patient_file/mdi_payments_ledger.php?pid='.(int)$form_pid, ENT_QUOTES); ?>','',1100,720,'','<?php echo xla('Payment'); ?>',{allowResize:true,allowDrag:false,type:'iframe'}); return false;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <?php echo xlt('Add Payment'); ?>
            </button>
            <a href="#" id="printbutton" class="mdi-btn-secondary" style="border-color:rgba(255,255,255,.3);color:#fff;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                <?php echo xlt('Print Ledger'); ?>
            </a>
            <?php endif; ?>
            <button type="button" style="background:none;border:none;cursor:pointer;color:#a78bfa;font-size:22px;padding:0 4px;line-height:1;" title="<?php echo xla('More options'); ?>">⋮</button>
        </div>
    </div>

    <form method='get' action='pat_ledger.php?form=<?php echo attr_url($type_form); ?>&patient_id=<?php echo attr_url($form_pid); ?>' id='theform' onsubmit='top.restoreSession(); return true;'>
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
        <input type='hidden' name='form_refresh' id='form_refresh' value='<?php echo $_REQUEST['form_refresh'] ? 'true' : ''; ?>'/>
        <input type='hidden' name='form_csvexport' id='form_csvexport' value=''/>
        <input type='hidden' name='form_pid' value='<?php echo attr($form_pid); ?>'/>
        <input type='hidden' name='form_dob' value='<?php echo attr($form_dob); ?>'/>

        <!-- Filter Bar -->
        <div id="report_parameters" class="mdi-filter-bar">
            <?php if ($type_form == '0') { ?>
            <span class="mdi-filter-group">
                <?php echo xlt('Facility'); ?>:
                <?php dropdown_facility($form_facility, 'form_facility', true); ?>
            </span>
            <span class="mdi-filter-group">
                <?php echo xlt('Provider'); ?>:
                <?php
                $query = "SELECT id, lname, fname FROM users WHERE authorized=1 AND active!=0 ORDER BY lname, fname";
                $ures = sqlStatement($query);
                echo "<select name='form_provider' class='form-control'>";
                echo "<option value=''>-- " . xlt('All') . " --</option>";
                while ($urow = sqlFetchArray($ures)) {
                    $provid = $urow['id'];
                    echo "<option value='" . attr($provid) . "'";
                    if ($provid == $_REQUEST['form_provider']) { echo " selected"; }
                    echo ">" . text($urow['lname']) . ", " . text($urow['fname']) . "</option>";
                }
                echo "</select>";
                ?>
            </span>
            <?php } ?>
            <?php
            // Detect current preset from the submitted dates
            $today      = date('Y-m-d');
            $month_start = date('Y-m-01');
            $year_start  = date('Y-01-01');
            $d30_start   = date('Y-m-d', strtotime('-30 days'));
            $d90_start   = date('Y-m-d', strtotime('-90 days'));
            $cur_from    = $form_from_date ?: $month_start;
            $cur_to      = $form_to_date   ?: $today;
            // Determine which preset matches
            if ($cur_from === $d90_start && $cur_to === $today && !isset($_REQUEST['form_from_date'])) {
                $cur_preset = 'last_90'; // default
            } elseif ($cur_from === $month_start && $cur_to === $today) {
                $cur_preset = 'current_month';
            } elseif ($cur_from === $d30_start && $cur_to === $today) {
                $cur_preset = 'last_30';
            } elseif ($cur_from === $d90_start && $cur_to === $today) {
                $cur_preset = 'last_90';
            } elseif ($cur_from === $year_start && $cur_to === $today) {
                $cur_preset = 'ytd';
            } elseif ($cur_from === '2000-01-01' && $cur_to === $today) {
                $cur_preset = 'all';
            } else {
                $cur_preset = 'custom';
            }
            ?>
            <span class="mdi-filter-group mdi-date-range-wrap" id="mdi-date-range-wrap">
                <select class="mdi-range-select" id="mdi_range_select" onchange="mdiRangeChange(this)">
                    <option value="current_month" <?php if($cur_preset==='current_month') echo 'selected'; ?>><?php echo xlt('Current Month'); ?></option>
                    <option value="last_30"       <?php if($cur_preset==='last_30')       echo 'selected'; ?>><?php echo xlt('Last 30 Days'); ?></option>
                    <option value="last_90"       <?php if($cur_preset==='last_90')       echo 'selected'; ?>><?php echo xlt('Last 90 Days'); ?></option>
                    <option value="ytd"           <?php if($cur_preset==='ytd')           echo 'selected'; ?>><?php echo xlt('Year to Date'); ?></option>
                    <option value="all"           <?php if($cur_preset==='all')           echo 'selected'; ?>><?php echo xlt('All'); ?></option>
                    <option value="custom"        <?php if($cur_preset==='custom')        echo 'selected'; ?>><?php echo xlt('Custom...'); ?></option>
                </select>
                <span class="mdi-custom-dates <?php echo $cur_preset==='custom' ? 'visible' : ''; ?>" id="mdi_custom_dates">
                    <label><?php echo xlt('From'); ?></label>
                    <input type="date" class="mdi-custom-date-input" id="mdi_custom_from"
                        value="<?php echo attr($cur_from); ?>"
                        max="<?php echo attr($today); ?>">
                    <label><?php echo xlt('To'); ?></label>
                    <input type="date" class="mdi-custom-date-input" id="mdi_custom_to"
                        value="<?php echo attr($cur_to); ?>"
                        max="<?php echo attr($today); ?>">
                    <button type="button" class="mdi-range-apply-btn" onclick="mdiApplyCustomRange()"><?php echo xlt('Apply'); ?></button>
                </span>
            </span>
            <!-- Hidden date fields still submitted to PHP -->
            <input type='hidden' name='form_from_date' id='form_from_date' value='<?php echo attr($cur_from); ?>' />
            <input type='hidden' name='form_to_date'   id='form_to_date'   value='<?php echo attr($cur_to); ?>' />
            <?php if ($type_form == '0') { ?>
            <span class="mdi-filter-group">
                <?php echo xlt('Patient'); ?>:
                <input type='text' size='20' name='form_patient' class='form-control'
                    style='cursor:pointer;' id='form_patient'
                    value='<?php echo ($form_patient) ? attr($form_patient) : xla('Click To Select'); ?>'
                    onclick='sel_patient()' title='<?php echo xla('Click to select patient'); ?>' />
            </span>
            <?php } else { ?>
                <input type='hidden' name='form_patient' value='<?php echo attr($form_patient); ?>' />
            <?php } ?>
            <a href='#' class='mdi-btn-submit' onclick='checkSubmit();'>
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo xlt('Submit'); ?>
            </a>
            <span class="mdi-filter-group" id="mdi-search-wrap" style="margin-left:auto;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <?php echo xlt('Search'); ?>:
                <input type="text" id="mdi_ledger_search" class="form-control"
                       placeholder="<?php echo attr(xlt('Filter results...')); ?>"
                       oninput="mdiLedgerFilter(this.value)"
                       style="min-width:180px;">
            </span>
            <?php if ($_REQUEST['form_refresh'] || $_REQUEST['form_csvexport']) { ?>
                <?php if ($type_form == '1') { ?>
                    <a href="../patient_file/summary/demographics.php" class="mdi-btn-secondary" onclick="top.restoreSession()">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                        <?php echo xlt('Back To Patient'); ?>
                    </a>
                <?php } ?>
            <?php } ?>
        </div><!-- end of filter bar -->

        <div class="mdi-results-wrap">
        <div class="ledger-shell" id="ledger-shell">
        <div class="ledger-sticky-top">
<?php
} // end not export
$from_date = $form_from_date . ' 00:00:00';
$to_date = $form_to_date . ' 23:59:59';

// Check if patient has active primary insurance — if so, all charges default to insurance balance
$patient_has_insurance = false;
if ($form_pid) {
    $ins_check = sqlQuery(
        "SELECT id FROM insurance_data WHERE pid=? AND type='primary' AND (date IS NULL OR date <= CURDATE()) ORDER BY date DESC LIMIT 1",
        [$form_pid]
    );
    $patient_has_insurance = !empty($ins_check['id']);
}

// ── All-time account totals (no date filter) ──────────────────────────────
// Used for Balance Overview header pills and Grand Totals footer so they
// always reflect the full account balance regardless of which date filter
// is active in the ledger body.
$acct_chg = $acct_ins_pmt = $acct_pat_pmt = $acct_ins_adj = $acct_pat_adj = 0;
$acct_ins_bal = $acct_pat_bal = $acct_bal = 0;

if ($form_pid) {
    $acct_enc_res = sqlStatement(
        "SELECT fe.encounter FROM form_encounter fe WHERE fe.pid=? ORDER BY fe.date",
        [$form_pid]
    );
    while ($acct_enc = sqlFetchArray($acct_enc_res)) {
        // Charges
        $acct_chg_res = sqlStatement(
            "SELECT b.fee, b.payer_id FROM billing b
             WHERE b.pid=? AND b.encounter=? AND b.activity>0",
            [$form_pid, $acct_enc['encounter']]
        );
        $acct_enc_chg = 0;
        $acct_enc_has_ins = false;
        while ($acct_c = sqlFetchArray($acct_chg_res)) {
            $acct_enc_chg += (float)$acct_c['fee'];
            if (!empty($acct_c['payer_id'])) { $acct_enc_has_ins = true; }
        }
        $acct_chg += $acct_enc_chg;

        // Initialise enc balances — charges start in ins or pat bucket
        if ($patient_has_insurance || $acct_enc_has_ins) {
            $acct_enc_ins_bal = $acct_enc_chg;
            $acct_enc_pat_bal = 0;
        } else {
            $acct_enc_ins_bal = 0;
            $acct_enc_pat_bal = $acct_enc_chg;
        }
        $acct_enc_bal = $acct_enc_chg;

        // Payments — session payer_id is authoritative (mirrors pat_ledger line 223)
        $acct_pmt_res = sqlStatement(
            "SELECT aa.pay_amount, aa.adj_amount, aa.memo, ars.payer_id
             FROM ar_activity aa
             JOIN ar_session ars ON ars.session_id = aa.session_id
             WHERE aa.pid=? AND aa.encounter=? AND aa.deleted IS NULL
             ORDER BY aa.sequence_no",
            [$form_pid, $acct_enc['encounter']]
        );
        while ($acct_p = sqlFetchArray($acct_pmt_res)) {
            $rpmt     = (float)($acct_p['pay_amount'] ?? 0);
            $radj     = (float)($acct_p['adj_amount'] ?? 0);
            $memo_raw = strtolower($acct_p['memo'] ?? '');
            $is_ins   = !empty($acct_p['payer_id']);

            // PR memo: shift balance ins→pat
            $is_pr = $is_ins && (
                strpos($memo_raw, 'copay')       !== false ||
                strpos($memo_raw, 'deductible')  !== false ||
                strpos($memo_raw, 'coinsurance') !== false ||
                strpos($memo_raw, 'ins1')        !== false ||
                preg_match('/\bpr\b/i', $memo_raw)
            );

            if ($is_pr) {
                $pr_total = preg_match('/(\d+\.\d{2})/', $memo_raw, $prm)
                    ? (float)$prm[1] : (abs($rpmt) + abs($radj));
                $acct_enc_ins_bal -= $pr_total;
                $acct_enc_pat_bal += $pr_total;
            } elseif ($is_ins) {
                $acct_enc_ins_bal -= ($rpmt + $radj);
                $acct_ins_pmt     += $rpmt;
                $acct_ins_adj     += $radj;
            } else {
                $acct_enc_pat_bal -= $rpmt;
                $acct_enc_ins_bal -= $radj;
                $acct_pat_pmt     += $rpmt;
                $acct_pat_adj     += $radj;
            }
            $acct_enc_bal -= ($rpmt + $radj);
        }
        $acct_ins_bal += $acct_enc_ins_bal;
        $acct_pat_bal += $acct_enc_pat_bal;
        $acct_bal     += $acct_enc_bal;
    }
}
// ── End all-time account totals ───────────────────────────────────────────
if ($_REQUEST['form_refresh'] || $_REQUEST['form_csvexport']) {
    $rows = array();
    $sqlBindArray = array();
    $query = "select b.code_type, b.code, b.code_text, b.modifier, b.pid, b.provider_id, " .
    "b.billed, b.payer_id, b.units, b.fee, b.bill_date, b.id, " .
    "ins.name, " .
    "fe.encounter, fe.date, fe.reason, fe.provider_id " .
    "FROM form_encounter AS fe " .
    "LEFT JOIN billing AS b ON b.pid=fe.pid AND b.encounter=fe.encounter " .
    "LEFT JOIN insurance_companies AS ins ON b.payer_id = ins.id " .
    "LEFT OUTER JOIN code_types AS c ON c.ct_key = b.code_type " .
    "WHERE fe.date >= ? AND fe.date <= ? AND fe.pid = ? ";
    array_push($sqlBindArray, $from_date, $to_date, $form_pid);
    if ($form_facility) {
        $query .= "AND fe.facility_id = ? ";
        array_push($sqlBindArray, $form_facility);
    }

    if ($form_provider) {
        $query .= "AND b.provider_id = ? ";
        array_push($sqlBindArray, $form_provider);
    }

    $query .= "AND c.ct_proc = '1' ";
    $query .= "AND activity > 0 ORDER BY fe.date, fe.id ";
    $res = sqlStatement($query, $sqlBindArray);

    if ($_REQUEST['form_csvexport']) {
      // CSV headers:
        if (true) {
            echo csvEscape("Code/Enc Dt") . ',';
            echo csvEscape("Description") . ',';
            echo csvEscape("Billed/Who") . ',';
            echo csvEscape("Type/Units") . ',';
            echo csvEscape("Chg/Pmt Amount") . "\n";
        }
    } else {
        if (!$form_facility) {
            $facility = $facilityService->getPrimaryBusinessEntity();
        } else {
            $facility = $facilityService->getById($form_facility);
        }

        $patient = sqlQuery("SELECT * from patient_data WHERE pid=?", array($form_patient));
        $pat_dob = $patient['DOB'] ?? null;
        $pat_name = ($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? '');
        ?>
        <?php
        // ── New dashboard-style summary row ───────────────────────────────────
        if ($form_refresh && $orow >= 0):
            $total_ins_pmt_val = $total_ins_pmt ?? $total_pmt;
            $total_pat_pmt_val = $total_pat_bal < 0 ? abs($total_pat_bal) : 0;
            $total_balance_val = $total_ins_bal + max(0, $total_pat_bal);
            // Compute per-encounter status counts for donut + quick filters
            // We'll pass these as PHP vars to JS via inline script
            $enc_paid_full = 0; $enc_partial = 0; $enc_balance_due = 0; $enc_total_ct = 0;
        ?>
        <div class="mdi-dash-row">

          <!-- Balance Overview card -->
          <div class="mdi-dash-card mdi-dash-balance">
            <div class="mdi-dash-card-title"><?php echo xlt('BALANCE OVERVIEW'); ?> <span class="mdi-dash-card-sub">(<?php echo xlt('All Dates'); ?>)</span></div>
            <div class="mdi-dash-balance-grid">
              <div class="mdi-dash-stat">
                <div class="mdi-dash-stat-val red-val">$<?php echo text(oeFormatMoney($total_balance_val)); ?></div>
                <div class="mdi-dash-stat-lbl"><?php echo xlt('Balance Due'); ?></div>
              </div>
              <div class="mdi-dash-stat">
                <div class="mdi-dash-stat-val"><?php echo '$'.text(oeFormatMoney($total_chg)); ?></div>
                <div class="mdi-dash-stat-lbl"><?php echo xlt('Total Charges'); ?></div>
              </div>
              <div class="mdi-dash-stat">
                <div class="mdi-dash-stat-val green-val">$<?php echo text(oeFormatMoney($total_ins_pmt_val)); ?></div>
                <div class="mdi-dash-stat-lbl"><?php echo xlt('Insurance Paid'); ?></div>
              </div>
              <div class="mdi-dash-stat">
                <div class="mdi-dash-stat-val blue-val">$<?php echo text(oeFormatMoney($total_pat_pmt_val)); ?></div>
                <div class="mdi-dash-stat-lbl"><?php echo xlt('Patient Paid'); ?></div>
              </div>
            </div>
          </div>

          <!-- Payment Summary card (date-filtered) -->
          <div class="mdi-dash-card mdi-dash-payments">
            <div class="mdi-dash-card-title"><?php echo xlt('PAYMENT SUMMARY'); ?> <span class="mdi-dash-card-sub">(<?php echo text(oeFormatShortDate($form_from_date)); ?> – <?php echo text(oeFormatShortDate($form_to_date)); ?>)</span></div>
            <div class="mdi-dash-balance-grid">
              <div class="mdi-dash-stat">
                <div class="mdi-dash-stat-val green-val">$<?php echo text(oeFormatMoney($total_ins_pmt_val)); ?></div>
                <div class="mdi-dash-stat-lbl"><?php echo xlt('Insurance Paid'); ?></div>
              </div>
              <div class="mdi-dash-stat">
                <div class="mdi-dash-stat-val blue-val">$<?php echo text(oeFormatMoney($total_pat_pmt_val)); ?></div>
                <div class="mdi-dash-stat-lbl"><?php echo xlt('Patient Paid'); ?></div>
              </div>
              <div class="mdi-dash-stat">
                <div class="mdi-dash-stat-val red-val">$<?php echo text(oeFormatMoney($total_balance_val)); ?></div>
                <div class="mdi-dash-stat-lbl"><?php echo xlt('Balance Due'); ?></div>
              </div>
            </div>
          </div>

          <!-- Distribution donut -->
          <div class="mdi-dash-card mdi-dash-donut">
            <div class="mdi-dash-card-title"><?php echo xlt('DISTRIBUTION OVERVIEW'); ?></div>
            <div class="mdi-dash-donut-wrap">
              <canvas id="mdi-donut-canvas" width="90" height="90"></canvas>
              <div class="mdi-dash-donut-legend" id="mdi-donut-legend"></div>
            </div>
          </div>

          <!-- Quick Filters -->
          <div class="mdi-dash-card mdi-dash-filters">
            <div class="mdi-dash-card-title"><?php echo xlt('QUICK FILTERS'); ?></div>
            <div class="mdi-dash-qf-wrap" id="mdi-qf-wrap">
              <button class="mdi-qf-btn active" data-filter="all" onclick="mdiQuickFilter('all',this)"><?php echo xlt('All'); ?> <span class="mdi-qf-count" id="qf-all">–</span></button>
              <button class="mdi-qf-btn green" data-filter="paid" onclick="mdiQuickFilter('paid',this)"><?php echo xlt('Paid in Full'); ?> <span class="mdi-qf-count" id="qf-paid">–</span></button>
              <button class="mdi-qf-btn amber" data-filter="partial" onclick="mdiQuickFilter('partial',this)"><?php echo xlt('Partially Paid'); ?> <span class="mdi-qf-count" id="qf-partial">–</span></button>
              <button class="mdi-qf-btn red" data-filter="due" onclick="mdiQuickFilter('due',this)"><?php echo xlt('Balance Due'); ?> <span class="mdi-qf-count" id="qf-due">–</span></button>
            </div>
          </div>

        </div><!-- /mdi-dash-row -->
        <?php endif; ?>

        <?php
        // ── toolbar: view tabs + single Add Payment button ──────────────
        $mdiPmtUrl = $GLOBALS['webroot'] . '/interface/patient_file/mdi_payments_ledger.php?pid=' . (int)$form_pid;
        echo "<div id='mdi-ledger-toolbar' style='display:flex;align-items:center;justify-content:space-between;padding:8px 4px 8px;flex-wrap:wrap;gap:8px;margin-bottom:4px;'>";
        echo "<div style='display:flex;gap:4px;'>";
        echo "<button type='button'class='mdi-view-tab active' data-view='all' onclick='mdiSetView(\"all\",this)'>" . xlt('View All') . "</button>";
        echo "<button type='button' class='mdi-view-tab' data-view='billed' onclick='mdiSetView(\"billed\",this)'>" . xlt('Billed') . "</button>";
        echo "<button type='button' class='mdi-view-tab' data-view='payments' onclick='mdiSetView(\"payments\",this)'>" . xlt('Payments') . "</button>";
        echo "</div>";
        echo "<div style='display:flex;gap:4px;'>";
        echo "<button type='button' class='mdi-view-tab' onclick='mdiExpandAll()' title='" . xla('Expand all encounters') . "'>&#9660; " . xlt('Expand All') . "</button>";
        echo "<button type='button' class='mdi-view-tab' onclick='mdiCollapseAll()' title='" . xla('Collapse all encounters') . "'>&#9650; " . xlt('Collapse All') . "</button>";
        echo "</div>";
        // Add Payment button moved to header bar
        echo "</div>";
        ?>
        </div><!-- /ledger-sticky-top -->
        <div class="ledger-scroll-area" id="mdi-ledger-scroll">
            
        <div id="report_results">
                    <?php
    // ── UAC + Prepayments rendered at TOP, before encounter rows ─────────────
    if ($form_pid && ($form_refresh || $_REQUEST['form_refresh'])) {
        $uac_top = GetAllUnapplied($form_pid, $from_date, $to_date);
        if (count($uac_top) > 0) {
            $uac_total_amt = 0;
            foreach ($uac_top as $u) { $uac_total_amt += max(0, (float)($u['pay_total'] ?? 0) - (float)($u['applied'] ?? 0)); }
            echo "<div class='ledger-section ls-uac'>";
            echo "<div class='ledger-section-hdr' onclick='ledgerSectionToggle(this)'>";
            echo "  <div class='ls-title'>";
            echo "    <svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='#92400e' stroke-width='2'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg>";
            echo "    <span>" . xlt('Unapplied Credits') . "</span>";
            echo "    <span style='font-size:10px;font-weight:400;opacity:.75;'>(" . xlt('Payments with remaining unapplied balance') . ")</span>";
            echo "  </div>";
            echo "  <div class='ls-right'>";
            echo "    <span class='ls-amt'>\$" . text(oeFormatMoney($uac_total_amt)) . "</span>";
            echo "    <span class='ls-chev open'>&#9658;</span>";
            echo "  </div>";
            echo "</div>";
            echo "<div class='ledger-section-body open'>";
            echo "<table class='dt'><thead><tr>";
            echo "<th>" . xlt('Date') . "</th>";
            echo "<th>" . xlt('Type') . "</th>";
            echo "<th>" . xlt('Source / Payer') . "</th>";
            echo "<th>" . xlt('Description') . "</th>";
            echo "<th class='r' style='color:var(--green);'>" . xlt('Amount') . "</th>";
            echo "<th class='r' style='color:#9d174d;'>" . xlt('Applied') . "</th>";
            echo "<th class='r' style='color:var(--amber);'>" . xlt('Unapplied') . "</th>";
            echo "<th class='r'>" . xlt('Balance') . "</th>";
            echo "</tr></thead><tbody>";
            foreach ($uac_top as $u) {
                $u_method = List_Look($u['payment_method'], 'payment_method');
                $u_ref    = $u['reference'] ?? '';
                $u_memo   = List_Look($u['adjustment_code'], 'payment_adjustment_code');
                $u_desc   = trim($u_method . ($u_ref ? ' - ' . $u_ref : '') . ($u_memo ? ' [' . $u_memo . ']' : ''));
                $u_payer  = ($u['name'] ?? '') ?: xl('Patient');
                $u_date   = substr($u['post_to_date'] ?? '', 0, 10);
                $u_total  = (float)($u['pay_total'] ?? 0);
                $u_appl   = (float)($u['applied']   ?? 0);
                $u_unapp  = $u_total - $u_appl;
                if ($u_unapp == 0 && !$GLOBALS['show_payment_history']) continue;
                $is_ins   = !empty($u['payer_id']);
                $badge    = $is_ins
                    ? "<span style='display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;background:#dbeafe;color:#1e40af;'>INS</span>"
                    : "<span style='display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;background:#fef3c7;color:#92400e;'>PAT</span>";
                echo "<tr style='background:var(--amber-l);'>";
                echo "<td class='mono'>" . text($u_date) . "</td>";
                echo "<td>" . $badge . " " . xlt('Unapplied') . "</td>";
                echo "<td style='font-weight:600;'>" . text($u_payer) . "</td>";
                echo "<td style='color:var(--text2);font-size:11px;'>" . text($u_desc) . "</td>";
                echo "<td class='r' style='color:var(--green);font-weight:700;'>\$" . text(oeFormatMoney($u_total)) . "</td>";
                echo "<td class='r' style='color:var(--text2);'>\$" . text(oeFormatMoney($u_appl)) . "</td>";
                echo "<td class='r' style='color:var(--amber);font-weight:700;'>\$" . text(oeFormatMoney($u_unapp)) . "</td>";
                echo "<td class='r'>&mdash;</td>";
                echo "</tr>\n";
            }
            echo "</tbody></table>";
            echo "</div>";
            echo "</div>";
        }

        $prepay_sql_top = "SELECT s.*, ins.name,
            COALESCE((SELECT SUM(aa.pay_amount) FROM ar_activity aa
                      WHERE aa.session_id=s.session_id AND aa.deleted IS NULL), 0) AS total_applied
            FROM ar_session s
            LEFT JOIN insurance_companies ins ON ins.id = s.payer_id
            WHERE s.patient_id = ?
              AND s.pay_total > 0
              AND s.post_to_date >= ? AND s.post_to_date <= ?
            ORDER BY s.post_to_date DESC";
        $prepay_res_top  = sqlStatement($prepay_sql_top, [$form_pid, $from_date, $to_date]);
        $prepay_rows_top = [];
        while ($pr = sqlFetchArray($prepay_res_top)) {
            $pr['applied'] = (float)($pr['total_applied'] ?? 0);
            $pr['remain']  = (float)($pr['pay_total']) - $pr['applied'];
            if ($pr['remain'] > 0.005 || ($GLOBALS['show_payment_history'] ?? false)) {
                $prepay_rows_top[] = $pr;
            }
        }
        if (count($prepay_rows_top) > 0) {
            $prepay_total_top = 0;
            foreach ($prepay_rows_top as $pr) { $prepay_total_top += $pr['remain']; }
            echo "<div class='ledger-section ls-prepay'>";
            echo "<div class='ledger-section-hdr' onclick='ledgerSectionToggle(this)'>";
            echo "  <div class='ls-title'>";
            echo "    <svg width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='var(--purple)' stroke-width='2'><rect x='2' y='5' width='20' height='14' rx='2'/><line x1='2' y1='10' x2='22' y2='10'/></svg>";
            echo "    <span>" . xlt('Prepayments') . "</span>";
            echo "  </div>";
            echo "  <div class='ls-right'>";
            echo "    <span class='ls-amt'>\$" . text(oeFormatMoney($prepay_total_top)) . "</span>";
            echo "    <span class='ls-chev'>&#9658;</span>";
            echo "  </div>";
            echo "</div>";
            echo "<div class='ledger-section-body'>";
            echo "<table class='dt'><thead><tr>";
            echo "<th>" . xlt('Date') . "</th>";
            echo "<th>" . xlt('Type') . "</th>";
            echo "<th>" . xlt('Payer') . "</th>";
            echo "<th>" . xlt('Reference') . "</th>";
            echo "<th class='r' style='color:var(--purple);'>" . xlt('Amount') . "</th>";
            echo "<th class='r'>" . xlt('Applied') . "</th>";
            echo "<th class='r' style='color:var(--green);'>" . xlt('Remaining') . "</th>";
            echo "<th class='r'>" . xlt('Note') . "</th>";
            echo "</tr></thead><tbody>";
            foreach ($prepay_rows_top as $pr) {
                $method  = List_Look($pr['payment_method'], 'payment_method');
                $ref     = $pr['reference'] ?? '';
                $label   = trim($method . ($ref ? ' - ' . $ref : ''));
                $payer   = $pr['name'] ?: xl('Patient');
                $pdate   = !empty($pr['check_date'])
                    ? substr((string)$pr['check_date'], 0, 10)
                    : substr((string)($pr['post_to_date'] ?? $pr['created_time'] ?? ''), 0, 10);
                $amt     = (float)($pr['pay_total']  ?? 0);
                $applied = (float)($pr['applied']    ?? 0);
                $remain  = $amt - $applied;
                $is_ins  = !empty($pr['payer_id']);
                $badge   = $is_ins
                    ? "<span style='display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;background:#dbeafe;color:#1e40af;'>INS</span>"
                    : "<span style='display:inline-block;font-size:10px;font-weight:700;padding:1px 7px;border-radius:10px;background:var(--purple-l);color:var(--purple);'>PREPAY</span>";
                echo "<tr style='background:var(--purple-l);'>";
                echo "<td class='mono'>" . text($pdate) . "</td>";
                echo "<td>" . $badge . "</td>";
                echo "<td style='font-weight:600;color:var(--purple);'>" . text($payer) . "</td>";
                echo "<td style='color:var(--text2);font-size:11px;'>" . text($label) . "</td>";
                echo "<td class='r' style='color:var(--purple);font-weight:700;'>\$" . text(oeFormatMoney($amt)) . "</td>";
                echo "<td class='r' style='color:var(--text2);'>\$" . text(oeFormatMoney($applied)) . "</td>";
                echo "<td class='r' style='color:var(--green);font-weight:700;'>\$" . text(oeFormatMoney($remain)) . "</td>";
                echo "<td class='r'>&mdash;</td>";
                echo "</tr>\n";
            }
            echo "</tbody></table>";
            echo "</div>";
            echo "</div>";
        }
    }
    // ── End top sections ──────────────────────────────────────────────────────
    }

    $orow = 0;
    $prev_encounter_id = -1;
    $hdr_printed = false;
    $prev_row = array();
    $ledger_pmt_hdr_enc = -1;
    while ($erow = sqlFetchArray($res)) {
        $print = '';
        $csv = '';
        if ($erow['encounter'] != $prev_encounter_id) {
            if ($prev_encounter_id != -1) {
                $credits = GetAllCredits($prev_encounter_id, $form_pid);
                if (count($credits) > 0) {
                    if (!$hdr_printed) {
                        // Pre-compute credit totals for header pills
                        $pre_pmt = 0; $pre_adj = 0; $pre_ins_pmt = 0; $pre_pat_pmt = 0; $pre_ins_adj = 0;
                        foreach ($credits as $c) {
                            $pre_pmt += (float)($c['pay_amount'] ?? 0);
                            $pre_adj += (float)($c['adj_amount'] ?? 0);
                            $c_is_ins = !empty($c['payer_id']);
                            if ($c_is_ins) { $pre_ins_pmt += (float)($c['pay_amount'] ?? 0); $pre_ins_adj += (float)($c['adj_amount'] ?? 0); }
                            else           { $pre_pat_pmt += (float)($c['pay_amount'] ?? 0); }
                        }
                        $pre_ins_bal = $enc_chg - $pre_ins_pmt - $pre_ins_adj;
                        $pre_pat_bal = 0 - $pre_pat_pmt;
                        PrintEncHeader($prev_row['date'], $prev_row['reason'], $prev_row['provider_id'], $prev_encounter_id, $form_pid, $enc_chg, $pre_pmt, $enc_chg - $pre_pmt - $pre_adj, $pre_ins_bal, $pre_pat_bal);
                    }
                    ClosePaymentTable();
                    PrintCreditDetail($credits, $form_pid);
                    CloseLedgerPaymentTable();
                    ClosePaymentTable();
                }
                if ($hdr_printed) {
                    ClosePaymentTable();
                    PrintEncFooter();
                }
                $hdr_printed = false;
            }
            $enc_units = $enc_chg = $enc_pmt = $enc_adj = $enc_bal = 0;
            $enc_ins_bal = $enc_pat_bal = 0;
            $ledger_pmt_hdr_enc = -1; // reset payment table header per encounter
            $GLOBALS['pending_mbo_bottom'] = ''; // reset pending MBO panel
            ClosePaymentTable();
            $ledger_pmt_hdr_enc = -1;
        }

        if ($erow['id']) {
            if (!$hdr_printed) {
                // Pre-fetch credits to compute totals for header pills
                $preview_credits = GetAllCredits($erow['encounter'], $form_pid);
                $pre_pmt = 0; $pre_adj = 0; $pre_chg = 0;
                $pre_ins_pmt = 0; $pre_pat_pmt = 0; $pre_ins_adj = 0;
                foreach ($preview_credits as $c) {
                    $pre_pmt += (float)($c['pay_amount'] ?? 0);
                    $pre_adj += (float)($c['adj_amount'] ?? 0);
                    $c_is_ins = !empty($c['payer_id']);
                    if ($c_is_ins) { $pre_ins_pmt += (float)($c['pay_amount'] ?? 0); $pre_ins_adj += (float)($c['adj_amount'] ?? 0); }
                    else           { $pre_pat_pmt += (float)($c['pay_amount'] ?? 0); }
                }
                // Charge total comes from billing rows — sum fee for this encounter
                $fee_res = sqlStatement("SELECT SUM(fee) as total FROM billing WHERE encounter=? AND pid=? AND activity>0", [$erow['encounter'], $form_pid]);
                $fee_row = sqlFetchArray($fee_res);
                $pre_chg = (float)($fee_row['total'] ?? 0);
                $pre_ins_bal = $pre_chg - $pre_ins_pmt - $pre_ins_adj;
                $pre_pat_bal = 0 - $pre_pat_pmt;
                PrintEncHeader(
                    $erow['date'],
                    $erow['reason'],
                    $erow['provider_id'],
                    $erow['encounter'],
                    $form_pid,
                    $pre_chg,
                    $pre_pmt,
                    $pre_chg - $pre_pmt - $pre_adj,
                    $pre_ins_bal,
                    $pre_pat_bal
                );
                $hdr_printed = true;
            }

            $code_desc = $erow['code_text'];
            if (strlen($code_desc) > 55) {
                $code_desc = substr($code_desc, 0, 55) . '...';
            }

            $who  = ($erow['name'] == '') ? xl('Self') : $erow['name'];
            $bill = substr($erow['bill_date'] ?? '', 0, 10);
            if ($bill == '') { $bill = xl('unbilled'); }

            // ── MBO data fetch ────────────────────────────────────────────────
            $mbo_svc_date  = substr($erow['date'] ?? '', 0, 10);
            $mbo_cpt       = text($erow['code'] ?? '');
            $mbo_units     = text($erow['units'] ?? 1);
            $mbo_fee       = '$' . text(oeFormatMoney($erow['fee'] ?? 0));
            $mbo_carrier   = text($erow['name'] ?? xl('Self'));
            $mbo_bill_date = text(substr($erow['bill_date'] ?? '', 0, 10) ?: xl('Unbilled'));
            $mbo_enc_id    = (int)$erow['encounter'];
            $mbo_pid_val   = (int)$form_pid;

            // POS + facility from form_encounter
            $mbo_fe = sqlQuery(
                "SELECT fe.reason, fe.facility_id, fe.billing_note,
                        f.name AS facility_name, f.pos_code
                 FROM form_encounter fe
                 LEFT JOIN facility f ON f.id = fe.facility_id
                 WHERE fe.encounter=? AND fe.pid=? LIMIT 1",
                [$mbo_enc_id, $mbo_pid_val]
            );
            $mbo_pos          = text($mbo_fe['pos_code']      ?? '');
            $mbo_facility     = text($mbo_fe['facility_name'] ?? '');
            $mbo_billing_note = text($mbo_fe['billing_note']  ?? '');

            // Billing row id + current modifier for this CPT
            $mbo_billing_row = sqlQuery(
                "SELECT id, modifier FROM billing
                  WHERE encounter=? AND pid=? AND code=? AND activity>0
                    AND code_type NOT IN ('ICD10','ICD9','ICD9CM')
                  ORDER BY id LIMIT 1",
                [$mbo_enc_id, $mbo_pid_val, $erow['code'] ?? '']
            );
            $mbo_billing_id = (int)($mbo_billing_row['id']       ?? 0);
            $mbo_modifier   = htmlspecialchars($mbo_billing_row['modifier'] ?? $erow['modifier'] ?? '', ENT_QUOTES);

            // DX tags from patient's active medical_problem list
            $mbo_dx_tags_html = '';
            $seen_codes       = [];
            $mbo_dx_res2 = sqlStatement(
                "SELECT l.id, l.diagnosis, l.title
                   FROM lists l
                   WHERE l.pid=? AND l.type='medical_problem' AND l.activity=1
                   ORDER BY l.date DESC LIMIT 12",
                [$mbo_pid_val]
            );
            while ($dxr2 = sqlFetchArray($mbo_dx_res2)) {
                $diag_str = trim($dxr2['diagnosis'] ?? '');
                if (!$diag_str) continue;
                foreach (explode(';', $diag_str) as $dp) {
                    $dp = trim($dp);
                    if (!$dp) continue;
                    $segs = explode(':', $dp);
                    if (count($segs) >= 3 && preg_match('/^\d{1,2}$/', end($segs))) array_pop($segs);
                    $full_code  = implode(':', $segs);
                    $code_only  = $segs[count($segs)-1] ?? $dp;
                    $code_type  = $segs[0] ?? 'ICD10';
                    if (isset($seen_codes[$full_code])) continue;
                    $seen_codes[$full_code] = true;
                    $dx_code  = htmlspecialchars($full_code,           ENT_QUOTES);
                    $dx_text  = htmlspecialchars($dxr2['title'] ?? '', ENT_QUOTES);
                    $dx_ctype = htmlspecialchars($code_type,           ENT_QUOTES);
                    $mbo_dx_tags_html .=
                        "<div class='mbo-dx-tag' data-dx-code='" . $dx_code . "' data-dx-text='" . $dx_text . "' data-dx-code-type='" . $dx_ctype . "'>"
                        . "<span class='mbo-dx-code'>" . text($code_only) . "</span>"
                        . ($dx_text ? "<span class='mbo-dx-desc'>" . text(substr($dxr2['title'], 0, 35)) . "</span>" : '')
                        . "<button type='button' class='mbo-dx-remove' onclick='this.parentElement.remove()' title='Remove'>&#10005;</button>"
                        . "</div>";
                }
            }

            // ── CHARGE ROW — timeline format ──
            $row_bid = (int)($erow['id'] ?? 0);
            $edit_row_id = 'cedit-' . $row_bid;

            // Running balance starts at fee
            $GLOBALS['tl_running_bal'] = (float)($erow['fee'] ?? 0);
            $tl_bal = $GLOBALS['tl_running_bal'];
            $tl_bal_cls = $tl_bal > 0.005 ? 'tl-bal-pos' : 'tl-bal-zero';

            $print  = "<tr data-billing-id='" . $row_bid . "' class='charge-data-row'>";
            // Date
            $charge_date = '';
            if (!empty($erow['date'])) {
                $charge_date = substr($erow['date'], 0, 10);
            }
            $print .= "<td class='mono' style='font-size:11px;color:var(--text2);'>" . text($charge_date) . "</td>";
            // Type
            $print .= "<td><span class='tl-type-lbl'><span class='tl-type-dot tl-type-charge'></span>Charge</span></td>";
            // Description — CPT + desc + edit button
            $print .= "<td>";
            $print .= "<div style='display:flex;align-items:center;gap:4px;flex-wrap:wrap;'>";
            $print .= "<span style='font-family:\"DM Mono\",monospace;font-weight:700;color:var(--purple);font-size:11.5px;'>" . text($erow['code']);
            if ($erow['modifier']) {
                foreach (array_filter(array_map('trim', explode(':', $erow['modifier']))) as $_mp) {
                    $print .= "<span style='margin-left:3px;background:var(--purple-l);border:1px solid #c4b5fd;border-radius:4px;padding:0 4px;font-size:10px;font-weight:700;color:var(--purple);'>" . text(strtoupper($_mp)) . "</span>";
                }
            }
            $print .= "</span>";
            $print .= "<button type='button' class='charge-row-edit-btn' onclick='chargeEditOpen(this)' title='" . xla('Edit service line') . "'>"
                   . "<svg width='11' height='11' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path d='M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7'/><path d='M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z'/></svg>"
                   . "</button>";
            $print .= "</div>";
            $print .= "<div style='color:var(--text3);font-size:10.5px;margin-top:2px;'>" . text($code_desc) . ($who ? " &mdash; <em>" . text($who) . "</em>" : "") . "</div>";
            $print .= "</td>";
            // Reference — encounter #
            $print .= "<td style='color:var(--text3);font-size:11px;'>Enc #" . text($erow['encounter'] ?? '') . "</td>";
            // Charge
            $print .= "<td class='r tl-charge-amt'>$" . text(oeFormatMoney($erow['fee'])) . "</td>";
            // Payment/Adj — dash
            $print .= "<td class='r' style='color:var(--text3);'>&mdash;</td>";
            // Balance
            $print .= "<td class='r " . $tl_bal_cls . "'>$" . text(oeFormatMoney($tl_bal)) . "</td>";
            $print .= "</tr>";

            // ── Editable row (hidden by default) ───────────────────────────
            $print .= "<tr class='charge-row-edit' id='" . attr($edit_row_id) . "'>";
            $print .= "<td colspan='7' class='charge-edit-cell'>";
            $print .= "<div class='charge-edit-grid'>";
            $print .= "<div class='charge-edit-field'><label class='charge-edit-lbl'>" . xlt('CPT Code') . "</label>"
                   .  "<input type='text' class='charge-edit-inp' data-ce-field='code' maxlength='20'"
                   .  " value='" . attr($erow['code']) . "'></div>";
            $print .= "<div class='charge-edit-field'><label class='charge-edit-lbl'>" . xlt('Description') . "</label>"
                   .  "<input type='text' class='charge-edit-inp' data-ce-field='code_text' maxlength='255'"
                   .  " value='" . attr($code_desc) . "'></div>";
            $print .= "<div class='charge-edit-field'><label class='charge-edit-lbl'>" . xlt('Fee ($)') . "</label>"
                   .  "<input type='number' class='charge-edit-inp' data-ce-field='fee' step='0.01' min='0'"
                   .  " value='" . attr($erow['fee']) . "'></div>";
            $print .= "<div class='charge-edit-field'><label class='charge-edit-lbl'>" . xlt('Units') . "</label>"
                   .  "<input type='number' class='charge-edit-inp' data-ce-field='units' step='1' min='1'"
                   .  " value='" . attr($erow['units']) . "'></div>";
            $print .= "</div>";
            $print .= "<div class='charge-edit-actions'>"
                   .  "<button type='button' class='charge-edit-save'"
                   .  " onclick='chargeEditSave(this," . $row_bid . "," . (int)($mbo_pid_val ?? 0) . ")'>"
                   .  "<svg width='11' height='11' fill='none' stroke='currentColor' stroke-width='2.5' viewBox='0 0 24 24'><polyline points='20 6 9 17 4 12'/></svg>"
                   .  " " . xlt('Save') . "</button>"
                   .  "<button type='button' class='charge-edit-cancel' onclick='chargeEditClose(this)'>" . xlt('Cancel') . "</button>"
                   .  "<span class='charge-edit-ok'>&#10003; " . xlt('Saved') . "</span>"
                   .  "</div>";
            $print .= "</td></tr>";

            // ── MBO BOTTOM PANEL ─────────────────────────────────────────────────
            // Modifiers
            $mod_parts = array_filter(array_map('trim', explode(':', $mbo_modifier)));
            $mod_parts = array_slice($mod_parts, 0, 4);
            $mod_count = count($mod_parts);
            $add_style = $mod_count >= 4 ? " style='display:none;'" : "";

            // Edit MBO popup URL
            $mbo_popup_url = $GLOBALS['web_root'] .
            '/interface/patient_file/encounter/view_form.php' .
            '?formname=misc_billing_options' .
            '&isBilling=1' .
            '&pid=' . urlencode($mbo_pid_val) .
            '&enc=' . urlencode($mbo_enc_id) .
            '&id=' . urlencode($mbo_id ?? 0);

            $mbo_bottom  = "<div class='mbo-bottom' id='mbo-bottom-" . $mbo_enc_id . "'>";
            $mbo_bottom .= "<div class='mbo-bottom-lbl'>";
            $mbo_bottom .= "<svg width='12' height='12' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path d='M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7'/><path d='M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z'/></svg>";
            $mbo_bottom .= " " . xlt('MBO (MODIFIER, DX, SERVICE INFO)') . "</div>";
            $mbo_bottom .= "<div class='mbo-bottom-grid'>";

            // ── Modifier column ──────────────────────────────────────────
            $mbo_bottom .= "<div class='mbo-bottom-modifier'>";
            $mbo_bottom .= "<div class='mbo-bottom-col-lbl'>" . xlt('MODIFIER') . "</div>";
            $mbo_bottom .= "<div class='mbo-mod-tags' id='modTags_" . $mbo_enc_id . "_" . $mbo_billing_id . "'>";
            foreach ($mod_parts as $mp) {
                $mbo_bottom .= "<span class='mbo-mod-tag'>" . text(strtoupper($mp))
                             . "<button type='button' class='mbo-mod-tag-remove' onclick='mboRemoveMod(this)' title='" . xla('Remove') . "'>&#215;</button>"
                             . "</span>";
            }
            $mbo_bottom .= "<span class='mbo-mod-add-wrap'" . $add_style . " id='modAddWrap_" . $mbo_enc_id . "_" . $mbo_billing_id . "'>";
            $mbo_bottom .= "<input type='text' class='mbo-mod-input' maxlength='8' placeholder='" . xla('Add mod...') . "'"
                         . " onkeydown='if(event.key===\"Enter\"){event.preventDefault();mboAddMod(this);}'"
                         . " oninput='this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/,\"\");'>";
            $mbo_bottom .= "<button type='button' class='mbo-mod-add-btn' onclick='mboAddMod(this.previousElementSibling)' title='" . xla('Add modifier') . "'>+</button>";
            $mbo_bottom .= "</span>";
            $mbo_bottom .= "<input type='hidden' class='mbo-mod-value' data-mbo-field='modifier' value='" . text($mbo_modifier) . "'>";
            $mbo_bottom .= "</div></div>"; // mod-tags + modifier col

            // ── DX Codes column ──────────────────────────────────────────
            $mbo_bottom .= "<div class='mbo-bottom-dx'>";
            $mbo_bottom .= "<div class='mbo-bottom-col-lbl'>" . xlt('DX CODES') . "</div>";
            // Use the correct mbo-dx-list class so mboOpenDxPopup / mboConfirmDxCodes work
            $mbo_bottom .= "<div class='mbo-dx-list'>" . $mbo_dx_tags_html . "</div>";
            $mbo_bottom .= "<button type='button' class='mbo-dx-add-btn' onclick='mboOpenDxPopup(this)'>";
            $mbo_bottom .= "<svg width='11' height='11' fill='none' stroke='currentColor' stroke-width='2.5' viewBox='0 0 24 24'><line x1='12' y1='5' x2='12' y2='19'/><line x1='5' y1='12' x2='19' y2='12'/></svg>";
            $mbo_bottom .= " " . xlt('Add Dx Code') . "</button>";
            $mbo_bottom .= "</div>"; // dx col

            // ── Service Info columns ─────────────────────────────────────
            $mbo_bottom .= "<div class='mbo-bottom-svcinfo'>";
            $mbo_bottom .= "<div class='mbo-bottom-col-lbl' style='display:flex;align-items:center;justify-content:space-between;'>";
            $mbo_bottom .= xlt('SERVICE INFO');
            $mbo_bottom .= "<button type='button' class='mbo-notes-btn" . $notes_btn_cls . "' onclick='mboToggleNotes(this)' title='" . xla('Billing Notes') . "'>"
                         . "<svg width='11' height='11' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path d='M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7'/><path d='M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z'/></svg>"
                         . " " . xlt('Notes') . "</button>";
            $mbo_bottom .= "</div>";
            $mbo_bottom .= "<div class='mbo-svc-grid'>";
            $mbo_bottom .= "<div class='mbo-svc-col'><div class='mbo-svc-col-lbl'>POS</div><div class='mbo-svc-col-val'>" . ($mbo_pos ?: '&mdash;') . "</div></div>";
            $mbo_bottom .= "<div class='mbo-svc-col'><div class='mbo-svc-col-lbl'>" . xlt('UNITS') . "</div><div class='mbo-svc-col-val'>" . $mbo_units . "</div></div>";
            $mbo_bottom .= "<div class='mbo-svc-col'><div class='mbo-svc-col-lbl'>" . xlt('FACILITY') . "</div><div class='mbo-svc-col-val'>" . ($mbo_facility ?: '&mdash;') . "</div></div>";
            $mbo_bottom .= "<div class='mbo-svc-col'><div class='mbo-svc-col-lbl'>" . xlt('FEE') . "</div><div class='mbo-svc-col-val'>" . $mbo_fee . "</div></div>";
            $mbo_bottom .= "<div class='mbo-svc-col'><div class='mbo-svc-col-lbl'>" . xlt('BILLED') . "</div><div class='mbo-svc-col-val'>" . $mbo_bill_date . "</div></div>";
            $mbo_bottom .= "<div class='mbo-svc-col'><div class='mbo-svc-col-lbl'>" . xlt('CARRIER') . "</div><div class='mbo-svc-col-val'>" . $mbo_carrier . "</div></div>";
            $mbo_bottom .= "</div>"; // svc-grid

            // ── Billing Notes textarea (hidden by default) ───────────────
            $notes_visible = !empty($mbo_fe['billing_note']) ? 'display:block;' : 'display:none;';
            $notes_btn_cls  = !empty($mbo_fe['billing_note']) ? ' active' : '';
            $mbo_bottom .= "<div class='mbo-notes-wrap' style='margin-top:10px;" . $notes_visible . "'>";
            $mbo_bottom .= "<textarea class='mbo-notes-textarea' data-mbo-field='billing_note'"
                         . " placeholder='" . xla('Add billing notes (saved to Additional Notes in MBO)...') . "'"
                         . " rows='3'>" . $mbo_billing_note . "</textarea>";
            $mbo_bottom .= "</div>";
            $mbo_bottom .= "</div>"; // svcinfo col

            // ── Action buttons ───────────────────────────────────────────
            $mbo_bottom .= "<div class='mbo-bottom-actions'>";
            // Edit MBO popup button
            $mbo_bottom .= "<button type='button' class='mbo-edit-mbo-btn'"
                         . " onclick='mboOpenMboPopup(this," . $mbo_enc_id . "," . $mbo_pid_val . ")'"
                         . " data-mbo-url='" . $mbo_popup_url . "'>";
            $mbo_bottom .= "<svg width='11' height='11' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><path d='M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7'/><path d='M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z'/></svg>";
            $mbo_bottom .= " " . xlt('Edit MBO') . "</button>";
            // Save MBO button
            $mbo_bottom .= "<button class='mbo-save-btn' onclick=\"mboSave(this," . $mbo_enc_id . "," . $mbo_pid_val . "," . $mbo_billing_id . ")\">";
            $mbo_bottom .= "<svg width='11' height='11' fill='none' stroke='currentColor' stroke-width='2.5' viewBox='0 0 24 24'><polyline points='20 6 9 17 4 12'/></svg>";
            $mbo_bottom .= " " . xlt('Save MBO') . "</button>";
            $mbo_bottom .= "<div class='mbo-save-ok'>&#10003; " . xlt('Saved') . "</div>";
            $mbo_bottom .= "</div>"; // actions

            $mbo_bottom .= "</div></div>"; // grid + mbo-bottom
            // Store MBO panel for output AFTER payment lines (not in $print)
            $GLOBALS['pending_mbo_bottom'] = $mbo_bottom;

            $total_units  += $erow['units'];
            $total_chg += $erow['fee'];
            $total_bal += $erow['fee'];
            $enc_units  += $erow['units'];
            $enc_chg += $erow['fee'];
            $enc_bal += $erow['fee'];
            // If patient has insurance, all charges go to insurance balance
            // Only goes to patient balance if truly self-pay (no insurance on file)
            if ($patient_has_insurance || !empty($erow['payer_id'])) {
                $enc_ins_bal   += $erow['fee'];
                $total_ins_bal += $erow['fee'];
            } else {
                $enc_pat_bal   += $erow['fee'];
                $total_pat_bal += $erow['fee'];
            }
            $orow++;

            if ($_REQUEST['form_csvexport']) {
                echo $csv;
            } else {
                echo $print;
            }
        }

        $prev_encounter_id = $erow['encounter'];
        $prev_row = $erow;
    }

    if ($prev_encounter_id != -1) {
        $credits = GetAllCredits($prev_encounter_id, $form_pid);
        if (count($credits) > 0) {
            if (!$hdr_printed) {
                $pre_pmt = 0; $pre_adj = 0; $pre_ins_pmt = 0; $pre_pat_pmt = 0; $pre_ins_adj = 0;
                foreach ($credits as $c) {
                    $pre_pmt += (float)($c['pay_amount'] ?? 0);
                    $pre_adj += (float)($c['adj_amount'] ?? 0);
                    $c_is_ins = !empty($c['payer_id']);
                    if ($c_is_ins) { $pre_ins_pmt += (float)($c['pay_amount'] ?? 0); $pre_ins_adj += (float)($c['adj_amount'] ?? 0); }
                    else           { $pre_pat_pmt += (float)($c['pay_amount'] ?? 0); }
                }
                $pre_ins_bal = $enc_chg - $pre_ins_pmt - $pre_ins_adj;
                $pre_pat_bal = 0 - $pre_pat_pmt;
                PrintEncHeader($prev_row['date'], $prev_row['reason'], $prev_row['provider_id'], $prev_encounter_id, $form_pid, $enc_chg, $pre_pmt, $enc_chg - $pre_pmt - $pre_adj, $pre_ins_bal, $pre_pat_bal);
            }
            ClosePaymentTable();
            PrintCreditDetail($credits, $form_pid);
            CloseLedgerPaymentTable();
            ClosePaymentTable();
        }
        if ($hdr_printed) {
            ClosePaymentTable();
            PrintEncFooter();
        }
    }

// This is the end of the encounter/charge loop -

    // ── MDItek: also show encounters that have payments but no billing codes ──
    $payonly_res = sqlStatement(
        "SELECT DISTINCT fe.encounter, fe.date, fe.reason, fe.provider_id " .
        "FROM form_encounter AS fe " .
        "INNER JOIN ar_activity AS aa ON aa.encounter = fe.encounter AND aa.pid = fe.pid AND aa.deleted IS NULL " .
        "WHERE fe.pid = ? AND fe.date >= ? AND fe.date <= ? " .
        "AND fe.encounter NOT IN (" .
        "  SELECT DISTINCT encounter FROM billing WHERE pid = ? AND activity > 0" .
        ")" .
        "ORDER BY fe.date, fe.encounter",
        array($form_pid, $from_date, $to_date, $form_pid)
    );
    while ($porow = sqlFetchArray($payonly_res)) {
        $credits = GetAllCredits($porow['encounter'], $form_pid);
        if (count($credits) > 0) {
            $pre_pmt = 0; $pre_adj = 0; $pre_ins_pmt = 0; $pre_pat_pmt = 0; $pre_ins_adj = 0;
            foreach ($credits as $c) {
                $pre_pmt += (float)($c['pay_amount'] ?? 0);
                $pre_adj += (float)($c['adj_amount'] ?? 0);
                $c_is_ins = !empty($c['payer_id']);
                if ($c_is_ins) { $pre_ins_pmt += (float)($c['pay_amount'] ?? 0); $pre_ins_adj += (float)($c['adj_amount'] ?? 0); }
                else           { $pre_pat_pmt += (float)($c['pay_amount'] ?? 0); }
            }
            $pre_ins_bal = 0 - $pre_ins_pmt - $pre_ins_adj;
            $pre_pat_bal = 0 - $pre_pat_pmt;
            PrintEncHeader(
                $porow['date'],
                $porow['reason'] ?: xl('Payment'),
                $porow['provider_id'],
                $porow['encounter'],
                $form_pid,
                0,
                $pre_pmt,
                0 - $pre_pmt - $pre_adj,
                $pre_ins_bal,
                $pre_pat_bal
            );
            ClosePaymentTable();
            PrintCreditDetail($credits, $form_pid);
            CloseLedgerPaymentTable();
            ClosePaymentTable();
            ClosePaymentTable();
            PrintEncFooter();
            $orow++;
        }
    }
  
    echo "</div>\n"; // #report_results
    echo "</div>\n"; // .ledger-scroll-area

    // ── Grand total bar — sticky footer inside ledger shell ──────────
    if (!$_REQUEST['form_csvexport'] && $orow) {
        echo "<div class='ledger-footer'>";
        echo "<div class='mdi-grand-total-bar' style='border-radius:0;margin-top:0;'>";
        echo "<span class='mdi-grand-total-bar-lbl'>" . xlt('Grand Totals') . "</span>";
        echo "<div class='mdi-grand-total-figures'>";
        echo "<div class='mdi-grand-total-fig'>"
           . "<span class='mdi-grand-total-fig-lbl'>" . xlt('NET RECEIVABLE') . "</span>"
           . "<span class='mdi-grand-total-fig-val' style='color:#f9a8d4;'>\$" . text(oeFormatMoney($acct_bal)) . "</span>"
           . "</div>";
        echo "<div class='mdi-grand-total-fig'>"
           . "<span class='mdi-grand-total-fig-lbl' style='color:#93c5fd;'>" . xlt('OPEN INSURANCE A/R') . "</span>"
           . "<span class='mdi-grand-total-fig-val' style='color:#93c5fd;'>\$" . text(oeFormatMoney($acct_ins_bal)) . "</span>"
           . "</div>";
        echo "<div class='mdi-grand-total-fig'>"
           . "<span class='mdi-grand-total-fig-lbl' style='color:#fcd34d;'>" . xlt('AVAILABLE PATIENT CREDIT') . "</span>"
           . "<span class='mdi-grand-total-fig-val' style='color:#fcd34d;'>\$" . text(oeFormatMoney($acct_pat_bal)) . "</span>"
           . "</div>";
        echo "</div>";
        echo "</div>"; // .mdi-grand-total-bar
        echo "</div>\n"; // .ledger-footer
    }

    // Always inject all-time account totals into header pills — independent of
    // any date filter so Balance Overview never changes when filtering.
    if (!$_REQUEST['form_csvexport'] && $_REQUEST['form_refresh']) {
        $acct_ins_paid_disp = $acct_chg - max(0, $acct_ins_bal);
        echo "<script>(function(){";
        echo "  var set=function(id,v){var e=document.getElementById(id);if(e)e.textContent=v;};";
        echo "  set('hdr-bal-due','\$"    . oeFormatMoney($acct_bal) . "');";
        echo "  set('hdr-total-chg','\$"  . oeFormatMoney($acct_chg) . "');";
        echo "  set('hdr-ins-paid','\$"   . oeFormatMoney($acct_ins_pmt) . "');";
        echo "  set('hdr-pat-paid','\$"   . oeFormatMoney($acct_pat_pmt) . "');";
        echo "  set('hdr-pat-credit','\$" . oeFormatMoney($acct_pat_bal < 0 ? abs($acct_pat_bal) : 0) . "');";
        echo "  set('hdr-open-ins','\$"   . oeFormatMoney(max(0, $acct_ins_bal)) . "');";
        echo "})();</script>";
    }
    echo "</div>\n"; // .ledger-shell
}

if (! $_REQUEST['form_csvexport']) {
    if ($_REQUEST['form_refresh'] && $orow <= 0) {
        echo "<span style='font-size: 13px;'>";
        echo xlt('No matches found. Try search again.');
        echo "</span>";
        echo '<script>document.getElementById("report_results").style.display="none";</script>';
    }

    if (!$_REQUEST['form_refresh'] && !$_REQUEST['form_csvexport']) { ?>
                    <div style="padding:16px;font-size:13px;color:#6b7280;font-style:italic;">
                        <?php echo xlt('Please input search criteria above, and click Submit to view results.'); ?>
                    </div><?php
    } ?>
        </form>
    </div><!-- .mdi-results-wrap -->

<!-- ══════════════════════════════════════════════════════════
     MBO Dx Code Editor Modal (mirrors demographics.php modal)
══════════════════════════════════════════════════════════ -->
<input type="hidden" id="mdi_csrf_token" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">

<div id="mbo-diag-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;">
  <div style="background:#fff;border-radius:10px;border:1px solid #dde3ee;width:100%;max-width:520px;padding:24px;position:relative;box-shadow:0 8px 32px rgba(30,58,95,.18);font-family:'DM Sans',sans-serif;max-height:calc(100vh - 40px);overflow-y:auto;margin:auto;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
      <span style="font-size:15px;font-weight:700;color:#1a2540;">
        <svg width="15" height="15" fill="none" stroke="#5b21b6" stroke-width="2" viewBox="0 0 24 24" style="margin-right:6px;vertical-align:middle;"><path d='M22 12h-4l-3 9L9 3l-3 9H2'/></svg>
        <?php echo xlt('Edit Dx Codes'); ?>
      </span>
      <button onclick="mboCloseDiagModal()" style="background:none;border:none;font-size:22px;cursor:pointer;color:#8a9bb5;line-height:1;padding:0 4px;">×</button>
    </div>

    <div style="margin-bottom:10px;">
      <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#4a5a72;display:block;margin-bottom:6px;"><?php echo xlt('Selected Codes'); ?></label>
      <div id="mbo-diag-codes-area" style="display:flex;flex-direction:column;gap:5px;min-height:10px;margin-bottom:8px;"></div>
    </div>

    <div style="margin-bottom:16px;">
      <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#4a5a72;display:block;margin-bottom:6px;">
        <?php echo xlt('ICD-10 Codes'); ?> <span style="color:#8a9bb5;font-size:10px;font-weight:400;text-transform:none;">— <?php echo xlt('search and select from the list'); ?></span>
      </label>
      <div style="position:relative;" id="mbo-icd-search-wrap">
        <div style="display:flex;gap:6px;align-items:center;">
          <input id="mbo-icd-search-input" type="text"
                 placeholder="<?php echo attr(xlt('Type to search ICD-10 codes…')); ?>"
                 autocomplete="off"
                 style="flex:1;padding:8px 10px;border:1.5px solid #c8d5e5;border-radius:6px;font-size:13px;font-family:inherit;"
                 oninput="mboIcdSearch(this.value)"
                 onkeydown="mboIcdKeydown(event)">
          <button onclick="mboIcdClear()" type="button"
                  style="background:none;border:1px dashed #c8d5e5;border-radius:6px;padding:7px 12px;font-size:12px;cursor:pointer;color:#4a5a72;font-family:inherit;white-space:nowrap;">
            ✕ <?php echo xlt('Clear'); ?>
          </button>
        </div>
        <div id="mbo-icd-dropdown"
             style="display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;
                    background:#fff;border:1px solid #c8d5e5;border-radius:6px;
                    box-shadow:0 4px 16px rgba(30,58,95,.13);z-index:99999;
                    max-height:220px;overflow-y:auto;font-family:inherit;"></div>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:14px;border-top:1px solid #edf0f7;">
      <button onclick="mboCloseDiagModal()" style="background:transparent;border:1px solid #dde3ee;border-radius:6px;padding:8px 16px;font-size:13px;cursor:pointer;font-family:inherit;color:#1a2540;"><?php echo xlt('Cancel'); ?></button>
      <button onclick="mboConfirmDxCodes()" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff;border:none;border-radius:6px;padding:8px 20px;font-size:13px;cursor:pointer;font-weight:700;font-family:inherit;"><?php echo xlt('Confirm'); ?></button>
    </div>

  </div>
</div>

</body>

</html>
    <?php
} // End not csv export
?>