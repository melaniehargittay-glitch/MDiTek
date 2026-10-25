<?php

/**
 * forms.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018-2021 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/encounter.inc.php");
require_once("$srcdir/group.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/amc.php");
require_once($GLOBALS['srcdir'] . '/ESign/Api.php');
require_once("$srcdir/../controllers/C_Document.class.php");

use ESign\Api;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Events\Encounter\EncounterMenuEvent;
use OpenEMR\Services\EncounterService;
use OpenEMR\Services\UserService;
use Symfony\Component\EventDispatcher\EventDispatcher;

$expand_default = (int)$GLOBALS['expand_form'] ? 'show' : 'hide';
$reviewMode = false;
if (!empty($_REQUEST['review_id'])) {
    $reviewMode = true;
    $encounter = sanitizeNumber($_REQUEST['review_id']);
}

// Re-sync encounter from session if it differs (catches stale globals.php cache)
if (empty($reviewMode) && !empty($_SESSION['encounter']) && (int)$_SESSION['encounter'] !== (int)$encounter) {
    $encounter = (int)$_SESSION['encounter'];
    $GLOBALS['encounter'] = $encounter;
}

$is_group = ($attendant_type == 'gid') ? true : false;
if ($attendant_type == 'gid') {
    $groupId = $therapy_group;
}
$attendant_id = $attendant_type == 'pid' ? $pid : $therapy_group;
if ($is_group && !AclMain::aclCheckCore("groups", "glog", false, array('view','write'))) {
    echo xlt("access not allowed");
    exit();
}

if ($GLOBALS['kernel']->getEventDispatcher() instanceof EventDispatcher) {
    /**
     * @var EventDispatcher
     */
    $eventDispatcher = $GLOBALS['kernel']->getEventDispatcher();
} else {
    throw new Exception("Could not get EventDispatcher from kernel", 1);
}

// Pre-fetch data needed for print header BEFORE HTML output
// Query directly from form_encounter by pid+encounter — same reliable approach as fee sheet
$enrow = sqlQuery(
    "SELECT fe.date FROM form_encounter fe
     JOIN forms f ON fe.id = f.form_id
     WHERE f.pid = ? AND f.encounter = ? AND f.formdir = 'newpatient' AND f.deleted = 0
     LIMIT 1",
    [$pid, $encounter]
);
if (empty($enrow) || empty($enrow['date']) || strtotime($enrow['date']) <= 0) {
    $enrow = sqlQuery(
        "SELECT date FROM form_encounter WHERE pid = ? AND encounter = ? LIMIT 1",
        [$pid, $encounter]
    );
}
$encounter_date = (!empty($enrow['date']) && strtotime($enrow['date']) > 0)
    ? date("Y-m-d", strtotime($enrow['date']))
    : date("Y-m-d");
$providerIDres  = getProviderIdOfEncounter($encounter);
$providerNameRes = getProviderName($providerIDres, false);
$patientName    = getPatientFullNameAsString($pid);
$patData        = getPatientData($pid);
$encData        = sqlQuery("SELECT reason FROM form_encounter WHERE encounter = ? AND pid = ?", [$encounter, $pid]);
// Fetch actual esign signature datetime + signer from DB (static, won't change on reload)
$esignSigRow    = sqlQuery(
    "SELECT es.datetime, es.uid,
            CONCAT(u.fname, ' ', u.lname) AS signer_name
     FROM esign_signatures es
     LEFT JOIN users u ON u.id = es.uid
     WHERE es.tid = ? AND es.`table` = 'form_encounter'
     ORDER BY es.id DESC LIMIT 1",
    [$encounter]
);
$esignSignedAt  = !empty($esignSigRow['datetime'])
    ? date('m/d/Y g:i A', strtotime($esignSigRow['datetime']))
    : '';
// Signer full name: prefer the users table lookup, fall back to provider name
$esignSignerName = (!empty($esignSigRow['signer_name']) && trim($esignSigRow['signer_name']) !== '')
    ? trim($esignSigRow['signer_name'])
    : $providerNameRes;

// ── Insurance data (primary / secondary / tertiary) ──────────────────────────
// Only include insurance rows that were active as of the encounter date:
//   • start date (date) must be on or before the encounter date — already
//     handled by the existing `ii.date <= ?` condition below.
//   • end date (date_end) must be NULL (still active / no expiry recorded)
//     OR must be on or after the encounter date — this is the new condition
//     that suppresses plans that had already expired by the time of the visit.
// Using $encounter_date (the actual visit date, pre-fetched above) rather than
// CURDATE() so that historical encounters correctly show the insurance that was
// in force at the time, not whatever happens to be active today.
$ins_types = ['primary' => 1, 'secondary' => 2, 'tertiary' => 3];
$insurances = [];
foreach ($ins_types as $type => $rank) {
    $insRow = sqlQuery(
        "SELECT ii.type, ii.provider AS ins_id, ii.plan_name, ii.policy_number, ii.group_number,
                ii.subscriber_relationship,
                ic.name AS company_name
         FROM insurance_data ii
         LEFT JOIN insurance_companies ic ON ic.id = ii.provider
         WHERE ii.pid = ? AND ii.type = ?
           AND (ii.date IS NULL OR ii.date <= ?)
           AND (ii.date_end IS NULL OR ii.date_end >= ?)
         ORDER BY ii.date DESC LIMIT 1",
        [$pid, $type, $encounter_date, $encounter_date]
    );
    if (!empty($insRow['ins_id'])) {
        $insurances[$type] = $insRow;
    }
}
$primaryIns = $insurances['primary'] ?? null;
$secondaryIns = $insurances['secondary'] ?? null;
$tertiaryIns  = $insurances['tertiary'] ?? null;

// ── Accident / MVA data from form_misc_billing_options ───────────────────────
// The table has NO 'encounter' column. It links via: forms.form_id = form_misc_billing_options.id
// Confirmed schema: id, pid, employment_related, auto_accident, accident_state,
//                   other_accident, onset_date, injury_date (no encounter column)
$acc_date  = '';
$acc_state = '';
$acc_emp   = '';
$acc_other = '';
$acc_auto  = '';
$has_box10 = false; // only true when MBO box 10 has an affirmative accident flag

$_mboRow = sqlQuery(
  "SELECT mbo.auto_accident,
          mbo.employment_related,
          mbo.other_accident,
          mbo.accident_state,
          mbo.onset_date
   FROM form_misc_billing_options mbo
   INNER JOIN forms f ON f.form_id = mbo.id
   WHERE f.pid = ?
     AND f.encounter = ?
     AND f.formdir = 'misc_billing_options'
     AND f.deleted = 0
   LIMIT 1",
  [$pid, $encounter]

);
if (!empty($_mboRow)) {
    $acc_auto  = $_mboRow['auto_accident']      ?? '';
    $acc_emp   = $_mboRow['employment_related'] ?? '';
    $acc_other = $_mboRow['other_accident']     ?? '';
    $acc_state = $_mboRow['accident_state']     ?? '';
    // injury_date = actual accident/injury date; onset_date = symptom onset

    // Box 10 is "filled" when any accident flag is affirmative
    $has_box10 =
        in_array(strtolower((string)$acc_auto),  ['yes','1','y'], true) ||
        in_array(strtolower((string)$acc_emp),   ['yes','1','y'], true) ||
        in_array(strtolower((string)$acc_other), ['yes','1','y'], true);
    $acc_date = (!empty($_mboRow['onset_date']) && $_mboRow['onset_date'] !== '0000-00-00')
    ? $_mboRow['onset_date']
    : '';
}

// Fallback: pull onset_date from form_encounter if still empty
if (empty($acc_date)) {
    $_feOnset = sqlQuery(
        "SELECT onset_date FROM form_encounter WHERE pid = ? AND encounter = ? LIMIT 1",
        [$pid, $encounter]
    );
    if (!empty($_feOnset['onset_date']) && $_feOnset['onset_date'] !== '0000-00-00') {
        $acc_date = $_feOnset['onset_date'];
    }
}

// ── Imaging findings (needed in print header + workflow cards) ────────────────
$imaging_findings_row = null;
try {
    $imaging_findings_row = sqlQuery(
        "SELECT findings, created_at FROM mdi_imaging_findings
          WHERE pid=? AND encounter=? AND findings != '' LIMIT 1",
        [$pid, $encounter]
    );
} catch (\Throwable $e) { /* table may not exist yet */ }
$imaging_reviewed = !empty($imaging_findings_row['findings']);

// ── Diagnosis codes (needed in print header) ──────────────────────────────────
$diagRows = [];
$_dr = sqlStatement(
    "SELECT diagnosis, title, comments FROM lists
      WHERE pid=? AND activity=1
        AND (enddate IS NULL OR DATE(enddate) = '0000-00-00' OR DATE(enddate) > CURDATE())
        AND diagnosis IS NOT NULL AND diagnosis != ''
      ORDER BY begdate DESC",
    [$pid]
);
while ($_drow = sqlFetchArray($_dr)) {
    $diagRows[] = [
        'diagnosis' => $_drow['diagnosis'],
        'title'     => !empty($_drow['title']) ? $_drow['title'] : ($_drow['comments'] ?? ''),
    ];
}
$_bdr = sqlStatement(
    "SELECT b.code AS diagnosis, b.code_text AS title FROM billing b
      WHERE b.pid=? AND b.encounter=? AND b.code_type='ICD10' AND b.activity=1",
    [$pid, $encounter]
);
while ($_bdrow = sqlFetchArray($_bdr)) { $diagRows[] = $_bdrow; }
// Deduplicate by diagnosis code
$_seen = []; $diagRowsUniq = [];
foreach ($diagRows as $_r) {
    if (!isset($_seen[$_r['diagnosis']])) { $_seen[$_r['diagnosis']] = true; $diagRowsUniq[] = $_r; }
}

// ── Facility for print header ─────────────────────────────────────────────────
$facilityRow = sqlQuery(
    "SELECT f.name, f.street, f.city, f.state, f.postal_code
       FROM facility f
       JOIN form_encounter fe ON fe.facility_id = f.id
      WHERE fe.encounter=? AND fe.pid=? LIMIT 1",
    [$encounter, $pid]
);
$posRow = sqlQuery("SELECT pos_code FROM form_encounter WHERE encounter=? AND pid=? LIMIT 1", [$encounter, $pid]);
?>

<!DOCTYPE html>
<html>

<head>

<?php require $GLOBALS['srcdir'] . '/js/xl/dygraphs.js.php'; ?>

<?php Header::setupHeader(['common','esign','dygraphs']); ?>
<title><?php echo text($encounter_date . ' Encounter'); ?></title>
<script>
(function() {
    var label = <?php echo js_escape($encounter_date . ' Encounter'); ?>;
    var encounterId = '<?php echo attr($encounter); ?>';
    function updateTab() {
        document.title = label;
        // forms.php lives inside an iframe (encounter_top) inside the 'enc' tab iframe
        // Walk all the way up to the true top window
        var topWin = window;
        try { while (topWin.parent && topWin.parent !== topWin) topWin = topWin.parent; } catch(e) {}
        try {
            if (!topWin.app_view_model) return;
            var tabs = topWin.app_view_model.application_data.tabs.tabsList();
            var bestTab = null;

            for (var i = 0; i < tabs.length; i++) {
                var t = tabs[i];
                // name() is the Knockout observable for the iframe name (e.g. 'enc')
                var tName = (typeof t.name === 'function') ? t.name() : (t.name || '');
                if (tName !== 'enc') continue;

                // url() is the Knockout observable — check for our encounter ID in the URL
                var tUrl = (typeof t.url === 'function') ? t.url() : (t.url || '');
                if (tUrl.indexOf('set_encounter=' + encounterId) !== -1 ||
                    tUrl.indexOf('encounter=' + encounterId) !== -1) {
                    // Exact match — update and stop
                    if (typeof t.title === 'function') t.title(label);
                    return;
                }
                // Fallback: first enc tab found
                if (!bestTab) bestTab = t;
            }

            // No URL match (e.g. URL not yet set) — fall back to the visible enc tab
            for (var j = 0; j < tabs.length; j++) {
                var tv = tabs[j];
                var tvName = (typeof tv.name === 'function') ? tv.name() : (tv.name || '');
                var tvVisible = (typeof tv.visible === 'function') ? tv.visible() : true;
                if (tvName === 'enc' && tvVisible) {
                    bestTab = tv;
                    break;
                }
            }
            if (bestTab && typeof bestTab.title === 'function') bestTab.title(label);
        } catch(e) {}
    }
    updateTab();
    window.addEventListener('load', updateTab);
    setTimeout(updateTab, 300);
    setTimeout(updateTab, 1000);
})();
</script>

<?php
$esignApi = new Api();
?>

<?php // if the track_anything form exists, then include the styling and js functions (and js variable) for graphing
if (file_exists(dirname(__FILE__) . "/../../forms/track_anything/style.css")) { ?>
 <script>
 var csrf_token_js = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
 </script>
 <script src="<?php echo $GLOBALS['web_root']?>/interface/forms/track_anything/report.js"></script>
 <link rel="stylesheet" href="<?php echo $GLOBALS['web_root']?>/interface/forms/track_anything/style.css">
<?php } ?>

<?php
// If the user requested attachment of any orphaned procedure orders, do it.
if (!empty($_GET['attachid'])) {
    $attachid = explode(',', $_GET['attachid']);
    foreach ($attachid as $aid) {
        $aid = intval($aid);
        if (!$aid) {
            continue;
        }
        $tmp = sqlQuery(
            "SELECT COUNT(*) AS count FROM procedure_order WHERE " .
            "procedure_order_id = ? AND patient_id = ? AND encounter_id = 0 AND activity = 1",
            array($aid, $pid)
        );
        if (!empty($tmp['count'])) {
              sqlStatement(
                  "UPDATE procedure_order SET encounter_id = ? WHERE " .
                  "procedure_order_id = ? AND patient_id = ? AND encounter_id = 0 AND activity = 1",
                  array($encounter, $aid, $pid)
              );
              addForm($encounter, "Procedure Order", $aid, "procedure_order", $pid, $userauthorized);
        }
    }
}
?>

<script>
$(function () {
    var formConfig = <?php echo $esignApi->formConfigToJson(); ?>;
    $(".esign-button-form").esign(
        formConfig,
        {
            afterFormSuccess : function( response ) {
                if ( response.locked ) {
                    var editButtonId = "form-edit-button-"+response.formDir+"-"+response.formId;
                    $("#"+editButtonId).replaceWith( response.editButtonHtml );
        
                }

                var logId = "esign-signature-log-"+response.formDir+"-"+response.formId;
                $.post( formConfig.logViewAction, response, function( html ) {
                    $("#"+logId).replaceWith( html );
                });
            }
        }
    );

    // NOTE: the native .esign-button-encounter password-prompt binding has
    // been removed — encounter signing now goes through the PIN flow
    // (mdiSignEncounterClick / verify_pin.php) instead. The per-form
    // .esign-button-form binding above is untouched.

    $("#prov_edu_res").click(function() {
        if ( $('#prov_edu_res').prop('checked') ) {
            var mode = "add";
        }
        else {
            var mode = "remove";
        }
        top.restoreSession();
        $.post( "../../../library/ajax/amc_misc_data.php",
            { amc_id: "patient_edu_amc",
              complete: true,
              mode: mode,
              patient_id: <?php echo js_escape($pid); ?>,
              object_category: "form_encounter",
              object_id: <?php echo js_escape($encounter); ?>,
              csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
            }
        );
    });

    $("#provide_sum_pat_flag").click(function() {
        if ( $('#provide_sum_pat_flag').prop('checked') ) {
            var mode = "add";
        }
        else {
            var mode = "remove";
        }
        top.restoreSession();
        $.post( "../../../library/ajax/amc_misc_data.php",
            { amc_id: "provide_sum_pat_amc",
              complete: true,
              mode: mode,
              patient_id: <?php echo js_escape($pid); ?>,
              object_category: "form_encounter",
              object_id: <?php echo js_escape($encounter); ?>,
              csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
            }
        );
    });

    $("#trans_trand_care").click(function() {
        if ( $('#trans_trand_care').prop('checked') ) {
            var mode = "add";
            // Enable the reconciliation checkbox
            $("#med_reconc_perf").removeAttr("disabled");
        $("#soc_provided").removeAttr("disabled");
        }
        else {
            var mode = "remove";
            //Disable the reconciliation checkbox (also uncheck it if applicable)
            $("#med_reconc_perf").attr("disabled", true);
            $("#med_reconc_perf").prop("checked",false);
        $("#soc_provided").attr("disabled",true);
        $("#soc_provided").prop("checked",false);
        }
        top.restoreSession();
        $.post( "../../../library/ajax/amc_misc_data.php",
            { amc_id: "med_reconc_amc",
              complete: false,
              mode: mode,
              patient_id: <?php echo js_escape($pid); ?>,
              object_category: "form_encounter",
              object_id: <?php echo js_escape($encounter); ?>,
              csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
            }
        );
    });

    $("#med_reconc_perf").click(function() {
        if ( $('#med_reconc_perf').prop('checked') ) {
            var mode = "complete";
        }
        else {
            var mode = "uncomplete";
        }
        top.restoreSession();
        $.post( "../../../library/ajax/amc_misc_data.php",
            { amc_id: "med_reconc_amc",
              complete: true,
              mode: mode,
              patient_id: <?php echo js_escape($pid); ?>,
              object_category: "form_encounter",
              object_id: <?php echo js_escape($encounter); ?>,
              csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
            }
        );
    });
    $("#soc_provided").click(function(){
        if($('#soc_provided').prop('checked')){
                var mode = "soc_provided";
        }
        else{
                var mode = "no_soc_provided";
        }
        top.restoreSession();
        $.post( "../../../library/ajax/amc_misc_data.php",
                { amc_id: "med_reconc_amc",
                complete: true,
                mode: mode,
                patient_id: <?php echo js_escape($pid); ?>,
                object_category: "form_encounter",
                object_id: <?php echo js_escape($encounter); ?>,
                csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
                }
        );
    });

     $(".deleteme").click(function(evt) { deleteme(); evt.stopPropogation(); });

<?php
  // If the user was not just asked about orphaned orders, build javascript for that.
if (!isset($_GET['attachid'])) {
    $ares = sqlStatement(
        "SELECT procedure_order_id, date_ordered " .
        "FROM procedure_order WHERE " .
        "patient_id = ? AND encounter_id = 0 AND activity = 1 " .
        "ORDER BY procedure_order_id",
        array($pid)
    );
    echo "  // Ask about attaching orphaned orders to this encounter.\n";
    echo "  var attachid = '';\n";
    while ($arow = sqlFetchArray($ares)) {
        $orderid   = $arow['procedure_order_id'];
        $orderdate = $arow['date_ordered'];
        echo "  if (confirm(" . xlj('There is a lab order') . " + ' ' + " . js_escape($orderid) . " + ' ' + " .
        xlj('dated') . " + ' ' + " . js_escape($orderdate) .  " + ' ' + " .
        xlj('for this patient not yet assigned to any encounter.') . " + ' ' + " .
        xlj('Assign it to this one?') . ")) attachid += " . js_escape($orderid . ",") . ";\n";
    }
    echo "  if (attachid) location.href = 'forms.php?attachid=' + encodeURIComponent(attachid);\n";
}
?>

    <?php if ($reviewMode) { ?>
        $("body table:first").hide();
        $(".encounter-summary-column").hide();
        $(".btn").hide();
        $(".encounter-summary-column:first").show();
        $(".title:first").text(<?php echo xlj("Review"); ?> + " " + $(".title:first").text() + " ( " + <?php echo js_escape($encounter); ?> + " )");
    <?php } ?>
});

 // Process click on Delete link.
 function deleteme() {
  dlgopen('../deleter.php?encounterid=' + <?php echo js_url($encounter); ?> + '&csrf_token_form=' + <?php echo js_url(CsrfUtils::collectCsrfToken()); ?>, '_blank', 500, 200, '', '', {
      allowResize: false,
      allowDrag: true,
  });
  return false;
 }


// create new follow-up Encounter.
function createFollowUpEncounter() {

    <?php
    $result = sqlQuery("SELECT * FROM form_encounter WHERE pid = ? AND encounter = ?", array(
        $_SESSION['pid'],
        $encounter
    ));
    $encounterId = (!empty($result['parent_encounter_id'])) ? $result['parent_encounter_id'] : $result['id'];
    ?>
    var data = {encounterId: '<?php echo attr($encounterId); ?>', mode: 'follow_up_encounter'};
    top.window.parent.newEncounter(data);
}


 // Called by the deleter.php window on a successful delete.
function imdeleted(EncounterId) {
    top.window.parent.left_nav.removeOptionSelected(EncounterId);
    top.window.parent.left_nav.clearEncounter();
    top.encounterList();
}

// Called to open the data entry form a specified encounter form instance.
function openEncounterForm(formdir, formname, formid) {
  var url = <?php echo js_escape($rootdir); ?> + '/patient_file/encounter/view_form.php?formname=' +
      encodeURIComponent(formdir) + '&id=' + encodeURIComponent(formid);

  // Both newpatient (Visit Summary) and questionnaire_assessments (Patient Intake Questionnaire)
  // open in a named tab rather than replacing the current frame
  if (formdir == 'newpatient' || formdir == 'questionnaire_assessments') {
    top.restoreSession();
    var label = formname || (formdir == 'newpatient' ? '<?php echo xla('Visit Summary'); ?>' : '<?php echo xla('Patient Intake Questionnaire'); ?>');
    if (typeof parent.twAddFrameTab === 'function') {
      parent.twAddFrameTab('enctabs', label, url);
    } else {
      location.href = url;
    }
    return false;
  }

  if (!parent.twAddFrameTab) {
    top.restoreSession();
    location.href = url;
  } else {
    parent.twAddFrameTab('enctabs', formname, url);
  }
  return false;
}

// Called when an encounter form may changed something that requires a refresh here.
function refreshVisitDisplay() {
  location.href = <?php echo js_escape($rootdir); ?> + '/patient_file/encounter/forms.php?review_id=' + <?php echo js_escape($encounter); ?>;
}


function printEncounterLetter() {
    top.restoreSession();
    var url = <?php echo js_escape($web_root); ?> + '/interface/patient_file/encounter/mdi_print_report.php'
            + '?pid='       + <?php echo js_escape($pid); ?>
            + '&encounter=' + <?php echo js_escape($encounter); ?>;
    var w = window.open(url, 'mdi_enc_print',
        'width=940,height=720,scrollbars=yes,resizable=yes,menubar=yes,toolbar=yes');
    if (w) { w.focus(); }
}

</script>

<style>

@media screen {
  /* Hide the print report div on screen — shown only in the print window */
  #mdi-print-report { display: none; }
}

@media print {
  /* Fallback if window.print() is called directly on this page */
  @page { size: letter portrait; margin: 0; }
  body > *:not(#mdi-print-report) { display: none !important; }
  #mdi-print-report {
    display: block !important;
    position: absolute !important;
    top: 0; left: 0; width: 100%;
  }
}

    body{
        background: #f3f0f9 !important;
    }
    div.tab {
        min-height: 50px;
        padding: 8px;
    }

    div.form_header {
        float: left;
        min-width: 400px;
    }

    div.form_header_controls {
        float: right;
        margin-bottom: 2px;
        margin-left: 6px;
    }

    div.formname {
        float: left;
        min-width: 160px;
        padding: 0;
        margin: 0;
    }

    .encounter-summary-container {
        float: left;
        width: 100%;
        margin-bottom:35px;
    }

    .encounter-summary-column {
        width: 33.3%;
        float: left;
        display: inline;
        margin-top: 10px;
    }

    #sddm {
        margin: 0;
        padding: 0;
        z-index: 30;
    }

    button:focus {
        outline: none;
    }

    button::-moz-focus-inner {
        border: 0;
    }

    /* ── Sidebar ── */
    .sidebar {
        width: 220px;
        background: #fff;
        border-right: 1px solid #e4ddf5;
        padding: 20px 0 40px;
        overflow-y: auto;
        position: fixed;
        top: 0;
        left: 0;
        height: 100%;
        z-index: 50;
        box-shadow: 2px 0 12px rgba(91,33,182,.06);
    }

    #encounter_forms {
        margin-left: 224px !important;
        margin-right: 0 !important;
        padding: 20px 24px 110px;
        flex-grow: 1;
    }

    /* Section headings */
    .sidebar-section {
        padding: 18px 18px 6px;
        font-size: 10px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #9197b3;
    }
    .sidebar-section:first-child { padding-top: 24px; }

    /* Links */
    .link-item {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 7px 18px;
        margin: 1px 8px;
        border-radius: 7px;
        cursor: pointer;
        transition: background .13s;
    }
    .link-item:hover { background: #ede9fe; }
    .link-item svg {
        width: 14px;
        height: 14px;
        flex-shrink: 0;
        stroke: #5b21b6;
        margin-right: 0;
    }
    .link-item a,
    .link-item a:visited {
        color: #3b1f7a;
        font-size: 12.5px;
        font-weight: 500;
        text-decoration: none;
        line-height: 1.3;
    }
    .link-item:hover a { color: #5b21b6; }

    /* Divider between sections */
    .sidebar-divider {
        height: 1px;
        background: #e4ddf5;
        margin: 10px 18px;
    }

    /* Right sidebar */
    .right-sidebar {
        width: 220px;
        background: #fff;
        border-left: 1px solid #e4ddf5;
        padding: 15px;
        overflow-y: auto;
        position: fixed;
        right: 0;
        top: 0;
        height: 100%;
    }

  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    border-radius: 7px; padding: 7px 14px;
    font-size: 13px; font-weight: 500; cursor: pointer;
    border: none; font-family: inherit; transition: all .15s;
  }
  .btn-outline {
    background: var(--surface); color: var(--text);
    border: 1px solid var(--border);
  }
  .btn-outline:hover { background: var(--surface2); border-color: #c9cdd4; }
  .encounter-title-bar {
    display: flex;                  /* Enable flexbox layout */
    justify-content: space-between; /* Space out items */
    align-items: center;           /* Center items vertically */
    width: 100%;                   /* Make it full width */
    padding: 10px;                /* Add some padding */    /* Optional: background color */
    border-bottom: 1px solid #ccc; /* Optional: border for separation */
}

.encounter-title-bar h5 {
    margin: 0;                    /* Remove default margin */
}

.button-group {
    display: flex;                /* Enable flexbox for button group */
    gap: 10px;                   /* Space between buttons */
}

.button-group .btn {
    /* Optional custom styles for buttons */
    margin-left: 10px;           /* Space buttons from the title */
}

 /* ── AMC Notice ── */
 .amc-notice {
    display:flex !important;
    background: #fffbeb;
    border: 1px solid #fde68a;
    color:#d97706;
    margin-bottom:10px;
    border-radius: 10px;
    padding: 12px 18px;
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; color: var(--amber);
    font-weight: 500;
  }

  .encounter-summary-container {
    /*  display: flex;                 /* Use flexbox for layout */
    justify-content: space-between; /* Space between left and right sections */
    align-items: center;          /* Align items vertically centered */
    width: 100%;                  /* Full width */
    padding: 20px;               /* Padding around the container */
    box-sizing: border-box;       /* Include padding in width calculation */
    background:#f3f0f9;
}

ul#enctabs {
    background:#f3f0f9;
}

.amc-notice {
    display: flex;                /* Use flexbox */
    align-items: center;          /* Center items vertically */
    color: #333;                  /* Text color */
}

.amc-notice svg {
    margin-right: 8px;           /* Space between icon and text */
}

.float-right {
    margin-left: auto;            /* Push the button to the right */
}

button {
    margin-left: 10px;            /* Space buttons from the title */
}

button.btn.btn-outline {
    background: #FFF;
    border:1px solid #e4ddf5;
}

.esign-signature-log-container{
    width:80% !important;
}

 /* ── Status Bar ── */
 .status-bar {
    background: #FFF;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 18px;
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  }
  .status-chip {
    display: flex; align-items: center; gap: 6px;
    font-size: 12.5px; font-weight: 500;
  }
  .status-dot {
    width: 8px; height: 8px; border-radius: 50%;
  }
  .status-dot.open { background: #059669; box-shadow: 0 0 0 2px #d1fae5; }
  .status-dot.unsigned { background: #9ca3af; }

  div#divid_1, div#divid_2, div#divid_3, div#divid_4, div#divid_5,
  div#divid_6, div#divid_7, div#divid_8, div#divid_9, div#divid_10,
  div#divid_11, div#divid_12, div#divid_13, div#divid_14, div#divid_15 {
    background: #fff;
    padding: 18px 20px 20px;
    border-top-left-radius: 0;
    border-top-right-radius: 0;
    border-bottom-left-radius: 12px;
    border-bottom-right-radius: 12px;
    margin-top: 0;
    border: 1.5px solid #e4ddf5;
    border-top: none;
  }

  .border-dark {
    border: 0px !important;
  }

  td.border-bottom.border-dark {
    padding: 14px 18px 14px 16px;
    border-radius: 12px 12px 0 0;
    background: #fff;
    border: 1.5px solid #e4ddf5;
    border-bottom: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 12px;
    transition: box-shadow .15s;
  }
  td.border-bottom.border-dark:hover { box-shadow: 0 4px 16px rgba(91,33,182,.08); }

  /* Collapsed rows (no visible open body) still get a full rounded border
     so they read as a self-contained card rather than a header with a
     missing bottom edge. */
  td.border-bottom.border-dark.mdi-fc-collapsed {
    border-radius: 12px;
    border-bottom: 1.5px solid #e4ddf5;
  }

  /* Circular status icon to the left of the form title, matching the
     Encounter Workflow cards' icon treatment (green check = locked/
     signed, violet pencil = still open/editable). */
  .mdi-fc-icon {
    width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
    display: inline-flex; align-items: center; justify-content: center;
    margin-right: 12px;
  }
  .mdi-fc-icon.locked { background: #d1fae5; }
  .mdi-fc-icon.open   { background: #ede9fe; }

  .mdi-fc-title-wrap { display: flex; align-items: center; min-width: 0; text-decoration: none; cursor: pointer; }
  .mdi-fc-title-wrap:hover { text-decoration: none; }
  .mdi-fc-titles { min-width: 0; }
  .mdi-fc-titles h5 { display: flex; flex-direction: column; gap: 1px; }
  .mdi-fc-titles .mdi-fc-name { font-size: 14px; font-weight: 700; color: #1e0a3c; letter-spacing: -.01em; line-height: 1.3; }
  .mdi-fc-name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
  .mdi-fc-date {
    font-size: 11px; font-weight: 700; color: #059669; background: #d1fae5;
    border-radius: 5px; padding: 1px 7px; line-height: 1.5; white-space: nowrap;
  }
  .mdi-fc-titles .mdi-fc-by   { font-size: 11.5px; font-weight: 500; color: #9197b3; line-height: 1.3; }

  /* Chevron toggle — Bootstrap's collapse plugin automatically adds/
     removes the .collapsed class on every [data-toggle="collapse"]
     trigger sharing a data-target, so this rotates correctly whether the
     row is toggled individually or via Collapse All / Expand All. */
  .mdi-fc-chevron {
    display: inline-flex; align-items: center; justify-content: center;
    width: 28px; height: 28px; border-radius: 7px; border: 1.5px solid #e4ddf5;
    background: #fff; color: #7c6f96; cursor: pointer; flex-shrink: 0;
    transition: background .12s;
  }
  .mdi-fc-chevron:hover { background: #f5f3ff; }
  .mdi-fc-chevron svg { transition: transform .18s; }
  .mdi-fc-chevron:not(.collapsed) svg { transform: rotate(180deg); }

  /* Restyle the generic Bootstrap buttons inside the controls bar to
     match the violet pill-button language used elsewhere on this page,
     without touching any of the PHP logic that decides which buttons
     render (ACL checks, lock state, etc. are untouched). */
  .form_header_controls .btn,
  .form_header_controls a.btn,
  .form_header_controls button.btn {
    font-size: 11.5px !important; font-weight: 600 !important;
    border-radius: 7px !important; padding: 5px 12px !important;
    border: 1.5px solid #ddd6fe !important; background: #f5f3ff !important;
    color: #5b21b6 !important; box-shadow: none !important;
  }
  .form_header_controls .btn:hover,
  .form_header_controls a.btn:hover,
  .form_header_controls button.btn:hover { background: #ede9fe !important; }
  .form_header_controls .btn-danger,
  .form_header_controls .btn-delete {
    background: #fff1f2 !important; border-color: #fecdd3 !important; color: #e11d48 !important;
  }
  .form_header_controls .btn-danger:hover,
  .form_header_controls .btn-delete:hover { background: #ffe4e6 !important; }
  .form_header_controls .form-edit-button-locked {
    background: #d1fae5 !important; border-color: #a7f3d0 !important; color: #047857 !important;
  }
  /* The old text "Expand/Collapse" button is replaced visually by the new
     chevron icon button — hide the text button rather than remove its
     markup, so the existing data-toggle/data-target wiring (and the
     Collapse All / Expand All master buttons that target the same
     .collapse elements) keeps working unchanged. */
  .form_header_controls .collapse-button-form { display: none !important; }

  /* New Expand All / Collapse All toolbar, sits as a clearly-visible
     light-violet band directly above the first form card (Visit
     Summary), right under the Encounter Workflow cards. */
  .mdi-fc-list-toolbar {
    display: flex; align-items: center; justify-content: flex-end;
    gap: 8px; margin: 0 0 10px; padding: 10px 14px;
    background: #f5f3ff; border: 1.5px solid #e4ddf5; border-radius: 10px;
  }

  /* "Collapse All / Expand All" master toggle, restyled to match */
  .mdi-fc-toolbar-btn {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px !important; font-weight: 600 !important;
    border-radius: 7px !important; padding: 6px 13px !important;
    border: 1.5px solid #ddd6fe !important; background: #fff !important;
    color: #5b21b6 !important;
  }
  .mdi-fc-toolbar-btn:hover { background: #ede9fe !important; }


  /* ── eSign Footer ── */
  #esign-main-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(124,58,237,.45) !important;
  }
  .esign-check.confirmed .check-icon {
    background: #059669 !important;
    border-color: #059669 !important;
  }
  .esign-check.confirmed .check-icon svg { stroke: #fff !important; }
  .esign-check.confirmed span:last-child { color: var(--text) !important; font-weight: 600 !important; }
  .notes-dd-open { display:block !important; }

  /* FORCE FORM ACTION BUTTONS ALWAYS VISIBLE */

.form_header_controls,
.form_header_controls.btn-group,
.form_header_controls .btn,
.form_header_controls a,
.form_header_controls button {
    display: inline-flex !important;
    visibility: visible !important;
    opacity: 1 !important;
}

.form_header_controls {
    flex-wrap: wrap !important;
    gap: 6px !important;
    align-items: center !important;
}

/* Prevent Bootstrap collapse from hiding controls */
.form_header_controls.collapse,
.form_header_controls.collapsing {
    display: inline-flex !important;
    height: auto !important;
}

/* Prevent esign JS from replacing/hiding buttons */
.form_header_controls .form-edit-button,
.form_header_controls .collapse-button-form,
.form_header_controls .esign-button-form,
.form_header_controls .btn-edit,
.form_header_controls .btn-delete {
    display: inline-flex !important;
    visibility: visible !important;
    opacity: 1 !important;
}

/* Keep notes dropdown visible */
#notes-dd-wrap,
#notes-dd-wrap * {
    visibility: visible !important;
    opacity: 1 !important;
}

/* Keep encounter title buttons visible */
.encounter-title-bar .btn,
.encounter-title-bar button {
    display: inline-flex !important;
    visibility: visible !important;
    opacity: 1 !important;
}
/* The native encounter eSign button (password prompt) is replaced by our
   own PIN-based "Sign Encounter" button below — keep it hidden even if
   it's ever echoed somewhere, rather than force-visible. */
.esign-button-encounter,
a.esign-button-encounter,
.btn.esign-button-encounter {
    display: none !important;
}
</style>

<!-- *************** -->
<!-- Form menu start -->
<script>

function openNewForm(sel, label) {
    top.restoreSession();
    let FormNameValueArray = sel.split('formname=');
    if (FormNameValueArray[1] == 'newpatient') {
        // TBD: Make this work when it's not the first frame.
        parent.frames[0].location.href = sel;
    } else {
        if (FormNameValueArray[1] == 'questionnaire_assessments') {
            sel += "&questionnaire_form=" + encodeURIComponent(label);
        }
        parent.twAddFrameTab('enctabs', label, sel);
    }
}

function toggleFrame1(fnum) {
  top.frames['left_nav'].document.forms[0].cb_top.checked=false;
  top.window.parent.left_nav.toggleFrame(fnum);
}
</script>
<script>

var timeout = 500;
var closetimer  = 0;
var ddmenuitem  = 0;
var oldddmenuitem = 0;
var flag = 0;

// open hidden layer
function mopen(id) {
    // cancel close timer
    //mcancelclosetime();

    flag = 10;

    // close old layer
    //if(ddmenuitem) ddmenuitem.style.visibility = 'hidden';
    //if(ddmenuitem) ddmenuitem.style.display = 'none';

    // get new layer and show it
    oldddmenuitem = ddmenuitem;
    ddmenuitem = document.getElementById(id);
    if ((ddmenuitem.style.visibility == '')||(ddmenuitem.style.visibility == 'hidden')){
        if (oldddmenuitem) {
            oldddmenuitem.style.visibility = 'hidden';
            oldddmenuitem.style.display = 'none';
        }
        ddmenuitem.style.visibility = 'visible';
        ddmenuitem.style.display = 'block';
    } else {
        ddmenuitem.style.visibility = 'hidden';
        ddmenuitem.style.display = 'none';
    }
}

// close showed layer


// close layer when click-out
document.addEventListener('click', function(e) {

var dd = document.getElementById('notes-dd');

if (!dd) return;

if (!e.target.closest('#notes-dd-wrap')) {
    dd.classList.remove('notes-dd-open');
}
});
//=================================================
function findPosX(id) {
    obj = document.getElementById(id);
    var curleft = 0;
    if(obj.offsetParent) {
        while(1) {
          curleft += obj.offsetLeft;
          if (!obj.offsetParent) {
            break;
          }
          obj = obj.offsetParent;
        }
    } else if(obj.x) {
        curleft += obj.x;
    }

    PropertyWidth = document.getElementById(id).offsetWidth;
    if (PropertyWidth > curleft) {
        document.getElementById(id).style.left = 0;
    }
}

function findPosY(obj) {
    var curtop = 0;
    if(obj.offsetParent) {
        while(1) {
            curtop += obj.offsetTop;
            if(!obj.offsetParent) {
                break;
            }
            obj = obj.offsetParent;
        }
    } else if (obj.y) {
        curtop += obj.y;
    }
    return curtop;
  }
</script><script>
$(document).ready(function() {
    function updatePendingMessage() {
        let allChecked = true;
        $('#amc-requires input[type="checkbox"]').each(function() {
            if (!$(this).is(':checked')) {
                allChecked = false;
            }
        });

        const isLocked = false; // Replace this with your actual check for locked encounters

        if (allChecked || isLocked) {
            $('#amc-notice').hide();  // Hide the entire AMC notice
        } else {
            $('#amc-notice').show();   // Show the notice if requirements are pending
        }
    }

    // Attach change event to checkboxes
    $('#amc-requires input[type="checkbox"]').change(function() {
        updatePendingMessage();
    });

    // Initial call to set the message on page load
    updatePendingMessage();
});
</script>
</head>
<body style="background:#f3f0f9 !important;">
        
<div class="sidebar">

    <div class="sidebar-section"><?php echo xlt('Billing'); ?></div>

    <div class="link-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        <a href="#" onclick="top.restoreSession(); dlgopen('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/encounter/load_form.php?formname=fee_sheet&encounter=<?php echo attr_js($encounter); ?>&pid=<?php echo attr_js($pid); ?>','fee_sheet_modal',1200,750,'','<?php echo xla('Fee Sheet'); ?>',{allowResize:true,allowDrag:true,type:'iframe'}); return false;">
            <?php echo xlt('Add Charges'); ?>
        </a>
    </div>

    <div class="link-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/>
        </svg>
        <a href="#" onclick="top.restoreSession(); dlgopen('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/mdi_payments_ledger.php?pid=<?php echo attr_js($pid); ?>','payment_modal',1100,720,'','<?php echo xla('Payment'); ?>',{allowResize:true,allowDrag:false,type:'iframe'}); return false;">
            <?php echo xlt('Add Payment'); ?>
        </a>
    </div>

    <div class="link-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
        </svg>
        <a href="#" onclick="top.restoreSession(); dlgopen('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_checkout_receipt.php?pid=<?php echo attr_js($pid); ?>','checkout_modal',900,700,'','<?php echo xla('Checkout / Receipts'); ?>',{allowResize:true,allowDrag:false,type:'iframe'}); return false;">
            <?php echo xlt('Receipts / Checkout'); ?>
        </a>
    </div>

    <div class="link-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
            <polyline points="14 2 14 8 20 8"/>
        </svg>
        <a href="#" onclick="openNewForm('/interface/billing/billing_report.php', 'Billing Manager')">
            <?php echo xlt('Billing/DaySheet'); ?>
        </a>
    </div>

    <div class="link-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
        </svg>
        <a href="#" onclick="openNewForm('/interface/patient_file/history/encounters.php', 'Visit History')">
            <?php echo xlt('View Patient History'); ?>
        </a>
    </div>

    <div class="sidebar-divider"></div>
    <div class="sidebar-section"><?php echo xlt('Actions'); ?></div>

    <div class="link-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
            <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
            <line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        <a href="#" onclick="openNewForm('/interface/main/main_info.php', 'Appointments')">
            <?php echo xlt('Schedule Appointment'); ?>
        </a>
    </div>

    <div class="link-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <polyline points="1 4 1 10 7 10"/>
            <path d="M3.51 15a9 9 0 1 0 .49-3.24"/>
        </svg>
        <a href="#" onclick="return createFollowUpEncounter()">
            <?php echo xlt('Create Follow Up Encounter'); ?>
        </a>
    </div>

    <div class="link-item">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M21.44 11.05l-9.19 9.19a6 6 0 01-8.49-8.49l9.19-9.19a4 4 0 015.66 5.66l-9.2 9.19a2 2 0 01-2.83-2.83l8.49-8.48"/>
        </svg>
        <a href="#" onclick="return mdiOpenAttachPanel();">
            <?php echo xlt('Attach Patient Forms'); ?>
        </a>
    </div>

</div>
<nav>

<?php //DYNAMIC FORM RETREIVAL
require_once("$srcdir/registry.inc.php");

$reg = getFormsByCategory();
$old_category = '';
$DivId = 1;

// To see if the encounter is locked. If it is, no new forms can be created
$encounterLocked = false;
$esign = null;
if (isset($GLOBALS['encounter']) && !empty($GLOBALS['encounter'])) {
    $esign = $esignApi->createEncounterESign($GLOBALS['encounter']);
    if ($esignApi->lockEncounters() && $esign && $esign->isLocked()) {
        $encounterLocked = true;
    }
}


// Convert the flat list of menu items into a multi-dimensional array based on the category
// decide what will be displayed (name or nickname)
$eventDispatcher->addListener(EncounterMenuEvent::MENU_RENDER, function (EncounterMenuEvent $menuEvent) {
    $menuArray = $menuEvent->getMenuData();
    $reg = getFormsByCategory();
    foreach ($reg as $item) {
        $tmp = explode('|', $item['aco_spec']);
        if (!empty($tmp[1])) {
            if (!AclMain::aclCheckCore($tmp[0], $tmp[1], '', 'write') && !AclMain::aclCheckCore($tmp[0], $tmp[1], '', 'addonly')) {
                continue;
            }
        }

        $_cat = trim($item['category']);
        $_cat = ($_cat == '') ? xl("Miscellaneous") : xl($_cat);
        $item['displayText'] = (trim($item['nickname'] ?? '') != '') ? trim($item['nickname'] ?? '') : trim($item['name'] ?? '');
        unset($item['category']);
        unset($item['name']);
        unset($item['nickname']);
        $menuArray[$_cat]['children'][] = $item;
    }
    $menuEvent->setMenuData($menuArray);
    return $menuEvent;
});


// Zend Module hooks
$eventDispatcher->addListener(EncounterMenuEvent::MENU_RENDER, function (EncounterMenuEvent $menuEvent) {
    $module_query = sqlStatement("SELECT msh.*, ms.menu_name, ms.path, m.mod_ui_name, m.type
        FROM modules_hooks_settings AS msh
        LEFT OUTER JOIN modules_settings AS ms ON obj_name=enabled_hooks AND ms.mod_id=msh.mod_id
        LEFT OUTER JOIN modules AS m ON m.mod_id=ms.mod_id
        WHERE fld_type=3
            AND mod_active=1
            AND sql_run=1
            AND attached_to='encounter'
        ORDER BY mod_id");

    $menuData = $menuEvent->getMenuData();

    while ($row = sqlFetchArray($module_query)) {
        $_cat = $row['mod_ui_name'];
        if ($row['type'] == 0) {
            $modulePath = $GLOBALS['customModDir'];
            $added = "";
        } else {
            $modulePath = $GLOBALS['zendModDir'];
            $added = "index";
        }

        $relativeLink = "../../modules/{$modulePath}/public/{$row['path']}";
        $row['menu_name'] = $row['menu_name'] ?: "No_Name";
        $row['href'] = $relativeLink;
        $menuData[$_cat]['children'][] = $row;
    }

    $menuEvent->setMenuData($menuData);
    return $menuEvent;
});

// $encounter_date already set correctly at top of file via direct query
$providerIDres = getProviderIdOfEncounter($encounter);
$providerNameRes = getProviderName($providerIDres, false);

// Check for group encounter
if ($attendant_type == 'pid' && is_numeric($pid)) {
    $groupEncounter = false;
    $result = getPatientData($pid, "fname,lname,squad");
    $patientName = getPatientFullNameAsString($pid);
} else {
    $groupEncounter = true;
    $result = getGroup($groupId);
    $patientName = $result['group_name'];
}

// Check for no access to the patient's squad.
if ($result['squad'] && !AclMain::aclCheckCore('squads', $result['squad'])) {
    $pass_sens_squad = false;
}


?>





<div id="encounter_forms" class="mx-1">
<div class='encounter-summary-container'>
    <div>
        <div>
            <?php
            $pass_sens_squad = true;
            //fetch acl for category of given encounter
            $pc_catid = fetchCategoryIdByEncounter($encounter);
            $postCalendarCategoryACO = AclMain::fetchPostCalendarCategoryACO($pc_catid);
            if ($postCalendarCategoryACO) {
                $postCalendarCategoryACO = explode('|', $postCalendarCategoryACO);
                $authPostCalendarCategory = AclMain::aclCheckCore($postCalendarCategoryACO[0], $postCalendarCategoryACO[1]);
                $authPostCalendarCategoryWrite = AclMain::aclCheckCore($postCalendarCategoryACO[0], $postCalendarCategoryACO[1], '', 'write');
            } else { // if no aco is set for category
                $authPostCalendarCategory = true;
                $authPostCalendarCategoryWrite = true;
            }

            // Check for no access to the encounter's sensitivity level.
            $sensitivity = (new EncounterService())->getSensitivity($pid, $encounter);
            if (($sensitivity && !AclMain::aclCheckCore('sensitivities', $sensitivity)) || (!$authPostCalendarCategory ?? '')) {
                $pass_sens_squad = false;
            }
            ?>
        </div>
     
          <!-- Title Bar -->
          <div class="encounter-title-bar">
    <?php
    // Use $encounter_date already set correctly at top of file
    $encounterDate = $encounter_date;
    $encounterType = "Encounter";
    $patientName = getPatientFullNameAsString($pid);
    $encounterTitle = "$encounterDate $encounterType — $patientName";
    echo "<h5>" . text($encounterTitle) . "</h5>";
    ?>
    
    <div class="spacer" style="flex:1"></div>
    <div class="spacer" style="flex:1"></div>
    <button class="btn btn-outline" onclick="printEncounterLetter()" title="Print as letter">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print
    </button>

    <div style="position:relative;display:inline-block;" id="notes-dd-wrap">
  <button class="btn btn-outline" onclick="event.stopPropagation();document.getElementById('notes-dd').classList.toggle('notes-dd-open')">
    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Clinical Notes
    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
  </button>
  <div id="notes-dd" style="display:none;position:absolute;top:calc(100% + 6px);left:0;min-width:200px;background:#fff;border:1px solid #e4ddf5;border-radius:10px;box-shadow:0 8px 24px rgba(124,58,237,.13);z-index:200;overflow:hidden;">
    <a href="#" onclick="event.preventDefault();document.getElementById('notes-dd').classList.remove('notes-dd-open');openNewForm('/interface/patient_file/encounter/load_form.php?formname=soap','Note Templates')"
       style="display:flex;align-items:center;gap:9px;padding:10px 16px;font-size:13px;color:#1a1523;text-decoration:none;transition:background .1s;"
       onmouseover="this.style.background='#f5f3ff'" onmouseout="this.style.background=''">
       <svg width="13" height="13" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Clinical Note Templates
    </a>
    <a href="#" onclick="event.preventDefault();document.getElementById('notes-dd').classList.remove('notes-dd-open');openNewForm('/interface/patient_file/encounter/load_form.php?formname=painmap','Graphic Pain Map')"
       style="display:flex;align-items:center;gap:9px;padding:10px 16px;font-size:13px;color:#1a1523;text-decoration:none;transition:background .1s;"
       onmouseover="this.style.background='#f5f3ff'" onmouseout="this.style.background=''">
      <svg width="13" height="13" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      Graphic Pain Map
    </a>
  </div>
</div>
      
   
        </div>
        </div>

     <div class="encounter-summary-container">

  <!-- Status Bar -->
  <div class="status-bar">
      <div class="status-chip">
        <div class="status-dot open"></div>
        Encounter Open
      </div>
      <?php if ($esign && $esign->isLocked()) : ?>
        <div class="status-chip" id="overall-sig-chip">
          <div class="status-dot unsigned"></div>
          Signed
        </div>
      <?php endif; ?>
      <div class="spacer" style="flex:1"></div>
      <button class="btn mdi-fc-toolbar-btn" onclick="$('.formrow .collapse').collapse('hide');">
        <i class="fa fa-compress-alt"></i> Collapse All
      </button>
      <button class="btn mdi-fc-toolbar-btn" onclick="$('.formrow .collapse').collapse('show');">
        <i class="fa fa-expand-alt"></i> Expand All
      </button>
      <a style="color:red;" href="#" class="nav-link text-danger btn-delete" onclick="return deleteme()">Delete</a>
  </div>

  <!-- Encounter Preview Panel (collapsed by default, sits above AMC notice) -->
  <?php
  // Preview panel needs $questionnaire_forms and $vitals_data — these are built
  // later in the page after the workflow data fetch. We render a lightweight
  // placeholder here and fill it with the same data fetched below.
  ?>
<div class="enc-preview-panel" style="margin-bottom:12px;">
  <div class="enc-preview-header" onclick="var b=this.nextElementSibling;b.classList.toggle('open');this.querySelector('.ep-chevron').style.transform=b.classList.contains('open')?'rotate(180deg)':''">
    <h6>
      <svg width="14" height="14" fill="none" stroke="#fff" stroke-width="2.2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      <?php echo xlt('Encounter Preview'); ?>
      <span style="font-weight:400;opacity:.75;font-size:11px;"><?php echo text('— ' . $encounter_date . ' · ' . $patientName); ?></span>
    </h6>
    <button class="ep-toggle" type="button">
      <svg class="ep-chevron" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="transition:transform .2s;"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
  </div>
  <div class="enc-preview-body" id="enc-preview-body-top">
    <!-- Content injected by PHP below once workflow data is available -->
  </div>
</div>

    <!-- AMC Notice -->
    <?php
    // Whether the encounter is signed. $esign->isLocked() reflects the
    // native vendor eSign flow; $esignSignedAt is computed directly from
    // esign_signatures earlier in this file and is also set by the
    // PIN-based Sign Note/Sign Encounter flow, so check both — otherwise
    // a PIN-signed encounter could still show this notice if the vendor
    // object's own lock state wasn't refreshed.
    $mdiEncounterIsSigned = !empty($esignSignedAt) || ($esign && $esign->isLocked());
    echo '<!-- locked: ' . ($mdiEncounterIsSigned ? 'YES' : 'NO') . ' -->';
?>
    <div  class="amc-notice <?php echo $mdiEncounterIsSigned ? 'd-none' : ''; ?>" id="amc-notice" <?php
    if ($mdiEncounterIsSigned) echo 'style="display:none !important;"';
?>>
    <?php if ($GLOBALS['enable_amc_prompting']) { ?>
        <a class="btn btn-link p-0 m-1" id="amc-requirements-link" data-toggle="collapse" data-target="#amc-requires">
            AMC Requirements
        </a>
        <span id="amc-requirements-text">Review and complete before signing</span>
        
        <div id="amc-requires" class="collapse">
            <table>
                <tr>
                    <td>
                        <?php
                        $itemAMC = amcCollect("patient_edu_amc", $pid, 'form_encounter', $encounter);
                        ?>
                        <input type="checkbox" id="prov_edu_res" <?php echo !empty($itemAMC) ? 'checked' : ''; ?> />
                    </td>
                    <td>
                        <span class="text"><?php echo xlt('Provided Education Resource(s)?') ?></span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <?php
                        $itemAMC = amcCollect("provide_sum_pat_amc", $pid, 'form_encounter', $encounter);
                        ?>
                        <input type="checkbox" id="provide_sum_pat_flag" <?php echo !empty($itemAMC) ? 'checked' : ''; ?> />
                    </td>
                    <td>
                        <span class="text"><?php echo xlt('Provided Clinical Summary?') ?></span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <input type="checkbox" id="trans_trand_care" <?php echo !empty($itemAMC) ? 'checked' : ''; ?> />
                    </td>
                    <td>
                        <span class="text"><?php echo xlt('Transition/Transfer of Care?') ?></span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <?php
                        $itemAMC = amcCollect("med_reconc_amc", $pid, 'form_encounter', $encounter);
                        ?>
                        <input type="checkbox" id="med_reconc_perf" <?php echo !empty($itemAMC['date_completed']) ? 'checked' : ''; ?> />
                    </td>
                    <td>
                        <span class="text"><?php echo xlt('Medication Reconciliation Performed?') ?></span>
                    </td>
                </tr>
                <tr>
                    <td>
                        <input type="checkbox" id="soc_provided" <?php echo !empty($itemAMC['soc_provided']) ? 'checked' : ''; ?> />
                    </td>
                    <td>
                        <span class="text"><?php echo xlt('Summary Of Care Provided?') ?></span>
                    </td>
                </tr>
            </table>
        </div>
    <?php } ?>
</div>

<?php
// ── Fetch workflow data for cards ──────────────────────────────────────────
// Questionnaire — check both questionnaire_assessments and newpatient (Patient Intake Questionnaire)
$q_form = sqlQuery(
    "SELECT f.date, f.user, f.form_id, f.formdir, f.form_name FROM forms f
      WHERE f.pid=? AND f.encounter=? AND f.formdir IN ('questionnaire_assessments','newpatient') AND f.deleted=0
      ORDER BY f.date DESC LIMIT 1",
    [$pid, $encounter]
);
// Vitals
$v_form = sqlQuery(
    "SELECT f.date, f.user, f.form_id FROM forms f WHERE f.pid=? AND f.encounter=? AND f.formdir='vitals' AND f.deleted=0 ORDER BY f.date DESC LIMIT 1",
    [$pid, $encounter]
);
$vitals_data = [];
if (!empty($v_form['form_id'])) {
    $vitals_data = sqlQuery("SELECT * FROM form_vitals WHERE id=?", [$v_form['form_id']]);
}
// X-Ray / Images — simple count for wf-card status only
$xray_count = 0;
$xray_date  = '';
$xr = sqlStatement(
    "SELECT f.date FROM forms f WHERE f.pid=? AND f.encounter=? AND f.formdir IN ('xray','radiology','imaging_report') AND f.deleted=0 ORDER BY f.date DESC",
    [$pid, $encounter]
);
while ($xrrow = sqlFetchArray($xr)) { $xray_count++; if (!$xray_date) $xray_date = $xrrow['date']; }
if (!$xray_count) {
    $xr2 = sqlStatement(
        "SELECT COUNT(*) AS cnt, MAX(d.date) AS dt FROM documents d
          JOIN categories_to_documents cd ON cd.document_id=d.id
          JOIN categories c ON c.id=cd.category_id
          WHERE d.foreign_id=? AND (c.name LIKE '%xray%' OR c.name LIKE '%x-ray%' OR c.name LIKE '%radiolog%' OR c.name LIKE '%imaging%')",
        [$pid]
    );
    if ($r2 = sqlFetchArray($xr2)) { $xray_count = (int)$r2['cnt']; $xray_date = $r2['dt']; }
}
// Also check encounter imaging documents (our custom upload) for this encounter
if (!$xray_count) {
    $xr3 = sqlStatement(
        "SELECT COUNT(*) AS cnt, MAX(date) AS dt FROM documents
          WHERE foreign_id=? AND encounter_id=? AND deleted=0 AND type='file_url'",
        [$pid, $encounter]
    );
    if ($r3 = sqlFetchArray($xr3)) { $xray_count = (int)$r3['cnt']; if ($r3['cnt']) $xray_date = $r3['dt']; }
}
// $imaging_findings_row and $imaging_reviewed already fetched at top of file
// SOAP note
$soap_form = sqlQuery(
    "SELECT f.date, f.user, f.form_id FROM forms f WHERE f.pid=? AND f.encounter=? AND f.formdir='soap' AND f.deleted=0 ORDER BY f.date DESC LIMIT 1",
    [$pid, $encounter]
);
// Review of Systems
$ros_form = sqlQuery(
    "SELECT f.date, f.user, f.form_id FROM forms f WHERE f.pid=? AND f.encounter=? AND f.formdir='ros' AND f.deleted=0 ORDER BY f.date DESC LIMIT 1",
    [$pid, $encounter]
);
// Encounter signed?
$enc_signed = !empty($esignSigRow['datetime']);

// ── Additional questionnaire forms ──────────────────────────────────────────
$questionnaire_forms = [];
// ── Helper: resolve display label for any formdir ─────────────────────────────
function mdi_form_label(string $formdir, string $form_name): string {
    if ($formdir === 'newpatient')                return xl('New Patient Encounter');
    if ($formdir === 'questionnaire_assessments') return xl('Patient Intake Questionnaire');
    if ($formdir === 'mditek_imaging_findings')   return xl('Imaging Findings');
    $resolved = xl_form_title($form_name);
    return (trim($resolved) !== '') ? $resolved : xl($form_name ?: 'Unknown Form');
}
$qall = sqlStatement(
    "SELECT f.form_id, f.formdir, f.form_name, f.date, f.user FROM forms f
      WHERE f.pid=? AND f.encounter=? AND f.formdir IN ('questionnaire_assessments','newpatient') AND f.deleted=0
      ORDER BY f.date ASC",
    [$pid, $encounter]
);
while ($qrow = sqlFetchArray($qall)) {
    $qrow['form_name'] = mdi_form_label($qrow['formdir'], $qrow['form_name'] ?? '');
    $questionnaire_forms[] = $qrow;
}

// Also pick up any LBF questionnaire-type forms
$lbf_q = sqlStatement(
    "SELECT f.form_id, f.formdir, f.form_name, f.date, f.user FROM forms f
      WHERE f.pid=? AND f.encounter=? AND f.formdir LIKE 'LBF%' AND f.deleted=0
        AND f.form_name NOT IN ('','newpatient')
        AND f.formdir NOT IN ('LBFref','LBFbilling','LBFclaim')
      ORDER BY f.date ASC",
    [$pid, $encounter]
);
while ($lrow = sqlFetchArray($lbf_q)) { $questionnaire_forms[] = $lrow; }

// Helper: status info for a form
function wf_status($row, $label) {
    if (empty($row)) return ['status'=>'not_started','label'=>'Not Started','date'=>'','class'=>'wf-ns'];
    $d = !empty($row['date']) ? date('m/d/Y g:i A', strtotime($row['date'])) : '';
    return ['status'=>'done','label'=>'Completed','date'=>$d,'class'=>'wf-done'];
}
function wf_soap($row) {
    if (empty($row)) return ['status'=>'not_started','label'=>'Not Started','date'=>'','class'=>'wf-ns'];
    $d = !empty($row['date']) ? date('m/d/Y g:i A', strtotime($row['date'])) : '';
    // Check SOAP progress heuristically via the date — if recent it may be in progress
    return ['status'=>'done','label'=>'Completed','date'=>$d,'class'=>'wf-done'];
}

$wf_q    = wf_status($q_form,    'Questionnaire');
$wf_v    = wf_status($v_form,    'Vitals');
$wf_ros  = wf_status($ros_form,  'Review of Systems');
// Imaging card: green if findings were saved, amber if images exist but no findings, grey if nothing
if ($imaging_reviewed) {
    $wf_x = ['status'=>'done','label'=>'Reviewed','date'=>(!empty($imaging_findings_row['created_at'])?date('m/d/Y g:i A',strtotime($imaging_findings_row['created_at'])):''),'class'=>'wf-done'];
} elseif ($xray_count > 0) {
    $wf_x = ['status'=>'prog','label'=>$xray_count.' Image(s) — Findings Pending','date'=>(!empty($xray_date)?date('m/d/Y g:i A',strtotime($xray_date)):''),'class'=>'wf-prog'];
} else {
    $wf_x = ['status'=>'not_started','label'=>'Not Started','date'=>'','class'=>'wf-ns'];
}
$wf_soap = wf_soap($soap_form);
$wf_enc  = $enc_signed ? ['status'=>'done','label'=>'Signed','date'=>$esignSignedAt,'class'=>'wf-done'] : ['status'=>'not_started','label'=>'Not Signed','date'=>'','class'=>'wf-ns'];

// ── Helper: render an editable date input (same style as Quick Charge in demographics) ──
// $rawDate  = raw YYYY-MM-DD (for the <input value>)
// $formId   = forms.form_id to update (or '' for encounter-level dates)
// $field    = 'form' | 'encounter' | 'vitals' | 'imaging' — tells the endpoint what to update
function wf_date_editor(string $rawDate, string $formId, string $field): string {
    if (empty($rawDate) || strtotime($rawDate) <= 0) return '';
    // Display format: MM/DD/YYYY [time stripped — just the date]
    $datePart  = substr($rawDate, 0, 10);            // YYYY-MM-DD
    $parts     = explode('-', $datePart);
    $displayed = count($parts) === 3 ? $parts[1].'/'.$parts[2].'/'.$parts[0] : $datePart;
    return '<span class="wf-date-wrap">'
        . '<span class="wf-date-display" style="font-size:10.5px;color:#9ca3af;">' . text($displayed) . '</span>'
        . '<input type="date" class="wf-date-input" style="width:0;height:0;opacity:0;position:absolute;pointer-events:none;"'
        . ' value="' . attr($datePart) . '"'
        . ' data-orig="' . attr($datePart) . '"'
        . ' data-form-id="' . attr($formId) . '"'
        . ' data-field="' . attr($field) . '"'
        . ' onchange="mdiSaveFormDate(this)">'
        . '<i class="fas fa-calendar-alt wf-date-icon" title="' . attr(xl('Change date')) . '"'
        . ' onclick="var inp=this.previousElementSibling;inp.style.cssText=\'\';inp.showPicker?inp.showPicker():inp.focus()"></i>'
        . '</span>';
}
?>

<!-- ══════ ENCOUNTER WORKFLOW + VITALS/QUESTIONNAIRE CARDS ══════ -->
<style>
/* Workflow cards */
.wf-section-label {
    font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.08em;
    color:#7c6f96;margin:18px 0 10px;
}
.wf-cards {
    display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;
}
.wf-card {
    flex:1;min-width:140px;background:#fff;border:1.5px solid #e4ddf5;border-radius:12px;
    padding:14px 16px 12px;display:flex;flex-direction:column;gap:4px;
    box-shadow:0 1px 6px rgba(91,33,182,.05);transition:box-shadow .15s;
}
.wf-card:hover { box-shadow:0 4px 16px rgba(91,33,182,.10); }
.wf-card-head { display:flex;align-items:center;gap:8px;margin-bottom:6px; }
.wf-icon { width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.wf-icon.done  { background:#d1fae5; }
.wf-icon.prog  { background:#fef3c7; }
.wf-icon.ns    { background:#f3f4f6; }
.wf-card-name  { font-size:13px;font-weight:700;color:#1a1523; }
.wf-card-status { font-size:11px;font-weight:600; }
.wf-card-status.wf-done { color:#059669; }
.wf-card-status.wf-prog { color:#d97706; }
.wf-card-status.wf-ns   { color:#9ca3af; }
.wf-card-date  { font-size:10.5px;color:#9ca3af;margin-top:1px; }
.wf-card-btn   { margin-top:8px; }

/* ── Editable date on workflow cards ── */
.wf-date-wrap {
    display: inline-flex; align-items: center; gap: 4px; margin-top: 1px;
}
.wf-date-input {
    font-size: 10.5px; font-weight: 600; color: #9ca3af;
    border: none; background: transparent;
    padding: 0; width: 84px;
    outline: none; cursor: pointer; font-family: inherit;
}
.wf-date-input::-webkit-calendar-picker-indicator { display: none; }
.wf-date-input:focus { color: #7c3aed; }
.wf-date-icon {
    color: #f97316; font-size: 10px; cursor: pointer; flex-shrink: 0;
    opacity: .7; transition: opacity .12s;
}
.wf-date-icon:hover { opacity: 1; }
.wf-date-saving { opacity: .4; pointer-events: none; }
.wf-card-btn a, .wf-card-btn button {
    display:inline-block;font-size:11px;font-weight:600;
    border:1.5px solid #ddd6fe;border-radius:6px;padding:4px 12px;
    color:#5b21b6;background:#f5f3ff;cursor:pointer;text-decoration:none;
    transition:background .12s;
}
.wf-card-btn a:hover, .wf-card-btn button:hover { background:#ede9fe; }

/* Vitals sub-panel inside the card */
.vitals-grid {
    display:grid;grid-template-columns:1fr 1fr;gap:4px 10px;
    margin-top:8px;padding-top:8px;border-top:1px solid #f3f0f9;
}
.vitals-item { display:flex;flex-direction:column; }
.vitals-label { font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af; }
.vitals-value { font-size:12px;font-weight:700;color:#1a1523; }
.vitals-value.missing { color:#d1d5db; }

/* Questionnaire sub-panel */
.q-list { margin-top:8px;padding-top:8px;border-top:1px solid #f3f0f9;display:flex;flex-direction:column;gap:4px; }
.q-item { display:flex;align-items:center;justify-content:space-between;gap:6px; }
.q-item-name { font-size:11.5px;color:#3b1f7a;font-weight:500;flex:1;line-height:1.3; }
.q-item-date { font-size:10px;color:#9ca3af;white-space:nowrap; }
.q-item-btn a {
    font-size:10px;font-weight:600;color:#5b21b6;background:#f5f3ff;
    border:1px solid #ddd6fe;border-radius:5px;padding:2px 8px;text-decoration:none;
}
.q-item-btn a:hover { background:#ede9fe; }
.q-add-row { margin-top:6px;display:flex;gap:6px;flex-wrap:wrap; }
.q-add-btn {
    font-size:10px;font-weight:600;color:#7c3aed;background:#fff;
    border:1.5px dashed #c4b5fd;border-radius:6px;padding:3px 10px;cursor:pointer;
    text-decoration:none;transition:background .1s;
}
.q-add-btn:hover { background:#f5f3ff; }

/* ── Imaging Review Panel — sits inside the iframe, slides in from the right ── */
#encounter_forms {
    position: relative;
}

.d-none {
    display:none !important;
}

/* Encounter Preview Panel */
.enc-preview-panel {
    background:#fff;border:1.5px solid #e4ddf5;border-radius:14px;
    margin-bottom:18px;overflow:hidden;box-shadow:0 2px 12px rgba(91,33,182,.07);
}
.enc-preview-header {
    background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff;
    padding:12px 18px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;
    user-select:none;
}
.enc-preview-header h6 { margin:0;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px; }
.enc-preview-header .ep-toggle { background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:5px;width:24px;height:24px;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center; }
.enc-preview-body { padding:16px 20px;display:none; }
.enc-preview-body.open { display:block; }
.ep-section { margin-bottom:16px; }
.ep-section-title { font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#7c3aed;margin-bottom:6px; }
.ep-row { display:flex;gap:12px;flex-wrap:wrap; }
.ep-field { flex:1;min-width:140px; }
.ep-field label { display:block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;margin-bottom:2px; }
.ep-field .ep-val { font-size:12.5px;color:#1a1523;font-weight:500; }
.ep-field .ep-val.empty { color:#d1d5db;font-style:italic; }
.ep-divider { height:1px;background:#f3f0f9;margin:12px 0; }
.ep-vitals-grid { display:grid;grid-template-columns:repeat(4,1fr);gap:8px; }
.ep-vital { background:#f8f6fd;border-radius:8px;padding:8px 10px; }
.ep-vital-label { font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af; }
.ep-vital-val { font-size:14px;font-weight:800;color:#5b21b6;margin-top:2px; }
.ep-vital-val.empty { color:#d1d5db;font-size:12px;font-weight:400; }
.ep-q-list { display:flex;flex-direction:column;gap:5px; }
.ep-q-row { display:flex;align-items:center;gap:10px;padding:5px 8px;background:#f8f6fd;border-radius:7px; }
.ep-q-name { font-size:12px;font-weight:600;color:#3b1f7a;flex:1; }
.ep-q-date { font-size:10.5px;color:#9ca3af; }
.ep-empty { color:#d1d5db;font-size:12px;font-style:italic; }
</style>

<!-- ══════ INSURANCE + ACCIDENT INFO PANELS ══════ -->
<style>
/* Info panels grid */
.info-panels-row {
    display:flex;gap:14px;margin-bottom:18px;flex-wrap:wrap;
}
.info-panel {
    flex:1;min-width:280px;background:#fff;border:1.5px solid #e4ddf5;border-radius:14px;
    padding:0;overflow:hidden;box-shadow:0 2px 10px rgba(91,33,182,.06);
}
.info-panel-header {
    display:flex;align-items:center;gap:10px;padding:12px 16px 10px;
    border-bottom:1.5px solid #f0ebff;
}
.info-panel-icon {
    width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;
}
.info-panel-icon.blue  { background:#eff6ff; }
.info-panel-icon.green { background:#f0fdf4; }
.info-panel-title {
    font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;
    color:#1a1523;flex:1;
}
/* Insurance type tabs */
.ins-tabs { display:flex;gap:4px;margin-left:auto; }
.ins-tab {
    font-size:10px;font-weight:700;padding:3px 9px;border-radius:20px;
    cursor:pointer;border:1.5px solid #e4ddf5;background:#f8f6fd;color:#7c6f96;
    transition:all .12s;white-space:nowrap;
}
.ins-tab.active { background:#5b21b6;color:#fff;border-color:#5b21b6; }
.ins-tab:hover:not(.active) { background:#ede9fe;border-color:#c4b5fd; }
.ins-tab.missing { opacity:.45;cursor:default; }

/* Panel body */
.info-panel-body { padding:12px 16px 14px; }
.info-rows { display:flex;flex-direction:column;gap:7px; }
.info-row { display:flex;align-items:baseline;gap:6px; }
.info-row-label { font-size:10.5px;color:#9ca3af;font-weight:600;min-width:110px;flex-shrink:0; }
.info-row-value { font-size:12.5px;color:#1a1523;font-weight:600; }
.info-row-value.highlight { color:#2563eb; }
.info-row-value.accent    { color:#059669; }
.info-panel-footer { padding:10px 16px;border-top:1px solid #f0ebff; }
.info-panel-edit-btn {
    display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;
    color:#5b21b6;border:1.5px solid #ddd6fe;border-radius:7px;padding:5px 13px;
    background:#f5f3ff;text-decoration:none;cursor:pointer;transition:background .12s;
}
.info-panel-edit-btn:hover { background:#ede9fe; }

/* Insurance type popup modal */
#ins-type-modal {
    display:none;position:fixed;inset:0;z-index:10000;
    background:rgba(0,0,0,.35);backdrop-filter:blur(2px);
    align-items:center;justify-content:center;
}
#ins-type-modal.open { display:flex; }
.ins-modal-box {
    background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.25);
    width:460px;max-width:95vw;overflow:hidden;
}
.ins-modal-header {
    background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff;
    padding:16px 20px;display:flex;align-items:center;justify-content:space-between;
}
.ins-modal-header h3 { margin:0;font-size:15px;font-weight:700; }
.ins-modal-close { background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center; }
.ins-modal-body { padding:20px; }
.ins-type-cards { display:flex;flex-direction:column;gap:10px; }
.ins-type-card {
    border:1.5px solid #e4ddf5;border-radius:10px;padding:12px 14px;cursor:pointer;
    transition:all .13s;display:flex;align-items:center;gap:12px;
}
.ins-type-card:hover { border-color:#7c3aed;background:#faf8ff; }
.ins-type-card.active { border-color:#5b21b6;background:#f5f3ff; }
.ins-type-card.empty  { opacity:.5;cursor:default; }
.ins-type-badge {
    font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.06em;
    padding:3px 9px;border-radius:20px;background:#ede9fe;color:#5b21b6;white-space:nowrap;
}
.ins-type-card.active .ins-type-badge { background:#5b21b6;color:#fff; }
.ins-type-info { flex:1; }
.ins-type-name { font-size:13px;font-weight:700;color:#1a1523; }
.ins-type-sub  { font-size:11px;color:#9ca3af;margin-top:2px; }
.ins-modal-footer { padding:14px 20px;border-top:1px solid #f0ebff;display:flex;justify-content:flex-end;gap:8px; }
.ins-modal-btn { font-size:12px;font-weight:600;padding:7px 18px;border-radius:8px;cursor:pointer;border:none; }
.ins-modal-btn.cancel { background:#f3f4f6;color:#374151; }
.ins-modal-btn.apply  { background:#5b21b6;color:#fff; }
.ins-modal-btn.apply:hover { background:#4c1d95; }
</style>

<div class="info-panels-row">

  <!-- INSURANCE PANEL -->
  <div class="info-panel" id="ins-panel">
    <div class="info-panel-header">
      <div class="info-panel-icon blue">
        <svg width="16" height="16" fill="none" stroke="#2563eb" stroke-width="2.2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <span class="info-panel-title"><?php echo xlt('Insurance Information'); ?></span>
      <div class="ins-tabs">
        <button class="ins-tab active" data-ins="primary"   onclick="switchInsPanel('primary')"><?php echo xlt('Primary'); ?></button>
        <button class="ins-tab <?php echo $secondaryIns ? '' : 'missing'; ?>" data-ins="secondary" onclick="<?php echo $secondaryIns ? "switchInsPanel('secondary')" : 'void(0)'; ?>"><?php echo xlt('Secondary'); ?></button>
        <button class="ins-tab <?php echo $tertiaryIns  ? '' : 'missing'; ?>" data-ins="tertiary"  onclick="<?php echo $tertiaryIns  ? "switchInsPanel('tertiary')"  : 'void(0)'; ?>"><?php echo xlt('Tertiary'); ?></button>
      </div>
    </div>
    <div class="info-panel-body">
      <?php
      // Render all three panels; only the active one is shown
      $ins_list = ['primary' => $primaryIns, 'secondary' => $secondaryIns, 'tertiary' => $tertiaryIns];
      foreach ($ins_list as $ins_type => $ins) : ?>
      <div id="ins-panel-<?php echo $ins_type; ?>" class="info-rows" style="<?php echo $ins_type !== 'primary' ? 'display:none' : ''; ?>">
        <?php if ($ins): ?>
          <div class="info-row">
            <span class="info-row-label"><?php echo xlt('Insurance'); ?></span>
            <span class="info-row-value highlight"><?php echo text($ins['company_name'] ?? $ins['plan_name'] ?? '—'); ?></span>
          </div>
          <div class="info-row">
            <span class="info-row-label"><?php echo xlt('Plan'); ?></span>
            <span class="info-row-value"><?php echo text($ins['plan_name'] ?? '—'); ?></span>
          </div>
          <div class="info-row">
            <span class="info-row-label"><?php echo xlt('Member ID'); ?></span>
            <span class="info-row-value"><?php echo text($ins['policy_number'] ?? '—'); ?></span>
          </div>
          <div class="info-row">
            <span class="info-row-label"><?php echo xlt('Group #'); ?></span>
            <span class="info-row-value"><?php echo text($ins['group_number'] ?? '—'); ?></span>
          </div>
          <div class="info-row">
            <span class="info-row-label"><?php echo xlt('Relationship'); ?></span>
            <span class="info-row-value"><?php echo text(ucfirst($ins['subscriber_relationship'] ?? '—')); ?></span>
          </div>
        <?php else: ?>
          <div style="color:#d1d5db;font-size:12px;font-style:italic;padding:6px 0;">
            <?php echo xlt('No') . ' ' . xlt($ins_type) . ' ' . xlt('insurance on file'); ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="info-panel-footer">
      <button class="info-panel-edit-btn" onclick="openInsTypeModal()">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        <?php echo xlt('Edit Insurance'); ?>
      </button>
      <button class="info-panel-edit-btn" onclick="openInsTypeModal()" style="margin-left:8px;">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="15 3 21 3 21 9"/><path d="M10 14L21 3"/><path d="M21 16v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>
        <?php echo xlt('Switch Type'); ?>
      </button>
    </div>
  </div>

  <!-- ACCIDENT PANEL — only shown when MBO box 10 has an affirmative accident flag -->
  <?php if ($has_box10): ?>
  <div class="info-panel" id="acc-panel">
    <div class="info-panel-header">
      <div class="info-panel-icon green">
        <svg width="16" height="16" fill="none" stroke="#059669" stroke-width="2.2" viewBox="0 0 24 24"><path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2h-2"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
      </div>
      <span class="info-panel-title"><?php echo xlt('Accident Information'); ?></span>
    </div>
    <div class="info-panel-body">
      <div class="info-rows">
        <div class="info-row">
          <span class="info-row-label"><?php echo xlt('Accident Type'); ?></span>
          <span class="info-row-value accent">
            <?php
            if (in_array(strtolower((string)$acc_auto), ['yes','1','y'], true)) {
                echo xlt('Auto Accident');
            } elseif (in_array(strtolower((string)$acc_emp), ['yes','1','y'], true)) {
                echo xlt('Work Related');
            } elseif (in_array(strtolower((string)$acc_other), ['yes','1','y'], true)) {
                echo xlt('Other Accident');
            } else {
                echo '—';
            }
            ?>
          </span>
        </div>
        <div class="info-row">
          <span class="info-row-label"><?php echo xlt('Accident Date'); ?></span>
          <span class="info-row-value">
            <?php echo !empty($acc_date) ? text(date('m/d/Y', strtotime($acc_date))) : '—'; ?>
          </span>
        </div>
        <div class="info-row">
          <span class="info-row-label"><?php echo xlt('State'); ?></span>
          <span class="info-row-value"><?php echo !empty($acc_state) ? text($acc_state) : '—'; ?></span>
        </div>
        <?php if (in_array(strtolower((string)$acc_emp), ['yes','1','y'], true)): ?>
        <div class="info-row">
          <span class="info-row-label"><?php echo xlt('Employment Related'); ?></span>
          <span class="info-row-value" style="color:#059669;font-weight:600;"><?php echo xlt('Yes'); ?></span>
        </div>
        <?php endif; ?>
        <?php if (in_array(strtolower((string)$acc_other), ['yes','1','y'], true)): ?>
        <div class="info-row">
          <span class="info-row-label"><?php echo xlt('Other Accident'); ?></span>
          <span class="info-row-value" style="color:#059669;font-weight:600;"><?php echo xlt('Yes'); ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="info-panel-footer">
      <button class="info-panel-edit-btn" onclick="openMboBillingPopup()">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        <?php echo xlt('Edit Accident Information'); ?>
      </button>
    </div>
  </div>

  <?php else: ?>
  <!-- No box 10 data — compact placeholder so layout stays balanced -->
  <div class="info-panel" id="acc-panel" style="opacity:.55;">
    <div class="info-panel-header">
      <div class="info-panel-icon" style="background:#f3f4f6;">
        <svg width="16" height="16" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24"><path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2h-2"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
      </div>
      <span class="info-panel-title" style="color:#9ca3af;"><?php echo xlt('Accident Information'); ?></span>
    </div>
    <div class="info-panel-body" style="padding:18px 20px;">
      <p style="margin:0;font-size:0.82rem;color:#9ca3af;"><?php echo xlt('No accident reported — Box 10 not completed.'); ?></p>
    </div>
    <div class="info-panel-footer">
      <button class="info-panel-edit-btn" onclick="openMboBillingPopup()">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
        <?php echo xlt('Add Accident Information'); ?>
      </button>
    </div>
  </div>
  <?php endif; ?>

</div><!-- .info-panels-row -->

<!-- INSURANCE TYPE SWITCHER MODAL -->
<div id="ins-type-modal">
  <div class="ins-modal-box">
    <div class="ins-modal-header">
      <h3><?php echo xlt('Select Insurance Type'); ?></h3>
      <button class="ins-modal-close" onclick="closeInsTypeModal()">&#x2715;</button>
    </div>
    <div class="ins-modal-body">
      <div class="ins-type-cards">
        <?php foreach (['primary' => $primaryIns, 'secondary' => $secondaryIns, 'tertiary' => $tertiaryIns] as $itype => $idata): ?>
        <div class="ins-type-card <?php echo $idata ? 'active' : 'empty'; ?>" id="ins-modal-card-<?php echo $itype; ?>"
             onclick="<?php echo $idata ? 'selectInsType(\'' . attr($itype) . '\')' : 'void(0)'; ?>">
          <span class="ins-type-badge"><?php echo xlt(ucfirst($itype)); ?></span>
          <div class="ins-type-info">
            <?php if ($idata): ?>
              <div class="ins-type-name"><?php echo text($idata['company_name'] ?? $idata['plan_name'] ?? xlt('Unknown')); ?></div>
              <div class="ins-type-sub"><?php echo xlt('Member ID'); ?>: <?php echo text($idata['policy_number'] ?? '—'); ?></div>
            <?php else: ?>
              <div class="ins-type-name" style="color:#9ca3af;"><?php echo xlt('Not on file'); ?></div>
              <div class="ins-type-sub"><a href="#" onclick="openAddInsurance(<?php echo attr_js($itype); ?>);return false;" style="color:#5b21b6;"><?php echo xlt('+ Add'); ?></a></div>
            <?php endif; ?>
          </div>
          <?php if ($idata): ?>
            <svg width="14" height="14" fill="none" stroke="#5b21b6" stroke-width="2.5" viewBox="0 0 24 24" class="ins-check" style="display:none;"><polyline points="20 6 9 17 4 12"/></svg>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="ins-modal-footer">
      <button class="ins-modal-btn cancel" onclick="closeInsTypeModal()"><?php echo xlt('Cancel'); ?></button>
      <button class="ins-modal-btn apply"  onclick="applyInsType()"><?php echo xlt('Apply'); ?></button>
    </div>
  </div>
</div>

<script>
// ── Insurance panel switcher ──────────────────────────────────────────────────
var _currentInsType = 'primary';
var _pendingInsType  = 'primary';

function switchInsPanel(type) {
    // Hide all panels
    ['primary','secondary','tertiary'].forEach(function(t) {
        var el = document.getElementById('ins-panel-' + t);
        if (el) el.style.display = 'none';
        var tab = document.querySelector('.ins-tab[data-ins="' + t + '"]');
        if (tab) tab.classList.remove('active');
    });
    // Show selected
    var target = document.getElementById('ins-panel-' + type);
    if (target) target.style.display = 'flex';
    var activeTab = document.querySelector('.ins-tab[data-ins="' + type + '"]');
    if (activeTab && !activeTab.classList.contains('missing')) activeTab.classList.add('active');
    _currentInsType = type;
}

// ── Insurance type modal ──────────────────────────────────────────────────────
function openInsTypeModal() {
    _pendingInsType = _currentInsType;
    updateModalSelection(_pendingInsType);
    document.getElementById('ins-type-modal').classList.add('open');
}
function closeInsTypeModal() {
    document.getElementById('ins-type-modal').classList.remove('open');
}
function selectInsType(type) {
    _pendingInsType = type;
    updateModalSelection(type);
}
function updateModalSelection(type) {
    ['primary','secondary','tertiary'].forEach(function(t) {
        var card = document.getElementById('ins-modal-card-' + t);
        var chk  = card ? card.querySelector('.ins-check') : null;
        if (card) card.classList.toggle('active', t === type);
        if (chk)  chk.style.display = (t === type) ? 'block' : 'none';
    });
}
function applyInsType() {
    switchInsPanel(_pendingInsType);
    closeInsTypeModal();
}
function openAddInsurance(type) {
    closeInsTypeModal();
    top.restoreSession();
    dlgopen(
        '<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/demographics_full.php?pid=<?php echo attr_url($pid); ?>#insurance-' + type,
        'add_insurance_modal', 1100, 750, '', '<?php echo xla('Insurance'); ?>',
        {allowResize:true, allowDrag:true, type:'iframe'}
    );
}
// ── Editable form date on workflow cards ──────────────────────────────────────
function mdiSaveFormDate(input) {
    var newDate = input.value.trim();
    var orig    = input.dataset.orig;
    if (!newDate || newDate === orig) return;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
        alert('Please use YYYY-MM-DD format.');
        input.value = orig;
        return;
    }
    input.classList.add('wf-date-saving');
    var fd = new FormData();
    fd.append('form_id',         input.dataset.formId);
    fd.append('pid',             '<?php echo attr($pid); ?>');
    fd.append('encounter',       '<?php echo attr($encounter); ?>');
    fd.append('new_date',        newDate);
    fd.append('field',           input.dataset.field);   // 'form' or 'encounter'
    fd.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/encounter/mdi_update_form_date.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        input.classList.remove('wf-date-saving');
        if (d && d.ok) {
            input.dataset.orig = newDate;
            // Update the visible formatted label beside the input
            var wrap = input.closest('.wf-date-wrap');
            if (wrap) {
                var parts = newDate.split('-');
                var lbl = wrap.querySelector('.wf-date-display');
                if (lbl) lbl.textContent = parts[1] + '/' + parts[2] + '/' + parts[0];
            }
        } else {
            alert('Could not save date: ' + (d && d.error ? d.error : 'Unknown error'));
            input.value = orig;
        }
    }).catch(function() {
        input.classList.remove('wf-date-saving');
        alert('Network error saving date.');
        input.value = orig;
    });
}
// Close modal on outside click
document.getElementById('ins-type-modal').addEventListener('click', function(e) {
    if (e.target === this) closeInsTypeModal();
});

// ── Accident / MBO popup ──────────────────────────────────────────────────────
function openMboBillingPopup() {
    top.restoreSession();
    // Pass existing form_id so the MBO form opens for editing, not blank
    var mboId = <?php
        $mboIdRow = sqlQuery(
            "SELECT form_id FROM forms WHERE pid = ? AND encounter = ? AND formdir = 'misc_billing_options' AND deleted = 0 LIMIT 1",
            [$pid, $encounter]
        );
        echo (int)($mboIdRow['form_id'] ?? 0);
    ?>;
    var url = '<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/encounter/load_form.php'
            + '?formname=misc_billing_options'
            + '&encounter=<?php echo attr_url($encounter); ?>'
            + '&pid=<?php echo attr_url($pid); ?>'
            + (mboId ? '&id=' + mboId : '');
    dlgopen(url, 'mbo_popup', 1000, 700, '', '<?php echo xla('Miscellaneous Billing Options'); ?>',
        {allowResize: true, allowDrag: true, type: 'iframe'});
}
</script>

<div class="wf-section-label"><?php echo xlt('Encounter Workflow'); ?></div>
<div class="wf-cards">

  <!-- Questionnaire card -->
  <div class="wf-card">
    <div class="wf-card-head">
      <div class="wf-icon <?php echo $wf_q['status'] === 'done' ? 'done' : 'ns'; ?>">
        <svg width="14" height="14" fill="none" stroke="<?php echo $wf_q['status']==='done'?'#059669':'#9ca3af'; ?>" stroke-width="2.2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      </div>
      <span class="wf-card-name"><?php echo xlt('Patient Forms'); ?></span>
    </div>
    <span class="wf-card-status <?php echo $wf_q['class']; ?>"><?php echo text($wf_q['label']); ?></span>
    <?php if ($wf_q['date'] && !empty($q_form['date'])): ?>
      <?php echo wf_date_editor($q_form['date'], (string)($q_form['form_id'] ?? ''), 'form'); ?>
    <?php elseif ($wf_q['date']): ?><span class="wf-card-date"><?php echo text($wf_q['date']); ?></span><?php endif; ?>

    <?php if (!empty($questionnaire_forms)): ?>
    <div class="q-list">
      <?php foreach ($questionnaire_forms as $qf): ?>
        <div class="q-item">
          <?php $qf_label = mdi_form_label($qf['formdir'], $qf['form_name'] ?? ''); ?>
          <span class="q-item-name"><?php echo text($qf_label); ?></span>
          <span class="q-item-date"><?php echo !empty($qf['date']) ? date('m/d/Y', strtotime($qf['date'])) : ''; ?></span>
          <span class="q-item-btn"><a href="#" onclick="top.restoreSession();openEncounterForm(<?php echo attr_js($qf['formdir']); ?>,<?php echo attr_js($qf_label); ?>,<?php echo attr_js($qf['form_id']); ?>);return false;"><?php echo xlt('View'); ?></a></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="q-add-row">
      <a class="q-add-btn" href="#" onclick="top.restoreSession();openNewForm('/interface/patient_file/encounter/load_form.php?formname=questionnaire_assessments&encounter=<?php echo attr_url($encounter); ?>&pid=<?php echo attr_url($pid); ?>','<?php echo xla('Questionnaire'); ?>');return false;">
        + <?php echo xlt('Add Questionnaire'); ?>
      </a>
    </div>

    <div class="wf-card-btn">
      <?php if (!empty($q_form)): ?>
        <?php
        $q_label = mdi_form_label($q_form['formdir'] ?? 'questionnaire_assessments', $q_form['form_name'] ?? '');
        $q_fdir  = $q_form['formdir'] ?? 'questionnaire_assessments';
        $q_fid   = $q_form['form_id'] ?? '0';
        ?>
        <a href="#" onclick="top.restoreSession();openEncounterForm(<?php echo attr_js($q_fdir); ?>,<?php echo attr_js($q_label); ?>,<?php echo attr_js($q_fid); ?>);return false;"><?php echo xlt('View'); ?></a>
      <?php else: ?>
        <a href="#" onclick="top.restoreSession();openNewForm('/interface/patient_file/encounter/load_form.php?formname=questionnaire_assessments&encounter=<?php echo attr_url($encounter); ?>&pid=<?php echo attr_url($pid); ?>','<?php echo xla('Questionnaire'); ?>');return false;"><?php echo xlt('Start'); ?></a>
      <?php endif; ?>
    </div>
  </div><!-- end questionnaire card -->

  <!-- Review of Systems card -->
  <div class="wf-card" id="wf-ros-card">
    <div class="wf-card-head">
      <div class="wf-icon <?php echo $wf_ros['status'] === 'done' ? 'done' : 'ns'; ?>">
        <svg width="14" height="14" fill="none" stroke="<?php echo $wf_ros['status']==='done'?'#059669':'#9ca3af'; ?>" stroke-width="2.2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      </div>
      <span class="wf-card-name"><?php echo xlt('Review of Systems'); ?></span>
    </div>
    <span class="wf-card-status <?php echo $wf_ros['class']; ?>"><?php echo text($wf_ros['label']); ?></span>
    <?php if ($wf_ros['date'] && !empty($ros_form['date'])): ?>
      <?php echo wf_date_editor($ros_form['date'], (string)($ros_form['form_id'] ?? ''), 'form'); ?>
    <?php elseif ($wf_ros['date']): ?>
      <span class="wf-card-date"><?php echo text($wf_ros['date']); ?></span>
    <?php endif; ?>
    <?php if (empty($ros_form)): ?>
      <p style="font-size:11px;color:#6b7280;margin:6px 0 4px;">Review patient systems and mark normal, positive, or abnormal findings.</p>
    <?php endif; ?>
    <div class="wf-card-btn">
      <?php if (!empty($ros_form)): ?>
        <a href="#" onclick="top.restoreSession();openEncounterForm('ros','<?php echo xla('Review of Systems'); ?>',<?php echo attr_js($ros_form['form_id']); ?>);return false;"><?php echo xlt('View'); ?></a>
      <?php else: ?>
        <a href="#" onclick="top.restoreSession();openNewForm('/interface/patient_file/encounter/load_form.php?formname=ros&encounter=<?php echo attr_url($encounter); ?>&pid=<?php echo attr_url($pid); ?>','<?php echo xla('Review of Systems'); ?>');return false;"><?php echo xlt('Start ROS'); ?></a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Vitals card -->
  <div class="wf-card">
    <div class="wf-card-head">
      <div class="wf-icon <?php echo $wf_v['status']==='done'?'done':'ns'; ?>">
        <svg width="14" height="14" fill="none" stroke="<?php echo $wf_v['status']==='done'?'#059669':'#9ca3af'; ?>" stroke-width="2.2" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
      </div>
      <span class="wf-card-name"><?php echo xlt('Vitals'); ?></span>
    </div>
    <span class="wf-card-status <?php echo $wf_v['class']; ?>"><?php echo text($wf_v['label']); ?></span>
    <?php if ($wf_v['date'] && !empty($v_form['date'])): ?>
      <?php echo wf_date_editor($v_form['date'], (string)($v_form['form_id'] ?? ''), 'vitals'); ?>
    <?php elseif ($wf_v['date']): ?><span class="wf-card-date"><?php echo text($wf_v['date']); ?></span><?php endif; ?>

    <?php if (!empty($vitals_data)): ?>
    <div class="vitals-grid">
      <?php
      // Helper: format a numeric vital cleanly (trim .000000 trailing zeros)
      $fmtVital = function($v, $decimals = 1) {
          if ($v === null || $v === '' || (float)$v == 0) return '';
          $n = (float)$v;
          // If it's a whole number, show no decimals
          if ($n == floor($n)) return (string)(int)$n;
          return rtrim(rtrim(number_format($n, $decimals), '0'), '.');
      };
      $vitals_display = [
          'BP'       => (!empty($vitals_data['bps']) && !empty($vitals_data['bpd']) && (float)$vitals_data['bps'] > 0)
                          ? $fmtVital($vitals_data['bps'], 0).'/'.$fmtVital($vitals_data['bpd'], 0).' mmHg' : '',
          'Pulse'    => (float)($vitals_data['pulse']   ?? 0) > 0 ? $fmtVital($vitals_data['pulse'],   0).' bpm'  : '',
          'Temp'     => (float)($vitals_data['temp']    ?? 0) > 0 ? $fmtVital($vitals_data['temp'],    1).'°'     : '',
          'Weight'   => (float)($vitals_data['weight']  ?? 0) > 0 ? $fmtVital($vitals_data['weight'],  1).' lbs'  : '',
          'Height'   => (float)($vitals_data['height']  ?? 0) > 0 ? $fmtVital($vitals_data['height'],  1).'"'     : '',
          'O₂ Sat'   => (float)($vitals_data['oxygen_saturation'] ?? 0) > 0 ? $fmtVital($vitals_data['oxygen_saturation'], 1).'%' : '',
          'Resp'     => (float)($vitals_data['respiration'] ?? 0) > 0 ? $fmtVital($vitals_data['respiration'], 0).'/min' : '',
          'BMI'      => (float)($vitals_data['BMI']     ?? 0) > 0 ? $fmtVital($vitals_data['BMI'],     1) : '',
      ];
      foreach ($vitals_display as $lbl => $val): ?>
        <div class="vitals-item">
          <span class="vitals-label"><?php echo text($lbl); ?></span>
          <span class="vitals-value <?php echo $val?'':'missing'; ?>"><?php echo $val ? text($val) : '—'; ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="wf-card-btn">
      <?php if (!empty($v_form)): ?>
        <a href="#" onclick="top.restoreSession();openEncounterForm('vitals','<?php echo xla('Vitals'); ?>',<?php echo attr_js($v_form['form_id']); ?>);return false;"><?php echo xlt('View'); ?></a>
      <?php else: ?>
        <a href="#" onclick="top.restoreSession();openNewForm('/interface/patient_file/encounter/load_form.php?formname=vitals&encounter=<?php echo attr_url($encounter); ?>&pid=<?php echo attr_url($pid); ?>','<?php echo xla('Vitals'); ?>');return false;"><?php echo xlt('Start'); ?></a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Imaging Review card -->
  <div class="wf-card" id="wf-imaging-card">
    <div class="wf-card-head">
      <div class="wf-icon <?php echo $wf_x['status']==='done'?'done':($wf_x['status']==='prog'?'prog':'ns'); ?>">
        <svg width="14" height="14" fill="none" stroke="<?php echo $wf_x['status']==='done'?'#059669':($wf_x['status']==='prog'?'#d97706':'#9ca3af'); ?>" stroke-width="2.2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
      </div>
      <span class="wf-card-name"><?php echo xlt('Imaging Review'); ?></span>
    </div>
    <span class="wf-card-status <?php echo $wf_x['class']; ?>"><?php echo text($wf_x['label']); ?></span>
    <?php
    $imaging_raw_date = !empty($imaging_findings_row['created_at']) ? $imaging_findings_row['created_at'] : $xray_date;
    $imaging_fid      = !empty($imaging_findings_row['id']) ? (string)$imaging_findings_row['id'] : '';
    if ($wf_x['date'] && !empty($imaging_raw_date)):
        echo wf_date_editor($imaging_raw_date, $imaging_fid, 'imaging');
    elseif ($wf_x['date']): ?><span class="wf-card-date"><?php echo text($wf_x['date']); ?></span><?php endif; ?>
    <?php if ($xray_count > 0): ?>
      <span class="wf-card-date" style="color:#7c3aed;font-weight:600;"><?php echo (int)$xray_count; ?> <?php echo xlt('image(s) available'); ?></span>
    <?php endif; ?>
    <?php if ($imaging_reviewed && !empty($imaging_findings_row['findings'])): ?>
      <div style="margin-top:6px;padding:6px 8px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;font-size:11px;color:#065f46;line-height:1.4;">
        <strong><?php echo xlt('Findings:'); ?></strong>
        <?php echo text(mb_strimwidth($imaging_findings_row['findings'], 0, 120, '…')); ?>
      </div>
    <?php endif; ?>
    <div class="wf-card-btn">
  <a href="#"
     onclick="top.restoreSession();
       openNewForm(
         'mditek_imaging_viewer.php?pid=<?php echo (int)$_SESSION['pid']; ?>&encounter=<?php echo (int)$_SESSION['encounter']; ?>',
         '<?php echo xla('Imaging Viewer'); ?>'
       );
       return false;">
    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
      <rect x="3" y="3" width="18" height="18" rx="2"/>
      <line x1="3" y1="9" x2="21" y2="9"/>
      <line x1="9" y1="21" x2="9" y2="9"/>
    </svg>
    <?php echo $imaging_reviewed ? xlt('View / Update') : ($xray_count ? xlt('Open Viewer') : xlt('Add Imaging')); ?>
  </a>
</div>
  </div>

    <!-- SOAP card -->
  <div class="wf-card">
    <div class="wf-card-head">
      <div class="wf-icon <?php echo $wf_soap['status']==='done'?'done':'ns'; ?>">
        <svg width="14" height="14" fill="none" stroke="<?php echo $wf_soap['status']==='done'?'#059669':'#9ca3af'; ?>" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      </div>
      <span class="wf-card-name"><?php echo xlt('SOAP Note'); ?></span>
    </div>
    <span class="wf-card-status <?php echo $wf_soap['class']; ?>"><?php echo text($wf_soap['label']); ?></span>
    <?php if ($wf_soap['date'] && !empty($soap_form['date'])): ?>
      <?php echo wf_date_editor($soap_form['date'], (string)($soap_form['form_id'] ?? ''), 'form'); ?>
    <?php elseif ($wf_soap['date']): ?><span class="wf-card-date"><?php echo text($wf_soap['date']); ?></span><?php endif; ?>
    <div class="wf-card-btn">
      <?php if (!empty($soap_form)): ?>
        <a href="#" onclick="top.restoreSession();openEncounterForm('soap','<?php echo xla('SOAP'); ?>',<?php echo attr_js($soap_form['form_id']); ?>);return false;"><?php echo xlt('View'); ?></a>
      <?php else: ?>
        <a href="#" onclick="top.restoreSession();openNewForm('/interface/patient_file/encounter/load_form.php?formname=soap&encounter=<?php echo attr_url($encounter); ?>&pid=<?php echo attr_url($pid); ?>','<?php echo xla('SOAP'); ?>');return false;"><?php echo xlt('Start'); ?></a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Encounter Signed card -->
  <div class="wf-card">
    <div class="wf-card-head">
      <div class="wf-icon <?php echo $wf_enc['status']==='done'?'done':'ns'; ?>">
        <svg width="14" height="14" fill="none" stroke="<?php echo $wf_enc['status']==='done'?'#059669':'#9ca3af'; ?>" stroke-width="2.2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      </div>
      <span class="wf-card-name"><?php echo xlt('Encounter Signed'); ?></span>
    </div>
    <span class="wf-card-status <?php echo $wf_enc['class']; ?>"><?php echo text($wf_enc['label']); ?></span>
    <?php if ($wf_enc['date']): ?><span class="wf-card-date"><?php echo text($wf_enc['date']); ?></span><?php endif; ?>
  </div>

</div><!-- .wf-cards -->

<!-- ══════ ENCOUNTER PREVIEW CONTENT (injected into top panel) ══════ -->
<?php ob_start(); ?>

    <!-- Patient + Encounter meta -->
    <div class="ep-section">
      <div class="ep-section-title"><?php echo xlt('Encounter Details'); ?></div>
      <div class="ep-row">
        <div class="ep-field"><label><?php echo xlt('Patient'); ?></label><div class="ep-val"><?php echo text($patientName); ?></div></div>
        <div class="ep-field"><label><?php echo xlt('Date of Service'); ?></label><div class="ep-val"><?php echo text($encounter_date); ?></div></div>
        <div class="ep-field"><label><?php echo xlt('Provider'); ?></label><div class="ep-val"><?php echo text($providerNameRes); ?></div></div>
        <div class="ep-field"><label><?php echo xlt('Reason'); ?></label><div class="ep-val <?php echo empty($encData['reason'])?'empty':''; ?>"><?php echo !empty($encData['reason']) ? text($encData['reason']) : xlt('Not specified'); ?></div></div>
      </div>
    </div>

    <div class="ep-divider"></div>

    <!-- Vitals snapshot -->
    <div class="ep-section">
      <div class="ep-section-title"><?php echo xlt('Vitals'); ?></div>
      <?php if (!empty($vitals_data)): ?>
      <div class="ep-vitals-grid">
        <?php
        $fmtV = function($v, $d=1){ if(!$v||(float)$v==0) return ''; $n=(float)$v; return $n==floor($n)?(string)(int)$n:rtrim(rtrim(number_format($n,$d),'0'),'.'); };
        $ep_vitals = [
            'Blood Pressure' => ((float)($vitals_data['bps']??0)>0) ? $fmtV($vitals_data['bps'],0).'/'.$fmtV($vitals_data['bpd'],0).' mmHg' : '',
            'Pulse'          => (float)($vitals_data['pulse']??0)>0  ? $fmtV($vitals_data['pulse'],0).' bpm' : '',
            'Temperature'    => (float)($vitals_data['temp']??0)>0   ? $fmtV($vitals_data['temp'],1).'°' : '',
            'Weight'         => (float)($vitals_data['weight']??0)>0 ? $fmtV($vitals_data['weight'],1).' lbs' : '',
            'Height'         => (float)($vitals_data['height']??0)>0 ? $fmtV($vitals_data['height'],1).'"' : '',
            'O₂ Saturation'  => (float)($vitals_data['oxygen_saturation']??0)>0 ? $fmtV($vitals_data['oxygen_saturation'],1).'%' : '',
            'Respiration'    => (float)($vitals_data['respiration']??0)>0 ? $fmtV($vitals_data['respiration'],0).'/min' : '',
            'BMI'            => (float)($vitals_data['BMI']??0)>0    ? $fmtV($vitals_data['BMI'],1) : '',
        ];
        foreach ($ep_vitals as $label => $val): ?>
          <div class="ep-vital">
            <div class="ep-vital-label"><?php echo text($label); ?></div>
            <div class="ep-vital-val <?php echo $val?'':'empty'; ?>"><?php echo $val ? text($val) : '—'; ?></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <div class="ep-empty"><?php echo xlt('No vitals recorded for this encounter.'); ?></div>
      <?php endif; ?>
    </div>

    <div class="ep-divider"></div>

    <!-- Questionnaire summary -->
    <div class="ep-section">
      <div class="ep-section-title"><?php echo xlt('Patient Forms'); ?></div>
      <?php if (!empty($questionnaire_forms)): ?>
      <div class="ep-q-list">
        <?php foreach ($questionnaire_forms as $qf): ?>
          <div class="ep-q-row">
            <?php $ep_qf_label = mdi_form_label($qf['formdir'], $qf['form_name'] ?? ''); ?>
            <span class="ep-q-name"><?php echo text($ep_qf_label); ?></span>
            <span class="ep-q-date"><?php echo !empty($qf['date']) ? date('m/d/Y g:i A', strtotime($qf['date'])) : ''; ?></span>
            <a href="#" style="font-size:10px;font-weight:600;color:#5b21b6;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:5px;padding:2px 8px;text-decoration:none;"
               onclick="top.restoreSession();openEncounterForm(<?php echo attr_js($qf['formdir']); ?>,<?php echo attr_js($ep_qf_label); ?>,<?php echo attr_js($qf['form_id']); ?>);return false;"><?php echo xlt('View'); ?></a>
          </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <div class="ep-empty"><?php echo xlt('No questionnaires completed.'); ?></div>
      <?php endif; ?>
    </div>

    <div class="ep-divider"></div>

    <?php if (!empty($imaging_findings_row['findings'])): ?>
    <!-- Imaging Findings in the preview panel -->
    <div class="ep-section">
      <div class="ep-section-title"><?php echo xlt('Imaging Findings'); ?></div>
      <div style="font-size:12px;color:#1e0a3c;line-height:1.65;white-space:pre-wrap;background:#fafafe;border:1px solid #e2dff0;border-radius:6px;padding:10px 12px;">
        <?php echo htmlspecialchars($imaging_findings_row['findings']); ?>
      </div>
      <?php if (!empty($imaging_findings_row['created_at'])): ?>
      <div style="margin-top:6px;font-size:10.5px;color:#9ca3af;">
        <?php echo xlt('Recorded'); ?>: <?php echo text(date('m/d/Y g:i A', strtotime($imaging_findings_row['created_at']))); ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="ep-divider"></div>
    <?php endif; ?>

    <!-- Forms summary -->
    <div class="ep-section">
      <div class="ep-section-title"><?php echo xlt('Completed Forms'); ?></div>
      <?php
      $all_forms = sqlStatement(
          "SELECT form_name, formdir, date FROM forms WHERE pid=? AND encounter=? AND deleted=0 AND formdir NOT IN ('misc_billing_options','LBFref','LBFbilling','LBFclaim') ORDER BY date ASC",
          [$pid, $encounter]
      );
      $ep_forms = [];
      while ($r = sqlFetchArray($all_forms)) { $ep_forms[] = $r; }
      if ($ep_forms):
      ?>
      <div class="ep-q-list">
        <?php foreach ($ep_forms as $ef): ?>
          <div class="ep-q-row">
            <span class="ep-q-name"><?php echo text(mdi_form_label($ef['formdir'], $ef['form_name'] ?? '')); ?></span>
            <span class="ep-q-date"><?php echo !empty($ef['date']) ? date('m/d/Y g:i A', strtotime($ef['date'])) : ''; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php else: ?>
        <div class="ep-empty"><?php echo xlt('No forms attached to this encounter.'); ?></div>
      <?php endif; ?>
    </div>

<?php
$_ep_content = ob_get_clean();
// Inject the content into the top preview panel body
echo '<script>document.getElementById("enc-preview-body-top").innerHTML = ' . json_encode($_ep_content) . ';</script>';
?>
<!-- ══════ END ENCOUNTER PREVIEW ══════ -->
<?php
if ($attendant_type == 'pid') {
    $docs_list = getDocumentsByEncounter($pid, $_SESSION['encounter']);
} else {
    // already doesn't exist document for therapy groups
    $docs_list = array();
}
if (!empty($docs_list) && count($docs_list) > 0) {
    ?>
<div class='enc_docs'>
<span class="font-weight-bold"><?php echo xlt("Document(s)"); ?>:</span>
    <?php
    $doc = new C_Document();
    foreach ($docs_list as $doc_iter) {
        $doc_url = $doc->getTemplateVars('CURRENT_ACTION') . "&view&patient_id=" . attr_url($pid) . "&document_id=" . attr_url($doc_iter['id']) . "&";
        // Get notes for this document.
        $queryString = "SELECT GROUP_CONCAT(note ORDER BY date DESC SEPARATOR '|') AS docNotes, GROUP_CONCAT(date ORDER BY date DESC SEPARATOR '|') AS docDates
			FROM notes WHERE foreign_id = ? GROUP BY foreign_id";
        $noteData = sqlQuery($queryString, array($doc_iter['id']));
        $note = '';
        if ($noteData) {
            $notes = array();
            $notes = explode("|", $noteData['docNotes']);
            $dates = explode("|", $noteData['docDates']);
            for ($i = 0, $iMax = count($notes); $i < $iMax; $i++) {
                $note .= oeFormatShortDate(date('Y-m-d', strtotime($dates[$i]))) . " : " . $notes[$i] . "\n";
            }
        }
        ?>
<br />
<a href="<?php echo $doc_url;?>" style="font-size: small;" onsubmit="return top.restoreSession()"><?php echo text($doc_iter['document_name']) . ": " . text(basename($doc_iter['name']));?></a>
        <?php if ($note != '') {?>
            <a href="javascript:void(0);" title="<?php echo attr($note);?>"><img src="<?php echo $GLOBALS['images_static_relative']; ?>/info.png"/></a>
    <?php }?>
<?php } ?>
</div>
<?php } ?>

<div class="mdi-fc-list-toolbar">
  <button type="button" class="btn mdi-fc-toolbar-btn" onclick="$('.formrow .collapse').collapse('show');">
    <i class="fa fa-expand-alt"></i> <?php echo xlt('Expand All'); ?>
  </button>
  <button type="button" class="btn mdi-fc-toolbar-btn" onclick="$('.formrow .collapse').collapse('hide');">
    <i class="fa fa-compress-alt"></i> <?php echo xlt('Collapse All'); ?>
  </button>
</div>

<?php
if (
    $pass_sens_squad &&
    ($result = getFormByEncounter(
        $attendant_id,
        $encounter,
        "id, date, form_id, form_name, formdir, user, deleted",
        "",
        "FIND_IN_SET(formdir,'newpatient') DESC, form_name, date DESC"
    ))
) {
    echo "<table class='w-100' id='partable'>";
    $divnos = 1;
    foreach ($result as $iter) {
        $formdir = $iter['formdir'];

        // skip forms whose 'deleted' flag is set to 1
        if ($iter['deleted'] == 1) {
            continue;
        }
        
    

        // Skip MBO (Modify Billing Order) forms — handled inline in the ledger.
        // Skip Misc Billing Options form.
        if ($formdir === 'misc_billing_options') {
            continue;
        }

        // Skip LBF billing/referral forms that don't belong in the encounter view:
        //   - LBFref  : referral/billing info (Box 17/14/15) — auto-attached but not clinical
        //   - Any LBF that resolves to a blank display title (renders as just "by [user]")
        if (substr($formdir, 0, 3) === 'LBF') {
            // Exact known billing LBFs
            if (in_array($formdir, ['LBFref', 'LBFbilling', 'LBFclaim'])) {
                continue;
            }
            // LBF whose translated title is blank — nothing useful to show
            $resolved_title = xl_form_title($iter['form_name'] ?? '');
            if (trim($resolved_title) === '') {
                continue;
            }
        }

        $aco_spec = false;

        if (substr($formdir, 0, 3) == 'LBF') {
            // Skip LBF forms that we are not authorized to see.
            $lrow = sqlQuery(
                "SELECT grp_aco_spec " .
                "FROM layout_group_properties WHERE " .
                "grp_form_id = ? AND grp_group_id = '' AND grp_activity = 1",
                array($formdir)
            );
            if (!empty($lrow)) {
                if (!empty($lrow['grp_aco_spec'])) {
                    $aco_spec = explode('|', $lrow['grp_aco_spec']);
                    if (!AclMain::aclCheckCore($aco_spec[0], $aco_spec[1])) {
                        continue;
                    }
                }
            }
        } else {
          // Skip non-LBF forms that we are not authorized to see.
            $tmp = getRegistryEntryByDirectory($formdir, 'aco_spec');
            if (!empty($tmp['aco_spec'])) {
                $aco_spec = explode('|', $tmp['aco_spec']);
                if (!AclMain::aclCheckCore($aco_spec[0], $aco_spec[1])) {
                    continue;
                }
            }
        }

        // $form_info = getFormInfoById($iter['id']);
        if (strtolower(substr($iter['form_name'], 0, 5)) == 'camos') {
            //CAMOS generates links from report.php and these links should
            //be clickable without causing view.php to come up unexpectedly.
            //I feel that the JQuery code in this file leading to a click
            //on the report.php content to bring up view.php steps on a
            //form's autonomy to generate it's own html content in it's report
            //but until any other form has a problem with this, I will just
            //make an exception here for CAMOS and allow it to carry out this
            //functionality for all other forms.  --Mark
            echo '<tr title="' . xla('Edit form') . '" ' .
                  'id="' . attr($formdir) . '~' . attr($iter['form_id']) . '">';
        } else {
            echo '<tr id="' . attr($formdir) . '~' . attr($iter['form_id']) . '" class="text onerow">';
        }

        $acl_groups = AclMain::aclCheckCore("groups", "glog", false, 'write') ? true : false;
        $user = (new UserService())->getUserByUsername($iter['user']);

        if ($formdir === 'newpatient') {
            // newpatient formdir = the Visit Summary (facility/provider/reason card)
            $form_name = xl('Visit Summary');
        } elseif ($formdir === 'questionnaire_assessments') {
            // questionnaire_assessments = Patient Intake Questionnaire
            // xl_form_title() may return blank if not in registry — hardcode label
            $form_name = xl('Patient Intake Questionnaire');
        } else {
            $resolved  = xl_form_title($iter['form_name'] ?? '');
            $form_name = (trim($resolved) !== '') ? $resolved : xl($iter['form_name'] ?? 'Unknown Form');
        }

        // Create the ESign instance for this form
        $esign = $esignApi->createFormESign($iter['id'], $formdir, $encounter);

        // Whether this row starts expanded — computed here (rather than
        // just before the body div, as before) so the header can reflect
        // the same state (rounded-all-corners when collapsed, chevron
        // orientation, etc.)
        // All form cards start collapsed on load — no exception for the
        // first one, and ignore $GLOBALS['expand_form'] here so the
        // encounter always opens with a clean, collapsed list.
        $divExpand = '';
        $rowCollapsedClass = ($divExpand === 'show') ? '' : ' mdi-fc-collapsed';
        $chevronCollapsedClass = ($divExpand === 'show') ? '' : ' collapsed';

        // echo "<tr>"; // Removed as bug fix.

        echo "<td class='border-bottom border-dark" . $rowCollapsedClass . "'>";

        // Use the user who saved the form as the author for all form types.
        // Fall back to the encounter provider only if the user field is empty.
        $form_author_raw = trim(($user['fname'] ?? '') . '  ' . ($user['lname'] ?? ''));
        $form_author = ($form_author_raw !== '') ? $form_author_raw : $providerNameRes;
        $div_nums_attr = attr($divnos);
        $author_text   = text($form_author);
        $by_text       = xlt("by");
        $form_text     = text($form_name);

        // newpatient gets its own hardcoded header ("Visit Summary") because
        // xl_form_title('newpatient') returns blank — no registry entry.
        // questionnaire_assessments gets "Patient Intake Questionnaire" for the same reason.
        if ($formdir === 'newpatient') {
            $display_title = 'Visit Summary';
        } elseif ($formdir === 'questionnaire_assessments') {
            $display_title = 'Patient Intake Questionnaire';
        } else {
            $display_title = $form_name; // already resolved above
        }
        $form_text_display = text($display_title);

        $form_date_display = '';
        if (!empty($iter['date']) && strtotime($iter['date']) > 0) {
            $form_date_display = text(date('m/d/Y', strtotime($iter['date'])));
        }
        $form_date_html = $form_date_display
            ? '<span class="mdi-fc-date">' . $form_date_display . '</span>'
            : '';

        $lockedIconClass = $esign->isLocked() ? 'locked' : 'open';
        $lockedIconSvg = $esign->isLocked()
            ? '<svg width="14" height="14" fill="none" stroke="#059669" stroke-width="2.2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>'
            : '<svg width="14" height="14" fill="none" stroke="#7c3aed" stroke-width="2.2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';

        echo <<<HTML
        <div class="form_header">
            <a href="#" data-toggle="collapse" data-target="#divid_{$div_nums_attr}" id="aid_{$div_nums_attr}" class="mdi-fc-title-wrap">
                <span class="mdi-fc-icon {$lockedIconClass}">{$lockedIconSvg}</span>
                <span class="mdi-fc-titles">
                    <h5 style="margin:0;">
                        <span class="mdi-fc-name-row">
                            <span class="mdi-fc-name">{$form_text_display}</span>
                            {$form_date_html}
                        </span>
                        <span class="mdi-fc-by">{$by_text} {$author_text}</span>
                    </h5>
                </span>
            </a>
        </div>
        <div class='form_header_controls btn-group' role='group'>
        HTML;

        // If the form is locked, it is no longer editable
        if ($esign->isLocked()) {
                 echo "<a href='#' class='btn btn-secondary btn-sm form-edit-button-locked' id='form-edit-button-" . attr($formdir) . "-" . attr($iter['id']) . "'><i class='fa fa-lock fa-fw'></i>&nbsp;" . xlt('Locked') . "</a>";
        } else {
            if (
                (!$aco_spec || AclMain::aclCheckCore($aco_spec[0], $aco_spec[1], '', 'write') and $is_group == 0 and $authPostCalendarCategoryWrite)
                or (((!$aco_spec || AclMain::aclCheckCore($aco_spec[0], $aco_spec[1], '', 'write')) and $is_group and AclMain::aclCheckCore("groups", "glog", false, 'write')) and $authPostCalendarCategoryWrite)
            ) {
                $edit_label = $formdir === 'newpatient'
                    ? xl('Visit Summary')
                    : ($formdir === 'questionnaire_assessments'
                        ? xl('Patient Intake Questionnaire')
                        : $form_name);
                echo "<a class='btn btn-secondary btn-sm form-edit-button btn-edit' " .
                    "id='form-edit-button-" . attr($formdir) . "-" . attr($iter['id']) . "' " .
                    "href='#' " .
                    "title='" . xla('Edit this form') . "' " .
                    "onclick=\"return openEncounterForm(" . attr_js($formdir) . ", " .
                    attr_js($edit_label) . ", " . attr_js($iter['form_id']) . ")\">";
                echo "" . xlt('Edit') . "</a>";
            }
        }

        if (($esign->isButtonViewable() and $is_group == 0 and $authPostCalendarCategoryWrite) or ($esign->isButtonViewable() and $is_group and AclMain::aclCheckCore("groups", "glog", false, 'write') and $authPostCalendarCategoryWrite)) {
            if (!$aco_spec || AclMain::aclCheckCore($aco_spec[0], $aco_spec[1], '', 'write')) {
                echo $esign->buttonHtml();
            }
        }

        if (substr($formdir, 0, 3) == 'LBF') {
          // A link for a nice printout of the LBF
            echo "<a target='_blank' " .
            "href='$rootdir/forms/LBF/printable.php?"   .
            "formname="   . attr_url($formdir)         .
            "&formid="    . attr_url($iter['form_id']) .
            "&visitid="   . attr_url($encounter)       .
            "&patientid=" . attr_url($pid)             .
            "' class='btn btn-secondary btn-sm' title='" . xla('Print this form') .
            "' onclick='top.restoreSession()'>" . xlt('Print') . "</a>";
        }

        if (AclMain::aclCheckCore('admin', 'super')) {
            if ($formdir != 'newpatient' && $formdir != 'newGroupEncounter') {
                // a link to delete the form from the encounter
                echo "<a href='$rootdir/patient_file/encounter/delete_form.php?" .
                    "formname=" . attr_url($formdir) .
                    "&id=" . attr_url($iter['id']) .
                    "&encounter=" . attr_url($encounter) .
                    "&pid=" . attr_url($pid) .
                    "' class='btn btn-danger btn-sm btn-delete' title='" . xla('Delete this form') . "' onclick='top.restoreSession()'>" . xlt('Delete') . "</a>";
            } else {
                // do not show delete button for main encounter here since it is displayed at top
            }
        }

        echo "<a class='btn btn-secondary btn-sm collapse-button-form' title='" . xla('Expand/Collapse this form') . "' data-toggle='collapse' data-target='#divid_" . attr($divnos) . "'>" . xlt('Expand/Collapse') . "</a>";
        echo "<a href='#' class='mdi-fc-chevron" . $chevronCollapsedClass . "' title='" . xla('Expand/Collapse this form') . "' data-toggle='collapse' data-target='#divid_" . attr($divnos) . "'>"
           . "<svg width='13' height='13' fill='none' stroke='currentColor' stroke-width='2.5' viewBox='0 0 24 24'><polyline points='6 9 12 15 18 9'/></svg></a>";
        echo "</div>\n"; // Added as bug fix.

        echo "</td>\n";
        echo "</tr>";
        echo "<tr>";
        echo "<td class='formrow'><div id='divid_" . attr($divnos) . "' ";
        echo "class='mb-5 collapse " . attr($divExpand) . "'>";

        // Use the form's report.php for display.  Forms with names starting with LBF
        // are list-based forms sharing a single collection of code.
        //
        if (substr($formdir, 0, 3) == 'LBF') {
            include_once($GLOBALS['incdir'] . "/forms/LBF/report.php");
            lbf_report($attendant_id, $encounter, 2, $iter['form_id'], $formdir, true);
        } elseif ($formdir === 'questionnaire_assessments') {
            // Buffer so any unexpected output or legacy die() can't break the page.
            ob_start();
            include_once($GLOBALS['incdir'] . "/forms/questionnaire_assessments/report.php");
            call_user_func('questionnaire_assessments_report', $attendant_id, $encounter, 2, $iter['form_id']);
            echo ob_get_clean();
        } elseif ($formdir === 'mditek_imaging_findings') {
            // Render imaging findings inline — no separate report.php needed
            $imf_row = sqlQuery(
                "SELECT findings, created_at FROM mdi_imaging_findings
                  WHERE pid=? AND encounter=?
                  ORDER BY created_at DESC LIMIT 1",
                [$pid, $encounter]
            );
            if (!empty($imf_row['findings'])) {
                echo '<div style="padding:14px 18px;font-family:system-ui,sans-serif;">';
                echo '<div style="font-size:11px;font-weight:700;text-transform:uppercase;'
                   . 'letter-spacing:.06em;color:#6b7280;margin-bottom:8px;">'
                   . xlt('Imaging Findings') . '</div>';
                echo '<div style="font-size:13px;color:#1e0a3c;line-height:1.65;white-space:pre-wrap;">'
                   . htmlspecialchars($imf_row['findings']) . '</div>';
                if (!empty($imf_row['created_at'])) {
                    echo '<div style="margin-top:10px;font-size:11px;color:#9ca3af;">'
                       . xlt('Recorded') . ': '
                       . text(date('m/d/Y g:i A', strtotime($imf_row['created_at'])))
                       . '</div>';
                }
                echo '</div>';
            } else {
                echo '<div style="padding:14px 18px;font-size:12px;color:#9ca3af;font-style:italic;">'
                   . xlt('No imaging findings recorded.') . '</div>';
            }
        } else {
            if ($formdir === 'newpatient') {
                // ── Visit Summary — render from variables already fetched at top of file.
                // Do NOT call newpatient_report() — it queries form_newpatient which doesn't exist.
                // Do NOT add new form_encounter queries — pc_catname / class don't exist on all installs.
                // $facilityRow, $encData, $posRow, $providerNameRes are all set at top of file.
                echo '<div style="padding:14px 18px;font-family:system-ui,sans-serif;font-size:13px;color:#1e0a3c;line-height:1.75;">';
                $fn = trim($facilityRow['name'] ?? '');
                $fc = trim(($facilityRow['city'] ?? '') . ', ' . ($facilityRow['state'] ?? '') . ' ' . ($facilityRow['postal_code'] ?? ''));
                if ($fn) {
                    echo '<div><strong>' . xlt('Facility') . ':</strong> ' . text($fn);
                    if (trim($fc, ', ') !== '') echo ' &ndash; ' . text(trim($fc, ', '));
                    echo '</div>';
                }
                if (!empty($encData['reason'])) {
                    echo '<div><strong>' . xlt('Reason') . ':</strong> ' . text($encData['reason']) . '</div>';
                }
                if ($providerNameRes) {
                    echo '<div>Provider: ' . text($providerNameRes) . '</div>';
                }
                // Referring provider — fetch only the safe column
                $fe_ref_row = sqlQuery(
                    "SELECT referring_provider_id FROM form_encounter WHERE encounter=? AND pid=? LIMIT 1",
                    [$encounter, $pid]
                );
                $fe_ref_id = (int)($fe_ref_row['referring_provider_id'] ?? 0);
                if ($fe_ref_id) {
                    $fe_ref_name = getProviderName($fe_ref_id, false);
                    if (trim($fe_ref_name)) {
                        echo '<div>' . xlt('Referring Provider') . ': ' . text($fe_ref_name) . '</div>';
                    }
                }
                if (!empty($posRow['pos_code'])) {
                    echo '<div>' . xlt('POS Code') . ': ' . text($posRow['pos_code']) . '</div>';
                }
                echo '</div>';
            } else {
            // Standard form: include report.php if it exists, silently skip if not
            $report_file = $GLOBALS['incdir'] . "/forms/$formdir/report.php";
            if (file_exists($report_file)) {
                include_once($report_file);
                if (function_exists($formdir . '_report')) {
                    // Wrap in ob + try to prevent any SQL errors from killing the page
                    ob_start();
                    try {
                        call_user_func($formdir . "_report", $attendant_id, $encounter, 2, $iter['form_id']);
                    } catch (\Throwable $e) {
                        ob_get_clean();
                        echo '<div style="padding:10px 16px;font-size:12px;color:#9ca3af;font-style:italic;">'
                           . xlt('Unable to render form report.') . '</div>';
                        error_log('forms.php report render error for ' . $formdir . ': ' . $e->getMessage());
                    }
                    if (ob_get_level()) echo ob_get_clean();
                }
            } else {
                // No report.php for this form — show a plain fallback
                echo '<div style="padding:10px 16px;font-size:12px;color:#9ca3af;font-style:italic;">'
                   . xlt('No printable report available for this form type.') . '</div>';
            }
            } // end else (non-newpatient)
        }

        if ($esign->isLogViewable()) {
            $esign->renderLog();
        }

        echo "</div></td></tr>";
        ++$divnos;
    }
    echo "</table>";
}
if (!$pass_sens_squad) {
    echo xlt("Not authorized to view this encounter");
}
?>

</div><!-- end large encounter_forms DIV -->
<!-- ══════════ STICKY ESIGN FOOTER ══════════ -->
<div id="esign-footer" style="
  position: fixed !important; bottom: 0 !important; left: 0 !important; right: 0 !important;
  z-index: 9999 !important;
  background: #ffffff !important;
  border-top: 2px solid #e4ddf5 !important;
  box-shadow: 0 -4px 24px rgba(124,58,237,.10) !important;
  padding: 0 24px !important;
  display: flex !important; align-items: center !important;
  height: 68px !important;
  width: 100% !important;
  box-sizing: border-box !important;
">
<div style="width:90%; margin:auto;">
  <div style="display:flex;align-items:center;gap:20px;flex:1">
    <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted)">Encounter Signature</span>

    <!-- Shown only after esign is completed -->
<span id="esign-signed-info" style="display:<?php echo $esignSignedAt ? 'flex' : 'none'; ?>;font-size:12px;color:#059669;font-weight:600;gap:6px;align-items:center;">
  <svg width="13" height="13" fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
  Signed by <?php echo htmlspecialchars($esignSignerName); ?>
  &nbsp;&mdash;&nbsp;
  <span id="esign-signed-time"><?php echo htmlspecialchars($esignSignedAt); ?></span>
</span>

<!-- Shown only before esign is completed (hidden once signed) -->
<span id="esign-pending-info" style="display:<?php echo $esignSignedAt ? 'none' : 'inline'; ?>;font-size:12px;color:#7c6f96;margin-left:4px;">
  <?php echo htmlspecialchars(date('m/d/Y g:i A')); ?>
</span>


    <div style="width:1px;height:32px;background:var(--border)"></div>

    <div style="text-align:right">
      <?php
      $esign = $esignApi->createEncounterESign($encounter);
      if ($esign->isButtonViewable()) {
      ?>
      <button type="button" id="mdiSignEncounterBtn" onclick="mdiSignEncounterClick()"
          style="display:inline-flex;align-items:center;gap:7px;background:#7c3aed;color:#fff;border:none;
                 border-radius:8px;padding:9px 18px;font-size:13px;font-weight:700;cursor:pointer;
                 font-family:inherit;transition:background .12s;">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
          </svg>
          Sign Encounter
      </button>
      <?php } ?>
    </div>
  </div>
</div>
<script>
// Close notes dropdown on outside click
document.addEventListener('click', function() {
    var dd = document.getElementById('notes-dd');
    if (dd) dd.classList.remove('notes-dd-open');
});
document.getElementById('notes-dd-wrap') && document.getElementById('notes-dd-wrap').addEventListener('click', function(e) {
    e.stopPropagation();
});


// Hook into the encounterConfig afterFormSuccess already defined above
// by observing DOM changes to the esign log area
var esignObserver = new MutationObserver(function(mutations) {
    mutations.forEach(function(m) {
        if (m.addedNodes.length) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType === 1) {
                    var text = node.textContent || '';
                    if (text.toLowerCase().includes('signed') || node.querySelector && node.querySelector('.esign-locked')) {
                        onEncounterSigned();
                    }
                }
            });
        }
    });
});

var logTarget = document.querySelector('[id^="esign-signature-log-encounter-"]');
if (logTarget) {
    esignObserver.observe(logTarget.parentNode || document.body, { childList: true, subtree: true });
}

function onEncounterSigned() {
    var timeEl = document.getElementById('esign-signed-time');
    if (timeEl && !timeEl.textContent.trim()) {
        var now = new Date();
        timeEl.textContent = now.toLocaleDateString('en-US') + ' ' +
            now.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
    }
    document.getElementById('esign-signed-info').style.display = 'flex';
    document.getElementById('esign-pending-info').style.display = 'none';
    var amc = document.getElementById('amc-notice');
    if (amc) {
        amc.style.transition = 'opacity .4s';
        amc.style.opacity = '0';
        setTimeout(function() { amc.style.display = 'none'; }, 420);
    }
}

</script>
</div>

<!-- ── Sign Encounter — PIN modal ────────────────────────────────────────
     Replaces the native eSign password prompt. First click tries a
     one-click sign (in case the provider already verified their PIN
     earlier this session); only if that comes back "need_pin" do we show
     this prompt. -->
<div id="mdiPinModal" style="display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;">
    <div onclick="mdiPinModalClose()" style="position:absolute;inset:0;background:rgba(10,5,25,0.55);backdrop-filter:blur(2px)"></div>
    <div style="position:relative;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.25);width:min(340px,90vw);overflow:hidden;animation:mdiPinModalIn 0.15s ease">
        <div style="padding:1.5rem 1.5rem 1.1rem;text-align:center;">
            <div style="width:44px;height:44px;border-radius:50%;background:#f5f2fc;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
            </div>
            <div style="font-size:1rem;font-weight:700;color:#1e0a3c;">Enter Signing PIN</div>
            <div style="font-size:0.78rem;color:#6b7280;margin-top:5px;">Confirm your identity to sign this encounter.</div>
        </div>
        <div style="padding:0 1.5rem 0.5rem;">
            <input id="mdiPinInput" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off"
                placeholder="••••" autofocus
                style="width:100%;padding:12px;border:1px solid #e2e6ed;border-radius:8px;font-size:1.3rem;text-align:center;letter-spacing:6px;box-sizing:border-box;font-family:inherit;">
            <div id="mdiPinError" style="display:none;font-size:0.75rem;color:#dc2626;margin-top:8px;text-align:center;"></div>
        </div>
        <div style="padding:1rem 1.5rem 1.5rem;display:flex;flex-direction:column;gap:8px;">
            <button type="button" id="mdiPinSubmitBtn" onclick="mdiPinSubmit()"
                style="padding:10px;font-size:13px;font-weight:700;background:#7c3aed;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;">
                Sign Encounter
            </button>
            <button type="button" onclick="mdiPinModalClose()"
                style="padding:10px;font-size:13px;font-weight:600;background:#fff;color:#374151;border:1px solid #e2e6ed;border-radius:8px;cursor:pointer;font-family:inherit;">
                Cancel
            </button>
        </div>
    </div>
</div>

<style>
@keyframes mdiPinModalIn { from{opacity:0;transform:translateY(8px) scale(0.98)} to{opacity:1;transform:translateY(0) scale(1)} }
</style>

<script>
(function () {
    'use strict';

    var MDI_PIN_API = <?php echo js_escape($web_root); ?> + '/interface/esign/verify_pin.php';
    var MDI_PIN_CSRF = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
    var MDI_ENCOUNTER = <?php echo js_escape((string)$encounter); ?>;
    var MDI_PID = <?php echo js_escape((string)$pid); ?>;

    function postSign(pin) {
        var body = new FormData();
        body.append('encounter', MDI_ENCOUNTER);
        body.append('pid', MDI_PID);
        body.append('csrf_token_form', MDI_PIN_CSRF);
        if (pin) body.append('pin', pin);

        return fetch(MDI_PIN_API, {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        }).then(function (r) {
            return r.json();
        });
    }

    function setBtnBusy(btn, busy, label) {
        if (!btn) return;
        btn.disabled = busy;
        btn.textContent = label;
    }

    function updateSignedUI() {

        // Existing callback
        if (typeof onEncounterSigned === 'function') {
            onEncounterSigned();
        }

        // Hide AMC Notice
        var amc = document.getElementById('amc-notice');
        if (amc) {
            amc.style.transition = 'opacity .2s';
            amc.style.opacity = '0';
            setTimeout(function () {
                amc.style.display = 'none';
            }, 200);
        }

        // Hide Sign Encounter button
        var signBtn = document.getElementById('mdiSignEncounterBtn');
        if (signBtn) {
            signBtn.style.display = 'none';
        }

        // Update status chip if present
        var chip = document.getElementById('overall-sig-chip');
        if (chip) {
            chip.innerHTML =
                '<div class="status-dot open"></div>Encounter Signed';
        }

        // Disable remaining sign buttons
        document.querySelectorAll('.esign-button-form').forEach(function (btn) {
            btn.disabled = true;
        });

        // Refresh after user sees the UI update
        setTimeout(function () {
            location.reload();
        }, 500);
    }

    window.mdiSignEncounterClick = function () {
        var btn = document.getElementById('mdiSignEncounterBtn');
        setBtnBusy(btn, true, 'Signing…');

        postSign(null).then(function (data) {

            if (data && data.success) {
                updateSignedUI();
                return;
            }

            setBtnBusy(btn, false, 'Sign Encounter');

            if (data && data.need_pin) {
                mdiPinModalOpen();
            } else {
                alert((data && data.message) || 'Could not sign encounter. Please try again.');
            }

        }).catch(function () {
            setBtnBusy(btn, false, 'Sign Encounter');
            alert('Could not sign encounter. Please try again.');
        });
    };

    window.mdiPinModalOpen = function () {
        document.getElementById('mdiPinModal').style.display = 'flex';
        document.getElementById('mdiPinInput').value = '';
        document.getElementById('mdiPinError').style.display = 'none';

        setTimeout(function () {
            var el = document.getElementById('mdiPinInput');
            if (el) {
                el.focus();
            }
        }, 30);
    };

    window.mdiPinModalClose = function () {
        document.getElementById('mdiPinModal').style.display = 'none';
    };

    window.mdiPinSubmit = function () {

        var pin = document.getElementById('mdiPinInput').value.trim();
        var errEl = document.getElementById('mdiPinError');

        if (!/^\d{4,8}$/.test(pin)) {
            errEl.textContent = 'Enter your 4–8 digit PIN.';
            errEl.style.display = 'block';
            return;
        }

        var btn = document.getElementById('mdiPinSubmitBtn');
        setBtnBusy(btn, true, 'Verifying…');

        postSign(pin).then(function (data) {

            setBtnBusy(btn, false, 'Sign Encounter');

            if (data && data.success) {
                mdiPinModalClose();
                updateSignedUI();
                return;
            }

            errEl.textContent = (data && data.message) || 'Incorrect PIN.';
            errEl.style.display = 'block';

            document.getElementById('mdiPinInput').value = '';
            document.getElementById('mdiPinInput').focus();

        }).catch(function () {

            setBtnBusy(btn, false, 'Sign Encounter');

            errEl.textContent = 'Could not verify PIN. Please try again.';
            errEl.style.display = 'block';

        });
    };

    var pinInput = document.getElementById('mdiPinInput');

    if (pinInput) {
        pinInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                mdiPinSubmit();
            }
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            mdiPinModalClose();
        }
    });

})();
</script>

<!-- ── Patient Forms Library panel ─────────────────────────────────────────
     Wide slide-in drawer from the right, with a dimmed full-page overlay
     behind it (matches the mockup) and two tabs:
       "My Forms" — a pinned Patient Intake Questionnaire (always visible
       at the top, regardless of which encounter it came from) plus a
       timeline of the last 6 prior encounters.
       "All Forms" — every prior encounter, with search + category/
       provider/time filters.
     Both tabs support Preview (read-only render via the form's own
     report.php) and Attach (copies the form's data forward into the
     current encounter, pre-filled). -->
<div id="mdiAttachOverlay" onclick="mdiCloseAttachPanel()" style="position:fixed;inset:0;background:rgba(10,5,25,.45);z-index:99998;opacity:0;visibility:hidden;transition:opacity .2s ease;"></div>
<div id="mdiAttachPanel" style="position:fixed;top:0;right:0;bottom:0;width:min(700px,94vw);background:#fff;z-index:99999;box-shadow:-12px 0 40px rgba(20,10,50,.22);display:flex;flex-direction:column;transform:translateX(100%);transition:transform .26s cubic-bezier(.2,.8,.2,1);">

    <div style="padding:18px 24px;border-bottom:1px solid #e4ddf5;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:38px;height:38px;border-radius:10px;background:#ede9fe;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg width="18" height="18" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
                <div style="font-size:16px;font-weight:800;color:#1e0a3c;">Patient Forms Library</div>
                <div style="font-size:11.5px;color:#9197b3;margin-top:1px;">Attach a form from a previous visit to this encounter.</div>
            </div>
        </div>
        <button onclick="mdiCloseAttachPanel()" type="button"
            style="background:none;border:none;cursor:pointer;color:#9197b3;font-size:20px;line-height:1;padding:4px;">✕</button>
    </div>

    <div style="display:flex;gap:6px;padding:0 24px;border-bottom:1px solid #e4ddf5;flex-shrink:0;">
        <button type="button" class="mdi-af-tab active" data-tab="my_forms" onclick="mdiAfSwitchTab('my_forms')">My Forms <span style="font-weight:500;opacity:.7;">(from previous visits)</span></button>
        <button type="button" class="mdi-af-tab" data-tab="all_forms" onclick="mdiAfSwitchTab('all_forms')">All Forms <span style="font-weight:500;opacity:.7;">(full history)</span></button>
    </div>

    <!-- ── My Forms tab ── -->
    <div id="mdiAfPaneMy" class="mdi-af-pane" style="flex:1;min-height:0;display:flex;flex-direction:column;">
        <div style="flex:1;overflow-y:auto;padding:16px 24px 20px;">
            <div id="mdiAfPinnedWrap" style="display:none;margin-bottom:20px;"></div>

            <div style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:#9197b3;margin-bottom:8px;">From Recent Encounters</div>
            <div id="mdiAfMyList" class="mdi-af-timeline"></div>
        </div>
        <div style="padding:11px 24px;border-top:1px solid #e4ddf5;flex-shrink:0;display:flex;align-items:center;gap:8px;background:#fbfbfd;">
            <svg width="13" height="13" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <span style="font-size:11px;color:#6b7280;">Click <strong>Preview</strong> to quickly review a form before attaching. Use <strong>Attach</strong> to add it to this encounter.</span>
        </div>
    </div>

    <!-- ── All Forms tab ── -->
    <div id="mdiAfPaneAll" class="mdi-af-pane" style="display:none;flex:1;min-height:0;flex-direction:column;">
        <div style="padding:14px 24px 10px;border-bottom:1px solid #f0edf9;flex-shrink:0;">
            <div style="position:relative;margin-bottom:8px;">
                <svg width="13" height="13" fill="none" stroke="#9197b3" stroke-width="2" viewBox="0 0 24 24" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input id="mdiAfSearch" type="text" placeholder="Search forms..." oninput="mdiAfDebouncedSearch()"
                    style="width:100%;padding:8px 10px 8px 30px;border:1px solid #e4ddf5;border-radius:8px;font-size:12.5px;box-sizing:border-box;font-family:inherit;">
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <select id="mdiAfCategory" onchange="mdiAfLoadAllForms()" class="mdi-af-filter">
                    <option value="">All Categories</option>
                    <option value="Clinical">Clinical</option>
                    <option value="Outcome">Outcome</option>
                </select>
                <select id="mdiAfProvider" onchange="mdiAfLoadAllForms()" class="mdi-af-filter">
                    <option value="">All Providers</option>
                </select>
                <select id="mdiAfTime" onchange="mdiAfLoadAllForms()" class="mdi-af-filter">
                    <option value="">All Time</option>
                    <option value="30">Last 30 Days</option>
                    <option value="90">Last 90 Days</option>
                    <option value="365">Last Year</option>
                </select>
                <button type="button" onclick="mdiAfClearFilters()" class="mdi-af-clear-btn">Clear Filters</button>
            </div>
        </div>

        <div style="flex:1;overflow-y:auto;padding:14px 24px 20px;">
            <div id="mdiAfAllList" class="mdi-af-timeline"></div>
        </div>

        <div style="padding:11px 24px;border-top:1px solid #e4ddf5;flex-shrink:0;display:flex;align-items:center;gap:8px;background:#fbfbfd;">
            <svg width="13" height="13" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
            <span id="mdiAfAllFooterNote" style="font-size:11px;color:#6b7280;">Showing all forms from all visits. Use filters to narrow your search.</span>
        </div>
    </div>
</div>

<!-- ── Form Preview modal ── -->
<div id="mdiAfPreviewOverlay" onclick="mdiAfClosePreview()" style="position:fixed;inset:0;background:rgba(10,5,25,.45);z-index:100000;opacity:0;visibility:hidden;transition:opacity .18s ease;"></div>
<div id="mdiAfPreviewModal" style="position:fixed;top:50%;left:50%;width:min(680px,92vw);max-height:82vh;background:#fff;z-index:100001;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.28);display:flex;flex-direction:column;transform:translate(-50%,-50%) scale(.96);opacity:0;visibility:hidden;transition:transform .18s ease,opacity .18s ease,visibility .18s ease;">
    <div style="padding:14px 18px;border-bottom:1px solid #e4ddf5;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
        <div id="mdiAfPreviewTitle" style="font-size:14px;font-weight:800;color:#1e0a3c;">Preview</div>
        <button onclick="mdiAfClosePreview()" type="button" style="background:none;border:none;cursor:pointer;color:#9197b3;font-size:18px;line-height:1;padding:4px;">✕</button>
    </div>
    <div id="mdiAfPreviewBody" style="flex:1;overflow-y:auto;padding:16px 18px;font-size:13px;color:#1e0a3c;"></div>
</div>

<style>
#mdiAttachOverlay.open, #mdiAfPreviewOverlay.open { opacity: 1 !important; visibility: visible !important; }
#mdiAttachPanel.open { transform: translateX(0) !important; }
#mdiAfPreviewModal.open { transform: translate(-50%,-50%) scale(1) !important; opacity: 1 !important; visibility: visible !important; }

.mdi-af-tab {
    border: none; background: none; cursor: pointer; font-family: inherit;
    font-size: 13.5px; font-weight: 700; color: #9197b3;
    padding: 12px 6px 11px; margin-right: 22px; border-bottom: 2.5px solid transparent;
}
.mdi-af-tab.active { color: #7c3aed; border-bottom-color: #7c3aed; }

.mdi-af-filter {
    font-size: 11.5px; font-family: inherit; border: 1px solid #e4ddf5; border-radius: 7px;
    padding: 6px 8px; background: #fff; color: #374151;
}
.mdi-af-clear-btn {
    margin-left: auto; font-size: 11.5px; font-weight: 600; color: #7c3aed; background: none;
    border: none; cursor: pointer; font-family: inherit; padding: 6px 4px;
}
.mdi-af-clear-btn:hover { text-decoration: underline; }

/* Pinned form */
.mdi-af-pinned-box { border: 1.5px solid #ddd6fe; background: #faf8ff; border-radius: 12px; padding: 12px 14px; }
.mdi-af-pinned-label { display: flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: #7c3aed; margin-bottom: 10px; }
.mdi-af-pinned-row { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid #ede9fe; border-radius: 9px; padding: 10px 12px; }

/* Timeline (encounter groups) */
.mdi-af-timeline { display: flex; flex-direction: column; gap: 8px; }
.mdi-af-enc-group { border: 1px solid #e4ddf5; border-radius: 10px; overflow: hidden; }
.mdi-af-enc-head {
    display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 10px 12px; background: #fbfbfd;
}
.mdi-af-enc-date { font-size: 12.5px; font-weight: 700; color: #1e0a3c; }
.mdi-af-enc-sub { font-size: 10.5px; color: #9197b3; margin-top: 1px; }
.mdi-af-enc-badge {
    margin-left: auto; font-size: 10.5px; font-weight: 700; color: #5b21b6; background: #ede9fe;
    border-radius: 20px; padding: 3px 10px; white-space: nowrap;
}
.mdi-af-enc-chevron { color: #9197b3; transition: transform .15s; flex-shrink: 0; }
.mdi-af-enc-group.collapsed .mdi-af-enc-chevron { transform: rotate(-90deg); }
.mdi-af-enc-body { border-top: 1px solid #e4ddf5; }
.mdi-af-enc-group.collapsed .mdi-af-enc-body { display: none; }
.mdi-af-form-row {
    display: flex; align-items: center; gap: 10px; padding: 9px 12px;
    border-top: 1px solid #f0edf9;
}
.mdi-af-form-row:first-child { border-top: none; }
.mdi-af-form-icon {
    width: 26px; height: 26px; border-radius: 7px; background: #ede9fe; color: #7c3aed; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.mdi-af-form-name { font-size: 12px; font-weight: 700; color: #1e0a3c; }
.mdi-af-cat-badge {
    font-size: 9.5px; font-weight: 700; border-radius: 5px; padding: 1px 7px; margin-left: 8px; vertical-align: middle;
}
.mdi-af-cat-badge.Clinical { background: #dbeafe; color: #1d4ed8; }
.mdi-af-cat-badge.Outcome  { background: #d1fae5; color: #047857; }
.mdi-af-cat-badge.Intake   { background: #dbeafe; color: #1d4ed8; }
.mdi-af-form-date { font-size: 10.5px; color: #9197b3; margin-top: 1px; }
.mdi-af-row-actions { margin-left: auto; display: flex; gap: 6px; flex-shrink: 0; }
.mdi-af-btn-preview {
    font-size: 11px; font-weight: 700; color: #5b21b6; background: #fff; border: 1.5px solid #ddd6fe;
    border-radius: 7px; padding: 5px 10px; cursor: pointer; font-family: inherit; display: inline-flex; align-items: center; gap: 4px;
    white-space: nowrap;
}
.mdi-af-btn-preview:hover { background: #faf8ff; }
.mdi-af-btn-attach {
    font-size: 11px; font-weight: 700; color: #fff; background: #7c3aed; border: none;
    border-radius: 7px; padding: 5px 12px; cursor: pointer; font-family: inherit; white-space: nowrap;
}
.mdi-af-btn-attach:hover { background: #6d28d9; }
.mdi-af-btn-attach:disabled { opacity: .6; cursor: default; }
.mdi-af-empty { text-align: center; color: #9197b3; font-size: 12.5px; padding: 30px 10px; line-height: 1.6; }
</style>

<script>
(function () {
    'use strict';

    var MDI_ATTACH_API = <?php echo js_escape($web_root); ?> + '/interface/patient_file/encounter/mdi_attach_forms_api.php';
    var MDI_ATTACH_CSRF = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
    var MDI_ATTACH_ENCOUNTER = <?php echo js_escape((string)$encounter); ?>;
    var MDI_ATTACH_PID = <?php echo js_escape((string)$pid); ?>;

    var activeTab = 'my_forms';
    var myFormsLoaded = false;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    var docIconSvg = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';

    // ── Open / close ────────────────────────────────────────────────────
    window.mdiOpenAttachPanel = function () {
        try {
            var overlay = document.getElementById('mdiAttachOverlay');
            var panel = document.getElementById('mdiAttachPanel');
            if (!overlay || !panel) {
                alert('Attach Patient Forms panel markup not found on this page. Try a hard refresh (Ctrl/Cmd+Shift+R).');
                return false;
            }
            overlay.classList.add('open');
            panel.classList.add('open');
            mdiAfSwitchTab('my_forms');
        } catch (err) {
            console.error('mdiOpenAttachPanel failed:', err);
            alert('Could not open the Patient Forms panel: ' + err.message);
        }
        return false;
    };
    window.mdiCloseAttachPanel = function () {
        document.getElementById('mdiAttachOverlay').classList.remove('open');
        document.getElementById('mdiAttachPanel').classList.remove('open');
    };

    // ── Tabs ────────────────────────────────────────────────────────────
    window.mdiAfSwitchTab = function (tab) {
        activeTab = tab;
        document.querySelectorAll('.mdi-af-tab').forEach(function (t) {
            t.classList.toggle('active', t.dataset.tab === tab);
        });
        document.getElementById('mdiAfPaneMy').style.display = (tab === 'my_forms') ? 'flex' : 'none';
        document.getElementById('mdiAfPaneAll').style.display = (tab === 'all_forms') ? 'flex' : 'none';

        if (tab === 'my_forms' && !myFormsLoaded) {
            myFormsLoaded = true;
            mdiAfLoadMyForms();
        } else if (tab === 'all_forms') {
            mdiAfLoadAllForms();
        }
    };

    // ── My Forms (pinned intake + last 6 encounters) ─────────────────────
    function mdiAfLoadMyForms() {
        var url = MDI_ATTACH_API + '?action=my_forms&pid=' + encodeURIComponent(MDI_ATTACH_PID)
                + '&encounter=' + encodeURIComponent(MDI_ATTACH_ENCOUNTER);
        var listEl = document.getElementById('mdiAfMyList');
        listEl.innerHTML = '<div class="mdi-af-empty">Loading…</div>';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(renderMyForms)
            .catch(function () { listEl.innerHTML = '<div class="mdi-af-empty">Could not load forms.</div>'; });
    }

    function renderMyForms(data) {
        var pinnedWrap = document.getElementById('mdiAfPinnedWrap');
        if (data.pinned) {
            var f = data.pinned;
            pinnedWrap.style.display = 'block';
            pinnedWrap.innerHTML =
                '<div class="mdi-af-pinned-box">'
                + '<div class="mdi-af-pinned-label">'
                + '<svg width="12" height="12" fill="#7c3aed" stroke="#7c3aed" stroke-width="1" viewBox="0 0 24 24"><path d="M12 2l2.4 7.2H22l-6 4.6 2.3 7.2L12 16.4 5.7 21l2.3-7.2-6-4.6h7.6z"/></svg>'
                + 'Pinned Form (always visible)</div>'
                + '<div class="mdi-af-pinned-row">'
                + '<div class="mdi-af-form-icon">' + docIconSvg + '</div>'
                + '<div style="min-width:0;flex:1;">'
                + '<span class="mdi-af-form-name">' + escapeHtml(f.label) + '</span>'
                + '<span class="mdi-af-cat-badge Intake">Intake</span>'
                + '<div class="mdi-af-form-date">Completed ' + escapeHtml(f.date) + (f.provider ? ' &middot; Dr. ' + escapeHtml(f.provider.replace(/^Dr\.?\s*/i, '')) : '') + '</div>'
                + '</div>'
                + '<div class="mdi-af-row-actions">'
                + '<button type="button" class="mdi-af-btn-preview" onclick="mdiAfPreview(' + f.id + ')">'
                + '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>Preview</button>'
                + '<button type="button" class="mdi-af-btn-attach" onclick="mdiAttachForm(' + f.id + ', this)">+ Attach</button>'
                + '</div>'
                + '</div>'
                + '</div>';
        } else {
            pinnedWrap.style.display = 'none';
        }

        var listEl = document.getElementById('mdiAfMyList');
        var encounters = data.encounters || [];
        if (!encounters.length) {
            listEl.innerHTML = '<div class="mdi-af-empty">No forms found in this patient\'s recent encounters.</div>';
            return;
        }
        listEl.innerHTML = encounters.map(function (enc, idx) {
            return renderEncounterGroup(enc, 'my', idx, idx === 0);
        }).join('');
    }

    // ── All Forms (search + filters) ─────────────────────────────────────
    var searchDebounce = null;
    window.mdiAfDebouncedSearch = function () {
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(mdiAfLoadAllForms, 300);
    };

    window.mdiAfClearFilters = function () {
        document.getElementById('mdiAfSearch').value = '';
        document.getElementById('mdiAfCategory').value = '';
        document.getElementById('mdiAfProvider').value = '';
        document.getElementById('mdiAfTime').value = '';
        mdiAfLoadAllForms();
    };

    var providersPopulated = false;

    window.mdiAfLoadAllForms = function () {
        var search = document.getElementById('mdiAfSearch').value.trim();
        var category = document.getElementById('mdiAfCategory').value;
        var provider = document.getElementById('mdiAfProvider').value;
        var time = document.getElementById('mdiAfTime').value;

        var url = MDI_ATTACH_API + '?action=all_forms&pid=' + encodeURIComponent(MDI_ATTACH_PID)
                + '&encounter=' + encodeURIComponent(MDI_ATTACH_ENCOUNTER)
                + '&search=' + encodeURIComponent(search)
                + '&category=' + encodeURIComponent(category)
                + '&provider=' + encodeURIComponent(provider)
                + '&time=' + encodeURIComponent(time);

        var listEl = document.getElementById('mdiAfAllList');
        listEl.innerHTML = '<div class="mdi-af-empty">Loading…</div>';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!providersPopulated && data.providers && data.providers.length) {
                    var sel = document.getElementById('mdiAfProvider');
                    data.providers.forEach(function (p) {
                        var opt = document.createElement('option');
                        opt.value = p.id; opt.textContent = p.name;
                        sel.appendChild(opt);
                    });
                    providersPopulated = true;
                }
                renderAllForms(data);
            })
            .catch(function () { listEl.innerHTML = '<div class="mdi-af-empty">Could not load forms.</div>'; });
    };

    function renderAllForms(data) {
        var listEl = document.getElementById('mdiAfAllList');
        var encounters = data.encounters || [];
        if (!encounters.length) {
            listEl.innerHTML = '<div class="mdi-af-empty">No forms match your search.</div>';
            document.getElementById('mdiAfAllFooterNote').textContent = 'No results.';
            return;
        }
        listEl.innerHTML = encounters.map(function (enc, idx) {
            return renderEncounterGroup(enc, 'all', idx, idx === 0);
        }).join('');
        document.getElementById('mdiAfAllFooterNote').textContent =
            'Showing all forms from all visits. Use filters to narrow your search.';
    }

    // ── Shared encounter-group + form-row rendering ──────────────────────
    function renderEncounterGroup(enc, ns, idx, expanded) {
        var groupId = 'mdiAf_' + ns + '_' + idx;
        var subParts = [];
        if (enc.reason) subParts.push(escapeHtml(enc.reason));
        if (enc.provider) subParts.push('Dr. ' + escapeHtml(enc.provider.replace(/^Dr\.?\s*/i, '')));
        subParts.push(enc.signed ? '<span style="color:#059669;font-weight:600;">Signed</span>' : '<span style="color:#d97706;font-weight:600;">Unsigned</span>');

        var rows = (enc.forms || []).map(function (f) {
            return '<div class="mdi-af-form-row">'
                + '<div class="mdi-af-form-icon">' + docIconSvg + '</div>'
                + '<div style="min-width:0;flex:1;">'
                + '<span class="mdi-af-form-name">' + escapeHtml(f.label) + '</span>'
                + '<span class="mdi-af-cat-badge ' + f.category + '">' + f.category + '</span>'
                + '<div class="mdi-af-form-date">' + escapeHtml(f.date) + '</div>'
                + '</div>'
                + '<div class="mdi-af-row-actions">'
                + '<button type="button" class="mdi-af-btn-preview" onclick="mdiAfPreview(' + f.id + ')">'
                + '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>Preview</button>'
                + '<button type="button" class="mdi-af-btn-attach" onclick="mdiAttachForm(' + f.id + ', this)">+ Attach</button>'
                + '</div>'
                + '</div>';
        }).join('');

        return '<div class="mdi-af-enc-group' + (expanded ? '' : ' collapsed') + '" id="' + groupId + '">'
            + '<div class="mdi-af-enc-head" onclick="mdiAfToggleGroup(\'' + groupId + '\')">'
            + '<svg width="15" height="15" fill="none" stroke="#7c3aed" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'
            + '<div style="min-width:0;flex:1;">'
            + '<div class="mdi-af-enc-date">' + escapeHtml(enc.date) + (enc.date_label ? ' &middot; ' + escapeHtml(enc.date_label) : '') + '</div>'
            + '<div class="mdi-af-enc-sub">' + subParts.join(' &middot; ') + '</div>'
            + '</div>'
            + '<span class="mdi-af-enc-badge">' + (enc.forms ? enc.forms.length : 0) + ' Form' + ((enc.forms && enc.forms.length === 1) ? '' : 's') + '</span>'
            + '<svg class="mdi-af-enc-chevron" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>'
            + '</div>'
            + '<div class="mdi-af-enc-body">' + rows + '</div>'
            + '</div>';
    }

    window.mdiAfToggleGroup = function (groupId) {
        var el = document.getElementById(groupId);
        if (el) el.classList.toggle('collapsed');
    };

    // ── Preview modal ───────────────────────────────────────────────────
    window.mdiAfPreview = function (formId) {
        var overlay = document.getElementById('mdiAfPreviewOverlay');
        var modal = document.getElementById('mdiAfPreviewModal');
        var body = document.getElementById('mdiAfPreviewBody');
        document.getElementById('mdiAfPreviewTitle').textContent = 'Preview';
        body.innerHTML = '<div class="mdi-af-empty">Loading…</div>';
        overlay.classList.add('open');
        modal.classList.add('open');

        var url = MDI_ATTACH_API + '?action=preview&form_id=' + encodeURIComponent(formId)
                + '&pid=' + encodeURIComponent(MDI_ATTACH_PID);
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    document.getElementById('mdiAfPreviewTitle').textContent = data.label || 'Preview';
                    body.innerHTML = data.html || '<div class="mdi-af-empty">Nothing to show.</div>';
                } else {
                    body.innerHTML = '<div class="mdi-af-empty">' + escapeHtml((data && data.message) || 'Could not load preview.') + '</div>';
                }
            })
            .catch(function () {
                body.innerHTML = '<div class="mdi-af-empty">Could not load preview.</div>';
            });
    };
    window.mdiAfClosePreview = function () {
        document.getElementById('mdiAfPreviewOverlay').classList.remove('open');
        document.getElementById('mdiAfPreviewModal').classList.remove('open');
    };

    // ── Attach ──────────────────────────────────────────────────────────
    window.mdiAttachForm = function (sourceFormId, btnEl) {
        btnEl.disabled = true;
        btnEl.textContent = 'Attaching…';
        var body = new FormData();
        body.append('action', 'attach');
        body.append('pid', MDI_ATTACH_PID);
        body.append('encounter', MDI_ATTACH_ENCOUNTER);
        body.append('source_form_id', sourceFormId);
        body.append('csrf_token_form', MDI_ATTACH_CSRF);
        fetch(MDI_ATTACH_API, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    btnEl.textContent = 'Attached ✓';
                    location.reload();
                } else {
                    btnEl.disabled = false;
                    btnEl.textContent = '+ Attach';
                    alert((data && data.message) || 'Could not attach this form. Please try again.');
                }
            })
            .catch(function () {
                btnEl.disabled = false;
                btnEl.textContent = '+ Attach';
                alert('Could not attach this form. Please try again.');
            });
    };

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        mdiAfClosePreview();
        mdiCloseAttachPanel();
    });
})();
</script>



</body>
</html>

</html>
