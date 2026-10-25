<?php

/*
 * Add or edit an event in the calendar.
 *
 * The event editor looks something like this:
 *
 * //------------------------------------------------------------//
 * // Category __________________V   O All day event             //
 * // Date     _____________ [?]     O Time     ___:___ __V      //
 * // Title    ___________________     duration ____ minutes     //
 * // Patient  _(Click_to_select)_                               //
 * // Provider __________________V   X Repeats  ______V ______V  //
 * // Status   __________________V     until    __________ [?]   //
 * // Comments ________________________________________________  //
 * //                                                            //
 * //       [Save]  [Find Available]  [Delete]  [Cancel]         //
 * //------------------------------------------------------------//
 *
 *
 * Copyright (C) 2005-2013 Rod Roark <rod@sunsetsystems.com>
 * Copyright (C) 2017 Brady Miller <brady.g.miller@gmail.com>
 * Copyright (C) 2019 Jerry Padgett <sjpadgett@gmail.com>
 * Copyright (C) 2021 Sherwin Gaddis <sherwingaddis@gmail.com>
 *
 * LICENSE: This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://opensource.org/licenses/gpl-license.php>;.
 *
 * @package OpenEMR
 * @author Rod Roark <rod@sunsetsystems.com>
 * @author Brady Miller <brady.g.miller@gmail.com>
 * @author Jerry Padgett <sjpadgett@gmail.com>
 * @link http://www.open-emr.org
 */

require_once(__DIR__ . '/../../globals.php');
require_once($GLOBALS['srcdir'] . '/patient.inc.php');
require_once($GLOBALS['srcdir'] . '/forms.inc.php');
require_once($GLOBALS['srcdir'] . '/calendar.inc.php');
require_once($GLOBALS['srcdir'] . '/options.inc.php');
require_once($GLOBALS['srcdir'] . '/encounter_events.inc.php');
require_once($GLOBALS['srcdir'] . '/patient_tracker.inc.php');
require_once($GLOBALS['incdir'] . "/main/holidays/Holidays_Controller.php");
require_once($GLOBALS['srcdir'] . '/group.inc.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Events\Appointments\AppointmentSetEvent;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;

 //Check access control
if (!AclMain::aclCheckCore('patients', 'appt', '', array('write','wsome'))) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Edit/Add Event")]);
    exit;
}

/* Things that might be passed by our opener. */
$eid           = $_GET['eid'] ?? null; // only for existing events
$date          = $_GET['date'] ?? null;        // this and below only for new events
$userid        = $_GET['userid'] ?? null;

// Default category: prefer "Office Visit" from the DB; fall back to catid 5.
$_office_visit_row = sqlQuery(
    "SELECT pc_catid, pc_catname FROM openemr_postcalendar_categories " .
    "WHERE pc_catname = 'Office Visit' AND pc_active = 1 LIMIT 1"
);
$_office_visit_catid = $_office_visit_row['pc_catid'] ?? '5';
$_office_visit_name  = $_office_visit_row['pc_catname'] ?? 'Office Visit';
$default_catid = !empty($_GET['catid']) ? $_GET['catid'] : $_office_visit_catid;

// form logic fails if not set to boolean
if (isset($_GET['group'])) {
    $_GET['group'] = $_GET['group'] == "true" ? true : false;
}
if (isset($_GET['prov'])) {
    $_GET['prov'] = $_GET['prov'] == "true" ? true : false;
}
$_POST['form_date'] = DateToYYYYMMDD($_POST['form_date'] ?? null);
$_POST['form_enddate'] = DateToYYYYMMDD($_POST['form_enddate'] ?? null);

if ($date) {
    $date = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6);
} else {
    $date = date("Y-m-d");
}

$starttimem = '00';
if (isset($_GET['starttimem'])) {
    $starttimem = substr('00' . $_GET['starttimem'], -2);
}

if (isset($_GET['starttimeh'])) {
    $starttimeh = $_GET['starttimeh'];
    if ($GLOBALS['time_display_format']  == 1 && isset($_GET['startampm'])) {
        if ($_GET['startampm'] == '2' && $starttimeh < 12) {
            $starttimeh += 12;
        }
    }
} else {
    $starttimeh = date("G");
}
/* some defaults to start */
$startampm = '';
$info_msg = "";
$g_edit = AclMain::aclCheckCore("groups", "gcalendar", false, 'write');
$g_view = AclMain::aclCheckCore("groups", "gcalendar", false, 'view');


/**
 * @var EventDispatcherInterface $eventDispatcher
 */
$eventDispatcher = $GLOBALS['kernel']->getEventDispatcher();

?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<?php Header::setupHeader(['common', 'datetime-picker', 'opener']); ?>
<title><?php echo $eid ? xlt('Edit') : xlt('Add New{{Event}}') ?> <?php echo xlt('Event');?></title>

<!-- validation library -->
<!--//Not lbf forms use the new validation, please make sure you have the corresponding values in the list Page validation-->
<?php
$use_validate_js = 1;
require_once($GLOBALS['srcdir'] . "/validation/validation_script.js.php");
//Gets validation rules from Page Validation list.
//Note that for technical reasons, we are bypassing the standard validateUsingPageRules() call.
$have_group_global_enabled = true;
if ((!$g_edit && !$g_view) || (!$GLOBALS['enable_group_therapy'])) {
    $_GET['group'] = false;
    $have_group_global_enabled = false;
}

if ($_GET['group'] == true) {
    //groups tab
    $collectthis = collectValidationPageRules("/interface/main/calendar/add_edit_event.php?group=true");
} elseif (!empty($_GET['prov'])) {
    //providers tab
    $collectthis = collectValidationPageRules("/interface/main/calendar/add_edit_event.php?prov=true");
} else { //patient tab
    $collectthis = collectValidationPageRules("/interface/main/calendar/add_edit_event.php");
}

if (empty($collectthis)) {
    $collectthis = "undefined";
} else {
    $collectthis = json_sanitize($collectthis[array_keys($collectthis)[0]]["rules"]);
}

$group_disabled = ($_GET['group'] && !$g_edit && $have_group_global_enabled ) ? ' disabled=true; ' : '';
if ($group_disabled) {
    echo '<script>$(function () {$("input").prop("disabled", true);$("select").prop("disabled", true);}) </script>';
}

function InsertEventFull()
{
    global $new_multiple_value,$provider,$event_date,$duration,$recurrspec,$starttime,$endtime,$locationspec;
    // =======================================
    // multi providers case
    // =======================================
    if (is_array($_POST['form_provider'])) {
        // obtain the next available unique key to group multiple providers around some event
        $q = sqlStatement("SELECT MAX(pc_multiple) as max FROM openemr_postcalendar_events");
        $max = sqlFetchArray($q);
        $new_multiple_value = $max['max'] + 1;

        $pc_eid = null;
        foreach ($_POST['form_provider'] as $provider) {
            $args = $_POST;
            // specify some special variables needed for the INSERT
            $args['new_multiple_value'] = $new_multiple_value;
            $args['form_provider'] = $provider;
            $args['event_date'] = $event_date;
            $args['duration'] = $duration * 60;
            $args['recurrspec'] = $recurrspec;
            $args['starttime'] = $starttime;
            $args['endtime'] = $endtime;
            $args['locationspec'] = $locationspec;
            $pc_eid_temp = InsertEvent($args);
            if ($pc_eid == null) {
                $pc_eid = $pc_eid_temp;
            }
        }

        return $pc_eid;


        // ====================================
        // single provider
        // ====================================
    } else {
        $args = $_POST;
        // specify some special variables needed for the INSERT
        $args['new_multiple_value'] = "";
        $args['event_date'] = $event_date;
        $args['duration'] = $duration * 60;
        $args['recurrspec'] = $recurrspec;
        $args['starttime'] = $starttime;
        $args['endtime'] = $endtime;
        $args['locationspec'] = $locationspec;
        $pc_eid = InsertEvent($args);
        return $pc_eid;
    }
}

function DOBandEncounter($pc_eid)
{
     global $event_date,$info_msg;
     // Save new DOB if it's there.
     $patient_dob = trim($_POST['form_dob'] ?? '');
     $tmph = $_POST['form_hour'] + 0;
     $tmpm = $_POST['form_minute'] + 0;
    if (!empty($_POST['form_ampm']) && ($_POST['form_ampm'] == '2' && $tmph < 12)) {
        $tmph += 12;
    }

     $appttime = "$tmph:$tmpm:00";

    if ($patient_dob && $_POST['form_pid']) {
        sqlStatement("UPDATE patient_data SET DOB = ? WHERE " .
                                "pid = ?", array($patient_dob,$_POST['form_pid']));
    }

    // Manage tracker status.
    // And auto-create a new encounter if appropriate.
    if (!empty($_POST['form_pid'])) {
        $is_tracker = is_tracker_encounter_exist($event_date, $appttime, $_POST['form_pid'], $_GET['eid']);
        $is_checkin = is_checkin($_POST['form_apptstatus']);
        if (
            (int)$GLOBALS['auto_create_new_encounters']
            && $event_date == date('Y-m-d')
            && $is_checkin == '1'
            && !$is_tracker
        ) {
            $encounter = todaysEncounterCheck($_POST['form_pid'], $event_date, $_POST['form_comments'], $_POST['facility'], $_POST['billing_facility'], $_POST['form_provider'], $_POST['form_category'], false);
            if ($encounter) {
                $info_msg .= xl("New encounter created with id");
                $info_msg .= " $encounter";
            }

            # Capture the appt status and room number for patient tracker. This will map the encounter to it also.
            if (isset($GLOBALS['temporary-eid-for-manage-tracker']) || !empty($_GET['eid'])) {
                // Note that the temporary-eid-for-manage-tracker is used to capture the eid for new appointments and when separate a recurring
                // appointment. It is set in the InsertEvent() function. Note that in the case of spearating a recurrent appointment, the get eid
                // parameter is actually erroneous(is eid of the recurrent appt and not the new separated appt), so need to use the
                // temporary-eid-for-manage-tracker global instead.
                $temp_eid = (isset($GLOBALS['temporary-eid-for-manage-tracker'])) ? $GLOBALS['temporary-eid-for-manage-tracker'] : $_GET['eid'];
                manage_tracker_status($event_date, $appttime, $temp_eid, $_POST['form_pid'], $_SESSION["authUser"], $_POST['form_apptstatus'], $_POST['form_room'], $encounter);
            }
        } else {
            # Capture the appt status and room number for patient tracker.
            if (!empty($_GET['eid'])) {
                manage_tracker_status($event_date, $appttime, $_GET['eid'], $_POST['form_pid'], $_SESSION["authUser"], $_POST['form_apptstatus'], $_POST['form_room'], $is_tracker);
            }
        }
    }

    // auto create encounter for therapy group
    if (!empty($_POST['form_gid'])) {
        // status Took Place is the check in of therapy group
        if ($GLOBALS['auto_create_new_encounters'] && $event_date == date('Y-m-d') && $_POST['form_apptstatus'] == '=') {
            $encounter = todaysTherapyGroupEncounterCheck($_POST['form_gid'], $event_date, $_POST['form_comments'], $_POST['facility'], $_POST['billing_facility'], $_POST['form_provider'], $_POST['form_category'], false, $pc_eid);
            if ($encounter) {
                $info_msg .= xl("New group encounter created with id");
                $info_msg .= " $encounter";
            }
        }
    }
}


 /*This function is used for setting the date of the first event when using the "day_every_week" repetition mechanism.
 When the 'start date' is not one of the days chosen for the repetition, the start date needs to be changed to the first
 occurrence of one of these set days. */
function setEventDate($start_date, $recurrence)
{
    $timestamp = strtotime($start_date);
    $day = date('w', $timestamp);
    //If the 'start date' is one of the set days
    if (in_array(($day + 1), explode(',', $recurrence))) {
        return $start_date;
    }

    //else: (we need to change start date to first occurrence of one of the set days)

    $new_date = getTheNextAppointment($start_date, $recurrence);

    return $new_date;
}

// EVENTS TO FACILITIES (lemonsoftware)
//(CHEMED) get facility name
// edit event case - if there is no association made, then insert one with the first facility
if ($eid) {
    $selfacil = '';
    $facility = sqlQuery("SELECT pc_facility, pc_multiple, pc_aid, facility.name
                            FROM openemr_postcalendar_events
                              LEFT JOIN facility ON (openemr_postcalendar_events.pc_facility = facility.id)
                              WHERE pc_eid = ?", array($eid));
        // if ( !$facility['pc_facility'] ) {
    if (is_array($facility) && !$facility['pc_facility']) {
        $qmin = sqlQuery("SELECT facility_id as minId, facility FROM users WHERE id = ?", array($facility['pc_aid']));
        $min  = $qmin['minId'];
        $min_name = $qmin['facility'];

        // multiple providers case
        if ($GLOBALS['select_multi_providers']) {
            $mul  = $facility['pc_multiple'];
            sqlStatement("UPDATE openemr_postcalendar_events SET pc_facility = ? WHERE pc_multiple = ?", array($min,$mul));
        }

        // EOS multiple

        sqlStatement("UPDATE openemr_postcalendar_events SET pc_facility = ? WHERE pc_eid = ?", array($min,$eid));
        $e2f = $min;
        $e2f_name = $min_name;
    } else {
          // not edit event
        if (!$facility['pc_facility'] && $_SESSION['pc_facility']) {
            $e2f = $_SESSION['pc_facility'];
        } elseif (!$facility['pc_facility'] && $_COOKIE['pc_facility'] && $GLOBALS['set_facility_cookie']) {
                $e2f = $_COOKIE['pc_facility'];
        } else {
            $e2f = $facility['pc_facility'];
            $e2f_name = $facility['name'];
        }
    }
}

// EOS E2F
//=============================================================================================================================
if (!empty($_POST['form_action']) && ($_POST['form_action'] == "duplicate" || $_POST['form_action'] == "save")) {
    // Compute start and end time strings to be saved.
    if ($_POST['form_allday']) {
        $tmph = 0;
        $tmpm = 0;
        $duration = 24 * 60;
    } else {
        $tmph = $_POST['form_hour'] + 0;
        $tmpm = $_POST['form_minute'] + 0;
        if (!empty($_POST['form_ampm']) && ($_POST['form_ampm'] == '2' && $tmph < 12)) {
            $tmph += 12;
        }

        $duration = abs($_POST['form_duration']); // fixes #395
    }

        $starttime = "$tmph:$tmpm:00";
        //
        $tmpm += $duration;
    while ($tmpm >= 60) {
        $tmpm -= 60;
        ++$tmph;
    }

        $endtime = "$tmph:$tmpm:00";

        // Set up working variables related to repeated events.
        $my_recurrtype = 0;
        $my_repeat_freq = 0 + ($_POST['form_repeat_freq'] ?? null);
        $my_repeat_type = 0 + ($_POST['form_repeat_type'] ?? null);
        $my_repeat_on_num  = 1;
        $my_repeat_on_day  = 0;
        $my_repeat_on_freq = 0;

         // the starting date of the event, pay attention with this value
         // when editing recurring events -- JRM Oct-08
         $event_date = $_POST['form_date'];

         //If used new recurrence mechanism of set days every week
    if (!empty($_POST['days_every_week'])) {
        $my_recurrtype = 3;
       //loop through checkboxes and insert encounter days into array
        $days_every_week_arr = array();
        for ($i = 1; $i <= 7; $i++) {
            if (!empty($_POST['day_' . $i])) {
                array_push($days_every_week_arr, $i);
            }
        }

        $my_repeat_freq = implode(",", $days_every_week_arr);
        $my_repeat_type = 6;
        $event_date = setEventDate($_POST['form_date'], $my_repeat_freq);
    } elseif (!empty($_POST['form_repeat'])) {
        $my_recurrtype = 1;
        if ($my_repeat_type > 4) {
            $my_recurrtype = 2;
            $time = strtotime($event_date);
            $my_repeat_on_day = 0 + date('w', $time);
            $my_repeat_on_freq = $my_repeat_freq;
            if ($my_repeat_type == 5) {
                $my_repeat_on_num = intval((date('j', $time) - 1) / 7) + 1;
            } else {
                // Last occurence of this weekday on the month
                $my_repeat_on_num = 5;
            }

            // Maybe not needed, but for consistency with postcalendar:
            $my_repeat_freq = 0;
            $my_repeat_type = 0;
        }
    }


        // Useless garbage that we must save.
        $locationspecs = array(
            "event_location" => "",
            "event_street1" => "",
            "event_street2" => "",
            "event_city" => "",
            "event_state" => "",
            "event_postal" => ""
                    );
        $locationspec = serialize($locationspecs);

        // capture the recurring specifications
        $recurrspec = array(
            "event_repeat_freq" => "$my_repeat_freq",
            "event_repeat_freq_type" => "$my_repeat_type",
            "event_repeat_on_num" => "$my_repeat_on_num",
            "event_repeat_on_day" => "$my_repeat_on_day",
            "event_repeat_on_freq" => "$my_repeat_on_freq",
            "exdate" => $_POST['form_repeat_exdate']
        );

        //
        if ($my_recurrtype == 2) { // Added by epsdky 2016 (details in commit)
            if ($_POST['old_repeats'] == 2) {
                if ($_POST['rt2_flag2']) {
                    $recurrspec['rt2_pf_flag'] = "1";
                }
            } else {
                $recurrspec['rt2_pf_flag'] = "1";
            }
        } // End of addition by epsdky
        //
        // no recurr specs, this is used for adding a new non-recurring event
        $noRecurrspec = array(
            "event_repeat_freq" => "",
            "event_repeat_freq_type" => "",
            "event_repeat_on_num" => "1",
            "event_repeat_on_day" => "0",
            "event_repeat_on_freq" => "0",
            "exdate" => ""
        );
}

if (!empty($_POST['form_action']) && ($_POST['form_action'] == "duplicate")) {
    $eid = InsertEventFull();
    DOBandEncounter($eid);
}

// If we are saving, then save and close the window.
//
if (!empty($_POST['form_action']) && ($_POST['form_action'] == "save")) {
    /* =======================================================
     *                    UPDATE EVENTS
     * =====================================================*/
    if ($eid) {
        // what is multiple key around this $eid?
        $row = sqlQuery("SELECT pc_multiple FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));

        // ====================================
        // multiple providers
        // ====================================
        if ($GLOBALS['select_multi_providers'] && $row['pc_multiple']) {
            // obtain current list of providers regarding the multiple key
            $up = sqlStatement("SELECT pc_aid FROM openemr_postcalendar_events WHERE pc_multiple=?", array($row['pc_multiple']));
            while ($current = sqlFetchArray($up)) {
                $providers_current[] = $current['pc_aid'];
            }

            // get the new list of providers from the submitted form
            $providers_new = $_POST['form_provider'];

            // ===== Only current event of repeating series =====
            if ($_POST['recurr_affect'] == 'current') {
                // update all existing event records to exlude the current date
                foreach ($providers_current as $provider) {
                    // update the provider's original event
                    // get the original event's repeat specs
                    $origEvent = sqlQuery("SELECT pc_recurrspec FROM openemr_postcalendar_events " .
                    " WHERE pc_aid = ? AND pc_multiple=?", array($provider,$row['pc_multiple']));
                    $oldRecurrspec = unserialize($origEvent['pc_recurrspec'], ['allowed_classes' => false]);
                    $selected_date = date("Ymd", strtotime($_POST['selected_date']));
                    if ($oldRecurrspec['exdate'] != "") {
                        $oldRecurrspec['exdate'] .= "," . $selected_date;
                    } else {
                        $oldRecurrspec['exdate'] .= $selected_date;
                    }

                    // mod original event recur specs to exclude this date
                        sqlStatement("UPDATE openemr_postcalendar_events SET " .
                        " pc_recurrspec = ? " .
                        " WHERE pc_aid = ? AND pc_multiple=?", array(serialize($oldRecurrspec),$provider,$row['pc_multiple']));
                }

                // obtain the next available unique key to group multiple providers around some event
                $q = sqlStatement("SELECT MAX(pc_multiple) as max FROM openemr_postcalendar_events");
                $max = sqlFetchArray($q);
                $new_multiple_value = $max['max'] + 1;

                // insert a new event record for each provider selected on the form
                foreach ($providers_new as $provider) {
                    // insert a new event on this date with POST form data
                    $args = $_POST;
                    // specify some special variables needed for the INSERT
                    $args['new_multiple_value'] = $new_multiple_value;
                    $args['form_provider'] = $provider;
                    $args['event_date'] = $event_date;
                    $args['duration'] = $duration * 60;
                    // this event is forced to NOT REPEAT
                    $args['form_repeat'] = "0";
                    $args['days_every_week'] = "0";
                    $args['recurrspec'] = $noRecurrspec;
                    $args['form_enddate'] = "0000-00-00";
                    $args['starttime'] = $starttime;
                    $args['endtime'] = $endtime;
                    $args['locationspec'] = $locationspec;
                    InsertEvent($args);
                }
            } elseif ($_POST['recurr_affect'] == 'future') { // ===== Future Recurring events of a repeating series =====
                // update all existing event records to
                // stop recurring on this date-1
                $selected_date = date("Y-m-d", (strtotime($_POST['selected_date']) - 24 * 60 * 60));
                foreach ($providers_current as $provider) {
                    // In case of a change in the middle of the event
                    if (strcmp($_POST['event_start_date'], $_POST['selected_date']) != 0) {
                        // mod original event recur specs to end on this date
                        sqlStatement("UPDATE openemr_postcalendar_events SET " .
                        " pc_enddate = ? " .
                        " WHERE pc_aid = ? AND pc_multiple=?", array($selected_date, $provider, $row['pc_multiple']));
                    } else { // In case of a change in the event head
                        sqlStatement("DELETE FROM openemr_postcalendar_events " .
                        " WHERE pc_aid = ? AND pc_multiple=?", array($provider, $row['pc_multiple']));
                    }
                }

                // obtain the next available unique key to group multiple providers around some event
                $q = sqlStatement("SELECT MAX(pc_multiple) as max FROM openemr_postcalendar_events");
                $max = sqlFetchArray($q);
                $new_multiple_value = $max['max'] + 1;

                // insert a new event record for each provider selected on the form
                foreach ($providers_new as $provider) {
                    // insert a new event on this date with POST form data
                    $args = $_POST;
                    // specify some special variables needed for the INSERT
                    $args['new_multiple_value'] = $new_multiple_value;
                    $args['form_provider'] = $provider;
                    $args['event_date'] = $event_date;
                    $args['duration'] = $duration * 60;
                    $args['recurrspec'] = $recurrspec;
                    $args['starttime'] = $starttime;
                    $args['endtime'] = $endtime;
                    $args['locationspec'] = $locationspec;
                    InsertEvent($args);
                }
            } else {
                /* =================================================================== */
                // ===== a Single event or All events in a repeating series ==========
                /* =================================================================== */

                // this difference means that some providers from current was UNCHECKED
                // so we must delete this event for them
                $r1 = array_diff($providers_current, $providers_new);
                $old_eid = null;
                if (count($r1)) {
                    foreach ($r1 as $to_be_removed) {
                        if (!empty($_POST['form_gid'])) {
                            $old_eid =  sqlQuery("SELECT pc_eid FROM openemr_postcalendar_events WHERE pc_aid=? AND pc_multiple=?", array($to_be_removed,$row['pc_multiple']));
                        }
                        sqlQuery("DELETE FROM openemr_postcalendar_events WHERE pc_aid=? AND pc_multiple=?", array($to_be_removed,$row['pc_multiple']));
                    }
                }

                // perform a check to see if user changed event date
                // this is important when editing an existing recurring event
                // oct-08 JRM
                if ($_POST['form_date'] == $_POST['selected_date']) {
                    // user has NOT changed the start date of the event (and not recurrtype 3)
                    if ($my_recurrtype != 3) {
                        $event_date = $_POST['event_start_date'];
                    }
                }

                // this difference means that some providers were added
                // so we must insert this event for them
                $r2 = array_diff($providers_new, $providers_current);
                if (count($r2)) {
                    foreach ($r2 as $to_be_inserted) {
                        $args = $_POST;
                        // specify some special variables needed for the INSERT
                        $args['new_multiple_value'] = $row['pc_multiple'];
                        $args['form_provider'] = $to_be_inserted;
                        $args['event_date'] = $event_date;
                        $args['duration'] = $duration * 60;
                        $args['recurrspec'] = $recurrspec;
                        $args['starttime'] = $starttime;
                        $args['endtime'] = $endtime;
                        $args['locationspec'] = $locationspec;
                        $new_eid = InsertEvent($args);
                    }
                }

                if (!is_null($old_eid) && !empty($old_eid)) {
                   // update group encounter is connected to event.
                    sqlQuery("UPDATE form_groups_encounter SET appt_id = ? WHERE appt_id = ?", array($new_eid, $old_eid['pc_eid']));
                }

                // after the two diffs above, we must update for remaining providers
                // those who are intersected in $providers_current and $providers_new
                foreach ($_POST['form_provider'] as $provider) {
                    sqlStatement("UPDATE openemr_postcalendar_events SET " .
                    "pc_catid = '" . add_escape_custom($_POST['form_category']) . "', " .
                    "pc_pid = '" . add_escape_custom($_POST['form_pid']) . "', " .
                    "pc_title = '" . add_escape_custom($_POST['form_title']) . "', " .
                    "pc_time = NOW(), " .
                    "pc_hometext = '" . add_escape_custom($_POST['form_comments']) . "', " .
                    "pc_room = '" . add_escape_custom($_POST['form_room']) . "', " .
                    "pc_informant = '" . add_escape_custom($_SESSION['authUserID']) . "', " .
                    "pc_eventDate = '" . add_escape_custom($event_date) . "', " .
                    "pc_endDate = '" . add_escape_custom($_POST['form_enddate']) . "', " .
                    "pc_duration = '" . add_escape_custom(($duration * 60)) . "', " .
                    "pc_recurrtype = '" . add_escape_custom($my_recurrtype) . "', " .
                    "pc_recurrspec = '" . add_escape_custom(serialize($recurrspec)) . "', " .
                    "pc_startTime = '" . add_escape_custom($starttime) . "', " .
                    "pc_endTime = '" . add_escape_custom($endtime) . "', " .
                    "pc_alldayevent = '" . add_escape_custom($_POST['form_allday']) . "', " .
                    "pc_apptstatus = '" . add_escape_custom($_POST['form_apptstatus']) . "', "  .
                    "pc_prefcatid = '" . add_escape_custom($_POST['form_prefcat']) . "' ,"  .
                    "pc_facility = '" . add_escape_custom((int)$_POST['facility']) . "' ,"  . // FF stuff
                    "pc_billing_location = '" . add_escape_custom((int)$_POST['billing_facility']) . "' "  .
                    "WHERE pc_aid = '" . add_escape_custom($provider) . "' AND pc_multiple = '" . add_escape_custom($row['pc_multiple'])  . "'");
                } // foreach
            }

            // ====================================
            // single provider
            // ====================================
        } elseif (!$row['pc_multiple']) {
            if ($GLOBALS['select_multi_providers']) {
                $prov = $_POST['form_provider'][0];
                $_POST['form_provider'] = $prov;
            } else {
                $prov =  $_POST['form_provider'];
            }

            if ($_POST['recurr_affect'] == 'current') {
                // get the original event's repeat specs
                $origEvent = sqlQuery("SELECT pc_recurrspec FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));
                $oldRecurrspec = unserialize($origEvent['pc_recurrspec'], ['allowed_classes' => false]);
                $selected_date = date("Ymd", strtotime($_POST['selected_date']));
                if ($oldRecurrspec['exdate'] != "") {
                    $oldRecurrspec['exdate'] .= "," . $selected_date;
                } else {
                    $oldRecurrspec['exdate'] .= $selected_date;
                }

                // mod original event recur specs to exclude this date
                    sqlStatement("UPDATE openemr_postcalendar_events SET " .
                    " pc_recurrspec = ? " .
                    " WHERE pc_eid = ?", array(serialize($oldRecurrspec),$eid));

                // insert a new event on this date with POST form data
                $args = $_POST;
                // specify some special variables needed for the INSERT
                $args['event_date'] = $event_date;
                $args['duration'] = $duration * 60;
                // this event is forced to NOT REPEAT
                $args['form_repeat'] = "0";
                $args['days_every_week'] = "0";
                $args['recurrspec'] = $noRecurrspec;
                $args['form_enddate'] = "0000-00-00";
                $args['starttime'] = $starttime;
                $args['endtime'] = $endtime;
                $args['locationspec'] = $locationspec;
                InsertEvent($args);
            } elseif ($_POST['recurr_affect'] == 'future') {
                // mod original event to stop recurring on this date-1
                $selected_date = date("Ymd", (strtotime($_POST['selected_date']) - 24 * 60 * 60));
                sqlStatement("UPDATE openemr_postcalendar_events SET " .
                " pc_enddate = ? " .
                " WHERE pc_eid = ?", array($selected_date,$eid));

                // insert a new event starting on this date with POST form data
                $args = $_POST;
                // specify some special variables needed for the INSERT
                $args['event_date'] = $event_date;
                $args['duration'] = $duration * 60;
                $args['recurrspec'] = $recurrspec;
                $args['starttime'] = $starttime;
                $args['endtime'] = $endtime;
                $args['locationspec'] = $locationspec;
                InsertEvent($args);
            } else {
        // perform a check to see if user changed event date
        // this is important when editing an existing recurring event
        // oct-08 JRM
                if ($_POST['form_date'] == $_POST['selected_date']) {
                    // user has NOT changed the start date of the event (and not recurrtype 3)
                    if ($my_recurrtype != 3) {
                        $event_date = $_POST['event_start_date'];
                    }
                }

                // mod the SINGLE event or ALL EVENTS in a repeating series
                // simple provider case
                sqlStatement("UPDATE openemr_postcalendar_events SET " .
                "pc_catid = '" . add_escape_custom($_POST['form_category']) . "', " .
                "pc_aid = '" . add_escape_custom($prov) . "', " .
                "pc_pid = '" . add_escape_custom($_POST['form_pid']) . "', " .
                "pc_title = '" . add_escape_custom($_POST['form_title']) . "', " .
                "pc_time = NOW(), " .
                "pc_hometext = '" . add_escape_custom($_POST['form_comments']) . "', " .
                "pc_room = '" . add_escape_custom($_POST['form_room']) . "', " .
                "pc_informant = '" . add_escape_custom($_SESSION['authUserID']) . "', " .
                "pc_eventDate = '" . add_escape_custom($event_date) . "', " .
                "pc_endDate = '" . add_escape_custom($_POST['form_enddate']) . "', " .
                "pc_duration = '" . add_escape_custom(($duration * 60)) . "', " .
                "pc_recurrtype = '" . add_escape_custom($my_recurrtype) . "', " .
                "pc_recurrspec = '" . add_escape_custom(serialize($recurrspec)) . "', " .
                "pc_startTime = '" . add_escape_custom($starttime) . "', " .
                "pc_endTime = '" . add_escape_custom($endtime) . "', " .
                "pc_alldayevent = '" . add_escape_custom($_POST['form_allday']) . "', " .
                "pc_apptstatus = '" . add_escape_custom($_POST['form_apptstatus']) . "', "  .
                "pc_prefcatid = '" . add_escape_custom($_POST['form_prefcat']) . "' ,"  .
                "pc_facility = '" . add_escape_custom((int)$_POST['facility']) . "' ,"  . // FF stuff
                "pc_billing_location = '" . add_escape_custom((int)$_POST['billing_facility']) . "' "  .
                "WHERE pc_eid = '" . add_escape_custom($eid) . "'");
            }
        }

        // =======================================
        // end Update Multi providers case
        // =======================================

        // EVENTS TO FACILITIES
        $e2f = (int)$eid;
        //Tell subscribers that a new multi appointment has been set
        $patientAppointmentSetEvent = new AppointmentSetEvent($_POST);
        $patientAppointmentSetEvent->eid = $e2f;  //setting the appointment id to an object
        $eventDispatcher->dispatch($patientAppointmentSetEvent, AppointmentSetEvent::EVENT_HANDLE, 10);
    } else {
        /* =======================================================
     *                    INSERT NEW EVENT(S)
     * ======================================================*/

        $eid = InsertEventFull();
        //Tell subscribers that a new single appointment has been set
        $patientAppointmentSetEvent = new AppointmentSetEvent($_POST);
        $patientAppointmentSetEvent->eid = $eid;  //setting the appointment id to an object
        $eventDispatcher->dispatch($patientAppointmentSetEvent, AppointmentSetEvent::EVENT_HANDLE, 10);
    }

        // done with EVENT insert/update statements

        DOBandEncounter(isset($eid) ? $eid : null);
} elseif (!empty($_POST['form_action']) && ($_POST['form_action'] == "delete")) { //    DELETE EVENT(s)
    // =======================================
    //  multi providers event
    // =======================================
    if ($GLOBALS['select_multi_providers']) {
        // what is multiple key around this $eid?
        $row = sqlQuery("SELECT pc_multiple FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));

        // obtain current list of providers regarding the multiple key
        $providers_current = array();
        $up = sqlStatement("SELECT pc_aid FROM openemr_postcalendar_events WHERE pc_multiple=?", array($row['pc_multiple']));
        while ($current = sqlFetchArray($up)) {
            $providers_current[] = $current['pc_aid'];
        }

        // establish a WHERE clause
        if ($row['pc_multiple']) {
            $whereClause = "pc_multiple = '{$row['pc_multiple']}'";
        } else {
            $whereClause = "pc_eid = '$eid'";
        }

        if ($_POST['recurr_affect'] == 'current') {
            // update all existing event records to exlude the current date
            foreach ($providers_current as $provider) {
                // update the provider's original event
                // get the original event's repeat specs
                $origEvent = sqlQuery("SELECT pc_recurrspec FROM openemr_postcalendar_events " .
                " WHERE pc_aid <=> ? AND pc_multiple=?", array($provider,$row['pc_multiple']));
                $oldRecurrspec = unserialize($origEvent['pc_recurrspec'], ['allowed_classes' => false]);
                $selected_date = date("Y-m-d", strtotime($_POST['selected_date']));
                if ($oldRecurrspec['exdate'] != "") {
                    $oldRecurrspec['exdate'] .= "," . $selected_date;
                } else {
                        $oldRecurrspec['exdate'] .= $selected_date;
                }

                // mod original event recur specs to exclude this date
                    sqlStatement("UPDATE openemr_postcalendar_events SET " .
                    " pc_recurrspec = ? " .
                    " WHERE " . $whereClause, array(serialize($oldRecurrspec)));
            }
        } elseif ($_POST['recurr_affect'] == 'future') {
            // update all existing event records to stop recurring on this date-1
            $selected_date = date("Y-m-d", (strtotime($_POST['selected_date']) - 24 * 60 * 60));
            foreach ($providers_current as $provider) {
                // In case of a change in the middle of the event
                if (strcmp($_POST['event_start_date'], $_POST['selected_date']) != 0) {
                    // update the provider's original event
                    sqlStatement("UPDATE openemr_postcalendar_events SET " .
                    " pc_enddate = ? " .
                    " WHERE " . $whereClause, array($selected_date));
                } else { // In case of a change in the event head
                    sqlStatement("DELETE FROM openemr_postcalendar_events WHERE " . $whereClause);
                }
            }
        } else {
            // really delete the event from the database
            sqlStatement("DELETE FROM openemr_postcalendar_events WHERE " . $whereClause);
        }
    } else { //  single provider event
        if ($_POST['recurr_affect'] == 'current') {
            // mod original event recur specs to exclude this date
            // get the original event's repeat specs
            $origEvent = sqlQuery("SELECT pc_recurrspec FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));
            $oldRecurrspec = unserialize($origEvent['pc_recurrspec'], ['allowed_classes' => false]);
            $selected_date = date("Ymd", strtotime($_POST['selected_date']));
            if ($oldRecurrspec['exdate'] != "") {
                $oldRecurrspec['exdate'] .= "," . $selected_date;
            } else {
                $oldRecurrspec['exdate'] .= $selected_date;
            }

                sqlStatement("UPDATE openemr_postcalendar_events SET " .
                " pc_recurrspec = ? " .
                " WHERE pc_eid = ?", array(serialize($oldRecurrspec),$eid));
        } elseif ($_POST['recurr_affect'] == 'future') {
            // mod original event to stop recurring on this date-1
            $selected_date = date("Ymd", (strtotime($_POST['selected_date']) - 24 * 60 * 60));
            sqlStatement("UPDATE openemr_postcalendar_events SET " .
                " pc_enddate = ? " .
                " WHERE pc_eid = ?", array($selected_date,$eid));
        } else {
            // fully delete the event from the database
            sqlStatement("DELETE FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));
        }
    }
}

if (!empty($_POST['form_action'])) {
    // Close this window and refresh the calendar (or the patient_tracker) display.
    echo "<html>\n<body>\n<script>\n";
    if ($info_msg) {
        echo " alert('" . addslashes($info_msg) . "');\n";
    }
    echo " if (opener && !opener.closed && opener.refreshme) {\n " .
      "  opener.refreshme();\n " . // This is for standard calendar page refresh
      " } else {\n " .
      " if(window.opener.pattrk){" .
     "  window.opener.pattrk.submit()\n " . // This is for patient flow board page refresh}
      " }};\n";
    echo " dlgclose();\n";
    echo "</script>\n</body>\n</html>\n";

    exit();
}
    $repeats = 0; // if the event repeats (always 0 for new events)
    $repeattype = '0';
    $repeatfreq = '0';
    $patientid = '';
if (!empty($_REQUEST['patientid'])) {
    $patientid = $_REQUEST['patientid'];
} elseif (!empty($_SESSION['pid'])) {
    $patientid = ($_SESSION['pid']);
}
    $patientname = null;
    $patienttitle = array();
    $pcroom = "";
    $hometext = "";
    $row = array();
    $informant = "";
    $groupid = '';
if (!empty($_REQUEST['groupid'])) {
    $groupid = $_REQUEST['groupid'];
}
    $groupname = null;
    $group_statuses = getGroupStatuses();

 // If we are editing an existing event, then get its data.
if ($eid) {
    // $row = sqlQuery("SELECT * FROM openemr_postcalendar_events WHERE pc_eid = $eid");

    $row = sqlQuery("SELECT e.*, u.fname, u.mname, u.lname " .
      "FROM openemr_postcalendar_events AS e " .
      "LEFT OUTER JOIN users AS u ON u.id = e.pc_informant " .
      "WHERE pc_eid = ?", array($eid));
    $informant = $row['fname'] . ' ' . $row['mname'] . ' ' . $row['lname'];

    // instead of using the event's starting date, keep what has been provided
    // via the GET array, see the top of this file
    if (empty($_GET['date'])) {
        $date = $row['pc_eventDate'];
    }

    $eventstartdate = $row['pc_eventDate']; // for repeating event stuff - JRM Oct-08
    $userid = $row['pc_aid'];
    $patientid = $row['pc_pid'];
    $groupid = $row['pc_gid'];
    $starttimeh = substr($row['pc_startTime'], 0, 2) + 0;
    $starttimem = substr($row['pc_startTime'], 3, 2);
    $repeats = $row['pc_recurrtype'];
    $multiple_value = $row['pc_multiple'];

    // parse out the repeating data, if any
    $rspecs = unserialize($row['pc_recurrspec'], ['allowed_classes' => false]); // extract recurring data
    if (!is_array($rspecs)) {
        $rspecs = [];
    }
    $repeattype = $rspecs['event_repeat_freq_type'] ?? 0;
    $repeatfreq = $rspecs['event_repeat_freq'] ?? 0;
    $repeatexdate = $rspecs['exdate'] ?? ''; // repeating date exceptions

    // Adjustments for repeat type 2, a particular weekday of the month.
    if ($repeats == 2) {
            $repeatfreq = $rspecs['event_repeat_on_freq'] ?? 0;
        if (($rspecs['event_repeat_on_num'] ?? 0) < 5) {
            $repeattype = 5;
        } else {
             $repeattype = 6;
        }
    }

    $recurrence_end_date = ($row['pc_endDate'] && $row['pc_endDate'] != '0000-00-00') ? $row['pc_endDate'] : null;
    $pcroom = $row['pc_room'];
    $hometext = $row['pc_hometext'];
    if (substr($hometext, 0, 6) == ':text:') {
        $hometext = substr($hometext, 6);
    }
} else {
      // a NEW event
      $eventstartdate = $date; // for repeating event stuff - JRM Oct-08

      //-------------------------------------
      //(CHEMED)
      //Set default facility for a new event based on the given 'userid'
    if ($userid) {
        if ($_SESSION['pc_facility']) {
            $pref_facility = sqlFetchArray(sqlStatement(
                "
		        SELECT f.id as facility_id,
		        f.name as facility
		        FROM facility f
		        WHERE f.id = ?
	          ",
                array($_SESSION['pc_facility'])
            ));
        } else {
            $pref_facility = sqlFetchArray(sqlStatement("
            SELECT u.facility_id,
	          f.name as facility
            FROM users u
            LEFT JOIN facility f on (u.facility_id = f.id)
            WHERE u.id = ?
            ", array($userid)));
        }
         $e2f = $pref_facility['facility_id'];
         $e2f_name = $pref_facility['facility'];
    }
}

 // If we have a patient ID, get the name and phone numbers to display.
if ($patientid) {
    $prow = sqlQuery("SELECT lname, fname, phone_home, phone_biz, DOB " .
     "FROM patient_data WHERE pid = ?", array($patientid));
    $patientname = $prow['lname'] . ", " . $prow['fname'];
    if ($prow['phone_home']) {
        $patienttitle['phone_home'] = xl("Home Phone") . ": " . $prow['phone_home'];
    }

    if ($prow['phone_biz']) {
        $patienttitle['phone_biz'] = xl("Work Phone") . ": " . $prow['phone_biz'];
    }
}

 // If we have a group id, get group data
if ($groupid) {
    $group_data = getGroup($groupid);
    $groupname = $group_data['group_name'];
    $group_end_date = $group_data['group_end_date'];
    if (!$recurrence_end_date && $group_end_date && $group_end_date != '0000-00-00') {
        $recurrence_end_date = $group_end_date;// If there is no recurr end date get group's end date as default (only if group has an end date)
    }
}

 // Get the providers list.
    $ures = sqlStatement("SELECT id, username, fname, lname FROM users WHERE " .
    "authorized != 0 AND active = 1 ORDER BY lname, fname");

 // Get event categories.
    $cres = sqlStatement("SELECT pc_catid, pc_catname, pc_recurrtype, pc_duration, pc_end_all_day " .
    "FROM openemr_postcalendar_categories where pc_active = 1 ORDER BY pc_seq");

 // Fix up the time format for AM/PM.
    $startampm = '1';
    if ($starttimeh >= 12) { // p.m. starts at noon and not 12:01
        $startampm = '2';
        if ($starttimeh > 12 && $GLOBALS['time_display_format'] == 1) {
            $starttimeh -= 12;
        }
    }

    ?>
<script>
<?php require $GLOBALS['srcdir'] . "/formatting_DateToYYYYMMDD_js.js.php" ?>

 var mypcc = '<?php echo $GLOBALS['phone_country_code'] ?>';

 var durations = new Array();
<?php
 // Read the event categories, generate their options list, and get
 // the default event duration from them if this is a new event.
 $cattype = 0;
if (!empty($_GET['prov']) && ($_GET['prov'] == true)) {
    $cattype = 1;
}

if ($_GET['group'] == true) {
    $cattype = 3;
}

$cres = sqlStatement("SELECT pc_catid, pc_cattype, pc_catname, " .
"pc_recurrtype, pc_duration, pc_end_all_day " .
"FROM openemr_postcalendar_categories where pc_active = 1 ORDER BY pc_seq");
$catoptions = "";
$prefcat_options = "    <option value='0'>-- " . xlt("None{{Category}}") . " --</option>\n";
$thisduration = 0;
if ($eid) {
    $thisduration = $row['pc_alldayevent'] ? 1440 : round($row['pc_duration'] / 60);
}

// Flag: new provider IN-Office open (Set Provider Schedule button).
// catid=2 normally has pc_end_all_day=1 → duration=1440 → "All day" radio checked → time fields disabled.
// We override this so the form opens in Timed mode with editable, pre-filled time fields.
$_is_new_prov_in = (!$eid && !empty($_GET['prov']) && $_GET['prov'] === true && (int)($_GET['catid'] ?? 0) === 2);

while ($crow = sqlFetchArray($cres)) {
    $duration = round($crow['pc_duration'] / 60);
    if ($crow['pc_end_all_day']) {
        $duration = 1440;
    }

    // This section is to build the list of preferred categories:
    if ($duration) {
        $prefcat_options .= "    <option value='" . attr($crow['pc_catid']) . "'";
        if ($eid) {
            if ($crow['pc_catid'] == $row['pc_prefcatid']) {
                $prefcat_options .= " selected";
            }
        }

        $prefcat_options .= ">" . text(xl_appt_category($crow['pc_catname'])) . "</option>\n";
    }

    if ($crow['pc_cattype'] != $cattype) {
        continue;
    }

    // For new provider IN-Office opens, emit 8 hrs instead of 1440 so Timed radio is selected.
    $emit_duration = ($_is_new_prov_in && (int)$crow['pc_catid'] === 2) ? 8 : $duration;
    echo " durations[" . attr($crow['pc_catid']) . "] = " . attr($emit_duration) . "\n";
    // echo " rectypes[" . $crow['pc_catid'] . "] = " . $crow['pc_recurrtype'] . "\n";
    $catoptions .= "    <option value='" . attr($crow['pc_catid']) . "'";
    if ($eid) {
        if ($crow['pc_catid'] == $row['pc_catid']) {
            $catoptions .= " selected";
        }
    } else {
        if ($crow['pc_catid'] == $default_catid) {
            $catoptions .= " selected";
            $thisduration = $_is_new_prov_in && (int)$crow['pc_catid'] === 2 ? 8 : $duration;
        }
    }

    $catoptions .= ">" . text(xl_appt_category($crow['pc_catname'])) . "</option>\n";
}
?>

<?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

// This is for callback by the find-patient popup.
function setpatient(pid, lname, fname, dob) {
    var f = document.forms[0];
    f.form_patient.value = lname + ', ' + fname;
    f.form_pid.value = pid;
    dobstyle = (dob == '' || dob.substr(5, 10) == '00-00') ? '' : 'none';
    document.getElementById('dob_row').style.display = dobstyle;
    let event = new CustomEvent('openemr:appointment:patient:set', {
        bubbles: true
        ,detail: {form: f, pid: pid, lname: lname, fname: fname, dob: dob}
    });
    f.dispatchEvent(event);
}

// This invokes the find-patient popup.
function sel_patient() {
    let title = '<?php echo xlt('Patient Search'); ?>';
    dlgopen('find_patient_popup.php', 'findPatient', 650, 450, '', title);
}

// This invokes javascript listener.
<?php
$eventDispatcher->dispatch(new AppointmentRenderEvent($row), AppointmentRenderEvent::RENDER_JAVASCRIPT, 10);
?>

// This is for callback by the find-group popup.
function setgroup(gid, name, end_date) {
    var f = document.forms[0];
    f.form_group.value = name;
    f.form_gid.value = gid;
    if (f.form_enddate.value == "") {
        f.form_enddate.value = end_date;
    }

}

// This invokes the find-group popup.
function sel_group() {
    top.restoreSession();
    let title = '<?php echo xlt('Group Search'); ?>';
    dlgopen('find_group_popup.php', '_blank', 650, 450, '', title);
}

// Do whatever is needed when a new event category is selected.
// For now this means changing the event title and duration.
function set_display() {
    var f = document.forms[0];
    var s = f.form_category;
    if (s.selectedIndex >= 0) {
        var catid = s.options[s.selectedIndex].value;
        var style_apptstatus = document.getElementById('title_apptstatus').style;
        var style_prefcat = document.getElementById('title_prefcat').style;
        if (catid == '2') { // In Office
            style_apptstatus.display = 'none';
            style_prefcat.display = '';
            f.form_apptstatus.style.display = 'none';
            f.form_prefcat.style.display = '';
        } else {
            style_prefcat.display = 'none';
            style_apptstatus.display = '';
            f.form_prefcat.style.display = 'none';
            f.form_apptstatus.style.display = '';
        }
    }
}

// Do whatever is needed when a new event category is selected.
// For now this means changing the event title and duration.
function set_category() {
    var f = document.forms[0];
    var s = f.form_category;
    if (s.selectedIndex >= 0) {
        var catid = s.options[s.selectedIndex].value;
        f.form_title.value = s.options[s.selectedIndex].text;
        f.form_duration.value = durations[catid];
        set_display();
    }
}

// On page load: sync display state. For new events the title is already
// pre-filled server-side; only overwrite if the field is blank.
document.addEventListener('DOMContentLoaded', function() {
    var f = document.forms[0];
    if (f && f.form_title && f.form_title.value === '') {
        set_category();
    } else {
        set_display();
    }
});

// Modify some visual attributes when the all-day or timed-event
// radio buttons are clicked.
function set_allday() {
    var f = document.forms[0];
    var color1 = 'var(--gray)';
    var color2 = 'var(--gray)';
    var disabled2 = true;
    if (document.getElementById('rballday1').checked) {
        color1 = '';
    }
    if (document.getElementById('rballday2').checked) {
        color2 = '';
        disabled2 = false;
    }
    document.getElementById('tdallday1').style.color = color1;
    document.getElementById('tdallday2').style.color = color2;
    //document.getElementById('tdallday3').style.color = color2;
    document.getElementById('tdallday4').style.color = color2;
    document.getElementById('tdallday5').style.color = color2;
    f.form_hour.disabled = disabled2;
    f.form_minute.disabled = disabled2;
    <?php if ($GLOBALS['time_display_format'] == 1) { ?>
        f.form_ampm.disabled = disabled2;
    <?php } ?>
    f.form_duration.disabled = disabled2;
}

// Modify some visual attributes when the Repeat checkbox is clicked.
function set_repeat() {
    var f = document.forms[0];
    var isdisabled = true;
    var mycolor = 'var(--gray)';
    var myvisibility = 'hidden';
    if (f.form_repeat.checked) {
        f.days_every_week.checked = false;
        document.getElementById("days_label").style.color = mycolor;
        var days = document.getElementById("days").getElementsByTagName('input');
        var labels = document.getElementById("days").getElementsByTagName('label');
        for (var i = 0; i < days.length; i++) {
            days[i].disabled = isdisabled;
            labels[i].style.color = mycolor;
        }
        isdisabled = false;
        mycolor = 'var(--black)';
        myvisibility = 'visible';
    }
    f.form_repeat_type.disabled = isdisabled;
    f.form_repeat_freq.disabled = isdisabled;
    f.form_enddate.disabled = isdisabled;
    document.getElementById('tdrepeat1').style.color = mycolor;
    document.getElementById('tdrepeat2').style.color = mycolor;
}

// Event when days_every_week is checked.
function set_days_every_week() {
    var f = document.forms[0];
    if (f.days_every_week.checked) {
        //disable regular repeat
        f.form_repeat.checked = false;
        f.form_repeat_type.disabled = true;
        f.form_repeat_freq.disabled = true;
        document.getElementById('tdrepeat1').style.color = 'var(--gray)';

        //enable end_date setting
        document.getElementById('tdrepeat2').style.color = 'var(--black)';
        f.form_enddate.disabled = false;

        var isdisabled = false;
        var mycolor = 'var(--black)';
        var myvisibility = 'visible';
    } else {
        var isdisabled = true;
        var mycolor = 'var(--gray)';
        var myvisibility = 'hidden';
    }
    document.getElementById("days_label").style.color = mycolor;
    var days = document.getElementById("days").getElementsByTagName('input');
    var labels = document.getElementById("days").getElementsByTagName('label');
    for (var i = 0; i < days.length; i++) {
        days[i].disabled = isdisabled;
        labels[i].style.color = mycolor;
    }

    //If no repetition is checked, disable end_date setting.
    if (!f.days_every_week.checked && !f.form_repeat.checked) {
        //disable end_date setting
        document.getElementById('tdrepeat2').style.color = mycolor;
        f.form_enddate.disabled = isdisabled;
    }
}

// Constants used by dateChanged() function.
const occurNames = Array(
    '<?php echo xls("1st{{nth}}"); ?>',
    '<?php echo xls("2nd{{nth}}"); ?>',
    '<?php echo xls("3rd{{nth}}"); ?>',
    '<?php echo xls("4th{{nth}}"); ?>'
);

const weekDays = Array(
    '<?php echo xls("Sunday"); ?>',
    '<?php echo xls("Monday"); ?>',
    '<?php echo xls("Tuesday"); ?>',
    '<?php echo xls("Wednesday"); ?>',
    '<?php echo xls("Thursday"); ?>',
    '<?php echo xls("Friday"); ?>',
    '<?php echo xls("Saturday"); ?>'
);

 // Monitor start date changes to adjust repeat type options.
function dateChanged() {
    var f = document.forms[0];
    if (!f.form_date.value) return;
    var d = new Date(DateToYYYYMMDD_js(f.form_date.value));
    var downame = weekDays[d.getUTCDay()];
    var nthtext = '';
    var occur = Math.floor((d.getUTCDate() - 1) / 7);
    if (occur < 4) { // 5th is not allowed
        nthtext = occurNames[occur] + ' ' + downame;
    }
    var lasttext = '';
    var tmp = new Date(d.getUTCFullYear(), d.getUTCMonth() + 1, 0);
    if (tmp.getDate() - d.getUTCDate() < 7) { // Modified by epsdky 2016 (details in commit)
        // This is a last occurrence of the specified weekday in the month,
        // so permit that as an option.
        lasttext = '<?php echo xls("Last"); ?> ' + downame;
    }
    var si = f.form_repeat_type.selectedIndex;
    var opts = f.form_repeat_type.options;
    opts.length = 5; // remove any nth and Last entries
    if (nthtext) {
        opts[opts.length] = new Option(nthtext, '5');
    }
    if (lasttext) {
        opts[opts.length] = new Option(lasttext, '6');
    }
    if (si < opts.length) {
        f.form_repeat_type.selectedIndex = si;
    } else {
        f.form_repeat_type.selectedIndex = 5;
    } // Added by epsdky 2016 (details in commit)
}

// This is for callback by the find-available popup.
function setappt(year,mon,mday,hours,minutes) {
    //Infeg Save button should become active once an appointment is selected.
    $('#form_save').attr('disabled', false);
    var f = document.forms[0];
    <?php
    $currentDateFormat = $GLOBALS['date_display_format'];
    if ($currentDateFormat == 0) { ?>
    f.form_date.value =  '' + year + '-' +
        ('' + (mon  + 100)).substring(1) + '-' +
        ('' + (mday + 100)).substring(1);
    <?php } elseif ($currentDateFormat == 1) { ?>
    f.form_date.value = ('' + (mon  + 100)).substring(1) + '/' +
        ('' + (mday + 100)).substring(1) + '/' +
        '' + year;
    <?php } elseif ($currentDateFormat == 2) { ?>
    f.form_date.value = ('' + (mday + 100)).substring(1) + '/' +
        ('' + (mon  + 100)).substring(1) + '/' +
        '' + year;
    <?php } ?>
    f.form_hour.value = hours;
    <?php if ($GLOBALS['time_display_format'] == 1) { ?>
        f.form_hour.value = (hours > 12) ? hours - 12 : hours;
        f.form_ampm.selectedIndex = (hours >= 12) ? 1 : 0;
    <?php } ?>
    f.form_minute.value = ('' + (minutes + 100)).substring(1);
}

// Invoke the find-available popup.
function find_available(extra) {
    //Infeg Save button should become active once an appointment is selected.
    $('#form_save').attr('disabled', false);
    top.restoreSession();
    // (CHEMED) Conditional value selection, because there is no <select> element
    // when making an appointment for a specific provider
    var s = document.forms[0].form_provider;
    var f = document.forms[0].facility;
    <?php if ($userid != 0) { ?>
        s = document.forms[0].form_provider.value;
        f = document.forms[0].facility.value;
    <?php } else {?>
        s = document.forms[0].form_provider.options[s.selectedIndex].value;
        f = document.forms[0].facility.options[f.selectedIndex].value;
    <?php }?>
    var c = document.forms[0].form_category;
    var formDate = document.forms[0].form_date;
    let title = <?php echo xlj('Available Appointments Calendar'); ?>;
    dlgopen('<?php echo $GLOBALS['web_root']; ?>/interface/main/calendar/find_appt_popup.php' +
        '?providerid=' + s +
        '&catid=' + c.options[c.selectedIndex].value +
        '&facility=' + f +
        '&startdate=' + formDate.value +
        '&evdur=' + document.forms[0].form_duration.value +
        '&eid=<?php echo (int)$eid; ?>' + extra,
        '', 725, 200, '', title);
}
</script>
<style>
    /* ── Design tokens ── */
    :root {
        --c-bg:         #f0f2f7;
        --c-surface:    #ffffff;
        --c-border:     #e2e6f0;
        --c-accent:     #9588e8;
        --c-accent-dk:  linear-gradient(135deg,#5b21b6,#7c3aed);
        --c-accent-lt:  #eef0fd;
        --c-danger:     #e63946;
        --c-warn:       #f4a261;
        --c-success:    #2ec4b6;
        --c-text:       #1a1d2e;
        --c-muted:      #6b7280;
        --c-label:      #374151;
        --radius:       10px;
        --radius-sm:    6px;
        --shadow:       0 2px 12px rgba(67,97,238,.10);
        --shadow-lg:    0 8px 32px rgba(67,97,238,.14);
        --font:         'Montserrat', system-ui, sans-serif;
    }

    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap');

    html, body.add-edit-event {
        height: 100%;
        margin: 0;
        padding: 0;
        overflow: hidden;
    }
    body.add-edit-event {
        background: var(--c-bg);
        font-family: Montserrat, sans-serif;
        color: var(--c-text);
        display: flex;
        flex-direction: column;
    }
    #ae-scroll-body {
        flex: 1 1 auto;
        overflow-y: auto;
        overflow-x: hidden;
        padding-bottom: 24px;
    }

    /* ── Tab bar ── */
    .ae-tabs {
        flex-shrink: 0;
        display: flex;
        gap: 2px;
        background: var(--c-surface);
        border-bottom: 2px solid var(--c-border);
        padding: 0 16px;
        margin-bottom: 0;
    }
    .ae-tabs a {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 12px 20px;
        font-size: 13px;
        font-weight: 600;
        color: var(--c-muted);
        text-decoration: none;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: color .15s, border-color .15s;
        letter-spacing: .3px;
    }
    .ae-tabs a:hover { color: var(--c-accent); }
    .ae-tabs a.active {
        color: var(--c-accent);
        border-bottom-color: var(--c-accent);
    }
    .ae-tabs a .tab-icon {
        width: 16px; height: 16px; opacity: .75;
    }

    /* ── Main card ── */
    .ae-card {
        background: var(--c-surface);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        margin: 14px 14px 0;
        padding: 0;
        overflow: hidden;
    }

    /* ── Section headers ── */
    .ae-section {
        padding: 14px 20px 16px;
        border-bottom: 1px solid var(--c-border);
    }
    .ae-section:last-child { border-bottom: none; }
    .ae-section-title {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
        color: var(--c-muted);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 7px;
    }
    .ae-section-title::before {
        content: '';
        display: inline-block;
        width: 3px; height: 14px;
        background: var(--c-accent);
        border-radius: 2px;
    }

    /* ── Form grid ── */
    .ae-grid {
        display: grid;
        gap: 12px 16px;
    }
    .ae-grid-2 { grid-template-columns: 1fr 1fr; }
    .ae-grid-3 { grid-template-columns: 1fr 1fr 1fr; }

    /* ── Field ── */
    .ae-field { display: flex; flex-direction: column; gap: 4px; }
    .ae-field label {
        font-size: 11.5px;
        font-weight: 600;
        color: var(--c-label);
        letter-spacing: .2px;
    }
    .ae-field .form-control,
    .ae-field select,
    .ae-field input[type=text],
    .ae-field input[type=number] {
        border: 1.5px solid var(--c-border);
        border-radius: var(--radius-sm);
        padding: 7px 10px;
        font-size: 13px;
        font-family: var(--font);
        color: var(--c-text);
        background: #fff;
        transition: border-color .15s, box-shadow .15s;
        height: auto;
        width: 100%;
        box-sizing: border-box;
    }
    .ae-field .form-control:focus,
    .ae-field select:focus,
    .ae-field input[type=text]:focus {
        border-color: var(--c-accent);
        box-shadow: 0 0 0 3px rgba(67,97,238,.12);
        outline: none;
    }

    /* ── Patient jumbotron → styled card ── */
    .ae-patient-card {
        background: var(--c-accent-lt);
        border: 1.5px solid #c7cff8;
        border-radius: var(--radius-sm);
        padding: 12px 14px;
    }
    .ae-patient-card .form-control {
        background: #fff;
        cursor: pointer;
    }
    .ae-patient-meta {
        font-size: 11.5px;
        color: var(--c-danger);
        margin-top: 5px;
        font-weight: 500;
    }

    /* ── Time row ── */
    .ae-time-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }
    .ae-time-row label { font-size: 11.5px; font-weight: 600; color: var(--c-label); white-space: nowrap; }
    .ae-time-row input[type=text],
    .ae-time-row select {
        border: 1.5px solid var(--c-border);
        border-radius: var(--radius-sm);
        padding: 7px 10px;
        font-size: 13px;
        font-family: var(--font);
        color: var(--c-text);
        background: #fff;
        transition: border-color .15s;
    }
    .ae-time-row input[type=text]:focus,
    .ae-time-row select:focus {
        border-color: var(--c-accent);
        box-shadow: 0 0 0 3px rgba(67,97,238,.12);
        outline: none;
    }
    .ae-time-row .w-date  { width: 130px; }
    .ae-time-row .w-hour  { width: 58px; text-align: center; }
    .ae-time-row .w-min   { width: 58px; text-align: center; }
    .ae-time-row .w-ampm  { width: 68px; }
    .ae-time-row .w-dur   { width: 72px; text-align: center; }
    .ae-time-row .sep     { color: var(--c-muted); font-weight: 700; }

    /* ── Radio / checkbox pills ── */
    .ae-radio-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px 5px 8px;
        border: 1.5px solid var(--c-border);
        border-radius: 20px;
        cursor: pointer;
        font-size: 12.5px;
        font-weight: 500;
        color: var(--c-muted);
        background: #fff;
        transition: all .15s;
        user-select: none;
    }
    .ae-radio-pill input { display: none; }
    .ae-radio-pill:has(input:checked) {
        border-color: var(--c-accent);
        background: var(--c-accent-lt);
        color: var(--c-accent);
    }
    .ae-radio-pill .dot {
        width: 14px; height: 14px;
        border-radius: 50%;
        border: 2px solid currentColor;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .ae-radio-pill:has(input:checked) .dot::after {
        content: '';
        width: 6px; height: 6px;
        background: var(--c-accent);
        border-radius: 50%;
    }

    /* ── Repeat section ── */
    .ae-repeat-row {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 8px;
    }
    .ae-check-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 12.5px;
        font-weight: 600;
        color: var(--c-label);
        cursor: pointer;
    }
    .ae-check-label input[type=checkbox] {
        width: 15px; height: 15px;
        accent-color: var(--c-accent);
        cursor: pointer;
    }
    .ae-repeat-row select {
        border: 1.5px solid var(--c-border);
        border-radius: var(--radius-sm);
        padding: 6px 10px;
        font-size: 12.5px;
        font-family: var(--font);
        background: #fff;
        color: var(--c-text);
        transition: border-color .15s;
    }
    .ae-repeat-row select:focus {
        border-color: var(--c-accent); outline: none;
        box-shadow: 0 0 0 3px rgba(67,97,238,.12);
    }

    /* ── Days-of-week chips ── */
    .dow-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
    .dow-chip {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 4px 11px;
        border: 1.5px solid var(--c-border);
        border-radius: 20px;
        font-size: 12px; font-weight: 600;
        color: var(--c-muted);
        background: #fff;
        cursor: pointer;
        transition: all .15s;
        user-select: none;
    }
    .dow-chip input[type=checkbox] { display: none; }
    .dow-chip:has(input:checked) {
        border-color: var(--c-accent);
        background: var(--c-accent-lt);
        color: var(--c-accent);
    }

    /* ── Recurrence popup ── */
    .ae-recurr-popup {
        background: #fffbeb;
        border: 1.5px solid var(--c-warn);
        border-radius: var(--radius-sm);
        padding: 14px 16px;
        margin-top: 4px;
    }
    .ae-recurr-popup p { font-size: 13px; font-weight: 500; margin: 0 0 10px; color: #92400e; }

    /* ── Action buttons ── */
    .ae-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        padding: 16px 20px;
        background: #f8f9fc;
        border-top: 1px solid var(--c-border);
    }
    .ae-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 20px;
        border-radius: var(--radius-sm);
        font-size: 13px;
        font-weight: 600;
        font-family: var(--font);
        border: none;
        cursor: pointer;
        transition: all .15s;
        letter-spacing: .2px;
    }
    .ae-btn-primary  { background: var(--c-accent); color: #fff; }
    .ae-btn-primary:hover  { background: var(--c-accent-dk); box-shadow:0 4px 14px rgba(124,58,237,.35) !important}
    .ae-btn-secondary { background: #fff; color: var(--c-text); border: 1.5px solid var(--c-border); }
    .ae-btn-secondary:hover { border-color: var(--c-accent); color: var(--c-accent); }
    .ae-btn-danger   { background: #fff; color: var(--c-danger); border: 1.5px solid #fca5a5; }
    .ae-btn-danger:hover   { background: #fef2f2; border-color: var(--c-danger); }
    .ae-btn:disabled { opacity: .4; cursor: not-allowed; }

    /* ── Informant footer ── */
    .ae-informant {
        font-size: 11px;
        color: var(--c-muted);
        padding: 8px 20px 0;
        font-style: italic;
    }

    /* ── Responsive ── */
    @media (max-width: 640px) {
        .ae-grid-2, .ae-grid-3 { grid-template-columns: 1fr; }
        .ae-time-row { gap: 6px; }
        .ae-card { margin: 8px 6px 0; }
        #ae-scroll-body { padding-bottom: 30px; }
    }
</style>
</head>

<body class="add-edit-event">

<!-- ══════════ TAB BAR ══════════ -->
<nav class="ae-tabs">
    <?php
        $provider_class = '';
        $group_class    = '';
        $normal         = '';
        if (!empty($_GET['prov']) && ($_GET['prov'] == true)) {
            $provider_class = ' active';
        } elseif ($_GET['group'] == true) {
            $group_class = ' active';
        } else {
            $normal = ' active';
        }
        $eid    = $_REQUEST["eid"]        ?? null;
        $startm = $_REQUEST["startampm"]  ?? null;
        $starth = $_REQUEST["starttimeh"] ?? null;
        $uid    = $_REQUEST["userid"]     ?? null;
        $starttm= $_REQUEST["starttimem"] ?? null;
        $dt     = $_REQUEST["date"]       ?? null;
        $cid    = $_REQUEST["catid"]      ?? null;
        $base   = "add_edit_event.php?startampm=" . attr($startm)
                . "&starttimeh=" . attr($starth)
                . "&userid="     . attr($uid)
                . "&starttimem=" . attr($starttm)
                . "&date="       . attr($dt)
                . "&catid="      . attr($cid);
    ?>
    <a class="<?php echo $normal; ?>" href="<?php echo $base; ?>">
        <svg class="tab-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10 9a3 3 0 100-6 3 3 0 000 6zm-7 9a7 7 0 1114 0H3z"/></svg>
        <?php echo xlt('Patient'); ?>
    </a>
    <a class="<?php echo $provider_class; ?>" href="<?php echo $base; ?>&prov=true">
        <svg class="tab-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 2a4 4 0 100 8 4 4 0 000-8zM2 18a8 8 0 1116 0H2z" clip-rule="evenodd"/></svg>
        <?php echo xlt('Provider'); ?>
    </a>
    <?php if ($have_group_global_enabled) : ?>
    <a class="<?php echo $group_class; ?>" href="<?php echo $base; ?>&group=true">
        <svg class="tab-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v1h8v-1zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-1a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v1h-3zM4.75 14.094A5.973 5.973 0 004 17v1H1v-1a3 3 0 013.75-2.906z"/></svg>
        <?php echo xlt('Group'); ?>
    </a>
    <?php endif; ?>
</nav>

<div id="ae-scroll-body">

<form role="form" method="post" name="theform" id="theform" action="add_edit_event.php?eid=<?php echo attr($eid) ?>">
<input type="hidden" size="2" name="resname" value="empty" />
<?php
if (!empty($_POST["resname"]) && ($_POST["resname"] == "noresult")) {
    echo '<script>
    if (!opener.closed && opener.refreshme) opener.refreshme();
    top.restoreSession();
    opener.document.location="../../new/new.php";
    dlgclose();
</script>';
}
$classprov = 'current';
$classpati = '';
?>
<input type="hidden" name="form_action"      id="form_action"      value="" />
<input type="hidden" name="recurr_affect"    id="recurr_affect"    value="" />
<input type="hidden" name="selected_date"    id="selected_date"    value="<?php echo attr($date); ?>" />
<input type="hidden" name="event_start_date" id="event_start_date" value="<?php echo attr($eventstartdate); ?>" />
<input type="hidden" name="old_repeats"      id="old_repeats"      value="<?php echo attr($repeats); ?>" />
<input type="hidden" name="rt2_flag2"        id="rt2_flag2"        value="<?php echo attr(isset($rspecs['rt2_pf_flag']) ? $rspecs['rt2_pf_flag'] : '0'); ?>" />

<div class="ae-card">

    <!-- ── Event Details ── -->
    <div class="ae-section">
        <div class="ae-section-title"><?php echo xlt('Event Details'); ?></div>
        <div class="ae-grid ae-grid-2">
            <div class="ae-field">
                <label for="form_category"><?php echo xlt('Category'); ?></label>
                <select class="form-control" name="form_category" id="form_category" onchange="set_category()">
                    <?php echo $catoptions ?>
                </select>
            </div>
            <div class="ae-field">
                <label for="form_title"><?php echo xlt('Title'); ?></label>
                <input class="form-control" type="text" name="form_title" id="form_title"
                       value="<?php echo $eid ? attr($row['pc_title'] ?? '') : attr($_office_visit_name); ?>"
                       title="<?php echo xla('Event title'); ?>" />
            </div>
            <div class="ae-field">
                <label for="facility"><?php echo xlt('Facility'); ?></label>
                <select class="form-control" name="facility" id="facility">
                    <?php
                    $facils = getUserFacilities($_SESSION['authUserID']);
                    $qsql   = sqlStatement("SELECT id, name FROM facility WHERE service_location != 0");
                    while ($facrow = sqlFetchArray($qsql)) {
                        if (!empty($_SESSION['authorizedUser']) || in_array($facrow, $facils)) {
                            $selected = ($facrow['id'] == $e2f) ? 'selected="selected"' : '';
                        } else {
                            $selected = (!empty($e2f) && ($facrow['id'] == $e2f)) ? 'selected="selected"' : '';
                        }
                        echo "<option value='" . attr($facrow['id']) . "' $selected>" . text($facrow['name']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="ae-field">
                <label for="billing_facility"><?php echo xlt('Billing Facility'); ?></label>
                <?php billing_facility('billing_facility', ($row['pc_billing_location'] ?? null)); ?>
            </div>
        </div>
    </div>

    <!-- ── Patient ── -->
    <?php if (empty($_GET['prov']) && empty($_GET['group'])) : ?>
    <div class="ae-section">
        <div class="ae-section-title"><?php echo xlt('Patient'); ?></div>
        <div class="ae-patient-card">
            <div class="ae-field">
                <label for="form_patient"><?php echo xlt('Patient Name'); ?></label>
                <input class="form-control" type="text" name="form_patient" id="form_patient"
                       style="cursor:pointer"
                       placeholder="<?php echo xla('Click to select patient'); ?>"
                       value="<?php echo is_null($patientname) ? '' : attr($patientname); ?>"
                       onclick="sel_patient()"
                       title="<?php echo xla('Click to select patient'); ?>" />
                <input type="hidden" name="form_pid" value="<?php echo attr($patientid) ?>" />
            </div>
            <?php if (!empty($patienttitle)) : ?>
            <div class="ae-patient-meta">
                <?php foreach ($patienttitle as $value) { if ($value != "") echo text(trim($value)) . "<br/>"; } ?>
            </div>
            <?php endif; ?>
            <?php $eventDispatcher->dispatch(new AppointmentRenderEvent($row), AppointmentRenderEvent::RENDER_BELOW_PATIENT, 10); ?>
        </div>
        <?php
        $patient_dob = trim($prow['DOB'] ?? '');
        $is_group    = $groupname;
        $dobstyle    = (!empty($prow) && (!$patient_dob || substr($patient_dob, 5) == '00-00') && !$is_group) ? '' : 'none';
        ?>
        <div id="dob_row" style="display:<?php echo $dobstyle ?>; margin-top:10px;">
            <div class="ae-field">
                <label for="form_dob" style="color:var(--c-danger)"><?php echo xlt('DOB is missing — please enter if possible'); ?></label>
                <input class="form-control datepicker" type="text" name="form_dob" id="form_dob"
                       title="<?php echo xla('yyyy-mm-dd date of birth'); ?>" />
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Group ── -->
    <?php if ($_GET['group'] === true && $have_group_global_enabled) : ?>
    <div class="ae-section">
        <div class="ae-section-title"><?php echo xlt('Group'); ?></div>
        <div class="ae-field">
            <label for="form_group"><?php echo xlt('Group Name'); ?></label>
            <input class="form-control" type="text" name="form_group" id="form_group"
                   style="cursor:pointer"
                   placeholder="<?php echo xla('Click to select'); ?>"
                   value="<?php echo is_null($groupname) ? '' : attr($groupname); ?>"
                   onclick="sel_group()"
                   title="<?php echo xla('Click to select group'); ?>" readonly />
            <input type="hidden" name="form_gid" value="<?php echo attr($groupid) ?>" />
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Provider ── -->
    <div class="ae-section">
        <div class="ae-section-title">
            <?php echo ($_GET['group'] == true) ? xlt('Coordinating Counselors') : xlt('Provider'); ?>
        </div>
        <div class="ae-field">
            <label for="provd">
                <?php echo ($_GET['group'] == true) ? xlt('Select Counselor(s)') : xlt('Select Provider'); ?>
            </label>
            <?php
            if ($GLOBALS['select_multi_providers']) {
                $providers_array = array();
                if ($eid) {
                    if ($multiple_value) {
                        $qall = sqlStatement("SELECT pc_aid AS providers FROM openemr_postcalendar_events WHERE pc_multiple = ?", array($multiple_value));
                        while ($r = sqlFetchArray($qall)) { $providers_array[] = $r['providers']; }
                    } else {
                        $qall = sqlStatement("SELECT pc_aid AS providers FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));
                        $providers_array = sqlFetchArray($qall);
                    }
                }
                echo "<select class='form-control' name='form_provider[]' id='provd' multiple='multiple' size='4'>";
                while ($urow = sqlFetchArray($ures)) {
                    echo "<option value='" . attr($urow['id']) . "'";
                    if ($userid && (in_array($urow['id'], $providers_array) || ($urow['id'] == $userid))) echo " selected";
                    echo ">" . text($urow['lname']);
                    if ($urow['fname']) echo ", " . text($urow['fname']);
                    echo "</option>\n";
                }
                echo '</select>';
            } else {
                if ($eid) {
                    $qprov = sqlStatement("SELECT pc_aid FROM openemr_postcalendar_events WHERE pc_eid = ?", array($eid));
                    $provider = sqlFetchArray($qprov);
                    $defaultProvider = $provider['pc_aid'];
                } else {
                    $defaultProvider = $_SESSION['authUserID'];
                    if (is_array($_SESSION['pc_username']) && count($_SESSION['pc_username']) >= 1) {
                        $pc_username   = $_SESSION['pc_username'];
                        $firstProvider = sqlFetchArray(sqlStatement("select id from users where username=?", array($pc_username[0])));
                        $defaultProvider = $firstProvider['id'] ?? '';
                    }
                    if ($userid) $defaultProvider = $userid;
                }
                echo "<select class='form-control' name='form_provider' id='provd'>";
                while ($urow = sqlFetchArray($ures)) {
                    echo "<option value='" . attr($urow['id']) . "'";
                    if ($urow['id'] == $defaultProvider) echo " selected";
                    echo ">" . text($urow['lname']);
                    if ($urow['fname']) echo ", " . text($urow['fname']);
                    echo "</option>\n";
                }
                echo "</select>";
            }
            ?>
        </div>
    </div>

    <!-- ── Date & Time ── -->
    <?php
    function isDaysEveryWeek($repeat) { return $repeat == 3; }
    function isRegularRepeat($repeat) { return $repeat == 1 || $repeat == 2; }
    ?>
    <div class="ae-section">
        <div class="ae-section-title"><?php echo xlt('Date & Time'); ?></div>

        <div class="ae-time-row" style="margin-bottom:14px;flex-wrap:wrap;">
            <label class="ae-radio-pill">
                <input type="radio" name="form_allday" onclick="set_allday()" value="1" id="rballday1"<?php echo ($thisduration == 1440) ? " checked" : ""; ?> />
                <span class="dot"></span>
                <span id="tdallday1"><?php echo xlt('All day'); ?></span>
            </label>
            <label class="ae-radio-pill">
                <input type="radio" name="form_allday" onclick="set_allday()" value="0" id="rballday2"<?php echo ($thisduration != 1440) ? " checked" : ""; ?> />
                <span class="dot"></span>
                <span id="tdallday2"><?php echo xlt('Timed'); ?></span>
            </label>
            <label style="font-size:11.5px;font-weight:600;color:var(--c-label)"><?php echo xlt('Date'); ?></label>
            <input class="w-date datepicker" type="text" name="form_date" id="form_date"
                   value="<?php echo attr(oeFormatShortDate($date)) ?>"
                   title="<?php echo xla('Event date or starting date'); ?>"
                   onchange="dateChanged()" />
            <label style="font-size:11.5px;font-weight:600;color:var(--c-label);margin-left:6px;" id="tdallday4"><?php echo xlt('Time'); ?></label>
            <input class="w-hour" type="text" name="form_hour"   value="<?php echo attr($starttimeh) ?>" title="<?php echo xla('Hour'); ?>" />
            <span class="sep">:</span>
            <input class="w-min"  type="text" name="form_minute" value="<?php echo attr($starttimem) ?>" title="<?php echo xla('Minute'); ?>" />
            <?php if ($GLOBALS['time_display_format'] == 1) : ?>
            <select class="w-ampm" name="form_ampm" title="<?php echo xla('Note: 12:00 noon is PM'); ?>">
                <option value="1"><?php echo xlt('AM'); ?></option>
                <option value="2"<?php echo ($startampm == '2') ? " selected" : ""; ?>><?php echo xlt('PM'); ?></option>
            </select>
            <?php endif; ?>
            <label style="font-size:11.5px;font-weight:600;color:var(--c-label)" id="tdallday5"><?php echo xlt('Duration'); ?></label>
            <input class="w-dur" type="text" name="form_duration" value="<?php echo attr($thisduration) ?>"
                   title="<?php echo xla('Duration in hours'); ?>" />
            <span style="font-size:11.5px;color:var(--c-muted)"><?php echo xlt('hr'); ?></span>
        </div>

        <!-- Repeat row -->
        <div class="ae-repeat-row" style="margin-bottom:10px;">
            <label class="ae-check-label">
                <input type="checkbox" name="form_repeat" id="form_repeat" onclick="set_repeat(this)" value="1"<?php echo isRegularRepeat($repeats) ? " checked" : ""; ?> />
                <span id="tdrepeat1"><?php echo xlt('Repeats'); ?></span>
            </label>
            <input type="hidden" name="form_repeat_exdate" id="form_repeat_exdate" value="<?php echo attr($repeatexdate ?? ''); ?>" />
            <select name="form_repeat_freq" title="<?php echo xla('Frequency'); ?>">
                <?php foreach (array(1=>xl('every'),2=>xl('2nd{{every}}'),3=>xl('3rd{{every}}'),4=>xl('4th{{every}}'),5=>xl('5th{{every}}'),6=>xl('6th{{every}}')) as $k=>$v) {
                    echo "<option value='" . attr($k) . "'" . ($k==$repeatfreq && isRegularRepeat($repeats)?" selected":"") . ">" . text($v) . "</option>\n";
                } ?>
            </select>
            <select name="form_repeat_type">
                <?php foreach (array(0=>xl('day'),4=>xl('workday'),1=>xl('week'),2=>xl('month'),3=>xl('year'),5=>'?',6=>'?') as $k=>$v) {
                    echo "<option value='" . attr($k) . "'" . ($k==$repeattype && isRegularRepeat($repeats)?" selected":"") . ">" . text($v) . "</option>\n";
                } ?>
            </select>
            <label style="font-size:11.5px;font-weight:600;color:var(--c-label)" id="tdrepeat2"><?php echo xlt('until'); ?></label>
            <input class="datepicker" type="text"
                   style="width:120px;border:1.5px solid var(--c-border);border-radius:var(--radius-sm);padding:6px 10px;font-size:12.5px;font-family:var(--font);"
                   name="form_enddate" id="form_enddate"
                   value="<?php echo attr(oeFormatShortDate($recurrence_end_date ?? '')) ?>"
                   title="<?php echo xla('Last date of this event'); ?>" />
            <?php /* Excluded-dates warning removed — not actionable for providers */ ?>
        </div>

        <!-- Days of week -->
        <div id="days_every_week_row">
            <label class="ae-check-label" style="margin-bottom:8px;">
                <input type="checkbox" id="days_every_week" name="days_every_week"
                       onclick="set_days_every_week()"<?php echo isDaysEveryWeek($repeats) ? " checked" : ""; ?> />
                <span id="days_label"><?php echo xlt('Days of Week'); ?></span>
            </label>
            <div class="dow-chips" id="days">
                <?php
                $weekdays = [1=>xl('Su{{Sunday}}'),2=>xl('Mo{{Monday}}'),3=>xl('Tu{{Tuesday}}'),4=>xl('We{{Wednesday}}'),5=>xl('Th{{Thursday}}'),6=>xl('Fr{{Friday}}'),7=>xl('Sa{{Saturday}}')];
                foreach ($weekdays as $key => $value) {
                    $ka = attr($key);
                    $vt = text($value);
                    $ch = (in_array($key, explode(',', $repeatfreq)) && isDaysEveryWeek($repeats)) ? "checked" : "";
                    echo "<label class='dow-chip'><input type='checkbox' name='day_{$ka}' id='day_{$ka}' {$ch}>{$vt}</label>";
                }
                ?>
            </div>
        </div>
    </div>

    <!-- ── Status & Room ── -->
    <div class="ae-section">
        <div class="ae-section-title"><?php echo xlt('Status'); ?></div>
        <div class="ae-grid ae-grid-2">
            <div class="ae-field">
                <label id="title_apptstatus"><?php echo xlt('Appointment Status'); ?></label>
                <label id="title_prefcat" style="display:none;font-weight:600"><?php echo xlt('Pref Cat'); ?></label>
                <?php
                if ($_GET['group'] != true) {
                    generate_form_field(array('data_type'=>1,'field_id'=>'apptstatus','list_id'=>'apptstat','empty_title'=>'SKIP'), ($row['pc_apptstatus'] ?? null));
                } else {
                    generate_form_field(array('data_type'=>1,'field_id'=>'apptstatus','list_id'=>'groupstat','empty_title'=>'SKIP'), ($row['pc_apptstatus'] ?? null));
                }
                ?>
                <select class="form-control form-control-sm" name="form_prefcat" style="display:none"
                        title="<?php echo xla('Preferred Event Category'); ?>">
                    <?php echo $prefcat_options ?>
                </select>
            </div>
            <?php if (empty($_GET['prov'])) : ?>
            <div class="ae-field">
                <label><?php echo xlt('Room Number'); ?></label>
                <?php echo generate_select_list('form_room', 'patient_flow_board_rooms', $pcroom, xl('Room Number')); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Comments ── -->
    <div class="ae-section">
        <div class="ae-section-title"><?php echo xlt('Comments'); ?></div>
        <div class="ae-field">
            <input class="form-control" type="text" name="form_comments"
                   value="<?php echo attr($hometext); ?>"
                   placeholder="<?php echo xla('Optional notes about this appointment'); ?>"
                   title="<?php echo xla('Optional information about this event'); ?>" />
        </div>
    </div>

    <!-- ── Recurrence popup ── -->
    <div class="ae-section" id="recurr_popup" style="display:none;">
        <div class="ae-recurr-popup">
            <p><?php echo xlt('Apply changes to: only the Current event, this and all Future occurrences, or All occurrences?'); ?></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <?php if ($GLOBALS['submit_changes_for_all_appts_at_once']) : ?>
                <button type="button" class="ae-btn ae-btn-secondary" name="all_events" id="all_events"><?php echo xlt('All'); ?></button>
                <?php endif; ?>
                <button type="button" class="ae-btn ae-btn-secondary" id="recurr_cancel"><?php echo xlt('Cancel'); ?></button>
                <button type="button" class="ae-btn ae-btn-secondary" id="future_events"><?php echo xlt('Future'); ?></button>
                <button type="button" class="ae-btn ae-btn-primary"   id="current_event"><?php echo xlt('Current'); ?></button>
            </div>
        </div>
    </div>

    <!-- ── Action buttons ── -->
    <div class="ae-actions">
        <button type="button" class="ae-btn ae-btn-primary" name="form_save" id="form_save">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M16.707 5.293a1 1 0 00-1.414 0L8 12.586 4.707 9.293a1 1 0 00-1.414 1.414l4 4a1 1 0 001.414 0l8-8a1 1 0 000-1.414z"/></svg>
            <?php echo xlt('Save'); ?>
        </button>
        <?php if (!($GLOBALS['select_multi_providers'])) : ?>
        <button type="button" class="ae-btn ae-btn-secondary" id="find_available">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
            <?php echo xlt('Find Available'); ?>
        </button>
        <?php endif; ?>
        <button type="button" class="ae-btn ae-btn-danger" name="form_delete" id="form_delete"<?php echo (!$eid) ? " disabled" : ""; ?>>
            <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm4 0a1 1 0 012 0v6a1 1 0 11-2 0V8z" clip-rule="evenodd"/></svg>
            <?php echo xlt('Delete'); ?>
        </button>
        <button type="button" class="ae-btn ae-btn-secondary" id="cancel" onclick="dlgclose()">
            <?php echo xlt('Cancel'); ?>
        </button>
        <button type="button" class="ae-btn ae-btn-secondary" name="form_duplicate" id="form_duplicate">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor"><path d="M7 9a2 2 0 012-2h6a2 2 0 012 2v6a2 2 0 01-2 2H9a2 2 0 01-2-2V9z"/><path d="M5 3a2 2 0 00-2 2v6a2 2 0 002 2V5h8a2 2 0 00-2-2H5z"/></svg>
            <?php echo xlt('Duplicate'); ?>
        </button>
    </div>

    <?php if ($informant) : ?>
    <div class="ae-informant">
        <?php echo xlt('Last updated by') . ' ' . text($informant) . ' ' . xlt('on') . ' ' . text($row['pc_time']); ?>
    </div>
    <?php endif; ?>

</div><!-- .ae-card -->
</form>

</div><!-- #ae-scroll-body -->

<!-- form support functions-->
<script>
$(function () {
    /* ── Form initialisation ── */
<?php if ($eid) { ?>
    set_display();
<?php } else { ?>
    set_category();
    /* New event: force repeat off and guarantee a sensible duration */
    document.getElementById('form_repeat').checked    = false;
    document.getElementById('days_every_week').checked = false;
    var durField = document.forms[0].form_duration;
    if (!durField.value || parseInt(durField.value) === 0) {
        durField.value = <?php echo (int)($thisduration > 0 ? $thisduration : 15); ?>;
    }
<?php if ($_is_new_prov_in) { ?>
    /* Set Provider Schedule: force Timed mode, pre-fill time from calendar IN event */
    document.getElementById('rballday1').checked = false;
    document.getElementById('rballday2').checked = true;
    var f = document.forms[0];
    f.form_hour.value   = '<?php echo attr($starttimeh); ?>';
    f.form_minute.value = '<?php echo attr($starttimem); ?>';
    <?php if ($GLOBALS['time_display_format'] == 1) { ?>
    f.form_ampm.value = '<?php echo attr((isset($_GET['startampm']) && $_GET['startampm'] == '2') ? '2' : ($starttimeh >= 12 ? '2' : '1')); ?>';
    <?php } ?>
    /* Pre-check Repeats and set workday frequency */
    document.getElementById('form_repeat').checked = true;
    document.querySelector('select[name="form_repeat_type"]').value = '4'; // workday
<?php } ?>
<?php } ?>
    set_allday();
    set_repeat();
    set_days_every_week();
    dateChanged();

    /* ── Button handlers ── */
    $("#form_save").click(function (e)      { validateform(e, "save"); });
    $("#form_duplicate").click(function (e) { validateform(e, "duplicate"); });
    $("#find_available").click(function ()  { find_available(''); });
    $("#form_delete").click(function ()     { deleteEvent(); });

    /* recurrence popup buttons */
    $("#all_events").click(function ()  { $("#recurr_affect").val("all");     EnableForm(); SubmitForm(); });
    $("#future_events").click(function(){ $("#recurr_affect").val("future");  EnableForm(); SubmitForm(); });
    $("#current_event").click(function(){ $("#recurr_affect").val("current"); EnableForm(); SubmitForm(); });
    $("#recurr_cancel").click(function(){ $("#recurr_affect").val("");        EnableForm(); HideRecurrPopup(); });

    /* datepicker */
    $('.datepicker').datetimepicker({
        <?php $datetimepicker_timepicker = false; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });
    $("#form_apptstatus").addClass('form-control-sm');
    $("#form_room").addClass('form-control-sm');
    $(".current a").addClass('active');
});

function are_days_checked(){
    var days = document.getElementById("days").getElementsByTagName('input');
    var counter = 0;
    for(var i=0; i < days.length; i++){
       if(days[i].checked){
           counter++;
       }
    }
    return counter;
}

/*
* validation on the form with new client side validation (using validate.js).
* this enable to add new rules for this form in the pageValidation list.
* */
var collectvalidation = <?php echo $collectthis; ?>;
function validateform(event,valu){
    $('#form_save').attr('disabled', true);
    //Make sure if days_every_week is checked that at least one weekday is checked.
    if($('#days_every_week').is(':checked') && !are_days_checked()){
        alert('<?php echo xls("Must choose at least one day!"); ?>');
        $('#form_save').attr('disabled', false);
        return false;
    }

    <?php if (!$GLOBALS['allow_early_check_in']) { ?>
        //Prevent from user to change status to Arrive before the time
        //Dependent in globals setting - allow_early_check_in
        if($('#form_apptstatus').val() == '@' && new Date(DateToYYYYMMDD_js($('#form_date').val())).getTime() > new Date().getTime()){
            alert('<?php echo xls("You can not change status to 'Arrive' before the appointment's time") . '.'; ?>');
            $('#form_save').attr('disabled', false);
            return false;
        }
    <?php } ?>

    //add rule if choose repeating event
    if ($('#form_repeat').is(':checked') || $('#days_every_week').is(':checked')){

        var format = '<?php echo DateFormatRead('validateJS'); ?>';

        collectvalidation.form_enddate = {
            datetime: {
                dateOnly: true,
                earliest: $('#form_date').val(),
                format: format,
                message: "An end date later than the start date is required for repeated events!"
            },
            presence: true
        }
    } else {
        if(typeof (collectvalidation) != 'undefined'){
            delete collectvalidation.form_enddate;
        }
    }


    <?php
    if ($GLOBALS['select_multi_providers']) {
        ?>
    //If multiple providers is enabled, create provider validation (Note: if no provider is chosen it causes bugs when deleting recurrent events).
    if(typeof (collectvalidation) == 'undefined'){
        collectvalidation = {form_provider:{presence: true}};
    }
    else{
        collectvalidation.form_provider = {presence: true};
    }
        <?php
    }
    ?>

    var submit = submitme(1, event, 'theform', collectvalidation);
    if(!submit)return $('#form_save').attr('disabled', false);

    $('#form_action').val(valu);

    <?php if ($eid && $repeats) : ?>
    // Only prompt for recurrence choice if the event is still configured as repeating
    if ($("#recurr_affect").val() == "" &&
        ($('#form_repeat').is(':checked') || $('#days_every_week').is(':checked'))) {
        DisableForm();
        $("#recurr_popup").css("display", "");
        $('#form_save').attr('disabled', false);
        return false;
    }
    <?php endif; ?>

    SubmitForm();
}

// disable form fields outside the recurr_popup so user must use popup buttons
function DisableForm() {
    // Disable visible interactive elements only — hidden inputs must stay enabled
    // so critical fields like form_action are always submitted with the form.
    $("#theform input:not([type=hidden]), #theform select, #theform textarea, #theform button")
        .not("#recurr_popup *").attr("disabled", "true");
}

function EnableForm() {
    $("#theform input, #theform select, #theform textarea, #theform button").removeAttr("disabled");
}

// hide the recurring popup DIV
function HideRecurrPopup() {
    $("#recurr_popup").css("display", "none");
}

function deleteEvent() {
    if (confirm("<?php echo addslashes(xl('Deleting this event cannot be undone. It cannot be recovered once it is gone. Are you sure you wish to delete this event?')); ?>")) {
        $('#form_action').val("delete");

        <?php if ($eid && $repeats) : ?>
        // Only prompt for recurrence choice if the event is still configured as repeating
        if ($("#recurr_affect").val() == "" &&
            ($('#form_repeat').is(':checked') || $('#days_every_week').is(':checked'))) {
            DisableForm();
            $("#recurr_popup").css("display", "");
            return false;
        }
        <?php endif; ?>

        return SubmitForm();
    }
    return false;
}

function SubmitForm() {
    var f = document.getElementById('theform');
    <?php
    $sdate = $date;
    $edate = new DateTime($date);
    $edate->modify('tomorrow');
    $edate = $edate->format('Y-m-d');
    $is_holiday = false;
    $holidays_controller = new Holidays_Controller();
    $holidays = $holidays_controller->get_holidays_by_date_range($sdate, $edate);
    if (in_array($sdate, $holidays)) {
        $is_holiday = true;
    }
    ?>
    <?php if ($is_holiday) { ?>
    if (f.form_action.value != 'delete') {
        if (!confirm('<?php echo xls('On this date there is a holiday, use it anyway?'); ?>')) {
            $('#form_save').attr('disabled', false);
            return false;
        }
    }
    <?php } ?>
    top.restoreSession();
    f.submit();
    return true;
}
</script>
</body>
</html>