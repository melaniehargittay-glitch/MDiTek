<?php

/**
 * This is the Indigent Patients Report.  It displays a summary of
 * encounters within the specified time period for patients without
 * insurance.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2005-2015, 2020 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017-2020 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('acct', 'rep_a')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Indigent Patients Report")]);
    exit;
}

$alertmsg = '';

function bucks($amount)
{
    if ($amount) {
        return oeFormatMoney($amount);
    }

    return "";
}

$form_start_date = (!empty($_POST['form_start_date'])) ?  DateToYYYYMMDD($_POST['form_start_date']) : date('Y-01-01');
$form_end_date  = (!empty($_POST['form_end_date'])) ? DateToYYYYMMDD($_POST['form_end_date']) : date('Y-m-d');

?>
<html>
<head>

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
.mdi-hint { font-size: 12px; color: #6b7280; font-style: italic; padding: 10px 16px; }

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

<?php Header::setupHeader('datetime-picker'); ?>

<title><?php echo xlt('Indigent Patients Report')?></title>

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

</head>

<body class="body_top">

<!-- Page Title Bar -->
<div class="mdi-report-header"><?php echo xlt('Report - Indigent Patients'); ?></div>

<form method='post' action='indigent_patients_report.php' id='theform' onsubmit='return top.restoreSession()'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type='hidden' name='form_refresh' id='form_refresh' value=''/>

<!-- Filter Bar -->
<div id="report_parameters" class="mdi-filter-bar">
    <span class="mdi-filter-group">
        <?php echo xlt('Visits From'); ?>:
        <input type='text' class='datepicker form-control' name='form_start_date' id="form_start_date" size='10'
            value='<?php echo attr(oeFormatShortDate($form_start_date)); ?>' />
    </span>
    <span class="mdi-filter-group">
        <?php echo xlt('To{{Range}}'); ?>:
        <input type='text' class='datepicker form-control' name='form_end_date' id="form_end_date" size='10'
            value='<?php echo attr(oeFormatShortDate($form_end_date)); ?>' />
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
<div id="report_results">
<table class='table'>

 <thead class="thead-light">
  <th>
   &nbsp;<?php echo xlt('Patient'); ?>
  </th>
  <th>
   &nbsp;<?php echo xlt('SSN'); ?>
  </th>
  <th>
   &nbsp;<?php echo xlt('Invoice'); ?>
  </th>
  <th>
   &nbsp;<?php echo xlt('Svc Date'); ?>
  </th>
  <th>
   &nbsp;<?php echo xlt('Due Date'); ?>
  </th>
  <th align="right">
    <?php echo xlt('Amount'); ?>&nbsp;
  </th>
  <th align="right">
    <?php echo xlt('Paid'); ?>&nbsp;
  </th>
  <th align="right">
    <?php echo xlt('Balance'); ?>&nbsp;
  </th>
 </thead>

<?php
if (!empty($_POST['form_refresh'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    $where = "";
    $sqlBindArray = array();

    if ($form_start_date) {
        $where .= " AND e.date >= ?";
        array_push($sqlBindArray, $form_start_date);
    }

    if ($form_end_date) {
        $where .= " AND e.date <= ?";
        array_push($sqlBindArray, $form_end_date);
    }

    $rez = sqlStatement("SELECT " .
    "e.date, e.encounter, p.pid, p.lname, p.fname, p.mname, p.ss " .
    "FROM form_encounter AS e, patient_data AS p, insurance_data AS i " .
    "WHERE p.pid = e.pid AND i.pid = e.pid AND i.type = 'primary' " .
    "AND i.provider = ''$where " .
    "ORDER BY p.lname, p.fname, p.mname, p.pid, e.date", $sqlBindArray);

    $total_amount = 0;
    $total_paid   = 0;

    for ($irow = 0; $row = sqlFetchArray($rez); ++$irow) {
        $patient_id = $row['pid'];
        $encounter_id = $row['encounter'];
        $invnumber = $row['pid'] . "." . $row['encounter'];
        $inv_duedate = '';
        $arow = sqlQuery("SELECT SUM(fee) AS amount FROM drug_sales WHERE " .
        "pid = ? AND encounter = ?", array($patient_id, $encounter_id));
        $inv_amount = $arow['amount'];
        $arow = sqlQuery("SELECT SUM(fee) AS amount FROM billing WHERE " .
          "pid = ? AND encounter = ? AND " .
          "activity = 1 AND code_type != 'COPAY'", array($patient_id, $encounter_id));
        $inv_amount += $arow['amount'];
        $arow = sqlQuery("SELECT SUM(fee) AS amount FROM billing WHERE " .
          "pid = ? AND encounter = ? AND " .
          "activity = 1 AND code_type = 'COPAY'", array($patient_id, $encounter_id));
        $inv_paid = 0 - $arow['amount'];
        $arow = sqlQuery(
            "SELECT SUM(pay_amount) AS pay, sum(adj_amount) AS adj " .
            "FROM ar_activity WHERE pid = ? AND encounter = ? AND deleted IS NULL",
            array($patient_id, $encounter_id)
        );
        $inv_paid   += floatval($arow['pay']);
        $inv_amount -= floatval($arow['adj']);
        $total_amount += $inv_amount;
        $total_paid   += $inv_paid;

        $bgcolor = (($irow & 1) ? "#ffdddd" : "#ddddff");
        ?>
  <tr bgcolor='<?php  echo $bgcolor ?>'>
<td class="detail">
 &nbsp;<?php echo text($row['lname'] . ', ' . $row['fname'] . ' ' . $row['mname']); ?>
</td>
<td class="detail">
 &nbsp;<?php echo text($row['ss']); ?>
</td>
<td class="detail">
 &nbsp;<?php echo text($invnumber); ?></a>
</td>
<td class="detail">
 &nbsp;<?php echo text(oeFormatShortDate(substr($row['date'], 0, 10))); ?>
</td>
<td class="detail">
 &nbsp;<?php echo text(oeFormatShortDate($inv_duedate)); ?>
</td>
<td class="detail" align="right">
        <?php echo bucks($inv_amount); ?>&nbsp;
</td>
<td class="detail" align="right">
        <?php echo bucks($inv_paid); ?>&nbsp;
</td>
<td class="detail" align="right">
        <?php echo bucks($inv_amount - $inv_paid); ?>&nbsp;
</td>
</tr>
        <?php
    }
    ?>
<tr class="table-light">
<td class="detail">
&nbsp;<?php echo xlt('Totals'); ?>
</td>
<td class="detail">
 &nbsp;
</td>
<td class="detail">
 &nbsp;
</td>
<td class="detail">
 &nbsp;
</td>
<td class="detail">
 &nbsp;
</td>
<td class="detail" align="right">
    <?php echo bucks($total_amount); ?>&nbsp;
</td>
<td class="detail" align="right">
    <?php echo bucks($total_paid); ?>&nbsp;
</td>
<td class="detail" align="right">
    <?php echo bucks($total_amount - $total_paid); ?>&nbsp;
</td>
</tr>
    <?php
}
?>

</table>
</div><!-- #report_results -->
</div><!-- .mdi-results-wrap -->

</form>
<script>
<?php
if ($alertmsg) {
    echo "alert(" . js_escape($alertmsg) . ");\n";
}
?>
</script>
</body>

</html>
