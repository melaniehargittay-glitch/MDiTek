<?php
/**
 * x12_tracker_download.php
 *
 * Serves EDI batch files through PHP so Apache's directory protection on
 * /sites/default/documents/ doesn't block direct browser access.
 *
 * Place at: interface/billing/x12_tracker_download.php
 * (same directory as claim_file_tracker.php and x12_tracker_ajax.php)
 *
 * @package  OpenEMR
 * @license  https://github.com/openemr/openemr/blob/master/LICENSE GNU GPL 3
 */

require_once(dirname(__FILE__, 2) . "/globals.php");

use OpenEMR\Common\Acl\AclMain;

function viewReceivedFile(string $filename): void
{
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
        die('Availity configuration not found');
    }

    $host = trim($partner['x12_sftp_host']);
    $user = trim($partner['x12_sftp_login']);
    $port = (int)($partner['x12_sftp_port'] ?: 22);
    $keyFile = trim($partner['x12_sftp_private_key']);

    $tmpLocal = tempnam(sys_get_temp_dir(), 'availity_view_');
    $tmpBatch = tempnam(sys_get_temp_dir(), 'availity_batch_');

    file_put_contents(
        $tmpBatch,
        "cd ReceiveFiles\n" .
        "get " . escapeshellarg($filename) . " " . escapeshellarg($tmpLocal) . "\n" .
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

    exec($cmd . ' 2>&1', $output, $rc);

    unlink($tmpBatch);

    if ($rc !== 0 || !file_exists($tmpLocal) || filesize($tmpLocal) === 0) {
        if (file_exists($tmpLocal)) {
            unlink($tmpLocal);
        }

        http_response_code(404);
        die('Unable to retrieve file');
    }

    $contents = file_get_contents($tmpLocal);

    unlink($tmpLocal);

    header('Content-Type: text/html; charset=utf-8');

    echo '<html><head><title>' . htmlspecialchars($filename) . '</title>';
    echo '<style>
        body{font-family:Arial,sans-serif;padding:20px;background:#f5f7fb;}
        .card{background:#fff;padding:20px;border-radius:8px;
              box-shadow:0 2px 10px rgba(0,0,0,.08);}
        pre{
            white-space:pre-wrap;
            word-break:break-word;
            font-family:Consolas,monospace;
            font-size:13px;
        }
    </style>';
    echo '</head><body>';
    echo '<div class="card">';
    echo '<h2>' . htmlspecialchars($filename) . '</h2>';
    echo '<pre>' . htmlspecialchars($contents) . '</pre>';
    echo '</div>';
    echo '</body></html>';
}

/** View individual received file */
function rfViewFile(string $filename): void 
{
    $filename = basename($filename);

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
            die('Availity partner configuration not found');
        }

        $host = trim($partner['x12_sftp_host']);
        $user = trim($partner['x12_sftp_login']);
        $port = (int)($partner['x12_sftp_port'] ?: 22);
        $keyFile = trim($partner['x12_sftp_private_key']);

        $remoteDir = 'ReceiveFiles';

        if (!$host || !$user || !$keyFile) {
            die('Missing Availity SFTP configuration');
        }

        if (!file_exists($keyFile)) {
            die('Availity key not found');
        }

        $tmpLocal = tempnam(sys_get_temp_dir(), 'availity_view_');
        $tmpBatch = tempnam(sys_get_temp_dir(), 'availity_batch_');

        file_put_contents(
            $tmpBatch,
            "cd {$remoteDir}\n" .
            "get " . escapeshellarg($filename) . " " . escapeshellarg($tmpLocal) . "\n" .
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

        if (
            $returnCode !== 0 ||
            !file_exists($tmpLocal) ||
            filesize($tmpLocal) === 0
        ) {
            unlink($tmpLocal);
            die('Unable to retrieve file');
        }

        $contents = file_get_contents($tmpLocal);

        unlink($tmpLocal);

        echo '<!DOCTYPE html>';
        echo '<html><head><title>' . htmlspecialchars($filename) . '</title>';

        echo '<style>
        body{
            margin:0;
            padding:20px;
            background:#f5f7fb;
            font-family:Arial,sans-serif;
        }
        .card{
            background:#fff;
            border-radius:8px;
            padding:20px;
            box-shadow:0 2px 10px rgba(0,0,0,.08);
        }
        h2{
            margin-top:0;
        }
        pre{
            white-space:pre-wrap;
            word-wrap:break-word;
            font-size:13px;
            line-height:1.4;
            font-family:Consolas,Monaco,monospace;
        }
        </style>';

        echo '</head><body>';

        echo '<div class="card">';
        echo '<h2>' . htmlspecialchars($filename) . '</h2>';
        echo '<pre>' . htmlspecialchars($contents) . '</pre>';
        echo '</div>';

        echo '</body></html>';

        exit;

    } catch (Throwable $e) {
        error_log('ViewReceivedFile Error: ' . $e->getMessage());
        die('Unable to open file');
    }
}


// ACL check — same permission as the tracker page
if (!AclMain::aclCheckCore('acct', 'bill')) {
    http_response_code(403);
    die(xlt("Not Authorized"));
}

// Get and sanitize filename.
// Allow only the characters that appear in EDI batch filenames:
// digits, letters, hyphens, underscores, dots. No slashes, no null bytes.
$filename = isset($_GET['file']) ? trim($_GET['file']) : '';
$type = $_GET['type'] ?? '';

if (
    $filename === '' ||
    !preg_match('/^[\w\-\.]+$/', $filename) ||
    strpos($filename, '..') !== false
) {
    http_response_code(400);
    die(xlt("Invalid filename"));
}

if ($type === 'received') {
    viewReceivedFile($filename);
    exit;
}



// Build path using the OpenEMR site dir global (filesystem path, not web URL)
$filePath = $GLOBALS['OE_SITE_DIR'] . '/documents/edi/' . $filename;

if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    die(xlt("File not found"));
}

// Determine MIME type
$ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$mime = ($ext === 'txt') ? 'text/plain' : 'application/octet-stream';

// Stream the file to the browser as a download
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($filePath);
exit;
