<?php

/**
 * Viewing and modification/creation of office notes.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Services\ONoteService;

// Control access
if (!AclMain::aclCheckCore('encounters', 'notes')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Office Notes")]);
    exit;
}

$oNoteService = new ONoteService();

//the number of records to display per screen
$N = 10;

$offset = (isset($_REQUEST['offset'])) ? $_REQUEST['offset'] : 0;
$active = (isset($_REQUEST['active'])) ? $_REQUEST['active'] : -1;

//this code handles changing the state of activity tags when the user updates them through the interface
if (isset($_POST['mode'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    if ($_POST['mode'] == "update") {
        foreach ($_POST as $var => $val) {
            if ($val == "true" || $val == "false") {
                $id = str_replace("act", "", $var);
                if ($val == "true") {
                    $oNoteService->enableNoteById($id);
                } elseif ($val == "false") {
                    $oNoteService->disableNoteById($id);
                }
            }
        }
    } elseif ($_POST['mode'] == "new") {
        $oNoteService->add($_POST["note"]);
    }
}
?>
<html>
<head>
<?php Header::setupHeader(); ?>
<title><?php echo xlt('Office Notes'); ?></title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
<style>
/* ── Root palette (matches suite) ── */
:root {
    --navy:        #1a1a2e;
    --navy-light:  #0f3460;
    --purple:      #7c3aed;
    --purple-mid:  #6d28d9;
    --purple-pale: #ede9fe;
    --purple-ghost:#f5f3ff;
    --white:       #ffffff;
    --gray-50:     #f9fafb;
    --gray-100:    #f3f4f6;
    --gray-200:    #e5e7eb;
    --gray-300:    #d1d5db;
    --gray-400:    #9ca3af;
    --gray-500:    #6b7280;
    --gray-700:    #374151;
    --gray-900:    #111827;
    --green:       #059669;
    --green-bg:    #d1fae5;
    --amber:       #d97706;
    --amber-bg:    #fef3c7;
    --shadow-sm:   0 1px 3px rgba(0,0,0,.08);
    --shadow:      0 4px 14px rgba(0,0,0,.10);
    --radius:      10px;
    --radius-sm:   6px;
}

*, *::before, *::after { box-sizing: border-box; }

body {
    font-family: 'DM Sans', system-ui, sans-serif !important;
    background: var(--gray-100) !important;
    color: var(--gray-900);
    margin: 0;
    padding: 0;
}

/* ── Page wrapper ── */
#on-page {
    max-width: 860px;
    margin: 0 auto;
    padding: 24px 20px 48px;
}

/* ── Hero banner ── */
.on-hero {
    position: relative;
    overflow: hidden;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 55%, var(--purple-mid) 100%);
    border-radius: var(--radius);
    padding: 22px 28px;
    margin-bottom: 22px;
    display: flex;
    align-items: center;
    gap: 16px;
}
.on-hero::after {
    content: '';
    position: absolute;
    inset: 0;
    background: repeating-linear-gradient(
        -45deg,
        rgba(255,255,255,.03) 0px,
        rgba(255,255,255,.03) 1px,
        transparent 1px,
        transparent 12px
    );
    pointer-events: none;
}
.on-hero-icon {
    width: 42px; height: 42px;
    background: rgba(255,255,255,.12);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.on-hero-icon svg { color: #fff; }
.on-hero-text { flex: 1; min-width: 0; }
.on-hero-title {
    font-size: 18px;
    font-weight: 700;
    color: #fff;
    letter-spacing: -.02em;
    margin: 0 0 2px;
}
.on-hero-sub {
    font-size: 12px;
    color: rgba(255,255,255,.6);
    font-weight: 400;
    margin: 0;
}
.on-back-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 600;
    color: rgba(255,255,255,.75);
    text-decoration: none;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 99px;
    padding: 5px 12px;
    transition: background .15s;
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}
.on-back-link:hover { background: rgba(255,255,255,.18); color: #fff; text-decoration: none; }

/* ── Cards ── */
.on-card {
    background: var(--white);
    border: 1.5px solid var(--gray-200);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    margin-bottom: 18px;
}
.on-card-header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 20px 12px;
    border-bottom: 1.5px solid var(--gray-200);
    background: var(--gray-50);
}
.on-card-header-icon {
    width: 28px; height: 28px;
    background: var(--purple-ghost);
    border-radius: var(--radius-sm);
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.on-card-header-icon svg { color: var(--purple); }
.on-card-header-label {
    font-size: 12px;
    font-weight: 700;
    color: var(--navy);
    text-transform: uppercase;
    letter-spacing: .06em;
}
.on-card-body { padding: 18px 20px; }

/* ── New note form ── */
.on-textarea {
    width: 100%;
    background: var(--white);
    border: 1.5px solid var(--gray-200);
    border-radius: var(--radius-sm);
    font-family: 'DM Sans', system-ui, sans-serif;
    font-size: 13px;
    color: var(--gray-900);
    padding: 10px 13px;
    resize: vertical;
    min-height: 88px;
    outline: none;
    transition: border-color .15s, box-shadow .15s;
    line-height: 1.6;
}
.on-textarea:focus {
    border-color: var(--purple);
    box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}
.on-textarea::placeholder { color: var(--gray-400); }

.on-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-family: 'DM Sans', system-ui, sans-serif;
    font-size: 13px;
    font-weight: 600;
    border-radius: var(--radius-sm);
    border: none;
    cursor: pointer;
    padding: 8px 18px;
    transition: all .15s;
    text-decoration: none;
}
.on-btn-primary {
    background: var(--purple);
    color: #fff;
    box-shadow: 0 2px 8px rgba(124,58,237,.25);
}
.on-btn-primary:hover { background: var(--purple-mid); transform: translateY(-1px); color: #fff; text-decoration: none; }
.on-btn-secondary {
    background: var(--white);
    color: var(--gray-700);
    border: 1.5px solid var(--gray-300);
}
.on-btn-secondary:hover { background: var(--gray-50); border-color: var(--gray-400); color: var(--gray-900); text-decoration: none; }

/* ── Filter tabs ── */
.on-filter-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 16px;
    flex-wrap: wrap;
}
.on-filter-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--gray-400);
    margin-right: 4px;
}
.on-filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-family: 'DM Sans', system-ui, sans-serif;
    font-size: 12px;
    font-weight: 600;
    padding: 5px 13px;
    border-radius: 99px;
    border: 1.5px solid var(--gray-300);
    background: var(--white);
    color: var(--gray-500);
    cursor: pointer;
    text-decoration: none;
    transition: all .15s;
}
.on-filter-btn:hover { border-color: var(--purple); color: var(--purple); text-decoration: none; }
.on-filter-btn.active {
    background: var(--purple);
    border-color: var(--purple);
    color: #fff;
    box-shadow: 0 2px 8px rgba(124,58,237,.2);
}
.on-filter-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px; height: 18px;
    background: rgba(255,255,255,.25);
    border-radius: 99px;
    font-size: 10px;
    font-weight: 700;
    padding: 0 5px;
}
.on-filter-btn:not(.active) .on-filter-count {
    background: var(--gray-100);
    color: var(--gray-500);
}

/* ── Notes table ── */
.on-table-wrap { overflow-x: auto; }
.on-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.on-table thead tr {
    background: var(--navy);
}
.on-table thead th {
    padding: 10px 14px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: rgba(255,255,255,.7);
    text-align: left;
    white-space: nowrap;
}
.on-table thead th:first-child { border-radius: var(--radius-sm) 0 0 0; width: 60px; text-align: center; }
.on-table thead th:last-child  { border-radius: 0 var(--radius-sm) 0 0; }

.on-table tbody tr {
    border-bottom: 1px solid var(--gray-100);
    transition: background .12s;
}
.on-table tbody tr:last-child { border-bottom: none; }
.on-table tbody tr:hover { background: var(--purple-ghost); }

.on-table tbody td {
    padding: 11px 14px;
    vertical-align: top;
    color: var(--gray-700);
    line-height: 1.55;
}
.on-table tbody td:first-child { text-align: center; padding-top: 13px; }

/* Toggle switch replacing checkbox */
.on-toggle-wrap { display: inline-flex; align-items: center; justify-content: center; }
.on-toggle {
    position: relative;
    width: 34px; height: 18px;
    display: inline-block;
    cursor: pointer;
}
.on-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }
.on-toggle-track {
    position: absolute; inset: 0;
    background: var(--gray-300);
    border-radius: 99px;
    transition: background .2s;
}
.on-toggle input:checked ~ .on-toggle-track { background: var(--green); }
.on-toggle-thumb {
    position: absolute;
    top: 2px; left: 2px;
    width: 14px; height: 14px;
    background: #fff;
    border-radius: 50%;
    transition: transform .2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.on-toggle input:checked ~ .on-toggle-thumb { transform: translateX(16px); }

.on-date-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    font-weight: 700;
    color: var(--gray-900);
    white-space: nowrap;
}
.on-date-badge.today {
    color: var(--purple);
}
.on-sender {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 11px;
    font-weight: 500;
    color: var(--gray-400);
    margin-top: 2px;
}
.on-note-body {
    font-size: 13px;
    color: var(--gray-700);
    line-height: 1.6;
}
.on-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 99px;
    text-transform: uppercase;
    letter-spacing: .05em;
}
.on-status-active   { background: var(--green-bg); color: var(--green); }
.on-status-inactive { background: var(--gray-100); color: var(--gray-400); }

/* Empty state */
.on-empty {
    padding: 36px 20px;
    text-align: center;
    color: var(--gray-400);
}
.on-empty svg { margin-bottom: 10px; color: var(--gray-300); }
.on-empty p { font-size: 13px; margin: 0; }

/* ── Save bar ── */
.on-save-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 20px;
    background: var(--gray-50);
    border-top: 1.5px solid var(--gray-200);
    gap: 12px;
}
.on-save-hint {
    font-size: 11px;
    color: var(--gray-400);
    font-weight: 500;
}

/* ── Pagination ── */
.on-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 0 0;
}
.on-page-info {
    font-size: 11px;
    color: var(--gray-400);
    font-weight: 500;
}
</style>
</head>
<body>

<div id="on-page">

    <!-- ── Hero ── -->
    <div class="on-hero">
        <div class="on-hero-icon">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <polyline points="10 9 9 9 8 9"/>
            </svg>
        </div>
        <div class="on-hero-text">
            <div class="on-hero-title"><?php echo xlt('Office Notes'); ?></div>
            <div class="on-hero-sub"><?php echo xlt('Internal notes visible to authorized staff'); ?></div>
        </div>
        <a href="office_comments.php" class="on-back-link" onclick='top.restoreSession()'>
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
            <?php echo xlt('Back'); ?>
        </a>
    </div>

    <!-- ── New Note card ── -->
    <div class="on-card">
        <div class="on-card-header">
            <div class="on-card-header-icon">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            </div>
            <span class="on-card-header-label"><?php echo xlt('Add New Note'); ?></span>
        </div>
        <div class="on-card-body">
            <form method="post" name="new_note" action="office_comments_full.php" onsubmit='return top.restoreSession()'>
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                <input type="hidden" name="mode" value="new">
                <input type="hidden" name="offset" value="<?php echo attr($offset); ?>">
                <input type="hidden" name="active" value="<?php echo attr($active); ?>">

                <textarea name="note" class="on-textarea" rows="3"
                    placeholder="<?php echo xla('Type your office note here…'); ?>"
                    required="required"></textarea>

                <div style="margin-top:12px;">
                    <button type="submit" class="on-btn on-btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <?php echo xlt('Add Note'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Notes list card ── -->
    <div class="on-card">
        <div class="on-card-header">
            <div class="on-card-header-icon">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            </div>
            <span class="on-card-header-label"><?php echo xlt('Office Notes'); ?></span>
        </div>
        <div class="on-card-body">

            <form method="post" name="update_activity" action="office_comments_full.php" onsubmit='return top.restoreSession()'>
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
                <input type="hidden" name="mode" value="update">
                <input type="hidden" name="offset" value="<?php echo attr($offset);?>">
                <input type="hidden" name="active" value="<?php echo attr($active);?>">

                <?php
                if ($active === "1") {
                    $all_active = ""; $only_active = " active"; $only_inactive = "";
                } elseif ($active === "0") {
                    $all_active = ""; $only_active = ""; $only_inactive = " active";
                } else {
                    $all_active = " active"; $only_active = ""; $only_inactive = "";
                }
                ?>

                <!-- Filter row -->
                <div class="on-filter-row">
                    <span class="on-filter-label"><?php echo xlt('Show'); ?>:</span>
                    <a href="office_comments_full.php?offset=0&active=-1"
                       class="on-filter-btn<?php echo $all_active; ?>"
                       onclick='top.restoreSession()'>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/></svg>
                        <?php echo xlt('All'); ?>
                    </a>
                    <a href="office_comments_full.php?offset=0&active=1"
                       class="on-filter-btn<?php echo $only_active; ?>"
                       onclick='top.restoreSession()'>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php echo xlt('Active'); ?>
                    </a>
                    <a href="office_comments_full.php?offset=0&active=0"
                       class="on-filter-btn<?php echo $only_inactive; ?>"
                       onclick='top.restoreSession()'>
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                        <?php echo xlt('Inactive'); ?>
                    </a>
                </div>

                <!-- Table -->
                <div class="on-table-wrap">
                    <table class="on-table">
                        <?php
                        $notes = $oNoteService->getNotes($active, $offset, $N);
                        $result_count = 0;

                        if (!empty($notes)) {
                            echo '<thead><tr>';
                            echo '<th>' . xlt('Active') . '</th>';
                            echo '<th>' . xlt('Date') . ' / ' . xlt('Sender') . '</th>';
                            echo '<th>' . xlt('Note') . '</th>';
                            echo '</tr></thead><tbody>';

                            foreach ($notes as $note) {
                                $result_count++;
                                $date = (new DateTime($note['date']))->format('Y-m-d');
                                $todaysDate = new DateTime();
                                $is_today = ($todaysDate->format('Y-m-d') == $date);
                                $date_string = $is_today
                                    ? xl("Today") . ", " . oeFormatShortDate($date)
                                    : oeFormatShortDate($date);
                                $is_active = ($note['activity'] == 1);
                                $checked   = $is_active ? "checked" : "";
                                $date_class = $is_today ? "on-date-badge today" : "on-date-badge";
                                $pill_class = $is_active ? "on-status-pill on-status-active" : "on-status-pill on-status-inactive";
                                $pill_label = $is_active ? xlt('Active') : xlt('Inactive');

                                echo "<tr>";

                                // Toggle cell
                                echo "<td>";
                                echo "<input type='hidden' value='' name='act" . attr($note['id']) . "' id='act" . attr($note['id']) . "'>";
                                echo "<label class='on-toggle'>";
                                echo "<input name='box" . attr($note['id']) . "' id='box" . attr($note['id']) . "' type='checkbox' $checked "
                                   . "onClick='document.update_activity.act" . attr($note['id']) . ".value=this.checked'>";
                                echo "<span class='on-toggle-track'></span>";
                                echo "<span class='on-toggle-thumb'></span>";
                                echo "</label>";
                                echo "</td>";

                                // Date / sender cell
                                echo "<td>";
                                echo "<div class='" . attr($date_class) . "'>";
                                echo "<svg width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='4' width='18' height='18' rx='2'/><line x1='16' y1='2' x2='16' y2='6'/><line x1='8' y1='2' x2='8' y2='6'/><line x1='3' y1='10' x2='21' y2='10'/></svg>";
                                echo text($date_string);
                                echo "</div>";
                                echo "<div class='on-sender'>";
                                echo "<svg width='11' height='11' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2'><path d='M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2'/><circle cx='12' cy='7' r='4'/></svg>";
                                echo text($note['user']);
                                echo "</div>";
                                echo "<div style='margin-top:5px'><span class='" . attr($pill_class) . "'>" . $pill_label . "</span></div>";
                                echo "</td>";

                                // Note body cell
                                echo "<td><div class='on-note-body'>" . nl2br(text($note['body'])) . "</div></td>";

                                echo "</tr>";
                            }
                            echo "</tbody>";
                        } else {
                            echo '<tbody><tr><td colspan="3">';
                            echo '<div class="on-empty">';
                            echo '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
                            echo '<p>' . xlt('No notes found for the selected filter.') . '</p>';
                            echo '</div>';
                            echo '</td></tr></tbody>';
                        }
                        ?>
                    </table>
                </div>

                <!-- Save bar -->
                <div class="on-save-bar">
                    <span class="on-save-hint">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <?php echo xlt('Toggle switches to change active status, then save.'); ?>
                    </span>
                    <button type="submit" class="on-btn on-btn-primary">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <?php echo xlt('Save Activity'); ?>
                    </button>
                </div>

            </form>

            <!-- Pagination -->
            <div class="on-pagination">
                <div>
                <?php
                if ($offset > ($N - 1)) {
                    echo "<a class='on-btn on-btn-secondary' href='office_comments_full.php?active=" . attr_url($active) . "&offset=" . attr_url($offset - $N) . "' onclick='top.restoreSession()'>"
                       . "<svg width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'><polyline points='15 18 9 12 15 6'/></svg>"
                       . xlt('Previous') . "</a>";
                }
                ?>
                </div>
                <div class="on-page-info">
                    <?php
                    $page_num = (int)($offset / $N) + 1;
                    echo xlt('Page') . ' ' . $page_num;
                    ?>
                </div>
                <div>
                <?php
                if ($result_count == $N) {
                    echo "<a class='on-btn on-btn-secondary' href='office_comments_full.php?active=" . attr_url($active) . "&offset=" . attr_url($offset + $N) . "' onclick='top.restoreSession()'>"
                       . xlt('Next')
                       . "<svg width='13' height='13' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round' style='margin-left:4px'><polyline points='9 18 15 12 9 6'/></svg>"
                       . "</a>";
                }
                ?>
                </div>
            </div>

        </div>
    </div>

</div>
</body>
</html>
