<?php
/**
 * non_patient_finder.php
 * Finder for person table records (non-patients).
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

// ── AJAX: create new non-patient ─────────────────────────────────────────────
if (isset($_GET['ajax']) && isset($_GET['create'])) {
    header('Content-Type: application/json');

    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(array('success' => false, 'error' => 'Invalid CSRF token.'));
        exit;
    }

    $fname = trim($_POST['fname'] ?? '');
    $lname = trim($_POST['lname'] ?? '');

    if (!$fname || !$lname) {
        echo json_encode(array('success' => false, 'error' => 'First and last name are required.'));
        exit;
    }

    // ── Duplicate check: look in person table first, then patient_data ────────
    $dupes_person = array();
    $dp = sqlStatement(
        "SELECT id, fname, lname, dob, hphone FROM person
          WHERE lname = ? AND fname = ?
          ORDER BY id LIMIT 5",
        array($lname, $fname)
    );
    while ($r = sqlFetchArray($dp)) { $dupes_person[] = $r; }

    $dupes_patient = array();
    $dk = sqlStatement(
        "SELECT pid, fname, lname, DOB, phone_home FROM patient_data
          WHERE lname = ? AND fname = ?
          ORDER BY pid LIMIT 5",
        array($lname, $fname)
    );
    while ($r = sqlFetchArray($dk)) { $dupes_patient[] = $r; }

    // If duplicate check mode — return matches without inserting
    if (!empty($_POST['check_only'])) {
        echo json_encode(array(
            'success'        => true,
            'dupes_person'   => $dupes_person,
            'dupes_patient'  => $dupes_patient,
        ));
        exit;
    }

    // ── Insert new person record ──────────────────────────────────────────────
    sqlStatement(
        "INSERT INTO person (fname, lname, username, tran_date)
         VALUES (?, ?, ?, NOW())",
        array($fname, $lname, isset($_SESSION['authUser']) ? $_SESSION['authUser'] : 'system')
    );

    $new_id = (int)sqlQuery("SELECT LAST_INSERT_ID() AS id")['id'];

    echo json_encode(array(
        'success' => true,
        'id'      => $new_id,
    ));
    exit;
}

// ── AJAX: search ─────────────────────────────────────────────────────────────
if (isset($_GET['ajax'])) {

    $searchName   = isset($_GET['name'])   ? trim($_GET['name'])   : '';
    $searchDob    = isset($_GET['dob'])    ? trim($_GET['dob'])    : '';
    $searchPhone  = isset($_GET['hphone']) ? trim($_GET['hphone']) : '';
    $includeInact = !empty($_GET['include_inactive']) && $_GET['include_inactive'] === '1';

    $params = array();
    $where  = "WHERE 1=1";

    if ($searchName) {
        $where   .= " AND (lname LIKE ? OR fname LIKE ?)";
        $params[] = '%' . $searchName . '%';
        $params[] = '%' . $searchName . '%';
    }
    if ($searchDob) {
        $where   .= " AND dob LIKE ?";
        $params[] = '%' . $searchDob . '%';
    }
    if ($searchPhone) {
        $where   .= " AND hphone LIKE ?";
        $params[] = '%' . $searchPhone . '%';
    }
    if (!$includeInact) {
        // DATE column — avoid empty-string comparison which causes type errors
        $where .= " AND (inactv_date IS NULL OR inactv_date = '0000-00-00')";
    }

    // Exclude records already converted to patients
    $where .= " AND (
                    person.pid = 0
                    OR NOT EXISTS (
                        SELECT 1 FROM patient_data pd WHERE pd.pid = person.pid
                    )
                )";

    // Keep SELECT simple — avoid NULLIF/COALESCE on prepared statements
    $query = "SELECT id,
                     pid,
                     fname,
                     mi,
                     lname,
                     address1,
                     city,
                     state,
                     zip,
                     hphone,
                     dob,
                     gender,
                     inactv_date
              FROM person
              $where
              ORDER BY lname, fname
              LIMIT 100";

    $result = sqlStatement($query, $params);
    $rows = array();
    while ($row = sqlFetchArray($result)) {
        $rows[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt("Non-Patient Finder"); ?></title>
    <style>
        div#container_div { padding: 10px !important; }

        h2 {
            background: none !important;
            color: #000 !important;
            border: 0 !important;
            padding: 0 !important;
            font-weight: bold;
        }

        /* ── badge strip under title ─────────────────────────── */
        .np-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff7ed;
            border: 1px solid #fed7aa;
            color: #c2410c;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 12px;
            font-family: monospace;
            letter-spacing: .03em;
        }
        .np-badge-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #f97316;
        }

        /* ── table ───────────────────────────────────────────── */
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
        #pt_table thead tr { background: #fff7ed; }
        #pt_table th {
            font-weight: 600;
            color: #9a3412;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        #pt_table tbody tr:hover { background: #fff7ed; cursor: pointer; }

        .person-link {
            color: #c2410c;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            text-transform: uppercase;
        }
        .person-link:hover { color: #ea580c; }

        #pt_count {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
            min-height: 18px;
        }

        /* ── form ─────────────────────────────────────────────── */
        .form-row  { display: flex; flex-wrap: wrap; gap: 12px; }
        .form-group {
            display: flex; flex-direction: column;
            flex: 1; min-width: 160px;
        }
        .form-group label {
            font-size: 11px; font-weight: 600; color: #374151;
            margin-bottom: 4px; text-transform: uppercase;
            letter-spacing: .04em;
        }
        .form-group input {
            padding: 7px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 13px; outline: none;
        }
        .form-group input:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 2px rgba(249,115,22,.12);
        }
        .form-check {
            display: flex; align-items: center;
            gap: 6px; font-size: 13px; margin-top: 8px;
        }
        .form-check input {
            width: 15px; height: 15px;
            accent-color: #f97316;
        }

/* ── Add Non-Patient modal ────────────────────────────────────────────────── */
.np-modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 800;
    align-items: center; justify-content: center;
}
.np-modal-overlay.open { display: flex; }
.np-modal {
    background: #fff; border-radius: 12px;
    padding: 28px 32px; width: 92%; max-width: 440px;
    box-shadow: 0 20px 60px rgba(0,0,0,.25);
    animation: npModalIn .18s ease;
}
@keyframes npModalIn { from{transform:scale(.95);opacity:0} to{transform:scale(1);opacity:1} }
.np-modal-title {
    font-size: 16px; font-weight: 700; color: #111827;
    margin-bottom: 4px;
}
.np-modal-sub { font-size: 12px; color: #6b7280; margin-bottom: 20px; }
.np-modal label {
    display: block; font-size: 11px; font-weight: 600;
    color: #374151; text-transform: uppercase;
    letter-spacing: .04em; margin-bottom: 4px;
}
.np-modal input[type=text] {
    width: 100%; padding: 8px 11px;
    border: 1px solid #d1d5db; border-radius: 7px;
    font-size: 13px; outline: none; margin-bottom: 14px;
    font-family: inherit;
}
.np-modal input[type=text]:focus {
    border-color: #f97316;
    box-shadow: 0 0 0 3px rgba(249,115,22,.12);
}
.np-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 6px; }
.btn-modal-cancel {
    background: transparent; color: #6b7280;
    border: 1px solid #d1d5db; border-radius: 7px;
    padding: 9px 18px; font-size: 13px; font-weight: 600;
    cursor: pointer; transition: all .15s;
}
.btn-modal-cancel:hover { border-color: #9ca3af; color: #374151; }
.btn-modal-save {
    background: #f97316; color: #fff;
    border: none; border-radius: 7px;
    padding: 9px 22px; font-size: 13px; font-weight: 700;
    cursor: pointer; transition: background .15s;
    display: inline-flex; align-items: center; gap: 7px;
}
.btn-modal-save:hover { background: #ea580c; }
.btn-modal-save:disabled { opacity: .4; cursor: not-allowed; }
/* duplicate list inside modal */
.np-dupe-box {
    background: #fff7ed; border: 1px solid #fed7aa;
    border-radius: 8px; padding: 12px 14px; margin-bottom: 14px;
}
.np-dupe-title { font-size: 11px; font-weight: 700; color: #c2410c; margin-bottom: 8px; text-transform: uppercase; letter-spacing: .04em; }
.np-dupe-row {
    display: flex; align-items: center; justify-content: space-between;
    font-size: 12px; padding: 5px 0; border-bottom: 1px solid #fed7aa;
}
.np-dupe-row:last-child { border-bottom: none; }
.np-dupe-name { font-weight: 600; color: #111827; }
.np-dupe-meta { color: #6b7280; font-size: 11px; }
.btn-dupe-open {
    font-size: 11px; font-weight: 600; color: #f97316;
    background: none; border: none; cursor: pointer; padding: 2px 6px;
    text-decoration: underline;
}
/* spinner in button */
.btn-spin {
    width: 14px; height: 14px; border: 2px solid rgba(255,255,255,.4);
    border-top-color: #fff; border-radius: 50%;
    animation: spin .6s linear infinite; display: none;
}
@keyframes spin { to { transform: rotate(360deg); } }

        /* suppress OpenEMR injected noise */
        tbody h1, tbody h2, tbody label, tbody input,
        tbody button#create_patient_btn1,
        tbody div.form-row { display: none !important; }
    </style>
</head>
<body>
<div id="container_div" class="container mt-3">

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:30px;margin-bottom:8px;">
        <div>
            <h2><?php echo xlt("Non-Patient Finder"); ?></h2>
            <div style="margin-top:6px;">
                <span class="np-badge">
                    <span class="np-badge-dot"></span>
                    person table — not yet in patient_data
                </span>
            </div>
        </div>
        <button id="btn-add-np"
                style="background:#f97316;color:#fff;border:none;border-radius:8px;
                       padding:0 20px;height:38px;font-size:13px;font-weight:700;
                       cursor:pointer;display:inline-flex;align-items:center;gap:8px;
                       transition:background .15s;"
                onmouseover="this.style.background='#ea580c'"
                onmouseout="this.style.background='#f97316'"
                onclick="openAddModal()">
            + <?php echo xlt('Add Non-Patient'); ?>
        </button>
    </div>
    <?php $csrf_finder = CsrfUtils::collectCsrfToken(); ?>

    <div style="margin-bottom:16px;margin-top:12px;font-size:12px;color:#6b7280;line-height:1.6;">
        <?php echo xlt("These records exist in the person table but have not been converted to full OpenEMR patients. Click any row to view their summary and optionally upgrade them to a patient."); ?>
    </div>

    <form method="GET" id="filterForm" class="mb-3" onsubmit="return false">
        <div class="form-row mb-3">
            <div class="form-group">
                <label for="name"><?php echo xlt("Name"); ?></label>
                <input type="text" name="name" id="name"
                       value="<?php echo attr(isset($_GET['name']) ? $_GET['name'] : ''); ?>"
                       placeholder="<?php echo xla('Last or first name'); ?>">
            </div>

            <div class="form-group">
                <label for="dob"><?php echo xlt("Date of Birth"); ?></label>
                <input type="text" name="dob" id="dob"
                       value="<?php echo attr(isset($_GET['dob']) ? $_GET['dob'] : ''); ?>"
                       placeholder="YYYY-MM-DD">
            </div>
            <div class="form-group">
                <label for="hphone"><?php echo xlt("Home Phone"); ?></label>
                <input type="text" name="hphone" id="hphone"
                       value="<?php echo attr(isset($_GET['hphone']) ? $_GET['hphone'] : ''); ?>">
            </div>
        </div>
        <div class="form-check">
            <input type="checkbox" id="include_inactive" name="include_inactive"
                   <?php echo !empty($_GET['include_inactive']) ? 'checked' : ''; ?>>
            <label for="include_inactive"><?php echo xlt("Include Inactive Records"); ?></label>
        </div>
    </form>

    <div id="pt_count"></div>

    <div class="table-responsive">
        <table id="pt_table">
            <thead>
                <tr>

                    <th><?php echo xlt("Last Name"); ?></th>
                    <th><?php echo xlt("First Name"); ?></th>
                    <th style="width:40px"><?php echo xlt("MI"); ?></th>
                    <th style="width:110px"><?php echo xlt("DOB"); ?></th>
                    <th style="width:80px"><?php echo xlt("Gender"); ?></th>
                    <th><?php echo xlt("City"); ?></th>
                    <th style="width:130px"><?php echo xlt("Home Phone"); ?></th>
                    <th style="width:80px"><?php echo xlt("Status"); ?></th>
                </tr>
            </thead>
            <tbody id="pt_tbody">
                <tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:24px">
                    <?php echo xlt("Type a name, date of birth, or phone number to search."); ?>
                </td></tr>
            </tbody>
        </table>
    </div>

</div><!-- /container -->

<script>
(function () {
    var DEBOUNCE_MS = 250;
    var webRoot     = <?php echo json_encode($web_root); ?>;
    var timer       = null;
    var lastXHR     = null;

    // ── Open detail page in same RTop frame ──────────────────────────────────
    function openPerson(id) {
        top.restoreSession();
        if (top.RTop && top.RTop.document && top.RTop.document.body) {
            top.RTop.document.body.style.opacity = '0.6';
            top.RTop.document.body.style.pointerEvents = 'none';
        }
        setTimeout(function () {
            top.RTop.location =
                webRoot + '/interface/main/finder/non_patient_detail.php?id=' +
                encodeURIComponent(id);
        }, 10);
    }

    // ── Render rows ──────────────────────────────────────────────────────────
    function renderRows(rows) {
        var tbody = document.getElementById('pt_tbody');
        var count = document.getElementById('pt_count');

        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#9ca3af;padding:24px">'
                + <?php echo json_encode(xlt('No records found.')); ?>
                + '</td></tr>';
            count.textContent = '';
            return;
        }

        var html = '';
        for (var i = 0; i < rows.length; i++) {
            var r  = rows[i];
            var inactive = r.inactv_date && r.inactv_date !== '0000-00-00' && r.inactv_date !== '';
            var statusBadge = inactive
                ? '<span style="background:#fee2e2;color:#991b1b;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:600;">Inactive</span>'
                : '<span style="background:#dcfce7;color:#166534;border-radius:10px;padding:2px 8px;font-size:10px;font-weight:600;">Active</span>';

            var dob = r.dob && r.dob !== '0000-00-00' ? esc(r.dob) : '<span style="color:#d1d5db">—</span>';
            html += '<tr onclick="openPerson(' + encodeURIComponent(r.id) + ')">'
                  + '<td><span class="person-link">' + esc(r.lname) + '</span></td>'
                  + '<td><span class="person-link">' + esc(r.fname) + '</span></td>'
                  + '<td>' + esc(r.mi) + '</td>'
                  + '<td>' + dob + '</td>'
                  + '<td>' + esc(r.gender) + '</td>'
                  + '<td>' + esc(r.city) + '</td>'
                  + '<td style="font-family:monospace">' + esc(r.hphone) + '</td>'
                  + '<td>' + statusBadge + '</td>'
                  + '</tr>';
        }

        tbody.innerHTML = html;
        count.textContent = rows.length === 100
            ? <?php echo json_encode(xlt('Showing first 100 results — refine your search to narrow down.')); ?>
            : rows.length + ' ' + <?php echo json_encode(xlt('record(s) found')); ?>;
    }

    // ── Fetch ─────────────────────────────────────────────────────────────────
    function loadRecords() {
        if (lastXHR) { lastXHR.abort(); }

        document.getElementById('pt_count').innerHTML =
            '<span style="display:inline-block;width:14px;height:14px;border:2px solid #fed7aa;border-top-color:#f97316;border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle"></span>'
            + ' <span style="color:#9ca3af;font-size:12px"><?php echo xlt("Searching…"); ?></span>';

        var params = 'ajax=1'
            + '&name='             + encodeURIComponent(document.getElementById('name').value.trim())
            + '&dob='              + encodeURIComponent(document.getElementById('dob').value.trim())
            + '&hphone='           + encodeURIComponent(document.getElementById('hphone').value.trim())
            + '&include_inactive=' + (document.getElementById('include_inactive').checked ? '1' : '0');

        var xhr = new XMLHttpRequest();
        lastXHR = xhr;
        xhr.open('GET', '?' + params, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 0)   return;
            if (xhr.status !== 200) {
                document.getElementById('pt_count').textContent = '<?php echo xlt("Error loading results."); ?>';
                return;
            }
            try   { renderRows(JSON.parse(xhr.responseText)); }
            catch (e) { document.getElementById('pt_count').textContent = '<?php echo xlt("Error loading results."); ?>'; }
        };
        xhr.send();
    }

    function onInput() {
        clearTimeout(timer);
        timer = setTimeout(loadRecords, DEBOUNCE_MS);
    }

    ['name','dob','hphone'].forEach(function (id) {
        document.getElementById(id).addEventListener('input', onInput);
    });
    document.getElementById('include_inactive').addEventListener('change', loadRecords);

    function esc(s) {
        if (!s) return '';
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    window.openPerson = openPerson;

    loadRecords();
}());
</script>
<!-- Add Non-Patient Modal -->
<div class="np-modal-overlay" id="np-add-modal">
  <div class="np-modal">
    <div class="np-modal-title">➕ <?php echo xlt('Add Non-Patient'); ?></div>
    <div class="np-modal-sub"><?php echo xlt('Enter first and last name. Duplicates will be flagged before saving.'); ?></div>

    <div id="np-dupe-area" style="display:none"></div>

    <label for="np-fname"><?php echo xlt('First Name'); ?> <span style="color:#ef4444">*</span></label>
    <input type="text" id="np-fname" placeholder="<?php echo xla('First name'); ?>" autocomplete="off">

    <label for="np-lname"><?php echo xlt('Last Name'); ?> <span style="color:#ef4444">*</span></label>
    <input type="text" id="np-lname" placeholder="<?php echo xla('Last name'); ?>" autocomplete="off">

    <div id="np-modal-err" style="display:none;color:#dc2626;font-size:12px;margin-bottom:10px"></div>

    <div class="np-modal-actions">
      <button class="btn-modal-cancel" onclick="closeAddModal()"><?php echo xlt('Cancel'); ?></button>
      <button class="btn-modal-save" id="btn-np-save" onclick="saveNewPerson()">
        <div class="btn-spin" id="np-save-spin"></div>
        <?php echo xlt('Check & Save'); ?>
      </button>
    </div>
  </div>
</div>

<script>
var NP_FINDER_CSRF = <?php echo json_encode($csrf_finder); ?>;
var NP_WEB_ROOT    = <?php echo json_encode($web_root); ?>;
var _npDupesShown  = false;

function openAddModal() {
    document.getElementById('np-fname').value = '';
    document.getElementById('np-lname').value = '';
    document.getElementById('np-modal-err').style.display = 'none';
    document.getElementById('np-dupe-area').style.display = 'none';
    document.getElementById('np-dupe-area').innerHTML = '';
    _npDupesShown = false;
    setBtnText('Check & Save');
    document.getElementById('np-add-modal').classList.add('open');
    setTimeout(function(){ document.getElementById('np-fname').focus(); }, 80);
}
function closeAddModal() {
    document.getElementById('np-add-modal').classList.remove('open');
}
function setBtnText(t) {
    var btn = document.getElementById('btn-np-save');
    // keep spinner, replace text node
    var spin = document.getElementById('np-save-spin');
    btn.textContent = '';
    btn.appendChild(spin);
    btn.appendChild(document.createTextNode(' ' + t));
}
function setSpin(on) {
    document.getElementById('np-save-spin').style.display = on ? 'inline-block' : 'none';
    document.getElementById('btn-np-save').disabled = on;
}

function saveNewPerson() {
    var fname = document.getElementById('np-fname').value.trim();
    var lname = document.getElementById('np-lname').value.trim();
    var errEl = document.getElementById('np-modal-err');
    errEl.style.display = 'none';

    if (!fname || !lname) {
        errEl.textContent = <?php echo json_encode(xlt('First and last name are required.')); ?>;
        errEl.style.display = 'block';
        return;
    }

    setSpin(true);

    // ── Step 1: check for duplicates ─────────────────────────────────────────
    if (!_npDupesShown) {
        var fd = new FormData();
        fd.append('csrf_token_form', NP_FINDER_CSRF);
        fd.append('fname', fname);
        fd.append('lname', lname);
        fd.append('check_only', '1');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '?ajax=1&create=1', true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            setSpin(false);
            if (xhr.status !== 200) {
                errEl.textContent = <?php echo json_encode(xlt('Server error. Please try again.')); ?>;
                errEl.style.display = 'block';
                return;
            }
            var resp;
            try { resp = JSON.parse(xhr.responseText); } catch(e) { return; }

            var hasDupes = (resp.dupes_person && resp.dupes_person.length) ||
                           (resp.dupes_patient && resp.dupes_patient.length);

            if (hasDupes) {
                renderDupes(resp.dupes_person, resp.dupes_patient, fname, lname);
                _npDupesShown = true;
                setBtnText(<?php echo json_encode(xlt('Save Anyway')); ?>);
            } else {
                // No dupes — go straight to insert
                doInsert(fname, lname);
            }
        };
        xhr.send(fd);
        return;
    }

    // ── Step 2: user confirmed after seeing dupes — insert ───────────────────
    doInsert(fname, lname);
}

function doInsert(fname, lname) {
    setSpin(true);
    var fd = new FormData();
    fd.append('csrf_token_form', NP_FINDER_CSRF);
    fd.append('fname', fname);
    fd.append('lname', lname);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '?ajax=1&create=1', true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        setSpin(false);
        if (xhr.status !== 200) { return; }
        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch(e) { return; }
        if (!resp.success) {
            document.getElementById('np-modal-err').textContent = resp.error || 'Error';
            document.getElementById('np-modal-err').style.display = 'block';
            return;
        }
        closeAddModal();
        // Navigate to the new record's detail page
        openPerson(resp.id);
    };
    xhr.send(fd);
}

function renderDupes(person_dupes, patient_dupes, fname, lname) {
    var area = document.getElementById('np-dupe-area');
    var html = '';

    if (person_dupes && person_dupes.length) {
        html += '<div class="np-dupe-box">';
        html += '<div class="np-dupe-title">⚠ ' + <?php echo json_encode(xlt('Existing Non-Patient Records')); ?> + '</div>';
        person_dupes.forEach(function(r) {
            html += '<div class="np-dupe-row">'
                  + '<span><span class="np-dupe-name">' + esc(r.fname) + ' ' + esc(r.lname) + '</span>'
                  + (r.dob   ? ' <span class="np-dupe-meta">DOB: ' + esc(r.dob) + '</span>' : '')
                  + (r.hphone? ' <span class="np-dupe-meta">📞 ' + esc(r.hphone) + '</span>' : '')
                  + '</span>'
                  + '<button class="btn-dupe-open" onclick="closeAddModal();openPerson(' + encodeURIComponent(r.id) + ')">'
                  + <?php echo json_encode(xlt('View')); ?>
                  + '</button></div>';
        });
        html += '</div>';
    }
    if (patient_dupes && patient_dupes.length) {
        html += '<div class="np-dupe-box" style="background:#eff6ff;border-color:#bfdbfe">';
        html += '<div class="np-dupe-title" style="color:#1d4ed8">⚠ ' + <?php echo json_encode(xlt('Existing Patient Records')); ?> + '</div>';
        patient_dupes.forEach(function(r) {
            html += '<div class="np-dupe-row" style="border-color:#bfdbfe">'
                  + '<span><span class="np-dupe-name">' + esc(r.fname) + ' ' + esc(r.lname) + '</span>'
                  + (r.DOB        ? ' <span class="np-dupe-meta">DOB: ' + esc(r.DOB) + '</span>' : '')
                  + (r.phone_home ? ' <span class="np-dupe-meta">📞 ' + esc(r.phone_home) + '</span>' : '')
                  + '</span>'
                  + '<span class="np-dupe-meta" style="color:#1d4ed8">PID ' + esc(String(r.pid)) + '</span>'
                  + '</div>';
        });
        html += '</div>';
    }

    area.innerHTML = html;
    area.style.display = 'block';
}

function esc(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Close modal on overlay click
document.getElementById('np-add-modal').addEventListener('click', function(e) {
    if (e.target === this) closeAddModal();
});
// Enter key submits
['np-fname','np-lname'].forEach(function(id) {
    document.getElementById(id).addEventListener('keydown', function(e) {
        if (e.key === 'Enter') saveNewPerson();
    });
});
</script>
</body>
</html>
