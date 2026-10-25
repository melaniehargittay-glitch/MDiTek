<?php

/**
 * Batch Email processor — included from batchcom.php
 *
 * Replaces the original mail() implementation with PHPMailer so it honours
 * the SMTP settings configured under Admin → Configuration → Notifications
 * (smtp_host, smtp_port, smtp_user, smtp_pass, smtp_secure, etc.)
 *
 * @package OpenEMR
 * @link    http://www.open-emr.org
 */

require_once("../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

// ── Load PHPMailer directly as fallback if postmaster not found ───────────────
if (!$_mailer_loaded) {
    $_phpmailer_paths = [
        $GLOBALS['fileroot'] . '/vendor/phpmailer/phpmailer/src/PHPMailer.php',
        $GLOBALS['fileroot'] . '/library/phpmailer/PHPMailer.php',
    ];
    foreach ($_phpmailer_paths as $_path) {
        if (file_exists($_path)) {
            require_once dirname($_path) . '/PHPMailer.php';
            @require_once dirname($_path) . '/SMTP.php';
            @require_once dirname($_path) . '/Exception.php';
            $_mailer_loaded = true;
            break;
        }
    }
}
$_phpmailer_loaded = $_mailer_loaded; // used in config table display

// ── Load OpenEMR's own mailer (postmaster.php wraps PHPMailer and reads
// the SMTP globals automatically — no need to pass them manually) ─────────────
$_postmaster_paths = [
    $GLOBALS['srcdir'] . '/classes/postmaster.php',          // modern path
    $GLOBALS['srcdir'] . '/../library/classes/postmaster.php', // alternate
    $GLOBALS['fileroot'] . '/library/classes/postmaster.php',
];
$_mailer_loaded = false;
foreach ($_postmaster_paths as $_path) {
    if (file_exists($_path)) {
        require_once $_path;
        $_mailer_loaded = true;
        break;
    }
}

// ── Read SMTP settings using the EXACT OpenEMR global key names ───────────────
// Source: library/globals.inc.php and postmaster.php in OpenEMR source.
//
//  Global key                    Config UI label (Admin→Config→Notifications)
//  ──────────────────────────    ──────────────────────────────────────────────
//  patient_reminder_sender_name  Patient Reminder Sender Name
//  patient_reminder_sender_email Patient Reminder Sender Email
//  EMAIL_METHOD                  Email Transport Method  (SMTP / PHPMAIL / SENDMAIL)
//  SMTP_HOST                     SMTP Server Hostname          ← was SMTP_HOSTNAME (wrong)
//  SMTP_PORT                     SMTP Server Port Number
//  SMTP_USER                     SMTP User for Authentication
//  SMTP_PASS                     SMTP Password for Authentication
//  SMTP_SECURE (or SMTPSecure)   SMTP Security Protocol (TLS/SSL)
//
$cfg_from_name  = trim($GLOBALS['patient_reminder_sender_name']  ?? 'Patient Notifications');
$cfg_from_email = trim(
    $GLOBALS['patient_reminder_sender_email']
    ?? $GLOBALS['practice_return_email_name']
    ?? ''
);

$cfg_smtp_host   = trim($GLOBALS['SMTP_HOST']   ?? '');
$cfg_smtp_port   = (int)($GLOBALS['SMTP_PORT']  ?? 587);
$cfg_smtp_user   = trim($GLOBALS['SMTP_USER']   ?? '');
$cfg_smtp_pass   = trim($GLOBALS['SMTP_PASS']   ?? '');
$cfg_smtp_auth   = !empty($GLOBALS['SMTP_Auth'] ?? $cfg_smtp_user);
$cfg_smtp_secure = strtolower(trim(
    $GLOBALS['SMTP_SECURE']   ??
    $GLOBALS['SMTPSecure']    ??
    $GLOBALS['smtp_secure']   ?? 'tls'
));
$cfg_transport   = strtoupper(trim($GLOBALS['EMAIL_METHOD'] ?? 'SMTP'));

// Use the form's sender field if staff overrode it; otherwise fall back to setting
$email_sender    = trim($_POST['email_sender'] ?? '');
if ($email_sender === '') {
    $email_sender = $cfg_from_email;
}
$email_sender_name = $cfg_from_name;

// ── Determine transport ───────────────────────────────────────────────────────
// Use EMAIL_METHOD (= 'SMTP') and SMTP_HOST (not SMTP_HOSTNAME) — exact OpenEMR keys.
$use_smtp     = $_mailer_loaded && $cfg_transport === 'SMTP' && $cfg_smtp_host !== '';
$use_mymailer = $use_smtp && class_exists('MyMailer');

// ── Counters ─────────────────────────────────────────────────────────────────
$sent_count   = 0;
$failed_count = 0;
$failed_rows  = [];

// ── Build the base mailer once (reused per recipient) ────────────────────────
$mailer           = null;
$smtp_setup_error = '';

if ($use_mymailer) {
    // Prefer OpenEMR's own MyMailer — it reads SMTP_HOST/PORT/USER/PASS from
    // globals internally, so no manual configuration needed.
    try {
        $mailer = new MyMailer();
        $mailer->setFrom($email_sender, $email_sender_name);
        $mailer->CharSet       = 'UTF-8';
        $mailer->isHTML(false);
        $mailer->SMTPKeepAlive = true;
    } catch (\Throwable $e) {
        $smtp_setup_error = $e->getMessage();
        $mailer = null; $use_smtp = false;
    }
} elseif ($use_smtp && class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    // Fall back to raw PHPMailer with manual config from globals
    try {
        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host     = $cfg_smtp_host;
        $mailer->Port     = $cfg_smtp_port;
        $mailer->SMTPAuth = $cfg_smtp_auth;
        $mailer->Username = $cfg_smtp_user;
        $mailer->Password = $cfg_smtp_pass;
        if ($cfg_smtp_secure === 'ssl') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($cfg_smtp_secure === 'tls') {
            $mailer->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPAutoTLS = false;
            $mailer->SMTPSecure  = '';
        }
        $mailer->CharSet       = 'UTF-8';
        $mailer->isHTML(false);
        $mailer->setFrom($email_sender, $email_sender_name);
        $mailer->SMTPKeepAlive = true;
    } catch (\Throwable $e) {
        $smtp_setup_error = $e->getMessage();
        $mailer = null; $use_smtp = false;
    }
}

?>
<html>
<head>
    <title><?php echo xlt('Email Notification Report'); ?></title>
    <?php Header::setupHeader(); ?>
    <style>
        body { font-family: 'DM Sans', system-ui, sans-serif; background: #f9fafb; color: #111827; }
        .bc-report { max-width: 820px; margin: 0 auto; padding: 24px 20px 48px; }
        .bc-report-header { margin-bottom: 24px; }
        .bc-report-title { font-size: 20px; font-weight: 700; color: #111827; margin: 0 0 4px; }
        .bc-report-title span { color: #7c3aed; }
        .bc-report-sub { font-size: 13px; color: #6b7280; }

        /* Status cards */
        .bc-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
        .bc-stat { background: #fff; border: 1.5px solid #e5e7eb; border-radius: 10px; padding: 14px 18px; }
        .bc-stat-num { font-size: 28px; font-weight: 800; line-height: 1; margin-bottom: 4px; }
        .bc-stat-lbl { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; }
        .bc-stat.sent   { border-color: #86efac; background: #f0fdf4; }
        .bc-stat.sent   .bc-stat-num { color: #15803d; }
        .bc-stat.failed { border-color: #fca5a5; background: #fef2f2; }
        .bc-stat.failed .bc-stat-num { color: #dc2626; }
        .bc-stat.total  { border-color: #c4b5fd; background: #f5f3ff; }
        .bc-stat.total  .bc-stat-num { color: #6d28d9; }

        /* Alert blocks */
        .bc-alert { border-radius: 10px; padding: 14px 18px; margin-bottom: 16px; font-size: 13px; line-height: 1.6; }
        .bc-alert-error { background: #fef2f2; border: 1.5px solid #fca5a5; color: #991b1b; }
        .bc-alert-warn  { background: #fffbeb; border: 1.5px solid #fcd34d; color: #92400e; }
        .bc-alert-info  { background: #eff6ff; border: 1.5px solid #93c5fd; color: #1e40af; }
        .bc-alert strong { display: block; font-size: 14px; margin-bottom: 4px; }
        .bc-alert code { background: rgba(0,0,0,.06); border-radius: 4px; padding: 1px 5px; font-size: 12px; font-family: monospace; }

        /* Config debug table */
        .bc-cfg { background: #fff; border: 1.5px solid #e5e7eb; border-radius: 10px; overflow: hidden; margin-bottom: 20px; }
        .bc-cfg caption { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; padding: 10px 16px 6px; text-align: left; }
        .bc-cfg td, .bc-cfg th { font-size: 12px; padding: 7px 16px; border-bottom: 1px solid #f3f4f6; }
        .bc-cfg td:first-child { font-weight: 600; color: #374151; width: 200px; }
        .bc-cfg td:last-child { font-family: monospace; color: #6d28d9; }
        .bc-cfg tr:last-child td { border-bottom: none; }

        /* Results list */
        .bc-results { background: #fff; border: 1.5px solid #e5e7eb; border-radius: 10px; overflow: hidden; }
        .bc-results-hdr { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; padding: 10px 16px 8px; border-bottom: 1px solid #f3f4f6; }
        .bc-result-row { display: flex; align-items: center; gap: 10px; padding: 8px 16px; border-bottom: 1px solid #f9fafb; font-size: 12.5px; }
        .bc-result-row:last-child { border-bottom: none; }
        .bc-result-icon { width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .bc-result-icon.ok   { background: #d1fae5; color: #065f46; }
        .bc-result-icon.fail { background: #fee2e2; color: #991b1b; }
        .bc-result-name  { flex: 1; font-weight: 600; color: #111827; }
        .bc-result-email { color: #6b7280; }
        .bc-result-reason{ color: #dc2626; font-size: 11.5px; margin-top: 1px; }

        .bc-back { display: inline-flex; align-items: center; gap: 6px; margin-top: 20px;
                   padding: 9px 18px; background: #7c3aed; color: #fff; border: none; border-radius: 8px;
                   font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; }
    </style>
</head>
<body class="body_top">

<div class="bc-report">
    <?php require_once("batch_navigation.php"); ?>

    <div class="bc-report-header">
        <div class="bc-report-title"><span><?php echo xlt('Batch Communication Tool'); ?></span> — <?php echo xlt('Email Send Report'); ?></div>
        <div class="bc-report-sub">
            <?php echo xlt('From'); ?>: <strong><?php echo text($email_sender_name); ?></strong>
            &lt;<?php echo text($email_sender); ?>&gt;
            &nbsp;&middot;&nbsp;
            <?php echo xlt('Via'); ?>: <?php echo text($use_smtp
                ? ($use_mymailer ? 'SMTP via MyMailer' : 'SMTP') . ' (' . $cfg_smtp_host . ':' . $cfg_smtp_port . ')'
                : ($smtp_setup_error ? 'SMTP setup failed — PHP mail() fallback' : 'PHP mail()')
            ); ?>
        </div>
    </div>

    <?php
    // ── SMTP setup error banner ───────────────────────────────────────────────
    if ($smtp_setup_error): ?>
    <div class="bc-alert bc-alert-error">
        <strong>⚠ SMTP Connection Setup Failed</strong>
        PHPMailer could not be configured with the settings from Admin → Configuration → Notifications.
        The batch fell back to PHP <code>mail()</code> which may also fail if your server has no local MTA.
        <br><br>
        <strong>Error detail:</strong> <code><?php echo text($smtp_setup_error); ?></code>
        <br><br>
        Check: <strong>SMTP Hostname</strong> (<code><?php echo text($cfg_smtp_host); ?></code>),
        <strong>Port</strong> (<code><?php echo text($cfg_smtp_port); ?></code>),
        and <strong>SMTP User / Password</strong> under Admin → Configuration → Notifications.
    </div>
    <?php elseif (!$_phpmailer_loaded): ?>
    <div class="bc-alert bc-alert-warn">
        <strong>⚠ PHPMailer Not Found</strong>
        PHPMailer could not be loaded from the expected vendor path. Falling back to PHP <code>mail()</code>.
        If emails fail, ensure Composer dependencies are installed (<code>composer install</code> in the OpenEMR root).
    </div>
    <?php elseif ($cfg_smtp_host === '' && $cfg_transport === 'SMTP'): ?>
    <div class="bc-alert bc-alert-warn">
        <strong>⚠ SMTP Hostname Not Configured</strong>
        The Email Transport Method is set to SMTP but no SMTP hostname has been entered.
        Go to Admin → Configuration → Notifications and fill in the SMTP Server Hostname field.
    </div>
    <?php endif; ?>

    <?php
    // ── Configuration summary (always shown so staff can verify) ─────────────
    ?>
    <table class="bc-cfg">
        <caption><?php echo xlt('Email Configuration (Admin → Configuration → Notifications)'); ?></caption>
        <tr><td><?php echo xlt('Transport'); ?></td><td><?php echo text(strtoupper($cfg_transport)); ?></td></tr>
        <tr><td><?php echo xlt('From Address'); ?></td><td><?php echo text($email_sender); ?></td></tr>
        <tr><td><?php echo xlt('From Name'); ?></td><td><?php echo text($email_sender_name); ?></td></tr>
        <?php if ($use_smtp || $cfg_transport === 'SMTP'): ?>
        <tr><td><?php echo xlt('SMTP Host'); ?></td><td><?php echo text($cfg_smtp_host ?: '— not set —'); ?></td></tr>
        <tr><td><?php echo xlt('SMTP Port'); ?></td><td><?php echo text($cfg_smtp_port); ?></td></tr>
        <tr><td><?php echo xlt('SMTP User'); ?></td><td><?php echo text($cfg_smtp_user ?: '— not set —'); ?></td></tr>
        <tr><td><?php echo xlt('SMTP Security'); ?></td><td><?php echo text(strtoupper($cfg_smtp_secure ?: 'none')); ?></td></tr>
        <?php endif; ?>
        <tr><td><?php echo xlt('Mailer Loaded'); ?></td><td><?php
            if ($use_mymailer)       { echo '✓ MyMailer (OpenEMR postmaster)'; }
            elseif ($_mailer_loaded) { echo '✓ PHPMailer (vendor)'; }
            else                     { echo '✗ Not found — using PHP mail()'; }
        ?></td></tr>
    </table>

    <?php
    // ═════════════════════════════════════════════════════════════════════════
    // SEND LOOP
    // ═════════════════════════════════════════════════════════════════════════
    $result_rows_html = '';

    while ($row = sqlFetchArray($res)) {
        $pt_name  = trim(($row['title'] ?? '') . ' ' . ($row['fname'] ?? '') . ' ' . ($row['lname'] ?? ''));
        $pt_email = trim($row['email'] ?? '');

        if ($pt_email === '') {
            $failed_count++;
            $failed_rows[] = ['name' => $pt_name, 'email' => '—', 'reason' => xl('No email address on file')];
            continue;
        }

        $subj = preg_replace('/\*{3}NAME\*{3}/', $pt_name, $_POST['email_subject'] ?? '');
        $body = preg_replace('/\*{3}NAME\*{3}/', $pt_name, $_POST['email_body']    ?? '');

        $ok     = false;
        $reason = '';

        if ($use_smtp && $mailer) {
            // ── PHPMailer / SMTP path ─────────────────────────────────────
            try {
                $mailer->clearAddresses();
                $mailer->clearReplyTos();
                $mailer->addAddress($pt_email, $pt_name);
                $mailer->addReplyTo($email_sender, $email_sender_name);
                $mailer->Subject = $subj;
                $mailer->Body    = $body;
                $mailer->send();
                $ok = true;
            } catch (\Throwable $e) {
                $reason = $e->getMessage();
                // If SMTP connection dropped mid-batch, try to close gracefully
                try { $mailer->smtpClose(); } catch (\Throwable $ignored) {}
            }
        } else {
            // ── PHP mail() fallback ───────────────────────────────────────
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "To: " . $pt_name . " <" . $pt_email . ">\r\n";
            $headers .= "From: " . $email_sender_name . " <" . $email_sender . ">\r\n";
            $headers .= "Reply-To: <" . $email_sender . ">\r\n";
            $headers .= "X-Priority: 3\r\n";
            $ok = @mail($pt_email, $subj, $body, $headers);
            if (!$ok) {
                $reason = xl('PHP mail() returned false. Check server MTA configuration.');
            }
        }

        if ($ok) {
            $sent_count++;
            $result_rows_html .= '
            <div class="bc-result-row">
                <div class="bc-result-icon ok">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div class="bc-result-name">' . text($pt_name) . '</div>
                <div class="bc-result-email">' . text($pt_email) . '</div>
            </div>';
        } else {
            $failed_count++;
            $failed_rows[] = ['name' => $pt_name, 'email' => $pt_email, 'reason' => $reason];
            $result_rows_html .= '
            <div class="bc-result-row">
                <div class="bc-result-icon fail">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="3" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </div>
                <div style="flex:1">
                    <div class="bc-result-name">' . text($pt_name) . ' <span class="bc-result-email">' . text($pt_email) . '</span></div>
                    <div class="bc-result-reason">' . text($reason) . '</div>
                </div>
            </div>';
        }
    }

    // Close SMTP connection cleanly
    if ($mailer && $use_smtp) {
        try { $mailer->smtpClose(); } catch (\Throwable $ignored) {}
    }
    ?>

    <!-- Summary cards -->
    <div class="bc-summary">
        <div class="bc-stat sent">
            <div class="bc-stat-num"><?php echo (int)$sent_count; ?></div>
            <div class="bc-stat-lbl"><?php echo xlt('Sent'); ?></div>
        </div>
        <div class="bc-stat failed">
            <div class="bc-stat-num"><?php echo (int)$failed_count; ?></div>
            <div class="bc-stat-lbl"><?php echo xlt('Failed'); ?></div>
        </div>
        <div class="bc-stat total">
            <div class="bc-stat-num"><?php echo (int)($sent_count + $failed_count); ?></div>
            <div class="bc-stat-lbl"><?php echo xlt('Total'); ?></div>
        </div>
    </div>

    <?php
    // ── Failure detail block ──────────────────────────────────────────────────
    if ($failed_count > 0):
        // Determine likely root cause for the banner
        $sample_reason = $failed_rows[0]['reason'] ?? '';
        $is_auth_fail  = stripos($sample_reason, 'auth') !== false
                      || stripos($sample_reason, 'password') !== false
                      || stripos($sample_reason, '535') !== false
                      || stripos($sample_reason, '534') !== false;
        $is_conn_fail  = stripos($sample_reason, 'connect') !== false
                      || stripos($sample_reason, 'timed out') !== false
                      || stripos($sample_reason, 'refused') !== false
                      || stripos($sample_reason, 'could not') !== false;
        $is_tls_fail   = stripos($sample_reason, 'tls') !== false
                      || stripos($sample_reason, 'ssl') !== false
                      || stripos($sample_reason, 'certificate') !== false;
    ?>
    <div class="bc-alert bc-alert-error" style="margin-bottom:16px;">
        <strong>
            <?php echo text($failed_count); ?> <?php echo xlt('email(s) could not be sent'); ?>
        </strong>

        <?php if ($is_auth_fail): ?>
            <?php echo xlt('SMTP authentication failed.'); ?>
            <?php echo xlt('Check the SMTP User and SMTP Password under Admin → Configuration → Notifications.'); ?>
            <?php echo xlt('Make sure the account has permission to relay email through'); ?>
            <code><?php echo text($cfg_smtp_host); ?></code>.

        <?php elseif ($is_conn_fail): ?>
            <?php echo xlt('Could not connect to the SMTP server.'); ?>
            <?php echo xlt('Verify that the hostname'); ?>
            <code><?php echo text($cfg_smtp_host); ?></code>
            <?php echo xlt('and port'); ?>
            <code><?php echo text($cfg_smtp_port); ?></code>
            <?php echo xlt('are correct, and that your server firewall allows outbound connections on that port.'); ?>

        <?php elseif ($is_tls_fail): ?>
            <?php echo xlt('TLS/SSL negotiation failed.'); ?>
            <?php echo xlt('Try changing the SMTP Security Protocol to'); ?> <code>TLS</code>
            <?php echo xlt('or'); ?> <code>SSL</code>
            <?php echo xlt('under Admin → Configuration → Notifications, or verify the server certificate.'); ?>

        <?php else: ?>
            <?php echo xlt('See the individual error messages in the results list below.'); ?>
            <?php echo xlt('All SMTP settings can be updated under Admin → Configuration → Notifications.'); ?>
        <?php endif; ?>

        <?php if ($sample_reason): ?>
        <br><br><strong><?php echo xlt('Most recent error'); ?>:</strong>
        <code><?php echo text($sample_reason); ?></code>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Per-recipient results -->
    <div class="bc-results">
        <div class="bc-results-hdr">
            <?php echo xlt('Results'); ?> — <?php echo text($sent_count); ?> / <?php echo text($sent_count + $failed_count); ?> <?php echo xlt('sent successfully'); ?>
        </div>
        <?php echo $result_rows_html; ?>
    </div>

    <a href="batchcom.php" class="bc-back">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
        <?php echo xlt('Back to BatchCom'); ?>
    </a>

</div>
</body>
</html>
