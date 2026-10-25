<link rel="shortcut icon" href="../public/images/logos/core/favicon/favicon.ico?t=1771584483">
<?php
/**
 * portal-nav.php — Shared MDITek Portal Navbar Partial
 */

// ── Gather session data safely ─────────────────────────────────────────────
$_nav_webroot   = $GLOBALS['web_root']   ?? '';
$_nav_pid       = $_SESSION['pid']       ?? 0;

// Name: try every session key the portal might use, then fall back to DB
$_nav_fname = $_SESSION['portal_fname']
           ?? $_SESSION['ptFname']
           ?? $_SESSION['authFirstName']
           ?? ($result['fname'] ?? '');
$_nav_lname = $_SESSION['portal_lname']
           ?? $_SESSION['ptLname']
           ?? $_SESSION['authLastName']
           ?? ($result['lname'] ?? '');

// If session had nothing, try a quick DB lookup by pid
if (($_nav_fname === '' && $_nav_lname === '') && !empty($_nav_pid)) {
    if (function_exists('sqlQuery')) {
        $_nav_ptrow = sqlQuery(
            "SELECT fname, lname FROM patient_data WHERE pid = ? LIMIT 1",
            [$_nav_pid]
        );
        $_nav_fname = $_nav_ptrow['fname'] ?? '';
        $_nav_lname = $_nav_ptrow['lname'] ?? '';
    }
}

$_nav_username = $_SESSION['portal_username'] ?? '';
$_nav_fullname = trim(htmlspecialchars($_nav_fname . ' ' . $_nav_lname));

// Build initials from first char of fname + first char of lname
$_nav_fi = strtoupper(substr(trim($_nav_fname), 0, 1));
$_nav_li = strtoupper(substr(trim($_nav_lname), 0, 1));

if ($_nav_fi !== '' && $_nav_li !== '') {
    // Both present: "JD"
    $_nav_initials = $_nav_fi . $_nav_li;
} elseif ($_nav_fi !== '') {
    // Only first name: "J"
    $_nav_initials = $_nav_fi;
} elseif ($_nav_li !== '') {
    // Only last name: "D"
    $_nav_initials = $_nav_li;
} elseif ($_nav_username !== '') {
    // Fall back to first two chars of username
    $_nav_initials = strtoupper(substr($_nav_username, 0, 2));
} else {
    $_nav_initials = '?';
}

// Unread message count
$_nav_newcnt = 0;
if (function_exists('getPortalPatientNotes')) {
    $_nav_msgs = getPortalPatientNotes($_nav_username);
    foreach ($_nav_msgs as $_m) {
        if ($_m['message_status'] === 'New') $_nav_newcnt++;
    }
}

$_nav_messagesURL = $_nav_webroot . '/portal/messaging/messages.php';
$_nav_active = $PORTAL_NAV_ACTIVE ?? '';

// Helper to emit active class
function _nav_chip_cls($key, $active) {
    return $key === $active ? ' active' : '';
}
?>
<!-- ══ PORTAL SUB-NAV ══════════════════════════════════════════════════ -->
<nav class="pt-sub-nav">

    <!-- Brand -->
    <a class="pt-nav-brand" href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/home.php">
        <div class="pt-nav-brand-dots">
            <span></span><span></span><span></span><span></span>
        </div>
        MDITek
    </a>

    <!-- Nav chips -->
    <div class="pt-nav-chips">

        <a class="pt-nav-chip<?php echo _nav_chip_cls('dashboard', $_nav_active); ?>"
           href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/home.php">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="currentColor">
                <rect x="3" y="3" width="7" height="7" rx="1"/>
                <rect x="14" y="3" width="7" height="7" rx="1"/>
                <rect x="3" y="14" width="7" height="7" rx="1"/>
                <rect x="14" y="14" width="7" height="7" rx="1"/>
            </svg>
            Dashboard
        </a>

        <?php if (!empty($GLOBALS['allow_portal_appointments'])): ?>
        <a style="display:none;" class="pt-nav-chip<?php echo _nav_chip_cls('appointments', $_nav_active); ?>"
           href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/home.php#appointmentcard">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2"/>
                <line x1="16" y1="2" x2="16" y2="6"/>
                <line x1="8" y1="2" x2="8" y2="6"/>
                <line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            Appointments
        </a>
        <?php endif; ?>

        <a style="display:none;" class="pt-nav-chip<?php echo _nav_chip_cls('messages', $_nav_active); ?>"
           href="<?php echo htmlspecialchars($_nav_messagesURL); ?>">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
            Messages
            <?php if ($_nav_newcnt > 0): ?>
            <span style="background:var(--rose);color:#fff;border-radius:10px;padding:0 5px;font-size:9px;font-weight:700;line-height:16px;display:inline-block;"><?php echo (int)$_nav_newcnt; ?></span>
            <?php endif; ?>
        </a>

        <a style="display:none;" class="pt-nav-chip<?php echo _nav_chip_cls('documents', $_nav_active); ?>"
           href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/patient/onsitedocuments?pid=<?php echo (int)$_nav_pid; ?>">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
            </svg>
            Documents
        </a>

        <?php if (!empty($GLOBALS['allow_portal_chat'])): ?>
        <a class="pt-nav-chip<?php echo _nav_chip_cls('chat', $_nav_active); ?>"
           href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/messaging/secure_chat.php">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
            </svg>
            Chat
        </a>
        <?php endif; ?>

        <?php if (!empty($GLOBALS['portal_two_ledger']) || !empty($GLOBALS['portal_two_payments'])): ?>
        <a class="pt-nav-chip<?php echo _nav_chip_cls('billing', $_nav_active); ?>"
           href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/portal_payment.php">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="4" width="22" height="16" rx="2"/>
                <line x1="1" y1="10" x2="23" y2="10"/>
            </svg>
            Billing
        </a>
        <?php endif; ?>

    </div><!-- /.pt-nav-chips -->

    <!-- Right: message badge + avatar dropdown -->
    <div class="pt-nav-right">

        <a style="display:none;" href="<?php echo htmlspecialchars($_nav_messagesURL); ?>"
           class="pt-nav-badge-btn" title="Messages">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                <polyline points="22,6 12,13 2,6"/>
            </svg>
            <?php if ($_nav_newcnt > 0): ?>
            <span class="pt-badge"><?php echo (int)$_nav_newcnt; ?></span>
            <?php endif; ?>
        </a>

        <div class="pt-dd-wrap">
            <button class="pt-avatar" id="pt-sub-avatar-btn"
                    onclick="ptSubNavToggle()" title="<?php echo $_nav_fullname ?: 'Account'; ?>">
                <?php echo htmlspecialchars($_nav_initials); ?>
            </button>

            <div class="pt-dd-menu" id="pt-sub-dd">
                <div class="pt-dd-header">
                    <div class="pt-dd-header-name"><?php echo $_nav_fullname; ?></div>
                    <div class="pt-dd-header-sub"><?php echo htmlspecialchars($_nav_username); ?></div>
                </div>

                <!-- My Profile -->
                <a class="pt-dd-item"
                   href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/patient/patientdata?pid=<?php echo (int)$_nav_pid; ?>">
                    <span class="pt-dd-icon" style="background:var(--violet-d);color:var(--violet);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="8" r="4"/>
                            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                        </svg>
                    </span>
                    My Profile
                </a>

                <!-- My Messages -->
                <a class="pt-dd-item" href="<?php echo htmlspecialchars($_nav_messagesURL); ?>">
                    <span class="pt-dd-icon" style="background:var(--blue-d);color:var(--blue);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>
                    </span>
                    My Messages
                    <?php if ($_nav_newcnt > 0): ?>
                    <span style="margin-left:auto;background:var(--rose);color:#fff;border-radius:10px;padding:1px 7px;font-size:10px;font-weight:700;"><?php echo (int)$_nav_newcnt; ?></span>
                    <?php endif; ?>
                </a>

                <!-- My Documents -->
                <a class="pt-dd-item"
                   href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/patient/onsitedocuments?pid=<?php echo (int)$_nav_pid; ?>">
                    <span class="pt-dd-icon" style="background:var(--amber-d);color:var(--amber);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                    </span>
                    My Documents
                </a>

                <?php if (!empty($GLOBALS['allow_portal_appointments'])): ?>
                <!-- My Appointments -->
                <a class="pt-dd-item"
                   href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/home.php#appointmentcard">
                    <span class="pt-dd-icon" style="background:var(--blue-d);color:var(--blue);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                    </span>
                    My Appointments
                </a>
                <?php endif; ?>

                <div class="pt-dd-divider"></div>

                <!-- Change Credentials -->
                <a class="pt-dd-item" href="/portal/account/index_reset.php">
                    <span class="pt-dd-icon" style="background:var(--ic-cred);color:var(--ic-cred-fg);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>
                        </svg>
                    </span>
                    Change Credentials
                </a>

                <!-- Logout -->
                <a class="pt-dd-item danger"
                   href="<?php echo htmlspecialchars($_nav_webroot); ?>/portal/logout.php">
                    <span class="pt-dd-icon" style="background:var(--rose-d);color:var(--rose);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/>
                            <polyline points="16 17 21 12 16 7"/>
                            <line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </span>
                    Logout
                </a>

            </div><!-- /.pt-dd-menu -->
        </div><!-- /.pt-dd-wrap -->
    </div><!-- /.pt-nav-right -->

</nav>
<!-- ══ END PORTAL SUB-NAV ══════════════════════════════════════════════ -->

<script>
/* Dropdown toggle for sub-page nav */
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
</script>
