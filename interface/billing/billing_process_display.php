<?php
/**
 * Billing Queue Process / Validation Results Display
 * Replaces the raw-text popup with a modern styled interface.
 *
 * Drop-in replacement for the content rendered inside
 * interface/billing/billing_report.php's dlgopen validation popup.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(__DIR__ . "/../globals.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo xlt('Billing Queue Results'); ?></title>
    <style>
    /* ── Reset ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* ── Variables ── */
    :root {
        --navy:      #1a1d2e;
        --navy-mid:  #252840;
        --purple:    #5b21b6;
        --purple-l:  #ede9fe;
        --purple-m:  #7c3aed;
        --green:     #059669;
        --green-l:   #d1fae5;
        --red:       #be123c;
        --red-l:     #ffe4e6;
        --amber:     #b45309;
        --amber-l:   #fef3c7;
        --border:    #e5e7ef;
        --surface:   #f8f8fc;
        --text:      #1a1d2e;
        --text2:     #4b5563;
        --text3:     #9197b3;
        --mono:      'JetBrains Mono', 'Fira Code', 'Courier New', monospace;
    }

    /* ── Base ── */
    html, body {
        height: 100%;
        background: #fff;
        font-family: 'Segoe UI', 'DM Sans', system-ui, sans-serif;
        font-size: 13px;
        color: var(--text);
        line-height: 1.5;
    }

    /* ── Page layout ── */
    .bq-page {
        display: flex;
        flex-direction: column;
        height: 100vh;
        overflow: hidden;
    }

    /* ── Header bar ── */
    .bq-header {
        background: var(--navy);
        padding: 12px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
        gap: 12px;
    }
    .bq-header-left {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .bq-header-icon {
        width: 32px; height: 32px;
        background: rgba(91,33,182,.4);
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .bq-header-title {
        font-size: 13px;
        font-weight: 700;
        color: #fff;
        letter-spacing: -.01em;
    }
    .bq-header-sub {
        font-size: 10px;
        color: rgba(255,255,255,.45);
        font-family: var(--mono);
        margin-top: 1px;
    }
    .bq-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: .03em;
    }
    .bq-status-pill.success { background: #064e3b; color: #6ee7b7; }
    .bq-status-pill.error   { background: #7f1d1d; color: #fca5a5; }
    .bq-status-pill.pending { background: #312e81; color: #a5b4fc; }
    .bq-status-dot {
        width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0;
    }

    /* ── Tab bar ── */
    .bq-tabs {
        display: flex;
        background: var(--navy-mid);
        border-bottom: 1px solid rgba(255,255,255,.06);
        padding: 0 18px;
        flex-shrink: 0;
    }
    .bq-tab {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 9px 14px;
        font-size: 11px;
        font-weight: 600;
        color: rgba(255,255,255,.45);
        cursor: pointer;
        border-bottom: 2px solid transparent;
        transition: all .15s;
        user-select: none;
    }
    .bq-tab:hover { color: rgba(255,255,255,.75); }
    .bq-tab.active {
        color: #fff;
        border-bottom-color: var(--purple-m);
    }
    .bq-tab-count {
        background: rgba(255,255,255,.12);
        border-radius: 10px;
        padding: 1px 7px;
        font-size: 9px;
        font-weight: 700;
    }
    .bq-tab.active .bq-tab-count {
        background: var(--purple-m);
    }

    /* ── Content area ── */
    .bq-content {
        flex: 1;
        overflow: hidden;
        position: relative;
    }
    .bq-panel {
        display: none;
        height: 100%;
        overflow-y: auto;
        padding: 16px 18px;
    }
    .bq-panel.active { display: block; }

    /* ── Results summary cards ── */
    .bq-summary-row {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 10px;
        margin-bottom: 16px;
    }
    .bq-stat-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .bq-stat-icon {
        width: 34px; height: 34px;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
        font-size: 16px;
    }
    .bq-stat-icon.green { background: var(--green-l); }
    .bq-stat-icon.red   { background: var(--red-l); }
    .bq-stat-icon.amber { background: var(--amber-l); }
    .bq-stat-body {}
    .bq-stat-val {
        font-size: 20px;
        font-weight: 800;
        line-height: 1;
        font-family: var(--mono);
    }
    .bq-stat-val.green { color: var(--green); }
    .bq-stat-val.red   { color: var(--red); }
    .bq-stat-val.amber { color: var(--amber); }
    .bq-stat-lbl {
        font-size: 10px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--text3);
        margin-top: 2px;
    }

    /* ── Result list ── */
    .bq-result-list {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .bq-result-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 10px 12px;
        border-radius: 7px;
        border: 1px solid var(--border);
        background: #fff;
        font-size: 12px;
        line-height: 1.5;
    }
    .bq-result-item.success { border-left: 3px solid var(--green); }
    .bq-result-item.error   { border-left: 3px solid var(--red); background: #fff8f8; }
    .bq-result-item.warning { border-left: 3px solid var(--amber); background: #fffbf0; }
    .bq-result-icon {
        width: 20px; height: 20px;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: 11px;
        font-weight: 700;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .bq-result-icon.success { background: var(--green-l); color: var(--green); }
    .bq-result-icon.error   { background: var(--red-l);   color: var(--red); }
    .bq-result-icon.warning { background: var(--amber-l); color: var(--amber); }
    .bq-result-text { flex: 1; color: var(--text2); }
    .bq-result-claim {
        display: inline-block;
        font-family: var(--mono);
        font-size: 10.5px;
        font-weight: 700;
        background: var(--purple-l);
        color: var(--purple);
        border-radius: 4px;
        padding: 1px 6px;
        margin-right: 4px;
    }

    /* ── X12 viewer panel ── */
    .bq-x12-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
        flex-wrap: wrap;
        gap: 8px;
    }
    .bq-x12-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: var(--text3);
    }
    .bq-copy-btn {
        height: 26px;
        padding: 0 10px;
        border-radius: 5px;
        background: var(--surface);
        border: 1px solid var(--border);
        color: var(--text2);
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all .15s;
    }
    .bq-copy-btn:hover { background: var(--navy); color: #fff; border-color: var(--navy); }
    .bq-x12-viewer {
        background: #0f1117;
        border-radius: 8px;
        border: 1px solid #2a2d3e;
        padding: 14px 16px;
        font-family: var(--mono);
        font-size: 11.5px;
        line-height: 1.8;
        color: #c8d0e0;
        overflow-x: auto;
        white-space: pre;
        tab-size: 4;
    }
    /* Syntax highlights for X12 segments */
    .x12-seg     { color: #79c0ff; font-weight: 700; } /* Segment ID: ISA, GS, ST… */
    .x12-delim   { color: #6e7681; }                   /* * delimiters */
    .x12-loop    { color: #d2a8ff; }                   /* HL loops */
    .x12-clm     { color: #ffa657; }                   /* CLM claim */
    .x12-sv      { color: #7ee787; }                   /* SV service lines */
    .x12-nm1     { color: #ff7b72; }                   /* NM1 names */
    .x12-env     { color: #f8cc99; }                   /* ISA/GE envelope */

    /* ── Footer bar ── */
    .bq-footer {
        background: var(--surface);
        border-top: 1px solid var(--border);
        padding: 10px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
        gap: 10px;
    }
    .bq-footer-left {
        font-size: 10.5px;
        color: var(--text3);
        font-family: var(--mono);
    }
    .bq-footer-btns {
        display: flex;
        gap: 6px;
    }
    .bq-btn {
        height: 32px;
        padding: 0 16px;
        border-radius: 6px;
        font-size: 11.5px;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all .15s;
        border: 1px solid transparent;
    }
    .bq-btn-outline {
        background: #fff;
        border-color: var(--border);
        color: var(--text2);
    }
    .bq-btn-outline:hover { border-color: var(--navy); color: var(--navy); }
    .bq-btn-primary {
        background: var(--purple);
        color: #fff;
        border-color: var(--purple);
    }
    .bq-btn-primary:hover { background: var(--purple-m); border-color: var(--purple-m); }
    </style>
</head>
<body>

<?php
// ── Parse the output from the billing process ─────────────────────────────
// The billing processor writes lines to $GLOBALS['billing_log'] or passes
// them via session/output buffer. We read whatever was buffered.
// Variables set by the calling billing_report.php process:
//   $claim_log_text  — raw X12 or CMS text output
//   $billing_results — array of result messages

// ── Loading state: show spinner while parent fetch completes ────────────────
$is_loading = !empty($_REQUEST['loading']);
// For standalone rendering, pull from request/session if available
$x12_raw        = $_REQUEST['x12_raw']        ?? ($_SESSION['bq_x12_raw']        ?? '');
$result_msgs    = $_REQUEST['result_msgs']    ?? ($_SESSION['bq_result_msgs']    ?? '');
$claim_count    = (int)($_REQUEST['claim_count']   ?? ($_SESSION['bq_claim_count']   ?? 0));
$error_count    = (int)($_REQUEST['error_count']   ?? ($_SESSION['bq_error_count']   ?? 0));
$process_mode   = $_REQUEST['process_mode']   ?? ($_SESSION['bq_process_mode']   ?? xl('Validate'));
$batch_id       = $_REQUEST['batch_id']       ?? ($_SESSION['bq_batch_id']       ?? '');
$ack_raw        = $_REQUEST['ack_raw']         ?? ($_SESSION['bq_ack_raw']         ?? '');

function parseAvailityAckFile(string $raw): array {
    $results = [];

    if (!$raw) {
        return $results;
    }

    $segments = preg_split('/[\r\n~]+/', trim($raw));

    foreach ($segments as $seg) {
        $seg = trim($seg);
        if ($seg === '') {
            continue;
        }

        $parts = explode('*', $seg);
        $id = $parts[0] ?? '';

        // 999 Implementation Acknowledgment errors
        if ($id === 'IK3') {
            $results[] = [
                'type' => 'error',
                'source' => '999',
                'code' => $parts[4] ?? 'IK3',
                'text' => '999 segment error: segment ' . ($parts[1] ?? '') .
                    ', position ' . ($parts[2] ?? '') .
                    ', loop ' . ($parts[3] ?? '') .
                    ', error code ' . ($parts[4] ?? '')
            ];
        }

        if ($id === 'IK4') {
            $results[] = [
                'type' => 'error',
                'source' => '999',
                'code' => $parts[3] ?? 'IK4',
                'text' => '999 element error: element position ' . ($parts[1] ?? '') .
                    ', component ' . ($parts[2] ?? '') .
                    ', error code ' . ($parts[3] ?? '') .
                    ', bad value: ' . ($parts[4] ?? '')
            ];
        }

        // 999 transaction status
        if ($id === 'IK5') {
            $status = $parts[1] ?? '';
            if ($status !== 'A') {
                $results[] = [
                    'type' => 'error',
                    'source' => '999',
                    'code' => 'IK5-' . $status,
                    'text' => '999 transaction rejected or partially accepted. Status: ' . $status
                ];
            }
        }

        if ($id === 'AK9') {
            $status = $parts[1] ?? '';
            if ($status !== 'A') {
                $results[] = [
                    'type' => 'error',
                    'source' => '999',
                    'code' => 'AK9-' . $status,
                    'text' => '999 functional group rejected or partially accepted. Status: ' . $status
                ];
            }
        }

        // 277CA claim status
        if ($id === 'STC') {
            $statusComposite = $parts[1] ?? '';
            $statusDate      = $parts[2] ?? '';
            $actionCode      = $parts[3] ?? '';
            $amount          = $parts[4] ?? '';

            $isReject = preg_match('/A3|A6|A7|A8|F2|F3|F4|F5/i', $statusComposite);

            $results[] = [
                'type' => $isReject ? 'error' : 'warning',
                'source' => '277CA',
                'code' => $statusComposite,
                'text' => '277CA claim status: ' . $statusComposite .
                    ($actionCode ? ' | action: ' . $actionCode : '') .
                    ($statusDate ? ' | date: ' . $statusDate : '') .
                    ($amount ? ' | amount: ' . $amount : '')
            ];
        }

        // 277CA free text
        if ($id === 'MSG') {
            $results[] = [
                'type' => 'warning',
                'source' => '277CA',
                'code' => 'MSG',
                'text' => '277CA message: ' . ($parts[1] ?? '')
            ];
        }
    }

    return $results;
}


// Clearinghouse Validation
function runClearinghouseValidation(string $x12): array {
    $errors = [];
    $warnings = [];

    if (!$x12) return ['errors' => $errors, 'warnings' => $warnings];

    // Split segments
    $segments = preg_split('/[\r\n~]+/', $x12);

    $submitterName = '';
    $submitterContact = '';
    $billingNPI = '';
    $renderingNPI = '';

    foreach ($segments as $seg) {
        if (strpos($seg, 'NM1*41') === 0) {
            $parts = explode('*', $seg);
            $submitterName = trim($parts[3] ?? '');
        }

        if (strpos($seg, 'PER') === 0) {
            $parts = explode('*', $seg);
            $submitterContact = trim($parts[2] ?? '');
        }

        if (strpos($seg, 'NM1*85') === 0) {
            $parts = explode('*', $seg);
            $billingNPI = trim($parts[9] ?? '');
        }

        if (strpos($seg, 'NM1*82') === 0) {
            $parts = explode('*', $seg);
            $renderingNPI = trim($parts[9] ?? '');
        }
    }

    // 🚨 RULE 1 — PER02 = NM1 (Availity rejection risk)
    if ($submitterContact && $submitterName &&
        strtoupper($submitterContact) === strtoupper($submitterName)) {

        $warnings[] = [
            'code' => 'PER02',
            'text' => "Submitter contact name matches submitter name (Availity may reject)",
        ];
    }

    // 🚨 RULE 2 — Billing = Rendering NPI
    if ($billingNPI && $renderingNPI && $billingNPI === $renderingNPI) {
        $warnings[] = [
            'code' => 'NM1',
            'text' => "Billing provider NPI equals rendering provider (payer-dependent rejection risk)",
        ];
    }

    // 🚨 RULE 3 — Missing CLM segment
    if (strpos($x12, 'CLM*') === false) {
        $errors[] = [
            'code' => 'CLM',
            'text' => "Missing CLM segment (invalid claim)",
        ];
    }

    return [
        'errors' => $errors,
        'warnings' => $warnings
    ];
}

// Parse result messages into typed entries
$parsed_results = [];
$success_count  = 0;
$warn_count     = 0;



// 🔥 NEW: Run real validation
$validation = runClearinghouseValidation($x12_raw);

// Add errors
foreach ($validation['errors'] as $err) {
    $parsed_results[] = [
        'type' => 'error',
        'text' => "[{$err['code']}] {$err['text']}"
    ];
}

// Add warnings
foreach ($validation['warnings'] as $warn) {
    $parsed_results[] = [
        'type' => 'warning',
        'text' => "[{$warn['code']}] {$warn['text']}"
    ];
}

// Count them
$error_count = count($validation['errors']);
$warn_count  = count($validation['warnings']);

if (!empty($result_msgs)) {
    foreach (explode("\n", trim($result_msgs)) as $line) {
        $line = trim($line);
        if (!$line) continue;
        if (preg_match('/error|fail|invalid|missing|rejected/i', $line)) {
            $parsed_results[] = ['type' => 'error', 'text' => $line];
            $error_count++;
        } elseif (preg_match('/warn|caution|check/i', $line)) {
            $parsed_results[] = ['type' => 'warning', 'text' => $line];
            $warn_count++;
        } else {
            $parsed_results[] = ['type' => 'success', 'text' => $line];
            $success_count++;
        }
    }
}

// Merge Availity 999/277CA acknowledgment results
$availity_results = parseAvailityAckFile($ack_raw);
foreach ($availity_results as $ack) {
    $parsed_results[] = [
        'type' => $ack['type'],
        'text' => '[' . $ack['source'] . ' ' . $ack['code'] . '] ' . $ack['text']
    ];
    if ($ack['type'] === 'error') {
        $error_count++;
    } else {
        $warn_count++;
    }
}

// If no parsed results yet, show placeholder
if (empty($parsed_results)) {
    $parsed_results[] = [
        'type' => 'success',
        'text' => xl('Billing queue processing complete. Check the X12 tab for full claim output.')
    ];
    $success_count = 1;
}

// Determine overall status
$overall_status = $error_count > 0 ? 'error' : ($warn_count > 0 ? 'warning' : 'success');
$status_label = $error_count > 0
    ? xl('Failed Validation')
    : ($warn_count > 0 ? xl('Ready with Warnings') : xl('Ready to Submit'));

// X12 syntax highlight
function highlightX12(string $raw): string {
    if (!$raw) return '';
    $lines = explode("\n", htmlspecialchars($raw, ENT_QUOTES));
    $out = [];
    foreach ($lines as $line) {
        if (!trim($line)) { $out[] = $line; continue; }
        $seg = strtok($line, '*~');
        if (in_array($seg, ['ISA','GS','GE','IEA'])) {
            $css = 'x12-env';
        } elseif (in_array($seg, ['ST','SE'])) {
            $css = 'x12-seg';
        } elseif (strpos($seg, 'HL') === 0) {
            $css = 'x12-loop';
        } elseif ($seg === 'CLM') {
            $css = 'x12-clm';
        } elseif (strpos($seg, 'SV') === 0) {
            $css = 'x12-sv';
        } elseif ($seg === 'NM1') {
            $css = 'x12-nm1';
        } else {
            $css = 'x12-seg';
        }
        $line = preg_replace('/^([A-Z0-9]+)(\*)/', "<span class=\"{$css}\">$1</span><span class=\"x12-delim\">*</span>", $line);
        $line = str_replace('*', '<span class="x12-delim">*</span>', substr($line, strpos($line, '</span>') + 7));
        // Rebuild
        $out[] = $line;
    }
    return implode("\n", $out);
}

$x12_highlighted = highlightX12($x12_raw);
$x12_line_count  = $x12_raw ? substr_count($x12_raw, "\n") + 1 : 0;
$result_count    = count($parsed_results);

// Status pill class
if ($overall_status === 'error') {
    $pill_class = 'error';
} elseif ($overall_status === 'warning') {
    $pill_class = 'pending';
} else {
    $pill_class = 'success';
}


?>

<div class="bq-page">

    <!-- ── Header ── -->
    <div class="bq-header">
        <div class="bq-header-left">
            <div class="bq-header-icon">
                <svg width="16" height="16" fill="none" stroke="rgba(167,139,250,1)" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <div class="bq-header-title"><?php echo xlt('Billing Queue Results'); ?></div>
                <div class="bq-header-sub">
                    <?php echo xlt('Mode'); ?>: <?php echo text($process_mode); ?>
                    <?php if ($batch_id): ?> &nbsp;·&nbsp; <?php echo xlt('Batch'); ?> <?php echo text($batch_id); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <span class="bq-status-pill <?php echo attr($pill_class); ?>">
            <span class="bq-status-dot"></span>
            <?php echo text($status_label); ?>
        </span>
    </div>

    <!-- ── Tabs ── -->
    <div class="bq-tabs">
        <div class="bq-tab active" onclick="bqTab('results', this)">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <?php echo xlt('Results'); ?>
            <span class="bq-tab-count"><?php echo (int)$result_count; ?></span>
        </div>
        <?php if ($x12_raw): ?>
        <div class="bq-tab" onclick="bqTab('x12', this)">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
            <?php echo xlt('X12 Output'); ?>
            <span class="bq-tab-count"><?php echo (int)$x12_line_count; ?> ln</span>
        </div>
        <?php endif; ?>
        <?php if ($ack_raw): ?>
        <div class="bq-tab" onclick="bqTab('ack', this)">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/><polyline points="13 2 13 9 20 9"/></svg>
            <?php echo xlt('Availity Response'); ?>
            <span class="bq-tab-count"><?php echo substr_count($ack_raw, '~') + 1; ?> seg</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── Content ── -->
    <div class="bq-content">

        <!-- Results panel -->
        <div class="bq-panel active" id="bq-panel-results">

            <!-- Summary stats -->
            <div class="bq-summary-row">
                <div class="bq-stat-card">
                    <div class="bq-stat-icon green">✓</div>
                    <div class="bq-stat-body">
                        <div class="bq-stat-val green"><?php echo (int)$success_count; ?></div>
                        <div class="bq-stat-lbl"><?php echo xlt('Validated'); ?></div>
                    </div>
                </div>
                <div class="bq-stat-card">
                    <div class="bq-stat-icon red">✗</div>
                    <div class="bq-stat-body">
                        <div class="bq-stat-val red"><?php echo (int)$error_count; ?></div>
                        <div class="bq-stat-lbl"><?php echo xlt('Errors'); ?></div>
                    </div>
                </div>
                <div class="bq-stat-card">
                    <div class="bq-stat-icon amber">!</div>
                    <div class="bq-stat-body">
                        <div class="bq-stat-val amber"><?php echo (int)$warn_count; ?></div>
                        <div class="bq-stat-lbl"><?php echo xlt('Warnings'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Result messages -->
            <div class="bq-result-list" id="bq-result-list">
                <?php foreach ($parsed_results as $item): ?>
                <?php
                    $type  = htmlspecialchars($item['type']);
                    $text  = $item['text'];
                    if ($item['type'] === 'error') { $icon = '✗'; }
                    elseif ($item['type'] === 'warning') { $icon = '!'; }
                    else { $icon = '✓'; }
                    // Highlight claim numbers like 7-44
                    $text_html = preg_replace('/\b(\d+-\d+)\b/', '<span class="bq-result-claim">$1</span>', htmlspecialchars($text, ENT_QUOTES));
                ?>
                <div class="bq-result-item <?php echo $type; ?>">
                    <span class="bq-result-icon <?php echo $type; ?>"><?php echo $icon; ?></span>
                    <span class="bq-result-text">
                    <?php echo $text_html; ?>
                    <?php if (strpos($text, 'PER02') !== false): ?>
                        <div style="font-size:11px; color:#6b7280; margin-top:4px;">
                            Fix: Remove PER02 or leave contact blank for Availity.
                        </div>
                    <?php endif; ?>
                </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- X12 panel -->
        <?php if ($x12_raw): ?>
        <div class="bq-panel" id="bq-panel-x12">
            <div class="bq-x12-toolbar">
                <span class="bq-x12-label"><?php echo xlt('X12 Claim Data'); ?> &mdash; <?php echo (int)$x12_line_count; ?> <?php echo xlt('lines'); ?></span>
                <button class="bq-copy-btn" onclick="bqCopyX12()">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    <?php echo xlt('Copy'); ?>
                </button>
            </div>
            <div class="bq-x12-viewer" id="bq-x12-raw"><?php
                // Output highlighted X12 — if no highlight, fall back to escaped raw
                echo $x12_highlighted ?: htmlspecialchars($x12_raw, ENT_QUOTES);
            ?></div>
        </div>
        <?php endif; ?>

        <!-- Availity 999 / 277CA response panel -->
        <?php if ($ack_raw): ?>
        <div class="bq-panel" id="bq-panel-ack">
            <div class="bq-x12-toolbar">
                <span class="bq-x12-label"><?php echo xlt('Raw Availity 999 / 277CA Response'); ?></span>
                <button class="bq-copy-btn" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('bq-ack-raw').textContent)">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                    <?php echo xlt('Copy'); ?>
                </button>
            </div>
            <div class="bq-x12-viewer" id="bq-ack-raw"><?php echo htmlspecialchars($ack_raw, ENT_QUOTES); ?></div>
        </div>
        <?php endif; ?>

    </div><!-- /bq-content -->

    <!-- ── Footer ── -->
    <div class="bq-footer">
        <div class="bq-footer-left">
            <?php if ($claim_count > 0): ?>
            <?php echo xlt('Total claims'); ?>: <strong><?php echo (int)$claim_count; ?></strong>
            <?php endif; ?>
        </div>
        <div class="bq-footer-btns">
            <button class="bq-btn bq-btn-outline" onclick="bqViewLogs()">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <?php echo xlt('Logs'); ?>
            </button>
            <button class="bq-btn bq-btn-primary" onclick="window.close()">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo xlt('Close'); ?>
            </button>
        </div>
    </div>

</div><!-- /bq-page -->

<script>
// ── Tab switching ────────────────────────────────────────────────────────────
function bqTab(name, tab) {
    document.querySelectorAll('.bq-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.bq-panel').forEach(function(p){ p.classList.remove('active'); });
    tab.classList.add('active');
    var panel = document.getElementById('bq-panel-' + name);
    if (panel) panel.classList.add('active');
}

// ── Copy X12 to clipboard ───────────────────────────────────────────────────
function bqCopyX12() {
    var el = document.getElementById('bq-x12-raw');
    if (!el) return;
    var text = el.innerText || el.textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            var btn = event.target.closest('.bq-copy-btn');
            if (btn) { btn.textContent = '✓ Copied'; setTimeout(function(){ btn.innerHTML = '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg> <?php echo xlt('Copy'); ?>'; }, 1500); }
        });
    }
}

// ── Open logs ───────────────────────────────────────────────────────────────
function bqViewLogs() {
    if (typeof opener !== 'undefined' && opener && opener.top) {
        // Try to trigger the parent's log view if available
        if (typeof opener.top.openBillingLog === 'function') {
            opener.top.openBillingLog();
        } else {
            opener.top.location.href = '<?php echo $GLOBALS["webroot"]; ?>/interface/billing/billing_log.php';
        }
    } else {
        window.open('<?php echo $GLOBALS["webroot"]; ?>/interface/billing/billing_log.php', 'billing_log', 'width=800,height=600,scrollbars=1');
    }
}

// ── Animate results in on load ───────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var items = document.querySelectorAll('.bq-result-item');
    items.forEach(function(item, i) {
        item.style.opacity = '0';
        item.style.transform = 'translateY(8px)';
        item.style.transition = 'opacity .2s ease, transform .2s ease';
        setTimeout(function() {
            item.style.opacity = '1';
            item.style.transform = 'translateY(0)';
        }, 60 + i * 40);
    });
});

// ── Accept messages pushed from parent ──────────────────────────────────────
// The parent billing_report.php can call:
//   popup.addResult(type, text)  after opening this popup
window.addResult = function(type, text) {
    var list = document.getElementById('bq-result-list');
    var icon = type === 'error' ? '✗' : type === 'warning' ? '!' : '✓';
    var textHtml = text.replace(/\b(\d+-\d+)\b/g, '<span class="bq-result-claim">$1</span>');
    var div = document.createElement('div');
    div.className = 'bq-result-item ' + type;
    div.innerHTML = '<span class="bq-result-icon ' + type + '">' + icon + '</span>'
                  + '<span class="bq-result-text">' + textHtml + '</span>';
    div.style.opacity = '0';
    div.style.transform = 'translateY(8px)';
    div.style.transition = 'opacity .2s, transform .2s';
    list.appendChild(div);
    requestAnimationFrame(function(){ div.style.opacity = '1'; div.style.transform = 'translateY(0)'; });

    // Update stat counts
    var map = { success: 0, error: 1, warning: 2 };
    var vals = document.querySelectorAll('.bq-stat-val');
    if (vals[map[type]]) vals[map[type]].textContent = parseInt(vals[map[type]].textContent || 0) + 1;

    // Update tab count
    var tabCount = document.querySelector('.bq-tab:first-child .bq-tab-count');
    if (tabCount) tabCount.textContent = list.children.length;
};

window.appendX12 = function(text) {
    var el = document.getElementById('bq-x12-raw');
    if (el) el.textContent += text;
};

// ── bqReceiveData: called by parent billing_report.php doSubmit() ─────────────
// Receives parsed billing data from the fetch response and renders it
window.bqReceiveData = function(data) {
    // Update mode label in header
    var sub = document.querySelector('.bq-header-sub');
    if (sub && data.modeLabel) {
        sub.textContent = 'Mode: ' + data.modeLabel;
    }

    // Clear loading state / placeholder results
    var list = document.getElementById('bq-result-list');
    if (list) list.innerHTML = '';

    // Tally counts
    var successCount = 0, errorCount = 0, warnCount = 0;

    // Render each result line
    (data.resultLines || []).forEach(function(line, i) {
        var type = 'success', icon = '✓';
        if (/error|fail|invalid|missing|No X-12/i.test(line)) {
            type = 'error'; icon = '✗'; errorCount++;
        } else if (/warn|caution|check/i.test(line)) {
            type = 'warning'; icon = '!'; warnCount++;
        } else {
            successCount++;
        }
        var textHtml = line.replace(/(\d+-\d+)/g,
            '<span class="bq-result-claim">$1</span>');
        var div = document.createElement('div');
        div.className = 'bq-result-item ' + type;
        div.innerHTML = '<span class="bq-result-icon ' + type + '">' + icon + '</span>'
                      + '<span class="bq-result-text">' + textHtml + '</span>';
        div.style.opacity = '0';
        div.style.transform = 'translateY(8px)';
        div.style.transition = 'opacity .18s ease ' + (i * 35) + 'ms, transform .18s ease ' + (i * 35) + 'ms';
        if (list) list.appendChild(div);
        requestAnimationFrame(function() {
            div.style.opacity = '1'; div.style.transform = 'translateY(0)';
        });
    });

    // Update stat counters
    var vals = document.querySelectorAll('.bq-stat-val');
    if (vals[0]) vals[0].textContent = successCount;
    if (vals[1]) vals[1].textContent = errorCount;
    if (vals[2]) vals[2].textContent = warnCount;

    // Update status pill
    var pill = document.querySelector('.bq-status-pill');
    if (pill) {
        pill.className = 'bq-status-pill ' + (errorCount > 0 ? 'error' : warnCount > 0 ? 'pending' : 'success');
        pill.innerHTML = '<span class="bq-status-dot"></span>' +
            (errorCount > 0 ? 'Errors Found' : warnCount > 0 ? 'Ready with Warnings' : 'Ready to Submit');
    }

    // Populate X12 viewer if we got X12 data
    if (data.x12Raw) {
        var x12El = document.getElementById('bq-x12-raw');
        if (x12El) {
            x12El.textContent = data.x12Raw;
        } else {
            // X12 panel may be hidden — make it visible
            var x12Panel = document.getElementById('bq-panel-x12');
            if (!x12Panel) {
                // Create the tab and panel dynamically
                var tabBar = document.querySelector('.bq-tabs');
                if (tabBar) {
                    var newTab = document.createElement('div');
                    newTab.className = 'bq-tab';
                    newTab.onclick = function(){ bqTab('x12', this); };
                    newTab.innerHTML = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg> X12 Output <span class="bq-tab-count">' + (data.x12Raw ? data.x12Raw.split('\n').length : 0) + ' ln</span>';
                    tabBar.appendChild(newTab);
                }
                var contentArea = document.querySelector('.bq-content');
                if (contentArea) {
                    var newPanel = document.createElement('div');
                    newPanel.className = 'bq-panel';
                    newPanel.id = 'bq-panel-x12';
                    newPanel.innerHTML = '<div class="bq-x12-toolbar"><span class="bq-x12-label">X12 Claim Data</span></div><div class="bq-x12-viewer" id="bq-x12-raw"></div>';
                    contentArea.appendChild(newPanel);
                    document.getElementById('bq-x12-raw').textContent = data.x12Raw;
                }
            }
        }
        // Update X12 tab count
        var x12Tab = document.querySelector('.bq-tab:nth-child(2) .bq-tab-count');
        if (x12Tab) x12Tab.textContent = data.x12Raw.split('\n').length + ' ln';
    }

    // Update footer claim count
    var footerLeft = document.querySelector('.bq-footer-left');
    if (footerLeft && data.claimCount) {
        footerLeft.innerHTML = 'Total claims: <strong>' + data.claimCount + '</strong>';
    }
};

// ── Show loading spinner while waiting for bqReceiveData ─────────────────────
<?php if ($is_loading || (!$x12_raw && !$result_msgs)): ?>
(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var list = document.getElementById('bq-result-list');
        if (list && list.children.length <= 1) {
            list.innerHTML = '<div style="display:flex;align-items:center;gap:12px;padding:20px;color:var(--text3);">'
                + '<svg style="animation:spin 1s linear infinite;flex-shrink:0;" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>'
                + '<span>Processing billing request...</span>'
                + '</div>';
        }
    });
    var style = document.createElement('style');
    style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
    document.head.appendChild(style);
})();
<?php endif; ?>
</script>

</body>
</html>
