<?php

/**
 * Credential Changes
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019-2021 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$ignoreAuth_onsite_portal = $ignoreAuth = false;
// Will start the (patient) portal OpenEMR session/cookie.
require_once(dirname(__FILE__) . "/../../src/Common/Session/SessionUtil.php");
OpenEMR\Common\Session\SessionUtil::portalSessionStart();

$landingpage = "./../index.php?site=" . urlencode($_SESSION['site_id']);
// kick out if patient not authenticated
if (isset($_SESSION['pid']) && isset($_SESSION['patient_portal_onsite_two'])) {
    $ignoreAuth_onsite_portal = true;
} else {
    OpenEMR\Common\Session\SessionUtil::portalSessionCookieDestroy();
    header('Location: ' . $landingpage . '&w');
    exit;
}
require_once(dirname(__FILE__) . '/../../interface/globals.php');
require_once(dirname(__FILE__) . "/../lib/appsql.class.php");

use OpenEMR\Common\Auth\AuthHash;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$logit = new ApplicationTable();
//exit if portal is turned off
if (!(isset($GLOBALS['portal_onsite_two_enable'])) || !($GLOBALS['portal_onsite_two_enable'])) {
    echo xlt('Patient Portal is turned off');
    exit;
}
if (!empty($_POST)) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"], "portal_index_reset")) {
        CsrfUtils::csrfNotVerified();
    }
}
$_SESSION['credentials_update'] = 1;

DEFINE("TBL_PAT_ACC_ON", "patient_access_onsite");
DEFINE("COL_ID", "id");
DEFINE("COL_PID", "pid");
DEFINE("COL_POR_PWD", "portal_pwd");
DEFINE("COL_POR_USER", "portal_username");
DEFINE("COL_POR_LOGINUSER", "portal_login_username");
DEFINE("COL_POR_PWD_STAT", "portal_pwd_status");

$sql = "SELECT " . implode(",", array(COL_ID, COL_PID, COL_POR_PWD, COL_POR_USER, COL_POR_LOGINUSER, COL_POR_PWD_STAT)) .
    " FROM " . TBL_PAT_ACC_ON . " WHERE pid = ?";

$auth = privQuery($sql, array($_SESSION['pid']));
$valid = ((!empty(trim($_POST['uname']))) &&
    (!empty(trim($_POST['login_uname']))) &&
    (!empty(trim($_POST['pass_current']))) &&
    (!empty(trim($_POST['pass_new']))) &&
    (trim($_POST['uname']) == $auth[COL_POR_USER]) &&
    (AuthHash::passwordVerify(trim($_POST['pass_current']), $auth[COL_POR_PWD])));
if (isset($_POST['submit'])) {
    if (!$valid) {
        $errmsg = xlt("Invalid Current Credentials Error.") . xlt("Unknown.");
        $logit->portalLog('Credential update attempt', '', ($_POST['uname'] . ':unknown'), '', '0');
        die($errmsg);
    }
    $new_hash = (new AuthHash('auth'))->passwordHash(trim($_POST['pass_new']));
    if (empty($new_hash)) {
        // Something is seriously wrong
        error_log('OpenEMR Error : OpenEMR is not working because unable to create a hash.');
        die("OpenEMR Error : OpenEMR is not working because unable to create a hash.");
    }
    $sqlUpdatePwd = " UPDATE " . TBL_PAT_ACC_ON . " SET " . COL_POR_PWD . "=?, " . COL_POR_LOGINUSER . "=?" . " WHERE " . COL_ID . "=?";
    privStatement($sqlUpdatePwd, array(
        $new_hash,
        $_POST['login_uname'],
        $auth[COL_ID]
    ));
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('Change Portal Credentials'); ?></title>
    <?php Header::setupHeader(['opener']); ?>
    <?php if (!empty($_POST['submit'])) {
        unset($_POST['submit']);
        echo "<script>dlgclose();</script>\n";
    } ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="shortcut icon" href="../public/images/logos/core/favicon/favicon.ico?t=1771584483">
    <!-- Portal shared CSS (for nav) -->
    <link rel="stylesheet" href="<?php echo $GLOBALS['web_root']; ?>/portal/portal-shared.css">

    <script>
        function ptSubNavToggle() {
            var m = document.getElementById('pt-sub-dd');
            if (m) m.classList.toggle('open');
        }
        document.addEventListener('click', function(e) {
            var dd  = document.getElementById('pt-sub-dd');
            var btn = document.getElementById('pt-sub-avatar-btn');
            if (dd && btn && !btn.contains(e.target) && !dd.contains(e.target)) {
                dd.classList.remove('open');
            }
        });

        function checkUserName() {
            let vacct = document.getElementById('uname').value;
            let vsuname = document.getElementById('login_uname').value;
            let data = { 'action': 'userIsUnique', 'account': vacct, 'loginUname': vsuname };
            $.ajax({ type: 'GET', url: './account.php', data: data })
             .done(function(rtn) {
                if (rtn === '1') return true;
                alert(<?php echo xlj('Log In Name is unavailable. Try again!'); ?>);
                return false;
            });
        }

        function process_new_pass() {
            if (document.getElementById('login_uname').value !== document.getElementById('confirm_uname').value) {
                showError(<?php echo xlj('The Username fields are not the same.'); ?>);
                return false;
            }
            if (document.getElementById('pass_new').value !== document.getElementById('pass_new_confirm').value) {
                showError(<?php echo xlj('The new password fields are not the same.'); ?>);
                return false;
            }
            if (document.getElementById('pass_current').value === document.getElementById('pass_new_confirm').value) {
                if (!confirm(<?php echo xlj('The new password is the same as the current password. Click Okay to accept anyway.'); ?>)) {
                    return false;
                }
            }
            return true;
        }

        function showError(msg) {
            var el = document.getElementById('ir-error');
            el.textContent = msg;
            el.style.display = 'flex';
        }

        function togglePwd(fieldId, btn) {
            var f = document.getElementById(fieldId);
            var showing = f.type === 'text';
            f.type = showing ? 'password' : 'text';
            btn.innerHTML = showing
                ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>'
                : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        }
    </script>

    <style>
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
            --green-light: #d1fae5;
            --red:         #dc2626;
            --red-light:   #fee2e2;
            --radius:      12px;
            --radius-sm:   7px;
            --shadow:      0 4px 24px rgba(0,0,0,.09);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'DM Sans', system-ui, sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            min-height: 100vh;
        }

        /* ── Page wrapper ── */
        .ir-page {
            min-height: calc(100vh - 56px);
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 40px 16px 60px;
        }

        /* ── Card ── */
        .ir-card {
            width: 100%;
            max-width: 480px;
            background: var(--white);
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        /* ── Card hero ── */
        .ir-hero {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, var(--navy) 0%, var(--navy-light) 55%, var(--purple-mid) 100%);
            padding: 28px 28px 24px;
        }
        .ir-hero::after {
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
        .ir-hero-icon {
            width: 46px; height: 46px;
            background: rgba(255,255,255,.12);
            border: 1.5px solid rgba(255,255,255,.18);
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 14px;
            position: relative; z-index: 1;
        }
        .ir-hero-icon svg { color: #fff; }
        .ir-hero-title {
            font-size: 20px; font-weight: 700;
            color: #fff; letter-spacing: -.02em;
            position: relative; z-index: 1;
            margin-bottom: 4px;
        }
        .ir-hero-sub {
            font-size: 12px; color: rgba(255,255,255,.6);
            font-weight: 400; position: relative; z-index: 1;
        }

        /* ── Form body ── */
        .ir-body { padding: 28px; }

        /* ── Section dividers ── */
        .ir-section-label {
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .08em;
            color: var(--gray-400);
            display: flex; align-items: center; gap: 8px;
            margin-bottom: 16px;
        }
        .ir-section-label::after {
            content: ''; flex: 1; height: 1px; background: var(--gray-200);
        }

        /* ── Field ── */
        .ir-field { margin-bottom: 14px; }
        .ir-field label {
            display: block;
            font-size: 12px; font-weight: 600;
            color: var(--gray-700);
            margin-bottom: 5px;
        }
        .ir-field label .ir-req {
            color: var(--purple); margin-left: 2px;
        }

        /* Input wrapper with optional eye toggle */
        .ir-input-wrap {
            position: relative;
        }
        .ir-input-wrap input {
            width: 100%;
            padding: 9px 13px;
            border: 1.5px solid var(--gray-200);
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', system-ui, sans-serif;
            font-size: 13px; color: var(--gray-900);
            background: var(--white);
            outline: none;
            transition: border-color .15s, box-shadow .15s;
        }
        .ir-input-wrap input:focus {
            border-color: var(--purple);
            box-shadow: 0 0 0 3px rgba(124,58,237,.1);
        }
        .ir-input-wrap input[readonly] {
            background: var(--gray-50); color: var(--gray-400); cursor: not-allowed;
        }
        .ir-input-wrap input.has-eye { padding-right: 40px; }
        .ir-eye-btn {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: none; cursor: pointer;
            color: var(--gray-400); padding: 4px;
            display: flex; align-items: center; justify-content: center;
            transition: color .15s;
        }
        .ir-eye-btn:hover { color: var(--purple); }

        /* Field hint */
        .ir-hint {
            font-size: 11px; color: var(--gray-400);
            margin-top: 4px; line-height: 1.4;
        }

        /* ── Error banner ── */
        #ir-error {
            display: none;
            align-items: center; gap: 8px;
            background: var(--red-light);
            border: 1.5px solid #fca5a5;
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 12px; font-weight: 600; color: var(--red);
            margin-bottom: 18px;
        }

        /* ── Submit ── */
        .ir-submit-row {
            display: flex; align-items: center; justify-content: space-between;
            padding-top: 8px;
            border-top: 1.5px solid var(--gray-100);
            margin-top: 6px;
        }
        .ir-case-note {
            font-size: 11px; color: var(--gray-400);
            display: flex; align-items: center; gap: 5px;
        }
        .ir-btn {
            display: inline-flex; align-items: center; gap: 7px;
            font-family: 'DM Sans', system-ui, sans-serif;
            font-size: 13px; font-weight: 600;
            padding: 9px 22px; border: none; border-radius: var(--radius-sm);
            cursor: pointer; transition: all .15s;
            background: var(--purple); color: #fff;
            box-shadow: 0 2px 8px rgba(124,58,237,.25);
        }
        .ir-btn:hover { background: var(--purple-mid); transform: translateY(-1px); }
        .ir-btn:active { transform: translateY(0); }

        /* ── Divider between sections ── */
        .ir-divider { height: 1px; background: var(--gray-100); margin: 20px 0; }
    </style>
</head>
<body>

<?php
$PORTAL_NAV_ACTIVE = '';
$_nav_partial = dirname(__DIR__) . '/partials/portal-nav.php';
if (file_exists($_nav_partial)) {
    require_once($_nav_partial);
}
?>

<div class="ir-page">
    <div class="ir-card">

        <!-- Hero -->
        <div class="ir-hero">
            <div class="ir-hero-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            </div>
            <div class="ir-hero-title"><?php echo xlt('Change Credentials'); ?></div>
            <div class="ir-hero-sub"><?php echo xlt('Update your portal username and password'); ?></div>
        </div>

        <!-- Form body -->
        <div class="ir-body">

            <div id="ir-error">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <span></span>
            </div>

            <form action="" method="POST" onsubmit="return process_new_pass()">
                <!-- Honeypot -->
                <input style="display:none" type="text"     name="dummyuname" />
                <input style="display:none" type="password" name="dummypassword" />
                <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken("portal_index_reset")); ?>" />

                <!-- ── Account section ── -->
                <div class="ir-section-label"><?php echo xlt('Account'); ?></div>

                <div class="ir-field">
                    <label><?php echo xlt('Account Name'); ?></label>
                    <div class="ir-input-wrap">
                        <input type="text" name="uname" id="uname" readonly
                               value="<?php echo attr($auth['portal_username']); ?>" />
                    </div>
                </div>

                <div class="ir-field">
                    <label><?php echo xlt('New or Current Username'); ?><span class="ir-req">*</span></label>
                    <div class="ir-input-wrap">
                        <input class="has-eye" type="text" name="login_uname" id="login_uname" required
                               onblur="checkUserName()"
                               title="<?php echo xla('Change or keep current. Enter 12 to 80 characters.'); ?>"
                               pattern=".{12,80}"
                               value="<?php echo attr($auth['portal_login_username']); ?>" />
                    </div>
                    <div class="ir-hint"><?php echo xlt('12–80 characters. Symbols and numbers recommended.'); ?></div>
                </div>

                <div class="ir-field">
                    <label><?php echo xlt('Confirm Username'); ?><span class="ir-req">*</span></label>
                    <div class="ir-input-wrap">
                        <input type="text" name="confirm_uname" id="confirm_uname" required
                               autocomplete="none" pattern=".{8,80}" value="" />
                    </div>
                </div>

                <div class="ir-divider"></div>

                <!-- ── Password section ── -->
                <div class="ir-section-label"><?php echo xlt('Password'); ?></div>

                <div class="ir-field">
                    <label><?php echo xlt('Current Password'); ?><span class="ir-req">*</span></label>
                    <div class="ir-input-wrap">
                        <input class="has-eye" type="password" name="pass_current" id="pass_current" required
                               placeholder="<?php echo xla('Enter current password to authorize changes'); ?>"
                               pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" />
                        <button type="button" class="ir-eye-btn" onclick="togglePwd('pass_current', this)" tabindex="-1">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <div class="ir-field">
                    <label><?php echo xlt('New Password'); ?><span class="ir-req">*</span></label>
                    <div class="ir-input-wrap">
                        <input class="has-eye" type="password" name="pass_new" id="pass_new" required
                               placeholder="<?php echo xla('Min 8 chars: upper, lower, number'); ?>"
                               pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" />
                        <button type="button" class="ir-eye-btn" onclick="togglePwd('pass_new', this)" tabindex="-1">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div class="ir-hint"><?php echo xlt('Must contain uppercase, lowercase, and a number.'); ?></div>
                </div>

                <div class="ir-field">
                    <label><?php echo xlt('Confirm New Password'); ?><span class="ir-req">*</span></label>
                    <div class="ir-input-wrap">
                        <input class="has-eye" type="password" name="pass_new_confirm" id="pass_new_confirm" required
                               pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}"
                               autocomplete="none" />
                        <button type="button" class="ir-eye-btn" onclick="togglePwd('pass_new_confirm', this)" tabindex="-1">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Submit row -->
                <div class="ir-submit-row">
                    <div class="ir-case-note">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <?php echo xlt('All fields are case sensitive'); ?>
                    </div>
                    <button type="submit" name="submit" class="ir-btn">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                        <?php echo xlt('Save Changes'); ?>
                    </button>
                </div>

            </form>
        </div><!-- /.ir-body -->
    </div><!-- /.ir-card -->
</div><!-- /.ir-page -->

</body>
</html>
