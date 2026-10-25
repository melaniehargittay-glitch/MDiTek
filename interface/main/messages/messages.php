<?php

/**
 * Message and Reminder Center UI
 *
 * @Package OpenEMR
 * @link http://www.open-emr.org
 * @author OpenEMR Support LLC
 * @author Roberto Vasquez <robertogagliotta@gmail.com>
 * @author Rod Roark <rod@sunsetsystems.com>
 * @author Brady Miller <brady.g.miller@gmail.com>
 * @author Ray Magauran <magauran@medfetch.com>
 * @author Tyler Wrenn <tyler@tylerwrenn.com>
 * @copyright Copyright (c) 2010 OpenEMR Support LLC
 * @copyright Copyright (c) 2017 MedEXBank.com
 * @copyright Copyright (c) 2018-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2020 Tyler Wrenn <tyler@tylerwrenn.com>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/pnotes.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/gprelations.inc.php");
require_once "$srcdir/user.inc.php";
require_once("$srcdir/MedEx/API.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

//Gets validation rules from Page Validation list.
$collectthis = collectValidationPageRules("/interface/main/messages/messages.php");
if (empty($collectthis)) {
    $collectthis = "{}";
} else {
    $collectthis = json_sanitize($collectthis[array_keys($collectthis)[0]]["rules"]);
}

$MedEx = new MedExApi\MedEx('MedExBank.com');

if ($GLOBALS['medex_enable'] == '1') {
    if ($_REQUEST['SMS_bot']) {
        $result = $MedEx->login('');
        $MedEx->display->SMS_bot($result);
        exit();
    }
    $logged_in = $MedEx->login();
} else {
    $logged_in = null;
}

$setting_bootstrap_submenu = prevSetting('', 'setting_bootstrap_submenu', 'setting_bootstrap_submenu', ' ');
//use $uspfx as the first variable for page/script specific user settings instead of '' (which is like a global but you have to request it).
$uspfx = substr(__FILE__, strlen($webserver_root)) . '.';
$rcb_selectors = prevSetting($uspfx, 'rcb_selectors', 'rcb_selectors', 'block');
$rcb_facility = prevSetting($uspfx, 'form_facility', 'form_facility', '');
$rcb_provider = prevSetting($uspfx, 'form_provider', 'form_provider', $_SESSION['authUserID']);

if (
    (array_key_exists('setting_bootstrap_submenu', $_POST)) ||
    (array_key_exists('rcb_selectors', $_POST))
) {
    // These are not form elements. We only ever change them via ajax, so exit now.
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php
    //validation library
    $use_validate_js = 1;
    require_once($GLOBALS['srcdir'] . "/validation/validation_script.js.php");
    ?>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="description" content="MedEx Bank" />
    <meta name="author" content="OpenEMR: MedExBank" />
    <?php Header::setupHeader(['datetime-picker', 'opener', 'moment', 'select2']); ?>
    <link rel="stylesheet" href="<?php echo $webroot; ?>/interface/main/messages/css/reminder_style.css?v=<?php echo $v_js_includes; ?>">

    <script>
        var xljs1 = '<?php echo xla('Preferences updated successfully'); ?>';
        var format_date_moment_js = '<?php echo attr(DateFormatRead("validateJS")); ?>';
        <?php require_once "$srcdir/restoreSession.php"; ?>
    </script>

    <script src="<?php echo $GLOBALS['web_root']; ?>/interface/main/messages/js/reminder_appts.js?v=<?php echo $v_js_includes; ?>"></script>
    <style>
        /* ── Custom theme variables ─────────────────────────── */
        :root {
            --mc-navy:         #1a1a2e;
            --mc-navy-mid:     #16213e;
            --mc-navy-light:   #0f3460;
            --mc-purple:       #7c3aed;
            --mc-purple-mid:   #6d28d9;
            --mc-purple-light: #8b5cf6;
            --mc-purple-pale:  #ede9fe;
            --mc-purple-ghost: #f5f3ff;
            --mc-accent:       #a78bfa;
            --mc-gray-50:      #f9fafb;
            --mc-gray-100:     #f3f4f6;
            --mc-gray-200:     #e5e7eb;
            --mc-gray-300:     #d1d5db;
            --mc-gray-400:     #9ca3af;
            --mc-gray-500:     #6b7280;
            --mc-gray-700:     #374151;
            --mc-shadow-sm:    0 1px 3px rgba(0,0,0,.08);
            --mc-shadow:       0 4px 12px rgba(0,0,0,.1);
            --mc-radius:       8px;
            --mc-radius-sm:    4px;
        }

        .btn-add::before{
            display:none !important;
        }

        .btn-delete::before{
            display:none !important;
        }

        /* ── Body / container ───────────────────────────────── */
        body.body_top {
            background: var(--mc-gray-100) !important;
        }
        #container_div {
            background: transparent !important;
        }

        /* ── Page heading ───────────────────────────────────── */
        .page-title,
        h2.page-title,
        .oe-page-heading h2 {
            color: var(--mc-navy) !important;
            font-weight: 700 !important;
            letter-spacing: -.01em;
        }

        /* ── Nav pills (tab bar) ─────────────────────────────── */
        #main-nav-pills {
            background: var(--mc-navy) !important;
            border-radius: var(--mc-radius) var(--mc-radius) 0 0 !important;
            padding: 8px 10px 0 10px !important;
            gap: 2px !important;
            border-bottom: none !important;
        }
        #main-nav-pills .nav-item .nav-link {
            color: rgba(255,255,255,.65) !important;
            background: transparent !important;
            border-radius: var(--mc-radius-sm) var(--mc-radius-sm) 0 0 !important;
            font-size: 13px !important;
            font-weight: 600 !important;
            padding: 9px 16px !important;
            border: none !important;
            transition: background .15s, color .15s !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 7px !important;
        }
        #main-nav-pills .nav-item .nav-link:hover {
            background: rgba(255,255,255,.1) !important;
            color: #fff !important;
        }
        #main-nav-pills .nav-item .nav-link.active {
            background: var(--mc-purple) !important;
            color: #fff !important;
        }

        /* ── Tab content area ────────────────────────────────── */
        .tab-content#content {
            background: #fff !important;
            border-radius: 0 0 var(--mc-radius) var(--mc-radius) !important;
            border: 1px solid var(--mc-gray-200) !important;
            border-top: none !important;
            box-shadow: var(--mc-shadow) !important;
        }
        .tab-content#content > .tab-pane {
            padding: 20px !important;
        }

        /* ── Section headings inside tabs ────────────────────── */
        .tab-content h4 {
            font-size: 15px !important;
            font-weight: 700 !important;
            color: var(--mc-navy) !important;
            margin-bottom: 14px !important;
        }

        /* ── Messages table ──────────────────────────────────── */
        .table.table-sm.table-hover {
            border-collapse: collapse !important;
        }
        .table.table-sm.table-hover thead.table-primary th {
            background: var(--mc-navy) !important;
            color: #fff !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: .06em !important;
            padding: 11px 14px !important;
            border: none !important;
        }
        .table.table-sm.table-hover tbody tr {
            border-bottom: 1px solid var(--mc-gray-100) !important;
            transition: background .12s !important;
        }
        .table.table-sm.table-hover tbody tr:hover {
            background: var(--mc-purple-ghost) !important;
        }
        .table.table-sm.table-hover tbody td {
            padding: 10px 14px !important;
            font-size: 13px !important;
            color: var(--mc-gray-700) !important;
            vertical-align: middle !important;
            border-color: var(--mc-gray-100) !important;
        }
        .table.table-sm.table-hover tbody td a {
            color: var(--mc-purple) !important;
            font-weight: 600 !important;
            text-decoration: none !important;
        }
        .table.table-sm.table-hover tbody td a:hover {
            text-decoration: underline !important;
        }
        /* sort arrow links */
        a.arrowhead { color: rgba(255,255,255,.6) !important; }
        a.arrowhead:hover { color: #fff !important; }

        /* ── Jumbotron (compose area) ────────────────────────── */
        .jumbotron.jumbotron-fluid {
            background: var(--mc-purple-ghost) !important;
            border: 1px solid var(--mc-purple-pale) !important;
            border-radius: var(--mc-radius) !important;
            padding: 16px 20px !important;
        }
        .jumbotron h4 {
            font-size: 14px !important;
            font-weight: 700 !important;
            color: var(--mc-navy) !important;
            margin-bottom: 14px !important;
        }

        /* ── Form controls ───────────────────────────────────── */
        .form-control {
            border: 1.5px solid var(--mc-gray-200) !important;
            border-radius: var(--mc-radius-sm) !important;
            font-size: 13px !important;
            padding: 7px 11px !important;
            transition: border-color .15s, box-shadow .15s !important;
        }
        .form-control:focus {
            border-color: var(--mc-purple-light) !important;
            box-shadow: 0 0 0 3px rgba(124,58,237,.12) !important;
            outline: none !important;
        }
        label {
            font-size: 11px !important;
            font-weight: 700 !important;
            color: var(--mc-gray-500) !important;
            text-transform: uppercase !important;
            letter-spacing: .04em !important;
        }

        /* ── Buttons ─────────────────────────────────────────── */
        .btn {
            font-size: 13px !important;
            font-weight: 600 !important;
            border-radius: var(--mc-radius-sm) !important;
            padding: 7px 16px !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            transition: all .15s !important;
            border: none !important;
        }
        .btn-primary,
        .btn-send-msg {
            background: var(--mc-purple) !important;
            color: #fff !important;
            border: none !important;
        }
        .btn-primary:hover,
        .btn-send-msg:hover {
            background: var(--mc-purple-mid) !important;
            color: #fff !important;
        }
        .btn-secondary,
        .btn-cancel,
        .btn-undo,
        .btn-transmit,
        .btn-refresh,
        .btn-mail {
            background: #fff !important;
            color: var(--mc-gray-700) !important;
            border: 1.5px solid var(--mc-gray-300) !important;
        }
        .btn-secondary:hover,
        .btn-cancel:hover,
        .btn-undo:hover,
        .btn-transmit:hover,
        .btn-refresh:hover,
        .btn-mail:hover {
            background: var(--mc-gray-50) !important;
            border-color: var(--mc-gray-400) !important;
            color: var(--mc-gray-700) !important;
        }
        .btn-danger,
        .btn-delete {
            background: #dc2626 !important;
            color: #fff !important;
            border: none !important;
        }
        .btn-danger:hover,
        .btn-delete:hover {
            background: #b91c1c !important;
            color: #fff !important;
        }
        .btn-add {
            background: var(--mc-purple) !important;
            color: #fff !important;
            border: none !important;
        }
        .btn-add:hover {
            background: var(--mc-purple-mid) !important;
            color: #fff !important;
        }
        .btn-print {
            background: #fff !important;
            color: var(--mc-gray-700) !important;
            border: 1.5px solid var(--mc-gray-300) !important;
        }

        /* ── Pagination / chevron links ──────────────────────── */
        .chevron_color { color: var(--mc-purple) !important; font-size: 18px !important; }

        /* ── Activity nav links (Show All / Active / Inactive) ── */
        .d-flex .nav .nav-link {
            font-size: 12px !important;
            font-weight: 600 !important;
            color: var(--mc-purple) !important;
            padding: 5px 12px !important;
            border-radius: 99px !important;
            border: 1.5px solid var(--mc-purple-pale) !important;
            transition: all .15s !important;
        }
        .d-flex .nav .nav-link:hover {
            background: var(--mc-purple-pale) !important;
        }
        .d-flex .nav .nav-link.disabled,
        .d-flex .nav .nav-link.active {
            background: var(--mc-purple) !important;
            color: #fff !important;
            border-color: var(--mc-purple) !important;
        }

        /* ── Message count / pagination row ──────────────────── */
        .text-right a i { color: var(--mc-purple) !important; }

        /* ── Checkbox accent ─────────────────────────────────── */
        input[type="checkbox"] { accent-color: var(--mc-purple); }

        /* ── SVG icon helper ─────────────────────────────────── */
        .mc-icon {
            display: inline-block;
            vertical-align: middle;
            flex-shrink: 0;
        }

        /* ── Help / expand icons ─────────────────────────────── */
        .oe-help-redirect .mc-icon,
        .oe-expandcontainer .mc-icon {
            width: 18px; height: 18px;
        }

        /* ── Row margin helpers ───────────────────────────────── */
        .oe-margin-t-3  { margin-top: 6px  !important; }
        .oe-margin-t-10 { margin-top: 14px !important; }

        /* ── Responsive ──────────────────────────────────────── */
        @media only screen and (max-width: 768px) {
            [class*="col-"] {
                width: 100%;
                text-align: left !important;
            }
            .icon-bar {
                background-color: var(--danger);
            }
            #main-nav-pills {
                flex-wrap: wrap !important;
                border-radius: var(--mc-radius) !important;
            }
        }
    </style>

<?php
if (($GLOBALS['medex_enable'] == '1') && (empty($_REQUEST['nomenu'])) && ($GLOBALS['disable_rcb'] != '1')) {
    $MedEx->display->navigation($logged_in);
    echo "<br /><br /><br />";
}

if (!empty($_REQUEST['go'])) { ?>
    <?php
    if (($_REQUEST['go'] == "setup") && (!$logged_in)) {
        echo "<title>" . xlt('MedEx Setup') . "</title>";
        $stage = $_REQUEST['stage'];
        if (!is_numeric($stage)) {
            echo "<br /><span class='title'>" . text($stage) . " " . xlt('Warning') . ": " . xlt('This is not a valid request') . ".</span>";
        } else {
            $MedEx->setup->MedExBank($stage);
        }
    } elseif ($_REQUEST['go'] == "addRecall") {
        echo "<title>" . xlt('New Recall') . "</title>";
        $MedEx->display->display_add_recall();
    } elseif ($_REQUEST['go'] == 'Recalls') {
        echo "<title>" . xlt('Recall Board') . "</title>";
        $MedEx->display->display_recalls($logged_in);
    } elseif ((($_REQUEST['go'] == "setup") || ($_REQUEST['go'] == 'Preferences')) && ($logged_in)) {
        echo "<title>MedEx: " . xlt('Preferences') . "</title>";
        $MedEx->display->preferences();
    } elseif ($_REQUEST['go'] == 'icons') {
        echo "<title>MedEx: " . xlt('Icons') . "&#x24B8;</title>";
        $MedEx->display->icon_template();
    } elseif ($_REQUEST['go'] == 'SMS_bot') {
        echo "<title>MedEx: SMS Bot&#x24B8;</title>";
        $MedEx->display->SMS_bot($logged_in);
        exit;
    } else {
        echo "<title>" . xlt('MedEx Setup') . "</title>";
        echo xlt('Warning: Navigation error. Please refresh this page.');
    }
} else {
    //original message.php stuff

    if ($GLOBALS['enable_help'] == 1) {
        $help_icon = '<a class="float-right oe-help-redirect" data-target="#myModal" data-toggle="modal" href="#" id="help-href" name="help-href" style="color: var(--gray)" title="' . xla("Click to view Help") . '"><svg class="mc-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></a>';
    } elseif ($GLOBALS['enable_help'] == 2) {
        $help_icon = '<a class="float-right oe-help-redirect" data-target="#myModal" data-toggle="modal" href="#" id="help-href" name="help-href" style="color: var(--gray300) !important" title="' . xla("To enable help - Go to  Administration > Globals > Features > Enable Help Modal") . '"><svg class="mc-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></a>';
    } elseif ($GLOBALS['enable_help'] == 0) {
         $help_icon = '';
    }
    $heading_caption = xlt('Messages') . ', ' . xlt('Reminders');
    if ($GLOBALS['disable_rcb'] != '1') {
        $heading_caption .= ', ' . xlt('Recalls');
    }

    $arrOeUiSettings = array(
        'heading_title' => $heading_caption,
        'include_patient_name' => false,// use only in appropriate pages
        'expandable' => true,
        'expandable_files' => array(""),//all file names need suffix _xpd
        'action' => "",//conceal, reveal, search, reset, link or back
        'action_title' => "",
        'action_href' => "",//only for actions - reset, link or back
        'show_help_icon' => true,
        'help_file_name' => "message_center_help.php"
    );
    $oemr_ui = new OemrUI($arrOeUiSettings);

    echo "<title>" .  xlt('Message Center') . "</title>";
    ?>
</head>
<body class='body_top'>
    <div id="container_div" class="<?php echo attr($oemr_ui->oeContainer()); ?>">
        <div class="row">
            <div class="col-sm-12">
                <div class="clearfix">
                    <?php echo  $oemr_ui->pageHeading() . "\r\n"; ?>
                </div>
            </div>
        </div>
        <div class="row my-3">
            <div class="col-sm-12">
            <ul class="nav nav-pills bg-light" id="main-nav-pills" role="tablist">
            <li class="nav-item" id='li-mess' role="presentation">
                <a href='#messages-div' class="active nav-link" id='messages-li' data-toggle="pill" role="tab" aria-controls="<?php echo xla("Message");?>" aria-selected="true"><svg class="mc-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><?php echo xlt('Messages'); ?></a>
            </li>
            <li class="nav-item" id='li-remi' role="presentation">
                <a href='#reminders-div' id='reminders-li' class="nav-link" data-toggle="pill" role="tab" aria-controls="<?php echo xla("Reminders");?>" aria-selected="true"><svg class="mc-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg><?php echo xlt('Reminders'); ?></a>
            </li>
            <?php if ($GLOBALS['disable_rcb'] != '1') { ?>
            <li class="nav-item" id='li-reca' role="presentation">
                <a href='#recalls-div' id='recalls-li' class="nav-link" data-toggle="pill" role="tab" aria-controls="<?php echo xla("Recalls");?>" aria-selected="true"><svg class="mc-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg><?php echo xlt('Recalls'); ?></a>
            </li>
            <?php } ?>
            <li class="nav-item" id='li-batch' role="presentation">
                <a href='/interface/batchcom/batchcom.php' id='batch-communication-tool-li' class="nav-link" role="tab" aria-controls="<?php echo xla('Batch Communication Tool'); ?>" aria-selected="false"><svg class="mc-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg><?php echo xlt('Batch Communication Tool'); ?></a>
            </li>
            <?php if ($logged_in) { ?>
            <li class="nav-item" id='li-sms' role="presentation">
                <a href='#sms-div' id='sms-li' class="nav-link" data-toggle="pill"  role="tab" aria-controls="<?php echo xla("SMS Zone");?>" aria-selected="true"><svg class="mc-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg><?php echo xlt('SMS Zone'); ?></a>
            </li>
            <?php } ?>
        </ul>
            </div>
        </div>
        <div class="tab-content" id="content">
            <div class="row tab-pane active" role="tabpanel" id="messages-div">
                <div class="col-sm-12">
                    <?php
                    // Check to see if the user has Admin rights, and if so, allow access to See All.
                    $showall = isset($_GET['show_all']) ? $_GET['show_all'] : "";
                    if ($showall == "yes") {
                        $show_all = $showall;
                    } else {
                        $show_all = "no";
                    }
                    // Collect active variable and applicable html code for links
                    $form_active = (isset($_REQUEST['form_active']) ? $_REQUEST['form_active'] : false);
                    $form_inactive = (isset($_REQUEST['form_inactive']) ? $_REQUEST['form_inactive'] : false);
                    if ($form_active) {
                        $active = '1';
                        $activity_string_html = 'form_active=1';
                    } elseif ($form_inactive) {
                        $active = '0';
                        $activity_string_html = 'form_inactive=1';
                    } else {
                        $active = 'all';
                        $activity_string_html = '';
                    }
                    //collect the task setting
                    $task = isset($_REQUEST['task']) ? $_REQUEST['task'] : "";
                    if (AclMain::aclCheckCore('admin', 'super')) {
                        if ($show_all == 'yes') {
                            $showall = "yes";
                            $lnkvar = "messages.php?show_all=no&" . $activity_string_html;
                            $lnkattributes = "name='Just Mine' onclick='top.restoreSession()'";
                            $otherstuff = "<svg id='just-mine-tooltip' class='mc-icon text-body' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/><circle cx='12' cy='7' r='4'/></svg>";
                            $messages = xl('All Messages');
                        } else {
                            $showall = "no";
                            $lnkvar = "messages.php?show_all=yes&" . $activity_string_html;
                            $lnkattributes = "name='See All' onclick='top.restoreSession()'";
                            $otherstuff = "<svg id='see-all-tooltip' class='mc-icon text-body' width='20' height='20' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round' aria-hidden='true'><path d='M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2'/><circle cx='9' cy='7' r='4'/><path d='M23 21v-2a4 4 0 0 0-3-3.87'/><path d='M16 3.13a4 4 0 0 1 0 7.75'/></svg>";
                            $messages = xl('My Messages');
                        }
                    } else {
                        $messages = xlt('My Messages');
                    }
                    ?>
                    <div class="d-flex justify-content-around">
                        <h4 class="flex-grow-1">
                            <?php echo text($messages); ?>
                            <a class='more' href="<?php echo $lnkvar ?? ''; ?>" <?php echo $lnkattributes ?? ''; ?>><?php echo $otherstuff ?? ''; ?></a>
                        </h4>
                        <ul class="nav text-right">
                        <?php
                        //show the activity links
                        if (empty($task) || $task == "add" || $task == "delete") { ?>
                            <li class="nav-item">
                                <?php if ($active == "all") : ?>
                                    <span class="nav-link disabled"><?php echo xlt('All Messages'); ?></span>
                                <?php else : ?>
                                    <a href="messages.php?show_all=yes" class="nav-link active" onclick="top.restoreSession()"><?php echo xlt('Show All'); ?></a>
                                <?php endif; ?>
                            </li>
                            <li class="nav-item">
                                <?php if ($active == '1') { ?>
                                    <span class="nav-link disabled"><?php echo xlt('Active Messages'); ?></span>
                                <?php } else { ?>
                                    <a href="messages.php?form_active=1" class="nav-link" onclick="top.restoreSession()"><?php echo xlt('Show Active'); ?></a>
                                <?php } ?>
                            </li>
                            <li class="nav-item">
                                <?php if ($active == '0') { ?>
                                    <span class="nav-link disabled"><?php echo xlt('Inactive Messages'); ?></span>
                                <?php } else { ?>
                                    <a href="messages.php?form_inactive=1" class="nav-link" onclick="top.restoreSession()"><?php echo xlt('Show Inactive'); ?></a>
                                <?php } ?>
                            </li>
                        <?php } ?>
                        </ul>
                    </div>
                    <div>
                    </div>
                    <?php
                    $note = '';
                    $noteid = '';
                    $title = '';
                    $form_message_status = '';
                    $reply_to = '';
                    $patientname = '';
                    switch ($task) {
                        case "add":
                            // Add a new message for a specific patient; the message is documented in Patient Notes.
                            // Add a new message; it's treated as a new note in Patient Notes.
                            $note = $_POST['note'];
                            $noteid = $_POST['noteid'];
                            $form_note_type = $_POST['form_note_type'];
                            $form_message_status = $_POST['form_message_status'];
                            $reply_to = explode(';', rtrim($_POST['reply_to'], ';'));
                            $assigned_to_list = explode(';', $_POST['assigned_to']);
                            $datetime = isset($_POST['form_datetime']) ? DateTimeToYYYYMMDDHHMMSS($_POST['form_datetime']) : '';
                            foreach ($assigned_to_list as $assigned_to) {
                                if ($noteid && $assigned_to != '-patient-') {
                                    updatePnote($noteid, $note, $form_note_type, $assigned_to, $form_message_status, $datetime);
                                    $noteid = '';
                                } else {
                                    if ($noteid && $assigned_to == '-patient-') {
                                        // When $assigned_to == '-patient-' we don't update the current note, but
                                        // instead create a new one with the current note's body prepended and
                                        // attributed to the patient.  This seems to be all for the patient portal.
                                        $row = getPnoteById($noteid);
                                        if (!$row) {
                                            die("getPnoteById() did not find id '" . text($noteid) . "'");
                                        }
                                        $pres = sqlQuery("SELECT lname, fname " .
                                            "FROM patient_data WHERE pid = ?", array($reply_to[0]));
                                        $patientname = $pres['lname'] . ", " . $pres['fname'];
                                        $note .= "\n\n$patientname on " . $row['date'] . " wrote:\n\n";
                                        $note .= $row['body'];
                                    }
                                    // There's no note ID, and/or it's assigned to the patient.
                                    // In these cases a new note is created.
                                    foreach ($reply_to as $patient) {
                                        $note_id = addPnote($patient, $note, $userauthorized, '1', $form_note_type, $assigned_to, $datetime, $form_message_status);
                                        if (!empty($_POST['attachment_id'] ?? null) && !empty($_POST['attachment_type'] ?? null)) {
                                            setGpRelation($_POST['attachment_type'], $_POST['attachment_id'], 6, $note_id);
                                            echo "<script>dlgclose();</script>";
                                        }
                                    }
                                }
                            }
                            break;
                        case "savePatient":
                        case "save":
                            // Update alert.
                            $noteid = $_POST['noteid'];
                            $form_message_status = $_POST['form_message_status'];
                            $reply_to = $_POST['reply_to'];
                            if ($task == "save") {
                                updatePnoteMessageStatus($noteid, $form_message_status);
                            } else {
                                updatePnotePatient($noteid, $reply_to);
                            }
                            $task = "edit";
                            $note = $_POST['note'];
                            $title = $_POST['form_note_type'];
                            $reply_to = $_POST['reply_to'];
                            break;
                        case "edit":
                            if ($noteid == "") {
                                $noteid = $_GET['noteid'];
                            }
                            // Update the message if it already exists; it's appended to an existing note in Patient Notes.
                            $result = getPnoteById($noteid);
                            if ($result) {
                                if ($title == "") {
                                    $title = $result['title'];
                                }
                                $body = $result['body'];
                                // if our reply-to is 0 it breaks multi patient select and other functionality
                                // this most likely didn't break before due to php implicit type conversion of 0 to ""
                                if ($reply_to == "" && $result['pid'] != 0) {
                                    $reply_to = $result['pid'];
                                }
                                $form_message_status = $result['message_status'];
                                $datetime = $result['date'];
                            }
                            break;
                        case "delete":
                            // Delete selected message(s) from the Messages box (only).
                            $delete_id = $_POST['delete_id'];
                            for ($i = 0; $i < count($delete_id); $i++) {
                                deletePnote($delete_id[$i]);
                                EventAuditLogger::instance()->newEvent("delete", $_SESSION['authUser'], $_SESSION['authProvider'], 1, "pnotes: id " . $delete_id[$i]);
                            }
                            break;
                    }
                    // This is for sorting the records.
                    $sort = array("users.lname", "patient_data.lname", "pnotes.title", "pnotes.date", "pnotes.message_status");
                    $sortby = (isset($_REQUEST['sortby']) && ($_REQUEST['sortby'] != "")) ? $_REQUEST['sortby'] : $sort[3];
                    $sortorder = (isset($_REQUEST['sortorder']) && ($_REQUEST['sortorder'] != "")) ? $_REQUEST['sortorder'] : "desc";
                    $begin = isset($_REQUEST['begin']) ? $_REQUEST['begin'] : 0;

                    if ($task == "addnew" or $task == "edit") {
                        // Display the Messages page layout.
                        echo "<form name='form_patient' id='new_note'
                                class='form-horizontal'
                                action=\"messages.php?showall=" . attr_url($showall) . "&sortby=" . attr_url($sortby) . "&sortorder=" . attr_url($sortorder) . "&begin=" . attr_url($begin) . "&$activity_string_html\"
                                method='post'>
                                <input type='hidden' name='noteid' id='noteid' value='" . attr($noteid) . "' />
                                
                                <input type='hidden' name='task' id='task' value='add' />";
                        if ($task == "addnew") {
                            $attach_id = $_REQUEST['attach'] ?? null;
                            $attach_type = $_REQUEST['gptype'] ?? null;
                            if (!empty($attach_id) && !empty($attach_type)) {
                                echo "<input type='hidden' name='attachment_id' id='attachment_id' value='" . attr($attach_id) . "' />";
                                echo "<input type='hidden' name='attachment_type' id='attachment_type' value='" . attr($attach_type) . "' />";
                            }
                            $message_legend = xl('Create New Message');
                            $onclick = "onclick=multi_sel_patient()";
                        } elseif ($task == "edit") {
                            $message_legend = xl('Add To Existing Message');
                            $onclick = "";
                        }
                        ?>
                        <div class='col-md-12'>
                            <div class="jumbotron jumbotron-fluid p-2">
                                <h4><?php echo text($message_legend); ?></h4>
                                <div class="row">
                                    <div class="col-12 oe-custom-line">
                                        <div class="row">
                                            <div class="col-6 col-md-3">
                                                <label for="form_note_type"><?php echo xlt('Type'); ?>:</label>
                                                <?php
                                                if ($title == "") {
                                                    $title = "Unassigned";
                                                }
                                                // Added 6/2009 by BM to incorporate the patient notes into the list_options listings.
                                                generate_form_field(array('data_type' => 1, 'field_id' => 'note_type', 'list_id' => 'note_type', 'empty_title' => 'SKIP', 'order_by' => 'title', 'class' => 'form-control'), $title);
                                                ?>
                                            </div>
                                            <div class="col-6 col-md-3">
                                                <label for="form_message_status"><?php echo xlt('Status'); ?>:</label>
                                                <?php
                                                if ($form_message_status == "") {
                                                    $form_message_status = 'New';
                                                }
                                                generate_form_field(array('data_type' => 1, 'field_id' => 'message_status', 'list_id' => 'message_status', 'empty_title' => 'SKIP', 'order_by' => 'title', 'class' => 'form-control'), $form_message_status); ?>
                                            </div>
                                            <div class="col-6 col-md-4">
                                                <?php
                                                if ($task != "addnew" && $result['pid'] != 0) { ?>
                                                    <a class="patLink" onclick="goPid('<?php echo attr(addslashes($result['pid'])); ?>')" title='<?php echo xla('Click me to Open Patient Dashboard') ?>'><?php echo xlt('Patient'); ?>:</a><label for="form_patient">&nbsp</label>
                                                    <?php
                                                } else { ?>
                                                    <span class='<?php echo($task == "addnew" ? "text-danger" : "") ?>'><?php echo xlt('Patient'); ?>:</span></a><label for="form_patient"></label>
                                                    <?php
                                                }

                                                if ($reply_to) {
                                                    $prow = sqlQuery("SELECT lname, fname,pid, pubpid, DOB  " .
                                                        "FROM patient_data WHERE pid = ?", array($reply_to));
                                                    $patientname = $prow['lname'] . ", " . $prow['fname'];
                                                }
                                                if ($task == "addnew" || $result['pid'] == 0) {
                                                    $cursor = "oe-cursor-add";
                                                    $background = "oe-patient-background";
                                                } elseif ($task == "edit") {
                                                    $cursor = "oe-cursor-stop";
                                                    $background = '';
                                                }
                                                ?>
                                                <input type='text' id='form_patient' name='form_patient' class='form-control <?php echo $cursor . " " . $background;?>' onclick="multi_sel_patient()" placeholder='<?php echo xla("Click to add patient"); ?>' value='<?php echo attr($patientname); ?>' readonly />
                                                <input type='hidden' class="form-control" name='reply_to' id='reply_to' value='<?php echo attr($reply_to); ?>'/>
                                            </div>
                                            <div class="col-6 col-md-2 d-flex flex-wrap">
                                                <?php
                                                if ($task == "addnew" || $result['pid'] == 0) {
                                                    echo "<label class='oe-empty-label' for='clear_patients'></label>";
                                                    echo '<button type="button" id="clear_patients"  class="btn btn-secondary btn-undo float-left flip" value="' . xla('Clear') . '"><svg class="mc-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' . xlt("Clear") . '</button>';
                                                } ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-12 oe-custom-line">
                                        <div class="row">
                                            <?php if ($GLOBALS['messages_due_date']) { ?>
                                            <div class="col-6 col-sm-2">
                                                <label for="form_note_type"><?php echo xlt('Due date'); ?>:</label>
                                                <?php generate_form_field(array('data_type' => 4, 'field_id' => 'datetime', 'edit_options' => 'F'), empty($datetime) ? date('Y-m-d H:i') : $datetime) ?>
                                            </div>
                                            <?php } ?>
                                            <div class="col-6 col-sm-4 d-flex align-items-end flex-wrap">
                                                <label for="assigned_to_text"><?php echo xlt('To{{Destination}}'); ?>:</label>
                                                <input type='text' name='assigned_to_text' class='form-control oe-cursor-stop' id='assigned_to_text' readonly='readonly' value='' placeholder='<?php echo xla("SELECT Users FROM The Dropdown LIST"); ?>' />
                                                <input type='hidden' name='assigned_to' id='assigned_to' />
                                            </div>
                                            <div class="col-6 col-sm-4">
                                                <label class="oe-empty-label" for="users"></label>
                                                <select name='users' id='users' class='form-control' onchange='addtolist(this);'>
                                                    <?php
                                                    echo "<option value='--'";
                                                    echo ">" . xlt('Select User');
                                                    echo "</option>\n";
                                                    $ures = sqlStatement("SELECT username, fname, lname FROM users " .
                                                        "WHERE username != '' AND active = 1 AND " .
                                                        "( info IS NULL OR info NOT LIKE '%Inactive%' ) " .
                                                        "ORDER BY lname, fname");
                                                    while ($urow = sqlFetchArray($ures)) {
                                                        echo "    <option value='" . attr($urow['username']) . "'";
                                                        echo ">" . text($urow['lname']);
                                                        if ($urow['fname']) {
                                                            echo ", " . text($urow['fname']);
                                                        }
                                                        echo "</option>\n";
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            <div class="col-6 col-sm-2  d-flex align-items-end flex-wrap">
                                                <label class="oe-empty-label" for="users"></label>
                                                <button type="button" name="clear_user" id="clear_user" class="btn btn-secondary btn-undo float-left flip" value="<?php echo xla('Clear'); ?>"><svg class="mc-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><?php echo xlt('Clear'); ?></button>
                                            </div>
                                        </div>
                                    <div class='col-12 oe-margin-t-3'>
                                    <?php
                                    if ($noteid) {
                                        include "templates/linked_documents.php";

                                        // Get the related procedure order IDs if any.
                                        $tmp = sqlStatement(
                                            "SELECT id1 FROM gprelations WHERE " .
                                            "type1 = ? AND type2 = ? AND id2 = ?",
                                            array('2', '6', $noteid)
                                        );
                                        if (sqlNumRows($tmp)) {
                                            echo " <tr>\n";
                                            echo "  <td class='text'><span class='font-weight-bold'>" . xlt('Linked procedure order') . ":</span>\n";
                                            while ($gprow = sqlFetchArray($tmp)) {
                                                echo "   <a href='";
                                                echo $GLOBALS['webroot'] . "/interface/orders/single_order_results.php?orderid=";
                                                echo attr_url($gprow['id1']);
                                                echo "' target='_blank' onclick='top.restoreSession()'>";
                                                echo text($gprow['id1']);
                                                echo "</a>\n";
                                            }
                                            echo "  </td>\n";
                                            echo " </tr>\n";
                                        }
                                    }
                                    ?>
                                </div>
                                </div>
                                <!-- <div class="row"> -->
                                    <div class='col-12'>
                                        <?php
                                        if ($noteid) {
                                            $body = preg_replace('/(:\d{2}\s\()' . $result['pid'] . '(\sto\s)/', '${1}' . $patientname . '${2}', $body);
                                            $body = preg_replace('/(\d{4}-\d{2}-\d{2} \d{2}:\d{2}\s\([^)(]+\s)(to)(\s[^)(]+\))/', '${1}' . xl('to{{Destination}}') . '${3}', $body);
                                            $body = pnoteConvertLinks(nl2br(text(oeFormatPatientNote($body))));
                                            echo "<div style='height: 120px; resize: vertical;' class='border overflow-auto text text-light bg-dark oe-margin-t-3 p-2 mb-2 w-100'>" . $body . "</div>";
                                        }
                                        ?>
                                        <textarea name='note' id='note' class='form-control oe-margin-t-3 p-1 text-dark bg-light' rows="5"><?php echo nl2br(text($note)); ?></textarea>
                                    </div>
                                    <div class="col-12 position-override oe-margin-t-10">
                                        <?php if ($noteid) { ?>
                                            <!-- This is for displaying an existing note. -->
                                            <button type="button" class="btn btn-primary btn-send-msg" id="newnote" value="<?php echo xla('Send message'); ?>"><svg class="mc-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg><?php echo xlt('Send message'); ?></button>
                                            <button type="button" class="btn btn-primary btn-print" id="printnote" value="<?php echo xla('Print message'); ?>"><svg class="mc-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg><?php echo xlt('Print message'); ?></button>
                                            <button type="button" class="btn btn-secondary btn-cancel" id="cancel" value="<?php echo xla('Cancel'); ?>"><svg class="mc-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><?php echo xlt('Cancel'); ?></button>
                                        <?php } else { ?>
                                            <!-- This is for displaying a new note. -->
                                            <button type="button" class="btn btn-primary btn-send-msg" id="newnote" value="<?php echo xla('Send message'); ?>"><svg class="mc-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg><?php echo xlt('Send message'); ?></button>
                                            <button type="button" class="btn btn-cancel btn-secondary" id="cancel" value="<?php echo xla('Cancel'); ?>"><svg class="mc-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg><?php echo xlt('Cancel'); ?></button>
                                        <?php }
                                        ?>
                                    </div>
                                <!-- </div> -->
                            </div>
                            </div>
                            </div>
                        </form>
                        <?php
                    } else {
                        for ($i = 0; $i < count($sort); $i++) {
                            $sortlink[$i] = "<a  class='arrowhead' href=\"messages.php?show_all=" . attr($showall) . "&sortby=" . attr($sort[$i]) . "&sortorder=asc&$activity_string_html\" onclick=\"top.restoreSession()\" alt=\"" . xla('Sort Up') . "\"><svg class='mc-icon' width='12' height='12' viewBox='0 0 24 24' fill='currentColor' aria-hidden='true'><path d='M12 19l-7-7h14z'/></svg></a>";
                        }
                        for ($i = 0; $i < count($sort); $i++) {
                            if ($sortby == $sort[$i]) {
                                switch ($sortorder) {
                                    case "asc":
                                        $sortlink[$i] = "<a class='arrowhead' href=\"messages.php?show_all=" . attr($showall) . "&sortby=" . attr($sortby) . "&sortorder=desc&$activity_string_html\" onclick=\"top.restoreSession()\" alt=\"" . xla('Sort Up') . "\"><svg class='mc-icon' width='12' height='12' viewBox='0 0 24 24' fill='currentColor' aria-hidden='true'><path d='M12 5l7 7H5z'/></svg></a>";
                                        break;
                                    case "desc":
                                        $sortlink[$i] = "<a class='arrowhead' href=\"messages.php?show_all=" . attr($showall) . "&sortby=" . attr($sortby) . "&sortorder=asc&$activity_string_html\" onclick=\"top.restoreSession()\"  alt=\"" . xla('Sort Down') . "\"><svg class='mc-icon' width='12' height='12' viewBox='0 0 24 24' fill='currentColor' aria-hidden='true'><path d='M12 19l-7-7h14z'/></svg></a>";
                                        break;
                                } break;
                            }
                        }
                        // Manage page numbering and display beneath the Messages table.
                        $listnumber = 25;
                        $total = getPnotesByUser($active, $show_all, $_SESSION['authUser'], true);
                        if ($begin == "" or $begin == 0) {
                            $begin = 0;
                        }
                        $prev = $begin - $listnumber;
                        $next = $begin + $listnumber;
                        $start = $begin + 1;
                        $end = $listnumber + $start - 1;

                        if ($end >= $total) {
                            $end = $total;
                        }
                        if ($end < $start) {
                            $start = 0;
                        }

                        $chevron_icon_left  = $_SESSION['language_direction'] == 'ltr' ? 'prev' : 'next';
                        $chevron_icon_right = $_SESSION['language_direction'] == 'ltr' ? 'next' : 'prev';

                        $svg_prev = '<svg class="mc-icon chevron_color" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 8 8 12 12 16"/><line x1="16" y1="12" x2="8" y2="12"/></svg>';
                        $svg_next = '<svg class="mc-icon chevron_color" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 16 16 12 12 8"/><line x1="8" y1="12" x2="16" y2="12"/></svg>';
                        $svg_prev_muted = '<svg class="mc-icon text-muted" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" title="' . xla("On first page") . '"><circle cx="12" cy="12" r="10"/><polyline points="12 8 8 12 12 16"/><line x1="16" y1="12" x2="8" y2="12"/></svg>';
                        $svg_next_muted = '<svg class="mc-icon text-muted" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" title="' . xla("On last page") . '"><circle cx="12" cy="12" r="10"/><polyline points="12 16 16 12 12 8"/><line x1="8" y1="12" x2="16" y2="12"/></svg>';

                        if ($prev >= 0) {
                            $prevlink = "<a href=\"messages.php?show_all=" . attr($showall) . "&sortby=" . attr($sortby) . "&sortorder=" . attr($sortorder) . "&begin=" . attr($prev) . "&$activity_string_html\" onclick=\"top.restoreSession()\">" . ($chevron_icon_left === 'prev' ? $svg_prev : $svg_next) . "</a>";
                        } else {
                            $prevlink = ($chevron_icon_left === 'prev' ? $svg_prev_muted : $svg_next_muted);
                        }

                        if ($next < $total) {
                            $nextlink = "<a href=\"messages.php?show_all=" . attr($showall) . "&sortby=" . attr($sortby) . "&sortorder=" . attr($sortorder) . "&begin=" . attr($next) . "&$activity_string_html\" onclick=\"top.restoreSession()\">" . ($chevron_icon_right === 'next' ? $svg_next : $svg_prev) . "</a>";
                        } else {
                            $nextlink = ($chevron_icon_right === 'next' ? $svg_next_muted : $svg_prev_muted);
                        }
                        // Display the Messages table header.
                        echo "
                            <table class=\"w-100\">
                                <tr>
                                    <td>
                                        <form name='MessageList' id='MessageList' action=\"messages.php?showall=" . attr($showall) . "&sortby=" . attr($sortby) . "&sortorder=" . attr($sortorder) . "&begin=" . attr($begin) . "&$activity_string_html\" method='post'>
                                            <table class='table table-sm table-hover w-100'>
                                                <input type='hidden' name='task' value='delete' />
                                                <thead class='table-primary'>
                                                    <tr height='24'>
                                                        <th align='center' width='25'><input type='checkbox' id='checkAll' onclick='selectAll()'></th>
                                                        <th width='20%' class='font-weight-bold'>&nbsp;" . xlt('From') . " $sortlink[0]</th>
                                                        <th width='20%' class='font-weight-bold'>&nbsp;" . xlt('Patient') . " $sortlink[1]</th>
                                                        <th class='font-weight-bold'>&nbsp;" . xlt('Type') . " $sortlink[2]</th>
                                                        <th width='15%' class='font-weight-bold'>&nbsp;" . xlt($GLOBALS['messages_due_date'] ? 'Due date' : 'Date') . " $sortlink[3]</th>
                                                        <th width='15%' class='font-weight-bold'>&nbsp;" . xlt('Status') . " $sortlink[4]</th>
                                                    </tr>
                                                </thead>";
                        // Display the Messages table body.
                        $count = 0;
                        $result = getPnotesByUser($active, $show_all, $_SESSION['authUser'], false, $sortby, $sortorder, $begin, $listnumber);
                        while ($myrow = sqlFetchArray($result)) {
                            $name = $myrow['user'];
                            $name = $myrow['users_lname'];
                            if ($myrow['users_fname']) {
                                $name .= ", " . $myrow['users_fname'];
                            }
                            $patient = $myrow['pid'];
                            if ($patient > 0) {
                                $patient = $myrow['patient_data_lname'];
                                if ($myrow['patient_data_fname']) {
                                    $patient .= ", " . $myrow['patient_data_fname'];
                                }
                            } else {
                                $patient = "* " . xl('Patient must be set manually') . " *";
                            }
                            $count++;
                            echo "
                                <tr id=\"row" . attr($count) . "\" height='24'>
                                    <td align='center'>
                                        <input type='checkbox' id=\"check" . attr($count) . "\" name=\"delete_id[]\" value=\"" .
                                        attr($myrow['id']) . "\" onclick=\"if(this.checked==true){ selectRow('row" . attr(addslashes($count)) . "'); }else{ deselectRow('row" . attr(addslashes($count)) . "'); }\"></td>
                                    <td>
                                        <div>" . text($name) . "</div>
                                    </td>
                                    <td>
                                        <div><a href=\"messages.php?showall=" . attr_url($showall) . "&sortby=" . attr_url($sortby) . "&sortorder=" . attr_url($sortorder) . "&begin=" . attr_url($begin) . "&task=edit&noteid=" .
                                        attr_url($myrow['id']) . "&$activity_string_html\" onclick=\"top.restoreSession()\">" .
                                        text($patient) . "</a></div>
                                    </td>
                                    <td>
                                        <div>" .
                                            xlt($myrow['title']) . "</div>
                                    <td>
                                        <div>" . text(oeFormatDateTime($myrow['date'])) . "</div>
                                    </td>
                                    <td>
                                        <div>" . text(getListItemTitle('message_status', $myrow['message_status'])) . "</div>
                                    </td>
                                </tr>";
                        }
                        // Display the Messages table footer.

                        echo "  </table>
                                        </form>
                                        <div class='row oe-margin-t-10'>

                                            <div class=\"col-12 col-md-12 col-lg-12\"><a href=\"messages.php?showall=" . attr_url($showall) . "&sortby=" . attr_url($sortby) . "&sortorder=" . attr_url($sortorder) . "&begin=" . attr_url($begin) . "&task=addnew&$activity_string_html\" class=\"btn btn-primary btn-add\" onclick=\"top.restoreSession()\"><svg class='mc-icon' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><line x1='12' y1='5' x2='12' y2='19'/><line x1='5' y1='12' x2='19' y2='12'/></svg>" .
                                            xlt('Add New{{Message}}') . "</a> &nbsp; <a href=\"javascript:confirmDeleteSelected()\" class=\"btn btn-danger btn-delete\" onclick=\"top.restoreSession()\"><svg class='mc-icon' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='3 6 5 6 21 6'/><path d='M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6'/><path d='M10 11v6'/><path d='M14 11v6'/><path d='M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2'/></svg>" .
                                            xlt('Delete') . "</a>";

                        if ($GLOBALS['phimail_enable']) {
                            echo "&nbsp; <a href='trusted-messages.php' onclick='top.restoreSession()' class='btn btn-secondary btn-mail'><svg class='mc-icon' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z'/><polyline points='22,6 12,13 2,6'/></svg>" . xlt("Compose Trusted Direct Message") . "</a>";
                            echo "&nbsp; <button class='btn btn-secondary btn-refresh trusted-messages-force-check'><svg class='mc-icon' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><polyline points='23 4 23 10 17 10'/><polyline points='1 20 1 14 7 14'/><path d='M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15'/></svg>" . xlt("Check New Trusted Messages") . "</button>";
                        }
                        echo "
                                            <div  class=\"text-right\">$prevlink &nbsp; " . text($end) . " " . xlt('of') . " " . text($total) . " &nbsp; $nextlink</div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                            <br />";
                        ?>

                        <script>
                            // This is to confirm delete action.
                            function confirmDeleteSelected() {
                                    var int_checked = 0;
                                    var elem = document.forms.namedItem("MessageList").getElementsByTagName("input");

                                    for (i=0; i < elem.length; i++){
                                        if(elem[i].checked == true){
                                            int_checked = ++int_checked;
                                        }
                                    }
                                    if (int_checked > 0){
                                        if (confirm("<?php echo xls('Do you really want to delete the selection?'); ?>")) {
                                            document.MessageList.submit();
                                        }
                                    } else {
                                        alert("<?php echo xls('Please select message(s) to delete'); ?>");
                                    }
                                }


                            // This is to allow selection of all items in Messages table for deletion.
                            function selectAll() {
                                if (document.getElementById("checkAll").checked === true) {
                                    document.getElementById("checkAll").checked = true;<?php
                                    for ($i = 1; $i <= $count; $i++) {
                                        echo "document.getElementById(\"check$i\").checked=true; document.getElementById(\"row$i\").style.background='var(--gray200)';  ";
                                    } ?>
                                } else {
                                    document.getElementById("checkAll").checked = false;<?php
                                    for ($i = 1; $i <= $count; $i++) {
                                        echo "document.getElementById(\"check$i\").checked=false; document.getElementById(\"row$i\").style.background='var(--light)';  ";
                                    } ?>
                                }
                            }

                            // The two functions below are for managing row styles in Messages table.
                            function selectRow(row) {
                                document.getElementById(row).style.background = "var(--gray200)";
                            }

                            function deselectRow(row) {
                                document.getElementById(row).style.background = "var(--light)";
                            }
                        </script>
                        <?php
                    }
                    ?>
                </div>
            </div><!--end of messages div-->
            <div class="row tab-pane" role="tabpanel" id="reminders-div">
                <div class="col-sm-12">
                    <div class="mb-3">
                        <h4><?php echo xlt('Reminders'); ?></h4>
                    </div>
                    <?php require_once '../dated_reminders/dated_reminders.php'; ?>
                </div>
            </div><!--end of reminders div-->
            <?php if ($GLOBALS['disable_rcb'] != '1') { ?>
            <div class="row tab-pane" role="tabpanel" id="recalls-div">
                <div class="col-sm-12" style="padding:0;">
                <style>
                    /* ── Recall Board ─────────────────────────────────────────── */
                    .rcb-wrap { background:#f9fafb; min-height:100%; }

                    /* Header */
                    .rcb-header {
                        display:flex; align-items:center; justify-content:space-between;
                        padding:20px 24px 16px; border-bottom:1px solid #e5e7eb;
                        background:#fff;
                    }
                    .rcb-header-left { display:flex; align-items:center; gap:14px; }
                    .rcb-header-icon {
                        width:44px; height:44px; border-radius:12px;
                        background:var(--mc-purple-pale); display:flex; align-items:center; justify-content:center;
                    }
                    .rcb-title { font-size:20px; font-weight:700; color:var(--mc-navy); margin:0; }
                    .rcb-subtitle { font-size:12px; color:var(--mc-gray-500); margin:2px 0 0; }
                    .rcb-header-right { display:flex; align-items:center; gap:10px; }
                    .rcb-btn-settings {
                        display:inline-flex; align-items:center; gap:6px;
                        padding:8px 14px; border-radius:6px; font-size:13px; font-weight:600;
                        border:1.5px solid #e5e7eb; background:#fff; color:#374151; cursor:pointer;
                        text-decoration:none;
                    }
                    .rcb-btn-settings:hover { background:#f3f4f6; color:#374151; }
                    .rcb-btn-new {
                        display:inline-flex; align-items:center; gap:6px;
                        padding:8px 16px; border-radius:6px; font-size:13px; font-weight:600;
                        background:var(--mc-purple); color:#fff; border:none; cursor:pointer;
                        text-decoration:none;
                    }
                    .rcb-btn-new:hover { background:var(--mc-purple-mid); color:#fff; }

                    /* Stat cards */
                    .rcb-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; padding:20px 24px; }
                    .rcb-stat-card {
                        background:#fff; border-radius:10px; border:1px solid #e5e7eb;
                        padding:18px 20px; display:flex; align-items:center; gap:14px;
                    }
                    .rcb-stat-icon {
                        width:40px; height:40px; border-radius:10px; display:flex;
                        align-items:center; justify-content:center; flex-shrink:0;
                    }
                    .rcb-stat-icon.purple  { background:#ede9fe; }
                    .rcb-stat-icon.green   { background:#dcfce7; }
                    .rcb-stat-icon.amber   { background:#fef3c7; }
                    .rcb-stat-icon.red     { background:#fee2e2; }
                    .rcb-stat-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; margin:0 0 4px; }
                    .rcb-stat-val   { font-size:26px; font-weight:800; color:var(--mc-navy); line-height:1; margin:0; }
                    .rcb-stat-val.green { color:#16a34a; }
                    .rcb-stat-val.amber { color:#d97706; }
                    .rcb-stat-val.red   { color:#dc2626; }
                    .rcb-stat-sub { font-size:11px; color:#6b7280; margin:4px 0 0; }

                    /* Filters */
                    .rcb-filters {
                        background:#fff; border-top:1px solid #e5e7eb; border-bottom:1px solid #e5e7eb;
                        padding:16px 24px;
                    }
                    .rcb-filter-row { display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; }
                    .rcb-filter-group { display:flex; flex-direction:column; gap:4px; }
                    .rcb-filter-group label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; margin:0; }
                    .rcb-filter-group select,
                    .rcb-filter-group input[type="text"],
                    .rcb-filter-group input[type="date"] {
                        height:36px; padding:0 10px; border:1.5px solid #e5e7eb; border-radius:6px;
                        font-size:13px; color:#374151; background:#fff; min-width:140px;
                    }
                    .rcb-filter-group select:focus,
                    .rcb-filter-group input:focus { border-color:var(--mc-purple-light); outline:none; box-shadow:0 0 0 3px rgba(124,58,237,.1); }
                    .rcb-filter-search { position:relative; }
                    .rcb-filter-search input { padding-left:32px; min-width:180px; }
                    .rcb-filter-search svg { position:absolute; left:9px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none; }
                    .rcb-filter-actions { display:flex; align-items:center; gap:8px; margin-left:auto; }
                    .rcb-filter-actions button { height:36px; }
                    .rcb-btn-filter {
                        display:inline-flex; align-items:center; gap:6px; padding:0 14px;
                        background:var(--mc-purple); color:#fff; border:none; border-radius:6px;
                        font-size:13px; font-weight:600; cursor:pointer;
                    }
                    .rcb-btn-filter:hover { background:var(--mc-purple-mid); }
                    .rcb-btn-clear {
                        display:inline-flex; align-items:center; gap:5px; padding:0 14px;
                        background:#fff; color:#374151; border:1.5px solid #e5e7eb; border-radius:6px;
                        font-size:13px; font-weight:600; cursor:pointer;
                    }
                    .rcb-btn-clear:hover { background:#f3f4f6; }
                    .rcb-save-filter {
                        display:inline-flex; align-items:center; gap:5px;
                        font-size:12px; font-weight:600; color:var(--mc-purple); cursor:pointer;
                        background:none; border:none; padding:0;
                        text-decoration:none;
                    }
                    .rcb-save-filter:hover { text-decoration:underline; color:var(--mc-purple-mid); }

                    /* Tabs */
                    .rcb-tabs { padding:16px 24px 0; background:#fff; }
                    .rcb-tab-list { display:flex; gap:6px; border-bottom:none; }
                    .rcb-tab {
                        display:inline-flex; align-items:center; gap:6px; padding:7px 14px;
                        border-radius:999px; font-size:13px; font-weight:600; cursor:pointer;
                        border:none; background:#f3f4f6; color:#374151; transition:all .15s;
                    }
                    .rcb-tab:hover { background:#e5e7eb; }
                    .rcb-tab.active-all    { background:var(--mc-navy); color:#fff; }
                    .rcb-tab.active-events { background:#dbeafe; color:#1d4ed8; }
                    .rcb-tab.active-inproc { background:#fef9c3; color:#854d0e; }
                    .rcb-tab.active-manual { background:#fee2e2; color:#b91c1c; }
                    .rcb-tab.active-recent { background:#dcfce7; color:#15803d; }
                    .rcb-tab-count {
                        display:inline-flex; align-items:center; justify-content:center;
                        min-width:22px; height:18px; padding:0 5px;
                        border-radius:999px; font-size:11px; font-weight:700;
                    }
                    .rcb-tab.active-all    .rcb-tab-count { background:rgba(255,255,255,.2); color:#fff; }
                    .rcb-tab.active-events .rcb-tab-count { background:#bfdbfe; color:#1e40af; }
                    .rcb-tab.active-inproc .rcb-tab-count { background:#fde68a; color:#78350f; }
                    .rcb-tab.active-manual .rcb-tab-count { background:#fecaca; color:#991b1b; }
                    .rcb-tab.active-recent .rcb-tab-count { background:#bbf7d0; color:#14532d; }
                    .rcb-tab:not([class*="active-"]) .rcb-tab-count { background:#e5e7eb; color:#374151; }

                    /* Table */
                    .rcb-table-wrap { padding:0 24px 24px; background:#f9fafb; }
                    .rcb-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-top:12px; font-size:13px; }
                    .rcb-table thead th {
                        padding:10px 14px; text-align:left; font-size:10px; font-weight:700;
                        text-transform:uppercase; letter-spacing:.06em; color:#6b7280;
                        background:#f9fafb; border-bottom:1px solid #e5e7eb; white-space:nowrap;
                    }
                    .rcb-table thead th:first-child { width:36px; }
                    .rcb-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background .1s; }
                    .rcb-table tbody tr:last-child { border-bottom:none; }
                    .rcb-table tbody tr:hover { background:#faf9ff; }
                    .rcb-table tbody td { padding:12px 14px; vertical-align:middle; color:#374151; }

                    /* Patient cell */
                    .rcb-patient-avatar {
                        width:32px; height:32px; border-radius:50%; display:inline-flex;
                        align-items:center; justify-content:center; font-size:12px;
                        font-weight:700; color:#fff; flex-shrink:0;
                    }
                    .rcb-patient-cell { display:flex; align-items:center; gap:10px; }
                    .rcb-patient-name { font-weight:600; color:var(--mc-navy); font-size:13px; }
                    .rcb-patient-meta { font-size:11px; color:#6b7280; margin-top:1px; }

                    /* Recall cell */
                    .rcb-recall-name { font-weight:600; font-size:13px; color:#374151; display:flex; align-items:center; gap:6px; }
                    .rcb-recall-last  { font-size:11px; color:#9ca3af; margin-top:2px; }

                    /* Due date badges */
                    .rcb-due { display:inline-flex; align-items:center; gap:5px; padding:3px 8px; border-radius:5px; font-size:12px; font-weight:600; }
                    .rcb-due.overdue  { background:#fee2e2; color:#dc2626; }
                    .rcb-due.due-soon { background:#fef3c7; color:#d97706; }
                    .rcb-due.not-due  { background:#dcfce7; color:#16a34a; }
                    .rcb-due-days { font-size:11px; color:#9ca3af; margin-top:2px; }

                    /* Status pills */
                    .rcb-status { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:700; }
                    .rcb-status.inprocess { background:#ede9fe; color:#6d28d9; }
                    .rcb-status.scheduled { background:#dbeafe; color:#1d4ed8; }
                    .rcb-status.manual    { background:#fee2e2; color:#b91c1c; }
                    .rcb-status.completed { background:#dcfce7; color:#15803d; }

                    /* Recall type pills */
                    .rcb-type { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; background:#f3f4f6; color:#374151; }
                    .rcb-type.preventive { background:#ede9fe; color:#6d28d9; }
                    .rcb-type.screening  { background:#dbeafe; color:#1e40af; }
                    .rcb-type.followup   { background:#fef3c7; color:#92400e; }
                    .rcb-type.chronic    { background:#dcfce7; color:#166534; }

                    /* Actions */
                    .rcb-actions { display:flex; align-items:center; gap:6px; }
                    .rcb-action-btn {
                        width:28px; height:28px; border-radius:6px; display:inline-flex;
                        align-items:center; justify-content:center; border:1.5px solid #e5e7eb;
                        background:#fff; color:#6b7280; cursor:pointer; transition:all .15s;
                        position:relative;
                    }
                    .rcb-action-btn:hover { border-color:#9ca3af; background:#f3f4f6; color:#374151; }

                    /* Pagination */
                    .rcb-pagination { display:flex; align-items:center; justify-content:space-between; padding:14px 24px; background:#fff; border-top:1px solid #e5e7eb; font-size:13px; }
                    .rcb-pagination-info { color:#6b7280; }
                    .rcb-pagination-pages { display:flex; align-items:center; gap:4px; }
                    .rcb-page-btn {
                        width:32px; height:32px; border-radius:6px; display:inline-flex;
                        align-items:center; justify-content:center; border:1.5px solid #e5e7eb;
                        background:#fff; color:#374151; font-size:13px; font-weight:600; cursor:pointer;
                        transition:all .15s;
                    }
                    .rcb-page-btn:hover { background:#f3f4f6; }
                    .rcb-page-btn.active { background:var(--mc-purple); color:#fff; border-color:var(--mc-purple); }
                    .rcb-page-btn.disabled { opacity:.4; pointer-events:none; }

                    /* Show entries */
                    .rcb-show-row { display:flex; align-items:center; gap:8px; color:#6b7280; font-size:13px; }
                    .rcb-show-row select { height:32px; padding:0 8px; border:1.5px solid #e5e7eb; border-radius:6px; font-size:13px; }

                    /* Sort indicators */
                    .rcb-sort { display:inline-flex; align-items:center; gap:3px; cursor:pointer; user-select:none; }
                    .rcb-sort svg { opacity:.4; }
                    .rcb-sort.asc svg.up,
                    .rcb-sort.desc svg.dn { opacity:1; color:var(--mc-purple); }

                    @media(max-width:900px) {
                        .rcb-stats { grid-template-columns:repeat(2,1fr); }
                        .rcb-filter-row { flex-direction:column; align-items:stretch; }
                        .rcb-filter-actions { margin-left:0; }
                    }
                </style>

                <div class="rcb-wrap">

                    <!-- Header -->
                    <div class="rcb-header">
                        <div class="rcb-header-left">
                            <div class="rcb-header-icon">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                            </div>
                            <div>
                                <div class="rcb-title"><?php echo xlt('Recall Board'); ?></div>
                                <div class="rcb-subtitle"><?php echo xlt('Persons needing a recall — no appointment scheduled yet'); ?></div>
                            </div>
                        </div>
                        <div class="rcb-header-right">
                            <a class="rcb-btn-settings" onclick="goReminderRecall('Recalls');" href="#">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                                <?php echo xlt('Recall Settings'); ?>
                            </a>
                            <button class="rcb-btn-new" onclick="goReminderRecall('addRecall');">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                <?php echo xlt('New Recall'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Stat Cards -->
                    <div class="rcb-stats" id="rcb-stats">
                        <div class="rcb-stat-card">
                            <div class="rcb-stat-icon purple">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                            </div>
                            <div>
                                <div class="rcb-stat-label"><?php echo xlt('Total Recalls'); ?></div>
                                <div class="rcb-stat-val" id="rcb-total">—</div>
                                <div class="rcb-stat-sub"><?php echo xlt('Active recalls'); ?></div>
                            </div>
                        </div>
                        <div class="rcb-stat-card">
                            <div class="rcb-stat-icon green">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </div>
                            <div>
                                <div class="rcb-stat-label"><?php echo xlt('Due This Week'); ?></div>
                                <div class="rcb-stat-val green" id="rcb-due-week">—</div>
                                <div class="rcb-stat-sub"><?php echo xlt('Next 7 days'); ?></div>
                            </div>
                        </div>
                        <div class="rcb-stat-card">
                            <div class="rcb-stat-icon amber">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <div>
                                <div class="rcb-stat-label"><?php echo xlt('Overdue'); ?></div>
                                <div class="rcb-stat-val amber" id="rcb-overdue">—</div>
                                <div class="rcb-stat-sub"><?php echo xlt('Past due'); ?></div>
                            </div>
                        </div>
                        <div class="rcb-stat-card">
                            <div class="rcb-stat-icon red">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                            </div>
                            <div>
                                <div class="rcb-stat-label"><?php echo xlt('No Appt Scheduled'); ?></div>
                                <div class="rcb-stat-val red" id="rcb-no-appt">—</div>
                                <div class="rcb-stat-sub" id="rcb-no-appt-pct">100%</div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="rcb-filters">
                        <div class="rcb-filter-row">
                            <div class="rcb-filter-group">
                                <label><?php echo xlt('Facility'); ?></label>
                                <select id="rcb-facility" class="form-control" style="height:36px;font-size:13px;min-width:150px;">
                                    <option value=""><?php echo xlt('All Facilities'); ?></option>
                                    <?php
                                    $fres = sqlStatement("SELECT id, name FROM facility ORDER BY name");
                                    while ($frow = sqlFetchArray($fres)) {
                                        echo '<option value="' . attr($frow['id']) . '">' . text($frow['name']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="rcb-filter-group">
                                <label><?php echo xlt('Provider'); ?></label>
                                <select id="rcb-provider" class="form-control" style="height:36px;font-size:13px;min-width:160px;">
                                    <option value=""><?php echo xlt('All Providers'); ?></option>
                                    <?php
                                    $pres = sqlStatement("SELECT id, fname, lname FROM users WHERE active=1 AND (authorized=1 OR info LIKE '%provider%') ORDER BY lname, fname");
                                    while ($prow = sqlFetchArray($pres)) {
                                        echo '<option value="' . attr($prow['id']) . '">' . text($prow['lname'] . ', ' . $prow['fname']) . '</option>';
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="rcb-filter-group rcb-filter-search">
                                <label><?php echo xlt('Patient'); ?></label>
                                <div style="position:relative;">
                                    <svg style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9ca3af;pointer-events:none;" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                    <input type="text" id="rcb-patient-search" placeholder="<?php echo xla('Search by name or ID...'); ?>" style="height:36px;padding:0 10px 0 32px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:13px;min-width:190px;">
                                </div>
                            </div>
                            <div class="rcb-filter-group">
                                <label><?php echo xlt('Recall Type'); ?></label>
                                <select id="rcb-type-filter" class="form-control" style="height:36px;font-size:13px;min-width:140px;">
                                    <option value=""><?php echo xlt('All Types'); ?></option>
                                </select>
                            </div>
                            <div class="rcb-filter-group">
                                <label><?php echo xlt('From'); ?></label>
                                <input type="date" id="rcb-from" value="<?php echo attr(date('Y-m-d', strtotime('-6 months'))); ?>" style="height:36px;padding:0 10px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:13px;">
                            </div>
                            <div class="rcb-filter-group">
                                <label><?php echo xlt('To'); ?></label>
                                <input type="date" id="rcb-to" value="<?php echo attr(date('Y-m-d')); ?>" style="height:36px;padding:0 10px;border:1.5px solid #e5e7eb;border-radius:6px;font-size:13px;">
                            </div>
                            <div class="rcb-filter-actions">
                                <button class="rcb-btn-filter" id="rcb-filter-btn">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                                    <?php echo xlt('Filter'); ?>
                                </button>
                                <button class="rcb-btn-clear" id="rcb-clear-btn">
                                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
                                    <?php echo xlt('Clear'); ?>
                                </button>
                                <a href="#" class="rcb-save-filter" id="rcb-save-filter">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13"/><polyline points="7 3 7 8 15 8"/></svg>
                                    <?php echo xlt('Save Filter'); ?>
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Status tabs -->
                    <div class="rcb-tabs">
                        <div class="rcb-tab-list">
                            <button class="rcb-tab active-all" data-tab="all" onclick="rcbSetTab(this)">
                                <?php echo xlt('All'); ?> <span class="rcb-tab-count" id="rcb-cnt-all">0</span>
                            </button>
                            <button class="rcb-tab" data-tab="Events Scheduled" onclick="rcbSetTab(this)" style="color:#1d4ed8;">
                                <?php echo xlt('Events Scheduled'); ?> <span class="rcb-tab-count" id="rcb-cnt-events">0</span>
                            </button>
                            <button class="rcb-tab" data-tab="In-process" onclick="rcbSetTab(this)" style="color:#854d0e;">
                                <?php echo xlt('In-process'); ?> <span class="rcb-tab-count" id="rcb-cnt-inproc">0</span>
                            </button>
                            <button class="rcb-tab" data-tab="Manual Processing Required" onclick="rcbSetTab(this)" style="color:#b91c1c;">
                                <?php echo xlt('Manual Processing Required'); ?> <span class="rcb-tab-count" id="rcb-cnt-manual">0</span>
                            </button>
                            <button class="rcb-tab" data-tab="Recently Completed" onclick="rcbSetTab(this)" style="color:#15803d;">
                                <?php echo xlt('Recently Completed'); ?> <span class="rcb-tab-count" id="rcb-cnt-recent">0</span>
                            </button>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="rcb-table-wrap">
                        <table class="rcb-table" id="rcb-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="rcb-check-all" style="accent-color:var(--mc-purple);"></th>
                                    <th><span class="rcb-sort" data-col="patient_name" onclick="rcbSort(this)"><?php echo xlt('Patient'); ?> <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline class="up" points="18 15 12 9 6 15"/></svg></span></th>
                                    <th><span class="rcb-sort" data-col="recall_name" onclick="rcbSort(this)"><?php echo xlt('Recall'); ?> <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline class="up" points="18 15 12 9 6 15"/></svg></span></th>
                                    <th><span class="rcb-sort desc" data-col="due_date" onclick="rcbSort(this)"><?php echo xlt('Due Date'); ?> <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline class="dn" points="6 9 12 15 18 9"/></svg></span></th>
                                    <th><?php echo xlt('Provider'); ?></th>
                                    <th><?php echo xlt('Recall Type'); ?></th>
                                    <th><?php echo xlt('Next Step'); ?></th>
                                    <th><?php echo xlt('Status'); ?></th>
                                    <th><?php echo xlt('Actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="rcb-tbody">
                                <tr><td colspan="9" style="text-align:center;padding:40px;color:#9ca3af;">
                                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin:0 auto 8px;display:block;opacity:.4"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>
                                    <?php echo xlt('Loading recalls…'); ?>
                                </td></tr>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-top:14px;flex-wrap:wrap;gap:10px;">
                            <div class="rcb-show-row">
                                <?php echo xlt('Show'); ?>
                                <select id="rcb-per-page" onchange="rcbLoadPage(1)">
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                    <option value="50">50</option>
                                </select>
                                <?php echo xlt('entries'); ?>
                            </div>
                            <div style="font-size:13px;color:#6b7280;" id="rcb-showing-info"></div>
                            <div class="rcb-pagination-pages" id="rcb-pages"></div>
                        </div>
                    </div>

                </div><!-- .rcb-wrap -->
                </div>
            </div><!--end of recalls div-->

            <script>
            (function(){
                // ── State ────────────────────────────────────────────────
                var _all     = [];   // full dataset from MedEx
                var _filtered = [];  // after tab + search + date filter
                var _tab     = 'all';
                var _sortCol = 'due_date';
                var _sortDir = 'asc';
                var _page    = 1;
                var _perPage = 10;

                // Avatar colours
                var _avatarColors = ['#7c3aed','#2563eb','#16a34a','#d97706','#dc2626','#0891b2','#9333ea','#c2410c'];
                function avatarColor(name) {
                    var h = 0;
                    for (var i=0;i<name.length;i++) h = (h*31 + name.charCodeAt(i)) & 0xffffffff;
                    return _avatarColors[Math.abs(h) % _avatarColors.length];
                }
                function initials(name) {
                    var p = name.split(',');
                    var last  = (p[0]||'').trim();
                    var first = (p[1]||'').trim();
                    return ((first[0]||'') + (last[0]||'')).toUpperCase();
                }

                // ── Pull data from MedEx recall display (hidden iframe trick) ──
                // We call the existing goReminderRecall but intercept its JSON feed
                // via the messages.php save.php endpoint that MedEx exposes.
                // If MedEx isn't wired, we fall back to a placeholder notice.
                function rcbFetch() {
                    // Try the MedEx JSON endpoint for recalls
                    var webroot = <?php echo js_escape($GLOBALS['web_root']); ?>;
                    fetch(webroot + '/interface/main/messages/save.php?go=Recalls&json=1', {credentials:'same-origin'})
                        .then(function(r){ return r.ok ? r.json() : Promise.reject(r.status); })
                        .then(function(d){ rcbIngest(d); })
                        .catch(function(){ rcbIngest(null); });
                }

                function rcbIngest(d) {
                    // Parse whatever MedEx returns; fall back to empty
                    var rows = [];
                    if (d && Array.isArray(d.recalls)) {
                        rows = d.recalls;
                    } else if (d && Array.isArray(d)) {
                        rows = d;
                    }
                    _all = rows;
                    rcbApplyFilter();
                }

                // ── Filtering ─────────────────────────────────────────────
                function rcbApplyFilter() {
                    var search   = (document.getElementById('rcb-patient-search').value || '').toLowerCase();
                    var facility = document.getElementById('rcb-facility').value;
                    var provider = document.getElementById('rcb-provider').value;
                    var typeF    = document.getElementById('rcb-type-filter').value;
                    var from     = document.getElementById('rcb-from').value;
                    var to       = document.getElementById('rcb-to').value;

                    _filtered = _all.filter(function(r) {
                        if (_tab !== 'all' && r.status !== _tab) return false;
                        if (search && !((r.patient_name||'').toLowerCase().includes(search) ||
                                        (r.pid||'').toString().includes(search))) return false;
                        if (facility && r.facility_id !== facility) return false;
                        if (provider && r.provider_id !== provider) return false;
                        if (typeF    && r.recall_type !== typeF) return false;
                        if (from && r.due_date && r.due_date < from) return false;
                        if (to   && r.due_date && r.due_date > to)   return false;
                        return true;
                    });

                    rcbSort(null);
                }

                // ── Sorting ───────────────────────────────────────────────
                function rcbSortData() {
                    var col = _sortCol, dir = _sortDir;
                    _filtered.sort(function(a,b) {
                        var va = (a[col]||'').toString().toLowerCase();
                        var vb = (b[col]||'').toString().toLowerCase();
                        if (va < vb) return dir === 'asc' ? -1 : 1;
                        if (va > vb) return dir === 'asc' ?  1 : -1;
                        return 0;
                    });
                }

                // ── Render ────────────────────────────────────────────────
                function rcbRender() {
                    rcbUpdateStats();
                    rcbUpdateTabs();
                    rcbRenderTable();
                    rcbRenderPager();
                }

                function rcbUpdateStats() {
                    var today = new Date().toISOString().slice(0,10);
                    var week  = new Date(Date.now() + 7*86400000).toISOString().slice(0,10);
                    var total=0, dueWeek=0, overdue=0, noAppt=0;
                    _all.forEach(function(r) {
                        if (r.status === 'Recently Completed') return;
                        total++;
                        if (r.due_date && r.due_date < today) overdue++;
                        if (r.due_date && r.due_date >= today && r.due_date <= week) dueWeek++;
                        noAppt++;
                    });
                    document.getElementById('rcb-total').textContent    = total;
                    document.getElementById('rcb-due-week').textContent = dueWeek;
                    document.getElementById('rcb-overdue').textContent  = overdue;
                    document.getElementById('rcb-no-appt').textContent  = noAppt;
                    document.getElementById('rcb-no-appt-pct').textContent = total ? Math.round(noAppt/total*100)+'%' : '0%';
                }

                function rcbUpdateTabs() {
                    var counts = {'all':_all.length,'Events Scheduled':0,'In-process':0,'Manual Processing Required':0,'Recently Completed':0};
                    _all.forEach(function(r){ if (counts[r.status] !== undefined) counts[r.status]++; });
                    document.getElementById('rcb-cnt-all').textContent    = counts['all'];
                    document.getElementById('rcb-cnt-events').textContent = counts['Events Scheduled'];
                    document.getElementById('rcb-cnt-inproc').textContent = counts['In-process'];
                    document.getElementById('rcb-cnt-manual').textContent = counts['Manual Processing Required'];
                    document.getElementById('rcb-cnt-recent').textContent = counts['Recently Completed'];
                }

                function rcbRenderTable() {
                    var tbody = document.getElementById('rcb-tbody');
                    if (!_filtered.length) {
                        tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:40px;color:#9ca3af;"><?php echo xlt('No recalls found matching the current filters.'); ?></td></tr>';
                        return;
                    }
                    var start = (_page-1)*_perPage, end = Math.min(start+_perPage, _filtered.length);
                    var html = '';
                    for (var i=start; i<end; i++) {
                        var r = _filtered[i];
                        html += rcbRow(r);
                    }
                    tbody.innerHTML = html;
                }

                function rcbRow(r) {
                    var today = new Date().toISOString().slice(0,10);
                    var week7 = new Date(Date.now()+7*86400000).toISOString().slice(0,10);
                    var name  = r.patient_name || '—';
                    var col   = avatarColor(name);
                    var init  = initials(name);

                    // Due date badge
                    var dueCls='not-due', dueLbl='<?php echo xlt('Not Due'); ?>';
                    if (r.due_date) {
                        if (r.due_date < today)       { dueCls='overdue';  dueLbl='<?php echo xlt('Overdue'); ?>'; }
                        else if (r.due_date <= week7) { dueCls='due-soon'; dueLbl='<?php echo xlt('Due Soon'); ?>'; }
                    }
                    var dueIcon = dueCls==='overdue'  ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>' :
                                  dueCls==='due-soon' ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' :
                                  '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';

                    // Days label
                    var daysLabel = '';
                    if (r.due_date) {
                        var diff = Math.round((new Date(r.due_date)-new Date(today)) / 86400000);
                        daysLabel = diff < 0 ? '(' + Math.abs(diff) + ' day' + (Math.abs(diff)!==1?'s':'') + ')' : '(' + diff + ' day' + (diff!==1?'s':'') + ')';
                    }

                    // Status pill
                    var stCls = {
                        'In-process':'inprocess','Events Scheduled':'scheduled',
                        'Manual Processing Required':'manual','Recently Completed':'completed'
                    }[r.status] || 'inprocess';

                    // Type pill
                    var typeCls = {
                        'Preventive':'preventive','Screening':'screening',
                        'Follow Up':'followup','Chronic Care':'chronic'
                    }[r.recall_type] || '';

                    var dueFmt = r.due_date ? r.due_date.replace(/(\d{4})-(\d{2})-(\d{2})/,'$2/$3/$1') : '—';

                    return '<tr>' +
                        '<td><input type="checkbox" style="accent-color:var(--mc-purple);"></td>' +
                        '<td><div class="rcb-patient-cell">' +
                            '<div class="rcb-patient-avatar" style="background:' + col + '">' + init + '</div>' +
                            '<div><div class="rcb-patient-name">' + escH(name) + '</div>' +
                            '<div class="rcb-patient-meta">ID: ' + escH(r.pid||'') + ' &nbsp;•&nbsp; ' + escH(r.dob||'') + '</div></div>' +
                        '</div></td>' +
                        '<td><div class="rcb-recall-name"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' + escH(r.recall_name||'—') + '</div>' +
                            '<div class="rcb-recall-last"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Last: ' + escH(r.last_contact||'—') + '</div></td>' +
                        '<td><div class="rcb-due ' + dueCls + '">' + dueIcon + dueLbl + '</div>' +
                            '<div class="rcb-due-days">' + escH(dueFmt) + ' ' + daysLabel + '</div></td>' +
                        '<td style="color:#374151;">' + escH(r.provider_name||'—') + '</td>' +
                        '<td><span class="rcb-type ' + typeCls + '">' + escH(r.recall_type||'—') + '</span></td>' +
                        '<td style="font-size:12px;color:#374151;">' + escH(r.next_step||'—') + '</td>' +
                        '<td><span class="rcb-status ' + stCls + '">' + escH(r.status||'—') + '</span></td>' +
                        '<td><div class="rcb-actions">' +
                            '<button class="rcb-action-btn" title="<?php echo xla('Actions'); ?>" onclick="rcbActionMenu(event,' + escH(JSON.stringify(r)) + ')">•••</button>' +
                        '</div></td>' +
                    '</tr>';
                }

                function rcbRenderPager() {
                    var total = _filtered.length;
                    var pages = Math.ceil(total / _perPage) || 1;
                    var start = (_page-1)*_perPage + 1;
                    var end   = Math.min(_page*_perPage, total);

                    document.getElementById('rcb-showing-info').textContent =
                        total ? ('<?php echo xlt('Showing'); ?> ' + start + ' <?php echo xlt('to'); ?> ' + end + ' <?php echo xlt('of'); ?> ' + total + ' <?php echo xlt('results'); ?>') : '';

                    var container = document.getElementById('rcb-pages');
                    var html = '';
                    html += '<button class="rcb-page-btn' + (_page===1?' disabled':'') + '" onclick="rcbLoadPage(1)" title="First">«</button>';
                    html += '<button class="rcb-page-btn' + (_page===1?' disabled':'') + '" onclick="rcbLoadPage(' + (_page-1) + ')">‹</button>';

                    var lo = Math.max(1, _page-2), hi = Math.min(pages, _page+2);
                    if (lo > 1) html += '<button class="rcb-page-btn disabled" style="border:none;background:none;">…</button>';
                    for (var p=lo; p<=hi; p++) {
                        html += '<button class="rcb-page-btn' + (p===_page?' active':'') + '" onclick="rcbLoadPage(' + p + ')">' + p + '</button>';
                    }
                    if (hi < pages) html += '<button class="rcb-page-btn disabled" style="border:none;background:none;">…</button>';
                    html += '<button class="rcb-page-btn' + (_page===pages?' disabled':'') + '" onclick="rcbLoadPage(' + (_page+1) + ')">›</button>';
                    html += '<button class="rcb-page-btn' + (_page===pages?' disabled':'') + '" onclick="rcbLoadPage(' + pages + ')" title="Last">»</button>';
                    container.innerHTML = html;
                }

                // ── Public interface ──────────────────────────────────────
                window.rcbSetTab = function(btn) {
                    document.querySelectorAll('.rcb-tab').forEach(function(b){
                        b.className = 'rcb-tab';
                        b.style.color = '';
                    });
                    _tab = btn.dataset.tab;
                    var cls = {
                        'all':'active-all','Events Scheduled':'active-events',
                        'In-process':'active-inproc','Manual Processing Required':'active-manual',
                        'Recently Completed':'active-recent'
                    }[_tab] || 'active-all';
                    btn.classList.add(cls);
                    _page = 1;
                    rcbApplyFilter();
                };

                window.rcbSort = function(el) {
                    if (el) {
                        var col = el.dataset ? el.dataset.col : el;
                        if (_sortCol === col) { _sortDir = _sortDir === 'asc' ? 'desc' : 'asc'; }
                        else { _sortCol = col; _sortDir = 'asc'; }
                        document.querySelectorAll('.rcb-sort').forEach(function(s){
                            s.classList.remove('asc','desc');
                        });
                        el.classList.add(_sortDir);
                    }
                    rcbSortData();
                    rcbRender();
                };

                window.rcbLoadPage = function(p) {
                    var pages = Math.ceil(_filtered.length/_perPage)||1;
                    _page = Math.max(1, Math.min(p, pages));
                    _perPage = parseInt(document.getElementById('rcb-per-page').value) || 10;
                    rcbRenderTable();
                    rcbRenderPager();
                };

                window.rcbActionMenu = function(evt, r) {
                    // Delegate to MedEx existing recall open if available
                    if (typeof goReminderRecall === 'function') {
                        goReminderRecall('Recalls');
                    }
                };

                function escH(s) {
                    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                }

                // ── Wire up controls ──────────────────────────────────────
                document.getElementById('rcb-filter-btn').addEventListener('click', function(){ _page=1; rcbApplyFilter(); });
                document.getElementById('rcb-clear-btn').addEventListener('click', function(){
                    document.getElementById('rcb-patient-search').value = '';
                    document.getElementById('rcb-facility').value = '';
                    document.getElementById('rcb-provider').value = '';
                    document.getElementById('rcb-type-filter').value = '';
                    document.getElementById('rcb-from').value = '<?php echo attr(date('Y-m-d', strtotime('-6 months'))); ?>';
                    document.getElementById('rcb-to').value   = '<?php echo attr(date('Y-m-d')); ?>';
                    _tab = 'all';
                    document.querySelectorAll('.rcb-tab').forEach(function(b){ b.className='rcb-tab'; b.style.color=''; });
                    document.querySelector('.rcb-tab[data-tab="all"]').classList.add('active-all');
                    _page = 1;
                    rcbApplyFilter();
                });
                document.getElementById('rcb-patient-search').addEventListener('keydown', function(e){
                    if (e.key==='Enter') { _page=1; rcbApplyFilter(); }
                });
                document.getElementById('rcb-check-all').addEventListener('change', function(){
                    document.querySelectorAll('#rcb-tbody input[type="checkbox"]').forEach(function(c){ c.checked = this.checked; }, this);
                });

                // ── Kick off ──────────────────────────────────────────────
                // Try to pull live data; if MedEx is not available show a notice
                // pointing to the full board.
                rcbFetch();

                // Fallback: if after 1.5 s we still have no data, show a
                // "click to open full board" notice so the page isn't blank.
                setTimeout(function(){
                    if (!_all.length) {
                        document.getElementById('rcb-tbody').innerHTML =
                            '<tr><td colspan="9" style="text-align:center;padding:40px 20px;">' +
                            '<div style="color:#9ca3af;margin-bottom:12px;"><?php echo xlt('Recall data is managed by MedEx. Click the button below to open the full Recall Board.'); ?></div>' +
                            '<button class="rcb-btn-new" onclick="goReminderRecall(\'Recalls\')">' +
                            '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg>' +
                            '<?php echo xlt('Open Recall Board'); ?></button>' +
                            '</td></tr>';
                        document.getElementById('rcb-showing-info').textContent = '';
                        document.getElementById('rcb-pages').innerHTML = '';
                    }
                }, 1500);
            })();
            </script>
            <?php } ?>
            <div class="row tab-pane" role="tabpanel" id="sms-div">
                <div class="col-sm-12">
                    <div class="jumbotron jumbotron-fluid p-2">
                        <?php if ($logged_in) { ?>
                        <div class="col-sm-4 col-md-4 col-lg-4">
                            <span class="title"><?php echo xlt('SMS Zone'); ?></span>
                            <br/><br/>
                            <form id="smsForm" class="input-group">
                                <select id="SMS_patient" type="text" class="form-control m-0 w-100" placeholder="<?php echo xla("Patient Name"); ?>" > </select>
                                <span class="input-group-addon" onclick="SMS_direct();"><svg class="mc-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6.06 6.06l1.27-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span>
                                <input type="hidden" id="sms_pid" />
                                <input type="hidden" id="sms_mobile" value="" />
                                <input type="hidden" id="sms_allow" value="" />
                            </form>
                        </div>
                        <?php } ?>
                    </div>
                </div>
            </div><!--end of sms div-->
        </div>
    </div><!--end of container div-->
    <?php $oemr_ui->oeBelowContainerDiv();?>
    <?php
    //home of the help modal ;)
    //$GLOBALS['enable_help'] = 0; // Please comment out line if you want help modal to function on this page
    if ($GLOBALS['enable_help'] == 1) {
        echo "<script>var helpFile = 'message_center_help.php'</script>";
        //help_modal.php lives in interface, set path accordingly
        require "../../help_modal.php";
    }
    ?>
    <script>
        var collectvalidation = <?php echo $collectthis; ?>;

        $(function () {
            var webRoot = <?php echo js_escape($GLOBALS['web_root']); ?>;

            $('.datetimepicker').datetimepicker({
                <?php $datetimepicker_timepicker = true; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
                ,minDate : 0 //only future
            })

            <?php if ($GLOBALS['phimail_enable']) : ?>
            $('.trusted-messages-force-check').click(function() {
                window.top.restoreSession();
                request = new FormData;
                request.append("ajax", "1");
                request.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
                request.append("background_service", "phimail");
                request.append("background_force", "1");
                fetch(webRoot + "/library/ajax/execute_background_services.php", {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: request
                }).then((response) => {
                    if (response.status !== 200) {
                        console.log('Background Service refresh failed. Status Code: ' + response.status);
                    } else {
                        // we've refreshed give them time to reload the page
                        setTimeout(function() {
                            window.location.reload();
                        }, 500);
                    }
                }).catch(function(error) {
                    console.log('Background Service refresh failed: ', error);
                    alert(window.xl("Check new messages failed. Check the server logs for more information."));
                });
            });
            <?php endif; ?>

        });
        $(function () {
            $( "ul.navbar-nav" ).children().click(function(){
                $(".collapse").collapse('hide');
            });
        });
        $(function () {
            $('#see-all-tooltip').attr({"title": <?php echo xlj('Click to show messages for all users'); ?>, "data-toggle":"tooltip", "data-placement":"bottom"}).tooltip();
            $('#just-mine-tooltip').attr({"title": <?php echo xlj('Click to show messages for only the current user'); ?>, "data-toggle":"tooltip", "data-placement":"bottom"}).tooltip();
        });
        $(function () {
            var f = $("#smsForm");
            $("#SMS_patient").select2({
                ajax: {
                    url: "save.php",
                    dataType: 'json',
                    data: function(params) {
                        return {
                        go: "sms_search",
                        term: params.term
                        };
                    },
                    processResults: function(data) {
                        return  {
                            results: $.map(data, function(item, index) {
                                return {
                                    text: item.value,
                                    id: index,
                                    value: item.Label + ' ' + item.mobile,
                                    pid: item.pid,
                                    mobile: item.mobile,
                                    allow: item.allow
                                }
                            })
                        };
                    },
                    cache: true
                }
            })

            $('#SMS_patient').on('select2:select', function (e) {
                        e.preventDefault();
                        $("#SMS_patient").val(e.params.data.value);
                        $("#sms_pid").val(e.params.data.pid);
                        $("#sms_mobile").val(e.params.data.mobile);
                        $("#sms_allow").val(e.params.data.allow);
            });
        })

        $(function () {
            $("#newnote").click(function (event) {
                NewNote(event);
            });

            $("#printnote").click(function () {
                PrintNote();
            });

            var obj = $("#form_message_status");
            obj.onchange = function () {
                SaveNote();
            };

            $("#cancel").click(function () {
                CancelNote();
            });

            $("#form_patient").focus();

            //clear button in messages
            $("#clear_user").click(function(){
                $("#assigned_to_text").val("<?php echo xls('Select Users From The Dropdown List'); ?>");
                $("#assigned_to").val("");
                $("#users").val("--");
            });

            //clear inputs of patients
            $("#clear_patients").click(function(){
                $("#reply_to").val("");
                $("#form_patient").val("");
            });
        });

        var NewNote = function (event) {
            top.restoreSession();
            if(document.getElementById("form_message_status").value !== 'Done'){
                collectvalidation.assigned_to = {
                    presence: {message: "<?php echo xls('Recipient required unless status is Done'); ?>"}
                }
            }
            else{
                delete collectvalidation.assigned_to;
            }

            if(document.getElementById("form_message_status").value == 'Done'){
                delete collectvalidation.note;
            }

            $('#newnote').attr('disabled', true);

            const submit = submitme(1, event, 'new_note', collectvalidation);
            if(!submit){
                $('#newnote').attr('disabled', false);
            }
            else {
                $("#new_note").submit();
            }
        };
        var PrintNote = function () {
            <?php if ($noteid) { ?>
            top.restoreSession();
            window.open('../../patient_file/summary/pnotes_print.php?noteid=' + <?php echo js_url($noteid); ?>, '_blank', 'resizable=1,scrollbars=1,width=600,height=500');
            <?php } ?>

        };

        var SaveNote = function () {
            <?php if ($noteid) { ?>
            top.restoreSession();
            $("#task").val("save");
            $("#new_note").submit();
            <?php } ?>
        };

        var CancelNote = function () {
            top.restoreSession();
            $("#task").val("");
            $("#new_note").submit();
        };

        // This is for callback by the find-patient popup.
        function setpatient(pid, lname, fname, dob) {
            var f = document.getElementById('new_note');
            f.form_patient.value += lname + ', ' + fname + '; ';
            f.reply_to.value += pid + ';';
            <?php if ($noteid) { ?>
            //used when direct messaging service inserts a pnote with indeterminate patient
            //to allow the user to assign the message to a patient.
            top.restoreSession();
            $("#task").val("savePatient");
            $("#new_note").submit();
            <?php } ?>
        }

        // This is for callback by the multi_patients_finder popup.
        function setMultiPatients(patientsList) {
            var f = document.getElementById('new_note');
            f.form_patient.value='';
            f.reply_to.value='';
            $.each(patientsList, function (key, patient) {
                f.form_patient.value += patient.lname + ', ' + patient.fname + '; ';
                f.reply_to.value += patient.pid + ';';
            })

            <?php if ($noteid) { ?>
            //used when direct messaging service inserts a pnote with indeterminate patient
            //to allow the user to assign the message to a patient.
            top.restoreSession();
            $("#task").val("savePatient");
            $("#new_note").submit();
            <?php } ?>
        }

        // This invokes the find-patient popup.
        function sel_patient() {
            dlgopen('../../main/calendar/find_patient_popup.php', '_blank', 625, 400);
        }

        function multi_sel_patient() {
            $('#reply_to').trigger('click');
            var url = '../../main/finder/multi_patients_finder.php'
            // for edit selected list
            if ($('#reply_to').val() !== '') {
                url = url + '?patients=' + $('#reply_to').val() + '&csrf_token_form=<?php echo attr_url(CsrfUtils::collectCsrfToken()); ?>';
            }
            dlgopen(url, '_blank', 625, 400);
        }

        function addtolist(sel) {
            $('#assigned_to').trigger("click");
            var itemtext = document.getElementById('assigned_to_text');
            var item = document.getElementById('assigned_to');
            if (sel.value !== '--') {
                if (item.value) {
                    if (item.value.indexOf(sel.value) === -1) {
                        itemtext.value = itemtext.value + ' ; ' + sel.options[sel.selectedIndex].text;
                        item.value = item.value + ';' + sel.value;
                    }
                } else {
                    itemtext.value = sel.options[sel.selectedIndex].text;
                    item.value = sel.value;
                }
            }
        }

        function SMS_direct() {
            var pid = $("#sms_pid").val();
            var m = $("#sms_mobile").val();
            var allow = $("#sms_allow").val();
            if ((pid === '') || (m === '')) {
                alert('<?php echo xls("MedEx needs a valid mobile number to send SMS messages..."); ?>');
            } else if (allow === 'NO') {
                alert('<?php echo xls("This patient does not allow SMS messaging!"); ?>');
            } else {
                top.restoreSession();
                window.open('messages.php?nomenu=1&go=SMS_bot&pid=' + encodeURIComponent(pid) + '&m=' + encodeURIComponent(m), 'SMS_bot', 'width=370,height=600,resizable=0');
            }
        }

        $(function () {
            const attachment = <?php echo js_escape($_REQUEST['jobId'] ?? '') ?>;
            const attach = <?php echo js_escape($_REQUEST['attach'] ?? '') ?>;
            if (attachment && attach) {
                let el = document.createElement('label').innerText = xl("Attaching Fax Id") + ": " + jsText(attachment);
                document.getElementById('note').after(el);
            }
        });
        function viewFaxAttachment(e, docid) {
            let actionUrl = top.webroot_url + '/interface/modules/custom_modules/oe-module-faxsms/viewFax?type=fax';
            if (e) {
                e.preventDefault();
            }
            try {
                top.restoreSession();
            } catch (error) {
                console.log('Session restore failed!');
            }
            $.post(actionUrl, {
                'type': 'fax',
                'docuri': '',
                'docid': docid,
                'pid': '',
                'download': ''
            }).done(function (json) {
                try {
                    data = JSON.parse(json);
                } catch {
                    data = json;
                }
                const binary = atob(data.base64.replace(/\s/g, ''));
                const len = binary.length;
                const buffer = new ArrayBuffer(len);
                const view = new Uint8Array(buffer);
                for (let i = 0; i < len; i++) {
                    view[i] = binary.charCodeAt(i);
                }
                const blob = new Blob([view], {type: data.mime});
                const dataUrl = URL.createObjectURL(blob);
                let width = window.innerWidth ? window.innerWidth : document.documentElement.clientWidth ?
                    document.documentElement.clientWidth : screen.width;
                let height = window.innerHeight ? window.innerHeight : document.documentElement.clientHeight ?
                    document.documentElement.clientHeight : screen.height;
                height = screen.height ? screen.height * 0.95 : height;
                let left = (width / 4);
                let top = '10';
                let win = window.open(
                    '', '',
                    'toolbar=0, location=0, directories=0, status=0, menubar=0, ' +
                    'scrollbars=0, resizable=0, copyhistory=0, ' +
                    'width=' + width / 1.75 + ', height=' + height + ', top=' + top + ', left=' + left
                );
                win.document.write("<iframe width='100%' height='100%' style='border:none;' src='" + dataUrl + "'></iframe>");
            });
            return false;
        }
    </script>
    <?php
}
?>
</body>
</html>
