<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/**
 * Patient Billing – Statement Queue & Batch Statement Generator
 * @package OpenEMR
 */

// Show errors during development — remove in production
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once("../globals.php");
use OpenEMR\Core\Header;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Acl\AclMain;


if (!AclMain::aclCheckCore('acct', 'bill')) {
    die(xlt('Access Denied'));
}

require_once($GLOBALS['fileroot'] . '/vendor/autoload.php');

use Mpdf\Mpdf;

// ── Logged-in user ────────────────────────────────────────────────────────────
$_uid = isset($_SESSION['authUserID']) ? (int)$_SESSION['authUserID'] : 0;
$current_user = sqlQuery(
    "SELECT CONCAT(fname, ' ', lname) AS full_name FROM users WHERE id = ?",
    array($_uid)
);
$current_user_name = (!empty($current_user['full_name']))
    ? $current_user['full_name']
    : (isset($_SESSION['authUser']) ? $_SESSION['authUser'] : 'User');

// ── Facility ──────────────────────────────────────────────────────────────────
// Use globals $facility_id if set, otherwise first facility row
$_fac_id = isset($GLOBALS['facility_id']) ? (int)$GLOBALS['facility_id'] : 0;
if ($_fac_id > 0) {
    $facility = sqlQuery(
        "SELECT name, street, city, state, postal_code, phone FROM facility WHERE id = ?",
        array($_fac_id)
    );
} else {
    $facility = null;
}
if (!$facility) {
    $facility = sqlQuery(
        "SELECT name, street, city, state, postal_code, phone FROM facility ORDER BY id LIMIT 1"
    );
}
$facility_name   = ($facility && isset($facility['name']))        ? $facility['name']        : 'Your Practice';
$facility_street = ($facility && isset($facility['street']))      ? $facility['street']      : '';
$facility_phone  = ($facility && isset($facility['phone']))       ? $facility['phone']       : '';
$facility_city   = ($facility && isset($facility['city']))        ? $facility['city']        : '';
$facility_state  = ($facility && isset($facility['state']))       ? $facility['state']       : '';
$facility_zip    = ($facility && isset($facility['postal_code'])) ? $facility['postal_code'] : '';
$facility_csz    = trim($facility_city . ', ' . $facility_state . ' ' . $facility_zip);

// ── Routing & filters ─────────────────────────────────────────────────────────
$view        = (isset($_GET['view'])  && in_array($_GET['view'], array('queue','preview'))) ? $_GET['view'] : 'queue';
$preview_pid = isset($_GET['pid'])        ? (int)$_GET['pid']        : 0;

$f_stmt_date = isset($_GET['stmt_date'])  ? $_GET['stmt_date']        : date('m/d/Y');
$f_as_of     = isset($_GET['as_of'])      ? $_GET['as_of']            : date('m/d/Y');
$f_aging     = isset($_GET['aging'])      ? $_GET['aging']            : 'all';
$f_bal_from  = (isset($_GET['bal_from']) && $_GET['bal_from'] !== '') ? (float)$_GET['bal_from'] : null;
$f_bal_to    = (isset($_GET['bal_to'])   && $_GET['bal_to']   !== '') ? (float)$_GET['bal_to']   : null;
$f_provider  = isset($_GET['provider'])   ? (int)$_GET['provider']   : 0;
$f_facility  = isset($_GET['facility_id'])? (int)$_GET['facility_id']: 0;
$f_stmt_type = isset($_GET['stmt_type'])  ? $_GET['stmt_type']        : 'Itemized';
$f_inactive  = !empty($_GET['inactive']);
$f_pid_from  = isset($_GET['pid_from'])   ? trim($_GET['pid_from'])   : '';
$f_pid_to    = isset($_GET['pid_to'])     ? trim($_GET['pid_to'])     : '';
$f_rp_from   = isset($_GET['rp_from'])    ? trim($_GET['rp_from'])    : '';
$f_rp_to     = isset($_GET['rp_to'])      ? trim($_GET['rp_to'])      : '';
$page        = isset($_GET['pg'])         ? max(1,(int)$_GET['pg'])   : 1;
$per_page    = 25;

// ── As-of date → SQL ──────────────────────────────────────────────────────────
if (!empty($f_as_of)) {
    $dt = DateTime::createFromFormat('m/d/Y', $f_as_of);

    if ($dt) {
        $as_of_sql = $dt->format('Y-m-d');
    } else {
        $as_of_sql = date('Y-m-d');
    }
} else {
    $as_of_sql = date('Y-m-d');
}

// ── Aging buckets ─────────────────────────────────────────────────────────────
function get_patient_aging($pid, $as_of_sql)
{
    $pid = (int)$pid;

    $enc_res = sqlStatement(
        "SELECT b.encounter, SUM(b.fee) AS total_charge, MAX(e.date) AS enc_date
           FROM billing b
           JOIN form_encounter e ON e.encounter = b.encounter AND e.pid = b.pid
          WHERE b.pid = ? AND b.activity = 1 AND b.code_type != 'COPAY'
            AND e.date <= ?
          GROUP BY b.encounter",
        array($pid, $as_of_sql)
    );

    $pay_res = sqlStatement(
        "SELECT encounter,
                SUM(pay_amount) AS total_pay,
                SUM(adj_amount) AS total_adj
           FROM ar_activity
          WHERE pid = ? AND deleted IS NULL AND DATE(post_time) <= ?
          GROUP BY encounter",
        array($pid, $as_of_sql)
    );

    $pay_by_enc = array();
    while ($r = sqlFetchArray($pay_res)) {
        $pay_by_enc[$r['encounter']] = array(
            'pay' => (float)$r['total_pay'],
            'adj' => (float)$r['total_adj'],
        );
    }

    $now    = strtotime($as_of_sql);
    $totals = array('total'=>0.0,'current'=>0.0,'d30'=>0.0,'d60'=>0.0,'d90'=>0.0,'d120'=>0.0);

    while ($enc = sqlFetchArray($enc_res)) {
        $enid   = $enc['encounter'];
        $charge = (float)$enc['total_charge'];
        $pay    = isset($pay_by_enc[$enid]) ? abs((float)$pay_by_enc[$enid]['pay']) : 0.0;
        $adj    = isset($pay_by_enc[$enid]) ? abs((float)$pay_by_enc[$enid]['adj']) : 0.0;
        $bal    = $charge - $pay - $adj;
        if ($bal <= 0.005) continue;

        $enc_ts = strtotime($enc['enc_date']);
        $days   = ($enc_ts && $now >= $enc_ts) ? (int)(($now - $enc_ts) / 86400) : 0;

        $totals['total'] += $bal;
        if      ($days < 30)  $totals['current'] += $bal;
        elseif  ($days < 60)  $totals['d30']     += $bal;
        elseif  ($days < 90)  $totals['d60']     += $bal;
        elseif  ($days < 120) $totals['d90']     += $bal;
        else                  $totals['d120']    += $bal;
    }
    return $totals;
}

// ── Line items ────────────────────────────────────────────────────────────────
function get_patient_line_items($pid, $as_of_sql)
{
    $pid   = (int)$pid;
    $items = array();

    $bres = sqlStatement(
        "SELECT b.date, b.code_text AS description, b.fee AS charge, b.code_type, b.code
           FROM billing b
          WHERE b.pid = ? AND b.activity = 1 AND b.date <= ?
          ORDER BY b.date, b.id",
        array($pid, $as_of_sql)
    );
    while ($row = sqlFetchArray($bres)) {
        $desc = !empty($row['description']) ? $row['description'] : ($row['code_type'] . ' ' . $row['code']);
        $items[] = array(
            'sort'        => $row['date'],
            'date'        => date('m/d/Y', strtotime($row['date'])),
            'description' => $desc,
            'charge'      => (float)$row['charge'],
            'payment'     => 0.0,
            'adjustment'  => 0.0,
        );
    }

    $ares = sqlStatement(
        "SELECT DATE(post_time) AS date,
                COALESCE(memo, 'Payment') AS description,
                pay_amount,
                adj_amount,
                account_code
           FROM ar_activity
          WHERE pid = ?
            AND deleted IS NULL
            AND DATE(post_time) <= ?
          ORDER BY post_time, sequence_no",
        array($pid, $as_of_sql)
    );
    while ($row = sqlFetchArray($ares)) {
        $pay = (float)$row['pay_amount'];
        $adj = (float)$row['adj_amount'];
        if ($pay == 0 && $adj == 0) continue;
        $desc = ($row['account_code'] === 'PCP') ? 'Copay' : $row['description'];
        $items[] = array(
            'sort'        => $row['date'],
            'date'        => date('m/d/Y', strtotime($row['date'])),
            'description' => $desc,
            'charge'      => 0.0,
            'payment'     => $pay,
            'adjustment'  => $adj,
        );
    }

    usort($items, function($a, $b) { return strcmp($a['sort'], $b['sort']); });

    $running = 0.0;
    foreach ($items as &$item) {
        $running += $item['charge'] + $item['payment'] + $item['adjustment'];
        $item['balance'] = $running;
    }
    unset($item);

    return $items;
}

// ── Provider list ─────────────────────────────────────────────────────────────
$providers = array();
$prov_res  = sqlStatement(
    "SELECT id, CONCAT(fname, ' ', lname) AS name FROM users
      WHERE authorized = 1 ORDER BY lname, fname"
);
while ($r = sqlFetchArray($prov_res)) {
    $providers[] = $r;
}

// ── Facility list ─────────────────────────────────────────────────────────────
$facilities = array();
$fac_res    = sqlStatement("SELECT id, name FROM facility ORDER BY name");
while ($r = sqlFetchArray($fac_res)) {
    $facilities[] = $r;
}

// ── Responsible Party list (for autocomplete) ─────────────────────────────────
// Uses patient_data: lname, fname, mname, pid as the responsible party
$rp_res = sqlStatement(
    "SELECT pid,
            CONCAT(lname, ', ', fname,
                   IF(mname IS NOT NULL AND mname != '', CONCAT(' ', mname), '')) AS full_name
       FROM patient_data
      ORDER BY lname, fname
      LIMIT 2000"
);
$rp_list = array();
while ($r = sqlFetchArray($rp_res)) {
    $rp_list[] = array('pid' => (int)$r['pid'], 'name' => $r['full_name']);
}

// ── Patient query — only columns that exist in patient_data ──────────────────
// patient_data confirmed columns used: pid, lname, fname, mname,
//   street, city, state, postal_code, email, deceased_date
$where_parts  = array("b.activity = 1", "b.code_type != 'COPAY'", "e.date <= ?");
$query_params = array($as_of_sql);

if (!$f_inactive) {
    $where_parts[] = "pd.deceased_date IS NULL";

}
if ($f_pid_from !== '') {
    $where_parts[]  = "pd.pid >= ?";
    $query_params[] = (int)$f_pid_from;
}
if ($f_pid_to !== '') {
    $where_parts[]  = "pd.pid <= ?";
    $query_params[] = (int)$f_pid_to;
}
if ($f_rp_from !== '') {
    // rp_from is a pid — filter patients whose pid >= this responsible party pid
    $where_parts[]  = "pd.pid >= ?";
    $query_params[] = (int)$f_rp_from;
}
if ($f_rp_to !== '') {
    $where_parts[]  = "pd.pid <= ?";
    $query_params[] = (int)$f_rp_to;
}
if ($f_provider) {
    $where_parts[]  = "e.provider_id = ?";
    $query_params[] = $f_provider;
}
if ($f_facility) {
    $where_parts[]  = "e.facility_id = ?";
    $query_params[] = $f_facility;
}

$where_sql = implode(' AND ', $where_parts);

$pid_sql = "SELECT DISTINCT
    pd.pid,
    CONCAT(pd.lname, ', ', pd.fname,
        IF(pd.mname IS NOT NULL AND pd.mname != '', CONCAT(' ', pd.mname), '')) AS patient_name,
    pd.street, pd.city, pd.state, pd.postal_code, pd.email
FROM patient_data pd
JOIN form_encounter e ON e.pid = pd.pid
JOIN billing b        ON b.pid = pd.pid AND b.encounter = e.encounter
WHERE $where_sql
ORDER BY pd.lname, pd.fname";



$pid_res = sqlStatement($pid_sql, $query_params);


$all_patients     = array();
$grand_total      = 0.0;
$email_eligible_n = 0;

while ($row = sqlFetchArray($pid_res)) {
    $aging = get_patient_aging((int)$row['pid'], $as_of_sql);
    $bal   = $aging['total'];
    if ($bal <= 0.005) continue;

    if ($f_bal_from !== null && $bal < $f_bal_from) continue;
    if ($f_bal_to   !== null && $bal > $f_bal_to)   continue;

    if ($f_aging !== 'all') {
        $bucket_map = array('current'=>'current','30'=>'d30','60'=>'d60','90'=>'d90','120'=>'d120');
        if (isset($bucket_map[$f_aging]) && $aging[$bucket_map[$f_aging]] < 0.005) continue;
    }

    $has_email = !empty($row['email']);
    if ($has_email) $email_eligible_n++;
    $grand_total += $bal;

    $all_patients[] = array(
        'pid'       => (int)$row['pid'],
        'name'      => $row['patient_name'],
        'balance'   => $bal,
        'current'   => $aging['current'],
        'd30'       => $aging['d30'],
        'd60'       => $aging['d60'],
        'd90'       => $aging['d90'],
        'd120'      => $aging['d120'],
        'email'     => $row['email'],
        'has_email' => $has_email,
        'type'      => $f_stmt_type,
        'address'   => isset($row['street']) ? trim($row['street']) : '',
        'csz'       => trim($row['city'] . ', ' . $row['state'] . ' ' . $row['postal_code']),
    );
}

$total_rows   = count($all_patients);
$print_only_n = $total_rows - $email_eligible_n;
$total_pages  = max(1, (int)ceil($total_rows / $per_page));
$page         = min($page, $total_pages);
$offset       = ($page - 1) * $per_page;
$patients     = array_slice($all_patients, $offset, $per_page);

// ── Preview patient ───────────────────────────────────────────────────────────
$preview_patient  = null;
$preview_lines    = array();
$preview_due_date = date('m/d/Y', strtotime('+20 days'));

if ($view === 'preview' && $preview_pid > 0) {
    $pp = sqlQuery(
        "SELECT pid,
            CONCAT(lname, ', ', fname,
                IF(mname IS NOT NULL AND mname != '', CONCAT(' ', mname), '')) AS patient_name,
            street, city, state, postal_code, email
         FROM patient_data WHERE pid = ?",
        array($preview_pid)
    );
    if ($pp) {
        $pa = get_patient_aging($preview_pid, $as_of_sql);
        $preview_patient = array(
            'pid'     => $preview_pid,
            'name'    => $pp['patient_name'],
            'balance' => $pa['total'],
            'current' => $pa['current'],
            'd30'     => $pa['d30'],
            'd60'     => $pa['d60'],
            'd90'     => $pa['d90'],
            'd120'    => $pa['d120'],
            'email'   => isset($pp['email']) ? $pp['email'] : '',
            'address' => isset($pp['street']) ? $pp['street'] : '',
            'csz'     => trim(
                (isset($pp['city'])        ? $pp['city']        : '') . ', ' .
                (isset($pp['state'])       ? $pp['state']       : '') . ' ' .
                (isset($pp['postal_code']) ? $pp['postal_code'] : '')
            ),
        );
        $preview_lines = get_patient_line_items($preview_pid, $as_of_sql);
    }
}
if ($preview_patient === null && !empty($patients)) {
    $preview_patient = $patients[0];
    $preview_lines   = get_patient_line_items($patients[0]['pid'], $as_of_sql);
}

// ── Batch print ───────────────────────────────────────────────────────────────
ob_start();
if (isset($_GET['batch_print'])) {
    $raw_pids = isset($_GET['pids']) ? $_GET['pids'] : '';
    $pids     = array_filter(array_map('intval', explode(',', $raw_pids)));
    $s_date   = htmlspecialchars($f_stmt_date);
    $s_as_of  = htmlspecialchars($f_as_of);
    $s_due    = htmlspecialchars($preview_due_date);
    $s_msg    = htmlspecialchars(isset($_GET['msg']) ? $_GET['msg'] : 'Thank you for allowing us to serve your healthcare needs.');

    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Batch Patient Statements</title>';
    echo '<style>
        body{font-family:Arial,sans-serif;font-size:12px;color:#111}
        .page{page-break-after:always;padding:30px 36px;max-width:800px;margin:0 auto}
        .page:last-child{page-break-after:auto}
        .hdr{display:flex;justify-content:space-between;margin-bottom:22px}
        .cn{font-size:16px;font-weight:700}.st{font-size:20px;font-weight:800;color:#3d2fa0}
        .sm{text-align:right;font-size:12px;line-height:1.8}
        .br{display:flex;justify-content:space-between;margin-bottom:18px;align-items:flex-start}
        .bt{font-size:12px;line-height:1.8}
        .ab{border:2px solid #ccc;min-width:360px}
        .at{background:#1a2340;color:#fff;text-align:center;padding:5px;font-size:11px;font-weight:700;text-transform:uppercase}
        .ac{display:flex}.acol{flex:1;padding:7px 8px;text-align:center;border-right:1px solid #ccc}
        .acol:last-child{border-right:none}
        .al{font-size:9px;text-transform:uppercase;color:#666;margin-bottom:3px}
        .av{font-size:13px;font-weight:700}.av.hl{color:#22a86e}
        table{width:100%;border-collapse:collapse;margin-bottom:16px}
        thead tr{background:#1a2340;color:#fff}
        th{padding:7px 10px;font-size:11px;text-align:left}th.r{text-align:right}
        td{padding:7px 10px;font-size:11px;border-bottom:1px solid #eee}td.r{text-align:right}
        tr:nth-child(even) td{background:#f9f9f9}
        .fm{text-align:center;font-style:italic;color:#3d2fa0;font-weight:600;margin-top:14px;border-top:2px solid #ddd;padding-top:12px}
        @media print{.page{padding:0}}
    </style></head><body>';


    foreach ($pids as $ppid) {
        $ppid = (int)$ppid;
        $pp2  = sqlQuery(
            "SELECT pid,
                CONCAT(lname, ', ', fname,
                    IF(mname IS NOT NULL AND mname != '', CONCAT(' ', mname), '')) AS patient_name,
                street, city, state, postal_code
             FROM patient_data WHERE pid = ?",
            array($ppid)
        );
        if (!$pp2) continue;
        $pa2    = get_patient_aging($ppid, $as_of_sql);
        $lines2 = get_patient_line_items($ppid, $as_of_sql);
        $csz2   = trim($pp2['city'] . ', ' . $pp2['state'] . ' ' . $pp2['postal_code']);

        echo '<div class="page"><div class="hdr"><div>';
        echo '<div class="cn">' . htmlspecialchars($facility_name)   . '</div>';
        if ($facility_street) echo '<div>' . htmlspecialchars($facility_street) . '</div>';
        if ($facility_csz)    echo '<div>' . htmlspecialchars($facility_csz)    . '</div>';
        if ($facility_phone)  echo '<div>' . htmlspecialchars($facility_phone)  . '</div>';
        echo '</div><div style="text-align:right"><div class="st">PATIENT STATEMENT</div><div class="sm">';
        echo 'Statement Date: <strong>' . $s_date  . '</strong><br>';
        echo 'As Of Date: <strong>'     . $s_as_of . '</strong><br>';
        echo 'Account #: <strong>'      . $ppid    . '</strong><br>';
        echo 'Due Date: <strong>'       . $s_due   . '</strong>';
        echo '</div></div></div>';

        echo '<div class="br"><div class="bt"><strong>Bill To:</strong><br>';
        echo htmlspecialchars($pp2['patient_name']) . '<br>';
        echo htmlspecialchars($pp2['street'])       . '<br>';
        echo htmlspecialchars($csz2) . '</div>';
        echo '<div class="ab"><div class="at">Account Summary</div><div class="ac">';
        $cols = array(
            'Total Balance' => array($pa2['total'],   true),
           
           
        );
        foreach ($cols as $cl => $ci) {
            $cls = $ci[1] ? ' hl' : '';
            echo '<div class="acol"><div class="al">' . $cl . '</div>';
            echo '<div class="av' . $cls . '">$' . number_format($ci[0], 2) . '</div></div>';
        }
        echo '</div></div></div>';

        echo '
<div style="
    border:2px solid #1a2340;
    margin:20px 0;
    padding:18px;
    border-radius:6px;
">

    <div style="
        font-size:18px;
        font-weight:700;
        color:#1a2340;
        margin-bottom:12px;
    ">
        Payment Coupon
    </div>

    <table style="width:100%;margin-bottom:18px;">
        <tr>
            <td><strong>Account #:</strong> ' . $ppid . '</td>
            <td><strong>Statement Date:</strong> ' . $s_date . '</td>
        </tr>
        <tr>
            <td><strong>Patient:</strong> ' . htmlspecialchars($pp2['patient_name']) . '</td>
            <td><strong>Amount Due:</strong> $' . number_format($pa2['total'],2) . '</td>
        </tr>
    </table>

    <div style="
        background:#f7f8fb;
        border:1px solid #d8deea;
        padding:12px;
        margin-top:10px;
    ">
        <strong>If paying by credit card, complete below:</strong>
    </div>

    <table style="width:100%;margin-top:15px;">
        <tr>
            <td style="padding:8px 0;">Cardholder Name:</td>
            <td style="border-bottom:1px solid #333;">&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:8px 0;">Card Number:</td>
            <td style="border-bottom:1px solid #333;">&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:8px 0;">Expiration Date:</td>
            <td style="border-bottom:1px solid #333;">&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:8px 0;">CVV:</td>
            <td style="border-bottom:1px solid #333;">&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:8px 0;">Amount Enclosed:</td>
            <td style="border-bottom:1px solid #333;">&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:8px 0;">Signature:</td>
            <td style="border-bottom:1px solid #333;">&nbsp;</td>
        </tr>
    </table>

</div>';
        echo '<div class="fm">' . $s_msg . '</div></div>';
    }
    $html = ob_get_clean();

    $mpdf = new Mpdf([
        'format' => 'Letter',
        'margin_left'   => 15,
        'margin_right'  => 15,
        'margin_top'    => 15,
        'margin_bottom' => 15
    ]);
    
    $mpdf->WriteHTML($html);
    
    $mpdf->Output('patient_statements.pdf', 'I');

exit;
}

// ── URL builder ───────────────────────────────────────────────────────────────
function qurl($overrides = array())
{
    $base = array(
        'view'        => isset($_GET['view'])        ? $_GET['view']        : 'queue',
        'stmt_date'   => isset($_GET['stmt_date'])   ? $_GET['stmt_date']   : '',
        'as_of'       => isset($_GET['as_of'])       ? $_GET['as_of']       : '',
        'aging'       => isset($_GET['aging'])       ? $_GET['aging']       : 'all',
        'bal_from'    => isset($_GET['bal_from'])    ? $_GET['bal_from']    : '',
        'bal_to'      => isset($_GET['bal_to'])      ? $_GET['bal_to']      : '',
        'provider'    => isset($_GET['provider'])    ? $_GET['provider']    : '',
        'facility_id' => isset($_GET['facility_id']) ? $_GET['facility_id'] : '',
        'stmt_type'   => isset($_GET['stmt_type'])   ? $_GET['stmt_type']   : 'Itemized',
        'inactive'    => isset($_GET['inactive'])    ? $_GET['inactive']    : '',
        'pid_from'    => isset($_GET['pid_from'])    ? $_GET['pid_from']    : '',
        'pid_to'      => isset($_GET['pid_to'])      ? $_GET['pid_to']      : '',
        'rp_from'     => isset($_GET['rp_from'])     ? $_GET['rp_from']     : '',
        'rp_to'       => isset($_GET['rp_to'])       ? $_GET['rp_to']       : '',
        'pg'          => isset($_GET['pg'])          ? $_GET['pg']          : '1',
    );
    return '?' . http_build_query(array_merge($base, $overrides));
}

$csrf_token = CsrfUtils::collectCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Billing – <?= $view === 'preview' ? 'Statement Preview' : 'Statement Queue' ?></title>
<?php // Header::setupHeader(['common']); // uncomment for OpenEMR global CSS/JS ?>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --navy:      #1a2340;
    --navy-mid:  #243156;
    --purple:    #5b4fcf;
    --purple-lt: #7c6fe0;
    --green:     #22a86e;
    --orange:    #e8893a;
    --red:       #e05555;
    --blue-icon: #3b82f6;
    --text:      #1e2535;
    --text-mid:  #4b5575;
    --text-lt:   #8b93ac;
    --border:    #dde2ef;
    --bg:        #f4f6fb;
    --white:     #ffffff;
    --row-hover: #f0f3fc;
    --shadow-sm: 0 1px 3px rgba(26,35,64,.08);
    --shadow-md: 0 4px 16px rgba(26,35,64,.12);
    --radius:    8px;
    --font:      'Segoe UI', system-ui, sans-serif;
}
body { font-family: var(--font); background: var(--bg); color: var(--text); font-size: 13px; min-height: 100vh; }

/* topbar */
.topbar { background: var(--navy); color: #fff; display: flex; align-items: center; justify-content: space-between; padding: 0 20px; height: 46px; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
.topbar-left { display: flex; align-items: center; gap: 12px; }
.topbar-logo { font-size: 15px; font-weight: 700; }
.topbar-sep  { color: rgba(255,255,255,.35); font-size: 18px; }
.topbar-page { font-size: 13px; color: rgba(255,255,255,.75); }
.topbar-right{ display: flex; align-items: center; gap: 16px; }
.topbar-user { text-align: right; line-height: 1.3; }
.topbar-user strong { display: block; font-size: 13px; }
.topbar-user span   { font-size: 11px; color: rgba(255,255,255,.6); }

/* tabstrip */
.tabstrip { background: var(--navy-mid); display: flex; padding: 0 20px; gap: 2px; }
.tab { padding: 9px 18px; font-size: 12px; font-weight: 600; color: rgba(255,255,255,.6); cursor: pointer; border-bottom: 3px solid transparent; transition: all .15s; text-decoration: none; display: flex; align-items: center; gap: 6px; }
.tab:hover  { color: #fff; border-color: rgba(255,255,255,.3); }
.tab.active { color: #fff; border-color: var(--purple-lt); background: rgba(255,255,255,.06); }

.page { padding: 20px; max-width: 1400px; margin: 0 auto; }

/* filter bar */
.filter-bar { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 18px; margin-bottom: 16px; box-shadow: var(--shadow-sm); }
.filter-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end; }
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-group label { font-size: 11px; font-weight: 600; color: var(--text-mid); text-transform: uppercase; letter-spacing: .4px; }
.filter-group input,
.filter-group select { height: 34px; padding: 0 10px; border: 1px solid var(--border); border-radius: 5px; font-size: 12px; color: var(--text); background: var(--white); outline: none; transition: border-color .15s; }
.filter-group input:focus,
.filter-group select:focus { border-color: var(--purple); box-shadow: 0 0 0 3px rgba(91,79,207,.12); }
.filter-separator { width: 100%; height: 0; border-top: 1px dashed var(--border); margin: 4px 0; }
.filter-actions { display: flex; gap: 8px; margin-left: auto; align-self: flex-end; }

/* buttons */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 0 16px; height: 34px; border: none; border-radius: 5px; font-size: 12px; font-weight: 600; cursor: pointer; transition: all .15s; white-space: nowrap; text-decoration: none; }
.btn-primary { background: var(--purple); color: #fff; }
.btn-primary:hover { background: var(--purple-lt); }
.btn-outline { background: transparent; color: var(--purple); border: 1.5px solid var(--purple); }
.btn-outline:hover { background: rgba(91,79,207,.08); }
.btn-sm { height: 30px; padding: 0 12px; font-size: 11px; }
.btn-icon-only { width: 34px; padding: 0; justify-content: center; }
.btn-back  { background: var(--white); color: var(--navy); border: 1.5px solid var(--border); }
.btn-back:hover { border-color: var(--navy); }
.btn-batch { background: var(--navy); color: #fff; }
.btn-batch:hover { background: var(--navy-mid); }

/* stat cards */
.stat-cards { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
.stat-card { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; display: flex; align-items: center; gap: 12px; box-shadow: var(--shadow-sm); }
.stat-icon { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.stat-icon.purple { background: rgba(91,79,207,.12); }
.stat-icon.green  { background: rgba(34,168,110,.12); }
.stat-icon.orange { background: rgba(232,137,58,.12); }
.stat-icon.blue   { background: rgba(59,130,246,.12); }
.stat-icon.red    { background: rgba(224,85,85,.12); }
.stat-body label  { font-size: 11px; font-weight: 600; color: var(--text-lt); text-transform: uppercase; letter-spacing: .4px; display: block; margin-bottom: 2px; }
.stat-body .val   { font-size: 22px; font-weight: 700; line-height: 1.1; }
.val.green { color: var(--green); }
.val.blue  { color: var(--blue-icon); }
.val.red   { color: var(--red); }

/* table toolbar */
.table-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.sel-label { font-size: 12px; font-weight: 600; color: var(--text-mid); display: flex; align-items: center; gap: 6px; }
.sel-label a { color: var(--purple); text-decoration: none; margin-left: 4px; }
.toolbar-actions { display: flex; gap: 8px; }

/* data table */
.data-table-wrap { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-sm); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table thead tr { background: var(--navy); }
.data-table thead th { padding: 10px 12px; font-size: 11px; font-weight: 700; color: rgba(255,255,255,.85); text-align: left; white-space: nowrap; border-right: 1px solid rgba(255,255,255,.08); }
.data-table thead th:last-child { border-right: none; }
.data-table thead th input[type=checkbox] { accent-color: var(--purple-lt); width: 14px; height: 14px; }
.data-table tbody tr { border-bottom: 1px solid var(--border); transition: background .1s; cursor: pointer; }
.data-table tbody tr:hover { background: var(--row-hover); }
.data-table tbody tr:last-child { border-bottom: none; }
.data-table tbody td { padding: 9px 12px; font-size: 12px; color: var(--text); white-space: nowrap; }
.data-table tbody td input[type=checkbox] { accent-color: var(--purple); width: 14px; height: 14px; }
.money { font-variant-numeric: tabular-nums; text-align: right; }
.text-center { text-align: center; }
.badge-email { color: var(--green); font-size: 15px; }
.badge-noemail { color: var(--text-lt); font-size: 15px; }
.badge-type  { font-size: 11px; font-weight: 600; color: var(--text-mid); background: #f0f3fb; border-radius: 4px; padding: 2px 7px; }
.no-results  { padding: 32px; text-align: center; color: var(--text-lt); font-size: 13px; }

/* table footer / pagination */
.table-footer { display: flex; align-items: center; justify-content: space-between; padding: 10px 16px; background: var(--white); border-top: 1px solid var(--border); font-size: 12px; color: var(--text-mid); }
.pagination { display: flex; gap: 4px; align-items: center; }
.page-btn { min-width: 30px; height: 30px; padding: 0 8px; border: 1px solid var(--border); border-radius: 4px; background: var(--white); font-size: 12px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--text-mid); transition: all .12s; text-decoration: none; }
.page-btn:hover { border-color: var(--purple); color: var(--purple); }
.page-btn.active { background: var(--purple); color: #fff; border-color: var(--purple); font-weight: 700; }
.page-btn.disabled { opacity: .4; pointer-events: none; }

/* preview layout */
.preview-layout { display: grid; grid-template-columns: 280px 1fr; gap: 16px; align-items: start; }
.preview-sidebar { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px; box-shadow: var(--shadow-sm); position: sticky; top: 66px; }
.preview-sidebar h3 { font-size: 13px; font-weight: 700; color: var(--navy); margin-bottom: 14px; border-bottom: 2px solid var(--purple); padding-bottom: 8px; }
.form-row { margin-bottom: 12px; }
.form-row label { display: block; font-size: 11px; font-weight: 600; color: var(--text-mid); margin-bottom: 4px; text-transform: uppercase; letter-spacing: .3px; }
.form-row input,
.form-row select,
.form-row textarea { width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 5px; font-size: 12px; color: var(--text); background: var(--white); outline: none; transition: border-color .15s; font-family: var(--font); }
.form-row textarea { resize: vertical; min-height: 64px; }
.form-row input:focus, .form-row select:focus, .form-row textarea:focus { border-color: var(--purple); box-shadow: 0 0 0 3px rgba(91,79,207,.1); }
.delivery-opts { margin: 14px 0; }
.delivery-opts > label { font-size: 11px; font-weight: 700; color: var(--text-mid); display: block; margin-bottom: 8px; text-transform: uppercase; letter-spacing: .3px; }
.radio-group { display: flex; gap: 14px; }
.radio-item  { display: flex; align-items: center; gap: 6px; font-size: 12px; cursor: pointer; }
.radio-item input { accent-color: var(--purple); }
.sidebar-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 16px; border-top: 1px solid var(--border); padding-top: 16px; }

/* print action buttons */
.print-actions { display: flex; flex-direction: column; gap: 10px; position: sticky; top: 66px; align-self: start; }
.print-action-btn { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; display: flex; flex-direction: column; align-items: center; gap: 5px; cursor: pointer; transition: all .15s; text-align: center; box-shadow: var(--shadow-sm); }
.print-action-btn:hover { border-color: var(--purple); box-shadow: var(--shadow-md); }
.print-action-btn .icon { font-size: 24px; }
.print-action-btn .lbl  { font-size: 11px; font-weight: 600; color: var(--text-mid); }

/* statement document */
.statement-doc { background: var(--white); border: 1px solid var(--border); border-radius: var(--radius); padding: 32px 36px; box-shadow: var(--shadow-md); }
.stmt-header   { display: grid; grid-template-columns: 1fr auto; gap: 24px; margin-bottom: 24px; align-items: start; }
.stmt-clinic strong { font-size: 16px; font-weight: 700; color: var(--navy); display: block; }
.stmt-clinic span   { font-size: 12px; color: var(--text-mid); line-height: 1.7; display: block; }
.stmt-title-block   { text-align: right; }
.stmt-title { font-size: 22px; font-weight: 800; color: var(--purple); letter-spacing: -.5px; margin-bottom: 10px; }
.stmt-meta  { font-size: 12px; line-height: 1.9; color: var(--text-mid); }
.stmt-meta strong { color: var(--text); }
.stmt-bill-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; align-items: start; }
.stmt-bill-to  { font-size: 12px; line-height: 1.8; }
.stmt-bill-to strong { font-size: 13px; font-weight: 700; display: block; margin-bottom: 4px; }
.account-summary-box { border: 2px solid var(--border); border-radius: 6px; overflow: hidden; }
.account-summary-box .box-title { background: var(--navy); color: #fff; font-size: 11px; font-weight: 700; text-align: center; padding: 6px; text-transform: uppercase; letter-spacing: .5px; }
.account-summary-box .box-body  { display: grid; grid-template-columns: repeat(6, 1fr); background: var(--white); }
.acc-col { padding: 8px 10px; border-right: 1px solid var(--border); text-align: center; }
.acc-col:last-child { border-right: none; }
.acc-col .acc-label { font-size: 10px; color: var(--text-lt); text-transform: uppercase; font-weight: 600; margin-bottom: 4px; }
.acc-col .acc-val   { font-size: 14px; font-weight: 700; color: var(--navy); }
.acc-col .acc-val.highlight { color: var(--green); }
.line-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
.line-table thead tr { background: var(--navy); }
.line-table thead th { padding: 8px 12px; font-size: 11px; font-weight: 700; color: #fff; text-align: left; white-space: nowrap; }
.line-table thead th.money { text-align: right; }
.line-table tbody tr { border-bottom: 1px solid var(--border); }
.line-table tbody tr:nth-child(even) { background: #fafbfe; }
.line-table tbody td { padding: 8px 12px; font-size: 12px; }
.line-table tbody td.money { text-align: right; font-variant-numeric: tabular-nums; }
.line-empty { padding: 24px; text-align: center; color: var(--text-lt); font-size: 12px; }
.stmt-footer-msg { text-align: center; font-size: 13px; font-style: italic; color: var(--purple); font-weight: 600; margin-top: 16px; padding-top: 16px; border-top: 2px solid var(--border); }

/* modal */
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 500; display: none; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal-box { background: var(--white); border-radius: 10px; padding: 28px 32px; max-width: 460px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,.25); animation: modalIn .2s ease; }
@keyframes modalIn { from { transform: scale(.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.modal-box h3 { font-size: 16px; font-weight: 700; color: var(--navy); margin-bottom: 6px; }
.modal-box p  { font-size: 13px; color: var(--text-mid); margin-bottom: 20px; }
.batch-opts   { display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
.batch-opt    { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 1.5px solid var(--border); border-radius: 6px; cursor: pointer; transition: border-color .12s; }
.batch-opt:hover { border-color: var(--purple); }
.batch-opt input { accent-color: var(--purple); }
.batch-opt .opt-body strong { display: block; font-size: 13px; font-weight: 600; }
.batch-opt .opt-body span   { font-size: 12px; color: var(--text-mid); }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; }

/* print */
@media print {
    .topbar, .tabstrip, .preview-sidebar, .print-actions { display: none !important; }
    .preview-layout { grid-template-columns: 1fr !important; }
    .statement-doc  { box-shadow: none !important; border: none !important; padding: 0 !important; }
    body { background: #fff !important; }
}
/* ── Responsible Party autocomplete ───────────────────────────────────────── */
.rp-ac-list {
    position: absolute; top: 100%; left: 0; right: 0;
    background: var(--white); border: 1px solid var(--border);
    border-radius: 0 0 6px 6px; max-height: 220px; overflow-y: auto;
    z-index: 200; display: none; box-shadow: var(--shadow-md);
}
.rp-ac-list.open { display: block; }
.rp-ac-item {
    padding: 8px 12px; font-size: 12px; cursor: pointer;
    border-bottom: 1px solid var(--border); color: var(--text);
    display: flex; justify-content: space-between; align-items: center;
}
.rp-ac-item:last-child { border-bottom: none; }
.rp-ac-item:hover { background: var(--row-hover); }
.rp-ac-item .rp-pid { font-size: 11px; color: var(--text-lt); margin-left: 8px; }
</style>
</head>
<body>

<!-- topbar -->
<div class="topbar">
    <div class="topbar-left">
        <span style="font-size:18px;cursor:pointer;color:rgba(255,255,255,.7)">☰</span>
        <span class="topbar-logo">Patient Billing</span>
        <span class="topbar-sep">/</span>
        <span class="topbar-page"><?= $view === 'preview' ? 'Statement Preview' : 'Statement Queue' ?></span>
    </div>
    <div class="topbar-right">
        <span style="font-size:16px;color:rgba(255,255,255,.7);cursor:pointer">📅</span>
        <span style="font-size:16px;color:rgba(255,255,255,.7);cursor:pointer">❓</span>
        <div class="topbar-user">
            <strong><?= htmlspecialchars($current_user_name) ?></strong>
            <span>Billing Staff</span>
        </div>
        <span style="color:rgba(255,255,255,.7)">▾</span>
    </div>
</div>

<!-- tab strip -->
<div class="tabstrip">
    <a class="tab <?= $view==='queue'?'active':'' ?>" href="<?= qurl(['view'=>'queue','pg'=>1]) ?>">📋 Statement Queue</a>
    <?php if ($view === 'preview' && !empty($preview_patient['pid'])): ?>
    <a class="tab active" href="<?= qurl(['view'=>'preview','pid'=>$preview_patient['pid']]) ?>">👁 Statement Preview</a>
    <?php endif; ?>
</div>

<?php if ($view === 'queue'): ?>
<!-- ═══════════════════ QUEUE VIEW ═══════════════════ -->
<div class="page">

<!-- filter form -->
<form method="GET" action="" id="filter-form">
<input type="hidden" name="view" value="queue">
<input type="hidden" name="pg" value="1" id="pg-input">

<div class="filter-bar">
    <div class="filter-row">
        <!-- Patient range -->
        <div class="filter-group">
            <label>Patient ID From</label>
            <input type="text" name="pid_from" value="<?= htmlspecialchars($f_pid_from) ?>" style="width:100px;" placeholder="e.g. 1000">
        </div>
        <div class="filter-group">
            <label>Patient ID To</label>
            <input type="text" name="pid_to" value="<?= htmlspecialchars($f_pid_to) ?>" style="width:100px;" placeholder="e.g. 9999">
        </div>
        <!-- Responsible Party range with autocomplete -->
        <?php
        $rp_from_name = '';
        $rp_to_name   = '';
        if ($f_rp_from !== '') {
            foreach ($rp_list as $_r) { if ($_r['pid'] == (int)$f_rp_from) { $rp_from_name = $_r['name']; break; } }
        }
        if ($f_rp_to !== '') {
            foreach ($rp_list as $_r) { if ($_r['pid'] == (int)$f_rp_to) { $rp_to_name = $_r['name']; break; } }
        }
        $rp_from_disp = $f_rp_from !== '' ? ($rp_from_name . ' (#' . $f_rp_from . ')') : '';
        $rp_to_disp   = $f_rp_to   !== '' ? ($rp_to_name   . ' (#' . $f_rp_to   . ')') : '';
        ?>
        <div class="filter-group" style="position:relative;">
            <label>Responsible Party From</label>
            <input type="text" id="rp_from_display" autocomplete="off"
                   value="<?= htmlspecialchars($rp_from_disp) ?>"
                   placeholder="Type name or ID…" style="width:180px;"
                   oninput="rpAutocomplete(this,'rp_from','rp_from_list')">
            <input type="hidden" name="rp_from" id="rp_from" value="<?= htmlspecialchars($f_rp_from) ?>">
            <div id="rp_from_list" class="rp-ac-list"></div>
        </div>
        <div class="filter-group" style="position:relative;">
            <label>Responsible Party To</label>
            <input type="text" id="rp_to_display" autocomplete="off"
                   value="<?= htmlspecialchars($rp_to_disp) ?>"
                   placeholder="Type name or ID…" style="width:180px;"
                   oninput="rpAutocomplete(this,'rp_to','rp_to_list')">
            <input type="hidden" name="rp_to" id="rp_to" value="<?= htmlspecialchars($f_rp_to) ?>">
            <div id="rp_to_list" class="rp-ac-list"></div>
        </div>
        <div class="filter-group">
            <label>Statement Date</label>
            <input type="text" name="stmt_date" value="<?= htmlspecialchars($f_stmt_date) ?>" style="width:115px;">
        </div>
        <div class="filter-group">
            <label>As Of Date</label>
            <input type="text" name="as_of" value="<?= htmlspecialchars($f_as_of) ?>" style="width:115px;">
        </div>
    </div>
    <div class="filter-separator"></div>
    <div class="filter-row">
        <div class="filter-group">
            <label>Aging Bucket</label>
            <select name="aging" style="width:110px;">
                <?php foreach (['all'=>'All','current'=>'Current','30'=>'30 Days','60'=>'60 Days','90'=>'90 Days','120'=>'120+ Days'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $f_aging===$v?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Balance From ($)</label>
            <input type="text" name="bal_from" value="<?= htmlspecialchars($f_bal_from !== null ? $f_bal_from : '') ?>" style="width:90px;" placeholder="0.00">
        </div>
        <div class="filter-group">
            <label>Balance To ($)</label>
            <input type="text" name="bal_to" value="<?= htmlspecialchars($f_bal_to !== null ? $f_bal_to : '') ?>" style="width:100px;" placeholder="No max">
        </div>
        <div class="filter-group">
            <label>Provider</label>
            <select name="provider" style="width:160px;">
                <option value="0">All Providers</option>
                <?php foreach ($providers as $pr): ?>
                <option value="<?= (int)$pr['id'] ?>" <?= $f_provider===(int)$pr['id']?'selected':'' ?>><?= htmlspecialchars($pr['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Facility</label>
            <select name="facility_id" style="width:150px;">
                <option value="0">All Facilities</option>
                <?php foreach ($facilities as $fac): ?>
                <option value="<?= (int)$fac['id'] ?>" <?= $f_facility===(int)$fac['id']?'selected':'' ?>><?= htmlspecialchars($fac['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Statement Type</label>
            <select name="stmt_type" style="width:130px;">
            <?php foreach (array('Itemized','Detailed','Summary') as $st): ?>
                <option value="<?= $st ?>" <?= ($f_stmt_type === $st) ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group" style="flex-direction:row;align-items:center;gap:6px;padding-top:18px;">
            <input type="checkbox" id="inactive" name="inactive" value="1" style="accent-color:var(--purple);width:14px;height:14px;" <?= $f_inactive?'checked':'' ?>>
            <label for="inactive" style="font-size:12px;font-weight:500;color:var(--text-mid);text-transform:none;letter-spacing:0;white-space:nowrap;">Include Inactive Patients</label>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">🔍 Search</button>
            <button type="button" class="btn btn-outline" onclick="resetFilters()">↺ Reset</button>
        </div>
    </div>
</div>

<!-- stat cards -->
<div class="stat-cards">
    <div class="stat-card"><div class="stat-icon purple">👥</div><div class="stat-body"><label>Patients</label><div class="val"><?= $total_rows ?></div></div></div>
    <div class="stat-card"><div class="stat-icon green">💲</div><div class="stat-body"><label>Total Balance</label><div class="val green">$<?= number_format($grand_total,2) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon orange">📄</div><div class="stat-body"><label>Statements</label><div class="val"><?= count($patients) ?></div></div></div>
    <div class="stat-card"><div class="stat-icon blue">✉️</div><div class="stat-body"><label>Email Eligible</label><div class="val blue"><?= $email_eligible_n ?></div></div></div>
    <div class="stat-card"><div class="stat-icon red">🖨️</div><div class="stat-body"><label>Print Only</label><div class="val red"><?= $print_only_n ?></div></div></div>
</div>

</form>

<!-- toolbar -->
<div class="table-toolbar">
    <div class="sel-label">
        <input type="checkbox" id="select-all" checked style="accent-color:var(--purple);width:14px;height:14px;">
        <span id="sel-count"><?= count($patients) ?> selected</span>
        <a href="#" onclick="clearAll();return false;">Clear Selection</a>
    </div>
    <div class="toolbar-actions">
        <button class="btn btn-outline btn-sm" onclick="previewFirst()">👁 Preview Statement</button>
        <button class="btn btn-outline btn-sm" onclick="printSelected()">🖨 Print Selected</button>
        <button class="btn btn-primary btn-sm" onclick="openBatch()">✉ Email Selected</button>
    </div>
</div>

<!-- data table -->
<div class="data-table-wrap">
    <table class="data-table" id="queue-table">
        <thead>
            <tr>
                <th><input type="checkbox" id="header-cb" checked></th>
                <th>Patient Name</th>
                <th>Patient ID</th>
                <th>Statement Date</th>
                <th class="money">Total Balance</th>
                <th class="money">Current</th>
                <th class="money">30 Days</th>
                <th class="money">60 Days</th>
                <th class="money">90 Days</th>
                <th class="money">120+ Days</th>
                <th class="text-center">Email</th>
                <th>Type</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($patients)): ?>
            <tr><td colspan="12" class="no-results">No patients with outstanding balances match the selected filters.</td></tr>
        <?php else: ?>
            <?php foreach ($patients as $p): ?>
            <tr data-pid="<?= $p['pid'] ?>" onclick="rowClick(event, <?= $p['pid'] ?>)">
                <td><input type="checkbox" class="row-cb" checked></td>
                <td style="font-weight:600;color:var(--navy)"><?= htmlspecialchars($p['name']) ?></td>
                <td><?= $p['pid'] ?></td>
                <td><?= htmlspecialchars($f_stmt_date) ?></td>
                <td class="money" style="font-weight:700;">$<?= number_format($p['balance'],2) ?></td>
                <td class="money">$<?= number_format($p['current'],2) ?></td>
                <td class="money">$<?= number_format($p['d30'],2) ?></td>
                <td class="money">$<?= number_format($p['d60'],2) ?></td>
                <td class="money">$<?= number_format($p['d90'],2) ?></td>
                <td class="money">$<?= number_format($p['d120'],2) ?></td>
                <td class="text-center">
                    <?php if ($p['has_email']): ?>
                    <span class="badge-email" title="<?= htmlspecialchars($p['email']) ?>">✅</span>
                    <?php else: ?>
                    <span class="badge-noemail" title="No email on file">—</span>
                    <?php endif; ?>
                </td>
                <td><span class="badge-type"><?= htmlspecialchars($p['type']) ?></span></td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <div class="table-footer">
        <span>
            Showing <?= $offset+1 ?>–<?= min($offset+$per_page, $total_rows) ?> of <?= $total_rows ?> patients
        </span>
        <div class="pagination">
            <a class="page-btn <?= $page<=1?'disabled':'' ?>" href="<?= qurl(['pg'=>$page-1]) ?>">‹ Prev</a>
            <?php
            $pg_range = range(max(1,$page-2), min($total_pages,$page+2));
            if (!in_array(1,$pg_range)) { echo '<a class="page-btn" href="'.qurl(['pg'=>1]).'">1</a>'; if ($pg_range[0]>2) echo '<span class="page-btn" style="pointer-events:none">…</span>'; }
            foreach ($pg_range as $pg_n):
            ?>
            <a class="page-btn <?= $pg_n===$page?'active':'' ?>" href="<?= qurl(['pg'=>$pg_n]) ?>"><?= $pg_n ?></a>
            <?php endforeach; ?>
            <?php if (!in_array($total_pages,$pg_range) && $total_pages>1) { if (end($pg_range)<$total_pages-1) echo '<span class="page-btn" style="pointer-events:none">…</span>'; echo '<a class="page-btn" href="'.qurl(['pg'=>$total_pages]).'">'.$total_pages.'</a>'; } ?>
            <a class="page-btn <?= $page>=$total_pages?'disabled':'' ?>" href="<?= qurl(['pg'=>$page+1]) ?>">Next ›</a>
        </div>
    </div>
</div>

</div><!-- /page -->

<?php else: ?>
<!-- ═══════════════════ PREVIEW VIEW ═══════════════════ -->
<div class="page">
<?php if (!$preview_patient): ?>
    <div style="padding:40px;text-align:center;color:var(--text-lt);">No patient found. <a href="<?= qurl(['view'=>'queue']) ?>" style="color:var(--purple)">Back to Queue</a></div>
<?php else: ?>
<div class="preview-layout">

    <!-- sidebar -->
    <div class="preview-sidebar">
        <h3>📋 Statement Details</h3>
        <div class="form-row">
            <label>Statement Date</label>
            <input type="text" id="sv-stmt-date" value="<?= htmlspecialchars($f_stmt_date) ?>">
        </div>
        <div class="form-row">
            <label>As Of Date</label>
            <input type="text" id="sv-as-of" value="<?= htmlspecialchars($f_as_of) ?>">
        </div>
        <div class="form-row">
            <label>Statement Type</label>
            <select id="sv-type">
                <?php foreach (array('Itemized Statement','Detailed Statement','Summary Statement') as $st): ?>
                <?php $parts = explode(' ', $st); $pfx = $parts[0]; $sel = (strpos($f_stmt_type, $pfx) !== false) ? 'selected' : ''; ?>
                <option <?= $sel ?>><?= htmlspecialchars($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <label>Message</label>
            <textarea id="sv-message">Thank you for allowing us to serve your healthcare needs.</textarea>
        </div>
        <div class="form-row">
            <label>Due Date</label>
            <input type="text" id="sv-due-date" value="<?= htmlspecialchars($preview_due_date) ?>">
        </div>
        <div class="delivery-opts">
            <label>Delivery Method</label>
            <div class="radio-group">
                <label class="radio-item"><input type="radio" name="delivery" value="print" checked> 🖨 Print</label>
                <label class="radio-item"><input type="radio" name="delivery" value="email"> ✉ Email</label>
            </div>
        </div>
        <div class="sidebar-actions">
            <a href="<?= qurl(['view'=>'queue']) ?>" class="btn btn-back">← Back to Queue</a>
            <button class="btn btn-batch" onclick="openBatch()">📦 Add to Batch</button>
        </div>
    </div>

    <!-- statement doc + right rail -->
    <div style="display:grid;grid-template-columns:1fr 90px;gap:14px;align-items:start;">

        <div class="statement-doc" id="print-area">
            <!-- clinic header -->
            <div class="stmt-header">
                <div class="stmt-clinic">
                    <strong><?= htmlspecialchars($facility_name) ?></strong>
                    <span><?= htmlspecialchars($facility_street) ?></span>
                    <span><?= htmlspecialchars($facility_csz) ?></span>
                    <span><?= htmlspecialchars($facility_phone) ?></span>
                </div>
                <div class="stmt-title-block">
                    <div class="stmt-title">PATIENT STATEMENT</div>
                    <div class="stmt-meta">
                        Statement Date: <strong><?= htmlspecialchars($f_stmt_date) ?></strong><br>
                        As Of Date: <strong><?= htmlspecialchars($f_as_of) ?></strong><br>
                        Account #: <strong><?= $preview_patient['pid'] ?></strong><br>
                        Due Date: <strong id="stmt-due-disp"><?= htmlspecialchars($preview_due_date) ?></strong>
                    </div>
                </div>
            </div>

            <!-- bill to + summary -->
            <div class="stmt-bill-row">
                <div class="stmt-bill-to">
                    <strong>Bill To:</strong>
                    <?= htmlspecialchars($preview_patient['name']) ?><br>
                    <?= htmlspecialchars($preview_patient['address']) ?><br>
                    <?= htmlspecialchars($preview_patient['csz']) ?>
                </div>
                <div>
                    <div class="account-summary-box">
                        <div class="box-title">Account Summary</div>
                        <div class="box-body">
                        <?php
                        $acc_items = array(
                            'Total Balance' => array($preview_patient['balance'], true ),
                            'Current'       => array($preview_patient['current'], false),
                            '30 Days'       => array($preview_patient['d30'],     false),
                            '60 Days'       => array($preview_patient['d60'],     false),
                            '90 Days'       => array($preview_patient['d90'],     false),
                            '120+ Days'     => array($preview_patient['d120'],    false),
                        );
                        foreach ($acc_items as $lbl => $ai):
                            $amt = $ai[0]; $hl = $ai[1];
                        ?>
                            <div class="acc-col">
                                <div class="acc-label"><?= htmlspecialchars($lbl) ?></div>
                                <div class="acc-val <?= $hl ? 'highlight' : '' ?>">$<?= number_format($amt,2) ?></div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- line items -->
            <table class="line-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th class="money">Charges</th>
                        <th class="money">Payments</th>
                        <th class="money">Adjustments</th>
                        <th class="money">Balance</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($preview_lines)): ?>
                    <tr><td colspan="6" class="line-empty">No itemised activity found for this patient.</td></tr>
                <?php else: ?>
                    <?php foreach ($preview_lines as $li): ?>
                    <tr>
                        <td><?= htmlspecialchars($li['date']) ?></td>
                        <td><?= htmlspecialchars($li['description']) ?></td>
                        <td class="money">$<?= number_format($li['charge'],2) ?></td>
                        <td class="money">$<?= number_format($li['payment'],2) ?></td>
                        <td class="money">$<?= number_format($li['adjustment'],2) ?></td>
                        <td class="money" style="font-weight:700;">$<?= number_format($li['balance'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <div class="stmt-footer-msg" id="stmt-footer-msg">
                Thank you for allowing us to serve your healthcare needs.
            </div>
        </div>

        <!-- right rail -->
        <div class="print-actions">
            <button class="print-action-btn" onclick="window.print()">
                <span class="icon">🖨</span><span class="lbl">Print Preview</span>
            </button>
            <button class="print-action-btn" onclick="emailPreview()">
                <span class="icon">✉</span><span class="lbl">Email Preview</span>
            </button>
            <button class="print-action-btn" onclick="window.print()">
                <span class="icon">📄</span><span class="lbl">Download PDF</span>
            </button>
        </div>

    </div>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<!-- batch modal -->
<div class="modal-overlay" id="batchModal">
    <div class="modal-box">
        <h3>📦 Create Batch</h3>
        <p id="batch-desc">Process selected patient statements.</p>
        <div class="batch-opts">
            <label class="batch-opt">
                <input type="radio" name="batch_type" value="print" checked>
                <div class="opt-body"><strong>🖨 Print Batch</strong><span>Open a print window with one statement per patient</span></div>
            </label>
            <label class="batch-opt">
                <input type="radio" name="batch_type" value="email">
                <div class="opt-body"><strong>✉ Email Batch</strong><span>Queue emails for all email-eligible patients</span></div>
            </label>
            <label class="batch-opt">
                <input type="radio" name="batch_type" value="both">
                <div class="opt-body"><strong>🔀 Print + Email</strong><span>Email eligible; print the rest</span></div>
            </label>
        </div>
        <div class="form-row">
            <label>Statement Message</label>
            <textarea id="batch-msg" style="width:100%;min-height:50px;font-size:12px;padding:7px 10px;border:1px solid var(--border);border-radius:5px;font-family:var(--font);">Thank you for allowing us to serve your healthcare needs.</textarea>
        </div>
        <div class="modal-actions" style="margin-top:16px;">
            <button class="btn btn-outline" onclick="closeBatch()">Cancel</button>
            <button class="btn btn-primary" onclick="processBatch()">Process Batch</button>
        </div>
    </div>
</div>

<script>
// ── filter helpers ─────────────────────────────────────────────────────────
function resetFilters() {
    var f = document.getElementById('filter-form');
    f.querySelectorAll('input[type=text]').forEach(function(el){ el.value = ''; });
    f.querySelectorAll('input[type=hidden]').forEach(function(el){
        if (el.name !== 'view' && el.id !== 'pg-input') el.value = '';
    });
    f.querySelectorAll('select').forEach(function(el){ el.selectedIndex = 0; });
    f.querySelectorAll('input[type=checkbox]').forEach(function(el){ el.checked = false; });
    // Restore today's date defaults
    var today = new Date();
    var mm = String(today.getMonth()+1).padStart(2,'0');
    var dd = String(today.getDate()).padStart(2,'0');
    var yy = today.getFullYear();
    var td = mm+'/'+dd+'/'+yy;
    var sd = f.querySelector('[name=stmt_date]');
    var ao = f.querySelector('[name=as_of]');
    if (sd) sd.value = td;
    if (ao) ao.value = td;
    // Clear RP autocomplete display fields
    var rpFromDisp = document.getElementById('rp_from_display');
    var rpToDisp   = document.getElementById('rp_to_display');
    if (rpFromDisp) rpFromDisp.value = '';
    if (rpToDisp)   rpToDisp.value   = '';
    document.querySelectorAll('.rp-ac-list').forEach(function(l){ l.classList.remove('open'); });
    document.getElementById('pg-input').value = 1;
    f.submit();
}

// ── checkboxes ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var headerCb = document.getElementById('header-cb');
    var selAll   = document.getElementById('select-all');

    function syncCount() {
        var chkd = document.querySelectorAll('.row-cb:checked');
        var lbl  = document.getElementById('sel-count');
        if (lbl) lbl.textContent = chkd.length + ' selected';
    }
    function syncHeader() {
        var all  = document.querySelectorAll('.row-cb');
        var chkd = document.querySelectorAll('.row-cb:checked');
        var indeterminate = chkd.length > 0 && chkd.length < all.length;
        if (headerCb) { headerCb.indeterminate = indeterminate; headerCb.checked = chkd.length === all.length; }
        if (selAll)   { selAll.indeterminate   = indeterminate; selAll.checked   = chkd.length === all.length; }
        syncCount();
    }
    if (headerCb) {
        headerCb.addEventListener('change', function() {
            document.querySelectorAll('.row-cb').forEach(function(cb){ cb.checked = headerCb.checked; });
            if (selAll) selAll.checked = headerCb.checked;
            syncCount();
        });
    }
    if (selAll) {
        selAll.addEventListener('change', function() {
            document.querySelectorAll('.row-cb').forEach(function(cb){ cb.checked = selAll.checked; });
            if (headerCb) headerCb.checked = selAll.checked;
            syncCount();
        });
    }
    document.querySelectorAll('.row-cb').forEach(function(cb){
        cb.addEventListener('change', syncHeader);
    });
});

function clearAll() {
    document.querySelectorAll('.row-cb, #header-cb, #select-all').forEach(function(cb){ cb.checked = false; cb.indeterminate = false; });
    var lbl = document.getElementById('sel-count');
    if (lbl) lbl.textContent = '0 selected';
}

function getSelectedPids() {
    var pids = [];
    document.querySelectorAll('#queue-table tbody tr').forEach(function(tr) {
        var cb = tr.querySelector('.row-cb');
        if (cb && cb.checked) { pids.push(tr.dataset.pid); }
    });
    return pids;
}

// ── row click → preview ────────────────────────────────────────────────────
function rowClick(evt, pid) {
    if (evt.target.type === 'checkbox') return;
    window.location.href = '<?= qurl(['view'=>'preview']) ?>&pid=' + pid;
}

// ── preview first selected ──────────────────────────────────────────────────
function previewFirst() {
    var pids = getSelectedPids();
    if (!pids.length) { alert('Please select at least one patient.'); return; }
    window.location.href = '<?= qurl(['view'=>'preview']) ?>&pid=' + pids[0];
}

// ── print selected (batch print — one page per patient) ─────────────────────
function printSelected() {
    var pids = getSelectedPids();
    if (!pids.length) { alert('No patients selected.'); return; }
    var msg  = encodeURIComponent('Thank you for allowing us to serve your healthcare needs.');
    var url  = '<?= qurl(['batch_print'=>1,'view'=>'queue']) ?>&pids=' + pids.join(',') + '&msg=' + msg;
    window.open(url, '_blank');
}

// ── batch modal ─────────────────────────────────────────────────────────────
function openBatch() {
    var pids = getSelectedPids();
    var desc = document.getElementById('batch-desc');
    if (desc) desc.textContent = 'Process ' + pids.length + ' selected patient statement(s).';
    document.getElementById('batchModal').classList.add('open');
}
function closeBatch() { document.getElementById('batchModal').classList.remove('open'); }

function processBatch() {
    var type = document.querySelector('input[name="batch_type"]:checked').value;
    var msg  = document.getElementById('batch-msg').value.trim();
    closeBatch();
    var pids = getSelectedPids();
    if (!pids.length) { alert('No patients selected.'); return; }

    if (type === 'print' || type === 'both') {
        var url = '<?= qurl(['batch_print'=>1,'view'=>'queue']) ?>&pids=' + pids.join(',') + '&msg=' + encodeURIComponent(msg);
        window.open(url, '_blank');
    }
    if (type === 'email' || type === 'both') {
        alert('Email batch queued for ' + pids.length + ' patient(s).\n(Email delivery integration required.)');
    }
}

document.addEventListener('click', function(e) {
    // Close batch modal on overlay click
    if (e.target.id === 'batchModal') closeBatch();
    // Close RP autocomplete dropdowns on outside click
    var lists = document.querySelectorAll('.rp-ac-list');
    lists.forEach(function(l) { l.classList.remove('open'); });
});

// ── Responsible Party autocomplete ───────────────────────────────────────────
var RP_LIST = <?php echo json_encode($rp_list, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function rpAutocomplete(inputEl, hiddenId, listId) {
    var term     = inputEl.value.trim().toLowerCase();
    var listEl   = document.getElementById(listId);
    var hiddenEl = document.getElementById(hiddenId);
    hiddenEl.value = '';

    if (term.length < 1) { listEl.classList.remove('open'); return; }

    var matches = [];
    for (var i = 0; i < RP_LIST.length; i++) {
        var r = RP_LIST[i];
        if (r.name.toLowerCase().indexOf(term) !== -1 || String(r.pid).indexOf(term) !== -1) {
            matches.push(r);
            if (matches.length >= 50) break;
        }
    }

    listEl.innerHTML = '';
    if (!matches.length) { listEl.classList.remove('open'); return; }

    matches.forEach(function(r) {
        var div = document.createElement('div');
        div.className = 'rp-ac-item';
        var nm  = document.createElement('span');
        nm.textContent = r.name;
        var pid = document.createElement('span');
        pid.className = 'rp-pid';
        pid.textContent = '#' + r.pid;
        div.appendChild(nm);
        div.appendChild(pid);
        div.addEventListener('mousedown', function(e) {
            e.preventDefault();
            inputEl.value  = r.name + ' (#' + r.pid + ')';
            hiddenEl.value = r.pid;
            listEl.classList.remove('open');
        });
        listEl.appendChild(div);
    });
    listEl.classList.add('open');
}

// ── preview: live due-date sync ──────────────────────────────────────────────
var dueDateEl = document.getElementById('sv-due-date');
var stmtDueDisp = document.getElementById('stmt-due-disp');
if (dueDateEl && stmtDueDisp) {
    dueDateEl.addEventListener('input', function() { stmtDueDisp.textContent = this.value; });
}

// ── preview: live message sync ───────────────────────────────────────────────
var msgEl   = document.getElementById('sv-message');
var footMsg = document.getElementById('stmt-footer-msg');
if (msgEl && footMsg) {
    msgEl.addEventListener('input', function() { footMsg.textContent = this.value; });
}

// ── email preview ─────────────────────────────────────────────────────────────
function emailPreview() {
    var email = '<?= htmlspecialchars(isset($preview_patient['email']) ? $preview_patient['email'] : '') ?>';
    if (!email) { alert('No email address on file for this patient.'); return; }
    if (confirm('Send statement preview to ' + email + '?')) {
        alert('Email queued for ' + email + '.\n(SMTP integration required.)');
    }
}
</script>
</body>
</html>
