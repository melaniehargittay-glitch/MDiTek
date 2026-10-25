<?php
/**
 * procedures_fees.php — Procedure & Fee Code Manager
 * Table: codes  +  prices
 *
 * Follows the same write logic as superbill_custom_full.php so that codes
 * added/edited here populate the fee sheet correctly:
 *   • code_type is stored as the numeric ct_id from code_types (not as the
 *     string label like 'CPT4'), matching how the fee sheet looks codes up.
 *   • Fee is written to the prices table (pr_level='standard') rather than
 *     codes.fee, since the fee sheet JOIN is against prices, not codes.fee.
 *   • On update, the old prices row is deleted then re-inserted, same as
 *     superbill_custom_full.php lines 119-133.
 */
require_once('../globals.php');
require_once "$srcdir/user.inc.php";
require_once "../../custom/code_types.inc.php"; // provides $code_types array with numeric ids
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Helper: map string type name (e.g. 'CPT4') to numeric ct_id used in the
// codes table. Superbill stores this numeric id; the fee sheet queries by it.
function pf_type_to_id($type_name) {
    global $code_types;
    if (!$type_name) return '';
    // $code_types is keyed by the string name; value has 'id' => numeric ct_id
    if (isset($code_types[$type_name])) {
        return (string)$code_types[$type_name]['id'];
    }
    // Fallback: direct DB lookup so this works even if code_types.inc isn't
    // loaded yet or the type is non-standard
    $row = sqlQuery("SELECT ct_id FROM code_types WHERE ct_key = ? LIMIT 1", [$type_name]);
    return $row ? (string)$row['ct_id'] : $type_name;
}

// Helper: reverse-map numeric ct_id → string label for display in the UI.
function pf_id_to_type($ct_id) {
    global $code_types;
    foreach ($code_types as $key => $ct) {
        if ((string)$ct['id'] === (string)$ct_id) return $key;
    }
    // Fallback
    $row = sqlQuery("SELECT ct_key FROM code_types WHERE ct_id = ? LIMIT 1", [(int)$ct_id]);
    return $row ? $row['ct_key'] : (string)$ct_id;
}

// ── AJAX search ───────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $rows = [];
    $res  = sqlStatement(
        "SELECT c.id, c.code_type, c.code, c.code_text, c.modifier, c.units,
                c.active,
                COALESCE(p.pr_price, c.fee, 0) AS fee
           FROM codes c
           LEFT JOIN prices p ON p.pr_id = c.id
                              AND p.pr_selector = ''
                              AND p.pr_level = 'standard'
          WHERE c.code LIKE ? OR c.code_text LIKE ?
          ORDER BY c.code_type, c.code
          LIMIT 100",
        [$q, $q]
    );
    while ($r = sqlFetchArray($res)) {
        // Return the human-readable type label for display in the UI even
        // though we store it as a numeric id internally.
        $r['code_type'] = pf_id_to_type($r['code_type']);
        $rows[] = $r;
    }
    echo json_encode($rows); exit;
}

// ── AJAX load one ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');
    $row = sqlQuery(
        "SELECT c.*, COALESCE(p.pr_price, c.fee, 0) AS fee
           FROM codes c
           LEFT JOIN prices p ON p.pr_id = c.id
                              AND p.pr_selector = ''
                              AND p.pr_level = 'standard'
          WHERE c.id = ? LIMIT 1",
        [(int)$_GET['id']]
    );
    if ($row) {
        $row['code_type'] = pf_id_to_type($row['code_type']);
    }
    echo json_encode($row ?: []); exit;
}

// ── POST save ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  ini_set('display_errors', 1);
  error_reporting(E_ALL);

  header('Content-Type: application/json');

    $action    = $_POST['action'] ?? 'insert';
    $code      = strtoupper(trim($_POST['code'] ?? ''));
    $code_type_str = trim($_POST['code_type'] ?? 'CPT4'); // human-readable label from UI
    $code_type_id  = pf_type_to_id($code_type_str);      // numeric ct_id for DB storage
    $code_text = trim($_POST['code_text'] ?? '');

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Remove the prices entry first (avoids orphan rows)
        sqlStatement("DELETE FROM prices WHERE pr_id = ? AND pr_selector = ''", [$id]);
        sqlStatement("DELETE FROM codes WHERE id = ?", [$id]);
        echo json_encode(['success'=>true]); exit;
    }

    if (!$code || !$code_text) {
        echo json_encode(['success'=>false,'error'=>'Code and description are required.']); exit;
    }

    $modifier = trim($_POST['modifier'] ?? '');
    $units    = (int)($_POST['units'] ?? 1);
    $fee      = (float)($_POST['fee'] ?? 0);
    $active   = (int)($_POST['active'] ?? 1);

    $id = (int)($_POST['id'] ?? 0);

    // Duplicate check disabled— same guard as superbill_custom_full.php lines 95-101
    /*$dup = sqlQuery(
        "SELECT COUNT(*) AS cnt FROM codes
          WHERE code_type = ? AND code = ? AND modifier = ? AND id != ?",
        [$code_type_id, $code, $modifier, $id]
    );
    if (!empty($dup['cnt'])) {
        echo json_encode(['success'=>false,'error'=>'A duplicate code/type/modifier already exists.']); exit;
    }*/

    if ($action === 'update' && $id) {
        // ── UPDATE path — mirrors superbill_custom_full.php lines 117-133 ──
        sqlStatement(
            "UPDATE codes
                SET code=?, code_type=?, code_text=?, modifier=?, units=?, active=?
              WHERE id=?",
            [$code, $code_type_id, $code_text, $modifier, $units, $active, $id]
        );
        // Delete then re-insert the standard price row, exactly as superbill does
        sqlStatement("DELETE FROM prices WHERE pr_id = ? AND pr_selector = ''", [$id]);
        if ($fee) {
            sqlStatement(
                "INSERT INTO prices (pr_id, pr_selector, pr_level, pr_price) VALUES (?, '', 'standard', ?)",
                [$id, $fee]
            );
        }
    } else {
        // ── INSERT path — mirrors superbill_custom_full.php line 122 + 129-133 ──
        $id = sqlInsert(
            "INSERT INTO codes (code, code_type, code_text, modifier, units, fee, active)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$code, $code_type_id, $code_text, $modifier, $units, $fee, $active]
        );
        if ($fee) {
            sqlStatement(
                "INSERT INTO prices (pr_id, pr_selector, pr_level, pr_price) VALUES (?, '', 'standard', ?)",
                [$id, $fee]
            );
        }
    }

    // Return the row with the human-readable code_type for the UI table
    $row = sqlQuery(
        "SELECT c.*, COALESCE(p.pr_price, c.fee, 0) AS fee
           FROM codes c
           LEFT JOIN prices p ON p.pr_id = c.id
                              AND p.pr_selector = ''
                              AND p.pr_level = 'standard'
          WHERE c.id = ? LIMIT 1",
        [$id]
    );
    if ($row) {
        $row['code_type'] = pf_id_to_type($row['code_type']);
    }
    echo json_encode(['success'=>true,'row'=>$row]); exit;
}
$csrf = CsrfUtils::collectCsrfToken();
?>
<!DOCTYPE html><html><head>
<?php Header::setupHeader(); ?>
<title><?php echo xlt("Procedures & Fees"); ?></title>
<?php include 'admin_style.php.inc'; ?>
<style>.tbl-btn-del{color:#dc2626!important;border-color:#fecaca!important;background:#fff5f5!important}.tbl-btn-del:hover{background:#fee2e2!important;border-color:#fca5a5!important}</style>
</head><body>
<div id="container_div" class="container adm-wrap">
<div class="adm-toolbar">
  <div class="adm-title">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
    <?php echo xlt("Procedures & Fees"); ?> <span class="adm-sub">codes</span>
  </div>
  <button class="btn-primary" onclick="openModal()">+ <?php echo xlt("Add Code"); ?></button>
</div>
<div class="adm-search-bar">
  <div class="search-wrap">
    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <input type="text" id="search-input" class="search-input" placeholder="<?php echo xla('Search by code or description...'); ?>" oninput="debSearch(this.value)" autofocus>
    <div class="search-spinner" id="spin"></div>
  </div>
</div>
<div class="adm-card">
  <div class="adm-card-head"><span id="result-count" class="adm-count">—</span></div>
  <div class="tbl-wrap">
    <table><thead><tr>
      <th style="width:90px"><?php echo xlt("Code"); ?></th>
      <th style="width:80px"><?php echo xlt("Type"); ?></th>
      <th><?php echo xlt("Description"); ?></th>
      <th style="width:70px"><?php echo xlt("Modifier"); ?></th>
      <th style="width:60px"><?php echo xlt("Units"); ?></th>
      <th style="width:80px"><?php echo xlt("Fee"); ?></th>
      <th style="width:70px"><?php echo xlt("Active"); ?></th>
      <th style="width:90px"></th>
    </tr></thead>
    <tbody id="result-tbody">
      <tr class="empty-row"><td colspan="8"><?php echo xlt("Type to search procedure codes."); ?></td></tr>
    </tbody></table>
  </div>
</div>

<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head"><span id="modal-title"><?php echo xlt("Add Procedure Code"); ?></span><button class="modal-close" onclick="closeModal()">✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field"><label><?php echo xlt("Code"); ?> *</label><input id="f_code" class="finput" placeholder="e.g. 98941" oninput="this.value=this.value.toUpperCase()"></div>
        <div class="field"><label><?php echo xlt("Code Type"); ?></label>
          <select id="f_type" class="finput">
            <option>CPT4</option><option>HCPCS</option><option>ICD10</option><option>COPAY</option>
          </select>
        </div>
        <div class="field field-full"><label><?php echo xlt("Description"); ?> *</label><input id="f_text" class="finput"></div>
        <div class="field"><label><?php echo xlt("Modifier"); ?></label><input id="f_mod" class="finput" maxlength="12"></div>
        <div class="field"><label><?php echo xlt("Units"); ?></label><input id="f_units" class="finput" type="number" min="0" value="1"></div>
        <div class="field"><label><?php echo xlt("Fee"); ?></label><input id="f_fee" class="finput" type="number" min="0" step="0.01" value="0"></div>
        <div class="field"><label><?php echo xlt("Active"); ?></label>
          <select id="f_active" class="finput"><option value="1"><?php echo xlt("Active"); ?></option><option value="0"><?php echo xlt("Inactive"); ?></option></select></div>
      </div>
      <div class="modal-err" id="modal-err"></div>
    </div>
    <div class="modal-foot">
      <button class="btn-ghost" onclick="closeModal()"><?php echo xlt("Cancel"); ?></button>
      <button class="btn-primary" id="modal-save" onclick="saveCode()"><?php echo xlt("Save"); ?></button>
    </div>
  </div>
</div>
<input type="hidden" id="edit-id">
<div id="toast"></div>
<script>
var CSRF = <?php echo json_encode($csrf); ?>;
var _action='insert',_timer=null;
function debSearch(v){clearTimeout(_timer);_timer=setTimeout(function(){doSearch(v);},220);}
function doSearch(v){
  document.getElementById('spin').style.display='block';
  var xhr=new XMLHttpRequest();xhr.open('GET','?ajax=1&q='+encodeURIComponent(v),true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;document.getElementById('spin').style.display='none';
    var rows;try{rows=JSON.parse(xhr.responseText);}catch(e){return;}renderRows(rows);};xhr.send();}
function renderRows(rows){
  var tb=document.getElementById('result-tbody');
  document.getElementById('result-count').textContent=rows.length+' <?php echo xlt("result(s)"); ?>';
  if(!rows.length){tb.innerHTML='<tr class="empty-row"><td colspan="8"><?php echo xlt("No matching codes found."); ?></td></tr>';return;}
  tb.innerHTML=rows.map(function(r){
    return '<tr>'
      +'<td style="font-family:monospace;font-weight:600;color:var(--accent)">'+esc(r.code)+'</td>'
      +'<td><span class="badge-neutral">'+esc(r.code_type)+'</span></td>'
      +'<td>'+esc(r.code_text)+'</td>'
      +'<td style="font-family:monospace">'+esc(r.modifier||'—')+'</td>'
      +'<td>'+esc(r.units||1)+'</td>'
      +'<td>'+formatFee(r.fee)+'</td>'
      +'<td>'+(parseInt(r.active)?'<span class="badge-ok">Active</span>':'<span class="badge-off">Off</span>')+'</td>'
      +'<td style="white-space:nowrap">'
      +'<button class="tbl-btn" onclick="editCode('+esc(r.id)+')">✎ <?php echo xlt("Edit"); ?></button> '
      +'<button class="tbl-btn tbl-btn-del" onclick="deleteCode('+esc(r.id)+',\''+esc(r.code)+'\')">✕</button>'
      +'</td>'
      +'</tr>';}).join('');}
function formatFee(v){if(!v||v==='0.00')return'<span style="color:var(--text3)">—</span>';return'$'+parseFloat(v).toFixed(2);}
function openModal(data){
  _action=data?'update':'insert';
  document.getElementById('modal-title').textContent=data?'<?php echo xlt("Edit Procedure Code"); ?>':'<?php echo xlt("Add Procedure Code"); ?>';
  document.getElementById('edit-id').value=data?data.id:'';
  document.getElementById('f_code').value=data?data.code:'';
  document.getElementById('f_code').readOnly=!!data;
  document.getElementById('f_type').value=data?data.code_type:'CPT4';
  document.getElementById('f_text').value=data?data.code_text:'';
  document.getElementById('f_mod').value=data?data.modifier:'';
  document.getElementById('f_units').value=data?data.units:1;
  document.getElementById('f_fee').value=data?data.fee:0;
  document.getElementById('f_active').value=data?(data.active||1):1;
  document.getElementById('modal-err').textContent='';
  document.getElementById('modal').classList.add('open');
  setTimeout(function(){document.getElementById(_action==='insert'?'f_code':'f_text').focus();},80);}
function closeModal(){document.getElementById('modal').classList.remove('open');}
function editCode(id){var xhr=new XMLHttpRequest();xhr.open('GET','?ajax_load=1&id='+id,true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;var d;try{d=JSON.parse(xhr.responseText);}catch(e){return;}openModal(d);};xhr.send();}
function saveCode(){
  var code=document.getElementById('f_code').value.trim();
  var text=document.getElementById('f_text').value.trim();
  var err=document.getElementById('modal-err');
  if(!code||!text){err.textContent='<?php echo xlt("Code and description are required."); ?>';return;}
  var btn=document.getElementById('modal-save');btn.disabled=true;
  var fd=new FormData();
  fd.append('csrf_token_form',CSRF);fd.append('action',_action);
  fd.append('id',document.getElementById('edit-id').value);
  fd.append('code',code);fd.append('code_type',document.getElementById('f_type').value);
  fd.append('code_text',text);fd.append('modifier',document.getElementById('f_mod').value);
  fd.append('units',document.getElementById('f_units').value);
  fd.append('fee',document.getElementById('f_fee').value);
  fd.append('active',document.getElementById('f_active').value);
  var xhr=new XMLHttpRequest();xhr.open('POST','',true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;btn.disabled=false;
    var r;try{r=JSON.parse(xhr.responseText);}catch(e){return;}
    if(!r.success){err.textContent=r.error||'Error';return;}
    closeModal();doSearch(document.getElementById('search-input').value);
    showToast(_action==='update'?'<?php echo xlt("Code updated."); ?>':'<?php echo xlt("Code added."); ?>');};xhr.send(fd);}
function esc(s){if(!s&&s!==0)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function deleteCode(id,code){
  if(!confirm('<?php echo xlt("Delete procedure code"); ?> '+code+'?'))return;
  var fd=new FormData();fd.append('csrf_token_form',CSRF);fd.append('action','delete');fd.append('id',id);
  var xhr=new XMLHttpRequest();xhr.open('POST','',true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;
    var r;try{r=JSON.parse(xhr.responseText);}catch(e){return;}
    if(!r.success){alert(r.error||'Error');return;}
    doSearch(document.getElementById('search-input').value);
    showToast('<?php echo xlt("Code deleted."); ?>');};xhr.send(fd);
}
function showToast(m){var el=document.getElementById('toast');el.textContent=m;el.className='show';clearTimeout(el._t);el._t=setTimeout(function(){el.className='';},2800);}
doSearch('');
</script></body></html>
