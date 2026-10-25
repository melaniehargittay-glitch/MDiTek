<?php
/**
 * MDItek Checkout Receipt
 * File: interface/patient_file/summary/mdi_checkout_receipt.php
 */
// Two levels up from summary/ reaches interface/globals.php
require_once("../../globals.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once(__DIR__ . "/../mdi_encounter_balances.inc.php"); // single source of truth

if (empty($_SESSION['authUser'])) { http_response_code(403); die('Session expired.'); }

// Money formatter — defined before try so it works in HTML template too
function rcpt_money(float $v): string {
    return '$' . number_format(abs($v), 2);
}

try {

// ── Security / input ──────────────────────────────────────────────────────
$pid = (int)($_GET['pid'] ?? 0);
if (!$pid) { die('No patient specified.'); }

// ── Patient info ──────────────────────────────────────────────────────────
$pat = sqlQuery(
    "SELECT fname, lname, DOB, phone_cell, phone_home, email FROM patient_data WHERE pid=?",
    [$pid]
);
$pat_name  = strtoupper(trim(($pat['fname'] ?? '') . ' ' . ($pat['lname'] ?? '')));
$pat_email = trim($pat['email'] ?? '');
$pat_dob   = !empty($pat['DOB'])
    ? date('m/d/Y', strtotime($pat['DOB'])) . ' (' . (new DateTime($pat['DOB']))->diff(new DateTime())->y . ')'
    : '—';
$pat_phone = $pat['phone_cell'] ?: ($pat['phone_home'] ?? '');
if ($pat_phone) {
    $p = preg_replace('/\D/', '', $pat_phone);
    if (strlen($p) === 10) $pat_phone = substr($p,0,3).'-'.substr($p,3,3).'-'.substr($p,6);
}

// ── Practice info — from facility table ───────────────────────────────────
$fac = sqlQuery("SELECT name, street, city, state, postal_code, phone FROM facility WHERE primary_business_entity=1 LIMIT 1", []);
if (empty($fac)) {
    $fac = sqlQuery("SELECT name, street, city, state, postal_code, phone FROM facility ORDER BY id LIMIT 1", []);
}
$practice_name  = !empty($fac['name'])  ? $fac['name']  : ($GLOBALS['openemr_name'] ?? 'Garza Chiropractic');
$practice_addr  = !empty($fac['street'])? $fac['street']: ($GLOBALS['practice_street'] ?? '145 N. Main Street');
$practice_city  = !empty($fac['city'])
    ? trim($fac['city'] . ', ' . ($fac['state'] ?? '') . ' ' . ($fac['postal_code'] ?? ''))
    : trim(($GLOBALS['practice_city'] ?? 'Adrian') . ', ' . ($GLOBALS['practice_state'] ?? 'MI') . ' ' . ($GLOBALS['practice_zip'] ?? '49221'));
$practice_phone = !empty($fac['phone']) ? $fac['phone'] : ($GLOBALS['practice_phone'] ?? '517-266-0460');

// ── Most recent encounter ─────────────────────────────────────────────────
$enc_row = sqlQuery(
    "SELECT fe.encounter, fe.date, CONCAT(u.fname,' ',u.lname) AS provider_name
     FROM form_encounter fe
     LEFT JOIN users u ON u.id = fe.provider_id
     WHERE fe.pid=?
     ORDER BY fe.date DESC, fe.encounter DESC LIMIT 1",
    [$pid]
);
$enc_id       = (int)($enc_row['encounter'] ?? 0);
$visit_date   = !empty($enc_row['date']) ? date('F j, Y', strtotime($enc_row['date'])) : date('F j, Y');
$provider = trim($enc_row['provider_name'] ?? '');
if ($provider) { $parts = explode(' ', $provider); $provider = 'Dr. ' . end($parts); }

// ── This visit: charges ───────────────────────────────────────────────────
$visit_chg = 0;
if ($enc_id) {
    $chg_row = sqlQuery(
        "SELECT COALESCE(SUM(b.fee),0) AS chg FROM billing b
         WHERE b.pid=? AND b.encounter=? AND b.activity>0",
        [$pid, $enc_id]
    );
    $visit_chg = (float)($chg_row['chg'] ?? 0);
}

// ── Today's patient payments for this encounter ───────────────────────────
$today_pmt     = 0;
$today_method  = '';
$today_ref     = '';
$today_pmt_res = sqlStatement(
    "SELECT aa.pay_amount, ars.payment_method, ars.reference, ars.payer_id
     FROM ar_activity aa
     JOIN ar_session ars ON ars.session_id = aa.session_id
     WHERE aa.pid=? AND aa.encounter=?
       AND aa.deleted IS NULL
       AND ars.payer_id IS NULL
       AND DATE(ars.post_to_date) = CURDATE()
     ORDER BY aa.sequence_no",
    [$pid, $enc_id]
);
while ($tp = sqlFetchArray($today_pmt_res)) {
    $today_pmt += (float)($tp['pay_amount'] ?? 0);
    if (!$today_method) {
        $m = List_Look($tp['payment_method'] ?? '', 'payment_method');
        $today_method = $m ?: 'Payment';
        $today_ref    = $tp['reference'] ?? '';
    }
}
$visit_remaining = $visit_chg - $today_pmt;

// ── Insurance check ───────────────────────────────────────────────────────
$has_insurance = false;
$ins_check = sqlQuery(
    "SELECT id FROM insurance_data WHERE pid=? AND type='primary'
     AND (date IS NULL OR date <= CURDATE()) ORDER BY date DESC LIMIT 1",
    [$pid]
);
$has_insurance = !empty($ins_check['id']);

// ── All-time account balances — mdiGetEncounterBalances() is the single source
// of truth. This matches demographics.php exactly so the receipt always shows
// the same numbers as the billing card. ──────────────────────────────────────
$acct_chg     = 0.0;
$acct_ins_pmt = 0.0;
$acct_pat_pmt = 0.0;
$acct_ins_adj = 0.0;
$insurancebalance = 0.0;
$patientbalance   = 0.0;

$enc_res = sqlStatement(
    "SELECT fe.encounter FROM form_encounter fe WHERE fe.pid=? ORDER BY fe.date",
    [$pid]
);
while ($enc = sqlFetchArray($enc_res)) {
    $b = mdiGetEncounterBalances($pid, intval($enc['encounter']), $has_insurance);
    $acct_chg     += $b['charge'];
    $acct_ins_pmt += $b['ins_paid'];
    $acct_pat_pmt += $b['pat_paid'];
    $acct_ins_adj += $b['ins_adj'] + $b['pat_adj'];
    $insurancebalance += $b['ins_balance'];
    $patientbalance   += $b['pat_balance'];
}

// Real cash credit on account — same query as demographics.php
$_credit_row = sqlQuery(
    "SELECT COALESCE(SUM(s.pay_total - COALESCE(a_used.used,0) - s.global_amount),0) AS avail
       FROM ar_session s
       LEFT JOIN (SELECT session_id, SUM(pay_amount) AS used
                    FROM ar_activity WHERE deleted IS NULL GROUP BY session_id
                 ) a_used ON a_used.session_id = s.session_id
      WHERE s.patient_id = ? AND s.pay_total > 0
        AND LOWER(COALESCE(s.payment_method,'')) IN
            ('patient_payment','cash','check','credit_card','eft','ach')",
    [$pid]
);
$unallocated_amt = round((float)($_credit_row['avail'] ?? 0), 2);

// ── Derived figures (identical to demographics.php) ───────────────────────
// Patient owes today = responsibility minus real cash credit
$patient_owes_today = max(0.0, round($patientbalance - $unallocated_amt, 2));
// Net account position = ins + patient share - cash credit
$net_account_pos    = round($insurancebalance + $patientbalance - $unallocated_amt, 2);

// ── Receipt number ────────────────────────────────────────────────────────
$receipt_no = 'R-' . date('Y-md') . '-' . str_pad($pid, 4, '0', STR_PAD_LEFT);

} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre style="padding:20px;font-family:monospace;color:#b91c1c;background:#fff;">'
        . 'Receipt error: ' . htmlspecialchars($e->getMessage()) . "\n"
        . 'File: '          . htmlspecialchars($e->getFile())    . ' line ' . $e->getLine()
        . '</pre>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout Receipt</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Inter', system-ui, sans-serif;
    background: #f5f5f5;
    color: #1a1a2e;
    font-size: 14px;
    line-height: 1.5;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .page {
    max-width: 780px;
    margin: 0 auto;
    background: #fff;
    min-height: 100vh;
    padding: 32px 36px 24px;
  }

  /* ── Header ── */
  .rcpt-header {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    align-items: start;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 2px solid #5c2d91;
  }
  .rcpt-title {
    font-size: 26px;
    font-weight: 800;
    color: #1a1a2e;
    letter-spacing: -0.5px;
  }
  .rcpt-practice {
    text-align: center;
  }
  .rcpt-practice-name {
    font-size: 15px;
    font-weight: 700;
    color: #5c2d91;
    margin-bottom: 3px;
  }
  .rcpt-practice-detail {
    font-size: 12px;
    color: #555;
    line-height: 1.6;
  }
  .rcpt-meta {
    text-align: right;
    font-size: 12px;
    color: #555;
    line-height: 1.7;
  }

  /* ── Grid sections ── */
  .rcpt-grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
  }

  .rcpt-card {
    border: 1.5px solid #e5e7eb;
    border-radius: 10px;
    padding: 18px 20px;
  }

  .rcpt-card-title {
    font-size: 13px;
    font-weight: 700;
    color: #5c2d91;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .rcpt-card-title svg { flex-shrink: 0; }

  .rcpt-patient-name {
    font-size: 18px;
    font-weight: 800;
    color: #1a1a2e;
    margin-bottom: 6px;
  }
  .rcpt-patient-detail {
    font-size: 12px;
    color: #555;
    line-height: 1.7;
    display: flex;
    gap: 24px;
  }
  .rcpt-phone {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: #555;
    margin-top: 4px;
  }

  .rcpt-info-row {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    padding: 5px 0;
    color: #333;
  }
  .rcpt-info-row + .rcpt-info-row {
    border-top: 1px solid #f3f4f6;
  }
  .rcpt-info-val { font-weight: 500; color: #1a1a2e; }

  /* ── This Visit card ── */
  .visit-line {
    display: flex;
    justify-content: space-between;
    font-size: 13px;
    padding: 5px 0;
  }
  .visit-line.payment { color: #16a34a; font-weight: 600; }
  .visit-divider { border: none; border-top: 1.5px solid #e5e7eb; margin: 10px 0; }
  .visit-total {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding-top: 6px;
  }
  .visit-total-lbl { font-size: 13px; font-weight: 700; color: #5c2d91; }
  .visit-total-lbl span { font-weight: 400; color: #777; font-size: 12px; }
  .visit-total-val { font-size: 22px; font-weight: 800; color: #1a1a2e; }

  /* ── Payment Collected card ── */
  .thank-you {
    margin-top: 14px;
    background: #f5f0ff;
    border-radius: 8px;
    padding: 12px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
  }
  .thank-you svg { flex-shrink: 0; margin-top: 1px; }
  .thank-you-text { font-size: 13px; }
  .thank-you-text strong { display: block; color: #5c2d91; font-weight: 700; margin-bottom: 2px; }
  .thank-you-text span { color: #555; }

  /* ── Full responsibility section ── */
  .rcpt-section {
    border: 1.5px solid #e5e7eb;
    border-radius: 10px;
    padding: 20px 22px;
    margin-bottom: 16px;
  }
  .rcpt-section-title {
    font-size: 14px;
    font-weight: 700;
    color: #5c2d91;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .rcpt-section-title span { color: #777; font-weight: 400; font-size: 13px; }

  .resp-line {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 6px 0;
    font-size: 13px;
    color: #333;
    border-top: 1px solid #f3f4f6;
  }
  .resp-line:first-of-type { border-top: none; }
  .resp-line.deduct { color: #16a34a; font-weight: 600; }
  .resp-line.credit { color: #16a34a; font-weight: 600; }

  .resp-total-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    padding-top: 12px;
    margin-top: 6px;
    border-top: 2px solid #1a1a2e;
  }
  .resp-total-lbl { font-size: 14px; font-weight: 700; color: #1a1a2e; }
  .resp-total-lbl span { display: block; font-size: 11px; font-weight: 400; color: #777; margin-top: 1px; }
  .resp-total-val { font-size: 26px; font-weight: 800; color: #dc2626; }

  .rcpt-note {
    background: #f8f9ff;
    border: 1px solid #e0e7ff;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px;
    color: #555;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-top: 12px;
  }
  .rcpt-note svg { flex-shrink: 0; margin-top: 1px; }

  /* ── Insurance notice ── */
  .ins-notice {
    background: #f8f9ff;
    border: 1px solid #e0e7ff;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 12px;
    color: #555;
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 20px;
  }

  /* ── Actions ── */
  .rcpt-actions {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 16px;
  }
  .rcpt-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    padding: 11px 16px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
  }
  .rcpt-btn-primary { background: #5c2d91; color: #fff; }
  .rcpt-btn-primary:hover { background: #4a2278; }
  .rcpt-btn-outline { background: #fff; color: #333; border: 1.5px solid #d1d5db; }
  .rcpt-btn-outline:hover { background: #f9fafb; }

  .rcpt-footer-note {
    text-align: center;
    font-size: 11px;
    color: #9ca3af;
    padding-top: 10px;
    border-top: 1px solid #f3f4f6;
  }

  @media print {
    body { background: #fff; }
    .page { padding: 16px; max-width: 100%; }
    .rcpt-actions { display: none; }
  }
</style>
</head>
<body>
<div class="page">

  <!-- Header -->
  <div class="rcpt-header">
    <div class="rcpt-title">Checkout Receipt</div>
    <div class="rcpt-practice">
      <div class="rcpt-practice-name"><?php echo text($practice_name); ?></div>
      <div class="rcpt-practice-detail">
        <?php echo text($practice_addr); ?><br>
        <?php echo text($practice_city); ?><br>
        <?php echo text($practice_phone); ?>
      </div>
    </div>
    <div class="rcpt-meta">
      Receipt #: <?php echo text($receipt_no); ?><br>
      <?php echo date('F j, Y'); ?> &nbsp;|&nbsp; <?php echo date('g:i A'); ?>
    </div>
  </div>

  <!-- Patient + Visit Info -->
  <div class="rcpt-grid-2">
    <div class="rcpt-card">
      <div class="rcpt-card-title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Patient Information
      </div>
      <div class="rcpt-patient-name"><?php echo text($pat_name); ?></div>
      <div class="rcpt-patient-detail">
        <span>DOB: <?php echo text($pat_dob); ?></span>
        <span>Patient ID: <?php echo text($pid); ?></span>
      </div>
      <?php if ($pat_phone): ?>
      <div class="rcpt-phone">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#555" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81a19.79 19.79 0 01-3.07-8.63A2 2 0 012 1h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L6.09 8.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0122 16.92z"/></svg>
        <?php echo text($pat_phone); ?>
      </div>
      <?php endif; ?>
    </div>

    <div class="rcpt-card">
      <div class="rcpt-card-title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        Visit Information
      </div>
      <div class="rcpt-info-row">
        <span>Visit Date</span>
        <span class="rcpt-info-val"><?php echo text($visit_date); ?></span>
      </div>
      <?php if ($provider): ?>
      <div class="rcpt-info-row">
        <span>Provider</span>
        <span class="rcpt-info-val"><?php echo text($provider); ?></span>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- This Visit + Payment Collected -->
  <div class="rcpt-grid-2">
    <div class="rcpt-card">
      <div class="rcpt-card-title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        This Visit (<?php echo text($visit_date); ?>)
      </div>
      <div class="visit-line">
        <span>Latest Charges (This Visit)</span>
        <strong><?php echo text(rcpt_money($visit_chg)); ?></strong>
      </div>
      <?php if ($today_pmt > 0): ?>
      <div class="visit-line payment">
        <span>Less: Patient Payments Collected Today</span>
        <span>– <?php echo text(rcpt_money($today_pmt)); ?></span>
      </div>
      <?php endif; ?>
      <hr class="visit-divider">
      <div class="visit-total">
        <div class="visit-total-lbl">
          Remaining Balance Due <span>(This Visit)</span>
        </div>
        <div class="visit-total-val"><?php echo text(rcpt_money($visit_remaining)); ?></div>
      </div>
    </div>

    <div class="rcpt-card">
      <div class="rcpt-card-title">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
        Payment Collected Today
      </div>
      <?php if ($today_pmt > 0): ?>
      <div class="rcpt-info-row">
        <span>Amount Paid</span>
        <span class="rcpt-info-val"><?php echo text(rcpt_money($today_pmt)); ?></span>
      </div>
      <?php if ($today_method): ?>
      <div class="rcpt-info-row">
        <span>Payment Method</span>
        <span class="rcpt-info-val"><?php echo text($today_method); ?></span>
      </div>
      <?php endif; ?>
      <?php if ($today_ref): ?>
      <div class="rcpt-info-row">
        <span>Reference / Auth #</span>
        <span class="rcpt-info-val"><?php echo text($today_ref); ?></span>
      </div>
      <?php endif; ?>
      <div class="thank-you">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#5c2d91" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        <div class="thank-you-text">
          <strong>Thank you for your payment!</strong>
          <span>We appreciate your trust in our care.</span>
        </div>
      </div>
      <?php else: ?>
      <div style="font-size:13px;color:#777;padding:8px 0;">No payment collected today for this visit.</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Full Patient Responsibility -->
  <div class="rcpt-section">
    <div class="rcpt-section-title">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="#5c2d91" stroke="none"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 14.5v-9l6 4.5-6 4.5z"/></svg>
      Your Full Patient Responsibility <span>(All Encounters)</span>
    </div>

    <div class="resp-line">
      <span>Total Charges (All Time)</span>
      <strong><?php echo text(rcpt_money($acct_chg)); ?></strong>
    </div>
    <?php if ($acct_ins_pmt > 0): ?>
    <div class="resp-line deduct">
      <span>Less: Insurance Paid (All Time)</span>
      <span>– <?php echo text(rcpt_money($acct_ins_pmt)); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($acct_ins_adj > 0): ?>
    <div class="resp-line deduct">
      <span>Less: Insurance Adjustments (All Time)</span>
      <span>– <?php echo text(rcpt_money($acct_ins_adj)); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($acct_pat_pmt > 0): ?>
    <div class="resp-line deduct">
      <span>Less: Patient Payments (All Time)</span>
      <span>– <?php echo text(rcpt_money($acct_pat_pmt)); ?></span>
    </div>
    <?php endif; ?>
    <?php if ($unallocated_amt > 0): ?>
    <div class="resp-line deduct">
      <span>Less: Patient Credit on Account</span>
      <span>– <?php echo text(rcpt_money($unallocated_amt)); ?></span>
    </div>
    <?php endif; ?>

    <div class="resp-total-row">
      <div class="resp-total-lbl">
        Patient Owes Today <span>(After Credits)</span>
        <span style="font-size:11px;color:#888;">(Does not include insurance-pending amounts)</span>
      </div>
      <div class="resp-total-val <?php echo $patient_owes_today > 0 ? 'balance' : ''; ?>"><?php echo text(rcpt_money($patient_owes_today)); ?></div>
    </div>

    <?php if ($insurancebalance > 0): ?>
    <div class="resp-line" style="margin-top:10px;padding-top:10px;border-top:1px solid #e5e7eb;">
      <span style="color:#6b7280;">Insurance Still Pending</span>
      <span style="color:#d63031;font-weight:600;"><?php echo text(rcpt_money($insurancebalance)); ?></span>
    </div>
    <div class="resp-line">
      <span style="color:#6b7280;font-weight:700;">Net Account Position</span>
      <span style="font-weight:700;"><?php echo text(rcpt_money($net_account_pos)); ?></span>
    </div>
    <?php endif; ?>

    <div class="rcpt-note">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Patient Owes Today = Patient Responsibility minus available credits. Insurance-pending balances are shown separately above.
    </div>
  </div>

  <!-- Insurance notice (only if patient has insurance) -->
  <?php if ($has_insurance): ?>
  <div class="ins-notice">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    Your insurance will be billed for this visit. Any remaining balance after insurance processing will be your responsibility.
  </div>
  <?php endif; ?>

  <!-- Actions -->
  <div class="rcpt-actions">
    <button class="rcpt-btn rcpt-btn-primary" onclick="window.print()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Print Receipt
    </button>
    <button class="rcpt-btn rcpt-btn-outline" onclick="sendEmail()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      Email Receipt
    </button>
    <button class="rcpt-btn rcpt-btn-outline" onclick="window.print()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Download PDF
    </button>
  </div>

  <div class="rcpt-footer-note">
    For questions about your account, please contact our office at <?php echo text($practice_phone); ?>.
  </div>

</div>
<script>
function sendEmail() {
    var to      = <?php echo json_encode($pat_email); ?>;
    var subject = <?php echo json_encode('Your Receipt from ' . $practice_name); ?>;
    var patName = <?php echo json_encode(ucwords(strtolower(($pat['fname'] ?? '') . ' ' . ($pat['lname'] ?? '')))); ?>;
    var body    = 'Dear ' + patName + ',\n\n'
        + 'Thank you for your visit on <?php echo addslashes($visit_date); ?>.\n\n'
        + 'RECEIPT SUMMARY\n'
        + '---------------\n'
        + 'Charges This Visit:    <?php echo rcpt_money($visit_chg); ?>\n'
        + <?php echo $today_pmt > 0 ? "'Payment Today:         -" . rcpt_money($today_pmt) . "\\n'" : "''"; ?>
        + 'Remaining (This Visit):<?php echo rcpt_money($visit_remaining); ?>\n\n'
        + 'Patient Owes Today:    <?php echo rcpt_money($patient_owes_today); ?>\n\n'
        + 'Questions? Call us at <?php echo addslashes($practice_phone); ?>.\n\n'
        + 'Thank you,\n<?php echo addslashes($practice_name); ?>';

    window.location.href = 'mailto:' + encodeURIComponent(to)
        + '?subject=' + encodeURIComponent(subject)
        + '&body='    + encodeURIComponent(body);
}
</script>
</body>
</html>
