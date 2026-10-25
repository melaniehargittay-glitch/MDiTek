<?php
/**
 * Edit demographics — MDItek redesign.
 * Insurance section moved to insurance_full.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @license   GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/validation/LBF_Validation.php");
require_once("$srcdir/patientvalidation.inc.php");
require_once("$srcdir/pid.inc.php");
require_once("$srcdir/patient.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Events\PatientDemographics\UpdateEvent;

$set_pid = $_GET["set_pid"] ?? ($_GET["pid"] ?? null);
if ($set_pid) {
    setpid((int)$set_pid);
}

$result  = getPatientData($pid, "*, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");
$result2 = getEmployerData($pid);

if ($pid) {
    $updateEvent = new UpdateEvent($pid);
    $updateEvent = $GLOBALS["kernel"]->getEventDispatcher()->dispatch($updateEvent, UpdateEvent::EVENT_HANDLE, 10);
    if (!$updateEvent->authorized() || !AclMain::aclCheckCore('patients', 'demo', '', 'write')) {
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

$CPR = 4;
?>
<!DOCTYPE html>
<html>
<head>
<?php Header::setupHeader(['datetime-picker','common','select2','erx']); ?>
<title><?php echo xlt('Edit Patient Demographics'); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<?php include_once($GLOBALS['srcdir'] . "/options.js.php"); ?>

<script>

$(function () {
    tabbify();

    $(".select-previous-names").select2({
        theme: "bootstrap4",
        dropdownAutoWidth: true,
        width: 'resolve',
        <?php require($GLOBALS['srcdir'] . '/js/xl/select2.js.php'); ?>
    }).on("select2:unselecting", function (e) {
        $(this).data('state', 'unselected');
        var data = e.params.args.data;
        const message = "<span>" + xl("Are You Sure you want to delete this name?") + "</span>";
        const ele = opener.document.getElementById('form_name_history');
        dialog.confirm(message).then(returned => {
            if (returned !== true) {
                if (data !== false) { $(".select-previous-names > option").prop("selected","selected").trigger("change"); }
                return false;
            }
            const url = top.webroot_url + '/library/ajax/specialty_form_ajax.php?delete=true';
            let doData = new FormData();
            doData.append('csrf_token_form', <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
            doData.append('id', data.id);
            doData.append('task_name_history', 'delete');
            fetch(url, { method: 'POST', body: doData }).then(rtn => rtn.json()).then((rtn) => {
                dialog.alert(xl("Returned: " + rtn));
                if (rtn === 'Success') { $(".select-previous-names option[value=" + data.id + "]").remove(); }
            });
        });
    }).on("select2:open", function (e) {
        if ($(this).data('state') === 'unselected') {
            $(this).removeData('state');
            let self = $(this);
            setTimeout(function () { self.select2('close'); }, 1);
        }
    }).on('select2:opening select2:closing', function (event) {
        let $search = $(this).parent().find('.select2-search__field');
        $search.prop('disabled', true);
    });

    $(".select-dropdown").select2({
        theme: "bootstrap4", dropdownAutoWidth: true, width: 'resolve',
        <?php require($GLOBALS['srcdir'] . '/js/xl/select2.js.php'); ?>
    });

    if (typeof error !== 'undefined' && error) { alertMsg(error); }

    $('.datepicker').datetimepicker({
        <?php $datetimepicker_timepicker = false; $datetimepicker_showseconds = false; $datetimepicker_formatInput = true; $datetimepicker_minDate = false; $datetimepicker_maxDate = false; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });
    $('.datetimepicker').datetimepicker({
        <?php $datetimepicker_timepicker = true; $datetimepicker_showseconds = false; $datetimepicker_formatInput = true; $datetimepicker_minDate = false; $datetimepicker_maxDate = false; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });
    $('.datepicker-past').datetimepicker({
        <?php $datetimepicker_timepicker = false; $datetimepicker_showseconds = false; $datetimepicker_formatInput = true; $datetimepicker_minDate = false; $datetimepicker_maxDate = '+1970/01/01'; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });
    $('.datepicker-future').datetimepicker({
        <?php $datetimepicker_timepicker = false; $datetimepicker_showseconds = false; $datetimepicker_formatInput = true; $datetimepicker_minDate = '-1970/01/01'; $datetimepicker_maxDate = false; ?>
        <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'); ?>
    });

    if (window.checkSkipConditions) { checkSkipConditions(); }

    // Enable save button on any change
    $('input, select, textarea').on('change keyup', function() {
        $('#submit_btn').prop('disabled', false).addClass('btn-enabled');
    });
});

var mypcc = <?php echo js_escape($GLOBALS['phone_country_code']); ?>;

function replace(string,text,by) {
    var strLength = string.length, txtLength = text.length;
    if ((strLength == 0) || (txtLength == 0)) return string;
    var i = string.indexOf(text);
    if ((!i) && (text != string.substring(0,txtLength))) return string;
    if (i == -1) return string;
    var newstr = string.substring(0,i) + by;
    if (i+txtLength < strLength) newstr += replace(string.substring(i+txtLength,strLength),text,by);
    return newstr;
}
function upperFirst(string,text) { return replace(string,text,text.charAt(0).toUpperCase() + text.substring(1,text.length)); }

function address_verify() {
    top.restoreSession();
    var f = document.demographics_form;
    dlgopen('../../practice/address_verify.php?address1=' + encodeURIComponent(f.form_street.value) +
    '&address2=' + encodeURIComponent(f.form_street_line_2.value) +
    '&city=' + encodeURIComponent(f.form_city.value) +
    '&state=' + encodeURIComponent(f.form_state.value) +
    '&zip5=' + encodeURIComponent(f.form_postal_code.value.substring(0,5)) +
    '&zip4=' + encodeURIComponent(f.form_postal_code.value.substring(5,9))
    , '_blank', 400, 150, '', xl('Address Verify'));
    return false;
}

function checkNum() {
    var re = /^\d*\.?\d*$/;
    str = document.demographics_form.monthly_income.value;
    if (!re.exec(str)) { alert(<?php echo xlj('Please enter a monetary amount using only numbers and a decimal point.'); ?>); }
}

function capitalizeMe(elem) {
    var a = elem.value.split(' '); var s = '';
    for (var i = 0; i < a.length; ++i) {
        if (a[i].length > 0) { if (s.length > 0) s += ' '; s += a[i].charAt(0).toUpperCase() + a[i].substring(1); }
    }
    elem.value = s;
}

function divclick(cb, divid) {
    var divstyle = document.getElementById(divid).style;
    divstyle.display = cb.checked ? 'block' : 'none';
    return true;
}

function trimlen(s) {
    var i = 0, j = s.length - 1;
    for (; i <= j && s.charAt(i) == ' '; ++i);
    for (; i <= j && s.charAt(j) == ' '; --j);
    if (i > j) return 0;
    return j + 1 - i;
}

function validate(f) {
    var errCount = 0; var errMsgs = new Array();
    <?php generate_layout_validation('DEM'); ?>
    var msg = <?php echo xlj('The following fields are required'); ?> + ":\n\n";
    for (var i = 0; i < errMsgs.length; i++) { msg += errMsgs[i] + "\n"; }
    msg += "\n" + <?php echo xlj('Please fill them in before continuing.'); ?>;
    if (errMsgs.length > 0) { alert(msg); }

    var deceasedEl = document.getElementById("form_deceased_date");
    var dateVal = deceasedEl ? deceasedEl.value : '';
    var d = new Date(); var month = '' + (d.getMonth() + 1), day = '' + d.getDate(), year = d.getFullYear();
    if (month.length < 2) month = '0' + month; if (day.length < 2) day = '0' + day;
    var currentDate = year + '-' + month + '-' + day;
    if (dateVal > currentDate) { alert(<?php echo xlj("Deceased Date should not be greater than Today"); ?>); return false; }

    <?php if ($GLOBALS['erx_enable']) { ?>
    alertMsg = '';
    for (i = 0; i < f.length; i++) {
        if (f[i].type == 'text' && f[i].value) {
            if (f[i].name == 'form_fname' || f[i].name == 'form_mname' || f[i].name == 'form_lname') {
                alertMsg += checkLength(f[i].name, f[i].value, 35);
                alertMsg += checkUsername(f[i].name, f[i].value);
            } else if (f[i].name == 'form_street' || f[i].name == 'form_city') {
                alertMsg += checkLength(f[i].name, f[i].value, 35);
                alertMsg += checkAlphaNumericExtended(f[i].name, f[i].value);
            } else if (f[i].name == 'form_phone_home') {
                alertMsg += checkPhone(f[i].name, f[i].value);
            }
        }
    }
    if (alertMsg) { alert(alertMsg); return false; }
    <?php } ?>

    return errMsgs.length < 1;
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
    --radius:    10px;
    --radius-sm: 6px;
}

* { font-family: 'Montserrat', sans-serif !important; box-sizing: border-box; }
body { background: var(--bg) !important; color: var(--text) !important; margin: 0; padding: 0; }

/* ── Top header bar ── */
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
    z-index: 100;
    box-shadow: 0 2px 12px rgba(30,10,60,.25);
    width:100%;
}
.mdi-page-title {
    font-size: 15px;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}
.mdi-page-title .pt-name {
    font-size: 13px;
    font-weight: 500;
    color: rgba(255,255,255,.7);
    margin-left: 8px;
    padding-left: 8px;
    border-left: 1px solid rgba(255,255,255,.25);
}
.mdi-header-actions { display: flex; gap: 8px; align-items: center; }
.btn-save-main {
    background: var(--gold);
    color: #fff;
    border: none;
    padding: 7px 20px;
    border-radius: var(--radius-sm);
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: background .15s, opacity .15s;
    opacity: .5;
}
.btn-save-main.btn-enabled { opacity: 1; }
.btn-save-main:hover { background: #7c6ee8; }
.btn-cancel-main {
    background: rgba(255,255,255,.12);
    color: #fff;
    border: 1px solid rgba(255,255,255,.25);
    padding: 7px 16px;
    border-radius: var(--radius-sm);
    font-size: 11px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: background .15s;
}
.btn-cancel-main:hover { background: rgba(255,255,255,.2); color: #fff; text-decoration: none; }

/* ── Insurance quick-link ── */
.mdi-ins-link {
    background: rgba(255,255,255,.1);
    color: rgba(255,255,255,.85);
    border: 1px solid rgba(255,255,255,.2);
    padding: 7px 14px;
    border-radius: var(--radius-sm);
    font-size: 11px;
    font-weight: 600;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: background .15s;
}
.mdi-ins-link:hover { background: rgba(255,255,255,.2); color: #fff; text-decoration: none; }

/* ── Page body ── */
.mdi-page-body { padding: 24px; max-width: 1200px; width:100%; margin: 0 auto; }

/* ── Tab nav ── */
.mdi-tab-nav {
    display: flex;
    gap: 0;
    border-bottom: 2px solid var(--border);
    margin-bottom: 0;
    flex-wrap: wrap;
    list-style: none;
    padding: 0;
    margin: 0;
}
.mdi-tab-nav li a {
    display: block;
    padding: 9px 18px;
    font-size: 11px;
    font-weight: 600;
    color: var(--text-muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: color .15s, border-color .15s;
    white-space: nowrap;
}
.mdi-tab-nav li a:hover { color: var(--accent); }
.mdi-tab-nav li.current a,
.mdi-tab-nav li a.current {
    color: var(--accent);
    border-bottom-color: var(--accent);
    font-weight: 700;
}

/* ── Tab container ── */
.mdi-tab-container {
    background: var(--white);
    border: 1px solid var(--border);
    border-top: none;
    border-radius: 0 0 var(--radius) var(--radius);
    padding: 24px;
    box-shadow: 0 2px 10px rgba(30,10,60,.07);
}

/* ── Override OpenEMR tab classes to match our theme ── */
.tabNav { display: flex; gap: 0; border-bottom: 2px solid var(--border); margin-bottom: 0; flex-wrap: wrap; list-style: none; padding: 0; background: transparent !important; }
.tabNav li a {
    display: block; padding: 9px 18px; font-size: 11px; font-weight: 600;
    color: var(--text-muted); text-decoration: none;
    border-bottom: 2px solid transparent; margin-bottom: -2px;
    background: transparent !important; border-top: none !important;
    border-left: none !important; border-right: none !important;
    transition: color .15s, border-color .15s;
}
.tabNav li a:hover { color: var(--accent); }
.tabNav li.current a { color: var(--accent) !important; border-bottom-color: var(--accent) !important; font-weight: 700; background: transparent !important; }
.tabContainer { background: var(--white); border: 1px solid var(--border); border-top: none; border-radius: 0 0 var(--radius) var(--radius); padding: 24px; box-shadow: 0 2px 10px rgba(30,10,60,.07); }
.tab { display: none; } .tab.current { display: block; }

/* ── Form fields ── */
.label_custom { font-size: 10.5px !important; font-weight: 600; color: var(--text-mid); text-transform: uppercase; letter-spacing: .4px; }
.label_custom span.required::after { content: ' *'; color: var(--accent); }

.form-control, .form-control-sm, input[type=text], input[type=entry], select, textarea {
    border: 1.5px solid var(--border) !important;
    border-radius: var(--radius-sm) !important;
    font-size: 11.5px !important;
    color: var(--text) !important;
    background: var(--white) !important;
    transition: border-color .15s, box-shadow .15s !important;
    font-family: 'Montserrat', sans-serif !important;
}
.form-control:focus, .form-control-sm:focus, input:focus, select:focus, textarea:focus {
    border-color: var(--accent) !important;
    box-shadow: 0 0 0 3px rgba(108,92,231,.12) !important;
    outline: none !important;
}

/* ── Section card ── */
.mdi-section-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 2px 10px rgba(30,10,60,.07);
    overflow: hidden;
    margin-bottom: 20px;
}
.mdi-section-card-header {
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
}
.mdi-section-card-header svg { color: var(--gold); }
.mdi-section-card-body { padding: 0; }

/* ── Demographics section label ── */
.section-header { display: none; } /* hidden — we use our own header */

/* ── Required asterisk ── */
span.required { }
span.required::after { content: ''; }

/* ── Button group override ── */
.btn-group { display: none; } /* hidden — using sticky header buttons */

/* ── Responsive ── */
@media (max-width: 768px) {
    .mdi-page-body { padding: 12px; }
    .tabContainer { padding: 14px; }
    .mdi-page-header { flex-wrap: wrap; gap: 8px; }
}

/* ── Saved success banner ── */
.mdi-saved-banner {
    background: #f0fdf4;
    border: 1px solid #86efac;
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
    0%   { opacity: 1; }
    70%  { opacity: 1; }
    100% { opacity: 0; }
}
.select2-container--bootstrap4 .select2-selection {
    border: 1.5px solid var(--border) !important;
    border-radius: var(--radius-sm) !important;
    font-size: 11.5px !important;
}
.select2-container--bootstrap4 .select2-selection--single { height: 31px !important; }
</style>

<?php
$constraints = LBF_Validation::generate_validate_constraints("DEM");
?>
<script>var constraints = <?php echo $constraints; ?>;</script>
</head>

<body class="body_top" style="background:var(--bg);">

<form action='demographics_save.php' name='demographics_form' id="DEM" method='post' class='form-inline'
    onsubmit="top.restoreSession(); return submitme(<?php echo $GLOBALS['new_validate'] ? 1 : 0; ?>,event,'DEM',constraints)">
<input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
<input type='hidden' name='mode'  value='save' />
<input type='hidden' name='pid'   value="<?php echo attr($pid); ?>" />
<input type='hidden' name='db_id' value="<?php echo attr($result['id']); ?>" />
<input type='hidden' name='save_source' value='demographics_full' />
<input type="hidden" name="isSwapClicked" value="" />

<!-- ── Sticky Page Header ── -->
<div class="mdi-page-header">
    <div class="mdi-page-title">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="color:var(--gold)"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
        <?php echo xlt('Edit Patient Demographics'); ?>
        <?php if ($pid) : ?>
        <span class="pt-name">
            <?php echo text($result['fname'] . ' ' . $result['lname']); ?>
            &nbsp;&bull;&nbsp; <?php echo xlt('DOB'); ?>: <?php echo text(oeFormatShortDate($result['DOB_YMD'])); ?>
        </span>
        <?php endif; ?>
    </div>
    <div class="mdi-header-actions">
        <?php if ($pid) : ?>
        <a href="insurance_full.php?set_pid=<?php echo attr_url($pid); ?>" onclick="top.restoreSession();" class="mdi-ins-link">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 4l5 2.18V11c0 3.47-2.34 6.71-5 7.93-2.66-1.22-5-4.46-5-7.93V7.18L12 5z"/></svg>
            <?php echo xlt('Insurance'); ?>
        </a>
        <?php endif; ?>
        <a class="btn-cancel-main" href="demographics.php" onclick="top.restoreSession()">
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M19 11H7.83l4.88-4.88c.39-.39.39-1.03 0-1.42-.39-.39-1.02-.39-1.41 0l-6.59 6.59c-.39.39-.39 1.02 0 1.41l6.59 6.59c.39.39 1.02.39 1.41 0 .39-.39.39-1.02 0-1.41L7.83 13H19c.55 0 1-.45 1-1s-.45-1-1-1z"/></svg>
            <?php echo xlt('Back To Patient'); ?>
        </a>
        <button type="submit" class="btn-save-main" id="submit_btn" disabled="disabled">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="currentColor"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
            <?php echo xlt('Save'); ?>
        </button>
    </div>
</div>

<!-- ── Page Body ── -->
<div class="mdi-page-body">

<?php if (!empty($_GET['saved'])) : ?>
<div class="mdi-saved-banner">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
    <?php echo xlt('Patient demographics saved successfully.'); ?>
</div>
<?php endif; ?>

    <div class="mdi-section-card">
        <div class="mdi-section-card-header">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12zm0 2.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z"/></svg>
            <?php echo xlt('Patient Information'); ?>
        </div>
        <div class="mdi-section-card-body">
            <div class="demographicsEditContainer p-0">
                <ul class="tabNav" style="padding: 0 24px; background: var(--navy-pale) !important; border-bottom: 2px solid var(--border);">
                    <?php display_layout_tabs('DEM', $result, $result2); ?>
                </ul>
                <div class="tabContainer" style="border-radius:0;border:none;box-shadow:none;">
                    <?php display_layout_tabs_data_editable('DEM', $result, $result2); ?>
                </div>
            </div>
        </div>
    </div>

</div><!-- /mdi-page-body -->
</form>

<script>
var skipArray = [
<?php echo $condition_str; ?>
];

<?php if ($GLOBALS['new_validate'] == 0) { ?>
var f = document.forms[0];
if (f.form_phone_contact) phonekeyup(f.form_phone_contact, mypcc);
if (f.form_phone_home   ) phonekeyup(f.form_phone_home,    mypcc);
if (f.form_phone_biz    ) phonekeyup(f.form_phone_biz,     mypcc);
if (f.form_phone_cell   ) phonekeyup(f.form_phone_cell,    mypcc);
<?php } ?>

<?php if ($set_pid) { ?>
parent.left_nav.setPatient(<?php echo js_escape($result['fname'] . " " . $result['lname']) . "," . js_escape($pid) . "," . js_escape($result['pubpid']) . ",''," . js_escape(" " . xl('DOB') . ": " . oeFormatShortDate($result['DOB_YMD']) . " " . xl('Age') . ": " . getPatientAgeDisplay($result['DOB_YMD'])); ?>);
<?php } ?>

<?php echo $date_init; ?>
</script>

<?php require $GLOBALS['fileroot'] . "/library/options_listadd.inc.php"; ?>

<?php
$form_id = "DEM";
$use_validate_js = $GLOBALS['new_validate'];
include_once("$srcdir/validation/validation_script.js.php");
?>

<script>
var duplicateFieldsArray = [];
<?php if (($GLOBALS['gbl_edit_patient_form'] == '1') && (checkIfPatientValidationHookIsActive())) : ?>
    var f = $("form");
    var url = '<?php echo $GLOBALS['web_root'] . "/interface/modules/zend_modules/public/patientvalidation"; ?>';
    $("#submit_btn").attr("name","btnSubmit").attr("id","btnSubmit");
    $("#btnSubmit").click(function(event) {
        top.restoreSession();
        if (!submitme(<?php echo $GLOBALS['new_validate'] ? 1 : 0; ?>, event, 'DEM', constraints)) { event.preventDefault(); return; }
        <?php
        $mflist = "";
        $mfres = sqlStatement("SELECT field_id FROM layout_options WHERE form_id='DEM' AND uor>0 AND field_id!='' AND (edit_options LIKE '%D%' OR edit_options LIKE '%E%') ORDER BY group_id, seq");
        while ($mfrow = sqlFetchArray($mfres)) {
            $field_id = $mfrow['field_id'];
            if (strpos($field_id, 'em_') === 0) continue;
            if (!empty($mflist)) $mflist .= ",";
            $mflist .= js_escape($field_id);
        }
        ?>
        var flds = new Array(<?php echo $mflist; ?>);
        var separator = '?'; var valueIsChanged = false;
        for (var i = 0; i < flds.length; ++i) {
            var fval = $('#form_' + flds[i]).val();
            if (duplicateFieldsArray['#form_' + flds[i]] != fval) { valueIsChanged = true; }
            if (fval && fval != '') { url += separator; separator = '&'; url += 'mf_' + flds[i] + '=' + encodeURIComponent(fval); }
        }
        if (valueIsChanged) {
            event.preventDefault();
            url += '&page=edit&closeBeforeOpening=1&mf_id=' + encodeURIComponent($("[name='db_id']").val());
            dlgopen(url, '_blank', 700, 500);
        } else { $('#DEM').submit(); }
    });
<?php endif; ?>

$(function() {
    var flds = new Array(<?php echo ($mflist ?? ''); ?>);
    for (var i = 0; i < flds.length; ++i) {
        var fval = $('#form_' + flds[i]).val();
        duplicateFieldsArray['#form_' + flds[i]] = fval;
    }
    $(".sel2").select2({
        theme: "bootstrap4", dropdownAutoWidth: true, width: 'resolve',
        <?php require($GLOBALS['srcdir'] . '/js/xl/select2.js.php'); ?>
    });
    <?php if ($GLOBALS['usps_webtools_enable']) { ?>
    $("#value_id_text_postal_code").append(
        "<input type='button' class='btn btn-sm btn-secondary mb-1' onclick='address_verify()' value='<?php echo xla('Verify Address'); ?>' />");
    <?php } ?>
});
</script>

</body>
</html>
