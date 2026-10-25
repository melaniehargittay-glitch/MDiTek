<?php

/**
 * import_template.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Cassian LUP <cassi.lup@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Tyler Wrenn <tyler@tylerwrenn.com>
 * @copyright Copyright (c) 2011 Cassian LUP <cassi.lup@gmail.com>
 * @copyright Copyright (c) 2016-2023 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2020 Tyler Wrenn <tyler@tylerwrenn.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// prevent UI redressing
Header("X-Frame-Options: DENY");
Header("Content-Security-Policy: frame-ancestors 'none'");

//setting the session & other config options

// Will start the (patient) portal OpenEMR session/cookie.

require_once __DIR__ . "/../src/Common/Session/SessionUtil.php";
OpenEMR\Common\Session\SessionUtil::portalSessionStart();

//don't require standard openemr authorization in globals.php
$ignoreAuth_onsite_portal = true;

//includes

require_once '../interface/globals.php';
require_once __DIR__ . "/lib/appsql.class.php";
$logit = new ApplicationTable();

use OpenEMR\Common\Auth\OneTimeAuth;
use OpenEMR\Common\Crypto\CryptoGen;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Core\Header;
use OpenEMR\Services\LogoService;
use OpenEMR\Common\Auth\Exception\OneTimeAuthExpiredException;
use OpenEMR\Common\Auth\Exception\OneTimeAuthException;
use OpenEMR\Common\Twig\TwigContainer;

//For redirect if the site on session does not match
$landingpage = "index.php?site=" . urlencode($_SESSION['site_id']);
$logoService = new LogoService();
$logoSrc = $logoService->getLogo("portal/login/primary");

// allow both get and post redirect params here... everything will be sanitized in get_patient_info.php before we
// actually do anything with the redirect
// this value should already be url encoded.
$redirectUrl = $_REQUEST['redirect'] ?? '';

//exit if portal is turned off
if (!(isset($GLOBALS['portal_onsite_two_enable'])) || !($GLOBALS['portal_onsite_two_enable'])) {
    echo xlt('Patient Portal is turned off');
    exit;
}
$auth['portal_pwd'] = '';
if (isset($_GET['woops'])) {
    unset($_GET['woops']);
    unset($_SESSION['password_update']);
}

/*
 * Patient for onetime is verified when token redirect is decoded.
 * The embedded pid in token is compared to the token looked up result pid.
 * Also verified as the portal account id is rebuilt from patient data
 * and compared to portal credential account id lookup.
 * */
if (!empty($_REQUEST['service_auth'] ?? null)) {
    if (!empty($_GET['service_auth'] ?? null)) {
        // we have to setup the csrf key to preven CSRF Login attacks
        // we also implement this mechanism in order to handle Same-Site cookie blocking when being referred by
        // an external site domain.  We used to auto process via GET but now we submit via the POST in order to make it
        // a same site cookie origin request. This is a workaround for the Same-Site cookie blocking.
        CsrfUtils::setupCsrfKey();
        $twig = new TwigContainer(null, $GLOBALS['kernel']);
        echo $twig->getTwig()->render('portal/login/autologin.html.twig', [
            'action' => $GLOBALS['web_root'] . '/portal/index.php',
            'service_auth' => $_GET['service_auth'],
            'target' => $_GET['target'] ?? null,
            'csrf_token' => CsrfUtils::collectCsrfToken('autologin'),
            'pagetitle' => xl("OpenEMR Patient Portal"),
            'images_static_relative' => $GLOBALS['images_static_relative'] ?? ''
        ]);
        exit;
    } else if (!empty($_POST['service_auth'] ?? null)) {
        $token = $_POST['service_auth'];
        $redirect_token = $_POST['target'] ?? null;
        $csrfToken = $_POST['csrf_token'] ?? null;
        try {
            if (!CsrfUtils::verifyCsrfToken($csrfToken, 'autologin')) {
                throw new OneTimeAuthException('Invalid CSRF token');
            }
            $oneTime = new OneTimeAuth();
            $auth = $oneTime->processOnetime($token, $redirect_token);
            $logit->portalLog('onetime login attempt', $auth['pid'], 'patient logged in and redirecting', '', '1');
            exit();
        } catch (OneTimeAuthExpiredException $exception) {
            $logit->portalLog(
                'onetime login attempt',
                $exception->getPid() ?? '',
                ':invalid one time',
                '',
                '0'
            );
            // do we want a separate message that their token has expired?
            OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
            header('Location: ' . $landingpage . '&oe');
            exit();
        } catch (OneTimeAuthException $exception) {
            $logit->portalLog(
                'onetime login attempt',
                $exception->getPid() ?? '',
                ':invalid one time',
                '',
                '0'
            );
            OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
            header('Location: ' . $landingpage . '&oi');
            exit();
        }
    } else {
        (new SystemLogger())->errorLogCaller("Invalid service_auth request - should never reach here");
        exit();
    }
}

if (!empty($_GET['forward_email_verify'])) {
    if (empty($GLOBALS['portal_onsite_two_register']) || empty($GLOBALS['google_recaptcha_site_key']) || empty($GLOBALS['google_recaptcha_secret_key'])) {
        (new SystemLogger())->debug("registration not supported, so stopped attempt to use forward_email_verify token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }

    $crypto = new CryptoGen();
    if (!$crypto->cryptCheckStandard($_GET['forward_email_verify'])) {
        (new SystemLogger())->debug("illegal token, so stopped attempt to use forward_email_verify token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }

    $token_one_time = $crypto->decryptStandard($_GET['forward_email_verify'], null, 'drive', 6);
    if (empty($token_one_time)) {
        (new SystemLogger())->debug("unable to decrypt token, so stopped attempt to use forward_email_verify token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }

    $sqlResource = sqlStatementNoLog("SELECT `id`, `token_onetime`, `fname`, `mname`, `lname`, `dob`, `email`, `language` FROM `verify_email` WHERE `active` = 1 AND `token_onetime` LIKE BINARY ?", [$token_one_time . '%']);
    if (sqlNumRows($sqlResource) > 1) {
        (new SystemLogger())->debug("active token (" . $token_one_time . ") found more than once, so stopped attempt to use forward_email_verify token");
        EventAuditLogger::instance()->newEvent('patient-reg-email-verify', '', '', 0, "active token (" . $token_one_time . ") found more than once, so stopped attempt to use forward_email_verify token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }
    if (!sqlNumRows($sqlResource)) {
        (new SystemLogger())->debug("active token (" . $token_one_time . ") not found, so stopped attempt to use forward_email_verify token");
        EventAuditLogger::instance()->newEvent('patient-reg-email-verify', '', '', 0, "active token (" . $token_one_time . ") not found, so stopped attempt to use forward_email_verify token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }
    $sqlVerify = sqlFetchArray($sqlResource);
    if (empty($sqlVerify['id']) || empty($sqlVerify['token_onetime'])) {
        (new SystemLogger())->debug("active token (" . $token_one_time . ") not properly set up, so stopped attempt to use forward_email_verify token");
        EventAuditLogger::instance()->newEvent('patient-reg-email-verify', '', '', 0, "active token (" . $token_one_time . ") not properly set up, so stopped attempt to use forward_email_verify token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }
    // have "used" token, so now make it inactive
    sqlStatementNoLog("UPDATE `verify_email` SET `active` = 0 WHERE `id` = ?", [$sqlVerify['id']]);

    $validateTime = hex2bin(str_replace($token_one_time, '', $sqlVerify['token_onetime']));
    if ($validateTime <= time()) {
        (new SystemLogger())->debug("active token (" . $token_one_time . ") has expired, so stopped attempt to use forward_email_verify token");
        EventAuditLogger::instance()->newEvent('patient-reg-email-verify', '', '', 0, "active token (" . $token_one_time . ") has expired, so stopped attempt to use forward_email_verify token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        die(xlt("Your email verification link has expired. Reset and try again."));
    }

    if (!empty($sqlVerify['fname']) && !empty($sqlVerify['lname']) && !empty($sqlVerify['dob']) && !empty($sqlVerify['email']) && !empty($sqlVerify['language'])) {
        // token has passed and have all needed data
        $fnameRegistration = $sqlVerify['fname'];
        $_SESSION['fnameRegistration'] = $fnameRegistration;
        $mnameRegistration = $sqlVerify['mname'] ?? '';
        $_SESSION['mnameRegistration'] = $mnameRegistration;
        $lnameRegistration = $sqlVerify['lname'];
        $_SESSION['lnameRegistration'] = $lnameRegistration;
        $dobRegistration = $sqlVerify['dob'];
        $_SESSION['dobRegistration'] = $dobRegistration;
        $emailRegistration = $sqlVerify['email'];
        $_SESSION['emailRegistration'] = $emailRegistration;
        $languageRegistration = $sqlVerify['language'];
        $_SESSION['language_choice'] = (int)($languageRegistration ?? 1);
        $portalRegistrationAuthorization = true;
        $_SESSION['token_id_holder'] = $sqlVerify['id'];
        (new SystemLogger())->debug("token worked for forward_email_verify token, now on to registration");
        EventAuditLogger::instance()->newEvent('patient-reg-email-verify', '', '', 1, "token (" . $token_one_time . ") was successful for forward_email_verify token");
        require_once(__DIR__ . "/account/register.php");
        exit();
    } else {
        (new SystemLogger())->debug("active token (" . $token_one_time . ") did not have all required data, so stopped attempt to use forward_email_verify token");
        EventAuditLogger::instance()->newEvent('patient-reg-email-verify', '', '', 0, "active token (" . $token_one_time . ") did not have all required data, so stopped attempt to use forward_email_verify token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }
} elseif (isset($_GET['forward'])) {
    if ((empty($GLOBALS['portal_two_pass_reset']) && empty($GLOBALS['portal_onsite_two_register'])) || empty($GLOBALS['google_recaptcha_site_key']) || empty($GLOBALS['google_recaptcha_secret_key'])) {
        (new SystemLogger())->debug("reset password and registration not supported, so stopped attempt to use forward token");
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }
    $auth = false;
    if (strlen($_GET['forward']) >= 64) {
        $crypto = new CryptoGen();
        if ($crypto->cryptCheckStandard($_GET['forward'])) {
            $one_time = $crypto->decryptStandard($_GET['forward'], null, 'drive', 6);
            if (!empty($one_time)) {
                $auth = sqlQueryNoLog("Select * From patient_access_onsite Where portal_onetime Like BINARY ?", array($one_time . '%'));
            }
        }
    }
    if ($auth === false) {
        error_log("PORTAL ERROR: " . errorLogEscape('One time reset:' . $_GET['forward']), 0);
        $logit->portalLog('login attempt', '', ($_GET['forward'] . ':invalid one time'), '', '0');
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        header('Location: ' . $landingpage . '&w&u');
        exit();
    }
    $parse = str_replace($one_time, '', $auth['portal_onetime']);
    $validate = hex2bin(substr($parse, 6));
    if ($validate <= time()) {
        error_log("PORTAL ERROR: " . errorLogEscape('One time reset link expired. Dying.'), 0);
        $logit->portalLog('password reset attempt', '', ($_POST['uname'] . ':link expired'), '', '0');
        OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
        die(xlt("Your one time credential reset link has expired. Reset and try again.") . "time:$validate time:" . time());
    }
    $_SESSION['pin'] = substr($parse, 0, 6);
    $_SESSION['forward'] = $auth['portal_onetime'];
    $_SESSION['portal_username'] = $auth['portal_username'];
    $_SESSION['portal_login_username'] = $auth['portal_login_username'];
    $_SESSION['password_update'] = 2;
    $_SESSION['onetime'] = $auth['portal_pwd'];
    unset($auth);
}
// security measure -- will check on next page.
$_SESSION['itsme'] = 1;
//

//
// Deal with language selection
//
// collect default language id (skip this if this is a password update or reset)
if (!(isset($_SESSION['password_update']) || (!empty($GLOBALS['portal_two_pass_reset']) && !empty($GLOBALS['google_recaptcha_site_key']) && !empty($GLOBALS['google_recaptcha_secret_key']) && isset($_GET['requestNew'])))) {
    $res2 = sqlStatement("select * from lang_languages where lang_description = ?", array($GLOBALS['language_default']));
    for ($iter = 0; $row = sqlFetchArray($res2); $iter++) {
        $result2[$iter] = $row;
    }

    if (count($result2) == 1) {
        $defaultLangID = $result2[0]["lang_id"];
        $defaultLangName = $result2[0]["lang_description"];
    } else {
        //default to english if any problems
        $defaultLangID = 1;
        $defaultLangName = "English";
    }

    // set session variable to default so login information appears in default language
    $_SESSION['language_choice'] = $defaultLangID;
    // collect languages if showing language menu
    if ($GLOBALS['language_menu_login']) {
        // sorting order of language titles depends on language translation options.
        $mainLangID = empty($_SESSION['language_choice']) ? '1' : $_SESSION['language_choice'];
        // Use and sort by the translated language name.
        $sql = "SELECT ll.lang_id, " .
            "IF(LENGTH(ld.definition),ld.definition,ll.lang_description) AS trans_lang_description, " .
            "ll.lang_description " .
            "FROM lang_languages AS ll " .
            "LEFT JOIN lang_constants AS lc ON lc.constant_name = ll.lang_description " .
            "LEFT JOIN lang_definitions AS ld ON ld.cons_id = lc.cons_id AND " .
            "ld.lang_id = ? " .
            "ORDER BY IF(LENGTH(ld.definition),ld.definition,ll.lang_description), ll.lang_id";
        $res3 = SqlStatement($sql, array($mainLangID));
        for ($iter = 0; $row = sqlFetchArray($res3); $iter++) {
            $result3[$iter] = $row;
        }
        if (count($result3) == 1) {
            //default to english if only return one language
            $hiddenLanguageField = "<input type='hidden' name='languageChoice' value='1' />\n";
        }
    } else {
        $hiddenLanguageField = "<input type='hidden' name='languageChoice' value='" . attr($defaultLangID) . "' />\n";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Patient Portal Login'); ?></title>
    <style>
    /* ── LAYOUT ───────────────────────────────────── */
.page {
  display: grid;
  grid-template-columns: 1fr 1fr;
  height: 100vh;
}

/* ── LEFT PANEL ───────────────────────────────── */
.left {
  background: #47377a;
  position: relative;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  overflow: hidden;
  padding: 2.5rem;
}

/* Organic blob shapes */
.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(60px);
  pointer-events: none;
}

.blob-1 {
  width: 500px; height: 500px;
  background: rgba(71,55,122,0.5);
  top: -120px; left: -100px;
  animation: drift1 12s ease-in-out infinite alternate;
}

.blob-2 {
  width: 400px; height: 400px;
  background: rgba(104,59,249,0.6);
  bottom: -100px; right: -80px;
  animation: drift2 15s ease-in-out infinite alternate;
}

.blob-3 {
  width: 250px; height: 250px;
  background: rgba(71,55,122,0.2);
  top: 40%; left: 30%;
  animation: drift3 10s ease-in-out infinite alternate;
}

@keyframes drift1 {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(40px, 30px) scale(1.08); }
}
@keyframes drift2 {
  from { transform: translate(0,0) scale(1); }
  to   { transform: translate(-30px, -20px) scale(1.05); }
}
@keyframes drift3 {
  from { transform: translate(0,0); }
  to   { transform: translate(-20px, 20px); }
}

/* Dot grid overlay */
.dot-grid {
  position: absolute;
  inset: 0;
  background-image: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
  background-size: 28px 28px;
  pointer-events: none;
}

/* Left content */
.left-content {
  position: relative;
  z-index: 1;
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 2rem 0;
}

.left-logo {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 10px;
}

.logo-circle {
  width: 38px; height: 38px;
  border-radius: 50%;
  background: rgba(255,255,255,0.15);
  border: 1px solid rgba(255,255,255,0.25);
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Gilda Display', serif;
  font-size: 1.1rem;
  color: #fff;
  backdrop-filter: blur(4px);
}

.logo-name {
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.85rem;
  font-weight: 600;
  color: rgba(255,255,255,0.9);
  letter-spacing: 0.04em;
}

.logo-sub {
  font-size: 0.6rem;
  color: rgba(255,255,255,0.45);
  letter-spacing: 0.12em;
  text-transform: uppercase;
  display: block;
}

.left-headline {
  font-family: 'Helvetica', serif;
  font-size: 2.8rem;
  color: #fff;
  line-height: 1.2;
  letter-spacing: -0.02em;
  margin-bottom: 1.2rem;
  max-width: 320px;
}

.left-headline em {
  color: rgba(255,255,255,0.55);
  font-style: italic;
}

.left-desc {
  font-size: 0.82rem;
  color: rgba(255,255,255,0.6);
  line-height: 1.75;
  max-width: 300px;
  font-weight: 300;
}

/* Feature pills */
.features {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 2rem;
  position: relative;
  z-index: 1;
}

.feature {
  display: flex;
  align-items: center;
  gap: 10px;
  animation: slideRight 0.5s ease both;
}

.feature:nth-child(1) { animation-delay: 0.1s; }
.feature:nth-child(2) { animation-delay: 0.2s; }
.feature:nth-child(3) { animation-delay: 0.3s; }
.feature:nth-child(4) { animation-delay: 0.4s; }

@keyframes slideRight {
  from { opacity: 0; transform: translateX(-12px); }
  to   { opacity: 1; transform: translateX(0); }
}

.feature-icon {
  width: 30px; height: 30px;
  border-radius: 8px;
  background: rgba(255,255,255,0.1);
  border: 1px solid rgba(255,255,255,0.15);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 0.75rem;
}

.feature-text {
  font-size: 0.76rem;
  color: rgba(255,255,255,0.7);
  font-weight: 400;
  line-height: 1.4;
}

.feature-text strong {
  color: rgba(255,255,255,0.95);
  font-weight: 500;
  display: block;
}

/* Left footer */
.left-footer {
  position: relative;
  z-index: 1;
  font-size: 0.6rem;
  color: rgba(255,255,255,0.3);
  letter-spacing: 0.08em;
}

/* ── RIGHT PANEL ──────────────────────────────── */
.right {
  background: var(--cream);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2rem;
  overflow-y: auto;
}

.form-container {
  width: 100%;
  max-width: 380px;
  animation: fadeUp 0.5s ease 0.1s both;
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}

.form-header {
  margin-bottom: 2.2rem;
}

.form-eyebrow {
  font-size: 0.6rem;
  letter-spacing: 0.2em;
  text-transform: uppercase;
  color: var(--sage);
  font-weight: 600;
  margin-bottom: 8px;
}

.bg-primary.text-white.pt-2.py-1 h3{
    font-size:1rem !important;
}

.form-title {
  font-family: 'Helvetica', serif;
  font-size: 2rem;
  color: var(--text);
  letter-spacing: -0.02em;
  line-height: 1.15;
  margin-bottom: 8px;
}

.form-subtitle {
  font-size: 0.78rem;
  color: var(--muted);
  line-height: 1.6;
  font-weight: 300;
}

/* Tabs */
.auth-tabs {
  display: flex;
  background: var(--warm);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 3px;
  margin-bottom: 1.8rem;
}

.auth-tab {
  flex: 1;
  padding: 0.5rem;
  text-align: center;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.74rem;
  font-weight: 500;
  color: var(--muted);
  transition: all 0.2s;
  user-select: none;
  border: none;
  background: transparent;
  font-family: 'Plus Jakarta Sans', sans-serif;
}

.auth-tab.active {
  background: var(--cream);
  color: var(--text);
  box-shadow: 0 1px 4px rgba(0,0,0,0.1);
}

/* Forms */
.form-panel { display: none; }
.form-panel.active { display: block; }

.field-group {
  margin-bottom: 1.1rem;
}

.field-label {
  display: block;
  font-size: 0.68rem;
  font-weight: 600;
  color: var(--text2);
  letter-spacing: 0.04em;
  margin-bottom: 6px;
}

.field-wrap {
  position: relative;
}

.field-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--muted2);
  pointer-events: none;
  display: flex;
  align-items: center;
}

.field-input {
  width: 100%;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.82rem;
  color: var(--text);
  background: var(--cream);
  border: 1.5px solid var(--border);
  border-radius: 8px;
  padding: 0.72rem 1rem 0.72rem 2.4rem;
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
}

.field-input::placeholder { color: var(--muted2); }

.field-input:focus {
  border-color: var(--sage);
  box-shadow: 0 0 0 3px rgba(61,107,94,0.1);
}

.field-input.error {
  border-color: var(--error);
  box-shadow: 0 0 0 3px rgba(192,57,43,0.08);
}

/* Password toggle */
.pw-toggle {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  cursor: pointer;
  color: var(--muted2);
  padding: 4px;
  display: flex;
  transition: color 0.15s;
}

.pw-toggle:hover { color: var(--text2); }

/* Error message */
.field-error {
  font-size: 0.64rem;
  color: var(--error);
  margin-top: 4px;
  display: none;
}

.field-error.show { display: block; }

/* Remember / forgot row */
.form-options {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.5rem;
}

.check-label {
  display: flex;
  align-items: center;
  gap: 7px;
  cursor: pointer;
  font-size: 0.72rem;
  color: var(--muted);
  user-select: none;
}

.check-label input[type="checkbox"] {
  width: 15px; height: 15px;
  accent-color: var(--sage);
  cursor: pointer;
  border-radius: 3px;
}

.forgot-link {
  font-size: 0.72rem;
  color: var(--sage);
  text-decoration: none;
  font-weight: 500;
  transition: color 0.15s;
}

.forgot-link:hover { color: var(--sage2); text-decoration: underline; }

/* Submit button */
.submit-btn {
  width: 100%;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.84rem;
  font-weight: 600;
  color: #fff;
  background: var(--sage);
  border: none;
  border-radius: 8px;
  padding: 0.82rem;
  cursor: pointer;
  transition: background 0.2s, transform 0.1s, box-shadow 0.2s;
  letter-spacing: 0.02em;
  position: relative;
  overflow: hidden;
}

.submit-btn::after {
  content: '';
  position: absolute;
  inset: 0;
  background: rgba(255,255,255,0);
  transition: background 0.2s;
}

.submit-btn:hover { background: var(--sage2); box-shadow: 0 4px 16px rgba(61,107,94,0.3); }
.submit-btn:active { transform: scale(0.99); }

.submit-btn.loading {
  pointer-events: none;
  opacity: 0.75;
}

/* Spinner */
.spinner {
  display: none;
  width: 16px; height: 16px;
  border: 2px solid rgba(255,255,255,0.4);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin 0.7s linear infinite;
  margin: 0 auto;
}

.loading .spinner { display: block; }
.loading .btn-text { display: none; }
@keyframes spin { to { transform: rotate(360deg); } }

/* Divider */
.divider {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 1.3rem 0;
}

.divider-line {
  flex: 1;
  height: 1px;
  background: var(--border);
}

.divider-text {
  font-size: 0.65rem;
  color: var(--muted2);
  white-space: nowrap;
  letter-spacing: 0.06em;
}

/* SSO buttons */
.sso-row {
  display: flex;
  gap: 0.6rem;
}

.sso-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  padding: 0.62rem;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  background: var(--cream);
  cursor: pointer;
  font-family: 'Plus Jakarta Sans', sans-serif;
  font-size: 0.72rem;
  font-weight: 500;
  color: var(--text2);
  transition: all 0.15s;
}

.sso-btn:hover { border-color: var(--border2); background: var(--warm); }

/* Register link */
.register-link {
  text-align: center;
  margin-top: 1.5rem;
  font-size: 0.72rem;
  color: var(--muted);
}

.register-link a {
  color: var(--sage);
  font-weight: 600;
  text-decoration: none;
  transition: color 0.15s;
}

.register-link a:hover { color: var(--sage2); text-decoration: underline; }

/* MFA panel */
.mfa-panel {
  display: none;
  text-align: center;
  animation: fadeUp 0.35s ease both;
}

.mfa-panel.show { display: block; }

.mfa-icon {
  width: 56px; height: 56px;
  background: var(--sage-pale);
  border-radius: 50%;
  border: 2px solid rgba(61,107,94,0.2);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1.2rem;
  font-size: 1.4rem;
}

.mfa-title {
  font-family: 'Gilda Display', serif;
  font-size: 1.5rem;
  color: var(--text);
  margin-bottom: 0.5rem;
}

.mfa-desc {
  font-size: 0.76rem;
  color: var(--muted);
  line-height: 1.6;
  margin-bottom: 1.8rem;
}

.otp-row {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-bottom: 1.5rem;
}

.otp-box {
  width: 46px; height: 54px;
  border: 1.5px solid var(--border);
  border-radius: 8px;
  font-family: 'Gilda Display', serif;
  font-size: 1.5rem;
  text-align: center;
  color: var(--text);
  background: var(--cream);
  outline: none;
  transition: border-color 0.15s, box-shadow 0.15s;
  caret-color: var(--sage);
}

.otp-box:focus {
  border-color: var(--sage);
  box-shadow: 0 0 0 3px rgba(61,107,94,0.1);
}

.resend-link {
  font-size: 0.72rem;
  color: var(--muted);
  margin-top: 0.8rem;
}

.resend-link a {
  color: var(--sage);
  font-weight: 500;
  cursor: pointer;
  text-decoration: none;
}

.resend-link a:hover { text-decoration: underline; }

.back-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.72rem;
  color: var(--muted);
  cursor: pointer;
  margin-bottom: 1.5rem;
  text-decoration: none;
  transition: color 0.15s;
}

.back-link:hover { color: var(--text2); }

/* HIPAA badge */
.hipaa-badge {
  display: flex;
  align-items: center;
  gap: 6px;
  background: var(--sage-pale);
  border: 1px solid rgba(61,107,94,0.2);
  border-radius: 6px;
  padding: 0.55rem 0.85rem;
  margin-top: 1.3rem;
}

.hipaa-icon {
  color: var(--sage);
  flex-shrink: 0;
}

.hipaa-text {
  font-size: 0.63rem;
  color: var(--muted);
  line-height: 1.5;
}

.hipaa-text strong {
  color: var(--sage);
  font-weight: 600;
}

/* Success state */
.success-panel {
  display: none;
  text-align: center;
  animation: fadeUp 0.4s ease both;
}

.success-panel.show { display: block; }

.success-ring {
  width: 64px; height: 64px;
  border-radius: 50%;
  background: var(--sage-pale);
  border: 2px solid rgba(61,107,94,0.3);
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0 auto 1.2rem;
  animation: pop 0.4s cubic-bezier(0.34,1.56,0.64,1) both;
}

@keyframes pop {
  from { transform: scale(0.5); opacity: 0; }
  to   { transform: scale(1); opacity: 1; }
}

/* Responsive */
@media (max-width: 768px) {
  .page { grid-template-columns: 1fr; }
  .left { display: none; }
}
</style>
    <?php
    Header::setupHeader(['no_main-theme', 'datetime-picker', 'patientportal-style', 'patientportal-base', 'patientportal-register']);
    ?>
    <script>
        function checkUserName() {
            let vacct = document.getElementById('uname').value;
            let vsuname = document.getElementById('login_uname').value;
            if (vsuname.length < 12) {
                alert(<?php echo xlj('User Name must be at least 12 characters!'); ?>);
                return false;
            }
            let data = {
                'action': 'userIsUnique',
                'account': vacct,
                'loginUname': vsuname
            };
            $.ajax({
                type: 'GET',
                url: './account/account.php',
                data: data
            }).done(function (rtn) {
                if (rtn === '1') {
                    return true;
                }
                alert(<?php echo xlj('Log In Name is unavailable. Try again!'); ?>);
                return false;
            });
        }

        function process() {
            if (!(validate())) {
                alert(<?php echo xlj('Field(s) are missing!'); ?>);
                return false;
            }
            return true;
        }

        function validate() {
            let pass = true;

            if (document.getElementById('uname').value == "") {
                $('#uname').addClass('is-invalid');
                pass = false;
            }
            if (document.getElementById('pass').value == "") {
                $('#pass').addClass('is-invalid');
                pass = false;
            }
            return pass;
        }

        function process_new_pass() {
            if (!(validate_new_pass())) {
                alert(<?php echo xlj('Field(s) are missing!'); ?>);
                return false;
            }
            if (document.getElementById('pass_new').value != document.getElementById('pass_new_confirm').value) {
                alert(<?php echo xlj('The new password fields are not the same.'); ?>);
                return false;
            }
            if (document.getElementById('pass').value == document.getElementById('pass_new').value) {
                alert(<?php echo xlj('The new password can not be the same as the current password.'); ?>);
                return false;
            }
        }

        function validate_new_pass() {
            var pass = true;
            if (document.getElementById('uname').value == "") {
                $('#uname').addClass('is-invalid');
                pass = false;
            }
            if (document.getElementById('pass').value == "") {
                $('#pass').addClass('is-invalid');
                pass = false;
            }
            if (document.getElementById('pass_new').value == "") {
                $('#pass_new').addClass('is-invalid');
                pass = false;
            }
            if (document.getElementById('pass_new_confirm').value == "") {
                $('#pass_new_confirm').addClass('is-invalid');
                pass = false;
            }
            return pass;
        }
    </script>

    <?php if (!empty($GLOBALS['portal_two_pass_reset']) && !empty($GLOBALS['google_recaptcha_site_key']) && !empty($GLOBALS['google_recaptcha_secret_key']) && isset($_GET['requestNew'])) { ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
        <script>
            function enableVerifyBtn(){
                document.getElementById("submitRequest").disabled = false;
            }
        </script>
        <?php // add csrf mechanism for the password reset ui
        CsrfUtils::setupCsrfKey();
        ?>
    <?php } ?>

</head>
<body class="login">
    <div class="page">
<!-- ── LEFT ── -->
<div class="left">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
    <div class="blob blob-3"></div>
    <div class="dot-grid"></div>

    <div class="left-logo">
      
      <div>
      <img style="width:150px;" class="img-fluid login-logo" src='<?php echo $logoSrc; ?>'>
        <span class="logo-sub">Patient Portal</span>
      </div>
    </div>

    <div class="left-content">
      <div class="left-headline">Your health,<br><em>at your</em><br>fingertips.</div>
      <p class="left-desc">Securely access your health records, communicate with your care team, and manage your appointments — all in one place.</p>
      <div class="features">
        <div class="feature">
          <div class="feature-icon">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="1.6" stroke-linecap="round"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M5 3V1.5M11 3V1.5M2 7h12"/></svg>
          </div>
          <div class="feature-text">
            <strong>Appointments</strong>
            Book, reschedule, or cancel visits online
          </div>
        </div>
        <div class="feature">
          <div class="feature-icon">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="1.6" stroke-linecap="round"><path d="M2 12l4-4 3 3 5-7"/></svg>
          </div>
          <div class="feature-text">
            <strong>Lab Results</strong>
            View test results as soon as they're available
          </div>
        </div>
        <div class="feature">
          <div class="feature-icon">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="1.6" stroke-linecap="round"><path d="M2 3h12a1 1 0 011 1v7a1 1 0 01-1 1H5l-3 2V4a1 1 0 011-1z"/></svg>
          </div>
          <div class="feature-text">
            <strong>Secure Messaging</strong>
            Message your doctor and care team directly
          </div>
        </div>
        <div class="feature">
          <div class="feature-icon">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="1.6" stroke-linecap="round"><rect x="2" y="2" width="12" height="12" rx="2"/><path d="M5 8h6M5 5h6M5 11h4"/></svg>
          </div>
          <div class="feature-text">
            <strong>Health Records</strong>
            Access visit notes, diagnoses, and medications
          </div>
        </div>
      </div>
    </div>

    <div class="left-footer">© 2026 MdiTek Software · HIPAA Compliant · v3.2.1</div>
  </div>
     <!-- ── RIGHT ── -->
     <div class="right">
    <div class="form-container">

      <!-- Login / Register tabs -->
      <div id="loginView">
        <div class="form-header">
          <div class="form-eyebrow">Patient Portal</div>
          <h1 class="form-title">Welcome back</h1>
          <p class="form-subtitle">Sign in to access your health records and care team.</p>
        </div>
        <?php if (isset($_SESSION['password_update']) || isset($_GET['password_update'])) {
            $_SESSION['password_update'] = 1;
            ?>
            <h2 class="title"><?php echo xlt('Please Enter New Credentials'); ?></h2>
            <form class="form pb-5" action="get_patient_info.php" method="POST" onsubmit="return process_new_pass()">
                <input style="display: none" type="text" name="dummyuname" />
                <input style="display: none" type="password" name="dummypass" />
                <?php if (isset($redirectUrl)) { ?>
                    <input id="redirect" type="hidden" name="redirect" value="<?php echo attr($redirectUrl); ?>" />
                <?php } ?>
                <div class="form-row my-3">
                    <label class="col-md-2 col-form-label" for="uname"><?php echo xlt('Account Name'); ?></label>
                    <div class="col-md">
                        <input class="form-control" name="uname" id="uname" type="text" readonly autocomplete="none" value="<?php echo attr($_SESSION['portal_username']); ?>" />
                    </div>
                </div>
                <div class="form-row my-3">
                    <label class="col-md-2 col-form-label" for="login_uname"><?php echo xlt('Use Username'); ?></label>
                    <div class="col-md">
                        <input class="form-control" name="login_uname" id="login_uname" type="text" autofocus autocomplete="none" title="<?php echo xla('Please enter a username of 12 to 80 characters. Recommended to include symbols and numbers but not required.'); ?>" placeholder="<?php echo xla('Must be 12 to 80 characters'); ?>" pattern=".{12,80}" value="<?php echo attr($_SESSION['portal_login_username']); ?>" onblur="checkUserName()" />
                    </div>
                </div>
                <div class="form-row my-3">
                    <label class="col-md-2 col-form-label" for="pass"><?php echo empty($_SESSION['onetime'] ?? null) ? xlt('Current Password') : ''; ?></label>
                    <div class="col-md">
                        <input class="form-control" name="pass" id="pass" <?php echo ($_SESSION['onetime'] ?? null) ? 'type="hidden" ' : 'type="password" '; ?> autocomplete="none" value="<?php echo attr($_SESSION['onetime'] ?? '');
                        $_SESSION['password_update'] = ($_SESSION['onetime'] ?? null) ? 2 : 1;
                        unset($_SESSION['onetime']); ?>" required />
                    </div>
                </div>
                <?php if ($_SESSION['pin'] ?? null) { ?>
                    <div class="form-row my-3">
                        <label class="col-md-2 col-form-label" for="token_pin"><?php echo xlt('One Time PIN'); ?></label>
                        <div class="col-md">
                            <input class="form-control" name="token_pin" id="token_pin" type="password" autocomplete="none" value="" required pattern=".{6,20}" />
                        </div>
                    </div>
                <?php } ?>
                <div class="form-row my-3">
                    <label class="col-md-2 col-form-label" for="pass_new"><?php echo xlt('New Password'); ?></label>
                    <div class="col-md">
                        <input class="form-control" name="pass_new" id="pass_new" type="password" required placeholder="<?php echo xla('Min length is 8 with upper,lowercase,numbers mix'); ?>" pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" />
                    </div>
                </div>
                <div class="form-row my-3">
                    <label class="col-md-2 col-form-label" for="pass_new_confirm"><?php echo xlt('Confirm New Password'); ?></label>
                    <div class="col-md">
                        <input class="form-control" name="pass_new_confirm" id="pass_new_confirm" type="password" required pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" />
                    </div>
                </div>
                <?php if ($GLOBALS['enforce_signin_email']) { ?>
                    <div class="form-row my-3">
                        <label class="col-md-2 col-form-label" for="passaddon"><?php echo xlt('Confirm Email Address'); ?></label>
                        <div class="col-md">
                            <input class="form-control" name="passaddon" id="passaddon" required placeholder="<?php echo xla('Current on record trusted email'); ?>" type="email" autocomplete="none" value="" />
                        </div>
                    </div>
                <?php } ?>
                <input class="btn btn-secondary float-left" type="button" onclick="document.location.replace('./index.php?woops=1&site=<?php echo attr_url($_SESSION['site_id']); ?><?php if (!empty($redirectUrl)) {
                    echo "&redirect=" . attr_url($redirectUrl); } ?>');" value="<?php echo xla('Cancel'); ?>" />
                <input class="btn btn-primary float-right" type="submit" value="<?php echo xla('Log In'); ?>" />
            </form>
        <?php } elseif (!empty($GLOBALS['portal_two_pass_reset']) && !empty($GLOBALS['google_recaptcha_site_key']) && !empty($GLOBALS['google_recaptcha_secret_key']) && isset($_GET['requestNew'])) { ?>
            <form id="resetPass" action="#" method="post">
                <input type='hidden' id='csrf_token_form' name='csrf_token_form' value='<?php echo attr(CsrfUtils::collectCsrfToken('passwordResetCsrf')); ?>' />
                <?php if (isset($redirectUrl)) { ?>
                    <input id="redirect" type="hidden" name="redirect" value="<?php echo attr($redirectUrl); ?>" />
                <?php } ?>
                <div class="text-center">
                    <fieldset>
                        <legend class='bg-primary text-white pt-2 py-1'><h3><?php echo xlt('Patient Credentials Reset') ?></h3></legend>
                        <div class="jumbotron jumbotron-fluid px-5 py-3">
                            <div class="form-row my-3">
                                <label class="col-md-2 col-form-label" for="fname"><?php echo xlt('First Name') ?></label>
                                <div class="col-md">
                                    <input type="text" class="form-control" id="fname" required placeholder="<?php echo xla('First Name'); ?>" />
                                </div>
                            </div>
                            <div class="form-row my-3">
                                <label class="col-md-2 col-form-label" for="lname"><?php echo xlt('Last Name') ?></label>
                                <div class="col-md">
                                    <input type="text" class="form-control" id="lname" required placeholder="<?php echo xla('Last Name'); ?>" />
                                </div>
                            </div>
                            <div class="form-row my-3">
                                <label class="col-md-2 col-form-label" for="dob"><?php echo xlt('Birth Date') ?></label>
                                <div class="col-md">
                                    <input id="dob" type="text" required class="form-control datepicker" placeholder="<?php echo xla('YYYY-MM-DD'); ?>" />
                                </div>
                            </div>
                            <div class="form-row my-3">
                                <label class="col-md-2 col-form-label" for="emailInput"><?php echo xlt('Enter E-Mail Address') ?></label>
                                <div class="col-md">
                                    <input id="emailInput" type="email" class="form-control" required placeholder="<?php echo xla('Current trusted email address on record.'); ?>" maxlength="100" />
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <div class="d-flex justify-content-center">
                                <div class="g-recaptcha" data-sitekey="<?php echo attr($GLOBALS['google_recaptcha_site_key']); ?>" data-callback="enableVerifyBtn"></div>
                            </div>
                        </div>
                        <input class="btn btn-secondary float-left" type="button" onclick="document.location.replace('./index.php?woops=1&site=<?php echo attr_url($_SESSION['site_id']); ?><?php if (!empty($redirectUrl)) {
                            echo "&redirect=" . attr_url($redirectUrl); } ?>');" value="<?php echo xla('Cancel'); ?>" />
                        <button id="submitRequest" class="btn btn-primary nextBtn float-right" type="submit" disabled="disabled"><?php echo xlt('Verify') ?></button>
                    </fieldset>
                </div>
            </form>
        <?php } else {
            ?> <!-- Main logon -->
        <form class="text-center" action="get_patient_info.php" method="POST" onsubmit="return process()">
            <?php if (isset($redirectUrl)) { ?>
                <input id="redirect" type="hidden" name="redirect" value="<?php echo attr($redirectUrl); ?>" />
            <?php } ?>
            <fieldset>
                <legend class="bg-primary text-white pt-2 py-1"><h3><?php echo xlt('Patient Portal Login'); ?></h3></legend>
                <div class="jumbotron jumbotron-fluid px-5 py-3">
                    <div class="form-row my-3">
                        <label class="col-form-label" for="uname"><?php echo xlt('Username') ?></label>
                        <div class="col-md">
                            <input type="text" class="form-control" name="uname" id="uname" autocomplete="none" required />
                        </div>
                    </div>
                    <div class="form-row mt-3">
                        <label class="col-form-label" for="pass"><?php echo xlt('Password') ?></label>
                        <div class="col-md">
                            <input class="form-control" name="pass" id="pass" type="password" required autocomplete="none" />
                        </div>
                    </div>
                    <?php if ($GLOBALS['enforce_signin_email']) { ?>
                        <div class="form-row mt-3">
                            <label class="col-md-2 col-form-label" for="passaddon"><?php echo xlt('E-Mail Address') ?></label>
                            <div class="col-md">
                                <input class="form-control" name="passaddon" id="passaddon" type="email" autocomplete="none" />
                            </div>
                        </div>
                    <?php } ?>
                    <?php if ($GLOBALS['language_menu_login']) { ?>
                        <?php if (count($result3) != 1) { ?>
                            <div class="form-row mt-3">
                                <label class="col-form-label" for="selLanguage"><?php echo xlt('Language'); ?></label>
                                <div class="col-md">
                                    <select class="form-control" id="selLanguage" name="languageChoice">
                                        <?php
                                        echo "<option selected='selected' value='" . attr($defaultLangID) . "'>" .
                                            text(xl('Default') . " - " . xl($defaultLangName)) . "</option>\n";
                                        foreach ($result3 as $iter) {
                                            if ($GLOBALS['language_menu_showall']) {
                                                if (!$GLOBALS['allow_debug_language'] && $iter['lang_description'] == 'dummy') {
                                                    continue; // skip the dummy language
                                                }
                                                echo "<option value='" . attr($iter['lang_id']) . "'>" .
                                                    text($iter['trans_lang_description']) . "</option>\n";
                                            } else {
                                                if (in_array($iter['lang_description'], $GLOBALS['language_menu_show'])) {
                                                    if (!$GLOBALS['allow_debug_language'] && $iter['lang_description'] == 'dummy') {
                                                        continue; // skip the dummy language
                                                    }
                                                    echo "<option value='" . attr($iter['lang_id']) . "'>" .
                                                        text($iter['trans_lang_description']) . "</option>\n";
                                                }
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        <?php }
                    } ?>
                </div>
                <div class="row">
                    <div class="col-12">
                        <?php if (!empty($GLOBALS['portal_onsite_two_register']) && !empty($GLOBALS['google_recaptcha_site_key']) && !empty($GLOBALS['google_recaptcha_secret_key'])) { ?>
                            <button class="btn btn-secondary float-left" onclick="location.replace('./account/verify.php?site=<?php echo attr_url($_SESSION['site_id']); ?>')"><?php echo xlt('Register'); ?></button>
                        <?php } ?>
                        <?php if (!empty($GLOBALS['portal_two_pass_reset']) && !empty($GLOBALS['google_recaptcha_site_key']) && !empty($GLOBALS['google_recaptcha_secret_key']) && isset($_GET['w']) && (isset($_GET['u']) || isset($_GET['p']))) { ?>
                            <button class="btn btn-danger ml-2" onclick="location.replace('./index.php?requestNew=1&site=<?php echo attr_url($_SESSION['site_id']); ?><?php if (!empty($redirectUrl)) {
                                echo "&redirect=" . attr_url($redirectUrl); } ?>')"><?php echo xlt('Reset Credentials'); ?></button>
                        <?php } ?>
                        <button class="btn btn-success float-right" type="submit"><?php echo xlt('Log In'); ?></button>
                    </div>
                </div>
            </fieldset>
            <?php if (!(empty($hiddenLanguageField))) {
                echo $hiddenLanguageField;
            } ?>
        </form>
    </div><!-- div wrapper -->
    <?php } ?> <!--  logon wrapper -->

    <div id="alertStore" class="d-none">
        <div class="h6 alert alert-warning alert-dismissible fade show my-1 py-1" role="alert">
            <button type="button" class="close my-1 py-0" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    </div>
    <div class="hipaa-badge" style="margin-top:1rem;">
            <span class="hipaa-icon">
              <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M8 1L2 4v4c0 3.5 2.5 6.7 6 7.5C11.5 14.7 14 11.5 14 8V4L8 1z"/><path d="M5.5 8l2 2 3-3"/></svg>
            </span>
            <div class="hipaa-text"><strong>HIPAA Compliant</strong> — Your health information is encrypted and protected.</div>
          </div>
        </div></div>
    <script>
        var tab_mode = true;
        var webroot_url = <?php echo js_escape($GLOBALS['web_root']) ?>;

        function restoreSession() {
            //dummy functions so the dlgopen function will work in the patient portal
            return true;
        }

        var isPortal = 1;

        $(function () {
            <?php // if something went wrong
            if (!empty($GLOBALS['portal_two_pass_reset']) && !empty($GLOBALS['google_recaptcha_site_key']) && !empty($GLOBALS['google_recaptcha_secret_key']) && isset($_GET['requestNew'])) {
                $_SESSION['register'] = true;
                $_SESSION['authUser'] = 'portal-user';
                $_SESSION['pid'] = true;
                ?>
            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = false; ?>
                <?php require $GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'; ?>
            });
            $(document.body).on('hidden.bs.modal', function () {
                callServer('cleanup');
            });
            $("#resetPass").on('submit', function (e) {
                e.preventDefault();
                callServer('reset_password');
                return false;
            });
            <?php } ?>
            <?php if (isset($_GET['w'])) { ?>
            // mdsupport - Would be good to include some clue about what went wrong!
            bsAlert(<?php echo xlj('Something went wrong. Please try again.'); ?>);
            <?php } ?>
            <?php if (isset($_GET['oe'])) { ?>
            // mdsupport - Would be good to include some clue about what went wrong!
            bsAlert(<?php echo xlj('Something went wrong. Onetime Authentication! Expired.'); ?>);
            <?php } ?>
            <?php if (isset($_GET['oi'])) { ?>
            // mdsupport - Would be good to include some clue about what went wrong!
            bsAlert(<?php echo xlj('Something went wrong. Onetime Authentication! Invalid.'); ?>);
            <?php } ?>
            <?php // if successfully logged out
            if (isset($_GET['logout'])) { ?>
            bsAlert(<?php echo xlj('You have been successfully logged out.'); ?>);
            <?php } ?>

            return false;
        });

        function callServer(action) {
            var data = {};
            if (action === 'reset_password') {
                data = {
                    'action': action,
                    'dob': $("#dob").val(),
                    'last': $("#lname").val(),
                    'first': $("#fname").val(),
                    'email': $("#emailInput").val(),
                    'g-recaptcha-response': grecaptcha.getResponse(),
                    'csrf_token_form': $("#csrf_token_form").val()
                }
            }
            if (action === 'cleanup') {
                data = {
                    'action': action
                }
            }
            $.ajax({
                type: 'GET',
                url: './account/account.php',
                data: data
            }).done(function (rtn) {
                if (action === "cleanup") {
                    let url = "./index.php?site=" + <?php echo js_url($_SESSION['site_id']); ?>; // Goto landing page.
                    let redirectUrl = $("#redirect").val();
                    if (redirectUrl) {
                        url += "&redirect=" + encodeURIComponent(redirectUrl);
                    }
                    window.location.href = url;
                } else if (action === "reset_password") {
                    if (JSON.parse(rtn) === 1) {
                        dialog.alert(<?php echo xlj("Check your email inbox (and possibly your spam folder) for further instructions to reset your password. If you have not received an email, then recommend contacting the clinic.") ?>);
                        return false;
                    } else {
                        dialog.alert(<?php echo xlj("Something went wrong. Recommend contacting the clinic.") ?>);
                        return false;
                    }
                }
            }).fail(function (err) {
                var message = <?php echo xlj('Something went wrong.') ?>;
                alert(message);
            });
        }

        function bsAlert(msg) {
            let divAlert = document.getElementById("alertStore").querySelector("div.alert").cloneNode(true);
            document.querySelector("form").prepend(divAlert);
            let strongMsg = document.createElement("strong");
            strongMsg.innerHTML = msg;
            divAlert.prepend(strongMsg);
            setTimeout(() => {
                document.querySelector("div.alert").remove();
            }, 6000);
        }
    </script>
</body>
</html>
