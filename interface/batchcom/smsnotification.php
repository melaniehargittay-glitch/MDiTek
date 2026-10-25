<?php

/**
 * smsnotification script.
 *
 * @package OpenEMR
 * @author  cfapress
 * @author  Jason 'Toolbox' Oettinger <jason@oettinger.email>
 * @link    http://www.open-emr.org
 * @copyright Copyright (c) 2008 cfapress
 * @copyright Copyright (c) 2017 Jason 'Toolbox' Oettinger <jason@oettinger.email>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/registry.inc.php");
require_once("batchcom.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

// gacl control
if (!AclMain::aclCheckCore('admin', 'notification')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("SMS Notification")]);
    exit;
}

// process form
if (!empty($_POST['form_action']) && ($_POST['form_action'] == 'save')) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    if (! is_numeric($_POST['notification_id'])) {  // shouldn't happen
        $form_err .= xl('Missing/invalid notification id') . '<br />';
    }

    if (empty($_POST['sms_gateway_type'])) {
        $form_err .= xl('Error in "SMS Gateway" selection') . '<br />';
    }

    if (empty($_POST['provider_name'])) {
        $form_err .= xl('Empty value in "Name of Provider"') . '<br />';
    }

    if (empty($_POST['message'])) {
        $form_err .= xl('Empty value in "SMS Text"') . '<br />';
    }

    // Store the new settings.  email_sender and email_subject are not used
    // by SMS, but must be present because they are NOT NULL.  notification_id
    // is the pk, and should always be 1 for SMS settings (because that's how
    // the db was seeded).

    if (!$form_err) {
        $sql_text = " ( `notification_id` , `sms_gateway_type` , `provider_name` , `message` , `email_sender` , `email_subject` , `type` ) ";
        $sql_value = " (?, ?, ?, ?, ?, ?, ?) ";
        $values = array($_POST['notification_id'], $_POST['sms_gateway_type'],
                        $_POST['provider_name'], $_POST['message'],
                        '', '', 'SMS');
        $query = "REPLACE INTO `automatic_notification` $sql_text VALUES $sql_value";
        //echo $query;
        $id = sqlInsert($query, $values);
        $sql_msg = xl("ERROR!... in Update");
        if ($id) {
            $sql_msg = xl("SMS Notification Settings Updated Successfully");
        }
    }
}

// fetch SMS config from table.  This should never fail, because one row
// of each type is seeded when the db is created.

$sql = "select * from automatic_notification where type='SMS'";
$result = sqlQuery($sql);
if ($result) {
    $notification_id = $result['notification_id'];
    $sms_gateway_type = $result['sms_gateway_type'];
    $provider_name = $result['provider_name'];
    $message = $result['message'];
} else {
    $sql_msg = xl('Missing SMS config record');
}

// array of legal values for sms_gateway_type.  This is a string field in
// the db, not an enum, so new values can be added here with no db change.
$sms_gateway = array ('CLICKATELL','TMB4');

//START OUT OUR PAGE....
?>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt("SMS Notification"); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bc-navy:#1a1a2e;--bc-navy-mid:#16213e;--bc-navy-light:#0f3460;
            --bc-purple:#7c3aed;--bc-purple-mid:#6d28d9;--bc-purple-light:#8b5cf6;
            --bc-purple-pale:#ede9fe;--bc-purple-ghost:#f5f3ff;
            --bc-white:#ffffff;--bc-gray-50:#f9fafb;--bc-gray-100:#f3f4f6;
            --bc-gray-200:#e5e7eb;--bc-gray-300:#d1d5db;--bc-gray-400:#9ca3af;
            --bc-gray-500:#6b7280;--bc-gray-700:#374151;--bc-gray-900:#111827;
            --bc-red-pale:#fee2e2;--bc-red:#dc2626;
            --bc-blue-pale:#dbeafe;--bc-blue:#2563eb;
            --bc-green-pale:#d1fae5;--bc-green:#059669;
            --bc-shadow-sm:0 1px 3px rgba(0,0,0,.08);--bc-radius:10px;--bc-radius-sm:6px;
        }
        *,*::before,*::after{box-sizing:border-box;}
        body.body_top{font-family:'DM Sans',system-ui,sans-serif!important;background:var(--bc-gray-100)!important;color:var(--bc-gray-900)!important;font-size:14px;line-height:1.55;padding-bottom:40px;}
        .bc-hero{background:linear-gradient(135deg,var(--bc-navy) 0%,var(--bc-navy-light) 60%,var(--bc-purple-mid) 100%);padding:26px 32px 22px;margin-bottom:28px;border-radius:0 0 var(--bc-radius) var(--bc-radius);position:relative;overflow:hidden;}
        .bc-hero::after{content:'';position:absolute;inset:0;background:repeating-linear-gradient(-45deg,transparent,transparent 28px,rgba(255,255,255,.025) 28px,rgba(255,255,255,.025) 56px);pointer-events:none;}
        .bc-hero-inner{position:relative;z-index:1;display:flex;align-items:center;gap:16px;}
        .bc-hero-icon{width:46px;height:46px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:var(--bc-radius);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .bc-hero h1{font-size:19px!important;font-weight:700!important;color:#fff!important;margin:0!important;letter-spacing:-.01em;}
        .bc-hero-sub{font-size:13px;color:rgba(255,255,255,.55);margin-top:3px;}
        .bc-hero-sub a{color:rgba(255,255,255,.75);text-decoration:none;border-bottom:1px solid rgba(255,255,255,.3);}
        .bc-hero-sub a:hover{color:#fff;}
        .alert{border-radius:var(--bc-radius-sm)!important;font-size:13px!important;font-weight:500!important;padding:12px 16px!important;display:flex!important;align-items:flex-start!important;gap:10px!important;margin-bottom:20px!important;border-width:1.5px!important;border-style:solid!important;}
        .alert-danger{background:var(--bc-red-pale)!important;border-color:#fca5a5!important;color:var(--bc-red)!important;}
        .alert-info{background:var(--bc-blue-pale)!important;border-color:#93c5fd!important;color:var(--bc-blue)!important;}
        .alert-success{background:var(--bc-green-pale)!important;border-color:#6ee7b7!important;color:var(--bc-green)!important;}
        .bc-section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--bc-gray-500);margin-bottom:12px;display:flex;align-items:center;gap:8px;}
        .bc-section-label::after{content:'';flex:1;height:1px;background:var(--bc-gray-200);}
        .bc-grid{display:grid;gap:14px;}
        .bc-grid-2{grid-template-columns:repeat(2,1fr);}
        .bc-full{grid-column:1/-1;}
        @media(max-width:600px){.bc-grid-2{grid-template-columns:1fr;}}
        .bc-card{background:var(--bc-white);border:1.5px solid var(--bc-gray-200);border-radius:var(--bc-radius);padding:16px 18px;box-shadow:var(--bc-shadow-sm);transition:border-color .18s,box-shadow .18s;}
        .bc-card:focus-within{border-color:var(--bc-purple-light);box-shadow:0 0 0 3px rgba(124,58,237,.10),var(--bc-shadow-sm);}
        .bc-card-header{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
        .bc-card-icon{width:28px;height:28px;background:var(--bc-purple-ghost);border-radius:var(--bc-radius-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .bc-card-icon svg{color:var(--bc-purple);}
        .bc-card-title{font-size:12px;font-weight:700;color:var(--bc-navy);text-transform:uppercase;letter-spacing:.05em;}
        label{display:block!important;font-size:11px!important;font-weight:700!important;color:var(--bc-gray-500)!important;text-transform:uppercase!important;letter-spacing:.04em!important;margin-bottom:5px!important;}
        .form-control{width:100%!important;padding:8px 12px!important;border:1.5px solid var(--bc-gray-200)!important;border-radius:var(--bc-radius-sm)!important;font-family:'DM Sans',system-ui,sans-serif!important;font-size:13px!important;color:var(--bc-gray-900)!important;background:var(--bc-white)!important;outline:none!important;transition:border-color .15s,box-shadow .15s!important;-webkit-appearance:none;appearance:none;}
        .form-control:focus{border-color:var(--bc-purple-light)!important;box-shadow:0 0 0 3px rgba(124,58,237,.12)!important;}
        select.form-control{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7280' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E")!important;background-repeat:no-repeat!important;background-position:right 11px center!important;padding-right:30px!important;}
        textarea.form-control{resize:vertical;min-height:140px;line-height:1.6;}
        .bc-tags-hint{font-size:11px;color:var(--bc-gray-400);margin-top:6px;line-height:1.8;}
        .bc-tag{display:inline-block;background:var(--bc-purple-ghost);color:var(--bc-purple);border:1px solid var(--bc-purple-pale);border-radius:4px;padding:1px 6px;font-size:11px;font-weight:600;letter-spacing:.02em;}
        .bc-submit-row{display:flex;align-items:center;gap:14px;padding-top:6px;}
        .btn{font-family:'DM Sans',system-ui,sans-serif!important;font-size:13px!important;font-weight:600!important;border-radius:var(--bc-radius-sm)!important;padding:9px 22px!important;border:none!important;cursor:pointer!important;display:inline-flex!important;align-items:center!important;gap:7px!important;transition:all .15s!important;line-height:1!important;}
        .btn-save,.btn-primary{background:var(--bc-purple)!important;color:#fff!important;box-shadow:0 2px 8px rgba(124,58,237,.25)!important;}
        .btn-save:hover,.btn-primary:hover{background:var(--bc-purple-mid)!important;box-shadow:0 4px 14px rgba(124,58,237,.35)!important;transform:translateY(-1px);}
        ::-webkit-scrollbar{width:6px;height:6px;}::-webkit-scrollbar-track{background:transparent;}::-webkit-scrollbar-thumb{background:var(--bc-gray-300);border-radius:99px;}
        ::placeholder{color:var(--bc-gray-400)!important;font-size:12px;}
    </style>
</head>
<body class="body_top">
<div style="max-width:960px;margin:0 auto;padding:0 20px;">
    <header>
        <?php require_once("batch_navigation.php");?>
        <div class="bc-hero">
            <div class="bc-hero-inner">
                <div class="bc-hero-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                </div>
                <div>
                    <h1><?php echo xlt('SMS Notification'); ?></h1>
                    <div class="bc-hero-sub"><a href="batchcom.php"><?php echo xlt('Batch Communication Tool'); ?></a> &rsaquo; <?php echo xlt('SMS Notification Settings'); ?></div>
                </div>
            </div>
        </div>
    </header>
    <main style="padding:0 4px 32px; margin-left:0px !important;">
        <?php
        if (!empty($form_err)) {
            echo '<div class="alert alert-danger"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' . xlt('The following errors occurred') . ': ' . text($form_err) . '</div>';
        }
        if (!empty($sql_msg)) {
            $is_err = strpos($sql_msg, 'ERROR') !== false;
            $icon = $is_err
                ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
                : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
            $cls = $is_err ? 'alert-danger' : 'alert-success';
            echo '<div class="alert ' . $cls . '">' . $icon . text($sql_msg) . '</div>';
        }
        ?>
        <form name="select_form" method="post" action="">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type="hidden" name="type" value="SMS">
            <input type="hidden" name="notification_id" value="<?php echo attr($notification_id); ?>">

            <div class="bc-section-label">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2" ry="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                <?php echo xlt('Gateway Configuration'); ?>
            </div>
            <div class="bc-grid bc-grid-2" style="margin-bottom:22px;">
                <div class="bc-card">
                    <div class="bc-card-header">
                        <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
                        <span class="bc-card-title"><?php echo xlt('SMS Gateway'); ?></span>
                    </div>
                    <label for="sms_gateway_type"><?php echo xlt('Select Gateway'); ?></label>
                    <select name="sms_gateway_type" class="form-control">
                        <option value=""><?php echo xlt('Select SMS Gateway'); ?></option>
                        <?php foreach ($sms_gateway as $value) { ?>
                            <option value="<?php echo attr($value); ?>" <?php if ($sms_gateway_type == $value) echo "selected"; ?>><?php echo text($value); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="bc-card">
                    <div class="bc-card-header">
                        <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                        <span class="bc-card-title"><?php echo xlt('Provider'); ?></span>
                    </div>
                    <label for="provider_name"><?php echo xlt('Name of Provider'); ?></label>
                    <input class="form-control" type="text" name="provider_name" value="<?php echo attr($provider_name); ?>" placeholder="<?php echo xla('provider name'); ?>">
                </div>
            </div>

            <div class="bc-section-label">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                <?php echo xlt('Message Template'); ?>
            </div>
            <div class="bc-card bc-full" style="margin-bottom:22px;">
                <div class="bc-card-header">
                    <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg></div>
                    <span class="bc-card-title"><?php echo xlt('SMS Text'); ?></span>
                </div>
                <label for="message"><?php echo xlt('Message Body'); ?></label>
                <textarea class="form-control" cols="35" rows="7" name="message"><?php echo text($message); ?></textarea>
                <div class="bc-tags-hint">
                    <?php echo xlt('Available tags'); ?>:
                    <span class="bc-tag">***NAME***</span>
                    <span class="bc-tag">***PROVIDER***</span>
                    <span class="bc-tag">***DATE***</span>
                    <span class="bc-tag">***STARTTIME***</span>
                    <span class="bc-tag">***ENDTIME***</span>
                    &mdash; <?php echo xlt('e.g.'); ?> <em>Dear ***NAME***</em>
                </div>
            </div>

            <div class="bc-submit-row">
                <button class="btn btn-save" type="submit" name="form_action" value="save">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?php echo xlt('Save'); ?>
                </button>
            </div>
        </form>
    </main>
</div>
</body>
</html>
