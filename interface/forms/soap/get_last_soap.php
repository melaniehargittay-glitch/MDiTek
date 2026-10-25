<?php
/**
 * get_last_soap.php
 * Place this file at: interface/forms/soap/get_last_soap.php
 *
 * Returns the most recent SOAP note for a given pid as JSON.
 * Called via fetch() from general_new.html (the SOAP form).
 */

// OpenEMR bootstrap — same pattern used by save.php in this directory
require_once(__DIR__ . "/../../globals.php");
require_once($GLOBALS['srcdir'] . "/api.inc");

use OpenEMR\Common\Csrf\CsrfUtils;

// ── Security ────────────────────────────────────────────────────────────────
// Only allow POST requests with a valid CSRF token
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    exit(json_encode(['error' => 'Invalid CSRF token']));
}

// Must be logged in
if (!isset($_SESSION['authUser'])) {
    http_response_code(401);
    exit(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');

// ── Input ────────────────────────────────────────────────────────────────────
$pid        = intval($_POST['pid'] ?? 0);
$current_id = intval($_POST['current_id'] ?? 0); // exclude the note currently being edited

if ($pid <= 0) {
    exit(json_encode(['error' => 'Invalid pid']));
}

// ── Query ────────────────────────────────────────────────────────────────────
// Fetch the most recent completed note for this patient,
// skipping the current encounter's note (current_id) if provided.
$sql = "SELECT id, date, subjective, objective, assessment, plan
        FROM form_soap
        WHERE pid = ?
          AND activity = 1
          " . ($current_id > 0 ? "AND id != ?" : "") . "
        ORDER BY date DESC
        LIMIT 1";

$params = $current_id > 0 ? [$pid, $current_id] : [$pid];
$row    = sqlQuery($sql, $params);

if (!$row) {
    exit(json_encode(['found' => false, 'message' => 'No previous notes found for this patient.']));
}

exit(json_encode([
    'found'      => true,
    'date'       => $row['date'],
    'subjective' => $row['subjective'] ?? '',
    'objective'  => $row['objective']  ?? '',
    'assessment' => $row['assessment'] ?? '',
    'plan'       => $row['plan']       ?? '',
]));
