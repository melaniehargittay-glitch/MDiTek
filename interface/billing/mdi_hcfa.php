<?php
/**
 * mdi_hcfa.php
 * Auto-forwards to mdi_hcfa_print.php within the same popup window.
 */
require_once("../globals.php");
use OpenEMR\Common\Csrf\CsrfUtils;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '')) {
    die("Invalid CSRF Token");
}
$selected = $_POST['mdi_encounters'] ?? [];
if (empty($selected)) { die("No encounters selected."); }
$newToken = attr(CsrfUtils::collectCsrfToken());
?><!DOCTYPE html><html><head><meta charset="utf-8">
<style>body{margin:0;background:#f5f3ff;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:sans-serif;color:#5b21b6;font-size:13px;gap:8px;}
@keyframes spin{to{transform:rotate(360deg);}}
.sp{width:16px;height:16px;border:2px solid #ddd6fe;border-top-color:#5b21b6;border-radius:50%;animation:spin .7s linear infinite;}</style>
</head><body>
<div class="sp"></div> <?php echo xlt('Generating HCFA 1500...'); ?>
<form id="f" method="post" action="mdi_hcfa_print.php">
<input type="hidden" name="csrf_token_form" value="<?php echo $newToken; ?>">
<?php foreach ($selected as $enc): ?><input type="hidden" name="mdi_encounters[]" value="<?php echo attr($enc); ?>"><?php endforeach; ?>
</form>
<script>document.getElementById('f').submit();</script>
</body></html>
