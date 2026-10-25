<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
$sessionAllowWrite = true;
$ignoreAuth = 1;

foreach ([__DIR__.'/../globals.php', __DIR__.'/../../globals.php', __DIR__.'/../../../globals.php'] as $_p) {
    if (file_exists($_p)) { require_once($_p); break; }
}

if (!function_exists('sqlStatement')) {
    http_response_code(500);
    die("globals.php not loaded");
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) ob_end_flush();
ob_implicit_flush(true);
set_time_limit(0);
ignore_user_abort(false);

echo str_repeat(' ', 4096);
ob_flush();
flush();

send(1, "Initializing ICD-10 updater...");

function send(int $pct, string $msg): void {
    echo json_encode(['progress' => $pct, 'message' => $msg]) . "\n";
    ob_flush(); flush();
}

$destDir = __DIR__;

// Detect year dynamically from any icd10cm_codes_YYYY.txt present,
// falling back to the current calendar year.
$detected = glob($destDir . '/icd10cm_codes_*.txt');
$year = (int) date('Y');
if ($detected) {
    if (preg_match('/icd10cm_codes_(\d{4})\.txt$/', $detected[0], $m)) {
        $year = (int) $m[1];
    }
}

$txtFile = $destDir . '/icd10cm_codes_' . $year . '.txt';
$zipFile = $destDir . '/icd10cm_codes_' . $year . '.zip';

$mode = $_GET['mode'] ?? 'run';

// ── Delete uploaded TXT ─────────────────────────────────────
if ($mode === 'delete') {

    if (file_exists($txtFile)) {
        unlink($txtFile);
        send(100, "🗑️ ICD-10 TXT deleted.");
    } else {
        send(0, "❌ No TXT file found.");
    }

    exit;
}

// ── Mode: download from CDC then extract ─────────────────────────────────────
if ($mode === 'download') {
    // Official CDC/CMS flat-file ZIP for ICD-10-CM 2026
    $url = 'https://ftp.cdc.gov/pub/Health_Statistics/NCHS/Publications/ICD10CM/2026/icd10cm-Code%20Descriptions-2026.zip';

    send(5, "Downloading ICD-10-CM 2026 from CDC...");
    send(5, "URL: $url");

    // Download with stream so we can show progress
    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 120,
            'follow_location' => 1,
            'user_agent'      => 'OpenEMR ICD-10 Updater',
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);

    $remote = @fopen($url, 'rb', false, $ctx);
    if (!$remote) {
        // Try alternate CMS URL
        $url2 = 'https://ftp.cdc.gov/pub/Health_Statistics/NCHS/Publications/ICD10CM/2026/icd10cm-Code%20Descriptions-2026.zip';
        send(10, "Primary URL failed, trying alternate...");
        $remote = @fopen($url2, 'rb', false, $ctx);
    }
    if (!$remote) {
        send(0, "❌ Could not download from CDC. Please upload the file manually.");
        exit;
    }

    $local = fopen($zipFile, 'wb');
    $bytes = 0;
    while (!feof($remote)) {
        $chunk = fread($remote, 65536);
        fwrite($local, $chunk);
        $bytes += strlen($chunk);
        $mb = round($bytes / 1048576, 1);
        if ($bytes % (512 * 1024) < 65536) { // ~every 512KB
            send(20, "Downloaded {$mb} MB...");
        }
    }
    fclose($remote);
    fclose($local);

    send(35, "Download complete (" . round(filesize($zipFile)/1048576,1) . " MB). Extracting...");

    // Extract
    $zip = new ZipArchive();
    $res = $zip->open($zipFile);
    if ($res !== true) {
        send(0, "❌ Failed to open ZIP (error $res). File may be corrupt or URL changed.");
        @unlink($zipFile);
        exit;
    }

    $extracted = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
       

        send(30, "ZIP ENTRY: $name");

    if (
        stripos($name, 'codes') !== false &&
        stripos($name, '.txt') !== false
    ) {


            // Read file directly from ZIP
            $contents = $zip->getFromIndex($i);
        
            if ($contents === false) {
                send(0, "❌ Failed reading TXT from ZIP.");
                exit;
            }
        
            // Save directly to target file
            file_put_contents($txtFile, $contents);
        
            $extracted = true;
        
            send(45, "Extracted: " . basename($name));
        
            break;
        }
    }
    $zip->close();
    @unlink($zipFile);

    if (!$extracted) {
        send(0, "❌ Could not find icd10cm_codes*.txt inside ZIP. Please upload manually.");
        exit;
    }
    send(2, "Connecting to CDC servers...");
    send(50, "File ready. Starting import...");
    // Fall through to import below
}

// ── Import the txt file ───────────────────────────────────────────────────────
if (!file_exists($txtFile)) {
    send(0, "❌ File not found: $txtFile");
    exit;
}

function fmtCode(string $c): string {
    $c = strtoupper(trim($c));
    return strlen($c) > 3 ? substr($c,0,3).'.'.substr($c,3) : $c;
}

$startPct = ($mode === 'download') ? 50 : 5;
send($startPct, "Reading " . basename($txtFile) . "...");

$lines = file($txtFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$total = count($lines);
if (!$total) { send(0, "❌ File is empty."); exit; }

send($startPct, "$total codes found. Importing...");

$ins = 0; $skip = 0; $errs = 0;
$range = 95 - $startPct;

$BATCH = 500;
$batch_vals   = [];   // placeholders  (?,?,?,?,?)
$batch_params = [];   // flat param list

function flushBatch(array &$vals, array &$params, int &$ins, int &$errs, int $year): void {
    if (!$vals) return;
    $sql = "INSERT INTO icd10_dx_order_code
                (dx_code, formatted_dx_code, short_desc, long_desc, valid_for_coding, active, revision)
            VALUES " . implode(',', $vals) . "
            ON DUPLICATE KEY UPDATE
                formatted_dx_code=VALUES(formatted_dx_code),
                short_desc=VALUES(short_desc),
                long_desc=VALUES(long_desc),
                valid_for_coding='Y', active=1, revision=VALUES(revision)";
    try {
        sqlStatement($sql, $params);
        $ins += count($vals);
    } catch (Throwable $e) {
        $errs++;
        send(0, "⚠️ Batch error: " . $e->getMessage());
    }
    $vals   = [];
    $params = [];
}

foreach ($lines as $i => $line) {
    if (connection_aborted()) {
        flushBatch($batch_vals, $batch_params, $ins, $errs, $year);
        send(intval($startPct + ($i/$total)*$range), "Cancelled at row $i.");
        exit;
    }

    $parts = preg_split('/\s+/', trim($line), 2);
    if (count($parts) < 2 || !$parts[0] || !$parts[1]) { $skip++; continue; }
    [$code, $desc] = $parts;

    $batch_vals[]   = '(?,?,?,?,\'Y\',1,?)';
    $batch_params[] = $code;
    $batch_params[] = fmtCode($code);
    $batch_params[] = substr($desc, 0, 60);
    $batch_params[] = $desc;
    $batch_params[] = $year;

    if (count($batch_vals) >= $BATCH) {
        flushBatch($batch_vals, $batch_params, $ins, $errs, $year);
        $pct = intval($startPct + (($i+1)/$total)*$range);
        send($pct, ($i+1)."/$total — updated:$ins skipped:$skip errors:$errs");
    }
}

// Flush remaining rows
flushBatch($batch_vals, $batch_params, $ins, $errs, $year);

send(100, "✅ Complete — updated:$ins skipped:$skip errors:$errs");
