<?php

/**
 * Batch list processor, included from batchcom
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @author  cfapress
 * @author  Jason 'Toolbox' Oettinger <jason@oettinger.email>
 * @copyright Copyright (c) 2008 cfapress
 * @copyright Copyright (c) 2017 Jason 'Toolbox' Oettinger <jason@oettinger.email>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 * @todo menu for fields could be added in the future
 */

require_once("../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

?>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt("Phone Call List"); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        :root{--bc-navy:#1a1a2e;--bc-navy-mid:#16213e;--bc-navy-light:#0f3460;--bc-purple:#7c3aed;--bc-purple-mid:#6d28d9;--bc-purple-light:#8b5cf6;--bc-purple-pale:#ede9fe;--bc-purple-ghost:#f5f3ff;--bc-white:#ffffff;--bc-gray-100:#f3f4f6;--bc-gray-200:#e5e7eb;--bc-gray-300:#d1d5db;--bc-gray-400:#9ca3af;--bc-gray-500:#6b7280;--bc-gray-700:#374151;--bc-gray-900:#111827;--bc-shadow-sm:0 1px 3px rgba(0,0,0,.08);--bc-radius:10px;--bc-radius-sm:6px;}
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
        .bc-table-wrap{overflow-x:auto;border-radius:var(--bc-radius);box-shadow:var(--bc-shadow-sm);}
        table.bc-table{width:100%;border-collapse:collapse;background:var(--bc-white);}
        table.bc-table thead tr{background:var(--bc-navy);}
        table.bc-table thead th{padding:11px 16px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#fff;border:none;white-space:nowrap;}
        table.bc-table thead th:first-child{border-radius:var(--bc-radius-sm) 0 0 0;}
        table.bc-table thead th:last-child{border-radius:0 var(--bc-radius-sm) 0 0;}
        table.bc-table tbody tr{border-bottom:1px solid var(--bc-gray-100);transition:background .12s;}
        table.bc-table tbody tr:hover{background:var(--bc-purple-ghost);}
        table.bc-table tbody td{padding:10px 16px;font-size:13px;color:var(--bc-gray-700);}
        table.bc-table tbody tr:last-child td{border-bottom:none;}
        table.bc-table tbody td:first-child{font-weight:600;color:var(--bc-gray-900);}
        .bc-meta{font-size:12px;color:var(--bc-gray-400);padding:12px 0 6px;display:flex;align-items:center;gap:6px;}
        ::-webkit-scrollbar{width:6px;height:6px;}::-webkit-scrollbar-track{background:transparent;}::-webkit-scrollbar-thumb{background:var(--bc-gray-300);border-radius:99px;}
    </style>
</head>
<body class="body_top">
<div style="max-width:1100px;margin:0 auto;padding:0 20px;">
    <header>
        <?php require_once("batch_navigation.php");?>
        <div class="bc-hero">
            <div class="bc-hero-inner">
                <div class="bc-hero-icon">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6.06 6.06l1.27-.95a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                </div>
                <div>
                    <h1><?php echo xlt('Phone Call List'); ?></h1>
                    <div class="bc-hero-sub"><a href="batchcom.php"><?php echo xlt('Batch Communication Tool'); ?></a> &rsaquo; <?php echo xlt('Phone Call List Report'); ?></div>
                </div>
            </div>
        </div>
    </header>
    <main style="padding:0 4px 32px; margin-left:0px !important;">
        <div class="bc-meta">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <?php echo xlt('Generated patient contact list based on selected filters'); ?>
        </div>
        <div class="bc-table-wrap">
            <table class="bc-table">
                <thead>
                    <tr>
                        <?php foreach ([xlt('Name'), xlt('DOB'), xlt('Home'), xlt('Work'), xlt('Contact'), xlt('Cell')] as $header) {
                            echo "<th>$header</th>";
                        } ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    while ($row = sqlFetchArray($res)) {
                        echo "<tr><td>";
                        echo text($row['title']) . ' ' . text($row['fname']) . ' ' . text($row['lname']);
                        echo "</td><td>";
                        echo text($row['DOB']);
                        echo "</td><td>";
                        echo text($row['phone_home']);
                        echo "</td><td>";
                        echo text($row['phone_biz']);
                        echo "</td><td>";
                        echo text($row['phone_contact']);
                        echo "</td><td>";
                        echo text($row['phone_cell']);
                        echo "</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </main>
</div>
</body>
</html>
