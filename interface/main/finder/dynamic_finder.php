<?php

/**
 * dynamic_finder.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(dirname(__FILE__) . "/../../globals.php");
require_once "$srcdir/user.inc.php";
require_once "$srcdir/options.inc.php";

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// ── AJAX handler — runs before any HTML output ────────────────────────────────
if (isset($_GET['ajax'])) {

    $searchName  = isset($_GET['name'])       ? trim($_GET['name'])       : '';
    $searchPid   = isset($_GET['pubpid'])      ? trim($_GET['pubpid'])     : '';
    $searchDob   = isset($_GET['dob'])         ? trim($_GET['dob'])        : '';
    $searchPhone = isset($_GET['phone_home'])  ? trim($_GET['phone_home']) : '';
    $includeInactive = !empty($_GET['include_inactive']) && $_GET['include_inactive'] === '1';

    // Require at least 1 character in some field before querying
    // Allow full patient list when no filters entered
    $hasInput = true;

    $rows = [];

    $params = [];
$where  = "WHERE 1=1";

if ($searchName) {
    $where   .= " AND (lname LIKE ? OR fname LIKE ?)";
    $params[] = '%' . $searchName . '%';
    $params[] = '%' . $searchName . '%';
}

if ($searchPid) {
    $where   .= " AND pubpid LIKE ?";
    $params[] = '%' . $searchPid . '%';
}

if ($searchDob) {
    $where   .= " AND DOB LIKE ?";
    $params[] = '%' . $searchDob . '%';
}

if ($searchPhone) {
    $where   .= " AND phone_home LIKE ?";
    $params[] = '%' . $searchPhone . '%';
}

if (!$includeInactive) {
    $where .= " AND (deceased_date IS NULL OR deceased_date = '0000-00-00')";
}

$query = "SELECT DISTINCT
            pid,
            pubpid,
            lname,
            fname,
            mname,
            DOB,
            phone_home
          FROM patient_data
          $where
          ORDER BY lname, fname
          LIMIT 100";

$result = sqlStatement($query, $params);

while ($row = sqlFetchArray($result)) {
    $rows[] = $row;
}


    // Return JSON — JS builds the table rows, no HTML parsing overhead
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); /* no datatables — not used, saves ~200 KB */ ?>
    <title><?php echo xlt("Patient Finder"); ?></title>
    <style>
        div#container_div { padding: 10px !important; }

        h2 {
            background: none !important;
            color: #000 !important;
            border: 0 !important;
            padding: 0 !important;
            font-weight: bold;
        }

        #pt_table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        #pt_table th,
        #pt_table td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding: 8px 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 13px;
        }

        #pt_table thead tr {
            background: #f3f4f6;
        }

        #pt_table th {
            font-weight: 600;
            color: #374151;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }

        #pt_table tbody tr:hover { background: #f5f3ff; cursor: pointer; }

        .patient-link {
            color: #47377a;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            text-transform:uppercase;
        }
        .patient-link:hover { color: #7c3aed;}

        #pt_count {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
            min-height: 18px;
        }

        /* Loading spinner */
        #pt_spinner {
            display: none;
            width: 18px; height: 18px;
            border: 2px solid #e5e7eb;
            border-top-color: #6b4fd8;
            border-radius: 50%;
            animation: spin .6s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .form-row { display: flex; flex-wrap: wrap; gap: 12px; }
        .form-group { display: flex; flex-direction: column; flex: 1; min-width: 160px; }
        .form-group label { font-size: 11px; font-weight: 600; color: #374151; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .04em; }
        .form-group input { padding: 7px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 13px; outline: none; }
        .form-group input:focus { border-color: #6b4fd8; box-shadow: 0 0 0 2px rgba(107,79,216,.12); }
        .form-check { display: flex; align-items: center; gap: 6px; font-size: 13px; margin-top: 8px; }
        .form-check input { width: 15px; height: 15px; accent-color: #6b4fd8; }

        /* hide OpenEMR UI noise injected inside frames */
        tbody h1, tbody h2, tbody label, tbody input,
        tbody button#create_patient_btn1, tbody div.form-row { display: none !important; }
    </style>
</head>
<body>
<div id="container_div" class="container mt-3">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:30px;margin-bottom:16px;">
        <h2><?php echo xlt("Patient Finder"); ?></h2>
        <?php if (AclMain::aclCheckCore('patients', 'demo', '', ['write', 'addonly'])): ?>
            <button class="btn btn-primary btn-add"
                    style="background:#6b4fd8!important;"
                    onclick="top.restoreSession(); top.RTop.location='<?php echo $web_root ?>/interface/new/new.php'">
                <?php echo xlt('Add New Patient'); ?>
            </button>
        <?php endif; ?>
    </div>

    <form method="GET" id="filterForm" class="mb-3" onsubmit="return false">
        <div class="form-row mb-3">
            <div class="form-group">
                <label for="name"><?php echo xlt("Name"); ?></label>
                <input type="text" name="name" id="name"
                       value="<?php echo attr($_GET['name'] ?? ''); ?>"
                       placeholder="<?php echo xla('Last or first name'); ?>">
            </div>
            <div class="form-group">
                <label for="pubpid"><?php echo xlt("Patient No."); ?></label>
                <input type="text" name="pubpid" id="pubpid"
                       value="<?php echo attr($_GET['pubpid'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label for="dob"><?php echo xlt("Date of Birth"); ?></label>
                <input type="text" name="dob" id="dob"
                       value="<?php echo attr($_GET['dob'] ?? ''); ?>"
                       placeholder="YYYY-MM-DD">
            </div>
            <div class="form-group">
                <label for="phone_home"><?php echo xlt("Home Phone"); ?></label>
                <input type="text" name="phone_home" id="phone_home"
                       value="<?php echo attr($_GET['phone_home'] ?? ''); ?>">
            </div>
        </div>
        <div class="form-check">
            <input type="checkbox" id="include_inactive" name="include_inactive"
                   <?php echo !empty($_GET['include_inactive']) ? 'checked' : ''; ?>>
            <label for="include_inactive"><?php echo xlt("Include Inactive Patients"); ?></label>
        </div>
    </form>

    <div id="pt_count"></div>

    <div class="table-responsive">
        <table id="pt_table">
            <thead>
                <tr>
                    <th style="width:100px"><?php echo xlt("Patient No."); ?></th>
                    <th><?php echo xlt("Last Name"); ?></th>
                    <th><?php echo xlt("First Name"); ?></th>
                    <th style="width:50px"><?php echo xlt("MI"); ?></th>
                    <th style="width:110px"><?php echo xlt("DOB"); ?></th>
                    <th style="width:140px"><?php echo xlt("Home Phone"); ?></th>
                </tr>
            </thead>
            <tbody id="pt_tbody">
                <tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:24px">
                    <?php echo xlt("Type a name, ID, date of birth, or phone number to search."); ?>
                </td></tr>
            </tbody>
        </table>
    </div>

</div>

<script>
(function () {
    // ── Config ────────────────────────────────────────────────────────────────
    var DEBOUNCE_MS  = 250;   // wait 250 ms after last keystroke before firing
    var MIN_CHARS    = 1;     // minimum characters before searching
    var webRoot      = <?php echo json_encode($web_root); ?>;

    // ── State ─────────────────────────────────────────────────────────────────
    var timer   = null;
    var lastXHR = null;       // abort in-flight request if user keeps typing

   
// ── Open patient in demographics WITHOUT triggering Visit History tab ─────
function openPatient(pid) {

top.restoreSession();

// Give instant visual response
if (top.RTop && top.RTop.document && top.RTop.document.body) {
    top.RTop.document.body.style.opacity = '0.6';
    top.RTop.document.body.style.pointerEvents = 'none';
}

setTimeout(function () {

    top.RTop.location =
        webRoot +
        '/interface/patient_file/summary/demographics.php?set_pid=' +
        encodeURIComponent(pid);

}, 10);
}



    // ── Build table rows from JSON array ─────────────────────────────────────
    function renderRows(rows) {
        var tbody = document.getElementById('pt_tbody');
        var count = document.getElementById('pt_count');

        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#9ca3af;padding:24px">'
                + <?php echo json_encode(xlt('No patients found.')); ?>
                + '</td></tr>';
            count.textContent = '';
            return;
        }

        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var r   = rows[i];
            var pid = r.pid;
            // Each row fires openPatient() on click — no <a href> that could
            // trigger OpenEMR's tab system into opening Visit History.
            html += '<tr onclick="openPatient(' + encodeURIComponent(pid) + ')">'
                  + '<td>' + esc(r.pubpid) + '</td>'
                  + '<td><span class="patient-link">' + esc(r.lname) + '</span></td>'
                  + '<td><span class="patient-link">' + esc(r.fname) + '</span></td>'
                  + '<td>' + esc(r.mname) + '</td>'
                  + '<td>' + esc(r.DOB)   + '</td>'
                  + '<td>' + esc(r.phone_home) + '</td>'
                  + '</tr>';
        }

        tbody.innerHTML = html;
        count.textContent = rows.length === 100
            ? <?php echo json_encode(xlt('Showing first 100 results — refine your search to narrow down.')); ?>
            : rows.length + ' ' + <?php echo json_encode(xlt('patient(s) found')); ?>;
    }

    // ── Fetch ─────────────────────────────────────────────────────────────────
    function loadPatients() {
        var name   = document.getElementById('name').value.trim();
        var pubpid = document.getElementById('pubpid').value.trim();
        var dob    = document.getElementById('dob').value.trim();
        var phone  = document.getElementById('phone_home').value.trim();
        var inactive = document.getElementById('include_inactive').checked ? '1' : '0';

    

        // Abort previous in-flight XHR
        if (lastXHR) { lastXHR.abort(); }

        // Show spinner inside count area
        document.getElementById('pt_count').innerHTML =
            '<span id="pt_spinner" style="display:inline-block;width:14px;height:14px;border:2px solid #e5e7eb;border-top-color:#6b4fd8;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle"></span>'
            + ' <span style="color:#9ca3af;font-size:12px"><?php echo xlt("Searching…"); ?></span>';

        var params = 'ajax=1'
            + '&name='         + encodeURIComponent(name)
            + '&pubpid='       + encodeURIComponent(pubpid)
            + '&dob='          + encodeURIComponent(dob)
            + '&phone_home='   + encodeURIComponent(phone)
            + '&include_inactive=' + inactive;

        var xhr = new XMLHttpRequest();
        lastXHR = xhr;
        xhr.open('GET', '?' + params, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 0) return; // aborted
            if (xhr.status !== 200) {
                document.getElementById('pt_count').textContent = '<?php echo xlt("Error loading results."); ?>';
                return;
            }
            try {
                var data = JSON.parse(xhr.responseText);
                renderRows(data);
            } catch (e) {
                document.getElementById('pt_count').textContent = '<?php echo xlt("Error loading results."); ?>';
            }
        };
        xhr.send();
    }

    // ── Debounced input handler ───────────────────────────────────────────────
    function onInput() {
        clearTimeout(timer);
        timer = setTimeout(loadPatients, DEBOUNCE_MS);
    }

    // ── Attach listeners ─────────────────────────────────────────────────────
    ['name','pubpid','dob','phone_home'].forEach(function(id) {
        document.getElementById(id).addEventListener('input', onInput);
    });
    document.getElementById('include_inactive').addEventListener('change', loadPatients);

    // ── Helpers ───────────────────────────────────────────────────────────────
    function esc(s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    // Expose openPatient for onclick in generated rows
    window.openPatient = openPatient;

    loadPatients();

}());
</script>

<form name='fnew' method='post' target='_blank'
      action='../main_screen.php?auth=login&site=<?php echo attr_url($_SESSION['site_id']); ?>'>
    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
    <input type='hidden' name='patientID' value='0'>
</form>
</body>
</html>
