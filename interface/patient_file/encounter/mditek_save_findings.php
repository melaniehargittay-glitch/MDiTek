<?php
/**
 * mditek_save_findings.php
 * Saves imaging findings text to the encounter.
 * Table: mdi_imaging_findings (id, pid, encounter, findings, created_by, created_at)
 */
ob_start();

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

function json_out(array $d): void {
    if (ob_get_length()) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d);
    exit;
}

if (!AclMain::aclCheckCore('patients', 'med', '', 'write')) {
    json_out(['success' => false, 'error' => 'Access denied']);
}

if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    json_out(['success' => false, 'error' => 'CSRF']);
}

$pid        = (int)($_POST['pid']       ?? 0);
$encounter  = (int)($_POST['encounter'] ?? 0);
$findings   = trim($_POST['findings']   ?? '');
$created_by = $_SESSION['authUser']     ?? '';

if (!$pid || !$encounter) {
    json_out(['success' => false, 'error' => 'Missing pid or encounter']);
}
if ($findings === '') {
    json_out(['success' => false, 'error' => 'Findings are empty']);
}

// ── Ensure the table exists with the correct schema ───────────────────────────
sqlStatement("CREATE TABLE IF NOT EXISTS `mdi_imaging_findings` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pid`        INT UNSIGNED NOT NULL,
    `encounter`  INT UNSIGNED NOT NULL,
    `findings`   MEDIUMTEXT   NOT NULL,
    `created_by` VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_pid_enc` (`pid`, `encounter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Upsert findings ───────────────────────────────────────────────────────────
$existing = sqlQuery(
    "SELECT id FROM mdi_imaging_findings WHERE pid=? AND encounter=? LIMIT 1",
    [$pid, $encounter]
);

if (!empty($existing['id'])) {
    $fid = (int)$existing['id'];
    sqlStatement(
        "UPDATE mdi_imaging_findings
         SET findings=?, created_by=?, created_at=NOW()
         WHERE id=?",
        [$findings, $created_by, $fid]
    );
} else {
    sqlStatement(
        "INSERT INTO mdi_imaging_findings (pid, encounter, findings, created_by, created_at)
         VALUES (?, ?, ?, ?, NOW())",
        [$pid, $encounter, $findings, $created_by]
    );
    $fid = (int)sqlLastInsertId();
}

// ── Ensure a forms table row exists so the encounter form list shows it ───────
$formExists = sqlQuery(
    "SELECT id FROM forms
     WHERE pid=? AND encounter=? AND formdir='mditek_imaging_findings' AND form_id=? AND deleted=0
     LIMIT 1",
    [$pid, $encounter, $fid]
);

if (empty($formExists['id'])) {
    sqlStatement(
        "INSERT INTO forms
            (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir)
         VALUES
            (NOW(), ?, 'Imaging Findings', ?, ?, ?, ?, 1, 0, 'mditek_imaging_findings')",
        [
            $encounter,
            $fid,
            $pid,
            $_SESSION['authUser']     ?? '',
            $_SESSION['authProvider'] ?? '',
        ]
    );
}

json_out(['success' => true, 'message' => 'Findings saved', 'form_row_id' => $fid]);
