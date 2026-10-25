<?php

/**
 * new_comprehensive_save.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2009-2017 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Services\ContactService;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

// Validation for non-unique external patient identifier.
$alertmsg = '';
if (!empty($_POST["form_pubpid"])) {
    $form_pubpid = trim($_POST["form_pubpid"]);
    $result = sqlQuery("SELECT count(*) AS count FROM patient_data WHERE " .
    "pubpid = ?", array($form_pubpid));
    if ($result['count']) {
        // Error, not unique.
        $alertmsg = xl('Warning: Patient ID is not unique!');
    }
}

require_once("$srcdir/pid.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");

// Update patient_data and employer_data:
// First, we prepare the data for insert into DB by querying the layout
// fields to see what valid fields we have to insert from the post we are receiving
$newdata = array();
$newdata['patient_data'] = array();
$newdata['employer_data'] = array();
$fres = sqlStatement("SELECT * FROM layout_options " .
  "WHERE form_id = 'DEM' AND (uor > 0 OR field_id = 'pubpid') AND field_id != '' " .
  "ORDER BY group_id, seq");
$addressFieldsToSave = array();
while ($frow = sqlFetchArray($fres)) {
    $data_type = $frow['data_type'];
    $field_id  = $frow['field_id'];
  // $value     = '';
    $colname   = $field_id;
    $tblname   = 'patient_data';
    if (strpos($field_id, 'em_') === 0) {
        $colname = substr($field_id, 3);
        $tblname = 'employer_data';
    }

  //get value only if field exist in $_POST (prevent deleting of field with disabled attribute)
    // TODO: why is this a different conditional than demographics_save.php...
    if ($data_type == 54) { // address list
        $addressFieldsToSave[$field_id] = get_layout_form_value($frow);
    } else if (isset($_POST["form_$field_id"]) || $field_id == "pubpid") {
        $value = get_layout_form_value($frow);
        $newdata[$tblname][$colname] = $value;
    }
}

// Auto-assign pubpid from last numeric pubpid if not supplied or not unique.
if (empty($newdata['patient_data']['pubpid']) || !empty($alertmsg)) {
    $pubpid_row = sqlQuery(
        "SELECT MAX(CAST(pubpid AS UNSIGNED)) AS maxpub
         FROM patient_data
         WHERE pubpid REGEXP '^[0-9]+$'"
    );
    $newdata['patient_data']['pubpid'] = str_pad(
        intval($pubpid_row['maxpub'] ?? 0) + 1,
        8, '0', STR_PAD_LEFT
    );
    $alertmsg = ''; // clear the "not unique" warning since we've assigned a fresh one
}

// Force names to uppercase to match system-wide naming convention.
foreach (['fname', 'mname', 'lname'] as $_nf) {
    if (!empty($newdata['patient_data'][$_nf])) {
        $newdata['patient_data'][$_nf] = strtoupper(trim($newdata['patient_data'][$_nf]));
    }
}

// Use the global helper to use the PatientService to create a new patient
// The result contains the pid, so use that to set the global session pid
$pid = updatePatientData(null, $newdata['patient_data'], true);
if (empty($pid)) {
    die("Internal error: setpid(" . text($pid) . ") failed!");
}
setpid($pid);

// ── MDiTek: Save Provider and Referring Provider ──────────────────────────
$new_provider_id = (int)filter_input(INPUT_POST, 'form_providerID');
$new_ref_prov_id = (int)filter_input(INPUT_POST, 'form_ref_providerID');
if ($new_provider_id || $new_ref_prov_id) {
    sqlQuery(
        "UPDATE patient_data SET providerID = ?, ref_providerID = ? WHERE pid = ?",
        [$new_provider_id ?: null, $new_ref_prov_id ?: null, $pid]
    );
}
// ── end MDiTek ────────────────────────────────────────────────────────────
if (!$GLOBALS['omit_employers']) {
    updateEmployerData($pid, $newdata['employer_data'], true);
}

if (!empty($addressFieldsToSave)) {
    // TODO: we would handle other types of address fields here, for now we will just go through and populate the patient
    // address information
    // TODO: how are error messages supposed to display if the save fails?
    foreach ($addressFieldsToSave as $field => $addressFieldData) {
        // if we need to save other kinds of addresses we could do that here with our field column...
        $contactService = new ContactService();
        $contactService->saveContactsForPatient($pid, $addressFieldData);
    }
}

$i1dob = DateToYYYYMMDD(filter_input(INPUT_POST, "i1subscriber_DOB"));
$i1date = DateToYYYYMMDD(filter_input(INPUT_POST, "i1effective_date"));

newHistoryData($pid);
// no need to save insurance for simple demos
if (!$GLOBALS['simplified_demographics']) {
    newInsuranceData(
        $pid,
        "primary",
        filter_input(INPUT_POST, "i1provider"),
        filter_input(INPUT_POST, "i1policy_number"),
        filter_input(INPUT_POST, "i1group_number"),
        filter_input(INPUT_POST, "i1plan_name"),
        filter_input(INPUT_POST, "i1subscriber_lname"),
        filter_input(INPUT_POST, "i1subscriber_mname"),
        filter_input(INPUT_POST, "i1subscriber_fname"),
        filter_input(INPUT_POST, "form_i1subscriber_relationship"),
        filter_input(INPUT_POST, "i1subscriber_ss"),
        $i1dob,
        filter_input(INPUT_POST, "i1subscriber_street"),
        filter_input(INPUT_POST, "i1subscriber_postal_code"),
        filter_input(INPUT_POST, "i1subscriber_city"),
        filter_input(INPUT_POST, "form_i1subscriber_state"),
        filter_input(INPUT_POST, "form_i1subscriber_country"),
        filter_input(INPUT_POST, "i1subscriber_phone"),
        filter_input(INPUT_POST, "i1subscriber_employer"),
        filter_input(INPUT_POST, "i1subscriber_employer_street"),
        filter_input(INPUT_POST, "i1subscriber_employer_city"),
        filter_input(INPUT_POST, "i1subscriber_employer_postal_code"),
        filter_input(INPUT_POST, "form_i1subscriber_employer_state"),
        filter_input(INPUT_POST, "form_i1subscriber_employer_country"),
        filter_input(INPUT_POST, 'i1copay'),
        filter_input(INPUT_POST, 'form_i1subscriber_sex'),
        $i1date,
        filter_input(INPUT_POST, 'i1accept_assignment')
    );

    //Dont save more than one insurance since only one is allowed / save space in DB
    if (!$GLOBALS['insurance_only_one']) {
        $i2dob = DateToYYYYMMDD(filter_input(INPUT_POST, "i2subscriber_DOB"));
        $i2date = DateToYYYYMMDD(filter_input(INPUT_POST, "i2effective_date"));

        newInsuranceData(
            $pid,
            "secondary",
            filter_input(INPUT_POST, "i2provider"),
            filter_input(INPUT_POST, "i2policy_number"),
            filter_input(INPUT_POST, "i2group_number"),
            filter_input(INPUT_POST, "i2plan_name"),
            filter_input(INPUT_POST, "i2subscriber_lname"),
            filter_input(INPUT_POST, "i2subscriber_mname"),
            filter_input(INPUT_POST, "i2subscriber_fname"),
            filter_input(INPUT_POST, "form_i2subscriber_relationship"),
            filter_input(INPUT_POST, "i2subscriber_ss"),
            $i2dob,
            filter_input(INPUT_POST, "i2subscriber_street"),
            filter_input(INPUT_POST, "i2subscriber_postal_code"),
            filter_input(INPUT_POST, "i2subscriber_city"),
            filter_input(INPUT_POST, "form_i2subscriber_state"),
            filter_input(INPUT_POST, "form_i2subscriber_country"),
            filter_input(INPUT_POST, "i2subscriber_phone"),
            filter_input(INPUT_POST, "i2subscriber_employer"),
            filter_input(INPUT_POST, "i2subscriber_employer_street"),
            filter_input(INPUT_POST, "i2subscriber_employer_city"),
            filter_input(INPUT_POST, "i2subscriber_employer_postal_code"),
            filter_input(INPUT_POST, "form_i2subscriber_employer_state"),
            filter_input(INPUT_POST, "form_i2subscriber_employer_country"),
            filter_input(INPUT_POST, 'i2copay'),
            filter_input(INPUT_POST, 'form_i2subscriber_sex'),
            $i2date,
            filter_input(INPUT_POST, 'i2accept_assignment')
        );

        $i3dob = DateToYYYYMMDD(filter_input(INPUT_POST, "i3subscriber_DOB"));
        $i3date = DateToYYYYMMDD(filter_input(INPUT_POST, "i3effective_date"));

        newInsuranceData(
            $pid,
            "tertiary",
            filter_input(INPUT_POST, "i3provider"),
            filter_input(INPUT_POST, "i3policy_number"),
            filter_input(INPUT_POST, "i3group_number"),
            filter_input(INPUT_POST, "i3plan_name"),
            filter_input(INPUT_POST, "i3subscriber_lname"),
            filter_input(INPUT_POST, "i3subscriber_mname"),
            filter_input(INPUT_POST, "i3subscriber_fname"),
            filter_input(INPUT_POST, "form_i3subscriber_relationship"),
            filter_input(INPUT_POST, "i3subscriber_ss"),
            $i3dob,
            filter_input(INPUT_POST, "i3subscriber_street"),
            filter_input(INPUT_POST, "i3subscriber_postal_code"),
            filter_input(INPUT_POST, "i3subscriber_city"),
            filter_input(INPUT_POST, "form_i3subscriber_state"),
            filter_input(INPUT_POST, "form_i3subscriber_country"),
            filter_input(INPUT_POST, "i3subscriber_phone"),
            filter_input(INPUT_POST, "i3subscriber_employer"),
            filter_input(INPUT_POST, "i3subscriber_employer_street"),
            filter_input(INPUT_POST, "i3subscriber_employer_city"),
            filter_input(INPUT_POST, "i3subscriber_employer_postal_code"),
            filter_input(INPUT_POST, "form_i3subscriber_employer_state"),
            filter_input(INPUT_POST, "form_i3subscriber_employer_country"),
            filter_input(INPUT_POST, 'i3copay'),
            filter_input(INPUT_POST, 'form_i3subscriber_sex'),
            $i3date,
            filter_input(INPUT_POST, 'i3accept_assignment')
        );
    }
}
// Collect display values for the success screen.
$_success_fname = $newdata['patient_data']['fname'] ?? '';
$_success_mname = $newdata['patient_data']['mname'] ?? '';
$_success_lname = $newdata['patient_data']['lname'] ?? '';
$_success_pubpid = $newdata['patient_data']['pubpid'] ?? $pid;
$_success_dob   = $newdata['patient_data']['DOB'] ?? '';
$_success_sex   = $newdata['patient_data']['sex'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<?php use OpenEMR\Core\Header; Header::setupHeader(); ?>
<title><?php echo xlt('Patient Created'); ?></title>
<style>
  body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f2f9; display: flex; align-items: center; justify-content: center; min-height: 80vh; margin: 0; }
  .success-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(30,10,60,.13); padding: 40px 48px; max-width: 460px; width: 100%; text-align: center; }
  .success-icon { width: 64px; height: 64px; background: #d1fae5; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
  .success-icon svg { color: #059669; }
  h2 { color: #1e0a3c; font-size: 20px; font-weight: 700; margin: 0 0 6px; }
  .sub { color: #6b7280; font-size: 13px; margin-bottom: 28px; }
  .patient-info { background: #f5f2fc; border: 1px solid #ddd6f0; border-radius: 8px; padding: 16px 20px; margin-bottom: 24px; text-align: left; }
  .patient-info .row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
  .patient-info .row .label { color: #6b7280; font-weight: 600; }
  .patient-info .row .value { color: #1e0a3c; font-weight: 700; }
  .btn-go { display: inline-block; background: #6c5ce7; color: #fff; padding: 10px 28px; border-radius: 7px; text-decoration: none; font-size: 13px; font-weight: 700; border: none; cursor: pointer; transition: background .15s; }
  .btn-go:hover { background: #4c1d95; }
  .countdown { font-size: 11px; color: #9ca3af; margin-top: 12px; }
</style>
</head>
<body>
<div class="success-card">
  <div class="success-icon">
    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
  </div>
  <h2><?php echo xlt('Patient Created Successfully'); ?></h2>
  <p class="sub"><?php echo xlt('The new patient record has been saved.'); ?></p>
  <div class="patient-info">
    <div class="row">
      <span class="label"><?php echo xlt('Name'); ?></span>
      <span class="value"><?php echo text($_success_fname . ' ' . ($_success_mname ? $_success_mname . ' ' : '') . $_success_lname); ?></span>
    </div>
    <div class="row">
      <span class="label"><?php echo xlt('Patient #'); ?></span>
      <span class="value"><?php echo text($_success_pubpid); ?></span>
    </div>
    <div class="row">
      <span class="label"><?php echo xlt('Date of Birth'); ?></span>
      <span class="value"><?php echo text($_success_dob ? oeFormatShortDate($_success_dob) : '—'); ?></span>
    </div>
    <div class="row">
      <span class="label"><?php echo xlt('Sex'); ?></span>
      <span class="value"><?php echo text($_success_sex ?: '—'); ?></span>
    </div>
  </div>
  <a class="btn-go" href="<?php echo $rootdir; ?>/patient_file/summary/demographics.php?set_pid=<?php echo attr_url($pid); ?>&is_new=1">
    <?php echo xlt('Go to Patient Record'); ?>
  </a>
  <div class="countdown" id="countdown"><?php echo xlt('Redirecting in'); ?> <span id="secs">5</span><?php echo xlt('s'); ?>…</div>
</div>
<script>
<?php if ($alertmsg) { echo "alert(" . js_escape($alertmsg) . ");\n"; } ?>
var s = 5;
var dest = <?php echo js_escape($rootdir . '/patient_file/summary/demographics.php?set_pid=' . attr_url($pid) . '&is_new=1'); ?>;
var t = setInterval(function() {
    s--;
    var el = document.getElementById('secs');
    if (el) el.textContent = s;
    if (s <= 0) { clearInterval(t); top.restoreSession && top.restoreSession(); window.location = dest; }
}, 1000);
</script>
</body>
</html>

