<?php
/**
 * mdi_enc_list.php — Encounter list for encounter side panel
 * Deploy to:
 * /interface/main/tabs/templates/mdi_enc_list.php
 */

$sessionAllowWrite = false;
require_once(__DIR__ . '/../../../globals.php');

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['authUser'])) {
    http_response_code(403);

    echo json_encode([
        'error' => 'Not authorized'
    ]);

    exit;
}

/**
 * Resolve PID
 */
$pid = (int)($_GET['pid'] ?? 0);

if ($pid <= 0 && !empty($_SESSION['pid'])) {
    $pid = (int)$_SESSION['pid'];
}

if ($pid <= 0) {

    echo json_encode([
        'encounters' => [],
        'error' => 'Missing pid'
    ]);

    exit;
}

/**
 * Convert billing-only encounter labels
 * into cleaner clinical UI labels.
 */
function mdiClinicalCategory(string $raw, int $xrayCount): string
{
    $lower = strtolower(trim($raw));

    $isBilling = (
        strpos($lower, 'charge') !== false ||
        strpos($lower, 'quick') !== false
    );

    if ($isBilling) {
        return ($xrayCount > 0)
            ? 'Procedure'
            : 'Office Visit';
    }

    if (
        strpos($lower, 'xray') !== false ||
        strpos($lower, 'x-ray') !== false ||
        strpos($lower, 'imaging') !== false ||
        strpos($lower, 'radiol') !== false
    ) {
        return 'Procedure';
    }

    return trim($raw) ?: 'Office Visit';
}

$encounters = [];

try {

    $res = sqlStatement(
        "
        SELECT
            fe.id AS form_id,
            fe.encounter AS encounter_id,

            DATE(fe.date) AS enc_date,

            COALESCE(
                NULLIF(TRIM(fe.reason), ''),
                opc.pc_catname,
                'Office Visit'
            ) AS raw_category,

            TRIM(
                CONCAT(
                    COALESCE(u.fname, ''),
                    ' ',
                    COALESCE(u.lname, '')
                )
            ) AS provider,

            /* SIGNED STATUS */
            (
                SELECT MAX(
                    CASE
                        WHEN es.id IS NOT NULL THEN 1
                        ELSE 0
                    END
                )
                FROM esign_signatures es
                WHERE es.tid = fe.encounter
                  AND es.`table` = 'form_encounter'
                  AND es.is_lock = 1
            ) AS signed_count,

            /* XRAY / PROCEDURE DETECTION */
            (
                SELECT COUNT(*)
                FROM billing b
                WHERE b.encounter = fe.encounter
                  AND b.pid = fe.pid
                  AND b.activity = 1
                  AND b.code_type = 'CPT4'
                  AND CAST(b.code AS UNSIGNED)
                        BETWEEN 70000 AND 79999
            ) AS xray_count

        FROM form_encounter fe

        LEFT JOIN openemr_postcalendar_categories opc
            ON opc.pc_catid = fe.pc_catid

        LEFT JOIN users u
            ON u.id = fe.provider_id

        WHERE fe.pid = ?

        ORDER BY fe.date DESC, fe.encounter DESC
        ",
        [$pid]
    );

    while ($row = sqlFetchArray($res)) {

        $category = mdiClinicalCategory(
            (string)($row['raw_category'] ?? ''),
            (int)($row['xray_count'] ?? 0)
        );

        $provider = trim((string)($row['provider'] ?? ''));

        /**
         * Encounter signed/open status
         */
        $status = ((int)($row['signed_count'] ?? 0) > 0)
            ? 'Signed'
            : 'Open';

        $encounters[] = [
            'id'            => (string)$row['encounter_id'],
            'date'          => (string)$row['enc_date'],
            'category'      => $category,
            'provider'      => $provider,
            'status'        => $status,
            'status_detail' => $status,
        ];
    }

} catch (Throwable $e) {

    error_log('mdi_enc_list error: ' . $e->getMessage());

    echo json_encode([
        'encounters' => [],
        'debug' => $e->getMessage()
    ]);

    exit;
}

echo json_encode([
    'pid'        => $pid,
    'count'      => count($encounters),
    'encounters' => $encounters
]);