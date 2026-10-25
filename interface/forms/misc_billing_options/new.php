<?php

/*
 * This program creates the misc_billing_form
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Terry Hill <terry@lilysystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (C) 2007 Bo Huynh
 * @copyright Copyright (C) 2016 Terry Hill <terry@lillysystems.com>
 * @copyright Copyright (C) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (C) 2017-2021 Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (C) 2018 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019 Ranganath Pathak <pathak@scrs1.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/user.inc.php");
require_once("$srcdir/pid.inc.php");
require_once("$srcdir/encounter.inc.php");

use OpenEMR\Billing\MiscBillingOptions;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

if (isset($_REQUEST['isBilling'])) {
    $pid = $_REQUEST['pid'];
    SessionUtil::setSession('billpid', $pid);

    if ($pid != $_SESSION["pid"]) {
        setpid($pid);
    }

    $encounter = $_REQUEST['enc'];
    SessionUtil::setSession('billencounter', $encounter);

    if ($encounter != $_SESSION["encounter"]) {
        setencounter($encounter);
    }
} elseif (isset($_SESSION['billencounter'])) {
    SessionUtil::unsetSession(['billpid', 'billencounter']);
}

$MBO = new OpenEMR\Billing\MiscBillingOptions();

if (!$encounter) { // comes from globals.php
    die(xlt("Internal error: we do not seem to be in an encounter!"));
}
//only one misc billing form per encounter so grab if exists
$formid = (int) (isset($_GET['id']) ? $_GET['id'] : 0);
if (empty($formid)) {
    $mboquery = sqlquery("SELECT `fmbo`.`id` FROM `form_misc_billing_options` AS `fmbo`
                          INNER JOIN `forms` ON (`fmbo`.`id` = `forms`.`form_id`) WHERE
                          `forms`.`deleted` = 0 AND `forms`.`formdir` = 'misc_billing_options' AND
                          `forms`.`encounter` = ? ORDER BY `fmbo`.`id` DESC", array($encounter));
    if (!empty($mboquery['id'])) {
        $formid = (int) $mboquery['id'];
    }
}
$obj = $formid ? formFetch("form_misc_billing_options", $formid) : array();
// 🔥 Pull encounter-level defaults
$encounter_defaults = sqlQuery(
    "SELECT injury_date, initial_treatment_date
     FROM form_encounter
     WHERE encounter = ?",
    [$encounter]
);

// 🔁 Apply fallback ONLY if MBO is empty
if (empty($obj['onset_date']) && !empty($encounter_defaults['injury_date'])) {
    $obj['onset_date'] = $encounter_defaults['injury_date'];
}

// Box 15 = initial treatment
if (empty($obj['date_initial_treatment']) && !empty($encounter_defaults['initial_treatment_date'])) {
    $obj['date_initial_treatment'] = $encounter_defaults['initial_treatment_date'];
}

// 🔥 Force qualifier to Initial Treatment (only if not already set)
if (empty($obj['box_15_date_qual'])) {
    $obj['box_15_date_qual'] = '454'; // Initial Treatment
}

// 🚫 Prevent replacement claim from sticking across encounters
if (
    !empty($obj['replacement_claim']) &&
    (int)$obj['replacement_claim'] !== 0
) {
    $hasICN  = !empty(trim($obj['icn_resubmission_number'] ?? ''));
    $hasMed  = !empty(trim($obj['medicaid_original_reference'] ?? ''));

    if (!$hasICN && !$hasMed) {
        $obj['replacement_claim'] = 0;
    }
}



?>
<html>
<head>
    <?php Header::setupHeader(['datetime-picker', 'opener']); ?>
    <title><?php echo xlt('Miscellaneous Billing Options for HCFA-1500'); ?></title>
    <?php
    $arrOeUiSettings = array(
        'heading_title' => xl('Miscellaneous Billing Options for HCFA-1500'),
        'include_patient_name' => true,
        'expandable' => false,
        'expandable_files' => array(""),
        'action' => "",
        'action_title' => "",
        'action_href' => "",
        'show_help_icon' => true,
        'help_file_name' => "cms_1500_help.php"
    );
    $oemr_ui = new OemrUI($arrOeUiSettings);
    ?>
    <style>
    /* ── Reset & base ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        background: #ffffff !important;
        font-family: 'DM Sans', 'Segoe UI', sans-serif;
        color: #1a1d2e;
        font-size: 13px;
    }
    /* Hide the default OemrUI chrome that shows stethoscope bg */
    #container_div { background: #fff !important; }
    .container-fluid, .oe-opt-head { background: #fff !important; }

    /* ── CSS Variables ── */
    :root {
        --purple:    #5b21b6;
        --purple-l:  #ede9fe;
        --purple-mid:#7c3aed;
        --border:    #e5e7ef;
        --border2:   #d1d5e8;
        --surface:   #f8f8fc;
        --text:      #1a1d2e;
        --text2:     #5a5f7a;
        --text3:     #9197b3;
        --green:     #059669;
        --r:         8px;
        --r-sm:      5px;
        --shadow:    0 1px 3px rgba(30,10,60,.08), 0 4px 16px rgba(30,10,60,.04);
    }

    /* ── Page wrapper ── */
    .mbo-page {
        max-width: 720px;
        margin: 0 auto;
        padding: 24px 20px 40px;
        background: #fff;
    }

    /* ── Page title ── */
    .mbo-title {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 24px; padding-bottom: 16px;
        border-bottom: 2px solid var(--purple-l);
    }
    .mbo-title-text {
        font-size: 15px; font-weight: 800; color: var(--purple);
        letter-spacing: -.01em;
    }
    .mbo-title-sub {
        font-size: 11px; color: var(--text3); font-weight: 500; margin-top: 2px;
    }
    .mbo-help-btn {
        width: 28px; height: 28px; border-radius: 50%;
        background: var(--purple-l); border: none;
        color: var(--purple); font-weight: 700; font-size: 13px;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }

    /* ── Section cards ── */
    .mbo-section {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 18px 20px;
        margin-bottom: 16px;
    }
    .mbo-section-title {
        font-size: 10px; font-weight: 800; text-transform: uppercase;
        letter-spacing: .08em; color: var(--text3);
        margin-bottom: 14px; display: flex; align-items: center; gap: 6px;
    }
    .mbo-section-title::before {
        content: ''; display: block; width: 3px; height: 12px;
        background: var(--purple); border-radius: 2px; flex-shrink: 0;
    }

    /* ── Field rows ── */
    .mbo-field-row {
        display: flex; align-items: flex-start; gap: 16px;
        margin-bottom: 14px; flex-wrap: wrap;
    }
    .mbo-field-row:last-child { margin-bottom: 0; }
    .mbo-field { flex: 1; min-width: 140px; display: flex; flex-direction: column; gap: 5px; }
    .mbo-field.narrow { flex: 0 0 160px; }
    .mbo-field.wide   { flex: 2; }

    /* ── Labels ── */
    .mbo-label {
        font-size: 10.5px; font-weight: 700; color: var(--text2);
        text-transform: uppercase; letter-spacing: .04em;
        display: flex; align-items: center; gap: 6px; white-space: nowrap;
    }

    /* ── Checkbox toggle ── */
    .mbo-check-wrap {
        display: flex; align-items: center; gap: 10px; height: 36px;
    }
    .mbo-check-wrap input[type="checkbox"] {
        width: 18px; height: 18px; border-radius: 4px; cursor: pointer;
        accent-color: var(--purple);
    }
    .mbo-check-wrap .mbo-check-lbl {
        font-size: 12px; color: var(--text); font-weight: 500;
    }

    /* ── Text inputs & selects ── */
    .mbo-input, .mbo-select, select.form-control {
        width: 100% !important;
        height: 36px !important;
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
    .mbo-input:focus, .mbo-select:focus, select.form-control:focus {
        border-color: var(--purple) !important;
        box-shadow: 0 0 0 3px rgba(91,33,182,.1) !important;
    }
    input.datepicker.form-control {
        height: 36px !important;
        padding: 0 10px !important;
        border: 1.5px solid var(--border2) !important;
        border-radius: var(--r-sm) !important;
        font-size: 12px !important;
        font-family: inherit !important;
        color: var(--text) !important;
        background: #fff !important;
        transition: border-color .15s !important;
        outline: none !important;
    }
    input.datepicker.form-control:focus {
        border-color: var(--purple) !important;
        box-shadow: 0 0 0 3px rgba(91,33,182,.1) !important;
    }

    /* ── Radio toggle buttons ── */
    .mbo-radio-group {
        display: flex; gap: 6px; flex-wrap: wrap; align-items: center;
    }
    .mbo-radio-group input[type="radio"] { display: none; }
    .mbo-radio-group label.btn {
        height: 32px; padding: 0 14px;
        border: 1.5px solid var(--border2) !important;
        border-radius: 20px !important;
        font-size: 11px !important; font-weight: 600 !important;
        color: var(--text2) !important;
        background: #fff !important;
        cursor: pointer; display: inline-flex; align-items: center;
        transition: all .15s !important;
        line-height: 1;
    }
    .mbo-radio-group input[type="radio"]:checked + label.btn {
        background: var(--purple) !important;
        border-color: var(--purple) !important;
        color: #fff !important;
    }
    .mbo-radio-group label.btn:hover {
        border-color: var(--purple) !important;
        color: var(--purple) !important;
    }

    /* ── Textarea ── */
    .mbo-textarea {
        width: 100%; padding: 10px 12px;
        border: 1.5px solid var(--border2); border-radius: var(--r-sm);
        font-size: 12px; font-family: inherit; color: var(--text);
        background: #fff; resize: vertical; min-height: 80px;
        transition: border-color .15s; outline: none;
    }
    .mbo-textarea:focus { border-color: var(--purple); box-shadow: 0 0 0 3px rgba(91,33,182,.1); }

    /* ── Hint text ── */
    .mbo-hint {
        font-size: 10.5px; color: var(--text3); font-style: italic; margin-bottom: 14px;
    }

    /* ── Save/Cancel bar ── */
    .mbo-footer {
        display: flex; align-items: center; justify-content: flex-end;
        gap: 8px; padding-top: 16px; border-top: 1px solid var(--border);
        margin-top: 4px;
    }
    .mbo-btn-save {
        height: 36px; padding: 0 20px; border-radius: 7px;
        background: var(--purple); color: #fff; border: none;
        font-size: 12px; font-weight: 700; cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        transition: background .15s;
    }
    .mbo-btn-save:hover { background: var(--purple-mid); }
    .mbo-btn-cancel {
        height: 36px; padding: 0 16px; border-radius: 7px;
        background: #fff; color: var(--text2);
        border: 1.5px solid var(--border2);
        font-size: 12px; font-weight: 600; cursor: pointer; font-family: inherit;
        display: inline-flex; align-items: center; gap: 6px;
        transition: all .15s;
    }
    .mbo-btn-cancel:hover { border-color: var(--text2); color: var(--text); }

    /* ── Hide original OemrUI heading (we render our own) ── */
    .oe-opt-head, #oe-opt-head, .page-header, h2.title, .pageheading { display: none !important; }
    </style>
</head>
<body>
    <div id="container_div" style="background:#fff;">
    <form method=post <?php echo "name='my_form' action='$rootdir/forms/misc_billing_options/save.php?id=" . attr_url($formid) . "'\n"; ?>>
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

        <div class="mbo-page">

            <!-- Page Title -->
            <div class="mbo-title">
                <div>
                    <div class="mbo-title-text"><?php echo xlt('Miscellaneous Billing Options'); ?> &mdash; HCFA-1500</div>
                    <div class="mbo-title-sub"><?php echo xlt('Select Options for Current Encounter'); ?></div>
                </div>
                <button type="button" class="mbo-help-btn" onclick="window.open('<?php echo $GLOBALS['webroot']; ?>/interface/forms/misc_billing_options/cms_1500_help.php','help','width=900,height=700,scrollbars=1')" title="<?php echo xla('Help'); ?>">?</button>
            </div>

            <p class="mbo-hint"><?php echo xlt('Checked box = yes, empty = no'); ?></p>

            <!-- ── Section 1: Accident / Condition ── -->
            <div class="mbo-section">
                <div class="mbo-section-title"><?php echo xlt('Box 10 — Condition Related To'); ?></div>

                <div class="mbo-field-row">
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 10A. Employment Related'); ?></span>
                        <div class="mbo-check-wrap">
                            <input type="checkbox" name="employment_related" id="box10a" value="1"
                                <?php if (!empty($obj['employment_related']) && ($obj['employment_related'] == "1")) echo "checked"; ?> />
                            <span class="mbo-check-lbl"><?php echo xlt('Yes'); ?></span>
                        </div>
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 10B. Auto Accident'); ?></span>
                        <div class="mbo-check-wrap">
                            <input type="checkbox" name="auto_accident" id="box10b" value="1"
                                <?php if (!empty($obj['auto_accident']) && ($obj['auto_accident'] == "1")) echo "checked"; ?> />
                            <span class="mbo-check-lbl"><?php echo xlt('Yes'); ?></span>
                        </div>
                    </div>
                    <div class="mbo-field narrow">
                        <span class="mbo-label"><?php echo xlt('State'); ?></span>
                        <input type="text" class="mbo-input form-control" name="accident_state" id="box10bstate" size="2"
                            value="<?php echo attr($obj['accident_state'] ?? ''); ?>" />
                    </div>
                </div>

                <div class="mbo-field-row">
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 10C. Other Accident'); ?></span>
                        <div class="mbo-check-wrap">
                            <input type="checkbox" name="other_accident" id="box10c" value="1"
                                <?php if (!empty($obj['other_accident']) && ($obj['other_accident'] == "1")) echo "checked"; ?> />
                            <span class="mbo-check-lbl"><?php echo xlt('Yes'); ?></span>
                        </div>
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 10D. EPSDT Referral Code'); ?></span>
                        <input type="text" class="mbo-input form-control" name="medicaid_referral_code" id="box10d"
                            value="<?php echo attr($obj['medicaid_referral_code'] ?? ''); ?>" />
                    </div>
                    <div class="mbo-field narrow">
                        <span class="mbo-label"><?php echo xlt('EPSDT Flag'); ?></span>
                        <div class="mbo-check-wrap">
                            <input type="checkbox" name="epsdt_flag" id="box10depsdt" value="1"
                                <?php if (!empty($obj['epsdt_flag']) && ($obj['epsdt_flag'] == "1")) echo "checked"; ?> />
                            <span class="mbo-check-lbl"><?php echo xlt('Yes'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section 2: Dates ── -->
            <div class="mbo-section">
                <div class="mbo-section-title"><?php echo xlt('Dates'); ?></div>

                <div class="mbo-field-row">
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 14. Onset Date'); ?></span>
                        <?php $onset_date = $obj['onset_date'] ?? null; ?>
                        <input type="text" size="10" class="datepicker form-control" name="onset_date" id="onset_date"
                            value="<?php echo attr($onset_date); ?>" title="<?php echo xla('yyyy-mm-dd'); ?>" />
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Qualifier'); ?></span>
                        <?php echo $MBO->generateDateQualifierSelect("box_14_date_qual", $MBO->box_14_qualifier_options, $obj); ?>
                    </div>
                </div>

                <div class="mbo-field-row">
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 15. Other Date'); ?></span>
                        <?php $date_initial_treatment = $obj['date_initial_treatment'] ?? null; ?>
                        <input type="text" size="10" class="datepicker form-control" name="date_initial_treatment"
                            id="date_initial_treatment" value="<?php echo attr($date_initial_treatment); ?>"
                            title="<?php echo xla('yyyy-mm-dd'); ?>" />
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Qualifier'); ?></span>
                        <?php $MBO->generateDateQualifierSelect("box_15_date_qual", $MBO->box_15_qualifier_options, $obj); ?>
                    </div>
                </div>

                <div class="mbo-field-row">
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 16. Unable to Work — From'); ?></span>
                        <?php $off_work_from = $obj['off_work_from'] ?? null; ?>
                        <input type="text" class="datepicker form-control" name="off_work_from" id="off_work_from"
                            value="<?php echo attr($off_work_from); ?>" title="<?php echo xla('yyyy-mm-dd'); ?>" />
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 16. Unable to Work — To'); ?></span>
                        <?php $off_work_to = $obj['off_work_to'] ?? null; ?>
                        <input type="text" class="datepicker form-control" name="off_work_to" id="off_work_to"
                            value="<?php echo attr($off_work_to); ?>" title="<?php echo xla('yyyy-mm-dd'); ?>" />
                    </div>
                </div>

                <div class="mbo-field-row">
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 18. Hospitalization From'); ?></span>
                        <?php $hospitalization_date_from = $obj['hospitalization_date_from'] ?? null; ?>
                        <input type="text" class="datepicker form-control" name="hospitalization_date_from"
                            id="hospitalization_date_from" value="<?php echo attr($hospitalization_date_from); ?>"
                            title="<?php echo xla('yyyy-mm-dd'); ?>" />
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 18. Hospitalization To'); ?></span>
                        <?php $hospitalization_date_to = $obj['hospitalization_date_to'] ?? null; ?>
                        <input type="text" class="datepicker form-control" name="hospitalization_date_to"
                            id="hospitalization_date_to" value="<?php echo attr($hospitalization_date_to); ?>"
                            title="<?php echo xla('yyyy-mm-dd'); ?>" />
                    </div>
                </div>
            </div>

            <!-- ── Section 3: Provider ── -->
            <div class="mbo-section">
                <div class="mbo-section-title"><?php echo xlt('Provider'); ?></div>

                <div class="mbo-field-row">
                    <div class="mbo-field wide">
                        <span class="mbo-label"><?php echo xlt('Box 17. Referring Provider'); ?></span>
                        <?php
                        if (!empty($obj['provider_id'])) {
                            $MBO->genReferringProviderSelect('provider_id', '-- ' . xl("Please Select") . ' --', $obj['provider_id']);
                        } else {
                            $MBO->genReferringProviderSelect('provider_id', '-- ' . xl("Please Select") . ' --', getPatientData($pid, "ref_providerID")['ref_providerID']);
                        }
                        ?>
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 17. Provider Qualifier'); ?></span>
                        <?php echo generate_select_list('provider_qualifier_code', 'provider_qualifier_code', ($obj['provider_qualifier_code'] ?? null), 'Provider Qualifier Code'); ?>
                    </div>
                </div>
            </div>

            <!-- ── Section 4: Lab / Billing ── -->
            <div class="mbo-section">
                <div class="mbo-section-title"><?php echo xlt('Lab & Billing'); ?></div>

                <div class="mbo-field-row">
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 20. Outside Lab Used?'); ?></span>
                        <div class="mbo-check-wrap">
                            <input type="checkbox" name="outside_lab" id="outside_lab" value="1"
                                <?php if (!empty($obj['outside_lab']) && ($obj['outside_lab'] == "1")) echo "checked"; ?> />
                            <span class="mbo-check-lbl"><?php echo xlt('Yes'); ?></span>
                        </div>
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Amount Charged'); ?></span>
                        <input type="text" size="7" class="mbo-input form-control" name="lab_amount" id="lab_amount"
                            value="<?php echo attr($obj['lab_amount'] ?? ''); ?>" />
                    </div>
                </div>

                <div class="mbo-field-row">
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Box 22. Medicaid Resubmission Code (ICD-10)'); ?></span>
                        <input type="text" class="mbo-input form-control" name="medicaid_resubmission_code" id="medicaid_resubmission_code"
                            value="<?php echo attr($obj['medicaid_resubmission_code'] ?? ''); ?>" />
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('Medicaid Original Reference No.'); ?></span>
                        <input type="text" class="mbo-input form-control" name="medicaid_original_reference" id="medicaid_original_reference"
                            value="<?php echo attr($obj['medicaid_original_reference'] ?? ''); ?>" />
                    </div>
                </div>

                <div class="mbo-field-row">
                    <div class="mbo-field wide">
                        <span class="mbo-label"><?php echo xlt('Box 23. Prior Authorization No.'); ?></span>
                        <input type="text" class="mbo-input form-control" name="prior_auth_number" id="prior_auth_number"
                            value="<?php echo attr($obj['prior_auth_number'] ?? ''); ?>" />
                    </div>
                    <div class="mbo-field">
                        <span class="mbo-label"><?php echo xlt('X12 ICN Resubmission No.'); ?></span>
                        <input type="text" class="mbo-input form-control" name="icn_resubmission_number" id="icn_resubmission_number"
                            value="<?php echo attr($obj['icn_resubmission_number'] ?? ''); ?>" />
                    </div>
                </div>

                <div class="mbo-field-row">
                    <div class="mbo-field wide">
                        <span class="mbo-label"><?php echo xlt('Claim Type'); ?></span>
                        <div class="mbo-radio-group">
                            <input type="radio" class="btn-check" name="replacement_claim" id="replacement_claim" autocomplete="off" value="1"
                                <?php if (!empty($obj['replacement_claim']) && ($obj['replacement_claim'] == "1")) echo "checked"; ?> />
                            <label class="btn btn-secondary" for="replacement_claim"><?php echo xlt('X12 Replacement Claim'); ?></label>

                            <input type="radio" class="btn-check" name="replacement_claim" id="void_claim" autocomplete="off" value="2"
                                <?php if (!empty($obj['replacement_claim']) && ($obj['replacement_claim'] == "2")) echo "checked"; ?> />
                            <label class="btn btn-secondary" for="void_claim"><?php echo xlt('Void Claim'); ?></label>

                            <input type="radio" class="btn-check" name="replacement_claim" id="new_claim" autocomplete="off" value="0"
                                <?php if (!isset($obj['replacement_claim']) || (int)$obj['replacement_claim'] === 0) echo "checked"; ?> />
                            <label class="btn btn-secondary" for="new_claim"><?php echo xlt('New Claim'); ?></label>
                        </div>
                    </div>
                </div>
            </div>

            

            <!-- ── Section 5: Additional Notes ── -->
            <div class="mbo-section">
                <div class="mbo-section-title"><?php echo xlt('Additional Notes'); ?></div>
                <textarea name="comments" id="comments" class="mbo-textarea" rows="3"><?php echo text($obj['comments'] ?? ''); ?></textarea>
            </div>

            <!-- ── Footer ── -->
            <div class="mbo-footer">
                <button type="button" class="dontsave mbo-btn-cancel">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php echo xlt('Cancel'); ?>
                </button>
                <button type="submit" class="mbo-btn-save save">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php echo xlt('Save'); ?>
                </button>
            </div>

        </div><!-- /mbo-page -->
    </form>
    </div><!--End of container div-->
<script>
    // jQuery stuff to make the page a little easier to use
    $(function () {
        $(".dontsave").click(function () {
            <?php if (isset($_REQUEST['isBilling'])) { ?>
                dlgclose();
            <?php } else { ?>
                parent.closeTab(window.name, false);
            <?php } ?>
        });

        $('.datepicker').datetimepicker({
            <?php $datetimepicker_timepicker = false; ?>
            <?php $datetimepicker_showseconds = false; ?>
            <?php $datetimepicker_formatInput = false; ?>
            <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
            <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
        });
    });

    $(function () {
        $('select').addClass("form-control");
    });
    $('form').on('submit', function(e) {
    const type = $('input[name="replacement_claim"]:checked').val();
    const icn  = $('#icn_resubmission_number').val().trim();
    const med  = $('#medicaid_original_reference').val().trim();

    if ((type == '1' || type == '2') && !icn && !med) {
        alert('ICN or Medicaid Reference is required for Replacement or Void claims.');
        e.preventDefault();
    }
});
$('#icn_resubmission_number, #medicaid_original_reference').on('input', function() {
    const icn = $('#icn_resubmission_number').val().trim();
    const med = $('#medicaid_original_reference').val().trim();

    if (!icn && !med) {
        $('#new_claim').prop('checked', true);
    }
});
</script>
</body>
</html>
