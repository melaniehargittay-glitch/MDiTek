<?php
require_once("../globals.php");

use OpenEMR\Billing\BillingProcessor\BillingProcessor;
use OpenEMR\Common\Csrf\CsrfUtils;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '')) {
    die("Invalid CSRF Token");
}

$selected = $_POST['mdi_encounters'] ?? [];
if (empty($selected)) {
    die("No encounters selected.");
}

$post = [];
$post['btn-continue']                = 1;
$post['bn_process_hcfa_mditek_img']  = 1;
$left_margin = $_POST['left_margin'] ?? $GLOBALS['cms_left_margin_default'];
$top_margin  = $_POST['top_margin']  ?? $GLOBALS['cms_top_margin_default'];

if ($left_margin + 0 === 20 && $top_margin + 0 === 24) {
    $left_margin = '24';
    $top_margin  = '20';
}

$post['left_margin'] = $left_margin;
$post['top_margin']  = $top_margin;
$post['claims'] = [];

foreach ($selected as $claimId) {
    $post['claims'][$claimId] = ['bill' => 1, 'partner' => 0, 'payor' => 'p0'];
}

$billingProcessor = new BillingProcessor($post);
$logger           = $billingProcessor->execute();
$log_lines        = $logger->bill_info();

$success_count = 0;
$error_count   = 0;
foreach ($log_lines as $line) {
    if (stripos($line, 'error') !== false || stripos($line, '***') !== false) {
        $error_count++;
    } else {
        $success_count++;
    }
}
$enc_count = count($selected);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?php echo xlt('HCFA 1500 Generated'); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: #f5f3ff;
    min-height: 100vh;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 32px 16px;
}
.card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 8px 40px rgba(91,33,182,.13);
    width: 100%;
    max-width: 560px;
    overflow: hidden;
}
.card-header {
    background: linear-gradient(135deg, #5b21b6, #7c3aed);
    padding: 24px 28px 20px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 14px;
}
.card-header-icon {
    width: 44px; height: 44px;
    background: rgba(255,255,255,.2);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.card-header h1 { font-size: 17px; font-weight: 700; margin-bottom: 3px; }
.card-header p  { font-size: 12px; opacity: .8; font-weight: 400; }
.card-body { padding: 24px 28px; }
.status-row { display: flex; gap: 12px; margin-bottom: 20px; }
.stat-box { flex: 1; border-radius: 10px; padding: 12px 14px; text-align: center; }
.stat-box.green { background: #f0fdf4; border: 1.5px solid #bbf7d0; }
.stat-box.red   { background: #fff5f5; border: 1.5px solid #fecaca; }
.stat-box.blue  { background: #eff6ff; border: 1.5px solid #bfdbfe; }
.stat-box .num  { font-size: 22px; font-weight: 800; line-height: 1; margin-bottom: 3px; }
.stat-box.green .num { color: #16a34a; }
.stat-box.red   .num { color: #dc2626; }
.stat-box.blue  .num { color: #2563eb; }
.stat-box .lbl  { font-size: 10.5px; font-weight: 600; text-transform: uppercase;
                   letter-spacing: .05em; color: #6b7280; }

/* Form image badge */
.form-badge {
    display: inline-flex; align-items: center; gap: 6px;
    background: #fef3c7; border: 1.5px solid #fde68a;
    border-radius: 8px; padding: 5px 10px;
    font-size: 11px; font-weight: 700; color: #92400e;
    margin-bottom: 16px;
}

.dl-banner {
    background: #f5f3ff; border: 1.5px solid #ddd6fe;
    border-radius: 10px; padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
    margin-bottom: 20px;
}
.dl-banner svg { flex-shrink: 0; color: #5b21b6; }
.dl-banner .dl-text { flex: 1; }
.dl-banner .dl-text strong { font-size: 13px; color: #3b1f7a; display: block; margin-bottom: 2px; }
.dl-banner .dl-text span   { font-size: 11.5px; color: #6b7280; }
#dl-btn {
    padding: 8px 16px; background: #5b21b6; color: #fff;
    border: none; border-radius: 8px; font-size: 12px; font-weight: 700;
    cursor: pointer; white-space: nowrap; text-decoration: none;
    display: inline-flex; align-items: center; gap: 6px;
    transition: background .12s;
}
#dl-btn:hover { background: #4c1d95; }
#dl-btn.waiting { background: #a78bfa; pointer-events: none; }
.log-toggle {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 11.5px; font-weight: 700; color: #5b21b6;
    cursor: pointer; user-select: none;
    padding: 8px 0; border-top: 1px solid #f0ebff;
}
.log-toggle:hover { color: #4c1d95; }
.log-body {
    display: none; background: #0f0f1a; border-radius: 8px;
    padding: 12px 14px; margin-top: 10px; max-height: 240px;
    overflow-y: auto; font-family: 'Courier New', monospace;
    font-size: 11px; line-height: 1.6;
}
.log-body .log-line     { color: #d4d4d4; }
.log-body .log-line.ok  { color: #86efac; }
.log-body .log-line.err { color: #fca5a5; }
@keyframes spin { to { transform: rotate(360deg); } }
.spinner {
    width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4);
    border-top-color: #fff; border-radius: 50%;
    animation: spin .7s linear infinite; display: inline-block;
}
</style>
</head>
<body>
<div class="card">
    <div class="card-header">
        <div class="card-header-icon">
            <svg width="22" height="22" fill="none" stroke="#fff" stroke-width="2.2" viewBox="0 0 24 24">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <line x1="3" y1="9" x2="21" y2="9"/>
                <line x1="3" y1="15" x2="21" y2="15"/>
                <line x1="9" y1="9" x2="9" y2="21"/>
            </svg>
        </div>
        <div>
            <h1><?php echo xlt('HCFA 1500 Generated'); ?></h1>
            <p><?php echo xlt('Preprinted form — overlaid on CMS 1500 background image'); ?></p>
        </div>
    </div>

    <div class="card-body">
        <div class="form-badge">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>
            <?php echo xlt('Preprinted CMS 1500 Form Layout'); ?>
        </div>

        <div class="status-row">
            <div class="stat-box blue">
                <div class="num"><?php echo (int)$enc_count; ?></div>
                <div class="lbl"><?php echo xlt('Claims'); ?></div>
            </div>
            <div class="stat-box green">
                <div class="num"><?php echo (int)$success_count; ?></div>
                <div class="lbl"><?php echo xlt('Processed'); ?></div>
            </div>
            <?php if ($error_count > 0): ?>
            <div class="stat-box red">
                <div class="num"><?php echo (int)$error_count; ?></div>
                <div class="lbl"><?php echo xlt('Warnings'); ?></div>
            </div>
            <?php endif; ?>
        </div>

        <div class="dl-banner">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <div class="dl-text">
                <strong><?php echo xlt('Your PDF is ready'); ?></strong>
                <span id="dl-status"><?php echo xlt('Preparing download...'); ?></span>
            </div>
            <button id="dl-btn" class="waiting" onclick="triggerDownload()">
                <span class="spinner" id="dl-spinner"></span>
                <?php echo xlt('Download'); ?>
            </button>
        </div>

        <div class="log-toggle" onclick="toggleLog(this)">
            <span>&#9776; <?php echo xlt('View Processing Log'); ?> (<?php echo count($log_lines); ?> <?php echo xlt('lines'); ?>)</span>
            <span id="log-chevron">&#9660;</span>
        </div>
        <div class="log-body" id="log-body">
            <?php foreach ($log_lines as $line): ?>
            <?php
                $cls = 'log-line';
                if (stripos($line, '***') !== false || stripos($line, 'error') !== false) {
                    $cls .= ' err';
                } elseif (stripos($line, 'success') !== false || stripos($line, 'processed') !== false) {
                    $cls .= ' ok';
                }
            ?>
            <div class="<?php echo $cls; ?>"><?php echo text($line); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function toggleLog(el) {
    var body = document.getElementById('log-body');
    var chev = document.getElementById('log-chevron');
    if (body.style.display === 'block') {
        body.style.display = 'none';
        chev.textContent = '▼';
    } else {
        body.style.display = 'block';
        chev.textContent = '▲';
    }
}
function triggerDownload() {
    var btn = document.getElementById('dl-btn');
    btn.classList.remove('waiting');
    document.getElementById('dl-spinner').style.display = 'none';
    document.getElementById('dl-status').textContent = '<?php echo addslashes(xlt('Download started')); ?>';
}
setTimeout(function() {
    var btn = document.getElementById('dl-btn');
    if (btn && btn.classList.contains('waiting')) {
        btn.classList.remove('waiting');
        document.getElementById('dl-spinner').style.display = 'none';
        document.getElementById('dl-status').textContent = '<?php echo addslashes(xlt('Click to download')); ?>';
    }
}, 2200);
</script>
<?php $logger->onLogComplete(); ?>
</body>
</html>
