<?php
/**
 * mdi_attach_forms_api.php — powers the "Patient Forms Library" panel.
 *
 * Place at: interface/patient_file/encounter/mdi_attach_forms_api.php
 *
 * Endpoints:
 *   GET  ?action=my_forms&pid=X&encounter=Y
 *        -> { pinned: {...}|null, encounters:[ {encounter, date, date_label,
 *             reason, provider, signed, forms:[...]} , ... ] }
 *        Pinned = the patient's single most recent Patient Intake
 *        Questionnaire, always shown at the top regardless of which
 *        encounter it came from. Encounters = the last 6 encounters
 *        before the current one.
 *
 *   GET  ?action=all_forms&pid=X&encounter=Y&search=&category=&provider=&time=
 *        -> { encounters:[...], providers:[{id,name}] }
 *        Every prior encounter (capped at 50), each with its attachable
 *        forms filtered by the given search/category/provider/time
 *        params. An encounter is omitted if none of its forms match.
 *
 *   GET  ?action=preview&form_id=X&pid=Y
 *        -> { success:true, html: "<...>" }
 *        Renders the form's own read-only report view (the same
 *        report.php dispatch this page already uses for the main form
 *        list) so it can be shown in a lightweight preview modal.
 *
 *   POST action=attach, pid, encounter, source_form_id, csrf_token_form
 *        -> copies the selected form's data forward into a brand-new form
 *           attached to the current encounter, pre-filled with the same
 *           answers.
 *
 * "Attachable" forms cover essentially every clinical form type on the
 * encounter — SOAP, ROS, Vitals, Imaging Findings, the intake
 * questionnaire, and any LBF-family form — excluding only the
 * auto-generated Visit Summary (newpatient) and the purely
 * administrative/billing LBF variants (LBFref, LBFbilling, LBFclaim,
 * misc_billing_options). See MDIAF_ATTACHABLE_WHERE below.
 */

require_once __DIR__ . '/../../globals.php';

use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');
header('Cache-Control: no-store');

function mdiaf_out($arr)
{
    echo json_encode($arr);
    exit;
}

if (empty($_SESSION['authUser'])) {
    http_response_code(401);
    mdiaf_out(['success' => false, 'message' => 'Not authenticated.']);
}

function mdiaf_form_label(string $formdir, string $form_name): string
{
    if ($formdir === 'newpatient')                return xl('New Patient Encounter');
    if ($formdir === 'questionnaire_assessments') return xl('Patient Intake Questionnaire');
    if ($formdir === 'mditek_imaging_findings')   return xl('Imaging Findings');
    $resolved = xl_form_title($form_name);
    return (trim($resolved) !== '') ? $resolved : xl($form_name ?: 'Unknown Form');
}

// Simple keyword-based categorization — Clinical vs Outcome — since we
// don't know every custom LBF formdir/label this install might use.
function mdiaf_category(string $formdir, string $label): string
{
    $hay = strtolower($formdir . ' ' . $label);
    foreach (['outcome', 'disability', 'odi', 'oswestry', 'vas', 'nds', 'functional score', 'pain scale'] as $kw) {
        if (strpos($hay, $kw) !== false) {
            return 'Outcome';
        }
    }
    return 'Clinical';
}

function mdiaf_relative_date(string $dateStr): string
{
    if (empty($dateStr) || strtotime($dateStr) <= 0) {
        return '';
    }
    $days = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($dateStr)))) / 86400);
    if ($days <= 0)  return 'Today';
    if ($days == 1)  return 'Yesterday';
    if ($days < 7)   return $days . ' days ago';
    if ($days < 14)  return '1 week ago';
    if ($days < 30)  return (int) floor($days / 7) . ' weeks ago';
    if ($days < 60)  return '1 month ago';
    return (int) floor($days / 30) . ' months ago';
}

// Which forms show up as attachable — a blacklist (rather than a
// whitelist of specific formdirs) so SOAP, ROS, Vitals, Imaging
// Findings, the intake questionnaire, and any custom clinical form type
// are all attachable. Only the encounter's own auto-generated Visit
// Summary and the purely administrative/billing LBF variants are
// excluded.
//
// form_name is checked against 'newpatient' as a belt-and-suspenders
// backstop for the visit summary (already excluded by formdir above) —
// but NOT against '' (blank), since plenty of legitimate forms (the
// intake questionnaire among them) simply never populate form_name.
// Excluding blank form_name here made those forms show up in the "My
// Forms" listing (which never applied this filter) but then fail with
// "Source form not found" the moment you tried to attach or preview
// them, since attach/preview both check this same constant.
const MDIAF_ATTACHABLE_WHERE = "
    f.formdir NOT IN ('newpatient', 'misc_billing_options', 'LBFref', 'LBFbilling', 'LBFclaim')
    AND (f.form_name IS NULL OR f.form_name <> 'newpatient')
    AND f.deleted = 0
";

function mdiaf_is_signed(int $pid, int $encounter): bool
{
    $row = sqlQuery(
        "SELECT 1 AS x
           FROM form_encounter fe
           LEFT JOIN esign_signatures es ON es.tid = fe.encounter AND es.`table` = 'form_encounter'
           LEFT JOIN mdi_signatures ms ON ms.encounter = fe.encounter AND ms.pid = fe.pid
          WHERE fe.encounter = ? AND fe.pid = ? AND (es.id IS NOT NULL OR ms.id IS NOT NULL)
          LIMIT 1",
        [$encounter, $pid]
    );
    return !empty($row);
}

function mdiaf_forms_for_encounter(int $pid, int $enc): array
{
    $rows = sqlStatement(
        "SELECT f.id, f.formdir, f.form_name, f.date
           FROM forms f
          WHERE f.pid = ? AND f.encounter = ? AND " . MDIAF_ATTACHABLE_WHERE . "
          ORDER BY f.date DESC",
        [$pid, $enc]
    );
    $out = [];
    while ($r = sqlFetchArray($rows)) {
        $label = mdiaf_form_label($r['formdir'], $r['form_name'] ?? '');
        $out[] = [
            'id'       => (int) $r['id'],
            'formdir'  => $r['formdir'],
            'label'    => $label,
            'category' => mdiaf_category($r['formdir'], $label),
            'date'     => !empty($r['date']) ? date('m/d/Y g:i A', strtotime($r['date'])) : '',
        ];
    }
    return $out;
}

function mdiaf_encounter_meta(int $pid, int $enc, string $date): array
{
    $encRow = sqlQuery('SELECT reason FROM form_encounter WHERE encounter = ? AND pid = ? LIMIT 1', [$enc, $pid]);
    $providerId = function_exists('getProviderIdOfEncounter') ? getProviderIdOfEncounter($enc) : 0;
    $providerName = $providerId && function_exists('getProviderName') ? getProviderName($providerId, false) : '';
    return [
        'encounter'   => $enc,
        'date'        => date('M j, Y', strtotime($date)),
        'date_label'  => mdiaf_relative_date($date),
        'reason'      => $encRow['reason'] ?? '',
        'provider_id' => (int) $providerId,
        'provider'    => trim((string) $providerName),
        'signed'      => mdiaf_is_signed($pid, $enc),
    ];
}

$action    = $_GET['action'] ?? $_POST['action'] ?? '';
$pid       = (int) ($_GET['pid'] ?? $_POST['pid'] ?? 0);
$encounter = (int) ($_GET['encounter'] ?? $_POST['encounter'] ?? 0);

if (!$pid) {
    mdiaf_out(['success' => false, 'message' => 'Missing patient.']);
}

// ── My Forms: pinned Patient Intake + the last 6 prior encounters ───────
if ($action === 'my_forms' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $encRows = sqlStatement(
        "SELECT encounter, date FROM form_encounter
          WHERE pid = ? AND encounter != ?
          ORDER BY date DESC LIMIT 6",
        [$pid, $encounter]
    );
    $encounters = [];
    while ($e = sqlFetchArray($encRows)) {
        $meta = mdiaf_encounter_meta($pid, (int) $e['encounter'], $e['date']);
        $meta['forms'] = mdiaf_forms_for_encounter($pid, (int) $e['encounter']);
        if (!empty($meta['forms'])) {
            $encounters[] = $meta;
        }
    }

    // Pinned: the patient's most recent Patient Intake Questionnaire,
    // always shown at the top of My Forms regardless of which encounter
    // it came from (so it's never buried under six encounters' worth of
    // other forms).
    $pinned = null;
    $intakeRow = sqlQuery(
        "SELECT f.id, f.formdir, f.form_name, f.date, f.encounter
           FROM forms f
          WHERE f.pid = ? AND f.formdir = 'questionnaire_assessments' AND " . MDIAF_ATTACHABLE_WHERE . "
          ORDER BY f.date DESC LIMIT 1",
        [$pid]
    );
    if (!empty($intakeRow)) {
        $meta = mdiaf_encounter_meta($pid, (int) $intakeRow['encounter'], $intakeRow['date']);
        $pinned = [
            'id'       => (int) $intakeRow['id'],
            'formdir'  => $intakeRow['formdir'],
            'label'    => mdiaf_form_label($intakeRow['formdir'], $intakeRow['form_name'] ?? ''),
            'category' => 'Intake',
            'date'     => !empty($intakeRow['date']) ? date('m/d/Y', strtotime($intakeRow['date'])) : '',
            'provider' => $meta['provider'],
        ];
    }

    mdiaf_out(['pinned' => $pinned, 'encounters' => $encounters]);
}

// ── All Forms: every prior encounter, filtered ───────────────────────────
if ($action === 'all_forms' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $search   = trim((string) ($_GET['search'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $provider = (int) ($_GET['provider'] ?? 0);
    $time     = trim((string) ($_GET['time'] ?? ''));

    $encWhere  = "pid = ? AND encounter != ?";
    $encParams = [$pid, $encounter];
    if ($time === '30' || $time === '90' || $time === '365') {
        $encWhere .= " AND date >= DATE_SUB(NOW(), INTERVAL ? DAY)";
        $encParams[] = (int) $time;
    }

    $encRows = sqlStatement(
        "SELECT encounter, date FROM form_encounter
          WHERE $encWhere
          ORDER BY date DESC LIMIT 50",
        $encParams
    );

    $encounters = [];
    $providerSet = [];
    while ($e = sqlFetchArray($encRows)) {
        $enc = (int) $e['encounter'];
        $meta = mdiaf_encounter_meta($pid, $enc, $e['date']);

        if ($provider && $meta['provider_id'] !== $provider) {
            continue;
        }
        if ($meta['provider_id']) {
            $providerSet[$meta['provider_id']] = $meta['provider'];
        }

        $forms = mdiaf_forms_for_encounter($pid, $enc);
        if ($search !== '') {
            $forms = array_values(array_filter($forms, function ($f) use ($search) {
                return stripos($f['label'], $search) !== false;
            }));
        }
        if ($category !== '' && $category !== 'All Categories') {
            $forms = array_values(array_filter($forms, function ($f) use ($category) {
                return strcasecmp($f['category'], $category) === 0;
            }));
        }
        if (empty($forms)) {
            continue;
        }

        $meta['forms'] = $forms;
        $encounters[] = $meta;
    }

    $providers = [];
    foreach ($providerSet as $pidKey => $pname) {
        $providers[] = ['id' => $pidKey, 'name' => $pname];
    }

    mdiaf_out(['encounters' => $encounters, 'providers' => $providers]);
}

// ── Preview: read-only render of a form using its own report view ───────
if ($action === 'preview' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $formRowId = (int) ($_GET['form_id'] ?? 0);
    if (!$formRowId) {
        mdiaf_out(['success' => false, 'message' => 'No form specified.']);
    }
    $f = sqlQuery(
        "SELECT f.id, f.form_id, f.formdir, f.form_name, f.encounter, f.pid
           FROM forms f
          WHERE f.id = ? AND f.pid = ? AND " . MDIAF_ATTACHABLE_WHERE . "
          LIMIT 1",
        [$formRowId, $pid]
    );
    if (empty($f)) {
        mdiaf_out(['success' => false, 'message' => 'Form not found.']);
    }

    $formdir = $f['formdir'];
    $fEncounter = (int) $f['encounter'];
    $attendantId = $pid; // matches this page's own $attendant_id for the 'pid' attendant type

    ob_start();
    try {
        if (substr($formdir, 0, 3) === 'LBF') {
            include_once($GLOBALS['incdir'] . '/forms/LBF/report.php');
            lbf_report($attendantId, $fEncounter, 2, $f['form_id'], $formdir, true);
        } elseif ($formdir === 'questionnaire_assessments') {
            include_once($GLOBALS['incdir'] . '/forms/questionnaire_assessments/report.php');
            call_user_func('questionnaire_assessments_report', $attendantId, $fEncounter, 2, $f['form_id']);
        } elseif ($formdir === 'mditek_imaging_findings') {
            $imf = sqlQuery(
                'SELECT findings, created_at FROM mdi_imaging_findings WHERE pid=? AND encounter=? ORDER BY created_at DESC LIMIT 1',
                [$pid, $fEncounter]
            );
            if (!empty($imf['findings'])) {
                echo '<div style="padding:4px 0;font-family:system-ui,sans-serif;">'
                    . '<div style="font-size:13px;color:#1e0a3c;line-height:1.65;white-space:pre-wrap;">'
                    . htmlspecialchars($imf['findings']) . '</div></div>';
            } else {
                echo '<div style="padding:10px 0;font-size:12px;color:#9ca3af;font-style:italic;">No imaging findings recorded.</div>';
            }
        } else {
            $report_file = $GLOBALS['incdir'] . "/forms/$formdir/report.php";
            if (file_exists($report_file)) {
                include_once($report_file);
                if (function_exists($formdir . '_report')) {
                    call_user_func($formdir . '_report', $attendantId, $fEncounter, 2, $f['form_id']);
                } else {
                    echo '<div style="padding:10px 0;font-size:12px;color:#9ca3af;font-style:italic;">Preview not available for this form type.</div>';
                }
            } else {
                echo '<div style="padding:10px 0;font-size:12px;color:#9ca3af;font-style:italic;">Preview not available for this form type.</div>';
            }
        }
    } catch (Throwable $e) {
        error_log('mdi_attach_forms_api preview: ' . $e->getMessage());
        echo '<div style="padding:10px 0;font-size:12px;color:#9ca3af;font-style:italic;">Unable to render preview.</div>';
    }
    $html = ob_get_clean();

    mdiaf_out(['success' => true, 'label' => mdiaf_form_label($formdir, $f['form_name'] ?? ''), 'html' => $html]);
}

// ── Attach: copy a form's data forward into the current encounter ───────
if ($action === 'attach') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        mdiaf_out(['success' => false, 'message' => 'Invalid request method.']);
    }
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        http_response_code(403);
        mdiaf_out(['success' => false, 'message' => 'Your session expired. Please refresh the page and try again.']);
    }
    if (!$encounter) {
        mdiaf_out(['success' => false, 'message' => 'Missing target encounter.']);
    }

    $sourceFormRowId = (int) ($_POST['source_form_id'] ?? 0);
    if (!$sourceFormRowId) {
        mdiaf_out(['success' => false, 'message' => 'No form selected.']);
    }

    $src = sqlQuery(
        "SELECT f.id, f.form_id, f.formdir, f.form_name
           FROM forms f
          WHERE f.id = ? AND f.pid = ? AND " . MDIAF_ATTACHABLE_WHERE . "
          LIMIT 1",
        [$sourceFormRowId, $pid]
    );
    if (empty($src)) {
        mdiaf_out(['success' => false, 'message' => 'Source form not found.']);
    }

    $formdir = $src['formdir'];
    $dataTable = 'form_' . $formdir;
    if (!preg_match('/^form_[A-Za-z0-9_]+$/', $dataTable)) {
        mdiaf_out(['success' => false, 'message' => 'Unrecognized form type.']);
    }

    try {
        $srcData = sqlQuery("SELECT * FROM `$dataTable` WHERE id = ? LIMIT 1", [$src['form_id']]);
        if (empty($srcData)) {
            mdiaf_out(['success' => false, 'message' => 'Could not find the original form data to copy.']);
        }
        unset($srcData['id']);
        $nowDateTime = date('Y-m-d H:i:s');
        if (array_key_exists('pid', $srcData))       $srcData['pid'] = $pid;
        if (array_key_exists('date', $srcData))      $srcData['date'] = $nowDateTime;
        if (array_key_exists('groupname', $srcData)) $srcData['groupname'] = 'Default';
        if (array_key_exists('user', $srcData))      $srcData['user'] = $_SESSION['authUser'] ?? '';
        if (array_key_exists('activity', $srcData))  $srcData['activity'] = 1;

        $cols   = array_keys($srcData);
        $qmarks = implode(',', array_fill(0, count($cols), '?'));
        $colSql = implode(',', array_map(function ($c) { return "`$c`"; }, $cols));
        $newDataId = sqlInsert("INSERT INTO `$dataTable` ($colSql) VALUES ($qmarks)", array_values($srcData));
        if (!$newDataId) {
            mdiaf_out(['success' => false, 'message' => 'Could not copy the form data.']);
        }

        sqlStatement(
            "INSERT INTO forms (date, encounter, form_name, formdir, form_id, pid, user, groupname, authorized, deleted)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'Default', 1, 0)",
            [
                $nowDateTime,
                $encounter,
                $src['form_name'],
                $formdir,
                $newDataId,
                $pid,
                $_SESSION['authUser'] ?? '',
            ]
        );

        mdiaf_out(['success' => true]);
    } catch (Throwable $e) {
        error_log('mdi_attach_forms_api attach: ' . $e->getMessage());
        mdiaf_out(['success' => false, 'message' => 'Could not attach this form. Please try again.']);
    }
}

mdiaf_out(['success' => false, 'message' => 'Unknown action.']);
