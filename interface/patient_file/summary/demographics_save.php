<?php

/**
 * demographics_save.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/pid.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Services\ContactService;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

// Establish $pid from the posted value so the correct patient is always saved,
// regardless of session state (handles multi-tab usage and stale sessions).
global $pid;
if (!empty($_POST['pid'])) {
    setpid((int)$_POST['pid']);
}
// Check authorization.
if ($pid) {
    if (!AclMain::aclCheckCore('patients', 'demo', '', 'write')) {
        die(xlt('Updating demographics is not authorized.'));
    }

    $tmp = getPatientData($pid, "squad");
    if ($tmp['squad'] && ! AclMain::aclCheckCore('squads', $tmp['squad'])) {
        die(xlt('You are not authorized to access this squad.'));
    }
} else {
    if (!AclMain::aclCheckCore('patients', 'demo', '', array('write','addonly'))) {
        die(xlt('Adding demographics is not authorized.'));
    }
}

foreach ($_POST as $key => $val) {
    if ($val == "MM/DD/YYYY") {
        $_POST[$key] = "";
    }
}

// Update patient_data and employer_data:
//
$newdata = array();
$newdata['patient_data']['id'] = $_POST['db_id'];
$fres = sqlStatement("SELECT * FROM layout_options " .
  "WHERE form_id = 'DEM' AND uor > 0 AND field_id != '' " .
  "ORDER BY group_id, seq");

$addressFieldsToSave = array();
while ($frow = sqlFetchArray($fres)) {
    $data_type = $frow['data_type'];
    if ((int)$data_type === 52) {
        // patient name history is saved in add.
        continue;
    }
    $field_id = $frow['field_id'];
    $colname = $field_id;
    $table = 'patient_data';
    if (str_starts_with($field_id, 'em_')) {
        $colname = substr($field_id, 3);
        $table = 'employer_data';
    }

    // Get value only if field exist in $_POST (prevent deleting of field with disabled attribute)
    // *unless* the data_type is a checkbox ("21"), because if the checkbox is unchecked, then it will not
    // have a value set on the form, it will be empty.
    if ($data_type == 54) { // address list
        $addressFieldsToSave[$field_id] = get_layout_form_value($frow);
    } elseif (isset($_POST["form_$field_id"]) || $data_type == 21) {
        $newdata[$table][$colname] = get_layout_form_value($frow);
    }
}

// TODO: All of this should be bundled up inside a transaction...

updatePatientData($pid, $newdata['patient_data']);
if (!$GLOBALS['omit_employers']) {
    updateEmployerData($pid, $newdata['employer_data']);
}

if (!empty($addressFieldsToSave)) {
    // TODO: we would handle other types of address fields here,
    // for now we will just go through and populate the patient
    // address information
    // TODO: how are error messages supposed to display if the save fails?
    foreach ($addressFieldsToSave as $field => $addressFieldData) {
        // if we need to save other kinds of addresses we could do that here with our field column...
        $contactService = new ContactService();
        $contactService->saveContactsForPatient($pid, $addressFieldData);
    }
}

$i1dob      = DateToYYYYMMDD(filter_input(INPUT_POST, "i1subscriber_DOB"));
$i1date     = DateToYYYYMMDD(filter_input(INPUT_POST, "i1effective_date"));
$i1date_end = DateToYYYYMMDD(filter_input(INPUT_POST, "i1effective_date_end"));

$swap_value = $_POST['isSwapClicked'] ?? null;
$type = ($swap_value == '2') ? "secondary" : "primary";
$i1provider = filter_input(INPUT_POST, "i1provider");

if (!empty($i1provider)) {

    $existing = sqlQuery(
        "SELECT id, provider FROM insurance_data
         WHERE pid = ? AND type = ?
         ORDER BY id DESC LIMIT 1",
        [$pid, $type]
    );

    if (empty($existing['id']) || $existing['provider'] != $i1provider) {
        // Provider changed or no record exists — insert a new history row
        newInsuranceData(
            $pid,
            $type,
            $i1provider,
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
            filter_input(INPUT_POST, 'i1accept_assignment'),
            filter_input(INPUT_POST, 'i1policy_type'),
            $i1date_end
        );

        // Fetch the newly inserted row id
        $existing = sqlQuery(
            "SELECT id FROM insurance_data
             WHERE pid = ? AND type = ?
             ORDER BY id DESC LIMIT 1",
            [$pid, $type]
        );
    } else {
        // Provider unchanged — UPDATE all fields on the existing row so nothing is lost
        sqlStatement(
            "UPDATE insurance_data SET
                plan_name                        = ?,
                policy_number                    = ?,
                group_number                     = ?,
                subscriber_lname                 = ?,
                subscriber_mname                 = ?,
                subscriber_fname                 = ?,
                subscriber_relationship          = ?,
                subscriber_ss                    = ?,
                subscriber_DOB                   = ?,
                subscriber_street                = ?,
                subscriber_street_line_2         = ?,
                subscriber_postal_code           = ?,
                subscriber_city                  = ?,
                subscriber_state                 = ?,
                subscriber_country               = ?,
                subscriber_phone                 = ?,
                subscriber_employer              = ?,
                subscriber_employer_street       = ?,
                subscriber_employer_street_line_2= ?,
                subscriber_employer_city         = ?,
                subscriber_employer_postal_code  = ?,
                subscriber_employer_state        = ?,
                subscriber_employer_country      = ?,
                copay                            = ?,
                subscriber_sex                   = ?,
                date                             = ?,
                accept_assignment                = ?,
                policy_type                      = ?,
                date_end                         = ?
             WHERE id = ?",
            [
                filter_input(INPUT_POST, "i1plan_name"),
                filter_input(INPUT_POST, "i1policy_number"),
                filter_input(INPUT_POST, "i1group_number"),
                filter_input(INPUT_POST, "i1subscriber_lname"),
                filter_input(INPUT_POST, "i1subscriber_mname"),
                filter_input(INPUT_POST, "i1subscriber_fname"),
                filter_input(INPUT_POST, "form_i1subscriber_relationship"),
                filter_input(INPUT_POST, "i1subscriber_ss"),
                $i1dob,
                filter_input(INPUT_POST, "i1subscriber_street"),
                filter_input(INPUT_POST, "i1subscriber_street_line_2"),
                filter_input(INPUT_POST, "i1subscriber_postal_code"),
                filter_input(INPUT_POST, "i1subscriber_city"),
                filter_input(INPUT_POST, "form_i1subscriber_state"),
                filter_input(INPUT_POST, "form_i1subscriber_country"),
                filter_input(INPUT_POST, "i1subscriber_phone"),
                filter_input(INPUT_POST, "i1subscriber_employer"),
                filter_input(INPUT_POST, "i1subscriber_employer_street"),
                filter_input(INPUT_POST, "i1subscriber_employer_street_line_2"),
                filter_input(INPUT_POST, "i1subscriber_employer_city"),
                filter_input(INPUT_POST, "i1subscriber_employer_postal_code"),
                filter_input(INPUT_POST, "form_i1subscriber_employer_state"),
                filter_input(INPUT_POST, "form_i1subscriber_employer_country"),
                filter_input(INPUT_POST, 'i1copay'),
                filter_input(INPUT_POST, 'form_i1subscriber_sex'),
                $i1date,
                filter_input(INPUT_POST, 'i1accept_assignment'),
                filter_input(INPUT_POST, 'i1policy_type'),
                $i1date_end,
                $existing['id'],
            ]
        );
    }

    // ALWAYS update max_visits on the latest row
    if (!empty($existing['id'])) {
        $max_visits = isset($_POST["i1max_visits"]) ? (int)$_POST["i1max_visits"] : 0;
        sqlStatement(
            "UPDATE insurance_data SET max_visits = ? WHERE id = ?",
            [$max_visits, $existing['id']]
        );
    }
}

//Dont save more than one insurance since only one is allowed / save space in DB
if (!$GLOBALS['insurance_only_one']) {
    $i2dob      = DateToYYYYMMDD(filter_input(INPUT_POST, "i2subscriber_DOB"));
    $i2date     = DateToYYYYMMDD(filter_input(INPUT_POST, "i2effective_date"));
    $i2date_end = DateToYYYYMMDD(filter_input(INPUT_POST, "i2effective_date_end"));

    // secondary swaps with primary, tertiary with secondary
    if ($swap_value == '2') {
        $type = "primary";
    } elseif ($swap_value == '3') {
        $type = "tertiary";
    } else {
        $type = "secondary";
    }

    $i2provider = filter_input(INPUT_POST, "i2provider");

    if (!empty($i2provider)) {

        $existing = sqlQuery(
            "SELECT id, provider FROM insurance_data
             WHERE pid = ? AND type = ?
             ORDER BY id DESC LIMIT 1",
            [$pid, $type]
        );

        if (empty($existing['id']) || $existing['provider'] != $i2provider) {
            // Provider changed or no record — insert new history row
            newInsuranceData(
                $pid,
                $type,
                $i2provider,
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
                filter_input(INPUT_POST, 'i2accept_assignment'),
                filter_input(INPUT_POST, 'i2policy_type'),
                $i2date_end
            );

            // Fetch newly inserted row id
            $existing = sqlQuery(
                "SELECT id FROM insurance_data
                 WHERE pid = ? AND type = ?
                 ORDER BY id DESC LIMIT 1",
                [$pid, $type]
            );
        } else {
            // Provider unchanged — UPDATE all fields so nothing is lost
            sqlStatement(
                "UPDATE insurance_data SET
                    plan_name                        = ?,
                    policy_number                    = ?,
                    group_number                     = ?,
                    subscriber_lname                 = ?,
                    subscriber_mname                 = ?,
                    subscriber_fname                 = ?,
                    subscriber_relationship          = ?,
                    subscriber_ss                    = ?,
                    subscriber_DOB                   = ?,
                    subscriber_street                = ?,
                    subscriber_street_line_2         = ?,
                    subscriber_postal_code           = ?,
                    subscriber_city                  = ?,
                    subscriber_state                 = ?,
                    subscriber_country               = ?,
                    subscriber_phone                 = ?,
                    subscriber_employer              = ?,
                    subscriber_employer_street       = ?,
                    subscriber_employer_street_line_2= ?,
                    subscriber_employer_city         = ?,
                    subscriber_employer_postal_code  = ?,
                    subscriber_employer_state        = ?,
                    subscriber_employer_country      = ?,
                    copay                            = ?,
                    subscriber_sex                   = ?,
                    date                             = ?,
                    accept_assignment                = ?,
                    policy_type                      = ?,
                    date_end                         = ?
                 WHERE id = ?",
                [
                    filter_input(INPUT_POST, "i2plan_name"),
                    filter_input(INPUT_POST, "i2policy_number"),
                    filter_input(INPUT_POST, "i2group_number"),
                    filter_input(INPUT_POST, "i2subscriber_lname"),
                    filter_input(INPUT_POST, "i2subscriber_mname"),
                    filter_input(INPUT_POST, "i2subscriber_fname"),
                    filter_input(INPUT_POST, "form_i2subscriber_relationship"),
                    filter_input(INPUT_POST, "i2subscriber_ss"),
                    $i2dob,
                    filter_input(INPUT_POST, "i2subscriber_street"),
                    filter_input(INPUT_POST, "i2subscriber_street_line_2"),
                    filter_input(INPUT_POST, "i2subscriber_postal_code"),
                    filter_input(INPUT_POST, "i2subscriber_city"),
                    filter_input(INPUT_POST, "form_i2subscriber_state"),
                    filter_input(INPUT_POST, "form_i2subscriber_country"),
                    filter_input(INPUT_POST, "i2subscriber_phone"),
                    filter_input(INPUT_POST, "i2subscriber_employer"),
                    filter_input(INPUT_POST, "i2subscriber_employer_street"),
                    filter_input(INPUT_POST, "i2subscriber_employer_street_line_2"),
                    filter_input(INPUT_POST, "i2subscriber_employer_city"),
                    filter_input(INPUT_POST, "i2subscriber_employer_postal_code"),
                    filter_input(INPUT_POST, "form_i2subscriber_employer_state"),
                    filter_input(INPUT_POST, "form_i2subscriber_employer_country"),
                    filter_input(INPUT_POST, 'i2copay'),
                    filter_input(INPUT_POST, 'form_i2subscriber_sex'),
                    $i2date,
                    filter_input(INPUT_POST, 'i2accept_assignment'),
                    filter_input(INPUT_POST, 'i2policy_type'),
                    $i2date_end,
                    $existing['id'],
                ]
            );
        }

        // ALWAYS update max_visits on the latest record
        if (!empty($existing['id'])) {
            $max_visits = isset($_POST["i2max_visits"]) ? (int)$_POST["i2max_visits"] : 0;
            sqlStatement(
                "UPDATE insurance_data SET max_visits = ? WHERE id = ?",
                [$max_visits, $existing['id']]
            );
        }
    }
    $i3dob      = DateToYYYYMMDD(filter_input(INPUT_POST, "i3subscriber_DOB"));
    $i3date     = DateToYYYYMMDD(filter_input(INPUT_POST, "i3effective_date"));
    $i3date_end = DateToYYYYMMDD(filter_input(INPUT_POST, "i3effective_date_end"));

    $type = ($swap_value == '3') ? "secondary" : "tertiary";

    $i3provider = filter_input(INPUT_POST, "i3provider");

    if (!empty($i3provider)) {

        $existing = sqlQuery(
            "SELECT id, provider FROM insurance_data
             WHERE pid = ? AND type = ?
             ORDER BY id DESC LIMIT 1",
            [$pid, $type]
        );

        if (empty($existing['id']) || $existing['provider'] != $i3provider) {
            // Provider changed or no record — insert new history row
            newInsuranceData(
                $pid,
                $type,
                $i3provider,
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
                filter_input(INPUT_POST, 'i3accept_assignment'),
                filter_input(INPUT_POST, 'i3policy_type'),
                $i3date_end
            );

            // Fetch newly inserted row id
            $existing = sqlQuery(
                "SELECT id FROM insurance_data
                 WHERE pid = ? AND type = ?
                 ORDER BY id DESC LIMIT 1",
                [$pid, $type]
            );
        } else {
            // Provider unchanged — UPDATE all fields so nothing is lost
            sqlStatement(
                "UPDATE insurance_data SET
                    plan_name                        = ?,
                    policy_number                    = ?,
                    group_number                     = ?,
                    subscriber_lname                 = ?,
                    subscriber_mname                 = ?,
                    subscriber_fname                 = ?,
                    subscriber_relationship          = ?,
                    subscriber_ss                    = ?,
                    subscriber_DOB                   = ?,
                    subscriber_street                = ?,
                    subscriber_street_line_2         = ?,
                    subscriber_postal_code           = ?,
                    subscriber_city                  = ?,
                    subscriber_state                 = ?,
                    subscriber_country               = ?,
                    subscriber_phone                 = ?,
                    subscriber_employer              = ?,
                    subscriber_employer_street       = ?,
                    subscriber_employer_street_line_2= ?,
                    subscriber_employer_city         = ?,
                    subscriber_employer_postal_code  = ?,
                    subscriber_employer_state        = ?,
                    subscriber_employer_country      = ?,
                    copay                            = ?,
                    subscriber_sex                   = ?,
                    date                             = ?,
                    accept_assignment                = ?,
                    policy_type                      = ?,
                    date_end                         = ?
                 WHERE id = ?",
                [
                    filter_input(INPUT_POST, "i3plan_name"),
                    filter_input(INPUT_POST, "i3policy_number"),
                    filter_input(INPUT_POST, "i3group_number"),
                    filter_input(INPUT_POST, "i3subscriber_lname"),
                    filter_input(INPUT_POST, "i3subscriber_mname"),
                    filter_input(INPUT_POST, "i3subscriber_fname"),
                    filter_input(INPUT_POST, "form_i3subscriber_relationship"),
                    filter_input(INPUT_POST, "i3subscriber_ss"),
                    $i3dob,
                    filter_input(INPUT_POST, "i3subscriber_street"),
                    filter_input(INPUT_POST, "i3subscriber_street_line_2"),
                    filter_input(INPUT_POST, "i3subscriber_postal_code"),
                    filter_input(INPUT_POST, "i3subscriber_city"),
                    filter_input(INPUT_POST, "form_i3subscriber_state"),
                    filter_input(INPUT_POST, "form_i3subscriber_country"),
                    filter_input(INPUT_POST, "i3subscriber_phone"),
                    filter_input(INPUT_POST, "i3subscriber_employer"),
                    filter_input(INPUT_POST, "i3subscriber_employer_street"),
                    filter_input(INPUT_POST, "i3subscriber_employer_street_line_2"),
                    filter_input(INPUT_POST, "i3subscriber_employer_city"),
                    filter_input(INPUT_POST, "i3subscriber_employer_postal_code"),
                    filter_input(INPUT_POST, "form_i3subscriber_employer_state"),
                    filter_input(INPUT_POST, "form_i3subscriber_employer_country"),
                    filter_input(INPUT_POST, 'i3copay'),
                    filter_input(INPUT_POST, 'form_i3subscriber_sex'),
                    $i3date,
                    filter_input(INPUT_POST, 'i3accept_assignment'),
                    filter_input(INPUT_POST, 'i3policy_type'),
                    $i3date_end,
                    $existing['id'],
                ]
            );
        }

        // ALWAYS update max_visits on the latest record
        if (!empty($existing['id'])) {
            $max_visits = isset($_POST["i3max_visits"]) ? (int)$_POST["i3max_visits"] : 0;
            sqlStatement(
                "UPDATE insurance_data SET max_visits = ? WHERE id = ?",
                [$max_visits, $existing['id']]
            );
        }
    }
}

// Redirect back to the originating page (POST→Redirect→GET).
// This prevents: broken layout, CSRF error on refresh, and browser "resubmit form?" prompts.
$redirect_pid = $pid ?? ($_SESSION['pid'] ?? '');
$source       = $_POST['save_source'] ?? 'demographics_full';

if ($source === 'insurance_full') {
    header("Location: insurance_full.php?set_pid=" . urlencode($redirect_pid) . "&saved=1");
} else {
    header("Location: demographics_full.php?set_pid=" . urlencode($redirect_pid) . "&saved=1");
}
exit;

