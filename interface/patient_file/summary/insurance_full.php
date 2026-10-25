<?php
/**
 * insurance_full.php — MDItek standalone insurance edit page.
 * All insurance fields preserved. Styled like the Insurance/Dates mockup.
 * Saves via demographics_save.php (same save handler, same form fields).
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/pid.inc.php");
require_once("$srcdir/patient.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$set_pid = $_GET["set_pid"] ?? ($_GET["pid"] ?? null);
if ($set_pid && $set_pid != $_SESSION["pid"]) {
    setpid($set_pid);
}

$result = getPatientData($pid, "*, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");

if ($pid) {
    if (!AclMain::aclCheckCore('patients', 'demo', '', 'write')) {
        die(xlt('Updating demographics is not authorized.'));
    }
    if ($result['squad'] && !AclMain::aclCheckCore('squads', $result['squad'])) {
        die(xlt('You are not authorized to access this squad.'));
    }
} else {
    if (!AclMain::aclCheckCore('patients', 'demo', '', array('write','addonly'))) {
        die(xlt('Adding demographics is not authorized.'));
    }
}

if ($GLOBALS['insurance_information'] != '0') {
    $insurancei = getInsuranceProvidersExtra();
} else {
    $insurancei = getInsuranceProviders();
}

if ($GLOBALS['insurance_only_one']) {
    $insurance_array    = array('primary');
    $insurance_headings = array(xl("Primary Insurance"));
} else {
    $insurance_array    = array('primary', 'secondary', 'tertiary');
    $insurance_headings = array(xl("Primary Insurance"), xl("Secondary Insurance"), xl("Tertiary Insurance"));
}

$insurance_info    = array();
$insurance_info[1] = getInsuranceData($pid, "primary");
$insurance_info[2] = getInsuranceData($pid, "secondary");
$insurance_info[3] = getInsuranceData($pid, "tertiary");
?>
<!DOCTYPE html>
<html>
<head>
<?php Header::setupHeader(['datetime-picker','common','select2']); ?>
<title><?php echo xlt('Insurance'); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>

<script>
var insurance_index  = 0;
var mypcc = <?php echo js_escape($GLOBALS['phone_country_code']); ?>;

// Patient data for "Self" autopopulate (injected server-side)
var patientData = {
    form_fname:             <?php echo js_escape($result['fname']            ?? ''); ?>,
    form_mname:             <?php echo js_escape($result['mname']            ?? ''); ?>,
    form_lname:             <?php echo js_escape($result['lname']            ?? ''); ?>,
    form_street:            <?php echo js_escape($result['street']           ?? ''); ?>,
    form_street_line_2:     <?php echo js_escape($result['street_line_2']    ?? ''); ?>,
    form_city:              <?php echo js_escape($result['city']             ?? ''); ?>,
    form_state:             <?php echo js_escape($result['state']            ?? ''); ?>,
    form_postal_code:       <?php echo js_escape($result['postal_code']      ?? ''); ?>,
    form_country_code:      <?php echo js_escape($result['country_code']     ?? ''); ?>,
    form_phone_home:        <?php echo js_escape($result['phone_home']       ?? ''); ?>,
    form_DOB:               <?php echo js_escape($result['DOB_YMD']          ?? ''); ?>,
    form_ss:                <?php echo js_escape($result['ss']               ?? ''); ?>,
    form_sex:               <?php echo js_escape($result['sex']              ?? ''); ?>,
    form_em_name:           <?php echo js_escape($result['em_name']          ?? ''); ?>,
    form_em_street:         <?php echo js_escape($result['em_street']        ?? ''); ?>,
    form_em_street_line_2:  <?php echo js_escape($result['em_street_line_2'] ?? ''); ?>,
    form_em_city:           <?php echo js_escape($result['em_city']          ?? ''); ?>,
    form_em_state:          <?php echo js_escape($result['em_state']         ?? ''); ?>,
    form_em_postal_code:    <?php echo js_escape($result['em_postal_code']   ?? ''); ?>,
    form_em_country:        <?php echo js_escape($result['em_country']       ?? ''); ?>
};

$(function () {
    // Tab switching
    $('.ins-tab-btn').on('click', function() {
        var tab = $(this).data('tab');
        $('.ins-tab-btn').removeClass('active');
        $(this).addClass('active');
        $('.ins-tab-panel').hide();
        $('#tab-' + tab).show();
    });

    // Show first tab
    $('.ins-tab-btn:first').click();

    // Datepickers
    $('.datepicker').datetimepicker({
        <?php $datetimepicker_timepicker = false; $datetimepicker_showseconds = false; $datetimepicker_formatInput = true; $datetimepicker_minDate = false; $datetimepicker_maxDate = false; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });

    // Insurance provider select2
    $(".ins-provider-sel").select2({
        theme: "bootstrap4",
        dropdownAutoWidth: true,
        width: 'resolve',
        <?php require($GLOBALS['srcdir'] . '/js/xl/select2.js.php'); ?>
    });

    $(".sel2").select2({
        theme: "bootstrap4", dropdownAutoWidth: true, width: 'resolve',
        <?php require($GLOBALS['srcdir'] . '/js/xl/select2.js.php'); ?>
    });

    // Medium modal for insurance search
    $(".medium_modal").on('click', function(e) {
        e.preventDefault(); e.stopPropagation();
        let title = <?php echo xlj('Insurance Search/Select/Add'); ?>;
        let ins_url = $(this).attr('href') + encodeURIComponent(sendInsToSearch(insurance_index));
        dlgopen('', '', 700, 600, '', title, {
            buttons: [{text: <?php echo xlj('Close'); ?>, close: true, style: 'default btn-sm'}],
            allowResize: true, allowDrag: true, dialogId: '', type: 'iframe', url: ins_url
        });
    });

    $('input, select, textarea').on('change keyup', function() {
        $('#submit_btn').prop('disabled', false).addClass('btn-enabled');
    });

    // Restore session before form submission
    $('#demographics_form').on('submit', function() {
        top.restoreSession && top.restoreSession();
    });

    // 🔥 AUTO-FILL ON LOAD if already "self"
    <?php for ($i = 1; $i <= 3; $i++) { ?>
    auto_populate_employer_address<?php echo $i; ?>();
    <?php } ?>

    // Auto-populate subscriber from patient
    <?php for ($i = 1; $i <= 3; $i++) { ?>
    $("#form_i<?php echo $i; ?>subscriber_relationship").on('change select2:select', function() {
        auto_populate_employer_address<?php echo attr($i); ?>();
    });
    <?php } ?>
});

function ins_search(ins) { insurance_index = ins; return false; }

function sendInsToSearch(ins) {
    let thesel = $('#i' + ins + 'provider');
    let theseldata = $(thesel).select2('data');
    return theseldata[0]['id'];
}

function InsSaveClose() { top.restoreSession(); document.location.reload(); }

function set_insurance(ins_id, ins_name) {
    var thesel = $('#i' + insurance_index + 'provider');
    if ($(thesel).find("option[value='" + ins_id + "']").length) {
        thesel.val(ins_id).trigger('change');
    } else {
        let newOption = new Option(ins_name, ins_id, true, true);
        thesel.append(newOption).trigger('change');
    }
}

// Helper: set a form element value by name (plain inputs) or trigger select2 change
function setVal(f, name, val) {
    var el = f[name];
    if (el && typeof el.value !== 'undefined') {
        el.value = val;
        // If it's a select2-powered element, notify select2
        $(el).trigger('change.select2');
    }
}

<?php for ($i = 1; $i <= 3; $i++) { ?>
function auto_populate_employer_address<?php echo attr($i); ?>() {
    // Read relationship from select2 or plain select
    var relVal = $('#form_i<?php echo $i; ?>subscriber_relationship').val();
    if (!relVal) {
        // Fallback: read native select element
        var relEl = document.getElementById('form_i<?php echo attr($i); ?>subscriber_relationship');
        if (!relEl) return;
        relVal = relEl.value;
    }

    if (relVal === "self") {
        var f   = document.demographics_form;
        var pfx = 'i<?php echo attr($i); ?>';

        // Plain text inputs — set directly from patientData
        setVal(f, pfx + 'subscriber_fname',                  patientData.form_fname);
        setVal(f, pfx + 'subscriber_mname',                  patientData.form_mname);
        setVal(f, pfx + 'subscriber_lname',                  patientData.form_lname);
        setVal(f, pfx + 'subscriber_street',                 patientData.form_street);
        setVal(f, pfx + 'subscriber_street_line_2',          patientData.form_street_line_2);
        setVal(f, pfx + 'subscriber_city',                   patientData.form_city);
        setVal(f, pfx + 'subscriber_postal_code',            patientData.form_postal_code);
        setVal(f, pfx + 'subscriber_phone',                  patientData.form_phone_home);
        setVal(f, pfx + 'subscriber_DOB',                    patientData.form_DOB);
        setVal(f, pfx + 'subscriber_ss',                     patientData.form_ss);
        setVal(f, pfx + 'subscriber_employer',               patientData.form_em_name);
        setVal(f, pfx + 'subscriber_employer_street',        patientData.form_em_street);
        setVal(f, pfx + 'subscriber_employer_street_line_2', patientData.form_em_street_line_2);
        setVal(f, pfx + 'subscriber_employer_city',          patientData.form_em_city);
        setVal(f, pfx + 'subscriber_employer_postal_code',   patientData.form_em_postal_code);

        // Select / select2 fields (generate_form_field uses "form_" prefix in field_id)
        setVal(f, 'form_' + pfx + 'subscriber_state',             patientData.form_state);
        setVal(f, 'form_' + pfx + 'subscriber_country',           patientData.form_country_code);
        setVal(f, 'form_' + pfx + 'subscriber_sex',               patientData.form_sex);
        setVal(f, 'form_' + pfx + 'subscriber_employer_state',    patientData.form_em_state);
        setVal(f, 'form_' + pfx + 'subscriber_employer_country',  patientData.form_em_country);

        // Enable Save button since data was auto-populated
        $('#submit_btn').prop('disabled', false).addClass('btn-enabled');
    }
}
<?php } ?>

function capitalizeMe(elem) {
    var a = elem.value.split(' '); var s = '';
    for (var i = 0; i < a.length; ++i) {
        if (a[i].length > 0) { if (s.length > 0) s += ' '; s += a[i].charAt(0).toUpperCase() + a[i].substring(1); }
    }
    elem.value = s;
}

function popUp(URL) {
    var day = new Date(); var id = day.getTime(); top.restoreSession();
    eval("page" + id + " = window.open(URL, '" + id + "', 'toolbar=0,scrollbars=1,location=0,statusbar=0,menubar=0,resizable=1,width=400,height=300,left=440,top=362');");
}

function browseForRecord(browsenum) {
    top.restoreSession();
    var title = <?php echo xlj('Select Subscriber'); ?>;
    dlgopen('browse.php?browsenum=' + encodeURIComponent(browsenum), '', 700, 500, '', title, {
        buttons: [{text: <?php echo xlj('Close'); ?>, close: true, style: 'default btn-sm'}],
        allowResize: true,
        allowDrag: true,
        dialogId: 'browse-subscriber-' + browsenum,
        type: 'iframe'
    });
}
</script>

<style>
:root {
    --navy:      #1e0a3c;
    --navy-mid:  #2d1260;
    --navy-light:#e8e0f5;
    --navy-pale: #f5f2fc;
    --gold:      #9588e8;
    --accent:    #6c5ce7;
    --white:     #ffffff;
    --bg:        #f4f2f9;
    --border:    #ddd6f0;
    --text:      #1a1028;
    --text-mid:  #4a3f60;
    --text-muted:#8a7aa0;
    --success:   #059669;
    --danger:    #dc2626;
    --warning:   #d97706;
    --radius:    10px;
    --radius-sm: 6px;
}

* { font-family: 'Montserrat', sans-serif !important; box-sizing: border-box; }
body { background: var(--bg) !important; color: var(--text) !important; margin: 0; padding: 0; }

/* ── Header ── */
.mdi-page-header {
    background: var(--navy);
    color: #fff;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 3px solid var(--gold);
    position: sticky;
    top: 0;
    width:100%;
    z-index: 100;
    box-shadow: 0 2px 12px rgba(30,10,60,.25);
    flex-wrap: wrap;
    gap: 8px;
}
button.btn-swap{
    color:#FFF;
}
.mdi-page-title {
    font-size: 15px; font-weight: 700;
    display: flex; align-items: center; gap: 10px; margin: 0;
}
.mdi-page-title .pt-name {
    font-size: 12.5px; font-weight: 500; color: rgba(255,255,255,.7);
    margin-left: 8px; padding-left: 8px; border-left: 1px solid rgba(255,255,255,.25);
}
.mdi-header-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.btn-save-main {
    background: var(--gold); color: #fff; border: none;
    padding: 7px 20px; border-radius: var(--radius-sm);
    font-size: 11px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; gap: 6px;
    transition: background .15s, opacity .15s; opacity: .5;
}
.btn-save-main.btn-enabled { opacity: 1; }
.btn-save-main:hover { background: #7c6ee8; }
.btn-cancel-main {
    background: rgba(255,255,255,.12); color: #fff;
    border: 1px solid rgba(255,255,255,.25);
    padding: 7px 14px; border-radius: var(--radius-sm);
    font-size: 11px; font-weight: 600; cursor: pointer;
    text-decoration: none; display: flex; align-items: center; gap: 5px;
}
.btn-cancel-main:hover { background: rgba(255,255,255,.2); color: #fff; text-decoration: none; }
.btn-dem-link {
    background: rgba(255,255,255,.1); color: rgba(255,255,255,.85);
    border: 1px solid rgba(255,255,255,.2); padding: 7px 14px;
    border-radius: var(--radius-sm); font-size: 11px; font-weight: 600;
    text-decoration: none; display: flex; align-items: center; gap: 5px;
}
.btn-dem-link:hover { background: rgba(255,255,255,.2); color: #fff; text-decoration: none; }

/* ── Body ── */
.mdi-page-body { padding: 20px 24px; max-width: 1200px; width:100%; margin: 0 auto; }

/* ── Insurance list table (top — like mockup) ── */
.ins-list-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 2px 10px rgba(30,10,60,.07);
    margin-bottom: 20px;
    overflow: hidden;
}
.ins-list-card-header {
    background: var(--navy);
    color: #fff;
    padding: 10px 18px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 2px solid var(--gold);
}
.ins-list-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11.5px;
}
.ins-list-table thead tr { background: var(--navy-pale); }
.ins-list-table thead td {
    padding: 8px 14px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
}
.ins-list-table tbody tr {
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background .1s;
}
.ins-list-table tbody tr:last-child { border-bottom: none; }
.ins-list-table tbody tr:hover { background: var(--navy-pale); }
.ins-list-table tbody tr.ins-row-active { background: var(--navy-light) !important; }
.ins-list-table tbody td { padding: 9px 14px; color: var(--text); }
.ins-type-badge {
    display: inline-block;
    padding: 2px 9px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}
.ins-type-badge.primary   { background: #ede9fe; color: var(--accent); }
.ins-type-badge.secondary { background: #dbeafe; color: #2563eb; }
.ins-type-badge.tertiary  { background: #d1fae5; color: var(--success); }
.ins-status-active   { color: var(--success); font-weight: 600; font-size: 11px; }
.ins-status-inactive { color: var(--text-muted); font-weight: 600; font-size: 11px; }

/* ── Insurance tabs ── */
.ins-tabs-bar {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border);
    background: var(--navy-pale);
    padding: 0 18px;
    flex-wrap: wrap;
}
.ins-tab-btn {
    padding: 10px 20px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    border: none;
    background: none;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.ins-tab-btn:hover { color: var(--accent); }
.ins-tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 700; }

/* ── Insurance tab panels ── */
.ins-tab-panel { display: none; }

/* ── Insurance detail card ── */
.ins-detail-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 2px 10px rgba(30,10,60,.07);
    overflow: hidden;
}
.ins-detail-header {
    background: var(--navy);
    color: #fff;
    padding: 10px 18px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 2px solid var(--gold);
}
.ins-detail-header .ins-name-display {
    font-size: 13px;
    font-weight: 600;
    color: rgba(255,255,255,.9);
    text-transform: none;
    letter-spacing: 0;
}

/* ── Field grid ── */
.ins-field-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 0;
}
.ins-field-group {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    border-right: 1px solid var(--border);
}
.ins-field-group:nth-child(4n) { border-right: none; }
.ins-field-group.span-2 { grid-column: span 2; }
.ins-field-group.span-3 { grid-column: span 3; }
.ins-field-group.span-4 { grid-column: span 4; }
.ins-field-label {
    font-size: 9.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    color: var(--text-muted);
    margin-bottom: 4px;
}
.ins-field-label .req { color: var(--accent); }

/* ── Section divider inside grid ── */
.ins-section-divider {
    grid-column: span 4;
    background: var(--navy-pale);
    border-bottom: 1px solid var(--border);
    border-right: none;
    padding: 7px 14px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    color: var(--text-mid);
    display: flex;
    align-items: center;
    gap: 6px;
}
.ins-section-divider svg { color: var(--accent); }

/* ── Visits / Financials highlight row ── */
.ins-financial-row {
    grid-column: span 4;
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    background: var(--navy-pale);
    border-bottom: 1px solid var(--border);
}
.ins-financial-row .ins-field-group {
    border-bottom: none;
    padding: 10px 14px;
}
.ins-max-visits-val {
    font-size: 20px;
    font-weight: 700;
    color: var(--accent);
    line-height: 1;
}
.ins-remaining-val {
    font-size: 20px;
    font-weight: 700;
    line-height: 1;
}
.ins-remaining-val.warn  { color: var(--warning); }
.ins-remaining-val.ok    { color: var(--success); }
.ins-remaining-val.crit  { color: var(--danger); }

/* ── Form inputs ── */
.ins-input, .ins-select {
    width: 100%;
    height: 30px;
    padding: 0 8px;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 11.5px;
    color: var(--text);
    background: var(--white);
    font-family: 'Montserrat', sans-serif !important;
    transition: border-color .15s, box-shadow .15s;
}
.ins-input:focus, .ins-select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(108,92,231,.12);
    outline: none;
}
.ins-input.datepicker { cursor: pointer; }
textarea.ins-input { height: auto; padding: 6px 8px; resize: vertical; }
.ins-number-input { width: 80px; }

/* ── Browse for Record button ── */
.btn-browse-record {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 5px;
    padding: 4px 10px;
    background: var(--navy-pale);
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    font-size: 10px;
    font-weight: 600;
    color: var(--accent);
    cursor: pointer;
    text-decoration: none;
    transition: background .15s, border-color .15s, color .15s;
    white-space: nowrap;
}
.btn-browse-record:hover {
    background: var(--navy-light);
    border-color: var(--accent);
    color: var(--navy-mid);
}
.btn-browse-record svg { flex-shrink: 0; }

/* ── Swap button ── */
.btn-swap {
    background: none;
    border: 1.5px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 4px 10px;
    font-size: 10.5px;
    font-weight: 600;
    color: var(--text-mid);
    cursor: pointer;
    transition: all .15s;
}
.btn-swap:hover { border-color: var(--accent); color: var(--accent); background: var(--navy-pale); }

/* ── Search/Add button ── */
.btn-ins-search {
    background: var(--accent);
    color: #fff;
    border: none;
    padding: 5px 12px;
    border-radius: var(--radius-sm);
    font-size: 10.5px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    transition: background .15s;
}
.btn-ins-search:hover { background: var(--navy-mid); color: #fff; text-decoration: none; }

/* ── Provider select ── */
.ins-provider-wrap { display: flex; gap: 8px; align-items: flex-start; flex-wrap: wrap; }

/* ── Select2 ── */
.select2-container--bootstrap4 .select2-selection {
    border: 1.5px solid var(--border) !important;
    border-radius: var(--radius-sm) !important;
    font-size: 11.5px !important;
}

/* ── Saved success banner ── */
.mdi-saved-banner {
    background: #f0fdf4;
    border: 1px solid #86efac;
    border-left: 4px solid #16a34a;
    color: #166534;
    padding: 9px 20px;
    font-size: 11.5px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    animation: mdi-fade-out 4s forwards;
}
@keyframes mdi-fade-out {
    0%   { opacity: 1; max-height: 60px; }
    70%  { opacity: 1; max-height: 60px; }
    100% { opacity: 0; max-height: 60px; }
}
@media (max-width: 900px) {
    .ins-field-grid { grid-template-columns: repeat(2, 1fr); }
    .ins-section-divider { grid-column: span 2; }
    .ins-field-group.span-2, .ins-field-group.span-3, .ins-field-group.span-4 { grid-column: span 2; }
    .ins-financial-row { grid-column: span 2; grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    .ins-field-grid { grid-template-columns: 1fr; }
    .ins-section-divider { grid-column: span 1; }
    .ins-field-group.span-2, .ins-field-group.span-3, .ins-field-group.span-4 { grid-column: span 1; }
}
</style>
</head>

<body style="background:var(--bg);">

<form action='demographics_save.php' name='demographics_form' id='demographics_form' method='post'>
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type='hidden' name='mode'  value='save' />
<input type='hidden' name='db_id' value="<?php echo attr($result['id']); ?>" />
<input type='hidden' name='save_source' value='insurance_full' />
<input type="hidden" name="isSwapClicked" value="" />

<!-- ── Sticky Page Header ── -->
<div class="mdi-page-header">
    <div class="mdi-page-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="color:var(--gold)"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4l5 2.18V11c0 3.47-2.34 6.71-5 7.93-2.66-1.22-5-4.46-5-7.93V7.18L12 5z"/></svg>
        <?php echo xlt('Insurance'); ?>
        <span class="pt-name">
            <?php echo text($result['fname'] . ' ' . $result['lname']); ?>
            &nbsp;&bull;&nbsp; <?php echo xlt('DOB'); ?>: <?php echo text(oeFormatShortDate($result['DOB_YMD'])); ?>
        </span>
    </div>
    <div class="mdi-header-actions">
        <a href="demographics_full.php?set_pid=<?php echo attr_url($pid); ?>" onclick="top.restoreSession();" class="btn-dem-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
            <?php echo xlt('Demographics'); ?>
        </a>
        <a class="btn-cancel-main" href="demographics.php" onclick="top.restoreSession();">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M19 11H7.83l4.88-4.88c.39-.39.39-1.03 0-1.42-.39-.39-1.02-.39-1.41 0l-6.59 6.59c-.39.39-.39 1.02 0 1.41l6.59 6.59c.39.39 1.02.39 1.41 0 .39-.39.39-1.02 0-1.41L7.83 13H19c.55 0 1-.45 1-1s-.45-1-1-1z"/></svg>
            <?php echo xlt('Back To Patient'); ?>
        </a>
        <button type="submit" class="btn-save-main" id="submit_btn" disabled="disabled">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
            <?php echo xlt('Save'); ?>
        </button>
    </div>
</div>

<div class="mdi-page-body">

<?php if (!empty($_GET['saved'])) : ?>
<div class="mdi-saved-banner">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
    <?php echo xlt('Insurance information saved successfully.'); ?>
</div>
<?php endif; ?>

    <!-- ── Insurance summary table (like Insurance/Dates mockup top list) ── -->
    <?php if (!$GLOBALS['simplified_demographics']) : ?>
    <div class="ins-list-card">
        <div class="ins-list-card-header">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor" style="color:var(--gold)"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
            <?php echo xlt('Insurance Coverage'); ?>
        </div>
        <table class="ins-list-table">
            <thead>
                <tr>
                    <td><?php echo xlt('Type'); ?></td>
                    <td><?php echo xlt('Carrier'); ?></td>
                    <td><?php echo xlt('Policy #'); ?></td>
                    <td><?php echo xlt('Group #'); ?></td>
                    <td><?php echo xlt('Effective Date'); ?></td>
                    <td><?php echo xlt('Term Date'); ?></td>
                    <td><?php echo xlt('Status'); ?></td>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($insurance_array as $idx => $instype) :
                $r3 = $insurance_info[$idx + 1];
                $carrier = '';
                if (!empty($r3['provider'])) {
                    foreach ($insurancei as $iid => $iname) {
                        if (strtolower($iid) == strtolower($r3['provider'])) { $carrier = $iname; break; }
                    }
                }
                $is_active = !empty($r3['provider']) && (empty($r3['date_end']) || $r3['date_end'] >= date('Y-m-d'));
                $tab_idx = $idx + 1;
            ?>
            <tr class="<?php echo $idx === 0 ? 'ins-row-active' : ''; ?>" onclick="$('.ins-tab-btn[data-tab=<?php echo attr_js($tab_idx); ?>]').click()" style="cursor:pointer;">
                <td><span class="ins-type-badge <?php echo attr($instype); ?>"><?php echo text(ucfirst($instype)); ?></span></td>
                <td><?php echo text($carrier ?: xl('Unassigned')); ?></td>
                <td><?php echo text($r3['policy_number'] ?? '—'); ?></td>
                <td><?php echo text($r3['group_number'] ?? '—'); ?></td>
                <td><?php echo text($r3['date'] ? oeFormatShortDate($r3['date']) : '—'); ?></td>
                <td><?php echo text($r3['date_end'] ? oeFormatShortDate($r3['date_end']) : xl('Active')); ?></td>
                <td>
                    <?php if (!empty($r3['provider'])) : ?>
                    <span class="<?php echo $is_active ? 'ins-status-active' : 'ins-status-inactive'; ?>">
                        <?php echo $is_active ? xlt('Active') : xlt('Inactive'); ?>
                    </span>
                    <?php else : ?>
                    <span class="ins-status-inactive"><?php echo xlt('None'); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Insurance detail edit tabs ── -->
    <div class="ins-detail-card">

        <!-- Tab bar -->
        <div class="ins-tabs-bar">
            <?php foreach ($insurance_array as $idx => $instype) :
                $tab_idx = $idx + 1;
                $CapInstype = ucfirst($instype);
            ?>
            <button type="button" class="ins-tab-btn <?php echo $idx === 0 ? 'active' : ''; ?>" data-tab="<?php echo attr($tab_idx); ?>">
                <?php echo xlt($CapInstype); ?>
            </button>
            <?php endforeach; ?>
        </div>

        <!-- Tab panels -->
        <?php for ($i = 1; $i <= 3; $i++) :
            if ($i > count($insurance_array)) continue;
            $result3 = $insurance_info[$i];
            $instype  = $insurance_array[$i - 1];
            $CapIns   = ucfirst($instype);

            // Carrier display name
            $carrier_display = '';
            if (!empty($result3['provider'])) {
                foreach ($insurancei as $iid => $iname) {
                    if (strtolower($iid) == strtolower($result3['provider'])) { $carrier_display = $iname; break; }
                }
            }

            // Remaining visits calculation
            $max_v       = (int)($result3['max_visits'] ?? 0);
            $used_v_row  = sqlQuery("SELECT COUNT(*) AS cnt FROM form_encounter fe JOIN insurance_data id2 ON id2.pid=fe.pid WHERE fe.pid=? AND id2.type=? AND YEAR(fe.date)=YEAR(CURDATE())", [$pid, $instype]);
            $used_v      = (int)($used_v_row['cnt'] ?? 0);
            $remaining_v = $max_v > 0 ? max(0, $max_v - $used_v) : null;
            $rem_class   = ($remaining_v !== null && $remaining_v <= 2) ? 'crit' : (($remaining_v !== null && $remaining_v <= 5) ? 'warn' : 'ok');
        ?>
        <div class="ins-tab-panel" id="tab-<?php echo attr($i); ?>">

            <!-- Panel header with carrier name -->
            <div class="ins-detail-header">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span class="ins-type-badge <?php echo attr($instype); ?>" style="font-size:11px;"><?php echo text($CapIns); ?></span>
                    <span class="ins-name-display"><?php echo text($carrier_display ?: xlt('No carrier assigned')); ?></span>
                </div>
                <?php if (!empty($GLOBALS['enable_swap_secondary_insurance']) && $i > 1) : ?>
                <button type="button" class="btn-swap" onclick="document.forms[0].isSwapClicked.value=<?php echo attr($i); ?>; document.forms[0].submit();">
                    <?php echo ($i == 2) ? xlt('↕ Swap with Primary') : xlt('↕ Swap with Secondary'); ?>
                </button>
                <?php endif; ?>
            </div>

            <!-- Field grid -->
            <div class="ins-field-grid">

                <!-- ── Carrier & Policy ── -->
                <div class="ins-section-divider">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/></svg>
                    <?php echo xlt('Carrier & Policy'); ?>
                </div>

                <!-- Provider -->
                <div class="ins-field-group span-2">
                    <div class="ins-field-label"><span class="req">*</span> <?php echo xlt('Insurance Provider'); ?></div>
                    <div class="ins-provider-wrap">
                        <a class="medium_modal btn-ins-search" href="../../practice/ins_search.php?ins=" onclick="ins_search(<?php echo attr_js($i); ?>)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                            <?php echo xlt('Search / Add'); ?>
                        </a>
                        <select id="i<?php echo attr($i); ?>provider" name="i<?php echo attr($i); ?>provider"
                            class="ins-provider-sel" style="flex:1;min-width:200px;height:30px;">
                            <option value=""><?php echo xlt('Unassigned'); ?></option>
                            <?php foreach ($insurancei as $iid => $iname) :
                                $sel = (!empty($result3['provider']) && strtolower($iid) == strtolower($result3['provider'])) ? ' selected' : '';
                                echo '<option value="' . attr($iid) . '"' . $sel . '>' . text($iname) . '</option>';
                            endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Plan name -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Plan Name'); ?></div>
                    <input type="text" class="ins-input" name="i<?php echo attr($i); ?>plan_name"
                        value="<?php echo attr($result3['plan_name'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- Policy type -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Secondary Medicare Type'); ?></div>
                    <select class="ins-select sel2" name="i<?php echo attr($i); ?>policy_type">
                        <?php
                        if (!empty($policy_types)) {
                            foreach ($policy_types as $key => $value) {
                                echo '<option value="' . attr($key) . '"';
                                if (!empty($result3['policy_type']) && $key == $result3['policy_type']) echo ' selected';
                                echo '>' . text($value) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>

                <!-- Policy number -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><span class="req">*</span> <?php echo xlt('Policy Number'); ?></div>
                    <input type="text" class="ins-input" name="i<?php echo attr($i); ?>policy_number"
                        value="<?php echo attr($result3['policy_number'] ?? ''); ?>"
                        onkeyup="policykeyup(this)" />
                </div>

                <!-- Group number -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Group Number'); ?></div>
                    <input type="text" class="ins-input" name="i<?php echo attr($i); ?>group_number"
                        value="<?php echo attr($result3['group_number'] ?? ''); ?>"
                        onkeyup="policykeyup(this)" />
                </div>

                <!-- Effective date -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Effective Date'); ?></div>
                    <input type="text" class="ins-input datepicker"
                        id="i<?php echo attr($i); ?>effective_date"
                        name="i<?php echo attr($i); ?>effective_date"
                        value="<?php echo attr(oeFormatShortDate($result3['date'] ?? '')); ?>" />
                </div>

                <!-- Term date -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Term Date'); ?></div>
                    <input type="text" class="ins-input datepicker"
                        id="i<?php echo attr($i); ?>effective_date_end"
                        name="i<?php echo attr($i); ?>effective_date_end"
                        value="<?php echo attr(oeFormatShortDate($result3['date_end'] ?? '')); ?>" />
                </div>

                <!-- CoPay -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Co-Pay'); ?></div>
                    <input type="text" class="ins-input" style="max-width:100px;"
                        name="i<?php echo attr($i); ?>copay"
                        value="<?php echo attr($result3['copay'] ?? ''); ?>" />
                </div>

                <!-- Accept assignment -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Accept Assignment'); ?></div>
                    <select class="ins-select" name="i<?php echo attr($i); ?>accept_assignment">
                        <option value="TRUE"  <?php echo (!empty($result3['accept_assignment']) && strtoupper($result3['accept_assignment']) == 'TRUE')  ? 'selected' : ''; ?>><?php echo xlt('Yes'); ?></option>
                        <option value="FALSE" <?php echo (!empty($result3['accept_assignment']) && strtoupper($result3['accept_assignment']) == 'FALSE') ? 'selected' : ''; ?>><?php echo xlt('No'); ?></option>
                    </select>
                </div>

                <!-- ── Visits & Financials ── -->
                <div class="ins-section-divider">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-1.09 1.01-1.85 2.7-1.85 1.78 0 2.44.85 2.5 2.1h2.21c-.07-1.72-1.12-3.3-3.21-3.81V3h-3v2.16c-1.94.42-3.5 1.68-3.5 3.61 0 2.31 1.91 3.46 4.7 4.13 2.5.6 3 1.48 3 2.41 0 .69-.49 1.79-2.7 1.79-2.06 0-2.87-.92-2.98-2.1h-2.2c.12 2.19 1.76 3.42 3.68 3.83V21h3v-2.15c1.95-.37 3.5-1.5 3.5-3.55 0-2.84-2.43-3.81-4.7-4.4z"/></svg>
                    <?php echo xlt('Visits & Financials'); ?>
                </div>

                <!-- Financial highlight row -->
                <div class="ins-financial-row">
                    <div class="ins-field-group">
                        <div class="ins-field-label"><?php echo xlt('Max Visits'); ?></div>
                        <input type="number" class="ins-input ins-number-input" style="width:80px;"
                            name="i<?php echo attr($i); ?>max_visits"
                            value="<?php echo attr($result3['max_visits'] ?? 0); ?>" min="0" />
                    </div>
                    <div class="ins-field-group">
                        <div class="ins-field-label"><?php echo xlt('Visits Used (Year)'); ?></div>
                        <div class="ins-max-visits-val" style="color:var(--text-mid);"><?php echo text($used_v); ?></div>
                    </div>
                    <div class="ins-field-group">
                        <div class="ins-field-label"><?php echo xlt('Remaining Visits'); ?></div>
                        <?php if ($max_v > 0) : ?>
                        <div class="ins-remaining-val <?php echo attr($rem_class); ?>"><?php echo text($remaining_v); ?></div>
                        <?php else : ?>
                        <div style="font-size:12px;color:var(--success);font-weight:600;"><?php echo xlt('Unlimited'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ── Subscriber Information ── -->
                <div class="ins-section-divider">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
                    <?php echo xlt('Subscriber Information'); ?>
                </div>

                <!-- Relationship -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><span class="req">*</span> <?php echo xlt('Relationship'); ?></div>
                    <?php generate_form_field(
                        ['data_type'=>1,'field_id'=>('i'.$i.'subscriber_relationship'),'list_id'=>'sub_relation','empty_title'=>' ','smallform'=>' ins-select'],
                        ($result3['subscriber_relationship'] ?? '')
                    ); ?>
                    <button type="button" class="btn-browse-record" onclick="browseForRecord(<?php echo attr_js($i); ?>)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                        <?php echo xlt('Browse for Record'); ?>
                    </button>
                </div>

                <!-- Subscriber first name -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('First Name'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_fname"
                        value="<?php echo attr($result3['subscriber_fname'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- Subscriber middle name -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Middle Name'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_mname"
                        value="<?php echo attr($result3['subscriber_mname'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- Subscriber last name -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Last Name'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_lname"
                        value="<?php echo attr($result3['subscriber_lname'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- DOB -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Date of Birth'); ?></div>
                    <input type="text" class="ins-input datepicker"
                        id="i<?php echo attr($i); ?>subscriber_DOB"
                        name="i<?php echo attr($i); ?>subscriber_DOB"
                        value="<?php echo attr(oeFormatShortDate($result3['subscriber_DOB'] ?? '')); ?>" />
                </div>

                <!-- Sex -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Sex'); ?></div>
                    <?php generate_form_field(
                        ['data_type'=>1,'field_id'=>('i'.$i.'subscriber_sex'),'list_id'=>'sex','smallform'=>' ins-select'],
                        ($result3['subscriber_sex'] ?? '')
                    ); ?>
                </div>

                <!-- SS -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Social Security'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_ss"
                        value="<?php echo attr(trim($result3['subscriber_ss'] ?? '')); ?>" />
                </div>

                <!-- Phone -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Phone'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_phone"
                        value="<?php echo attr($result3['subscriber_phone'] ?? ''); ?>"
                        onkeyup="phonekeyup(this, mypcc)" />
                </div>

                <!-- ── Subscriber Address ── -->
                <div class="ins-section-divider">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>
                    <?php echo xlt('Subscriber Address'); ?>
                </div>

                <!-- Street -->
                <div class="ins-field-group span-2">
                    <div class="ins-field-label"><?php echo xlt('Street Address'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_street"
                        value="<?php echo attr($result3['subscriber_street'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- Street line 2 -->
                <div class="ins-field-group span-2">
                    <div class="ins-field-label"><?php echo xlt('Address Line 2'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_street_line_2"
                        value="<?php echo attr($result3['subscriber_street_line_2'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- City -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('City'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_city"
                        value="<?php echo attr($result3['subscriber_city'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- State -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo ($GLOBALS['phone_country_code'] == '1') ? xlt('State') : xlt('Locality'); ?></div>
                    <?php generate_form_field(
                        ['data_type'=>$GLOBALS['state_data_type'],'field_id'=>('i'.$i.'subscriber_state'),'list_id'=>$GLOBALS['state_list'],'fld_length'=>'15','max_length'=>'63','edit_options'=>'C','smallform'=>'true'],
                        ($result3['subscriber_state'] ?? '')
                    ); ?>
                </div>

                <!-- Zip -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo ($GLOBALS['phone_country_code'] == '1') ? xlt('Zip Code') : xlt('Postal Code'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_postal_code"
                        value="<?php echo attr($result3['subscriber_postal_code'] ?? ''); ?>" />
                </div>

                <!-- Country -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Country'); ?></div>
                    <?php generate_form_field(
                        ['data_type'=>$GLOBALS['country_data_type'],'field_id'=>('i'.$i.'subscriber_country'),'list_id'=>$GLOBALS['country_list'],'fld_length'=>'10','max_length'=>'63','edit_options'=>'C','smallform'=>'true'],
                        ($result3['subscriber_country'] ?? '')
                    ); ?>
                </div>

                <!-- ── Subscriber Employer ── -->
                <?php if (!$GLOBALS['omit_employers']) : ?>
                <div class="ins-section-divider">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 7V3H2v18h20V7H12zm-2 12H4v-2h6v2zm0-4H4v-2h6v2zm0-4H4V9h6v2zm0-4H4V5h6v2zm10 12h-8V9h8v10zm-2-8h-4V9h4v2zm0 4h-4v-2h4v2z"/></svg>
                    <?php echo xlt('Subscriber Employer'); ?>
                </div>

                <!-- Employer name -->
                <div class="ins-field-group span-2">
                    <div class="ins-field-label"><?php echo xlt('Employer Name'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_employer"
                        value="<?php echo attr($result3['subscriber_employer'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                    <div style="font-size:10px;color:var(--text-muted);margin-top:3px;"><?php echo xlt('If unemployed enter Student, PT Student, or leave blank'); ?></div>
                </div>

                <!-- Employer street -->
                <div class="ins-field-group span-2">
                    <div class="ins-field-label"><?php echo xlt('Employer Street'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_employer_street"
                        value="<?php echo attr($result3['subscriber_employer_street'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- Employer street 2 -->
                <div class="ins-field-group span-2">
                    <div class="ins-field-label"><?php echo xlt('Employer Address Line 2'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_employer_street_line_2"
                        value="<?php echo attr($result3['subscriber_employer_street_line_2'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- Employer city -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Employer City'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_employer_city"
                        value="<?php echo attr($result3['subscriber_employer_city'] ?? ''); ?>"
                        onchange="capitalizeMe(this);" />
                </div>

                <!-- Employer state -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo ($GLOBALS['phone_country_code'] == '1') ? xlt('Employer State') : xlt('Employer Locality'); ?></div>
                    <?php generate_form_field(
                        ['data_type'=>$GLOBALS['state_data_type'],'field_id'=>('i'.$i.'subscriber_employer_state'),'list_id'=>$GLOBALS['state_list'],'fld_length'=>'15','max_length'=>'63','edit_options'=>'C','smallform'=>'true'],
                        ($result3['subscriber_employer_state'] ?? '')
                    ); ?>
                </div>

                <!-- Employer zip -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo ($GLOBALS['phone_country_code'] == '1') ? xlt('Employer Zip') : xlt('Employer Postal Code'); ?></div>
                    <input type="text" class="ins-input"
                        name="i<?php echo attr($i); ?>subscriber_employer_postal_code"
                        value="<?php echo attr($result3['subscriber_employer_postal_code'] ?? ''); ?>" />
                </div>

                <!-- Employer country -->
                <div class="ins-field-group">
                    <div class="ins-field-label"><?php echo xlt('Employer Country'); ?></div>
                    <?php generate_form_field(
                        ['data_type'=>$GLOBALS['country_data_type'],'field_id'=>('i'.$i.'subscriber_employer_country'),'list_id'=>$GLOBALS['country_list'],'fld_length'=>'10','max_length'=>'63','edit_options'=>'C','smallform'=>'true'],
                        ($result3['subscriber_employer_country'] ?? '')
                    ); ?>
                </div>
                <?php endif; // omit_employers ?>

            </div><!-- /ins-field-grid -->
        </div><!-- /ins-tab-panel -->
        <?php endfor; ?>

    </div><!-- /ins-detail-card -->
    <?php endif; // simplified_demographics ?>

</div><!-- /mdi-page-body -->
</form>

<script>
var mypcc = <?php echo js_escape($GLOBALS['phone_country_code']); ?>;
<?php if ($GLOBALS['new_validate'] == 0) { ?>
var f = document.forms[0];
if (f.i1subscriber_phone) phonekeyup(f.i1subscriber_phone, mypcc);
if (f.i2subscriber_phone) phonekeyup(f.i2subscriber_phone, mypcc);
if (f.i3subscriber_phone) phonekeyup(f.i3subscriber_phone, mypcc);
<?php } ?>

<?php if ($set_pid) { ?>
parent.left_nav.setPatient(<?php echo js_escape($result['fname'] . " " . $result['lname']) . "," . js_escape($pid) . "," . js_escape($result['pubpid']) . ",''," . js_escape(" " . xl('DOB') . ": " . oeFormatShortDate($result['DOB_YMD']) . " " . xl('Age') . ": " . getPatientAgeDisplay($result['DOB_YMD'])); ?>);
<?php } ?>

// Re-init select2 on all .ins-select after page loads
$(function() {
    $(".sel2").select2({
        theme: "bootstrap4", dropdownAutoWidth: true, width: 'resolve',
        <?php require($GLOBALS['srcdir'] . '/js/xl/select2.js.php'); ?>
    });
    // Apply Montserrat to select2 dropdowns
    document.querySelectorAll('.select2-selection, .select2-results__option').forEach(function(el) {
        el.style.fontFamily = "'Montserrat', sans-serif";
    });
});
</script>

<?php require $GLOBALS['fileroot'] . "/library/options_listadd.inc.php"; ?>

</body>
</html>
