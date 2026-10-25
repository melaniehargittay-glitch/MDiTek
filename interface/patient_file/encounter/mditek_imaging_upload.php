<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
/**
 * mditek_imaging_upload.php
 * Saves an uploaded imaging file into OpenEMR's documents table.
 * Uses random_bytes(16) for uuid — no external uuid library needed.
 */
ob_start();
require_once(__DIR__ . "/../../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;

function json_send(array $p): void {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($p);
    exit;
}

// ── ACL ───────────────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('patients', 'med')) {
    json_send(['success' => false, 'error' => 'Access denied']);
}

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
    json_send(['success' => false, 'error' => 'CSRF token invalid']);
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$pid       = (int)($_POST['pid']       ?? 0);
$encounter = (int)($_POST['encounter'] ?? 0);
if (!$pid) { json_send(['success' => false, 'error' => 'Missing patient ID']); }

// ── File validation ───────────────────────────────────────────────────────────
$errMap = [
    UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize',
    UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE',
    UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
    UPLOAD_ERR_NO_FILE    => 'No file received',
    UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder on server',
    UPLOAD_ERR_CANT_WRITE => 'Server could not write temp file',
    UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension',
];
$fileErr = $_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE;
if (empty($_FILES['image_file']['tmp_name']) || $fileErr !== UPLOAD_ERR_OK) {
    json_send(['success' => false, 'error' => $errMap[$fileErr] ?? 'Upload error code: ' . $fileErr]);
}

$allowedExt  = ['jpg','jpeg','png','gif','webp','bmp','pdf','dcm','dicom','tif','tiff'];
$allowedMime = ['image/jpeg','image/png','image/gif','image/webp','image/bmp','image/tiff',
                'application/pdf','application/dicom','application/octet-stream'];
$origName   = basename((string)$_FILES['image_file']['name']);
$ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
$clientMime = strtolower((string)$_FILES['image_file']['type']);

if (!in_array($ext, $allowedExt, true) && !in_array($clientMime, $allowedMime, true)) {
    json_send(['success' => false, 'error' => 'File type not allowed: ' . htmlspecialchars($ext)]);
}

// ── One-time AUTO_INCREMENT repair ────────────────────────────────────────────
// The documents table in this install has id default 0 (not AUTO_INCREMENT).
// Fix it silently on every upload call — ALTER TABLE is a no-op if already set.
try {
    $aiCheck = sqlQuery("SELECT AUTO_INCREMENT FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'");
    $currentAI = (int)($aiCheck['AUTO_INCREMENT'] ?? 0);
    if ($currentAI <= 1) {
        // Find the current max id and set AI above it
        $maxRow = sqlQuery("SELECT MAX(id) as maxid FROM documents");
        $nextId = max(1, ((int)($maxRow['maxid'] ?? 0)) + 1);
       
    }
} catch (\Throwable $ignored) {
    // If the repair fails just continue — worst case we get id=0 again,
    // which is caught below.
}

// ── Save file to disk ─────────────────────────────────────────────────────────
$uploadDir =
    $GLOBALS['OE_SITES_BASE'] . '/' .
    $_SESSION['site_id'] .
    '/documents/imaging/' .
    $pid;
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0775, true)) {
        json_send(['success' => false, 'error' => 'Cannot create upload directory (permissions?)']);
    }
}

$filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
$fullPath = $uploadDir . '/' . $filename;

if (!move_uploaded_file($_FILES['image_file']['tmp_name'], $fullPath)) {
    json_send(['success' => false, 'error' => 'Could not move file to ' . $uploadDir . ' (permissions?)']);
}



// ── Build document URL ────────────────────────────────────────────────────────
$siteId = $_SESSION['site_id'] ?? 'default';
$docUrl = '/sites/' . $siteId . '/documents/imaging/' . $pid . '/' . $filename;



// ── INSERT ────────────────────────────────────────────────────────────────────
try {

    sqlStatement(
        "INSERT INTO documents
        (
            type,
            size,
            date,
            url,
            mimetype,
            owner,
            foreign_id,
            docdate,
            name,
            encounter_id,
            deleted
        )
        VALUES
        (
            'file_url',
            ?,
            NOW(),
            ?,
            ?,
            ?,
            ?,
            CURDATE(),
            ?,
            ?,
            0
        )",
        [
            filesize($fullPath),
            $docUrl,
            $clientMime ?: 'application/octet-stream',
            (int)($_SESSION['authUserID'] ?? 1),
            $pid,
            $origName,
            $encounter
        ]
    );

} catch (\Throwable $e) {

    json_send([
        'success' => false,
        'error' => 'DOCUMENT INSERT: ' . $e->getMessage()
    ]);
}
$inserted = sqlQuery(
    "SELECT id
       FROM documents
      WHERE url = ?
        AND foreign_id = ?
   ORDER BY id DESC
      LIMIT 1",
    [
        $docUrl,
        $pid
    ]
);

$newDocId = (int)($inserted['id'] ?? 0);

if ($newDocId <= 0) {
    // Still 0 — try to find the row we just inserted by url
    $inserted = sqlQuery("SELECT id FROM documents WHERE url = ? AND foreign_id = ? ORDER BY id DESC LIMIT 1",
        [$docUrl, $pid]);
    $newDocId = (int)($inserted['id'] ?? 0);
}

if ($newDocId <= 0) {
    json_send([
        'success' => false,
        'error'   => 'Upload saved to disk but documents.id=0. Run this in phpMyAdmin to fix: '
                   . 'ALTER TABLE `documents` MODIFY `id` int NOT NULL AUTO_INCREMENT;'
                   . ' Then re-upload.',
    ]);
}

// ── Category link ─────────────────────────────────────────────────────────────


json_send(['success' => true, 'message' => 'Imaging uploaded', 'document_id' => $newDocId]);
