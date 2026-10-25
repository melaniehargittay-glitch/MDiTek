<?php
/**
 * insurance_companies_manager.php — Insurance & MediGap Companies
 * Tables: insurance_companies + addresses
 */
ob_start();
require_once(dirname(__FILE__) . "/../globals.php");
require_once "$srcdir/user.inc.php";
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (isset($_GET['ajax'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $q='%'.trim($_GET['q']??'').'%';
    $res = sqlStatement(
      "SELECT
          ic.id,
          ic.name,
          ic.cms_id,
          ic.ins_type_code,
          a.line1,
          a.city,
          a.state,
          a.zip
       FROM insurance_companies ic
       LEFT JOIN addresses a
          ON a.foreign_id = ic.id
       WHERE ic.name LIKE ?
          OR ic.cms_id LIKE ?
          OR a.city LIKE ?
       ORDER BY ic.name
       LIMIT 100",
      [$q, $q, $q]
  );
    $rows=[];while($r=sqlFetchArray($res))$rows[]=$r;
    echo json_encode($rows);exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    if(!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form']??'')){echo json_encode(['success'=>false,'error'=>'CSRF']);exit;}
    $action=$_POST['action']??'insert';
    $name=trim($_POST['name']??'');
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
        sqlStatement("DELETE FROM addresses WHERE foreign_id=?", [$id]);
        sqlStatement("DELETE FROM insurance_companies WHERE id=?", [$id]);
        echo json_encode(['success'=>true]); exit;
    }
    if(!$name){echo json_encode(['success'=>false,'error'=>'Company name is required.']);exit;}
    $cms_id=trim($_POST['cms_id']??'');
    $ins_type=trim($_POST['ins_type_code']??'');
    $line1=trim($_POST['line1']??'');$city=trim($_POST['city']??'');
    $state=trim($_POST['state']??'');$zip=trim($_POST['zip']??'');
    $user=$_SESSION['authUser']??'system';
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
        sqlStatement(
            "UPDATE insurance_companies SET name=?,cms_id=?,ins_type_code=? WHERE id=?",
            [$name,$cms_id,$ins_type,$id]
        );
        $addr_exists = sqlQuery("SELECT id FROM addresses WHERE foreign_id=?", [$id]);
        if ($addr_exists) {
            sqlStatement(
                "UPDATE addresses SET line1=?,city=?,state=?,zip=? WHERE foreign_id=?",
                [$line1,$city,$state,$zip,$id]
            );
        } else {
            sqlStatement(
                "INSERT INTO addresses (foreign_id,line1,city,state,zip) VALUES (?,?,?,?,?)",
                [$id,$line1,$city,$state,$zip]
            );
        }
    } else {
        // Guard against duplicate company names
        $exists = sqlQuery("SELECT id FROM insurance_companies WHERE name=? LIMIT 1", [$name]);
        if ($exists) {
            echo json_encode(['success'=>false,'error'=>xl('A company with this name already exists.')]);
            exit;
        }
        sqlStatement(
            "INSERT INTO insurance_companies (name,cms_id,ins_type_code) VALUES (?,?,?)",
            [$name,$cms_id,$ins_type]
        );
        $id = (int)(sqlQuery("SELECT LAST_INSERT_ID() AS id")['id']);
        // Hard stop — never allow foreign_id=0 in addresses
        if ($id <= 0) {
            echo json_encode(['success'=>false,'error'=>xl('Insert failed — could not retrieve new record ID.')]);
            exit;
        }
        sqlStatement(
            "INSERT INTO addresses (foreign_id,line1,city,state,zip) VALUES (?,?,?,?,?)",
            [$id,$line1,$city,$state,$zip]
        );
    }
    $row = sqlQuery(
        "SELECT ic.*, a.line1, a.city, a.state, a.zip
           FROM insurance_companies ic
           LEFT JOIN addresses a ON a.foreign_id = ic.id
          WHERE ic.id = ?",
        [$id]
    );
    echo json_encode(['success'=>true,'row'=>$row ?: []]);
    exit;
}

// ── Pre-load all companies into page for instant JS lookup ───────────────────
$all_companies = [];
$all_res = sqlStatement(
    "SELECT ic.id, ic.name, ic.cms_id, ic.ins_type_code,
            a.line1, a.city, a.state, a.zip
       FROM insurance_companies ic
       LEFT JOIN addresses a ON a.foreign_id = ic.id
      ORDER BY ic.name"
);
while ($row = sqlFetchArray($all_res)) {
    $all_companies[(int)$row['id']] = $row;
}

// ── Load insurance types from insurance_type_codes ────────────────────────────
$ins_types = [];
$itr = sqlStatement("SELECT id, type, claim_type FROM insurance_type_codes ORDER BY id");
while ($row = sqlFetchArray($itr)) {
    $ins_types[] = $row;
}
?>
<!DOCTYPE html><html><head>
<?php Header::setupHeader();?>
<title><?php echo xlt("Insurance Companies");?></title>
<?php include 'admin_style.php.inc';?>
<style>
/* Insurance companies modal overrides */
#modal.modal-overlay {
    position: fixed !important;
    inset: 0 !important;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    background: rgba(0,0,0,.45);
}
#modal.modal-overlay.open { display: flex !important; }
#modal .modal-box {
    width: 520px;
    max-width: calc(100vw - 32px);
    max-height: calc(100vh - 48px);
    overflow-y: auto;
    border-radius: 12px;
    background: #fff;
    box-shadow: 0 12px 48px rgba(0,0,0,.22);
    margin: auto;
}
#modal .modal-head {
    padding: 16px 20px;
    border-bottom: 1px solid #f0ebff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 700;
    font-size: 14px;
    color: #3b1f7a;
}
#modal .modal-body {
    padding: 20px 24px !important;
}
#modal .modal-foot {
    padding: 14px 20px;
    border-top: 1px solid #f0ebff;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
#modal .form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px 16px;
}
#modal .form-grid .field-full { grid-column: 1 / -1; }
#modal .field label {
    display: block;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #7c3aed;
    margin-bottom: 5px;
}
#modal .finput {
    width: 100%;
    border: 1.5px solid #e4ddf5;
    border-radius: 7px;
    padding: 8px 10px;
    font-size: 13px;
    font-family: inherit;
    outline: none;
    transition: border-color .12s;
    box-sizing: border-box;
}
#modal .finput:focus { border-color: #7c3aed; }
#modal .modal-err { color: #dc2626; font-size: 12px; margin-top: 8px; min-height: 16px; }
.tbl-btn-del { color: #dc2626 !important; border-color: #fecaca !important; background: #fff5f5 !important; }
.tbl-btn-del:hover { background: #fee2e2 !important; border-color: #fca5a5 !important; }
</style>
</head><body>
<script>
var IC_DATA   = <?php echo json_encode($all_companies, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
var INS_TYPES_DB = <?php echo json_encode($ins_types, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
</script>
<div id="container_div" class="container adm-wrap">
<div class="adm-toolbar">
  <div class="adm-title">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2m-2 0h-2M5 21H3m2 0h2M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
    <?php echo xlt("Insurance & MediGap Companies");?> <span class="adm-sub">insurance_companies</span>
  </div>
  <button class="btn-primary" onclick="openAddModal()">+ <?php echo xlt("Add Company");?></button>
</div>
<div class="adm-search-bar">
  <div class="search-wrap">
    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <input type="text" id="search-input" class="search-input" placeholder="<?php echo xla('Search by name, CMS ID, or city...');?>" oninput="debSearch(this.value)" autofocus>
    <div class="search-spinner" id="spin"></div>
  </div>
</div>
<div class="adm-card">
  <div class="adm-card-head"><span id="result-count" class="adm-count">—</span></div>
  <div class="tbl-wrap"><table><thead><tr>
    <th><?php echo xlt("Company Name");?></th>
    <th style="width:100px"><?php echo xlt("CMS ID");?></th>
    <th style="width:80px"><?php echo xlt("Type");?></th>
    <th><?php echo xlt("Address");?></th>
    <th style="width:90px"></th>
  </tr></thead>
  <tbody id="result-tbody"><tr class="empty-row"><td colspan="5"><?php echo xlt("Type to search insurance companies.");?></td></tr></tbody>
  </table></div>
</div>

<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head"><span id="modal-title"><?php echo xlt("Add Insurance Company");?></span><button class="modal-close" onclick="closeModal()">✕</button></div>
    <div class="modal-body" style="padding:20px 24px;">
      <div class="form-grid" style="gap:12px 16px;">
        <div class="field field-full"><label><?php echo xlt("Company Name");?> *</label><input id="f_name" class="finput"></div>
        <div class="field"><label><?php echo xlt("CMS ID");?></label><input id="f_cms" class="finput" maxlength="20"></div>
        <div class="field"><label><?php echo xlt("Insurance Type");?></label>
          <select id="f_type" class="finput">
            <option value="">— <?php echo xlt("Select");?> —</option>
            <?php foreach ($ins_types as $it): ?>
            <option value="<?php echo (int)$it['id']; ?>"><?php echo text($it['type']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field field-full"><label><?php echo xlt("Street Address");?></label><input id="f_line1" class="finput"></div>
        <div class="field"><label><?php echo xlt("City");?></label><input id="f_city" class="finput"></div>
        <div class="field"><label><?php echo xlt("State");?></label><input id="f_state" class="finput" maxlength="2" oninput="this.value=this.value.toUpperCase()"></div>
        <div class="field"><label><?php echo xlt("ZIP");?></label><input id="f_zip" class="finput" maxlength="10"></div>
      </div>
      <div class="modal-err" id="modal-err"></div>
    </div>
    <div class="modal-foot">
      <button class="btn-ghost" onclick="closeModal()"><?php echo xlt("Cancel");?></button>
      <button class="btn-primary" id="modal-save" onclick="saveIns()"><?php echo xlt("Save");?></button>
    </div>
  </div>
</div>
<input type="hidden" id="edit-id">
<div id="toast"></div>
<script>
var CSRF = '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>';
// Build INS_TYPES lookup from DB data
var INS_TYPES = {};
for (var i = 0; i < INS_TYPES_DB.length; i++) {
    INS_TYPES[INS_TYPES_DB[i].id] = INS_TYPES_DB[i].type;
}
var _action = 'insert';
var _timer  = null;

// ── Search ────────────────────────────────────────────────────────────────────
function debSearch(v) { clearTimeout(_timer); _timer = setTimeout(function(){ doSearch(v); }, 220); }

function doSearch(v) {
    document.getElementById('spin').style.display = 'block';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', '?ajax=1&q=' + encodeURIComponent(v), true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        document.getElementById('spin').style.display = 'none';
        var rows; try { rows = JSON.parse(xhr.responseText); } catch(e) { return; }
        renderRows(rows);
    };
    xhr.send();
}

function renderRows(rows) {
    var tb = document.getElementById('result-tbody');
    document.getElementById('result-count').textContent = rows.length + ' <?php echo xlt("result(s)"); ?>';
    if (!rows.length) {
        tb.innerHTML = '<tr class="empty-row"><td colspan="5"><?php echo xlt("No companies found."); ?></td></tr>';
        return;
    }
    var html = '';
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        var addr = [r.city, r.state, r.zip].filter(Boolean).join(', ');
        var typeLabel = INS_TYPES[r.ins_type_code] || r.ins_type_code || '—';
        html += '<tr>'
            + '<td style="font-weight:600">' + esc(r.name) + '</td>'
            + '<td style="font-family:monospace;font-size:11px">' + esc(r.cms_id || '—') + '</td>'
            + '<td><span class="badge-neutral">' + esc(typeLabel) + '</span></td>'
            + '<td style="font-size:11px;color:var(--text2)">' + esc(addr || '—') + '</td>'
            + '<td style="white-space:nowrap">'
            + '<button class="tbl-btn" onclick="editIns(' + parseInt(r.id) + ')">✎ <?php echo xlt("Edit"); ?></button> '
            + '<button class="tbl-btn tbl-btn-del" onclick="deleteIns(' + parseInt(r.id) + ',\'' + esc(r.name) + '\')">✕</button>'
            + '</td>'
            + '</tr>';
    }
    tb.innerHTML = html;
}

// ── Add new (blank form) ──────────────────────────────────────────────────────
function openAddModal() {
    _action = 'insert';
    setField('edit-id',  '');
    setField('f_name',   '');
    setField('f_cms',    '');
    setField('f_line1',  '');
    setField('f_city',   '');
    setField('f_state',  '');
    setField('f_zip',    '');
    document.getElementById('f_type').value = '';
    document.getElementById('modal-title').textContent = '<?php echo xlt("Add Insurance Company"); ?>';
    document.getElementById('modal-err').textContent = '';
    document.getElementById('modal').classList.add('open');
    setTimeout(function(){ document.getElementById('f_name').focus(); }, 80);
}

// ── Edit: load record then fill form ─────────────────────────────────────────
function editIns(id) {
    var r = IC_DATA[id];
    if (!r) { alert('Record ' + id + ' not in page data.'); return; }
    _action = 'update';
    setField('edit-id', id);
    setField('f_name',  r.name   || '');
    setField('f_cms',   r.cms_id || '');
    setField('f_line1', r.line1  || '');
    setField('f_city',  r.city   || '');
    setField('f_state', r.state  || '');
    setField('f_zip',   r.zip    || '');
    document.getElementById('f_type').value =
        (r.ins_type_code !== null && r.ins_type_code !== undefined) ? r.ins_type_code : '';
    document.getElementById('modal-title').textContent = '<?php echo xlt("Edit Insurance Company"); ?>';
    document.getElementById('modal-err').textContent = '';
    document.getElementById('modal').classList.add('open');
    setTimeout(function(){ document.getElementById('f_name').focus(); }, 80);
}

function setField(id, val) {
    var el = document.getElementById(id);
    if (el) el.value = val;
}

function closeModal() { document.getElementById('modal').classList.remove('open'); }

// ── Save ──────────────────────────────────────────────────────────────────────
function saveIns() {
    var name  = document.getElementById('f_name').value.trim();
    var err   = document.getElementById('modal-err');
    var editId = document.getElementById('edit-id').value;

    if (!name) { err.textContent = '<?php echo xlt("Company name is required."); ?>'; return; }

    // Cross-check: if action is 'update' but edit-id is missing, something went wrong
    if (_action === 'update' && (!editId || parseInt(editId) <= 0)) {
        err.textContent = '<?php echo xlt("Error: missing record ID. Please close and try again."); ?>';
        return;
    }
    // Opposite: if action is 'insert' but edit-id has a value, clear the stale ID
    if (_action === 'insert' && editId) {
        document.getElementById('edit-id').value = '';
        editId = '';
    }

    var btn = document.getElementById('modal-save');
    btn.disabled = true;
    var fd = new FormData();
    fd.append('csrf_token_form', CSRF);
    fd.append('action',          _action);
    fd.append('id',              editId);
    fd.append('name',            name);
    fd.append('cms_id',          document.getElementById('f_cms').value);
    fd.append('ins_type_code',   document.getElementById('f_type').value);
    fd.append('line1',           document.getElementById('f_line1').value);
    fd.append('city',            document.getElementById('f_city').value);
    fd.append('state',           document.getElementById('f_state').value);
    fd.append('zip',             document.getElementById('f_zip').value);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        if (xhr.status !== 200) { err.textContent = 'Server error (' + xhr.status + ')'; return; }
        var r = null;
        try {
            var json_start = xhr.responseText.indexOf('{');
            var clean = json_start >= 0 ? xhr.responseText.slice(json_start) : xhr.responseText;
            r = JSON.parse(clean);
        } catch(e) { r = null; }
        // Never silently succeed on a parse failure — show an error instead
        if (!r) {
            err.textContent = '<?php echo xlt("Unexpected server response. Please verify the record was saved."); ?>';
            return;
        }
        if (!r.success) { err.textContent = r.error || 'Error'; return; }
        // Only update in-memory cache when we have a valid ID back
        if (r.row && parseInt(r.row.id) > 0) {
            IC_DATA[parseInt(r.row.id)] = r.row;
        }
        closeModal();
        doSearch(document.getElementById('search-input').value);
        showToast(_action === 'update' ? '<?php echo xlt("Company updated."); ?>' : '<?php echo xlt("Company added."); ?>');
    };
    xhr.send(fd);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function esc(s) {
    if (!s && s !== 0) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function deleteIns(id, name) {
    if (!confirm('<?php echo xlt("Delete insurance company"); ?>: ' + name + '?')) return;
    var fd = new FormData();
    fd.append('csrf_token_form', CSRF);
    fd.append('action', 'delete');
    fd.append('id', id);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        var r = null;
        try { var j = xhr.responseText.indexOf('{'); r = JSON.parse(j >= 0 ? xhr.responseText.slice(j) : xhr.responseText); } catch(e) {}
        if (r && !r.success) { alert(r.error || 'Error'); return; }
        delete IC_DATA[id];
        doSearch(document.getElementById('search-input').value);
        showToast('<?php echo xlt("Company deleted."); ?>');
    };
    xhr.send(fd);
}
function showToast(m) {
    var el = document.getElementById('toast');
    el.textContent = m; el.className = 'show';
    clearTimeout(el._t); el._t = setTimeout(function(){ el.className = ''; }, 2800);
}

doSearch('');
</script></body></html>
