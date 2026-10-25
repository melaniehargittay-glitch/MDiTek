<?php
/**
 * mdi_adj_lines.php
 *
 * AJAX endpoint: returns billing line items for a given pid+encounter as JSON.
 * Used by the Adjustments / Write-Offs tab in mdi_payments_ledger.php.
 *
 * Request: GET ?pid=N&enc=N&csrf=TOKEN
 * Response: { lines: [ { billing_id, code, description, charge, paid, adj } ] }
 *
 * Drop in:  interface/patient_file/mdi_adj_lines.php
 *
 * @package   OpenEMR / MDItek
 * @license   GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;

header('Content-Type: application/json');

// Access check
if (!AclMain::aclCheckCore('acct', 'bill', '', 'write')) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// CSRF (passed as GET param since this is a GET AJAX call)
if (!CsrfUtils::verifyCsrfToken($_GET['csrf'] ?? '')) {
    echo json_encode(['error' => 'CSRF']);
    exit;
}

$pid = intval($_GET['pid'] ?? 0);
$enc = intval($_GET['enc'] ?? 0);

if (!$pid || !$enc) {
    echo json_encode(['lines' => []]);
    exit;
}

// Fetch billing lines for this encounter
$billing_res = sqlStatement(
    "SELECT b.id, b.code, b.code_text, b.modifier, b.fee, b.units
     FROM billing b
     WHERE b.pid=? AND b.encounter=? AND b.activity>0
     ORDER BY b.id",
    [$pid, $enc]
);

$lines = [];
while ($b = sqlFetchArray($billing_res)) {
    $bid   = (int)$b['id'];
    $code  = trim($b['code']);
    $desc  = trim($b['code_text']);
    $mod   = trim($b['modifier'] ?? '');
    $charge = (float)$b['fee'];

    // Display code with modifier
    $code_display = $code . ($mod ? ':' . $mod : '');

    // Sum payments for this line
    $pmt_row = sqlQuery(
        "SELECT COALESCE(SUM(pay_amount),0) AS paid, COALESCE(SUM(adj_amount),0) AS adj
         FROM ar_activity
         WHERE deleted IS NULL AND pid=? AND encounter=? AND code=? AND modifier=?",
        [$pid, $enc, $code, $mod]
    );
    $paid = abs((float)($pmt_row['paid'] ?? 0));
    $adj  = abs((float)($pmt_row['adj']  ?? 0));

    $lines[] = [
        'billing_id'  => $bid,
        'code'        => $code_display,
        'description' => $desc,
        'charge'      => round($charge, 2),
        'paid'        => round($paid, 2),
        'adj'         => round($adj, 2),
    ];
}

echo json_encode(['lines' => $lines]);
exit;
