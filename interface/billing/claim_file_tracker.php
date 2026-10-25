<?php
/**
 * Claim File Tracker — X12 Remote SFTP Upload Status
 *
 * Place this file at:  interface/billing/claim_file_tracker.php
 *
 * @package  OpenEMR
 * @license  https://github.com/openemr/openemr/blob/master/LICENSE GNU GPL 3
 */

require_once(dirname(__FILE__, 2) . "/globals.php");

use OpenEMR\Billing\BillingProcessor\X12RemoteTracker;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

require_once $GLOBALS['fileroot'] . '/vendor/phpseclib/phpseclib/phpseclib/Net/SFTP.php';
require_once $GLOBALS['fileroot'] . '/vendor/phpseclib/phpseclib/phpseclib/Crypt/RSA.php';

use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

/**
 * Fetch Availity FTP settings from OpenEMR globals.
 * These are stored under the x12 partner / sftp configuration
 * (same credentials the outbound tracker uses).
 */
function getAvailityFtpSettings(): array {
    $row = sqlQuery("
        SELECT 
            x12_sftp_host,
            x12_sftp_port,
            x12_sftp_login,
            x12_sftp_pass,
            x12_sftp_remote_dir
        FROM x12_partners
        WHERE name = 'Availity'
        LIMIT 1
    ");

    if (!$row) {
        return [];
    }

    return [
        'host'        => $row['x12_sftp_host'] ?? '',
        'port'        => (int)($row['x12_sftp_port'] ?? 22),
        'username'    => $row['x12_sftp_login'] ?? '',
        'password'    => $row['x12_sftp_pass'] ?? '',
        'inbound_dir' => 'ReceiveFiles',
    ];

  
}


/**
 * Fetch received files list from Availity FTP.
 * Returns an array of file metadata entries.
 */

 function fetchReceivedFiles(): array
 {
     error_log('FETCH CALLED');
 
     $files = [];
 
     try {
 
        $partner = sqlQuery("
        SELECT
            x12_sftp_host,
            x12_sftp_login,
            x12_sftp_private_key,
            x12_sftp_port
        FROM x12_partners
        WHERE name = 'Availity'
        LIMIT 1
    ");
    
    if (empty($partner)) {
        error_log('Availity partner configuration not found');
        return [];
    }
    
    $host = trim($partner['x12_sftp_host']);
    $user = trim($partner['x12_sftp_login']);
    $port = (int)($partner['x12_sftp_port'] ?: 22);
    
    $keyFile = trim($partner['x12_sftp_private_key']);
    
    // Received Files should always use Availity's inbound folder
    $remoteDir = 'ReceiveFiles';
    
    if (!$host || !$user) {
        error_log('Missing Availity SFTP configuration');
        return [];
    }
    
    if (!file_exists($keyFile)) {
        error_log("Availity key not found: {$keyFile}");
        return [];
    }
 
         $tmpBatch = tempnam(sys_get_temp_dir(), 'availity_receive_');
 
         file_put_contents(
             $tmpBatch,
             "cd {$remoteDir}\n" .
             "ls -l\n" .
             "bye\n"
         );
 
         $cmd =
             'sftp ' .
             '-P ' . $port . ' ' .
             '-i ' . escapeshellarg($keyFile) . ' ' .
             '-o BatchMode=yes ' .
             '-o UserKnownHostsFile=/var/www/.ssh/known_hosts ' .
             '-b ' . escapeshellarg($tmpBatch) . ' ' .
             escapeshellarg($user . '@' . $host);
 
         $output = [];
         $returnCode = 0;
 
         exec($cmd . ' 2>&1', $output, $returnCode);
 
         unlink($tmpBatch);
 
         error_log('RETURN CODE=' . $returnCode);
         error_log(print_r($output, true));
 
         if ($returnCode !== 0) {
             error_log('ReceiveFiles SFTP failed');
             return [];
         }
 
         foreach ($output as $line) {
 
             $line = trim($line);
 
             if (
                 empty($line) ||
                 str_contains($line, 'Connected to') ||
                 str_contains($line, 'sftp>')
             ) {
                 continue;
             }
 
             if (
                 preg_match(
                     '/^[\-dl].*\s+(\d+)\s+([A-Za-z]{3})\s+(\d+)\s+([\d:]{4,5})\s+(.+)$/',
                     $line,
                     $m
                 )
             ) {
                 $size     = (int)$m[1];
                 $month    = $m[2];
                 $day      = $m[3];
                 $timeYear = $m[4];
                 $filename = trim($m[5]);
 
                 $dateReceived = date(
                     'Y-m-d H:i:s',
                     strtotime("$month $day $timeYear")
                 );
 
                 $files[] = [
                     'filename'      => $filename,
                     'size'          => $size,
                     'date_received' => $dateReceived,
                     'type'          => detectFileType($filename),
                     'source'        => detectSource($filename),
                     'status'        => detectPostingStatus($filename),
                     'path'          => '/' . $remoteDir . '/' . $filename
                 ];
             }
         }
 
     } catch (Throwable $e) {
         error_log("ReceiveFiles Error: " . $e->getMessage());
     }
 
     usort(
         $files,
         fn($a, $b) => strcmp($b['date_received'], $a['date_received'])
     );
 
     error_log("Received files returned: " . count($files));
 
     return $files;
 }
/**
 * Pull previously-synced received files from the local database cache
 * (x12_remote_tracker or a dedicated received_files table if present).
 */
function fetchCachedReceivedFiles(): array {
    $files = [];
    try {
        $tbl = sqlQuery("SHOW TABLES LIKE 'x12_received_files'");
        if ($tbl) {
            $rows = sqlStatement("SELECT * FROM x12_received_files ORDER BY date_received DESC LIMIT 200");
            while ($row = sqlFetchArray($rows)) {
                $files[] = $row;
            }
        }
    } catch (Exception $e) {
        // Table doesn't exist yet — return empty
    }
    return $files;
}

function detectFileType(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'era': return 'ERA';
        case 'ebr': return 'Response Report';
        case 'ebt': return 'Transmission Report';
        case 'dpr': return 'DPR';
        case 'dpt': return 'DPT';
        case 'ta1': return 'TA1';
        default: return strtoupper($ext);
    }
}

function detectSource(string $filename): string {
    $fn = strtoupper($filename);
    if (strpos($fn, 'HUMANA') !== false)  return 'Humana';
    if (strpos($fn, 'BCBS') !== false)    return 'BCBS';
    if (strpos($fn, 'AETNA') !== false)   return 'Aetna';
    if (strpos($fn, 'CIGNA') !== false)   return 'Cigna';
    if (strpos($fn, 'UHC') !== false || strpos($fn, 'UNITED') !== false) return 'UHC';
    return 'Availity';
}

function detectPostingStatus(string $filename): string {
    // Safely check if ERA has already been posted — table may not exist yet
    try {
        $tbl = sqlQuery("SHOW TABLES LIKE 'x12_received_files'");
        if ($tbl) {
            $fn = sqlQuery("SELECT status FROM x12_received_files WHERE filename = ?", [$filename]);
            if ($fn && !empty($fn['status'])) return $fn['status'];
        }
    } catch (Exception $e) {
        // Table doesn't exist — fall through to defaults
    }
    // New files from FTP default to unposted for ERA 835, accepted for 999/277
    $type = detectFileType($filename);

if ($type === 'ERA') return 'unposted';

if (
    $type === 'Response Report' ||
    $type === 'Transmission Report' ||
    $type === 'DPR' ||
    $type === 'DPT' ||
    $type === 'TA1'
) {
    return 'accepted';
}

return 'posted';
}

// ACL check — must happen before any data fetching or output
if (!AclMain::aclCheckCore('acct', 'bill')) {
    die(xlt("Not Authorized"));
}

$tracker   = new X12RemoteTracker();
$allRows   = $tracker->fetchAll();
$csrfToken = CsrfUtils::collectCsrfToken() ?? '';

// Decode messages for each row
foreach ($allRows as &$row) {
    if (!empty($row['messages']) && is_string($row['messages'])) {
        $row['messages'] = json_decode($row['messages'], true) ?? [];
    } else {
        $row['messages'] = [];
    }
}
unset($row);

// Count by status for summary bar
$statusCounts = [
    'waiting'         => 0,
    'in-progress'     => 0,
    'success'         => 0,
    'login-error'     => 0,
    'parameter-error' => 0,
    'claim-file-error'=> 0,
    'chdir-error'     => 0,
    'upload-error'    => 0,
];
foreach ($allRows as $r) {
    $s = $r['status'] ?? 'unknown';
    if (isset($statusCounts[$s])) {
        $statusCounts[$s]++;
    }
}

// Fetch received files — wrapped so any FTP/DB error never breaks the page
try {
    $receivedFiles = fetchReceivedFiles();
    error_log('FTP FILE COUNT=' . count($receivedFiles));


} catch (Exception $e) {
    $receivedFiles = [];
}
$ftpSettings   = getAvailityFtpSettings();
$ftpConfigured = !empty($ftpSettings['host']) && !empty($ftpSettings['username']);

// Tally received file stats
$rfStats = ['total'=>0,'unposted_era'=>0,'posted_era'=>0,'rejected'=>0];
foreach ($receivedFiles as $rf) {
    $rfStats['total']++;
    if (($rf['type'] ?? '') === 'ERA') {
        if (($rf['status'] ?? '') === 'unposted') $rfStats['unposted_era']++;
        else $rfStats['posted_era']++;
    }
    if (($rf['status'] ?? '') === 'rejected') $rfStats['rejected']++;
}

/**
 * Return a small inline SVG for common icons.
 * $size is applied to width/height; style is optional extra CSS.
 */
function svgIcon(string $name, string $style = '', string $size = '12'): string {
    $paths = [
        'file-medical-alt' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 9h-2v2h-2v-2H7v-2h2V7h2v2h2v2zm1-10.5V8h4.5L14 .5z"/>',
        'sync-alt'         => '<path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6a5.87 5.87 0 0 1-.72 2.84l1.46 1.46A7.93 7.93 0 0 0 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6a5.87 5.87 0 0 1 .72-2.84L5.26 7.7A7.93 7.93 0 0 0 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/>',
        'paper-plane'      => '<path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>',
        'clock'            => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/>',
        'spinner'          => '<path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 8-8 8 8 0 0 1-8 8z" opacity=".3"/><path d="M12 2a10 10 0 0 1 10 10h-2a8 8 0 0 0-8-8z"/>',
        'check-circle'     => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>',
        'lock'             => '<path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1h.01c1.71 0 3.1 1.39 3.1 3.1v2z"/>',
        'exclamation-triangle' => '<path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/>',
        'file-times'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11zm-9-6.59L10.41 12 9 10.59 10.59 9 12 10.41 13.41 9 15 10.59 13.59 12 15 13.41 13.41 15 12 13.59 10.59 15 9 13.41z"/>',
        'cloud-upload-alt' => '<path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM14 13v4h-4v-4H7l5-5 5 5h-3z"/>',
        'list'             => '<path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/>',
        'file-alt'         => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM6 20V4h7v5h5v11H6zm2-3h8v-2H8v2zm0-4h8v-2H8v2zm0-4h5V7H8v2z"/>',
        'check'            => '<path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>',
        'exclamation-circle' => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>',
        'redo-alt'         => '<path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/>',
        'question-circle'  => '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/>',
        'file-excel'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM6 20V4h7v5h5v11H6zm7.41-6l1.59 2h-2.17l-.83-1.29L11.17 16H9l1.59-2L9 12h2.17l.83 1.29L12.83 12H15l-1.59 2z"/>',
        'folder-times'     => '<path d="M20 6h-8l-2-2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6.59 9L12 13.59 10.59 15 9 13.41 10.41 12 9 10.59 10.59 9 12 10.41 13.41 9 15 10.59 13.59 12 15 13.41z"/>',
    ];
    $fill = 'currentColor';
    $p = $paths[$name] ?? '<circle cx="12" cy="12" r="10"/>';
    $styleAttr = $style ? " style=\"{$style}\"" : '';
    return "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"{$size}\" height=\"{$size}\" viewBox=\"0 0 24 24\" fill=\"{$fill}\" aria-hidden=\"true\"{$styleAttr}>{$p}</svg>";
}

function statusBadgeClass(string $status): string {
    return match ($status) {
        'success'         => 'badge-success',
        'waiting'         => 'badge-waiting',
        'in-progress'     => 'badge-inprogress',
        'login-error',
        'parameter-error',
        'claim-file-error',
        'chdir-error',
        'upload-error'    => 'badge-error',
        default           => 'badge-unknown',
    };
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo xlt('Claim File Tracker'); ?></title>
    <?php Header::setupHeader(['datatables', 'datatables-dt', 'datatables-bs']); ?>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
    <style>
    /* ── MDItek Claim File Tracker — matches Flow Board theme ── */
    :root {
        --navy:       #1e0a3c;
        --navy-mid:   #2d1260;
        --navy-light: #e8e0f5;
        --navy-pale:  #f5f2fc;
        --gold:       #9588e8;
        --white:      #ffffff;
        --bg:         #f2eff9;
        --border:     #d8d0ed;
        --text:       #1a1028;
        --text-mid:   #4a3f60;
        --text-muted: #8a7aa0;
        --radius:     10px;
        --radius-sm:  7px;
        --success:    #1e8c55;
        --success-bg: #e6f5ee;
        --error:      #9b1c1c;
        --error-bg:   #fde8e8;
        --warn:       #92400e;
        --warn-bg:    #fef3c7;
    }
    * { font-family: 'Montserrat', sans-serif !important; box-sizing: border-box; }
    body { background: var(--bg) !important; color: var(--text) !important; margin: 0; padding: 0; }
    a { text-decoration: none; }

    /* ── Page Header ── */
    .mdi-page-header {
        background: var(--navy);
        color: #fff;
        padding: 11px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 3px solid var(--gold);
        flex-wrap: wrap;
        gap: 8px;
    }
    .mdi-page-title {
        font-size: 15px; font-weight: 700; margin: 0;
        display: flex; align-items: center; gap: 9px;
    }
    .mdi-page-title i { color: var(--gold); }

    /* ── Action buttons ── */
    .mdi-action-btns { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .mdi-btn {
        height: 30px; padding: 0 13px;
        border-radius: var(--radius-sm);
        font-size: 10px; font-weight: 600;
        border: 1.5px solid rgba(255,255,255,.25);
        background: rgba(255,255,255,.1);
        color: #fff; cursor: pointer;
        transition: all .15s;
        display: inline-flex; align-items: center; gap: 6px;
        text-decoration: none;
    }
    .mdi-btn:hover { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.4); }
    .mdi-btn:disabled { opacity: .5; cursor: not-allowed; }
    .mdi-btn-primary {
        background: var(--gold); border-color: var(--gold); color: #fff;
    }
    .mdi-btn-primary:hover:not(:disabled) { background: #7c6fd4; border-color: #7c6fd4; }

    /* ── Summary pills bar ── */
    .mdi-summary-bar {
        background: var(--white);
        border-bottom: 1px solid var(--border);
        padding: 10px 20px;
        display: flex; align-items: center; flex-wrap: wrap; gap: 6px;
    }
    .mdi-status-pill {
        display: inline-flex; align-items: center; gap: 5px;
        background: var(--navy-pale); border: 1px solid var(--border);
        border-radius: 20px; padding: 3px 10px 3px 10px;
        font-size: 10px; font-weight: 600; color: var(--text-mid);
    }
    .mdi-status-pill i { font-size: 9px; color: var(--text-muted); }
    .mdi-pill-num {
        background: var(--navy); color: #fff;
        border-radius: 10px; padding: 1px 7px;
        font-size: 10px; font-weight: 700;
    }
    .mdi-pill-num.is-error   { background: #9b1c1c; }
    .mdi-pill-num.is-success { background: var(--success); }
    .mdi-pill-num.is-warn    { background: #b45309; }

    /* ── Table section ── */
    #flb_table { padding: 16px 20px 28px; background: var(--bg); }
    .mdi-table-wrap {
        overflow-x: auto;
        border-radius: var(--radius);
        box-shadow: 0 2px 10px rgba(30,10,60,.1);
        border: 1px solid var(--border);
    }
    .mdi-table-head {
        background: var(--white);
        border-bottom: 1px solid var(--border);
        padding: 10px 16px;
        display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
    }
    .mdi-table-head-title { font-size: 11px; font-weight: 700; color: var(--text-mid); margin: 0; }

    /* DataTable overrides */
    #claimTable { width: 100% !important; }
    #claimTable thead tr { background: var(--navy) !important; }
    #claimTable thead th {
        padding: 11px 14px !important;
        font-size: 9.5px !important; font-weight: 700 !important;
        text-transform: uppercase !important; letter-spacing: .7px !important;
        color: rgba(255,255,255,.75) !important;
        border: none !important; border-right: 1px solid rgba(255,255,255,.07) !important;
        white-space: nowrap;
    }
    #claimTable thead th:first-child { border-left: 4px solid var(--gold) !important; color: rgba(255,255,255,.95) !important; }
    #claimTable thead th:last-child  { border-right: none !important; }
    #claimTable tbody tr { border-bottom: 1px solid var(--border) !important; border-left: 4px solid transparent; transition: background .1s; }
    #claimTable tbody tr:nth-child(odd)  { background: var(--white) !important; }
    #claimTable tbody tr:nth-child(even) { background: var(--navy-pale) !important; }
    #claimTable tbody tr:hover { background: var(--navy-light) !important; }
    #claimTable tbody td {
        padding: 9px 14px !important; font-size: 11px !important;
        color: var(--text); border: none !important;
        border-right: 1px solid var(--border) !important;
        vertical-align: middle !important;
    }
    #claimTable tbody td:last-child { border-right: none !important; }
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        border: 1.5px solid var(--border) !important;
        border-radius: var(--radius-sm) !important;
        font-size: 11px !important; padding: 3px 8px !important;
        color: var(--text) !important;
    }
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_filter label,
    .dataTables_wrapper .dataTables_length label { font-size: 11px !important; color: var(--text-muted) !important; }
    .dataTables_wrapper .paginate_button {
        font-size: 10px !important; border-radius: var(--radius-sm) !important;
        padding: 3px 9px !important;
    }
    .dataTables_wrapper .paginate_button.current {
        background: var(--navy) !important; color: #fff !important; border-color: var(--navy) !important;
    }

    /* ── Status badges ── */
    .mdi-badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 9px; border-radius: 12px;
        font-size: 9.5px; font-weight: 700;
        white-space: nowrap; text-transform: uppercase; letter-spacing: .4px;
    }
    .badge-success    { background: var(--success-bg); color: var(--success); }
    .badge-error      { background: var(--error-bg);   color: var(--error);   }
    .badge-waiting    { background: var(--navy-pale);   color: var(--navy-mid); border: 1px solid var(--border); }
    .badge-inprogress { background: var(--warn-bg);     color: var(--warn);    }
    .badge-unknown    { background: #f0f0f0;             color: #666;           }

    /* ── File link ── */
    .filename-link { color: var(--navy-mid); font-weight: 600; font-size: 10.5px; }
    .filename-link:hover { color: var(--navy); text-decoration: underline; }

    /* ── Messages ── */
    .msg-list { margin: 0; padding: 0; list-style: none; font-size: 10px; color: var(--error); }
    .msg-list li { margin-bottom: 2px; display: flex; gap: 4px; align-items: flex-start; }
    .msg-list li::before { content: ""; }
    .no-msg { color: var(--text-muted); font-size: 10px; font-style: italic; }

    /* ── Action buttons in table ── */
    .tbl-btn {
        height: 26px; padding: 0 10px;
        border-radius: var(--radius-sm); font-size: 10px; font-weight: 600;
        border: 1.5px solid var(--border); background: var(--white);
        color: var(--text-mid); cursor: pointer;
        transition: all .15s; display: inline-flex; align-items: center; gap: 5px;
    }
    .tbl-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-pale); }
    .tbl-btn:disabled { opacity: .5; cursor: not-allowed; }

    /* ── Spinner ── */
    .spinner {
        display: inline-block; width: 12px; height: 12px;
        border: 2px solid rgba(255,255,255,.35); border-top-color: #fff;
        border-radius: 50%; animation: mdi-spin .6s linear infinite; vertical-align: middle;
    }
    .spinner-dark {
        display: inline-block; width: 12px; height: 12px;
        border: 2px solid var(--border); border-top-color: var(--navy);
        border-radius: 50%; animation: mdi-spin .6s linear infinite; vertical-align: middle;
    }
    @keyframes mdi-spin { to { transform: rotate(360deg); } }

    /* ── Refresh dot ── */
    .refresh-dot {
        width: 7px; height: 7px; border-radius: 50%;
        background: #6ee7b7; display: inline-block;
        animation: mdi-pulse 2s ease-in-out infinite;
    }
    @keyframes mdi-pulse { 0%,100%{opacity:1} 50%{opacity:.25} }

    /* ── Toast ── */
    #toast {
        position: fixed; bottom: 24px; right: 24px; z-index: 9999;
        background: var(--navy); color: #fff;
        padding: 11px 18px; border-radius: var(--radius);
        font-size: 11px; font-weight: 600;
        box-shadow: 0 4px 20px rgba(30,10,60,.3);
        opacity: 0; transform: translateY(8px);
        transition: opacity .25s, transform .25s; pointer-events: none;
        display: flex; align-items: center; gap: 8px;
    }
    #toast.show { opacity: 1; transform: translateY(0); }
    #toast.toast-success { background: #065f46; }
    #toast.toast-error   { background: #7f1d1d; }

    .dt-wrapper { padding: 16px 18px; }

    /* ═══════════════════════════════════════════════════════
       RECEIVED FILES SLIDE-OUT PANEL
    ═══════════════════════════════════════════════════════ */

    /* Overlay backdrop */
    #rfOverlay {
        position: fixed; inset: 0; z-index: 1040;
        background: rgba(10,0,30,.45);
        opacity: 0; pointer-events: none;
        transition: opacity .3s ease;
    }
    #rfOverlay.open { opacity: 1; pointer-events: all; }

    /* Slide panel */
    #rfPanel {
        position: fixed; top: 0; right: 0; bottom: 0;
        width: 560px; max-width: 96vw;
        z-index: 1050;
        background: var(--white);
        box-shadow: -6px 0 40px rgba(30,10,60,.22);
        display: flex; flex-direction: column;
        transform: translateX(100%);
        transition: transform .32s cubic-bezier(.4,0,.2,1);
    }
    #rfPanel.open { transform: translateX(0); }

    /* Panel Header */
    .rf-header {
        background: var(--navy);
        padding: 16px 20px 14px;
        flex-shrink: 0;
        border-bottom: 3px solid var(--gold);
    }
    .rf-header-top {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 5px;
    }
    .rf-title {
        font-size: 14px; font-weight: 700; color: #fff;
        display: flex; align-items: center; gap: 8px; margin: 0;
    }
    .rf-count-badge {
        background: var(--gold); color: #fff;
        font-size: 10px; font-weight: 700;
        padding: 2px 8px; border-radius: 10px;
    }
    .rf-close-btn {
        width: 28px; height: 28px; border-radius: 6px;
        border: 1.5px solid rgba(255,255,255,.25);
        background: rgba(255,255,255,.1); color: #fff;
        font-size: 14px; font-weight: 700;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: all .15s; line-height: 1;
    }
    .rf-close-btn:hover { background: rgba(255,255,255,.25); border-color: rgba(255,255,255,.5); }
    .rf-subtitle { color: rgba(255,255,255,.5); font-size: 9.5px; margin: 0; }

    /* Stats row */
    .rf-stats {
        display: grid; grid-template-columns: repeat(4, 1fr);
        background: var(--navy-pale);
        border-bottom: 1px solid var(--border);
        flex-shrink: 0;
    }
    .rf-stat-cell {
        padding: 12px 14px;
        border-right: 1px solid var(--border);
        text-align: center;
    }
    .rf-stat-cell:last-child { border-right: none; }
    .rf-stat-label { font-size: 9px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 3px; }
    .rf-stat-val { font-size: 22px; font-weight: 800; color: var(--navy); line-height: 1; }
    .rf-stat-val.orange  { color: #c2410c; }
    .rf-stat-val.green   { color: var(--success); }
    .rf-stat-val.red     { color: var(--error); }

    /* Filters bar */
    .rf-filters {
        display: flex; align-items: center; gap: 6px;
        padding: 9px 14px;
        background: var(--white);
        border-bottom: 1px solid var(--border);
        flex-shrink: 0; flex-wrap: wrap;
    }
    .rf-select {
        height: 28px; padding: 0 8px;
        border: 1.5px solid var(--border); border-radius: var(--radius-sm);
        font-size: 10px; font-weight: 600; color: var(--text-mid);
        background: var(--white); cursor: pointer;
        font-family: 'Montserrat', sans-serif !important;
    }
    .rf-date-range {
        display: flex; align-items: center; gap: 4px;
        height: 28px; padding: 0 10px;
        border: 1.5px solid var(--border); border-radius: var(--radius-sm);
        font-size: 10px; font-weight: 600; color: var(--text-mid);
        background: var(--white); white-space: nowrap; cursor: pointer;
    }
    .rf-date-range:hover { border-color: var(--navy); }

    /* Table container — scrollable */
    .rf-table-container {
        flex: 1 1 0; overflow-y: auto; padding: 0;
    }
    .rf-table {
        width: 100%; border-collapse: collapse;
    }
    .rf-table thead tr {
        background: var(--navy);
        position: sticky; top: 0; z-index: 2;
    }
    .rf-table thead th {
        padding: 9px 12px;
        font-size: 8.5px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .6px;
        color: rgba(255,255,255,.7);
        border: none; white-space: nowrap;
        border-right: 1px solid rgba(255,255,255,.07);
    }
    .rf-table thead th:first-child { border-left: 4px solid var(--gold); color: rgba(255,255,255,.95); }
    .rf-table thead th:last-child  { border-right: none; }
    .rf-table tbody tr {
        border-bottom: 1px solid var(--border);
        transition: background .1s;
    }
    .rf-table tbody tr:nth-child(odd)  { background: var(--white); }
    .rf-table tbody tr:nth-child(even) { background: var(--navy-pale); }
    .rf-table tbody tr:hover { background: var(--navy-light); }
    .rf-table tbody td {
        padding: 8px 12px;
        font-size: 10.5px; color: var(--text);
        border: none; border-right: 1px solid var(--border);
        vertical-align: middle;
    }
    .rf-table tbody td:last-child { border-right: none; }

    /* Type badges */
    .type-badge {
        display: inline-block; padding: 2px 7px;
        border-radius: 5px; font-size: 9px; font-weight: 800;
        letter-spacing: .3px; white-space: nowrap;
    }
    .type-era835 { background: #ede9fe; color: #5b21b6; }
    .type-999    { background: #d1fae5; color: #065f46; }
    .type-277ca  { background: #fef3c7; color: #92400e; }
    .type-271    { background: #dbeafe; color: #1e40af; }
    .type-other  { background: #f3f4f6; color: #374151; }

    /* Status badges — received files */
    .rf-status-badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 8px; border-radius: 10px;
        font-size: 9px; font-weight: 700;
        white-space: nowrap; text-transform: uppercase;
    }
    .rf-posted    { background: var(--success-bg); color: var(--success); }
    .rf-unposted  { background: #fff7ed; color: #c2410c; border: 1.5px solid #fed7aa; }
    .rf-accepted  { background: var(--success-bg); color: var(--success); }
    .rf-rejected  { background: var(--error-bg); color: var(--error); }

    /* Status dot */
    .rf-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
    .dot-green  { background: var(--success); }
    .dot-orange { background: #c2410c; }
    .dot-red    { background: var(--error); }

    /* Action buttons inside table */
    .rf-tbl-btn {
        height: 24px; padding: 0 9px;
        border-radius: 5px; font-size: 9.5px; font-weight: 600;
        border: 1.5px solid var(--border); background: var(--white);
        color: var(--text-mid); cursor: pointer;
        transition: all .15s; display: inline-flex; align-items: center; gap: 4px;
        font-family: 'Montserrat', sans-serif !important;
    }
    .rf-tbl-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-pale); }
    .rf-tbl-btn.btn-post {
        background: var(--gold); border-color: var(--gold); color: #fff;
    }
    .rf-tbl-btn.btn-post:hover { background: #7c6fd4; border-color: #7c6fd4; }
    .rf-tbl-btn.btn-errors {
        background: var(--error-bg); border-color: #fca5a5; color: var(--error);
    }
    .rf-tbl-btn.btn-errors:hover { background: #fee2e2; }

    /* Pagination row */
    .rf-pagination {
        display: flex; align-items: center; justify-content: space-between;
        padding: 8px 14px;
        border-top: 1px solid var(--border);
        font-size: 10px; color: var(--text-muted);
        flex-shrink: 0; background: var(--white);
    }
    .rf-page-btns { display: flex; align-items: center; gap: 4px; }
    .rf-page-btn {
        height: 26px; min-width: 26px; padding: 0 8px;
        border-radius: 5px; font-size: 10px; font-weight: 600;
        border: 1.5px solid var(--border); background: var(--white);
        color: var(--text-mid); cursor: pointer;
        transition: all .15s; display: inline-flex; align-items: center; justify-content: center;
        font-family: 'Montserrat', sans-serif !important;
    }
    .rf-page-btn.active { background: var(--navy); border-color: var(--navy); color: #fff; }
    .rf-page-btn:hover:not(.active) { border-color: var(--navy); color: var(--navy); }

    /* Footer action row */
    .rf-footer {
        display: flex; align-items: center; gap: 8px;
        padding: 11px 14px;
        border-top: 1px solid var(--border);
        background: var(--navy-pale);
        flex-shrink: 0;
    }
    .rf-footer-btn {
        height: 30px; padding: 0 13px;
        border-radius: var(--radius-sm); font-size: 10px; font-weight: 600;
        border: 1.5px solid var(--border); background: var(--white);
        color: var(--text-mid); cursor: pointer;
        transition: all .15s; display: inline-flex; align-items: center; gap: 6px;
        font-family: 'Montserrat', sans-serif !important;
    }
    .rf-footer-btn:hover { border-color: var(--navy); color: var(--navy); background: var(--navy-pale); }
    .rf-footer-btn.primary {
        background: var(--navy); border-color: var(--navy); color: #fff; margin-left: auto;
    }
    .rf-footer-btn.primary:hover { background: var(--navy-mid); border-color: var(--navy-mid); }

    /* FTP not configured warning */
    .rf-ftp-warning {
        margin: 16px; padding: 12px 14px;
        background: #fff7ed; border: 1.5px solid #fed7aa;
        border-radius: var(--radius); font-size: 11px; color: #92400e;
        display: flex; align-items: flex-start; gap: 8px;
    }

    /* Received Files tab button in header */
    .tab-btn {
        display: inline-flex; align-items: center; gap: 5px;
        height: 26px; padding: 14px;
        border-radius: var(--radius-sm); font-size: 10px; font-weight: 600;
        cursor: pointer; transition: all .15s;
        font-family: 'Montserrat', sans-serif !important;
        background: linear-gradient(135deg,#0f766e,#0d9488);
    }
    .tab-btn-outbound {
        background: rgba(255,255,255,.12); border: 1.5px solid rgba(255,255,255,.25); color: rgba(255,255,255,.75);
    }
    .tab-btn-outbound.active, .tab-btn-outbound:hover { background: rgba(255,255,255,.22); color: #fff; border-color: rgba(255,255,255,.45); }
    .tab-btn-received {
         color: #fff;
    }
    .tab-btn-received:hover { background: #7c6fd4; border-color: #7c6fd4; }
    .tab-btn-received .tab-count {
        background: rgba(255,255,255,.25); color: #fff;
        padding: 1px 5px; border-radius: 8px; font-size: 9px; font-weight: 700;
    }

    .rf-viewer-modal{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.55);
    z-index:99999;
    display:none;
    align-items:center;
    justify-content:center;
}

.rf-viewer-modal.open{
    display:flex;
}

.rf-viewer-card{
    width:90vw;
    height:85vh;
    background:#fff;
    border-radius:12px;
    overflow:hidden;
    box-shadow:0 25px 80px rgba(0,0,0,.35);
}

.rf-viewer-header{
    height:50px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 16px;
    border-bottom:1px solid #e5e7eb;
    font-weight:600;
}

.rf-viewer-close{
    border:none;
    background:none;
    font-size:22px;
    cursor:pointer;
}

.rf-viewer-frame{
    width:100%;
    height:calc(100% - 50px);
    border:none;
}

    @media (max-width: 600px) {
        #rfPanel { width: 100vw; }
        .rf-stats { grid-template-columns: repeat(2,1fr); }
    }
    </style>
</head>
<body>

<!-- ── Page Header ── -->
<div class="mdi-page-header">
    <div class="mdi-page-title">
        <?php echo svgIcon('file-medical-alt', 'color:var(--gold)', '16'); ?>
        <?php echo xlt('Claim File Tracker'); ?>
    </div>
    <div class="mdi-action-btns">
        <span style="font-size:10px;color:rgba(255,255,255,.5);display:flex;align-items:center;gap:5px;">
            <span class="refresh-dot"></span><?php echo xlt('Auto-refresh 30s'); ?>
        </span>
        <button class="mdi-btn" id="btnRefresh" onclick="refreshTable()">
            <?php echo svgIcon('sync-alt'); ?> <?php echo xlt('Refresh'); ?>
        </button>
        <button class="mdi-btn mdi-btn-primary" id="btnDispatch" onclick="dispatchNow()">
            <?php echo svgIcon('paper-plane'); ?> <?php echo xlt('Send Waiting Files'); ?>
        </button>
        <button class="tab-btn tab-btn-received" id="tabReceived" onclick="openReceivedPanel()">
        <?php echo svgIcon('file-alt','','11'); ?> <?php echo xlt('Received Files'); ?>
        <span class="tab-count"><?php echo (int)$rfStats['total']; ?></span>
    </button>
    </div>
</div>

<!-- ── Tab / View Toggle Bar ── -->
<div style="background:var(--navy-mid);padding:6px 20px;display:flex;align-items:center;gap:6px;border-bottom:1px solid rgba(255,255,255,.1);">
    <button class="tab-btn tab-btn-outbound active" id="tabOutbound">
        <?php echo svgIcon('cloud-upload-alt','','11'); ?> <?php echo xlt('Outbound Files'); ?>
        <span style="background:rgba(255,255,255,.2);color:#fff;padding:1px 5px;border-radius:8px;font-size:9px;font-weight:700;"><?php echo count($allRows); ?></span>
    </button>
  
</div>

<!-- ── Summary Pills ── -->
<div class="mdi-summary-bar">
    <?php
    $summaryDef = [
        ['key'=>'waiting',          'label'=>'Waiting',     'icon'=>'clock',                  'numcls'=>''],
        ['key'=>'in-progress',      'label'=>'In Progress', 'icon'=>'spinner',                'numcls'=>'is-warn'],
        ['key'=>'success',          'label'=>'Success',     'icon'=>'check-circle',           'numcls'=>'is-success'],
        ['key'=>'login-error',      'label'=>'Login Err',   'icon'=>'lock',                   'numcls'=>'is-error'],
        ['key'=>'parameter-error',  'label'=>'Param Err',   'icon'=>'exclamation-triangle',   'numcls'=>'is-error'],
        ['key'=>'claim-file-error', 'label'=>'File Err',    'icon'=>'file-times',             'numcls'=>'is-error'],
        ['key'=>'upload-error',     'label'=>'Upload Err',  'icon'=>'cloud-upload-alt',       'numcls'=>'is-error'],
    ];
    foreach ($summaryDef as $s):
        $cnt = $statusCounts[$s['key']] ?? 0;
    ?>
    <div class="mdi-status-pill">
        <?php echo svgIcon($s['icon'], 'color:var(--text-muted)', '9'); ?>
        <?php echo xlt($s['label']); ?>
        <span class="mdi-pill-num <?php echo $s['numcls']; ?>" id="cnt-<?php echo attr(str_replace('-','_',$s['key'])); ?>"><?php echo (int)$cnt; ?></span>
    </div>
    <?php endforeach; ?>
    <span style="margin-left:auto;font-size:10px;color:var(--text-muted);">
        <?php echo xlt('Total'); ?>: <strong id="totalCount"><?php echo count($allRows); ?></strong>
    </span>
</div>

<!-- ── Table ── -->
<div id="flb_table">
    <div class="mdi-table-wrap">
        <div class="mdi-table-head">
            <div class="mdi-table-head-title"><?php echo svgIcon('list', 'color:var(--gold);margin-right:6px;', '13'); ?><?php echo xlt('All Claim File Submissions'); ?></div>
        </div>
        <div class="dt-wrapper">
            <table id="claimTable" class="table" style="width:100%">
                <thead>
                    <tr>
                        <th><?php echo xlt('Status'); ?></th>
                        <th><?php echo xlt('X-12 Partner'); ?></th>
                        <th><?php echo xlt('File'); ?></th>
                        <th><?php echo xlt('Messages'); ?></th>
                        <th><?php echo xlt('Date Created'); ?></th>
                        <th><?php echo xlt('Date Updated'); ?></th>
                        <th><?php echo xlt('Actions'); ?></th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                <?php foreach ($allRows as $row):
                    $badgeClass = statusBadgeClass($row['status'] ?? '');
                    $msgs = $row['messages'];
                ?>
                    <tr data-id="<?php echo (int)$row['id']; ?>">
                        <td>
                            <span class="mdi-badge <?php echo attr($badgeClass); ?>">
                                <?php
                                $iconMap = [
                                    'success'         => 'check-circle',
                                    'waiting'         => 'clock',
                                    'in-progress'     => 'spinner',
                                    'login-error'     => 'lock',
                                    'parameter-error' => 'exclamation-triangle',
                                    'claim-file-error'=> 'file-excel',
                                    'chdir-error'     => 'folder-times',
                                    'upload-error'    => 'cloud-upload-alt',
                                ];
                                $ico = $iconMap[$row['status']] ?? 'question-circle';
                                ?>
                                <?php echo svgIcon($ico, '', '11'); ?>
                                <?php echo text($row['status']); ?>
                            </span>
                        </td>
                        <td style="font-weight:600;color:var(--text-mid);"><?php echo text($row['name'] ?? '—'); ?></td>
                        <td>
                        <a href="#"
                        class="filename-link"
                        onclick="previewX12File('<?php echo attr($row['x12_filename']); ?>', 'sent'); return false;">
                            <?php echo text($row['x12_filename']); ?>
                        </a>
                    </td>
                        <td>
                            <?php if (empty($msgs)): ?>
                                <span class="no-msg"><?php echo svgIcon('check', 'color:var(--success);margin-right:3px;', '11'); ?><?php echo xlt('None'); ?></span>
                            <?php else: ?>
                                <ul class="msg-list">
                                    <?php foreach ($msgs as $msg): ?>
                                        <li><?php echo svgIcon('exclamation-circle', 'color:var(--error);margin-right:3px;flex-shrink:0;', '11'); ?><?php echo text($msg); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;color:var(--text-muted);font-size:10px;"><?php echo text($row['created_at']); ?></td>
                        <td style="white-space:nowrap;color:var(--text-muted);font-size:10px;"><?php echo text($row['updated_at']); ?></td>
                        <td>
                            <?php if (in_array($row['status'], ['login-error','parameter-error','claim-file-error','chdir-error','upload-error'])): ?>
                                <button class="tbl-btn" onclick="retryRecord(<?php echo (int)$row['id']; ?>, this)">
                                    <?php echo svgIcon('redo-alt'); ?> <?php echo xlt('Retry'); ?>
                                </button>
                            <?php elseif ($row['status'] === 'waiting'): ?>
                                <span style="color:var(--navy-mid);font-size:10px;font-weight:600;"><?php echo svgIcon('clock', 'margin-right:3px;', '11'); ?><?php echo xlt('Pending'); ?></span>
                            <?php elseif ($row['status'] === 'success'): ?>
                                <span style="color:var(--success);font-size:10px;font-weight:600;"><?php echo svgIcon('check-circle', 'margin-right:3px;', '11'); ?><?php echo xlt('Sent'); ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-muted);font-size:10px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Toast ── -->
<div id="toast"></div>

<!-- ════════════════════════════════════════════════════════════
     RECEIVED FILES SLIDE-OUT PANEL
     Slides in from the right over the Claim File Tracker page.
     Uses Availity FTP credentials from OpenEMR globals.
════════════════════════════════════════════════════════════ -->
<div id="rfOverlay" onclick="closeReceivedPanel()"></div>

<div id="rfPanel" role="dialog" aria-modal="true" aria-label="<?php echo xla('Received Files'); ?>">

    <!-- Panel Header -->
    <div class="rf-header">
        <div class="rf-header-top">
            <h2 class="rf-title">
                <?php echo svgIcon('file-alt','color:var(--gold)','15'); ?>
                <?php echo xlt('Received Files'); ?>
                <span class="rf-count-badge" id="rfCountBadge"><?php echo (int)$rfStats['total']; ?></span>
            </h2>
            <button class="rf-close-btn" onclick="closeReceivedPanel()" title="<?php echo xla('Close'); ?>">✕</button>
        </div>
        <p class="rf-subtitle">
            <?php echo xlt('Files received from clearinghouse (Availity) via FTP'); ?>
            <?php if (!$ftpConfigured): ?>
                &nbsp;— <span style="color:#fca5a5;"><?php echo xlt('FTP not configured'); ?></span>
            <?php endif; ?>
        </p>
    </div>

    <!-- Summary Stats -->
    <div class="rf-stats">
        <div class="rf-stat-cell">
            <div class="rf-stat-label"><?php echo xlt('Total Files'); ?></div>
            <div class="rf-stat-val" id="rfStatTotal"><?php echo (int)$rfStats['total']; ?></div>
        </div>
        <div class="rf-stat-cell">
            <div class="rf-stat-label"><?php echo xlt('Unposted ERA'); ?></div>
            <div class="rf-stat-val orange" id="rfStatUnposted"><?php echo (int)$rfStats['unposted_era']; ?></div>
        </div>
        <div class="rf-stat-cell">
            <div class="rf-stat-label"><?php echo xlt('Posted ERA'); ?></div>
            <div class="rf-stat-val green" id="rfStatPosted"><?php echo (int)$rfStats['posted_era']; ?></div>
        </div>
        <div class="rf-stat-cell">
            <div class="rf-stat-label"><?php echo xlt('Rejected'); ?></div>
            <div class="rf-stat-val red" id="rfStatRejected"><?php echo (int)$rfStats['rejected']; ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="rf-filters">
        <select class="rf-select" id="rfFilterType" onchange="rfApplyFilters()">
        <option value="">All Types</option>
        <option value="ERA">ERA</option>
        <option value="Response Report">EBR</option>
        <option value="Transmission Report">EBT</option>
        <option value="DPR">DPR</option>
        <option value="DPT">DPT</option>
        <option value="TA1">TA1</option>
        </select>
        <select class="rf-select" id="rfFilterStatus" onchange="rfApplyFilters()">
            <option value=""><?php echo xlt('All Statuses'); ?></option>
            <option value="posted"><?php echo xlt('Posted'); ?></option>
            <option value="unposted"><?php echo xlt('Unposted'); ?></option>
            <option value="accepted"><?php echo xlt('Accepted'); ?></option>
            <option value="rejected"><?php echo xlt('Rejected'); ?></option>
        </select>
        <div class="rf-date-range" id="rfDateRange">
            <?php echo svgIcon('clock','color:var(--text-muted)','11'); ?>
            <span id="rfDateRangeLabel">
                <?php
                $weekAgo = date('m/d/Y', strtotime('-7 days'));
                $today   = date('m/d/Y');
                echo attr($weekAgo) . ' – ' . attr($today);
                ?>
            </span>
        </div>
    </div>

    <!-- Table (scrollable) -->
    <div class="rf-table-container" id="rfTableContainer">

        <?php if (!$ftpConfigured): ?>
        <div class="rf-ftp-warning">
            <?php echo svgIcon('exclamation-triangle','flex-shrink:0;margin-top:1px;','14'); ?>
            <div>
                <strong><?php echo xlt('Availity FTP not configured'); ?></strong><br>
                <?php echo xlt('Configure the Availity submitter FTP connection in Admin → Globals → X12 Partner settings to enable live file retrieval.'); ?>
            </div>
        </div>
        <?php endif; ?>

        <table class="rf-table" id="rfTable">
            <thead>
                <tr>
                    <th style="width:28px;padding-left:10px;">
                        <input type="checkbox" id="rfSelectAll" onchange="rfToggleAll(this)" style="cursor:pointer;">
                    </th>
                    <th><?php echo xlt('Date Received'); ?></th>
                    <th><?php echo xlt('Type'); ?></th>
                    <th><?php echo xlt('Source'); ?></th>
                    <th><?php echo xlt('Status'); ?></th>
                    <th><?php echo xlt('Actions'); ?></th>
                </tr>
            </thead>
            <tbody id="rfTableBody">
            <?php
            $rfDisplayed = array_slice($receivedFiles, 0, 10);
            foreach ($rfDisplayed as $rf):
                $type   = $rf['type']   ?? 'Unknown';
                $source = $rf['source'] ?? 'Availity';
                $status = $rf['status'] ?? 'unposted';
                $dt_received = $rf['date_received'] ?? '';
                $dateStr = '';
                $timeStr = '';
                if ($dt_received) {
                    $ts = strtotime($dt_received);
                    $dateStr = date('m/d/Y', $ts);
                    $timeStr = date('g:i A', $ts);
                }

                // Type badge class
                $typeCls = match ($type) {
                    'ERA'                 => 'type-era835',
                    'Response Report'     => 'type-999',
                    'Transmission Report' => 'type-277ca',
                    'DPR'                 => 'type-271',
                    'DPT'                 => 'type-271',
                    'TA1'                 => 'type-271',
                    default               => 'type-other',
                };
                // Status badge
                $statusCls = match ($status) {
                    'posted'   => 'rf-posted',
                    'unposted' => 'rf-unposted',
                    'accepted' => 'rf-accepted',
                    'rejected' => 'rf-rejected',
                    default    => 'rf-accepted',
                };
                $dotCls = match ($status) {
                    'posted','accepted' => 'dot-green',
                    'unposted'          => 'dot-orange',
                    'rejected'          => 'dot-red',
                    default             => 'dot-green',
                };
                $statusLabel = match ($status) {
                    'posted'   => xlt('Posted'),
                    'unposted' => xlt('Unposted'),
                    'accepted' => xlt('Accepted'),
                    'rejected' => xlt('Rejected'),
                    default    => xlt('Unknown'),
                };
            ?>
            <tr data-type="<?php echo attr($type); ?>" data-status="<?php echo attr($status); ?>">
                <td style="text-align:center;">
                    <input type="checkbox" class="rf-row-check" value="<?php echo attr($rf['filename'] ?? ''); ?>" style="cursor:pointer;">
                </td>
                <td>
                    <div style="font-weight:600;font-size:10.5px;color:var(--text);"><?php echo text($dateStr); ?></div>
                    <div style="font-size:9.5px;color:var(--text-muted);"><?php echo text($timeStr); ?></div>
                </td>
                <td><span class="type-badge <?php echo attr($typeCls); ?>"><?php echo text($type); ?></span></td>
                <td style="font-size:10.5px;font-weight:600;color:var(--text-mid);"><?php echo text($source); ?></td>
                <td>
                    <span class="rf-status-badge <?php echo attr($statusCls); ?>">
                        <span class="rf-dot <?php echo attr($dotCls); ?>"></span>
                        <?php echo $statusLabel; ?>
                    </span>
                </td>
                <td>
                    <?php if ($status === 'unposted' && $type === 'ERA'): ?>
                        <button class="rf-tbl-btn btn-post"
                            onclick="rfPostEra(<?php echo js_escape($rf['filename'] ?? ''); ?>, this)">
                            <?php echo svgIcon('check-circle','','10'); ?> <?php echo xlt('Post ERA'); ?>
                        </button>
                    <?php elseif ($status === 'rejected'): ?>
                        <button class="rf-tbl-btn btn-errors"
                            onclick="rfViewErrors(<?php echo js_escape($rf['filename'] ?? ''); ?>)">
                            <?php echo svgIcon('exclamation-circle','','10'); ?> <?php echo xlt('View Errors'); ?>
                        </button>
                    <?php else: ?>
                        <button class="rf-tbl-btn"
                            onclick="rfViewFile(<?php echo js_escape($rf['filename'] ?? ''); ?>)">
                            <?php echo svgIcon('file-alt','','10'); ?> <?php echo xlt('View'); ?>
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($receivedFiles)): ?>
            <tr>
                <td colspan="6" style="text-align:center;padding:30px;color:var(--text-muted);font-size:11px;">
                    <?php echo svgIcon('cloud-upload-alt','display:block;margin:0 auto 8px;color:var(--border);','28'); ?>
                    <?php echo xlt('No received files found.'); ?>
                    <?php if (!$ftpConfigured): ?>
                        <br><span style="font-size:10px;"><?php echo xlt('Configure Availity FTP in settings to sync files.'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <div class="rf-pagination">
        <span id="rfPaginationInfo">
            <?php
            $total = count($receivedFiles);
            $showing = min(10, $total);
            echo xlt('Showing') . " 1 " . xlt('to') . " {$showing} " . xlt('of') . " {$total} " . xlt('entries');
            ?>
        </span>
        <div class="rf-page-btns" id="rfPageBtns">
            <?php
            $pages = (int)ceil(count($receivedFiles) / 10);
            if ($pages <= 1) $pages = 1;
            for ($p = 1; $p <= min($pages, 5); $p++):
            ?>
            <button class="rf-page-btn <?php echo $p === 1 ? 'active' : ''; ?>"
                onclick="rfGoToPage(<?php echo $p; ?>, this)"><?php echo $p; ?></button>
            <?php endfor; ?>
            <?php if ($pages > 5): ?>
            <span style="color:var(--text-muted);font-size:10px;">…</span>
            <?php endif; ?>
            <button class="rf-page-btn" id="rfBtnNext" onclick="rfNextPage()">
                <?php echo xlt('Next'); ?> &rsaquo;
            </button>
        </div>
    </div>

    <!-- Footer actions -->
    <div class="rf-footer">
        <button class="rf-footer-btn" onclick="rfDownloadSelected()">
            <?php echo svgIcon('cloud-upload-alt','','11'); ?> <?php echo xlt('Download Selected'); ?>
        </button>
        <button class="rf-footer-btn primary" onclick="rfViewAll()">
            <?php echo svgIcon('list','','11'); ?> <?php echo xlt('View All Received Files'); ?>
            <?php echo svgIcon('file-alt','','10'); ?>
        </button>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo js_escape($csrfToken); ?>;
const AJAX_URL   = 'x12_tracker_ajax.php';

// ── Inline SVG constants (replaces Font Awesome) ─────────────────────
const SVG_PAPER_PLANE   = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>`;
const SVG_REDO          = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8-3.58-8-8-8z"/></svg>`;
const SVG_CLOCK         = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="margin-right:3px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>`;
const SVG_CHECK_CIRCLE  = `<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="margin-right:3px"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>`;

// ── DataTable init ───────────────────────────────────────────────────
let dt;
document.addEventListener('DOMContentLoaded', () => {
    dt = $('#claimTable').DataTable({
        order: [[4, 'desc']],
        pageLength: 10,
        columnDefs: [
            { orderable: false, targets: [3, 6] }
        ],
        language: {
            search: '<?php echo xlt("Search"); ?>:',
            lengthMenu: '<?php echo xlt("Show"); ?> _MENU_ <?php echo xlt("entries"); ?>',
        }
    });
});

// ── Toast helper ─────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, type = '') {
    const el = document.getElementById('toast');
    el.textContent = msg;
    el.className   = 'show' + (type ? ' toast-' + type : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = ''; }, 3500);
}

// ── Send Waiting Files ────────────────────────────────────────────────
async function dispatchNow() {
    const btn = document.getElementById('btnDispatch');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> <?php echo xlt("Sending..."); ?>';
    try {
        const fd = new FormData();
        fd.append('action',     'dispatch');
        fd.append('csrf_token', CSRF_TOKEN);
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('Dispatch complete. Refreshing...', 'success');
            setTimeout(refreshTable, 800);
        } else {
            showToast(data.error || 'Dispatch failed', 'error');
        }
    } catch (e) {
        showToast('Network error: ' + e.message, 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = SVG_PAPER_PLANE + ' <?php echo xlt("Send Waiting Files"); ?>';
    }
}

// ── Retry a single record ─────────────────────────────────────────────
async function retryRecord(id, btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-dark"></span>';
    try {
        const fd = new FormData();
        fd.append('action',     'retry');
        fd.append('id',         id);
        fd.append('csrf_token', CSRF_TOKEN);
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('<?php echo xlt("Record reset to waiting"); ?>', 'success');
            setTimeout(refreshTable, 400);
        } else {
            showToast(data.error || '<?php echo xlt("Retry failed"); ?>', 'error');
            btn.disabled = false;
        }
    } catch (e) {
        showToast('<?php echo xlt("Network error"); ?>', 'error');
        btn.disabled = false;
    }
}

// ── Refresh table data ────────────────────────────────────────────────
async function refreshTable() {
    try {
        const fd = new FormData();
        fd.append('action',     'fetch');
        fd.append('csrf_token', CSRF_TOKEN);
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.success) return;

        // Rebuild DataTable rows
        dt.clear();

        const counts = {};
        data.rows.forEach(row => {
            counts[row.status] = (counts[row.status] || 0) + 1;

            const msgs = Array.isArray(row.messages) ? row.messages : [];
            const badgeClass = statusToBadgeClass(row.status);
            const msgHtml = msgs.length
                ? '<ul class="msg-list">' + msgs.map(m => '<li>' + escHtml(m) + '</li>').join('') + '</ul>'
                : '<span class="no-msg"><?php echo xlt("None"); ?></span>';

            let actionHtml = '';
            if (['login-error','parameter-error','claim-file-error','chdir-error','upload-error'].includes(row.status)) {
                actionHtml = `<button class="tbl-btn" onclick="retryRecord(${row.id}, this)">${SVG_REDO} <?php echo xlt("Retry"); ?></button>`;
            } else if (row.status === 'waiting') {
                actionHtml = `<span style="color:var(--navy-mid);font-size:10px;font-weight:600;">${SVG_CLOCK} <?php echo xlt("Pending"); ?></span>`;
            } else if (row.status === 'success') {
                actionHtml = `<span style="color:var(--success);font-size:10px;font-weight:600;">${SVG_CHECK_CIRCLE} <?php echo xlt("Sent"); ?></span>`;
            } else {
                actionHtml = '<span style="color:var(--text-muted);font-size:10px;">—</span>';
            }

            dt.row.add([
                `<span class="mdi-badge ${badgeClass}">${escHtml(row.status)}</span>`,
                escHtml(row.name || '—'),
                `<a href="x12_tracker_download.php?file=${encodeURIComponent(row.x12_filename)}" class="filename-link" target="_blank">${escHtml(row.x12_filename)}</a>`,
                msgHtml,
                `<span style="white-space:nowrap;color:var(--text-muted);font-size:10px;">${escHtml(row.created_at)}</span>`,
                `<span style="white-space:nowrap;color:var(--text-muted);font-size:10px;">${escHtml(row.updated_at)}</span>`,
                actionHtml
            ]);
        });

        dt.draw(false);
        document.getElementById('totalCount').textContent = data.rows.length;

        // Update summary counts
        const keys = ['waiting','in_progress','success','login_error','parameter_error','claim_file_error','upload_error'];
        const statusMap = {
            'waiting':'waiting','in-progress':'in_progress','success':'success',
            'login-error':'login_error','parameter-error':'parameter_error',
            'claim-file-error':'claim_file_error','upload-error':'upload_error'
        };
        Object.entries(statusMap).forEach(([status, key]) => {
            const el = document.getElementById('cnt-' + key);
            if (el) el.textContent = counts[status] || 0;
        });

    } catch (e) {
        console.error('Refresh error', e);
    }
}

function statusToBadgeClass(status) {
    if (status === 'success')     return 'badge-success';
    if (status === 'waiting')     return 'badge-waiting';
    if (status === 'in-progress') return 'badge-inprogress';
    if (['login-error','parameter-error','claim-file-error','chdir-error','upload-error'].includes(status)) return 'badge-error';
    return 'badge-unknown';
}

function escHtml(str) {
    return String(str ?? '')
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ── Auto-refresh every 30 s ───────────────────────────────────────────
setInterval(refreshTable, 30000);

// ════════════════════════════════════════════════════════════
//  RECEIVED FILES PANEL  — JavaScript
// ════════════════════════════════════════════════════════════

// All received files (PHP → JS)
const RF_ALL = <?php echo json_encode(array_values($receivedFiles)); ?>;
const RF_PER_PAGE = 10;
let rfPage = 1;
let rfFilteredRows = [...RF_ALL];

/** Open slide-out panel */
function openReceivedPanel() {
    document.getElementById('rfPanel').classList.add('open');
    document.getElementById('rfOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    rfApplyFilters();
}

/** Close slide-out panel */
function closeReceivedPanel() {
    document.getElementById('rfPanel').classList.remove('open');
    document.getElementById('rfOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

/** Keyboard close on Escape */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeReceivedPanel();
});

/** Apply type + status filters and re-render */
function rfApplyFilters() {
    const typeFilter   = document.getElementById('rfFilterType').value;
    const statusFilter = document.getElementById('rfFilterStatus').value;
    rfFilteredRows = RF_ALL.filter(r => {
        if (typeFilter   && r.type   !== typeFilter)   return false;
        if (statusFilter && r.status !== statusFilter) return false;
        return true;
    });
    rfPage = 1;
    rfRenderTable();
    rfRenderPagination();
}

/** Render visible page of rows into table body */
function rfRenderTable() {
    const tbody = document.getElementById('rfTableBody');
    const start = (rfPage - 1) * RF_PER_PAGE;
    const rows  = rfFilteredRows.slice(start, start + RF_PER_PAGE);

    if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:28px;color:var(--text-muted);font-size:11px;">
            <?php echo xlt('No files match the selected filters.'); ?></td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map(rf => {
        const ts        = rf.date_received ? new Date(rf.date_received.replace(' ', 'T')) : null;
        const dateStr   = ts ? ts.toLocaleDateString('en-US', {month:'2-digit',day:'2-digit',year:'numeric'}) : '';
        const timeStr   = ts ? ts.toLocaleTimeString('en-US', {hour:'numeric',minute:'2-digit'}) : '';
        const typeCls = {
    'ERA':'type-era835',
    'Response Report':'type-999',
    'Transmission Report':'type-277ca',
    'DPR':'type-271',
    'DPT':'type-271',
    'TA1':'type-271'
        }[rf.type] || 'type-other';
        const statusCls = {'posted':'rf-posted','unposted':'rf-unposted','accepted':'rf-accepted','rejected':'rf-rejected'}[rf.status] || 'rf-accepted';
        const dotCls    = {'posted':'dot-green','accepted':'dot-green','unposted':'dot-orange','rejected':'dot-red'}[rf.status] || 'dot-green';
        const statusLabel = {'posted':'Posted','unposted':'Unposted','accepted':'Accepted','rejected':'Rejected'}[rf.status] || rf.status;

        let actionBtn = '';
        if (rf.status === 'unposted' && rf.type === 'ERA') {
            actionBtn = `<button class="rf-tbl-btn btn-post" onclick="rfPostEra('${escHtml(rf.filename)}', this)">
                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>
                <?php echo xlt('Post ERA'); ?></button>`;
        } else if (rf.status === 'rejected') {
            actionBtn = `<button class="rf-tbl-btn btn-errors" onclick="rfViewErrors('${escHtml(rf.filename)}')">
                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                <?php echo xlt('View Errors'); ?></button>`;
        } else {
            actionBtn = `<button class="rf-tbl-btn" onclick="rfViewFile('${escHtml(rf.filename)}')">
                <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="currentColor"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zM6 20V4h7v5h5v11H6zm2-3h8v-2H8v2zm0-4h8v-2H8v2zm0-4h5V7H8v2z"/></svg>
                <?php echo xlt('View'); ?></button>`;
        }

        return `<tr data-type="${escHtml(rf.type)}" data-status="${escHtml(rf.status)}">
            <td style="text-align:center;"><input type="checkbox" class="rf-row-check" value="${escHtml(rf.filename)}" style="cursor:pointer;"></td>
            <td>
                <div style="font-weight:600;font-size:10.5px;color:var(--text);">${dateStr}</div>
                <div style="font-size:9.5px;color:var(--text-muted);">${timeStr}</div>
            </td>
            <td><span class="type-badge ${typeCls}">${escHtml(rf.type)}</span></td>
            <td style="font-size:10.5px;font-weight:600;color:var(--text-mid);">${escHtml(rf.source)}</td>
            <td><span class="rf-status-badge ${statusCls}"><span class="rf-dot ${dotCls}"></span>${statusLabel}</span></td>
            <td>${actionBtn}</td>
        </tr>`;
    }).join('');
}

/** Render pagination buttons */
function rfRenderPagination() {
    const total   = rfFilteredRows.length;
    const pages   = Math.max(1, Math.ceil(total / RF_PER_PAGE));
    const start   = (rfPage - 1) * RF_PER_PAGE + 1;
    const end     = Math.min(rfPage * RF_PER_PAGE, total);

    document.getElementById('rfPaginationInfo').textContent =
        `<?php echo xlt('Showing'); ?> ${start} <?php echo xlt('to'); ?> ${end} <?php echo xlt('of'); ?> ${total} <?php echo xlt('entries'); ?>`;

    const btnsEl = document.getElementById('rfPageBtns');
    let html = '';
    const maxShow = 5;
    let pStart = Math.max(1, rfPage - 2);
    let pEnd   = Math.min(pages, pStart + maxShow - 1);
    if (pEnd - pStart < maxShow - 1) pStart = Math.max(1, pEnd - maxShow + 1);

    if (rfPage > 1) html += `<button class="rf-page-btn" onclick="rfGoToPage(${rfPage-1})">&lsaquo;</button>`;
    for (let p = pStart; p <= pEnd; p++) {
        html += `<button class="rf-page-btn ${p === rfPage ? 'active' : ''}" onclick="rfGoToPage(${p})">${p}</button>`;
    }
    if (rfPage < pages) html += `<button class="rf-page-btn" onclick="rfGoToPage(${rfPage+1})"><?php echo xlt('Next'); ?> &rsaquo;</button>`;
    btnsEl.innerHTML = html;
}

function rfGoToPage(p) {
    rfPage = p;
    rfRenderTable();
    rfRenderPagination();
    document.getElementById('rfTableContainer').scrollTop = 0;
}

function rfNextPage() {
    const pages = Math.max(1, Math.ceil(rfFilteredRows.length / RF_PER_PAGE));
    if (rfPage < pages) rfGoToPage(rfPage + 1);
}

/** Select / deselect all checkboxes */
function rfToggleAll(master) {
    document.querySelectorAll('.rf-row-check').forEach(c => c.checked = master.checked);
}

/** Post ERA file via AJAX */
async function rfPostEra(filename, btn) {
    if (!confirm(`<?php echo xlt('Post ERA file'); ?> "${filename}"?`)) return;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-dark"></span>';
    try {
        const fd = new FormData();
        fd.append('action',     'post_era');
        fd.append('filename',    filename);
        fd.append('csrf_token',  CSRF_TOKEN);
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast(`<?php echo xlt('ERA posted successfully'); ?>`, 'success');
            // Update local data status
            const rec = RF_ALL.find(r => r.filename === filename);
            if (rec) rec.status = 'posted';
            rfApplyFilters();
        } else {
            showToast(data.error || '<?php echo xlt('Post ERA failed'); ?>', 'error');
        }
    } catch (e) {
        showToast('<?php echo xlt('Network error'); ?>', 'error');
    } finally {
        btn.disabled = false;
    }
}

function rfViewFile(filename) {
    previewX12File(filename, 'received');
}
/** View rejection errors */
function rfViewErrors(filename) {
    const url = `x12_tracker_download.php?file=${encodeURIComponent(filename)}&type=errors`;
    window.open(url, '_blank');
}

/** Download selected files */
function rfDownloadSelected() {
    const checked = [...document.querySelectorAll('.rf-row-check:checked')].map(c => c.value);
    if (checked.length === 0) {
        showToast('<?php echo xlt('Please select at least one file'); ?>', '');
        return;
    }
    const url = `x12_tracker_download.php?type=batch&files=${encodeURIComponent(JSON.stringify(checked))}`;
    window.open(url, '_blank');
}

/** View all received files (opens full-page view) */
function rfViewAll() {
    window.open('x12_received_files.php', '_blank');
}

function closeRfViewer() {

document
    .getElementById('rfViewerModal')
    .classList
    .remove('open');

document
    .getElementById('rfViewerFrame')
    .src = '';
}
function previewX12File(filename, type) {

    document.getElementById('rfViewerFrame').src =
        'x12_tracker_download.php?file=' +
        encodeURIComponent(filename) +
        '&type=' +
        encodeURIComponent(type);

    document.getElementById('rfViewerModal').classList.add('open');
}
function closeViewer() {
    document.getElementById('rfViewerModal').classList.remove('open');
    document.getElementById('rfViewerFrame').src = '';
}
</script>

<div id="rfViewerModal" class="rf-viewer-modal">
    <div class="rf-viewer-card">
        <div class="rf-viewer-header">
            <span>EDI File Viewer</span>
            <button onclick="closeViewer()" class="rf-viewer-close">×</button>
        </div>
        <iframe id="rfViewerFrame" class="rf-viewer-frame"></iframe>
    </div>
</div>
</body>
</html>
