<?php
/**
 * mdi_hist_enc_list.php
 *
 * JSON endpoint — returns historical encounters for a patient from
 * the mdi_historical_encounters table (imported from legacy systems).
 *
 * Called by patient_data_template.php via fetch():
 *   /interface/main/tabs/templates/mdi_hist_enc_list.php?pid=<pid>
 *
 * Response shape:
 *   { "encounters": [ { encounter_date, visit_number, initial_visit_date,
 *                        chief_complaint, subjective, objective, assessment,
 *                        plan, treatment, diagnoses, pain_scale, rom,
 *                        functional_status, provider_notes, original_filename,
 *                        import_source }, ... ] }
 */

// ── Bootstrap ─────────────────────────────────────────────────────────────
require_once dirname(__FILE__, 4) . '/globals.php';

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

// Drain any buffered HTML before we send JSON
while (ob_get_level() > 0) { ob_get_clean(); }
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

// ── Auth ──────────────────────────────────────────────────────────────────
if (!isset($_SESSION['authUser'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'encounters' => []]);
    exit;
}

if (!AclMain::aclCheckCore('patients', 'demo')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden', 'encounters' => []]);
    exit;
}

// ── Input ─────────────────────────────────────────────────────────────────
$pid = (int)($_GET['pid'] ?? 0);
if ($pid <= 0) {
    echo json_encode(['encounters' => []]);
    exit;
}

// ── Query mdi_historical_encounters ───────────────────────────────────────
try {
    $result = sqlStatement(
        "SELECT
            id,
            encounter,
            encounter_date,
            visit_number,
            initial_visit_date,
            chief_complaint,
            subjective,
            objective,
            assessment,
            plan,
            treatment,
            diagnoses,
            procedures,
            pain_scale,
            functional_status,
            rom,
            recommendations,
            provider_notes,
            original_filename,
            import_source,
            created_at
         FROM mdi_historical_encounters
         WHERE pid = ?
           AND (encounter_date IS NOT NULL OR created_at IS NOT NULL)
         ORDER BY encounter_date DESC, id DESC
         LIMIT 500",
        [$pid]
    );
} catch (\Throwable $e) {
    // Table may not exist yet on all installs
    echo json_encode(['encounters' => [], 'error' => 'Table not available: ' . $e->getMessage()]);
    exit;
}

// ── Format rows ───────────────────────────────────────────────────────────
$encounters = [];
while ($row = sqlFetchArray($result)) {
    // Format dates for display
    $encDate  = !empty($row['encounter_date'])     ? date('M j, Y', strtotime($row['encounter_date']))     : '';
    $initDate = !empty($row['initial_visit_date']) ? date('M j, Y', strtotime($row['initial_visit_date'])) : '';

    // Truncate very long text fields to keep JSON payload reasonable
    $encounters[] = [
        'id'                => (int)$row['id'],
        'pid'               => $pid,
        'encounter'         => (int)$row['encounter'],
        'encounter_date'    => $encDate,
        'encounter_date_raw'=> $row['encounter_date'] ?? '',
        'visit_number'      => $row['visit_number']      ?? '',
        'initial_visit_date'=> $initDate,
        'chief_complaint'   => $row['chief_complaint']   ?? '',
        'subjective'        => $row['subjective']        ?? '',
        'objective'         => $row['objective']         ?? '',
        'assessment'        => $row['assessment']        ?? '',
        'plan'              => $row['plan']              ?? '',
        'treatment'         => $row['treatment']         ?? '',
        'diagnoses'         => $row['diagnoses']         ?? '',
        'procedures'        => $row['procedures']        ?? '',
        'pain_scale'        => $row['pain_scale']        ?? '',
        'functional_status' => $row['functional_status'] ?? '',
        'rom'               => $row['rom']               ?? '',
        'recommendations'   => $row['recommendations']   ?? '',
        'provider_notes'    => $row['provider_notes']    ?? '',
        'original_filename' => $row['original_filename'] ?? '',
        'import_source'     => $row['import_source']     ?? '',
        'created_at'        => $row['created_at']        ?? '',
    ];
}

echo json_encode(['encounters' => $encounters], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
