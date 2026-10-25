<?php
/**
 * mdi_encounter_balances.inc.php
 *
 * Drop in:  interface/patient_file/mdi_encounter_balances.inc.php
 *
 * Shared by:
 *   mdi_payments_ledger.php  (ledger, ins-payment, pat-payment, auto-apply)
 *   demographics.php         (billing card / account summary)
 *
 * Require once at the top of each consuming file:
 *   require_once(__DIR__ . '/mdi_encounter_balances.inc.php');
 *
 * The guard below prevents fatal redeclaration if the file is included twice.
 */
if (function_exists('mdiGetEncounterBalances')) { return; }

/**
 * mdiGetEncounterBalances()
 *
 * Single source of truth for every balance figure shown in the ledger,
 * insurance-payments, and patient-payments pages.
 *
 * All three pages previously ran their own independent queries and walking
 * logic, which caused the "–$25 credit showing as $15 balance" class of
 * bugs.  Now they all call this one function and read the keys they need.
 *
 * ── Accounting model ────────────────────────────────────────────────────────
 *
 *   Raw balance  = SUM(billing.fee)
 *                - SUM(ar_activity.pay_amount)      [all payers]
 *                - SUM(ABS(ar_activity.adj_amount))  [all payers]
 *
 *   When raw_balance < 0 the encounter is in credit, NOT in debt.
 *   max(0, …) is only applied AFTER we know the sign.
 *
 *   Insurance balance walk  (mirrors pat_ledger.php exactly)
 *   ─────────────────────────────────────────────────────────
 *   ins_bal  starts = charges (if encounter has insurance) else 0
 *   pat_bal  starts = charges (if no insurance)           else 0
 *
 *   Each ar_activity row is walked in sequence_no order:
 *
 *     TRANS / PR       → shift money between ins_bal ↔ pat_bal, never touch enc_bal
 *     ac = 'WO'        → writeoff: reduce ins_bal first, spill to pat_bal if ins = 0
 *     payer_type = 1   → ins payment / ins adj: ins_bal -= (pay + adj)
 *     payer_type = 0   → patient payment / adj: pat_bal -= (pay + adj)
 *
 *   NOTE: writeoff detection uses ONLY account_code = 'WO'.  Memo text is
 *   never used — ERA contractual lines ("Adjust code 45", "Adjust code 253")
 *   contain the word "contract" and would be misclassified otherwise.
 *
 *   Final:
 *     if raw_balance <= 0: ins_bal = 0, pat_bal = 0   ← credit guard
 *     ins_balance = max(0, ins_bal)
 *     pat_balance = max(0, pat_bal)
 *     enc_balance = max(0, raw_balance)
 *
 *   Invariant enforced:  enc_credit > 0  ⟹  ins_balance = 0 AND pat_balance = 0
 *   enc_credit = max(0, -(raw_balance))     ← positive when overpaid
 *
 * ── Return value ────────────────────────────────────────────────────────────
 *
 * @return array {
 *   // ── Raw totals (straight sums, no clamping) ──────────────────────────
 *   charge         float   SUM(billing.fee)
 *   ins_paid       float   insurance pay_amount rows
 *   pat_paid       float   patient  pay_amount rows
 *   ins_adj        float   insurance adj rows (WO/AJ posted as ins)
 *   pat_adj        float   patient  adj rows  (WO/AJ posted as pat)
 *   total_paid     float   ins_paid + pat_paid
 *   total_adj      float   ins_adj  + pat_adj
 *
 *   // ── Derived balances (all non-negative; credit separated out) ────────
 *   raw_balance    float   charge - total_paid - total_adj  (may be negative)
 *   enc_credit     float   max(0,  -raw_balance)   → use for "Encounter Credit"
 *   enc_balance    float   max(0,   raw_balance)   → use for "Balance Due"
 *
 *   ins_balance    float   insurance share still owed  (≥ 0)
 *   pat_balance    float   patient   share still owed  (≥ 0)
 *   pat_credit     float   alias of enc_credit — the single source of truth
 *                          for credits regardless of which bucket they live in
 * }
 *
 * @param int  $pid        patient id
 * @param int  $encounter  encounter number
 * @param bool $has_ins    true when patient has active primary insurance
 *                         (pass the $_has_ins flag you already computed, or
 *                          set null to let the function look it up itself)
 */
function mdiGetEncounterBalances(int $pid, int $encounter, ?bool $has_ins = null): array
{
    // ── 1. Charges ────────────────────────────────────────────────────────
    $charge = (float) sqlQuery(
        "SELECT COALESCE(SUM(fee), 0) AS amt
           FROM billing
          WHERE pid = ? AND encounter = ? AND activity = 1",
        [$pid, $encounter]
    )['amt'];

    // ── 2. Insurance coverage flag ────────────────────────────────────────
    if ($has_ins === null) {
        $ins_chk = sqlQuery(
            "SELECT id FROM insurance_data
              WHERE pid = ? AND type = 'primary'
                AND (date IS NULL OR date <= CURDATE())
              ORDER BY date DESC LIMIT 1",
            [$pid]
        );
        $has_ins = !empty($ins_chk['id']);
    }

    // Does this specific encounter have insurance billing lines?
    $enc_has_ins_billing = (bool) sqlQuery(
        "SELECT 1 FROM billing WHERE pid = ? AND encounter = ? AND activity = 1 AND payer_id > 0 LIMIT 1",
        [$pid, $encounter]
    );

    // ── 3. Walk ar_activity rows ──────────────────────────────────────────
    $acts = sqlStatement(
        "SELECT aa.pay_amount, aa.adj_amount, aa.memo, aa.account_code,
                aa.payer_type, aa.sequence_no,
                ars.payment_type, ars.payment_method, ars.payer_id,
                ars.adjustment_code
           FROM ar_activity aa
           LEFT JOIN ar_session ars ON ars.session_id = aa.session_id
          WHERE aa.pid = ? AND aa.encounter = ? AND aa.deleted IS NULL
          ORDER BY aa.sequence_no ASC",
        [$pid, $encounter]
    );

    // Running totals (raw, before clamping)
    $ins_paid = 0.0;
    $pat_paid = 0.0;
    $ins_adj  = 0.0;
    $pat_adj  = 0.0;

    // Balance-walk state
    // Detect legacy migrated balance
    $isLegacy = (bool) sqlQuery(
        "SELECT 1
        FROM billing
        WHERE pid = ?
            AND encounter = ?
            AND code = 'PATBAL'
            AND code_text = 'PREV-BAL'
        LIMIT 1",
        [$pid, $encounter]
    );
    if ($isLegacy) {
        // Legacy A/R is already patient responsibility
        $ins_bal = 0.0;
        $pat_bal = $charge;
    } else {
        $ins_bal = ($has_ins || $enc_has_ins_billing) ? $charge : 0.0;
        $pat_bal = ($has_ins || $enc_has_ins_billing) ? 0.0     : $charge;
    }

    while ($p = sqlFetchArray($acts)) {
        $rpmt     = (float)($p['pay_amount'] ?? 0);
        $radj_raw = (float)($p['adj_amount'] ?? 0);
        $radj     = abs($radj_raw);
        $ac       = strtoupper(trim($p['account_code'] ?? ''));
        $memo_lc  = strtolower(trim($p['memo'] ?? ''));
        $pmt_type = strtolower(trim($p['payment_type'] ?? ''));
        $p_method = strtolower(trim($p['payment_method'] ?? ''));
        $adj_code = strtolower(trim($p['adjustment_code'] ?? ''));

        // ── Detect row kind ───────────────────────────────────────────────

        // Credit application rows (pre_payment / applied credit) — they ARE
        // patient payments for balance purposes; account_code=PP.
        $is_credit =
            ($ac === 'PP' && $adj_code === 'pre_payment')
            || ($ac === 'PP' && stripos($p['memo'] ?? '', 'credit') !== false)
            || ($ac === 'PP' && $p_method === 'patient_payment' && $adj_code === 'insurance_payment');

        // Writeoffs — account_code is the ONLY reliable signal.
        // Do NOT sniff the memo: ERA contractual adjustment rows (e.g. "Adjust
        // code 45", "Adjust code 253") contain words like "contract" and would
        // be misclassified as writeoffs, corrupting ins_bal / pat_bal.
        // All other adjustments (AJ, CO, ERA lines, etc.) fall through to the
        // normal ins/patient adjustment path below.
        $is_writeoff = ($ac === 'WO');

        // Transfers (TRANS new-style; PR legacy OpenEMR)
        $is_pr    = ($ac === 'PR');
        $is_trans = ($ac === 'TRANS') || $is_pr;

        // Insurance vs patient payer
        // Explicit patient rows always go in patient bucket.
        if (
            intval($p['payer_type'] ?? 0) === 0
            || in_array($ac, ['PP', 'PCP', 'COPAY', 'PATIENT_PAYMENT'])
            || $is_credit
        ) {
            $is_ins = false;
        } else {
            $is_ins =
                strpos($pmt_type, 'insurance') !== false
                || !empty($p['payer_id'])
                || intval($p['payer_type'] ?? 0) === 1
                || in_array($ac, ['IPP', 'IA', 'INS1']);
        }
        // TRANS rows are not payments — never mark them as insurance payments
        if ($is_trans) {
            $is_ins = false;
        }

        // ── Balance walk ──────────────────────────────────────────────────

        if ($is_trans) {
            // ── TRANS (new-style) ─────────────────────────────────────────
            if ($is_pr) {
                // Legacy "PR 55.00" format
                $xfer_amt = 0.0;
                if (preg_match('/PR\s+([\d]+(?:\.[\d]{1,2})?)/', $p['memo'] ?? '', $m)) {
                    $xfer_amt = (float) $m[1];
                }
                if ($xfer_amt > 0) {
                    $ins_bal -= $xfer_amt;
                    $pat_bal += $xfer_amt;
                }
                continue;
            }

            // New-style TRANS: adj_amount holds value; fall back to pay_amount
            $xfer_amt = $radj > 0 ? $radj : abs($rpmt);
            if ($xfer_amt <= 0) {
                continue;
            }

            $src_payer = intval($p['payer_type'] ?? 0);
            if ($src_payer === 1) {
                // Insurance → Patient (or secondary/tertiary)
                $ins_bal -= $xfer_amt;
                if (
                    strpos($memo_lc, 'secondary') === false
                    && strpos($memo_lc, 'tertiary') === false
                ) {
                    $pat_bal += $xfer_amt;
                }
            } else {
                // Patient → Insurance
                $pat_bal -= $xfer_amt;
                $ins_bal += $xfer_amt;
            }
            continue;
        }

        if ($is_writeoff) {
            $wo_amt = $rpmt + $radj;
            if ($ins_bal > 0) {
                $ins_bal -= $wo_amt;
                $ins_adj += $wo_amt;
            } else {
                $pat_bal -= $wo_amt;
                $pat_adj += $wo_amt;
            }
            continue;
        }

        if ($is_ins) {
            $ins_paid += $rpmt;
            $ins_adj  += $radj;
            $ins_bal  -= ($rpmt + $radj);
        } else {
            // Patient payment (including credit applications)
            $pat_paid += $rpmt;
            $pat_adj  += $radj;
            $pat_bal  -= ($rpmt + $radj);
        }
    }

    // ── 4. Derive final balances ──────────────────────────────────────────
    $total_paid = $ins_paid + $pat_paid;
    $total_adj  = $ins_adj  + $pat_adj;

    // The true, un-clamped balance (negative = credit / overpayment)
    $raw_balance = round($charge - $total_paid - $total_adj, 2);

    // Encounter-level credit / balance
    $enc_credit  = round(max(0.0,  -$raw_balance), 2);
    $enc_balance = round(max(0.0,   $raw_balance), 2);

    // ── Credit guard ──────────────────────────────────────────────────────
    // When the encounter is fully paid or in credit (raw_balance ≤ 0) the
    // balance-walk buckets must both be zeroed before clamping.
    //
    // Without this, a walk inconsistency (e.g. insurance-side rows reduce
    // ins_bal to –25 while pat_bal still holds a leftover 15 from a transfer)
    // would let us return:
    //
    //   enc_balance = 0,  ins_balance = 0,  pat_balance = 15   ← wrong
    //
    // With the guard:
    //
    //   enc_balance = 0,  ins_balance = 0,  pat_balance = 0    ← correct
    //
    // Rule: enc_credit > 0  ⟹  ins_balance = 0 AND pat_balance = 0.
    // raw_balance is the single source of truth; the walk buckets are
    // discarded whenever they contradict it.
    if ($raw_balance <= 0) {
        $ins_bal = 0.0;
        $pat_bal = 0.0;
    }

    // Insurance and patient shares (balance walk, clamped to ≥ 0)
    $ins_balance = round(max(0.0, $ins_bal), 2);
    $pat_balance = round(max(0.0, $pat_bal), 2);

    // Patient credit = encounter credit — do NOT re-derive from a second formula.
    // Credits can originate from patient payments, insurance overpayments, or
    // transfer combinations (Kathy's case).  raw_balance already captures all of
    // them; a separate pat_raw calculation would diverge and produce inconsistent
    // numbers across the ledger, patient, and credits screens.
    $pat_credit = $enc_credit;

    return [
        // ── Raw totals ────────────────────────────────────────────────────
        'charge'      => round($charge,    2),
        'ins_paid'    => round($ins_paid,  2),
        'pat_paid'    => round($pat_paid,  2),
        'ins_adj'     => round($ins_adj,   2),
        'pat_adj'     => round($pat_adj,   2),
        'total_paid'  => round($total_paid, 2),
        'total_adj'   => round($total_adj,  2),

        // ── Derived balances ──────────────────────────────────────────────
        'raw_balance' => $raw_balance,   // may be negative
        'enc_credit'  => $enc_credit,    // > 0 means encounter is overpaid
        'enc_balance' => $enc_balance,   // > 0 means money still owed

        'ins_balance' => $ins_balance,   // insurance share still owed
        'pat_balance' => $pat_balance,   // patient   share still owed
        'pat_credit'  => $pat_credit,    // patient overpaid (after ins + adj)
    ];
}


// ════════════════════════════════════════════════════════════════════════════
// Per-screen consumption map
// Every screen that currently calculates its own balance should be replaced
// with a single call to this function.  Read only the keys you need.
// ════════════════════════════════════════════════════════════════════════════
//
// ── LEDGER ────────────────────────────────────────────────────────────────────
//   Keys:  enc_balance, ins_balance, pat_balance
//
//   $b = mdiGetEncounterBalances($pid, $enc['encounter'], $_has_ins);
//
//   $ledger_rows[] = [
//       'enc'         => $enc,
//       'charges'     => $charge_rows,
//       'pmts'        => $pmt_rows,
//       'activity'    => $activity_rows,
//       'enc_chg'     => $b['charge'],
//       'enc_pmt'     => $b['total_paid'],
//       'enc_adj'     => $b['total_adj'],
//       'enc_bal'     => $b['enc_balance'],   // ← "Balance" column
//       'enc_ins_bal' => $b['ins_balance'],   // ← insurance share
//       'enc_pat_bal' => $b['pat_balance'],   // ← patient share
//       'enc_ins_pmt' => $b['ins_paid'],
//       'enc_ins_adj' => $b['ins_adj'],
//       'enc_pat_adj' => $b['pat_adj'],
//       'enc_pat_pmt' => $b['pat_paid'],
//   ];
//
//   // Page-level totals
//   $total_chg     += $b['charge'];
//   $total_pmt     += $b['total_paid'];
//   $total_adj     += $b['total_adj'];
//   $total_ins_bal += $b['ins_balance'];
//   $total_pat_bal += $b['pat_balance'];
//   $total_bal     += $b['enc_balance'];
//   $total_ins_pmt += $b['ins_paid'];
//   $total_pat_pmt += $b['pat_paid'];
//
//
// ── INSURANCE PAYMENT SCREEN ─────────────────────────────────────────────────
//   Key:   ins_balance
//
//   $b = mdiGetEncounterBalances($pid, $enc_no);
//   echo mdi_money($b['ins_balance']);   // balance column — what insurance owes
//
//
// ── PATIENT PAYMENT SCREEN ───────────────────────────────────────────────────
//   Key:   pat_balance
//
//   $b = mdiGetEncounterBalances($pid, $enc_no);
//   echo mdi_money($b['pat_balance']);   // what to collect from patient
//   // JS data attribute:
//   // <input ... data-pt-due="<?= $b['pat_balance'] ?>">
//
//
// ── CREDITS SCREEN ───────────────────────────────────────────────────────────
//   Key:   enc_credit  (= pat_credit, they are the same value)
//
//   $b = mdiGetEncounterBalances($pid, $enc_no);
//   if ($b['enc_credit'] > 0) {
//       echo 'Encounter Credit: ' . mdi_money($b['enc_credit']);
//   }
//
//
// ── DASHBOARD / ACCOUNT SUMMARY ──────────────────────────────────────────────
//   Aggregate across all open encounters:
//
//   $patient_responsibility = 0.0;  // SUM(enc_balance)
//   $patient_credit         = 0.0;  // SUM(enc_credit)
//
//   foreach ($encounters as $enc_no) {
//       $b = mdiGetEncounterBalances($pid, $enc_no);
//       $patient_responsibility += $b['enc_balance'];
//       $patient_credit         += $b['enc_credit'];
//   }
//
//   $total_account_balance = $patient_responsibility - $patient_credit;
//   //                        ↑ what's owed            ↑ what's already overpaid
//
//
// ── mdiGetEncounterBalance() shim (drop-in backward compat) ──────────────────
//
//   function mdiGetEncounterBalance(int $pid, int $encounter): float {
//       return mdiGetEncounterBalances($pid, $encounter)['raw_balance'];
//   }
//
//
// ── Encounter 2455 worked example ────────────────────────────────────────────
//
//   Charge          55.00
//   Patient Pay     40.00
//   Insurance Pay   21.71
//   Adjustment      18.29
//   ─────────────────────
//   raw_balance   = 55.00 - 40.00 - 21.71 - 18.29 = -25.00  ← credit
//   enc_credit    =  25.00   ✓  ("Encounter Credit = $25.00")
//   enc_balance   =   0.00   ✓  (nothing still owed)
//
//   Credit guard fires (raw_balance ≤ 0):
//     ins_bal and pat_bal are zeroed before clamping
//   ins_balance   =   0.00   ✓  (not $15 — the old broken result)
//   pat_balance   =   0.00   ✓
//   pat_credit    =  25.00   ✓  (alias of enc_credit — same number everywhere)
//
//   Old (broken) code clamped the walk buckets independently, letting a
//   walk-residual $15 in pat_bal survive as pat_balance = $15 even though
//   raw_balance was already −$25.  The credit guard closes that gap.
