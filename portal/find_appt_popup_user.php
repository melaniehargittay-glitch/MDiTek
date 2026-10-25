<?php

/**
 *
 * Modified from main codebase for the patient portal.
 * Rewritten to derive available slots directly from "In Office" calendar events.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ian Jardine ( github.com/epsdky )
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__FILE__) . "/../src/Common/Session/SessionUtil.php");
OpenEMR\Common\Session\SessionUtil::portalSessionStart();

$landingpage = "index.php?site=" . urlencode($_SESSION['site_id']);

if (isset($_SESSION['pid']) && isset($_SESSION['patient_portal_onsite_two'])) {
    $pid = $_SESSION['pid'];
} else {
    OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
    header('Location: ' . $landingpage . '&w');
    exit();
}

$ignoreAuth_onsite_portal = true;

require_once("../interface/globals.php");
require_once("$srcdir/patient.inc.php");
require_once(dirname(__FILE__) . "/../library/appointments.inc.php");
// calendar.inc.php contains InsertEvent() — required for direct appointment insert
if (file_exists($GLOBALS['srcdir'] . '/calendar.inc.php')) {
    require_once($GLOBALS['srcdir'] . '/calendar.inc.php');
} else {
    require_once(dirname(__FILE__) . '/../interface/main/calendar/../../library/calendar.inc.php');
}

// ── Resolve provider (must happen before save handler) ───────────────────────
$providerid = (int)($_REQUEST['providerid'] ?? 0);
if (!$providerid) {
    $patrow = sqlQuery("SELECT providerid FROM patient_data WHERE pid = ?", [$pid]);
    $providerid = (int)($patrow['providerid'] ?? 0);
}
// Also try ref_providerid as fallback
if (!$providerid) {
    $patrow2 = sqlQuery("SELECT ref_providerid FROM patient_data WHERE pid = ?", [$pid]);
    $providerid = (int)($patrow2['ref_providerid'] ?? 0);
}

// ── HTML response helpers ────────────────────────────────────────────────────
function apptSuccessPage($display_date, $catname) {
    return '<!DOCTYPE html><html><head>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
<style>*{font-family:Montserrat,sans-serif;box-sizing:border-box;}
body{margin:0;padding:20px;background:#f0f2f8;}
.card{background:#f0fdf4;border:1.5px solid #a7f3d0;border-radius:12px;padding:28px;text-align:center;max-width:480px;margin:40px auto;}
.icon{margin-bottom:12px;}
h3{color:#065f46;font-size:16px;margin:0 0 8px;}
p{color:#047857;font-size:13px;margin:4px 0;}
.note{color:#6b7280;font-size:12px;margin-top:10px;}
</style></head><body>
<div class="card">
<div class="icon"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div>
<h3>Appointment Requested!</h3>
<p>' . htmlspecialchars($display_date) . '</p>
<p>' . htmlspecialchars($catname) . '</p>
<p class="note">Your request is pending confirmation. We will notify you once it is approved.</p>
</div></body></html>';
}

function apptErrorPage($msg) {
    return '<!DOCTYPE html><html><head>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">
<style>*{font-family:Montserrat,sans-serif;box-sizing:border-box;}
body{margin:0;padding:20px;background:#f0f2f8;}
.card{background:#fef2f2;border:1.5px solid #fca5a5;border-radius:12px;padding:28px;text-align:center;max-width:480px;margin:40px auto;}
h3{color:#991b1b;font-size:15px;margin:0 0 8px;}
p{color:#b91c1c;font-size:13px;margin:0;}
a{display:inline-block;margin-top:16px;color:#7c3aed;font-size:13px;}
</style></head><body>
<div class="card">
<h3>Could not save appointment</h3>
<p>' . htmlspecialchars($msg) . '</p>
<a href="javascript:history.back()">&#8592; Go back and try again</a>
</div></body></html>';
}

// ── Handle appointment save ──────────────────────────────────────────────────
if (!empty($_POST['save_slot'])) {
    try {
        $slot_date  = $_POST['slot_date']  ?? '';
        $slot_hour  = (int)($_POST['slot_hour']  ?? 0);
        $slot_min   = (int)($_POST['slot_min']   ?? 0);
        $slot_catid = (int)($_POST['slot_catid'] ?? 5);
        $interval   = (int)($GLOBALS['calendar_interval'] ?? 15);

        if (!$slot_date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $slot_date)) {
            echo apptErrorPage('Invalid date');
            exit;
        }
        if (!$providerid) {
            echo apptErrorPage('No provider assigned. Please contact the office.');
            exit;
        }

        // ── Patient info ──────────────────────────────────────────────────────
        $patrow  = sqlQuery("SELECT fname, lname FROM patient_data WHERE pid = ?", [$pid]);
        $patname = trim(($patrow['fname'] ?? '') . ' ' . ($patrow['lname'] ?? ''));

        // ── Category ──────────────────────────────────────────────────────────
        $catrow   = sqlQuery(
            "SELECT pc_catname, pc_duration FROM openemr_postcalendar_categories WHERE pc_catid = ?",
            [$slot_catid]
        );
        $duration = (!empty($catrow['pc_duration'])) ? (int)$catrow['pc_duration'] : $interval;
        $catname  = $catrow['pc_catname'] ?? 'Office Visit';

        // ── Facility ──────────────────────────────────────────────────────────
        $facrow   = sqlQuery("SELECT facility_id FROM users WHERE id = ?", [$providerid]);
        $facility = (int)($facrow['facility_id'] ?? ($GLOBALS['facility_id'] ?? 0));

        // ── Compute times exactly as add_edit_event.php does ─────────────────
        $tmph      = $slot_hour;
        $tmpm      = $slot_min;
        $starttime = sprintf('%d:%02d:00', $tmph, $tmpm);
        $tmpm     += $duration;
        while ($tmpm >= 60) { $tmpm -= 60; ++$tmph; }
        $endtime   = sprintf('%d:%02d:00', $tmph, $tmpm);
        $event_date = $slot_date;

        // ── recurrspec / locationspec ─────────────────────────────────────────
        $recurrspec = serialize([
            'event_repeat_freq'      => '0',
            'event_repeat_freq_type' => '0',
            'event_repeat_on_num'    => '1',
            'event_repeat_on_day'    => '0',
            'event_repeat_on_freq'   => '0',
            'exdate'                 => '',
        ]);
        $locationspec = serialize([
            'event_location' => '',
            'event_street1'  => '',
            'event_street2'  => '',
            'event_city'     => '',
            'event_state'    => '',
            'event_postal'   => '',
        ]);

        // ── Populate $_POST exactly as add_edit_event.php expects ─────────────
        // InsertEventFull() reads $_POST directly
        $_POST['form_category']      = $slot_catid;
        $_POST['form_pid']           = $pid;
        $_POST['form_provider']      = $providerid;
        $_POST['form_title']         = $patname;
        $_POST['form_apptstatus']    = '^';
        $_POST['form_comments']      = xl('Appointment requested via patient portal');
        $_POST['form_date']          = $slot_date;
        $_POST['form_enddate']       = '0000-00-00';
        $_POST['form_hour']          = $slot_hour;
        $_POST['form_minute']        = $slot_min;
        $_POST['form_ampm']          = ($slot_hour >= 12) ? '2' : '1';
        $_POST['form_duration']      = $duration;
        $_POST['form_allday']        = '0';
        $_POST['form_repeat']        = '0';
        $_POST['form_repeat_freq']   = '0';
        $_POST['form_repeat_type']   = '0';
        $_POST['form_repeat_exdate'] = '';
        $_POST['form_prefcat']       = '';
        $_POST['form_room']          = '';
        $_POST['facility']           = $facility;
        $_POST['billing_facility']   = $facility;
        $_POST['days_every_week']    = '0';

        // ── Set globals InsertEventFull reads ─────────────────────────────────
        $new_multiple_value = '';
        $provider           = $providerid;
        $duration_global    = $duration;

        // ── Build args array and call InsertEvent directly ────────────────────
        $args = $_POST;
        $args['new_multiple_value'] = '';
        $args['event_date']         = $event_date;
        $args['duration']           = $duration * 60;
        $args['recurrspec']         = $recurrspec;
        $args['starttime']          = $starttime;
        $args['endtime']            = $endtime;
        $args['locationspec']       = $locationspec;

        $pc_eid = InsertEvent($args);

        if (!$pc_eid) {
            echo apptErrorPage('Could not save appointment (InsertEvent failed). Please try again or contact the office.');
            exit;
        }

        // Send portal notification to provider via pnotes table
        $appt_display = date('l, F j, Y', strtotime($slot_date)) . ' at ' .
                        date('g:i a', strtotime($slot_date . ' ' . $starttime));

        // Get provider username for assigned_to field
        $provrow       = sqlQuery("SELECT username, fname, lname FROM users WHERE id = ?", [$providerid]);
        $prov_username = $provrow['username'] ?? '';
        $prov_name     = trim(($provrow['fname'] ?? '') . ' ' . ($provrow['lname'] ?? ''));

        $msg_subject = xl('New Appointment Request');
        $msg_body    = '<p><strong>' . xl('Patient') . ':</strong> ' . text($patname) . '</p>' .
                       '<p><strong>' . xl('Visit Type') . ':</strong> ' . text($catname) . '</p>' .
                       '<p><strong>' . xl('Requested Time') . ':</strong> ' . text($appt_display) . '</p>' .
                       '<p>' . xl('This appointment is pending your approval in the portal dashboard.') . '</p>';

        // sender_id for portal patient = pid (matches IS_PORTAL in messages.php)
        $sender_id   = (string)$pid;
        $sender_name = $patname;

        // Insert into pnotes — provider's patient note inbox
        sqlStatement(
            "INSERT INTO pnotes (pid, user, groupname, date, title, body, activity, authorized, assigned_to, message_status, portal_relation) VALUES (?, ?, ?, NOW(), ?, ?, 1, 1, ?, 'New', 'portal')",
            [
                $pid,
                $sender_name,           // user = patient name (who sent it)
                'Default',              // groupname
                $msg_subject,           // title
                $msg_body,              // body
                $prov_username,         // assigned_to = provider username
            ]
        );
        echo apptSuccessPage($appt_display, $catname);

    } catch (Exception $e) {
        echo apptErrorPage($e->getMessage());
    }
    exit;
}
// ── End save handler ──────────────────────────────────────────────────────────

use OpenEMR\Core\Header;

// ── Date range ────────────────────────────────────────────────────────────────
$searchdays = max(1, (int)($_REQUEST['searchdays'] ?? 7));

if (!empty($_REQUEST['startdate']) &&
    preg_match('/(\d{4})\D*(\d{2})\D*(\d{2})/', $_REQUEST['startdate'], $m)) {
    $sdate = $m[1] . '-' . $m[2] . '-' . $m[3];
} else {
    $sdate = date('Y-m-d');
}

$edate = date('Y-m-d', strtotime($sdate . " +{$searchdays} days"));

// ── Build available slots from "In Office" events ─────────────────────────────
// Query all "In Office" events for this provider in the date range,
// including recurring events expanded by fetchEvents.
// Then subtract any slots already booked with real patient appointments.

$available_slots = []; // keyed by 'Y-m-d', value = array of ['time'=>'H:i', 'display'=>'g:i a', 'ts'=>timestamp]

if ($providerid) {
    $interval_mins = max(1, (int)($GLOBALS['calendar_interval'] ?? 15));

    // ── Step 1: Find all "In Office" blocks for this provider ─────────────────
    // fetchEvents expands recurring events automatically
    $in_office_query =
        "SELECT pc_eventDate, pc_endDate, pc_startTime, pc_duration,
                pc_recurrtype, pc_recurrspec, pc_alldayevent, pc_catid, pc_prefcatid, pc_title
         FROM openemr_postcalendar_events
         WHERE pc_aid = ?
           AND pc_title = 'In Office'
           AND ((pc_endDate >= ? AND pc_eventDate <= ?)
             OR (pc_endDate = '0000-00-00' AND pc_eventDate >= ? AND pc_eventDate <= ?))";

    $in_office_bind = [$providerid, $sdate, $edate, $sdate, $edate];
    $in_office_events = fetchEvents($sdate, $edate, null, null, false, 0, $in_office_bind, $in_office_query);

    // Build a map of date => [ [start_ts, end_ts], ... ] office blocks
    $office_blocks = []; // 'Y-m-d' => array of [start_minute_of_day, end_minute_of_day]
    foreach ($in_office_events as $ev) {
        $evdate = $ev['pc_eventDate'];
        if ($evdate < $sdate || $evdate > $edate) continue;
        $start_mins = 0;
        if (preg_match('/(\d+):(\d+)/', $ev['pc_startTime'], $tm)) {
            $start_mins = (int)$tm[1] * 60 + (int)$tm[2];
        }
        $duration_mins = max($interval_mins, (int)$ev['pc_duration']);
        $end_mins = $start_mins + $duration_mins;
        $office_blocks[$evdate][] = [$start_mins, $end_mins];
    }

    // ── Step 2: Find already-booked appointments for this provider ────────────
    $booked_res = sqlStatement(
        "SELECT pc_eventDate, pc_startTime, pc_duration
         FROM openemr_postcalendar_events
         WHERE pc_aid = ?
           AND pc_title != 'In Office'
           AND pc_title != 'Out Of Office'
           AND pc_catid NOT IN (2, 3)
           AND pc_eventDate >= ? AND pc_eventDate <= ?",
        [$providerid, $sdate, $edate]
    );
    $booked = []; // 'Y-m-d' => array of [start_minute, end_minute]
    while ($brow = sqlFetchArray($booked_res)) {
        $bdate = $brow['pc_eventDate'];
        $bmins = 0;
        if (preg_match('/(\d+):(\d+)/', $brow['pc_startTime'], $bm)) {
            $bmins = (int)$bm[1] * 60 + (int)$bm[2];
        }
        $bdur  = max($interval_mins, (int)$brow['pc_duration']);
        $booked[$bdate][] = [$bmins, $bmins + $bdur];
    }

    // ── Step 3: For each office block, generate interval slots, filter booked ──
    // Walk every day in the range
    $day = $sdate;
    while ($day <= $edate) {
        if (isset($office_blocks[$day])) {
            foreach ($office_blocks[$day] as [$blk_start, $blk_end]) {
                $slot = $blk_start;
                while ($slot + $interval_mins <= $blk_end) {
                    $slot_end = $slot + $interval_mins;
                    // Check not blocked by a real appointment
                    $blocked = false;
                    if (isset($booked[$day])) {
                        foreach ($booked[$day] as [$bs, $be]) {
                            // overlap: slot starts before booking ends AND slot ends after booking starts
                            if ($slot < $be && $slot_end > $bs) {
                                $blocked = true;
                                break;
                            }
                        }
                    }
                    if (!$blocked) {
                        $h = intdiv($slot, 60);
                        $m = $slot % 60;
                        $ts = strtotime($day . sprintf(' %02d:%02d:00', $h, $m));
                        $available_slots[$day][] = [
                            'time'    => sprintf('%02d:%02d', $h, $m),
                            'display' => date('g:i a', $ts),
                            'ampm'    => date('a', $ts),
                            'hour'    => $h,
                            'minute'  => $m,
                            'ts'      => $ts,
                            'date'    => $day,
                        ];
                    }
                    $slot += $interval_mins;
                }
            }
        }
        $day = date('Y-m-d', strtotime($day . ' +1 day'));
    }
    ksort($available_slots);
}

$did_search = isset($_REQUEST['bypatient']) || !empty($_REQUEST['startdate']) || !empty($_REQUEST['searchdays']);
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Find Available Appointments'); ?></title>
    <?php Header::setupHeader(['no_main-theme', 'datetime-picker', 'opener']); ?>
    <link rel="stylesheet" href="<?php echo $GLOBALS['web_root']; ?>/portal/portal-shared.css">
    <script>
        if (typeof top !== 'undefined' && typeof top.get_opener !== 'function') {
            try { top.get_opener = function() { return null; }; } catch(e) {}
        }

        function setappt(year, mon, mday, hours, minutes) {
            var slotDate = year + '-' +
                           String(mon).padStart(2,'0') + '-' +
                           String(mday).padStart(2,'0');
            var params   = new URLSearchParams(window.location.search);
            var catid    = params.get('catid') || '5';
            var provid   = params.get('providerid') || '0';

            // Show saving indicator immediately
            document.getElementById('searchResults').innerHTML =
                '<div style="text-align:center;padding:40px 20px;">' +
                '<svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" style="animation:spin 1s linear infinite">' +
                '<path d="M21 12a9 9 0 11-6-8.5"/></svg>' +
                '<p style="margin-top:12px;color:#7c3aed;font-weight:600;font-size:14px;">Saving appointment...</p>' +
                '</div><style>@keyframes spin{to{transform:rotate(360deg)}}</style>';

            // Build and submit a hidden form — avoids fetch being intercepted by extensions
            var f = document.createElement('form');
            f.method = 'POST';
            f.action = window.location.pathname + window.location.search;
            f.style.display = 'none';

            var fields = {
                save_slot:  '1',
                slot_date:  slotDate,
                slot_hour:  hours,
                slot_min:   minutes,
                slot_catid: catid,
                providerid: provid,
                bypatient:  '1'
            };
            for (var k in fields) {
                var i = document.createElement('input');
                i.type = 'hidden'; i.name = k; i.value = fields[k];
                f.appendChild(i);
            }
            document.body.appendChild(f);
            f.submit();
            return false;
        }

        function dlgclose() {
            try { parent.postMessage({type:'apptSelected'},'*'); } catch(e) {}
            try { window.close(); } catch(e) {}
        }
    </script>
    <style>
        form { padding: 0; margin: 0; }
        body { background: #f0f2f8; font-family: 'Plus Jakarta Sans', sans-serif; }

        #searchCriteria {
            background: #1e1b4b;
            padding: 12px 16px;
            border-radius: 0;
        }
        #searchCriteria label { color: #c4b5fd; font-size: 13px; font-weight: 500; }
        #searchCriteria .form-control {
            border-radius: 8px !important;
            border: 1.5px solid #4c1d95 !important;
            background: #2e2a5e !important;
            color: #fff !important;
            font-size: 13px;
        }
        #searchCriteria .btn-primary {
            background: #7c3aed !important;
            border: none !important;
            border-radius: 8px !important;
            font-weight: 600;
            padding: 6px 20px;
        }

        #searchResults { padding: 12px; }

        .day-card {
            background: #fff;
            border-radius: 12px;
            border: 1.5px solid #e2e5f0;
            margin-bottom: 12px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(124,58,237,.06);
        }
        .day-card-header {
            background: #1e1b4b;
            color: #fff;
            padding: 8px 14px;
            font-weight: 600;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .day-card-header .slot-count {
            background: #7c3aed;
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 11px;
            font-weight: 700;
        }
        .slots-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            padding: 10px 12px;
        }
        .slot-btn {
            background: #f5f3ff;
            color: #5b21b6;
            border: 1.5px solid #ede9fe;
            border-radius: 8px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none !important;
            transition: all .15s;
            display: inline-block;
        }
        .slot-btn:hover {
            background: #7c3aed;
            color: #fff !important;
            border-color: #7c3aed;
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(124,58,237,.25);
        }
        .slot-btn.pm { color: #047857; border-color: #a7f3d0; background: #f0fdf4; }
        .slot-btn.pm:hover { background: #047857; color: #fff !important; border-color: #047857; }

        .ampm-label {
            width: 100%;
            font-size: 10px;
            font-weight: 700;
            color: #9ca3af;
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-bottom: 2px;
            margin-top: 4px;
        }
        .no-slots {
            background: #fff;
            border-radius: 12px;
            border: 1.5px solid #e2e5f0;
            padding: 24px;
            text-align: center;
            color: #6b7280;
            font-size: 14px;
        }
        .no-provider {
            background: #fef3c7;
            border: 1.5px solid #fbbf24;
            border-radius: 12px;
            padding: 16px;
            color: #92400e;
            font-size: 13px;
            margin: 12px;
        }
    </style>
</head>

<body class="body_top">

<div id="searchCriteria">
    <form method="post" name="theform"
          action="./find_appt_popup_user.php?providerid=<?php echo attr_url($providerid); ?>&catid=<?php echo attr_url($_REQUEST['catid'] ?? ''); ?>">
        <input type="hidden" name="bypatient" value="1" />
        <div class="form-row mx-0 align-items-center" style="gap:8px;">
            <label for="startdate" class="col-auto col-form-label"><?php echo xlt('Start date:'); ?></label>
            <div class="col-auto">
                <input type="text" class="datepicker form-control" name="startdate" id="startdate"
                       size="10" value="<?php echo attr($sdate); ?>"
                       title="yyyy-mm-dd starting date for search" />
            </div>
            <label for="searchdays" class="col-auto col-form-label"><?php echo xlt('for'); ?></label>
            <div class="col-auto">
                <input type="text" class="form-control" name="searchdays" id="searchdays"
                       size="3" value="<?php echo attr($searchdays); ?>"
                       title="Number of days to search" />
            </div>
            <label class="col-auto col-form-label"><?php echo xlt('days'); ?></label>
            <div class="col-auto">
                <input type="submit" class="btn btn-primary btn-sm"
                       value="<?php echo xla('Search'); ?>" />
            </div>
        </div>
    </form>
</div>

<div id="searchResults">
<?php if (!$providerid): ?>
    <div class="no-provider">
        <?php echo xlt('No provider is assigned to your account. Please contact the office.'); ?>
    </div>
<?php elseif ($did_search): ?>
    <?php if (empty($available_slots)): ?>
        <div class="no-slots">
            <?php echo xlt('No openings were found for this period.'); ?>
        </div>
    <?php else: ?>
        <?php foreach ($available_slots as $day_date => $slots): ?>
            <?php
            $day_label = date('l', strtotime($day_date));
            $date_label = date('F j, Y', strtotime($day_date));
            $slot_count = count($slots);
            $last_ampm  = '';
            ?>
            <div class="day-card">
                <div class="day-card-header">
                    <span><?php echo text($day_label . ', ' . $date_label); ?></span>
                    <span class="slot-count"><?php echo $slot_count; ?> <?php echo xlt('open'); ?></span>
                </div>
                <div class="slots-grid">
                    <?php foreach ($slots as $slot): ?>
                        <?php if ($slot['ampm'] !== $last_ampm): ?>
                            <div class="ampm-label"><?php echo strtoupper($slot['ampm']); ?></div>
                            <?php $last_ampm = $slot['ampm']; ?>
                        <?php endif; ?>
                        <a href="#"
                           class="slot-btn <?php echo $slot['ampm'] === 'pm' ? 'pm' : ''; ?>"
                           onclick="return setappt(
                               <?php echo attr_js(date('Y', $slot['ts'])); ?>,
                               <?php echo attr_js((int)date('n', $slot['ts'])); ?>,
                               <?php echo attr_js((int)date('j', $slot['ts'])); ?>,
                               <?php echo attr_js($slot['hour']); ?>,
                               <?php echo attr_js($slot['minute']); ?>
                           );"
                           title="<?php echo attr('Schedule ' . $slot['display']); ?>">
                            <?php echo text($slot['display']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
<?php else: ?>
    <div class="no-slots" style="color:#7c3aed; background:#f5f3ff; border-color:#ede9fe;">
        <?php echo xlt('Select a start date and click Search to see available times.'); ?>
    </div>
<?php endif; ?>
</div>

<script>
    $(function () {
        $('.datepicker').datetimepicker({
            <?php $datetimepicker_timepicker = false; ?>
            <?php $datetimepicker_formatInput = false; ?>
            <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
        });
    });
</script>
</body>
</html>
