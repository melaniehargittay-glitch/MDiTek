<?php
/**
 * mdi_visits.php — Encounters History
 * Deploy to: /interface/main/tabs/mdi_visits.php
 */
$sessionAllowWrite = false;
require_once(__DIR__ . '/../../globals.php');
if (empty($_SESSION['authUser'])) { http_response_code(403); exit('Not authorized'); }

use OpenEMR\Common\Csrf\CsrfUtils;

$mdi_csrf = CsrfUtils::collectCsrfToken();

// The "MDI session-sign" table used by the PIN-based Sign Note/Sign
// Encounter flow (see verify_pin.php). Created lazily here too, so this
// page never fails to load even if verify_pin.php hasn't run yet.
sqlStatement(
    "CREATE TABLE IF NOT EXISTS mdi_signatures (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        pid         INT NOT NULL,
        encounter   INT NOT NULL,
        uid         INT NOT NULL,
        signer_name VARCHAR(191) NOT NULL,
        signed_at   DATETIME NOT NULL,
        UNIQUE KEY uniq_pid_encounter (pid, encounter)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8"
);

$today = date('Y-m-d');

// ── Input ──────────────────────────────────────────────────────────────────
$f_from     = $_GET['date_from'] ?? '2000-01-01';
$f_to       = $_GET['date_to']   ?? $today;
$f_provider = isset($_GET['provider']) ? (int)$_GET['provider'] : 0;
$f_patient  = trim($_GET['patient'] ?? '');
$f_tab      = in_array($_GET['tab'] ?? '', ['all','unsigned','signed']) ? $_GET['tab'] : 'all';
$page       = max(1, (int)($_GET['page']     ?? 1));
$per_page   = in_array((int)($_GET['per_page'] ?? 25), [25,50,100]) ? (int)($_GET['per_page'] ?? 25) : 25;
$sort       = in_array($_GET['sort'] ?? '', ['date','patient','encounter','provider','note_status']) ? $_GET['sort'] : 'date';
$dir        = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

// ── Build WHERE ────────────────────────────────────────────────────────────
// Only columns that exist in form_encounter schema + forms schema
$wp = [];
$wc = "fe.date >= ? AND fe.date <= ?
    AND EXISTS (
        SELECT 1 FROM forms sf
         WHERE sf.encounter  = fe.encounter
           AND sf.pid        = fe.pid
           AND sf.form_name LIKE 'SOAP%'
           AND sf.deleted    = 0
    )";
$wp[] = $f_from . ' 00:00:00';
$wp[] = $f_to   . ' 23:59:59';

if ($f_provider > 0) {
    $wc .= " AND fe.provider_id = ?";
    $wp[] = $f_provider;
}
if ($f_patient !== '') {
    $wc .= " AND (CONCAT(COALESCE(pd.lname,''),' ',COALESCE(pd.fname,'')) LIKE ? OR CAST(fe.pid AS CHAR) = ?)";
    $wp[] = '%' . $f_patient . '%';
    $wp[] = $f_patient;
}

// ── Note-status HAVING ─────────────────────────────────────────────────────
// Signed = row in esign_signatures (OpenEMR native) OR mdi_signatures (MDI session-sign)
$signed_expr = 'SUM(CASE WHEN es.id IS NOT NULL OR ms.id IS NOT NULL THEN 1 ELSE 0 END)';
$hav = '';
if ($f_tab === 'unsigned') $hav = "HAVING $signed_expr = 0";
if ($f_tab === 'signed')   $hav = "HAVING $signed_expr >= 1";

// ── Count helper ───────────────────────────────────────────────────────────
function mdiVCount($wc, $wp, $hav) {
    $sql = "SELECT COUNT(*) AS n FROM (
        SELECT fe.encounter
        FROM form_encounter fe
        LEFT JOIN patient_data pd ON pd.pid = fe.pid
        LEFT JOIN esign_signatures es
            ON  es.tid     = fe.encounter
            AND es.`table` = 'form_encounter'
        LEFT JOIN mdi_signatures ms
            ON  ms.encounter = fe.encounter
            AND ms.pid       = fe.pid
        WHERE $wc
        GROUP BY fe.encounter, fe.pid
        $hav
    ) AS _c";
    try {
        $r = sqlQuery($sql, $wp);
        return (int)($r['n'] ?? 0);
    } catch (Throwable $e) {
        error_log('mdi_visits count: ' . $e->getMessage());
        return 0;
    }
}

// ── Tab counts ─────────────────────────────────────────────────────────────
$cnt_all      = mdiVCount($wc, $wp, '');
$cnt_unsigned = mdiVCount($wc, $wp, "HAVING $signed_expr = 0");
$cnt_signed   = mdiVCount($wc, $wp, "HAVING $signed_expr >= 1");

// ── Pagination ─────────────────────────────────────────────────────────────
$total       = mdiVCount($wc, $wp, $hav);
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

// ── Sort ───────────────────────────────────────────────────────────────────
$order_col = [
    'date'        => 'fe.date',
    'patient'     => 'pt_lname',
    'encounter'   => 'fe.encounter',
    'provider'    => 'prov_lname',
    'note_status' => 'note_auth',
][$sort] ?? 'fe.date';

// ── Data ───────────────────────────────────────────────────────────────────
// Columns from confirmed schema only:
// form_encounter: encounter, pid, date, facility (longtext), provider_id
// forms: authorized
// patient_data: fname, lname, DOB
// users: fname, lname
$rows = [];
try {
    $data_sql = "
        SELECT
            fe.encounter,
            fe.pid,
            fe.date,
            MIN(fe.facility)                AS location,
            MIN(pd.fname)                   AS pt_fname,
            MIN(pd.lname)                   AS pt_lname,
            MIN(pd.DOB)                     AS DOB,
            MIN(u.fname)                    AS prov_fname,
            MIN(u.lname)                    AS prov_lname,
            -- 1 if signed via OpenEMR native esign OR MDI session-sign, 0 otherwise
            MAX(CASE WHEN es.id IS NOT NULL OR ms.id IS NOT NULL THEN 1 ELSE 0 END) AS note_auth,
            -- signer name: prefer esign_signatures, fall back to mdi_signatures
            COALESCE(
                MIN(CASE WHEN es.id IS NOT NULL THEN CONCAT(su.fname,' ',su.lname) END),
                MIN(ms.signer_name)
            ) AS signer_name,
            -- signed timestamp: same preference order
            COALESCE(
                MIN(CASE WHEN es.id IS NOT NULL THEN es.datetime END),
                MIN(ms.signed_at)
            ) AS signed_at
        FROM form_encounter fe
        LEFT JOIN patient_data pd  ON pd.pid = fe.pid
        LEFT JOIN users u          ON u.id   = fe.provider_id
        LEFT JOIN esign_signatures es
            ON  es.tid     = fe.encounter
            AND es.`table` = 'form_encounter'
        LEFT JOIN users su         ON su.id  = es.uid
        LEFT JOIN mdi_signatures ms
            ON  ms.encounter = fe.encounter
            AND ms.pid       = fe.pid
        WHERE $wc
        GROUP BY fe.encounter, fe.pid, fe.date
        $hav
        ORDER BY $order_col $dir
        LIMIT ? OFFSET ?";

    $dp = array_merge($wp, [$per_page, $offset]);
    $res = sqlStatement($data_sql, $dp);
    while ($row = sqlFetchArray($res)) $rows[] = $row;
} catch (Throwable $e) {
    error_log('mdi_visits data: ' . $e->getMessage());
}

// ── Provider dropdown ──────────────────────────────────────────────────────
$providers = [];
try {
    $r = sqlStatement(
        "SELECT id, CONCAT(fname,' ',lname) AS name FROM users
          WHERE active = 1
            AND (
                authorized = 1
                OR (npi IS NOT NULL AND npi != '')
                OR physician_type IS NOT NULL
            )
          ORDER BY lname, fname", []);
    while ($row = sqlFetchArray($r)) $providers[] = $row;
} catch (Throwable $e) {}

// ── Helpers ────────────────────────────────────────────────────────────────
function mdiVAge($dob) {
    if (!$dob || substr($dob,0,4) === '0000') return '';
    try { return (new DateTime())->diff(new DateTime($dob))->y . ' y/o'; }
    catch(Exception $e){ return ''; }
}
function mdiVEnc($n) { return 'ENC-'.str_pad($n,7,'0',STR_PAD_LEFT); }
function mdiVInit($name) {
    $p = explode(' ', trim($name));
    return strtoupper(substr($p[0]??'',0,1).substr(end($p),0,1))?:'?';
}
$mdiColors = ['#6366f1','#0d9488','#f59e0b','#ef4444','#8b5cf6','#10b981','#3b82f6','#ec4899'];
function mdiVColor($name,$c){return $c[abs(crc32($name))%count($c)];}
function mdiVUrl($ov=[]){
    $p = array_merge([
        'date_from'=>$_GET['date_from']??'','date_to'=>$_GET['date_to']??'',
        'provider'=>$_GET['provider']??'','patient'=>$_GET['patient']??'',
        'tab'=>$_GET['tab']??'all','sort'=>$_GET['sort']??'date',
        'dir'=>$_GET['dir']??'DESC','per_page'=>$_GET['per_page']??25,
        'page'=>$_GET['page']??1,
    ],$ov);
    return '?'.http_build_query(array_filter($p,fn($v)=>$v!==''&&$v!==0));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Encounters History</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap');
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--navy:#1e0a3c;--teal:#0d9488;--rose:#dc2626;--green:#16a34a;--amber:#d97706;--violet:#7c3aed;--muted:#64748b;--border:#e2e6ed;--bg:#f0f2f5;--text:#0f172a;--text2:#334155;--surface:#fff}
html,body{background:var(--bg);font-family:'Montserrat',sans-serif;font-size:13px;color:var(--text)}
.page{padding:1.4rem 1.8rem}
/* Header */
.ph{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.3rem}
.pt{font-size:1.4rem;font-weight:700;color:var(--navy)}
.ps{font-size:.75rem;color:var(--muted);margin-top:2px}
.btn-exp{display:flex;align-items:center;gap:5px;padding:.5rem 1rem;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);color:var(--text2);font-size:.75rem;font-weight:600;cursor:pointer;font-family:inherit}
.btn-exp:hover{border-color:var(--navy);color:var(--navy)}
/* Filters */
.fc{background:var(--surface);border-radius:12px;padding:1.1rem 1.3rem;box-shadow:0 1px 4px rgba(0,0,0,.06);margin-bottom:1.1rem}
.ftitle{display:flex;align-items:center;gap:5px;font-size:.8rem;font-weight:700;color:var(--navy);margin-bottom:.9rem}
.fg{display:grid;grid-template-columns:1.4fr 1fr 1fr;gap:.7rem}
.fg2{display:flex;gap:.6rem;margin-top:.7rem;align-items:flex-end}
.fl{font-size:.62rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:3px}
.fi,.fs{padding:.42rem .65rem;border:1.5px solid var(--border);border-radius:7px;font-size:.75rem;font-family:inherit;color:var(--text);background:var(--surface);outline:none;width:100%}
.fi:focus,.fs:focus{border-color:var(--navy)}
.dr{display:flex;align-items:center;gap:5px}
.dr input{flex:1;padding:.4rem .55rem;border:1.5px solid var(--border);border-radius:6px;font-size:.75rem;font-family:inherit;outline:none;min-width:0}
.dr input:focus{border-color:var(--navy)}
.fsr{position:relative}.fsr input{padding-right:1.8rem}
.fsr svg{position:absolute;right:.55rem;top:50%;transform:translateY(-50%);color:var(--muted);pointer-events:none}
.btn-go{display:flex;align-items:center;gap:4px;padding:.45rem .9rem;border-radius:7px;background:var(--navy);color:#fff;font-size:.75rem;font-weight:700;cursor:pointer;border:none;font-family:inherit;white-space:nowrap}
.btn-clr{padding:.45rem .85rem;border-radius:7px;background:var(--surface);color:var(--text2);font-size:.75rem;font-weight:600;cursor:pointer;border:1.5px solid var(--border);font-family:inherit;white-space:nowrap}
.btn-clr:hover{border-color:var(--rose);color:var(--rose)}
/* Tabs */
.tabs{display:flex;align-items:center;border-bottom:2px solid var(--border);margin-bottom:.9rem}
.tab{padding:.55rem .9rem;font-size:.75rem;font-weight:600;color:var(--muted);cursor:pointer;border-bottom:2.5px solid transparent;margin-bottom:-2px;white-space:nowrap;text-decoration:none;display:inline-flex;align-items:center;gap:4px}
.tab.on{color:var(--navy);border-bottom-color:var(--navy)}
.tab:hover:not(.on){color:var(--text2)}
.tc{font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:20px;background:#f1f5f9;color:var(--muted)}
.tab.on .tc{background:var(--navy);color:#fff}
.tab-r{margin-left:auto;font-size:.7rem;color:var(--muted);align-self:center;padding-bottom:5px}
/* Table */
.tcard{background:var(--surface);border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden}
table{width:100%;border-collapse:collapse}
thead tr{background:#f8fafc;border-bottom:1.5px solid var(--border)}
th{padding:.65rem .9rem;text-align:left;font-size:.65rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;white-space:nowrap}
th a{color:inherit;text-decoration:none;display:flex;align-items:center;gap:3px}
th a:hover{color:var(--navy)}
tbody tr{border-bottom:1px solid #f1f5f9;cursor:pointer;transition:background .1s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#f8fafc}
td{padding:.7rem .9rem;vertical-align:middle;font-size:.78rem;color:var(--text2)}
.td-dt{font-size:.75rem;font-weight:600;color:var(--navy);white-space:nowrap}
.td-tm{font-size:.65rem;color:var(--muted);margin-top:1px}
.td-pt{display:flex;align-items:center;gap:8px}
.av{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.62rem;font-weight:700;color:#fff;flex-shrink:0}
.pt-n{font-weight:600;color:var(--navy);font-size:.78rem}
.pt-d{font-size:.65rem;color:var(--muted);margin-top:1px}
.enc{font-family:monospace;font-size:.72rem;color:var(--muted)}
.bu{display:inline-flex;align-items:center;gap:4px;background:#fff1f2;color:var(--rose);font-size:.64rem;font-weight:700;padding:2px 8px;border-radius:20px}
.bs{display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:var(--green);font-size:.64rem;font-weight:700;padding:2px 8px;border-radius:20px}
.dot{width:5px;height:5px;border-radius:50%;background:currentColor}
.btn-note{display:inline-flex;align-items:center;gap:4px;padding:.3rem .7rem;border-radius:6px;background:var(--surface);border:1.5px solid var(--border);color:var(--text2);font-size:.7rem;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
.btn-note:hover{border-color:var(--navy);color:var(--navy)}
.act-btns{display:flex;align-items:center;gap:5px;flex-wrap:wrap}
.btn-sign{display:inline-flex;align-items:center;gap:4px;padding:.3rem .7rem;border-radius:6px;background:var(--violet);border:1.5px solid var(--violet);color:#fff;font-size:.7rem;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
.btn-sign:hover{background:#6d28d9;border-color:#6d28d9}
.btn-sign:disabled{opacity:.6;cursor:default}
.btn-print{display:inline-flex;align-items:center;gap:4px;padding:.3rem .7rem;border-radius:6px;background:var(--surface);border:1.5px solid var(--border);color:var(--text2);font-size:.7rem;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
.btn-print:hover{border-color:var(--teal);color:var(--teal)}
.btn-more{width:26px;height:26px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);cursor:pointer;display:inline-flex;align-items:center;justify-content:center;margin-left:3px}
.btn-more:hover{background:#f1f5f9}
/* Pagination */
.pgbar{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1rem;border-top:1.5px solid var(--border);flex-wrap:wrap;gap:.5rem}
.pgi{font-size:.7rem;color:var(--muted)}
.pgc{display:flex;align-items:center;gap:3px}
.pgb{width:28px;height:28px;border-radius:6px;border:1.5px solid var(--border);background:var(--surface);color:var(--text2);font-size:.75rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;text-decoration:none;transition:all .12s}
.pgb:hover{border-color:var(--navy);color:var(--navy)}
.pgb.on{background:var(--navy);border-color:var(--navy);color:#fff}
.pgb.off{opacity:.35;pointer-events:none}
.ppr{display:flex;align-items:center;gap:5px;font-size:.7rem;color:var(--muted)}
.ppr select{padding:2px 5px;border:1.5px solid var(--border);border-radius:5px;font-size:.7rem;font-family:inherit;background:var(--surface);outline:none}
/* Empty */
.empty{text-align:center;padding:3rem;color:var(--muted);font-size:.82rem}
</style>
</head>
<body>
<div class="page">

<!-- Header -->
<div class="ph">
  <div>
    <div class="pt">Encounters History</div>
    <div class="ps">View all encounters and sign any unsigned notes.</div>
  </div>
  <button class="btn-exp" onclick="doExport()">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Export
  </button>
</div>

<!-- Filters -->
<form method="get" id="ff">
<div class="fc">
  <div class="ftitle">
    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
    Filters
  </div>
  <div class="fg">
    <div>
      <div class="fl">Date Range</div>
      <div class="dr">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        <input type="date" name="date_from" value="<?php echo attr($f_from); ?>">
        <span style="color:var(--muted);font-size:.72rem">–</span>
        <input type="date" name="date_to" value="<?php echo attr($f_to); ?>">
      </div>
    </div>
    <div>
      <div class="fl">Provider</div>
      <select name="provider" class="fs">
        <option value="">All Providers</option>
        <?php foreach ($providers as $p): ?>
        <option value="<?php echo attr($p['id']); ?>" <?php echo $f_provider==$p['id']?'selected':''; ?>><?php echo text($p['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <div class="fl">Patient</div>
      <div class="fsr">
        <input type="text" name="patient" class="fi" placeholder="Name or ID…" value="<?php echo attr($f_patient); ?>">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
      </div>
    </div>
  </div>
  <div class="fg2">
    <button type="button" class="btn-clr" onclick="doClear()">Clear Filters</button>
    <button type="submit" class="btn-go">
      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
      Apply Filters
    </button>
  </div>
  <input type="hidden" name="tab"      value="<?php echo attr($f_tab); ?>">
  <input type="hidden" name="sort"     value="<?php echo attr($sort); ?>">
  <input type="hidden" name="dir"      value="<?php echo attr($dir); ?>">
  <input type="hidden" name="per_page" value="<?php echo attr($per_page); ?>">
</div>
</form>

<!-- Tabs -->
<div class="tabs">
  <?php
  $tabDefs = ['all'=>['All Encounters',$cnt_all],'unsigned'=>['Unsigned Notes',$cnt_unsigned],'signed'=>['Signed',$cnt_signed]];
  foreach ($tabDefs as $tk => [$tl,$tc]):
    $url = mdiVUrl(['tab'=>$tk,'page'=>1]);
  ?>
  <a href="<?php echo $url; ?>" class="tab <?php echo $f_tab===$tk?'on':''; ?>">
    <?php echo text($tl); ?> <span class="tc"><?php echo $tc; ?></span>
  </a>
  <?php endforeach; ?>
  <div class="tab-r"><?php echo $cnt_unsigned; ?> unsigned notes</div>
</div>

<!-- Table -->
<div class="tcard">
<?php if (empty($rows)): ?>
  <div class="empty">No encounters found for the selected filters.</div>
<?php else: ?>
  <?php
  function mdiVSortLink($col,$label,$cur,$cdir){
    $nd = ($cur===$col && $cdir==='DESC')?'ASC':'DESC';
    $url = mdiVUrl(['sort'=>$col,'dir'=>$nd,'page'=>1]);
    $on  = $cur===$col;
    $svg = '<svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="'
         . ($on&&$cdir==='DESC'?'18 15 12 9 6 15':'6 9 12 15 18 9')
         . '"/></svg>';
    return '<a href="'.htmlspecialchars($url).'" style="color:inherit;text-decoration:none;display:flex;align-items:center;gap:3px">'.htmlspecialchars($label).$svg.'</a>';
  }
  ?>
  <table>
    <thead>
      <tr>
        <th><?php echo mdiVSortLink('date','Date/Time',$sort,$dir); ?></th>
        <th><?php echo mdiVSortLink('patient','Patient',$sort,$dir); ?></th>
        <th><?php echo mdiVSortLink('encounter','Encounter ID',$sort,$dir); ?></th>
        <th><?php echo mdiVSortLink('provider','Provider',$sort,$dir); ?></th>
        <th>Location</th>
        <th><?php echo mdiVSortLink('note_status','Note Status',$sort,$dir); ?></th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $row):
      $unsigned  = (int)($row['note_auth']??0) === 0;
      $dob       = $row['DOB'] ?? '';
      $dob_fmt   = ($dob && substr($dob,0,4)!=='0000') ? date('m/d/Y',strtotime($dob)).' ('.mdiVAge($dob).')' : '';
      $dt        = $row['date'] ?? '';
      $dt_date   = $dt ? date('m/d/Y',strtotime($dt)) : '';
      $dt_time   = $dt ? date('g:i A',strtotime($dt)) : '';
      $pt_name   = trim(($row['pt_fname']??'').' '.($row['pt_lname']??''));
      $prov_name = trim(($row['prov_fname']??'').' '.($row['prov_lname']??''));
      $location  = $row['location']      ?? '';
      $av_color  = mdiVColor($pt_name, $mdiColors);
      $initials  = mdiVInit($pt_name);
      $pid       = (int)$row['pid'];
      $enc       = (int)$row['encounter'];
    ?>
    <tr onclick="openEnc(<?php echo $pid; ?>,<?php echo $enc; ?>)">
      <td>
        <div class="td-dt"><?php echo text($dt_date); ?></div>
        <div class="td-tm"><?php echo text($dt_time); ?></div>
      </td>
      <td>
        <div class="td-pt">
          <div class="av" style="background:<?php echo attr($av_color); ?>"><?php echo text($initials); ?></div>
          <div>
            <div class="pt-n"><?php echo text($pt_name); ?></div>
            <div class="pt-d"><?php echo text($dob_fmt); ?></div>
          </div>
        </div>
      </td>
      <td><span class="enc"><?php echo text(mdiVEnc($enc)); ?></span></td>
      <td><?php echo text($prov_name); ?></td>
      <td><?php echo text($location ?: 'Main Office'); ?></td>
      <td>
        <?php if ($unsigned): ?>
          <span class="bu"><span class="dot"></span>Unsigned</span>
        <?php else:
          $signer  = trim($row['signer_name'] ?? '');
          $sig_at  = !empty($row['signed_at']) ? date('m/d/Y g:i A', strtotime($row['signed_at'])) : '';
        ?>
          <span class="bs"><span class="dot"></span>Signed</span>
          <?php if ($signer || $sig_at): ?>
          <div style="font-size:10px;color:var(--muted);margin-top:2px;line-height:1.3;">
            <?php if ($signer) echo text($signer); ?>
            <?php if ($sig_at) echo '<br>' . text($sig_at); ?>
          </div>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td onclick="event.stopPropagation()">
        <div class="act-btns">
          <button class="btn-note" onclick="openEnc(<?php echo $pid; ?>,<?php echo $enc; ?>)">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Open Note
          </button>
          <?php if ($unsigned): ?>
          <button class="btn-sign" onclick="signNoteClick(<?php echo $pid; ?>,<?php echo $enc; ?>,this)">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            Sign Note
          </button>
          <?php endif; ?>
          <button class="btn-print" onclick="printEncounter(<?php echo $pid; ?>,<?php echo $enc; ?>)">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Encounter
          </button>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

  <!-- Pagination -->
  <div class="pgbar">
    <div class="pgi">
      <?php
      $fr = $total > 0 ? $offset+1 : 0;
      $to = min($offset+$per_page, $total);
      echo "Showing $fr to $to of $total entries";
      ?>
    </div>
    <div class="pgc">
      <a href="<?php echo mdiVUrl(['page'=>$page-1]); ?>" class="pgb <?php echo $page<=1?'off':''; ?>">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
      </a>
      <?php for($p=max(1,$page-2);$p<=min($total_pages,$page+2);$p++): ?>
      <a href="<?php echo mdiVUrl(['page'=>$p]); ?>" class="pgb <?php echo $p===$page?'on':''; ?>"><?php echo $p; ?></a>
      <?php endfor; ?>
      <a href="<?php echo mdiVUrl(['page'=>$page+1]); ?>" class="pgb <?php echo $page>=$total_pages?'off':''; ?>">
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
      </a>
    </div>
    <div class="ppr">
      Rows per page:
      <select onchange="changePP(this.value)">
        <?php foreach([25,50,100] as $pp): ?>
        <option value="<?php echo $pp; ?>" <?php echo $per_page==$pp?'selected':''; ?>><?php echo $pp; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
</div><!-- /tcard -->

</div><!-- /page -->

<script>
var _wr = <?php echo js_escape($GLOBALS['web_root']); ?>;
function openEnc(pid,enc){
    var url = _wr+'/interface/patient_file/encounter/encounter_top.php?set_encounter='+enc+'&pid='+pid;
    try{ if(window.top&&typeof window.top.mdiNavGo==='function') window.top.mdiNavGo(url); else window.top.location.href=url; }
    catch(e){ window.location.href=url; }
}

// Opens the same mdi_print_report.php preview popup used by the Print
// button on the encounter forms page (printEncounterLetter() there).
function printEncounter(pid,enc){
    var url = _wr+'/interface/patient_file/encounter/mdi_print_report.php'
            + '?pid='+pid+'&encounter='+enc;
    var w = window.open(url, 'mdi_enc_print_'+pid+'_'+enc,
        'width=940,height=720,scrollbars=yes,resizable=yes,menubar=yes,toolbar=yes');
    if (w) { w.focus(); }
}

// ── Sign Note (PIN-based) ───────────────────────────────────────────────
var _mdiSignCsrf = <?php echo js_escape($mdi_csrf); ?>;
var _mdiSignTarget = null; // { pid, enc, btn }

function signNoteClick(pid, enc, btnEl) {
    _mdiSignTarget = { pid: pid, enc: enc, btn: btnEl };
    var orig = btnEl.innerHTML;
    btnEl.disabled = true;
    btnEl.textContent = 'Signing…';
    mdiPostSign(pid, enc, null).then(function (data) {
        if (data && data.success) {
            location.reload();
            return;
        }
        btnEl.disabled = false;
        btnEl.innerHTML = orig;
        if (data && data.need_pin) {
            mdiPinModalOpen();
        } else {
            alert((data && data.message) || 'Could not sign note. Please try again.');
        }
    }).catch(function () {
        btnEl.disabled = false;
        btnEl.innerHTML = orig;
        alert('Could not sign note. Please try again.');
    });
}

function mdiPostSign(pid, enc, pin) {
    var body = new FormData();
    body.append('encounter', enc);
    body.append('pid', pid);
    body.append('csrf_token_form', _mdiSignCsrf);
    if (pin) body.append('pin', pin);
    return fetch(_wr + '/interface/esign/verify_pin.php', {
        method: 'POST', credentials: 'same-origin', body: body
    }).then(function (r) { return r.json(); });
}

function mdiPinModalOpen() {
    document.getElementById('mdiPinModal').style.display = 'flex';
    document.getElementById('mdiPinInput').value = '';
    document.getElementById('mdiPinError').style.display = 'none';
    setTimeout(function () {
        var el = document.getElementById('mdiPinInput');
        if (el) el.focus();
    }, 30);
}
function mdiPinModalClose() {
    document.getElementById('mdiPinModal').style.display = 'none';
    _mdiSignTarget = null;
}
function mdiPinSubmit() {
    if (!_mdiSignTarget) { mdiPinModalClose(); return; }
    var pin = document.getElementById('mdiPinInput').value.trim();
    var errEl = document.getElementById('mdiPinError');
    if (!/^\d{4,8}$/.test(pin)) {
        errEl.textContent = 'Enter your 4–8 digit PIN.';
        errEl.style.display = 'block';
        return;
    }
    var submitBtn = document.getElementById('mdiPinSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Verifying…';
    mdiPostSign(_mdiSignTarget.pid, _mdiSignTarget.enc, pin).then(function (data) {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Sign Note';
        if (data && data.success) {
            mdiPinModalClose();
            location.reload();
            return;
        }
        errEl.textContent = (data && data.message) || 'Incorrect PIN.';
        errEl.style.display = 'block';
        document.getElementById('mdiPinInput').value = '';
        document.getElementById('mdiPinInput').focus();
    }).catch(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Sign Note';
        errEl.textContent = 'Could not verify PIN. Please try again.';
        errEl.style.display = 'block';
    });
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') mdiPinModalClose();
});

function doClear(){
    window.location.href='?tab=all&date_from=2000-01-01&date_to=<?php echo attr($today); ?>';
}
function changePP(v){
    window.location.href='<?php echo mdiVUrl(['per_page'=>'__PP__','page'=>1]); ?>'.replace('__PP__',v);
}
function doExport(){
    window.location.href=window.location.href+(window.location.href.indexOf('?')>=0?'&':'?')+'export=csv&per_page=9999&page=1';
}
try{ if(window.top&&window.top!==window) window.top.postMessage({type:'mdi_refresh'},'*'); }catch(e){}
</script>

<?php
// ── CSV export ────────────────────────────────────────────────────────────
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="encounters_'.date('Y-m-d').'.csv"');
    echo "Date,Time,Patient,DOB,Encounter ID,Provider,Location,Note Status,Signed By,Signed At\n";
    foreach ($rows as $r) {
        $dt = $r['date'] ?? '';
        $sig_at = !empty($r['signed_at']) ? date('m/d/Y g:i A', strtotime($r['signed_at'])) : '';
        echo implode(',', array_map(fn($v) => '"'.str_replace('"','""',$v).'"', [
            $dt ? date('m/d/Y',strtotime($dt)) : '',
            $dt ? date('g:i A',strtotime($dt)) : '',
            trim(($r['pt_fname']??'').' '.($r['pt_lname']??'')), $r['DOB']??'',
            mdiVEnc($r['encounter']),
            trim(($r['prov_fname']??'').' '.($r['prov_lname']??'')), $r['location']??'',
            (int)($r['note_auth']??0)?'Signed':'Unsigned',
            trim($r['signer_name'] ?? ''),
            $sig_at,
        ]))."\n";
    }
    exit;
}
?>
<!-- ── Sign Note — PIN modal ─────────────────────────────────────────── -->
<div id="mdiPinModal" style="display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;">
    <div onclick="mdiPinModalClose()" style="position:absolute;inset:0;background:rgba(10,5,25,0.55);backdrop-filter:blur(2px)"></div>
    <div style="position:relative;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.25);width:min(340px,90vw);overflow:hidden;">
        <div style="padding:1.5rem 1.5rem 1.1rem;text-align:center;">
            <div style="width:44px;height:44px;border-radius:50%;background:#f5f2fc;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
            </div>
            <div style="font-size:1rem;font-weight:700;color:var(--navy);">Enter Signing PIN</div>
            <div style="font-size:0.78rem;color:var(--muted);margin-top:5px;">Confirm your identity to sign this note.</div>
        </div>
        <div style="padding:0 1.5rem 0.5rem;">
            <input id="mdiPinInput" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="off"
                placeholder="••••" autofocus
                style="width:100%;padding:12px;border:1px solid var(--border);border-radius:8px;font-size:1.3rem;text-align:center;letter-spacing:6px;box-sizing:border-box;font-family:inherit;">
            <div id="mdiPinError" style="display:none;font-size:0.75rem;color:var(--rose);margin-top:8px;text-align:center;"></div>
        </div>
        <div style="padding:1rem 1.5rem 1.5rem;display:flex;flex-direction:column;gap:8px;">
            <button type="button" id="mdiPinSubmitBtn" onclick="mdiPinSubmit()"
                style="padding:10px;font-size:13px;font-weight:700;background:var(--violet);color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;">
                Sign Note
            </button>
            <button type="button" onclick="mdiPinModalClose()"
                style="padding:10px;font-size:13px;font-weight:600;background:#fff;color:var(--text2);border:1px solid var(--border);border-radius:8px;cursor:pointer;font-family:inherit;">
                Cancel
            </button>
        </div>
    </div>
</div>
<script>
document.getElementById('mdiPinInput').addEventListener('keydown', function (e) {
    if (e.key === 'Enter') mdiPinSubmit();
});
</script>

</body>
</html>
