<?php
/**
 * quick_charge_manager.php — Quick Charge Button Manager
 * Table: mdi_quick_charge_templates
 */
require_once(dirname(__FILE__) . "/../globals.php");
require_once "$srcdir/user.inc.php";
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// ── AJAX load all active templates ────────────────────────────────────────────
if (isset($_GET['ajax_load'])) {
    header('Content-Type: application/json');
    $res = sqlStatement("SELECT id, name, template_json, active FROM mdi_quick_charge_templates WHERE active=1 ORDER BY id LIMIT 10");
    $rows = [];
    while ($r = sqlFetchArray($res)) {
        $tpl = json_decode($r['template_json'], true) ?: [];
        $rows[] = ['id'=>$r['id'],'name'=>$r['name'],'active'=>$r['active'],'codes'=>$tpl['codes']??[]];
    }
    echo json_encode($rows); exit;
}
if (isset($_GET['ajax_cpt'])) {
  header('Content-Type: application/json');

  $q = '%' . trim($_GET['q'] ?? '') . '%';

  $res = sqlStatement(
    "SELECT code, code_text
       FROM codes
      WHERE code_type = 1
        AND (
            code LIKE ?
            OR code_text LIKE ?
        )
      ORDER BY code
      LIMIT 25",
    [$q, $q]
);

  $rows = [];
  while ($r = sqlFetchArray($res)) {
      $rows[] = $r;
  }

  echo json_encode($rows);
  exit;
}

// ── POST: save all 10 buttons at once ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(['success'=>false,'error'=>'CSRF']); exit;
    }
    $buttons = json_decode($_POST['buttons'] ?? '[]', true);
    if (!is_array($buttons)) {
        echo json_encode(['success'=>false,'error'=>'Invalid data']); exit;
    }
    // Deactivate all, then re-insert active ones
    sqlStatement("DELETE FROM mdi_quick_charge_templates");
    foreach ($buttons as $btn) {
        $name = trim($btn['name'] ?? '');
        if (!$name) continue;
        $codes = $btn['codes'] ?? [];
        $tpl_json = json_encode(['name'=>$name,'codes'=>$codes]);
        sqlStatement(
            "INSERT INTO mdi_quick_charge_templates (name,template_json,active) VALUES (?,?,1)",
            [$name, $tpl_json]
        );
    }
    echo json_encode(['success'=>true]); exit;
}
$csrf = CsrfUtils::collectCsrfToken();
?>
<!DOCTYPE html><html><head>
<?php Header::setupHeader(); ?>
<title><?php echo xlt("Quick Charge Buttons"); ?></title>
<?php include 'admin_style.php.inc'; ?>
<style>
/* Quick charge specific */
.qc-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;padding:20px}
.qc-btn-card{background:#fff;border:2px solid var(--border);border-radius:10px;overflow:hidden;transition:border-color .15s}
.qc-btn-card:hover{border-color:var(--accent)}
.qc-btn-card.has-name{border-color:var(--accent)}
.qc-card-head{background:#f9fafb;padding:8px 12px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text2)}
.qc-num{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;background:var(--accent);color:#fff;border-radius:50%;font-size:10px;font-weight:700;flex-shrink:0}
.qc-card-body{padding:12px}
.qc-name-input{width:100%;padding:6px 9px;border:1px solid var(--border2);border-radius:6px;font-size:12px;font-weight:600;outline:none;margin-bottom:10px;font-family:inherit;transition:border-color .15s}
.qc-name-input:focus{border-color:var(--accent);box-shadow:0 0 0 2px rgba(107,79,216,.1)}
.qc-name-input::placeholder{font-weight:400;color:var(--text3)}
.codes-list{display:flex;flex-direction:column;gap:5px;min-height:40px}
.code-chip{display:flex;align-items:center;justify-content:space-between;background:#f3f4f6;border-radius:5px;padding:4px 8px;font-size:11px;font-family:var(--mono)}
.code-chip-label{font-weight:600;color:var(--accent)}
.code-chip-text{color:var(--text2);font-size:10px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:80px;font-family:inherit}
.code-chip-del{background:none;border:none;cursor:pointer;color:var(--text3);font-size:13px;line-height:1;padding:0 2px;transition:color .15s}
.code-chip-del:hover{color:#dc2626}
.add-code-row{display:flex;gap:5px;margin-top:8px}
.code-input-small{flex:1;padding:5px 7px;border:1px solid var(--border2);border-radius:5px;font-size:11px;font-family:var(--mono);outline:none;min-width:0;transition:border-color .15s}
.code-input-small:focus{border-color:var(--accent)}
.btn-add-code{background:var(--accent);color:#fff;border:none;border-radius:5px;padding:5px 9px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;transition:background .15s}
.btn-add-code:hover{background:var(--accent2)}
.btn-clear-btn{background:none;border:1px solid var(--border2);border-radius:5px;padding:4px 7px;font-size:10px;cursor:pointer;color:var(--text3);transition:all .15s;margin-left:4px}
.btn-clear-btn:hover{border-color:#dc2626;color:#dc2626}
/* preview strip */
.preview-strip{display:flex;flex-wrap:wrap;gap:8px;padding:16px 20px;background:var(--s1);border-bottom:1px solid var(--border)}
.preview-strip-label{font-size:11px;font-weight:600;color:var(--text3);text-transform:uppercase;letter-spacing:.05em;width:100%;margin-bottom:4px}
.preview-chip{background:var(--accent);color:#fff;border-radius:6px;padding:6px 14px;font-size:12px;font-weight:700;min-width:80px;text-align:center;opacity:.85}
.preview-chip.empty{background:#f3f4f6;color:var(--text3);border:1px dashed var(--border2);opacity:1}
/* save bar */
.save-bar{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;background:var(--s1);border-top:1px solid var(--border);position:sticky;bottom:0}
.save-status{font-size:12px;color:var(--text3)}
.save-status.unsaved{color:#92400e}

/* cpt css */
.add-code-row {
    display: block;
    margin-top: 8px;
    position: relative;
}

.cpt-search-wrap {
    position: relative;
    width: 100%;
}

.cpt-search {
    width: 100%;
    padding: 7px 9px;
    border: 1px solid var(--border2);
    border-radius: 6px;
    font-size: 11px;
    outline: none;
    margin-bottom:10px;
}

.cpt-results {
    display: none;
    position: absolute;
    left: 0;
    right: 0;
    top: 34px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 8px;
    max-height: 220px;
    overflow-y: auto;
    z-index: 99999;
    box-shadow: 0 8px 22px rgba(0,0,0,.18);
}

.cpt-item {
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    font-size: 11px;
}

.cpt-item:hover {
    background: #f5f7ff;
}
</style>
</head><body>
<div id="container_div" class="container adm-wrap">
<div class="adm-toolbar">
  <div class="adm-title">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><path d="M7 8h.01M11 8h.01M15 8h.01"/></svg>
    <?php echo xlt("Quick Charge Buttons"); ?> <span class="adm-sub">mdi_quick_charge_templates</span>
  </div>
</div>

<!-- Live preview strip -->
<div class="preview-strip" id="preview-strip">
  <div class="preview-strip-label"><?php echo xlt("Live Preview"); ?></div>
  <?php for($i=1;$i<=10;$i++): ?>
  <div class="preview-chip empty" id="preview-<?php echo $i; ?>">Button <?php echo $i; ?></div>
  <?php endfor; ?>
</div>

<!-- 10 button cards -->
<div class="qc-grid" id="qc-grid">
  <?php for($i=1;$i<=10;$i++): ?>
  <div class="qc-btn-card" id="card-<?php echo $i; ?>">
    <div class="qc-card-head">
      <div style="display:flex;align-items:center;gap:6px"><span class="qc-num"><?php echo $i; ?></span> <?php echo xlt("Button"); ?> <?php echo $i; ?></div>
      <button class="btn-clear-btn" onclick="clearBtn(<?php echo $i; ?>)" title="<?php echo xla('Clear button'); ?>">✕ <?php echo xlt("Clear"); ?></button>
    </div>
    <div class="qc-card-body">
      <input type="text" id="btn-name-<?php echo $i; ?>" class="qc-name-input"
             placeholder="<?php echo xla('Button label...'); ?>"
             oninput="onNameInput(<?php echo $i; ?>)">
             <div class="cpt-search-wrap">
    <input type="text"
           class="form-control cpt-search"
           placeholder="<?php echo xla('Search CPT code or description...'); ?>"
           onkeyup="searchCpt(this.value, <?php echo $i; ?>)">

    <div id="cpt-results-<?php echo $i; ?>" class="cpt-results"></div>
</div>
      <div class="codes-list" id="codes-<?php echo $i; ?>"></div>
      <div class="add-code-row">
    
      </div>
    </div>
  </div>
  <?php endfor; ?>
</div>

<div class="save-bar">
  <span class="save-status" id="save-status"><?php echo xlt("Loading..."); ?></span>
  <div style="display:flex;gap:8px">
    <button class="btn-ghost" onclick="loadTemplates()"><?php echo xlt("Reload"); ?></button>
    <button class="btn-primary" id="btn-save" onclick="saveAll()"><?php echo xlt("Save All Buttons"); ?></button>
  </div>
</div>
</div>

<div id="toast"></div>
<script>
var CSRF='<?php echo attr(CsrfUtils::collectCsrfToken()); ?>';
var _dirty=false;

// Each button slot: {name:'', codes:[{code:'',text:''}]}
var DATA={};
for(var i=1;i<=10;i++) DATA[i]={name:'',codes:[]};

function loadTemplates(){
  var xhr=new XMLHttpRequest();xhr.open('GET','?ajax_load=1',true);
  xhr.onreadystatechange=function(){
    if(xhr.readyState!==4)return;
    var rows;try{rows=JSON.parse(xhr.responseText);}catch(e){return;}
    // Reset all slots
    for(var i=1;i<=10;i++) DATA[i]={name:'',codes:[]};
    rows.forEach(function(r,idx){
      var slot=idx+1;if(slot>10)return;
      DATA[slot]={name:r.name,codes:r.codes||[]};
    });
    for(var i=1;i<=10;i++) renderSlot(i);
    setStatus('<?php echo xlt("Loaded from database"); ?>',false);
  };xhr.send();
}

function renderSlot(i){
  var d=DATA[i];
  var nameEl=document.getElementById('btn-name-'+i);
  nameEl.value=d.name||'';
  // Update preview
  var prev=document.getElementById('preview-'+i);
  if(d.name){prev.textContent=d.name;prev.className='preview-chip';}
  else{prev.textContent='Button '+i;prev.className='preview-chip empty';}
  // Update card border
  var card=document.getElementById('card-'+i);
  card.className='qc-btn-card'+(d.name?' has-name':'');
  // Render codes
  var cl=document.getElementById('codes-'+i);
  if(!d.codes||!d.codes.length){cl.innerHTML='<div style="color:var(--text3);font-size:11px;font-style:italic"><?php echo xlt("No codes added"); ?></div>';return;}
  cl.innerHTML = d.codes.map(function(c,ci){
    var label = c.label || c.code || '';
    var value = c.value || '';

    return '<div class="code-chip">'
      + '<div style="display:flex;flex-direction:column">'
      + '<span class="code-chip-label">' + esc(label) + '</span>'
      + '</div>'
      + '<button class="code-chip-del" onclick="removeCode('+i+','+ci+')" title="Remove">✕</button>'
      + '</div>';
}).join('');
}

function onNameInput(i){
  DATA[i].name=document.getElementById('btn-name-'+i).value;
  renderSlot(i);setStatus('<?php echo xlt("Unsaved changes"); ?>',true);
}

function addCode(i){
  var input=document.getElementById('code-input-'+i);
  var code=input.value.trim().toUpperCase();
  if(!code)return;
  // Check if already added
  if(DATA[i].codes.some(function(c){return c.code===code;})){input.value='';return;}
  DATA[i].codes.push({code:code,text:''});
  input.value='';
  renderSlot(i);setStatus('<?php echo xlt("Unsaved changes"); ?>',true);
}

function removeCode(i,ci){
  DATA[i].codes.splice(ci,1);
  renderSlot(i);setStatus('<?php echo xlt("Unsaved changes"); ?>',true);
}

function clearBtn(i){
  if(!DATA[i].name&&!DATA[i].codes.length)return;
  if(!confirm('<?php echo xlt("Clear button"); ?> '+i+'?'))return;
  DATA[i]={name:'',codes:[]};
  renderSlot(i);setStatus('<?php echo xlt("Unsaved changes"); ?>',true);
}

function saveAll(){
  var btn=document.getElementById('btn-save');btn.disabled=true;
  var buttons=[];
  for(var i=1;i<=10;i++){
    if(DATA[i].name) buttons.push({name:DATA[i].name,codes:DATA[i].codes});
  }
  var fd=new FormData();
  fd.append('csrf_token_form',CSRF);
  fd.append('buttons',JSON.stringify(buttons));
  var xhr=new XMLHttpRequest();xhr.open('POST','',true);
  xhr.onreadystatechange=function(){
    if(xhr.readyState!==4)return;btn.disabled=false;
    var r;try{r=JSON.parse(xhr.responseText);}catch(e){return;}
    if(r.success){setStatus('<?php echo xlt("Saved successfully"); ?>',false);showToast('<?php echo xlt("Quick charge buttons saved."); ?>');}
    else{showToast(r.error||'Error','err');}
  };xhr.send(fd);
}

function setStatus(msg,dirty){
  var el=document.getElementById('save-status');
  el.textContent=msg;el.className='save-status'+(dirty?' unsaved':'');_dirty=dirty;
}

function esc(s){if(!s&&s!==0)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function showToast(m,t){var el=document.getElementById('toast');el.textContent=m;el.className='show'+(t?' err':'');clearTimeout(el._t);el._t=setTimeout(function(){el.className='';},2800);}

function updateCode(i,ci,val){
    DATA[i].codes[ci].code = val.toUpperCase().trim();
    setStatus('Unsaved changes',true);
}

// Warn unsaved on page leave
window.addEventListener('beforeunload',function(e){if(_dirty){e.preventDefault();e.returnValue='';}});
function searchCpt(q, slot){

const box = document.getElementById('cpt-results-' + slot);

if(q.length < 2){
    box.style.display = 'none';
    box.innerHTML = '';
    return;
}

fetch('?ajax_cpt=1&q=' + encodeURIComponent(q))
    .then(r => r.json())
    .then(rows => {

        let html = '';

        rows.forEach(function(r){

            html +=
                '<div class="cpt-item" ' +
                'onclick="addCptToSlot(' + slot + ',\'' +
                r.code.replace(/'/g,"\\'") + '\',\'' +
                (r.code_text || '').replace(/'/g,"\\'") +
                '\')">' +
                '<strong>' + r.code + '</strong> - ' +
                r.code_text +
                '</div>';
        });

        box.innerHTML = html;
        box.style.display = rows.length ? 'block' : 'none';
    });
}

function searchCpt(q, slot) {
    var box = document.getElementById('cpt-results-' + slot);

    if (!box) return;

    q = q.trim();

    if (q.length < 2) {
        box.style.display = 'none';
        box.innerHTML = '';
        return;
    }

    fetch('?ajax_cpt=1&q=' + encodeURIComponent(q))
        .then(function(r) { return r.json(); })
        .then(function(rows) {
            var html = '';

            rows.forEach(function(r) {
                var code = r.code || '';
                var text = r.code_text || '';

                html += '<div class="cpt-item" onclick="addCptToSlot('
                    + slot + ', '
                    + '\'' + code.replace(/'/g, "\\'") + '\', '
                    + '\'' + text.replace(/'/g, "\\'") + '\')">'
                    + '<strong>' + esc(code) + '</strong> - '
                    + esc(text)
                    + '</div>';
            });

            box.innerHTML = html || '<div class="cpt-item">No results</div>';
            box.style.display = 'block';
        });
}

function addCptToSlot(slot, code, desc) {
    var label = code + (desc ? ' ' + desc : '');
    var value = 'CPT4|' + code + '|';

    if (!DATA[slot].codes) DATA[slot].codes = [];

    var exists = DATA[slot].codes.some(function(c) {
        return c.value === value;
    });

    if (!exists) {
        DATA[slot].codes.push({
            label: label,
            value: value
        });
    }

    document.getElementById('cpt-results-' + slot).style.display = 'none';

    renderSlot(slot);
    setStatus('Unsaved changes', true);
}
loadTemplates();
</script>
<div id="cpt-modal" class="modal-overlay" style="display:none;">

</div>
</body></html>
