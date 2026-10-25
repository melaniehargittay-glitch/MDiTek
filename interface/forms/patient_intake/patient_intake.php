<?php
/**
 * New Patient Intake Form
 * MDiTek / OpenEMR integration
 *
 * Usage:
 *   Create new:  patient_intake.php?pid=123
 *   Edit draft:  patient_intake.php?pid=123&form_id=45
 *   View signed: patient_intake.php?pid=123&form_id=45&mode=view
 */

require_once("../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;

// ── Auth check ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['authUser'])) {
    die(xlt("Not authorized."));
}

$pid      = (int)($_REQUEST['pid']     ?? $GLOBALS['pid'] ?? 0);
$form_id  = (int)($_REQUEST['form_id'] ?? 0);
$mode     = trim($_REQUEST['mode']     ?? 'edit');   // edit | view
$user_id  = (int)$_SESSION['authUserID'];

if (!$pid) die(xlt("No patient selected."));

// ── Helper: sanitize and fetch a POST field ────────────────────────────────────
function pStr($k, $default = '')  { return trim($_POST[$k] ?? $default); }
function pInt($k, $default = 0)   { return (int)($_POST[$k] ?? $default); }
function pDate($k)                 { $v = trim($_POST[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
function pChk($k)                  { return isset($_POST[$k]) ? 1 : 0; }

// ── Handle POST (save / sign) ─────────────────────────────────────────────────
$save_error   = '';
$save_success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        die(xlt("CSRF verification failed."));
    }

    $action = pStr('action', 'draft');   // draft | sign

    // Collect all fields
    $d = [
        'pid'                  => $pid,
        'encounter'            => pInt('encounter') ?: null,
        // §1
        'chief_complaint'      => pStr('chief_complaint'),
        'affected_areas'       => pStr('affected_areas'),
        'onset_date'           => pDate('onset_date'),
        'onset_type'           => pStr('onset_type') ?: null,
        'pain_level'           => pInt('pain_level'),
        'pain_sharp'           => pChk('pain_sharp'),
        'pain_dull'            => pChk('pain_dull'),
        'pain_burning'         => pChk('pain_burning'),
        'pain_aching'          => pChk('pain_aching'),
        'pain_throbbing'       => pChk('pain_throbbing'),
        'pain_tingling'        => pChk('pain_tingling'),
        'pain_other'           => pStr('pain_other'),
        'pain_travels'         => isset($_POST['pain_travels']) ? (pStr('pain_travels') === 'yes' ? 1 : 0) : null,
        'pain_travels_where'   => pStr('pain_travels_where'),
        'moi_auto_accident'    => pChk('moi_auto_accident'),
        'moi_work_injury'      => pChk('moi_work_injury'),
        'moi_sports_injury'    => pChk('moi_sports_injury'),
        'moi_fall'             => pChk('moi_fall'),
        'moi_no_known'         => pChk('moi_no_known'),
        'moi_other'            => pStr('moi_other'),
        'agg_sitting'          => pChk('agg_sitting'),
        'agg_standing'         => pChk('agg_standing'),
        'agg_walking'          => pChk('agg_walking'),
        'agg_bending'          => pChk('agg_bending'),
        'agg_lifting'          => pChk('agg_lifting'),
        'agg_sleeping'         => pChk('agg_sleeping'),
        'agg_other'            => pStr('agg_other'),
        'rel_rest'             => pChk('rel_rest'),
        'rel_ice'              => pChk('rel_ice'),
        'rel_heat'             => pChk('rel_heat'),
        'rel_stretching'       => pChk('rel_stretching'),
        'rel_medication'       => pChk('rel_medication'),
        'rel_other'            => pStr('rel_other'),
        'prev_other_provider'  => isset($_POST['prev_other_provider']) ? (pStr('prev_other_provider') === 'yes' ? 1 : 0) : null,
        'prev_chiro'           => isset($_POST['prev_chiro']) ? (pStr('prev_chiro') === 'yes' ? 1 : 0) : null,
        'prev_physical_therapy'=> isset($_POST['prev_physical_therapy']) ? (pStr('prev_physical_therapy') === 'yes' ? 1 : 0) : null,
        'prev_imaging'         => isset($_POST['prev_imaging']) ? (pStr('prev_imaging') === 'yes' ? 1 : 0) : null,
        'prev_imaging_type'    => pStr('prev_imaging_type'),
        // §2
        'functional_notes'     => pStr('functional_notes'),
        'oswestry_score'       => pStr('oswestry_score') !== '' ? pStr('oswestry_score') : null,
        // §3
        'complaint_summary'    => pStr('complaint_summary'),
        // §4
        'exam_notes'           => pStr('exam_notes'),
        'bp'                   => pStr('bp'),
        'pulse'                => pStr('pulse'),
        'temp'                 => pStr('temp'),
        'height'               => pStr('height'),
        'weight'               => pStr('weight'),
        // §5
        'objective_notes'      => pStr('objective_notes'),
        'posture_notes'        => pStr('posture_notes'),
        'rom_cervical'         => pStr('rom_cervical'),
        'rom_lumbar'           => pStr('rom_lumbar'),
        'rom_thoracic'         => pStr('rom_thoracic'),
        // §6
        'assessment_notes'     => pStr('assessment_notes'),
        'diagnosis_codes'      => pStr('diagnosis_codes'),
        // §7
        'plan_notes'           => pStr('plan_notes'),
        'visits_per_week'      => pInt('visits_per_week') ?: null,
        'weeks_of_care'        => pInt('weeks_of_care') ?: null,
        // §8
        'treatment_notes'      => pStr('treatment_notes'),
        'treatment_performed'  => pStr('treatment_performed'),
        // §9
        'goals_short_term'     => pStr('goals_short_term'),
        'goals_long_term'      => pStr('goals_long_term'),
        // §10
        'xray_taken'           => pChk('xray_taken'),
        'xray_findings'        => pStr('xray_findings'),
        'xray_date'            => pDate('xray_date'),
        // §11–15
        'ep_chief_complaint'   => pStr('ep_chief_complaint'),
        'ep_objective'         => pStr('ep_objective'),
        'ep_assessment'        => pStr('ep_assessment'),
        'ep_plan'              => pStr('ep_plan'),
        'ep_treatment'         => pStr('ep_treatment'),
        // §16
        'aa_date_of_accident'  => pDate('aa_date_of_accident'),
        'aa_description'       => pStr('aa_description'),
        'aa_claim_number'      => pStr('aa_claim_number'),
        'aa_attorney'          => pStr('aa_attorney'),
        // §17
        'wc_date_of_injury'    => pDate('wc_date_of_injury'),
        'wc_employer'          => pStr('wc_employer'),
        'wc_claim_number'      => pStr('wc_claim_number'),
        'wc_notes'             => pStr('wc_notes'),
        // §18–22
        'exercise_program'     => pStr('exercise_program'),
        'supplements'          => pStr('supplements'),
        'neuro_notes'          => pStr('neuro_notes'),
        'neuro_reflexes'       => pStr('neuro_reflexes'),
        'neuro_sensation'      => pStr('neuro_sensation'),
        'neuro_motor'          => pStr('neuro_motor'),
        'upper_ext_notes'      => pStr('upper_ext_notes'),
        'upper_ext_rom'        => pStr('upper_ext_rom'),
        'upper_ext_strength'   => pStr('upper_ext_strength'),
        'lower_ext_notes'      => pStr('lower_ext_notes'),
        'lower_ext_rom'        => pStr('lower_ext_rom'),
        'lower_ext_strength'   => pStr('lower_ext_strength'),
    ];

    $new_status = ($action === 'sign') ? 'signed' : 'draft';
    $d['status'] = $new_status;
    if ($action === 'sign') {
        $d['signed_by'] = $user_id;
        $d['signed_at'] = date('Y-m-d H:i:s');
    }

    if ($form_id) {
        // ── UPDATE ─────────────────────────────────────────────────────────────
        // Block edits on locked forms
        $existing = sqlQuery("SELECT status FROM patient_intake_forms WHERE id=? AND pid=?", [$form_id, $pid]);
        if ($existing && $existing['status'] === 'locked') {
            $save_error = xl("This form is locked and cannot be edited.");
        } else {
            $sets = [];
            $vals = [];
            foreach ($d as $col => $val) {
                $sets[] = "`$col` = ?";
                $vals[] = $val;
            }
            $vals[] = $form_id;
            $vals[] = $pid;
            sqlStatement("UPDATE patient_intake_forms SET " . implode(', ', $sets) . " WHERE id=? AND pid=?", $vals);
            $save_success = ($action === 'sign') ? xl("Form signed successfully.") : xl("Draft saved.");
        }
    } else {
        // ── INSERT ─────────────────────────────────────────────────────────────
        $d['created_by'] = $user_id;
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($d)));
        $placeholders = implode(', ', array_fill(0, count($d), '?'));
        $new_id = sqlInsert("INSERT INTO patient_intake_forms ($cols) VALUES ($placeholders)", array_values($d));
        if ($new_id) {
            $form_id    = (int)$new_id;
            $save_success = ($action === 'sign') ? xl("Form signed successfully.") : xl("Draft saved.");
        } else {
            $save_error = xl("Database error saving form.");
        }
    }
}

// ── Load existing form data (for edit/view mode) ──────────────────────────────
$f = [];
if ($form_id) {
    $row = sqlQuery("SELECT * FROM patient_intake_forms WHERE id=? AND pid=?", [$form_id, $pid]);
    if ($row) {
        $f = $row;
        if ($f['status'] === 'signed' && $mode !== 'view') $mode = 'view'; // auto-read-only
    }
}
$is_view     = ($mode === 'view' || ($f['status'] ?? '') === 'locked');
$is_signed   = in_array($f['status'] ?? '', ['signed', 'locked']);

// Load patient info
$patient     = sqlQuery("SELECT lname, fname, mname, DOB, sex, pubpid FROM patient_data WHERE pid=?", [$pid]);
$pat_name    = strtoupper(($patient['lname'] ?? '') . ', ' . ($patient['fname'] ?? ''));
$pat_dob     = $patient['DOB'] ?? '';
$pat_sex     = $patient['sex'] ?? '';
$pat_chart   = $patient['pubpid'] ?? $pid;

// Load previous forms for this patient (history list)
$prior_forms = [];
$pf_res = sqlStatement(
    "SELECT id, created_at, updated_at, status, signed_at FROM patient_intake_forms
     WHERE pid=? ORDER BY created_at DESC LIMIT 10",
    [$pid]
);
while ($pf = sqlFetchArray($pf_res)) $prior_forms[] = $pf;

// ── Shorthand: value from $f or empty ────────────────────────────────────────
function fv($key, $default = '') {
    global $f;
    return htmlspecialchars($f[$key] ?? $default, ENT_QUOTES);
}
function fChk($key) {
    global $f;
    return !empty($f[$key]);
}
function fRadio($key, $value) {
    global $f;
    if (!isset($f[$key])) return '';
    return (string)$f[$key] === (string)$value ? 'checked' : '';
}

$csrf = attr(CsrfUtils::collectCsrfToken());
$today = date('m/d/Y');

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo xlt("New Patient Intake"); ?> — MDiTek</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --navy:#1a1f3a;--navyMid:#2d3461;--purple:#6366f1;--purpleL:#e0e7ff;--purplePale:#f0f0ff;
  --green:#16a34a;--greenPale:#f0fdf4;--amber:#d97706;--red:#dc2626;
  --border:#e5e7eb;--text1:#111827;--text2:#4b5563;--text3:#9ca3af;
  --surface:#f9fafb;--white:#ffffff;
}
body{font-family:'DM Sans',sans-serif;background:var(--surface);color:var(--text1);font-size:13px;min-height:100vh}

/* ── Chrome ── */
.hdr{background:var(--navy);padding:10px 22px;display:flex;align-items:center;gap:12px}
.hdr-logo{width:30px;height:30px;background:var(--purple);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.hdr-brand{font-size:15px;font-weight:800;color:#fff}
.hdr-sub{font-size:12px;color:rgba(255,255,255,.4)}
.hdr-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.hdr-user{font-size:12px;color:rgba(255,255,255,.65)}
.pat-bar{background:var(--white);border-bottom:1px solid var(--border);padding:9px 22px;display:flex;align-items:center;gap:16px}
.pat-avatar{width:36px;height:36px;border-radius:50%;background:var(--navyMid);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.8);font-weight:700;font-size:12px;flex-shrink:0}
.pat-name{font-size:13px;font-weight:700}
.pat-meta{font-size:11px;color:var(--text3);margin-top:1px}
.pat-date-lbl{font-size:9.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);font-weight:700}
.pat-date-val{font-size:13px;font-weight:700}
.layout{display:flex;max-width:1100px;margin:0 auto;padding:0 16px 80px;gap:18px;margin-top:18px}
.sidebar{width:220px;flex-shrink:0}
.main{flex:1;min-width:0}

/* ── Sidebar ── */
.sidebar-card{background:var(--white);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:12px}
.sidebar-hd{background:var(--navy);padding:10px 14px;font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:rgba(255,255,255,.75)}
.sidebar-body{padding:10px}
.hist-item{display:flex;align-items:center;justify-content:space-between;padding:7px 8px;border-radius:6px;cursor:pointer;text-decoration:none;color:var(--text1);transition:background .12s;font-size:11.5px;margin-bottom:2px}
.hist-item:hover{background:var(--purplePale)}
.hist-item.active{background:var(--purplePale);font-weight:600;color:var(--purple)}
.hist-date{font-size:10.5px;color:var(--text3)}
.status-pill{font-size:9px;font-weight:700;padding:2px 7px;border-radius:20px;text-transform:uppercase;letter-spacing:.04em;flex-shrink:0}
.pill-draft{background:#fef3c7;color:#92400e}
.pill-signed{background:var(--greenPale);color:var(--green)}
.pill-locked{background:#f3f4f6;color:var(--text3)}
.btn-new-form{display:flex;align-items:center;gap:7px;width:100%;padding:8px 10px;background:var(--purple);color:#fff;border:none;border-radius:7px;font-size:12.5px;font-weight:700;cursor:pointer;font-family:inherit;margin-bottom:10px;justify-content:center}
.btn-new-form:hover{filter:brightness(1.08)}

/* ── Title ── */
.title-row{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;gap:14px}
.title-h1{font-size:20px;font-weight:800;color:var(--navy);margin-bottom:3px}
.title-sub{font-size:12px;color:var(--text2)}
.prog-wrap{min-width:200px;padding-top:4px}
.prog-lbl{display:flex;justify-content:space-between;font-size:10px;margin-bottom:4px}
.prog-track{height:6px;border-radius:99px;background:var(--border);overflow:hidden}
.prog-fill{height:100%;border-radius:99px;transition:width .4s;background:var(--amber)}

/* ── Alerts ── */
.alert{padding:10px 16px;border-radius:7px;font-size:12.5px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.alert-ok{background:var(--greenPale);color:var(--green);border:1px solid #bbf7d0}
.alert-err{background:#fee2e2;color:var(--red);border:1px solid #fca5a5}
.alert-info{background:var(--purplePale);color:var(--purple);border:1px solid var(--purpleL)}

/* ── Section accordion ── */
.sec{border:1px solid var(--border);border-radius:10px;margin-bottom:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.sec-btn{width:100%;display:flex;align-items:center;gap:10px;padding:11px 16px;background:var(--white);border:none;cursor:pointer;transition:background .12s;text-align:left}
.sec-btn:hover{background:#f5f5ff}
.sec-btn.open{background:var(--navyMid)}
.sec-num{width:22px;height:22px;border-radius:5px;background:var(--purple);color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sec-title{font-size:12.5px;font-weight:600;color:var(--text1);flex:1}
.sec-btn.open .sec-title,.sec-btn.open .sec-chev{color:#fff !important}
.sec-done{font-size:9.5px;font-weight:700;color:var(--green);background:var(--greenPale);border-radius:20px;padding:2px 8px;display:inline-flex;align-items:center;gap:3px}
.sec-chev{color:var(--text3);font-size:16px;line-height:1;transition:transform .18s;flex-shrink:0}
.sec-btn.open .sec-chev{transform:rotate(180deg)}
.sec-body{padding:16px 18px;border-top:1px solid var(--border);background:var(--white)}
.sec-body.view-mode{background:#fafbff}

/* ── Grid helpers ── */
.row{display:grid;gap:12px;margin-bottom:12px}
.r2{grid-template-columns:1fr 1fr}
.r3{grid-template-columns:1fr 1fr 1fr}
.r4{grid-template-columns:1fr 1fr 1fr 1fr}
.r5{grid-template-columns:repeat(5,1fr)}
.r13{grid-template-columns:2fr 2fr 1fr 1fr}
.r21{grid-template-columns:2fr 1fr}
.r12{grid-template-columns:1fr 2fr}

/* ── Form controls ── */
.lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);display:block;margin-bottom:4px}
.req{color:var(--red);margin-left:2px}
input[type=text],input[type=date],input[type=number],select,textarea{
  width:100%;padding:7px 9px;border:1px solid var(--border);border-radius:6px;font-size:12.5px;
  font-family:inherit;color:var(--text1);background:var(--white);outline:none;transition:border-color .15s;resize:vertical}
input:focus,select:focus,textarea:focus{border-color:var(--purple);box-shadow:0 0 0 3px rgba(99,102,241,.12)}
input[readonly],textarea[readonly],select[disabled]{background:#f9fafb;color:var(--text2);cursor:default;border-color:#f0f0f0}
.chk-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:3px 0}
.chk-row{display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer;padding:2px 0;user-select:none}
.chk-row input[type=checkbox],.chk-row input[type=radio]{accent-color:var(--purple);width:13px;height:13px;cursor:pointer;flex-shrink:0}
.radio-row{display:flex;gap:14px;margin-top:5px}
.radio-item{display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer;user-select:none}
.radio-item input{accent-color:var(--purple);width:13px;height:13px;cursor:pointer}
.pain-slider-row{display:flex;align-items:center;gap:12px;margin-top:4px}
input[type=range]{flex:1;accent-color:var(--purple);cursor:pointer;height:4px}
.pain-num{font-size:22px;font-weight:800;min-width:28px;text-align:center;font-family:'DM Mono',monospace;transition:color .2s}
.pain-scale{display:flex;justify-content:space-between;font-size:10px;color:var(--text3);margin-top:2px}
.prev-care{display:grid;grid-template-columns:1fr 1fr 1fr 1fr 1.5fr;gap:12px;align-items:start;margin-top:4px}
.prev-lbl-sm{font-size:10.5px;color:var(--text3);margin-bottom:5px}
.grp-hdr{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--text3);padding:14px 0 5px;border-bottom:1px solid var(--border);margin-bottom:8px}
.view-value{font-size:13px;color:var(--text1);padding:7px 0;min-height:26px;border-bottom:1px solid #f0f0f0;word-break:break-word}
.view-value.empty{color:var(--text3);font-style:italic}

/* ── Signed banner ── */
.signed-banner{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;margin-bottom:14px;font-size:12.5px;color:var(--green);font-weight:600}

/* ── Footer ── */
.footer{position:fixed;bottom:0;left:0;right:0;background:var(--white);border-top:1px solid var(--border);padding:10px 22px;display:flex;align-items:center;justify-content:space-between;z-index:100}
.btn-ghost{padding:8px 16px;border:1.5px solid var(--border);border-radius:7px;font-size:12.5px;font-weight:600;color:var(--text2);background:var(--white);cursor:pointer;font-family:inherit}
.btn-outline{padding:8px 16px;border:1.5px solid var(--purple);border-radius:7px;font-size:12.5px;font-weight:600;color:var(--purple);background:var(--white);cursor:pointer;font-family:inherit}
.btn-primary{padding:8px 18px;background:var(--purple);border:none;border-radius:7px;font-size:12.5px;font-weight:700;color:#fff;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px}
.btn-sign{padding:8px 18px;background:var(--green);border:none;border-radius:7px;font-size:12.5px;font-weight:700;color:#fff;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:7px}
.btn-edit{padding:8px 16px;border:1.5px solid var(--amber);border-radius:7px;font-size:12.5px;font-weight:600;color:var(--amber);background:var(--white);cursor:pointer;font-family:inherit}
.btn-primary:hover,.btn-sign:hover{filter:brightness(1.08)}
.btn-ghost:hover,.btn-outline:hover,.btn-edit:hover{opacity:.85}
</style>
</head>
<body>

<!-- ── Header ── -->
<div class="hdr">
  <div class="hdr-logo">
    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M4 19V9l8-7 8 7v10"/><rect x="9" y="14" width="6" height="5" rx="1"/></svg>
  </div>
  <span class="hdr-brand">MDiTek</span>
  <span class="hdr-sub">EHR Platform</span>
  <div class="hdr-right">
    <span class="hdr-user"><?php echo text($_SESSION['authUser'] ?? ''); ?></span>
  </div>
</div>

<!-- ── Patient bar ── -->
<div class="pat-bar">
  <div class="pat-avatar"><?php echo text(substr($patient['lname']??'',0,1) . substr($patient['fname']??'',0,1)); ?></div>
  <div>
    <div class="pat-name"><?php echo text($pat_name); ?></div>
    <div class="pat-meta">DOB: <?php echo text($pat_dob); ?> · <?php echo text($pat_sex); ?> · Chart #<?php echo text($pat_chart); ?></div>
  </div>
  <div style="margin-left:auto;text-align:right">
    <div class="pat-date-lbl"><?php echo xlt("Today's Date"); ?></div>
    <div class="pat-date-val"><?php echo text($today); ?></div>
  </div>
</div>

<div class="layout">

  <!-- ── Sidebar ── -->
  <div class="sidebar">
    <div class="sidebar-card">
      <div class="sidebar-hd"><?php echo xlt("Patient Forms"); ?></div>
      <div class="sidebar-body">
        <a href="patient_intake.php?pid=<?php echo attr($pid); ?>" class="btn-new-form">
          <svg width="13" height="13" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
          <?php echo xlt("New Intake Form"); ?>
        </a>
        <?php if (empty($prior_forms)): ?>
          <div style="font-size:11px;color:var(--text3);text-align:center;padding:10px 0"><?php echo xlt("No forms on file."); ?></div>
        <?php else: ?>
          <?php foreach ($prior_forms as $pf): ?>
            <?php
              $is_active = ((int)$pf['id'] === $form_id);
              $pill_cls  = 'pill-' . $pf['status'];
              $date_str  = substr($pf['updated_at'] ?? $pf['created_at'], 0, 10);
              $view_url  = 'patient_intake.php?pid=' . attr($pid) . '&form_id=' . attr($pf['id'])
                         . ($pf['status'] === 'draft' ? '' : '&mode=view');
            ?>
            <a href="<?php echo $view_url; ?>" class="hist-item <?php echo $is_active ? 'active' : ''; ?>">
              <div>
                <div style="font-weight:600"><?php echo xlt("Intake Form"); ?></div>
                <div class="hist-date"><?php echo text($date_str); ?></div>
              </div>
              <span class="status-pill <?php echo $pill_cls; ?>"><?php echo text($pf['status']); ?></span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Main ── -->
  <div class="main">

    <?php if ($save_success): ?>
      <div class="alert alert-ok">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <?php echo text($save_success); ?>
      </div>
    <?php endif; ?>

    <?php if ($save_error): ?>
      <div class="alert alert-err">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?php echo text($save_error); ?>
      </div>
    <?php endif; ?>

    <?php if ($is_signed): ?>
      <div class="signed-banner">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <?php echo xlt("This form has been signed."); ?>
        <?php if (!empty($f['signed_at'])): ?>
          <?php echo text($f['signed_at']); ?>
        <?php endif; ?>
        <?php if (!$is_view): ?>
          — <a href="patient_intake.php?pid=<?php echo attr($pid); ?>&form_id=<?php echo attr($form_id); ?>&mode=edit" style="color:var(--green);font-weight:700;"><?php echo xlt("Edit anyway"); ?></a>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Title row -->
    <div class="title-row">
      <div>
        <div class="title-h1"><?php echo $form_id ? xlt("Patient Intake Form") : xlt("New Patient Intake"); ?></div>
        <div class="title-sub">
          <?php echo $is_view ? xlt("Read-only view.") : xlt("Complete all sections. Click section headers to expand."); ?>
          <?php if ($form_id): ?> · ID #<?php echo text($form_id); ?><?php endif; ?>
        </div>
      </div>
      <div class="prog-wrap">
        <div class="prog-lbl">
          <span id="progLabel" style="color:var(--text3)">0/22</span>
          <span id="progPct" style="font-weight:700;color:var(--amber)">0%</span>
        </div>
        <div class="prog-track"><div class="prog-fill" id="progFill" style="width:0%"></div></div>
      </div>
    </div>

    <form method="post" id="intakeForm"
      action="patient_intake.php?pid=<?php echo attr($pid); ?><?php echo $form_id ? '&form_id='.attr($form_id) : ''; ?>">
      <input type="hidden" name="csrf_token_form" value="<?php echo $csrf; ?>">
      <input type="hidden" name="action" id="formAction" value="draft">

      <?php
      // ──────────────────────────────────────────────────────────────────────────
      // Section renderer helper
      // ──────────────────────────────────────────────────────────────────────────
      function secOpen($num, $title, $accent = null) {
          global $form_id;
          $accentStyle = $accent ? "background:{$accent}" : '';
          // Auto-open §1, and any section that has content
          $auto_open_js = "checkAutoOpen({$num})";
          echo "<div class='sec' id='sec{$num}'>";
          echo "<button type='button' class='sec-btn' id='secbtn{$num}' onclick='toggleSec({$num})'>";
          echo "<span class='sec-num' style='{$accentStyle}'>{$num}</span>";
          echo "<span class='sec-title'>" . xlt($title) . "</span>";
          echo "<span class='sec-done' id='done{$num}' style='display:none'>&#10003; " . xlt("Done") . "</span>";
          echo "<span class='sec-chev'>&#8964;</span>";
          echo "</button>";
          echo "<div class='sec-body' id='body{$num}' style='display:none'>";
      }
      function secClose() { echo "</div></div>"; }

      function inp($name, $placeholder = '', $type = 'text', $extra = '') {
          global $f, $is_view;
          $val = htmlspecialchars($f[$name] ?? '', ENT_QUOTES);
          $ro  = $is_view ? 'readonly' : '';
          return "<input type='{$type}' name='{$name}' id='{$name}' value='{$val}' placeholder='" . htmlspecialchars($placeholder, ENT_QUOTES) . "' {$ro} {$extra} onchange='updateProg()'>";
      }
      function ta($name, $placeholder = '', $rows = 3) {
          global $f, $is_view;
          $val = htmlspecialchars($f[$name] ?? '', ENT_QUOTES);
          $ro  = $is_view ? 'readonly' : '';
          return "<textarea name='{$name}' id='{$name}' rows='{$rows}' placeholder='" . htmlspecialchars($placeholder, ENT_QUOTES) . "' {$ro} onchange='updateProg()'>{$val}</textarea>";
      }
      function chk($name, $label) {
          global $f, $is_view;
          $checked = !empty($f[$name]) ? 'checked' : '';
          $dis     = $is_view ? 'disabled' : '';
          return "<label class='chk-row'><input type='checkbox' name='{$name}' {$checked} {$dis} onchange='updateProg()'> " . xlt($label) . "</label>";
      }
      function radioYN($name, $yes_label = 'Yes', $no_label = 'No') {
          global $f, $is_view;
          $dis  = $is_view ? 'disabled' : '';
          $chkY = isset($f[$name]) && $f[$name] == 1 ? 'checked' : '';
          $chkN = isset($f[$name]) && $f[$name] == 0 ? 'checked' : '';
          return "<div class='radio-row'>
            <label class='radio-item'><input type='radio' name='{$name}' value='yes' {$chkY} {$dis} onchange='updateProg()'> " . xlt($yes_label) . "</label>
            <label class='radio-item'><input type='radio' name='{$name}' value='no'  {$chkN} {$dis} onchange='updateProg()'> " . xlt($no_label) . "</label>
          </div>";
      }
      function radioField($name, $options) {
          global $f, $is_view;
          $dis = $is_view ? 'disabled' : '';
          $cur = $f[$name] ?? '';
          $out = "<div class='radio-row'>";
          foreach ($options as $val => $lbl) {
              $ck = ($cur === $val) ? 'checked' : '';
              $out .= "<label class='radio-item'><input type='radio' name='{$name}' value='" . attr($val) . "' {$ck} {$dis} onchange='updateProg()'> " . xlt($lbl) . "</label>";
          }
          return $out . "</div>";
      }
      function label($txt, $required = false) {
          echo "<label class='lbl'>" . xlt($txt) . ($required ? "<span class='req'>*</span>" : "") . "</label>";
      }
      function row($cols = 'r2') { echo "<div class='row {$cols}'>"; }
      function endrow() { echo "</div>"; }
      function field($open = true) { echo $open ? "<div class='field'>" : "</div>"; }
      ?>

      <!-- ═══════════════════════════════════════════════════════════════
           § 1  History of Present Illness
      ═══════════════════════════════════════════════════════════════ -->
      <?php secOpen(1, "History of Present Illness / Case History"); ?>

        <div class="row r13">
          <div class="field"><?php label("Chief Complaint", true); echo ta("chief_complaint", "Primary reason for today's visit", 2); ?></div>
          <div class="field"><?php label("Affected Areas", true); echo ta("affected_areas", "e.g., Lower back, Neck, Left shoulder", 2); ?></div>
          <div class="field"><?php label("Onset Date", true); echo inp("onset_date", "", "date"); ?></div>
          <div class="field"><?php label("Onset Type", true); echo radioField("onset_type", ['sudden'=>'Sudden','gradual'=>'Gradual']); ?></div>
        </div>

        <div class="row r3">
          <div class="field">
            <?php label("Pain Level (0–10)", true); ?>
            <?php $pl = intval($f['pain_level'] ?? 5); $pc = $pl<=3?'#16a34a':($pl<=6?'#d97706':'#dc2626'); ?>
            <div style="margin-top:4px">
              <div class="pain-slider-row">
                <input type="range" min="0" max="10" value="<?php echo $pl; ?>" name="pain_level" id="pain_level"
                  oninput="updatePain(this)" <?php echo $is_view?'disabled':''; ?> style="flex:1">
                <span class="pain-num" id="pain_num" style="color:<?php echo $pc; ?>"><?php echo $pl; ?></span>
              </div>
              <div class="pain-scale"><span>0 None</span><span>5 Moderate</span><span>10 Worst</span></div>
            </div>
          </div>
          <div class="field">
            <?php label("Pain Description", true); ?>
            <div class="chk-grid">
              <?php echo chk("pain_sharp","Sharp"); echo chk("pain_dull","Dull"); echo chk("pain_burning","Burning"); ?>
              <?php echo chk("pain_aching","Aching"); echo chk("pain_throbbing","Throbbing"); echo chk("pain_tingling","Tingling"); ?>
            </div>
            <div style="display:flex;align-items:center;gap:7px;margin-top:4px">
              <?php
                $has_other_pain = !empty($f['pain_other']);
                $dis = $is_view ? 'disabled' : '';
                echo "<label class='chk-row'><input type='checkbox' id='po_chk' " . ($has_other_pain?'checked':'') . " {$dis} onchange=\"toggleShow('po_chk','pain_other_row')\"> " . xlt("Other") . "</label>";
              ?>
              <span id="pain_other_row" style="display:<?php echo $has_other_pain?'flex':'none'; ?>;flex:1">
                <?php echo inp("pain_other", xlt("Describe")); ?>
              </span>
            </div>
          </div>
          <div class="field">
            <?php label("Does pain travel?", true); ?>
            <?php
              $pt_val = isset($f['pain_travels']) ? ($f['pain_travels']==1?'yes':'no') : '';
              echo radioField("pain_travels", ['yes'=>'Yes','no'=>'No']);
              $show_travel = ($f['pain_travels'] ?? null) == 1;
            ?>
            <div id="travel_row" style="margin-top:8px;display:<?php echo $show_travel?'block':'none'; ?>">
              <?php label("Where does it travel?"); echo inp("pain_travels_where", xlt("e.g., Down left leg")); ?>
            </div>
          </div>
        </div>

        <div class="row r3">
          <div class="field">
            <?php label("Mechanism of Injury"); ?>
            <?php echo chk("moi_auto_accident","Auto accident"); echo chk("moi_work_injury","Work injury"); ?>
            <?php echo chk("moi_sports_injury","Sports injury"); echo chk("moi_fall","Fall"); ?>
            <?php echo chk("moi_no_known","No known injury"); ?>
            <div style="display:flex;align-items:center;gap:7px;margin-top:3px">
              <?php $has_moi = !empty($f['moi_other']); ?>
              <label class='chk-row'><input type='checkbox' id='mo_chk' <?php echo $has_moi?'checked':''; ?> <?php echo $is_view?'disabled':''; ?> onchange="toggleShow('mo_chk','moi_other_row')"> <?php echo xlt("Other"); ?></label>
              <span id="moi_other_row" style="display:<?php echo $has_moi?'flex':'none'; ?>;flex:1"><?php echo inp("moi_other",""); ?></span>
            </div>
          </div>
          <div class="field">
            <?php label("Aggravating Factors"); ?>
            <div class="chk-grid">
              <?php echo chk("agg_sitting","Sitting"); echo chk("agg_standing","Standing"); echo chk("agg_walking","Walking"); ?>
              <?php echo chk("agg_bending","Bending"); echo chk("agg_lifting","Lifting"); echo chk("agg_sleeping","Sleeping"); ?>
            </div>
            <div style="display:flex;align-items:center;gap:7px;margin-top:3px">
              <?php $has_agg = !empty($f['agg_other']); ?>
              <label class='chk-row'><input type='checkbox' id='ag_chk' <?php echo $has_agg?'checked':''; ?> <?php echo $is_view?'disabled':''; ?> onchange="toggleShow('ag_chk','agg_other_row')"> <?php echo xlt("Other"); ?></label>
              <span id="agg_other_row" style="display:<?php echo $has_agg?'flex':'none'; ?>;flex:1"><?php echo inp("agg_other",""); ?></span>
            </div>
          </div>
          <div class="field">
            <?php label("Relieving Factors"); ?>
            <?php echo chk("rel_rest","Rest"); echo chk("rel_ice","Ice"); echo chk("rel_heat","Heat"); ?>
            <?php echo chk("rel_stretching","Stretching"); echo chk("rel_medication","Medication"); ?>
            <div style="display:flex;align-items:center;gap:7px;margin-top:3px">
              <?php $has_rel = !empty($f['rel_other']); ?>
              <label class='chk-row'><input type='checkbox' id='re_chk' <?php echo $has_rel?'checked':''; ?> <?php echo $is_view?'disabled':''; ?> onchange="toggleShow('re_chk','rel_other_row')"> <?php echo xlt("Other"); ?></label>
              <span id="rel_other_row" style="display:<?php echo $has_rel?'flex':'none'; ?>;flex:1"><?php echo inp("rel_other",""); ?></span>
            </div>
          </div>
        </div>

        <div>
          <?php label("Previous Care"); ?>
          <div class="prev-care">
            <div><div class="prev-lbl-sm"><?php echo xlt("Other provider?"); ?></div><?php echo radioYN("prev_other_provider"); ?></div>
            <div><div class="prev-lbl-sm"><?php echo xlt("Chiropractic care?"); ?></div><?php echo radioYN("prev_chiro"); ?></div>
            <div><div class="prev-lbl-sm"><?php echo xlt("Physical therapy?"); ?></div><?php echo radioYN("prev_physical_therapy"); ?></div>
            <div>
              <div class="prev-lbl-sm"><?php echo xlt("Imaging performed?"); ?></div>
              <?php echo radioYN("prev_imaging"); ?>
            </div>
            <div id="img_type_field" style="display:<?php echo !empty($f['prev_imaging']) && $f['prev_imaging']==1 ? 'block':'none'; ?>">
              <?php label("Imaging type"); echo inp("prev_imaging_type", xlt("e.g., X-Ray, MRI")); ?>
            </div>
          </div>
        </div>

      <?php secClose(); ?>

      <!-- § 2 -->
      <?php secOpen(2, "Functional Assessment"); ?>
        <div class="row r2">
          <div class="field"><?php label("Functional Notes"); echo ta("functional_notes","ADL limitations, functional deficits...",4); ?></div>
          <div class="field"><?php label("Oswestry / NDI Score (0–100)"); echo inp("oswestry_score","","number","min='0' max='100'"); ?></div>
        </div>
      <?php secClose(); ?>

      <!-- § 3 -->
      <?php secOpen(3, "Chief Complaint (Summary)"); ?>
        <?php label("Narrative Summary"); echo ta("complaint_summary","Summarize the chief complaint in narrative form...",5); ?>
      <?php secClose(); ?>

      <!-- § 4 -->
      <?php secOpen(4, "Examination"); ?>
        <div class="row r5">
          <?php foreach(['bp'=>'B/P','pulse'=>'Pulse','temp'=>'Temp','height'=>'Height','weight'=>'Weight'] as $k=>$l): ?>
          <div class="field"><?php label($l); echo inp($k, "—"); ?></div>
          <?php endforeach; ?>
        </div>
        <?php label("Examination Notes"); echo ta("exam_notes","Palpation, orthopedic tests, neurological findings...",5); ?>
      <?php secClose(); ?>

      <!-- § 5 -->
      <?php secOpen(5, "Objective"); ?>
        <div class="row r3">
          <div class="field"><?php label("Cervical ROM"); echo ta("rom_cervical","Flex/Ext/LB/LF/Rot...",3); ?></div>
          <div class="field"><?php label("Lumbar ROM"); echo ta("rom_lumbar","Flex/Ext/LB/LF/Rot...",3); ?></div>
          <div class="field"><?php label("Thoracic ROM"); echo ta("rom_thoracic","Flex/Ext/Rot...",3); ?></div>
        </div>
        <?php label("Posture Notes"); echo ta("posture_notes","Postural analysis, spinal analysis...",3); ?>
        <div style="margin-top:10px"><?php label("Additional Objective Notes"); echo ta("objective_notes","",3); ?></div>
      <?php secClose(); ?>

      <!-- § 6 -->
      <?php secOpen(6, "Assessment"); ?>
        <div class="row r2">
          <div class="field"><?php label("Assessment / Clinical Impression"); echo ta("assessment_notes","Clinical findings, diagnoses...",5); ?></div>
          <div class="field"><?php label("Diagnosis Codes (ICD-10, one per line)"); echo ta("diagnosis_codes","M54.5\nM50.12\nM99.01",5); ?></div>
        </div>
      <?php secClose(); ?>

      <!-- § 7 -->
      <?php secOpen(7, "Plan"); ?>
        <div class="row r3">
          <div class="field"><?php label("Visits / Week"); echo inp("visits_per_week","3","number","min='1' max='7'"); ?></div>
          <div class="field"><?php label("Weeks of Care"); echo inp("weeks_of_care","8","number","min='1'"); ?></div>
          <div class="field"><?php label("Plan Notes"); echo ta("plan_notes","Treatment plan details...",2); ?></div>
        </div>
      <?php secClose(); ?>

      <!-- § 8 -->
      <?php secOpen(8, "Treatment"); ?>
        <div class="row r2">
          <div class="field"><?php label("Treatment Performed (CPT)"); echo ta("treatment_performed","98940, 97012, 97035...",3); ?></div>
          <div class="field"><?php label("Treatment Notes"); echo ta("treatment_notes","Details of treatment rendered...",3); ?></div>
        </div>
      <?php secClose(); ?>

      <!-- § 9 -->
      <?php secOpen(9, "Short / Long Term Treatment Goals"); ?>
        <div class="row r2">
          <div class="field"><?php label("Short Term Goals (4 weeks)"); echo ta("goals_short_term","Goals within 4 weeks...",4); ?></div>
          <div class="field"><?php label("Long Term Goals (discharge)"); echo ta("goals_long_term","Goals at discharge...",4); ?></div>
        </div>
      <?php secClose(); ?>

      <!-- § 10 -->
      <?php secOpen(10, "X-Ray Exam"); ?>
        <?php
          $xray_taken = !empty($f['xray_taken']);
          $dis = $is_view ? 'disabled' : '';
        ?>
        <label class="chk-row" style="margin-bottom:10px">
          <input type="checkbox" name="xray_taken" id="xray_taken" <?php echo $xray_taken?'checked':''; ?> <?php echo $dis; ?> onchange="toggleXray(this.checked)">
          <?php echo xlt("X-Rays taken at this visit"); ?>
        </label>
        <div id="xray_fields" style="display:<?php echo $xray_taken?'block':'none'; ?>">
          <div class="row r2">
            <div class="field"><?php label("X-Ray Date"); echo inp("xray_date","","date"); ?></div>
            <div class="field"><?php label("Findings"); echo ta("xray_findings","X-ray findings and interpretation...",3); ?></div>
          </div>
        </div>
      <?php secClose(); ?>

      <!-- ── Established Patient ── -->
      <div class="grp-hdr"><?php echo xlt("Established Patient Sections"); ?></div>
      <?php
      $ep_sections = [
        [11,'ep_chief_complaint','Established Patient — Chief Complaint'],
        [12,'ep_objective',      'Established Patient — Objective'],
        [13,'ep_assessment',     'Established Patient — Assessment'],
        [14,'ep_plan',           'Established Patient — Plan'],
        [15,'ep_treatment',      'Established Patient — Treatment'],
      ];
      foreach ($ep_sections as [$num,$key,$title]):
        secOpen($num, $title, '#2d3461');
        label(explode('— ',$title)[1] . " Notes");
        echo ta($key, "", 5);
        secClose();
      endforeach;
      ?>

      <!-- ── Auto / Work ── -->
      <div class="grp-hdr"><?php echo xlt("Auto / Work"); ?></div>

      <?php secOpen(16, "Auto Accident", '#b45309'); ?>
        <div class="row r3">
          <div class="field"><?php label("Date of Accident"); echo inp("aa_date_of_accident","","date"); ?></div>
          <div class="field"><?php label("Claim Number"); echo inp("aa_claim_number",xlt("Claim #")); ?></div>
          <div class="field"><?php label("Attorney"); echo inp("aa_attorney",xlt("Attorney name")); ?></div>
        </div>
        <?php label("Description"); echo ta("aa_description","Description of accident...",4); ?>
      <?php secClose(); ?>

      <?php secOpen(17, "Worker's Compensation", '#b45309'); ?>
        <div class="row r3">
          <div class="field"><?php label("Date of Injury"); echo inp("wc_date_of_injury","","date"); ?></div>
          <div class="field"><?php label("Employer"); echo inp("wc_employer",xlt("Employer name")); ?></div>
          <div class="field"><?php label("Claim Number"); echo inp("wc_claim_number",xlt("Claim #")); ?></div>
        </div>
        <?php label("Notes"); echo ta("wc_notes","",4); ?>
      <?php secClose(); ?>

      <!-- ── Miscellaneous ── -->
      <div class="grp-hdr"><?php echo xlt("Miscellaneous"); ?></div>

      <?php secOpen(18, "Exercise Program", '#0369a1'); ?>
        <?php echo ta("exercise_program","Home exercise program instructions...",5); ?>
      <?php secClose(); ?>

      <?php secOpen(19, "Supplements", '#0369a1'); ?>
        <?php echo ta("supplements","Supplement recommendations...",4); ?>
      <?php secClose(); ?>

      <!-- ── Other Exams ── -->
      <div class="grp-hdr"><?php echo xlt("Other Exams"); ?></div>

      <?php secOpen(20, "Complete Neuro", '#6d28d9'); ?>
        <div class="row r2">
          <div class="field"><?php label("Reflexes"); echo ta("neuro_reflexes","DTRs, pathological reflexes...",3); ?></div>
          <div class="field"><?php label("Sensation"); echo ta("neuro_sensation","Dermatomal sensory testing...",3); ?></div>
          <div class="field"><?php label("Motor"); echo ta("neuro_motor","Myotomal motor testing...",3); ?></div>
          <div class="field"><?php label("General Notes"); echo ta("neuro_notes","",3); ?></div>
        </div>
      <?php secClose(); ?>

      <?php secOpen(21, "Upper Extremities", '#6d28d9'); ?>
        <div class="row r3">
          <div class="field"><?php label("Notes"); echo ta("upper_ext_notes","",3); ?></div>
          <div class="field"><?php label("ROM"); echo ta("upper_ext_rom","Shoulder, elbow, wrist ROM...",3); ?></div>
          <div class="field"><?php label("Strength"); echo ta("upper_ext_strength","Grip, muscle testing...",3); ?></div>
        </div>
      <?php secClose(); ?>

      <?php secOpen(22, "Lower Extremities", '#6d28d9'); ?>
        <div class="row r3">
          <div class="field"><?php label("Notes"); echo ta("lower_ext_notes","",3); ?></div>
          <div class="field"><?php label("ROM"); echo ta("lower_ext_rom","Hip, knee, ankle ROM...",3); ?></div>
          <div class="field"><?php label("Strength"); echo ta("lower_ext_strength","SLR, muscle testing...",3); ?></div>
        </div>
      <?php secClose(); ?>

    </form><!-- /intakeForm -->
  </div><!-- /main -->
</div><!-- /layout -->

<!-- ── Footer ── -->
<div class="footer">
  <a href="patient_intake.php?pid=<?php echo attr($pid); ?>" class="btn-ghost"><?php echo xlt("Cancel"); ?></a>
  <div style="display:flex;gap:10px;align-items:center">
    <?php if ($is_view && !$is_view): // view mode edit button
    ?>
      <a href="patient_intake.php?pid=<?php echo attr($pid); ?>&form_id=<?php echo attr($form_id); ?>&mode=edit" class="btn-edit">
        <?php echo xlt("Edit Form"); ?>
      </a>
    <?php endif; ?>
    <?php if (!$is_view): ?>
      <button type="button" class="btn-outline" onclick="submitForm('draft')">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:4px"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
        <?php echo xlt("Save Draft"); ?>
      </button>
      <?php if (!$is_signed): ?>
        <button type="button" class="btn-sign" onclick="confirmSign()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:4px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <?php echo xlt("Sign Form"); ?>
        </button>
      <?php endif; ?>
    <?php else: ?>
      <a href="patient_intake.php?pid=<?php echo attr($pid); ?>&form_id=<?php echo attr($form_id); ?>&mode=edit" class="btn-edit">
        &#9998; <?php echo xlt("Edit Form"); ?>
      </a>
    <?php endif; ?>
  </div>
</div>

<script>
// ── Section toggle ────────────────────────────────────────────────────────────
function toggleSec(n) {
  var btn  = document.getElementById('secbtn' + n);
  var body = document.getElementById('body' + n);
  if (!body) return;
  var open = body.style.display !== 'none';
  body.style.display = open ? 'none' : 'block';
  btn.classList.toggle('open', !open);
}

// ── Conditional show/hide ─────────────────────────────────────────────────────
function toggleShow(chkId, targetId) {
  var chk = document.getElementById(chkId);
  var el  = document.getElementById(targetId);
  if (el) el.style.display = chk.checked ? 'flex' : 'none';
}

// pain_travels radios → show/hide where field
document.querySelectorAll('input[name="pain_travels"]').forEach(function(r) {
  r.addEventListener('change', function() {
    document.getElementById('travel_row').style.display = this.value === 'yes' ? 'block' : 'none';
  });
});

// prev_imaging radios → show/hide imaging type
document.querySelectorAll('input[name="prev_imaging"]').forEach(function(r) {
  r.addEventListener('change', function() {
    document.getElementById('img_type_field').style.display = this.value === 'yes' ? 'block' : 'none';
  });
});

function toggleXray(checked) {
  document.getElementById('xray_fields').style.display = checked ? 'block' : 'none';
}

// ── Pain slider ───────────────────────────────────────────────────────────────
function updatePain(el) {
  var v = parseInt(el.value);
  var c = v <= 3 ? '#16a34a' : v <= 6 ? '#d97706' : '#dc2626';
  document.getElementById('pain_num').textContent  = v;
  document.getElementById('pain_num').style.color  = c;
  el.style.accentColor = c;
}

// ── Progress tracker ──────────────────────────────────────────────────────────
var doneSecs = new Set();

function updateProg() {
  // §1 has required fields
  var cc = document.getElementById('chief_complaint');
  var aa = document.getElementById('affected_areas');
  var od = document.getElementById('onset_date');
  if (cc && aa && od && cc.value.trim() && aa.value.trim() && od.value) markDone(1);

  // All other sections: mark done if any textarea/input has content
  for (var n = 2; n <= 22; n++) {
    var body = document.getElementById('body' + n);
    if (!body) continue;
    var els = body.querySelectorAll('textarea, input[type=text], input[type=number], input[type=date]');
    var hasContent = false;
    els.forEach(function(el) { if (el.value.trim()) hasContent = true; });
    if (!hasContent) {
      var chks = body.querySelectorAll('input[type=checkbox]:checked, input[type=radio]:checked');
      if (chks.length) hasContent = true;
    }
    if (hasContent) markDone(n);
  }

  var total = 22, done = doneSecs.size;
  var pct = Math.round((done / total) * 100);
  document.getElementById('progFill').style.width = pct + '%';
  document.getElementById('progPct').textContent  = pct + '%';
  document.getElementById('progLabel').textContent = done + '/22';
  var color = pct < 40 ? '#d97706' : pct < 80 ? '#6366f1' : '#16a34a';
  document.getElementById('progFill').style.background = color;
  document.getElementById('progPct').style.color = color;
}

function markDone(n) {
  doneSecs.add(n);
  var badge = document.getElementById('done' + n);
  if (badge) badge.style.display = 'inline-flex';
  updateProg();
}

// ── Auto-open sections that have saved content ────────────────────────────────
function checkAutoOpen(n) {
  var body = document.getElementById('body' + n);
  if (!body) return;
  var els = body.querySelectorAll('textarea, input[type=text], input[type=number], input[type=date]');
  var has = false;
  els.forEach(function(el) { if (el.value.trim()) has = true; });
  if (!has) {
    var chks = body.querySelectorAll('input[type=checkbox]:checked, input[type=radio]:checked');
    if (chks.length) has = true;
  }
  if (has || n === 1) {
    body.style.display = 'block';
    document.getElementById('secbtn' + n).classList.add('open');
    markDone(n);
  }
}

// ── Form submission ───────────────────────────────────────────────────────────
function submitForm(action) {
  document.getElementById('formAction').value = action;
  document.getElementById('intakeForm').submit();
}

function confirmSign() {
  if (confirm('<?php echo xlt("Sign and finalize this form? You can still edit it after signing."); ?>')) {
    submitForm('sign');
  }
}

// ── Init ──────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  // §1 always open
  document.getElementById('body1').style.display = 'block';
  document.getElementById('secbtn1').classList.add('open');

  // Auto-open sections with data and compute initial progress
  for (var n = 2; n <= 22; n++) checkAutoOpen(n);
  updateProg();
});
</script>
</body>
</html>
