<?php
/**
 * Legacy EOB Posting — Historical insurance payments for encounters not yet in MdiTek.
 *
 * Supports:
 *   - Manual single-entry: patient + DOS + insurance + amounts
 *   - Batch CSV upload: one row per claim
 *   - ERA/835 upload: auto-parse BPR date, TRN check#, N1*PR payer
 *
 * If a form_encounter for the given pid+DOS does NOT exist, the user is prompted
 * to confirm creation of a minimal billing-only encounter (no SOAP, no provider,
 * no appointment linkage required).
 *
 * DB writes (on POST confirmation):
 *   form_encounter   — reason='Legacy EOB', encounter_type_code='legacy_eob'
 *   billing          — CPT4 code '00', fee = orig_charge
 *   ar_session       — one per posting batch (payer_type=1 insurance)
 *   ar_activity      — debit (adj_amount=charge), payment (pay_amount=ins_paid),
 *                      writeoff (adj_amount=contractual_adj), patient (pay_amount=pat_resp)
 *
 * @package   MdiTek
 * @link      http://www.mditekehr.com
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");

use OpenEMR\Billing\ParseERA;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

// ── ACL ────────────────────────────────────────────────────────────────────────
if (!AclMain::aclCheckCore('acct', 'bill', '', 'write') && !AclMain::aclCheckCore('acct', 'eob', '', 'write')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render(
        'core/unauthorized.html.twig',
        ['pageTitle' => xl("Legacy EOB Posting")]
    );
    exit;
}

// ── CSRF ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        // Return JSON for AJAX calls so fetch() doesn't crash
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'csrf', 'msg' => 'Session expired. Please reload the page.']);
            exit;
        }
        CsrfUtils::csrfNotVerified();
    }
}

// ── HELPERS ────────────────────────────────────────────────────────────────────

/**
 * Find pid + encounter for a given patient PID and date-of-service.
 * Returns ['pid'=>…, 'encounter'=>…] or null.
 */
function legacyFindEncounter(int $pid, string $dos): ?array
{
    $row = sqlQuery(
        "SELECT pid, encounter FROM form_encounter
          WHERE pid = ? AND DATE(date) = ?
          ORDER BY encounter DESC LIMIT 1",
        [$pid, $dos]
    );
    return $row ?: null;
}

/**
 * Create a minimal billing-only historical encounter.
 * No SOAP notes, no provider, no appointment linkage required.
 */
function legacyCreateEncounter(int $pid, string $dos, int $facilityId = 0): int
{
    $uuid = (new UuidRegistry(['table_name' => 'form_encounter']))->createUuid();
    // encounter placeholder=0; updated immediately after via LAST_INSERT_ID
    // shift, voucher_number included because they are NOT NULL with '' default
    $eid = sqlInsert(
        "INSERT INTO form_encounter
            (uuid, date, onset_date, reason, facility, facility_id, pid,
             encounter, pc_catid, last_level_billed, last_level_closed,
             last_stmt_date, stmt_count, provider_id, supervisor_id,
             billing_facility, invoice_refno, referral_source,
             pos_code, class_code, shift, voucher_number,
             encounter_type_code, encounter_type_description,
             in_collection, sensitivity)
         VALUES
            (?, ?, ?, 'Legacy EOB', '', ?, ?,
             0, 5, 0, -1,
             NULL, 0, 0, 0,
             ?, '', '',
             11, 'AMB', '', '',
             'legacy_eob', 'Legacy EOB — Billing Anchor Only',
             NULL, 'normal')",
        [
            $uuid,
            $dos . ' 00:00:00',
            $dos . ' 00:00:00',
            $facilityId,
            $pid,
            $facilityId,
        ]
    );
    // sqlInsert() returns the new auto-increment id in MdiTek
    $eid = (int)$eid;
    // Write encounter id back into its own row (MdiTek pattern)
    sqlStatement(
        "UPDATE form_encounter SET encounter = ? WHERE id = ?",
        [$eid, $eid]
    );
    return $eid;
}

/**
 * Create a billing row anchoring the charge.
 */
function legacyCreateBilling(int $pid, int $encounter, string $dos, float $fee, int $payerId, int $postUser): void
{
    sqlInsert(
        "INSERT INTO billing
            (date, code_type, code, pid, provider_id, `user`, groupname,
             authorized, encounter, code_text, billed, activity, payer_id,
             bill_process, modifier, units, fee, pricelevel,
             notecodes, revenue_code, chargecat)
         VALUES
            (?, 'CPT4', '00', ?, 0, ?, 'Default',
             1, ?, 'Legacy EOB Charge', 0, 1, ?,
             0, '', 1, ?, 'standard',
             '', '', '')",
        [$dos . ' 00:00:00', $pid, $postUser, $encounter, $payerId, $fee]
    );

  

}



/**
 * Create an ar_session and post ar_activity rows.
 * Returns the new session id.
 */
function legacyPostARSession(
    int    $pid,
    int    $encounter,
    string $dos,
    float  $origCharge,
    float  $insPaid,
    float  $contractAdj,
    float  $patResp,
    float  $otherAdj,
    int    $payerId,
    string $checkNum,
    string $payMethod,
    string $notes,
    int    $postUser
): int {
    // ar_session — one per posting
    // All NOT NULL / no-default columns included:
    //   reference, pay_total, payment_type, adjustment_code,
    //   post_to_date, patient_id, payment_method, modified_time
    // created_time has DEFAULT CURRENT_TIMESTAMP — omitted deliberately
    $sesId = sqlInsert(
        "INSERT INTO ar_session
            (payer_id, user_id, closed, reference,
             check_date, deposit_date, post_to_date,
             pay_total, modified_time, global_amount,
             payment_type, description,
             adjustment_code, patient_id, payment_method)
         VALUES
            (?, ?, 0, ?,
             ?, ?, ?,
             ?, NOW(), 0.00,
             'insurance', ?,
             '', 0, ?)",
        [
            $payerId,
            $postUser,
            $checkNum,
            $dos,   // check_date
            $dos,   // deposit_date
            $dos,   // post_to_date
            $insPaid,
            $notes ?: 'Legacy EOB from prior system',
            $payMethod ?: 'check',
        ]
    );

    // sequence tracking within this encounter
    $seqRow = sqlQuery(
      "SELECT COALESCE(MAX(sequence_no), 0) AS max_seq
         FROM ar_activity
        WHERE pid = ?
          AND encounter = ?",
      [$pid, $encounter]
  );
  
  $seq = ((int)$seqRow['max_seq']) + 1;


    // 1 — Insurance payment
    if ($insPaid > 0) {
        sqlInsert(
            "INSERT INTO ar_activity
                (pid, encounter, sequence_no, code_type, code, modifier,
                 payer_type, post_user, post_time, post_date, session_id,
                 pay_amount, adj_amount, memo, follow_up, account_code)
             VALUES
                (?, ?, ?, 'CPT4', '00', '',
                 1, ?, NOW(), ?, ?,
                 ?, 0.00, ?, '0', 'PCP')",
            [$pid, $encounter, $seq++, $postUser, $dos, $sesId, $insPaid,
             'Legacy Insurance Payment' . ($checkNum ? " — $checkNum" : '')]
        );
    }

    // 2 — Contractual adjustment / writeoff
    if ($contractAdj > 0) {
        sqlInsert(
            "INSERT INTO ar_activity
                (pid, encounter, sequence_no, code_type, code, modifier,
                 payer_type, post_user, post_time, post_date, session_id,
                 pay_amount, adj_amount, memo, follow_up, account_code)
             VALUES
                (?, ?, ?, 'CPT4', '00', '',
                 1, ?, NOW(), ?, ?,
                 0.00, ?, 'Contractual Writeoff', '0', 'WO')",
            [$pid, $encounter, $seq++, $postUser, $dos, $sesId, $contractAdj]
        );
    }

  

    // 3 — Other adjustment
    if ($otherAdj > 0) {
        sqlInsert(
            "INSERT INTO ar_activity
                (pid, encounter, sequence_no, code_type, code, modifier,
                 payer_type, post_user, post_time, post_date, session_id,
                 pay_amount, adj_amount, memo, follow_up, account_code)
             VALUES
                (?, ?, ?, 'CPT4', '00', '',
                 1, ?, NOW(), ?, ?,
                 0.00, ?, 'Other Adjustment', '0', 'OA')",
            [$pid, $encounter, $seq++, $postUser, $dos, $sesId, $otherAdj]
        );
    }

    return (int)$sesId;
}

// ── INSURANCE COMPANIES (for dropdowns) ───────────────────────────────────────
$insuranceList = sqlStatement(
    "SELECT id, name FROM insurance_companies ORDER BY name"
);
$insuranceOptions = [];
while ($row = sqlFetchArray($insuranceList)) {
    $insuranceOptions[] = $row;
}

// ── POST ACTION: CONFIRM & WRITE ───────────────────────────────────────────────
$postResult   = null; // success/error message
$confirmNeeded = false;
$confirmData   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // ── AJAX: check if DOS exists for a patient ────────────────────────────────
    if ($action === 'check_dos') {
        $pid = (int)($_POST['pid'] ?? 0);
        $dos = trim($_POST['dos'] ?? '');
        $enc = $pid && $dos ? legacyFindEncounter($pid, $dos) : null;
        header('Content-Type: application/json');
        echo json_encode([
            'found'     => $enc ? true : false,
            'encounter' => $enc ? (int)$enc['encounter'] : 0,
        ]);
        exit;
    }

    // ── AJAX: patient search ──────────────────────────────────────────────────
    if ($action === 'patient_search') {
        $term = '%' . add_escape_custom(trim($_POST['term'] ?? '')) . '%';
        $rows = [];
        $res  = sqlStatement(
            "SELECT pid, fname, lname, pubpid, DOB
               FROM patient_data
              WHERE (lname LIKE ? OR fname LIKE ? OR pubpid LIKE ?)
              ORDER BY lname, fname LIMIT 20",
            [$term, $term, $term]
        );
        while ($r = sqlFetchArray($res)) {
            $rows[] = [
                'pid'    => (int)$r['pid'],
                'pubpid' => $r['pubpid'],
                'name'   => strtoupper($r['lname']) . ', ' . $r['fname'],
                'dob'    => $r['DOB'],
                'label'  => strtoupper($r['lname']) . ', ' . $r['fname']
                            . ' (' . $r['pubpid'] . ')',
            ];
        }
        header('Content-Type: application/json');
        echo json_encode($rows);
        exit;
    }

    // ── AJAX: parse ERA file and return claims for review ────────────────────
    if ($action === 'parse_era_preview' && !empty($_FILES['era_preview_file']['tmp_name'])) {
        $eraText   = file_get_contents($_FILES['era_preview_file']['tmp_name']);
        $claims    = [];

        // BPR date
        $checkDate = date('Y-m-d');
        if (preg_match('/BPR[^~]*\*(\d{8})[*~]/', $eraText, $bm)) {
            $r = $bm[1];
            $checkDate = substr($r,0,4).'-'.substr($r,4,2).'-'.substr($r,6,2);
        }

        // Payer
        $payerName = '';
        if (preg_match('/N1\*PR\*([^*~\r\n]+)/', $eraText, $bm)) {
            $payerName = trim($bm[1]);
        }
        $checkNum = '';
        if (preg_match('/TRN\*1\*([^*~\r\n]+)/', $eraText, $bm)) {
            $checkNum = trim($bm[1]);
        }

        // CLP segments
        preg_match_all(
            '/CLP\*([^*]+)\*.*?\*([0-9.\-]+)\*([0-9.\-]+)/',
            $eraText, $clpAll, PREG_SET_ORDER
        );
        $blocks = preg_split('/(?=CLP\*)/', $eraText);

        foreach ($clpAll as $clp) {
            $claimNum   = trim($clp[1]);
            $origCharge = (float)$clp[2];
            $insPaid    = (float)$clp[3];
            $dos        = $checkDate;
            $patLname   = ''; $patFname = ''; $memberId = '';
            $pid        = 0;  $patLabel = '';

            foreach ($blocks as $block) {
                if (strpos($block, 'CLP*'.$claimNum.'*') !== 0) continue;

                // NM1*QC patient name + member ID
                if (preg_match('/NM1\*QC\*1\*([^*~]+)\*([^*~]+).*?\*MI\*([^*~]+)/s', $block, $nm)) {
                    $patLname  = trim($nm[1]);
                    $patFname  = trim($nm[2]);
                    $memberId  = trim($nm[3]);
                }
                // DTM*232 DOS
                if (preg_match('/DTM\*232\*(\d{8})/', $block, $dm)) {
                    $r   = $dm[1];
                    $dos = substr($r,0,4).'-'.substr($r,4,2).'-'.substr($r,6,2);
                }
                // CAS adjustments
                if (preg_match_all(
                  '/CAS\*([A-Z]{2})\*([^~]+)~/',
                  $block,
                  $casGroups,
                  PREG_SET_ORDER
              )) {
              
                  foreach ($casGroups as $cg) {
              
                      $group = $cg[1];
                      $parts = explode('*', $cg[2]);
              
                      for ($i = 1; $i < count($parts); $i += 3) {
              
                          if (empty($parts[$i])) {
                              continue;
                          }
              
                          $amt = (float)$parts[$i];
              
                          if ($group === 'CO') {
                              $contractAdj += $amt;
                          }
              
                          if ($group === 'PR') {
                              $patResp += $amt;
                          }
                      }
                  }
              }
                break;
            }

            // Try member-id → pubpid first
            if ($memberId) {
                $pr = sqlQuery("SELECT pid, fname, lname, pubpid FROM patient_data WHERE pubpid = ? LIMIT 1", [$memberId]);
                if ($pr) { $pid = (int)$pr['pid']; $patLabel = strtoupper($pr['lname']).', '.$pr['fname'].' ('.$pr['pubpid'].')'; }
            }
            // Fallback: name match
            if (!$pid && $patLname) {
                $pr = sqlQuery("SELECT pid, fname, lname, pubpid FROM patient_data WHERE lname = ? AND fname = ? LIMIT 1", [$patLname, $patFname]);
                if ($pr) { $pid = (int)$pr['pid']; $patLabel = strtoupper($pr['lname']).', '.$pr['fname'].' ('.$pr['pubpid'].')'; }
            }

            $claims[] = [
                'claim_num'    => $claimNum,
                'dos'          => $dos,
                'orig_charge'  => $origCharge,
                'ins_paid'     => $insPaid,
                'contract_adj' => $contractAdj ?? 0,
                'pat_resp'     => $patResp ?? 0,
                'era_lname'    => $patLname,
                'era_fname'    => $patFname,
                'member_id'    => $memberId,
                'pid'          => $pid,
                'pat_label'    => $patLabel,
                'matched'      => $pid > 0,
            ];
        }

        header('Content-Type: application/json');
        echo json_encode([
            'check_date' => $checkDate,
            'check_num'  => $checkNum,
            'payer_name' => $payerName,
            'claims'     => $claims,
        ]);
        exit;
    }

    // ── AJAX: post reviewed ERA claims ────────────────────────────────────────
    if ($action === 'post_era_reviewed') {
        $claimsJson  = $_POST['claims']    ?? '[]';
        $checkNum    = trim($_POST['check_num']   ?? '');
        $payerId     = (int)($_POST['payer_id']   ?? 0);
        $postUser    = (int)$_SESSION['authUserID'];
        $claims      = json_decode($claimsJson, true) ?: [];
        $posted = 0; $created = 0; $errors = [];

        foreach ($claims as $c) {
            $pid        = (int)($c['pid']        ?? 0);
            $dos        = trim($c['dos']          ?? '');
            $origCharge = (float)($c['orig_charge']  ?? 0);
            $insPaid    = (float)($c['ins_paid']     ?? 0);
            $contractAdj= (float)($c['contract_adj'] ?? 0);
            $patResp    = (float)($c['pat_resp']     ?? 0);
            $claimNum   = trim($c['claim_num']   ?? '');

            if (!$pid || !$dos) {
                $errors[] = "Skipped claim {$claimNum}: no patient assigned";
                continue;
            }

            // Duplicate check
            /*$dup = sqlQuery(
                "SELECT 1 FROM ar_activity a
                   JOIN ar_session s ON s.session_id = a.session_id
                  WHERE a.pid=? AND s.reference=? AND s.payer_id=? LIMIT 1",
                [$pid, $checkNum, $payerId]
            );
            if ($dup) { $errors[] = "Duplicate skipped: claim {$claimNum}"; continue; } */

            $enc = legacyFindEncounter($pid, $dos);
            if (!$enc) {
                $encId = legacyCreateEncounter($pid, $dos);
                $created++;
            } else {
                $encId = (int)$enc['encounter'];
            }

            legacyCreateBilling($pid, $encId, $dos, $origCharge, $payerId, $postUser);
            legacyPostARSession($pid, $encId, $dos, $origCharge, $insPaid, $contractAdj, $patResp, 0,
                                $payerId, $checkNum, 'Electronic', 'Legacy ERA import', $postUser);
            $posted++;
        }

        header('Content-Type: application/json');
        echo json_encode([
            'type'    => empty($errors) ? 'success' : 'warning',
            'msg'     => "{$posted} claims posted. {$created} encounters created."
                       . (empty($errors) ? '' : ' Warnings: '.implode('; ', $errors)),
            'posted'  => $posted,
            'created' => $created,
            'errors'  => $errors,
        ]);
        exit;
    }
if ($action === 'lookup_patient') {

  $encounter = trim($_POST['claim_num'] ?? '');

$pat = sqlQuery(
    "SELECT p.pid, p.fname, p.lname, p.pubpid
       FROM form_encounter fe
       JOIN patient_data p ON p.pid = fe.pid
      WHERE CAST(fe.encounter AS CHAR) = ?
      LIMIT 1",
    [$encounter]
);

  header('Content-Type: application/json');

  if ($pat) {

      echo json_encode([
          'name' =>
              strtoupper($pat['lname']) .
              ', ' .
              $pat['fname'] .
              ' (' . $pat['pubpid'] . ')'
      ]);

  } else {

      echo json_encode([
          'name' => 'Patient not found'
      ]);
  }

  exit;
}

    // ── MAIN POST: post_manual ────────────────────────────────────────────────
    if ($action === 'post_manual') {
        $pid          = (int)($_POST['pid']          ?? 0);
        $dos          = trim($_POST['dos']          ?? '');
        $payerId      = (int)($_POST['payer_id']    ?? 0);
        $checkNum     = trim($_POST['check_num']    ?? '');
        $origCharge   = (float)($_POST['orig_charge']    ?? 0);
        $insPaid      = (float)($_POST['ins_paid']       ?? 0);
        $contractAdj  = (float)($_POST['contract_adj']   ?? 0);
        $patResp      = (float)($_POST['pat_resp']       ?? 0);
        $otherAdj     = (float)($_POST['other_adj']      ?? 0);
        $payMethod    = trim($_POST['pay_method']  ?? 'check');
        $notes        = trim($_POST['notes']        ?? '');
        $autoCreate   = isset($_POST['auto_create']);
        $confirmed    = isset($_POST['confirmed']);

        $postUser = (int)$_SESSION['authUserID'];

        // Validate
        if (!$pid || !$dos || !$payerId) {
            $postResult = ['type' => 'error', 'msg' => xl('Patient, DOS, and Insurance are required.')];
        } else {
            $enc = legacyFindEncounter($pid, $dos);

            if (!$enc && !$autoCreate) {
                $postResult = ['type' => 'error', 'msg' => xl('No encounter found for this DOS. Enable auto-create or select a different date.')];
            } elseif (!$enc && $autoCreate && !$confirmed) {
                // Need JS confirmation — send back JSON if AJAX, else set flag
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['need_confirm' => true]);
                    exit;
                }
                $confirmNeeded = true;
                $confirmData   = compact('pid','dos','payerId','checkNum',
                    'origCharge','insPaid','contractAdj','patResp','otherAdj',
                    'payMethod','notes');
            } else {
                // Create encounter if needed
                $createdEnc = false;
                if (!$enc) {
                    $encId      = legacyCreateEncounter($pid, $dos);
                    $enc        = ['pid' => $pid, 'encounter' => $encId];
                    $createdEnc = true;
                }
                $encId = (int)$enc['encounter'];

                // Billing row
                legacyCreateBilling($pid, $encId, $dos, $origCharge, $payerId, $postUser);

                // AR session + activity
                $sesId = legacyPostARSession(
                    $pid, $encId, $dos,
                    $origCharge, $insPaid, $contractAdj, $patResp, $otherAdj,
                    $payerId, $checkNum, $payMethod, $notes, $postUser
                );

                $encMsg = $createdEnc
                    ? ' ' . xl('(new legacy encounter created)')
                    : '';
                $postResult = [
                    'type' => 'success',
                    'msg'  => xl('Legacy EOB posted successfully.') . $encMsg
                             . ' ' . xl('AR Session') . ': #' . $sesId
                             . ', ' . xl('Encounter') . ': #' . $encId,
                ];
            }
        }
    }

    // ── CSV BATCH UPLOAD ──────────────────────────────────────────────────────
    if ($action === 'post_csv' && !empty($_FILES['csv_file']['tmp_name'])) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $header = fgetcsv($handle); // skip header row
        $count  = 0;
        $errors = [];
        $postUser = (int)$_SESSION['authUserID'];

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 7) { continue; }
            [$pubpid, $dos, $payerName, $checkNum, $origCharge, $insPaid, $contractAdj, $patResp, $otherAdj, $notes]
                = array_pad($row, 10, '');

            // Resolve pubpid → pid from patient_data
            $patRow = sqlQuery(
                "SELECT pid FROM patient_data WHERE pubpid = ? LIMIT 1",
                [trim($pubpid)]
            );
            if (!$patRow) { $errors[] = xl('Patient not found') . ': ' . text($pubpid); continue; }
            $pid = (int)$patRow['pid'];

            // Resolve payer
            $payRow = sqlQuery(
                "SELECT id FROM insurance_companies WHERE name LIKE ? LIMIT 1",
                ['%' . trim($payerName) . '%']
            );
            $payerId = $payRow ? (int)$payRow['id'] : 0;

            $enc = legacyFindEncounter($pid, trim($dos));
            if (!$enc) {
                $encId = legacyCreateEncounter($pid, trim($dos));
                $enc   = ['pid' => $pid, 'encounter' => $encId];
            }
            $encId = (int)$enc['encounter'];

            legacyCreateBilling($pid, $encId, trim($dos), (float)$origCharge, $payerId, $postUser);
            legacyPostARSession(
                $pid, $encId, trim($dos),
                (float)$origCharge, (float)$insPaid, (float)$contractAdj,
                (float)$patResp, (float)($otherAdj ?? 0),
                $payerId, trim($checkNum), 'check', trim($notes ?? ''), $postUser
            );
            $count++;
        }
        fclose($handle);

        $msg = xl('CSV batch import complete.') . ' ' . $count . ' ' . xl('records posted.');
        if ($errors) { $msg .= ' ' . xl('Errors') . ': ' . implode('; ', $errors); }
        $postResult = ['type' => $errors ? 'warning' : 'success', 'msg' => $msg];
    }

    if ($action === 'post_era' && !empty($_FILES['form_erafile']['tmp_name'])) {

      $eraText = file_get_contents($_FILES['form_erafile']['tmp_name']);
    
      // Parse BPR date
      $checkDate = date('Y-m-d');
      if (preg_match('/BPR[^~]*\*(\d{8})[*~]/', $eraText, $m)) {
          $r = $m[1];
          $checkDate =
              substr($r,0,4) . '-' .
              substr($r,4,2) . '-' .
              substr($r,6,2);
      }

      
    
      // Parse payer
      $payerName = '';
      if (preg_match('/N1\*PR\*([^*~\r\n]+)/', $eraText, $m)) {
          $payerName = trim($m[1]);
      }

      $checkNum = '';

      if (preg_match('/TRN\*1\*([^*~\r\n]+)/', $eraText, $m)) {
          $checkNum = trim($m[1]);
      }
    
      // Resolve payer ID
      $payerRow = sqlQuery(
          "SELECT id FROM insurance_companies WHERE name LIKE ? LIMIT 1",
          ['%' . $payerName . '%']
      );
    
      $payerId = $payerRow ? (int)$payerRow['id'] : 0;
    
      // Parse CLP segments
      preg_match_all(
        '/CLP\*([^*]+)\*.*?\*([0-9\.\-]+)\*([0-9\.\-]+)/',
          $eraText,
          $matches,
          PREG_SET_ORDER
      );
    
      $posted = 0;
      $created = 0;
      $errors = [];

           // Try to extract DOS from DTM*232
          // Split ERA into individual claim blocks
          $claimBlocks = preg_split('/(?=CLP\*)/', $eraText);
    
      foreach ($matches as $clp) {
    
        $claimNum   = trim($clp[1]);
        $origCharge = (float)$clp[2];
        $insPaid    = (float)$clp[3];
        
        $pid = 0;

        foreach ($claimBlocks as $block) {
        
            if (strpos($block, 'CLP*' . $claimNum . '*') !== 0) {
                continue;
            }
        
            // Patient from NM1*QC
            if (preg_match(
                '/NM1\*QC\*1\*([^*~]+)\*([^*~]+).*?\*MI\*([^*~]+)/s',
                $block,
                $pm
            )) {
        
                $lname   = trim($pm[1]);
                $fname   = trim($pm[2]);
                $memberId = trim($pm[3]);
        
                // Try pubpid/member-id first
                $pat = sqlQuery(
                    "SELECT pid
                       FROM patient_data
                      WHERE pubpid = ?
                      LIMIT 1",
                    [$memberId]
                );
        
                // Fallback name match
                if (!$pat) {
        
                    $pat = sqlQuery(
                        "SELECT pid
                           FROM patient_data
                          WHERE lname = ?
                            AND fname = ?
                          LIMIT 1",
                        [$lname, $fname]
                    );
                }
        
                if ($pat) {
                    $pid = (int)$pat['pid'];
                }
            }
        
            break;
        }
        
        if (!$pid) {
            $errors[] = "No patient match for claim {$claimNum}";
            continue;
        }
    
         

          $dos = $checkDate;

          foreach ($claimBlocks as $block) {

              // Only inspect the block matching THIS claim
              if (strpos($block, 'CLP*' . $claimNum . '*') !== 0) {
                  continue;
              }

              // Extract DTM*232 service date safely within this claim block
              if (preg_match('/DTM\*232\*(\d{8})/', $block, $dm)) {

                  $r = $dm[1];

                  $dos =
                      substr($r, 0, 4) . '-' .
                      substr($r, 4, 2) . '-' .
                      substr($r, 6, 2);
              }

              break;
          }
        
     
    
          $enc = legacyFindEncounter($pid, $dos);
    
          if (!$enc) {
            $encId = legacyCreateEncounter($pid, $dos);
            $enc   = ['pid'=>$pid,'encounter'=>$encId];
            $created++;
        } else {
            $encId = (int)$enc['encounter'];
        }
    
  


          // Prevent duplicate posting for SAME patient + encounter + check #
         /* $dup = sqlQuery(
            "SELECT 1
              FROM ar_activity a
              JOIN ar_session s
                ON s.session_id = a.session_id
              WHERE a.pid = ?
                AND a.encounter = ?
                AND s.reference = ?
                AND s.payer_id = ?
              LIMIT 1",
            [
                $pid,
                $encId,
                $checkNum,
                $payerId
            ]
          );

          if ($dup) {
            continue;
          }*/
          // ONLY CREATE BILLING IF NOT DUPLICATE
          legacyCreateBilling(
            $pid,
            $encId,
            $dos,
            $origCharge,
            $payerId,
            (int)$_SESSION['authUserID']
        );


        $contractAdj = 0.0;
        $patResp     = 0.0;
        
        // Parse CAS segments for THIS claim
        foreach ($claimBlocks as $block) {
        
            if (strpos($block, 'CLP*' . $claimNum . '*') !== 0) {
                continue;
            }
        
            if (preg_match_all(
              '/CAS\*([A-Z]{2})\*(.+?)~/',
              $block,
              $casGroups,
              PREG_SET_ORDER
          )) {
          
              foreach ($casGroups as $cg) {
          
                  $group = $cg[1];
          
                  // extract ALL numeric amounts
                  if (preg_match_all(
                      '/\*([0-9]+\.[0-9]{1,2})/',
                      '*' . $cg[2],
                      $amounts
                  )) {
          
                      foreach ($amounts[1] as $amtVal) {
          
                          $amt = (float)$amtVal;
          
                          if ($group === 'CO') {
                              $contractAdj += $amt;
                          }
          
                          if ($group === 'PR') {
                              $patResp += $amt;
                          }
                      }
                  }
              }
          }
        
            break;
        }

          // THEN POST AR
          legacyPostARSession(
            $pid,
            $encId,
            $dos,
            $origCharge,
            $insPaid,
            $contractAdj,
            $patResp,
            0,
            $payerId,
            $checkNum,
            'Electronic',
            'Legacy ERA import',
            (int)$_SESSION['authUserID']
        );
    
          $posted++;
      }
    
      $postResult = [
          'type' => empty($errors) ? 'success' : 'warning',
          'msg'  =>
              "ERA processed. "
              . "{$posted} claims posted. "
              . "{$created} encounters auto-created."
              . (empty($errors) ? '' : ' Errors: ' . implode('; ', $errors))
      ];

      header('Content-Type: application/json');

      echo json_encode($postResult);
      exit;
    }


   
    // ERA files that DO have matching encounters follow the normal era_payments flow.
    // Unmatched ones are queued back to manual entry with pre-filled fields.
}




// ── POSTING HISTORY (last 50 legacy sessions) ─────────────────────────────────
$historyRows = [];
$histRes = sqlStatement(
    "SELECT s.session_id AS ses_id, s.check_date, s.pay_total, s.reference,
            s.description, s.user_id,
            p.lname, p.fname, p.pubpid,
            fe.encounter, fe.date AS enc_date,
            ic.name AS payer_name,
            u.username
       FROM ar_session s
       JOIN ar_activity a  ON a.session_id = s.session_id
       JOIN form_encounter fe ON fe.pid = a.pid AND fe.encounter = a.encounter
       JOIN patient_data p    ON p.pid = a.pid
  LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
  LEFT JOIN users u ON u.id = s.user_id
      WHERE fe.encounter_type_code = 'legacy_eob'
   GROUP BY s.session_id
   ORDER BY s.session_id DESC
      LIMIT 50"
);
while ($r = sqlFetchArray($histRes)) {
    $historyRows[] = $r;
}

?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['datetime-picker', 'common']); ?>
    <?php require_once("{$GLOBALS['srcdir']}/ajax/payment_ajax_jav.inc.php"); ?>
    <title><?php echo xlt('Legacy EOB Posting'); ?></title>
    <?php
    $arrOeUiSettings = [
        'heading_title'         => xl('Payments'),
        'include_patient_name'  => false,
        'expandable'            => true,
        'expandable_files'      => ['era_payments_xpd', 'search_payments_xpd', 'new_payment_xpd'],
        'action'                => '',
        'action_title'          => '',
        'action_href'           => '',
        'show_help_icon'        => false,
        'help_file_name'        => '',
    ];
    $oemr_ui = new OemrUI($arrOeUiSettings);
    ?>
<style>
/* ── Modern Payment Nav ──────────────────────────────────────────────────── */
.pay-nav-wrap{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:6px;margin-bottom:1.5rem;display:flex;align-items:center;gap:2px;flex-wrap:wrap;box-shadow:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);}
.pay-nav-wrap .pnav-item{display:flex;align-items:center;gap:7px;padding:8px 15px;border-radius:7px;font-size:13px;font-weight:500;color:#64748b;text-decoration:none!important;transition:background .15s,color .15s;white-space:nowrap;border:1px solid transparent;line-height:1;}
.pay-nav-wrap .pnav-item svg{flex-shrink:0;opacity:.7;transition:opacity .15s;}
.pay-nav-wrap .pnav-item:hover{background:#f8fafc;color:#1e293b;border-color:#e2e8f0;text-decoration:none!important;}
.pay-nav-wrap .pnav-item:hover svg{opacity:1;}
.pay-nav-wrap .pnav-item.pnav-active{background:#f5f3ff;color:#6610f2;border-color:#ddd6fe;font-weight:600;box-shadow:0 1px 3px rgba(102,16,242,.1);}
.pay-nav-wrap .pnav-item.pnav-active svg{opacity:1;}
.pnav-badge{display:inline-flex;align-items:center;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700;line-height:1.4;background:#f5f3ff;color:#6610f2;border:1px solid #ddd6fe;letter-spacing:.03em;margin-left:2px;}
.pnav-item.pnav-active .pnav-badge{background:#ede9fe;border-color:#c4b5fd;}
.pnav-sep{width:1px;height:20px;background:#e2e8f0;margin:0 3px;flex-shrink:0;}
@media(max-width:768px){.pay-nav-wrap{gap:1px;padding:4px;}.pay-nav-wrap .pnav-item{padding:7px 10px;font-size:12px;}.pnav-label{display:none;}.pnav-sep{display:none;}}

/* ── Page-specific overrides ─────────────────── */
.leob-mode-toggle { display:flex; gap:0; margin-bottom:1.25rem; border:1px solid #dee2e6; border-radius:.375rem; overflow:hidden; width:fit-content; }
.leob-mode-btn { padding:.5rem 1.25rem; background:#fff; border:none; font-size:.875rem; font-weight:500; color:#6c757d; cursor:pointer; transition:all .15s; }
.leob-mode-btn.active { background:#6610f2; color:#fff; }
.leob-mode-btn:not(.active):hover { background:#f8f9fa; color:#212529; }

.leob-section { background:#fff; border:1px solid #dee2e6; border-radius:.5rem; padding:1.25rem; margin-bottom:1rem; }
.leob-section-title { font-size:.8125rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#6c757d; margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }
.leob-section-title::before { content:''; width:10px; height:10px; border-radius:50%; background:#6610f2; flex-shrink:0; }

.leob-label { font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#6c757d; margin-bottom:.25rem; display:block; }
.leob-req { color:#dc3545; }

.leob-money { position:relative; }
.leob-money-prefix { position:absolute; left:.75rem; top:50%; transform:translateY(-50%); color:#6c757d; font-weight:500; pointer-events:none; }
.leob-money input { padding-left:1.4rem; font-family:'Courier New', monospace; }

.leob-preview { width:100%; border-collapse:collapse; }
.leob-preview th { padding:.5rem 1rem; font-size:.6875rem; font-weight:600; text-transform:uppercase; letter-spacing:.07em; color:#6c757d; border-bottom:2px solid #dee2e6; text-align:left; }
.leob-preview th:last-child { text-align:right; }
.leob-preview td { padding:.8125rem 1rem; font-size:.875rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.leob-preview td:last-child { text-align:right; font-family:'Courier New', monospace; font-weight:600; }
.leob-preview tbody tr:hover td { background:#f8f9fa; }

.leob-badge { display:inline-flex; align-items:center; gap:.3125rem; padding:.1875rem .5625rem; border-radius:.375rem; font-size:.6875rem; font-weight:600; }
.leob-badge-charge { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
.leob-badge-payment { background:#eff6ff; color:#2563eb; border:1px solid #bfdbfe; }
.leob-badge-adj { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
.leob-badge-patient { background:#fffbeb; color:#d97706; border:1px solid #fde68a; }

.leob-amount-neg { color:#dc2626; }
.leob-amount-pat { color:#d97706; }
.leob-amount-pos { color:#212529; }

.leob-balance-footer { display:flex; justify-content:flex-end; align-items:center; gap:.75rem; padding:.875rem 1rem 0; border-top:2px solid #dee2e6; }
.leob-balance-label { font-size:.875rem; font-weight:600; color:#6c757d; }
.leob-balance-val { font-size:1rem; font-weight:700; font-family:'Courier New', monospace; color:#d97706; }

.leob-check-badge { display:inline-flex; align-items:center; gap:.375rem; padding:.1875rem .625rem; border-radius:20px; font-size:.6875rem; font-weight:600; }
.leob-check-ok { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
.leob-check-warn { background:#fffbeb; color:#d97706; border:1px solid #fde68a; }

.era-dropzone { border:2px dashed #dee2e6; border-radius:.5rem; padding:2.5rem 1.5rem; text-align:center; cursor:pointer; position:relative; background:#f8fafc; transition:all .2s; }
.era-dropzone:hover, .era-dropzone.drag-over { border-color:#6610f2; background:#f5f3ff; }
.era-dropzone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; width:100%; height:100%; }

.leob-patient-dropdown { position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #dee2e6; border-radius:.375rem; box-shadow:0 4px 12px rgba(0,0,0,.1); z-index:200; display:none; }
.leob-patient-dropdown.open { display:block; }
.leob-patient-opt { padding:.625rem .875rem; cursor:pointer; border-bottom:1px solid #f1f5f9; transition:background .1s; }
.leob-patient-opt:last-child { border-bottom:none; }
.leob-patient-opt:hover { background:#f5f3ff; }
.leob-patient-opt-name { font-size:.875rem; font-weight:600; }
.leob-patient-opt-meta { font-size:.75rem; color:#6c757d; font-family:'Courier New', monospace; }

.leob-dos-alert { display:none; }
.leob-dos-alert.show { display:flex; }
.leob-autocreate-row { display:none; }
.leob-autocreate-row.show { display:flex; }

.history-table th, .history-table td { font-size:.8125rem; }
.history-table td { vertical-align:middle; }

/* Modal confirm */
.leob-modal-overlay { display:none; position:fixed; inset:0; background:rgba(15,23,42,.5); backdrop-filter:blur(3px); z-index:1050; align-items:center; justify-content:center; }
.leob-modal-overlay.open { display:flex; }
.leob-modal { background:#fff; border-radius:.75rem; box-shadow:0 20px 60px rgba(0,0,0,.2); width:560px; max-width:calc(100vw - 2rem); animation:leobSlide .18s ease; }
@keyframes leobSlide { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:none} }
.leob-modal-hdr { padding:1.25rem 1.5rem 1rem; border-bottom:1px solid #dee2e6; display:flex; gap:.875rem; align-items:flex-start; }
.leob-modal-icon { width:40px; height:40px; background:#fffbeb; border:1px solid #fde68a; border-radius:.625rem; display:grid; place-items:center; flex-shrink:0; }
.leob-modal-title { font-size:1rem; font-weight:700; margin-bottom:.25rem; }
.leob-modal-sub { font-size:.8125rem; color:#6c757d; line-height:1.5; }
.leob-modal-body { padding:1.25rem 1.5rem; }
.leob-modal-ftr { padding:1rem 1.5rem 1.25rem; border-top:1px solid #dee2e6; display:flex; justify-content:flex-end; gap:.625rem; }
.leob-field-row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }
.leob-info-row { display:flex; justify-content:space-between; align-items:center; padding:.625rem .875rem; background:#f8fafc; border:1px solid #dee2e6; border-radius:.375rem; margin-top:.5rem; }
.leob-info-lbl { font-size:.75rem; color:#6c757d; font-weight:500; }
.leob-info-val { font-size:.8125rem; font-weight:700; font-family:'Courier New', monospace; }
</style>
</head>

<body>
<div id="container_div" class="<?php echo attr($oemr_ui->oeContainer()); ?> mt-3">

  <div class="row">
    <div class="col-sm-12">
      <?php echo $oemr_ui->pageHeading() . "\r\n"; ?>
    </div>
  </div>

  <!-- ── Modern Payment Navigation ──────────────────────────────────── -->
  <nav class="pay-nav-wrap" aria-label="<?php echo xla('Payment Navigation'); ?>">
    <a class="pnav-item" href="new_payment.php">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="7" y1="15" x2="10" y2="15"/><line x1="14" y1="15" x2="17" y2="15"/></svg>
      <span class="pnav-label"><?php echo xlt('<- Back to New Payment'); ?></span>
    </a>
    <div class="pnav-sep"></div>
    <a class="pnav-item pnav-active" href="legacy_eob_posting.php" aria-current="page">
      <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
      <span class="pnav-label"><?php echo xlt('Legacy EOB Posting'); ?></span>
      <span class="pnav-badge">NEW</span>
    </a>
  </nav>

  <!-- ── Post result banner ─────────────────────────────────────────────── -->
  <?php if ($postResult): ?>
  <div class="row mb-3">
    <div class="col-12">
      <div class="alert alert-<?php echo $postResult['type'] === 'success' ? 'success' : ($postResult['type'] === 'warning' ? 'warning' : 'danger'); ?> d-flex align-items-center gap-2">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <?php if ($postResult['type'] === 'success'): ?>
            <polyline points="20 6 9 17 4 12"/>
          <?php else: ?>
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
          <?php endif; ?>
        </svg>
        <strong><?php echo text($postResult['msg']); ?></strong>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Page header row ────────────────────────────────────────────────── -->
  <div class="row mb-3">
    <div class="col-sm-8">
      <h4 class="mb-1"><?php echo xlt('Legacy EOB Posting'); ?></h4>
      <p class="text-muted mb-0" style="font-size:.875rem">
        <?php echo xlt('Post historical EOB payments for service dates or encounters not yet in the current system. No SOAP notes, providers, or appointments required.'); ?>
      </p>
    </div>
    <div class="col-sm-4 text-right d-flex align-items-start justify-content-end gap-2">
      <button class="btn btn-sm btn-outline-secondary" type="button" data-toggle="collapse" data-target="#historyPanel">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
        <?php echo xlt('Posting History'); ?>
      </button>
      <button class="btn btn-sm btn-outline-secondary" type="button" onclick="document.getElementById('csv-upload-btn').click()">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <?php echo xlt('Import CSV'); ?>
      </button>
    </div>
  </div>

  <!-- ── Posting History (collapsible) ─────────────────────────────────── -->
  <div class="collapse mb-4" id="historyPanel">
    <div class="leob-section">
      <div class="leob-section-title" style="--leob-dot:#2563eb">
        <?php echo xlt('Posting History — Last 50 Legacy EOB Sessions'); ?>
      </div>
      <?php if ($historyRows): ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover history-table">
          <thead class="thead-light">
            <tr>
              <th><?php echo xlt('Session'); ?></th>
              <th><?php echo xlt('Posted'); ?></th>
              <th><?php echo xlt('Patient'); ?></th>
              <th><?php echo xlt('DOS'); ?></th>
              <th><?php echo xlt('Payer'); ?></th>
              <th><?php echo xlt('Check #'); ?></th>
              <th class="text-right"><?php echo xlt('Ins. Paid'); ?></th>
              <th><?php echo xlt('By'); ?></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($historyRows as $hr): ?>
            <tr>
              <td><code>#<?php echo text($hr['ses_id']); ?></code></td>
              <td style="font-family:'Courier New',monospace"><?php echo text($hr['check_date']); ?></td>
              <td><?php echo text(strtoupper($hr['lname']) . ', ' . $hr['fname']); ?>
                  <small class="text-muted">(<?php echo text($hr['pubpid']); ?>)</small></td>
              <td style="font-family:'Courier New',monospace"><?php echo text(date('m/d/Y', strtotime($hr['enc_date']))); ?></td>
              <td><?php echo text($hr['payer_name']); ?></td>
              <td><code><?php echo text($hr['reference']); ?></code></td>
              <td class="text-right" style="color:#16a34a;font-family:'Courier New',monospace">$<?php echo number_format((float)$hr['pay_total'], 2); ?></td>
              <td class="text-muted"><?php echo text($hr['username']); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
        <p class="text-muted mb-0"><?php echo xlt('No legacy EOB postings found.'); ?></p>
      <?php endif; ?>
    </div>
  </div>

  <!-- ── Mode Toggle ────────────────────────────────────────────────────── -->
  <div class="leob-mode-toggle">
    <button class="leob-mode-btn active" id="btn-mode-manual" onclick="setMode('manual')">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:5px"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
      <?php echo xlt('Manual Entry'); ?>
    </button>
    <button class="leob-mode-btn" id="btn-mode-era" onclick="setMode('era')">
      <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:5px"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      <?php echo xlt('Upload ERA / EOB'); ?>
    </button>
  </div>

  <!-- ════════════════════════════════════════════════════════════════════
       MANUAL ENTRY MODE
       ════════════════════════════════════════════════════════════════ -->
  <div id="manual-mode">
    <form id="manual-form" method="POST" action="legacy_eob_posting.php" autocomplete="off">
      <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
      <input type="hidden" name="action" value="post_manual">
      <input type="hidden" name="pid" id="field-pid" value="">
      <input type="hidden" name="confirmed" id="field-confirmed" value="">

      <!-- ── Encounter Identification ─────────────────────────────────── -->
      <div class="leob-section">
        <div class="leob-section-title d-flex justify-content-between">
          <span><?php echo xlt('Encounter Identification'); ?></span>
          <span id="dos-badge" style="display:none"></span>
        </div>

        <div class="row">
          <!-- Patient -->
          <div class="col-md-3 col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Patient'); ?> <span class="leob-req">*</span></label>
            <div class="position-relative">
              <input type="text" class="form-control form-control-sm" id="patient-display"
                     placeholder="<?php echo xla('Search name or ID…'); ?>"
                     oninput="patientSearch(this.value)" autocomplete="off">
              <div class="leob-patient-dropdown" id="patient-dropdown"></div>
            </div>
          </div>

          <!-- DOS -->
          <div class="col-md-3 col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Service Date (DOS)'); ?> <span class="leob-req">*</span></label>
            <input type="date" class="form-control form-control-sm datepicker" id="field-dos" name="dos"
                   onchange="checkDOS()" value="<?php echo attr($_POST['dos'] ?? ''); ?>">
          </div>

          <!-- Insurance -->
          <div class="col-md-3 col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Insurance'); ?> <span class="leob-req">*</span></label>
            <select class="form-control form-control-sm" name="payer_id" id="field-payer">
              <option value=""><?php echo xlt('Select insurance…'); ?></option>
              <?php foreach ($insuranceOptions as $ins): ?>
                <option value="<?php echo attr($ins['id']); ?>"
                  <?php echo (!empty($_POST['payer_id']) && $_POST['payer_id'] == $ins['id']) ? 'selected' : ''; ?>>
                  <?php echo text($ins['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Claim # -->
          <div class="col-md-3 col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Claim / Check #'); ?></label>
            <input type="text" class="form-control form-control-sm" name="check_num"
                   placeholder="H123456789" value="<?php echo attr($_POST['check_num'] ?? ''); ?>">
          </div>
        </div>

        <!-- DOS status alerts -->
        <div class="alert alert-warning leob-dos-alert align-items-center gap-2 mb-2" id="dos-missing-alert">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="flex-shrink-0"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
          <div>
            <?php echo xlt('No encounter found for this DOS.'); ?>
            <strong><?php echo xlt('A historical encounter will be auto-created when you post.'); ?></strong>
            <?php echo xlt('No SOAP notes, provider, or appointment required.'); ?>
          </div>
        </div>
        <div class="alert alert-success leob-dos-alert align-items-center gap-2 mb-2" id="dos-found-alert">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="flex-shrink-0"><polyline points="20 6 9 17 4 12"/></svg>
          <span><?php echo xlt('Encounter found for DOS'); ?> <strong id="dos-found-date"></strong>. <?php echo xlt('Payment will be applied to the existing encounter.'); ?></span>
        </div>

        <!-- Auto-create checkbox (shown only when DOS missing) -->
        <div class="leob-autocreate-row align-items-center gap-2 p-2 rounded mb-0"
             id="autocreate-row"
             style="background:#f5f3ff;border:1px solid #ddd6fe;">
          <input type="checkbox" name="auto_create" id="auto-create-check" class="form-check-input"
                 style="width:16px;height:16px;accent-color:#6610f2" checked>
          <label for="auto-create-check" style="font-size:.875rem;font-weight:500;color:#6610f2;cursor:pointer;margin:0">
            <?php echo xlt('Auto-create legacy encounter if not found — only DOS, patient &amp; billing anchor required'); ?>
          </label>
        </div>
      </div>

      <!-- ── Payment Amounts ───────────────────────────────────────────── -->
      <div class="leob-section">
        <div class="leob-section-title d-flex justify-content-between">
          <span><?php echo xlt('Payment Amounts'); ?></span>
          <span id="balance-badge" style="display:none"></span>
        </div>

        <div class="row">
          <div class="col-md col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Original Charge'); ?></label>
            <div class="leob-money">
              <span class="leob-money-prefix">$</span>
              <input type="number" class="form-control form-control-sm" name="orig_charge"
                     id="f-orig-charge" min="0" step="0.01" placeholder="0.00"
                     value="<?php echo attr($_POST['orig_charge'] ?? ''); ?>"
                     oninput="updatePreview()">
            </div>
          </div>
          <div class="col-md col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Insurance Paid'); ?></label>
            <div class="leob-money">
              <span class="leob-money-prefix">$</span>
              <input type="number" class="form-control form-control-sm" name="ins_paid"
                     id="f-ins-paid" min="0" step="0.01" placeholder="0.00"
                     value="<?php echo attr($_POST['ins_paid'] ?? ''); ?>"
                     oninput="updatePreview()">
            </div>
          </div>
          <div class="col-md col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Contractual Adj / Writeoff'); ?></label>
            <div class="leob-money">
              <span class="leob-money-prefix">$</span>
              <input type="number" class="form-control form-control-sm" name="contract_adj"
                     id="f-contract-adj" min="0" step="0.01" placeholder="0.00"
                     value="<?php echo attr($_POST['contract_adj'] ?? ''); ?>"
                     oninput="updatePreview()">
            </div>
          </div>
          <div class="col-md col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Patient Responsibility'); ?></label>
            <div class="leob-money">
              <span class="leob-money-prefix">$</span>
              <input type="number" class="form-control form-control-sm" name="pat_resp"
                     id="f-pat-resp" min="0" step="0.01" placeholder="0.00"
                     value="<?php echo attr($_POST['pat_resp'] ?? ''); ?>"
                     oninput="updatePreview()">
            </div>
          </div>
          <div class="col-md col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Other Adjustment'); ?></label>
            <div class="leob-money">
              <span class="leob-money-prefix">$</span>
              <input type="number" class="form-control form-control-sm" name="other_adj"
                     id="f-other-adj" min="0" step="0.01" placeholder="0.00"
                     value="<?php echo attr($_POST['other_adj'] ?? ''); ?>"
                     oninput="updatePreview()">
            </div>
          </div>
        </div>

        <hr class="my-3">

        <div class="row">
          <div class="col-md-3 col-sm-6 mb-2">
            <label class="leob-label"><?php echo xlt('Payment Method'); ?></label>
            <select class="form-control form-control-sm" name="pay_method">
              <option value="check" <?php echo ($_POST['pay_method'] ?? '') === 'check' ? 'selected' : ''; ?>><?php echo xlt('Check'); ?></option>
              <option value="eft"   <?php echo ($_POST['pay_method'] ?? '') === 'eft'   ? 'selected' : ''; ?>><?php echo xlt('EFT / Electronic'); ?></option>
              <option value="cash"  <?php echo ($_POST['pay_method'] ?? '') === 'cash'  ? 'selected' : ''; ?>><?php echo xlt('Cash'); ?></option>
              <option value="other" <?php echo ($_POST['pay_method'] ?? '') === 'other' ? 'selected' : ''; ?>><?php echo xlt('Other'); ?></option>
            </select>
          </div>
          <div class="col-md-3 col-sm-6 mb-2">
            <label class="leob-label"><?php echo xlt('Payment Type'); ?></label>
            <select class="form-control form-control-sm" name="pay_type">
              <option value="insurance" selected><?php echo xlt('Insurance'); ?></option>
              <option value="patient"><?php echo xlt('Patient'); ?></option>
              <option value="adjustment"><?php echo xlt('Adjustment Only'); ?></option>
            </select>
          </div>
          <div class="col-md-6 mb-2">
            <label class="leob-label"><?php echo xlt('Reference / Notes'); ?></label>
            <input type="text" class="form-control form-control-sm" name="notes"
                   placeholder="<?php echo xla('Legacy EOB from prior system'); ?>"
                   value="<?php echo attr($_POST['notes'] ?? 'Legacy EOB from prior system'); ?>">
          </div>
        </div>
      </div>

      <!-- ── Posting Preview ───────────────────────────────────────────── -->
      <div class="leob-section" id="preview-section">
        <div class="leob-section-title"><?php echo xlt('Posting Preview'); ?></div>
        <table class="leob-preview">
          <thead>
            <tr>
              <th><?php echo xlt('TYPE'); ?></th>
              <th><?php echo xlt('DESCRIPTION'); ?></th>
              <th><?php echo xlt('DATE'); ?></th>
              <th><?php echo xlt('AMOUNT'); ?></th>
            </tr>
          </thead>
          <tbody id="preview-tbody">
            <tr><td colspan="4" class="text-muted text-center py-3" style="font-size:.8125rem"><?php echo xlt('Fill in amounts above to see posting preview.'); ?></td></tr>
          </tbody>
        </table>
        <div class="leob-balance-footer">
          <span class="leob-balance-label"><?php echo xlt('Resulting Balance'); ?>:</span>
          <span class="leob-balance-val" id="resulting-balance">$0.00</span>
        </div>
      </div>

      <!-- ── Action Row ────────────────────────────────────────────────── -->
      <div class="d-flex justify-content-end gap-2 mt-3 mb-4">
        <button type="button" class="btn btn-sm btn-light" onclick="clearManualForm()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          <?php echo xlt('Clear'); ?>
        </button>
        <button type="button" class="btn btn-sm btn-primary" onclick="submitManualForm()">
          <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          <?php echo xlt('Post Legacy EOB'); ?>
        </button>
      </div>
    </form>
  </div><!-- /manual-mode -->

  <!-- ════════════════════════════════════════════════════════════════════
       ERA / EOB UPLOAD MODE
       ════════════════════════════════════════════════════════════════ -->
  <div id="era-mode" style="display:none">
    <!-- Drop zone -->
    <div class="leob-section">
      <div class="leob-section-title"><?php echo xlt('Upload ERA / EOB File'); ?></div>
      <form method="POST"
      action="legacy_eob_posting.php"
      enctype="multipart/form-data"
      id="era-form">

      <div class="era-dropzone" id="era-dropzone">

      <input type="file"
       id="era-file-input"
       name="form_erafile"
       accept=".835,.edi,.txt,.eob,.era">
        <div style="margin-bottom:.75rem">
          <div style="width:48px;height:48px;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:12px;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem">
            <svg width="24" height="24" fill="none" stroke="#6610f2" stroke-width="1.8" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/><path d="M14 2v6h6M12 18v-6M9 15l3 3 3-3"/></svg>
          </div>
          <div style="font-size:.9375rem;font-weight:600;margin-bottom:.25rem"><?php echo xlt('Drop ERA or EOB file here'); ?></div>
          <div style="font-size:.8125rem;color:#6c757d;margin-bottom:.75rem"><?php echo xlt('Supports X12 835 ERA files and plain EOB text'); ?></div>
          <div style="display:flex;gap:.375rem;justify-content:center">
            <code style="background:#f1f5f9;border:1px solid #dee2e6;border-radius:4px;padding:1px 7px;font-size:.6875rem">.835</code>
            <code style="background:#f1f5f9;border:1px solid #dee2e6;border-radius:4px;padding:1px 7px;font-size:.6875rem">.edi</code>
            <code style="background:#f1f5f9;border:1px solid #dee2e6;border-radius:4px;padding:1px 7px;font-size:.6875rem">.txt</code>
            <code style="background:#f1f5f9;border:1px solid #dee2e6;border-radius:4px;padding:1px 7px;font-size:.6875rem">.era</code>
          </div>
        </div>
      </div>

      <div id="era-loaded" class="alert alert-success d-none align-items-center gap-2 mt-3 mb-0">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
        <div><strong id="era-filename">—</strong> &nbsp;<span id="era-meta" class="text-muted" style="font-size:.75rem;font-family:'Courier New',monospace"></span></div>
        <button type="button" class="btn btn-sm btn-light ml-auto" onclick="clearERA()"><?php echo xlt('Remove'); ?></button>
      </div>
    </div>

    <!-- ERA auto-parsed fields -->
    <div class="leob-section d-none" id="era-details-card">
      <div class="leob-section-title d-flex justify-content-between">
        <span><?php echo xlt('ERA / EOB Payment Details'); ?></span>
        <span class="leob-check-badge leob-check-ok d-none" id="era-auto-badge">
          <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          <?php echo xlt('Auto-filled from BPR/TRN'); ?>
        </span>
      </div>

      <div class="alert alert-success d-none align-items-center gap-2 mb-3" id="era-detect-banner">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div><strong><?php echo xlt('ERA deposit date auto-detected'); ?></strong> — <?php echo xlt('All date fields pre-filled from BPR segment. Verify before posting.'); ?></div>
      </div>

  
        <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
        <input type="hidden" name="action" id="era-action-hidden" value="">
        <input type="hidden" name="form_erafile_path" id="era-path-hidden" value="">
        <input type="hidden" name="hidden_type_code" id="era-ins-id-hidden" value="">
        <input type="hidden" name="after_value" id="era-after-value" value="">

        <!-- Auto-parsed header fields -->
        <div class="row">
          <div class="col-md-3 col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Check / EFT Date'); ?> <span class="leob-req">*</span></label>
            <input type="text" class="form-control form-control-sm datepicker" id="era-check-date" name="check_date"
                   placeholder="<?php echo xla('YYYY-MM-DD'); ?>">
          </div>
          <div class="col-md-3 col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Check / TRN #'); ?></label>
            <input type="text" class="form-control form-control-sm" id="era-check-num" name="check_num" placeholder="<?php echo xla('Auto-parsed'); ?>">
          </div>
          <div class="col-md-3 col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Insurance / Payer'); ?></label>
            <input type="text" class="form-control form-control-sm" id="era-payer-name" name="type_code"
                   placeholder="<?php echo xla('Auto-detected from N1*PR'); ?>">
          </div>
          <div class="col-md-3 col-sm-6 mb-3">
            <label class="leob-label"><?php echo xlt('Payer'); ?> <span class="leob-req">*</span></label>
            <select class="form-control form-control-sm" id="era-payer-select" name="payer_id">
              <option value=""><?php echo xlt('Select payer…'); ?></option>
              <?php foreach ($insuranceOptions as $ins): ?>
                <option value="<?php echo attr($ins['id']); ?>"><?php echo text($ins['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Claims Review Table -->
        <div id="era-claims-wrap" style="display:none">
          <div class="leob-section-title mt-2 mb-2" style="font-size:.8125rem">
            <?php echo xlt('Claims Review — assign any unmatched patients before posting'); ?>
            <span id="era-match-summary" class="ml-2" style="font-size:.75rem;font-weight:400;color:#6c757d"></span>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover" style="font-size:.8125rem" id="era-claims-table">
              <thead class="thead-light">
                <tr>
                  <th style="width:30px"><input type="checkbox" id="era-check-all" checked></th>
                  <th><?php echo xlt('Claim #'); ?></th>
                  <th><?php echo xlt('ERA Name'); ?></th>
                  <th><?php echo xlt('DOS'); ?></th>
                  <th><?php echo xlt('Charge'); ?></th>
                  <th><?php echo xlt('Paid'); ?></th>
                  <th><?php echo xlt('Adj'); ?></th>
                  <th><?php echo xlt('Pt Resp'); ?></th>
                  <th style="min-width:220px"><?php echo xlt('Patient'); ?></th>
                  <th><?php echo xlt('Status'); ?></th>
                </tr>
              </thead>
              <tbody id="era-claims-tbody"></tbody>
            </table>
          </div>

          <div class="d-flex gap-2 justify-content-end mt-3">
            <button type="button" class="btn btn-sm btn-light" onclick="clearERA()"><?php echo xlt('Clear'); ?></button>
            <button type="button" class="btn btn-sm btn-primary" id="era-post-btn" onclick="postERAReviewed()">
              <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg>
              <?php echo xlt('Post Selected Claims'); ?>
            </button>
          </div>
        </div><!-- /era-claims-wrap -->

        <!-- Result banner lives outside era-claims-wrap so it persists after clear -->
        <div id="era-post-result" class="mt-3" style="display:none"></div>

        <div id="era-parse-spinner" style="display:none;padding:16px 0;text-align:center;color:#6c757d;font-size:.875rem">
          <span><?php echo xlt('Parsing ERA file…'); ?></span>
        </div>

        <div class="d-flex gap-2 justify-content-end" id="era-initial-actions">
          <button type="button" class="btn btn-sm btn-light" onclick="clearERA()"><?php echo xlt('Clear'); ?></button>
        </div>
      </form>
    </div>
  </div><!-- /era-mode -->

  <!-- Hidden CSV form -->
  <form id="csv-form" method="POST" action="legacy_eob_posting.php" enctype="multipart/form-data" style="display:none">
    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
    <input type="hidden" name="action" value="post_csv">
    <input type="file" id="csv-upload-btn" name="csv_file" accept=".csv" onchange="this.form.submit()">
  </form>

</div><!-- /container_div -->

<!-- ════════════════════════════════════════════════════════════════════════
     HISTORICAL ENCOUNTER CONFIRM MODAL
     ════════════════════════════════════════════════════════════════════ -->
<div class="leob-modal-overlay" id="confirm-modal">
  <div class="leob-modal">
    <div class="leob-modal-hdr">
      <div class="leob-modal-icon">
        <svg width="20" height="20" fill="none" stroke="#d97706" stroke-width="2" viewBox="0 0 24 24">
          <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
          <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
        </svg>
      </div>
      <div>
        <div class="leob-modal-title"><?php echo xlt('Create Historical Encounter?'); ?></div>
        <div class="leob-modal-sub"><?php echo xlt('No encounter was found for this DOS. A minimal billing-only encounter will be created — no clinical forms, SOAP notes, provider, or appointment required.'); ?></div>
      </div>
    </div>

    <div class="leob-modal-body">
      <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?php echo xlt('This creates a financial-only anchor for the legacy payment. It will not appear in clinical workflows.'); ?></span>
      </div>

      <div class="leob-field-row mb-2">
        <div>
          <label class="leob-label"><?php echo xlt('Patient'); ?></label>
          <input type="text" class="form-control form-control-sm" id="modal-patient" readonly style="background:#f8f9fa">
        </div>
        <div>
          <label class="leob-label"><?php echo xlt('Date of Service'); ?></label>
          <input type="text" class="form-control form-control-sm" id="modal-dos" readonly style="background:#f8f9fa">
        </div>
      </div>
      <div class="leob-field-row mb-2">
        <div>
          <label class="leob-label"><?php echo xlt('Insurance'); ?></label>
          <input type="text" class="form-control form-control-sm" id="modal-insurance" readonly style="background:#f8f9fa">
        </div>
        <div>
          <label class="leob-label"><?php echo xlt('Claim / Check #'); ?></label>
          <input type="text" class="form-control form-control-sm" id="modal-claim" readonly style="background:#f8f9fa">
        </div>
      </div>
      <div class="leob-info-row">
        <span class="leob-info-lbl"><?php echo xlt('Original Charge (billing anchor)'); ?></span>
        <span class="leob-info-val" id="modal-charge">$0.00</span>
      </div>
      <div class="leob-info-row" style="margin-top:.5rem">
        <span class="leob-info-lbl"><?php echo xlt('Encounter type'); ?></span>
        <span style="display:flex;align-items:center;gap:.5rem;font-size:.75rem;color:#6c757d">
          <code style="background:#f5f3ff;border:1px solid #ddd6fe;color:#6610f2;border-radius:4px;padding:1px 7px;font-size:.6875rem;font-weight:700">LEGACY BILLING ONLY</code>
          <?php echo xlt('No SOAP · No provider · No appointment'); ?>
        </span>
      </div>
    </div>

    <div class="leob-modal-ftr">
      <button type="button" class="btn btn-sm btn-light" onclick="closeConfirmModal()"><?php echo xlt('Cancel'); ?></button>
      <button type="button" class="btn btn-sm btn-primary" onclick="confirmAndPost()">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:4px"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <?php echo xlt('Create Encounter &amp; Post'); ?>
      </button>
    </div>
  </div>
</div>

<?php $oemr_ui->oeBelowContainerDiv(); ?>
<script src="<?php echo $webroot; ?>/library/js/oeUI/oeFileUploads.js"></script>

<script>
/* ── globals ─────────────────────────────────── */
const CSRF = <?php echo json_encode(CsrfUtils::collectCsrfToken()); ?>;
const XL   = {
  noPatient:   <?php echo json_encode(xlt('Patient, DOS, and Insurance are required.')); ?>,
  noAmt:       <?php echo json_encode(xlt('Insurance Paid or Patient Responsibility amount is required.')); ?>,
  charge:      <?php echo json_encode(xlt('Charge (Auto)')); ?>,
  legEnc:      <?php echo json_encode(xlt('Legacy Encounter Charge')); ?>,
  insPay:      <?php echo json_encode(xlt('Insurance Payment')); ?>,
  payerPay:    <?php echo json_encode(xlt('Payer Payment')); ?>,
  adjWO:       <?php echo json_encode(xlt('Adjustment / Writeoff')); ?>,
  contWO:      <?php echo json_encode(xlt('Contractual Writeoff')); ?>,
  patBal:      <?php echo json_encode(xlt('Patient Balance')); ?>,
  patResp:     <?php echo json_encode(xlt('Patient Responsibility')); ?>,
  otherAdj:    <?php echo json_encode(xlt('Other Adjustment')); ?>,
  otherAdjLbl: <?php echo json_encode(xlt('Other Adjustment')); ?>,
  balOk:       <?php echo json_encode(xlt('Amounts balance')); ?>,
  balDiff:     <?php echo json_encode(xlt('Difference')); ?>
};

let currentPID    = 0;
let currentPatLbl = '';

/* ── mode toggle ─────────────────────────────── */
function setMode(m) {
  document.getElementById('manual-mode').style.display = m === 'manual' ? '' : 'none';
  document.getElementById('era-mode').style.display    = m === 'era'    ? '' : 'none';
  document.getElementById('btn-mode-manual').classList.toggle('active', m === 'manual');
  document.getElementById('btn-mode-era').classList.toggle('active',    m === 'era');
}

/* ── patient search ──────────────────────────── */
let patTimer;
function patientSearch(val) {
  clearTimeout(patTimer);
  const dd = document.getElementById('patient-dropdown');
  if (!val.trim()) { dd.classList.remove('open'); return; }
  patTimer = setTimeout(() => {
    fetch('legacy_eob_posting.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
      body: new URLSearchParams({action:'patient_search', term:val, csrf_token_form:CSRF})
    })
    .then(r => {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.text();
    })
    .then(text => {
      let data;
      try { data = JSON.parse(text); } catch(e) {
        console.error('Patient search response not JSON:', text.substring(0,200));
        dd.innerHTML = '<div class="p-2 text-muted" style="font-size:.8125rem">Search error — check console</div>';
        dd.classList.add('open');
        return;
      }
      if (data.error) {
        dd.innerHTML = '<div class="p-2 text-danger" style="font-size:.8125rem">' + (data.msg || 'Session expired. Reload page.') + '</div>';
        dd.classList.add('open');
        return;
      }
      dd.innerHTML = data.map(p => `
        <div class="leob-patient-opt" onclick='selectPatient(${p.pid}, ${JSON.stringify(p.label)}, ${JSON.stringify(p.name)}, ${JSON.stringify(p.dob)})'>
          <div class="leob-patient-opt-name">${p.name}</div>
          <div class="leob-patient-opt-meta">${p.pubpid} &middot; DOB: ${p.dob}</div>
        </div>`).join('') || '<div class="p-2 text-muted" style="font-size:.8125rem">No patients found</div>';
      dd.classList.add('open');
    })
    .catch(err => {
      console.error('Patient search failed:', err);
      dd.innerHTML = '<div class="p-2 text-muted" style="font-size:.8125rem">Search unavailable</div>';
      dd.classList.add('open');
    });
  }, 280);
}

function selectPatient(pid, label, name, dob) {
  currentPID    = pid;
  currentPatLbl = label;
  document.getElementById('patient-display').value = label;
  document.getElementById('field-pid').value        = pid;
  document.getElementById('patient-dropdown').classList.remove('open');
  checkDOS();
}

document.addEventListener('click', e => {
  if (!e.target.closest('.leob-patient-dropdown') && !e.target.closest('#patient-display')) {
    document.getElementById('patient-dropdown').classList.remove('open');
  }
});

/* ── DOS check ───────────────────────────────── */
function checkDOS() {
  const pid  = parseInt(document.getElementById('field-pid').value) || 0;
  const dos  = document.getElementById('field-dos').value;
  const misEl  = document.getElementById('dos-missing-alert');
  const fndEl  = document.getElementById('dos-found-alert');
  const acRow  = document.getElementById('autocreate-row');

  if (!pid || !dos) {
    misEl.classList.remove('show');
    fndEl.classList.remove('show');
    acRow.classList.remove('show');
    return;
  }

  fetch('legacy_eob_posting.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: new URLSearchParams({action:'check_dos', pid, dos, csrf_token_form:CSRF})
  })
  .then(r => r.text())
  .then(text => {
    let data;
    try { data = JSON.parse(text); } catch(e) {
      console.error('DOS check response not JSON:', text.substring(0,200));
      return;
    }
    if (data.error) { console.warn('DOS check:', data.msg); return; }
    if (data.found) {
      misEl.classList.remove('show');
      acRow.classList.remove('show');
      fndEl.classList.add('show');
      document.getElementById('dos-found-date').textContent = dos;
    } else {
      fndEl.classList.remove('show');
      misEl.classList.add('show');
      acRow.classList.add('show');
    }
    updatePreview();
  })
  .catch(err => console.error('DOS check failed:', err));
}

/* ── preview ─────────────────────────────────── */
function fmt(n) { return '$' + (parseFloat(n)||0).toFixed(2); }

function updatePreview() {
  const dos  = document.getElementById('field-dos').value;
  const orig = parseFloat(document.getElementById('f-orig-charge').value)  || 0;
  const ins  = parseFloat(document.getElementById('f-ins-paid').value)      || 0;
  const cAdj = parseFloat(document.getElementById('f-contract-adj').value) || 0;
  const pat  = parseFloat(document.getElementById('f-pat-resp').value)      || 0;
  const oth  = parseFloat(document.getElementById('f-other-adj').value)     || 0;
  const dateStr = dos || '—';

  const rows = [];
  if (orig > 0) rows.push({ badge:'charge',  bclass:'leob-badge-charge',  type:XL.charge,  desc:XL.legEnc,  date:dateStr, amt:orig,  aclass:'leob-amount-pos' });
  if (ins  > 0) rows.push({ badge:'payment', bclass:'leob-badge-payment', type:XL.insPay,  desc:XL.payerPay,date:dateStr, amt:-ins,  aclass:'leob-amount-neg' });
  if (cAdj > 0) rows.push({ badge:'adj',     bclass:'leob-badge-adj',     type:XL.adjWO,   desc:XL.contWO,  date:dateStr, amt:-cAdj, aclass:'leob-amount-neg' });
  if (pat  > 0) rows.push({ badge:'patient', bclass:'leob-badge-patient', type:XL.patBal,  desc:XL.patResp, date:dateStr, amt:pat,   aclass:'leob-amount-pat' });
  if (oth  > 0) rows.push({ badge:'adj',     bclass:'leob-badge-adj',     type:XL.otherAdj,desc:XL.otherAdjLbl,date:dateStr,amt:-oth, aclass:'leob-amount-neg' });

  const tbody = document.getElementById('preview-tbody');
  if (!rows.length) {
    tbody.innerHTML = `<tr><td colspan="4" class="text-muted text-center py-3" style="font-size:.8125rem">Fill in amounts above to see posting preview.</td></tr>`;
    document.getElementById('resulting-balance').textContent = '$0.00';
    document.getElementById('balance-badge').style.display = 'none';
    return;
  }

  tbody.innerHTML = rows.map(r => `
    <tr>
      <td><span class="leob-badge ${r.bclass}"><span style="width:6px;height:6px;border-radius:50%;background:currentColor;display:inline-block"></span>${r.type}</span></td>
      <td style="color:#6c757d">${r.desc}</td>
      <td style="font-family:'Courier New',monospace;font-size:.8125rem;color:#6c757d">${r.date}</td>
      <td class="${r.aclass}">${r.amt < 0 ? '- ' + fmt(Math.abs(r.amt)) : fmt(r.amt)}</td>
    </tr>`).join('');

  document.getElementById('resulting-balance').textContent = fmt(pat);

  // Balance check
  const bb = document.getElementById('balance-badge');
  if (orig > 0) {
    const diff = Math.abs(orig - (ins + cAdj + pat + oth));
    const ok   = diff < 0.01;
    bb.style.display    = 'inline-flex';
    bb.className        = 'leob-check-badge ' + (ok ? 'leob-check-ok' : 'leob-check-warn');
    bb.innerHTML        = ok
      ? `<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>${XL.balOk}`
      : `<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>${XL.balDiff}: $${diff.toFixed(2)}`;
  } else {
    bb.style.display = 'none';
  }
}

/* ── submit ──────────────────────────────────── */
function submitManualForm() {
  const pid  = document.getElementById('field-pid').value;
  const dos  = document.getElementById('field-dos').value;
  const pay  = document.getElementById('field-payer').value;
  if (!pid || !dos || !pay) { alert(XL.noPatient); return; }
  const ins  = parseFloat(document.getElementById('f-ins-paid').value) || 0;
  const pat  = parseFloat(document.getElementById('f-pat-resp').value) || 0;
  if (!ins && !pat) { alert(XL.noAmt); return; }

  // Check DOS via AJAX; if missing → show confirm modal
  fetch('legacy_eob_posting.php', {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: new URLSearchParams({action:'check_dos', pid, dos, csrf_token_form:CSRF})
  })
  .then(r => r.text())
  .then(text => {
    let d;
    try { d = JSON.parse(text); } catch(e) {
      console.error('submit check_dos not JSON:', text.substring(0,200));
      // Fall back to direct form submit — let PHP decide
      document.getElementById('manual-form').submit();
      return;
    }
    if (!d.found) {
      const payTxt = document.getElementById('field-payer').options[document.getElementById('field-payer').selectedIndex]?.text || '';
      document.getElementById('modal-patient').value   = document.getElementById('patient-display').value;
      document.getElementById('modal-dos').value       = dos;
      document.getElementById('modal-insurance').value = payTxt;
      document.getElementById('modal-claim').value     = document.querySelector('[name=check_num]').value;
      document.getElementById('modal-charge').textContent = fmt(parseFloat(document.getElementById('f-orig-charge').value)||0);
      document.getElementById('confirm-modal').classList.add('open');
    } else {
      document.getElementById('manual-form').submit();
    }
  })
  .catch(err => {
    console.error('submit check_dos failed:', err);
    document.getElementById('manual-form').submit();
  });
}

function confirmAndPost() {
  document.getElementById('field-confirmed').value = '1';
  closeConfirmModal();
  document.getElementById('manual-form').submit();
}

function closeConfirmModal() {
  document.getElementById('confirm-modal').classList.remove('open');
}
document.getElementById('confirm-modal').addEventListener('click', function(e){ if(e.target===this) closeConfirmModal(); });

function clearManualForm() {
  ['f-orig-charge','f-ins-paid','f-contract-adj','f-pat-resp','f-other-adj'].forEach(id => {
    document.getElementById(id).value = '';
  });
  document.querySelector('[name=check_num]').value = '';
  document.querySelector('[name=notes]').value = 'Legacy EOB from prior system';
  updatePreview();
}

/* ── ERA upload ──────────────────────────────── */
let eraClaimsData = [];

document.getElementById('era-file-input').addEventListener('change', function() {
  if (this.files[0]) handleERA(this.files[0]);
});
const dz = document.getElementById('era-dropzone');
dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('drag-over'); });
dz.addEventListener('dragleave', ()  => dz.classList.remove('drag-over'));
dz.addEventListener('drop', e => {
  e.preventDefault(); dz.classList.remove('drag-over');
  if (e.dataTransfer.files[0]) handleERA(e.dataTransfer.files[0]);
});

function handleERA(file) {
  if (!file) return;

  // Show loaded pill
  document.getElementById('era-loaded').classList.remove('d-none');
  document.getElementById('era-loaded').classList.add('d-flex');
  document.getElementById('era-filename').textContent = file.name;
  document.getElementById('era-meta').textContent     = (file.size/1024).toFixed(1) + ' KB';
  document.getElementById('era-details-card').classList.remove('d-none');
  document.getElementById('era-dropzone').style.display = 'none';
  document.getElementById('era-claims-wrap').style.display  = 'none';
  document.getElementById('era-parse-spinner').style.display = 'block';
  document.getElementById('era-initial-actions').style.display = 'none';

  // Send file to PHP for full server-side parsing
  const fd = new FormData();
  fd.append('csrf_token_form', CSRF);
  fd.append('action', 'parse_era_preview');
  fd.append('era_preview_file', file, file.name);

  fetch('legacy_eob_posting.php', {
    method: 'POST',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    body: fd
  })
  .then(r => r.text())
  .then(text => {
    document.getElementById('era-parse-spinner').style.display = 'none';
    let data;
    try { data = JSON.parse(text); } catch(e) {
      alert('Parse error: ' + text.substring(0,200));
      return;
    }

    // Populate header fields
    document.getElementById('era-check-date').value = data.check_date || '';
    document.getElementById('era-check-num').value  = data.check_num  || '';
    document.getElementById('era-payer-name').value = data.payer_name || '';

    // Auto-select payer in dropdown by partial name match
    const sel = document.getElementById('era-payer-select');
    if (data.payer_name) {
      const needle = data.payer_name.toLowerCase();
      for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].text.toLowerCase().includes(needle)) {
          sel.selectedIndex = i; break;
        }
      }
    }

    // Build claims table
    eraClaimsData = data.claims || [];
    renderERAClaimsTable(eraClaimsData);
    document.getElementById('era-claims-wrap').style.display = '';
    document.getElementById('era-initial-actions').style.display = '';

    const unmatched = eraClaimsData.filter(c => !c.matched).length;
    document.getElementById('era-match-summary').textContent =
      eraClaimsData.length + ' claims · ' +
      (eraClaimsData.length - unmatched) + ' matched · ' +
      (unmatched > 0 ? unmatched + ' need patient assignment' : 'all matched ✓');
  })
  .catch(err => {
    document.getElementById('era-parse-spinner').style.display = 'none';
    document.getElementById('era-initial-actions').style.display = '';
    console.error('ERA parse failed:', err);
    alert('ERA parse failed. Check console for details.');
  });
}

function renderERAClaimsTable(claims) {
  const tbody = document.getElementById('era-claims-tbody');
  tbody.innerHTML = claims.map((c, i) => {
    const matchedHtml = c.matched
      ? `<span style="color:#16a34a;font-size:.75rem">✓ ${escHtml(c.pat_label)}</span>`
      : `<div class="position-relative era-pat-search-wrap">
           <input type="text" class="form-control form-control-sm era-pat-input"
                  data-idx="${i}" placeholder="Search patient…"
                  oninput="eraPatSearch(this)" autocomplete="off"
                  value="${c.pat_label ? escHtml(c.pat_label) : ''}">
           <div class="era-pat-dropdown" id="era-pat-dd-${i}"></div>
         </div>`;

    const status = c.matched
      ? '<span class="badge badge-success" style="font-size:.7rem">Matched</span>'
      : '<span class="badge badge-warning" style="font-size:.7rem">Unmatched</span>';

    return `<tr id="era-row-${i}">
      <td><input type="checkbox" class="era-row-check" data-idx="${i}" checked></td>
      <td style="font-family:monospace;font-size:.75rem">${escHtml(c.claim_num)}</td>
      <td style="font-size:.75rem;color:#6c757d">${escHtml((c.era_lname||'') + (c.era_fname ? ', '+c.era_fname : ''))}</td>
      <td style="font-family:monospace;font-size:.75rem">${escHtml(c.dos)}</td>
      <td style="font-family:monospace;font-size:.75rem">$${parseFloat(c.orig_charge).toFixed(2)}</td>
      <td style="font-family:monospace;font-size:.75rem;color:#16a34a">$${parseFloat(c.ins_paid).toFixed(2)}</td>
      <td style="font-family:monospace;font-size:.75rem;color:#dc3545">$${parseFloat(c.contract_adj||0).toFixed(2)}</td>
      <td style="font-family:monospace;font-size:.75rem;color:#d97706">$${parseFloat(c.pat_resp||0).toFixed(2)}</td>
      <td>${matchedHtml}</td>
      <td>${status}</td>
    </tr>`;
  }).join('');
}

/* ERA patient search in table rows */
let eraPatTimers = {};
function eraPatSearch(input) {
  const idx = input.dataset.idx;
  clearTimeout(eraPatTimers[idx]);
  const dd = document.getElementById('era-pat-dd-' + idx);
  const val = input.value.trim();
  if (!val) { dd.style.display = 'none'; return; }
  eraPatTimers[idx] = setTimeout(() => {
    fetch('legacy_eob_posting.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
      body: new URLSearchParams({action:'patient_search', term:val, csrf_token_form:CSRF})
    })
    .then(r => r.text())
    .then(text => {
      let data; try { data = JSON.parse(text); } catch(e) { return; }
      if (!Array.isArray(data) || !data.length) { dd.style.display = 'none'; return; }
      dd.innerHTML = data.map(p =>
        `<div class="era-pat-opt" onclick="selectERAPat(${idx}, ${p.pid}, ${JSON.stringify(p.label)})">
           <div style="font-size:.8125rem;font-weight:600">${escHtml(p.name)}</div>
           <div style="font-size:.75rem;color:#6c757d;font-family:monospace">${escHtml(p.pubpid)} · DOB: ${escHtml(p.dob)}</div>
         </div>`).join('');
      dd.style.display = 'block';
    });
  }, 280);
}

function selectERAPat(idx, pid, label) {
  eraClaimsData[idx].pid       = pid;
  eraClaimsData[idx].pat_label = label;
  eraClaimsData[idx].matched   = true;
  renderERAClaimsTable(eraClaimsData);
  // Re-count unmatched
  const unmatched = eraClaimsData.filter(c => !c.matched).length;
  document.getElementById('era-match-summary').textContent =
    eraClaimsData.length + ' claims · ' +
    (eraClaimsData.length - unmatched) + ' matched · ' +
    (unmatched > 0 ? unmatched + ' need patient assignment' : 'all matched ✓');
}

/* Select-all checkbox */
document.addEventListener('change', e => {
  if (e.target.id === 'era-check-all') {
    document.querySelectorAll('.era-row-check').forEach(cb => cb.checked = e.target.checked);
  }
});

/* Post reviewed claims */
function postERAReviewed() {
  const payerId = document.getElementById('era-payer-select').value;
  if (!payerId) { showERAResult('error', 'Please select the payer before posting.'); return; }

  const checkNum = document.getElementById('era-check-num').value;

  // Collect only checked + matched rows
  const toPost = [];
  document.querySelectorAll('.era-row-check').forEach(cb => {
    if (!cb.checked) return;
    const i = parseInt(cb.dataset.idx);
    const c = eraClaimsData[i];
    if (!c || !c.pid) return; // skip unmatched
    toPost.push(c);
  });

  if (!toPost.length) { showERAResult('warning', 'No matched claims are selected. Assign patients to unmatched claims first.'); return; }

  const btn = document.getElementById('era-post-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm mr-1" style="width:.75rem;height:.75rem"></span> Posting…';

  fetch('legacy_eob_posting.php', {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
    body: new URLSearchParams({
      action:          'post_era_reviewed',
      claims:          JSON.stringify(toPost),
      check_num:       checkNum,
      payer_id:        payerId,
      csrf_token_form: CSRF
    })
  })
  .then(r => r.text())
  .then(text => {
    btn.disabled = false;
    btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg><?php echo xlt("Post Selected Claims"); ?>';

    let d;
    try { d = JSON.parse(text); }
    catch(e) { showERAResult('error', 'Unexpected server response: ' + text.substring(0, 150)); return; }

    showERAResult(d.type || 'error', d.msg || 'Unknown error');

    if (d.type === 'success') {
      // Clear the file/table but keep the result banner visible
      
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:4px"><polyline points="20 6 9 17 4 12"/></svg><?php echo xlt("Post Selected Claims"); ?>';
    showERAResult('error', 'Network error. Please try again.');
    console.error('ERA post failed:', err);
  });
}

function showERAResult(type, msg) {
  const el  = document.getElementById('era-post-result');
  const cls = type === 'success' ? 'alert-success' : type === 'warning' ? 'alert-warning' : 'alert-danger';
  const icon = type === 'success'
    ? '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" class="mr-2 flex-shrink-0"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>'
    : '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="mr-2 flex-shrink-0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
  el.className     = 'alert ' + cls + ' d-flex align-items-center mt-3';
  el.style.display = '';
  el.innerHTML     = icon
    + '<span style="flex:1">' + escHtml(msg) + '</span>'
    + '<button type="button" class="close ml-2" style="font-size:1.1rem;line-height:1" onclick="this.closest(\'.alert\').style.display=\'none\'">&times;</button>';
  el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

// Clear file/table after success but leave the result banner in place
function clearERAKeepResult() {
  document.getElementById('era-dropzone').style.display   = '';
  document.getElementById('era-loaded').classList.add('d-none');
  document.getElementById('era-loaded').classList.remove('d-flex');
  document.getElementById('era-details-card').classList.add('d-none');
  document.getElementById('era-claims-wrap').style.display    = 'none';
  document.getElementById('era-parse-spinner').style.display  = 'none';
  document.getElementById('era-initial-actions').style.display = '';
  document.getElementById('era-file-input').value = '';
  eraClaimsData = [];
  ['era-check-date','era-check-num','era-payer-name'].forEach(id => {
    const el = document.getElementById(id);
    if (el) { el.value = ''; el.readOnly = false; el.style.background = ''; }
  });
  document.getElementById('era-payer-select').selectedIndex = 0;
  const ab = document.getElementById('era-auto-badge');
  if (ab) ab.classList.add('d-none');
  const db = document.getElementById('era-detect-banner');
  if (db) { db.classList.add('d-none'); db.classList.remove('d-flex'); }
  // era-post-result is intentionally NOT cleared here
}

function clearERA() {
  clearERAKeepResult();
  // Manual clear also resets the result banner
  const res = document.getElementById('era-post-result');
  if (res) { res.style.display = 'none'; res.innerHTML = ''; }
}

function escHtml(s) {
  if (!s) return '';
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Init
updatePreview();
</script>
<style>
.era-pat-search-wrap { position:relative; }
.era-pat-dropdown {
  display:none; position:absolute; top:100%; left:0; right:0; z-index:300;
  background:#fff; border:1px solid #dee2e6; border-radius:.375rem;
  box-shadow:0 4px 12px rgba(0,0,0,.1); max-height:200px; overflow-y:auto;
}
.era-pat-opt { padding:.5rem .75rem; cursor:pointer; border-bottom:1px solid #f1f5f9; }
.era-pat-opt:last-child { border-bottom:none; }
.era-pat-opt:hover { background:#f5f3ff; }
</style>
</body>
</html>
