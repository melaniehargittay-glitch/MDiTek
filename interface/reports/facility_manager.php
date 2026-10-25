<?php
/**
 * facility_manager.php — Facility/Hospital Manager
 * Table: facility
 */
require_once(dirname(__FILE__) . "/../globals.php");
require_once "$srcdir/user.inc.php";
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $res = sqlStatement(
        "SELECT id,name,street,city,state,postal_code,phone,fax,email,
                facility_code,pos_code
           FROM facility
          WHERE name LIKE ? OR city LIKE ? OR facility_code LIKE ?
          ORDER BY name LIMIT 100",
        [$q,$q,$q]
    );
    $rows = []; while ($r = sqlFetchArray($res)) $rows[] = $r;
    echo json_encode($rows); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>'CSRF']); exit;
    }
    $action = $_POST['action'] ?? 'insert';
    $name   = trim($_POST['name'] ?? '');
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
        sqlStatement("DELETE FROM addresses WHERE foreign_id=?", [$id]);
        sqlStatement("DELETE FROM facility WHERE id=?", [$id]);
        echo json_encode(['success'=>true]); exit;
    }
    if (!$name) { echo json_encode(['success'=>false,'error'=>'Facility name is required.']); exit; }
    $fields = [
        'street'        => trim($_POST['street']        ?? ''),
        'city'          => trim($_POST['city']          ?? ''),
        'state'         => trim($_POST['state']         ?? ''),
        'postal_code'   => trim($_POST['postal_code']   ?? ''),
        'phone'         => trim($_POST['phone']         ?? ''),
        'fax'           => trim($_POST['fax']           ?? ''),
        'email'         => trim($_POST['email']         ?? ''),
        'facility_code' => trim($_POST['facility_code'] ?? ''),
        'pos_code'      => trim($_POST['pos_code']      ?? ''),
    ];
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'error'=>'Invalid ID']); exit; }
        $sets = implode(',', array_map(fn($k) => "`$k`=?", array_keys($fields)));
        $vals = array_values($fields); $vals[] = $id;
        sqlStatement("UPDATE facility SET name=?, $sets WHERE id=?", array_merge([$name], $vals));
    } else {
        $keys = implode(',', array_map(fn($k) => "`$k`", array_keys($fields)));
        $phs  = implode(',', array_fill(0, count($fields), '?'));
        sqlStatement("INSERT INTO facility (name,$keys) VALUES (?,$phs)", array_merge([$name], array_values($fields)));
        $id = (int)(sqlQuery("SELECT LAST_INSERT_ID() AS id")['id']);
    }
    $row = sqlQuery("SELECT * FROM facility WHERE id=?", [$id]);
    // Strip junk before JSON in case of shutdown output
    echo json_encode(['success'=>true,'row'=>$row ?: []]);
    exit;
}

// ── Pre-load all facilities for instant JS edit lookup ────────────────────────
$all_facilities = [];
$fac_res = sqlStatement(
    "SELECT id,name,street,city,state,postal_code,phone,fax,email,
            facility_code,pos_code
       FROM facility ORDER BY name"
);
while ($row = sqlFetchArray($fac_res)) {
    $all_facilities[(int)$row['id']] = $row;
}
$csrf = CsrfUtils::collectCsrfToken();
?>
<!DOCTYPE html><html><head>
<?php Header::setupHeader();?>
<title><?php echo xlt("Facilities");?></title>
<?php include 'admin_style.php.inc';?>
</head><body>
<script>
var FAC_DATA = <?php echo json_encode($all_facilities, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
</script>
<div id="container_div" class="container adm-wrap">
<div class="adm-toolbar">
  <div class="adm-title">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
    <?php echo xlt("Facility / Hospital");?> <span class="adm-sub">facility</span>
  </div>
  <button class="btn-primary" onclick="openModal()">+ <?php echo xlt("Add Facility");?></button>
</div>
<div class="adm-search-bar">
  <div class="search-wrap">
    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
    <input type="text" id="search-input" class="search-input" placeholder="<?php echo xla('Search by name, city, or code...');?>" oninput="debSearch(this.value)" autofocus>
    <div class="search-spinner" id="spin"></div>
  </div>
</div>
<div class="adm-card">
  <div class="adm-card-head"><span id="result-count" class="adm-count">—</span></div>
  <div class="tbl-wrap"><table><thead><tr>
    <th><?php echo xlt("Name");?></th>
    <th><?php echo xlt("Address");?></th>
    <th style="width:80px"><?php echo xlt("Phone");?></th>
    <th style="width:70px"><?php echo xlt("Code");?></th>
    <th style="width:50px"><?php echo xlt("POS");?></th>
    <th style="width:90px"></th>
  </tr></thead>
  <tbody id="result-tbody"><tr class="empty-row"><td colspan="7"><?php echo xlt("Type to search facilities.");?></td></tr></tbody>
  </table></div>
</div>

<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
  <div class="modal-box">
    <div class="modal-head"><span id="modal-title"><?php echo xlt("Add Facility");?></span><button class="modal-close" onclick="closeModal()">✕</button></div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="field field-full"><label><?php echo xlt("Facility Name");?> *</label><input id="f_name" class="finput"></div>
        <div class="field field-full"><label><?php echo xlt("Street Address");?></label><input id="f_street" class="finput"></div>
        <div class="field"><label><?php echo xlt("City");?></label><input id="f_city" class="finput"></div>
        <div class="field"><label><?php echo xlt("State");?></label><input id="f_state" class="finput" maxlength="2" oninput="this.value=this.value.toUpperCase()"></div>
        <div class="field"><label><?php echo xlt("ZIP");?></label><input id="f_zip" class="finput" maxlength="10"></div>
        <div class="field"><label><?php echo xlt("Phone");?></label><input id="f_phone" class="finput" type="tel"></div>
        <div class="field"><label><?php echo xlt("Fax");?></label><input id="f_fax" class="finput" type="tel"></div>
        <div class="field"><label><?php echo xlt("Email");?></label><input id="f_email" class="finput" type="email"></div>
        <div class="field"><label><?php echo xlt("Facility Code");?></label><input id="f_code" class="finput" maxlength="20"></div>
        <div class="field"><label><?php echo xlt("POS Code");?></label><input id="f_pos" class="finput" maxlength="10"></div>
      </div>
      <div class="modal-err" id="modal-err"></div>
    </div>
    <div class="modal-foot">
      <button class="btn-ghost" onclick="closeModal()"><?php echo xlt("Cancel");?></button>
      <button class="btn-primary" id="modal-save" onclick="saveFac()"><?php echo xlt("Save");?></button>
    </div>
  </div>
</div>
<input type="hidden" id="edit-id">
<div id="toast"></div>
<script>
var CSRF='<?php echo attr(CsrfUtils::collectCsrfToken());?>';
var _action='insert',_timer=null;
function debSearch(v){clearTimeout(_timer);_timer=setTimeout(function(){doSearch(v);},220);}
function doSearch(v){document.getElementById('spin').style.display='block';
  var xhr=new XMLHttpRequest();xhr.open('GET','?ajax=1&q='+encodeURIComponent(v),true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;document.getElementById('spin').style.display='none';
    var rows;try{rows=JSON.parse(xhr.responseText);}catch(e){return;}renderRows(rows);};xhr.send();}
function renderRows(rows){
  var tb=document.getElementById('result-tbody');
  document.getElementById('result-count').textContent=rows.length+' <?php echo xlt("result(s)");?>';
  if(!rows.length){tb.innerHTML='<tr class="empty-row"><td colspan="7"><?php echo xlt("No facilities found.");?></td></tr>';return;}
  tb.innerHTML=rows.map(function(r){
    var addr=[r.city,r.state,r.postal_code].filter(Boolean).join(', ');
    return'<tr><td style="font-weight:600">'+esc(r.name)+'</td><td style="font-size:11px;color:var(--text2)">'+esc(addr||'—')+'</td>'
      +'<td style="font-family:monospace;font-size:11px;width:200px">'+esc(r.phone||'—')+'</td>'
      +'<td><span class="badge-neutral">'+esc(r.facility_code||'—')+'</span></td>'
      +'<td style="font-family:monospace">'+esc(r.pos_code||'—')+'</td>'
      +'<td style="white-space:nowrap">'
      +'<button class="tbl-btn" onclick="editFac('+r.id+')">✎ <?php echo xlt("Edit");?></button> '
      +'<button class="tbl-btn tbl-btn-del" onclick="deleteFac('+r.id+',\''+esc(r.name)+'\')">✕</button>'
      +'</td>'}).join('');}
function deleteFac(id,name){
  if(!confirm('<?php echo xlt("Delete facility"); ?>: '+name+'?'))return;
  var fd=new FormData();fd.append('csrf_token_form',CSRF);fd.append('action','delete');fd.append('id',id);
  var xhr=new XMLHttpRequest();xhr.open('POST','',true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;
    var r;try{r=JSON.parse(xhr.responseText);}catch(e){return;}
    if(!r.success){alert(r.error||'Error');return;}
    delete FAC_DATA[id];
    doSearch(document.getElementById('search-input').value);
    showToast('<?php echo xlt("Facility deleted."); ?>');};xhr.send(fd);
}
function openModal(d){
  _action=d?'update':'insert';
  document.getElementById('modal-title').textContent=d?'<?php echo xlt("Edit Facility");?>':'<?php echo xlt("Add Facility");?>';
  document.getElementById('edit-id').value=d?d.id:'';
  ['name','street','city','state','zip','phone','fax','email','code','pos'].forEach(function(k){
    var el=document.getElementById('f_'+k);if(el)el.value='';});
  if(d){
    document.getElementById('f_name').value=d.name||'';document.getElementById('f_street').value=d.street||'';
    document.getElementById('f_city').value=d.city||'';document.getElementById('f_state').value=d.state||'';
    document.getElementById('f_zip').value=d.postal_code||'';document.getElementById('f_phone').value=d.phone||'';
    document.getElementById('f_fax').value=d.fax||'';document.getElementById('f_email').value=d.email||'';
    document.getElementById('f_code').value=d.facility_code||'';document.getElementById('f_pos').value=d.pos_code||'';
  }
  document.getElementById('modal-err').textContent='';
  document.getElementById('modal').classList.add('open');
  setTimeout(function(){document.getElementById('f_name').focus();},80);}
function closeModal(){document.getElementById('modal').classList.remove('open');}
function editFac(id) {
    var d = FAC_DATA[id];
    if (!d) { alert('Facility ' + id + ' not found.'); return; }
    openModal(d);
}
function saveFac(){
  var name=document.getElementById('f_name').value.trim();
  var err=document.getElementById('modal-err');
  if(!name){err.textContent='<?php echo xlt("Facility name is required.");?>';return;}
  var btn=document.getElementById('modal-save');btn.disabled=true;
  var fd=new FormData();
  fd.append('csrf_token_form',CSRF);fd.append('action',_action);fd.append('id',document.getElementById('edit-id').value);
  fd.append('name',name);fd.append('street',document.getElementById('f_street').value);
  fd.append('city',document.getElementById('f_city').value);fd.append('state',document.getElementById('f_state').value);
  fd.append('postal_code',document.getElementById('f_zip').value);fd.append('phone',document.getElementById('f_phone').value);
  fd.append('fax',document.getElementById('f_fax').value);fd.append('email',document.getElementById('f_email').value);
  fd.append('facility_code',document.getElementById('f_code').value);fd.append('pos_code',document.getElementById('f_pos').value);
  var xhr=new XMLHttpRequest();xhr.open('POST','',true);
  xhr.onreadystatechange=function(){if(xhr.readyState!==4)return;btn.disabled=false;
    var r;
    try {
        var _js = xhr.responseText.indexOf('{');
        r = JSON.parse(_js >= 0 ? xhr.responseText.slice(_js) : xhr.responseText);
    } catch(e) {
        closeModal(); doSearch(document.getElementById('search-input').value);
        showToast(_action==='update'?'<?php echo xlt("Facility updated.");?>':'<?php echo xlt("Facility added.");?>');
        return;
    }
    if(!r.success){err.textContent=r.error||'Error';return;}
    if(r.row && r.row.id) FAC_DATA[parseInt(r.row.id)] = r.row;
    closeModal();doSearch(document.getElementById('search-input').value);
    showToast(_action==='update'?'<?php echo xlt("Facility updated.");?>':'<?php echo xlt("Facility added.");?>');};xhr.send(fd);}
function esc(s){if(!s&&s!==0)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function showToast(m){var el=document.getElementById('toast');el.textContent=m;el.className='show';clearTimeout(el._t);el._t=setTimeout(function(){el.className='';},2800);}
doSearch('');
</script></body></html>
