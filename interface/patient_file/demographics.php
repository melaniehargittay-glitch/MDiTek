<?php

/**
 *
 * Patient summary screen.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Sharon Cohen <sharonco@matrix.co.il>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Tyler Wrenn <tyler@tylerwrenn.com>
 * @author    Robert Down <robertdown@live.com>
 * @copyright Copyright (c) 2017-2020 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2017 Sharon Cohen <sharonco@matrix.co.il>
 * @copyright Copyright (c) 2018-2020 Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2018 Ranganath Pathak <pathak@scrs1.org>
 * @copyright Copyright (c) 2020 Tyler Wrenn <tyler@tylerwrenn.com>
 * @copyright Copyright (c) 2021-2022 Robert Down <robertdown@live.com
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once("../history/history.inc.php");
require_once("$srcdir/clinical_rules.php");
require_once("$srcdir/group.inc.php");
require_once(__DIR__ . "/../../../library/appointments.inc.php");

use OpenEMR\Billing\EDI270;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\SessionUtil;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\Events\Patient\Summary\Card\RenderEvent as CardRenderEvent;
use OpenEMR\Events\Patient\Summary\Card\SectionEvent;
use OpenEMR\Events\Patient\Summary\Card\RenderModel;
use OpenEMR\Events\Patient\Summary\Card\CardInterface;
use OpenEMR\Events\PatientDemographics\ViewEvent;
use OpenEMR\Events\PatientDemographics\RenderEvent;
use OpenEMR\FHIR\SMART\SmartLaunchController;
use OpenEMR\Menu\PatientMenuRole;
use OpenEMR\OeUI\OemrUI;
use OpenEMR\Patient\Cards\PortalCard;
use OpenEMR\Reminder\BirthdayReminder;
use Symfony\Component\EventDispatcher\EventDispatcher;

$twig = new TwigContainer(null, $GLOBALS['kernel']);

// Set session for pid (via setpid). Also set session for encounter (if applicable)
if (isset($_GET['set_pid'])) {
    require_once("$srcdir/pid.inc.php");
    setpid($_GET['set_pid']);
    if (isset($_GET['set_encounterid']) && ((int)$_GET['set_encounterid'] > 0)) {
        $encounter = (int)$_GET['set_encounterid'];
        SessionUtil::setSession('encounter', $encounter);
    }
}

// Note: it would eventually be a good idea to move this into
// it's own module that people can remove / add if they don't
// want smart support in their system.
$smartLaunchController = new SMARTLaunchController($GLOBALS["kernel"]->getEventDispatcher());
$smartLaunchController->registerContextEvents();

/**
 * @var EventDispatcher
 */
$ed = $GLOBALS['kernel']->getEventDispatcher();

$active_reminders = false;
$all_allergy_alerts = false;
if ($GLOBALS['enable_cdr']) {
    //CDR Engine stuff
    if ($GLOBALS['enable_allergy_check'] && $GLOBALS['enable_alert_log']) {
        //Check for new allergies conflicts and throw popup if any exist(note need alert logging to support this)
        $new_allergy_alerts = allergy_conflict($pid, 'new', $_SESSION['authUser']);
        if (!empty($new_allergy_alerts)) {
            $pod_warnings = '';
            foreach ($new_allergy_alerts as $new_allergy_alert) {
                $pod_warnings .= js_escape($new_allergy_alert) . ' + "\n"';
            }
            $allergyWarningMessage = '<script>alert(' . xlj('WARNING - FOLLOWING ACTIVE MEDICATIONS ARE ALLERGIES') . ' + "\n" + ' . $pod_warnings . ')</script>';
        }
    }

    if ((empty($_SESSION['alert_notify_pid']) || ($_SESSION['alert_notify_pid'] != $pid)) && isset($_GET['set_pid']) && $GLOBALS['enable_cdr_crp']) {
        // showing a new patient, so check for active reminders and allergy conflicts, which use in active reminder popup
        $active_reminders = active_alert_summary($pid, "reminders-due", '', 'default', $_SESSION['authUser'], true);
        if ($GLOBALS['enable_allergy_check']) {
            $all_allergy_alerts = allergy_conflict($pid, 'all', $_SESSION['authUser'], true);
        }
    }
    SessionUtil::setSession('alert_notify_pid', $pid);
    // can not output html until after above setSession call
    if (!empty($allergyWarningMessage)) {
        echo $allergyWarningMessage;
    }
}
//Check to see is only one insurance is allowed
if ($GLOBALS['insurance_only_one']) {
    $insurance_array = array('primary');
} else {
    $insurance_array = array('primary', 'secondary', 'tertiary');
}

function print_as_money($money)
{
    preg_match("/(\d*)\.?(\d*)/", $money, $moneymatches);
    $tmp = wordwrap(strrev($moneymatches[1]), 3, ",", 1);
    $ccheck = strrev($tmp);
    if ($ccheck[0] == ",") {
        $tmp = substr($ccheck, 1, strlen($ccheck) - 1);
    }

    if ($moneymatches[2] != "") {
        return "$ " . strrev($tmp) . "." . $moneymatches[2];
    } else {
        return "$ " . strrev($tmp);
    }
}

// get an array from Photos category
function pic_array($pid, $picture_directory)
{
    $pics = array();
    $sql_query = "select documents.id from documents join categories_to_documents " .
        "on documents.id = categories_to_documents.document_id " .
        "join categories on categories.id = categories_to_documents.category_id " .
        "where categories.name like ? and documents.foreign_id = ? and documents.deleted = 0";
    if ($query = sqlStatement($sql_query, array($picture_directory, $pid))) {
        while ($results = sqlFetchArray($query)) {
            array_push($pics, $results['id']);
        }
    }

    return ($pics);
}

// Get the document ID's in a specific catg.
// this is only used in one place, here for id photos
function get_document_by_catg($pid, $doc_catg, $limit = 1)
{
    $results = null;

    if ($pid and $doc_catg) {
        $query = sqlStatement("SELECT d.id, d.date, d.url
            FROM documents AS d, categories_to_documents AS cd, categories AS c
            WHERE d.foreign_id = ?
            AND cd.document_id = d.id
            AND c.id = cd.category_id
            AND c.name LIKE ?
            ORDER BY d.date DESC LIMIT " . escape_limit($limit), array($pid, $doc_catg));
    }
    while ($result = sqlFetchArray($query)) {
        $results[] = $result['id'];
    }
    return ($results ?? false);
}

function isPortalEnabled(): bool
{
    if (
        !$GLOBALS['portal_onsite_two_enable']
    ) {
        return false;
    }

    return true;
}

function isPortalSiteAddressValid(): bool
{
    if (
        // maybe can use filter_var() someday but the default value in GLOBALS
        // fails with FILTER_VALIDATE_URL
        !isset($GLOBALS['portal_onsite_two_address'])
    ) {
        return false;
    }

    return true;
}

function isPortalAllowed($pid): bool
{
    $return = false;

    $portalStatus = sqlQuery("SELECT allow_patient_portal FROM patient_data WHERE pid = ?", [$pid]);
    if ($portalStatus['allow_patient_portal'] == 'YES') {
        $return = true;
    }
    return $return;
}

function isApiAllowed($pid): bool
{
    $return = false;

    $apiStatus = sqlQuery("SELECT prevent_portal_apps FROM patient_data WHERE pid = ?", [$pid]);
    if (strtoupper($apiStatus['prevent_portal_apps'] ?? '') != 'YES') {
        $return = true;
    }
    return $return;
}

function areCredentialsCreated($pid): bool
{
    $return = false;
    $credentialsCreated = sqlQuery("SELECT date_created FROM `patient_access_onsite` WHERE `pid`=?", [$pid]);
    if ($credentialsCreated['date_created'] ?? null) {
        $return = true;
    }

    return $return;
}

function isContactEmail($pid): bool
{
    $return = false;

    $email = sqlQuery("SELECT email, email_direct FROM patient_data WHERE pid = ?", [$pid]);
    if (!empty($email['email']) || !empty($email['email_direct'])) {
        $return = true;
    }
    return $return;
}

function isEnforceSigninEmailPortal(): bool
{
    if (
        $GLOBALS['enforce_signin_email']
    ) {
        return true;
    }

    return false;
}

function deceasedDays($days_deceased)
{
    $deceased_days = intval($days_deceased['days_deceased'] ?? '');
    if ($deceased_days == 0) {
        $num_of_days = xl("Today");
    } elseif ($deceased_days == 1) {
        $num_of_days =  $deceased_days . " " . xl("day ago");
    } elseif ($deceased_days > 1 && $deceased_days < 90) {
        $num_of_days =  $deceased_days . " " . xl("days ago");
    } elseif ($deceased_days >= 90 && $deceased_days < 731) {
        $num_of_days =  "~" . round($deceased_days / 30) . " " . xl("months ago");  // function intdiv available only in php7
    } elseif ($deceased_days >= 731) {
        $num_of_days =  xl("More than") . " " . round($deceased_days / 365) . " " . xl("years ago");
    }

    if (strlen($days_deceased['date_deceased'] ?? '') > 10 && $GLOBALS['date_display_format'] < 1) {
        $deceased_date = substr($days_deceased['date_deceased'], 0, 10);
    } else {
        $deceased_date = oeFormatShortDate($days_deceased['date_deceased'] ?? '');
    }

    return xlt("Inactive") . " - " . text($deceased_date) . " (" . text($num_of_days) . ")";
}

$deceased = is_patient_deceased($pid);


// Display image in 'widget style'
function image_widget($doc_id, $doc_catg)
{
    global $pid, $web_root;
    $docobj = new Document($doc_id);
    $image_file = $docobj->get_url_file();
    $image_file_name = $docobj->get_name();
    $image_width = $GLOBALS['generate_doc_thumb'] == 1 ? '' : 'width=100';
    $extension = substr($image_file_name, strrpos($image_file_name, "."));
    $viewable_types = array('.png', '.jpg', '.jpeg', '.png', '.bmp', '.PNG', '.JPG', '.JPEG', '.PNG', '.BMP');
    if (in_array($extension, $viewable_types)) { // extension matches list
        $to_url = "<td> <a href = '$web_root" .
            "/controller.php?document&retrieve&patient_id=" . attr_url($pid) . "&document_id=" . attr_url($doc_id) . "&as_file=false&original_file=true&disable_exit=false&show_original=true'" .
            " onclick='top.restoreSession();' class='image_modal'>" .
            " <img src = '$web_root" .
            "/controller.php?document&retrieve&patient_id=" . attr_url($pid) . "&document_id=" . attr_url($doc_id) . "&as_file=false'" .
            " $image_width alt='" . attr($doc_catg) . ":" . attr($image_file_name) . "'>  </a> </td> <td class='align-middle'>" .
            text($doc_catg) . '<br />&nbsp;' . text($image_file_name) . "</td>";
    } else {
        $to_url = "<td> <a href='" . $web_root . "/controller.php?document&retrieve" .
            "&patient_id=" . attr_url($pid) . "&document_id=" . attr_url($doc_id) . "'" .
            " onclick='top.restoreSession()' class='btn btn-primary btn-sm'>" .
            "<span>" .
            xlt("View") . "</a> &nbsp;" .
            text("$doc_catg - $image_file_name") .
            "</span> </td>";
    }

    echo "<table><tr>";
    echo $to_url;
    echo "</tr></table>";
}

// Determine if the Vitals form is in use for this site.
$tmp = sqlQuery("SELECT count(*) AS count FROM registry WHERE directory = 'vitals' AND state = 1");
$vitals_is_registered = $tmp['count'];

// Get patient/employer/insurance information.
//
$result = getPatientData($pid, "*, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");
$result2 = getEmployerData($pid);
$result3 = getInsuranceData(
    $pid,
    "primary",
    "copay,
    provider,
    DATE_FORMAT(`date`,'%Y-%m-%d') as effdate,
    DATE_FORMAT(`date_end`,'%Y-%m-%d') as effdate_end"
);

// Fetch pnotes count for the Notes button badge
$_pnotes_count_row = sqlQuery(
    "SELECT COUNT(*) AS cnt FROM pnotes WHERE pid = ? AND deleted != 1 AND activity = 1 AND groupname = 'Patient File - MDI'",
    [$pid]
);
$_pnotes_count = (int)($_pnotes_count_row['cnt'] ?? 0);
$insco_name = "";
if (!empty($result3['provider'])) {   // Use provider in case there is an ins record w/ unassigned insco
    $insco_name = getInsuranceProvider($result3['provider']);
}

$arrOeUiSettings = array(
    'page_id' => 'core.mrd',
    'heading_title' => xl('Patient File'),
    'include_patient_name' => true,
    'expandable' => true,
    'expandable_files' => array(), //all file names need suffix _xpd
    'action' => "", //conceal, reveal, search, reset, link or back
    'action_title' => "",
    'action_href' => "", //only for actions - reset, link or back
    'show_help_icon' => true,
    'help_file_name' => "medical_dashboard_help.php"
);
$oemr_ui = new OemrUI($arrOeUiSettings);
?>
<!DOCTYPE html>
<html>

<head>
    <?php
    Header::setupHeader(['common']);
    require_once("$srcdir/options.js.php");
    ?>
    <script>
        // Process click on diagnosis for referential cds popup.
        function referentialCdsClick(codetype, codevalue) {
            top.restoreSession();
            // Force a new window instead of iframe to address cross site scripting potential
            dlgopen('../education.php?type=' + encodeURIComponent(codetype) + '&code=' + encodeURIComponent(codevalue), '_blank', 1024, 750,true);
        }

        function oldEvt(apptdate, eventid) {
            let title = <?php echo xlj('Appointments'); ?>;
            dlgopen('../../main/calendar/add_edit_event.php?date=' + encodeURIComponent(apptdate) + '&eid=' + encodeURIComponent(eventid), '_blank', 800, 500, '', title);
        }

        function newEvt() {
            let title = <?php echo xlj('Appointments'); ?>;
            // Use server-side date to avoid JS Date locale/timezone issues
            let dateStr = <?php echo js_escape(date('Ymd')); ?>; // add_edit_event.php expects Ymd no dashes
            top.restoreSession();
            dlgopen('../../main/calendar/add_edit_event.php?date=' + encodeURIComponent(dateStr) + '&pid=' + <?php echo js_escape($pid); ?>, '_blank', 800, 500, '', title);
            return false;
        }

        function advdirconfigure() {
            dlgopen('advancedirectives.php', '_blank', 400, 500);
        }

        function refreshme() {
            top.restoreSession();
            location.reload();
        }

        // ── Force tab to show "Patient Demographics" instead of "Default Visit Summary" ──
        (function() {
            var _mdiTabTitle = <?php echo js_escape(xl('Patient Demographics')); ?>;
            document.title = _mdiTabTitle;
            // Update parent tab label if OpenEMR tabs framework is available
            try {
                if (typeof top !== 'undefined' && top.tab_title) {
                    top.tab_title(_mdiTabTitle);
                }
                if (typeof top !== 'undefined' && top.set_tab_title) {
                    top.set_tab_title(_mdiTabTitle);
                }
                // OpenEMR's tabs framework watches for this event
                if (window.parent && window.parent.document) {
                    var tabFrames = window.parent.document.querySelectorAll('iframe');
                    tabFrames.forEach(function(f) {
                        try {
                            if (f.contentWindow === window) {
                                // Find the tab label element and update it
                                var tabId = f.id;
                                if (tabId) {
                                    var tabLabel = window.parent.document.querySelector('[data-frame="' + tabId + '"] .tab-label, #' + tabId + '_label, .ui-tabs a[href="#' + tabId + '"]');
                                    if (tabLabel) tabLabel.textContent = _mdiTabTitle;
                                }
                            }
                        } catch(e) {}
                    });
                }
            } catch(e) {}
        })();

        function mdiNavGo(url) {
            top.restoreSession();
            // Navigate in the main content area via the left_nav frame if available
            if (typeof parent !== 'undefined' && parent.left_nav && typeof parent.left_nav.loadFrame === 'function') {
                parent.left_nav.loadFrame('msg1', 'msg', url);
            } else if (typeof top !== 'undefined' && top.left_nav && typeof top.left_nav.loadFrame === 'function') {
                top.left_nav.loadFrame('msg1', 'msg', url);
            } else {
                window.location.href = url;
            }
        }

function openQuickChargeModal(pid) {
    top.restoreSession();

    var feeSheetUrl = '<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_ensure_encounter.php'
        + '?pid=' + encodeURIComponent(pid);

    dlgopen(
        feeSheetUrl,
        '',
        1400,
        750,
        '',
        <?php echo xlj('Quick Charges'); ?>,
        {
            allowResize: true,
            allowDrag:   false,
            dialogId:    'quickcharge_modal',
            onClosed:    'refreshme',
            type:        'iframe'
        }
    );

    // Strip the theme stethoscope placeholder via MutationObserver —
    // dlgopen sets backgroundImage as inline style which overrides CSS
    var obs = new MutationObserver(function() {
        var body = document.querySelector('#quickcharge_modal .modal-body');
        if (body) {
            body.style.backgroundImage = 'none';
            body.style.backgroundColor = '#fff';
            var content = body.closest('.modal-content');
            if (content) { content.style.backgroundImage = 'none'; }
            obs.disconnect();
        }
    });
    obs.observe(document.body, { childList: true, subtree: true });
}

function openPaymentModal(pid, tab) {
    top.restoreSession();

    var payUrl = '<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/mdi_payments_ledger.php'
        + '?pid=' + encodeURIComponent(pid)
        + (tab ? '&tab=' + encodeURIComponent(tab) : '');

    dlgopen(
        payUrl,
        '',
        1100,
        720,
        '',
        <?php echo xlj('Payment'); ?>,
        {
            allowResize: true,
            allowDrag:   false,
            dialogId:    'payment_modal',
            onClosed:    'refreshme',
            type:        'iframe'
        }
    );

    // Strip background image from modal
    var obs = new MutationObserver(function() {
        var body = document.querySelector('#payment_modal .modal-body');
        if (body) {
            body.style.backgroundImage = 'none';
            body.style.backgroundColor = '#fff';
            var content = body.closest('.modal-content');
            if (content) { content.style.backgroundImage = 'none'; }
            obs.disconnect();
        }
    });
    obs.observe(document.body, { childList: true, subtree: true });
}
function deleteQuickCharge(encounterId, pid, btnEl) {
    if (!confirm(<?php echo xlj('Delete this Quick Charge encounter? This cannot be undone.'); ?>)) {
        return false;
    }

    top.restoreSession();

    var row = btnEl ? $(btnEl).closest('tr') : null;

    var csrfToken = document.getElementById('mdi_csrf_token') ? document.getElementById('mdi_csrf_token').value : '';

    fetch(
        '<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_delete_encounter.php',
        {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body:
                'csrf_token_form=' + encodeURIComponent(csrfToken) +
                '&encounter=' + encodeURIComponent(encounterId) +
                '&pid=' + encodeURIComponent(pid)
        }
    )
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (row) {
                row.fadeOut(300, function() {
                    $(this).remove();
                    try { if (top.left_nav) top.left_nav.clearEncounter(); } catch(e) {}
                    refreshme();
                });
            } else {
                try { if (top.left_nav) top.left_nav.clearEncounter(); } catch(e) {}
                refreshme();
            }
        } else {
            alert(data.error || <?php echo xlj('Delete failed.'); ?>);
        }
    })
    .catch(() => {
        alert(<?php echo xlj('Delete failed.'); ?>);
    });

    return false;
}


        // edit prescriptions dialog.
        // called from stats.php.
        //
        function editScripts(url) {
            var AddScript = function() {
                var __this = $(this);
                __this.find("#clearButton").css("display", "");
                __this.find("#backButton").css("display", "");
                __this.find("#addButton").css("display", "none");

                var iam = top.frames.editScripts;
                iam.location.href = '<?php echo $GLOBALS['webroot'] ?>/controller.php?prescription&edit&id=0&pid=' + <?php echo js_url($pid); ?>;
            };
            var ListScripts = function() {
                var __this = $(this);
                __this.find("#clearButton").css("display", "none");
                __this.find("#backButton").css("display", "none");
                __this.find("#addButton").css("display", "");
                var iam = top.frames.editScripts
                iam.location.href = '<?php echo $GLOBALS['webroot'] ?>/controller.php?prescription&list&id=' + <?php echo js_url($pid); ?>;
            };

            let title = <?php echo xlj('Prescriptions'); ?>;
            let w = 960; // for weno width

            dlgopen(url, 'editScripts', w, 400, '', '', {
                buttons: [{
                        text: <?php echo xlj('Add'); ?>,
                        close: false,
                        id: 'addButton',
                        class: 'btn-primary btn-sm',
                        click: AddScript
                    },
                    {
                        text: <?php echo xlj('Clear'); ?>,
                        close: false,
                        id: 'clearButton',
                        style: 'display:none;',
                        class: 'btn-primary btn-sm',
                        click: AddScript
                    },
                    {
                        text: <?php echo xlj('Back'); ?>,
                        close: false,
                        id: 'backButton',
                        style: 'display:none;',
                        class: 'btn-primary btn-sm',
                        click: ListScripts
                    },
                    {
                        text: <?php echo xlj('Quit'); ?>,
                        close: true,
                        id: 'doneButton',
                        class: 'btn-secondary btn-sm'
                    }
                ],
                onClosed: 'refreshme',
                allowResize: false,
                allowDrag: false,
                dialogId: 'editscripts',
                type: 'iframe'
            });
        }

        /**
         * async function fetchHtml(...)
         *
         * @param {*} url
         * @param {boolean} embedded
         * @param {boolean} sessionRestore
         * @returns {text}
         */
        async function fetchHtml(url, embedded = false, sessionRestore = false) {
            if (sessionRestore === true) {
                // restore cookie before fetch.
                top.restoreSession();
            }
            let csrf = new FormData;
            // a security given.
            csrf.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
            if (embedded === true) {
                // special formatting in certain widgets.
                csrf.append("embeddedScreen", true);
            }

            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                body: csrf
            });
            return await response.text();
        }

        /**
         * async function placeHtml(...) will await fetch of html then place in divId.
         * This function will return a promise for use to init various items regarding
         * inserted HTML if needed.
         * If divId does not exist, then will skip.
         * Example
         *
         * @param {*} url
         * @param {string} divId id
         * @param {boolean} embedded
         * @param {boolean} sessionRestore
         * @returns {object} promise
         */
        async function placeHtml(url, divId, embedded = false, sessionRestore = false) {
            const contentDiv = document.getElementById(divId);
            if (contentDiv) {
                await fetchHtml(url, embedded, sessionRestore).then(fragment => {
                    contentDiv.innerHTML = fragment;
                });
            }
        }

        if (typeof load_location === 'undefined') {
            function load_location(location) {
                top.restoreSession();
                document.location = location;
            }
        }


        $(function() {
            var msg_updation = '';
            <?php
            if ($GLOBALS['erx_enable']) {
                $soap_status = sqlStatement("select soap_import_status,pid from patient_data where pid=? and soap_import_status in ('1','3')", array($pid));
                while ($row_soapstatus = sqlFetchArray($soap_status)) { ?>
                    top.restoreSession();
                    $.ajax({
                        type: "POST",
                        url: "../../soap_functions/soap_patientfullmedication.php",
                        dataType: "html",
                        data: {
                            patient: <?php echo js_escape($row_soapstatus['pid']); ?>,
                        },
                        async: false,
                        success: function(thedata) {
                            //alert(thedata);
                            msg_updation += thedata;
                        },
                        error: function() {
                            alert('ajax error');
                        }
                    });

                    top.restoreSession();
                    $.ajax({
                        type: "POST",
                        url: "../../soap_functions/soap_allergy.php",
                        dataType: "html",
                        data: {
                            patient: <?php echo js_escape($row_soapstatus['pid']); ?>,
                        },
                        async: false,
                        success: function(thedata) {
                            //alert(thedata);
                            msg_updation += thedata;
                        },
                        error: function() {
                            alert('ajax error');
                        }
                    });
                    <?php
                    if ($GLOBALS['erx_import_status_message']) { ?>
                        if (msg_updation)
                            alert(msg_updation);
                        <?php
                    }
                }
            }
            ?>

            // load divs
            placeHtml("stats.php", "stats_div", true).then(() => {
                // ── MDItek: Diagnosis edit intercept ─────────────────────────
                document.getElementById('stats_div').addEventListener('click', function(e) {
                    var el = e.target;
                    for (var i = 0; i < 6; i++) {
                        if (!el || el === document) break;
                        var onclick = el.getAttribute('onclick') || '';
                        if (onclick.indexOf('stats_full.php') !== -1 &&
                            onclick.indexOf('medical_problem') !== -1) {
                            e.preventDefault();
                            e.stopPropagation();
                            var ids = window._mdiIssueIds || [];
                            if (ids.length === 1) {
                                mdiOpenDiagnosisEditor(ids[0]);
                            } else if (ids.length > 1) {
                                mdiShowIssuePicker(ids);
                            } else {
                                mdiOpenDiagnosisEditor('');
                            }
                            return;
                        }
                        el = el.parentElement;
                    }
                }, true);
            });
            placeHtml("pnotes_fragment.php", 'pnotes_ps_expand').then(() => {
                // must be delegated event!
                $(this).on("click", ".complete_btn", function() {
                    let btn = $(this);
                    let csrf = new FormData;
                    csrf.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
                    fetch("pnotes_fragment.php?docUpdateId=" + encodeURIComponent(btn.attr('data-id')), {
                            method: "POST",
                            credentials: 'same-origin',
                            body: csrf
                        })
                        .then(function() {
                            placeHtml("pnotes_fragment.php", 'pnotes_ps_expand');
                        });
                });
            });
            placeHtml("disc_fragment.php", "disclosures_ps_expand");
            placeHtml("labdata_fragment.php", "labdata_ps_expand");
            placeHtml("track_anything_fragment.php", "track_anything_ps_expand");
            <?php if ($vitals_is_registered && AclMain::aclCheckCore('patients', 'med')) { ?>
                // Initialize the Vitals form if it is registered and user is authorized.
                placeHtml("vitals_fragment.php", "vitals_ps_expand");
            <?php } ?>

            <?php if ($GLOBALS['enable_cdr'] && $GLOBALS['enable_cdr_crw']) { ?>
                placeHtml("clinical_reminders_fragment.php", "clinical_reminders_ps_expand", true, true).then(() => {
                    // (note need to place javascript code here also to get the dynamic link to work)
                    $(".medium_modal").on('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        dlgopen('', '', 800, 200, '', '', {
                            buttons: [{
                                text: <?php echo xlj('Close'); ?>,
                                close: true,
                                style: 'secondary btn-sm'
                            }],
                            onClosed: 'refreshme',
                            allowResize: false,
                            allowDrag: false,
                            dialogId: 'demreminder',
                            type: 'iframe',
                            url: $(this).attr('href')
                        });
                    });
                });
            <?php } // end crw
            ?>

            <?php if ($GLOBALS['enable_cdr'] && $GLOBALS['enable_cdr_prw']) { ?>
                placeHtml("patient_reminders_fragment.php", "patient_reminders_ps_expand", false, true);
            <?php } // end prw
            ?>

            <?php
            // Initialize for each applicable LBF form.
            $gfres = sqlStatement("SELECT grp_form_id
                FROM layout_group_properties
                WHERE grp_form_id LIKE 'LBF%'
                    AND grp_group_id = ''
                    AND grp_repeats > 0
                    AND grp_activity = 1
                ORDER BY grp_seq, grp_title");
            while ($gfrow = sqlFetchArray($gfres)) { ?>
                $(<?php echo js_escape("#" . $gfrow['grp_form_id'] . "_ps_expand"); ?>).load("lbf_fragment.php?formname=" + <?php echo js_url($gfrow['grp_form_id']); ?>, {
                    csrf_token_form: <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>
                });
            <?php } ?>
            tabbify();

            // modal for dialog boxes
            $(".large_modal").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 1000, 600, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    allowResize: false,
                    allowDrag: false,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            $(".rx_modal").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var title = <?php echo xlj('Amendments'); ?>;
                dlgopen('', 'editAmendments', 800, 300, '', title, {
                    onClosed: 'refreshme',
                    allowResize: false,
                    allowDrag: false,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            // modal for image viewer
            $(".image_modal").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 400, 300, '', <?php echo xlj('Patient Images'); ?>, {
                    allowResize: false,
                    allowDrag: false,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            $(".deleter").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 600, 360, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    //onClosed: 'imdeleted',
                    allowResize: false,
                    allowDrag: false,
                    dialogId: 'patdel',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            $(".iframe1").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 350, 300, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    allowResize: false,
                    allowDrag: false,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });
            // for patient portal
            $(".small_modal").on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                dlgopen('', '', 550, 550, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    allowResize: false,
                    allowDrag: false,
                    dialogId: '',
                    type: 'iframe',
                    url: $(this).attr('href')
                });
            });

            // ── Override dlgopen to force payment/checkout modals wider ──
        // Also suppress interact.js errors inside payment/checkout iframes
        (function() {
            // Suppress interact.js undefined errors in iframes
            function suppressInteractErrors(iframeEl) {
                if (!iframeEl) return;
                iframeEl.addEventListener('load', function() {
                    try {
                        var iw = iframeEl.contentWindow;
                        if (!iw) return;
                        // Override onerror to swallow interact.js errors
                        var origErr = iw.onerror;
                        iw.onerror = function(msg, url, line, col, err) {
                            // Swallow errors from interact.js or undefined errors
                            if (!msg || msg === 'undefined' || !url ||
                                (url && url.indexOf('interact') !== -1)) {
                                return true; // suppressed
                            }
                            if (origErr) return origErr(msg, url, line, col, err);
                            return false;
                        };
                    } catch(e) {}
                });
            }

            // Watch for new iframes added to modals
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(m) {
                    m.addedNodes.forEach(function(node) {
                        if (node.tagName === 'IFRAME') {
                            suppressInteractErrors(node);
                        } else if (node.querySelectorAll) {
                            node.querySelectorAll('iframe').forEach(suppressInteractErrors);
                        }
                    });
                });
            });
            observer.observe(document.body, { childList: true, subtree: true });

            // Also override dlgopen width for payment pages
            var _origDlgopen = window.dlgopen;
            if (typeof _origDlgopen === 'function') {
                window.dlgopen = function(url, winName, w, h) {
                    var args = Array.prototype.slice.call(arguments);
                    if (typeof url === 'string' &&
                        (url.indexOf('front_payment') !== -1 || url.indexOf('pos_checkout') !== -1)) {
                        args[2] = 1400;
                        args[3] = 750;
                    }
                    return _origDlgopen.apply(this, args);
                };
            }
        })();

        // Override OpenEMR's utility.js error handler AFTER it loads
        // to suppress interact.js / undefined errors from iframes
        window.addEventListener('load', function() {
            var _prevOnError = window.onerror;
            window.onerror = function(msg, url, line, col, err) {
                var msgStr = String(msg || '');
                var urlStr = String(url || '');
                if (!msg || msgStr === 'undefined' || msgStr === 'Script error.' ||
                    urlStr.indexOf('interact') !== -1 ||
                    urlStr.indexOf('compact_style') !== -1 ||
                    urlStr.indexOf('front_payment') !== -1 ||
                    urlStr.indexOf('payment') !== -1 ||
                    (!url && !line && !col) ||
                    (!url || url === 'undefined')) {
                    return true;
                }
                if (_prevOnError) return _prevOnError.apply(this, arguments);
                return false;
            };
        });

        // Also suppress interact.js undefined errors (missing asset on this server)
        (function() {
            var _prevOnError = window.onerror;
            window.onerror = function(msg, url, line, col, err) {
                var msgStr = String(msg || '');
                var urlStr = String(url || '');
                // Suppress: undefined errors, interact.js errors, script load errors, payment iframe errors
                if (msgStr === 'undefined' || msgStr === '' || !msg ||
                    urlStr.indexOf('interact') !== -1 ||
                    urlStr.indexOf('compact_style') !== -1 ||
                    urlStr.indexOf('front_payment') !== -1 ||
                    urlStr.indexOf('payment') !== -1 ||
                    (msgStr.indexOf('Script error') !== -1 && !line) ||
                    msgStr.indexOf('undefined is not') !== -1 ||
                    (!url && !line && !col) ||
                    (!url || url === 'undefined')) {
                    return true; // swallow
                }
                if (_prevOnError) return _prevOnError.apply(this, arguments);
                return false;
            };
        })();

        function openReminderPopup() {
                top.restoreSession()
                dlgopen('', 'reminders', 500, 250, '', '', {
                    buttons: [{
                        text: <?php echo xlj('Close'); ?>,
                        close: true,
                        style: 'secondary btn-sm'
                    }],
                    allowResize: false,
                    allowDrag: false,
                    dialogId: '',
                    type: 'iframe',
                    url: $("#reminder_popup_link").attr('href')
                });
            }

            <?php if ($GLOBALS['patient_birthday_alert']) {
                // To display the birthday alert:
                //  1. The patient is not deceased
                //  2. The birthday is today (or in the past depending on global selection)
                //  3. The notification has not been turned off (or shown depending on global selection) for this year
                $birthdayAlert = new BirthdayReminder($pid, $_SESSION['authUserID']);
                if ($birthdayAlert->isDisplayBirthdayAlert()) {
                    ?>
                    // show the active reminder modal
                    dlgopen('', 'bdayreminder', 300, 170, '', false, {
                        allowResize: false,
                        allowDrag: false,
                        dialogId: '',
                        type: 'iframe',
                        url: $("#birthday_popup").attr('href')
                    });

                <?php } elseif ($active_reminders || $all_allergy_alerts) { ?>
                    openReminderPopup();
                <?php } ?>
            <?php } elseif ($active_reminders || $all_allergy_alerts) { ?>
                openReminderPopup();
            <?php } ?>

            // $(".card-title").on('click', "button", (e) => {
            //     console.debug("click");
            //     updateUserVisibilitySetting(e);
            // });
        });

        /**
         * Change the preference to expand/collapse a given card.
         *
         * For the given e element, find the corresponding card body, determine if it is collapsed
         * or shown, and then save the state to the user preferences via an async fetch call POST'ing
         * the updated setting.
         *
         * @var e element The Button that was clicked to collapse/expand the card
         */
        async function updateUserVisibilitySetting(e) {
            const targetID = e.target.getAttribute("data-target");
            const target = document.querySelector(targetID);
            const targetStr = targetID.substring(1);

            let formData = new FormData();
            formData.append("csrf_token_form", <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>);
            formData.append("target", targetStr);
            formData.append("mode", (target.classList.contains("show")) ? 0 : 1);

            const response = await fetch("../../../library/ajax/user_settings.php", {
                method: "POST",
                credentials: 'same-origin',
                body: formData,
            });

            const update = await response.text();
            return update;
        }

        // Update the User's visibility setting when the card header is clicked
        function cardTitleButtonClickListener() {
            const buttons = document.querySelectorAll(".card-title a[data-toggle='collapse']");
            buttons.forEach((b) => {
                b.addEventListener("click", (e) => {
                    updateUserVisibilitySetting(e);
                });
            });
        }

        // JavaScript stuff to do when a new patient is set.
        //
        function setMyPatient() {
            <?php
            if (isset($_GET['set_pid'])) {
                $date_of_death = is_patient_deceased($pid);
                if (!empty($date_of_death)) {
                    $date_of_death = $date_of_death['date_deceased'];
                }
                ?>
                parent.left_nav.setPatient(<?php echo js_escape($result['fname'] . " " . $result['lname']) .
                                                "," . js_escape($pid) . "," . js_escape($result['pubpid']) . ",'',";
                if (empty($date_of_death)) {
                    echo js_escape(" " . xl('DOB') . ": " . oeFormatShortDate($result['DOB_YMD']) . " " . xl('Age') . ": " . getPatientAgeDisplay($result['DOB_YMD']));
                } else {
                    echo js_escape(" " . xl('DOB') . ": " . oeFormatShortDate($result['DOB_YMD']) . " " . xl('Inactive') . ": " . oeFormatShortDate(substr($date_of_death, 0, 10)));
                } ?>);
                var EncounterDateArray = new Array;
                var CalendarCategoryArray = new Array;
                var EncounterIdArray = new Array;
                var Count = 0;
                <?php
                //Encounter details are stored to javacript as array.
                $result4 = sqlStatement("SELECT fe.encounter,fe.date,openemr_postcalendar_categories.pc_catname FROM form_encounter AS fe " .
                    " left join openemr_postcalendar_categories on fe.pc_catid=openemr_postcalendar_categories.pc_catid  WHERE fe.pid = ? order by fe.date desc", array($pid));
                if (sqlNumRows($result4) > 0) {
                    while ($rowresult4 = sqlFetchArray($result4)) { ?>
                        EncounterIdArray[Count] = <?php echo js_escape($rowresult4['encounter']); ?>;
                        EncounterDateArray[Count] = <?php echo js_escape(oeFormatShortDate(date("Y-m-d", strtotime($rowresult4['date'])))); ?>;
                        CalendarCategoryArray[Count] = <?php echo js_escape(xl_appt_category($rowresult4['pc_catname'])); ?>;
                        Count++;
                        <?php
                    }
                }
                ?>
                parent.left_nav.setPatientEncounter(EncounterIdArray, EncounterDateArray, CalendarCategoryArray);
                <?php
            } // end setting new pid
            ?>
            parent.left_nav.syncRadios();
            <?php if ((isset($_GET['set_pid'])) && (isset($_GET['set_encounterid'])) && (intval($_GET['set_encounterid']) > 0)) {
                $query_result = sqlQuery("SELECT `date` FROM `form_encounter` WHERE `encounter` = ?", array($encounter)); ?>
                encurl = 'encounter/encounter_top.php?set_encounter=' + <?php echo js_url($encounter); ?> + '&pid=' + <?php echo js_url($pid); ?>;
                parent.left_nav.setEncounter(<?php echo js_escape(oeFormatShortDate(date("Y-m-d", strtotime($query_result['date'])))); ?>, <?php echo js_escape($encounter); ?>, 'enc');
                top.restoreSession();
                parent.left_nav.loadFrame('enc2', 'enc', 'patient_file/' + encurl);
            <?php } // end setting new encounter id (only if new pid is also set)
            ?>
        }

        $(window).on('load', function() {
            setMyPatient();
            // Keep mdi-current-pid in sync with the active patient
            var pidEl = document.getElementById('mdi-current-pid');
            if (pidEl) {
                var urlPid = new URLSearchParams(window.location.search).get('set_pid') || new URLSearchParams(window.location.search).get('pid');
                if (urlPid) pidEl.value = urlPid;
            }
        });

        document.addEventListener("DOMContentLoaded", () => {
            cardTitleButtonClickListener();
        });
    </script>

    <style>
        /* ══════════════════════════════════════════════════════════════════
           MDITek Patient Dashboard — Redesigned 2026
        ══════════════════════════════════════════════════════════════════ */
        @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap');

        :root {
            --white:        #ffffff;
            --bg:           #f0f3f7;
            --surface:      #ffffff;
            --border:       #e2e8f0;
            --border-mid:   #c8d5e5;
            --text:         #1a2540;
            --text-mid:     #4a5a72;
            --text-muted:   #8a9bb5;
            --accent:       #4f46e5;
            --accent-g:     linear-gradient(135deg,#4f46e5,#7c3aed);
            --accent-light: #eef2ff;
            --accent-hover: #4338ca;
            --success:      #16a34a;
            --success-bg:   #dcfce7;
            --warning:      #d97706;
            --warning-bg:   #fef3c7;
            --danger:       #dc2626;
            --danger-bg:    #fee2e2;
            --radius:       10px;
            --radius-sm:    6px;
            --shadow:       0 1px 3px rgba(30,58,95,.08), 0 2px 10px rgba(30,58,95,.06);
            --shadow-md:    0 4px 16px rgba(30,58,95,.10);
        }

        html, body {
            font-family: 'DM Sans', 'Montserrat', sans-serif !important;
            background: var(--bg) !important;
            color: var(--text) !important;
            font-size: 13px !important;
            width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box;
        }

        .container, .container-fluid,
        #patientDemographics, .tab-content, .tab-pane,
        .section, .demographics {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }
        .container { max-width: 100%; }

        /* ── Page wrapper ── */
        .mdi-pg {
            padding: 0 0 80px;
            width: 100%;
            box-sizing: border-box;
        }

        /* ══ PATIENT HEADER BANNER ══════════════════════════════════════ */
        /* ══ PATIENT BANNER ═══════════════════════════════════════════ */
        .mdi-patient-banner {
            display: none;
            align-items: center;
            gap: 0;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            padding: 16px 20px;
            margin-bottom: 16px;
            min-height: 90px;
        }
        .mdi-banner-avatar {
            width: 60px; height: 60px; border-radius: 50%;
            background: #e0e7ff; display: flex; align-items: center; justify-content: center;
            font-size: 22px; color: var(--accent); font-weight: 700;
            margin-right: 18px; flex-shrink: 0;
            border: 2px solid var(--accent-light);
        }
        .mdi-banner-identity { flex: 0 0 auto; margin-right: 0; min-width: 0; }
        .mdi-banner-name { font-size: 20px; font-weight: 700; color: var(--text); margin-bottom: 3px; }
        .mdi-banner-sub  { font-size: 11.5px; color: var(--text-muted); display: flex; flex-wrap: wrap; gap: 6px; }
        .mdi-banner-sub span { display: flex; align-items: center; gap: 3px; }
        /* Stats section — fills remaining space between identity and actions */
        .mdi-banner-stats {
            display: flex; align-items: stretch; flex: 1;
            margin: 0 16px;
            overflow: hidden;
        }
        .mdi-banner-stat {
            padding: 0 20px;
            border-left: 1px solid var(--border);
            flex: 1; min-width: 0;
        }
        .mdi-banner-stat:first-child { border-left: 1px solid var(--border); }
        .mdi-banner-stat-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: var(--text-muted); margin-bottom: 4px;
        }
        .mdi-banner-stat-val {
            font-size: 14px; font-weight: 700; color: var(--text); line-height: 1.2;
        }
        .mdi-banner-stat-val.balance     { color: var(--danger);  font-size: 20px; }
        .mdi-banner-stat-val.balance-zero{ color: var(--success); font-size: 20px; }
        .mdi-banner-stat-sub { font-size: 10.5px; color: var(--text-muted); margin-top: 2px; }
        /* Actions — right side column */
        .mdi-banner-actions {
            display: flex; flex-direction: column; gap: 7px;
            flex-shrink: 0; min-width: 220px;
            border-left: 1px solid var(--border);
            padding-left: 20px;
        }
        .mdi-banner-actions .btn-new-enc {
            background: var(--accent-g); color: #fff; border: none;
            border-radius: var(--radius-sm); padding: 9px 14px; font-size: 13px;
            font-weight: 700; cursor: pointer; text-decoration: none;
            display: flex; align-items: center; justify-content: center; gap: 6px;
        }
        .mdi-banner-actions .btn-new-enc:hover { opacity: .9; color: #fff; text-decoration: none; }
        .mdi-banner-action-row { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
        .mdi-banner-action-row a {
            border: 1px solid var(--border); border-radius: var(--radius-sm);
            padding: 5px 8px; font-size: 11px; font-weight: 600; color: var(--text);
            text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 4px;
            background: #fff; white-space: nowrap;
        }
        .mdi-banner-action-row a:hover { border-color: var(--accent); color: var(--accent); text-decoration: none; }

        /* ══ DASHBOARD GRID ═════════════════════════════════════════════ */
        .mdi-pg-grid {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 0 16px;
            box-sizing: border-box;
        }

        /* Wrappers that used display:contents are now direct flex children handled as rows */
        .mdi-pg-left-billing  { display: contents; }
        .mdi-pg-mid-cards     { display: contents; }
        .mdi-pg-right         { display: contents; }
        .mdi-pg-right-cards   { display: none !important; }
        .mdi-pg-mid           { display: none !important; }

        /* Each row is a flex row with equal-height cards */
        /* Column that stacks Demographics + Insurance to match Quick Charges height */
        .mdi-demo-ins-col {
            display: flex;
            flex-direction: column;
            gap: 14px;
            min-width: 0;
        }
        .mdi-demo-ins-col .mdi-demo-card,
        .mdi-demo-ins-col .mdi-ins-card {
            flex: 1;
            margin-bottom: 0 !important;
        }
        .mdi-row {
            display: grid;
            gap: 14px;
            align-items: stretch;   /* equal height cards */
        }
        .mdi-row-3   { grid-template-columns: 1fr 1.6fr 1fr; }
        .mdi-row-3b  { grid-template-columns: 1fr 1.4fr 1.4fr; }
        .mdi-row-5   { grid-template-columns: repeat(5, 1fr); }
        .mdi-row-4   { grid-template-columns: repeat(4, 1fr); }

        /* ══ CARD BASE ══════════════════════════════════════════════════ */
        .card {
            border: 1px solid var(--border) !important;
            border-radius: var(--radius) !important;
            box-shadow: var(--shadow) !important;
            background: var(--surface) !important;
            margin-bottom: 0 !important;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .card-header {
            background: var(--white) !important;
            border-bottom: 1px solid var(--border) !important;
            padding: 10px 14px !important;
            font-weight: 700 !important;
            font-size: 11px !important;
            letter-spacing: .06em !important;
            text-transform: uppercase !important;
            color: var(--text) !important;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        .card-header .card-header-icon {
            width: 28px; height: 28px; border-radius: 7px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 14px; margin-right: 8px; flex-shrink: 0;
        }
        .card-header-left { display: flex; align-items: center; }
        .card-body {
            padding: 12px 14px !important;
            font-size: 12.5px !important;
            flex: 1;
        }

        /* ── Small header buttons ── */
        .btn {
            font-family: 'DM Sans', sans-serif !important;
            font-size: 11px !important;
            font-weight: 600 !important;
            border-radius: var(--radius-sm) !important;
            transition: all .15s !important;
        }
        .btn-primary   { background: var(--accent) !important; border-color: var(--accent) !important; }
        .btn-primary:hover { background: var(--accent-hover) !important; }
        .btn-success   { background: var(--success) !important; border-color: var(--success) !important; }
        .btn-outline-primary { color: var(--accent) !important; border-color: var(--accent) !important; background: transparent !important; }
        .btn-outline-primary:hover { background: var(--accent) !important; color: #fff !important; }
        .btn-sm  { padding: 4px 10px !important; font-size: 11px !important; }
        .btn-xs  { padding: 2px 7px !important; font-size: 10px !important; border-radius: 4px !important; }
        .btn-add-circle {
            width: 26px; height: 26px; border-radius: 50%;
            background: var(--success); color: #fff; border: none;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 16px; cursor: pointer; line-height: 1; text-decoration: none;
        }
        .btn-add-circle:hover { background: #15803d; color: #fff; text-decoration: none; }

        /* ── Nav tabs ── */
        .nav-tabs .nav-link {
            font-size: 12px !important; font-weight: 500 !important;
            color: var(--text-mid) !important; border: none !important;
            border-bottom: 2px solid transparent !important;
            padding: 6px 12px !important; border-radius: 0 !important;
        }
        .nav-tabs .nav-link.active {
            color: var(--accent) !important;
            border-bottom-color: var(--accent) !important;
            background: transparent !important;
        }

        /* ── Tables ── */
        .table { font-size: 12px !important; }
        .table thead th {
            font-weight: 700 !important; font-size: 10.5px !important;
            text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-muted) !important;
            border-bottom: 1px solid var(--border) !important;
            background: #f7f9fc !important; padding: 7px 10px !important;
        }
        .table td { padding: 7px 10px !important; vertical-align: middle !important; border-color: var(--border) !important; }
        .table-hover tbody tr:hover { background: var(--accent-light) !important; }

        section { background: var(--white); margin-top: .2em; padding: .2em; }
        .section-header-dynamic { border-bottom: none; }

        /* ══ BILLING CARD ═══════════════════════════════════════════════ */
        .mdi-billing-card {
            background: #fff;
            border: 1px solid var(--border) !important;
            border-radius: var(--radius) !important;
            overflow: hidden;
            box-shadow: var(--shadow) !important;
            display: flex; flex-direction: column;
        }
        .mdi-billing-hdr {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 14px 8px;
            border-bottom: 1px solid var(--border);
            font-size: 11px; font-weight: 700;
            letter-spacing: .06em; color: var(--text);
            text-transform: uppercase;
        }
        .mdi-billing-hdr-left { display: flex; align-items: center; gap: 8px; }
        .mdi-billing-hdr-icon {
            width: 28px; height: 28px; border-radius: 7px;
            background: #fffbeb; display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: #d97706;
        }
        .mdi-billing-ledger-btn {
            font-size: 11px; font-weight: 600; color: var(--accent);
            text-decoration: none; padding: 3px 8px; border-radius: 4px;
            border: 1px solid var(--border);
        }
        .mdi-billing-ledger-btn:hover { background: var(--accent); color: #fff; text-decoration: none; border-color: var(--accent); }
        .mdi-billing-menu-btn {
            background: none; border: none; cursor: pointer;
            font-size: 16px; color: #888; padding: 0 4px; line-height: 1;
        }
        .mdi-today-checkout {
            padding: 14px 14px 10px;
            border-bottom: 1px solid var(--border);
        }
        .mdi-today-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .07em; color: var(--text-muted); margin-bottom: 8px;
            display: flex; align-items: center; gap: 5px;
        }
        .mdi-today-amounts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px; }
        .mdi-today-col { }
        .mdi-today-sublabel { font-size: 10.5px; color: var(--text-muted); margin-bottom: 2px; }
        .mdi-today-amount { font-size: 26px; font-weight: 700; letter-spacing: -1px; line-height: 1.1; }
        .mdi-today-subdesc { font-size: 10px; color: var(--text-muted); margin-top: 2px; }
        .mdi-copay-amt  { color: var(--text); }
        .mdi-bal-red    { color: var(--danger); }
        .mdi-bal-green  { color: var(--success); }
        .mdi-bal-zero   { color: var(--text-muted); }
        /* Primary action buttons */
        .mdi-checkout-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 10px 14px; }
        .mdi-checkout-main-btn {
            display: flex; align-items: center; justify-content: center; gap: 7px;
            padding: 10px 12px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 700;
            cursor: pointer; border: none; text-decoration: none; color: #fff;
        }
        .mdi-checkout-main-btn:hover { opacity: .88; color: #fff; text-decoration: none; }
        .mdi-checkout-btn {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            padding: 8px 10px; border-radius: var(--radius-sm); font-size: 12px; font-weight: 700;
            cursor: pointer; border: none; text-decoration: none;
        }
        .mdi-checkout-btn.checkout-btn {
            background: var(--accent-g); color: #fff;
        }
        .mdi-checkout-btn.payments-btn {
            background: var(--success); color: #fff;
        }
        .mdi-checkout-btn:hover { opacity: .88; color: #fff; text-decoration: none; }
        .mdi-receipt-row-2 {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px; padding: 0 0 10px;
        }
        .mdi-receipt-row {
            display: grid; grid-template-columns: repeat(3,1fr); gap: 6px; padding: 0 0 10px;
        }
        .mdi-receipt-btn {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: 2px; padding: 6px 4px; border-radius: 6px; font-size: 10px; font-weight: 600;
            color: var(--text-mid); background: #f7f9fc; border: 1px solid var(--border);
            cursor: pointer; text-decoration: none;
        }
        .mdi-receipt-btn i { font-size: 13px; color: var(--text-muted); }
        .mdi-receipt-btn:hover { background: var(--accent-light); color: var(--accent); border-color: var(--accent); text-decoration: none; }
        .mdi-receipt-btn:hover i { color: var(--accent); }
        .mdi-receipt-info {
            display: flex; align-items: center; gap: 5px;
            background: var(--success-bg); border-radius: 6px;
            padding: 6px 10px; margin: 0 14px 10px; font-size: 11px; color: var(--success);
            font-weight: 500;
        }
        .mdi-acct-section { border-top: 1px solid var(--border); }
        .mdi-section-hdr {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 14px 6px; font-size: 10.5px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted);
            cursor: pointer;
        }
        .mdi-acct-sub { padding: 0 14px 10px; }
        .mdi-acct-row {
            display: flex; justify-content: space-between; align-items: baseline;
            padding: 4px 0; font-size: 12px;
        }
        .mdi-acct-row-label { color: var(--text-mid); }
        .mdi-acct-row-desc  { font-size: 10.5px; color: var(--text-muted); }
        .mdi-acct-total { border-top: 1px solid var(--border); margin-top: 4px; padding-top: 6px; font-weight: 700; }
        /* Account summary table */
        .mdi-acct-table {
            width: 100%; border-collapse: collapse; font-size: 12px;
        }
        .mdi-acct-table td {
            padding: 5px 14px; vertical-align: top; border-bottom: 1px solid var(--border);
        }
        .mdi-acct-table tr:last-child td { border-bottom: none; }
        .mdi-acct-table td:first-child { color: var(--text-mid); width: 60%; }
        .mdi-acct-table td.mdi-acct-val {
            text-align: right; font-weight: 600; white-space: nowrap;
        }
        .mdi-acct-table .mdi-acct-sub {
            font-size: 10.5px; color: var(--text-muted); padding: 0; display: block;
        }
        .mdi-acct-table tr.mdi-acct-total td {
            font-weight: 700; border-top: 2px solid var(--border); border-bottom: none;
            padding-top: 8px;
        }
        .mdi-billing-note {
            font-size: 10.5px; font-style: italic; color: var(--text-muted);
            background: var(--warning-bg); border-top: 1px solid var(--border);
            padding: 6px 14px;
        }

        /* ══ ENCOUNTER PANEL ════════════════════════════════════════════ */
        #mdi-encounter-panel {
            display: flex; flex-direction: column;
            overflow: hidden;
            align-self: start;
        }
        /* Stop card-body stretching to fill column height in encounter panel */
        #mdi-encounter-panel .card-body,
        #mdi-encounter-panel .mdi-enc-body { flex: 0 0 auto !important; }
        #mdi-encounter-panel .card-header {
            background: linear-gradient(135deg,#4f46e5,#7c3aed) !important;
            color: #fff !important; border-bottom: none !important;
        }
        #mdi-encounter-panel .card-header .btn-success {
            background: rgba(255,255,255,.2) !important;
            border-color: rgba(255,255,255,.4) !important;
            color: #fff !important;
        }
        #mdi-encounter-panel .card-header .btn-success:hover {
            background: rgba(255,255,255,.35) !important;
        }
        .mdi-enc-filter {
            display: flex; align-items: center; gap: 6px;
            padding: 8px 12px; background: #f7f9fc;
            border-bottom: 1px solid var(--border);
            font-size: 11px; flex-wrap: wrap; flex-shrink: 0;
        }
        .mdi-enc-filter label { font-weight: 600; color: var(--text-muted); margin: 0; }
        .mdi-enc-filter .form-control {
            width: 110px !important; height: 28px !important; font-size: 11px !important;
            padding: 2px 6px !important; border-radius: 4px !important;
        }
        .mdi-enc-filter-btn {
            background: var(--accent); color: #fff; border: none; border-radius: 4px;
            padding: 3px 8px; font-size: 11px; cursor: pointer;
        }
        .mdi-enc-clear-btn {
            background: none; border: 1px solid var(--border); border-radius: 4px;
            padding: 3px 7px; font-size: 11px; cursor: pointer; color: var(--text-muted);
        }
        .mdi-enc-body {
            flex: 1; overflow-y: auto; max-height: 220px;
        }
        #mdi-enc-table { width: 100%; border-collapse: collapse; }
        #mdi-enc-table thead th {
            position: sticky; top: 0; z-index: 1;
        }
        .mdi-enc-row td { font-size: 12px !important; padding: 7px 10px !important; }
        .mdi-enc-date { font-weight: 600; color: var(--text); white-space: nowrap; }
        .mdi-enc-date-wrap {
            display: inline-flex; align-items: center; gap: 5px; white-space: nowrap;
        }
        .mdi-enc-date-input {
            font-size: 11.5px; font-weight: 600; color: var(--text);
            border: none; background: transparent;
            padding: 0; width: 92px;
            outline: none; cursor: pointer; font-family: inherit;
        }
        /* Hide the browser's default calendar icon; we show our own */
        .mdi-enc-date-input::-webkit-calendar-picker-indicator {
            display: none;
        }
        .mdi-enc-date-icon {
            color: #f97316; font-size: 11px; cursor: pointer; flex-shrink: 0;
            opacity: .85; transition: opacity .12s;
        }
        .mdi-enc-date-icon:hover { opacity: 1; }
        .mdi-enc-date-saving { opacity: .5; pointer-events: none; }
        .mdi-enc-date-input:focus { color: var(--accent, #6c5ce7); }
        .mdi-enc-reason { color: var(--accent); font-weight: 500; }
        .mdi-enc-proc-badge {
            display: inline-flex; align-items: center; gap: 3px;
            font-size: 10px; font-weight: 600;
            color: var(--accent); background: var(--accent-light);
            border: 1px solid #c7d2fe; border-radius: 4px;
            padding: 1px 5px; white-space: nowrap; margin-top: 2px;
        }
        .mdi-enc-actions { display: flex; gap: 4px; }
        .mdi-enc-empty {
            text-align: center; padding: 24px; color: var(--text-muted); font-style: italic; font-size: 12px;
        }

        /* ══ DEMOGRAPHICS CARD ══════════════════════════════════════════ */
        .mdi-demo-card .card-header-icon { background: #eff6ff; color: #2563eb; }
        .mdi-demo-card .mdi-demo-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .mdi-demo-card .mdi-demo-row {
            display: flex; flex-direction: column; gap: 2px; padding: 7px 10px 7px 0;
            border-bottom: 1px solid var(--border);
        }
        .mdi-demo-card .mdi-demo-row.full-width {
            grid-column: 1 / -1;
        }
        .mdi-demo-card .mdi-demo-row:last-child,
        .mdi-demo-card .mdi-demo-row.no-border { border-bottom: none; }
        .mdi-demo-card .mdi-demo-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; color: var(--text-muted);
        }
        .mdi-demo-card .mdi-demo-value { color: var(--text); font-weight: 500; font-size: 12.5px; line-height: 1.4; }
        .mdi-demo-card .card-header { }

        /* ══ DIAGNOSIS CARD ═════════════════════════════════════════════ */
        .mdi-diag-left-card .card-header-icon { background: #f0fdf4; color: #16a34a; }
        .mdi-diag-left-card .mdi-diag-table {
            width: 100%; border-collapse: collapse;
        }
        .mdi-diag-left-card .mdi-diag-table thead th {
            font-size: 10px !important; font-weight: 700 !important;
            text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-muted) !important;
            background: #f7f9fc !important; padding: 6px 10px !important;
            border-bottom: 1px solid var(--border) !important;
        }
        .mdi-diag-left-card .mdi-diag-table td {
            padding: 7px 10px !important; font-size: 12px !important;
            border-bottom: 1px solid var(--border); vertical-align: middle !important;
        }
        .mdi-diag-code-pill {
            display: inline-block; padding: 2px 7px; border-radius: 4px;
            background: #eff6ff; border: 1px solid #bfdbfe;
            color: #1e40af; font-size: 11px; font-weight: 700; white-space: nowrap;
        }
        .mdi-diag-status-active {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            background: var(--success-bg); color: var(--success);
            font-size: 10.5px; font-weight: 600;
        }
        .mdi-diag-add-row {
            padding: 8px 12px; border-top: 1px solid var(--border);
        }
        .mdi-diag-add-btn {
            display: flex; align-items: center; gap: 5px;
            color: var(--accent); font-size: 12px; font-weight: 600;
            cursor: pointer; background: none; border: none; padding: 0;
        }
        .mdi-diag-add-btn:hover { color: var(--accent-hover); text-decoration: none; }

        /* ══ APPOINTMENTS CARD ══════════════════════════════════════════ */
        .mdi-appt-card .card-header-icon { background: #f0fdf4; color: var(--success); }
        .mdi-appt-card .card-body { padding: 0 !important; }
        .mdi-appt-table { width: 100%; border-collapse: collapse; }
        .mdi-appt-table thead th {
            font-size: 10px !important; font-weight: 700 !important;
            text-transform: uppercase; letter-spacing: .5px;
            color: var(--text-muted) !important;
            background: #f7f9fc !important; padding: 7px 10px !important;
            border-bottom: 1px solid var(--border) !important;
        }
        .mdi-appt-table td { padding: 8px 10px !important; font-size: 12px !important; border-bottom: 1px solid var(--border) !important; vertical-align: middle !important; }
        .mdi-appt-table tbody tr:last-child td { border-bottom: none !important; }
        .mdi-appt-badge {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: 10.5px; font-weight: 600;
        }
        .mdi-appt-badge.scheduled { background: var(--accent-light); color: var(--accent); }
        .mdi-appt-badge.confirmed { background: var(--success-bg); color: var(--success); }
        .mdi-appt-badge.cancelled { background: var(--danger-bg); color: var(--danger); }
        .mdi-appt-empty { text-align: center; padding: 28px 16px; color: var(--text-muted); font-style: italic; font-size: 12px; }
        .mdi-appt-empty i { font-size: 28px; display: block; margin-bottom: 8px; color: var(--border); }
        .mdi-appt-footer {
            padding: 10px 12px; border-top: 1px solid var(--border);
        }

        /* ══ INSURANCE CARD ═════════════════════════════════════════════ */
        .mdi-ins-card .card-header-icon { background: #f0f9ff; color: #0369a1; }
        .mdi-ins-card .ins-row {
            display: grid; grid-template-columns: 28px 1fr auto;
            gap: 8px; align-items: center;
            padding: 8px 0; border-bottom: 1px solid var(--border);
        }
        .mdi-ins-card .ins-row:last-child { border-bottom: none; }
        .mdi-ins-card .ins-type-badge {
            width: 24px; height: 24px; border-radius: 50%; border: 2px solid;
            display: flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 800;
        }
        .mdi-ins-card .ins-type-badge.primary   { background: var(--accent-light); color: var(--accent); border-color: var(--accent); }
        .mdi-ins-card .ins-type-badge.secondary { background: #e0f2fe; color: #0369a1; border-color: #0369a1; }
        .mdi-ins-card .ins-type-badge.tertiary  { background: var(--warning-bg); color: var(--warning); border-color: var(--warning); }
        .ins-legend {
            display: flex; flex-direction: row; flex-wrap: wrap;
            align-items: center; gap: 12px;
            margin-top: 8px; padding-top: 8px;
            border-top: 1px solid var(--border);
            font-size: 11.5px; color: var(--text-muted);
        }
        .ins-legend > span {
            display: inline-flex; align-items: center; gap: 5px;
            white-space: nowrap;
        }
        .mdi-ins-card .ins-name { font-weight: 600; font-size: 12.5px; }
        .mdi-ins-card .ins-policy { color: var(--text-muted); font-size: 11px; }
        .mdi-ins-card .ins-status {
            display: inline-block; padding: 2px 8px; border-radius: 20px;
            font-size: 10.5px; font-weight: 600;
            background: var(--success-bg); color: var(--success);
        }
        .mdi-ins-card .ins-status.inactive {
            background: var(--danger-bg); color: var(--danger);
        }
        .mdi-ins-card .ins-resp {
            display: flex; justify-content: space-between;
            padding: 8px 0 4px; font-size: 12px;
            border-top: 1px solid var(--border); margin-top: 4px;
        }
        .mdi-ins-card .ins-resp-label { color: var(--text-muted); font-weight: 500; }
        .mdi-ins-card .ins-resp-val { font-weight: 700; color: var(--text); }

        /* ══ INTAKE / VITALS / ALLERGY / MED / REC CARE CARDS ══════════ */
        .mdi-intake-card .card-header-icon  { background: #eff6ff; color: #2563eb; }
        .mdi-vitals-card .card-header-icon  { background: #f0fdf4; color: var(--success); }
        .mdi-auth-card .card-header-icon    { background: #eff6ff; color: #2563eb; }
        .mdi-allergy-card .card-header-icon { background: var(--danger-bg); color: var(--danger); }
        .mdi-med-card .card-header-icon     { background: #f0f9ff; color: #0369a1; }
        .mdi-rec-care-card .card-header-icon { background: #f0fdf4; color: var(--success); }

        .mdi-vitals-row {
            display: flex; justify-content: space-between;
            padding: 5px 0; border-bottom: 1px solid var(--border); font-size: 12px;
        }
        .mdi-vitals-row:last-child { border-bottom: none; }
        .mdi-vitals-label { color: var(--text-muted); font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
        .mdi-vitals-val   { font-weight: 600; color: var(--text); }

        /* ══ COLLAPSIBLE BOTTOM SECTION — sticky to bottom, purple ══════ */
        .mdi-additional-section {
            position: sticky;
            bottom: 0;
            z-index: 200;
            border: none;
            border-top: 2px solid #7c3aed;
            border-radius: 0;
            background: #6c5ce7;
            box-shadow: 0 -4px 18px rgba(108,92,231,.25);
            overflow: hidden;
            margin: 0 !important;
        }
        .mdi-additional-toggle {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 16px;
            cursor: pointer; user-select: none;
            font-weight: 700; font-size: 12px; color: #fff;
            text-transform: uppercase; letter-spacing: .08em;
            background: transparent;
        }
        .mdi-additional-toggle:hover { background: rgba(255,255,255,.08); }
        .mdi-additional-toggle .mdi-toggle-icon {
            transition: transform .2s; color: rgba(255,255,255,.8);
        }
        .mdi-additional-toggle.open .mdi-toggle-icon { transform: rotate(180deg); }
        .mdi-additional-body {
            display: none; padding: 30px 16px;
            grid-template-columns: repeat(4, 1fr); gap: 10px;
            border-top: 1px solid rgba(255,255,255,.2);
            background: #5b4fcf;
        }
        .mdi-additional-body.open { display: grid; }
        .mdi-additional-item {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 12px; border-radius: var(--radius-sm);
            border: 1px solid rgba(255,255,255,.25); background: rgba(255,255,255,.1);
            cursor: pointer; text-decoration: none; color: #fff;
            font-size: 12px; font-weight: 600;
        }
        .mdi-additional-item:hover { background: rgba(255,255,255,.22); border-color: rgba(255,255,255,.5); color: #fff; text-decoration: none; }
        .mdi-additional-item i { font-size: 15px; color: rgba(255,255,255,.75); }
        .mdi-additional-item:hover i { color: #fff; }

        /* ══ MISC ═══════════════════════════════════════════════════════ */
        .mdi-recommended-care-card .recall-item {
            display: flex; justify-content: space-between; align-items: center;
            padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 12px;
        }
        .mdi-recommended-care-card .recall-item:last-child { border-bottom: none; }
        .mdi-recommended-care-card .recall-name { font-weight: 500; display: flex; align-items: center; gap: 6px; }
        .mdi-recommended-care-card .recall-due  { font-size: 11px; color: var(--text-muted); white-space: nowrap; }
        span.badge.badge-light.ml-1 { color: white !important; }
        .navbar-brand { padding-top: 0 !important; padding-bottom: 0 !important; }
        .card-body.p-1 strong { color: #333; }
        .mdi-enc-no-results { text-align:center; padding:16px; color:var(--text-muted); font-style:italic; font-size:12px; display:none; }

        /* ── Responsive ── */
        @media (max-width: 1200px) {
            .mdi-row-3, .mdi-row-3b { grid-template-columns: 1fr 1fr; }
            .mdi-row-5 { grid-template-columns: repeat(3, 1fr); }
            .mdi-additional-body { grid-template-columns: repeat(2,1fr); }
        }
        @media (max-width: 768px) {
            .mdi-row-3, .mdi-row-3b, .mdi-row-4, .mdi-row-5 { grid-template-columns: 1fr; }
            .mdi-patient-banner { flex-wrap: wrap; }
        .mdi-banner-stats { display: none; }
        .mdi-banner-actions { border-left: none; padding-left: 0; min-width: 0; }
            .mdi-banner-stat { display: none; }
            .mdi-additional-body { grid-template-columns: 1fr 1fr; }
        }
    </style>
        <script>
        // Hide Transactions tab and style active tab
        document.addEventListener('DOMContentLoaded', function() {
            // Remove Transactions menu item
            document.querySelectorAll('ul.nav li a, .navbar ul.nav li a').forEach(function(a) {
                var txt = a.textContent.trim().toLowerCase();
                if (txt === 'transactions' || txt.indexOf('transaction') === 0) {
                    var li = a.closest('li');
                    if (li) li.style.display = 'none';
                }
            });
        });
        </script>
        <?php
        // Get the document ID of the patient ID card if access to it is wanted here.
        $idcard_doc_id = false;
        if ($GLOBALS['patient_id_category_name']) {
            $idcard_doc_id = get_document_by_catg($pid, $GLOBALS['patient_id_category_name'], 3);
        }
        ?>
        <div class="mdi-pg">

            <!-- ══ PATIENT HEADER BANNER ══ -->
            <?php
            // Pre-compute patient balance for the banner.
            // Sums billing charges minus payments for this patient.
            $mdi_pre_total = 0.0;
            try {
                $_bal_row = sqlQuery(
                    "SELECT
                        COALESCE(SUM(CASE WHEN b.code_type = 'COPAY' OR b.code_type = 'TAX' THEN 0
                                         WHEN b.billed = 0 AND b.activity = 1 THEN b.fee
                                         WHEN b.billed = 1 AND b.activity = 1 THEN b.fee
                                         ELSE 0 END), 0)
                      - COALESCE((SELECT SUM(ar.pay_amount)
                                    FROM ar_activity ar
                                   WHERE ar.pid = ? AND ar.deleted IS NULL), 0) AS total
                     FROM billing b
                    WHERE b.pid = ? AND b.activity = 1",
                    [$pid, $pid]
                );
                $mdi_pre_total = (float)($_bal_row['total'] ?? 0);
            } catch (Exception $_e) { $mdi_pre_total = 0.0; }
            if (!isset($totalbalance))     { $totalbalance     = $mdi_pre_total; }
            if (!isset($patientbalance))   { $patientbalance   = $mdi_pre_total; }
            if (!isset($insurancebalance)) { $insurancebalance = 0; }
            $mdi_banner_name = trim(($result['fname'] ?? '') . ' ' . ($result['lname'] ?? ''));
            $mdi_banner_initials = strtoupper(substr($result['fname'] ?? '', 0, 1) . substr($result['lname'] ?? '', 0, 1));
            $mdi_banner_dob  = !empty($result['DOB_YMD']) ? oeFormatShortDate($result['DOB_YMD']) : '';
            $mdi_banner_age  = !empty($result['DOB_YMD']) ? getPatientAgeDisplay($result['DOB_YMD']) : '';
            $mdi_banner_sex  = !empty($result['sex']) ? xl($result['sex']) : '';
            $mdi_banner_phone = !empty($result['phone_home']) ? $result['phone_home'] : ($result['phone_cell'] ?? '');
            // Primary insurance
            $mdi_banner_ins = sqlQuery(
                "SELECT ic.name, ins.group_number FROM insurance_data ins
                  LEFT JOIN insurance_companies ic ON ic.id = ins.provider
                 WHERE ins.pid = ? AND ins.type = 'primary'
                   AND (ins.date IS NULL OR ins.date <= CURDATE())
                 ORDER BY ins.date DESC LIMIT 1", [$pid]
            );
            // Last visit
            $mdi_banner_last = sqlQuery(
                "SELECT fe.date, CONCAT(u.fname,' ',u.lname) AS prov
                   FROM form_encounter fe
                   LEFT JOIN users u ON u.id = fe.provider_id
                  WHERE fe.pid = ? ORDER BY fe.date DESC LIMIT 1", [$pid]
            );
            // Next appointment — use fetchNextXAppts() (same function used by the appointments card)
            $mdi_banner_next = null;
            if (!$GLOBALS['disable_calendar'] && AclMain::aclCheckCore('patients', 'appt')) {
                $_bn_evts = fetchNextXAppts(date('Y-m-d'), $pid, 1, true);
                if (!empty($_bn_evts)) {
                    $_bn      = $_bn_evts[0];
                    $_bn_hour = (int)substr($_bn['pc_startTime'], 0, 2);
                    $_bn_min  = substr($_bn['pc_startTime'], 3, 2);
                    $_bn_mer  = '';
                    if ($GLOBALS['time_display_format'] == 1) {
                        $_bn_mer = $_bn_hour >= 12 ? ' PM' : ' AM';
                        if ($_bn_hour > 12) $_bn_hour -= 12;
                        if ($_bn_hour == 0) $_bn_hour  = 12;
                    }
                    $mdi_banner_next = [
                        'pc_eventDate' => $_bn['pc_eventDate'],
                        'pc_startTime' => sprintf('%02d', $_bn_hour) . ':' . $_bn_min . $_bn_mer,
                        'prov'         => trim(($_bn['ufname'] ?? '') . ' ' . ($_bn['ulname'] ?? '')),
                    ];
                }
            }
            $mdi_bal_class = ($totalbalance > 0) ? 'balance' : (($totalbalance < 0) ? 'balance' : 'balance-zero');
            ?>
            <div class="mdi-patient-banner">
                <!-- Avatar -->
                <div class="mdi-banner-avatar"><?php echo text($mdi_banner_initials ?: '?'); ?></div>
                <!-- Name + sub -->
                <div class="mdi-banner-identity" style="margin-right:20px;">
                    <div class="mdi-banner-name"><?php echo text(strtoupper($mdi_banner_name)); ?>
                        <span style="font-size:14px;font-weight:400;color:var(--text-muted);margin-left:6px;">(<?php echo text($pid); ?>)</span>
                    </div>
                    <div class="mdi-banner-sub">
                        <span><?php echo xlt('DOB'); ?>: <?php echo text($mdi_banner_dob); ?></span>
                        <span>&bull; <?php echo xlt('Age'); ?> <?php echo text($mdi_banner_age); ?></span>
                        <?php if ($mdi_banner_sex) : ?><span>&bull; <?php echo text($mdi_banner_sex); ?></span><?php endif; ?>
                        <?php if ($mdi_banner_phone) : ?><span>&bull; <?php echo text($mdi_banner_phone); ?></span><?php endif; ?>
                    </div>
                </div>
                <!-- Stats group -->
                <div class="mdi-banner-stats">
                    <!-- Primary Insurance -->
                    <div class="mdi-banner-stat">
                        <div class="mdi-banner-stat-label"><?php echo xlt('Primary Insurance'); ?></div>
                        <div class="mdi-banner-stat-val"><?php echo text($mdi_banner_ins['name'] ?? xl('None')); ?></div>
                        <?php if (!empty($mdi_banner_ins['group_number'])) : ?>
                        <div class="mdi-banner-stat-sub"><?php echo xlt('Policy #'); ?> <?php echo text($mdi_banner_ins['group_number']); ?></div>
                        <?php endif; ?>
                    </div>
                    <!-- Patient Balance -->
                    <div class="mdi-banner-stat">
                        <div class="mdi-banner-stat-label"><?php echo xlt('Patient Balance'); ?></div>
                        <div class="mdi-banner-stat-val <?php echo $totalbalance != 0 ? 'balance' : 'balance-zero'; ?>">
                            $<?php echo text(number_format(abs($totalbalance), 2)); ?>
                        </div>
                        <div class="mdi-banner-stat-sub"><?php echo xlt('As of'); ?> <?php echo text(date('m/d/Y')); ?></div>
                    </div>
                    <!-- Last Visit -->
                    <div class="mdi-banner-stat">
                        <div class="mdi-banner-stat-label"><?php echo xlt('Last Visit'); ?></div>
                        <div class="mdi-banner-stat-val"><?php echo !empty($mdi_banner_last['date']) ? text(oeFormatShortDate(substr($mdi_banner_last['date'],0,10))) : '—'; ?></div>
                        <?php if (!empty($mdi_banner_last['prov'])) : ?>
                        <div class="mdi-banner-stat-sub"><?php echo text($mdi_banner_last['prov']); ?></div>
                        <?php endif; ?>
                    </div>
                    <!-- Next Appointment -->
                    <div class="mdi-banner-stat">
                        <div class="mdi-banner-stat-label"><?php echo xlt('Next Appointment'); ?></div>
                        <?php if (!empty($mdi_banner_next)) : ?>
                        <div class="mdi-banner-stat-val"><?php echo text(oeFormatShortDate($mdi_banner_next['pc_eventDate'])); ?></div>
                        <div class="mdi-banner-stat-sub"><?php echo text($mdi_banner_next['pc_startTime']); ?><?php if (!empty($mdi_banner_next['prov'])) echo ' &bull; ' . text($mdi_banner_next['prov']); ?></div>
                        <?php else : ?>
                        <div class="mdi-banner-stat-val" style="color:var(--text-muted);">—</div>
                        <?php endif; ?>
                    </div>
                </div>
                <!-- Action buttons -->
                <div class="mdi-banner-actions">
                    <a href="#" onclick="top.restoreSession(); loadFrame('enc2','enc','patient_file/encounter/encounter_top.php?new=1&pid=<?php echo attr_url($pid); ?>'); return false;"
                       class="btn-new-enc"><i class="fas fa-plus mr-1"></i><?php echo xlt('New Encounter'); ?></a>
                    <div class="mdi-banner-action-row">
                        <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/ledger.php?set_pid=<?php echo attr_url($pid); ?>" onclick="top.restoreSession();">
                            <i class="fas fa-book"></i> <?php echo xlt('Patient Ledger'); ?>
                        </a>
                        <a href="#" onclick="top.restoreSession(); openQuickChargeModal(<?php echo attr_js($pid); ?>); return false;">
                            <i class="fas fa-bolt" style="color:var(--warning);"></i> <?php echo xlt('Quick Charge'); ?>
                        </a>
                    </div>
                    <div class="mdi-banner-action-row">
                        <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/front_payment.php?set_pid=<?php echo attr_url($pid); ?>" onclick="top.restoreSession();">
                            <i class="fas fa-dollar-sign"></i> <?php echo xlt('Payment'); ?>
                        </a>
                        <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/history/encounters_history.php?set_pid=<?php echo attr_url($pid); ?>" onclick="top.restoreSession();">
                            <i class="fas fa-calendar-check"></i> <?php echo xlt('View Encounters'); ?>
                        </a>
                    </div>
                </div>
            </div>
            <!-- /patient banner -->

            <!-- 3-col patient dashboard -->
            <div class="mdi-pg-grid">

                <!-- ══ ROW 1: Billing | Quick Charges | Insurance ══ -->
                <div class="mdi-row mdi-row-3">
                <!-- ══ COL LEFT: Billing Overview + Quick Buttons ══ -->
                <?php
                if ($deceased > 0) :
                    echo $twig->getTwig()->render('patient/partials/deceased.html.twig', [
                        'deceasedDays' => deceasedDays($deceased),
                    ]);
                endif;
                ?>
                    <!-- LEFT col: Billing Overview -->
                    <?php
                    $sectionRenderEvents = $ed->dispatch(new SectionEvent('primary'), SectionEvent::EVENT_HANDLE);
                    $sectionCards = $sectionRenderEvents->getCards();

                    $t = $twig->getTwig();

                    foreach ($sectionCards as $card) {
                        $_auth = $card->getAcl();
                        if ($card->getIdentifier() === 'billing') {
                            continue;
                        }
                        // Skip PortalCard — rendered directly in the right column
                        if ($_auth === ['patientportal', 'portal']) {
                            continue;
                        }
                        if (!AclMain::aclCheckCore($_auth[0], $_auth[1])) {
                            continue;
                        }

                        $btnLabel = false;
                        if ($card->canAdd()) {
                            $btnLabel = 'Add';
                        } elseif ($card->canEdit()) {
                            $btnLabel = 'Edit';
                        }

                        $viewArgs = [
                            'title' => $card->getTitle(),
                            'id' => $card->getIdentifier(),
                            'initiallyCollapsed' => !$card->isInitiallyCollapsed(),
                            'card_bg_color' => $card->getBackgroundColorClass(),
                            'card_text_color' => $card->getTextColorClass(),
                            'forceAlwaysOpen' => !$card->canCollapse(),
                            'btnLabel' => $btnLabel,
                            'btnLink' => 'test',
                        ];

                        echo $t->render($card->getTemplateFile(), array_merge($card->getTemplateVariables(), $viewArgs));
                    }

                    ?>
                    <div class="mdi-pg-left-billing">
                    <?php
                    if (!$GLOBALS['hide_billing_widget']) :
                        $forceBillingExpandAlways = ($GLOBALS['force_billing_widget_open']) ? true : false;

                        // ── MDItek billing card — mirrors pat_ledger.php balance logic exactly ──
                        $has_insurance = false;
                        try {
                            $_ins_chk = sqlQuery(
                                "SELECT id FROM insurance_data WHERE pid=? AND type='primary'
                                 AND (date IS NULL OR date <= CURDATE()) ORDER BY date DESC LIMIT 1",
                                [$pid]
                            );
                            $has_insurance = !empty($_ins_chk['id']);
                        } catch (Exception $e) { $has_insurance = false; }

                        $card_ins_bal = 0.0;
                        $card_pat_bal = 0.0;
                        $card_total_bal = 0.0;

                        $card_enc_res = sqlStatement(
                            "SELECT encounter
                            FROM form_encounter
                            WHERE pid = ?
                            ORDER BY date",
                            [$pid]
                        );

                        while ($card_enc = sqlFetchArray($card_enc_res)) {

                            $encounter = (int)$card_enc['encounter'];

                            // --------------------------------------------------
                            // Charges
                            // --------------------------------------------------
                            $enc_charge = 0.0;
                            $enc_has_ins = false;

                            $chg_res = sqlStatement(
                                "SELECT fee, payer_id
                                FROM billing
                                WHERE pid = ?
                                    AND encounter = ?
                                    AND activity > 0",
                                [$pid, $encounter]
                            );

                            while ($chg = sqlFetchArray($chg_res)) {
                                $enc_charge += (float)$chg['fee'];

                                if (!empty($chg['payer_id'])) {
                                    $enc_has_ins = true;
                                }
                            }

                            if ($enc_charge == 0) {
                                continue;
                            }

            // --------------------------------------------------
            // Starting balances
            // --------------------------------------------------
            $enc_ins_bal = ($has_insurance || $enc_has_ins)
                ? $enc_charge
                : 0.0;

            $enc_pat_bal = ($has_insurance || $enc_has_ins)
                ? 0.0
                : $enc_charge;

            $enc_total_bal = $enc_charge;

            // --------------------------------------------------
            // Payments / Adjustments
            // --------------------------------------------------
            $pmt_res = sqlStatement(
                "SELECT
                    aa.pay_amount,
                    aa.adj_amount,
                    aa.memo,
                    aa.account_code,
                    ars.payer_id
                FROM ar_activity aa
                LEFT JOIN ar_session ars
                    ON ars.session_id = aa.session_id
                WHERE aa.pid = ?
                AND aa.encounter = ?
                AND aa.deleted IS NULL
                ORDER BY aa.sequence_no, aa.post_time",
                [$pid, $encounter]
            );

            while ($p = sqlFetchArray($pmt_res)) {

                $pay_amt = (float)($p['pay_amount'] ?? 0);
                $adj_amt = abs((float)($p['adj_amount'] ?? 0));

                $acct_code = strtoupper(
                    trim((string)($p['account_code'] ?? ''))
                );

                $memo_raw = strtolower(
                    trim((string)($p['memo'] ?? ''))
                );

                $is_ins = !empty($p['payer_id']);

                // --------------------------------------------------
                // PR / Deductible / Coinsurance transfers
                // --------------------------------------------------
                $is_pr = $is_ins && (
                    strpos($memo_raw, 'copay') !== false ||
                    strpos($memo_raw, 'deductible') !== false ||
                    strpos($memo_raw, 'coinsurance') !== false ||
                    preg_match('/\bpr\b/i', $memo_raw)
                );

                if ($is_pr) {

                    $pr_amt = ($adj_amt > 0)
                        ? $adj_amt
                        : abs($pay_amt);

                    $enc_ins_bal -= $pr_amt;
                    $enc_pat_bal += $pr_amt;
                    continue;
                }

                // --------------------------------------------------
                // Insurance rows
                // --------------------------------------------------
                if ($is_ins) {

                    if ($pay_amt > 0) {
                        $enc_ins_bal   -= $pay_amt;
                        $enc_total_bal -= $pay_amt;
                    }

                    if ($adj_amt > 0) {

                        // WO = contractual writeoff
                        if (
                            $acct_code === 'WO' ||
                            strpos($memo_raw, 'writeoff') !== false ||
                            strpos($memo_raw, 'write off') !== false
                        ) {

                            $enc_ins_bal   -= $adj_amt;
                            $enc_total_bal -= $adj_amt;

                        } else {

                            $enc_ins_bal   -= $adj_amt;
                            $enc_total_bal -= $adj_amt;
                        }
                    }

                    continue;
                }

                // --------------------------------------------------
                // Patient rows
                // --------------------------------------------------
                if ($pay_amt > 0) {

                    // only reduce patient responsibility
                    $apply_amt = min($enc_pat_bal, $pay_amt);

                    $enc_pat_bal   -= $apply_amt;
                    $enc_total_bal -= $apply_amt;
                }

                if ($adj_amt > 0) {

                    $enc_pat_bal   -= $adj_amt;
                    $enc_total_bal -= $adj_amt;
                }
            }

            $card_ins_bal   += $enc_ins_bal;
            $card_pat_bal   += $enc_pat_bal;
            $card_total_bal += $enc_total_bal;
        }

        $insurancebalance = round($card_ins_bal, 2);
        $patientbalance   = round($card_pat_bal, 2);
        $totalbalance     = round($card_total_bal, 2);

                        $unallocated_amt = get_unallocated_patient_balance($pid);

                        $id = "billing_ps_expand";
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('billing'), CardRenderEvent::EVENT_HANDLE);

                        $viewArgs = [
                            'title' => xl('Billing'),
                            'id' => $id,
                            'initiallyCollapsed' => false,
                            'hideBtn' => true,
                            'patientBalance' => $patientbalance,
                            'insuranceBalance' => $insurancebalance,
                            'totalBalance' => $totalbalance,
                            'unallocated' => $unallocated_amt,
                            'forceAlwaysOpen' => true,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];

                        if (!empty($result['billing_note'])) {
                            $viewArgs['billingNote'] = $result['billing_note'];
                        }

                        if (!empty($result3['provider'])) {
                            $viewArgs['provider'] = true;
                            $viewArgs['insName'] = $insco_name;
                            $viewArgs['copay'] = $result3['copay'];
                            $viewArgs['effDate'] = $result3['effdate'];
                            $viewArgs['effDateEnd'] = $result3['effdate_end'];
                        }

                        // ── MDItek Custom Billing Widget ──
                        // Today's copay from insurance data
                        $today_copay       = !empty($result3['copay']) ? (float)$result3['copay'] : 0;
                        $today_date_label  = date('m/d/Y');
                        // Amount to collect = patient responsibility balance (what patient owes)
                        $collect_today     = max(0, $patientbalance);
                        ?>

                        <!-- ══ BILLING & CHECKOUT CARD ══ -->
                        <div class="mdi-billing-card">

                            <!-- Card header -->
                            <div class="mdi-billing-hdr">
                                <div class="mdi-billing-hdr-left">
                                    <div class="mdi-billing-hdr-icon"><i class="fas fa-dollar-sign"></i></div>
                                    <?php echo xlt('BILLING & CHECKOUT'); ?>
                                </div>
                                <div style="display:flex;align-items:center;gap:6px;">
                                    <a href="<?php echo $GLOBALS['webroot']; ?>/interface/reports/pat_ledger.php?form=1&patient_id=<?php echo attr_url($pid); ?>" onclick="top.restoreSession();" class="mdi-billing-ledger-btn">
                                        <?php echo xlt('View Ledger'); ?>
                                    </a>
                                    <button class="mdi-billing-menu-btn" title="More actions">&#8943;</button>
                                </div>
                            </div>

                            <!-- TODAY'S CHECKOUT section -->
                            <div class="mdi-today-checkout">
                                <div class="mdi-today-label">
                                    <?php echo xlt("TODAY'S CHECKOUT"); ?>
                                    <span class="mdi-today-info" title="<?php echo xla('Amount based on copay or outstanding visit balance'); ?>">&#9432;</span>
                                </div>
                                <?php
                                $amount_due_today = max($today_copay, $collect_today);
                                ?>
                                <div class="mdi-today-amounts" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                                    <div class="mdi-today-col">
                                        <div class="mdi-today-sublabel"><?php echo xlt('Outstanding Balance'); ?></div>
                                        <div class="mdi-today-amount" style="font-size:30px;color:<?php echo $totalbalance > 0 ? '#d63031' : ($totalbalance < 0 ? '#20b958' : '#6c757d'); ?>">
                                            $<?php echo text(number_format($totalbalance, 2)); ?>
                                        </div>
                                    </div>
                                    <div class="mdi-today-col">
                                        <div class="mdi-today-sublabel"><?php echo xlt('Copay Today'); ?> &middot; <?php echo text($today_date_label); ?></div>
                                        <div class="mdi-today-amount" style="font-size:24px;color:<?php echo $today_copay > 0 ? '#20b958' : '#6c757d'; ?>">
                                            $<?php echo text(number_format($today_copay, 2)); ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Two primary action buttons -->
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:0 0 10px;">
                                    <a class="mdi-checkout-main-btn" style="background:var(--accent-g);"
                                       onclick="mdiOpenQuickPay(<?php echo attr_js($pid); ?>, <?php echo attr_js($amount_due_today); ?>); return false;"
                                       href="#">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        <?php echo xlt('Checkout'); ?>
                                    </a>
                                    <a class="mdi-checkout-main-btn" style="background:#00897b;"
                                       onclick="top.restoreSession(); openPaymentModal(<?php echo attr_js($pid); ?>, 'payment'); return false;"
                                       href="#">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                        <?php echo xlt('Payments'); ?>
                                    </a>
                                </div>

                                <!-- Print / Email receipt -->
                                <div class="mdi-receipt-row">
                                    <a class="mdi-receipt-btn" href="#"
                                       onclick="mdiViewReceiptPopup(<?php echo attr_js($pid); ?>); return false;">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php echo xlt('View Receipt'); ?>
                                    </a>
                                    <a class="mdi-receipt-btn" href="#"
                                       onclick="top.restoreSession(); (function(){ var w=window.open('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_checkout_receipt.php?pid=<?php echo attr_js($pid); ?>','rcpt_print','width=820,height=700,scrollbars=yes'); w.addEventListener('load',function(){ w.print(); }); })(); return false;">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                        <?php echo xlt('Print Receipt'); ?>
                                    </a>
                                    <a class="mdi-receipt-btn" href="#"
                                       onclick="top.restoreSession(); (function(){ var w=window.open('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_checkout_receipt.php?pid=<?php echo attr_js($pid); ?>','rcpt_email','width=820,height=700,scrollbars=yes'); w.addEventListener('load',function(){ if(w.sendEmail) w.sendEmail(); }); })(); return false;">
                                        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                        <?php echo xlt('Email Receipt'); ?>
                                    </a>
                                </div>

                                <!-- Auto-receipt info bar -->
                                <div class="mdi-receipt-info">
                                    <svg width="12" height="12" fill="#20b958" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/></svg>
                                    <?php echo xlt('At checkout, a receipt will be automatically generated.'); ?>
                                </div>
                            </div>

                            <!-- ACCOUNT SUMMARY section -->
                            <div class="mdi-acct-section">
                                <div class="mdi-section-hdr" style="color:var(--accent,#6c5ce7);">
                                    <span><?php echo xlt('ACCOUNT SUMMARY'); ?></span>
                                    <svg class="mdi-section-arrow" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
                                </div>
                                <table class="mdi-acct-table">
                                    <?php if ($has_insurance) : ?>
                                    <tr>
                                        <td><?php echo xlt('Insurance Pending'); ?>
                                            <?php if (!empty($insco_name)): ?>
                                            <div class="mdi-acct-sub"><?php echo text($insco_name); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="mdi-acct-val" style="color:<?php echo $insurancebalance > 0 ? '#d63031' : '#20b958'; ?>;">
                                            <?php echo text(oeFormatMoney($insurancebalance)); ?>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr>
                                        <td><?php echo $patientbalance < 0 ? xlt('Patient Credit Available') : xlt('Patient Responsibility'); ?></td>
                                        <td class="mdi-acct-val" style="color:<?php echo $patientbalance < 0 ? '#20b958' : ($patientbalance > 0 ? '#d63031' : '#333'); ?>;">
                                            <?php echo text(oeFormatMoney($patientbalance)); ?>
                                        </td>
                                    </tr>
                                    <?php if ($unallocated_amt > 0) : ?>
                                    <tr>
                                        <td style="font-style:italic;"><?php echo xlt('Patient Credit Available'); ?></td>
                                        <td class="mdi-acct-val" style="color:#20b958;font-style:italic;">-<?php echo text(oeFormatMoney($unallocated_amt)); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                    <tr class="mdi-acct-total">
                                        <td><?php echo $totalbalance < 0 ? xlt('Net Credit Balance') : ($totalbalance == 0 ? xlt('No Outstanding Balance') : xlt('Total Account Balance')); ?></td>
                                        <td class="mdi-acct-val" style="color:<?php echo $totalbalance > 0 ? '#d63031' : ($totalbalance < 0 ? '#20b958' : '#333'); ?>;">
                                            <?php echo $totalbalance < 0 ? '-' . text(oeFormatMoney(abs($totalbalance))) : text(oeFormatMoney($totalbalance)); ?>
                                        </td>
                                    </tr>
                                    <?php if (!empty($result3['effdate'])) : ?>
                                    <tr><td><?php echo xlt('Effective Date'); ?></td><td class="mdi-acct-val"><?php echo text(oeFormatShortDate($result3['effdate'])); ?></td></tr>
                                    <?php endif; ?>
                                    <?php if (!empty($result3['effdate_end'])) : ?>
                                    <tr><td><?php echo xlt('Effective Date End'); ?></td><td class="mdi-acct-val"><?php echo text(oeFormatShortDate($result3['effdate_end'])); ?></td></tr>
                                    <?php endif; ?>
                                </table>
                                <?php if (!empty($result['billing_note'])) : ?>
                                <div class="mdi-billing-note"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo text($result['billing_note']); ?></div>
                                <?php endif; ?>
                            </div>


                        </div><!-- /mdi-billing-card -->
                        <?php
                    endif; // End the hide_billing_widget
                    ?>

                    <?php
                    // Build $enc_rows BEFORE the encounter panel HTML renders it with count()
                    $mdi_note_col_exists = false;
                    $mdi_col_chk = sqlQuery(
                        "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME   = 'form_encounter'
                           AND COLUMN_NAME  = 'mdi_billing_note'"
                    );
                    if (!empty($mdi_col_chk['cnt'])) { $mdi_note_col_exists = true; }

                    $enc_select = $mdi_note_col_exists
                        ? "SELECT fe.encounter, fe.date, fe.reason, opc.pc_catname, fe.mdi_billing_note"
                        : "SELECT fe.encounter, fe.date, fe.reason, opc.pc_catname";

                    $enc_res = sqlStatement(
                        $enc_select .
                        " FROM form_encounter AS fe" .
                        " LEFT JOIN openemr_postcalendar_categories AS opc ON opc.pc_catid = fe.pc_catid" .
                        " WHERE fe.pid = ? ORDER BY fe.date DESC",
                        array($pid)
                    );
                    $enc_rows = [];
                    while ($enc = sqlFetchArray($enc_res)) {
                        $billed = sqlQuery(
                            "SELECT COUNT(*) AS cnt FROM billing
                              WHERE encounter = ? AND pid = ? AND activity = 1 AND billed = 1",
                            array($enc['encounter'], $pid)
                        );
                        $enc['is_billed'] = ($billed['cnt'] > 0);
                        $enc_rows[] = $enc;
                    }
                    ?>
                    <input type="hidden" id="mdi_csrf_token" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                    <div class="card" id="mdi-encounter-panel">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <span style="display:flex;align-items:center;gap:8px;">
                                <span class="card-header-icon" style="background:rgba(255,255,255,.2);color:#fff;"><i class="fas fa-bolt"></i></span>
                                <?php echo xlt('Quick Charges'); ?>
                                <span class="badge badge-light ml-1" style="font-size:10px;">
                                    <?php echo count($enc_rows); ?>
                                </span>
                            </span>
                            <a href="#"
                               onclick="top.restoreSession(); openQuickChargeModal(<?php echo attr_js($pid); ?>); return false;"
                               class="btn btn-success btn-sm" style="font-size:11px;">
                                <i class="fas fa-bolt"></i> <?php echo xlt('Quick Charge'); ?>
                            </a>
                        </div>
                        <!-- Date filter bar -->
                        <div class="mdi-enc-filter">
                            <label><?php echo xlt('From'); ?></label>
                            <input type="text" id="mdi-enc-from" class="form-control datepicker" placeholder="<?php echo attr(xl('yyyy-mm-dd')); ?>" />
                            <label><?php echo xlt('To'); ?></label>
                            <input type="text" id="mdi-enc-to" class="form-control datepicker" placeholder="<?php echo attr(xl('yyyy-mm-dd')); ?>" />
                            <button class="btn btn-sm btn-outline-primary" onclick="mdiFilterEnc()">
                                <i class="fas fa-filter"></i>
                            </button>
                            <button class="btn btn-sm btn-link text-muted p-0" onclick="mdiClearEncFilter()" title="<?php echo attr(xl('Clear filter')); ?>">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <!-- Scrollable encounter list -->
                        <div id="mdi-enc-list-wrap" style="overflow-y:auto;max-height:600px;">
                            <?php if (empty($enc_rows)) : ?>
                            <div class="mdi-enc-empty">
                                <i class="fas fa-folder-open"></i>
                                <?php echo xlt('No encounters on record.'); ?>
                            </div>
                            <?php else : ?>
                            <table class="table table-hover mb-0" id="mdi-enc-table">
                                <thead>
                                    <tr>
                                        <th><?php echo xlt('Date'); ?></th>
                                        <th><?php echo xlt('Reason / Codes'); ?></th>
                                        <th style="text-align:right;white-space:nowrap;"><?php echo xlt('Actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($enc_rows as $enc) :
                                    $enc_date_raw    = substr($enc['date'], 0, 10);
                                    $enc_date_disp   = oeFormatShortDate($enc_date_raw);
                                    $enc_reason      = $enc['reason'] ?: ($enc['pc_catname'] ?: xl('Visit'));
                                    $enc_url_id      = attr_url($enc['encounter']);
                                    $enc_url_pid     = attr_url($pid);
                                    $enc_js_id       = js_escape($enc['encounter']);
                                    $enc_js_pid      = js_escape($pid);
                                    $is_quick_charge = (stripos($enc_reason, 'Quick Charge') !== false);
                                    // Fetch CPT codes + descriptions + fees for rich tooltip
                                    $enc_codes_res = sqlStatement(
                                        "SELECT b.code, b.code_type, b.code_text, b.fee FROM billing b
                                         WHERE b.pid=? AND b.encounter=? AND b.activity>0
                                         AND b.code_type NOT IN ('ICD10','ICD9','DIAG')
                                         ORDER BY b.id LIMIT 8",
                                        [$pid, $enc['encounter']]
                                    );
                                    $enc_code_list  = [];
                                    $enc_total_fee  = 0.0;
                                    while ($ec = sqlFetchArray($enc_codes_res)) {
                                        $enc_code_list[] = [
                                            'code' => $ec['code'],
                                            'text' => $ec['code_text'] ?: $ec['code'],
                                            'fee'  => (float)($ec['fee'] ?? 0),
                                        ];
                                        $enc_total_fee += (float)($ec['fee'] ?? 0);
                                    }
                                ?>
                                <tr class="mdi-enc-row" data-date="<?php echo attr($enc_date_raw); ?>" data-encounter="<?php echo attr($enc['encounter']); ?>">
                                    <td style="white-space:nowrap;font-size:11.5px;">
                                        <span class="mdi-enc-date-wrap">
                                            <input type="date"
                                                   class="mdi-enc-date-input"
                                                   value="<?php echo attr($enc_date_raw); ?>"
                                                   data-enc="<?php echo attr($enc['encounter']); ?>"
                                                   data-pid="<?php echo attr($pid); ?>"
                                                   data-orig="<?php echo attr($enc_date_raw); ?>"
                                                   title="<?php echo attr(xl('Click to change encounter date')); ?>"
                                                   onchange="mdiSaveEncDate(this)">
                                            <i class="fas fa-calendar-alt mdi-enc-date-icon"
                                               title="<?php echo attr(xl('Change date')); ?>"
                                               onclick="this.previousElementSibling.showPicker ? this.previousElementSibling.showPicker() : this.previousElementSibling.focus()"></i>
                                        </span>
                                    </td>
                                    <td style="max-width:160px;">
                                        <?php if ($is_quick_charge) : ?>
                                            <a href="#"
                                               onclick="top.restoreSession(); dlgopen('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_fee_sheet_bridge.php?encounter=<?php echo $enc_url_id; ?>&pid=<?php echo $enc_url_pid; ?>','',1400,750,'','<?php echo xla('Fee Sheet'); ?>',{allowResize:true,allowDrag:true,dialogId:'quickcharge_modal',type:'iframe'}); return false;"
                                               style="color:var(--primary);font-weight:600;">
                                                <?php echo text($enc_reason); ?>
                                            </a>
                                        <?php else : ?>
                                            <a href="#"
                                               onclick="top.restoreSession(); dlgopen('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_fee_sheet_bridge.php?encounter=<?php echo $enc_url_id; ?>&pid=<?php echo $enc_url_pid; ?>','',1400,750,'','<?php echo xla('Fee Sheet'); ?>',{allowResize:true,allowDrag:true,dialogId:'feesheet_modal',type:'iframe'}); return false;"
                                               style="color:inherit;text-decoration:none;">
                                                <?php echo text($enc_reason); ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!empty($enc_code_list)) : ?>
                                        <?php
                                            $proc_count = count($enc_code_list);
                                            $proc_label = $proc_count === 1
                                                ? xl('1 Procedure')
                                                : sprintf(xl('%d Procedures'), $proc_count);
                                            // Build tooltip text: "CODE - Description" per line
                                            $tip_lines = [];
                                            foreach ($enc_code_list as $cl) {
                                                $tip_lines[] = $cl['code'] . ' — ' . $cl['text'];
                                            }
                                            ?>
                                        <?php
                                        $proc_count = count($enc_code_list);
                                        $proc_label = $proc_count === 1
                                            ? xl('1 Procedure')
                                            : sprintf(xl('%d Procedures'), $proc_count);
                                        $tip_lines2 = ["Procedures:"];
                                        foreach ($enc_code_list as $cl) {
                                            $fee_str2 = $cl['fee'] > 0 ? '   $' . number_format($cl['fee'], 2) : '';
                                            $tip_lines2[] = $cl['code'] . ' — ' . $cl['text'] . $fee_str2;
                                        }
                                        if ($enc_total_fee > 0) {
                                            $tip_lines2[] = "";
                                            $tip_lines2[] = 'Total:   $' . number_format($enc_total_fee, 2);
                                        }
                                        $tip_attr = attr(implode("
", $tip_lines2));
                                        ?>
                                        <span class="mdi-tip-wrap"
                                              onmouseenter="mdiShowTip(event,this)"
                                              onmouseleave="mdiHideTip()">
                                            <span class="mdi-enc-proc-badge" style="cursor:default;">
                                                <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                                                <?php echo text($proc_label); ?>
                                            </span>
                                            <span class="mdi-tipbox" data-tip="<?php echo $tip_attr; ?>"></span>
                                        </span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="white-space:nowrap;text-align:right;vertical-align:middle;">
                                        <?php if ($is_quick_charge) : ?>
                                        <a href="#"
                                           onclick="top.restoreSession(); dlgopen('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_fee_sheet_bridge.php?encounter=<?php echo $enc_url_id; ?>&pid=<?php echo $enc_url_pid; ?>','',1400,750,'','<?php echo xla('Fee Sheet'); ?>',{allowResize:true,allowDrag:true,dialogId:'quickcharge_modal',type:'iframe'}); return false;"
                                           class="btn btn-xs btn-outline-primary mr-1" style="padding:2px 7px;font-size:11px;">
                                            <?php echo xlt('Open'); ?>
                                        </a>
                                        <?php
                                        $enc_has_note = !empty(trim((string)($enc['mdi_billing_note'] ?? '')));

                                        $enc_note_style = $enc_has_note
                                            ? 'padding:2px 7px;font-size:11px;background:#f59e0b;color:#fff;border-color:#f59e0b;'
                                            : 'padding:2px 7px;font-size:11px;';

                                        $enc_note_title = $enc_has_note
                                            ? attr(xl('Billing Note Saved — Click to Edit'))
                                            : attr(xl('Add Billing Note'));
                                        ?>
                                        <a href="#" onclick="mdiOpenBillingNote(<?php echo js_escape($enc['encounter']); ?>,<?php echo js_escape($pid); ?>); return false;"
                                           class="btn btn-xs btn-outline-secondary mr-1" style="<?php echo $enc_note_style; ?>" title="<?php echo $enc_note_title; ?>"
                                           id="mdi-note-btn-<?php echo attr($enc['encounter']); ?>">
                                            <i class="fas fa-sticky-note"></i>
                                        </a>
                                        <?php if (AclMain::aclCheckCore('admin', 'super')) : ?>
                                            <?php if (!empty($enc['is_billed'])) : ?>
                                            <span class="btn btn-xs btn-secondary disabled"
                                                  title="<?php echo attr(xl('Cannot delete - encounter has been billed')); ?>">
                                                <i class="fas fa-lock"></i>
                                            </span>
                                            <?php else : ?>
                                            <a href="#"
                                               onclick="return deleteQuickCharge(<?php echo js_escape($enc['encounter']); ?>, <?php echo js_escape($pid); ?>, this);"
                                               class="btn btn-xs btn-outline-danger"
                                               title="<?php echo attr(xl('Delete encounter')); ?>">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php else : ?>
                                        <a href="#"
                                           onclick="top.restoreSession(); dlgopen('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_fee_sheet_bridge.php?encounter=<?php echo $enc_url_id; ?>&pid=<?php echo $enc_url_pid; ?>','',1400,750,'','<?php echo xla('Fee Sheet'); ?>',{allowResize:true,allowDrag:true,dialogId:'feesheet_modal',type:'iframe'}); return false;"
                                           class="btn btn-xs btn-outline-primary mr-1" style="padding:2px 7px;font-size:11px;">
                                            <?php echo xlt('Open'); ?>
                                        </a>
                                        <?php
                                        $enc_has_note = !empty(trim((string)($enc['mdi_billing_note'] ?? '')));

                                        $enc_note_style = $enc_has_note
                                            ? 'padding:2px 7px;font-size:11px;background:#f59e0b;color:#fff;border-color:#f59e0b;'
                                            : 'padding:2px 7px;font-size:11px;';

                                        $enc_note_title = $enc_has_note
                                            ? attr(xl('Billing Note Saved — Click to Edit'))
                                            : attr(xl('Add Billing Note'));
                                        ?>
                                        <a href="#" onclick="mdiOpenBillingNote(<?php echo js_escape($enc['encounter']); ?>,<?php echo js_escape($pid); ?>); return false;"
                                           class="btn btn-xs btn-outline-secondary mr-1" style="<?php echo $enc_note_style; ?>" title="<?php echo $enc_note_title; ?>"
                                           id="mdi-note-btn-<?php echo attr($enc['encounter']); ?>">
                                            <i class="fas fa-sticky-note"></i>
                                        </a>
                                        <?php if (AclMain::aclCheckCore('admin', 'super')) : ?>
                                        <span class="btn btn-xs btn-secondary disabled"
                                              title="<?php echo attr(xl('Office visit - use Delete Encounter to remove')); ?>">
                                            <i class="fas fa-lock"></i>
                                        </span>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </div><!-- /mdi-encounter-panel -->

                    <script>
                    function mdiFilterEnc() {
                        var from = document.getElementById('mdi-enc-from').value.trim();
                        var to   = document.getElementById('mdi-enc-to').value.trim();
                        var rows = document.querySelectorAll('#mdi-enc-table .mdi-enc-row');
                        var visible = 0;
                        rows.forEach(function(row) {
                            var d = row.dataset.date;
                            var show = true;
                            if (from && d < from) show = false;
                            if (to   && d > to)   show = false;
                            row.style.display = show ? '' : 'none';
                            if (show) visible++;
                        });
                        var empty = document.getElementById('mdi-enc-no-results');
                        if (empty) empty.style.display = (visible === 0) ? '' : 'none';
                    }
                    function mdiClearEncFilter() {
                        document.getElementById('mdi-enc-from').value = '';
                        document.getElementById('mdi-enc-to').value   = '';
                        document.querySelectorAll('#mdi-enc-table .mdi-enc-row')
                            .forEach(function(r) { r.style.display = ''; });
                        var empty = document.getElementById('mdi-enc-no-results');
                        if (empty) empty.style.display = 'none';
                    }
                    function mdiSaveEncDate(input) {
                        var newDate = input.value.trim();
                        var enc     = input.dataset.enc;
                        var pid     = input.dataset.pid;
                        var orig    = input.dataset.orig;
                        if (!newDate || newDate === orig) return;
                        // Basic YYYY-MM-DD validation
                        if (!/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
                            alert('Please use YYYY-MM-DD format.');
                            input.value = orig;
                            return;
                        }
                        input.classList.add('mdi-enc-date-saving');
                        var fd = new FormData();
                        fd.append('encounter',       enc);
                        fd.append('pid',             pid);
                        fd.append('new_date',        newDate);
                        fd.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
                        fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_update_enc_date.php', {
                            method: 'POST', body: fd, credentials: 'same-origin'
                        }).then(function(r) { return r.json(); })
                        .then(function(d) {
                            input.classList.remove('mdi-enc-date-saving');
                            if (d && d.ok) {
                                input.dataset.orig = newDate;
                                // Keep the row filter in sync
                                var row = input.closest('tr');
                                if (row) row.dataset.date = newDate;
                            } else {
                                alert('Could not save date: ' + (d && d.error ? d.error : 'Unknown error'));
                                input.value = orig;
                            }
                        }).catch(function() {
                            input.classList.remove('mdi-enc-date-saving');
                            alert('Network error saving date.');
                            input.value = orig;
                        });
                    }

                    function mdiSaveVitalsDate(input) {
                        var newDate  = input.value.trim();
                        var vitalsId = input.dataset.vitalsId;
                        var pid      = input.dataset.pid;
                        var orig     = input.dataset.orig;
                        if (!newDate || newDate === orig) return;
                        if (!/^\d{4}-\d{2}-\d{2}$/.test(newDate)) {
                            alert('Please use YYYY-MM-DD format.');
                            input.value = orig;
                            return;
                        }
                        input.classList.add('mdi-enc-date-saving');
                        var fd = new FormData();
                        fd.append('vitals_id',       vitalsId);
                        fd.append('pid',             pid);
                        fd.append('new_date',        newDate);
                        fd.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
                        fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_update_vitals_date.php', {
                            method: 'POST', body: fd, credentials: 'same-origin'
                        }).then(function(r) { return r.json(); })
                        .then(function(d) {
                            input.classList.remove('mdi-enc-date-saving');
                            if (d && d.ok) {
                                input.dataset.orig = newDate;
                            } else {
                                alert('Could not save vitals date: ' + (d && d.error ? d.error : 'Unknown error'));
                                input.value = orig;
                            }
                        }).catch(function() {
                            input.classList.remove('mdi-enc-date-saving');
                            alert('Network error saving vitals date.');
                            input.value = orig;
                        });
                    }
                    function openDiagnosisEditorStandalone() {
                        top.restoreSession();

                        var ids = window._mdiIssueIds || [];

                        if (ids.length === 1) {
                            mdiOpenDiagnosisEditor(ids[0]);
                        } else if (ids.length > 1) {
                            mdiShowIssuePicker(ids);
                        } else {
                            mdiOpenDiagnosisEditor('');
                        }
                    }
                    </script>
                    <!-- end MDItek Encounter Panel -->
                    
                    <!-- ══ COL 3: Appointments + Demographics + Insurance stacked ══ -->
                    <div class="mdi-demo-ins-col">

                    <?php
                    // ── MDItek: Pre-fetch upcoming appointments ──
                    $mdi_appt_prefetch = [];
                    if (!$GLOBALS['disable_calendar'] && AclMain::aclCheckCore('patients', 'appt')) {
                        $mdi_appt_pre_events = fetchNextXAppts(date('Y-m-d'), $pid, 10, true);
                        if ($mdi_appt_pre_events) {
                            foreach ($mdi_appt_pre_events as $_ar) {
                                $_disphour = (int)substr($_ar['pc_startTime'], 0, 2);
                                $_dispmin  = substr($_ar['pc_startTime'], 3, 2);
                                $_merid    = '';
                                if ($GLOBALS['time_display_format'] == 1) {
                                    $_merid = $_disphour >= 12 ? ' PM' : ' AM';
                                    if ($_disphour > 12) $_disphour -= 12;
                                    if ($_disphour == 0) $_disphour = 12;
                                }
                                $mdi_appt_prefetch[] = [
                                    'date'     => $_ar['pc_eventDate'],
                                    'time'     => sprintf("%02d", $_disphour) . ':' . $_dispmin . $_merid,
                                    'type'     => $_ar['pc_catname'] ?: ($_ar['pc_title'] ?? xl('Appointment')),
                                    'provider' => trim(($_ar['ufname'] ?? '') . ' ' . ($_ar['ulname'] ?? '')),
                                    'eid'      => $_ar['pc_eid'],
                                    'status'   => xl('Scheduled'),
                                ];
                            }
                        }
                    }
                    ?>

                     <!-- ── MDItek Appointments Card (top of right col) ── -->
                     <div class="card mb-2 mdi-appt-card" id="mdi-appt-card-main">
                        <div class="card-header">
                            <div class="card-header-left">
                                <span class="card-header-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-calendar-alt"></i></span>
                                <?php echo xlt('Appointments'); ?>
                            </div>
                            <?php if (AclMain::aclCheckCore('patients', 'appt', '', 'write') || AclMain::aclCheckCore('patients', 'appt', '', 'addonly')) : ?>
                            <a href="#" onclick="top.restoreSession(); newEvt(); return false;" class="btn-add-circle" title="<?php echo attr(xl('New Appointment')); ?>">+</a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body p-0" style="display:flex;flex-direction:column;">
                            <?php if (empty($mdi_appt_prefetch)) : ?>
                            <div class="mdi-appt-empty">
                                <i class="fas fa-calendar-times"></i>
                                <?php echo xlt('No upcoming appointments.'); ?>
                            </div>
                            <?php else : ?>
                            <table class="mdi-appt-table">
                                <thead><tr>
                                    <th><?php echo xlt('DATE / TIME'); ?></th>
                                    <th><?php echo xlt('TYPE'); ?></th>
                                    <th><?php echo xlt('PROVIDER'); ?></th>
                                    <th><?php echo xlt('STATUS'); ?></th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($mdi_appt_prefetch as $mdi_arow) :
                                    $mdi_adate = !empty($mdi_arow['date']) ? oeFormatShortDate($mdi_arow['date']) . ' ' . $mdi_arow['time'] : '—';
                                    $mdi_astat = strtolower($mdi_arow['status'] ?? 'scheduled');
                                    $mdi_astat_label = ucfirst($mdi_arow['status'] ?? xl('Scheduled'));
                                ?>
                                <tr>
                                    <td style="white-space:nowrap;font-weight:600;"><?php echo text($mdi_adate); ?></td>
                                    <td><?php echo text($mdi_arow['type'] ?? ''); ?></td>
                                    <td style="color:var(--text-muted);"><?php echo text(trim($mdi_arow['provider'] ?? '')); ?></td>
                                    <td><span class="mdi-appt-badge <?php echo attr($mdi_astat); ?>"><?php echo text($mdi_astat_label); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                            <div class="mdi-appt-footer" style="margin-top:auto;">
                                <a href="#" onclick="mdiOpenApptAll(); return false;"
                                   class="btn btn-outline-primary btn-sm" style="width:100%;text-align:center;display:block;">
                                    <i class="fas fa-calendar-alt mr-1"></i><?php echo xlt('View All Appointments'); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                    <!-- ── END MDItek Appointments Card ── -->

                     <!-- ── MDItek Custom Demographics Card ── -->
                     <div class="card mb-2 mdi-demo-card" style="flex:none !important;">
                            <div class="card-header">
                                <div class="card-header-left">
                                    <span class="card-header-icon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-user"></i></span>
                                    <?php echo xlt('Patient Demographics'); ?>
                                </div>
                                <?php if (AclMain::aclCheckCore('patients', 'demo', '', 'write')) : ?>
                                <a href="demographics_full.php" onclick="top.restoreSession();" class="btn btn-sm btn-outline-primary"><?php echo xlt('Edit'); ?></a>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-3">
                                <?php
                                $demo_name   = trim(($result['fname'] ?? '') . ' ' . ($result['mname'] ? $result['mname'] . ' ' : '') . ($result['lname'] ?? ''));
                                $demo_dob    = !empty($result['DOB_YMD']) ? oeFormatShortDate($result['DOB_YMD']) . ' (' . getPatientAgeDisplay($result['DOB_YMD']) . ')' : '';
                                $demo_addr1  = trim(($result['street'] ?? ''));
                                $demo_addr2  = trim(($result['city'] ?? '') . ($result['city'] && $result['state'] ? ', ' : '') . ($result['state'] ?? '') . ' ' . ($result['postal_code'] ?? ''));
                                $demo_ext_id = $result['pubpid'] ?? '';
                                $mdi_phone   = $result['phone_home'] ?? ($result['phone_cell'] ?? '');
                                $mdi_email   = $result['email'] ?? ($result['email_direct'] ?? '');
                                ?>
                                <div class="mdi-demo-grid">
                                    <!-- Row 1: Name | DOB / Age -->
                                    <?php if ($demo_name) : ?>
                                    <div class="mdi-demo-row">
                                        <span class="mdi-demo-label"><?php echo xlt('Name'); ?></span>
                                        <span class="mdi-demo-value"><?php echo text(strtoupper($demo_name)); ?></span>
                                    </div>
                                    <?php else : ?><div class="mdi-demo-row"></div><?php endif; ?>
                                    <?php if ($demo_dob) : ?>
                                    <div class="mdi-demo-row">
                                        <span class="mdi-demo-label"><?php echo xlt('DOB / Age'); ?></span>
                                        <span class="mdi-demo-value"><?php echo text($demo_dob); ?></span>
                                    </div>
                                    <?php else : ?><div class="mdi-demo-row"></div><?php endif; ?>

                                    <!-- Row 2: Gender (full width) -->
                                    <?php if (!empty($result['sex'])) : ?>
                                    <div class="mdi-demo-row full-width">
                                        <span class="mdi-demo-label"><?php echo xlt('Gender'); ?></span>
                                        <span class="mdi-demo-value"><?php echo text(xl($result['sex'])); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Row 3: Phone | Email -->
                                    <?php if ($mdi_phone) : ?>
                                    <div class="mdi-demo-row">
                                        <span class="mdi-demo-label"><?php echo xlt('Phone'); ?></span>
                                        <span class="mdi-demo-value"><?php echo text($mdi_phone); ?></span>
                                    </div>
                                    <?php else : ?><div class="mdi-demo-row"></div><?php endif; ?>
                                    <div class="mdi-demo-row">
                                        <span class="mdi-demo-label"><?php echo xlt('Email'); ?></span>
                                        <?php if ($mdi_email) : ?>
                                        <span class="mdi-demo-value">
                                            <a href="mailto:<?php echo attr($mdi_email); ?>" style="color:var(--accent);text-decoration:none;font-size:12px;"><?php echo text($mdi_email); ?></a>
                                        </span>
                                        <?php else : ?>
                                        <span class="mdi-demo-value" style="color:var(--text-muted);font-style:italic;font-size:11.5px;"><?php echo xlt('Not on file'); ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Row 4: Address | External ID -->
                                    <?php if ($demo_addr1 || $demo_addr2) : ?>
                                    <div class="mdi-demo-row no-border">
                                        <span class="mdi-demo-label"><?php echo xlt('Address'); ?></span>
                                        <span class="mdi-demo-value">
                                            <?php echo text($demo_addr1); ?><?php if ($demo_addr1 && trim($demo_addr2)) echo '<br>'; ?><?php echo text(trim($demo_addr2)); ?>
                                        </span>
                                    </div>
                                    <?php else : ?><div class="mdi-demo-row no-border"></div><?php endif; ?>
                                    <?php if ($demo_ext_id) : ?>
                                    <div class="mdi-demo-row no-border">
                                        <span class="mdi-demo-label"><?php echo xlt('External ID'); ?></span>
                                        <span class="mdi-demo-value"><?php echo text($demo_ext_id); ?></span>
                                    </div>
                                    <?php else : ?><div class="mdi-demo-row no-border"></div><?php endif; ?>
                                </div><!-- /mdi-demo-grid -->
                            </div>
                            </div>
                        <!-- ── END MDItek Custom Demographics Card ── -->
                      
                        <?php
                        // Insurance
                        $insArr = [];
                        $insInBinder = '?';
                        for ($y = 1; count($insurance_array) > $y; $y++) {
                            $insInBinder .= ',?';
                        }
                        $sql = "SELECT * FROM insurance_data WHERE pid = ? AND type IN(" . $insInBinder . ") ORDER BY type, date DESC";
                        $params = [$pid];
                        $params = array_merge($params, $insurance_array);
                        $res = sqlStatement($sql, $params);
                        $prior_ins_type = '';

                        while ($row = sqlFetchArray($res)) {
                            $row['max_visits'] = $row['max_visits'] ?? '';
                            if ($row['provider']) {
                                // since the query is sorted by DATE DESC can use prior ins type to identify
                                $row['isOld'] = (!empty($row['date_end']) && $row['date_end'] <= date("Y-m-d")) ? true : false;
                                $icobj = new InsuranceCompany($row['provider']);
                                $adobj = $icobj->get_address();
                                $insco_name = trim($icobj->get_name());
                                $row['insco'] = [
                                    'name' => trim($icobj->get_name()),
                                    'address' => [
                                        'line1' => $adobj->get_line1(),
                                        'line2' => $adobj->get_line2(),
                                        'city' => $adobj->get_city(),
                                        'state' => $adobj->get_state(),
                                        'postal' => $adobj->get_zip(),
                                        'country' => $adobj->get_country()
                                    ],
                                ];
                                $row['policy_type'] = (!empty($row['policy_type'])) ? $policy_types[$row['policy_type']] : false;
                                $row['dispFromDate'] = $row['date'] ? true : false;
                                $mname = ($row['subscriber_mname'] != "") ? $row['subscriber_mname'] : "";
                                $row['subscriber_full_name'] = str_replace("%mname%", $mname, "{$row['subscriber_fname']} %mname% {$row['subscriber_lname']}");
                                $row['until_date'] = $row['isOld'] ? $row['isOld'] : xlt('Present');
                                $insArr[] = $row;
                                $prior_ins_type = $row['type'];
                            } else {
                                $row['isOld'] = (strcmp($row['type'], $prior_ins_type) == 0) ? true : false;
                                $row['dispFromDate'] = $row['date'] ? true : false;
                                $row['insco'] = [
                                    'name' => 'Self-Pay',
                                    'address' => [
                                        'line1' => '',
                                        'line2' => '',
                                        'city' => '',
                                        'state' => '',
                                        'postal' => '',
                                        'country' => ''
                                    ],
                                ];
                                $row['policy_type'] = false;
                                $mname = ''; //($row['subscriber_mname'] != "") ? $row['subscriber_mname'] : "";
                                $row['subscriber_full_name'] = ' '; // str_replace("%mname%", $mname, "{$row['subscriber_fname']} %mname% {$row['subscriber_lname']}");
                                $row['until_date'] = ($row['isOld']) ? $row['isOld'] : xlt("Present");
                                $prior_ins_type = $row['type'];
                                if ($row['type'] != 'primary') {
                                    continue;
                                }
                                $insArr[] = $row;
                            }
                        }

                        if ($GLOBALS["enable_oa"]) {
                            if (($_POST['status_update'] ?? '') === 'true') {
                                unset($_POST['status_update']);
                                $showEligibility = true;
                                $ok = EDI270::requestEligibleTransaction($pid);
                                if ($ok === true) {
                                    ob_start();
                                    EDI270::showEligibilityInformation($pid, false);
                                    $output = ob_get_contents();
                                    ob_end_clean();
                                } else {
                                    $output = $ok;
                                }
                            } else {
                                ob_start();
                                EDI270::showEligibilityInformation($pid, true);
                                $output = ob_get_contents();
                                ob_end_clean();
                            }
                        } else {
                            ob_start();
                            EDI270::showEligibilityInformation($pid, true);
                            $output = ob_get_contents();
                            ob_end_clean();
                        }

                        $id = "insurance_ps_expand";
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('insurance'), CardRenderEvent::EVENT_HANDLE);
                        $viewArgs = [
                            'title' => xl("Insurance"),
                            'id' => $id,
                            'btnLabel' => "Edit",
                            'btnLink' => "demographics_full.php",
                            'linkMethod' => 'html',
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'ins' => $insArr,
                            'eligibility' => $output,
                            'enable_oa' => $GLOBALS['enable_oa'],
                            'auth' => AclMain::aclCheckCore('patients', 'demo', '', 'write'),
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];

                        if (count($insArr) > 0) {
                            // Hidden old twig insurance card (do not remove):
                            // echo $twig->getTwig()->render('patient/card/insurance.html.twig', $viewArgs);
                        }

                        // ── MDItek Custom Insurance Summary Card ──
                        // Build comprehensive insurance list for display
                        $mdi_ins_display = [];
                        foreach ($insurance_array as $ins_type) {
                            $ins_q = sqlQuery(
                                "SELECT ins.*, ic.name AS ins_company_name, ic.id AS ins_company_id
                                FROM insurance_data ins
                                LEFT JOIN insurance_companies ic ON ic.id = ins.provider
                                WHERE ins.pid = ?
                                AND ins.type = ?
                                AND (ins.date IS NULL OR ins.date <= CURDATE())
                                ORDER BY ins.date DESC
                                LIMIT 1",
                                [$pid, $ins_type]
                            );
                            if (!empty($ins_q['provider'])) {
                                $ins_active = empty($ins_q['date_end']) || $ins_q['date_end'] >= date('Y-m-d');
                                $mdi_ins_display[] = [
                                    'type'     => $ins_type,
                                    'name'     => $ins_q['ins_company_name'] ?: xl('Unknown'),
                                    'policy'   => $ins_q['policy_number'] ?: '',
                                    'active'   => $ins_active,
                                    'group_no' => $ins_q['group_number'] ?: '',
                                ];
                            }
                        }
                        if (!empty($mdi_ins_display)) :
                        ?>
                        <!-- ── MDItek Custom Insurance Summary Card ── -->
                        <div class="card mb-2 mdi-ins-card">
                            <div class="card-header">
                                <div class="card-header-left">
                                    <span class="card-header-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fas fa-shield-alt"></i></span>
                                    <?php echo xlt('Insurance Summary'); ?>
                                </div>
                                <?php if (AclMain::aclCheckCore('patients', 'demo', '', 'write')) : ?>
                                <div style="display:flex;gap:6px;align-items:center;">
            
                                    <a href="insurance_full.php" onclick="top.restoreSession();" class="btn btn-sm btn-outline-primary"><?php echo xlt('Manage'); ?></a>
                                    <a href="#" onclick="mdiOpenAuthPopup(); return false;"
                                       class="btn btn-sm btn-outline-secondary" style="font-size:10px;padding:3px 8px;">
                                        <i class="fas fa-shield-alt mr-1"></i><?php echo xlt('Authorizations'); ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-3">
                                <!-- Header row -->
                                <div style="display:grid;grid-template-columns:50px 1fr auto auto;gap:6px;align-items:center;padding:0 0 6px;border-bottom:1px solid var(--border);font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);">
                                    <span><?php echo xlt('Type'); ?></span>
                                    <span><?php echo xlt('Carrier'); ?></span>
                                    <span><?php echo xlt('Policy #'); ?></span>
                                    <span><?php echo xlt('Status'); ?></span>
                                </div>
                                <?php foreach ($mdi_ins_display as $ins_item) :
                                    $type_char = strtoupper(substr($ins_item['type'], 0, 1));
                                    $type_class = $ins_item['type'];
                                    $status_label = $ins_item['active'] ? xl('Active') : xl('Inactive');
                                    $status_class = $ins_item['active'] ? 'active' : 'inactive';
                                ?>
                                <div style="display:grid;grid-template-columns:50px 1fr auto auto;gap:6px;align-items:center;padding:7px 0;border-bottom:1px solid #f0f3f7;">
                                    <span class="ins-type-badge <?php echo attr($type_class); ?>"><?php echo text($type_char); ?></span>
                                    <span class="ins-name"><?php echo text($ins_item['name']); ?></span>
                                    <span class="ins-policy"><?php echo $ins_item['policy'] ? text($ins_item['policy']) : '<span style="color:#bbb;">&#8212;</span>'; ?></span>
                                    <span class="ins-status-badge <?php echo attr($status_class); ?>"><?php echo text($status_label); ?></span>
                                </div>
                                <?php endforeach; ?>
                                <!-- Legend -->
                                <div class="ins-legend">
                                    <span><span class="ins-type-badge primary" style="width:18px;height:18px;font-size:9px;">P</span> <?php echo xlt('Primary'); ?></span>
                                    <?php if (count($mdi_ins_display) > 1) : ?>
                                    <span><span class="ins-type-badge secondary" style="width:18px;height:18px;font-size:9px;">S</span> <?php echo xlt('Secondary'); ?></span>
                                    <?php endif; ?>
                                    <?php if (count($mdi_ins_display) > 2) : ?>
                                    <span><span class="ins-type-badge tertiary" style="width:18px;height:18px;font-size:9px;">T</span> <?php echo xlt('Tertiary'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php
                                // ── Remaining Visits from insurance_data.max_visits ──
                                $mdi_max_vis = sqlQuery(
                                    "SELECT max_visits FROM insurance_data WHERE pid = ? AND type = 'primary' AND (date IS NULL OR date <= CURDATE()) ORDER BY date DESC LIMIT 1",
                                    [$pid]
                                );
                                $mdi_max_visits_val = $mdi_max_vis['max_visits'] ?? null;
                                if ($mdi_max_visits_val !== null && $mdi_max_visits_val !== '') :
                                ?>
                                <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
                                    <span style="font-size:11.5px;color:var(--text-muted);"><?php echo xlt('Max Visits'); ?></span>
                                    <span style="font-size:13px;font-weight:700;color:var(--accent);"><?php echo text($mdi_max_visits_val); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- ── END MDItek Custom Insurance Summary Card ── -->

                        <?php else : // no insurance on file — show placeholder ?>
                        <!-- ── Insurance placeholder card ── -->
                        <div class="card mb-2 mdi-ins-card">
                            <div class="card-header">
                                <div class="card-header-left">
                                    <span class="card-header-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fas fa-shield-alt"></i></span>
                                    <?php echo xlt('Insurance Summary'); ?>
                                </div>
                                <?php if (AclMain::aclCheckCore('patients', 'demo', '', 'write')) : ?>
                                <a href="insurance_full.php?set_pid=<?php echo attr_url($pid); ?>" onclick="top.restoreSession();" class="btn btn-sm btn-outline-primary"><?php echo xlt('+ Add'); ?></a>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-3" style="text-align:center;padding:20px 12px !important;">
                                <i class="fas fa-shield-alt" style="font-size:28px;color:var(--border);margin-bottom:8px;display:block;"></i>
                                <div style="font-size:12px;color:var(--text-muted);font-weight:600;margin-bottom:4px;"><?php echo xlt('No insurance on file'); ?></div>
                                <div style="font-size:11px;color:var(--text-muted);margin-bottom:12px;"><?php echo xlt('Add insurance to track coverage and billing.'); ?></div>
                                <?php if (AclMain::aclCheckCore('patients', 'demo', '', 'write')) : ?>
                                <a href="insurance_full.php?set_pid=<?php echo attr_url($pid); ?>" onclick="top.restoreSession();"
                                   style="display:inline-block;padding:6px 16px;background:var(--accent);color:#fff;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;">
                                    <i class="fas fa-plus mr-1"></i><?php echo xlt('Add Insurance'); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; // end insurance card ?>

                        <!-- ── MDItek Authorizations Popup ── -->
                        <div id="mdi-auth-overlay" onclick="if(event.target===this)mdiCloseAuthPopup();"
                             style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99997;align-items:center;justify-content:center;">
                          <div style="background:#fff;border-radius:12px;width:100%;max-width:900px;max-height:88vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.22);overflow:hidden;">
                            <!-- Header -->
                            <div style="background:#6c5ce7;color:#fff;padding:13px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
                              <span style="font-size:14px;font-weight:700;"><i class="fas fa-shield-alt mr-2"></i><?php echo xlt('Insurance Authorizations'); ?>
                                <span style="font-size:11px;font-weight:400;opacity:.8;margin-left:8px;"><?php echo text(strtoupper(($result['fname'] ?? '') . ' ' . ($result['lname'] ?? ''))); ?></span>
                              </span>
                              <div style="display:flex;gap:8px;align-items:center;">
                                <a href="<?php echo $GLOBALS['webroot']; ?>/interface/reports/prior_auth_manager.php"
                                   onclick="top.restoreSession();"
                                   target="main"
                                   style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;font-size:11px;font-weight:600;padding:5px 12px;border-radius:6px;text-decoration:none;">
                                    <i class="fas fa-external-link-alt mr-1"></i><?php echo xlt('Full Page'); ?>
                                </a>
                                <button onclick="mdiCloseAuthPopup()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;opacity:.85;">&times;</button>
                              </div>
                            </div>
                            <!-- Body: iframe loads the prior auth manager -->
                            <iframe id="mdi-auth-iframe"
                                    src="about:blank"
                                    style="flex:1;border:none;min-height:500px;"
                                    onload="this.style.opacity='1'"
                                    loading="lazy"></iframe>
                          </div>
                        </div>
                        <script>
                        (function() {
                            var _authUrl = <?php echo js_escape($GLOBALS['webroot'] . '/interface/reports/prior_auth_manager.php'); ?>;
                            var _authLoaded = false;
                            window.mdiOpenAuthPopup = function() {
                                var overlay = document.getElementById('mdi-auth-overlay');
                                var iframe  = document.getElementById('mdi-auth-iframe');
                                overlay.style.display = 'flex';
                                if (!_authLoaded) {
                                    iframe.style.opacity = '0.3';
                                    top.restoreSession();
                                    iframe.src = _authUrl;
                                    _authLoaded = true;
                                    iframe.onload = function() { this.style.opacity = '1'; };
                                }
                            };
                            window.mdiCloseAuthPopup = function() {
                                document.getElementById('mdi-auth-overlay').style.display = 'none';
                            };
                        })();
                        </script>
                        <!-- ── END MDItek Authorizations Popup ── -->

                    </div><!-- /mdi-demo-ins-col -->

                    </div><!-- /mdi-pg-left-billing -->
                </div><!-- /mdi-row Row 1 -->

                <!-- ══ ROW 2: Diagnosis | Medicare | Recommended Care ══ -->
                <div class="mdi-row mdi-row-3">
                    <div class="mdi-pg-mid-cards">
                     <!-- start right column div -->

                    <?php
                    // if anyone wants to render anything before the patient demographic list
                    $GLOBALS["kernel"]->getEventDispatcher()->dispatch(new RenderEvent($pid), RenderEvent::EVENT_SECTION_LIST_RENDER_BEFORE, 10);

                    if (AclMain::aclCheckCore('patients', 'demo')) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('demographic'), CardRenderEvent::EVENT_HANDLE);
                        // Render the Demographics box
                        $viewArgs = [
                            'title' => xl("Demographics"),
                            'id' => "demographics_ps_expand",
                            'btnLabel' => "Edit",
                            'btnLink' => "demographics_full.php",
                            'linkMethod' => "html",
                            'auth' => ACLMain::aclCheckCore('patients', 'demo', '', 'write'),
                            'requireRestore' => (!isset($_SESSION['patient_portal_onsite_two'])) ? true : false,
                            'initiallyCollapsed' => getUserSetting("demographics_ps_expand") == true ? true : false,
                            'tabID' => "DEM",
                            'result' => $result,
                            'result2' => $result2,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        // Hide the old collapsed Demographics card (do not remove)
                        // echo $twig->getTwig()->render('patient/card/tab_base.html.twig', $viewArgs);
                        ?>
                       
                        <?php
                    endif; // end if demographics authorized (AclMain 'demo')

                    // ── Claim Dates Card ──────────────────────────────────────────
                    if (AclMain::aclCheckCore('patients', 'demo')) :
                        $medicare_dates_id = "medicare_dates_ps_expand";
                        // Use substr to safely extract YYYY-MM-DD from datetime fields before formatting
                        $init_tx_date  = !empty($result['initial_treatment_date']) ? oeFormatShortDate(substr($result['initial_treatment_date'], 0, 10)) : '';
                        $injury_date_v = !empty($result['injury_date'])            ? oeFormatShortDate(substr($result['injury_date'], 0, 10))            : '';
                        $last_xray_v   = !empty($result['last_xray_date'])         ? oeFormatShortDate(substr($result['last_xray_date'], 0, 10))         : '';
                        $can_edit_cd   = AclMain::aclCheckCore('patients', 'demo', '', 'write');
                        ?>
                    </div>
                        <?php
                        // ── MDItek Custom Default Diagnosis Card (left column) ──
                        $mdi_diag_rows = [];
                        $mdi_diag_res = sqlStatement(
                            "SELECT id, title, diagnosis FROM lists
                             WHERE pid = ? AND type = 'medical_problem' AND activity = 1
                             ORDER BY date DESC",
                            [$pid]
                        );
                        while ($mdi_dr = sqlFetchArray($mdi_diag_res)) {
                            $mdi_diag_rows[] = $mdi_dr;
                        }
                        ?>
                        <!-- ── MDItek Custom Default Diagnosis Card ── -->
                        <div class="card mb-2 mdi-diag-left-card">
                            <div class="card-header">
                                <div class="card-header-left">
                                    <span class="card-header-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-stethoscope"></i></span>
                                    <?php echo xlt('Diagnosis'); ?>
                                </div>
                                <div style="display:flex;gap:6px;align-items:center;">
                                    <a href="#" onclick="openDiagnosisEditorStandalone(); return false;"
                                       class="btn btn-sm btn-outline-primary"><?php echo xlt('View All'); ?></a>
                                </div>
                            </div>
                            <div class="card-body p-3">
                                <?php
                                // Collect all unique codes across every active medical_problem row
                                $mdi_all_codes = [];
                                foreach ($mdi_diag_rows as $mdi_dr) {
                                    if (!empty($mdi_dr['diagnosis'])) {
                                        foreach (explode(';', $mdi_dr['diagnosis']) as $_dc) {
                                            $_dc = trim($_dc);
                                            if (!$_dc) { continue; }
                                            // Strip type prefix (ICD10:, ICD9:, SNOMED:, etc.)
                                            // Format is "TYPE:CODE" or "TYPE:CODE:QUALIFIER" — keep only CODE
                                            $_parts = explode(':', $_dc, 3);
                                            if (count($_parts) >= 2 && preg_match('/^[A-Z0-9]+$/', $_parts[0])) {
                                                // Has a prefix — use only the code part, strip trailing :0/:1 qualifier
                                                $_code = $_parts[1];
                                            } else {
                                                $_code = $_dc;
                                            }
                                            if ($_code && !in_array($_code, $mdi_all_codes)) {
                                                $mdi_all_codes[] = $_code;
                                            }
                                        }
                                    }
                                }
                                ?>
                                <?php if (!empty($mdi_all_codes)) : ?>
                                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">
                                    <?php foreach ($mdi_all_codes as $_code) : ?>
                                    <span class="mdi-diag-code-pill"><?php echo text($_code); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php elseif (!empty($mdi_diag_rows)) : ?>
                                <div style="margin-bottom:10px;">
                                    <?php foreach ($mdi_diag_rows as $mdi_dr) :
                                        $mdi_dtitle = trim($mdi_dr['title'] ?? '');
                                        if ($mdi_dtitle && strtolower($mdi_dtitle) !== 'default diagnosis') : ?>
                                        <div style="font-size:12px;color:var(--text);margin-bottom:2px;"><?php echo text($mdi_dtitle); ?></div>
                                        <?php endif;
                                    endforeach; ?>
                                </div>
                                <?php else : ?>
                                <div style="text-align:center;padding:16px 8px;">
                                    <i class="fas fa-stethoscope" style="font-size:26px;color:var(--border);margin-bottom:8px;display:block;"></i>
                                    <div style="font-size:12px;color:var(--text-muted);font-weight:600;margin-bottom:10px;"><?php echo xlt('No diagnoses on file'); ?></div>
                                    <a href="#" onclick="openDiagnosisEditorStandalone(); return false;"
                                       style="display:inline-block;padding:6px 16px;background:var(--accent);color:#fff;border-radius:6px;font-size:11px;font-weight:600;text-decoration:none;">
                                        <i class="fas fa-plus mr-1"></i><?php echo xlt('Add Diagnosis'); ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                                <button class="mdi-diag-add-btn" onclick="openDiagnosisEditorStandalone(); return false;">
                                    <i class="fas fa-plus"></i><?php echo xlt('Add Diagnosis'); ?>
                                </button>
                            </div>
                        </div>
                        <!-- ── END MDItek Custom Default Diagnosis Card ── -->


<div class="card mb-2" id="<?php echo attr($medicare_dates_id); ?>_card">
                            <div class="card-header d-flex justify-content-between align-items-center"
                                 data-toggle="collapse"
                                 data-target="#<?php echo attr($medicare_dates_id); ?>_body"
                                 aria-expanded="true">
                                <span>
                                    <i class="fas fa-file-invoice mr-2" style="color:#0984e3;"></i><?php echo xlt('HCFA / Billing Dates'); ?>
                                    <small style="font-size:10px;font-weight:400;opacity:0.7;margin-left:6px;"><?php echo xlt('Box 14, 15 & Last X-Ray'); ?></small>
                                </span>
                                <div style="display:flex;align-items:center;gap:6px;" onclick="event.stopPropagation()">
                                    <button type="button"
                                            class="btn btn-sm"
                                            style="font-size:11px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:3px 9px;"
                                            onclick="mdiOpenMboPopup()"
                                            title="<?php echo xla('Open Miscellaneous Billing Options'); ?>">
                                        <i class="fas fa-edit mr-1"></i><?php echo xlt('MBO'); ?>
                                    </button>
                                    <?php if ($can_edit_cd) : ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="cdToggleEdit();"
                                            id="cd-edit-btn" style="font-size:11px;">
                                        <?php echo xlt('Edit'); ?>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="collapse show card-body p-2" id="<?php echo attr($medicare_dates_id); ?>_body">
                                <!-- VIEW mode -->
                                <div id="cd-view-mode">
                                    <table class="table table-sm table-hover mb-0" style="font-size:12px;">
                                        <tbody>
                                        <tr>
                                            <td class="text-muted" style="width:55%;"><?php echo xlt('Initial Treatment Date'); ?> <small class="text-muted">(Box 15)</small></td>
                                            <td id="cd-view-init"><?php echo $init_tx_date ? text($init_tx_date) : '<span class="text-muted">&#8212;</span>'; ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted"><?php echo xlt('Injury / Onset Date'); ?> <small class="text-muted">(Box 14)</small></td>
                                            <td id="cd-view-injury"><?php echo $injury_date_v ? text($injury_date_v) : '<span class="text-muted">&#8212;</span>'; ?></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted"><?php echo xlt('Last X-Ray Date'); ?></td>
                                            <td id="cd-view-xray"><?php echo $last_xray_v ? text($last_xray_v) : '<span class="text-muted">&#8212;</span>'; ?></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <!-- EDIT mode -->
                                <?php if ($can_edit_cd) : ?>
                                <div id="cd-edit-mode" style="display:none;">
                                    <table class="table table-sm mb-2" style="font-size:12px;">
                                        <tbody>
                                        <tr>
                                            <td style="width:55%;vertical-align:middle;" class="text-muted"><?php echo xlt('Initial Treatment Date'); ?> <small>(Box 15)</small></td>
                                            <td><input type="text" id="cd-input-init" class="form-control form-control-sm datepicker" value="<?php echo attr($init_tx_date); ?>" placeholder="YYYY-MM-DD" style="max-width:160px;font-size:12px;"></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted"><?php echo xlt('Injury / Onset Date'); ?> <small>(Box 14)</small></td>
                                            <td><input type="text" id="cd-input-injury" class="form-control form-control-sm datepicker" value="<?php echo attr($injury_date_v); ?>" placeholder="YYYY-MM-DD" style="max-width:160px;font-size:12px;"></td>
                                        </tr>
                                        <tr>
                                            <td class="text-muted"><?php echo xlt('Last X-Ray Date'); ?></td>
                                            <td><input type="text" id="cd-input-xray" class="form-control form-control-sm datepicker" value="<?php echo attr($last_xray_v); ?>" placeholder="YYYY-MM-DD" style="max-width:160px;font-size:12px;"></td>
                                        </tr>
                                        </tbody>
                                    </table>
                                    <div class="d-flex pl-2 pb-1">
                                        <button type="button" class="btn btn-sm btn-primary" onclick="cdSave();" style="font-size:11px;"><?php echo xlt('Save'); ?></button>
                                        <button type="button" class="btn btn-sm btn-secondary ml-2" onclick="cdCancelEdit();" style="font-size:11px;"><?php echo xlt('Cancel'); ?></button>
                                        <span id="cd-save-msg" class="ml-2 align-self-center" style="font-size:11px;display:none;"></span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
     
                        <script>
                        (function() {
                            var _cdEditing = false;
                            window.cdToggleEdit = function() {
                                _cdEditing = !_cdEditing;
                                document.getElementById('cd-view-mode').style.display = _cdEditing ? 'none' : '';
                                document.getElementById('cd-edit-mode').style.display = _cdEditing ? '' : 'none';
                                var btn = document.getElementById('cd-edit-btn');
                                if (btn) btn.textContent = _cdEditing ? '<?php echo xlt("Cancel"); ?>' : '<?php echo xlt("Edit"); ?>';
                            };
                            window.cdCancelEdit = function() {
                                if (_cdEditing) cdToggleEdit();
                            };
                            window.cdSave = function() {
                                var msg   = document.getElementById('cd-save-msg');
                                var init  = document.getElementById('cd-input-init').value.trim();
                                var inj   = document.getElementById('cd-input-injury').value.trim();
                                var xray  = document.getElementById('cd-input-xray').value.trim();
                                msg.style.display = 'inline';
                                msg.style.color = '#666';
                                msg.textContent = '<?php echo xlt("Saving\xe2\x80\xa6"); ?>';
                                top.restoreSession();
                                fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/save_claim_dates.php', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                    body: new URLSearchParams({
                                        csrf_token_form:        '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>',
                                        pid:                    '<?php echo attr($pid); ?>',
                                        initial_treatment_date: init,
                                        injury_date:            inj,
                                        last_xray_date:         xray,
                                    })
                                })
                                .then(function(r) { return r.json(); })
                                .then(function(d) {
                                    if (d.success) {
                                        msg.style.color = '#16a34a';
                                        msg.textContent = '<?php echo xlt("Saved"); ?>';
                                        document.getElementById('cd-view-init').innerHTML   = init   || '<span class="text-muted">&#8212;</span>';
                                        document.getElementById('cd-view-injury').innerHTML = inj    || '<span class="text-muted">&#8212;</span>';
                                        document.getElementById('cd-view-xray').innerHTML   = xray   || '<span class="text-muted">&#8212;</span>';
                                        setTimeout(function() { cdCancelEdit(); msg.style.display = 'none'; }, 1200);
                                    } else {
                                        msg.style.color = '#dc2626';
                                        msg.textContent = d.error || '<?php echo xlt("Save failed"); ?>';
                                    }
                                })
                                .catch(function() {
                                    msg.style.color = '#dc2626';
                                    msg.textContent = '<?php echo xlt("Network error"); ?>';
                                });
                            };
                        })();

                        // ── MBO popup for HCFA / Billing Dates card ──
                        window.mdiOpenMboPopup = function() {
                            <?php
                            $mboFormRow = sqlQuery(
                                "SELECT f.form_id FROM forms f WHERE f.pid = ? AND f.formdir = 'misc_billing_options' AND f.deleted = 0 ORDER BY f.date DESC LIMIT 1",
                                [$pid]
                            );
                            $mboEncRow = sqlQuery(
                                "SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC LIMIT 1",
                                [$pid]
                            );
                            $mboEnc    = (int)($mboEncRow['encounter'] ?? 0);
                            $mboFormId = (int)($mboFormRow['form_id'] ?? 0);
                            ?>
                            top.restoreSession();
                            var url = '<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/encounter/load_form.php'
                                    + '?formname=misc_billing_options'
                                    + '&encounter=<?php echo attr_url($mboEnc); ?>'
                                    + '&pid=<?php echo attr_url($pid); ?>'
                                    + (<?php echo $mboFormId; ?> ? '&id=<?php echo attr_url($mboFormId); ?>' : '');
                            dlgopen(url, 'mbo_popup', 1000, 700, '',
                                '<?php echo xla('Miscellaneous Billing Options'); ?>',
                                {allowResize: true, allowDrag: true, type: 'iframe'});
                        };
                        </script>
                        <?php
                    endif; // end claim dates card
                    // ── End Claim Dates Card ───────────────────────────────────────
                    // Insurance Summary + Default Diagnosis moved to left column
                    ?>

<!-- ── MDItek: Recommended Care card ──  -->
<div class="card mb-2 mdi-recommended-care-card">
    <div class="card-header">
        <div class="card-header-left">
            <span class="card-header-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-heart"></i></span>
            <?php echo xlt('Recommended Care'); ?>
        </div>
        <a href="#" onclick="top.restoreSession(); top.mdiNavGo('<?php echo $GLOBALS['webroot']; ?>/interface/main/messages/messages.php?go=Recalls'); return false;"
           class="btn btn-sm btn-outline-primary"><?php echo xlt('View All'); ?></a>
    </div>
    <div class="card-body p-3">
        <?php
        $recall_res2 = sqlStatement(
            "SELECT r_eventDate, r_reason FROM medex_recalls WHERE r_pid = ? ORDER BY r_eventDate ASC",
            [$pid]
        );
        $recall_rows_display = [];
        while ($rr2 = sqlFetchArray($recall_res2)) {
            $recall_rows_display[] = $rr2;
        }
        if (empty($recall_rows_display)) : ?>
        <p style="font-size:12px;color:var(--text-muted);margin-bottom:10px;"><?php echo xlt('No recalls on record.'); ?></p>
        <?php else : ?>
        <?php foreach ($recall_rows_display as $ritem) : ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);font-size:12px;">
            <span style="display:flex;align-items:center;gap:6px;">
                <i class="fas fa-check-circle" style="color:#059669;font-size:11px;"></i>
                <?php echo text($ritem['r_reason']); ?>
            </span>
            <span style="color:var(--text-muted);font-size:11px;"><?php echo xlt('Due'); ?> <?php echo text(oeFormatShortDate($ritem['r_eventDate'])); ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

    </div>
</div>
<!-- ── END MDItek Recommended Care card ── -->







                    </div><!-- /mdi-pg-mid-cards -->
                </div><!-- /mdi-row Row 2 -->

                <!-- ══ ROW 3: Intake | Vitals | Allergies | Medications ══ -->
                <div class="mdi-row mdi-row-5" style="margin:16px;">
                    <!-- ══ COL RIGHT (hidden twig container): Messages, Reminders, Disclosures, Amendments, Labs, Vitals ══ -->
                    <div class="mdi-pg-right-cards">
                    <?php
                    if (AclMain::aclCheckCore('patients', 'notes')) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('note'), CardRenderEvent::EVENT_HANDLE);
                        // Notes expand collapse widget
                        $id = "pnotes_ps_expand";
                        $viewArgs = [
                            'title' => xl("Messages"),
                            'id' => $id,
                            'btnLabel' => "Edit",
                            'btnLink' => "pnotes_full.php?form_active=1",
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'linkMethod' => "html",
                            'bodyClass' => "notab",
                            'auth' => AclMain::aclCheckCore('patients', 'notes', '', 'write'),
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    endif; // end if notes authorized

                    if (AclMain::aclCheckCore('patients', 'reminder') && $GLOBALS['enable_cdr'] && $GLOBALS['enable_cdr_prw']) :
                        // patient reminders collapse widget
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('reminder'), CardRenderEvent::EVENT_HANDLE);
                        $id = "patient_reminders_ps_expand";
                        $viewArgs = [
                            'title' => xl('Patient Reminders'),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'btnLabel' => 'Edit',
                            'btnLink' => '../reminder/patient_reminders.php?mode=simple&patient_id=' . attr_url($pid),
                            'linkMethod' => 'html',
                            'bodyClass' => 'notab collapse show',
                            'auth' => AclMain::aclCheckCore('patients', 'reminder', '', 'write'),
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    endif; //end if prw is activated

                    if (AclMain::aclCheckCore('patients', 'disclosure')) :
                        $authWriteDisclosure = AclMain::aclCheckCore('patients', 'disclosure', '', 'write');
                        $authAddonlyDisclosure = AclMain::aclCheckCore('patients', 'disclosure', '', 'addonly');
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('disclosure'), CardRenderEvent::EVENT_HANDLE);
                        // disclosures expand collapse widget
                        $id = "disclosures_ps_expand";
                        $viewArgs = [
                            'title' => xl('Disclosures'),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'btnLabel' => 'Edit',
                            'btnLink' => 'disclosure_full.php',
                            'linkMethod' => 'html',
                            'bodyClass' => 'notab collapse show',
                            'auth' => ($authWriteDisclosure || $authAddonlyDisclosure),
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    endif; // end if disclosures authorized

                    if ($GLOBALS['amendments'] && AclMain::aclCheckCore('patients', 'amendment')) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('amendment'), CardRenderEvent::EVENT_HANDLE);
                        // Amendments widget
                        $sql = "SELECT * FROM amendments WHERE pid = ? ORDER BY amendment_date DESC";
                        $result = sqlStatement($sql, [$pid]);
                        $amendments = [];
                        while ($row = sqlFetchArray($result)) {
                            $amendments[] = $row;
                        }

                        $id = "amendments_ps_expand";
                        $viewArgs = [
                            'title' => xl('Amendments'),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'btnLabel' => 'Edit',
                            'btnLink' => $GLOBALS['webroot'] . "/interface/patient_file/summary/list_amendments.php?id=" . attr_url($pid),
                            'btnCLass' => '',
                            'linkMethod' => 'html',
                            'bodyClass' => 'notab collapse show',
                            'auth' => AclMain::aclCheckCore('patients', 'amendment', '', ['write', 'addonly']),
                            'amendments' => $amendments,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/amendments.html.twig', $viewArgs);
                    endif; // end amendments authorized

                    if (AclMain::aclCheckCore('patients', 'lab')) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('lab'), CardRenderEvent::EVENT_HANDLE);
                        // labdata expand collapse widget
                        // check to see if any labdata exist
                        $spruch = "SELECT procedure_report.date_collected AS date
                            FROM procedure_report
                            JOIN procedure_order ON  procedure_report.procedure_order_id = procedure_order.procedure_order_id
                            WHERE procedure_order.patient_id = ?
                            ORDER BY procedure_report.date_collected DESC";
                        $existLabdata = sqlQuery($spruch, array($pid));
                        $widgetAuth = ($existLabdata) ? true : false;

                        $id = "labdata_ps_expand";
                        $viewArgs = [
                            'title' => xl('Labs'),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'btnLabel' => 'Trend',
                            'btnLink' => "../summary/labdata.php",
                            'linkMethod' => 'html',
                            'bodyClass' => 'collapse show',
                            'auth' => $widgetAuth,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    endif; // end labs authorized

                    if ($vitals_is_registered && AclMain::aclCheckCore('patients', 'med')) :
                        $dispatchResult = $ed->dispatch(new CardRenderEvent('vital_sign'), CardRenderEvent::EVENT_HANDLE);
                        // vitals expand collapse widget
                        // check to see if any vitals exist
                        $existVitals = sqlQuery("SELECT * FROM form_vitals WHERE pid=?", array($pid));
                        $widgetAuth = ($existVitals) ? true : false;

                        $id = "vitals_ps_expand";
                        $viewArgs = [
                            'title' => xl('Vitals'),
                            'id' => $id,
                            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
                            'btnLabel' => 'Trend',
                            'btnLink' => "../encounter/trend_form.php?formname=vitals&context=dashboard",
                            'linkMethod' => 'html',
                            'bodyClass' => 'collapse show',
                            'auth' => $widgetAuth,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    endif; // end vitals

                    // if anyone wants to render anything after the patient demographic list
                    $GLOBALS["kernel"]->getEventDispatcher()->dispatch(new RenderEvent($pid), RenderEvent::EVENT_SECTION_LIST_RENDER_AFTER, 10);

                    // This generates a section similar to Vitals for each LBF form that
                    // supports charting.  The form ID is used as the "widget label".
                    $gfres = sqlStatement("SELECT grp_form_id AS option_id, grp_title AS title, grp_aco_spec
                        FROM layout_group_properties
                        WHERE grp_form_id LIKE 'LBF%'
                        AND grp_group_id = ''
                        AND grp_repeats > 0
                        AND grp_activity = 1
                        ORDER BY grp_seq, grp_title");

                    while ($gfrow = sqlFetchArray($gfres)) :
                        // $jobj = json_decode($gfrow['notes'], true);
                        $LBF_ACO = empty($gfrow['grp_aco_spec']) ? false : explode('|', $gfrow['grp_aco_spec']);
                        if ($LBF_ACO && !AclMain::aclCheckCore($LBF_ACO[0], $LBF_ACO[1])) {
                            continue;
                        }

                        // vitals expand collapse widget
                        $widgetAuth = false;
                        if (!$LBF_ACO || AclMain::aclCheckCore($LBF_ACO[0], $LBF_ACO[1], '', 'write')) {
                            // check to see if any instances exist for this patient
                            $existVitals = sqlQuery("SELECT * FROM forms WHERE pid = ? AND formdir = ? AND deleted = 0", [$pid, $vitals_form_id]);
                            $widgetAuth = $existVitals;
                        }

                        $dispatchResult = $ed->dispatch(new CardRenderEvent($gfrow['title']), CardRenderEvent::EVENT_HANDLE);
                        $viewArgs = [
                            'title' => xl($gfrow['title']),
                            'id' => $vitals_form_id,
                            'initiallyCollapsed' => getUserSetting($vitals_form_id) == true ? true : false,
                            'btnLabel' => 'Trend',
                            'btnLink' => "../encounter/trend_form.php?formname=vitals&context=dashboard",
                            'linkMethod' => 'html',
                            'bodyClass' => 'notab collapse show',
                            'auth' => $widgetAuth,
                            'prependedInjection' => $dispatchResult->getPrependedInjection(),
                            'appendedInjection' => $dispatchResult->getAppendedInjection(),
                        ];
                        echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
                    endwhile; // end while
                    ?>
                    </div><!-- /mdi-pg-right-cards -->

                    <!-- ══ COL RIGHT: Appointments (top) + Portal + Recall + Docs + Stats ══ -->
                    <div class="mdi-pg-right">
<!-- ── MDItek Intake Questionnaire Card ── -->
<?php
// Query form_questionnaire_assessments for intake data
$mdi_intake_row = sqlQuery(
    "SELECT *
       FROM form_questionnaire_assessments
      WHERE pid = ? AND activity = 1
      ORDER BY date DESC LIMIT 1",
    [$pid]
);
$mdi_intake_date      = '';
$mdi_intake_name      = '';
$mdi_intake_user      = '';
$mdi_intake_form_id   = 0;
$mdi_intake_complaint = '';
$mdi_intake_pain      = null;
$mdi_intake_completed = '';
if (!empty($mdi_intake_row)) {
    $mdi_intake_date      = !empty($mdi_intake_row['date']) ? oeFormatShortDate(substr($mdi_intake_row['date'], 0, 10)) . ' ' . substr($mdi_intake_row['date'], 11, 5) : '';
    $mdi_intake_name      = $mdi_intake_row['form_name'] ?? xl('Intake Questionnaire');
    $mdi_intake_user      = $mdi_intake_row['user'] ?? '';
    $mdi_intake_form_id   = (int)($mdi_intake_row['id'] ?? 0);
    $mdi_intake_complaint = $mdi_intake_row['intake_chief_complaint'] ?? '';
    $mdi_intake_pain      = $mdi_intake_row['intake_pain_level'];
    $mdi_intake_completed = $mdi_intake_row['intake_completed_by'] ?? $mdi_intake_user;
}
?>
<div class="card mb-2 mdi-intake-card">
    <div class="card-header">
        <div class="card-header-left">
            <span class="card-header-icon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-clipboard-list"></i></span>
            <?php echo xlt('Intake Questionnaire'); ?>
        </div>
        <?php if (AclMain::aclCheckCore('patients', 'demo', '', 'write')) : ?>

        <?php endif; ?>
    </div>
        <div class="card-body" style="padding:12px 14px !important;">
        <?php if (!empty($mdi_intake_row)) : ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
            <i class="fas fa-check-circle" style="color:#059669;font-size:16px;flex-shrink:0;"></i>
            <div>
                <div style="font-size:12px;font-weight:700;color:#059669;"><?php echo xlt('Completed'); ?></div>
                <div style="font-size:10.5px;color:var(--text-muted);"><?php echo text($mdi_intake_date); ?></div>
            </div>
        </div>
        <?php if (!empty($mdi_intake_completed)) : ?>
        <div style="font-size:11px;margin-bottom:4px;color:var(--text-muted);">
            <?php echo xlt('By'); ?>: <span style="font-weight:600;color:var(--text);"><?php echo text($mdi_intake_completed); ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($mdi_intake_complaint)) : ?>
        <div style="font-size:11px;margin-bottom:4px;color:var(--text-muted);">
            <?php echo xlt('Chief Complaint'); ?>:
            <span style="color:var(--text);font-weight:500;"><?php echo text(mb_substr($mdi_intake_complaint, 0, 60)) . (mb_strlen($mdi_intake_complaint) > 60 ? '…' : ''); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($mdi_intake_pain !== null && $mdi_intake_pain !== '') : ?>
        <div style="font-size:11px;margin-bottom:6px;color:var(--text-muted);">
            <?php echo xlt('Pain Level'); ?>:
            <span style="font-weight:700;color:<?php echo (int)$mdi_intake_pain >= 7 ? '#dc2626' : ((int)$mdi_intake_pain >= 4 ? '#d97706' : '#059669'); ?>;">
                <?php echo text($mdi_intake_pain); ?>/10
            </span>
        </div>
        <?php endif; ?>
        <a href="#"
           onclick="mdiOpenIntakePopup(); return false;"
           class="btn btn-outline-primary btn-sm" style="width:100%;text-align:center;display:block;font-size:11px;margin-top:4px;">
            <i class="fas fa-eye mr-1"></i><?php echo xlt('View Questionnaire'); ?>
        </a>
        <?php else : ?>
        <p style="font-size:12px;color:var(--text-muted);margin:0;font-style:italic;"><?php echo xlt('No intake questionnaire on file.'); ?></p>
        <?php endif; ?>
    </div>
</div>
<!-- ── END MDItek Intake Questionnaire Card ── -->


                    <?php
                    // ── MDItek: Pre-fetch upcoming appointments for top card ──
                    // (populated before the Appointments card renders above)
                    ?>


<?php
// ── MDItek Portal Access Card ──────────────────────────────────────────────
$portalAuth = AclMain::aclCheckCore('patientportal', 'portal')
           || AclMain::aclCheckCore('patients', 'demo')
           || AclMain::aclCheckCore('patients', 'med');
if ($portalAuth) {
    $portal_enabled    = isPortalEnabled();
    $portal_allowed    = isPortalAllowed($pid);
    $portal_addr_valid = isPortalSiteAddressValid();
    $creds_created     = areCredentialsCreated($pid);
    $has_email         = isContactEmail($pid);
    $portal_creds_row  = sqlQuery(
        "SELECT portal_username FROM patient_access_onsite WHERE pid = ?",
        [$pid]
    );
    $portal_username = $portal_creds_row['portal_username'] ?? '';
    // Fetch email for display
    $portal_email_row = sqlQuery("SELECT email, email_direct FROM patient_data WHERE pid = ?", [$pid]);
    $portal_email     = !empty($portal_email_row['email']) ? $portal_email_row['email']
                      : (!empty($portal_email_row['email_direct']) ? $portal_email_row['email_direct'] : '');
?>
<!-- ── MDItek Portal Access Card ── -->
<div class="card mb-2 mdi-portal-card" id="mdi-portal-card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>
            <i class="fas fa-user-shield mr-2" style="color:var(--accent);"></i>
            <?php echo xlt('Portal Access'); ?>
        </span>
        <?php if ($portal_enabled && $portal_allowed) : ?>
        <span class="portal-status-badge <?php echo $creds_created ? 'active' : ''; ?>"
              style="<?php echo !$creds_created ? 'background:var(--warning-bg);color:var(--warning);' : ''; ?>">
            <?php echo $creds_created ? xlt('Enabled') : xlt('Pending'); ?>
        </span>
        <?php elseif (!$portal_enabled) : ?>
        <span class="portal-status-badge" style="background:#f1f5f9;color:var(--text-muted);"><?php echo xlt('Disabled'); ?></span>
        <?php else : ?>
        <span class="portal-status-badge" style="background:var(--warning-bg);color:var(--warning);"><?php echo xlt('Not Allowed'); ?></span>
        <?php endif; ?>
    </div>

    <div class="card-body p-3">
        <?php if (!$portal_enabled) : ?>
            <div class="portal-warning-box"><i class="fas fa-info-circle mr-1"></i><?php echo xlt('Portal is disabled in Globals.'); ?></div>
        <?php elseif (!$portal_allowed) : ?>
            <div class="portal-warning-box"><i class="fas fa-info-circle mr-1"></i><?php echo xlt('Portal not allowed for this patient.'); ?></div>
        <?php else : ?>
            <?php if ($creds_created && $portal_username) : ?>
            <div class="portal-creds-box mb-2">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                    <i class="fas fa-check-circle" style="color:var(--success);font-size:13px;"></i>
                    <span style="font-size:12px;font-weight:600;color:var(--success);"><?php echo xlt('Credentials Active'); ?></span>
                </div>
                <div style="font-size:11.5px;color:var(--text-muted);">
                    <?php echo xlt('Username'); ?>: <strong style="color:var(--text);"><?php echo text($portal_username); ?></strong>
                </div>
            </div>
            <?php else : ?>
            <div style="background:#f8fafc;border:1px dashed var(--border-mid);border-radius:6px;padding:9px 12px;margin-bottom:10px;font-size:12px;color:var(--text-muted);">
                <i class="fas fa-key mr-1"></i><?php echo xlt('No portal credentials created yet.'); ?>
                <?php if (!$has_email) : ?>
                <div style="margin-top:5px;color:var(--warning);">
                    <i class="fas fa-exclamation-triangle mr-1"></i><?php echo xlt('Email required to create credentials.'); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div style="display:flex;flex-direction:column;gap:6px;">
                <?php if ($has_email) : ?>
                <a href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/create_portallogin.php?pid=<?php echo attr_url($pid); ?>"
                   class="btn btn-primary btn-sm small_modal">
                    <i class="fas fa-<?php echo $creds_created ? 'sync-alt' : 'user-plus'; ?> mr-1"></i>
                    <?php echo $creds_created ? xlt('Update Credentials') : xlt('Create Credentials'); ?>
                </a>
                <?php else : ?>
                <a href="demographics_full.php" onclick="top.restoreSession();"
                   class="btn btn-sm btn-outline-warning" style="font-size:11px;">
                    <i class="fas fa-pencil-alt mr-1"></i><?php echo xlt('Add Email to Demographics'); ?>
                </a>
                <?php endif; ?>
                <?php if ($creds_created && $portal_allowed && $portal_addr_valid) : ?>
                <a href="<?php echo attr($GLOBALS['portal_onsite_two_address']); ?>"
                   target="_blank" onclick="top.restoreSession();"
                   class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-external-link-alt mr-1"></i><?php echo xlt('Open Patient Portal'); ?>
                </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<!-- ── END MDItek Portal Access Card ── -->
<?php
    // Suppress original twig PortalCard render
    $portalCard = new PortalCard($GLOBALS);
}

// Secondary section cards — skip PortalCard (already rendered above)
$sectionRenderEvents = $ed->dispatch(new SectionEvent('secondary'), SectionEvent::EVENT_HANDLE);
$sectionCards = $sectionRenderEvents->getCards();
$t = $twig->getTwig();

foreach ($sectionCards as $card) {

    if ($card->getIdentifier() === 'patient_portal') {
        continue;
    }
    $_auth = $card->getAcl();
    $_auth = $card->getAcl();
    $auth = AclMain::aclCheckCore($_auth[0], $_auth[1]);
    if (!$auth) {
        continue;
    }
    $btnLabel = false;
    if ($card->canAdd()) {
        $btnLabel = 'Add';
    } elseif ($card->canEdit()) {
        $btnLabel = 'Edit';
    }
    $viewArgs = [
        'card' => $card,
        'title' => $card->getTitle(),
        'id' => $card->getIdentifier() . "_expand",
        'auth' => $auth,
        'linkMethod' => 'html',
        'initiallyCollapsed' => !$card->isInitiallyCollapsed(),
        'card_bg_color' => $card->getBackgroundColorClass(),
        'card_text_color' => $card->getTextColorClass(),
        'forceAlwaysOpen' => !$card->canCollapse(),
        'btnLabel' => $btnLabel,
        'btnLink' => "javascript:$('#patient_portal').collapse('toggle')",
    ];
    echo $t->render($card->getTemplateFile(), array_merge($card->getTemplateVariables(), $viewArgs));
}

if ($GLOBALS['erx_enable']) :
    $dispatchResult = $ed->dispatch(new CardRenderEvent('demographics'), CardRenderEvent::EVENT_HANDLE);
    echo $twig->getTwig()->render('patient/partials/erx.html.twig', [
        'prependedInjection' => $dispatchResult->getPrependedInjection(),
        'appendedInjection' => $dispatchResult->getAppendedInjection(),
    ]);
endif;

// Advance Directives
if ($GLOBALS['advance_directives_warning']) {
    // advance directives expand collapse widget

    $counterFlag = false; //flag to record whether any categories contain ad records
    $query = "SELECT id FROM categories WHERE name='Advance Directive'";
    $myrow2 = sqlQuery($query);
    $advDirArr = [];
    if ($myrow2) {
        $parentId = $myrow2['id'];
        $query = "SELECT id, name FROM categories WHERE parent=?";
        $resNew1 = sqlStatement($query, array($parentId));
        while ($myrows3 = sqlFetchArray($resNew1)) {
            $categoryId = $myrows3['id'];
            $nameDoc = $myrows3['name'];
            $query = "SELECT documents.date, documents.id
                FROM documents
                INNER JOIN categories_to_documents ON categories_to_documents.document_id=documents.id
                WHERE categories_to_documents.category_id=?
                AND documents.foreign_id=?
                AND documents.deleted = 0
                ORDER BY documents.date DESC";
            $resNew2 = sqlStatement($query, array($categoryId, $pid));
            $limitCounter = 0; // limit to one entry per category
            while (($myrows4 = sqlFetchArray($resNew2)) && ($limitCounter == 0)) {
                $dateTimeDoc = $myrows4['date'];
                // remove time from datetime stamp
                $tempParse = explode(" ", $dateTimeDoc);
                $dateDoc = $tempParse[0];
                $idDoc = $myrows4['id'];
                $tmp = [
                    'pid' => $pid,
                    'docID' => $idDoc,
                    'docName' => $nameDoc,
                    'docDate' => $dateDoc,
                ];
                $advDirArr[] = $tmp;
                $limitCounter = $limitCounter + 1;
                $counterFlag = true;
            }
        }

        $id = "adv_directives_ps_expand";

        $dispatchResult = $ed->dispatch(new CardRenderEvent('advance_directive'), CardRenderEvent::EVENT_HANDLE);
        $viewArgs = [
            'title' => xl("Advance Directives"),
            'id' => $id,
            'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
            'btnLabel' => 'Edit',
            'linkMethod' => "javascript",
            'btnLink' => "return advdirconfigure();",
            'bodyClass' => 'collapse show',
            'auth' => true,
            'advDirArr' => $advDirArr,
            'counterFlag' => $counterFlag,
            'prependedInjection' => $dispatchResult->getPrependedInjection(),
            'appendedInjection' => $dispatchResult->getAppendedInjection(),
        ];
        echo $twig->getTwig()->render('patient/card/adv_dir.html.twig', $viewArgs);
    }
}  // close advanced dir block

// Show Clinical Reminders for any user that has rules that are permitted.
$clin_rem_check = resolve_rules_sql('', '0', true, '', $_SESSION['authUser']);
$cdr = $GLOBALS['enable_cdr'];
$cdr_crw = $GLOBALS['enable_cdr_crw'];
if (!empty($clin_rem_check) && $cdr && $cdr_crw && AclMain::aclCheckCore('patients', 'alert')) {
    // clinical summary expand collapse widget
    $id = "clinical_reminders_ps_expand";
    $dispatchResult = $ed->dispatch(new CardRenderEvent('clinical_reminders'), CardRenderEvent::EVENT_HANDLE);
    $viewArgs = [
        'title' => xl("Clinical Reminders"),
        'id' => $id,
        'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
        'btnLabel' => "Edit",
        'btnLink' => "../reminder/clinical_reminders.php?patient_id=" . attr_url($pid),
        'linkMethod' => "html",
        'auth' => AclMain::aclCheckCore('patients', 'alert', '', 'write'),
        'prependedInjection' => $dispatchResult->getPrependedInjection(),
        'appendedInjection' => $dispatchResult->getAppendedInjection(),
    ];
    echo $twig->getTwig()->render('patient/card/loader.html.twig', $viewArgs);
} // end if crw

$displayAppts = false;
$displayRecurrAppts = false;
$displayPastAppts = false;

// Show current and upcoming appointments.
// Recurring appointment support and Appointment Display Sets
// added to Appointments by Ian Jardine ( epsdky ).
if (isset($pid) && !$GLOBALS['disable_calendar'] && AclMain::aclCheckCore('patients', 'appt')) {
    $displayAppts = true;
    $current_date2 = date('Y-m-d');
    $events = array();
    $apptNum = (int) $GLOBALS['number_of_appts_to_show'];
    $apptNum2 = ($apptNum != 0) ? abs($apptNum) : 10;

    $mode1 = !$GLOBALS['appt_display_sets_option'];
    $colorSet1 = $GLOBALS['appt_display_sets_color_1'];
    $colorSet2 = $GLOBALS['appt_display_sets_color_2'];
    $colorSet3 = $GLOBALS['appt_display_sets_color_3'];
    $colorSet4 = $GLOBALS['appt_display_sets_color_4'];
    $extraAppts = ($mode1) ? 1 : 6;
    $extraApptDate = '';

    $past_appts = [];
    $recallArr = [];

    $events = fetchNextXAppts($current_date2, $pid, $apptNum2 + $extraAppts, true);

    if ($events) {
        $selectNum = 0;
        $apptNumber = count($events);
        //
        if ($apptNumber <= $apptNum2) {
            $extraApptDate = '';
            //
        } elseif ($mode1 && $apptNumber == $apptNum2 + 1) {
            $extraApptDate = $events[$apptNumber - 1]['pc_eventDate'];
            array_pop($events);
            --$apptNumber;
            $selectNum = 1;
            //
        } elseif ($apptNumber == $apptNum2 + 6) {
            $extraApptDate = $events[$apptNumber - 1]['pc_eventDate'];
            array_pop($events);
            --$apptNumber;
            $selectNum = 2;
            //
        } else { // mode 2 - $apptNum2 < $apptNumber < $apptNum2 + 6
            $extraApptDate = '';
            $selectNum = 2;
            //
        }

        $limitApptIndx = $apptNum2 - 1;
        $limitApptDate = $events[$limitApptIndx]['pc_eventDate'] ?? '';

        switch ($selectNum) {
            case 2:
                $lastApptIndx = $apptNumber - 1;
                $thisNumber = $lastApptIndx - $limitApptIndx;
                for ($i = 1; $i <= $thisNumber; ++$i) {
                    if ($events[$limitApptIndx + $i]['pc_eventDate'] != $limitApptDate) {
                        $extraApptDate = $events[$limitApptIndx + $i]['pc_eventDate'];
                        $events = array_slice($events, 0, $limitApptIndx + $i);
                        break;
                    }
                }
                // Break in the loop to improve performance
            case 1:
                $firstApptIndx = 0;
                for ($i = 1; $i <= $limitApptIndx; ++$i) {
                    if ($events[$limitApptIndx - $i]['pc_eventDate'] != $limitApptDate) {
                        $firstApptIndx = $apptNum2 - $i;
                        break;
                    }
                }
                // Break in the loop to improve performance
        }

        if ($extraApptDate) {
            if ($extraApptDate != $limitApptDate) {
                $apptStyle2 = " style='background-color:" . attr($colorSet3) . ";'";
            } else {
                $apptStyle2 = " style='background-color:" . attr($colorSet4) . ";'";
            }
        }
    }

    $count = 0;
    $toggleSet = true;
    $priorDate = "";
    $therapyGroupCategories = array();
    $query = sqlStatement("SELECT pc_catid FROM openemr_postcalendar_categories WHERE pc_cattype = 3 AND pc_active = 1");
    while ($result = sqlFetchArray($query)) {
        $therapyGroupCategories[] = $result['pc_catid'];
    }

    // Build the UI Loop
    $appts = [];
    foreach ($events as $row) {
        $count++;
        $dayname = date("D", strtotime($row['pc_eventDate']));
        $displayMeridiem = ($GLOBALS['time_display_format'] == 0) ? "" : "am";
        $disphour = substr($row['pc_startTime'], 0, 2) + 0;
        $dispmin = substr($row['pc_startTime'], 3, 2);
        if ($disphour >= 12 && $GLOBALS['time_display_format'] == 1) {
            $displayMeridiem = "pm";
            if ($disphour > 12) {
                $disphour -= 12;
            }
        }

        // Note the translaution occurs here instead of in teh Twig file for some specific concatenation needs
        $etitle = xl('(Click to edit)');
        if ($row['pc_hometext'] != "") {
            $etitle = xl('Comments') . ": " . ($row['pc_hometext']) . "\r\n" . $etitle;
        }

        $row['etitle'] = $etitle;

        if ($extraApptDate && $count > $firstApptIndx) {
            $apptStyle = $apptStyle2;
        } else {
            if ($row['pc_eventDate'] != $priorDate) {
                $priorDate = $row['pc_eventDate'];
                $toggleSet = !$toggleSet;
            }

            $bgColor = ($toggleSet) ? $colorSet2 : $colorSet1;
        }

        $row['pc_eventTime'] = sprintf("%02d", $disphour) . ":{$dispmin}";
        $row['pc_status'] = generate_display_field(array('data_type' => '1', 'list_id' => 'apptstat'), $row['pc_apptstatus']);
        if ($row['pc_status'] == 'None') {
            $row['pc_status'] = 'Scheduled';
        }

        if (in_array($row['pc_catid'], $therapyGroupCategories)) {
            $row['groupName'] = getGroup($row['pc_gid'])['group_name'];
        }

        $row['uname'] = text($row['ufname'] . " " . $row['ulname']);
        $row['bgColor'] = $bgColor;
        $row['dayName'] = $dayname;
        $row['displayMeridiem'] = $displayMeridiem;
        $row['jsEvent'] = attr_js(preg_replace("/-/", "", $row['pc_eventDate'])) . ', ' . attr_js($row['pc_eid']);
        $appts[] = $row;
    }

    if ($resNotNull) {
        // Recall twig card suppressed — using custom MDItek Recommended Care card instead
        $query = sqlStatement("SELECT * FROM `medex_recalls` WHERE `r_pid` = ?", [(int)$pid]);
        $recallArr = [];
        while ($result2 = sqlFetchArray($query)) {
            $recallArr[] = [
                'date'   => $result2['r_eventDate'],
                'reason' => $result2['r_reason'],
            ];
            $count2++;
        }
        // (recall twig card intentionally not rendered here)
    }
} // End of Appointments Widget.

/* Widget that shows recurrences for appointments. */
$recurr = [];
if (isset($pid) && !$GLOBALS['disable_calendar'] && $GLOBALS['appt_recurrences_widget'] && AclMain::aclCheckCore('patients', 'appt')) {
    $displayRecurrAppts = true;
    $count = 0;
    $toggleSet = true;
    $priorDate = "";

    //Fetch patient's recurrences. Function returns array with recurrence appointments' category, recurrence pattern (interpreted), and end date.
    $recurrences = fetchRecurrences($pid);
    if (!empty($recurrences)) {
        foreach ($recurrences as $row) {
            if (!recurrence_is_current($row['pc_endDate'])) {
                continue;
            }

            if (ends_in_a_week($row['pc_endDate'])) {
                $row['close_to_end'] = true;
            }
            $recurr[] = $row;
        }
    }
}
/* End of recurrence widget */

// Show PAST appointments.
// added by Terry Hill to allow reverse sorting of the appointments
$direction = '1';
if ($GLOBALS['num_past_appointments_to_show'] < 0) {
    $direction = '2';
    ($showpast = -1 * $GLOBALS['num_past_appointments_to_show']);
} else {
    $showpast = $GLOBALS['num_past_appointments_to_show'];
}

if (isset($pid) && !$GLOBALS['disable_calendar'] && $showpast > 0 && AclMain::aclCheckCore('patients', 'appt')) {
    $displayPastAppts = true;

    $pastAppts = fetchXPastAppts($pid, $showpast, $direction); // This line added by epsdky

    $count = 0;

    foreach ($pastAppts as $row) {
        $count++;
        $dayname = date("D", strtotime($row['pc_eventDate']));
        $displayMeridiem = ($GLOBALS['time_display_format'] == 0) ? "" : "am";
        $disphour = substr($row['pc_startTime'], 0, 2) + 0;
        $dispmin = substr($row['pc_startTime'], 3, 2);
        if ($disphour >= 12) {
            $displayMeridiem = "pm";
            if ($disphour > 12 && $GLOBALS['time_display_format'] == 1) {
                $disphour -= 12;
            }
        }

        $petitle = xl('(Click to edit)');
        if ($row['pc_hometext'] != "") {
            $petitle = xl('Comments') . ": " . ($row['pc_hometext']) . "\r\n" . $petitle;
        }
        $row['etitle'] = $petitle;

        $row['pc_status'] = generate_display_field(array('data_type' => '1', 'list_id' => 'apptstat'), $row['pc_apptstatus']);

        $row['dayName'] = $dayname;
        $row['displayMeridiem'] = $displayMeridiem;
        $row['pc_eventTime'] = sprintf("%02d", $disphour) . ":{$dispmin}";
        $row['uname'] = text($row['ufname'] . " " . $row['ulname']);
        $row['jsEvent'] = attr_js(preg_replace("/-/", "", $row['pc_eventDate'])) . ', ' . attr_js($row['pc_eid']);
        $past_appts[] = $row;
    }
}
// END of past appointments

// Twig appointments card NOT rendered — its JS calls parent.left_nav functions
// that cannot be safely intercepted, causing "Cannot redefine property: left_nav" errors
// and injecting unwanted tabs/bars. Data comes from $mdi_appt_prefetch instead.

// Fire the dispatch event so plugins don't break, but skip the render
$dispatchResult = $ed->dispatch(
    new CardRenderEvent('appointment'),
    CardRenderEvent::EVENT_HANDLE
);

// Empty placeholder keeps #appointments_ps_expand references from throwing
?>
<div id="appointments_ps_expand" style="display:none;"></div>

<!-- ── MDItek Custom Vitals Card ── -->

<?php
// Fetch last 2 vitals readings for history display
$mdi_vitals_res = sqlStatement(
    "SELECT fv.id, fv.date, fv.weight, fv.height, fv.bps, fv.bpd,
            fv.pulse, fv.respiration, fv.temperature, fv.oxygen_saturation, fv.BMI
       FROM form_vitals fv
      WHERE fv.pid = ? AND fv.id > 0
      ORDER BY fv.date DESC LIMIT 2",
    [$pid]
);
$mdi_vitals_rows = [];
while ($vr = sqlFetchArray($mdi_vitals_res)) { $mdi_vitals_rows[] = $vr; }

// Fetch full vitals history (for the Vitals History popup)
$mdi_vitals_hist_res = sqlStatement(
    "SELECT fv.date, fv.weight, fv.height, fv.bps, fv.bpd,
            fv.pulse, fv.respiration, fv.temperature, fv.temp_method,
            fv.oxygen_saturation, fv.oxygen_flow_rate, fv.BMI, fv.BMI_status,
            fv.waist_circ, fv.head_circ, fv.note
       FROM form_vitals fv
      WHERE fv.pid = ? AND fv.id > 0
      ORDER BY fv.date DESC LIMIT 100",
    [$pid]
);
$mdi_vitals_hist_rows = [];
while ($vhr = sqlFetchArray($mdi_vitals_hist_res)) { $mdi_vitals_hist_rows[] = $vhr; }
?>
<div class="card mb-2 mdi-vitals-card">
    <div class="card-header">
        <div class="card-header-left">
            <span class="card-header-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-heartbeat"></i></span>
            <?php echo xlt('Vitals'); ?>
        </div>
        <a href="#"
           onclick="mdiOpenVitalsHistory(); return false;"
           class="btn btn-sm btn-outline-primary"><?php echo xlt('View History'); ?></a>
    </div>
    <div class="card-body p-0">
        <?php if (!empty($mdi_vitals_rows)) : ?>
        <?php if (!function_exists('mdi_vval')) {
            function mdi_vval($v) {
                return ($v !== null && $v !== '' && (string)$v !== '0' && (string)$v !== '0.00')
                    ? text($v) : '<span style="color:var(--text-muted);">&#8212;</span>';
            }
        } ?>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:11px;">
            <thead><tr style="background:#f7f9fc;">
                <th style="padding:6px 8px;text-align:left;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);white-space:nowrap;"><?php echo xlt('DATE'); ?></th>
                <th style="padding:6px 8px;text-align:right;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);"><?php echo xlt('WT'); ?></th>
                <th style="padding:6px 8px;text-align:right;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);"><?php echo xlt('HT'); ?></th>
                <th style="padding:6px 8px;text-align:right;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);"><?php echo xlt('SYS'); ?></th>
                <th style="padding:6px 8px;text-align:right;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);"><?php echo xlt('DIA'); ?></th>
                <th style="padding:6px 8px;text-align:right;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);"><?php echo xlt('PULSE'); ?></th>
                <th style="padding:6px 8px;text-align:right;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);"><?php echo xlt('RESP'); ?></th>
                <th style="padding:6px 8px;text-align:right;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);"><?php echo xlt('TEMP'); ?></th>
                <th style="padding:6px 8px;text-align:right;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);">O&#8322;%</th>
            </tr></thead>
            <tbody>
            <?php foreach ($mdi_vitals_rows as $vrow) :
                $vdate_raw = !empty($vrow['date']) ? substr($vrow['date'], 0, 10) : '';
                $vdate     = $vdate_raw ? oeFormatShortDate($vdate_raw) : '—';
            ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:7px 8px;white-space:nowrap;">
                    <span class="mdi-enc-date-wrap">
                        <input type="date"
                               class="mdi-enc-date-input"
                               value="<?php echo attr($vdate_raw); ?>"
                               data-vitals-id="<?php echo attr($vrow['id']); ?>"
                               data-pid="<?php echo attr($pid); ?>"
                               data-orig="<?php echo attr($vdate_raw); ?>"
                               title="<?php echo attr(xl('Click to change vitals date')); ?>"
                               onchange="mdiSaveVitalsDate(this)">
                        <i class="fas fa-calendar-alt mdi-enc-date-icon"
                           title="<?php echo attr(xl('Change date')); ?>"
                           onclick="this.previousElementSibling.showPicker ? this.previousElementSibling.showPicker() : this.previousElementSibling.focus()"></i>
                    </span>
                </td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vval($vrow['weight']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vval($vrow['height']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vval($vrow['bps']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vval($vrow['bpd']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vval($vrow['pulse']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vval($vrow['respiration']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vval($vrow['temperature']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vval($vrow['oxygen_saturation']); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else : ?>
        <div style="padding:14px;font-size:12px;color:var(--text-muted);font-style:italic;"><?php echo xlt('No vitals on record.'); ?></div>
        <?php endif; ?>
    </div>
</div>
<!-- ── END MDItek Custom Vitals Card ── -->





<!-- ── MDItek Custom Allergy Card ── -->
<?php
if (AclMain::aclCheckIssue('allergy')) :
    $mdi_allergy_rows = [];
    $mdi_allergy_res = sqlStatement(
        "SELECT id, title, begdate, enddate, reaction, outcome, occurrence, comments
         FROM lists WHERE pid = ? AND type = 'allergy'
         ORDER BY begdate DESC",
        [$pid]
    );
    while ($mdi_ar = sqlFetchArray($mdi_allergy_res)) {
        $mdi_allergy_rows[] = $mdi_ar;
    }
?>
<div class="card mb-2 mdi-allergy-card">
    <div class="card-header">
        <div class="card-header-left">
            <span class="card-header-icon" style="background:#fee2e2;color:#dc2626;"><i class="fas fa-hand-paper"></i></span>
            <?php echo xlt('Allergies'); ?>
        </div>
        <?php if (AclMain::aclCheckIssue('allergy', '', ['write','addonly'])) : ?>
            <a href="#"
            onclick='mdiOpenModal(
                "<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/add_edit_issue.php?issue=<?php echo attr_url($mdi_ar["id"]); ?>&type=allergy&pid=<?php echo attr_url($pid); ?>",
                "Edit Allergy"
            ); return false;'
            class="btn btn-sm btn-outline-danger"
            style="font-size:11px;">
                <?php echo xlt('Edit'); ?>
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-3">
        <?php if (empty($mdi_allergy_rows)) : ?>
        <p style="font-size:12px;color:var(--text-muted);margin:0;font-style:italic;"><?php echo xlt('No allergies recorded.'); ?></p>
        <?php else : ?>
        <?php foreach ($mdi_allergy_rows as $mdi_ar) :
            $mdi_a_active   = empty($mdi_ar['enddate']);
            $mdi_a_resolved = !$mdi_a_active && $mdi_ar['outcome'] == '1';
            $mdi_a_status   = $mdi_a_active ? 'active' : ($mdi_a_resolved ? 'resolved' : 'inactive');
            $mdi_a_label    = $mdi_a_active ? xl('Active') : ($mdi_a_resolved ? xl('Resolved') : xl('Inactive'));
            $mdi_a_reaction = !empty($mdi_ar['reaction'])
                ? generate_display_field(['data_type'=>'1','list_id'=>'reaction'], $mdi_ar['reaction'])
                : '';
        ?>
        <div class="mdi-issue-row">
            <div class="mdi-issue-icon"><i class="fas fa-exclamation-triangle"></i></div>
            <div class="mdi-issue-info">
                <div class="mdi-issue-title"><?php echo text($mdi_ar['title']); ?></div>
                <div class="mdi-issue-meta">
                    <span class="mdi-issue-status <?php echo attr($mdi_a_status); ?>"><?php echo text($mdi_a_label); ?></span>
                    <?php if ($mdi_a_reaction) : ?>
                    <span style="color:#dc2626;font-size:11px;"><i class="fas fa-bolt" style="font-size:9px;"></i> <?php echo $mdi_a_reaction; ?></span>
                    <?php endif; ?>
                    <?php if ($mdi_ar['begdate']) : ?>
                    <span><?php echo text(oeFormatShortDate($mdi_ar['begdate'])); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (AclMain::aclCheckIssue('allergy', '', 'write')) : ?>
                <a style="display:none;" href="#"
            onclick='mdiOpenModal(
                "<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/add_edit_issue.php?issue=<?php echo attr_url($mdi_ar["id"]); ?>&type=allergy&pid=<?php echo attr_url($pid); ?>",
                "Edit Allergy"
            ); return false;'
            class="btn btn-sm btn-outline-danger"
            style="font-size:11px;">
                <?php echo xlt('Edit'); ?>
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; // end allergy ACL ?>
<!-- ── END MDItek Custom Allergy Card ── -->

<!-- ── MDItek Custom Medications Card ── -->
<?php
if (AclMain::aclCheckIssue('medication')) :
    $mdi_med_rows = [];
    $mdi_med_cond = '';
    if ($GLOBALS['erx_enable'] && $GLOBALS['erx_medication_display']) {
        $mdi_med_cond = " AND erx_uploaded != '1'";
    }
    $mdi_med_res = sqlStatement(
        "SELECT id, title, begdate, enddate, outcome, comments
         FROM lists WHERE pid = ? AND type = 'medication'" . $mdi_med_cond . "
         ORDER BY begdate DESC",
        [$pid]
    );
    while ($mdi_mr = sqlFetchArray($mdi_med_res)) {
        $mdi_med_rows[] = $mdi_mr;
    }
?>
<div class="card mb-2 mdi-med-card">
    <div class="card-header">
        <div class="card-header-left">
            <span class="card-header-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fas fa-pills"></i></span>
            <?php echo xlt('Medications'); ?>
        </div>
        <?php if (AclMain::aclCheckIssue('medication', '', ['write','addonly'])) : ?>
            <a href="#"
            onclick='mdiOpenModal(
            "<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/add_edit_issue.php?issue=0&type=medication&pid=<?php echo attr_url($pid); ?>",
            "Add Medication"
        ); return false;'
            class="btn btn-sm btn-outline-primary"
            style="font-size:11px;">
                <?php echo xlt('Edit'); ?>
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body p-3">
        <?php if (empty($mdi_med_rows)) : ?>
        <p style="font-size:12px;color:var(--text-muted);margin:0;font-style:italic;"><?php echo xlt('No medications recorded.'); ?></p>
        <?php else : ?>
        <?php foreach ($mdi_med_rows as $mdi_mr) :
            $mdi_m_active   = empty($mdi_mr['enddate']);
            $mdi_m_resolved = !$mdi_m_active && $mdi_mr['outcome'] == '1';
            $mdi_m_status   = $mdi_m_active ? 'active' : ($mdi_m_resolved ? 'resolved' : 'inactive');
            $mdi_m_label    = $mdi_m_active ? xl('Active') : ($mdi_m_resolved ? xl('Resolved') : xl('Inactive'));
        ?>
        <div class="mdi-issue-row">
            <div class="mdi-issue-icon"><i class="fas fa-pills"></i></div>
            <div class="mdi-issue-info">
                <div class="mdi-issue-title"><?php echo text($mdi_mr['title']); ?></div>
                <div class="mdi-issue-meta">
                    <span class="mdi-issue-status <?php echo attr($mdi_m_status); ?>"><?php echo text($mdi_m_label); ?></span>
                    <?php if ($mdi_mr['begdate']) : ?>
                    <span><?php echo xlt('Since'); ?> <?php echo text(oeFormatShortDate($mdi_mr['begdate'])); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($mdi_mr['comments'])) : ?>
                    <span style="color:var(--text-muted);font-style:italic;"><?php echo text(mb_substr($mdi_mr['comments'], 0, 40)); ?><?php echo mb_strlen($mdi_mr['comments']) > 40 ? '…' : ''; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (AclMain::aclCheckIssue('medication', '', 'write')) : ?>
            <a style="display:none" href="#" class="mdi-issue-edit"
               onclick="top.restoreSession(); dlgopen('add_edit_issue.php?issue=<?php echo attr_url($mdi_mr['id']); ?>&thistype=medication', '_blank', 650, 500, '', <?php echo xlj('Edit Medication'); ?>, {onClosed:function(){ location.reload(); },allowResize:true,allowDrag:false,type:'iframe'}); return false;">
                <?php echo xlt('Edit'); ?>
            </a>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; // end medication ACL ?>
<!-- ── END MDItek Custom Medications Card ── -->

<!-- stats_div kept hidden (diagnosis editor JS still needs it loaded) -->
<div id="stats_div" style="display:none;"></div>

<?php
// ── MDItek: embed medical_problem issue ids for the diagnosis editor ──
$mdiIssueRows = sqlStatement(
    "SELECT id, title, diagnosis FROM lists
     WHERE pid = ? AND type = 'medical_problem'
       AND activity = 1
     ORDER BY date DESC",
    [$pid]
);
$mdiIssues = [];
while ($mdiRow = sqlFetchArray($mdiIssueRows)) {
    $label = $mdiRow['title'];
    if (empty($label) || strtolower(trim($label)) === 'default diagnosis') {
        if (!empty($mdiRow['diagnosis'])) {
            $codes = array_filter(array_map(function($e) {
                $p = explode(':', trim($e));
                return count($p) >= 2 ? $p[0] . ':' . $p[1] : trim($e);
            }, explode(';', $mdiRow['diagnosis'])));
            $label = implode(', ', $codes);
        } else {
            $label = 'Untitled diagnosis';
        }
    }
    $mdiIssues[] = ['id' => (int)$mdiRow['id'], 'title' => $label, 'diagnosis' => $mdiRow['diagnosis']];
}
echo '<script>window._mdiIssueIds = ' . json_encode(array_column($mdiIssues, 'id')) . ';'
   . 'window._mdiIssues = ' . json_encode($mdiIssues) . ';</script>';

// TRACK ANYTHING
// Determine if track_anything form is in use for this site.
$tmp = sqlQuery("SELECT count(*) AS count FROM registry WHERE directory = 'track_anything' AND state = 1");
$track_is_registered = $tmp['count'];
if ($track_is_registered) {
    $spruch = "SELECT id FROM forms WHERE pid = ? AND formdir = ?";
    $existTracks = sqlQuery($spruch, array($pid, "track_anything"));
    $id = "track_anything_ps_expand";
    $dispatchResult = $ed->dispatch(new CardRenderEvent('track_anything'), CardRenderEvent::EVENT_HANDLE);
    echo $twig->getTwig()->render('patient/card/loader.html.twig', [
        'title' => xl("Tracks"),
        'id' => $id,
        'initiallyCollapsed' => (getUserSetting($id) == 0) ? false : true,
        'btnLink' => "../../forms/track_anything/create.php",
        'linkMethod' => "html",
        'prependedInjection' => $dispatchResult->getPrependedInjection(),
        'appendedInjection' => $dispatchResult->getAppendedInjection(),
    ]);
}  // end track_anything

if ($thisauth) :
    echo $twig->getTwig()->render('patient/partials/delete.html.twig', [
        'isAdmin' => AclMain::aclCheckCore('admin', 'super'),
        'allowPatientDelete' => $GLOBALS['allow_pat_delete'],
        'csrf' => CsrfUtils::collectCsrfToken(),
        'pid' => $pid
    ]);
endif;
?>
                    </div><!-- /mdi-pg-right -->
                </div><!-- /mdi-row Row 3 -->

            </div><!-- /mdi-pg-grid -->

            <!-- ══ ADDITIONAL CLINICAL INFORMATION (sticky bottom, purple) ══ -->
            <div class="mdi-additional-section">
                <div class="mdi-additional-toggle" id="mdi-addl-toggle" style="justify-content:space-between;">
                    <div onclick="mdiToggleAdditional()" style="display:flex;align-items:center;gap:10px;flex:1;cursor:pointer;">
                        <i class="fas fa-chevron-up mdi-toggle-icon" id="mdi-addl-chevron"></i>
                        <i class="fas fa-th-list mr-1" style="font-size:13px;opacity:.8;"></i>
                        <?php echo xlt('Additional Clinical Information'); ?>
                    </div>
                    <button onclick="mdiOpenPatientNotes()" id="mdi-notes-btn" style="
                        display:inline-flex;align-items:center;gap:6px;
                        padding:5px 13px;margin-right:4px;
                        border:1.5px solid rgba(255,255,255,.4);border-radius:7px;
                        background:rgba(255,255,255,.15);color:#fff;
                        font-size:11.5px;font-weight:700;cursor:pointer;
                        letter-spacing:.04em;transition:background .12s;white-space:nowrap;
                    " onmouseover="this.style.background='rgba(255,255,255,.28)'" onmouseout="this.style.background='rgba(255,255,255,.15)'">
                        <i class="fas fa-sticky-note"></i>
                        <?php echo xlt('Notes'); ?>
                        <span id="mdi-notes-badge" style="
                            background:<?php echo $_pnotes_count > 0 ? 'rgba(255,255,255,.9)' : 'rgba(255,255,255,.25)'; ?>;
                            color:<?php echo $_pnotes_count > 0 ? '#5b21b6' : 'rgba(255,255,255,.7)'; ?>;
                            border-radius:20px;padding:1px 7px;font-size:10px;font-weight:800;
                        "><?php echo (int)$_pnotes_count; ?></span>
                    </button>
                </div>
                <div class="mdi-additional-body" id="mdi-addl-body">
                    <!-- Documents — controller.php?document&list&patient_id={pid} -->
                    <a class="mdi-additional-item"
                       href="<?php echo $GLOBALS['webroot']; ?>/controller.php?document&list&patient_id=<?php echo attr_url($pid); ?>"
                       onclick="top.restoreSession();">
                        <i class="fas fa-folder-open" style="color:rgba(255,200,80,.9);"></i>
                        <?php echo xlt('Documents'); ?>
                    </a>
                    <!-- Authorizations — prior_auth_manager.php -->
                    <a class="mdi-additional-item"
                       href="<?php echo $GLOBALS['webroot']; ?>/interface/reports/prior_auth_manager.php"
                       onclick="top.restoreSession();">
                        <i class="fas fa-shield-alt" style="color:rgba(147,210,255,.9);"></i>
                        <?php echo xlt('Authorizations'); ?>
                    </a>
                    <!-- External Data — external_data.php -->
                    <a class="mdi-additional-item"
                       href="<?php echo $GLOBALS['webroot']; ?>/interface/reports/external_data.php"
                       onclick="top.restoreSession();">
                        <i class="fas fa-database" style="color:rgba(134,239,172,.9);"></i>
                        <?php echo xlt('External Data'); ?>
                    </a>
                    <!-- Reports — patient_report.php -->
                    <a class="mdi-additional-item"
                       href="<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/report/patient_report.php"
                       onclick="top.restoreSession();">
                        <i class="fas fa-chart-bar" style="color:rgba(147,197,253,.9);"></i>
                        <?php echo xlt('Report'); ?>
                    </a>
                </div>
            </div>
            <script>
            function mdiToggleAdditional() {
                var body    = document.getElementById('mdi-addl-body');
                var toggle  = document.getElementById('mdi-addl-toggle');
                var chevron = document.getElementById('mdi-addl-chevron');
                body.classList.toggle('open');
                toggle.classList.toggle('open');
                // Flip arrow direction: up when collapsed, down when open
                if (body.classList.contains('open')) {
                    chevron.classList.remove('fa-chevron-up');
                    chevron.classList.add('fa-chevron-down');
                } else {
                    chevron.classList.remove('fa-chevron-down');
                    chevron.classList.add('fa-chevron-up');
                }
            }
            function mdiShowPortalTooltip() {
                var t = document.getElementById('mdi-portal-tooltip');
                if (t) t.style.display = 'block';
            }
            function mdiHidePortalTooltip() {
                var t = document.getElementById('mdi-portal-tooltip');
                if (t) t.style.display = 'none';
            }
            </script>

            <div id="mdiModalOverlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.4); z-index:9999;">
    <div style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%);
                width:700px; max-width:95%; height:550px; background:#fff; border-radius:8px;
                box-shadow:0 10px 30px rgba(0,0,0,0.2); overflow:hidden; display:flex; flex-direction:column;">

        <!-- Header -->
        <div style="padding:10px 15px; border-bottom:1px solid #ddd; display:flex; justify-content:space-between;">
            <strong id="mdiModalTitle">Modal</strong>
            <button onclick="mdiCloseModal()" style="border:none;background:none;font-size:18px;">×</button>
        </div>

        <!-- Body -->
        <iframe id="mdiModalFrame" style="flex:1; border:none;"></iframe>
    </div>
</div>
        </div><!-- /mdi-pg -->
        <?php $oemr_ui->oeBelowContainerDiv(); ?>
        <script>
            // Array of skip conditions for the checkSkipConditions() function.
            var skipArray = [
                <?php echo ($condition_str ?? ''); ?>
            ];
            checkSkipConditions();



            var isPost = <?php echo js_escape($showEligibility ?? false); ?>;
            var listId = '#' + <?php echo js_escape($list_id); ?>;
            $(function() {
                $(listId).addClass("active");
                if (isPost === true) {
                    $("#eligibility").click();
                    $("#eligibility").get(0).scrollIntoView();
                }
            });
        </script>
<script>
    // Suppress "Someone else just changed this dashboard" concurrent-edit notification.
    // Originates from patient/dashboard_header.html.twig rendered by dashboard_header.php.
    (function() {
        // 1. Intercept setInterval registrations — no-op any callback that mentions
        //    dashboard change detection (runs before the twig JS fires on DOMContentLoaded)
        var _setInterval = window.setInterval;
        window.setInterval = function(fn, delay) {
            var src = (typeof fn === 'function') ? fn.toString() : String(fn);
            if (src.indexOf('dashboard') !== -1 || src.indexOf('changed') !== -1 || src.indexOf('Someone') !== -1) {
                return 0; // swallow — return fake id
            }
            return _setInterval.apply(this, arguments);
        };

        // 2. No-op known function names the twig template may call
        window.notifyDashboardChange      = function() {};
        window.dashboardChangedAlert      = function() {};
        window.showDashboardStaleAlert    = function() {};
        window.dashboardConcurrentAlert   = function() {};
        window.checkDashboardVersion      = function() {};

        // 3. Suppress storage events keyed on dashboard change
        window.addEventListener('storage', function(e) {
            if (e.key && (e.key.indexOf('dashboard') !== -1 || e.key.indexOf('changed') !== -1)) {
                e.stopImmediatePropagation();
            }
        }, true);

        // 4. Final safety net — suppress any alert/toast containing the exact phrase
        var _alert = window.alert;
        window.alert = function(msg) {
            if (typeof msg === 'string' && msg.toLowerCase().indexOf('someone else') !== -1) return;
            _alert.apply(this, arguments);
        };

        // 5. If twig uses a custom toast fn, wrap it after DOM ready
        document.addEventListener('DOMContentLoaded', function() {
            // Clear any intervals registered by the twig template during its own DOMContentLoaded
            var highestId = window.setInterval(function(){}, 0);
            for (var i = 1; i <= highestId; i++) { window.clearInterval(i); }
            // Restore real setInterval now that twig template has already run
            window.setInterval = _setInterval;
        }, true); // capture phase — runs before twig's own DOMContentLoaded
    })();
</script>

<!-- ── MDItek: All Future Appointments Popup ── -->
<style>
#mdi-appt-all-overlay {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 99990;
    align-items: center;
    justify-content: center;
}
#mdi-appt-all-modal {
    background: #fff;
    border-radius: 10px;
    width: 100%;
    max-width: 480px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 8px 32px rgba(0,0,0,.22);
    overflow: hidden;
}
.mdi-modal-header {
    background: #6c5ce7;
    color: #fff;
    padding: 13px 18px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    font-size: 14px;
    font-weight: 700;
}
.mdi-modal-close {
    background: none;
    border: none;
    color: #fff;
    font-size: 22px;
    cursor: pointer;
    line-height: 1;
    opacity: .85;
    padding: 0;
}
.mdi-modal-close:hover { opacity: 1; }
#mdi-appt-all-body {
    flex: 1;
    overflow-y: auto;
    padding: 6px 0;
    min-height: 60px;
}
.mdi-appt-popup-empty {
    text-align: center;
    padding: 32px 16px;
    color: #6b7280;
    font-size: 13px;
    font-style: italic;
}
.mdi-appt-popup-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    border-bottom: 1px solid #f0f0f6;
    cursor: pointer;
    transition: background .12s;
}
.mdi-appt-popup-row:hover { background: #f7f5ff; }
.mdi-appt-popup-row:last-child { border-bottom: none; }
.mdi-appt-popup-date {
    flex-shrink: 0;
    width: 44px;
    background: #6c5ce7;
    border-radius: 8px;
    text-align: center;
    padding: 4px 2px 6px;
    color: #fff;
}
.mdi-appt-popup-date .month {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .06em;
    background: rgba(255,255,255,.2);
    border-radius: 4px 4px 0 0;
    padding: 1px 0;
}
.mdi-appt-popup-date .day {
    font-size: 22px;
    font-weight: 800;
    line-height: 1.1;
}
.mdi-appt-popup-info { flex: 1; min-width: 0; }
.mdi-appt-popup-info .time {
    font-size: 11px;
    color: #6b7280;
    margin-bottom: 2px;
}
.mdi-appt-popup-info .type {
    font-size: 13px;
    font-weight: 600;
    color: #1a2540;
}
.mdi-appt-popup-info .prov {
    font-size: 11px;
    color: #6b7280;
    margin-top: 1px;
}
.mdi-appt-popup-add {
    padding: 12px 16px;
    border-top: 1px solid #e5e7eb;
    flex-shrink: 0;
}
.mdi-appt-popup-add .btn-primary {
    background: #6c5ce7 !important;
    border-color: #6c5ce7 !important;
    border-radius: 7px !important;
    font-size: 13px !important;
    padding: 10px 0 !important;
    font-weight: 700 !important;
}
.mdi-appt-popup-add .btn-primary:hover { background: #5b4fcf !important; }
</style>
<div id="mdi-appt-all-overlay" onclick="if(event.target===this)mdiCloseApptAll();" style="display:none;">
  <div id="mdi-appt-all-modal">
    <div class="mdi-modal-header">
      <span><i class="fas fa-calendar-alt mr-2"></i><?php echo xlt('All Upcoming Appointments'); ?></span>
      <button class="mdi-modal-close" onclick="mdiCloseApptAll()">×</button>
    </div>
    <div id="mdi-appt-all-body">
      <div class="mdi-appt-popup-empty"><i class="fas fa-spinner fa-spin mr-2"></i><?php echo xlt('Loading...'); ?></div>
    </div>
    <div class="mdi-appt-popup-add">
      <a href="#" onclick="top.restoreSession(); newEvt(); mdiCloseApptAll(); return false;"
         class="btn btn-primary btn-sm" style="width:100%;text-align:center;display:block;">
        <i class="fas fa-plus mr-1"></i><?php echo xlt('Schedule New Appointment'); ?>
      </a>
    </div>
  </div>
</div>
<script>
(function() {
    var _mdiApptPid  = <?php echo js_escape($pid); ?>;
    var _mdiWebroot  = <?php echo js_escape($GLOBALS['webroot']); ?>;
    // Pre-fetched on the server — always available, no AJAX needed
    var _mdiApptData = <?php echo json_encode(array_values($mdi_appt_prefetch ?? [])); ?>;

    window.mdiOpenApptAll = function() {
        var overlay = document.getElementById('mdi-appt-all-overlay');
        var body    = document.getElementById('mdi-appt-all-body');
        overlay.style.display = 'flex';
        // Use server-side pre-fetched data immediately (no spinner needed)
        if (_mdiApptData && _mdiApptData.length) {
            mdiRenderApptPopup(_mdiApptData);
        } else {
            body.innerHTML = '<div class="mdi-appt-popup-empty"><i class="fas fa-calendar-times mr-2"></i>' + <?php echo xlj("No upcoming appointments found."); ?> + '</div>';
        }
    };

    window.mdiCloseApptAll = function() {
        document.getElementById('mdi-appt-all-overlay').style.display = 'none';
    };

    function mdiRenderApptPopup(appts) {
        var body = document.getElementById('mdi-appt-all-body');
        if (!appts || !appts.length) {
            body.innerHTML = '<div class="mdi-appt-popup-empty"><i class="fas fa-calendar-times mr-2"></i>' + <?php echo xlj("No upcoming appointments found."); ?> + '</div>';
            return;
        }
        var html = '';
        appts.forEach(function(a) {
            var d = new Date(a.date + 'T00:00:00');
            var month = d.toLocaleString('default', {month: 'short'});
            var day   = d.getDate();
            html += '<div class="mdi-appt-popup-row">';
            html += '<div class="mdi-appt-popup-date"><div class="month">' + month + '</div><div class="day">' + day + '</div></div>';
            html += '<div class="mdi-appt-popup-info">';
            html += '<div class="time"><i class="fas fa-clock mr-1" style="font-size:10px;"></i>' + (a.time || '') + '</div>';
            html += '<div class="type">' + (a.type || <?php echo xlj("Appointment"); ?>) + '</div>';
            if (a.provider) html += '<div class="prov"><i class="fas fa-user-md mr-1" style="font-size:10px;"></i>' + a.provider + '</div>';
            html += '</div></div>';
        });
        body.innerHTML = html;
    }

    function mdiRenderApptPopupFromPage() {
        // Parse appointments already rendered in the twig card as a fallback
        var body = document.getElementById('mdi-appt-all-body');
        var rows = document.querySelectorAll('#appointments_ps_expand table tbody tr');
        if (!rows.length) {
            body.innerHTML = '<div class="mdi-appt-popup-empty"><i class="fas fa-calendar-times mr-2"></i>' + <?php echo xlj("No upcoming appointments found."); ?> + '</div>';
            return;
        }
        var html = '';
        var seen = 0;
        rows.forEach(function(tr) {
            var tds = tr.querySelectorAll('td');
            if (tds.length >= 2) {
                var dateText = '';
                var infoText = '';
                tds.forEach(function(td, i) {
                    if (i === 0) dateText = td.textContent.trim();
                    else infoText += td.textContent.trim() + ' ';
                });
                if (dateText && infoText.trim()) {
                    html += '<div class="mdi-appt-popup-row" style="cursor:default;">';
                    html += '<div style="font-size:12px;font-weight:600;color:var(--accent);min-width:70px;">' + dateText + '</div>';
                    html += '<div style="font-size:12.5px;color:var(--text);">' + infoText.trim() + '</div>';
                    html += '</div>';
                    seen++;
                }
            }
        });
        body.innerHTML = seen ? html : '<div class="mdi-appt-popup-empty">' + <?php echo xlj("No upcoming appointments found."); ?> + '</div>';
    }

    // Intercept "View All Appointments" link in the twig appointments card
    document.addEventListener('DOMContentLoaded', function() {
        // Watch for dynamically-rendered appointment card link
        function interceptViewAll() {
            var links = document.querySelectorAll('#appointments_ps_expand a');
            links.forEach(function(a) {
                var txt = (a.textContent || a.innerText || '').trim().toLowerCase();
                if (txt.indexOf('view all') !== -1 || txt.indexOf('all appointment') !== -1) {
                    if (!a.dataset.mdiIntercepted) {
                        a.dataset.mdiIntercepted = '1';
                        a.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            mdiOpenApptAll();
                        });
                    }
                }
            });
            // Also intercept any "more" or "next" links that navigate to a calendar page
            var moreLinks = document.querySelectorAll('#appointments_ps_expand a[href*="calendar"], #appointments_ps_expand a[href*="appointments"]');
            moreLinks.forEach(function(a) {
                if (!a.dataset.mdiIntercepted) {
                    a.dataset.mdiIntercepted = '1';
                    a.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        mdiOpenApptAll();
                    });
                }
            });
        }
        // Run now and again after a short delay (twig card loads async)
        interceptViewAll();
        setTimeout(interceptViewAll, 800);
        setTimeout(interceptViewAll, 2000);

        // MutationObserver to catch dynamically-loaded cards
        var obs = new MutationObserver(function() { interceptViewAll(); });
        var apptCard = document.getElementById('appointments_ps_expand');
        if (apptCard) obs.observe(apptCard, {childList: true, subtree: true});
    });
})();
</script>

<!-- ── END MDItek Appointments Popup ── -->

<!-- ── MDItek: Vitals History Popup ── -->
<div id="mdi-vitals-history-overlay" onclick="if(event.target===this)mdiCloseVitalsHistory();" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99996;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;">
  <div style="background:#fff;border-radius:10px;width:100%;max-width:900px;max-height:calc(100vh - 40px);overflow:hidden;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.22);">
    <div style="background:var(--accent,#6c5ce7);color:#fff;padding:13px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <span style="font-size:14px;font-weight:700;"><i class="fas fa-heartbeat mr-2"></i><?php echo xlt('Vitals History'); ?></span>
      <button onclick="mdiCloseVitalsHistory()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;opacity:.85;" title="<?php echo xla('Close'); ?>">&times;</button>
    </div>
    <div style="padding:14px 18px;overflow:auto;">
      <?php if (empty($mdi_vitals_hist_rows)) : ?>
        <p style="font-size:13px;color:var(--text-muted);font-style:italic;text-align:center;padding:24px 0;margin:0;"><?php echo xlt('No vitals on record.'); ?></p>
      <?php else : ?>
        <?php
        if (!function_exists('mdi_vh_val')) {
            function mdi_vh_val($v)
            {
                return ($v !== null && $v !== '' && (string)$v !== '0' && (string)$v !== '0.00')
                    ? text($v) : '<span style="color:var(--text-muted);">&#8212;</span>';
            }
        }
        ?>
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:11px;white-space:nowrap;">
            <thead><tr style="background:#f7f9fc;">
                <?php foreach ([
                    'DATE / TIME' => 'left', 'WT' => 'right', 'HT' => 'right', 'BMI' => 'right',
                    'BMI STATUS' => 'left', 'SYS' => 'right', 'DIA' => 'right', 'PULSE' => 'right',
                    'RESP' => 'right', 'TEMP' => 'right', "O\xE2\x82\x82%" => 'right', 'O2 FLOW' => 'right',
                    'WAIST' => 'right', 'HEAD' => 'right', 'NOTE' => 'left',
                ] as $col => $align) : ?>
                <th style="padding:6px 8px;text-align:<?php echo $align; ?>;font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border);position:sticky;top:0;background:#f7f9fc;"><?php echo $col === "O\xE2\x82\x82%" ? $col : xlt($col); ?></th>
                <?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($mdi_vitals_hist_rows as $vh) :
                $vh_date = !empty($vh['date'])
                    ? oeFormatShortDate(substr($vh['date'], 0, 10)) . ' ' . substr($vh['date'], 11, 5)
                    : '—';
            ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:7px 8px;font-weight:600;color:var(--accent);"><?php echo text($vh_date); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['weight']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['height']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['BMI']); ?></td>
                <td style="padding:7px 8px;"><?php echo mdi_vh_val($vh['BMI_status']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['bps']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['bpd']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['pulse']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['respiration']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['temperature']); ?><?php echo !empty($vh['temp_method']) ? ' <span style="color:var(--text-muted);font-size:10px;">(' . text($vh['temp_method']) . ')</span>' : ''; ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['oxygen_saturation']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['oxygen_flow_rate']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['waist_circ']); ?></td>
                <td style="padding:7px 8px;text-align:right;"><?php echo mdi_vh_val($vh['head_circ']); ?></td>
                <td style="padding:7px 8px;white-space:normal;max-width:220px;color:var(--text-muted);font-style:italic;"><?php echo !empty($vh['note']) ? text($vh['note']) : '<span style="color:var(--text-muted);">&#8212;</span>'; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
function mdiOpenVitalsHistory() {
    document.getElementById('mdi-vitals-history-overlay').style.display = 'flex';
}
function mdiCloseVitalsHistory() {
    document.getElementById('mdi-vitals-history-overlay').style.display = 'none';
}
</script>
<!-- ── END MDItek: Vitals History Popup ── -->

<!-- ── MDItek: Intake Questionnaire Popup ── -->
<div id="mdi-intake-overlay" onclick="if(event.target===this)mdiCloseIntakePopup();" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99996;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;">
  <div style="background:#fff;border-radius:10px;width:100%;max-width:820px;max-height:calc(100vh - 40px);overflow:hidden;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.22);">
    <div style="background:var(--accent,#6c5ce7);color:#fff;padding:13px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <span style="font-size:14px;font-weight:700;"><i class="fas fa-clipboard-list mr-2"></i><?php echo xlt('Intake Questionnaire'); ?></span>
      <div style="display:flex;align-items:center;gap:8px;">
        <button onclick="mdiSaveIntakePopup()" id="mdi-intake-save-btn" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;font-size:12px;font-weight:700;padding:5px 14px;border-radius:6px;cursor:pointer;display:none;">
          <i class="fas fa-save mr-1"></i><?php echo xlt('Save'); ?>
        </button>
        <button onclick="mdiCloseIntakePopup()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;opacity:.85;" title="<?php echo xla('Close'); ?>">&times;</button>
      </div>
    </div>
    <div style="padding:16px 20px;overflow:auto;" id="mdi-intake-body">
      <?php if (empty($mdi_intake_row)) : ?>
        <p style="font-size:13px;color:var(--text-muted);font-style:italic;text-align:center;padding:24px 0;margin:0;"><?php echo xlt('No intake questionnaire on file.'); ?></p>
      <?php else :
          $ir = $mdi_intake_row;

          if (!function_exists('mdi_intake_field')) {
              function mdi_intake_field($label, $value, $fullWidth = false, $fieldKey = '')
              {
                  $style = $fullWidth ? 'grid-column:1/-1;' : '';
                  $inputStyle = 'width:100%;border:1px solid #dde3ee;border-radius:5px;padding:5px 8px;font-size:12.5px;color:var(--text);background:#fafbfc;font-family:inherit;resize:vertical;';
                  $dataAttr   = $fieldKey ? ' data-field="' . attr($fieldKey) . '"' : '';
                  // Use textarea for long fields (full-width), input for short ones
                  if ($fullWidth) {
                      $input = '<textarea rows="2" class="mdi-intake-editable"' . $dataAttr . ' style="' . $inputStyle . '">' . text($value) . '</textarea>';
                  } else {
                      $input = '<input type="text" class="mdi-intake-editable"' . $dataAttr . ' value="' . attr($value) . '" style="' . $inputStyle . '">';
                  }
                  return '<div style="' . $style . '">'
                       . '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);margin-bottom:3px;">' . xlt($label) . '</div>'
                       . $input
                       . '</div>';
              }
          }
          if (!function_exists('mdi_intake_section')) {
              function mdi_intake_section($title, $fieldsHtml, $icon = '')
              {
                  if (empty($fieldsHtml)) {
                      return '';
                  }
                  $out  = '<div style="margin-bottom:16px;">';
                  $out .= '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--accent);margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid var(--border);">';
                  if ($icon) {
                      $out .= '<i class="' . $icon . '" style="margin-right:6px;"></i>';
                  }
                  $out .= xlt($title) . '</div>';
                  $out .= '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:10px 16px;">' . $fieldsHtml . '</div>';
                  $out .= '</div>';
                  return $out;
              }
          }

          // ── History / Chief Complaint ──
          $mdi_hist_html = mdi_intake_field('Chief Complaint', $ir['intake_chief_complaint'] ?? '', true, 'intake_chief_complaint')
              . mdi_intake_field('Onset Date', !empty($ir['intake_onset_date']) ? oeFormatShortDate($ir['intake_onset_date']) : '', false, 'intake_onset_date')
              . mdi_intake_field('Duration', $ir['intake_duration'] ?? '', false, 'intake_duration')
              . mdi_intake_field('Cause', $ir['intake_cause'] ?? '', false, 'intake_cause')
              . mdi_intake_field('Pattern', $ir['intake_pattern'] ?? '', false, 'intake_pattern')
              . mdi_intake_field('Aggravating Factors', $ir['intake_aggravating'] ?? '', true, 'intake_aggravating')
              . mdi_intake_field('Relieving Factors', $ir['intake_relieving'] ?? '', true, 'intake_relieving');

          // ── Pain Assessment ──
          $mdi_pain_html = mdi_intake_field('Current Pain (0-10)', $ir['intake_pain_level'] ?? '', false, 'intake_pain_level')
              . mdi_intake_field('Best Pain (0-10)', $ir['intake_pain_best'] ?? '', false, 'intake_pain_best')
              . mdi_intake_field('Worst Pain (0-10)', $ir['intake_pain_worst'] ?? '', false, 'intake_pain_worst')
              . mdi_intake_field('Body Region(s)', $ir['intake_body_regions'] ?? '', true, 'intake_body_regions')
              . mdi_intake_field('Pain Quality', $ir['intake_pain_quality'] ?? '', true, 'intake_pain_quality')
              . mdi_intake_field('Radiation', $ir['intake_radiation'] ?? '', false, 'intake_radiation');

          // ── Medical Background ──
          $mdi_hx_html = mdi_intake_field('Medical Conditions', $ir['intake_conditions'] ?? '', true, 'intake_conditions')
              . mdi_intake_field('Past Surgeries', $ir['intake_surgeries'] ?? '', true, 'intake_surgeries')
              . mdi_intake_field('Current Medications', $ir['intake_medications'] ?? '', true, 'intake_medications')
              . mdi_intake_field('Allergies', $ir['intake_allergies'] ?? '', true, 'intake_allergies');

          // ── Lifestyle & Occupation ──
          $mdi_life_html = mdi_intake_field('Occupation', $ir['intake_occupation'] ?? '', false, 'intake_occupation')
              . mdi_intake_field('Smoking', $ir['intake_smoking'] ?? '', false, 'intake_smoking')
              . mdi_intake_field('Exercise', $ir['intake_exercise'] ?? '', false, 'intake_exercise')
              . mdi_intake_field('Previous Treatment', $ir['intake_prev_treatment'] ?? '', true, 'intake_prev_treatment');

          // ── Review of Systems ──
          $mdi_ros_fields = [
              'intake_sys_musculoskeletal' => 'Musculoskeletal',
              'intake_sys_neurological'    => 'Neurological',
              'intake_sys_cardiovascular'  => 'Cardiovascular',
              'intake_sys_respiratory'     => 'Respiratory',
              'intake_sys_gastrointestinal' => 'Gastrointestinal',
              'intake_sys_genitourinary'   => 'Genitourinary',
              'intake_sys_integumentary'   => 'Integumentary',
              'intake_sys_endocrine'       => 'Endocrine',
              'intake_sys_psychological'   => 'Psychological',
              'intake_sys_ent'             => 'ENT',
              'intake_sys_hematologic'     => 'Hematologic',
              'intake_sys_immunologic'     => 'Immunologic',
          ];
          $mdi_ros_html = '';
          foreach ($mdi_ros_fields as $mdi_rk => $mdi_rl) {
              $mdi_ros_html .= mdi_intake_field($mdi_rl, $ir[$mdi_rk] ?? '', false, $mdi_rk);
          }
          $mdi_ros_html .= mdi_intake_field('Notes', $ir['intake_sys_notes'] ?? '', true, 'intake_sys_notes');

          // ── Functional Impact ──
          $mdi_func_fields = [
              'intake_func_standing' => 'Standing',
              'intake_func_sitting'  => 'Sitting',
              'intake_func_walking'  => 'Walking',
              'intake_func_stairs'   => 'Stairs',
              'intake_func_bending'  => 'Bending',
              'intake_func_lifting'  => 'Lifting',
              'intake_func_dressing' => 'Dressing',
              'intake_func_driving'  => 'Driving',
              'intake_func_sleep'    => 'Sleep',
              'intake_func_work'     => 'Work',
          ];
          $mdi_func_html = '';
          foreach ($mdi_func_fields as $mdi_fk => $mdi_fl) {
              $mdi_func_html .= mdi_intake_field($mdi_fl, $ir[$mdi_fk] ?? '', false, $mdi_fk);
          }

          // ── Goals & Treatment Plan ──
          $mdi_goals_html = mdi_intake_field('Goals', $ir['intake_goals'] ?? '', true, 'intake_goals')
              . mdi_intake_field('Expected Timeline', $ir['intake_timeline'] ?? '', false, 'intake_timeline')
              . mdi_intake_field('Provider Notes', $ir['intake_provider_notes'] ?? '', true, 'intake_provider_notes');

          // ── Consent ──
          $mdi_consent_html = mdi_intake_field('Consent', $ir['intake_consent'] ?? '', false, 'intake_consent')
              . mdi_intake_field('Completed By', $ir['intake_completed_by'] ?? '', false, 'intake_completed_by');
      ?>
      <!-- meta -->
      <div style="display:flex;flex-wrap:wrap;gap:16px;margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border);font-size:12px;color:var(--text-muted);">
        <div><strong style="color:var(--text);"><?php echo xlt('Date'); ?>:</strong> <?php echo text($mdi_intake_date); ?></div>
        <?php if (!empty($mdi_intake_name)) : ?>
        <div><strong style="color:var(--text);"><?php echo xlt('Form'); ?>:</strong> <?php echo text($mdi_intake_name); ?></div>
        <?php endif; ?>
      </div>
      <!-- Hidden form id for save -->
      <input type="hidden" id="mdi-intake-form-id" value="<?php echo attr($mdi_intake_form_id); ?>">
      <div id="mdi-intake-save-msg" style="display:none;margin-bottom:8px;padding:8px 12px;border-radius:6px;font-size:12px;font-weight:600;"></div>

      <?php
      echo mdi_intake_section('History / Chief Complaint', $mdi_hist_html, 'fas fa-comment-medical');
      echo mdi_intake_section('Pain Assessment', $mdi_pain_html, 'fas fa-bolt');
      echo mdi_intake_section('Medical Background', $mdi_hx_html, 'fas fa-file-medical');
      echo mdi_intake_section('Lifestyle &amp; Occupation', $mdi_life_html, 'fas fa-briefcase');
      echo mdi_intake_section('Review of Systems', $mdi_ros_html, 'fas fa-stethoscope');
      echo mdi_intake_section('Functional Impact', $mdi_func_html, 'fas fa-walking');
      echo mdi_intake_section('Goals &amp; Treatment Plan', $mdi_goals_html, 'fas fa-bullseye');
      echo mdi_intake_section('Consent', $mdi_consent_html, 'fas fa-file-signature');
      ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
function mdiOpenIntakePopup() {
    document.getElementById('mdi-intake-overlay').style.display = 'flex';
    var saveBtn = document.getElementById('mdi-intake-save-btn');
    if (saveBtn) {
        var formId = document.getElementById('mdi-intake-form-id');
        saveBtn.style.display = (formId && formId.value > 0) ? 'inline-block' : 'none';
    }
}
function mdiCloseIntakePopup() {
    document.getElementById('mdi-intake-overlay').style.display = 'none';
}
function mdiSaveIntakePopup() {
    var btn = document.getElementById('mdi-intake-save-btn');
    var msg = document.getElementById('mdi-intake-save-msg');
    var formId = document.getElementById('mdi-intake-form-id') ? document.getElementById('mdi-intake-form-id').value : '';
    if (!formId) { return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i><?php echo xlt("Saving"); ?>…';

    var fields = {};
    document.querySelectorAll('#mdi-intake-body .mdi-intake-editable[data-field]').forEach(function(el) {
        fields[el.dataset.field] = el.value !== undefined ? el.value : el.textContent;
    });

    var body = new URLSearchParams();
    body.append('csrf_token_form', document.getElementById('mdi_csrf_token') ? document.getElementById('mdi_csrf_token').value : '');
    body.append('form_id', formId);
    body.append('pid', <?php echo js_escape($pid); ?>);
    body.append('fields', JSON.stringify(fields));

    top.restoreSession();
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_save_intake.php', {
        method: 'POST',
        credentials: 'same-origin',
        body: body
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save mr-1"></i><?php echo xlt("Save"); ?>';
        msg.style.display = 'block';
        if (d.success) {
            msg.style.background = '#f0fdf4'; msg.style.color = '#16a34a'; msg.style.border = '1px solid #bbf7d0';
            msg.textContent = '<?php echo xlt("Saved successfully!"); ?>';
            setTimeout(function() { msg.style.display = 'none'; }, 2500);
        } else {
            msg.style.background = '#fef2f2'; msg.style.color = '#dc2626'; msg.style.border = '1px solid #fecaca';
            msg.textContent = d.error || '<?php echo xlt("Save failed. Please try again."); ?>';
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save mr-1"></i><?php echo xlt("Save"); ?>';
        msg.style.display = 'block';
        msg.style.background = '#fef2f2'; msg.style.color = '#dc2626'; msg.style.border = '1px solid #fecaca';
        msg.textContent = '<?php echo xlt("Network error. Please try again."); ?>';
    });
}
</script>
<!-- ── END MDItek: Intake Questionnaire Popup ── -->


<!-- ── MDItek Quick Pay + Note Modals + JS ── -->

<!-- Patient General Notes Modal -->
<div id="mdi-patient-notes-modal" style="display:none;position:fixed;inset:0;z-index:99993;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:14px;box-shadow:0 12px 48px rgba(0,0,0,.25);width:580px;max-width:96vw;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff;padding:15px 20px;display:flex;align-items:center;justify-content:space-between;">
      <span style="font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;">
        <i class="fas fa-sticky-note"></i> <?php echo xlt('Patient Notes'); ?>
        <span style="font-size:11px;opacity:.8;font-weight:500;"><?php echo text($result['fname'] . ' ' . $result['lname']); ?></span>
      </span>
      <button onclick="document.getElementById('mdi-patient-notes-modal').style.display='none'" style="background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;">&times;</button>
    </div>
    <div style="padding:20px;">
      <!-- Existing pnotes list -->
      <div id="mdi-pnotes-list" style="max-height:280px;overflow-y:auto;margin-bottom:16px;">
        <div style="text-align:center;color:#9ca3af;font-size:12px;padding:20px 0;" id="mdi-pnotes-loading">
          <i class="fas fa-spinner fa-spin"></i> <?php echo xlt('Loading notes...'); ?>
        </div>
      </div>
      <!-- Add new note -->
      <div style="border-top:1px solid #f0ebff;padding-top:14px;">
        <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#7c3aed;display:block;margin-bottom:6px;"><?php echo xlt('Add Note'); ?></label>
        <textarea id="mdi-pnote-new-text" rows="3" style="width:100%;border:1.5px solid #e4ddf5;border-radius:8px;padding:10px;font-size:13px;resize:vertical;outline:none;font-family:inherit;" placeholder="<?php echo attr(xl('Type a note...')); ?>" onfocus="this.style.borderColor='#7c3aed'" onblur="this.style.borderColor='#e4ddf5'"></textarea>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;">
          <button onclick="document.getElementById('mdi-patient-notes-modal').style.display='none'" style="padding:8px 18px;border:1.5px solid #e4ddf5;border-radius:7px;background:#fff;color:#374151;cursor:pointer;font-size:13px;"><?php echo xlt('Close'); ?></button>
          <button onclick="mdiSavePatientNote()" style="padding:8px 20px;background:#5b21b6;color:#fff;border:none;border-radius:7px;cursor:pointer;font-size:13px;font-weight:700;"><?php echo xlt('Save Note'); ?></button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- View Receipt Modal -->
<div id="mdi-receipt-modal" style="display:none;position:fixed;inset:0;z-index:99995;background:rgba(0,0,0,.5);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.28);width:860px;max-width:96vw;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;">
    <div style="background:var(--accent,#6c5ce7);color:#fff;padding:13px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <span style="font-size:14px;font-weight:700;">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:6px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        <?php echo xlt('Receipt'); ?>
      </span>
      <button onclick="mdiCloseReceiptPopup()" style="background:none;border:none;color:#fff;font-size:22px;cursor:pointer;line-height:1;opacity:.85;" title="Close">&times;</button>
    </div>
    <div style="flex:1;overflow:hidden;min-height:0;">
      <iframe id="mdi-receipt-frame" src="" style="width:100%;height:100%;min-height:600px;border:none;display:block;"></iframe>
    </div>
  </div>
</div>

<!-- Quick Payment Modal -->
<div id="mdi-quickpay-modal" style="display:none;position:fixed;inset:0;z-index:99990;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.22);width:380px;max-width:95vw;overflow:hidden;font-family:inherit;">
    <div style="background:var(--accent,#6c5ce7);color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;">
      <span style="font-size:14px;font-weight:700;">&#128722; Quick Patient Payment</span>
      <button onclick="document.getElementById('mdi-quickpay-modal').style.display='none'" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:18px;">
      <div style="margin-bottom:12px;">
        <label style="font-size:11px;font-weight:700;color:#555;display:block;margin-bottom:4px;letter-spacing:.06em;">AMOUNT</label>
        <div style="display:flex;align-items:center;gap:8px;border:2px solid #e0e0e0;border-radius:8px;padding:4px 12px;" id="mdi-qp-amt-wrap">
          <span style="font-size:20px;font-weight:700;color:#aaa;">$</span>
          <input type="number" id="mdi-qp-amount" step="0.01" min="0" style="flex:1;font-size:24px;font-weight:700;border:none;outline:none;width:100%;" onfocus="document.getElementById('mdi-qp-amt-wrap').style.borderColor='var(--accent,#6c5ce7)'" onblur="document.getElementById('mdi-qp-amt-wrap').style.borderColor='#e0e0e0'">
        </div>
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-size:11px;font-weight:700;color:#555;display:block;margin-bottom:6px;letter-spacing:.06em;">PAYMENT METHOD</label>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;" id="mdi-qp-methods">
          <button onclick="mdiQPSelectMethod(this,'cash')" data-method="cash" style="padding:10px 6px;border:2px solid #e0e0e0;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;font-weight:600;transition:all .15s;">&#128181; Cash</button>
          <button onclick="mdiQPSelectMethod(this,'check')" data-method="check" style="padding:10px 6px;border:2px solid #e0e0e0;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;font-weight:600;transition:all .15s;">&#9989; Check</button>
          <button onclick="mdiQPSelectMethod(this,'cc')" data-method="cc" style="padding:10px 6px;border:2px solid #e0e0e0;border-radius:8px;background:#fff;cursor:pointer;font-size:12px;font-weight:600;transition:all .15s;">&#128179; Card</button>
        </div>
      </div>
      <div style="margin-bottom:14px;display:none;" id="mdi-qp-ref-row">
        <label style="font-size:11px;font-weight:700;color:#555;display:block;margin-bottom:4px;letter-spacing:.06em;" id="mdi-qp-ref-label">REFERENCE #</label>
        <input type="text" id="mdi-qp-ref" placeholder="e.g. check number" style="width:100%;border:2px solid #e0e0e0;border-radius:8px;padding:8px 12px;font-size:13px;outline:none;" onfocus="this.style.borderColor='var(--accent,#6c5ce7)'" onblur="this.style.borderColor='#e0e0e0'">
      </div>
      <div style="margin-bottom:14px;">
        <label style="font-size:11px;font-weight:700;color:#555;display:block;margin-bottom:4px;letter-spacing:.06em;">DATE</label>
        <input type="text" id="mdi-qp-date" class="datepicker" style="width:100%;border:2px solid #e0e0e0;border-radius:8px;padding:8px 12px;font-size:13px;background:#fff;color:#333;cursor:pointer;outline:none;" value="<?php echo attr(date('Y-m-d')); ?>" placeholder="YYYY-MM-DD" onfocus="this.style.borderColor='var(--accent,#6c5ce7)'" onblur="this.style.borderColor='#e0e0e0'">
      </div>
      <input type="hidden" id="mdi-qp-pid" value="">
      <input type="hidden" id="mdi-qp-method-val" value="">
      <div style="text-align:center;font-size:11px;color:#aaa;margin-bottom:12px;">
        <svg width="11" height="11" fill="#20b958" viewBox="0 0 24 24" style="vertical-align:middle;"><circle cx="12" cy="12" r="10"/></svg>
        A receipt will be automatically generated after payment.
      </div>
      <button onclick="mdiSubmitQuickPay()" style="width:100%;padding:13px;background:var(--accent,#6c5ce7);color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;transition:background .15s;" onmouseover="this.style.background='#5b4fcf'" onmouseout="this.style.background='var(--accent,#6c5ce7)'">
        &#10003; Collect Payment &amp; Generate Receipt
      </button>
    </div>
  </div>
</div>

<!-- Billing Note Modal -->
<div id="mdi-note-modal" style="display:none;position:fixed;inset:0;z-index:99991;background:rgba(0,0,0,.45);align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,.22);width:420px;max-width:95vw;overflow:hidden;">
    <div style="background:#374151;color:#fff;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;">
      <span style="font-size:13px;font-weight:700;">&#128221; Billing Note</span>
      <button onclick="document.getElementById('mdi-note-modal').style.display='none'" style="background:none;border:none;color:#fff;font-size:20px;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:16px;">
      <p id="mdi-note-enc-label" style="font-size:11px;color:#888;margin-bottom:8px;"></p>
      <textarea id="mdi-note-text" rows="5" style="width:100%;border:2px solid #e0e0e0;border-radius:8px;padding:10px;font-size:13px;resize:vertical;outline:none;" placeholder="Enter billing note..." onfocus="this.style.borderColor='#374151'" onblur="this.style.borderColor='#e0e0e0'"></textarea>
      <input type="hidden" id="mdi-note-enc" value="">
      <input type="hidden" id="mdi-note-pid" value="">
      <div style="display:flex;gap:8px;margin-top:10px;align-items:center;">
        <button id="mdi-note-clear-btn" onclick="mdiClearBillingNote()" style="display:none;padding:8px 14px;border:1.5px solid #ef4444;border-radius:6px;background:#fff;color:#ef4444;cursor:pointer;font-size:13px;font-weight:600;margin-right:auto;">Clear Note</button>
        <button onclick="document.getElementById('mdi-note-modal').style.display='none'" style="padding:8px 18px;border:1.5px solid #ccc;border-radius:6px;background:#fff;cursor:pointer;font-size:13px;">Cancel</button>
        <button onclick="mdiSaveBillingNote()" style="padding:8px 18px;background:#374151;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;">Save Note</button>
      </div>
    </div>
  </div>
</div>

<!-- Shared tooltip anchor (fixed, always on top) -->
<div id="mdi-tip-anchor" style="display:none;position:fixed;z-index:99999;background:#1e1b3a;color:#fff;font-size:11px;line-height:1.6;padding:8px 12px;border-radius:6px;white-space:pre-line;box-shadow:0 4px 18px rgba(0,0,0,.32);pointer-events:none;min-width:180px;max-width:300px;text-align:left;"></div>

<script>
// ── Fixed-position tooltip ─────────────────────────────────────────────────
function mdiShowTip(evt, wrap) {
    var box = wrap.querySelector('.mdi-tipbox');
    if (!box) return;
    var tip = document.getElementById('mdi-tip-anchor');
    if (!tip) return;
    tip.textContent = box.getAttribute('data-tip') || '';
    tip.style.display = 'block';
    // Position after making visible so offsetWidth is correct
    requestAnimationFrame(function() {
        var r = wrap.getBoundingClientRect();
        var tw = tip.offsetWidth, th = tip.offsetHeight;
        var left = r.left + r.width / 2 - tw / 2;
        left = Math.max(4, Math.min(left, window.innerWidth - tw - 4));
        var top = r.top - th - 8;
        if (top < 4) top = r.bottom + 8; // flip below if no room above
        tip.style.left = left + 'px';
        tip.style.top  = top  + 'px';
    });
}
function mdiHideTip() {
    var tip = document.getElementById('mdi-tip-anchor');
    if (tip) tip.style.display = 'none';
}

// ── Quick Patient Payment ──────────────────────────────────────────────────
function mdiOpenQuickPay(pid, amount) {
    document.getElementById('mdi-qp-pid').value = pid;
    document.getElementById('mdi-qp-amount').value = parseFloat(amount || 0).toFixed(2);
    document.getElementById('mdi-qp-method-val').value = '';
    document.getElementById('mdi-qp-ref').value = '';
    document.getElementById('mdi-qp-ref-row').style.display = 'none';
    document.querySelectorAll('#mdi-qp-methods button').forEach(function(b) {
        b.style.borderColor = '#e0e0e0'; b.style.background = '#fff'; b.style.color = '#333';
    });
    var modal = document.getElementById('mdi-quickpay-modal');
    modal.style.display = 'flex';
    setTimeout(function() { document.getElementById('mdi-qp-amount').select(); }, 120);
}
function mdiQPSelectMethod(btn, method) {
    document.querySelectorAll('#mdi-qp-methods button').forEach(function(b) {
        b.style.borderColor = '#e0e0e0'; b.style.background = '#fff'; b.style.color = '#333';
    });
    btn.style.borderColor = 'var(--accent,#6c5ce7)';
    btn.style.background  = '#f0eeff';
    btn.style.color       = 'var(--accent,#6c5ce7)';
    document.getElementById('mdi-qp-method-val').value = method;
    var refRow   = document.getElementById('mdi-qp-ref-row');
    var refLabel = document.getElementById('mdi-qp-ref-label');
    if (method === 'check')      { refRow.style.display = ''; refLabel.textContent = 'CHECK NUMBER'; }
    else if (method === 'cc')    { refRow.style.display = ''; refLabel.textContent = 'CARD LAST 4'; }
    else                         { refRow.style.display = 'none'; }
}
function mdiSubmitQuickPay() {
    var pid    = document.getElementById('mdi-qp-pid').value;
    var amount = parseFloat(document.getElementById('mdi-qp-amount').value);
    var method = document.getElementById('mdi-qp-method-val').value;
    var ref    = document.getElementById('mdi-qp-ref').value;
    var date   = document.getElementById('mdi-qp-date').value;
    if (!method) { alert('Please select a payment method.'); return; }
    if (isNaN(amount) || amount <= 0) { alert('Please enter a valid amount.'); return; }
    if (!date) { alert('Please enter a payment date.'); return; }
    top.restoreSession();
    document.getElementById('mdi-quickpay-modal').style.display = 'none';
    // POST payment to server, then open receipt
    var fd = new FormData();
    fd.append('pid',    pid);
    fd.append('amount', amount.toFixed(2));
    fd.append('method', method);
    fd.append('ref',    ref);
    fd.append('date',   date);
    fd.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_quick_pay.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d && d.ok) {
            window.open('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_checkout_receipt.php?pid=' + encodeURIComponent(pid), 'qp_receipt', 'width=820,height=700,scrollbars=yes');
        } else {
            alert('Payment error: ' + (d && d.error ? d.error : 'Please try again.'));
        }
    }).catch(function() {
        // Fallback: open receipt anyway
        window.open('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_checkout_receipt.php?pid=' + encodeURIComponent(pid), 'qp_receipt', 'width=820,height=700,scrollbars=yes');
    });
}

// ── View Receipt Popup ────────────────────────────────────────────────────
function mdiViewReceiptPopup(pid) {
    top.restoreSession();
    var modal = document.getElementById('mdi-receipt-modal');
    var frame = document.getElementById('mdi-receipt-frame');
    frame.src = '<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_checkout_receipt.php?pid=' + encodeURIComponent(pid);
    modal.style.display = 'flex';
}
function mdiCloseReceiptPopup() {
    var modal = document.getElementById('mdi-receipt-modal');
    var frame = document.getElementById('mdi-receipt-frame');
    modal.style.display = 'none';
    frame.src = '';
}
// Close receipt modal on backdrop click
document.getElementById('mdi-receipt-modal').addEventListener('click', function(e) {
    if (e.target === this) mdiCloseReceiptPopup();
});

// ── Patient General Notes ──────────────────────────────────────────────────
var _mdiPnotesLoaded = false;
function mdiOpenPatientNotes() {
    var modal = document.getElementById('mdi-patient-notes-modal');
    modal.style.display = 'flex';
    if (!_mdiPnotesLoaded) { mdiLoadPatientNotes(); }
}
document.getElementById('mdi-patient-notes-modal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
function mdiLoadPatientNotes() {
    var list = document.getElementById('mdi-pnotes-list');
    list.innerHTML = '<div style="text-align:center;color:#9ca3af;font-size:12px;padding:20px 0;"><i class="fas fa-spinner fa-spin"></i> <?php echo xlt('Loading notes...'); ?></div>';
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_get_pnotes.php?pid=<?php echo attr_url($pid); ?>', {
        credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        _mdiPnotesLoaded = true;
        mdiRenderPnotes(d.notes || []);
        // Update badge count
        var badge = document.getElementById('mdi-notes-badge');
        if (badge) {
            var cnt = (d.notes || []).length;
            badge.textContent = cnt;
            badge.style.background = cnt > 0 ? '#5b21b6' : '#e4ddf5';
            badge.style.color      = cnt > 0 ? '#fff'    : '#9ca3af';
        }
    }).catch(function() {
        list.innerHTML = '<div style="color:#ef4444;font-size:12px;padding:10px;"><?php echo xlt('Could not load notes.'); ?></div>';
    });
}
function mdiRenderPnotes(notes) {
    var list = document.getElementById('mdi-pnotes-list');
    if (!notes.length) {
        list.innerHTML = '<div style="text-align:center;padding:28px 0;"><svg width="40" height="40" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto 10px;"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><div style="color:#9ca3af;font-size:12px;"><?php echo xlt('No notes have been added.'); ?></div></div>';
        return;
    }
    var html = '';
    var _delTitle = '<?php echo addslashes(xlt('Delete note')); ?>';
    notes.forEach(function(n) {
        html += '<div style="border:1px solid #f0ebff;border-radius:8px;padding:10px 12px;margin-bottom:8px;background:#faf8ff;">'
              + '<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px;gap:8px;">'
              + '<span style="font-size:10.5px;font-weight:700;color:#7c3aed;">' + mdiEsc(n.user || '') + '</span>'
              + '<div style="display:flex;align-items:center;gap:6px;flex-shrink:0;">'
              + '<span style="font-size:10px;color:#9ca3af;">' + (n.date || '') + '</span>'
              + '<button onclick="mdiDeleteNote(' + n.id + ')" '
              +   'style="background:none;border:none;cursor:pointer;color:#d1d5db;padding:2px 4px;border-radius:4px;line-height:1;transition:color .12s;" '
              +   'onmouseover="this.style.color=\'#ef4444\'" onmouseout="this.style.color=\'#d1d5db\'" '
              +   'title="' + _delTitle + '">'
              +   '<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>'
              + '</button>'
              + '</div>'
              + '</div>'
              + '<div style="font-size:12.5px;color:#1a1523;white-space:pre-wrap;line-height:1.5;">' + mdiEsc(n.body || '') + '</div>'
              + '</div>';
    });
    list.innerHTML = html;
}
function mdiEsc(str) {
    return (str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function mdiDeleteNote(id) {
    if (!confirm('<?php echo xlt('Delete this note? This cannot be undone.'); ?>')) return;
    var fd = new FormData();
    fd.append('id',              id);
    fd.append('pid',             '<?php echo attr($pid); ?>');
    fd.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_delete_pnote.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d && d.ok) {
            _mdiPnotesLoaded = false;
            mdiLoadPatientNotes();
        } else {
            alert('<?php echo xlt('Error deleting note'); ?>: ' + (d && d.error ? d.error : ''));
        }
    }).catch(function() { alert('<?php echo xlt('Network error.'); ?>'); });
}
function mdiSavePatientNote() {
    var text = document.getElementById('mdi-pnote-new-text').value.trim();
    if (!text) { alert('<?php echo xlt('Please enter a note.'); ?>'); return; }
    var fd = new FormData();
    fd.append('pid',             '<?php echo attr($pid); ?>');
    fd.append('note',            text);
    fd.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_save_pnote.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d && d.ok) {
            document.getElementById('mdi-pnote-new-text').value = '';
            _mdiPnotesLoaded = false;
            mdiLoadPatientNotes();
        } else {
            alert('<?php echo xlt('Error saving note'); ?>: ' + (d && d.error ? d.error : ''));
        }
    }).catch(function() { alert('<?php echo xlt('Network error saving note.'); ?>'); });
}

// ── Billing Note ───────────────────────────────────────────────────────────
// Store notes keyed by encounter — pre-populated from server at page render
var _mdiNotes = <?php
    $mdi_notes_map = [];
    foreach ($enc_rows as $_enr) {
        if (!empty($_enr['mdi_billing_note'])) {
            $mdi_notes_map[(string)$_enr['encounter']] = $_enr['mdi_billing_note'];
        }
    }
    echo json_encode($mdi_notes_map);
?>;

function mdiOpenBillingNote(enc, pid) {
    document.getElementById('mdi-note-enc').value = enc;
    document.getElementById('mdi-note-pid').value = pid;
    document.getElementById('mdi-note-enc-label').textContent = 'Encounter #' + enc;
    // If we already have it in memory, show immediately
    if (_mdiNotes[enc] !== undefined) {
        document.getElementById('mdi-note-text').value = _mdiNotes[enc];
        document.getElementById('mdi-note-clear-btn').style.display = _mdiNotes[enc] ? '' : 'none';
        document.getElementById('mdi-note-modal').style.display = 'flex';
        setTimeout(function() { document.getElementById('mdi-note-text').focus(); }, 120);
        return;
    }
    // Otherwise fetch from server first
    top.restoreSession();
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_get_billing_note.php?pid=' + encodeURIComponent(pid) + '&encounter=' + encodeURIComponent(enc), {
        credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        _mdiNotes[enc] = d && d.note ? d.note : '';
        document.getElementById('mdi-note-text').value = _mdiNotes[enc];
        document.getElementById('mdi-note-clear-btn').style.display = _mdiNotes[enc] ? '' : 'none';
        // Highlight button if note exists
        if (_mdiNotes[enc]) {
            var btn = document.getElementById('mdi-note-btn-' + enc);
            if (btn) {
                btn.style.color = '#f59e0b';
                btn.style.borderColor = '#f59e0b';
                btn.style.background = '#fffbeb';
                btn.title = 'Billing Note (saved) — click to edit';
            }
        }
    }).catch(function() {
        _mdiNotes[enc] = '';
        document.getElementById('mdi-note-text').value = '';
        document.getElementById('mdi-note-clear-btn').style.display = 'none';
    }).finally(function() {
        document.getElementById('mdi-note-modal').style.display = 'flex';
        setTimeout(function() { document.getElementById('mdi-note-text').focus(); }, 120);
    });
}

function mdiClearBillingNote() {
    if (!confirm('Clear this billing note? This cannot be undone.')) return;
    var enc = document.getElementById('mdi-note-enc').value;
    var pid = document.getElementById('mdi-note-pid').value;
    var fd  = new FormData();
    fd.append('pid',             pid);
    fd.append('encounter',       enc);
    fd.append('note',            '');
    fd.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
    top.restoreSession();
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_save_billing_note.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d && d.ok) {
            _mdiNotes[enc] = '';
            var btn = document.getElementById('mdi-note-btn-' + enc);
            if (btn) {
                btn.style.color       = '';
                btn.style.borderColor = '';
                btn.style.background  = '';
                btn.title = '<?php echo xlt("Billing Note"); ?>';
            }
            document.getElementById('mdi-note-modal').style.display = 'none';
            mdiShowToast('Billing note cleared.');
        } else {
            alert('Clear failed: ' + (d && d.error ? d.error : 'Please try again.'));
        }
    }).catch(function() {
        alert('Network error. Please try again.');
    });
}

function mdiSaveBillingNote() {
    var enc  = document.getElementById('mdi-note-enc').value;
    var pid  = document.getElementById('mdi-note-pid').value;
    var note = document.getElementById('mdi-note-text').value.trim();
    if (!note) { alert('Please enter a note.'); return; }

    // Save to the encounter billing note via dedicated endpoint
    var fd = new FormData();
    fd.append('pid',             pid);
    fd.append('encounter',       enc);
    fd.append('note',            note);
    fd.append('csrf_token_form', '<?php echo attr(CsrfUtils::collectCsrfToken()); ?>');
    top.restoreSession();
    fetch('<?php echo $GLOBALS["webroot"]; ?>/interface/patient_file/summary/mdi_save_billing_note.php', {
        method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function(r) { return r.json(); })
    .then(function(d) {
        if (d && d.ok) {
            _mdiNotes[enc] = note;
            var btn = document.getElementById('mdi-note-btn-' + enc);
            if (btn) {
                btn.style.color       = '#f59e0b';
                btn.style.borderColor = '#f59e0b';
                btn.style.background  = '#fffbeb';
                btn.title = 'Billing Note (saved) — click to edit';
            }
            document.getElementById('mdi-note-modal').style.display = 'none';
            mdiShowToast('Billing note saved!');
        } else {
            alert('Save failed: ' + (d && d.error ? d.error : 'Please try again.'));
        }
    }).catch(function() {
        // Fallback: keep in memory, still close
        _mdiNotes[enc] = note;
        var btn = document.getElementById('mdi-note-btn-' + enc);
        if (btn) {
            btn.style.color = '#f59e0b';
            btn.style.borderColor = '#f59e0b';
            btn.style.background = '#fffbeb';
        }
        document.getElementById('mdi-note-modal').style.display = 'none';
        mdiShowToast('Billing note saved locally.');
    });
}
</script>

</body>
<?php $ed->dispatch(new RenderEvent($pid), RenderEvent::EVENT_RENDER_POST_PAGELOAD, 10); ?>

<!-- ── Issue picker overlay ── -->
<div id="mdi-picker-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;">
  <div style="background:#fff;border-radius:10px;border:1px solid #dde3ee;width:100%;max-width:440px;padding:24px;font-family:'Montserrat',sans-serif;box-shadow:0 8px 32px rgba(30,58,95,.18);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
      <span style="font-size:15px;font-weight:600;color:#1a2540;">Select diagnosis to edit</span>
      <button onclick="document.getElementById('mdi-picker-overlay').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:#8a9bb5;line-height:1;">×</button>
    </div>
    <div id="mdi-picker-list"></div>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #edf0f7;">
      <button onclick="document.getElementById('mdi-picker-overlay').style.display='none';mdiOpenDiagnosisEditor('');" style="background:none;border:1px solid #dde3ee;border-radius:6px;padding:7px 14px;font-size:12px;cursor:pointer;color:#4a5a72;font-family:inherit;">+ Add new diagnosis</button>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════════════
     MDItek Custom Diagnosis Editor Modal
     Intercepts the Edit button on any medical issue / diagnosis row
     in stats.php and provides an inline editor backed by issue_save.php
     ══════════════════════════════════════════════════════════════ -->
<div id="mdi-diag-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;">
  <div id="mdi-diag-modal" style="background:#fff;border-radius:10px;border:1px solid #dde3ee;width:100%;max-width:540px;padding:24px;position:relative;box-shadow:0 8px 32px rgba(30,58,95,.18);font-family:'Montserrat',sans-serif;max-height:calc(100vh - 40px);overflow-y:auto;margin:auto;">

    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
      <span id="mdi-diag-modal-title" style="font-size:15px;font-weight:600;color:#1a2540;">Edit diagnosis</span>
      <button onclick="mdiCloseDiagEditor()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#8a9bb5;line-height:1;padding:0 4px;">×</button>
    </div>

    <input type="hidden" id="mdi-diag-id">
    <input type="hidden" id="mdi-current-pid" value="<?php echo attr($pid); ?>">

    <div style="margin-bottom:12px;">
      <label style="font-size:12px;color:#4a5a72;display:block;margin-bottom:4px;">Title</label>
      <input id="mdi-diag-title" type="text" placeholder="e.g. Spinal Stenosis"
             style="width:100%;padding:8px 10px;border:1px solid #dde3ee;border-radius:6px;font-size:13px;font-family:inherit;">
    </div>

    <div style="display:flex;gap:12px;margin-bottom:12px;">
      <div style="flex:1;">
        <label style="font-size:12px;color:#4a5a72;display:block;margin-bottom:4px;">Status</label>
        <select id="mdi-diag-status" style="width:100%;padding:8px 10px;border:1px solid #dde3ee;border-radius:6px;font-size:13px;font-family:inherit;">
          <option value="1">Active</option>
          <option value="0">Inactive</option>
        </select>
      </div>
      <div style="flex:1;">
        <label style="font-size:12px;color:#4a5a72;display:block;margin-bottom:4px;">Verification</label>
        <select id="mdi-diag-verification" style="width:100%;padding:8px 10px;border:1px solid #dde3ee;border-radius:6px;font-size:13px;font-family:inherit;">
          <option value="confirmed">Confirmed</option>
          <option value="unconfirmed">Unconfirmed</option>
          <option value="provisional">Provisional</option>
        </select>
      </div>
    </div>

    <div style="margin-bottom:12px;">
      <label style="font-size:12px;color:#4a5a72;display:block;margin-bottom:4px;">
        ICD-10 Codes
        <span style="color:#8a9bb5;font-size:11px;">— search and select from the list</span>
      </label>
      <div id="mdi-diag-codes-area" style="display:flex;flex-direction:column;gap:6px;"></div>

      <!-- ICD-10 search picker -->
      <div style="margin-top:8px;position:relative;" id="mdi-icd-search-wrap">
        <div style="display:flex;gap:6px;align-items:center;">
          <input id="mdi-icd-search-input" type="text" placeholder="Type to search ICD-10 codes…"
                 autocomplete="off"
                 style="flex:1;padding:7px 10px;border:1px solid #c8d5e5;border-radius:6px;font-size:13px;font-family:inherit;"
                 oninput="mdiIcdSearch(this.value)"
                 onkeydown="mdiIcdKeydown(event)">
          <button onclick="mdiIcdClearSearch()" type="button"
                  style="background:none;border:1px dashed #c8d5e5;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;color:#4a5a72;font-family:inherit;white-space:nowrap;">
            ✕ Clear
          </button>
        </div>
        <!-- Dropdown results -->
        <div id="mdi-icd-dropdown"
             style="display:none;position:absolute;top:calc(100% + 2px);left:0;right:0;
                    background:#fff;border:1px solid #c8d5e5;border-radius:6px;
                    box-shadow:0 4px 16px rgba(30,58,95,.13);z-index:99999;
                    max-height:220px;overflow-y:auto;font-family:inherit;">
        </div>
      </div>
    </div>

    <div style="display:flex;gap:12px;margin-bottom:12px;">
      <div style="flex:1;">
        <label style="font-size:12px;color:#4a5a72;display:block;margin-bottom:4px;">Begin date</label>
        <input id="mdi-diag-begin" type="date" style="width:100%;padding:8px 10px;border:1px solid #dde3ee;border-radius:6px;font-size:13px;font-family:inherit;">
      </div>
      <div style="flex:1;">
        <label style="font-size:12px;color:#4a5a72;display:block;margin-bottom:4px;">End date <span style="color:#8a9bb5;">(blank if active)</span></label>
        <input id="mdi-diag-end" type="date" style="width:100%;padding:8px 10px;border:1px solid #dde3ee;border-radius:6px;font-size:13px;font-family:inherit;">
      </div>
    </div>

    <div style="margin-bottom:18px;">
      <label style="font-size:12px;color:#4a5a72;display:block;margin-bottom:4px;">Comments</label>
      <input id="mdi-diag-comments" type="text" placeholder="Optional notes..."
             style="width:100%;padding:8px 10px;border:1px solid #dde3ee;border-radius:6px;font-size:13px;font-family:inherit;">
    </div>

    <div id="mdi-diag-error" style="display:none;color:#b91c1c;font-size:12px;margin-bottom:10px;"></div>

    <div style="display:flex;justify-content:flex-end;gap:10px;padding-top:14px;border-top:1px solid #edf0f7;">
      <button onclick="mdiCloseDiagEditor()" style="background:transparent;border:1px solid #dde3ee;border-radius:6px;padding:8px 16px;font-size:13px;cursor:pointer;font-family:inherit;color:#1a2540;">Cancel</button>
      <button onclick="mdiSaveDiagnosis()" id="mdi-diag-save-btn" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff;border:none;border-radius:6px;padding:8px 20px;font-size:13px;cursor:pointer;font-weight:600;font-family:inherit;">Save</button>
    </div>

  </div>
</div>

<script>
(function() {

    // ── Read the current patient pid dynamically ───────────────────────
    // OpenEMR stores the active pid in several places. We try each in order:
    //   1. URL param (set_pid or pid) on the current page
    //   2. The hidden input #mdi-current-pid we stamp in the page
    //   3. top.left_nav.getPid() if the nav frame exposes it
    window.mdiGetCurrentPid = function() {
        // 1. URL param
        var urlParams = new URLSearchParams(window.location.search);
        var pid = urlParams.get('set_pid') || urlParams.get('pid');
        if (pid) return pid;
        // 2. Hidden input stamped by PHP at page load (still valid for initial load)
        var el = document.getElementById('mdi-current-pid');
        if (el && el.value) return el.value;
        // 3. Left nav frame
        try {
            var navPid = top.left_nav && top.left_nav.getPid ? top.left_nav.getPid() : null;
            if (navPid) return navPid;
        } catch(e) {}
        // 4. Fall back to the pid baked in at page-load time
        return <?php echo js_escape($pid); ?>;
    };
    window.mdiOpenDiagnosisEditor = function(issueId) {
        var overlay = document.getElementById('mdi-diag-overlay');
        document.getElementById('mdi-diag-error').style.display = 'none';
        document.getElementById('mdi-diag-id').value = issueId || '';

        if (issueId) {
            document.getElementById('mdi-diag-modal-title').textContent = 'Edit diagnosis';
            // Fetch existing issue data via OpenEMR's API shim
            top.restoreSession();
            fetch('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_get_issue.php?id=' + encodeURIComponent(issueId) + '&pid=' + encodeURIComponent(mdiGetCurrentPid()), {
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                document.getElementById('mdi-diag-title').value        = d.title        || '';
                document.getElementById('mdi-diag-status').value       = d.activity != null ? String(d.activity) : '1';
                document.getElementById('mdi-diag-verification').value = d.verification || 'confirmed';
                document.getElementById('mdi-diag-begin').value        = d.begdate      || '';
                document.getElementById('mdi-diag-end').value          = d.enddate      || '';
                document.getElementById('mdi-diag-comments').value     = d.comments     || '';
                mdiRenderCodeRows(mdiParseDiagnosisCodes(d.diagnosis || ''));
            })
            .catch(function() {
                // Fallback: open with blank fields pre-filled with the issue id
                document.getElementById('mdi-diag-title').value = '';
                mdiRenderCodeRows(['']);
            });
        } else {
            document.getElementById('mdi-diag-modal-title').textContent = 'Add diagnosis';
            document.getElementById('mdi-diag-title').value        = '';
            document.getElementById('mdi-diag-status').value       = '1';
            document.getElementById('mdi-diag-verification').value = 'confirmed';
            document.getElementById('mdi-diag-begin').value        = '';
            document.getElementById('mdi-diag-end').value          = '';
            document.getElementById('mdi-diag-comments').value     = '';
            mdiRenderCodeRows(['']);
        }

        overlay.style.display = 'flex';
    };

    window.mdiCloseDiagEditor = function() {
        document.getElementById('mdi-diag-overlay').style.display = 'none';
    };

    // ── Render ICD code input rows ────────────────────────────────────
    // ── Parse OpenEMR diagnosis string into display codes ─────────────
    // DB format: "ICD10:M542:0;ICD10:S13160A:0" or "ICD10:M542;ICD10:S13160A"
    // Display format: ["ICD10:M542", "ICD10:S13160A"]
    window.mdiParseDiagnosisCodes = function(diagStr) {
        if (!diagStr) return [''];
        return diagStr.split(';').map(function(entry) {
            entry = entry.trim();
            // OpenEMR DB format is either:
            //   "ICD10:M542:0"  (type:code:qualifier) — strip trailing :0 or :1
            //   "ICD10:M542"    (type:code)           — use as-is
            // Split into parts and reconstruct as TYPE:CODE only
            var parts = entry.split(':');
            if (parts.length >= 3) {
                // Check if last part is a short qualifier (0, 1, etc.) not part of the code
                var last = parts[parts.length - 1];
                if (/^\d{1,2}$/.test(last)) {
                    // Strip the trailing qualifier
                    return parts.slice(0, parts.length - 1).join(':');
                }
            }
            return entry;
        }).filter(Boolean);
    };

    // ── Serialize display codes back to OpenEMR DB format ─────────────
    window.mdiSerializeDiagnosisCodes = function(codesArray) {
        return codesArray.filter(Boolean).map(function(c) {
            c = c.trim();
            var parts = c.split(':');
            // Already has qualifier (3+ parts with short last segment) — leave as-is
            if (parts.length >= 3 && /^\d{1,2}$/.test(parts[parts.length - 1])) {
                return c;
            }
            // Add :0 qualifier
            return c + ':0';
        }).join(';');
    };

    window.mdiRenderCodeRows = function(codes) {
        var area = document.getElementById('mdi-diag-codes-area');
        area.innerHTML = '';
        if (!codes || !codes.length) return;
        codes.forEach(function(c) {
            if (c) mdiAppendCodeTag(c);
        });
    };

    // ── Append a selected code as a removable pill/tag ────────────────
    window.mdiAppendCodeTag = function(codeVal, label) {
        var area = document.getElementById('mdi-diag-codes-area');
        // Avoid duplicates
        var existing = Array.from(area.querySelectorAll('[data-code]')).map(function(el){ return el.dataset.code; });
        if (existing.indexOf(codeVal) !== -1) return;

        var tag = document.createElement('div');
        tag.dataset.code = codeVal;
        tag.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:#eef2ff;'
            + 'border:1px solid #c7d2fe;border-radius:20px;padding:4px 10px;font-size:12px;'
            + 'color:#1a2540;font-family:monospace;margin:2px;width:fit-content;';
        var displayText = label ? codeVal + ' — ' + label : codeVal;
        tag.innerHTML = '<span>' + displayText + '</span>'
            + '<button type="button" onclick="this.parentElement.remove()" '
            + 'style="background:none;border:none;color:#7c3aed;font-size:14px;cursor:pointer;'
            + 'padding:0;line-height:1;font-weight:700;" title="Remove">×</button>';
        area.appendChild(tag);
    };

    // Legacy: still used when loading existing codes from DB
    window.mdiAppendCodeRow = window.mdiAppendCodeTag;
    window.mdiAddCodeRow = function() { /* no-op — use search instead */ };

    // ── ICD-10 search ─────────────────────────────────────────────────
    var _mdiIcdTimer = null;
    var _mdiIcdSelected = -1;

    window.mdiIcdSearch = function(term) {
        clearTimeout(_mdiIcdTimer);
        var dd = document.getElementById('mdi-icd-dropdown');
        if (!term || term.length < 2) { dd.style.display = 'none'; dd.innerHTML = ''; return; }
        _mdiIcdTimer = setTimeout(function() {
            dd.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:#8a9bb5;">Searching…</div>';
            dd.style.display = 'block';
            top.restoreSession();

            var csrf = document.getElementById('mdi_csrf_token') ? document.getElementById('mdi_csrf_token').value : '';
            var url  = '<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_icd_search.php'
                     + '?term=' + encodeURIComponent(term)
                     + '&limit=25'
                     + '&csrf_token_form=' + encodeURIComponent(csrf);

            fetch(url, { credentials: 'same-origin' })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text();
            })
            .then(function(rawText) {
                console.log('ICD raw response:', rawText.substring(0,500));
                var data;
                try { data = JSON.parse(rawText); }
                catch(pe) {
                    mdiIcdShowManual('PHP output error: ' + rawText.substring(0,300));
                    return;
                }
                if (data && data.error) {
                    mdiIcdShowManual('DB error: ' + data.error);
                } else {
                    mdiIcdRenderResults(data, rawText);
                }
            })
            .catch(function(err) {
                console.warn('ICD search error:', err);
                mdiIcdShowManual('Fetch failed: ' + err.message);
            });
        }, 300);
    };

    window.mdiIcdShowManual = function(msg) {
        var dd = document.getElementById('mdi-icd-dropdown');
        dd.innerHTML = '<div style="padding:8px 12px 4px;font-size:11px;color:#b91c1c;">' + msg + '</div>'
            + '<div style="padding:4px 12px 10px;display:flex;gap:6px;">'
            + '<input id="mdi-icd-manual" type="text" placeholder="e.g. M542" '
            + 'style="flex:1;padding:6px 8px;border:1px solid #dde3ee;border-radius:6px;font-size:12px;font-family:monospace;"'
            + ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();mdiIcdAddManual();}">'
            + '<button onclick="mdiIcdAddManual()" type="button" '
            + 'style="background:#5b21b6;color:#fff;border:none;border-radius:6px;'
            + 'padding:5px 12px;font-size:12px;cursor:pointer;font-family:inherit;white-space:nowrap;">+ Add</button></div>';
        dd.style.display = 'block';
    };

    window.mdiIcdRenderResults = function(data, term) {
        var dd = document.getElementById('mdi-icd-dropdown');
        _mdiIcdSelected = -1;
        if (!data || !data.length) {
            dd.style.display = 'none';
            dd.innerHTML = '';
            return;
        }
        var html = '';
        data.forEach(function(item, idx) {
            // OpenEMR returns: {code, code_text} or {code, label} depending on version
            var code  = item.code  || item.id    || '';
            var label = item.code_text || item.label || item.description || '';
            // Ensure ICD10: prefix
            var fullCode = code.indexOf('ICD10:') === 0 ? code : 'ICD10:' + code;
            html += '<div class="mdi-icd-item" data-code="' + fullCode + '" data-label="' + label.replace(/"/g,'&quot;') + '"'
                + ' onclick="mdiIcdSelect(this)"'
                + ' onmouseenter="mdiIcdHover(this)"'
                + ' style="padding:8px 12px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f2f7;display:flex;gap:8px;align-items:baseline;">'
                + '<span style="font-family:monospace;color:#5b21b6;font-weight:600;white-space:nowrap;">' + fullCode + '</span>'
                + '<span style="color:#1a2540;flex:1;">' + label + '</span>'
                + '</div>';
        });
        dd.innerHTML = html;
        dd.style.display = 'block';
    };

    window.mdiIcdSelect = function(el) {
        var code  = el.dataset.code;
        var label = el.dataset.label;
        mdiAppendCodeTag(code, label);
        // Clear search
        document.getElementById('mdi-icd-search-input').value = '';
        var dd = document.getElementById('mdi-icd-dropdown');
        dd.style.display = 'none';
        dd.innerHTML = '';
        _mdiIcdSelected = -1;
    };

    window.mdiIcdHover = function(el) {
        var items = document.querySelectorAll('.mdi-icd-item');
        items.forEach(function(i, idx) {
            i.style.background = i === el ? '#f5f3ff' : '';
            if (i === el) _mdiIcdSelected = idx;
        });
    };

    window.mdiIcdKeydown = function(e) {
        var dd    = document.getElementById('mdi-icd-dropdown');
        var items = Array.from(dd.querySelectorAll('.mdi-icd-item'));
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            _mdiIcdSelected = Math.min(_mdiIcdSelected + 1, items.length - 1);
            items.forEach(function(i, idx) { i.style.background = idx === _mdiIcdSelected ? '#f5f3ff' : ''; });
            items[_mdiIcdSelected].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            _mdiIcdSelected = Math.max(_mdiIcdSelected - 1, 0);
            items.forEach(function(i, idx) { i.style.background = idx === _mdiIcdSelected ? '#f5f3ff' : ''; });
            items[_mdiIcdSelected].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter' && _mdiIcdSelected >= 0) {
            e.preventDefault();
            mdiIcdSelect(items[_mdiIcdSelected]);
        } else if (e.key === 'Escape') {
            dd.style.display = 'none'; dd.innerHTML = ''; _mdiIcdSelected = -1;
        }
    };

    window.mdiIcdClearSearch = function() {
        document.getElementById('mdi-icd-search-input').value = '';
        var dd = document.getElementById('mdi-icd-dropdown');
        dd.style.display = 'none'; dd.innerHTML = '';
        _mdiIcdSelected = -1;
    };

    window.mdiIcdAddManual = function() {
        var inp = document.getElementById('mdi-icd-manual');
        if (!inp || !inp.value.trim()) return;
        var raw = inp.value.trim().toUpperCase();
        var full = raw.indexOf('ICD10:') === 0 ? raw : 'ICD10:' + raw;
        mdiAppendCodeTag(full, '');
        mdiIcdClearSearch();
    };

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        var wrap = document.getElementById('mdi-icd-search-wrap');
        var dd   = document.getElementById('mdi-icd-dropdown');
        if (wrap && dd && !wrap.contains(e.target)) {
            dd.style.display = 'none';
        }
    });

    // ── Save via OpenEMR's issue_save endpoint ────────────────────────
    window.mdiSaveDiagnosis = function() {
        var btn = document.getElementById('mdi-diag-save-btn');
        btn.disabled = true;
        btn.textContent = 'Saving…';

        var issueId   = document.getElementById('mdi-diag-id').value;
        var title     = document.getElementById('mdi-diag-title').value.trim();
        var activity  = document.getElementById('mdi-diag-status').value;
        var verif     = document.getElementById('mdi-diag-verification').value;
        var begdate   = document.getElementById('mdi-diag-begin').value;
        var enddate   = document.getElementById('mdi-diag-end').value;
        var comments  = document.getElementById('mdi-diag-comments').value;

        // Collect codes from pill tags (data-code attribute)
        var codeTags = document.querySelectorAll('#mdi-diag-codes-area [data-code]');
        var rawCodes = Array.from(codeTags).map(function(el){ return el.dataset.code.trim(); }).filter(Boolean);
        var codes = mdiSerializeDiagnosisCodes(rawCodes);

        var body = new URLSearchParams();
        body.append('csrf_token_form', document.getElementById('mdi_csrf_token') ? document.getElementById('mdi_csrf_token').value : '');
        body.append('pid',         mdiGetCurrentPid());
        body.append('issue',       issueId);
        body.append('type',        'medical_problem');
        body.append('title',       title || 'Default Diagnosis');
        body.append('activity',    activity);
        body.append('verification',verif);
        body.append('begdate',     begdate);
        body.append('enddate',     enddate);
        body.append('comments',    comments);
        body.append('diagnosis',   codes);

        top.restoreSession();
        fetch('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_save_issue.php', {
            method: 'POST',
            credentials: 'same-origin',
            body: body
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            btn.disabled = false;
            btn.textContent = 'Save';
            if (d.success) {
                mdiCloseDiagEditor();
                mdiShowToast('Diagnosis saved successfully!');
                // Reload the stats widget and refresh the in-memory issue list
                placeHtml('stats.php', 'stats_div', true);
                mdiRefreshIssueList();
            } else {
                var errEl = document.getElementById('mdi-diag-error');
                errEl.textContent = d.error || 'Save failed. Please try again.';
                errEl.style.display = 'block';
            }
        })
        .catch(function() {
            btn.disabled = false;
            btn.textContent = 'Save';
            var errEl = document.getElementById('mdi-diag-error');
            errEl.textContent = 'Network error. Please try again.';
            errEl.style.display = 'block';
        });
    };

    // ── Refresh the in-memory issue list after add/save ───────────────
    window.mdiRefreshIssueList = function() {
        var pid = mdiGetCurrentPid();
        top.restoreSession();
        fetch('<?php echo $GLOBALS['webroot']; ?>/interface/patient_file/summary/mdi_list_issues.php?pid=' + encodeURIComponent(pid), {
            credentials: 'same-origin'
        })
        .then(function(r) { return r.json(); })
        .then(function(issues) {
            if (Array.isArray(issues)) {
                window._mdiIssues   = issues;
                window._mdiIssueIds = issues.map(function(i) { return i.id; });
            }
        })
        .catch(function() {});
    };

    // Close overlay when clicking outside the modal
    document.getElementById('mdi-diag-overlay').addEventListener('click', function(e) {
        if (e.target === this) mdiCloseDiagEditor();
    });

    // ── Issue picker ───────────────────────────────────────────────────
    window.mdiShowIssuePicker = function(ids) {
        var issues = (window._mdiIssues || []).filter(function(i) {
            return ids.indexOf(i.id) !== -1;
        });
        var overlay = document.getElementById('mdi-picker-overlay');
        var list    = document.getElementById('mdi-picker-list');
        list.innerHTML = '';
        issues.forEach(function(issue) {
            var btn = document.createElement('button');
            btn.style.cssText = 'display:block;width:100%;text-align:left;padding:10px 14px;margin-bottom:6px;'
                + 'background:#f8f8fc;border:1px solid #dde3ee;border-radius:6px;cursor:pointer;'
                + 'font-family:inherit;font-size:13px;color:#1a2540;';
            btn.textContent = issue.title || 'Untitled diagnosis';
            btn.onclick = function() {
                document.getElementById('mdi-picker-overlay').style.display = 'none';
                mdiOpenDiagnosisEditor(issue.id);
            };
            list.appendChild(btn);
        });
        overlay.style.display = 'flex';
    };

    document.getElementById('mdi-picker-overlay').addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });

})();

function mdiOpenModal(url, title) {
    document.getElementById('mdiModalTitle').innerText = title;
    document.getElementById('mdiModalFrame').src = url;
    document.getElementById('mdiModalOverlay').style.display = 'block';
}

function mdiCloseModal() {
    document.getElementById('mdiModalOverlay').style.display = 'none';
    document.getElementById('mdiModalFrame').src = '';
    location.reload(); // optional (remove if you don’t want reload)
}
function mdiShowToast(message) {
    var toast = document.getElementById('mdi-save-toast');
    if (!toast) return;
    toast.querySelector('.mdi-toast-msg').textContent = message;
    toast.style.opacity = '1';
    toast.style.transform = 'translateX(-50%) translateY(0)';
    clearTimeout(toast._hideTimer);
    toast._hideTimer = setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(-50%) translateY(12px)';
    }, 3000);
}
</script>

<div id="mdi-save-toast" style="
    position:fixed; bottom:28px; left:50%; transform:translateX(-50%) translateY(12px);
    background:#1e1e2e; color:#fff; padding:10px 22px; border-radius:8px;
    font-family:'Montserrat',sans-serif; font-size:13px; font-weight:600;
    box-shadow:0 4px 18px rgba(0,0,0,0.22); z-index:99999;
    opacity:0; transition:opacity 0.3s ease, transform 0.3s ease;
    pointer-events:none; white-space:nowrap; display:flex; align-items:center; gap:8px;
">
    <i class="fas fa-check-circle" style="color:#6ee7b7;"></i>
    <span class="mdi-toast-msg">Diagnosis saved successfully!</span>
</div>
<!-- ── end MDItek Custom Diagnosis Editor ── -->

</html>
