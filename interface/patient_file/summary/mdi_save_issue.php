<?php
/**
 * MDItek - Save (insert or update) a medical issue from the custom
 * diagnosis/allergy/medication editors. Writes directly to the openemr
 * `lists` table.
 * Place in: /interface/patient_file/summary/mdi_save_issue.php
 *
 * Shared by three editors (see demographics.php):
 *   - Diagnosis editor  → type=medical_problem
 *   - Allergy editor    → type=allergy
 *   - Medication editor → type=medication
 *
 * BUGFIX: the INSERT branch previously hardcoded type='medical_problem'
 * regardless of what was actually POSTed, so every new Allergy/Medication
 * (issue id blank, i.e. "Add") was silently created as a Diagnosis instead
 * — it never appeared in the Allergy/Medication cards (which filter by
 * type), making "+ Add" look like it did nothing. The UPDATE branch also
 * never constrained by type, so an id collision across types was
 * theoretically possible. Both are fixed below by reading $_POST['type'],
 * validating it against a whitelist, and using it consistently in both the
 * INSERT and the UPDATE's WHERE clause.
 */

require_once("../../globals.php");
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

// Whitelist of `lists.type` values this endpoint is allowed to write —
// never trust $_POST['type'] directly into a query without validating it
// against known-good values first.
$allowedTypes = ['medical_problem', 'allergy', 'medication'];
$type = $_POST['type'] ?? 'medical_problem';
if (!in_array($type, $allowedTypes, true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid type']);
    exit;
}

$issueId       = (int)($_POST['issue']        ?? 0);
$pid           = (int)($_POST['pid']          ?? 0);
$title         = $_POST['title']              ?? '';
$activity      = (int)($_POST['activity']     ?? 1);
$verif         = $_POST['verification']       ?? 'confirmed';
$begdate       = $_POST['begdate']            ?? '';
$enddate       = $_POST['enddate']            ?? '';
$comments      = $_POST['comments']           ?? '';
$diagnosis     = $_POST['diagnosis']          ?? '';
// Allergy/medication-only fields — harmless empty values for Diagnosis saves.
$referredby    = $_POST['referredby']         ?? '';
$classification = $_POST['classification']    ?? '';
$occurrence    = $_POST['occurrence']         ?? '';
$severityAl    = $_POST['severity_al']        ?? '';
$reaction      = $_POST['reaction']           ?? '';
$outcome       = $_POST['outcome']            ?? '';

// Validate and sanitise dates
$begdate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $begdate) ? $begdate : null;
$enddate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $enddate) ? $enddate : null;

if (!$pid) {
    // If no pid in POST, look it up from the existing record
    if ($issueId) {
        $existing = sqlQuery("SELECT pid FROM lists WHERE id = ?", [$issueId]);
        $pid = (int)($existing['pid'] ?? 0);
    }
    if (!$pid) {
        echo json_encode(['success' => false, 'error' => 'Missing pid']);
        exit;
    }
}

$defaultTitles = [
    'medical_problem' => 'Default Diagnosis',
    'allergy'          => 'Default Allergy',
    'medication'       => 'Default Medication',
];
$title = $title ?: $defaultTitles[$type];
$now   = date('Y-m-d H:i:s');

// classification/occurrence are nullable ints in `lists` (per the table
// schema: `occurrence int YES default 0`, `classification int YES default
// 0`) — coerce blank strings to NULL rather than letting them become 0,
// since 0 is itself a meaningful selectable option value for some of these
// list_options and shouldn't be conflated with "not set".
//
// `outcome` is the one exception: the schema defines it as
// `outcome int NOT NULL default 0` — explicitly inserting NULL into a
// NOT NULL column fails the query regardless of SQL strict mode, which is
// exactly what was happening on every single save through this endpoint
// (including ordinary Diagnosis saves, since mdiSaveDiagnosis() in
// demographics.php never sends an `outcome` field at all, so it always
// defaulted to '' here and was being coerced straight into an illegal
// NULL). Use the schema's own default (0) instead of NULL for this one.
$classification = ($classification === '' ? null : (int)$classification);
$occurrence     = ($occurrence === ''     ? null : (int)$occurrence);
$outcome        = ($outcome === ''        ? 0    : (int)$outcome);

if ($issueId) {
    // WHERE includes type so an id can never cross-update a row of a
    // different type than the editor that's currently open.
    sqlStatement(
        "UPDATE lists
         SET title = ?, activity = ?, diagnosis = ?,
             begdate = ?, enddate = ?, verification = ?,
             comments = ?, referredby = ?, classification = ?,
             occurrence = ?, severity_al = ?, reaction = ?,
             outcome = ?, modifydate = ?
         WHERE id = ? AND pid = ? AND type = ?",
        [$title, $activity, $diagnosis, $begdate, $enddate,
         $verif, $comments, $referredby, $classification,
         $occurrence, $severityAl, $reaction, $outcome, $now,
         $issueId, $pid, $type]
    );
} else {
    sqlInsert(
        "INSERT INTO lists
            (pid, type, title, activity, diagnosis, begdate, enddate,
             verification, comments, referredby, classification,
             occurrence, severity_al, reaction, outcome, date, modifydate)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$pid, $type, $title, $activity, $diagnosis, $begdate, $enddate,
         $verif, $comments, $referredby, $classification,
         $occurrence, $severityAl, $reaction, $outcome, $now, $now]
    );
}

echo json_encode(['success' => true]);
