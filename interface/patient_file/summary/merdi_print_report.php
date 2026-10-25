<?php
/**
 * mdi_print_report.php — MDiTek Encounter Print Report
 * Standalone page opened via window.open() from forms.php.
 * URL: /interface/patient_file/encounter/mdi_print_report.php?pid=X&encounter=Y
 */

// ── Standalone bootstrap ─────────────────────────────────────────────────
if (!defined('INCLUDED_IN_FORMS')) {
    require_once __DIR__ . '/../../globals.php';
    require_once $srcdir . '/patient.inc.php';
    require_once $srcdir . '/encounter.inc.php';

    if (empty($_SESSION['authUser'])) { http_response_code(401); exit('Unauthorized'); }

    $pid       = (int)($_GET['pid']       ?? $_SESSION['pid']       ?? 0);
    $encounter = (int)($_GET['encounter'] ?? $_SESSION['encounter'] ?? 0);
    if (!$pid || !$encounter) { exit('Missing pid or encounter.'); }

    // Encounter date
    $_er = sqlQuery("SELECT date FROM form_encounter WHERE pid=? AND encounter=? LIMIT 1", [$pid, $encounter]);
    $encounter_date = !empty($_er['date']) ? date('Y-m-d', strtotime($_er['date'])) : '';

    // Provider + Patient
    $providerIDres   = getProviderIdOfEncounter($encounter);
    $providerNameRes = getProviderName($providerIDres, false);
    $patientName     = getPatientFullNameAsString($pid);
    $patData         = getPatientData($pid);
    $encData         = sqlQuery("SELECT reason FROM form_encounter WHERE encounter=? AND pid=? LIMIT 1", [$encounter, $pid]) ?: [];

    // Facility
    $facilityRow = sqlQuery(
        "SELECT f.name, f.phone, f.street, f.city, f.state, f.postal_code
           FROM facility f JOIN form_encounter fe ON fe.facility_id = f.id
          WHERE fe.encounter=? AND fe.pid=? LIMIT 1", [$encounter, $pid]
    );
    if (empty($facilityRow)) {
        $facilityRow = sqlQuery("SELECT * FROM facility ORDER BY id LIMIT 1") ?: [];
    }

    // Vitals
    $v_form = sqlQuery(
        "SELECT * FROM forms WHERE pid=? AND encounter=? AND formdir='vitals' AND deleted=0 ORDER BY date DESC LIMIT 1",
        [$pid, $encounter]
    );
    $vitals_data = [];
    if (!empty($v_form['form_id'])) {
        $vitals_data = sqlQuery("SELECT * FROM form_vitals WHERE id=?", [$v_form['form_id']]) ?: [];
    }

    // Questionnaire forms
    $questionnaire_forms = [];
    $_qres = sqlStatement(
        "SELECT * FROM forms
          WHERE pid=? AND encounter=? AND formdir IN ('questionnaire_assessments','newpatient') AND deleted=0
          ORDER BY date ASC", [$pid, $encounter]
    );
    while ($_qr = sqlFetchArray($_qres)) { $questionnaire_forms[] = $_qr; }

    // Diagnoses
    $diagRowsUniq = []; $_sdx = [];
    $_dxr = sqlStatementNoLog(
        "SELECT b.code AS diagnosis, c.code_text AS title FROM billing b
           LEFT JOIN codes c ON c.code=b.code AND c.code_type=2
          WHERE b.pid=? AND b.encounter=? AND b.activity=1 AND b.code_type='ICD10' ORDER BY b.id",
        [$pid, $encounter]
    );
    if ($_dxr) {
        while ($_dr = sqlFetchArray($_dxr)) {
            if (!isset($_sdx[$_dr['diagnosis']])) { $_sdx[$_dr['diagnosis']] = true; $diagRowsUniq[] = $_dr; }
        }
    }

    // Primary insurance — use sqlStatementNoLog so HelpfulDie() never fires on column errors
    $primaryIns = null;
    // Step 1: get the insurance_data row safely (no JOIN, no risky columns)
    $_idr = sqlStatementNoLog(
        "SELECT * FROM insurance_data WHERE pid=? AND type='primary' ORDER BY id DESC LIMIT 1",
        [$pid]
    );
    if ($_idr) {
        $_id_row = sqlFetchArray($_idr);
        if ($_id_row) {
            $primaryIns = [
                'policy_number'           => $_id_row['policy_number']           ?? '',
                'group_number'            => $_id_row['group_number']            ?? '',
                'subscriber_relationship' => $_id_row['subscriber_relationship'] ?? '',
                'company_name'            => '',
                'plan_name'               => '',
            ];
            // Step 2: get company name separately
            $_ins_prov = (int)($_id_row['provider'] ?? 0);
            if ($_ins_prov) {
                $_icr = sqlStatementNoLog(
                    "SELECT * FROM insurance_companies WHERE id=? LIMIT 1",
                    [$_ins_prov]
                );
                if ($_icr) {
                    $_ic_row = sqlFetchArray($_icr);
                    if ($_ic_row) {
                        $primaryIns['company_name'] = $_ic_row['name']      ?? '';
                        $primaryIns['plan_name']    = $_ic_row['plan_name'] ?? '';
                    }
                }
            }
        }
    }

    // Accident info
    $has_box10 = false;
    $acc_auto = $acc_emp = $acc_other = $acc_date = $acc_state = '';
    $_ar = sqlStatementNoLog(
        "SELECT *
           FROM form_encounter WHERE encounter=? AND pid=? LIMIT 1", [$encounter, $pid]
    );
    if ($_ar) {
        $_arw = sqlFetchArray($_ar);
        if ($_arw) {
            $has_box10 = true;
            $acc_auto  = $_arw['auto_accident']      ?? '';
            $acc_emp   = $_arw['employment_related'] ?? '';
            $acc_other = $_arw['other_accident']     ?? '';
            $acc_date  = $_arw['date_onset']         ?? '';
            $acc_state = $_arw['state']              ?? '';
        }
    }

    // Imaging findings
    $imaging_findings_row = [];
    $_imr = sqlStatementNoLog(
        "SELECT findings, created_at FROM mdi_imaging_findings WHERE pid=? AND encounter=? ORDER BY id DESC LIMIT 1",
        [$pid, $encounter]
    );
    if ($_imr) { $imaging_findings_row = sqlFetchArray($_imr) ?: []; }

    // eSign
    $esignSignedAt = '';
    $esignSignerName = '';
    $_esr = sqlStatementNoLog(
        "SELECT es.datetime, CONCAT(u.fname,' ',u.lname) AS signer_name
           FROM esign_signatures es LEFT JOIN users u ON u.id=es.uid
          WHERE es.tid=? AND es.`table`='form_encounter' ORDER BY es.id DESC LIMIT 1",
        [$encounter]
    );
    if ($_esr) {
        $_esw = sqlFetchArray($_esr);
        if (!empty($_esw['datetime'])) {
            $esignSignedAt   = date('m/d/Y g:i A', strtotime($_esw['datetime']));
            $esignSignerName = trim($_esw['signer_name'] ?? '') ?: $providerNameRes;
        }
    }
}
// ── End standalone bootstrap ─────────────────────────────────────────────

// Helper functions — defined here (outside any if block) so always available
if (!function_exists('mdipr_esc')) {
    function mdipr_esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('mdipr_age')) {
    function mdipr_age($dob) {
        if (empty($dob) || $dob === '0000-00-00') return '';
        try { return (new DateTime($dob))->diff(new DateTime())->y; }
        catch (\Throwable $e) { return ''; }
    }
}
if (!function_exists('mdipr_v')) {
    function mdipr_v($val, $dec = 0) {
        $f = floatval($val ?? 0);
        return $f > 0 ? number_format($f, $dec) : '—';
    }
}

// When opened standalone, output a full HTML document wrapper
if (!defined('INCLUDED_IN_FORMS')):
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Encounter Report — <?php echo htmlspecialchars($patientName ?? '', ENT_QUOTES, 'UTF-8'); ?></title>
<style>
/* ── Print toolbar (screen only) ── */
#mdi-print-toolbar {
    position: fixed; top: 0; left: 0; right: 0; z-index: 9999;
    background: #1e0a3c; color: #fff;
    display: flex; align-items: center; gap: 12px;
    padding: 10px 20px; box-shadow: 0 2px 8px rgba(0,0,0,.3);
    font-family: system-ui, sans-serif; font-size: 13px;
}
#mdi-print-toolbar .tbar-title { font-weight: 700; flex: 1; }
#mdi-print-toolbar button {
    background: #7c3aed; color: #fff; border: none; border-radius: 6px;
    padding: 7px 18px; font-size: 13px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; gap: 7px; transition: background .15s;
}
#mdi-print-toolbar button:hover { background: #6d28d9; }
#mdi-print-toolbar .tbar-close {
    background: transparent; border: 1px solid rgba(255,255,255,.3);
    color: rgba(255,255,255,.7); font-weight: 400;
}
#mdi-print-toolbar .tbar-close:hover { background: rgba(255,255,255,.1); color: #fff; }
body { margin: 0; padding: 56px 0 0; background: #e5e7eb; }
#mdi-print-report { margin: 0 auto; }
@media print {
    #mdi-print-toolbar { display: none !important; }
    body { margin: 0; padding: 0; background: #fff; }
    @page { size: letter portrait; margin: 0; }
    .pr-pg {
        page-break-after: always !important;
        page-break-inside: avoid !important;
        break-after: page !important;
        width: 8.5in !important;
        min-height: 11in !important;
        max-height: 11in !important;
        overflow: hidden !important;
        box-sizing: border-box !important;
    }
    .pr-pg:last-child {
        page-break-after: auto !important;
        break-after: auto !important;
    }
}
</style>
</head>
<body>
<div id="mdi-print-toolbar">
    <span class="tbar-title">
        <?php echo htmlspecialchars($patientName ?? '', ENT_QUOTES, 'UTF-8'); ?>
        &mdash; Encounter Report
    </span>
    <button onclick="window.print()">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <polyline points="6 9 6 2 18 2 18 9"/>
            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
            <rect x="6" y="14" width="12" height="8"/>
        </svg>
        Print / Save PDF
    </button>
    <button class="tbar-close" onclick="window.close()">Close</button>
</div>
<?php endif; // end standalone HTML wrapper — rest of file is shared HTML output ?>
<?php
// ── Data processing ────────────────────────────────────────────────────────
$_enc_num  = 'E-' . str_pad((string)$encounter, 5, '0', STR_PAD_LEFT);
$_enc_date = !empty($encounter_date) ? date('m/d/Y', strtotime($encounter_date)) : '';
$_enc_time = '';
try {
    $_fe = sqlQuery("SELECT date FROM form_encounter WHERE encounter=? AND pid=? LIMIT 1", [$encounter, $pid]);
    if (!empty($_fe['date'])) $_enc_time = date('g:i A', strtotime($_fe['date']));
} catch (\Throwable $e) {}

// ── Facility ───────────────────────────────────────────────────────────────
$_fn   = $facilityRow['name']        ?? '';
$_fph  = $facilityRow['phone']       ?? '';
$_fc   = $facilityRow['city']        ?? '';
$_fs   = $facilityRow['state']       ?? '';
$_fz   = $facilityRow['postal_code'] ?? '';
$_fa   = trim("$_fc, $_fs $_fz");
if (in_array(trim($_fa, ' ,'), ['', ','], true)) $_fa = '';

// ── Patient ────────────────────────────────────────────────────────────────
$_pdob = !empty($patData['DOB']) ? date('Y-m-d', strtotime($patData['DOB'])) : '';
$_page = mdipr_age($patData['DOB'] ?? '');

// ── Vitals ─────────────────────────────────────────────────────────────────
$_pv   = !empty($vitals_data) ? $vitals_data : [];
$_vu   = $v_form['user'] ?? '';
$_vH   = (float)($_pv['height'] ?? 0) > 0 ? mdipr_v($_pv['height'], 0).'"' : '—';
$_vBMI = (float)($_pv['BMI']    ?? 0) > 0 ? mdipr_v($_pv['BMI'],    1)     : '—';
$_vBP  = ((float)($_pv['bps'] ?? 0) > 0 && (float)($_pv['bpd'] ?? 0) > 0)
           ? mdipr_v($_pv['bps'],0).'/'.mdipr_v($_pv['bpd'],0).' mmHg' : '—';
$_vPu  = (float)($_pv['pulse'] ?? 0) > 0 ? mdipr_v($_pv['pulse'], 0).' bpm' : '—';
$_vTe  = (float)($_pv['temp']  ?? 0) > 0 ? mdipr_v($_pv['temp'],  1).' °F'  : '—';
$_vO2  = (float)($_pv['oxygen_saturation'] ?? 0) > 0 ? mdipr_v($_pv['oxygen_saturation'], 0).'%' : '—';
$_vBr  = (float)($_pv['BMI']   ?? 0) > 0 ? mdipr_v($_pv['BMI'], 1) : '—';

// ── SOAP ───────────────────────────────────────────────────────────────────
$_soap = [];
try {
    $_sf = sqlQuery(
        "SELECT f.form_id FROM forms f WHERE f.pid=? AND f.encounter=? AND f.formdir='soap' AND f.deleted=0 ORDER BY f.date DESC LIMIT 1",
        [$pid, $encounter]
    );
    if (!empty($_sf['form_id']))
        $_soap = sqlQuery("SELECT * FROM form_soap WHERE id=?", [$_sf['form_id']]);
} catch (\Throwable $e) {}

// ── Accident ───────────────────────────────────────────────────────────────
$_al = '';
if (in_array(strtolower((string)($acc_auto  ?? '')), ['yes','1','y'], true)) $_al = 'Auto Accident';
elseif (in_array(strtolower((string)($acc_emp ?? '')), ['yes','1','y'], true)) $_al = 'Work Related';
elseif (in_array(strtolower((string)($acc_other ?? '')), ['yes','1','y'], true)) $_al = 'Other Accident';

// ── Questionnaire — query form_questionnaire_assessments directly ──────────
$_q = null;
$_qu = '';
try {
    // Get the form_id from forms table for this encounter
    $_qf = sqlQuery(
        "SELECT f.form_id, f.user FROM forms f
          WHERE f.pid=? AND f.encounter=? AND f.formdir='questionnaire_assessments' AND f.deleted=0
          ORDER BY f.date DESC LIMIT 1",
        [$pid, $encounter]
    );
    if (!empty($_qf['form_id'])) {
        $_qu = $_qf['user'] ?? '';
        $_q = sqlQuery("SELECT * FROM form_questionnaire_assessments WHERE id=?", [$_qf['form_id']]);
    }
    // Fallback: try newpatient formdir
    if (empty($_q)) {
        $_qf2 = sqlQuery(
            "SELECT f.form_id, f.user FROM forms f
              WHERE f.pid=? AND f.encounter=? AND f.formdir='newpatient' AND f.deleted=0
              ORDER BY f.date DESC LIMIT 1",
            [$pid, $encounter]
        );
        if (!empty($_qf2['form_id'])) {
            $_qu = $_qf2['user'] ?? '';
            // newpatient uses form_encounter_newpatient or similar — try common table names
            foreach (['form_newpatient', 'form_encounter_newpatient'] as $_qt) {
                try {
                    $_qtry = sqlQuery("SELECT * FROM $_qt WHERE id=?", [$_qf2['form_id']]);
                    if (!empty($_qtry)) { $_q = $_qtry; break; }
                } catch (\Throwable $e) {}
            }
        }
    }
} catch (\Throwable $e) { $_q = null; }

// ── Pain map ───────────────────────────────────────────────────────────────
$_pm = null;
$_pm_markers = [];   // array of {x, y} percentage positions
$_pm_notes   = [];   // array of "Region: note" strings
try {
    $_pm = sqlQuery(
        "SELECT * FROM form_painmap WHERE pid=? AND encounter=? AND activity=1
           ORDER BY id DESC LIMIT 1",
        [$pid, $encounter]
    );
    if (!empty($_pm['markers_json'])) {
        // markers_json may be base64-encoded JSON {dots:[...], notes:{...}}
        // or plain JSON array [{x,y,...},...]
        $raw = $_pm['markers_json'];
        // Try base64 decode first (report.php format)
        $decoded = json_decode(base64_decode($raw), true);
        if (is_array($decoded) && isset($decoded['dots'])) {
            // Format: {dots:[{region,x,y},...], notes:{idx:text,...}}
            foreach ($decoded['dots'] as $idx => $dot) {
                $x = floatval($dot['x'] ?? -1);
                $y = floatval($dot['y'] ?? -1);
                if ($x >= 0 && $x <= 100 && $y >= 0 && $y <= 100) {
                    $_pm_markers[] = ['x' => $x, 'y' => $y];
                }
                $region = $dot['region'] ?? ('Marker ' . ($idx + 1));
                $note   = trim($decoded['notes'][$idx] ?? $decoded['notes'][$region] ?? '');
                if ($note !== '') {
                    $_pm_notes[] = $region . ': ' . $note;
                }
            }
        } else {
            // Try plain JSON array [{x,y},...] fallback
            $plain = json_decode($raw, true);
            if (is_array($plain)) {
                foreach ($plain as $idx => $m) {
                    $x = floatval($m['x'] ?? -1);
                    $y = floatval($m['y'] ?? -1);
                    if ($x >= 0 && $x <= 100 && $y >= 0 && $y <= 100) {
                        $_pm_markers[] = ['x' => $x, 'y' => $y];
                    }
                    $note = trim($m['note'] ?? $m['notes'] ?? '');
                    if ($note !== '') {
                        $_pm_notes[] = 'Marker ' . ($idx + 1) . ': ' . $note;
                    }
                }
            }
        }
    }
} catch (\Throwable $e) { $_pm = null; }

// ── Imaging ────────────────────────────────────────────────────────────────
$_img = $imaging_findings_row['findings']   ?? '';
$_igd = $imaging_findings_row['created_at'] ?? $imaging_findings_row['date'] ?? '';

// ── Patient education ──────────────────────────────────────────────────────
$_edu = false;
try { $_edu = !empty(amcCollect("patient_edu_amc", $pid, 'form_encounter', $encounter)); }
catch (\Throwable $e) {}
?>
<div id="mdi-print-report">
<style>
/* ── Base ── */
#mdi-print-report * { box-sizing: border-box; }
#mdi-print-report {
    font-family: Arial, Helvetica, sans-serif;
    font-size: 8pt;
    color: #111;
    background: #fff;
    width: 8.5in;
    margin: 0 auto;
}

/* ── Page ── */
.pr-pg {
    width: 8.5in;
    padding: .32in .48in .3in .48in;
    page-break-after: always;
    break-after: page;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    overflow: hidden;
    box-sizing: border-box;
}
.pr-pg:last-child { page-break-after: auto; break-after: auto; }

/* ── Topbar ── */
.pr-top {
    display: flex; justify-content: flex-end;
    margin-bottom: 6px; padding-bottom: 6px;
    border-bottom: 2px solid #1e3a5f;
}
.pr-fn  { font-size: 9.5pt; font-weight: 700; text-align: right; }
.pr-fs  { font-size: 7pt; color: #6b7280; text-align: right; margin-top: 1px; }

/* ── Title / Meta ── */
.pr-ttl { font-size: 12.5pt; font-weight: 900; color: #1e3a5f; margin-bottom: 4px; }
.pr-met {
    display: flex; gap: 16px; flex-wrap: wrap;
    background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 4px;
    padding: 3px 9px; font-size: 7.5pt; margin-bottom: 8px;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.pr-met b { margin-right: 2px; color: #374151; }

/* ── 2-col grid ── */
.pr-2c { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 7px; }
.pr-2c > .pr-c { margin-bottom: 0; }

/* ── Card ── */
.pr-c {
    border: 1px solid #d1d5db; border-radius: 5px;
    overflow: hidden; break-inside: avoid; margin-bottom: 7px;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.pr-ch {
    background: #1e3a5f; color: #fff;
    padding: 3px 9px; font-size: 7pt; font-weight: 700;
    letter-spacing: .05em; text-transform: uppercase;
    display: flex; align-items: center; gap: 5px;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.pr-cb { padding: 7px 9px; }

/* ── KV rows ── */
.pr-kv { display: flex; gap: 3px; margin-bottom: 2px; font-size: 8pt; line-height: 1.35; }
.pr-kl { color: #555; font-weight: 600; min-width: 102px; flex-shrink: 0; }
.pr-kv2 { color: #111; }

/* ── Vitals ── */
.pr-vg { display: grid; grid-template-columns: repeat(3,1fr); gap: 5px; }
.pr-vb {
    background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 4px;
    padding: 4px 7px;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.pr-vl { font-size: 6.5pt; font-weight: 700; text-transform: uppercase; color: #6b7280; }
.pr-vv { font-size: 9.5pt; font-weight: 800; color: #1e3a5f; }

/* ── SOAP ── */
.pr-sp { display: flex; flex-direction: column; gap: 5px; }
.pr-sr { display: flex; gap: 6px; align-items: flex-start; break-inside: avoid; }
.pr-sb {
    width: 22px; height: 22px; border-radius: 4px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 10.5pt; margin-top: 1px;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.pr-ss { background: #1e3a5f; color: #fff; }
.pr-so { background: #065f46; color: #fff; }
.pr-sa { background: #92400e; color: #fff; }
.pr-sp2{ background: #3730a3; color: #fff; }
.pr-sl { font-size: 6.5pt; font-weight: 700; text-transform: uppercase; color: #555; margin-bottom: 1px; }
.pr-st { font-size: 8pt; line-height: 1.45; white-space: pre-wrap; }

/* ── Tables ── */
.pr-it { width: 100%; border-collapse: collapse; font-size: 8pt; }
.pr-it td { padding: 2px 5px 2px 0; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.pr-it td:first-child { color: #666; font-weight: 600; width: 84px; }
.pr-it tr:last-child td { border-bottom: none; }

/* ── Signature ── */
.pr-sig {
    break-inside: avoid; border: 1px solid #d1d5db; border-radius: 5px;
    padding: 7px 11px; display: flex; align-items: center;
    justify-content: space-between; margin-top: 7px;
}
.pr-sgc { color: #059669; font-weight: 700; font-size: 7.5pt; display: flex; align-items: center; gap: 4px; }
.pr-sgn { font-family: cursive; font-size: 14pt; color: #111; }
.pr-sgl { font-size: 6pt; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin-bottom: 3px; }

/* ── Footer ── */
.pr-ft {
    display: flex; justify-content: space-between;
    margin-top: 8px; padding-top: 5px; border-top: 1px solid #e5e7eb;
    font-size: 6.5pt; color: #6b7280;
}

/* ── Imaging box ── */
.pr-ib { border-left: 3px solid #1e3a5f; padding-left: 8px; font-size: 8pt; line-height: 1.45; }

/* ── Questionnaire table ── */
.pr-qt { width: 100%; border-collapse: collapse; font-size: 7.5pt; }
.pr-qt th {
    background: #1e3a5f; color: #fff; padding: 3px 6px; font-size: 7pt;
    font-weight: 700; text-align: left;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.pr-qt th.pr-qtr { text-align: right; }
.pr-qt td { padding: 2.5px 6px; border-bottom: 1px solid #f0f0f0; vertical-align: top; }
.pr-qt tr:last-child td { border-bottom: none; }
.pr-qt tr:nth-child(even) td { background: #f8fafc; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.pr-qsh {
    background: #eef2ff; font-weight: 700; font-size: 7pt; text-transform: uppercase;
    letter-spacing: .04em; color: #1e3a5f; padding: 3px 6px;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
</style>

<?php

<!-- ══════ PAGE 1 ══════ -->
<div class="pr-pg">

  <div class="pr-top">
    <div>
      <?php if ($_fn): ?><div class="pr-fn"><?php echo mdipr_esc($_fn); ?></div><?php endif; ?>
      <?php if ($_fa): ?><div class="pr-fs"><?php echo mdipr_esc($_fa); ?></div><?php endif; ?>
      <?php if ($_fph): ?><div class="pr-fs"><?php echo mdipr_esc($_fph); ?></div><?php endif; ?>
    </div>
  </div>

  <div class="pr-ttl">ENCOUNTER SUMMARY / SOAP NOTE</div>
  <div class="pr-met">
    <div><b>Encounter #:</b><?php echo mdipr_esc($_enc_num); ?></div>
    <div><b>Date of Service:</b><?php echo mdipr_esc($_enc_date); ?></div>
    <?php if ($_enc_time): ?><div><b>Time:</b><?php echo mdipr_esc($_enc_time); ?></div><?php endif; ?>
    <div><b>Provider:</b><?php echo mdipr_esc($providerNameRes); ?></div>
  </div>

  <!-- Patient Info + Encounter Details -->
  <div class="pr-2c">
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Patient Information</div>
      <div class="pr-cb">
        <div class="pr-kv"><span class="pr-kl">Name:</span><span class="pr-kv2"><?php echo mdipr_esc($patientName); ?></span></div>
        <?php if ($_pdob): ?><div class="pr-kv"><span class="pr-kl">Date of Birth:</span><span class="pr-kv2"><?php echo mdipr_esc($_pdob); ?><?php echo $_page ? ' ('.(int)$_page.')' : ''; ?></span></div><?php endif; ?>
        <?php if (!empty($patData['sex'])): ?><div class="pr-kv"><span class="pr-kl">Sex:</span><span class="pr-kv2"><?php echo mdipr_esc($patData['sex']); ?></span></div><?php endif; ?>
        <?php if (!empty($patData['phone_home'])): ?><div class="pr-kv"><span class="pr-kl">Phone:</span><span class="pr-kv2"><?php echo mdipr_esc($patData['phone_home']); ?></span></div><?php endif; ?>
      </div>
    </div>
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>Encounter Details</div>
      <div class="pr-cb">
        <div class="pr-kv"><span class="pr-kl">Encounter Type:</span><span class="pr-kv2">Office Visit</span></div>
        <div class="pr-kv"><span class="pr-kl">Date of Service:</span><span class="pr-kv2"><?php echo mdipr_esc($_enc_date); ?></span></div>
        <?php if ($_fn): ?><div class="pr-kv"><span class="pr-kl">Location:</span><span class="pr-kv2"><?php echo mdipr_esc($_fn); ?></span></div><?php endif; ?>
        <div class="pr-kv"><span class="pr-kl">Provider:</span><span class="pr-kv2"><?php echo mdipr_esc($providerNameRes); ?></span></div>
        <?php if (!empty($encData['reason'])): ?><div class="pr-kv"><span class="pr-kl">Reason for Visit:</span><span class="pr-kv2"><?php echo mdipr_esc($encData['reason']); ?></span></div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Visit Summary + Vitals -->
  <div class="pr-2c">
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Visit Summary</div>
      <div class="pr-cb">
        <?php if ($_fn): ?><div class="pr-kv"><span class="pr-kl">Facility:</span><span class="pr-kv2"><?php echo mdipr_esc($_fn); ?><?php if ($_fa) echo ' &ndash; '.mdipr_esc($_fa); ?></span></div><?php endif; ?>
        <div class="pr-kv"><span class="pr-kl">Category:</span><span class="pr-kv2">Office Visit</span></div>
        <?php if (!empty($encData['reason'])): ?><div class="pr-kv"><span class="pr-kl">Reason:</span><span class="pr-kv2"><?php echo mdipr_esc($encData['reason']); ?></span></div><?php endif; ?>
        <div class="pr-kv"><span class="pr-kl">Provider:</span><span class="pr-kv2"><?php echo mdipr_esc($providerNameRes); ?></span></div>
      </div>
    </div>
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>Vitals<?php if ($_vu): ?> <span style="font-weight:400;opacity:.75;font-size:6pt;">(by <?php echo mdipr_esc($_vu); ?>)</span><?php endif; ?></div>
      <div class="pr-cb">
        <?php if (!empty($_pv)): ?>
        <div class="pr-vg">
          <?php foreach (['Height'=>$_vH,'BMI'=>$_vBMI,'Blood Pressure'=>$_vBP,'Pulse'=>$_vPu,'Temperature'=>$_vTe,'O2 Saturation'=>$_vO2] as $l=>$v): ?>
          <div class="pr-vb"><div class="pr-vl"><?php echo mdipr_esc($l); ?></div><div class="pr-vv"><?php echo mdipr_esc($v); ?></div></div>
          <?php endforeach; ?>
        </div>
        <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No vitals recorded.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- SOAP -->
  <div class="pr-c">
    <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Clinical Documentation <span style="font-weight:400;font-size:6pt;opacity:.8;">(SOAP NOTE)</span></div>
    <div class="pr-cb">
      <?php if (!empty($_soap['subjective'])||!empty($_soap['objective'])||!empty($_soap['assessment'])||!empty($_soap['plan'])): ?>
      <div class="pr-sp">
        <?php if (!empty($_soap['subjective'])): ?><div class="pr-sr"><div class="pr-sb pr-ss">S</div><div><div class="pr-sl">Subjective</div><div class="pr-st"><?php echo nl2br(mdipr_esc($_soap['subjective'])); ?></div></div></div><?php endif; ?>
        <?php if (!empty($_soap['objective'])): ?><div class="pr-sr"><div class="pr-sb pr-so">O</div><div><div class="pr-sl">Objective</div><div class="pr-st"><?php echo nl2br(mdipr_esc($_soap['objective'])); ?></div></div></div><?php endif; ?>
        <?php if (!empty($_soap['assessment'])): ?>
        <div class="pr-sr"><div class="pr-sb pr-sa">A</div><div><div class="pr-sl">Assessment</div>
          <?php if (!empty($diagRowsUniq)): ?><div style="margin-bottom:2px;"><?php foreach ($diagRowsUniq as $dr): ?><div style="font-size:8pt;"><strong><?php echo mdipr_esc($dr['diagnosis']); ?></strong><?php if (!empty($dr['title'])) echo ' <span style="color:#555;">'.mdipr_esc($dr['title']).'</span>'; ?></div><?php endforeach; ?></div><?php endif; ?>
          <div class="pr-st"><?php echo nl2br(mdipr_esc($_soap['assessment'])); ?></div>
        </div></div>
        <?php endif; ?>
        <?php if (!empty($_soap['plan'])): ?><div class="pr-sr"><div class="pr-sb pr-sp2">P</div><div><div class="pr-sl">Plan</div><div class="pr-st"><?php echo nl2br(mdipr_esc($_soap['plan'])); ?></div></div></div><?php endif; ?>
      </div>
      <?php elseif (!empty($diagRowsUniq)): ?>
      <table style="width:100%;border-collapse:collapse;font-size:8pt;"><?php foreach ($diagRowsUniq as $dr): ?><tr><td style="font-weight:700;color:#1e3a5f;width:115px;padding:2px 5px 2px 0;"><?php echo mdipr_esc($dr['diagnosis']); ?></td><td style="font-size:8pt;"><?php echo mdipr_esc($dr['title']??''); ?></td></tr><?php endforeach; ?></table>
      <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No SOAP note recorded.</div><?php endif; ?>
    </div>
  </div>

  <!-- Insurance + Accident -->
  <div class="pr-2c">
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Insurance Information</div>
      <div class="pr-cb">
        <?php if ($primaryIns): ?>
        <table class="pr-it">
          <tr><td>Insurance</td><td><?php echo mdipr_esc($primaryIns['company_name']??$primaryIns['plan_name']??'—'); ?></td></tr>
          <tr><td>Plan</td><td><?php echo mdipr_esc($primaryIns['plan_name']??'—'); ?></td></tr>
          <tr><td>Member ID</td><td><strong><?php echo mdipr_esc($primaryIns['policy_number']??'—'); ?></strong></td></tr>
          <tr><td>Group #</td><td><?php echo mdipr_esc($primaryIns['group_number']??'—'); ?></td></tr>
          <tr><td>Relationship</td><td><?php echo mdipr_esc(ucfirst($primaryIns['subscriber_relationship']??'—')); ?></td></tr>
        </table>
        <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No insurance on file.</div><?php endif; ?>
      </div>
    </div>
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M5 17H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2h-2"/><circle cx="7.5" cy="17.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>Accident Information</div>
      <div class="pr-cb">
        <?php if (!empty($has_box10)&&$_al): ?>
        <table class="pr-it">
          <tr><td>Accident Type</td><td><?php echo mdipr_esc($_al); ?></td></tr>
          <?php if (!empty($acc_date)&&$acc_date!=='0000-00-00'): ?><tr><td>Date</td><td><?php echo mdipr_esc(date('m/d/Y',strtotime($acc_date))); ?></td></tr><?php endif; ?>
          <?php if (!empty($acc_state)): ?><tr><td>State</td><td><?php echo mdipr_esc($acc_state); ?></td></tr><?php endif; ?>
        </table>
        <?php else: ?><div style="font-size:8pt;color:#6b7280;">No accident reported &mdash; This is not completed</div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Additional Notes -->
  <div class="pr-c">
    <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/></svg>Additional Notes</div>
    <div class="pr-cb"><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No additional notes for this encounter.</div></div>
  </div>

  <!-- Signature -->
  <div class="pr-sgl">Encounter Signature</div>
  <div class="pr-sig">
    <div>
      <?php if ($esignSignedAt): ?>
      <div class="pr-sgc"><svg width="9" height="9" fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Signed by <?php echo mdipr_esc($esignSignerName); ?></div>
      <div style="font-size:6.5pt;color:#059669;margin-top:1px;font-weight:600;"><?php echo mdipr_esc($esignSignedAt); ?></div>
      <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">Not yet signed</div><?php endif; ?>
    </div>
    <?php if ($esignSignedAt): ?><div style="text-align:right;"><div class="pr-sgn"><?php echo mdipr_esc($esignSignerName); ?></div><div style="font-size:6pt;color:#6b7280;">Electronically Signed</div></div><?php endif; ?>
  </div>

  <div class="pr-ft">
    <span><?php echo mdipr_esc($_fn); ?><?php if ($_fa) echo ' &mdash; '.mdipr_esc($_fa); ?></span>
    <span>Confidential &mdash; For Authorized Use Only</span>
    <span>Page 1 of 2</span>
  </div>
</div><!-- page 1 -->


<!-- ══════ PAGE 2 ══════ -->
<div class="pr-pg">

  <?php if (!empty($_q) || !empty($questionnaire_forms)): ?>
  <div style="font-size:10pt;font-weight:900;color:#1e3a5f;margin-bottom:7px;padding-bottom:6px;border-bottom:2px solid #1e3a5f;display:flex;align-items:center;gap:6px;">
    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0 0h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"/></svg>
    PATIENT INTAKE QUESTIONNAIRE<?php if ($_qu): ?> <span style="font-size:7.5pt;font-weight:400;color:#6b7280;">(by <?php echo mdipr_esc($_qu); ?>)</span><?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (!empty($_q)): ?>
  <!-- Substance Use Screening -->
  <div class="pr-c" style="margin-bottom:7px;">
    <div class="pr-ch">Substance Use Screening</div>
    <div class="pr-cb" style="padding:0;">
      <table class="pr-qt" style="width:100%;">
        <tr><th style="width:78%;">Question</th><th class="pr-qtr" style="width:22%;">Response</th></tr>
        <?php
        $sus = [
            'intake_chief_complaint' => 'In the last year, have you used any illegal substances or used a prescription medication for non-medical reasons?',
        ];
        // Show chief complaint as first question proxy, then key intake fields
        $sus_fields = [
            'intake_chief_complaint'  => 'Chief Complaint',
            'intake_cause'            => 'Cause',
            'intake_pattern'          => 'Pattern',
            'intake_conditions'       => 'Pre-existing Conditions',
            'intake_prev_treatment'   => 'Previous Treatment',
            'intake_body_regions'     => 'Body Regions',
        ];
        foreach ($sus_fields as $fk => $fl): $fv = $_q[$fk] ?? ''; ?>
        <tr><td style="padding:3px 6px;font-size:7.5pt;"><?php echo mdipr_esc($fl); ?></td><td style="text-align:right;padding:3px 6px;font-weight:500;"><?php echo $fv !== '' ? mdipr_esc($fv) : '&mdash;'; ?></td></tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <!-- Mental Health / Functional Screening -->
  <div class="pr-c" style="margin-bottom:7px;">
    <div class="pr-ch">Functional Status &amp; Systems Review</div>
    <div class="pr-cb" style="padding:0;">
      <table class="pr-qt" style="width:100%;">
        <tr><th style="width:55%;">Area</th><th class="pr-qtr">Status</th><th class="pr-qtr">Notes</th></tr>
        <?php
        $func_fields = [
            'intake_func_standing' => 'Standing',
            'intake_func_sitting'  => 'Sitting',
            'intake_func_walking'  => 'Walking',
            'intake_func_stairs'   => 'Stairs',
            'intake_func_bending'  => 'Bending',
            'intake_func_lifting'  => 'Lifting',
            'intake_func_dressing' => 'Dressing',
            'intake_func_driving'  => 'Driving',
            'intake_func_sleep'    => 'Sleep',
            'intake_func_work'     => 'Work',
        ];
        foreach ($func_fields as $fk => $fl):
            $fv = $_q[$fk] ?? '';
            if ($fv === '' || $fv === null) continue; ?>
        <tr><td style="padding:2.5px 6px;font-size:7.5pt;"><?php echo mdipr_esc($fl); ?></td><td style="text-align:right;padding:2.5px 6px;"><?php echo mdipr_esc($fv); ?></td><td></td></tr>
        <?php endforeach; ?>
        <?php if (!empty($_q['intake_sys_notes'])): ?>
        <tr><td colspan="3" style="padding:3px 6px;font-size:7.5pt;color:#555;font-style:italic;"><?php echo mdipr_esc($_q['intake_sys_notes']); ?></td></tr>
        <?php endif; ?>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- Pain Assessment + Imaging -->
  <div class="pr-2c">

    <!-- Pain Assessment -->
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>Pain Assessment</div>
      <div class="pr-cb">
        <?php if (!empty($_pm)): ?>
          <?php if ($_pm['max_pain'] !== '' && $_pm['max_pain'] !== null): ?>
          <div class="pr-kv" style="margin-bottom:3px;"><span class="pr-kl">Overall Pain (0-10):</span><span class="pr-kv2"><strong><?php echo mdipr_esc($_pm['max_pain']); ?>/10</strong></span></div>
          <?php endif; ?>
          <?php if (!empty($_q['intake_pain_quality'])): ?>
          <div class="pr-kv" style="margin-bottom:3px;"><span class="pr-kl">Pain Quality:</span><span class="pr-kv2"><?php echo mdipr_esc($_q['intake_pain_quality']); ?></span></div>
          <?php endif; ?>
          <?php if (!empty($_q['intake_radiation'])): ?>
          <div class="pr-kv" style="margin-bottom:3px;"><span class="pr-kl">Radiation:</span><span class="pr-kv2"><?php echo mdipr_esc($_q['intake_radiation']); ?></span></div>
          <?php endif; ?>
          <?php if (!empty($_pm['regions_text'])): ?>
          <div class="pr-kv" style="margin-bottom:3px;"><span class="pr-kl">Pain Location:</span><span class="pr-kv2"><?php echo mdipr_esc($_pm['regions_text']); ?></span></div>
          <?php endif; ?>
          <?php if (!empty($_pm_notes)): ?>
          <div style="margin-top:6px;padding-top:5px;border-top:1px solid #e5e7eb;">
            <div style="font-size:7pt;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:4px;">Marker Notes</div>
            <?php foreach ($_pm_notes as $_pn): ?>
            <div style="font-size:8pt;color:#374151;line-height:1.65;padding:1px 0;"><?php echo mdipr_esc($_pn); ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No pain assessment recorded.</div><?php endif; ?>
      </div>
    </div>

    <!-- Imaging Findings -->
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>Imaging Findings</div>
      <div class="pr-cb">
        <?php if (!empty($_img)): ?>
        <div class="pr-ib">
          <?php if (!empty($_igd)&&$_igd!=='0000-00-00'): ?><div class="pr-kv"><span class="pr-kl">Date:</span><span class="pr-kv2"><?php echo mdipr_esc(date('m/d/Y',strtotime($_igd))); ?></span></div><?php endif; ?>
          <div style="margin-top:3px;font-size:8pt;line-height:1.45;"><?php echo nl2br(mdipr_esc($_img)); ?></div>
        </div>
        <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No imaging findings recorded.</div><?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Patient Education -->
  <?php if ($_edu): ?>
  <div class="pr-c" style="margin-bottom:7px;">
    <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>Patient Education</div>
    <div class="pr-cb"><div style="font-size:8pt;">Patient education provided during this encounter.</div></div>
  </div>
  <?php endif; ?>

  <!-- Signature -->
  <div class="pr-sgl">Encounter Signature</div>
  <div class="pr-sig">
    <div>
      <?php if ($esignSignedAt): ?>
      <div class="pr-sgc"><svg width="9" height="9" fill="none" stroke="#059669" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>Signed by <?php echo mdipr_esc($esignSignerName); ?></div>
      <div style="font-size:6.5pt;color:#059669;margin-top:1px;font-weight:600;"><?php echo mdipr_esc($esignSignedAt); ?></div>
      <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">Not yet signed</div><?php endif; ?>
    </div>
    <?php if ($esignSignedAt): ?><div style="text-align:right;"><div class="pr-sgn"><?php echo mdipr_esc($esignSignerName); ?></div><div style="font-size:6pt;color:#6b7280;">Electronically Signed</div></div><?php endif; ?>
  </div>

  <div class="pr-ft">
    <span><?php echo mdipr_esc($_fn); ?><?php if ($_fa) echo ' &mdash; '.mdipr_esc($_fa); ?></span>
    <span>Confidential &mdash; For Authorized Use Only</span>
    <span>Page 2 of 2</span>
  </div>
</div><!-- page 2 -->

</div><!-- #mdi-print-report -->
<?php if (!defined('INCLUDED_IN_FORMS')): ?>
<script>window.focus();</script>
</body>
</html>
<?php endif; ?>
