<?php

/**
 *
 * Script to find open appointment slots
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Ian Jardine ( github.com/epsdky )
 * @author    Roberto Vasquez <robertogagliotta@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2005-2013 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2017-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Stephen Waite <stephen.waite@cmsvt.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
*/

require_once("../../globals.php");
require_once("$srcdir/patient.inc.php");
require_once(dirname(__FILE__) . "/../../../library/appointments.inc.php");
require_once($GLOBALS['incdir'] . "/main/holidays/Holidays_Controller.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

?>

<?php
 // check access controls
if (!AclMain::aclCheckCore('patients', 'appt', '', array('write','wsome'))) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Find Available Appointments")]);
    exit;
}

// If the caller is updating an existing event, then get its ID so
// we don't count it as a reserved time slot.
$eid = empty($_REQUEST['eid']) ? 0 : 0 + $_REQUEST['eid'];

$input_catid = $_REQUEST['catid'];

// Record an event into the slots array for a specified day.
function doOneDay($catid, $udate, $starttime, $duration, $prefcatid)
{
    global $slots, $slotsecs, $slotstime, $slotbase, $slotcount, $input_catid;
    $udate = strtotime($starttime, $udate);
    if ($udate < $slotstime) {
        return;
    }

    $i = (int) ($udate / $slotsecs) - $slotbase;
    $iend = (int) (($duration + $slotsecs - 1) / $slotsecs) + $i;
    if ($iend > $slotcount) {
        $iend = $slotcount;
    }

    if ($iend <= $i) {
        $iend = $i + 1;
    }

    for (; $i < $iend; ++$i) {
        if ($catid == 2) {        // in office
            // If a category ID was specified when this popup was invoked, then select
            // only IN events with a matching preferred category or with no preferred
            // category; other IN events are to be treated as OUT events.
            if ($input_catid) {
                if ($prefcatid == $input_catid || !$prefcatid) {
                    if ($slots[$i] ?? '') {
                        $slots[$i] |= 1;
                    } else {
                        $slots[$i] = 1;
                    }
                } else {
                    $slots[$i] |= 2;
                }
            } else {
                $slots[$i] |= 1;
            }

            break; // ignore any positive duration for IN
        } elseif ($catid == 3) { // out of office
            $slots[$i] |= 2;
            break; // ignore any positive duration for OUT
        } else { // all other events reserve time
            $slots[$i] |= 4;
        }
    }
}

// seconds per time slot
$slotsecs = $GLOBALS['calendar_interval'] * 60;


$catslots = 1;
if ($input_catid) {
    $srow = sqlQuery("SELECT pc_duration FROM openemr_postcalendar_categories WHERE pc_catid = ?", array($input_catid));
    if ($srow['pc_duration']) {
        $catslots = ceil($srow['pc_duration'] / $slotsecs);
    }
}

$info_msg = "";

$searchdays = 7; // default to a 1-week lookahead
if (!empty($_REQUEST['searchdays'])) {
    $searchdays = $_REQUEST['searchdays'];
}

// Get a start date.
$sdate = ($_REQUEST['startdate']) ? DateToYYYYMMDD($_REQUEST['startdate']) : date("Y-m-d");

// Get an end date - actually the date after the end date.
preg_match("/(\d\d\d\d)\D*(\d\d)\D*(\d\d)/", $sdate, $matches);
$edate = date(
    "Y-m-d",
    mktime(0, 0, 0, $matches[2], $matches[3] + $searchdays, $matches[1])
);

// compute starting time slot number and number of slots.
$slotstime = strtotime("$sdate 00:00:00");
$slotetime = strtotime("$edate 00:00:00");
$slotbase  = (int) ($slotstime / $slotsecs);
$slotcount = (int) ($slotetime / $slotsecs) - $slotbase;

if ($slotcount <= 0 || $slotcount > 100000) {
    die(xlt("Invalid date range"));
}

$slotsperday = (int) (60 * 60 * 24 / $slotsecs);

// Compute the number of time slots for the given event duration, or if
// none is given then assume the default category duration.
$evslots = $catslots;
if (isset($_REQUEST['evdur'])) {
    // bug fix #445 -- Craig Bezuidenhout 09 Aug 2016
    // if the event duration is less than or equal to zero, use the global calander interval
    // if the global calendar interval is less than or equal to zero, use 10 mins
    if (intval($_REQUEST['evdur']) <= 0) {
        if (intval($GLOBALS['calendar_interval']) <= 0) {
                $_REQUEST['evdur'] = 10;
        } else {
            $_REQUEST['evdur'] = intval($GLOBALS['calendar_interval']);
        }
    }

    $evslots = 60 * $_REQUEST['evdur'];
    $evslots = (int) (($evslots + $slotsecs - 1) / $slotsecs);
}

// If we have a provider, search.
//
if ($_REQUEST['providerid']) {
    $providerid = $_REQUEST['providerid'];

    // Create and initialize the slot array. Values are bit-mapped:
    //   bit 0 = in-office occurs here
    //   bit 1 = out-of-office occurs here
    //   bit 2 = reserved
    // So, values may range from 0 to 7.
    //
    $slots = array_pad(array(), $slotcount, 0);

    $sqlBindArray = array();

    // Note there is no need to sort the query results.
    $query = "SELECT pc_eventDate, pc_endDate, pc_startTime, pc_duration, " .
        "pc_recurrtype, pc_recurrspec, pc_alldayevent, pc_catid, pc_prefcatid " .
        "FROM openemr_postcalendar_events " .
        "WHERE pc_aid = ? AND " .
        "pc_eid != ? AND " .
        "((pc_endDate >= ? AND pc_eventDate < ? ) OR " .
        "(pc_endDate = '0000-00-00' AND pc_eventDate >= ? AND pc_eventDate < ?))";

        array_push($sqlBindArray, $providerid, $eid, $sdate, $edate, $sdate, $edate);

    // phyaura whimmel facility filtering
    if ($_REQUEST['facility'] ?? '' > 0) {
            $facility = $_REQUEST['facility'];
            $query .= " AND pc_facility = ?";
            array_push($sqlBindArray, $facility);
    }

    // end facility filtering whimmel 29apr08

    //////
    $events2 = fetchEvents($sdate, $edate, null, null, false, 0, $sqlBindArray, $query);
    foreach ($events2 as $row) {
            $thistime = strtotime($row['pc_eventDate'] . " 00:00:00");
            doOneDay(
                $row['pc_catid'],
                $thistime,
                $row['pc_startTime'],
                $row['pc_duration'],
                $row['pc_prefcatid']
            );
    }

    //////

    // Mark all slots reserved where the provider is not in-office.
    // Actually we could do this in the display loop instead.
    $inoffice = false;
    for ($i = 0; $i < $slotcount; ++$i) {
        if (($i % $slotsperday) == 0) {
            $inoffice = false;
        }

        if ($slots[$i] & 1) {
            $inoffice = true;
        }

        if ($slots[$i] & 2) {
            $inoffice = false;
        }

        if (! $inoffice) {
            $slots[$i] |= 4;
            $prov[$i] = $i;
        }
    }
}

$ckavail = true;
// If the requested date is a holiday/closed date we need to alert the user about it and let him choose if he wants to proceed
//////
$is_holiday = false;
$holidays_controller = new Holidays_Controller();
$holidays = $holidays_controller->get_holidays_by_date_range($sdate, $edate);
if (in_array($sdate, $holidays)) {
    $is_holiday = true;
    $ckavail = true;
}

//////
?>

<!DOCTYPE html>
<html>
<head>

    <?php Header::setupHeader(['common', 'datetime-picker', 'opener']); ?>
    <title><?php echo xlt('Find Available Appointments'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">

<?php

// The cktime parameter is a number of minutes into the starting day of a
// tentative appointment that is to be checked.  If it is present then we are
// being asked to check if this indicated slot is available, and to submit
// the opener and go away quietly if it is.  If it's not then we have more
// work to do.

if (isset($_REQUEST['cktime'])) {
    $cktime = 0 + $_REQUEST['cktime'];
    $ckindex = (int) ($cktime * 60 / $slotsecs);
    for ($j = $ckindex; $j < $ckindex + $evslots; ++$j) {
        if ($slots[$j] >= 4) {
            $ckavail = false;
            $isProv = false;
            if (isset($prov[$j])) {
                $isProv = 'TRUE';
            }
        }
    }

    if ($ckavail) {
        // Slot is free — styled save card with fixed submit chain
        echo "<!DOCTYPE html>\n<html>\n<head>\n";
        echo "<link rel='preconnect' href='https://fonts.googleapis.com'>";
        echo "<link href='https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap' rel='stylesheet'>";
        echo "<style>
            :root{--mdi-navy:#1e0a3c;--mdi-navy-pale:#f5f2fc;--mdi-teal:#0d9488;}
            *{font-family:'Montserrat',sans-serif;box-sizing:border-box;}
            body{margin:0;padding:0;background:var(--mdi-navy-pale);display:flex;align-items:center;justify-content:center;height:100vh;}
            .save-card{background:#fff;border-radius:14px;padding:2rem 2.5rem;text-align:center;
                box-shadow:0 8px 32px rgba(30,10,60,.12);max-width:340px;width:90%;
                animation:fadeUp .35s ease both;}
            @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
            .save-icon{width:52px;height:52px;border-radius:50%;background:rgba(13,148,136,.12);
                display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;}
            .save-icon svg{color:var(--mdi-teal);}
            h4{font-size:1rem;font-weight:700;color:var(--mdi-navy);margin:0 0 .4rem;}
            p{font-size:.75rem;color:#64748b;margin:0;}
            .save-bar{height:3px;background:var(--mdi-navy-pale);border-radius:2px;margin-top:1.25rem;overflow:hidden;}
            .save-bar-fill{height:100%;background:var(--mdi-teal);border-radius:2px;width:0;animation:fill 2.3s linear forwards;}
            @keyframes fill{to{width:100%}}
        </style>\n";
        echo "<script>\n";
        echo "function mytimeout() {\n";
        echo "  var win = opener || window.dialogOpener || null;\n";
        echo "  if (!win) { window.close(); return; }\n";
        echo "  win.top.restoreSession();\n";
        echo "  win.document.getElementById('form_action').value = 'save';\n";
        echo "  win.document.getElementById('theform').submit();\n";
        echo "  window.close();\n";
        echo "}\n";
        echo "</script>\n";
        echo "</head>\n";
        echo "<body onload='setTimeout(\"mytimeout()\",2500);'>\n";
        echo "<div class='save-card'>\n";
        echo "  <div class='save-icon'><svg width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5'><polyline points='20 6 9 17 4 12'/></svg></div>\n";
        echo "  <h4>" . xlt('Time slot is available') . "</h4>\n";
        echo "  <p>" . xlt('Saving appointment') . "...</p>\n";
        echo "  <div class='save-bar'><div class='save-bar-fill'></div></div>\n";
        echo "</div>\n";
        echo "</body></html>\n";
        exit();
    }

    // The appointment slot is not available.  A message will be displayed
    // after this page is loaded.
}
?>

    <script>
        function setappt(year,mon,mday,hours,minutes) {
            if (opener.closed || !opener.setappt) {
                alert(<?php echo xlj('The destination form was closed; I cannot act on your selection.'); ?>);
                dlgclose();
                return false;
            }
            opener.setappt(year, mon, mday, hours, minutes);
            var cktime = hours * 60 + minutes;
            opener.find_available('&cktime=' + cktime);
            return false;
        }
    </script>

    <style>
        body { background: #f0f2f8; font-family: 'Montserrat', sans-serif; }
        form { padding: 0; margin: 0; }

        #searchCriteria {
            background: #1e0a3c;
            padding: 12px 16px;
        }
        #searchCriteria label,
        #searchCriteria span { color: #c4b5fd; font-size: 13px; font-weight: 500; }
        #searchCriteria .form-control {
            border-radius: 8px !important;
            border: 1.5px solid #4c1d95 !important;
            background: #2e2a5e !important;
            color: #fff !important;
            font-size: 13px;
        }
        #searchCriteria .btn-search,
        #searchCriteria .btn-primary {
            background: #7c3aed !important;
            border: none !important;
            border-radius: 8px !important;
            font-weight: 600;
            padding: 6px 20px;
            color: #fff;
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
            background: #1e0a3c;
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
    </style>
</head>

<body class="body_top">
<div class="container-fluid">
    <div id="searchCriteria">
        <form class="form-inline" method='post' name='theform' target='_self' action='find_appt_popup.php?providerid=<?php echo attr_url($providerid) ?>&catid=<?php echo attr_url($input_catid) ?>'>
            <?php echo xlt('Start date:'); ?>
        <input type='text' class='datepicker input-sm form-control' name='startdate' id='startdate' size='10' value='<?php echo attr(oeFormatShortDate($sdate)); ?>' title='<?php echo xla('Starting date for search'); ?> '/>
            <?php echo xlt('for'); ?>
        <input type='text' class="input-sm form-control" name='searchdays' size='3' value='<?php echo attr($searchdays) ?>' title='<?php echo xla('Number of days to search from the start date'); ?>' />
            <?php echo xlt('days'); ?>&nbsp;
        <button type='submit' class='btn btn-primary btn-search'><?php echo xla('Search'); ?></button>
        </form>
    </div>
<div id="searchResults">
<?php if (!empty($slots)):
    // Build day-cards array
    $day_cards = [];
    $lastdate  = '';
    for ($i = 0; $i < $slotcount; ++$i) {
        $available = true;
        for ($j = $i; $j < $i + $evslots; ++$j) {
            if ($slots[$j] >= 4) { $available = false; break; }
        }
        if (!$available) continue;

        $utime    = ($slotbase + $i) * $slotsecs;
        $thisdate = date('Y-m-d', $utime);
        $ampm     = date('a', $utime);
        $adate    = getdate($utime);
        $hour_fmt = ($GLOBALS['time_display_format'] == 0) ? 'g' : 'G';
        $display  = date($hour_fmt . ':i', $utime) . ' ' . $ampm;
        $atitle   = 'Choose ' . date('h:i a', $utime);

        if (!isset($day_cards[$thisdate])) {
            $day_cards[$thisdate]['label'] = xlt(date('l', $utime)) . ', ' . text(oeFormatSDFT($utime));
            $day_cards[$thisdate]['slots'] = [];
        }
        $day_cards[$thisdate]['slots'][] = [
            'ampm'    => $ampm,
            'display' => $display,
            'atitle'  => $atitle,
            'year'    => $adate['year'],
            'mon'     => $adate['mon'],
            'mday'    => $adate['mday'],
            'hours'   => $adate['hours'],
            'minutes' => $adate['minutes'],
        ];
        $i += $evslots - 1;
    }

    if (!empty($day_cards)):
        foreach ($day_cards as $date => $card):
            $slot_count = count($card['slots']);
            $last_ampm  = null;
?>
    <div class="day-card">
        <div class="day-card-header">
            <span><?php echo $card['label']; ?></span>
            <span class="slot-count"><?php echo $slot_count; ?></span>
        </div>
        <div class="slots-grid">
            <?php foreach ($card['slots'] as $slot):
                if ($last_ampm !== $slot['ampm']): ?>
                    <div class="ampm-label"><?php echo strtoupper($slot['ampm']); ?></div>
                <?php $last_ampm = $slot['ampm']; endif; ?>
                <a href="#"
                   class="slot-btn <?php echo $slot['ampm'] === 'pm' ? 'pm' : ''; ?>"
                   onclick="return setappt(
                       <?php echo attr_js($slot['year']); ?>,
                       <?php echo attr_js($slot['mon']); ?>,
                       <?php echo attr_js($slot['mday']); ?>,
                       <?php echo attr_js($slot['hours']); ?>,
                       <?php echo attr_js($slot['minutes']); ?>
                   );"
                   title="<?php echo attr($slot['atitle']); ?>">
                    <?php echo text($slot['display']); ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php
        endforeach;
    else:
?>
    <div class="no-slots"><?php echo xlt('No openings were found for this period.'); ?></div>
<?php
    endif;
endif;
?>
</div>

<script>
// jQuery stuff to make the page a little easier to use
$(function () {
    $(".oneresult").mouseover(function() { $(this).toggleClass("highlight"); });
    $(".oneresult").mouseout(function() { $(this).toggleClass("highlight"); });
    $(".oneresult a").mouseover(function () { $(this).toggleClass("blue_highlight"); $(this).children().toggleClass("blue_highlight"); });
    $(".oneresult a").mouseout(function() { $(this).toggleClass("blue_highlight"); $(this).children().toggleClass("blue_highlight"); });

    $('.datepicker').datetimepicker({
        <?php $datetimepicker_timepicker = false; ?>
        <?php $datetimepicker_showseconds = false; ?>
        <?php $datetimepicker_formatInput = true; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
        <?php // can add any additional javascript settings to datetimepicker here; need to prepend first setting with a comma ?>
    });
});


<?php
if (!$ckavail) {
    if (AclMain::aclCheckCore('patients', 'appt', '', 'write')) {
        if ($is_holiday) { ?>
            if (confirm(<?php echo xlj('On this date there is a holiday, use it anyway?'); ?>)) {
                var win = opener || window.dialogOpener || null;
                if (win) {
                    win.top.restoreSession();
                    win.document.getElementById('form_action').value = 'save';
                    win.document.getElementById('theform').submit();
                }
                dlgclose();
            } <?php
        } else {
            //Someone is going to have to go over this with a fine-toothed comb because I couldn't really parse the original here
            if ($isProv) { ?>
                if (confirm(<?php echo xlj('Provider not available, use it anyway?'); ?>)) {
                <?php
            } else { ?>
                if (confirm(<?php echo xlj('This appointment slot is already used, use it anyway?'); ?>)) {
                <?php
            } ?>
            var win = opener || window.dialogOpener || null;
            if (win) {
                win.top.restoreSession();
                win.document.getElementById('form_action').value = 'save';
                win.document.getElementById('theform').submit();
            }
            dlgclose();
        }
            <?php
        }
    } else {
        if ($is_holiday) { ?>
            alert(<?php echo xlj('On this date there is a holiday, use it anyway?'); ?>);
            <?php
        } else {
            if ($isProv) { ?>
                alert(<?php echo xlj('Provider not available, please choose another.'); ?>);
                <?php
            } else { ?>
                alert(<?php echo xlj('This appointment slot is already used, please choose another.'); ?>);
                <?php
            }
        } //close if is holiday
    }
} ?>

</script>
</div>
</body>
</html>
