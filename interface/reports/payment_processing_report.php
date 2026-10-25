<?php

/**
 * Payment processing report.
 *  Supports void and credit with Sphere payment processing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2021 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\PaymentProcessing\PaymentProcessing;
use OpenEMR\PaymentProcessing\Sphere\SphereRevert;

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

if (!AclMain::aclCheckCore('acct', 'rep_a')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Payment Processing")]);
    exit;
}

// If from date is empty, default to 1 week ago.
$from_date = (!empty($_POST['form_from_date'])) ? DateTimeToYYYYMMDDHHMMSS($_POST['form_from_date']) : date('Y-m-d H:i:s', strtotime('-1 week'));
$to_date = (!empty($_POST['form_to_date'])) ? DateTimeToYYYYMMDDHHMMSS($_POST['form_to_date']) : date('Y-m-d H:i:s');

$patient = $_POST['form_patient'] ?? null;
$service = $_POST['form_service'] ?? null;
$ticket = $_POST['form_ticket'] ?? null;
$transId = $_POST['form_trans_id'] ?? null;
$actionName = $_POST['form_action_name'] ?? null;
?>

<html>

<head>
    <title><?php echo xlt('Payment Processing'); ?></title>

    <?php Header::setupHeader(["datetime-picker","report-helper"]); ?>

    <script>
        $(function () {
            var win = top.printLogSetup ? top : opener.top;
            win.printLogSetup(document.getElementById('printbutton'));

            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = true; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
            });
        });

        function refreshme() {
            document.forms[0].submit();
        }

        function setpatient(pid, lname, fname, dob) {
          document.forms[0].elements['form_patient'].value = pid;
        }

        function sel_patient() {
            dlgopen('../main/calendar/find_patient_popup.php?pflag=0', '_blank', 500, 400);
        }

        <?php
        if ($GLOBALS['payment_gateway'] == 'Sphere') {
            echo SphereRevert::renderRevertSphereJs();
        }
        ?>
    </script>

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
            #report_results table {
                margin-top: 0px;
            }
        }

        /* specifically exclude some from the screen */
        @media screen {
            #report_parameters_daterange {
                visibility: hidden;
                display: none;
            }
        }
    </style>

    <!-- MDItek Reports Theme -->
    <link rel="stylesheet" href="<?php echo $GLOBALS['webroot']; ?>/interface/reports/mdi_reports.css" />
</head>

<body class="body_top">

<!-- Required for the popup date selectors -->
<div id="overDiv" style="position:absolute;visibility:hidden;z-index:1000;"></div>

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
.mdi-filter-group select.form-control { min-width: 120px; }
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
/* print */
@media print {
    #report_parameters_daterange { visibility: visible; display: inline; }
    .mdi-report-header, .mdi-filter-bar { display: none; }
    .mdi-results-wrap { padding: 0; }
}
@media screen {
    #report_parameters_daterange { visibility: hidden; display: none; }
}
</style>

<div id="report_parameters_daterange"><?php echo text(oeFormatShortDate($from_date)) . " &nbsp; " . xlt('to{{Range}}') . " &nbsp; " . text(oeFormatShortDate($to_date)); ?></div>

<!-- Page Title Bar -->
<div class="mdi-report-header"><?php echo xlt('Report - Payment Processing'); ?></div>

<form method='post' name='theform' id='theform' action='payment_processing_report.php' onsubmit='return top.restoreSession()'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type='hidden' name='form_refresh' id='form_refresh' value='' />

<!-- Filter Bar -->
<div id="report_parameters" class="mdi-filter-bar">
    <span class="mdi-filter-group">
        <?php echo xlt('Service'); ?>:
        <select name='form_service' id='form_service' class='form-control'>
            <option value=''><?php echo xlt('All'); ?></option>
            <option value='sphere' <?php echo ($service == 'sphere') ? 'selected' : '' ?>><?php echo xlt('Sphere'); ?></option>
        </select>
    </span>
    <span class="mdi-filter-group">
        <?php echo xlt('Patient'); ?>:
        <input type='text' name='form_patient' class='form-control' style='cursor:pointer;' id='form_patient'
            value='<?php echo attr($patient); ?>' onclick='sel_patient()'
            title='<?php echo xla('Click to select patient'); ?>' />
    </span>
    <span class="mdi-filter-group">
        <?php echo xlt('From'); ?>:
        <input type='text' name='form_from_date' id="form_from_date" class='datepicker form-control'
            size='16' value='<?php echo attr(oeFormatDateTime($from_date)); ?>' />
    </span>
    <span class="mdi-filter-group">
        <?php echo xlt('To{{Range}}'); ?>:
        <input type='text' name='form_to_date' id="form_to_date" class='datepicker form-control'
            size='16' value='<?php echo attr(oeFormatDateTime($to_date)); ?>' />
    </span>
    <span class="mdi-filter-group">
        <?php echo xlt('Ticket'); ?>:
        <input type='text' name='form_ticket' id='form_ticket' class='form-control' value='<?php echo attr($ticket); ?>' />
    </span>
    <span class="mdi-filter-group">
        <?php echo xlt('Trans ID'); ?>:
        <input type='text' name='form_trans_id' id='form_trans_id' class='form-control' value='<?php echo attr($transId); ?>' />
    </span>
    <span class="mdi-filter-group">
        <?php echo xlt('Action'); ?>:
        <select name='form_action_name' id='form_action_name' class='form-control'>
            <option value=''><?php echo xlt('All'); ?></option>
            <option value='Sale' <?php echo ($actionName == 'Sale') ? 'selected' : '' ?>><?php echo xlt('Sale'); ?></option>
            <option value='credit' <?php echo ($actionName == 'credit') ? 'selected' : '' ?>><?php echo xlt('Credit'); ?></option>
            <option value='void' <?php echo ($actionName == 'void') ? 'selected' : '' ?>><?php echo xlt('Void'); ?></option>
        </select>
    </span>
    <a href='#' class='mdi-btn-submit' onclick='$(\'#form_refresh\').attr("value","true"); $(\'#theform\').submit();'>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo xlt('Submit'); ?>
    </a>
    <?php if (!empty($_POST['form_refresh'])) { ?>
        <a href='#' class='mdi-btn-secondary' id='printbutton'>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            <?php echo xlt('Print'); ?>
        </a>
    <?php } ?>
</div>
<!-- end of search parameters -->
<div class="mdi-results-wrap"> <?php
if (!empty($_POST['form_refresh'])) {
    $showDate = ($from_date != $to_date) || (!$to_date);
    ?>
<div id="report_results">
<table class='table'>

    <thead class='thead-light'>
        <th><?php echo xlt('Date'); ?></th>
        <th><?php echo xlt('Service'); ?></th>
        <th><?php echo xlt('Front'); ?></th>
        <th><?php echo xlt('Ticket'); ?></th>
        <th><?php echo xlt('Transaction ID'); ?></th>
        <th><?php echo xlt('Patient'); ?></th>
        <th><?php echo xlt('Action'); ?></th>
        <th><?php echo xlt('Success'); ?></th>
        <th><?php echo xlt('Amount'); ?></th>
        <th><?php echo xlt('Error Message'); ?></th>
        <th><?php echo xlt('Void/Credit'); ?></th>
    </thead>
    <tbody>
        <!-- added for better print-ability -->
    <?php
    $auditEntries = PaymentProcessing::fetchAudit($from_date, $to_date, $patient, $service, $ticket, $transId, $actionName);

    foreach ($auditEntries as $auditEntry) {
        ?>

        <tr valign='top''); ?>'>
            <td class="detail">&nbsp;<?php echo text(oeFormatDateTime($auditEntry['date'])); ?></td>
            <td class="detail">&nbsp;<?php echo text($auditEntry['service']); ?></td>
            <td class="detail">&nbsp;<?php echo text($auditEntry['front_label']); ?></td>
            <td class="detail">&nbsp;<?php echo text($auditEntry['ticket']); ?></td>
            <td class="detail">&nbsp;<?php echo text($auditEntry['transaction_id']); ?></td>
            <td class="detail">&nbsp;<?php echo text($auditEntry['pid']); ?></td>
            <td class="detail">&nbsp;<?php echo text($auditEntry['action_name_label'] ?? ''); ?></td>
            <td class="detail">&nbsp;<?php echo (!empty($auditEntry['success'])) ? xlt("Yes") : xlt("No"); ?></td>
            <td class="detail">&nbsp;<?php echo text($auditEntry['amount']); ?></td>
            <td class="detail">&nbsp;<?php echo text($auditEntry['error_message'] ?? ''); ?></td>
            <td class="detail">
                <?php
                if ($auditEntry['action_name'] == 'Sale') {
                    if (!empty($auditEntry['reverted'])) {
                        // Charge has already been reverted
                        if ($auditEntry['revert_action_name'] == 'void') {
                            echo xlt("This charge was reversed via void on following date") . ": " . text(oeFormatDateTime($auditEntry['revert_date'])) . "<br>" .
                                xlt("The transaction_id for the void was") . ": " . text($auditEntry['revert_transaction_id']);
                        } else { // $auditEntry['revert_action_name'] == 'credit'
                            echo xlt("This charge was reversed via credit on following date") . ": " . text(oeFormatDateTime($auditEntry['revert_date'])) . "<br>" .
                                xlt("The Transaction ID for the credit was") . ": " . text($auditEntry['revert_transaction_id']);
                        }
                    }
                    if (!empty($auditEntry['offer_void'])) {
                        if (($auditEntry['service'] == 'sphere') && ($GLOBALS['payment_gateway'] == 'Sphere')) {
                            echo SphereRevert::renderSphereVoidButton($auditEntry['front'], $auditEntry['transaction_id'], $auditEntry['uuid']);
                        }
                    }
                    if (!empty($auditEntry['offer_credit'])) {
                        if (($auditEntry['service'] == 'sphere') && ($GLOBALS['payment_gateway'] == 'Sphere')) {
                            echo SphereRevert::renderSphereCreditButton($auditEntry['front'], $auditEntry['transaction_id'], $auditEntry['uuid']);
                        }
                    }
                } elseif (($auditEntry['action_name'] == 'void') || ($auditEntry['action_name'] == 'credit')) {
                    if (!empty($auditEntry['success'])) {
                        if ($auditEntry['action_name'] == 'void') {
                            echo xlt("This transaction voided the following Transaction ID" . ": " . $auditEntry['map_transaction_id']);
                        } else { // $auditEntry['action_name'] == 'credit'
                            echo xlt("This transaction credited the following Transaction ID" . ": " . $auditEntry['map_transaction_id']);
                        }
                    }
                }
                ?>
            </td>
        </tr>
    <?php } ?>
    </tbody>
</table>
</div>
<!-- end of search results -->
<?php } else { ?>
<div class='text'><?php echo xlt('Please input search criteria above, and click Submit to view results.'); ?>
</div>
<?php } ?>
<input type='hidden' name='form_refresh' id='form_refresh' value='' /></div><!-- .mdi-results-wrap -->
</form>

</body>

</html>
