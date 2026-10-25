<?php
/**
 * mdi_historical_encounter.php
 *
 * View page for a single historical encounter from mdi_historical_encounters.
 * Linked from the Historical tab in patient_data_template.php.
 *
 * URL params:
 *   ?id=<encounter_id>        required — mdi_historical_encounters.id
 *   ?pid=<pid>                required — patient pid
 *   ?list_pid=<pid>           optional — pid to return to (defaults to pid)
 *
 * Place in: /interface/main/tabs/mdi_historical_encounter.php
 * (same directory as mdi_visits.php)
 */

require_once dirname(__FILE__, 3) . '/globals.php';
require_once $srcdir . '/patient.inc.php';

use OpenEMR\Common\Acl\AclMain;

// ── Auth ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['authUser'])) {
    header('Location: ' . $GLOBALS['webroot'] . '/interface/login/login.php');
    exit;
}
if (!AclMain::aclCheckCore('patients', 'demo')) {
    die(xlt('Access denied'));
}

// ── Input ─────────────────────────────────────────────────────────────────
$enc_id = (int)($_GET['id']  ?? 0);
$pid    = (int)($_GET['pid'] ?? 0);

if (!$enc_id || !$pid) {
    die(xlt('Missing required parameters.'));
}

// ── Load the encounter ────────────────────────────────────────────────────
$enc = sqlQuery(
    "SELECT h.*, u.fname AS prov_fname, u.lname AS prov_lname
       FROM mdi_historical_encounters h
       LEFT JOIN users u ON u.id = h.provider_id
      WHERE h.id = ? AND h.pid = ?
      LIMIT 1",
    [$enc_id, $pid]
);

if (!$enc) {
    die(xlt('Historical encounter not found.'));
}

// ── Load patient demographics ─────────────────────────────────────────────
$pat = getPatientData($pid, 'fname, lname, DOB, sex, phone_home');

// ── Load all encounters for this patient (sidebar list) ────────────────────
$all_encs_res = sqlStatement(
    "SELECT id, encounter_date, chief_complaint, visit_number, provider_notes
       FROM mdi_historical_encounters
      WHERE pid = ?
      ORDER BY encounter_date DESC, id DESC
      LIMIT 200",
    [$pid]
);
$all_encs = [];
while ($row = sqlFetchArray($all_encs_res)) {
    $all_encs[] = $row;
}

// ── Helper functions ──────────────────────────────────────────────────────
function fmt_date($d) {
    if (!$d || $d === '0000-00-00') return '—';
    return date('M j, Y', strtotime($d));
}
function fmt_icd($raw) {
    if (!$raw) return [];
    $codes = [];
    foreach (preg_split('/[;,]+/', $raw) as $part) {
        $part = trim($part);
        if (!$part || $part === 'NULL') continue;
        // Extract code portion (first token before space)
        $tok = preg_split('/\s+/', $part, 2);
        $code = trim($tok[0]);
        // Reformat: MXXXXX -> M99.03 style if no dot
        if ($code && !strpos($code, '.')) {
            // Leave as-is for display
        }
        $codes[] = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    }
    return array_unique(array_filter($codes));
}
function fmt_procs($raw) {
    if (!$raw) return [];
    $procs = [];
    foreach (preg_split('/[;,]+/', $raw) as $p) {
        $p = trim($p);
        if ($p && $p !== 'NULL') $procs[] = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
    }
    return array_unique(array_filter($procs));
}
function esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function nl2p($v) {
    $v = htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    return nl2br($v);
}

// ── Derived display values ────────────────────────────────────────────────
$pat_name    = trim(($pat['fname'] ?? '') . ' ' . ($pat['lname'] ?? ''));
$pat_initials = strtoupper(substr($pat['fname'] ?? 'P', 0, 1) . substr($pat['lname'] ?? 'T', 0, 1));
$pat_dob     = !empty($pat['DOB']) ? date('Y-m-d', strtotime($pat['DOB'])) : '';
$pat_age     = $pat_dob ? (new DateTime($pat['DOB']))->diff(new DateTime())->y : '';

$prov_name   = !empty($enc['prov_fname'])
    ? trim($enc['prov_fname'] . ' ' . $enc['prov_lname']) . ', DC'
    : (esc($enc['provider_notes']) ?: '—');

$enc_date_fmt  = fmt_date($enc['encounter_date']);
$init_date_fmt = fmt_date($enc['initial_visit_date']);
$created_fmt   = !empty($enc['created_at']) ? date('M j, Y g:i A', strtotime($enc['created_at'])) : '—';

$diag_codes = fmt_icd($enc['diagnoses']);
$proc_codes = fmt_procs($enc['procedures']);

// Chief complaint — strip patient name prefix if present
$chief = trim($enc['chief_complaint'] ?? '');
if (preg_match('/^[A-Z\s]+\s+chief complaint:\s*/i', $chief, $m)) {
    $chief = substr($chief, strlen($m[0]));
}

// Sidebar: determine prev/next ids
$prev_id = null; $next_id = null;
for ($i = 0; $i < count($all_encs); $i++) {
    if ((int)$all_encs[$i]['id'] === $enc_id) {
        $prev_id = isset($all_encs[$i - 1]) ? $all_encs[$i - 1]['id'] : null; // newer
        $next_id = isset($all_encs[$i + 1]) ? $all_encs[$i + 1]['id'] : null; // older
        break;
    }
}

$base_url = $GLOBALS['web_root'] . '/interface/main/tabs/mdi_historical_encounter.php';
$list_url = $GLOBALS['web_root'] . '/interface/patient_file/encounter/encounter_top.php?set_pid=' . urlencode($pid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc($pat_name); ?> — Historical Encounter <?php echo esc($enc_date_fmt); ?></title>
<link rel="stylesheet" href="<?php echo $GLOBALS['web_root']; ?>/public/assets/bootstrap/dist/css/bootstrap.min.css">
<style>
/* ── Design tokens ── */
:root {
    --bg:      #f4f3fb;
    --surface: #ffffff;
    --border:  #e8e0f5;
    --navy:    #1e0a3c;
    --purple:  #7c3aed;
    --purple2: #5b21b6;
    --violet:  #ede9fe;
    --text1:   #1e0a3c;
    --text2:   #4b5563;
    --text3:   #9ca3af;
    --green:   #10b981;
    --orange:  #f59e0b;
    --red:     #ef4444;
    --blue:    #3b82f6;
    --teal:    #0891b2;
    --mono:    'JetBrains Mono', 'Fira Mono', monospace;
    --font:    'Inter', 'Montserrat', system-ui, sans-serif;
    --r:       10px;
    --r-sm:    7px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); font-family: var(--font); font-size: 13px; color: var(--text1); min-height: 100vh; }

/* ── Layout ── */
.mhi-shell { display: flex; min-height: 100vh; }
.mhi-sidebar { width: 300px; flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; overflow: hidden; }
.mhi-main { flex: 1; overflow-y: auto; padding: 0 0 40px; }

/* ── Sidebar ── */
.mhi-sb-header { background: var(--navy); padding: 16px; flex-shrink: 0; }
.mhi-sb-pat { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.mhi-sb-avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--purple); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 800; flex-shrink: 0; }
.mhi-sb-pat-name { font-size: 13px; font-weight: 700; color: #fff; line-height: 1.2; }
.mhi-sb-pat-meta { font-size: 10px; color: rgba(255,255,255,.55); margin-top: 1px; }
.mhi-sb-back { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 600; color: #c4b5fd; text-decoration: none; transition: color .13s; }
.mhi-sb-back:hover { color: #fff; text-decoration: none; }
.mhi-sb-back svg { flex-shrink: 0; }

.mhi-sb-list-hdr { padding: 12px 14px 6px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; }
.mhi-sb-list-ttl { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text3); }
.mhi-sb-count { background: var(--violet); color: var(--purple); font-size: 10px; font-weight: 700; border-radius: 10px; padding: 1px 7px; }

.mhi-sb-search { padding: 6px 12px 8px; flex-shrink: 0; border-bottom: 1px solid var(--border); position: relative; }
.mhi-sb-search input { width: 100%; padding: 6px 28px 6px 9px; border: 1.5px solid var(--border); border-radius: 7px; font-size: 11px; font-family: var(--font); color: var(--text1); outline: none; background: #faf9ff; }
.mhi-sb-search input:focus { border-color: var(--purple); }
.mhi-sb-search svg { position: absolute; right: 22px; top: 50%; transform: translateY(-50%); color: var(--text3); pointer-events: none; }

.mhi-sb-list { flex: 1; overflow-y: auto; }
.mhi-sb-list::-webkit-scrollbar { width: 3px; }
.mhi-sb-list::-webkit-scrollbar-thumb { background: #c4b5fd; border-radius: 2px; }

.mhi-sb-item { display: block; padding: 10px 14px; border-bottom: 1px solid var(--border); text-decoration: none; transition: background .12s; cursor: pointer; }
.mhi-sb-item:hover { background: #f8f6ff; text-decoration: none; }
.mhi-sb-item.active { background: var(--violet); border-left: 3px solid var(--purple); }
.mhi-sb-item-date { font-size: 12px; font-weight: 700; color: var(--text1); font-family: var(--mono); }
.mhi-sb-item-cc { font-size: 11px; font-weight: 600; color: var(--text2); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mhi-sb-item-meta { font-size: 10px; color: var(--text3); margin-top: 2px; }

.mhi-sb-footer { padding: 10px 14px; border-top: 1px solid var(--border); flex-shrink: 0; }
.mhi-sb-footer-link { display: inline-flex; align-items: center; gap: 5px; font-size: 11px; font-weight: 700; color: var(--purple); text-decoration: none; }
.mhi-sb-footer-link:hover { color: var(--purple2); text-decoration: none; }

/* ── Top bar ── */
.mhi-topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 14px 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; position: sticky; top: 0; z-index: 50; }
.mhi-topbar-left { display: flex; flex-direction: column; gap: 6px; }
.mhi-topbar-title { font-size: 20px; font-weight: 800; color: var(--navy); letter-spacing: -.02em; }
.mhi-imported-badge { display: inline-flex; align-items: center; gap: 5px; background: #fff7ed; border: 1px solid #fed7aa; border-radius: 20px; padding: 3px 10px; font-size: 11px; font-weight: 600; color: #c2410c; }
.mhi-topbar-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.mhi-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; cursor: pointer; border: 1.5px solid; text-decoration: none; transition: all .15s; white-space: nowrap; }
.mhi-btn:hover { text-decoration: none; }
.mhi-btn-ghost { background: #fff; border-color: var(--border); color: var(--text2); }
.mhi-btn-ghost:hover { border-color: var(--purple); color: var(--purple); }
.mhi-btn-outline { background: #fff; border-color: #c4b5fd; color: var(--purple2); }
.mhi-btn-outline:hover { background: var(--violet); }
.mhi-btn-primary { background: var(--purple); border-color: var(--purple); color: #fff; }
.mhi-btn-primary:hover { background: var(--purple2); border-color: var(--purple2); }
.mhi-btn-nav { background: #fff; border-color: var(--border); color: var(--text3); padding: 7px 10px; }
.mhi-btn-nav:hover { border-color: var(--purple); color: var(--purple); }

/* ── Meta strip ── */
.mhi-meta-strip { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 24px; display: flex; align-items: stretch; gap: 0; }
.mhi-meta-item { padding: 14px 20px 14px 0; display: flex; flex-direction: column; gap: 3px; border-right: 1px solid var(--border); padding-right: 20px; margin-right: 20px; min-width: 120px; }
.mhi-meta-item:last-child { border-right: none; margin-right: 0; }
.mhi-meta-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text3); display: flex; align-items: center; gap: 5px; }
.mhi-meta-label svg { color: var(--purple); flex-shrink: 0; }
.mhi-meta-val { font-size: 14px; font-weight: 700; color: var(--navy); }
.mhi-meta-val.mono { font-family: var(--mono); font-size: 13px; }
.mhi-open-billing-btn { margin-left: auto; align-self: center; }

/* ── Content grid ── */
.mhi-content { padding: 20px 24px; display: grid; grid-template-columns: 1fr 340px; gap: 16px; align-items: start; }

/* ── Cards ── */
.mhi-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--r); overflow: hidden; }
.mhi-card-hdr { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 8px; }
.mhi-card-hdr-icon { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mhi-card-title { font-size: 13px; font-weight: 700; color: var(--navy); }
.mhi-card-body { padding: 14px 16px; }
.mhi-card-text { font-size: 13px; line-height: 1.65; color: var(--text2); }
.mhi-card + .mhi-card { margin-top: 12px; }

/* Card icon colors */
.ic-blue   { background: #eff6ff; } .ic-blue   svg { color: var(--blue); }
.ic-purple { background: var(--violet); } .ic-purple svg { color: var(--purple); }
.ic-green  { background: #f0fdf4; } .ic-green  svg { color: var(--green); }
.ic-orange { background: #fff7ed; } .ic-orange svg { color: var(--orange); }
.ic-navy   { background: rgba(30,10,60,.08); } .ic-navy svg { color: var(--navy); }
.ic-teal   { background: #ecfeff; } .ic-teal   svg { color: var(--teal); }
.ic-indigo { background: #eef2ff; } .ic-indigo svg { color: #4f46e5; }
.ic-slate  { background: #f8fafc; } .ic-slate  svg { color: #475569; }

/* ── Diagnosis chips ── */
.mhi-chip-row { display: flex; flex-wrap: wrap; gap: 6px; }
.mhi-chip { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; cursor: default; }
.chip-dx { background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; }
.chip-proc { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.mhi-chip-add { background: transparent; border: 1.5px dashed var(--border); color: var(--text3); font-size: 11px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; transition: all .13s; }
.mhi-chip-add:hover { border-color: var(--purple); color: var(--purple); }

/* ── Pain / Functional card ── */
.mhi-pf-row { display: flex; align-items: baseline; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f5f2fc; }
.mhi-pf-row:last-child { border-bottom: none; }
.mhi-pf-label { font-size: 11px; font-weight: 600; color: var(--text3); min-width: 100px; }
.mhi-pf-val { font-size: 12px; font-weight: 600; color: var(--text1); text-align: right; background: #f1f5f9; border-radius: 6px; padding: 2px 9px; }

/* ── Imported info card ── */
.mhi-imp-row { display: flex; align-items: flex-start; gap: 8px; padding: 5px 0; border-bottom: 1px solid #f5f2fc; font-size: 11.5px; }
.mhi-imp-row:last-child { border-bottom: none; }
.mhi-imp-key { color: var(--text3); min-width: 90px; flex-shrink: 0; font-weight: 600; }
.mhi-imp-val { color: var(--text1); font-weight: 500; word-break: break-all; }

/* ── Original Report card ── */
.mhi-report-body { padding: 14px 16px; }
.mhi-report-notice { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--text3); margin-bottom: 10px; }
.mhi-report-text { font-family: var(--mono); font-size: 11px; line-height: 1.7; color: var(--text2); background: #fafafa; border: 1px solid var(--border); border-radius: 7px; padding: 12px 14px; max-height: 220px; overflow-y: auto; white-space: pre-wrap; word-break: break-word; }
.mhi-report-text::-webkit-scrollbar { width: 4px; } .mhi-report-text::-webkit-scrollbar-thumb { background: #c4b5fd; border-radius: 2px; }
.mhi-view-full-btn { display: inline-flex; align-items: center; gap: 5px; margin-top: 10px; font-size: 11px; font-weight: 700; color: var(--purple); text-decoration: none; cursor: pointer; background: none; border: none; padding: 0; }
.mhi-view-full-btn:hover { color: var(--purple2); }

/* ── Left column stacking ── */
.mhi-left { display: flex; flex-direction: column; gap: 12px; }
.mhi-right { display: flex; flex-direction: column; gap: 12px; }

/* ── Print overrides ── */
@media print {
    .mhi-sidebar, .mhi-topbar-actions, .mhi-open-billing-btn { display: none !important; }
    .mhi-shell { display: block; }
    .mhi-main { overflow: visible; }
    .mhi-content { grid-template-columns: 1fr 1fr; }
    .mhi-card { break-inside: avoid; }
}

/* ── Full report modal ── */
.mhi-modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9999; align-items: center; justify-content: center; }
.mhi-modal-bg.open { display: flex; }
.mhi-modal { background: #fff; border-radius: 12px; width: 760px; max-width: 95vw; max-height: 85vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.mhi-modal-hdr { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.mhi-modal-title { font-size: 15px; font-weight: 700; color: var(--navy); }
.mhi-modal-close { background: none; border: none; cursor: pointer; color: var(--text3); padding: 4px; border-radius: 6px; transition: color .13s; }
.mhi-modal-close:hover { color: var(--red); }
.mhi-modal-body { flex: 1; overflow-y: auto; padding: 16px 20px; font-family: var(--mono); font-size: 11.5px; line-height: 1.75; color: var(--text2); white-space: pre-wrap; word-break: break-word; }
</style>
</head>
<body>
<div class="mhi-shell">

<!-- ══ SIDEBAR ══ -->
<aside class="mhi-sidebar">
    <div class="mhi-sb-header">
        <div class="mhi-sb-pat">
            <div class="mhi-sb-avatar"><?php echo esc($pat_initials); ?></div>
            <div>
                <div class="mhi-sb-pat-name"><?php echo esc($pat_name); ?> <span style="color:rgba(255,255,255,.45);font-size:11px;">(<?php echo esc($pid); ?>)</span></div>
                <div class="mhi-sb-pat-meta">
                    <?php echo esc($pat['DOB'] ?? ''); ?><?php if ($pat_age): ?> · Age <?php echo (int)$pat_age; ?><?php endif; ?>
                    <?php if (!empty($pat['sex'])): ?> · <?php echo esc($pat['sex']); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <a class="mhi-sb-back" href="javascript:history.back()">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            Back to Patient
        </a>
    </div>

    <div class="mhi-sb-list-hdr">
        <span class="mhi-sb-list-ttl">Historical Encounters</span>
        <span class="mhi-sb-count"><?php echo count($all_encs); ?></span>
    </div>

    <div class="mhi-sb-search">
        <input type="text" id="sb-search" placeholder="Search historical encounters…" oninput="mhiSbFilter(this.value)">
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    </div>

    <div class="mhi-sb-list" id="mhi-sb-list">
        <?php foreach ($all_encs as $row):
            $is_active = (int)$row['id'] === $enc_id;
            $row_url   = $base_url . '?id=' . urlencode($row['id']) . '&pid=' . urlencode($pid);
            $row_date  = fmt_date($row['encounter_date']);
            // Short chief complaint
            $row_cc = trim($row['chief_complaint'] ?? '');
            if (preg_match('/^[A-Z\s]+\s+chief complaint:\s*/i', $row_cc, $m2)) $row_cc = substr($row_cc, strlen($m2[0]));
            $row_cc = mb_strimwidth($row_cc ?: '—', 0, 55, '…');
            $row_visit = $row['visit_number'] ? 'Visit ' . esc($row['visit_number']) . ' · ' : '';
            $row_prov  = esc($row['provider_notes'] ?: 'Deborah Nodrick, DC');
        ?>
        <a class="mhi-sb-item<?php echo $is_active ? ' active' : ''; ?>" href="<?php echo esc($row_url); ?>"
           data-date="<?php echo esc($row['encounter_date']); ?>"
           data-cc="<?php echo esc(strtolower($row_cc)); ?>">
            <div class="mhi-sb-item-date"><?php echo esc($row_date); ?></div>
            <div class="mhi-sb-item-cc"><?php echo esc($row_cc); ?></div>
            <div class="mhi-sb-item-meta"><?php echo $row_visit; ?><?php echo $row_prov; ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="mhi-sb-footer">
        <a class="mhi-sb-footer-link" href="javascript:history.back()">
            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            View All Historical Encounters
        </a>
    </div>
</aside>

<!-- ══ MAIN ══ -->
<main class="mhi-main">

    <!-- Top bar -->
    <div class="mhi-topbar">
        <div class="mhi-topbar-left">
            <div class="mhi-topbar-title">Historical Encounter</div>
            <div>
                <span class="mhi-imported-badge">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                    Imported from <?php echo esc($enc['import_source'] ?: 'Legacy System'); ?>
                </span>
            </div>
        </div>
        <div class="mhi-topbar-actions">
            <?php if ($prev_id): ?>
            <a class="mhi-btn mhi-btn-nav" href="<?php echo esc($base_url . '?id=' . $prev_id . '&pid=' . $pid); ?>" title="Newer encounter">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($next_id): ?>
            <a class="mhi-btn mhi-btn-nav" href="<?php echo esc($base_url . '?id=' . $next_id . '&pid=' . $pid); ?>" title="Older encounter">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
            </a>
            <?php endif; ?>
            <button class="mhi-btn mhi-btn-ghost" onclick="window.print()">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print
            </button>
            <?php if (!empty($enc['imported_report'])): ?>
            <button class="mhi-btn mhi-btn-ghost" onclick="document.getElementById('mhi-modal').classList.add('open')">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/></svg>
                View Original Report
            </button>
            <?php endif; ?>
            <button class="mhi-btn mhi-btn-primary" onclick="mhiEditToggle()">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                Edit Encounter
            </button>
        </div>
    </div>

    <!-- Meta strip -->
    <div class="mhi-meta-strip">
        <div class="mhi-meta-item">
            <div class="mhi-meta-label">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Encounter Date
            </div>
            <div class="mhi-meta-val"><?php echo esc($enc_date_fmt); ?></div>
        </div>
        <div class="mhi-meta-item">
            <div class="mhi-meta-label">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Visit Number
            </div>
            <div class="mhi-meta-val"><?php echo $enc['visit_number'] ? esc($enc['visit_number']) . ' of ' . esc($enc['visit_number']) : '—'; ?></div>
        </div>
        <div class="mhi-meta-item">
            <div class="mhi-meta-label">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Provider
            </div>
            <div class="mhi-meta-val"><?php echo esc($prov_name); ?></div>
        </div>
        <div class="mhi-meta-item">
            <div class="mhi-meta-label">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Initial Visit Date
            </div>
            <div class="mhi-meta-val"><?php echo esc($init_date_fmt); ?></div>
        </div>
        <div class="mhi-meta-item">
            <div class="mhi-meta-label">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/></svg>
                Encounter #
            </div>
            <div class="mhi-meta-val mono"><?php echo esc($enc['encounter']); ?></div>
        </div>
        <div class="mhi-meta-item mhi-open-billing-btn">
            <a class="mhi-btn mhi-btn-outline" href="<?php echo esc($list_url); ?>" onclick="top.restoreSession();">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                Open Billing
            </a>
        </div>
    </div>

    <!-- Two-column content -->
    <div class="mhi-content">

        <!-- LEFT: SOAP notes -->
        <div class="mhi-left">

            <?php if ($chief): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-blue"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div>
                    <span class="mhi-card-title">Chief Complaint</span>
                </div>
                <div class="mhi-card-body"><div class="mhi-card-text"><?php echo nl2p($chief); ?></div></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($enc['subjective'])): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-purple"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M5.5 21a7 7 0 0 1 13 0"/></svg></div>
                    <span class="mhi-card-title">Subjective</span>
                </div>
                <div class="mhi-card-body"><div class="mhi-card-text"><?php echo nl2p($enc['subjective']); ?></div></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($enc['objective'])): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-teal"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
                    <span class="mhi-card-title">Objective</span>
                </div>
                <div class="mhi-card-body"><div class="mhi-card-text"><?php echo nl2p($enc['objective']); ?></div></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($enc['assessment'])): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-orange"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
                    <span class="mhi-card-title">Assessment</span>
                </div>
                <div class="mhi-card-body"><div class="mhi-card-text"><?php echo nl2p($enc['assessment']); ?></div></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($enc['plan'])): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-indigo"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></div>
                    <span class="mhi-card-title">Plan</span>
                </div>
                <div class="mhi-card-body"><div class="mhi-card-text"><?php echo nl2p($enc['plan']); ?></div></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($enc['treatment'])): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-green"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div>
                    <span class="mhi-card-title">Treatment</span>
                </div>
                <div class="mhi-card-body"><div class="mhi-card-text"><?php echo nl2p($enc['treatment']); ?></div></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($enc['imported_report'])): ?>
            <!-- Original Report card (collapsed) -->
            <div class="mhi-card" id="mhi-orig-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-slate"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/></svg></div>
                    <span class="mhi-card-title">Original Imported Report <span style="font-weight:400;color:var(--text3);font-size:11px;">(Unedited)</span></span>
                </div>
                <div class="mhi-report-body">
                    <div class="mhi-report-notice">
                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        This is the full report as it was imported from the legacy system. No changes have been made.
                    </div>
                    <div class="mhi-report-text" id="mhi-report-preview"><?php
                        $report_text = $enc['imported_report'];
                        // Strip RTF if present
                        if (substr(ltrim($report_text), 0, 5) === '{\\rtf') {
                            // Basic RTF strip: remove control sequences
                            $report_text = preg_replace('/\{\\\\[^}]*\}/', '', $report_text);
                            $report_text = preg_replace('/\\\\[a-z]+[-]?[0-9]*\s?/', ' ', $report_text);
                            $report_text = preg_replace('/[{}]/', '', $report_text);
                            $report_text = html_entity_decode(trim($report_text));
                        }
                        echo esc(mb_strimwidth($report_text, 0, 1200, '… [truncated — click View Full Report to see all]'));
                    ?></div>
                    <button class="mhi-view-full-btn" onclick="document.getElementById('mhi-modal').classList.add('open')">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        View Full Report
                    </button>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /mhi-left -->

        <!-- RIGHT: diagnoses, procedures, pain, notes, import info -->
        <div class="mhi-right">

            <!-- Diagnoses -->
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-green"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></div>
                    <span class="mhi-card-title">Diagnoses</span>
                </div>
                <div class="mhi-card-body">
                    <?php if ($diag_codes): ?>
                    <div class="mhi-chip-row">
                        <?php foreach ($diag_codes as $code): ?>
                        <span class="mhi-chip chip-dx" title="<?php echo esc($enc['diagnoses']); ?>"><?php echo $code; ?></span>
                        <?php endforeach; ?>
                        <span class="mhi-chip-add">+ Add Diagnosis</span>
                    </div>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--text3);">No diagnoses recorded.</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Procedures -->
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-blue"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 7h8M8 12h5M8 17h6"/></svg></div>
                    <span class="mhi-card-title">Procedures</span>
                </div>
                <div class="mhi-card-body">
                    <?php if ($proc_codes): ?>
                    <div class="mhi-chip-row">
                        <?php foreach ($proc_codes as $code): ?>
                        <span class="mhi-chip chip-proc"><?php echo $code; ?></span>
                        <?php endforeach; ?>
                        <span class="mhi-chip-add">+ Add Procedure</span>
                    </div>
                    <?php else: ?>
                    <span style="font-size:12px;color:var(--text3);">No procedures recorded.</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pain / Functional -->
            <?php if ($enc['pain_scale'] || $enc['functional_status'] || $enc['rom']): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-orange"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
                    <span class="mhi-card-title">Pain / Functional</span>
                </div>
                <div class="mhi-card-body">
                    <?php if ($enc['pain_scale']): ?>
                    <div class="mhi-pf-row"><span class="mhi-pf-label">Pain Scale</span><span class="mhi-pf-val"><?php echo esc($enc['pain_scale']); ?></span></div>
                    <?php endif; ?>
                    <?php if ($enc['functional_status']): ?>
                    <div class="mhi-pf-row"><span class="mhi-pf-label">Functional Status</span><span class="mhi-pf-val"><?php echo esc(mb_strimwidth($enc['functional_status'], 0, 60, '…')); ?></span></div>
                    <?php endif; ?>
                    <?php if ($enc['rom']): ?>
                    <div class="mhi-pf-row"><span class="mhi-pf-label">ROM</span><span class="mhi-pf-val"><?php echo esc(mb_strimwidth($enc['rom'], 0, 80, '…')); ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recommendations -->
            <?php if (!empty($enc['recommendations'])): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-teal"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg></div>
                    <span class="mhi-card-title">Recommendations</span>
                </div>
                <div class="mhi-card-body"><div class="mhi-card-text"><?php echo nl2p($enc['recommendations']); ?></div></div>
            </div>
            <?php endif; ?>

            <!-- Provider Notes -->
            <?php if (!empty($enc['provider_notes'])): ?>
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-purple"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></div>
                    <span class="mhi-card-title">Provider Notes</span>
                </div>
                <div class="mhi-card-body"><div class="mhi-card-text"><?php echo nl2p($enc['provider_notes']); ?></div></div>
            </div>
            <?php endif; ?>

            <!-- Imported Information -->
            <div class="mhi-card">
                <div class="mhi-card-hdr">
                    <div class="mhi-card-hdr-icon ic-slate"><svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/></svg></div>
                    <span class="mhi-card-title">Imported Information</span>
                </div>
                <div class="mhi-card-body">
                    <?php if (!empty($enc['original_filename'])): ?>
                    <div class="mhi-imp-row"><span class="mhi-imp-key">Original File</span><span class="mhi-imp-val"><?php echo esc($enc['original_filename']); ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($enc['import_source'])): ?>
                    <div class="mhi-imp-row"><span class="mhi-imp-key">Import Source</span><span class="mhi-imp-val"><?php echo esc($enc['import_source']); ?></span></div>
                    <?php endif; ?>
                    <?php if ($created_fmt !== '—'): ?>
                    <div class="mhi-imp-row"><span class="mhi-imp-key">Imported On</span><span class="mhi-imp-val"><?php echo esc($created_fmt); ?></span></div>
                    <?php endif; ?>
                    <div class="mhi-imp-row"><span class="mhi-imp-key">Imported By</span><span class="mhi-imp-val"><?php echo esc($_SESSION['authUser'] ?? 'System'); ?></span></div>
                </div>
            </div>

        </div><!-- /mhi-right -->
    </div><!-- /mhi-content -->
</main><!-- /mhi-main -->
</div><!-- /mhi-shell -->

<!-- ══ Full Report Modal ══ -->
<?php if (!empty($enc['imported_report'])): ?>
<div class="mhi-modal-bg" id="mhi-modal" onclick="if(event.target===this)this.classList.remove('open')">
    <div class="mhi-modal">
        <div class="mhi-modal-hdr">
            <span class="mhi-modal-title">Original Imported Report — <?php echo esc($enc_date_fmt); ?></span>
            <button class="mhi-modal-close" onclick="document.getElementById('mhi-modal').classList.remove('open')">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="mhi-modal-body"><?php
            $full_report = $enc['imported_report'];
            if (substr(ltrim($full_report), 0, 5) === '{\\rtf') {
                $full_report = preg_replace('/\{\\\\[^}]*\}/', '', $full_report);
                $full_report = preg_replace('/\\\\[a-z]+[-]?[0-9]*\s?/', ' ', $full_report);
                $full_report = preg_replace('/[{}]/', '', $full_report);
                $full_report = html_entity_decode(trim($full_report));
            }
            echo esc($full_report);
        ?></div>
    </div>
</div>
<?php endif; ?>

<script>
// ── Sidebar search filter ─────────────────────────────────────────────────
function mhiSbFilter(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('#mhi-sb-list .mhi-sb-item').forEach(function(el) {
        var date = el.dataset.date || '';
        var cc   = el.dataset.cc   || '';
        var show = !q || date.indexOf(q) >= 0 || cc.indexOf(q) >= 0;
        el.style.display = show ? '' : 'none';
    });
}

// ── Edit toggle stub (wire to your edit form as needed) ──────────────────
function mhiEditToggle() {
    // Placeholder — extend to open an inline edit form or navigate to edit URL
    alert('Edit functionality: wire to your edit endpoint for encounter ID <?php echo (int)$enc_id; ?>.');
}

// ── Keyboard nav ─────────────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var modal = document.getElementById('mhi-modal');
        if (modal) modal.classList.remove('open');
    }
    <?php if ($prev_id): ?>
    if (e.altKey && e.key === 'ArrowLeft') window.location.href = '<?php echo esc($base_url . '?id=' . $prev_id . '&pid=' . $pid); ?>';
    <?php endif; ?>
    <?php if ($next_id): ?>
    if (e.altKey && e.key === 'ArrowRight') window.location.href = '<?php echo esc($base_url . '?id=' . $next_id . '&pid=' . $pid); ?>';
    <?php endif; ?>
});

// ── Scroll active sidebar item into view ─────────────────────────────────
(function() {
    var active = document.querySelector('#mhi-sb-list .mhi-sb-item.active');
    if (active) active.scrollIntoView({ block: 'center', behavior: 'smooth' });
})();
</script>
</body>
</html>
