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

 // ---------------------------------------------------------------------
// Diagnoses (Lists table as of encounter date)
// ---------------------------------------------------------------------

$diagRowsUniq = [];
$seenCodes    = [];

// $encounter_date was already loaded earlier from form_encounter
// Format: YYYY-MM-DD

$_dxr = sqlStatementNoLog(
    "SELECT id,
            diagnosis,
            title,
            date
       FROM lists
      WHERE pid = ?
        AND activity = 1
        AND type IN ('medical_problem','diagnosis')
        AND diagnosis IS NOT NULL
        AND diagnosis <> ''
        AND DATE(date) <= ?
      ORDER BY DATE(date), id",
    [$pid, $encounter_date]
);

while ($_row = sqlFetchArray($_dxr)) {

    // Split multiple diagnoses
    $codes = preg_split('/[;\r\n]+/', $_row['diagnosis']);

    foreach ($codes as $entry) {

        $entry = trim($entry);

        if ($entry == '') {
            continue;
        }

        // Remove ICD10: prefix
        $entry = preg_replace('/^ICD10:/i', '', $entry);

        // Remove anything after second colon
        // Example:
        // M153:0   -> M153
        // M54.2:0 -> M54.2
        if (strpos($entry, ':') !== false) {
            $entry = explode(':', $entry)[0];
        }

        $code = strtoupper(trim($entry));

        if ($code == '') {
            continue;
        }

        // Prevent duplicates
        if (isset($seenCodes[$code])) {
            continue;
        }

        $seenCodes[$code] = true;

        // Lookup description from OpenEMR's icd10_dx_order_code reference
        // table (the official CMS ICD-10-CM code list — see
        // icd10_dx_order_code.short_desc). Codes stored in `lists` can
        // appear either with or without the decimal point (e.g. "M5450"
        // vs "M54.50"), so match against both dx_code (no dot) and
        // formatted_dx_code (with dot) to be safe.
        $codeNoDot = str_replace('.', '', $code);
        $codeRow = sqlQuery(
            "SELECT short_desc
               FROM icd10_dx_order_code
              WHERE dx_code = ?
                 OR formatted_dx_code = ?
              ORDER BY active DESC, revision DESC
              LIMIT 1",
            [$codeNoDot, $code]
        );

        $diagRowsUniq[] = [
            'diagnosis' => $code,
            'title'     => $codeRow['short_desc'] ?? ($_row['title'] ?? '')
        ];
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

    // eSign — fetch full signature history, not just the most recent row.
    // esign_signatures can carry more than one row per encounter (e.g. an
    // original signature plus a later re-signature after an addendum/
    // amendment); the most recent is still used as "the" signature for the
    // top-level Signed By line, but every row is kept for the Addendum
    // History list in Section 6 below.
    $esignSignedAt = '';
    $esignSignerName = '';
    $esignHistory = [];
    $_esr = sqlStatementNoLog(
        "SELECT es.datetime, CONCAT(u.fname,' ',u.lname) AS signer_name
           FROM esign_signatures es LEFT JOIN users u ON u.id=es.uid
          WHERE es.tid=? AND es.`table`='form_encounter' ORDER BY es.id DESC",
        [$encounter]
    );
    if ($_esr) {
        while ($_esw = sqlFetchArray($_esr)) {
            if (empty($_esw['datetime'])) continue;
            $esignHistory[] = [
                'datetime' => date('m/d/Y g:i A', strtotime($_esw['datetime'])),
                'signer'   => trim($_esw['signer_name'] ?? '') ?: $providerNameRes,
            ];
        }
        if (!empty($esignHistory)) {
            $esignSignedAt   = $esignHistory[0]['datetime'];
            $esignSignerName = $esignHistory[0]['signer'];
        }
    }
}
// ── End standalone bootstrap ─────────────────────────────────────────────

// Helper functions — defined here (outside any if block) so always available
if (!function_exists('mdipr_esc')) {
    function mdipr_esc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('mdipr_dx_inline')) {
    /**
     * Renders a diagnosis code list as inline, wrapping "chips" (side by
     * side) instead of one-per-row. Titles that are just the generic
     * "Default Diagnosis" placeholder (no real description was found in
     * the codes table) are suppressed — only a genuine, looked-up
     * description is ever shown next to the code.
     */
    function mdipr_dx_inline($rows, $extraStyle = '') {
        if (empty($rows)) return '';
        $chips = [];
        foreach ($rows as $dr) {
            $code = mdipr_esc($dr['diagnosis'] ?? '');
            if ($code === '') continue;
            $title = trim((string)($dr['title'] ?? ''));
            $showTitle = ($title !== '' && strcasecmp($title, 'Default Diagnosis') !== 0);
            $chips[] = '<span style="display:inline-flex;align-items:center;border:1px solid #c7d2e0;'
                . 'background:#f4f7fb;border-radius:3px;padding:0 4px;margin:0 2px 2px 0;'
                . 'font-size:7pt;white-space:nowrap;">'
                . '<strong style="color:#1e3a5f;">' . $code . '</strong>'
                . ($showTitle ? ' <span style="color:#555;margin-left:4px;">' . mdipr_esc($title) . '</span>' : '')
                . '</span>';
        }
        if (empty($chips)) return '';
        return '<div style="display:flex;flex-wrap:wrap;align-items:center;' . $extraStyle . '">' . implode('', $chips) . '</div>';
    }
}

// ── Structured Clinical Note detection ───────────────────────────────────
// The SOAP template UI (general_new.html) now offers several non-SOAP note
// types — Auto Accident, Re-Exam, Diagnosis Summary, Discharge Summary —
// that all still save into form_soap's subjective/objective/assessment/plan
// columns (there's no dedicated note-type column). Each one writes a
// distinctive marker into `subjective` so this report (and report.php, the
// encounter-screen renderer) can tell them apart from an ordinary SOAP note
// and from each other. Mirrors the same constants/logic as report.php —
// keep both in sync if the front-end markers ever change.
if (!defined('MDIPR_ACCIDENT_MARKER'))  define('MDIPR_ACCIDENT_MARKER', 'AUTOMOBILE ACCIDENT');
if (!defined('MDIPR_REEXAM_MARKER'))    define('MDIPR_REEXAM_MARKER', '[RE-EXAM SUMMARY]');
if (!defined('MDIPR_DIAGNOSIS_MARKER')) define('MDIPR_DIAGNOSIS_MARKER', '[DIAGNOSIS SUMMARY]');
if (!isset($MDIPR_DISCHARGE_MARKERS)) {
    $MDIPR_DISCHARGE_MARKERS = ['Treatment Summary:', 'Outcomes:', 'Final Findings:', 'Discharge Narrative:'];
}

if (!function_exists('mdipr_detect_note_type')) {
    /**
     * Returns 'accident' | 'reexam' | 'diagnosis' | 'discharge' | 'soap'
     * based on the markers found in the subjective text. 'soap' means no
     * special marker was found — render as an ordinary SOAP note.
     */
    function mdipr_detect_note_type($subjective) {
        global $MDIPR_DISCHARGE_MARKERS;
        $subjective = (string)($subjective ?? '');

        if ($subjective === '') return 'soap';
        if (strpos($subjective, MDIPR_ACCIDENT_MARKER) !== false)  return 'accident';
        if (strpos($subjective, MDIPR_REEXAM_MARKER) !== false)    return 'reexam';
        if (strpos($subjective, MDIPR_DIAGNOSIS_MARKER) !== false) return 'diagnosis';

        $allPresent = true;
        foreach ($MDIPR_DISCHARGE_MARKERS as $marker) {
            if (strpos($subjective, $marker) === false) { $allPresent = false; break; }
        }
        if ($allPresent) return 'discharge';

        return 'soap';
    }
}

if (!function_exists('mdipr_strip_hidden_marker')) {
    // Strips the zero-width-space-prefixed "[LABEL]" line that
    // buildReexamNote()/buildDiagnosisNote() prepend purely for detection —
    // never meant to be visible to a user or printed on a report.
    function mdipr_strip_hidden_marker($text) {
        return preg_replace('/^\x{200B}\[[^\]]*\]\s*\n+/u', '', (string)($text ?? ''));
    }
}

if (!function_exists('mdipr_note_text')) {
    // Renders note body text consistently: escape, strip the hidden marker
    // if present, collapse 3+ consecutive newlines down to a single blank
    // line (prevents the doubled/tripled vertical gaps that nl2br() +
    // CSS white-space:pre-wrap used to produce together — pre-wrap already
    // renders \n as a line break, so the old nl2br() call was inserting a
    // redundant extra <br> for every newline), then let pre-wrap (see
    // .pr-st CSS) handle line breaks — no nl2br() needed.
    function mdipr_note_text($text) {
        $text = mdipr_strip_hidden_marker($text);
        $text = mdipr_esc($text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return $text;
    }
}

if (!function_exists('mdipr_render_structured_body')) {
    /**
     * Renders the inner body markup for a structured note card (Accident,
     * Discharge, Re-Exam, Diagnosis) — section dividers/labels become small
     * uppercase sub-headings, everything else prints as plain paragraph
     * text. Mirrors report.php's soap_report_print_structured_card()
     * formatting logic, adapted to this report's markup/classes.
     */
    function mdipr_render_structured_body($subjective, $type) {
        $subjective = mdipr_strip_hidden_marker((string)($subjective ?? ''));
        $lines = preg_split("/\r\n|\r|\n/", $subjective);

        $html = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // Accident-style section dividers: ═══ AUTOMOBILE ACCIDENT — DETAILS ═══
            if (preg_match('/^[=\x{2550}\x{2500}\-]+\s*(.+?)\s*[=\x{2550}\x{2500}\-]+$/u', $line, $m)) {
                $title = trim($m[1]);
                $html .= '<div style="font-size:6.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:8px 0 2px;">'
                    . mdipr_esc($title) . '</div>';
                continue;
            }

            // Discharge-style "Section Name: paragraph text" lines — only a
            // section break if the label portion is short (a real heading),
            // not an arbitrary sentence that happens to contain a colon.
            if ($type === 'discharge' && preg_match('/^([A-Z][A-Za-z ]{2,30}):\s*(.*)$/', $line, $m)) {
                $html .= '<div style="font-size:6.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:8px 0 2px;">'
                    . mdipr_esc(trim($m[1])) . '</div>';
                if (trim($m[2]) !== '') {
                    $html .= '<div class="pr-st">' . mdipr_esc(trim($m[2])) . '</div>';
                }
                continue;
            }

            // Accident-style "Label: value" lines
            if ($type === 'accident' && strpos($line, ':') !== false) {
                list($label, $val) = explode(':', $line, 2);
                $html .= '<div class="pr-st"><strong>' . mdipr_esc(trim($label)) . ':</strong> '
                    . mdipr_esc(trim($val)) . '</div>';
                continue;
            }

            // Re-Exam / Diagnosis Summary — plain prose, no internal sectioning.
            $html .= '<div class="pr-st">' . mdipr_esc($line) . '</div>';
        }

        return $html;
    }
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
        /* Force a page break between each explicit .pr-pg block (page 1,
           page 2) — but DON'T cap the height or hide overflow. The previous
           max-height:11in + overflow:hidden combination silently clipped
           any content that ran past 11 inches instead of letting it flow,
           which is what produced the squished/cut-off page 2 — content was
           being chopped off rather than paginated. Individual cards
           (.pr-c, below) already carry break-inside:avoid, so they won't
           get split awkwardly across a page boundary; the page itself is
           now free to size to its actual content and the browser handles
           genuine overflow by pushing it onto a following page rather than
           deleting it. */
           page-break-after: auto;
            break-after: auto;
        width: 8.5in !important;
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
// An encounter can have multiple form_soap rows (e.g. a Discharge Summary,
// a regular SOAP note, and an Accident Details entry all logged separately
// on the same encounter — see the "Main Encounter Screen" tab, which lists
// each as its own card). The print report previously only fetched the most
// recent one (LIMIT 1), so anything earlier silently never made it onto the
// PDF. Now fetches every form_soap row tied to this encounter, newest first
// (matching the order shown on the encounter screen), and each one gets its
// own Documentation card below — see the rendering loop further
// down this file.
$_soapList = [];
try {
    $_sfRows = sqlStatement(
        "SELECT f.form_id FROM forms f WHERE f.pid=? AND f.encounter=? AND f.formdir='soap' AND f.deleted=0 ORDER BY f.date DESC",
        [$pid, $encounter]
    );
    while ($_sfRow = sqlFetchArray($_sfRows)) {
        if (!empty($_sfRow['form_id'])) {
            $_soapRow = sqlQuery("SELECT * FROM form_soap WHERE id=?", [$_sfRow['form_id']]);
            if ($_soapRow) $_soapList[] = $_soapRow;
        }
    }
} catch (\Throwable $e) {}

// Backward-compat alias: a few other places in this file (e.g. the
// Accident/Insurance section below) reference the single most-recent SOAP
// row via $_soap — keep that working by pointing it at the first (newest)
// entry in $_soapList.
$_soap = $_soapList[0] ?? [];

// ── Encounter-level note type ────────────────────────────────────────────
// Drives the whole report's structure per the requested layout: a Daily
// visit shows S/O/A/P only; a Re-Exam encounter gets a dedicated "RE-EXAM
// SUMMARY" block right after the header, followed by focused SOAP; a
// Discharge encounter gets a "DISCHARGE SUMMARY" block followed by final
// findings. Only one of these three banners is ever shown — never more than
// one — based on the newest form_soap entry's detected type. If an encounter
// has multiple form_soap rows of different types (e.g. an Accident Details
// entry alongside a Discharge Summary), every entry still gets its own card
// in the Documentation section below; this just decides which single banner
// (if any) appears at the top, using the most recent entry as the canonical
// "kind" of visit this encounter represents.
$_encounterNoteType = 'soap';
foreach ($_soapList as $_soapScan) {
    $_t = mdipr_detect_note_type($_soapScan['subjective'] ?? '');
    if ($_t === 'reexam' || $_t === 'discharge') { $_encounterNoteType = $_t; break; }
}

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
            // newpatient uses form_encounter_newpatient or similar — try common table names,
            // but first confirm the table actually exists, since sqlQuery() fatals/echoes
            // a "Query Error" on a bad table name rather than throwing a catchable exception.
            foreach (['form_newpatient', 'form_encounter_newpatient'] as $_qt) {
                try {
                    $_exists = sqlQuery(
                        "SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?",
                        [$_qt]
                    );
                    if (empty($_exists['c'])) {
                        continue; // table doesn't exist in this install, skip it
                    }
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
        $raw = $_pm['markers_json'];

        // save.php stores plain JSON: {dots:[{id,region,x,y},...], notes:{"1":"text","2":"text"}}
        // notes object is keyed by dot id (string), NOT by array index
        $decoded = json_decode($raw, true);
        // Fallback: try base64 decode
        if (!is_array($decoded) || !isset($decoded['dots'])) {
            $decoded = json_decode(base64_decode($raw), true);
        }

        if (is_array($decoded) && isset($decoded['dots'])) {
            $notesMap = $decoded['notes'] ?? [];
            foreach ($decoded['dots'] as $dot) {
                // Markers for SVG (kept but no longer rendered — data preserved)
                $x = floatval($dot['x'] ?? -1);
                $y = floatval($dot['y'] ?? -1);
                if ($x >= 0 && $x <= 100 && $y >= 0 && $y <= 100) {
                    $_pm_markers[] = ['x' => $x, 'y' => $y];
                }
                // Notes — keyed by dot id (string key in JSON object)
                $dotId  = (string)($dot['id'] ?? '');
                $region = $dot['region'] ?? ('Marker ' . $dotId);
                $note   = trim(
                    $notesMap[$dotId]             // "1" => "text"
                    ?? $notesMap[(int)$dotId]     // int key fallback
                    ?? $notesMap[$region]         // region name fallback
                    ?? ''
                );
                if ($note !== '') {
                    $_pm_notes[] = $region . ': ' . $note;
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
    box-sizing: border-box;
}
.pr-pg:last-child { page-break-after: auto; break-after: auto; }

/* Page 2 starts with no facility topbar/title above its first card (unlike
   page 1), so without this it reads as more cramped at the top even though
   both pages share the same base padding — add a bit of extra breathing
   room above page 2's content specifically. */
.pr-pg-2 { padding-top: .32in; }

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

/* ── Top-level section labels (Visit Snapshot, Documentation, etc.) ── */
.pr-sec-lbl {
    font-size: 7pt; font-weight: 800; letter-spacing: .08em; text-transform: uppercase;
    color: #1e3a5f; margin: 10px 0 5px; padding-bottom: 3px; page-break-after: avoid;
    border-bottom: 1.5px solid #c7d2fe;
}
.pr-sec-lbl:first-of-type { margin-top: 0; }

/* ── 2-col grid ── */
.pr-2c { display: grid; grid-template-columns: 1fr 1fr; gap: 7px; margin-bottom: 7px; }
.pr-2c > .pr-c { margin-bottom: 0; }

/* ── Card ── */
.pr-c {
    border: 1px solid #d1d5db; border-radius: 5px;
    overflow: hidden; break-inside: avoid; page-break-inside: avoid; margin-bottom: 7px;
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
// ── Body silhouette SVG paths (front + back) ───────────────────────────────
// Viewbox: 200 x 420 total (two figures side by side, each 100w)
// Front figure: center x=50, Back figure: center x=150
// Markers from form_painmap have x/y as 0-100% of original canvas
// We'll split into front (x<50) and back (x>=50) based on the canvas layout
// (pain map typically stores front-left half and back-right half of a 200-wide canvas)
?>

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

  <!-- ══ 2. VISIT SNAPSHOT ══════════════════════════════════════════════
       Encounter type and reason for visit are already shown in the
       Encounter Details card above (in the Header section) — this section
       adds the two pieces not yet shown: Vitals and a Diagnosis Summary,
       so nothing is duplicated between Header and Visit Snapshot. -->
  <div class="pr-sec-lbl">VISIT SNAPSHOT</div>
  <div class="pr-2c">
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
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Diagnosis Summary</div>
      <div class="pr-cb">
        <?php if (!empty($diagRowsUniq)): ?>
        <?php echo mdipr_dx_inline($diagRowsUniq); ?>
        <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No diagnoses recorded for this encounter.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ══ 3. CLINICAL NOTE TYPE ═══════════════════════════════════════════
       A Daily SOAP visit has no banner here — Documentation (S/O/A/P) is
       the whole story. A Re-Exam or Discharge encounter gets its one
       dedicated summary card here instead, using whichever form_soap entry
       drove $_encounterNoteType above. Only one of these three kinds of
       visit is ever represented per encounter print — never more than one
       banner. -->
  <?php
    // Initialized unconditionally (not just inside the reexam/discharge
    // branch below) so $_bannerIdx is always a defined variable by the time
    // the Documentation loop further down checks it — for a Daily SOAP
    // encounter it simply stays null, meaning "no entry is the banner
    // entry", and every form_soap row prints its full S/O/A/P as normal.
    $_bannerEntry = null;
    $_bannerIdx = null;
  ?>
  <?php if ($_encounterNoteType === 'reexam' || $_encounterNoteType === 'discharge'): ?>
    <?php
      // Find the newest form_soap entry matching the encounter's overall type,
      // to use as the source for this banner. Tracked by index (not content
      // comparison) so two entries with coincidentally identical text are
      // never both mistaken for "the banner entry".
      foreach ($_soapList as $_seIdx => $_se) {
          if (mdipr_detect_note_type($_se['subjective'] ?? '') === $_encounterNoteType) {
              $_bannerEntry = $_se;
              $_bannerIdx = $_seIdx;
              break;
          }
      }
      $_bannerTitle = $_encounterNoteType === 'reexam' ? 'RE-EXAM SUMMARY' : 'DISCHARGE SUMMARY';
    ?>
  <div class="pr-sec-lbl">CLINICAL NOTE TYPE</div>
  <div class="pr-c">
    <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?php echo mdipr_esc($_bannerTitle); ?></div>
    <div class="pr-cb">
      <?php if ($_bannerEntry): ?>
        <?php echo mdipr_render_structured_body($_bannerEntry['subjective'], $_encounterNoteType); ?>
        <?php if ($_encounterNoteType === 'reexam' && !empty($_bannerEntry['plan'])): ?>
        <!-- Re-Exam keeps Plan as a normal, separately-editable field (see
             general_new.html) — print it here without the S/O/A/P letter
             badge (unlike Documentation's SOAP cards), since this banner's
             other lines are plain section headings + paragraphs, not
             badge-style rows. -->
        <div style="font-size:6.5pt;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:8px 0 2px;">Plan</div>
        <div class="pr-st"><?php echo mdipr_note_text($_bannerEntry['plan']); ?></div>
        <?php endif; ?>
      <?php else: ?>
        <div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No <?php echo $_encounterNoteType === 'reexam' ? 're-exam' : 'discharge'; ?> summary recorded.</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ══ 4. DOCUMENTATION (S / O / A / P) ════════════════════════════════
       Daily SOAP visits: every form_soap entry's full S/O/A/P prints here
       as normal. Re-Exam / Discharge encounters: the entry that drove the
       Clinical Note Type banner above is NOT repeated in full here (its
       content already printed there) — only its Objective/Assessment
       fields print, under a "Focused SOAP" subtitle, for whatever exam
       findings exist outside the structured summary. Any other form_soap
       entries on the same encounter (e.g. a separate Accident Details
       entry, or additional Daily SOAP visits logged the same day) still
       get their own full card here regardless of the encounter's overall
       type, since those are genuinely separate documentation. -->
  <div class="pr-sec-lbl">DOCUMENTATION</div>
  <?php
    $_cardTitles = [
        'accident'  => 'Accident Details',
        'reexam'    => 'Re-Exam Summary',
        'diagnosis' => 'Diagnosis Summary',
        'discharge' => 'Discharge Summary',
    ];
  ?>
  <?php if (empty($_soapList) && empty($diagRowsUniq)): ?>
  <div class="pr-c">
    <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Documentation</div>
    <div class="pr-cb"><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No clinical note recorded.</div></div>
  </div>
  <?php else: ?>
    <?php
      // Every form_soap row for this encounter gets its own card. Previously
      // only the single most recent row was ever fetched (see $_soapList
      // above), which is why Discharge Summary / Accident Details entries
      // created earlier than the latest note were silently missing here.
      //
      // Display order: Daily SOAP prints first, then Accident Details, then
      // Discharge Summary (per explicit request), with everything else
      // keeping the original newest-first order within its own type.
      // $_soapIdx (used elsewhere, e.g. to decide which entry drives the
      // Assessment-section diagnosis list) still reflects each entry's
      // original position in $_soapList — only the order cards are
      // *printed* in changes here, not the underlying indices.
      $_docTypePriority = ['soap' => 0, 'accident' => 1, 'discharge' => 2];
      $_docOrder = array_keys($_soapList);
      usort($_docOrder, function($a, $b) use ($_soapList, $_docTypePriority) {
          $ta = mdipr_detect_note_type($_soapList[$a]['subjective'] ?? '');
          $tb = mdipr_detect_note_type($_soapList[$b]['subjective'] ?? '');
          $pa = $_docTypePriority[$ta] ?? 99;
          $pb = $_docTypePriority[$tb] ?? 99;
          if ($pa !== $pb) return $pa <=> $pb;
          return $a <=> $b; // stable: preserve original newest-first order within same priority
      });
      $_soapRendered = false;
    ?>
    <?php foreach ($_docOrder as $_soapIdx): $_soapEntry = $_soapList[$_soapIdx]; ?>
      <?php
        $_noteType = mdipr_detect_note_type($_soapEntry['subjective'] ?? '');
        $_isBannerEntry = ($_bannerIdx !== null) && ($_soapIdx === $_bannerIdx);

        if ($_isBannerEntry) {
          // This entry already printed in full as the Clinical Note Type
          // banner above — here it only contributes its "focused SOAP"
          // pieces: Objective and Assessment (the parts of a normal SOAP
          // note that exist independently of the structured summary text).
          // Subjective and Plan are intentionally skipped, since Subjective
          // carries the structured summary (already shown above) and Plan
          // was already printed inside the banner card for Re-Exam.
          $_hasFocusedContent = !empty($_soapEntry['objective']) || !empty($_soapEntry['assessment']);
          if (!$_hasFocusedContent) continue; // nothing left to show beyond the banner
          $_soapRendered = true;
      ?>
      <div class="pr-c">
        <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Focused SOAP <span style="font-weight:400;font-size:6pt;opacity:.8;">(supplemental to the summary above)</span></div>
        <div class="pr-cb">
          <div class="pr-sp">
            <?php if (!empty($_soapEntry['objective'])): ?><div class="pr-sr"><div class="pr-sb pr-so">O</div><div><div class="pr-sl">Objective</div><div class="pr-st"><?php echo mdipr_note_text($_soapEntry['objective']); ?></div></div></div><?php endif; ?>
            <?php if (!empty($_soapEntry['assessment'])): ?>
            <div class="pr-sr"><div class="pr-sb pr-sa">A</div><div><div class="pr-sl">Assessment</div>
              <?php if ($_soapIdx === 0 && !empty($diagRowsUniq)): ?><?php echo mdipr_dx_inline($diagRowsUniq, 'margin-bottom:4px;'); ?><?php endif; ?>
              <div class="pr-st"><?php echo mdipr_note_text($_soapEntry['assessment']); ?></div>
            </div></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php
          continue;
        }

        $_hasAnyContent = !empty($_soapEntry['subjective'])||!empty($_soapEntry['objective'])||!empty($_soapEntry['assessment'])||!empty($_soapEntry['plan']);
        if (!$_hasAnyContent) continue; // skip genuinely empty form_soap rows
        $_soapRendered = true;
        $_cardSubtitle = isset($_cardTitles[$_noteType]) ? '' : '(SOAP NOTE)';
        $_cardHeading  = isset($_cardTitles[$_noteType]) ? $_cardTitles[$_noteType] : 'Daily SOAP';
      ?>
      <div class="pr-c">
        <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><?php echo mdipr_esc($_cardHeading); ?><?php if ($_cardSubtitle): ?> <span style="font-weight:400;font-size:6pt;opacity:.8;"><?php echo mdipr_esc($_cardSubtitle); ?></span><?php endif; ?></div>
        <div class="pr-cb">
          <?php if (isset($_cardTitles[$_noteType])): ?>
            <?php echo mdipr_render_structured_body($_soapEntry['subjective'], $_noteType); ?>
            <?php if ($_noteType === 'reexam' && !empty($_soapEntry['plan'])): ?>
            <!-- Re-Exam keeps Plan as a normal, separately-editable field
                 (see general_new.html) — print it the same way a regular
                 SOAP Plan would render, since it isn't part of the
                 structured note body. -->
            <div class="pr-sr" style="margin-top:8px;"><div class="pr-sb pr-sp2">P</div><div><div class="pr-sl">Plan</div><div class="pr-st"><?php echo mdipr_note_text($_soapEntry['plan']); ?></div></div></div>
            <?php endif; ?>
          <?php else: ?>
          <div class="pr-sp">
            <?php if (!empty($_soapEntry['subjective'])): ?><div class="pr-sr"><div class="pr-sb pr-ss">S</div><div><div class="pr-sl">Subjective</div><div class="pr-st"><?php echo mdipr_note_text($_soapEntry['subjective']); ?></div></div></div><?php endif; ?>
            <?php if (!empty($_soapEntry['objective'])): ?><div class="pr-sr"><div class="pr-sb pr-so">O</div><div><div class="pr-sl">Objective</div><div class="pr-st"><?php echo mdipr_note_text($_soapEntry['objective']); ?></div></div></div><?php endif; ?>
            <?php if (!empty($_soapEntry['assessment'])): ?>
            <div class="pr-sr"><div class="pr-sb pr-sa">A</div><div><div class="pr-sl">Assessment</div>
              <?php if ($_soapIdx === 0 && !empty($diagRowsUniq)): ?><?php echo mdipr_dx_inline($diagRowsUniq, 'margin-bottom:4px;'); ?><?php endif; ?>
              <div class="pr-st"><?php echo mdipr_note_text($_soapEntry['assessment']); ?></div>
            </div></div>
            <?php endif; ?>
            <?php if (!empty($_soapEntry['plan'])): ?><div class="pr-sr"><div class="pr-sb pr-sp2">P</div><div><div class="pr-sl">Plan</div><div class="pr-st"><?php echo mdipr_note_text($_soapEntry['plan']); ?></div></div></div><?php endif; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$_soapRendered && !empty($diagRowsUniq)): ?>
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Documentation</div>
      <div class="pr-cb">
        <?php echo mdipr_dx_inline($diagRowsUniq); ?>
      </div>
    </div>
    <?php elseif (!$_soapRendered): ?>
    <div class="pr-c">
      <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>Documentation</div>
      <div class="pr-cb"><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No clinical note recorded.</div></div>
    </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="pr-ft">
    <span><?php echo mdipr_esc($_fn); ?><?php if ($_fa) echo ' &mdash; '.mdipr_esc($_fa); ?></span>
    <span>Confidential &mdash; For Authorized Use Only</span>
    <span>Page 1 of 2</span>
  </div>
</div><!-- page 1 -->


<!-- ══════ PAGE 2 ══════ -->
<div class="pr-pg pr-pg-2">

  <!-- ══ 5. SUPPORTING SECTIONS ═══════════════════════════════════════
       Intake Questionnaire, Pain Assessment, Imaging, Attachments. -->
  <div class="pr-sec-lbl">SUPPORTING SECTIONS</div>

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

  <!-- Attachments -->
  <div class="pr-c" style="margin-bottom:7px;">
    <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>Attachments</div>
    <div class="pr-cb"><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No attachments for this encounter.</div></div>
  </div>

  <!-- Patient Education -->
  <?php if ($_edu): ?>
  <div class="pr-c" style="margin-bottom:7px;">
    <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>Patient Education</div>
    <div class="pr-cb"><div style="font-size:8pt;">Patient education provided during this encounter.</div></div>
  </div>
  <?php endif; ?>

  <!-- Billing & administrative data — placed here, directly above the
       signature, in normal document flow. No forced page-break is applied
       to this pair of cards, so the browser paginates them naturally: if
       they fit on the current page they stay put, otherwise they flow onto
       the next page along with everything below them, the same as any
       other card on the report. -->
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

  <!-- ══ 6. SIGNATURE ════════════════════════════════════════════════════
       Provider signature, signed date/time, addendum history. -->
  <div class="pr-sec-lbl">SIGNATURE</div>
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

  <!-- Addendum History — every esign_signatures row beyond the first
       (newest) represents an earlier signature later superseded by a
       re-signature, e.g. after the note was amended. The current/active
       signature is shown above; anything older prints here as the
       addendum trail. -->
  <div class="pr-c" style="margin-top:7px;">
    <div class="pr-ch"><svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="10"/></svg>Addendum History</div>
    <div class="pr-cb">
      <?php if (count($esignHistory) > 1): ?>
      <table class="pr-it">
        <?php for ($_ei = 1; $_ei < count($esignHistory); $_ei++): ?>
        <tr><td><?php echo mdipr_esc($esignHistory[$_ei]['datetime']); ?></td><td><?php echo mdipr_esc($esignHistory[$_ei]['signer']); ?></td></tr>
        <?php endfor; ?>
      </table>
      <?php else: ?><div style="font-size:7.5pt;color:#9ca3af;font-style:italic;">No addenda recorded for this encounter.</div><?php endif; ?>
    </div>
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
