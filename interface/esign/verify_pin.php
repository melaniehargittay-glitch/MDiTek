<?php
/**
 * verify_pin.php — PIN-based electronic signature for encounters.
 *
 * Place at: interface/esign/verify_pin.php
 *
 * Replaces the native OpenEMR "eSign Encounter" password prompt with a
 * short signing PIN (configured under My Signature in the top nav — see
 * mdi_signature_api.php's save_pin / pin_status actions and the
 * mdi_provider_security table).
 *
 * POST params: encounter, pid, pin (optional), csrf_token_form
 *
 * Flow:
 *   1. If $_SESSION['signature_verified'] is still "fresh" for this
 *      provider (see mdipin_session_fresh()), the encounter is signed
 *      immediately with no PIN required at all — this is what gives the
 *      "next 60 encounters: one-click sign" behavior within a session.
 *   2. Otherwise the caller must resubmit with `pin`. It's checked
 *      against mdi_provider_security.signature_pin_hash. On success the
 *      encounter is signed and $_SESSION['signature_verified'] is set,
 *      opening the one-click window described above.
 *
 * Response shapes:
 *   { success:true }                                    — signed
 *   { success:false, need_pin:true }                     — caller should prompt for a PIN and resubmit
 *   { success:false, message:'...', attempts_left:n }    — wrong PIN / other failure
 *
 * Signing itself is done by writing directly to esign_signatures using
 * the exact columns (tid, `table`, uid, datetime, is_lock) already relied
 * on elsewhere in this app (the dashboard "unsigned encounters" count and
 * the encounter print report both read this table the same way), rather
 * than reaching into the vendor ESign\Api internals. If your
 * esign_signatures table has additional NOT NULL columns beyond those
 * five, add them to the INSERTs in mdipin_do_sign() below.
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../globals.php';

use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function mdipin_out($arr)
{
    echo json_encode($arr);
    exit;
}

if (empty($_SESSION['authUser'])) {
    http_response_code(401);
    mdipin_out(['success' => false, 'message' => 'Not authenticated.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    mdipin_out(['success' => false, 'message' => 'Invalid request method.']);
}
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    mdipin_out(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.']);
}

$authUserID = (int)($_SESSION['authUserID'] ?? 0);
if (!$authUserID) {
    $u = sqlQuery('SELECT id FROM users WHERE username = ? LIMIT 1', [$_SESSION['authUser']]);
    $authUserID = (int)($u['id'] ?? 0);
}
if (!$authUserID) {
    mdipin_out(['success' => false, 'message' => 'Unable to resolve current user.']);
}

$encounter = (int)($_POST['encounter'] ?? 0);
$pid       = (int)($_POST['pid'] ?? 0);
if (!$encounter || !$pid) {
    mdipin_out(['success' => false, 'message' => 'Missing encounter or patient.']);
}

// Confirm the encounter actually belongs to this patient before touching
// anything, so a forged encounter/pid pair can't sign the wrong record.
$encRow = sqlQuery('SELECT encounter FROM form_encounter WHERE encounter = ? AND pid = ? LIMIT 1', [$encounter, $pid]);
if (empty($encRow)) {
    mdipin_out(['success' => false, 'message' => 'Encounter not found.']);
}

sqlStatement(
    "CREATE TABLE IF NOT EXISTS mdi_provider_security (
        user_id                    BIGINT NOT NULL PRIMARY KEY,
        signature_pin_hash         VARCHAR(255) NOT NULL,
        pin_updated_at             DATETIME NULL,
        require_pin_after_timeout  TINYINT(1) NOT NULL DEFAULT 1,
        failed_attempts            INT NOT NULL DEFAULT 0,
        last_verified              DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
);

// "MDI session-sign" table — read by mdi_visits.php (Encounters History)
// alongside esign_signatures to show who PIN-signed a note and when.
sqlStatement(
    "CREATE TABLE IF NOT EXISTS mdi_signatures (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        pid         INT NOT NULL,
        encounter   INT NOT NULL,
        uid         INT NOT NULL,
        signer_name VARCHAR(191) NOT NULL,
        signed_at   DATETIME NOT NULL,
        UNIQUE KEY uniq_pid_encounter (pid, encounter)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
);

const MDIPIN_SESSION_TTL = 1800; // 30 minutes

/**
 * Is this provider's PIN verification still valid for a one-click sign?
 * If they've unchecked "require PIN after session timeout", the
 * verification stays valid for the rest of the login session (cleared
 * only at logout / idle timeout) rather than expiring after 30 minutes.
 */
function mdipin_session_fresh($authUserID)
{
    if (empty($_SESSION['signature_verified'])) {
        return false;
    }
    $sec = sqlQuery('SELECT require_pin_after_timeout FROM mdi_provider_security WHERE user_id = ? LIMIT 1', [$authUserID]);
    $requireTimeout = $sec ? (int)$sec['require_pin_after_timeout'] : 1;
    if (!$requireTimeout) {
        return true;
    }
    return $_SESSION['signature_verified'] > (time() - MDIPIN_SESSION_TTL);
}

/**
 * Marks the encounter — and every SOAP form on it — as signed, using the
 * same esign_signatures columns already read elsewhere in this app.
 * Also records the sign in mdi_signatures — the "MDI session-sign" table
 * that mdi_visits.php reads alongside esign_signatures to show who
 * PIN-signed a note and when.
 */
function mdipin_do_sign($authUserID, $encounter, $pid)
{
    $existing = sqlQuery(
        "SELECT id FROM esign_signatures WHERE tid = ? AND `table` = 'form_encounter' LIMIT 1",
        [$encounter]
    );
    if ($existing) {
        sqlStatement(
            'UPDATE esign_signatures SET uid = ?, datetime = NOW(), is_lock = 1 WHERE id = ?',
            [$authUserID, $existing['id']]
        );
    } else {
        sqlStatement(
            "INSERT INTO esign_signatures (uid, `table`, tid, datetime, is_lock) VALUES (?, 'form_encounter', ?, NOW(), 1)",
            [$authUserID, $encounter]
        );
    }

    $forms = sqlStatement(
        "SELECT id FROM forms WHERE encounter = ? AND pid = ? AND form_name LIKE 'SOAP%' AND deleted = 0",
        [$encounter, $pid]
    );
    while ($f = sqlFetchArray($forms)) {
        $fExisting = sqlQuery(
            "SELECT id FROM esign_signatures WHERE tid = ? AND `table` = 'forms' LIMIT 1",
            [$f['id']]
        );
        if ($fExisting) {
            sqlStatement(
                'UPDATE esign_signatures SET uid = ?, datetime = NOW(), is_lock = 1 WHERE id = ?',
                [$authUserID, $fExisting['id']]
            );
        } else {
            sqlStatement(
                "INSERT INTO esign_signatures (uid, `table`, tid, datetime, is_lock) VALUES (?, 'forms', ?, NOW(), 1)",
                [$authUserID, $f['id']]
            );
        }
    }

    // Record it in mdi_signatures too — this is the "MDI session-sign"
    // table mdi_visits.php reads to show who PIN-signed a note and when.
    $signer = sqlQuery('SELECT fname, lname FROM users WHERE id = ? LIMIT 1', [$authUserID]);
    $signerName = trim(($signer['fname'] ?? '') . ' ' . ($signer['lname'] ?? ''));
    sqlStatement(
        'INSERT INTO mdi_signatures (pid, encounter, uid, signer_name, signed_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            uid = VALUES(uid),
            signer_name = VALUES(signer_name),
            signed_at = NOW()',
        [$pid, $encounter, $authUserID, $signerName]
    );
}

// ── Path 1: already verified recently — one-click sign, no PIN needed ───
if (mdipin_session_fresh($authUserID)) {
    mdipin_do_sign($authUserID, $encounter, $pid);
    $_SESSION['signature_verified'] = time(); // refresh the window
    mdipin_out(['success' => true]);
}

// ── Path 2: PIN required ─────────────────────────────────────────────────
$pin = $_POST['pin'] ?? '';
if ($pin === '') {
    // Caller didn't send a PIN yet — tell it to prompt for one rather
    // than treating this as a failure.
    mdipin_out(['success' => false, 'need_pin' => true]);
}

$sec = sqlQuery('SELECT * FROM mdi_provider_security WHERE user_id = ? LIMIT 1', [$authUserID]);
if (empty($sec) || empty($sec['signature_pin_hash'])) {
    mdipin_out(['success' => false, 'message' => 'No signing PIN configured yet. Set one under My Signature first.']);
}

if ((int)$sec['failed_attempts'] >= 5) {
    mdipin_out(['success' => false, 'message' => 'Too many incorrect attempts. Please contact an administrator to reset your PIN.']);
}

if (!password_verify($pin, $sec['signature_pin_hash'])) {
    sqlStatement('UPDATE mdi_provider_security SET failed_attempts = failed_attempts + 1 WHERE user_id = ?', [$authUserID]);
    $attemptsLeft = max(0, 5 - ((int)$sec['failed_attempts'] + 1));
    mdipin_out(['success' => false, 'message' => 'Incorrect PIN.', 'attempts_left' => $attemptsLeft]);
}

// Correct PIN — reset the failure counter, remember verification, sign.
sqlStatement(
    'UPDATE mdi_provider_security SET failed_attempts = 0, last_verified = NOW() WHERE user_id = ?',
    [$authUserID]
);
$_SESSION['signature_verified'] = time();

mdipin_do_sign($authUserID, $encounter, $pid);

mdipin_out(['success' => true]);
