<?php

/* Copyright (C) 2005-2007 Rod Roark <rod@sunsetsystems.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 */

require_once('../../globals.php');
require_once("$srcdir/patient.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Core\Header;

$info_msg = "";

$result = "";
if (!empty($_REQUEST['searchby']) && !empty($_REQUEST['searchparm'])) {
    $searchby  = $_REQUEST['searchby'];
    $searchparm = trim($_REQUEST['searchparm']);

    if ($searchby == "Last") {
        $result = getPatientLnames("$searchparm", "*");
    } elseif ($searchby == "Phone") {
        $result = getPatientPhone("$searchparm", "*");
    } elseif ($searchby == "ID") {
        $result = getPatientId("$searchparm", "*");
    } elseif ($searchby == "DOB") {
        $result = getPatientDOB(DateToYYYYMMDD($searchparm), "*");
    } elseif ($searchby == "SSN") {
        $result = getPatientSSN("$searchparm", "*");
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<?php Header::setupHeader(['common', 'datetime-picker', 'opener']); ?>
<title><?php echo htmlspecialchars(xl('Patient Finder'), ENT_NOQUOTES); ?></title>

<style>
* { box-sizing: border-box; }

html, body {
    height: 100%;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    font-size: 13px;
    background: #f3f0f9;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}

.page-wrap {
    display: flex;
    flex-direction: column;
    height: 100%;
}

#results-wrap {
    background: #FFF !important;
}

form { padding: 0; margin: 0; }

/* ── Search bar ── */
#searchCriteria {
    background: #fff;
    border-bottom: 1px solid #e4ddf5;
    padding: 12px 14px;
    position: sticky;
    top: 0;
    z-index: 10;
}

.search-row {
    display: flex;
    align-items: center;
    gap: 8px;
}

.search-row label {
    font-size: 12px;
    font-weight: 600;
    color: #47377a;
    white-space: nowrap;
}

#searchby {
    border: 1px solid #e4ddf5;
    border-radius: 7px;
    padding: 6px 10px;
    font-size: 12px;
    font-family: inherit;
    background: #fff;
    color: #47377a;
    cursor: pointer;
}
#searchby:focus { outline: none; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.1); }

/* Search input wrapper with icon */
.search-input-wrap {
    flex: 1;
    position: relative;
    display: flex;
    align-items: center;
}
.search-input-wrap svg {
    position: absolute;
    left: 10px;
    stroke: #9ca3af;
    pointer-events: none;
}
#searchparm {
    width: 100%;
    border: 1px solid #e4ddf5;
    border-radius: 7px;
    padding: 7px 10px 7px 32px;
    font-size: 13px;
    font-family: inherit;
    background: #fff;
    transition: border-color .15s, box-shadow .15s;
}
#searchparm:focus {
    outline: none;
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124,58,237,.1);
}

/* Spinner inside input */
#searchspinner {
    position: absolute;
    right: 10px;
    display: none;
    align-items: center;
}
#searchspinner.active { display: flex; }

.spinner-ring {
    width: 14px; height: 14px;
    border: 2px solid #e4ddf5;
    border-top-color: #7c3aed;
    border-radius: 50%;
    animation: spin .6s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Status bar ── */
#searchstatus {
    padding: 8px 14px;
    font-size: 12px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 6px;
}
#searchstatus.idle    { color: #9ca3af; }
#searchstatus.success { color: #059669; background: #f0fdf4; border-bottom: 1px solid #bbf7d0; }
#searchstatus.warning { color: #d97706; background: #fffbeb; border-bottom: 1px solid #fde68a; }
#searchstatus.error   { color: #dc2626; background: #fef2f2; border-bottom: 1px solid #fecaca; }

/* ── Results table ── */
#results-wrap {
    padding: 0;
    overflow-y: auto;
    flex: 1;
    min-height: 0;
}

#searchResults {
    width: 100%;
    border-collapse: collapse;
}

#searchResults thead th {
    background: #f3f0f9;
    padding: 7px 12px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #47377a;
    border-bottom: 1px solid #e4ddf5;
    position: sticky;
    top: 0;
}

#searchResults tbody tr {
    cursor: pointer;
    border-bottom: 1px solid #f3f0f9;
    transition: background .1s;
}
#searchResults tbody tr:hover,
#searchResults tbody tr.highlight {
    background: #f5f3ff;
}
#searchResults tbody tr:active {
    background: #ede9fe;
}

#searchResults td {
    padding: 9px 12px;
    font-size: 13px;
    color: #1a1523;
}

.srName { font-weight: 600; color: #47377a !important; }
.srPhone, .srDOB { color: #6b7280 !important; font-size: 12px !important; }
.srID { font-size: 11px !important; color: #9ca3af !important; font-family: monospace; }

.billing td { color: #dc2626 !important; }
.billing .srName::after {
    content: ' ⚠ billing note';
    font-size: 10px;
    font-weight: 400;
    color: #dc2626;
    margin-left: 6px;
}

/* No results / add patient link */
.add-patient-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 4px;
    color: #7c3aed;
    font-size: 12px;
    font-weight: 500;
    text-decoration: none;
}
.add-patient-link:hover { text-decoration: underline; }
</style>

<?php
if (isset($_GET["res"])) {
    echo '<script>
    opener.document.theform.resname.value = "noresult";
    opener.document.theform.submit();
    window.self.close();
    </script>';
}
?>
</head>

<body>
<div class="page-wrap">
<div id="searchCriteria">
    <form method="post" name="theform" id="theform"
          action="find_patient_popup.php?<?php if (isset($_GET['pflag'])) echo 'pflag=0'; ?>">
        <div class="search-row">
            <label for="searchby"><?php echo htmlspecialchars(xl('Search by:'), ENT_NOQUOTES); ?></label>
            <select name="searchby" id="searchby">
                <option value="Last"><?php echo htmlspecialchars(xl('Name'), ENT_NOQUOTES); ?></option>
                <option value="Phone"<?php if (!empty($searchby) && $searchby=='Phone') echo ' selected'; ?>>
                    <?php echo htmlspecialchars(xl('Phone'), ENT_NOQUOTES); ?>
                </option>
                <option value="ID"<?php if (!empty($searchby) && $searchby=='ID') echo ' selected'; ?>>
                    <?php echo htmlspecialchars(xl('ID'), ENT_NOQUOTES); ?>
                </option>
                <option value="SSN"<?php if (!empty($searchby) && $searchby=='SSN') echo ' selected'; ?>>
                    <?php echo htmlspecialchars(xl('SSN'), ENT_NOQUOTES); ?>
                </option>
                <option value="DOB"<?php if (!empty($searchby) && $searchby=='DOB') echo ' selected'; ?>>
                    <?php echo htmlspecialchars(xl('DOB'), ENT_NOQUOTES); ?>
                </option>
            </select>

            <div class="search-input-wrap">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <input type="text" id="searchparm" name="searchparm"
                       autocomplete="off"
                       placeholder="<?php echo htmlspecialchars(xl('Type to search patients…'), ENT_QUOTES); ?>"
                       value="<?php echo attr($_REQUEST['searchparm'] ?? ''); ?>" />
                <div id="searchspinner">
                    <div class="spinner-ring"></div>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Status -->
<div id="searchstatus" class="idle">
    <?php if (!isset($_REQUEST['searchparm'])): ?>
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <?php echo htmlspecialchars(xl('Start typing to search patients'), ENT_NOQUOTES); ?>
    <?php endif; ?>
</div>

<!-- Results -->
<div id="results-wrap">
    <table id="searchResults">
        <thead>
            <tr>
                <th class="srName"><?php echo htmlspecialchars(xl('Name'), ENT_NOQUOTES); ?></th>
                <th class="srPhone"><?php echo htmlspecialchars(xl('Phone'), ENT_NOQUOTES); ?></th>
                <th class="srDOB"><?php echo htmlspecialchars(xl('DOB'), ENT_NOQUOTES); ?></th>
                <th class="srID"><?php echo htmlspecialchars(xl('ID'), ENT_NOQUOTES); ?></th>
            </tr>
        </thead>
        <tbody id="searchResultsBody">
            <?php if (is_countable($result) && count($result) > 0):
                foreach ($result as $iter):
                    $iterpid   = $iter['pid'];
                    $iterlname = $iter['lname'];
                    $iterfname = $iter['fname'];
                    $itermname = $iter['mname'];
                    $iterdob   = $iter['DOB'];
                    $trClass   = 'oneresult' . (!empty($iter['billing_note']) ? ' billing' : '');
                    $rowId     = htmlspecialchars($iterpid.'~'.$iterlname.'~'.$iterfname.'~'.$iterdob, ENT_QUOTES);
            ?>
            <tr class="<?php echo $trClass; ?>" id="<?php echo $rowId; ?>">
                <td class="srName"><?php echo htmlspecialchars($iterlname.', '.$iterfname.' '.$itermname, ENT_NOQUOTES); ?></td>
                <td class="srPhone"><?php echo htmlspecialchars($iter['phone_home'], ENT_NOQUOTES); ?></td>
                <td class="srDOB"><?php echo htmlspecialchars($iterdob, ENT_NOQUOTES); ?></td>
                <td class="srID"><?php echo htmlspecialchars($iter['pubpid'], ENT_NOQUOTES); ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
$(function () {
    $('#searchparm').focus();

    // ── Row click handler (attached to tbody so it works after AJAX re-render) ──
    $('#searchResultsBody').on('click', '.oneresult', function() {
        var parts = this.id.split('~');
        selpid(parts[0], parts[1], parts[2], parts[3]);
    });

    // ── Hover highlight ──
    $('#searchResultsBody').on('mouseenter', '.oneresult', function() { $(this).addClass('highlight'); });
    $('#searchResultsBody').on('mouseleave', '.oneresult', function() { $(this).removeClass('highlight'); });

    // ── DOB datepicker ──
    $('select[name="searchby"]').on('change', function () {
        if ($(this).val() === 'DOB') {
            $('#searchparm').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = true; ?>
                <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
            });
        } else {
            $('#searchparm').datetimepicker('destroy');
        }
        // Re-focus and trigger search on type change
        $('#searchparm').val('').focus();
        setStatus('idle', 'Start typing to search patients');
        $('#searchResultsBody').empty();
    });

    // ── Auto-search as user types ──
    var searchTimer = null;
    var lastQuery   = '';
    var minChars    = 2; // start searching after 2 chars

    $('#searchparm').on('input', function () {
        clearTimeout(searchTimer);
        var val = $(this).val().trim();

        if (val === lastQuery) return;

        if (val.length < minChars) {
            lastQuery = '';
            setStatus('idle', 'Type at least ' + minChars + ' characters to search…');
            $('#searchResultsBody').empty();
            return;
        }

        // Debounce: wait 300ms after user stops typing
        searchTimer = setTimeout(function () {
            doSearch(val);
        }, 300);
    });

    // Allow pressing Enter to search immediately
    $('#searchparm').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(searchTimer);
            var val = $(this).val().trim();
            if (val.length >= minChars) doSearch(val);
        }
    });

    // ── If page loaded with a search param already, show results ──
    <?php if (!empty($_REQUEST['searchparm']) && is_countable($result)): ?>
    updateStatus(<?php echo count($result); ?>);
    <?php endif; ?>
});

function doSearch(query) {
    var searchby = $('#searchby').val();
    lastQuery = query;

    setStatus('searching', '');
    $('#searchspinner').addClass('active');

    $.ajax({
        url: 'find_patient_popup.php',
        method: 'POST',
        data: {
            searchby:  searchby,
            searchparm: query,
            ajax: 1
        },
        success: function (html) {
            $('#searchspinner').removeClass('active');
            $('#searchResultsBody').html(html);
            var count = $('#searchResultsBody .oneresult').length;
            updateStatus(count, query);
        },
        error: function () {
            $('#searchspinner').removeClass('active');
            setStatus('error', 'Search failed. Please try again.');
        }
    });
}

function updateStatus(count, query) {
    if (count === 0) {
        var canAdd = <?php echo (!isset($_GET['pflag']) && AclMain::aclCheckCore('patients', 'demo', '', array('write','addonly'))) ? 'true' : 'false'; ?>;
        var msg = 'No records found.';
        if (canAdd) {
            msg += ' <a class="add-patient-link" href="find_patient_popup.php?res=noresult">' +
                   '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>' +
                   ' Add new patient</a>';
        }
        setStatus('error', msg, true);
    } else if (count >= 100) {
        setStatus('warning', 'More than 100 results — please narrow your search.');
    } else {
        setStatus('success', count + ' patient' + (count !== 1 ? 's' : '') + ' found');
    }
}

function setStatus(type, msg, isHtml) {
    var el = $('#searchstatus');
    el.removeClass('idle success warning error').addClass(type);
    if (type === 'searching') {
        el.html('<span style="color:#9ca3af">Searching…</span>');
    } else if (isHtml) {
        el.html(msg);
    } else {
        el.text(msg);
    }
}

function selpid(pid, lname, fname, dob) {
    if (opener.closed || !opener.setpatient) {
        alert("<?php echo htmlspecialchars(xl('The destination form was closed; I cannot act on your selection.'), ENT_QUOTES); ?>");
    } else {
        opener.setpatient(pid, lname, fname, dob);
    }
    dlgclose();
    return false;
}
</script>

<?php
// ── AJAX partial response — return only <tr> rows ──
if (!empty($_REQUEST['ajax'])) {
    $out = '';
    if (is_countable($result) && count($result) > 0) {
        foreach ($result as $iter) {
            $iterpid   = $iter['pid'];
            $iterlname = $iter['lname'];
            $iterfname = $iter['fname'];
            $itermname = $iter['mname'];
            $iterdob   = $iter['DOB'];
            $trClass   = 'oneresult' . (!empty($iter['billing_note']) ? ' billing' : '');
            $rowId     = htmlspecialchars($iterpid.'~'.$iterlname.'~'.$iterfname.'~'.$iterdob, ENT_QUOTES);
            $out .= '<tr class="'.$trClass.'" id="'.$rowId.'">';
            $out .= '<td class="srName">'.htmlspecialchars($iterlname.', '.$iterfname.' '.$itermname, ENT_NOQUOTES).'</td>';
            $out .= '<td class="srPhone">'.htmlspecialchars($iter['phone_home'], ENT_NOQUOTES).'</td>';
            $out .= '<td class="srDOB">'.htmlspecialchars($iterdob, ENT_NOQUOTES).'</td>';
            $out .= '<td class="srID">'.htmlspecialchars($iter['pubpid'], ENT_NOQUOTES).'</td>';
            $out .= '</tr>';
        }
    }
    // Return only the rows — JS handles status separately
    die($out);
}
?>

</div><!-- end page-wrap -->
</body>
</html>












































