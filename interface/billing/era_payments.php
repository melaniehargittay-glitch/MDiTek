<?php

/*
 * The functions of this class support the billing process like the script billing_process.php.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Eldho Chacko <eldho@zhservices.com>
 * @author    Paul Simon K <paul@zhservices.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) Z&H Consultancy Services Private Limited <sam@zhservices.com>
 * @copyright Copyright (C) 2018 Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */


require_once("../globals.php");
require_once("$srcdir/patient.inc.php");
require_once($GLOBALS['OE_SITE_DIR'] . "/statement.inc.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Billing\ParseERA;
use OpenEMR\Billing\SLEOB;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

if (!AclMain::aclCheckCore('acct', 'bill', '', 'write') && !AclMain::aclCheckCore('acct', 'eob', '', 'write')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("ERA Posting")]);
    exit;
}

$hidden_type_code = isset($_POST['hidden_type_code']) ? $_POST['hidden_type_code'] : '';
$check_date = isset($_POST['check_date']) ? $_POST['check_date'] : '';
$post_to_date = isset($_POST['post_to_date']) ? $_POST['post_to_date'] : '';
$deposit_date = isset($_POST['deposit_date']) ? $_POST['deposit_date'] : '';
$type_code = isset($_POST['type_code']) ? $_POST['type_code'] : '';

//===============================================================================
// This is called back by ParseERA::parseERA() if we are processing X12 835's.
$alertmsg = '';
$where = '';
$eraname = '';
$eracount = 0;
$Processed = 0;
function era_callback(&$out)
{
    global $where, $eracount, $eraname;
    ++$eracount;
    $eraname = $out['gs_date'] . '_' . ltrim($out['isa_control_number'], '0') .
    '_' . ltrim($out['payer_id'], '0');
    list($pid, $encounter, $invnumber) = SLEOB::slInvoiceNumber($out);
    if ($pid && $encounter) {
        if ($where) {
            $where .= ' OR ';
        }
        $where .= "( f.pid = '" . add_escape_custom($pid) . "' AND f.encounter = '" . add_escape_custom($encounter) . "' )";
    }
}
//===============================================================================
  // Handle X12 835 file upload.
if (!empty($_FILES['form_erafile']['size'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    $tmp_name = $_FILES['form_erafile']['tmp_name'];
    // Handle .zip extension if present.  Probably won't work on Windows.
    if (strtolower(substr($_FILES['form_erafile']['name'], -4)) == '.zip') {
        rename($tmp_name, "$tmp_name.zip");
        exec("unzip -p " . escapeshellarg($tmp_name . ".zip") . " > " . escapeshellarg($tmp_name));
        unlink("$tmp_name.zip");
    }
    $alertmsg .= ParseERA::parseERA($tmp_name, 'era_callback');
    $erafullname = $GLOBALS['OE_SITE_DIR'] . "/documents/era/$eraname.edi";
    if (is_file($erafullname)) {
        $alertmsg .=  xl("Warning") . ': ' . xl("Set") . ' ' . $eraname . ' ' . xl("was already uploaded") . ' ';
        if (is_file($GLOBALS['OE_SITE_DIR'] . "/documents/era/$eraname.html")) {
            $Processed = 1;
            $alertmsg .=  xl("and processed.") . ' ';
        } else {
            $alertmsg .=  xl("but not yet processed.") . ' ';
        };
    }
    rename($tmp_name, $erafullname);

    // ── Auto-extract BPR deposit date from ERA ──────────────────────────────
    // The BPR segment last date field (pos 16) is the EFT deposit date —
    // the correct date for check_date, post_to_date, and deposit_date.
    // Only auto-fills if the user left the date fields blank.
    $era_bpr_date = '';
    if (is_file($erafullname)) {
        $era_raw = file_get_contents($erafullname);
        if (preg_match('/BPR\\*[^~]*\\*(\\d{8})~/', $era_raw, $bpr_match)) {
            $raw_date = $bpr_match[1]; // YYYYMMDD
            $era_bpr_date = date('Y-m-d', mktime(0, 0, 0,
                (int)substr($raw_date, 4, 2),
                (int)substr($raw_date, 6, 2),
                (int)substr($raw_date, 0, 4)
            ));
        }
    }
    if ($era_bpr_date) {
        if (empty($check_date))   { $check_date   = $era_bpr_date; }
        if (empty($post_to_date)) { $post_to_date = $era_bpr_date; }
        if (empty($deposit_date)) { $deposit_date = $era_bpr_date; }
    }
    // ── End auto-date extraction ─────────────────────────────────────────────
} // End 835 upload
//===============================================================================

//===============================================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php Header::setupHeader(['common']); ?>
    <?php require_once("{$GLOBALS['srcdir']}/ajax/payment_ajax_jav.inc.php"); ?>
    <title><?php echo xlt('ERA Posting'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@300;400;500;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
        --bg:        #f4f5f7;
        --surface:   #ffffff;
        --border:    #e1e4ea;
        --border2:   #c8cdd8;
        --text:      #111827;
        --text2:     #4b5563;
        --text3:     #9ca3af;
        --purple:    #5b21b6;
        --purple-l:  #ede9fe;
        --purple-m:  #7c3aed;
        --green:     #059669;
        --green-l:   #d1fae5;
        --amber:     #d97706;
        --amber-l:   #fef3c7;
        --red:       #dc2626;
        --red-l:     #fee2e2;
        --blue:      #1d4ed8;
        --blue-l:    #dbeafe;
        --r:         10px;
        --r-sm:      6px;
        --shadow:    0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.06);
        --shadow-lg: 0 8px 32px rgba(0,0,0,.12);
    }

    body {
       
        background: var(--bg);
        color: var(--text);
        font-size: 13px;
        min-height: 100vh;
    }

    /* ── Nav sits full-width inside the body grid ── */
    .era-nav {
        grid-column: 1 / -1;
        margin-bottom: 4px;
    }

    /* ── Page shell ── */
    .era-shell {
        display: flex;
        flex-direction: column;
        height: 100vh;
        overflow: hidden;
    }

    /* ── Top header bar ── */
    .era-header {
        background: var(--surface);
        border-bottom: 1px solid var(--border);
        padding: 0 24px;
        height: 70px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-shrink: 0;
        z-index: 50;
    }
    .era-header-left { display: flex; align-items: center; gap: 16px; }
    .era-header-title { font-size: 15px; font-weight: 700; color: var(--text); }
    .era-header-sub   { font-size: 11px; color: var(--text3); margin-top: 1px; }

    /* ── Body layout ── */
    .era-body {
        flex: 1;
        overflow-y: auto;
        padding: 20px 24px 80px;
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
        align-items: start;
    }

    /* ── Card ── */
    .card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r);
        box-shadow: var(--shadow);
        overflow: hidden;
    }
    .card-header {
        padding: 14px 18px 12px;
        border-bottom: 1px solid var(--border);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .card-title {
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--text2);
        display: flex;
        align-items: center;
        gap: 7px;
    }
    .card-title-num {
        width: 20px; height: 20px;
        border-radius: 50%;
        background: var(--purple-l);
        color: var(--purple);
        font-size: 11px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .card-body { padding: 18px; }

    /* ── Upload zone ── */
    .upload-zone {
        border: 2px dashed var(--border2);
        border-radius: var(--r);
        padding: 32px 20px;
        text-align: center;
        cursor: pointer;
        transition: all .2s;
        background: var(--bg);
        position: relative;
    }
    .upload-zone:hover, .upload-zone.dragover {
        border-color: var(--purple);
        background: var(--purple-l);
    }
    .upload-zone.has-file {
        border-color: var(--green);
        background: var(--green-l);
    }
    .upload-icon {
        width: 44px; height: 44px;
        margin: 0 auto 12px;
        background: var(--purple-l);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .upload-zone.has-file .upload-icon {
        background: var(--green-l);
    }
    .upload-title { font-size: 14px; font-weight: 600; color: var(--purple); margin-bottom: 4px; }
    .upload-zone.has-file .upload-title { color: var(--green); }
    .upload-sub { font-size: 11px; color: var(--text3); margin-bottom: 14px; }
    .upload-accepted { font-size: 11px; color: var(--text3); margin-top: 10px; }
    .upload-file-name {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        font-size: 13px;
        font-weight: 600;
        color: var(--green);
        margin-top: 8px;
    }
    .upload-file-meta { font-size: 11px; color: var(--text3); font-weight: 400; }
    .choose-btn {
        display: inline-block;
        padding: 8px 20px;
        background: var(--purple);
        color: #fff;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        border: none;
        font-family: inherit;
        transition: background .15s;
    }
    .choose-btn:hover { background: var(--purple-m); }

    /* ── ERA info grid ── */
    .era-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0;
    }
    .era-info-row {
        display: contents;
    }
    .era-info-label {
        padding: 9px 14px 9px 0;
        font-size: 11px;
        color: var(--text3);
        display: flex;
        align-items: center;
        gap: 6px;
        border-bottom: 1px solid var(--border);
    }
    .era-info-val {
        padding: 9px 0 9px 14px;
        font-size: 12px;
        font-weight: 600;
        font-family: 'IBM Plex Mono', monospace;
        color: var(--text);
        border-bottom: 1px solid var(--border);
        border-left: 1px solid var(--border);
    }
    .era-info-label:last-of-type, .era-info-val:last-of-type { border-bottom: none; }

    /* Parsed badge */
    .parsed-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
    }
    .parsed-badge.success { background: var(--green-l); color: var(--green); }
    .parsed-badge.pending { background: var(--amber-l); color: var(--amber); }

    /* ── Insurance detection ── */
    .ins-detection-box {
        border: 1px solid var(--amber);
        border-radius: var(--r-sm);
        background: var(--amber-l);
        padding: 12px 14px;
        margin-bottom: 14px;
    }
    .ins-detection-title {
        font-size: 12px;
        font-weight: 700;
        color: var(--amber);
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 6px;
    }
    .ins-detection-sub { font-size: 11px; color: var(--text2); margin-bottom: 10px; }

    /* ── Date fields ── */
    .date-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 10px;
        margin-bottom: 14px;
    }
    .form-group { display: flex; flex-direction: column; gap: 4px; }
    .form-label {
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--text2);
    }
    .form-label .req { color: var(--red); }
    .form-control {
        padding: 8px 10px;
        border: 1.5px solid var(--border);
        border-radius: var(--r-sm);
        font-family: 'IBM Plex Mono', monospace;
        font-size: 12px;
        color: var(--text);
        background: var(--surface);
        outline: none;
        transition: border-color .15s;
        width: 100%;
    }
    .form-control:focus { border-color: var(--purple); }
    .form-control.required-empty { border-color: var(--red); background: var(--red-l); }
    .form-control-text {
        padding: 8px 10px;
        border: 1.5px solid var(--border);
        border-radius: var(--r-sm);
        font-size: 12px;
        color: var(--text);
        background: var(--surface);
        outline: none;
        transition: border-color .15s;
        width: 100%;
    }
    .form-control-text:focus { border-color: var(--purple); }

    /* ── Validation checklist ── */
    .val-item {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 10px 0;
        border-bottom: 1px solid var(--border);
    }
    .val-item:last-child { border-bottom: none; }
    .val-icon {
        width: 20px; height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        margin-top: 1px;
    }
    .val-icon.ok    { background: var(--green-l); color: var(--green); }
    .val-icon.warn  { background: var(--amber-l); color: var(--amber); }
    .val-icon.error { background: var(--red-l);   color: var(--red); }
    .val-icon.info  { background: var(--blue-l);  color: var(--blue); }
    .val-text-main  { font-size: 12px; font-weight: 600; color: var(--text); }
    .val-text-sub   { font-size: 11px; color: var(--text3); margin-top: 1px; }
    .val-action {
        margin-left: auto;
        flex-shrink: 0;
    }

    /* ── Ready banner ── */
    .ready-banner {
        background: var(--green-l);
        border: 1px solid #6ee7b7;
        border-radius: var(--r-sm);
        padding: 12px 14px;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        margin-top: 14px;
    }
    .ready-banner-icon {
        width: 28px; height: 28px;
        border-radius: 50%;
        background: var(--green);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }
    .ready-banner-title { font-size: 13px; font-weight: 700; color: var(--green); }
    .ready-banner-sub   { font-size: 11px; color: var(--text2); margin-top: 2px; }

    /* ── Posting summary ── */
    .summary-grid {
        display: grid;
        grid-template-columns: repeat(4,1fr);
        gap: 1px;
        background: var(--border);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        overflow: hidden;
        margin-bottom: 14px;
    }
    .summary-cell {
        background: var(--surface);
        padding: 12px 14px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .summary-num {
        font-size: 22px;
        font-weight: 700;
        font-family: 'IBM Plex Mono', monospace;
        line-height: 1;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .summary-num .s-icon {
        width: 22px; height: 22px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .summary-lbl { font-size: 10px; color: var(--text3); font-weight: 500; text-transform: uppercase; letter-spacing: .05em; }
    .summary-amt { font-size: 13px; font-weight: 600; font-family: 'IBM Plex Mono', monospace; color: var(--text2); }

    /* ── Processing mode ── */
    .mode-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; }
    .mode-card {
        border: 2px solid var(--border);
        border-radius: var(--r-sm);
        padding: 14px 12px;
        cursor: pointer;
        transition: all .15s;
        display: flex;
        flex-direction: column;
        gap: 6px;
        position: relative;
    }
    .mode-card:hover { border-color: var(--purple-m); background: var(--purple-l); }
    .mode-card.selected { border-color: var(--purple); background: var(--purple-l); }
    .mode-card input[type=radio] {
        position: absolute;
        top: 12px; right: 12px;
        accent-color: var(--purple);
        width: 16px; height: 16px;
        cursor: pointer;
    }
    .mode-title { font-size: 12px; font-weight: 700; color: var(--text); }
    .mode-sub   { font-size: 11px; color: var(--text3); line-height: 1.4; }

    /* ── Bottom action bar ── */
    .era-footer {
        position: fixed;
        bottom: 0; left: 0; right: 0;
        background: var(--surface);
        border-top: 1px solid var(--border);
        padding: 12px 24px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        z-index: 50;
        box-shadow: 0 -2px 8px rgba(0,0,0,.06);
    }

    /* ── Buttons ── */
    .btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 9px 18px;
        border-radius: 6px;
        font-family: 'IBM Plex Sans', sans-serif;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .15s;
        text-decoration: none;
        white-space: nowrap;
    }
    .btn-ghost  { background: transparent; border: 1.5px solid var(--border2); color: var(--text2); }
    .btn-ghost:hover { border-color: var(--purple); color: var(--purple); }
    .btn-outline { background: transparent; border: 1.5px solid var(--purple); color: var(--purple); }
    .btn-outline:hover { background: var(--purple-l); }
    .btn-primary { background: var(--purple); color: #fff; }
    .btn-primary:hover { background: var(--purple-m); box-shadow: 0 4px 12px rgba(91,33,182,.3); }
    .btn-primary svg { animation: none; }
    .btn-primary:hover svg { animation: spin .6s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Alert boxes ── */
    .alert { padding: 10px 14px; border-radius: var(--r-sm); font-size: 12px; margin-bottom: 12px; display: flex; align-items: flex-start; gap: 8px; }
    .alert-success { background: var(--green-l); border: 1px solid #6ee7b7; color: #065f46; }
    .alert-warning { background: var(--amber-l); border: 1px solid #fcd34d; color: #78350f; }
    .alert-info    { background: var(--blue-l);  border: 1px solid #93c5fd; color: #1e3a8a; }

    /* ── Misc ── */
    .wo-checkbox { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--text2); cursor: pointer; }
    .wo-checkbox input { accent-color: var(--purple); width: 14px; height: 14px; cursor: pointer; }
    .ins-id-box {
        padding: 8px 10px;
        border: 1.5px solid var(--border);
        border-radius: var(--r-sm);
        background: var(--bg);
        font-family: 'IBM Plex Mono', monospace;
        font-size: 12px;
        font-weight: 600;
        color: var(--purple);
        min-height: 38px;
    }

    /* ajax insurance dropdown */
    #ajax_div_insurance {
        position: absolute; z-index: 999;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--r-sm);
        padding: 6px;
        box-shadow: var(--shadow-lg);
        min-width: 280px;
    }

    /* scrollbar */
    ::-webkit-scrollbar { width: 4px; height: 4px; }
    ::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }

    /* ── Validation modal ── */
    .val-modal-overlay {
        position: fixed; inset: 0; z-index: 1000;
        background: rgba(17,24,39,.45);
        display: flex; align-items: center; justify-content: center;
        opacity: 0; pointer-events: none;
        transition: opacity .2s;
    }
    .val-modal-overlay.open { opacity: 1; pointer-events: all; }
    .val-modal {
        background: var(--surface);
        border-radius: var(--r);
        box-shadow: var(--shadow-lg);
        width: 480px; max-width: 95vw;
        overflow: hidden;
        transform: translateY(12px);
        transition: transform .2s;
    }
    .val-modal-overlay.open .val-modal { transform: translateY(0); }
    .val-modal-header {
        padding: 16px 20px 14px;
        border-bottom: 1px solid var(--border);
        display: flex; align-items: center; justify-content: space-between;
    }
    .val-modal-title {
        font-size: 14px; font-weight: 700; color: var(--text);
        display: flex; align-items: center; gap: 8px;
    }
    .val-modal-icon {
        width: 28px; height: 28px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .val-modal-body { padding: 16px 20px; }
    .val-modal-footer {
        padding: 12px 20px 16px;
        border-top: 1px solid var(--border);
        display: flex; justify-content: flex-end; gap: 8px;
    }
    .val-check-row {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 9px 0; border-bottom: 1px solid var(--border);
    }
    .val-check-row:last-child { border-bottom: none; }
    .val-dot {
        width: 18px; height: 18px; border-radius: 50%; flex-shrink: 0;
        display: flex; align-items: center; justify-content: center; margin-top: 1px;
    }
    .val-dot.ok    { background: var(--green-l); color: var(--green); }
    .val-dot.warn  { background: var(--amber-l); color: var(--amber); }
    .val-dot.error { background: var(--red-l);   color: var(--red); }
    .val-check-main { font-size: 12px; font-weight: 600; color: var(--text); }
    .val-check-sub  { font-size: 11px; color: var(--text3); margin-top: 1px; }
    .val-summary-bar {
        display: flex; gap: 8px; flex-wrap: wrap;
        padding: 10px 12px;
        background: var(--bg);
        border-radius: var(--r-sm);
        margin-bottom: 14px;
        border: 1px solid var(--border);
    }
    .val-summary-pill {
        font-size: 11px; font-weight: 700;
        padding: 3px 10px; border-radius: 20px;
        display: flex; align-items: center; gap: 4px;
    }
/* ── Modern Payment Nav ──────────────────────────────────────────────────── */
.pay-nav-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:6px;margin-bottom:1.5rem;display:flex;align-items:center;gap:2px;flex-wrap:wrap;box-shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);}
.pay-nav-wrap .pnav-item{display:flex;align-items:center;gap:7px;padding:8px 15px;border-radius:7px;font-size:13px;font-weight:500;color:#64748b;text-decoration:none!important;transition:background .15s,color .15s;white-space:nowrap;border:1px solid transparent;line-height:1;}
.pay-nav-wrap .pnav-item svg{flex-shrink:0;opacity:.7;transition:opacity .15s;}
.pay-nav-wrap .pnav-item:hover{background:#f8fafc;color:#1e293b;border-color:#e2e8f0;text-decoration:none!important;}
.pay-nav-wrap .pnav-item:hover svg{opacity:1;}
.pay-nav-wrap .pnav-item.pnav-active{background:#f5f3ff;color:#6610f2;border-color:#ddd6fe;font-weight:600;box-shadow:0 1px 3px rgba(102,16,242,.1);}
.pay-nav-wrap .pnav-item.pnav-active svg{opacity:1;}
.pnav-badge{display:inline-flex;align-items:center;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700;line-height:1.4;background:#f5f3ff;color:#6610f2;border:1px solid #ddd6fe;letter-spacing:.03em;margin-left:2px;}
.pnav-item.pnav-active .pnav-badge{background:#ede9fe;border-color:#c4b5fd;}
.pnav-sep{width:1px;height:20px;background:#e2e8f0;margin:0 3px;flex-shrink:0;}
@media(max-width:768px){.pay-nav-wrap{gap:1px;padding:4px;}.pay-nav-wrap .pnav-item{padding:7px 10px;font-size:12px;}.pnav-label{display:none;}.pnav-sep{display:none;}}
    </style>

    <script>
    document.onclick = HideTheAjaxDivs;

    // ── State ─────────────────────────────────────────────────────────────────
    var _eraValid    = false;  // passes client-side validation
    var _eraClpCount = 0;

    // ── Client-side ERA parse: runs the moment a file is selected ─────────────
    // Validates structure, fills date fields, populates ERA info card,
    // then shows the validation modal so the user sees results immediately.
    function eraFillDates(file) {
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(evt) {
            var txt = evt.target.result;

            // Extract key segments
            var isaOk      = /\bISA\*/.test(txt);
            var bprMatch   = txt.match(/BPR[^~]*\*(\d{8})[~*]/);
            var trnMatch   = txt.match(/TRN\*1\*([^*~]+)/);
            var payerMatch = txt.match(/N1\*PR\*([^*~]+)/);
            var amtMatch   = txt.match(/BPR\*[^*]*\*([0-9.]+)\*/);
            var pmMatch    = txt.match(/BPR\*[^*]*\*[^*]*\*([A-Z]+)\*/);
            _eraClpCount   = (txt.match(/\bCLP\*/g) || []).length;

            var checkNum   = trnMatch   ? trnMatch[1]               : '';
            var payerName  = payerMatch ? payerMatch[1]             : '';
            var checkAmt   = amtMatch   ? '$' + parseFloat(amtMatch[1]).toFixed(2) : '';
            var payMethod  = pmMatch    ? pmMatch[1]                : '';

            // Fill readonly date inputs (still submitted with form)
            if (bprMatch) {
                var raw = bprMatch[1];
                var fmt = raw.substring(0,4)+'-'+raw.substring(4,6)+'-'+raw.substring(6,8);
                ['check_date','post_to_date','deposit_date'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) { el.value = fmt; el.dispatchEvent(new Event('change',{bubbles:true})); }
                });
                var dateDisp = document.getElementById('era-eft-date-val');
                if (dateDisp) dateDisp.textContent = fmt;
            }

            // Populate ERA info display fields
            var fieldMap = {
                'era-payer-name': payerName || '—',
                'era-check-ref':  checkNum  || '—',
                'era-pay-method': payMethod || '—',
                'era-check-amt':  checkAmt  || '—',
                'era-clp-count':  _eraClpCount || '—'
            };
            Object.keys(fieldMap).forEach(function(id) {
                var el = document.getElementById(id);
                if (el) {
                    el.textContent = fieldMap[id];
                    if (id === 'era-payer-name' && payerName) el.style.color = 'var(--text)';
                    if (id === 'era-check-amt'  && checkAmt)  el.style.color = 'var(--green)';
                }
            });

            // Show ERA info card
            var infoCard = document.getElementById('era-info-card');
            if (infoCard) infoCard.style.display = '';

            // Populate Posting Summary immediately on file select
            var deniedCount = (txt.match(/\bCLP\*[^~]*\*4\*/g) || []).length;
            var totalEl  = document.getElementById('summary-total');
            var parsedEl = document.getElementById('summary-parsed');
            var reviewEl = document.getElementById('summary-review');
            var deniedEl = document.getElementById('summary-denied');
            var emptyEl  = document.getElementById('summary-empty');
            var gridEl   = document.getElementById('summary-grid');
            if (totalEl)  totalEl.firstChild.textContent  = String(_eraClpCount || 0);
            if (parsedEl) parsedEl.firstChild.textContent = String(_eraClpCount || 0);
            if (reviewEl) reviewEl.firstChild.textContent = '\u2014';
            if (deniedEl) deniedEl.firstChild.textContent = String(deniedCount);
            if (emptyEl)  emptyEl.style.display = 'none';
            if (gridEl)   gridEl.style.display  = '';

            // Update upload zone to "has file" state
            var zone = document.getElementById('upload-zone');
            if (zone) {
                zone.classList.add('has-file');
                var fn = document.getElementById('upload-filename');
                if (fn) { fn.textContent = file.name; fn.style.display = 'flex'; }
                var sub = document.getElementById('upload-sub');
                if (sub) sub.textContent = (file.size/1024/1024).toFixed(1) + ' MB';
                document.getElementById('upload-init').style.display = 'none';
                document.getElementById('upload-done').style.display = '';
            }

            // ── Build and show validation modal ───────────────────────────────
            var checks = [
                {
                    ok:   isaOk,
                    main: <?php echo xlj('ISA/GS envelope found'); ?>,
                    sub:  isaOk
                        ? <?php echo xlj('Valid X12 835 envelope structure'); ?>
                        : <?php echo xlj('ISA header not found — may not be a valid 835 file'); ?>
                },
                {
                    ok:   !!bprMatch,
                    main: <?php echo xlj('BPR segment found'); ?>,
                    sub:  bprMatch
                        ? <?php echo xlj('EFT date and payment amount detected'); ?>
                        : <?php echo xlj('BPR segment missing — dates will not auto-populate'); ?>
                },
                {
                    ok:   _eraClpCount > 0,
                    main: <?php echo xlj('Claim payment loops (CLP)'); ?>,
                    sub:  _eraClpCount > 0
                        ? _eraClpCount + ' <?php echo addslashes(xlt('claims found in this ERA')); ?>'
                        : <?php echo xlj('No CLP segments — file may contain no claims'); ?>
                },
                {
                    ok:   !!trnMatch,
                    warn: !trnMatch,
                    main: <?php echo xlj('Check / EFT reference number'); ?>,
                    sub:  trnMatch ? trnMatch[1] : <?php echo xlj('TRN segment not found'); ?>
                },
                {
                    ok:   !!payerMatch,
                    warn: !payerMatch,
                    main: <?php echo xlj('Payer identification (N1*PR)'); ?>,
                    sub:  payerMatch ? payerMatch[1] : <?php echo xlj('Payer name not found — select insurance manually'); ?>
                }
            ];

            var hasError = checks.some(function(c){ return !c.ok && !c.warn; });
            var hasWarn  = checks.some(function(c){ return !c.ok && c.warn; });
            _eraValid    = !hasError;

            // Summary pills
            var good = checks.filter(function(c){ return c.ok; }).length;
            var warn = checks.filter(function(c){ return !c.ok && c.warn; }).length;
            var err  = checks.filter(function(c){ return !c.ok && !c.warn; }).length;
            var pills = '<span class="val-summary-pill" style="background:var(--green-l);color:var(--green);">'
                + '<svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>&nbsp;'
                + good + ' <?php echo addslashes(xlt('Passed')); ?></span>';
            if (warn) pills += ' <span class="val-summary-pill" style="background:var(--amber-l);color:var(--amber);">'
                + warn + ' <?php echo addslashes(xlt('Warnings')); ?></span>';
            if (err)  pills += ' <span class="val-summary-pill" style="background:var(--red-l);color:var(--red);">'
                + err  + ' <?php echo addslashes(xlt('Errors')); ?></span>';

            // Checklist rows
            var rows = '';
            checks.forEach(function(c) {
                var cls  = c.ok ? 'ok' : (c.warn ? 'warn' : 'error');
                var icon = c.ok
                    ? '<svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>'
                    : '<svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="14"/><circle cx="12" cy="19" r="1" fill="currentColor"/></svg>';
                rows += '<div class="val-check-row"><div class="val-dot '+cls+'">'+icon+'</div>'
                    + '<div><div class="val-check-main">'+c.main+'</div>'
                    + '<div class="val-check-sub">'+c.sub+'</div></div></div>';
            });

            // Header styling based on result
            var hColor = hasError ? 'var(--red)'   : (hasWarn ? 'var(--amber)' : 'var(--green)');
            var hBg    = hasError ? 'var(--red-l)' : (hasWarn ? 'var(--amber-l)' : 'var(--green-l)');
            var hTitle = hasError ? <?php echo xlj('Validation Issues Found'); ?>
                       : (hasWarn ? <?php echo xlj('Ready — review warnings'); ?>
                       : <?php echo xlj('ERA File Validated'); ?>);
            var hIcon  = hasError
                ? '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
                : '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';

            // Footer: always show Close; also Continue if no hard errors
            var footer = '<button type="button" class="btn btn-ghost" onclick="closeValModal()">'
                + <?php echo xlj('Close'); ?> + '</button>';
            if (!hasError) {
                footer += ' <button type="button" class="btn btn-primary" onclick="closeValModal()">'
                    + '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>&nbsp;'
                    + <?php echo xlj('Looks Good'); ?> + '</button>';
            }

            // Inject into modal DOM
            var iconEl = document.getElementById('val-modal-icon');
            if (iconEl) { iconEl.style.background = hBg; iconEl.style.color = hColor; iconEl.innerHTML = hIcon; }
            var titleEl = document.getElementById('val-modal-title-text');
            if (titleEl) { titleEl.textContent = hTitle; titleEl.style.color = hColor; }
            var pillsEl = document.getElementById('val-modal-pills');
            if (pillsEl) pillsEl.innerHTML = pills;
            var checksEl = document.getElementById('val-modal-checks');
            if (checksEl) checksEl.innerHTML = rows;
            var footerEl = document.getElementById('val-modal-footer');
            if (footerEl) footerEl.innerHTML = footer;

            openValModal();
        };
        reader.readAsText(file);
    }

    function openValModal()  { document.getElementById('val-modal-overlay').classList.add('open'); }
    function closeValModal() { document.getElementById('val-modal-overlay').classList.remove('open'); }

    // Close on overlay click
    document.addEventListener('click', function(e) {
        if (e.target && e.target.id === 'val-modal-overlay') closeValModal();
    });

    // File input listeners
    document.addEventListener('change', function(e) {
        if (e.target && (e.target.id === 'uploadedfile' || e.target.name === 'form_erafile')) {
            eraFillDates(e.target.files[0]);
        }
    });
    $(function() {
        var inp = document.getElementById('uploadedfile');
        if (inp && !inp._eraBound) {
            inp._eraBound = true;
            inp.addEventListener('change', function(){ eraFillDates(this.files[0]); });
        }
    });

    // ── Input validation (file + insurance) ───────────────────────────────────
    function validateInputs() {
        var fileEl = document.getElementById('uploadedfile');
        if (!fileEl || !fileEl.value) {
            alert(<?php echo xlj('Please choose an ERA file first.'); ?>);
            return false;
        }
        var insId   = document.getElementById('hidden_type_code').value;
        var insDisp = document.getElementById('div_insurance_or_patient').innerHTML;
        if (!insId) {
            alert(<?php echo xlj('Please select an insurance payer.'); ?>);
            document.getElementById('type_code').focus();
            return false;
        }
        if (insId !== insDisp) {
            alert(<?php echo xlj('Please select the insurance from the dropdown list.'); ?>);
            document.getElementById('type_code').focus();
            return false;
        }
        return true;
    }

    // ── Auto Post ─────────────────────────────────────────────────────────────
    // Submits the form with form_without=0 (normal posting).
    // sl_eob_process.php opens in a new tab via OnloadAction.
    function doAutoPost() {
        if (!validateInputs()) return;
        document.getElementById('form_without').value = '0';
        document.getElementById('manual_review_pending').value = '0';
        top.restoreSession();
        document.getElementById('era-form').submit();
    }

    // ── Manual Review ─────────────────────────────────────────────────────────
    // Opens sl_eob_process.php with debug=1 (without update) in a named popup.
    // If the file hasn't been uploaded server-side yet (no eraname), submits
    // the form first with manual_review_pending=1; OnloadAction then opens the popup.
    function doManualReview() {
        if (!validateInputs()) return;

        var eraname  = <?php echo js_escape($eraname ?: ''); ?>;
        var insId    = document.getElementById('hidden_type_code').value;
        var paydate  = document.getElementById('check_date').value;
        var postDate = document.getElementById('post_to_date').value;
        var depDate  = document.getElementById('deposit_date').value;
        var csrf     = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;

        if (eraname) {
            // ERA already on server — open popup directly
            var url = 'sl_eob_process.php'
                + '?eraname='       + encodeURIComponent(eraname)
                + '&debug=1'
                + '&original=original'
                + '&paydate='       + encodeURIComponent(paydate)
                + '&post_to_date='  + encodeURIComponent(postDate)
                + '&deposit_date='  + encodeURIComponent(depDate)
                + '&InsId='         + encodeURIComponent(insId)
                + '&csrf_token_form=' + encodeURIComponent(csrf);
            var popup = window.open(url, 'era_manual_review',
                'width=1100,height=700,scrollbars=yes,resizable=yes,toolbar=no,menubar=no');
            if (!popup) alert(<?php echo xlj('Popup was blocked. Please allow popups for this site and try again.'); ?>);
        } else {
            // File not on server yet — submit form, OnloadAction will open the popup
            document.getElementById('form_without').value          = '1';
            document.getElementById('manual_review_pending').value = '1';
            top.restoreSession();
            document.getElementById('era-form').submit();
        }
    }

    // ── OnloadAction: fires after form submit (file upload) ───────────────────
    function OnloadAction() {
        var after = document.getElementById('after_value');
        if (after && after.value !== '') alert(after.value);

        <?php if (!empty($_FILES['form_erafile']['size'])): ?>
        var f           = document.getElementById('era-form');
        var paydate     = f.check_date.value;
        var post_to_date = f.post_to_date.value;
        var deposit_date = f.deposit_date.value;
        var insId       = document.getElementById('hidden_type_code').value;
        var csrf        = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
        var eraname     = <?php echo js_escape($eraname); ?>;
        var isManual    = document.getElementById('manual_review_pending').value === '1'
                       || <?php echo js_escape(($_REQUEST['form_without'] ?? '0')); ?> === '1';

        var url = 'sl_eob_process.php'
            + '?eraname='      + encodeURIComponent(eraname)
            + '&debug='        + (isManual ? '1' : '0')
            + '&original=original'
            + '&paydate='      + encodeURIComponent(paydate)
            + '&post_to_date=' + encodeURIComponent(post_to_date)
            + '&deposit_date=' + encodeURIComponent(deposit_date)
            + '&InsId='        + encodeURIComponent(insId)
            + '&csrf_token_form=' + encodeURIComponent(csrf);

        if (isManual) {
            var popup = window.open(url, 'era_manual_review',
                'width=1100,height=700,scrollbars=yes,resizable=yes,toolbar=no,menubar=no');
            if (!popup) alert(<?php echo xlj('Popup was blocked. Please allow popups for this site.'); ?>);
        } else {
            window.open(url, '_blank');
        }
        <?php endif; ?>
    }
    </script>
</head>

<body onload="OnloadAction()">
<div class="era-shell">

    <!-- ── Header ── -->
    <header class="era-header">
        <div class="era-header-left">
            <div>
                <div class="era-header-title"><?php echo xlt('ERA Posting'); ?></div>
                <div class="era-header-sub"><?php echo xlt('Upload and process ERA (835) files'); ?></div>
            </div>
        </div>

        <button style="display:none" class="btn btn-ghost btn-sm" onclick="window.close();" style="padding:6px 14px;font-size:12px;">
            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            <?php echo xlt('Close'); ?>
        </button>
    </header>

    <!-- ── Body ── -->
    <div class="era-body">

        <!-- ── Navigation (spans full width) ── -->
        <nav class="pay-nav-wrap era-nav" aria-label="<?php echo xla('Payment Navigation'); ?>">
          <a class="pnav-item" href="new_payment.php">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="7" y1="15" x2="10" y2="15"/><line x1="14" y1="15" x2="17" y2="15"/></svg>
            <span class="pnav-label"><?php echo xlt('New Payment'); ?></span>
          </a>
          <div class="pnav-sep"></div>
          <a class="pnav-item" href="search_payments.php">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <span class="pnav-label"><?php echo xlt('Search Payment'); ?></span>
          </a>
          <div class="pnav-sep"></div>
          <a class="pnav-item pnav-active" href="era_payments.php" aria-current="page">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <span class="pnav-label"><?php echo xlt('ERA Posting'); ?></span>
          </a>
          <div class="pnav-sep"></div>
          <a class="pnav-item" href="sl_eob_search.php">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/><path d="M14 2v6h6"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            <span class="pnav-label"><?php echo xlt('EOB Posting'); ?></span>
          </a>
          <div class="pnav-sep"></div>
          <a class="pnav-item" href="sl_eob_search.php">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            <span class="pnav-label"><?php echo xlt('Invoice Search'); ?></span>
          </a>
          <div class="pnav-sep"></div>
          <a class="pnav-item" href="legacy_eob_posting.php">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
            <span class="pnav-label"><?php echo xlt('Legacy EOB Posting'); ?></span>
            <span class="pnav-badge">NEW</span>
          </a>
        </nav>
        <!-- ══ LEFT COLUMN ══ -->
        <div style="display:flex;flex-direction:column;gap:16px;">

            <!-- 1. Upload ERA File -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-title-num">1</span>
                        <?php echo xlt('Upload ERA File'); ?>
                    </div>
                </div>
                <div class="card-body">
                    <form action="era_payments.php" enctype="multipart/form-data" method="post" id="era-form">
                    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                    <input type="hidden" name="after_value" id="after_value" value="<?php echo attr($alertmsg); ?>" />
                    <input type="hidden" name="hidden_type_code" id="hidden_type_code" value="<?php echo attr($hidden_type_code); ?>" />
                    <input type="hidden" name="ajax_mode" id="ajax_mode" value="" />
                    <input name="MAX_FILE_SIZE" type="hidden" value="10000000" />
                    <input type="hidden" name="manual_review_pending" id="manual_review_pending" value="0" />

                    <!-- Upload zone -->
                    <div class="upload-zone <?php echo !empty($_FILES['form_erafile']['size']) ? 'has-file' : ''; ?>"
                         id="upload-zone"
                         onclick="document.getElementById('uploadedfile').click();"
                         ondragover="event.preventDefault();this.classList.add('dragover')"
                         ondragleave="this.classList.remove('dragover')"
                         ondrop="event.preventDefault();this.classList.remove('dragover');var f=event.dataTransfer.files[0];if(f){var inp=document.getElementById('uploadedfile');var dt=new DataTransfer();dt.items.add(f);inp.files=dt.files;eraFillDates(f);}">

                        <input type="file" id="uploadedfile" name="form_erafile"
                               accept=".835,.txt,.era,.zip"
                               style="display:none;" />

                        <!-- Init state -->
                        <div id="upload-init" <?php echo !empty($_FILES['form_erafile']['size']) ? 'style="display:none;"' : ''; ?>>
                            <div class="upload-icon">
                                <svg width="22" height="22" fill="none" stroke="var(--purple)" stroke-width="2" viewBox="0 0 24 24"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                            </div>
                            <div class="upload-title"><?php echo xlt('Drag & drop your ERA (.835) file here'); ?></div>
                            <div class="upload-sub"><?php echo xlt('or click to browse'); ?></div>
                            <button type="button" class="choose-btn" onclick="event.stopPropagation();document.getElementById('uploadedfile').click();"><?php echo xlt('Choose File'); ?></button>
                            <div class="upload-accepted"><?php echo xlt('Accepted: .835, .txt, .era'); ?> &nbsp;|&nbsp; <?php echo xlt('Max size: 10MB'); ?></div>
                        </div>

                        <!-- Done state -->
                        <div id="upload-done" <?php echo empty($_FILES['form_erafile']['size']) ? 'style="display:none;"' : ''; ?>>
                            <div class="upload-icon">
                                <svg width="22" height="22" fill="none" stroke="var(--green)" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                            <?php if (!empty($_FILES['form_erafile']['name'])): ?>
                            <div class="upload-file-name">
                                <svg width="14" height="14" fill="none" stroke="var(--green)" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <?php echo text($_FILES['form_erafile']['name']); ?>
                            </div>
                            <div class="upload-file-meta" style="margin-top:4px;">
                                <?php echo text(round($_FILES['form_erafile']['size']/1024/1024, 1)); ?> MB
                                &nbsp;·&nbsp; <?php echo xlt('Uploaded'); ?> <?php echo text(date('m/d/Y g:i A')); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div id="upload-filename" style="display:none;" class="upload-file-name"></div>
                        <div id="upload-sub" style="font-size:11px;color:var(--text3);margin-top:4px;"></div>
                    </div>

                    <?php if (!empty($era_bpr_date)): ?>
                    <div class="alert alert-success" style="margin-top:12px;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;margin-top:1px;"><polyline points="20 6 9 17 4 12"/></svg>
                        <div>
                            <strong><?php echo xlt('ERA deposit date auto-detected'); ?>:</strong>
                            <strong><?php echo text($era_bpr_date); ?></strong>
                            — <?php echo xlt('All date fields pre-filled from BPR segment. Verify before posting.'); ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- 2. ERA Information -->
            <div class="card" id="era-info-card" <?php echo empty($_FILES['form_erafile']['size']) && empty($hidden_type_code) ? 'style="display:none;"' : ''; ?>>
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-title-num">2</span>
                        <?php echo xlt('ERA Information (Auto-Extracted)'); ?>
                    </div>
                    <div class="parsed-badge <?php echo !empty($eraname) ? 'success' : 'pending'; ?>">
                        <?php if (!empty($eraname)): ?>
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php echo xlt('Parsed Successfully'); ?>
                        <?php else: ?>
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <?php echo xlt('Awaiting File'); ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body" style="padding:0;">
                    <div class="era-info-grid">
                        <div class="era-info-label">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <?php echo xlt('Payer'); ?>
                        </div>
                        <div class="era-info-val" id="era-payer-name" style="color:var(--text3);"><?php echo xlt('—'); ?></div>

                        <div class="era-info-label">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                            <?php echo xlt('EFT / Check Date'); ?>
                        </div>
                        <div class="era-info-val"><?php echo text($check_date ?: '—'); ?></div>

                        <div class="era-info-label">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></svg>
                            <?php echo xlt('Check / EFT #'); ?>
                        </div>
                        <div class="era-info-val" id="era-check-ref"><?php echo xlt('—'); ?></div>

                        <div class="era-info-label">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="12" y1="15" x2="12" y2="20"/></svg>
                            <?php echo xlt('Payment Method'); ?>
                        </div>
                        <div class="era-info-val" id="era-pay-method"><?php echo xlt('—'); ?></div>

                        <div class="era-info-label">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            <?php echo xlt('Check Amount'); ?>
                        </div>
                        <div class="era-info-val" id="era-check-amt" style="color:var(--green);font-weight:700;"><?php echo xlt('—'); ?></div>

                        <div class="era-info-label">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                            <?php echo xlt('Claim Count'); ?>
                        </div>
                        <div class="era-info-val" id="era-clp-count"><?php echo text($eracount ?: '—'); ?></div>
                    </div>
                </div>

                <!-- Insurance selection -->
                <div style="padding:14px 18px;border-top:1px solid var(--border);">
                    <?php if (empty($hidden_type_code)): ?>
                    <div class="ins-detection-box">
                        <div class="ins-detection-title">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <?php echo xlt('Insurance Detection'); ?>
                        </div>
                        <div class="ins-detection-sub"><?php echo xlt("We couldn't confidently auto-detect the payer from this ERA. Please select the correct insurance below."); ?></div>
                    </div>
                    <?php endif; ?>

                    <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;margin-bottom:10px;">
                        <div class="form-group">
                            <label class="form-label"><?php echo xlt('Select Insurance'); ?> <span class="req">*</span></label>
                            <div style="position:relative;">
                                <input autocomplete="off" class="form-control-text" id="type_code" name="type_code"
                                       onkeydown="PreventIt(event)"
                                       placeholder="<?php echo xla('Search insurance by name, payer ID, or plan...'); ?>"
                                       type="text" value="<?php echo attr($type_code); ?>"
                                       form="era-form" />
                                <div id="ajax_div_insurance_section">
                                    <div id="ajax_div_insurance_error"></div>
                                    <div id="ajax_div_insurance" style="display:none;"></div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-outline" style="height:38px;"
                                onclick="document.getElementById('type_code').focus();">
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <?php echo xlt('Search Insurance'); ?>
                        </button>
                    </div>

                    <div class="form-group" style="margin-bottom:10px;">
                        <label class="form-label"><?php echo xlt('Insurance ID'); ?></label>
                        <div class="ins-id-box" id="div_insurance_or_patient"><?php echo text($hidden_type_code); ?></div>
                        <input id="description" name="description" type="hidden" form="era-form" />
                        <input id="hidden_ajax_close_value" type="hidden" value="<?php echo attr($type_code); ?>" />
                    </div>

                    <label class="wo-checkbox">
                        <input type="checkbox" name="save_default_payer" value="1" />
                        <?php echo xlt('Save as default payer for future ERAs from this source'); ?>
                    </label>
                </div>

                <!-- Date fields -->
                <div style="padding:14px 18px;border-top:1px solid var(--border);">
                    <div class="date-grid">
                        <div class="form-group">
                            <label class="form-label"><?php echo xlt('EFT / Check Date'); ?></label>
                            <input class="form-control" id="check_date" name="check_date"
                                   type="text" readonly
                                   value="<?php echo attr($check_date); ?>"
                                   placeholder="<?php echo xla('Auto-filled from ERA'); ?>"
                                   style="background:var(--bg);cursor:default;color:var(--text2);"
                                   form="era-form" />
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?php echo xlt('Post To Date'); ?></label>
                            <input class="form-control" id="post_to_date" name="post_to_date"
                                   type="text" readonly
                                   value="<?php echo attr($post_to_date); ?>"
                                   placeholder="<?php echo xla('Auto-filled from ERA'); ?>"
                                   style="background:var(--bg);cursor:default;color:var(--text2);"
                                   form="era-form" />
                        </div>
                        <div class="form-group">
                            <label class="form-label"><?php echo xlt('Deposit Date'); ?></label>
                            <input class="form-control" id="deposit_date" name="deposit_date"
                                   type="text" readonly
                                   value="<?php echo attr($deposit_date); ?>"
                                   placeholder="<?php echo xla('Auto-filled from ERA'); ?>"
                                   style="background:var(--bg);cursor:default;color:var(--text2);"
                                   form="era-form" />
                        </div>
                    </div>
                    <!-- Hidden form_without — controlled by processing mode cards below -->
                    <input type="hidden" name="form_without" id="form_without" value="0" form="era-form" />
                </div>

            </div><!-- /era-info-card -->

        </div><!-- /left col -->

        <!-- ══ RIGHT COLUMN ══ -->
        <div style="display:flex;flex-direction:column;gap:16px;">

                        <!-- 4. Posting Summary -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-title-num">4</span>
                        <?php echo xlt('Posting Summary (Preview)'); ?>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Empty state: shown until a file is selected -->
                    <div id="summary-empty" style="text-align:center;padding:20px;color:var(--text3);font-size:12px;">
                        <?php echo xlt('Select an ERA file to see the posting summary.'); ?>
                    </div>
                    <!-- Summary grid: hidden until file is selected, populated client-side -->
                    <div class="summary-grid" id="summary-grid" style="display:none;margin-bottom:4px;">
                        <div class="summary-cell">
                            <div class="summary-num" id="summary-total">
                                <?php echo !empty($eracount) ? text($eracount) : '—'; ?>
                                <span class="s-icon" style="background:var(--blue-l);">
                                    <svg width="11" height="11" fill="none" stroke="var(--blue)" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
                                </span>
                            </div>
                            <div class="summary-lbl"><?php echo xlt('Total Claims'); ?></div>
                        </div>
                        <div class="summary-cell">
                            <div class="summary-num" style="color:var(--green);" id="summary-parsed">
                                <?php echo !empty($eracount) ? text($eracount) : '—'; ?>
                                <span class="s-icon" style="background:var(--green-l);">
                                    <svg width="11" height="11" fill="none" stroke="var(--green)" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                </span>
                            </div>
                            <div class="summary-lbl"><?php echo xlt('Parsed Claims'); ?></div>
                        </div>
                        <div class="summary-cell">
                            <div class="summary-num" style="color:var(--amber);" id="summary-review">
                                0
                                <span class="s-icon" style="background:var(--amber-l);">
                                    <svg width="11" height="11" fill="none" stroke="var(--amber)" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                </span>
                            </div>
                            <div class="summary-lbl"><?php echo xlt('Require Review'); ?></div>
                        </div>
                        <div class="summary-cell">
                            <div class="summary-num" style="color:var(--red);" id="summary-denied">
                                0
                                <span class="s-icon" style="background:var(--red-l);">
                                    <svg width="11" height="11" fill="none" stroke="var(--red)" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </span>
                            </div>
                            <div class="summary-lbl"><?php echo xlt('Denied Claims'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 5. Processing Mode -->
            <div class="card" style="display:none">
                <div class="card-header">
                    <div class="card-title">
                        <span class="card-title-num">5</span>
                        <?php echo xlt('Processing Mode'); ?>
                    </div>
                </div>
                <div class="card-body">
                    <div class="mode-grid" style="grid-template-columns:1fr 1fr;">
                        <div class="mode-card selected" id="mode-auto" onclick="setProcessingMode('auto')">
                            <input type="radio" name="processing_mode" value="auto_post" checked />
                            <svg width="16" height="16" fill="none" stroke="var(--purple)" stroke-width="2" viewBox="0 0 24 24" style="margin-bottom:4px;"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            <div class="mode-title"><?php echo xlt('Auto Post'); ?></div>
                            <div class="mode-sub"><?php echo xlt('Post matched claims automatically'); ?></div>
                        </div>
                        <div class="mode-card" id="mode-manual" onclick="setProcessingMode('manual')">
                            <input type="radio" name="processing_mode" value="manual_review" />
                            <svg width="16" height="16" fill="none" stroke="var(--text2)" stroke-width="2" viewBox="0 0 24 24" style="margin-bottom:4px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <div class="mode-title"><?php echo xlt('Manual Review'); ?></div>
                            <div class="mode-sub"><?php echo xlt('Review all claims before posting (Without Update)'); ?></div>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /right col -->

    </div><!-- /era-body -->

    <!-- ── Footer action bar ── -->
    <footer class="era-footer">
        <button type="button" class="btn btn-ghost" onclick="history.back();">
            <?php echo xlt('Cancel'); ?>
        </button>
        <div style="display:flex;gap:10px;align-items:center;">
            <button type="button"  style="display:none;" class="btn btn-outline" onclick="doManualReview();"
                    title="<?php echo xla('Opens a popup to review all claims before posting (Without Update)'); ?>">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                <?php echo xlt('Manual Review'); ?>
            </button>
            <button type="button" class="btn btn-primary" onclick="doAutoPost();">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <?php echo xlt('Post Batch'); ?>
            </button>
        </div>
    </footer>

    <!-- ── Validation modal ── -->
    <div class="val-modal-overlay" id="val-modal-overlay">
        <div class="val-modal">
            <div class="val-modal-hdr">
                <div class="val-modal-title">
                    <div class="val-modal-icon" id="val-modal-icon"></div>
                    <span id="val-modal-title-text"></span>
                </div>
                <button type="button" class="btn btn-ghost" style="padding:3px 8px;font-size:11px;" onclick="closeValModal()">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="val-modal-body">
                <div class="val-summary-bar" id="val-modal-pills"></div>
                <div id="val-modal-checks"></div>
            </div>
            <div class="val-modal-ftr" id="val-modal-footer"></div>
        </div>
    </div>

</div><!-- /era-shell -->

<?php $oemr_ui->oeBelowContainerDiv(); ?>
<script src="<?php echo $webroot; ?>/library/js/oeUI/oeFileUploads.js"></script>
</body>
</html>
