<?php
// Buffer all output from globals.php so the refresh endpoint can
// discard it and return clean JSON without header() conflicts.
ob_start();

/**
 * main.php
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Kevin Yeh <kevin.y@integralemr.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Ranganath Pathak <pathak@scrs1.org>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2016 Kevin Yeh <kevin.y@integralemr.com>
 * @copyright Copyright (c) 2016-2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Ranganath Pathak <pathak@scrs1.org>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once(__DIR__ . '/../../globals.php');
require_once $GLOBALS['srcdir'] . '/ESign/Api.php';

use Esign\Api;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;
use OpenEMR\Events\Main\Tabs\RenderEvent;
use OpenEMR\Services\LogoService;

$logoService = new LogoService();
$menuLogo = $logoService->getLogo('core/menu/primary/');

// Kept in sync with mdi_visits.php's "Unsigned" tab: an encounter counts
// as unsigned only if it actually has a SOAP note AND has no lock in
// esign_signatures (native eSign) or mdi_signatures (PIN-based Sign
// Note/Sign Encounter). No date-range cap — matches mdi_visits.php's own
// default (all time) filter, since both the dashboard card and the
// "Action Required" row link straight to that same default view.
// Defined unconditionally (not inside the AJAX-refresh branch below) so
// it's available both there and in the normal full-page render further
// down.
function mdi_count_unsigned_encounters() {
    try {
        $r = sqlQuery(
            "SELECT COUNT(*) AS cnt FROM (
                SELECT fe.encounter, fe.pid
                FROM form_encounter fe
                LEFT JOIN esign_signatures es
                    ON es.tid = fe.encounter AND es.`table` = 'form_encounter'
                LEFT JOIN mdi_signatures ms
                    ON ms.encounter = fe.encounter AND ms.pid = fe.pid
                WHERE EXISTS (
                    SELECT 1 FROM forms sf
                     WHERE sf.encounter  = fe.encounter
                       AND sf.pid        = fe.pid
                       AND sf.form_name LIKE 'SOAP%'
                       AND sf.deleted    = 0
                )
                GROUP BY fe.encounter, fe.pid
                HAVING SUM(CASE WHEN es.id IS NOT NULL OR ms.id IS NOT NULL THEN 1 ELSE 0 END) = 0
            ) AS _u",
            []
        );
        return (int)($r['cnt'] ?? 0);
    } catch (Throwable $e) {
        error_log("MDI unsigned count: " . $e->getMessage());
        return 0;
    }
}

// ── Dashboard auto-refresh endpoint ─────────────────────────────────────────
// Must be BEFORE the token_main check. Uses session authUser for auth.
// JS fetches this URL using the restoreSession keepalive URL, not main.php URL.
if (!empty($_GET['bm_dash_refresh'])) {
    if (empty($_SESSION['authUser'])) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        echo '{}'; exit;
    }
    // Discard anything globals.php may have buffered/output
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    try {
        $today = date('Y-m-d');
        $authU = $_SESSION['authUser'] ?? '';
        $pt = sqlQuery(
            "SELECT 
                COALESCE(SUM(a.pay_amount),0) AS amt,
                COUNT(DISTINCT a.session_id) AS cnt
             FROM ar_activity a
             JOIN ar_session s ON s.session_id = a.session_id
             WHERE DATE(a.post_time) = ?
               AND a.deleted IS NULL
               AND a.pay_amount > 0
               AND a.account_code NOT IN ('WO','AJ','TRANS','RFND','PR')
               AND LOWER(COALESCE(s.payment_method,'')) IN (
                    'patient_payment',
                    'insurance_payment',
                    'cash',
                    'check',
                    'credit_card',
                    'eft',
                    'ach',
                    'money_order'
               )",
            [$today]
        );
        $pm = sqlQuery(
            "SELECT COALESCE(SUM(a.pay_amount),0) AS total
             FROM ar_activity a
             JOIN ar_session s ON s.session_id = a.session_id
             WHERE a.deleted IS NULL
               AND a.post_time >= ?
               AND a.post_time <= ?
               AND a.pay_amount > 0
               AND a.account_code NOT IN ('WO','AJ','TRANS','RFND','PR')
               AND LOWER(COALESCE(s.payment_method,'')) IN (
                    'patient_payment',
                    'insurance_payment',
                    'cash',
                    'check',
                    'credit_card',
                    'eft',
                    'ach',
                    'money_order'
               )",
            [date('Y-m-01') . ' 00:00:00', date('Y-m-t') . ' 23:59:59']
        );
        $ub = sqlQuery(
            "SELECT 
                COALESCE(SUM(fee),0) AS amt,
                COUNT(DISTINCT CONCAT(pid,'-',encounter)) AS enc
             FROM billing
             WHERE billed = 0
               AND activity = 1
               AND fee > 0
               AND code_type != 'COPAY'",
            []
        );
        // Action Required counts
        $ac = [];
        $ac['unsigned'] = mdi_count_unsigned_encounters();
        $r = sqlQuery("SELECT COUNT(*) AS c FROM pnotes WHERE assigned_to=? AND deleted!=1 AND message_status IN ('New','flagged')", [$authU]);
        $ac['msgs'] = (int)($r['c'] ?? 0);
        $r = sqlQuery("SELECT COUNT(DISTINCT encounter) AS c FROM billing WHERE billed=0 AND bill_process=0 AND activity=1", []);
        $ac['claims_send'] = (int)($r['c'] ?? 0);
        $r = sqlQuery("SELECT COUNT(*) AS c FROM claims WHERE status=4", []);
        $ac['claims_rej'] = (int)($r['c'] ?? 0);
        $r = sqlQuery("SELECT COUNT(*) AS c FROM openemr_postcalendar_events WHERE pc_apptstatus='^' AND DATE(pc_eventDate)>=CURDATE() AND pc_pid>0", []);
        $ac['appts'] = (int)($r['c'] ?? 0);
        $ac['recalls'] = 0;
        if (function_exists('tableExists') && tableExists('medex_recalls')) {
            $r = sqlQuery("SELECT COUNT(*) AS c FROM medex_recalls WHERE r_eventDate<=CURDATE()", []);
            $ac['recalls'] = (int)($r['c'] ?? 0);
        }
        $r = sqlQuery("SELECT COUNT(*) AS c FROM billing WHERE billed=0 AND activity=1 AND bill_process=0", []);
        $ac['docs'] = 0; // portal docs via KO observable — leave as 0 here
        $r = sqlQuery("SELECT COUNT(*) AS c FROM openemr_postcalendar_events WHERE pc_apptstatus='^' AND pc_pid>0 AND DATE(pc_eventDate)>=CURDATE()", []);
        // pnp = unapplied payments (same as paymentsNotPosted card)
        // Calculated via ar_session subquery later; placeholder here
        $ac['pnp'] = 0;
        $r = sqlQuery("SELECT COUNT(DISTINCT fe.encounter) AS c FROM form_encounter fe INNER JOIN billing b ON b.encounter=fe.encounter AND b.pid=fe.pid AND b.activity=1 AND b.billed=0 WHERE fe.date<DATE_SUB(CURDATE(),INTERVAL 7 DAY)", []);
        $ac['unbilled_old'] = (int)($r['c'] ?? 0);
        $r = sqlQuery(
"SELECT COUNT(*) AS cnt FROM (
                    SELECT b.pid,
                        SUM(b.fee)
                        - COALESCE(SUM(CASE
                            WHEN (aa.payer_type=1 OR COALESCE(s.payer_id,0)>0
                                  OR LOWER(COALESCE(s.payment_type,'')) LIKE '%insurance%'
                                  OR LOWER(COALESCE(s.payment_method,'')) LIKE '%insurance%')
                                 AND UPPER(COALESCE(aa.account_code,'')) NOT IN ('WO','TRANS','PR')
                            THEN aa.pay_amount + ABS(aa.adj_amount) ELSE 0 END),0)
                        - COALESCE(SUM(CASE
                            WHEN UPPER(COALESCE(aa.account_code,'')) = 'WO'
                                 OR UPPER(COALESCE(aa.memo,'')) LIKE '%CONTRACT%'
                                 OR UPPER(COALESCE(aa.memo,'')) LIKE '%BAD_DEBT%'
                            THEN ABS(aa.adj_amount) + aa.pay_amount ELSE 0 END),0)
                        - COALESCE(SUM(CASE
                            WHEN UPPER(COALESCE(aa.account_code,'')) = 'AJ'
                                 AND (aa.payer_type=1 OR COALESCE(s.payer_id,0)>0)
                            THEN ABS(aa.adj_amount) ELSE 0 END),0)
                        - COALESCE(SUM(CASE
                            WHEN UPPER(COALESCE(aa.account_code,'')) = 'TRANS' AND aa.payer_type=1
                            THEN ABS(aa.adj_amount) + aa.pay_amount ELSE 0 END),0)
                        + COALESCE(SUM(CASE
                            WHEN UPPER(COALESCE(aa.account_code,'')) = 'TRANS' AND aa.payer_type=0
                            THEN ABS(aa.adj_amount) + aa.pay_amount ELSE 0 END),0)
                        AS insurance_balance
                    FROM billing b
                    LEFT JOIN ar_activity aa ON aa.pid=b.pid AND aa.encounter=b.encounter AND aa.deleted IS NULL
                    LEFT JOIN ar_session s ON s.session_id=aa.session_id
                    WHERE b.billed=1 AND b.activity=1
                      AND b.bill_date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                      
                      AND (b.code_text IS NULL OR b.code_text NOT LIKE '%LEGACY%')
                    GROUP BY b.pid
                    HAVING insurance_balance > 0.01
                ) _aging", []);
        $ac['aging'] = (int)($r['cnt'] ?? 0);
        // Today's payment rows for popup
        $ptRows = [];
        $ptDetail = sqlStatement("SELECT a.pid, a.pay_amount, a.post_time, a.memo, CONCAT(pd.lname,', ',pd.fname) AS patient, s.reference AS ref FROM ar_activity a LEFT JOIN patient_data pd ON pd.pid=a.pid LEFT JOIN ar_session s ON s.session_id=a.session_id WHERE DATE(a.post_time)=? AND a.deleted IS NULL AND a.pay_amount>0 ORDER BY a.post_time DESC LIMIT 50", [$today]);
        while ($row = sqlFetchArray($ptDetail)) { $ptRows[] = ['pat'=>$row['patient'],'amt'=>(float)$row['pay_amount'],'ref'=>$row['ref']??'','time'=>substr($row['post_time'],11,5)]; }
        // Today's schedule (lightweight — time + patient name + status)
        $schedRows = [];
        try {
            $sRes = sqlStatement("SELECT e.pc_startTime, e.pc_apptstatus, e.pc_duration, CONCAT(p.fname,' ',p.lname) AS patient, e.pc_catname FROM openemr_postcalendar_events e LEFT JOIN patient_data p ON p.pid=e.pc_pid WHERE DATE(e.pc_eventDate)=? AND e.pc_pid>0 ORDER BY e.pc_startTime ASC LIMIT 30", [$today]);
            while ($sr = sqlFetchArray($sRes)) { $schedRows[] = ['time'=>$sr['pc_startTime'],'status'=>$sr['pc_apptstatus'],'patient'=>$sr['patient'],'cat'=>$sr['pc_catname'],'dur'=>(int)(($sr['pc_duration']??0)/60)]; }
        } catch (Throwable $ex) {}
        echo json_encode([
            'paid_today'       => (float)($pt['amt']   ?? 0),
            'paid_today_count' => (int)  ($pt['cnt']   ?? 0),
            'paid_month'       => (float)($pm['total'] ?? 0),
            'unbilled_amt'     => (float)($ub['amt']   ?? 0),
            'unbilled_enc'     => (int)  ($ub['enc']   ?? 0),
            'action'           => $ac,
            'pt_rows'          => $ptRows,
            'schedule'         => $schedRows,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// Ensure token_main matches so this script can not be run by itself
//  If do not match, then destroy the session and go back to login screen
if (
    (empty($_SESSION['token_main_php'])) ||
    (empty($_GET['token_main'])) ||
    ($_GET['token_main'] != $_SESSION['token_main_php'])
) {
    // Below functions are from auth.inc, which is included in globals.php
    authCloseSession();
    authLoginScreen(false);
}
// this will not allow copy/paste of the link to this main.php page or a refresh of this main.php page
//  (default behavior, however, this behavior can be turned off in the prevent_browser_refresh global)
if ($GLOBALS['prevent_browser_refresh'] > 1) {
    unset($_SESSION['token_main_php']);
}

$esignApi = new Api();

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo text($openemr_name); ?></title>
    <script>
        // This is to prevent users from losing data by refreshing or backing out of OpenEMR.
        //  (default behavior, however, this behavior can be turned off in the prevent_browser_refresh global)
        <?php if ($GLOBALS['prevent_browser_refresh'] > 0) { ?>
            window.addEventListener('beforeunload', (event) => {
                if (!timed_out) {
                    event.returnValue = <?php echo xlj('Recommend not leaving or refreshing or you may lose data.'); ?>;
                }
            });
        <?php } ?>

        <?php require($GLOBALS['srcdir'] . "/restoreSession.php"); ?>

        // Since this should be the parent window, this is to prevent calls to the
        // window that opened this window. For example when a new window is opened
        // from the Patient Flow Board or the Patient Finder.
        window.opener = null;
        window.name = "main";

        // This flag indicates if another window or frame is trying to reload the login
        // page to this top-level window.  It is set by javascript returned by auth.inc.php
        // and is checked by handlers of beforeunload events.
        var timed_out = false;
        // some globals to access using top.variable
        // note that 'let' or 'const' does not allow global scope here.
        // only use var
        var isPortalEnabled = "<?php echo $GLOBALS['portal_onsite_two_enable'] ?>";
        // Set the csrf_token_js token that is used in the below js/tabs_view_model.js script
        var csrf_token_js = <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>;
        var userDebug = <?php echo js_escape($GLOBALS['user_debug']); ?>;
        var webroot_url = <?php echo js_escape($web_root); ?>;
        var jsLanguageDirection = <?php echo js_escape($_SESSION['language_direction']); ?>;
        var jsGlobals = {}; // this should go away and replace with just global_enable_group_therapy
        // used in tabs_view_model.js.
        jsGlobals.enable_group_therapy = <?php echo js_escape($GLOBALS['enable_group_therapy']); ?>

        var WindowTitleAddPatient = <?php echo ($GLOBALS['window_title_add_patient_name'] ? 'true' : 'false' ); ?>;
        var WindowTitleBase = <?php echo js_escape($openemr_name); ?>;
        const isSms = "<?php echo !empty($GLOBALS['oefax_enable_sms'] ?? null); ?>";
        const isFax = "<?php echo !empty($GLOBALS['oefax_enable_fax']) ?? null?>";
        const isServicesOther = (isSms || isFax);
        function goRepeaterServices() {
            // Ensure send the skip_timeout_reset parameter to not count this as a manual entry in the
            // timing out mechanism in OpenEMR.

            // Send the skip_timeout_reset parameter to not count this as a manual entry in the
            // timing out mechanism in OpenEMR. Notify App for various portal and reminder alerts.
            // Combined portal and reminders ajax to fetch sjp 06-07-2020.
            // Incorporated timeout mechanism in 2021
            restoreSession();
            let request = new FormData;
            request.append("skip_timeout_reset", "1");
            request.append("isPortal", isPortalEnabled);
            request.append("isServicesOther", isServicesOther);
            request.append("isSms", isSms);
            request.append("isFax", isFax);
            request.append("csrf_token_form", csrf_token_js);
            fetch(webroot_url + "/library/ajax/dated_reminders_counter.php", {
                method: 'POST',
                credentials: 'same-origin',
                body: request
            }).then((response) => {
                if (response.status !== 200) {
                    console.log('Reminders start failed. Status Code: ' + response.status);
                    return;
                }
                return response.json();
            }).then((data) => {
                if (data.timeoutMessage && (data.timeoutMessage == 'timeout')) {
                    // timeout has happened, so logout
                    timeoutLogout();
                }
                if (isPortalEnabled) {
                    let mail = data.mailCnt;
                    let chats = data.chatCnt;
                    let audits = data.auditCnt;
                    let payments = data.paymentCnt;
                    let total = data.total;
                    let enable = ((1 * mail) + (1 * audits)); // payments are among audits.
                    // Send portal counts to notification button model
                    // Will turn off button display if no notification!
                    app_view_model.application_data.user().portal(enable);
                    if (enable > 0) {
                        app_view_model.application_data.user().portalAlerts(total);
                        app_view_model.application_data.user().portalAudits(audits);
                        app_view_model.application_data.user().portalMail(mail);
                        app_view_model.application_data.user().portalChats(chats);
                        app_view_model.application_data.user().portalPayments(payments);
                    }
                }
                if (isServicesOther) {
                    let sms = data.smsCnt;
                    let fax = data.faxCnt;
                    let total = data.serviceTotal;
                    let enable = ((1 * sms) + (1 * fax));
                    // Will turn off button display if no notification!
                    app_view_model.application_data.user().servicesOther(enable);
                    if (enable > 0) {
                        app_view_model.application_data.user().serviceAlerts(total);
                        app_view_model.application_data.user().smsAlerts(sms);
                        app_view_model.application_data.user().faxAlerts(fax);
                    }
                }
                // Always send reminder count text to model
                app_view_model.application_data.user().messages(data.reminderText);
            }).catch(function(error) {
                console.log('Request failed', error);
            });

            // run background-services
            // delay 10 seconds to prevent both utility trigger at close to same time.
            // Both call globals so that is my concern.
            setTimeout(function() {
                restoreSession();
                request = new FormData;
                request.append("skip_timeout_reset", "1");
                request.append("ajax", "1");
                request.append("csrf_token_form", csrf_token_js);
                fetch(webroot_url + "/library/ajax/execute_background_services.php", {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: request
                }).then((response) => {
                    if (response.status !== 200) {
                        console.log('Background Service start failed. Status Code: ' + response.status);
                    }
                }).catch(function(error) {
                    console.log('HTML Background Service start Request failed: ', error);
                });
            }, 10000);

            // auto run this function every 60 seconds
            var repeater = setTimeout("goRepeaterServices()", 60000);
        }

        function isEncounterLocked(encounterId) {
            <?php if ($esignApi->lockEncounters()) { ?>
                // If encounter locking is enabled, make a syncronous call (async=false) to check the
                // DB to see if the encounter is locked.
                // Call restore session, just in case
                // @TODO next clean up pass, turn into await promise then modify tabs_view_model.js L-309
                restoreSession();
                let url = webroot_url + "/interface/esign/index.php?module=encounter&method=esign_is_encounter_locked";
                $.ajax({
                    type: 'POST',
                    url: url,
                    data: {
                        encounterId: encounterId
                    },
                    success: function(data) {
                        encounter_locked = data;
                    },
                    dataType: 'json',
                    async: false
                });
                return encounter_locked;
            <?php } else { ?>
                // If encounter locking isn't enabled then always return false
                return false;
            <?php } ?>
        }
    </script>
     <script> window.chtlConfig = { chatbotId: "1527875554" } </script>
<script async data-id="1527875554" id="chtl-script" type="text/javascript" src="https://chatling.ai/js/embed.js"></script>

    <?php Header::setupHeader(['knockout', 'tabs-theme', 'i18next', 'hotkeys']); ?>
    <script>
        // set up global translations for js
        function setupI18n(lang_id) {
            restoreSession();
            return fetch(<?php echo js_escape($GLOBALS['webroot']) ?> + "/library/ajax/i18n_generator.php?lang_id=" + encodeURIComponent(lang_id) + "&csrf_token_form=" + encodeURIComponent(csrf_token_js), {
                credentials: 'same-origin',
                method: 'GET'
            }).then((response) => {
                if (response.status !== 200) {
                    console.log('I18n setup failed. Status Code: ' + response.status);
                    return [];
                }
                return response.json();
            })
        }
        setupI18n(<?php echo js_escape($_SESSION['language_choice']); ?>).then(translationsJson => {
            i18next.init({
                lng: 'selected',
                debug: false,
                nsSeparator: false,
                keySeparator: false,
                resources: {
                    selected: {
                        translation: translationsJson
                    }
                }
            });
        }).catch(error => {
            console.log(error.message);
        });

        /**
         * Assign and persist documents to portal patients
         * @var int patientId pid
         */
        function assignPatientDocuments(patientId) {
            let url = top.webroot_url + '/portal/import_template_ui.php?from_demo_pid=' + encodeURIComponent(patientId);
            dlgopen(url, 'pop-assignments', 'modal-lg', 850, '', '', {
                allowDrag: true,
                allowResize: true,
                sizeHeight: 'full',
            });
        }
    </script>

    <script src="js/custom_bindings.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/user_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/patient_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/therapy_group_data_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/tabs_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/application_view_model.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/frame_proxies.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/dialog_utils.js?v=<?php echo $v_js_includes; ?>"></script>
    <script src="js/shortcuts.js?v=<?php echo $v_js_includes; ?>"></script>

    <?php
    // Below code block is to prepare certain elements for deciding what links to show on the menu
    //
    // prepare newcrop globals that are used in creating the menu
    if ($GLOBALS['erx_enable']) {
        $newcrop_user_role_sql = sqlQuery("SELECT `newcrop_user_role` FROM `users` WHERE `username` = ?", array($_SESSION['authUser']));
        $GLOBALS['newcrop_user_role'] = $newcrop_user_role_sql['newcrop_user_role'];
        if ($GLOBALS['newcrop_user_role'] === 'erxadmin') {
            $GLOBALS['newcrop_user_role_erxadmin'] = 1;
        }
    }

    // prepare track anything to be used in creating the menu
    $track_anything_sql = sqlQuery("SELECT `state` FROM `registry` WHERE `directory` = 'track_anything'");
    $GLOBALS['track_anything_state'] = ($track_anything_sql['state'] ?? 0);
    // prepare Issues popup link global that is used in creating the menu
    $GLOBALS['allow_issue_menu_link'] = ((AclMain::aclCheckCore('encounters', 'notes', '', 'write') || AclMain::aclCheckCore('encounters', 'notes_a', '', 'write')) &&
        AclMain::aclCheckCore('patients', 'med', '', 'write'));
    ?>

    <?php require_once("templates/tabs_template.php"); ?>
    <?php require_once("templates/menu_template.php"); ?>
    <?php require_once("templates/patient_data_template.php"); ?>
    <?php require_once("templates/therapy_group_template.php"); ?>
    <?php require_once("templates/user_data_template.php"); ?>
    <?php require_once("menu/menu_json.php"); ?>
    <?php $userQuery = sqlQuery("select * from users where username = ?", array($_SESSION['authUser'])); ?>

    <script>
        <?php if (!empty($_SESSION['frame1url']) && !empty($_SESSION['frame1target'])) { ?>
            // Use session variables and tabStatus object to set up initial/default first tab
            app_view_model.application_data.tabs.tabsList.push(new tabStatus(<?php echo xlj("Loading"); ?> + "...", <?php echo json_encode("../" . $_SESSION['frame1url']); ?>, <?php echo json_encode($_SESSION['frame1target']); ?>, <?php echo xlj("Loading"); ?> + " " + <?php echo json_encode($_SESSION['frame1label']); ?>, true, true, false));
        <?php } ?>

        <?php if (!empty($_SESSION['frame2url']) && !empty($_SESSION['frame2target'])) { ?>
            // Use session variables and tabStatus object to set up initial/default second tab, if none is set in globals, this tab will not be displayed initially
            app_view_model.application_data.tabs.tabsList.push(new tabStatus(<?php echo xlj("Loading"); ?> + "...", <?php echo json_encode("../" . $_SESSION['frame2url']); ?>, <?php echo json_encode($_SESSION['frame2target']); ?>, <?php echo xlj("Loading"); ?> + " " + <?php echo json_encode($_SESSION['frame2label']); ?>, true, false, false));
        <?php } ?>

        app_view_model.application_data.user(new user_data_view_model(<?php echo json_encode($_SESSION["authUser"])
                                                                            . ',' . json_encode($userQuery['fname'])
                                                                            . ',' . json_encode($userQuery['lname'])
                                                                            . ',' . json_encode($_SESSION['authProvider']); ?>));
    </script>
    <style>
        html,
        body {
            width: max-content;
            min-height: 100% !important;
            height: 100% !important;
        }
    </style>

<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&display=swap');
@import url('https://fonts.googleapis.com/css2?family=Dancing+Script:wght@500;700&family=Great+Vibes&family=Pacifico&family=Sacramento&family=Allura&display=swap');

:root {
    --mdi-navy:       #1e0a3c;
    --mdi-navy-mid:   #2d1260;
    --mdi-navy-light: #e8e0f5;
    --mdi-navy-pale:  #f5f2fc;
    --mdi-gold:       #ffc107;
    --mdi-bg:         #f0f2f5;
    --mdi-surface:    #ffffff;
    --mdi-border:     #e2e6ed;
    --mdi-blue:       #47377a;
    --mdi-blue-d:     rgba(45,107,228,0.10);
    --mdi-teal:       #0d9488;
    --mdi-teal-d:     rgba(13,148,136,0.10);
    --mdi-amber:      #d97706;
    --mdi-amber-d:    rgba(217,119,6,0.10);
    --mdi-rose:       #dc2626;
    --mdi-rose-d:     rgba(220,38,38,0.10);
    --mdi-green:      #16a34a;
    --mdi-green-d:    rgba(22,163,74,0.10);
    --mdi-violet:     #7c3aed;
    --mdi-violet-d:   rgba(124,58,237,0.10);
    --mdi-text:       #0f172a;
    --mdi-text2:      #334155;
    --mdi-muted:      #64748b;
    --mdi-muted2:     #94a3b8;
    --mdi-surface2:   #f8f9fb;
    --mdi-sidenav-w:  280px;
}

html, body {
    width: 100%;
    min-height: 100vh;
    margin: 0;
    /* overflow-x: hidden removed — it creates stacking context that traps fixed sidenav */
}

#mainBox>nav {
    z-index: 501 !important;
}

/* Montserrat everywhere in mainBox */
#mainBox, #mainBox * {
    font-family: 'Montserrat', sans-serif !important;
}

/* ─── QUICK LINKS (original preserved) ─────────────────────────── */
.flex-container {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-around;
    max-width: 90%;
    margin: 0 auto;
}
a { text-decoration: none; }
.flex-item { flex: 1 1 200px; margin: 10px; min-width: 150px; text-align: center; }
.flex-item i { margin-right: 20px; }
.grid-container { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr 1fr 1fr 1fr; gap: 15px; justify-items: center; }
#quickLinks { background: rgba(149,136,232,.7); color: #FFF; margin: 0px !important; padding-top: 10px; padding-bottom: 20px; font-weight: bold; }
.grid-item { padding: 15px; border-radius: 5px; text-decoration: none; color: #333; transition: background-color 0.3s; text-align: center; }
.grid-item:hover { background-color: #e2e6ea; text-decoration: none; }
.grid-item i { font-size: 24px; display: block; margin-bottom: 5px; float: left; margin-right: 15px; }
#quickLinks h5 { text-align: center; }

/* ─── SIDENAV BACKDROP ──────────────────────────────────────────── */
#mdi-sidenav-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 2147483646;
    opacity: 0;
    transition: opacity 0.3s ease;
}
#mdi-sidenav-backdrop.open { opacity: 1; }

/* ─── SIDENAV DRAWER ────────────────────────────────────────────── */
#mdi-sidenav {
    position: fixed;
    top: 0; left: 0;
    height: 100%;
    width: var(--mdi-sidenav-w);
    background: #ffffff;
    z-index: 2147483647;
    transform: translateX(calc(-1 * var(--mdi-sidenav-w)));
    transition: transform 0.32s cubic-bezier(0.4,0,0.2,1);
    display: flex;
    flex-direction: column;
    box-shadow: 4px 0 32px rgba(30,10,60,0.22);
    overflow: hidden;
}
#mdi-sidenav.open { transform: translateX(0); }

.mdi-sn-header {
    background: var(--mdi-navy);
    padding: 1rem 1.2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.mdi-sn-logo { display: flex; align-items: center; gap: 10px; }
.mdi-sn-logo-mark {
    width: 34px; height: 34px;
    background: var(--mdi-blue);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 1rem; flex-shrink: 0;
}
.mdi-sn-logo-text strong { display: block; font-size: 0.8rem; font-weight: 700; color: #fff; font-family: 'Montserrat',sans-serif; }
.mdi-sn-logo-text span { font-size: 0.58rem; color: rgba(255,255,255,0.45); letter-spacing: 0.1em; text-transform: uppercase; font-family: 'Montserrat',sans-serif; }
.mdi-sn-close {
    background: rgba(255,255,255,0.12); border: none; cursor: pointer;
    width: 30px; height: 30px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,0.8); font-size: 1rem; transition: background 0.15s;
}
.mdi-sn-close:hover { background: rgba(255,255,255,0.25); color: #fff; }

.mdi-sn-body { flex: 1; overflow-y: auto; padding: 0.5rem 0 1rem; }
.mdi-sn-body::-webkit-scrollbar { width: 3px; }
.mdi-sn-body::-webkit-scrollbar-thumb { background: var(--mdi-navy-light); border-radius: 2px; }

.mdi-sn-group-label {
    font-size: 0.54rem; letter-spacing: 0.18em; text-transform: uppercase;
    color: var(--mdi-muted2); padding: 0.65rem 1.2rem 0.2rem;
    font-weight: 700; font-family: 'Montserrat',sans-serif;
}
.mdi-sn-item {
    display: flex; align-items: center; gap: 11px;
    padding: 0.58rem 1.2rem;
    color: var(--mdi-text2); font-size: 0.78rem; font-weight: 600;
    font-family: 'Montserrat',sans-serif;
    text-decoration: none; cursor: pointer;
    border-left: 3px solid transparent;
    transition: background 0.13s, color 0.13s, border-color 0.13s;
}
.mdi-sn-item:hover, .mdi-sn-item:focus {
    background: var(--mdi-navy-pale); color: var(--mdi-navy);
    border-left-color: var(--mdi-navy); text-decoration: none;
}
.mdi-sn-item i { width: 16px; text-align: center; color: var(--mdi-muted); font-size: 13px; flex-shrink: 0; transition: color 0.13s; }
.mdi-sn-item:hover i { color: var(--mdi-navy); }
.mdi-sn-item.sn-home { background: var(--mdi-navy-pale); border-left-color: #9588e8; color: var(--mdi-navy); font-weight: 700; }
.mdi-sn-item.sn-home i { color: var(--mdi-gold); }
.mdi-sn-divider { height: 1px; background: var(--mdi-border); margin: 0.35rem 1rem; }
.mdi-sn-footer { padding: 0.75rem 1.2rem; border-top: 1px solid var(--mdi-border); font-size: 0.6rem; color: var(--mdi-muted2); flex-shrink: 0; font-family: 'Montserrat',sans-serif; }

/* ─── HAMBURGER BAR ─────────────────────────────────────────────── */
#mdi-nav-bar {
    background: #ffffff;
    border-bottom: 2px solid var(--mdi-navy-light);
    display: flex; align-items: center;
    padding: 0 1rem; min-height: 40px; gap: 0.75rem;
    box-shadow: 0 2px 6px rgba(30,10,60,0.06);
    position: relative; z-index: 500;
}
#mdi-ham-btn {
    background: none; border: none; cursor: pointer;
    display: flex; flex-direction: column; justify-content: center;
    gap: 4px; padding: 7px 8px; border-radius: 6px;
    transition: background 0.15s; flex-shrink: 0;
}
div#attendantData{background:#FFF !important}

#mdi-ham-btn:hover { background: var(--mdi-navy-light); }
#mdi-ham-btn span { display: block; width: 20px; height: 2px; background: var(--mdi-navy); border-radius: 2px; }
.mdi-nav-bar-label {
    font-size: 0.64rem; font-weight: 700; letter-spacing: 0.1em;
    text-transform: uppercase; color: var(--mdi-navy);
    font-family: 'Montserrat',sans-serif; user-select: none;
}
.mdi-nav-bar-chips { display: flex; gap: 0.4rem; flex-wrap: wrap; margin-left: 0.5rem; }
.mdi-nav-chip {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 0.64rem; font-weight: 600;
    padding: 3px 10px; border-radius: 20px;
    border: 1px solid var(--mdi-navy-light);
    background: var(--mdi-navy-pale); color: var(--mdi-navy);
    cursor: pointer; white-space: nowrap;
    transition: all 0.15s; font-family: 'Montserrat',sans-serif; text-decoration: none;
}
.mdi-nav-chip:hover { background: var(--mdi-navy); color: #fff; border-color: var(--mdi-navy); text-decoration: none; }
.mdi-nav-chip-active { background: var(--mdi-navy) !important; color: #fff !important; border-color: var(--mdi-navy) !important; }
.mdi-nav-chip i { font-size: 11px; }
/* Reports dropdown chip */
.mdi-nav-chip-dd { position: relative; display: inline-block; }
.mdi-nav-chip-dd > .mdi-nav-chip { display: flex; align-items: center; gap: 4px; }
.mdi-nav-chip-dd > .mdi-nav-chip svg.dd-caret { transition: transform 0.2s; }
.mdi-nav-chip-dd:hover > .mdi-nav-chip svg.dd-caret,
.mdi-nav-chip-dd.open > .mdi-nav-chip svg.dd-caret { transform: rotate(180deg); }
.mdi-nav-dd-menu {
    display: none; position: absolute; top: calc(100% + 4px); left: 0; z-index: 9999;
    background: #fff; border: 1px solid var(--mdi-border); border-radius: 10px;
    box-shadow: 0 8px 32px rgba(30,10,60,0.13); min-width: 560px;
    padding: 10px; animation: mdiDdFadeIn 0.15s ease;
}
@keyframes mdiDdFadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:none; } }
.mdi-nav-chip-dd:hover .mdi-nav-dd-menu,
.mdi-nav-chip-dd.open .mdi-nav-dd-menu { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2px 12px; }
/* Reports dropdown gets a wider 4-col layout */
#mdi-reports-chip-dd .mdi-nav-dd-menu { grid-template-columns: repeat(4, 1fr) !important; min-width: 700px !important; }
.mdi-dd-section { padding: 4px 0; }
.mdi-dd-section-label { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: var(--mdi-navy); padding: 4px 8px 2px; }
.mdi-dd-item { display: block; padding: 5px 8px; font-size: 11.5px; color: #374151; border-radius: 6px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-decoration: none; transition: background 0.1s; }
.mdi-dd-item:hover { background: var(--mdi-navy-pale); color: var(--mdi-navy); text-decoration: none; }
.mdi-dd-item svg { vertical-align: middle; margin-right: 4px; opacity: 0.6; }

/* ─── DASHBOARD ─────────────────────────────────────────────────── */
#mdi-dashboard {
    background: var(--mdi-bg);
    padding: 1.2rem 1.5rem 3rem;
    overflow-y: auto;
}
#mdi-dashboard.mdi-hidden { display: none !important; }

.mdi-stat-row { display: grid; grid-template-columns: repeat(5,1fr); gap: 0.85rem; margin-bottom: 1.1rem; }
@media (max-width: 1200px) { .mdi-stat-row { grid-template-columns: repeat(3,1fr); } }
@media (max-width: 800px)  { .mdi-stat-row { grid-template-columns: repeat(2,1fr); } }

.mdi-stat-card {
    background: var(--mdi-surface);
    border-radius: 14px;
    padding: 1.1rem 1.2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    border: 1px solid var(--mdi-border);
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    animation: fadeUp .35s ease both;
    overflow: hidden;
    position: relative;
}
.mdi-stat-card.mdi-stat-link {
    cursor: pointer; transition: border-color 0.15s, box-shadow 0.15s;
}
.mdi-stat-card.mdi-stat-link:hover {
    border-color: var(--mdi-green); box-shadow: 0 2px 10px rgba(16,185,129,0.12);
}
@keyframes mdiUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
.mdi-stat-card:nth-child(1){animation-delay:.04s} .mdi-stat-card:nth-child(2){animation-delay:.08s}
.mdi-stat-card:nth-child(3){animation-delay:.12s} .mdi-stat-card:nth-child(4){animation-delay:.16s}
.mdi-stat-card:nth-child(5){animation-delay:.20s}
.mdi-stat-label { font-size: 0.6rem; letter-spacing: 0.09em; text-transform: uppercase; color: var(--mdi-muted); margin-bottom: 6px; font-weight: 700; }
.mdi-stat-value { font-size: 2.1rem; font-weight: 800; line-height: 1; color: var(--mdi-navy); letter-spacing: -0.04em; }
.mdi-stat-sub { font-size: 0.65rem; color: var(--mdi-muted2); margin-top: 5px; }
.mdi-stat-sub .up { color: var(--mdi-green); font-weight: 600; }
.mdi-stat-sub .down { color: var(--mdi-rose); font-weight: 600; }
.mdi-stat-sub .warn { color: var(--mdi-amber); font-weight: 600; }
.mdi-stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 20px; }
.mdi-si-blue   { background: var(--mdi-blue-d);   color: var(--mdi-blue); }
.mdi-si-teal   { background: var(--mdi-teal-d);   color: var(--mdi-teal); }
.mdi-si-amber  { background: var(--mdi-amber-d);  color: var(--mdi-amber); }
.mdi-si-rose   { background: var(--mdi-rose-d);   color: var(--mdi-rose); }
.mdi-si-violet { background: var(--mdi-violet-d); color: var(--mdi-violet); }
.mdi-si-purple { background: #ede9fe; color: #7c3aed; }
.mdi-si-green  { background: var(--mdi-green-d); color: var(--mdi-green); }

.mdi-dash-grid { display: grid; grid-template-columns: 1.1fr 1.2fr 1.1fr; gap: 1.1rem; align-items: start; }
@media (max-width: 1200px) { .mdi-dash-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 900px)  { .mdi-dash-grid { grid-template-columns: 1fr; } }

.mdi-panel { background: #fff; border: 1px solid var(--mdi-border); border-radius: 10px; overflow: hidden; animation: mdiUp 0.4s ease 0.2s both; height:100% }
.mdi-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 0.85rem 1.2rem 0.7rem; border-bottom: 1px solid var(--mdi-border); }
.mdi-panel-title { font-size: 0.78rem; font-weight: 700; color: var(--mdi-navy); }
.mdi-panel-sub { font-size: 0.61rem; color: var(--mdi-muted); margin-top: 1px; }
.mdi-chip-btn { font-size: 0.62rem; font-weight: 600; padding: 4px 10px; border-radius: 6px; border: 1px solid var(--mdi-border); background: transparent; color: var(--mdi-muted); cursor: pointer; font-family: 'Montserrat',sans-serif; transition: all 0.15s; }
.mdi-chip-btn:hover { border-color: var(--mdi-blue); color: var(--mdi-blue); background: var(--mdi-blue-d); }

.mdi-schedule-list { overflow-y: auto; max-height: 460px; }
.mdi-schedule-item { display: grid; grid-template-columns: 62px 4px 1fr auto; gap: 0 10px; align-items: stretch; padding: 0.6rem 1.2rem; border-bottom: 1px solid var(--mdi-border); cursor: pointer; transition: background 0.12s; }
.mdi-schedule-item:last-child { border-bottom: none; }
.mdi-schedule-item:hover { background: var(--mdi-surface2); }
.mdi-appt-time { font-size: 0.63rem; font-weight: 600; color: var(--mdi-muted); text-align: right; line-height: 1.4; }
.mdi-appt-time .dur { font-size: 0.56rem; color: var(--mdi-muted2); font-weight: 400; }
.mdi-appt-bar { width: 4px; border-radius: 3px; align-self: stretch; min-height: 26px; }
.mdi-bar-new { background: var(--mdi-blue); } .mdi-bar-followup { background: var(--mdi-teal); }
.mdi-bar-urgent { background: var(--mdi-rose); } .mdi-bar-wellness { background: var(--mdi-green); }
.mdi-bar-done { background: var(--mdi-muted2); }
.mdi-appt-name { font-size: 0.74rem; font-weight: 700; color: var(--mdi-text); }
.mdi-appt-detail { font-size: 0.61rem; color: var(--mdi-muted); margin-top: 1px; }
.mdi-status-badge { font-size: 0.54rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; padding: 2px 7px; border-radius: 4px; white-space: nowrap; align-self: flex-start; margin-top: 2px; }
.mdi-sb-waiting   { background: var(--mdi-amber-d); color: var(--mdi-amber); }
.mdi-sb-inroom    { background: var(--mdi-blue-d);  color: var(--mdi-blue); }
.mdi-sb-done      { background: #f1f5f9; color: var(--mdi-muted2); }
.mdi-sb-scheduled { background: var(--mdi-violet-d); color: var(--mdi-violet); }
.mdi-sb-noshow    { background: var(--mdi-rose-d); color: var(--mdi-rose); }

.mdi-right-col { display: flex; flex-direction: column; gap: 1.1rem; }
.mdi-action-list { display: flex; flex-direction: column; }
.mdi-action-row { display: grid; grid-template-columns: 36px 1fr 16px; gap: 0 10px; align-items: center; padding: 0.55rem 1.1rem; border-bottom: 1px solid var(--mdi-border); cursor: pointer; transition: background 0.12s; text-decoration: none; }
.mdi-action-row:last-child { border-bottom: none; }
.mdi-action-row:hover { background: var(--mdi-navy-pale); }
.mdi-action-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.mdi-action-icon svg { width: 15px; height: 15px; }
.mdi-action-text { display: flex; align-items: baseline; gap: 6px; min-width: 0; }
.mdi-action-count { font-size: 1.15rem; font-weight: 700; color: var(--mdi-text); flex-shrink: 0; min-width: 22px; }
.mdi-action-row.clear .mdi-action-count { color: var(--mdi-green); font-size: 0.9rem; }
.mdi-action-label { font-size: 0.78rem; color: var(--mdi-text2); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mdi-action-row.has-items .mdi-action-label { color: var(--mdi-text); font-weight: 500; }
.mdi-action-arrow { width: 14px; height: 14px; color: var(--mdi-muted2); flex-shrink: 0; }

/* Alert / notification items */
.mdi-alert-item { display: flex; align-items: flex-start; gap: 10px; padding: 0.65rem 1.1rem; border-bottom: 1px solid var(--mdi-border); cursor: pointer; transition: background 0.12s; }
.mdi-alert-item:last-child { border-bottom: none; }
.mdi-alert-item:hover { background: var(--mdi-surface2); }
.mdi-alert-icon { width: 30px; height: 30px; border-radius: 7px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 13px; }
.mdi-ai-rose  { background: var(--mdi-rose-d);   color: var(--mdi-rose); }
.mdi-ai-amber { background: var(--mdi-amber-d);  color: var(--mdi-amber); }
.mdi-ai-blue  { background: var(--mdi-blue-d);   color: var(--mdi-blue); }
.mdi-ai-teal  { background: var(--mdi-teal-d);   color: var(--mdi-teal); }
.mdi-ai-violet{ background: var(--mdi-violet-d); color: var(--mdi-violet); }
.mdi-alert-title { font-size: 0.72rem; font-weight: 700; color: var(--mdi-text); }
.mdi-alert-desc  { font-size: 0.62rem; color: var(--mdi-muted); margin-top: 2px; line-height: 1.4; }
.mdi-alert-time  { font-size: 0.57rem; color: var(--mdi-muted2); margin-top: 2px; }
.mdi-empty-state { padding: 1rem 1.2rem; font-size: 0.72rem; color: var(--mdi-muted2); text-align: center; }

/* Messages */
.mdi-msg-item { display: flex; align-items: flex-start; gap: 9px; padding: 0.6rem 1.1rem; border-bottom: 1px solid var(--mdi-border); cursor: pointer; transition: background 0.12s; position: relative; }
.mdi-msg-item:last-child { border-bottom: none; }
.mdi-msg-item:hover { background: var(--mdi-surface2); }
.mdi-msg-item.unread { background: rgba(45,107,228,0.04); }
.mdi-msg-item.unread::before { content:''; position:absolute; left:0; top:50%; transform:translateY(-50%); width:3px; height:60%; background:var(--mdi-blue); border-radius:0 2px 2px 0; }
.mdi-msg-avatar { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:0.58rem; font-weight:700; color:#fff; flex-shrink:0; }
.mdi-av1{background:linear-gradient(135deg,#4f8ef7,#2d6be4)} .mdi-av2{background:linear-gradient(135deg,#34d399,#0d9488)}
.mdi-av3{background:linear-gradient(135deg,#f59e0b,#d97706)} .mdi-av4{background:linear-gradient(135deg,#a78bfa,#7c3aed)}
.mdi-av5{background:linear-gradient(135deg,#f87171,#dc2626)}
.mdi-msg-from { font-size:0.7rem; font-weight:700; color:var(--mdi-text); display:flex; justify-content:space-between; align-items:baseline; gap:4px; }
.mdi-msg-time { font-size:0.57rem; color:var(--mdi-muted2); font-weight:400; }
.mdi-msg-subject { font-size:0.65rem; color:var(--mdi-text2); font-weight:600; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mdi-msg-preview { font-size:0.61rem; color:var(--mdi-muted); margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

/* Tasks */
.mdi-task-item { display:flex; align-items:center; gap:9px; padding:0.55rem 1.1rem; border-bottom:1px solid var(--mdi-border); cursor:pointer; transition:background 0.12s; }
.mdi-task-item:last-child { border-bottom:none; }
.mdi-task-item:hover { background:var(--mdi-surface2); }
.mdi-task-check { width:15px; height:15px; border-radius:4px; border:2px solid #cdd3dc; flex-shrink:0; display:flex; align-items:center; justify-content:center; background:transparent; transition:all 0.15s; }
.mdi-task-check.done { background:var(--mdi-green); border-color:var(--mdi-green); }
.mdi-task-check.done::after { content:''; width:7px; height:4px; border-left:2px solid #fff; border-bottom:2px solid #fff; transform:rotate(-45deg) translateY(-1px); display:block; }
.mdi-task-text { font-size:0.7rem; color:var(--mdi-text2); flex:1; line-height:1.4; }
.mdi-task-text.done-text { text-decoration:line-through; color:var(--mdi-muted2); }
.mdi-task-pri { font-size:0.55rem; font-weight:700; padding:2px 6px; border-radius:4px; flex-shrink:0; }
.mdi-tp-urgent{background:var(--mdi-rose-d);color:var(--mdi-rose)} .mdi-tp-normal{background:var(--mdi-blue-d);color:var(--mdi-blue)} .mdi-tp-low{background:var(--mdi-teal-d);color:var(--mdi-teal)}

.mdi-date-badge { font-size:0.63rem; font-weight:600; color:var(--mdi-muted); background:#fff; border:1px solid var(--mdi-border); padding:3px 10px; border-radius:20px; }
.mdi-dash-topbar { display:flex; align-items:center; justify-content:space-between; margin-bottom:1.1rem; flex-wrap:wrap; gap:0.5rem; }
@keyframes mdi-spin { to { transform: rotate(360deg); } }
.mdi-dash-greeting { font-size:1.05rem; font-weight:700; color:var(--mdi-navy); }
.mdi-dash-greeting span { color:var(--mdi-blue); }

/* Loading skeleton */
.mdi-loading { display:flex; align-items:center; justify-content:center; padding:1.5rem; color:var(--mdi-muted2); font-size:0.72rem; gap:8px; }
.mdi-loading i { animation: spin 1s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ─── PENDING PORTAL APPTS PANEL ───────────────────────────────── */
.mdi-pending-list { overflow-y: auto; max-height: 220px; }
.mdi-pending-item {
    display: grid; grid-template-columns: 1fr auto;
    align-items: center; gap: 8px;
    padding: 0.55rem 1.1rem; border-bottom: 1px solid var(--mdi-border);
    transition: background 0.12s;
}
.mdi-pending-item:last-child { border-bottom: none; }
.mdi-pending-item:hover { background: var(--mdi-navy-pale); }
.mdi-pending-name { font-size: 0.73rem; font-weight: 700; color: var(--mdi-text); }
.mdi-pending-detail { font-size: 0.6rem; color: var(--mdi-muted); margin-top: 2px; }
.mdi-pending-approve {
    font-size: 0.59rem; font-weight: 700; padding: 3px 9px; border-radius: 5px;
    background: var(--mdi-blue-d); color: var(--mdi-blue);
    border: 1px solid rgba(71,55,122,0.18); cursor: pointer; white-space: nowrap;
    font-family: 'Montserrat', sans-serif; transition: all 0.15s; text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px;
}
.mdi-pending-approve:hover { background: var(--mdi-blue); color: #fff; border-color: var(--mdi-blue); text-decoration: none; }

::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cdd3dc; border-radius: 2px; }

/* ─── RIGHT NAV CHIPS ───────────────────────────────────────────── */
.mdi-rchip {
    position: relative;
    padding: 5px 13px !important;
    font-size: 0.68rem !important;
    font-weight: 700 !important;
    white-space: nowrap;
}
/* Notification bubble */
.mdi-nbadge {
    position: absolute;
    top: -6px; right: -6px;
    min-width: 18px; height: 18px;
    border-radius: 9px;
    font-size: 9.5px; font-weight: 800;
    display: flex; align-items: center; justify-content: center;
    padding: 0 4px; line-height: 1;
    color: #fff;
    border: 2px solid #fff;
    font-family: 'Montserrat', sans-serif;
    box-shadow: 0 1px 4px rgba(0,0,0,.18);
}
.mdi-nbadge-rose  { background: var(--mdi-rose); }
.mdi-nbadge-amber { background: var(--mdi-amber); }

/* ─── ICON-ONLY NAV BUTTONS (Messages / Portal / Services) ─────── */
.mdi-rn-icon-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: none;
    background: rgba(255,255,255,0.10);
    color: rgba(255,255,255,0.80);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
    flex-shrink: 0;
    padding: 0;
}
.mdi-rn-icon-btn:hover,
.mdi-rn-dd.open > .mdi-rn-icon-btn {
    background: rgba(255,255,255,0.22);
    color: #fff;
}
/* Notification dot/count badge on icon buttons */
.mdi-rn-icon-badge {
    position: absolute;
    top: -3px;
    right: -3px;
    min-width: 16px;
    height: 16px;
    border-radius: 8px;
    font-size: 9px;
    font-weight: 800;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 3px;
    line-height: 1;
    color: #fff;
    border: 2px solid #1e0a3c;
    font-family: 'Montserrat', sans-serif;
    pointer-events: none;
}
.mdi-rn-badge-amber { background: var(--mdi-amber); }
.mdi-rn-badge-rose  { background: var(--mdi-rose); }
/* The portal dropdown trigger needs flex so the badge positions correctly */
#mdi-rn-portal-dd,
#mdi-rn-svc-dd { align-items: center; }
</style>
</head>

<body class="min-vw-100">
<script>
    // ── Dashboard: only hide on EXPLICIT user navigation ──────────────
    // OpenEMR auto-loads frame1url on startup — we must NOT respond to that.
    // We control visibility exclusively via mdiNavGo() and mdiShowDashboard().

    var mdiDashVisible = true;

    function mdiHideDashboard() {
        var d = document.getElementById('mdi-dashboard');
        if (d) { d.classList.add('mdi-hidden'); mdiDashVisible = false; }
        var chip = document.getElementById('mdi-dash-chip');
        if (chip) chip.classList.remove('mdi-nav-chip-active');
    }

    function mdiShowDashboard() {
        var d = document.getElementById('mdi-dashboard');
        if (d) { d.classList.remove('mdi-hidden'); mdiDashVisible = true; }
        var chip = document.getElementById('mdi-dash-chip');
        if (chip) chip.classList.add('mdi-nav-chip-active');
        // Hide tabs/frames with visibility:hidden (preserves layout, hides content)
        var fd = document.getElementById('framesDisplay');
        if (fd) fd.style.visibility = 'hidden';
        var td = document.getElementById('tabs_div');
        if (td) td.style.visibility = 'hidden';
        // Refresh dashboard data every time Dashboard tab is clicked
        if (typeof window._doRefresh === 'function') {
            var btn = document.getElementById('mdi-refresh-btn');
            window._doRefresh(btn);
}
    }

    // ── Auto-show dashboard when all tabs are closed ──────────────────────
    (function() {
        setInterval(function() {
            try {
                var tabs = app_view_model.application_data.tabs.tabsList();
                if (tabs.length === 0 && !mdiDashVisible) {
                    mdiShowDashboard();
                }
            } catch(e) {}
        }, 400);
    })();

    // ── URL-to-tab-name mapping ──────────────────────────────────────────
    // tabs_view_model.js routes pages into named tab slots. Each tab name
    // corresponds to an iframe. Reusing the same name replaces that tab's
    // content (unless locked). These names match OpenEMR's own conventions.
    function mdiTabName(url) {
        if (/main_info\.php/.test(url))                  return 'cal';
        if (/dynamic_finder\.php/.test(url))             return 'fin';
        if (/new\/new\.php/.test(url))                   return 'fin';
        if (/patient_tracker\.php/.test(url))            return 'flb';
        if (/messages\.php/.test(url))                   return 'msg';
        if (/messages\.php.*go=Recalls/.test(url))       return 'rcb';
        if (/demographics\.php/.test(url))               return 'pat';
        if (/encounter/.test(url))                       return 'enc';
        if (/billing_report\.php/.test(url))             return 'bil';
        if (/billing_tracker\.php/.test(url))            return 'bil';
        if (/edih_view\.php/.test(url))                  return 'bil';
        if (/new_payment\.php/.test(url))                return 'bil';
        if (/edit_payment\.php/.test(url))               return 'bil';
        if (/sl_eob_search\.php/.test(url))              return 'bil';
        if (/edi_270\.php/.test(url))                    return 'bil';
        if (/edi_271\.php/.test(url))                    return 'bil';
        if (/front_payment\.php/.test(url))              return 'bil';
        if (/pos_checkout\.php/.test(url))               return 'bil';
        if (/collections_report\.php/.test(url))         return 'rep';
        if (/pat_ledger\.php/.test(url))                  return 'rep';
        if (/reports\//.test(url))                        return 'rep';
        if (/orders\//.test(url))                        return 'rep';
        if (/usergroup\//.test(url))                     return 'adm';
        if (/super\//.test(url))                         return 'adm';
        if (/facilities\.php/.test(url))                 return 'adm';
        if (/modules\//.test(url))                       return 'adm';
        if (/backup\.php/.test(url))                     return 'adm';
        if (/logview\.php/.test(url))                    return 'adm';
        if (/document/.test(url))                        return 'doc';
        if (/upload_edit\.php/.test(url))                return 'doc';
        if (/authorizations/.test(url))                  return 'msc';
        if (/onotes/.test(url))                          return 'msc';
        if (/batchcom/.test(url))                        return 'msc';
        if (/dicom/.test(url))                           return 'msc';
        if (/patient_edu/.test(url))                     return 'msc';
        // Default: general purpose tab
        return 'msc';
    }

    // All navigation goes through here — this is the ONLY trigger to hide dashboard
    function mdiNavGo(url) {
        mdiHideDashboard();
        mdiCloseSidenav();
        top.restoreSession();

        var fd = document.getElementById('framesDisplay');
        if (fd) fd.style.visibility = 'visible';
        var td = document.getElementById('tabs_div');
        if (td) td.style.visibility = 'visible';

        // Build full URL
        var fullUrl = (url.charAt(0) === '/') ? webroot_url + url : url;

        // Use navigateTab() — the real OpenEMR tab API from tabs_view_model.js.
        // This opens the URL in the named tab slot, replacing its content,
        // and hides all other unlocked tabs. One page visible at a time.
        var tabName = mdiTabName(fullUrl);
        navigateTab(fullUrl, tabName, function() {
            activateTabByName(tabName, true);
        });
    }

    // ── Side nav ───────────────────────────────────────────────────────
    function mdiOpenSidenav() {
        var nav = document.getElementById('mdi-sidenav');
        var bd  = document.getElementById('mdi-sidenav-backdrop');
        if (!nav || !bd) return;
        bd.style.display = 'block';
        requestAnimationFrame(function() {
            bd.classList.add('open');
            nav.classList.add('open');
        });
    }

    function mdiCloseSidenav() {
        var nav = document.getElementById('mdi-sidenav');
        var bd  = document.getElementById('mdi-sidenav-backdrop');
        if (!nav || !bd) return;
        nav.classList.remove('open');
        bd.classList.remove('open');
        setTimeout(function() { bd.style.display = 'none'; }, 330);
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') mdiCloseSidenav();
    });
    </script>

<?php
    // fire off an event here
if (!empty($GLOBALS['kernel']->getEventDispatcher())) {
    /**
     * @var \Symfony\Component\EventDispatcher\EventDispatcher
     */
    $dispatcher = $GLOBALS['kernel']->getEventDispatcher();
    $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_PRE);
}
?>
    <!-- Below iframe is to support logout, which needs to be run in an inner iframe to work as intended -->
    <iframe name="logoutinnerframe" id="logoutinnerframe" style="visibility:hidden; position:absolute; left:0; top:0; height:0; width:0; border:none;" src="about:blank"></iframe>
    <?php // mdsupport - app settings
    $disp_mainBox = '';
    if (isset($_SESSION['app1'])) {
        $rs = sqlquery(
            "SELECT title, app_url FROM list_options WHERE activity=1 AND list_id=? AND option_id=?",
            array('apps', $_SESSION['app1'])
        );
        if ($rs['app_url'] != "main/main_screen.php") {
            echo '<iframe name="app1" src="../../' . attr($rs['app_url']) . '"
    			style="position: absolute; left: 0; top: 0; height: 100%; width: 100%; border: none;" />';
            $disp_mainBox = 'style="display: none;"';
        }
    }
    ?>

    <!-- ═══ SLIDE-IN SIDE NAV (outside mainBox so fixed pos works) ═══ -->
        <!-- ══ SLIDE-IN SIDE NAV ════════════════════════════════════════ -->
        <div id="mdi-sidenav-backdrop" onclick="mdiCloseSidenav()"></div>
        <nav id="mdi-sidenav">
            <div class="mdi-sn-header">
                <div class="mdi-sn-logo">
                    <div class="mdi-sn-logo-mark"><img style="width:20px; height:20px;" src="logo.png"></div>
                    <div class="mdi-sn-logo-text">
                        <strong>MdiTek EHR</strong>
                        <span>Navigation</span>
                    </div>
                </div>
                <button class="mdi-sn-close" onclick="mdiCloseSidenav()"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
            </div>
            <div class="mdi-sn-body">
                <!-- Dashboard home link — always first -->
                <a class="mdi-sn-item sn-home" onclick="mdiShowDashboard(); mdiCloseSidenav();">
                    <svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Dashboard
                </a>
                <div class="mdi-sn-divider"></div>
                <div class="mdi-sn-group-label">Patients</div>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/main/finder/dynamic_finder.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Patients</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/patient_tracker/patient_tracker.php?skip_timeout_reset=1')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Flow Board</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/main/messages/messages.php?go=Recalls')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg> Recall List</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/main/main_info.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Scheduler</a>
                <div class="mdi-sn-divider"></div>
                <div class="mdi-sn-group-label">Clinical</div>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/main/onotes/office_comments_full.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h8l6-6V4a2 2 0 00-2-2z"/><polyline points="14 2 14 8 20 8"/></svg> Office Notes</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/library/dicom_frame.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="12" cy="12" r="4"/><line x1="12" y1="3" x2="12" y2="7"/><line x1="12" y1="17" x2="12" y2="21"/><line x1="3" y1="12" x2="7" y2="12"/><line x1="17" y1="12" x2="21" y2="12"/></svg>Imaging</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/controller.php?document&list&patient_id=00')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg> Documents</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/patient_file/summary/demographics_print.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg> Blank Forms</a>
                <div class="mdi-sn-divider"></div>
                <div class="mdi-sn-group-label">Billing &amp; Finance</div>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/billing/billing_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9.5" y1="13" x2="14.5" y2="13"/></svg> Billing</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/billing/new_payment.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="6" y1="10" x2="14" y2="10"/><line x1="6" y1="14" x2="10" y2="14"/></svg> Batch Payments/EOB Entry</a>
                <div class="mdi-sn-divider"></div>
                <div class="mdi-sn-group-label">Communication</div>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/main/messages/messages.php?form_active=1')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Messages</a>
                <div class="mdi-sn-divider"></div>
                <div class="mdi-sn-group-label">Reports — Clients</div>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/patient_list.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Patient List</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/prescriptions_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V9l-6-6z"/><polyline points="9 3 9 9 15 9"/></svg> Rx Report</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/patient_list_creation.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/></svg> Patient List Creation</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/message_list.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Message List</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/clinical_reports.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> Clinical</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/referrals_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Referrals</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/immunization_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> Immunization Registry</a>

                <div class="mdi-sn-divider"></div>
                <div class="mdi-sn-group-label">Reports — Visits</div>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/daily_summary_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="3" y1="14" x2="21" y2="14"/></svg> Daily Report</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/appointments_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Appointments</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/patient_flow_board_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Patient Flow Board</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/encounters_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Encounters</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/appt_encounter_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/></svg> Appt-Encounter</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/custom_report_range.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> Superbill</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/billing/edi_270.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/></svg> Eligibility Check</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/billing/edi_271.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Eligibility Response</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/services_by_category.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg> Services</a>

                <div class="mdi-sn-divider"></div>
                <div class="mdi-sn-group-label">Reports — Financial</div>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/sales_by_item.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg> Sales</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/billing/sl_receipts_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Cash Receipts</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/front_receipts_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Front Receipts</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/receipts_by_method_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg> Payment Method</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/collections_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Collections &amp; Aging</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/pat_ledger.php?form=0')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg> Patient Ledger</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/svc_code_financial_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg> Financial by Service Code</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/payment_processing_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><path d="M12 22V7"/></svg> Payment Processing</a>

                <div class="mdi-sn-divider"></div>
                <div class="mdi-sn-group-label">Reports — Procedures &amp; Insurance</div>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/orders/pending_orders.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><polyline points="12 8 12 12 14 14"/></svg> Pending Results</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/orders/procedure_stats.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Procedure Statistics</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/insurance_allocation_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Insurance Distribution</a>
                <a class="mdi-sn-item" onclick="mdiNavGo('/interface/reports/unique_seen_patients_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 014-4h4a4 4 0 014 4v2"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg> Unique Seen Patients</a>
            </div>
            <div class="mdi-sn-footer"><?php echo text($openemr_name ?? 'OpenEMR'); ?> &nbsp;&middot;&nbsp; MdiTek EHR</div>
        </nav>
    <div id="mainBox" <?php echo $disp_mainBox ?>>


        <!-- ══ MAIN OPENEMR NAVBAR (KO — hidden, kept for bindings) ════ -->
        <nav style="display:none!important;height:0;overflow:hidden;visibility:hidden;">
            <div class="collapse navbar-collapse" id="mainMenu" data-bind="template: {name: 'menu-template', data: application_data}"></div>
            <span id="userData" data-bind="template: {name: 'user-data-template', data: application_data}"></span>
        </nav>

        <!-- ══ ROLE-BASED NAV (standalone — no KO dependency) ══════════ -->
        <?php
        // ── Detect user role via ACL ─────────────────────────────────
        // Provider is checked FIRST (before admin) so that users like ogarza
        // who have both clinical ACL and some admin ACL show as Provider, not Admin.
        $mdi_is_super     = (bool) AclMain::aclCheckCore('admin', 'super');
        // Provider: clinical rights take priority over admin rights for role display
        $mdi_is_provider  = !$mdi_is_super && (
                            AclMain::aclCheckCore('encounters', 'auth')
                         || AclMain::aclCheckCore('encounters', 'notes', '', 'write')
                         || AclMain::aclCheckCore('patients',   'med',  '', 'write'));
        // Admin (non-super, non-provider): admin management rights only
        $mdi_is_admin     = !$mdi_is_super && !$mdi_is_provider && (
                            AclMain::aclCheckCore('admin', 'users')
                         || AclMain::aclCheckCore('admin', 'acl'));
        $mdi_is_billing   = !$mdi_is_super && !$mdi_is_admin && !$mdi_is_provider && (
                            AclMain::aclCheckCore('acct', 'bill', '', 'write')
                         || AclMain::aclCheckCore('acct', 'eob',  '', 'write')
                         || AclMain::aclCheckCore('acct', 'rep'));
        $mdi_is_frontdesk = !$mdi_is_super && !$mdi_is_admin && !$mdi_is_provider && !$mdi_is_billing;

        // Role label
        if ($mdi_is_super || $mdi_is_admin) {
            $mdi_role_label = 'Admin';
        } elseif ($mdi_is_provider) {
            $mdi_role_label = 'Provider';
        } elseif ($mdi_is_billing) {
            $mdi_role_label = 'Billing';
        } else {
            $mdi_role_label = 'Front Desk';
        }

        // ── Shared SVG path constants ─────────────────────────────────
        $svg_patients  = 'M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2 M12 3a4 4 0 100 8 4 4 0 000-8z';
        $svg_scheduler = 'M3 4h18v18H3z M16 2v4 M8 2v4 M3 10h18';
        $svg_flowboard = 'M9 11l3 3L22 4 M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11';
        $svg_activities= 'M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z M14 2v6h6 M8 13h8 M8 17h8';
        $svg_reports   = 'M18 20V10 M12 20V4 M6 20v-6 M2 20h20';
        $svg_messages  = 'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z';
        $svg_clinical  = 'M22 12h-4l-3 9L9 3l-3 9H2';
        $svg_docs      = 'M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z';
        $svg_admin     = 'M12 15a3 3 0 100-6 3 3 0 000 6z M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z';
        $svg_misc      = 'M4 6h16 M4 12h16 M4 18h7';
        $svg_popups    = 'M15 3h6v6 M10 14L21 3 M21 3 M9 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-4';

        // ── Lists dropdown — visible to all roles ──────────────────────
        $dd_lists = [
            ['Diagnosis Codes',               '/interface/reports/dx_codes.php'],
            ['Facility / Hospital',           '/interface/reports/facility_manager.php'],
            ['Insurance & MediGap Companies', '/interface/reports/insurance_companies_manager.php'],
            ['Procedures & Fees', '/interface/reports/procedures_fees.php'],
            ['Quick Charge Templates', '/interface/reports/quick_charge_manager.php'],
            ['Non-Patients',                  '/interface/main/finder/non_patient_finder.php'],
        ];
        $svg_lists = 'M8 6h13 M8 12h13 M8 18h13 M3 6h.01 M3 12h.01 M3 18h.01';

        // ── Activities (Billing) dropdown — shared across roles ───────
        $dd_activities = [
            ['Insurance Billing',    '/interface/billing/billing_report.php'],
            ['Patient Billing',    '/interface/reports/patient_statements.php'],
            ['Batch Payments',       '/interface/billing/new_payment.php'],
            ['Eligibility Check',    '/interface/billing/edi_270.php'],
            ['Update ICD-10 Codes',    '/interface/custom/update_icd.php'],
            ['Claim File Tracker',   '/interface/billing/claim_file_tracker.php'],
            ['EDI History',          '/interface/billing/edih_view.php'],
        ];
        
          // ── Scheduler dropdown — shared across roles ───────
          $dd_scheduler = [
            ['Reminders',    '/interface/modules/custom_modules/oe-module-faxsms/sms_dashboard.php'],
        ];

        // Admin subnav items use type 'group' for nested flyouts:
        // ['Group Label', '', null, 'group', [['Item','url'], ...]]
        // Regular items: ['Label', '/url']
        // Popup items:   ['Label', '/url', true]
        $dd_admin_super = [
            ['Config',                   '/interface/super/edit_globals.php'],
            ['Clinic',                   '', null, 'group', [
                ['Facilities',           '/interface/usergroup/facilities.php'],
                ['Calendar',             '/interface/main/calendar/index.php?module=PostCalendar&type=admin&func=modifyconfig'],
                ['Import Holidays',      '/interface/main/holidays/import_holidays.php'],
            ]],
            ['Patients',                 '', null, 'group', [
                ['Patient Reminders',    '/interface/patient_file/reminder/patient_reminders.php?mode=admin&patient_id='],
                ['Merge Patients',       '/interface/patient_file/merge_patients.php'],
                ['Manage Duplicates',    '/interface/patient_file/manage_dup_patients.php'],
            ]],
            ['Practice',                 '', null, 'group', [
                ['Practice Settings',    '/controller.php?practice_settings&pharmacy&action=list'],
                ['Rules',                '/interface/super/rules/index.php?action=browse!list'],
                ['Alerts',               '/interface/super/rules/index.php?action=alerts!listactmgr'],
            ]],
            ['Coding',                   '', null, 'group', [
                ['Codes',                '/interface/patient_file/encounter/superbill_custom_full.php'],
                ['Native Data Loads',    '/interface/super/load_codes.php'],
                ['External Data Loads',  '/interface/code_systems/dataloads_ajax.php'],
            ]],
            ['Forms',                    '', null, 'group', [
                ['Forms Administration', '/interface/forms_admin/forms_admin.php'],
                ['Layouts',              '/interface/super/edit_layout.php'],
                ['Lists',                '/interface/super/edit_list.php'],
            ]],
            ['Documents',                '', null, 'group', [
                ['Document Templates',   '/interface/super/manage_document_templates.php'],
            ]],
            ['System',                   '', null, 'group', [
                ['Backup',               '/interface/main/backup.php'],
                ['Files',                '/interface/super/manage_site_files.php'],
                ['Language',             '/interface/language/language.php'],
                ['Certificates',         '/interface/usergroup/ssl_certificates_admin.php'],
                ['Logs',                 '/interface/logview/logview.php'],
                ['Audit Log Tamper',     '/interface/reports/audit_log_tamper_report.php'],
                ['Diagnostics',          '/interface/main/calendar/index.php?module=PostCalendar&type=admin&func=testSystem'],
                ['API Clients',          '/interface/smart/admin-client.php'],
            ]],
            ['Non-Patients',             '/interface/main/finder/non_patient_finder.php'],
            ['Users',                    '/interface/usergroup/usergroup_admin.php'],
            ['Address Book',             '/interface/usergroup/addrbook_list.php'],
            ['ACL',                      '/interface/usergroup/adminacl.php'],
        ];

        $dd_admin_base = [
            ['Users',                    '/interface/usergroup/usergroup_admin.php'],
            ['Address Book',             '/interface/usergroup/addrbook_list.php'],
            ['Facilities',               '/interface/usergroup/facilities.php'],
            ['Practice Settings',        '/controller.php?practice_settings&pharmacy&action=list'],
            ['Backup',                   '/interface/main/backup.php'],
        ];

        // ── Modules dropdown (super/admin only) ───────────────────────
        // Twilio and Care Coordination only show when their globals are enabled
        $dd_modules = [
            ['Manage Modules',           '/interface/modules/zend_modules/public/Installer'],
        ];
        if (!empty($GLOBALS['oefax_enable_sms'])) {
            $dd_modules[] = ['Twilio Messaging',  '/interface/modules/custom_modules/oe-module-faxsms/messageUI.php?type=sms'];
        }
        if (!empty($GLOBALS['oefax_enable_fax']) || !empty($GLOBALS['portal_onsite_two_enable'])) {
            $dd_modules[] = ['Care Coordination',  '/interface/modules/custom_modules/oe-module-faxsms/messageUI.php?type=fax'];
        }

        // ── Procedures dropdown (admin only, from JSON) ───────────────
        $dd_procedures = [
            ['Providers',                '/interface/orders/procedure_provider_list.php'],
            ['Configuration',            '/interface/orders/types.php'],
            ['Load Compendium',          '/interface/orders/load_compendium.php'],
            ['Pending Review',           '/interface/orders/orders_results.php?review=1'],
            ['Patient Results',          '/interface/orders/orders_results.php'],
            ['Lab Overview',             '/interface/patient_file/summary/labdata.php'],
            ['Batch Results',            '/interface/orders/orders_results.php?batch=1'],
            ['Electronic Reports',       '/interface/orders/list_reports.php'],
            ['Lab Documents',            '/interface/main/display_documents.php'],
        ];

        // ── Quick Lists bar — shown for all non-super roles ─────────
        // A persistent row of frequently-needed admin links
        $mdi_show_lists_bar = !$mdi_is_super;

        // ── Miscellaneous dropdown (from JSON) ────────────────────────
        $dd_misc = [
            ['Dicom Viewer',         '/library/dicom_frame.php'],
            ['Patient Education',    '/interface/reports/patient_edu_web_lookup.php'],
            ['Authorizations',       '/interface/main/authorizations/authorizations.php'],
            ['Fax / Scan',           '/interface/fax/faxq.php'],
            ['Office Notes',         '/interface/main/onotes/office_comments.php'],
            ['Batch Communication',  '/interface/batchcom/batchcom.php'],
            ['Configure Tracks',     '/interface/forms/track_anything/create.php'],
            ['New Documents',        '/controller.php?document&list&patient_id=00'],
            ['Demographics (Blank)', '/interface/patient_file/summary/demographics_print.php'],
            ['Superbill (Blank)',     '/interface/patient_file/printed_fee_sheet.php'],
            ['Referral (Blank)',      '/interface/patient_file/transaction/print_referral.php'],
        ];

        // ── Popups dropdown (from JSON — opens in popup window) ───────
        // Popup items: ['Label', '/url', true] — third element signals popup window
        $dd_popups = [
            ['Issues',               '/interface/patient_file/problem_encounter.php',    true],
            ['Export',               '/custom/export_xml.php',                           true],
            ['Import',               '/custom/import_xml.php',                           true],
            ['Appointments',         '/interface/reports/appointments_report.php?patient=', true],
            ['Superbill',            '/interface/patient_file/printed_fee_sheet.php?fill=1', true],
            ['Payment',              '/interface/patient_file/mdi_payment_ledger.php',         true],
            ['Letter',               '/interface/patient_file/letter.php',                true],
            ['Chart Label',          '/interface/patient_file/label.php',                 true],
            ['Barcode Label',        '/interface/patient_file/barcode_label.php',         true],
            ['Address Label',        '/interface/patient_file/addr_label.php',            true],
        ];

        // ── Nav items per role ────────────────────────────────────────
        if ($mdi_is_super || $mdi_is_admin) {
            $mdi_nav_items = [
                ['Patients',    '', $svg_patients, true, [
                    ['New / Search',         '/interface/new/new.php'],
                    ['Flow Board',           '/interface/patient_tracker/patient_tracker.php?skip_timeout_reset=1'],
                    ['Recall',               '/interface/main/messages/messages.php?go=Recalls'],
                ]],
                ['Scheduler',   '/interface/main/main_info.php',  $svg_scheduler, true, $dd_scheduler],
                ['Lists',       '', $svg_lists, true, $dd_lists],
                ['Activities',  '', $svg_activities, true, $dd_activities],
                ['Admin',       '', $svg_admin,   true, $mdi_is_super ? $dd_admin_super : $dd_admin_base],
                ['Modules',     '', 'M4 6h16 M4 10h16 M4 14h6 M14 14h6 M14 18h6', true, $dd_modules],
                ['Procedures',  '', 'M22 12h-4l-3 9L9 3l-3 9H2', true, $dd_procedures],
                ['Misc',        '', $svg_misc,    true, $dd_misc],
                ['Popups',      '', $svg_popups,  true, $dd_popups],
            ];
        } elseif ($mdi_is_provider) {
            $mdi_nav_items = [
                ['Patients',    '', $svg_patients, true, [
                    ['New / Search',         '/interface/new/new.php'],
                    ['Flow Board',           '/interface/patient_tracker/patient_tracker.php?skip_timeout_reset=1'],
                    ['Recall',               '/interface/main/messages/messages.php?go=Recalls'],
                    ['Record Request',       '/interface/patient_file/transaction/record_request.php'],
                ]],
                ['Scheduler',   '/interface/main/main_info.php', $svg_scheduler, true, $dd_scheduler],
                ['Lists',       '', $svg_lists, true, $dd_lists],
                ['Activities',  '', $svg_activities, true, $dd_activities],
                ['Documents',   '/controller.php?document&list&patient_id=00', $svg_docs, false, []],
                ['Lists',       '', $svg_lists, true, $dd_lists],
                ['Misc',        '', $svg_misc,    true, $dd_misc],
                ['Popups',      '', $svg_popups,  true, $dd_popups],
            ];
        } elseif ($mdi_is_billing) {
            $mdi_nav_items = [
                ['Patients',    '', $svg_patients, true, [
                    ['New / Search',          '/interface/new/new.php'],
                    ['Flow Board',            '/interface/patient_tracker/patient_tracker.php?skip_timeout_reset=1'],
                    ['Recall',                '/interface/main/messages/messages.php?go=Recalls'],
                    ['Record Request',        '/interface/patient_file/transaction/record_request.php'],
                ]],
                ['Lists',       '', $svg_lists, true, $dd_lists],
                ['Activities',  '', $svg_activities, true, $dd_activities],
                ['Misc',        '', $svg_misc,    true, $dd_misc],
                ['Popups',      '', $svg_popups,  true, $dd_popups],
            ];
        } else {
            // Front Desk
            $mdi_nav_items = [
                ['Patients',    '', $svg_patients, true, [
                    ['New / Search',          '/interface/new/new.php'],
                    ['Flow Board',            '/interface/patient_tracker/patient_tracker.php?skip_timeout_reset=1'],
                    ['Recall',                '/interface/main/messages/messages.php?go=Recalls'],
                    ['Record Request',        '/interface/patient_file/transaction/record_request.php'],
                ]],
                ['Scheduler',   '/interface/main/main_info.php', $svg_scheduler, true, $dd_scheduler],
                ['Check-In',    '', $svg_flowboard, true, [
                    ['Patient Check-In',      '/interface/patient_tracker/patient_tracker.php'],
                    ['Front Desk Payments',   '/interface/patient_file/front_payment.php'],
                ]],
                ['Documents',   '/controller.php?document&list&patient_id=00', $svg_docs, false, []],
                ['Lists',       '', $svg_lists, true, $dd_lists],
                ['Misc',        '', $svg_misc,    true, $dd_misc],
                ['Popups',      '', $svg_popups,  true, $dd_popups],
            ];
        }
        ?>

        <style>
        /* ── Role-based nav bar ───────────────────────────────────── */
        #mdi-role-nav {
            background: #1e0a3c;
            border-bottom: 1px solid rgba(255,255,255,.08);
            display: flex;
            align-items: stretch;
            justify-content: flex-start;
            height: 42px;
            padding: 0 12px;
            position: relative;
            z-index: 190;
            box-shadow: 0 2px 8px rgba(0,0,0,.18);
            gap: 2px;
        }
        .mdi-rn-badge {
            display: flex;
            align-items: center;
            padding: 0 14px 0 10px;
            margin-right: 6px;
            border-right: 1px solid rgba(255,255,255,.12);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            white-space: nowrap;
            gap: 7px;
            color: #1e0a3c;
            background: #fff;
            flex-shrink: 0;
            height: 100%;
        }
        .mdi-rn-badge-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            flex-shrink: 0;
            /* color set inline per role */
        }
        .mdi-rn-item {
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 0 13px;
            font-size: 12.5px;
            font-weight: 600;
            color: rgba(255,255,255,.75);
            text-decoration: none;
            cursor: pointer;
            border: none;
            background: none;
            font-family: inherit;
            white-space: nowrap;
            height: 100%;
            border-bottom: 2px solid transparent;
            transition: color .13s, border-color .13s, background .13s;
            position: relative;
        }
        .mdi-rn-item:hover,
        .mdi-rn-item.active {
            color: #fff;
            border-bottom-color: #9d77f0;
            background: rgba(255,255,255,.07);
            text-decoration: none;
        }
        .mdi-rn-item svg { flex-shrink: 0; opacity: .6; }
        .mdi-rn-item:hover svg,
        .mdi-rn-item.active svg { opacity: 1; }
        /* Dropdown */
        .mdi-rn-dd { position: relative; display: flex; align-items: stretch; }
        .mdi-rn-caret {
            display: inline-block;
            width: 6px; height: 6px;
            border-right: 1.5px solid currentColor;
            border-bottom: 1.5px solid currentColor;
            transform: rotate(45deg) translateY(-2px);
            opacity: .5;
            margin-left: 2px;
            transition: transform .15s;
        }
        .mdi-rn-dd.open > .mdi-rn-item .mdi-rn-caret { transform: rotate(225deg) translateY(2px); opacity: .9; }
        .mdi-rn-dd-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            min-width: 200px;
            background: #fff;
            border: 1px solid #e8e0f5;
            border-top: 2px solid #7c3aed;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 8px 28px rgba(26,16,51,.2);
            padding: 4px;
            z-index: 9999;
            animation: mdiRnFade .15s ease;
        }
        @keyframes mdiRnFade { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:translateY(0); } }
        .mdi-rn-dd.open .mdi-rn-dd-menu { display: block; }
        .mdi-rn-dd-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            font-size: 12.5px;
            font-weight: 500;
            color: #1e0a3c;
            text-decoration: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background .12s, color .12s;
            white-space: nowrap;
        }
        .mdi-rn-dd-item:hover { background: #f5f2fc; color: #7c3aed; text-decoration: none; }
        .mdi-rn-dd-popup { color: #1e0a3c; }
        /* ── Nested group flyout ── */
        .mdi-rn-dd-group { position: relative; }
        .mdi-rn-dd-group-trigger {
            display: flex; align-items: center; gap: 8px;
            padding: 8px 12px; font-size: 12.5px; font-weight: 600;
            color: #1e0a3c; border-radius: 6px; cursor: pointer;
            transition: background .12s; white-space: nowrap;
            user-select: none;
        }
        .mdi-rn-dd-group-trigger:hover { background: #f5f2fc; color: #7c3aed; }
        .mdi-rn-group-caret { margin-left: auto; opacity: .45; flex-shrink: 0; }
        .mdi-rn-dd-group-menu {
            display: none;
            position: absolute;
            left: 100%;
            top: 0;
            min-width: 190px;
            background: #fff;
            border: 1px solid #e8e0f5;
            border-top: 2px solid #9d77f0;
            border-radius: 0 10px 10px 10px;
            box-shadow: 6px 8px 24px rgba(26,16,51,.15);
            padding: 4px;
            z-index: 10000;
            animation: mdiRnFade .12s ease;
        }
        .mdi-rn-dd-group:hover .mdi-rn-dd-group-menu { display: block; }
        /* ── Lists quick-access bar ── */
        #mdi-lists-bar {
            background: #f0edf9;
            border-bottom: 1px solid #e0d8f4;
            display: flex;
            align-items: center;
            height: 30px;
            padding: 0 14px;
            gap: 8px;
            z-index: 189;
        }
        .mdi-lb-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .08em; color: #7c3aed; margin-right: 4px;
            white-space: nowrap; flex-shrink: 0;
        }
        .mdi-lb-chip {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 3px 10px; border-radius: 5px;
            background: #fff; border: 1px solid #d8cff0;
            font-size: 11.5px; font-weight: 600; color: #1e0a3c;
            cursor: pointer; text-decoration: none;
            transition: background .12s, border-color .12s, color .12s;
            white-space: nowrap;
        }
        .mdi-lb-chip:hover { background: #1e0a3c; border-color: #1e0a3c; color: #fff; text-decoration: none; }
        .mdi-lb-chip svg { opacity: .55; flex-shrink: 0; }
        .mdi-lb-chip:hover svg { opacity: 1; }
        .mdi-rn-popup-badge { margin-left: auto; font-size: 10px; opacity: .45; }
        .mdi-rn-dd-popup:hover .mdi-rn-popup-badge { opacity: .9; }
        /* User menu dropdown on right */
        .mdi-rn-user {
            margin-left: auto !important;
            display: flex;
            align-items: center;
            flex-shrink: 0;
            position: relative;
        }
        .mdi-rn-user-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 0 10px;
            height: 42px;
            font-size: 12.5px;
            font-weight: 600;
            color: rgba(255,255,255,.85);
            background: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            border-bottom: 2px solid transparent;
            transition: background .13s, border-color .13s, color .13s;
            white-space: nowrap;
        }
        .mdi-rn-user-btn:hover,
        .mdi-rn-user.open .mdi-rn-user-btn {
            background: rgba(255,255,255,.07);
            border-bottom-color: #9d77f0;
            color: #fff;
        }
        .mdi-rn-user-avatar {
            width: 26px; height: 26px;
            border-radius: 50%;
            background: linear-gradient(135deg, #7c3aed, #06b6d4);
            color: #fff;
            font-size: 10px;
            font-weight: 800;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            letter-spacing: .02em;
        }
        .mdi-rn-user-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            min-width: 210px;
            background: #fff;
            border: 1px solid #e8e0f5;
            border-top: 2px solid #7c3aed;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 8px 28px rgba(26,16,51,.14);
            padding: 4px;
            z-index: 9999;
            animation: mdiRnFade .15s ease;
        }
        .mdi-rn-user.open .mdi-rn-user-menu { display: block; }
        .mdi-rn-user-header {
            padding: 10px 12px 8px;
            border-bottom: 1px solid #e8e0f5;
            margin-bottom: 4px;
        }
        .mdi-rn-user-header .name { font-size: 13px; font-weight: 700; color: #1e0a3c; }
        .mdi-rn-user-header .role { font-size: 11px; color: #6b7280; margin-top: 1px; }
        .mdi-rn-user-item {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 8px 12px;
            font-size: 12.5px;
            font-weight: 500;
            color: #1e0a3c;
            text-decoration: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background .12s, color .12s;
            white-space: nowrap;
            border: none;
            background: none;
            width: 100%;
            font-family: inherit;
            text-align: left;
        }
        .mdi-rn-user-item:hover { background: #f5f2fc; color: #7c3aed; text-decoration: none; }
        .mdi-rn-user-item.danger:hover { background: #fff1f2; color: #e11d48; }
        .mdi-rn-user-divider { height: 1px; background: #e8e0f5; margin: 4px 8px; }

        /* ── My Signature modal ─────────────────────────────────────── */
        .mdi-sig-tab {
            border: none; background: none; cursor: pointer; font-family: inherit;
            font-size: 12.5px; font-weight: 600; color: var(--mdi-muted);
            padding: 7px 12px 9px; border-bottom: 2px solid transparent;
            display: flex; align-items: center; gap: 5px; transition: color .12s, border-color .12s;
        }
        .mdi-sig-tab:hover { color: var(--mdi-violet); }
        .mdi-sig-tab.active { color: var(--mdi-violet); border-bottom-color: var(--mdi-violet); }
        .mdi-sig-font-btn {
            border: 1.5px solid var(--mdi-border); background: #fff; border-radius: 8px;
            padding: 10px 8px; cursor: pointer; text-align: center; font-size: 1.35rem;
            color: var(--mdi-navy); transition: border-color .12s, background .12s;
        }
        .mdi-sig-font-btn:hover { border-color: #c9b8f0; background: #faf8ff; }
        .mdi-sig-font-btn.active { border-color: var(--mdi-violet); background: #f5f2fc; box-shadow: 0 0 0 1px var(--mdi-violet) inset; }
        .mdi-sig-btn-primary {
            background: var(--mdi-violet); color: #fff; border: none; border-radius: 8px;
            padding: 8px 16px; font-size: 12.5px; font-weight: 700; cursor: pointer;
            font-family: inherit; transition: background .12s;
        }
        .mdi-sig-btn-primary:hover { background: #6d28d9; }
        .mdi-sig-btn-primary:disabled { opacity: .6; cursor: default; }
        .mdi-sig-btn-secondary {
            background: #fff; color: var(--mdi-text2); border: 1px solid var(--mdi-border); border-radius: 8px;
            padding: 8px 16px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: inherit;
            transition: background .12s;
        }
        .mdi-sig-btn-secondary:hover { background: var(--mdi-surface2); }
        .mdi-sig-btn-danger-text {
            background: none; border: none; color: #dc2626; font-size: 12px; font-weight: 600;
            cursor: pointer; font-family: inherit; padding: 8px 4px;
        }
        .mdi-sig-btn-danger-text:hover { text-decoration: underline; }
        .mdi-sig-upload-drop {
            display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px;
            border: 2px dashed var(--mdi-border); border-radius: 10px; padding: 22px 10px;
            cursor: pointer; background: #fbfbfd; text-align: center; transition: border-color .12s, background .12s;
        }
        .mdi-sig-upload-drop:hover { border-color: #c9b8f0; background: #faf8ff; }
        </style>

        <nav id="mdi-role-nav" role="navigation" aria-label="Role Navigation">
            <!-- Role badge -->
            <div class="mdi-rn-badge">
                <span class="mdi-rn-badge-dot" style="background:<?php echo attr($mdi_role_color); ?>"></span>
                <?php echo text($mdi_role_label); ?>
            </div>

            <!-- Nav items -->
            <?php foreach ($mdi_nav_items as $idx => $item):
                $label    = $item[0];
                $url      = $item[1];
                $svgd     = $item[2];
                $has_dd   = $item[3];
                $dd_items = $item[4];
                $onclick  = isset($item[5]) ? $item[5] : ($url ? "mdiNavGo('" . attr($url) . "')" : '');
                $uid      = 'mdi-rn-dd-' . $idx;
            ?>
            <?php if ($has_dd): ?>
            <div class="mdi-rn-dd" id="<?php echo attr($uid); ?>">
                <button class="mdi-rn-item" onclick="mdiRnToggle('<?php echo attr($uid); ?>')" type="button" aria-haspopup="true" aria-expanded="false">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <?php foreach (explode(' M', $svgd) as $pi => $pd):
                            echo '<path d="' . ($pi === 0 ? '' : 'M') . attr($pd) . '"/>'; endforeach; ?>
                    </svg>
                    <?php echo text($label); ?>
                    <span class="mdi-rn-caret"></span>
                </button>
                <div class="mdi-rn-dd-menu" role="menu">
                    <?php foreach ($dd_items as $dd):
                        $is_popup = isset($dd[2]) && $dd[2] === true;
                        $is_group = isset($dd[3]) && $dd[3] === 'group';
                    ?>
                    <?php if ($is_group): ?>
                    <!-- Nested group with flyout -->
                    <div class="mdi-rn-dd-group">
                        <div class="mdi-rn-dd-group-trigger">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                            <?php echo text($dd[0]); ?>
                            <svg class="mdi-rn-group-caret" width="8" height="8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>
                        <div class="mdi-rn-dd-group-menu">
                            <?php foreach ($dd[4] as $sub): ?>
                            <a class="mdi-rn-dd-item" role="menuitem"
                               onclick="mdiNavGo('<?php echo attr($sub[1]); ?>'); mdiRnCloseAll()">
                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                                <?php echo text($sub[0]); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php elseif ($is_popup): ?>
                    <a class="mdi-rn-dd-item mdi-rn-dd-popup" role="menuitem"
                       onclick="mdiRnPopup('<?php echo attr($dd[1]); ?>'); mdiRnCloseAll()"
                       title="Opens in popup window">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                        <?php echo text($dd[0]); ?>
                        <span class="mdi-rn-popup-badge">↗</span>
                    </a>
                    <?php else: ?>
                    <a class="mdi-rn-dd-item" role="menuitem"
                       onclick="mdiNavGo('<?php echo attr($dd[1]); ?>'); mdiRnCloseAll()">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        <?php echo text($dd[0]); ?>
                    </a>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <button class="mdi-rn-item" onclick="<?php echo $onclick; ?>" type="button">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <?php foreach (explode(' M', $svgd) as $pi => $pd):
                        echo '<path d="' . ($pi === 0 ? '' : 'M') . attr($pd) . '"/>'; endforeach; ?>
                </svg>
                <?php echo text($label); ?>
            </button>
            <?php endif; ?>
            <?php endforeach; ?>

            <!-- Messages + Portal + Services icon buttons (left of user menu).
                 Always rendered visible — never gated behind the async KO
                 sync below, so they can't silently disappear if that sync
                 is slow, errors out, or never runs on a given page. The
                 JS further down only ever toggles the small numeric
                 badges; it no longer controls whether the icons themselves
                 are shown. -->
                 <div id="mdi-rn-right"
     style="display:flex;align-items:center;margin-left:auto;gap:8px;">

        <div id="mdi-rn-msg-wrap"
         style="display:flex;align-items:center;gap:2px;">

                <!-- Services (Fax/SMS) icon button + dropdown -->
                <div class="mdi-rn-dd" id="mdi-rn-svc-dd" style="display:flex;align-items:center;">
                    <button class="mdi-rn-icon-btn" type="button"
                            onclick="mdiRnToggle('mdi-rn-svc-dd')"
                            title="Services"
                            aria-haspopup="true" aria-expanded="false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 014.69 12a19.79 19.79 0 01-3.07-8.67A2 2 0 013.62 2h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.91 9.91a16 16 0 006.18 6.18l.95-.95a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/>
                        </svg>
                        <span id="mdi-rn-svc-badge" class="mdi-rn-icon-badge mdi-rn-badge-rose" style="display:none;"></span>
                    </button>
                    <div class="mdi-rn-dd-menu" role="menu" style="right:0;left:auto;min-width:170px;">
                        <a class="mdi-rn-dd-item" role="menuitem"
                           onclick="mdiRnSvcAction('viewFaxCount'); mdiRnCloseAll()">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 17h2a2 2 0 002-2V5a2 2 0 00-2-2H2"/><path d="M17 17h2a2 2 0 002-2V9a2 2 0 00-2-2h-2"/><rect x="6" y="9" width="11" height="13" rx="1"/><path d="M9 5H6"/><path d="M9 3h6v6H9z"/></svg>
                            Pending Faxes
                            <span id="mdi-rn-svc-fax-cnt" style="margin-left:auto;font-size:10px;font-weight:700;color:#dc2626;"></span>
                        </a>
                        <div class="mdi-rn-user-divider"></div>
                        <a class="mdi-rn-dd-item" role="menuitem"
                           onclick="mdiRnSvcAction('viewSmsCount'); mdiRnCloseAll()">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                            Pending SMS
                            <span id="mdi-rn-svc-sms-cnt" style="margin-left:auto;font-size:10px;font-weight:700;color:#dc2626;"></span>
                        </a>
                    </div>
                </div>

                <!-- Messages icon button -->
                <button id="mdi-rn-msg-btn" type="button"
                        onclick="mdiNavGo('/interface/main/messages/messages.php')"
                        title="Messages"
                        class="mdi-rn-icon-btn"
                        style="display:inline-flex;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                    </svg>
                    <span id="mdi-rn-msg-badge" class="mdi-rn-icon-badge mdi-rn-badge-amber" style="display:none;"></span>
                </button>

                <!-- Portal icon button + dropdown -->
                <div class="mdi-rn-dd" id="mdi-rn-portal-dd" style="display:flex;align-items:center;">
                    <button class="mdi-rn-icon-btn" type="button"
                            onclick="mdiRnToggle('mdi-rn-portal-dd')"
                            title="Portal"
                            aria-haspopup="true" aria-expanded="false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="3" width="20" height="14" rx="2"/>
                            <path d="M8 21h8M12 17v4"/>
                        </svg>
                        <span id="mdi-rn-portal-badge" class="mdi-rn-icon-badge mdi-rn-badge-amber" style="display:none;"></span>
                    </button>
                    <div class="mdi-rn-dd-menu" role="menu" style="right:0;left:auto;min-width:190px;">
                        <a class="mdi-rn-dd-item" role="menuitem"
                           onclick="mdiRnPortalAction('viewPortalMail'); mdiRnCloseAll()">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 01-2.06 0L2 7"/></svg>
                            Portal Mail
                            <span id="mdi-rn-portal-mail-cnt" style="margin-left:auto;font-size:10px;font-weight:700;color:#7c3aed;"></span>
                        </a>
                        <div class="mdi-rn-user-divider"></div>
                        <a class="mdi-rn-dd-item" role="menuitem"
                           onclick="mdiNavGo('/portal/patient/onsiteactivityviews'); mdiRnCloseAll()">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                            Portal Audits
                            <span id="mdi-rn-portal-audit-cnt" style="margin-left:auto;font-size:10px;font-weight:700;color:#7c3aed;"></span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- User menu (right side) -->
            <?php
            $mdi_fname   = $userQuery['fname'] ?? '';
            $mdi_lname   = $userQuery['lname'] ?? '';
            $mdi_initials = strtoupper(substr($mdi_fname, 0, 1) . substr($mdi_lname, 0, 1));
            $mdi_fullname = trim($mdi_fname . ' ' . $mdi_lname);
            ?>
            <div class="mdi-rn-user" id="mdi-rn-user-dd">
                <button class="mdi-rn-user-btn" type="button"
                        onclick="mdiRnUserToggle()" aria-haspopup="true" aria-expanded="false">
                    <div class="mdi-rn-user-avatar"><?php echo text($mdi_initials ?: 'U'); ?></div>
                    <?php echo text($mdi_fullname ?: ($_SESSION['authUser'] ?? '')); ?>
                    <span class="mdi-rn-caret" style="margin-left:2px"></span>
                </button>
                <div class="mdi-rn-user-menu" role="menu">
                    <!-- User info header -->
                    <div class="mdi-rn-user-header">
                        <div class="name"><?php echo text($mdi_fullname ?: ($_SESSION['authUser'] ?? '')); ?></div>
                        <div class="role"><?php echo text($mdi_role_label); ?> &middot; <?php echo text($_SESSION['authUser'] ?? ''); ?></div>
                    </div>

                    <!-- Change Password -->
                    <button class="mdi-rn-user-item" role="menuitem"
                            onclick="mdiNavGo('/interface/usergroup/user_info.php?resetpass=1'); mdiRnUserClose()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0110 0v4"/>
                        </svg>
                        Change Password
                    </button>

                    <!-- MFA Management -->
                    <button class="mdi-rn-user-item" role="menuitem"
                            onclick="mdiNavGo('/interface/usergroup/mfa_registrations.php'); mdiRnUserClose()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="5" y="2" width="14" height="20" rx="2"/>
                            <line x1="12" y1="18" x2="12.01" y2="18"/>
                            <path d="M9 9h.01 M12 9h.01 M15 9h.01 M9 13h.01 M12 13h.01 M15 13h.01"/>
                        </svg>
                        MFA Management
                    </button>

                    <!-- My Signature -->
                    <button class="mdi-rn-user-item" role="menuitem"
                            onclick="mdiOpenSignatureModal(); mdiRnUserClose()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>
                        </svg>
                        My Signature
                        <span id="mdi-rn-sig-badge" style="margin-left:auto;width:6px;height:6px;border-radius:50%;background:var(--mdi-rose,#dc2626);display:none;" title="No signature on file"></span>
                    </button>

                    <!-- Help -->
                    <a class="mdi-rn-user-item" role="menuitem"
                       href="https://support.mditekehr.com" target="_blank" rel="noopener"
                       onclick="mdiRnUserClose()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                        </svg>
                        Help &amp; Support
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-left:auto;opacity:.4"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    </a>

                    <div class="mdi-rn-user-divider"></div>

                    <!-- Logout -->
                    <button class="mdi-rn-user-item danger" role="menuitem"
                            onclick="mdiRnLogout()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                        Log Out
                    </button>
                </div>
            </div> <!-- mdi-rn-user -->
            </div> <!-- mdi-rn-right -->
        </nav>

        <?php if ($mdi_show_lists_bar): ?>
        <!-- ── Lists quick-access bar (all non-super roles) ────────────── -->
        <div id="mdi-lists-bar">
            <span class="mdi-lb-label">Quick Access:</span>
            <a class="mdi-lb-chip" onclick="mdiNavGo('/controller.php?practice_settings&pharmacy&action=list')">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4"/></svg>
                Practice Settings
            </a>
            <a class="mdi-lb-chip" onclick="mdiNavGo('/interface/usergroup/usergroup_admin.php')">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                Users
            </a>
            <a class="mdi-lb-chip" onclick="mdiNavGo('/interface/main/finder/non_patient_finder.php')">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Non-Patients
            </a>
            <a class="mdi-lb-chip" onclick="mdiNavGo('/interface/usergroup/facilities.php')">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                Facilities
            </a>
            <a class="mdi-lb-chip" onclick="mdiNavGo('/interface/patient_file/encounter/superbill_custom_full.php')">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
                Procedure Codes
            </a>
        </div>
        <?php endif; ?>

        <script>
        // ── Role nav dropdown toggle ───────────────────────────────────
        // Open a URL in an OpenEMR-style popup (uses dlgopen if available, else window.open)
        function mdiRnPopup(url) {
            var fullUrl = webroot_url + url;
            if (typeof dlgopen === 'function') {
                dlgopen(fullUrl, 'mdi_popup_' + Date.now(), 'modal-lg', 700, '', '', {
                    allowDrag: true,
                    allowResize: true,
                    sizeHeight: 'window',
                });
            } else {
                window.open(fullUrl, 'mdi_popup', 'width=850,height=650,resizable=yes,scrollbars=yes,status=yes');
            }
        }

        function mdiRnCloseAll() {
            document.querySelectorAll('.mdi-rn-dd.open').forEach(function(d) {
                d.classList.remove('open');
                var btn = d.querySelector('.mdi-rn-item');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            });
            mdiRnUserClose();
        }
        function mdiRnToggle(id) {
            var dd = document.getElementById(id);
            if (!dd) return;
            var isOpen = dd.classList.contains('open');
            mdiRnCloseAll();
            if (!isOpen) {
                dd.classList.add('open');
                var btn = dd.querySelector('.mdi-rn-item');
                if (btn) btn.setAttribute('aria-expanded', 'true');
            }
        }
        // ── User menu ──────────────────────────────────────────────────
        function mdiRnUserClose() {
            var ud = document.getElementById('mdi-rn-user-dd');
            if (ud) {
                ud.classList.remove('open');
                var btn = ud.querySelector('.mdi-rn-user-btn');
                if (btn) btn.setAttribute('aria-expanded', 'false');
            }
        }
        function mdiRnUserToggle() {
            var ud = document.getElementById('mdi-rn-user-dd');
            if (!ud) return;
            var isOpen = ud.classList.contains('open');
            mdiRnCloseAll();   // closes all nav dropdowns first
            if (!isOpen) {
                ud.classList.add('open');
                var btn = ud.querySelector('.mdi-rn-user-btn');
                if (btn) btn.setAttribute('aria-expanded', 'true');
            }
        }
        function mdiRnLogout() {
            mdiRnUserClose();
            top.restoreSession();
            top.location.href = '<?php echo $web_root; ?>/interface/logout.php?csrf_token_form=' + encodeURIComponent(csrf_token_js);
        }

        // ── Portal / Messages / Services nav bar buttons ───────────────
        // These mirror the KO-driven buttons previously in patient_data_template.
        // goRepeaterServices() already updates app_view_model; we subscribe here
        // to keep the nav bar badge counts in sync. The icons themselves are
        // always rendered visible in the HTML above — this only ever updates
        // the small numeric badges, so a slow/failed/absent KO sync can no
        // longer make the icons disappear.
        (function mdiRnAlertSync() {
            function sync() {
                try {
                    var u = app_view_model.application_data.user();
                    if (!u) return;

                    // ── Messages ──────────────────────────────────────
                    var msgBadge = document.getElementById('mdi-rn-msg-badge');
                    var msgVal   = typeof u.messages === 'function' ? u.messages() : null;
                    var hasMsg   = msgVal && parseInt(msgVal) > 0;
                    if (msgBadge) {
                        msgBadge.textContent  = msgVal || '';
                        msgBadge.style.display = hasMsg ? 'flex' : 'none';
                    }

                    // ── Portal ────────────────────────────────────────
                    var portalBadge = document.getElementById('mdi-rn-portal-badge');
                    var mailCnt    = document.getElementById('mdi-rn-portal-mail-cnt');
                    var auditCnt   = document.getElementById('mdi-rn-portal-audit-cnt');
                    var portalTotal   = typeof u.portalAlerts === 'function' ? u.portalAlerts() : 0;
                    var portalMail    = typeof u.portalMail === 'function' ? u.portalMail() : 0;
                    var portalAudits  = typeof u.portalAudits === 'function' ? u.portalAudits() : 0;
                    if (portalBadge) {
                        portalBadge.textContent   = portalTotal || '';
                        portalBadge.style.display = portalTotal > 0 ? 'flex' : 'none';
                    }
                    if (mailCnt)  mailCnt.textContent  = portalMail  > 0 ? portalMail  : '';
                    if (auditCnt) auditCnt.textContent = portalAudits > 0 ? portalAudits : '';

                    // ── Services (Fax/SMS) ────────────────────────────
                    var svcBadge = document.getElementById('mdi-rn-svc-badge');
                    var faxCnt   = document.getElementById('mdi-rn-svc-fax-cnt');
                    var smsCnt   = document.getElementById('mdi-rn-svc-sms-cnt');
                    var svcTotal   = typeof u.serviceAlerts === 'function' ? u.serviceAlerts() : 0;
                    var faxVal     = typeof u.faxAlerts === 'function' ? u.faxAlerts() : 0;
                    var smsVal     = typeof u.smsAlerts === 'function' ? u.smsAlerts() : 0;
                    if (svcBadge) {
                        svcBadge.textContent   = svcTotal || '';
                        svcBadge.style.display = svcTotal > 0 ? 'flex' : 'none';
                    }
                    if (faxCnt) faxCnt.textContent = faxVal > 0 ? faxVal : '';
                    if (smsCnt) smsCnt.textContent = smsVal > 0 ? smsVal : '';
                } catch(e) {}
            }

            // Wait for KO to initialise, then subscribe to the relevant observables
            function attachSubscriptions() {
                try {
                    var u = app_view_model.application_data.user();
                    if (!u) { setTimeout(attachSubscriptions, 500); return; }
                    ['messages','portal','portalAlerts','portalMail','portalAudits',
                     'servicesOther','serviceAlerts','faxAlerts','smsAlerts'].forEach(function(k) {
                        if (typeof u[k] === 'function') u[k].subscribe(function() { sync(); });
                    });
                    sync();
                } catch(e) { setTimeout(attachSubscriptions, 500); }
            }

            setTimeout(attachSubscriptions, 800);
        })();

        // Proxy KO portal/services actions into the real KO user view-model
        function mdiRnPortalAction(action) {
            try {
                var u = app_view_model.application_data.user();
                if (u && typeof u[action] === 'function') u[action]();
            } catch(e) {}
        }
        function mdiRnSvcAction(action) {
            try {
                var u = app_view_model.application_data.user();
                if (u && typeof u[action] === 'function') u[action]();
            } catch(e) {}
        }
        // ── Close all on outside click ─────────────────────────────────
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.mdi-rn-dd') && !e.target.closest('.mdi-rn-user')) {
                document.querySelectorAll('.mdi-rn-dd.open').forEach(function(d) {
                    d.classList.remove('open');
                    var btn = d.querySelector('.mdi-rn-item');
                    if (btn) btn.setAttribute('aria-expanded', 'false');
                });
                mdiRnUserClose();
            }
        });
        // ── Close on ESC ───────────────────────────────────────────────
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') mdiRnCloseAll();
        });
        </script>

        <!-- ══ SECONDARY NAV BAR ════════════════════════════════════════ -->
        <div id="mdi-nav-bar">
            <button id="mdi-ham-btn" onclick="mdiOpenSidenav()" title="Open menu">
                <span></span><span></span><span></span>
            </button>
            <span class="mdi-nav-bar-label">Menu</span>
            <div class="mdi-nav-bar-chips">
                <a class="mdi-nav-chip mdi-nav-chip-active" id="mdi-dash-chip" onclick="mdiShowDashboard()"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg> Dashboard</a>
                <a class="mdi-nav-chip" onclick="mdiNavGo('/interface/main/finder/dynamic_finder.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Patients</a>
                <a class="mdi-nav-chip" onclick="mdiNavGo('/interface/patient_tracker/patient_tracker.php?skip_timeout_reset=1')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Flow Board</a>
                <a class="mdi-nav-chip" onclick="mdiNavGo('/interface/main/main_info.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Scheduler</a>
                <a class="mdi-nav-chip" onclick="mdiNavGo('/interface/billing/billing_report.php')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg> Billing</a>
                <a class="mdi-nav-chip" onclick="mdiNavGo('/interface/main/messages/messages.php?form_active=1')"><svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Messages</a>
                <div class="mdi-nav-chip-dd" id="mdi-reports-chip-dd">
                    <a class="mdi-nav-chip" onclick="document.getElementById('mdi-reports-chip-dd').classList.toggle('open'); event.stopPropagation();">
                        <svg style="width:1em;height:1em;vertical-align:middle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg>
                        Reports
                        <svg class="dd-caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
                    </a>
                    <div class="mdi-nav-dd-menu" style="min-width:680px;grid-template-columns:repeat(4,1fr);">

                        <!-- ── Clients ── -->
                        <div class="mdi-dd-section">
                            <div class="mdi-dd-section-label">&#9654; Clients</div>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/patient_list.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Patient List</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/prescriptions_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 3H5a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2V9l-6-6z"/></svg> Rx Report</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/patient_list_creation.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> Patient List Creation</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/message_list.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/></svg> Message List</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/clinical_reports.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg> Clinical</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/referrals_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg> Referrals</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/immunization_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3l3 3m0 0l3-3m-3 3v9"/><circle cx="12" cy="12" r="1"/></svg> Immunization Registry</a>
                        </div>

                        <!-- ── Clinical (CDR / AMC) ── -->
                        <div class="mdi-dd-section">
                            <div class="mdi-dd-section-label">&#9654; Clinical</div>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/report_results.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Report Results</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/cqm.php?type=standard');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Standard Measures</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/cqm.php?type=amc');mdiCloseReportsDD()" style="font-weight:700;color:var(--mdi-navy);"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> Automated Measures (AMC)</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/cdr_log.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8h1a4 4 0 010 8h-1"/><path d="M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg> Alerts Log</a>
                        </div>

                        <!-- ── Visits ── -->
                        <div class="mdi-dd-section">
                            <div class="mdi-dd-section-label">&#9654; Visits</div>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/daily_summary_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Daily Report</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/appointments_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Appointments</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/patient_flow_board_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Patient Flow Board</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/encounters_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Encounters</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/appt_encounter_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/></svg> Appt-Encounter</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/custom_report_range.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg> Superbill</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/billing/edi_270.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/></svg> Eligibility</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/billing/edi_271.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Eligibility Response</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/services_by_category.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Services</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/non_reported.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Syndromic Surveillance</a>
                        </div>

                        <!-- ── Financial ── -->
                        <div class="mdi-dd-section">
                            <div class="mdi-dd-section-label">&#9654; Financial</div>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/sales_by_item.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg> Sales</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/billing/sl_receipts_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Cash Receipts</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/front_receipts_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg> Front Receipts</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/receipts_by_method_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg> Payment Method</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/collections_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Collections &amp; Aging</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/pat_ledger.php?form=0');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg> Patient Ledger</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/svc_code_financial_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><line x1="2" y1="20" x2="22" y2="20"/></svg> Financial by Service Code</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/payment_processing_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><path d="M12 22V7"/></svg> Payment Processing</a>
                        </div>

                        <!-- ── Procedures & Insurance ── -->
                        <div class="mdi-dd-section">
                            <div class="mdi-dd-section-label">&#9654; Procedures</div>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/orders/pending_orders.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><polyline points="12 8 12 12 14 14"/></svg> Pending Results</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/orders/pending_followup.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Pending F/U</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/orders/procedure_stats.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg> Procedure Statistics</a>
                        </div>

                        <!-- ── Insurance ── -->
                        <div class="mdi-dd-section">
                            <div class="mdi-dd-section-label">&#9654; Insurance</div>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/insurance_allocation_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Distribution</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/billing/indigent_patients_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Indigents</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/unique_seen_patients_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 014-4h4a4 4 0 014 4v2"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg> Unique Seen Patients</a>
                        </div>

                        <!-- ── Services / Admin ── -->
                        <div class="mdi-dd-section">
                            <div class="mdi-dd-section-label">&#9654; Services</div>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/background_services.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4"/></svg> Background Services</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/direct_message_log.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Direct Message Log</a>
                            <a class="mdi-dd-item" onclick="mdiNavGo('/interface/reports/audit_log_tamper_report.php');mdiCloseReportsDD()"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg> Audit Log Tamper</a>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── Right side: search + quick-action buttons ── -->
            <div style="margin-left:auto;display:flex;align-items:center;gap:6px;flex-shrink:0;">

                <!-- search hidden — kept in DOM for KO binding + Enter key -->
                <form name="frm_search_globals" style="position:absolute;width:0;height:0;overflow:hidden;opacity:0;pointer-events:none;z-index:-1;">
                    <input type="text" id="anySearchBox" name="anySearchBox" class="<?php echo $any_search_class ?>">
                    <button type="button" id="search_globals" class="<?php echo $search_globals_class ?>"
                            data-bind="event: {mousedown: viewPtFinder.bind( $data, '<?php echo xla("The search field cannot be empty. Please enter a search term") ?>', '<?php echo attr($search_any_type); ?>')}"></button>
                </form>


            </div>
        </div>

        <div id="attendantData" data-bind="template: {name: app_view_model.attendant_template_type, data: application_data}"></div>

        <!-- ══ QUICK LINKS (original preserved) ════════════════════════ -->
        <div id="quickLinks" class="my-3" style="display:none">
            <div class="flex-container">
                <div class="flex-item"><button class="btn btn-light" style="width:100%;" onclick="mdiNavGo('/interface/main/finder/dynamic_finder.php')"><svg style="width:1em;height:1em;vertical-align:middle;color:#2d6be4;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg> Patients</button></div>
                <div class="flex-item"><button class="btn btn-light" style="width:100%;" onclick="mdiNavGo('/interface/patient_tracker/patient_tracker.php?skip_timeout_reset=1')"><svg style="width:1em;height:1em;vertical-align:middle;color:#0d9488;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Flow Board</button></div>
                <div class="flex-item"><button class="btn btn-light" style="width:100%;" onclick="mdiNavGo('/interface/main/main_info.php')"><svg style="width:1em;height:1em;vertical-align:middle;color:#7c3aed;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Scheduler</button></div>
                <div class="flex-item"><button class="btn btn-light" style="width:100%;" onclick="mdiNavGo('/interface/billing/billing_report.php')"><svg style="width:1em;height:1em;vertical-align:middle;color:#d97706;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg> Billing</button></div>
                <div class="flex-item"><button class="btn btn-light" style="width:100%;" onclick="mdiNavGo('/interface/main/messages/messages.php?form_active=1')"><svg style="width:1em;height:1em;vertical-align:middle;color:#16a34a;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> Messages</button></div>
                <div class="flex-item"><button class="btn btn-light" style="width:100%;" onclick="mdiNavGo('/interface/reports/daily_summary_report.php')"><svg style="width:1em;height:1em;vertical-align:middle;color:#dc2626;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> DaySheets</button></div>
            </div>
        </div>

        <!-- ══ DASHBOARD ════════════════════════════════════════════════ -->
        <?php
        // ── Live data queries using verified OpenEMR schema ───────────
        $today    = date('Y-m-d');
        $authUser = $_SESSION['authUser'] ?? '';
        $authUserID = $_SESSION['authUserID'] ?? 0;

        // Get provider's numeric ID (used for encounter queries)
        $providerRow = sqlQuery("SELECT id FROM users WHERE username = ? LIMIT 1", [$authUser]);
        $providerID  = (int)($providerRow['id'] ?? 0);

        // ── Today's appointments via the same fetch_Patient_Tracker_Events() path
        //    the Flow Board uses — fetchAppointments() handles all recurrence logic
        //    natively so we don't duplicate it here.
        $appts        = [];
        $totalAppts   = 0;
        $doneAppts    = 0;
        $pendingAppts = 0;

        try {
            if (!function_exists('fetch_Patient_Tracker_Events')) {
                require_once $GLOBALS['srcdir'] . '/patient_tracker.inc.php';
            }
            // Pass $today for both from/to to scope results to today only.
            // All other filters left empty (null) so ALL providers/facilities show.
            $rawEvents = fetch_Patient_Tracker_Events($today, $today);

            // fetchAppointments returns rows keyed by OpenEMR field names.
            // Normalize them to the field names the dashboard renderer expects.
            foreach ($rawEvents as $ev) {
                $pid = $ev['pid'] ?? $ev['pc_pid'] ?? 0;
                if (empty($pid)) continue;

                // Patient name: fetchAppointments provides fname/lname directly
                $fname = $ev['fname'] ?? '';
                $lname = $ev['lname'] ?? '';

                // Provider name
                $prov = trim(($ev['ufname'] ?? '') . ' ' . ($ev['ulname'] ?? ''));
                if ($prov === '') {
                    $prov = trim($ev['provider_name'] ?? '');
                }

                $appts[] = [
                    'pc_eid'        => $ev['eid']          ?? $ev['pc_eid']       ?? 0,
                    'pc_eventDate'  => $ev['pc_eventDate'] ?? $today,
                    'pc_startTime'  => $ev['pc_startTime'] ?? '00:00:00',
                    'pc_duration'   => $ev['pc_duration']  ?? 0,
                    'pc_apptstatus' => $ev['pc_apptstatus'] ?? '',
                    'pc_catid'      => $ev['pc_catid']     ?? 0,
                    'pc_hometext'   => $ev['pc_hometext']  ?? '',
                    'fname'         => $fname,
                    'lname'         => $lname,
                    'pid'           => $pid,
                    'pc_catname'    => $ev['pc_catname']   ?? '',
                    'provider_name' => $prov,
                ];
            }
            // Sort by start time to match flow board order
            usort($appts, function($a, $b) {
                return strcmp($a['pc_startTime'], $b['pc_startTime']);
            });
        } catch (Throwable $mdiEx) { error_log("MDI dashboard appts: " . $mdiEx->getMessage()); $appts = []; }

        $totalAppts   = count($appts);
        $doneAppts    = count(array_filter($appts, function($a) {
            return in_array($a['pc_apptstatus'] ?? '', ['>','#','?','%','x','y']);
        }));
        $pendingAppts = $totalAppts - $doneAppts;

        // ── Unread messages for current user ─────────────────────────
        // pnotes: assigned_to = username string of recipient
        //         user = integer ID of sender (joined to users.id)
        $unreadMsgs = 0;
        try {
            // Count all active unread messages regardless of case
            $msgCountRow = sqlQuery(
                "SELECT COUNT(*) AS cnt FROM pnotes
                 WHERE assigned_to = ? AND (deleted = 0 OR deleted IS NULL)
                   AND LOWER(message_status) IN ('new','flagged')",
                [$authUser]
            );
            $unreadMsgs = (int)($msgCountRow['cnt'] ?? 0);
        } catch (Throwable $mdiEx) { error_log("MDI dashboard pnotes count: " . $mdiEx->getMessage()); }

        // ── Recent messages list ──────────────────────────────────────
        // pn.user is an integer user ID — join to users.id (NOT users.username)
        $messages = [];
        try {
            $msgListRes = sqlStatement(
                "SELECT pn.id, pn.date, pn.body, pn.title AS subject, pn.message_status,
                        CONCAT(u.fname, ' ', u.lname) AS from_name
                 FROM pnotes pn
                 LEFT JOIN users u ON u.username = pn.user
                 WHERE pn.assigned_to = ?
                   AND pn.deleted != 1
                 ORDER BY pn.date DESC LIMIT 5",
                [$authUser]
            );
            while ($row = sqlFetchArray($msgListRes)) { $messages[] = $row; }
        } catch (Throwable $mdiEx) { error_log("MDI dashboard messages: " . $mdiEx->getMessage()); }

        // ── Portal audits pending ─────────────────────────────────────
        $portalAudits = 0;
        if (function_exists('tableExists') && tableExists('onsite_portal_activity')) {
            $paRow = sqlQuery("SELECT COUNT(*) AS cnt FROM onsite_portal_activity WHERE require_audit = 1 AND status = 'waiting'", []);
            if ($paRow !== false) $portalAudits = (int)($paRow['cnt'] ?? 0);
        }

        // ── Documents pending physician authorization ─────────────────
        $docsToAuth = 0;
        if (function_exists('tableExists') && tableExists('documents')) {
            $dRow = sqlQuery("SELECT COUNT(*) AS cnt FROM documents WHERE couch_docid IS NULL AND list_id = 0 AND deleted = 0 AND owner IS NOT NULL", []);
            if ($dRow !== false) $docsToAuth = (int)($dRow['cnt'] ?? 0);
        }

        // ── Portal payments pending ───────────────────────────────────
        $portalPayments = 0;
        if (function_exists('tableExists') && tableExists('onsite_payment')) {
            // Try with posting_date column first, fall back if column missing
            $ppRow = sqlQuery("SELECT COUNT(*) AS cnt FROM onsite_payment WHERE amount > 0 AND posting_date IS NULL", []);
            if ($ppRow !== false) {
                $portalPayments = (int)($ppRow['cnt'] ?? 0);
            } else {
                $ppRow2 = sqlQuery("SELECT COUNT(*) AS cnt FROM onsite_payment WHERE amount > 0", []);
                if ($ppRow2 !== false) $portalPayments = (int)($ppRow2['cnt'] ?? 0);
            }
        }

        // ── Unsigned encounters: kept in sync with mdi_visits.php's
        // "Unsigned" tab and the mdi_count_unsigned_encounters() helper
        // defined above (SOAP note present, no esign_signatures/
        // mdi_signatures lock, no date-range cap). ──────────────────────
        $unsignedNotes = mdi_count_unsigned_encounters();

        // ── Action items: billing & claims ───────────────────────────
        // Claims ready to send: billing rows where billed=1 (approved/ready),
        // bill_process=0 (not yet transmitted), activity=1 (active charge)
        $claimsToSend = 0;
        try {
            $r = sqlQuery(
                "SELECT COUNT(DISTINCT encounter) AS cnt FROM billing
                 WHERE billed = 0 AND bill_process = 0 AND activity = 1",
                []
            );
            if ($r !== false) $claimsToSend = (int)($r['cnt'] ?? 0);
        } catch (Throwable $e) { error_log("MDI claimsToSend: ".$e->getMessage()); }
        // Rejected claims needing followup
        $claimsRejected = 0;
        if (function_exists('tableExists') && tableExists('claims')) {
            $r = sqlQuery("SELECT COUNT(*) AS cnt FROM claims WHERE status = 4", []);
            if ($r !== false) $claimsRejected = (int)($r['cnt'] ?? 0);
        }
        // Denied claims needing followup (payer denied)
        $claimsDenied = 0;
        if (function_exists('tableExists') && tableExists('claims')) {
            $r = sqlQuery("SELECT COUNT(*) AS cnt FROM claims WHERE status = 7", []);
            if ($r !== false) $claimsDenied = (int)($r['cnt'] ?? 0);
        }
        // Messages requiring followup (activity=1, not new, not deleted)
        $msgsFollowup = 0;
        try {
            $r = sqlQuery("SELECT COUNT(*) AS cnt FROM pnotes WHERE assigned_to = ? AND deleted != 1 AND message_status IN ('New','flagged')", [$authUser]);
            if ($r !== false) $msgsFollowup = (int)($r['cnt'] ?? 0);
        } catch (Throwable $e) { error_log("MDI msgs followup: ".$e->getMessage()); }
        // Portal patient activities pending audit + calendar pending approvals
        $apptsToApprove = 0;
        try {
            if (!empty($GLOBALS['portal_onsite_two_enable']) && function_exists('tableExists') && tableExists('onsite_activity')) {
                $r = sqlQuery("SELECT COUNT(*) AS cnt FROM onsite_activity WHERE activity = 1 AND status = 'waiting'", []);
                if ($r !== false) $apptsToApprove = (int)($r['cnt'] ?? 0);
            }
            // Also count calendar pending-approval events (pc_apptstatus='^')
            $r2 = sqlQuery("SELECT COUNT(*) AS cnt FROM openemr_postcalendar_events WHERE pc_apptstatus = '^' AND DATE(pc_eventDate) >= CURDATE()", []);
            if ($r2 !== false) $apptsToApprove += (int)($r2['cnt'] ?? 0);
        } catch (Throwable $e) { error_log("MDI appts approve: ".$e->getMessage()); }
        // Recalls due: medex_recalls table (r_eventDate <= today)
        $recallsDue = 0;
        try {
            if (function_exists('tableExists') && tableExists('medex_recalls')) {
                $r = sqlQuery("SELECT COUNT(*) AS cnt FROM medex_recalls WHERE r_eventDate <= CURDATE()", []);
                if ($r !== false) $recallsDue = (int)($r['cnt'] ?? 0);
            } elseif (function_exists('tableExists') && tableExists('openemr_recall')) {
                $r = sqlQuery("SELECT COUNT(*) AS cnt FROM openemr_recall WHERE recall_date <= CURDATE() AND recall_status = 0", []);
                if ($r !== false) $recallsDue = (int)($r['cnt'] ?? 0);
            }
        } catch (Throwable $e) { error_log("MDI recallsDue: ".$e->getMessage()); }

        // ── Unbilled charges ─────────────────────────────────────────────
        $unbilledCharges    = 0;
        $unbilledEncounters = 0;
        try {
            $ubRow = sqlQuery(
                "SELECT COUNT(DISTINCT encounter) AS encs, COALESCE(SUM(fee),0) AS amt
                 FROM billing WHERE billed = 0 AND activity = 1",
                []
            );
            if ($ubRow !== false) {
                $unbilledCharges    = (float)($ubRow['amt'] ?? 0);
                $unbilledEncounters = (int)($ubRow['encs'] ?? 0);
            }
        } catch (Throwable $e) { error_log("MDI unbilledCharges: " . $e->getMessage()); }

        // ── Total A/R — sum of all outstanding insurance + patient balances
        //    Uses the same logic as the aging query: fee - ins_pay - writeoffs
        $totalOutstanding = 0;
        try {
            $arRow = sqlQuery(
                "SELECT COALESCE(SUM(sub.insurance_balance),0) AS total_ar
                   FROM (
                       SELECT b.pid, b.encounter,
                              SUM(b.fee)
                              - COALESCE(SUM(CASE WHEN aa.payer_type = 1 THEN aa.pay_amount ELSE 0 END),0)
                              - COALESCE(SUM(CASE WHEN aa.account_code = 'WO' THEN aa.adj_amount ELSE 0 END),0)
                              AS insurance_balance
                         FROM billing b
                         LEFT JOIN ar_activity aa ON aa.pid=b.pid AND aa.encounter=b.encounter AND aa.deleted IS NULL
                        WHERE b.activity=1
                          AND (b.code_text IS NULL OR b.code_text NOT LIKE '%LEGACY%')
                        GROUP BY b.pid
                       HAVING insurance_balance > 0.01
                   ) sub",
                []
            );
            $totalOutstanding = (float)($arRow['total_ar'] ?? 0);
        } catch (Throwable $e) { error_log("MDI totalOutstanding: " . $e->getMessage()); }

        // ── Paid Today ────────────────────────────────────────────────────
        $paidToday = 0;
        $paidTodayCount = 0;
        try {
            $ptRow = sqlQuery(
                "SELECT COALESCE(SUM(pay_amount),0) AS amt, COUNT(*) AS cnt
                 FROM ar_activity WHERE DATE(post_time) = ? AND deleted IS NULL",
                [$today]
            );
            if ($ptRow !== false) {
                $paidToday      = (float)($ptRow['amt'] ?? 0);
                $paidTodayCount = (int)($ptRow['cnt'] ?? 0);
            }
        } catch (Throwable $e) { error_log("MDI paidToday: " . $e->getMessage()); }

        // ── Paid Today Detail (for popup) ─────────────────────────────────
        $paidTodayRows = [];
        try {
            $ptDetail = sqlStatement(
                "SELECT
                    a.pid,
                    a.session_id,
                    a.pay_amount,
                    a.post_time,
                    a.memo,
                    a.payer_type,
                    a.account_code,
                    CONCAT(pd.lname, ', ', pd.fname) AS patient,
                    s.reference AS check_ref
                 FROM ar_activity a
                 LEFT JOIN patient_data pd ON pd.pid = a.pid
                 LEFT JOIN ar_session s ON s.session_id = a.session_id
                 WHERE DATE(a.post_time) = ? AND a.deleted IS NULL AND a.pay_amount > 0
                 ORDER BY a.post_time DESC",
                [$today]
            );
            while ($r = sqlFetchArray($ptDetail)) {
                $paidTodayRows[] = $r;
            }
        } catch (Throwable $e) { error_log("MDI paidTodayDetail: " . $e->getMessage()); }

        // ── Paid This Month ───────────────────────────────────────────────
        $paidThisMonth = 0;
        $paidMonthCount = 0;
        try {
            $pmRow = sqlQuery(
                "SELECT COALESCE(SUM(aa.pay_amount),0) AS amt, COUNT(*) AS cnt
                 FROM ar_activity aa
                 WHERE YEAR(aa.post_time)=YEAR(CURDATE())
                   AND MONTH(aa.post_time)=MONTH(CURDATE())
                   AND aa.deleted IS NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM billing b
                       WHERE b.pid = aa.pid AND b.encounter = aa.encounter
                         AND b.code_text LIKE '%LEGACY%'
                   )",
                []
            );
            if ($pmRow !== false) {
                $paidThisMonth  = (float)($pmRow['amt'] ?? 0);
                $paidMonthCount = (int)($pmRow['cnt'] ?? 0);
            }
        } catch (Throwable $e) { error_log("MDI paidThisMonth: " . $e->getMessage()); }

        // ── Aging claims >30 days ─────────────────────────────────────────
        // Requires: claim actually transmitted (claims.status IN 2,3,5,6),
        // billed >30 days ago, and still has an open balance.
        $agingClaims = 0;
        try {
            $agRow = sqlQuery(
                "SELECT COUNT(*) AS cnt
                 FROM (
                    SELECT
                        b.pid,
            
                        SUM(b.fee)
            
                        - COALESCE(SUM(CASE
                            WHEN (
                                aa.payer_type = 1
                                OR COALESCE(s.payer_id,0) > 0
                                OR LOWER(COALESCE(s.payment_type,'')) LIKE '%insurance%'
                                OR LOWER(COALESCE(s.payment_method,'')) LIKE '%insurance%'
                            )
                            AND UPPER(COALESCE(aa.account_code,'')) NOT IN ('WO','TRANS','PR')
                            THEN aa.pay_amount + ABS(aa.adj_amount)
                            ELSE 0
                        END),0)
            
                        - COALESCE(SUM(CASE
                            WHEN UPPER(COALESCE(aa.account_code,'')) = 'WO'
                                 OR UPPER(COALESCE(aa.memo,'')) LIKE '%CONTRACT%'
                                 OR UPPER(COALESCE(aa.memo,'')) LIKE '%BAD_DEBT%'
                            THEN ABS(aa.adj_amount) + aa.pay_amount
                            ELSE 0
                        END),0)
            
                        - COALESCE(SUM(CASE
                            WHEN UPPER(COALESCE(aa.account_code,'')) = 'AJ'
                                 AND (
                                     aa.payer_type = 1
                                     OR COALESCE(s.payer_id,0) > 0
                                 )
                            THEN ABS(aa.adj_amount)
                            ELSE 0
                        END),0)
            
                        - COALESCE(SUM(CASE
                            WHEN UPPER(COALESCE(aa.account_code,'')) = 'TRANS'
                                 AND aa.payer_type = 1
                            THEN ABS(aa.adj_amount) + aa.pay_amount
                            ELSE 0
                        END),0)
            
                        + COALESCE(SUM(CASE
                            WHEN UPPER(COALESCE(aa.account_code,'')) = 'TRANS'
                                 AND aa.payer_type = 0
                            THEN ABS(aa.adj_amount) + aa.pay_amount
                            ELSE 0
                        END),0)
            
                        AS insurance_balance
            
                    FROM billing b
            
                    LEFT JOIN ar_activity aa
                           ON aa.pid = b.pid
                          AND aa.encounter = b.encounter
                          AND aa.deleted IS NULL
            
                    LEFT JOIN ar_session s
                           ON s.session_id = aa.session_id
            
                    WHERE b.billed = 1
                      AND b.activity = 1
                      AND b.bill_date <= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                      AND (b.code_text IS NULL OR b.code_text NOT LIKE '%LEGACY%')
            
                    GROUP BY b.pid
            
                    HAVING insurance_balance > 0.01
            
                 ) _aging",
                []
            );
            if ($agRow !== false) $agingClaims = (int)($agRow['cnt'] ?? 0);
        } catch (Throwable $e) { error_log("MDI agingClaims: " . $e->getMessage()); }

        // ── Unbilled encounters >7 days ───────────────────────────────────
        $unbilledOld = 0;
        try {
            $uoRow = sqlQuery(
                "SELECT COUNT(DISTINCT fe.encounter) AS cnt
                 FROM form_encounter fe
                 INNER JOIN billing b ON b.encounter = fe.encounter AND b.pid = fe.pid AND b.activity = 1 AND b.billed = 0
                 WHERE fe.date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   AND (b.code_text IS NULL OR b.code_text NOT LIKE '%LEGACY%')",
                []
            );
            if ($uoRow !== false) $unbilledOld = (int)($uoRow['cnt'] ?? 0);
        } catch (Throwable $e) { error_log("MDI unbilledOld: " . $e->getMessage()); }

        // ── Pending portal appointment requests (pc_apptstatus = '^') ──
        $pendingPortalAppts = [];
        try {
            $ppRes = sqlStatement(
                "SELECT e.pc_eid, e.pc_eventDate, e.pc_startTime, e.pc_duration,
                        e.pc_apptstatus, e.pc_catid, e.pc_hometext,
                        p.fname, p.lname, p.pid,
                        c.pc_catname
                 FROM openemr_postcalendar_events e
                 LEFT JOIN patient_data p ON p.pid = e.pc_pid
                 LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = e.pc_catid
                 WHERE e.pc_apptstatus = '^'
                   AND DATE(e.pc_eventDate) >= CURDATE()
                   AND e.pc_pid > 0
                 ORDER BY e.pc_eventDate ASC, e.pc_startTime ASC
                 LIMIT 20",
                []
            );
            if ($ppRes !== false) { while ($r = sqlFetchArray($ppRes)) { $pendingPortalAppts[] = $r; } }
        } catch (Throwable $mdiEx) { error_log("MDI pending portal appts: " . $mdiEx->getMessage()); }

        // ── Helper functions ──────────────────────────────────────────
        $statusMap = [
            ''  => ['Scheduled',    'mdi-sb-scheduled'],
            '-' => ['Scheduled',    'mdi-sb-scheduled'],
            '^' => ['Pending',      'mdi-sb-scheduled'],
            '*' => ['Arrived',      'mdi-sb-waiting'],
            '<' => ['Checked In',   'mdi-sb-waiting'],
            '@' => ['In Room',      'mdi-sb-inroom'],
            '~' => ['In Room',      'mdi-sb-inroom'],
            'S' => ['With Staff',   'mdi-sb-inroom'],
            'M' => ['With MD',      'mdi-sb-inroom'],
            '1' => ['With Nurse',   'mdi-sb-inroom'],
            '2' => ['With Doctor',  'mdi-sb-inroom'],
            '>' => ['Checked Out',  'mdi-sb-done'],
            '#' => ['Done',         'mdi-sb-done'],
            '?' => ['Done',         'mdi-sb-done'],
            '%' => ['Done',         'mdi-sb-done'],
            'x' => ['No Show',      'mdi-sb-noshow'],
            'y' => ['Cancelled',    'mdi-sb-noshow'],
        ];
        function mdiStatusLabel($s, $map) {
            return $map[$s] ?? ['Scheduled','mdi-sb-scheduled'];
        }
        function mdiBarClass($catname) {
            $n = strtolower($catname ?? '');
            if (strpos($n,'new') !== false)    return 'mdi-bar-new';
            if (strpos($n,'follow') !== false
             || strpos($n,'re-exam') !== false) return 'mdi-bar-followup';
            if (strpos($n,'urgent') !== false
             || strpos($n,'emerg') !== false)   return 'mdi-bar-urgent';
            if (strpos($n,'well') !== false
             || strpos($n,'annual') !== false)  return 'mdi-bar-wellness';
            return 'mdi-bar-new';
        }
        // ── Payments not posted ──────────────────────────────────────────────
        // ── Payments not posted: ar_session rows where global_amount not fully posted ──
        // ── Payments Not Posted: ar_session rows where payment is still unapplied ──
        // Matches the "Unapplied" status shown in search_payments.php.
        // undistributed = global_amount - (SUM pay_amount + SUM adj_amount from ar_activity)
        // A session is "unapplied" when undistributed > 0 (money not yet applied to encounters).
        // ── Payments Not Posted: count ar_session rows still unapplied ──────────
        // Matches the "Unapplied" rows in search_payments.php.
        // undistributed = global_amount - SUM(ar_activity pay + adj)
        // Sessions where undistributed > 0 = payment not fully applied to encounters.
        $paymentsNotPosted = 0;

try {
    $pnp = sqlQuery("
    SELECT COUNT(*) AS cnt
    FROM (
        SELECT 
            s.session_id,
            (
                s.pay_total
                - COALESCE(SUM(CASE 
                    WHEN a.deleted IS NULL 
                    THEN a.pay_amount 
                    ELSE 0 
                  END),0)
                - COALESCE(s.global_amount,0)
            ) AS available_amount
        FROM ar_session s
        LEFT JOIN ar_activity a 
            ON a.session_id = s.session_id
        WHERE s.pay_total > 0
          AND LOWER(COALESCE(s.payment_method,'')) IN (
                'patient_payment',
                'insurance_payment',
                'cash',
                'check',
                'credit_card',
                'eft',
                'ach',
                'money_order'
          )
        GROUP BY s.session_id, s.pay_total, s.global_amount
        HAVING available_amount > 0.01
    ) t
");

    if ($pnp !== false) {
        $paymentsNotPosted = (int)($pnp['cnt'] ?? 0);
    }

} catch (Throwable $e) {
    error_log('MDI paymentsNotPosted: ' . $e->getMessage());
}
        // ── ERA files to process: ar_session rows that came from ERA import ──
        $eraFilesToProcess = 0;
        try {
            // ERA sessions are identified by reference starting with ERA or having batch_date set
            $era = sqlQuery(
                "SELECT COUNT(*) AS cnt FROM ar_session
                  WHERE (reference LIKE 'ERA%' OR reference LIKE '%.835' OR reference LIKE '%.835.%')
                    AND global_amount > (
                        SELECT COALESCE(SUM(pay_amount),0) FROM ar_activity
                         WHERE ar_activity.session_id = ar_session.session_id
                           AND ar_activity.deleted IS NULL
                    )",
                []
            );
            if ($era !== false) $eraFilesToProcess = (int)($era['cnt'] ?? 0);
        } catch (Throwable $e) { error_log('MDI eraFiles: '.$e->getMessage()); }

        // ── Collections this week ─────────────────────────────────────────────
        $collectionsThisWeek = 0;
        try {
            // WEEKDAY(CURDATE()): 0=Mon...6=Sun — subtract to get Monday of current week
            $cw = sqlQuery(
                "SELECT COALESCE(SUM(pay_amount),0) AS total
                   FROM ar_activity
                  WHERE deleted IS NULL
                    AND DATE(post_time) >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)
                    AND DATE(post_time) <= CURDATE()",
                []
            );
            if ($cw !== false) $collectionsThisWeek = (float)($cw['total'] ?? 0);
        } catch (Throwable $e) { error_log('MDI collectionsWeek: '.$e->getMessage()); }

        $hour = (int)date('H');

        $greeting = $hour < 12 
            ? xl('Good Morning') 
            : ($hour < 17 
                ? xl('Good Afternoon') 
                : xl('Good Evening')
            );

        $providerName = text($userQuery['fname'] ?? 'Doctor');
        ?>
        <div id="mdi-dashboard">

            <div class="mdi-dash-topbar">
                <div class="mdi-dash-greeting"><?php echo $greeting; ?>, <span><?php echo $providerName; ?></span> 👋</div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button id="mdi-refresh-btn" onclick="_doRefresh(this)" title="Refresh dashboard data" class="mdi-chip-btn" style="display:flex;align-items:center;gap:5px;">
                        <svg id="mdi-refresh-icon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                        Refresh
                    </button>
                    <span class="mdi-date-badge"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px;"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>

            <!-- Stat Cards (live data) -->
            <div class="mdi-stat-row">
                <!-- Unbilled Charges -->
                <div class="mdi-stat-card mdi-stat-link" onclick="mdiNavGo('/interface/billing/billing_report.php')" style="cursor:pointer">
                    <div>
                        <div class="mdi-stat-label">Unbilled Charges</div>
                        <div class="mdi-stat-value" id="mdi-unbilled-val" style="color:var(--mdi-rose)">$<?php echo number_format($unbilledCharges,0); ?></div>
                        <div class="mdi-stat-sub" id="mdi-unbilled-sub"><span class="<?php echo $unbilledEncounters > 0 ? 'down' : 'up'; ?>"><?php echo $unbilledEncounters; ?> encounter<?php echo $unbilledEncounters != 1 ? 's' : ''; ?></span></div>
                    </div>
                    <div class="mdi-stat-icon mdi-si-rose">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    </div>
                </div>
                <!-- Paid Today -->
                <?php $today = date('Y-m-d'); ?>
                <div class="mdi-stat-card mdi-stat-link" onclick="mdiShowPaidToday()" title="View today's payments">
                    <div>
                        <div class="mdi-stat-label">Paid Today</div>
                        <div class="mdi-stat-value" id="mdi-paid-today-val" style="color:var(--mdi-green)">$<?php echo number_format($paidToday,0); ?></div>
                        <div class="mdi-stat-sub" id="mdi-paid-today-sub"><?php echo $paidTodayCount; ?> payment<?php echo $paidTodayCount != 1 ? 's' : ''; ?></div>
                    </div>
                    <div class="mdi-stat-icon mdi-si-teal">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="10" y2="15"/></svg>
                    </div>
                </div>
                <!-- Unsigned Notes -->
                <div class="mdi-stat-card mdi-stat-link" onclick="mdiNavGo('<?php echo $web_root; ?>/interface/main/tabs/mdi_visits.php?tab=unsigned')" style="cursor:pointer">
                    <div>
                        <div class="mdi-stat-label">Unsigned Notes</div>
                        <div class="mdi-stat-value"><?php echo $unsignedNotes; ?></div>
                        <div class="mdi-stat-sub"><?php echo $unsignedNotes > 0 ? '<span class="down">Needs attention</span>' : '<span class="up">All signed ✓</span>'; ?></div>
                    </div>
                    <div class="mdi-stat-icon mdi-si-purple">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    </div>
                </div>
                <!-- Portal Audits -->
                <div class="mdi-stat-card mdi-stat-link" onclick="mdiNavGo('/portal/patient/onsiteactivityviews')" style="cursor:pointer">
                    <div>
                        <div class="mdi-stat-label">Portal Audits</div>
                        <div class="mdi-stat-value" id="mdi-portal-audits-val"><?php echo $portalAudits; ?></div>
                        <div class="mdi-stat-sub" id="mdi-portal-audits-sub"><?php echo $portalAudits > 0 ? '<span class="warn">Pending review</span>' : 'All clear'; ?></div>
                    </div>
                    <div class="mdi-stat-icon mdi-si-amber">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                    </div>
                </div>
                <!-- Portal Payments -->
                <div class="mdi-stat-card mdi-stat-link" onclick="mdiNavGo('/portal/patient/onsiteactivityviews')">
                    <div>
                        <div class="mdi-stat-label">Portal Payments</div>
                        <div class="mdi-stat-value"><?php echo $portalPayments; ?></div>
                        <div class="mdi-stat-sub"><?php echo $portalPayments > 0 ? '<span class="warn">Awaiting posting</span>' : 'None pending'; ?></div>
                    </div>
                    <div class="mdi-stat-icon mdi-si-blue">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/><circle cx="12" cy="10" r="3"/></svg>
                    </div>
                </div>
            </div>

            <!-- Main Grid -->
            <div class="mdi-dash-grid">

                <!-- Schedule Panel (live) -->
                <div class="mdi-panel">
                    <div class="mdi-panel-header">
                        <div>
                            <div class="mdi-panel-title"><svg style="width:1em;height:1em;vertical-align:middle;color:var(--mdi-blue);margin-right:6px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="16" y2="14"/></svg>Today's Schedule</div>
                            <div class="mdi-panel-sub"><?php echo $totalAppts; ?> appointment<?php echo $totalAppts != 1 ? 's' : ''; ?> &middot; <?php echo date('l, F j'); ?></div>
                        </div>
                        <button class="mdi-chip-btn" onclick="mdiNavGo('/interface/main/main_info.php')"><svg style="width:1em;height:1em;vertical-align:middle;font-size:10px;margin-right:3px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>Open Scheduler</button>
                    </div>
                    <div class="mdi-schedule-list" id="mdi-schedule-list">
                        <?php if (empty($appts)): ?>
                            <div class="mdi-empty-state"><svg style="width:1em;height:1em;vertical-align:middle;color:var(--mdi-green);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><polyline points="9 12 11 14 15 10"/></svg> No appointments scheduled for today.</div>
                        <?php else: ?>
                            <?php foreach ($appts as $appt):
                                $t      = strtotime($appt['pc_startTime'] ?? '00:00:00');
                                $ampm   = date('g:i A', $t);
                                $dur    = (int)(($appt['pc_duration'] ?? 0) / 60);
                                // patient name: fetchAppointments returns fname/lname, fallback has patient_name
                                $ptName = isset($appt['patient_name']) && $appt['patient_name']
                                        ? $appt['patient_name']
                                        : trim(($appt['fname'] ?? '') . ' ' . ($appt['lname'] ?? ''));
                                $pid    = $appt['pid'] ?? 0;
                                [$slabel, $sclass] = mdiStatusLabel($appt['pc_apptstatus'] ?? '', $statusMap);
                                $barClass = mdiBarClass($appt['pc_catname'] ?? '');
                                $catLabel = $appt['pc_catname'] ?? 'Appointment';
                                $note     = $appt['pc_hometext'] ?? '';
                            ?>
                            <div class="mdi-schedule-item" onclick="mdiNavGo('/interface/patient_file/summary/demographics.php?set_pid=<?php echo (int)$pid; ?>')">
                                <div class="mdi-appt-time"><?php echo text($ampm); ?><div class="dur"><?php echo $dur; ?> min</div></div>
                                <div class="mdi-appt-bar <?php echo $barClass; ?>"></div>
                                <div>
                                    <div class="mdi-appt-name"><?php echo text($ptName); ?></div>
                                    <div class="mdi-appt-detail"><?php echo text($catLabel); ?><?php echo $note ? ' &middot; ' . text(substr($note,0,40)) : ''; ?></div>
                                </div>
                                <span class="mdi-status-badge <?php echo $sclass; ?>"><?php echo text($slabel); ?></span>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Required Panel (col 2) -->
                    <div class="mdi-panel mdi-action-panel" style="animation-delay:.25s">
                        <div class="mdi-panel-header">
                            <div>
                                <div class="mdi-panel-title"><svg style="width:1em;height:1em;vertical-align:middle;color:var(--mdi-amber);margin-right:6px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>Action Required</div>
                                <div class="mdi-panel-sub" id="mdi-ac-total"><?php echo ($msgsFollowup + $claimsToSend + $claimsRejected + $claimsDenied + $apptsToApprove + $recallsDue + $docsToAuth + $paymentsNotPosted + $unbilledOld + $agingClaims); ?> items need attention</div>
                            </div>
                        </div>
                        <div class="mdi-action-list">

                        <!-- Encounters to Sign -->
                        <div class="mdi-action-row <?php echo $unsignedNotes > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('<?php echo $web_root; ?>/interface/main/tabs/mdi_visits.php?tab=unsigned')">
                            <div class="mdi-action-icon" style="background:<?php echo $unsignedNotes > 0 ? 'var(--mdi-rose-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $unsignedNotes > 0 ? 'var(--mdi-rose)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-unsigned"><?php echo $unsignedNotes; ?></span>
                                <span class="mdi-action-label">Encounter<?php echo $unsignedNotes != 1 ? 's' : ''; ?> to Sign</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Messages Requiring Followup -->
                        <div class="mdi-action-row <?php echo $msgsFollowup > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('/interface/main/messages/messages.php?form_active=1')">
                            <div class="mdi-action-icon" style="background:<?php echo $msgsFollowup > 0 ? 'var(--mdi-violet-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $msgsFollowup > 0 ? 'var(--mdi-violet)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-msgs"><?php echo $msgsFollowup; ?></span>
                                <span class="mdi-action-label">Message<?php echo $msgsFollowup != 1 ? 's' : ''; ?> Requiring Followup</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Claims to Send -->
                        <div class="mdi-action-row <?php echo $claimsToSend > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('/interface/billing/billing_report.php')">
                            <div class="mdi-action-icon" style="background:<?php echo $claimsToSend > 0 ? 'var(--mdi-amber-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $claimsToSend > 0 ? 'var(--mdi-amber)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-claims_send"><?php echo $claimsToSend; ?></span>
                                <span class="mdi-action-label">Claim<?php echo $claimsToSend != 1 ? 's' : ''; ?> Ready to Send</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Rejected Claims -->
                        <div class="mdi-action-row <?php echo $claimsRejected > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('/interface/billing/billing_report.php?status=4')">
                            <div class="mdi-action-icon" style="background:<?php echo $claimsRejected > 0 ? 'var(--mdi-rose-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $claimsRejected > 0 ? 'var(--mdi-rose)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-claims_rej"><?php echo $claimsRejected; ?></span>
                                <span class="mdi-action-label">Rejected Claim<?php echo $claimsRejected != 1 ? 's' : ''; ?> Need Followup</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Appointments to Approve / Portal Patient Activities Audit -->
                        <div class="mdi-action-row <?php echo $apptsToApprove > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('/portal/patient/onsiteactivityviews')">
                            <div class="mdi-action-icon" style="background:<?php echo $apptsToApprove > 0 ? 'var(--mdi-blue-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $apptsToApprove > 0 ? 'var(--mdi-blue)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="9 16 11 18 15 14"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-appts"><?php echo $apptsToApprove; ?></span>
                                <span class="mdi-action-label">Appointment<?php echo $apptsToApprove != 1 ? 's' : ''; ?> to Approve</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Denied Claims removed -->

                        <!-- Recalls Due -->
                        <div class="mdi-action-row <?php echo $recallsDue > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('/interface/main/messages/messages.php?go=Recalls')">
                            <div class="mdi-action-icon" style="background:<?php echo $recallsDue > 0 ? 'var(--mdi-teal-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $recallsDue > 0 ? 'var(--mdi-teal)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.8a19.79 19.79 0 01-3.07-8.63A2 2 0 012 .18h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 14.92z"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-recalls"><?php echo $recallsDue; ?></span>
                                <span class="mdi-action-label">Recall<?php echo $recallsDue != 1 ? 's' : ''; ?> Need to Be Made</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Documents to Authorize -->
                        <div class="mdi-action-row <?php echo $docsToAuth > 0 ? 'has-items' : 'clear'; ?>" id="mdi-docs-auth-row" onclick="mdiNavGo('/portal/patient/onsiteactivityviews')">
                            <div class="mdi-action-icon" id="mdi-docs-auth-icon" style="background:<?php echo $docsToAuth > 0 ? 'var(--mdi-blue-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $docsToAuth > 0 ? 'var(--mdi-blue)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><polyline points="9 12 11 14 15 10"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-docs-auth-val"><?php echo $docsToAuth; ?></span>
                                <span class="mdi-action-label" id="mdi-docs-auth-lbl">Document<?php echo $docsToAuth != 1 ? 's' : ''; ?> to Authorize</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Payments to Post (unapplied ar_session) -->
                        <div class="mdi-action-row <?php echo $paymentsNotPosted > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('/interface/billing/search_payments.php')">
                            <div class="mdi-action-icon" style="background:<?php echo $paymentsNotPosted > 0 ? 'var(--mdi-teal-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $paymentsNotPosted > 0 ? 'var(--mdi-teal)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-pnp"><?php echo $paymentsNotPosted; ?></span>
                                <span class="mdi-action-label">Payment<?php echo $paymentsNotPosted != 1 ? 's' : ''; ?> to Post</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Unbilled Encounters >7 Days -->
                        <div class="mdi-action-row <?php echo $unbilledOld > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('/interface/billing/billing_report.php')">
                            <div class="mdi-action-icon" style="background:<?php echo $unbilledOld > 0 ? 'var(--mdi-amber-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $unbilledOld > 0 ? 'var(--mdi-amber)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-unbilled_old"><?php echo $unbilledOld; ?></span>
                                <span class="mdi-action-label">Unbilled Encounter<?php echo $unbilledOld != 1 ? 's' : ''; ?> &gt;7 Days</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        <!-- Aging Claims >30 Days -->
                        <div class="mdi-action-row <?php echo $agingClaims > 0 ? 'has-items' : 'clear'; ?>" onclick="mdiNavGo('/interface/billing/mdi_aging_claims.php')" style="display:none;">
                            <div class="mdi-action-icon" style="background:<?php echo $agingClaims > 0 ? 'var(--mdi-rose-d)' : 'var(--mdi-green-d)'; ?>;color:<?php echo $agingClaims > 0 ? 'var(--mdi-rose)' : 'var(--mdi-green)'; ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                            </div>
                            <div class="mdi-action-text">
                                <span class="mdi-action-count" id="mdi-ac-aging"><?php echo $agingClaims; ?></span>
                                <span class="mdi-action-label">Aging Claim<?php echo $agingClaims != 1 ? 's' : ''; ?> &gt;30 Days</span>
                            </div>
                            <svg class="mdi-action-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>

                        </div><!-- /mdi-action-list -->
                    </div>

                <!-- Right Column (col 3) -->
                <div class="mdi-right-col">

                    <!-- Aging Claims Summary with donut -->
                    <?php
                    // Fetch per-bucket counts and balances for the donut chart
                    if (!function_exists('mdi_aging_bucket_data')) {
                    // min_days = lower bound: bill must be AT LEAST this old (b.bill_date <= N days ago)
                    // max_days = upper bound: bill must be LESS THAN this old (b.bill_date > M days ago)
                    // Produces non-overlapping buckets — each encounter counted in exactly one.
                    function mdi_aging_bucket_data($min_days, $max_days = null) {
                        $lower = ' AND b.bill_date <= DATE_SUB(CURDATE(), INTERVAL ' . (int)$min_days . ' DAY)';
                        $upper = ($max_days !== null)
                            ? ' AND b.bill_date > DATE_SUB(CURDATE(), INTERVAL ' . (int)$max_days . ' DAY)'
                            : '';
                        try {
                            $r = sqlQuery(
                                "SELECT COUNT(*) AS cnt,
                                        COALESCE(SUM(sub.insurance_balance),0) AS total_bal
                                   FROM (
                                       SELECT b.pid,
                            
                                              SUM(b.fee)
                            
                                              - COALESCE(SUM(CASE
                                                  WHEN (
                                                      aa.payer_type = 1
                                                      OR COALESCE(s.payer_id,0) > 0
                                                      OR LOWER(COALESCE(s.payment_type,'')) LIKE '%insurance%'
                                                      OR LOWER(COALESCE(s.payment_method,'')) LIKE '%insurance%'
                                                  )
                                                  AND UPPER(COALESCE(aa.account_code,'')) NOT IN ('WO','TRANS','PR')
                                                  THEN aa.pay_amount + ABS(aa.adj_amount)
                                                  ELSE 0
                                              END),0)
                            
                                              - COALESCE(SUM(CASE
                                                  WHEN UPPER(COALESCE(aa.account_code,'')) = 'WO'
                                                       OR UPPER(COALESCE(aa.memo,'')) LIKE '%CONTRACT%'
                                                       OR UPPER(COALESCE(aa.memo,'')) LIKE '%BAD_DEBT%'
                                                  THEN ABS(aa.adj_amount) + aa.pay_amount
                                                  ELSE 0
                                              END),0)
                            
                                              - COALESCE(SUM(CASE
                                                  WHEN UPPER(COALESCE(aa.account_code,'')) = 'AJ'
                                                       AND (
                                                           aa.payer_type = 1
                                                           OR COALESCE(s.payer_id,0) > 0
                                                       )
                                                  THEN ABS(aa.adj_amount)
                                                  ELSE 0
                                              END),0)
                            
                                              - COALESCE(SUM(CASE
                                                  WHEN UPPER(COALESCE(aa.account_code,'')) = 'TRANS'
                                                       AND aa.payer_type = 1
                                                  THEN ABS(aa.adj_amount) + aa.pay_amount
                                                  ELSE 0
                                              END),0)
                            
                                              + COALESCE(SUM(CASE
                                                  WHEN UPPER(COALESCE(aa.account_code,'')) = 'TRANS'
                                                       AND aa.payer_type = 0
                                                  THEN ABS(aa.adj_amount) + aa.pay_amount
                                                  ELSE 0
                                              END),0)
                            
                                              AS insurance_balance
                            
                                         FROM billing b
                            
                                         LEFT JOIN ar_activity aa
                                                ON aa.pid = b.pid
                                               AND aa.encounter = b.encounter
                                               AND aa.deleted IS NULL
                            
                                         LEFT JOIN ar_session s
                                                ON s.session_id = aa.session_id
                            
                                        WHERE b.billed = 1
                                          AND b.activity = 1"
                                          . $lower . $upper .
                                        " AND (b.code_text IS NULL OR b.code_text NOT LIKE '%LEGACY%')
                            
                                        GROUP BY b.pid
                            
                                        HAVING insurance_balance > 0.01
                            
                                   ) sub",
                                []
                            );
                            
                            return [
                                'cnt' => (int)($r['cnt'] ?? 0),
                                'bal' => (float)($r['total_bal'] ?? 0)
                            ];
                        } catch (Throwable $e) {
                            error_log('MDI aging_bucket: ' . $e->getMessage());
                            return ['cnt' => 0, 'bal' => 0.0];
                        }
                    }
                    } // end function_exists guard
                    // Non-overlapping buckets — each encounter in exactly ONE bucket
                    $ag_b31  = mdi_aging_bucket_data(31,  60);  // 31-60 days
                    $ag_b61  = mdi_aging_bucket_data(61,  90);  // 61-90 days
                    $ag_b91  = mdi_aging_bucket_data(91, 120);  // 91-120 days
                    $ag_b121 = mdi_aging_bucket_data(121);       // 120+ days
                    // Total aging = >30 days only — MUST equal $agingClaims (same query logic)
                    $ag_total_cnt = $ag_b31['cnt'] + $ag_b61['cnt'] + $ag_b91['cnt'] + $ag_b121['cnt'];
                    $ag_total_bal = $ag_b31['bal'] + $ag_b61['bal'] + $ag_b91['bal'] + $ag_b121['bal'];
                    ?>
                    <div class="mdi-panel" style="animation-delay:.30s">
                        <div class="mdi-panel-header">
                            <div>
                                <div class="mdi-panel-title">
                                    <svg style="width:1em;height:1em;vertical-align:middle;color:var(--mdi-rose);margin-right:6px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                                    Aging Claims Summary
                                </div>
                                <div class="mdi-panel-sub"><?php echo number_format($ag_total_cnt); ?> open claims</div>
                            </div>
                            <button class="mdi-chip-btn" onclick="mdiNavGo('/interface/billing/mdi_aging_claims.php')">View Work Queue</button>
                        </div>

                        <!-- Donut chart + legend side by side -->
                        <div style="display:flex;align-items:center;gap:14px;padding:14px 16px;">
                            <!-- SVG donut -->
                            <?php
                            $buckets = [
                                ['120+ Days', $ag_b121['cnt'], '#ef4444', $ag_b121['bal']],
                                ['91-120 Days',$ag_b91['cnt'], '#f59e0b', $ag_b91['bal']],
                                ['61-90 Days', $ag_b61['cnt'], '#eab308', $ag_b61['bal']],
                                ['31-60 Days', $ag_b31['cnt'], '#3b82f6', $ag_b31['bal']],
                                // Note: donut total = ag_b31+ag_b61+ag_b91+ag_b121 (matches $agingClaims)
                            ];
                            $donut_total = array_sum(array_column($buckets, 1)) ?: 1;
                            $r_outer=54; $r_inner=34; $cx=60; $cy=60;
                            $stroke_w = $r_outer - $r_inner;
                            $r_mid = ($r_outer + $r_inner) / 2;
                            $circumference = 2 * M_PI * $r_mid;
                            $offset = 0;
                            $paths = '';
                            foreach ($buckets as [$blabel, $bcount, $bcolor, $bbal]) {
                                $pct = $bcount / $donut_total;
                                $dash = $pct * $circumference;
                                $gap  = $circumference - $dash;
                                $paths .= '<circle r="'.round($r_mid,2).'" cx="'.$cx.'" cy="'.$cy.'" '
                                        . 'fill="none" stroke="'.$bcolor.'" stroke-width="'.$stroke_w.'" '
                                        . 'stroke-dasharray="'.round($dash,2).' '.round($gap,2).'" '
                                        . 'stroke-dashoffset="'.round(-$offset * $circumference,2).'" '
                                        . 'transform="rotate(-90 '.$cx.' '.$cy.')" />';
                                $offset += $pct;
                            }
                            ?>
                            <div style="position:relative;flex-shrink:0;width:120px;height:120px;">
                                <svg width="120" height="120" viewBox="0 0 120 120">
                                    <circle r="<?php echo $r_mid; ?>" cx="60" cy="60" fill="none"
                                        stroke="#f3f4f6" stroke-width="<?php echo $stroke_w; ?>"/>
                                    <?php echo $paths; ?>
                                </svg>
                                <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                                    <div style="font-size:1.35rem;font-weight:800;color:var(--mdi-navy);line-height:1;"><?php echo number_format($ag_total_cnt); ?></div>
                                    <div style="font-size:.58rem;color:var(--mdi-muted);font-weight:600;letter-spacing:.03em;">Total<br>Claims</div>
                                </div>
                            </div>

                            <!-- Legend -->
                            <div style="flex:1;display:flex;flex-direction:column;gap:6px;">
                                <?php foreach ($buckets as [$blabel, $bcount, $bcolor, $bbal]): ?>
                                <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;">
                                    <div style="display:flex;align-items:center;gap:6px;">
                                        <div style="width:10px;height:10px;border-radius:50%;background:<?php echo $bcolor; ?>;flex-shrink:0;"></div>
                                        <span style="font-size:11px;color:var(--mdi-text2);"><?php echo text($blabel); ?></span>
                                    </div>
                                    <span style="font-size:11px;font-weight:700;color:var(--mdi-navy);min-width:28px;text-align:right;"><?php echo number_format($bcount); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>

                    <!-- Billing Snapshot MTD -->
                    <?php
                    $mtd_start = date('Y-m-01');
                    $mtd_end   = date('Y-m-d');
                    $mtd_charges = $mtd_payments = $mtd_adj = 0;
                    try {
                        $mc = sqlQuery(
                            "SELECT COALESCE(SUM(b.fee),0) AS charges
                               FROM billing b
                               JOIN form_encounter fe ON fe.encounter=b.encounter AND fe.pid=b.pid
                              WHERE b.activity=1 AND DATE(fe.date) BETWEEN ? AND ?",
                            [$mtd_start,$mtd_end]
                        );
                        $mtd_charges = (float)($mc['charges']??0);

                        $mp = sqlQuery(
                            "SELECT COALESCE(SUM(pay_amount),0) AS pmts,
                                    COALESCE(SUM(CASE WHEN account_code IN ('WO','AJ','CO') THEN adj_amount ELSE 0 END),0) AS adjs
                               FROM ar_activity
                              WHERE deleted IS NULL AND DATE(post_time) BETWEEN ? AND ?",
                            [$mtd_start,$mtd_end]
                        );
                        $mtd_payments = (float)($mp['pmts']??0);
                        $mtd_adj      = abs((float)($mp['adjs']??0));
                    } catch(Throwable $e){}
                    $mtd_net = $mtd_payments; // Net collections = payments received
                    ?>
                    <div class="mdi-panel" style="animation-delay:.33s">
                        <div class="mdi-panel-header">
                            <div>
                                <div class="mdi-panel-title">
                                    <svg style="width:1em;height:1em;vertical-align:middle;color:var(--mdi-green);margin-right:6px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11L2 12v6a2 2 0 002 2h16a2 2 0 002-2v-6l-3.45-6.89A2 2 0 0016.76 4H7.24a2 2 0 00-1.79 1.11z"/></svg>
                                    Billing Snapshot (MTD)
                                </div>
                                <div class="mdi-panel-sub"><?php echo date('F 1'); ?> &ndash; <?php echo date('F j, Y'); ?></div>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-top:1px solid var(--mdi-border);">
                            <?php
                            $snaps = [
                                ['Charges',        $mtd_charges,  'var(--mdi-navy)',   false],
                                ['Payments',       $mtd_payments, 'var(--mdi-navy)',   false],
                                ['Adjustments',    $mtd_adj,      'var(--mdi-navy)',   false],
                                ['Net Collections',$mtd_net,      'var(--mdi-green)',  true],
                            ];
                            foreach ($snaps as $i => [$slabel,$sval,$scolor,$sbold]):
                            ?>
                            <div style="padding:12px 10px;text-align:center;<?php echo $i<3?'border-right:1px solid var(--mdi-border);':''; ?>">
                                <div style="font-size:.55rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--mdi-muted);margin-bottom:4px;"><?php echo text($slabel); ?></div>
                                <div style="font-size:.85rem;font-weight:<?php echo $sbold?800:700;?>;color:<?php echo $scolor; ?>;font-family:monospace;">$<?php echo number_format($sval,2); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="mdi-panel" style="animation-delay:.35s">
                        <div class="mdi-panel-header">
                            <div>
                                <div class="mdi-panel-title"><svg style="width:1em;height:1em;vertical-align:middle;color:var(--mdi-green);margin-right:6px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>Quick Actions</div>
                                <div class="mdi-panel-sub">Jump to common tasks</div>
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:4px;padding:10px 8px;">
                            <?php
                            $qaItems = [
                                ['Do Billing',        '/interface/billing/billing_report.php',    'var(--mdi-violet)', 'var(--mdi-violet-d)', '<path d="M12 1a3 3 0 103 3h3a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h3a3 3 0 013-3z"/>'],
                                ['Sign Notes',        '/interface/forms/notes/new.php',           'var(--mdi-teal)',   'var(--mdi-teal-d)',   '<path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/>'],
                                ['Post Payment',      '/interface/billing/new_payment.php',       'var(--mdi-blue)',   'var(--mdi-blue-d)',   '<rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/>'],
                                ['Collections Report','/interface/reports/collections_report.php','var(--mdi-rose)',   'var(--mdi-rose-d)',   '<polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/>'],
                            ];
                            foreach ($qaItems as $qa):
                                $qaOnclick = ($qa[0] === 'Sign Notes')
                                    ? "mdiSignNotesGuard('" . attr($qa[1]) . "')"
                                    : "mdiNavGo('" . attr($qa[1]) . "')";
                            ?>
                            <div onclick="<?php echo $qaOnclick; ?>"
                                 style="display:flex;flex-direction:column;align-items:center;gap:4px;padding:8px 4px;border-radius:10px;cursor:pointer;transition:background .12s;text-align:center;"
                                 onmouseover="this.style.background='var(--mdi-surface2)'" onmouseout="this.style.background=''">
                                <div style="width:38px;height:38px;border-radius:10px;background:<?php echo $qa[3]; ?>;display:flex;align-items:center;justify-content:center;">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?php echo $qa[2]; ?>" stroke-width="2"><?php echo $qa[4]; ?></svg>
                                </div>
                                <span style="font-size:.58rem;color:var(--mdi-text2);font-weight:600;line-height:1.2;"><?php echo text($qa[0]); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                </div><!-- /right-col -->
            </div><!-- /dash-grid -->

            <!-- ── Bottom Row: Recent Activity ── -->
            <?php
            // Recent activity feed
            $recentActivity = [];
            try {
                $raRes = sqlStatement(
                    "SELECT aa.pid, aa.pay_amount, aa.adj_amount, aa.memo, aa.post_time,
                            aa.account_code, ars.reference, ars.payment_type,
                            CONCAT(pd.lname, ' ', pd.fname) AS patient_name,
                            ic.name AS ins_name
                       FROM ar_activity aa
                       JOIN ar_session ars ON ars.session_id = aa.session_id
                       LEFT JOIN patient_data pd ON pd.pid = aa.pid
                       LEFT JOIN insurance_companies ic ON ic.id = ars.payer_id
                      WHERE aa.deleted IS NULL
                        AND DATE(aa.post_time) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                        AND (aa.pay_amount > 0 OR aa.adj_amount != 0)
                      ORDER BY aa.post_time DESC LIMIT 6",
                    []
                );
                if ($raRes !== false) { while ($ra = sqlFetchArray($raRes)) { $recentActivity[] = $ra; } }
            } catch(Throwable $e){}
            ?>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.85rem;margin-top:.85rem;">
                <!-- Recent Activity — fixed height to match stacked right col -->
                <div style="background:var(--mdi-surface);border-radius:12px;padding:0;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;display:flex;flex-direction:column;">
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--mdi-border);">
                        <div>
                            <div style="font-size:13px;font-weight:700;color:var(--mdi-navy);display:flex;align-items:center;gap:6px;">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--mdi-blue)" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                Recent Activity
                            </div>
                            <div style="font-size:11px;color:var(--mdi-muted);margin-top:1px;">Latest system activity</div>
                        </div>
                        <button class="mdi-chip-btn" onclick="mdiNavGo('/interface/reports/collections_report.php')">View All Activity →</button>
                    </div>
                    <div style="flex:1;overflow-y:auto;max-height:320px;">
                    <?php if (empty($recentActivity)): ?>
                    <div style="padding:24px;text-align:center;color:var(--mdi-muted);font-size:12px;">No recent activity.</div>
                    <?php else: foreach ($recentActivity as $ra):
                        $ra_time = $ra['post_time'] ? date('g:i A', strtotime($ra['post_time'])) : '';
                        $ra_pid  = (int)($ra['pid'] ?? 0);
                        $ra_name = strtoupper($ra['patient_name'] ?: 'Unknown');
                        $ra_ac   = strtoupper($ra['account_code'] ?? '');
                        $ra_link =
                        "<a onclick=\"mdiNavGo('/interface/patient_file/summary/demographics.php?set_pid={$ra_pid}')\"
                        style=\"color:var(--mdi-blue);cursor:pointer;font-weight:600;text-decoration:none;\">"
                        . text($ra_name) .
                        "</a>";
                        if ($ra_ac === 'WO') {
                            $ra_desc = 'Write-off posted for ' . $ra_link;
                            $ra_amt  = '–$' . number_format(abs((float)$ra['adj_amount']), 2);
                        } elseif (!empty($ra['ins_name'])) {
                            $ra_desc = 'EOB posted for ' . $ra_link . ' (' . text($ra['ins_name']) . ')';
                            $ra_amt  = '–$' . number_format((float)$ra['pay_amount'], 2);
                        } else {
                            $ra_desc = 'Payment posted for ' . $ra_link;
                            $ra_amt  = '–$' . number_format((float)$ra['pay_amount'], 2);
                        }
                    ?>
                    <div style="display:flex;align-items:center;gap:10px;padding:9px 16px;border-bottom:1px solid var(--mdi-border);font-size:12px;">
                        <span style="color:var(--mdi-muted);white-space:nowrap;min-width:52px;font-size:11px;"><?php echo text($ra_time); ?></span>
                        <span style="flex:1;color:var(--mdi-text2);"><?php echo $ra_desc; ?> <?php echo text($ra_amt); ?></span>
                    </div>
                    <?php endforeach; endif; ?>
                    </div><!-- /scroll list -->
                </div>

                <!-- Collections this week + Total A/R (stacked) -->
                <div style="display:flex;flex-direction:column;gap:.85rem;">

                <!-- Collections (This Week) -->
                <div style="background:var(--mdi-surface);border-radius:12px;padding:1.1rem 1.3rem;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:44px;height:44px;border-radius:10px;background:var(--mdi-green-d);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--mdi-green)" stroke-width="2"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                        </div>
                        <div>
                            <div style="font-size:.55rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mdi-muted);margin-bottom:2px;">Collections (This Week)</div>
                            <div style="font-size:1.9rem;font-weight:800;color:var(--mdi-green);line-height:1;">$<?php echo number_format($collectionsThisWeek,0); ?></div>
                            <div style="font-size:.68rem;color:var(--mdi-muted);">Total collected</div>
                        </div>
                    </div>
                    <div style="margin-top:.7rem;padding-top:.6rem;border-top:1px solid var(--mdi-border);">
                        <span onclick="mdiNavGo('/interface/reports/collections_report.php')" style="font-size:.7rem;font-weight:700;color:var(--mdi-green);cursor:pointer;">View Report &rarr;</span>
                    </div>
                </div>

                <!-- Total A/R -->
                <div style="background:var(--mdi-surface);border-radius:12px;padding:1.1rem 1.3rem;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                    <div style="display:flex;align-items:center;gap:12px;">
                        <div style="width:44px;height:44px;border-radius:10px;background:var(--mdi-amber-d);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--mdi-amber)" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
                        </div>
                        <div>
                            <div style="font-size:.55rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--mdi-muted);margin-bottom:2px;">Total A/R</div>
                            <div style="font-size:1.9rem;font-weight:800;color:var(--mdi-amber);line-height:1;">$<?php echo number_format($totalOutstanding,0); ?></div>
                            <div style="font-size:.68rem;color:var(--mdi-muted);">Outstanding balance</div>
                        </div>
                    </div>
                    <div style="margin-top:.7rem;padding-top:.6rem;border-top:1px solid var(--mdi-border);">
                        <span onclick="mdiNavGo('/interface/reports/collections_report.php')" style="font-size:.7rem;font-weight:700;color:var(--mdi-amber);cursor:pointer;">View A/R Report &rarr;</span>
                    </div>
                </div>

                </div><!-- /stacked right -->
            </div><!-- /bottom row -->

        </div><!-- /#mdi-dashboard -->

        <div class="body_title" id="tabs_div" data-bind="template: {name: 'tabs-controls', data: application_data}"></div>
        <div class="mainFrames d-flex flex-row" id="mainFrames_div" style="position:relative;flex:1;min-height:0;">
            <div id="framesDisplay" data-bind="template: {name: 'tabs-frames', data: application_data}"></div>
        </div>
    </div>
    <script>
        ko.applyBindings(app_view_model);

        // Mark Dashboard chip active and hide frames/tabs on load
        (function() {
            var chip = document.getElementById('mdi-dash-chip');
            if (chip) chip.classList.add('mdi-nav-chip-active');
            var fd2 = document.getElementById('framesDisplay');
            if (fd2) fd2.style.visibility = 'hidden';
            var td2 = document.getElementById('tabs_div');
            if (td2) td2.style.visibility = 'hidden';
        })();

        // ── Reports dropdown helpers ───────────────────────────────────
        function mdiCloseReportsDD() {
            var dd = document.getElementById('mdi-reports-chip-dd');
            if (dd) dd.classList.remove('open');
        }
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            var dd = document.getElementById('mdi-reports-chip-dd');
            if (dd && !dd.contains(e.target)) dd.classList.remove('open');
        });

        // ── Sync Portal Audits + Documents counts from KO observables ──
        // portalAudits() is updated by the repeater service (same data as the Portal badge).
        // We poll until the repeater has run (up to 5s) then update the dashboard tiles.
        (function mdiSyncPortalCounts() {
            var attempts = 0;
            var interval = setInterval(function() {
                attempts++;
                try {
                    var u = app_view_model.application_data.user();
                    var audits = parseInt(u.portalAudits()) || 0;
                    var total  = parseInt(u.portalAlerts())  || 0;
                    // Only update once we have a real value OR after 5s
                    if (audits > 0 || total > 0 || attempts > 50) {
                        clearInterval(interval);
                        // Portal Audits stat card
                        var valEl = document.getElementById('mdi-portal-audits-val');
                        var subEl = document.getElementById('mdi-portal-audits-sub');
                        if (valEl) valEl.textContent = audits;
                        if (subEl) subEl.innerHTML = audits > 0
                            ? '<span style="color:var(--mdi-amber);font-weight:600;">Pending review</span>'
                            : 'All clear';
                        // Documents to Authorize — use portalAlerts total as proxy
                        // (total = audits + mail + payments; documents show in audit flow)
                        var docVal  = document.getElementById('mdi-docs-auth-val');
                        var docLbl  = document.getElementById('mdi-docs-auth-lbl');
                        var docRow  = document.getElementById('mdi-docs-auth-row');
                        var docIcon = document.getElementById('mdi-docs-auth-icon');
                        var docs    = parseInt(u.portalAudits()) || 0; // same feed includes doc activity
                        if (docVal)  docVal.textContent  = docs;
                        if (docLbl)  docLbl.textContent  = docs !== 1 ? 'Documents to Authorize' : 'Document to Authorize';
                        if (docRow)  { docRow.className  = 'mdi-action-row ' + (docs > 0 ? 'has-items' : 'clear'); }
                        if (docIcon) {
                            docIcon.style.background = docs > 0 ? 'var(--mdi-blue-d)' : 'var(--mdi-green-d)';
                            docIcon.style.color      = docs > 0 ? 'var(--mdi-blue)'   : 'var(--mdi-green)';
                        }
                        // ── Update nav bar badges ──────────────────────────────
                        var mail = parseInt(u.portalMail()) || 0;
                        var msgBadge  = document.getElementById('mdi-msg-nav-badge');
                        var authBadge = document.getElementById('mdi-auth-nav-badge');
                        if (msgBadge) {
                            msgBadge.textContent = mail;
                            msgBadge.style.display = mail > 0 ? 'flex' : 'none';
                        }
                        if (authBadge) {
                            authBadge.textContent = audits;
                            authBadge.style.display = audits > 0 ? 'flex' : 'none';
                        }
                    }
                } catch(e) { clearInterval(interval); }
            }, 100);
        })();

        $(function() {
            $('.dropdown-toggle').dropdown();
            $('#patient_caret').click(function() {
                $('#attendantData').slideToggle();
                var svg = document.getElementById('patient_caret_svg');
                if (svg) {
                    var isDown = svg.getAttribute('data-dir') !== 'up';
                    svg.setAttribute('data-dir', isDown ? 'up' : 'down');
                    svg.innerHTML = isDown
                        ? '<polyline points="18 15 12 9 6 15"/>'
                        : '<polyline points="6 9 12 15 18 9"/>';
                }
            });
            if ($('body').css('direction') == "rtl") {
                $('.dropdown-menu-right').each(function() {
                    $(this).removeClass('dropdown-menu-right');
                });
            }
        });
        $(function() {
            $('#logo_menu').focus();
        });
        $('#anySearchBox').keypress(function(event) {
            if (event.which === 13 || event.keyCode === 13) {
                event.preventDefault();
                $('#search_globals').mousedown();
            }
        });
        document.addEventListener('touchstart', {}); //specifically added for iOS devices, especially in iframes
        $(function() {
            goRepeaterServices();
        });
    </script>
    <script>
        function eSign(section) {
    // Legacy stub — now handled by signEntireEncounter
  }

  function signEntireEncounter() {
    const btn = document.getElementById('esign-main-btn');
    const amcWarn = document.getElementById('amc-warning');

    // Animate button to loading
    btn.innerHTML = `<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="animation:spin .7s linear infinite"><path d="M21 12a9 9 0 1 1-6.22-8.56"/></svg> Signing…`;
    btn.style.opacity = '.8';
    btn.style.pointerEvents = 'none';

    // Step through each section check with staggered timing
    setTimeout(() => {
      document.getElementById('check-visit').classList.add('confirmed');
    }, 500);
    setTimeout(() => {
      document.getElementById('check-soap').classList.add('confirmed');
    }, 900);
    setTimeout(() => {
      document.getElementById('check-painmap').classList.add('confirmed');
      amcWarn.style.display = 'none';
    }, 1300);

    // Final signed state
    setTimeout(() => {
      const now = new Date().toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'numeric',minute:'2-digit'});

      btn.innerHTML = `<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Signed`;
      btn.style.background = 'linear-gradient(135deg,#047857,#059669)';
      btn.style.boxShadow = '0 4px 14px rgba(5,150,105,.35)';
      btn.style.opacity = '1';

      document.getElementById('esign-status-label').innerHTML = `
        <svg width="14" height="14" fill="none" stroke="#059669" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <span style="color:#059669;font-weight:600">Signed by Garza, Oliver · ${now}</span>
      `;

      // Update overall status chip
      const chip = document.getElementById('overall-sig-chip');
      chip.querySelector('.status-dot').style.background = '#059669';
      chip.querySelector('.status-dot').style.boxShadow = '0 0 0 3px #d1fae5';
      chip.childNodes[chip.childNodes.length - 1].textContent = ' Signed';

      // Lock all edit buttons
      document.querySelectorAll('.btn-outline').forEach(b => {
        if (b.textContent.trim() === 'Edit') {
          b.style.opacity = '.4';
          b.style.pointerEvents = 'none';
          b.title = 'Encounter is signed — editing locked';
        }
      });

      showToast('Encounter eSigned successfully!');
    }, 1800);
  }

        </script>
    <?php
    // fire off an event here
    if (!empty($GLOBALS['kernel']->getEventDispatcher())) {
        /**
         * @var \Symfony\Component\EventDispatcher\EventDispatcher
         */
        $dispatcher = $GLOBALS['kernel']->getEventDispatcher();
        $dispatcher->dispatch(new RenderEvent(), RenderEvent::EVENT_BODY_RENDER_POST);
    }
    ?>

<!-- ── Paid Today Modal ──────────────────────────────────────────────── -->
<div id="mdiPaidTodayModal" style="display:none;position:fixed;inset:0;z-index:99999;align-items:center;justify-content:center;">
    <div onclick="mdiClosePaidToday()" style="position:absolute;inset:0;background:rgba(10,5,25,0.55);backdrop-filter:blur(2px)"></div>
    <div style="position:relative;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.25);width:min(680px,95vw);max-height:80vh;display:flex;flex-direction:column;overflow:hidden;animation:mdiModalIn 0.2s ease">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem 0.75rem;border-bottom:1px solid var(--mdi-border);background:linear-gradient(135deg,#f0fdf4,#ffffff)">
            <div>
                <div style="font-size:0.6rem;letter-spacing:0.12em;text-transform:uppercase;color:var(--mdi-green);font-weight:700;margin-bottom:2px">Today's Collections</div>
                <div style="font-size:1.4rem;font-weight:700;color:var(--mdi-navy);letter-spacing:-0.02em" id="mdi-pt-modal-total">
                    $<?php echo number_format($paidToday, 2); ?>
                    <span style="font-size:0.75rem;font-weight:500;color:var(--mdi-muted);margin-left:6px"><?php echo $paidTodayCount; ?> payment<?php echo $paidTodayCount != 1 ? 's' : ''; ?></span>
                </div>
            </div>
            <button onclick="mdiClosePaidToday()" style="background:none;border:none;cursor:pointer;color:var(--mdi-muted);padding:4px;border-radius:6px;line-height:1;font-size:1.1rem" title="Close">✕</button>
        </div>
        <!-- Search -->
        <div style="padding:0.6rem 1.25rem;border-bottom:1px solid var(--mdi-border)">
            <input id="mdiPtSearch" type="text" placeholder="Filter by patient or reference…" oninput="mdiFilterPT(this.value)"
                style="width:100%;border:1px solid var(--mdi-border);border-radius:7px;padding:6px 10px;font-size:0.8rem;outline:none;font-family:inherit;box-sizing:border-box">
        </div>
        <!-- Table -->
        <div style="overflow-y:auto;flex:1">
            <?php if (empty($paidTodayRows)): ?>
                <div style="text-align:center;padding:2.5rem;color:var(--mdi-muted);font-size:0.85rem">No payments posted today yet.</div>
            <?php else: ?>
            <table id="mdiPtTable" style="width:100%;border-collapse:collapse;font-size:0.78rem">
                <thead>
                    <tr style="background:#f8fafc;position:sticky;top:0;z-index:1">
                        <th style="text-align:left;padding:8px 1.25rem;font-size:0.6rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--mdi-muted);font-weight:700;border-bottom:1px solid var(--mdi-border)">Time</th>
                        <th style="text-align:left;padding:8px 10px;font-size:0.6rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--mdi-muted);font-weight:700;border-bottom:1px solid var(--mdi-border)">Patient</th>
                        <th style="text-align:left;padding:8px 10px;font-size:0.6rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--mdi-muted);font-weight:700;border-bottom:1px solid var(--mdi-border)">Reference</th>
                        <th style="text-align:left;padding:8px 10px;font-size:0.6rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--mdi-muted);font-weight:700;border-bottom:1px solid var(--mdi-border)">Type</th>
                        <th style="text-align:right;padding:8px 1.25rem 8px 10px;font-size:0.6rem;letter-spacing:0.08em;text-transform:uppercase;color:var(--mdi-muted);font-weight:700;border-bottom:1px solid var(--mdi-border)">Amount</th>
                    </tr>
                </thead>
                <tbody id="mdi-pt-tbody">
                <?php foreach ($paidTodayRows as $i => $r):
                    $time    = date('g:i a', strtotime($r['post_time']));
                    $patient = htmlspecialchars($r['patient'] ?? '—');
                    $ref     = htmlspecialchars($r['check_ref'] ?? $r['account_code'] ?? '—');
                    $type    = $r['payer_type'] == 1 ? 'Insurance' : ($r['payer_type'] == 2 ? 'Patient' : 'Other');
                    $amount  = number_format((float)$r['pay_amount'], 2);
                    $bg      = $i % 2 === 0 ? '#fff' : '#fafbfc';
                ?>
                <tr class="mdi-pt-row" style="background:<?php echo $bg; ?>;border-bottom:1px solid #f0f2f5" data-search="<?php echo strtolower($patient . ' ' . $ref); ?>">
                    <td style="padding:9px 1.25rem;color:var(--mdi-muted);white-space:nowrap"><?php echo $time; ?></td>
                    <td style="padding:9px 10px;font-weight:600;color:var(--mdi-navy)"><?php echo $patient; ?></td>
                    <td style="padding:9px 10px;color:var(--mdi-muted2);font-family:monospace;font-size:0.73rem"><?php echo $ref; ?></td>
                    <td style="padding:9px 10px">
                        <span style="font-size:0.6rem;font-weight:700;padding:2px 7px;border-radius:4px;letter-spacing:0.05em;
                            background:<?php echo $type==='Insurance' ? 'var(--mdi-blue-d)' : ($type==='Patient' ? 'var(--mdi-green-d)' : 'var(--mdi-amber-d)'); ?>;
                            color:<?php echo $type==='Insurance' ? 'var(--mdi-blue)' : ($type==='Patient' ? 'var(--mdi-green)' : 'var(--mdi-amber)'); ?>">
                            <?php echo $type; ?>
                        </span>
                    </td>
                    <td style="padding:9px 1.25rem 9px 10px;text-align:right;font-weight:700;color:var(--mdi-green);font-family:'DM Mono',monospace">$<?php echo $amount; ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <!-- Footer -->
        <div style="padding:0.6rem 1.25rem;border-top:1px solid var(--mdi-border);display:flex;justify-content:space-between;align-items:center;background:#fafbfc">
            <span style="font-size:0.7rem;color:var(--mdi-muted)"><?php echo date('F j, Y'); ?></span>
            <button onclick="mdiNavGo('/interface/billing/search_payments.php?from_date=<?php echo date('Y-m-d'); ?>&to_date=<?php echo date('Y-m-d'); ?>&form_submit=1');mdiClosePaidToday();"
                style="font-size:0.7rem;font-weight:600;padding:5px 14px;border-radius:7px;border:1px solid var(--mdi-border);background:#fff;cursor:pointer;color:var(--mdi-navy);font-family:inherit;transition:background 0.1s"
                onmouseover="this.style.background='var(--mdi-navy-light)'" onmouseout="this.style.background='#fff'">
                Open Full Search →
            </button>
        </div>
    </div>
</div>
<style>
@keyframes mdiModalIn { from{opacity:0;transform:translateY(10px) scale(0.98)} to{opacity:1;transform:translateY(0) scale(1)} }
</style>
<script>
function mdiShowPaidToday() {
    var m = document.getElementById('mdiPaidTodayModal');
    m.style.display = 'flex';
    document.getElementById('mdiPtSearch').value = '';
    mdiFilterPT('');
    document.getElementById('mdiPtSearch').focus();
}
function mdiClosePaidToday() {
    document.getElementById('mdiPaidTodayModal').style.display = 'none';
}
function mdiFilterPT(q) {
    q = q.toLowerCase().trim();
    document.querySelectorAll('.mdi-pt-row').forEach(function(row) {
        row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
    });
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') mdiClosePaidToday();
});
</script>

<!-- ── My Signature Modal ────────────────────────────────────────────── -->
<div id="mdiSigModal" style="display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;">
    <div onclick="mdiCloseSignatureModal()" style="position:absolute;inset:0;background:rgba(10,5,25,0.55);backdrop-filter:blur(2px)"></div>
    <div style="position:relative;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.25);width:min(560px,95vw);max-height:92vh;display:flex;flex-direction:column;overflow:hidden;animation:mdiModalIn 0.2s ease">
        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem 0.85rem;border-bottom:1px solid var(--mdi-border);background:linear-gradient(135deg,#f5f2fc,#ffffff)">
            <div style="display:flex;align-items:center;gap:9px;">
                <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="var(--mdi-violet)" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                <div>
                    <div style="font-size:1rem;font-weight:700;color:var(--mdi-navy)">My Signature</div>
                    <div style="font-size:0.7rem;color:var(--mdi-muted)">Used to electronically sign encounter notes</div>
                </div>
            </div>
            <button onclick="mdiCloseSignatureModal()" style="background:none;border:none;cursor:pointer;color:var(--mdi-muted);padding:4px;border-radius:6px;line-height:1;font-size:1.1rem" title="Close">✕</button>
        </div>

        <!-- Tabs -->
        <div style="display:flex;gap:6px;padding:8px 1.25rem 0;border-bottom:1px solid var(--mdi-border)">
            <button type="button" class="mdi-sig-tab active" data-tab="draw" onclick="mdiSigTab('draw')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                Draw
            </button>
            <button type="button" class="mdi-sig-tab" data-tab="upload" onclick="mdiSigTab('upload')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                Upload
            </button>
            <button type="button" class="mdi-sig-tab" data-tab="typed" onclick="mdiSigTab('typed')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
                Typed
            </button>
        </div>

        <!-- Body -->
        <div style="padding:1.1rem 1.25rem;overflow-y:auto;flex:1;">

            <!-- Draw pane -->
            <div id="mdi-sig-pane-draw" class="mdi-sig-pane">
                <canvas id="mdiSigCanvas" style="width:100%;height:180px;border:2px dashed var(--mdi-border);border-radius:10px;background:#fbfbfd;touch-action:none;cursor:crosshair;display:block;"></canvas>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                    <span style="font-size:0.7rem;color:var(--mdi-muted)">Draw with your mouse or touchscreen</span>
                    <button type="button" class="mdi-sig-btn-secondary" onclick="mdiSigClearCanvas()">Clear</button>
                </div>
            </div>

            <!-- Upload pane -->
            <div id="mdi-sig-pane-upload" class="mdi-sig-pane" style="display:none">
                <div id="mdiSigUploadPlaceholder" class="mdi-sig-upload-drop" onclick="document.getElementById('mdiSigFileInput').click()">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="var(--mdi-muted)" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    <div style="font-size:0.82rem;font-weight:600;color:var(--mdi-text2)">Click to upload a PNG</div>
                    <div style="font-size:0.68rem;color:var(--mdi-muted)">Best results with a transparent background</div>
                </div>
                <img id="mdiSigUploadPreviewImg" style="display:none;max-width:100%;max-height:180px;border:2px dashed var(--mdi-border);border-radius:10px;background:#fbfbfd repeating-conic-gradient(#eef0f4 0% 25%, transparent 0% 50%) 50% / 14px 14px;padding:10px;box-sizing:border-box;" />
                <input id="mdiSigFileInput" type="file" accept="image/png,image/jpeg" style="display:none" onchange="mdiSigFileSelected(event)">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.75rem;color:var(--mdi-text2);cursor:pointer;">
                        <input type="checkbox" id="mdiSigRemoveWhite" checked onchange="mdiSigRenderUploadPreview()">
                        Remove white background automatically
                    </label>
                    <button type="button" class="mdi-sig-btn-secondary" onclick="mdiSigResetUpload()">Change file</button>
                </div>
            </div>

            <!-- Typed pane -->
            <div id="mdi-sig-pane-typed" class="mdi-sig-pane" style="display:none">
                <input id="mdiSigTypedText" type="text" value="<?php echo attr($mdi_fullname); ?>" oninput="mdiSigRenderTyped()"
                    style="width:100%;padding:9px 11px;border:1px solid var(--mdi-border);border-radius:8px;font-size:0.9rem;box-sizing:border-box;margin-bottom:10px;font-family:inherit;">
                <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mdi-muted);margin-bottom:6px;">Script style</div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:7px;">
                    <button type="button" class="mdi-sig-font-btn active" style="font-family:'Dancing Script',cursive;" onclick="mdiSigPickFont(&quot;'Dancing Script',cursive&quot;, this)">Deborah</button>
                    <button type="button" class="mdi-sig-font-btn" style="font-family:'Great Vibes',cursive;" onclick="mdiSigPickFont(&quot;'Great Vibes',cursive&quot;, this)">Deborah</button>
                    <button type="button" class="mdi-sig-font-btn" style="font-family:'Pacifico',cursive;" onclick="mdiSigPickFont(&quot;'Pacifico',cursive&quot;, this)">Deborah</button>
                    <button type="button" class="mdi-sig-font-btn" style="font-family:'Sacramento',cursive;" onclick="mdiSigPickFont(&quot;'Sacramento',cursive&quot;, this)">Deborah</button>
                    <button type="button" class="mdi-sig-font-btn" style="font-family:'Allura',cursive;" onclick="mdiSigPickFont(&quot;'Allura',cursive&quot;, this)">Deborah</button>
                </div>
                <div id="mdiSigTypedPreviewBox" style="margin-top:14px;border:2px dashed var(--mdi-border);border-radius:10px;padding:22px 14px;text-align:center;background:#fbfbfd;overflow:hidden;">
                    <span id="mdiSigTypedPreviewText" style="font-size:2.3rem;color:var(--mdi-navy);font-family:'Dancing Script',cursive;white-space:nowrap;"><?php echo text($mdi_fullname); ?></span>
                </div>
            </div>

            <!-- Live preview: how it appears on signed notes -->
            <div style="margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--mdi-border);">
                <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mdi-muted);margin-bottom:8px;">Preview — how this appears on signed notes</div>
                <div style="border:1px solid var(--mdi-border);border-radius:8px;padding:12px 14px;background:#fff;">
                    <div style="font-size:0.68rem;color:var(--mdi-muted);margin-bottom:5px;display:flex;align-items:center;gap:5px;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        Electronically Signed
                    </div>
                    <img id="mdiSigPreviewImg" style="display:none;max-height:52px;max-width:240px;" />
                    <div style="font-size:0.72rem;color:var(--mdi-text2);margin-top:6px;"><?php echo text($mdi_fullname); ?> &middot; <span id="mdiSigPreviewDate"></span></div>
                </div>
            </div>

            <!-- Electronic Signature (Signing PIN) — lets the provider set a
                 short PIN so they don't have to retype their OpenEMR
                 password every time they sign an encounter note. Used by
                 the "Sign Encounter" PIN prompt (see verify_pin.php). -->
            <div style="margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--mdi-border);">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                    <div style="font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--mdi-muted);">Electronic Signature</div>
                    <span id="mdiPinConfiguredBadge" style="display:none;font-size:0.65rem;font-weight:700;color:#059669;display:none;align-items:center;gap:4px;">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        PIN configured
                    </span>
                </div>
                <div style="border:1px solid var(--mdi-border);border-radius:8px;padding:14px;background:#fbfbfd;">
                    <div style="font-size:0.75rem;color:var(--mdi-text2);margin-bottom:10px;line-height:1.5;">
                        Set a short PIN to sign encounter notes with one click, instead of typing your full password every time.
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <div>
                            <label style="font-size:0.68rem;font-weight:600;color:var(--mdi-text2);display:block;margin-bottom:4px;">Signing PIN</label>
                            <input id="mdiPinNew" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="new-password"
                                placeholder="4–8 digits"
                                style="width:100%;padding:8px 10px;border:1px solid var(--mdi-border);border-radius:8px;font-size:0.9rem;box-sizing:border-box;font-family:inherit;letter-spacing:2px;">
                        </div>
                        <div>
                            <label style="font-size:0.68rem;font-weight:600;color:var(--mdi-text2);display:block;margin-bottom:4px;">Confirm PIN</label>
                            <input id="mdiPinConfirm" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="8" autocomplete="new-password"
                                placeholder="4–8 digits"
                                style="width:100%;padding:8px 10px;border:1px solid var(--mdi-border);border-radius:8px;font-size:0.9rem;box-sizing:border-box;font-family:inherit;letter-spacing:2px;">
                        </div>
                    </div>
                    <label style="display:flex;align-items:center;gap:6px;font-size:0.75rem;color:var(--mdi-text2);margin-top:10px;cursor:pointer;">
                        <input type="checkbox" id="mdiPinRequireTimeout" checked>
                        Require PIN again after 30 minutes of inactivity
                    </label>
                    <div id="mdiPinMsg" style="font-size:0.72rem;margin-top:8px;display:none;"></div>
                    <div style="display:flex;justify-content:flex-end;margin-top:10px;">
                        <button id="mdiPinSaveBtn" type="button" class="mdi-sig-btn-primary" onclick="mdiPinSave()">Save PIN</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div style="padding:0.85rem 1.25rem;border-top:1px solid var(--mdi-border);display:flex;justify-content:space-between;align-items:center;background:#fafbfc">
            <button id="mdiSigDeleteBtn" type="button" class="mdi-sig-btn-danger-text" onclick="mdiSigDelete()" style="display:none">Delete Signature</button>
            <div style="margin-left:auto;display:flex;gap:8px;">
                <button type="button" class="mdi-sig-btn-secondary" onclick="mdiCloseSignatureModal()">Cancel</button>
                <button id="mdiSigSaveBtn" type="button" class="mdi-sig-btn-primary" onclick="mdiSigSave()">Save Signature</button>
            </div>
        </div>
    </div>
</div>

<!-- ── Electronic Signature Required Modal ─────────────────────────────
     Shown in place of the old "No signature found." error whenever the
     provider clicks Sign Notes without a signature configured. -->
<div id="mdiSigRequiredModal" style="display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;">
    <div onclick="mdiCloseSigRequiredModal()" style="position:absolute;inset:0;background:rgba(10,5,25,0.55);backdrop-filter:blur(2px)"></div>
    <div style="position:relative;background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,0.25);width:min(380px,90vw);overflow:hidden;animation:mdiModalIn 0.2s ease">
        <div style="padding:1.75rem 1.5rem 1.1rem;text-align:center;">
            <div style="width:46px;height:46px;border-radius:50%;background:#fef3c7;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><path d="M12 9v4"/><path d="M12 17h.01"/><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </div>
            <div style="font-size:1.05rem;font-weight:700;color:var(--mdi-navy);">Electronic Signature Required</div>
            <div style="font-size:0.8rem;color:var(--mdi-muted);margin-top:7px;line-height:1.55;">
                You must configure your signature before<br>you can sign encounter notes.
            </div>
        </div>
        <div style="padding:0 1.5rem 1.5rem;display:flex;flex-direction:column;gap:8px;">
            <button type="button" class="mdi-sig-btn-primary" style="padding:10px;font-size:13px;" onclick="mdiSigRequiredConfigure()">Configure Signature</button>
            <button type="button" class="mdi-sig-btn-secondary" style="padding:10px;font-size:13px;" onclick="mdiCloseSigRequiredModal()">Cancel</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var SIG_API = webroot_url + '/interface/usergroup/mdi_signature_api.php';
    var sigState = { hasSignature: null };
    var sigActiveTab = 'draw';
    var sigPendingNavUrl = null;
    var typedFont = "'Dancing Script',cursive";
    var uploadDataUrl = null, uploadRawImg = null;
    var canvas, ctx, drawing = false, lastX, lastY;

    // ── Draw tab ────────────────────────────────────────────────────────
    function initCanvas() {
        canvas = document.getElementById('mdiSigCanvas');
        if (!canvas) return;
        var ratio = window.devicePixelRatio || 1;
        var rect = canvas.getBoundingClientRect();
        if (!rect.width) return; // modal not laid out yet
        canvas.width = rect.width * ratio;
        canvas.height = rect.height * ratio;
        ctx = canvas.getContext('2d');
        ctx.scale(ratio, ratio);
        ctx.lineWidth = 2.4;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#1e0a3c';

        if (canvas._mdiInit) return;
        canvas._mdiInit = true;

        function pos(e) {
            var r = canvas.getBoundingClientRect();
            return { x: e.clientX - r.left, y: e.clientY - r.top };
        }
        canvas.addEventListener('pointerdown', function (e) {
            drawing = true;
            var p = pos(e);
            lastX = p.x; lastY = p.y;
            canvas.setPointerCapture(e.pointerId);
        });
        canvas.addEventListener('pointermove', function (e) {
            if (!drawing) return;
            var p = pos(e);
            ctx.beginPath();
            ctx.moveTo(lastX, lastY);
            ctx.lineTo(p.x, p.y);
            ctx.stroke();
            lastX = p.x; lastY = p.y;
            mdiSigUpdatePreviewFromCanvas();
        });
        ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) {
            canvas.addEventListener(ev, function () { drawing = false; });
        });
    }

    function canvasIsBlank() {
        if (!canvas) return true;
        var blank = document.createElement('canvas');
        blank.width = canvas.width; blank.height = canvas.height;
        return canvas.toDataURL() === blank.toDataURL();
    }

    window.mdiSigClearCanvas = function () {
        if (!ctx || !canvas) return;
        var rect = canvas.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, rect.height);
        mdiSigUpdatePreviewFromCanvas();
    };

    window.mdiSigUpdatePreviewFromCanvas = function () {
        if (sigActiveTab !== 'draw' || !canvas) return;
        var img = document.getElementById('mdiSigPreviewImg');
        if (canvasIsBlank()) { img.style.display = 'none'; return; }
        img.src = canvas.toDataURL('image/png');
        img.style.display = 'inline-block';
    };

    // ── Upload tab ──────────────────────────────────────────────────────
    window.mdiSigFileSelected = function (evt) {
        var file = evt.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var img = new Image();
            img.onload = function () {
                uploadRawImg = img;
                mdiSigRenderUploadPreview();
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    };

    window.mdiSigRenderUploadPreview = function () {
        if (!uploadRawImg) return;
        var c = document.createElement('canvas');
        c.width = uploadRawImg.width; c.height = uploadRawImg.height;
        var cctx = c.getContext('2d');
        cctx.drawImage(uploadRawImg, 0, 0);
        if (document.getElementById('mdiSigRemoveWhite').checked) {
            var imgData = cctx.getImageData(0, 0, c.width, c.height);
            var px = imgData.data;
            for (var i = 0; i < px.length; i += 4) {
                if (px[i] > 235 && px[i + 1] > 235 && px[i + 2] > 235) px[i + 3] = 0;
            }
            cctx.putImageData(imgData, 0, 0);
        }
        uploadDataUrl = c.toDataURL('image/png');
        var img = document.getElementById('mdiSigUploadPreviewImg');
        img.src = uploadDataUrl;
        img.style.display = 'block';
        document.getElementById('mdiSigUploadPlaceholder').style.display = 'none';
        if (sigActiveTab === 'upload') {
            var pimg = document.getElementById('mdiSigPreviewImg');
            pimg.src = uploadDataUrl; pimg.style.display = 'inline-block';
        }
    };

    window.mdiSigResetUpload = function () {
        uploadDataUrl = null; uploadRawImg = null;
        document.getElementById('mdiSigFileInput').value = '';
        document.getElementById('mdiSigUploadPreviewImg').style.display = 'none';
        document.getElementById('mdiSigUploadPlaceholder').style.display = 'flex';
        if (sigActiveTab === 'upload') document.getElementById('mdiSigPreviewImg').style.display = 'none';
    };

    // ── Typed tab ───────────────────────────────────────────────────────
    window.mdiSigPickFont = function (font, btn) {
        typedFont = font;
        document.querySelectorAll('.mdi-sig-font-btn').forEach(function (b) { b.classList.remove('active'); });
        if (btn) btn.classList.add('active');
        mdiSigRenderTyped();
    };

    function renderTypedToPng(text, font) {
        var c = document.createElement('canvas');
        c.width = 640; c.height = 170;
        var cctx = c.getContext('2d');
        cctx.font = "66px " + font;
        cctx.fillStyle = '#1e0a3c';
        cctx.textBaseline = 'middle';
        cctx.fillText(text, 12, 95);
        return c.toDataURL('image/png');
    }

    window.mdiSigRenderTyped = function () {
        var text = document.getElementById('mdiSigTypedText').value.trim() || <?php echo json_encode($mdi_fullname); ?>;
        var box = document.getElementById('mdiSigTypedPreviewText');
        box.textContent = text;
        box.style.fontFamily = typedFont;
        if (sigActiveTab === 'typed') {
            var dataUrl = renderTypedToPng(text, typedFont);
            var pimg = document.getElementById('mdiSigPreviewImg');
            pimg.src = dataUrl; pimg.style.display = 'inline-block';
        }
    };

    // ── Tabs ────────────────────────────────────────────────────────────
    window.mdiSigTab = function (tab) {
        sigActiveTab = tab;
        document.querySelectorAll('.mdi-sig-tab').forEach(function (t) {
            t.classList.toggle('active', t.dataset.tab === tab);
        });
        document.querySelectorAll('.mdi-sig-pane').forEach(function (p) {
            p.style.display = (p.id === 'mdi-sig-pane-' + tab) ? '' : 'none';
        });
        if (tab === 'draw') {
            initCanvas();
            mdiSigUpdatePreviewFromCanvas();
        } else if (tab === 'upload') {
            var pimg = document.getElementById('mdiSigPreviewImg');
            if (uploadDataUrl) { pimg.src = uploadDataUrl; pimg.style.display = 'inline-block'; }
            else pimg.style.display = 'none';
        } else if (tab === 'typed') {
            mdiSigRenderTyped();
        }
    };

    // ── Open / close ────────────────────────────────────────────────────
    window.mdiOpenSignatureModal = function () {
        document.getElementById('mdiSigModal').style.display = 'flex';
        document.getElementById('mdiSigPreviewDate').textContent = new Date().toLocaleString();
        initCanvas();
        mdiSigLoadExisting();
        mdiPinLoadStatus();
    };
    window.mdiCloseSignatureModal = function () {
        document.getElementById('mdiSigModal').style.display = 'none';
    };

    function mdiSigLoadExisting() {
        fetch(SIG_API + '?action=status', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { has_signature: false }; })
            .catch(function () { return { has_signature: false }; })
            .then(function (data) {
                sigState.hasSignature = !!data.has_signature;
                document.getElementById('mdiSigDeleteBtn').style.display = sigState.hasSignature ? '' : 'none';
                mdiSigUpdateBadge();
                if (!data.has_signature || !data.data_url) return;

                if (data.sig_type === 'typed') {
                    document.getElementById('mdiSigTypedText').value = data.text || '';
                    var matchBtn = null;
                    document.querySelectorAll('.mdi-sig-font-btn').forEach(function (b) {
                        if (b.style.fontFamily.replace(/"/g, "'") === (data.font || '').replace(/"/g, "'")) matchBtn = b;
                    });
                    mdiSigPickFont(data.font || typedFont, matchBtn);
                    mdiSigTab('typed');
                } else if (data.sig_type === 'upload') {
                    uploadDataUrl = data.data_url;
                    document.getElementById('mdiSigUploadPreviewImg').src = data.data_url;
                    document.getElementById('mdiSigUploadPreviewImg').style.display = 'block';
                    document.getElementById('mdiSigUploadPlaceholder').style.display = 'none';
                    mdiSigTab('upload');
                } else {
                    // draw — load the saved strokes onto the canvas so the
                    // provider can see and, if they like, redraw over it.
                    initCanvas();
                    if (canvas && ctx) {
                        var img = new Image();
                        img.onload = function () {
                            var rect = canvas.getBoundingClientRect();
                            ctx.drawImage(img, 0, 0, rect.width, rect.height);
                            mdiSigUpdatePreviewFromCanvas();
                        };
                        img.src = data.data_url;
                    }
                    mdiSigTab('draw');
                }
            });
    }

    function mdiSigUpdateBadge() {
        var b = document.getElementById('mdi-rn-sig-badge');
        if (b) b.style.display = sigState.hasSignature ? 'none' : 'inline-block';
    }

    // ── Electronic Signature (Signing PIN) ─────────────────────────────
    function mdiPinLoadStatus() {
        fetch(SIG_API + '?action=pin_status', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { has_pin: false }; })
            .catch(function () { return { has_pin: false }; })
            .then(function (data) {
                var badge = document.getElementById('mdiPinConfiguredBadge');
                if (badge) badge.style.display = data.has_pin ? 'inline-flex' : 'none';
                var cb = document.getElementById('mdiPinRequireTimeout');
                if (cb) cb.checked = data.has_pin ? !!data.require_pin_after_timeout : true;
                document.getElementById('mdiPinNew').value = '';
                document.getElementById('mdiPinConfirm').value = '';
                mdiPinShowMsg('');
            });
    }

    function mdiPinShowMsg(text, isError) {
        var el = document.getElementById('mdiPinMsg');
        if (!el) return;
        if (!text) { el.style.display = 'none'; el.textContent = ''; return; }
        el.textContent = text;
        el.style.color = isError ? '#dc2626' : '#059669';
        el.style.display = 'block';
    }

    window.mdiPinSave = function () {
        var pin = document.getElementById('mdiPinNew').value.trim();
        var confirmPin = document.getElementById('mdiPinConfirm').value.trim();
        var requireTimeout = document.getElementById('mdiPinRequireTimeout').checked;

        if (!/^\d{4,8}$/.test(pin)) {
            mdiPinShowMsg('PIN must be 4–8 digits.', true);
            return;
        }
        if (pin !== confirmPin) {
            mdiPinShowMsg('PINs do not match.', true);
            return;
        }

        var body = new FormData();
        body.append('action', 'save_pin');
        body.append('pin', pin);
        body.append('require_pin_after_timeout', requireTimeout ? '1' : '0');
        body.append('csrf_token_form', csrf_token_js);

        var btn = document.getElementById('mdiPinSaveBtn');
        btn.disabled = true; btn.textContent = 'Saving…';

        fetch(SIG_API, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btn.disabled = false; btn.textContent = 'Save PIN';
                if (data && data.success) {
                    mdiPinShowMsg('Signing PIN saved.', false);
                    document.getElementById('mdiPinNew').value = '';
                    document.getElementById('mdiPinConfirm').value = '';
                    document.getElementById('mdiPinConfiguredBadge').style.display = 'inline-flex';
                } else {
                    mdiPinShowMsg((data && data.message) || 'Could not save PIN. Please try again.', true);
                }
            })
            .catch(function () {
                btn.disabled = false; btn.textContent = 'Save PIN';
                mdiPinShowMsg('Could not save PIN. Please try again.', true);
            });
    };

    // ── Save / Delete ───────────────────────────────────────────────────
    window.mdiSigSave = function () {
        var payload = null;
        if (sigActiveTab === 'draw') {
            if (canvasIsBlank()) { alert('Please draw your signature first.'); return; }
            payload = { sig_type: 'draw', data_url: canvas.toDataURL('image/png') };
        } else if (sigActiveTab === 'upload') {
            if (!uploadDataUrl) { alert('Please choose a PNG or JPG to upload first.'); return; }
            payload = { sig_type: 'upload', data_url: uploadDataUrl };
        } else if (sigActiveTab === 'typed') {
            var text = document.getElementById('mdiSigTypedText').value.trim();
            if (!text) { alert('Please enter a name to render as your signature.'); return; }
            payload = { sig_type: 'typed', data_url: renderTypedToPng(text, typedFont), text: text, font: typedFont };
        }
        if (!payload) return;

        var body = new FormData();
        body.append('action', 'save');
        body.append('sig_type', payload.sig_type);
        body.append('data_url', payload.data_url);
        if (payload.text) body.append('text', payload.text);
        if (payload.font) body.append('font', payload.font);
        body.append('csrf_token_form', csrf_token_js);

        var saveBtn = document.getElementById('mdiSigSaveBtn');
        saveBtn.disabled = true; saveBtn.textContent = 'Saving…';

        fetch(SIG_API, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                saveBtn.disabled = false; saveBtn.textContent = 'Save Signature';
                if (data && data.success) {
                    sigState.hasSignature = true;
                    mdiSigUpdateBadge();
                    document.getElementById('mdiSigDeleteBtn').style.display = '';
                    mdiCloseSignatureModal();
                    if (sigPendingNavUrl) {
                        var u = sigPendingNavUrl; sigPendingNavUrl = null;
                        mdiNavGo(u);
                    }
                } else {
                    alert((data && data.message) || 'Could not save signature. Please try again.');
                }
            })
            .catch(function () {
                saveBtn.disabled = false; saveBtn.textContent = 'Save Signature';
                alert('Could not save signature. Please try again.');
            });
    };

    window.mdiSigDelete = function () {
        if (!confirm('Delete your saved signature? You will need to configure a new one before signing notes.')) return;
        var body = new FormData();
        body.append('action', 'delete');
        body.append('csrf_token_form', csrf_token_js);
        fetch(SIG_API, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    sigState.hasSignature = false;
                    mdiSigUpdateBadge();
                    document.getElementById('mdiSigDeleteBtn').style.display = 'none';
                    document.getElementById('mdiSigPreviewImg').style.display = 'none';
                    mdiSigClearCanvas();
                    mdiSigResetUpload();
                } else {
                    alert((data && data.message) || 'Could not delete signature.');
                }
            });
    };

    // ── Sign Notes guard ────────────────────────────────────────────────
    // Replaces the old "No signature found." error: checks signature
    // status first, and if none is configured, shows a friendly modal
    // that offers to open the signature editor immediately instead of
    // silently blocking the provider.
    window.mdiSignNotesGuard = function (url) {
        fetch(SIG_API + '?action=status', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { has_signature: false }; })
            .catch(function () { return { has_signature: false }; })
            .then(function (data) {
                sigState.hasSignature = !!data.has_signature;
                mdiSigUpdateBadge();
                if (sigState.hasSignature) {
                    mdiNavGo(url);
                } else {
                    sigPendingNavUrl = url;
                    document.getElementById('mdiSigRequiredModal').style.display = 'flex';
                }
            });
    };

    window.mdiCloseSigRequiredModal = function () {
        document.getElementById('mdiSigRequiredModal').style.display = 'none';
        sigPendingNavUrl = null;
    };

    window.mdiSigRequiredConfigure = function () {
        document.getElementById('mdiSigRequiredModal').style.display = 'none';
        mdiOpenSignatureModal();
    };

    // ── Badge status on page load (non-blocking) ───────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        fetch(SIG_API + '?action=status', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .catch(function () { return null; })
            .then(function (data) {
                if (!data) return;
                sigState.hasSignature = !!data.has_signature;
                mdiSigUpdateBadge();
            });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        mdiCloseSignatureModal();
        mdiCloseSigRequiredModal();
    });
})();
</script>


<script>
(function () {
    'use strict';

    // ── 2-minute dashboard refresh ─────────────────────────────────────────
    // Appends bm_dash_refresh=1 to the current URL (preserving token_main).
    // Use a clean relative URL — the endpoint exits before token_main check
    var _refreshUrl = '<?php echo $web_root; ?>/interface/main/tabs/main.php?bm_dash_refresh=1';

    function _doRefresh(btn) {
        var icon = document.getElementById('mdi-refresh-icon');
        var rbtn = btn || document.getElementById('mdi-refresh-btn');
        if (icon) icon.style.animation = 'mdi-spin .7s linear infinite';
        if (rbtn) rbtn.disabled = true;

        // Build URL: include token_main from current page URL
        var tm = (window.location.href.match(/[?&]token_main=([^&]+)/) || [])[1] || '';
        var url = window.location.pathname + '?bm_dash_refresh=1'
                + (tm ? '&token_main=' + tm : '')
                + '&_t=' + Date.now();

        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.withCredentials = true;
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            // Stop spinner regardless of outcome
            if (icon) icon.style.animation = '';
            if (rbtn) rbtn.disabled = false;

            var txt = xhr.responseText || '';
            // Find our JSON — it starts with {"paid_today" and ends at the last }
            var s = txt.lastIndexOf('{"paid_today"');
            if (s < 0) { console.warn('MDI refresh: no JSON in response (status ' + xhr.status + ')'); return; }
            var d;
            try { d = JSON.parse(txt.slice(s)); }
            catch(e) { console.warn('MDI refresh: parse failed:', txt.slice(s, s+80)); return; }
            if (!d || d.error) { console.warn('MDI refresh error:', d && d.error); return; }

            var el;
            // ── Stat cards ─────────────────────────────────────────────────────
            el = document.getElementById('mdi-paid-today-val');
            if (el) el.textContent = '$' + Math.round(d.paid_today).toLocaleString();
            el = document.getElementById('mdi-paid-today-sub');
            if (el) el.textContent = d.paid_today_count + ' payment' + (d.paid_today_count !== 1 ? 's' : '');
            el = document.getElementById('mdi-paid-month-val');
            if (el) el.textContent = '$' + Math.round(d.paid_month).toLocaleString();
            el = document.getElementById('mdi-unbilled-val');
            if (el) el.textContent = '$' + Math.round(d.unbilled_amt).toLocaleString();
            el = document.getElementById('mdi-unbilled-sub');
            if (el) el.innerHTML = '<span class="' + (d.unbilled_enc > 0 ? 'down' : 'up') + '">'
                + d.unbilled_enc + ' encounter' + (d.unbilled_enc !== 1 ? 's' : '') + '</span>';

            // ── Action Required counts ─────────────────────────────────────────
            if (d.action) {
                var ac = d.action;
                var acMap = {
                    'mdi-ac-unsigned':    ac.unsigned,
                    'mdi-ac-msgs':        ac.msgs,
                    'mdi-ac-claims_send': ac.claims_send,
                    'mdi-ac-claims_rej':  ac.claims_rej,
                    'mdi-ac-appts':       ac.appts,
                    'mdi-ac-recalls':     ac.recalls,
                    'mdi-docs-auth-val':  ac.docs,
                    'mdi-ac-pnp':          ac.pnp,
                    'mdi-ac-unbilled_old':ac.unbilled_old,
                    'mdi-ac-aging':       ac.aging
                };
                var total = 0;
                for (var k in acMap) {
                    var v = acMap[k] || 0;
                    total += v;
                    el = document.getElementById(k);
                    if (el) { el.textContent = v; }
                    // Also update the parent row has-items/clear class
                    if (el) {
                        var row = el.closest ? el.closest('.mdi-action-row') : null;
                        if (row) {
                            row.classList.toggle('has-items', v > 0);
                            row.classList.toggle('clear', v === 0);
                        }
                    }
                }
                el = document.getElementById('mdi-ac-total');
                if (el) el.textContent = total + ' items need attention';
            }

            // ── Schedule list ──────────────────────────────────────────────────
            if (d.schedule && d.schedule.length >= 0) {
                var sList = document.getElementById('mdi-schedule-list');
                if (sList) {
                    var statusClasses = {
                        '':  ['Scheduled','mdi-sb-scheduled'], '-':['Scheduled','mdi-sb-scheduled'],
                        '^': ['Pending','mdi-sb-scheduled'],   '*':['Arrived','mdi-sb-waiting'],
                        '<': ['Checked In','mdi-sb-waiting'],  '@':['In Room','mdi-sb-inroom'],
                        '~': ['In Room','mdi-sb-inroom'],      'S':['With Staff','mdi-sb-inroom'],
                        'M': ['With MD','mdi-sb-inroom'],      '1':['With Nurse','mdi-sb-inroom'],
                        '2': ['With Doctor','mdi-sb-inroom'],  '>':['Checked Out','mdi-sb-done'],
                        '#': ['Done','mdi-sb-done'],           '?':['Done','mdi-sb-done'],
                        '%': ['Done','mdi-sb-done'],           'x':['No Show','mdi-sb-noshow'],
                        'y': ['Cancelled','mdi-sb-noshow']
                    };
                    if (d.schedule.length === 0) {
                        sList.innerHTML = '<div class="mdi-empty-state">No appointments scheduled for today.</div>';
                    } else {
                        var html = '';
                        d.schedule.forEach(function(s) {
                            var sc = statusClasses[s.status] || ['Scheduled','mdi-sb-scheduled'];
                            var t = s.time ? s.time.slice(0,5) : '';
                            var h = parseInt(t.split(':')[0]), m = t.split(':')[1];
                            var ampm = h >= 12 ? (h > 12 ? (h-12) : h) + ':' + m + ' PM' : (h||12) + ':' + m + ' AM';
                            html += '<div class="mdi-schedule-item">'
                                + '<div class="mdi-appt-time">' + ampm + '<div class="dur">' + s.dur + ' min</div></div>'
                                + '<div class="mdi-appt-bar mdi-bar-new"></div>'
                                + '<div><div class="mdi-appt-name">' + (s.patient||'').replace(/</g,'&lt;') + '</div>'
                                + '<div class="mdi-appt-detail">' + (s.cat||'').replace(/</g,'&lt;') + '</div></div>'
                                + '<span class="mdi-status-badge ' + sc[1] + '">' + sc[0] + '</span>'
                                + '</div>';
                        });
                        sList.innerHTML = html;
                    }
                }
            }

            // ── Paid Today modal ───────────────────────────────────────────────
            if (d.pt_rows) {
                var totalEl = document.getElementById('mdi-pt-modal-total');
                if (totalEl) totalEl.innerHTML = '$' + d.paid_today.toFixed(2)
                    + '<span style="font-size:0.75rem;font-weight:500;color:var(--mdi-muted);margin-left:6px">'
                    + d.paid_today_count + ' payment' + (d.paid_today_count !== 1 ? 's' : '') + '</span>';
                var tbody = document.getElementById('mdi-pt-tbody');
                if (tbody) {
                    if (d.pt_rows.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--mdi-muted)">No payments posted today yet.</td></tr>';
                    } else {
                        var rows = '';
                        d.pt_rows.forEach(function(r, i) {
                            var bg = i % 2 === 0 ? '#ffffff' : '#f8fafc';
                            rows += '<tr class="mdi-pt-row" style="background:' + bg + ';border-bottom:1px solid #f0f2f5" data-search="' + (r.pat||'').toLowerCase() + ' ' + (r.ref||'').toLowerCase() + '">'
                                + '<td style="padding:8px 14px;font-size:.8rem;font-weight:600;color:var(--mdi-navy)">' + (r.pat||'').replace(/</g,'&lt;') + '</td>'
                                + '<td style="padding:8px 14px;font-size:.75rem;color:var(--mdi-muted)">' + (r.time||'') + '</td>'
                                + '<td style="padding:8px 14px;font-size:.75rem;color:var(--mdi-muted)">' + (r.ref||'—').replace(/</g,'&lt;') + '</td>'
                                + '<td style="padding:8px 14px;font-size:.82rem;font-weight:700;color:var(--mdi-green);text-align:right;font-family:monospace">$' + r.amt.toFixed(2) + '</td>'
                                + '</tr>';
                        });
                        tbody.innerHTML = rows;
                    }
                }
            }
            window._doRefresh = _doRefresh;
        };
        xhr.send();
    }
    window._doRefresh = _doRefresh;
    setInterval(_doRefresh, 2 * 60 * 1000); // every 2 minutes

    // ── Listen for refresh requests from child iframes ─────────────────────
    // Any iframe page can call: window.top.postMessage({type:'mdi_refresh'},'*')
    // to trigger a dashboard refresh (e.g. after saving a payment, signing an
    // encounter, approving an appointment, etc.)
    window.addEventListener('message', function(e) {
        if (e && e.data && e.data.type === 'mdi_refresh') {
            _doRefresh();
        }
    });

    // ── Idle logout: warn at 3 min (for testing), logout at 3 min 30 sec ──
    // Change WARN_MS / LOGOUT_MS to 14*60*1000 / 10*60*1000 for production.
    var WARN_MS   = 9.5 * 60 * 1000;  // 9.5 minutes → show warning
    var LOGOUT_MS = 10  * 60 * 1000;  // 10 minutes  → auto logout

    var _warnTimer, _logoutTimer, _countdownInterval;
    var _warned = false;

    // Build the warning modal
    var _modal = document.createElement('div');
    _modal.id  = 'mdi-idle-modal';
    _modal.style.cssText = [
        'display:none',
        'position:fixed',
        'inset:0',
        'z-index:999999',
        'align-items:center',
        'justify-content:center',
        'background:rgba(10,5,25,.65)',
        'backdrop-filter:blur(3px)',
    ].join(';');
    _modal.innerHTML = [
        '<div style="background:#fff;border-radius:14px;box-shadow:0 20px 60px rgba(0,0,0,.3);',
        'padding:2rem 2.5rem;max-width:380px;width:90vw;text-align:center;">',
        '<div style="font-size:2.5rem;margin-bottom:.5rem">⏱️</div>',
        '<div style="font-size:1.1rem;font-weight:700;color:#1a1d2e;margin-bottom:.5rem">Session Expiring</div>',
        '<div style="font-size:.88rem;color:#6b7280;margin-bottom:1.5rem">',
        'You will be logged out in <strong id="mdi-idle-secs">30</strong> seconds due to inactivity.',
        '</div>',
        '<button id="mdi-idle-stay" style="padding:.6rem 1.6rem;border-radius:8px;',
        'background:#5b21b6;color:#fff;border:none;font-weight:700;cursor:pointer;margin-right:.6rem">',
        'Stay Logged In</button>',
        '<button id="mdi-idle-out" style="padding:.6rem 1rem;border-radius:8px;',
        'background:#f3f4f6;color:#374151;border:1px solid #d1d5db;cursor:pointer">',
        'Logout Now</button>',
        '</div>',
    ].join('');
    document.body.appendChild(_modal);

    document.getElementById('mdi-idle-stay').addEventListener('click', _idleReset);
    document.getElementById('mdi-idle-out').addEventListener('click', _idleLogout);

    function _idleShowWarning() {
        _warned = true;
        _modal.style.display = 'flex';
        var secs = Math.round((LOGOUT_MS - WARN_MS) / 1000);
        var el   = document.getElementById('mdi-idle-secs');
        if (el) el.textContent = secs;
        _countdownInterval = setInterval(function () {
            secs -= 1;
            var e2 = document.getElementById('mdi-idle-secs');
            if (e2) e2.textContent = Math.max(secs, 0);
            if (secs <= 0) clearInterval(_countdownInterval);
        }, 1000);
    }

    function _idleReset() {
        _warned = false;
        _modal.style.display = 'none';
        clearInterval(_countdownInterval);
        clearTimeout(_warnTimer);
        clearTimeout(_logoutTimer);
        // Do NOT call top.restoreSession() — it navigates the frame and
        // causes "Site ID is missing". Session is still active (user just clicked).
        _scheduleTimers();
    }

    function _idleLogout() {
        timed_out = true;  // suppress beforeunload — logout is intentional
        clearTimeout(_warnTimer);
        clearTimeout(_logoutTimer);
        clearInterval(_countdownInterval);
        _modal.style.display = 'none';
        top.restoreSession();
        top.location.href = '<?php echo $web_root; ?>/interface/logout.php?csrf_token_form=' + encodeURIComponent(csrf_token_js);
    }

    function _scheduleTimers() {
        _warnTimer   = setTimeout(_idleShowWarning, WARN_MS);
        _logoutTimer = setTimeout(function () {
            if (_warned) _idleLogout();  // _idleLogout sets timed_out=true
        }, LOGOUT_MS);
    }

    // Reset timers on any user interaction
    ['mousemove', 'keydown', 'mousedown', 'touchstart', 'scroll', 'click']
        .forEach(function (ev) {
            document.addEventListener(ev, function () {
                if (!_warned) {
                    clearTimeout(_warnTimer);
                    clearTimeout(_logoutTimer);
                    _scheduleTimers();
                }
            }, { passive: true });
        });

    // Escape dismisses warning
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && _warned) _idleReset();
    });

    _scheduleTimers();
}());
</script>

</body>

</html>
<?php if (ob_get_level() > 0) { ob_end_flush(); } ?>
