<?php

/**
 * Patient Portal Home — MDITek Redesign
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Shiqiang Tao <StrongTSQ@gmail.com>
 * @author    Ben Marte <benmarte@gmail.com>
 * @copyright Copyright (c) 2016-2022 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019-2021 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2020 Shiqiang Tao <StrongTSQ@gmail.com>
 * @copyright Copyright (c) 2021 Ben Marte <benmarte@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once('verify_session.php');
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once('lib/portal_mail.inc.php');
require_once(__DIR__ . '/../library/appointments.inc.php');

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Events\PatientPortal\AppointmentFilterEvent;
use OpenEMR\Services\LogoService;

if (isset($_SESSION['register']) && $_SESSION['register'] === true) {
    require_once(__DIR__ . '/../src/Common/Session/SessionUtil.php');
    OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
    header('Location: ' . $landingpage . '&w');
    exit();
}

if (!isset($_SESSION['portal_init'])) {
    $_SESSION['portal_init'] = true;
}

$logoService = new LogoService();

$language = $_SESSION['language_choice'] ?? '1';
$sql = "SELECT c.constant_name, d.definition FROM lang_definitions as d
        JOIN lang_constants AS c ON d.cons_id = c.cons_id
        WHERE d.lang_id = ?";
$tarns = sqlStatement($sql, $language);
$language_defs = array();
while ($row = SqlFetchArray($tarns)) {
    $language_defs[$row['constant_name']] = $row['definition'];
}

$whereto = $_SESSION['whereto'] ?? null;
$user    = $_SESSION['sessionUser'] ?? 'portal user';
$result  = getPatientData($pid);

$msgs   = getPortalPatientNotes($_SESSION['portal_username']);
$msgcnt = count($msgs);
$newcnt = 0;
foreach ($msgs as $i) {
    if ($i['message_status'] == 'New') {
        $newcnt++;
    }
}
if ($newcnt > 0 && $_SESSION['portal_init']) {
    $whereto = $_SESSION['whereto'] = '#secure-msgs-card';
}
$messagesURL = $GLOBALS['web_root'] . '/portal/messaging/messages.php';
$csrfToken   = CsrfUtils::collectCsrfToken();
$webRoot     = $GLOBALS['web_root'];

// ── Appointments ─────────────────────────────────────────────────────────────
$current_date2 = date('Y-m-d');
$apptLimit     = 30;
$appts         = fetchNextXAppts($current_date2, $pid, $apptLimit);
$appointments  = [];
$count         = 0;

if ($appts) {
    foreach ($appts as $row) {
        $status_title = getListItemTitle('apptstat', $row['pc_apptstatus']);
        $count++;
        $dayname  = xl(date('l', strtotime($row['pc_eventDate'])));
        $dispampm = 'am';
        $disphour = (int)substr($row['pc_startTime'], 0, 2);
        $dispmin  = substr($row['pc_startTime'], 3, 2);
        if ($disphour >= 12) {
            $dispampm = 'pm';
            if ($disphour > 12) $disphour -= 12;
        }
        $etitle = $row['pc_hometext'] != '' ? xl('Comments') . ': ' . $row['pc_hometext'] . "\r\n" : '';

        $formattedRecord = [
            'appointmentDate' => $dayname . ', ' . $row['pc_eventDate'] . ' ' . $disphour . ':' . $dispmin . ' ' . $dispampm,
            'appointmentType' => xl('Type') . ': ' . $row['pc_catname'],
            'provider'        => xl('Provider') . ': ' . $row['ufname'] . ' ' . $row['ulname'],
            'status'          => xl('Status') . ': ' . $status_title,
            'mode'            => (int)$row['pc_recurrtype'] > 0 ? 'recurring' : $row['pc_recurrtype'],
            'icon_type'       => (int)$row['pc_recurrtype'] > 0,
            'etitle'          => $etitle,
            'pc_eid'          => $row['pc_eid'],
            'raw_date'        => $row['pc_eventDate'],
            'raw_time'        => $row['pc_startTime'],
            'catname'         => $row['pc_catname'],
            'provider_name'   => $row['ufname'] . ' ' . $row['ulname'],
            'appt_status'     => $status_title,
        ];
        $filteredEvent  = $GLOBALS['kernel']->getEventDispatcher()->dispatch(
            new AppointmentFilterEvent($row, $formattedRecord), AppointmentFilterEvent::EVENT_NAME
        );
        $appointments[] = $filteredEvent->getAppointment() ?? $formattedRecord;
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
$patientName    = text(($result['fname'] ?? '') . ' ' . ($result['lname'] ?? ''));
$patientInitials = strtoupper(substr($result['fname'] ?? 'P', 0, 1) . substr($result['lname'] ?? '', 0, 1));
$hour      = (int)date('H');
$greeting  = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$todayFmt  = date('l, F j, Y');
$nextAppt  = !empty($appointments) ? $appointments[0] : null;

// upcoming = future appointments only
$upcomingAppts = array_filter($appointments, function($a) {
    return strtotime($a['raw_date'] ?? '') >= strtotime('today');
});
$upcomingCount = count($upcomingAppts);

// ── Output ────────────────────────────────────────────────────────────────────
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo xl('My Health Portal') . ' | MDITek'; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ── MDITek Portal Variables ─────────────────────────────────────────────── */
:root {
    --navy:       #1e0a3c;
    --navy-mid:   #2d1260;
    --navy-light: #e8e0f5;
    --navy-pale:  #f5f2fc;
    --gold:       #ffc107;
    --bg:         #f0f2f5;
    --border:     #e2e6ed;
    --white:      #ffffff;
    --text:       #1a1a2e;
    --muted:      #6b7280;
    --blue:       #3b82f6;
    --blue-d:     #eff6ff;
    --green:      #10b981;
    --green-d:    #ecfdf5;
    --amber:      #f59e0b;
    --amber-d:    #fffbeb;
    --rose:       #f43f5e;
    --rose-d:     #fff1f2;
    --violet:     #8b5cf6;
    --violet-d:   #f5f3ff;
    --teal:       #0d9488;
    --teal-d:     #f0fdfa;
    --radius:     12px;
    --shadow:     0 1px 4px rgba(0,0,0,.08), 0 4px 16px rgba(30,10,60,.06);
    --nav-h:      54px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Montserrat', sans-serif;
    background: var(--bg);
    color: var(--text);
    font-size: 13px;
    min-height: 100vh;
}

/* ── Navbar ──────────────────────────────────────────────────────────────── */
#pt-nav {
    position: fixed;
    top: 0; left: 0; right: 0;
    height: var(--nav-h);
    background: var(--white);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    padding: 0 20px;
    z-index: 1000;
    gap: 0;
}

.pt-nav-brand {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 15px;
    color: var(--navy);
    text-decoration: none;
    margin-right: 24px;
    white-space: nowrap;
}

.pt-nav-brand-dots {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 3px;
    width: 18px;
    height: 18px;
}
.pt-nav-brand-dots span {
    background: var(--navy-mid);
    border-radius: 2px;
    display: block;
}
.pt-nav-brand-dots span:nth-child(2),
.pt-nav-brand-dots span:nth-child(3) { background: var(--gold); }

.pt-nav-chips { display: flex; gap: 4px; flex: 1; }

.pt-nav-chip {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    color: var(--muted);
    cursor: pointer;
    border: none;
    background: none;
    transition: background .15s, color .15s;
    text-decoration: none;
    white-space: nowrap;
}
.pt-nav-chip:hover, .pt-nav-chip.active {
    background: var(--navy-pale);
    color: var(--navy-mid);
}

.pt-nav-right {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-left: auto;
}

.pt-nav-badge-btn {
    position: relative;
    width: 34px; height: 34px;
    border-radius: 50%;
    border: none;
    background: var(--navy-pale);
    color: var(--navy-mid);
    cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.pt-nav-badge-btn:hover { background: var(--navy-light); }
.pt-nav-badge-btn .badge {
    position: absolute;
    top: 2px; right: 2px;
    min-width: 16px; height: 16px;
    padding: 0 4px;
    border-radius: 8px;
    background: var(--rose);
    color: #fff;
    font-size: 9px;
    font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    line-height: 1;
}

.pt-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--navy-mid), var(--violet));
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    border: none;
    position: relative;
}

/* Dropdown */
.pt-dd-wrap { position: relative; }
.pt-dd-menu {
    position: fixed;
    top: calc(var(--nav-h) + 4px);
    right: 16px;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 8px 32px rgba(30,10,60,.15);
    min-width: 200px;
    padding: 6px 0;
    z-index: 99999;
    display: none;
    animation: ddFade .15s ease;
}
.pt-dd-menu.open { display: block; }
@keyframes ddFade { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }

.pt-dd-header {
    padding: 10px 14px 8px;
    border-bottom: 1px solid var(--border);
    margin-bottom: 4px;
}
.pt-dd-header-name { font-weight: 700; font-size: 13px; color: var(--navy); }
.pt-dd-header-sub  { font-size: 11px; color: var(--muted); margin-top: 1px; }

.pt-dd-item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 14px;
    color: var(--text);
    text-decoration: none;
    font-size: 12.5px;
    font-weight: 500;
    cursor: pointer;
    transition: background .12s;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
}
.pt-dd-item:hover { background: var(--navy-pale); color: var(--navy-mid); }
.pt-dd-item.danger { color: var(--rose); }
.pt-dd-item.danger:hover { background: var(--rose-d); }
.pt-dd-divider { height: 1px; background: var(--border); margin: 4px 0; }

/* ── Page body ───────────────────────────────────────────────────────────── */
#pt-body {
    margin-top: var(--nav-h);
    padding: 24px 24px 40px;
    max-width: 1400px;
    margin-left: auto;
    margin-right: auto;
}

/* Topbar */
.pt-topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 8px;
}
.pt-greeting { font-size: 20px; font-weight: 700; color: var(--navy); }
.pt-greeting span { color: var(--navy-mid); }
.pt-date-badge {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--muted);
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 4px 12px;
}

/* ── Stat Cards ──────────────────────────────────────────────────────────── */
.pt-stat-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.pt-stat-card {
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 16px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: var(--shadow);
    animation: fadeUp .4s ease both;
}
@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:translateY(0); } }

.pt-stat-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: var(--muted); margin-bottom: 4px; }
.pt-stat-value { font-size: 28px; font-weight: 700; color: var(--navy); line-height: 1; }
.pt-stat-sub   { font-size: 11px; color: var(--muted); margin-top: 4px; }
.pt-stat-sub .up   { color: var(--green); font-weight: 600; }
.pt-stat-sub .down { color: var(--rose);  font-weight: 600; }
.pt-stat-sub .warn { color: var(--amber); font-weight: 600; }

.pt-stat-icon {
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.pt-si-blue   { background: var(--blue-d);   color: var(--blue);   }
.pt-si-green  { background: var(--green-d);  color: var(--green);  }
.pt-si-amber  { background: var(--amber-d);  color: var(--amber);  }
.pt-si-rose   { background: var(--rose-d);   color: var(--rose);   }
.pt-si-violet { background: var(--violet-d); color: var(--violet); }

/* ── Main Grid ───────────────────────────────────────────────────────────── */
.pt-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}
@media(max-width:900px) { .pt-grid { grid-template-columns: 1fr; } }

/* Panel */
.pt-panel {
    background: var(--white);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    box-shadow: var(--shadow);
    overflow: hidden;
    animation: fadeUp .45s ease both;
}

.pt-panel-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 16px 18px 12px;
    border-bottom: 1px solid var(--border);
}
.pt-panel-title { font-size: 13px; font-weight: 700; color: var(--navy); display: flex; align-items: center; gap: 6px; }
.pt-panel-sub   { font-size: 11px; color: var(--muted); margin-top: 2px; }

.pt-chip-btn {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1px solid var(--border);
    background: var(--navy-pale);
    color: var(--navy-mid);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    font-family: 'Montserrat', sans-serif;
    text-decoration: none;
    transition: background .15s;
    white-space: nowrap;
}
.pt-chip-btn:hover { background: var(--navy-light); }

/* Appointments list */
.pt-appt-list { padding: 6px 0; }

.pt-appt-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 18px;
    border-bottom: 1px solid var(--border);
    transition: background .12s;
    cursor: default;
}
.pt-appt-item:last-child { border-bottom: none; }
.pt-appt-item:hover { background: var(--navy-pale); }

.pt-appt-date-col { min-width: 90px; }
.pt-appt-month    { font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--navy-mid); }
.pt-appt-day      { font-size: 22px; font-weight: 700; color: var(--navy); line-height: 1; }
.pt-appt-time     { font-size: 11px; color: var(--muted); margin-top: 1px; }

.pt-appt-bar {
    width: 3px;
    height: 44px;
    border-radius: 2px;
    flex-shrink: 0;
    background: var(--blue);
}
.pt-appt-bar.upcoming { background: var(--green); }
.pt-appt-bar.today    { background: var(--amber); }

.pt-appt-info { flex: 1; min-width: 0; }
.pt-appt-type { font-size: 13px; font-weight: 600; color: var(--navy); }
.pt-appt-prov { font-size: 11.5px; color: var(--muted); margin-top: 2px; }

.pt-appt-status {
    font-size: 10.5px;
    font-weight: 600;
    padding: 3px 9px;
    border-radius: 20px;
    background: var(--blue-d);
    color: var(--blue);
    white-space: nowrap;
}

.pt-empty-state {
    padding: 32px 18px;
    text-align: center;
    color: var(--muted);
    font-size: 12.5px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.pt-empty-icon {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: var(--green-d);
    color: var(--green);
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}

/* Messages panel */
.pt-msg-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 18px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background .12s;
}
.pt-msg-item:last-child { border-bottom: none; }
.pt-msg-item:hover { background: var(--navy-pale); }
.pt-msg-item.unread { background: #fafbff; }

.pt-msg-avatar {
    width: 34px; height: 34px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    color: #fff;
    flex-shrink: 0;
}
.pt-av1 { background: linear-gradient(135deg,#3b82f6,#6366f1); }
.pt-av2 { background: linear-gradient(135deg,#10b981,#0d9488); }
.pt-av3 { background: linear-gradient(135deg,#f59e0b,#ef4444); }
.pt-av4 { background: linear-gradient(135deg,#8b5cf6,#ec4899); }
.pt-av5 { background: linear-gradient(135deg,#06b6d4,#3b82f6); }

.pt-msg-from    { font-size: 12.5px; font-weight: 600; color: var(--navy); display: flex; justify-content: space-between; }
.pt-msg-time    { font-size: 10.5px; color: var(--muted); font-weight: 400; }
.pt-msg-subject { font-size: 12px; color: var(--text); margin-top: 1px; }
.pt-msg-preview { font-size: 11.5px; color: var(--muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 260px; }
.pt-msg-unread-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--blue); margin-top: 5px; flex-shrink: 0; }

/* Quick links panel */
.pt-quick-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    padding: 14px 18px;
}

.pt-quick-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 16px 10px;
    border-radius: 10px;
    border: 1px solid var(--border);
    background: var(--navy-pale);
    color: var(--navy-mid);
    font-size: 11.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s, transform .15s;
    text-align: center;
}
.pt-quick-card:hover { background: var(--navy-light); transform: translateY(-2px); }
.pt-quick-card svg { width: 22px; height: 22px; }

/* ── Full-width panels ───────────────────────────────────────────────────── */
.pt-full-row {
    grid-column: 1 / -1;
}

/* Health summary cards row */
.pt-health-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
    padding: 14px 18px;
}
.pt-health-card {
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s;
    color: var(--text);
}
.pt-health-card:hover { background: var(--navy-pale); }
.pt-health-card-icon {
    width: 36px; height: 36px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.pt-health-card-label { font-size: 11.5px; font-weight: 600; color: var(--navy); }
.pt-health-card-sub   { font-size: 10.5px; color: var(--muted); margin-top: 1px; }

/* Alert banner */
.pt-alert-banner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 11px 18px;
    background: var(--amber-d);
    border-left: 3px solid var(--amber);
    border-radius: 0 8px 8px 0;
    margin: 10px 18px;
    font-size: 12px;
    font-weight: 500;
    color: #92400e;
}

/* Timeout bar */
#pt-timeout-bar {
    position: fixed;
    bottom: 0; left: 0; right: 0;
    height: 3px;
    background: var(--navy-light);
    z-index: 9999;
}
#pt-timeout-progress {
    height: 100%;
    background: linear-gradient(90deg, var(--navy-mid), var(--gold));
    transition: width 1s linear;
}

/* Scrollbar */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--navy-light); border-radius: 3px; }

/* Responsive */
@media(max-width:600px) {
    #pt-body { padding: 14px 12px 30px; }
    .pt-stat-row { grid-template-columns: 1fr 1fr; }
    .pt-quick-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body>

<!-- ══ NAVBAR ══════════════════════════════════════════════════════════════ -->
<nav id="pt-nav">

    <!-- Brand -->
    <a class="pt-nav-brand" href="<?php echo attr($webRoot); ?>/portal/home.php">
        <div class="pt-nav-brand-dots">
            <span></span><span></span><span></span><span></span>
        </div>
        MDITek
    </a>

    <!-- Nav chips -->
    <div class="pt-nav-chips">
        <a class="pt-nav-chip active" href="<?php echo attr($webRoot); ?>/portal/home.php">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Dashboard
        </a>
        <?php if (!empty($GLOBALS['allow_portal_appointments'])): ?>
        <a class="pt-nav-chip" href="/portal/home.php#appointmentcard">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Appointments
        </a>
        <?php endif; ?>
        <a class="pt-nav-chip" href="<?php echo attr($messagesURL); ?>">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            Messages
        </a>
        <a class="pt-nav-chip" href="<?php echo attr($webRoot); ?>/portal/patient/onsitedocuments?pid=<?php echo attr($pid); ?>">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Documents
        </a>
        <?php if (!empty($GLOBALS['allow_portal_chat'])): ?>
        <a class="pt-nav-chip" href="<?php echo attr($webRoot); ?>/portal/messaging/secure_chat.php">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
            Chat
        </a>
        <?php endif; ?>
        <?php if (!empty($GLOBALS['portal_two_ledger']) || !empty($GLOBALS['portal_two_payments'])): ?>
        <a class="pt-nav-chip" href="/portal/home.php#ledgercard">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            Billing
        </a>
        <?php endif; ?>
        <a class="pt-nav-chip" href="<?php echo attr($webRoot . '/ccdaservice/ccda_gateway.php?action=view&csrf_token_form=' . urlencode($csrfToken)); ?>" target="_blank">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 12 11 14 15 10"/></svg>
            Health Record
        </a>
    </div>

    <!-- Right: messages badge + avatar -->
    <div class="pt-nav-right">
        <a href="<?php echo attr($messagesURL); ?>" class="pt-nav-badge-btn" title="Messages">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            <?php if ($newcnt > 0): ?>
            <span class="badge"><?php echo (int)$newcnt; ?></span>
            <?php endif; ?>
        </a>

        <div class="pt-dd-wrap">
            <button class="pt-avatar" id="pt-avatar-btn" onclick="ptToggleDD()" title="Account">
                <?php echo text($patientInitials); ?>
            </button>
            <div class="pt-dd-menu" id="pt-user-dd">
                <div class="pt-dd-header">
                    <div class="pt-dd-header-name"><?php echo $patientName; ?></div>
                    <div class="pt-dd-header-sub"><?php echo text($_SESSION['portal_username'] ?? ''); ?></div>
                </div>
                <a class="pt-dd-item" href="<?php echo attr($webRoot); ?>/portal/patient/patientdata?pid=<?php echo attr($pid); ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    My Profile
                </a>
                <a class="pt-dd-item" href="<?php echo attr($messagesURL); ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    My Messages
                    <?php if ($newcnt > 0): ?>
                    <span style="margin-left:auto;background:var(--rose);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700"><?php echo (int)$newcnt; ?></span>
                    <?php endif; ?>
                </a>
                <a class="pt-dd-item" href="<?php echo attr($webRoot); ?>/portal/patient/onsitedocuments?pid=<?php echo attr($pid); ?>">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    My Documents
                </a>
                <?php if (!empty($GLOBALS['allow_portal_appointments'])): ?>
                <a class="pt-dd-item" href="/portal/home.php#appointmentcard">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    My Appointments
                </a>
                <?php endif; ?>
                <div class="pt-dd-divider"></div>
                <a class="pt-dd-item" href="javascript:changeCredentials(event)">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    Change Credentials
                </a>
                <a class="pt-dd-item danger" href="<?php echo attr($webRoot); ?>/portal/logout.php">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Logout
                </a>
            </div>
        </div>
    </div>
</nav>

<!-- ══ BODY ════════════════════════════════════════════════════════════════ -->
<div id="pt-body">

    <!-- Greeting -->
    <div class="pt-topbar">
        <div class="pt-greeting"><?php echo $greeting; ?>, <span><?php echo text($result['fname'] ?? 'Patient'); ?></span> 👋</div>
        <span class="pt-date-badge"><?php echo $todayFmt; ?></span>
    </div>

    <!-- New messages alert -->
    <?php if ($newcnt > 0): ?>
    <div class="pt-alert-banner" style="margin-bottom:16px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        You have <strong>&nbsp;<?php echo (int)$newcnt; ?> new message<?php echo $newcnt != 1 ? 's' : ''; ?></strong> &mdash;
        <a href="<?php echo attr($messagesURL); ?>" style="color:inherit;font-weight:700;text-decoration:underline;">View now</a>
    </div>
    <?php endif; ?>

    <!-- Stat Cards -->
    <div class="pt-stat-row">
        <div class="pt-stat-card" style="animation-delay:.05s">
            <div>
                <div class="pt-stat-label">Upcoming Appointments</div>
                <div class="pt-stat-value"><?php echo (int)$upcomingCount; ?></div>
                <div class="pt-stat-sub">
                    <?php if ($nextAppt): ?>
                        <span class="up">Next: <?php echo text(date('M j', strtotime($nextAppt['raw_date']))); ?></span>
                    <?php else: ?>
                        <span>None scheduled</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pt-stat-icon pt-si-blue">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
        </div>

        <div class="pt-stat-card" style="animation-delay:.10s">
            <div>
                <div class="pt-stat-label">Messages</div>
                <div class="pt-stat-value"><?php echo (int)$msgcnt; ?></div>
                <div class="pt-stat-sub">
                    <?php if ($newcnt > 0): ?>
                        <span class="down"><?php echo (int)$newcnt; ?> unread</span>
                    <?php else: ?>
                        <span class="up">All read ✓</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="pt-stat-icon pt-si-violet">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
            </div>
        </div>

        <div class="pt-stat-card" style="animation-delay:.15s">
            <div>
                <div class="pt-stat-label">Health Record</div>
                <div class="pt-stat-value" style="font-size:18px;line-height:1.4">CCD</div>
                <div class="pt-stat-sub"><span class="up">Available</span></div>
            </div>
            <div class="pt-stat-icon pt-si-green">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 12 11 14 15 10"/></svg>
            </div>
        </div>

        <?php if (!empty($GLOBALS['portal_two_payments'])): ?>
        <div class="pt-stat-card" style="animation-delay:.20s">
            <div>
                <div class="pt-stat-label">Payments</div>
                <div class="pt-stat-value" style="font-size:18px;line-height:1.4">Pay</div>
                <div class="pt-stat-sub"><span class="warn">Online payment</span></div>
            </div>
            <div class="pt-stat-icon pt-si-amber">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Main 2-col Grid -->
    <div class="pt-grid">

        <!-- Appointments Panel -->
        <?php if (!empty($GLOBALS['allow_portal_appointments'])): ?>
        <div class="pt-panel" id="appointmentcard" style="animation-delay:.20s">
            <div class="pt-panel-header">
                <div>
                    <div class="pt-panel-title">
                        <svg style="width:1em;height:1em;color:var(--blue)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        My Appointments
                    </div>
                    <div class="pt-panel-sub"><?php echo (int)$upcomingCount; ?> upcoming</div>
                </div>
            </div>
            <div class="pt-appt-list">
                <?php if (empty($appointments)): ?>
                    <div class="pt-empty-state">
                        <div class="pt-empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
                        </div>
                        No upcoming appointments.
                    </div>
                <?php else: ?>
                    <?php foreach (array_slice($appointments, 0, 5) as $appt):
                        $apptDate = strtotime($appt['raw_date'] ?? '');
                        $isToday  = date('Y-m-d', $apptDate) === date('Y-m-d');
                        $barCls   = $isToday ? 'today' : 'upcoming';
                        $timeStr  = '';
                        if (!empty($appt['raw_time'])) {
                            $t = strtotime($appt['raw_time']);
                            $timeStr = date('g:i A', $t);
                        }
                    ?>
                    <div class="pt-appt-item">
                        <div class="pt-appt-date-col">
                            <div class="pt-appt-month"><?php echo text(date('M', $apptDate)); ?></div>
                            <div class="pt-appt-day"><?php echo text(date('j', $apptDate)); ?></div>
                            <?php if ($timeStr): ?><div class="pt-appt-time"><?php echo text($timeStr); ?></div><?php endif; ?>
                        </div>
                        <div class="pt-appt-bar <?php echo $barCls; ?>"></div>
                        <div class="pt-appt-info">
                            <div class="pt-appt-type"><?php echo text($appt['catname'] ?? 'Appointment'); ?></div>
                            <div class="pt-appt-prov"><?php echo text($appt['provider_name'] ?? ''); ?></div>
                        </div>
                        <span class="pt-appt-status"><?php echo text($appt['appt_status'] ?? 'Scheduled'); ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($appointments) > 5): ?>
                    <div style="padding:10px 18px;font-size:11.5px;color:var(--muted);text-align:center;">
                        +<?php echo count($appointments) - 5; ?> more appointment<?php echo (count($appointments)-5) != 1 ? 's' : ''; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Messages Panel -->
        <div class="pt-panel" id="secure-msgs-card" style="animation-delay:.25s">
            <div class="pt-panel-header">
                <div>
                    <div class="pt-panel-title">
                        <svg style="width:1em;height:1em;color:var(--violet)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        My Messages
                    </div>
                    <div class="pt-panel-sub"><?php echo (int)$newcnt; ?> unread</div>
                </div>
                <a class="pt-chip-btn" href="<?php echo attr($messagesURL); ?>">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    Open
                </a>
            </div>
            <?php
            $avatarCls = ['pt-av1','pt-av2','pt-av3','pt-av4','pt-av5'];
            if (empty($msgs)):
            ?>
                <div class="pt-empty-state">
                    <div class="pt-empty-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>
                    </div>
                    No messages.
                </div>
            <?php else:
                foreach (array_slice($msgs, 0, 5) as $mi => $msg):
                    $isUnread = ($msg['message_status'] === 'New');
                    $avCls    = $avatarCls[$mi % 5];
                    $fromName = trim($msg['from_name'] ?? $msg['from_user'] ?? 'Staff');
                    $initials = '';
                    foreach (explode(' ', $fromName) as $p) $initials .= strtoupper(substr($p,0,1));
                    $initials = substr($initials,0,2) ?: '?';
                    $msgDate  = $msg['date'] ?? '';
                    $msgTime  = $msgDate ? (date('Y-m-d',strtotime($msgDate)) < date('Y-m-d') ? date('M j',strtotime($msgDate)) : date('g:i A',strtotime($msgDate))) : '';
                    $subject  = $msg['title'] ?? $msg['subject'] ?? '(no subject)';
                    $preview  = strip_tags($msg['body'] ?? '');
            ?>
                <div class="pt-msg-item <?php echo $isUnread ? 'unread' : ''; ?>" onclick="window.location='<?php echo attr($messagesURL); ?>'">
                    <?php if ($isUnread): ?><div class="pt-msg-unread-dot"></div><?php endif; ?>
                    <div class="pt-msg-avatar <?php echo $avCls; ?>"><?php echo text($initials); ?></div>
                    <div style="flex:1;min-width:0">
                        <div class="pt-msg-from"><?php echo text($fromName); ?> <span class="pt-msg-time"><?php echo text($msgTime); ?></span></div>
                        <div class="pt-msg-subject"><?php echo text($subject); ?></div>
                        <div class="pt-msg-preview"><?php echo text(substr($preview,0,80)); ?>…</div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Quick Access Panel -->
        <div class="pt-panel" style="animation-delay:.30s">
            <div class="pt-panel-header">
                <div>
                    <div class="pt-panel-title">
                        <svg style="width:1em;height:1em;color:var(--green)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><polyline points="3 6 4 7 6 5"/><polyline points="3 12 4 13 6 11"/><polyline points="3 18 4 19 6 17"/></svg>
                        Quick Access
                    </div>
                    <div class="pt-panel-sub">Jump to your health tools</div>
                </div>
            </div>
            <div class="pt-quick-grid">
                <a class="pt-quick-card" href="<?php echo attr($webRoot); ?>/portal/patient/patientdata?pid=<?php echo attr($pid); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    My Profile
                </a>
                <a class="pt-quick-card" href="<?php echo attr($webRoot); ?>/portal/patient/onsitedocuments?pid=<?php echo attr($pid); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    Documents
                </a>
                <a class="pt-quick-card" href="<?php echo attr($webRoot . '/ccdaservice/ccda_gateway.php?action=view&csrf_token_form=' . urlencode($csrfToken)); ?>" target="_blank">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    View CCD
                </a>
                <a class="pt-quick-card" href="<?php echo attr($webRoot . '/ccdaservice/ccda_gateway.php?action=dl&csrf_token_form=' . urlencode($csrfToken)); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download CCD
                </a>
                <?php if (!empty($GLOBALS['portal_two_payments'])): ?>
                <a class="pt-quick-card" href="/portal/home.php#paymentcard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                    Make Payment
                </a>
                <?php endif; ?>
                <?php if (!empty($GLOBALS['portal_two_ledger'])): ?>
                <a class="pt-quick-card" href="/portal/home.php#ledgercard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                    Ledger
                </a>
                <?php endif; ?>
                <a class="pt-quick-card" href="<?php echo attr($webRoot); ?>/portal/patient/onsiteactivityviews">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    My Dashboard
                </a>
                <a class="pt-quick-card" href="/portal/home.php#openSignModal" data-toggle="modal" data-type="patient-signature">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg>
                    My Signature
                </a>
            </div>
        </div>

        <!-- Health Summary Panel -->
        <div class="pt-panel" id="lists" style="animation-delay:.35s">
            <div class="pt-panel-header">
                <div>
                    <div class="pt-panel-title">
                        <svg style="width:1em;height:1em;color:var(--teal)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                        My Health Summary
                    </div>
                    <div class="pt-panel-sub">Your care information at a glance</div>
                </div>
            </div>
            <div class="pt-health-row">
                <a class="pt-health-card" href="/portal/home.php#reportcard">
                    <div class="pt-health-card-icon" style="background:var(--blue-d);color:var(--blue)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    </div>
                    <div>
                        <div class="pt-health-card-label">Reports</div>
                        <div class="pt-health-card-sub">View chart</div>
                    </div>
                </a>
                <a class="pt-health-card" href="<?php echo attr($webRoot); ?>/portal/patient/patientdata?pid=<?php echo attr($pid); ?>">
                    <div class="pt-health-card-icon" style="background:var(--green-d);color:var(--green)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                    </div>
                    <div>
                        <div class="pt-health-card-label">Demographics</div>
                        <div class="pt-health-card-sub">Update info</div>
                    </div>
                </a>
                <a class="pt-health-card" href="/portal/home.php#downloadcard">
                    <div class="pt-health-card-icon" style="background:var(--violet-d);color:var(--violet)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    </div>
                    <div>
                        <div class="pt-health-card-label">Downloads</div>
                        <div class="pt-health-card-sub">Charted docs</div>
                    </div>
                </a>
                <?php if (!empty($GLOBALS['allow_portal_chat'])): ?>
                <a class="pt-health-card" href="<?php echo attr($webRoot); ?>/portal/messaging/secure_chat.php">
                    <div class="pt-health-card-icon" style="background:var(--teal-d);color:var(--teal)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:1em;height:1em"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    </div>
                    <div>
                        <div class="pt-health-card-label">Chat</div>
                        <div class="pt-health-card-sub">Live support</div>
                    </div>
                </a>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /pt-grid -->

</div><!-- /pt-body -->

<!-- Timeout progress bar -->
<div id="pt-timeout-bar"><div id="pt-timeout-progress" style="width:100%"></div></div>

<script>
// ── Dropdown ──────────────────────────────────────────────────────────────
function ptToggleDD() {
    document.getElementById('pt-user-dd').classList.toggle('open');
}
document.addEventListener('click', function(e) {
    var dd  = document.getElementById('pt-user-dd');
    var btn = document.getElementById('pt-avatar-btn');
    if (dd && btn && !dd.contains(e.target) && !btn.contains(e.target)) {
        dd.classList.remove('open');
    }
});

// ── Session timeout bar ───────────────────────────────────────────────────
(function() {
    var total   = <?php echo (int)($GLOBALS['portal_timeout'] ?? 1800); ?> * 1000;
    var started = Date.now();
    var bar     = document.getElementById('pt-timeout-progress');
    var timer   = setInterval(function() {
        var elapsed = Date.now() - started;
        var pct     = Math.max(0, 100 - (elapsed / total * 100));
        if (bar) bar.style.width = pct + '%';
        if (pct <= 0) {
            clearInterval(timer);
            window.location = '<?php echo attr($webRoot); ?>/portal/logout.php?timeout=1';
        }
    }, 1000);
})();

// ── Auto-scroll to whereto ────────────────────────────────────────────────
<?php if (!empty($whereto) && $whereto !== '#documentscard'): ?>
document.addEventListener('DOMContentLoaded', function() {
    var el = document.querySelector('<?php echo attr(addslashes($whereto)); ?>');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php endif; ?>

// changeCredentials stub — keep compatible with existing OpenEMR portal JS
function changeCredentials(e) {
    if (e) e.preventDefault();
    // OpenEMR portal loads this via the standard bootstrap modal mechanism
    // The actual credential change modal is in the portal's layout JS
    if (typeof window.openCredentialModal === 'function') window.openCredentialModal();
}
</script>

</body>
</html>
