<?php

/**
 * Functions to globally validate and prepare data for sql database insertion.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    MMF Systems, Inc
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2010 MMF Systems, Inc
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019-2020 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__file__) . "/../globals.php");
require_once("$srcdir/forms.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/report.inc.php");
require_once("$srcdir/calendar.inc.php");

use OpenEMR\Billing\EDI270;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }
}

//  File location (URL or server path)
$target = $GLOBALS['edi_271_file_path'];
$batch_log = '';

if (isset($_FILES) && !empty($_FILES)) {
    $target = $target . time() . basename($_FILES['uploaded']['name']);

    if ($_FILES['uploaded']['size'] > 350000) {
        $message .=  xlt('Your file is too large') . "<br />";
    }
    if (mime_content_type($_FILES['uploaded']['tmp_name']) != "text/plain") {
        $message .= xlt('You may only upload .txt files') . "<br />";
    }
    if (preg_match("/(.*)\.(inc|php|php7|php8)$/i", $_FILES['uploaded']['name']) !== 0) {
        $message .= xlt('Invalid file type.') . "<br />";
    }
    if (!isset($message)) {
        $cryptoGen = new CryptoGen();
        $uploadedFile = file_get_contents($_FILES['uploaded']['tmp_name']);
        if ($GLOBALS['drive_encryption']) {
            $uploadedFile = $cryptoGen->encryptStandard($uploadedFile, null, 'database');
        }
        if (file_put_contents($target, $uploadedFile)) {
            $message = xlt('The following EDI file has been uploaded') . ': "' . text(basename($_FILES['uploaded']['name'])) . '"';
            $Response271 = file_get_contents($target);
            if ($cryptoGen->cryptCheckStandard($Response271)) {
                $Response271 = $cryptoGen->decryptStandard($Response271, null, 'database');
            }
            if ($Response271) {
                $batch_log = EDI270::parseEdi271($Response271);
            } else {
                $message = xlt('The following EDI file upload failed to open') . ': "' . text(basename($_FILES['uploaded']['name'])) . '"';
            }
        } else {
            $message = xlt('The following EDI file failed save to archive') . ': "' . text(basename($_FILES['uploaded']['name'])) . '"';
        }
    } else {
        $message .= xlt('Sorry, there was a problem uploading your file') . "<br /><br />";
    }
}
if ($batch_log && !$GLOBALS['disable_eligibility_log']) {
    $fn = sprintf(
        'elig-batch_log_%s.txt',
        date("Y-m-d:H:i:s")
    );
    $batch_log = str_replace('~', "~\r", $batch_log);
    while (@ob_end_flush()) {
    }
    header('Content-Type: text/plain');
    header("Content-Length: " . strlen($batch_log));
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    ob_start();
    echo $batch_log;
    exit();
}
?>
<html>
<head>
<title><?php echo xlt('EDI-271 Response File Upload'); ?></title>

<?php Header::setupHeader(); ?>

<style>
        /* MDI Report Layout */
        .mdi-report-header {
            background: #1e0a3c;
            color: #fff;
            padding: 10px 20px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .mdi-filter-bar {
            background: #f8f7fc;
            border-bottom: 1px solid #e0d8f4;
            padding: 8px 16px;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px 16px;
        }
        .mdi-filter-group {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: #5b21b6;
            white-space: nowrap;
        }
        .mdi-filter-group .form-control {
            height: 30px;
            font-size: 12.5px;
            padding: 3px 8px;
            border-radius: 5px;
            border: 1px solid #d1c7f0;
            background: #fff;
            color: #1e0a3c;
            min-width: 110px;
        }
        .mdi-filter-group select.form-control { min-width: 140px; }
        .mdi-filter-group .mdi-x12-wrap { display: flex; flex-direction: column; gap: 3px; }
        .mdi-x12-warn { font-size: 11px; color: #dc2626; font-weight: 600; text-transform: none; letter-spacing: 0; }
        .mdi-btn-submit {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 14px; border-radius: 6px;
            background: #5b21b6; color: #fff !important; border: none;
            font-size: 12px; font-weight: 700; cursor: pointer;
            transition: background .15s; white-space: nowrap;
            text-decoration: none;
        }
        .mdi-btn-submit:hover { background: #4c1d95; color: #fff !important; }
        .mdi-btn-secondary {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 6px;
            background: #fff; color: #5b21b6 !important;
            border: 1px solid #d1c7f0;
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: all .15s; white-space: nowrap;
            text-decoration: none;
        }
        .mdi-btn-secondary:hover { background: #5b21b6; color: #fff !important; border-color: #5b21b6; }
        .mdi-results-wrap { padding: 12px 16px; }
        .mdi-hint {
            font-size: 12px; color: #6b7280; font-style: italic;
            padding: 10px 16px;
        }
        @media print {
            .mdi-report-header, .mdi-filter-bar { display: none; }
            #report_parameters_daterange { visibility: visible; display: inline; }
            #report_results table { margin-top: 0; }
            .mdi-results-wrap { padding: 0; }
        }
        @media screen {
            #report_parameters_daterange { visibility: hidden; display: none; }
        }
</style>
<script>
    function edivalidation() {
        var mypcc = <?php echo xlj('Required Field Missing: Please choose the EDI-271 file to upload'); ?>;
        if (document.getElementById('uploaded').value == "") {
            alert(mypcc);
            return false;
        } else {
            $("#theform").trigger("submit");
        }
    }
</script>
</head>
<body class="body_top">

<div id="overDiv" style="position:absolute;visibility:hidden;z-index:1000;"></div>

<?php if (isset($message) && !empty($message)) { ?>
    <div class="mdi-results-wrap">
        <div class="alert alert-info" style="font-size:13px;"><?php echo $message; $message = ""; ?></div>
    </div>
<?php } ?>
<?php if (isset($messageEDI)) { ?>
    <div class="mdi-results-wrap">
        <div class="alert alert-danger" style="font-size:13px;"><?php echo xlt('Please choose the proper formatted EDI-271 file'); $messageEDI = ""; ?></div>
    </div>
<?php } ?>

<!-- Page Title Bar -->
<div class="mdi-report-header"><?php echo xlt('EDI-271 Response File Upload'); ?></div>

<form enctype="multipart/form-data" name="theform" id="theform" action="edi_271.php" method="POST" onsubmit="return top.restoreSession()">
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type="hidden" name="form_orderby" value="<?php echo attr($form_orderby ?? ''); ?>" />
<input type='hidden' name='form_refresh' id='form_refresh' value=''/>

<!-- Filter Bar -->
<div id="report_parameters" class="mdi-filter-bar">
    <span class="mdi-filter-group">
        <?php echo xlt('EDI-271 File'); ?>:
        <input name="uploaded" id="uploaded" type="file"
            style="font-size:12px;color:#374151;border:1px solid #d1c7f0;border-radius:5px;padding:3px 8px;background:#fff;cursor:pointer;" />
    </span>
    <a href='#' class='mdi-btn-submit' onclick='return edivalidation();'>
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <?php echo xlt('Upload'); ?>
    </a>
</div>

<div class="mdi-hint">
    <?php echo xlt('Select an EDI-271 response file and click Upload to process eligibility results.'); ?>
</div>

</form>
</body>
</html>
