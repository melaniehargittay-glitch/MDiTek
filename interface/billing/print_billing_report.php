<?php
/*
 * Print billing report — MDItek
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");

use OpenEMR\Billing\BillingReport;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// ── Accept params from either GET (dlgopen iframe) or POST ──────────────────
function rp(string $key, string $default = ''): string {
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

$from_date   = rp('from_date',  date('Y-m-d'));
$to_date     = rp('to_date',    '');
$code_type   = rp('code_type',  'all');
$unbilled    = rp('unbilled',   'on');
$my_auth     = rp('authorized', 'on');
$bm_billed   = rp('bm_billed',  '');      // '0'=unbilled, '1'=billed, ''=all (from billing_report filter)
$bm_patient  = rp('bm_patient', '');      // pid filter
$bm_bill_from = rp('bm_bill_from', '');
$bm_bill_to   = rp('bm_bill_to',   '');
$bm_submitter = rp('bm_submitter',  '');
$bm_insurance = rp('bm_insurance',  '');

// Normalise legacy params
$my_auth_sql = ($my_auth === 'on' || $my_auth === '1') ? '1' : '%';

// ── Prime the globals that getBillsBetween() reads internally ────────────────
global $from_date, $to_date, $unbilled, $my_authorized;
$from_date     = rp('from_date',  '0000-01-01');
$to_date       = rp('to_date',    date('Y-m-d'));
$unbilled      = '%';
$my_authorized = $my_auth_sql;

// ── Build direct SQL query using active filter params ─────────────────────────
// This mirrors BillingReport::getBillsBetween but applies our filters at query time.
$sql_where   = ["b.activity = 1", "b.pid IS NOT NULL"];
$sql_params  = [];

// Service date range (from billing_report's hidden from_date/to_date fields)
if (!empty($from_date) && $from_date !== '0000-01-01') {
    $sql_where[] = "fe.date >= ?";
    $sql_params[] = $from_date . ' 00:00:00';
}
if (!empty($to_date)) {
    $sql_where[] = "fe.date <= ?";
    $sql_params[] = $to_date . ' 23:59:59';
}

// Billing date range (from bm_bill_from / bm_bill_to filter bar fields)
if (!empty($bm_bill_from)) {
    $sql_where[] = "b.date >= ?";
    $sql_params[] = $bm_bill_from . ' 00:00:00';
}
if (!empty($bm_bill_to)) {
    $sql_where[] = "b.date <= ?";
    $sql_params[] = $bm_bill_to . ' 23:59:59';
}

// Insurance filter
if (!empty($bm_insurance) && is_numeric($bm_insurance)) {
    $sql_where[] = "c.payer_id = ?";
    $sql_params[] = (int)$bm_insurance;
}

// Billing status filter at SQL level
if ($bm_billed !== '') {
    $sql_where[] = "b.billed = ?";
    $sql_params[] = (int)$bm_billed;
}

// Exclude legacy balance transfers
$sql_where[] = "b.code_text NOT LIKE 'LEGACY BALANCE%'";
$sql_where[] = "b.code NOT LIKE 'PREVBAL-%'";

// Code type filter
if (!empty($code_type) && $code_type !== 'all') {
    $sql_where[] = "b.code_type = ?";
    $sql_params[] = $code_type;
}

$where_clause = implode(' AND ', $sql_where);

$sql = "SELECT
            b.id, b.pid, b.encounter, b.code_type, b.code, b.modifier,
            b.code_text, b.units, b.fee, b.billed, b.authorized,
            b.date AS bill_date, b.x12_partner_id,
            fe.date AS enc_date, fe.encounter AS enc_encounter, fe.pid AS enc_pid,
            pd.fname, pd.lname, pd.pubpid
        FROM billing b
        LEFT JOIN code_types ct ON ct.ct_key = b.code_type
        LEFT JOIN form_encounter fe ON fe.encounter = b.encounter AND fe.pid = b.pid
        LEFT JOIN patient_data pd ON pd.pid = b.pid
        LEFT JOIN claims c ON c.encounter_id = b.encounter AND c.patient_id = b.pid AND c.version = (
            SELECT MAX(c2.version) FROM claims c2 WHERE c2.encounter_id = b.encounter AND c2.patient_id = b.pid
        )
        WHERE {$where_clause}
        ORDER BY pd.lname, pd.fname, fe.date, b.encounter, b.id";

$ret_res = sqlStatement($sql, $sql_params);
$ret = [];
while ($row = sqlFetchArray($ret_res)) {
    $ret[] = $row;
}

// ── PHP post-filters ─────────────────────────────────────────────────────────
$bm_billed_filter = null; // already handled in SQL above

// Patient name filter (normalised, commas stripped)
$bm_patient_filter = strtolower(trim(str_replace(',', ' ', $bm_patient)));
$bm_patient_filter = preg_replace('/\s+/', ' ', $bm_patient_filter);

// X12 submitter post-filter
$bm_submitter_filter = (is_numeric($bm_submitter) && $bm_submitter !== '')
    ? (int)$bm_submitter : null;

// ── Apply remaining post-filters and group by encounter ──────────────────────
$rows = [];
foreach ($ret as $iter) {
    if (!$iter['id']) continue;

    // X12 submitter post-filter
    if ($bm_submitter_filter !== null && (int)($iter['x12_partner_id'] ?? 0) !== $bm_submitter_filter) continue;

    // Patient/PID post-filter
if ($bm_patient_filter !== '') {

    $lname = strtolower(trim($iter['lname'] ?? ''));
    $fname = strtolower(trim($iter['fname'] ?? ''));
    $pid   = strtolower(trim((string)($iter['pid'] ?? '')));
    $pubpid = strtolower(trim((string)($iter['pubpid'] ?? '')));

    // Normalize spacing
    $full1 = preg_replace('/\s+/', ' ', trim($lname . ' ' . $fname));
    $full2 = preg_replace('/\s+/', ' ', trim($fname . ' ' . $lname));
    $full3 = preg_replace('/\s+/', ' ', trim($lname . ', ' . $fname));

    $search = preg_replace('/\s+/', ' ', strtolower(trim($bm_patient_filter)));

    $matched =
        strpos($full1, $search) !== false ||
        strpos($full2, $search) !== false ||
        strpos($full3, $search) !== false ||
        strpos($pid, $search) !== false ||
        strpos($pubpid, $search) !== false;

    if (!$matched) {
        continue;
    }
}

    $rows[] = $iter;
}

// ── Group by encounter ────────────────────────────────────────────────────────
$encounters = [];
foreach ($rows as $row) {
    $pid = $row['enc_pid'] ?? $row['pid'];
    $key = $pid . '-' . $row['enc_encounter'];

    if (!isset($encounters[$key])) {
        $encounters[$key] = [
            'pid'       => $pid,
            'encounter' => $row['enc_encounter'],
            'date'      => $row['enc_date'] ?? '',
            'fname'     => $row['fname'] ?? '',
            'lname'     => $row['lname'] ?? '',
            'charges'   => [],
            'total'     => 0,
        ];
    }
    $encounters[$key]['charges'][] = $row;
    $encounters[$key]['total'] += (float)($row['fee'] ?? 0);
}

$grand_total = array_sum(array_column($encounters, 'total'));
$label_billed = $bm_billed === '1' ? xlt('Billed') : ($bm_billed === '' ? xlt('All') : ($bm_billed === '0' ? xlt('Unbilled') : xlt('Denied')));
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt('Billing Report'); ?></title>
    <style>
        html, body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; font-size: 13px; color: #1a1a2e; background: #fff !important; background-image: none !important; background-color: #fff !important; margin: 0; padding: 0; }

        /* ── Toolbar (hidden on print) ── */
        .no-print { background: #f8f7ff; border-bottom: 1px solid #ddd; padding: 10px 20px; display: flex; align-items: center; justify-content: space-between; }
        .no-print h2 { margin: 0; font-size: 16px; color: #2d1b69; }
        .btn-print { background: #6c5ce7; color: #fff; border: none; border-radius: 6px; padding: 8px 22px; font-size: 13px; font-weight: 600; cursor: pointer; display: flex; align-items: center; gap: 7px; }
        .btn-print:hover { background: #5a4bd1; }
        .btn-print svg { width: 15px; height: 15px; }

        /* ── Report content ── */
        .report-wrap { padding: 20px 24px; }
        .report-header { text-align: center; margin-bottom: 18px; border-bottom: 2px solid #2d1b69; padding-bottom: 12px; }
        .report-header h1 { font-size: 18px; margin: 0 0 4px; color: #2d1b69; }
        .report-header .meta { font-size: 11px; color: #666; }
        .summary-bar { display: flex; gap: 24px; margin-bottom: 18px; padding: 10px 14px; background: #f5f3ff; border-radius: 6px; font-size: 12px; }
        .summary-bar strong { font-size: 13px; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 0; font-size: 12px; }
        thead th { background: #2d1b69; color: #fff; padding: 7px 10px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        thead th.right { text-align: right; }
        .enc-row td { background: #ede9ff; padding: 6px 10px; font-weight: 700; font-size: 12px; border-top: 2px solid #c4b5fd; }
        tbody tr.charge-row td { padding: 5px 10px; border-bottom: 1px solid #e9e9f0; }
        tbody tr.charge-row:hover td { background: #faf9ff; }
        tbody tr.enc-total td { padding: 5px 10px; border-bottom: 2px solid #c4b5fd; font-style: italic; background: #faf8ff; }
        .right { text-align: right; }
        .grand-total-row td { background: #2d1b69; color: #fff; padding: 8px 10px; font-weight: 700; font-size: 13px; }
        .empty-msg { text-align: center; padding: 40px; color: #888; font-size: 14px; }
        .count-badge { background: #6c5ce7; color: #fff; border-radius: 4px; padding: 1px 7px; font-size: 11px; margin-left: 6px; }

        /* ── Print styles ── */
        @media print {
            .no-print { display: none !important; }
            .report-wrap { padding: 0; }
            body { font-size: 11px; }
            thead th { background: #2d1b69 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .enc-row td { background: #ede9ff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .grand-total-row td { background: #2d1b69 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<!-- Toolbar -->
<div class="no-print">
    <h2><?php echo xlt('Billing Report'); ?>
        <?php if (!empty($encounters)): ?>
        <span class="count-badge"><?php echo count($encounters); ?> encounters</span>
        <?php endif; ?>
    </h2>
    <button class="btn-print" onclick="window.print()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        <?php echo xlt('Print'); ?>
    </button>
</div>

<!-- Report -->
<div class="report-wrap">
    <div class="report-header">
        <h1><?php echo xlt('Billing Report'); ?></h1>
        <div class="meta">
            <?php
            $range = '';
            $rangeParts = [];

            // Service date range
            if (!empty($from_date) && $from_date !== '0000-01-01') {
                $svcRange = xlt('Service Dates') . ': ' . text($from_date);

                if (!empty($to_date)) {
                    $svcRange .= ' → ' . text($to_date);
                }

                $rangeParts[] = $svcRange;
            }

            // Billing date range
            if (!empty($bm_bill_from)) {
                $billRange = xlt('Billing Dates') . ': ' . text($bm_bill_from);

                if (!empty($bm_bill_to)) {
                    $billRange .= ' → ' . text($bm_bill_to);
                }

                $rangeParts[] = $billRange;
            }

            echo implode(' | ', $rangeParts);
            ?>
            &nbsp;|&nbsp; <?php echo xlt('Status'); ?>: <?php echo text($label_billed); ?>
            &nbsp;|&nbsp; <?php echo xlt('Generated'); ?>: <?php echo date('Y-m-d g:i A'); ?>
        </div>
    </div>

    <?php if (empty($encounters)): ?>
    <div class="empty-msg"><?php echo xlt('No billing records found matching the current filters.'); ?></div>
    <?php else: ?>

    <div class="summary-bar no-print">
        <div><?php echo xlt('Encounters'); ?>: <strong><?php echo count($encounters); ?></strong></div>
        <div><?php echo xlt('Total Charges'); ?>: <strong><?php echo text(oeFormatMoney($grand_total)); ?></strong></div>
    </div>

    <table>
        <thead>
            <tr>
                <th><?php echo xlt('Patient'); ?></th>
                <th><?php echo xlt('PID'); ?></th>
                <th><?php echo xlt('Encounter'); ?></th>
                <th><?php echo xlt('Service Date'); ?></th>
                <th><?php echo xlt('Billing Date'); ?></th>
                <th><?php echo xlt('Code Type'); ?></th>
                <th><?php echo xlt('Code'); ?></th>
                <th><?php echo xlt('Modifier'); ?></th>
                <th><?php echo xlt('Description'); ?></th>
                <th class="right"><?php echo xlt('Units'); ?></th>
                <th class="right"><?php echo xlt('Fee'); ?></th>
                <th><?php echo xlt('Status'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($encounters as $enc): ?>
            <tr class="enc-row">
                <td colspan="12">
                    <strong><?php echo text($enc['fname'] . ' ' . $enc['lname']); ?></strong>
                    &nbsp;&nbsp;
                    <?php
                    $encDate = '';
                    if (!empty($enc['date'])) {
                        $ts = strtotime($enc['date']);
                        if ($ts > 0) $encDate = date('Y-m-d', $ts);
                    }
                    echo text($encDate);
                    ?>
                    &nbsp;&mdash;&nbsp; Enc #<?php echo text($enc['encounter']); ?>
                </td>
            </tr>
            <?php foreach ($enc['charges'] as $charge): ?>
            <tr class="charge-row">
                <td></td>
                <td><?php echo text($enc['pid']); ?></td>
                <td><?php echo text($charge['enc_encounter']); ?></td>
                <!-- Service Date -->
                <td><?php
                    $svcDate = '';

                    if (!empty($charge['enc_date'])) {
                        $ts = strtotime($charge['enc_date']);
                        if ($ts > 0) {
                            $svcDate = date('Y-m-d', $ts);
                        }
                    }

                    echo text($svcDate);
                ?></td>

                <!-- Billing Date -->
                <td><?php
                    $billDate = '';

                    if (!empty($charge['bill_date'])) {
                        $ts = strtotime($charge['bill_date']);
                        if ($ts > 0) {
                            $billDate = date('Y-m-d', $ts);
                        }
                    }

                    echo text($billDate);
                ?></td>
                <td><?php echo text($charge['code_type'] ?? ''); ?></td>
                <td><?php echo text($charge['code'] ?? ''); ?></td>
                <td><?php echo text($charge['modifier'] ?? ''); ?></td>
                <td><?php echo text($charge['code_text'] ?? ''); ?></td>
                <td class="right"><?php echo text($charge['units'] ?? 1); ?></td>
                <td class="right"><?php echo text(oeFormatMoney($charge['fee'] ?? 0)); ?></td>
                <td><?php echo ($charge['billed'] ?? 0) ? xlt('Billed') : xlt('Unbilled'); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="enc-total">
                <td colspan="10" class="right"><em><?php echo xlt('Encounter Total'); ?>:</em></td>
                <td class="right"><strong><?php echo text(oeFormatMoney($enc['total'])); ?></strong></td>
                <td></td>
            </tr>
        <?php endforeach; ?>
            <tr class="grand-total-row">
                <td colspan="10" class="right"><?php echo xlt('GRAND TOTAL'); ?></td>
                <td class="right"><?php echo text(oeFormatMoney($grand_total)); ?></td>
                <td></td>
            </tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>

</body>
</html>
