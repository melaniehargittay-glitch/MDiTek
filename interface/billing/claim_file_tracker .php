<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
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

// ACL check
if (!AclMain::aclCheckCore('acct', 'bill')) {
    die(xlt("Not Authorized"));
}

$tracker  = new X12RemoteTracker();
$allRows  = $tracker->fetchAll();
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
    </div>
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
                            <a href="<?php echo attr($GLOBALS['webroot']); ?>/interface/billing/x12_tracker_download.php?file=<?php echo attr(urlencode($row['x12_filename'])); ?>"
                               class="filename-link" target="_blank">
                                <?php echo svgIcon('file-alt', 'color:var(--gold);margin-right:3px;', '12'); ?><?php echo text($row['x12_filename']); ?>
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
                `<a href="<?php echo attr($GLOBALS['webroot']); ?>/interface/billing/x12_tracker_download.php?file=${encodeURIComponent(row.x12_filename)}" class="filename-link" target="_blank">${escHtml(row.x12_filename)}</a>`,
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
</script>

</body>
</html>
