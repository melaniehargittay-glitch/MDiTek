<?php
/**
 * MDItek Delete Encounter
 *
 * Deletes a Quick Charge encounter after verifying it has no active billing.
 * Legacy A/R Migration entries (synthetic placeholder-balance encounters
 * created by the receivables import tool) are exempt from the admin/super
 * requirement and the is_billed lock — anyone can remove one, and the
 * self-generated PATBAL row shouldn't block it the way real billing would.
 * That exemption is determined here from the encounter's own `reason`
 * column, never from anything the client sends, so it can't be spoofed
 * to bypass protection on a real billed encounter.
 *
 * Returns JSON: { success: true } or { error: "message" }
 *
 * Place this file at:
 *   /interface/patient_file/summary/mdi_delete_encounter.php
 */

require_once("../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionUtil;

header('Content-Type: application/json');

// Validate CSRF
$token = $_POST['csrf_token_form'] ?? '';
if (empty($token) || !CsrfUtils::verifyCsrfToken($token)) {
    echo json_encode(['error' => xl('Invalid CSRF token')]);
    exit;
}

$encounter = (int)($_POST['encounter'] ?? 0);
$pid       = (int)($_POST['pid'] ?? 0);

if (!$encounter || !$pid) {
    echo json_encode(['error' => xl('Invalid encounter or patient ID')]);
    exit;
}

// Check it belongs to this patient, and pull reason so we can determine
// server-side whether this is a Legacy A/R Migration entry.
$enc = sqlQuery(
    "SELECT encounter, reason FROM form_encounter WHERE encounter = ? AND pid = ?",
    [$encounter, $pid]
);
if (empty($enc)) {
    echo json_encode(['error' => xl('Encounter not found')]);
    exit;
}

$is_legacy_ar = (stripos((string)($enc['reason'] ?? ''), 'Legacy A/R Migration') !== false);

// Must be admin — except for Legacy A/R Migration entries, which anyone
// may remove.
if (!$is_legacy_ar && !AclMain::aclCheckCore('admin', 'super')) {
    echo json_encode(['error' => xl('Not authorized')]);
    exit;
}

// Check for submitted billing — only block if claim has actually been sent/billed
// activity=1 just means the line exists; billed=1 means it was submitted to insurance.
// Skipped for Legacy A/R Migration entries: their billed=1 row is just the
// synthetic PATBAL placeholder charge the migration itself created, not a
// real submitted claim, so it shouldn't lock deletion.
if (!$is_legacy_ar) {
    $billed = sqlQuery(
        "SELECT COUNT(*) AS cnt FROM billing
         WHERE encounter = ? AND pid = ? AND activity = 1 AND billed = 1",
        [$encounter, $pid]
    );
    if ($billed['cnt'] > 0) {
        echo json_encode(['error' => xl('Cannot delete — encounter has been billed')]);
        exit;
    }
}

// Legacy A/R Migration entries can carry an associated credit in
// ar_activity/ar_session (created by the receivables import tool when a
// patient had a credit balance). ar_session has no encounter column of
// its own — it's only reachable via ar_activity.session_id — so collect
// those session ids first, remove the ar_activity rows tied to this
// encounter, then remove any of those sessions that aren't referenced by
// some other ar_activity row.
$sessionIdsRes = sqlStatement(
    "SELECT DISTINCT session_id FROM ar_activity WHERE encounter = ? AND pid = ? AND session_id IS NOT NULL",
    [$encounter, $pid]
);
$sessionIds = [];
while ($row = sqlFetchArray($sessionIdsRes)) {
    if (!empty($row['session_id'])) {
        $sessionIds[] = (int)$row['session_id'];
    }
}

sqlStatement("DELETE FROM ar_activity WHERE encounter = ? AND pid = ?", [$encounter, $pid]);

foreach ($sessionIds as $sessionId) {
    $stillUsed = sqlQuery(
        "SELECT COUNT(*) AS cnt FROM ar_activity WHERE session_id = ?",
        [$sessionId]
    );
    if (empty($stillUsed['cnt'])) {
        sqlStatement("DELETE FROM ar_session WHERE session_id = ?", [$sessionId]);
    }
}

// Delete billing entries (inactive/zero), forms, and the encounter itself
sqlStatement("DELETE FROM billing WHERE encounter = ? AND pid = ?", [$encounter, $pid]);
sqlStatement("DELETE FROM forms WHERE encounter = ? AND pid = ?", [$encounter, $pid]);
sqlStatement("DELETE FROM form_encounter WHERE encounter = ? AND pid = ?", [$encounter, $pid]);

// Clear encounter from session directly so page reload doesn't error
if (isset($_SESSION['encounter']) && (int)$_SESSION['encounter'] === $encounter) {
    $_SESSION['encounter'] = '';
    unset($_SESSION['encounter']);
}

// Force session write before sending response
session_write_close();

echo json_encode(['success' => true]);
exit;
