<?php

/**
 * Questionnaire Assessment Encounters Template
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2022 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../../src/Common/Forms/CoreFormToPortalUtility.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Forms\CoreFormToPortalUtility;
use OpenEMR\Core\Header;
use OpenEMR\Services\QuestionnaireResponseService;
use OpenEMR\Services\QuestionnaireService;

// block of code to securely support use by the patient portal
$isPortal = CoreFormToPortalUtility::isPatientPortalSession($_GET);
if ($isPortal) {
    $ignoreAuth_onsite_portal = true;
}
$patientPortalOther = CoreFormToPortalUtility::isPatientPortalOther($_GET);

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/user.inc.php");

$service = new QuestionnaireService();
$responseService = new QuestionnaireResponseService();
$questionnaire_form = $_GET['questionnaire_form'] ?? null;
$repository_item = $_POST['select_item'] ?? null;

if ($isPortal) {
    $questionnaire_form = $_GET['formname'] ?? null;
}
// for new questionnaires user must be admin. leave strict conditional.
$isAdmin = AclMain::aclCheckCore('admin', 'forms');
$is_authorized = $isAdmin || ($questionnaire_form !== 'New Questionnaire' && $_GET['formname'] ?? null === 'questionnaire_assessments');

// ── Ensure native intake columns exist (runs once per installation, safe to repeat) ──
$_intake_cols = [
    'intake_chief_complaint'  => "TEXT",
    'intake_onset_date'       => "DATE",
    'intake_duration'         => "VARCHAR(64)",
    'intake_cause'            => "VARCHAR(128)",
    'intake_pattern'          => "VARCHAR(64)",
    'intake_aggravating'      => "TEXT",
    'intake_relieving'        => "TEXT",
    'intake_conditions'       => "TEXT",
    'intake_surgeries'        => "TEXT",
    'intake_medications'      => "TEXT",
    'intake_allergies'        => "VARCHAR(512)",
    'intake_occupation'       => "VARCHAR(128)",
    'intake_smoking'          => "VARCHAR(32)",
    'intake_exercise'         => "VARCHAR(8)",
    'intake_prev_treatment'   => "TEXT",
    'intake_body_regions'     => "TEXT",
    'intake_pain_level'       => "TINYINT",
    'intake_pain_best'        => "TINYINT",
    'intake_pain_worst'       => "TINYINT",
    'intake_pain_quality'     => "TEXT",
    'intake_radiation'        => "VARCHAR(32)",
    'intake_sys_notes'        => "TEXT",
    'intake_func_standing'    => "VARCHAR(32)",
    'intake_func_sitting'     => "VARCHAR(32)",
    'intake_func_walking'     => "VARCHAR(32)",
    'intake_func_stairs'      => "VARCHAR(32)",
    'intake_func_bending'     => "VARCHAR(32)",
    'intake_func_lifting'     => "VARCHAR(32)",
    'intake_func_dressing'    => "VARCHAR(32)",
    'intake_func_driving'     => "VARCHAR(32)",
    'intake_func_sleep'       => "VARCHAR(32)",
    'intake_func_work'        => "VARCHAR(32)",
    'intake_goals'            => "TEXT",
    'intake_timeline'         => "VARCHAR(64)",
    'intake_provider_notes'   => "TEXT",
    'intake_consent'          => "VARCHAR(8)",
    'intake_completed_by'     => "VARCHAR(32)",
    // Systems review (one column per system)
    'intake_sys_musculoskeletal' => "VARCHAR(4)",
    'intake_sys_neurological'    => "VARCHAR(4)",
    'intake_sys_cardiovascular'  => "VARCHAR(4)",
    'intake_sys_respiratory'     => "VARCHAR(4)",
    'intake_sys_gastrointestinal'=> "VARCHAR(4)",
    'intake_sys_genitourinary'   => "VARCHAR(4)",
    'intake_sys_integumentary'   => "VARCHAR(4)",
    'intake_sys_endocrine'       => "VARCHAR(4)",
    'intake_sys_psychological'   => "VARCHAR(4)",
    'intake_sys_ent'             => "VARCHAR(4)",
    'intake_sys_hematologic'     => "VARCHAR(4)",
    'intake_sys_immunologic'     => "VARCHAR(4)",
];
$_existing_cols = [];
$_col_res = sqlStatementNoLog("SHOW COLUMNS FROM form_questionnaire_assessments");
while ($_col = sqlFetchArray($_col_res)) { $_existing_cols[] = $_col['Field']; }
foreach ($_intake_cols as $_col_name => $_col_type) {
    if (!in_array($_col_name, $_existing_cols)) {
        sqlStatementNoLog("ALTER TABLE form_questionnaire_assessments ADD COLUMN `$_col_name` $_col_type NULL DEFAULT NULL");
    }
}
unset($_intake_cols, $_col_res, $_col, $_existing_cols, $_col_name, $_col_type);

// ── Clean old lform_response rows that still have _nativeIntakeData ──────────
// These cause "Nothing to report. Parse error." in the encounter renderer.
// Clear lform_response for any row where it contains _nativeIntakeData
// (the real data is now in intake_* columns). Safe to run every page load —
// after all rows are cleaned the LIKE query returns 0 rows instantly.
if (!empty($pid)) {
    sqlStatementNoLog(
        "UPDATE form_questionnaire_assessments
            SET lform_response = ''
          WHERE pid = ?
            AND lform_response LIKE '%_nativeIntakeData%'
            AND activity = 1",
        [$pid]
    );
}


try {
    if (!empty($_GET['id'] ?? 0)) {
        $mode = 'update';
        $formid = $_GET['id'];

        // Prevent grabbing wrong form due to currently selected pid and not portal pid!
        // This is Dashboard so ...
        if (($_REQUEST["formOrigin"] ?? null) == 1 && empty($isPortal)) {
            $form = sqlQuery("SELECT * FROM `form_questionnaire_assessments` Where id=? And activity = 1 Limit 1", [$formid]);
        } else {
            $form = formFetch("form_questionnaire_assessments", $formid);
        }
        if (empty($form)) {
            throw new RuntimeException("Can not find encounter form.");
        }
        CoreFormToPortalUtility::confirmFormBootstrapPatient($isPortal, $formid, 'questionnaire_assessments', $_SESSION['pid']);
        $qr = $responseService->fetchQuestionnaireResponse(null, $form["response_id"]);
        // if empty form will revert to the backup response stored with form.
        if (!empty($qr)) {
            // This is primary response.
            $form['questionnaire_response'] = $qr['questionnaire_response'];
            $form['response_id'] = $qr['response_id'];
        }
    }

    $q_json = '';
    $lform = '';
    $form_name = '';
    if (empty($formid) && !empty($questionnaire_form) && $questionnaire_form != 'New Questionnaire') {
        // since we are here then user is authorized for a pre-approved questionnaire form.
        $is_authorized = true;
        if ($isPortal) {
            if (is_numeric($questionnaire_form)) {
                $q = $service->fetchQuestionnaireById((int)$questionnaire_form);
            } else {
                $q = $service->fetchQuestionnaireResource($questionnaire_form);
            }
        } else {
            $q = $service->fetchEncounterQuestionnaireForm($questionnaire_form);
        }
        $q_json = $q['questionnaire'] ?: '';
        $lform = $q['lform'] ?: '';
        $mode = 'new_form';
        $form_name = $q['name'] ?: '';
    }
// This is for newly selected questionnaire from repository dropdown.
    if (!empty($repository_item) && $questionnaire_form == 'New Questionnaire') {
        $q = $service->fetchQuestionnaireById($repository_item);
        $q_json = $q['questionnaire'] ?: '';
        $lform = $q['lform'] ?: '';
        $form_name = $q['name'] ?: '';
        $mode = 'new_repository_form';
    }

    if (!$isPortal) {
        $do_warning = checkUserSetting('disable_form_disclaimer', '1') === true ? 0 : 1;
    }
    if ($questionnaire_form == 'New Questionnaire') {
        $q_list = $service->getQuestionnaireList(true);
    }
} catch (Exception $e) {
    die(xlt("Can not continue with reason.") . '<br />' . text($e->getMessage()));
}
?>
<!DOCTYPE html>
<html>
<head>
    <title id="main_title"><?php echo xlt('Questionnaire'); ?></title>
    <?php Header::setupHeader(); ?>
    <!--<link href="<?php /*echo $GLOBALS['assets_static_relative']; */ ?>/lforms/webcomponent/styles.css" media="screen" rel="stylesheet" />-->
    <!-- TODO remove next release -->
    <script>
        let isPortal = <?php echo js_escape($isPortal); ?>;
        let portalOther = <?php echo js_escape($patientPortalOther); ?>;

        function initSelect() {
            let ourSelect = $('.select-dropdown');
            ourSelect.select2({
                multiple: false,
                placeholder: xl('Type to search.'),
                theme: 'bootstrap4',
                dropdownAutoWidth: true,
                width: 'resolve',
                closeOnSelect: true,
                <?php require($GLOBALS['srcdir'] . '/js/xl/select2.js.php'); ?>
            });
            $(document).on('select2:open', () => {
                document.querySelector('.select2-search__field').focus();
            });
            ourSelect.on("change", function (e) {
                top.restoreSession();
                let data = $('#select_item').select2('data');
                if (data) {
                    document.getElementById('form_name').value = data[0].text;
                }
                document.qa_form.action = "#";
                document.qa_form.submit();
            });
        }

        let formOptions = {
            "questionLayout": "vertical",
            "hideTreeLine": true,
            "hideRepetitionNumber": true,
            "showCodingInstruction": false,
            "displayScoreWithAnswerText": false
        };

        function saveQR() {
            if (!isPortal) {
                top.restoreSession();
            }
            if (formMode == 'register') {
                return true;
            }

            // If no LForms questionnaire loaded, native intake fields POST directly
            // as named columns — no packing needed.
            let lformValue = document.getElementById('lform') ? document.getElementById('lform').value : '';
            let lformsActive = !!(lformValue && lformValue.length > 2);
            if (!lformsActive) {
                return true;
            }

            let formElement = document.getElementById("formContainer");
            let notValid = LForms.Util.checkValidity(formElement);
            if (notValid) {
                notValid = "<span class='font-weight-bold p-2'>" + jsText(notValid) + "</span>";
                let error = notValid.replace(/requires a value,/g, "requires a value,<br />");
                error = error.replace(/requires a value/g, "<span class='text-danger'>requires a value</span>")
                let formatText = "<span class='h5'>" + xl('Form failed validation!') + "</span><br />" + error;
                dialog.alert(formatText).then(returned => {
                    dialog.close();
                    return false;
                });
            } else {
                let qr = LForms.Util.getFormFHIRData('QuestionnaireResponse', 'R4');
                let data = LForms.Util.getUserData(formElement, false, true, true);
                document.getElementById('lform_response').value = JSON.stringify(data);
                document.getElementById('questionnaire_response').value = JSON.stringify(qr);

                if (!document.getElementById('questionnaire').value) {
                    try {
                        let lForm = JSON.parse(document.getElementById('lform').value);
                        let qFhir = LForms.Util.getFormFHIRData("Questionnaire", 'R4', data);
                        document.getElementById('questionnaire').value = JSON.stringify(qFhir);
                    } catch(e) { /* non-fatal if questionnaire JSON unavailable */ }
                }
                return true;
            }
            return false;
        }

        function initUpdate() {
            // Merge QuestionnaireResponse
            let lForm = null;
            let qFhir = null;
            if (document.getElementById('lform').value) {
                lForm = JSON.parse(document.getElementById('lform').value);
            }
            if (document.getElementById('questionnaire').value) {
                qFhir = document.getElementById('questionnaire').value;
            }
            let qrRaw = document.getElementById('questionnaire_response').value;
            let qr = '';
            if (qrRaw) {
                try {
                    let qrObj = JSON.parse(qrRaw);
                    if (qrObj.resourceType === 'QuestionnaireResponse') {
                        qr = JSON.stringify(qrObj);
                    }
                } catch(e) { qr = ''; }
            }
            let qResponse;
            let responseData;
            if (!lForm && qFhir > '') {
                let qData = JSON.parse(qFhir);
                lForm = LForms.Util.convertFHIRQuestionnaireToLForms(qData, 'R4');
                document.getElementById('lform').value = JSON.stringify(lForm);
            }
            if (qr > '') {
                qResponse = JSON.parse(qr);
                responseData = LForms.Util.mergeFHIRDataIntoLForms("QuestionnaireResponse", qResponse, lForm, "R4");
            }
            // No alert if response is missing/corrupt — just load the blank form

            if (responseData) {
                LForms.Util.addFormToPage(responseData, 'formContainer', formOptions);
            } else if (lForm) {
                LForms.Util.addFormToPage(lForm, 'formContainer', formOptions);
            }
        }

        function initNewForm(flag = false) {
            let lform = <?php echo js_escape($lform); ?>;
            let qFhir = <?php echo js_escape($q_json); ?>;
            let formName = <?php echo js_escape($form_name); ?>;
            // Guard: if no form data, nothing to render in LForms — skip silently
            if (!lform && !qFhir) {
                return;
            }
            let data;
            if (lform) {
                data = JSON.parse(lform);
            } else if (qFhir) {
                let qData = JSON.parse(qFhir);
                data = LForms.Util.convertFHIRQuestionnaireToLForms(qData, 'R4');
            } else {
                alert(xl('Error Missing Form.'));
                parent.closeTab(window.name, false);
            }
            document.getElementById('questionnaire').value = qFhir;
            document.getElementById('lform').value = JSON.stringify(data);
            if (!flag) {
                document.getElementById('form_name').value = jsAttr(formName);
            }
            if (typeof data.copyrightNotice !== 'undefined' && data.copyrightNotice > '') {
                document.getElementById('copyright').value = jsAttr(data.copyrightNotice);
                document.getElementById('copyrightNotice').innerHTML = jsText(data.copyrightNotice);
            }
            LForms.Util.addFormToPage(data, 'formContainer', formOptions);
        }

        function initSearch() {
            // Guard: only run if the loinc_item element exists (admin New Questionnaire flow)
            if (!document.getElementById('loinc_item')) return;
            initSelect();
            <?php if ($do_warning) { ?>
            let msg = xl("OpenEMR is not responsible for any copyright and or permissions pertaining to questionnaires or assessments imported from external sources and then implemented and used by this feature.");
            msg += "<br />" + xl("Some, if not many, LOINC forms will display a copyright notice for information regarding permissions.");
            msg += "<br /><br />" + xl("Click Got it icon to dismiss this alert forever.");
            // dialog alertMsg 5th parameter will set flag to disable this user seeing message
            alertMsg(msg, 20000, 'danger', '', 'disable_form_disclaimer');
            <?php } ?>
            $(".isNew").toggleClass('d-none');
            // setup LOINC search listener
            let ac;
            ac = new LForms.Def.Autocompleter.Search(
                'loinc_item',
                'https://clinicaltables.nlm.nih.gov/api/loinc_items/v3/search?type=form&available=true&df=text,LOINC_NUM',
                {
                    tableFormat: true, valueCols: [0, 1],
                    colHeaders: ['Text', 'LOINC Code']
                }
            );
            LForms.Def.Autocompleter.Event.observeListSelections('loinc_item', function () {
                let formCode = ac.getSelectedCodes()[0];
                if (formCode) {
                    top.restoreSession();
                    let url = "https://clinicaltables.nlm.nih.gov/loinc_form_definitions?loinc_num=" + encodeURIComponent(formCode);
                    fetch(url).then((form) => {
                        return form.json();
                    }).then((data) => {
                        let saveButton = document.getElementById('save_response');
                        let registryButton = document.getElementById('save_registry');
                        saveButton.classList.remove("d-none");
                        registryButton.classList.remove("d-none");
                        document.getElementById('form_name').value = jsAttr(data.name);
                        document.getElementById('lform').value = JSON.stringify(data);
                        if (typeof data.copyrightNotice !== 'undefined' && data.copyrightNotice > '') {
                            document.getElementById('copyright').value = jsAttr(data.copyrightNotice);
                            document.getElementById('copyrightNotice').innerHTML = jsText(data.copyrightNotice);
                        }
                        LForms.Util.addFormToPage(data, 'formContainer', formOptions);
                        return data;
                    }).then((data) => {
                        let qFhir = LForms.Util.getFormFHIRData("Questionnaire", 'R4', data);
                        document.getElementById('questionnaire').value = JSON.stringify(qFhir);
                    });
                }
            });
        }

        function initSearchForm() {
            // Guard: only run if the loinc_item element exists (admin New Questionnaire flow)
            if (!document.getElementById('loinc_item')) return;
            initSelect();
            $(".isNew").toggleClass('d-none');

            document.getElementById('select_item').addEventListener('change', function () {
                let el = document.getElementById('select_item');
                document.getElementById('form_name').value = el.options[el.selectedIndex].text;
                document.qa_form.action = "#";
                document.qa_form.submit();
            });

            let ac;
            ac = new LForms.Def.Autocompleter.Search(
                'loinc_item',
                'https://clinicaltables.nlm.nih.gov/api/loinc_items/v3/search?type=form&available=true&df=text,LOINC_NUM',
                {
                    tableFormat: true, valueCols: [0, 1],
                    colHeaders: ['Text', 'LOINC Code']
                }
            );
            LForms.Def.Autocompleter.Event.observeListSelections('loinc_item', function () {
                let formCode = ac.getSelectedCodes()[0];
                if (formCode) {
                    top.restoreSession();
                    let url = "https://clinicaltables.nlm.nih.gov/loinc_form_definitions?loinc_num=" + encodeURIComponent(formCode);
                    fetch(url).then((form) => {
                        return form.json();
                    }).then((data) => {
                        let saveButton = document.getElementById('save_response');
                        let registryButton = document.getElementById('save_registry');
                        saveButton.classList.remove("d-none");
                        registryButton.classList.remove("d-none");
                        document.getElementById('lform').value = JSON.stringify(data);
                        document.getElementById('form_name').value = jsAttr(data.name);
                        if (typeof data.copyrightNotice !== 'undefined' && data.copyrightNotice > '') {
                            document.getElementById('copyright').value = jsAttr(data.copyrightNotice);
                            document.getElementById('copyrightNotice').innerHTML = jsText(data.copyrightNotice);
                        }
                        LForms.Util.addFormToPage(data, 'formContainer', formOptions);
                        return data;
                    }).then((data) => {
                        let qFhir = LForms.Util.getFormFHIRData("Questionnaire", 'R4', data);
                        document.getElementById('questionnaire').value = JSON.stringify(qFhir);
                    });
                }
            });

            initNewForm(true);
            let saveButton = document.getElementById('save_response');
            let registryButton = document.getElementById('save_registry');
            saveButton.classList.remove("d-none");
            registryButton.classList.remove("d-none");
        }
    </script>
</head>
<style>
/* ── Reset & base ── */
*, *::before, *::after { box-sizing: border-box; }

body.qa-body {
    background: #f4f2f9;
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    font-size: 14px;
    color: #1a1523;
    margin: 0;
    min-height: 100vh;
}

/* ── Tokens ── */
:root {
    --purple-900: #2d0f6b;
    --purple-700: #5b21b6;
    --purple-600: #7c3aed;
    --purple-400: #a78bfa;
    --purple-100: #ede9fe;
    --purple-50:  #f5f3ff;
    --green:      #059669;
    --green-light:#d1fae5;
    --amber:      #d97706;
    --amber-light:#fef3c7;
    --red:        #dc2626;
    --red-light:  #fee2e2;
    --border:     #e4ddf5;
    --surface:    #ffffff;
    --surface2:   #f8f6fd;
    --text-muted: #7c6f96;
    --radius:     10px;
    --shadow:     0 2px 12px rgba(91,33,182,.08);
    --shadow-lg:  0 8px 32px rgba(91,33,182,.14);
}

/* ── Shell layout ── */
.qa-shell {
    display: flex;
    min-height: 100vh;
}

/* ── Left nav / step rail ── */
.qa-rail {
    width: 220px;
    flex-shrink: 0;
    background: #fff;
    border-right: 1px solid var(--border);
    padding: 24px 0 80px;
    position: fixed;
    top: 0; left: 0; bottom: 0;
    overflow-y: auto;
    z-index: 10;
    box-shadow: 2px 0 12px rgba(91,33,182,.05);
}

.qa-rail-logo {
    padding: 0 20px 20px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 18px;
}
.qa-rail-logo .qa-form-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--purple-700);
    line-height: 1.35;
    word-break: break-word;
}
.qa-rail-logo .qa-form-sub {
    font-size: 11px;
    color: var(--text-muted);
    margin-top: 2px;
}

.qa-rail-label {
    font-size: 9.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--text-muted);
    padding: 0 20px 8px;
}

/* Step items */
.qa-step {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px 8px 18px;
    cursor: pointer;
    border-left: 3px solid transparent;
    transition: all .14s;
    text-decoration: none;
}
.qa-step:hover { background: var(--purple-50); border-left-color: var(--purple-400); }
.qa-step.active { background: var(--purple-50); border-left-color: var(--purple-600); }
.qa-step.done   { border-left-color: var(--green); }

.qa-step-num {
    width: 22px; height: 22px;
    border-radius: 50%;
    background: #f0edf8;
    border: 2px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 800;
    color: var(--text-muted);
    flex-shrink: 0;
    transition: all .14s;
}
.qa-step.active .qa-step-num {
    background: var(--purple-600);
    border-color: var(--purple-600);
    color: #fff;
}
.qa-step.done .qa-step-num {
    background: var(--green);
    border-color: var(--green);
    color: #fff;
}
.qa-step-done-icon { display: none; }
.qa-step.done .qa-step-done-icon { display: block; }
.qa-step.done .qa-step-num-text  { display: none; }

.qa-step-info { flex: 1; min-width: 0; }
.qa-step-name {
    font-size: 12px; font-weight: 600; color: #1a1523;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.qa-step.active .qa-step-name { color: var(--purple-700); }
.qa-step-hint { font-size: 10px; color: var(--text-muted); margin-top: 1px; }

/* Progress bar */
.qa-rail-progress {
    padding: 14px 20px 0;
    margin-top: 10px;
    border-top: 1px solid var(--border);
}
.qa-rail-progress-label {
    display: flex; justify-content: space-between;
    font-size: 10px; font-weight: 700; color: var(--text-muted);
    margin-bottom: 6px;
}
.qa-rail-progress-bar {
    height: 5px; background: var(--border); border-radius: 3px; overflow: hidden;
}
.qa-rail-progress-fill {
    height: 100%; background: linear-gradient(90deg, var(--purple-600), var(--purple-400));
    border-radius: 3px; transition: width .3s ease;
}

/* ── Main content ── */
.qa-main {
    margin-left: 220px;
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* Top bar */
.qa-topbar {
    background: #fff;
    border-bottom: 1px solid var(--border);
    padding: 14px 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky; top: 0; z-index: 5;
    box-shadow: 0 1px 8px rgba(91,33,182,.06);
}
.qa-topbar-title {
    font-size: 16px; font-weight: 800; color: var(--purple-900);
    display: flex; align-items: center; gap: 10px;
}
.qa-topbar-title svg { color: var(--purple-600); }
.qa-topbar-mode-badge {
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 3px 9px; border-radius: 20px; background: var(--purple-100); color: var(--purple-700);
}
.qa-topbar-actions { display: flex; gap: 8px; align-items: center; }

/* ── Buttons ── */
.qa-btn {
    display: inline-flex; align-items: center; gap: 6px;
    border-radius: 7px; padding: 8px 16px;
    font-size: 13px; font-weight: 600; cursor: pointer;
    border: none; font-family: inherit; transition: all .13s;
    line-height: 1;
}
.qa-btn-primary {
    background: linear-gradient(135deg, var(--purple-700), var(--purple-600));
    color: #fff;
    box-shadow: 0 2px 8px rgba(124,58,237,.25);
}
.qa-btn-primary:hover { opacity: .88; transform: translateY(-1px); }
.qa-btn-primary:disabled { opacity: .4; transform: none; cursor: not-allowed; }
.qa-btn-outline {
    background: #fff; color: var(--purple-700);
    border: 1.5px solid var(--border);
}
.qa-btn-outline:hover { border-color: var(--purple-400); background: var(--purple-50); }
.qa-btn-ghost {
    background: transparent; color: var(--text-muted);
    border: 1.5px solid transparent;
}
.qa-btn-ghost:hover { background: var(--surface2); color: #1a1523; }
.qa-btn-danger {
    background: #fff; color: var(--red);
    border: 1.5px solid #fecaca;
}
.qa-btn-danger:hover { background: var(--red-light); }
.qa-btn-sm { padding: 5px 11px; font-size: 11.5px; }

/* ── Content area ── */
.qa-content {
    flex: 1;
    padding: 28px 32px 120px;
    max-width: 900px;
}

/* ── Section card ── */
.qa-section {
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 14px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
    overflow: hidden;
    scroll-margin-top: 80px;
}
.qa-section-header {
    background: var(--surface2);
    border-bottom: 1.5px solid var(--border);
    padding: 14px 20px;
    display: flex; align-items: center; gap: 12px;
    cursor: pointer; user-select: none;
}
.qa-section-icon {
    width: 32px; height: 32px; border-radius: 8px;
    background: var(--purple-100);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.qa-section-icon svg { color: var(--purple-600); }
.qa-section-title { font-size: 14px; font-weight: 700; color: var(--purple-900); flex: 1; }
.qa-section-subtitle { font-size: 11px; color: var(--text-muted); margin-top: 1px; }
.qa-section-chevron { color: var(--text-muted); transition: transform .2s; }
.qa-section.collapsed .qa-section-chevron { transform: rotate(-90deg); }
.qa-section-body { padding: 20px; }
.qa-section.collapsed .qa-section-body { display: none; }

/* ── Form grid ── */
.qa-grid { display: grid; gap: 14px; }
.qa-grid-2 { grid-template-columns: 1fr 1fr; }
.qa-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
.qa-grid-4 { grid-template-columns: 1fr 1fr 1fr 1fr; }

.qa-field { display: flex; flex-direction: column; gap: 5px; }
.qa-field-full { grid-column: 1 / -1; }

.qa-label {
    font-size: 10.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .05em;
    color: var(--text-muted);
}
.qa-label-required::after { content: ' *'; color: var(--red); }

.qa-input, .qa-select, .qa-textarea {
    border: 1.5px solid var(--border);
    border-radius: 7px;
    padding: 8px 11px;
    font-size: 13px;
    font-family: inherit;
    color: #1a1523;
    background: #fff;
    transition: border-color .13s, box-shadow .13s;
    width: 100%;
}
.qa-input:focus, .qa-select:focus, .qa-textarea:focus {
    border-color: var(--purple-600);
    box-shadow: 0 0 0 3px rgba(124,58,237,.10);
    outline: none;
}
.qa-textarea { resize: vertical; min-height: 80px; line-height: 1.5; }
.qa-select { cursor: pointer; }

/* ── Radio / Checkbox groups ── */
.qa-radio-group, .qa-check-group {
    display: flex; flex-wrap: wrap; gap: 8px;
}
.qa-radio-group.vertical, .qa-check-group.vertical {
    flex-direction: column; gap: 6px;
}

.qa-radio-btn input, .qa-check-btn input { display: none; }
.qa-radio-btn label, .qa-check-btn label {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 13px;
    border: 1.5px solid var(--border);
    border-radius: 7px;
    font-size: 12.5px; font-weight: 500;
    cursor: pointer; color: #374151;
    transition: all .12s;
    user-select: none;
}
.qa-radio-btn label:hover, .qa-check-btn label:hover {
    border-color: var(--purple-400); background: var(--purple-50);
}
.qa-radio-btn input:checked + label, .qa-check-btn input:checked + label {
    border-color: var(--purple-600); background: var(--purple-100); color: var(--purple-700); font-weight: 700;
}

/* Inline yes/no toggle */
.qa-yn { display: flex; gap: 0; border-radius: 7px; overflow: hidden; border: 1.5px solid var(--border); width: fit-content; }
.qa-yn input { display: none; }
.qa-yn label {
    padding: 6px 18px; font-size: 12.5px; font-weight: 600; cursor: pointer; color: var(--text-muted); transition: all .12s;
}
.qa-yn label:first-of-type { border-right: 1px solid var(--border); }
.qa-yn input:checked + label { background: var(--purple-600); color: #fff; }

/* ── Pain scale ── */
.qa-pain-scale {
    display: flex; gap: 4px; align-items: center;
}
.qa-pain-btn input { display: none; }
.qa-pain-btn label {
    width: 36px; height: 36px;
    border-radius: 8px;
    border: 1.5px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700;
    cursor: pointer; transition: all .12s;
    color: #6b7280;
}
.qa-pain-btn label:hover { transform: scale(1.08); }
.qa-pain-btn input:checked + label { border-color: transparent; color: #fff; }

/* Color gradient for pain 0–10 */
.qa-pain-btn:nth-child(1)  label { background: #f0fdf4; }
.qa-pain-btn:nth-child(2)  label { background: #dcfce7; }
.qa-pain-btn:nth-child(3)  label { background: #bbf7d0; }
.qa-pain-btn:nth-child(4)  label { background: #fef9c3; }
.qa-pain-btn:nth-child(5)  label { background: #fef08a; }
.qa-pain-btn:nth-child(6)  label { background: #fed7aa; }
.qa-pain-btn:nth-child(7)  label { background: #fdba74; }
.qa-pain-btn:nth-child(8)  label { background: #f97316; }
.qa-pain-btn:nth-child(9)  label { background: #ef4444; }
.qa-pain-btn:nth-child(10) label { background: #dc2626; }
.qa-pain-btn:nth-child(11) label { background: #7f1d1d; }

.qa-pain-btn:nth-child(1)  input:checked + label { background: #059669; }
.qa-pain-btn:nth-child(2)  input:checked + label { background: #16a34a; }
.qa-pain-btn:nth-child(3)  input:checked + label { background: #22c55e; }
.qa-pain-btn:nth-child(4)  input:checked + label { background: #ca8a04; }
.qa-pain-btn:nth-child(5)  input:checked + label { background: #eab308; }
.qa-pain-btn:nth-child(6)  input:checked + label { background: #f97316; }
.qa-pain-btn:nth-child(7)  input:checked + label { background: #ea580c; }
.qa-pain-btn:nth-child(8)  input:checked + label { background: #f97316; color:#fff; }
.qa-pain-btn:nth-child(9)  input:checked + label { background: #ef4444; }
.qa-pain-btn:nth-child(10) input:checked + label { background: #dc2626; }
.qa-pain-btn:nth-child(11) input:checked + label { background: #7f1d1d; }

.qa-pain-labels {
    display: flex; justify-content: space-between; margin-top: 4px;
    font-size: 10px; color: var(--text-muted);
}

/* ── Body region selector ── */
.qa-body-regions {
    display: flex; flex-wrap: wrap; gap: 6px;
}
.qa-body-chip {
    padding: 5px 12px;
    border: 1.5px solid var(--border);
    border-radius: 20px;
    font-size: 11.5px; font-weight: 500; cursor: pointer;
    color: #374151; background: #fff; transition: all .12s;
    user-select: none;
}
.qa-body-chip:hover { border-color: var(--purple-400); background: var(--purple-50); }
.qa-body-chip.selected { border-color: var(--purple-600); background: var(--purple-100); color: var(--purple-700); font-weight: 700; }

/* ── Systems review grid ── */
.qa-systems-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}
.qa-system-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 8px 12px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    background: var(--surface2);
    gap: 8px;
}
.qa-system-name { font-size: 12px; font-weight: 600; color: #374151; flex: 1; }
.qa-system-yn { display: flex; gap: 0; border-radius: 5px; overflow: hidden; border: 1.5px solid var(--border); }
.qa-system-yn input { display: none; }
.qa-system-yn label {
    padding: 3px 10px; font-size: 11px; font-weight: 600; cursor: pointer; color: var(--text-muted);
}
.qa-system-yn label:first-of-type { border-right: 1px solid var(--border); }
.qa-system-yn input:checked + label { background: var(--purple-600); color: #fff; }

/* ── Inline LOINC search (admin only) ── */
.qa-loinc-bar {
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 20px;
    box-shadow: var(--shadow);
}
.qa-loinc-bar-title {
    font-size: 12px; font-weight: 700; color: var(--purple-700);
    margin-bottom: 12px; display: flex; align-items: center; gap: 7px;
}

/* ── Floating save bar ── */
.qa-save-bar {
    position: fixed; bottom: 0; left: 220px; right: 0; z-index: 20;
    background: #fff;
    border-top: 2px solid var(--border);
    box-shadow: 0 -4px 24px rgba(91,33,182,.10);
    padding: 12px 32px;
    display: flex; align-items: center; gap: 16px;
}
.qa-save-bar-left { display: flex; align-items: center; gap: 12px; flex: 1; }
.qa-save-bar-status {
    font-size: 12px; color: var(--text-muted);
    display: flex; align-items: center; gap: 6px;
}
.qa-save-bar-status.saved { color: var(--green); }
.qa-save-bar-right { display: flex; gap: 8px; }

/* ── Divider with label ── */
.qa-divider {
    display: flex; align-items: center; gap: 10px; margin: 16px 0 10px;
}
.qa-divider span { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); white-space: nowrap; }
.qa-divider::before, .qa-divider::after { content:''; flex:1; height:1px; background: var(--border); }

/* ── Notice banner ── */
.qa-notice {
    background: var(--amber-light);
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px; color: #92400e;
    display: flex; gap: 8px; align-items: flex-start;
    margin-bottom: 16px;
}
.qa-notice.info { background: var(--purple-50); border-color: var(--purple-100); color: var(--purple-700); }

/* ── lform container override ── */
#formContainer { margin-top: 8px; }
#formContainer .lf-form { font-family: inherit; }

/* ── Unauthorized state ── */
.qa-unauth {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    min-height: 60vh; gap: 10px; text-align: center;
}
.qa-unauth-icon { color: #d1d5db; margin-bottom: 8px; }
.qa-unauth h2 { font-size: 20px; font-weight: 800; color: #1a1523; margin: 0; }
.qa-unauth p  { color: var(--text-muted); font-size: 14px; margin: 0; }

/* Responsive */
@media (max-width: 768px) {
    .qa-rail { display: none; }
    .qa-main { margin-left: 0; }
    .qa-save-bar { left: 0; }
    .qa-grid-2, .qa-grid-3, .qa-grid-4 { grid-template-columns: 1fr; }
    .qa-systems-grid { grid-template-columns: 1fr 1fr; }
    .qa-content { padding: 16px 16px 120px; }
}

/* Print */
@media print {
    .qa-rail, .qa-topbar, .qa-save-bar, .qa-loinc-bar { display: none !important; }
    .qa-main { margin-left: 0; }
    .qa-section.collapsed .qa-section-body { display: block !important; }
}
</style>

<body class="qa-body">
<div class="qa-shell">

  <!-- ── Left step rail ── -->
  <aside class="qa-rail" id="qaRail">
    <div class="qa-rail-logo">
      <svg width="28" height="28" fill="none" viewBox="0 0 24 24" style="color:var(--purple-600);margin-bottom:8px;" stroke="currentColor" stroke-width="1.8"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      <div class="qa-form-title" id="railFormTitle">
        <?php echo text($form['form_name'] ?: $form_name ?: xlt('Patient Questionnaire')); ?>
      </div>
      <div class="qa-form-sub"><?php echo xlt('Encounter Assessment'); ?></div>
    </div>

    <div class="qa-rail-label"><?php echo xlt('Sections'); ?></div>

    <?php
    $rail_steps = [
        ['id'=>'sec-chief',    'name'=>xlt('Chief Complaint'),       'hint'=>xlt('Reason for visit')],
        ['id'=>'sec-history',  'name'=>xlt('History'),               'hint'=>xlt('Past and social history')],
        ['id'=>'sec-pain',     'name'=>xlt('Pain Assessment'),       'hint'=>xlt('Location and severity')],
        ['id'=>'sec-systems',  'name'=>xlt('Systems Review'),        'hint'=>xlt('By system')],
        ['id'=>'sec-function', 'name'=>xlt('Functional Status'),     'hint'=>xlt('Daily activities')],
        ['id'=>'sec-goals',    'name'=>xlt('Goals and Notes'),       'hint'=>xlt('Treatment goals')],
    ];
    // If this is update/new_form mode, also show the LOINC form container step
    if (in_array($mode ?? '', ['update','new_form','new_repository_form'])) {
        $rail_steps[] = ['id'=>'sec-lform', 'name'=>xlt('Assessment Form'), 'hint'=>xlt('LForms questionnaire')];
    }
    foreach ($rail_steps as $si => $step):
    ?>
    <div class="qa-step" id="rail-<?php echo attr($step['id']); ?>" onclick="scrollToSection('<?php echo attr($step['id']); ?>')" role="button" tabindex="0">
      <div class="qa-step-num">
        <span class="qa-step-num-text"><?php echo $si+1; ?></span>
        <svg class="qa-step-done-icon" width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <div class="qa-step-info">
        <div class="qa-step-name"><?php echo text($step['name']); ?></div>
        <div class="qa-step-hint"><?php echo text($step['hint']); ?></div>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="qa-rail-progress" style="margin-top:20px;">
      <div class="qa-rail-progress-label">
        <span><?php echo xlt('Completion'); ?></span>
        <span id="progressPct">0%</span>
      </div>
      <div class="qa-rail-progress-bar"><div class="qa-rail-progress-fill" id="progressFill" style="width:0%"></div></div>
    </div>
  </aside>

  <!-- ── Main area ── -->
  <div class="qa-main">

    <!-- Top bar -->
    <div class="qa-topbar">
      <div class="qa-topbar-title">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
        <?php if (in_array($mode ?? '', ['update','new_form','new_repository_form'])): ?>
          <span><?php echo text($form['form_name'] ?: $form_name ?: xlt('Questionnaire')); ?></span>
          <span class="qa-topbar-mode-badge"><?php echo ($mode === 'update') ? xlt('Editing') : xlt('New'); ?></span>
        <?php else: ?>
          <span><?php echo xlt('Patient Intake Questionnaire'); ?></span>
          <span class="qa-topbar-mode-badge"><?php echo xlt('New Form'); ?></span>
        <?php endif; ?>
      </div>
      <div class="qa-topbar-actions">
        <button type="button" class="qa-btn qa-btn-ghost qa-btn-sm" onclick="parent.closeTab ? parent.closeTab(window.name,false) : history.back()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          <?php echo xlt('Close'); ?>
        </button>
      </div>
    </div>

    <!-- Content -->
    <div class="qa-content" id="qaContent">

      <?php if (!$is_authorized): ?>
      <div class="qa-unauth">
        <svg class="qa-unauth-icon" width="56" height="56" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <h2><?php echo xlt('Not Authorized'); ?></h2>
        <p><?php echo xlt('You must have administrator privileges to use this feature.'); ?></p>
        <p><?php echo xlt('Contact an administrator to enable access.'); ?></p>
        <button type="button" class="qa-btn qa-btn-primary" onclick="parent.closeTab ? parent.closeTab(window.name,false) : history.back()"><?php echo xlt('Exit'); ?></button>
      </div>
      <?php die(); endif; ?>

      <!-- LOINC / Repository selector (admin, new form only) -->
      <?php if ($questionnaire_form == 'New Questionnaire'): ?>
      <div class="qa-loinc-bar">
        <div class="qa-loinc-bar-title">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          <?php echo xlt('Select a Questionnaire Form'); ?>
        </div>
        <form method="post" id="qa_form_select" name="qa_form" action="<?php echo $rootdir; ?>/forms/questionnaire_assessments/save.php?form_id=<?php echo attr_url($formid ?? ''); ?>&mode=<?php echo attr_url($mode ?? ''); ?>">
          <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
          <input type="hidden" id="form_name" name="form_name" value="" />
          <div class="qa-grid qa-grid-2" style="gap:12px;">
            <div class="qa-field">
              <label class="qa-label"><?php echo xlt('Search LOINC Forms'); ?></label>
              <input class="qa-input search_field isNew" type="text" id="loinc_item" placeholder="<?php echo xla('Type to search LOINC…'); ?>" autocomplete="off" />
            </div>
            <div class="qa-field">
              <label class="qa-label"><?php echo xlt('Repository'); ?></label>
              <select class="qa-select select-dropdown isNew" id="select_item" name="select_item">
                <option value=""><?php echo xlt('— Choose from repository —'); ?></option>
                <?php foreach ($q_list ?? [] as $item): $id = attr($item['id']); ?>
                  <option value="<?php echo $id; ?>" <?php echo ($id == $repository_item) ? 'selected' : ''; ?>><?php echo text($item['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div id="copyrightNotice" style="font-size:11px;color:var(--text-muted);margin-top:8px;"><?php echo text($form['copyright'] ?? ''); ?></div>
        </form>
      </div>
      <?php endif; ?>

      <!-- ════ Main questionnaire form ════ -->
      <form method="post" id="qa_form" name="qa_form" onsubmit="return saveQR()"
            action="<?php echo $rootdir; ?>/forms/questionnaire_assessments/save.php?form_id=<?php echo attr_url($formid ?? ''); ?><?php echo $isPortal ? '&isPortal=1' : ''; ?><?php echo $patientPortalOther ? '&formOrigin='.attr_url($_GET['formOrigin']) : ''; ?><?php echo '&mode='.attr_url($mode ?? ''); ?>">

        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
        <input type="hidden" id="lform" name="lform" value="<?php echo attr($form['lform'] ?? ''); ?>" />
        <input type="hidden" id="lform_response" name="lform_response" value="<?php echo attr($form['lform_response'] ?? ''); ?>" />
        <input type="hidden" id="response_id" name="response_id" value="<?php echo attr($form['response_id'] ?? ''); ?>" />
        <input type="hidden" id="response_meta" name="response_meta" value="<?php echo attr($form['response_meta'] ?? ''); ?>" />
        <input type="hidden" id="copyright" name="copyright" value="<?php echo attr($form['copyright'] ?? ''); ?>" />
        <input type="hidden" id="questionnaire" name="questionnaire" value="<?php echo attr($form['questionnaire'] ?? ''); ?>" />
        <input type="hidden" id="questionnaire_response" name="questionnaire_response" value="<?php echo attr($form['questionnaire_response'] ?? ''); ?>" />
        <!-- Native questionnaire data is embedded into questionnaire_response JSON on submit (see JS below) -->

        <?php if (!empty($form['copyright'])): ?>
        <div class="qa-notice" style="margin-bottom:16px;">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <span><?php echo text($form['copyright']); ?></span>
        </div>
        <?php endif; ?>

        <!-- ────── Section 1: Chief Complaint ────── -->
        <div class="qa-section" id="sec-chief" data-section="1">
          <div class="qa-section-header" onclick="toggleSection(this)">
            <div class="qa-section-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            </div>
            <div style="flex:1">
              <div class="qa-section-title"><?php echo xlt('Chief Complaint'); ?></div>
              <div class="qa-section-subtitle"><?php echo xlt('Primary reason for today\'s visit'); ?></div>
            </div>
            <svg class="qa-section-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="qa-section-body">
            <div class="qa-grid" style="gap:14px;">
              <div class="qa-field">
                <label class="qa-label qa-label-required"><?php echo xlt('Primary Complaint'); ?></label>
                <textarea class="qa-textarea" id="qa_chief_complaint" name="intake_chief_complaint" rows="3"
                  placeholder="<?php echo xla('Describe the patient\'s main complaint in their own words…'); ?>"
                  oninput="trackField(this)"><?php echo text($form['intake_chief_complaint'] ?? ''); ?></textarea>
              </div>
              <div class="qa-grid qa-grid-2">
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Onset'); ?></label>
                  <input type="date" class="qa-input" id="qa_onset_date" name="intake_onset_date" value="<?php echo attr($form['intake_onset_date'] ?? ''); ?>" oninput="trackField(this)" />
                </div>
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Duration'); ?></label>
                  <select class="qa-select" id="qa_duration" name="intake_duration" onchange="trackField(this)">
                    <option value=""><?php echo xlt('Select…'); ?></option>
                    <option value="acute" <?php echo ($form['intake_duration']??'')=='acute'?'selected':''; ?>><?php echo xlt('Acute (< 6 weeks)'); ?></option>
                    <option value="subacute" <?php echo ($form['intake_duration']??'')=='subacute'?'selected':''; ?>><?php echo xlt('Sub-acute (6–12 weeks)'); ?></option>
                    <option value="chronic" <?php echo ($form['intake_duration']??'')=='chronic'?'selected':''; ?>><?php echo xlt('Chronic (> 12 weeks)'); ?></option>
                    <option value="recurrent" <?php echo ($form['intake_duration']??'')=='recurrent'?'selected':''; ?>><?php echo xlt('Recurrent / Episodic'); ?></option>
                  </select>
                </div>
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Mechanism / Cause'); ?></label>
                <div class="qa-radio-group">
                  <?php $causes = ['Spontaneous','Traumatic','Postural','Work-related','Sport / Activity','MVA','Unknown']; ?>
                  <?php foreach ($causes as $ci => $c): ?>
                  <div class="qa-radio-btn">
                    <input type="radio" id="cause_<?php echo $ci; ?>" name="intake_cause" value="<?php echo attr($c); ?>" <?php echo (($form['intake_cause']??'')==$c)?'checked':''; ?> onchange="trackField(this)" />
                    <label for="cause_<?php echo $ci; ?>"><?php echo xlt($c); ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Pattern'); ?></label>
                <div class="qa-radio-group">
                  <?php $patterns = ['Constant','Intermittent','Worsening','Improving','Fluctuating']; ?>
                  <?php foreach ($patterns as $pi => $p): ?>
                  <div class="qa-radio-btn">
                    <input type="radio" id="pattern_<?php echo $pi; ?>" name="intake_pattern" value="<?php echo attr($p); ?>" <?php echo (($form['intake_pattern']??'')==$p)?'checked':''; ?> onchange="trackField(this)" />
                    <label for="pattern_<?php echo $pi; ?>"><?php echo xlt($p); ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="qa-grid qa-grid-2">
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Aggravating Factors'); ?></label>
                  <textarea class="qa-textarea" name="intake_aggravating" rows="2" placeholder="<?php echo xla('e.g. sitting, bending, lifting…'); ?>" style="min-height:60px;" oninput="trackField(this)"><?php echo text($form['intake_aggravating'] ?? ''); ?></textarea>
                </div>
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Relieving Factors'); ?></label>
                  <textarea class="qa-textarea" name="intake_relieving" rows="2" placeholder="<?php echo xla('e.g. rest, heat, ice, medication…'); ?>" style="min-height:60px;" oninput="trackField(this)"><?php echo text($form['intake_relieving'] ?? ''); ?></textarea>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ────── Section 2: History ────── -->
        <div class="qa-section" id="sec-history" data-section="2">
          <div class="qa-section-header" onclick="toggleSection(this)">
            <div class="qa-section-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div style="flex:1">
              <div class="qa-section-title"><?php echo xlt('Medical History'); ?></div>
              <div class="qa-section-subtitle"><?php echo xlt('Past conditions, surgeries, medications, and family history'); ?></div>
            </div>
            <svg class="qa-section-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="qa-section-body">
            <div class="qa-grid" style="gap:14px;">
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Known Conditions'); ?></label>
                <div class="qa-check-group">
                  <?php $conditions = ['Hypertension','Diabetes','Heart Disease','Osteoporosis','Arthritis','Fibromyalgia','Cancer','Depression / Anxiety','Thyroid Disorder','None']; ?>
                  <?php foreach ($conditions as $ci => $c): ?>
                  <div class="qa-check-btn">
                    <input type="checkbox" id="cond_<?php echo $ci; ?>" name="intake_conditions" value="<?php echo attr($c); ?>" <?php echo (strpos(','.($form['intake_conditions']??'').':', ':'.($c).':')!==false)?'checked':''; ?> onchange="trackField(this)" />
                    <label for="cond_<?php echo $ci; ?>"><?php echo xlt($c); ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="qa-grid qa-grid-2">
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Previous Surgeries / Procedures'); ?></label>
                  <textarea class="qa-textarea" name="intake_surgeries" rows="2" style="min-height:60px;" placeholder="<?php echo xla('List any surgeries and approximate dates…'); ?>" oninput="trackField(this)"><?php echo text($form['intake_surgeries'] ?? ''); ?></textarea>
                </div>
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Current Medications'); ?></label>
                  <textarea class="qa-textarea" name="intake_medications" rows="2" style="min-height:60px;" placeholder="<?php echo xla('Name, dose, frequency…'); ?>" oninput="trackField(this)"><?php echo text($form['intake_medications'] ?? ''); ?></textarea>
                </div>
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Allergies'); ?></label>
                <input type="text" class="qa-input" name="intake_allergies" value="<?php echo attr($form['intake_allergies'] ?? ''); ?>" placeholder="<?php echo xla('Drug, food, latex, or other allergies (or NKDA)…'); ?>" oninput="trackField(this)" />
              </div>
              <div class="qa-divider"><span><?php echo xlt('Lifestyle & Social'); ?></span></div>
              <div class="qa-grid qa-grid-3">
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Occupation'); ?></label>
                  <input type="text" class="qa-input" name="intake_occupation" value="<?php echo attr($form['intake_occupation'] ?? ''); ?>" placeholder="<?php echo xla('Job title…'); ?>" oninput="trackField(this)" />
                </div>
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Smoking'); ?></label>
                  <select class="qa-select" name="intake_smoking" onchange="trackField(this)">
                    <option value=""><?php echo xlt('Select…'); ?></option>
                    <option value="never" <?php echo (($form['intake_smoking']??'')=='never')?'selected':''; ?>><?php echo xlt('Never'); ?></option>
                    <option value="former" <?php echo (($form['intake_smoking']??'')=='former')?'selected':''; ?>><?php echo xlt('Former'); ?></option>
                    <option value="current" <?php echo (($form['intake_smoking']??'')=='current')?'selected':''; ?>><?php echo xlt('Current'); ?></option>
                  </select>
                </div>
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Exercise (days/wk)'); ?></label>
                  <select class="qa-select" name="intake_exercise" onchange="trackField(this)">
                    <option value=""><?php echo xlt('Select…'); ?></option>
                    <?php for ($d=0;$d<=7;$d++) echo '<option value="'.$d.'"'.(((string)($form['intake_exercise']??''))===(string)$d?' selected':'').">$d</option>"; ?>
                  </select>
                </div>
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Previous Treatment for This Complaint'); ?></label>
                <div class="qa-check-group">
                  <?php $txts = ['Physical Therapy','Chiropractic','Massage Therapy','Acupuncture','Surgery','Injection / Steroid','Medication only','None']; ?>
                  <?php foreach ($txts as $ti => $t): ?>
                  <div class="qa-check-btn">
                    <input type="checkbox" id="prevtx_<?php echo $ti; ?>" name="intake_prev_treatment" value="<?php echo attr($t); ?>" <?php echo (strpos(','.($form['intake_prev_treatment']??'').':', ':'.($t).':')!==false)?'checked':''; ?> onchange="trackField(this)" />
                    <label for="prevtx_<?php echo $ti; ?>"><?php echo xlt($t); ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ────── Section 3: Pain Assessment ────── -->
        <div class="qa-section" id="sec-pain" data-section="3">
          <div class="qa-section-header" onclick="toggleSection(this)">
            <div class="qa-section-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div style="flex:1">
              <div class="qa-section-title"><?php echo xlt('Pain Assessment'); ?></div>
              <div class="qa-section-subtitle"><?php echo xlt('Location, quality, and severity'); ?></div>
            </div>
            <svg class="qa-section-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="qa-section-body">
            <div class="qa-grid" style="gap:16px;">
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Body Region(s) Affected'); ?></label>
                <div class="qa-body-regions" id="bodyRegions">
                  <?php $regions = ['Head / Skull','Cervical (Neck)','Upper Thoracic','Mid Thoracic','Lower Thoracic','Lumbar (Low Back)','Sacral / Coccyx','Left Shoulder','Right Shoulder','Left Elbow','Right Elbow','Left Wrist / Hand','Right Wrist / Hand','Left Hip','Right Hip','Left Knee','Right Knee','Left Ankle / Foot','Right Ankle / Foot','Bilateral']; ?>
                  <?php foreach ($regions as $ri => $r): ?>
                  <span class="qa-body-chip" data-value="<?php echo attr($r); ?>" onclick="toggleBodyChip(this)"><?php echo xlt($r); ?></span>
                  <?php endforeach; ?>
                </div>
                <input type="hidden" id="qa_body_regions" name="intake_body_regions" value="<?php echo attr($form['intake_body_regions']??''??''); ?>" />
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Current Pain Level (0 = none, 10 = worst imaginable)'); ?></label>
                <div class="qa-pain-scale">
                  <?php for ($p = 0; $p <= 10; $p++): ?>
                  <div class="qa-pain-btn">
                    <input type="radio" id="pain_<?php echo $p; ?>" name="intake_pain_level" value="<?php echo $p; ?>" <?php echo (((int)($form['intake_pain_level']??-1))==$p)?'checked':''; ?> onchange="trackField(this);updatePainLabel(<?php echo $p; ?>)" />
                    <label for="pain_<?php echo $p; ?>"><?php echo $p; ?></label>
                  </div>
                  <?php endfor; ?>
                </div>
                <div class="qa-pain-labels"><span><?php echo xlt('No pain'); ?></span><span><?php echo xlt('Moderate'); ?></span><span><?php echo xlt('Worst'); ?></span></div>
                <div id="painLabelText" style="font-size:11px;color:var(--text-muted);margin-top:4px;height:16px;"></div>
              </div>
              <div class="qa-grid qa-grid-2">
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Pain at Best (last 7 days)'); ?></label>
                  <div class="qa-pain-scale" style="gap:3px;">
                    <?php for ($p = 0; $p <= 10; $p++): ?>
                    <div class="qa-pain-btn"><input type="radio" id="painbest_<?php echo $p; ?>" name="intake_pain_best" value="<?php echo $p; ?>" <?php echo (((int)($form['intake_pain_best']??-1))==$p)?'checked':''; ?> onchange="trackField(this)" /><label for="painbest_<?php echo $p; ?>" style="width:28px;height:28px;font-size:11px;"><?php echo $p; ?></label></div>
                    <?php endfor; ?>
                  </div>
                </div>
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Pain at Worst (last 7 days)'); ?></label>
                  <div class="qa-pain-scale" style="gap:3px;">
                    <?php for ($p = 0; $p <= 10; $p++): ?>
                    <div class="qa-pain-btn"><input type="radio" id="painworst_<?php echo $p; ?>" name="intake_pain_worst" value="<?php echo $p; ?>" <?php echo (((int)($form['intake_pain_worst']??-1))==$p)?'checked':''; ?> onchange="trackField(this)" /><label for="painworst_<?php echo $p; ?>" style="width:28px;height:28px;font-size:11px;"><?php echo $p; ?></label></div>
                    <?php endfor; ?>
                  </div>
                </div>
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Pain Quality'); ?></label>
                <div class="qa-check-group">
                  <?php $qualities = ['Sharp','Dull / Aching','Burning','Shooting','Throbbing','Numbness','Tingling','Stiffness','Spasm','Pressure']; ?>
                  <?php foreach ($qualities as $qi => $q): ?>
                  <div class="qa-check-btn">
                    <input type="checkbox" id="pq_<?php echo $qi; ?>" name="intake_pain_quality" value="<?php echo attr($q); ?>" <?php echo (strpos(','.($form['intake_pain_quality']??'').':', ':'.($q).':')!==false)?'checked':''; ?> onchange="trackField(this)" />
                    <label for="pq_<?php echo $qi; ?>"><?php echo xlt($q); ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Radiation / Referral'); ?></label>
                <div class="qa-radio-group" style="margin-bottom:6px;">
                  <div class="qa-radio-btn"><input type="radio" id="rad_no" name="intake_radiation" value="none" <?php echo (($form['intake_radiation']??'')=='none')?'checked':''; ?> onchange="trackField(this)" /><label for="rad_no"><?php echo xlt('None'); ?></label></div>
                  <div class="qa-radio-btn"><input type="radio" id="rad_arm" name="intake_radiation" value="arm" <?php echo (($form['intake_radiation']??'')=='arm')?'checked':''; ?> onchange="trackField(this)" /><label for="rad_arm"><?php echo xlt('Into Arm'); ?></label></div>
                  <div class="qa-radio-btn"><input type="radio" id="rad_leg" name="intake_radiation" value="leg" <?php echo (($form['intake_radiation']??'')=='leg')?'checked':''; ?> onchange="trackField(this)" /><label for="rad_leg"><?php echo xlt('Into Leg'); ?></label></div>
                  <div class="qa-radio-btn"><input type="radio" id="rad_head" name="intake_radiation" value="head" <?php echo (($form['intake_radiation']??'')=='head')?'checked':''; ?> onchange="trackField(this)" /><label for="rad_head"><?php echo xlt('Into Head'); ?></label></div>
                  <div class="qa-radio-btn"><input type="radio" id="rad_other" name="intake_radiation" value="other" <?php echo (($form['intake_radiation']??'')=='other')?'checked':''; ?> onchange="trackField(this)" /><label for="rad_other"><?php echo xlt('Other'); ?></label></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ────── Section 4: Systems Review ────── -->
        <div class="qa-section" id="sec-systems" data-section="4">
          <div class="qa-section-header" onclick="toggleSection(this)">
            <div class="qa-section-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="3" x2="9" y2="21"/><line x1="15" y1="3" x2="15" y2="21"/></svg>
            </div>
            <div style="flex:1">
              <div class="qa-section-title"><?php echo xlt('Review of Systems'); ?></div>
              <div class="qa-section-subtitle"><?php echo xlt('Symptom screen by body system'); ?></div>
            </div>
            <svg class="qa-section-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="qa-section-body">
            <div class="qa-notice info" style="margin-bottom:12px;">
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
              <span><?php echo xlt('Mark Yes for any symptom present in the past 4 weeks.'); ?></span>
            </div>
            <div class="qa-systems-grid">
              <?php
              $systems = [
                  'Musculoskeletal'  => 'Joint pain, muscle weakness, swelling',
                  'Neurological'     => 'Headache, dizziness, numbness',
                  'Cardiovascular'   => 'Chest pain, palpitations, edema',
                  'Respiratory'      => 'Shortness of breath, cough, wheezing',
                  'Gastrointestinal' => 'Nausea, constipation, abdominal pain',
                  'Genitourinary'    => 'Incontinence, frequency, urgency',
                  'Integumentary'    => 'Skin rash, wounds, bruising',
                  'Endocrine'        => 'Fatigue, weight change, temperature',
                  'Psychological'    => 'Anxiety, depression, sleep issues',
                  'ENT'              => 'Ear pain, tinnitus, visual changes',
                  'Hematologic'      => 'Easy bruising, clotting issues',
                  'Immunologic'      => 'Frequent infections, autoimmune',
              ];
              $si = 0;
              foreach ($systems as $sysName => $hint): $si++;
              ?>
              <div class="qa-system-row" title="<?php echo attr($hint); ?>">
                <span class="qa-system-name"><?php echo xlt($sysName); ?></span>
                <div class="qa-system-yn">
                  <input type="radio" id="sys_<?php echo $si; ?>_n" name="intake_sys_<?php echo strtolower(str_replace([' ','/','-'],'_',$sysName)); ?>" value="no" <?php echo (($form['intake_sys_'.strtolower(str_replace([' ','/','-'],'_',$sysName))]??'')==='no')?'checked':''; ?> onchange="trackField(this)" />
                  <label for="sys_<?php echo $si; ?>_n"><?php echo xlt('N'); ?></label>
                  <input type="radio" id="sys_<?php echo $si; ?>_y" name="intake_sys_<?php echo strtolower(str_replace([' ','/','-'],'_',$sysName)); ?>" value="yes" <?php echo (($form['intake_sys_'.strtolower(str_replace([' ','/','-'],'_',$sysName))]??'')==='yes')?'checked':''; ?> onchange="trackField(this)" />
                  <label for="sys_<?php echo $si; ?>_y"><?php echo xlt('Y'); ?></label>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <div class="qa-field" style="margin-top:12px;">
              <label class="qa-label"><?php echo xlt('Additional Symptoms / Notes'); ?></label>
              <textarea class="qa-textarea" name="intake_sys_notes" rows="2" style="min-height:56px;" placeholder="<?php echo xla('Any other symptoms to note…'); ?>" oninput="trackField(this)"><?php echo text($form['intake_sys_notes'] ?? ''); ?></textarea>
            </div>
          </div>
        </div>

        <!-- ────── Section 5: Functional Status ────── -->
        <div class="qa-section" id="sec-function" data-section="5">
          <div class="qa-section-header" onclick="toggleSection(this)">
            <div class="qa-section-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <div style="flex:1">
              <div class="qa-section-title"><?php echo xlt('Functional Status'); ?></div>
              <div class="qa-section-subtitle"><?php echo xlt('How symptoms affect daily activities'); ?></div>
            </div>
            <svg class="qa-section-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="qa-section-body">
            <?php
            $func_items = [
                'intake_func_standing'  => xlt('Prolonged standing (> 30 min)'),
                'intake_func_sitting'   => xlt('Prolonged sitting (> 30 min)'),
                'intake_func_walking'   => xlt('Walking one block'),
                'intake_func_stairs'    => xlt('Climbing stairs'),
                'intake_func_bending'   => xlt('Bending / stooping'),
                'intake_func_lifting'   => xlt('Lifting (moderate weight)'),
                'intake_func_dressing'  => xlt('Dressing / personal care'),
                'intake_func_driving'   => xlt('Driving / transport'),
                'intake_func_sleep'     => xlt('Sleeping through the night'),
                'intake_func_work'      => xlt('Performing usual work / duties'),
            ];
            $func_levels = [
                'no_difficulty'   => xlt('No difficulty'),
                'mild'            => xlt('Mild'),
                'moderate'        => xlt('Moderate'),
                'severe'          => xlt('Severe'),
                'unable'          => xlt('Unable to do'),
            ];
            ?>
            <div style="display:flex;flex-direction:column;gap:8px;">
              <?php foreach ($func_items as $fname => $flabel): ?>
              <div style="display:flex;align-items:center;gap:12px;padding:8px 12px;background:var(--surface2);border:1.5px solid var(--border);border-radius:8px;flex-wrap:wrap;">
                <span style="font-size:12.5px;font-weight:600;color:#374151;min-width:200px;"><?php echo $flabel; ?></span>
                <div style="display:flex;gap:6px;flex-wrap:wrap;">
                  <?php foreach ($func_levels as $fval => $ftext): ?>
                  <div class="qa-radio-btn">
                    <input type="radio" id="<?php echo attr($fname.'_'.$fval); ?>" name="<?php echo attr($fname); ?>" value="<?php echo attr($fval); ?>" <?php echo (($form[$fname] ?? '') === $fval) ? 'checked' : ''; ?> onchange="trackField(this)" />
                    <label for="<?php echo attr($fname.'_'.$fval); ?>" style="padding:4px 10px;font-size:11.5px;"><?php echo $ftext; ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- ────── Section 6: Goals & Notes ────── -->
        <div class="qa-section" id="sec-goals" data-section="6">
          <div class="qa-section-header" onclick="toggleSection(this)">
            <div class="qa-section-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div style="flex:1">
              <div class="qa-section-title"><?php echo xlt('Goals and Provider Notes'); ?></div>
              <div class="qa-section-subtitle"><?php echo xlt('Treatment objectives and clinical notes'); ?></div>
            </div>
            <svg class="qa-section-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="qa-section-body">
            <div class="qa-grid" style="gap:14px;">
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Patient Goals (what they want to achieve)'); ?></label>
                <div class="qa-check-group">
                  <?php $goals = ['Pain relief','Return to work','Return to sport','Improved mobility','Better sleep','Reduced medication','Improved posture','Independent self-care']; ?>
                  <?php foreach ($goals as $gi => $g): ?>
                  <div class="qa-check-btn">
                    <input type="checkbox" id="goal_<?php echo $gi; ?>" name="intake_goals" value="<?php echo attr($g); ?>" <?php echo (strpos(','.($form['intake_goals']??'').':', ':'.($g).':')!==false)?'checked':''; ?> onchange="trackField(this)" />
                    <label for="goal_<?php echo $gi; ?>"><?php echo xlt($g); ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Expected Timeline for Recovery'); ?></label>
                <select class="qa-select" name="intake_timeline" onchange="trackField(this)">
                  <option value=""><?php echo xlt('Select…'); ?></option>
                  <?php foreach (['< 4 weeks','4–8 weeks','8–12 weeks','3–6 months','Ongoing management'] as $tl): ?>
                  <option value="<?php echo attr($tl); ?>" <?php echo (($form['intake_timeline']??'')===$tl)?'selected':''; ?>><?php echo xlt($tl); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="qa-field">
                <label class="qa-label"><?php echo xlt('Provider Clinical Notes'); ?></label>
                <textarea class="qa-textarea" name="intake_provider_notes" id="qa_provider_notes" rows="4"
                  placeholder="<?php echo xla('Clinical impressions, additional observations, treatment recommendations…'); ?>"
                  oninput="trackField(this)"><?php echo text($form['intake_provider_notes'] ?? ''); ?></textarea>
              </div>
              <div class="qa-grid qa-grid-2">
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Consent to Treat'); ?></label>
                  <div class="qa-yn">
                    <input type="radio" id="consent_y" name="intake_consent" value="yes" <?php echo (($form['intake_consent']??'')=='yes')?'checked':''; ?> onchange="trackField(this)" />
                    <label for="consent_y"><?php echo xlt('Yes'); ?></label>
                    <input type="radio" id="consent_n" name="intake_consent" value="no" <?php echo (($form['intake_consent']??'')=='no')?'checked':''; ?> onchange="trackField(this)" />
                    <label for="consent_n"><?php echo xlt('No'); ?></label>
                  </div>
                </div>
                <div class="qa-field">
                  <label class="qa-label"><?php echo xlt('Form Completed By'); ?></label>
                  <select class="qa-select" name="intake_completed_by" onchange="trackField(this)">
                    <option value="provider" <?php echo (($form['intake_completed_by']??'')=='provider'||empty($form['intake_completed_by']))?'selected':''; ?>><?php echo xlt('Provider / Clinician'); ?></option>
                    <option value="patient" <?php echo (($form['intake_completed_by']??'')=='patient')?'selected':''; ?>><?php echo xlt('Patient'); ?></option>
                    <option value="staff" <?php echo (($form['intake_completed_by']??'')=='staff')?'selected':''; ?>><?php echo xlt('Front desk / Staff'); ?></option>
                  </select>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ────── LForms container (only in update/new_form modes) ────── -->
        <?php if (in_array($mode ?? '', ['update','new_form','new_repository_form'])): ?>
        <div class="qa-section" id="sec-lform" data-section="7">
          <div class="qa-section-header" onclick="toggleSection(this)">
            <div class="qa-section-icon">
              <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            </div>
            <div style="flex:1">
              <div class="qa-section-title"><?php echo text($form['form_name'] ?: $form_name ?: xlt('Assessment Form')); ?></div>
              <div class="qa-section-subtitle"><?php echo xlt('LOINC / FHIR structured questionnaire'); ?></div>
            </div>
            <svg class="qa-section-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="qa-section-body">
            <div id="formContainer"></div>
          </div>
        </div>
        <?php else: ?>
        <!-- formContainer needed by LForms JS even when not shown -->
        <div id="formContainer" style="display:none;"></div>
        <?php endif; ?>

        <!-- Hidden form_name field for LForms compatibility -->
        <input type="hidden" id="form_name" name="form_name" value="<?php echo attr($form['form_name'] ?: $form_name); ?>" />

      </form><!-- #qa_form -->

    </div><!-- .qa-content -->
  </div><!-- .qa-main -->
</div><!-- .qa-shell -->

<!-- ── Floating save bar ── -->
<div class="qa-save-bar" id="qaSaveBar">
  <div class="qa-save-bar-left">
    <div class="qa-save-bar-status" id="saveStatus">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <?php echo xlt('Unsaved changes'); ?>
    </div>
    <div style="font-size:11px;color:var(--text-muted);" id="fieldProgress">
      <?php echo xlt('Fill in sections above to complete the questionnaire'); ?>
    </div>
  </div>
  <div class="qa-save-bar-right">
    <?php if (!$isPortal && !$patientPortalOther): ?>
      <button type="button" class="qa-btn qa-btn-ghost" onclick="parent.closeTab ? parent.closeTab(window.name,false) : history.back()">
        <?php echo xlt('Discard'); ?>
      </button>
      <button type="button" class="qa-btn qa-btn-outline" id="save_registry" name="save_registry"
              title="<?php echo xla('Register as a new encounter form for reuse in any encounter.'); ?>"
              onclick="formMode='register';document.getElementById('qa_form').submit()">
        <?php echo xlt('Register New'); ?>
      </button>
      <button type="submit" form="qa_form" class="qa-btn qa-btn-primary" id="save_response"
              title="<?php echo xla('Save questionnaire responses for this encounter.'); ?>">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo xlt('Save Questionnaire'); ?>
      </button>
    <?php endif; ?>
  </div>
</div>

<script>
/* ══ Section toggle ════════════════════════════════════ */
function toggleSection(headerEl) {
    var sec = headerEl.closest('.qa-section');
    sec.classList.toggle('collapsed');
    updateRailFromScroll();
}

/* ══ Scroll to section ════════════════════════════════ */
function scrollToSection(id) {
    var el = document.getElementById(id);
    if (!el) return;
    if (el.classList.contains('collapsed')) toggleSection(el.querySelector('.qa-section-header'));
    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    setActiveStep(id);
}

/* ══ Active rail step ════════════════════════════════ */
function setActiveStep(sectionId) {
    document.querySelectorAll('.qa-step').forEach(function(s) { s.classList.remove('active'); });
    var step = document.getElementById('rail-' + sectionId);
    if (step) step.classList.add('active');
}

/* ══ Scroll spy ══════════════════════════════════════ */
function updateRailFromScroll() {
    var sections = document.querySelectorAll('.qa-section');
    var topBar = 80;
    var current = null;
    sections.forEach(function(s) {
        var rect = s.getBoundingClientRect();
        if (rect.top <= topBar + 40) current = s.id;
    });
    if (current) setActiveStep(current);
}
document.getElementById('qaContent').addEventListener('scroll', updateRailFromScroll);
window.addEventListener('scroll', updateRailFromScroll);

/* ══ Pain label text ════════════════════════════════ */
var painLabels = ['No pain','Minimal','Mild','Mild-Moderate','Moderate','Moderate','Moderate-Severe','Severe','Very Severe','Extremely Severe','Worst Possible'];
function updatePainLabel(val) {
    var el = document.getElementById('painLabelText');
    if (el) el.textContent = val + ' — ' + (painLabels[val] || '');
}

/* ══ Body region chips ══════════════════════════════ */
function toggleBodyChip(chip) {
    chip.classList.toggle('selected');
    var selected = [];
    document.querySelectorAll('.qa-body-chip.selected').forEach(function(c) { selected.push(c.dataset.value); });
    document.getElementById('qa_body_regions').value = selected.join(', ');
    updateProgress();
}

/* ══ Field tracking & progress ════════════════════ */
var _filledFields = new Set();
function trackField(el) {
    var val = (el.type === 'checkbox' || el.type === 'radio') ? el.checked : (el.value || '').trim();
    if (val) { _filledFields.add(el.name || el.id); }
    updateProgress();
    markSectionDone(el);
}

function markSectionDone(el) {
    var sec = el.closest('.qa-section');
    if (!sec) return;
    var step = document.getElementById('rail-' + sec.id);
    if (step) step.classList.add('done');
}

var _totalFields = 0;
function updateProgress() {
    _totalFields = document.querySelectorAll('[name^="intake_"]').length;
    var pct = _totalFields > 0 ? Math.min(100, Math.round((_filledFields.size / _totalFields) * 100)) : 0;
    var fill = document.getElementById('progressFill');
    var pctEl = document.getElementById('progressPct');
    if (fill) fill.style.width = pct + '%';
    if (pctEl) pctEl.textContent = pct + '%';
    var fp = document.getElementById('fieldProgress');
    if (fp) fp.textContent = _filledFields.size + ' / ' + _totalFields + ' ' + xl('fields filled');
    if (pct === 100) markSavedStatus(true);
}

function markSavedStatus(saved) {
    var el = document.getElementById('saveStatus');
    if (!el) return;
    if (saved) {
        el.classList.add('saved');
        el.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> <?php echo xlt("Complete"); ?>';
    }
}


/* ══ xl helper for JS ════════════════════════════════ */
function xl(s) { return s; }

/* ══ Init — body region chips from saved value ═══════ */
window.addEventListener('DOMContentLoaded', function() {
    setActiveStep('sec-chief');
    updateProgress();

    // Restore body region chip visual state from the hidden input (PHP already set the value)
    var brEl = document.getElementById('qa_body_regions');
    if (brEl && brEl.value) {
        var selected = brEl.value.split(', ');
        document.querySelectorAll('.qa-body-chip').forEach(function(chip) {
            if (selected.indexOf(chip.dataset.value) !== -1) {
                chip.classList.add('selected');
            }
        });
    }

    // Restore pain label if pain level is pre-selected
    var checkedPain = document.querySelector('[name="intake_pain_level"]:checked');
    if (checkedPain) updatePainLabel(parseInt(checkedPain.value));

    // Mark sections done for any pre-populated fields
    document.querySelectorAll('[name^="intake_"]').forEach(function(el) {
        if ((el.type === 'radio' || el.type === 'checkbox') ? el.checked : el.value) {
            markSectionDone(el);
        }
    });
});
</script>
    <!-- Below scripts must be in body. -->
    <!--<script src="<?php /*echo $GLOBALS['assets_static_relative']; */ ?>/lforms/webcomponent/assets/lib/zone.min.js"></script>
    <script src="<?php /*echo $GLOBALS['assets_static_relative']; */ ?>/lforms/webcomponent/scripts.js"></script>
    <script src="<?php /*echo $GLOBALS['assets_static_relative']; */ ?>/lforms/webcomponent/runtime-es2015.js"></script>
    <script src="<?php /*echo $GLOBALS['assets_static_relative']; */ ?>/lforms/webcomponent/polyfills-es2015.js"></script>
    <script src="<?php /*echo $GLOBALS['assets_static_relative']; */ ?>/lforms/webcomponent/main-es2015.js"></script>
    <script src="<?php /*echo $GLOBALS['assets_static_relative']; */ ?>/lforms/fhir/R4/lformsFHIR.min.js"></script>-->

    <!-- TODO Temporary dependencies location -->
    <?php require(__DIR__ . "/../../forms/questionnaire_assessments/lform_webcomponents.php") ?>
    <!-- Dependency scopes seem strange using the way we have to implement the necessary web components. -->
    <?php Header::setupAssets(['select2', 'bootstrap']); ?>
    <script>
        <?php if ($isPortal || $patientPortalOther) { ?>
        $(function () {
            window.addEventListener("message", (e) => {
                if (e.origin !== window.location.origin) {
                    syncAlertMsg(<?php echo xlj("Request is not same origin!"); ?>, 15000);
                    return false;
                }
                if (e.data.submitForm === true) {
                    let pass = saveQR();
                    if (pass) {
                        e.preventDefault();
                        document.forms[0].submit();
                    } else {
                        syncAlertMsg(<?php echo xlj("Form validation failed."); ?>);
                        return false;
                    }
                }
            });
        });
        <?php }
        if (($mode == "update") && $patientPortalOther && !empty($formid)) { ?>
        parent.postMessage({formid:<?php echo attr($formid) ?>}, window.location.origin);
        <?php } ?>
    </script>
</body>
<script>
    /*
    * mode update = existing form edit
    * mode new = new registry form being added
    * mode new_form = a new registered form for first edit
    * mode new_repository_form = a new registered form selected from Questionnaire Repository.
    * */
    let formMode = <?php echo js_escape($mode); ?>;
    <?php if ($mode == 'update') { ?>
    window.onload = initUpdate();
    <?php } elseif ($mode == 'new') { ?>
    window.onload = initSearch();
    <?php } elseif ($mode == 'new_form') { ?>
    window.onload = initNewForm();
    <?php } elseif ($mode == 'new_repository_form') { ?>
    window.onload = initSearchForm();
    <?php } ?>
</script>
</html>
