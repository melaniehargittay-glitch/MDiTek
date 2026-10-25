<?php
/**
 * MDItek Password Reset — Step 1: Username Entry
 * Place at: /interface/login/mdi_forgot_password.php
 */
$ignoreAuth = true;
$sessionAllowWrite = true;
// Start session before globals.php so $ignoreAuth is in scope
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once("../globals.php");

// Security headers
// Allow OpenEMR frame context but block external framing
$host = $_SERVER['HTTP_HOST'] ?? '';

header("X-Frame-Options: SAMEORIGIN");
header("Content-Security-Policy: frame-ancestors 'self' https://$host");
header("X-Content-Type-Options: nosniff");

// Simple session nonce — replaces CsrfUtils which requires full auth framework
function mdiResetNonce(): string {
    if (empty($_SESSION['mdi_reset_nonce'])) {
        $_SESSION['mdi_reset_nonce'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['mdi_reset_nonce'];
}
function mdiVerifyNonce(string $token): bool {
    return !empty($_SESSION['mdi_reset_nonce'])
        && hash_equals($_SESSION['mdi_reset_nonce'], $token);
}



if (!empty($_SESSION['authUser'])) {
    header('Location: ../main/main_screen.php'); exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mdiVerifyNonce($_POST['csrf_token_form'] ?? '')) {
        $error = xl('Invalid request. Please try again.');
    } else {
        $username = trim($_POST['username'] ?? '');
        if (empty($username)) {
            $error = xl('Please enter your username.');
        } else {
            $user = sqlQuery(
                "SELECT u.id, u.username, u.email,
                        (SELECT COUNT(*) FROM login_mfa_registrations
                         WHERE user_id = u.id AND method = 'TOTP') AS has_totp
                 FROM users u WHERE u.username = ? AND u.active = 1 LIMIT 1",
                [$username]
            );

            if ($user && !empty($user['email'])) {
                $_SESSION['pw_reset_uid']      = (int)$user['id'];
                $_SESSION['pw_reset_username'] = $user['username'];
                $_SESSION['pw_reset_email']    = $user['email'];
                $_SESSION['pw_reset_has_totp'] = (int)($user['has_totp'] ?? 0) > 0;
                $_SESSION['pw_reset_step']     = 'verify';
                $_SESSION['pw_reset_initiated']= time();
                $_SESSION['pw_reset_attempts'] = 0;

                // If no TOTP registered, send email OTP now
                if (!$_SESSION['pw_reset_has_totp']) {
                    $otp = sprintf('%06d', random_int(0, 999999));
                    $_SESSION['pw_reset_otp_hash']    = password_hash($otp, PASSWORD_DEFAULT);
                    $_SESSION['pw_reset_otp_expires'] = time() + 600;

                    // Send OTP via OpenEMR's configured SMTP
                    require_once($GLOBALS['srcdir'] . '/classes/postmaster.php');
                    $siteName  = (string)($GLOBALS['openemr_name'] ?? 'OpenEMR');
                    $emailFrom = '';
                    foreach (['patient_reminder_sender_email','practice_return_email_path','smtp_username'] as $gk) {
                        if (!empty($GLOBALS[$gk])) { $emailFrom = (string)$GLOBALS[$gk]; break; }
                    }
                    if (empty($emailFrom)) {
                        $emailFrom = 'noreply@' . preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
                    }
                    $emailBody = xl('Hello') . ' ' . $user['username'] . ",\n\n"
                               . xl('Your password reset code is') . ":\n\n"
                               . "    " . $otp . "\n\n"
                               . xl('This code expires in 10 minutes.') . "\n"
                               . xl('If you did not request this, ignore this email.') . "\n\n"
                               . "-- " . $siteName;
                    try {
                        $mail           = new MyMailer();
                        $mail->From     = $emailFrom;
                        $mail->FromName = $siteName;
                        $mail->AddAddress((string)$user['email']);
                        $mail->Subject  = $siteName . ': ' . xl('Your password reset code');
                        $mail->IsHTML(false);
                        $mail->Body     = $emailBody;
                        $sent = $mail->Send();
                        $_SESSION['pw_reset_mail_sent']   = $sent;
                        $_SESSION['pw_reset_mail_error']  = $sent ? '' : (string)($mail->ErrorInfo ?? '');
                        $_SESSION['pw_reset_otp_display'] = $sent ? '' : $otp;
                        if (!$sent) error_log('MDI reset mail failed: ' . $mail->ErrorInfo);
                    } catch (Exception $e) {
                        $_SESSION['pw_reset_mail_sent']   = false;
                        $_SESSION['pw_reset_mail_error']  = $e->getMessage();
                        $_SESSION['pw_reset_otp_display'] = $otp;
                        error_log('MDI reset mail exception: ' . $e->getMessage());
                    }
                } // ✅ ADD THIS LINE (closes if (!pw_reset_has_totp))
            } elseif ($user && empty($user['email'])) {
                // User exists but has no email — flag it so verify page can show message
                $_SESSION['pw_reset_uid']      = (int)$user['id'];
                $_SESSION['pw_reset_username'] = $user['username'];
                $_SESSION['pw_reset_email']    = '';
                $_SESSION['pw_reset_has_totp'] = (int)($user['has_totp'] ?? 0) > 0;
                $_SESSION['pw_reset_step']     = 'verify';
                $_SESSION['pw_reset_initiated']= time();
                $_SESSION['pw_reset_attempts'] = 0;
            } else {
                usleep(random_int(200000, 500000));
            }

            session_write_close();
            header('Location: mdi_otp_verify.php'); exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo xlt('Reset Password'); ?> — <?php echo text($GLOBALS['openemr_name'] ?? 'OpenEMR'); ?></title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family:'Outfit',sans-serif; background:linear-gradient(135deg,#0f0520 0%,#1e0a3c 40%,#2d1060 100%); min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px; }
.card { background:#fff; border-radius:20px; width:100%; max-width:420px; box-shadow:0 24px 64px rgba(0,0,0,.4),0 0 0 1px rgba(167,139,250,.2); overflow:hidden; }
.card-hdr { background:linear-gradient(160deg,#1e0a3c 0%,#3b0764 55%,#5b21b6 100%); padding:28px 32px 40px; position:relative; overflow:hidden; }
.card-hdr::before { content:''; position:absolute; inset:0; background:radial-gradient(ellipse at 75% 40%,rgba(167,139,250,.2) 0%,transparent 65%); }
.card-hdr::after { content:''; position:absolute; bottom:-1px; left:0; right:0; height:32px; background:linear-gradient(to bottom right,transparent 49%,#fff 50%); }
.hdr-icon { width:46px; height:46px; background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.18); border-radius:14px; display:flex; align-items:center; justify-content:center; margin-bottom:14px; position:relative; }
.card-hdr h1 { color:#fff; font-size:20px; font-weight:800; letter-spacing:-.02em; margin-bottom:4px; position:relative; }
.card-hdr p { color:rgba(196,181,253,.8); font-size:12px; position:relative; line-height:1.5; }
.card-body { padding:24px 32px 32px; }
.lbl { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#6b21a8; margin-bottom:5px; }
.inp { width:100%; border:1.5px solid #ddd6fe; border-radius:10px; padding:12px 14px; font-family:'JetBrains Mono',monospace; font-size:13px; color:#1e0a3c; background:#fafafe; transition:all .2s; outline:none; }
.inp:focus { border-color:#7c3aed; background:#fff; box-shadow:0 0 0 3px rgba(124,58,237,.12); }
.inp::placeholder { color:#c4b5fd; }
.btn { width:100%; background:linear-gradient(135deg,#5b21b6 0%,#7c3aed 100%); border:none; border-radius:12px; padding:13px; font-family:'Outfit',sans-serif; font-size:14px; font-weight:700; color:#fff; cursor:pointer; transition:all .2s; box-shadow:0 4px 14px rgba(91,33,182,.35); margin-top:8px; letter-spacing:.02em; display:flex; align-items:center; justify-content:center; gap:7px; }
.btn:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(91,33,182,.45); }
.err { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; color:#991b1b; font-size:12.5px; font-weight:500; padding:10px 14px; margin-bottom:16px; }
.info { background:#f5f3ff; border:1px solid #ddd6fe; border-radius:10px; color:#5b21b6; font-size:12px; padding:11px 14px; margin-bottom:18px; line-height:1.6; display:flex; gap:9px; }
.fg { margin-bottom:18px; }
.back { display:block; text-align:center; margin-top:16px; font-size:12px; color:#9ca3af; text-decoration:none; transition:color .15s; }
.back:hover { color:#7c3aed; }
</style>
</head>
<body>
<div class="card">
    <div class="card-hdr">
        <div class="hdr-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
        </div>
        <h1><?php echo xlt('Reset Password'); ?></h1>
        <p><?php echo xlt('Verify your identity to reset your password'); ?></p>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
        <div class="err"><?php echo text($error); ?></div>
        <?php endif; ?>
        <div class="info">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
            <span><?php echo xlt('Enter your username. You will verify your identity using your authenticator app or a one-time code sent to your registered email.'); ?></span>
        </div>
        <form method="post" action="mdi_forgot_password.php" autocomplete="off">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(mdiResetNonce()); ?>">
            <div class="fg">
                <label class="lbl" for="username"><?php echo xlt('Username'); ?></label>
                <input type="text" id="username" name="username" class="inp"
                       autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                       placeholder="<?php echo attr(xlt('Enter your username')); ?>"
                       value="<?php echo attr($_POST['username'] ?? ''); ?>">
            </div>
            <button type="submit" class="btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                <?php echo xlt('Continue'); ?>
            </button>
        </form>
        <a href="login.php" class="back">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            <?php echo xlt('Back to login'); ?>
        </a>
    </div>
</div>
</body>
</html>
