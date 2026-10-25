<?php
/**
 * MDItek Password Reset — Step 3: Set New Password
 * Place at: /interface/login/mdi_new_password.php
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

// OpenEMR password update — uses sqlStatement directly since
// login_operations.php requires auth context



$token  = trim($_GET['token'] ?? $_POST['token'] ?? '');
$uid    = (int)($_SESSION['pw_reset_token_uid'] ?? 0);
$error  = '';
$done   = false;

// Validate token exists and isn't expired
if (empty($token) || empty($uid)) {
    header('Location: mdi_forgot_password.php?msg=invalid'); exit;
}

// Ensure token table exists
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

$tokenRow = sqlQuery(
    "SELECT token_hash, expires_at FROM mdi_password_reset_tokens
     WHERE user_id = ? AND expires_at > NOW() LIMIT 1",
    [$uid]
);

if (empty($tokenRow) || !password_verify($token, $tokenRow['token_hash'])) {
    header('Location: mdi_forgot_password.php?msg=invalid'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set') {
    if (!mdiVerifyNonce($_POST['csrf_token_form'] ?? '')) {
        $error = xl('Invalid request.');
    } else {
        $pass1 = $_POST['password1'] ?? '';
        $pass2 = $_POST['password2'] ?? '';

        if (strlen($pass1) < 8) {
            $error = xl('Password must be at least 8 characters.');
        } elseif (!preg_match('/[A-Z]/', $pass1)) {
            $error = xl('Password must contain at least one uppercase letter.');
        } elseif (!preg_match('/[0-9]/', $pass1)) {
            $error = xl('Password must contain at least one number.');
        } elseif (!preg_match('/[^A-Za-z0-9]/', $pass1)) {
            $error = xl('Password must contain at least one special character.');
        } elseif ($pass1 !== $pass2) {
            $error = xl('Passwords do not match.');
        } else {
            // Hash using OpenEMR's standard bcrypt
            $hash   = password_hash($pass1, PASSWORD_BCRYPT, ['cost' => 10]);
            $result = false;

            // Update users_secure (modern OpenEMR stores passwords here)
            $secureCheck = sqlQuery(
                "SELECT id FROM users_secure WHERE id = ?", [$uid]
            );
            if ($secureCheck) {
                $result = sqlStatement(
                    "UPDATE users_secure SET password = ?, last_update_password = NOW() WHERE id = ?",
                    [$hash, $uid]
                );
            } else {
                // users_secure row missing — insert it
                $urow = sqlQuery("SELECT username FROM users WHERE id = ?", [$uid]);
                if ($urow) {
                    $result = sqlStatement(
                        "INSERT INTO users_secure (id, username, password, last_update_password)
                         VALUES (?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE password = ?, last_update_password = NOW()",
                        [$uid, $urow['username'], $hash, $hash]
                    );
                }
            }
            // Also update legacy users.password column for compatibility
            sqlStatement("UPDATE users SET password = ? WHERE id = ?", [$hash, $uid]);

            if ($result !== false) {
                // Invalidate the token immediately
                sqlStatement("DELETE FROM mdi_password_reset_tokens WHERE user_id = ?", [$uid]);
                // Clear session
                unset($_SESSION['pw_reset_token_uid'], $_SESSION['pw_reset_uid'],
                      $_SESSION['pw_reset_username'], $_SESSION['pw_reset_email'],
                      $_SESSION['pw_reset_step'], $_SESSION['pw_reset_initiated'],
                      $_SESSION['mdi_reset_nonce']);
                session_write_close();
                $done = true;
            } else {
                $error = xl('Failed to update password. Please contact your administrator.');
            }
        }
    }
}

function passwordStrength(string $p): array {
    $score = 0;
    if (strlen($p) >= 8)  $score++;
    if (strlen($p) >= 12) $score++;
    if (preg_match('/[A-Z]/', $p)) $score++;
    if (preg_match('/[0-9]/', $p)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $p)) $score++;
    $labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
    $colors = ['#ef4444','#f97316','#eab308','#22c55e','#10b981','#059669'];
    return ['score' => $score, 'label' => $labels[$score], 'color' => $colors[$score]];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo xlt('New Password'); ?></title>

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
.inp-wrap { position:relative; }
.inp { width:100%; border:1.5px solid #ddd6fe; border-radius:10px; padding:12px 42px 12px 14px; font-family:'JetBrains Mono',monospace; font-size:13px; color:#1e0a3c; background:#fafafe; transition:all .2s; outline:none; }
.inp:focus { border-color:#7c3aed; background:#fff; box-shadow:0 0 0 3px rgba(124,58,237,.12); }
.inp::placeholder { color:#c4b5fd; font-size:12px; font-family:'Outfit',sans-serif; letter-spacing:normal; }
.eye-btn { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#9ca3af; padding:4px; display:flex; }
.eye-btn:hover { color:#7c3aed; }
.btn { width:100%; background:linear-gradient(135deg,#5b21b6 0%,#7c3aed 100%); border:none; border-radius:12px; padding:13px; font-family:'Outfit',sans-serif; font-size:14px; font-weight:700; color:#fff; cursor:pointer; transition:all .2s; box-shadow:0 4px 14px rgba(91,33,182,.35); margin-top:8px; display:flex; align-items:center; justify-content:center; gap:7px; }
.btn:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(91,33,182,.45); }
.err { background:#fef2f2; border:1px solid #fecaca; border-radius:10px; color:#991b1b; font-size:12.5px; font-weight:500; padding:10px 14px; margin-bottom:16px; display:flex; gap:8px; }
.fg { margin-bottom:18px; }
.strength-bar { height:4px; border-radius:2px; background:#f0edff; margin-top:8px; overflow:hidden; }
.strength-fill { height:100%; border-radius:2px; transition:all .3s; width:0%; }
.strength-lbl { font-size:10px; color:#9ca3af; margin-top:4px; text-align:right; }
.rules { background:#f9f7ff; border:1px solid #ede9fe; border-radius:10px; padding:12px 14px; margin-bottom:18px; }
.rules p { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b21a8; margin-bottom:8px; }
.rule { font-size:12px; color:#6b7280; display:flex; align-items:center; gap:7px; padding:2px 0; transition:color .2s; }
.rule.pass { color:#059669; }
.rule svg { flex-shrink:0; }
.done-state { text-align:center; padding:8px 0; }
.done-state .done-icon { width:64px; height:64px; background:linear-gradient(135deg,#10b981,#059669); border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 18px; box-shadow:0 8px 24px rgba(16,185,129,.35); }
.done-state h2 { font-size:20px; font-weight:800; color:#1e0a3c; margin-bottom:8px; }
.done-state p { font-size:13px; color:#6b7280; margin-bottom:24px; line-height:1.6; }
</style>
</head>
<body>
<div class="card">
    <div class="card-hdr">
        <div class="hdr-icon">
            <?php if ($done): ?>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            <?php else: ?>
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
            <?php endif; ?>
        </div>
        <h1><?php echo $done ? xlt('Password Updated') : xlt('New Password'); ?></h1>
        <p><?php echo $done ? xlt('Your password has been successfully reset') : xlt('Choose a strong new password'); ?></p>
    </div>
    <div class="card-body">
        <?php if ($done): ?>
        <div class="done-state">
            <div class="done-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <h2><?php echo xlt('All done!'); ?></h2>
            <p><?php echo xlt('Your password has been updated. You can now log in with your new password.'); ?></p>
            <a href="login.php" style="display:inline-block;background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#fff;border-radius:12px;padding:12px 28px;font-weight:700;font-size:14px;text-decoration:none;box-shadow:0 4px 14px rgba(91,33,182,.35);">
                <?php echo xlt('Go to Login'); ?>
            </a>
        </div>
        <?php else: ?>

        <?php if ($error): ?>
        <div class="err">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#991b1b" stroke-width="2" style="flex-shrink:0;margin-top:1px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <?php echo text($error); ?>
        </div>
        <?php endif; ?>

        <div class="rules" id="rules">
            <p><?php echo xlt('Password requirements'); ?></p>
            <div class="rule" id="r-len"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg><?php echo xlt('At least 8 characters'); ?></div>
            <div class="rule" id="r-upper"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg><?php echo xlt('One uppercase letter (A-Z)'); ?></div>
            <div class="rule" id="r-num"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg><?php echo xlt('One number (0-9)'); ?></div>
            <div class="rule" id="r-sym"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg><?php echo xlt('One special character (!@#$...)'); ?></div>
        </div>

        <form method="post" action="mdi_new_password.php" autocomplete="off">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(mdiResetNonce()); ?>">
            <input type="hidden" name="action" value="set">
            <input type="hidden" name="token" value="<?php echo attr($token); ?>">

            <div class="fg">
                <label class="lbl" for="password1"><?php echo xlt('New Password'); ?></label>
                <div class="inp-wrap">
                    <input type="password" id="password1" name="password1" class="inp"
                           placeholder="<?php echo attr(xlt('Enter new password')); ?>"
                           autofocus oninput="checkStrength(this.value)">
                    <button type="button" class="eye-btn" onclick="toggleVis('password1', this)">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strength-fill"></div></div>
                <div class="strength-lbl" id="strength-lbl"></div>
            </div>
            <div class="fg">
                <label class="lbl" for="password2"><?php echo xlt('Confirm Password'); ?></label>
                <div class="inp-wrap">
                    <input type="password" id="password2" name="password2" class="inp"
                           placeholder="<?php echo attr(xlt('Re-enter new password')); ?>">
                    <button type="button" class="eye-btn" onclick="toggleVis('password2', this)">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
                <?php echo xlt('Set New Password'); ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<script>
var CHECK_SVG = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
var CIRCLE_SVG = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';

function rule(id, pass) {
    var el = document.getElementById(id);
    if (!el) return;
    el.className = 'rule' + (pass ? ' pass' : '');
    el.querySelector('svg').outerHTML; // replace icon
    el.innerHTML = (pass ? CHECK_SVG : CIRCLE_SVG) + el.innerHTML.replace(/<svg[^>]*>.*?<\/svg>/s, '');
}

function checkStrength(v) {
    var len   = v.length >= 8;
    var upper = /[A-Z]/.test(v);
    var num   = /[0-9]/.test(v);
    var sym   = /[^A-Za-z0-9]/.test(v);
    var score = [len, upper, num, sym].filter(Boolean).length + (v.length >= 12 ? 1 : 0);
    var pct   = Math.min(score * 20, 100);
    var colors= ['#ef4444','#f97316','#eab308','#22c55e','#059669'];
    var labels= ['','Weak','Fair','Good','Strong','Very Strong'];
    var fill  = document.getElementById('strength-fill');
    var lbl   = document.getElementById('strength-lbl');
    if (fill) { fill.style.width = pct + '%'; fill.style.background = colors[Math.min(score-1,4)] || '#f0edff'; }
    if (lbl)  lbl.textContent = v.length > 0 ? (labels[score] || '') : '';

    // Update rule indicators
    var rules = document.querySelectorAll('.rule');
    [[len, 'r-len'],[upper, 'r-upper'],[num, 'r-num'],[sym, 'r-sym']].forEach(function(r) {
        var el = document.getElementById(r[1]);
        if (!el) return;
        el.className = 'rule' + (r[0] ? ' pass' : '');
        el.querySelector('svg').setAttribute('stroke', r[0] ? '#059669' : 'currentColor');
    });
}

function toggleVis(id, btn) {
    var inp = document.getElementById(id);
    var show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    btn.style.color = show ? '#7c3aed' : '';
}
</script>
</body>
</html>
