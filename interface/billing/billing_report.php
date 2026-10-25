<?php
// Buffer output from the very first line so that any HTML output from globals.php
// (session redirects, error pages) can be discarded for AJAX requests.
ob_start();

/**
 * Billing Report Program
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Terry Hill <terry@lilysystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Sherwin Gaddis <sherwingaddis@gmail.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2016 Terry Hill <terry@lillysystems.com>
 * @copyright Copyright (c) 2017-2020 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018-2020 Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2019-2020 Sherwin Gaddis <sherwingaddis@gmail.com>
 * @copyright Copyright (c) 2021 Stephen Waite <stephen.waite@cmsvt.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once "../globals.php";
require_once "../../custom/code_types.inc.php";
require_once "$srcdir/patient.inc.php";
require_once "$srcdir/options.inc.php";

use OpenEMR\Billing\BillingReport;
use OpenEMR\Billing\BillingUtilities;
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

//ensure user has proper access
if (!AclMain::aclCheckCore('acct', 'eob', '', 'write') && !AclMain::aclCheckCore('acct', 'bill', '', 'write')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Billing Manager")]);
    exit;
}

// ── Inline patient-search AJAX handler ──────────────────────────────────────
// Called by the autocomplete: billing_report.php?bm_patient_search=1&q=smith
if (!empty($_GET['bm_patient_search'])) {
    while (ob_get_level() > 0) { ob_get_clean(); }
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo '[]'; exit; }
    $like = '%' . $q . '%';
    $rows = sqlStatement(
        "SELECT pid, pubpid, fname, lname
           FROM patient_data
          WHERE (lname LIKE ? OR fname LIKE ? OR CONCAT(lname,' ',fname) LIKE ? OR CONCAT(fname,' ',lname) LIKE ?)
          ORDER BY lname, fname
          LIMIT 20",
        [$like, $like, $like, $like]
    );
    $out = [];
    while ($r = sqlFetchArray($rows)) {
        $out[] = [
            'pid'    => $r['pid'],
            'pubpid' => $r['pubpid'],
            'fname'  => $r['fname'],
            'lname'  => $r['lname'],
        ];
    }
    echo json_encode($out);
    exit;
}
// ── End inline patient-search handler ───────────────────────────────────────

$EXPORT_INC = "$webserver_root/custom/BillingExport.php";
// echo $GLOBALS['daysheet_provider_totals'];

$daysheet = false;
$daysheet_total = false;
$provider_run = false;

if ($GLOBALS['use_custom_daysheet'] != 0) {
    $daysheet = true;
    if ($GLOBALS['daysheet_provider_totals'] == 1) {
        $daysheet_total = true;
        $provider_run = false;
    }
    if ($GLOBALS['daysheet_provider_totals'] == 0) {
        $daysheet_total = false;
        $provider_run = true;
    }
}

$alertmsg = '';

// ── CSRF token refresh endpoint ──────────────────────────────────────────────
// Called by bmRefreshCsrf() before any form submit to ensure token is fresh.
if (!empty($_GET['bm_csrf_refresh'])) {
    while (ob_get_level() > 0) { ob_get_clean(); }
    header('Content-Type: application/json');
    echo json_encode(['csrf_token_form' => CsrfUtils::collectCsrfToken()]);
    exit;
}

// ── Safe Reopen + Reactivate selected encounters ───────────────────────────
if (!empty($_POST['bm_reopen_reactivate'])) {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $raw = trim($_POST['bm_reopen_reactivate_ids'] ?? '');
    $pairs = array_filter(array_map('trim', explode(',', $raw)));
    $updated = 0;
    foreach ($pairs as $pair) {
        $parts = explode('-', $pair, 2);
        if (count($parts) !== 2) { continue; }
        $pid = (int)$parts[0];
        $enc = (int)$parts[1];
        if ($pid <= 0 || $enc <= 0) { continue; }
        sqlStatement(
            "UPDATE billing
                SET activity = 1,
                    billed = 0,
                    bill_process = 0,
                    bill_date = NULL,
                    process_date = NULL,
                    process_file = NULL,
                    payer_id = NULL,
                    target = NULL
              WHERE pid = ?
                AND encounter = ?
                AND code_type != 'COPAY'",
            [$pid, $enc]
        );
        $updated++;
    }
    // Output a minimal result page for the popup window.
    // The popup shows success and closes itself; the opener reloads.
    ob_end_clean();
    $msg = xl("Reopened and reactivated") . " " . $updated . " " . xl("encounter(s).");
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
       . '<style>body{font-family:"Segoe UI",sans-serif;display:flex;align-items:center;'
       . 'justify-content:center;height:100vh;margin:0;background:#f0fdf4;}'
       . '.box{text-align:center;padding:30px 40px;background:#fff;border-radius:12px;'
       . 'box-shadow:0 4px 20px rgba(0,0,0,.1);border-top:4px solid #16a34a;}'
       . '.icon{font-size:36px;margin-bottom:10px;}'
       . '.msg{font-size:15px;font-weight:600;color:#15803d;margin-bottom:16px;}'
       . 'button{padding:8px 20px;background:#5b21b6;color:#fff;border:none;'
       . 'border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;}'
       . '</style></head><body>'
       . '<div class="box">'
       . '<div class="icon">&#9989;</div>'
       . '<div class="msg">' . text($msg) . '</div>'
       . '<button onclick="window.opener && window.opener.location.reload(); window.close();">'
       . text(xl('OK — Reload Billing Manager')) . '</button>'
       . '</div></body></html>';
    exit;
}

// ── Billing output capture for styled display page ──────────────────────────
// ── Comprehensive Claim Validation AJAX Handler ──────────────────────────────
// Called by bmRunValidation() JS. Runs 5-layer validation and returns JSON.
// ── Claim Validation AJAX Handler - chunked to prevent timeouts ─────────────
// Phase 1: bm_validate_count  → returns total encounter count + filter summary
// Phase 2: bm_validate_batch  → validates one page of encounters (BATCH_SIZE each)
// Phase 3: bm_validate_x12   → validates an already-generated X12 string against actual emitted segments
// JS aggregates all batches client-side, no single request can timeout.
define('BV_BATCH_SIZE', 20); // encounters per batch - keep small to stay under 5s

// ── Phase 3: X12 segment validator ───────────────────────────────────────────
// Called after Preview X12 is generated. Validates the ACTUAL emitted X12 string,
// not the database — so it can never produce false positives.
// POST body: bm_validate_x12=1, x12_content=<raw 837 text>
if (!empty($_POST['bm_validate_x12'])) {
    error_reporting(0); ini_set('display_errors', '0');
    // Drain ALL output buffer levels before sending JSON
    while (ob_get_level() > 0) { ob_get_clean(); }
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    $bv3_token = $_POST['csrf_token_form'] ?? $_POST['bm_csrf_hidden'] ?? '';
    if (!CsrfUtils::verifyCsrfToken($bv3_token)) {
        echo json_encode(['error' => 'CSRF mismatch']); exit;
    }

    $x12 = $_POST['x12_content'] ?? '';
    if (empty(trim($x12))) {
        echo json_encode(['error' => 'No X12 content supplied']); exit;
    }

    // Parse all transaction sets out of the X12 string
    // Each ST…SE block is one claim
    $st_blocks = [];
    preg_match_all('/ST\*837[^~]*~(.*?)SE\*\d+/s', $x12, $m, PREG_SET_ORDER);
    foreach ($m as $mm) { $st_blocks[] = $mm[0] . 'SE'; }
    $total_claims = count($st_blocks);

    $x12_errors   = [];
    $x12_warnings = [];

    foreach ($st_blocks as $idx => $block) {
        $clm_num = '';
        if (preg_match('/CLM\*([^*]+)\*/', $block, $cm)) { $clm_num = $cm[1]; }
        $label   = $clm_num ?: ('TX ' . ($idx + 1));

        // ── NM1*PR: Payer ID ─────────────────────────────────────────────────
        // Flag only when PI qualifier is MISSING — not when the payer name exists.
        if (!preg_match('/NM1\*PR\*[12]\*[^*]*\*[^*]*\*[^*]*\*[^*]*\*[^*]*\*PI\*[^~]+/', $block)) {
            $x12_errors[] = ['loop'=>'2010BB','segment'=>'NM1*PR','claim'=>$label,
                'message'=>'Payer ID (NM1*PR PI qualifier) missing or empty in emitted X12'];
        }

        // ── NM1*IL: Subscriber name ───────────────────────────────────────────
        if (!preg_match('/NM1\*IL\*/', $block)) {
            $x12_errors[] = ['loop'=>'2010BA','segment'=>'NM1*IL','claim'=>$label,
                'message'=>'Subscriber name segment (NM1*IL) missing'];
        }

        // ── NM1*82: Rendering provider ───────────────────────────────────────
        if (!preg_match('/NM1\*82\*/', $block)) {
            $x12_warnings[] = ['loop'=>'2310B','segment'=>'NM1*82','claim'=>$label,
                'message'=>'Rendering provider (NM1*82) missing'];
        }

        // ── PRV*PE: Rendering taxonomy ───────────────────────────────────────
        if (!preg_match('/PRV\*PE\*/', $block)) {
            $x12_warnings[] = ['loop'=>'2310B','segment'=>'PRV*PE','claim'=>$label,
                'message'=>'Rendering provider taxonomy (PRV*PE) missing'];
        }

        // ── HI: Diagnosis codes ───────────────────────────────────────────────
        if (!preg_match('/HI\*[^~]+~/', $block)) {
            $x12_errors[] = ['loop'=>'2300','segment'=>'HI','claim'=>$label,
                'message'=>'Diagnosis segment (HI) missing'];
        }

        // ── SV1: Service line ─────────────────────────────────────────────────
        if (!preg_match('/SV1\*/', $block)) {
            $x12_errors[] = ['loop'=>'2400','segment'=>'SV1','claim'=>$label,
                'message'=>'Service line (SV1) missing'];
        } else {
            // SV1*HC:XXXXX*CHARGE*UN*UNITS — check charge and units
            if (preg_match('/SV1\*[^*]+\*([0-9.]+)\*[A-Z]+\*([0-9]+)/', $block, $sv)) {
                if ((float)$sv[1] == 0 && (int)$sv[2] == 0) {
                    $x12_warnings[] = ['loop'=>'2400','segment'=>'SV1','claim'=>$label,
                        'message'=>'SV1 charge and units are both 0'];
                }
            }
        }

        // ── DTP*472: Date of service ─────────────────────────────────────────
        if (!preg_match('/DTP\*472\*/', $block)) {
            $x12_errors[] = ['loop'=>'2400','segment'=>'DTP*472','claim'=>$label,
                'message'=>'Date of service (DTP*472) missing'];
        }

        // ── SBR: Subscriber/insurance ────────────────────────────────────────
        if (!preg_match('/SBR\*/', $block)) {
            $x12_errors[] = ['loop'=>'2000B','segment'=>'SBR','claim'=>$label,
                'message'=>'Insurance subscriber (SBR) segment missing'];
        }

        // ── CLM: Claim totals ─────────────────────────────────────────────────
        if (preg_match('/CLM\*[^*]+\*([0-9.]+)\*/', $block, $cm2)) {
            if ((float)$cm2[1] == 0) {
                $x12_warnings[] = ['loop'=>'2300','segment'=>'CLM','claim'=>$label,
                    'message'=>'CLM total charge is $0.00'];
            }
        }
    }

    $score = $total_claims > 0
        ? max(0, 100 - intval((count($x12_errors) / $total_claims) * 100) - intval((count($x12_warnings) / $total_claims) * 20))
        : 100;

    echo json_encode([
        'total_claims'  => $total_claims,
        'errors'        => $x12_errors,
        'warnings'      => $x12_warnings,
        'error_count'   => count($x12_errors),
        'warning_count' => count($x12_warnings),
        'score'         => $score,
        'timestamp'     => date('M j, Y g:i A'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}


if (!empty($_POST['bm_validate_ajax']) || !empty($_POST['bm_validate_count']) || !empty($_POST['bm_validate_batch'])) {
    error_reporting(0);
    ini_set('display_errors', '0');
    // Drain ALL output buffer levels before we set our own headers.
    // globals.php opens nested ob levels internally; a single ob_get_clean()
    // only pops the top level and leaves HTML from deeper levels intact,
    // which then gets prepended to our JSON response and breaks JSON.parse().
    while (ob_get_level() > 0) { ob_get_clean(); }
    ob_start(); // open a single fresh clean level that bv_emit will manage
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    // Helper: discard buffered HTML, output clean JSON, exit.
    // OpenEMR's sqlStatement echoes HTML on error rather than throwing,
    // so we always strip the buffer before sending.
    $bv_emit = function($json_str) {
        // Drain ALL output buffer levels.
        // globals.php may open its own nested ob levels internally, so a single
        // ob_get_clean() may leave HTML from deeper levels intact.
        // We pop every level until the stack is empty.
        $buf = '';
        while (ob_get_level() > 0) {
            $buf .= ob_get_clean();
        }
        $buf = trim($buf);
        if (empty($buf)) {
            echo $json_str;
        } else {
            // Buffer had HTML content (sqlStatement error, session redirect, etc.)
            $decoded = json_decode($json_str, true);
            if ($decoded && isset($decoded['error'])) {
                echo $json_str; // already an error JSON, prefer it
            } else {
                $msg = trim(strip_tags($buf));
                $msg = $msg ?: 'Validation query failed.';
                echo json_encode(['error' => substr($msg, 0, 300), 'valid' => 0, 'total' => 0,
                    'errors' => [], 'warnings' => [], 'insights' => [],
                    'score' => 0, 'timestamp' => date('M j, Y g:i A')]);
            }
        }
    };

    // CSRF - accept from either field
    $bv_token = $_POST['csrf_token_form'] ?? $_POST['bm_csrf_hidden'] ?? '';
    if (!CsrfUtils::verifyCsrfToken($bv_token)) {
        $bv_emit(json_encode(['error' => 'CSRF mismatch - please refresh the page.'])); exit;
    }

    // ── Shared filter builder ─────────────────────────────────────────────────
    // This is used ONLY when no specific claim IDs are passed (i.e. list not loaded yet).
    // When bm_enc_ids IS passed (pid-encounter pairs from visible rows), the filter is bypassed.
    // bm_billed is included so validation scope matches what the user is looking at.
    function bv_base_where(&$params) {
        $v_billed    = $_POST['bm_billed']    ?? '';   // '' = All, '0' = Unbilled, '1' = Billed
        $v_submitter = (int)($_POST['bm_submitter'] ?? 0);
        $v_ins       = (int)($_POST['bm_insurance']  ?? 0);
        $v_patient   = trim($_POST['bm_patient']     ?? '');
        $v_from      = trim($_POST['bm_bill_from']   ?? '');
        $v_to        = trim($_POST['bm_bill_to']     ?? '');
        $where = " WHERE b.activity = 1 AND b.code_type != 'COPAY'";
        if ($v_billed !== '')  { $where .= " AND b.billed = ?";                   $params[] = $v_billed; }
        if ($v_submitter)      { $where .= " AND b.x12_partner_id = ?";           $params[] = $v_submitter; }
        if ($v_ins)            { $where .= " AND ins.provider = ?";               $params[] = $v_ins; }
        if ($v_patient)        { $where .= " AND CONCAT(pd.lname,', ',pd.fname) LIKE ?"; $params[] = "%$v_patient%"; }
        if ($v_from)           { $where .= " AND b.bill_date >= ?";               $params[] = $v_from; }
        if ($v_to)             { $where .= " AND b.bill_date <= ?";               $params[] = $v_to; }
        return $where;
    }

    try {

    // ── PHASE 1: COUNT ────────────────────────────────────────────────────────
    if (!empty($_POST['bm_validate_count']) || !empty($_POST['bm_validate_ajax'])) {
        $p = [];
        $w = bv_base_where($p);
        // If visible row encounter IDs were passed (pid-encounter pairs), count only those
        $enc_id_pairs = [];
        if (!empty($_POST['bm_enc_ids'])) {
            foreach (explode(',', $_POST['bm_enc_ids']) as $pair) {
                $parts = explode('-', trim($pair), 2);
                if (count($parts) === 2) {
                    $pid = (int)$parts[0]; $enc = (int)$parts[1];
                    if ($pid > 0 && $enc > 0) $enc_id_pairs[] = [$pid, $enc];
                }
            }
        }
        if (!empty($enc_id_pairs)) {
            // Count exactly the encounters visible in the list
            $or_clauses = array_map(function($pair){ return "(b.pid=" . $pair[0] . " AND b.encounter=" . $pair[1] . ")"; }, $enc_id_pairs);
            $cnt_row = sqlQuery("SELECT COUNT(DISTINCT CONCAT(b.encounter,'-',b.pid)) AS cnt
                                 FROM billing b WHERE b.activity = 1 AND (" . implode(' OR ', $or_clauses) . ")");
        } else {
            $count_sql = "SELECT COUNT(DISTINCT CONCAT(b.encounter,'-',b.pid)) AS cnt
                          FROM billing b
                          LEFT JOIN patient_data pd   ON pd.pid = b.pid
                          LEFT JOIN insurance_data ins ON ins.pid = b.pid AND ins.type = 'primary'
                          $w";
            $cnt_row = sqlQuery($count_sql, $p);
        }
        $total   = (int)($cnt_row['cnt'] ?? 0);
        $batches = $total > 0 ? (int)ceil($total / BV_BATCH_SIZE) : 0;
        $bv_emit(json_encode([
            'phase'   => 'count',
            'total'   => $total,
            'batches' => $batches,
            'batch_size' => BV_BATCH_SIZE,
        ])); exit;
    }

    // ── PHASE 2: BATCH VALIDATE ───────────────────────────────────────────────
    if (!empty($_POST['bm_validate_batch'])) {
        $offset = max(0, (int)($_POST['bm_batch_offset'] ?? 0));
        $p = [];
        $w = bv_base_where($p);

        // If visible encounter IDs were passed (pid-encounter pairs), use those instead of filter
        $enc_id_pairs_batch = [];
        if (!empty($_POST['bm_enc_ids'])) {
            foreach (explode(',', $_POST['bm_enc_ids']) as $pair) {
                $parts = explode('-', trim($pair), 2);
                if (count($parts) === 2) {
                    $pid = (int)$parts[0]; $enc = (int)$parts[1];
                    if ($pid > 0 && $enc > 0) $enc_id_pairs_batch[] = [$pid, $enc];
                }
            }
        }

        // Get this page of distinct encounter+pid combos
        if (!empty($enc_id_pairs_batch)) {
            // Page through the provided encounter list directly (already distinct)
            $page_pairs = array_slice($enc_id_pairs_batch, $offset, BV_BATCH_SIZE);
            // Build a query to get these specific encounters
            $or_clauses_b = array_map(function($pair){ return "(b.pid=" . $pair[0] . " AND b.encounter=" . $pair[1] . ")"; }, $page_pairs);
            $enc_sql = "SELECT DISTINCT b.encounter, b.pid
                        FROM billing b
                        WHERE b.activity = 1 AND (" . implode(' OR ', $or_clauses_b) . ")
                        ORDER BY b.encounter";
            $enc_res = sqlStatement($enc_sql);
        } else {
            $enc_sql = "SELECT DISTINCT b.encounter, b.pid
                        FROM billing b
                        LEFT JOIN patient_data pd    ON pd.pid = b.pid
                        LEFT JOIN insurance_data ins  ON ins.pid = b.pid AND ins.type = 'primary'
                        $w
                        ORDER BY b.encounter
                        LIMIT " . BV_BATCH_SIZE . " OFFSET $offset";
            $enc_res = sqlStatement($enc_sql, $p);
        }
        $enc_pairs = [];
        while ($er = sqlFetchArray($enc_res)) {
            $enc_pairs[] = [(int)$er['encounter'], (int)$er['pid']];
        }

        if (empty($enc_pairs)) {
            $bv_emit(json_encode(['phase' => 'batch', 'offset' => $offset, 'rows' => [], 'done' => true])); exit;
        }

        // Build IN clause for these encounters — cast to int to prevent SQL injection
        $in_enc = implode(',', array_map('intval', array_column($enc_pairs, 0)));
        $in_pid = implode(',', array_unique(array_map('intval', array_column($enc_pairs, 1))));

        // Full detail query - only on this batch's encounters (fast, small set)
        // NOTE: We fetch ALL primary insurance rows per patient (no date filter in SQL)
        // and resolve the correct one in PHP. This avoids MySQL version-specific issues
        // with DATE() comparisons on date_end columns that may contain '' or '0000-00-00'.
        $detail_sql = "SELECT b.id, b.pid, b.encounter, b.code, b.code_type, b.modifier,
                              b.fee, b.units, b.justify, b.payer_id,
                              b.provider_id, b.billed, b.activity,
                              fe.date as enc_date, fe.reason as enc_reason,
                              pd.fname, pd.lname, pd.DOB, pd.sex,
                              pd.street, pd.city, pd.state, pd.postal_code,
                              ins.policy_number, ins.provider as ins_provider_id,
                              ins.date as ins_eff_date, ins.date_end as ins_exp_date,
                              ins.subscriber_relationship,
                              u.npi as provider_npi,
                              u.taxonomy as provider_taxonomy,
                              ic.x12_receiver_id as payer_id_x12,
                              ic.cms_id as payer_id_cms
                       FROM billing b
                       LEFT JOIN form_encounter fe    ON fe.encounter = b.encounter AND fe.pid = b.pid
                       LEFT JOIN patient_data pd      ON pd.pid = b.pid
                       LEFT JOIN insurance_data ins   ON ins.pid = b.pid AND ins.type = 'primary'
                       LEFT JOIN users u              ON u.id = COALESCE(NULLIF(b.provider_id,0), NULLIF(fe.provider_id,0))
                       LEFT JOIN insurance_companies ic ON ic.id = ins.provider
                       WHERE b.activity = 1
                         AND b.encounter IN ($in_enc)
                         AND b.pid IN ($in_pid)
                       ORDER BY b.encounter, b.pid, ins.date DESC, b.id";

        // Pre-build diagnosis lookups — wrapped in try/catch so a missing column
        // never breaks the whole validation response
        $pid_has_dx  = [];
        $enc_has_icd = [];
        try {
            // 1. Patient problem list (lists table)
            if (!empty($in_pid)) {
                $dx_res = sqlStatement(
                    "SELECT DISTINCT pid FROM lists
                      WHERE pid IN ($in_pid)
                        AND type = 'medical_problem'
                        AND activity = 1");
                while ($dx_row = sqlFetchArray($dx_res)) {
                    $pid_has_dx[(int)$dx_row['pid']] = true;
                }
            }
            // 2. ICD diagnosis rows in billing for these encounters
            if (!empty($in_enc)) {
                $icd_res = sqlStatement(
                    "SELECT DISTINCT encounter, pid FROM billing
                      WHERE encounter IN ($in_enc)
                        AND activity = 1
                        AND code_type IN ('ICD10','ICD9','SNOMED','ICD10 SQ')");
                while ($icd_row = sqlFetchArray($icd_res)) {
                    $enc_has_icd[$icd_row['encounter'] . '-' . $icd_row['pid']] = true;
                }
            }
        } catch (\Throwable $dx_ex) {
            // Ignore lookup errors — diagnosis check will fall back to justify only
            error_log('BV dx lookup error: ' . $dx_ex->getMessage());
        }

        // Execute detail query — wrap in try/catch so SQL errors become clean JSON errors.
        // If this throws (e.g. missing column), bv_emit in the outer catch will handle it.
        $det_res = sqlStatement($detail_sql);
        if ($det_res === false) {
            throw new \RuntimeException('Detail query failed - check column names in billing/form_encounter tables.');
        }

        // ── PHP-side insurance row resolution ────────────────────────────────
        // The JOIN returns ALL primary insurance rows per patient (sorted by ins.date DESC).
        // For each billing row (b.id), keep only the best-matching insurance:
        //   1. Active on the encounter date (ins.date <= enc_date AND (date_end blank OR >= enc_date))
        //   2. If multiple active rows: the one with the most recent ins.date
        //   3. Fallback: any row (the first one, i.e. most recent, since ORDER BY ins.date DESC)
        // We deduplicate so each billing row appears exactly once.
        $raw_rows = [];
        while ($r = sqlFetchArray($det_res)) {
            $raw_rows[] = $r;
        }

        // Group by billing row id — keep best insurance per billing row
        $best_ins = []; // billing_id -> best insurance row fields
        foreach ($raw_rows as $r) {
            $bid      = $r['id'];
            $enc_date = substr($r['enc_date'] ?? '', 0, 10);
            $eff      = substr($r['ins_eff_date']  ?? '', 0, 10);
            $exp      = substr($r['ins_exp_date']  ?? '', 0, 10);

            // Is this insurance row active on the DOS?
            $date_ok   = (empty($eff)  || $eff  === '0000-00-00' || $eff  <= $enc_date);
            $end_ok    = (empty($exp)  || $exp  === '0000-00-00' || $exp  >= $enc_date);
            $is_active = $date_ok && $end_ok;

            if (!isset($best_ins[$bid])) {
                // First row for this billing id — accept it unconditionally as baseline
                $best_ins[$bid] = ['row' => $r, 'active' => $is_active];
            } elseif ($is_active && !$best_ins[$bid]['active']) {
                // Upgrade from a non-active row to an active one
                $best_ins[$bid] = ['row' => $r, 'active' => true];
            }
            // If current best is already active, keep it (first = most recent due to ORDER BY ins.date DESC)
        }

        $v_rows    = [];
        $v_enc_map = [];
        foreach ($best_ins as $entry) {
            $r = $entry['row'];
            $v_rows[] = $r;
            $v_enc_map[$r['encounter'] . '-' . $r['pid']][] = $r;
        }

        // ── Pre-fetch rendering provider data (Loop 2310B / NM1*82) ─────────────
        // Rendering provider = the individual clinician who performed the service.
        // This is separate from billing provider (Loop 2010AA / NM1*85).
        // We check the users table via provider_id on each billing row.
        // Billing provider NPI (facility/group) is on the facility or global settings —
        // we do NOT flag that as missing here.
        // Build NPI map — try every provider ID path for each encounter.
        // b.provider_id covers billing-row provider (most common in MDITek).
        // Fallback query covers fe.provider_id, fe.supervisor_id, and any
        // other per-encounter provider field this OpenEMR version may use.
        $rendering_npi_map = []; // enc_id -> rendering NPI
        foreach ($v_rows as $r) {
            $enc_id = $r['encounter'] . '-' . $r['pid'];
            if (!isset($rendering_npi_map[$enc_id])) {
                $rendering_npi_map[$enc_id] = trim($r['provider_npi'] ?? '');
            }
        }
        // Fallback: for any enc_id still missing NPI, try every provider column
        // on form_encounter directly (covers supervisor_id, provider_id, etc.)
        $missing_npi_encs = array_keys(array_filter($rendering_npi_map, function($v){ return $v === ''; }));
        if (!empty($missing_npi_encs)) {
            try {
                foreach ($missing_npi_encs as $eid) {
                    list($enc, $pid) = explode('-', $eid, 2);
                    // Try every integer column on form_encounter that could be a provider ID
                    $fe_row = sqlQuery(
                        "SELECT provider_id, supervisor_id, referring_provider_id
                           FROM form_encounter
                          WHERE encounter = ? AND pid = ?
                          LIMIT 1",
                        [(int)$enc, (int)$pid]
                    );
                    if ($fe_row) {
                        $try_ids = array_filter(array_map('intval',
                            [$fe_row['provider_id'] ?? 0,
                             $fe_row['supervisor_id'] ?? 0,
                             $fe_row['referring_provider_id'] ?? 0]
                        ), function($v){ return $v > 0; });
                        foreach ($try_ids as $uid) {
                            $u_row = sqlQuery("SELECT npi, taxonomy FROM users WHERE id = ? LIMIT 1", [$uid]);
                            if ($u_row && !empty(trim($u_row['npi'] ?? ''))) {
                                $rendering_npi_map[$eid] = trim($u_row['npi']);
                                // Patch the v_rows so taxonomy check also works
                                foreach ($v_rows as &$vr) {
                                    if ($vr['encounter'].'-'.$vr['pid'] === $eid) {
                                        $vr['provider_npi']      = $rendering_npi_map[$eid];
                                        $vr['provider_taxonomy'] = trim($u_row['taxonomy'] ?? '');
                                    }
                                }
                                unset($vr);
                                break;
                            }
                        }
                    }
                }
            } catch (\Throwable $npi_ex) {
                // supervisor_id/referring_provider_id columns may not exist — skip gracefully
            }
        }

        // Payer ZIP check skipped — ic.zip column varies by OpenEMR version.
        // Use the 'Loop 2010BB — Payer ID Missing' warning instead to flag payer issues.
        $payer_zip_map = []; // disabled

        // Fetch billing (facility) provider NPI and tax ID for informational display
        $facility_npi   = $GLOBALS['facility_npi']   ?? '';
        $facility_taxid = $GLOBALS['facility_ein']   ?? $GLOBALS['cms_taxid'] ?? '';

        // ── Validate this batch ───────────────────────────────────────────────
        $issue_enc = []; $valid_enc = [];
        // 837 Loop-mapped error/warning arrays — keyed as enc_id arrays
        $missing_dx             = []; // Loop 2300 HI segment
        $rendering_missing      = []; // Loop 2310B NM1*82 — rendering provider NPI
        $rendering_bad_npi      = []; // Loop 2310B NM1*82 — NPI format invalid
        $missing_taxonomy       = []; // Loop 2310B PRV — missing provider taxonomy
        $invalid_cpt            = []; // Loop 2400 SV1 — invalid CPT/procedure
        $zero_charge            = []; // Loop 2400 SV1 — zero charge or units
        $missing_dx_pointer     = []; // Loop 2400 SV1 — diagnosis pointer empty in justify
        $missing_ins            = []; // Loop 2000B SBR — no insurance on file
        $expired_ins            = []; // Loop 2000B SBR — policy expired before DOS
        $missing_sub            = []; // Loop 2010BA NM1*IL — missing subscriber relationship
        $missing_payer_id       = []; // Loop 2010BB NM1*PR — missing payer ID (x12_receiver_id)
        $missing_sub_addr       = []; // Loop 2010BA N3/N4 — subscriber address blank
        $missing_payer_zip      = []; // Loop 2010BB N4 — payer ZIP blank
        $dup_cpt                = []; // Loop 2400 duplicate SV1
        // enc_id -> pid for building fix URLs
        $enc_pid_map = [];

        foreach ($v_rows as $r) {
            $enc_id = $r['encounter'] . '-' . $r['pid'];
            $enc_pid_map[$enc_id] = ['pid' => $r['pid'], 'encounter' => $r['encounter']];

            // ── Loop 2300: Diagnosis (HI segment) ────────────────────────────
            $has_dx = !empty($r['justify'])
                   || isset($enc_has_icd[$enc_id])
                   || isset($pid_has_dx[(int)$r['pid']]);
            if (!$has_dx) { $missing_dx[] = $enc_id; $issue_enc[$enc_id] = true; }

            // ── Loop 2400: Diagnosis pointer in SV1 ──────────────────────────
            // NOTE: OpenEMR assigns SV1 diagnosis pointers automatically at X12 generation
            // time from the justify field (which stores ICD codes, not pointer letters).
            // We validate that justify is populated (has_dx check above covers this).
            // Do NOT flag empty justify separately — that's a false positive.

            // ── Loop 2310B: Rendering Provider NM1*82 ────────────────────────
            // Use the pre-built map which includes fallbacks from form_encounter
            $rend_npi = $rendering_npi_map[$enc_id] ?? trim($r['provider_npi'] ?? '');
            if (empty($rend_npi)) {
                $rendering_missing[] = $enc_id;
                $issue_enc[$enc_id]  = true;
            } elseif (!preg_match('/^\d{10}$/', $rend_npi)) {
                $rendering_bad_npi[] = $enc_id;
                $issue_enc[$enc_id]  = true;
            }

            // ── Loop 2310B: Provider Taxonomy PRV*PE (only when NPI present) ─
            if (!empty($rend_npi) && empty(trim($r['provider_taxonomy'] ?? ''))) {
                $missing_taxonomy[] = $enc_id;
            }

            // ── Loop 2400: Service Line (SV1 segment) ────────────────────────
            if (empty($r['code']) || !preg_match('/^[A-Z0-9]{4,7}$/', $r['code'])) {
                $invalid_cpt[] = $enc_id; $issue_enc[$enc_id] = true;
            }
            // ── Loop 2400: Zero Charge ───────────────────────────────────────
            // Only flag when fee is $0 AND units = 0. A $0 fee alone may mean
            // a fee schedule applies at generation time — the X12 generator fills
            // in the actual charge. Flag only the unambiguous case: both are zero.
            if ((float)$r['fee'] == 0 && (int)$r['units'] == 0) { $zero_charge[] = $enc_id; }

            // ── Loop 2000B / 2010BA: Insurance & Subscriber ──────────────────
            if (empty($r['ins_provider_id'])) {
                $missing_ins[] = $enc_id; $issue_enc[$enc_id] = true;
            } else {
                if (!empty($r['ins_exp_date'])
                    && $r['ins_exp_date'] !== '0000-00-00'
                    && !empty($r['enc_date'])
                    && $r['ins_exp_date'] < substr($r['enc_date'], 0, 10)) {
                    $expired_ins[] = $enc_id; $issue_enc[$enc_id] = true;
                }
                if (empty($r['subscriber_relationship'])) { $missing_sub[] = $enc_id; }
                // Payer ID: x12_receiver_id is preferred; cms_id is the fallback the X12 generator uses.
                // Only flag missing if BOTH are empty — matching what the generator actually emits.
                $has_payer_id = !empty(trim($r['payer_id_x12'] ?? ''))
                             || !empty(trim($r['payer_id_cms'] ?? ''));
                if (!$has_payer_id) { $missing_payer_id[] = $enc_id; }
                // Loop 2010BB N4 — payer ZIP (via pre-fetched map, absent if ic.zip column missing)
                $ic_id = (int)($r['ins_provider_id'] ?? 0);
                if ($ic_id && isset($payer_zip_map[$ic_id]) && $payer_zip_map[$ic_id] === '') {
                    $missing_payer_zip[] = $enc_id;
                }

                // Loop 2010BA N3/N4 — subscriber address: use patient address as proxy
                // (subscriber address in insurance_data varies by OpenEMR version; patient address is always present)
                $sub_street = trim($r['street']      ?? '');
                $sub_city   = trim($r['city']        ?? '');
                $sub_state  = trim($r['state']       ?? '');
                $sub_zip    = trim($r['postal_code'] ?? '');
                if (empty($sub_street) || empty($sub_city) || empty($sub_state) || empty($sub_zip)) {
                    $missing_sub_addr[] = $enc_id;
                    $issue_enc[$enc_id] = true;
                }
            }
        }

        // ── Duplicate CPT within encounter (Loop 2400) ───────────────────────
        // A duplicate is the same code+modifier on the same encounter.
        // Different encounters are never compared — each enc_id is isolated.
        // A code that appears multiple times with DIFFERENT modifiers is NOT a duplicate.
        foreach ($v_enc_map as $eid => $erows) {
            $seen = [];
            $dup_flagged = false;
            foreach ($erows as $r) {
                $key = $r['code'] . ':' . ($r['modifier'] ?? '');
                if (isset($seen[$key]) && !$dup_flagged) {
                    $dup_cpt[] = $eid;
                    $dup_flagged = true; // add enc_id once per encounter
                }
                $seen[$key] = true;
            }
        }

        // Build partial result for this batch
        $total_in_batch = count($v_enc_map);
        $issue_enc_list = array_keys($issue_enc);
        $valid_enc_list = array_diff(array_keys($v_enc_map), $issue_enc_list);

        // Build a lookup: enc_id -> "Firstname Lastname (pid-enc)"
        $enc_names = [];
        foreach ($v_rows as $r) {
            $eid = $r['encounter'] . '-' . $r['pid'];
            if (!isset($enc_names[$eid])) {
                $name = trim(($r['fname'] ?? '') . ' ' . ($r['lname'] ?? ''));
                // Append claim ID in pid-encounter format (matches billing list display)
                $claim_id = $r['pid'] . '-' . $r['encounter'];
                $enc_names[$eid] = ($name ? $name . ' #' . $claim_id : $eid);
            }
        }
        // label(): return up to 5 unique patient names from enc_id list
        // nameCount(): count unique encounters (not unique names)
        $label = function($ids) use ($enc_names) {
            $seen_names = [];
            $result     = [];
            foreach (array_unique($ids) as $eid) {
                $name = $enc_names[$eid] ?? $eid;
                if (!in_array($name, $seen_names)) {
                    $seen_names[] = $name;
                    $result[]     = $name;
                    if (count($result) >= 5) break;
                }
            }
            return $result;
        };

        // $u counts unique encounters (each enc_id is one encounter)
        $u = function($a){ return count(array_unique($a)); };
        $partial_errors   = [];
        $partial_warnings = [];

        // Helper: build per-claim fix links array [{name, pid, encounter, fix_url}]
        $web_root = $GLOBALS['web_root'] ?? '';
        $fix_links = function($enc_ids, $url_tpl) use ($enc_names, $enc_pid_map, $web_root) {
            $seen = []; $result = [];
            foreach (array_unique($enc_ids) as $eid) {
                if (isset($seen[$eid])) continue; $seen[$eid] = true;
                $name = $enc_names[$eid] ?? $eid;
                $pid  = $enc_pid_map[$eid]['pid']       ?? '';
                $enc  = $enc_pid_map[$eid]['encounter']  ?? '';
                $url  = $web_root . str_replace(['{pid}','{enc}'], [$pid, $enc], $url_tpl);
                $result[] = ['name' => $name, 'pid' => $pid, 'encounter' => $enc, 'fix_url' => $url];
            }
            return $result;
        };

        // ── ERRORS (block submission) ─────────────────────────────────────────
        // Loop 2300 HI — diagnosis
        if ($u($missing_dx))
            $partial_errors[] = ['type'=>'Loop 2300 — Diagnosis',
                'message'=>'Missing diagnosis code (HI segment). Add ICD-10 diagnosis to encounter.',
                'fix_label'=>'Add Diagnosis',
                'fix_url'=>$web_root.'/interface/patient_file/encounter/encounter_top.php',
                'count'=>$u($missing_dx),
                'claims'=>$fix_links($missing_dx, '/interface/patient_file/encounter/encounter_top.php?set_pid={pid}&set_encounter={enc}')];
        // Loop 2310B NM1*82 — rendering provider
        if ($u($rendering_missing))
            $partial_errors[] = ['type'=>'Loop 2310B — Rendering Provider Missing',
                'message'=>'NM1*82 rendering provider NPI not found. Add individual provider NPI.',
                'fix_label'=>'Edit Provider',
                'fix_url'=>$web_root.'/interface/usergroup/user_admin.php',
                'count'=>$u($rendering_missing),
                'claims'=>$fix_links($rendering_missing, '/interface/patient_file/encounter/encounter_top.php?set_pid={pid}&set_encounter={enc}'),
                'dependency'=>'Taxonomy (PRV) will also be required once provider is added.'];
        if ($u($rendering_bad_npi))
            $partial_errors[] = ['type'=>'Loop 2310B — Rendering Provider NPI Invalid',
                'message'=>'NM1*82 rendering provider NPI format invalid (must be 10 digits). Go to Admin → Users and fix NPI.',
                'fix_label'=>'Edit Provider',
                'fix_url'=>$web_root.'/interface/usergroup/user_admin.php',
                'count'=>$u($rendering_bad_npi),
                'claims'=>$fix_links($rendering_bad_npi, '/interface/patient_file/encounter/encounter_top.php?set_pid={pid}&set_encounter={enc}')];
        // Loop 2400 SV1 — CPT
        if ($u($invalid_cpt))
            $partial_errors[] = ['type'=>'Loop 2400 — CPT/Procedure Invalid',
                'message'=>'SV1 procedure code missing or invalid format.',
                'fix_label'=>'Edit Fee Sheet',
                'fix_url'=>$web_root.'/interface/patient_file/encounter/encounter_top.php',
                'count'=>$u($invalid_cpt),
                'claims'=>$fix_links($invalid_cpt, '/interface/patient_file/encounter/encounter_top.php?set_pid={pid}&set_encounter={enc}')];
        // Loop 2000B — insurance
        if ($u($missing_ins))
            $partial_errors[] = ['type'=>'Loop 2000B — No Insurance',
                'message'=>'SBR/NM1*IL: No active primary insurance on file for this patient.',
                'fix_label'=>'Add Insurance',
                'fix_url'=>$web_root.'/interface/patient_file/summary/demographics.php',
                'count'=>$u($missing_ins),
                'claims'=>$fix_links($missing_ins, '/interface/patient_file/summary/demographics.php?set_pid={pid}')];
        if ($u($expired_ins))
            $partial_errors[] = ['type'=>'Loop 2000B — Insurance Expired',
                'message'=>'SBR: Policy expired before date of service. Update insurance coverage dates.',
                'fix_label'=>'Edit Insurance',
                'fix_url'=>$web_root.'/interface/patient_file/summary/demographics.php',
                'count'=>$u($expired_ins),
                'claims'=>$fix_links($expired_ins, '/interface/patient_file/summary/demographics.php?set_pid={pid}')];
        // Loop 2010BA N3/N4 — subscriber address
        if ($u($missing_sub_addr))
            $partial_errors[] = ['type'=>'Loop 2010BA — Subscriber Address Missing',
                'message'=>'N3/N4 subscriber address is incomplete. Add street, city, state, and ZIP.',
                'fix_label'=>'Edit Demographics',
                'fix_url'=>$web_root.'/interface/patient_file/summary/demographics.php',
                'count'=>$u($missing_sub_addr),
                'claims'=>$fix_links($missing_sub_addr, '/interface/patient_file/summary/demographics.php?set_pid={pid}')];

        // ── WARNINGS (allow but risky) ────────────────────────────────────────
        // Loop 2010BA — subscriber relationship
        if ($u($missing_sub))
            $partial_warnings[] = ['type'=>'Loop 2010BA — Subscriber Relationship',
                'message'=>'SBR segment missing subscriber relationship code.',
                'fix_label'=>'Edit Insurance',
                'fix_url'=>$web_root.'/interface/patient_file/summary/demographics.php',
                'count'=>$u($missing_sub),
                'claims'=>$fix_links($missing_sub, '/interface/patient_file/summary/demographics.php?set_pid={pid}')];
        // Loop 2010BB — payer ID
        if ($u($missing_payer_id))
            $partial_warnings[] = ['type'=>'Loop 2010BB — Payer ID Missing',
                'message'=>'NM1*PR PI qualifier empty. Electronic claims require payer ID in insurance company settings.',
                'fix_label'=>'Edit Insurance Co.',
                'fix_url'=>$web_root.'/interface/practice/ins_search.php',
                'count'=>$u($missing_payer_id),
                'claims'=>$fix_links($missing_payer_id, '/interface/patient_file/summary/demographics.php?set_pid={pid}')];
        // Loop 2010BB N4 — payer ZIP
        if ($u($missing_payer_zip))
            $partial_warnings[] = ['type'=>'Loop 2010BB — Payer ZIP Missing',
                'message'=>'N4 payer ZIP code is blank. Add ZIP to insurance company address.',
                'fix_label'=>'Edit Insurance Co.',
                'fix_url'=>$web_root.'/interface/practice/ins_search.php',
                'count'=>$u($missing_payer_zip),
                'claims'=>$fix_links($missing_payer_zip, '/interface/patient_file/summary/demographics.php?set_pid={pid}')];
        // Loop 2310B PRV — taxonomy
        if ($u($missing_taxonomy))
            $partial_warnings[] = ['type'=>'Loop 2310B — Rendering Provider Taxonomy Missing',
                'message'=>'PRV*PE segment missing taxonomy code (e.g. 207Q00000X). Add taxonomy in Admin → Users → provider settings.',
                'fix_label'=>'Edit Provider',
                'fix_url'=>$web_root.'/interface/usergroup/user_admin.php',
                'count'=>$u($missing_taxonomy),
                'claims'=>$fix_links($missing_taxonomy, '/interface/patient_file/encounter/encounter_top.php?set_pid={pid}&set_encounter={enc}')];
        // Loop 2400 — diagnosis pointer check removed (OpenEMR assigns pointers automatically)
        // Loop 2400 — duplicate CPT
        if ($u($dup_cpt))
            $partial_warnings[] = ['type'=>'Loop 2400 — Duplicate CPT',
                'message'=>'Same procedure code billed twice on the same encounter.',
                'fix_label'=>'Edit Fee Sheet',
                'fix_url'=>$web_root.'/interface/patient_file/encounter/encounter_top.php',
                'count'=>$u($dup_cpt),
                'claims'=>$fix_links($dup_cpt, '/interface/patient_file/encounter/encounter_top.php?set_pid={pid}&set_encounter={enc}')];
        // Loop 2400 — zero charge
        if ($u($zero_charge))
            $partial_warnings[] = ['type'=>'Loop 2400 — Zero Charge',
                'message'=>'SV1 charge amount is \$0 or units = 0.',
                'fix_label'=>'Edit Fee Sheet',
                'fix_url'=>$web_root.'/interface/patient_file/encounter/encounter_top.php',
                'count'=>$u($zero_charge),
                'claims'=>$fix_links($zero_charge, '/interface/patient_file/encounter/encounter_top.php?set_pid={pid}&set_encounter={enc}')];

        $bv_emit(json_encode([
            'phase'       => 'batch',
            'offset'      => $offset,
            'processed'   => $total_in_batch,
            'valid'       => count($valid_enc_list),
            'issues'      => count($issue_enc_list),
            'errors'      => $partial_errors,
            'warnings'    => $partial_warnings,
            'done'        => count($enc_pairs) < BV_BATCH_SIZE,
        ], JSON_UNESCAPED_UNICODE)); exit;
    }

    } catch (\Throwable $bv_ex) {
        $bv_emit(json_encode(['error' => $bv_ex->getMessage(),
            'valid' => 0, 'total' => 0, 'errors' => [], 'warnings' => [],
            'insights' => [], 'score' => 0, 'timestamp' => date('M j, Y g:i A')]));
    }
    exit;
}

// ── When a billing action button (Generate X12, Validate, etc.) is clicked,
// the form targets the 'ValidateShowBatch' iframe. We capture all billing
// output into the session so billing_process_display.php can render it styled.
// HCFA PDF actions are excluded — they stream PDF directly to the browser (new tab).
// X12/validate/clear/continue are captured so we can show the inline success banner.
$_bq_billing_actions = ['bn_x12','bn_validate',
    'bn_x12_encounter','bn_process_ub04','bn_mark','bn_reopen',
    'btn-continue','btn-validate','btn-clear'];
$_bq_hcfa_direct = isset($_POST['bn_process_hcfa']) || isset($_POST['bn_process_hcfa_form'])
    || isset($_POST['bn_process_ub04_form']) || isset($_POST['bn_hcfa_txt_file']);
// bm_x12_preview=1: bypass session capture so raw X12 output goes directly to iframe
// for the Preview X12 viewer. Uses btn-validate so claims are NOT marked as billed.
$_bq_x12_preview = !empty($_POST['bm_x12_preview']);
$_bq_is_billing = !$_bq_hcfa_direct && !$_bq_x12_preview
    && isset($_POST['mode']) && $_POST['mode'] === 'bill'
    && count(array_intersect($_bq_billing_actions, array_keys($_POST))) > 0;
if ($_bq_is_billing) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    ob_start();
}
// ── End capture setup ─────────────────────────────────────────────────────────

if (isset($_POST['mode'])) {
    file_put_contents(
        '/tmp/hcfa_post.log',
        print_r($_POST, true)
    );
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    if ($_POST['mode'] == 'export') {
        $sql = BillingReport::returnOFXSql();
        $db = get_db();
        $results = $db->Execute($sql);
        $billings = array();
        if ($results->RecordCount() == 0) {
            echo "<fieldset id='error_info' style='border:1px solid var(--danger) !important; background-color: var(--danger) !important; color: var(--white) !important; font-weight: bold; font-family: sans-serif; border-radius: 5px; padding: 20px 5px !important;'>";
            echo xlt("No Bills Found to Include in OFX Export") . "<br />";
            echo "</fieldset>";
        } else {
            while (!$results->EOF) {
                $billings[] = $results->fields;
                $results->MoveNext();
            }
            $ofx = new OFX($billings);
            header("Pragma: public");
            header("Expires: 0");
            header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
            header("Content-Disposition: attachment; filename=openemr_ofx.ofx");
            header("Content-Type: text/xml");
            echo $ofx->get_OFX();
            exit();
        }
    }
}

// global variables:
$from_date = isset($_POST['from_date']) ? $_POST['from_date'] : date('Y-m-d');
$to_date = isset($_POST['to_date']) ? $_POST['to_date'] : '';
$code_type = isset($_POST['code_type']) ? $_POST['code_type'] : 'all';
$unbilled = isset($_POST['unbilled']) ? $_POST['unbilled'] : 'on';
$my_authorized = isset($_POST["authorized"]) ? $_POST["authorized"] : '';

// This tells us if only encounters that appear to be missing a "25" modifier
// are to be reported.
$missing_mods_only = (isset($_POST['missing_mods_only']) && !empty($_POST['missing_mods_only']));

// ── Flush billing capture into session, then redirect to display page ───────
if (!empty($_bq_is_billing)) {
    $bq_raw = ob_get_clean();

    // Extract clean X12 block (ISA...IEA)
    $bq_x12 = '';
    if (preg_match('/(ISA*.+?IEA*\d+*\d+[~

]*)/s', $bq_raw, $_m)) {
        $bq_x12 = $_m[1];
    }

    // Strip HTML for result message lines
    $bq_lines = array_values(array_filter(
        array_map('trim', explode("
", strip_tags($bq_raw))),
        function ($l) { return strlen($l) > 3 && !preg_match('/^[\s\-\*]+$/', $l); }
    ));

    // Count validated claims and errors
    preg_match_all('/Successfully validated claim/i', $bq_raw, $_sc);
    preg_match_all('/error|fail|invalid/i',    $bq_raw, $_ec);
    $bq_claim_count = count($_sc[0]);
    $bq_error_count = count($_ec[0]);

    // Mode label
    $bq_mode = xl('Process');
    if (!empty($_POST['btn-validate'])) { $bq_mode = xl('Validate Only'); }
    elseif (!empty($_POST['btn-clear']))    { $bq_mode = xl('Validate & Clear'); }
    elseif (!empty($_POST['btn-continue'])) { $bq_mode = xl('Generate Batch'); }
    elseif (!empty($_POST['bn_x12']))       { $bq_mode = xl('Generate X12'); }

    // Store for billing_process_display.php
    $_SESSION['bq_x12_raw']      = $bq_x12;
    $_SESSION['bq_result_msgs']  = implode("
", $bq_lines);
    $_SESSION['bq_raw_html']     = $bq_raw;
    $_SESSION['bq_claim_count']  = $bq_claim_count;
    $_SESSION['bq_error_count']  = $bq_error_count;
    $_SESSION['bq_process_mode'] = $bq_mode;
    $_SESSION['bq_ts']           = time();

    // Navigate the named iframe to the styled display page
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
       . '<script>window.location.replace("billing_process_display.php?from_session=1");</script>'
       . '</head><body style="background:#1a1d2e;color:#a5b4fc;font-family:monospace;padding:20px;">'
       . '<p>Loading results...</p>'
       . '</body></html>';
    exit();
}
// ── End billing capture ───────────────────────────────────────────────────────

$left_margin = isset($_POST["left_margin"]) ? $_POST["left_margin"] : $GLOBALS['cms_left_margin_default'];
$top_margin = isset($_POST["top_margin"]) ? $_POST["top_margin"] : $GLOBALS['cms_top_margin_default'];
if ($left_margin + 0 === 20 && $top_margin + 0 === 24) {
// defaults are flipped. No easy way to reset existing. Global defaults fixed.
    $left_margin = '24';
    $top_margin = '20';
}
if ($ub04_support) {
    $left_ubmargin = isset($_POST["left_ubmargin"]) ? $_POST["left_ubmargin"] : $GLOBALS['left_ubmargin_default'];
    $top_ubmargin = isset($_POST["top_ubmargin"]) ? $_POST["top_ubmargin"] : $GLOBALS['top_ubmargin_default'];
}
$ofrom_date = $from_date;
$oto_date = $to_date;
$ocode_type = $code_type;
$ounbilled = $unbilled;
$oauthorized = $my_authorized;
$x = new X12Partner();
$partners = $x->_utility_array($x->x12_partner_factory());
?>
<!DOCTYPE html>
<html>

<head>

    <?php Header::setupHeader(['datetime-picker', 'common']); ?>
    <style>
        button.btn-action.btn-primary-act:hover{
            background: linear-gradient(135deg,#5b21b6,#7c3aed) !important!
           
        }
         /* ── Container ── */
         .container {
            max-width: 100%;
         }

         .btn-success{
            background:linear-gradient(135deg,#0f766e,#0d9488);
         }
        /* ── Modal fixes ── */
        .modal { overflow-y: auto; }
        .modal-open { overflow: auto; }
        .modal-open[style] { padding-right: 0px !important; }

        /* ── Filter bar ── */
        #bm-filter-bar {
            background: #fff;
            border: 1px solid #e0d9f5;
            border-radius: 10px;
            padding: 14px 20px 12px;
            margin-bottom: 14px;
            box-shadow: 0 2px 8px rgba(124,58,237,.07);
        }
        #bm-filter-bar .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px 14px;
            align-items: flex-end;
        }
        #bm-filter-bar .filter-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 140px;
        }
        #bm-filter-bar .filter-group.date-group { min-width: 120px; max-width: 160px; }
        #bm-filter-bar .filter-group.patient-group { min-width: 160px; max-width: 220px; }
        #bm-filter-bar label.filter-label {
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .6px;
            color: #7c6f96;
            margin: 0;
        }
        #bm-filter-bar select.form-control,
        #bm-filter-bar input.form-control {
            border: 1.5px solid #d1d5db;
            border-radius: 7px;
            font-size: 13px;
            padding: 6px 10px;
            height: auto;
            color: #1a1523;
            background: #fafaf9;
        }
        #bm-filter-bar select.form-control:focus,
        #bm-filter-bar input.form-control:focus {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124,58,237,.1);
            outline: none;
        }
        #bm-filter-bar .filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-shrink: 0;
        }
        #bm-filter-bar .btn-update,
        #bm-action-bar .btn-update {
            background: #fff;
            color: #5b21b6;
            border: 2px solid #7c3aed;
            border-radius: 7px;
            padding: 7px 20px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all .15s;
        }
        #bm-filter-bar .btn-update:hover,
        #bm-action-bar .btn-update:hover { background: #f5f3ff; border-color: #5b21b6; color: #4c1d95; }
        #bm-filter-bar .btn-print-link {
            font-size: 12px;
            color: #7c3aed;
            background: none;
            border: 1.5px solid #ddd6fe;
            border-radius: 7px;
            padding: 6px 12px;
            cursor: pointer;
            white-space: nowrap;
        }
        #bm-filter-bar .btn-print-link:hover { background: #f5f3ff; }

        /* ── Batch picker button ── */
        #bm-batch-btn {
            display: flex;
            align-items: center;
            gap: 6px;
            background: #fafaf9;
            border: 1.5px solid #d1d5db;
            border-radius: 7px;
            font-size: 13px;
            padding: 6px 10px;
            color: #1a1523;
            cursor: pointer;
            width: 100%;
            text-align: left;
            transition: border-color .15s;
            min-height: 34px;
        }
        #bm-batch-btn:hover, #bm-batch-btn.active {
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124,58,237,.1);
        }
        #bm-batch-btn .batch-btn-label { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#6b7280; }
        #bm-batch-btn .batch-btn-label.has-value { color:#1a1523; font-weight:600; }
        #bm-batch-btn .batch-caret { color:#9ca3af; font-size:10px; flex-shrink:0; }
        #bm-batch-btn .batch-tag { background:#ede9fe; color:#5b21b6; border-radius:4px; font-size:10px; font-weight:700; padding:1px 5px; flex-shrink:0; }

        /* ── Batch popup overlay ── */
        #bm-batch-popup { display:none; position:fixed; inset:0; z-index:9999; }
        #bm-batch-popup.open { display:block; }
        #bm-batch-backdrop { position:absolute; inset:0; background:rgba(15,10,30,.35); backdrop-filter:blur(2px); }
        #bm-batch-modal {
            position:absolute; background:#fff; border-radius:14px;
            box-shadow:0 20px 60px rgba(0,0,0,.22),0 4px 16px rgba(124,58,237,.12);
            width:560px; max-width:96vw; max-height:80vh;
            display:flex; flex-direction:column; overflow:hidden;
            top:50%; left:50%; transform:translate(-50%,-50%);
            animation:batchModalIn .18s ease;
        }
        @keyframes batchModalIn {
            from { opacity:0; transform:translate(-50%,-48%) scale(.96); }
            to   { opacity:1; transform:translate(-50%,-50%) scale(1); }
        }
        #bm-batch-modal-header {
            padding:18px 20px 14px; border-bottom:1px solid #f3f0fc;
            display:flex; align-items:center; gap:10px; flex-shrink:0;
        }
        #bm-batch-modal-header h5 { margin:0; font-size:15px; font-weight:700; color:#1a1523; flex:1; }
        #bm-batch-modal-header .batch-count-badge { background:#ede9fe; color:#5b21b6; border-radius:20px; font-size:11px; font-weight:700; padding:2px 9px; }
        #bm-batch-modal-header .btn-close-batch {
            background:#f3f4f6; border:none; border-radius:6px; width:28px; height:28px;
            cursor:pointer; font-size:14px; color:#6b7280;
            display:flex; align-items:center; justify-content:center; flex-shrink:0;
        }
        #bm-batch-modal-header .btn-close-batch:hover { background:#fee2e2; color:#dc2626; }
        #bm-batch-search-wrap { padding:12px 20px 10px; border-bottom:1px solid #f3f0fc; flex-shrink:0; }
        #bm-batch-search {
            width:100%; border:1.5px solid #d1d5db; border-radius:8px;
            padding:7px 12px 7px 34px; font-size:13px; outline:none; box-sizing:border-box;
            background:#fafaf9 url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center;
        }
        #bm-batch-search:focus { border-color:#7c3aed; box-shadow:0 0 0 3px rgba(124,58,237,.1); }
        #bm-batch-list { flex:1; overflow-y:auto; padding:8px 12px; }
        #bm-batch-list::-webkit-scrollbar { width:6px; }
        #bm-batch-list::-webkit-scrollbar-thumb { background:#ddd6fe; border-radius:3px; }
        .batch-item {
            display:flex; align-items:center; gap:10px; padding:9px 12px;
            border-radius:8px; cursor:pointer; transition:background .1s;
            border:1.5px solid transparent; margin-bottom:3px;
        }
        .batch-item:hover { background:#f5f3ff; border-color:#ddd6fe; }
        .batch-item.selected { background:#ede9fe; border-color:#7c3aed; }
        .batch-item .batch-icon {
            width:32px; height:32px; border-radius:7px; flex-shrink:0; color:#5b21b6;
            background:linear-gradient(135deg,#ede9fe,#ddd6fe);
            display:flex; align-items:center; justify-content:center; font-size:14px;
        }
        .batch-item.selected .batch-icon { background:linear-gradient(135deg,#5b21b6,#7c3aed); color:#fff; }
        .batch-item .batch-name { flex:1; font-size:13px; font-weight:500; color:#1a1523; word-break:break-all; }
        .batch-item.selected .batch-name { color:#3b0764; font-weight:700; }
        .batch-item .batch-check { width:18px; height:18px; border-radius:50%; border:2px solid #d1d5db; flex-shrink:0; }
        .batch-item.selected .batch-check {
            background:#7c3aed; border-color:#7c3aed;
            display:flex; align-items:center; justify-content:center;
        }
        .batch-item.selected .batch-check::after {
            content:''; width:5px; height:9px; border:2px solid #fff;
            border-top:none; border-left:none; transform:rotate(45deg) translateY(-1px);
        }
        #bm-batch-empty { text-align:center; padding:32px 20px; color:#9ca3af; font-size:13px; }
        #bm-batch-modal-footer { padding:12px 20px; border-top:1px solid #f3f0fc; display:flex; align-items:center; gap:10px; flex-shrink:0; }
        #bm-batch-clear-btn { font-size:12px; color:#6b7280; background:none; border:1.5px solid #e5e7eb; border-radius:7px; padding:6px 14px; cursor:pointer; }
        #bm-batch-clear-btn:hover { border-color:#fca5a5; color:#dc2626; background:#fef2f2; }
        #bm-batch-apply-btn { background:linear-gradient(135deg,#5b21b6,#7c3aed); color:#fff; border:none; border-radius:7px; padding:7px 22px; font-size:13px; font-weight:600; cursor:pointer; margin-left:auto; }
        #bm-batch-apply-btn:hover { opacity:.9; }

        /* ── Full width wrapper (replaces container-fluid) ── */
        .bm-full-width {
            width: 100%;
            padding: 0 4px;
            box-sizing: border-box;
        }
        #bm-val-panel {
            margin-left: 0 !important;
            margin-right: 0 !important;
            width: 100%;
        }

        /* ── Action toolbar ── */
        #bm-action-bar {
            background: #f8f6fd;
            border: 1px solid #e0d9f5;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 10px;
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 12px;
            width:100%;
        }
        .bm-section {
            display: flex; flex-direction: column; gap: 5px;
        }
        .bm-section-label {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; color: #9ca3af; white-space: nowrap;
        }
        .bm-section-btns {
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        }
        #bm-action-bar .action-sep {
            width: 1px; height: 44px; background: #ddd6fe; align-self: flex-end; margin: 0 2px;
        }
        #bm-action-bar .btn-action {
            font-size: 12px; font-weight: 600; padding: 7px 14px;
            border-radius: 7px; border: 1.5px solid #d1d5db;
            background: #fff; color: #374151; cursor: pointer;
            transition: all .12s; white-space: nowrap;
            display: inline-flex; align-items: center; gap: 5px;
        }
        #bm-action-bar .btn-action:hover:not(:disabled) {
            border-color: #7c3aed; color: #7c3aed; background: #f5f3ff;
        }
        #bm-action-bar .btn-action:disabled { opacity: .4; cursor: not-allowed; }
        #bm-action-bar .btn-action.btn-primary-act {
            background: linear-gradient(135deg,#5b21b6,#7c3aed);
            color: #fff; border-color: transparent;
        }
        #bm-action-bar .btn-action.btn-primary-act:hover:not(:disabled) { opacity: .88; }
        #bm-action-bar .btn-action.btn-danger-act:hover:not(:disabled) {
            border-color: #fca5a5; color: #dc2626; background: #fef2f2;
        }
        #bm-action-bar .btn-action.btn-validate-act {
            background: linear-gradient(135deg,#0f766e,#0d9488);
            color: #fff; border-color: transparent;
        }
        #bm-action-bar .btn-action.btn-validate-act:hover:not(:disabled) { opacity: .88; }
        #bm-action-bar .btn-action.btn-validate-act:disabled,
        #bm-action-bar .btn-action.btn-validate-act[disabled] {
            background: #e5e7eb; color: #9ca3af; border-color: #e5e7eb;
            cursor: not-allowed; opacity: 1;
        }
        @keyframes bm-slide-down {
            from { transform: translateY(-100%); opacity: 0; }
            to   { transform: translateY(0);     opacity: 1; }
        }
        @keyframes bm-spin {
            from { transform: rotate(0deg); }
            to   { transform: rotate(360deg); }
        }
        /* ── Slide-in toast ── */
        @keyframes bm-toast-in {
            from { transform: translateX(110%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        @keyframes bm-toast-out {
            from { transform: translateX(0);    opacity: 1; }
            to   { transform: translateX(110%); opacity: 0; }
        }
        .bm-toast-container {
            position: fixed; bottom: 24px; right: 24px;
            z-index: 9999; display: flex; flex-direction: column;
            gap: 10px; pointer-events: none;
        }
        .bm-toast {
            pointer-events: all;
            min-width: 320px; max-width: 420px;
            background: #fff; border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,.14), 0 2px 8px rgba(0,0,0,.08);
            overflow: hidden; animation: bm-toast-in .3s cubic-bezier(.22,.68,0,1.2) both;
        }
        .bm-toast.bm-toast-exit {
            animation: bm-toast-out .25s ease forwards;
        }
        .bm-toast-bar {
            height: 4px; width: 100%;
        }
        .bm-toast-body {
            padding: 14px 16px 12px;
            display: flex; align-items: flex-start; gap: 12px;
        }
        .bm-toast-icon {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 15px; font-weight: 800; flex-shrink: 0; margin-top: 1px;
        }
        .bm-toast-content { flex: 1; }
        .bm-toast-title {
            font-size: 13.5px; font-weight: 700; color: #111827; line-height: 1.3;
        }
        .bm-toast-sub {
            font-size: 11.5px; color: #6b7280; margin-top: 3px; line-height: 1.4;
        }
        .bm-toast-actions {
            display: flex; gap: 7px; margin-top: 10px; align-items: center;
        }
        .bm-toast-btn-primary {
            padding: 5px 13px; border-radius: 6px; font-size: 11.5px; font-weight: 700;
            border: none; cursor: pointer; text-decoration: none; display: inline-block;
            white-space: nowrap;
        }
        .bm-toast-btn-ghost {
            padding: 5px 10px; border-radius: 6px; font-size: 11.5px; font-weight: 600;
            background: none; border: 1px solid #e5e7eb; color: #6b7280;
            cursor: pointer; white-space: nowrap;
        }
        .bm-toast-btn-ghost:hover { background: #f9fafb; }
        .bm-toast-close {
            flex-shrink: 0; background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: 16px; line-height: 1; padding: 2px 4px;
            align-self: flex-start; margin-top: -1px;
        }
        .bm-toast-close:hover { color: #374151; }
        .bm-toast-progress {
            height: 3px; background: rgba(0,0,0,.06);
        }
        .bm-toast-progress-fill {
            height: 100%; transition: width linear;
        }
        /* MDi HCFA buttons */
        .bm-mdi-btn {
            font-size: 12px; font-weight: 700; padding: 7px 13px;
            border-radius: 7px; border: 2px solid #6d28d9;
            background: #6d28d9; color: #fff; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px;
            white-space: nowrap; transition: all .12s;
            box-shadow: 0 1px 4px rgba(109,40,217,.25);
        }
        .bm-mdi-btn:hover:not(:disabled) { background: #5b21b6; border-color: #5b21b6; }
        .bm-mdi-btn:disabled { opacity: .45; cursor: not-allowed; }
        .bm-mdi-btn-outline { background: #fff; color: #6d28d9; }
        .bm-mdi-btn-outline:hover:not(:disabled) { background: #f5f3ff; color: #5b21b6; }
        #bm-action-bar .margin-inputs {
            display: flex; align-items: center; gap: 5px;
            font-size: 12px; color: #6b7280;
        }
        #bm-action-bar .margin-inputs input {
            width: 44px; font-size: 12px; padding: 4px 6px; border-radius: 5px;
        }

        /* ── Validation Results Panel ── */
        #bm-val-panel {
            display: none;
            background: #fff;
            border: 1.5px solid #e0d9f5;
            border-radius: 10px;
            margin-bottom: 12px;
            overflow: hidden;
        }
        #bm-val-panel.visible { display: block; }
        .bm-val-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 18px; border-bottom: 1px solid #f0edf8; flex-wrap: wrap; gap: 8px;
        }
        .bm-val-header-left { display: flex; align-items: center; gap: 10px; }
        .bm-val-status-icon {
            width: 32px; height: 32px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; font-size: 15px;
        }
        .bm-val-status-icon.ok   { background: #d1fae5; color: #059669; }
        .bm-val-status-icon.warn { background: #fef3c7; color: #b45309; }
        .bm-val-status-icon.err  { background: #fee2e2; color: #b91c1c; }
        .bm-val-title { font-size: 14px; font-weight: 700; color: #1a1d2e; }
        .bm-val-subtitle { font-size: 12px; color: #6b7280; margin-top: 1px; }
        .bm-val-meta { font-size: 11px; color: #9ca3af; display: flex; align-items: center; gap: 8px; }
        .bm-val-expand-btn {
            font-size: 12px; font-weight: 600; color: #7c3aed;
            background: none; border: none; cursor: pointer; display: flex; align-items: center; gap: 4px;
        }
        .bm-val-score-bar {
            display: flex; align-items: center; gap: 10px; padding: 8px 18px;
            background: #faf8ff; border-bottom: 1px solid #f0edf8; flex-wrap: wrap; gap: 10px;
        }
        .bm-val-stat-card {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 16px; border-radius: 8px; border: 1px solid #e0d9f5;
            background: #fff; min-width: 130px;
        }
        .bm-val-stat-icon {
            width: 36px; height: 36px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center; font-size: 17px;
        }
        .bm-val-stat-icon.v { background: #d1fae5; }
        .bm-val-stat-icon.e { background: #fee2e2; }
        .bm-val-stat-icon.w { background: #fef3c7; }
        .bm-val-stat-num { font-size: 24px; font-weight: 800; font-family: monospace; }
        .bm-val-stat-num.v { color: #059669; }
        .bm-val-stat-num.e { color: #dc2626; }
        .bm-val-stat-num.w { color: #b45309; }
        .bm-val-stat-lbl { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; }
        .bm-val-score-wrap {
            margin-left: auto; display: flex; align-items: center; gap: 10px;
        }
        .bm-val-score-ring {
            position: relative; width: 54px; height: 54px;
        }
        .bm-val-score-ring svg { transform: rotate(-90deg); }
        .bm-val-score-text {
            position: absolute; inset: 0; display: flex; align-items: center;
            justify-content: center; font-size: 14px; font-weight: 800; color: #1a1d2e;
        }
        .bm-val-score-lbl { font-size: 11px; color: #6b7280; }
        /* Detail pane */
        .bm-val-details { display: none; padding: 0 18px 16px; }
        .bm-val-details.open { display: block; }
        .bm-val-section { margin-top: 14px; }
        .bm-val-section-title {
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .06em; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;
        }
        .bm-val-section-title.err  { color: #dc2626; }
        .bm-val-section-title.warn { color: #b45309; }
        .bm-val-section-title.info { color: #1d4ed8; }
        .bm-val-issue-row {
            display: flex; align-items: flex-start; justify-content: space-between;
            padding: 7px 12px; border-radius: 6px; margin-bottom: 4px; gap: 10px;
        }
        .bm-val-issue-row.err  { background: #fff8f8; border: 1px solid #fca5a5; }
        .bm-val-issue-row.warn { background: #fffbf0; border: 1px solid #fcd34d; }
        .bm-val-issue-row.info { background: #eff6ff; border: 1px solid #bfdbfe; }
        .bm-val-issue-icon { width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; flex-shrink: 0; margin-top: 1px; }
        .bm-val-issue-icon.err  { background: #fee2e2; color: #dc2626; }
        .bm-val-issue-icon.warn { background: #fef3c7; color: #b45309; }
        .bm-val-issue-icon.info { background: #dbeafe; color: #1d4ed8; }
        .bm-val-issue-msg { flex: 1; font-size: 12px; color: #374151; }
        .bm-val-issue-count { font-size: 11px; font-weight: 700; color: #6b7280; flex-shrink: 0; white-space: nowrap; }
        .bm-val-footer {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 18px; border-top: 1px solid #f0edf8;
            background: #faf8ff; flex-wrap: wrap; gap: 8px;
        }
        .bm-val-footer-note { font-size: 12px; color: #6b7280; display: flex; align-items: center; gap: 6px; }
        .bm-val-proceed {
            font-size: 12px; font-weight: 600; padding: 7px 18px; border-radius: 7px;
            border: 1.5px solid #d1d5db; background: #fff; color: #374151;
            cursor: pointer; transition: all .12s; white-space: nowrap;
        }
        .bm-val-proceed:hover { border-color: #7c3aed; color: #7c3aed; }
        .bm-val-loading {
            text-align: center; padding: 28px; color: #9ca3af; font-size: 13px;
        }
        .bm-val-loading-spinner {
            display: inline-block; width: 20px; height: 20px;
            border: 2px solid #e0d9f5; border-top-color: #7c3aed;
            border-radius: 50%; animation: bm-spin .7s linear infinite;
            margin-right: 8px; vertical-align: middle;
        }
        @keyframes bm-spin { to { transform: rotate(360deg); } }
        .bm-val-claim-badge {
            display: inline-block; padding: 1px 7px; border-radius: 10px;
            font-size: 10px; font-weight: 700; font-family: monospace;
            background: #ede9fe; color: #5b21b6; margin-right: 4px;
        }

        /* ── Results table ── */
        .table th, .table td { border-top: none !important; }
        a, a:visited, a:hover { text-decoration: none; color: var(--black); }
        button[type="submit"].subbtn-warning { background: #ec971f !important; color: black !important; }
        button[type="submit"].subbtn-warning:hover { background: #da8104 !important; color: var(--white) !important; }
        @media (min-width: 992px) { .modal-lg { width: 1000px !Important; } }
        @media only screen and (max-width: 1024px) { [class*="col-"] { width: 100%; text-align: left !Important; } }
    </style>
    <script>
        var partners = <?php echo json_encode($partners); ?>;

        // next set of 4 functions are for a wait confirm action then submit.
        // I wrote this a little more involved than it needs to be
        // to example the pattern. Ideal submit part would be via a fetch or ajax
        // then could do refresh or after submit actions.
        //
        function doSubmit(action) {
            top.restoreSession();
            return new Promise(function(resolve, reject) {
                var showLog = function() { $("#view-log-link").click(); };

                // Open blank dialog - billing_process.php renders into it
                dlgopen('', 'ValidateShowBatch', 960, 600, false, '', {
                    buttons: [
                        { text: '<?php echo addslashes(xlt("Logs")); ?>',  close: false, style: 'default btn-sm', click: showLog },
                        { text: '<?php echo addslashes(xlt("Close")); ?>', close: true,  style: 'default btn-sm' }
                    ],
                    sizeHeight: 'full'
                });

                // Submit form into the named iframe (original OpenEMR pattern)
                document.update_form.target = 'ValidateShowBatch';
                top.restoreSession();
                document.update_form.submit();

                // Poll until billing_process.php finishes, then render styled UI in-place
                var bqAttempts = 0;
                var bqPoll = setInterval(function() {
                    bqAttempts++;
                    if (bqAttempts > 150) { clearInterval(bqPoll); return; }
                    try {
                        var ifr = document.querySelector('iframe[name="ValidateShowBatch"]');
                        if (!ifr) return;
                        var d = ifr.contentDocument || ifr.contentWindow.document;
                        if (!d || d.readyState !== 'complete') return;
                        var bt = d.body ? (d.body.innerText || d.body.textContent || '') : '';
                        if (bt.trim().length < 20 && bqAttempts < 40) return;
                        clearInterval(bqPoll);

                        if (/service unavailable|503|temporarily unable/i.test(bt)) {
                            bqWriteError(ifr, bt); resolve(true); return;
                        }

                        var x12 = '';
                        var pre = d.querySelector('pre');
                        if (pre) { x12 = pre.innerText || pre.textContent || ''; }
                        if (!x12) {
                            var ia = bt.indexOf('ISA*'), ie = bt.lastIndexOf('IEA*');
                            if (ia >= 0 && ie >= 0) {
                                var ieEnd = bt.indexOf('\n', ie + 10);
                                x12 = bt.substring(ia, ieEnd > 0 ? ieEnd + 1 : bt.length);
                            }
                        }
                        var rl = [];
                        d.querySelectorAll('li').forEach(function(li) {
                            var t = (li.innerText || li.textContent || '').trim();
                            if (t) rl.push(t);
                        });
                        if (!rl.length) {
                            bt.split('\n').forEach(function(l) {
                                l = l.trim();
                                if (l.length > 4 && /success|error|fail|invalid|missing|No X-12/i.test(l)) rl.push(l);
                            });
                        }
                        var cc   = (bt.match(/Successfully validated claim/gi)||[]).length;
                        var ec   = (bt.match(/No X-12 partner assigned/gi)||[]).length;
                        var mode = action==='btn-validate' ? 'Validate Only'
                                 : action==='btn-clear'    ? 'Validate & Clear'
                                 : action==='btn-continue' ? 'Generate Batch' : 'Generate X12';
                        bqWriteDisplay(ifr, x12, rl, cc, ec, mode);
                    } catch(e) { console.warn('bq poll:', e); }
                }, 200);

                resolve(true);
            });
        }

        function bqWriteError(ifr, rawText) {
            var d = ifr.contentDocument || ifr.contentWindow.document;
            d.open();
            d.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:"Segoe UI",sans-serif;background:#fff;color:#1a1d2e;padding:24px}</style></head><body>'
                + '<div style="background:#fef2f2;border:1.5px solid #fca5a5;border-radius:8px;padding:20px 24px">'
                + '<div style="font-size:14px;font-weight:700;color:#be123c;margin-bottom:8px">&#9888; Server Error</div>'
                + '<div style="font-size:12px;color:#6b7280">The billing process returned a server error. Please try again in a moment.</div>'
                + '<details style="margin-top:12px"><summary style="font-size:11px;color:#9ca3af;cursor:pointer">Details</summary>'
                + '<pre style="font-size:10.5px;color:#6b7280;margin-top:8px;white-space:pre-wrap">'
                + rawText.substring(0,400).replace(/</g,'&lt;').replace(/>/g,'&gt;')
                + '<\/pre><\/details><\/div><\/body><\/html>');
            d.close();
        }

        function bqWriteDisplay(ifr, x12, rl, claimCount, errorCount, modeLabel) {
            var sc=0, wc=0, ec=errorCount, items='';
            rl.forEach(function(line) {
                var type='success', icon='&#10003;';
                if (/error|fail|invalid|missing|No X-12/i.test(line)) { type='error';   icon='&#10007;'; ec++; }
                else if (/warn|caution/i.test(line))                    { type='warning'; icon='!';        wc++; }
                else { sc++; }
                var bc=type==='error'?'#be123c':type==='warning'?'#b45309':'#059669';
                var bg=type==='error'?'#fff8f8':type==='warning'?'#fffbf0':'#fff';
                var ib=type==='error'?'#ffe4e6':type==='warning'?'#fef3c7':'#d1fae5';
                var cl=line.replace(/\b(\d+-\d+)\b/g,'<span style="background:#ede9fe;color:#5b21b6;border-radius:4px;padding:0 5px;font-family:monospace;font-size:10.5px;font-weight:700">$1<\/span>');
                items+='<div style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:7px;border:1px solid #e5e7ef;border-left:3px solid '+bc+';background:'+bg+';margin-bottom:6px;font-size:12px">'
                    +'<span style="width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;background:'+ib+';color:'+bc+'">'+icon+'<\/span>'
                    +'<span style="flex:1">'+cl+'<\/span><\/div>';
            });
            if (!items) items='<p style="font-size:12px;color:#9197b3;padding:8px 0">Processing complete.<\/p>';

            var x12html='', x12tab='';
            if (x12) {
                var esc=x12.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                           .replace(/^([A-Z][A-Z0-9]{1,2})\*/gm,'<b style="color:#79c0ff">$1<\/b><span style="color:#6e7681">*<\/span>');
                x12tab='<div id="bqtx12" onclick="bqTab(\'x12\',this)" style="display:inline-flex;align-items:center;gap:6px;padding:9px 14px;font-size:11px;font-weight:600;color:rgba(255,255,255,.5);cursor:pointer;border-bottom:2px solid transparent">'
                    +'<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"\/><\/svg>X12<span style="background:rgba(255,255,255,.12);border-radius:10px;padding:1px 7px;font-size:9px;font-weight:700">'+(x12.split('\n').length)+' ln<\/span><\/div>';
                x12html='<div id="bqpx12" style="display:none;height:100%;overflow-y:auto;padding:16px 18px">'
                    +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">'
                    +'<span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#9197b3">X12 Claim Data<\/span>'
                    +'<button onclick="try{navigator.clipboard.writeText(document.getElementById(\'bqraw\').textContent)}catch(e){}" style="height:26px;padding:0 10px;border-radius:5px;background:#f8f8fc;border:1px solid #e5e7ef;color:#4b5563;font-size:11px;font-weight:600;cursor:pointer">Copy<\/button><\/div>'
                    +'<pre id="bqraw" style="background:#0f1117;border-radius:8px;border:1px solid #2a2d3e;padding:14px 16px;font-family:\'Courier New\',monospace;font-size:11px;line-height:1.8;color:#c8d0e0;overflow-x:auto;white-space:pre;margin:0">'+esc+'<\/pre><\/div>';
            }
            var sl=ec>0?'Errors Found':wc>0?'Ready with Warnings':'Ready to Submit';
            var pb=ec>0?'#7f1d1d':wc>0?'#312e81':'#064e3b';
            var pc=ec>0?'#fca5a5':wc>0?'#a5b4fc':'#6ee7b7';
            var html='<!DOCTYPE html><html><head><meta charset="UTF-8"><style>*{box-sizing:border-box;margin:0;padding:0}body{background:#fff;font-family:"Segoe UI",sans-serif;font-size:13px;color:#1a1d2e;height:100vh;display:flex;flex-direction:column;overflow:hidden}<\/style><\/head><body>'
                +'<div style="background:#1a1d2e;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;gap:12px">'
                +'<div style="display:flex;align-items:center;gap:10px">'
                +'<div style="width:32px;height:32px;background:rgba(91,33,182,.4);border-radius:8px;display:flex;align-items:center;justify-content:center">'
                +'<svg width="16" height="16" fill="none" stroke="rgba(167,139,250,1)" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"\/><\/svg><\/div>'
                +'<div><div style="font-size:13px;font-weight:700;color:#fff">Billing Queue Results<\/div>'
                +'<div style="font-size:10px;color:rgba(255,255,255,.45);font-family:monospace;margin-top:1px">Mode: '+modeLabel+'<\/div><\/div><\/div>'
                +'<span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:10.5px;font-weight:700;background:'+pb+';color:'+pc+'">'
                +'<span style="width:6px;height:6px;border-radius:50%;background:currentColor"><\/span>'+sl+'<\/span><\/div>'
                +'<div style="display:flex;background:#252840;border-bottom:1px solid rgba(255,255,255,.06);padding:0 18px;flex-shrink:0">'
                +'<div id="bqtres" onclick="bqTab(\'res\',this)" style="display:inline-flex;align-items:center;gap:6px;padding:9px 14px;font-size:11px;font-weight:600;color:#fff;cursor:pointer;border-bottom:2px solid #7c3aed">'
                +'<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"\/><\/svg>Results'
                +'<span style="background:#7c3aed;border-radius:10px;padding:1px 7px;font-size:9px;font-weight:700">'+rl.length+'<\/span><\/div>'
                +x12tab+'<\/div>'
                +'<div style="flex:1;overflow:hidden;position:relative">'
                +'<div id="bqpres" style="display:block;height:100%;overflow-y:auto;padding:16px 18px">'
                +'<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">'
                +'<div style="background:#f8f8fc;border:1px solid #e5e7ef;border-radius:8px;padding:12px 14px;display:flex;align-items:center;gap:10px">'
                +'<div style="width:34px;height:34px;border-radius:8px;background:#d1fae5;display:flex;align-items:center;justify-content:center;color:#059669;font-size:18px;font-weight:700">&#10003;<\/div>'
                +'<div><div style="font-size:20px;font-weight:800;color:#059669;font-family:monospace">'+sc+'<\/div><div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#9197b3;margin-top:2px">Validated<\/div><\/div><\/div>'
                +'<div style="background:#f8f8fc;border:1px solid #e5e7ef;border-radius:8px;padding:12px 14px;display:flex;align-items:center;gap:10px">'
                +'<div style="width:34px;height:34px;border-radius:8px;background:#ffe4e6;display:flex;align-items:center;justify-content:center;color:#be123c;font-size:18px;font-weight:700">&#10007;<\/div>'
                +'<div><div style="font-size:20px;font-weight:800;color:#be123c;font-family:monospace">'+ec+'<\/div><div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#9197b3;margin-top:2px">Errors<\/div><\/div><\/div>'
                +'<div style="background:#f8f8fc;border:1px solid #e5e7ef;border-radius:8px;padding:12px 14px;display:flex;align-items:center;gap:10px">'
                +'<div style="width:34px;height:34px;border-radius:8px;background:#fef3c7;display:flex;align-items:center;justify-content:center;color:#b45309;font-size:18px;font-weight:700">!<\/div>'
                +'<div><div style="font-size:20px;font-weight:800;color:#b45309;font-family:monospace">'+wc+'<\/div><div style="font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:#9197b3;margin-top:2px">Warnings<\/div><\/div><\/div><\/div>'
                +items+'<\/div>'+x12html+'<\/div>'
                +'<div style="background:#f8f8fc;border-top:1px solid #e5e7ef;padding:10px 18px;display:flex;justify-content:space-between;align-items:center;flex-shrink:0">'
                +'<span style="font-size:10.5px;color:#9197b3;font-family:monospace">'+(claimCount?'Total claims: <strong>'+claimCount+'<\/strong>':'')+'<\/span>'
                +'<button onclick="try{top.restoreSession()}catch(e){}window.close()" style="height:32px;padding:0 20px;border-radius:6px;background:#5b21b6;color:#fff;border:none;font-size:11.5px;font-weight:700;cursor:pointer">&#10003; Close<\/button><\/div>'
                +'<script>function bqTab(n,el){["bqpres","bqpx12"].forEach(function(id){var p=document.getElementById(id);if(p)p.style.display="none"});var p=document.getElementById("bqp"+n);if(p)p.style.display="block";document.querySelectorAll("[id^=bqt]").forEach(function(t){t.style.borderBottomColor="transparent";t.style.color="rgba(255,255,255,.5)"});el.style.borderBottomColor="#7c3aed";el.style.color="#fff"}<\/script>'
                +'<\/body><\/html>';
            var d = ifr.contentDocument || ifr.contentWindow.document;
            d.open(); d.write(html); d.close();
        }

        // -- HCFA dropdown toggle -----------------------------------------
        function bmToggleHcfa(e) {
            e.stopPropagation();
            document.getElementById('bm-hcfa-menu').classList.toggle('open');
        }
        function bmHcfaAction(btnName, mType) {
            document.getElementById('bm-hcfa-menu').classList.remove('open');
            top.restoreSession();

            // Count checked encounters for the toast
            var checked = {};
            document.querySelectorAll('input[id^="CheckBoxBilling"]:checked').forEach(function(cb) {
                var eid = cb.getAttribute('data-enc-id'); if (eid) checked[eid] = true;
            });
            var claimCount = Object.keys(checked).length;

            var f = document.update_form;

            // Inject btn-continue + the HCFA button name
            var inp1 = document.createElement('input');
            inp1.type = 'hidden'; inp1.name = 'btn-continue'; inp1.value = '1'; inp1.id = '_bm_hcfa_c';
            var inp2 = document.createElement('input');
            inp2.type = 'hidden'; inp2.name = btnName; inp2.value = 'true'; inp2.id = '_bm_hcfa_n';
            f.appendChild(inp1);
            f.appendChild(inp2);

            // Open a properly-sized popup and submit the form into it.
            // billing_process.php outputs the PDF inline (Content-Type: application/pdf).
            // A visible popup reliably renders it. We know this works because the original
            // dlgopen/ValidateShowBatch approach worked.
            // Open real browser tab
            window.open('', 'MDiTekHCFA');

            f.target = 'MDiTekHCFA';
            f.submit();
            f.target = '';

            // Clean up injected inputs after submit
            setTimeout(function() {
                ['_bm_hcfa_c', '_bm_hcfa_n'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el && el.parentNode) el.parentNode.removeChild(el);
                });
            }, 500);

            // Show success toast
            bmShowHcfaToast(claimCount, false);
        }
        // ── Helper: inject hidden inputs, submit form into a named iframe, clean up ──
        // ── Refresh CSRF token before any submit (prevents token expiry issues) ────
        function bmRefreshCsrf(callback) {
            var url = '<?php echo $GLOBALS["web_root"]; ?>/interface/billing/billing_report.php?bm_csrf_refresh=1';
            fetch(url, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    if (d && d.csrf_token_form) {
                        // Update all csrf_token_form fields in the page
                        document.querySelectorAll('[name="csrf_token_form"]').forEach(function(el) {
                            el.value = d.csrf_token_form;
                        });
                        document.querySelectorAll('[name="bm_csrf_hidden"]').forEach(function(el) {
                            el.value = d.csrf_token_form;
                        });
                        var cEl = document.getElementById('bm_csrf_token_form');
                        if (cEl) cEl.value = d.csrf_token_form;
                    }
                    callback();
                })
                .catch(function() { callback(); }); // on error, proceed anyway
        }

        function _bmSubmitHidden(iframeName, extraFields) {
            top.restoreSession();
            var f = document.update_form;
            bmRefreshCsrf(function() {
                var injected = [];
                extraFields.forEach(function(pair) {
                    var el = document.createElement('input');
                    el.type = 'hidden'; el.name = pair[0]; el.value = pair[1];
                    f.appendChild(el);
                    injected.push(el);
                });
                f.target = iframeName;
                f.submit();
                f.target = '';
                injected.forEach(function(el) { f.removeChild(el); });
            });
        }

        // ── Preview X12 — show 837 content in popup WITHOUT generating/billing ────
        // bm_x12_preview=1 bypasses the session capture so billing_process.php
        // outputs the raw X12 directly into the dlgopen iframe.
        // btn-validate means claims are validated but NOT marked as billed.
        // doSubmit polls the iframe, finds ISA*...IEA*, renders the styled X12 viewer.
        function bmPreviewX12() {
            top.restoreSession();
            var f = document.update_form;
            // btn-validate: validate+generate content without marking claims billed
            // bn_x12: tells billing_process.php to generate X12 format
            // bm_x12_preview: bypasses session capture so raw output reaches iframe
            var fields = [['btn-validate','1'],['bn_x12','true'],['bm_x12_preview','1']];
            var injected = [];
            fields.forEach(function(pair) {
                var el = document.createElement('input');
                el.type = 'hidden'; el.name = pair[0]; el.value = pair[1];
                el.className = '_bm_px12_tmp';
                f.appendChild(el);
                injected.push(el);
            });
            doSubmit('btn-validate').then(function() {
                injected.forEach(function(el) {
                    if (el && el.parentNode) el.parentNode.removeChild(el);
                });
                // ── Phase 3: validate the actual emitted X12 ─────────────────
                // Give the iframe a moment to render, then grab its text content
                // and run it through the X12 segment validator.
                setTimeout(function() {
                    try {
                        var iframe = document.getElementById('ValidateShowBatch')
                                  || document.querySelector('iframe[name="ValidateShowBatch"]');
                        if (!iframe) return;
                        var iDoc = iframe.contentDocument || iframe.contentWindow.document;
                        var x12text = iDoc ? (iDoc.body ? (iDoc.body.innerText || iDoc.body.textContent || '') : '') : '';
                        // Only proceed if it looks like an X12 file (starts with ISA or ST)
                        if (!x12text || !/^(ISA|ST)\*/m.test(x12text.trim())) return;
                        bmValidateX12Segments(x12text.trim());
                    } catch(e) { /* cross-origin or content not ready */ }
                }, 1800);
            });
        }

        // ── Phase 3: X12 segment validation ──────────────────────────────────
        // Sends the generated X12 text to the server-side validator.
        // Reports only issues that are ACTUALLY in the outbound file.
        function bmValidateX12Segments(x12text) {
            var p = bmParams({ bm_validate_x12: '1', x12_content: x12text });
            fetch(bmBase, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: p.toString(),
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(d) {
                if (d.error) { console.warn('X12 validator error:', d.error); return; }
                if (!d.total_claims) return;

                // Only surface errors/warnings that weren't already in Phase 1+2
                var newErrs  = (d.errors   || []).filter(function(e) { return e.message; });
                var newWarns = (d.warnings || []).filter(function(w) { return w.message; });
                if (!newErrs.length && !newWarns.length) return;

                // Show a supplemental notice in the validation panel
                var vPanel = document.getElementById('bm-val-score-bar');
                if (!vPanel) return;
                var html = '<div style="margin-top:10px;padding:10px 14px;background:#fefce8;border:1px solid #fbbf24;border-radius:6px;font-size:12px;">'
                    + '<strong style="color:#92400e;">&#9888; X12 Segment Check (' + d.total_claims + ' transactions)</strong>';
                newErrs.forEach(function(e) {
                    html += '<div style="color:#dc2626;margin-top:4px;">&#10007; [' + bmEsc(e.loop) + ' ' + bmEsc(e.segment) + '] '
                          + bmEsc(e.claim) + ': ' + bmEsc(e.message) + '</div>';
                });
                newWarns.forEach(function(w) {
                    html += '<div style="color:#b45309;margin-top:4px;">&#9888; [' + bmEsc(w.loop) + ' ' + bmEsc(w.segment) + '] '
                          + bmEsc(w.claim) + ': ' + bmEsc(w.message) + '</div>';
                });
                html += '</div>';
                vPanel.insertAdjacentHTML('beforeend', html);
            })
            .catch(function(e) { console.warn('X12 segment validator fetch failed:', e); });
        }


        // ── Proceed Anyway — same as Generate X12 (btn-continue + bn_x12) ─────
        // Called from validation panel footer. Uses hidden iframe + success banner.
        function bmProceedAnyway() {
            var fakeEvent = { preventDefault: function(){}, currentTarget: document.querySelector('[name="bn_x12"]') || document.createElement('button') };
            bmGenerateX12Direct(fakeEvent);
        }

        // ── Generate X12 directly — no popup, inline success banner ──────────
        function bmGenerateX12Direct(e) {
            e.preventDefault();
            top.restoreSession();
            var f = document.update_form;

            // Inject bn_x12 so billing_process.php takes the X12 path and writes claim_file_tracker.
            // btn-continue is already submitted by doSubmit — bn_x12 piggybacks on it.
            var inpX12 = document.createElement('input');
            inpX12.type = 'hidden'; inpX12.name = 'bn_x12'; inpX12.value = 'true'; inpX12.id = '_bm_gx12_a';
            f.appendChild(inpX12);

            // Use doSubmit('btn-continue') — the exact same path as the modal Continue button.
            // This opens the validation popup, submits to billing_process.php via ValidateShowBatch,
            // writes claim_file_tracker, then shows the styled results.
            doSubmit('btn-continue').then(function() {
                var el = document.getElementById('_bm_gx12_a');
                if (el && el.parentNode) el.parentNode.removeChild(el);
            }).catch(function() {
                var el = document.getElementById('_bm_gx12_a');
                if (el && el.parentNode) el.parentNode.removeChild(el);
            });
        }

        // ── Success banner after Generate X12 ────────────────────────────────
        // ── Shared slide-in toast engine ─────────────────────────────────────
        // bmShowToast({ type, title, sub, actions, duration })
        // type: 'success' | 'error' | 'info'
        // actions: array of { label, href, onclick, primary }
        // duration: ms before auto-dismiss (0 = no auto-dismiss)
        function bmShowToast(opts) {
            // Ensure container exists
            var container = document.getElementById('bm-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'bm-toast-container';
                container.className = 'bm-toast-container';
                document.body.appendChild(container);
            }

            var type     = opts.type || 'success';
            var duration = (opts.duration !== undefined) ? opts.duration : 6000;

            var barColor  = type === 'success' ? '#16a34a' : type === 'error' ? '#dc2626' : '#5b21b6';
            var iconBg    = type === 'success' ? '#dcfce7' : type === 'error' ? '#fee2e2' : '#ede9fe';
            var iconColor = type === 'success' ? '#16a34a' : type === 'error' ? '#dc2626' : '#5b21b6';
            var iconChar  = type === 'success' ? '&#10003;' : type === 'error' ? '&#10007;' : '&#8505;';

            var actionsHtml = '';
            if (opts.actions && opts.actions.length) {
                actionsHtml = '<div class="bm-toast-actions">';
                opts.actions.forEach(function(a) {
                    if (a.href) {
                        var nav = 'try{if(window.top&&window.top.mdiNavGo)window.top.mdiNavGo(\'' + a.href + '\');else window.top.location=\'' + a.href + '\';}catch(e){}return false;';
                        actionsHtml += '<a href="' + a.href + '" onclick="' + nav + '" class="bm-toast-btn-primary" style="background:' + barColor + ';color:#fff;">' + a.label + '</a>';
                    } else if (a.dismiss) {
                        actionsHtml += '<button class="bm-toast-btn-ghost" onclick="bmDismissToast(this.closest(\'.bm-toast\'))">' + a.label + '</button>';
                    }
                });
                actionsHtml += '</div>';
            }

            var progressHtml = duration > 0
                ? '<div class="bm-toast-progress"><div class="bm-toast-progress-fill" style="background:' + barColor + ';width:100%;"></div></div>'
                : '';

            var toast = document.createElement('div');
            toast.className = 'bm-toast';
            toast.innerHTML =
                '<div class="bm-toast-bar" style="background:' + barColor + ';"></div>'
                + '<div class="bm-toast-body">'
                +   '<div class="bm-toast-icon" style="background:' + iconBg + ';color:' + iconColor + ';">' + iconChar + '</div>'
                +   '<div class="bm-toast-content">'
                +     '<div class="bm-toast-title">' + (opts.title || '') + '</div>'
                +     (opts.sub ? '<div class="bm-toast-sub">' + opts.sub + '</div>' : '')
                +     actionsHtml
                +   '</div>'
                +   '<button class="bm-toast-close" onclick="bmDismissToast(this.closest(\'.bm-toast\'))">&#10005;</button>'
                + '</div>'
                + progressHtml;

            container.appendChild(toast);

            // Start progress bar shrink
            if (duration > 0) {
                var fill = toast.querySelector('.bm-toast-progress-fill');
                if (fill) {
                    // Force reflow then start transition
                    fill.getBoundingClientRect();
                    fill.style.transition = 'width ' + (duration / 1000) + 's linear';
                    fill.style.width = '0%';
                }
                setTimeout(function() { bmDismissToast(toast); }, duration);
            }

            return toast;
        }

        function bmDismissToast(toast) {
            if (!toast || toast.classList.contains('bm-toast-exit')) return;
            toast.classList.add('bm-toast-exit');
            setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 260);
        }

        // ── X12 generate result → slide-in toast ─────────────────────────────
        function bmShowGenerateBanner(count, hasError, rawText) {
            var cfUrl = '<?php echo $GLOBALS["web_root"]; ?>/interface/billing/claim_file_tracker.php';
            if (hasError && !count) {
                bmShowToast({
                    type: 'error',
                    title: '<?php echo addslashes(xlt("Generation Failed")); ?>',
                    sub:   '<?php echo addslashes(xlt("Check logs for details.")); ?>',
                    duration: 8000
                });
            } else {
                bmShowToast({
                    type: 'success',
                    title: '&#10003; ' + count + ' <?php echo addslashes(xlt("claims sent successfully")); ?>',
                    sub:   '<?php echo addslashes(xlt("X12 batch generated.")); ?>',
                    actions: [
                        { label: '<?php echo addslashes(xlt("View File Status")); ?>', href: cfUrl },
                        { label: '<?php echo addslashes(xlt("Dismiss")); ?>', dismiss: true }
                    ],
                    duration: 0
                });
            }
        }

        // ── HCFA generate result → slide-in toast ────────────────────────────
        function bmShowHcfaToast(count, hasError) {
            if (hasError) {
                bmShowToast({
                    type: 'error',
                    title: '<?php echo addslashes(xlt("HCFA Generation Failed")); ?>',
                    sub:   '<?php echo addslashes(xlt("Check logs for details.")); ?>',
                    duration: 8000
                });
            } else {
                bmShowToast({
                    type: 'success',
                    title: '&#10003; ' + (count > 0 ? count + ' ' : '') + '<?php echo addslashes(xlt("HCFA forms downloaded")); ?>',
                    sub:   '<?php echo addslashes(xlt("Check your downloads folder.")); ?>',
                    actions: [
                        { label: '<?php echo addslashes(xlt("Dismiss")); ?>', dismiss: true }
                    ],
                    duration: 6000
                });
            }
        }

        // ── Reopen + Reactivate selected encounters ──────────────────────────
        // Opens the same Popup_Window as the Re-Open button so behavior is consistent.
        function bmReopenReactivateSelected(e) {
            e.preventDefault();
            var ids = [];
            document.querySelectorAll('input[id^="CheckBoxBilling"]:checked').forEach(function(cb) {
                var eid = cb.getAttribute('data-enc-id');
                if (eid && ids.indexOf(eid) === -1) ids.push(eid);
            });
            if (!ids.length) {
                alert('<?php echo addslashes(xlt("Select at least one claim first.")); ?>');
                return false;
            }
            if (!confirm('<?php echo addslashes(xlt("Reopen + Reactivate")); ?> ' + ids.length + ' <?php echo addslashes(xlt("selected encounter(s)? This will reset them to unbilled and reactivate in the billing queue.")); ?>')) {
                return false;
            }
            top.restoreSession();
            var f = document.update_form;

            // Inject our action fields
            var a1 = document.createElement('input');
            a1.type = 'hidden'; a1.name = 'bm_reopen_reactivate'; a1.value = '1'; a1.id = '_rrA';
            var a2 = document.createElement('input');
            a2.type = 'hidden'; a2.name = 'bm_reopen_reactivate_ids'; a2.value = ids.join(','); a2.id = '_rrB';
            f.appendChild(a1); f.appendChild(a2);

            // Open the same popup window Re-Open uses, submit into it
            window.open('about:blank', 'Popup_Window',
                'toolbar=0,scrollbars=1,location=0,statusbar=0,menubar=0,resizable=1,width=500,height=300,left=312,top=234');
            f.action = '<?php echo $GLOBALS["web_root"]; ?>/interface/billing/billing_report.php';
            f.target = 'Popup_Window';
            f.submit();
            f.action = 'billing_process.php';
            f.target = '';

            // Clean up injected inputs
            setTimeout(function() {
                ['_rrA','_rrB'].forEach(function(id){
                    var el = document.getElementById(id);
                    if (el && el.parentNode) el.parentNode.removeChild(el);
                });
            }, 400);
            return false;
        }

        // ── HCFA Inline Preview Panel ────────────────────────────────────────
        // Shows a panel below the action bar listing each encounter with its status.
        // Valid claims get a "View Form" link; invalid ones show why.
        function bmHcfaPreview(btnName) {
            document.getElementById('bm-hcfa-menu').classList.remove('open');

            // Build claim list from checked checkboxes
            var claims = [];
            var seen = {};
            document.querySelectorAll('input[id^="CheckBoxBilling"]').forEach(function(cb) {
                var eid = cb.getAttribute('data-enc-id');
                if (!eid || seen[eid]) return;
                seen[eid] = true;
                var parts = eid.split('-');
                var pid = parts[0], enc = parts[1];

                // Find patient name: walk up to the row, find the bold name span
                var row = cb.closest('tr');
                var nameEl = null;
                // The name is in the first td of the encounter block (a prior sibling row)
                var tbl = cb.closest('table');
                if (tbl) {
                    var allRows = tbl.querySelectorAll('tr');
                    var found = false;
                    for (var i = allRows.length - 1; i >= 0; i--) {
                        if (!found && allRows[i].contains(cb)) { found = true; }
                        if (found && allRows[i].querySelector('.font-weight-bold span, span.font-weight-bold')) {
                            nameEl = allRows[i].querySelector('.font-weight-bold span, span.font-weight-bold');
                            if (!nameEl) nameEl = allRows[i].querySelector('.font-weight-bold');
                            break;
                        }
                    }
                }
                var patName = nameEl ? (nameEl.textContent || nameEl.innerText || '').trim() : ('Patient ' + pid);
                // Clean up name - remove age/DOB info in parens
                patName = patName.replace(/\s*\(\d+-\d+\)\s*/, '').trim();

                // Check insurance: find payer select for this encounter
                var payerSel = document.querySelector('select[name="claims[' + eid + '][payer]"]');
                var hasIns = payerSel && payerSel.value && payerSel.value !== '' && payerSel.value !== '0';

                // Check if checked (only include checked ones, or all if none checked)
                var isChecked = cb.checked;

                claims.push({ eid: eid, pid: pid, enc: enc, name: patName, hasIns: hasIns, checked: isChecked });
            });

            // If some are checked, only show those
            var anyChecked = claims.some(function(c){ return c.checked; });
            if (anyChecked) claims = claims.filter(function(c){ return c.checked; });

            if (!claims.length) {
                alert('<?php echo addslashes(xlt("No claims selected. Please load the billing list first.")); ?>');
                return;
            }

            bmShowHcfaPanel(claims, btnName);
        }


        document.addEventListener('click', function(e) {
            if (!e.target.closest('#bm-hcfa-wrap')) {
                var m = document.getElementById('bm-hcfa-menu');
                if (m) m.classList.remove('open');
            }
        });

        // -- Validation Engine ---------------------------------------------
        var _bmLastValidation = null;
        var _bmDetailsOpen    = false;

        // -- Chunked validation orchestrator ---------------------------------
        var _bmAbort   = false;   // set true to stop mid-run
        var _bmRunning = false;

        function bmRunValidation() {
            if (_bmRunning) { _bmAbort = true; }  // cancel previous run
            _bmAbort   = false;
            _bmRunning = true;
            _bmLastValidation = null;
            _bmDetailsOpen    = false;

            // Show panel with spinner
            var panel = document.getElementById('bm-val-panel');
            panel.classList.add('visible');
            var detEl = document.getElementById('bm-val-details');
            if (detEl) detEl.style.display = 'none';
            _bmDetailsOpen = false;
            document.getElementById('bm-val-footer').style.display = 'none';
            document.getElementById('bm-val-subtitle').textContent = '<?php echo addslashes(xlt("Starting...")); ?>';
            var iconEl = document.getElementById('bm-val-icon');
            iconEl.className = 'bm-val-status-icon';
            iconEl.innerHTML = '<span class="bm-val-loading-spinner" style="width:16px;height:16px;border-width:2px;"></span>';
            // Reset stat numbers
            ['bm-stat-valid','bm-stat-errors','bm-stat-warnings'].forEach(function(id){
                var el = document.getElementById(id); if(el) el.textContent = '-';
            });
            var arcEl = document.getElementById('bm-score-arc');
            if (arcEl) { arcEl.style.strokeDashoffset = '138.2'; arcEl.style.stroke = '#e0d9f5'; }
            var textEl = document.getElementById('bm-score-text');
            if (textEl) textEl.textContent = '-';

            var bmBase = window.location.pathname;
            var bmCsrf = (document.getElementById('bm_csrf_hidden') || {}).value ||
                         (document.querySelector('[name="csrf_token_form"]') || {}).value || '';

            // Collect filter params once
            function bmParams(extra) {
                var p = new URLSearchParams();
                p.append('csrf_token_form', bmCsrf);
                // Pass current filter state
                ['bm_submitter','bm_billed','bm_insurance','bm_patient','bm_bill_from','bm_bill_to','bm_batch'].forEach(function(k){
                    var el = document.getElementById(k) || document.querySelector('[name="'+k+'"]');
                    if (el && el.value) p.append(k, el.value);
                });
                // Scope validation to visible rows using encounter IDs (data-enc-id attribute).
                // data-enc-id = "pid-encounter" format, matching validation's enc_id.
                // 1. If specific rows are checked, validate only those encounters
                // 2. Otherwise, validate ALL visible encounter rows
                var checkedEncIds = [];
                document.querySelectorAll('input[id^="CheckBoxBilling"]:checked').forEach(function(cb) {
                    var eid = cb.getAttribute('data-enc-id');
                    if (eid) checkedEncIds.push(eid);
                });
                if (checkedEncIds.length > 0) {
                    // Use only checked encounters (deduplicated)
                    var unique = checkedEncIds.filter(function(v,i,a){ return a.indexOf(v)===i; });
                    p.append('bm_enc_ids', unique.join(','));
                } else {
                    // Use ALL visible encounters - collect from every CheckBoxBilling
                    var allEncIds = [];
                    document.querySelectorAll('input[id^="CheckBoxBilling"]').forEach(function(cb) {
                        var eid = cb.getAttribute('data-enc-id');
                        if (eid) allEncIds.push(eid);
                    });
                    if (allEncIds.length > 0) {
                        var uniqueAll = allEncIds.filter(function(v,i,a){ return a.indexOf(v)===i; });
                        p.append('bm_enc_ids', uniqueAll.join(','));
                    }
                    // If no rows visible at all, filter params (bm_billed, bm_submitter etc.) scope the query
                }
                for (var k in extra) p.append(k, extra[k]);
                return p;
            }

            // Aggregated results across batches
            var agg = { valid: 0, issues: 0, errors: {}, warnings: {}, total: 0 };

            function mergePartial(batch) {
                // PHP returns encounter-level valid/issues counts per batch
                agg.valid  += (batch.valid  || 0);
                agg.issues += (batch.issues || 0);
                (batch.errors || []).forEach(function(e) {
                    var k = e.type + '|' + e.message; // deduplicate by type+message
                    if (!agg.errors[k]) agg.errors[k] = { type: e.type, message: e.message, count: 0, claims: [] };
                    agg.errors[k].count += (e.count || 0);
                    if (e.claims) agg.errors[k].claims = agg.errors[k].claims.concat(e.claims).slice(0, 5);
                });
                (batch.warnings || []).forEach(function(w) {
                    var k = w.type + '|' + w.message;
                    if (!agg.warnings[k]) agg.warnings[k] = { type: w.type, message: w.message, count: 0, claims: [] };
                    agg.warnings[k].count += (w.count || 0);
                    if (w.claims) agg.warnings[k].claims = agg.warnings[k].claims.concat(w.claims).slice(0, 5);
                });
            }

            function updateProgress(done, total) {
                var pct = total > 0 ? Math.round((done / total) * 100) : 0;
                document.getElementById('bm-val-subtitle').textContent =
                    '<?php echo addslashes(xlt("Validating")); ?> ' + done + ' / ' + total +
                    ' <?php echo addslashes(xlt("claims")); ?> (' + pct + '%)';
            }

            function finalize(total) {
                _bmRunning = false;
                var errList  = Object.values(agg.errors);
                var warnList = Object.values(agg.warnings);
                var insights = [];
                if (errList.some(function(e){ return e.type === 'Diagnosis'; }))
                    insights.push({ message: '<?php echo addslashes(xlt("Claims with missing diagnosis are likely to be denied by the payer")); ?>' });
                if (agg.warnings['Payer ID'])
                    insights.push({ message: '<?php echo addslashes(xlt("Electronic submissions may fail for claims with missing payer IDs")); ?>' });
                var score = total > 0 ? Math.round((agg.valid / total) * 100) : 100;
                score = Math.max(0, score - warnList.length * 2);
                var result = {
                    valid:  agg.valid,
                    issues: agg.issues,          // encounters with >=1 error
                    total:  agg.valid + agg.issues,
                    errors: errList, warnings: warnList, insights: insights,
                    score: score, timestamp: new Date().toLocaleString()
                };
                _bmLastValidation = result;
                bmRenderValidation(result);
            }

            function runBatch(offset, total) {
                if (_bmAbort) { _bmRunning = false; return; }
                updateProgress(offset, total);

                fetch(bmBase, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: bmParams({ bm_validate_batch: '1', bm_batch_offset: offset }).toString(),
                    credentials: 'same-origin',
                    signal: AbortSignal.timeout ? AbortSignal.timeout(15000) : undefined
                })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(batch) {
                    if (batch.error) throw new Error(batch.error);
                    mergePartial(batch);
                    var nextOffset = offset + (batch.processed || 20);
                    if (!batch.done && nextOffset < total && !_bmAbort) {
                        // Small delay between batches to avoid hammering the server
                        setTimeout(function(){ runBatch(nextOffset, total); }, 150);
                    } else {
                        finalize(total);
                    }
                })
                .catch(function(err) {
                    _bmRunning = false;
                    var sub = document.getElementById('bm-val-subtitle');
                    if (sub) sub.textContent = '';
                    var iconEl2 = document.getElementById('bm-val-icon');
                    if (iconEl2) { iconEl2.className = 'bm-val-status-icon err'; iconEl2.innerHTML = '&#10007;'; }
                    // Show whatever we have so far if partial results exist
                    if (agg.valid > 0 || agg.issues > 0) {
                        finalize(agg.valid + agg.issues);
                    } else {
                        document.getElementById('bm-val-subtitle').textContent =
                            '<?php echo addslashes(xlt("Validation error")); ?>: ' + String(err).substring(0, 80);
                    }
                });
            }

            // Step 1: Get total count, then run batches
            fetch(bmBase, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: bmParams({ bm_validate_count: '1' }).toString(),
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) throw new Error(data.error);
                var total   = data.total   || 0;
                agg.total   = total;
                if (total === 0) {
                    _bmRunning = false;
                    bmRenderValidation({ valid: 0, total: 0, errors: [], warnings: [], insights: [], score: 100, timestamp: new Date().toLocaleString() });
                    return;
                }
                var selCount = (bmParams({}).get('bm_enc_ids') || '').split(',').filter(Boolean).length;
                var scopeMsg;
                if (selCount > 0) {
                    var checkedOnly = document.querySelectorAll('input[id^="CheckBoxBilling"]:checked').length;
                    scopeMsg = checkedOnly > 0
                        ? '<?php echo addslashes(xlt("Validating")); ?> ' + selCount + ' <?php echo addslashes(xlt("selected claims")); ?>...'
                        : '<?php echo addslashes(xlt("Validating")); ?> ' + selCount + ' <?php echo addslashes(xlt("filtered claims")); ?>...';
                } else {
                    scopeMsg = '<?php echo addslashes(xlt("Found")); ?> ' + total + ' <?php echo addslashes(xlt("claims - starting validation")); ?>...';
                }
                runBatch(0, total);
            })
            .catch(function(err) {
                _bmRunning = false;
                var iconEl3 = document.getElementById('bm-val-icon');
                if (iconEl3) { iconEl3.className = 'bm-val-status-icon err'; iconEl3.innerHTML = '&#10007;'; }
                document.getElementById('bm-val-subtitle').textContent =
                    '<?php echo addslashes(xlt("Could not start validation")); ?>: ' + String(err).substring(0, 80);
            });
        }


        function bmRenderValidation(d) {
            // Pure error with no data
            if (d && d.error && !d.total && !(d.errors && d.errors.length)) {
                document.getElementById('bm-val-score-bar').innerHTML =
                    '<div class="bm-val-loading" style="color:#dc2626;">&#10007; <?php echo addslashes(xlt("Validation error:")); ?> ' + bmEsc(d.error) + '</div>';
                var hdr = document.getElementById('bm-val-header-inner');
                if (hdr) hdr.innerHTML = '<div class="bm-val-status-icon err">&#10007;</div>'
                    + '<div><div class="bm-val-title"><?php echo addslashes(xlt("Validation Error")); ?></div>'
                    + '<div class="bm-val-subtitle">' + bmEsc(d.error) + '</div></div>';
                return;
            }

            var valid      = d.valid    || 0;
            var total      = d.total    || (d.valid + d.issues) || 0;
            var issueCount = d.issues   || Math.max(0, total - valid); // from PHP directly
            var warnCount  = d.warnings ? d.warnings.reduce(function(s,w){ return s + (w.count||1); }, 0) : 0;
            var errCount   = d.errors   ? d.errors.length : 0;
            var score      = d.score    || 0;
            var ts         = d.timestamp || '';

            // Score color + label
            var scoreColor = score >= 85 ? '#059669' : score >= 60 ? '#b45309' : '#dc2626';
            var scoreLbl   = score >= 85 ? '<?php echo addslashes(xlt("Clean")); ?>'
                           : score >= 60 ? '<?php echo addslashes(xlt("Risky")); ?>'
                           : '<?php echo addslashes(xlt("Likely Denied")); ?>';
            var arc        = 138.2 - (score / 100) * 138.2;

            // Header status icon + subtitle (these stay in static HTML)
            var iconEl = document.getElementById('bm-val-icon');
            var subEl  = document.getElementById('bm-val-subtitle');
            var tsEl   = document.getElementById('bm-val-timestamp');
            if (iconEl) {
                if (errCount > 0)       { iconEl.className = 'bm-val-status-icon err';  iconEl.innerHTML = '&#10007;'; }
                else if (warnCount > 0) { iconEl.className = 'bm-val-status-icon warn'; iconEl.innerHTML = '!'; }
                else                    { iconEl.className = 'bm-val-status-icon ok';   iconEl.innerHTML = '&#10003;'; }
            }
            if (subEl) {
                if (errCount > 0) {
                    subEl.innerHTML = '<span style="color:#059669;font-weight:600;">' + valid + ' <?php echo addslashes(xlt("valid")); ?></span>'
                        + ' &nbsp;|&nbsp; <span style="color:#dc2626;font-weight:600;">' + errCount + ' <?php echo addslashes(xlt("issues")); ?></span>';
                } else if (warnCount > 0) {
                    subEl.innerHTML = '<span style="color:#059669;font-weight:600;">' + valid + ' <?php echo addslashes(xlt("valid")); ?></span>'
                        + ' &nbsp;|&nbsp; <span style="color:#b45309;font-weight:600;">' + warnCount + ' <?php echo addslashes(xlt("warnings")); ?></span>';
                } else {
                    subEl.textContent = valid + ' <?php echo addslashes(xlt("claims are valid - ready to submit")); ?>';
                }
            }
            if (tsEl && ts) tsEl.textContent = '<?php echo addslashes(xlt("Last validated:")); ?> ' + ts;
            // Show Preview X12 button now that validation has run
            var prevBtn = document.getElementById('bm-preview-x12-btn');
            if (prevBtn) prevBtn.style.display = 'inline-flex';

            // Update stat cards directly - never destroy/replace the container
            var elValid = document.getElementById('bm-stat-valid');
            var elErr   = document.getElementById('bm-stat-errors');
            var elWarn  = document.getElementById('bm-stat-warnings');
            if (elValid) elValid.textContent = valid;
            if (elErr)   elErr.textContent   = issueCount;   // claims with issues = total - valid
            if (elWarn)  elWarn.textContent  = warnCount;

            // Update score ring in-place
            var arcEl    = document.getElementById('bm-score-arc');
            var textEl   = document.getElementById('bm-score-text');
            var sublblEl = document.getElementById('bm-score-sublbl');
            if (arcEl) {
                arcEl.style.strokeDashoffset = arc;
                arcEl.style.stroke = scoreColor;
            }
            if (textEl)   textEl.textContent   = score + '%';
            if (sublblEl) sublblEl.textContent = scoreLbl;

            // Helper: render per-claim badges with clickable fix links
            function bmClaimBadges(claims, maxShow) {
                if (!claims || !claims.length) return '';
                var shown = claims.slice(0, maxShow || 5);
                return ' &mdash; ' + shown.map(function(c) {
                    var name = (typeof c === 'object') ? c.name : c;
                    var url  = (typeof c === 'object' && c.fix_url) ? c.fix_url : null;
                    if (url) {
                        var _nav = 'var _u=this.getAttribute(\'href\');try{if(window.top&&window.top.mdiNavGo)window.top.mdiNavGo(_u);else window.top.location=_u;}catch(e){}return false;';
                        return '<a href="' + bmEsc(url) + '" onclick="' + _nav + '" class="bm-val-claim-badge" style="text-decoration:none;cursor:pointer;" title="Open in interface">' + bmEsc(name) + '</a>';
                    }
                    return '<span class="bm-val-claim-badge">' + bmEsc(name) + '</span>';
                }).join('');
            }

            // Build issues list HTML
            var html = '';
            if (d.errors && d.errors.length) {
                html += '<div class="bm-val-section">'
                    + '<div class="bm-val-section-title err">&#10007; <?php echo addslashes(xlt("Errors - Block Submission")); ?></div>';
                d.errors.forEach(function(e2) {
                    var badges = bmClaimBadges(e2.claims, 5);
                    var _ef = e2.fix_url ? 'var _u=this.getAttribute(\'href\');try{if(window.top&&window.top.mdiNavGo)window.top.mdiNavGo(_u);else window.top.location=_u;}catch(e){}return false;' : '';
                    var fixBtn = (e2.fix_url) ? ' <a href="' + bmEsc(e2.fix_url) + '" onclick="' + _ef + '" style="font-size:10px;font-weight:700;color:#fff;background:#dc2626;border-radius:4px;padding:2px 7px;text-decoration:none;margin-left:6px;white-space:nowrap;">' + bmEsc(e2.fix_label || 'Fix') + '</a>' : '';
                    html += '<div class="bm-val-issue-row err">'
                        + '<div class="bm-val-issue-icon err">&#10007;</div>'
                        + '<div class="bm-val-issue-msg"><strong>' + bmEsc(e2.type) + ':</strong> ' + bmEsc(e2.message) + fixBtn + badges + '</div>'
                        + '<div class="bm-val-issue-count">' + (e2.count || 1) + ' claim' + ((e2.count||1) !== 1 ? 's' : '') + '</div>'
                        + '</div>';
                });
                html += '</div>';
            }
            if (d.warnings && d.warnings.length) {
                html += '<div class="bm-val-section">'
                    + '<div class="bm-val-section-title warn">&#9888; <?php echo addslashes(xlt("Warnings - Allow but Risky")); ?></div>';
                d.warnings.forEach(function(w) {
                    var _wf = w.fix_url ? 'var _u=this.getAttribute(\'href\');try{if(window.top&&window.top.mdiNavGo)window.top.mdiNavGo(_u);else window.top.location=_u;}catch(e){}return false;' : '';
                    var fixBtn = (w.fix_url) ? ' <a href="' + bmEsc(w.fix_url) + '" onclick="' + _wf + '" style="font-size:10px;font-weight:700;color:#fff;background:#b45309;border-radius:4px;padding:2px 7px;text-decoration:none;margin-left:6px;white-space:nowrap;">' + bmEsc(w.fix_label || 'Fix') + '</a>' : '';
                    var badges = bmClaimBadges(w.claims, 5);
                    html += '<div class="bm-val-issue-row warn">'
                        + '<div class="bm-val-issue-icon warn">!</div>'
                        + '<div class="bm-val-issue-msg"><strong>' + bmEsc(w.type) + ':</strong> ' + bmEsc(w.message) + fixBtn + badges + '</div>'
                        + '<div class="bm-val-issue-count">' + (w.count || 1) + ' claim' + ((w.count||1) !== 1 ? 's' : '') + '</div>'
                        + '</div>';
                });
                html += '</div>';
            }
            if (d.insights && d.insights.length) {
                html += '<div class="bm-val-section">'
                    + '<div class="bm-val-section-title info">&#128161; <?php echo addslashes(xlt("Smart Insights")); ?></div>';
                d.insights.forEach(function(ins) {
                    html += '<div class="bm-val-issue-row info">'
                        + '<div class="bm-val-issue-icon info">&#128161;</div>'
                        + '<div class="bm-val-issue-msg">' + bmEsc(ins.message) + '</div>'
                        + '</div>';
                });
                html += '</div>';
            }
            if (!html) html = '<div style="padding:16px 0;font-size:12px;color:#6b7280;text-align:center;">&#10003; <?php echo addslashes(xlt("No issues found - all claims are ready to submit.")); ?></div>';
            document.getElementById('bm-val-issues-list').innerHTML = html;

            // Show footer only when there are errors
            var footerEl = document.getElementById('bm-val-footer');
            if (footerEl) footerEl.style.display = errCount > 0 ? 'flex' : 'none';

            // Auto-open inline details when there are errors
            if (errCount > 0 && !_bmDetailsOpen) {
                bmToggleDetails();
            }
            // Reset spinner icon if it was set during loading
            var iconEl2 = document.getElementById('bm-val-icon');
            if (iconEl2 && iconEl2.querySelector('.bm-val-loading-spinner')) {
                iconEl2.innerHTML = errCount > 0 ? '&#10007;' : warnCount > 0 ? '!' : '&#10003;';
                iconEl2.className = 'bm-val-status-icon ' + (errCount > 0 ? 'err' : warnCount > 0 ? 'warn' : 'ok');
            }
        }

        // "View Details" - toggles the inline detail pane under the stat cards
        function bmToggleDetails() {
            if (!_bmLastValidation) { bmRunValidation(); return; }
            _bmDetailsOpen = !_bmDetailsOpen;
            var det = document.getElementById('bm-val-details');
            var btn = document.getElementById('bm-val-expand-btn');
            if (!det) return;
            if (_bmDetailsOpen) {
                det.style.display = 'block';
                det.style.padding = '0 18px 16px';
                if (btn) btn.innerHTML = '<?php echo addslashes(xlt("Hide Details")); ?> &#9650;';
            } else {
                det.style.display = 'none';
                if (btn) btn.innerHTML = '<?php echo addslashes(xlt("View Details")); ?> &#9660;';
            }
        }

        // "View All Issues" - opens a dlgopen dialog with full issue list
        function bmViewAllIssues() {
            if (!_bmLastValidation) { bmRunValidation(); return; }
            var d          = _bmLastValidation;
            var valid      = d.valid    || 0;
            var issueCount = d.issues   || 0;
            var score      = d.score    || 0;

            var scoreBg  = score >= 85 ? '#f0fdf4' : score >= 60 ? '#fffbeb' : '#fef2f2';
            var scoreBdr = score >= 85 ? '#bbf7d0' : score >= 60 ? '#fde68a' : '#fecaca';
            var scoreClr = score >= 85 ? '#059669' : score >= 60 ? '#b45309' : '#dc2626';
            var scoreLbl = score >= 85 ? '<?php echo addslashes(xlt("Clean")); ?>'
                         : score >= 60 ? '<?php echo addslashes(xlt("Risky")); ?>'
                         :               '<?php echo addslashes(xlt("Likely Denied")); ?>';

            var html = '<div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">'
                + '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 18px;flex:1;min-width:110px;text-align:center;">'
                    + '<div style="font-size:28px;font-weight:800;color:#059669;">' + valid + '</div>'
                    + '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-top:4px;"><?php echo addslashes(xlt("Valid Claims")); ?></div>'
                + '</div>'
                + '<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 18px;flex:1;min-width:110px;text-align:center;">'
                    + '<div style="font-size:28px;font-weight:800;color:#dc2626;">' + issueCount + '</div>'
                    + '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-top:4px;"><?php echo addslashes(xlt("Claims with Issues")); ?></div>'
                + '</div>'
                + '<div style="background:' + scoreBg + ';border:1px solid ' + scoreBdr + ';border-radius:8px;padding:14px 18px;flex:1;min-width:110px;text-align:center;">'
                    + '<div style="font-size:28px;font-weight:800;color:' + scoreClr + ';">' + score + '%</div>'
                    + '<div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-top:4px;">' + scoreLbl + '</div>'
                + '</div>'
            + '</div>';

            // -- Row builder with expandable claim list --------------------
            var _ri = 0;
            function makeRow(type, msg, count, claims, rowBg, rowBdr, acc, iBg, iClr, iChar, dependency, fixLabel, fixUrl) {
                var id = 'bm-cl-' + (_ri++);
                var has = claims && claims.length > 0;
                var listHtml = '';
                if (has) {
                    listHtml = '<div id="' + id + '" style="display:none;margin-top:8px;padding:8px 10px;'
                        + 'background:#fff;border:1px solid ' + rowBdr + ';border-radius:6px;">'
                        + '<div style="font-size:10px;font-weight:700;text-transform:uppercase;'
                        + 'letter-spacing:.06em;color:#6b7280;margin-bottom:6px;"><?php echo addslashes(xlt("Affected Patients")); ?></div>'
                        + '<div style="display:flex;flex-wrap:wrap;gap:5px;">';
                    claims.forEach(function(c) {
                        var name = (typeof c === 'object') ? c.name : c;
                        var url  = (typeof c === 'object' && c.fix_url) ? c.fix_url : null;
                        if (url) {
                            var _mn = 'var _u=this.getAttribute(\'href\');try{if(window.top&&window.top.mdiNavGo)window.top.mdiNavGo(_u);else window.top.location=_u;}catch(e){}return false;';
                        listHtml += '<a href="' + bmEsc(url) + '" onclick="' + _mn + '" style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:12px;'
                                + 'padding:3px 10px;font-size:11.5px;font-weight:600;color:#5b21b6;white-space:nowrap;text-decoration:none;display:inline-block;cursor:pointer;">'
                                + bmEsc(name) + ' &#8599;</a>';
                        } else {
                            listHtml += '<span style="background:#ede9fe;border:1px solid #c4b5fd;border-radius:12px;'
                                + 'padding:3px 10px;font-size:11.5px;font-weight:600;color:#5b21b6;white-space:nowrap;">'
                                + bmEsc(name) + '</span>';
                        }
                    });
                    listHtml += '</div></div>';
                }
                var toggleHtml = has
                    ? ' <button onclick="(function(el,btn){var o=el.style.display===\'block\';el.style.display=o?\'none\':\'block\';btn.textContent=o?\'Show patients \u25be\':\'Hide patients \u25b4\';})(document.getElementById(\'' + id + '\'),this);" style="font-size:10px;font-weight:600;color:' + acc + ';background:none;border:none;cursor:pointer;padding:0;margin-left:6px;vertical-align:middle;text-decoration:underline;">Show patients \u25be</button>'
                    : '';
                var fixBtn = fixUrl
                    ? ' <a href="' + bmEsc(fixUrl) + '" onclick="var _u=this.getAttribute(\'href\');try{if(window.top&&window.top.mdiNavGo)window.top.mdiNavGo(_u);else window.top.location=_u;}catch(e){}return false;" style="font-size:10px;font-weight:700;color:#fff;background:' + acc + ';border-radius:4px;padding:2px 7px;text-decoration:none;margin-left:6px;white-space:nowrap;">' + bmEsc(fixLabel || 'Fix') + '</a>'
                    : '';
                var depHtml = dependency
                    ? '<div style="margin-top:6px;padding:5px 10px;background:rgba(0,0,0,.03);'
                        + 'border-left:2px solid ' + rowBdr + ';border-radius:0 4px 4px 0;'
                        + 'font-size:11px;color:#6b7280;font-style:italic;">'
                        + '&#8627; ' + bmEsc(dependency) + '</div>'
                    : '';
                return '<div style="padding:10px 14px;background:' + rowBg + ';border:1px solid ' + rowBdr + ';'
                    + 'border-left:3px solid ' + acc + ';border-radius:7px;margin-bottom:6px;">'
                    + '<div style="display:flex;align-items:center;gap:8px;">'
                        + '<span style="display:inline-flex;align-items:center;justify-content:center;'
                        + 'width:20px;height:20px;border-radius:50%;background:' + iBg + ';color:' + iClr + ';'
                        + 'font-size:10px;font-weight:700;flex-shrink:0;">' + iChar + '</span>'
                        + '<div style="flex:1;font-size:12px;"><strong>' + bmEsc(type) + ':</strong> ' + bmEsc(msg) + fixBtn + toggleHtml + '</div>'
                        + '<span style="font-size:11px;font-weight:700;color:#6b7280;white-space:nowrap;">'
                        + count + ' claim' + (count !== 1 ? 's' : '') + '</span>'
                    + '</div>' + listHtml + depHtml + '</div>';
            }

            if (d.errors && d.errors.length) {
                html += '<div style="margin-bottom:16px;">'
                    + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#dc2626;margin-bottom:8px;">'
                    + '&#10007; <?php echo addslashes(xlt("Errors - Block Submission")); ?></div>';
                d.errors.forEach(function(e) {
                    html += makeRow(e.type, e.message, e.count||1, e.claims||[],
                        '#fff8f8','#fecaca','#dc2626','#fee2e2','#dc2626','&#10007;',
                        e.dependency || null, e.fix_label || null, e.fix_url || null);
                });
                html += '</div>';
            }
            if (d.warnings && d.warnings.length) {
                html += '<div style="margin-bottom:16px;">'
                    + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#b45309;margin-bottom:8px;">'
                    + '&#9888; <?php echo addslashes(xlt("Warnings - Allow but Risky")); ?></div>';
                d.warnings.forEach(function(w) {
                    html += makeRow(w.type, w.message, w.count||1, w.claims||[],
                        '#fffbf0','#fde68a','#b45309','#fef3c7','#b45309','!',
                        null, w.fix_label || null, w.fix_url || null);
                });
                html += '</div>';
            }
            if (d.insights && d.insights.length) {
                html += '<div style="margin-bottom:8px;">'
                    + '<div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#1d4ed8;margin-bottom:8px;">&#128161; <?php echo addslashes(xlt("Smart Insights")); ?></div>';
                d.insights.forEach(function(ins) {
                    html += '<div style="padding:10px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-left:3px solid #3b82f6;border-radius:7px;margin-bottom:6px;font-size:12px;color:#1e40af;">&#128161; ' + bmEsc(ins.message) + '</div>';
                });
                html += '</div>';
            }
            if (!(d.errors && d.errors.length) && !(d.warnings && d.warnings.length)) {
            }
            if (!(d.errors && d.errors.length) && !(d.warnings && d.warnings.length)) {
                html += '<div style="text-align:center;padding:24px;color:#059669;font-size:13px;font-weight:600;">&#10003; <?php echo addslashes(xlt("All claims are valid - ready to submit!")); ?></div>';
            }

            var modalBody = document.getElementById('bm-issues-modal-body');
            if (!modalBody) {
                var dlg = document.createElement('div');
                dlg.className = 'modal fade'; dlg.id = 'bm-issues-modal';
                dlg.setAttribute('tabindex','-1'); dlg.setAttribute('role','dialog');
                var inner = document.createElement('div');
                inner.className = 'modal-dialog modal-lg'; inner.style.maxWidth = '780px';
                inner.setAttribute('role','document');
                inner.innerHTML = '<div class="modal-content">'
                    + '<div class="modal-header" style="background:#1a1d2e;color:#fff;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;">'
                    + '<h5 style="font-size:14px;font-weight:700;color:#fff;margin:0;"><?php echo addslashes(xlt("Validation Report")); ?></h5>'
                    + '<button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.7;font-size:22px;background:none;border:none;cursor:pointer;line-height:1;">&times;</button>'
                    + '</div><div class="modal-body" id="bm-issues-modal-body" style="max-height:65vh;overflow-y:auto;padding:20px 24px;"></div>'
                    + '<div class="modal-footer" style="background:#f8f6fd;border-top:1px solid #e0d9f5;padding:10px 20px;display:flex;align-items:center;gap:8px;">'
                    + '<span id="bm-issues-modal-ts" style="font-size:11px;color:#9ca3af;flex:1;"></span>'
                    + '<button type="button" class="btn btn-sm btn-outline-secondary" data-dismiss="modal"><?php echo addslashes(xlt("Close")); ?></button>'
                    + '<button type="button" id="bm-issues-revalidate" class="btn btn-sm" style="background:#5b21b6;border-color:#5b21b6;color:#fff;"><?php echo addslashes(xlt("Re-Validate")); ?></button>'
                    + '</div></div>';
                dlg.appendChild(inner); document.body.appendChild(dlg);
                document.getElementById('bm-issues-revalidate').addEventListener('click', function() {
                    if (typeof $ !== 'undefined' && $.fn && $.fn.modal) { $('#bm-issues-modal').modal('hide'); }
                    else { document.getElementById('bm-issues-modal').style.display = 'none'; }
                    setTimeout(bmRunValidation, 300);
                });
                modalBody = document.getElementById('bm-issues-modal-body');
            }
            var tsSpan = document.getElementById('bm-issues-modal-ts');
            if (tsSpan) tsSpan.textContent = '<?php echo addslashes(xlt("Last validated:")); ?> ' + bmEsc(d.timestamp || '');
            modalBody.innerHTML = html;
            if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
                $('#bm-issues-modal').modal('show');
            } else {
                var m = document.getElementById('bm-issues-modal');
                m.style.cssText = 'display:block;position:fixed;z-index:9999;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);overflow:auto;';
            }
        }


        function bmEsc(s) {
            return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }

        function doConfirm(Message) {
            placeModal(Message);
            return new Promise(function(resolve, reject) {
                $('#confirmDialog').modal('show');
                $('#confirmDialog .btn-continue').click(function() {
                    $(this).off();
                    resolve("btn-continue");
                });
                $('#confirmDialog .btn-clear').click(function() {
                    $(this).off();
                    resolve("btn-clear");
                });
                $('#confirmDialog .btn-validate').click(function() {
                    $(this).off();
                    resolve("btn-validate");
                });
                $('#confirmDialog .btn-danger').click(function() {
                    $(this).off();
                    reject("btn-cancel");
                });
            });
        }

        async function confirmActions(e, mType) {
            e.preventDefault();
            let Message = "";
            let ClaimCount = 0;
            for (var CheckBoxBillingIndex = 0;; CheckBoxBillingIndex++) {
                CheckBoxBillingObject = document.getElementById('CheckBoxBilling' +
                    CheckBoxBillingIndex);
                if (!CheckBoxBillingObject) break;
                if (CheckBoxBillingObject.checked) {
                    ++ClaimCount;
                }
            }
            let addOn = <?php echo xlj('click View Log and review for errors.'); ?>;
            if (mType == '1') {
                Message = <?php echo xlj('After saving your batch'); ?> + ", " + addOn;
            } else if (mType == '2') {
                Message = <?php echo xlj('After saving the PDF'); ?> + ", " + addOn;
            } else if (mType == '3') {
                Message = <?php echo xlj('After saving the TEXT file'); ?> + "(s), " + addOn;
            }
            Message += "<br/><br/>" + <?php echo xlj('Validate and Clear validates then sets claims status only, to billed, leaving billing process unaltered and claim submission resets to unsubmitted.'); ?>;
            Message += "<br/><br/>" + <?php echo xlj('Validate Only does a claim validation dry run for errors leaving claim status unaltered.'); ?>;
            Message += "<br/><br/>" + <?php echo xlj('Continue completes selected billing option normally.'); ?>;
            Message += "<br/><br/>" + <?php echo xlj('Total of'); ?> + ' ' + ClaimCount + ' ' + <?php echo xlj('claims selected.'); ?> + "\n";
            let sName = e.currentTarget.name;
            // wait for confirm result
            await doConfirm(Message).then(action => {
                console.log(sName, action);
                // set post button for form submit
                $('<input>').attr({
                    type: 'hidden',
                    id: "submitTask",
                    name: sName,
                    value: 'true'
                }).prependTo(document.update_form);
                // passing confirm clicked button
                $('<input>').attr({
                    type: 'hidden',
                    id: "confirmTask",
                    name: action,
                    value: 1
                }).prependTo(document.update_form);
                return action;
            }).then(action => {
                // submit update_form then cleanup
                return doSubmit(action).then(function() {
                    $("#submitTask").remove();
                    $("#confirmTask").remove();
                });
            }).catch(function(why) {
                // cancel clicked in confirm. do nothing...
                console.warn("Task was canceled", why);
            });

            return false;
        }

        function placeModal(Message) {
            let mConfirm = <?php echo xlj('Please Confirm') ?>;
            let mClear = <?php echo xlj('Validate and Clear') ?>;
            let mVal = <?php echo xlj('Validate Only') ?>;
            let mCont = <?php echo xlj('Continue') ?>;
            let mCancel = <?php echo xlj('Cancel') ?>;
            let dModal = "<div class='modal fade' id='confirmDialog' aria-hidden='true'><div class='modal-dialog'><div class='modal-content'>" +
                "<div class='modal-header'><h4 class='modal-title'>" + mConfirm + "</h4></div><div class='modal-body'>" +
                "<label>" + Message + "</label></div><div class='modal-footer'>" +
                "<button type='button' class='btn btn-primary btn-clear' data-dismiss='modal'>" + mClear + "</button>" +
                "<button type='button' class='btn btn-primary btn-validate' data-dismiss='modal'>" + mVal + "</button>" +
                "<button type='button' class='btn btn-primary btn-continue' data-dismiss='modal'>" + mCont + "</button>" +
                "<button type='button' class='btn btn-secondary btn-cancel' data-dismiss='modal'>" + mCancel + "</button>" +
                "</div></div></div></div>";

            $("body").append(dModal);

            $('#confirmDialog').on('hidden.bs.modal', function(e) {
                // remove modal
                $(this).remove();
                console.log("Confirm Modal Removed");
                // remove this event for next time.
                $(this).off(e);
            });
        }

        function onNewPayer(oEvent) {
            let p = oEvent.target.options[event.target.selectedIndex].dataset.partner;
            let partnerSelect = oEvent.target.options[event.target.selectedIndex].dataset.partner;
            partnerSelect = partnerSelect ? partnerSelect : -1;
            document.getElementById("partners").value = partnerSelect;
        }

        // Called by X12 / HCFA buttons - shows confirm dialog then submits normally.
        // Browser handles the file download to the default Downloads folder.
        // mType: '1'=X12/EBilling  '2'=HCFA PDF  '3'=HCFA TEXT
        async function confirmAndSave(e, mType) {
            e.preventDefault();
            var sName = e.currentTarget.name;

            var ClaimCount = 0;
            for (var i = 0;; i++) {
                var cb = document.getElementById('CheckBoxBilling' + i);
                if (!cb) break;
                if (cb.checked) ClaimCount++;
            }
            var addOn   = <?php echo xlj('click View Log and review for errors.'); ?>;
            var baseMsg = (mType === '1') ? <?php echo xlj('After saving your batch'); ?> :
                          (mType === '2') ? <?php echo xlj('After saving the PDF'); ?> :
                                            <?php echo xlj('After saving the TEXT file'); ?>;
            var Message = baseMsg + ', ' + addOn;
            Message += '<br/><br/><?php echo addslashes(xlt("Validate and Clear validates then sets claims status only, to billed, leaving billing process unaltered and claim submission resets to unsubmitted.")); ?>';
            Message += '<br/><br/><?php echo addslashes(xlt("Validate Only does a claim validation dry run for errors leaving claim status unaltered.")); ?>';
            Message += '<br/><br/><?php echo addslashes(xlt("Continue completes selected billing option normally.")); ?>';
            Message += '<br/><br/><?php echo addslashes(xlt("Total of")); ?> ' + ClaimCount + ' <?php echo addslashes(xlt("claims selected.")); ?>';

            await doConfirm(Message).then(action => {
                $('<input>').attr({ type: 'hidden', id: 'submitTask',  name: sName,  value: 'true' }).prependTo(document.update_form);
                $('<input>').attr({ type: 'hidden', id: 'confirmTask', name: action, value: 1      }).prependTo(document.update_form);
                return action;
            }).then(function(action) {
                doSubmit(action).then(function() {
                    $('#submitTask').remove();
                    $('#confirmTask').remove();
                });
            }).catch(function(why) {
                console.warn('Task cancelled', why);
            });
            return false;
        }

        function select_all() {
            for ($i = 0; $i < document.update_form.length; $i++) {
                $name = document.update_form[$i].name;
                if ($name.substring(0, 7) == "claims[" && $name.substring($name.length -
                        6) == "[bill]") {
                    document.update_form[$i].checked = true;
                }
            }
            set_button_states();
        }

        function set_button_states() {
            var f = document.update_form;
            var count0 = 0; // selected and not billed or queued
            var count1 = 0; // selected and queued
            var count2 = 0; // selected and billed
            for ($i = 0; $i < f.length; ++$i) {
                $name = f[$i].name;
                if ($name.substring(0, 7) == "claims[" && $name.substring($name.length -
                        6) == "[bill]" && f[$i].checked == true) {
                    if (f[$i].value == '0') ++count0;
                    else if (f[$i].value == '1' || f[$i].value == '5') ++count1;
                    else ++count2;
                }
            }
            // Enable Validate Claims only when list is loaded (checkboxes exist in DOM)
            var listLoaded = document.querySelector('input[id^="CheckBoxBilling"]') !== null;
            var validateBtn = document.getElementById('btn-run-validate');
            if (validateBtn) {
                validateBtn.disabled = !listLoaded;
                validateBtn.title = listLoaded
                    ? '<?php echo addslashes(xla("Run comprehensive claim validation")); ?>'
                    : '<?php echo addslashes(xla("Load claims first using Update List")); ?>';
                // Also update the helper text below the button
                var helpText = validateBtn.parentNode && validateBtn.parentNode.nextElementSibling;
                if (helpText && helpText.style !== undefined) {
                    helpText.textContent = listLoaded
                        ? '<?php echo addslashes(xlt("Validate claims before submission")); ?>'
                        : '<?php echo addslashes(xlt("Load claims first")); ?>';
                }
            }
            // Gate ALL action buttons on list being loaded
            var totalChecked = count0 + count1 + count2;
            var can_mark = listLoaded && (totalChecked === 0 || (count0 + count1) > 0);
            var can_bill = listLoaded && (count2 > 0);
            <?php if (file_exists($EXPORT_INC)) { ?>
            if (f.bn_external) f.bn_external.disabled = !listLoaded;
            <?php } else { ?>
            if (f.bn_x12)  f.bn_x12.disabled  = !listLoaded;
                <?php if ($GLOBALS['support_encounter_claims']) { ?>
            if (f.bn_x12_encounter) f.bn_x12_encounter.disabled = !listLoaded;
            <?php } ?>
                <?php if ($GLOBALS['ub04_support']) { ?>
            if (f.bn_process_ub04_support) f.bn_process_ub04_support.disabled = !listLoaded;
            <?php } ?>
            if (f.bn_reopen) f.bn_reopen.disabled = !can_bill;
            // Reopen + Reactivate button — same gating as bn_reopen (needs list loaded + billed claims)
            var rrBtn = document.querySelector('button[onclick*="bmReopenReactivateSelected"]');
            if (rrBtn) rrBtn.disabled = !listLoaded;
            // HCFA 1500 + HCFA Preprinted buttons
            document.querySelectorAll('.bm-mdi-btn').forEach(function(btn) {
                btn.disabled = !listLoaded;
            });
            <?php } ?>
            if (f.bn_mark) f.bn_mark.disabled = !can_mark;
        }

        // Process a click to go to an encounter.
        function toencounter(pid, pubpid, pname, enc, datestr, dobstr) {
            top.restoreSession();
            encurl = 'patient_file/encounter/encounter_top.php?set_encounter=' + encodeURIComponent(enc) +
                '&pid=' + encodeURIComponent(pid);
            parent.left_nav.setPatient(pname, pid, pubpid, '', dobstr);
            parent.left_nav.setEncounter(datestr, enc, 'enc');
            parent.left_nav.loadFrame('enc2', 'enc', encurl);
        }

        // Process a click to go to an patient.
        function topatient(pid, pubpid, pname, enc, datestr, dobstr) {
            top.restoreSession();
            paturl = 'patient_file/summary/demographics.php?set_pid=' + encodeURIComponent(pid);
            parent.left_nav.setPatient(pname, pid, pubpid, '', dobstr);
            parent.left_nav.loadFrame('pat2', 'pat', paturl);
        }

        function popMBO(pid, enc, mboid) {
            if (!window.focus) return true;
            if (!ProcessBeforeSubmitting()) return false;
            top.restoreSession();
            let qstring = "&pid=" + encodeURIComponent(pid) + "&enc=" + encodeURIComponent(enc) + "&id=" + encodeURIComponent(mboid);
            let href = "<?php echo $GLOBALS['web_root']?>/interface/patient_file/encounter/view_form.php?formname=misc_billing_options&isBilling=1" + qstring;
            dlgopen(href, 'mbopop', 'modal-lg', 750, false, '', {
                sizeHeight: 'full' // override min height auto size.
            });
            return true;
        }

        function popUB04(pid, enc) {
            if (!window.focus) return true;
            if (!ProcessBeforeSubmitting()) return false;
            top.restoreSession();
            let href = "<?php echo $GLOBALS['web_root']?>/interface/billing/ub04_form.php?pid=" + encodeURIComponent(pid) + "&enc=" + encodeURIComponent(enc);
            dlgopen(href, 'ub04pop', 1175, 750, false, '', {
                sizeHeight: 'full' // override min height auto size.
            });
            return true;
        }

        var EncounterDateArray = new Array;
        var CalendarCategoryArray = new Array;
        var EncounterIdArray = new Array;
        var EncounterNoteArray = new Array;

        function SubmitTheScreen() { //Action on Update List link
            if (!ProcessBeforeSubmitting()) return false;
            if (!criteriaSelectHasValue('final_this_page_criteria')) return false;
            var btn = document.getElementById('update-tooltip');
            if (btn) { btn.innerHTML = '<i class="fa fa-sync fa-spin fa-1x"></i> <?php echo addslashes(xlt("Updating...")); ?>'; btn.disabled = true; }
            top.restoreSession();
            document.the_form.mode.value = 'change';
            document.the_form.target = '_self';
            document.the_form.action = 'billing_report.php';
            document.the_form.submit();
            return true;
        }

        function SubmitTheScreenPrint() { //Action on View Printable Report link
            if (!ProcessBeforeSubmitting()) return false;
            top.restoreSession();
            // Read filter values directly from the filter bar inputs by ID
            function gv(id) {
                var el = document.getElementById(id);
                return el ? encodeURIComponent(el.value || '') : '';
            }
            // For hidden inputs that back the autocomplete fields
            function ghv(id) {
                var el = document.getElementById(id);
                return el ? encodeURIComponent(el.value || '') : '';
            }
            var params =
                'bm_billed='    + gv('bm_billed') +
                '&bm_patient='  + ghv('bm_patient') +
                '&bm_submitter='+ gv('bm_submitter') +
                '&bm_insurance='+ ghv('bm_insurance') +
                '&bm_svc_from=' + gv('bm_svc_from') +
                '&bm_svc_to='   + gv('bm_svc_to') +
                '&bm_bill_from='+ gv('bm_bill_from') +
                '&bm_bill_to='  + gv('bm_bill_to') +
                '&bm_batch='    + ghv('bm_batch') +
                '&exclude_legacy=1';
            dlgopen('print_billing_report.php?' + params, '_blank', 1100, 750, '', <?php echo xlj('Billing Report'); ?>, {
                allowResize: true,
                allowDrag:   true,
                type:        'iframe'
            });
            return true;
        }

        function SubmitTheEndDayPrint() { //Action on View End of Day Report link
            if (!ProcessBeforeSubmitting()) return false;
            top.restoreSession();
            document.the_form.target = 'new';
            <?php if ($GLOBALS['use_custom_daysheet'] == 1) { ?>
            document.the_form.action = 'print_daysheet_report_num1.php';
            <?php } ?>
            <?php if ($GLOBALS['use_custom_daysheet'] == 2) { ?>
            document.the_form.action = 'print_daysheet_report_num2.php';
            <?php } ?>
            <?php if ($GLOBALS['use_custom_daysheet'] == 3) { ?>
            document.the_form.action = 'print_daysheet_report_num3.php';
            <?php } ?>
            document.the_form.submit();
            return true;
        }

        function SubmitTheScreenExportOFX() { //Action on Export OFX link
            if (!ProcessBeforeSubmitting()) return false;
            top.restoreSession();
            document.the_form.mode.value = 'export';
            document.the_form.target = '_self';
            document.the_form.action = 'billing_report.php';
            document.the_form.submit();
            return true;
        }

        function TestExpandCollapse() { //Checks whether the Expand All, Collapse All labels need to be placed.If any result set is there these will be placed.
            var divnosEl = document.getElementById("divnos");
            if (!divnosEl) return; // element not in DOM yet (fresh load before results render)
            var set = -1;
            for (i = 1; i <= divnosEl.value; i++) {
                var ele = document.getElementById("divid_" + i);
                if (ele) {
                    set = 1;
                    break;
                }
            }
            if (set == -1) {
                if (document.getElementById("expandAllCollapseAll")) {
                    document.getElementById("expandAllCollapseAll").innerHTML = '';
                }
            }
        }

        function expandcollapse(atr) {
            if (atr == "expand") { //Called in the Expand All, Collapse All links(All items will be expanded or collapsed)
                var _divnosEl = document.getElementById("divnos");
                if (!_divnosEl) return;
                for (i = 1; i <= _divnosEl.value; i++) {
                    var mydivid = "divid_" + i;
                    var myspanid = "spanid_" + i;
                    var ele = document.getElementById(mydivid);
                    var text = document.getElementById(myspanid);
                    if (ele) {
                        ele.style.display = "inline";
                        text.innerHTML =
                            jsText(<?php echo xlj('Collapse'); ?>);
                    }
                }
            } else {
                var _divnosEl = document.getElementById("divnos");
                if (!_divnosEl) return;
                for (i = 1; i <= _divnosEl.value; i++) {
                    var mydivid = "divid_" + i;
                    var myspanid = "spanid_" + i;
                    var ele = document.getElementById(mydivid);
                    var text = document.getElementById(myspanid);
                    if (ele) {
                        ele.style.display = "none";
                        text.innerHTML =
                            jsText(<?php echo xlj('Expand'); ?>);
                    }
                }
            }
        }

        function divtoggle(spanid, divid) { //Called in the Expand, Collapse links(This is for a single item)
            var ele = document.getElementById(divid);
            if (ele) {
                var text = document.getElementById(spanid);
                if (ele.style.display == "inline") {
                    ele.style.display = "none";
                    text.innerHTML =
                        jsText(<?php echo xlj('Expand'); ?>);
                } else {
                    ele.style.display = "inline";
                    text.innerHTML =
                        jsText(<?php echo xlj('Collapse'); ?>);
                }
            }
        }

    

function mdiGenerateHCFA() {
    var checked = [];
    document.querySelectorAll('input[id^="CheckBoxBilling"]:checked').forEach(function(cb) {
        checked.push(cb.dataset.encId);
    });
    if (!checked.length) { alert('Please select at least one claim.'); return; }

    window.open('', 'hcfa1500_popup', 'width=620,height=540,resizable=yes,scrollbars=yes,toolbar=no,menubar=no,location=no,status=no');

    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'mdi_hcfa_print.php';
    form.target = 'hcfa1500_popup';
    var csrf = document.querySelector('[name="csrf_token_form"]');
    if (csrf) {
        var t = document.createElement('input'); t.type='hidden'; t.name='csrf_token_form'; t.value=csrf.value; form.appendChild(t);
    }
    checked.forEach(function(val) {
        var i = document.createElement('input'); i.type='hidden'; i.name='mdi_encounters[]'; i.value=val; form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}


function mdiGenerateHCFAImage() {
    var checked = [];
    document.querySelectorAll('input[id^="CheckBoxBilling"]:checked').forEach(function(cb) {
        checked.push(cb.dataset.encId);
    });
    if (!checked.length) { alert('Please select at least one claim.'); return; }

    window.open('', 'hcfa_preprinted_popup', 'width=620,height=540,resizable=yes,scrollbars=yes,toolbar=no,menubar=no,location=no,status=no');

    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'mdi_hcfa_print_img.php';
    form.target = 'hcfa_preprinted_popup';
    var csrf = document.querySelector('[name="csrf_token_form"]');
    if (csrf) {
        var t = document.createElement('input'); t.type='hidden'; t.name='csrf_token_form'; t.value=csrf.value; form.appendChild(t);
    }
    checked.forEach(function(val) {
        var i = document.createElement('input'); i.type='hidden'; i.name='mdi_encounters[]'; i.value=val; form.appendChild(i);
    });
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}


function criteriaSelectHasValue(select) {
            return true; // filter bar handles its own validation
        }
    </script>
    <?php require_once "$srcdir/../interface/reports/report.script.php"; ?>
    <!-- Criteria Section common javascript page-->
    <!-- =============Included for Insurance ajax criteria==== -->
    <?php require_once "{$GLOBALS['srcdir']}/ajax/payment_ajax_jav.inc.php"; ?>
    <style>
        #ajax_div_insurance {
            position: absolute; z-index: 10;
            background-color: #FBFDD0; border: 1px solid #ccc; padding: 10px;
        }
    </style>
    <div id="ajax_div_insurance" style="display:none;"></div>
    <script>
        // Override from report.script.php - ValidateDateCriteria() looks for old
        // criteria-tab <select> elements that no longer exist, causing null.options crash
        function ProcessBeforeSubmitting() {
            top.restoreSession();
            return true;
        }
        // Safe replacement for TakeActionOnHide from payment_ajax_jav.inc.php
        document.onclick = function(e) {
            var div = document.getElementById('ajax_div_insurance');
            if (div) { div.style.display = 'none'; }
        };

       
    </script>
    <!-- =============Included for Insurance ajax criteria==== -->
    <title><?php echo xlt('Billing Manager'); ?></title>
    <?php
    $arrOeUiSettings = array(
        'heading_title' => xl('Billing Manager'),
        'include_patient_name' => false,
        'expandable' => false,
        'expandable_files' => array('billing_report_xpd'),
        'action' => "conceal",
        'action_title' => "",
        'action_href' => "",
        'show_help_icon' => false,
        'help_file_name' => ""
    );
    $oemr_ui = new OemrUI($arrOeUiSettings);

    
    ?>
</head>

<body onload="TestExpandCollapse()">
    <div id="container_div" class="<?php echo attr($oemr_ui->oeContainer()); ?> mt-3">
        <div class="row">
            <div class="col-sm-12">
                <?php echo $oemr_ui->pageHeading() . "\r\n"; ?>
            </div>
        </div>

        <!-- ══════════ FILTER BAR ══════════ -->
        <form class="form" name='the_form' method='post' action='billing_report.php' onsubmit='return top.restoreSession()'>
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type='hidden' name='mode' value='change' />
            <?php
            // Set up criteria arrays so criteria.tab.php still runs (needed for BillingReport internals)
            $TPSCriteriaDisplayRadioMaster = array();
            $TPSCriteriaRadioKeyMaster = array();
            $TPSCriteriaQueryDropDownMaster = array();
            $TPSCriteriaQueryDropDownMasterDefault = array();
            $TPSCriteriaQueryDropDownMasterDefaultKey = array();
            $TPSCriteriaIncludeMaster = array();
            if ($daysheet) {
                $TPSCriteriaDisplayMaster = array(xl("Date of Service"),xl("Date of Entry"),xl("Date of Billing"),xl("Claim Type"),xl("Patient Name"),xl("Patient Id"),xl("Insurance Company"),xl("Encounter"),xl("Whether Insured"),xl("Charge Coded"),xl("Billing Status"),xl("Authorization Status"),xl("Last Level Billed"),xl("X12 Partner"),xl("User"));
                $TPSCriteriaKeyMaster = "form_encounter.date,billing.date,claims.process_time,claims.target,patient_data.fname,form_encounter.pid,claims.payer_id,form_encounter.encounter,insurance_data.provider,billing.id,billing.billed,billing.authorized,form_encounter.last_level_billed,billing.x12_partner_id,billing.user";
                $TPSCriteriaDataTypeMaster = "datetime,datetime,datetime,radio,text_like,text,include,text,radio,radio,radio,radio_like,radio,query_drop_down,text";
            } else {
                $TPSCriteriaDisplayMaster = array(xl("Date of Service"),xl("Date of Entry"),xl("Date of Billing"),xl("Claim Type"),xl("Patient Name"),xl("Patient Id"),xl("Insurance Company"),xl("Encounter"),xl("Whether Insured"),xl("Charge Coded"),xl("Billing Status"),xl("Authorization Status"),xl("Last Level Billed"),xl("X12 Partner"));
                $TPSCriteriaKeyMaster = "form_encounter.date,billing.date,claims.process_time,claims.target,patient_data.fname,form_encounter.pid,claims.payer_id,form_encounter.encounter,insurance_data.provider,billing.id,billing.billed,billing.authorized,form_encounter.last_level_billed,billing.x12_partner_id";
                $TPSCriteriaDataTypeMaster = "datetime,datetime,datetime,radio,text_like,text,include,text,radio,radio,radio,radio_like,radio,query_drop_down";
            }
            $TPSCriteriaDisplayRadioMaster[1] = array(xl("All"),xl("eClaims"),xl("Paper"));
            $TPSCriteriaRadioKeyMaster[1] = "all,standard,hcfa";
            $TPSCriteriaDisplayRadioMaster[2] = array(xl("All"),xl("Insured"),xl("Non-Insured"));
            $TPSCriteriaRadioKeyMaster[2] = "all,1,0";
            $TPSCriteriaDisplayRadioMaster[3] = array(xl("All"),xl("Coded"),xl("Not Coded"));
            $TPSCriteriaRadioKeyMaster[3] = "all,not null,null";
            $TPSCriteriaDisplayRadioMaster[4] = array(xl("All"),xl("Unbilled"),xl("Billed"),xl("Denied"));
            $TPSCriteriaRadioKeyMaster[4] = "all,0,1,7";
            $TPSCriteriaDisplayRadioMaster[5] = array(xl("All"),xl("Authorized"),xl("Unauthorized"));
            $TPSCriteriaRadioKeyMaster[5] = "%,1,0";
            $TPSCriteriaDisplayRadioMaster[6] = array(xl("All"),xl("None{{Insurance}}"),xl("Ins 1"),xl("Ins 2 or Ins 3"));
            $TPSCriteriaRadioKeyMaster[6] = "all,0,1,2";
            $TPSCriteriaQueryDropDownMaster[1] = "SELECT name,id FROM x12_partners;";
            $TPSCriteriaQueryDropDownMasterDefault[1] = xl("All");
            $TPSCriteriaQueryDropDownMasterDefaultKey[1] = "all";
            $TPSCriteriaIncludeMaster[1] = "OpenEMR\Billing\BillingReport::insuranceCompanyDisplay";
            if (!isset($_REQUEST['mode'])) {
                $_REQUEST['final_this_page_criteria'][0] = "form_encounter.date|between|" . date("0-0-0 00:00:00") . "|" . date("Y-m-d 23:59:59");
                //$_REQUEST['final_this_page_criteria'][1] = "billing.billed|=|0";
                $_REQUEST['final_this_page_criteria_text'][1] = xl("Billing Status = Unbilled");
                $_REQUEST['master_from_date_form_encounter_date'] = date("Y-m-d");
                $_REQUEST['master_to_date_form_encounter_date'] = date("Y-m-d");
                //$_REQUEST['radio_billing_billed'] = 0;
                $_REQUEST['query_drop_down_master_billing_x12_partner_id'] = "";
            }
            // Run criteria.tab.php but suppress its HTML output - we only need the hidden inputs it adds
            ob_start();
            require_once "$srcdir/../interface/reports/criteria.tab.php";
            ob_end_clean();

            // Build insurance list for our dropdown
            $ins_list = sqlStatement("SELECT DISTINCT name, id FROM insurance_companies ORDER BY name");
            // Build x12 partners list
            $x12_list = sqlStatement("SELECT name, id FROM x12_partners ORDER BY name");

            // Resolve selected filter values from POST/REQUEST
            $sel_submitter  = $_POST['bm_submitter']  ?? $_REQUEST['query_drop_down_master_billing_x12_partner_id'] ?? '';
            $sel_billed     = $_POST['bm_billed']     ?? ($_REQUEST['radio_billing_billed'] ?? '0'); // default Unbilled
            $sel_insurance  = $_POST['bm_insurance']  ?? '';
            $sel_patient    = $_POST['bm_patient']    ?? '';
            $sel_bill_from  = $_POST['bm_bill_from']  ?? '';
            $sel_bill_to    = $_POST['bm_bill_to']    ?? '';
            $sel_batch      = $_POST['bm_batch']      ?? '';
            $sel_svc_from   = $_POST['bm_svc_from']   ?? '';
            $sel_svc_to     = $_POST['bm_svc_to']     ?? '';
            // Build distinct batch file list for the popup picker
            $batch_list = sqlStatement(
                "SELECT DISTINCT process_file
                   FROM claims
                  WHERE process_file IS NOT NULL
                    AND process_file != ''
                    AND process_file NOT LIKE '%,%'
                  ORDER BY process_file DESC
                  LIMIT 300"
            );
            $batch_options = [];
            while ($br = sqlFetchArray($batch_list)) { $batch_options[] = $br['process_file']; }
            ?>

            <div id="bm-filter-bar">
                <div class="filter-row">

                    <!-- Submitters (X12 Partners) -->
                    <div class="filter-group">
                        <label class="filter-label"><?php echo xlt('Submitter'); ?></label>
                        <select name="bm_submitter" class="form-control" id="bm_submitter">
                            <option value=""><?php echo xlt('All Submitters'); ?></option>
                            <?php
                            while ($row = sqlFetchArray($x12_list)) {
                                $sel = ($sel_submitter == $row['id']) ? ' selected' : '';
                                echo "<option value='" . attr($row['id']) . "'" . $sel . ">" . text($row['name']) . "</option>\n";
                            }
                            ?>
                        </select>
                    </div>

                    <!-- Billing Status -->
                    <div class="filter-group">
                        <label class="filter-label"><?php echo xlt('Billing Status'); ?></label>
                        <select name="bm_billed" class="form-control" id="bm_billed">
                            <option value="" <?php echo ($sel_billed === '') ? 'selected' : ''; ?>><?php echo xlt('All'); ?></option>
                            <option value="0" <?php echo ($sel_billed === '0' || $sel_billed === 0) ? 'selected' : ''; ?>><?php echo xlt('Unbilled'); ?></option>
                            <option value="1" <?php echo ($sel_billed === '1' || $sel_billed === 1) ? 'selected' : ''; ?>><?php echo xlt('Billed'); ?></option>
                            <option value="7" <?php echo ($sel_billed === '7') ? 'selected' : ''; ?>><?php echo xlt('Denied'); ?></option>
                        </select>
                    </div>

                    <!-- Insurance (autocomplete) -->
                    <div class="filter-group" style="position:relative;">
                        <label class="filter-label"><?php echo xlt('Insurance'); ?></label>
                        <?php
                        // Resolve the display name for any pre-selected insurance
                        $ins_display_val = '';
                        if (!empty($sel_insurance)) {
                            $ins_row = sqlQuery("SELECT name FROM insurance_companies WHERE id = ?", [$sel_insurance]);
                            if ($ins_row) { $ins_display_val = $ins_row['name']; }
                        }
                        ?>
                        <input type="text" id="bm_insurance_display" class="form-control"
                               autocomplete="off"
                               value="<?php echo attr($ins_display_val); ?>"
                               placeholder="<?php echo xla('Type to search...'); ?>" />
                        <input type="hidden" name="bm_insurance" id="bm_insurance"
                               value="<?php echo attr($sel_insurance); ?>" />
                        <div id="bm_insurance_ac" style="display:none;position:absolute;z-index:200;
                             background:#fff;border:1.5px solid #7c3aed;border-radius:7px;
                             box-shadow:0 4px 16px rgba(124,58,237,.15);min-width:220px;max-height:220px;
                             overflow-y:auto;top:100%;left:0;"></div>
                    </div>

                    <!-- Patient Name (autocomplete) -->
                    <div class="filter-group patient-group" style="position:relative;">
                        <label class="filter-label"><?php echo xlt('Patient Name'); ?></label>
                        <input type="text" id="bm_patient_display" class="form-control"
                               autocomplete="off"
                               value="<?php echo attr($sel_patient); ?>"
                               placeholder="<?php echo xla('Last, First...'); ?>" />
                        <!-- bm_patient carries the typed/selected name for the SQL LIKE filter -->
                        <input type="hidden" name="bm_patient" id="bm_patient"
                               value="<?php echo attr($sel_patient); ?>" />
                        <div id="bm_patient_ac" style="display:none;position:absolute;z-index:200;
                             background:#fff;border:1.5px solid #7c3aed;border-radius:7px;
                             box-shadow:0 4px 16px rgba(124,58,237,.15);min-width:220px;max-height:220px;
                             overflow-y:auto;top:100%;left:0;"></div>
                    </div>

                    <!-- Service Date (from/to) -->
                    <div class="filter-group date-group">
                        <label class="filter-label"><?php echo xlt('Svc Date From'); ?></label>
                        <input type="text" name="bm_svc_from" class="form-control datepicker"
                               id="bm_svc_from" value="<?php echo attr($sel_svc_from); ?>"
                               placeholder="<?php echo xla('YYYY-MM-DD'); ?>" />
                    </div>
                    <div class="filter-group date-group">
                        <label class="filter-label"><?php echo xlt('Svc Date To'); ?></label>
                        <input type="text" name="bm_svc_to" class="form-control datepicker"
                               id="bm_svc_to" value="<?php echo attr($sel_svc_to); ?>"
                               placeholder="<?php echo xla('YYYY-MM-DD'); ?>" />
                    </div>

                    <!-- Date of Billing (from/to) -->
                    <div class="filter-group date-group">
                        <label class="filter-label"><?php echo xlt('Bill Date From'); ?></label>
                        <input type="text" name="bm_bill_from" class="form-control datepicker"
                               id="bm_bill_from" value="<?php echo attr($sel_bill_from); ?>"
                               placeholder="<?php echo xla('YYYY-MM-DD'); ?>" />
                    </div>
                    <div class="filter-group date-group">
                        <label class="filter-label"><?php echo xlt('Bill Date To'); ?></label>
                        <input type="text" name="bm_bill_to" class="form-control datepicker"
                               id="bm_bill_to" value="<?php echo attr($sel_bill_to); ?>"
                               placeholder="<?php echo xla('YYYY-MM-DD'); ?>" />
                    </div>

                    <!-- Batch Number — styled popup button -->
                    <div class="filter-group" style="min-width:160px;max-width:240px;">
                        <label class="filter-label"><?php echo xlt('Batch Number'); ?></label>
                        <input type="hidden" name="bm_batch" id="bm_batch" value="<?php echo attr($sel_batch); ?>" />
                        <?php if (!empty($batch_options)) { ?>
                        <button type="button" id="bm-batch-btn" onclick="bmBatchOpen()" aria-haspopup="dialog">
                            <?php if (!empty($sel_batch)) { ?>
                            <span class="batch-tag">&#128196;</span>
                            <span class="batch-btn-label has-value"><?php echo text($sel_batch); ?></span>
                            <?php } else { ?>
                            <span class="batch-btn-label"><?php echo xlt('All Batches'); ?></span>
                            <?php } ?>
                            <span class="batch-caret">&#9660;</span>
                        </button>
                        <?php } else { ?>
                        <input type="text" class="form-control"
                               oninput="document.getElementById('bm_batch').value=this.value"
                               value="<?php echo attr($sel_batch); ?>"
                               placeholder="<?php echo xla('Batch file name...'); ?>" />
                        <?php } ?>
                    </div>

                    <!-- Actions -->
                    <div class="filter-actions">
                  
                        <button type="button" class="btn-print-link" onclick="SubmitTheScreenPrint()">
                            <?php echo xlt('Print'); ?>
                        </button>
                        <?php if ($daysheet) { ?>
                        <button type="button" class="btn-print-link" onclick="SubmitTheEndDayPrint()">
                            <?php echo xlt('End of Day'); ?>
                        </button>
                        <?php } ?>
                    </div>

                </div><!-- .filter-row -->
            </div><!-- #bm-filter-bar -->

            <!-- Hidden inputs that criteria.tab.php would have generated, mapped from our new filter controls -->
            <input type="hidden" name="final_this_page_criteria" id="final_this_page_criteria_hidden" value="" />
        </form>

        <!-- ══════════ HIDDEN CRITERIA FORM (still used by SubmitTheScreen) ══════════ -->
        <!-- criteria.tab.php output is suppressed above; the_form is now the filter bar form -->
    <div class="bm-full-width">
        <form class="form-inline" name='update_form' method='post' action='billing_process.php'>
            <input type="hidden" id="bm_csrf_hidden" name="bm_csrf_hidden"
                   value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input type="hidden" id="bm_csrf_token_form" name="csrf_token_form"
                   value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

            <!-- ══════════ ACTION BAR ══════════ -->
            <div id="bm-action-bar">
                <div class="filter-actions">
            <button type="button" id="update-tooltip" class="btn-update" onclick="SubmitTheScreen()">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15A9 9 0 1 1 5.64 5.64L23 10"/></svg>
                <?php echo xlt('Update List'); ?>
            </button>
                        </div>
                <!-- SECTION: Validation & Claims Actions -->
                <div class="bm-section">
                    <span class="bm-section-label"><?php echo xlt('Validation & Claims Actions'); ?></span>
                    <div class="bm-section-btns">
                        <button type="button" class="btn-action btn-validate-act" id="btn-run-validate"
                                onclick="bmRunValidation();"
                                <?php echo (!$bm_run_query ? 'disabled' : ''); ?>
                                title="<?php echo $bm_run_query ? xla('Run comprehensive claim validation') : xla('Load claims first using Update List'); ?>">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?php echo xlt('Validate Claims'); ?>
                        </button>
                        <div style="font-size:10.5px;color:#9ca3af;margin-top:2px;">
                            <?php echo $bm_run_query ? xlt('Validate claims before submission') : xlt('Load claims first'); ?>
                        </div>
                    </div>
                </div>

                <div class="action-sep"></div>

                <!-- SECTION: X12 Options -->
                <div class="bm-section">
                    <span class="bm-section-label"><?php echo xlt('X12 Options'); ?></span>
                    <div class="bm-section-btns">
                        <?php if (file_exists($EXPORT_INC)) { ?>
                            <button type="submit" data-open-popup="true" class="btn-action" name="bn_external"
                                    title="<?php echo xla('Export to external billing system'); ?>">
                                <?php echo xlt('Export Billing'); ?>
                            </button>
                        <?php } else { ?>
                            <button type="button" class="btn-action btn-primary-act" name="bn_x12"
                                    onclick="bmGenerateX12Direct(event);"
                                    <?php echo !$bm_run_query ? 'disabled' : ''; ?>
                                    title="<?php echo xla('Generate and download X12 batch file immediately'); ?>">
                                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 3v9m0 0l-3-3m3 3l3-3M3 17v2a2 2 0 002 2h14a2 2 0 002-2v-2"/></svg>
                                <?php echo xlt('Generate X12'); ?>
                            </button>
                            <?php if ($GLOBALS['ub04_support']) { ?>
                            <button type="submit" class="btn-action" name="bn_ub04_x12"
                                    onclick="confirmAndSave(event, '1');">
                                <?php echo xlt('X12 837I'); ?>
                            </button>
                            <?php } ?>
                            <?php if ($GLOBALS['support_encounter_claims']) { ?>
                            <button type="submit" class="btn-action" name="bn_x12_encounter"
                                    onclick="confirmAndSave(event, '1');">
                                <?php echo xlt('X12 Encounter'); ?>
                            </button>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </div>

                <div class="action-sep"></div>

                <!-- SECTION: HCFA Form -->
                <div class="bm-section">
                    <span class="bm-section-label"><?php echo xlt('HCFA Form'); ?></span>
                    <div class="bm-section-btns">
                        <button type="button" class="bm-mdi-btn"
                                onclick="mdiGenerateHCFA();"
                                <?php echo !$bm_run_query ? 'disabled' : ''; ?>
                                title="<?php echo xla('Generate HCFA 1500 PDF — plain paper'); ?>">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            <?php echo xlt('HCFA 1500'); ?>
                        </button>
                        <button type="button" class="bm-mdi-btn bm-mdi-btn-outline"
                                onclick="mdiGenerateHCFAImage();"
                                <?php echo !$bm_run_query ? 'disabled' : ''; ?>
                                title="<?php echo xla('Generate HCFA 1500 on preprinted CMS 1500 form image'); ?>">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="9" y1="9" x2="9" y2="21"/></svg>
                            <?php echo xlt('HCFA Preprinted'); ?>
                        </button>
                    </div>
                </div>

                <div class="action-sep"></div>

                <!-- SECTION: Status -->
                <div class="bm-section">
                    <span class="bm-section-label"><?php echo xlt('Status'); ?></span>
                    <div class="bm-section-btns">
                        <button class="btn-action btn-primary-act" data-open-popup="true" name="bn_mark"
                                title="<?php echo xla('Post to accounting and mark as billed'); ?>"
                                <?php echo !$bm_run_query ? 'disabled' : ''; ?> type="submit">
                            <?php echo xlt('Mark as Cleared'); ?>
                        </button>
                        <button class="btn-action btn-danger-act" data-open-popup="true" name="bn_reopen"
                                title="<?php echo xla('Mark as not billed'); ?>"
                                <?php echo !$bm_run_query ? 'disabled' : ''; ?> type="submit">
                            <?php echo xlt('Re-Open'); ?>
                        </button>
                        <button type="button" class="btn-action btn-danger-act"
                                onclick="bmReopenReactivateSelected(event)"
                                <?php echo !$bm_run_query ? 'disabled' : ''; ?>
                                title="<?php echo xla('Reset selected claims to unbilled and reactivate in the billing queue'); ?>">
                            <?php echo xlt('Reopen + Reactivate'); ?>
                        </button>
                    </div>
                </div>

                <div class="action-sep"></div>

                <!-- Margin inputs -->
                <div class="bm-section">
                    <span class="bm-section-label"><?php echo xlt('CMS Margins'); ?></span>
                    <div class="margin-inputs">
                        <span><?php echo xlt('L'); ?>:</span>
                        <input type='text' class='form-control' id='left_margin' name='left_margin'
                               value='<?php echo attr($left_margin); ?>'
                               title='<?php echo xla('HCFA left margin in points'); ?>' />
                        <span><?php echo xlt('T'); ?>:</span>
                        <input type='text' class='form-control' id='top_margin' name='top_margin'
                               value='<?php echo attr($top_margin); ?>'
                               title='<?php echo xla('HCFA top margin in points'); ?>' />
                        <?php if ($ub04_support) { ?>
                        &nbsp;<span><?php echo xlt('UB L'); ?>:</span>
                        <input type='text' class='form-control' id='left_ubmargin' name='left_ubmargin'
                               value='<?php echo attr($left_ubmargin); ?>'
                               title='<?php echo xla('UB04 left margin in points'); ?>' />
                        <span><?php echo xlt('T'); ?>:</span>
                        <input type='text' class='form-control' id='top_ubmargin' name='top_ubmargin'
                               value='<?php echo attr($top_ubmargin); ?>'
                               title='<?php echo xla('UB04 top margin in points'); ?>' />
                        <?php } ?>
                    </div>
                </div>

            </div><!-- #bm-action-bar -->

            <!-- ══════════ VALIDATION RESULTS PANEL ══════════ -->
            <div id="bm-val-panel">
                <div class="bm-val-header">
                    <div class="bm-val-header-left">
                        <div class="bm-val-status-icon" id="bm-val-icon">&#9679;</div>
                        <div>
                            <div class="bm-val-title" id="bm-val-title"><?php echo xlt('Validation Results'); ?></div>
                            <div class="bm-val-subtitle" id="bm-val-subtitle"></div>
                        </div>
                    </div>
                    <div class="bm-val-meta">
                        <span id="bm-val-timestamp"></span>
                        <button type="button" id="bm-preview-x12-btn" onclick="bmPreviewX12();"
                                style="display:none;background:none;border:1px solid #c4b5fd;border-radius:6px;cursor:pointer;color:#5b21b6;font-size:11.5px;font-weight:700;padding:4px 10px;display:none;align-items:center;gap:5px;white-space:nowrap;"
                                title="<?php echo xla('Generate and preview X12 file'); ?>">
                            <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <?php echo xlt('Preview X12'); ?>
                        </button>
                        <button type="button" onclick="bmRunValidation();" style="background:none;border:none;cursor:pointer;color:#9ca3af;" title="<?php echo xla('Re-run validation'); ?>">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.5 15a9 9 0 11-2.2-9.6L23 10"/></svg>
                        </button>
                        <button type="button" class="bm-val-expand-btn" onclick="bmToggleDetails()" id="bm-val-expand-btn">
                            <?php echo xlt('View Details'); ?>
                            <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" id="bm-val-chevron"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                    </div>
                </div>
                <!-- Stat cards + score ring -->
                <div class="bm-val-score-bar" id="bm-val-score-bar">
                    <div class="bm-val-stat-card">
                        <div class="bm-val-stat-icon v">&#10003;</div>
                        <div>
                            <div class="bm-val-stat-num v" id="bm-stat-valid">-</div>
                            <div class="bm-val-stat-lbl"><?php echo xlt('Valid Claims'); ?></div>
                        </div>
                    </div>
                    <div class="bm-val-stat-card">
                        <div class="bm-val-stat-icon e">&#10007;</div>
                        <div>
                            <div class="bm-val-stat-num e" id="bm-stat-errors">-</div>
                            <div class="bm-val-stat-lbl"><?php echo xlt('Claims with Issues'); ?></div>
                        </div>
                    </div>
                    <div class="bm-val-stat-card">
                        <div class="bm-val-stat-icon w">!</div>
                        <div>
                            <div class="bm-val-stat-num w" id="bm-stat-warnings">-</div>
                            <div class="bm-val-stat-lbl"><?php echo xlt('Warnings'); ?></div>
                        </div>
                    </div>
                    <div class="bm-val-score-wrap">
                        <div>
                            <div class="bm-val-score-lbl"><?php echo xlt('Confidence Score'); ?></div>
                            <div class="bm-val-score-lbl" id="bm-score-sublbl" style="font-weight:600;color:#374151;"></div>
                        </div>
                        <div class="bm-val-score-ring">
                            <svg width="54" height="54" viewBox="0 0 54 54">
                                <circle cx="27" cy="27" r="22" fill="none" stroke="#f0edf8" stroke-width="5"/>
                                <circle id="bm-score-arc" cx="27" cy="27" r="22" fill="none"
                                        stroke="#7c3aed" stroke-width="5"
                                        stroke-dasharray="138.2 138.2" stroke-dashoffset="138.2"
                                        stroke-linecap="round" style="transition:stroke-dashoffset .6s ease;"/>
                            </svg>
                            <div class="bm-val-score-text" id="bm-score-text">-</div>
                        </div>
                    </div>
                    <button type="button" id="bm-view-all-btn" onclick="bmViewAllIssues()"
                            style="margin-left:auto;font-size:12px;font-weight:600;padding:7px 16px;
                                   border-radius:7px;border:1.5px solid #7c3aed;background:#fff;
                                   color:#7c3aed;cursor:pointer;white-space:nowrap;">
                        <?php echo xlt('View All Issues'); ?>
                    </button>
                </div>
                <!-- Expandable details -->
                <div class="bm-val-details" id="bm-val-details" style="display:none;">
                    <div id="bm-val-issues-list"></div>
                </div>
                <!-- Footer -->
                <div class="bm-val-footer" id="bm-val-footer" style="display:none;">
                    <div class="bm-val-footer-note">
                        <svg width="14" height="14" fill="none" stroke="#6b7280" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <?php echo xlt('Fix the issues above before generating your batch file to avoid claim rejections.'); ?>
                    </div>
                    <button type="button" class="bm-val-proceed" onclick="bmProceedAnyway();"><?php echo xlt('Proceed Anyway'); ?></button>
                </div>
            </div><!-- #bm-val-panel -->
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />
            <input name='mode' type='hidden' value="bill" />
            <input name='authorized' type='hidden' value="<?php echo attr($my_authorized); ?>" />
            <input name='unbilled' type='hidden' value="<?php echo attr($unbilled); ?>" />
            <input name='code_type' type='hidden' value="%" />
            <input name='to_date' type='hidden' value="<?php echo attr($to_date); ?>" />
            <input name='from_date' type='hidden' value="<?php echo attr($from_date); ?>" />
            <?php
            if ($my_authorized == "on") {
                $my_authorized = "1";
            } else {
                $my_authorized = "%";
            }
            if ($unbilled == "on") {
                $unbilled = "0";
            } else {
                $unbilled = "%";
            }
            $list = BillingReport::getBillsListBetween("%");
            // don't query the whole encounter table if no criteria selected

            if (!isset($_POST["mode"])) {
                if (!isset($_POST["from_date"])) {
                    $from_date = date("Y-m-d");
                } else {
                    $from_date = $_POST["from_date"];
                }
                if (empty($_POST["to_date"])) {
                    $to_date = '';
                } else {
                    $to_date = $_POST["to_date"];
                }
                if (!isset($_POST["code_type"])) {
                    $code_type = "all";
                } else {
                    $code_type = $_POST["code_type"];
                }
                if (!isset($_POST["unbilled"])) {
                    $unbilled = "on";
                } else {
                    $unbilled = $_POST["unbilled"];
                }
                if (!isset($_POST["authorized"])) {
                    $my_authorized = "on";
                } else {
                    $my_authorized = $_POST["authorized"];
                }
            } else {
                $from_date = $_POST["from_date"] ?? null;
                $to_date = $_POST["to_date"] ?? null;
                $code_type = $_POST["code_type"] ?? null;
                $unbilled = $_POST["unbilled"] ?? null;
                $my_authorized = $_POST["authorized"] ?? null;
            }

            if ($my_authorized == "on") {
                $my_authorized = "1";
            } else {
                $my_authorized = "%";
            }

            if ($unbilled == "on") {
                $unbilled = "0";
            } else {
                $unbilled = "%";
            }

            // When our filter bar controls billing status, override $unbilled to "%"
            // so getBillsBetween doesn't pre-filter to unbilled-only in SQL.
            // The PHP post-filters below handle billed/unbilled/denied exclusively.
            if (isset($_POST['mode']) && $_POST['mode'] === 'change' && isset($_POST['bm_billed'])) {
                $unbilled = "%";
            }

            //if (isset($_POST["mode"]) && $_POST["mode"] == "bill") {
              //  billCodesList($list);
           // }


           if (isset($_POST["mode"]) && $_POST["mode"] == "bill") {

            if (isset($_POST['bn_process_hcfa'])) {
                echo "<h1>MDITEK HCFA OVERRIDE HIT</h1>";
                echo "<pre>";
                print_r($_POST);
                echo "</pre>";
                exit;
            }

            // MDiTek HCFA PDF
            if (isset($_POST['bn_process_hcfa'])) {
                require_once __DIR__ . '/mdi_hcfa_pdf.php';
                exit;
            }
        
            // MDiTek HCFA Form
            if (isset($_POST['bn_process_hcfa_form'])) {
                require_once __DIR__ . '/mdi_hcfa_form.php';
                exit;
            }
        
            // Everything else stays stock
            billCodesList($list);
        }

            // ── Translate filter bar bm_* values into final_this_page_criteria ──
            //
            // RULES:
            // - Never call add_escape_custom() on criteria values - the parser escapes internally.
            // - billing.billed: conflicts with the $unbilled param inside getBillsBetween.
            //   Handled as PHP post-filter ($bm_billed_filter) below.
            // - billing.x12_partner_id: uses "query_drop_down" type - |=| syntax not valid.
            //   Handled as PHP post-filter ($bm_submitter_filter) below.
            // - Patient name: PHP post-filter only (never safe to pass to SQL here).
            if (isset($_POST['mode']) && $_POST['mode'] === 'change') {
                $criteria = [];

                // Insurance company
                if (!empty($_POST['bm_insurance']) && is_numeric($_POST['bm_insurance'])) {
                    $criteria[] = "claims.payer_id|=|" . (int)$_POST['bm_insurance'];
                }

                // Service date (form_encounter.date)
                if (!empty($_POST['bm_svc_from']) || !empty($_POST['bm_svc_to'])) {
                    $normSvcDate = function($raw, $default) {
                        if (empty($raw)) { return $default; }
                        $raw = trim($raw);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) { return $raw; }
                        if (preg_match('/^\d{8}$/', $raw)) { return substr($raw,0,4).'-'.substr($raw,4,2).'-'.substr($raw,6,2); }
                        $c = function_exists('DateToYYYYMMDD') ? DateToYYYYMMDD($raw) : '';
                        if ($c && strlen($c) === 8) { return substr($c,0,4).'-'.substr($c,4,2).'-'.substr($c,6,2); }
                        return $default;
                    };
                    $df = $normSvcDate($_POST['bm_svc_from'] ?? '', '0001-01-01');
                    $dt = $normSvcDate($_POST['bm_svc_to']   ?? '', date('Y-m-d'));
                    $criteria[] = "form_encounter.date|between|{$df} 00:00:00|{$dt} 23:59:59";
                }

                // Note: billing date (claims.bill_time) is handled as a PHP post-filter below
                // because claims.bill_time is not in the criteria parser's recognised column list.

                if (!empty($criteria)) {
                    $_REQUEST['final_this_page_criteria'] = $criteria;
                } else {
                    unset($_REQUEST['final_this_page_criteria']);
                }
            }

            // Normalize patient filter: remove commas so "Smith, John" matches "smith john"
            $bm_patient_filter   = strtolower(trim(str_replace(',', ' ', $_POST['bm_patient'] ?? '')));
            $bm_patient_filter   = preg_replace('/\s+/', ' ', $bm_patient_filter);
            // Billing status: '' = all, '0' = unbilled, '1' = billed, '7' = denied
            $bm_billed_filter    = (isset($_POST['bm_billed']) && $_POST['bm_billed'] !== '')
                                   ? (int)$_POST['bm_billed'] : null;
            // X12 submitter: null = all, otherwise integer partner ID
            $bm_submitter_filter = (isset($_POST['bm_submitter']) && is_numeric($_POST['bm_submitter']))
                                   ? (int)$_POST['bm_submitter'] : null;
            // Batch number: null = all, otherwise partial match against claims.process_file
            $bm_batch_filter     = (isset($_POST['bm_batch']) && trim($_POST['bm_batch']) !== '')
                                   ? trim($_POST['bm_batch']) : null;
            // Billing date: filter against claims.bill_time as a PHP post-filter
            // (claims.bill_time is not in the criteria parser's column whitelist)
            $bm_bill_from_filter = (isset($_POST['bm_bill_from']) && trim($_POST['bm_bill_from']) !== '')
                                   ? trim($_POST['bm_bill_from']) : null;
            $bm_bill_to_filter   = (isset($_POST['bm_bill_to']) && trim($_POST['bm_bill_to']) !== '')
                                   ? trim($_POST['bm_bill_to']) : null;
            // Only run query when user has submitted the filter form
            $bm_run_query = (isset($_POST['mode']) && $_POST['mode'] === 'change');
            ?>
            <div class="table-responsive" style="min-height:0;">
                <table class="table table-sm" style="margin-bottom:0;">
                    <?php
                    $divnos = 0;
                    $rendered = 0;
                    if ($bm_run_query && ($ret = BillingReport::getBillsBetween("%"))) {
                        if (is_array($ret)) { ?>
                    <tr>
                        <td class="text-right" colspan='9'>
                            <table>
                                <tr>
                                    <td id='expandAllCollapseAll'>
                                        <div class="btn-group">
                                            <button type="button" class="btn btn-primary btn-sm" onclick="expandcollapse('expand');">
                                                <?php echo '(' . xlt('Expand All') . ')' ?>
                                            </button>
                                            <button type="button" class="btn btn-primary btn-sm" onclick="expandcollapse('collapse');">
                                                <?php echo '(' . xlt('Collapse All') . ')' ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <?php } ?>

                        <?php
                        $loop = 0;
                        $oldcode = "";
                        $last_encounter_id = "";
                        $lhtml = "";
                        $rhtml = "";
                        $lcount = 0;
                        $rcount = 0;
                        $bgcolor = "var(--light)";
                        $skipping = false;
                        $enc_scripts = "";

                        $mmo_empty_mod = false;
                        $mmo_num_charges = 0;

                        foreach ($ret as $iter) {
                            // Only show encounters that have at least one active billing charge.
                            if (!$iter['id']) {
                                continue;
                            }

                            // Exclude legacy balance transfer entries (cleared/zeroed charges)
                            if (stripos($iter['code_text'] ?? '', 'LEGACY BALANCE') !== false) {
                                continue;
                            }

                            // Patient name filter is applied below, after getPatientData() populates $name.

                            // Billing status post-filter
                            if ($bm_billed_filter !== null && (int)($iter['billed'] ?? 0) !== $bm_billed_filter) {
                                continue;
                            }

                            // X12 submitter post-filter.
                            // For billed records, billing.x12_partner_id is set directly.
                            // For unbilled records it is 0/NULL (claim never submitted), so we
                            // look up the patient's primary insurance default X12 partner instead.
                            if ($bm_submitter_filter !== null) {
                                $iter_partner = (int)($iter['x12_partner_id'] ?? 0);
                                if ($iter_partner === 0) {
                                    // Unbilled - resolve via primary insurance company
                                    $ins_row = sqlQuery(
                                        "SELECT ic.x12_default_partner_id " .
                                        "FROM insurance_data id " .
                                        "JOIN insurance_companies ic ON ic.id = id.provider " .
                                        "WHERE id.pid = ? AND id.type = 'primary' " .
                                        "AND (id.date <= ? OR id.date IS NULL) " .
                                        "AND (id.date_end >= ? OR id.date_end IS NULL) " .
                                        "ORDER BY id.date DESC LIMIT 1",
                                        [$iter['enc_pid'], $iter['enc_date'], $iter['enc_date']]
                                    );
                                    $iter_partner = (int)($ins_row['x12_default_partner_id'] ?? 0);
                                }
                                if ($iter_partner !== $bm_submitter_filter) {
                                    continue;
                                }
                            }

                            // Batch number post-filter: match against claims.process_file
                            if ($bm_batch_filter !== null) {
                                $batch_match = sqlQuery(
                                    "SELECT 1 FROM claims
                                      WHERE patient_id = ? AND encounter_id = ?
                                        AND process_file LIKE ?
                                      LIMIT 1",
                                    [$iter['enc_pid'], $iter['enc_encounter'], '%' . $bm_batch_filter . '%']
                                );
                                if (!$batch_match) {
                                    continue;
                                }
                            }

                            // Billing date post-filter: match against claims.bill_time
                            if ($bm_bill_from_filter !== null || $bm_bill_to_filter !== null) {
                                $normBillDate = function($raw) {
                                    if (!$raw) { return null; }
                                    $raw = trim($raw);
                                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) { return $raw; }
                                    if (preg_match('/^\d{8}$/', $raw)) { return substr($raw,0,4).'-'.substr($raw,4,2).'-'.substr($raw,6,2); }
                                    $c = function_exists('DateToYYYYMMDD') ? DateToYYYYMMDD($raw) : '';
                                    if ($c && strlen($c) === 8) { return substr($c,0,4).'-'.substr($c,4,2).'-'.substr($c,6,2); }
                                    return $raw;
                                };
                                $bill_df = $normBillDate($bm_bill_from_filter);
                                $bill_dt = $normBillDate($bm_bill_to_filter);
                                $bill_sql  = "SELECT 1 FROM claims WHERE patient_id = ? AND encounter_id = ? AND bill_time IS NOT NULL";
                                $bill_args = [$iter['enc_pid'], $iter['enc_encounter']];
                                if ($bill_df) { $bill_sql .= " AND DATE(bill_time) >= ?"; $bill_args[] = $bill_df; }
                                if ($bill_dt) { $bill_sql .= " AND DATE(bill_time) <= ?"; $bill_args[] = $bill_dt; }
                                $bill_sql .= " LIMIT 1";
                                if (!sqlQuery($bill_sql, $bill_args)) {
                                    continue;
                                }
                            }

                            $rendered++;

                            $this_encounter_id = $iter['enc_pid'] . "-" . $iter['enc_encounter'];

                            if ($last_encounter_id != $this_encounter_id) {
                            // This dumps all HTML for the previous encounter.
                                if ($lhtml) {
                                    while ($rcount < $lcount) {
                                        $rhtml .= "<tr style='background-color:" . attr($bgcolor) . ";line-height:0;font-size:0;'><td colspan='9' style='padding:0;line-height:0;font-size:0;height:0;'></td></tr>";
                                        ++$rcount;
                                    }
                                    // This test handles the case where we are only listing encounters
                                    // that appear to have a missing "25" modifier.
                                    if (!$missing_mods_only || ($mmo_empty_mod && $mmo_num_charges > 1)) {
                                        if ($DivPut == 'yes') {
                                            $lhtml .= '</div>';
                                            $DivPut = 'no';
                                        }
                                        echo "<tr style='background-color: " . attr($bgcolor) . ";'>\n<td class='align-top' rowspan='" . attr($rcount) . "'>\n$lhtml</td>$rhtml\n";
                                        echo "<tr style='background-color: " . attr($bgcolor) . ";'><td colspan='9' height='5'></td></tr>\n\n";
                                        $encount = $encount ?? null;
                                        ++$encount;
                                    }
                                }

                                $lhtml = "";
                                $rhtml = "";
                                $mmo_empty_mod = false;
                                $mmo_num_charges = 0;

                                // If there are ANY unauthorized items in this encounter and this is
                                // the normal case of viewing only authorized billing, then skip the
                                // entire encounter.
                                $skipping = false;
                                if ($my_authorized == '1') {
                                    $res = sqlQuery(
                                        "SELECT count(*) AS count FROM billing WHERE " .
                                        "encounter = ? AND " .
                                        "pid=? AND " .
                                        "activity = 1 AND authorized = 0",
                                        array(
                                        $iter['enc_encounter'],
                                        $iter['enc_pid']
                                        )
                                    );
                                    if ($res['count'] > 0) {
                                        $skipping = true;
                                        $last_encounter_id = $this_encounter_id;
                                        continue;
                                    }
                                }
                                // Is there a MBO with actual data filled in?
                                // Check if the form exists. Then inspect form_misc_billing_options columns
                                // using the actual OpenEMR schema (prefixed field names).
                                $mbo_form = sqlQuery(
                                    "SELECT forms.form_id FROM forms WHERE forms.encounter = ? AND forms.authorized = 1 AND forms.formdir = 'misc_billing_options' AND forms.deleted != 1 LIMIT 1",
                                    array($iter['enc_encounter'])
                                );
                                $iter['mboid'] = 0;
                                if ($mbo_form) {
                                    // Check the actual data table for any filled fields.
                                    // Inspect all columns dynamically to avoid hardcoding field names.
                                    $mbo_data = sqlQuery(
                                        "SELECT * FROM form_misc_billing_options WHERE id = ? LIMIT 1",
                                        array($mbo_form['form_id'])
                                    );
                                    if ($mbo_data) {
                                        // Skip internal/system columns; if any other column has a non-empty value, mark as filled.
                                        $skip_cols = ['id', 'pid', 'user', 'groupname', 'authorized', 'activity', 'date'];
                                        $has_data = false;
                                        foreach ($mbo_data as $col => $val) {
                                            if (in_array($col, $skip_cols)) continue;
                                            if ($val !== null && $val !== '' && $val !== '0' && $val !== 0
                                                && $val !== '0000-00-00' && $val !== '0000-00-00 00:00:00') {
                                                $has_data = true;
                                                break;
                                            }
                                        }
                                        if ($has_data) {
                                            $iter['mboid'] = attr($mbo_form['form_id']);
                                        }
                                    }
                                }

                                // ── Last Updated By ──────────────────────────────────────────
                                // 1. Most recent billing activity (payment/adjustment posting)
                                $last_billing_row = sqlQuery(
                                    "SELECT ara.post_time, u.fname, u.lname, u.username
                                       FROM ar_activity AS ara
                                       LEFT JOIN users AS u ON u.id = ara.post_user
                                      WHERE ara.pid = ? AND ara.encounter = ? AND ara.deleted IS NULL
                                      ORDER BY ara.post_time DESC LIMIT 1",
                                    array($iter['enc_pid'], $iter['enc_encounter'])
                                );
                                // 2. Most recent encounter form save (SOAP, fee sheet, etc.)
                                $last_form_row = sqlQuery(
                                    "SELECT f.date, f.user
                                       FROM forms AS f
                                      WHERE f.pid = ? AND f.encounter = ? AND f.deleted = 0
                                      ORDER BY f.date DESC LIMIT 1",
                                    array($iter['enc_pid'], $iter['enc_encounter'])
                                );
                                // Pick whichever is more recent
                                $last_updated_by   = '';
                                $last_updated_time = '';
                                $billing_ts = $last_billing_row ? strtotime($last_billing_row['post_time']) : 0;
                                $form_ts    = $last_form_row    ? strtotime($last_form_row['date'])         : 0;
                                if ($billing_ts >= $form_ts && $billing_ts > 0) {
                                    $uname = trim(($last_billing_row['fname'] ?? '') . ' ' . ($last_billing_row['lname'] ?? ''));
                                    $last_updated_by   = $uname ?: ($last_billing_row['username'] ?? '');
                                    $last_updated_time = oeFormatDateTime($last_billing_row['post_time']);
                                } elseif ($form_ts > 0) {
                                    $last_updated_by   = $last_form_row['user'] ?? '';
                                    $last_updated_time = oeFormatDateTime($last_form_row['date']);
                                }
                                // ── End Last Updated By ──────────────────────────────────────

                                $name = getPatientData($iter['enc_pid'], "fname, mname, lname, pubpid, billing_note, DATE_FORMAT(DOB,'%Y-%m-%d') as DOB_YMD");

                                // Patient name post-filter - runs here because $name is now populated
                                if ($bm_patient_filter !== '') {
                                    $full = strtolower(($name['lname'] ?? '') . ' ' . ($name['fname'] ?? ''));
                                    $full = preg_replace('/\s+/', ' ', trim($full));
                                    $full_rev = strtolower(($name['fname'] ?? '') . ' ' . ($name['lname'] ?? ''));
                                    if (strpos($full, $bm_patient_filter) === false &&
                                        strpos($full_rev, $bm_patient_filter) === false) {
                                        continue;
                                    }
                                }

                                // Check if patient has primary insurance and a subscriber exists for it.
                                // If not we will highlight their name in red.
                                // TBD: more checking here.
                                $res = sqlQuery(
                                    "SELECT count(*) AS count FROM insurance_data WHERE " .
                                    "pid = ? AND " .
                                    "type='primary' AND " .
                                    "subscriber_lname IS NOT NULL AND " .
                                    "subscriber_lname != '' LIMIT 1",
                                    array(
                                    $iter['enc_pid']
                                    )
                                );
                                $namecolor = ($res['count'] > 0) ? "black" : "#ff7777";

                                $bgcolor = ((($encount ?? null) & 1) ? "var(--light)" : "var(--gray300)");
                                echo "<tr style='background-color: " . attr($bgcolor) . ";'><td colspan='9' height='5'></td></tr>\n";
                                $lcount = 1;
                                $rcount = 0;
                                $oldcode = "";

                                $ptname = $name['fname'] . " " . $name['lname'];
                                $raw_encounter_date = date("Y-m-d", strtotime($iter['enc_date']));
                                $billing_note = $name['billing_note'];
                                // Add Encounter Date to display with "To Encounter" button 2/17/09 JCH
                                $lhtml .= "<span class='font-weight-bold' style='color: " . attr($namecolor) . "'>" . text($ptname) . "</span><span class=small>&nbsp;(" . text($iter['enc_pid']) . "-" . text($iter['enc_encounter']) . ")</span>";

                                // Encounter details are stored to javacript as array.
                                $result4 = sqlStatement(
                                    "SELECT fe.encounter,fe.date,fe.billing_note,openemr_postcalendar_categories.pc_catname FROM form_encounter AS fe " .
                                    " LEFT JOIN openemr_postcalendar_categories ON fe.pc_catid=openemr_postcalendar_categories.pc_catid  WHERE fe.pid = ? ORDER BY fe.date DESC",
                                    array(
                                    $iter['enc_pid']
                                    )
                                );
                                if (sqlNumRows($result4) > 0) {
                                    ;
                                }
                                $enc_scripts .= "Count=0;";
                                $enc_scripts .= "EncounterDateArray[" . (int)$iter['enc_pid'] . "]=[];";
                                $enc_scripts .= "CalendarCategoryArray[" . (int)$iter['enc_pid'] . "]=[];";
                                $enc_scripts .= "EncounterIdArray[" . (int)$iter['enc_pid'] . "]=[];";
                                $enc_scripts .= "EncounterNoteArray[" . (int)$iter['enc_pid'] . "]=[];";
                                while ($rowresult4 = sqlFetchArray($result4)) {
                                    $enc_scripts .= "EncounterIdArray[" . (int)$iter['enc_pid'] . "][Count]=" . js_escape($rowresult4['encounter']) . ";";
                                    $enc_scripts .= "EncounterDateArray[" . (int)$iter['enc_pid'] . "][Count]=" . js_escape(oeFormatShortDate(date("Y-m-d", strtotime($rowresult4['date'])))) . ";";
                                    $enc_scripts .= "CalendarCategoryArray[" . (int)$iter['enc_pid'] . "][Count]=" . js_escape(xl_appt_category($rowresult4['pc_catname'])) . ";";
                                    $enc_scripts .= "EncounterNoteArray[" . (int)$iter['enc_pid'] . "][Count]=" . js_escape($rowresult4['billing_note']) . ";";
                                    $enc_scripts .= "Count++;";
                                    $enc_billing_note[$rowresult4['encounter']] = $rowresult4['billing_note'];
                                }
                                $lhtml .= "<div class='button-group'>";
                                // Not sure why the next section seems to do nothing except post "To Encounter" button 2/17/09 JCH
                                $lhtml .= "<a class='btn btn-sm btn-primary' role='button'" . "href='javascript:
                                    window.toencounter(" . attr_js($iter['enc_pid']) . "," . attr_js($name['pubpid']) . "," . attr_js($ptname) . "," . attr_js($iter['enc_encounter']) . "," . attr_js(oeFormatShortDate($raw_encounter_date)) . "," . attr_js(" " . xl('DOB') . ": " . oeFormatShortDate($name['DOB_YMD']) . " " . xl('Age') . ": " . getPatientAge($name['DOB_YMD'])) . ");
                                    top.window.parent.left_nav.setPatientEncounter(EncounterIdArray[" . attr($iter['enc_pid']) . "],EncounterDateArray[" . attr($iter['enc_pid']) . "], CalendarCategoryArray[" . attr($iter['enc_pid']) . "]);
                                    top.setEncounter(" . attr_js($iter['enc_encounter']) . ");
                                    '>" . xlt('Encounter') . " " . text(oeFormatShortDate($raw_encounter_date)) . "</a>";

                                // Changed "To xxx" buttons to allow room for encounter date display 2/17/09 JCH
                                $lhtml .= "<a class='btn btn-sm btn-primary' role='button' " . "href=\"javascript:window.topatient(" . attr_js($iter['enc_pid']) . "," . attr_js($name['pubpid']) . "," . attr_js($ptname) . "," . attr_js($iter['enc_encounter']) . "," . attr_js(oeFormatShortDate($raw_encounter_date)) . "," . attr_js(" " . xl('DOB') . ": " . oeFormatShortDate($name['DOB_YMD']) . " " . xl('Age') . ": " . getPatientAge($name['DOB_YMD'])) . ");
                                    top.window.parent.left_nav.setPatientEncounter(EncounterIdArray[" . attr($iter['enc_pid']) . "],EncounterDateArray[" . attr($iter['enc_pid']) . "], CalendarCategoryArray[" . attr($iter['enc_pid']) . "])\">" . xlt('Patient') . "</a>";
                                $is_edited = $iter['mboid'] ? 'btn-success' : 'btn-secondary';
                                $title = $iter['mboid'] ? xlt("This claim has HCFA 1500 miscellaneous billing options") : xlt("Click to add HCFA 1500 miscellaneous billing options");
                                $lhtml .= "<a class='btn btn-sm $is_edited' role='button' title='" . attr($title) . "' onclick='popMBO(" . attr_js($iter['enc_pid']) . "," . attr_js($iter['enc_encounter']) . "," . attr_js($iter['mboid']) . "); return false;'>" . xlt('MBO ') . "</a>";
                                if ($ub04_support && isset($iter['billed'])) {
                                    $c = sqlQuery(
                                        "SELECT submitted_claim AS status FROM claims WHERE " .
                                        "encounter_id = ? AND " .
                                        "patient_id=? " .
                                        "ORDER BY version DESC LIMIT 1",
                                        array(
                                        $iter['enc_encounter'],
                                        $iter['enc_pid']
                                        )
                                    );
                                    $is_edited = ($c['status'] ?? null) ? 'btn-success' : 'btn-warning';
                                    $bname = ($c['status'] ?? null) ? xl('Reviewed') : xl('Review UB04');
                                    $lhtml .= "<a class='btn btn-sm $is_edited' role='button' onclick='popUB04(" . attr_js($iter['enc_pid']) . "," . attr_js($iter['enc_encounter']) . "); return false;'>" . text($bname) . "</a>";
                                }
                                $lhtml .= "</div>";
                                $divnos = $divnos + 1;
                                $lhtml .= "&nbsp;&nbsp;&nbsp;<a onclick='divtoggle(" . attr_js("spanid_" . $divnos) . "," . attr_js("divid_" . $divnos) . ");' class='small' id='aid_" . attr($divnos) . "' href=\"JavaScript:void(0);" . "\">(<span id=spanid_" . attr($divnos) . " class=\"indicator\">" . xlt('Expand') . '</span>)<br /></a>';
                                if ($GLOBALS['notes_to_display_in_Billing'] == 2 || $GLOBALS['notes_to_display_in_Billing'] == 3) {
                                    $lhtml .= '<span class="font-weight-bold text-danger" style="margin-left: 20px;">' . text($billing_note) . '</span>';
                                }

                                // ── Last Updated By display ──────────────────────────────────
                                if ($last_updated_by !== '') {
                                    $lhtml .= '<br /><span style="font-size:11px; color:#6c757d; margin-left:4px;">'
                                            . '<i class="fa fa-user-edit" style="margin-right:3px; opacity:.65;"></i>'
                                            . xlt('Last updated by') . ': '
                                            . '<strong>' . text($last_updated_by) . '</strong>'
                                            . ' &mdash; ' . text($last_updated_time)
                                            . '</span>';
                                }
                                // ── End Last Updated By display ──────────────────────────────

                                if ($iter['id']) {
                                    $lcount += 2;
                                    $lhtml .= "<br />\n";
                                    $lhtml .= "&nbsp;<span class='form-group'>" . xlt('Bill') . ": ";
                                    $lhtml .= "<select name='claims[" . attr($this_encounter_id) . "][payer]' onchange='onNewPayer(event)' class='form-control'>";

                                    $query = "SELECT id.provider AS id, id.type, id.date, " .
                                    "ic.x12_default_partner_id AS ic_x12id, ic.name AS provider " .
                                    "FROM insurance_data AS id, insurance_companies AS ic WHERE " .
                                    "ic.id = id.provider AND " .
                                    "id.pid = ? AND " .
                                    "(id.date <= ? OR id.date IS NULL) AND " .
                                    "(id.date_end >= ? OR id.date_end IS NULL) " .
                                    "ORDER BY id.type ASC, id.date DESC";

                                    $result = sqlStatement(
                                        $query,
                                        array(
                                        $iter['enc_pid'],
                                        $raw_encounter_date,
                                        $raw_encounter_date
                                        )
                                    );
                                    $count = 0;
                                    $default_x12_partner = $iter['ic_x12id'] ?? null;
                                    $prevtype = '';

                                    while ($row = sqlFetchArray($result)) {
                                        if (strcmp($row['type'], $prevtype) == 0) {
                                            continue;
                                        }
                                        $prevtype = $row['type'];
                                        if (strlen($row['provider']) > 0) {
                                            // This preserves any existing insurance company selection, which is
                                            // important when EOB posting has re-queued for secondary billing.
                                            $lhtml .= "<option value=\"" . attr(substr($row['type'], 0, 1) . $row['id']) . "\"";
                                            if (($count == 0 && !$iter['payer_id']) || $row['id'] == $iter['payer_id']) {
                                                $lhtml .= " selected";
                                                if (!is_numeric($default_x12_partner)) {
                                                    $default_x12_partner = $row['ic_x12id'];
                                                }
                                            }
                                            $lhtml .= " data-partner='" . attr($row['ic_x12id']) . "'>" . text($row['type']) . ": " . text($row['provider']) . "</option>";
                                        }
                                        $count++;
                                    }

                                    $lhtml .= "<option value='-1'>" . xlt("Unassigned") . "</option>\n";
                                    $lhtml .= "</select>&nbsp;&nbsp;\n";
                                    $lhtml .= "&nbsp;<span class='form-group'>X12: ";
                                    $lhtml .= "<select class='form-control' id='partners' name='claims[" . attr($this_encounter_id) . "][partner]'>";
                                    $lhtml .= "<option value='-1' label='Unassigned'>" . xlt("Partner not configured") . "</option>\n";
                                    foreach ($partners as $xid => $xname) {
                                        if (empty(trim($xname))) {
                                            continue;
                                        }
                                        $lhtml .= '<option label="' . attr($xname) . '" value="' . attr($xid) . '"';
                                        if ($xid == $default_x12_partner) {
                                            $lhtml .= "selected";
                                        }
                                        $lhtml .= '>' . text($xname) . '</option>';
                                    }
                                    $lhtml .= "</select></span>";
                                    $DivPut = 'yes';

                                    if ($GLOBALS['notes_to_display_in_Billing'] == 1 || $GLOBALS['notes_to_display_in_Billing'] == 3) {
                                        $lhtml .= "<br /><span class='font-weight-bold text-success ml-3'>" . text($enc_billing_note[$iter['enc_encounter']]) . "</span>";
                                    }
                                    $lhtml .= "<br />\n&nbsp;<div id='divid_" . attr($divnos) . "' style='display:none'>" . text(oeFormatShortDate(substr($iter['date'], 0, 10))) . text(substr($iter['date'], 10, 6)) . " " . xlt("Encounter was coded");

                                    $query = "SELECT * FROM claims WHERE patient_id = ? AND encounter_id = ? ORDER BY version";
                                    $cres = sqlStatement(
                                        $query,
                                        array(
                                        $iter['enc_pid'],
                                        $iter['enc_encounter']
                                        )
                                    );

                                    $lastcrow = false;

                                    while ($crow = sqlFetchArray($cres)) {
                                        $query = "SELECT id.type, ic.name " .
                                            "FROM insurance_data AS id, insurance_companies AS ic WHERE " .
                                            "id.pid = ? AND " .
                                            "id.provider = ? AND " .
                                            "(id.date <= ? OR id.date IS NULL) AND " .
                                            "(id.date_end >= ? OR id.date_end IS NULL) AND " .
                                            "ic.id = id.provider " .
                                            "ORDER BY id.type ASC, id.date DESC";

                                        $irow = sqlQuery(
                                            $query,
                                            array(
                                            $iter['enc_pid'],
                                            $crow['payer_id'],
                                            $raw_encounter_date,
                                            $raw_encounter_date
                                            )
                                        );

                                        if ($crow['bill_process']) {
                                                $lhtml .= "<br />\n&nbsp;" . text(oeFormatShortDate(substr($crow['bill_time'], 0, 10))) . text(substr($crow['bill_time'], 10, 6)) . " " . xlt("Queued for") . " " . text($irow['type'] ?? '') . " " . text($crow['target'] ?? '') . " " . xlt("billing to ") . text($irow['name'] ?? '');
                                                ++$lcount;
                                        } elseif ($crow['status'] < 6) {
                                            if ($crow['status'] > 1) {
                                                $lhtml .= "<br />\n&nbsp;" . text(oeFormatShortDate(substr($crow['bill_time'], 0, 10))) . text(substr($crow['bill_time'], 10, 6)) . " " . xlt("Marked as cleared");
                                                ++$lcount;
                                            } else {
                                                $lhtml .= "<br />\n&nbsp;" . text(oeFormatShortDate(substr($crow['bill_time'], 0, 10))) . text(substr($crow['bill_time'], 10, 6)) . " " . xlt("Re-opened");
                                                ++$lcount;
                                            }
                                        } elseif ($crow['status'] == 6) {
                                            $lhtml .= "<br />\n&nbsp;" . text(oeFormatShortDate(substr($crow['bill_time'], 0, 10))) . text(substr($crow['bill_time'], 10, 6)) . " " . xlt("This claim has been forwarded to next level.");
                                            ++$lcount;
                                        } elseif ($crow['status'] == 7) {
                                            $lhtml .= "<br />\n&nbsp;" . text(oeFormatShortDate(substr($crow['bill_time'], 0, 10))) . text(substr($crow['bill_time'], 10, 6)) . " " . xlt("This claim has been denied.Reason:-");
                                            if ($crow['process_file']) {
                                                $code_array = explode(',', $crow['process_file']);
                                                foreach ($code_array as $code_key => $code_value) {
                                                    $lhtml .= "<br />\n&nbsp;&nbsp;&nbsp;";
                                                    $reason_array = explode('_', $code_value);
                                                    if (!isset($adjustment_reasons[$reason_array[3]])) {
                                                        $lhtml .= xlt("For code") . ' [' . text($reason_array[0]) . '] ' . xlt("and modifier") . ' [' . text($reason_array[1]) . '] ' . xlt("the Denial code is") . ' [' . text($reason_array[2]) . ' ' . text($reason_array[3]) . ']';
                                                    } else {
                                                        $lhtml .= xlt("For code") . ' [' . text($reason_array[0]) . '] ' . xlt("and modifier") . ' [' . text($reason_array[1]) . '] ' . xlt("the Denial Group code is") . ' [' . text($reason_array[2]) . '] ' . xlt("and the Reason is") . ':- ' . text($adjustment_reasons[$reason_array[3]]);
                                                    }
                                                }
                                            } else {
                                                $lhtml .= xlt("Not Specified.");
                                            }
                                            ++$lcount;
                                        }

                                        if ($crow['process_time']) {
                                            $lhtml .= "<br />\n&nbsp;" . text(oeFormatShortDate(substr($crow['process_time'], 0, 10))) . text(substr($crow['process_time'], 10, 6)) . " " . xlt("Claim was generated to file") . " " . "<a href='get_claim_file.php?key=" . attr_url($crow['process_file']) . "&csrf_token_form=" . attr_url(CsrfUtils::collectCsrfToken()) . "' onclick='top.restoreSession()'>" . text($crow['process_file']) . "</a>";
                                            ++$lcount;
                                        }

                                        $lastcrow = $crow;
                                    } // end while ($crow = sqlFetchArray($cres))

                                    if ($lastcrow && $lastcrow['status'] == 4) {
                                        $lhtml .= "<br />\n&nbsp;" . xlt("This claim has been closed.");
                                        ++$lcount;
                                    }

                                    if ($lastcrow && $lastcrow['status'] == 5) {
                                        $lhtml .= "<br />\n&nbsp;" . xlt("This claim has been canceled.");
                                        ++$lcount;
                                    }
                                } // end if ($iter['id'])
                            } // end if ($last_encounter_id != $this_encounter_id)

                            if ($skipping) {
                                continue;
                            }

                            // Collect info related to the missing modifiers test.
                            if ($iter['fee'] > 0) {
                                ++$mmo_num_charges;
                                $tmp = substr($iter['code'], 0, 3);
                                if (($tmp == '992' || $tmp == '993') && empty($iter['modifier'])) {
                                    $mmo_empty_mod = true;
                                }
                            }

                            ++$rcount;

                            if ($rhtml) {
                                $rhtml .= "<tr style='background-color: " . attr($bgcolor) . ";'>\n";
                            }
                            $rhtml .= "<td width='50'>";
                            if ($iter['id'] && $oldcode != $iter['code_type']) {
                                $rhtml .= "<span class='text'>" . text($iter['code_type']) . ": </span>";
                            }

                            $oldcode = $iter['code_type'];
                            $rhtml .= "</td>\n";
                            $justify = "";

                            if ($iter['id'] && !empty($code_types[$iter['code_type']]['just'])) {
                                $js = explode(":", $iter['justify']);
                                $counter = 0;
                                foreach ($js as $j) {
                                    if (!empty($j)) {
                                        if ($counter == 0) {
                                            $justify .= " (<b>" . text($j) . "</b>)";
                                        } else {
                                            $justify .= " (" . text($j) . ")";
                                        }
                                        $counter++;
                                    }
                                }
                            }

                            $rhtml .= "<td><span class='text'>" . ($iter['code_type'] == 'COPAY' ? text(oeFormatMoney($iter['code'])) : text($iter['code']));
                            if ($iter['modifier']) {
                                $rhtml .= ":" . text($iter['modifier']);
                            }
                            $rhtml .= "</span><span style='font-size:8pt;'>$justify</span></td>\n";

                            $rhtml .= '<td align="right"><span style="font-size:8pt;">&nbsp;&nbsp;&nbsp;';
                            if ($iter['id'] && $iter['fee'] > 0) {
                                $rhtml .= text(oeFormatMoney($iter['fee']));
                            }
                            $rhtml .= "</span></td>\n";
                            $rhtml .= '<td><span style="font-size:8pt; font-weight:900; background:#ffff9e">&nbsp;&nbsp;&nbsp;';
                            if ($iter['id']) {
                                $rhtml .= getProviderName(empty($iter['provider_id']) ? text($iter['enc_provider_id']) : text($iter['provider_id']));
                            }
                            $rhtml .= "</span></td>\n";
                            $rhtml .= '<td><span style="font-size:8pt;">&nbsp;&nbsp;&nbsp;';
                            if ($GLOBALS['display_units_in_billing'] != 0) {
                                if ($iter['id']) {
                                    $rhtml .= xlt("Units") . ":" . text($iter["units"]);
                                }
                            }
                            $rhtml .= "</span></td>\n";
                            $rhtml .= '<td width="100">&nbsp;&nbsp;&nbsp;<span style="font-size:8pt;">';
                            if ($iter['id']) {
                                $rhtml .= text(oeFormatSDFT(strtotime($iter["date"])));
                            }
                            $rhtml .= "</span></td>\n";
// This error message is generated if the authorized check box is not checked
                            if ($iter['id'] && $iter['authorized'] != 1) {
                                $rhtml .= "<td><span class='alert'>" . xlt("Note: This code has not been authorized.") . "</span></td>\n";
                            } else {
                                $rhtml .= "<td></td>\n";
                            }
                            if ($iter['id'] && $last_encounter_id != $this_encounter_id) {
                                $tmpbpr = $iter['bill_process'];
                                if ($tmpbpr == '0' && $iter['billed']) {
                                    $tmpbpr = '2';
                                }
                                $rhtml .= "<td><input type='checkbox' value='" . attr($tmpbpr) . "' name='claims[" . attr($this_encounter_id) . "][bill]' onclick='set_button_states()' id='CheckBoxBilling" . attr(($CheckBoxBilling ?? null) * 1) . "' data-enc-id='" . attr($this_encounter_id) . "'>&nbsp;</td>\n";
                                $CheckBoxBilling = ($CheckBoxBilling ?? null) + 1;
                            } else {
                                $rhtml .= "<td></td>\n";
                            }
                            if ($last_encounter_id != $this_encounter_id) {
                                $rhtml2 = "";
                                $rowcnt = 0;
                                $resMoneyGot = sqlStatement(
                                    "SELECT pay_amount AS PatientPay,date(post_time) AS date FROM ar_activity WHERE " .
                                    "pid = ? AND encounter = ? AND deleted IS NULL AND payer_type = 0 AND account_code = 'PCP'",
                                    array(
                                        $iter['enc_pid'],
                                        $iter['enc_encounter']
                                    )
                                );
// new fees screen copay gives account_code='PCP'
                                if (sqlNumRows($resMoneyGot) > 0) {
                                    $lcount += 2;
                                    $rcount++;
                                }
// checks whether a copay exists for the encounter and if exists displays it.
                                while ($rowMoneyGot = sqlFetchArray($resMoneyGot)) {
                                    $rowcnt++;
                                    $PatientPay = $rowMoneyGot['PatientPay'];
                                    $date = $rowMoneyGot['date'];
                                    if ($PatientPay > 0) {
                                        if ($rhtml) {
                                            $rhtml2 .= "<tr style='background-color: " . attr($bgcolor) . ";'>\n";
                                        }
                                        $rhtml2 .= "<td width='50'>";
                                        $rhtml2 .= "<span class='text'>" . xlt('COPAY') . ": </span>";
                                        $rhtml2 .= "</td>\n";
                                        $rhtml2 .= "<td><span class='text'>" . text(oeFormatMoney($PatientPay)) . "</span><span style='font-size:8pt;'>&nbsp;</span></td>\n";
                                        $rhtml2 .= '<td align="right"><span style="font-size:8pt;">&nbsp;&nbsp;&nbsp;';
                                        $rhtml2 .= "</span></td>\n";
                                        $rhtml2 .= '<td><span style="font-size:8pt;">&nbsp;&nbsp;&nbsp;';
                                        $rhtml2 .= "</span></td>\n";
                                        $rhtml2 .= '<td><span style="font-size:8pt;">&nbsp;&nbsp;&nbsp;';
                                        $rhtml2 .= "</span></td>\n";
                                        $rhtml2 .= '<td width=100>&nbsp;&nbsp;&nbsp;<span style="font-size:8pt;">';
                                        $rhtml2 .= text(oeFormatSDFT(strtotime($date)));
                                        $rhtml2 .= "</span></td>\n";
                                        if ($iter['id'] && $iter['authorized'] != 1) {
                                            $rhtml2 .= "<td><span class='alert'>" . xlt("Note: This copay was entered against billing that has not been authorized. Please review status.") . "</span></td>\n";
                                        } else {
                                            $rhtml2 .= "<td></td>\n";
                                        }
                                        if (!$iter['id'] && $rowcnt == 1) {
                                            $rhtml2 .= "<td><input type='checkbox' value='0' name='claims[" . attr($this_encounter_id) . "][bill]' onclick='set_button_states()' id='CheckBoxBilling" . attr($CheckBoxBilling * 1) . "' data-enc-id='" . attr($this_encounter_id) . "'>&nbsp;</td>\n";
                                            $CheckBoxBilling++;
                                        } else {
                                            $rhtml2 .= "<td></td>\n";
                                        }
                                    }
                                }
                                $rhtml .= $rhtml2;
                            }
                            $rhtml .= "</tr>\n";
                            $last_encounter_id = $this_encounter_id;
                        } // end foreach

                        if ($lhtml) {
                            while ($rcount < $lcount) {
                                $rhtml .= "<tr style='background-color:" . attr($bgcolor) . ";line-height:0;font-size:0;'><td colspan='9' style='padding:0;line-height:0;font-size:0;height:0;'></td></tr>";
                                ++$rcount;
                            }
                            if (!$missing_mods_only || ($mmo_empty_mod && $mmo_num_charges > 1)) {
                                if ($DivPut == 'yes') {
                                    $lhtml .= '</div>';
                                    $DivPut = 'no';
                                }
                                echo "<tr style='background-color: " . attr($bgcolor) . ";'>\n<td rowspan='" . attr($rcount) . "' valign='top' width='25%'>\n$lhtml</td>$rhtml\n";
                                echo "<tr style='background-color: " . attr($bgcolor) . ";'><td colspan='9' height='5'></td></tr>\n";
                            }
                        }
                    }

                    if ($rendered === 0) { ?>
                    <tr>
                        <td colspan="9">
                            <div style="text-align:center; padding: 40px 20px; color: #6c757d;">
                                <i class="fa fa-filter fa-2x" style="display:block; margin-bottom:10px; opacity:.4;"></i>
                                <?php echo $bm_run_query ? xlt('There is nothing to bill.') : xlt('Select filters above and click Update List to view billing claims.'); ?>
                            </div>
                        </td>
                    </tr>
                    <?php } ?>

                </table>
            </div>
            <?php if (!empty($enc_scripts)) { ?>
            <script><?php echo $enc_scripts; ?></script>
            <?php } ?>
        </form>

    </div>
    <!--end of container div -->
    <?php $oemr_ui->oeBelowContainerDiv(); ?>
    <script>
        set_button_states();
        <?php
        if ($alertmsg) {
            echo "alert(" . js_escape($alertmsg) . ");\n";
        }
        ?>
        $(function () {
            // Auto-check all billing checkboxes so actions are ready immediately
            $('input[name^="claims["][type="checkbox"]').prop('checked', true);
            set_button_states();

            $("#view-log-link").click(function() {
                top.restoreSession();
                dlgopen('customize_log.php', '_blank', 750, 400);
            });
            $("#clear-log").click(function() {
                var checkstr = confirm(<?php echo xlj("Do you really want to clear the log?"); ?>);
                if (checkstr == true) {
                    top.restoreSession();
                    dlgopen("clear_log.php?csrf_token_form=" + <?php echo js_escape(CsrfUtils::collectCsrfToken()); ?>, '_blank', 500, 400);
                } else {
                    return false;
                }
            });

            $('button[type="submit"]').click(function() {
                top.restoreSession();
                $(this).attr('data-clicked', true);
            });

            $('form[name="update_form"]').on('submit', function(e) {
                var clickedButton = $("button[type=submit][data-clicked='true'")[0];
                $('button[type="submit"]').attr('data-clicked', false);
                if (!clickedButton || $(clickedButton).attr("data-open-popup") !== "true") {
                    $(this).removeAttr("target");
                    return top.restoreSession();
                } else {
                    top.restoreSession();
                    var w = window.open('about:blank', 'Popup_Window', 'toolbar=0,scrollbars=0,location=0,statusbar=0,menubar=0,resizable=0,width=400,height=300,left=312,top=234');
                    this.target = 'Popup_Window';
                }
            });

            $('.datepicker').datetimepicker({
                <?php $datetimepicker_timepicker = false; ?>
                <?php $datetimepicker_showseconds = false; ?>
                <?php $datetimepicker_formatInput = false; ?>
                <?php require $GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4.js.php'; ?>
            });
        });
    </script>
    <input type="hidden" name="divnos" id="divnos" value="<?php echo attr($divnos ?? '') ?>" />
    <input type='hidden' name='ajax_mode' id='ajax_mode' value='' />

    <!-- ══════════ AUTOFILL: Insurance & Patient Name ══════════ -->
    <script>
    (function () {
        'use strict';

        /* ── shared dropdown helpers ────────────────────────────── */
        var AC_ITEM_STYLE = [
            'padding:7px 12px',
            'cursor:pointer',
            'font-size:13px',
            'color:#1a1523',
            'border-bottom:1px solid #f3f4f6',
            'white-space:nowrap',
            'overflow:hidden',
            'text-overflow:ellipsis'
        ].join(';');

        function showDropdown(drop, items) {
            drop.innerHTML = '';
            if (!items.length) { drop.style.display = 'none'; return; }
            items.forEach(function (item) {
                var d = document.createElement('div');
                d.setAttribute('style', AC_ITEM_STYLE);
                d.textContent = item.label;
                d.addEventListener('mouseenter', function () { this.style.background = '#f5f3ff'; });
                d.addEventListener('mouseleave', function () { this.style.background = ''; });
                d.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    item.onSelect();
                });
                drop.appendChild(d);
            });
            drop.style.display = 'block';
        }

        function hideDropdown(drop) { drop.style.display = 'none'; }

        

        function debounce(fn, ms) {
            var t; return function () { clearTimeout(t); t = setTimeout(fn, ms); };
        }

        /* ── keyboard nav helper ───────────────────────────────── */
        function attachKeyNav(input, drop) {
            input.addEventListener('keydown', function (e) {
                var items = drop.querySelectorAll('div');
                var active = drop.querySelector('div.ac-active');
                var idx = -1;
                items.forEach(function (el, i) { if (el === active) idx = i; });

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    var next = items[idx + 1] || items[0];
                    if (active) { active.classList.remove('ac-active'); active.style.background = ''; }
                    if (next)   { next.classList.add('ac-active'); next.style.background = '#ede9fe'; next.scrollIntoView({block:'nearest'}); }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    var prev = items[idx - 1] || items[items.length - 1];
                    if (active) { active.classList.remove('ac-active'); active.style.background = ''; }
                    if (prev)   { prev.classList.add('ac-active'); prev.style.background = '#ede9fe'; prev.scrollIntoView({block:'nearest'}); }
                } else if (e.key === 'Enter') {
                    if (active) { e.preventDefault(); active.dispatchEvent(new MouseEvent('mousedown')); }
                } else if (e.key === 'Escape') {
                    hideDropdown(drop);
                }
            });
        }

        /* ══ 1. INSURANCE autocomplete ════════════════════════════ */
        var insDisplay = document.getElementById('bm_insurance_display');
        var insHidden  = document.getElementById('bm_insurance');
        var insDrop    = document.getElementById('bm_insurance_ac');

        if (insDisplay && insDrop) {
            var insData = <?php
                $ins_ac = sqlStatement("SELECT id, name FROM insurance_companies ORDER BY name");
                $ins_ac_arr = [];
                while ($r = sqlFetchArray($ins_ac)) { $ins_ac_arr[] = ['id' => $r['id'], 'name' => $r['name']]; }
                echo json_encode($ins_ac_arr);
            ?>;

            function filterIns(q) {
                q = q.toLowerCase();
                return insData.filter(function (r) {
                    return r.name.toLowerCase().indexOf(q) !== -1;
                }).slice(0, 40);
            }

            function renderIns(q) {
                if (q.length < 1) { hideDropdown(insDrop); return; }
                var matches = filterIns(q);
                var items = matches.map(function (r) {
                    return {
                        label: r.name,
                        value: r.id,
                        onSelect: function () {
                            insDisplay.value = r.name;
                            insHidden.value  = r.id;
                            hideDropdown(insDrop);
                        }
                    };
                });
                // Prepend "All Insurance" clear option
                items.unshift({
                    label: <?php echo json_encode(xl('All Insurance')); ?>,
                    value: '',
                    onSelect: function () {
                        insDisplay.value = '';
                        insHidden.value  = '';
                        hideDropdown(insDrop);
                    }
                });
                showDropdown(insDrop, items);
            }

            insDisplay.addEventListener('input', debounce(function () { renderIns(insDisplay.value); }, 150));
            insDisplay.addEventListener('focus', function () { if (insDisplay.value.length >= 1) renderIns(insDisplay.value); });
            insDisplay.addEventListener('blur',  function () { setTimeout(function () { hideDropdown(insDrop); }, 180); });
            insDisplay.addEventListener('input', function () { if (!insDisplay.value.trim()) insHidden.value = ''; });
            attachKeyNav(insDisplay, insDrop);
        }

        /* ══ 2. PATIENT NAME autocomplete ═════════════════════════ */
        var patDisplay = document.getElementById('bm_patient_display');
        var patHidden  = document.getElementById('bm_patient');
        var patDrop    = document.getElementById('bm_patient_ac');
        var patXhr     = null;

        if (patDisplay && patDrop) {
            function renderPat(q) {
                if (q.length < 2) { hideDropdown(patDrop); return; }
                if (patXhr) { patXhr.abort(); }
                // Self-contained endpoint embedded in this same file
                var url = 'billing_report.php?bm_patient_search=1&q=' + encodeURIComponent(q);
                patXhr = $.getJSON(url)
                    .done(function (data) {
                        if (!Array.isArray(data) || !data.length) {
                            patDrop.innerHTML = '<div style="' + AC_ITEM_STYLE + ';color:#9ca3af;">' +
                                <?php echo json_encode(xl('No patients found')); ?> + '</div>';
                            patDrop.style.display = 'block';
                            return;
                        }
                        var items = data.map(function (p) {
                            var displayLabel = p.lname + ', ' + p.fname + ' - #' + p.pubpid;
                            // filterValue matches what server builds: "lname fname" (no comma)
                            var filterValue  = (p.lname + ' ' + p.fname).trim();
                            return {
                                label: displayLabel,
                                onSelect: function () {
                                    patDisplay.value = p.lname + ', ' + p.fname;
                                    patHidden.value  = filterValue;
                                    hideDropdown(patDrop);
                                }
                            };
                        });
                        showDropdown(patDrop, items);
                    })
                    .fail(function (xhr) {
                        if (xhr.status === 0) return; // aborted
                        hideDropdown(patDrop);
                    });
            }

            patDisplay.addEventListener('input', debounce(function () {
                // Normalize typed text: strip comma so "Smith, John" → "smith john" matches server
                patHidden.value = patDisplay.value.replace(/,/g, ' ').replace(/\s+/g, ' ').trim();
                renderPat(patDisplay.value);
            }, 220));
            patDisplay.addEventListener('focus', function () { if (patDisplay.value.length >= 2) renderPat(patDisplay.value); });
            patDisplay.addEventListener('blur',  function () { setTimeout(function () { hideDropdown(patDrop); }, 180); });
            attachKeyNav(patDisplay, patDrop);
        }

    }());
    </script>
    <!-- ══════════ END AUTOFILL ══════════ -->

    <!-- ══════════ BATCH PICKER POPUP ══════════ -->
    <?php if (!empty($batch_options)) { ?>
    <div id="bm-batch-popup" role="dialog" aria-modal="true" aria-labelledby="bm-batch-modal-title">
        <div id="bm-batch-backdrop" onclick="bmBatchClose()"></div>
        <div id="bm-batch-modal">
            <div id="bm-batch-modal-header">
                <h5 id="bm-batch-modal-title">&#128196; <?php echo xlt('Select Batch'); ?></h5>
                <span class="batch-count-badge"><?php echo count($batch_options); ?> <?php echo xlt('batches'); ?></span>
                <button type="button" class="btn-close-batch" onclick="bmBatchClose()" title="Close">&times;</button>
            </div>
            <div id="bm-batch-search-wrap">
                <input type="text" id="bm-batch-search" placeholder="<?php echo xla('Search batches...'); ?>"
                       oninput="bmBatchFilter(this.value)" autocomplete="off" />
            </div>
            <div id="bm-batch-list">
                <div class="batch-item" id="bm-batch-item-all" data-value="" onclick="bmBatchSelect(this)">
                    <div class="batch-icon">&#9654;</div>
                    <div class="batch-name"><?php echo xlt('All Batches'); ?></div>
                    <div class="batch-check"></div>
                </div>
                <?php foreach ($batch_options as $bopt) { ?>
                <div class="batch-item" data-value="<?php echo attr($bopt); ?>" onclick="bmBatchSelect(this)">
                    <div class="batch-icon">&#128196;</div>
                    <div class="batch-name"><?php echo text($bopt); ?></div>
                    <div class="batch-check"></div>
                </div>
                <?php } ?>
                <div id="bm-batch-empty" style="display:none"><?php echo xlt('No batches match your search.'); ?></div>
            </div>
            <div id="bm-batch-modal-footer">
                <button type="button" id="bm-batch-clear-btn" onclick="bmBatchClear()"><?php echo xlt('Clear'); ?></button>
                <button type="button" id="bm-batch-apply-btn" onclick="bmBatchApply()"><?php echo xlt('Apply Filter'); ?></button>
            </div>
        </div>
    </div>
    <script>
    (function() {
        'use strict';
        var _pending = '';
        function getSelected() { return document.getElementById('bm_batch').value; }
        function markSelected(val) {
            document.querySelectorAll('#bm-batch-list .batch-item').forEach(function(el) {
                el.classList.toggle('selected', el.dataset.value === val);
            });
        }
        window.bmBatchOpen = function() {
            _pending = getSelected();
            markSelected(_pending);
            document.getElementById('bm-batch-search').value = '';
            bmBatchFilter('');
            document.getElementById('bm-batch-popup').classList.add('open');
            document.getElementById('bm-batch-btn').classList.add('active');
            setTimeout(function() { document.getElementById('bm-batch-search').focus(); }, 80);
        };
        window.bmBatchClose = function() {
            document.getElementById('bm-batch-popup').classList.remove('open');
            var btn = document.getElementById('bm-batch-btn');
            if (btn) btn.classList.remove('active');
        };
        window.bmBatchSelect = function(el) { _pending = el.dataset.value; markSelected(_pending); };
        window.bmBatchClear  = function() { _pending = ''; markSelected(''); };
        window.bmBatchApply  = function() {
            var val = _pending;
            document.getElementById('bm_batch').value = val;
            var btn = document.getElementById('bm-batch-btn');
            if (btn) {
                btn.innerHTML = val
                    ? '<span class="batch-tag">&#128196;</span><span class="batch-btn-label has-value">' + escHtml(val) + '</span><span class="batch-caret">&#9660;</span>'
                    : '<span class="batch-btn-label"><?php echo xlt("All Batches"); ?></span><span class="batch-caret">&#9660;</span>';
            }
            bmBatchClose();
        };
        window.bmBatchFilter = function(q) {
            q = q.toLowerCase().trim();
            var items = document.querySelectorAll('#bm-batch-list .batch-item');
            var visible = 0;
            items.forEach(function(el) {
                if (el.id === 'bm-batch-item-all') { el.style.display = ''; return; }
                var show = !q || el.dataset.value.toLowerCase().indexOf(q) !== -1;
                el.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            document.getElementById('bm-batch-empty').style.display = (visible === 0 && q) ? '' : 'none';
        };
        function escHtml(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('bm-batch-popup').classList.contains('open')) bmBatchClose();
        });
        markSelected(getSelected());
    }());
    </script>
    <?php } ?>
    <!-- ══════════ END BATCH PICKER POPUP ══════════ -->
</body>

</html>
<?php
// Flush the top-level output buffer started at the top of this file.
// For AJAX requests this is never reached (bv_emit calls ob_get_clean and exits).
// For normal page loads this sends all the buffered HTML to the browser.
if (ob_get_level() > 0) { ob_end_flush(); }
?>
































































































