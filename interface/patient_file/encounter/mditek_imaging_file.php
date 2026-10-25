<?php
/**
 * mditek_imaging_file.php
 * Serves an imaging document directly from disk.
 * URL: mditek_imaging_file.php?id=<document_id>
 *
 * The stored url format is: /sites/{site}/documents/imaging/{pid}/{filename}
 * We resolve that to a filesystem path via OE_SITES_BASE (not DOCUMENT_ROOT,
 * which is wrong on CloudClusters / non-standard installs).
 */

require_once(__DIR__ . "/../../globals.php");
use OpenEMR\Common\Acl\AclMain;

// ── Access control ────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('patients', 'med')) {
    http_response_code(403);
    exit('Access denied');
}

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    http_response_code(400);
    exit('Missing id');
}

// ── Fetch document row ────────────────────────────────────────────────────────
$row = sqlQuery(
    "SELECT id, url, mimetype, name, foreign_id, deleted FROM documents WHERE id = ? LIMIT 1",
    [$id]
);

if (empty($row) || (int)$row['deleted'] === 1) {
    http_response_code(404);
    exit('Document not found');
}

// ── Resolve filesystem path ───────────────────────────────────────────────────
// Stored URL: /sites/default/documents/imaging/13/1781289541_Xray.jpg
// OE_SITES_BASE: /cloudclusters/openemr/sites  (or wherever)
// Strip the leading "/sites/" prefix and join with OE_SITES_BASE
$storedUrl = (string)$row['url'];

// Support two storage patterns:
//   /sites/{site}/documents/...   → OE_SITES_BASE/{site}/documents/...
//   file:///absolute/path/...     → absolute path directly
if (strpos($storedUrl, 'file://') === 0) {
    $filePath = str_replace('file://', '', $storedUrl);
} elseif (strpos($storedUrl, '/sites/') === 0) {
    // Remove the "/sites/" prefix; OE_SITES_BASE already ends at the sites directory
    $relative = preg_replace('#^/sites/#', '', $storedUrl);
    $filePath = rtrim($GLOBALS['OE_SITES_BASE'], '/') . '/' . $relative;
} else {
    // Fallback: try DOCUMENT_ROOT
    $filePath = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim($storedUrl, '/');
}

// ── Serve the file ────────────────────────────────────────────────────────────
if (!is_readable($filePath)) {
    http_response_code(404);
    // Surface the attempted path to aid debugging (only visible as HTTP body,
    // not shown to users in the viewer — img.onerror catches it silently)
    exit('File not found on disk. Tried: ' . htmlspecialchars($filePath));
}

$mime = $row['mimetype'] ?: mime_content_type($filePath) ?: 'application/octet-stream';

header('Content-Type: '   . $mime);
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

// Suggest filename for downloads
$name = basename((string)($row['name'] ?: $storedUrl));
header('Content-Disposition: inline; filename="' . addslashes($name) . '"');

readfile($filePath);
exit;
