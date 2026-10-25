<?php
/**
 * MDItek Aging Claims Work Queue
 * Place at: /interface/billing/mdi_aging_claims.php
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Broad ACL: anyone who can view billing or reports can see this page
$acl_ok = AclMain::aclCheckCore('acct', 'rep')
       || AclMain::aclCheckCore('acct', 'bill')
       || AclMain::aclCheckCore('admin', 'super');
if (!$acl_ok) {
    die('<p style="padding:20px;font-family:sans-serif;color:red;">' . xlt('Access denied') . '</p>');
}

// ── Bucket count helper ──────────────────────────────────────────────────────
// Uses a subquery that groups by (pid, encounter) so each encounter counts once.
// The inner GROUP BY collapses multi-CPT encounters into one row before HAVING.
// Canonical aging bucket query — identical logic to main.php mdi_aging_bucket_data().
// min_days: AT LEAST this many days old (<=).  max_days: LESS THAN this many days old (>).
// e.g. (31,60)=billed 31-60 days ago; (74,null)=74+ days ago.
// Exact mirror of mdi_payments_ledger.php balance walk.
// Joins ar_session to detect insurance via payer_id, payment_type, payment_method.
// Handles WO, AJ, TRANS rows the same way the ledger Step 2 does.
function aging_bucket_count(int $min_days, ?int $max_days = null): int {
    $lower = 'AND b.bill_date <= DATE_SUB(CURDATE(), INTERVAL ' . $min_days . ' DAY)';
    $upper = $max_days !== null
        ? ' AND b.bill_date > DATE_SUB(CURDATE(), INTERVAL ' . $max_days . ' DAY)'
        : '';
    try {
        $r = sqlQuery(
            "SELECT COUNT(*) AS cnt FROM (
                SELECT b.pid, b.encounter,
                       SUM(b.fee)
                       - COALESCE(SUM(CASE
                           WHEN (aa.payer_type = 1
                                 OR COALESCE(s.payer_id, 0) > 0
                                 OR LOWER(COALESCE(s.payment_type,'')) LIKE '%insurance%'
                                 OR LOWER(COALESCE(s.payment_method,'')) LIKE '%insurance%')
                                AND UPPER(COALESCE(aa.account_code,'')) NOT IN ('WO','TRANS','PR')
                           THEN aa.pay_amount + ABS(aa.adj_amount)
                           ELSE 0 END), 0)
                       - COALESCE(SUM(CASE
                           WHEN UPPER(COALESCE(aa.account_code,'')) = 'WO'
                                OR UPPER(COALESCE(aa.memo,'')) LIKE '%CONTRACT%'
                                OR UPPER(COALESCE(aa.memo,'')) LIKE '%BAD_DEBT%'
                           THEN ABS(aa.adj_amount) + aa.pay_amount
                           ELSE 0 END), 0)
                       - COALESCE(SUM(CASE
                           WHEN UPPER(COALESCE(aa.account_code,'')) = 'AJ'
                                AND (aa.payer_type = 1 OR COALESCE(s.payer_id,0) > 0)
                           THEN ABS(aa.adj_amount)
                           ELSE 0 END), 0)
                       - COALESCE(SUM(CASE
                           WHEN UPPER(COALESCE(aa.account_code,'')) = 'TRANS'
                                AND aa.payer_type = 1
                           THEN ABS(aa.adj_amount) + aa.pay_amount
                           ELSE 0 END), 0)
                       + COALESCE(SUM(CASE
                           WHEN UPPER(COALESCE(aa.account_code,'')) = 'TRANS'
                                AND aa.payer_type = 0
                           THEN ABS(aa.adj_amount) + aa.pay_amount
                           ELSE 0 END), 0)
                       AS insurance_balance
                FROM billing b
                LEFT JOIN ar_activity aa
                       ON aa.pid = b.pid AND aa.encounter = b.encounter AND aa.deleted IS NULL
                LEFT JOIN ar_session s ON s.session_id = aa.session_id
                WHERE b.billed = 1 AND b.activity = 1
                  $lower $upper
                  AND (b.code_text IS NULL OR b.code_text NOT LIKE '%LEGACY%')
                GROUP BY b.pid
               HAVING insurance_balance > 0.01
             ) x",
            []
        );
        return (int)($r['cnt'] ?? 0);
    } catch (Throwable $e) {
        error_log('MDI aging_bucket_count: ' . $e->getMessage());
        return 0;
    }
}

// ── AJAX: load follow-up history ────────────────────────────────────────────
if (!empty($_GET['action']) && $_GET['action'] === 'get_followup') {
    header('Content-Type: application/json');
    $fp_pid = intval($_GET['pid'] ?? 0);
    $fp_enc = intval($_GET['enc'] ?? 0);
    if (!$fp_pid) { echo json_encode(['notes'=>[], 'next_date'=>'']); exit; }

    $notes = [];
    $nres = sqlStatement(
        "SELECT * FROM mdi_claim_followup
          WHERE pid=? AND (enc=? OR enc=0)
          ORDER BY followup_date DESC LIMIT 20",
        [$fp_pid, $fp_enc]
    );
    while ($nr = sqlFetchArray($nres)) { $notes[] = $nr; }

    $next = sqlQuery(
        "SELECT next_followup_date FROM mdi_claim_followup
          WHERE pid=? AND next_followup_date IS NOT NULL AND next_followup_date >= CURDATE()
          ORDER BY next_followup_date ASC LIMIT 1",
        [$fp_pid]
    );
    echo json_encode(['notes' => $notes, 'next_date' => $next['next_followup_date'] ?? '']);
    exit;
}

// ── AJAX: get open claims list for a patient (sidebar in popup) ──────────────
if (!empty($_GET['action']) && $_GET['action'] === 'get_claims') {
    header('Content-Type: application/json');
    $gc_pid = intval($_GET['pid'] ?? 0);
    $claims = [];
    try {
        $cres = sqlStatement(
            "SELECT
                fe.encounter,
                LEFT(fe.date,10) AS dos,
                COALESCE(SUM(b.fee),0) AS charges
             FROM form_encounter fe
             JOIN billing b
               ON b.pid = fe.pid
              AND b.encounter = fe.encounter
              AND b.billed = 1
              AND b.activity = 1
             WHERE fe.pid = ?
               AND b.bill_date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
               AND NOT EXISTS (
                    SELECT 1
                    FROM billing bl
                    WHERE bl.pid = b.pid
                      AND bl.encounter = b.encounter
                      AND bl.code_text LIKE '%LEGACY%'
               )
             GROUP BY fe.encounter, fe.date
             ORDER BY fe.date ASC",
            [$gc_pid]
        );
        while ($cr = sqlFetchArray($cres)) {
            $cr['dos_fmt'] = $cr['dos']
                ? date('m/d/Y', strtotime($cr['dos']))
                : '—';
        
            $claims[] = $cr;
        }
    } catch (Throwable $e) { error_log('MDI get_claims: ' . $e->getMessage()); }
    echo json_encode(['claims' => $claims]);
    exit;
}

// ── AJAX: save follow-up note ─────────────────────────────────────────────
if (!empty($_POST['action']) && $_POST['action'] === 'save_followup') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>'Invalid CSRF token']); exit;
    }
    $fp_pid      = intval($_POST['fp_pid'] ?? 0);
    $fp_enc      = intval($_POST['fp_enc'] ?? 0);
    $fp_note     = trim($_POST['fp_note'] ?? '');
    $fp_status   = trim($_POST['fp_status'] ?? 'Pending');
    $fp_next     = trim($_POST['fp_next'] ?? '') ?: null;
    $fp_date     = date('Y-m-d');

    if (!$fp_pid || !$fp_note) {
        echo json_encode(['success'=>false,'error'=>'Missing required fields']); exit;
    }

    // Create table if not exists
    sqlStatement("CREATE TABLE IF NOT EXISTS mdi_claim_followup (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        pid         INT NOT NULL,
        enc         INT NOT NULL DEFAULT 0,
        followup_date DATE NOT NULL,
        note        TEXT NOT NULL,
        status      VARCHAR(50) NOT NULL DEFAULT 'Pending',
        next_followup_date DATE DEFAULT NULL,
        user_id     INT NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_pid_enc (pid, enc),
        INDEX idx_next (next_followup_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $fp_ref  = trim($_POST['fp_ref'] ?? '');
    // Add ref_number column if not exists (safe migration)
    try {
        sqlStatement("ALTER TABLE mdi_claim_followup ADD COLUMN IF NOT EXISTS ref_number VARCHAR(50) DEFAULT NULL");
        sqlStatement("ALTER TABLE mdi_claim_followup ADD COLUMN IF NOT EXISTS followup_time TIME DEFAULT NULL");
    } catch (Throwable $e) {}
    sqlInsert("INSERT INTO mdi_claim_followup
        (pid, enc, followup_date, followup_time, note, status, next_followup_date, ref_number, user_id)
        VALUES (?,?,?,NOW(),?,?,?,?,?)",
        [$fp_pid, $fp_enc, $fp_date, $fp_note, $fp_status, $fp_next ?: null, $fp_ref ?: null, $_SESSION['authUserID'] ?? 0]
    );

    echo json_encode(['success'=>true]);
    exit;
}

// Wrap in try/catch so any DB errors show instead of 500
try {

// Same canonical query as main.php — non-overlapping buckets
$bucket_31_60  = aging_bucket_count(31,  60);
$bucket_61_90  = aging_bucket_count(61,  90);
$bucket_91_120 = aging_bucket_count(91, 120);
$bucket_120p   = aging_bucket_count(121);
$total_aging   = $bucket_31_60 + $bucket_61_90 + $bucket_91_120 + $bucket_120p;

// ── Filters ──────────────────────────────────────────────────────────────────
$f_search   = trim($_GET['search']   ?? '');
$f_carrier  = intval($_GET['carrier']  ?? 0);
$f_provider = intval($_GET['provider'] ?? 0);
$f_bucket   = in_array($_GET['bucket'] ?? '', ['31','61','91','121']) ? $_GET['bucket'] : '31';

$bucket_ranges = [
    '31'  => [31,  60],
    '61'  => [61,  90],
    '91'  => [91, 120],
    '121' => [121, null],
];
[$min_days, $max_days] = $bucket_ranges[$f_bucket];

// ── Build parameterised WHERE for the grid ───────────────────────────────────
$params = [];

// The INNER subquery (one row per encounter) carries the WHERE conditions on billing.
// Patient-name search is done on the OUTER query via HAVING so we can reference the alias.
$inner_where = "b.billed = 1 AND b.activity = 1
                AND b.bill_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
$params[] = $min_days;

if ($max_days) {
    $inner_where .= " AND b.bill_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
    $params[] = $max_days;
}
if ($f_carrier) {
    $inner_where .= " AND b.payer_id = ?";
    $params[] = $f_carrier;
}
if ($f_provider) {
    $inner_where .= " AND b.provider_id = ?";
    $params[] = $f_provider;
}

$outer_having = "insurance_balance > 0.01";
if ($f_search !== '') {
    $outer_having .= " AND (patient_name LIKE ? OR CAST(enc_pid AS CHAR) LIKE ?)";
    $like = '%' . addslashes($f_search) . '%';
    $params[] = $like;
    $params[] = $like;
}

$allowed_sort = [
    'patient_name',
    'oldest_dos',
    'carrier',
    'charges',
    'insurance_balance',
    'aging_days'
];
$sort = in_array($_GET['sort'] ?? '', $allowed_sort) ? $_GET['sort'] : 'aging_days';
$dir  = (($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

// ── Core SQL (one row per encounter — multi-CPT collapsed by inner GROUP BY) ─
// Outer query adds patient/carrier/provider names and sort capability.
$base_sql = "
SELECT
    b.pid AS b_pid,

    CONCAT(
        COALESCE(pd.lname,''),
        ', ',
        COALESCE(pd.fname,'')
    ) AS patient_name,

    COUNT(DISTINCT b.encounter) AS claim_count,

    MIN(fe.date) AS oldest_dos,
    MAX(fe.date) AS newest_dos,

    MIN(ic.name) AS carrier,

    MIN(
        CONCAT(
            COALESCE(u.fname,''),
            ' ',
            COALESCE(u.lname,'')
        )
    ) AS provider_name,

    DATEDIFF(
        CURDATE(),
        MIN(COALESCE(b.bill_date,b.date))
    ) AS aging_days

FROM billing b

LEFT JOIN patient_data pd
    ON pd.pid = b.pid

LEFT JOIN form_encounter fe
    ON fe.pid = b.pid
   AND fe.encounter = b.encounter

LEFT JOIN insurance_companies ic
    ON ic.id = b.payer_id

LEFT JOIN users u
    ON u.id = b.provider_id

WHERE
    $inner_where

    AND NOT EXISTS (
        SELECT 1
        FROM billing bl
        WHERE bl.pid = b.pid
          AND bl.encounter = b.encounter
          AND bl.code_text LIKE '%LEGACY%'
    )

GROUP BY
    b.pid

ORDER BY
    $sort $dir
";
// ── Export CSV — handle before any HTML output ────────────────────────────────
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="aging_claims_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        $er['patient_name'],
        $er['b_pid'],
        $er['claim_count'],
        $er['oldest_dos'],
        $er['carrier'],
        number_format((float)$er['charges'], 2),
        number_format((float)$er['insurance_balance'], 2),
        $er['aging_days'],
    ]);
    $exp_res = sqlStatement($base_sql, $params);
    while ($er = sqlFetchArray($exp_res)) {
        fputcsv($out, [
            $er['patient_name'], $er['b_pid'], $er['encounter'],
            $er['dos'], $er['carrier'],
            number_format((float)$er['charges'], 2),
            number_format((float)$er['insurance_balance'], 2),
            $er['aging_days'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Pagination ────────────────────────────────────────────────────────────────
$page     = max(1, intval($_GET['page'] ?? 1));
$per_page = 50;
$offset   = ($page - 1) * $per_page;

// Count = base_sql stripped of ORDER BY, wrapped in COUNT(*)
$count_base = preg_replace('/\s*HAVING\s+\$outer_having\s*ORDER BY\s+\S+\s+\S+\s*$/i', '', $base_sql);
$count_row = sqlQuery(
    "SELECT COUNT(*) AS cnt FROM ($count_base) _c",
    $params
);
$total_rows  = (int)($count_row['cnt'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

$rows = [];
$res  = sqlStatement($base_sql . " LIMIT $per_page OFFSET $offset", $params);
while ($row = sqlFetchArray($res)) {

    $pid = (int)$row['b_pid'];

    // insurance_balance is computed in SQL — no per-row override needed


    $rows[] = $row;   // <-- move this to the bottom
}
   

// ── Carrier / provider selects ────────────────────────────────────────────────
$carriers = [];
$cres = sqlStatement("SELECT DISTINCT ic.id, ic.name FROM insurance_companies ic
    JOIN billing b ON b.payer_id=ic.id WHERE b.billed=1 AND b.activity=1 ORDER BY ic.name LIMIT 300");
while ($cr = sqlFetchArray($cres)) { $carriers[] = $cr; }

$providers = [];
$pres = sqlStatement("SELECT id, CONCAT(fname,' ',lname) AS name FROM users
    WHERE active=1 AND authorized=1 ORDER BY lname,fname LIMIT 100");
while ($pr = sqlFetchArray($pres)) { $providers[] = $pr; }

// ── Aging badge ───────────────────────────────────────────────────────────────
function aging_badge(int $days): string {
    if ($days > 120)     { $cls='age-red';    $lbl='120+ days'; }
    elseif ($days > 90)  { $cls='age-orange'; $lbl='91-120 days'; }
    elseif ($days > 60)  { $cls='age-yellow'; $lbl='61-90 days'; }
    else                 { $cls='age-blue';   $lbl='31-60 days'; }
    return '<span class="age-badge '.$cls.'" title="'.attr($lbl).'">'.$days.'</span>';
}

// ── Sort link helper ──────────────────────────────────────────────────────────
function sort_link(string $col, string $label, string $cur_sort, string $cur_dir, array $get): string {
    $new_dir = ($cur_sort === $col && $cur_dir === 'DESC') ? 'asc' : 'desc';
    $url = '?' . http_build_query(array_merge($get, ['sort'=>$col,'dir'=>$new_dir,'page'=>1]));
    $arrow = ($cur_sort === $col)
        ? '<span class="sort-arrow active">'.($cur_dir==='ASC'?'▲':'▼').'</span>'
        : '<span class="sort-arrow">⇅</span>';
    return '<a href="'.attr($url).'">'.text($label).$arrow.'</a>';
}
$sg = $_GET;

} catch (\Throwable $e) {
    echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:30px;">';
    echo '<h2 style="color:#dc2626;">Aging Claims — Error</h2>';
    echo '<pre style="background:#f3f4f6;padding:16px;border-radius:8px;overflow:auto;">' . text($e->getMessage()) . '</pre>';
    echo '<p><a href="javascript:history.back()">&#8592; Back</a></p>';
    echo '</body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo xlt('Aging Claims'); ?></title>
<?php
// Load OpenEMR header if available, otherwise skip gracefully
try { Header::setupHeader(['no_main-theme']); } catch (\Throwable $e) { /* standalone mode */ }
?>
<style>
:root{
    --purple:#6c3fc5;--purple-l:#f0ebff;
    --text:#1a1a2e;--text2:#6b7280;
    --border:#e5e7eb;--surface:#f9fafb;
    --red:#dc2626;--red-l:#fee2e2;
    --orange:#ea580c;--orange-l:#ffedd5;
    --yellow:#b45309;--yellow-l:#fef3c7;
    --blue:#1d4ed8;--blue-l:#dbeafe;
    --green:#15803d;--green-l:#dcfce7;
    --r:10px;--r-sm:6px;--shadow:0 2px 12px rgba(0,0,0,.08);
}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f3f4f6;color:var(--text);font-size:13px;}

.page-header{background:var(--purple);color:#fff;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;}
.page-header h1{font-size:17px;font-weight:700;display:flex;align-items:center;gap:9px;}
.header-meta{font-size:11px;opacity:.8;margin-top:2px;}
.back-btn{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;padding:6px 14px;border-radius:var(--r-sm);cursor:pointer;font-size:12px;font-weight:600;text-decoration:none;display:flex;align-items:center;gap:6px;}
.back-btn:hover{background:rgba(255,255,255,.25);color:#fff;}

.summary-strip{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:14px 22px 0;}
.sum-card{background:#fff;border:1px solid var(--border);border-radius:var(--r);padding:14px;cursor:pointer;transition:box-shadow .15s;text-align:center;border-top:4px solid transparent;}
.sum-card.blue  {border-top-color:var(--blue);}
.sum-card.yellow{border-top-color:var(--yellow);}
.sum-card.orange{border-top-color:var(--orange);}
.sum-card.red   {border-top-color:var(--red);}
.sum-card.active{box-shadow:var(--shadow);}
.sum-card:hover {box-shadow:var(--shadow);}
.sum-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:5px;}
.sum-count.blue  {font-size:30px;font-weight:800;color:var(--blue);}
.sum-count.yellow{font-size:30px;font-weight:800;color:var(--yellow);}
.sum-count.orange{font-size:30px;font-weight:800;color:var(--orange);}
.sum-count.red   {font-size:30px;font-weight:800;color:var(--red);}
.sum-sub{font-size:11px;color:var(--text2);margin-top:3px;}

.toolbar{display:flex;align-items:center;gap:10px;padding:12px 22px;background:#fff;border-bottom:1px solid var(--border);margin-top:12px;flex-wrap:wrap;}
.toolbar input[type=text],.toolbar select{border:1px solid var(--border);border-radius:var(--r-sm);padding:7px 10px;font-size:12px;color:var(--text);font-family:inherit;background:#fff;outline:none;}
.toolbar input[type=text]:focus,.toolbar select:focus{border-color:var(--purple);}
.toolbar input[type=text]{min-width:200px;}
.toolbar select{cursor:pointer;}
.spacer{flex:1;}
.export-btn{background:var(--purple);color:#fff;border:none;padding:7px 14px;border-radius:var(--r-sm);font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;text-decoration:none;font-family:inherit;}
.export-btn:hover{background:#5a32a8;color:#fff;}
.total-badge{font-size:12px;color:var(--text2);white-space:nowrap;}

.grid-wrap{padding:0 22px 22px;overflow-x:auto;}
table.claims-grid{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--border);border-radius:var(--r);overflow:hidden;margin-top:12px;font-size:12px;}
.claims-grid th{padding:9px 11px;text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text2);background:var(--surface);border-bottom:1px solid var(--border);white-space:nowrap;}
.claims-grid th a{color:inherit;text-decoration:none;display:flex;align-items:center;gap:3px;}
.claims-grid th a:hover{color:var(--purple);}
.sort-arrow{opacity:.4;font-size:9px;}
.sort-arrow.active{opacity:1;color:var(--purple);}
.claims-grid td{padding:9px 11px;border-bottom:1px solid var(--border);vertical-align:middle;}
.claims-grid tbody tr:last-child td{border-bottom:none;}
.claims-grid tbody tr:hover{background:#faf9ff;}
.patient-name{font-weight:600;}
.pid-enc{font-size:10px;color:var(--text2);margin-top:1px;}
.mono{font-family:'DM Mono','Courier New',monospace;}
.ins-bal{font-weight:700;color:var(--red);}
.age-badge{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;min-width:48px;text-align:center;}
.age-blue  {background:var(--blue-l);  color:var(--blue);}
.age-yellow{background:var(--yellow-l);color:var(--yellow);}
.age-orange{background:var(--orange-l);color:var(--orange);}
.age-red   {background:var(--red-l);   color:var(--red);}
.action-group{display:flex;gap:4px;}
.act-btn{padding:4px 8px;border:1px solid var(--border);border-radius:4px;background:#fff;color:var(--text2);font-size:10px;font-weight:700;cursor:pointer;font-family:inherit;transition:all .12s;text-transform:uppercase;}
.act-btn:hover{border-color:var(--purple);color:var(--purple);background:var(--purple-l);}
.ledger-btn:hover { border-color:#7c3aed !important; color:#7c3aed !important; background:#f5f0ff !important; }
.followup-btn { border-color: #d97706; color: #d97706; }
.followup-btn:hover { border-color:#b45309 !important; color:#b45309 !important; background:#fef3c7 !important; }
.followup-btn.has-notes { border-color:#d97706; color:#fff; background:#d97706; }
.followup-btn.has-notes:hover { background:#b45309 !important; border-color:#b45309 !important; }
.empty-state{text-align:center;padding:48px 22px;color:var(--text2);}
.empty-state h3{font-size:15px;font-weight:600;margin-bottom:6px;}
.pagination{display:flex;align-items:center;justify-content:space-between;padding:10px 22px;font-size:12px;color:var(--text2);}
.pg-btns{display:flex;gap:4px;}
.pg-btn{padding:5px 10px;border:1px solid var(--border);border-radius:4px;background:#fff;font-size:12px;cursor:pointer;color:var(--text);font-family:inherit;}
.pg-btn:hover{border-color:var(--purple);color:var(--purple);}
.pg-btn.current{background:var(--purple);color:#fff;border-color:var(--purple);}
</style>
</head>
<body>

<div class="page-header">
    <div>
        <h1>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>
            </svg>
            <?php echo xlt('Aging Claims Work Queue'); ?>
        </h1>
        <div class="header-meta">
            <?php echo xlt('Insurance balances outstanding > 30 days'); ?> &nbsp;·&nbsp;
            <?php echo (int)$total_aging; ?> <?php echo xlt('total encounters'); ?>
        </div>
    </div>
    <a href="javascript:window.close()" class="back-btn">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        <?php echo xlt('Back'); ?>
    </a>
</div>

<!-- Summary Cards -->
<div class="summary-strip">
<?php
$buckets = [
    ['31',  '31–60 Days',  $bucket_31_60,  'blue'],
    ['61',  '61–90 Days',  $bucket_61_90,  'yellow'],
    ['91',  '91–120 Days', $bucket_91_120, 'orange'],
    ['121', '120+ Days',   $bucket_120p,   'red'],
];
foreach ($buckets as [$bk, $bl, $bc, $bcolor]):
    $active = ($f_bucket === $bk) ? 'active' : '';
    $burl   = '?' . http_build_query(array_merge($_GET, ['bucket'=>$bk,'page'=>1]));
?>
<div class="sum-card <?php echo $bcolor; ?> <?php echo $active; ?>" onclick="window.location='<?php echo attr($burl); ?>'">
    <div class="sum-label"><?php echo text($bl); ?></div>
    <div class="sum-count <?php echo $bcolor; ?>"><?php echo (int)$bc; ?></div>
    <div class="sum-sub"><?php echo xlt('encounters'); ?></div>
</div>
<?php endforeach; ?>
</div>

<!-- Toolbar -->
<form method="GET" action="mdi_aging_claims.php" id="ff">
<input type="hidden" name="bucket" value="<?php echo attr($f_bucket); ?>">
<input type="hidden" name="sort"   value="<?php echo attr($sort); ?>">
<input type="hidden" name="dir"    value="<?php echo attr($_GET['dir'] ?? 'desc'); ?>">
<div class="toolbar">
    <input type="text" name="search" value="<?php echo attr($f_search); ?>"
           placeholder="<?php echo xla('Search patient…'); ?>"
           onchange="document.getElementById('ff').submit()">

    <select name="carrier" onchange="document.getElementById('ff').submit()">
        <option value="0"><?php echo xlt('All Carriers'); ?></option>
        <?php foreach ($carriers as $c): ?>
        <option value="<?php echo attr($c['id']); ?>" <?php echo $f_carrier==$c['id']?'selected':''; ?>>
            <?php echo text($c['name']); ?>
        </option>
        <?php endforeach; ?>
    </select>

    <select name="provider" onchange="document.getElementById('ff').submit()">
        <option value="0"><?php echo xlt('All Providers'); ?></option>
        <?php foreach ($providers as $p): ?>
        <option value="<?php echo attr($p['id']); ?>" <?php echo $f_provider==$p['id']?'selected':''; ?>>
            <?php echo text($p['name']); ?>
        </option>
        <?php endforeach; ?>
    </select>

    <div class="spacer"></div>
    <span class="total-badge"><?php echo (int)$total_rows; ?> <?php echo xlt('results'); ?></span>
    <a class="export-btn" href="<?php echo attr('mdi_aging_claims.php?' . http_build_query(array_merge($_GET, ['export'=>'csv']))); ?>">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
        </svg>
        <?php echo xlt('Export'); ?>
    </a>
</div>
</form>

<!-- Grid -->
<div class="grid-wrap">
<?php if (empty($rows)): ?>
<div class="empty-state">
    <h3><?php echo xlt('No aging claims found'); ?></h3>
    <p><?php echo xlt('Try adjusting your filters.'); ?></p>
</div>
<?php else: ?>
<table class="claims-grid">
    <thead><tr>
        <th><?php echo sort_link('patient_name',      'Patient',      $sort, strtoupper($_GET['dir']??'desc'), $sg); ?></th>
        <th><?php echo sort_link('oldest_dos',        'Oldest DOS',   $sort, strtoupper($_GET['dir']??'desc'), $sg); ?></th>
        <th><?php echo sort_link('carrier',           'Carrier',      $sort, strtoupper($_GET['dir']??'desc'), $sg); ?></th>
        <th><?php echo xlt('Status'); ?></th>
        <th><?php echo sort_link('aging_days',        'Days',         $sort, strtoupper($_GET['dir']??'desc'), $sg); ?></th>
        <th><?php echo xlt('Provider'); ?></th>
        <th><?php echo xlt('Actions'); ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $pid     = (int)$r['b_pid'];$claim_count = (int)$r['claim_count'];
        $enc     = (int)$r['encounter'];
        $days    = (int)$r['aging_days'];
        $dos_fmt = $r['oldest_dos']
        ? date('m/d/y', strtotime($r['oldest_dos']))
        : '—';
        $wroot   = $GLOBALS['webroot'];
    ?>
    <tr>
        <td>
            <div class="patient-name"><a href="<?php echo attr($wroot); ?>/interface/patient_file/summary/demographics.php?set_pid=<?php echo (int)$pid; ?>" style="color:inherit;text-decoration:none;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'"><?php echo text($r['patient_name']); ?></a></div>
            <div class="pid-enc">PID <?php echo (int)$pid; ?></div>
        </td>
        <td class="mono"><?php echo text($dos_fmt); ?></td>
        <td><?php echo text($r['carrier'] ?: '—'); ?></td>
        <td><?php
            $bstat = $r['billed_status'] ?? 'Pending';
            $bstat_colors = [
                'Partial'    => ['#dcfce7','#15803d'],
                'Pending'    => ['#fef3c7','#b45309'],
                'Not Posted' => ['#f3f4f6','#6b7280'],
            ];
            [$bsc_bg, $bsc_txt] = $bstat_colors[$bstat] ?? ['#f3f4f6','#6b7280'];
            echo '<span style="display:inline-block;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:700;'
               . 'background:'.attr($bsc_bg).';color:'.attr($bsc_txt).'">'
               . text($bstat) . '</span>';
        ?></td>
        <td><?php echo aging_badge($days); ?></td>
        <td style="color:var(--text2);font-size:11px;"><?php echo text($r['provider_name'] ?: '—'); ?></td>
        <td>
            <div class="action-group">
                <button class="act-btn ledger-btn" title="<?php echo xla('Payment Ledger'); ?>"
                        onclick="openLedgerPopup(<?php echo (int)$pid; ?>)">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                    <?php echo xlt('Ledger'); ?>
                </button>
                <button class="act-btn followup-btn"
                        data-pid="<?php echo (int)$pid; ?>"
                        data-claims="<?php echo (int)$claim_count; ?>"
                        data-days="<?php echo (int)$days; ?>"
                        data-dos="<?php echo attr($dos_fmt); ?>"
                        data-status="<?php echo attr($r['billed_status'] ?? 'Pending'); ?>"
                        data-patient="<?php echo attr($r['patient_name']); ?>"
                        data-carrier="<?php echo attr($r['carrier'] ?: ''); ?>"
                        title="<?php echo xla('Follow-Up History'); ?>"
                        onclick="openFollowup(this)">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    <?php echo xlt('Follow Up'); ?>
                </button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($total_pages > 1): ?>
<div class="pagination">
    <div>
        <?php echo xlt('Showing'); ?>
        <?php echo (($page-1)*$per_page+1); ?>–<?php echo min($page*$per_page,$total_rows); ?>
        <?php echo xlt('of'); ?> <?php echo (int)$total_rows; ?>
    </div>
    <div class="pg-btns">
        <?php if ($page > 1): ?>
        <button class="pg-btn" onclick="window.location='<?php echo attr('?'.http_build_query(array_merge($_GET,['page'=>$page-1]))); ?>'">‹</button>
        <?php endif; ?>
        <?php for ($pg=max(1,$page-2); $pg<=min($total_pages,$page+2); $pg++): ?>
        <button class="pg-btn <?php echo $pg===$page?'current':''; ?>"
                onclick="window.location='<?php echo attr('?'.http_build_query(array_merge($_GET,['page'=>$pg]))); ?>'">
            <?php echo (int)$pg; ?>
        </button>
        <?php endfor; ?>
        <?php if ($page < $total_pages): ?>
        <button class="pg-btn" onclick="window.location='<?php echo attr('?'.http_build_query(array_merge($_GET,['page'=>$page+1]))); ?>'">›</button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
</div>

<!-- ── Follow-Up Popup Modal ── -->
<style>
#fu-modal { display:none;position:fixed;inset:0;z-index:1000;align-items:center;justify-content:center;padding:20px; }
#fu-modal.open { display:flex; }
.fu-backdrop { position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:-1; }
.fu-card { background:#fff;border-radius:16px;box-shadow:0 24px 80px rgba(0,0,0,.22);
           width:900px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden; }
.fu-header { background:#2d1b6b;color:#fff;padding:18px 24px;flex-shrink:0; }
.fu-header-top { display:flex;align-items:flex-start;justify-content:space-between; }
.fu-title { font-size:20px;font-weight:700;letter-spacing:-.01em; }
.fu-sub { font-size:12px;opacity:.7;margin-top:3px; }
.fu-days-badge { background:#4c2faf;color:#fff;border-radius:999px;padding:6px 16px;font-size:13px;font-weight:700;white-space:nowrap; }
.fu-meta-bar { display:flex;gap:0;border:1px solid #e5e7eb;border-radius:10px;margin:14px 20px 0;overflow:hidden;flex-shrink:0;background:#fff; }
.fu-meta-item { flex:1;padding:12px 16px;border-right:1px solid #e5e7eb;display:flex;align-items:center;gap:10px; }
.fu-meta-item:last-child { border-right:none; }
.fu-meta-icon { width:34px;height:34px;border-radius:8px;background:#ede9fe;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.fu-meta-label { font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9ca3af;margin-bottom:2px; }
.fu-meta-value { font-size:13px;font-weight:700;color:#111827; }
.fu-body { display:flex;flex:1;min-height:0;overflow:hidden; }
.fu-left { flex:1;display:flex;flex-direction:column;border-right:1px solid #f3f4f6;min-height:0; }
.fu-history-head { padding:14px 20px 10px;display:flex;align-items:center;gap:10px;flex-shrink:0; }
.fu-history-title { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#6c3fc5; }
.fu-note-count { background:#ede9fe;color:#6c3fc5;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px; }
.fu-timeline { flex:1;overflow-y:auto;padding:0 20px 10px; }
.fu-tl-item { display:flex;gap:14px;padding:12px 0;border-bottom:1px solid #f9fafb;position:relative; }
.fu-tl-dot { width:12px;height:12px;border-radius:50%;border:2px solid #6c3fc5;background:#fff;flex-shrink:0;margin-top:3px; }
.fu-tl-line { position:absolute;left:5px;top:22px;bottom:-12px;width:2px;background:#e5e7eb; }
.fu-tl-date { font-size:11px;font-weight:700;color:#6c3fc5;white-space:nowrap; }
.fu-tl-time { font-size:10px;color:#9ca3af;margin-top:1px; }
.fu-tl-note { font-size:13px;font-weight:600;color:#111827;margin-bottom:2px; }
.fu-tl-detail { font-size:12px;color:#6b7280;line-height:1.5; }
.fu-tl-ref { color:#6c3fc5;font-weight:700; }
.fu-tl-status { flex-shrink:0;align-self:flex-start; }
.fu-status-chip { display:inline-block;padding:3px 10px;border-radius:999px;font-size:10px;font-weight:700; }
.fu-next-banner { background:#fef3c7;padding:10px 20px;font-size:12px;font-weight:600;color:#92400e;flex-shrink:0;display:none;border-top:1px solid #fde68a; }
.fu-add-form { border-top:1px solid #f3f4f6;padding:16px 20px;background:#f9fafb;flex-shrink:0; }
.fu-add-title { font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#6b7280;margin-bottom:10px; }
.fu-add-textarea { width:100%;border:1.5px solid #e5e7eb;border-radius:8px;padding:10px 12px;font-size:13px;
                   font-family:inherit;resize:none;outline:none;color:#111827;min-height:56px; }
.fu-add-textarea:focus { border-color:#6c3fc5;box-shadow:0 0 0 3px rgba(108,63,197,.1); }
.fu-add-row { display:flex;gap:8px;margin-top:10px;align-items:center;flex-wrap:wrap; }
.fu-add-select,.fu-add-date,.fu-add-ref { border:1.5px solid #e5e7eb;border-radius:8px;padding:8px 10px;
    font-size:12px;font-family:inherit;color:#111827;outline:none;background:#fff; }
.fu-add-select:focus,.fu-add-date:focus,.fu-add-ref:focus { border-color:#6c3fc5; }
.fu-add-select { flex:1;min-width:120px; }
.fu-add-date { min-width:130px; }
.fu-add-ref { min-width:130px;flex:1; }
.fu-save-btn { background:#2d1b6b;color:#fff;border:none;padding:8px 18px;border-radius:8px;
               font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap;
               display:flex;align-items:center;gap:6px; }
.fu-save-btn:hover { background:#3d2a8a; }
.fu-right { width:240px;flex-shrink:0;display:flex;flex-direction:column;overflow:hidden; }
.fu-claims-head { padding:14px 16px 8px;font-size:11px;font-weight:700;text-transform:uppercase;
                  letter-spacing:.08em;color:#6c3fc5;flex-shrink:0; }
.fu-claims-list { flex:1;overflow-y:auto;padding:0 16px 10px; }
.fu-claims-table { width:100%;border-collapse:collapse;font-size:12px; }
.fu-claims-table th { font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;
                       color:#9ca3af;padding:4px 0;border-bottom:1px solid #f3f4f6; }
.fu-claims-table td { padding:8px 0;border-bottom:1px solid #f9fafb;color:#374151; }
.fu-claims-table .fu-total-row td { font-weight:700;color:#6c3fc5;border-top:2px solid #e5e7eb;padding-top:10px;border-bottom:none; }
.fu-footer { padding:14px 20px;display:flex;justify-content:flex-end;gap:10px;border-top:1px solid #f3f4f6;flex-shrink:0; }
.fu-btn-cancel { border:1.5px solid #e5e7eb;background:#fff;padding:8px 20px;border-radius:8px;
                 font-size:13px;font-weight:600;cursor:pointer;color:#374151;font-family:inherit; }
.fu-btn-close { background:#2d1b6b;color:#fff;border:none;padding:8px 24px;border-radius:8px;
                font-size:13px;font-weight:700;cursor:pointer;font-family:inherit; }
</style>

<div id="fu-modal">
    <div class="fu-backdrop" onclick="closeFollowup()"></div>
    <div class="fu-card">

        <!-- Header -->
        <div class="fu-header">
            <div class="fu-header-top">
                <div>
                    <div class="fu-title" id="fu-patient-name">PATIENT NAME</div>
                    <div class="fu-sub" id="fu-carrier-name"></div>
                </div>
                <div style="display:flex;align-items:center;gap:12px;">
                    <div class="fu-days-badge" id="fu-days-badge">— DAYS</div>
                    <button onclick="closeFollowup()" style="background:rgba(255,255,255,.15);border:none;color:#fff;
                        width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:18px;
                        display:flex;align-items:center;justify-content:center;">×</button>
                </div>
            </div>
        </div>

        <!-- Meta bar -->
        <div class="fu-meta-bar">
            <div class="fu-meta-item">
                <div class="fu-meta-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6c3fc5" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div>
                    <div class="fu-meta-label">Oldest DOS</div>
                    <div class="fu-meta-value" id="fu-meta-dos">—</div>
                </div>
            </div>
            <div class="fu-meta-item">
                <div class="fu-meta-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6c3fc5" stroke-width="2">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                </div>
                <div>
                    <div class="fu-meta-label">Status</div>
                    <div class="fu-meta-value" id="fu-meta-status">—</div>
                </div>
            </div>
            <div class="fu-meta-item">
                <div class="fu-meta-icon">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6c3fc5" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                        <circle cx="12" cy="7" r="4"/>
                    </svg>
                </div>
                <div>
                    <div class="fu-meta-label">Assigned To</div>
                    <div class="fu-meta-value">Billing Team</div>
                </div>
            </div>
        </div>

        <!-- Body: left timeline + right claims -->
        <div class="fu-body">

            <!-- Left: History + Add Form -->
            <div class="fu-left">
                <div class="fu-history-head">
                    <span class="fu-history-title">Follow-Up History</span>
                    <span class="fu-note-count" id="fu-note-count">0 NOTES</span>
                </div>
                <div class="fu-timeline" id="fu-history">
                    <div style="padding:24px;text-align:center;color:#9ca3af;font-size:12px;">Loading…</div>
                </div>
                <div class="fu-next-banner" id="fu-next-banner">
                    Next Follow Up: <span id="fu-next-date"></span>
                </div>
                <!-- Add Note Form -->
                <div class="fu-add-form">
                    <div class="fu-add-title">Add Follow-Up Note</div>
                    <textarea id="fu-note" class="fu-add-textarea"
                        placeholder="Enter follow-up notes, conversation details, or updates..."></textarea>
                    <div class="fu-add-row">
                        <select id="fu-status" class="fu-add-select">
                            <option value="Pending">Pending</option>
                            <option value="Called">Called</option>
                            <option value="Processing">Processing</option>
                            <option value="Resubmitted">Resubmitted</option>
                            <option value="Escalated">Escalated</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Portal Check">Portal Check</option>
                        </select>
                        <input type="date" id="fu-next-input" class="fu-add-date"
                               title="Next Follow-Up Date">
                        <input type="text" id="fu-ref" class="fu-add-ref"
                               placeholder="e.g. 4589912" title="Reference # (Optional)">
                        <button class="fu-save-btn" onclick="saveFollowup()">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                                <polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>
                            </svg>
                            Save Note
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- /fu-body -->

        <!-- Footer -->
        <div class="fu-footer">
            <button class="fu-btn-cancel" onclick="closeFollowup()">Cancel</button>
            <button class="fu-btn-close" onclick="closeFollowup()">Close</button>
        </div>

    </div><!-- /fu-card -->
</div><!-- /fu-modal -->

<script>
var _fuPid = 0, _fuClaims = [];
var _csrfToken = <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>;
var _wroot = <?php echo json_encode($GLOBALS['webroot']); ?>;

function openLedgerPopup(pid) {
    top.restoreSession();
    var url = _wroot + '/interface/patient_file/mdi_payments_ledger.php?pid=' + encodeURIComponent(pid);
    dlgopen(url, '', 1100, 720, '', <?php echo xlj('Payment'); ?>, {
        allowResize: true, allowDrag: false, dialogId: 'payment_modal',
        onClosed: 'refreshme', type: 'iframe'
    });
}

function openFollowup(btn) {
    _fuPid = parseInt(btn.dataset.pid) || 0;
    var patName = (btn.dataset.patient || '').toUpperCase();
    var carrier = (btn.dataset.carrier || '').toUpperCase();
    var days    = btn.dataset.days || '—';
    var claims  = btn.dataset.claims || '—';
    var dos     = btn.dataset.dos || '—';
    var status  = btn.dataset.status || 'Pending';

    document.getElementById('fu-patient-name').textContent  = patName;
    document.getElementById('fu-carrier-name').textContent  = carrier + ' · PID ' + _fuPid;
    document.getElementById('fu-days-badge').textContent    = days + ' DAYS';
    document.getElementById('fu-meta-dos').textContent      = dos;
    document.getElementById('fu-meta-status').textContent   = status;
    document.getElementById('fu-note').value    = '';
    document.getElementById('fu-next-input').value = '';
    document.getElementById('fu-ref').value     = '';
    document.getElementById('fu-status').value  = 'Pending';
    document.getElementById('fu-next-banner').style.display = 'none';

    document.getElementById('fu-modal').classList.add('open');
    document.body.style.overflow = 'hidden';

    loadFollowupHistory();
    loadClaimsList();
}

function closeFollowup() {
    document.getElementById('fu-modal').classList.remove('open');
    document.body.style.overflow = '';
}

function loadFollowupHistory() {
    var hist = document.getElementById('fu-history');
    hist.innerHTML = '<div style="padding:24px;text-align:center;color:#9ca3af;font-size:12px;">Loading…</div>';
    fetch(_wroot + '/interface/billing/mdi_aging_claims.php?action=get_followup&pid=' + _fuPid)
        .then(function(r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function(d) {
            renderHistory(d.notes || []);
            var banner = document.getElementById('fu-next-banner');
            if (d.next_date) {
                document.getElementById('fu-next-date').textContent = formatDateLong(d.next_date);
                banner.style.display = 'block';
            } else {
                banner.style.display = 'none';
            }
        })
        .catch(function(e) {
            hist.innerHTML = '<div style="padding:16px;color:#dc2626;font-size:12px;font-style:italic;">No history yet — add the first note below.</div>';
        });
}

function loadClaimsList() {
    // Fetch open encounters for this patient via existing get_followup endpoint
    // (claims sidebar shows DOS + charges from the grid row data)
    var tbody = document.getElementById('fu-claims-tbody');
    tbody.innerHTML = '<tr><td colspan="2" style="color:#9ca3af;font-size:11px;padding:12px 0;">Loading…</td></tr>';
    fetch(_wroot + '/interface/billing/mdi_aging_claims.php?action=get_claims&pid=' + _fuPid)
        .then(function(r){ return r.json(); })
        .then(function(d) {
            var rows = d.claims || [];
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="2" style="color:#9ca3af;font-size:11px;padding:8px 0;">No claims found.</td></tr>';
                return;
            }
            var html = '';
            var total = 0;
            rows.forEach(function(c) {
                total += parseFloat(c.ins_balance || 0);
                html += '<tr><td>' + escH(c.dos_fmt || c.dos || '—') + '</td>'
                     + '<td style="text-align:right;font-weight:600;">$' + escH(parseFloat(c.ins_balance||0).toFixed(2)) + '</td></tr>';
            });
            html += '<tr class="fu-total-row"><td>Total Open Balance</td>'
                 + '<td style="text-align:right;">$' + total.toFixed(2) + '</td></tr>';
            tbody.innerHTML = html;
        })
        .catch(function(){ tbody.innerHTML = '<tr><td colspan="2" style="color:#9ca3af;font-size:11px;padding:8px 0;">—</td></tr>'; });
}

function renderHistory(notes) {
    var hist = document.getElementById('fu-history');
    var countEl = document.getElementById('fu-note-count');
    countEl.textContent = notes.length + ' NOTES';
    if (!notes.length) {
        hist.innerHTML = '<div style="padding:24px;text-align:center;color:#9ca3af;font-size:12px;font-style:italic;">No follow-up notes yet.</div>';
        return;
    }
    var statusColors = {
        'Pending':     ['#fef3c7','#b45309'],
        'Called':      ['#dbeafe','#1d4ed8'],
        'Processing':  ['#e0e7ff','#4338ca'],
        'Resubmitted': ['#d1fae5','#059669'],
        'Escalated':   ['#fee2e2','#dc2626'],
        'Resolved':    ['#dcfce7','#15803d'],
        'Portal Check':['#ede9fe','#6c3fc5']
    };
    var html = '';
    notes.forEach(function(n, i) {
        var sc = statusColors[n.status] || ['#f3f4f6','#6b7280'];
        var isLast = (i === notes.length - 1);
        html += '<div class="fu-tl-item" style="' + (isLast ? 'border-bottom:none;' : '') + '">'
              + '<div style="position:relative;flex-shrink:0;">'
              + '<div class="fu-tl-dot"></div>'
              + (!isLast ? '<div class="fu-tl-line"></div>' : '')
              + '</div>'
              + '<div style="flex:1;min-width:0;">'
              + '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px;">'
              + '<div>'
              + '<div class="fu-tl-date">' + escH(formatDateLong(n.followup_date)) + '</div>'
              + '<div class="fu-tl-time">' + escH(n.followup_time || '') + '</div>'
              + '</div>'
              + '<span class="fu-status-chip" style="background:' + sc[0] + ';color:' + sc[1] + ';">' + escH(n.status) + '</span>'
              + '</div>'
              + '<div class="fu-tl-note">' + escH(n.note) + '</div>'
              + (n.ref_number ? '<div class="fu-tl-detail">Ref # <span class="fu-tl-ref">' + escH(n.ref_number) + '</span></div>' : '')
              + (n.next_followup_date ? '<div style="font-size:11px;color:#d97706;font-weight:600;margin-top:4px;">Next: ' + escH(formatDateLong(n.next_followup_date)) + '</div>' : '')
              + '</div>'
              + '</div>';
    });
    hist.innerHTML = html;
}

function saveFollowup() {
    var note = document.getElementById('fu-note').value.trim();
    if (!note) { document.getElementById('fu-note').focus(); return; }
    var saveBtn = document.querySelector('.fu-save-btn');
    saveBtn.disabled = true;
    var fd = new FormData();
    fd.append('action',          'save_followup');
    fd.append('csrf_token_form', _csrfToken);
    fd.append('fp_pid',          _fuPid);
    fd.append('fp_enc',          0);
    fd.append('fp_note',         note);
    fd.append('fp_status',       document.getElementById('fu-status').value);
    fd.append('fp_next',         document.getElementById('fu-next-input').value);
    fd.append('fp_ref',          document.getElementById('fu-ref').value);
    fetch(_wroot + '/interface/billing/mdi_aging_claims.php', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(d) {
            saveBtn.disabled = false;
            if (d.success) {
                document.getElementById('fu-note').value = '';
                document.getElementById('fu-next-input').value = '';
                document.getElementById('fu-ref').value = '';
                loadFollowupHistory();
                var btn = document.querySelector('.followup-btn[data-pid="'+_fuPid+'"]');
                if (btn) btn.classList.add('has-notes');
            } else {
                alert(d.error || 'Save failed.');
            }
        })
        .catch(function(){ saveBtn.disabled = false; alert('Network error.'); });
}

function formatDateLong(d) {
    if (!d) return '';
    var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var parts = d.split('-');
    return months[parseInt(parts[1],10)-1] + ' ' + parseInt(parts[2],10) + ', ' + parts[0];
}
function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeFollowup(); });
</script>
</body>
</html>
