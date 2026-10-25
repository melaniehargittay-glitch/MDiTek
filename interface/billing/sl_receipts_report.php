<?php

/**
 * Report - Cash receipts by Provider
 *
 * This module was written for one of my clients to report on cash
 * receipts by practitioner.  It is not as complete as it should be
 * but I wanted to make the code available to the project because
 * many other practices have this same need. - rod@sunsetsystems.com
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Terry Hill <terry@lillysystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2006-2020 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2016 Terry Hill <terry@lillysystems.com>
 * @copyright Copyright (c) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// TODO: Replace tables with BS4 grid classes for GSoC


require_once('../globals.php');
require_once($GLOBALS['srcdir'] . '/patient.inc.php');
require_once($GLOBALS['srcdir'] . '/options.inc.php');
require_once($GLOBALS['fileroot'] . '/custom/code_types.inc.php');
// This determines if a particular procedure code corresponds to receipts
// for the "Clinic" column as opposed to receipts for the practitioner.  Each
// practice will have its own policies in this regard, so you'll probably
// have to customize this function.  If you use the "fee sheet" encounter
// form then the code below may work for you.
//
require_once('../forms/fee_sheet/codes.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('acct', 'rep') && !AclMain::aclCheckCore('acct', 'rep_a')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Cash Receipts by Provider")]);
    exit;
}

function is_clinic($code)
{
    global $bcodes;
    $i = strpos($code, ':');
    if ($i) {
        $code = substr($code, 0, $i);
    }

    return (
        !empty($bcodes['CPT4'][xl('Lab')][$code])   ||
        !empty($bcodes['CPT4'][xl('Immunizations')][$code]) ||
        !empty($bcodes['HCPCS'][xl('Therapeutic Injections')][$code])
    );
}

function bucks($amount)
{
    if ($amount) {
        echo attr(oeFormatMoney($amount));
    }
}

$form_use_edate  = $_POST['form_use_edate'] ?? null;

$form_proc_codefull = trim($_POST['form_proc_codefull'] ?? '');
// Parse the code type and the code from <code_type>:<code>
$tmp_code_array = explode(':', $form_proc_codefull);
$form_proc_codetype = $tmp_code_array[0];
$form_proc_code = $tmp_code_array[1] ?? null;

$form_dx_codefull  = trim($_POST['form_dx_codefull'] ?? '');
// Parse the code type and the code from <code_type>:<code>
$tmp_code_array = explode(':', $form_dx_codefull);
$form_dx_codetype = $tmp_code_array[0];
$form_dx_code = $tmp_code_array[1] ?? null;

$form_procedures = empty($_POST['form_procedures']) ? 0 : 1;
$form_from_date = (isset($_POST['form_from_date'])) ? DateToYYYYMMDD($_POST['form_from_date']) : date('Y-m-01');
$form_to_date   = (isset($_POST['form_to_date'])) ? DateToYYYYMMDD($_POST['form_to_date']) : date('Y-m-d');

$form_facility   = $_POST['form_facility'] ?? null;
?>
<html>
<head>

    <title><?php echo xlt('Cash Receipts by Provider')?></title>

    <?php Header::setupHeader(['datetime-picker', 'report-helper']); ?>

    <style>
        /* MDI Report Layout */
        .mdi-report-header {
            background: #1e0a3c;
            color: #fff;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .mdi-filter-bar {
            background: #f8f7fc;
            border-bottom: 1px solid #e0d8f4;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px 16px;
        }
        .mdi-filter-group {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #5b21b6;
            white-space: nowrap;
        }
        .mdi-filter-group .form-control {
            height: 30px;
            font-size: 12.5px;
            padding: 3px 8px;
            border-radius: 5px;
            border: 1px solid #d1c7f0;
            background: #fff;
            color: #1e0a3c;
            min-width: 110px;
        }
        .mdi-filter-group select.form-control { min-width: 130px; }
        .mdi-filter-group input[type=text].form-control { min-width: 100px; }
        .mdi-filter-group label {
            display: flex; align-items: center; gap: 5px;
            font-size: 11.5px; font-weight: 600; color: #374151;
            text-transform: none; letter-spacing: 0;
            cursor: pointer; margin: 0;
        }
        .mdi-filter-group input[type=checkbox] { margin: 0; cursor: pointer; }
        .mdi-btn-submit {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 14px; border-radius: 6px;
            background: #5b21b6; color: #fff !important; border: none;
            font-size: 12px; font-weight: 700; cursor: pointer;
            transition: background .15s; white-space: nowrap;
            text-decoration: none;
        }
        .mdi-btn-submit:hover { background: #4c1d95; color: #fff !important; }
        .mdi-btn-secondary {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 6px;
            background: #fff; color: #5b21b6 !important;
            border: 1px solid #d1c7f0;
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: all .15s; white-space: nowrap;
            text-decoration: none;
        }
        .mdi-btn-secondary:hover { background: #5b21b6; color: #fff !important; border-color: #5b21b6; }
        .mdi-results-wrap { padding: 12px 16px; }

        @media print {
            .mdi-report-header, .mdi-filter-bar { display: none; }
            #report_parameters_daterange { visibility: visible; display: inline; }
            #report_results { margin-top: 0; }
            .mdi-results-wrap { padding: 0; }
        }
        @media screen {
            #report_parameters_daterange { visibility: hidden; display: none; }
        }
    </style>

    <script>
        $(function () {
            oeFixedHeaderSetup(document.getElementById('mymaintable'));
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

        // This is for callback by the find-code popup.
        // Erases the current entry
        // The target element is set by the find-code popup
        //  (this allows use of this in multiple form elements on the same page)
        function set_related_target(codetype, code, selector, codedesc, target_element, limit=0) {
            var f = document.forms[0];
            var s = f[target_element].value;
            if (code) {
                s = codetype + ':' + code;
            } else {
                s = '';
            }
            f[target_element].value = s;
        }

        // This invokes the find-code (procedure/service codes) popup.
        function sel_procedure() {
            dlgopen('../patient_file/encounter/find_code_popup.php?target_element=form_proc_codefull&codetype=' + <?php echo js_url(collect_codetypes("procedure", "csv")); ?>, '_blank', 500, 400);
        }

        // This invokes the find-code (diagnosis codes) popup.
        function sel_diagnosis() {
            dlgopen('../patient_file/encounter/find_code_popup.php?target_element=form_dx_codefull&codetype=' + <?php echo js_url(collect_codetypes("diagnosis", "csv")); ?>, '_blank', 500, 400);
        }

    </script>
</head>

<body class="body_top">

<!-- Page Title Bar -->
<div class="mdi-report-header"><?php echo xlt('Report - Cash Receipts by Provider'); ?></div>

<form method='post' action='sl_receipts_report.php' id='theform' onsubmit='return top.restoreSession()'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type='hidden' name='form_refresh' id='form_refresh' value=''/>

<!-- Filter Bar -->
<div id="report_parameters" class="mdi-filter-bar">

    <span class="mdi-filter-group">
        <?php echo xlt('Facility'); ?>:
        <?php dropdown_facility($form_facility, 'form_facility'); ?>
    </span>

    <span class="mdi-filter-group">
        <?php echo xlt('Provider'); ?>:
        <?php
        if (AclMain::aclCheckCore('acct', 'rep_a')) {
            $query = "select id, lname, fname from users where authorized = 1 order by lname, fname";
            $res = sqlStatement($query);
            echo "<select name='form_doctor' class='form-control'>";
            echo "<option value=''>-- " . xlt('All Providers') . " --</option>";
            while ($row = sqlFetchArray($res)) {
                $provid = $row['id'];
                echo "<option value='" . attr($provid) . "'";
                if (!empty($_POST['form_doctor']) && ($provid == $_POST['form_doctor'])) {
                    echo " selected";
                }
                echo ">" . text($row['lname']) . ", " . text($row['fname']) . "</option>";
            }
            echo "</select>";
        } else {
            echo "<input type='hidden' name='form_doctor' value='" . attr($_SESSION['authUserID']) . "'>";
        }
        ?>
    </span>

    <span class="mdi-filter-group">
        <?php echo xlt('Date Type'); ?>:
        <select name='form_use_edate' class='form-control'>
            <option value='0'><?php echo xlt('Payment Date'); ?></option>
            <option value='1'<?php echo ($form_use_edate) ? ' selected' : ''; ?>><?php echo xlt('Invoice Date'); ?></option>
        </select>
    </span>

    <span class="mdi-filter-group">
        <?php echo xlt('From'); ?>:
        <input type='text' class='datepicker form-control' name='form_from_date' id="form_from_date" size='10'
            value='<?php echo attr(oeFormatShortDate($form_from_date)); ?>'
            title='<?php echo xla('Date of appointments'); ?>' />
    </span>

    <span class="mdi-filter-group">
        <?php echo xlt('To{{Range}}'); ?>:
        <input type='text' class='datepicker form-control' name='form_to_date' id="form_to_date" size='10'
            value='<?php echo attr(oeFormatShortDate($form_to_date)); ?>'
            title='<?php echo xla('Optional end date'); ?>' />
    </span>

    <?php if (!$GLOBALS['simplified_demographics']) { ?>
    <span class="mdi-filter-group">
        <?php echo xlt('Procedure'); ?>:
        <input type='text' class='form-control' name='form_proc_codefull' size='10'
            value='<?php echo attr($form_proc_codefull); ?>' onclick='sel_procedure()'
            title='<?php echo xla('Optional procedure/service code'); ?>' style='cursor:pointer;' />
    </span>
    <span class="mdi-filter-group">
        <?php echo xlt('Diagnosis'); ?>:
        <input type='text' class='form-control' name='form_dx_codefull' size='10'
            value='<?php echo attr($form_dx_codefull); ?>' onclick='sel_diagnosis()'
            title='<?php echo xla('Enter a diagnosis code to exclude all invoices not containing it'); ?>' style='cursor:pointer;' />
    </span>
    <?php } else { ?>
        <input type='hidden' name='form_proc_codefull' value='<?php echo attr($form_proc_codefull); ?>' />
        <input type='hidden' name='form_dx_codefull' value='<?php echo attr($form_dx_codefull); ?>' />
    <?php } ?>

    <span class="mdi-filter-group">
        <label>
            <input type='checkbox' name='form_details' value='1' <?php echo (!empty($_POST['form_details'])) ? "checked" : ""; ?> />
            <?php echo xlt('Details'); ?>
        </label>
    </span>

    <span class="mdi-filter-group">
        <label>
            <input type='checkbox' name='form_procedures' value='1' <?php echo ($form_procedures) ? "checked" : ""; ?> />
            <?php echo xlt('Procedures'); ?>
        </label>
    </span>

    <a href='#' class='mdi-btn-submit' onclick='$("#form_refresh").attr("value","true"); $("#theform").submit();'>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo xlt('Submit'); ?>
    </a>
    <?php if (!empty($_POST['form_refresh'])) { ?>
        <a href='#' class='mdi-btn-secondary' id='printbutton'>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            <?php echo xlt('Print'); ?>
        </a>
    <?php } ?>

</div><!-- end filter bar -->
<div class="mdi-results-wrap">

                <?php
                if (!empty($_POST['form_refresh'])) {
                    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
                        CsrfUtils::csrfNotVerified();
                    }

                    ?>
                <div id="report_results">
                <table class='table' cellpadding='1' cellspacing='2' width='98%' id='mymaintable'>
                <thead>
                <th>
                    <?php echo xlt('Practitioner') ?>
                </th>
                <th>
                    <?php echo xlt('Date') ?>
                </th>
                    <?php if ($form_procedures) { ?>
                <th>
                        <?php
                        if ($GLOBALS['cash_receipts_report_invoice'] == '0') {
                            echo xlt('Invoice');
                        } else {
                            echo xlt('Name');
                        }?>
                </th>
                    <?php } ?>
                    <?php if ($form_proc_codefull) { ?>
                <th align='right'>
                        <?php echo xlt('InvAmt') ?>
                </th>
                    <?php } ?>
                    <?php if ($form_proc_codefull) { ?>
                <th>
                        <?php echo xlt('Insurance') ?>
                </th>
                    <?php } ?>
                    <?php if ($form_procedures) { ?>
                <th>
                        <?php echo xlt('Procedure') ?>
                </th>
                <th align="right">
                        <?php echo xlt('Prof.') ?>
                </th>
                <th align="right">
                        <?php echo xlt('Clinic') ?>
                </th>
                    <?php } else { ?>
                <th align="right">
                        <?php echo xlt('Received') ?>
                </th>
                    <?php } ?>
                </thead>
                    <?php
                    if ($_POST['form_refresh']) {
                        $form_doctor = $_POST['form_doctor'];
                        if (!AclMain::aclCheckCore('acct', 'rep_a')) {
                            // only allow user to see their encounter information
                            $form_doctor = $_SESSION['authUserID'];
                        }

                        $arows = array();

                        $ids_to_skip = array();
                        $irow = 0;

                        // Get copays.  These will be ignored if a CPT code was specified.
                        //
                        if (!$form_proc_code || !$form_proc_codetype) {
                            /*************************************************************
                            $query = "SELECT b.fee, b.pid, b.encounter, b.code_type, b.code, b.modifier, " .
                            "fe.date, fe.id AS trans_id, u.id AS docid " .
                            "FROM billing AS b " .
                            "JOIN form_encounter AS fe ON fe.pid = b.pid AND fe.encounter = b.encounter " .
                            "JOIN forms AS f ON f.pid = b.pid AND f.encounter = b.encounter AND f.formdir = 'newpatient' " .
                            "LEFT OUTER JOIN users AS u ON u.username = f.user " .
                            "WHERE b.code_type = 'COPAY' AND b.activity = 1 AND " .
                            "fe.date >= '$form_from_date 00:00:00' AND fe.date <= '$form_to_date 23:59:59'";
                            // If a facility was specified.
                            if ($form_facility) {
                            $query .= " AND fe.facility_id = '$form_facility'";
                            }
                            // If a doctor was specified.
                            if ($form_doctor) {
                            $query .= " AND u.id = '$form_doctor'";
                            }
                            *************************************************************/
                            $sqlBindArray = array();
                            $query = "SELECT b.fee, b.pid, b.encounter, b.code_type, b.code, b.modifier, " .
                            "fe.date, fe.id AS trans_id, fe.provider_id AS docid, fe.invoice_refno " .
                            "FROM billing AS b " .
                            "JOIN form_encounter AS fe ON fe.pid = b.pid AND fe.encounter = b.encounter " .
                            "WHERE b.code_type = 'COPAY' AND b.activity = 1 AND " .
                            "fe.date >= ? AND fe.date <= ?";
                            array_push($sqlBindArray, $form_from_date . " 00:00:00", $form_to_date . " 23:59:59");
                            // If a facility was specified.
                            if ($form_facility) {
                                $query .= " AND fe.facility_id = ?";
                                array_push($sqlBindArray, $form_facility);
                            }

                            // If a doctor was specified.
                            if ($form_doctor) {
                                $query .= " AND fe.provider_id = ?";
                                array_push($sqlBindArray, $form_doctor);
                            }

                            /************************************************************/
                            //
                            $res = sqlStatement($query, $sqlBindArray);
                            while ($row = sqlFetchArray($res)) {
                                $trans_id = $row['trans_id'];
                                $thedate = substr($row['date'], 0, 10);
                                $patient_id = $row['pid'];
                                $encounter_id = $row['encounter'];
                            //
                                if (!empty($ids_to_skip[$trans_id])) {
                                    continue;
                                }

                            //
                            // If a diagnosis code was given then skip any invoices without
                            // that diagnosis.
                                if ($form_dx_code && $form_dx_codetype) {
                                    $tmp = sqlQuery("SELECT count(*) AS count FROM billing WHERE " .
                                    "pid = ? AND encounter = ? AND " .
                                    "code_type = ? AND code LIKE ? AND " .
                                    "activity = 1", array($patient_id,$encounter_id,$form_dx_codetype,$form_dx_code));
                                    if (empty($tmp['count'])) {
                                        $ids_to_skip[$trans_id] = 1;
                                        continue;
                                    }
                                }

                            //
                                $key = sprintf(
                                    "%08u%s%08u%08u%06u",
                                    $row['docid'],
                                    $thedate,
                                    $patient_id,
                                    $encounter_id,
                                    ++$irow
                                );
                                $arows[$key] = array();
                                $arows[$key]['transdate'] = $thedate;
                                $arows[$key]['amount'] = $row['fee'];
                                $arows[$key]['docid'] = $row['docid'];
                                $arows[$key]['project_id'] = 0;
                                $arows[$key]['memo'] = '';
                                if ($GLOBALS['cash_receipts_report_invoice'] == '0') {
                                    $arows[$key]['invnumber'] = "$patient_id.$encounter_id";
                                } else {
                                    $arows[$key]['invnumber'] = "$patient_name";
                                }

                                $arows[$key]['irnumber'] = $row['invoice_refno'];
                            } // end while
                        } // end copays (not $form_proc_code)

                        // Get ar_activity (having payments), form_encounter, forms, users, optional ar_session
                        /***************************************************************
                        $query = "SELECT a.pid, a.encounter, a.post_time, a.code, a.modifier, a.pay_amount, " .
                        "fe.date, fe.id AS trans_id, u.id AS docid, s.deposit_date, s.payer_id " .
                        "FROM ar_activity AS a " .
                        "JOIN form_encounter AS fe ON fe.pid = a.pid AND fe.encounter = a.encounter " .
                        "JOIN forms AS f ON f.pid = a.pid AND f.encounter = a.encounter AND f.formdir = 'newpatient' " .
                        "LEFT OUTER JOIN users AS u ON u.username = f.user " .
                        "LEFT OUTER JOIN ar_session AS s ON s.session_id = a.session_id " .
                        "WHERE a.pay_amount != 0 AND ( " .
                        "a.post_time >= '$form_from_date 00:00:00' AND a.post_time <= '$form_to_date 23:59:59' " .
                        "OR fe.date >= '$form_from_date 00:00:00' AND fe.date <= '$form_to_date 23:59:59' " .
                        "OR s.deposit_date >= '$form_from_date' AND s.deposit_date <= '$form_to_date' )";
                        // If a procedure code was specified.
                        if ($form_proc_code) $query .= " AND a.code = '$form_proc_code'";
                        // If a facility was specified.
                        if ($form_facility) $query .= " AND fe.facility_id = '$form_facility'";
                        // If a doctor was specified.
                        if ($form_doctor) $query .= " AND u.id = '$form_doctor'";
                        ***************************************************************/
                        $sqlBindArray = array();
                        $query = "SELECT a.pid, a.encounter, a.post_time, a.code, a.modifier, a.pay_amount, " .
                        "fe.date, fe.id AS trans_id, fe.provider_id AS docid, fe.invoice_refno, s.deposit_date, s.payer_id, " .
                        "b.provider_id, concat(p.lname, ' ', p.fname) as 'pat_fulname' " .
                        "FROM ar_activity AS a " .
                        "JOIN form_encounter AS fe ON fe.pid = a.pid AND fe.encounter = a.encounter " .
                        "LEFT OUTER JOIN ar_session AS s ON s.session_id = a.session_id " .
                        "LEFT OUTER JOIN patient_data AS p ON p.pid = a.pid " .
                        "LEFT OUTER JOIN billing AS b ON b.pid = a.pid AND b.encounter = a.encounter AND " .
                        "b.code = a.code AND b.modifier = a.modifier AND b.activity = 1 AND " .
                        "b.code_type != 'COPAY' AND b.code_type != 'TAX' " .
                        "WHERE a.deleted IS NULL AND a.pay_amount != 0 AND ( " .
                        "a.post_time >= ? AND a.post_time <= ? " .
                        "OR fe.date >= ? AND fe.date <= ? " .
                        "OR s.deposit_date >= ? AND s.deposit_date <= ? )";
                        array_push($sqlBindArray, $form_from_date . " 00:00:00", $form_to_date . " 23:59:59", $form_from_date . " 00:00:00", $form_to_date . " 23:59:59", $form_from_date, $form_to_date);
                        // If a procedure code was specified.
                        // Support code type if it is in the ar_activity table. Note it is not always included, so
                        // also support a blank code type in ar_activity table.
                        if ($form_proc_codetype && $form_proc_code) {
                            $query .= " AND (a.code_type = ? OR a.code_type = '') AND a.code = ?";
                            array_push($sqlBindArray, $form_proc_codetype, $form_proc_code);
                        }

                        // If a facility was specified.
                        if ($form_facility) {
                            $query .= " AND fe.facility_id = ?";
                            array_push($sqlBindArray, $form_facility);
                        }

                        // If a doctor was specified.
                        if ($form_doctor) {
                            $query .= " AND ( b.provider_id = ? OR " .
                            "( ( b.provider_id IS NULL OR b.provider_id = 0 ) AND " .
                            "fe.provider_id = ? ) )";
                            array_push($sqlBindArray, $form_doctor, $form_doctor);
                        }

                        /**************************************************************/
                        //
                        $res = sqlStatement($query, $sqlBindArray);
                        while ($row = sqlFetchArray($res)) {
                            $trans_id = $row['trans_id'];
                            $patient_id = $row['pid'];
                            $encounter_id = $row['encounter'];
                            $patient_name = $row['pat_fulname'];
                            //
                            if (!empty($ids_to_skip[$trans_id])) {
                                continue;
                            }

                            //
                            if ($form_use_edate) {
                                $thedate = substr($row['date'], 0, 10);
                            } else {
                                if (!empty($row['deposit_date'])) {
                                    $thedate = $row['deposit_date'];
                                } else {
                                    $thedate = substr($row['post_time'], 0, 10);
                                }
                            }

                            if (strcmp($thedate, $form_from_date) < 0 || strcmp($thedate, $form_to_date) > 0) {
                                continue;
                            }

                            //
                            // If a diagnosis code was given then skip any invoices without
                            // that diagnosis.
                            if ($form_dx_code && $form_dx_codetype) {
                                $tmp = sqlQuery("SELECT count(*) AS count FROM billing WHERE " .
                                "pid = ? AND encounter = ? AND " .
                                "code_type = ? AND code LIKE ? AND " .
                                "activity = 1", array($patient_id,$encounter_id,$form_dx_codetype,$form_dx_code));
                                if (empty($tmp['count'])) {
                                    $ids_to_skip[$trans_id] = 1;
                                    continue;
                                }
                            }

                            //
                            $docid = empty($row['encounter_id']) ? $row['docid'] : $row['encounter_id'];
                            $key = sprintf(
                                "%08u%s%08u%08u%06u",
                                $docid,
                                $thedate,
                                $patient_id,
                                $encounter_id,
                                ++$irow
                            );
                            $arows[$key] = array();
                            $arows[$key]['transdate'] = $thedate;
                            $arows[$key]['amount'] = 0 - $row['pay_amount'];
                            $arows[$key]['docid'] = $docid;
                            $arows[$key]['project_id'] = empty($row['payer_id']) ? 0 : $row['payer_id'];
                            $arows[$key]['memo'] = $row['code'];
                            if ($GLOBALS['cash_receipts_report_invoice'] == '0') {
                                $arows[$key]['invnumber'] = "$patient_id.$encounter_id";
                            } else {
                                $arows[$key]['invnumber'] = "$patient_name";
                            }

                            $arows[$key]['irnumber'] = $row['invoice_refno'];
                        } // end while

                        ksort($arows);
                        $docid = 0;

                        foreach ($arows as $row) {
                        // Get insurance company name
                            $insconame = '';
                            if ($form_proc_codefull  && $row['project_id']) {
                                $tmp = sqlQuery("SELECT name FROM insurance_companies WHERE " .
                                "id = ?", array($row['project_id']));
                                $insconame = $tmp['name'];
                            }

                            $amount1 = 0;
                            $amount2 = 0;
                            if ($form_procedures && is_clinic($row['memo'])) {
                                $amount2 -= $row['amount'];
                            } else {
                                $amount1 -= $row['amount'];
                            }

                        // if ($docid != $row['employee_id']) {
                            if ($docid != $row['docid']) {
                                if ($docid) {
                                    // Print doc totals.
                                    ?>
                    <!-- TODO: Replace bgcolor with BS4 !-->
                    <tr bgcolor="#ddddff">
                        <td class="detail" colspan="<?php echo ($form_proc_codefull ? 4 : 2) + ($form_procedures ? 2 : 0); ?>">
                                    <?php echo xlt('Totals for ') . text($docname) ?>
                </td>
                <td align="right">
                                    <?php bucks($doctotal1) ?>
                </td>
                                    <?php if ($form_procedures) { ?>
                <td align="right">
                                        <?php bucks($doctotal2) ?>
                </td>
                    <?php } ?>
                </tr>
                                    <?php
                                }

                                $doctotal1 = 0;
                                $doctotal2 = 0;

                                $docid = $row['docid'];
                                $tmp = sqlQuery("SELECT lname, fname FROM users WHERE id = ?", array($docid));
                                $docname = empty($tmp) ? xl('Unknown') : $tmp['fname'] . ' ' . $tmp['lname'];

                                $docnameleft = $docname;
                            }

                            if ($_POST['form_details']) {
                                ?>

                <tr>
                    <td class="detail">
                                <?php echo text($docnameleft); $docnameleft = " " ?>
                </td>
                <td class="detail">
                                <?php echo text(oeFormatShortDate($row['transdate'])); ?>
                </td>
                                <?php if ($form_procedures) { ?>
                <td class="detail">
                                    <?php echo empty($row['irnumber']) ? text($row['invnumber']) : text($row['irnumber']); ?>
                </td>
                    <?php } ?>
                                <?php
                                if ($form_proc_code && $form_proc_codetype) {
                                        echo "  <td class='detail' align='right'>";
                                        list($patient_id, $encounter_id) = explode(".", $row['invnumber']);
                                        $tmp = sqlQuery("SELECT SUM(fee) AS sum FROM billing WHERE " .
                                        "pid = ? AND encounter = ? AND " .
                                        "code_type = ? AND code = ? AND activity = 1", array($patient_id,$encounter_id,$form_proc_codetype,$form_proc_code));
                                        bucks($tmp['sum']);
                                        echo "  </td>\n";
                                }
                                ?>
                                <?php
                                if ($form_proc_codefull) { ?>
                    <td class="detail">
                                        <?php echo text($insconame) ?>
                    </td>
                                    <?php } ?>
                                <?php if ($form_procedures) { ?>
                <td class="detail">
                                    <?php echo text($row['memo']) ?>
                </td>
                            <?php } ?>
                <td class="detail" align="right">
                                <?php bucks($amount1) ?>
                </td>
                                <?php if ($form_procedures) { ?>
                <td class="detail" align="right">
                                    <?php bucks($amount2) ?>
                </td>
                            <?php } ?>
                </tr>
                                <?php
                            } // end details
                            $doctotal1   += $amount1;
                            $doctotal2   += $amount2;

                            $grandtotal1 = $grandtotal1 ?? null;
                            $grandtotal1 += $amount1;

                            $grandtotal2 = $grandtotal2 ?? null;
                            $grandtotal2 += $amount2;
                        }
                        ?>
                <!-- TODO: Replace bgcolor with BS4 !-->
                <tr bgcolor="#ddddff">
                <td class="detail" colspan="<?php echo ($form_proc_codefull ? 4 : 2) + ($form_procedures ? 2 : 0); ?>">
                        <?php echo xlt('Totals for ') . text($docname ?? '') ?>
                </td>
                <td align="right">
                        <?php bucks($doctotal1 ?? '') ?>
                </td>
                        <?php if ($form_procedures) { ?>
                <td align="right">
                            <?php bucks($doctotal2) ?>
                </td>
                <?php } ?>
                </tr>

                <!-- TODO: Replace bgcolor with BS4 !-->
                <tr bgcolor="#ffdddd">
                <td class="detail" colspan="<?php echo ($form_proc_codefull ? 4 : 2) + ($form_procedures ? 2 : 0); ?>">
                        <?php echo xlt('Grand Totals') ?>
                </td>
                <td align="right">
                        <?php bucks($grandtotal1 ?? '') ?>
                </td>
                        <?php if ($form_procedures) { ?>
                <td align="right">
                            <?php bucks($grandtotal2) ?>
                </td>
                <?php } ?>
                </tr>
                        <?php $report_from_date = oeFormatShortDate($form_from_date)  ;
                        $report_to_date = oeFormatShortDate($form_to_date)  ;
                        ?>
                <div align='right'><span class='title'><?php echo xlt('Report Date') . ' '; ?><?php echo text($report_from_date);?> - <?php echo text($report_to_date);?></span></div>

                        <?php
                    }
                    ?>

                </table>
                </div>
                <?php } else { ?>
                <div class='text'>
                    <?php echo xlt('Please input search criteria above, and click Submit to view results.'); ?>
                </div>
                <?php } ?>

</div><!-- .mdi-results-wrap -->
</form>

</body>

</html>
