<?php

/**
 * Login screen.
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 * @author  Rod Roark <rod@sunsetsystems.com>
 * @author  Brady Miller <brady.g.miller@gmail.com>
 * @author  Kevin Yeh <kevin.y@integralemr.com>
 * @author  Scott Wakefield <scott.wakefield@gmail.com>
 * @author  ViCarePlus <visolve_emr@visolve.com>
 * @author  Julia Longtin <julialongtin@diasp.org>
 * @author  cfapress
 * @author  markleeds
 * @author  Tyler Wrenn <tyler@tylerwrenn.com>
 * @author  Ken Chapple <ken@mi-squared.com>
 * @author  Daniel Pflieger <daniel@mi-squared.com> <daniel@growlingflea.com>
 * @author  Robert Down <robertdown@live.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2020 Tyler Wrenn <tyler@tylerwrenn.com>
 * @copyright Copyright (c) 2021 Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2021 Daniel Pflieger <daniel@mi-squared.com> <daniel@growlingflea.com>
 * @copyright Copyright (c) 2021-2023 Robert Down <robertdown@live.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// prevent UI redressing
Header("X-Frame-Options: DENY");
Header("Content-Security-Policy: frame-ancestors 'none'");

use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Events\Core\TemplatePageEvent;
use OpenEMR\Services\FacilityService;
use OpenEMR\Services\LogoService;
use Symfony\Component\EventDispatcher\EventDispatcher;

$ignoreAuth = true;
// Set $sessionAllowWrite to true to prevent session concurrency issues during authorization related code
$sessionAllowWrite = true;
require_once("../globals.php");

$twig = new TwigContainer(null, $GLOBALS["kernel"]);
$t = $twig->getTwig();

$logoService = new LogoService();
$primaryLogo = $logoService->getLogo("core/login/primary");
$secondaryLogo = $logoService->getLogo("core/login/secondary");
$smallLogoOne = $logoService->getLogo("core/login/small_logo_1");
$smallLogoTwo = $logoService->getLogo("core/login/small_logo_2");

$layout = $GLOBALS['login_page_layout'];

// mdsupport - Add 'App' functionality for user interfaces without standard menu and frames
// If this script is called with app parameter, validate it without showing other apps.
//
// Build a list of valid entries
$emr_app = array();
$sql = "SELECT option_id, title,is_default FROM list_options WHERE list_id=? and activity=1 ORDER BY seq, option_id";
$rs = sqlStatement($sql, ['apps']);
if (sqlNumRows($rs)) {
    while ($app = sqlFetchArray($rs)) {
        $app_req = explode('?', trim($app['title']));
        if (! file_exists('../' . $app_req[0])) {
            continue;
        }

        $emr_app [trim($app ['option_id'])] = trim($app ['title']);
        if ($app ['is_default']) {
            $emr_app_def = $app ['option_id'];
        }
    }
}

$div_app = '';
if (count($emr_app)) {
    // Standard app must exist
    $std_app = 'main/main_screen.php';
    if (!in_array($std_app, $emr_app)) {
        $emr_app['*OpenEMR'] = $std_app;
    }

    if (isset($_REQUEST['app']) && $emr_app[$_REQUEST['app']]) {
        $div_app = sprintf('<input type="hidden" name="appChoice" value="%s">', attr($_REQUEST['app']));
    } else {
        foreach ($emr_app as $opt_disp => $opt_value) {
            $opt_htm .= sprintf(
                '<option value="%s" %s>%s</option>\n',
                attr($opt_disp),
                ($opt_disp == $opt_default ? 'selected="selected"' : ''),
                text(xl_list_label($opt_disp))
            );
        }

        $div_app = sprintf(
            '
            <div id="divApp" class="form-group">
                <label for="appChoice" class="text-right">%s:</label>
                <div>
                    <select class="form-control" id="selApp" name="appChoice" size="1">%s</select>
                </div>
            </div>',
            xlt('App'),
            $opt_htm
        );
    }
}


function getDefaultLanguage(): array
{
    $sql = "SELECT * FROM lang_languages where lang_description = ?";
    $res = sqlStatement($sql, [$GLOBALS['language_default']]);
    $langs = [];

    while ($row = sqlFetchArray($res)) {
        $langs[] = $row;
    }

    $id = 1;
    $desc = "English";

    if (count($langs) == 1) {
        $id = $langs[0]["lang_id"];
        $desc = $langs[0]["lang_description"];
    }

    return ["id" => $id, "language" => $desc];
}

function getLanguagesList(): array
{
    $mainLangID = empty($_SESSION['language_choice']) ? '1' : $_SESSION['language_choice'];
    $sql = "SELECT ll.lang_id, IF(LENGTH(ld.definition), ld.definition, ll.lang_description) AS trans_lang_description, ll.lang_description
        FROM lang_languages AS ll
        LEFT JOIN lang_constants AS lc ON lc.constant_name = ll.lang_description
        LEFT JOIN lang_definitions AS ld ON ld.cons_id = lc.cons_id AND ld.lang_id = ?
        ORDER BY IF(LENGTH(ld.definition),ld.definition,ll.lang_description), ll.lang_id";
    $res = sqlStatement($sql, [$mainLangID]);
    $langList = [];

    while ($row = sqlFetchArray($res)) {
        $langList[] = $row;
    }

    return $langList;
}

$facilities = [];
$facilitySelected = false;
if ($GLOBALS['login_into_facility']) {
    $facilityService = new FacilityService();
    $facilities = $facilityService->getAllFacility();
    $facilitySelected = ($GLOBALS['set_facility_cookie'] && isset($_COOKIE['pc_facility'])) ? $_COOKIE['pc_facility'] : null;
}

$defaultLanguage = getDefaultLanguage();
$languageList = getLanguagesList();
$_SESSION['language_choice'] = $defaultLanguage['id'];

$relogin = (isset($_SESSION['relogin']) && ($_SESSION['relogin'] == 1)) ? true : false;
if ($relogin) {
    unset($_SESSION["relogin"]);
}

$t1 = $GLOBALS['tiny_logo_1'];
$t2 = $GLOBALS['tiny_logo_2'];
$displaySmallLogo = false;
if ($t1 && !$t2) {
    $displaySmallLogo = 1;
} if ($t2 && !$t1) {
    $displaySmallLogo = 2;
} if ($t1 && $t2) {
    $displaySmallLogo = 3;
}

$regTranslations = json_encode(array(
    'title' => xla('OpenEMR Product Registration'),
    'pleaseProvideValidEmail' => xla('Please provide a valid email address'),
    'success' => xla('Success'),
    'registeredSuccess' => xla('Your installation of OpenEMR has been registered'),
    'submit' => xla('Submit'),
    'noThanks' => xla('No Thanks'),
    'registeredEmail' => xla('Registered email'),
    'registeredId' => xla('Registered id'),
    'genericError' => xla('Error. Try again later'),
    'closeTooltip' => ''
));

$cookie = '';
if (session_name()) {
    $sid = urlencode(session_id());
    $sname = urlencode(session_name());
    $scparams = session_get_cookie_params();
    $domain = $scparams['domain'];
    $path = $scparams['path'];
    $oldDate = gmdate('Y', strtotime("-1 years"));
    $expires = gmdate(DATE_RFC1123, $oldDate);
    $sameSite = empty($scparams['samesite']) ? '' : $scparams['samesite'];
    $cookie = "{$sname}={$sid}; path={$path}; domain={$domain}; expires={$expires}";

    if ($sameSite) {
        $cookie .= "; SameSite={$sameSite}";
    }

    $cookie = json_encode($cookie);
}

$viewArgs = [
    'title' => $openemr_name,
    'displayLanguage' => ($GLOBALS["language_menu_login"] && (count($languageList) != 1)) ? true : false,
    'defaultLangID' => $defaultLanguage['id'],
    'defaultLangName' => $defaultLanguage['language'],
    'languageList' => $languageList,
    'relogin' => $relogin,
    'loginFail' => (isset($_SESSION["loginfailure"]) && $_SESSION["loginfailure"] == 1) ? true : false,
    'displayFacilities' => ($GLOBALS["login_into_facility"]) ? true : false,
    'facilityList' => $facilities,
    'facilitySelected' => $facilitySelected,
    'displayGoogleSignin' => (!empty($GLOBALS['google_signin_enabled']) && !empty($GLOBALS['google_signin_client_id'])) ? true : false,
    'googleSigninClientID' => $GLOBALS['google_signin_client_id'],
    'displaySmallLogo' => $displaySmallLogo,
    'smallLogoOne' => $smallLogoOne,
    'smallLogoTwo' => $smallLogoTwo,
    'displayTagline' => $GLOBALS['show_tagline_on_login'],
    'tagline' => $GLOBALS['login_tagline_text'],
    'displayAck' => $GLOBALS['display_acknowledgements_on_login'],
    'hasSession' => (session_name()) ? true : false,
    'cookieText' => $cookie,
    'regTranslations' => $regTranslations,
    'regConstants' => json_encode(['webroot' => $GLOBALS['webroot']]),
    'siteID' => $_SESSION['site_id'],
    'showLabels' => $GLOBALS['show_labels_on_login_form'],
    'primaryLogo'   => $primaryLogo,
    'displaySecondaryLogo' => $GLOBALS['extra_logo_login'],
    'secondaryLogo' => $secondaryLogo,
    'secondaryLogoPosition' => $GLOBALS['secondary_logo_position'],
];

/**
 * @var EventDispatcher;
 */
$ed = $GLOBALS['kernel']->getEventDispatcher();

$templatePageEvent = new TemplatePageEvent('login/login.php', [], $layout, $viewArgs);
$event = $ed->dispatch($templatePageEvent, TemplatePageEvent::RENDER_EVENT);

$rendered = $t->render($event->getTwigTemplate(), $event->getTwigVariables());

// ── Inject MDI styles + Forgot Password link ──────────────────────
$mdiInject = <<<'MDIEND'
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap');
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
*, *::before, *::after { box-sizing: border-box; }
body, html { font-family: 'Montserrat', sans-serif !important; }
.card.border-0.shadow, .card.shadow, [class*="login"] .card, .login-box {
    background: rgba(255,255,255,0.97) !important; border: none !important;
    border-radius: 20px !important;
    box-shadow: 0 24px 64px rgba(30,10,60,0.22), 0 0 0 1px rgba(167,139,250,0.18), 0 0 48px rgba(124,58,237,0.08) !important;
    overflow: hidden; animation: mdi-glow-pulse 4s ease-in-out infinite;
}
@keyframes mdi-glow-pulse {
    0%,100% { box-shadow: 0 24px 64px rgba(30,10,60,0.22),0 0 0 1px rgba(167,139,250,0.18),0 0 48px rgba(124,58,237,0.08); }
    50%      { box-shadow: 0 24px 64px rgba(30,10,60,0.28),0 0 0 1px rgba(167,139,250,0.30),0 0 64px rgba(124,58,237,0.16); }
}
.card-header, .login-header {
    background: linear-gradient(160deg,#1e0a3c 0%,#3b0764 50%,#5b21b6 100%) !important;
    border: none !important; padding: 28px 32px 24px !important; position: relative; overflow: hidden;
}
.card-header::before { content:''; position:absolute; inset:0; background:radial-gradient(ellipse at 70% 50%,rgba(167,139,250,.25) 0%,transparent 65%); pointer-events:none; }
.card-header::after { content:''; position:absolute; bottom:-1px; left:0; right:0; height:32px; background:linear-gradient(to bottom right,transparent 49%,rgba(255,255,255,0.97) 50%); }
.card-header img, .login-header img { filter:brightness(0) invert(1) !important; max-height:44px !important; }
.card-header h1,.card-header h2,.card-header h3,.card-header h4,.card-header .h4,.card-header p { color:#fff !important; font-family:'Montserrat',sans-serif !important; font-weight:700 !important; }
.card-body, .login-body { padding: 28px 32px 32px !important; }
.card-body label,.login-body label,.form-group label { font-family:'Montserrat',sans-serif !important; font-size:11.5px !important; font-weight:700 !important; text-transform:uppercase !important; letter-spacing:0.08em !important; color:#6b21a8 !important; margin-bottom:5px !important; }
.card-body .form-control,.login-body .form-control,input[name="authUser"],input[name="clearPass"] { border:1.5px solid #ddd6fe !important; border-radius:10px !important; padding:11px 14px !important; font-family:'JetBrains Mono',monospace !important; font-size:13px !important; color:#1e0a3c !important; background:#fafafe !important; transition:all 0.2s !important; box-shadow:none !important; }
.card-body .form-control:focus,input[name="authUser"]:focus,input[name="clearPass"]:focus { border-color:#7c3aed !important; background:#fff !important; outline:none !important; box-shadow:0 0 0 3px rgba(124,58,237,.12) !important; }
button[type="submit"],.card-body .btn-primary,.login-body .btn-primary { background:linear-gradient(135deg,#5b21b6 0%,#7c3aed 100%) !important; border:none !important; border-radius:12px !important; padding:13px 24px !important; font-family:'Montserrat',sans-serif !important; font-size:14px !important; font-weight:700 !important; color:#fff !important; width:100% !important; transition:all 0.2s !important; box-shadow:0 4px 14px rgba(91,33,182,.35) !important; }
button[type="submit"]:hover,.card-body .btn-primary:hover { transform:translateY(-1px) !important; box-shadow:0 6px 20px rgba(91,33,182,.45) !important; }
.card-body .form-group { margin-bottom:18px !important; }
.card-footer { background:#fafafe !important; border-top:1px solid #f3f0ff !important; padding:14px 32px !important; border-radius:0 0 20px 20px !important; }
.alert-danger { background:#fef2f2 !important; border:1px solid #fecaca !important; border-radius:10px !important; color:#991b1b !important; font-size:12.5px !important; }
/* Forgot password link */
.mdi-forgot-link { display:block; text-align:right; margin-top:-10px; margin-bottom:14px; font-size:12px; color:#7c3aed; text-decoration:none; font-weight:600; transition:color .15s; }
.mdi-forgot-link:hover { color:#5b21b6; }
body{background:url('slide-1.jpg') no-repeat center center fixed !important; background-size: cover}
</style>
<script>
// Inject "Forgot password?" link after username/password fields render
document.addEventListener('DOMContentLoaded', function() {
    var passField = document.querySelector('input[name="clearPass"], input[type="password"]');
    if (passField) {
        var fg = passField.closest('.form-group') || passField.parentElement;
        if (fg) {
            var link = document.createElement('a');
            link.href = 'mdi_forgot_password.php';
            link.className = 'mdi-forgot-link';
            link.textContent = 'Forgot password?';
            fg.after(link);
        }
    }
});
</script>
MDIEND;

$rendered = str_replace('</head>', $mdiInject . '</head>', $rendered);
echo $rendered;
