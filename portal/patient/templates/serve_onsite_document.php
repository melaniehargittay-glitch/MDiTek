<?php
/**
 * serve_onsite_document.php
 *
 * Serves a portal onsite document from the onsite_documents table.
 * Place this file at:  openemr/portal/patient/serve_onsite_document.php
 *
 * URL params:
 *   pid   - patient id (required for ACL check)
 *   recid - onsite_documents.id (required)
 *   is_dashboard - set 1 when loading inside the activity dashboard iframe
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────
$ignoreAuth = false;
// Adjust the relative path depth to reach globals.php from portal/patient/
require_once __DIR__ . '/../../src/Common/Session/SessionUtil.php';
require_once __DIR__ . '/../../interface/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// ── Auth ───────────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('patientportal', 'portal')) {
    http_response_code(403);
    exit(xlt('Unauthorized'));
}

// ── Input validation ───────────────────────────────────────────────────────
$pid   = (int)($_GET['pid']   ?? 0);
$recid = (int)($_GET['recid'] ?? 0);
$isDashboard = !empty($_GET['is_dashboard']);

if ($pid <= 0 || $recid <= 0) {
    http_response_code(400);
    exit(xlt('Missing required parameters.'));
}

// ── Fetch document from onsite_documents ──────────────────────────────────
$row = sqlQuery(
    "SELECT id, pid, doc_type, file_name, file_path, full_document,
            patient_signed_status, accept_signed_status,
            patient_signature, authorized_signature,
            create_date, authorizing_signator
     FROM onsite_documents
     WHERE id = ? AND pid = ?",
    [$recid, $pid]
);

if (empty($row)) {
    http_response_code(404);
    exit(xlt('Document not found.'));
}

// ── Determine content to serve ────────────────────────────────────────────
$fileName  = $row['file_name'] ?? 'document';
$docType   = strtolower($row['doc_type'] ?? '');
$filePath  = $row['file_path'] ?? '';
$blobData  = $row['full_document'] ?? null;

// Priority: full_document blob > file_path on disk
$serveBlob = !empty($blobData);
$servePath = !$serveBlob && !empty($filePath) && file_exists($filePath);

if (!$serveBlob && !$servePath) {
    // Nothing to serve — render a simple status page inside the iframe
    $signed      = (int)$row['patient_signed_status'];
    $accepted    = (int)$row['accept_signed_status'];
    $createDate  = $row['create_date'] ?? '';
    $signator    = $row['authorizing_signator'] ?? '';
    ?>
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="utf-8">
    <title><?php echo xlt('Document'); ?></title>
    <style>
        body{font-family:system-ui,sans-serif;background:#f5f3ff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}
        .box{background:#fff;border-radius:12px;padding:2rem 2.5rem;box-shadow:0 4px 24px rgba(0,0,0,.09);max-width:420px;text-align:center;}
        h2{font-size:1.1rem;color:#1a1540;margin-bottom:.5rem;}
        p{font-size:.82rem;color:#6b7280;line-height:1.6;}
        .badge{display:inline-block;padding:3px 12px;border-radius:20px;font-size:.72rem;font-weight:700;margin-top:1rem;}
        .signed{background:#d1fae5;color:#065f46;}
        .pending{background:#fef3c7;color:#92400e;}
    </style>
    </head>
    <body>
    <div class="box">
        <h2><?php echo text($fileName); ?></h2>
        <p><?php echo xlt('Type'); ?>: <?php echo text(strtoupper($docType ?: 'N/A')); ?><br>
           <?php echo xlt('Created'); ?>: <?php echo text($createDate); ?><br>
           <?php if ($signator) echo xlt('Authorizer') . ': ' . text($signator) . '<br>'; ?>
        </p>
        <?php if ($signed): ?>
            <span class="badge signed"><?php echo xlt('Patient Signed'); ?></span>
        <?php else: ?>
            <span class="badge pending"><?php echo xlt('Awaiting Patient Signature'); ?></span>
        <?php endif; ?>
        <?php if ($accepted): ?>
            <span class="badge signed" style="margin-left:4px;"><?php echo xlt('Authorized'); ?></span>
        <?php else: ?>
            <span class="badge pending" style="background:#dbeafe;color:#1e40af;margin-left:4px;"><?php echo xlt('Pending Authorization'); ?></span>
        <?php endif; ?>
        <p style="margin-top:1.2rem;font-size:.72rem;color:#9ca3af;"><?php echo xlt('Document content not available for inline preview.'); ?></p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ── Determine MIME type ───────────────────────────────────────────────────
$mimeMap = [
    'pdf'  => 'application/pdf',
    'html' => 'text/html',
    'htm'  => 'text/html',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'txt'  => 'text/plain',
];
$ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mime = $mimeMap[$ext] ?? ($mimeMap[$docType] ?? 'application/octet-stream');

// ── Send headers ──────────────────────────────────────────────────────────
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . addslashes($fileName) . '"');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');

if ($serveBlob) {
    header('Content-Length: ' . strlen($blobData));
    echo $blobData;
} else {
    // Serve from file path
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
}
exit;
