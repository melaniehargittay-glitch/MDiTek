<?php

/**
 * Common script for the encounter form (new and view) scripts.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Ranganath Pathak <pathak@scrs1.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("$srcdir/options.inc.php");
require_once("$srcdir/lists.inc.php");

use OpenEMR\Billing\MiscBillingOptions;
use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\ListService;
use OpenEMR\Services\UserService;
use OpenEMR\OeUI\OemrUI;
use OpenEMR\OeUI\RenderFormFieldHelper;

$facilityService = new FacilityService();

if ($GLOBALS['enable_group_therapy']) {
    require_once("$srcdir/group.inc.php");
}

$months = array("01","02","03","04","05","06","07","08","09","10","11","12");
$days   = array("01","02","03","04","05","06","07","08","09","10","11","12","13","14",
    "15","16","17","18","19","20","21","22","23","24","25","26","27","28","29","30","31");
$thisyear = date("Y");
$years = array($thisyear - 1, $thisyear, $thisyear + 1, $thisyear + 2);

$mode     = (!empty($_GET['mode'])) ? $_GET['mode'] : null;
$viewmode = false;
if (!empty($_GET['id'])) {
    $viewmode = true;
}

if ($mode === "followup") {
    $encounter = (!empty($_GET['enc'])) ? (int)$_GET['enc'] : null;
    if (!is_null($encounter)) {
        $viewmode        = true;
        $_REQUEST['id']  = $encounter;
    }
}

if ($viewmode) {
    $id      = (isset($_REQUEST['id'])) ? $_REQUEST['id'] : '';
    $result  = sqlQuery("SELECT * FROM form_encounter WHERE id = ?", array($id));
    $encounter = $result['encounter'];
    $encounter_followup_id = $result['parent_encounter_id'] ?? null;
    if ($encounter_followup_id) {
        $q = "SELECT fe.date as date, fe.encounter as encounter FROM form_encounter AS fe " .
             "JOIN forms AS f ON f.form_id = fe.id AND f.encounter = fe.encounter " .
             "WHERE fe.id = ? AND f.deleted = 0 ";
        $followup_enc  = sqlQuery($q, array($encounter_followup_id));
        $followup_date = date("m/d/Y", strtotime($followup_enc['date']));
        $encounter_followup = $followup_enc['encounter'];
    }
    if ($mode === "followup") {
        $followup_date     = date("m/d/Y", strtotime($result['date']));
        $encounter_followup = $result['encounter'];
        $result['reason'] = '';
        $result['date']   = date('Y-m-d H:i:s');
        $encounterId      = $result['id'];
    }

    if ($result['sensitivity'] && !AclMain::aclCheckCore('sensitivities', $result['sensitivity'])) {
        echo "<body>\n<html>\n";
        echo "<p>" . xlt('You are not authorized to see this encounter.') . "</p>\n";
        echo "</body>\n</html>\n";
        exit();
    }
}

$displayMode = ($viewmode && $mode !== "followup") ? "edit" : "new";

function getDefinedFacility()
{
    global $viewmode, $default_fac_override, $care_team_facility, $user_facility;
    $care_team_facility = null;
    if (!empty($GLOBALS['set_service_facility_encounter'])) {
        $care_team_facility = sqlQuery(
            "SELECT `care_team_facility` FROM `patient_data` WHERE `pid` = ?",
            array($_SESSION['pid'])
        );
    }
    if ($viewmode) {
        global $result;
        $def_facility = $result['facility_id'];
    } elseif (!empty($default_fac_override)) {
        $def_facility = $default_fac_override;
    } elseif (!empty($GLOBALS['set_service_facility_encounter']) && !empty($care_team_facility['care_team_facility'])) {
        $def_facility = $care_team_facility['care_team_facility'];
    } else {
        $def_facility = $user_facility['id'] ?? null;
    }
    return $def_facility;
}

$posCode = '11';

function getFacilityList()
{
    global $viewmode, $facilityService, $posCode;
    $def_facility = getDefinedFacility();
    $facilities   = $facilityService->getAllServiceLocations();
    $results      = [];
    foreach ($facilities as $iter) {
        $posCode   = (($def_facility === $iter['id']) && !$viewmode) ? $iter['pos_code'] : $posCode;
        $results[] = [
            'id'       => $iter['id'],
            'selected' => ($def_facility == $iter['id']) ? true : false,
            'name'     => $iter['name'],
        ];
    }
    return $results;
}

function displayOption($field)
{
    global $displayMode;
    echo RenderFormFieldHelper::shouldDisplayFormField($GLOBALS[$field], $displayMode) ? '' : 'd-none';
}

function sensitivity_compare($a, $b)
{
    return ($a[2] < $b[2]) ? -1 : 1;
}

// Get issues list
$ires = sqlStatement(
    "SELECT id, type, title, begdate FROM lists WHERE pid = ? AND enddate IS NULL ORDER BY type, begdate",
    array($pid)
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php Header::setupHeader(['datetime-picker', 'common']); ?>
    <title><?php echo xlt('Patient Encounter'); ?></title>

    <?php
    $use_validate_js = 1;
    require_once($GLOBALS['srcdir'] . "/validation/validation_script.js.php"); ?>

    <?php include_once("{$GLOBALS['srcdir']}/ajax/facility_ajax_jav.inc.php"); ?>

    <script>
        const mypcc = '' + <?php echo js_escape($GLOBALS['phone_country_code']); ?>;

        function newissue() {
            dlgopen('../../patient_file/summary/add_edit_issue.php', '_blank', 700, 535, '', '', {
                buttons: [{text: <?php echo xlj('Close'); ?>, close: true, style: 'default btn-sm'}]
            });
            return false;
        }

        function refreshIssue(issue, title) {
            var s = document.forms[0]['issues[]'];
            s.options[s.options.length] = new Option(title, issue, true, true);
        }

        <?php
        $collectthis = collectValidationPageRules("/interface/forms/newpatient/common.php");
        if (empty($collectthis)) {
            $collectthis = "undefined";
        } else {
            $collectthis = json_sanitize($collectthis["new_encounter"]["rules"]);
        }
        ?>
        let collectvalidation = <?php echo $collectthis ?: '[]'; ?>;

        $(function () {
            window.saveClicked = function (event) {
                // Submit directly — save.php handles server-side validation.
                // submitme() was silently blocking due to missing validation targets
                // in the redesigned form (removed issues section).
                top.restoreSession();
                document.getElementById('new-encounter-form').submit();
            }

            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
            });
        });

        const isPosEnabled = "" + <?php echo js_escape($GLOBALS['set_pos_code_encounter']); ?>;

        function getPOS() {
            if (!isPosEnabled) return false;
            let facility = document.forms[0].facility_id.value;
            $.ajax({
                url: "./../../../library/ajax/facility_ajax_code.php",
                method: "GET",
                data: { mode: "get_pos", facility_id: facility, csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?> }
            }).done(function (fid) {
                document.forms[0].pos_code.value = JSON.parse(fid);
            });
        }

        function newUserSelected() {
            let provider = document.getElementById('provider_id').value;
            $.ajax({
                url: "./../../../library/ajax/facility_ajax_code.php",
                method: "GET",
                data: { mode: "get_user_data", provider_id: provider, csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?> }
            }).done(function (data) {
                let rtn = JSON.parse(data);
                document.forms[0].facility_id.value = rtn[0];
                if (isPosEnabled) document.forms[0].pos_code.value = rtn[1];
                if (Number(rtn[2]) === 1) document.forms[0]['billing_facility'].value = rtn[0];
            });
        }

        function cancelClickedNew() {
            window.parent.left_nav.loadFrame('ens1', window.name, 'patient_file/history/encounters.php');
            return false;
        }

        function cancelClickedOld() {
            location.href = '<?php echo "$rootdir/patient_file/encounter/forms.php"; ?>';
            return false;
        }
    </script>

    <style>
    /* ── Reset & base ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
        --navy:      #1a1d2e;
        --purple:    #5b21b6;
        --purple-l:  #ede9fe;
        --purple-m:  #7c3aed;
        --border:    #e5e7ef;
        --border2:   #d1d5e8;
        --surface:   #f8f8fc;
        --text:      #1a1d2e;
        --text2:     #4b5563;
        --text3:     #9197b3;
        --green:     #059669;
        --r:         8px;
        --r-sm:      5px;
    }
    body {
        background: #fff !important;
        font-family: 'Segoe UI', 'DM Sans', system-ui, sans-serif;
        font-size: 13px;
        color: var(--text);
    }
    #container_div { background: #fff !important; }
    /* Hide stethoscope bg */
    .oe-opt-head, .page-header, h2.title, .pageheading { display: none !important; }

    /* ── Page wrapper ── */
    .enc-page {
        max-width: 860px;
        margin: 0 auto;
        padding: 20px 20px 40px;
    }

    /* ── Page title ── */
    .enc-page-title {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 22px; padding-bottom: 14px;
        border-bottom: 2px solid var(--purple-l);
    }
    .enc-page-title-left { display: flex; align-items: center; gap: 10px; }
    .enc-title-icon {
        width: 36px; height: 36px; background: var(--purple-l); border-radius: 9px;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .enc-page-title h1 {
        font-size: 16px; font-weight: 800; color: var(--navy); letter-spacing: -.01em;
    }
    .enc-page-title-sub {
        font-size: 11px; color: var(--text3); margin-top: 2px;
        font-family: 'Courier New', monospace;
    }
    .enc-mode-badge {
        font-size: 10px; font-weight: 800; padding: 3px 10px; border-radius: 20px;
        text-transform: uppercase; letter-spacing: .05em;
        background: var(--purple-l); color: var(--purple); border: 1.5px solid #c4b5fd;
    }

    /* ── Section cards ── */
    .enc-section {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: 10px; padding: 18px 20px; margin-bottom: 16px;
    }
    .enc-section-title {
        font-size: 10px; font-weight: 800; text-transform: uppercase;
        letter-spacing: .08em; color: var(--text3);
        margin-bottom: 16px; display: flex; align-items: center; gap: 6px;
    }
    .enc-section-title::before {
        content: ''; display: block; width: 3px; height: 12px;
        background: var(--purple); border-radius: 2px; flex-shrink: 0;
    }

    /* ── Field grid ── */
    .enc-field-row {
        display: grid; gap: 12px; margin-bottom: 14px;
    }
    .enc-field-row.cols-2 { grid-template-columns: repeat(2, 1fr); }
    .enc-field-row.cols-3 { grid-template-columns: repeat(3, 1fr); }
    .enc-field-row.cols-4 { grid-template-columns: repeat(4, 1fr); }
    .enc-field-row:last-child { margin-bottom: 0; }
    .enc-field-row.cols-1 { grid-template-columns: 1fr; }

    .enc-field { display: flex; flex-direction: column; gap: 5px; }
    .enc-field.hidden { display: none; }

    .enc-label {
        font-size: 10.5px; font-weight: 700; color: var(--text2);
        text-transform: uppercase; letter-spacing: .04em;
    }

    /* ── Inputs / selects ── */
    .enc-input, .enc-select, select.form-control, input.form-control, textarea.form-control {
        width: 100% !important;
        min-height: 34px !important;
        padding: 0 10px !important;
        border: 1.5px solid var(--border2) !important;
        border-radius: var(--r-sm) !important;
        font-size: 12px !important;
        font-family: inherit !important;
        color: var(--text) !important;
        background: #fff !important;
        transition: border-color .15s, box-shadow .15s !important;
        outline: none !important;
    }
    textarea.form-control {
        height: auto !important; padding: 8px 10px !important;
        resize: vertical; min-height: 80px !important;
    }
    select.form-control, .enc-select { cursor: pointer !important; }
    .enc-input:focus, .enc-select:focus,
    select.form-control:focus, input.form-control:focus, textarea.form-control:focus {
        border-color: var(--purple) !important;
        box-shadow: 0 0 0 3px rgba(91,33,182,.1) !important;
    }
    input.datepicker.form-control {
        height: 34px !important; padding: 0 10px !important;
        border: 1.5px solid var(--border2) !important;
        border-radius: var(--r-sm) !important;
        font-size: 12px !important;
    }
    input.datepicker.form-control:focus {
        border-color: var(--purple) !important;
        box-shadow: 0 0 0 3px rgba(91,33,182,.1) !important;
    }

    /* ── Footer bar ── */
    .enc-footer {
        display: flex; align-items: center; justify-content: flex-end;
        gap: 8px; padding-top: 16px; border-top: 1px solid var(--border);
        margin-top: 4px;
    }
    .enc-btn-save {
        height: 36px; padding: 0 22px; border-radius: 7px;
        background: var(--purple); color: #fff; border: none;
        font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px; transition: background .15s;
    }
    .enc-btn-save:hover { background: var(--purple-m); }
    .enc-btn-cancel {
        height: 36px; padding: 0 16px; border-radius: 7px;
        background: #fff; color: var(--text2); border: 1.5px solid var(--border2);
        font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px; transition: all .15s;
    }
    .enc-btn-cancel:hover { border-color: var(--text2); color: var(--text); }

    /* ── Sensitivity tooltip ── */
    .enc-info-icon { color: var(--text3); cursor: help; margin-left: 4px; }

    /* ── Responsive ── */
    @media (max-width: 680px) {
        .enc-field-row.cols-2,
        .enc-field-row.cols-3,
        .enc-field-row.cols-4 { grid-template-columns: 1fr; }
    }
    </style>

    <?php
    if ($viewmode) {
        $body_javascript  = '';
        $heading_caption  = xl('Patient Encounter Form');
        $mode_badge_label = xl('Edit Encounter');
    } else {
        $body_javascript  = 'onload="javascript:document.new_encounter.reason.focus();"';
        $heading_caption  = xl('New Encounter Form');
        $mode_badge_label = xl('New Encounter');
    }

    $arrOeUiSettings = array(
        'heading_title'        => $heading_caption,
        'include_patient_name' => true,
        'expandable'           => false,
        'expandable_files'     => array(""),
        'action'               => "",
        'action_title'         => "",
        'action_href'          => "",
        'show_help_icon'       => true,
        'help_file_name'       => "common_help.php"
    );
    $oemr_ui = new OemrUI($arrOeUiSettings);

    $provider_id = $userauthorized ? $_SESSION['authUserID'] : 0;
    if (!$viewmode) {
        $now    = date('Y-m-d');
        $encnow = date('Y-m-d 00:00:00');
        $time   = date("H:i:00");
        $q = "SELECT pc_aid, pc_facility, pc_billing_location, pc_catid, pc_startTime" .
             " FROM openemr_postcalendar_events WHERE pc_pid=? AND pc_eventDate=?" .
             " ORDER BY pc_startTime ASC";
        $q_events = sqlStatement($q, array($pid, $now));
        while ($override = sqlFetchArray($q_events)) {
            $q2    = "SELECT fe.encounter as encounter FROM form_encounter AS fe " .
                     "JOIN forms AS f ON f.form_id = fe.id AND f.encounter = fe.encounter " .
                     "WHERE fe.pid=? AND fe.date=? AND fe.provider_id=? AND f.deleted=0";
            $q_enc = sqlQuery($q2, array($pid, $encnow, $override['pc_aid']));
            if (!empty($override) && is_array($override) && empty($q_enc['encounter'])) {
                $provider_id             = $override['pc_aid'];
                $default_bill_fac_override = $override['pc_billing_location'];
                $default_fac_override    = $override['pc_facility'];
                $default_catid_override  = $override['pc_catid'];
            }
        }
    }
    $user_facility = $facilityService->getFacilityForUser($_SESSION['authUserID']);
    $MBO = new OpenEMR\Billing\MiscBillingOptions();
    ?>
</head>
<body <?php echo $body_javascript; ?>>
    <div id="container_div" style="background:#fff;">

    <form class="mt-0" id="new-encounter-form" method="post"
          action="<?php echo $rootdir ?>/forms/newpatient/save.php"
          name="new_encounter">

        <input type="hidden" id="facility_id" name="facility_id"
               value="<?php echo attr(getDefinedFacility()); ?>" />
        <?php if ($viewmode && $mode !== "followup") { ?>
            <input type="hidden" name="mode" value="update" />
            <input type="hidden" name="id"   value="<?php echo (isset($_GET["id"])) ? attr($_GET["id"]) : '' ?>" />
        <?php } else { ?>
            <input type="hidden" name="mode" value="new" />
        <?php } ?>
        <input type="hidden" name="csrf_token_form"
               value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
        <?php if ($mode === "followup") { ?>
            <input type="hidden" name="parent_enc_id"
                   value="<?php echo attr($encounterId); ?>" />
        <?php } ?>

        <div class="enc-page">

            <!-- ── Page title ── -->
            <div class="enc-page-title">
                <div class="enc-page-title-left">
                    <div class="enc-title-icon">
                        <svg width="18" height="18" fill="none" stroke="var(--purple)" stroke-width="2"
                             viewBox="0 0 24 24">
                            <rect x="3" y="4" width="18" height="18" rx="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8"  y1="2" x2="8"  y2="6"/>
                            <line x1="3"  y1="10" x2="21" y2="10"/>
                        </svg>
                    </div>
                    <div>
                        <h1><?php echo xlt($heading_caption); ?>
                            <?php if (!empty($encounter_followup)): ?>
                                <small style="font-size:11px;font-weight:500;color:var(--text3);margin-left:8px;">
                                    <?php echo xlt("Follow up for") . ": " . text($encounter_followup) . " (" . text($followup_date) . ")"; ?>
                                </small>
                            <?php endif; ?>
                        </h1>
                        <div class="enc-page-title-sub"><?php echo xlt('Patient Encounter'); ?></div>
                    </div>
                </div>
                <span class="enc-mode-badge"><?php echo $mode_badge_label; ?></span>
            </div>

            <!-- ═══════════════════════════════════════════════════
                 SECTION 1: Visit Details
            ════════════════════════════════════════════════════ -->
            <div class="enc-section">
                <div class="enc-section-title"><?php echo xlt('Visit Details'); ?></div>

                <!-- Row 1: Category / Class / Type / Sensitivity -->
                <div class="enc-field-row cols-4">
                    <!-- Visit Category -->
                    <div class="enc-field <?php displayOption('enc_enable_visit_category'); ?>">
                        <label class="enc-label"><?php echo xlt('Visit Category'); ?></label>
                        <select name="pc_catid" id="pc_catid" class="enc-select"
                                <?php echo ($mode === "followup") ? "disabled" : ""; ?>>
                            <option value="_blank">-- <?php echo xlt('Select One'); ?> --</option>
                            <?php
                            $visitSQL = "SELECT pc_catid, pc_catname, pc_cattype
                                         FROM openemr_postcalendar_categories
                                         WHERE pc_active = 1 AND pc_cattype IN (0,3)
                                         AND pc_constant_id != 'no_show'
                                         ORDER BY pc_catname";
                            $visitResult = sqlStatement($visitSQL);
                            $therapyGroupCategories = [];
                            while ($row = sqlFetchArray($visitResult)) {
                                $catId = $row['pc_catid'];
                                $name  = $row['pc_catname'];
                                if ($row['pc_cattype'] == 3) $therapyGroupCategories[] = $catId;
                                if ($catId === "_blank") continue;
                                if ($row['pc_cattype'] == 3 && !$GLOBALS['enable_group_therapy']) continue;
                                $postCalendarCategoryACO = AclMain::fetchPostCalendarCategoryACO($catId);
                                if ($postCalendarCategoryACO) {
                                    $postCalendarCategoryACO = explode('|', $postCalendarCategoryACO);
                                    $authWrite = AclMain::aclCheckCore($postCalendarCategoryACO[0], $postCalendarCategoryACO[1], '', 'write');
                                } else {
                                    $authWrite = true;
                                }
                                if (!$authWrite) continue;
                                $selected = '';
                                if ($viewmode) {
                                    $selected = ($result['pc_catid'] == $catId) ? " selected" : "";
                                } else {
                                    $selected = ($GLOBALS['default_visit_category'] == $catId) ? " selected" : "";
                                }
                                echo "<option value=\"" . attr($catId) . "\"" . $selected . ">" . text(xl_appt_category($name)) . "</option>\n";
                            }
                            ?>
                        </select>
                        <?php if ($mode === "followup") { ?>
                            <input name="pc_catid" value="<?php echo attr($result['pc_catid']); ?>" hidden />
                        <?php } ?>
                    </div>

                    <!-- Class -->
                    <div class="enc-field <?php displayOption('enc_enable_class'); ?>">
                        <label class="enc-label"><?php echo xlt('Class'); ?></label>
                        <?php echo generate_select_list('class_code', '_ActEncounterCode',
                            $viewmode ? $result['class_code'] ?? null : null,
                            'Encounter Class', '', '', 'enc-select'); ?>
                    </div>

                    <!-- Type -->
                    <div class="enc-field <?php displayOption('enc_enable_type'); ?>">
                        <label class="enc-label"><?php echo xlt('Type'); ?></label>
                        <?php
                        $encounter_type_option = $result['encounter_type_code'] ?? '';
                        if (!empty($encounter_type_option)) {
                            $listService = new ListService();
                            $codes = $listService->getOptionsByListName('encounter-types', ['codes' => $result['encounter_type_code']]);
                            if (empty($codes[0])) {
                                $option = $listService->getListOption('encounter-types', $encounter_type_option);
                                $encounter_type_option = $option['option_id'] ?? '';
                            } else {
                                $encounter_type_option = $codes[0]['option_id'];
                            }
                        }
                        echo generate_select_list('encounter_type', 'encounter-types',
                            $viewmode ? $encounter_type_option : null,
                            'Encounter Type', '', '', 'enc-select');
                        ?>
                    </div>

                    <!-- Sensitivity -->
                    <?php
                    $sensitivities = AclExtended::aclGetSensitivities();
                    if ($sensitivities && count($sensitivities)):
                        usort($sensitivities, "sensitivity_compare");
                    ?>
                    <div class="enc-field <?php displayOption('enc_sensitivity_visibility'); ?>">
                        <label class="enc-label">
                            <?php echo xlt('Sensitivity'); ?>
                            <i id="sensitivity-tooltip" class="enc-info-icon" title="">&#9432;</i>
                        </label>
                        <select name="form_sensitivity" id="form_sensitivity" class="enc-select">
                            <?php
                            foreach ($sensitivities as $value) {
                                if (!AclMain::aclCheckCore('sensitivities', $value[1])) continue;
                                $selected = ($viewmode && $result['sensitivity'] == $value[1]) ? 'selected' : '';
                                echo "<option value=\"" . attr($value[1]) . "\" $selected>" . xlt($value[1]) . "</option>\n";
                            }
                            $selected = ($viewmode && !$result['sensitivity']) ? "selected" : "";
                            echo "<option value='' $selected>" . xlt('None{{Sensitivity}}') . "</option>\n";
                            ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Row 2: Date / Onset / Referral / POS / In Collection -->
                <div class="enc-field-row cols-4">
                    <!-- Date of Service -->
                    <div class="enc-field <?php displayOption('enc_service_date'); ?>">
                        <label class="enc-label"><?php echo xlt('Date of Service'); ?></label>
                        <input type="text" class="datepicker form-control" name="form_date" id="form_date"
                               value="<?php
                               if ($viewmode) {
                                   $displayDate = (!empty($result['date']) && strtotime($result['date']) > 0)
                                       ? $result['date'] : date('Y-m-d H:i:00');
                                       echo attr(oeFormatShortDate($displayDate));
                               } else {
                                echo attr(oeFormatShortDate(date('Y-m-d')));
                               }
                               ?>" title="<?php echo xla('Date of service'); ?>" />
                    </div>

                    <!-- Onset / Hospital Date -->
                    <div class="enc-field <?php echo ($GLOBALS['gbl_visit_onset_date'] == 1) ? '' : 'hidden'; ?>">
                        <label class="enc-label">
                            <?php echo xlt('Onset / Hospital Date'); ?>
                            <i id="onset-tooltip" class="enc-info-icon" title="">&#9432;</i>
                        </label>
                        <input type="text" class="datepicker form-control" name="form_onset_date"
                               id="form_onset_date"
                               value="<?php echo $viewmode ? attr(oeFormatShortDate($result['onset_date'] ?? '')) : ''; ?>" />
                    </div>

                    <!-- Referral Source -->
                    <div class="enc-field <?php echo ($GLOBALS['gbl_visit_referral_source'] == 1) ? '' : 'hidden'; ?>">
                        <label class="enc-label"><?php echo xlt('Referral Source'); ?></label>
                        <?php echo generate_select_list('form_referral_source', 'refsource',
                            $viewmode ? $result['referral_source'] ?? null : null,
                            'Referral Source', '', '', 'enc-select'); ?>
                    </div>

                    <!-- POS Code -->
                    <div class="enc-field <?php echo ($GLOBALS['set_pos_code_encounter'] == 1) ? '' : 'hidden'; ?>">
                        <label class="enc-label"><?php echo xlt('POS Code'); ?></label>
                        <select name="pos_code" id="pos_code" class="enc-select">
                            <?php
                            $pc = new POSRef();
                            foreach ($pc->get_pos_ref() as $pos) {
                                $sel = (($pos["code"] == ($result['pos_code'] ?? '') && $viewmode)
                                     || ($pos["code"] == ($posCode ?? '') && !$viewmode)) ? " selected" : "";
                                echo "<option value=\"" . attr($pos["code"]) . "\"$sel>" . text($pos['code']) . ": " . xlt($pos['title']) . "</option>\n";
                            }
                            ?>
                        </select>
                    </div>

                    <!-- In Collection -->
                    <div class="enc-field <?php echo ($GLOBALS['hide_billing_widget'] != 1) ? '' : 'hidden'; ?>">
                        <label class="enc-label"><?php echo xlt('In Collection'); ?></label>
                        <select class="enc-select" name="in_collection" id="in_collection">
                            <option value="1" <?php echo (($result["in_collection"] ?? null) == 1) ? "selected" : ""; ?>><?php echo xlt('Yes'); ?></option>
                            <option value="0" <?php echo (($result["in_collection"] ?? null) == 0) ? "selected" : ""; ?>><?php echo xlt('No'); ?></option>
                        </select>
                    </div>
                </div>

                <!-- Row 3: Provider / Referring Provider / Facility / Billing Facility -->
                <div class="enc-field-row cols-4">
                    <!-- Encounter Provider -->
                    <div class="enc-field">
                        <label class="enc-label"><?php echo xlt('Encounter Provider'); ?></label>
                        <select name="provider_id" id="provider_id" class="enc-select"
                                onChange="newUserSelected()">
                            <?php
                            if ($viewmode) $provider_id = $result['provider_id'];
                            $userService = new UserService();
                            $users = $userService->getActiveUsers();
                            foreach ($users as $activeUser) {
                                $p_id    = (int)$activeUser['id'];
                                $flag_it = "";
                                if ($activeUser['authorized'] != 1) {
                                    if ($p_id === (int)($result['provider_id'] ?? null)) {
                                        $flag_it = " (" . xlt("Non Provider") . ")";
                                    } else {
                                        continue;
                                    }
                                }
                                $sel = ((int)$provider_id === $p_id) ? " selected" : "";
                                echo "<option value='" . attr($p_id) . "'$sel>" .
                                     text($activeUser['lname']) . ' ' . text($activeUser['fname']) .
                                     ' ' . text($activeUser['mname']) . $flag_it . "</option>\n";
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Referring Provider -->
                    <div class="enc-field <?php displayOption('enc_enable_referring_provider'); ?>">
                        <label class="enc-label"><?php echo xlt('Referring Provider'); ?></label>
                        <?php
                        if ($viewmode && !empty($result["referring_provider_id"])) {
                            $MBO->genReferringProviderSelect('referring_provider_id',
                                '-- ' . xl("Please Select") . ' --', $result["referring_provider_id"]);
                        } else {
                            $MBO->genReferringProviderSelect('referring_provider_id',
                                '-- ' . xl("Please Select") . ' --',
                                getPatientData($pid, "ref_providerID")['ref_providerID']);
                        }
                        ?>
                        </select>
                    </div>

                    <!-- Facility -->
                    <div class="enc-field <?php displayOption('enc_enable_facility'); ?>">
                        <label class="enc-label"><?php echo xlt('Facility'); ?></label>
                        <select name="facility_id_sel" id="facility_id_sel" class="enc-select"
                                <?php echo ($mode === "followup") ? "disabled" : ""; ?>>
                            <?php
                            $fac = getFacilityList();
                            foreach ($fac as $f) {
                                $sel = ($f['selected'] == true) ? " selected" : "";
                                echo "<option value=\"" . attr($f['id']) . "\"$sel>" . text($f['name']) . "</option>\n";
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Billing Facility -->
                    <div class="enc-field <?php echo ($GLOBALS['hide_billing_widget'] != 1) ? '' : 'hidden'; ?>">
                        <label class="enc-label"><?php echo xlt('Billing Facility'); ?></label>
                        <?php
                        if (!empty($default_bill_fac_override)) {
                            $default_bill_fac = $default_bill_fac_override;
                        } elseif (!$viewmode && $mode !== "followup") {
                            if ($user_facility['billing_location'] == '1') {
                                $default_bill_fac = $user_facility['id'];
                            } else {
                                $tmp_be = $facilityService->getPrimaryBusinessEntity();
                                $tmp_bl = $facilityService->getPrimaryBillingLocation();
                                $tmp    = !empty($tmp_be['id']) ? $tmp_be['id'] : (!empty($tmp_bl['id']) ? $tmp_bl['id'] : null);
                                $default_bill_fac = !empty($tmp) ? $tmp : getDefinedFacility();
                            }
                        } else {
                            $default_bill_fac = $result['billing_facility'] ?? null;
                        }
                        billing_facility('billing_facility', $default_bill_fac);
                        ?>
                    </div>
                </div>

                <!-- Row 4: Discharge Disposition / Group (conditional) -->
                <?php
                $hasDisch = RenderFormFieldHelper::shouldDisplayFormField($GLOBALS['enc_enable_discharge_disposition'], $displayMode);
                $hasGroup = ($GLOBALS['enable_group_therapy'] == 1);
                if ($hasDisch || $hasGroup):
                ?>
                <div class="enc-field-row cols-2">
                    <!-- Discharge Disposition -->
                    <div class="enc-field <?php displayOption('enc_enable_discharge_disposition'); ?>">
                        <label class="enc-label"><?php echo xlt('Discharge Disposition'); ?></label>
                        <select name="discharge_disposition" id="discharge_disposition" class="enc-select">
                            <option value="_blank">-- <?php echo xlt('Select One'); ?> --</option>
                            <?php
                            $ddList = new ListService();
                            foreach ($ddList->getOptionsByListName('discharge-disposition') as $disp) {
                                $sel = (($result['discharge_disposition'] ?? null) == $disp['option_id']) ? " selected" : "";
                                echo "<option value=\"" . attr($disp['option_id']) . "\"$sel>" . text($disp['title']) . "</option>\n";
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Group Therapy -->
                    <div id="therapy_group_name"
                         class="enc-field <?php echo $hasGroup ? '' : 'hidden'; ?>"
                         style="display:<?php echo $hasGroup ? 'flex' : 'none'; ?>;">
                        <label class="enc-label"><?php echo xlt('Group Name'); ?></label>
                        <input type="text" name="form_group" class="form-control" id="form_group"
                               placeholder="<?php echo xla('Click to select group'); ?>"
                               onclick="sel_group()" readonly
                               value="<?php echo $viewmode && in_array($result['pc_catid'] ?? '', $therapyGroupCategories)
                                   ? attr(getGroup($result['form_gid'] ?? '')['group_name'] ?? '') : ''; ?>" />
                        <input type="hidden" name="form_gid"
                               value="<?php echo $viewmode && in_array($result['pc_catid'] ?? '', $therapyGroupCategories)
                                   ? attr($result['form_gid'] ?? '') : ''; ?>" />
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- ═══════════════════════════════════════════════════
                 SECTION 2: Reason for Visit
            ════════════════════════════════════════════════════ -->
            <div class="enc-section">
                <div class="enc-section-title"><?php echo xlt('Reason for Visit'); ?></div>
                <div class="enc-field-row cols-1">
                    <div class="enc-field">
                        <textarea name="reason" id="reason" class="form-control" rows="4"
                                  style="min-height:90px;"><?php echo $viewmode ? text($result['reason']) : ''; ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ── Footer ── -->
            <div class="enc-footer">
                <?php $link_submit = ($viewmode || empty($_GET['autoloaded'])) ? '' : 'link_submit'; ?>
                <button type="button" class="enc-btn-cancel <?php echo $link_submit; ?>"
                        onClick="return <?php echo $viewmode ? 'cancelClickedOld' : 'cancelClickedNew'; ?>();">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"
                         viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php echo xlt('Cancel'); ?>
                </button>
                <button type="button" class="enc-btn-save"
                        onclick="top.restoreSession(); saveClicked(event);">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5"
                         viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php echo xlt('Save'); ?>
                </button>
            </div>

        </div><!-- /enc-page -->
    </form>
    </div><!--End of container div-->

    <?php $oemr_ui->oeBelowContainerDiv(); ?>

<script>
    const fac_id_sel = document.getElementById("facility_id_sel");
    if (fac_id_sel) {
        fac_id_sel.addEventListener("change", () => {
            let fac_id = document.getElementById("facility_id");
            fac_id.value = fac_id_sel[fac_id_sel.selectedIndex].value;
            getPOS();
        });
    }

    <?php if (!$viewmode) { ?>
    function duplicateVisit(enc, datestr) {
        if (!confirm(<?php echo xlj("A visit already exists for this patient today. Click Cancel to open it, or OK to proceed with creating a new one."); ?>)) {
            top.restoreSession();
            parent.left_nav.setEncounter(datestr, enc, window.name);
            parent.left_nav.loadFrame('enc2', window.name, 'patient_file/encounter/encounter_top.php?set_encounter=' + enc + '&pid=<?php echo urlencode($pid); ?>');
            return;
        }
    }
    <?php
    $erow = sqlQuery(
        "SELECT fe.encounter, fe.date FROM form_encounter AS fe, forms AS f " .
        "WHERE fe.pid = ? AND fe.date >= ? AND fe.date <= ? " .
        "AND f.formdir = 'newpatient' AND f.form_id = fe.id AND f.deleted = 0 " .
        "ORDER BY fe.encounter DESC LIMIT 1",
        array($pid, date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'))
    );
    if (!empty($erow['encounter'])) {
        echo "duplicateVisit(" . js_escape($erow['encounter']) . ", " .
             js_escape(oeFormatShortDate(substr($erow['date'], 0, 10))) . ");\n";
    }
    ?>
    <?php } ?>

    <?php if ($GLOBALS['enable_group_therapy']) { ?>
    let groupCategories = <?php echo json_encode($therapyGroupCategories); ?>;
    $('#pc_catid').on('change', function () {
        if (groupCategories.indexOf($(this).val()) > -1) {
            $('#therapy_group_name').show();
        } else {
            $('#therapy_group_name').hide();
        }
    });
    function sel_group() {
        top.restoreSession();
        const url = '<?php echo $GLOBALS['webroot']; ?>/interface/main/calendar/find_group_popup.php';
        dlgopen(url, '_blank', 500, 400, '', '', {
            buttons: [{text: <?php echo xlj('Close'); ?>, close: true, style: 'default btn-sm'}]
        });
    }
    function setgroup(gid, name) {
        var f = document.forms[0];
        f.form_group.value = name;
        f.form_gid.value   = gid;
    }
    <?php if ($viewmode && in_array($result['pc_catid'] ?? '', $therapyGroupCategories)) { ?>
    $('#therapy_group_name').show();
    <?php } ?>
    <?php } ?>

    $(function () {
        $('#sensitivity-tooltip').attr({
            "title": <?php echo xlj('If set as high will restrict visibility of encounter to authorized users only.'); ?>
        });
        $('#onset-tooltip').attr({
            "title": <?php echo xlj('Hospital date needed for successful billing of hospital encounters.'); ?>
        });
    });
</script>

<?php if (!empty($GLOBALS['text_templates_enabled'])) { ?>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/CustomTemplateLoader.js"></script>
<?php } ?>
</body>
</html>
