<?php
/**
 * Graphic Pain Map — save.php
 * AJAX POST endpoint called by new.php's savePainMap() JS function.
 * Place at: /interface/forms/painmap/save.php
 *
 * POST params:
 *   formid        int     — existing form_painmap.id (0 = new record)
 *   dots          JSON    — array of {id, region, x, y, view}
 *   painScores    JSON    — object keyed by dot id
 *   notes         JSON    — object keyed by dot id  ← THIS is why notes weren't saving
 *   provider_notes string — free-text provider notes
 *   csrf_token_form / X-CSRF-Token header
 */

require_once(__DIR__ . '/../../globals.php');
require_once($srcdir . '/api.inc.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// Drain any buffered output before sending JSON
while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/json');
header('Cache-Control: no-store');

// ── Auth ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['authUser'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if (!AclMain::aclCheckCore('encounters', 'notes', '', 'write')) {
    echo json_encode(['success' => false, 'message' => 'Access not authorized']);
    exit;
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token_form'] ?? '';
if (!CsrfUtils::verifyCsrfToken($token)) {
    echo json_encode(['success' => false, 'message' => 'CSRF token invalid']);
    exit;
}

// ── Input ────────────────────────────────────────────────────────────────────
$pid      = (int)($_SESSION['pid']       ?? 0);
$encounter= (int)($_SESSION['encounter'] ?? 0);
$formId   = (int)($_POST['formid']       ?? 0);

$providerNotes = substr($_POST['provider_notes'] ?? '', 0, 5000);
$dots          = json_decode($_POST['dots']       ?? '[]', true) ?: [];
$painScores    = json_decode($_POST['painScores'] ?? '[]', true) ?: [];
$markerNotes   = json_decode($_POST['notes']      ?? '{}', true) ?: [];
// markerNotes is an object keyed by dot id: {"1": "note text", "3": "other"}

if (!$pid || !$encounter) {
    echo json_encode(['success' => false, 'message' => 'Missing pid or encounter in session']);
    exit;
}

// ── Derived summary values ────────────────────────────────────────────────────
$markerCount = count($dots);
$scoreValues = array_values((array)$painScores);
$maxPain     = count($scoreValues) ? (int)max($scoreValues) : 0;
$regions     = implode(', ', array_unique(array_filter(array_column($dots, 'region'))));

// ── Encode — store as plain JSON (no base64) ─────────────────────────────────
// notes object keyed by dot id so report.php/mdi_print_report.php can look up
// "what note belongs to this dot" by dot.id, not by array index.
$markersJson = json_encode([
    'dots'       => $dots,
    'painScores' => $painScores,
    'notes'      => $markerNotes,
], JSON_UNESCAPED_UNICODE);

// ── Save ──────────────────────────────────────────────────────────────────────
try {
    if ($formId > 0) {
        // UPDATE existing record
        $existing = sqlQuery(
            "SELECT id FROM form_painmap WHERE id=? AND pid=? AND activity=1 LIMIT 1",
            [$formId, $pid]
        );
        if (!$existing) {
            echo json_encode(['success' => false, 'message' => 'Record not found']);
            exit;
        }
        sqlStatement(
            "UPDATE form_painmap
                SET markers_json   = ?,
                    marker_count   = ?,
                    max_pain       = ?,
                    regions_text   = ?,
                    provider_notes = ?,
                    date           = NOW()
              WHERE id = ? AND pid = ?",
            [$markersJson, $markerCount, $maxPain ?: null, $regions, $providerNotes, $formId, $pid]
        );
        echo json_encode(['success' => true, 'formid' => $formId, 'message' => 'Pain map updated.']);
    } else {
        // INSERT new record
        sqlStatement(
            "INSERT INTO form_painmap
                (pid, encounter, user, groupname, authorized, activity, date,
                 markers_json, marker_count, max_pain, regions_text, provider_notes)
             VALUES (?, ?, ?, ?, 1, 1, NOW(), ?, ?, ?, ?, ?)",
            [
                $pid, $encounter,
                $_SESSION['authUser'] ?? '',
                $_SESSION['authProvider'] ?? '',
                $markersJson, $markerCount, $maxPain ?: null, $regions, $providerNotes,
            ]
        );
        $newId = (int)sqlInsertId();

        // Register in forms table so it shows in encounter
        addForm($encounter, 'Graphic Pain Map', $newId, 'painmap', $pid, 1);

        echo json_encode(['success' => true, 'formid' => $newId, 'message' => 'Pain map saved.']);
    }
} catch (\Throwable $e) {
    error_log('painmap/save.php error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
