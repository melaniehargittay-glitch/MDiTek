<?php
/**
 * MDItek Fee Sheet Bridge
 * Sets session then includes new.php directly — no redirect.
 */

require_once("../../globals.php");
require_once("$srcdir/pid.inc.php");

use OpenEMR\Common\Session\SessionUtil;

$encounter = isset($_GET['encounter']) ? (int)$_GET['encounter'] : 0;
$pid_in    = isset($_GET['pid'])       ? (int)$_GET['pid']       : 0;

if (!$encounter || !$pid_in) { die(xlt('Invalid encounter or patient ID.')); }

setpid($pid_in);
SessionUtil::setSession('pid', $pid_in);
SessionUtil::setSession('encounter', $encounter);

require($GLOBALS['fileroot'] . '/interface/forms/fee_sheet/new.php');
exit;
