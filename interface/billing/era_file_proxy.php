<?php
/**
 * Serves ERA HTML report files for the ERA viewer iframe.
 * Only allows files inside the site's eras/ documents directory.
 *
 * @package   OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('acct', 'bill', '', 'write') && !AclMain::aclCheckCore('acct', 'eob', '', 'write')) {
    http_response_code(403);
    exit('Unauthorized');
}

$requested = isset($_GET['path']) ? $_GET['path'] : '';

// Strictly enforce that the file must be inside the era documents directory
// Resolve ERA dir using same multi-fallback strategy
function resolve_era_dir_proxy() {
    $candidates = [];
    if (!empty($GLOBALS['OE_SITE_DIR'])) {
        $d = rtrim($GLOBALS['OE_SITE_DIR'], '/');
        $candidates[] = $d . '/documents/eras/';
        if ($d[0] !== '/') {
            $dr = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
            if ($dr) $candidates[] = $dr . '/' . ltrim($d, '/') . '/documents/eras/';
        }
    }
    $oe_root = dirname(dirname(__DIR__));
    $candidates[] = $oe_root . '/sites/default/documents/eras/';
    $docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docroot) {
        $candidates[] = $docroot . '/sites/default/documents/eras/';
        $candidates[] = $docroot . '/openemr/sites/default/documents/eras/';
    }
    foreach ($candidates as $c) {
        $real = realpath($c);
        if ($real && is_dir($real) && is_readable($real)) return rtrim($real, '/') . '/';
    }
    return rtrim($candidates[0] ?? '', '/') . '/';
}
$era_dir_resolved = resolve_era_dir_proxy();
$era_dir  = $era_dir_resolved ? rtrim(realpath($era_dir_resolved) ?: $era_dir_resolved, '/') : '';
$resolved = realpath($requested);

if (!$era_dir || !$resolved) {
    http_response_code(404);
    exit('File not found');
}

// Path traversal check: resolved path must start with era_dir
if (!$era_dir || strpos($resolved, rtrim($era_dir,'/\\') . DIRECTORY_SEPARATOR) !== 0) {
    http_response_code(403);
    exit('Access denied');
}

$ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
if (!in_array($ext, ['html', 'htm'])) {
    http_response_code(403);
    exit('Only HTML files are served here');
}

if (!is_readable($resolved)) {
    http_response_code(404);
    exit('File not found');
}

header('Content-Type: text/html; charset=UTF-8');
readfile($resolved);
