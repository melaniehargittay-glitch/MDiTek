<?php

/**
 * Batch Communication Tool for selecting/communicating with subsets of patients
 *
 * @package OpenEMR
 * @author  Brady Miller <brady.g.miller@gmail.com>
 * @author  Jason 'Toolbox' Oettinger <jason@oettinger.email>
 * @link    http://www.open-emr.org
 * @copyright Copyright (c) 2017 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017 Jason 'Toolbox' Oettinger <jason@oettinger.email>
 * @license https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

//INCLUDES, DO ANY ACTIONS, THEN GET OUR DATA
require_once("../globals.php");
require_once("$srcdir/registry.inc.php");
require_once("batchcom.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('admin', 'batchcom')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("BatchCom")]);
    exit;
}

// menu arrays (done this way so it's easier to validate input on validate selections)
$process_choices = array(xl('Send Emails'), xl('Download CSV File'), xl('Phone call list'));
$gender_choices = array(xl('Any{{Gender}}'), xl('Male'), xl('Female'));
$hipaa_choices = array(xl('No'), xl('Yes'));
$sort_by_choices = array(xl('Zip Code') => 'patient_data.postal_code', xl('Last Name') => 'patient_data.lname', xl('Appointment Date') => 'last_appt');

// Default sender email — pulled from Notifications settings so it matches
// the configured notification address without staff having to retype it.
// Prefer patient_reminder_sender_email, fall back to practice_return_email_name.
$default_sender_email = trim(
    $GLOBALS['patient_reminder_sender_email']
    ?? $GLOBALS['practice_return_email_name']
    ?? ''
);

// process form
if (!empty($_POST['form_action']) && ($_POST['form_action'] == 'process')) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    //validation uses the functions in batchcom.inc.php
    //validate dates
    if (!check_date_format($_POST['app_s'])) {
        $form_err .= xl('Date format for "appointment start" is not valid');
    }

    if (!check_date_format($_POST['app_e'])) {
        $form_err .= xl('Date format for "appointment end" is not valid');
    }

    if (!check_date_format($_POST['seen_since'])) {
        $form_err .= xl('Date format for "seen since" is not valid');
    }

    if (!check_date_format($_POST['seen_before'])) {
        $form_err .= xl('Date format for "seen before" is not valid');
    }

    // validate numbers
    if (!check_age($_POST['age_from'])) {
        $form_err .= xl('Age format for "age from" is not valid');
    }

    if (!check_age($_POST['age_upto'])) {
        $form_err .= xl('Age format for "age up to" is not valid');
    }

    // validate selections
    if (!check_select($_POST['gender'], $gender_choices)) {
        $form_err .= xl('Error in "Gender" selection');
    }

    if (!check_select($_POST['process_type'], $process_choices)) {
        $form_err .= xl('Error in "Process" selection');
    }

    if (!check_select($_POST['hipaa_choice'], $hipaa_choices)) {
        $form_err .= xl('Error in "HIPAA" selection');
    }

    if (!check_select($_POST['sort_by'], $sort_by_choices)) {
        $form_err .= xl('Error in "Sort By" selection');
    }

    //process sql
    if (!$form_err) {
        $sql = "select patient_data.*, cal_events.pc_eventDate as next_appt, cal_events.pc_startTime
                    as appt_start_time,cal_date.last_appt,forms.last_visit from patient_data
                    left outer join openemr_postcalendar_events as cal_events on patient_data.pid=cal_events.pc_pid
                    and curdate() < cal_events.pc_eventDate left outer join (select pc_pid,max(pc_eventDate)
                    as last_appt from openemr_postcalendar_events where curdate() >= pc_eventDate group by pc_pid )
                    as cal_date on cal_date.pc_pid=patient_data.pid left outer join (select pid,max(date)
                    as last_visit from forms where curdate() >= date group by pid)
                    as forms on forms.pid=patient_data.pid where 1=1";
        $params = array();

        //appointment dates
        if ($_POST['app_s'] != 0 and $_POST['app_s'] != '') {
            $sql .= " and cal_events.pc_eventDate >= ?";
            array_push($params, $_POST['app_s']);
        }

        if ($_POST['app_e'] != 0 and $_POST['app_e'] != '') {
            $sql .= " and cal_events.pc_endDate <= ?";
            array_push($params, $_POST['app_e']);
        }

        // encounter dates
        if ($_POST['seen_since'] != 0 and $_POST['seen_since'] != '') {
            $sql .= " and forms.date >= ?" ;
            array_push($params, $_POST['seen_since']);
        }

        if ($_POST['seen_before'] != 0 and $_POST['seen_before'] != '') {
            $sql .= " and forms.date <= ?" ;
            array_push($params, $_POST['seen_before']);
        }

        // age
        if ($_POST['age_from'] != 0 and $_POST['age_from'] != '') {
            $sql .= " and DATEDIFF( CURDATE( ), patient_data.DOB )/ 365.25 >= ?";
            array_push($params, $_POST['age_from']);
        }

        if ($_POST['age_upto'] != 0 and $_POST['age_upto'] != '') {
            $sql .= " and DATEDIFF( CURDATE( ), patient_data.DOB )/ 365.25 <= ?";
            array_push($params, $_POST['age_upto']);
        }

        // gender
        if ($_POST['gender'] != 'Any') {
            $sql .= " and patient_data.sex=?";
            array_push($params, $_POST['gender']);
        }

        // hipaa override
        if ($_POST['hipaa_choice'] != $hipaa_choices[0]) {
            $sql .= " and patient_data.hipaa_mail='YES' ";
        }

        switch ($_POST['process_type']) :
            case $choices[1]: // Email
                $sql .= " and patient_data.email IS NOT NULL ";
                break;
        endswitch;

        // sort by
        $sql .= ' ORDER BY ' . escape_identifier($_POST['sort_by'], array_values($sort_by_choices), true);
        // send query for results.
        //echo $sql;exit();
        $res = sqlStatement($sql, $params);

        if (sqlNumRows($res) == 0) {
            $form_err = xl('No results found, please try again.');
        } else {
            switch ($_POST['process_type']) :
                case $process_choices[0]: // Email
                    require_once('batchEmail.php');
                    exit();
                case $process_choices[1]: // CSV File
                    generate_csv($res);
                    exit();
                case $process_choices[2]: // Phone list
                    require_once('batchPhoneList.php');
                    exit();
            endswitch;
        }
    }
}

?>
<html>
<head>
    <?php Header::setupHeader(['datetime-picker']); ?>
    <title><?php echo xlt('BatchCom'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        :root {
            --bc-navy:         #1a1a2e;
            --bc-navy-mid:     #16213e;
            --bc-navy-light:   #0f3460;
            --bc-purple:       #7c3aed;
            --bc-purple-mid:   #6d28d9;
            --bc-purple-light: #8b5cf6;
            --bc-purple-pale:  #ede9fe;
            --bc-purple-ghost: #f5f3ff;
            --bc-accent:       #a78bfa;
            --bc-white:        #ffffff;
            --bc-gray-50:      #f9fafb;
            --bc-gray-100:     #f3f4f6;
            --bc-gray-200:     #e5e7eb;
            --bc-gray-300:     #d1d5db;
            --bc-gray-400:     #9ca3af;
            --bc-gray-500:     #6b7280;
            --bc-gray-700:     #374151;
            --bc-gray-900:     #111827;
            --bc-red-pale:     #fee2e2;
            --bc-red:          #dc2626;
            --bc-shadow-sm:    0 1px 3px rgba(0,0,0,.08);
            --bc-shadow:       0 4px 16px rgba(0,0,0,.10);
            --bc-radius:       10px;
            --bc-radius-sm:    6px;
        }
        *, *::before, *::after { box-sizing: border-box; }
        body.body_top {
            font-family: 'DM Sans', system-ui, sans-serif !important;
            background: var(--bc-gray-100) !important;
            color: var(--bc-gray-900) !important;
            font-size: 14px;
            line-height: 1.55;
            padding-bottom: 40px;
        }
        /* Hero banner */
        .bc-hero {
            background: linear-gradient(135deg, var(--bc-navy) 0%, var(--bc-navy-light) 60%, var(--bc-purple-mid) 100%);
            padding: 28px 32px 24px;
            margin-bottom: 28px;
            border-radius: 0 0 var(--bc-radius) var(--bc-radius);
            position: relative;
            overflow: hidden;
        }
        .bc-hero::after {
            content: '';
            position: absolute;
            inset: 0;
            background: repeating-linear-gradient(-45deg, transparent, transparent 28px, rgba(255,255,255,.025) 28px, rgba(255,255,255,.025) 56px);
            pointer-events: none;
        }
        .bc-hero-inner { position: relative; z-index: 1; display: flex; align-items: center; gap: 16px; }
        .bc-hero-icon {
            width: 48px; height: 48px;
            background: rgba(255,255,255,.12);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: var(--bc-radius);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .bc-hero h1 {
            font-size: 20px !important; font-weight: 700 !important;
            color: #fff !important; margin: 0 !important; letter-spacing: -.01em;
        }
        .bc-hero-sub { font-size: 13px; color: rgba(255,255,255,.55); margin-top: 3px; }
        /* Alert */
        .alert.alert-danger {
            background: var(--bc-red-pale) !important;
            border: 1.5px solid #fca5a5 !important;
            color: var(--bc-red) !important;
            border-radius: var(--bc-radius-sm) !important;
            font-size: 13px !important; font-weight: 500 !important;
            padding: 12px 16px !important;
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 20px;
        }
        /* Section label */
        .bc-section-label {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            color: var(--bc-gray-500);
            margin-bottom: 12px;
            display: flex; align-items: center; gap: 8px;
        }
        .bc-section-label::after { content: ''; flex: 1; height: 1px; background: var(--bc-gray-200); }
        /* Card grid */
        .bc-grid { display: grid; gap: 14px; }
        .bc-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .bc-grid-3 { grid-template-columns: repeat(3, 1fr); }
        @media (max-width: 900px) { .bc-grid-4 { grid-template-columns: repeat(2,1fr); } .bc-grid-3 { grid-template-columns: repeat(2,1fr); } }
        @media (max-width: 560px) { .bc-grid-4, .bc-grid-3 { grid-template-columns: 1fr; } }
        /* Field card */
        .bc-card {
            background: var(--bc-white);
            border: 1.5px solid var(--bc-gray-200);
            border-radius: var(--bc-radius);
            padding: 16px 18px;
            box-shadow: var(--bc-shadow-sm);
            transition: border-color .18s, box-shadow .18s;
        }
        .bc-card:focus-within {
            border-color: var(--bc-purple-light);
            box-shadow: 0 0 0 3px rgba(124,58,237,.10), var(--bc-shadow-sm);
        }
        .bc-card-header { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
        .bc-card-icon {
            width: 28px; height: 28px;
            background: var(--bc-purple-ghost);
            border-radius: var(--bc-radius-sm);
            display: flex; align-items: center; justify-content: center; flex-shrink: 0;
        }
        .bc-card-icon svg { color: var(--bc-purple); }
        .bc-card-title { font-size: 12px; font-weight: 700; color: var(--bc-navy); text-transform: uppercase; letter-spacing: .05em; }
        /* Labels & controls */
        label {
            display: block; font-size: 11px !important; font-weight: 700 !important;
            color: var(--bc-gray-500) !important; text-transform: uppercase;
            letter-spacing: .04em; margin-bottom: 5px !important;
        }
        .form-control {
            width: 100%; padding: 8px 12px !important;
            border: 1.5px solid var(--bc-gray-200) !important;
            border-radius: var(--bc-radius-sm) !important;
            font-family: 'DM Sans', system-ui, sans-serif !important;
            font-size: 13px !important; color: var(--bc-gray-900) !important;
            background: var(--bc-white) !important;
            outline: none !important; transition: border-color .15s, box-shadow .15s !important;
            -webkit-appearance: none; appearance: none;
        }
        .form-control:focus {
            border-color: var(--bc-purple-light) !important;
            box-shadow: 0 0 0 3px rgba(124,58,237,.12) !important;
        }
        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7280' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 11px center !important;
            padding-right: 30px !important;
        }
        textarea.form-control { resize: vertical; min-height: 130px; line-height: 1.6; }
        /* Range separator */
        .bc-range-sep {
            display: flex; align-items: center; gap: 8px; margin: 7px 0;
        }
        .bc-range-sep span { font-size: 11px; font-weight: 600; color: var(--bc-gray-400); white-space: nowrap; text-transform: uppercase; letter-spacing: .04em; }
        .bc-range-sep::before, .bc-range-sep::after { content: ''; flex: 1; height: 1px; background: var(--bc-gray-200); }
        /* Email card */
        .bc-email-card {
            background: var(--bc-white);
            border: 1.5px solid var(--bc-purple-pale);
            border-radius: var(--bc-radius);
            padding: 20px 22px;
            box-shadow: var(--bc-shadow-sm);
        }
        .bc-email-card:focus-within {
            border-color: var(--bc-purple-light);
            box-shadow: 0 0 0 3px rgba(124,58,237,.08), var(--bc-shadow-sm);
        }
        .bc-email-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .bc-email-inner .bc-full { grid-column: 1 / -1; }
        @media (max-width: 640px) { .bc-email-inner { grid-template-columns: 1fr; } }
        /* Submit row */
        .bc-submit-row { display: flex; align-items: center; gap: 14px; padding-top: 6px; }
        .bc-submit-hint { font-size: 12px; color: var(--bc-gray-400); }
        /* Buttons */
        .btn {
            font-family: 'DM Sans', system-ui, sans-serif !important;
            font-size: 13px !important; font-weight: 600 !important;
            border-radius: var(--bc-radius-sm) !important;
            padding: 9px 22px !important; border: none !important;
            cursor: pointer !important;
            display: inline-flex !important; align-items: center !important; gap: 7px !important;
            transition: all .15s !important; line-height: 1 !important;
        }
        .btn-save, .btn-primary {
            background: var(--bc-purple) !important; color: #fff !important;
            box-shadow: 0 2px 8px rgba(124,58,237,.25) !important;
        }
        .btn-save:hover, .btn-primary:hover {
            background: var(--bc-purple-mid) !important;
            box-shadow: 0 4px 14px rgba(124,58,237,.35) !important;
            transform: translateY(-1px);
        }
        .btn-save:active { transform: translateY(0); }
        /* Template picker */
        .bc-tpl-bar {
            background: var(--bc-white);
            border: 1.5px solid var(--bc-purple-pale);
            border-radius: var(--bc-radius);
            padding: 16px 20px;
            box-shadow: var(--bc-shadow-sm);
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        }
        .bc-tpl-bar label { margin-bottom: 0 !important; white-space: nowrap; }
        .bc-tpl-select {
            flex: 1; min-width: 220px;
        }
        .bc-tpl-hint {
            font-size: 11.5px; color: var(--bc-purple); font-weight: 500;
            display: none; align-items: center; gap: 5px;
        }
        .bc-tpl-hint.show { display: flex; }
        .bc-tpl-applied-tag {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 9px; border-radius: 99px;
            background: #d1fae5; color: #065f46;
            font-size: 11px; font-weight: 700;
        }
        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--bc-gray-300); border-radius: 99px; }
        ::placeholder { color: var(--bc-gray-400) !important; font-size: 12px; }
    </style>
</head>
<body class="body_top">
<div style="max-width:960px;margin:0 auto;padding:0 20px;">
<header>
    <?php require_once("batch_navigation.php");?>
    <div class="bc-hero">
        <div class="bc-hero-inner">
            <div class="bc-hero-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div>
                <h1><?php echo xlt('Batch Communication Tool')?></h1>
                <div class="bc-hero-sub"><?php echo xlt('Filter patients and send targeted communications'); ?></div>
            </div>
        </div>
    </div>
</header>
<main style="padding:0 4px 32px; margin-left:0px !important">
    <?php
    if (!empty($form_err)) {
        echo '<div class="alert alert-danger"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>' . xlt('The following errors occurred') . ': ' . text($form_err) . '</div>';
    }
    ?>
    <form name="select_form" method="post" action="">
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

        <!-- ── Row 1: Output options ── -->
        <div class="bc-section-label">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
            <?php echo xlt('Output Options'); ?>
        </div>
        <div class="bc-grid bc-grid-4" style="margin-bottom:22px;">
            <div class="bc-card">
                <div class="bc-card-header">
                    <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></div>
                    <span class="bc-card-title"><?php echo xlt('Process'); ?></span>
                </div>
                <select name="process_type" class="form-control">
                    <?php foreach ($process_choices as $choice) { echo "<option>" . text($choice) . "</option>"; } ?>
                </select>
            </div>
            <div class="bc-card">
                <div class="bc-card-header">
                    <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
                    <span class="bc-card-title"><?php echo xlt('Override HIPAA'); ?></span>
                </div>
                <select name="hipaa_choice" class="form-control">
                    <?php foreach ($hipaa_choices as $choice) { echo "<option>" . text($choice) . "</option>"; } ?>
                </select>
            </div>
            <div class="bc-card">
                <div class="bc-card-header">
                    <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg></div>
                    <span class="bc-card-title"><?php echo xlt('Sort By'); ?></span>
                </div>
                <select name="sort_by" class="form-control">
                    <?php foreach ($sort_by_choices as $choice => $sorting_code) { echo "<option value=\"$sorting_code\">" . text($choice) . "</option>"; } ?>
                </select>
            </div>
            <div class="bc-card">
                <div class="bc-card-header">
                    <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
                    <span class="bc-card-title"><?php echo xlt('Gender'); ?></span>
                </div>
                <select name="gender" class="form-control">
                    <?php foreach ($gender_choices as $choice) { echo "<option>" . text($choice) . "</option>"; } ?>
                </select>
            </div>
        </div>

        <!-- ── Row 2: Patient filters ── -->
        <div class="bc-section-label">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
            <?php echo xlt('Patient Filters'); ?>
        </div>
        <div class="bc-grid bc-grid-3" style="margin-bottom:22px;">
            <div class="bc-card">
                <div class="bc-card-header">
                    <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
                    <span class="bc-card-title"><?php echo xlt('Age Range'); ?></span>
                </div>
                <label for="age_from"><?php echo xlt('From'); ?></label>
                <input name="age_from" type="number" min="0" max="150" class="form-control" placeholder="<?php echo xla('any'); ?>">
                <div class="bc-range-sep"><span><?php echo xlt('to{{Range}}'); ?></span></div>
                <label for="age_upto"><?php echo xlt('Up to'); ?></label>
                <input name="age_upto" type="number" min="0" max="150" class="form-control" placeholder="<?php echo xla('any'); ?>">
            </div>
            <div class="bc-card">
                <div class="bc-card-header">
                    <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
                    <span class="bc-card-title"><?php echo xlt('Appointment Within'); ?></span>
                </div>
                <label for="app_s"><?php echo xlt('Start'); ?></label>
                <input type="text" class="datepicker form-control" name="app_s" placeholder="<?php echo xla('any date'); ?>" />
                <div class="bc-range-sep"><span><?php echo xlt('to{{Range}}'); ?></span></div>
                <label for="app_e"><?php echo xlt('End'); ?></label>
                <input type="text" class="datepicker form-control" name="app_e" placeholder="<?php echo xla('any date'); ?>" />
            </div>
            <div class="bc-card">
                <div class="bc-card-header">
                    <div class="bc-card-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></div>
                    <span class="bc-card-title"><?php echo xlt('Seen Within'); ?></span>
                </div>
                <label for="seen_since"><?php echo xlt('Since'); ?></label>
                <input type="text" class="datepicker form-control" name="seen_since" placeholder="<?php echo xla('any date'); ?>" />
                <div class="bc-range-sep"><span><?php echo xlt('to{{Range}}'); ?></span></div>
                <label for="seen_before"><?php echo xlt('Before'); ?></label>
                <input type="text" class="datepicker form-control" name="seen_before" placeholder="<?php echo xla('any date'); ?>" />
            </div>
        </div>

        <!-- ── Template picker ── -->
        <div class="email" style="margin-bottom:18px;">
            <div class="bc-section-label">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>
                <?php echo xlt('Message Template'); ?>
            </div>
            <div class="bc-tpl-bar">
                <label for="bc_template"><?php echo xlt('Select Template'); ?></label>
                <div class="bc-tpl-select">
                    <select id="bc_template" class="form-control" onchange="bcApplyTemplate(this.value)">
                        <option value=""><?php echo xla('— Choose a template or compose manually —'); ?></option>
                        <option value="recall_6"><?php echo xla('Recall – 6 Months'); ?></option>
                        <option value="recall_12"><?php echo xla('Recall – 12 Months'); ?></option>
                        <option value="new_patient"><?php echo xla('New Patient Welcome'); ?></option>
                        <option value="reactivation"><?php echo xla('Reactivation'); ?></option>
                        <option value="office_closure"><?php echo xla('Office Closure Notice'); ?></option>
                        <option value="insurance_reminder"><?php echo xla('Insurance Reminder'); ?></option>
                        <option value="wellness"><?php echo xla('Wellness Promotion'); ?></option>
                    </select>
                </div>
                <div id="bc-tpl-applied" class="bc-tpl-hint">
                    <span class="bc-tpl-applied-tag">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php echo xlt('Template applied'); ?>
                    </span>
                    <a href="#" style="font-size:11px;color:var(--bc-gray-400);text-decoration:none;" onclick="bcClearTemplate();return false;"><?php echo xlt('Clear'); ?></a>
                </div>
            </div>
        </div>

        <!-- ── Email section (conditionally shown) ── -->
        <div class="email" style="margin-bottom:22px;">
            <div class="bc-section-label">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <?php echo xlt('Email Composition'); ?>
            </div>
            <div class="bc-email-card">
                <div class="bc-email-inner">
                    <div>
                        <label for="email_sender"><?php echo xlt('Email Sender'); ?></label>
                        <input class="form-control" type="email" name="email_sender" id="email_sender"
                               value="<?php echo attr($default_sender_email); ?>"
                               placeholder="your@email.com" />
                        <?php if ($default_sender_email): ?>
                        <div style="font-size:10.5px;color:var(--bc-gray-400);margin-top:4px;">
                            <?php echo xlt('Default from Notifications settings — edit to override'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label for="email_subject"><?php echo xlt('Email Subject'); ?></label>
                        <input class="form-control" type="text" name="email_subject" id="email_subject" placeholder="<?php echo xla('From your clinic'); ?>" />
                    </div>
                    <div class="bc-full">
                        <label for="email_body"><?php echo xlt('Email Body'); ?> &mdash; <span style="text-transform:none;letter-spacing:0;font-weight:400;color:var(--bc-purple);font-size:11px;"><?php echo xlt('Use ***NAME*** for patient name, e.g. Dear ***NAME***{{Do Not translate the ***NAME*** elements of this constant.}}'); ?></span></label>
                        <textarea class="form-control" name="email_body" id="email_body" cols="40" rows="7"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Submit ── -->
        <div class="bc-submit-row">
            <input type="hidden" name="form_action" value="process" />
            <button type="submit" name="submit" class="btn btn-save">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                <?php echo xlt("Process"); ?>
            </button>
            <span class="bc-submit-hint"><?php echo xlt('Results will be processed according to the selected output type above.'); ?></span>
        </div>

    </form>
</main>
</div><!-- end wrapper -->
</body>

<script>
    // ── BatchCom Template Definitions ────────────────────────────────────────
    // Each entry defines subject, body (***NAME*** = patient first name),
    // and recommended filter pre-fills (empty string = leave blank).
    var BC_TEMPLATES = {
        recall_6: {
            subject: "We Miss You — Time for Your 6-Month Visit",
            body: "Dear ***NAME***,\n\nIt has been about 6 months since your last visit with us. We wanted to reach out and invite you to schedule your next appointment.\n\nRegular care helps us keep you feeling your best. Please give us a call or reply to this email to schedule at your convenience.\n\nWarm regards,\nThe Team",
            seen_since: "",
            seen_before: bcDateOffset(-270), // ~9 months ago as upper bound
            age_from: "",
            age_upto: ""
        },
        recall_12: {
            subject: "It's Been a While — Let's Schedule Your Annual Visit",
            body: "Dear ***NAME***,\n\nWe noticed it has been about a year since your last visit. We'd love to welcome you back and make sure you're doing well.\n\nPlease contact our office to schedule your appointment at a time that works for you.\n\nWarm regards,\nThe Team",
            seen_since: "",
            seen_before: bcDateOffset(-365),
            age_from: "",
            age_upto: ""
        },
        new_patient: {
            subject: "Welcome to Our Practice!",
            body: "Dear ***NAME***,\n\nWelcome! We are so glad to have you as a new patient. Our team is committed to providing you with the highest quality of care.\n\nIf you have any questions before your first visit, please don't hesitate to reach out.\n\nWarm regards,\nThe Team",
            seen_since: bcDateOffset(-30), // seen within the last 30 days
            seen_before: "",
            age_from: "",
            age_upto: ""
        },
        reactivation: {
            subject: "We'd Love to See You Again",
            body: "Dear ***NAME***,\n\nWe haven't seen you in a while and wanted to check in. Life gets busy — we completely understand.\n\nWhenever you're ready, our team is here for you. Give us a call or reply to this email to get back on the schedule.\n\nWarm regards,\nThe Team",
            seen_since: "",
            seen_before: bcDateOffset(-365),
            age_from: "",
            age_upto: ""
        },
        office_closure: {
            subject: "Important Office Notice",
            body: "Dear ***NAME***,\n\nWe wanted to inform you of an upcoming change to our office schedule. Please contact us if you have an appointment during this time so we can assist you in rescheduling.\n\nThank you for your understanding and continued trust in our care.\n\nWarm regards,\nThe Team",
            seen_since: "",
            seen_before: "",
            age_from: "",
            age_upto: ""
        },
        insurance_reminder: {
            subject: "Action Required: Insurance Information Update",
            body: "Dear ***NAME***,\n\nOur records indicate that we may need updated insurance information from you. To avoid any delays in processing your claims, please contact our office at your earliest convenience.\n\nThank you for helping us keep your account up to date.\n\nWarm regards,\nThe Team",
            seen_since: bcDateOffset(-180),
            seen_before: "",
            age_from: "",
            age_upto: ""
        },
        wellness: {
            subject: "Invest in Your Health — Wellness Visit Available",
            body: "Dear ***NAME***,\n\nSpring is a great time to focus on your wellness! We're offering wellness visits to help you set health goals and address any concerns proactively.\n\nReply to this email or call us to schedule your wellness visit today.\n\nWarm regards,\nThe Team",
            seen_since: "",
            seen_before: "",
            age_from: "",
            age_upto: ""
        }
    };

    // Returns a YYYY-MM-DD date string offset by `days` from today
    function bcDateOffset(days) {
        var d = new Date();
        d.setDate(d.getDate() + days);
        return d.toISOString().slice(0, 10);
    }

    function bcApplyTemplate(key) {
        var hint = document.getElementById('bc-tpl-applied');
        if (!key || !BC_TEMPLATES[key]) {
            if (hint) hint.classList.remove('show');
            return;
        }
        var t = BC_TEMPLATES[key];

        // Populate email fields
        var subj = document.getElementById('email_subject');
        var body = document.getElementById('email_body');
        if (subj) subj.value = t.subject;
        if (body) body.value = t.body;

        // Populate filter fields
        var fields = {
            seen_since: t.seen_since,
            seen_before: t.seen_before,
            age_from: t.age_from,
            age_upto: t.age_upto
        };
        for (var name in fields) {
            var el = document.querySelector('[name="' + name + '"]');
            if (el && fields[name] !== undefined) { el.value = fields[name]; }
        }

        // Auto-select "Send Emails" process type
        var proc = document.querySelector('select[name="process_type"]');
        if (proc) proc.value = proc.options[0].value; // first option = Send Emails
        // Trigger the email section show/hide
        proc.dispatchEvent(new Event('change'));

        // Show applied badge
        if (hint) hint.classList.add('show');
    }

    function bcClearTemplate() {
        document.getElementById('bc_template').value = '';
        var hint = document.getElementById('bc-tpl-applied');
        if (hint) hint.classList.remove('show');

        // Clear the fields the template populated
        ['email_subject', 'email_body'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
        ['seen_since', 'seen_before', 'age_from', 'age_upto'].forEach(function(n) {
            var el = document.querySelector('[name="' + n + '"]');
            if (el) el.value = '';
        });
    }

    (function() {
        var email = document.querySelector('.email');
        var process = document.querySelector('select[name="process_type"]');
        function hideEmail() {
            if (process.value !== <?php echo js_escape($process_choices[0]); ?>) { email.style.display = 'none'; } else { email.style.display = ''; }
        }
        process.addEventListener('change', hideEmail);
        hideEmail();
        $('.datepicker').datetimepicker({
            <?php $datetimepicker_timepicker = false; ?>
            <?php $datetimepicker_showseconds = false; ?>
            <?php $datetimepicker_formatInput = false; ?>
            <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
            <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
        });
    })();
</script>
</html>
