<?php
/**
 * mdi_signature_api.php — persists a provider's electronic signature
 * (drawn, uploaded, or typed) used to sign encounter notes.
 *
 * Place at: interface/usergroup/mdi_signature_api.php
 * Called from main.php's My Signature modal via fetch(), credentials:
 * 'same-origin', matching the csrf_token_js + FormData convention already
 * used elsewhere in main.php (see the dated_reminders_counter.php calls).
 *
 * Endpoints:
 *   GET  ?action=status   -> { has_signature, sig_type, data_url, text, font, updated_at }
 *   POST action=save      -> { success, message }   (sig_type, data_url[, text, font])
 *   POST action=delete    -> { success, message }
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../globals.php';

use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function mdipr_json_out($arr)
{
    echo json_encode($arr);
    exit;
}

if (empty($_SESSION['authUser'])) {
    http_response_code(401);
    mdipr_json_out(['success' => false, 'message' => 'Not authenticated.']);
}

// Resolve current user id (mirrors the pattern used in main.php).
$authUserID = (int)($_SESSION['authUserID'] ?? 0);
if (!$authUserID) {
    $u = sqlQuery('SELECT id FROM users WHERE username = ? LIMIT 1', [$_SESSION['authUser']]);
    $authUserID = (int)($u['id'] ?? 0);
}
if (!$authUserID) {
    mdipr_json_out(['success' => false, 'message' => 'Unable to resolve current user.']);
}

// Ensure storage table exists — created lazily so this feature doesn't
// require a separate migration step to start working. One row per
// provider; a new save simply overwrites the previous signature.
sqlStatement(
    "CREATE TABLE IF NOT EXISTS mdi_provider_signatures (
        uid         INT NOT NULL PRIMARY KEY,
        sig_type    VARCHAR(20) NOT NULL,
        sig_data    LONGTEXT NOT NULL,
        sig_text    VARCHAR(190) DEFAULT NULL,
        sig_font    VARCHAR(80)  DEFAULT NULL,
        updated_at  DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
);

// Storage for the signing PIN (Electronic Signature) used by the
// PIN-based "Sign Encounter" flow — kept in its own table, separate from
// login credentials, so it can be extended later (rotation, lockout,
// last-verified tracking) without touching the users table.
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Read: signature status / data ───────────────────────────────────────
if ($action === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = sqlQuery('SELECT * FROM mdi_provider_signatures WHERE uid = ? LIMIT 1', [$authUserID]);
    if (empty($row)) {
        mdipr_json_out(['has_signature' => false]);
    }
    mdipr_json_out([
        'has_signature' => true,
        'sig_type'      => $row['sig_type'],
        'data_url'      => $row['sig_data'],
        'text'          => $row['sig_text'],
        'font'          => $row['sig_font'],
        'updated_at'    => $row['updated_at'],
    ]);
}

// ── Read: signing PIN status (never returns the PIN itself) ─────────────
if ($action === 'pin_status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = sqlQuery('SELECT require_pin_after_timeout FROM mdi_provider_security WHERE user_id = ? LIMIT 1', [$authUserID]);
    mdipr_json_out([
        'has_pin'                   => !empty($row),
        'require_pin_after_timeout' => $row ? (bool)$row['require_pin_after_timeout'] : true,
    ]);
}

// ── Everything below mutates data — require POST + CSRF ─────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    mdipr_json_out(['success' => false, 'message' => 'Invalid request method.']);
}
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    http_response_code(403);
    mdipr_json_out(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.']);
}

if ($action === 'save') {
    $sigType = $_POST['sig_type'] ?? '';
    $dataUrl = $_POST['data_url'] ?? '';
    $text    = $_POST['text'] ?? null;
    $font    = $_POST['font'] ?? null;

    if (!in_array($sigType, ['draw', 'upload', 'typed'], true)) {
        mdipr_json_out(['success' => false, 'message' => 'Unrecognized signature type.']);
    }
    if (strpos($dataUrl, 'data:image/png;base64,') !== 0) {
        mdipr_json_out(['success' => false, 'message' => 'Signature image was not in the expected format.']);
    }
    // Sanity cap — a signature PNG this large almost certainly means
    // something went wrong client-side rather than a legitimate signature.
    if (strlen($dataUrl) > 2000000) {
        mdipr_json_out(['success' => false, 'message' => 'Signature image is too large. Please simplify or re-crop it.']);
    }

    sqlStatement(
        'INSERT INTO mdi_provider_signatures (uid, sig_type, sig_data, sig_text, sig_font, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            sig_type = VALUES(sig_type),
            sig_data = VALUES(sig_data),
            sig_text = VALUES(sig_text),
            sig_font = VALUES(sig_font),
            updated_at = NOW()',
        [$authUserID, $sigType, $dataUrl, $text, $font]
    );

    mdipr_json_out(['success' => true]);
}

if ($action === 'save_pin') {
    $pin             = $_POST['pin'] ?? '';
    $requireTimeout  = !empty($_POST['require_pin_after_timeout']) ? 1 : 0;

    if (!preg_match('/^\d{4,8}$/', $pin)) {
        mdipr_json_out(['success' => false, 'message' => 'PIN must be 4–8 digits.']);
    }

    $hash = password_hash($pin, PASSWORD_DEFAULT);

    sqlStatement(
        'INSERT INTO mdi_provider_security (user_id, signature_pin_hash, pin_updated_at, require_pin_after_timeout, failed_attempts, last_verified)
         VALUES (?, ?, NOW(), ?, 0, NULL)
         ON DUPLICATE KEY UPDATE
            signature_pin_hash = VALUES(signature_pin_hash),
            pin_updated_at = NOW(),
            require_pin_after_timeout = VALUES(require_pin_after_timeout),
            failed_attempts = 0',
        [$authUserID, $hash, $requireTimeout]
    );

    // Changing the PIN invalidates any currently "remembered" session —
    // the provider must re-verify with the new PIN before signing again.
    unset($_SESSION['signature_verified']);

    mdipr_json_out(['success' => true]);
}

if ($action === 'delete') {
    sqlStatement('DELETE FROM mdi_provider_signatures WHERE uid = ?', [$authUserID]);
    mdipr_json_out(['success' => true]);
}

mdipr_json_out(['success' => false, 'message' => 'Unknown action.']);
