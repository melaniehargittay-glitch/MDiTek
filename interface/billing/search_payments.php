<?php
/**
* Payments in database can be searched through this screen and edit popup is also its part.
* Deletion of the payment is done with logging.
*
*
* @package   OpenEMR
* @link      http://www.open-emr.org
* @author    Eldho Chacko <eldho@zhservices.com>
* @author    Paul Simon K <paul@zhservices.com>
* @author    Brady Miller <brady.g.miller@gmail.com>
* @author    Rod Roark <rod@sunsetsystems.com>
* @copyright Copyright (c) 2010 Z&H Consultancy Services Private Limited <sam@zhservices.com>
* @copyright Copyright (c) 2019-2020 Brady Miller <brady.g.miller@gmail.com>
* @copyright Copyright (c) 2020 Rod Roark <rod@sunsetsystems.com>
* @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
*/

require_once("../globals.php");
require_once("../../custom/code_types.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/payment.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

if (!AclMain::aclCheckCore('acct', 'bill', '', 'write') && !AclMain::aclCheckCore('acct', 'eob', '', 'write')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Search Payment")]);
    exit;
}

//===============================================================================
//Deletion of payment and its corresponding distributions.
//===============================================================================
set_time_limit(0);
if (isset($_POST["mode"])) {
    if ($_POST["mode"] == "DeletePayments") {
        $DeletePaymentId = isset($_POST['DeletePaymentId']) ? trim($_POST['DeletePaymentId']) : '';
        $ResultSearch = sqlStatement(
            "SELECT distinct encounter, pid from ar_activity where deleted IS NULL AND session_id = ?",
            [$DeletePaymentId]
        );
        if (sqlNumRows($ResultSearch) > 0) {
            while ($RowSearch = sqlFetchArray($ResultSearch)) {
                $Encounter = $RowSearch['encounter'];
                $PId = $RowSearch['pid'];
                sqlStatement("update form_encounter set last_level_closed=last_level_closed - 1 where pid =? and encounter=?", [$PId, $Encounter]);
            }
        }

    //delete and log that action
        row_delete("ar_session", "session_id ='" . add_escape_custom($DeletePaymentId) . "'");
        row_modify("ar_activity", "deleted = NOW()", "deleted IS NULL AND session_id = '" . add_escape_custom($DeletePaymentId) . "'");
        $Message = 'Delete';
    //------------------
        $_POST["mode"] = "SearchPayment";
    }

//===============================================================================
//Search section.
//===============================================================================
    if ($_POST["mode"] == "SearchPayment") {
        $FromDate = isset($_POST['FromDate']) ? trim($_POST['FromDate']) : '';
        $ToDate = isset($_POST['ToDate']) ? trim($_POST['ToDate']) : '';
        $PaymentMethod = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : '';
        $CheckNumber = isset($_POST['check_number']) ? trim($_POST['check_number']) : '';
        $PaymentAmount = isset($_POST['payment_amount']) ? trim($_POST['payment_amount']) : '';
        $PayingEntity = isset($_POST['type_name']) ? trim($_POST['type_name']) : '';
        $PaymentCategory = isset($_POST['adjustment_code']) ? trim($_POST['adjustment_code']) : '';
        $PaymentFrom = isset($_POST['hidden_type_code']) ? trim($_POST['hidden_type_code']) : '';
        $PaymentStatus = isset($_POST['PaymentStatus']) ? trim($_POST['PaymentStatus']) : '';
        $PaymentSortBy = isset($_POST['PaymentSortBy']) ? trim($_POST['PaymentSortBy']) : '';
        $PaymentDate = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : '';
        $QueryString = "Select * from  ar_session where  ";
        $And = '';

        $sqlBindArray = array();

        // Default to check_date so $PaymentDateString is always defined
        if ($PaymentDate == 'post_to_date') {
            $PaymentDateString = ' post_to_date ';
        } elseif ($PaymentDate == 'deposit_date') {
            $PaymentDateString = ' deposit_date ';
        } else {
            // covers 'date_val', empty, or any unrecognised value
            $PaymentDateString = ' check_date ';
        }

        if ($FromDate != '') {
            $QueryString .= " $And $PaymentDateString >=?";
            $And = ' and ';
            $sqlBindArray[] = DateToYYYYMMDD($FromDate);
        }

        if ($ToDate != '') {
            $QueryString .= " $And $PaymentDateString <=?";
            $And = ' and ';
            $sqlBindArray[] = DateToYYYYMMDD($ToDate);
        }

        if ($PaymentMethod != '') {
            $QueryString .= " $And payment_method =?";
            $And = ' and ';
            $sqlBindArray[] = $PaymentMethod;
        }

        if ($CheckNumber != '') {
            $QueryString .= " $And reference like ?";
            $And = ' and ';
            $sqlBindArray[] = '%' . $CheckNumber . '%';
        }

        if ($PaymentAmount != '') {
            $QueryString .= " $And pay_total =?";
            $And = ' and ';
            $sqlBindArray[] = $PaymentAmount;
        }

        if ($PayingEntity != '') {
            if ($PayingEntity == 'insurance') {
                $QueryString .= " $And payer_id !='0'";
            }

            if ($PayingEntity == 'patient') {
                $QueryString .= " $And payer_id ='0'";
            }

            $And = ' and ';
        }

        if ($PaymentCategory != '') {
            $QueryString .= " $And adjustment_code =?";
            $And = ' and ';
            $sqlBindArray[] = $PaymentCategory;
        }

        if ($PaymentFrom != '') {
            if ($PayingEntity == 'insurance' || $PayingEntity == '') {
                //-------------------
                $res = sqlStatement("SELECT insurance_companies.name FROM insurance_companies
            where insurance_companies.id =?", [$PaymentFrom]);
                $row = sqlFetchArray($res);
                $div_after_save = $row['name'];
                //-------------------

                $QueryString .= " $And payer_id =?";
                $sqlBindArray[] = $PaymentFrom;
            }

            if ($PayingEntity == 'patient') {
                //-------------------
                $res = sqlStatement("SELECT fname,lname,mname FROM patient_data
            where pid =?", [$PaymentFrom]);
                $row = sqlFetchArray($res);
                $fname = $row['fname'];
                $lname = $row['lname'];
                $mname = $row['mname'];
                $div_after_save = $lname . ' ' . $fname . ' ' . $mname;
                //-------------------

                $QueryString .= " $And patient_id =?";
                $sqlBindArray[] = $PaymentFrom;
            }

            $And = ' and ';
        }

        if ($PaymentStatus != '') {
            $QsString = "select ar_session.session_id, pay_total, global_amount, sum(pay_amount) sum_pay_amount " .
                "from ar_session,ar_activity WHERE " .
                "ar_activity.deleted IS NULL AND ar_session.session_id = ar_activity.session_id " .
                "group by ar_activity.session_id, ar_session.session_id " .
                "having pay_total - global_amount - sum_pay_amount = 0 or pay_total = 0";
            $rs = sqlStatement($QsString);
            while ($rowrs = sqlFetchArray($rs)) {
                $StringSessionId .= $rowrs['session_id'] . ',';
            }

            $QsString = "select ar_session.session_id from ar_session	where pay_total=0";
            $rs = sqlStatement($QsString);
            while ($rowrs = sqlFetchArray($rs)) {
                $StringSessionId .= $rowrs['session_id'] . ',';
            }

            $StringSessionId = substr($StringSessionId, 0, -1);
            if ($PaymentStatus == 'fully_paid') {
                $QueryString .= " $And session_id in(" . add_escape_custom($StringSessionId) . ") ";
            } elseif ($PaymentStatus == 'unapplied') {
                $QueryString .= " $And session_id not in(" . add_escape_custom($StringSessionId) . ") ";
            }

            $And = ' and ';
        }

        if ($PaymentSortBy != '') {
            $SortFieldOld = isset($_POST['SortFieldOld']) ? trim($_POST['SortFieldOld']) : '';
            $Sort = isset($_POST['Sort']) ? trim($_POST['Sort']) : '';
            if ($SortFieldOld == $PaymentSortBy) {
                if ($Sort == 'DESC' || $Sort == '') {
                    $Sort = 'ASC';
                } else {
                    $Sort = 'DESC';
                }
            } else {
                $Sort = 'ASC';
            }

            $QueryString .= " order by " . escape_sql_column_name($PaymentSortBy, ['ar_session']) . " " . escape_sort_order($Sort);
        }

        $ResultSearch = sqlStatement($QueryString, $sqlBindArray);
    }
}

//===============================================================================
?>
<!DOCTYPE html>
<html>
<head>
<title><?php echo xlt("Search Payment") ?></title>
<?php Header::setupHeader(['datetime-picker']); ?>

<?php include_once("{$GLOBALS['srcdir']}/payment_jav.inc.php"); ?>
<?php include_once("{$GLOBALS['srcdir']}/ajax/payment_ajax_jav.inc.php"); ?>

<script>
    function refreshSearch() {
        top.restoreSession();
        SearchPayment();
    }

    $(function () {
    <?php if (!empty($_POST['mode']) && ($_POST['mode'] == 'SearchPayment')) { ?>
        $("html").animate({ scrollTop: $("div table.table").offset().top }, 800);
    <?php } ?>

        $(".medium_modal").on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            dlgopen('', '', 'modal-full', 800, '', '', {
                buttons: [
                    {text: <?php echo xlj('Close'); ?>, close: true, style: 'default btn-sm'}
                ],
                sizeHeight: '',
                type: 'iframe',
                url: $(this).attr('href')
            });
        });

        $('.datepicker').datetimepicker({
            <?php $datetimepicker_timepicker = false; ?>
            <?php $datetimepicker_showseconds = false; ?>
            <?php $datetimepicker_formatInput = true; ?>
            <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
            <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
        });
    });

    var mypcc = '1';

    function SearchPayment() {
        // Search  validations.
        if (document.getElementById('FromDate').value == '' && document.getElementById('ToDate').value == '' && document.getElementById('PaymentStatus').selectedIndex == 0 && document.getElementById('payment_method').selectedIndex == 0 && document.getElementById('type_name').selectedIndex == 0 && document.getElementById('adjustment_code').selectedIndex == 0 && document.getElementById('check_number').value == '' && document.getElementById('payment_amount').value == '' && document.getElementById('hidden_type_code').value == '') {
            alert(<?php echo xlj('Please select any Search Option.'); ?>);
            return false;
        }
        if (document.getElementById('FromDate').value != '' && document.getElementById('ToDate').value != '') {
            if (!DateCheckGreater(document.getElementById('FromDate').value, document.getElementById('ToDate').value, '<?php echo DateFormatRead();?>')) {
                alert(<?php echo xlj('From Date Cannot be Greater than To Date.'); ?>);
                document.getElementById('FromDate').focus();
                return false;
            }
        }
        top.restoreSession();
        document.getElementById('mode').value = 'SearchPayment';
        document.forms[0].submit();
    }

    function OnloadAction() {
        // No-op kept for onload compatibility
    }

    function SearchPayingEntityAction() {
        // Which ajax is to be active(patient,insurance), is decided by the 'Paying Entity' drop down, where this function is called.
        // So on changing some initialization is need.Done below.
        document.getElementById('type_code').value = '';
        document.getElementById('hidden_ajax_close_value').value = '';
        document.getElementById('hidden_type_code').value = '';
        document.getElementById('div_insurance_or_patient').innerHTML = '&nbsp;';
        document.getElementById('description').value = '';
        if (document.getElementById('ajax_div_insurance')) {
            $("#ajax_div_patient_error").empty();
            $("#ajax_div_patient").empty();
            $("#ajax_div_insurance_error").empty();
            $("#ajax_div_insurance").empty();
            $("#ajax_div_insurance").hide();
            document.getElementById('payment_method').style.display = '';
        }
    }

    document.onclick = HideTheAjaxDivs;

    function viewEraFile(ref, sessionId) {
        var url = 'era_viewer.php?ref=' + encodeURIComponent(ref) + '&session_id=' + encodeURIComponent(sessionId);
        dlgopen(url, 'era_viewer_' + sessionId, 'modal-lg', 600, '', 'ERA File Viewer', {
            buttons: [{text: <?php echo xlj('Close'); ?>, close: true, style: 'default btn-sm'}],
            sizeHeight: 'auto',
            type: 'iframe'
        });
    }

    function deletePayment(paymentId) {
        if (!confirm(<?php echo xlj('Are you sure you want to delete this payment and all its distributions? This cannot be undone.'); ?>)) {
            return false;
        }
        document.getElementById('mode').value = 'DeletePayments';
        document.getElementById('DeletePaymentId').value = paymentId;
        top.restoreSession();
        document.forms[0].submit();
        return false;
    }
</script>

<style>
#ajax_div_insurance {
    position: absolute;
    z-index: 10;
    background-color: #FBFDD0;
    border: 1px solid var(--gray);
    padding: 10px;
}
@media (min-width: 992px) {
    .modal-lg { width: 1000px !Important; }
}

/* ── Clean search results table ── */
.table-responsive-sm .table {
    border-collapse: collapse;
    width: 100%;
    background: #fff;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.07), 0 4px 16px rgba(0,0,0,.05);
    border: 1px solid #e2e6f0;
}
.table-responsive-sm .table thead tr {
    background: #1a1d2e;
    color: #fff;
}
.table-responsive-sm .table thead th {
    padding: 9px 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #9197b3;
    border: none;
    white-space: nowrap;
}
tr.sp-row td {
    padding: 9px 12px;
    border-bottom: 1px solid #e2e6f0;
    font-size: 12px;
    vertical-align: middle;
    background: #fff;
    color: #1a1d2e;
}
tr.sp-row:last-child td { border-bottom: none; }
tr.sp-row:hover td { background: #f7f8fc; }

/* ID link */
.sp-id a { color: #5b21b6; font-weight: 600; text-decoration: none; }
.sp-id a:hover { text-decoration: underline; }

/* Mono amounts */
.sp-mono { font-family: 'DM Mono', monospace; font-size: 11px; }
.sp-zero    { color: #0ca678; font-weight: 600; }
.sp-nonzero { color: #e03131; font-weight: 600; }

/* Status badges */
.sp-badge {
    display: inline-flex; align-items: center;
    padding: 2px 9px; border-radius: 20px;
    font-size: 10px; font-weight: 700; white-space: nowrap;
}
.sp-badge-applied  { background: #e6f9f3; color: #0a5c44; }
.sp-badge-unapplied { background: #fff8e6; color: #92400e; }

/* Action buttons */
.sp-actions { white-space: nowrap; }
.sp-btn-edit, .sp-btn-post, .sp-btn-era {
    display: inline-flex; align-items: center;
    padding: 4px 11px; border-radius: 5px;
    font-size: 11px; font-weight: 700;
    text-decoration: none; cursor: pointer;
    transition: all .12s; margin-bottom: 2px;
}
.sp-btn-edit  { background: #5b21b6; color: #fff; margin-right: 4px; }
.sp-btn-edit:hover  { background: #4c1d95; color: #fff; text-decoration: none; }
.sp-btn-post  { background: #0a7c4e; color: #fff; border: 1px solid #0a7c4e; margin-right: 4px; }
.sp-btn-post:hover  { background: #065f3a; color: #fff; text-decoration: none; }
.sp-btn-era   { background: #0369a1; color: #fff; border: 1px solid #0369a1; margin-right: 4px; }
.sp-btn-era:hover   { background: #075985; color: #fff; text-decoration: none; }
.sp-btn-delete { display:inline-flex;align-items:center;gap:4px;background:#fff;color:#dc2626;border:1.5px solid #fca5a5;padding:5px 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;text-decoration:none;transition:all .12s;margin-right:4px; }
.sp-btn-delete:hover { background:#fee2e2;color:#b91c1c;border-color:#f87171;text-decoration:none; }
/* ── Modern Payment Nav ──────────────────────────────────────────────────── */
.pay-nav-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:6px;margin-bottom:1.5rem;display:flex;align-items:center;gap:2px;flex-wrap:wrap;box-shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);}
.pay-nav-wrap .pnav-item{display:flex;align-items:center;gap:7px;padding:8px 15px;border-radius:7px;font-size:13px;font-weight:500;color:#64748b;text-decoration:none!important;transition:background .15s,color .15s;white-space:nowrap;border:1px solid transparent;line-height:1;}
.pay-nav-wrap .pnav-item svg{flex-shrink:0;opacity:.7;transition:opacity .15s;}
.pay-nav-wrap .pnav-item:hover{background:#f8fafc;color:#1e293b;border-color:#e2e8f0;text-decoration:none!important;}
.pay-nav-wrap .pnav-item:hover svg{opacity:1;}
.pay-nav-wrap .pnav-item.pnav-active{background:#f5f3ff;color:#6610f2;border-color:#ddd6fe;font-weight:600;box-shadow:0 1px 3px rgba(102,16,242,.1);}
.pay-nav-wrap .pnav-item.pnav-active svg{opacity:1;}
.pnav-badge{display:inline-flex;align-items:center;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700;line-height:1.4;background:#f5f3ff;color:#6610f2;border:1px solid #ddd6fe;letter-spacing:.03em;margin-left:2px;}
.pnav-item.pnav-active .pnav-badge{background:#ede9fe;border-color:#c4b5fd;}
.pnav-sep{width:1px;height:20px;background:#e2e8f0;margin:0 3px;flex-shrink:0;}
@media(max-width:768px){.pay-nav-wrap{gap:1px;padding:4px;}.pay-nav-wrap .pnav-item{padding:7px 10px;font-size:12px;}.pnav-label{display:none;}.pnav-sep{display:none;}}
</style>
<?php
$arrOeUiSettings = array(
'heading_title' => xl('Payments'),
'include_patient_name' => false,// use only in appropriate pages
'expandable' => true,
'expandable_files' => array("search_payments_xpd", "new_payment_xpd", "era_payments_xpd"),//all file names need suffix _xpd
'action' => "",//conceal, reveal, search, reset, link or back
'action_title' => "",
'action_href' => "",//only for actions - reset, link or back
'show_help_icon' => false,
'help_file_name' => ""
);
$oemr_ui = new OemrUI($arrOeUiSettings);
?>
</head>
<body onload="OnloadAction()">
    <div id="container_div" class="<?php echo attr($oemr_ui->oeContainer()); ?> mt-3">
        <div class="row">
            <div class="col-sm-12">
                <?php echo $oemr_ui->pageHeading() . "\r\n"; ?>
            </div>
        </div>
  <!-- ── Modern Payment Navigation ──────────────────────────────────── -->
  <nav class="pay-nav-wrap" aria-label="<?php echo xla('Payment Navigation'); ?>">
    <a class="pnav-item" href="new_payment.php">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="7" y1="15" x2="10" y2="15"/><line x1="14" y1="15" x2="17" y2="15"/></svg>
      <span class="pnav-label"><?php echo xlt('New Payment'); ?></span>
    </a>
    <div class="pnav-sep"></div>
    <a class="pnav-item pnav-active" href="search_payments.php" aria-current="page">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      <span class="pnav-label"><?php echo xlt('Search Payment'); ?></span>
    </a>
    <div class="pnav-sep"></div>
    <a class="pnav-item" href="era_payments.php">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span class="pnav-label"><?php echo xlt('ERA Posting'); ?></span>
    </a>
    <div class="pnav-sep"></div>
    <a class="pnav-item" href="sl_eob_search.php">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/><path d="M14 2v6h6"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      <span class="pnav-label"><?php echo xlt('EOB Posting'); ?></span>
    </a>
    <div class="pnav-sep"></div>
    <a class="pnav-item" href="sl_eob_search.php">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
      <span class="pnav-label"><?php echo xlt('Invoice Search'); ?></span>
    </a>
    <div class="pnav-sep"></div>
    <a class="pnav-item" href="legacy_eob_posting.php">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
      <span class="pnav-label"><?php echo xlt('Legacy EOB Posting'); ?></span><span class="pnav-badge">NEW</span>
    </a>
  </nav>
  <!-- ── End Payment Navigation ──────────────────────────────────────── -->
        <div class="row">
            <div class="col-sm-12">
                <form id="new_payment" method='post' name='new_payment'>
                    <fieldset>
                        <div class="jumbotron py-4">
                            <div class="row h3">
                                <?php echo xlt('Payment List'); ?>
                            </div>
                            <div class="row oe-custom-line">
                                <div class="forms col-2">
                                    <label class="control-label" for="payment_date"><?php echo xlt('Payment date'); ?>:</label>
                                    <?php echo generate_select_list("payment_date", "payment_date", ($PaymentDate ?? ''), "Payment Date", "", ""); ?>
                                </div>
                                <div class="forms col-2">
                                    <label class="control-label" for="FromDate"><?php echo xlt('From'); ?>:</label>
                                    <input class="form-control datepicker" id='FromDate' name='FromDate' type='text' value='<?php echo attr($FromDate ?? ''); ?>' autocomplete="off" />
                                </div>
                                <div class="forms col-2">
                                    <label class="control-label" for="ToDate"><?php echo xlt('To{{Range}}'); ?>:</label>
                                    <input class="form-control datepicker" id='ToDate' name='ToDate' type='text' value='<?php echo attr($ToDate ?? ''); ?>' autocomplete="off" />
                                </div>
                                <div class="forms col-3">
                                    <label class="control-label" for="payment_method"><?php echo xlt('Payment Method'); ?>:</label>
                                    <?php echo generate_select_list("payment_method", "payment_method", ($PaymentMethod ?? ''), "Payment Method", " ", ""); ?>
                                </div>
                                <div class="forms col-3">
                                    <label class="control-label" for="check_number"><?php echo xlt('Check Number'); ?>:</label>
                                    <input autocomplete="off" class="form-control" id="check_number" name="check_number" type="text" value="<?php echo attr($_POST['check_number'] ?? ''); ?>" />
                                </div>
                            </div>
                            <div class="row oe-custom-line">
                                <div class="forms col-4">
                                    <label class="control-label" for="payment_method"><?php echo xlt('Payment Amount'); ?>:</label>
                                    <input autocomplete="off" class="form-control" id="payment_amount" name="payment_amount" onkeyup="ValidateNumeric(this);" type="text" value="<?php echo attr($_POST['payment_amount'] ?? ''); ?>" />
                                </div>
                                <div class="forms col-2">
                                    <label class="control-label" for="type_name"><?php echo xlt('Paying Entity'); ?>:</label>
                                    <?php echo generate_select_list("type_name", "payment_type", ($type_name ?? ''), "Paying Entity", " ", "", "SearchPayingEntityAction()"); ?>
                                </div>
                                <div class="forms col-3">
                                    <label class="control-label" for="adjustment_code"><?php echo xlt('Payment Category'); ?>:</label>
                                    <?php echo generate_select_list("adjustment_code", "payment_adjustment_code", ($adjustment_code ?? ''), "Paying Category", " ", ""); ?>
                                </div>
                                <div class="forms col-3">
                                    <label class="control-label" for="PaymentStatus"><?php echo xlt('Pay Status'); ?>:</label>
                                    <?php echo generate_select_list("PaymentStatus", "payment_status", ($PaymentStatus ?? ''), "Pay Status", " ", ""); ?>
                                </div>
                            </div>
                            <div class="row oe-custom-line">
                                <div class="forms col-4">
                                    <label class="control-label" for="type_code"><?php echo xlt('Payment From'); ?>:</label>
                                    <input id="hidden_ajax_close_value" type="hidden" value="<?php echo attr($div_after_save ?? ''); ?>" />
                                    <input autocomplete="off" class="form-control" id='type_code' name='type_code' onkeydown="PreventIt(event)" type="text" value="<?php echo attr($div_after_save ?? ''); ?>" />
                                    <!--onKeyUp="ajaxFunction(event,'non','search_payments.php');"-->
                                    <div id='ajax_div_insurance_section'>
                                        <div id='ajax_div_insurance_error'></div>
                                        <div id="ajax_div_insurance" style="display:none;"></div>
                                    </div>
                                </div>
                                <div class="forms col-2">
                                    <label class="control-label" for="div_insurance_or_patient"><?php echo xlt('Payor ID'); ?>:</label>
                                    <div class="form-control" id="div_insurance_or_patient"><?php echo attr($_POST['hidden_type_code'] ?? ''); ?></div>
                                    <input id="description" name="description" type="hidden" />
                                    <input id="deposit_date" name="deposit_date" style="display:none" type="text" />
                                </div>
                                <div class="forms col-3">
                                    <label class="control-label" for="PaymentSortBy"><?php echo xlt('Sort Result by'); ?>:</label>
                                    <?php echo generate_select_list("PaymentSortBy", "payment_sort_by", ($PaymentSortBy ?? ''), "Sort Result by", " ", ""); ?>
                                </div>
                            </div>
                            <!-- can change position of buttons by creating a class 'position-override' and adding rule text-align:center or right as the case may be in individual stylesheets -->
                            <div class="form-group mt-3">
                                <div class="col-sm-12 text-left position-override">
                                    <div class="btn-group" role="group">
                                        <a class="btn btn-primary btn-search" href="#" onclick="javascript:return SearchPayment();"><span><?php echo xlt('Search'); ?></span></a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </fieldset><!--End of Search-->
                    <?php
                    if (!empty($_POST["mode"]) && ($_POST["mode"] == "SearchPayment")) {
                        echo "&nbsp;" . "<br />"; // do not remove else below div will not display !!
                        ?>
                        <div class="table-responsive-sm">
                            <table class="table table-sm table-bordered">
                                <?php
                                if (sqlNumRows($ResultSearch) > 0) { ?>
                                <thead>
                                <tr>
                                <th><?php echo xlt('ID'); ?></th>
                                <th><?php
                                    if (($PaymentDate ?? '') == 'post_to_date') echo xlt('Post To Date');
                                    elseif (($PaymentDate ?? '') == 'deposit_date') echo xlt('Deposit Date');
                                    else echo xlt('Check Date');
                                ?></th>
                                <th><?php echo xlt('Paying Entity'); ?></th>
                                <th><?php echo xlt('Payer'); ?></th>
                                <th><?php echo xlt('Ins Code'); ?></th>
                                <th><?php echo xlt('Payment Method'); ?></th>
                                <th><?php echo xlt('Check Number'); ?></th>
                                <th><?php echo xlt('Pay Status'); ?></th>
                                <th class="text-right"><?php echo xlt('Payment'); ?></th>
                                <th class="text-right"><?php echo xlt('Undistributed'); ?></th>
                                <th class="text-center"><?php echo xlt('Action'); ?></th>
                                </tr>
                                    </thead>
                                    <?php
                                    $CountIndex = 0;
                                    while ($RowSearch = sqlFetchArray($ResultSearch)) {
                                        $Payer = '';
                                        if ($RowSearch['payer_id'] * 1 > 0) {
                                            // Insurance payment — look up by payer_id
                                            $res = sqlStatement(
                                                "SELECT name FROM insurance_companies WHERE id = ?",
                                                [$RowSearch['payer_id']]
                                            );
                                            $row = sqlFetchArray($res);
                                            $Payer = $row['name'] ?? '';
                                        } elseif ($RowSearch['patient_id'] * 1 > 0) {
                                            // Patient payment — look up by patient_id
                                            $res = sqlStatement(
                                                "SELECT fname, lname, mname FROM patient_data WHERE pid = ?",
                                                [$RowSearch['patient_id']]
                                            );
                                            $row = sqlFetchArray($res);
                                            if ($row) {
                                                $Payer = trim(($row['lname'] ?? '') . ' ' . ($row['fname'] ?? '') . ' ' . ($row['mname'] ?? ''));
                                            }
                                        } elseif (!empty($RowSearch['payment_type'])) {
                                            // Unapplied payment with no payer/patient linked yet; show type as fallback
                                            $Payer = '(' . xlt($RowSearch['payment_type']) . ' - ' . xlt('Unapplied') . ')';
                                        }
                                        //=============================================
                                        $CountIndex++;
                                        ?>
                                        <tr class="sp-row">
                                            <td class="sp-id">
                                                <a class="medium_modal" href="edit_payment.php?payment_id=<?php echo attr_url($RowSearch['session_id']); ?>"><?php echo text($RowSearch['session_id']); ?></a>
                                            </td>
                                            <td><?php
                                                if (($PaymentDate ?? '') == 'post_to_date') {
                                                    $displayDate = $RowSearch['post_to_date'];
                                                } elseif (($PaymentDate ?? '') == 'deposit_date') {
                                                    $displayDate = $RowSearch['deposit_date'];
                                                } else {
                                                    $displayDate = $RowSearch['check_date'];
                                                }
                                                echo ($displayDate == '0000-00-00' || empty($displayDate)) ? '&nbsp;' : text(oeFormatShortDate($displayDate));
                                            ?></td>
                                            <td>
                                                <?php
                                                $frow['data_type'] = 1;
                                                $frow['list_id'] = 'payment_type';
                                                $PaymentType = '';
                                                if ($RowSearch['payment_type'] == 'insurance' || $RowSearch['payer_id'] * 1 > 0) {
                                                    $PaymentType = 'insurance';
                                                } elseif ($RowSearch['payment_type'] == 'patient' || $RowSearch['patient_id'] * 1 > 0) {
                                                    $PaymentType = 'patient';
                                                } elseif (($RowSearch['payer_id'] * 1 == 0 && $RowSearch['patient_id'] * 1 == 0)) {
                                                    $PaymentType = '';
                                                }
                                                generate_print_field($frow, $PaymentType);
                                                ?>
                                            </td>
                                            <td><?php echo $Payer == '' ? '&nbsp;' : text($Payer); ?></td>
                                            <td><?php echo $RowSearch['payer_id'] * 1 > 0 ? text($RowSearch['payer_id']) : '&nbsp;'; ?></td>
                                            <td>
                                                <?php
                                                $frow['data_type'] = 1;
                                                $frow['list_id'] = 'payment_method';
                                                generate_print_field($frow, $RowSearch['payment_method']);
                                                ?>
                                            </td>
                                            <td><?php echo $RowSearch['reference'] == '' ? '&nbsp;' : text($RowSearch['reference']); ?></td>
                                            <td>
                                                <?php
                                                $rs = sqlStatement("select pay_total,global_amount from ar_session where session_id=?", [$RowSearch['session_id']]);
                                                $row = sqlFetchArray($rs);
                                                $pay_total     = $row['pay_total'];
                                                $global_amount = $row['global_amount'];
                                                $rs = sqlStatement(
                                                    "select sum(pay_amount) sum_pay_amount from ar_activity where deleted IS NULL AND session_id = ?",
                                                    [$RowSearch['session_id']]
                                                );
                                                $row              = sqlFetchArray($rs);
                                                $pay_amount       = $row['sum_pay_amount'];
                                                $UndistributedAmount = $pay_total - $pay_amount - $global_amount;
                                                $isApplied        = ($UndistributedAmount * 1 == 0);
                                                ?>
                                                <span class="sp-badge <?php echo $isApplied ? 'sp-badge-applied' : 'sp-badge-unapplied'; ?>">
                                                    <?php echo $isApplied ? xlt('Applied') : xlt('Unapplied'); ?>
                                                </span>
                                            </td>
                                            <td class="text-right sp-mono">$<?php echo text(number_format($pay_total, 2)); ?></td>
                                            <td class="text-right sp-mono <?php echo $isApplied ? 'sp-zero' : 'sp-nonzero'; ?>">
                                                $<?php echo text(number_format($UndistributedAmount, 2)); ?>
                                            </td>
                                            <td class="text-center sp-actions">
                                                <?php $isElectronic = strtolower($RowSearch['payment_method'] ?? '') === 'electronic'; ?>
                                                <?php if (!$isElectronic): ?>
                                                    <a class="medium_modal sp-btn-edit" href="edit_payment.php?payment_id=<?php echo attr_url($RowSearch['session_id']); ?>">
                                                        &#x270E;&nbsp;<?php echo xlt('Edit'); ?>
                                                    </a>
                                                    <?php if (!$isApplied): ?>
                                                    <a class="medium_modal sp-btn-post" href="new_payment.php?payment_id=<?php echo attr_url($RowSearch['session_id']); ?>&post_date=<?php echo attr_url($RowSearch['post_to_date']); ?>">
                                                        &#x2794;&nbsp;<?php echo xlt('Post'); ?>
                                                    </a>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <a class="sp-btn-era" href="#"
                                                       onclick="viewEraFile(<?php echo attr_js($RowSearch['reference'] ?? ''); ?>, <?php echo attr_js($RowSearch['session_id']); ?>); return false;">
                                                        &#x1F4C4;&nbsp;<?php echo xlt('View ERA'); ?>
                                                    </a>
                                                <?php endif; ?>
                                                <a class="sp-btn-delete" href="#"
                                                   onclick="return deletePayment(<?php echo attr_js($RowSearch['session_id']); ?>);">
                                                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="vertical-align:middle;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                                    <?php echo xlt('Delete'); ?>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php
                                    }// End of while ($RowSearch = sqlFetchArray($ResultSearch))
                                } else { // End of if(sqlNumRows($ResultSearch)>0)
                                    ?>
                                    <tr>
                                        <td class="text" colspan="11"><?php echo xlt('No Result Found, for the above search criteria.'); ?></td>
                                    </tr>
                                    <?php
                                }// End of else
                                ?>
                            </table>
                        </div><!--End of table-responsive div-->
                        <?php
                    }// End of if ($_POST["mode"] == "SearchPayment")
                    ?>
                    <div class="row">
                        <input id='mode' name='mode' type='hidden' value='' />
                        <input id='ajax_mode' name='ajax_mode' type='hidden' value='' />
                        <input id="hidden_type_code" name="hidden_type_code" type="hidden" value="<?php echo attr($_POST['hidden_type_code'] ?? ''); ?>" />
                        <input id='SortFieldOld' name='SortFieldOld' type='hidden' value='<?php echo attr($PaymentSortBy ?? ''); ?>' />
                        <input id='Sort' name='Sort' type='hidden' value='<?php echo attr($Sort ?? ''); ?>' />
                        <input id="after_value" name="after_value" type="hidden" value="<?php echo attr($Message ?? ''); ?>" />
                        <input id='DeletePaymentId' name='DeletePaymentId' type='hidden' value='' />
                    </div>
                </form>
            </div>
        </div>
    </div><!--end of container div-->
<?php $oemr_ui->oeBelowContainerDiv(); ?>
</body>
</html>
