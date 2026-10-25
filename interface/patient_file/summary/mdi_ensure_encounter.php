<?php
require_once("../../globals.php");
require_once("$srcdir/pid.inc.php");
require_once("$srcdir/forms.inc.php"); // ✅ needed for generate_id()

use OpenEMR\Common\Session\SessionUtil;

$pid_in = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
if (!$pid_in) {
    die(xlt('Invalid patient ID.'));
}

setpid($pid_in);

$today = date('Y-m-d');
$now   = date('Y-m-d H:i:s');

// ✅ Reuse ANY encounter for today (enforces 1 per day)
$existing = sqlQuery(
    "SELECT id, encounter
       FROM form_encounter
      WHERE pid = ?
        AND DATE(date) = ?
      ORDER BY id DESC
      LIMIT 1",
    [$pid_in, $today]
);

if (!empty($existing['encounter'])) {
    $encounter_id = (int)$existing['encounter'];
} else {

    // ✅ Get provider + facility
    $pat         = sqlQuery("SELECT providerID FROM patient_data WHERE pid = ?", [$pid_in]);
    $provider_id = (int)($pat['providerID'] ?? $_SESSION['authUserID'] ?? 0);
    $facility_id = (int)($GLOBALS['facility_id'] ?? $_SESSION['facilityId'] ?? 1);
    $facility    = $_SESSION['facilityName'] ?? '';

    // ✅ FIX: proper encounter ID generation
    $encounter_id = generate_id();

    // ✅ IMPORTANT: get correct Office Visit category ID
    // (replace 5 if needed after checking your DB)
    $pc_catid = 5;

    // ✅ Create encounter (THIS drives UI fields)
    $form_encounter_id = sqlInsert(
        "INSERT INTO form_encounter
            (date, reason, facility, facility_id, pid, encounter, onset_date,
             sensitivity, pc_catid, provider_id, billing_facility,
             encounter_type_code, encounter_type_description, pos_code, class_code)
         VALUES (?, ?, ?, ?, ?, ?, ?,
                 'normal', ?, ?, ?,
                 'quick_charge', 'Quick Charge', 11, 'AMB')",
        [
            $now,
            'Quick Charge',   // dashboard label
            $facility,
            $facility_id,
            $pid_in,
            $encounter_id,
            $now,
            $pc_catid,        // Office Visit category
            $provider_id,
            $facility_id
        ]
    );

    $patient_defaults = sqlQuery(
        "SELECT initial_treatment_date, injury_date, last_xray_date
         FROM patient_data
         WHERE pid = ?",
        [$pid_in]
    );
    
    sqlStatement(
        "UPDATE form_encounter
         SET
            initial_treatment_date = COALESCE(initial_treatment_date, ?),
            injury_date = COALESCE(injury_date, ?),
            last_xray_date = COALESCE(last_xray_date, ?)
         WHERE id = ?",
        [
            $patient_defaults['initial_treatment_date'],
            $patient_defaults['injury_date'],
            $patient_defaults['last_xray_date'],
            $form_encounter_id
        ]
    );

    // ✅ Register encounter form properly
    sqlStatement(
        "INSERT INTO forms
            (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir)
         VALUES (?, ?, 'New Patient Encounter', ?, ?, ?, ?, 1, 0, 'newpatient')",
        [
            $now,
            $encounter_id,
            $form_encounter_id,   // ✅ correct linkage
            $pid_in,
            $_SESSION['authUser'] ?? '',
            $_SESSION['authProvider'] ?? 'Default'
        ]
    );

  // 🔍 Check if MBO already exists
$existing_mbo = sqlQuery(
    "SELECT fmbo.id
     FROM form_misc_billing_options fmbo
     JOIN forms f ON f.form_id = fmbo.id
     WHERE f.encounter = ?
       AND f.formdir = 'misc_billing_options'
       AND f.deleted = 0
     LIMIT 1",
    [$encounter_id]
);

if (empty($existing_mbo['id'])) {

    // 🔥 Pull encounter values (use ID, not encounter)
    $encounter_data = sqlQuery(
        "SELECT injury_date, initial_treatment_date
         FROM form_encounter
         WHERE id = ?",
        [$form_encounter_id]
    );

    // 🛟 Optional fallback
    if (empty($encounter_data['injury_date']) || empty($encounter_data['initial_treatment_date'])) {
        $patient_defaults = sqlQuery(
            "SELECT injury_date, initial_treatment_date
             FROM patient_data
             WHERE pid = ?",
            [$pid_in]
        );

        $encounter_data['injury_date'] = $encounter_data['injury_date'] ?: $patient_defaults['injury_date'];
        $encounter_data['initial_treatment_date'] = $encounter_data['initial_treatment_date'] ?: $patient_defaults['initial_treatment_date'];
    }

    // ✅ Insert MBO
    $mbo_id = sqlInsert(
        "INSERT INTO form_misc_billing_options
            (pid, onset_date, date_initial_treatment, box_15_date_qual)
         VALUES (?, ?, ?, ?)",
        [
            $pid_in,
            $encounter_data['injury_date'] ?: null,
            $encounter_data['initial_treatment_date'] ?: null,
            '454'
        ]
    );

    // ✅ Register form
    sqlStatement(
        "INSERT INTO forms
            (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir)
         VALUES (?, ?, 'Misc Billing Options', ?, ?, ?, ?, 1, 0, 'misc_billing_options')",
        [
            $now,
            $encounter_id,
            $mbo_id,
            $pid_in,
            $_SESSION['authUser'] ?? '',
            $_SESSION['authProvider'] ?? 'Default'
        ]
    );
}


    // ✅ Optional: keep note for reference (safe, not required)
    sqlStatement(
        "INSERT INTO pnotes
            (date, pid, user, groupname, authorized, title, body, activity)
         VALUES (NOW(), ?, ?, ?, 1, ?, ?, 1)",
        [
            $pid_in,
            $_SESSION['authUser'] ?? '',
            $_SESSION['authProvider'] ?? 'Default',
            'Default Visit Summary',
            "Visit Category: Office Visit\n" .
            "Class: Outpatient\n" .
            "Type: Established Patient - 10-19 Minutes\n" .
            "POS Code: 11 Office\n" .
            "Sensitivity: normal\n" .
            "Encounter Provider: Garza Oliver\n" .
            "Facility: {$facility}\n" .
            "Billing Facility: {$facility}\n\n" .
            "Physician: Please update this note."
        ]
    );
}

// ✅ Set session
SessionUtil::setSession('pid', $pid_in);
SessionUtil::setSession('encounter', $encounter_id);

// ✅ REDIRECT (better than require)
header("Location: " . $GLOBALS['webroot'] .
    "/interface/forms/fee_sheet/new.php?pid={$pid_in}&encounter={$encounter_id}");
exit;