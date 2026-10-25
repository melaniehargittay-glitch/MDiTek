<?php
/**
 * Patient Tracker (Flow Board) — MDItek Clean Rewrite
 *
 * Design principles:
 *  - No tracker rows pre-created on page load (causes duplicate/lock issues)
 *  - Every calendar appointment always renders a row
 *  - Tracker rows created on-demand via AJAX when status button first clicked
 *  - bpopup() works for both existing and new tracker rows
 *  - Zero PHP warnings: all array accesses use ?? defaults
 *  - strtotime() always checked for false, never compared to ''
 *  - xlt() only in HTML context; xlj() in JS strings
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   GNU General Public License 3
 */

require_once "../globals.php";
require_once "$srcdir/patient.inc.php";
require_once "$srcdir/options.inc.php";
require_once "$srcdir/patient_tracker.inc.php";
require_once "$srcdir/user.inc.php";
require_once "$srcdir/MedEx/API.php";

date_default_timezone_set($GLOBALS['gbl_timezone'] ?? 'America/New_York');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// ── CSRF gate ────────────────────────────────────────────────────────────────
if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
}

// ── Sticky user preferences ──────────────────────────────────────────────────
$uspfx                     = substr(__FILE__, strlen($webserver_root)) . '.';
$setting_new_window        = prevSetting($uspfx, 'setting_new_window',        'setting_new_window',        ' ');
$setting_bootstrap_submenu = prevSetting('',     'setting_bootstrap_submenu', 'setting_bootstrap_submenu', ' ');
$setting_selectors         = prevSetting($uspfx, 'setting_selectors',         'setting_selectors',         'block');
$form_apptcat              = prevSetting($uspfx, 'form_apptcat',              'form_apptcat',              '');
$form_apptstatus           = prevSetting($uspfx, 'form_apptstatus',           'form_apptstatus',           '');
$facility                  = prevSetting($uspfx, 'form_facility',             'form_facility',             '');
$provider                  = prevSetting($uspfx, 'form_provider',             'form_provider',             '');

// Ajax-only sticky-setting saves — respond then exit
if (
    ($_POST['setting_new_window']        ?? false) ||
    ($_POST['setting_bootstrap_submenu'] ?? false) ||
    ($_POST['setting_selectors']         ?? false)
) {
    exit();
}

// MedEx callback save
if (($_POST['saveCALLback'] ?? '') === 'Save') {
    sqlQuery(
        "INSERT INTO medex_outgoing
           (msg_pc_eid, msg_pid, campaign_uid, msg_type, msg_reply, msg_extra_text)
         VALUES (?, ?, ?, 'NOTES', 'CALLED', ?)",
        [
            (int)($_POST['pc_eid']       ?? 0),
            (int)($_POST['pc_pid']       ?? 0),
            $_POST['campaign_uid']       ?? '',
            $_POST['txtCALLback']        ?? '',
        ]
    );
}

// ── AJAX: inline status update — creates tracker if needed, then sets status ──
// Schema: patient_tracker (id, date, apptdate, appttime, eid, pid, original_user, encounter, lastseq)
//         patient_tracker_element (pt_tracker_id, start_datetime, room, status, seq varchar, user)
if (!empty($_POST['flb_update_status'])) {
    header('Content-Type: application/json');

    $u_eid    = (int)($_POST['appt_eid']  ?? 0);
    $u_pid    = (int)($_POST['appt_pid']  ?? 0);
    $u_enc    = (int)($_POST['appt_enc']  ?? 0);
    $u_date   = preg_replace('/[^0-9\-]/', '', $_POST['appt_date'] ?? '');
    $u_time   = preg_replace('/[^0-9:]/',  '', $_POST['appt_time'] ?? '');
    $u_status = trim($_POST['new_status'] ?? '-');
    $u_room   = trim($_POST['new_room']   ?? '');
    $u_user   = $_SESSION['authUser'] ?? '';
    if (preg_match('/^\d{2}:\d{2}$/', $u_time)) { $u_time .= ':00'; }

    // Validate status value exists in list
    $vcheck = sqlQuery("SELECT option_id FROM list_options WHERE list_id='apptstat' AND option_id=? AND activity=1 LIMIT 1", [$u_status]);
    if (empty($vcheck['option_id'])) { $u_status = '-'; }

    // Find or create the patient_tracker header row
    $tid = 0; $last_seq = 0;
    if ($u_eid) {
        $row = sqlQuery("SELECT id, lastseq FROM patient_tracker WHERE eid=? LIMIT 1", [$u_eid]);
        if (!empty($row['id'])) { $tid = (int)$row['id']; $last_seq = (int)$row['lastseq']; }
    }
    if (!$tid && $u_pid && $u_date) {
        $row = sqlQuery(
            "SELECT id, lastseq FROM patient_tracker WHERE pid=? AND apptdate=? AND (appttime=? OR appttime=?) ORDER BY id DESC LIMIT 1",
            [$u_pid, $u_date, $u_time, substr($u_time, 0, 5)]
        );
        if (!empty($row['id'])) { $tid = (int)$row['id']; $last_seq = (int)$row['lastseq']; }
    }
    if (!$tid) {
        $tid = (int)sqlInsert(
            "INSERT INTO patient_tracker (date, apptdate, appttime, eid, pid, original_user, encounter, lastseq) VALUES (NOW(), ?, ?, ?, ?, ?, ?, '1')",
            [$u_date, $u_time, $u_eid, $u_pid, $u_user, $u_enc]
        );
        $last_seq = 0;
    }
    if (!$tid) { echo json_encode(['ok' => false, 'error' => 'Could not create tracker']); exit; }

    // Next seq (stored as varchar)
    $seq_row  = sqlQuery("SELECT MAX(CAST(seq AS UNSIGNED)) AS ms FROM patient_tracker_element WHERE pt_tracker_id=?", [$tid]);
    $next_seq = (string)((int)($seq_row['ms'] ?? 0) + 1);

    // Insert new element row
    sqlInsert(
        "INSERT INTO patient_tracker_element (pt_tracker_id, start_datetime, room, status, seq, user) VALUES (?, NOW(), ?, ?, ?, ?)",
        [$tid, $u_room, $u_status, $next_seq, $u_user]
    );

    // Update lastseq on header
    sqlQuery("UPDATE patient_tracker SET lastseq=? WHERE id=?", [$next_seq, $tid]);

    // Sync calendar status
    if ($u_eid) {
        sqlQuery("UPDATE openemr_postcalendar_events SET pc_apptstatus=? WHERE pc_eid=?", [$u_status, $u_eid]);
    }

    // Return fresh times and colour
    $arrive  = collect_checkin($tid)  ?: '';
    $end     = collect_checkout($tid) ?: '';
    $colinfo = collectApptStatusSettings($u_status);
    $bgcolor = $colinfo['color'] ?? '';

    echo json_encode([
        'ok'         => true,
        'tracker_id' => $tid,
        'arrive'     => ($arrive && strtotime($arrive) !== false) ? oeFormatTime($arrive) : '',
        'checkout'   => ($end    && strtotime($end)    !== false) ? oeFormatTime(substr($end, 11)) : '',
        'color'      => $bgcolor,
    ]);
    exit;
}

// ── Date range ───────────────────────────────────────────────────────────────
if (!$GLOBALS['ptkr_date_range']) {
    $from_date = date('Y-m-d');
} elseif (!is_null($_REQUEST['form_from_date'] ?? null)) {
    $from_date = DateToYYYYMMDD($_REQUEST['form_from_date']);
} elseif (($GLOBALS['ptkr_start_date'] ?? '') === 'D0') {
    $from_date = date('Y-m-d');
} elseif (($GLOBALS['ptkr_start_date'] ?? '') === 'B0') {
    $dow  = (int)date('w');
    $fday = (int)($GLOBALS['first_day_week'] ?? 0);
    if ($dow === $fday) {
        $from_date = date('Y-m-d');
    } elseif ($fday === 0) {
        $from_date = date('Y-m-d', strtotime('last sunday'));
    } elseif ($fday === 1) {
        $from_date = date('Y-m-d', strtotime('last monday'));
    } else {
        $from_date = date('Y-m-d', strtotime('last saturday'));
    }
} else {
    $from_date = date('Y-m-d');
}

if ($GLOBALS['ptkr_date_range']) {
    $ptkr_end = $GLOBALS['ptkr_end_date'] ?? 'D1';
    $ptype    = substr($ptkr_end, 0, 1);
    $pval     = (int)substr($ptkr_end, 1, 1);
    if ($ptype === 'Y') {
        $ptkr_future = mktime(0, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y') + $pval);
    } elseif ($ptype === 'M') {
        $ptkr_future = mktime(0, 0, 0, (int)date('m') + $pval, (int)date('d'), (int)date('Y'));
    } else {
        $ptkr_future = mktime(0, 0, 0, (int)date('m'), (int)date('d') + $pval, (int)date('Y'));
    }
    $to_date = date('Y-m-d', $ptkr_future);
    if (!is_null($_REQUEST['form_to_date'] ?? null)) {
        $to_date = DateToYYYYMMDD($_REQUEST['form_to_date']);
    }
} else {
    $to_date = date('Y-m-d');
}

$form_patient_name = $_POST['form_patient_name'] ?? null;
$form_patient_id   = $_POST['form_patient_id']   ?? null;

// ── Appointment status list ──────────────────────────────────────────────────
$statuses_list = [];
$lres = sqlStatement(
    "SELECT option_id, title FROM list_options WHERE list_id = ? AND activity = 1 ORDER BY seq",
    ['apptstat']
);
while ($lrow = sqlFetchArray($lres)) {
    $t = $lrow['title'];
    if (isset($t[1]) && $t[1] === ' ') {
        $parts = explode(' ', $t, 2);
        $t = $parts[1] ?? $t;
    }
    $statuses_list[$lrow['option_id']] = $t;
}

// ── MedEx ────────────────────────────────────────────────────────────────────
$icons          = [];
$logged_in      = false;
$current_events = '';
$MedEx          = null;
if ($GLOBALS['medex_enable'] == '1') {
    $iconed = sqlStatement("SELECT * FROM medex_icons");
    while ($icon = sqlFetchArray($iconed)) {
        $icons[$icon['msg_type']][$icon['msg_status']]['html'] = $icon['i_html'];
    }
    $MedEx   = new MedExApi\MedEx('MedExBank.com');
    $prefs   = sqlFetchArray(sqlStatement("SELECT * FROM medex_prefs LIMIT 1")) ?: [];
    $results = json_decode($prefs['status'] ?? '{}', true) ?: [];
    $logged_in      = $results;
    $current_events = !empty($logged_in['token']) ? xlt('On-line') : xlt('Currently off-line');
}

// ════════════════════════════════════════════════════════════════════════════
// SHELL PAGE — rendered on initial load only (not AJAX table refresh)
// ════════════════════════════════════════════════════════════════════════════
if (!($_REQUEST['flb_table'] ?? null)) {
?><!doctype html>
<html>
<head>
<meta charset="utf-8"/>
<?php Header::setupHeader(['datetime-picker', 'opener']); ?>
<title><?php echo xlt('Flow Board'); ?></title>
<script><?php require_once "$srcdir/restoreSession.php"; ?></script>
<?php if (($_SESSION['language_direction'] ?? '') === 'rtl') { ?>
<link rel="stylesheet" href="<?php echo $GLOBALS['themes_static_relative']; ?>/misc/rtl_bootstrap_navbar.css"/>
<?php } else { ?>
<link rel="stylesheet" href="<?php echo $GLOBALS['themes_static_relative']; ?>/misc/bootstrap_navbar.css"/>
<?php } ?>
<script src="<?php echo $GLOBALS['web_root']; ?>/interface/main/messages/js/reminder_appts.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<style>
:root {
    --navy:      #1e0a3c; --navy-mid: #2d1260; --navy-light: #e8e0f5;
    --navy-pale: #f5f2fc; --gold:     #9588e8; --white:      #ffffff;
    --bg:        #f2eff9; --border:   #d8d0ed; --text:       #1a1028;
    --text-mid:  #4a3f60; --text-muted:#8a7aa0; --r: 10px;   --rs: 7px;
}
*{font-family:'Montserrat',sans-serif!important;box-sizing:border-box;}
body{background:var(--bg)!important;color:var(--text)!important;margin:0;padding:0;}
a{text-decoration:none;}
.mdi-page-header{background:var(--navy);color:#fff;padding:11px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:3px solid var(--gold);flex-wrap:wrap;gap:8px;}
.mdi-page-title{font-size:15px;font-weight:700;margin:0;display:flex;align-items:center;gap:9px;}
#flb_selectors{background:var(--white);border-bottom:1px solid var(--border);padding:14px 20px;}
.mdi-filter-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:10px;align-items:end;}
.mdi-filter-group{display:flex;flex-direction:column;gap:4px;}
.mdi-filter-label{font-size:9px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted);}
.mdi-filter-grid select,.mdi-filter-grid input[type="text"]{width:100%;height:32px;padding:0 9px;border:1.5px solid var(--border);border-radius:var(--rs);font-size:11px;color:var(--text);background:var(--white);outline:none;}
.mdi-filter-grid select:focus,.mdi-filter-grid input:focus{border-color:var(--navy);}
.mdi-filter-btn{width:100%;height:32px;background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff;border:none;border-radius:var(--rs);font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;}
.mdi-filter-btn:hover{background:var(--navy-mid);}
.mdi-summary-bar{background:var(--white);border-bottom:1px solid var(--border);padding:8px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;}
.mdi-status-pills{display:flex;flex-wrap:wrap;gap:5px;align-items:center;}
.mdi-status-pill{display:inline-flex;align-items:center;gap:5px;background:var(--navy-pale);border:1px solid var(--border);border-radius:20px;padding:3px 8px 3px 10px;font-size:10px;font-weight:600;color:var(--text-mid);cursor:pointer;user-select:none;transition:background .15s,border-color .15s,color .15s;}
.mdi-status-pill:hover{background:var(--navy-light);border-color:var(--navy);color:var(--navy);}
.mdi-status-pill.mdi-pill-active{background:var(--navy);border-color:var(--navy);color:#fff;}
.mdi-status-pill.mdi-pill-active .mdi-pill-num{background:rgba(255,255,255,.25);}
.mdi-status-pill .mdi-pill-num{background:var(--navy);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;}
.mdi-action-btns{display:flex;align-items:center;gap:5px;flex-wrap:wrap;}
.mdi-btn{height:28px;padding:0 11px;border-radius:var(--rs);font-size:10px;font-weight:600;border:1.5px solid var(--border);background:var(--white);color:var(--text-mid);cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:all .15s;}
.mdi-btn:hover{border-color:var(--navy);color:var(--navy);background:var(--navy-pale);}
.mdi-btn-icon{background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:15px;padding:2px 5px;transition:color .15s;}
.mdi-btn-icon:hover{color:var(--navy);}
#collapseSetting{background:var(--navy-pale);border-top:1px solid var(--border);padding:10px 20px;font-size:11px;color:var(--text-mid);}
#loader{display:none;flex-direction:column;align-items:center;justify-content:center;padding:60px 20px;gap:14px;color:var(--text-muted);background:var(--bg);}
#loader.visible{display:flex;}
#loader .spinner{width:32px;height:32px;border:3px solid var(--navy-light);border-top-color:var(--navy);border-radius:50%;animation:spin .7s linear infinite;}
@keyframes spin{to{transform:rotate(360deg);}}
#loader p{font-size:13px;font-weight:500;margin:0;}
#flb_table{padding:16px 20px 28px;background:var(--bg);}
.mdi-table-wrap{overflow-x:auto;border-radius:var(--r);box-shadow:0 2px 10px rgba(30,10,60,.1);border:1px solid var(--border);}
table.mdi-table{width:100%;border-collapse:collapse;background:var(--white);table-layout:auto;}
table.mdi-table thead tr{background:var(--navy);}
table.mdi-table thead td{padding:11px 14px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:rgba(255,255,255,.75);white-space:nowrap;border:none;border-right:1px solid rgba(255,255,255,.07);vertical-align:middle;}
table.mdi-table thead td:last-child{border-right:none;}
table.mdi-table thead td:first-child{border-left:4px solid var(--gold);color:rgba(255,255,255,.95);}
table.mdi-table tbody tr{border-bottom:1px solid var(--border);border-left:4px solid transparent;transition:background .1s;}
table.mdi-table tbody tr:last-child{border-bottom:none;}
table.mdi-table tbody tr:nth-child(odd){background:var(--white);}
table.mdi-table tbody tr:nth-child(even){background:var(--navy-pale);}
table.mdi-table tbody tr:hover{background:var(--navy-light);}
table.mdi-table tbody td{padding:9px 14px;font-size:11px;color:var(--text);border:none;border-right:1px solid var(--border);vertical-align:middle;white-space:nowrap;}
table.mdi-table tbody td:last-child{border-right:none;}
table.mdi-table tbody td a{color:var(--navy-mid);font-weight:600;text-decoration:none;}
table.mdi-table tbody td a:hover{color:var(--navy);text-decoration:underline;}
.mdi-status-btn{display:inline-flex;align-items:center;padding:3px 11px;border-radius:12px;font-size:10px;font-weight:600;border:1.5px solid var(--border);background:var(--navy-pale);color:var(--navy-mid);cursor:pointer;white-space:nowrap;transition:background .15s,border-color .15s;text-decoration:none;}
.mdi-status-btn:hover{background:var(--navy-light);border-color:var(--navy);color:var(--navy);}
.mdi-status-btn.creating{opacity:.6;cursor:wait;}
/* ── Status + Room inline card ── */
.mdi-status-cell { padding: 7px 12px !important; }
.mdi-status-card {
    display: flex; flex-direction: column; gap: 5px;
    min-width: 140px;
}
/* Status select */
.mdi-status-select {
    appearance: none; -webkit-appearance: none;
    width: 100%; height: 30px;
    padding: 0 26px 0 11px;
    border-radius: 8px;
    font-size: 10.5px; font-weight: 600;
    cursor: pointer; outline: none;
    border: 1.5px solid var(--border);
    background: var(--navy-pale)
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23ffffff'/%3E%3C/svg%3E")
        no-repeat right 8px center;
    background-size: 8px 5px;
    color: var(--navy-mid);
    transition: box-shadow .15s, border-color .15s;
}
.mdi-status-select:hover  { border-color: var(--navy); }
.mdi-status-select:focus  { outline: none; box-shadow: 0 0 0 3px rgba(92,33,182,.18); }
.mdi-status-select.mdi-saving { opacity: .55; cursor: wait; }
.mdi-status-select.mdi-saved  { box-shadow: 0 0 0 2px #16a34a; }
.mdi-status-select.mdi-error  { box-shadow: 0 0 0 2px #dc2626; }
/* Room select */
.mdi-room-select {
    appearance: none; -webkit-appearance: none;
    width: 100%; height: 24px;
    padding: 0 22px 0 8px;
    border-radius: 6px;
    font-size: 9.5px; font-weight: 500;
    cursor: pointer; outline: none;
    border: 1px solid var(--border);
    background: var(--white)
        url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='5'%3E%3Cpath d='M0 0l4 5 4-5z' fill='%238a7aa0'/%3E%3C/svg%3E")
        no-repeat right 6px center;
    background-size: 6px 4px;
    color: var(--text-muted);
    transition: border-color .15s;
}
.mdi-room-select:hover { border-color: var(--navy-mid); color: var(--text); }
.mdi-room-select:focus { outline: none; border-color: var(--navy); box-shadow: 0 0 0 2px rgba(92,33,182,.12); }
.mdi-room-select.mdi-saving { opacity: .55; cursor: wait; }
.mdi-room-select.mdi-saved  { border-color: #16a34a; }
/* Save indicator dot */
.mdi-save-dot {
    width: 5px; height: 5px; border-radius: 50%;
    background: #16a34a; opacity: 0;
    transition: opacity .3s;
    align-self: center; flex-shrink: 0;
    display: none;
}
.mdi-save-dot.visible { opacity: 1; display: block; }
td.mdi-blink{animation:blink 1s step-start infinite;font-weight:700;color:#9b1c1c;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.12}}
.drug_screen_completed{accent-color:var(--navy);width:15px;height:15px;cursor:pointer;}

/* ── SMS Status cell ── */
.mdi-sms-cell { white-space: nowrap; }
.mdi-sms-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 10.5px; font-weight: 600;
}
.mdi-sms-time { font-size: 10px; color: var(--text-muted); display: block; margin-top: 2px; }
.mdi-sms-icon { width: 22px; height: 22px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mdi-sms-delivered .mdi-sms-icon { background: #dcfce7; color: #16a34a; }
.mdi-sms-delivered .mdi-sms-label { color: #16a34a; }
.mdi-sms-sent .mdi-sms-icon { background: #dbeafe; color: #2563eb; }
.mdi-sms-sent .mdi-sms-label { color: #2563eb; }
.mdi-sms-pending .mdi-sms-icon { background: #fef9c3; color: #ca8a04; }
.mdi-sms-pending .mdi-sms-label { color: #ca8a04; }
.mdi-sms-failed .mdi-sms-icon { background: #fee2e2; color: #dc2626; }
.mdi-sms-failed .mdi-sms-label { color: #dc2626; }
@media print{.mdi-page-header,#flb_selectors,.mdi-summary-bar{display:none!important;}body{background:#fff!important;}table.mdi-table tbody tr{background:#fff!important;border-left:none!important;}table.mdi-table tbody tr:nth-child(even){background:#f5f2fc!important;}.mdi-table-wrap{box-shadow:none!important;border:1px solid #ccc!important;}}
</style>
</head>
<body>
<?php
if (($GLOBALS['medex_enable'] == '1') && empty($_REQUEST['nomenu']) && $MedEx) {
    $logged_in = $MedEx->login();
    $MedEx->display->navigation($logged_in);
}
?>
<div class="mdi-page-header" name="kiosk_hide">
    <div class="mdi-page-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="color:var(--gold)"><path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1s-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
        <?php echo xlt('Flow Board'); ?>
    </div>
    <div style="font-size:11px;color:rgba(255,255,255,.6);">
        <?php echo text(oeFormatShortDate($from_date));
        if ($GLOBALS['ptkr_date_range']) { echo ' &ndash; ' . text(oeFormatShortDate($to_date)); } ?>
    </div>
</div>

<div id="flb_selectors" style="display:<?php echo attr($setting_selectors); ?>;" name="kiosk_hide">
    <form name="flb" id="flb" method="post">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>"/>
        <input type="hidden" id="kiosk" name="kiosk" value="<?php echo attr($_REQUEST['kiosk'] ?? ''); ?>"/>
        <div class="mdi-filter-grid">

            <div class="mdi-filter-group">
                <label class="mdi-filter-label"><?php echo xlt('Visit Category'); ?></label>
                <select id="form_apptcat" name="form_apptcat" onchange="refineMe();">
                    <option value=""><?php echo xlt('All Categories'); ?></option>
                    <?php
                    $cats = fetchAppointmentCategories();
                    while ($cat = sqlFetchArray($cats)) {
                        $sel = ($cat['id'] == ($_POST['form_apptcat'] ?? '')) ? ' selected' : '';
                        echo '<option value="' . attr($cat['id']) . '"' . $sel . '>' . xlt($cat['category']) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="mdi-filter-group">
                <label class="mdi-filter-label"><?php echo xlt('Status'); ?></label>
                <select id="form_apptstatus" name="form_apptstatus" onchange="refineMe();">
                    <option value=""><?php echo xlt('All Statuses'); ?></option>
                    <?php
                    $sstmt = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id='apptstat' AND activity=1 ORDER BY seq");
                    while ($ss = sqlFetchArray($sstmt)) {
                        $sel = ($ss['option_id'] == ($_POST['form_apptstatus'] ?? '')) ? ' selected' : '';
                        echo '<option value="' . attr($ss['option_id']) . '"' . $sel . '>' . xlt($ss['title']) . '</option>';
                    }
                    ?>
                </select>
            </div>

            <div class="mdi-filter-group">
                <label class="mdi-filter-label"><?php echo xlt('Facility'); ?></label>
                <?php
                $fac_opts = ''; $count_facs = 0;
                $fq = sqlStatement("SELECT id, name FROM facility ORDER BY name");
                while ($fr = sqlFetchArray($fq)) {
                    $sel = ($fr['id'] == ($_POST['form_facility'] ?? '')) ? ' selected' : '';
                    $fac_opts .= '<option value="' . attr($fr['id']) . '"' . $sel . '>' . text($fr['name']) . '</option>';
                    $count_facs++;
                }
                ?>
                <select id="form_facility" name="form_facility"
                    <?php if ($count_facs < 2) { echo 'disabled'; } ?>
                    onchange="refineMe();">
                    <option value=""><?php echo xlt('All Facilities'); ?></option>
                    <?php echo $fac_opts; ?>
                </select>
            </div>

            <div class="mdi-filter-group">
                <label class="mdi-filter-label"><?php echo xlt('Provider'); ?></label>
                <?php
                $prov_opts = ''; $count_provs = 0;
                $pq = sqlStatement("SELECT id, lname, fname FROM users WHERE authorized=1 AND active=1 AND username>'' ORDER BY lname, fname");
                while ($pr = sqlFetchArray($pq)) {
                    $sel = ($pr['id'] == ($_POST['form_provider'] ?? '')) ? ' selected' : '';
                    $prov_opts .= '<option value="' . attr($pr['id']) . '"' . $sel . '>' . text($pr['lname'] . ', ' . $pr['fname']) . '</option>';
                    $count_provs++;
                }
                ?>
                <select id="form_provider" name="form_provider"
                    <?php if ($count_provs < 2) { echo 'disabled'; } ?>
                    onchange="refineMe();">
                    <option value=""><?php echo xlt('All Providers'); ?></option>
                    <?php echo $prov_opts; ?>
                </select>
            </div>

            <div class="mdi-filter-group">
                <label class="mdi-filter-label"><?php echo xlt('Patient Name'); ?></label>
                <input type="text" id="form_patient_name" name="form_patient_name"
                    placeholder="<?php echo xla('Search name...'); ?>"
                    value="<?php echo $form_patient_name ? attr($form_patient_name) : ''; ?>"
                    onkeyup="refineMe();"/>
            </div>

            <div class="mdi-filter-group">
                <label class="mdi-filter-label"><?php echo xlt('Patient ID'); ?></label>
                <input type="text" id="form_patient_id" name="form_patient_id"
                    placeholder="<?php echo xla('Search ID...'); ?>"
                    value="<?php echo $form_patient_id ? attr($form_patient_id) : ''; ?>"
                    onkeyup="refineMe();"/>
            </div>

            <?php $dstyle = ($GLOBALS['ptkr_date_range'] == '1') ? '' : 'display:none;'; ?>
            <div class="mdi-filter-group" style="<?php echo $dstyle; ?>">
                <label class="mdi-filter-label"><?php echo xlt('From'); ?></label>
                <input type="text" id="form_from_date" name="form_from_date" class="datepicker"
                    value="<?php echo attr(oeFormatShortDate($from_date)); ?>"/>
            </div>
            <div class="mdi-filter-group" style="<?php echo $dstyle; ?>">
                <label class="mdi-filter-label"><?php echo xlt('To'); ?></label>
                <input type="text" id="form_to_date" name="form_to_date" class="datepicker"
                    value="<?php echo attr(oeFormatShortDate($to_date)); ?>"/>
            </div>

            <div class="mdi-filter-group">
                <label class="mdi-filter-label">&nbsp;</label>
                <button id="filter_submit" class="mdi-filter-btn" type="button">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/></svg>
                    <?php echo xlt('Filter'); ?>
                </button>
            </div>

            <?php if ($GLOBALS['medex_enable'] == '1') { ?>
            <div class="mdi-filter-group" style="font-size:11px;padding-top:18px;">
                <b>MedEx:</b>
                <a href="https://medexbank.com/cart/upload/index.php?route=information/campaigns&g=rem" target="_medex"><?php echo $current_events; ?></a>
            </div>
            <?php } ?>
        </div>
    </form>
</div>

<div id="loader"><div class="spinner"></div><p><?php echo xlt('Loading data'); ?>...</p></div>
<div id="flb_table" name="flb_table"></div>

<form name="fnew" method="post" target="_blank"
      action="../main/main_screen.php?auth=login&site=<?php echo attr_url($_SESSION['site_id']); ?>">
    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>"/>
    <input type="hidden" name="patientID"   value="0"/>
    <input type="hidden" name="encounterID" value="0"/>
</form>

<?php mdiLocalJS(); ?>
</body>
</html>
<?php
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// TABLE FRAGMENT — returned by AJAX, no <html>/<head>/<body>
// ════════════════════════════════════════════════════════════════════════════

$appointments = fetch_Patient_Tracker_Events(
    $from_date, $to_date, '', '', '', '',
    $form_patient_name, $form_patient_id
);
$appointments = sortAppointments($appointments, 'date', 'time');

// Populate pt_tracker_id from existing rows — read-only, no inserts here
foreach ($appointments as &$appt) {
    if (!empty($appt['pt_tracker_id'])) { continue; } // set by LEFT JOIN alias

    $pid_  = (int)($appt['pid']  ?? $appt['pc_pid']       ?? 0);
    $eid_  = (int)($appt['eid']  ?? $appt['pc_eid']       ?? 0);
    $time_ = $appt['appttime']   ?? $appt['pc_startTime'] ?? '';
    $date_ = $appt['pc_eventDate'] ?? '';
    if (!$pid_ || !$date_) { continue; }
    $time_norm = preg_match('/^\d{2}:\d{2}$/', $time_) ? $time_ . ':00' : $time_;

    $row = $eid_ ? sqlQuery("SELECT id FROM patient_tracker WHERE eid = ? LIMIT 1", [$eid_]) : null;
    if (empty($row['id'])) {
        $row = sqlQuery(
            "SELECT id FROM patient_tracker
              WHERE pid = ? AND apptdate = ? AND (appttime = ? OR appttime = ?)
              ORDER BY id DESC LIMIT 1",
            [$pid_, $date_, $time_, $time_norm]
        );
    }
    if (!empty($row['id'])) {
        $appt['pt_tracker_id'] = (int)$row['id'];
    }
}
unset($appt);

$appointments_status = getApptStatus($appointments);
$chk_prov = [];
foreach ($appointments as $apt) {
    $chk_prov[$apt['uprovider_id'] ?? ''] = ($apt['ulname'] ?? '') . ', ' . ($apt['ufname'] ?? '') . ' ' . ($apt['umname'] ?? '');
}

// Pre-fetch SMS status from notification_log using exact schema columns:
// type=SMS, pc_eventDate=today, keyed by pc_eid then pid
// Columns used: iLogId, pid, pc_eid, type, status, sent_at, dSentDateTime, pc_eventDate
$sms_by_eid = [];
$sms_by_pid = [];

// normalize date
$from_date = empty($from_date)
    ? date('Y-m-d')
    : date('Y-m-d', strtotime($from_date));

// escape safely using OpenEMR helper
$from_date_sql = add_escape_custom($from_date);

$notif_res = sqlStatement(
    "SELECT *
     FROM notification_log
     WHERE sms_gateway_type = 'RCSMS'
     ORDER BY dSentDateTime DESC"
);
while ($nr = sqlFetchArray($notif_res)) {
    $eid_k = (int)($nr['pc_eid'] ?? 0);
    $pid_k = (int)($nr['pid']    ?? 0);
    // Use sent_at if available, else dSentDateTime
    $ts_field = !empty($nr['sent_at']) ? $nr['sent_at'] : ($nr['dSentDateTime'] ?? '');
    $mapped = [
        'status'     => $nr['status']  ?? 'pending',
        'created_at' => $ts_field,
    ];
    // Store most-recent record per eid/pid (query ordered DESC so first hit wins)
    if ($eid_k && !isset($sms_by_eid[$eid_k])) { $sms_by_eid[$eid_k] = $mapped; }
    if ($pid_k && !isset($sms_by_pid[$pid_k])) { $sms_by_pid[$pid_k] = $mapped; }
}

// Pre-build room options once — reused in every row
$room_opts_html = '<option value="">' . xlt('— Room —') . '</option>';
$has_rooms = false;
$rq = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id='patient_flow_board_rooms' AND activity=1 ORDER BY seq");
while ($ro = sqlFetchArray($rq)) {
    $has_rooms = true;
    $room_opts_html .= '<option value="' . attr($ro['option_id']) . '">' . xlt($ro['title']) . '</option>';
}

// Status map: normalise all known status codes to the 4 flow board values
$status_map     = ['-'=>'-','>'=>'>','@'=>'@','<'=>'<','SMS'=>'-','CONFIRMED'=>'-','ARRIVED'=>'>','INROOM'=>'@','CHECKEDOUT'=>'<'];
$valid_statuses = ['-','>','@','<'];

?>
<div class="mdi-summary-bar" name="kiosk_hide">
    <div class="mdi-status-pills" id="status_summary">
        <?php
        $total = $appointments_status['count_all'] ?? 0;
        echo "<div class='mdi-status-pill total mdi-pill-active'><span>" . xlt('Total') . "</span><span class='mdi-pill-num'>" . text($total) . "</span></div>";
        unset($appointments_status['count_all']);
        foreach ($appointments_status as $sym => $cnt) {
            $label = xl_list_label($statuses_list[$sym] ?? $sym);
            echo "<div class='mdi-status-pill' data-status='" . attr($sym) . "'><span>" . text($label) . "</span><span class='mdi-pill-num'>" . text($cnt) . "</span></div>";
        }
        ?>
    </div>
    <div class="mdi-action-btns">
        <button class="mdi-btn-icon" id="flb_caret" onclick="toggleSelectors();" title="<?php echo xla('Show/Hide Filters'); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M3 17v2h6v-2H3zM3 5v2h10V5H3zm10 16v-2h8v-2h-8v-2h-2v6h2zM7 9v2H3v2h4v2h2V9H7zm14 4v-2H11v2h10zm-6-4h2V7h4V5h-4V3h-2v6z"/></svg>
        </button>
        <a class="mdi-btn" id="refreshme">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6a5.87 5.87 0 0 1-.72 2.84l1.46 1.46A7.93 7.93 0 0 0 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6a5.87 5.87 0 0 1 .72-2.84L5.26 7.7A7.93 7.93 0 0 0 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg>
            <?php echo xlt('Refresh'); ?>
        </a>
        <a class="mdi-btn" onclick="window.print();">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
            <?php echo xlt('Print'); ?>
        </a>
        <a class="mdi-btn" onclick="kiosk_FLB();">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M21 3H3c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h5v2h8v-2h5c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 14H3V5h18v12z"/></svg>
            <?php echo xlt('Kiosk'); ?>
        </a>
        <a class="mdi-btn" data-toggle="collapse" href="#collapseSetting">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 0 0 .12-.61l-1.92-3.32a.49.49 0 0 0-.59-.22l-2.39.96a7.02 7.02 0 0 0-1.62-.94l-.36-2.54A.484.484 0 0 0 14 2h-4c-.25 0-.46.18-.49.42l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96a.476.476 0 0 0-.59.22L2.74 8.87a.47.47 0 0 0 .12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58a.49.49 0 0 0-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.42.49.42h4c.25 0 .46-.18.49-.42l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32a.47.47 0 0 0-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
        </a>
    </div>
</div>
<div class="collapse" id="collapseSetting" name="kiosk_hide">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px 20px;font-size:11px;">
        <input type="checkbox" id="setting_new_window" name="setting_new_window"
            value="<?php echo attr($setting_new_window); ?>"
            <?php echo ($setting_new_window === 'checked') ? 'checked' : ''; ?>/>
        <?php echo xlt('Open Patient in New Window'); ?>
    </label>
</div>

<div class="mdi-table-wrap">
<table class="mdi-table">
<thead><tr>
    <?php if ($GLOBALS['ptkr_show_pid'])       { echo '<td name="kiosk_hide">' . xlt('PID') . '</td>'; } ?>
    <td><?php echo xlt('Patient'); ?></td>
    <?php if ($GLOBALS['ptkr_visit_reason']=='1') { echo '<td name="kiosk_hide">' . xlt('Reason') . '</td>'; } ?>
    <?php if ($GLOBALS['ptkr_show_encounter'])    { echo '<td name="kiosk_hide">' . xlt('Encounter') . '</td>'; } ?>
    <?php if ($GLOBALS['ptkr_date_range']=='1')   { echo '<td name="kiosk_hide">' . xlt('Appt Date') . '</td>'; } ?>
    <td><?php echo xlt('Appt Time'); ?></td>
    <td><?php echo xlt('Arrive Time'); ?></td>
    <td><?php echo xlt('Current Status'); ?></td>
    <td><?php echo xlt('SMS Status'); ?></td>
    <td><?php echo xlt('Time in Status'); ?></td>
    <td name="kiosk_hide"><?php echo xlt('Visit Type'); ?></td>
    <?php if (count($chk_prov) > 1) { echo '<td>' . xlt('Provider') . '</td>'; } ?>
    <td><?php echo xlt('Total Time'); ?></td>
    <td><?php echo xlt('Check Out Time'); ?></td>
    <?php if ($GLOBALS['ptkr_show_staff']=='1')   { echo '<td name="kiosk_hide">' . xlt('Updated By') . '</td>'; } ?>
    <?php if ($GLOBALS['drug_screen']) {
        echo '<td name="kiosk_hide">' . xlt('Random Drug Screen') . '</td>';
        echo '<td name="kiosk_hide">' . xlt('Drug Screen Completed') . '</td>';
    } ?>
</tr></thead>
<tbody id="flb_tbody">
<?php
foreach ($appointments as $appointment) {
    $appt_pid  = (int)((!empty($appointment['pid'])) ? $appointment['pid'] : ($appointment['pc_pid'] ?? 0));
    $appt_eid  = (int)((!empty($appointment['eid'])) ? $appointment['eid'] : ($appointment['pc_eid'] ?? 0));
    $appt_time = (!empty($appointment['appttime'])) ? $appointment['appttime'] : ($appointment['pc_startTime'] ?? '');
    $appt_date = $appointment['pc_eventDate'] ?? '';
    $appt_enc  = $appointment['encounter'] ?? 0;
    $appt_room = (!empty($appointment['room'])) ? $appointment['room'] : ($appointment['pc_room'] ?? '');

    if (!$appt_pid) { continue; } // skip block slots

    $date_squash  = str_replace('-', '', $appt_date);
    $ptname       = ($appointment['lname'] ?? '') . ', ' . ($appointment['fname'] ?? '') . ' ' . ($appointment['mname'] ?? '');
    $ptname_short = substr($appointment['fname'] ?? '', 0, 1) . ' ' . substr($appointment['lname'] ?? '', 0, 1);
    $docname      = $chk_prov[$appointment['uprovider_id'] ?? ''] ?? '';

    $status_raw = $appointment['pc_apptstatus'] ?? '-';
    $status     = $status_map[$status_raw] ?? '-';
    if (!in_array($status, $valid_statuses, true)) { $status = '-'; }

    $tracker_id = (int)($appointment['pt_tracker_id'] ?? 0);

    // Arrive/checkout
    $newarrive = $tracker_id ? (collect_checkin($tracker_id)  ?: '') : '';
    $newend    = $tracker_id ? (collect_checkout($tracker_id) ?: '') : '';

    // Status colour
    $colorevents = collectApptStatusSettings($status);
    $bgcolor     = $colorevents['color']      ?? '';
    $statalert   = (int)($colorevents['time_alert'] ?? 0);
    $sc = ltrim($bgcolor, '#');
    if (strlen($sc) === 6) {
        $slum     = 0.299 * hexdec(substr($sc,0,2)) + 0.587 * hexdec(substr($sc,2,2)) + 0.114 * hexdec(substr($sc,4,2));
        $stextcol = ($slum > 155) ? '#1e0a3c' : '#ffffff';
    } else {
        $stextcol = '#1e0a3c';
    }
    $sbtnstyle = $bgcolor ? 'background:' . attr($bgcolor) . ';color:' . attr($stextcol) . ';border-color:' . attr($bgcolor) . ';' : '';

    // MedEx icons
    $icon_here  = [];
    $icon2_here = '';
    $icon_CALL  = '';
    $icon_extra = '';
    if (($GLOBALS['medex_enable'] == '1') && empty($appt_room) && $logged_in && ($setting_bootstrap_submenu !== 'hide')) {
        $myMedEx = sqlStatement("SELECT * FROM medex_outgoing WHERE msg_pc_eid=? ORDER BY medex_uid ASC", [$appt_eid]);
        while ($row = sqlFetchArray($myMedEx)) {
            $local = !empty($row['msg_extra_text']) ? attr($row['msg_extra_text']) . ' |' : '';
            $mt = $row['msg_type'];
            $mr = $row['msg_reply'];
            if ($mr === 'Other') {
                $icon_extra .= str_replace('EXTRA', attr(oeFormatShortDate($row['msg_date'])) . "\n" . xla('Patient Message') . ":\n" . attr($row['msg_extra_text']) . "\n", $icons[$mt]['EXTRA']['html'] ?? '');
                continue;
            }
            if ($mr === 'CANCELLED')   { $appointment[$mt]['stage'] = 'CANCELLED'; $icon_here[$mt] = ''; }
            elseif ($mr === 'FAILED')  { $appointment[$mt]['stage'] = 'FAILED';    $icon_here[$mt] = $icons[$mt]['FAILED']['html'] ?? ''; }
            elseif ($mr === 'CONFIRMED' || ($appointment[$mt]['stage'] ?? '') === 'CONFIRMED') { $appointment[$mt]['stage'] = 'CONFIRMED'; $icon_here[$mt] = $icons[$mt]['CONFIRMED']['html'] ?? ''; }
            elseif ($mt === 'NOTES')   { $FINAL = $icons['NOTES']['CALLED']['html'] ?? ''; $icon_CALL = str_replace('Call Back: COMPLETED', attr(oeFormatShortDate($row['msg_date'])) . ' :: ' . xla('Callback Performed') . ' | ' . xla('NOTES') . ': ' . $row['msg_extra_text'] . ' | ', $FINAL); continue; }
            elseif ($mr === 'READ'    || ($appointment[$mt]['stage'] ?? '') === 'READ')  { $appointment[$mt]['stage'] = 'READ';  $icon_here[$mt] = $icons[$mt]['READ']['html']  ?? ''; }
            elseif ($mr === 'SENT'    || ($appointment[$mt]['stage'] ?? '') === 'SENT')  { $appointment[$mt]['stage'] = 'SENT';  $icon_here[$mt] = $icons[$mt]['SENT']['html']  ?? ''; }
            else { $stage = $appointment[$mt]['stage'] ?? ''; if (!in_array($stage, ['CONFIRMED','READ','SENT','FAILED'])) { $appointment[$mt]['stage'] = 'QUEUED'; $icon_here[$mt] = $icons[$mt]['SCHEDULED']['html'] ?? ''; } }
            if ($mr === 'CALL') {
                $icon_here = [];
                $icon_CALL = "<span onclick=\"doCALLback(" . attr_js($date_squash) . ',' . attr_js($appt_eid) . ',' . attr_js($appointment['pc_cattype'] ?? '') . ")\">" . ($icons[$mt]['CALL']['html'] ?? '') . "</span>"
                    . "<span class='hidden' id='progCALLback_" . attr($appt_eid) . "' name='progCALLback_" . attr($appt_eid) . "'>"
                    . "<form id='notation_" . attr($appt_eid) . "' method='post' action='#'>"
                    . "<input type='hidden' name='csrf_token_form' value='" . attr(CsrfUtils::collectCsrfToken()) . "'/>"
                    . "<input type='hidden' name='pc_eid' value='" . attr($appt_eid) . "'/>"
                    . "<input type='hidden' name='pc_pid' value='" . attr($appt_pid) . "'/>"
                    . "<input type='hidden' name='campaign_uid' value='" . attr($row['campaign_uid'] ?? '') . "'/>"
                    . "<h4>" . xlt('Call Back Notes') . ":</h4>"
                    . "<textarea name='txtCALLback' rows='4' cols='20'></textarea>"
                    . "<input type='submit' name='saveCALLback' value='" . xla('Save') . "'/>"
                    . "</form></span>";
            } elseif ($mr === 'STOP')  { $icon2_here .= $icons[$mt]['STOP']['html']   ?? ''; }
            elseif ($mr === 'CALLED')  { $icon2_here .= $icons[$mt]['CALLED']['html'] ?? ''; }
        }
    }

    $reason_visit = ($GLOBALS['ptkr_visit_reason'] == '1') ? ($appointment['pc_hometext'] ?? '') : '';

    // Time calculations — safe strtotime checks
    $arrive_ts = ($newarrive !== '' && strtotime($newarrive) !== false) ? strtotime($newarrive) : 0;
    $end_ts    = ($newend    !== '' && strtotime($newend)    !== false) ? strtotime($newend)    : 0;
    $now_ts    = time();

    // Time in current status = from arrive to checkout (or now if still here)
    $timecheck  = ($arrive_ts > 0) ? (int)round((($end_ts > 0 ? $end_ts : $now_ts) - $arrive_ts) / 60) : 0;
    // Total time = same (arrive to checkout or now)
    $totalcheck = $timecheck;
    $still_here = ($arrive_ts > 0 && !$end_ts);
    $has_arrived = ($arrive_ts > 0);

    // Row
    echo '<tr data-apptstatus="' . attr($status) . '"'
       . ' data-apptcat="'     . attr($appointment['pc_catid']     ?? '') . '"'
       . ' data-facility="'    . attr($appointment['pc_facility']  ?? '') . '"'
       . ' data-provider="'    . attr($appointment['uprovider_id'] ?? '') . '"'
       . ' data-pid="'         . attr($appt_pid) . '"'
       . ' data-pname="'       . attr($ptname)   . '"'
       . ' class="mdi-row"'
       . ' data-statuscolor="' . attr($bgcolor)  . '">';

    if ($GLOBALS['ptkr_show_pid']) {
        echo '<td name="kiosk_hide">' . text($appt_pid) . '</td>';
    }
    ?>
    <td>
        <span name="kiosk_hide"><a href="#" onclick="return topatient(<?php echo attr_js($appt_pid); ?>,<?php echo attr_js($appt_enc); ?>)"><?php echo text($ptname); ?></a></span>
        <span name="kiosk_show" style="display:none;"><a href="#" onclick="return topatient(<?php echo attr_js($appt_pid); ?>,<?php echo attr_js($appt_enc); ?>)"><?php echo text($ptname_short); ?></a></span>
    </td>
    <?php
    if ($GLOBALS['ptkr_visit_reason'] == '1') { echo '<td name="kiosk_hide">' . text($reason_visit) . '</td>'; }
    if ($GLOBALS['ptkr_show_encounter'])       { echo '<td name="kiosk_hide">' . ($appt_enc ? text($appt_enc) : '') . '</td>'; }
    if ($GLOBALS['ptkr_date_range'] == '1')    { echo '<td name="kiosk_hide">' . text(oeFormatShortDate($appt_date)) . '</td>'; }

    echo '<td>' . text(oeFormatTime($appt_time)) . '</td>';
    echo '<td class="mdi-arrive-cell">' . ($arrive_ts ? text(oeFormatTime($newarrive)) : '') . '</td>';

    // ── Status + Room inline card ─────────────────────────────────────────────
    $sel_bg  = $bgcolor ? 'background-color:' . attr($bgcolor) . ';color:' . attr($stextcol) . ';border-color:' . attr($bgcolor) . ';background-image:url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'10\' height=\'6\'%3E%3Cpath d=\'M0 0l5 6 5-6z\' fill=\'' . ($stextcol === '#ffffff' ? '%23ffffff' : '%231e0a3c') . '\'/%3E%3C/svg%3E");' : '';
    $da      = ' data-eid="'  . attr($appt_eid)  . '"'
             . ' data-pid="'  . attr($appt_pid)  . '"'
             . ' data-enc="'  . attr($appt_enc)  . '"'
             . ' data-date="' . attr($appt_date) . '"'
             . ' data-time="' . attr($appt_time) . '"';

    echo '<td class="mdi-status-cell">';
    echo '<div class="mdi-status-card">';

    // Status select
    echo '<select class="mdi-status-select" style="' . $sel_bg . '"' . $da . ' onchange="mdiUpdateStatus(this)">';
    $stat_q = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id='apptstat' AND activity=1 ORDER BY seq");
    while ($sopt = sqlFetchArray($stat_q)) {
        $sel2 = ($sopt['option_id'] === $status) ? ' selected' : '';
        echo '<option value="' . attr($sopt['option_id']) . '"' . $sel2 . '>' . xlt($sopt['title']) . '</option>';
    }
    echo '</select>';

    // Room select (always render if rooms exist, pre-select current room)
    if ($has_rooms) {
        echo '<select class="mdi-room-select"' . $da . ' onchange="mdiUpdateRoom(this)">';
        // Re-emit options with current room pre-selected
        $rq2 = sqlStatement("SELECT option_id, title FROM list_options WHERE list_id='patient_flow_board_rooms' AND activity=1 ORDER BY seq");
        echo '<option value="">' . xlt('— Room —') . '</option>';
        while ($ro2 = sqlFetchArray($rq2)) {
            $rsel = ($ro2['option_id'] === $appt_room) ? ' selected' : '';
            echo '<option value="' . attr($ro2['option_id']) . '"' . $rsel . '>' . xlt($ro2['title']) . '</option>';
        }
        echo '</select>';
    }

    echo '</div>';
    echo '</td>';

    // ── SMS Status cell (before Time in Status per mockup) ───────────────────
    $sms_data = $sms_by_eid[$appt_eid] ?? $sms_by_pid[$appt_pid] ?? null;
    echo '<td class="mdi-sms-cell">';
    if ($sms_data) {
        $sms_raw  = strtolower($sms_data['status'] ?? '');
        $sms_time = $sms_data['created_at'] ?? '';
        $sms_ts   = ($sms_time && strtotime($sms_time) !== false) ? strtotime($sms_time) : 0;
        $sms_fmt  = $sms_ts ? oeFormatTime(date('H:i', $sms_ts)) : '';
        if (in_array($sms_raw, ['delivered', 'confirmed', 'read'])) {
            $sms_class = 'mdi-sms-delivered'; $sms_label = xl('Delivered');
            $sms_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
        } elseif (in_array($sms_raw, ['sent', 'queued', 'notification'])) {
            $sms_class = 'mdi-sms-sent'; $sms_label = xl('Sent');
            $sms_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>';
        } elseif (in_array($sms_raw, ['failed', 'undelivered', 'error'])) {
            $sms_class = 'mdi-sms-failed'; $sms_label = xl('Failed');
            $sms_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>';
        } else {
            // pending or anything else
            $sms_class = 'mdi-sms-pending'; $sms_label = xl('Pending');
            $sms_svg = '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg>';
        }
        echo '<div class="mdi-sms-badge ' . attr($sms_class) . '">'
           . '<span class="mdi-sms-icon">' . $sms_svg . '</span>'
           . '<span class="mdi-sms-label">' . text($sms_label) . '</span>'
           . '</div>';
        if ($sms_fmt) { echo '<span class="mdi-sms-time">' . text($sms_fmt) . '</span>'; }
    } else {
        echo '<span style="color:var(--text-muted);font-size:10px;">&mdash;</span>';
    }
    echo '</td>';

    // Time in status — show for any row with an arrive time
    $blink = ($statalert > 0 && $timecheck >= $statalert && $still_here) ? ' mdi-blink' : '';
    echo '<td class="' . trim($blink) . '">';
    if ($has_arrived && $timecheck >= 1) {
        echo text($timecheck . ' ' . ($timecheck >= 2 ? xl('minutes') : xl('minute')));
    } elseif (!$has_arrived && ($icon_here || $icon2_here || $icon_CALL)) {
        echo "<span style='font-size:.7rem;' onclick='return calendarpopup(" . attr_js($appt_eid) . ',' . attr_js($date_squash) . ")'>" . implode('', $icon_here) . $icon2_here . "</span> " . $icon_CALL;
    } elseif (!$has_arrived && $logged_in && $MedEx) {
        $pat = $MedEx->display->possibleModalities($appointment);
        echo "<span style='font-size:.7rem;' onclick='return calendarpopup(" . attr_js($appt_eid) . ',' . attr_js($date_squash) . ")'>" . ($pat['SMS'] ?? '') . ($pat['AVM'] ?? '') . ($pat['EMAIL'] ?? '') . '</span>';
    }
    echo '</td>';

    echo '<td name="kiosk_hide">' . xlt($appointment['pc_title'] ?? '') . '</td>';
    if (count($chk_prov) > 1) { echo '<td>' . text($docname) . '</td>'; }

    // Total time
    echo '<td>' . ($arrive_ts > 0 && $totalcheck >= 1 ? text($totalcheck . ' ' . ($totalcheck >= 2 ? xl('minutes') : xl('minute'))) : '') . '</td>';

    // Check-out time
    echo '<td class="mdi-checkout-cell">' . ($end_ts ? text(oeFormatTime(substr($newend, 11))) : '') . '</td>';

    if ($GLOBALS['ptkr_show_staff'] == '1') {
        echo '<td name="kiosk_hide">' . text($appointment['user'] ?? '') . '</td>';
    }

    if ($GLOBALS['drug_screen']) {
        $arrived  = $arrive_ts > 0;
        echo '<td name="kiosk_hide">';
        if ($arrived) { echo ($appointment['random_drug_test'] == '1') ? xlt('Yes') : xlt('No'); }
        echo '</td>';
        echo '<td name="kiosk_hide">';
        if ($arrived && $appointment['random_drug_test'] == '1') {
            $tid_a = attr($tracker_id ?: '');
            $chk   = ($appointment['drug_screen_completed'] == '1') ? 'checked' : '';
            echo $end_ts
                ? "<input type='checkbox' disabled class='drug_screen_completed' id='{$tid_a}' {$chk}/>"
                : "<input type='checkbox' class='drug_screen_completed' id='{$tid_a}' name='drug_screen_completed' {$chk}/>";
        }
        echo '</td>';
    }

    echo '</tr>';
}
?>
</tbody>
</table>
</div>
<?php
exit; // End of AJAX table fragment

// ════════════════════════════════════════════════════════════════════════════
function mdiLocalJS()
{
    global $from_date, $to_date, $GLOBALS;
    ?>
<script>
var auto_refresh = null;

// Named global so dialog.js onClosed callback can find it via window['refreshme']
function refreshme() { refreshMe(); }
window.refreshme = refreshme;

try { window.parent.$("[name='flb']").attr('allowFullscreen','true'); } catch(e){}

function toggleSelectors() {
    top.restoreSession();
    var vis  = $("#flb_selectors").css('display') !== 'none';
    var next = vis ? 'none' : 'block';
    $.post("<?php echo $GLOBALS['webroot']; ?>/interface/patient_tracker/patient_tracker.php", {
        setting_selectors: next,
        csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
    }).done(function() { $("#flb_selectors").slideToggle(); });
}

function refreshMe(fromTimer) {
    if (!fromTimer) {
        $("#flb_table").html('');
        $("#loader").addClass('visible');
    }
    var t0 = Date.now();
    top.restoreSession();
    $.post('../patient_tracker/patient_tracker.php', {
        flb_table:         '1',
        form_from_date:    $("#form_from_date").val(),
        form_to_date:      $("#form_to_date").val(),
        form_facility:     $("#form_facility").val(),
        form_provider:     $("#form_provider").val(),
        form_apptstatus:   $("#form_apptstatus").val(),
        form_patient_name: $("#form_patient_name").val(),
        form_patient_id:   $("#form_patient_id").val(),
        form_apptcat:      $("#form_apptcat").val(),
        kiosk:             $("#kiosk").val(),
        csrf_token_form:   <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
    }).done(function(data) {
        var delay = (!fromTimer && Date.now()-t0 < 400) ? 400 : 0;
        setTimeout(function(){ drawTable(data); }, delay);
    });
}
// refreshme() defined at top of script for dialog.js onClosed callback

function drawTable(data) {
    $("#loader").removeClass('visible');
    $("#flb_table").html(data);
    $('#flb_table tbody tr[data-statuscolor]').each(function(){
        var c = $(this).data('statuscolor');
        if (c && c !== '' && c.toLowerCase() !== '#ffffff') {
            $(this).css('border-left-color', c);
        }
    });
    applyKioskMode();
    restoreActivePill();
    refineMe();
    initTableButtons();
}

function applyKioskMode() {
    if ($("#kiosk").val() === '') {
        $("[name='kiosk_hide']").show(); $("[name='kiosk_show']").hide();
    } else {
        $("[name='kiosk_hide']").hide(); $("[name='kiosk_show']").show();
    }
}

function restoreActivePill() {
    var cur = $("#form_apptstatus").val();
    $('#flb_table .mdi-status-pill').removeClass('mdi-pill-active');
    if (cur === '') { $('#flb_table .mdi-status-pill.total').addClass('mdi-pill-active'); }
    else            { $('#flb_table .mdi-status-pill[data-status="'+cur+'"]').addClass('mdi-pill-active'); }
}

function refineMe() {
    var catV   = $("#form_apptcat").val()       || '';
    var statV  = $("#form_apptstatus").val()    || '';
    var facV   = $("#form_facility").val()      || '';
    var provV  = ($("#form_provider").val()     || '').toString();
    var pidV   = ($("#form_patient_id").val()   || '').trim();
    var pnameV = ($("#form_patient_name").val() || '').trim();
    var pidRE   = pidV   ? new RegExp(pidV,   'g')  : null;
    var pnameRE = pnameV ? new RegExp(pnameV, 'ig') : null;

    $('#flb_table tbody tr.mdi-row').each(function(){
        var d = $(this).data();
        var ok =
            (!catV   || catV  == d.apptcat)              &&
            (!statV  || statV == d.apptstatus)            &&
            (!facV   || facV  == d.facility)              &&
            (!provV  || provV == String(d.provider || '')) &&
            (!pidRE  || pidRE.test(String(d.pid   || ''))) &&
            (!pnameRE|| pnameRE.test(String(d.pname|| '')));
        $(this).toggle(ok);
    });
}

// Status pill click — event delegation so it works after table reload
$(document).on('click', '.mdi-status-pill', function(){
    var code = $(this).hasClass('total') ? '' : ($(this).data('status') || '');
    $("#form_apptstatus").val(code);
    restoreActivePill();
    refineMe();
});

// ── Inline status + room update — no popup, no dlgopen ───────────────────────
function mdiSave($s, newStat, newRoom) {
    top.restoreSession();
    var $tr = $s.closest('tr');
    $s.addClass('mdi-saving');

    $.post('../patient_tracker/patient_tracker.php', {
        flb_update_status: '1',
        appt_eid:            $s.data('eid'),
        appt_pid:            $s.data('pid'),
        appt_enc:            $s.data('enc') || 0,
        appt_date:           $s.data('date'),
        appt_time:           $s.data('time'),
        new_status:          newStat,
        new_room:            newRoom,
        csrf_token_form:     <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
    }, 'json').done(function(resp) {
        $s.removeClass('mdi-saving');
        if (resp && resp.ok) {
            $tr.attr('data-apptstatus', newStat).data('apptstatus', newStat);
            // Recolour status select
            var $stat = $tr.find('.mdi-status-select');
            var c = resp.color || '';
            if (c && c.length >= 4 && /^#[0-9a-f]{6}$/i.test(c)) {
                var r=parseInt(c.substr(1,2),16), g=parseInt(c.substr(3,2),16), b=parseInt(c.substr(5,2),16);
                var tc = (0.299*r+0.587*g+0.114*b) > 155 ? '#1e0a3c' : '#ffffff';
                var arrowClr = tc === '#ffffff' ? '%23ffffff' : '%231e0a3c';
                $stat.css({
                    'background-color': c, 'color': tc, 'border-color': c,
                    'background-image': 'url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'10\' height=\'6\'%3E%3Cpath d=\'M0 0l5 6 5-6z\' fill=\'' + arrowClr + '\'/%3E%3C/svg%3E")'
                });
                $tr.css('border-left-color', c.toLowerCase() !== '#ffffff' ? c : 'transparent');
            } else {
                $stat.css({'background-color':'','color':'','border-color':'','background-image':''});
                $tr.css('border-left-color','transparent');
            }
            if (resp.arrive)   { $tr.find('.mdi-arrive-cell').text(resp.arrive); }
            if (resp.checkout) { $tr.find('.mdi-checkout-cell').text(resp.checkout); }
            $s.addClass('mdi-saved');
            setTimeout(function(){ $s.removeClass('mdi-saved'); }, 1200);
            refineMe();
        } else {
            $s.addClass('mdi-error');
            setTimeout(function(){ $s.removeClass('mdi-error'); }, 2000);
        }
    }).fail(function() {
        $s.removeClass('mdi-saving').addClass('mdi-error');
        setTimeout(function(){ $s.removeClass('mdi-error'); }, 2000);
    });
}

function mdiUpdateStatus(sel) {
    var $s    = $(sel);
    var $card = $s.closest('.mdi-status-card');
    var room  = $card.find('.mdi-room-select').val() || '';
    mdiSave($s, $s.val(), room);
}

function mdiUpdateRoom(sel) {
    var $s    = $(sel);
    var $card = $s.closest('.mdi-status-card');
    var stat  = $card.find('.mdi-status-select').val() || '-';
    mdiSave($s, stat, $s.val());
}

function calendarpopup(eid, date_squash) {
    top.restoreSession();
    dlgopen('../main/calendar/add_edit_event.php?eid='+encodeURIComponent(eid)+'&date='+encodeURIComponent(date_squash),'_blank',775,500);
    return false;
}

function topatient(pid, enc) {
    if ($('#setting_new_window').prop('checked')) {
        document.fnew.patientID.value   = pid;
        document.fnew.encounterID.value = enc;
        top.restoreSession();
        document.fnew.submit();
    } else {
        top.restoreSession();
        var url = "<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/demographics.php?set_pid=" + encodeURIComponent(pid);
        if (enc > 0) { url += "&set_encounterid=" + encodeURIComponent(enc); }
        top.RTop.location = url;
    }
}

function doCALLback(eventdate, eid) {
    $("#progCALLback_"+eid).parent().css('animation-name','none');
    $("#progCALLback_"+eid).removeClass('hidden');
    if (auto_refresh) clearInterval(auto_refresh);
}

function kiosk_FLB() {
    $("#kiosk").val('1');
    $("[name='kiosk_hide']").hide(); $("[name='kiosk_show']").show();
    var el = document.getElementById("flb_table");
    (el.requestFullscreen||el.webkitRequestFullscreen||el.mozRequestFullScreen||el.msRequestFullscreen||function(){}).call(el);
}
function KioskUp() {
    var kv = $("#kiosk").val();
    if (kv==='0'||kv==='') { $("#kiosk").val('1'); $("[name='kiosk_hide']").hide(); $("[name='kiosk_show']").show(); }
    else                   { $("#kiosk").val('0'); $("[name='kiosk_hide']").show(); $("[name='kiosk_show']").hide(); }
}
function SMS_bot(pid) {
    top.restoreSession();
    var f=<?php echo js_escape($from_date??''); ?>, t=<?php echo js_escape($to_date??''); ?>;
    window.open('../main/messages/messages.php?nomenu=1&go=SMS_bot&pid='+encodeURIComponent(pid)+'&to='+encodeURIComponent(t)+'&from='+encodeURIComponent(f),'SMS_bot','width=370,height=600,resizable=0');
    return false;
}

function initTableButtons() {
    $('#refreshme').off('click').on('click', function(){ refreshMe(); });
}

$(function(){
    refreshMe();
    $("#kiosk").val('');
    applyKioskMode();

    <?php if (($GLOBALS['pat_trkr_timer'] ?? '0') !== '0') { ?>
    (function(){
        var parts = <?php echo js_escape($GLOBALS['pat_trkr_timer']); ?>.split(':');
        var ms = ((parseInt(parts[0])||0)*60 + (parseInt(parts[1])||0))*1000;
        if (ms>0) { auto_refresh = setInterval(function(){ refreshMe(true); }, ms); }
    })();
    <?php } ?>

    ["fullscreenchange","webkitfullscreenchange","mozfullscreenchange","msfullscreenchange"]
        .forEach(function(e){ document.addEventListener(e, KioskUp, false); });

    $('#filter_submit').on('click', function(e){ e.preventDefault(); refreshMe(); });

    $(document).on('click','.drug_screen_completed', function(){
        top.restoreSession();
        $.post("../../library/ajax/drug_screen_completed.php",{
            trackerid:       this.id,
            testcomplete:    this.checked ? 'true':'false',
            csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
        });
    });

    $(document).on('click','#setting_new_window',function(){
        top.restoreSession();
        $.post("<?php echo $GLOBALS['webroot']; ?>/interface/patient_tracker/patient_tracker.php",{
            setting_new_window: this.checked ? 'checked':' ',
            csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
        });
    });

    <?php if ($GLOBALS['ptkr_date_range'] == '1') { ?>
    $('.datepicker').datetimepicker({
        <?php
        $datetimepicker_timepicker  = false;
        $datetimepicker_showseconds = false;
        $datetimepicker_formatInput = true;
        require($GLOBALS['srcdir'].'/js/xl/jquery-datetimepicker-2-5-4.js.php');
        ?>
    });
    <?php } ?>

    initTableButtons();
});
</script>
<?php
}
