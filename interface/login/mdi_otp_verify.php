<?php
/**
 * MDItek Password Reset — Step 2: MFA / OTP Verification
 * Place at: /interface/login/mdi_otp_verify.php
 */
$ignoreAuth = true;
$sessionAllowWrite = true;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once("../globals.php");

// Security headers
header("X-Frame-Options: DENY");
header("Content-Security-Policy: frame-ancestors 'none'");
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



// Must come from step 1
if (empty($_SESSION['pw_reset_uid']) || ($_SESSION['pw_reset_step'] ?? '') !== 'verify') {
    header('Location: mdi_forgot_password.php'); exit;
}

// Session timeout — 10 min
if ((time() - ($_SESSION['pw_reset_initiated'] ?? 0)) > 600) {
    session_unset();
    header('Location: mdi_forgot_password.php?msg=expired'); exit;
}

$hasTOTP  = (bool)($_SESSION['pw_reset_has_totp'] ?? false);
$email    = $_SESSION['pw_reset_email'] ?? '';
$attempts = (int)($_SESSION['pw_reset_attempts'] ?? 0);

// Mask email for display
$maskedEmail = '';
if ($email) {
    if (strpos($email, '@') !== false) {
        [$local, $domain] = explode('@', $email, 2);
        $maskedEmail = substr($local, 0, 2) . str_repeat('*', max(strlen($local) - 2, 3)) . '@' . $domain;
    }
}

$error   = '';
$method  = $_POST['method'] ?? ($hasTOTP ? 'totp' : 'email');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Resend email OTP ──────────────────────────────────────────
    if ($_POST['action'] === 'resend') {
        if (!mdiVerifyNonce($_POST['csrf_token_form'] ?? '')) {
            $error = xl('Invalid request.');
        } else {
            $otp = sprintf('%06d', random_int(0, 999999));
            $_SESSION['pw_reset_otp_hash']    = password_hash($otp, PASSWORD_DEFAULT);
            $_SESSION['pw_reset_otp_expires'] = time() + 600;
            $_SESSION['pw_reset_attempts']    = 0;

            require_once($GLOBALS['srcdir'] . '/classes/postmaster.php');
            $siteName  = (string)($GLOBALS['openemr_name'] ?? 'OpenEMR');
            $emailFrom = '';
            foreach (['patient_reminder_sender_email','practice_return_email_path','smtp_username'] as $gk) {
                if (!empty($GLOBALS[$gk])) { $emailFrom = (string)$GLOBALS[$gk]; break; }
            }
            if (empty($emailFrom)) $emailFrom = 'noreply@' . (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
            $emailBody = xl('Your new password reset code is') . ":\n\n    " . $otp . "\n\n"
                       . xl('This code expires in 10 minutes.') . "\n\n-- " . $siteName;
            $mailSent = false;
            try {
                $mail           = new MyMailer();
                $mail->From     = $emailFrom;
                $mail->FromName = $siteName;
                $mail->AddAddress($email);
                $mail->Subject  = $siteName . ': ' . xl('Your password reset code');
                $mail->IsHTML(false);
                $mail->Body     = $emailBody;
                $mailSent = $mail->Send();
                if (!$mailSent) error_log('MDI OTP resend failed: ' . ($mail->ErrorInfo ?? ''));
            } catch (Exception $e) {
                error_log('MDI OTP resend exception: ' . $e->getMessage());
            }
                        $info = $mailSent ? xl('A new code has been sent to your email.') : xl('Failed to send — contact your administrator.');
        }
    }

    // ── Verify code ───────────────────────────────────────────────
    if ($_POST['action'] === 'verify') {
        if (!mdiVerifyNonce($_POST['csrf_token_form'] ?? '')) {
            $error = xl('Invalid request.');
        } elseif ($attempts >= 5) {
            session_unset();
            header('Location: mdi_forgot_password.php?msg=blocked'); exit;
        } else {
            $_SESSION['pw_reset_attempts']++;
            $code   = preg_replace('/\s+/', '', trim($_POST['code'] ?? ''));
            $method = $_POST['method'] ?? 'email';
            $ok     = false;

            if ($method === 'totp' && $hasTOTP && strlen($code) === 6) {
                // Verify TOTP via OpenEMR's MfaUtils
                $mfaUtilsFile = $GLOBALS['srcdir'] . '/MfaUtils.php';
                if (file_exists($mfaUtilsFile)) {
                    require_once($mfaUtilsFile);
                    $mfa = new MfaUtils($_SESSION['pw_reset_uid']);
                    $ok  = $mfa->check($code, 'TOTP');
                }
            } elseif ($method === 'email') {
                // Verify email OTP
                $otpHash    = $_SESSION['pw_reset_otp_hash'] ?? '';
                $otpExpires = (int)($_SESSION['pw_reset_otp_expires'] ?? 0);
                if ($otpHash && $otpExpires > time() && password_verify($code, $otpHash)) {
                    $ok = true;
                }
            }

            if ($ok) {
                // Ensure token table exists (created on first use)
                sqlStatement("CREATE TABLE IF NOT EXISTS `mdi_password_reset_tokens` (
                    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `user_id` INT NOT NULL,
                    `token_hash` VARCHAR(255) NOT NULL,
                    `expires_at` DATETIME NOT NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uq_user` (`user_id`),
                    KEY `idx_expires` (`expires_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                // Generate secure single-use reset token
                $token   = bin2hex(random_bytes(32));
                $expires = time() + 900; // 15 min

                // Store hashed token in DB
                sqlStatement(
                    "DELETE FROM mdi_password_reset_tokens WHERE user_id = ?",
                    [$_SESSION['pw_reset_uid']]
                );
                sqlStatement(
                    "INSERT INTO mdi_password_reset_tokens (user_id, token_hash, expires_at, created_at)
                     VALUES (?, ?, FROM_UNIXTIME(?), NOW())",
                    [$_SESSION['pw_reset_uid'], password_hash($token, PASSWORD_DEFAULT), $expires]
                );

                // Clear sensitive session data
                $uid = $_SESSION['pw_reset_uid'];
                unset($_SESSION['pw_reset_otp_hash'], $_SESSION['pw_reset_otp_expires'],
                      $_SESSION['pw_reset_has_totp'], $_SESSION['pw_reset_step']);
                $_SESSION['pw_reset_token_uid'] = $uid;

                session_write_close();
                header('Location: mdi_new_password.php?token=' . urlencode($token)); exit;
            } else {
                $error = xl('Invalid or expired code. Please try again.');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo xlt('Verify Identity'); ?></title>

<style>
@import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
*, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
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
.inp { width:100%; border:1.5px solid #ddd6fe; border-radius:10px; padding:12px 14px; font-family:'JetBrains Mono',monospace; font-size:22px; letter-spacing:.2em; text-align:center; color:#1e0a3c; background:#fafafe; transition:all .2s; outline:none; }
.inp:focus { border-color:#7c3aed; background:#fff; box-shadow:0 0 0 3px rgba(124,58,237,.12); }
.inp::placeholder { font-size:14px; letter-spacing:normal; color:#c4b5fd; }
.btn { width:100%; background:linear-gradient(135deg,#5b21b6 0%,#7c3aed 100%); border:none; border-radius:12px; padding:13px; font-family:'Outfit',sans-serif; font-size:14px; font-weight:700; color:#fff; cursor:pointer; transition:all .2s; box-shadow:0 4px 14px rgba(91,33,182,.35); margin-top:8px; display:flex; align-items:center; justify-content:center; gap:7px; }
.btn:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(91,33,182,.45); }
.btn-ghost { width:100%; background:#fff; border:1.5px solid #ddd6fe; border-radius:12px; padding:11px; font-family:'Outfit',sans-serif; font-size:13px; font-weight:600; color:#5b21b6; cursor:pointer; transition:all .2s; margin-top:8px; display:flex; align-items:center; justify-content:center; gap:7px; }
.btn-ghost:hover { border-color:#7c3aed; background:#f5f3ff; }
.err { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; color:#991b1b; font-size:12.5px; font-weight:500; padding:10px 14px; margin-bottom:16px; display:flex; gap:8px; align-items:flex-start; }
.success { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px; color:#166534; font-size:12.5px; font-weight:500; padding:10px 14px; margin-bottom:16px; display:flex; gap:8px; }
.info { background:#f5f3ff; border:1px solid #ddd6fe; border-radius:10px; color:#5b21b6; font-size:12px; padding:11px 14px; margin-bottom:18px; line-height:1.6; display:flex; gap:9px; }
.tabs { display:flex; gap:8px; margin-bottom:20px; }
.tab { flex:1; padding:9px 10px; border:1.5px solid #ddd6fe; border-radius:10px; font-family:'Outfit',sans-serif; font-size:12px; font-weight:600; color:#6b7280; background:#fafafe; cursor:pointer; text-align:center; transition:all .15s; }
.tab.active { border-color:#7c3aed; color:#5b21b6; background:#f5f3ff; }
.tab:hover:not(.active) { border-color:#c4b5fd; color:#7c3aed; }
.fg { margin-bottom:18px; }
.back { display:block; text-align:center; margin-top:16px; font-size:12px; color:#9ca3af; text-decoration:none; }
.back:hover { color:#7c3aed; }
.attempts { text-align:center; font-size:11px; color:#9ca3af; margin-top:10px; }
</style>
</head>
<body>
<div class="card">
    <div class="card-hdr">
        <div class="hdr-icon">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        </div>
        <h1><?php echo xlt('Verify Identity'); ?></h1>
        <p><?php echo xlt('Enter your verification code to continue'); ?></p>
    </div>
    <div class="card-body">

        <?php if (!empty($_SESSION['pw_reset_email']) === false && empty($_SESSION['pw_reset_has_totp'])): ?>
        <div class="err">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#991b1b" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo xlt('No email address is on file for this account. Please contact your administrator to add an email to your user profile.'); ?>
        </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['pw_reset_mail_sent']) && $_SESSION['pw_reset_mail_sent'] === false): ?>
        <div class="err" style="flex-direction:column;gap:6px;">
            <div style="display:flex;gap:8px;align-items:flex-start;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#991b1b" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?php echo xlt('Email could not be sent — SMTP is not configured in OpenEMR.'); ?>
            </div>
            <?php if (!empty($_SESSION['pw_reset_otp_display'])): ?>
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:10px 14px;margin-top:4px;">
                <div style="font-size:11px;font-weight:700;color:#856404;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">
                    <?php echo xlt('Your verification code (admin use only):'); ?>
                </div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700;color:#1e0a3c;letter-spacing:.3em;">
                    <?php echo text($_SESSION['pw_reset_otp_display']); ?>
                </div>
                <div style="font-size:10px;color:#856404;margin-top:4px;">
                    <?php echo xlt('Enter this code below. Configure SMTP in Admin > Globals > Notifications to enable email delivery.'); ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
        <div class="err">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#991b1b" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo text($error); ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($info)): ?>
        <div class="success">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2" style="flex-shrink:0;margin-top:1px"><polyline points="20 6 9 17 4 12"/></svg>
            <?php echo text($info); ?>
        </div>
        <?php endif; ?>

        <?php if ($hasTOTP): ?>
        <!-- Tab switcher — TOTP or Email -->
        <div class="tabs">
            <div class="tab <?php echo $method !== 'email' ? 'active' : ''; ?>" onclick="switchMethod('totp')">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                <?php echo xlt('Authenticator App'); ?>
            </div>
            <div class="tab <?php echo $method === 'email' ? 'active' : ''; ?>" onclick="switchMethod('email')">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:4px"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 01-2.06 0L2 7"/></svg>
                <?php echo xlt('Email Code'); ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="panel-totp" style="<?php echo ($hasTOTP && $method !== 'email') ? '' : 'display:none'; ?>">
            <div class="info">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" style="flex-shrink:0;margin-top:1px"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12.01" y2="18"/></svg>
                <span><?php echo xlt('Open your authenticator app and enter the 6-digit code for this account.'); ?></span>
            </div>
        </div>

        <div id="panel-email" style="<?php echo (!$hasTOTP || $method === 'email') ? '' : 'display:none'; ?>">
            <div class="info">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#7c3aed" stroke-width="2" style="flex-shrink:0;margin-top:1px"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 01-2.06 0L2 7"/></svg>
                <span>
                    <?php echo xlt('A 6-digit code was sent to'); ?>
                    <strong><?php echo text($maskedEmail ?: xlt('your registered email')); ?></strong>.
                    <?php echo xlt('It expires in 10 minutes.'); ?>
                </span>
            </div>
        </div>

        <form method="post" action="mdi_otp_verify.php" id="verify-form" autocomplete="off">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(mdiResetNonce()); ?>">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="method" id="method-field" value="<?php echo attr($method); ?>">
            <div class="fg">
                <label class="lbl" for="code"><?php echo xlt('Verification Code'); ?></label>
                <input type="text" id="code" name="code" class="inp"
                       inputmode="numeric" pattern="[0-9 ]*" maxlength="7"
                       autocomplete="one-time-code" placeholder="000000"
                       autofocus>
            </div>
            <button type="submit" class="btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <?php echo xlt('Verify & Continue'); ?>
            </button>
        </form>

        <?php if ($hasTOTP || !empty($_SESSION['pw_reset_otp_hash'])): ?>
        <!-- Resend email OTP -->
        <form method="post" action="mdi_otp_verify.php" id="resend-form" autocomplete="off">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(mdiResetNonce()); ?>">
            <input type="hidden" name="action" value="resend">
            <button type="submit" class="btn-ghost" id="resend-btn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.51"/></svg>
                <?php echo xlt('Resend email code'); ?>
            </button>
        </form>
        <?php endif; ?>

        <?php if ($attempts > 2): ?>
        <p class="attempts"><?php echo sprintf(xl('%d of 5 attempts used'), $attempts); ?></p>
        <?php endif; ?>

        <a href="mdi_forgot_password.php" class="back">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:middle;margin-right:3px"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            <?php echo xlt('Start over'); ?>
        </a>
    </div>
</div>
<script>
function switchMethod(m) {
    document.getElementById('method-field').value = m;
    document.getElementById('panel-totp').style.display = m === 'totp' ? '' : 'none';
    document.getElementById('panel-email').style.display = m === 'email' ? '' : 'none';
    document.querySelectorAll('.tab').forEach(function(t, i) {
        t.classList.toggle('active', (i === 0 && m === 'totp') || (i === 1 && m === 'email'));
    });
    document.getElementById('code').focus();
    // Send email OTP if switching to email and hasn't been sent
    if (m === 'email') {
        document.getElementById('resend-form') && document.getElementById('resend-btn').textContent;
    }
}
// Auto-submit when 6 digits entered
document.getElementById('code').addEventListener('input', function() {
    var v = this.value.replace(/\D/g, '');
    this.value = v;
    if (v.length === 6) document.getElementById('verify-form').submit();
});
</script>
</body>
</html>
