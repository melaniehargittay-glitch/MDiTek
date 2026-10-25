<?php

/**
 * main file for the 270 batch creation.
 * This report is the batch report required for batch eligibility verification.
 *
 * This program creates the batch for the x12 270 eligibility file
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Terry Hill <terry@lilysystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2010 MMF Systems, Inc
 * @copyright Copyright (c) 2016 Terry Hill <terry@lillysystems.com>
 * @copyright Copyright (c) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019-2020 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019-2020 Stephen Waite <stephen.waite@cmsvt.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/forms.inc.php");
require_once("$srcdir/patient.inc.php");
require_once "$srcdir/options.inc.php";
require_once("$srcdir/calendar.inc.php");
require_once("$srcdir/appointments.inc.php");

use OpenEMR\Billing\EDI270;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

// Element data seperator
$eleDataSep = "*";
// Segment Terminator
$segTer = "~";
// Component Element seperator
$compEleSep     = ":";

// filter conditions for the report and batch creation

$from_date      = (isset($_POST['form_from_date'])) ? DateToYYYYMMDD($_POST['form_from_date']) : date('Y-m-d');
$to_date        = (isset($_POST['form_to_date'])) ? DateToYYYYMMDD($_POST['form_to_date']) : date('Y-m-d');
$form_facility  = (!empty($_POST['form_facility'])) ? $_POST['form_facility'] : '';
$form_provider  = (!empty($_POST['form_users'])) ? $_POST['form_users'] : '';
$exclude_policy = (!empty($_POST['removedrows'])) ? $_POST['removedrows'] : '';
$x12_partner    = (!empty($_POST['form_x12'])) ? $_POST['form_x12'] : '';
$X12info        = EDI270::getX12Partner($x12_partner);

// grab appointments, sort by date and make unique to first upcoming appt by pid.
$appts = fetchAppointments($from_date, $to_date);
$appts = sortAppointments($appts);
$appts = unique_by_key($appts, 'pid');
$ids = [];
foreach ($appts as $eid) {
    $ids[] = $eid['pc_eid'];
}
//Set up the sql variable binding array (this prevents sql-injection attacks)
$sqlBindArray = array();

$ids = count($ids) > 0 ? implode(',', $ids) : "'0'";
$where  = "e.pc_eid in($ids) ";

if ($form_facility != "") {
    $where .= " AND f.id = ? ";
    array_push($sqlBindArray, $form_facility);
}

if ($form_provider != "") {
    $where .= " AND d.id = ? ";
    array_push($sqlBindArray, $form_provider);
}

if ($exclude_policy != "") {
    $arrayExplode = explode(",", $exclude_policy);
    $excludePlacemakers = "";
    $firstFlag = true;
    foreach ($arrayExplode as $processExclude) {
        // grab the string between the _ character and the ending ' character (and then drop these characters)
        $processExclude = strstr($processExclude, '_');
        $processExclude = substr($processExclude, 1, strlen($processExclude) - 2);
        array_push($sqlBindArray, $processExclude);
        if ($firstFlag) {
            $excludePlacemakers = "?";
            $firstFlag = false;
        } else {
            $excludePlacemakers .= ",?";
        }
    }
    $where .= " AND i.policy_number NOT IN ($excludePlacemakers)";
}
    $where .= " AND (i.policy_number is NOT NULL AND i.policy_number != '')";
    $where .= " GROUP BY p.pid ORDER BY c.name";
    $query = sprintf("SELECT e.pc_facility,
        e.pc_eid,
        p.lname,
        p.fname,
        p.mname,
        DATE_FORMAT(p.dob, '%%Y%%m%%d') as dob,
        p.ss,
        p.sex,
        p.pid,
        p.pubpid,
        i.subscriber_ss,
        i.policy_number,
        i.provider as payer_id,
        i.subscriber_relationship,
        i.subscriber_lname,
        i.subscriber_fname,
        i.subscriber_mname,
        DATE_FORMAT(i.subscriber_dob, '%%Y%%m%%d') as subscriber_dob,
        i.policy_number,
        i.subscriber_sex,
        DATE_FORMAT(i.date,'%%Y%%m%%d') as date,
        d.lname as provider_lname,
        d.fname as provider_fname,
        d.npi as provider_npi,
        d.upin as provider_pin,
        f.federal_ein as federal_ein,
        f.facility_npi as facility_npi,
        f.name as facility_name,
        c.cms_id as cms_id,
        c.eligibility_id as eligibility_id,
        c.name as payer_name
        FROM openemr_postcalendar_events AS e
        LEFT JOIN users AS d on (e.pc_aid is not null and e.pc_aid = d.id)
        LEFT JOIN facility AS f on (f.id = e.pc_facility)
        LEFT JOIN patient_data AS p ON p.pid = e.pc_pid
        LEFT JOIN insurance_data AS i ON (i.id =(SELECT id FROM insurance_data AS i WHERE pid = p.pid AND type = 'primary' ORDER BY date DESC LIMIT 1))
        LEFT JOIN insurance_companies as c ON (c.id = i.provider)
        WHERE %s ", $where);

    // Run the query
    $rslt = sqlStatement($query, $sqlBindArray);
    $res = [];
    while ($row = sqlFetchArray($rslt)) {
        foreach ($appts as $tmp) {
            if ((int)$tmp['pc_eid'] === (int)$row['pc_eid']) {
                $row['pc_eventDate'] = date("Ymd", strtotime($tmp['pc_eventDate']));
            }
        }
        $res[] = $row;
    }
    // Get the facilities information
    $facilities     = getUserFacilities($_SESSION['authUserID']);

    // Get the Providers information
    $providers      = EDI270::getUsernames();

    //Get the x12 partners information
    $clearinghouses = EDI270::getX12Partner();

    if (isset($_POST['form_xmit']) && !empty($_POST['form_xmit']) && $res) {
        $eFlag = !$GLOBALS['disable_eligibility_log'];
        // make the batch request
        $log = EDI270::requestRealTimeEligible($res, $X12info, $segTer, $compEleSep, $eFlag);
        $e = strpos($log, "Error:");
        if ($e !== false) {
            $log =  text(xlt("One or more transactions failed") .
                "\n" . $log . "\n");
        }
        if ($eFlag) {
            $fn = sprintf(
                'elig-log_%s_%s.txt',
                strtolower(str_replace(' ', '', $X12info['name'])),
                date("Y-m-d:H:i:s")
            );
            $log = str_replace('~', "~\r", $log);
            while (@ob_end_flush()) {
            }
            header('Content-Type: text/plain');
            header("Content-Length: " . strlen($log));
            header('Content-Disposition: attachment; filename="' . $fn . '"');
            ob_start();
            echo $log;
            exit();
        }
    }

    if (isset($_POST['form_savefile']) && !empty($_POST['form_savefile']) && $res) {
        header('Content-Type: text/plain');
        header(sprintf(
            'Content-Disposition: attachment; filename="batch-elig-270.%s.%s.txt"',
            strtolower(str_replace(' ', '', $X12info['name'])),
            date("Y-m-d:H:i:s")
        ));
        EDI270::printElig($res, $X12info, $segTer, $compEleSep);
        exit;
    }

// unique multidimensional array by key
    function unique_by_key($source, $key)
    {
        $i = 0;
        $rtn_array = array();
        $key_array = array();

        foreach ($source as $val) {
            if (!in_array($val[$key], $key_array)) {
                $key_array[$i] = $val[$key];
                $rtn_array[$i] = $val;
            }
            $i++;
        }
        return $rtn_array;
    }
    ?>

<html>

    <head>

        <title><?php echo xlt('Eligibility 270 Inquiry Batch'); ?></title>

        <?php Header::setupHeader('datetime-picker'); ?>

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
        .mdi-filter-group select.form-control { min-width: 140px; }
        .mdi-filter-group .mdi-x12-wrap { display: flex; flex-direction: column; gap: 3px; }
        .mdi-x12-warn { font-size: 11px; color: #dc2626; font-weight: 600; text-transform: none; letter-spacing: 0; }
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
        .mdi-hint {
            font-size: 12px; color: #6b7280; font-style: italic;
            padding: 10px 16px;
        }
        @media print {
            .mdi-report-header, .mdi-filter-bar { display: none; }
            #report_parameters_daterange { visibility: visible; display: inline; }
            #report_results table { margin-top: 0; }
            .mdi-results-wrap { padding: 0; }
        }
        @media screen {
            #report_parameters_daterange { visibility: hidden; display: none; }
        }
        </style>

        <script>

            var stringDelete = <?php echo xlj('Do you want to remove this record?'); ?>;
            var stringBatch  = <?php echo xlj('Please select X12 partner, required to create the 270 batch'); ?>;

            // for form refresh

            function refreshme() {
                document.forms[0].submit();
            }

            //  To delete the row from the reports section
            function deletetherow(id){
                var suredelete = confirm(stringDelete);
                if(suredelete == true){
                    document.getElementById('PR'+id).style.display="none";
                    if(document.getElementById('removedrows').value == ""){
                        document.getElementById('removedrows').value = "'" + id + "'";
                    }else{
                        document.getElementById('removedrows').value = document.getElementById('removedrows').value + ",'" + id + "'";

                    }
                }

            }

            //  To validate the batch file generation - for the required field [clearing house/x12 partner]
            function validate_batch(eFlag) {
                if (document.getElementById('form_x12').value == '') {
                    alert(stringBatch);
                    return false;
                }
                else {
                    if (eFlag === true) {
                        document.getElementById('form_xmit').value = "true";
                    } else {
                        document.getElementById('form_savefile').value = "true";
                    }

                    document.theform.submit();
                }
            }

            // To Clear the hidden input field

            function validate_policy()
            {
                document.getElementById('removedrows').value = "";
                document.getElementById('form_savefile').value = "";
                document.getElementById('form_xmit').value = "";
                return true;
            }

            // To toggle the clearing house empty validation message
            function toggleMessage(id,x12){

                var spanstyle = String();

                spanstyle       = document.getElementById(id).style.visibility;
                selectoption    = document.getElementById(x12).value;

                if(selectoption != '')
                {
                    document.getElementById(id).style.visibility = "hidden";
                }
                else
                {
                    document.getElementById(id).style.visibility = "visible";
                    document.getElementById(id).style.display = "inline";
                }
                return true;

            }

            $(function () {
                $('.datepicker').datetimepicker({
                    <?php $datetimepicker_timepicker = false; ?>
                    <?php $datetimepicker_showseconds = false; ?>
                    <?php $datetimepicker_formatInput = true; ?>
                    <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                    <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
                });
            });

        </script>

    </head>
    <body class="body_top">

        <div id="overDiv" style="position:absolute;visibility:hidden;z-index:1000;"></div>

        <!-- Page Title Bar -->
        <div class="mdi-report-header"><?php echo xlt('Report - Eligibility 270 Inquiry Batch'); ?></div>

        <div id="report_parameters_daterange">
            <?php echo text(oeFormatShortDate($from_date)) . " &nbsp; " . xlt('to{Range}') . "&nbsp; " . text(oeFormatShortDate($to_date)); ?>
        </div>

        <form method='post' name='theform' id='theform' action='edi_270.php' onsubmit="return top.restoreSession()">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type="hidden" name="removedrows" id="removedrows" value="">
            <input type='hidden' name='form_savefile' id='form_savefile' value='' />
            <input type='hidden' name='form_xmit' id='form_xmit' value='' />

            <!-- Filter Bar -->
            <div id="report_parameters" class="mdi-filter-bar">

                <span class="mdi-filter-group">
                    <?php echo xlt('From'); ?>:
                    <input type='text' class='datepicker form-control' name='form_from_date' id="form_from_date" size='10'
                        value='<?php echo attr(oeFormatShortDate($from_date)); ?>' />
                </span>

                <span class="mdi-filter-group">
                    <?php echo xlt('To{Range}'); ?>:
                    <input type='text' class='datepicker form-control' name='form_to_date' id="form_to_date" size='10'
                        value='<?php echo attr(oeFormatShortDate($to_date)); ?>' />
                </span>

                <span class="mdi-filter-group">
                    <?php echo xlt('Facility'); ?>:
                    <?php dropdown_facility($form_facility, 'form_facility', false); ?>
                </span>

                <span class="mdi-filter-group">
                    <?php echo xlt('Provider'); ?>:
                    <select name='form_users' class='form-control' onchange='form.submit();'>
                        <option value=''>-- <?php echo xlt('All'); ?> --</option>
                        <?php foreach ($providers as $user) : ?>
                            <option value='<?php echo attr($user['id']); ?>'
                                <?php echo $form_provider == $user['id'] ? " selected " : null; ?>
                            ><?php echo text($user['fname'] . " " . $user['lname']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </span>

                <span class="mdi-filter-group">
                    <?php echo xlt('X12 Partner'); ?>:
                    <span class="mdi-x12-wrap">
                        <select name='form_x12' id='form_x12' class='form-control'
                            onchange='return toggleMessage("emptyVald","form_x12");'>
                            <option value=''>-- <?php echo xlt('select'); ?> --</option>
                            <?php
                            if (isset($clearinghouses) && !empty($clearinghouses)) {
                                foreach ($clearinghouses as $clearinghouse) {
                                    if (!empty($clearinghouse['id'])) {
                                        echo "<option value='" . attr($clearinghouse['id']) . "'" .
                                            (!empty($X12info['id']) && ($clearinghouse['id'] == $X12info['id']) ? " selected " : '') .
                                            ">" . text($clearinghouse['name']) . "</option>";
                                    }
                                }
                            }
                            ?>
                        </select>
                        <span id='emptyVald' class='mdi-x12-warn'
                            style='visibility:<?php echo (!empty($X12info['id'])) ? "hidden" : "visible"; ?>;'>
                            * <?php echo xlt('Clearing house required for batch creation.'); ?>
                        </span>
                    </span>
                </span>

                <a href='#' class='mdi-btn-secondary' onclick='validate_policy(); $("#theform").submit();'>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    <?php echo xlt('Refresh'); ?>
                </a>
                <a href='#' class='mdi-btn-submit' onclick='return validate_batch(false);'>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    <?php echo xlt('Create Batch'); ?>
                </a>
                <?php if ($GLOBALS['enable_oa']) { ?>
                <a href='#' class='mdi-btn-submit' onclick='return validate_batch(true);'>
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    <?php echo xlt('Request Eligibility'); ?>
                </a>
                <?php } ?>

            </div><!-- end filter bar -->

            <div class="mdi-hint">
                <?php echo xlt('Please choose date range criteria above, and click Refresh to view results.'); ?>
            </div>

        </form>

        <?php
        if ($res) {
            EDI270::showElig($res, $X12info, $segTer, $compEleSep);
        }
        ?>
    </body>

    <script>
        <?php
        if (!empty($alertmsg)) {
            echo " alert(" . js_escape($alertmsg) . ");\n";
        } ?>
    </script>

</html>
