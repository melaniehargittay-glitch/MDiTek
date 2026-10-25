<?php
/**
 * ERA Debug - paste this in /interface/billing/ temporarily to diagnose matching
 * Access: /interface/billing/era_debug.php?ref=ePay+-+790427237&session_id=328
 */
require_once("../globals.php");
use OpenEMR\Common\Acl\AclMain;
if (!AclMain::aclCheckCore('acct', 'bill', '', 'write') && !AclMain::aclCheckCore('acct', 'eob', '', 'write')) { exit('Unauthorized'); }

$ref        = isset($_GET['ref'])        ? trim($_GET['ref'])        : '';
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

$era_dir  = $GLOBALS['OE_SITE_DIR'] . '/documents/eras/';
$idx_path = $era_dir . '.era_index.json';

$check_num = '';
if (preg_match('/(\d+)\s*$/', $ref, $m)) { $check_num = $m[1]; }

header('Content-Type: text/plain');
echo "=== ERA DEBUG ===\n";
echo "ref        = " . $ref . "\n";
echo "check_num  = " . $check_num . "\n";
echo "session_id = " . $session_id . "\n";
echo "era_dir    = " . $era_dir . "\n";
echo "dir exists = " . (is_dir($era_dir) ? 'YES' : 'NO') . "\n";
echo "dir readable = " . (is_readable($era_dir) ? 'YES' : 'NO') . "\n";
echo "index file = " . $idx_path . "\n";
echo "index exists = " . (file_exists($idx_path) ? 'YES' : 'NO') . "\n";
echo "index readable = " . (is_readable($idx_path) ? 'YES' : 'NO') . "\n";
echo "index writable dir = " . (is_writable($era_dir) ? 'YES' : 'NO') . "\n\n";

if (file_exists($idx_path)) {
    echo "=== EXISTING INDEX ===\n";
    echo file_get_contents($idx_path) . "\n\n";
}

echo "=== FILES IN ERA DIR ===\n";
if (is_dir($era_dir)) {
    $files = scandir($era_dir);
    $edi_exts = ['edi','835','x12'];
    foreach ($files as $fn) {
        if ($fn[0] === '.') { echo "  [hidden] $fn\n"; continue; }
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        $size = filesize($era_dir . $fn);
        echo "  $fn ($size bytes, ext=$ext)\n";
    }
} else {
    echo "  DIR NOT FOUND\n";
}

echo "\n=== TRN SCAN OF EDI FILES ===\n";
$edi_exts = ['edi','835','x12'];
if (is_dir($era_dir)) {
    foreach (scandir($era_dir) as $fn) {
        if ($fn[0] === '.') continue;
        $ext = strtolower(pathinfo($fn, PATHINFO_EXTENSION));
        if (!in_array($ext, $edi_exts)) continue;
        $path = $era_dir . $fn;
        $head = file_get_contents($path, false, null, 0, 4096);
        if ($head === false) { echo "  $fn: COULD NOT READ\n"; continue; }
        if (preg_match('/\bTRN\*1\*(\d+)\b/i', $head, $m)) {
            $trn = $m[1];
            $match = ($trn === $check_num) ? ' <== MATCH!' : '';
            echo "  $fn => TRN=$trn$match\n";
        } else {
            // Show first 200 chars to debug
            $preview = substr(preg_replace('/\s+/', ' ', $head), 0, 300);
            echo "  $fn => NO TRN FOUND. Preview: $preview\n";
        }
    }
}

echo "\n=== DONE ===\n";
