<?php
/**
 * dx_codes.php  — Diagnosis Code Manager (ICD-10)
 * Table: icd10_dx_order_code
 */
require_once(dirname(__FILE__) . "/../globals.php");
require_once "$srcdir/user.inc.php";
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// ── AJAX search ───────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $q    = '%' . trim($_GET['q'] ?? '') . '%';
    $rows = [];
    $res  = sqlStatement(
      "SELECT
      dx_id,
      dx_code,
      formatted_dx_code,
      long_desc,
      short_desc,
      valid_for_coding,
      active
           FROM icd10_dx_order_code
          WHERE dx_code LIKE ? OR long_desc LIKE ? OR short_desc LIKE ?
          ORDER BY dx_code
          LIMIT 100",
        [$q, $q, $q]
    );
    while ($r = sqlFetchArray($res)) $rows[] = $r;
    echo json_encode($rows); exit;
}

// ── AJAX load one row ─────────────────────────────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');
    $row = sqlQuery("SELECT * FROM icd10_dx_order_code WHERE dx_code=?",
        [trim($_GET['code'] ?? '')]);
    echo json_encode($row ?: []); exit;
}

// ── POST save ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>'CSRF']); exit;
    }
    $code      = strtoupper(trim($_POST['dx_code'] ?? ''));
    $long_desc = trim($_POST['long_desc'] ?? '');
    if (!$code || !$long_desc) {
        echo json_encode(['success'=>false,'error'=>'Code and description are required.']); exit;
    }
    $short_desc = trim($_POST['short_desc'] ?? '');
    $valid      = $_POST['valid_for_coding'] ?? 'Y';
    $active     = (int)($_POST['active'] ?? 1);
    $action     = $_POST['action'] ?? 'insert';

    if ($action === 'delete') {
        sqlStatement("UPDATE icd10_dx_order_code SET active=0 WHERE dx_code=?", [$code]);
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'update') {
        sqlStatement(
            "UPDATE icd10_dx_order_code SET long_desc=?,short_desc=?,valid_for_coding=?,active=?
              WHERE dx_code=?",
            [$long_desc,$short_desc,$valid,$active,$code]
        );
    } else {
        $exists = sqlQuery("SELECT dx_code FROM icd10_dx_order_code WHERE dx_code=?", [$code]);
        if ($exists) { echo json_encode(['success'=>false,'error'=>'Code already exists.']); exit; }
        sqlStatement(
            "INSERT INTO icd10_dx_order_code (dx_code,long_desc,short_desc,valid_for_coding,active)
             VALUES (?,?,?,?,?)",
            [$code,$long_desc,$short_desc,$valid,$active]
        );
    }
    $row = sqlQuery("SELECT * FROM icd10_dx_order_code WHERE dx_code=?", [$code]);
    echo json_encode(['success'=>true,'row'=>$row]); exit;
}

$csrf = CsrfUtils::collectCsrfToken();
?>
<!DOCTYPE html><html><head>
<?php Header::setupHeader(); ?>
<title><?php echo xlt("Diagnosis Codes"); ?></title>
<?php include 'admin_style.php.inc'; ?>
<style>.tbl-btn-del{color:#dc2626!important;border-color:#fecaca!important;background:#fff5f5!important}.tbl-btn-del:hover{background:#fee2e2!important;border-color:#fca5a5!important}</style>
</head><body>
<div id="container_div" class="container adm-wrap">

<div class="adm-toolbar">
  <div class="adm-title">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
    <?php echo xlt("Diagnosis Codes"); ?> <span class="adm-sub">ICD-10</span>
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
  <div class="adm-card-head">
    <span id="result-count" class="adm-count">—</span>
  </div>
  <div class="tbl-wrap">
    <table><thead><tr>
      <th style="width:110px"><?php echo xlt("Code"); ?></th>
      <th><?php echo xlt("Long Description"); ?></th>
      <th style="width:180px"><?php echo xlt("Short Description"); ?></th>
      <th style="width:70px"><?php echo xlt("Valid"); ?></th>
      <th style="width:70px"><?php echo xlt("Active"); ?></th>
      <th style="width:90px"></th>
    </tr></thead>
    <tbody id="result-tbody">
      <tr class="empty-row"><td colspan="6"><?php echo xlt("Type to search diagnosis codes."); ?></td></tr>
    </tbody></table>
  </div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head"><span id="modal-title"><?php echo xlt("Add Diagnosis Code"); ?></span><button class="modal-close" onclick="closeModal()">✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field"><label><?php echo xlt("Diagnosis Code"); ?> *</label><input id="f_code" class="finput" placeholder="e.g. M54.5" oninput="this.value=this.value.toUpperCase()"></div>
        <div class="field"><label><?php echo xlt("Valid for Coding"); ?></label>
          <select id="f_valid" class="finput"><option value="Y"><?php echo xlt("Yes"); ?></option><option value="N"><?php echo xlt("No"); ?></option></select></div>
        <div class="field field-full"><label><?php echo xlt("Long Description"); ?> *</label><input id="f_long" class="finput" placeholder="<?php echo xla('Full description'); ?>"></div>
        <div class="field field-full"><label><?php echo xlt("Short Description"); ?></label><input id="f_short" class="finput" placeholder="<?php echo xla('Abbreviated description'); ?>"></div>
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
<div id="toast"></div>
<script>
var CSRF='<?php echo attr(CsrfUtils::collectCsrfToken()); ?>';
var _action='insert',_timer=null;
function debSearch(v){clearTimeout(_timer);_timer=setTimeout(function(){doSearch(v);},220);}
function doSearch(v){
  var sp=document.getElementById('spin');sp.style.display='block';
  var xhr=new XMLHttpRequest();
  xhr.open('GET','?ajax=1&q='+encodeURIComponent(v),true);
  xhr.onreadystatechange=function(){
    if(xhr.readyState!==4)return;sp.style.display='none';
    var rows;try{rows=JSON.parse(xhr.responseText);}catch(e){return;}
    renderRows(rows);
  };xhr.send();
}
function renderRows(rows){
  var tb=document.getElementById('result-tbody');
  document.getElementById('result-count').textContent=rows.length+' <?php echo xlt("result(s)"); ?>';
  if(!rows.length){tb.innerHTML='<tr class="empty-row"><td colspan="6"><?php echo xlt("No matching codes found."); ?></td></tr>';return;}
  tb.innerHTML=rows.map(function(r){
    return '<tr>'
      +'<td style="font-family:monospace;font-weight:600;color:var(--accent)">'+esc(r.dx_code)+'</td>'
      +'<td>'+esc(r.long_desc)+'</td>'
      +'<td style="font-size:11px;color:var(--text2)">'+esc(r.short_desc||'—')+'</td>'
      +'<td>'+badge(r.valid_for_coding,'Yes','No')+'</td>'
      +'<td>'+badge(r.active,'Active','Inactive')+'</td>'
      +'<td style="white-space:nowrap">'
      +'<button class="tbl-btn" onclick="editCode(\''+esc(r.dx_code)+'\')">✎ <?php echo xlt("Edit"); ?></button> '
      +'<button class="tbl-btn tbl-btn-del" onclick="deleteCode(\''+esc(r.dx_code)+'\')">✕</button>'
      +'</td>'
      +'</tr>';
  }).join('');
}
function badge(v,y,n){
  var s=String(v).toUpperCase();
  return (s==='Y'||s==='1')?'<span class="badge-ok">'+y+'</span>':'<span class="badge-off">'+n+'</span>';
}
function openModal(data){
  _action=data?'update':'insert';
  document.getElementById('modal-title').textContent=data?'<?php echo xlt("Edit Diagnosis Code"); ?>':'<?php echo xlt("Add Diagnosis Code"); ?>';
  document.getElementById('f_code').value=data?data.dx_code:'';
  document.getElementById('f_code').readOnly=!!data;
  document.getElementById('f_long').value=data?data.long_desc:'';
  document.getElementById('f_short').value=data?data.short_desc:'';
  document.getElementById('f_valid').value =data ? (data.valid_for_coding || 'Y') : 'Y';
  document.getElementById('f_active').value=data?(data.active||1):1;
  document.getElementById('modal-err').textContent='';
  document.getElementById('modal').classList.add('open');
  setTimeout(function(){(document.getElementById(_action==='insert'?'f_code':'f_long')).focus();},80);
}
function closeModal(){document.getElementById('modal').classList.remove('open');}
function editCode(code){
  var xhr=new XMLHttpRequest();
  xhr.open('GET','?ajax_load=1&code='+encodeURIComponent(code),true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;var d;try{d=JSON.parse(xhr.responseText);}catch(e){return;}openModal(d);};
  xhr.send();
}
function saveCode(){
  var code=document.getElementById('f_code').value.trim();
  var long_desc=document.getElementById('f_long').value.trim();
  var err=document.getElementById('modal-err');
  if(!code||!long_desc){err.textContent='<?php echo xlt("Code and description are required."); ?>';return;}
  var btn=document.getElementById('modal-save');btn.disabled=true;
  var fd=new FormData();
  fd.append('csrf_token_form',CSRF);fd.append('action',_action);
  fd.append('dx_code',code);fd.append('long_desc',long_desc);
  fd.append('short_desc',document.getElementById('f_short').value);
  fd.append('valid_for_coding',document.getElementById('f_valid').value);
  fd.append('active',document.getElementById('f_active').value);
  var xhr=new XMLHttpRequest();xhr.open('POST','',true);
  xhr.onreadystatechange=function(){
    if(xhr.readyState!==4)return;btn.disabled=false;
    var r;try{r=JSON.parse(xhr.responseText);}catch(e){return;}
    if(!r.success){err.textContent=r.error||'Error';return;}
    closeModal();doSearch(document.getElementById('search-input').value);
    showToast(_action==='update'?'<?php echo xlt("Code updated."); ?>':'<?php echo xlt("Code added."); ?>');
  };xhr.send(fd);
}
function esc(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function deleteCode(code){
  if(!confirm('<?php echo xlt("Delete diagnosis code"); ?> '+code+'?'))return;
  var fd=new FormData();fd.append('csrf_token_form',CSRF);fd.append('action','delete');fd.append('dx_code',code);
  var xhr=new XMLHttpRequest();xhr.open('POST','',true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;
    var r;try{r=JSON.parse(xhr.responseText);}catch(e){return;}
    if(!r.success){alert(r.error||'Error');return;}
    doSearch(document.getElementById('search-input').value);
    showToast('<?php echo xlt("Code deleted."); ?>');};xhr.send(fd);
}
function showToast(m){var el=document.getElementById('toast');el.textContent=m;el.className='show';clearTimeout(el._t);el._t=setTimeout(function(){el.className='';},2800);}
doSearch('');
</script>
</body></html>
