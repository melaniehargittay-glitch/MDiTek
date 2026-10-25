<?php

/**
 * mdi_update_form_date.php
 *
 * AJAX endpoint used by the encounter workflow cards (forms.php) to save an
 * inline-edited date for a form/vitals/imaging entry without opening the
 * full form editor.
 *
 * Expects POST:
 *   pid, encounter, form_id, new_date (YYYY-MM-DD), field ('form'|'vitals'|'imaging'),
 *   csrf_token_form
 *
 * Responds with JSON: {"ok": true} or {"ok": false, "error": "..."}
 */

require_once(__DIR__ . "/../../globals.php");
require_once($GLOBALS["srcdir"] . "/api.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

function mdi_json_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

// Must be a logged-in session (globals.php already enforces this in most
// OpenEMR installs, but double-check before touching the DB).
if (empty($_SESSION['authUser'])) {
    mdi_json_fail('Not authenticated', 401);
}

if (empty($_POST['csrf_token_form']) || !CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'])) {
    mdi_json_fail('Invalid CSRF token', 403);
}

$pid       = !empty($_POST['pid']) ? (int)$_POST['pid'] : 0;
$encounter = !empty($_POST['encounter']) ? (int)$_POST['encounter'] : 0;
$formId    = !empty($_POST['form_id']) ? (int)$_POST['form_id'] : 0;
$newDate   = trim($_POST['new_date'] ?? '');
$field     = trim($_POST['field'] ?? 'form');

if (!$pid || !$encounter || !$formId) {
    mdi_json_fail('Missing required parameters');
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate) || strtotime($newDate) <= 0) {
    mdi_json_fail('Invalid date');
}

try {
    switch ($field) {
        case 'imaging':
            // mdi_imaging_findings rows are scoped by pid + encounter directly
            // (there's no forms.form_id link for this custom table).
            $row = sqlQuery(
                "SELECT id, created_at FROM mdi_imaging_findings WHERE id=? AND pid=? AND encounter=? LIMIT 1",
                [$formId, $pid, $encounter]
            );
            if (empty($row)) {
                mdi_json_fail('Imaging findings record not found for this encounter', 404);
            }
            $timePart = (!empty($row['created_at']) && strtotime($row['created_at']) > 0)
                ? date('H:i:s', strtotime($row['created_at']))
                : date('H:i:s');
            sqlStatement(
                "UPDATE mdi_imaging_findings SET created_at=? WHERE id=?",
                [$newDate . ' ' . $timePart, $formId]
            );
            break;

        case 'vitals':
        case 'form':
        default:
            // Confirm the forms row actually belongs to this pid/encounter before
            // touching it — prevents a crafted form_id from editing another
            // patient's or another encounter's record.
            $row = sqlQuery(
                "SELECT form_id, formdir, date FROM forms WHERE form_id=? AND pid=? AND encounter=? AND deleted=0 LIMIT 1",
                [$formId, $pid, $encounter]
            );
            if (empty($row)) {
                mdi_json_fail('Form not found for this encounter', 404);
            }

            // Preserve the existing time-of-day where possible so we're only
            // changing the calendar date, not the time the form was recorded.
            $timePart = (!empty($row['date']) && strtotime($row['date']) > 0)
                ? date('H:i:s', strtotime($row['date']))
                : date('H:i:s');
            $newDateTime = $newDate . ' ' . $timePart;

            sqlStatement(
                "UPDATE forms SET date=? WHERE form_id=? AND pid=? AND encounter=?",
                [$newDateTime, $formId, $pid, $encounter]
            );

            // Best-effort: most clinical forms (form_vitals, form_ros, form_soap,
            // etc.) keep their own `date` column in sync with the forms table.
            // Update it too if the sub-table/column exists; harmless no-op if not.
            $formdir = $row['formdir'] ?? '';
            if ($formdir !== '' && preg_match('/^[A-Za-z0-9_]+$/', $formdir)) {
                $subTable = 'form_' . $formdir;
                try {
                    sqlStatement("UPDATE `$subTable` SET date=? WHERE id=?", [$newDateTime, $formId]);
                } catch (\Throwable $e) {
                    // Sub-table may not exist, may not have a `date` column, or
                    // may key on something other than `id` — non-fatal either way.
                }
            }
            break;
    }

    echo json_encode(['ok' => true]);
} catch (\Throwable $e) {
    error_log('mdi_update_form_date error: ' . $e->getMessage());
    mdi_json_fail('Server error saving date', 500);
}
