<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
$sessionAllowWrite = true;
$ignoreAuth = 1;

foreach ([__DIR__.'/../globals.php', __DIR__.'/../../globals.php', __DIR__.'/../../../globals.php'] as $_p) {
    if (file_exists($_p)) { require_once($_p); break; }
}

// ── Handle manual file upload (bypasses browser limit with chunked approach) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['icd_file'])) {
    header('Content-Type: application/json');
    $f = $_FILES['icd_file'];

    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        echo json_encode(['success' => false, 'error' =>
            'File too large for PHP upload limit. Use the "Download from CDC" button instead, ' .
            'or ask your server admin to increase upload_max_filesize in php.ini.']);
        exit;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'Upload error code: ' . $f['error']]);
        exit;
    }

// Detect year dynamically
$detected = glob(__DIR__ . '/icd10cm_codes_*.txt');
$icdYear  = (int) date('Y');
if ($detected && preg_match('/icd10cm_codes_(\d{4})\.txt$/', $detected[0], $m)) {
    $icdYear = (int) $m[1];
}
// For upload destination we use next year if no file exists yet, else detected year
$uploadYear = $icdYear ?: (int) date('Y');

$dest = __DIR__ . '/icd10cm_codes_' . $uploadYear . '.txt';

    // Handle .zip upload too
    $name = $f['name'];
    if (strtolower(substr($name, -4)) === '.zip') {
        $tmpZip = $f['tmp_name'];
        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            echo json_encode(['success' => false, 'error' => 'Could not open ZIP file.']);
            exit;
        }
        $found = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zname = $zip->getNameIndex($i);
            if (stripos($zname, 'icd10cm_codes') !== false && substr($zname,-4)==='.txt') {
                copy('zip://'.$tmpZip.'#'.$zname, $dest);
                $found = true; break;
            }
        }
        $zip->close();
        if (!$found) {
            echo json_encode(['success' => false, 'error' => 'No icd10cm_codes*.txt found inside ZIP.']);
            exit;
        }
    } else {
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            echo json_encode(['success' => false, 'error' => 'Could not save file. Check server permissions on ' . __DIR__]);
            exit;
        }
    }

    $lines = count(file($dest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    echo json_encode(['success' => true, 'lines' => $lines]);
    exit;
}

// Detect year for display
$detectedFiles = glob(__DIR__ . '/icd10cm_codes_*.txt');
$displayYear   = (int) date('Y');
if ($detectedFiles && preg_match('/icd10cm_codes_(\d{4})\.txt$/', $detectedFiles[0], $ym)) {
    $displayYear = (int) $ym[1];
}
$icdTxtFile   = __DIR__ . '/icd10cm_codes_' . $displayYear . '.txt';
$fileExists   = file_exists($icdTxtFile);
$fileSizeText = $fileExists ? round(filesize($icdTxtFile)/1048576,1).' MB' : '';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>ICD-10 Updater</title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,sans-serif;padding:24px;background:#f5f5f5;margin:0;font-size:14px}
h2{color:#1e0a3c;margin:0 0 20px}
.card{background:#fff;border-radius:10px;padding:20px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}
.card-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#6b7280;margin-bottom:12px}

/* Status */
.status{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.status.ok  {background:#f0fdf4;border:1px solid #86efac;color:#166534}
.status.miss{background:#fff7ed;border:1px solid #fed7aa;color:#92400e}

/* Upload zone */
.upload-zone{border:2px dashed #c4b5e8;border-radius:10px;padding:32px;background:#faf5ff;text-align:center;cursor:pointer;transition:all .2s}
.upload-zone:hover,.upload-zone.drag{border-color:#7c3aed;background:#f3e8ff}
.upload-zone input[type=file]{display:none}
.upload-zone .uz-icon{color:#7c3aed;margin-bottom:10px}
.upload-zone .uz-main{font-size:14px;color:#374151;margin:0}
.upload-zone .uz-hint{font-size:11px;color:#9ca3af;margin:4px 0 0}
.upload-err{color:#dc2626;font-size:12px;margin-top:8px;display:none}

/* OR divider */
.or-row{display:flex;align-items:center;gap:12px;margin:16px 0}
.or-row hr{flex:1;border:none;border-top:1px solid #e5e7eb}
.or-row span{font-size:12px;color:#9ca3af;font-weight:600}

/* Buttons */
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 18px;font-size:14px;font-weight:600;border:none;border-radius:7px;cursor:pointer;font-family:inherit;transition:background .15s}
.btn-cdc   {background:#eff6ff;color:#1d4ed8;border:1.5px solid #bfdbfe;width:100%;justify-content:center;padding:13px}
.btn-cdc:hover{background:#dbeafe}
.btn-run   {background:#1e0a3c;color:#fff}
.btn-run:hover{background:#3b1d6e}
.btn-run:disabled{background:#9ca3af;cursor:not-allowed}
.btn-cancel{background:#fee2e2;color:#dc2626}
.btn-cancel:hover{background:#fecaca}
.btn-retry {background:#fffbeb;color:#d97706}
.btn-retry:hover{background:#fef3c7}
.btns{display:flex;gap:8px;flex-wrap:wrap}

/* Delete button in status bar */
.btn-delete{background:#fee2e2;color:#dc2626;border:1px solid #fca5a5;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:background .15s;margin-left:6px}
.btn-delete:hover{background:#fecaca}

/* Animated dots */
@keyframes dot-pulse{0%,80%,100%{opacity:0}40%{opacity:1}}
.dots span{animation:dot-pulse 1.4s infinite;display:inline-block}
.dots span:nth-child(2){animation-delay:.2s}
.dots span:nth-child(3){animation-delay:.4s}

/* Progress */
#prog-wrap{width:100%;background:#e5e7eb;border-radius:8px;height:28px;overflow:hidden;margin-top:0}
#prog-bar{height:100%;width:0%;background:#16a34a;text-align:center;color:#fff;line-height:28px;font-size:12px;font-weight:600;transition:width .3s}
#prog-bar.err{background:#dc2626}

/* Log */
#log{background:#111;color:#4ade80;padding:12px;height:200px;overflow-y:auto;font-family:monospace;font-size:12px;border-radius:8px;line-height:1.7;margin-top:0}
#log .e{color:#f87171}
#log .w{color:#fbbf24}
</style>
</head>
<body>
<h2>⚕ ICD-10 Code Updater</h2>

<!-- File status -->
<div class="status <?php echo $fileExists ? 'ok' : 'miss'; ?>">
  <?php if ($fileExists): ?>
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    icd10cm_codes_<?php echo $displayYear; ?>.txt ready — <?php echo $fileSizeText; ?>  <button
        class="btn-delete"
        onclick="deleteTxtFile()">
        🗑 Delete
    </button>
  <?php else: ?>
    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    No ICD-10 file found — download from CDC or upload below
  <?php endif; ?>
</div>

<!-- Get the file -->
<div class="card">
  <div class="card-title">Step 1 — Get the ICD-10 File</div>

  <!-- CDC download (server-side, no size limit) -->
  <button class="btn btn-cdc" id="btnCdc">
    <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Download ICD-10 <?php echo $displayYear; ?> from CDC (recommended)
  </button>

  <div class="or-row"><hr><span>OR</span><hr></div>

  <!-- Manual upload -->
  <div class="upload-zone" id="dropZone" onclick="document.getElementById('fileInput').click()">
    <div class="uz-icon">
      <svg width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
    </div>
    <p class="uz-main"><strong>Click to upload</strong> or drag &amp; drop</p>
    <p class="uz-hint">icd10cm_codes_<?php echo $displayYear; ?>.txt or .zip &mdash; accepts both</p>
    <input type="file" id="fileInput" accept=".txt,.zip">
  </div>
  <div class="upload-err" id="uploadErr"></div>
</div>

<!-- Run -->
<div class="card">
  <div class="card-title">Step 2 — Import to Database</div>
  <div class="btns" style="margin-bottom:14px">
    <button class="btn btn-run"    id="btnRun"    <?php echo !$fileExists ? 'disabled' : ''; ?>>&#9654; Run Update</button>
    <button class="btn btn-cancel" id="btnCancel" style="display:none">&#10005; Cancel</button>
    <button class="btn btn-retry"  id="btnRetry"  style="display:none">&#8635; Retry</button>
  </div>
  <div id="prog-wrap" style="margin-bottom:14px"><div id="prog-bar">0%</div></div>
  <div id="log"></div>
</div>

<script>
var ctrl = null;
var _dotsInterval = null;

function addLog(msg, cls) {
    var el = document.getElementById('log');
    var d  = document.createElement('div');
    if (cls) d.className = cls;
    d.textContent = msg;
    el.appendChild(d);
    el.scrollTop = el.scrollHeight;
}
function addLogAnimated(msg) {
    if (_dotsInterval) { clearInterval(_dotsInterval); _dotsInterval = null; }
    var el = document.getElementById('log');
    var d  = document.createElement('div');
    d.id = 'log-animating';
    d.innerHTML = msg + '<span class="dots"><span>.</span><span>.</span><span>.</span></span>';
    el.appendChild(d);
    el.scrollTop = el.scrollHeight;
}
function clearAnimatedLog() {
    var d = document.getElementById('log-animating');
    if (d) d.removeAttribute('id'); // freeze it in place, dots stop
}
function setBar(pct, isErr) {
    var b = document.getElementById('prog-bar');
    b.style.width = pct + '%';
    b.textContent = pct + '%';
    b.className   = isErr ? 'err' : '';
}
function setState(s) {
    document.getElementById('btnRun').style.display    = (s==='idle'||s==='done') ? '' : 'none';
    document.getElementById('btnRun').disabled         = (s==='no-file');
    document.getElementById('btnCancel').style.display = (s==='running') ? '' : 'none';
    document.getElementById('btnRetry').style.display  = (s==='error')   ? '' : 'none';
}
function markFileReady(label) {
    var s = document.querySelector('.status');
    s.className = 'status ok';
    s.innerHTML = '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' + label;
    document.getElementById('btnRun').disabled = false;
}

// ── Manual upload ─────────────────────────────────────────────────────────────
function uploadFile(file) {
    if (!file) return;
    var errEl = document.getElementById('uploadErr');
    errEl.style.display = 'none';
    addLog('Uploading ' + file.name + ' (' + (file.size/1048576).toFixed(1) + ' MB)...');

    var fd = new FormData();
    fd.append('icd_file', file);

    fetch(window.location.href, { method:'POST', body:fd, credentials:'same-origin' })
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.success) {
                markFileReady('icd10cm_codes_<?php echo $displayYear; ?>.txt ready — ' + d.lines.toLocaleString() + ' codes');
                addLog('✅ Upload complete: ' + d.lines.toLocaleString() + ' codes ready to import.');
            } else {
                errEl.style.display = 'block';
                errEl.textContent   = d.error;
                addLog('❌ Upload failed: ' + d.error, 'e');
            }
        })
        .catch(function(e){ addLog('❌ Upload error: ' + e.message, 'e'); });
}

document.getElementById('fileInput').onchange = function(){ uploadFile(this.files[0]); };
var dz = document.getElementById('dropZone');
dz.addEventListener('dragover',  function(e){ e.preventDefault(); dz.classList.add('drag'); });
dz.addEventListener('dragleave', function(){ dz.classList.remove('drag'); });
dz.addEventListener('drop', function(e){
    e.preventDefault(); dz.classList.remove('drag');
    uploadFile(e.dataTransfer.files[0]);
});

// ── CDC server-side download ──────────────────────────────────────────────────
document.getElementById('btnCdc').onclick = function() {
    document.getElementById('log').innerHTML = '';
    setBar(1, false);
    addLogAnimated('Initializing ICD-10 updater');
    setState('running');
    ctrl = new AbortController();
    streamFrom('run_update.php?mode=download&_=' + Date.now());
};

// ── Run import ────────────────────────────────────────────────────────────────
async function streamFrom(url) {
    try {
        var res = await fetch(url, { signal: ctrl.signal, credentials: 'same-origin' });
        if (!res.ok) {
            var txt = await res.text();
            addLog('HTTP ' + res.status + ': ' + txt.substring(0,300), 'e');
            setState('error'); return;
        }
        var reader = res.body.getReader();
        var dec = new TextDecoder();
        var buf = '';
        var firstChunk = true;
        while (true) {
            var chunk = await reader.read();
            if (chunk.done) break;
            if (firstChunk) { clearAnimatedLog(); firstChunk = false; }
            buf += dec.decode(chunk.value, {stream:true});
            var lines = buf.split('\n');
            buf = lines.pop();
            lines.forEach(function(line){
                line = line.trim(); if (!line) return;
                try {
                    var d = JSON.parse(line);
                    if (d.progress !== undefined) setBar(d.progress, false);
                    if (d.message) {
                        var isE = /❌|error/i.test(d.message);
                        var isW = /⚠|warn/i.test(d.message);
                        addLog(d.message, isE?'e':isW?'w':null);
                        // After CDC download completes, update file status
                        if (d.progress === 100) markFileReady('icd10cm_codes_<?php echo $displayYear; ?>.txt ready');
                    }
                } catch(e){ addLog('RAW: '+line,'w'); }
            });
        }
        setBar(100, false);
        setState('done');
    } catch(err) {
        if (err.name==='AbortError') {
            addLog('Cancelled.','w'); setBar(0,false); setState('idle');
        } else {
            addLog('Error: '+err.message,'e'); setState('error');
        }
    }
}

async function doRun() {
    if (!confirm('Run ICD-10 import? This may take a few minutes.')) return;
    document.getElementById('log').innerHTML = '';
    setBar(1, false);
    addLogAnimated('Initializing ICD-10 updater');
    setState('running');
    ctrl = new AbortController();
    streamFrom('run_update.php?mode=run&_=' + Date.now());
}

document.getElementById('btnRun').onclick    = doRun;
document.getElementById('btnRetry').onclick  = doRun;
document.getElementById('btnCancel').onclick = function(){ if(ctrl) ctrl.abort(); };
async function deleteTxtFile() {

if (!confirm('Delete ICD-10 TXT file?')) {
    return;
}

const r = await fetch('run_update.php?mode=delete');

const text = await r.text();

console.log(text);

location.reload();
}
</script>
</body>
</html>
