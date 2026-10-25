<?php
/**
 * MDiTek Imaging Viewer
 * interface/patient_file/encounter/mditek_imaging_viewer.php
 */

require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/patient.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('patients', 'med')) {
    echo (new OpenEMR\Common\Twig\TwigContainer(null, $GLOBALS['kernel']))
        ->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => 'Imaging Viewer']);
    exit;
}

$pid       = (int)($_REQUEST['pid']       ?? 0);
$encounter = (int)($_REQUEST['encounter'] ?? 0);
if (!$pid) { die("No patient selected."); }

$patient     = sqlQuery("SELECT fname, lname, DOB FROM patient_data WHERE pid = ?", [$pid]);
$patientName = trim(($patient['fname'] ?? '') . ' ' . ($patient['lname'] ?? ''));

// Load documents
$docs = [];
$res  = sqlStatement(
    "SELECT id, name, url, mimetype, size, date, docdate
       FROM documents
      WHERE foreign_id   = ?
        AND encounter_id = ?
        AND deleted      = 0
        AND type         = 'file_url'
      ORDER BY docdate DESC, date DESC, id ASC",
    [$pid, $encounter]
);
while ($row = sqlFetchArray($res)) {
    $mt  = strtolower((string)$row['mimetype']);
    $ext = strtolower(pathinfo((string)$row['name'], PATHINFO_EXTENSION));
    if ((strpos($mt,'image/')===0) || $mt==='application/pdf' || $mt==='application/dicom'
        || in_array($ext,['jpg','jpeg','png','gif','bmp','webp','dcm','pdf'],true)) {
        $docs[] = $row;
    }
}

// Group into studies/series
function miv_series_name(string $n): string {
    $b = pathinfo($n, PATHINFO_FILENAME);
    $b = trim(preg_replace('/[\s_\-]*\(?\d+\)?$/', '', $b));
    return $b !== '' ? $b : pathinfo($n, PATHINFO_FILENAME);
}
$studies = [];
foreach ($docs as $d) {
    $date   = $d['docdate'] ?: substr((string)$d['date'],0,10);
    if (!$date||$date==='0000-00-00') { $date='Unknown'; }
    $series = miv_series_name($d['name']);
    $studies[$date]['series'][$series][] = $d;
}
uksort($studies, fn($a,$b)=>$a==='Unknown'?1:($b==='Unknown'?-1:strcmp($b,$a)));

$csrf    = CsrfUtils::collectCsrfToken();
$webroot = rtrim($GLOBALS['webroot'],'/');

function miv_doc_url(int $id, int $pid, string $csrf): string {
    global $webroot;
    return $webroot.'/interface/patient_file/encounter/mditek_imaging_file.php?id='.urlencode($id);
}

$jsDocs = [];
foreach ($docs as $d) {
    $id = (int)$d['id'];
    if ($id <= 0) { continue; }   // skip broken rows with id=0
    $jsDocs[$id] = [
        'id'       => $id,
        'name'     => $d['name'],
        'mimetype' => $d['mimetype'],
        'size'     => (int)$d['size'],
        'docdate'  => $d['docdate'] ?: substr((string)$d['date'],0,10),
        'url'      => miv_doc_url($id,$pid,$csrf),
        'is_pdf'   => strtolower((string)$d['mimetype'])==='application/pdf',
    ];
}

$jsStudies = [];
foreach ($studies as $date => $study) {
    $sa = [];
    foreach ($study['series'] as $sName => $images) {
        $ids = array_values(array_filter(array_map(fn($i)=>(int)$i['id'],$images), fn($id)=>$id>0));
        if (empty($ids)) { continue; }
        $sa[] = ['name'=>$sName,'image_ids'=>$ids];
    }
    if (!empty($sa)) { $jsStudies[] = ['date'=>$date,'series'=>$sa]; }
}

$epBase = $webroot.'/interface/patient_file/encounter';

// Load existing findings
$existingFindings = '';
try {
    $frow = sqlQuery("SELECT findings FROM form_mditek_imaging_findings WHERE pid=? AND encounter=? AND deleted=0 LIMIT 1",[$pid,$encounter]);
    if (!empty($frow['findings'])) { $existingFindings = $frow['findings']; }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html>
<head>
<?php Header::setupHeader(['common']); ?>
<title>Imaging Viewer</title>
<style>
:root{--bg:#0c0c14;--panel:#15151f;--panel2:#1c1c29;--toolbar:#1a1a26;--accent:#6c5ce7;--asoft:#3a3554;--txt:#f1f1f5;--dim:#9a9aab;--border:#2a2a3a;}
*,*::before,*::after{box-sizing:border-box;}
html,body{height:100%;margin:0;padding:0;overflow:hidden;}
body{background:var(--bg);color:var(--txt);font:13px -apple-system,Segoe UI,Roboto,Arial,sans-serif;}
.wrap{display:flex;height:100vh;width:100vw;}
/* Left */
.left{width:220px;flex-shrink:0;background:var(--panel);border-right:1px solid var(--border);overflow-y:auto;padding:10px;}
.left h3{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);margin:4px 0 8px;}
.study{margin-bottom:5px;border-radius:5px;overflow:hidden;}
.study-hd{display:flex;align-items:center;gap:6px;padding:7px 8px;cursor:pointer;background:var(--panel2);border-radius:5px;transition:background .15s;}
.study-hd:hover{background:var(--asoft);}
.chev{font-size:10px;color:var(--dim);transition:transform .15s;}
.study.open .chev{transform:rotate(90deg);}
.study-date{font-weight:600;font-size:12px;}
.study-sub{font-size:11px;color:var(--dim);}
.series-list{display:none;padding:3px 0 3px 14px;}
.study.open .series-list{display:block;}
.series-item{display:flex;justify-content:space-between;align-items:center;padding:5px 7px;border-radius:4px;cursor:pointer;margin:2px 0;}
.series-item:hover{background:var(--asoft);}
.series-item.on{background:var(--accent);color:#fff;}
.series-ct{font-size:11px;color:var(--dim);}
.series-item.on .series-ct{color:#e0daff;}
/* Center */
.center{flex:1;display:flex;flex-direction:column;min-width:0;}
.hdr{background:var(--toolbar);border-bottom:1px solid var(--border);padding:8px 14px;flex-shrink:0;}
.hdr .pname{font-weight:700;font-size:14px;}
.hdr .pmeta{color:var(--dim);font-size:11px;}
.toolbar{background:var(--toolbar);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:3px;padding:5px 8px;flex-wrap:wrap;flex-shrink:0;}
.tbtn{display:flex;flex-direction:column;align-items:center;gap:2px;background:none;border:none;color:var(--dim);font-size:10px;padding:5px 8px;border-radius:5px;cursor:pointer;min-width:44px;}
.tbtn i{font-size:14px;}
.tbtn:hover{background:var(--panel2);color:var(--txt);}
.tbtn.on{background:var(--accent);color:#fff;}
.tsep{width:1px;background:var(--border);align-self:stretch;margin:0 3px;}
.tspacer{flex:1;}
.exp-btn{background:var(--panel2);color:var(--txt);border:1px solid var(--border);border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer;text-decoration:none;}
.exp-btn:hover{background:var(--asoft);}
.viewport{flex:1;background:#000;overflow:hidden;position:relative;min-height:0;}
#miv-cell{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;cursor:grab;border:1px solid var(--border);overflow:hidden;}
#miv-cell.dragging{cursor:grabbing;}
#cell-img{display:block;max-width:100%;max-height:100%;width:auto;height:auto;object-fit:contain;user-select:none;-webkit-user-drag:none;transform-origin:center center;}
#cell-pdf{display:block;width:95%;height:95%;background:#fff;transform-origin:center center;}
.cell-overlay{position:absolute;pointer-events:none;z-index:5;font-size:12px;}
.cell-tl{top:8px;left:10px;font-weight:600;text-shadow:0 1px 3px #000;line-height:1.4;}
.cell-tr{top:8px;right:10px;color:#cfcfe2;text-shadow:0 1px 3px #000;text-align:right;}
.cell-bl{bottom:6px;left:10px;color:#cfcfe2;text-shadow:0 1px 3px #000;}
.empty-msg{color:var(--dim);}
#ann-canvas{position:absolute;inset:0;z-index:20;pointer-events:none;}
/* Thumbstrip */
.thumbstrip{background:var(--panel);border-top:1px solid var(--border);display:flex;align-items:center;gap:8px;padding:6px 10px;flex-shrink:0;}
.ts-label .name{font-weight:600;font-size:12px;}
.ts-label .count{color:var(--dim);font-size:11px;}
.ts-track{flex:1;display:flex;gap:6px;overflow-x:auto;padding:2px;}
.thumb{width:58px;height:58px;border-radius:4px;border:2px solid var(--border);flex-shrink:0;cursor:pointer;background:#000;display:flex;align-items:center;justify-content:center;}
.thumb img{width:100%;height:100%;object-fit:cover;}
.thumb.on{border-color:var(--accent);}
.pdf-lbl{font-size:10px;color:var(--dim);}
.thumb-del{display:none;position:absolute;top:-5px;right:-5px;z-index:10;width:16px;height:16px;border-radius:50%;background:#c0392b;color:#fff;border:none;cursor:pointer;font-size:11px;align-items:center;justify-content:center;padding:0;line-height:1;}
.thumb-wrap:hover .thumb-del{display:flex;}
.thumb-wrap{position:relative;flex-shrink:0;}
.nav-btn{background:var(--panel2);border:1px solid var(--border);color:var(--txt);border-radius:5px;width:26px;height:26px;cursor:pointer;font-size:16px;flex-shrink:0;}
.nav-btn:hover{background:var(--asoft);}
/* Right */
.right{width:240px;flex-shrink:0;background:var(--panel);border-left:1px solid var(--border);overflow-y:auto;padding:12px;}
.right h3{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--dim);margin:12px 0 6px;}
.right h3:first-child{margin-top:0;}
.info-row{display:flex;justify-content:space-between;padding:3px 0;font-size:12px;border-bottom:1px solid var(--border);}
.info-row .lbl{color:var(--dim);}
.info-row .val{text-align:right;max-width:130px;word-break:break-word;}
.empty-dim{color:var(--dim);font-size:12px;}
textarea.findings{width:100%;min-height:130px;margin-top:4px;background:var(--panel2);color:var(--txt);border:1px solid var(--border);border-radius:5px;padding:7px;font-size:12px;resize:vertical;}
.btn{width:100%;margin-top:7px;padding:8px;background:var(--panel2);border:1px solid var(--border);color:var(--txt);border-radius:5px;cursor:pointer;font-size:12px;text-align:center;text-decoration:none;display:block;}
.btn:hover{background:var(--asoft);}
.btn.on{background:var(--accent);color:#fff;border-color:var(--accent);}
.btn:disabled{opacity:.5;cursor:not-allowed;}
/* Toast */
#toast{position:fixed;bottom:20px;right:20px;z-index:9999;background:#27ae60;color:#fff;padding:9px 16px;border-radius:7px;font-size:13px;font-weight:600;box-shadow:0 4px 14px rgba(0,0,0,.4);opacity:0;pointer-events:none;transition:opacity .25s;}
#toast.err{background:#e74c3c;}
#toast.show{opacity:1;}
</style>
</head>
<body>
<div class="wrap">

<!-- LEFT -->
<div class="left">
  <h3>Imaging Studies</h3>
  <?php if (empty($jsStudies)): ?>
    <div class="empty-dim">No images for this encounter.</div>
  <?php else: foreach ($jsStudies as $si => $study): ?>
    <div class="study<?= $si===0?' open':'' ?>" data-si="<?= $si ?>">
      <div class="study-hd" onclick="toggleStudy(<?= $si ?>)">
        <span class="chev">&#9656;</span>
        <div>
          <div class="study-date"><?= htmlspecialchars($study['date']!=='Unknown'?date('m/d/Y',strtotime($study['date'])):'Unknown') ?></div>
          <div class="study-sub"><?= count($study['series']) ?> series &bull; <?= array_sum(array_map(fn($s)=>count($s['image_ids']),$study['series'])) ?> images</div>
        </div>
      </div>
      <div class="series-list">
        <?php foreach ($study['series'] as $ri => $series): ?>
          <div class="series-item" data-si="<?= $si ?>" data-ri="<?= $ri ?>"
               onclick="selectSeries(<?= $si ?>,<?= $ri ?>)">
            <span><?= htmlspecialchars($series['name']) ?></span>
            <span class="series-ct"><?= count($series['image_ids']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; endif; ?>
</div>

<!-- CENTER -->
<div class="center">
  <div class="hdr">
    <div class="pname"><?= htmlspecialchars($patientName) ?> <span style="color:var(--dim);font-weight:400">(<?= (int)$pid ?>)</span></div>
    <div class="pmeta">DOB: <?= htmlspecialchars($patient['DOB']??'') ?> &nbsp;|&nbsp; Encounter: <?= $encounter?:'—' ?></div>
  </div>

  <div class="toolbar">
    <button class="tbtn" onclick="fitImage()"><i class="fa fa-expand"></i>Fit</button>
    <button class="tbtn" onclick="resetView()"><i class="fa fa-refresh"></i>Reset</button>
    <div class="tsep"></div>
    <button class="tbtn" onclick="rotateDeg(-90)"><i class="fa fa-undo"></i>Rotate L</button>
    <button class="tbtn" onclick="rotateDeg(90)"><i class="fa fa-repeat"></i>Rotate R</button>
    <button class="tbtn" id="btn-invert" onclick="toggleInvert()"><i class="fa fa-adjust"></i>Invert</button>
    <div class="tsep"></div>
    <button class="tbtn on" id="btn-pan" onclick="setMode('pan')"><i class="fa fa-arrows"></i>Pan</button>
    <button class="tbtn" id="btn-zoom" onclick="setMode('zoom')"><i class="fa fa-search"></i>Zoom</button>
    <div class="tspacer"></div>
    <a class="exp-btn" id="exp-link" href="#" target="_blank">Export</a>
  </div>

  <div class="viewport" id="viewport">
    <div id="miv-cell">
      <span class="empty-msg" id="cell-empty">Select a series from the left panel</span>
      <img id="cell-img" src="" draggable="false" style="display:none;">
      <embed id="cell-pdf" src="" type="application/pdf" style="display:none;">
      <canvas id="ann-canvas"></canvas>
      <div class="cell-overlay cell-tl" id="cell-lbl"></div>
      <div class="cell-overlay cell-tr" id="cell-meta"></div>
      <div class="cell-overlay cell-bl" id="cell-zoom-lbl">Zoom: 1.00</div>
    </div>
  </div>

  <div class="thumbstrip">
    <div class="ts-label">
      <div class="name"  id="ts-name">No series selected</div>
      <div class="count" id="ts-count">0 images</div>
    </div>
    <button class="nav-btn" onclick="scrollThumbs(-1)">&#8249;</button>
    <div class="ts-track" id="ts-track"></div>
    <button class="nav-btn" onclick="scrollThumbs(1)">&#8250;</button>
  </div>
</div>

<!-- RIGHT -->
<div class="right">
  <h3>Document Info</h3>
  <div id="doc-info"><div class="empty-dim">No image selected.</div></div>

  <h3>Encounter Findings</h3>
  <textarea class="findings" id="findings" placeholder="Document imaging findings..."><?= htmlspecialchars($existingFindings) ?></textarea>
  <button class="btn" id="btn-save-findings" onclick="saveFindings()">Save Findings</button>

  <h3>Actions</h3>
  <input type="file" id="upload-input" hidden
         accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.dcm,.dicom"
         onchange="doUpload(this)">
  <label for="upload-input" class="btn" id="upload-label" style="cursor:pointer;">⬆ Upload Image</label>

  <button class="btn" id="btn-annotate" onclick="toggleAnnotate()">✏ Annotate</button>
  <button class="btn" id="btn-save-ann" onclick="saveAnnotation()">💾 Save Annotation</button>

  <a href="#" class="btn" id="dl-btn" target="_blank">⬇ Download</a>
  <a href="../summary/demographics.php?set_pid=<?= (int)$pid ?>" class="btn" target="_top" style="margin-top:14px;">← Back to Encounter</a>
</div>

</div>
<div id="toast"></div>

<script>
const DOCS    = <?= json_encode($jsDocs,    JSON_UNESCAPED_SLASHES) ?>;
const STUDIES = <?= json_encode($jsStudies, JSON_UNESCAPED_SLASHES) ?>;
const PID     = <?= (int)$pid ?>;
const ENC     = <?= (int)$encounter ?>;
const CSRF    = <?= json_encode($csrf) ?>;
const EP_UPLOAD = <?= json_encode($epBase.'/mditek_imaging_upload.php') ?>;
const EP_FIND   = <?= json_encode($epBase.'/mditek_save_findings.php') ?>;
const EP_ANN    = <?= json_encode($epBase.'/mditek_save_annotations.php') ?>;
const EP_DEL    = <?= json_encode($epBase.'/mditek_delete_doc.php') ?>;

// State
let curStudy=0, curSeries=0, imageIds=[], curIdx=0;
let zoom=1, rot=0, panX=0, panY=0, inverted=false, mode='pan';
let annMode=false, annPaths=[], drawing=false, curPath=[];

// Toast
function toast(msg, err=false){
    const el=document.getElementById('toast');
    el.textContent=msg; el.className='show'+(err?' err':'');
    clearTimeout(el._t); el._t=setTimeout(()=>el.className='',3000);
}

// Study/series
function toggleStudy(si){
    const el=document.querySelector('.study[data-si="'+si+'"]');
    if(el) el.classList.toggle('open');
}

function selectSeries(si, ri){
    curStudy=si; curSeries=ri;
    document.querySelectorAll('.series-item').forEach(e=>e.classList.remove('on'));
    const s=document.querySelector('.series-item[data-si="'+si+'"][data-ri="'+ri+'"]');
    if(s) s.classList.add('on');
    imageIds=STUDIES[si].series[ri].image_ids.slice();
    curIdx=0;
    zoom=1; rot=0; panX=0; panY=0; inverted=false;
    document.getElementById('btn-invert').classList.remove('on');
    loadImage();
    buildThumbs();
    updateDocInfo();
}

// Image
function loadImage(){
    const empty=document.getElementById('cell-empty');
    const img=document.getElementById('cell-img');
    const pdf=document.getElementById('cell-pdf');
    const lbl=document.getElementById('cell-lbl');
    const meta=document.getElementById('cell-meta');

    if(!imageIds.length){
        empty.style.display=''; img.style.display='none'; pdf.style.display='none';
        lbl.innerHTML=''; meta.innerHTML=''; clearCanvas(); return;
    }
    const doc=DOCS[imageIds[curIdx]];
    if(!doc){ empty.style.display=''; empty.textContent='Document not found'; return; }

    // Reset transform when switching images
    applyTransform();
    annPaths = annPaths.filter(p => p.doc_id === imageIds[curIdx]);
    sizeCanvas();
    drawAllAnnotations();

    const sn=STUDIES[curStudy].series[curSeries].name;
    lbl.innerHTML=esc(sn)+'<br><span style="font-weight:400;font-size:11px;color:#cfcfe2">'+esc(doc.name)+'</span>';
    meta.innerHTML=esc(doc.docdate||'')+'<br>'+(curIdx+1)+'/'+imageIds.length;
    document.getElementById('exp-link').href=doc.url;
    document.getElementById('dl-btn').href=doc.url;
    document.getElementById('miv-cell').style.filter=inverted?'invert(1)':'';

    empty.style.display='none';
    if(doc.is_pdf){
        img.style.display='none';
        pdf.style.display='block';
        pdf.src=doc.url;
    } else {
        pdf.style.display='none';
        img.style.display='block';
        img.onerror=()=>{ img.style.display='none'; empty.style.display=''; empty.textContent='⚠ Image failed to load — check document URL'; };
        img.onload=()=>{ applyTransform(); };
        img.src=doc.url;
    }
}

function applyTransform(){
    const t='translate('+panX+'px,'+panY+'px) scale('+zoom+') rotate('+rot+'deg)';
    document.getElementById('cell-img').style.transform=t;
    document.getElementById('cell-pdf').style.transform=t;
    document.getElementById('cell-zoom-lbl').textContent='Zoom: '+zoom.toFixed(2);
}

// Toolbar
function setMode(m){
    mode=m;
    document.getElementById('btn-pan').classList.toggle('on',m==='pan');
    document.getElementById('btn-zoom').classList.toggle('on',m==='zoom');
}
function fitImage(){zoom=1;panX=0;panY=0;applyTransform();}
function resetView(){zoom=1;rot=0;panX=0;panY=0;inverted=false;document.getElementById('btn-invert').classList.remove('on');applyTransform();document.getElementById('miv-cell').style.filter='';}
function rotateDeg(d){rot=(rot+d)%360;applyTransform();}
function toggleInvert(){inverted=!inverted;document.getElementById('btn-invert').classList.toggle('on',inverted);document.getElementById('miv-cell').style.filter=inverted?'invert(1)':'';}

// Pan/Zoom interactions
(function(){
    const cell=document.getElementById('miv-cell');
    let drag=false,lx=0,ly=0;
    cell.addEventListener('mousedown',e=>{if(e.button!==0||annMode)return;drag=true;lx=e.clientX;ly=e.clientY;cell.classList.add('dragging');});
    window.addEventListener('mouseup',()=>{drag=false;cell.classList.remove('dragging');});
    window.addEventListener('mousemove',e=>{
        if(!drag)return;
        const dx=e.clientX-lx,dy=e.clientY-ly;lx=e.clientX;ly=e.clientY;
        if(mode==='zoom'){zoom=Math.max(0.05,zoom+dy*-0.01);}else{panX+=dx;panY+=dy;}
        applyTransform();
    });
    cell.addEventListener('wheel',e=>{e.preventDefault();zoom=Math.max(0.05,Math.min(10,zoom+(e.deltaY<0?0.1:-0.1)));applyTransform();},{passive:false});
    cell.addEventListener('dblclick',resetView);
})();

// Thumbstrip
function buildThumbs(){
    const track=document.getElementById('ts-track');
    track.innerHTML='';
    const s=STUDIES[curStudy].series[curSeries];
    document.getElementById('ts-name').textContent=s.name;
    document.getElementById('ts-count').textContent=s.image_ids.length+' image'+(s.image_ids.length===1?'':'s');
    s.image_ids.forEach((docId,idx)=>{
        const doc=DOCS[docId];
        const wrap=document.createElement('div'); wrap.className='thumb-wrap';
        const th=document.createElement('div'); th.className='thumb'+(idx===curIdx?' on':'');
        th.innerHTML=doc.is_pdf?'<span class="pdf-lbl">PDF</span>':'<img src="'+doc.url+'" loading="lazy">';
        th.onclick=()=>{curIdx=idx;loadImage();syncThumb();updateDocInfo();};
        const del=document.createElement('button'); del.className='thumb-del'; del.title='Remove'; del.textContent='×';
        del.onclick=e=>{e.stopPropagation();deleteDoc(docId);};
        wrap.appendChild(th); wrap.appendChild(del); track.appendChild(wrap);
    });
}
function syncThumb(){document.querySelectorAll('.thumb').forEach((el,i)=>el.classList.toggle('on',i===curIdx));}
function scrollThumbs(dir){document.getElementById('ts-track').scrollBy({left:dir*180,behavior:'smooth'});}

// Doc info
function updateDocInfo(){
    const el=document.getElementById('doc-info');
    if(!imageIds.length){el.innerHTML='<div class="empty-dim">No image selected.</div>';return;}
    const doc=DOCS[imageIds[curIdx]]; if(!doc)return;
    const kb=doc.size?(doc.size/1024).toFixed(1)+' KB':'—';
    el.innerHTML='<div class="info-row"><span class="lbl">File</span><span class="val">'+esc(doc.name)+'</span></div>'
        +'<div class="info-row"><span class="lbl">Type</span><span class="val">'+esc(doc.mimetype||'—')+'</span></div>'
        +'<div class="info-row"><span class="lbl">Size</span><span class="val">'+kb+'</span></div>'
        +'<div class="info-row"><span class="lbl">Date</span><span class="val">'+esc(doc.docdate||'—')+'</span></div>';
}

// Annotation canvas
function sizeCanvas(){
    const cell=document.getElementById('miv-cell');
    const c=document.getElementById('ann-canvas');
    c.width=cell.clientWidth; c.height=cell.clientHeight;
    const ctx=c.getContext('2d'); ctx.lineWidth=2; ctx.strokeStyle='#ff5252';
}
function clearCanvas(){
    const c=document.getElementById('ann-canvas');
    c.getContext('2d').clearRect(0,0,c.width,c.height);
}
function drawAllAnnotations(){
    const c=document.getElementById('ann-canvas');
    const ctx=c.getContext('2d');
    ctx.clearRect(0,0,c.width,c.height);
    ctx.lineWidth=2; ctx.strokeStyle='#ff5252';
    // Draw all saved paths for this image
    annPaths.forEach(path=>{
        if(!path.points||path.points.length<2) return;
        ctx.beginPath();
        path.points.forEach((p,i)=>i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y));
        ctx.stroke();
    });
    // Draw the in-progress stroke
    if(drawing&&curPath.length>1){
        ctx.beginPath();
        curPath.forEach((p,i)=>i===0?ctx.moveTo(p.x,p.y):ctx.lineTo(p.x,p.y));
        ctx.stroke();
    }
}
function toggleAnnotate(){
    annMode=!annMode;
    const c=document.getElementById('ann-canvas');
    c.style.pointerEvents=annMode?'auto':'none';
    c.style.cursor=annMode?'crosshair':'';
    document.getElementById('btn-annotate').classList.toggle('on',annMode);
    toast(annMode?'Annotation ON — draw on image':'Annotation OFF');
}
(function(){
    const c=document.getElementById('ann-canvas');
    c.addEventListener('mousedown',e=>{
        if(!annMode)return;
        drawing=true; curPath=[];
        const r=c.getBoundingClientRect();
        curPath.push({x:e.clientX-r.left,y:e.clientY-r.top});
    });
    c.addEventListener('mousemove',e=>{
        if(!annMode||!drawing)return;
        const r=c.getBoundingClientRect();
        curPath.push({x:e.clientX-r.left,y:e.clientY-r.top});
        drawAllAnnotations();
    });
    window.addEventListener('mouseup',()=>{
        if(!drawing)return;
        drawing=false;
        if(imageIds.length&&curPath.length>1){
            annPaths.push({doc_id:imageIds[curIdx],points:curPath});
        }
        curPath=[];
        drawAllAnnotations();
    });
})();

// Helpers
function esc(s){return(s||'').toString().replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

async function apiPost(url, data){
    const fd=new FormData();
    fd.append('csrf_token_form',CSRF); fd.append('pid',PID); fd.append('encounter',ENC);
    for(const[k,v]of Object.entries(data))fd.append(k,v);
    const r=await fetch(url,{method:'POST',body:fd});
    if(!r.ok) throw new Error('HTTP '+r.status);
    const txt=await r.text();
    let json; try{json=JSON.parse(txt);}catch(e){throw new Error('Non-JSON: '+txt.slice(0,200));}
    if(!json.success) throw new Error(json.error||'Failed');
    return json;
}

function reload(){
    const u=new URL(window.location.href);
    u.searchParams.set('pid',PID); u.searchParams.set('encounter',ENC);
    window.location.replace(u.toString());
}

// Upload
async function doUpload(input){
    if(!input.files||!input.files.length)return;
    const lbl=document.getElementById('upload-label');
    lbl.textContent='Uploading…'; lbl.style.opacity='.6';
    try{
        const fd=new FormData();
        fd.append('csrf_token_form',CSRF); fd.append('pid',PID); fd.append('encounter',ENC);
        fd.append('image_file',input.files[0]);
        const r=await fetch(EP_UPLOAD,{method:'POST',body:fd});
        if(!r.ok) throw new Error('HTTP '+r.status);
        const txt=await r.text();
        let json; try{json=JSON.parse(txt);}catch(e){throw new Error('Non-JSON: '+txt.slice(0,200));}
        if(!json.success) throw new Error(json.error||'Upload failed');
        toast('Uploaded! Reloading…'); setTimeout(reload,800);
    }catch(e){
        lbl.textContent='⬆ Upload Image'; lbl.style.opacity=''; input.value='';
        toast('Upload failed: '+e.message,true);
    }
}

// Save findings
async function saveFindings(){
    const findings=document.getElementById('findings').value.trim();
    if(!findings){toast('Findings are empty',true);return;}
    const btn=document.getElementById('btn-save-findings');
    btn.textContent='Saving…'; btn.disabled=true;
    try{await apiPost(EP_FIND,{findings}); toast('Findings saved ✓');}
    catch(e){toast('Save failed: '+e.message,true);}
    finally{btn.textContent='Save Findings'; btn.disabled=false;}
}

// Save annotation
async function saveAnnotation(){
    if(!annPaths.length){toast('Nothing drawn yet',true);return;}
    const btn=document.getElementById('btn-save-ann');
    btn.textContent='Saving…'; btn.disabled=true;
    try{
        const j=await apiPost(EP_ANN,{annotation_json:JSON.stringify(annPaths)});
        toast(j.message||'Annotation saved ✓'); annPaths=[];
    }catch(e){toast('Save failed: '+e.message,true);}
    finally{btn.textContent='💾 Save Annotation'; btn.disabled=false;}
}

// Delete
async function deleteDoc(docId){
    if(!confirm('Remove this image?'))return;
    try{await apiPost(EP_DEL,{document_id:docId}); toast('Removed'); setTimeout(reload,800);}
    catch(e){toast('Delete failed: '+e.message,true);}
}

// Init
window.addEventListener('DOMContentLoaded',()=>{
    sizeCanvas();
    if(STUDIES.length>0&&STUDIES[0].series.length>0) selectSeries(0,0);
    window.addEventListener('resize',()=>{ sizeCanvas(); drawAllAnnotations(); });
});
</script>
</body>
</html>
