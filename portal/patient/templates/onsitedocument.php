<?php

/**
 * onsitedocument.php
 *
 * Serves an onsite portal document from the onsite_documents table.
 * Loaded inside the dashboard document viewer modal iframe.
 *
 * Query params:
 *   pid   - patient id
 *   docid - onsite_documents.id
 *
 * Place this file at:
 *   openemr/portal/patient/onsiteactivity/onsitedocument.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// Bootstrap OpenEMR — adjust path depth as needed
$ignoreAuth = false;
require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../../../../library/sql.inc.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// ── Auth ──────────────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('patientportal', 'portal')) {
    http_response_code(403);
    echo '<p style="color:red;font-family:sans-serif;">'.xlt('Unauthorized').'</p>';
    exit;
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$pid   = (int)($_GET['pid']   ?? 0);
$docid = (int)($_GET['docid'] ?? 0);

if ($pid <= 0 || $docid <= 0) {
    http_response_code(400);
    echo '<p style="color:red;font-family:sans-serif;">'.xlt('Missing required parameters.').'</p>';
    exit;
}

// ── Fetch document row ────────────────────────────────────────────────────────
$row = sqlQuery(
    "SELECT id, pid, doc_type, file_name, file_path, full_document,
            patient_signed_status, authorize_signed_time, patient_signed_time,
            create_date, authorizing_signator
     FROM onsite_documents
     WHERE id = ? AND pid = ?",
    [$docid, $pid]
);

if (empty($row)) {
    http_response_code(404);
    echo '<p style="color:red;font-family:sans-serif;">'.xlt('Document not found.').'</p>';
    exit;
}

// ── Decide how to serve ───────────────────────────────────────────────────────
// Priority: full_document blob > file_path on disk > error
$hasBlob     = !empty($row['full_document']);
$hasFilePath = !empty($row['file_path']) && file_exists($row['file_path']);

// Determine MIME from file_name extension
$ext  = strtolower(pathinfo($row['file_name'] ?? 'doc.html', PATHINFO_EXTENSION));
$mime = match($ext) {
    'pdf'        => 'application/pdf',
    'html','htm' => 'text/html; charset=utf-8',
    'txt'        => 'text/plain; charset=utf-8',
    default      => 'text/html; charset=utf-8',
};

if ($hasBlob) {
    // Serve the stored blob inline so the iframe renders it
    $content = $row['full_document'];

    // If content looks like an HTML document, wrap it with doc-viewer chrome
    $isHtml = ($mime === 'text/html; charset=utf-8') ||
              (stripos($content, '<!DOCTYPE') !== false) ||
              (stripos($content, '<html') !== false);

    if ($isHtml) {
        // Inject a thin status bar at the top so the toolbar actions can find
        // the standard OpenEMR template buttons (saveTemplate, submitTemplate…)
        $signedBadge = '';
        if (!empty($row['patient_signed_time'])) {
            $signedBadge = '<span style="background:#d1fae5;color:#065f46;border-radius:5px;padding:2px 9px;font-size:.68rem;font-weight:700;margin-left:8px;">'.
                           xlt('Patient Signed').' '.text($row['patient_signed_time']).'</span>';
        }
        if (!empty($row['authorize_signed_time'])) {
            $signedBadge .= '<span style="background:#dbeafe;color:#1e40af;border-radius:5px;padding:2px 9px;font-size:.68rem;font-weight:700;margin-left:6px;">'.
                            xlt('Authorized').' '.text($row['authorize_signed_time']).'</span>';
        }
        $statusBar = '<div style="font-family:Montserrat,sans-serif;background:#1a1540;color:rgba(255,255,255,.7);'.
                     'padding:6px 14px;font-size:.68rem;display:flex;align-items:center;gap:8px;flex-wrap:wrap;border-bottom:1px solid rgba(139,92,246,.3);">'.
                     '<strong style="color:#fff;">'.text($row['doc_type'] ?: $row['file_name']).'</strong>'.
                     '<span style="opacity:.4;">|</span>'.
                     xlt('Patient').' #'.text($pid).
                     '<span style="opacity:.4;">|</span>'.
                     xlt('Created').': '.text(date('M j, Y', strtotime($row['create_date']))).
                     $signedBadge.
                     '</div>';

        // Inject the status bar right after <body> tag, or prepend if none found
        if (preg_match('/<body[^>]*>/i', $content)) {
            $content = preg_replace('/(<body[^>]*>)/i', '$1'.$statusBar, $content, 1);
        } else {
            $content = $statusBar . $content;
        }

        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        echo $content;
        exit;
    }

    // Non-HTML blob — serve with correct MIME (PDF, etc.)
    header('Content-Type: ' . $mime);
    header('Content-Disposition: inline; filename="' . addslashes($row['file_name']) . '"');
    header('Content-Length: ' . strlen($content));
    header('X-Frame-Options: SAMEORIGIN');
    echo $content;
    exit;
}

if ($hasFilePath) {
    // Stream file from disk
    $ext2 = strtolower(pathinfo($row['file_path'], PATHINFO_EXTENSION));
    $mime2 = match($ext2) {
        'pdf'        => 'application/pdf',
        'html','htm' => 'text/html; charset=utf-8',
        default      => 'application/octet-stream',
    };
    header('Content-Type: ' . $mime2);
    header('Content-Disposition: inline; filename="' . addslashes(basename($row['file_path'])) . '"');
    header('X-Frame-Options: SAMEORIGIN');
    readfile($row['file_path']);
    exit;
}

// ── Nothing to serve ─────────────────────────────────────────────────────────
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head><style>
    body { font-family: Montserrat, sans-serif; background: #f5f3ff; display: flex; align-items: center;
           justify-content: center; min-height: 100vh; margin: 0; }
    .box { background: #fff; border-radius: 12px; padding: 2rem 2.5rem; text-align: center;
           box-shadow: 0 4px 24px rgba(0,0,0,.08); max-width: 380px; }
    .ico { font-size: 2.4rem; margin-bottom: .8rem; }
    h2   { font-size: .95rem; color: #1a1540; margin-bottom: .4rem; }
    p    { font-size: .74rem; color: #6b7280; }
</style></head>
<body>
<div class="box">
    <div class="ico">📄</div>
    <h2><?php echo xlt('Document Unavailable'); ?></h2>
    <p><?php echo xlt('No document content is stored for this record. It may have been removed or not yet generated.'); ?></p>
</div>
</body>
</html>
