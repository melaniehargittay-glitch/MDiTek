<?php
/**
 * prior_auth_manager.php
 * Insurance Authorization Manager — insauth table, inline table editing.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(__DIR__ . '/../globals.php');
require_once "$srcdir/user.inc.php";
require_once "$srcdir/options.inc.php";

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$pid = isset($GLOBALS['pid']) ? (int)$GLOBALS['pid']
     : (isset($_SESSION['pid']) ? (int)$_SESSION['pid'] : 0);

function nd($v) {
    $v = trim($v ?? '');
    if ($v === '' || $v === 'NULL') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v;
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m))
        return $m[3].'-'.str_pad($m[1],2,'0',STR_PAD_LEFT).'-'.str_pad($m[2],2,'0',STR_PAD_LEFT);
    return null;
}


// ── GET: load single record for inline edit ───────────────────────────────────
if (isset($_GET['ajax_load']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $rid = (int)$_GET['id'];
    $row = sqlQuery(
        "SELECT ia.*
           FROM insauth ia
          WHERE ia.id = ?
            AND (ia.pid = ? OR ia.pid = 0)",
        [$rid, $pid]
    
    );
    echo json_encode($row ?: []);
    exit;
}

// ── POST: delete ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>'CSRF']); exit;
    }
    $rid = (int)($_POST['id'] ?? 0);
    if ($rid && $pid) {
        // Verify this auth belongs to this patient before deleting
        $chk = sqlQuery(
            "SELECT id FROM insauth WHERE id = ? AND (pid = ? OR pid = 0)",
            [$rid, $pid]
        );
        if ($chk) {
            sqlStatement("DELETE FROM insauth WHERE id=?", [$rid]);
            echo json_encode(['success'=>true]);
        } else {
            echo json_encode(['success'=>false,'error'=>'Record not found for this patient']);
        }
    } else {
        echo json_encode(['success'=>false,'error'=>'Invalid ID']);
    }
    exit;
}

// ── POST: insert / update ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['insert','update'])) {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>'CSRF']); exit;
    }

    $auth_number = trim($_POST['auth_number'] ?? '');
    $visits    = (int)($_POST['visits_approved']  ?? 0);
    $visits_used  = (int)($_POST['visits_used'] ?? 0);
    $dollars   = (float)($_POST['dollars_approved'] ?? 0);
    $dollars_used = (float)($_POST['dollars_used'] ?? 0);
    $start     = nd($_POST['start_date']  ?? '');
    $expiry    = nd($_POST['expiry_date'] ?? '');
    $policy_id = (int)($_POST['policy_id'] ?? 0) ?: null;
    $prc       = (int)($_POST['prc_mst_id'] ?? 0) ?: null;
    $auth_type = strtoupper(trim($_POST['auth_type'] ?? ''));
    $notes     = trim($_POST['notes'] ?? '');
    $user      = $_SESSION['authUser'] ?? 'system';

    if (!$pid) {
        echo json_encode(['success'=>false,'error'=>'Patient context is required.']); exit;
    }
    if (!$auth_number) {
        echo json_encode(['success'=>false,'error'=>'Authorization number is required.']); exit;
    }

    $cpt_code  = strtoupper(trim($_POST['cpt_code'] ?? ''));

    if ($_POST['action'] === 'insert') {
        sqlStatement(
            "INSERT INTO insauth
                (pid,policy_id,auth_number,prc_mst_id,visits_approved,visits_used,
                 dollars_approved,dollars_used,notes,username,tran_date,created,
                 created_by,start_date,expiry_date,auth_type,cpt_code)
             VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),?,?,?,?,?)",
            [$pid,$policy_id,$auth_number,$prc,$visits,$visits_used,
             $dollars,$dollars_used,$notes,$user,$user,$start,$expiry,$auth_type,$cpt_code]
        );
        $new_id = (int)(sqlQuery("SELECT LAST_INSERT_ID() AS id")['id']);

        // Fetch back the new row with joins for display
        $row = sqlQuery(
            "SELECT ia.*, ic.name AS ins_name
               FROM insauth ia
               LEFT JOIN insurance_data id2 ON id2.id = ia.policy_id
               LEFT JOIN insurance_companies ic ON ic.id = id2.provider
              WHERE ia.id=?", [$new_id]
        );
        echo json_encode(['success'=>true,'id'=>$new_id,'row'=>$row]);
    } else {
        $rid = (int)($_POST['id'] ?? 0);
        if (!$rid) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
        $cpt_code  = strtoupper(trim($_POST['cpt_code'] ?? ''));
        sqlStatement(
            "UPDATE insauth SET
                policy_id=?,auth_number=?,prc_mst_id=?,visits_approved=?,visits_used=?,
                dollars_approved=?,dollars_used=?,notes=?,username=?,tran_date=NOW(),
                start_date=?,expiry_date=?,auth_type=?,cpt_code=?
             WHERE id=? AND (pid=? OR pid=0)",
            [$policy_id,$auth_number,$prc,$visits,$visits_used,
             $dollars,$dollars_used,$notes,$user,$start,$expiry,$auth_type,$cpt_code,$rid,$pid]
        );
        $row = sqlQuery(
            "SELECT ia.*, ic.name AS ins_name
               FROM insauth ia
               LEFT JOIN insurance_data id2 ON id2.id = ia.policy_id
               LEFT JOIN insurance_companies ic ON ic.id = id2.provider
              WHERE ia.id=?", [$rid]
        );
        echo json_encode(['success'=>true,'id'=>$rid,'row'=>$row]);
    }
    exit;
}

// ── Page data ─────────────────────────────────────────────────────────────────
$patient = $pid ? sqlQuery(
    "SELECT pid,lname,fname,mname,DOB FROM patient_data WHERE pid=?", [$pid]
) : null;

// Insurance policies: policy_id in insauth = id in insurance_data
$policies = [];
if ($pid) {
    $pr = sqlStatement(
        "SELECT id.id, id.type, ic.name
           FROM insurance_data id
           LEFT JOIN insurance_companies ic ON ic.id = id.provider
          WHERE id.pid=?
          ORDER BY FIELD(id.type,'primary','secondary','tertiary'), ic.name",
        [$pid]
    );
    while ($r = sqlFetchArray($pr)) $policies[] = $r;
}

$auths = [];
if ($pid) {
    $ar = sqlStatement(
        "SELECT ia.*,
                ic.name AS ins_name
           FROM insauth ia
           LEFT JOIN insurance_data id2 ON id2.id = ia.policy_id
           LEFT JOIN insurance_companies ic ON ic.id = id2.provider
          WHERE ia.pid = ?
          ORDER BY ia.start_date DESC, ia.id DESC",
        [$pid]
    );
    while ($r = sqlFetchArray($ar)) $auths[] = $r;

    // Backfill pid=0 records that belong to this patient via policy_id
    foreach ($auths as $a) {
        if ((int)$a['pid'] === 0 && !empty($a['policy_id'])) {
            sqlStatement(
                "UPDATE insauth SET pid = ? WHERE id = ? AND pid = 0",
                [$pid, (int)$a['id']]
            );
        }
    }

    // Calculate live visits + dollars per auth — simple flat queries, no subqueries
    foreach ($auths as &$a) {
        $start  = (!empty($a['start_date'])  && $a['start_date']  !== '0000-00-00') ? $a['start_date']  : null;
        $expiry = (!empty($a['expiry_date']) && $a['expiry_date'] !== '0000-00-00') ? $a['expiry_date'] : null;
        $cpt    = !empty($a['cpt_code']) ? trim($a['cpt_code']) : null;

        // Visits used: get all encounters for this patient in date window, then filter by CPT in PHP
        $vp = [$pid];
        $vw = '';
        if ($start)  { $vw .= ' AND DATE(fe.date) >= ?'; $vp[] = $start; }
        if ($expiry) { $vw .= ' AND DATE(fe.date) <= ?'; $vp[] = $expiry; }

        $enc_res = sqlStatement(
            "SELECT DISTINCT fe.encounter, fe.date
               FROM form_encounter fe
              WHERE fe.pid = ? $vw
              ORDER BY fe.encounter",
            $vp
        );
        $enc_rows = [];
        while ($er = sqlFetchArray($enc_res)) $enc_rows[] = $er;

        if ($cpt && count($enc_rows) > 0) {
            // Filter encounters that have this CPT code billed
            $cpt_res = sqlStatement(
                "SELECT DISTINCT b.encounter
                   FROM billing b
                   JOIN form_encounter fe
                     ON fe.pid = b.pid
                    AND fe.encounter = b.encounter
                  WHERE b.pid = ?
                    AND b.code = ?
                    AND b.activity = 1
                    $vw",
                array_merge([$pid, $cpt], array_slice($vp, 1))
            );
            $cpt_encs = [];
            while ($cr = sqlFetchArray($cpt_res)) $cpt_encs[] = $cr['encounter'];
            $a['visits_used_live'] = count($cpt_encs);
            $enc_filter = $cpt_encs;
        } else {
            $a['visits_used_live'] = count($enc_rows);
            $enc_filter = array_column($enc_rows, 'encounter');
        }

        // Dollars used from ar_activity for the filtered encounters
        if (count($enc_filter) > 0) {
            $placeholders2 = implode(',', array_fill(0, count($enc_filter), '?'));
            $dol_params = array_merge([$pid], $enc_filter);
            $dr = sqlQuery(
                "SELECT COALESCE(SUM(pay_amount), 0) AS total
                   FROM ar_activity
                  WHERE pid = ? AND deleted IS NULL
                    AND encounter IN ($placeholders2)",
                $dol_params
            );
            $a['dollars_used_live'] = (float)($dr['total'] ?? 0);
        } else {
            $a['dollars_used_live'] = 0.0;
        }
    }
    unset($a);
}

$csrf = CsrfUtils::collectCsrfToken();
$pol_json  = json_encode($policies, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT);
$auth_json = json_encode($auths,    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html>
<head>
<?php Header::setupHeader(); ?>
<title><?php echo xlt("Insurance Authorization Manager"); ?></title>
<style>
div#container_div{padding:0!important}
body{background:#f3f4f6;font-family:'Inter',-apple-system,sans-serif;font-size:13px;color:#111827}
/* Patient banner */
.pt-bar{background:#fff;border-bottom:1px solid #e5e7eb;padding:9px 20px;display:flex;align-items:center;gap:12px}
.pt-av{width:34px;height:34px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#6b4fd8,#4f46e5);color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center}
.pt-name{font-weight:700;font-size:14px}.pt-sub{font-size:11px;color:#6b7280;margin-top:1px}
.pt-pill{margin-left:auto;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:20px;font-size:10px;font-weight:600;padding:2px 10px;font-family:monospace}
/* Toolbar */
.toolbar{padding:10px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}
.toolbar-title{font-size:15px;font-weight:700;color:#111827;display:flex;align-items:center;gap:8px}
.toolbar-title svg{width:18px;height:18px;color:#6b4fd8}
.btn-add{background:#6b4fd8;color:#fff;border:none;border-radius:7px;padding:0 16px;height:34px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background .15s;font-family:inherit}
.btn-add:hover{background:#5b3fc8}.btn-add svg{width:14px;height:14px}
/* Card */
.card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin:0 20px 20px;overflow:hidden}
.card-head{background:#f9fafb;border-bottom:1px solid #e5e7eb;padding:9px 14px;display:flex;align-items:center;justify-content:space-between}
.card-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#374151;display:flex;align-items:center;gap:6px}
.cnt-badge{background:#ede9fe;color:#6b4fd8;border-radius:20px;font-size:10px;font-weight:700;padding:2px 9px;font-family:monospace}
/* Table — no horizontal scroll */
table{width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed}
thead tr{background:#f9fafb}
th{padding:7px 8px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
td{padding:7px 8px;border-bottom:1px solid #f3f4f6;vertical-align:middle;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
tbody tr:last-child td{border-bottom:none}
tbody tr.data-row:hover td{background:#faf5ff;cursor:pointer}
.empty-row td{text-align:center;padding:28px;color:#9ca3af;font-style:italic;white-space:normal}
/* Row buttons */
.rbtn{background:none;border:1px solid #e5e7eb;border-radius:5px;padding:3px 7px;cursor:pointer;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:3px;transition:all .15s;font-family:inherit}
.rbtn svg{width:11px;height:11px;flex-shrink:0}
.rbtn-edit{color:#6b4fd8}.rbtn-edit:hover{background:#ede9fe;border-color:#6b4fd8}
.rbtn-del{color:#dc2626}.rbtn-del:hover{background:#fee2e2;border-color:#dc2626}
.act-cell{white-space:nowrap;display:flex;gap:3px;align-items:center}
/* Expiry badge */
.xbadge{display:inline-flex;align-items:center;border-radius:20px;font-size:10px;font-weight:600;padding:2px 7px;white-space:nowrap}
.xbadge.ok{background:#dcfce7;color:#166534}.xbadge.warn{background:#fef9c3;color:#854d0e}
.xbadge.exp{background:#fee2e2;color:#991b1b}.xbadge.none{background:#f3f4f6;color:#9ca3af}
/* ── Modal ───────────────────────────────────────────────────────── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:10px;width:92%;max-width:560px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:mIn .18s ease}
@keyframes mIn{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #e5e7eb;font-weight:700;font-size:14px}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:#9ca3af;line-height:1;padding:2px 6px}
.modal-close:hover{color:#374151}
.modal-body{padding:18px}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid #f3f4f6}
.modal-err{color:#dc2626;font-size:12px;margin-top:8px;min-height:16px}
/* Form grid inside modal */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field{display:flex;flex-direction:column;gap:4px}
.field-full{grid-column:span 2}
.field label{font-size:11px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.finput{padding:7px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;outline:none;font-family:inherit;background:#fff;transition:border-color .15s}
.finput:focus{border-color:#6b4fd8;box-shadow:0 0 0 2px rgba(107,79,216,.1)}
.finput.err{border-color:#dc2626}
/* Modal buttons */
.btn-primary{background:#6b4fd8;color:#fff;border:none;border-radius:6px;padding:0 18px;height:34px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;transition:background .15s;font-family:inherit}
.btn-primary:hover{background:#5b3fc8}.btn-primary:disabled{opacity:.4;cursor:not-allowed}
.btn-ghost{background:transparent;color:#6b7280;border:1px solid #d1d5db;border-radius:6px;padding:0 14px;height:34px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s}
.btn-ghost:hover{border-color:#6b4fd8;color:#6b4fd8}
/* Toast */
#pa-toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%) translateY(8px);background:#1f2937;color:#fff;border-radius:8px;padding:9px 16px;font-size:12px;font-weight:500;opacity:0;pointer-events:none;transition:all .25s;z-index:9999;max-width:340px;box-shadow:0 4px 20px rgba(0,0,0,.3)}
#pa-toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
#pa-toast.ok{background:#14532d}#pa-toast.err{background:#7f1d1d}
</style>
</head>
<body>
<div id="container_div" class="container">

<!-- Patient banner -->
<?php if ($patient): ?>
<div class="pt-bar">
    <div class="pt-av">
        <?php echo strtoupper(
            substr($patient['fname']??'',0,1).substr($patient['lname']??'',0,1)
        ); ?>
    </div>
    <div>
        <div class="pt-name">
            <?php echo text(($patient['lname']??'').', '.($patient['fname']??'').
                ($patient['mname'] ? ' '.text($patient['mname']) : '')); ?>
        </div>
        <div class="pt-sub">
            PID: <?php echo (int)$patient['pid']; ?>
            <?php if ($patient['DOB']): ?>
             &nbsp;·&nbsp; DOB: <?php echo text($patient['DOB']); ?>
            <?php endif; ?>
        </div>
    </div>
    <span class="pt-pill">insauth</span>
</div>
<?php endif; ?>

<!-- Toolbar -->
<div class="toolbar">
    <div class="toolbar-title">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
        </svg>
        <?php echo xlt("Insurance Authorization Manager"); ?>
    </div>
    <button class="btn-add" onclick="addRow()">
       
        <?php echo xlt("Add Authorization"); ?>
    </button>
</div>

<!-- Table card -->
<div class="card">
    <div class="card-head">
        <div class="card-title">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13">
                <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2
                         M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            <?php echo xlt("Authorizations"); ?>
        </div>
        <span class="cnt-badge" id="auth-count"><?php echo count($auths); ?></span>
    </div>
    <div class="tbl-wrap">
        <table id="auth-table">
            <thead>
                <tr>
                    <th style="width:18%"><?php echo xlt("Auth Number"); ?></th>
                    <th style="width:16%"><?php echo xlt("Insurance"); ?></th>
                    <th style="width:7%"><?php echo xlt("CPT"); ?></th>
                    <th style="width:10%"><?php echo xlt("Expiry"); ?></th>
                    <th style="width:6%"><?php echo xlt("Vis"); ?></th>
                    <th style="width:6%"><?php echo xlt("Used"); ?></th>
                    <th style="width:9%"><?php echo xlt("$ Auth"); ?></th>
                    <th style="width:9%"><?php echo xlt("$ Used"); ?></th>
                    <th style="width:9%"><?php echo xlt("$ Left"); ?></th>
                    <th style="width:10%"></th>
                </tr>
            </thead>
            <tbody id="auth-tbody">
                <?php if (empty($auths)): ?>
                <tr class="empty-row" id="empty-msg">
                    <td colspan="10"><?php echo xlt("No authorizations on file. Click 'Add Authorization' to create one."); ?></td>
                </tr>
                <?php else: ?>
                <?php foreach ($auths as $a): ?>
                <?php
                    $exp_cls = 'none'; $exp_lbl = '—';
                    if (!empty($a['expiry_date']) && $a['expiry_date'] !== '0000-00-00') {
                        $exp_lbl = $a['expiry_date'];
                        $dl = (strtotime($a['expiry_date']) - time()) / 86400;
                        $exp_cls = $dl < 0 ? 'exp' : ($dl < 30 ? 'warn' : 'ok');
                    }
                    $vis_appr  = (int)($a['visits_approved'] ?? 0);
                    $vis_used  = (int)max($a['visits_used_live'] ?? 0, $a['visits_used'] ?? 0);
                    $dol_appr  = (float)($a['dollars_approved'] ?? 0);
                    $dol_used  = (float)max($a['dollars_used_live'] ?? 0, $a['dollars_used'] ?? 0);
                    $dol_left  = $dol_appr > 0 ? $dol_appr - $dol_used : null;
                    if ($dol_left === null) {
                        $dol_left_html = '<span style="color:#9ca3af">—</span>';
                    } elseif ($dol_left <= 0) {
                        $dol_left_html = '<span class="xbadge exp">$'.number_format(abs($dol_left),2).'</span>';
                    } elseif ($dol_left < 100) {
                        $dol_left_html = '<span class="xbadge warn">$'.number_format($dol_left,2).'</span>';
                    } else {
                        $dol_left_html = '<span class="xbadge ok">$'.number_format($dol_left,2).'</span>';
                    }
                ?>
                <tr class="data-row" id="row-<?php echo (int)$a['id']; ?>" onclick="editRow(<?php echo (int)$a['id']; ?>)" title="<?php echo xla('Click to edit'); ?>">
                    <td style="font-family:monospace;font-weight:600;color:#6b4fd8"><?php echo text($a['auth_number'] ?: '—'); ?></td>
                    <td style="font-size:11px;color:#374151"><?php echo $a['ins_name'] ? text($a['ins_name']) : '<span style="color:#9ca3af">—</span>'; ?></td>
                    <td style="font-family:monospace;font-size:11px"><?php echo $a['cpt_code'] ? text($a['cpt_code']) : '<span style="color:#9ca3af">—</span>'; ?></td>
                    <td><span class="xbadge <?php echo $exp_cls; ?>"><?php echo text($exp_lbl); ?></span></td>
                    <td><?php echo $vis_appr ?: '<span style="color:#9ca3af">—</span>'; ?></td>
                    <td><?php echo $vis_used > 0 ? $vis_used : '<span style="color:#9ca3af">0</span>'; ?></td>
                    <td><?php echo $dol_appr > 0 ? '$'.number_format($dol_appr,2) : '<span style="color:#9ca3af">—</span>'; ?></td>
                    <td><?php echo $dol_used > 0 ? '$'.number_format($dol_used,2) : '<span style="color:#9ca3af">0</span>'; ?></td>
                    <td><?php echo $dol_left_html; ?></td>
                    <td onclick="event.stopPropagation()">
                        <div class="act-cell">
                            <button class="rbtn rbtn-edit" onclick="editRow(<?php echo (int)$a['id']; ?>)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                <?php echo xlt("Edit"); ?>
                            </button>
                            <button class="rbtn rbtn-del" onclick="delRow(<?php echo (int)$a['id']; ?>)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</div><!-- /container -->

<!-- Auth Modal -->
<div class="modal-overlay" id="auth-modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head">
      <span id="modal-title"><?php echo xlt("Add Authorization"); ?></span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field field-full">
          <label><?php echo xlt("Auth Number"); ?> *</label>
          <input id="f_auth" class="finput" placeholder="e.g. VA0047512115" autocomplete="off">
        </div>
        <div class="field field-full">
          <label><?php echo xlt("Insurance Policy"); ?></label>
          <select id="f_policy" class="finput">
            <option value="">— <?php echo xlt("Select policy"); ?> —</option>
            <?php foreach ($policies as $pol):
                $tmap = ['primary'=>'Primary','secondary'=>'Secondary','tertiary'=>'Tertiary'];
                $tlbl = $tmap[$pol['type']??''] ?? $pol['type'];
            ?>
            <option value="<?php echo (int)$pol['id']; ?>"><?php echo text($tlbl.': '.($pol['name']??'')); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label><?php echo xlt("Auth Type"); ?></label>
          <select id="f_type" class="finput">
            <option value="">— <?php echo xlt("Select"); ?> —</option>
            <option value="R"><?php echo xlt("R — Referral No"); ?></option>
            <option value="A"><?php echo xlt("A — Authorization No"); ?></option>
          </select>
        </div>
        <div class="field">
          <label><?php echo xlt("CPT Code"); ?></label>
          <input id="f_cpt" class="finput" placeholder="e.g. 98941" maxlength="15" oninput="this.value=this.value.toUpperCase()" autocomplete="off">
        </div>
        <div class="field">
          <label><?php echo xlt("Start Date"); ?></label>
          <input id="f_start" class="finput" type="date">
        </div>
        <div class="field">
          <label><?php echo xlt("Expiry Date"); ?></label>
          <input id="f_expiry" class="finput" type="date">
        </div>
        <div class="field">
          <label><?php echo xlt("Visits Approved"); ?></label>
          <input id="f_visits" class="finput" type="number" min="0" placeholder="0">
        </div>
        <div class="field">
          <label><?php echo xlt("Visits Used"); ?></label>
          <input id="f_visits_used" class="finput" type="number" min="0" placeholder="0">
        </div>
        <div class="field">
          <label><?php echo xlt("Dollars Approved"); ?></label>
          <input id="f_dollars" class="finput" type="number" min="0" step="0.01" placeholder="0.00">
        </div>
        <div class="field">
          <label><?php echo xlt("Dollars Used"); ?></label>
          <input id="f_dollars_used" class="finput" type="number" min="0" step="0.01" placeholder="0.00">
        </div>
        <div class="field">
          <label><?php echo xlt("Proc ID"); ?></label>
          <input id="f_prc" class="finput" type="number" min="0" placeholder="0">
        </div>
        <div class="field">
          <label><?php echo xlt("Notes"); ?></label>
          <input id="f_notes" class="finput" placeholder="<?php echo xla('Optional notes'); ?>">
        </div>
      </div>
      <div class="modal-err" id="modal-err"></div>
    </div>
    <div class="modal-foot">
      <button class="btn-ghost" onclick="closeModal()"><?php echo xlt("Cancel"); ?></button>
      <button class="btn-primary" id="btn-modal-save" onclick="saveModal()"><?php echo xlt("Save Authorization"); ?></button>
    </div>
  </div>
</div>
<input type="hidden" id="edit-id">
<div id="pa-toast"></div>

<script>
var CSRF     = <?php echo json_encode($csrf); ?>;
var _editId  = null;

function toast(msg, type) {
    var el = document.getElementById('pa-toast');
    el.textContent = msg; el.className = 'show ' + (type||'ok');
    clearTimeout(el._t); el._t = setTimeout(function(){ el.className=''; }, 3000);
}
function setField(id, val) { var el = document.getElementById(id); if (el) el.value = val; }
function clearFields() {
    ['f_auth','f_cpt','f_start','f_expiry','f_notes','f_prc'].forEach(function(id){ setField(id,''); });
    setField('f_policy',''); setField('f_type','');
    setField('f_visits',''); setField('f_visits_used','0');
    setField('f_dollars',''); setField('f_dollars_used','0');
}
function closeModal() { document.getElementById('auth-modal').classList.remove('open'); _editId = null; }

function addRow() {
    _editId = null;
    document.getElementById('edit-id').value = '';
    document.getElementById('modal-title').textContent = '<?php echo xlt("Add Authorization"); ?>';
    clearFields();
    document.getElementById('modal-err').textContent = '';
    document.getElementById('auth-modal').classList.add('open');
    setTimeout(function(){ document.getElementById('f_auth').focus(); }, 80);
}

function editRow(id) {
    top.restoreSession && top.restoreSession();
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '?ajax_load=1&id=' + id, true);
    xhr.onreadystatechange = function(){
        if (xhr.readyState !== 4 || xhr.status !== 200) return;
        var d; try { d = JSON.parse(xhr.responseText); } catch(e){ return; }
        _editId = id;
        document.getElementById('edit-id').value = id;
        document.getElementById('modal-title').textContent = '<?php echo xlt("Edit Authorization"); ?>';
        setField('f_auth',         d.auth_number  || '');
        setField('f_policy',       d.policy_id    || '');
        setField('f_type',         d.auth_type    || '');
        setField('f_cpt',          d.cpt_code     || '');
        setField('f_start',        (d.start_date  && d.start_date  !== '0000-00-00') ? d.start_date  : '');
        setField('f_expiry',       (d.expiry_date && d.expiry_date !== '0000-00-00') ? d.expiry_date : '');
        setField('f_visits',       d.visits_approved  || '');
        setField('f_visits_used',  d.visits_used      || '0');
        setField('f_dollars',      d.dollars_approved || '');
        setField('f_dollars_used', d.dollars_used     || '0');
        setField('f_prc',          d.prc_mst_id   || '');
        setField('f_notes',        d.notes        || '');
        document.getElementById('modal-err').textContent = '';
        document.getElementById('auth-modal').classList.add('open');
        setTimeout(function(){ document.getElementById('f_auth').focus(); }, 80);
    };
    xhr.send();
}

function saveModal() {
    var authNo = document.getElementById('f_auth').value.trim();
    var errEl  = document.getElementById('modal-err');
    errEl.textContent = '';
    if (!authNo) {
        document.getElementById('f_auth').classList.add('err');
        errEl.textContent = '<?php echo xlt("Authorization number is required."); ?>';
        return;
    }
    document.getElementById('f_auth').classList.remove('err');
    var btn = document.getElementById('btn-modal-save');
    btn.disabled = true;
    var fd = new FormData();
    fd.append('csrf_token_form',  CSRF);
    fd.append('action',           _editId ? 'update' : 'insert');
    if (_editId) fd.append('id', _editId);
    fd.append('auth_number',      authNo);
    fd.append('policy_id',        document.getElementById('f_policy').value);
    fd.append('auth_type',        document.getElementById('f_type').value);
    fd.append('cpt_code',         document.getElementById('f_cpt').value);
    fd.append('start_date',       document.getElementById('f_start').value);
    fd.append('expiry_date',      document.getElementById('f_expiry').value);
    fd.append('visits_approved',  document.getElementById('f_visits').value || '0');
    fd.append('visits_used',      document.getElementById('f_visits_used').value || '0');
    fd.append('dollars_approved', document.getElementById('f_dollars').value || '0');
    fd.append('dollars_used',     document.getElementById('f_dollars_used').value || '0');
    fd.append('prc_mst_id',       document.getElementById('f_prc').value || '0');
    fd.append('notes',            document.getElementById('f_notes').value);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.onreadystatechange = function(){
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        var resp; try {
            var js = xhr.responseText.indexOf('{');
            resp = JSON.parse(js >= 0 ? xhr.responseText.slice(js) : xhr.responseText);
        } catch(e){ errEl.textContent = 'Server error'; return; }
        if (!resp.success) { errEl.textContent = resp.error || 'Error'; return; }
        closeModal();
        var newHtml = buildDisplayRow(resp.id, resp.row);
        var tbody = document.getElementById('auth-tbody');
        var existing = document.getElementById('row-' + resp.id);
        var tmp = document.createElement('tbody');
        tmp.innerHTML = newHtml;
        if (existing) {
            tbody.insertBefore(tmp.firstChild, existing);
            existing.remove();
        } else {
            var em = document.getElementById('empty-msg'); if (em) em.remove();
            tbody.insertBefore(tmp.firstChild, tbody.firstChild);
            updateCount(1);
        }
        toast(_editId ? '<?php echo xlt("Authorization updated."); ?>' : '<?php echo xlt("Authorization saved."); ?>');
    };
    xhr.send(fd);
}

function buildDisplayRow(id, r) {
    r = r || {};
    var expCls = 'none', expLbl = '—';
    if (r.expiry_date && r.expiry_date !== '0000-00-00') {
        expLbl = r.expiry_date;
        var dl = (new Date(r.expiry_date) - new Date()) / 86400000;
        expCls = dl < 0 ? 'exp' : (dl < 30 ? 'warn' : 'ok');
    }
    var insName  = r.ins_name ? esc(r.ins_name) : '<span style="color:#9ca3af">—</span>';
    var cptDisp  = r.cpt_code ? '<span style="font-family:monospace;font-size:11px">'+esc(r.cpt_code)+'</span>' : '<span style="color:#9ca3af">—</span>';
    var visDisp  = r.visits_approved ? esc(String(r.visits_approved)) : '<span style="color:#9ca3af">—</span>';
    var usedDisp = (parseInt(r.visits_used)||0) > 0 ? esc(String(r.visits_used)) : '<span style="color:#9ca3af">0</span>';
    var dolDisp  = parseFloat(r.dollars_approved||0) > 0 ? '$'+parseFloat(r.dollars_approved).toFixed(2) : '<span style="color:#9ca3af">—</span>';
    return '<tr class="data-row" id="row-'+id+'" onclick="editRow('+id+')" title="Click to edit">'
        + '<td style="font-family:monospace;font-weight:600;color:#6b4fd8">'+esc(r.auth_number||'—')+'</td>'
        + '<td style="font-size:11px;color:#374151">'+insName+'</td>'
        + '<td>'+cptDisp+'</td>'
        + '<td><span class="xbadge '+expCls+'">'+expLbl+'</span></td>'
        + '<td>'+visDisp+'</td>'
        + '<td>'+usedDisp+'</td>'
        + '<td>'+dolDisp+'</td>'
        + '<td onclick="event.stopPropagation()"><div class="act-cell">'
        +   '<button class="rbtn rbtn-edit" onclick="editRow('+id+')">'
        +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>'
        +     '<?php echo xlt("Edit"); ?>'
        +   '</button>'
        +   '<button class="rbtn rbtn-del" onclick="delRow('+id+')">'
        +     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/></svg>'
        +   '</button>'
        + '</div></td>'
        + '</tr>';
}

function delRow(id) {
    if (!confirm('<?php echo xlt("Delete this authorization?"); ?>')) return;
    top.restoreSession && top.restoreSession();
    var fd = new FormData();
    fd.append('csrf_token_form', CSRF);
    fd.append('action', 'delete');
    fd.append('id', id);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.onreadystatechange = function(){
        if (xhr.readyState !== 4 || xhr.status !== 200) return;
        var resp; try { resp = JSON.parse(xhr.responseText); } catch(e){ return; }
        if (resp.success) {
            var row = document.getElementById('row-' + id);
            if (row) row.remove();
            updateCount(-1); checkEmpty();
            toast('<?php echo xlt("Authorization deleted."); ?>');
        } else { toast(resp.error || 'Error', 'err'); }
    };
    xhr.send(fd);
}

function esc(s){if(!s&&s!==0)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function updateCount(d){var el=document.getElementById('auth-count');el.textContent=Math.max(0,(parseInt(el.textContent)||0)+d);}
function checkEmpty(){var tbody=document.getElementById('auth-tbody');if(!tbody.querySelector('tr.data-row')&&!document.getElementById('empty-msg')){var tr=document.createElement('tr');tr.id='empty-msg';tr.className='empty-row';tr.innerHTML='<td colspan="8"><?php echo xlt("No authorizations on file. Click 'Add Authorization' to create one."); ?></td>';tbody.appendChild(tr);}}
document.addEventListener('keydown',function(e){if(e.key==='Escape')closeModal();});
</script>

</body>
</html>
