<?php

/**
 * This processes X12 835 remittances and produces a report.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2006-2020 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019-2020 Stephen Waite <stephen.waite@cmsvt.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Buffer all output so we can archive it to a file.
ob_start();

require_once("../globals.php");

use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Billing\InvoiceSummary;
use OpenEMR\Billing\ParseERA;
use OpenEMR\Billing\SLEOB;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$debug = !empty($_GET['debug']) ? 1 : 0; // set to 1 for debugging mode
$paydate = parse_date($_GET['paydate'] ?? '');
$encount = 0;

$last_ptname = '';
$last_invnumber = '';
$last_code = '';
$invoice_total = 0.00;
$InsertionId; // last inserted ID of

///////////////////////// Assorted Functions /////////////////////////

function parse_date($date)
{
    $date = substr(trim($date), 0, 10);
    if (preg_match('/^(\d\d\d\d)\D*(\d\d)\D*(\d\d)$/', $date, $matches)) {
        return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
    }

    return '';
}

function writeMessageLine($bgcolor, $class, $description, $nl2br_process = "false")
{
    $dline =
    " <tr bgcolor='" . attr($bgcolor) . "'>\n" .
    "  <td class='" . attr($class) . "' colspan='4'></td>\n";
    if ($nl2br_process) {
        $dline .= "  <td class='" . attr($class) . "'>" . nl2br(text($description)) . "</td>\n";
    } else {
        $dline .= "  <td class='" . attr($class) . "'>" . text($description) . "</td>\n";
    }
    $dline .=
    "  <td class='" . attr($class) . "' colspan='2'></td>\n" .
    " </tr>\n";
    echo $dline;
}

function writeDetailLine(
    $bgcolor,
    $class,
    $ptname,
    $invnumber,
    $code,
    $date,
    $description,
    $amount,
    $balance,
    $pid = 0,
    $encounter = 0
) {

    global $last_ptname, $last_invnumber, $last_code;
    $display_ptname = $ptname;
    if ($ptname == $last_ptname) {
        $display_ptname = '';
    } else {
        $last_ptname = $ptname;
    }

    if ($invnumber == $last_invnumber) {
        $invnumber = '';
    } else {
        $last_invnumber = $invnumber;
    }

    if ($code == $last_code) {
        $code = '';
    } else {
        $last_code = $code;
    }

    if ($amount) {
        $amount  = sprintf("%.2f", $amount);
    }

    if ($balance) {
        $balance = sprintf("%.2f", $balance);
    }

    // Render patient name as clickable link to the correct encounter
    if ($display_ptname !== '' && $pid && $encounter) {
        $enc_url = $GLOBALS['webroot']
            . '/interface/patient_file/summary/demographics.php'
            . '?set_pid=' . urlencode($pid)
            . '&set_encounterid=' . urlencode($encounter);
        $ptname_cell = "<a href='" . attr($enc_url) . "' target='_top' "
            . "style='color:inherit;text-decoration:underline;'>"
            . text($display_ptname) . "</a>";
    } else {
        $ptname_cell = text($display_ptname);
    }

    $dline =
    " <tr bgcolor='" . attr($bgcolor) . "'>\n" .
    "  <td class='" . attr($class) . "'>" . $ptname_cell . "</td>\n" .
    "  <td class='" . attr($class) . "'>" . text($invnumber) . "</td>\n" .
    "  <td class='" . attr($class) . "'>" . text($code) . "</td>\n" .
    "  <td class='" . attr($class) . "'>" . text(oeFormatShortDate($date)) . "</td>\n" .
    "  <td class='" . attr($class) . "'>" . text($description) . "</td>\n" .
    "  <td class='" . attr($class) . "' align='right'>" . text(oeFormatMoney($amount)) . "</td>\n" .
    "  <td class='" . attr($class) . "' align='right'>" . text(oeFormatMoney($balance)) . "</td>\n" .
    " </tr>\n";
    echo $dline;
}

    // This writes detail lines that were already in SQL-Ledger for a given
    // charge item.
    //
function writeOldDetail(&$prev, $ptname, $invnumber, $dos, $code, $bgcolor, $pid = 0, $encounter = 0)
{
    global $invoice_total;
    // $prev['total'] = 0.00; // to accumulate total charges
    ksort($prev['dtl']);
    foreach ($prev['dtl'] as $dkey => $ddata) {
        $ddate = substr($dkey, 0, 10);
        $description = ($ddata['src'] ?? '') . ($ddata['rsn'] ?? '');
        if ($ddate == '          ') { // this is the service item
            $ddate = $dos;
            $description = 'Service Item';
        }

        $amount = sprintf(
            "%.2f",
            floatval($ddata['chg'] ?? 0) -
            floatval($ddata['pmt'] ?? 0)
        );
        $invoice_total =
            floatval($invoice_total) +
            floatval($amount);
        writeDetailLine(
            $bgcolor,
            'olddetail',
            $ptname,
            $invnumber,
            $code,
            $ddate,
            $description,
            $amount,
            $invoice_total,
            $pid,
            $encounter
        );
    }
}

    // This is called back by ParseERA::parseERA() once per claim.
    //

// TODO: Sort colors here for Bootstrap themes
function era_callback_check(&$out)
{
    // last inserted ID of ar_session table
    global $InsertionId;
    global $StringToEcho,$debug;

    if (!empty($_GET['original']) && $_GET['original'] == 'original') {
        $StringToEcho .= "<table>";
        $StringToEcho .= "<thead><tr>";
        $StringToEcho .= "<th scope='col'>" . xlt('Check Number') . "</th>";
        $StringToEcho .= "<th scope='col'>" . xlt('Payee Name') . "</th>";
        $StringToEcho .= "<th scope='col'>" . xlt('Payer Name') . "</th>";
        $StringToEcho .= "<th scope='col'>" . xlt('Check Amount') . "</th>";
        $StringToEcho .= "</tr></thead><tbody>";
        $WarningFlag = false;
        for ($check_count = 1; $check_count <= $out['check_count']; $check_count++) {
            $rowClass = $check_count % 2 == 1 ? 'era-row-odd' : 'era-row-even';
            $rs = sqlQ("select reference from ar_session where reference=?", array($out['check_number' . $check_count]));
            $isDupe = sqlNumRows($rs) > 0;
            if ($isDupe) { $rowClass = 'era-row-dupe'; $WarningFlag = true; }

            $StringToEcho .= "<tr class='" . attr($rowClass) . "'>";
            $StringToEcho .= "<td class='era-chk-num'>";
            $StringToEcho .= "<input type='checkbox' name='chk" . attr($out['check_number' . $check_count]) . "' id='chk" . attr($out['check_number' . $check_count]) . "'/>";
            $StringToEcho .= "<label for='chk" . attr($out['check_number' . $check_count]) . "' style='cursor:pointer;'>";
            $StringToEcho .= " " . text($out['check_number' . $check_count]);
            if ($isDupe) $StringToEcho .= " <span style='font-size:9.5px;background:#dc2626;color:#fff;border-radius:4px;padding:1px 6px;margin-left:4px;font-weight:700;'>DUPLICATE</span>";
            $StringToEcho .= "</label></td>";
            $StringToEcho .= "<td>" . text($out['payee_name' . $check_count]) . "</td>";
            $StringToEcho .= "<td>" . text($out['payer_name' . $check_count]) . "</td>";
            $StringToEcho .= "<td style='font-family:\"DM Mono\",monospace;font-weight:700;'>$" . text(number_format($out['check_amount' . $check_count], 2)) . "</td>";
            $StringToEcho .= "</tr>";
        }

        $StringToEcho .= "<tr class='era-action-row'>";
        $StringToEcho .= "<td><button type='button' class='btn-era-check-all' onclick='checkAll(true)'>";
        $StringToEcho .= "<svg width='12' height='12' fill='none' stroke='currentColor' stroke-width='2.5' viewBox='0 0 24 24'><polyline points='20 6 9 17 4 12'/></svg>";
        $StringToEcho .= xlt('Check All') . "</button></td>";
        $StringToEcho .= "<td><button type='submit' name='CheckSubmit' class='btn-era-submit'>";
        $StringToEcho .= "<svg width='12' height='12' fill='none' stroke='currentColor' stroke-width='2.5' viewBox='0 0 24 24'><polyline points='22 12 18 12 15 21 9 3 6 12 2 12'/></svg>";
        $StringToEcho .= xlt('Process Selected') . "</button></td>";
        $StringToEcho .= "<td colspan='2'></td></tr>";
        $StringToEcho .= "</tbody></table>";

        if ($WarningFlag == true) {
            $StringToEcho .= "<div class='era-warning-banner'>";
            $StringToEcho .= "<svg width='14' height='14' fill='none' stroke='currentColor' stroke-width='2' viewBox='0 0 24 24'><circle cx='12' cy='12' r='10'/><line x1='12' y1='8' x2='12' y2='12'/><line x1='12' y1='16' x2='12.01' y2='16'/></svg>";
            $StringToEcho .= xlt('Warning: one or more check numbers already exist in the database. Previously processed ERA files are highlighted in red.');
            $StringToEcho .= "</div>";
        }
    } else {
        for ($check_count = 1; $check_count <= $out['check_count']; $check_count++) {
            $chk_num = $out['check_number' . $check_count];
            $chk_num = str_replace(' ', '_', $chk_num);
            if (isset($_REQUEST['chk' . $chk_num])) {
                $check_date = $out['check_date' . $check_count] ? $out['check_date' . $check_count] : ($_REQUEST['paydate'] ?? '');
                $post_to_date = !empty($_REQUEST['post_to_date']) ? $_REQUEST['post_to_date'] : date('Y-m-d');
                $deposit_date = !empty($_REQUEST['deposit_date']) ? $_REQUEST['deposit_date'] : date('Y-m-d');
                $InsertionId[$out['check_number' . $check_count]] = SLEOB::arPostSession($_REQUEST['InsId'] ?? '', $out['check_number' . $check_count], $out['check_date' . $check_count], $out['check_amount' . $check_count], $post_to_date, $deposit_date, $debug);
            }
        }
    }
}
function era_callback(&$out)
{
    global $encount, $debug;
    global $invoice_total, $last_code, $paydate;
    // last inserted ID of ar_session table
    global $InsertionId;

    // Some heading information.
    $chk_123 = $out['check_number'];
    $chk_123 = str_replace(' ', '_', $chk_123);
    if (isset($_REQUEST['chk' . $chk_123])) {
        if ($encount == 0) {
            writeMessageLine(
                'var(--white)',
                'infdetail',
                "Payer: " . $out['payer_name']
            );
            if ($debug) {
                writeMessageLine(
                    'var(--white)',
                    'infdetail',
                    "WITHOUT UPDATE is selected; no changes will be applied."
                );
            }
        }

        $last_code = '';
        $invoice_total = 0.00;
        $bgcolor = (++$encount & 1) ? "#ddddff" : "#ffdddd";
        list($pid, $encounter, $invnumber) = SLEOB::slInvoiceNumber($out);

        // Get details, if we have them, for the invoice.
        $inverror = true;
        $ferow = array();
        $codes = array();
        if ($pid && $encounter) {
            // Get invoice data into $ferow.
            $ferow = sqlQuery("SELECT e.*, p.fname, p.mname, p.lname " .
            "FROM form_encounter AS e, patient_data AS p WHERE " .
            "e.pid = ? AND e.encounter = ? AND " .
            "p.pid = e.pid", array($pid, $encounter));
            if (empty($ferow)) {
                  $pid = $encounter = 0;
                  $invnumber = $out['our_claim_id'] ?? '';
            } else {
                  $inverror = false;
                  $codes = InvoiceSummary::arGetInvoiceSummary($pid, $encounter, true);
            }
        }

        // Fallback: ERA may send bare billing.id or encounter number without pid- prefix
        if (!$pid || !$encounter) {
            $bare_num = !empty($out['our_claim_id'])
                ? (int) preg_replace('/[^0-9]/', '', $out['our_claim_id']) : 0;

            // Strategy 1: match via claims table encounter_id
            // More reliable than billing.id since payers use their own claim ref numbers
            if (!$pid && $bare_num > 0) {
                $crow = sqlQuery(
                    "SELECT patient_id, encounter_id FROM claims " .
                    "WHERE encounter_id = ? ORDER BY version DESC LIMIT 1",
                    array($bare_num)
                );
                if (!empty($crow)) {
                    $ferow = sqlQuery(
                        "SELECT e.*, p.fname, p.mname, p.lname " .
                        "FROM form_encounter AS e " .
                        "JOIN patient_data AS p ON p.pid = e.pid " .
                        "WHERE e.pid = ? AND e.encounter = ? LIMIT 1",
                        array($crow['patient_id'], $crow['encounter_id'])
                    );
                    if (!empty($ferow)) {
                        $pid = (int)$ferow['pid']; $encounter = (int)$ferow['encounter'];
                        $invnumber = $pid . '-' . $encounter; $inverror = false;
                        $codes = InvoiceSummary::arGetInvoiceSummary($pid, $encounter, true);
                    }
                }
            }

            // Strategy 2: form_encounter.encounter = ERA claim ID
            if ((!$pid || !$encounter) && $bare_num > 0) {
                $ferow = sqlQuery(
                    "SELECT e.*, p.fname, p.mname, p.lname " .
                    "FROM form_encounter AS e " .
                    "JOIN patient_data AS p ON p.pid = e.pid " .
                    "WHERE e.encounter = ? ORDER BY e.date DESC LIMIT 1",
                    array($bare_num)
                );
                if (!empty($ferow)) {
                    $pid = (int)$ferow['pid']; $encounter = (int)$ferow['encounter'];
                    $invnumber = $pid . '-' . $encounter; $inverror = false;
                    $codes = InvoiceSummary::arGetInvoiceSummary($pid, $encounter, true);
                }
            }

            // Strategy 3: billing.encounter = ERA claim ID
            if ((!$pid || !$encounter) && $bare_num > 0) {
                $brow2 = sqlQuery(
                    "SELECT pid, encounter FROM billing WHERE encounter = ? ORDER BY id DESC LIMIT 1",
                    array($bare_num)
                );
                if (!empty($brow2)) {
                    $ferow = sqlQuery(
                        "SELECT e.*, p.fname, p.mname, p.lname " .
                        "FROM form_encounter AS e " .
                        "JOIN patient_data AS p ON p.pid = e.pid " .
                        "WHERE e.pid = ? AND e.encounter = ? LIMIT 1",
                        array($brow2['pid'], $brow2['encounter'])
                    );
                    if (!empty($ferow)) {
                        $pid = (int)$ferow['pid']; $encounter = (int)$ferow['encounter'];
                        $invnumber = $pid . '-' . $encounter; $inverror = false;
                        $codes = InvoiceSummary::arGetInvoiceSummary($pid, $encounter, true);
                    }
                }
            }

            // Strategy 4: match by patient last name + CPT code + DOS from ERA
if ((!$pid || !$encounter) && !empty($out['patient_lname'])) {

    $era_lname = strtolower(trim($out['patient_lname']));
    $era_code  = '';
    $era_dos   = '';

    // Pull CPT code from ERA service lines
    if (!empty($out['svc'])) {
        foreach ($out['svc'] as $s) {
            $era_code = $s['code'] ?? '';
            break;
        }
    }

    // Pull DOS from ERA
    if (!empty($out['dos'])) {
        $era_dos = substr($out['dos'], 0, 10);
    } elseif (!empty($out['claim_date'])) {
        $era_dos = substr($out['claim_date'], 0, 10);
    }

    if ($era_lname && $era_code && $era_dos) {

        $nmrow = sqlQuery(
            "SELECT
                e.pid,
                e.encounter,
                e.date,
                p.fname,
                p.mname,
                p.lname
             FROM billing AS b
             JOIN form_encounter AS e
                ON e.pid = b.pid
                AND e.encounter = b.encounter
             JOIN patient_data AS p
                ON p.pid = b.pid
             WHERE LOWER(p.lname) = ?
             AND b.code = ?
             AND DATE(e.date) = ?
             LIMIT 1",
            array(
                $era_lname,
                $era_code,
                $era_dos
            )
        );

        if (!empty($nmrow)) {

            $ferow = $nmrow;

            $pid = (int)$nmrow['pid'];
            $encounter = (int)$nmrow['encounter'];

            $invnumber = $pid . '-' . $encounter;

            $inverror = false;

            $codes = InvoiceSummary::arGetInvoiceSummary(
                $pid,
                $encounter,
                true
            );
        }
    }
}
        }

        // Show the claim status.
        $csc = $out['claim_status_code'];
        $inslabel = 'Ins1';
        if ($csc == '1' || $csc == '19') {
            $inslabel = 'Ins1';
        }

        if ($csc == '2' || $csc == '20') {
            $inslabel = 'Ins2';
        }

        if ($csc == '3' || $csc == '21') {
            $inslabel = 'Ins3';
        }

        $primary = ($inslabel == 'Ins1');
        writeMessageLine(
            $bgcolor,
            'infdetail',
            "Claim status $csc: " . BillingUtilities::CLAIM_STATUS_CODES_CLP02[$csc]
        );

    // Show an error message if the claim is missing or already posted.
        if ($inverror) {
            writeMessageLine(
                $bgcolor,
                'errdetail',
                "The following claim is not in our database"
            );
        } else {
            // Skip this test. Claims can get multiple CLPs from the same payer!
            //
            // $insdone = strtolower($arrow['shipvia']);
            // if (strpos($insdone, 'ins1') !== false) {
            //  $inverror = true;
            //  writeMessageLine($bgcolor, 'errdetail',
            //   "Primary insurance EOB was already posted for the following claim");
            // }
        }

        if ($csc == '4') {//Denial case, code is stored in the claims table for display in the billing manager screen with reason explained.
            $inverror = true;
            if (!$debug) {
                if ($pid && $encounter) {
                    $code_value = '';
                    foreach ($out['svc'] as $svc) {
                        foreach ($svc['adj'] as $adj) {//Per code and modifier the reason will be showed in the billing manager.
                            $code_value .= $svc['code'] . '_' . $svc['mod'] . '_' . $adj['group_code'] . '_' . $adj['reason_code'] . ',';
                        }
                    }

                    $code_value = substr($code_value, 0, -1);
                    //We store the reason code to display it with description in the billing manager screen.
                    //process_file is used as for the denial case file name will not be there, and extra field(to store reason) can be avoided.
                    BillingUtilities::updateClaim(true, $pid, $encounter, $_REQUEST['InsId'] ?? '', substr($inslabel, 3), 7, 0, $code_value);
                }
            }

            writeMessageLine(
                $bgcolor,
                'errdetail',
                "Not posting adjustments for denied claims, please follow up manually!"
            );
        } elseif ($csc == '22') {
            $inverror = true;
            writeMessageLine(
                $bgcolor,
                'errdetail',
                "Payment reversals are not automated, please enter manually!"
            );
        }

        if ($out['warnings']) {
            writeMessageLine($bgcolor, 'infdetail', rtrim($out['warnings']), true);
        }

    // Simplify some claim attributes for cleaner code.
        $service_date = parse_date(isset($out['dos']) ? $out['dos'] : $out['claim_date']);
        $check_date      = $paydate ? $paydate : parse_date($out['check_date']);
        $production_date = $paydate ? $paydate : parse_date($out['production_date']);

        $insurance_id = SLEOB::arGetPayerID($pid, $service_date, substr($inslabel, 3));
        if (empty($ferow['lname'])) {
              $patient_name = $out['patient_fname'] . ' ' . $out['patient_lname'];
        } else {
            $patient_name = $ferow['fname'] . ' ' . $ferow['lname'];
        }

        $error = $inverror;

    // This loops once for each service item in this claim.
        foreach ($out['svc'] as $svc) {
          // Treat a modifier in the remit data as part of the procedure key.
          // This key will then make its way into SQL-Ledger.
            $codekey = $svc['code'];
            if ($svc['mod']) {
                $codekey .= ':' . $svc['mod'];
            }

            $prev = $codes[$codekey] ?? '';

            if (empty($prev)) {
                $bare_codekey = explode(':', $codekey)[0];
                $prev = $codes[$bare_codekey] ?? '';
            }
            $codetype = ''; //will hold code type, if exists

            // This reports detail lines already on file for this service item.
            if ($prev) {
                $codetype = $prev['code_type'] ?? ''; //store code type
                writeOldDetail($prev, $patient_name, $invnumber, $service_date, $codekey, $bgcolor, $pid, $encounter);
                // Check for sanity in amount charged.
                $prevchg = sprintf(
                    "%.2f",
                    floatval($prev['chg'] ?? 0) +
                    floatval($prev['adj'] ?? 0)
                );
                if ($prevchg != abs($svc['chg'])) {
                    writeMessageLine(
                        $bgcolor,
                        'errdetail',
                        "EOB charge amount " . $svc['chg'] . " for this code does not match our invoice"
                    );
                    writeMessageLine(
                        $bgcolor,
                        'infdetail',
                        "Charge mismatch tolerated automatically"
                    );
                }

                // Check for already-existing primary remittance activity.
                // Removed this check because it was not allowing for copays manually
                // entered into the invoice under a non-copay billing code.
                /****
            if ((sprintf("%.2f",$prev['chg']) != sprintf("%.2f",$prev['bal']) ||
                $prev['adj'] != 0) && $primary)
            {
                writeMessageLine($bgcolor, 'errdetail',
                    "This service item already has primary payments and/or adjustments!");
                $error = true;
            }
                ****/

                unset($codes[$codekey]);

                $bare_codekey = explode(':', $codekey)[0];
                unset($codes[$bare_codekey]);
            } else { // If the service item is not in our database...
                // Before adding a new charge, check if the code already exists in billing
                // for this encounter (can happen when ERA claim ID doesn't match our DB IDs
                // and we resolved via name+CPT fallback - codes[] may be empty but
                // billing row exists under a slightly different key format)
                $bare_codekey = explode(':', $codekey)[0]; // strip modifier for billing lookup
                $existing_charge = sqlQuery(
                    "SELECT id FROM billing
                     WHERE pid = ?
                     AND encounter = ?
                     AND activity = 1
                     AND (
                        code = ?
                        OR code = ?
                     )
                     LIMIT 1",
                    array(
                        $pid,
                        $encounter,
                        $bare_codekey,
                        $codekey
                    )
                );

                $description = "CPT4:$codekey Added by $inslabel $production_date";
                if (!$error && !$debug && empty($existing_charge)) {
                    // Only post new charge if one doesn't already exist
                    SLEOB::arPostCharge(
                        $pid,
                        $encounter,
                        0,
                        $svc['chg'],
                        1,
                        $service_date,
                        $codekey,
                        $description,
                        $debug,
                        '',
                        $codetype ?? ''
                    );
                    $invoice_total += $svc['chg'];
                } elseif (!empty($existing_charge)) {
                    // Charge already exists - reload codes so payment posts correctly
                    $codes = InvoiceSummary::arGetInvoiceSummary($pid, $encounter, true);
                    $prev  = $codes[$codekey] ?? ($codes[$bare_codekey] ?? '');
                    if ($prev) {
                        $codetype = $prev['code_type'] ?? '';
                        $invoice_total += $prev['chg'] ?? 0;
                    }
                    $description = "CPT4:$codekey (existing charge) $inslabel $production_date";
                }

                $class = $error ? 'errdetail' : 'newdetail';
                writeDetailLine(
                    $bgcolor,
                    $class,
                    $patient_name,
                    $invnumber,
                    $codekey,
                    $production_date,
                    $description,
                    $svc['chg'],
                    ($error ? '' : $invoice_total),
                    $pid,
                    $encounter
                );
            }

            $class = $error ? 'errdetail' : 'newdetail';

            // Report Allowed Amount.
            if (isset($svc['allowed']) && floatval($svc['allowed']) != 0) {
                writeMessageLine(
                    $bgcolor,
                    'infdetail',
                    'Allowed amount is ' . sprintf("%.2f", $svc['allowed'])
                );
            }

            // Report miscellaneous remarks.
            if ($svc['remark'] ?? '') {
                $rmk = $svc['remark'];
                writeMessageLine($bgcolor, 'infdetail', "$rmk: " .
                    BillingUtilities::REMITTANCE_ADVICE_REMARK_CODES[$rmk]);
            }

            // Post and report the payment for this service item from the ERA.
            // By the way a 'Claim' level payment is probably going to be negative,
            // i.e. a payment reversal.
            if (isset($svc['paid']) && floatval($svc['paid']) != 0) {
                if (!$error && !$debug) {
                    SLEOB::arPostPayment(
                        $pid,
                        $encounter,
                        $InsertionId[$out['check_number']],
                        $svc['paid'], //$InsertionId[$out['check_number']] gives the session id
                        $codekey,
                        substr($inslabel, 3),
                        $out['check_number'],
                        $debug,
                        '',
                        $codetype,
                        $date ?? null,
                        $out['payer_claim_id']
                    );
                    $invoice_total =
                        floatval($invoice_total) -
                        floatval($svc['paid']);
                }

                $description = "$inslabel/" . $out['check_number'] . ' payment';
                if (floatval($svc['paid']) < 0) {
                    $description .= ' reversal';
                }

                writeDetailLine(
                    $bgcolor,
                    $class,
                    $patient_name,
                    $invnumber,
                    $codekey,
                    $check_date,
                    $description,
                    0 - $svc['paid'],
                    ($error ? '' : $invoice_total),
                    $pid,
                    $encounter
                );
            }

            // Post and report adjustments from this ERA.  Posted adjustment reasons
            // must be 25 characters or less in order to fit on patient statements.
            foreach ($svc['adj'] as $adj) {
                $reason_code = $adj['reason_code'] ?? '';

                $description = $reason_code . ': ' .
                    (BillingUtilities::CLAIM_ADJUSTMENT_REASON_CODES[$reason_code] ?? '');
                if ($adj['group_code'] == 'PR' || !$primary) {
                    // Group code PR is Patient Responsibility.  Enter these as zero
                    // adjustments to retain the note without crediting the claim.
                    if ($primary) {
                /****
                    $reason = 'Pt resp: '; // Reasons should be 25 chars or less.
                    if ($adj['reason_code'] == '1') $reason = 'To deductible: ';
                    else if ($adj['reason_code'] == '2') $reason = 'Coinsurance: ';
                    else if ($adj['reason_code'] == '3') $reason = 'Co-pay: ';
                ****/
                        $reason = "$inslabel ptresp: "; // Reasons should be 25 chars or less.
                        if ($adj['reason_code'] == '1') {
                            $reason = "$inslabel dedbl: ";
                        } elseif ($adj['reason_code'] == '2') {
                            $reason = "$inslabel coins: ";
                        } elseif ($adj['reason_code'] == '3') {
                            $reason = "$inslabel copay: ";
                        }
                    } else { // Non-primary insurance adjustments are garbage, either repeating
                        // the primary or are not adjustments at all.  Report them as notes
                        // but do not post any amounts.
                        $reason = "$inslabel note " . $adj['reason_code'] . ': ';
                /****
                    $reason .= sprintf("%.2f", $adj['amount']);
                ****/
                    }

                    $reason .= sprintf("%.2f", $adj['amount']);
                    // Post a zero-dollar adjustment just to save it as a comment.
                    if (!$error && !$debug) {
                        SLEOB::arPostAdjustment(
                            $pid,
                            $encounter,
                            $InsertionId[$out['check_number']],
                            0,
                            $codekey, //$InsertionId[$out['check_number']] gives the session id
                            substr($inslabel, 3),
                            $reason,
                            $debug,
                            '',
                            $codetype,
                            $out['payer_claim_id']
                        );
                    }

                    writeMessageLine($bgcolor, $class, $description . ' ' .
                    sprintf("%.2f", $adj['amount']));
                } else { // Other group codes for primary insurance are real adjustments.
                    if (!$error && !$debug) {
                        SLEOB::arPostAdjustment(
                            $pid,
                            $encounter,
                            $InsertionId[$out['check_number']],
                            $adj['amount'], //$InsertionId[$out['check_number']] gives the session id
                            $codekey,
                            substr($inslabel, 3),
                            "Adjust code " . $adj['reason_code'],
                            $debug,
                            '',
                            $codetype ?? '',
                            $out['payer_claim_id']
                        );
                        $invoice_total =
                            floatval($invoice_total) -
                            floatval($adj['amount']);
                    }

                    writeDetailLine(
                        $bgcolor,
                        $class,
                        $patient_name,
                        $invnumber,
                        $codekey,
                        $production_date,
                        $description,
                        0 - $adj['amount'],
                        ($error ? '' : $invoice_total),
                        $pid,
                        $encounter
                    );
                }
            }
        } // End of service item

    // Report any existing service items not mentioned in the ERA, and
    // determine if any of them are still missing an insurance response
    // (if so, then insurance is not yet done with the claim).
        $insurance_done = true;
        foreach ($codes as $code => $prev) {
          // writeOldDetail($prev, $arrow['name'], $invnumber, $service_date, $code, $bgcolor);
            writeOldDetail($prev, $patient_name, $invnumber, $service_date, $code, $bgcolor, $pid, $encounter);
            $got_response = false;
            foreach ($prev['dtl'] as $ddata) {
                if ($ddata['pmt'] ?? '' || ($ddata['rsn'] ?? '')) {
                    $got_response = true;
                }
            }

            if (!$got_response) {
                $insurance_done = false;
            }
        }

    // Cleanup: If all is well, mark Ins<x> done and check for secondary billing.
        if (!$error && !$debug && $insurance_done) {
            $level_done = 0 + substr($inslabel, 3);

            if ($out['crossover'] == 1) {//Automatic forward case.So need not again bill from the billing manager screen.
                sqlStatement("UPDATE form_encounter " .
                "SET last_level_closed = ?,last_level_billed=? WHERE " .
                "pid = ? AND encounter = ?", array($level_done, $level_done, $pid, $encounter));
                writeMessageLine(
                    $bgcolor,
                    'infdetail',
                    'This claim is processed by Insurance ' . $level_done . ' and automatically forwarded to Insurance ' . ($level_done + 1) . ' for processing. '
                );
            } else {
                sqlStatement("UPDATE form_encounter " .
                "SET last_level_closed = ? WHERE " .
                "pid = ? AND encounter = ?", array($level_done, $pid, $encounter));
            }

            // Check for secondary insurance.
            if ($primary && SLEOB::arGetPayerID($pid, $service_date, 2)) {
                SLEOB::arSetupSecondary($pid, $encounter, $debug, $out['crossover']);

                if ($out['crossover'] <> 1) {
                    writeMessageLine(
                        $bgcolor,
                        'infdetail',
                        'This claim is now re-queued for secondary paper billing'
                    );
                }
            }
        }
    }
}

/////////////////////////// End Functions ////////////////////////////

$info_msg = "";

if (!CsrfUtils::verifyCsrfToken($_GET["csrf_token_form"] ?? "")) {
    CsrfUtils::csrfNotVerified();
}

$eraname = $_GET['eraname'] ?? '';

if (! $eraname) {
    die(xlt("You cannot access this page directly."));
}

    // Open the output file early so that in case it fails, we do not post a
    // bunch of stuff without saving the report.  Also be sure to retain any old
    // report files.  Do not save the report if this is a no-update situation.
    //
if (!$debug) {
    $nameprefix = $GLOBALS['OE_SITE_DIR'] . "/documents/era/$eraname";
    $namesuffix = '';
    for ($i = 1; is_file("$nameprefix$namesuffix.html"); ++$i) {
        $namesuffix = "_$i";
    }

    $fnreport = "$nameprefix$namesuffix.html";
    $fhreport = fopen($fnreport, 'w');
    if (!$fhreport) {
        die(xlt("Cannot create") . " '" . text($fnreport) . "'");
    }
}

?>
<html>
<head>
<?php Header::setupHeader(); ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
    :root {
        --navy:        #1a1f3a;
        --navy-mid:    #2d3461;
        --purple:      #6366f1;
        --purple-pale: #f0f0ff;
        --green:       #16a34a;
        --green-pale:  #f0fdf4;
        --red:         #dc2626;
        --red-pale:    #fff1f1;
        --amber:       #d97706;
        --amber-pale:  #fffbeb;
        --blue-pale:   #eff6ff;
        --border:      #e5e7eb;
        --text1:       #111827;
        --text2:       #4b5563;
        --text3:       #9ca3af;
        --white:       #ffffff;
        --surface:     #f9fafb;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        background: var(--surface);
        color: var(--text1);
        margin: 0;
        padding: 0;
    }

    /* ── Page chrome ── */
    .era-page-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 24px 48px; }

    .era-page-header {
        background: var(--navy);
        padding: 14px 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 0;
    }
    .era-page-header h1 {
        margin: 0;
        font-size: 14px;
        font-weight: 700;
        color: #fff;
        letter-spacing: .03em;
    }
    .era-page-header .era-sub {
        font-size: 11px;
        color: rgba(255,255,255,.55);
        margin-left: auto;
        font-family: 'DM Mono', monospace;
    }

    /* ── Check confirmation card (original=original) ── */
    .era-check-card {
        background: var(--white);
        border-radius: 10px;
        border: 1px solid var(--border);
        box-shadow: 0 1px 6px rgba(0,0,0,.06);
        overflow: hidden;
        margin-top: 20px;
    }
    .era-check-card table { width: 100%; border-collapse: collapse; }
    .era-check-card thead tr {
        background: var(--navy);
    }
    .era-check-card thead th {
        padding: 10px 14px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .07em;
        color: rgba(255,255,255,.8);
        text-align: left;
    }
    .era-check-card tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
    .era-check-card tbody tr:hover td { background: var(--purple-pale) !important; }
    .era-check-card tbody td, .era-check-card tbody th[scope=row] {
        padding: 10px 14px;
        font-size: 12.5px;
        vertical-align: middle;
    }

    /* Row states */
    .era-row-odd   { background: #f8f9ff; }
    .era-row-even  { background: var(--white); }
    .era-row-dupe  { background: #fff1f1 !important; }
    .era-row-dupe td, .era-row-dupe th { color: var(--red) !important; font-weight: 700; }

    /* Checkbox styling */
    .era-check-card input[type=checkbox] {
        width: 15px; height: 15px;
        accent-color: var(--purple);
        cursor: pointer;
        vertical-align: middle;
        margin-right: 6px;
    }
    .era-chk-num {
        font-family: 'DM Mono', monospace;
        font-size: 12px;
        font-weight: 600;
    }

    /* Action row */
    .era-action-row td {
        padding: 12px 14px !important;
        background: var(--surface) !important;
        border-top: 2px solid var(--border) !important;
    }
    .btn-era-check-all {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 16px;
        background: var(--white);
        border: 1.5px solid var(--border);
        border-radius: 6px;
        font-size: 12px; font-weight: 600;
        color: var(--text2);
        cursor: pointer;
        font-family: 'DM Sans', sans-serif;
        transition: border-color .15s, color .15s;
    }
    .btn-era-check-all:hover { border-color: var(--purple); color: var(--purple); }
    .btn-era-submit {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 7px 20px;
        background: var(--purple);
        border: none;
        border-radius: 6px;
        font-size: 12px; font-weight: 700;
        color: #fff;
        cursor: pointer;
        font-family: 'DM Sans', sans-serif;
        transition: filter .15s;
    }
    .btn-era-submit:hover { filter: brightness(1.1); }

    /* Warning banner */
    .era-warning-banner {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 16px;
        background: #fff1f1;
        border: 1px solid #fca5a5;
        border-radius: 7px;
        margin: 14px 0 4px;
        font-size: 12px; font-weight: 600;
        color: var(--red);
    }

    /* ── Detail posting table ── */
    .era-detail-card {
        background: var(--white);
        border-radius: 10px;
        border: 1px solid var(--border);
        box-shadow: 0 1px 6px rgba(0,0,0,.06);
        overflow: hidden;
        margin-top: 20px;
    }
    .era-detail-card table { width: 100%; border-collapse: collapse; }
    .dehead {
        background: var(--navy) !important;
        padding: 9px 12px !important;
        font-size: 9.5px !important;
        font-weight: 700 !important;
        text-transform: uppercase !important;
        letter-spacing: .07em !important;
        color: rgba(255,255,255,.8) !important;
        font-family: 'DM Sans', sans-serif !important;
        border: none !important;
    }

    /* Detail row types */
    .olddetail { font-size: 12px; color: var(--text2); padding: 7px 12px !important; }
    .newdetail  { font-size: 12px; color: var(--green); font-weight: 600; padding: 7px 12px !important; }
    .errdetail  { font-size: 12px; color: var(--red);   font-weight: 600; padding: 7px 12px !important; }
    .infdetail  { font-size: 12px; color: var(--purple); padding: 7px 12px !important; }

    /* Alternating row colors for detail table */
    tr[bgcolor="#ddddff"] td { background: var(--blue-pale) !important; }
    tr[bgcolor="#ffdddd"] td { background: #fff8f8 !important; }
    tr[bgcolor="var(--white)"] td { background: var(--white) !important; }
    tr[bgcolor="#ff0000"] td { background: var(--red-pale) !important; color: var(--red) !important; font-weight: 700; }

    /* Amount/balance columns */
    td[align=right], th[align=right] {
        text-align: right;
        font-family: 'DM Mono', monospace;
        font-size: 11.5px;
    }

    /* Totals / info message row */
    .era-totals-row td { 
        padding: 10px 14px !important;
        background: var(--green-pale) !important;
        font-weight: 700;
        color: var(--green);
        font-family: 'DM Mono', monospace;
        border-top: 2px solid #bbf7d0 !important;
    }
</style>
<title><?php echo xlt('ERA Posting — EOB Processing'); ?></title>
</head>
<body class='m-0'>
<div class="era-page-header">
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="color:rgba(255,255,255,.7);flex-shrink:0;"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
    <h1><?php echo xlt("ERA Posting — Electronic Remittances"); ?></h1>
    <?php if ($eraname): ?>
    <span class="era-sub"><?php echo text($eraname); ?></span>
    <?php endif; ?>
</div>
<div class="era-page-wrap">
<form action="sl_eob_process.php" method="get">
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

<?php
if (!empty($_GET['original']) && $_GET['original'] == 'original') {
    $alertmsg = ParseERA::parseERAForCheck($GLOBALS['OE_SITE_DIR'] . "/documents/era/$eraname.edi", 'era_callback');
    echo '<div class="era-check-card">';
    echo $StringToEcho;
    echo '</div>';
} else {
    ?>
    <div class="era-detail-card">
    <table class='w-100' cellpadding='0' cellspacing='0'>
     <thead>
     <tr>
      <th class="dehead"><?php echo xlt('Patient'); ?></th>
      <th class="dehead"><?php echo xlt('Invoice'); ?></th>
      <th class="dehead"><?php echo xlt('Code'); ?></th>
      <th class="dehead"><?php echo xlt('Date'); ?></th>
      <th class="dehead"><?php echo xlt('Description'); ?></th>
      <th class="dehead" align="right"><?php echo xlt('Amount'); ?></th>
      <th class="dehead" align="right"><?php echo xl('Balance'); ?></th>
     </tr>
     </thead>
     <tbody>

    <?php
    global $InsertionId;

    $eraname = $_REQUEST['eraname'] ?? '';
    $alertmsg = ParseERA::parseERAForCheck($GLOBALS['OE_SITE_DIR'] . "/documents/era/$eraname.edi");
    $alertmsg = ParseERA::parseERA($GLOBALS['OE_SITE_DIR'] . "/documents/era/$eraname.edi", 'era_callback');
    if (!$debug) {
          $StringIssue = xl("Total Distribution for following check number is not full") . ': ';
          $StringPrint = 'No';
        if (is_countable($InsertionId)) {
            foreach ($InsertionId as $key => $value) {
                $rs = sqlQ("select pay_total from ar_session where session_id=?", array($value));
                $row = sqlFetchArray($rs);
                $pay_total = $row['pay_total'];
                $rs = sqlQ(
                    "SELECT
                        SUM(pay_amount) AS sum_pay_amount,
                        SUM(adj_amount) AS sum_adj_amount
                     FROM ar_activity
                     WHERE deleted IS NULL
                     AND session_id = ?",
                    array($value)
                );
                
                $row = sqlFetchArray($rs);
                
                $pay_amount = floatval($row['sum_pay_amount']);
                $adj_amount = floatval($row['sum_adj_amount']);
                
                // EFT/check reconciliation should ONLY use payments
                if (round($pay_total - $pay_amount, 2) != 0) {
                    $StringIssue .= $key . ' ';
                    $StringPrint = 'Yes';
                }
                
                // Optional adjustment audit
                if ($adj_amount == 0) {
                    error_log("ERA session $value posted without adjustments");
                }
            }
        }

        if ($StringPrint == 'Yes') {
            echo "<script>alert(" . js_escape($StringIssue) . ")</script>";
        }
    }


    ?>
    </tbody></table></div>
    <?php
}
?>
<script>
<?php
if ($alertmsg) {
    echo " alert(" . js_escape($alertmsg) . ");\n";
}
?>
function checkAll(checked) {
    var f = document.forms[0];
    for (var i = 0; i < f.elements.length; ++i) {
        var etype = f.elements[i].type;
        if (etype === 'checkbox')
            f.elements[i].checked = checked;
    }
}
</script>
<input type="hidden" name="paydate" value="<?php echo attr(DateToYYYYMMDD($_REQUEST['paydate'] ?? '')); ?>" />
<input type="hidden" name="post_to_date" value="<?php echo attr(DateToYYYYMMDD($_REQUEST['post_to_date'] ?? '')); ?>" />
<input type="hidden" name="deposit_date" value="<?php echo attr(DateToYYYYMMDD($_REQUEST['deposit_date'] ?? '')); ?>" />
<input type="hidden" name="debug" value="<?php echo attr($_REQUEST['debug'] ?? ''); ?>" />
<input type="hidden" name="InsId" value="<?php echo attr($_REQUEST['InsId'] ?? ''); ?>" />
<input type="hidden" name="eraname" value="<?php echo attr($eraname); ?>" />
</form>
</div><!-- /era-page-wrap -->
</body>
</html>
<?php
    // Save all of this script's output to a report file.
if (!$debug) {
    fwrite($fhreport, ob_get_contents());
    fclose($fhreport);
}

    ob_end_flush();
?>
