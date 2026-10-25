<?php

/**
 * This screen handles the cash/cheque entry and its distribution to various charges.
 *
 * The functions of this class support the billing process like the script billing_process.php.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Eldho Chacko <eldho@zhservices.com>
 * @author    Paul Simon K <paul@zhservices.com>
 * @author    Stephen Waite <stephen.waite@cmsvt.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) Z&H Consultancy Services Private Limited <sam@zhservices.com>
 * @copyright Copyright (C) 2018 Stephen Waite <stephen.waite@cmsvt.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../globals.php");
require_once("../../custom/code_types.inc.php");
require_once("$srcdir/patient.inc.php");
require_once("$srcdir/options.inc.php");
require_once("$srcdir/payment.inc.php");

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;
use OpenEMR\OeUI\OemrUI;

// ERA parse callback — top-level with guard to prevent redeclaration fatal
if (!function_exists('_np_era_callback')) {
    function _np_era_callback(&$out) {
        global $eracount, $eraname;
        ++$eracount;
        $eraname = $out['gs_date'] . '_' . ltrim($out['isa_control_number'], '0')
                 . '_' . ltrim($out['payer_id'] ?? $out['isa_sender_id'] ?? '0', '0');
    }
}

// ── AJAX: sample EOB data for testing ────────────────────────────────────────
if (isset($_GET['ajax_sample_eob'])) {
    header('Content-Type: application/json');
    // Create a sample ar_session if none exists for testing
    $sample_payer = sqlQuery("SELECT id, name FROM insurance_companies ORDER BY name LIMIT 1");
    $sample_patient = sqlQuery("SELECT pid, lname, fname FROM patient_data WHERE pid > 0 LIMIT 1");
    $sample_encounter = null;
    $sample_billing = [];

    if (!empty($sample_patient)) {
        $enc_res = sqlStatement(
            "SELECT fe.encounter, fe.date, b.id AS billing_id, b.code, b.code_text,
                    b.modifier, b.fee, b.pid
               FROM form_encounter fe
               JOIN billing b ON b.encounter = fe.encounter AND b.pid = fe.pid AND b.activity = 1
              WHERE fe.pid = ?
              ORDER BY fe.date DESC LIMIT 3",
            [$sample_patient['pid']]
        );
        while ($r = sqlFetchArray($enc_res)) $sample_billing[] = $r;
    }

    echo json_encode([
        'payer'    => $sample_payer,
        'patient'  => $sample_patient,
        'billing'  => $sample_billing,
        'tip'      => 'Use the sidebar EOBs to test posting. Enter a Payment amount (e.g. 21.71), Adj (e.g. 5.00), and/or Ded (e.g. 8.29) then click Post EOB.',
    ]);
    exit;
}

// ── AJAX: patient search (autocomplete) ──────────────────────────────────────
if (isset($_GET['ajax_pt_search'])) {
    header('Content-Type: application/json');
    $q = '%' . trim($_GET['q'] ?? '') . '%';
    $res = sqlStatement(
        "SELECT pid, lname, fname, DOB
           FROM patient_data
          WHERE (lname LIKE ? OR fname LIKE ? OR CONCAT(lname,' ',fname) LIKE ? OR CONCAT(fname,' ',lname) LIKE ?)
            AND pid > 0
          ORDER BY lname, fname LIMIT 30",
        [$q, $q, $q, $q]
    );
    $rows = [];
    while ($r = sqlFetchArray($res)) $rows[] = $r;
    echo json_encode($rows); exit;
}

// ── AJAX: encounters for a patient ────────────────────────────────────────────
if (isset($_GET['ajax_encounters'])) {
    header('Content-Type: application/json');
    $pid = (int)($_GET['pid'] ?? 0);
    if (!$pid) { echo json_encode([]); exit; }
    $res = sqlStatement(
        "SELECT fe.encounter, fe.date, fe.reason,
                f.name AS facility_name,
                COALESCE(SUM(b.fee),0) AS total_charge,
                COALESCE(SUM(aa.pay_amount),0) AS total_paid
           FROM form_encounter fe
           LEFT JOIN facility f ON f.id = fe.facility_id
           LEFT JOIN billing b ON b.encounter = fe.encounter AND b.pid = fe.pid
           AND b.activity = 1
           LEFT JOIN ar_activity aa ON aa.encounter = fe.encounter AND aa.pid = fe.pid
                                    AND aa.deleted IS NULL
          WHERE fe.pid = ?
          GROUP BY fe.encounter, fe.date, fe.reason, f.name
          ORDER BY fe.date DESC LIMIT 50",
        [$pid]
    );
    $rows = [];
    while ($r = sqlFetchArray($res)) $rows[] = $r;
    echo json_encode($rows); exit;
}

// ── AJAX: charges for an encounter ───────────────────────────────────────────
if (isset($_GET['ajax_charges'])) {
    header('Content-Type: application/json');
    $enc = (int)($_GET['encounter'] ?? 0);
    $pid = (int)($_GET['pid'] ?? 0);
    if (!$enc) { echo json_encode([]); exit; }
    $res = sqlStatement(
        "SELECT b.id AS billing_id,
                b.code, b.code_text, b.modifier, b.fee AS charge, b.units,
                fe.date AS dos,
                COALESCE(SUM(aa.pay_amount),0) AS paid,
                COALESCE(SUM(aa.adj_amount),0) AS adjusted,
                (b.fee - COALESCE(SUM(aa.pay_amount),0) - COALESCE(SUM(aa.adj_amount),0)) AS balance
           FROM billing b
           JOIN form_encounter fe ON fe.encounter = b.encounter AND fe.pid = b.pid
           LEFT JOIN ar_activity aa
            ON aa.pid = b.pid
            AND aa.encounter = b.encounter
            AND aa.code = b.code
            AND COALESCE(aa.modifier,'')
                = COALESCE(b.modifier,'')
            AND aa.deleted IS NULL
          WHERE b.encounter = ? AND b.pid = ?
          GROUP BY b.id
          ORDER BY b.code",
        [$enc, $pid]
    );
    $rows = [];
    while ($r = sqlFetchArray($res)) $rows[] = $r;
    echo json_encode($rows); exit;
}

// ── AJAX: load open charges for a carrier ────────────────────────────────────
if (isset($_GET['ajax_carrier_charges'])) {
    header('Content-Type: application/json');
    $carrier_id = (int)($_GET['carrier_id'] ?? 0);
    if (!$carrier_id) { echo json_encode([]); exit; }
    $res = sqlStatement(
        "SELECT
            b.id          AS billing_id,
            fe.date       AS dos,
            fe.encounter,
            b.pid,
            CONCAT(pd.lname,', ',pd.fname)   AS patient_name,
            b.code,
            b.code_text,
            b.modifier,
            b.fee         AS charge,
            u.lname       AS provider_lname,
            u.fname       AS provider_fname,
            COALESCE(SUM(aa.pay_amount),0)   AS paid,
            COALESCE(SUM(aa.adj_amount),0)   AS adjusted,
            (b.fee - COALESCE(SUM(aa.pay_amount),0) - COALESCE(SUM(aa.adj_amount),0)) AS balance
           FROM billing b
           JOIN form_encounter fe ON fe.encounter = b.encounter AND fe.pid = b.pid
           JOIN patient_data pd ON pd.pid = b.pid
           LEFT JOIN users u ON u.id = b.provider_id
           LEFT JOIN ar_activity aa ON aa.pid = b.pid AND aa.encounter = b.encounter
                                    AND aa.deleted IS NULL
          WHERE b.activity = 1 
            AND b.payer_id = ?
          GROUP BY b.id
          HAVING balance > 0
          ORDER BY fe.date DESC, pd.lname
          LIMIT 200",
        [$carrier_id]
    );
    $rows = [];
    while ($r = sqlFetchArray($res)) $rows[] = $r;
    echo json_encode($rows); exit;
}

// ── AJAX: load open charges for a patient (patient mode) ──────────────────────
if (isset($_GET['ajax_patient_charges'])) {
    header('Content-Type: application/json');
    $pid = (int)($_GET['pid'] ?? 0);
    if (!$pid) { echo json_encode([]); exit; }
    $res = sqlStatement(
        "SELECT
            b.id          AS billing_id,
            fe.date       AS dos,
            fe.encounter,
            b.pid,
            CONCAT(pd.lname,', ',pd.fname)   AS patient_name,
            b.code,
            b.code_text,
            b.modifier,
            b.fee         AS charge,
            u.lname       AS provider_lname,
            u.fname       AS provider_fname,
            COALESCE(SUM(aa.pay_amount),0)   AS paid,
            COALESCE(SUM(aa.adj_amount),0)   AS adjusted,
            (b.fee - COALESCE(SUM(aa.pay_amount),0) - COALESCE(SUM(aa.adj_amount),0)) AS balance
           FROM billing b
           JOIN form_encounter fe ON fe.encounter = b.encounter AND fe.pid = b.pid
           JOIN patient_data pd ON pd.pid = b.pid
           LEFT JOIN users u ON u.id = b.provider_id
           LEFT JOIN ar_activity aa ON aa.pid = b.pid AND aa.encounter = b.encounter
                                    AND aa.deleted IS NULL
          WHERE b.activity = 1 
            AND b.pid = ?
          GROUP BY b.id
          HAVING balance > 0
          ORDER BY fe.date DESC
          LIMIT 200",
        [$pid]
    );
    $rows = [];
    while ($r = sqlFetchArray($res)) $rows[] = $r;
    echo json_encode($rows); exit;
}

// ── AJAX: ERA file upload + parse (stays on page, no redirect) ───────────────
if (isset($_POST['ajax_era_process'])) {
    ob_start();
    header('Content-Type: application/json');

    if (!\OpenEMR\Common\Csrf\CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'CSRF verification failed.']);
        exit;
    }

    if (empty($_FILES['era_file']['size'])) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'No file received.']);
        exit;
    }

    $eraname  = '';
    $eracount = 0;
    $alertmsg = '';

    $tmp_name = $_FILES['era_file']['tmp_name'];
    if (strtolower(substr($_FILES['era_file']['name'], -4)) === '.zip') {
        rename($tmp_name, "$tmp_name.zip");
        exec("unzip -p " . escapeshellarg("$tmp_name.zip") . " > " . escapeshellarg($tmp_name));
        unlink("$tmp_name.zip");
    }

    $alertmsg .= \OpenEMR\Billing\ParseERA::parseERA($tmp_name, '_np_era_callback');

    if (!$eraname) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'Could not parse ERA file. Ensure it is a valid X12 835.']);
        exit;
    }

    $erafullname = $GLOBALS['OE_SITE_DIR'] . "/documents/era/$eraname.edi";
    $edihname    = $GLOBALS['OE_SITE_DIR'] . "/documents/edi/history/f835/$eraname.835";
    $already     = is_file($erafullname);
    rename($tmp_name, $erafullname);
    @copy($erafullname, $edihname);

    $checkDate  = trim($_POST['check_date']   ?? '');
    $postDate   = trim($_POST['post_to_date'] ?? '');
    $depositDate= trim($_POST['deposit_date'] ?? '');
    $insId      = trim($_POST['hidden_type_code'] ?? '');
    $without    = intval($_POST['form_without'] ?? 0);

    // Build the sl_eob_process URL — JS will open it in a new tab
    $process_url = $GLOBALS['webroot'] . '/interface/billing/sl_eob_process.php'
        . '?eraname='       . urlencode($eraname)
        . '&debug='         . urlencode($without)
        . '&paydate='       . urlencode($checkDate)
        . '&post_to_date='  . urlencode($postDate)
        . '&deposit_date='  . urlencode($depositDate)
        . '&original=original'
        . '&InsId='         . urlencode($insId)
        . '&csrf_token_form=' . urlencode(\OpenEMR\Common\Csrf\CsrfUtils::collectCsrfToken());

    ob_end_clean();
    echo json_encode([
        'success'     => true,
        'eraname'     => $eraname,
        'count'       => $eracount,
        'already'     => $already,
        'alert'       => $alertmsg,
        'process_url' => $process_url,
    ]);
    exit;
}

// ── AJAX: invoice search (Statements drawer) ─────────────────────────────────
if (isset($_GET['ajax_invoice_search'])) {
    ob_start();
    header('Content-Type: application/json');

    $s_name      = trim($_GET['s_name']      ?? '');
    $s_pid       = trim($_GET['s_pid']       ?? '');
    $s_encounter = trim($_GET['s_encounter'] ?? '');
    $s_date_from = trim($_GET['s_date_from'] ?? '');
    $s_date_to   = trim($_GET['s_date_to']   ?? '');
    $s_category  = trim($_GET['s_category']  ?? '');

    $where = '';
    if ($s_name) {
        if (preg_match('/^(.*\S)\s*,\s*(.*)/', $s_name, $m)) {
            $where .= "p.lname LIKE '" . add_escape_custom($m[1]) . "%' AND p.fname LIKE '" . add_escape_custom($m[2]) . "%'";
        } else {
            $where .= "p.lname LIKE '%" . add_escape_custom($s_name) . "%'";
        }
    }
    if ($s_pid) {
        $where .= ($where ? ' AND ' : '') . "f.pid = '" . add_escape_custom($s_pid) . "'";
    }
    if ($s_encounter) {
        $where .= ($where ? ' AND ' : '') . "f.encounter = '" . add_escape_custom($s_encounter) . "'";
    }
    if ($s_date_from) {
        $df = preg_match('/^\d{4}-\d{2}-\d{2}$/', $s_date_from) ? $s_date_from : '';
        $dt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $s_date_to)   ? $s_date_to   : '';
        if ($df) {
            $where .= ($where ? ' AND ' : '') . ($dt
                ? "f.date >= '$df' AND f.date <= '$dt'"
                : "f.date = '$df'");
        }
    }
    if (!$where) {
        $where = '1 = 1';
    }
    // Apply category filter on balance
    $query = "SELECT f.id, f.pid, f.encounter, f.date,
        f.last_level_closed, f.last_stmt_date, f.stmt_count, f.in_collection,
        p.fname, p.mname, p.lname, p.pubpid, p.billing_note,
        (SELECT SUM(b.fee) FROM billing b WHERE b.pid=f.pid AND b.encounter=f.encounter AND b.activity=1 AND b.code_type!='COPAY') AS charges,
        (SELECT SUM(a.pay_amount) FROM ar_activity a WHERE a.pid=f.pid AND a.encounter=f.encounter AND a.deleted IS NULL AND a.payer_type=0 AND a.account_code='PCP')*-1 AS copays,
        (SELECT SUM(a.pay_amount) FROM ar_activity a WHERE a.pid=f.pid AND a.encounter=f.encounter AND a.deleted IS NULL AND a.account_code!='PCP') AS payments,
        (SELECT SUM(a.adj_amount) FROM ar_activity a WHERE a.pid=f.pid AND a.encounter=f.encounter AND a.deleted IS NULL) AS adjustments
        FROM form_encounter f
        JOIN patient_data p ON p.pid=f.pid
        WHERE $where
        ORDER BY p.lname, p.fname, f.date DESC
        LIMIT 200";

    $res  = sqlStatement($query);
    $rows = [];
    while ($row = sqlFetchArray($res)) {
        $bal = sprintf('%.2f', ($row['charges'] ?? 0) + ($row['copays'] ?? 0) - ($row['payments'] ?? 0) - ($row['adjustments'] ?? 0));
        if ($s_category === 'due' && $bal <= 0) continue;
        $rows[] = [
            'id'        => (int)$row['id'],
            'pid'       => (int)$row['pid'],
            'encounter' => (int)$row['encounter'],
            'date'      => substr($row['date'] ?? '', 0, 10),
            'name'      => ($row['lname'] ?? '') . ', ' . ($row['fname'] ?? ''),
            'charges'   => number_format((float)($row['charges']   ?? 0), 2),
            'adj'       => number_format((float)($row['adjustments']?? 0), 2),
            'paid'      => number_format((float)(($row['payments'] ?? 0) - ($row['copays'] ?? 0)), 2),
            'balance'   => $bal,
            'stmt_count'=> (int)($row['stmt_count'] ?? 0),
            'last_stmt' => substr($row['last_stmt_date'] ?? '', 0, 10),
        ];
    }

    ob_end_clean();
    echo json_encode(['success'=>true,'rows'=>$rows,'total'=>count($rows)]);
    exit;
}

// ── AJAX: create a new ar_session from form data ─────────────────────────────
if (isset($_POST['ajax_create_session'])) {
    ob_start();
    header('Content-Type: application/json');

    $type_name     = trim($_POST['type_name']     ?? 'insurance');
    $payer_id      = (int)($_POST['payer_id']      ?? 0);
    $patient_id    = (int)($_POST['patient_id']    ?? 0);
    $check_number  = trim($_POST['check_number']  ?? '');
    $pay_total     = trim($_POST['pay_total']      ?? '0');
    $payment_method= trim($_POST['payment_method'] ?? 'check');
    $check_date_raw= trim($_POST['check_date']     ?? '');
    $description   = trim($_POST['description']    ?? '');

    // Validate minimum required fields
    if ($type_name === 'insurance' && !$payer_id) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'Please select a carrier before creating an EOB.']);
        exit;
    }
    if ($type_name === 'patient' && !$patient_id) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'Please select a patient before creating a payment.']);
        exit;
    }

    $check_date   = $check_date_raw ? DateToYYYYMMDD($check_date_raw) : date('Y-m-d');
    $post_to_date = date('Y-m-d');
    $modified_time= date('Y-m-d H:i:s');
    $user_id      = $_SESSION['authUserID'];

    $QueryPart = ($type_name === 'insurance')
        ? "payer_id = '" . add_escape_custom($payer_id) . "', patient_id = '0'"
        : "payer_id = '0', patient_id = '" . add_escape_custom($patient_id) . "'";

    $new_sid = sqlInsert(
        "INSERT INTO ar_session SET "
        . $QueryPart
        . ", user_id = '"        . add_escape_custom($user_id)        . "'"
        . ", closed = '0'"
        . ", reference = '"      . add_escape_custom($check_number)   . "'"
        . ", check_date = '"     . add_escape_custom($check_date)     . "'"
        . ", deposit_date = '"   . add_escape_custom($post_to_date)   . "'"
        . ", post_to_date = '"   . add_escape_custom($post_to_date)   . "'"
        . ", pay_total = '"      . add_escape_custom($pay_total)      . "'"
        . ", payment_type = '"   . add_escape_custom($type_name)      . "'"
        . ", payment_method = '" . add_escape_custom($payment_method) . "'"
        . ", description = '"    . add_escape_custom($description)    . "'"
        . ", modified_time = '"  . add_escape_custom($modified_time)  . "'"
    );

    if (!$new_sid) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'Database error creating session.']);
        exit;
    }

    // Return enough info for the sidebar to show the new item
    $row = sqlQuery(
        "SELECT s.*, ic.name AS payer_name, CONCAT(pd.lname,', ',pd.fname) AS patient_name
           FROM ar_session s
           LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
           LEFT JOIN patient_data pd ON pd.pid = s.patient_id
          WHERE s.session_id = ?",
        [$new_sid]
    );

    ob_end_clean();
    echo json_encode(['success'=>true,'session_id'=>(int)$new_sid,'session'=>$row]);
    exit;
}

// ── AJAX: update an existing ar_session header ────────────────────────────────
if (isset($_POST['ajax_update_session'])) {
    ob_start();
    header('Content-Type: application/json');

    $sid           = (int)($_POST['session_id']    ?? 0);
    $check_number  = trim($_POST['check_number']  ?? '');
    $pay_total     = trim($_POST['pay_total']      ?? '0');
    $payment_method= trim($_POST['payment_method'] ?? 'check');
    $check_date_raw= trim($_POST['check_date']     ?? '');
    $description   = trim($_POST['description']    ?? '');
    $payer_id      = (int)($_POST['payer_id']      ?? 0);
    $patient_id    = (int)($_POST['patient_id']    ?? 0);
    $type_name     = trim($_POST['type_name']      ?? 'insurance');

    if (!$sid) {
        ob_end_clean();
        echo json_encode(['success'=>false,'error'=>'No session ID.']);
        exit;
    }

    $check_date    = $check_date_raw ? DateToYYYYMMDD($check_date_raw) : date('Y-m-d');
    $modified_time = date('Y-m-d H:i:s');

    $QueryPart = ($type_name === 'insurance')
        ? "payer_id = '" . add_escape_custom($payer_id) . "', patient_id = '0'"
        : "payer_id = '0', patient_id = '" . add_escape_custom($patient_id) . "'";

    sqlStatement(
        "UPDATE ar_session SET "
        . $QueryPart
        . ", reference = '"      . add_escape_custom($check_number)   . "'"
        . ", check_date = '"     . add_escape_custom($check_date)     . "'"
        . ", pay_total = '"      . add_escape_custom($pay_total)      . "'"
        . ", payment_method = '" . add_escape_custom($payment_method) . "'"
        . ", description = '"    . add_escape_custom($description)    . "'"
        . ", modified_time = '"  . add_escape_custom($modified_time)  . "'"
        . " WHERE session_id = '" . add_escape_custom($sid) . "'"
    );

    ob_end_clean();
    echo json_encode(['success'=>true]);
    exit;
}

// ── AJAX: EOB detail — open charges, applied, posted history ─────────────────
if (isset($_GET['ajax_eob_detail'])) {
    ob_start(); // buffer any stray warnings so they don't corrupt JSON
    header('Content-Type: application/json');

    $sid = (int)($_GET['session_id'] ?? 0);

    if (!$sid) {
        ob_end_clean();
        echo json_encode(['session'=>null,'open'=>[],'applied'=>[],'history'=>[]]);
        exit;
    }

    $session = sqlQuery(
        "SELECT s.*,
                ic.name AS payer_name,
                CONCAT(pd.lname, ', ', pd.fname) AS patient_name
           FROM ar_session s
      LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
      LEFT JOIN patient_data pd ON pd.pid = s.patient_id
          WHERE s.session_id = ?",
        [$sid]
    );

    if (!$session) {
        echo json_encode(['session'=>null,'open'=>[],'applied'=>[],'history'=>[]]);
        exit;
    }

    $open = [];
    $applied = [];
    $history = [];
    $encounters = [];

    // 1. Applied rows for THIS EOB — one row per ar_activity entry, all fields
    $ap_res = sqlStatement(
        "SELECT
            aa.encounter,
            aa.pid,
            aa.code,
            aa.modifier,
            aa.pay_amount,
            aa.adj_amount,
            aa.account_code,
            aa.memo,
            aa.sequence_no,
            aa.payer_type,
            fe.date          AS dos,
            CONCAT(pd.lname, ', ', pd.fname) AS patient_name
         FROM ar_activity aa
         LEFT JOIN form_encounter fe
           ON fe.encounter = aa.encounter
          AND fe.pid = aa.pid
         LEFT JOIN patient_data pd
           ON pd.pid = aa.pid
         WHERE aa.session_id = ?
           AND aa.deleted IS NULL
         ORDER BY fe.date DESC, aa.sequence_no",
        [$sid]
    );

    while ($r = sqlFetchArray($ap_res)) {
        $applied[] = $r;
        $encounters[(int)$r['encounter']] = (int)$r['pid'];
    }

    // 2. OPEN CHARGES
    if (count($applied) == 0) {
        // Draft EOB: no applied rows yet, show eligible open charges.

        if (!empty($session['patient_id'])) {
            $res = sqlStatement(
                "SELECT
                    b.id AS billing_id,
                    b.pid,
                    b.encounter,
                    b.code,
                    b.code_text,
                    b.modifier,
                    b.fee AS charge,
                    fe.date AS dos,
                    CONCAT(pd.lname,', ',pd.fname) AS patient_name,
                    COALESCE(SUM(aa.pay_amount),0) AS paid,
                    COALESCE(SUM(aa.adj_amount),0) AS adjusted,
                    (
                        b.fee
                        - COALESCE(SUM(aa.pay_amount),0)
                        - COALESCE(SUM(aa.adj_amount),0)
                    ) AS balance
                 FROM billing b
                 JOIN form_encounter fe
                   ON fe.encounter = b.encounter
                  AND fe.pid = b.pid
                 JOIN patient_data pd
                   ON pd.pid = b.pid
                 LEFT JOIN ar_activity aa
                   ON aa.pid = b.pid
                  AND aa.encounter = b.encounter
                  AND aa.deleted IS NULL
                WHERE b.activity = 1
                  AND b.pid = ?
                GROUP BY b.id
                HAVING balance > .01
                ORDER BY fe.date DESC",
                [$session['patient_id']]
            );

            while ($r = sqlFetchArray($res)) {
                $open[] = $r;
                $encounters[(int)$r['encounter']] = (int)$r['pid'];
            }

        } elseif (!empty($session['payer_id'])) {
            $res = sqlStatement(
                "SELECT
                    b.id AS billing_id,
                    b.pid,
                    b.encounter,
                    b.code,
                    b.code_text,
                    b.modifier,
                    b.fee AS charge,
                    fe.date AS dos,
                    CONCAT(pd.lname,', ',pd.fname) AS patient_name,
                    COALESCE(SUM(aa.pay_amount),0) AS paid,
                    COALESCE(SUM(aa.adj_amount),0) AS adjusted,
                    (
                        b.fee
                        - COALESCE(SUM(aa.pay_amount),0)
                        - COALESCE(SUM(aa.adj_amount),0)
                    ) AS balance
                 FROM billing b
                 JOIN form_encounter fe
                   ON fe.encounter = b.encounter
                  AND fe.pid = b.pid
                 JOIN patient_data pd
                   ON pd.pid = b.pid
                 LEFT JOIN ar_activity aa
                   ON aa.pid = b.pid
                  AND aa.encounter = b.encounter
                  AND aa.deleted IS NULL
                WHERE b.activity = 1
                  AND b.payer_id = ?
                GROUP BY b.id
                HAVING balance > .01
                ORDER BY fe.date DESC",
                [$session['payer_id']]
            );

            while ($r = sqlFetchArray($res)) {
                $open[] = $r;
                $encounters[(int)$r['encounter']] = (int)$r['pid'];
            }
        }

    } else {
        // Posted or partially posted EOB:
        // only show remaining open balances for encounters attached to THIS EOB.

        foreach ($encounters as $enc => $pid) {
            $oc_res = sqlStatement(
                "SELECT
                    b.id AS billing_id,
                    b.pid,
                    b.encounter,
                    b.code,
                    b.code_text,
                    b.modifier,
                    b.fee AS charge,
                    fe.date AS dos,
                    CONCAT(pd.lname,', ',pd.fname) AS patient_name,
                    COALESCE(SUM(aa.pay_amount),0) AS paid,
                    COALESCE(SUM(aa.adj_amount),0) AS adjusted,
                    (
                        b.fee
                        - COALESCE(SUM(aa.pay_amount),0)
                        - COALESCE(SUM(aa.adj_amount),0)
                    ) AS balance
                 FROM billing b
                 JOIN form_encounter fe
                   ON fe.encounter = b.encounter
                  AND fe.pid = b.pid
                 JOIN patient_data pd
                   ON pd.pid = b.pid
                 LEFT JOIN ar_activity aa
                   ON aa.pid = b.pid
                  AND aa.encounter = b.encounter
                  AND aa.deleted IS NULL
                WHERE b.activity = 1
                  AND b.encounter = ?
                  AND b.pid = ?
                GROUP BY b.id
                HAVING balance > .01
                ORDER BY fe.date DESC",
                [$enc, $pid]
            );

            while ($r = sqlFetchArray($oc_res)) {
                $open[] = $r;
            }
        }
    }

    // 3. Posted history: activity from OTHER sessions — per-code, per-row
    foreach ($encounters as $enc => $pid) {
        $hi_res = sqlStatement(
            "SELECT
                aa.encounter,
                aa.pid,
                aa.code,
                aa.modifier,
                aa.pay_amount,
                aa.adj_amount,
                aa.account_code,
                aa.memo,
                aa.sequence_no,
                aa.payer_type,
                s.session_id,
                s.reference,
                s.check_date,
                s.payment_type,
                CASE
                    WHEN aa.account_code = 'Deduct' THEN 'Deductible'
                    WHEN aa.pay_amount  <> 0        THEN 'Payment'
                    WHEN aa.adj_amount  <> 0        THEN 'Adjustment'
                    ELSE 'Other'
                END AS activity_type,
                fe.date AS dos,
                CONCAT(pd.lname, ', ', pd.fname) AS patient_name
             FROM ar_activity aa
             JOIN ar_session s  ON s.session_id = aa.session_id
             LEFT JOIN form_encounter fe
               ON fe.encounter = aa.encounter AND fe.pid = aa.pid
             LEFT JOIN patient_data pd ON pd.pid = aa.pid
            WHERE aa.encounter = ?
              AND aa.pid = ?
              AND aa.session_id <> ?
              AND aa.deleted IS NULL
            ORDER BY s.check_date DESC, aa.sequence_no",
            [$enc, $pid, $sid]
        );
        while ($r = sqlFetchArray($hi_res)) {
            $history[] = $r;
        }
    }

    ob_end_clean(); // discard any buffered warnings
    echo json_encode([
        'session' => $session,
        'open' => $open,
        'applied' => $applied,
        'history' => $history
    ]);
    exit;
}

// ── AJAX: search payments ─────────────────────────────────────────────────────
if (isset($_GET['ajax_payment_search'])) {
    header('Content-Type: application/json');
    $where  = ["1=1"];   // no type restriction — show all EOBs
    $params = [];

    $payer_id   = (int)($_GET['payer_id']   ?? 0);
    $check_num  = trim($_GET['check_num']   ?? '');
    $date_from  = trim($_GET['date_from']   ?? '');
    $date_to    = trim($_GET['date_to']     ?? '');
    $amount     = trim($_GET['amount']      ?? '');
    $method     = trim($_GET['method']      ?? '');
    $status     = trim($_GET['status']      ?? '');  // 'posted'|'draft'|''

    if ($payer_id) {
        $where[]  = 's.payer_id = ?';
        $params[] = $payer_id;
    }
    if ($check_num !== '') {
        $where[]  = 's.reference LIKE ?';
        $params[] = '%' . $check_num . '%';
    }
    if ($date_from !== '') {
        $where[]  = 'DATE(s.check_date) >= ?';
        $params[] = date('Y-m-d', strtotime($date_from));
    }
    if ($date_to !== '') {
        $where[]  = 'DATE(s.check_date) <= ?';
        $params[] = date('Y-m-d', strtotime($date_to));
    }
    if ($amount !== '') {
        $where[]  = 's.pay_total = ?';
        $params[] = (float)str_replace(['$', ','], '', $amount);
    }
    if ($method !== '') {
        $where[]  = 's.payment_method = ?';
        $params[] = $method;
    }

    $sql = "SELECT s.session_id, s.reference, s.check_date, s.pay_total,
                   s.payment_method, s.created_time,
                   ic.name AS payer_name,
                   CONCAT(pd.lname, ', ', pd.fname) AS patient_name,
                   COALESCE(SUM(a.pay_amount), 0)     AS posted_amount,
                   COUNT(DISTINCT a.encounter)         AS enc_count
              FROM ar_session s
              LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
              LEFT JOIN patient_data pd ON pd.pid = s.patient_id
              LEFT JOIN ar_activity a
                ON a.session_id = s.session_id AND a.deleted IS NULL
             WHERE " . implode(' AND ', $where) . "
             GROUP BY s.session_id, s.reference, s.check_date, s.pay_total,
                      s.payment_method, s.created_time, ic.name, pd.lname, pd.fname
             ORDER BY s.session_id DESC
             LIMIT 50";

    $res  = sqlStatement($sql, $params);
    $rows = [];
    while ($r = sqlFetchArray($res)) {

        $posted_amt = (float)$r['posted_amount'];
        $total      = (float)$r['pay_total'];
        $remaining  = round($total - $posted_amt, 2);
    
        if ($posted_amt <= 0.01) {
            $row_status = 'draft';
        } elseif ($remaining > 0.01) {
            $row_status = 'partial';
        } else {
            $row_status = 'posted';
        }
    
        if ($status !== '' && $row_status !== $status) {
            continue;
        }
    
        $r['post_status'] = $row_status;
        $r['unassigned']  = $remaining;
    
        $rows[] = $r;
    }
    echo json_encode($rows);
    exit;
}

if (!AclMain::aclCheckCore('acct', 'bill', '', 'write') && !AclMain::aclCheckCore('acct', 'eob', '', 'write')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("New Payment")]);
    exit;
}

//===============================================================================
    $screen = 'new_payment';
//===============================================================================
// Initialisations
$mode                    = isset($_POST['mode'])                   ? $_POST['mode']                   : '';
$payment_id              = isset($_REQUEST['payment_id'])          ? $_REQUEST['payment_id'] + 0      : 0;
$request_payment_id      = $payment_id ;
$hidden_patient_code     = isset($_REQUEST['hidden_patient_code']) ? $_REQUEST['hidden_patient_code'] : '';
$default_search_patient  = isset($_POST['default_search_patient']) ? $_POST['default_search_patient'] : '';
$hidden_type_code        = isset($_REQUEST['hidden_type_code']) ? $_REQUEST['hidden_type_code'] : '';
//===============================================================================
//ar_session addition code
//===============================================================================

if ($mode == "new_payment" || $mode == "distribute") {

    if (trim($_POST['type_name']) == 'insurance') {

        $QueryPart =
            "payer_id = '" . add_escape_custom($hidden_type_code) .
            "', patient_id = '0'";

    } elseif (trim($_POST['type_name']) == 'patient') {

        $QueryPart =
            "payer_id = '0', patient_id = '" .
            add_escape_custom($hidden_patient_code) .
            "'";
    }

      $user_id = $_SESSION['authUserID'];
      $closed = 0;
      $modified_time = date('Y-m-d H:i:s');
      $check_date = DateToYYYYMMDD(formData('check_date'));
      $deposit_date = DateToYYYYMMDD(formData('deposit_date'));
      $post_to_date = DateToYYYYMMDD(formData('post_to_date'));
    if ($post_to_date == '') {
        $post_to_date = date('Y-m-d');
    }
    if ($_POST['deposit_date'] == '') {
        $deposit_date = $post_to_date;
    }
      $payment_id = sqlInsert("insert into ar_session set "    .
        $QueryPart .
        "', user_id = '"     . trim(add_escape_custom($user_id))  .
        "', closed = '"      . trim(add_escape_custom($closed))  .
        "', reference = '"   . trim(formData('check_number')) .
        "', check_date = '"  . trim(add_escape_custom($check_date)) .
        "', deposit_date = '" . trim(add_escape_custom($deposit_date))  .
        "', pay_total = '"    . trim(formData('payment_amount')) .
        "', modified_time = '" . trim(add_escape_custom($modified_time))  .
        "', payment_type = '"   . trim(formData('type_name')) .
        "', description = '"   . trim(formData('description')) .
        "', adjustment_code = '"   . trim(formData('adjustment_code')) .
        "', post_to_date = '" . trim(add_escape_custom($post_to_date))  .
        "', payment_method = '"   . trim(formData('payment_method')) .
        "'");
}

//===============================================================================
//ar_activity addition code
//===============================================================================
if ($mode == "PostPayments" || $mode == "FinishPayments") {
    header('Content-Type: application/json');

    $rows_inserted = 0;
    $errors        = [];
    $user_id       = $_SESSION['authUserID'];
    $created_time  = date('Y-m-d H:i:s');

    for ($CountRow = 1;; $CountRow++) {
        if (isset($_POST["HiddenEncounter$CountRow"])) {
            // Allow per-row patient override so multi-patient EOBs post correctly.
            // DistributionInsert reads formData('hidden_patient_code') globally,
            // so we override $_POST and $_REQUEST before each call.
            if (!empty($_POST["HiddenPatientCode$CountRow"])) {
                $_POST['hidden_patient_code']    = $_POST["HiddenPatientCode$CountRow"];
                $_REQUEST['hidden_patient_code'] = $_POST["HiddenPatientCode$CountRow"];
            }
            try {
                DistributionInsert($CountRow, $created_time, $user_id);
                $rows_inserted++;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        } else {
            break;
        }
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'error' => implode('; ', $errors), 'rows' => $rows_inserted]);
        exit;
    }

    $sid_fresh   = (int)$payment_id;
    $sidebar_row = null;
    if ($sid_fresh > 0) {
        $sidebar_row = sqlQuery(
            "SELECT s.session_id, s.reference, s.check_date, s.pay_total,
                    s.payment_method, ic.name AS payer_name,
                    COALESCE(SUM(a.pay_amount),0) AS posted_amount,
                    COUNT(DISTINCT a.encounter) AS enc_count
               FROM ar_session s
               LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
               LEFT JOIN ar_activity a ON a.session_id = s.session_id AND a.deleted IS NULL
              WHERE s.session_id = ?
              GROUP BY s.session_id",
            [$sid_fresh]
        );
    }

    if ($mode == "FinishPayments") {
        echo json_encode([
            'success'     => true,
            'rows'        => $rows_inserted,
            'mode'        => 'finish',
            'redirect'    => 'edit_payment.php?payment_id=' . urlencode($payment_id) . '&ParentPage=new_payment',
            'session_id'  => $sid_fresh,
            'sidebar_row' => $sidebar_row,
        ]);
        exit;
    }

    echo json_encode([
        'success'     => true,
        'rows'        => $rows_inserted,
        'mode'        => 'post',
        'session_id'  => $sid_fresh,
        'sidebar_row' => $sidebar_row,
    ]);
    exit;
}

//==============================================================================
//===============================================================================
$payment_id = $payment_id * 1 > 0 ? $payment_id + 0 : $request_payment_id + 0;
//===============================================================================

// ── Load data for new EOB Information form fields ─────────────────────────────

// Load insurance companies for payer dropdown
$insurance_companies_list = [];
$ic_res = sqlStatement(
    "SELECT id, name FROM insurance_companies ORDER BY name LIMIT 300"
);
while ($ic_row = sqlFetchArray($ic_res)) {
    $insurance_companies_list[] = $ic_row;
}

// Load patients for patient dropdown
$patient_list = [];
$pt_res = sqlStatement(
    "SELECT pid, lname, fname FROM patient_data
      WHERE pid > 0
      ORDER BY lname, fname LIMIT 500"
);
while ($pt_row = sqlFetchArray($pt_res)) {
    $patient_list[] = $pt_row;
}

// Load existing payment if payment_id set
$current_payment = [];
$current_charges = [];   // open charges (top grid)
$distributed    = [];   // already distributed (bottom grid)
if ($payment_id > 0) {
    $current_payment = sqlQuery(
        "SELECT s.*, ic.name AS payer_name,
                CONCAT(pd.lname,', ',pd.fname) AS patient_name
           FROM ar_session s
           LEFT JOIN insurance_companies ic ON ic.id = s.payer_id
           LEFT JOIN patient_data pd ON pd.pid = s.patient_id
          WHERE s.session_id = ?",
        [$payment_id]
    );

    // Open charges for the payer
    if (!empty($current_payment['payer_id'])) {
        $charge_res = sqlStatement(
            "SELECT
                fe.date AS dos,
                CONCAT(pd.lname, ', ', pd.fname) AS patient_name,
                b.code,
                b.code_text,
                b.fee AS charge,
                (
                    b.fee
                    - COALESCE(SUM(aa.pay_amount),0)
                    - COALESCE(SUM(aa.adj_amount),0)
                ) AS balance,
                fe.encounter,
                b.id AS billing_id
        
             FROM billing b
        
             JOIN form_encounter fe
               ON fe.encounter = b.encounter
              AND fe.pid = b.pid
        
             JOIN patient_data pd
               ON pd.pid = b.pid
        
             LEFT JOIN ar_activity aa
               ON aa.pid = b.pid
              AND aa.encounter = b.encounter
              AND aa.deleted IS NULL
        
             WHERE b.activity = 1
        
             GROUP BY b.id
        
             HAVING balance > 0
        
             ORDER BY fe.date DESC, pd.lname
        
             LIMIT 100"
        );
        while ($cr = sqlFetchArray($charge_res)) $current_charges[] = $cr;
    }

    // Already distributed under this session
    $dist_res = sqlStatement(
        "SELECT
        fe.date AS dos,
        CONCAT(pd.lname,', ',pd.fname) AS patient_name,
        b.code,
        b.code_text,
        b.fee AS charge,
        aa.pay_amount AS payment,
        aa.adj_amount AS adj_amount
           FROM ar_activity aa
           JOIN form_encounter fe ON fe.encounter = aa.encounter AND fe.pid = aa.pid
           JOIN patient_data pd ON pd.pid = aa.pid
           JOIN billing b
            ON b.encounter = aa.encounter
            AND b.pid = aa.pid
            AND b.activity = 1
          WHERE aa.session_id = ? AND aa.deleted IS NULL
          ORDER BY fe.date DESC",
        [$payment_id]
    );
    while ($dr = sqlFetchArray($dist_res)) $distributed[] = $dr;
}

// Unapplied amount
$unapplied = 0;
if ($payment_id > 0 && !empty($current_payment)) {
    $applied = sqlQuery(
        "SELECT COALESCE(SUM(pay_amount),0) AS total
           FROM ar_activity
          WHERE session_id = ? AND deleted IS NULL",
        [$payment_id]
    );
    $unapplied = (float)($current_payment['pay_total'] ?? 0) - (float)($applied['total'] ?? 0);
}

// Open balance across all charges for the payer
$open_balance = 0;
foreach ($current_charges as $cr) {
    $open_balance += (float)$cr['balance'];
}
$total_distributed = array_sum(array_column($distributed, 'payment'));
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(['common', 'datetime-picker']);?>
    <script>var mypcc = '1';</script>
    <?php include_once("{$GLOBALS['srcdir']}/payment_jav.inc.php"); ?>
    <?php include_once("{$GLOBALS['srcdir']}/ajax/payment_ajax_jav.inc.php"); ?>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <title><?php echo xlt('EOB Workbench'); ?></title>
    <?php
    $arrOeUiSettings = array(
        'heading_title' => xl('EOB Workbench'),
        'include_patient_name' => false,
        'expandable' => true,
        'expandable_files' => array("new_payment_xpd","search_payments_xpd","era_payments_xpd"),
        'action' => "",
        'action_title' => "",
        'action_href' => "",
        'show_help_icon' => false,
        'help_file_name' => ""
    );
    $oemr_ui = new OemrUI($arrOeUiSettings);
    ?>
    <style>
    :root {
        --navy:#1a1f3a; --navy-mid:#2d3461; --purple:#5b4fcf; --purple-lt:#7c6fe0;
        --purple-pale:#f0eeff; --purple-dim:#e8e4ff;
        --green:#0f7b55; --green-bg:#e6f5ef;
        --amber:#b45309; --amber-bg:#fef3c7;
        --red:#b91c1c; --red-bg:#fef2f2;
        --slate:#475569; --slate-lt:#64748b;
        --border:#e2e8f0; --border-mid:#cbd5e1;
        --bg:#f8f9fc; --white:#ffffff;
        --text:#1e293b; --text2:#475569; --text3:#94a3b8;
        --radius:8px; --radius-lg:12px;
        --shadow-sm:0 1px 3px rgba(0,0,0,.07),0 1px 2px rgba(0,0,0,.05);
    }
    *,*::before,*::after{box-sizing:border-box;}
    body{background:var(--bg)!important;color:var(--text);font-family:'DM Sans',sans-serif!important;margin:0;}
    /* Billing Hub nav */
    .bh-nav{background:var(--navy);display:flex;align-items:stretch;border-bottom:3px solid var(--purple);padding:0 20px;overflow-x:auto;}
    .bh-nav a{display:flex;align-items:center;gap:8px;padding:13px 18px;color:rgba(255,255,255,.6);font-size:12.5px;font-weight:500;text-decoration:none!important;border-bottom:3px solid transparent;margin-bottom:-3px;white-space:nowrap;transition:color .15s,border-color .15s;}
    .bh-nav a:hover{color:#fff;}
    .bh-nav a.active{color:#fff;border-bottom-color:var(--purple-lt);font-weight:600;}
    .bh-nav-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.35);padding:14px 10px 14px 0;display:flex;align-items:center;border-right:1px solid rgba(255,255,255,.08);margin-right:8px;}
    /* Layout */
    .wb-wrap{display:flex;align-items:flex-start;}
    .wb-main{flex:1;padding:18px 20px 90px;min-width:0;}
    .wb-sidebar{width:256px;flex-shrink:0;border-left:1px solid var(--border);background:var(--white);padding:14px 12px;position:sticky;top:0;align-self:flex-start;max-height:100vh;overflow-y:auto;}
    /* Cards */
    .wb-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);margin-bottom:14px;}
    .wb-card-head{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border);background:#fafbfc;border-radius:var(--radius-lg) var(--radius-lg) 0 0;}
    .wb-card-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--slate);display:flex;align-items:center;gap:6px;}
    .wb-card-body{padding:14px;}
    /* Header */
    .wb-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:14px;gap:12px;flex-wrap:wrap;}
    .wb-header-left h1{font-size:19px;font-weight:700;color:var(--text);margin:0 0 3px;}
    .wb-header-left p{font-size:11.5px;color:var(--text3);margin:0;}
    .wb-header-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;transition:margin-right .28s cubic-bezier(.4,0,.2,1);}
    #paymentSearchDrawer.open ~ * .wb-header-actions,
    body.search-open .wb-header-actions { margin-right: 328px; }
    /* Buttons */
    .btn-wb{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none!important;transition:all .15s;font-family:'DM Sans',sans-serif;white-space:nowrap;line-height:1.3;}
    .btn-primary-wb{background:var(--purple);color:#fff;border-color:var(--purple);}
    .btn-primary-wb:hover{background:var(--purple-lt);border-color:var(--purple-lt);color:#fff;}
    .btn-outline-wb{background:var(--white);color:var(--slate);border-color:var(--border-mid);}
    .btn-outline-wb:hover{background:var(--purple-pale);color:var(--purple);border-color:var(--purple);}
    .btn-success-wb{background:var(--green);color:#fff;border-color:var(--green);}
    .btn-ghost-wb{background:transparent;color:var(--slate);border:1px solid transparent;}
    .btn-ghost-wb:hover{background:var(--bg);border-color:var(--border);}
    .btn-post{background:linear-gradient(135deg,var(--purple),var(--purple-lt));color:#fff;border:none;padding:9px 22px;font-size:13px;box-shadow:0 2px 8px rgba(91,79,207,.35);}
    .btn-post:hover{box-shadow:0 4px 16px rgba(91,79,207,.45);transform:translateY(-1px);color:#fff;}
    /* Form */
    .wb-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--slate-lt);display:block;margin-bottom:4px;}
    .wb-label .req{color:var(--red);margin-left:2px;}
    .wb-input,.wb-select{width:100%;padding:7px 10px;border:1px solid var(--border-mid);border-radius:6px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--text);background:var(--white);outline:none;transition:border-color .15s,box-shadow .15s;}
    .wb-input:focus,.wb-select:focus{border-color:var(--purple);box-shadow:0 0 0 3px rgba(91,79,207,.12);}
    .wb-input.mono{font-family:'DM Mono',monospace;}
    /* Encounter tabs */
    .enc-tabs{display:flex;gap:3px;}
    .enc-tab{padding:6px 14px;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer;border:1px solid var(--border);background:var(--white);color:var(--slate);transition:all .15s;font-family:'DM Sans',sans-serif;}
    .enc-tab.active{background:var(--purple-pale);color:var(--purple);border-color:var(--purple);}
    .enc-tab:hover:not(.active){background:var(--bg);}
    /* EOB table */
    .eob-table-wrap{overflow-x:auto;}
    .eob-table{width:100%;border-collapse:collapse;font-size:11.5px;min-width:1000px;}
    .eob-table thead th{background:var(--navy);color:rgba(255,255,255,.85);padding:8px 7px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;text-align:center;white-space:nowrap;border-right:1px solid rgba(255,255,255,.08);}
    .eob-table thead th:first-child{text-align:left;}
    .eob-table thead th:last-child{border-right:none;}
    .eob-table tbody tr{border-bottom:1px solid var(--border);}
    .eob-table tbody tr:hover td{background:var(--purple-pale);}
    .eob-table td{padding:5px 6px;vertical-align:middle;}
    .td-mono{font-family:'DM Mono',monospace;font-size:11px;text-align:right;}
    .td-cpt{font-family:'DM Mono',monospace;font-weight:600;color:var(--navy-mid);}
    .td-charge{font-family:'DM Mono',monospace;font-weight:600;text-align:right;}
    .amt-input{width:68px;padding:4px 5px;text-align:right;border:1px solid var(--border-mid);border-radius:5px;font-family:'DM Mono',monospace;font-size:11.5px;color:var(--text);background:#fff;outline:none;transition:border-color .12s;}
    .amt-input:focus{border-color:var(--purple);box-shadow:0 0 0 2px rgba(91,79,207,.15);}
    .remark-sel{width:76px;padding:4px;font-size:11px;border:1px solid var(--border-mid);border-radius:5px;background:#fff;}
    .eob-table tfoot td{font-weight:700;font-size:11.5px;padding:7px 6px;border-top:2px solid var(--navy);font-family:'DM Mono',monospace;text-align:right;background:#f8f9fc;color:var(--navy);}
    .eob-table tfoot td:first-child{text-align:left;font-family:'DM Sans',sans-serif;font-size:11px;color:var(--slate);font-weight:600;}
    .del-btn{background:none;border:none;color:var(--red);cursor:pointer;padding:2px 5px;border-radius:4px;}
    .del-btn:hover{background:var(--red-bg);}
    /* Remark/accounting/summary */
    .remark-tbl{width:100%;font-size:11.5px;border-collapse:collapse;}
    .remark-tbl td{padding:5px 8px;border-bottom:1px solid var(--border);vertical-align:top;}
    .remark-tbl td:first-child{font-family:'DM Mono',monospace;font-weight:600;color:var(--purple);width:56px;}
    .acct-tbl{width:100%;font-size:11.5px;border-collapse:collapse;}
    .acct-tbl tr{border-bottom:1px solid var(--border);}
    .acct-tbl td{padding:5px 4px;}
    .acct-tbl td:nth-child(2){text-align:center;color:var(--text3);}
    .acct-tbl td:last-child{text-align:right;font-family:'DM Mono',monospace;font-weight:600;}
    .acct-pay{color:var(--green)!important;} .acct-adj{color:var(--amber)!important;} .acct-pt{color:var(--purple)!important;font-weight:700!important;}
    .summary-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;border-bottom:1px solid var(--border);font-size:12px;}
    .summary-row:last-child{border-bottom:none;}
    .summary-row .s-val{font-family:'DM Mono',monospace;font-weight:600;}
    /* Sidebar */
    .sb-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--slate);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;}
    .sb-title a{font-size:10px;color:var(--purple);text-transform:none;letter-spacing:0;font-weight:600;text-decoration:none;}
    .eob-hist-item{padding:9px 0;border-bottom:1px solid var(--border);font-size:11px;}
    .eob-hist-item:last-child{border-bottom:none;}
    .eob-hist-date{font-size:10px;color:var(--text3);margin-bottom:2px;}
    .eob-hist-payer{font-weight:600;color:var(--text);}
    .eob-hist-ref{color:var(--text2);font-size:10.5px;font-family:'DM Mono',monospace;}
    .eob-hist-amount{font-family:'DM Mono',monospace;font-weight:600;font-size:12px;margin-top:2px;}
    .eob-hist-status{display:inline-flex;align-items:center;gap:4px;font-size:9.5px;font-weight:600;padding:2px 7px;border-radius:20px;float:right;margin-top:2px;}
    .eob-hist-status.posted{background:var(--green-bg);color:var(--green);}
    .eob-hist-status.draft{background:var(--amber-bg);color:var(--amber);}
    .sb-qa-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--slate);margin:16px 0 8px;}
    .qa-item{display:flex;align-items:center;gap:8px;padding:7px 0;border-bottom:1px solid var(--border);font-size:12px;color:var(--text2);cursor:pointer;text-decoration:none!important;transition:color .12s;}
    .qa-item:hover{color:var(--purple);}
    .qa-item:last-child{border-bottom:none;}
    /* Footer */
    .wb-footer{position:sticky;bottom:0;background:var(--white);border-top:1px solid var(--border);padding:11px 20px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 -4px 16px rgba(0,0,0,.06);z-index:100;gap:10px;}
    /* Drawer */
    #paymentSearchDrawer{position:fixed;top:0;right:-340px;width:320px;height:100%;background:var(--white);box-shadow:-8px 0 40px rgba(0,0,0,.16);transition:right .28s cubic-bezier(.4,0,.2,1);z-index:9999;display:flex;flex-direction:column;border-left:1px solid var(--border);}
    #paymentSearchDrawer.open{right:0;}
    /* Search drawer stays open — no overlay, pin button shows it's docked */
    #paymentSearchDrawer .drawer-pin-bar{
        display:flex;align-items:center;justify-content:space-between;
        padding:6px 14px;background:var(--accent-light,#f3f0ff);
        border-bottom:1px solid var(--border);font-size:11px;color:var(--text2);
    }
    .drawer-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border);background:var(--navy);color:#fff;flex-shrink:0;}
    .drawer-head h5{margin:0;font-size:14px;font-weight:600;color:#fff;}
    .drawer-close{background:none;border:none;color:rgba(255,255,255,.6);font-size:20px;cursor:pointer;line-height:1;padding:2px 6px;}
    .drawer-close:hover{color:#fff;}
    .drawer-body{flex:1;overflow-y:auto;padding:16px;}
    .drawer-result-item{padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;cursor:pointer;transition:all .15s;}
    .drawer-result-item:hover{border-color:var(--purple);background:var(--purple-pale);}
    .dr-payer{font-weight:600;font-size:12.5px;}
    .dr-ref{font-family:'DM Mono',monospace;font-size:11px;color:var(--text2);margin-top:2px;}
    .dr-amount{font-family:'DM Mono',monospace;font-weight:700;font-size:13px;color:var(--green);float:right;}
    #drawerOverlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.3);z-index:9998;}
    #drawerOverlay.open{display:block;}
    /* ── Statements / Invoice Search Drawer ───────────────────────────────────── */
    #statementsDrawer{position:fixed;top:0;right:-640px;width:620px;height:100vh;
        background:var(--white);box-shadow:-4px 0 24px rgba(0,0,0,.15);z-index:9999;
        transition:right .28s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;}
    #statementsDrawer.open{right:0;}
    #statementsDrawer .stmt-drawer-hd{padding:14px 18px;border-bottom:1px solid var(--border);
        display:flex;align-items:center;justify-content:space-between;background:var(--navy);color:#fff;flex-shrink:0;}
    #statementsDrawer .stmt-drawer-hd h3{margin:0;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
    #statementsDrawer .stmt-drawer-body{flex:1;overflow-y:auto;}
    .stmt-search-bar{padding:14px 18px;border-bottom:1px solid var(--border);background:#fafbfc;}
    .stmt-search-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:8px;}
    .stmt-search-row2{display:grid;grid-template-columns:1fr 1fr auto;gap:8px;align-items:end;}
    .stmt-lbl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:3px;display:block;}
    .stmt-inp{width:100%;padding:6px 9px;border:1px solid var(--border);border-radius:5px;font-size:12px;font-family:inherit;box-sizing:border-box;}
    .stmt-inp:focus{border-color:var(--purple);outline:none;}
    .stmt-search-btn{padding:6px 16px;background:var(--purple);color:#fff;border:none;border-radius:5px;font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;}
    .stmt-search-btn:hover{filter:brightness(1.1);}
    #stmtResultsInfo{font-size:11px;color:var(--text3);padding:8px 18px 0;}
    #stmtResultsTable{width:100%;border-collapse:collapse;font-size:11.5px;}
    #stmtResultsTable thead th{position:sticky;top:0;background:var(--navy);color:rgba(255,255,255,.85);
        padding:7px 10px;font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;text-align:left;}
    #stmtResultsTable thead th.r{text-align:right;}
    #stmtResultsTable tbody tr:hover td{background:var(--purple-pale);}
    #stmtResultsTable tbody tr{border-bottom:1px solid var(--border);}
    #stmtResultsTable td{padding:6px 10px;vertical-align:middle;}
    #stmtResultsTable td.r{text-align:right;font-family:'DM Mono',monospace;font-size:11px;}
    #stmtResultsTable td.bal-pos{color:var(--amber);font-weight:700;}
    #stmtResultsTable td.bal-zero{color:var(--green);}
    .stmt-inv-link{color:var(--purple);font-weight:700;text-decoration:none;font-family:'DM Mono',monospace;font-size:11px;cursor:pointer;}
    .stmt-inv-link:hover{text-decoration:underline;}
    .stmt-empty{padding:32px;text-align:center;color:var(--text3);font-size:12px;}
    /* ── ERA Import Drawer ───────────────────────────────────────────────────── */
    #eraImportDrawer{position:fixed;top:0;right:-500px;width:480px;height:100vh;
        background:var(--white);box-shadow:-4px 0 24px rgba(0,0,0,.15);z-index:9999;
        transition:right .28s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;overflow:hidden;}
    #eraImportDrawer.open{right:0;}
    #eraImportDrawer .era-drawer-hd{padding:14px 18px;border-bottom:1px solid var(--border);
        display:flex;align-items:center;justify-content:space-between;background:var(--navy);color:#fff;flex-shrink:0;}
    #eraImportDrawer .era-drawer-hd h3{margin:0;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
    #eraImportDrawer .era-drawer-body{flex:1;overflow-y:auto;padding:18px;}
    .era-lbl{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text2);margin-bottom:4px;display:block;margin-top:12px;}
    .era-lbl:first-child{margin-top:0;}
    .era-inp{width:100%;padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:12.5px;box-sizing:border-box;font-family:inherit;}
    .era-inp:focus{border-color:var(--purple);outline:none;box-shadow:0 0 0 2px var(--purple-pale);}
    .era-2col{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
    #eraDropZone{border:2px dashed var(--border-mid);border-radius:8px;padding:26px 16px;text-align:center;
        cursor:pointer;transition:border-color .18s,background .18s;background:#fafbfc;margin-bottom:14px;}
    #eraDropZone:hover,#eraDropZone.dragover{border-color:var(--purple);background:var(--purple-pale);}
    #eraDropZone .dz-icon{display:block;margin:0 auto 10px;opacity:.35;}
    #eraDropZone .dz-label{font-size:12px;color:var(--text2);font-weight:600;}
    #eraDropZone .dz-sub{font-size:11px;color:var(--text3);margin-top:3px;}
    #eraFileChosen{font-size:11px;color:var(--green);font-weight:700;margin-top:8px;display:none;
        padding:5px 10px;background:#d1fae5;border-radius:5px;border:1px solid #6ee7b7;}
    #eraDateBanner{display:none;background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;
        padding:8px 12px;font-size:11px;margin-bottom:12px;color:#065f46;}
    .era-process-btn{width:100%;padding:10px;background:var(--purple);color:#fff;border:none;border-radius:7px;
        font-size:13px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;margin-top:18px;}
    .era-process-btn:hover{filter:brightness(1.1);}
    .era-process-btn:disabled{opacity:.55;cursor:not-allowed;}
    @keyframes spin{to{transform:rotate(360deg);}}
    /* ── 3-panel EOB workspace ─────────────────────────────────────── */
    .eob-panels{display:grid;grid-template-columns:1fr 1fr 1fr;gap:0;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;background:var(--white);}
    .eob-panel{display:flex;flex-direction:column;min-width:0;}
    .eob-panel+.eob-panel{border-left:1px solid var(--border);}
    .eob-panel-head{padding:9px 12px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);flex-shrink:0;}
    .eob-panel-head.open-hd  {background:#f0f4ff;}
    .eob-panel-head.applied-hd{background:#edfaf4;}
    .eob-panel-head.history-hd{background:#fdf6ee;}
    .eob-panel-title{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;display:flex;align-items:center;gap:6px;}
    .eob-panel-title.open-t   {color:var(--navy-mid);}
    .eob-panel-title.applied-t{color:var(--green);}
    .eob-panel-title.history-t{color:var(--amber);}
    .eob-panel-badge{font-size:9.5px;font-weight:700;padding:1px 7px;border-radius:20px;}
    .eob-panel-badge.open-b   {background:var(--purple-dim);color:var(--purple);}
    .eob-panel-badge.applied-b{background:var(--green-bg);color:var(--green);}
    .eob-panel-badge.history-b{background:var(--amber-bg);color:var(--amber);}
    .eob-panel-body{overflow-y:auto;max-height:340px;flex:1;}
    .eob-mini-tbl{width:100%;border-collapse:collapse;font-size:11px;}
    .eob-mini-tbl thead th{position:sticky;top:0;background:var(--navy);color:rgba(255,255,255,.8);padding:5px 8px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;text-align:left;}
    .eob-mini-tbl thead th.r{text-align:right;}
    .eob-mini-tbl tbody tr{border-bottom:1px solid var(--border);}
    .eob-mini-tbl tbody tr:hover td{background:var(--purple-pale);}
    .eob-mini-tbl td{padding:5px 8px;vertical-align:middle;}
    .eob-mini-tbl td.r{text-align:right;font-family:'DM Mono',monospace;font-size:10.5px;}
    .eob-mini-tbl td.cpt{font-family:'DM Mono',monospace;font-weight:600;color:var(--navy-mid);font-size:10.5px;}
    .eob-mini-tbl td.dos{font-family:'DM Mono',monospace;font-size:10px;color:var(--purple);white-space:nowrap;}
    .eob-panel-empty{padding:28px 12px;text-align:center;color:var(--text3);font-size:11px;}
    .eob-panel-total{padding:7px 12px;border-top:2px solid var(--border);background:#fafbfc;display:flex;align-items:center;justify-content:space-between;font-size:10.5px;font-weight:700;font-family:'DM Mono',monospace;flex-shrink:0;}
    .eob-ws-idle{padding:36px;text-align:center;color:var(--text3);font-size:12px;border:1px solid var(--border);border-radius:var(--radius-lg);background:var(--white);}
    .eob-hist-status.partial{
    background:#fef3c7;
    color:#92400e;
}
    /* highlight active EOB in sidebar */
    .eob-hist-item.active-eob{background:var(--purple-pale);border-left:3px solid var(--purple);padding-left:8px;border-radius:6px;}
    /* ── Sample EOB test banner ──────────────────────────────────────────────── */
    #sampleBanner{background:linear-gradient(135deg,#1a1f3a,#2d3461);color:#fff;border-radius:10px;
        padding:16px 20px;margin-bottom:14px;display:none;position:relative;}
    #sampleBanner h4{margin:0 0 8px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
    #sampleBanner .sb-badge{background:var(--purple);color:#fff;font-size:9px;font-weight:700;
        text-transform:uppercase;letter-spacing:.08em;padding:2px 8px;border-radius:20px;}
    #sampleBanner table{width:100%;border-collapse:collapse;font-size:11.5px;margin-top:10px;}
    #sampleBanner th{text-align:left;padding:5px 10px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.6);
        font-size:9.5px;text-transform:uppercase;letter-spacing:.05em;font-weight:700;}
    #sampleBanner td{padding:6px 10px;border-bottom:1px solid rgba(255,255,255,.07);color:#e2e8f0;font-family:'DM Mono',monospace;font-size:11px;}
    #sampleBanner .sb-close{position:absolute;top:10px;right:12px;background:none;border:none;color:rgba(255,255,255,.5);
        font-size:18px;cursor:pointer;line-height:1;padding:2px 6px;}
    #sampleBanner .sb-close:hover{color:#fff;}
    #sampleBanner .sb-step{display:flex;align-items:flex-start;gap:8px;margin-top:8px;font-size:12px;
        color:rgba(255,255,255,.8);line-height:1.5;}
    #sampleBanner .sb-num{background:var(--purple);color:#fff;border-radius:50%;width:18px;height:18px;
        display:inline-flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0;margin-top:1px;}
    /* ── Post toast ──────────────────────────────────────────────────────────── */
    #postToast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(20px);
        background:var(--navy);color:#fff;padding:13px 22px;border-radius:10px;font-size:13px;font-weight:600;
        box-shadow:0 4px 24px rgba(0,0,0,.25);display:flex;align-items:center;gap:10px;
        opacity:0;transition:opacity .25s,transform .25s;z-index:9999;pointer-events:none;white-space:nowrap;}
    #postToast.show{opacity:1;transform:translateX(-50%) translateY(0);}
    #postToast.success{background:var(--green);}
    #postToast.error{background:var(--red);}
    .toast-icon{width:18px;height:18px;flex-shrink:0;}
    /* ── Patient mode: hide insurance-only alloc columns ────────────────────── */
    .pat-mode [data-ins-only="1"] { display:none; }
    /* Legacy compat */
    .class1{width:125px;} .amt_input{max-width:75px;}
    #container_div{padding:0!important;max-width:none!important;}
    </style>
</head>
<body onload="OnloadAction()">
<!-- Billing Hub Nav -->
<nav class="bh-nav">
    <span class="bh-nav-label">Billing Hub</span>
    <a href="new_payment.php" class="active">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/><path d="M14 2v6h6"/></svg>
        <?php echo xlt('EOB Workbench'); ?>
    </a>
    <a href="#" onclick="toggleEraDrawer();return false;">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
        <?php echo xlt('ERA'); ?>
    </a>
    <a href="#" onclick="toggleStatementsDrawer();return false;">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
        <?php echo xlt('Statements'); ?>
    </a>
    <a href="legacy_eob_posting.php">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
        <?php echo xlt('Legacy EOB Posting'); ?>
    </a>
</nav>
<div id="container_div">
<form action="new_payment.php" id="new_payment" method="post" name="new_payment"
    onsubmit="<?php echo $payment_id*1==0 ? 'top.restoreSession();' : 'return false;'; ?>" style="display:inline">

<div class="wb-wrap">
<!-- Main -->
<div class="wb-main">
    <!-- Header -->
    <div class="wb-header">
        <div class="wb-header-left">
            <h1><?php echo xlt('EOB Workbench'); ?></h1>
            <p><?php echo xlt('Post insurance EOBs, adjustments and non-payments in one step.'); ?></p>
        </div>
        <div class="wb-header-actions">
            <button type="button" class="btn-wb btn-outline-wb" onclick="togglePaymentSearch()">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <?php echo xlt('Search Payments'); ?>
            </button>
            <button type="button" id="btnImportEra" class="btn-wb btn-outline-wb" onclick="toggleEraDrawer()">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                <?php echo xlt('Import ERA File'); ?>
            </button>
            <button type="button" id="btnNewEob" class="btn-wb btn-outline-wb" onclick="createNewEob()" style="border-color:var(--green);color:var(--green);">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                <?php echo xlt('New EOB'); ?>
            </button>
            <button type="button" class="btn-wb btn-primary-wb" onclick="return PostPayments();">
                <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
                <?php echo xlt('Save EOB Draft'); ?>
            </button>
        </div>
    </div>

    <!-- Sample EOB test banner -->
    <div id="sampleBanner" style="display:none;">
        <button class="sb-close" onclick="document.getElementById('sampleBanner').style.display='none'">×</button>
        <h4>
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            <?php echo xlt("Sample EOB — Test Data"); ?>
            <span class="sb-badge"><?php echo xlt("Testing"); ?></span>
        </h4>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:4px;">

            <!-- Scenario A: Payment only -->
            <div>
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.5);margin-bottom:8px;"><?php echo xlt("Scenario A — Payment + Write-off"); ?></div>
                <table>
                    <thead><tr><th><?php echo xlt("Field"); ?></th><th><?php echo xlt("Value"); ?></th><th><?php echo xlt("Posts as"); ?></th></tr></thead>
                    <tbody>
                        <tr><td><?php echo xlt("Carrier"); ?></td><td style="color:#e2e8f0;">Medicare Part B</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("Select from dropdown"); ?></td></tr>
                        <tr><td><?php echo xlt("Check #"); ?></td><td style="color:#e2e8f0;">TEST-A-001</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("ar_session.reference"); ?></td></tr>
                        <tr><td><?php echo xlt("Amount"); ?></td><td style="color:#86efac;">$21.71</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("ar_session.pay_total"); ?></td></tr>
                        <tr><td>Payment</td><td style="color:#86efac;">21.71</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("pay_amount, account_code: IPP"); ?></td></tr>
                        <tr><td>Adj</td><td style="color:#fde68a;">33.29</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("adj_amount, account_code: IA"); ?></td></tr>
                        <tr><td>Ded</td><td style="color:rgba(255,255,255,.4);">0</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("Leave blank"); ?></td></tr>
                    </tbody>
                </table>
            </div>

            <!-- Scenario B: Payment + Deductible -->
            <div>
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,.5);margin-bottom:8px;"><?php echo xlt("Scenario B — Payment + Deductible"); ?></div>
                <table>
                    <thead><tr><th><?php echo xlt("Field"); ?></th><th><?php echo xlt("Value"); ?></th><th><?php echo xlt("Posts as"); ?></th></tr></thead>
                    <tbody>
                        <tr><td><?php echo xlt("Carrier"); ?></td><td style="color:#e2e8f0;">Medicare Part B</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("Select from dropdown"); ?></td></tr>
                        <tr><td><?php echo xlt("Check #"); ?></td><td style="color:#e2e8f0;">TEST-B-001</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("ar_session.reference"); ?></td></tr>
                        <tr><td><?php echo xlt("Amount"); ?></td><td style="color:#86efac;">$29.13</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("ar_session.pay_total"); ?></td></tr>
                        <tr><td>Payment</td><td style="color:#86efac;">29.13</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("pay_amount, account_code: IPP"); ?></td></tr>
                        <tr><td>Adj</td><td style="color:#fde68a;">17.58</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("adj_amount, account_code: IA"); ?></td></tr>
                        <tr><td>Ded</td><td style="color:#a5b4fc;">8.29</td><td style="color:rgba(255,255,255,.45);"><?php echo xlt("separate row, account_code: Deduct"); ?></td></tr>
                    </tbody>
                </table>
            </div>

        </div>
        <div class="sb-step" style="margin-top:12px;border-top:1px solid rgba(255,255,255,.1);padding-top:10px;">
            <span class="sb-num">→</span>
            <span><?php echo xlt("Pick any Draft EOB in the sidebar → charges load → enter values above → Post EOB. The Applied panel shows each row separately with CPT, Pmt, Adj, Ded, Seq. The Posted History panel shows prior sessions. Green toast confirms success."); ?></span>
        </div>
    </div>

    <!-- §1 PAYMENT INFORMATION -->
    <div class="wb-card" id="sectionPayment">
        <div class="wb-card-head">
            <div class="wb-card-title">
                <span style="background:var(--purple);color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;">1</span>
                <?php echo strtoupper(xlt('Payment / EOB Header')); ?>
            </div>
            <?php if ($payment_id > 0 && $unapplied != 0): ?>
            <div style="background:<?php echo $unapplied>0?'linear-gradient(135deg,#e8f5e9,#d1fae5)':'linear-gradient(135deg,#fef2f2,#fee2e2)';?>;border:1px solid <?php echo $unapplied>0?'#a7f3d0':'#fca5a5';?>;border-radius:7px;padding:5px 12px;">
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:<?php echo $unapplied>0?'var(--green)':'var(--red)';?>;"><?php echo xlt('Unapplied'); ?></div>
                <div style="font-size:16px;font-weight:700;font-family:'DM Mono',monospace;color:<?php echo $unapplied>0?'var(--green)':'var(--red)';?>;">$<?php echo number_format($unapplied,2); ?></div>
            </div>
            <?php endif; ?>
        </div>
        <div class="wb-card-body" style="padding:18px 20px;">
        <?php
        $PaymentType    = $current_payment['payment_type']   ?? 'insurance';
        $TypeCode       = $current_payment['payer_id']       ?? '';
        $check_number   = $current_payment['reference']      ?? '';
        $payment_amount = $current_payment['pay_total']      ?? '';
        $check_date_val = !empty($current_payment['check_date'])    ? date('m/d/Y',strtotime($current_payment['check_date']))    : date('m/d/Y');
        $post_date_val  = !empty($current_payment['post_to_date'])  ? date('m/d/Y',strtotime($current_payment['post_to_date']))  : date('m/d/Y');
        $pay_method     = $current_payment['payment_method'] ?? 'check';
        $cur_patient_id = $current_payment['patient_id']     ?? '';
        ?>

        <!-- Source row -->
        <div style="display:flex;align-items:center;gap:20px;margin-bottom:20px;flex-wrap:wrap;">
            <div>
                <div class="wb-label" style="margin-bottom:8px;"><?php echo xlt('Source'); ?> <span class="req">*</span></div>
                <div style="display:flex;gap:6px;">
                    <label id="lbl_ins" style="display:flex;align-items:center;gap:8px;padding:7px 16px;border:2px solid <?php echo $PaymentType==='insurance'?'var(--purple)':'var(--border-mid)';?>;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;background:<?php echo $PaymentType==='insurance'?'var(--purple-pale)':'var(--white)';?>;color:<?php echo $PaymentType==='insurance'?'var(--purple)':'var(--text2)';?>;transition:all .15s;">
                        <input type="radio" name="type_name" value="insurance" id="rb_insurance" onchange="sourceChanged('insurance')" <?php echo $PaymentType==='insurance'?'checked':'';?> style="accent-color:var(--purple);">
                        <?php echo xlt('Insurance'); ?>
                    </label>
                    <label id="lbl_pat" style="display:flex;align-items:center;gap:8px;padding:7px 16px;border:2px solid <?php echo $PaymentType==='patient'?'var(--purple)':'var(--border-mid)';?>;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;background:<?php echo $PaymentType==='patient'?'var(--purple-pale)':'var(--white)';?>;color:<?php echo $PaymentType==='patient'?'var(--purple)':'var(--text2)';?>;transition:all .15s;">
                        <input type="radio" name="type_name" value="patient" id="rb_patient" onchange="sourceChanged('patient')" <?php echo $PaymentType==='patient'?'checked':'';?> style="accent-color:var(--purple);">
                        <?php echo xlt('Patient'); ?>
                    </label>
                </div>
            </div>

            <!-- Insurance: Carrier + Amount row -->
            <div id="ins_fields" style="display:<?php echo $PaymentType==='insurance'?'flex':'none';?>;align-items:flex-end;gap:14px;flex:1;flex-wrap:wrap;">
                <div style="flex:2;min-width:180px;position:relative;">
                    <label class="wb-label"><?php echo xlt('Carrier'); ?> <span class="req">*</span></label>
                    <select class="wb-select" name="hidden_type_code" id="hidden_type_code_sel"
                        onchange="carrierChanged(this.value)">
                        <option value=""><?php echo xlt('— Select carrier —'); ?></option>
                        <?php foreach ($insurance_companies_list as $ic): ?>
                        <option value="<?php echo attr($ic['id']); ?>" <?php echo $TypeCode==$ic['id']?'selected':''; ?>><?php echo text($ic['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="flex:1;min-width:130px;">
                    <label class="wb-label"><?php echo xlt('Amount'); ?> <span class="req">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);font-weight:600;font-size:14px;">$</span>
                        <input type="text" class="wb-input mono" name="payment_amount" id="payment_amount"
                            value="<?php echo attr($payment_amount); ?>"
                            style="padding-left:22px;" oninput="recalc()" placeholder="0.00">
                    </div>
                </div>
            </div>

            <!-- Patient: Patient search + Amount row -->
            <div id="pat_fields" style="display:<?php echo $PaymentType==='patient'?'flex':'none';?>;align-items:flex-end;gap:14px;flex:1;flex-wrap:wrap;">
                <div style="flex:2;min-width:200px;position:relative;">
                    <label class="wb-label"><?php echo xlt('Patient'); ?> <span class="req">*</span></label>
                    <div style="position:relative;">
                        <input type="text" class="wb-input" id="ptSearchInput2"
                            placeholder="<?php echo xla('Search by name or DOB...'); ?>"
                            autocomplete="off"
                            oninput="ptSearchDebounce2(this.value)"
                            onfocus="if(this.value.length>=2)showPtResults2()"
                            value="<?php echo attr(!empty($current_payment['patient_name'])?strtoupper($current_payment['patient_name']):''); ?>"
                            style="padding-right:32px;">
                        <?php if (!empty($cur_patient_id)): ?>
                        <button type="button" onclick="clearPatient()" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--text3);cursor:pointer;font-size:16px;line-height:1;padding:0;">×</button>
                        <?php endif; ?>
                        <input type="hidden" id="hidden_patient_code" name="hidden_patient_code" value="<?php echo attr($cur_patient_id); ?>">
                        <input type="hidden" id="selectedPid" name="selected_pid" value="<?php echo attr($cur_patient_id); ?>">
                        <div id="ptDropdown2" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid var(--border-mid);border-top:none;border-radius:0 0 7px 7px;box-shadow:0 6px 20px rgba(0,0,0,.12);z-index:8000;max-height:200px;overflow-y:auto;"></div>
                    </div>
                </div>
                <div style="flex:1;min-width:130px;">
                    <label class="wb-label"><?php echo xlt('Amount'); ?> <span class="req">*</span></label>
                    <div style="position:relative;">
                        <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);font-weight:600;font-size:14px;">$</span>
                        <input type="text" class="wb-input mono" id="payment_amount_pat"
                            value="<?php echo attr($payment_amount); ?>"
                            style="padding-left:22px;" oninput="syncAmt(this)" placeholder="0.00">
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Insurance fields (check/eft, date, method, date paid) -->
        <div id="ins_row2" style="display:<?php echo $PaymentType==='insurance'?'grid':'none';?>;grid-template-columns:1.2fr 1fr 1fr 1fr;gap:14px;margin-bottom:16px;">
            <div>
                <label class="wb-label" id="check_label"><?php echo xlt('Check / EFT #'); ?></label>
                <input type="text" class="wb-input mono" name="check_number" id="check_number"
                    value="<?php echo attr($check_number); ?>"
                    placeholder="<?php echo xla('Check or EFT number'); ?>">
            </div>
            <div>
                <label class="wb-label"><?php echo xlt('Check Date'); ?> <span class="req">*</span></label>
                <input type="text" class="wb-input datepicker" name="check_date" id="check_date"
                    value="<?php echo attr($check_date_val); ?>">
            </div>
            <div>
                <label class="wb-label"><?php echo xlt('Method'); ?> <span class="req">*</span></label>
                <select class="wb-select" name="payment_method" id="payment_method" onchange="methodChanged(this.value)">
                    <option value="check"       <?php echo $pay_method==='check'?'selected':''; ?>><?php echo xlt('Check'); ?></option>
                    <option value="eft"         <?php echo $pay_method==='eft'?'selected':''; ?>><?php echo xlt('EFT'); ?></option>
                    <option value="eob"         <?php echo $pay_method==='eob'?'selected':''; ?>><?php echo xlt('EOB'); ?></option>
                    <option value="cash"        <?php echo $pay_method==='cash'?'selected':''; ?>><?php echo xlt('Cash'); ?></option>
                    <option value="credit_card" <?php echo $pay_method==='credit_card'?'selected':''; ?>><?php echo xlt('Credit Card'); ?></option>
                    <option value="other"       <?php echo $pay_method==='other'?'selected':''; ?>><?php echo xlt('Other'); ?></option>
                </select>
            </div>
            <div>
                <label class="wb-label"><?php echo xlt('Date Paid'); ?> <span class="req">*</span></label>
                <input type="text" class="wb-input datepicker" name="post_to_date" id="post_to_date"
                    value="<?php echo attr($post_date_val); ?>">
            </div>
        </div>

        <!-- Row 2: Patient fields (method, date paid, check/ref) -->
        <div id="pat_row2" style="display:<?php echo $PaymentType==='patient'?'grid':'none';?>;grid-template-columns:1fr 1fr 1.2fr;gap:14px;margin-bottom:16px;">
            <div>
                <label class="wb-label"><?php echo xlt('Payment Method'); ?> <span class="req">*</span></label>
                <select class="wb-select" name="payment_method_pat" id="payment_method_pat" onchange="methodChanged(this.value)">
                    <option value="check"       <?php echo $pay_method==='check'?'selected':''; ?>><?php echo xlt('Check'); ?></option>
                    <option value="eft"         <?php echo $pay_method==='eft'?'selected':''; ?>><?php echo xlt('EFT'); ?></option>
                    <option value="eob"         <?php echo $pay_method==='eob'?'selected':''; ?>><?php echo xlt('EOB'); ?></option>
                    <option value="cash"        <?php echo $pay_method==='cash'?'selected':''; ?>><?php echo xlt('Cash'); ?></option>
                    <option value="credit_card" <?php echo $pay_method==='credit_card'?'selected':''; ?>><?php echo xlt('Credit Card'); ?></option>
                    <option value="other"       <?php echo $pay_method==='other'?'selected':''; ?>><?php echo xlt('Other'); ?></option>
                </select>
            </div>
            <div>
                <label class="wb-label"><?php echo xlt('Date Paid'); ?> <span class="req">*</span></label>
                <input type="text" class="wb-input datepicker" name="post_to_date_pat" id="post_to_date_pat"
                    value="<?php echo attr($post_date_val); ?>">
            </div>
            <div>
                <label class="wb-label"><?php echo xlt('Check / Reference #'); ?></label>
                <input type="text" class="wb-input mono" name="check_number_pat" id="check_number_pat"
                    value="<?php echo attr($check_number); ?>"
                    placeholder="<?php echo xla('Optional reference number'); ?>">
            </div>
        </div>

        <!-- Notes toggle -->
        <div style="display:flex;align-items:center;gap:8px;border-top:1px solid var(--border);padding-top:12px;">
            <button type="button" class="btn-wb btn-ghost-wb" onclick="toggleNotes()" style="font-size:11px;padding:5px 10px;">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/><path d="M14 2v6h6"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <?php echo xlt('Notes'); ?>
            </button>
            <?php if ($payment_id > 0): ?>
            <span style="font-size:11px;color:var(--text3);"><?php echo xlt('Payment ID:'); ?> <strong><?php echo text($payment_id); ?></strong></span>
            <?php endif; ?>
        </div>
        <div id="notesSection" style="display:none;margin-top:12px;">
            <textarea class="wb-input" name="description" id="description" rows="2" style="resize:vertical;"><?php echo text($current_payment['description'] ?? ''); ?></textarea>
        </div>

        <!-- Hidden legacy fields -->
        <input type="hidden" name="hidden_type_code" id="hidden_type_code" value="<?php echo attr($TypeCode); ?>">
        <input type="hidden" name="mode" id="mode_field" value="new_payment">
        <input type="hidden" name="deposit_date" id="deposit_date" value="<?php echo attr($post_date_val); ?>">
        <input type="hidden" name="payment_id" id="payment_id" value="<?php echo attr($payment_id); ?>">
        <input type="hidden" name="global_amount" id="global_amount" value="no">
        </div>
    </div>

    <!-- §2 ALLOCATION ENTRY -->
    <div class="wb-card" id="sectionCharges">
        <div class="wb-card-head">
            <div class="wb-card-title" style="gap:8px;">
                <span style="background:var(--purple);color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;">2</span>
                <?php echo strtoupper(xlt('Allocation Entry')); ?>
                <span id="chargesSubtitle" style="font-size:10px;color:var(--text3);font-weight:400;text-transform:none;letter-spacing:0;"><?php echo xlt('Select a carrier or patient above to load charges.'); ?></span>
            </div>
            <div style="display:flex;gap:10px;align-items:center;">
                <div style="text-align:right;">
                    <div style="font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('Open Balance'); ?></div>
                    <div style="font-family:'DM Mono',monospace;font-weight:700;font-size:13px;" id="chgTotalCharge">$0.00</div>
                </div>
                <div style="text-align:right;padding:0 10px;border-left:1px solid var(--border);border-right:1px solid var(--border);">
                    <div id="labelThisEob" style="font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('This EOB'); ?></div>
                    <div style="font-family:'DM Mono',monospace;font-weight:700;font-size:13px;color:var(--green);" id="chgTotalPaid">$0.00</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:9px;color:var(--text3);text-transform:uppercase;letter-spacing:.06em;"><?php echo xlt('Prior Posted'); ?></div>
                    <div style="font-family:'DM Mono',monospace;font-weight:700;font-size:13px;color:var(--amber);" id="chgTotalBalance">$0.00</div>
                </div>
            </div>
        </div>
        <div class="wb-card-body" style="padding:12px;">

            <!-- Idle state: no carrier/patient selected yet -->
            <div id="eobWorkspaceIdle" class="eob-ws-idle">
                <svg width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.2" viewBox="0 0 24 24" style="display:block;margin:0 auto 10px;opacity:.3;"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                <?php echo xlt('Select a carrier or patient in Step 1 to load open charges.'); ?>
            </div>

            <!-- Active EOB banner (shown when a specific EOB session is loaded) -->
            <div id="eobActiveBanner" style="display:none;background:var(--purple-pale);border:1px solid var(--purple-dim);border-radius:8px;padding:8px 14px;margin-bottom:12px;align-items:center;justify-content:space-between;gap:10px;">
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <svg width="14" height="14" fill="none" stroke="var(--purple)" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    <span style="font-size:12px;font-weight:600;color:var(--purple);" id="bannerPayerName"></span>
                    <span style="font-size:11px;color:var(--text2);" id="bannerRef"></span>
                    <span style="font-family:'DM Mono',monospace;font-size:12px;font-weight:700;color:var(--navy);" id="bannerAmount"></span>
                    <span id="bannerStatus" style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:20px;"></span>
                </div>
                <button type="button" class="btn-wb btn-ghost-wb" style="font-size:11px;padding:4px 9px;flex-shrink:0;" onclick="clearEobWorkspace()">
                    <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php echo xlt('Clear'); ?>
                </button>
            </div>

            <!-- §2a: Allocation Grid — primary work area, shown as soon as charges load -->
            <div id="eobAllocGrid" style="display:none;margin-bottom:14px;">
                <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:11.5px;min-width:820px;" id="chargesTable">
                    <thead>
                        <tr style="background:var(--navy);">
                            <th style="width:28px;padding:8px 6px;"><input type="checkbox" id="chkAllCharges" onchange="toggleAllCharges(this)" style="accent-color:var(--purple-lt);"></th>
                            <th style="padding:8px 8px;text-align:left;color:rgba(255,255,255,.8);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap;"><?php echo xlt('DOS'); ?></th>
                            <th style="padding:8px 8px;text-align:left;color:rgba(255,255,255,.8);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;" id="chgColHeader"><?php echo xlt('Patient'); ?></th>
                            <th style="padding:8px 8px;text-align:left;color:rgba(255,255,255,.8);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('CPT'); ?></th>
                            <th style="padding:8px 8px;text-align:right;color:rgba(255,255,255,.8);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Billed'); ?></th>
                            <th style="padding:8px 8px;text-align:right;color:rgba(255,255,255,.8);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;"><?php echo xlt('Balance'); ?></th>
                            <!-- EOB entry columns (darker background) -->
                            <th style="padding:8px 6px;color:rgba(255,255,255,.95);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#1e3a5f;" title="<?php echo xla('Plan Paid (E on EOB)'); ?>"><?php echo xlt('Pmt'); ?></th>
                            <th data-ins-only="1" style="padding:8px 6px;color:rgba(255,255,255,.95);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#1e3a5f;" title="<?php echo xla('Contractual Adj / Not Covered (J on EOB)'); ?>"><?php echo xlt('Adj'); ?></th>
                            <th data-ins-only="1" style="padding:8px 6px;color:rgba(255,255,255,.95);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#2a4a6b;" title="<?php echo xla('Patient Deductible (I on EOB)'); ?>"><?php echo xlt('Ded'); ?></th>
                            <th data-ins-only="1" style="padding:8px 6px;color:rgba(255,255,255,.95);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#1f3a5f;" title="<?php echo xla('Coinsurance (H on EOB)'); ?>"><?php echo xlt('Coins'); ?></th>
                            <th style="padding:8px 6px;color:rgba(255,255,255,.95);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#1f3a5f;" title="<?php echo xla('Patient Copay (G on EOB)'); ?>"><?php echo xlt('Copay'); ?></th>
                            <th data-ins-only="1" style="padding:8px 6px;color:rgba(255,255,255,.95);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#3a2a5f;" title="<?php echo xla('Takeback / Recoupment'); ?>"><?php echo xlt('Takebk'); ?></th>
                            <th style="padding:8px 6px;color:rgba(255,255,255,.95);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#1e3a5f;width:44px;"><?php echo xlt('Seq'); ?></th>
                            <th style="padding:8px 6px;color:rgba(255,255,255,.7);font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:#162c45;" title="<?php echo xla('Non-payment / Denial / Follow-up'); ?>"><?php echo xlt('NP'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="chargesBody">
                        <tr id="chargesEmptyRow">
                            <td colspan="14" style="padding:28px;text-align:center;color:var(--text3);font-size:12px;">
                                <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto 8px;opacity:.3;"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                <?php echo xlt('Select a carrier or patient above to load open charges.'); ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
                <div id="chargesFooter" style="display:none;padding:10px 14px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:#fafbfc;">
                    <button type="button" class="btn-wb btn-ghost-wb" onclick="clearAllocations()" style="font-size:11px;padding:4px 10px;">
                        <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>
                        <?php echo xlt('Clear Allocations'); ?>
                    </button>
                    <div style="display:flex;align-items:center;gap:8px;font-size:11px;color:var(--text3);">
                        <span id="chgRowInfo">1–0 of 0</span>
                        <button type="button" onclick="chgPrevPage()" id="chgBtnPrev" class="btn-wb btn-ghost-wb" style="padding:3px 7px;font-size:11px;" disabled>‹</button>
                        <button type="button" onclick="chgNextPage()" id="chgBtnNext" class="btn-wb btn-ghost-wb" style="padding:3px 7px;font-size:11px;" disabled>›</button>
                    </div>
                </div>
            </div><!-- /#eobAllocGrid -->

            <!-- §2b + §2c: Applied + History panels — shown only when a specific EOB is loaded -->
            <div id="eobPanels" style="display:none;">
                <div class="eob-panels">

                    <!-- LEFT: Open Charges -->
                    <div class="eob-panel">
                        <div class="eob-panel-head open-hd">
                            <div class="eob-panel-title open-t">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                <?php echo xlt('Open Charges'); ?>
                                <span class="eob-panel-badge open-b" id="openChargesBadge">0</span>
                            </div>
                            <div style="font-size:10px;color:var(--text3);"><?php echo xlt('Available to work'); ?></div>
                        </div>
                        <div class="eob-panel-body" id="openChargesBody">
                            <div class="eob-panel-empty"><?php echo xlt('No open charges found.'); ?></div>
                        </div>
                        <div class="eob-panel-total">
                            <span style="font-family:'DM Sans',sans-serif;font-size:10px;color:var(--text2);font-weight:600;"><?php echo xlt('Total Balance'); ?></span>
                            <span id="openChargesTotal" style="color:var(--amber);">$0.00</span>
                        </div>
                    </div>

                    <!-- CENTER: Applied (Current EOB Draft) -->
                    <div class="eob-panel">
                        <div class="eob-panel-head applied-hd">
                            <div class="eob-panel-title applied-t">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                                <?php echo xlt('Applied to This EOB'); ?>
                                <span class="eob-panel-badge applied-b" id="appliedBadge">0</span>
                            </div>
                            <div style="font-size:10px;color:var(--text3);"><?php echo xlt('Current draft'); ?></div>
                        </div>
                        <div class="eob-panel-body" id="appliedBody">
                            <div class="eob-panel-empty"><?php echo xlt('No distributions applied yet.'); ?></div>
                        </div>
                        <div class="eob-panel-total">
                            <span style="font-family:'DM Sans',sans-serif;font-size:10px;color:var(--text2);font-weight:600;"><?php echo xlt('Total Applied'); ?></span>
                            <span id="appliedTotal" style="color:var(--green);">$0.00</span>
                        </div>
                    </div>

                    <!-- RIGHT: Posted History -->
                    <div class="eob-panel">
                        <div class="eob-panel-head history-hd">
                            <div class="eob-panel-title history-t">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                <?php echo xlt('Previously Posted'); ?>
                                <span class="eob-panel-badge history-b" id="historyBadge">0</span>
                            </div>
                            <div style="font-size:10px;color:var(--text3);"><?php echo xlt('Other sessions'); ?></div>
                        </div>
                        <div class="eob-panel-body" id="historyBody">
                            <div class="eob-panel-empty"><?php echo xlt('No prior activity found.'); ?></div>
                        </div>
                        <div class="eob-panel-total">
                            <span style="font-family:'DM Sans',sans-serif;font-size:10px;color:var(--text2);font-weight:600;"><?php echo xlt('Total Posted'); ?></span>
                            <span id="historyTotal" style="color:var(--slate);">$0.00</span>
                        </div>
                    </div>

                </div><!-- /eob-panels -->
            </div><!-- /#eobPanels -->

        </div>
    </div>

    <!-- §3 ALLOCATION SUMMARY -->
    <div class="wb-card" id="sectionSummary" style="margin-bottom:80px;">
        <div class="wb-card-head">
            <div class="wb-card-title">
                <span style="background:var(--purple);color:#fff;border-radius:50%;width:18px;height:18px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;flex-shrink:0;">3</span>
                <?php echo strtoupper(xlt('Allocation Summary')); ?>
            </div>
        </div>
        <div class="wb-card-body" style="padding:16px 20px;">
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;align-items:center;">
                <!-- Payment Amount -->
                <div style="display:flex;align-items:center;gap:12px;background:#f0eeff;border-radius:10px;padding:14px 16px;">
                    <div style="background:var(--purple);border-radius:8px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:var(--slate-lt);margin-bottom:2px;"><?php echo xlt('Payment Amount'); ?></div>
                        <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:var(--navy);" id="sumPayAmt">$0.00</div>
                    </div>
                </div>
                <!-- Allocated -->
                <div style="display:flex;align-items:center;gap:12px;background:#e6f5ef;border-radius:10px;padding:14px 16px;">
                    <div style="background:var(--green);border-radius:8px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:var(--slate-lt);margin-bottom:2px;"><?php echo xlt('Allocated'); ?></div>
                        <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:var(--green);" id="sumAllocated">$0.00</div>
                    </div>
                </div>
                <!-- Remaining -->
                <div style="display:flex;align-items:center;gap:12px;background:#fef3c7;border-radius:10px;padding:14px 16px;">
                    <div style="background:var(--amber);border-radius:8px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:var(--slate-lt);margin-bottom:2px;"><?php echo xlt('Remaining'); ?></div>
                        <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:var(--amber);" id="sumRemaining">$0.00</div>
                    </div>
                </div>
                <!-- Unallocated Charges -->
                <div style="display:flex;align-items:center;gap:12px;background:#fef2f2;border-radius:10px;padding:14px 16px;">
                    <div style="background:var(--red);border-radius:8px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/><circle cx="12" cy="12" r="10"/></svg>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:var(--slate-lt);margin-bottom:2px;"><?php echo xlt('Unallocated Charges'); ?></div>
                        <div style="font-size:18px;font-weight:700;font-family:'DM Mono',monospace;color:var(--red);" id="sumUnalloc">$0.00</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /wb-main -->

<!-- Sidebar -->
<div class="wb-sidebar">
    <div class="sb-title"><?php echo xlt('Recent EOBs'); ?> <a href="#" onclick="openAllEobsPopup();return false;"><?php echo xlt('View All'); ?></a></div>
    <?php
    $recentEobs = sqlStatement("
        SELECT
            s.session_id,
            s.reference,
            s.check_date,
            s.pay_total,
            s.payment_type,
            s.payment_method,
            s.created_time,
            ic.name AS payer_name,
            CONCAT(pd.lname, ', ', pd.fname) AS patient_name,
            COALESCE(SUM(a.pay_amount), 0)          AS posted_amount,
            COUNT(DISTINCT a.encounter)              AS encounter_count,
            CASE
                WHEN COALESCE(SUM(a.pay_amount), 0) = 0 THEN 'draft'
                ELSE 'posted'
            END AS post_status
        FROM ar_session s
        LEFT JOIN insurance_companies ic
            ON ic.id = s.payer_id
        LEFT JOIN patient_data pd
            ON pd.pid = s.patient_id
        LEFT JOIN ar_activity a
            ON  a.session_id = s.session_id
            AND a.deleted    IS NULL
        GROUP BY
            s.session_id,
            s.reference,
            s.check_date,
            s.pay_total,
            s.payment_type,
            s.payment_method,
            s.created_time,
            ic.name,
            pd.lname,
            pd.fname
        ORDER BY s.session_id DESC
        LIMIT 50
    ");
    $has_eobs = false;
    while ($row = sqlFetchArray($recentEobs)):
        $has_eobs   = true;
        $chk_date   = !empty($row['check_date']) ? oeFormatShortDate($row['check_date']) : '—';
        if (!empty($row['payer_name'])) {
            $payer = $row['payer_name'];
        } elseif (!empty($row['patient_name'])) {
            $payer = $row['patient_name'];
        } else {
            $payer = xl('Unknown');
        }
        $ref        = $row['reference'] ?: '—';
        $total      = (float)$row['pay_total'];
        $posted     = (float)$row['posted_amount'];
        $remaining  = round($total - $posted, 2);
        $enc_cnt    = (int)$row['encounter_count'];

        if ($posted <= 0.01) {
            $status = 'draft';
        } elseif ($remaining > 0.01) {
            $status = 'partial';
        } else {
            $status = 'posted';
        }

        $method_lbl = match(strtolower($row['payment_method'] ?? '')) {
            'eft'         => xlt('EFT'),
            'eob'         => xlt('EOB'),
            'cash'        => xlt('Cash'),
            'credit_card' => xlt('Credit Card'),
            default       => xlt('Check'),
        };
    ?>
    <div class="eob-hist-item"
         style="cursor:pointer;"
         id="eobHistItem_<?php echo (int)$row['session_id']; ?>"
         onclick="loadSavedEob(<?php echo (int)$row['session_id']; ?>)"
         title="<?php echo xla('Click to open and continue posting'); ?>">

        <div class="eob-hist-date"><?php echo text($chk_date); ?></div>

        <div class="eob-hist-payer"><?php echo text(strtoupper($payer)); ?></div>

        <div class="eob-hist-ref">
            <?php echo text($method_lbl); ?> #<?php echo text($ref); ?>
            <?php if ($enc_cnt > 0): ?>
            &nbsp;·&nbsp;<?php echo $enc_cnt; ?> <?php echo xlt('Claims'); ?>
            <?php endif; ?>
        </div>

        <div style="display:flex;align-items:baseline;justify-content:space-between;margin-top:4px;">
            <div class="eob-hist-amount">
                $<?php echo text(number_format($total, 2)); ?>
            </div>
            <span class="eob-hist-status <?php echo attr($status); ?>">
            <?php
            if ($status === 'posted') {
                echo '● ' . xlt('Posted');
            } elseif ($status === 'partial') {
                echo '◐ ' . xlt('Partially Posted');
            } else {
                echo '◐ ' . xlt('Draft');
            }
            ?>
            </span>
        </div>

        <?php if ($status === 'posted' && $remaining != 0): ?>
        <div style="font-size:10px;margin-top:2px;color:<?php echo $remaining > 0 ? 'var(--amber)' : 'var(--red)'; ?>;">
            <?php echo $remaining > 0 ? xlt('Unassigned:') : xlt('Over-applied:'); ?>
            $<?php echo text(number_format(abs($remaining), 2)); ?>
        </div>
        <?php endif; ?>

    </div>
    <?php endwhile; ?>
    <?php if (!$has_eobs): ?>
    <div style="padding:20px 0;text-align:center;font-size:11px;color:var(--text3);">
        <?php echo xlt('No insurance EOBs on file yet.'); ?>
    </div>
    <?php endif; ?>

    <div class="sb-qa-title"><?php echo xlt('Quick Actions'); ?></div>
    <a class="qa-item" href="#" onclick="toggleEraDrawer();return false;"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg><?php echo xlt('Import ERA File'); ?></a>
    <a class="qa-item" href="#" onclick="togglePaymentSearch();return false;"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><?php echo xlt('Open Payment Search'); ?></a>
    <a class="qa-item" href="legacy_eob_posting.php"><svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg><?php echo xlt('Legacy EOB Posting'); ?></a>
</div>
</div><!-- /wb-wrap -->

<!-- Footer -->
<div class="wb-footer">
    <button type="button" class="btn-wb btn-outline-wb" onclick="if(confirm('<?php echo xlt('Cancel and lose changes?'); ?>'))location.href='new_payment.php';"><?php echo xlt('Cancel'); ?></button>
    <div style="display:flex;gap:7px;align-items:center;">
        <?php if ($payment_id*1>0 && isset($CountIndexBelow) && $CountIndexBelow>0): ?>
        <button type="button" class="btn-wb btn-outline-wb" onclick="return PostPayments();"><?php echo xlt('Post Payments'); ?></button>
        <button type="button" class="btn-wb btn-outline-wb" onclick="return FinishPayments();"><?php echo xlt('Finish Payments'); ?></button>
        <?php endif; ?>
        <button type="button" class="btn-wb btn-outline-wb" onclick="return PostPayments();">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/></svg>
            <?php echo xlt('Save Draft'); ?>
        </button>
        <button type="button" class="btn-wb btn-post" onclick="return PostPayments();">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <?php echo xlt('Post EOB'); ?>
        </button>
    </div>
</div>

<!-- Legacy hidden fields (inside form for submission) -->
<!-- hidden_patient_code_2 removed: duplicate name caused pid=0 on all rows -->
<input id="mode" name="mode" type="hidden" value=""/>
<input id="default_search_patient" name="default_search_patient" type="hidden" value="<?php echo attr($default_search_patient);?>"/>
<input id="ajax_mode" name="ajax_mode" type="hidden" value=""/>
<input id="after_value" name="after_value" type="hidden" value="<?php echo attr($mode);?>"/>
<input id="payment_id" name="payment_id" type="hidden" value="<?php echo attr($payment_id);?>"<?php if($payment_id>0) echo ' data-php-set="1"'; ?>/>
<input id="global_amount" name="global_amount" type="hidden" value=""/>
</form>
</div>

<!-- Search Drawer -->
<div id="drawerOverlay" onclick="document.getElementById('statementsDrawer').classList.remove('open'); document.getElementById('eraImportDrawer').classList.remove('open'); this.classList.remove('open');"></div>
<div id="paymentSearchDrawer">
    <div class="drawer-pin-bar">
        <span>📌 <?php echo xlt('Search panel stays open — click any result to load it'); ?></span>
        <button class="drawer-close" onclick="togglePaymentSearch()" style="background:none;border:none;cursor:pointer;font-size:16px;color:var(--text2);">×</button>
    </div>
    <div class="drawer-head">
        <h5>
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-right:6px;vertical-align:middle;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?php echo xlt('Search Payments'); ?>
        </h5>
    </div>
    <div class="drawer-body">

        <!-- Payer -->
        <div style="margin-bottom:10px;">
            <label class="wb-label"><?php echo xlt('Payer'); ?></label>
            <select class="wb-select" id="dPayer">
                <option value=""><?php echo xlt('All Payers'); ?></option>
                <?php
                $dpayers = sqlStatement("SELECT id, name FROM insurance_companies ORDER BY name LIMIT 300");
                while ($dp = sqlFetchArray($dpayers)):
                ?><option value="<?php echo attr($dp['id']); ?>"><?php echo text($dp['name']); ?></option><?php
                endwhile;
                ?>
            </select>
        </div>

        <!-- Check / EFT # -->
        <div style="margin-bottom:10px;">
            <label class="wb-label"><?php echo xlt('Check / EFT #'); ?></label>
            <input type="text" class="wb-input mono" id="dCheckNum"
                placeholder="<?php echo xla('Enter check or EFT number'); ?>">
        </div>

        <!-- Date From / Date To -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
            <div>
                <label class="wb-label"><?php echo xlt('Date From'); ?></label>
                <input type="text" class="wb-input datepicker" id="dDateFrom">
            </div>
            <div>
                <label class="wb-label"><?php echo xlt('Date To'); ?></label>
                <input type="text" class="wb-input datepicker" id="dDateTo">
            </div>
        </div>

        <!-- Amount -->
        <div style="margin-bottom:10px;">
            <label class="wb-label"><?php echo xlt('Amount'); ?></label>
            <input type="text" class="wb-input mono" id="dAmount" placeholder="$0.00">
        </div>

        <!-- Payment Method -->
        <div style="margin-bottom:10px;">
            <label class="wb-label"><?php echo xlt('Payment Method'); ?></label>
            <select class="wb-select" id="dMethod">
            <option value=""><?php echo xlt('All Methods'); ?></option>
            <option value="insurance_payment"><?php echo xlt('Insurance Payment'); ?></option>
            <option value="patient_payment"><?php echo xlt('Patient Payment'); ?></option>
            <option value="check_payment"><?php echo xlt('Check'); ?></option>
            <option value="electronic"><?php echo xlt('EFT / Electronic'); ?></option>
            <option value="eob"><?php echo xlt('EOB'); ?></option>
            <option value="credit_card"><?php echo xlt('Credit Card'); ?></option>
            <option value="cash"><?php echo xlt('Cash'); ?></option>
        </select>
        </div>

        <!-- Payment Status -->
        <div style="margin-bottom:16px;">
            <label class="wb-label"><?php echo xlt('Payment Status'); ?></label>
            <div style="display:flex;gap:12px;padding:6px 0;font-size:12px;">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="radio" name="dStatus" id="dStatusAll" value="" checked>
                    <?php echo xlt('All'); ?>
                </label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="radio" name="dStatus" id="dStatusPosted" value="posted">
                    <span style="display:inline-flex;align-items:center;gap:4px;">
                        <span style="width:7px;height:7px;border-radius:50%;background:var(--green);display:inline-block;"></span>
                        <?php echo xlt('Posted'); ?>
                    </span>
                </label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="radio" name="dStatus" id="dStatusDraft" value="draft">
                    <span style="display:inline-flex;align-items:center;gap:4px;">
                        <span style="width:7px;height:7px;border-radius:50%;background:var(--amber);display:inline-block;"></span>
                        <?php echo xlt('Unapplied'); ?>
                    </span>
                </label>
            </div>
        </div>

        <!-- Search button -->
        <button type="button" class="btn-wb btn-primary-wb"
            style="width:100%;justify-content:center;margin-bottom:4px;"
            onclick="drawerSearch()">
            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?php echo xlt('Search'); ?>
        </button>
        <button type="button" class="btn-wb btn-ghost-wb"
            style="width:100%;justify-content:center;font-size:11px;"
            onclick="clearDrawerSearch()">
            <?php echo xlt('Clear'); ?>
        </button>

        <hr style="border:none;border-top:1px solid var(--border);margin:14px 0;">

        <!-- Results count -->
        <div id="drawerResultCount" style="display:none;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text3);margin-bottom:10px;"></div>

        <!-- Results list -->
        <div id="drawerResults">
            <div style="font-size:11px;color:var(--text3);text-align:center;padding:18px 0;">
                <?php echo xlt('Enter search criteria and click Search'); ?>
            </div>
        </div>

    </div>
</div>

<script src="<?php echo $webroot;?>/library/js/oeUI/oeFileUploads.js"></script>
<script>
$(function(){
    var dpCfg={<?php $datetimepicker_timepicker=false;$datetimepicker_showseconds=false;$datetimepicker_formatInput=true;require($GLOBALS['srcdir'].'/js/xl/jquery-datetimepicker-2-5-4.js.php');?>};
    $('.datepicker').datetimepicker(dpCfg);
    $('select').removeClass('class1 text');
    var pa=document.getElementById('payment_amount');
    if(pa) pa.addEventListener('focus',function(e){e.target.select();});
});
// ── Missing utility / autocomplete functions ─────────────────────────────────
function escHtml(s) {
    return String(s||'')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#39;');
}

function OnloadAction() {
    // Legacy hook — nothing required on load for the new workbench
}

function HideTheAjaxDivs() {
    hidePtDropdown2();
}

document.onclick = HideTheAjaxDivs;

// Patient autocomplete state
var _selectedPid  = '<?php echo attr($cur_patient_id ?? ''); ?>';
var _ptTimer2     = null;

function ptSearchDebounce2(val) {
    clearTimeout(_ptTimer2);
    if (val.length < 2) { hidePtDropdown2(); return; }
    _ptTimer2 = setTimeout(function(){ doPtSearch2(val); }, 280);
}

function doPtSearch2(val) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'new_payment.php?ajax_pt_search=1&q=' + encodeURIComponent(val), true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        var rows;
        try { rows = JSON.parse(xhr.responseText); } catch(e) { return; }
        renderPtDropdown2(rows);
    };
    xhr.send();
}

function renderPtDropdown2(rows) {
    var dd = document.getElementById('ptDropdown2');
    if (!dd) return;
    if (!rows || !rows.length) {
        dd.style.display = 'none';
        return;
    }
    dd.innerHTML = rows.map(function(r) {
        var dob = r.DOB ? ' <span style="color:var(--text3);font-size:10px;">DOB: ' + escHtml(r.DOB.substring(0,10)) + '</span>' : '';
        var name = escHtml(r.lname.toUpperCase() + ', ' + r.fname);
        return '<div class="pt-dd-item" style="padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--border);font-size:12.5px;"'
             + ' onmousedown="selectPatient2(' + escHtml(r.pid) + ',\'' + escHtml(r.lname.toUpperCase()+', '+r.fname) + '\')">'
             + name + dob + '</div>';
    }).join('');
    dd.style.display = 'block';
}

function showPtResults2() {
    var dd = document.getElementById('ptDropdown2');
    if (dd && dd.innerHTML) dd.style.display = 'block';
}

function hidePtDropdown2() {
    var dd = document.getElementById('ptDropdown2');
    if (dd) dd.style.display = 'none';
}

// ── Source toggle ────────────────────────────────────────────────────────────
function sourceChanged(val) {
    // Accept bare string OR object with .value (called from renderEobWorkspace)
    if (val && typeof val === 'object') val = val.value;
    var isIns = val === 'insurance';
    // Toggle field visibility
    document.getElementById('ins_fields').style.display  = isIns ? 'flex'  : 'none';
    document.getElementById('pat_fields').style.display  = isIns ? 'none'  : 'flex';
    document.getElementById('ins_row2').style.display    = isIns ? 'grid'  : 'none';
    document.getElementById('pat_row2').style.display    = isIns ? 'none'  : 'grid';
    // Style source buttons
    var lbl_ins = document.getElementById('lbl_ins');
    var lbl_pat = document.getElementById('lbl_pat');
    lbl_ins.style.borderColor  = isIns ? 'var(--purple)' : 'var(--border-mid)';
    lbl_ins.style.background   = isIns ? 'var(--purple-pale)' : 'var(--white)';
    lbl_ins.style.color        = isIns ? 'var(--purple)' : 'var(--text2)';
    lbl_pat.style.borderColor  = !isIns ? 'var(--purple)' : 'var(--border-mid)';
    lbl_pat.style.background   = !isIns ? 'var(--purple-pale)' : 'var(--white)';
    lbl_pat.style.color        = !isIns ? 'var(--purple)' : 'var(--text2)';
    // Update col header (patient col shows provider name in patient mode)
    var cHdr = document.getElementById('chgColHeader');
    if (cHdr) cHdr.textContent = isIns
        ? '<?php echo xlt("Patient"); ?>'
        : '<?php echo xlt("Provider"); ?>';
    // Toggle insurance-only alloc columns (Adj, Ded, Coins, Takebk)
    var tbl = document.getElementById('chargesTable');
    if (tbl) {
        if (isIns) tbl.classList.remove('pat-mode');
        else       tbl.classList.add('pat-mode');
    }
    // Rename "This EOB" → "This Payment" in patient mode
    var eobLbl = document.getElementById('labelThisEob');
    if (eobLbl) eobLbl.textContent = isIns
        ? '<?php echo xlt("This EOB"); ?>'
        : '<?php echo xlt("This Payment"); ?>';
    // When called by user interaction: reset workspace and reload charges.
    // When called programmatically (skipReset=true): just update UI chrome, don't wipe data.
    if (!sourceChanged._skipReset) {
        clearEobWorkspace();
        if (isIns) {
            var carr = document.getElementById('hidden_type_code_sel');
            if (carr && carr.value) carrierChanged(carr.value);
        }
    }
}

// ── Carrier selected → clear stale EOB state, load open charges ──────────────
function carrierChanged(carrierId) {
    var hc = document.getElementById('hidden_type_code');
    if (hc) hc.value = carrierId;
    clearEobWorkspace();                          // wipes _activeSessionId + panels
    if (!carrierId) return;
    setSubtitle('<?php echo xlt("Loading charges..."); ?>');
    loadChargesFromServer('?ajax_carrier_charges=1&carrier_id=' + encodeURIComponent(carrierId), 'insurance');
}

// ── Patient selected → clear stale EOB state, load open charges ──────────────
function selectPatient2(pid, name) {
    var hpc = document.getElementById('hidden_patient_code');
    var sp  = document.getElementById('selectedPid');
    var inp = document.getElementById('ptSearchInput2');
    var inp1= document.getElementById('ptSearchInput');
    if (hpc) hpc.value = pid;
    if (sp)  sp.value  = pid;
    if (inp) inp.value = name;
    if (inp1)inp1.value= name;
    hidePtDropdown2();
    _selectedPid = pid;
    clearEobWorkspace();                          // wipes _activeSessionId + panels
    setSubtitle('<?php echo xlt("Loading charges..."); ?>');
    loadChargesFromServer('?ajax_patient_charges=1&pid=' + encodeURIComponent(pid), 'patient');
    if (typeof loadEncounters === 'function') loadEncounters(pid);
}

function clearPatient() {
    var hpc = document.getElementById('hidden_patient_code');
    var sp  = document.getElementById('selectedPid');
    var inp = document.getElementById('ptSearchInput2');
    if (hpc) hpc.value = '';
    if (sp)  sp.value  = '';
    if (inp) inp.value = '';
    _selectedPid = '';
    clearEobWorkspace();
}

function setSubtitle(txt) {
    var el = document.getElementById('chargesSubtitle');
    if (el) el.textContent = txt;
}

// ── Core: fetch charges and render table ─────────────────────────────────────
var _allCharges    = [];   // all rows from server
var _chgPage       = 0;
var _chgPageSize   = 25;
var _chgMode       = 'insurance'; // or 'patient'

function loadChargesFromServer(url, mode) {
    _chgMode = mode;
    // Show alloc grid immediately (charges are the primary work area)
    document.getElementById('eobWorkspaceIdle').style.display = 'none';
    document.getElementById('eobAllocGrid').style.display     = 'block';
    // Panels stay hidden — only appear when a specific EOB is loaded
    document.getElementById('eobPanels').style.display        = 'none';
    document.getElementById('eobActiveBanner').style.display  = 'none';

    var tbody = document.getElementById('chargesBody');
    tbody.innerHTML = '<tr><td colspan="14" style="padding:24px;text-align:center;color:var(--text3);font-size:12px;">'
        + '<svg style="animation:spin .8s linear infinite;display:inline-block;vertical-align:middle;margin-right:6px;" width="14" height="14" viewBox="0 0 24 24" fill="var(--purple)"><path d="M12 2a10 10 0 1 0 10 10H20a8 8 0 1 1-8-8V2z"/></svg>'
        + '<?php echo xlt("Loading charges..."); ?></td></tr>';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function(){
        if (xhr.readyState !== 4) return;
        var rows; try { rows = JSON.parse(xhr.responseText); } catch(e) {
            tbody.innerHTML = '<tr><td colspan="14" style="padding:20px;text-align:center;color:var(--red);font-size:12px;"><?php echo xlt("Error loading charges."); ?></td></tr>';
            return;
        }
        _allCharges = rows;
        _chgPage    = 0;
        renderChargesPage();
        if (_activeSessionId <= 0) {
        updateChargeTotals();
         }

        setSubtitle(rows.length
            ? '<?php echo xlt("Open charges for the selected"); ?> ' + (mode === 'insurance' ? '<?php echo xlt("carrier"); ?>' : '<?php echo xlt("patient"); ?>') + ' — <?php echo xlt("click a Recent EOB to load applied + history"); ?>'
            : '<?php echo xlt("No open charges found."); ?>');
    };
    xhr.send();
}

function renderChargesPage() {
    var tbody   = document.getElementById('chargesBody');
    var total   = _allCharges.length;
    var start   = _chgPage * _chgPageSize;
    var end     = Math.min(start + _chgPageSize, total);
    var page    = _allCharges.slice(start, end);

    var cc=document.getElementById('chargesCount'); if(cc) cc.textContent = total;

    if (!total) {
        tbody.innerHTML = '<tr id="chargesEmptyRow"><td colspan="14" style="padding:28px;text-align:center;color:var(--text3);font-size:12px;"><?php echo xlt("No open charges found."); ?></td></tr>';
        document.getElementById('chargesFooter').style.display = 'none';
        return;
    }

    document.getElementById('chargesFooter').style.display = '';
    document.getElementById('chgRowInfo').textContent = (start+1) + '–' + end + ' of ' + total;
    document.getElementById('chgBtnPrev').disabled = (_chgPage === 0);
    document.getElementById('chgBtnNext').disabled = (end >= total);

    // Non-payment reason options
    var npReasons = [
        ['','— select reason —'],['DENY','Denied'],['PEND','Pending / Under Review'],
        ['NOAUTH','No Authorization'],['TIMELY','Timely Filing'],['DUPX','Duplicate Claim'],
        ['COB','COB Pending'],['INVL','Invalid Claim'],['OTHERINS','Other Ins Primary'],
        ['REBILL','Rebill Required'],['INFO','Need More Info'],['OTHER','Other']
    ];
    var npOpts = npReasons.map(function(o){
        return '<option value="'+escHtml(o[0])+'">'+escHtml(o[1])+'</option>';
    }).join('');

    tbody.innerHTML = page.map(function(r, i){
        var dos    = r.dos ? r.dos.substring(0,10) : '';
        var dosFmt = dos ? fmtDos(r.dos) : '';
        var cptTxt = r.code + (r.modifier ? '-'+r.modifier : '');
        var colVal = _chgMode === 'insurance'
            ? escHtml((r.patient_name||'').toUpperCase())
            : escHtml((r.provider_lname ? r.provider_lname+', '+r.provider_fname : '').replace(/,[\s]*$/,''));
        var bid = r.billing_id;

        // Compact amount cell — no $ prefix, right-aligned monospace
        function ai(nm, ph, w, bg, insOnly) {
            return '<td' + (insOnly ? ' data-ins-only="1"' : '') + ' style="padding:2px 3px;background:'+(bg||'#f5f8ff')+';white-space:nowrap;">'
                 + '<input type="text" class="amt-input" id="'+nm+'_'+bid+'" name="'+nm+'_'+bid+'"'
                 + ' data-bid="'+bid+'" value="" placeholder="'+ph+'"'
                 + ' style="width:'+(w||46)+'px;padding:3px 4px;text-align:right;'
                 + 'font-family:\'DM Mono\',monospace;font-size:11px;border:1px solid var(--border);'
                 + 'border-radius:4px;background:transparent;" oninput="allocChanged(this)">'
                 + '</td>';
        }

        return '<tr id="chgRow_'+bid+'" style="border-bottom:1px solid var(--border);">'
            + '<td style="padding:5px 6px;text-align:center;"><input type="checkbox" class="chg-chk" data-bid="'+bid+'"></td>'
            + '<td style="padding:5px 6px;font-family:\'DM Mono\',monospace;font-size:10px;color:var(--purple);white-space:nowrap;">'+dosFmt+'</td>'
            + '<td style="padding:5px 6px;font-size:10.5px;max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="'+colVal+'">'+colVal+'</td>'
            + '<td style="padding:5px 6px;font-family:\'DM Mono\',monospace;font-size:10px;font-weight:600;color:var(--navy-mid);">'+escHtml(cptTxt)+'</td>'
            + '<td style="padding:5px 6px;text-align:right;font-family:\'DM Mono\',monospace;font-weight:600;font-size:10px;">$'+fmt2(r.charge)+'</td>'
            + '<td style="padding:5px 6px;text-align:right;font-family:\'DM Mono\',monospace;font-weight:700;color:var(--amber);font-size:10px;">$'+fmt2(r.balance)+'</td>'
            + ai('alloc',  '0.00', 54, '#edf2ff')   // Pmt  — Plan Paid (E)
            + ai('adj',    '0.00', 50, '#edf2ff', true)   // Adj  — Not Covered / Contractual (J)
            + ai('ded',    '0.00', 46, '#eef0ff', true)   // Ded  — Deductible (I)
            + ai('coins',  '0.00', 46, '#eef0ff', true)   // Coins — Coinsurance (H)
            + ai('copay',  '0.00', 46, '#eef0ff')   // Copay — Copay (G)
            + ai('takebk', '0.00', 46, '#f5eeff', true)   // Takeback / Recoupment
            + '<td style="padding:2px 3px;background:#edf2ff;white-space:nowrap;">'
            +   '<input type="text" class="amt-input" id="seq_'+bid+'" name="seq_'+bid+'" value="" placeholder="1"'
            +   ' style="width:34px;padding:3px 4px;text-align:center;font-family:\'DM Mono\',monospace;font-size:11px;'
            +   'border:1px solid var(--border);border-radius:4px;background:transparent;" oninput="allocChanged(this)">'
            + '</td>'
            + '<td style="padding:2px 6px;background:#f5f0ff;text-align:center;">'
            +   '<input type="checkbox" class="np-chk" id="np_'+bid+'" data-bid="'+bid+'"'
            +   ' title="Non-payment / Denial / Follow-up" style="accent-color:#6366f1;cursor:pointer;width:14px;height:14px;">'
            + '</td>'
            + '</tr>'
            // Non-payment reason row — hidden until checkbox checked
            + '<tr id="npRow_'+bid+'" style="display:none;background:linear-gradient(90deg,#f5f0ff,#faf8ff);border-bottom:2px solid #c4b5fd;">'
            + '<td colspan="14" style="padding:7px 14px;">'
            +   '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">'
            +   '<span style="font-size:9.5px;font-weight:700;color:#6366f1;text-transform:uppercase;letter-spacing:.06em;flex-shrink:0;">'
            +     '<svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:3px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
            +     'Non-Payment</span>'
            +   '<select id="npreason_'+bid+'" name="npreason_'+bid+'" onchange="allocChanged(this)"'
            +   ' style="font-size:11px;padding:4px 8px;border:1px solid #c4b5fd;border-radius:5px;color:var(--navy);min-width:160px;">'+npOpts+'</select>'
            +   '<input type="text" id="npnote_'+bid+'" name="npnote_'+bid+'" placeholder="Additional note…"'
            +   ' style="flex:1;min-width:180px;font-size:11px;padding:4px 8px;border:1px solid var(--border);border-radius:5px;" oninput="allocChanged(this)">'
            +   '<label style="font-size:11px;color:var(--text2);display:flex;align-items:center;gap:5px;cursor:pointer;white-space:nowrap;">'
            +     '<input type="checkbox" id="npfollowup_'+bid+'" name="npfollowup_'+bid+'"'
            +     ' style="accent-color:#6366f1;cursor:pointer;" onchange="allocChanged(this)">'
            +     'Flag for follow-up'
            +   '</label>'
            +   '</div>'
            + '</td>'
            + '</tr>';
    }).join('');

    // Re-attach listeners
    tbody.querySelectorAll('.chg-chk').forEach(function(cb){
        cb.addEventListener('change', function(){ updateChargeTotals(); });
    });
    tbody.querySelectorAll('.np-chk').forEach(function(cb){
        cb.addEventListener('change', function(){ toggleNpRow(this.dataset.bid); });
    });
}

function chgPrevPage(){ if (_chgPage > 0){ _chgPage--; renderChargesPage(); } }
function chgNextPage(){ if ((_chgPage+1)*_chgPageSize < _allCharges.length){ _chgPage++; renderChargesPage(); } }

function clearChargesTable(){
    _allCharges = [];
    _chgPage    = 0;
    document.getElementById('chargesBody').innerHTML =
        '<tr id="chargesEmptyRow"><td colspan="14" style="padding:28px;text-align:center;color:var(--text3);font-size:12px;"><?php echo xlt("Select a carrier or patient above to load open charges."); ?></td></tr>';
    var cc2=document.getElementById('chargesCount'); if(cc2) cc2.textContent = '0';
    document.getElementById('chargesFooter').style.display = 'none';
    // Also reset live applied panel
    var ab = document.getElementById('appliedBody');
    if (ab) ab.innerHTML = '<div class="eob-panel-empty"><?php echo xlt("No distributions applied yet."); ?></div>';
    var abadge = document.getElementById('appliedBadge');
    if (abadge) abadge.textContent = '0';
    var atot = document.getElementById('appliedTotal');
    if (atot) atot.textContent = '$0.00';
    updateChargeTotals();
    recalcSummary();
}

function toggleNpRow(bid) {
    var row = document.getElementById('npRow_' + bid);
    var chk = document.getElementById('np_' + bid);
    if (row) row.style.display = (chk && chk.checked) ? '' : 'none';
    allocChanged(chk);
}

function clearAllocations(){
    document.querySelectorAll(
        '[id^="alloc_"],[id^="adj_"],[id^="ded_"],[id^="coins_"],[id^="copay_"],'
        + '[id^="takebk_"],[id^="seq_"],[id^="npnote_"]'
    ).forEach(function(inp){ inp.value = ''; });
    document.querySelectorAll('[id^="np_"],[id^="npfollowup_"]').forEach(function(cb){
        cb.checked = false;
    });
    document.querySelectorAll('[id^="npreason_"]').forEach(function(sel){ sel.selectedIndex = 0; });
    document.querySelectorAll('[id^="npRow_"]').forEach(function(row){ row.style.display = 'none'; });
    recalcSummary();
    updateLiveAppliedPanel();
}

function toggleAllCharges(cb){
    document.querySelectorAll('.chg-chk').forEach(function(c){ c.checked = cb.checked; });
}

// ── Charge column totals ──────────────────────────────────────────────────────
function updateChargeTotals(){
    var totChg = 0, totPaid = 0, totBal = 0;

    _allCharges.forEach(function(r){
        totChg  += parseFloat(r.charge  || 0);
        totPaid += parseFloat(r.paid    || 0);
        totBal  += parseFloat(r.balance || 0);
    });

    // Loaded saved EOB: use EOB totals, not open-charge totals
    if (_activeSessionId > 0) {
        setEl('chgTotalCharge',  '$' + fmt2(window._loadedEobOpenTotal || 0));
        setEl('chgTotalPaid',    '$' + fmt2(window._loadedEobPaymentTotal || 0));
        setEl('chgTotalBalance', '$' + fmt2(window._loadedEobHistoryTotal || 0));

        setColor('chgTotalPaid', 'var(--green)');
        setColor('chgTotalBalance', (window._loadedEobHistoryTotal || 0) > 0 ? 'var(--amber)' : 'var(--green)');

        recalcSummary();
        return;
    }

    // New/draft charge load
    setEl('chgTotalCharge',  '$' + fmt2(totBal));
    setEl('chgTotalPaid',    '$' + fmt2(totPaid));
    setEl('chgTotalBalance', '$' + fmt2(totBal));

    recalcSummary();
}

// ── Alloc input change → update summary + live Applied panel ────────────────
function allocChanged(inp) {
    recalcSummary();
    updateLiveAppliedPanel();
}

// Live-update the "Applied to this EOB" center panel as the biller types
function updateLiveAppliedPanel() {
    var panels = document.getElementById('eobPanels');
    if (!panels || panels.style.display === 'none') return;
    if (_activeSessionId > 0) return; // server data shown instead

    var rows = [];
    _allCharges.forEach(function(r) {
        var bid    = r.billing_id;
        var g      = function(id){ return parseFloat((document.getElementById(id+'_'+bid)||{value:'0'}).value)||0; };
        var pmt    = g('alloc'); var adj = g('adj'); var ded = g('ded');
        var coins  = g('coins'); var copay = g('copay'); var takebk = g('takebk');
        var seq    = (document.getElementById('seq_'+bid)||{value:''}).value || '';
        var npChk  = document.getElementById('np_'+bid);
        var isNp   = npChk && npChk.checked;
        var npR    = (document.getElementById('npreason_'+bid)||{value:''}).value;
        var cpt    = r.code + (r.modifier ? '-'+r.modifier : '');
        if (pmt || adj || ded || coins || copay || takebk || isNp) {
            rows.push({ dos:r.dos, cpt:cpt, pmt:pmt, adj:adj, ded:ded,
                        coins:coins, copay:copay, takebk:takebk, seq:seq, isNp:isNp, npR:npR });
        }
    });

    var totalApplied = rows.reduce(function(s,r){ return s+r.pmt; }, 0);
    document.getElementById('appliedBadge').textContent = rows.length;
    document.getElementById('appliedTotal').textContent = '$' + fmt2(totalApplied);
    setEl('chgTotalPaid', '$' + fmt2(totalApplied));

    var body = document.getElementById('appliedBody');
    if (!rows.length) {
        body.innerHTML = '<div class="eob-panel-empty"><?php echo xlt("Enter values above to see live totals."); ?></div>';
        return;
    }
    body.innerHTML = '<table class="eob-mini-tbl"><thead><tr>'
        + '<th><?php echo xlt("DOS"); ?></th>'
        + '<th><?php echo xlt("CPT"); ?></th>'
        + '<th class="r"><?php echo xlt("Pmt"); ?></th>'
        + '<th class="r"><?php echo xlt("Adj"); ?></th>'
        + '<th class="r" style="color:#93c5fd;"><?php echo xlt("Ded"); ?></th>'
        + '<th class="r"><?php echo xlt("Coins"); ?></th>'
        + '<th class="r"><?php echo xlt("Copay"); ?></th>'
        + '<th class="r"><?php echo xlt("TkBk"); ?></th>'
        + '<th class="r"><?php echo xlt("Seq"); ?></th>'
        + '<th><?php echo xlt("NP"); ?></th>'
        + '</tr></thead><tbody>'
        + rows.map(function(r) {
            return '<tr>'
                + '<td class="dos">' + fmtDos(r.dos) + '</td>'
                + '<td class="cpt">' + escHtml(r.cpt) + '</td>'
                + '<td class="r" style="color:var(--green);font-weight:700;">'   + (r.pmt    ? '$'+fmt2(r.pmt)    : '\u2014') + '</td>'
                + '<td class="r">'                                                + (r.adj    ? '$'+fmt2(r.adj)    : '\u2014') + '</td>'
                + '<td class="r" style="color:#6366f1;">'                        + (r.ded    ? '$'+fmt2(r.ded)    : '\u2014') + '</td>'
                + '<td class="r" style="color:#3b82f6;">'                        + (r.coins  ? '$'+fmt2(r.coins)  : '\u2014') + '</td>'
                + '<td class="r" style="color:#0ea5e9;">'                        + (r.copay  ? '$'+fmt2(r.copay)  : '\u2014') + '</td>'
                + '<td class="r" style="color:var(--red);">'                     + (r.takebk ? '-$'+fmt2(r.takebk): '\u2014') + '</td>'
                + '<td class="r" style="color:var(--text3);">'                   + escHtml(r.seq||'\u2014') + '</td>'
                + '<td>' + (r.isNp ? '<span style="color:#6366f1;font-size:9px;font-weight:700;">NP'+(r.npR?' '+escHtml(r.npR):'')+'</span>' : '') + '</td>'
                + '</tr>';
        }).join('')
        + '</tbody></table>';
}

function recalcSummary(){
    // Payment amount — try both insurance and patient inputs
    var payAmtEl = document.getElementById('payment_amount') || document.getElementById('payment_amount_pat');
    var payAmt   = parseFloat((payAmtEl||{value:'0'}).value.replace(/[^0-9.]/g,'')) || 0;

    // Sum all allocate inputs
    var allocated = 0;

    if (_activeSessionId > 0) {

        allocated = window._loadedEobPaymentTotal || 0;

    } else {

        document.querySelectorAll('[id^="alloc_"]').forEach(function(inp){
            allocated += parseFloat(inp.value.replace(/[^0-9.]/g,'')) || 0;
        });
    }

    // Unallocated charges = sum of balance on rows with no allocation
    var unalloc = 0;
    _allCharges.forEach(function(r){
        var inp   = document.getElementById('alloc_' + r.billing_id);
        var alVal = inp ? (parseFloat(inp.value.replace(/[^0-9.]/g,'')) || 0) : 0;
        if (alVal === 0) unalloc += parseFloat(r.balance || 0);
    });

    var remaining = payAmt - allocated;

    setEl('sumPayAmt',   '$' + fmt2(payAmt));
    setEl('sumAllocated','$' + fmt2(allocated));
    setEl('sumRemaining','$' + fmt2(remaining));
    setEl('sumUnalloc',  '$' + fmt2(unalloc));
    setColor('sumRemaining', remaining < -0.005 ? 'var(--red)' : remaining < 0.005 ? 'var(--green)' : 'var(--amber)');
    setColor('sumUnalloc',   unalloc < 0.005 ? 'var(--green)' : 'var(--red)');
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function fmt2(v){ return parseFloat(v||0).toFixed(2); }
function setEl(id, txt){ var el=document.getElementById(id); if(el) el.textContent=txt; }
function setColor(id, col){ var el=document.getElementById(id); if(el) el.style.color=col; }
function methodChanged(v){
    var lbl=document.getElementById('check_label');
    if(!lbl)return;
    if(v==='eft')       lbl.textContent='EFT #';
    else if(v==='check')lbl.textContent='<?php echo xlt("Check No."); ?>';
    else                lbl.textContent='<?php echo xlt("Reference #"); ?>';
}
function toggleNotes(){
    var ns=document.getElementById('notesSection');
    if(ns)ns.style.display=ns.style.display==='none'?'':'none';
}
function syncAmt(inp){
    var pa = document.getElementById('payment_amount');
    if (pa) pa.value = inp.value;
    recalcSummary();
}
function FillUnappliedAmount(){}
function ResetForm(){document.forms[0].reset();}
// ── Load EOB into workspace (no page reload) ─────────────────────────────────
var _activeSessionId = 0;

function loadSavedEob(sessionId) {
    if (_activeSessionId === sessionId) return; // already loaded

    // Search drawer stays open — results remain visible while editing the loaded EOB

    // Highlight sidebar item
    document.querySelectorAll('.eob-hist-item').forEach(function(el){ el.classList.remove('active-eob'); });
    var sideItem = document.getElementById('eobHistItem_' + sessionId);
    if (sideItem) sideItem.classList.add('active-eob');

    // Show loading state
    document.getElementById('eobWorkspaceIdle').style.display = 'none';
    document.getElementById('eobActiveBanner').style.display  = 'flex';
    document.getElementById('eobPanels').style.display        = 'none';
    document.getElementById('eobAllocGrid').style.display     = 'none';
    document.getElementById('bannerPayerName').textContent    = '<?php echo xlt("Loading..."); ?>';
    document.getElementById('bannerRef').textContent          = '';
    document.getElementById('bannerAmount').textContent       = '';
    document.getElementById('bannerStatus').textContent       = '';
    setSubtitle('<?php echo xlt("Loading EOB data..."); ?>');

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'new_payment.php?ajax_eob_detail=1&session_id=' + encodeURIComponent(sessionId), true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        var data;
        try { data = JSON.parse(xhr.responseText); } catch(e) {
            console.error('EOB detail parse error. Raw response:', xhr.responseText.substring(0, 800));
            document.getElementById('bannerPayerName').textContent = '<?php echo xlt("Error loading EOB data."); ?>';
            document.getElementById('bannerRef').textContent = e.message + ' — check browser console';
            return;
        }
        _activeSessionId = sessionId;
        // Keep hidden payment_id in sync so PostPayments/FinishPayments always have it
        var pidHid = document.getElementById('payment_id');
        if (pidHid) pidHid.value = sessionId;
        try {
            renderEobWorkspace(data);
        } catch(renderErr) {
            console.error('renderEobWorkspace error:', renderErr);
            document.getElementById('eobActiveBanner').style.display = 'flex';
            document.getElementById('eobWorkspaceIdle').style.display = 'none';
            document.getElementById('bannerPayerName').textContent = '<?php echo xlt("Error loading EOB data."); ?>';
            document.getElementById('bannerRef').textContent = renderErr.message || '';
            document.getElementById('bannerStatus').textContent = '';
            document.getElementById('bannerAmount').textContent = '';
        }
    };
    xhr.send();
}

function setEobHeaderTotals(openTotal, eobTotal, priorTotal) {
    setEl('chgTotalCharge',  '$' + fmt2(openTotal));
    setEl('chgTotalPaid',    '$' + fmt2(eobTotal));
    setEl('chgTotalBalance', '$' + fmt2(priorTotal));

    setColor('chgTotalPaid', 'var(--green)');
    setColor('chgTotalBalance', priorTotal > 0 ? 'var(--amber)' : 'var(--green)');
}

function renderEobWorkspace(data) {
    if (!data || !data.session) {
        throw new Error('<?php echo xlt("Session not found. The EOB may have been deleted."); ?>');
    }
    var session = data.session;

    window._loadedSession = session;

    var open    = data.open    || [];
    var applied = data.applied || [];
    var history = data.history || [];

    // ── Populate Payment Information header ────────────────────────────────────
    // Use skip flag so sourceChanged only updates UI chrome — no clearEobWorkspace cascade
    sourceChanged._skipReset = true;
    try {
        var isIns = (session.payment_type || 'insurance') !== 'patient';
        // Source radio
        var rbIns = document.getElementById('rb_insurance');
        var rbPat = document.getElementById('rb_patient');
        if (rbIns) rbIns.checked =  isIns;
        if (rbPat) rbPat.checked = !isIns;
        sourceChanged(isIns ? 'insurance' : 'patient');

        if (isIns) {
            // Carrier dropdown
            var carrSel = document.getElementById('hidden_type_code_sel');
            var carrHid = document.getElementById('hidden_type_code');
            if (carrSel) carrSel.value = session.payer_id || '';
            if (carrHid) carrHid.value = session.payer_id || '';
            // Amount
            var amtEl = document.getElementById('payment_amount');
            if (amtEl) amtEl.value = parseFloat(session.pay_total || 0).toFixed(2);
            // Check / EFT #
            var chkEl = document.getElementById('check_number');
            if (chkEl) chkEl.value = session.reference || '';
            // Check date (YYYY-MM-DD → MM/DD/YYYY)
            var cdEl = document.getElementById('check_date');
            if (cdEl && session.check_date) {
                var dp = session.check_date.substring(0,10).split('-');
                if (dp.length === 3) cdEl.value = dp[1] + '/' + dp[2] + '/' + dp[0];
            }
            // Payment method
            var pmEl = document.getElementById('payment_method');
            if (pmEl && session.payment_method) pmEl.value = session.payment_method;
        } else {
            // Patient
            var ptHid = document.getElementById('hidden_patient_code');
            var ptPid = document.getElementById('selectedPid');
            var ptInp = document.getElementById('ptSearchInput2');
            if (ptHid) ptHid.value = session.patient_id || '';
            if (ptPid) ptPid.value = session.patient_id || '';
            if (ptInp) ptInp.value = (session.patient_name || '').toUpperCase();
            // Amount
            var amtPat = document.getElementById('payment_amount_pat');
            var amtIns = document.getElementById('payment_amount');
            var payVal = parseFloat(session.pay_total || 0).toFixed(2);
            if (amtPat) amtPat.value = payVal;
            if (amtIns) amtIns.value = payVal;
            // Reference
            var chkPat = document.getElementById('check_number_pat');
            if (chkPat) chkPat.value = session.reference || '';
        }
    } finally {
        sourceChanged._skipReset = false;
    }

    // ── Banner: populate from first applied row (has payer context) ──────────
    // We'll rely on the sidebar item text since we don't have session meta here;
    // just show totals for now — payer name is on the sidebar item
    var totalApplied = applied.reduce(function(s, r){ return s + parseFloat(r.pay_amount||0); }, 0);
    var totalHistory = history.reduce(function(s, r){ return s + parseFloat(r.pay_amount||0) + parseFloat(r.adj_amount||0); }, 0);
    var totalOpen    = open.reduce(function(s, r){ return s + parseFloat(r.balance||0); }, 0);

    var eobAmount = parseFloat(session.pay_total || 0);

    var eobRemaining = eobAmount - totalApplied;

    if (eobRemaining < 0.005) {
        eobRemaining = 0;
    }

    window._loadedEobPaymentTotal = totalApplied;
    window._loadedEobHistoryTotal = totalHistory;
    window._loadedEobOpenTotal = eobRemaining;

    setEobHeaderTotals(
        eobRemaining,
        totalApplied,
        totalHistory
    );

    window._loadedEobPaymentTotal = totalApplied;

    window._loadedEobAdjustmentTotal = applied.reduce(function(s, r){
        return s + parseFloat(r.adj_amount || 0);
    }, 0);

    window._loadedEobOpenTotal = totalOpen;

    document.getElementById('bannerPayerName').textContent =
    session.payer_name ||
    session.patient_name ||
    ('EOB #' + _activeSessionId);
    var appliedClaims = {};

    applied.forEach(function(r) {
        if (r.encounter) {
            appliedClaims[r.encounter] = true;
        }
    });

    var appliedCount = Object.keys(appliedClaims).length;

    var priorClaims = {};

    history.forEach(function(r) {
        if (r.encounter) {
            priorClaims[r.encounter] = true;
        }
    });

    var priorCount = Object.keys(priorClaims).length;
    document.getElementById('bannerRef').textContent =
    (session.reference || '—')
    + ' • '
    + open.length + ' open'
    + ' • '
    + appliedCount + ' applied'
    + ' • '
    + priorCount + ' prior';
    document.getElementById('bannerAmount').textContent =
    '$' + fmt2(session.pay_total || 0);
    var statusBadge = document.getElementById('bannerStatus');
    if (applied.length > 0) {
        statusBadge.textContent = '● <?php echo xlt("Has Activity"); ?>';
        statusBadge.style.background = 'var(--green-bg)';
        statusBadge.style.color = 'var(--green)';
    } else {
        statusBadge.textContent = '◐ <?php echo xlt("Draft"); ?>';
        statusBadge.style.background = 'var(--amber-bg)';
        statusBadge.style.color = 'var(--amber)';
    }

    // Summary bar
    document.getElementById('chgTotalCharge').textContent =
    '$' + fmt2(eobRemaining);

    document.getElementById('chgTotalPaid').textContent =
        '$' + fmt2(totalApplied);

    document.getElementById('chgTotalBalance').textContent =
        '$' + fmt2(totalHistory);
    setSubtitle('<?php echo xlt("EOB loaded — open, applied, and history below."); ?>');

    // ── LEFT PANEL: Open Charges ──────────────────────────────────────────────
    document.getElementById('openChargesBadge').textContent = open.length;
    document.getElementById('openChargesTotal').textContent = '$' + fmt2(totalOpen);
    var openBody = document.getElementById('openChargesBody');
    if (!open.length) {
        openBody.innerHTML = '<div class="eob-panel-empty"><?php echo xlt("All charges have been fully applied."); ?></div>';
    } else {
        openBody.innerHTML = '<table class="eob-mini-tbl"><thead><tr>'
            + '<th><?php echo xlt("DOS"); ?></th>'
            + '<th><?php echo xlt("CPT"); ?></th>'
            + '<th><?php echo xlt("Pt"); ?></th>'
            + '<th class="r"><?php echo xlt("Charge"); ?></th>'
            + '<th class="r"><?php echo xlt("Balance"); ?></th>'
            + '</tr></thead><tbody>'
            + open.map(function(r) {
                var dos = fmtDos(r.dos);
                var cpt = escHtml(r.code + (r.modifier ? '-'+r.modifier : ''));
                var pt  = escHtml((r.patient_name||'').split(',')[0].trim());
                return '<tr>'
                    + '<td class="dos">'  + dos + '</td>'
                    + '<td class="cpt">'  + cpt + '</td>'
                    + '<td style="font-size:10.5px;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + pt + '</td>'
                    + '<td class="r">$'  + fmt2(r.charge)  + '</td>'
                    + '<td class="r" style="color:var(--amber);font-weight:700;">$' + fmt2(r.balance) + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table>';
    }

    // ── CENTER PANEL: Applied ─────────────────────────────────────────────────
    var distinctClaims = {};

    applied.forEach(function(r){
        distinctClaims[r.encounter] = true;
    });

    document.getElementById('appliedBadge').textContent =
        Object.keys(distinctClaims).length;
    document.getElementById('appliedTotal').textContent = '$' + fmt2(totalApplied);
    var appliedBody = document.getElementById('appliedBody');
    if (!applied.length) {
        appliedBody.innerHTML = '<div class="eob-panel-empty"><?php echo xlt("No distributions applied yet."); ?></div>';
    } else {
        appliedBody.innerHTML = '<table class="eob-mini-tbl"><thead><tr>'
            + '<th><?php echo xlt("DOS"); ?></th>'
            + '<th><?php echo xlt("PT"); ?></th>'
            + '<th><?php echo xlt("CPT"); ?></th>'
            + '<th class="r"><?php echo xlt("Pmt"); ?></th>'
            + '<th class="r"><?php echo xlt("Adj"); ?></th>'
            + '<th class="r" style="color:#93c5fd;"><?php echo xlt("Ded"); ?></th>'
            + '<th class="r"><?php echo xlt("Seq"); ?></th>'
            + '<th><?php echo xlt("Type"); ?></th>'
            + '</tr></thead><tbody>'
            + applied.map(function(r) {
                var dos  = fmtDos(r.dos);
                var pt   = escHtml((r.patient_name || '').split(',')[0].trim());
                var cpt  = escHtml((r.code || '') + (r.modifier ? '-' + r.modifier : ''));
                var pmt  = parseFloat(r.pay_amount  || 0);
                var adj  = parseFloat(r.adj_amount  || 0);
                var isDed = (r.account_code || '').toLowerCase().indexOf('deduct') !== -1;
                var dedAmt = isDed ? pmt : 0;
                var pmtAmt = isDed ? 0   : pmt;
                var typeLbl = isDed ? '<span style="color:#6366f1;font-size:9.5px;font-weight:700;">DED</span>'
                            : adj !== 0 ? '<span style="color:var(--amber);font-size:9.5px;">ADJ</span>'
                            : pmt !== 0 ? '<span style="color:var(--green);font-size:9.5px;">PMT</span>'
                            : '<span style="color:var(--text3);font-size:9.5px;">' + escHtml(r.account_code||'—') + '</span>';
                return '<tr>'
                    + '<td class="dos">' + dos + '</td>'
                    + '<td style="font-size:10.5px;max-width:70px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + pt + '</td>'
                    + '<td class="cpt">' + cpt + '</td>'
                    + '<td class="r" style="color:var(--green);font-weight:700;">' + (pmtAmt ? '$'+fmt2(pmtAmt) : '—') + '</td>'
                    + '<td class="r">' + (adj  ? '$'+fmt2(adj)    : '—') + '</td>'
                    + '<td class="r" style="color:#6366f1;">' + (dedAmt ? '$'+fmt2(dedAmt) : '—') + '</td>'
                    + '<td class="r" style="color:var(--text3);">' + escHtml(r.sequence_no||'—') + '</td>'
                    + '<td>' + typeLbl + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table>';
    }

    // ── RIGHT PANEL: Posted History ───────────────────────────────────────────
    document.getElementById('historyBadge').textContent = history.length;
    document.getElementById('historyTotal').textContent = '$' + fmt2(totalHistory);
    var historyBody = document.getElementById('historyBody');
    if (!history.length) {
        historyBody.innerHTML = '<div class="eob-panel-empty"><?php echo xlt("No prior activity on these encounters."); ?></div>';
    } else {
        historyBody.innerHTML = '<table class="eob-mini-tbl"><thead><tr>'
            + '<th><?php echo xlt("DOS"); ?></th>'
            + '<th><?php echo xlt("CPT"); ?></th>'
            + '<th><?php echo xlt("Type"); ?></th>'
            + '<th class="r"><?php echo xlt("Pmt"); ?></th>'
            + '<th class="r"><?php echo xlt("Adj"); ?></th>'
            + '<th class="r" style="color:#93c5fd;"><?php echo xlt("Ded"); ?></th>'
            + '<th><?php echo xlt("Date"); ?></th>'
            + '<th><?php echo xlt("Ref"); ?></th>'
            + '</tr></thead><tbody>'
            + history.map(function(r) {
                var dos    = fmtDos(r.dos);
                var chkDt  = r.check_date ? fmtDos(r.check_date) : '—';
                var cpt    = escHtml((r.code||'') + (r.modifier ? '-'+r.modifier : ''));
                var pmt    = parseFloat(r.pay_amount || 0);
                var adj    = parseFloat(r.adj_amount || 0);
                var isDed  = (r.account_code||'').toLowerCase().indexOf('deduct') !== -1;
                var dedAmt = isDed ? pmt : 0;
                var pmtAmt = isDed ? 0   : pmt;
                var typeColor = isDed ? '#6366f1' : adj !== 0 ? 'var(--amber)' : pmt !== 0 ? 'var(--green)' : 'var(--text3)';
                return '<tr>'
                    + '<td class="dos">' + dos + '</td>'
                    + '<td class="cpt">' + cpt + '</td>'
                    + '<td style="font-size:9.5px;font-weight:700;color:' + typeColor + ';">' + escHtml(r.activity_type||'—') + '</td>'
                    + '<td class="r" style="color:var(--green);">' + (pmtAmt ? '$'+fmt2(pmtAmt) : '—') + '</td>'
                    + '<td class="r">' + (adj   ? '$'+fmt2(adj)    : '—') + '</td>'
                    + '<td class="r" style="color:#6366f1;">'       + (dedAmt ? '$'+fmt2(dedAmt) : '—') + '</td>'
                    + '<td class="dos">' + chkDt + '</td>'
                    + '<td style="font-family:\'DM Mono\',monospace;font-size:10px;color:var(--text3);">' + escHtml(r.reference||'\u2014') + '</td>'
                    + '</tr>';
            }).join('')
            + '</tbody></table>';
    }

    // ── Allocation grid: render open charges, then pre-fill from applied ────────
    _allCharges = open;
    _chgPage    = 0;
    renderChargesPage();   // builds the input fields

    // Pre-fill allocation inputs from server-side applied data.
    // Group by (encounter + code + modifier) → sum pay/adj/ded/coins, keep last seq.
    // The alloc grid rows are keyed by billing_id, which maps to a billing row.
    // Build a lookup: encounter+code+modifier → {pmt, adj, ded, coins, seq}
    // Build pre-fill map: encounter|code|modifier → {pmt, adj, ded, seq}
    // Applied rows now have per-entry detail; aggregate by billing key.
    var appliedMap = {};
    applied.forEach(function(a) {
        var key = (a.encounter||'') + '|' + (a.code||'') + '|' + (a.modifier||'');
        if (!appliedMap[key]) appliedMap[key] = {pmt:0, adj:0, ded:0, seq:''};
        var isDed = (a.account_code||'').toLowerCase().indexOf('deduct') !== -1;
        if (isDed) {
            appliedMap[key].ded += parseFloat(a.pay_amount || 0);
        } else {
            appliedMap[key].pmt += parseFloat(a.pay_amount || 0);
            appliedMap[key].adj += parseFloat(a.adj_amount || 0);
        }
        if (a.sequence_no) appliedMap[key].seq = a.sequence_no;
    });

    open.forEach(function(r) {
        var key = (r.encounter||'') + '|' + (r.code||'') + '|' + (r.modifier||'');
        var av  = appliedMap[key];
        if (!av) return;
        var bid = r.billing_id;
        function fill(pfx, val) {
            var el = document.getElementById(pfx + '_' + bid);
            if (el && val) el.value = parseFloat(val).toFixed(2);
        }
        if (av.pmt) fill('alloc', av.pmt);
        if (av.adj) fill('adj',   av.adj);
        if (av.ded) fill('ded',   av.ded);
        if (av.seq) {
            var seqEl = document.getElementById('seq_' + bid);
            if (seqEl) seqEl.value = av.seq;
        }
    });

    updateChargeTotals();
    recalcSummary();
    updateLiveAppliedPanel();   // reflect pre-filled values in Applied panel

    // Show alloc grid + EOB panels; hide idle
    document.getElementById('eobWorkspaceIdle').style.display = 'none';
    document.getElementById('eobAllocGrid').style.display     = 'block';
    document.getElementById('eobPanels').style.display        = 'block';
}

function clearEobWorkspace() {
    _activeSessionId = 0;
    // Clear the payment_id hidden field so a stale session can't be posted
    var pidHid = document.getElementById('payment_id');
    if (pidHid && !pidHid.getAttribute('data-php-set')) pidHid.value = '';
    // De-highlight any selected sidebar item
    document.querySelectorAll('.eob-hist-item').forEach(function(el){ el.classList.remove('active-eob'); });
    // Always hide EOB-specific panels and banner
    document.getElementById('eobActiveBanner').style.display = 'none';
    document.getElementById('eobPanels').style.display       = 'none';
    // If charges are already loaded (carrier/patient mode), keep the alloc grid visible
    // and just wipe the EOB-specific panel data. Only go back to idle if no charges at all.
    if (_allCharges.length > 0) {
        // Keep alloc grid; reset panel totals in header to open-charges values
        document.getElementById('eobWorkspaceIdle').style.display = 'none';
        document.getElementById('eobAllocGrid').style.display     = 'block';
        setSubtitle('<?php echo xlt("Open charges loaded — click a Recent EOB to see applied + history"); ?>');
        setEl('chgTotalPaid',    '$0.00');
        setEl('chgTotalBalance', '$0.00');
    } else {
        // No charges at all — show idle placeholder
        document.getElementById('eobWorkspaceIdle').style.display = 'block';
        document.getElementById('eobAllocGrid').style.display     = 'none';
        setSubtitle('<?php echo xlt("Select a carrier or patient above to load charges."); ?>');
        clearChargesTable();
    }
}

function fmtDos(d) {
    if (!d) return '—';
    var s = d.substring(0, 10);
    var p = s.split('-');
    return p.length === 3 ? p[1]+'/'+p[2]+'/'+p[0].substring(2) : s;
}
function autoApply(){}
function reloadCharges(){}
function deleteSingle(){}
// Legacy stubs
function recalc(){ recalcSummary(); }

// ── Statements / Invoice Search Drawer ─────────────────────────────────────
function toggleStatementsDrawer() {
    var drawer  = document.getElementById('statementsDrawer');
    var overlay = document.getElementById('drawerOverlay');
    if (!drawer) return;
    var opening = !drawer.classList.contains('open');
    drawer.classList.toggle('open', opening);
    overlay.classList.toggle('open', opening);
    // Close ERA drawer if open
    var era = document.getElementById('eraImportDrawer');
    if (era && opening) era.classList.remove('open');
}

function stmtSearch() {
    var name     = document.getElementById('stmtName').value.trim();
    var pid      = document.getElementById('stmtPid').value.trim();
    var enc      = document.getElementById('stmtEncounter').value.trim();
    var from     = document.getElementById('stmtDateFrom').value;
    var to       = document.getElementById('stmtDateTo').value;
    var cat      = document.getElementById('stmtCategory').value;
    var info     = document.getElementById('stmtResultsInfo');
    var tbody    = document.getElementById('stmtResultsBody');

    info.textContent = '<?php echo xlt("Searching..."); ?>';
    tbody.innerHTML  = '<tr><td colspan="9" class="stmt-empty">'
        + '<svg style="animation:spin .8s linear infinite;display:inline;vertical-align:middle;margin-right:6px;" width="14" height="14" viewBox="0 0 24 24" fill="var(--purple)"><path d="M12 2a10 10 0 1 0 10 10H20a8 8 0 1 1-8-8V2z"/></svg>'
        + '<?php echo xlt("Searching..."); ?></td></tr>';

    var url = 'new_payment.php?ajax_invoice_search=1'
        + (name ? '&s_name='      + encodeURIComponent(name) : '')
        + (pid  ? '&s_pid='       + encodeURIComponent(pid)  : '')
        + (enc  ? '&s_encounter=' + encodeURIComponent(enc)  : '')
        + (from ? '&s_date_from=' + encodeURIComponent(from) : '')
        + (to   ? '&s_date_to='   + encodeURIComponent(to)   : '')
        + (cat  ? '&s_category='  + encodeURIComponent(cat)  : '');

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch(e) {
            info.textContent = '<?php echo xlt("Search error."); ?>';
            tbody.innerHTML  = '';
            return;
        }
        if (!resp.success) { info.textContent = resp.error || 'Error'; return; }

        info.textContent = resp.total + ' <?php echo xlt("result(s)"); ?>';

        if (!resp.rows.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="stmt-empty"><?php echo xlt("No invoices found."); ?></td></tr>';
            return;
        }

        var webroot = '<?php echo $webroot; ?>';
        tbody.innerHTML = resp.rows.map(function(r) {
            var balCls = parseFloat(r.balance) > 0 ? 'bal-pos'
                       : parseFloat(r.balance) < 0 ? 'bal-zero' : '';
            var invUrl = webroot + '/interface/billing/sl_eob_invoice.php?id=' + r.id
                       + '&csrf_token_form=<?php echo attr(\OpenEMR\Common\Csrf\CsrfUtils::collectCsrfToken()); ?>';
            return '<tr>'
                + '<td>' + escHtml(r.name) + '</td>'
                + '<td><a class="stmt-inv-link" href="' + invUrl + '" target="_blank">' + r.pid + '.' + r.encounter + '</a></td>'
                + '<td>' + (r.date || '&mdash;') + '</td>'
                + '<td style="font-size:10.5px;color:var(--text3);">' + (r.last_stmt || '&mdash;') + '</td>'
                + '<td class="r">$' + r.charges + '</td>'
                + '<td class="r">$' + r.adj + '</td>'
                + '<td class="r">$' + r.paid + '</td>'
                + '<td class="r ' + balCls + '">$' + r.balance + '</td>'
                + '<td class="r" style="color:var(--text3);">' + (r.stmt_count || '0') + '</td>'
                + '</tr>';
        }).join('');
    };
    xhr.send();
}

// Allow pressing Enter in search fields
document.addEventListener('DOMContentLoaded', function() {
    ['stmtName','stmtPid','stmtEncounter'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('keydown', function(e){ if (e.key==='Enter') stmtSearch(); });
    });
});

// ── ERA Import Drawer ────────────────────────────────────────────────────────
var _eraFile = null;

function toggleEraDrawer() {
    var drawer  = document.getElementById('eraImportDrawer');
    var overlay = document.getElementById('drawerOverlay');
    if (!drawer) return;
    var opening = !drawer.classList.contains('open');
    drawer.classList.toggle('open', opening);
    overlay.classList.toggle('open', opening);
    // Close Statements drawer if open
    var stmt = document.getElementById('statementsDrawer');
    if (stmt && opening) stmt.classList.remove('open');
}

function eraDrawerFileChosen(input) {
    var file = input.files && input.files[0];
    if (!file) return;
    _eraFile = file;

    var chosen = document.getElementById('eraFileChosen');
    chosen.style.display = 'block';
    chosen.textContent   = '✓ ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';

    // Drop-zone feedback
    var dz = document.getElementById('eraDropZone');
    if (dz) { dz.style.borderColor = 'var(--green)'; dz.style.background = '#f0fdf4'; }

    // Parse ERA for BPR date + TRN check number + payer ID
    var reader = new FileReader();
    reader.onload = function(evt) {
        var txt = evt.target.result;

        // BPR date (EFT deposit date)
        var bpr = txt.match(/BPR[^~]*\*(\d{8})[~*]/);
        if (bpr) {
            var raw = bpr[1];
            var iso = raw.substring(0,4) + '-' + raw.substring(4,6) + '-' + raw.substring(6,8);
            ['eraCheckDate','eraPostDate','eraDepositDate'].forEach(function(id){
                var el = document.getElementById(id);
                if (el) { el.value = iso; el.readOnly = true; el.style.background = '#f0fdf4'; }
            });
            var banner = document.getElementById('eraDateBanner');
            if (banner) {
                banner.style.display = 'block';
                banner.innerHTML = '<strong><?php echo xlt("EFT date auto-detected from BPR:"); ?> ' + iso + '</strong>'
                    + ' — <?php echo xlt("all date fields pre-filled. Verify before processing."); ?>';
            }
        }

        // Payer ID from N1*PR (payer name) or NM1*PR segments
        var payerIdMatch = txt.match(/N1\*PR\*([^*~]+)/);
        var payerName = payerIdMatch ? payerIdMatch[1].trim() : '';

        // Try to auto-select insurance by name match in dropdown
        if (payerName) {
            var sel = document.getElementById('eraInsuranceSel');
            if (sel) {
                var opts = Array.from(sel.options);
                var match = opts.find(function(o){
                    return o.text.toLowerCase().indexOf(payerName.toLowerCase()) !== -1;
                });
                if (match) {
                    sel.value = match.value;
                    document.getElementById('eraInsuranceId').value = match.value;
                }
            }
        }

        eraCheckCanProcess();
    };
    reader.readAsText(file);
}

function eraInsSelChanged(sel) {
    document.getElementById('eraInsuranceId').value = sel.value;
    eraCheckCanProcess();
}

function eraCheckCanProcess() {
    var hasFile = !!_eraFile;
    var hasIns  = !!document.getElementById('eraInsuranceId').value;
    var hasDates = document.getElementById('eraCheckDate').value &&
                   document.getElementById('eraPostDate').value &&
                   document.getElementById('eraDepositDate').value;
    var btn = document.getElementById('eraProcessBtn');
    if (btn) btn.disabled = !(hasFile && hasIns && hasDates);
}

function eraDrawerProcess() {
    if (!_eraFile) return;
    var insId    = document.getElementById('eraInsuranceId').value;
    var checkDt  = document.getElementById('eraCheckDate').value;
    var postDt   = document.getElementById('eraPostDate').value;
    var depositDt= document.getElementById('eraDepositDate').value;
    var without  = document.getElementById('eraWithout').checked ? '1' : '0';

    if (!insId) { showToast('<?php echo xlt("Please select an insurance company."); ?>', 'error'); return; }
    if (!checkDt || !postDt || !depositDt) { showToast('<?php echo xlt("All three date fields are required."); ?>', 'error'); return; }

    var btn = document.getElementById('eraProcessBtn');
    if (btn) { btn.disabled = true; btn.textContent = '<?php echo xlt("Processing..."); ?>'; }

    var fd = new FormData();
    fd.append('ajax_era_process',  '1');
    fd.append('csrf_token_form',   '<?php echo attr(OpenEMR\Common\Csrf\CsrfUtils::collectCsrfToken()); ?>');
    fd.append('era_file',          _eraFile);
    fd.append('hidden_type_code',  insId);
    fd.append('check_date',        checkDt);
    fd.append('post_to_date',      postDt);
    fd.append('deposit_date',      depositDt);
    fd.append('form_without',      without);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'new_payment.php', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        if (btn) { btn.disabled = false; btn.textContent = '<?php echo xlt("Process ERA File"); ?>'; }

        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch(e) {
            showToast('<?php echo xlt("Server error parsing ERA."); ?>', 'error');
            console.error('ERA process error:', xhr.responseText.substring(0,400));
            return;
        }
        if (!resp.success) {
            showToast(resp.error || '<?php echo xlt("ERA processing failed."); ?>', 'error');
            return;
        }

        if (resp.already) {
            showToast('<?php echo xlt("Warning: This ERA was already uploaded."); ?>', 'error');
        } else {
            showToast('<?php echo xlt("ERA uploaded:"); ?> ' + resp.eraname + ' (' + resp.count + ' <?php echo xlt("claims"); ?>)', 'success');
        }

        // Open sl_eob_process.php in a new tab — drawer stays open on this page
        window.open(resp.process_url, '_blank');
        setTimeout(toggleEraDrawer, 800);
    };
    xhr.send(fd);
}

// Drag-and-drop on ERA drop zone
document.addEventListener('DOMContentLoaded', function() {
    var dz = document.getElementById('eraDropZone');
    if (!dz) return;
    dz.addEventListener('dragover', function(e){ e.preventDefault(); dz.classList.add('dragover'); });
    dz.addEventListener('dragleave', function(){ dz.classList.remove('dragover'); });
    dz.addEventListener('drop', function(e){
        e.preventDefault(); dz.classList.remove('dragover');
        var files = e.dataTransfer.files;
        if (files.length) {
            var fi = document.getElementById('eraFileInput');
            var dt = new DataTransfer();
            dt.items.add(files[0]);
            fi.files = dt.files;
            eraDrawerFileChosen(fi);
        }
    });
    // Wire date inputs to re-check processability
    ['eraCheckDate','eraPostDate','eraDepositDate','eraInsuranceSel'].forEach(function(id){
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', eraCheckCanProcess);
    });
});

// ── Search Payments Drawer ────────────────────────────────────────────────────
function togglePaymentSearch() {
    var drawer  = document.getElementById('paymentSearchDrawer');
    if (!drawer) return;
    var opening = !drawer.classList.contains('open');
    drawer.classList.toggle('open', opening);
    document.body.classList.toggle('search-open', opening);
}

// ── View All EOBs popup ───────────────────────────────────────────────────────
function openAllEobsPopup() {
    var modal = document.getElementById('allEobsModal');
    if (!modal) return;
    modal.style.display = 'flex';
    loadAllEobs();
}
function closeAllEobsPopup() {
    document.getElementById('allEobsModal').style.display = 'none';
}
function loadAllEobs() {
    var tbody = document.getElementById('allEobsTbody');
    var info  = document.getElementById('allEobsCount');
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text3);">Loading...</td></tr>';
    info.textContent = '';
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'new_payment.php?ajax_payment_search=1', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        var rows;
        try { rows = JSON.parse(xhr.responseText); } catch(e) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#dc2626;">Error loading EOBs</td></tr>';
            return;
        }
        info.textContent = rows.length + ' <?php echo xlt("result(s)"); ?>';
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text3);"><?php echo xlt("No EOBs found."); ?></td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(function(r) {
            var dt = r.check_date ? r.check_date.substring(0,10).replace(/(\d{4})-(\d{2})-(\d{2})/, '$2/$3/$1') : '—';
            var name = (r.payer_name || r.patient_name || '<?php echo xlt("Unknown"); ?>').toUpperCase();
            var amt  = '$' + parseFloat(r.pay_total||0).toFixed(2);
            var statusCls = r.post_status === 'posted' ? 'badge-ok' : r.post_status === 'partial' ? 'badge-partial' : 'badge-draft';
            var statusLbl = r.post_status === 'posted' ? '<?php echo xlt("Posted"); ?>' : r.post_status === 'partial' ? '<?php echo xlt("Partial"); ?>' : '<?php echo xlt("Draft"); ?>';
            return '<tr style="cursor:pointer;" onclick="closeAllEobsPopup();loadSavedEob(' + parseInt(r.session_id) + ')">'
                + '<td style="font-size:11.5px;color:var(--text2);">' + escHtml(dt) + '</td>'
                + '<td style="font-weight:600;">' + escHtml(name) + '</td>'
                + '<td style="font-family:monospace;font-size:11px;">' + escHtml(r.reference || '—') + '</td>'
                + '<td style="font-family:monospace;">' + escHtml(amt) + '</td>'
                + '<td style="font-size:11px;">' + escHtml(r.payment_method || '—') + '</td>'
                + '<td><span class="' + statusCls + '" style="font-size:10px;padding:2px 7px;border-radius:4px;">' + escHtml(statusLbl) + '</span></td>'
                + '</tr>';
        }).join('');
    };
    xhr.send();
}

function drawerSearch() {
    var payer    = document.getElementById('dPayer').value;
    var checkNum = document.getElementById('dCheckNum').value.trim();
    var dateFrom = document.getElementById('dDateFrom').value.trim();
    var dateTo   = document.getElementById('dDateTo').value.trim();
    var amount   = document.getElementById('dAmount').value.trim();
    var method   = document.getElementById('dMethod').value;
    var status   = (document.querySelector('input[name="dStatus"]:checked') || {}).value || '';

    var url = 'new_payment.php?ajax_payment_search=1'
        + (payer    ? '&payer_id='   + encodeURIComponent(payer)    : '')
        + (checkNum ? '&check_num='  + encodeURIComponent(checkNum) : '')
        + (dateFrom ? '&date_from='  + encodeURIComponent(dateFrom) : '')
        + (dateTo   ? '&date_to='    + encodeURIComponent(dateTo)   : '')
        + (amount   ? '&amount='     + encodeURIComponent(amount)   : '')
        + (method   ? '&method='     + encodeURIComponent(method)   : '')
        + (status   ? '&status='     + encodeURIComponent(status)   : '');

    var resultsEl = document.getElementById('drawerResults');
    var countEl   = document.getElementById('drawerResultCount');
    resultsEl.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text3);font-size:12px;">'
        + '<svg style="animation:spin .8s linear infinite;display:inline-block;vertical-align:middle;margin-right:6px;" width="14" height="14" viewBox="0 0 24 24" fill="var(--purple)"><path d="M12 2a10 10 0 1 0 10 10H20a8 8 0 1 1-8-8V2z"/></svg>'
        + '<?php echo xlt("Searching..."); ?></div>';
    countEl.style.display = 'none';

    var xhr = new XMLHttpRequest();
    xhr.open('GET', url, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        var rows;
        try { rows = JSON.parse(xhr.responseText); } catch(e) {
            resultsEl.innerHTML = '<div style="color:var(--red);font-size:12px;padding:12px;"><?php echo xlt("Search error. Please try again."); ?></div>';
            return;
        }
        countEl.textContent = rows.length + ' <?php echo xlt("result(s)"); ?>';
        countEl.style.display = 'block';
        if (!rows.length) {
            resultsEl.innerHTML = '<div style="font-size:11px;color:var(--text3);text-align:center;padding:18px 0;"><?php echo xlt("No payments found."); ?></div>';
            return;
        }
        resultsEl.innerHTML = rows.map(function(r) {
            var dt    = r.check_date ? r.check_date.substring(0,10) : '\u2014';
            var parts = dt.split('-');
            var dtFmt = parts.length === 3 ? parts[1]+'/'+parts[2]+'/'+parts[0] : dt;
            var statusBadge = '';

if (r.post_status === 'posted') {

    if (parseFloat(r.open_balance || 0) > 0.01) {

        statusBadge =
            '<span class="eob-hist-status partial">' +
            '◐ <?php echo xlt("Partially Posted"); ?>' +
            '</span>';

                } else {

                    statusBadge =
                        '<span class="eob-hist-status posted">' +
                        '● <?php echo xlt("Posted"); ?>' +
                        '</span>';
                }

            } else {

                statusBadge =
                    '<span class="eob-hist-status draft">' +
                    '◐ <?php echo xlt("Draft"); ?>' +
                    '</span>';
            }
            return '<div class="drawer-result-item" onclick="loadSavedEob(' + parseInt(r.session_id,10) + ')">'
                + '<div class="dr-amount">$' + escHtml(parseFloat(r.pay_total||0).toFixed(2)) + '</div>'
                + '<div class="dr-payer">' + escHtml((r.payer_name || r.patient_name || '<?php echo xlt("Unknown"); ?>').toUpperCase()) + '</div>'
                + '<div class="dr-ref">' + escHtml(r.reference||'\u2014') + ' &nbsp;\u00b7&nbsp; ' + dtFmt + '</div>'
                + '<div style="margin-top:4px;display:flex;align-items:center;gap:8px;">'
                + statusBadge
                + '<span style="font-size:10px;color:var(--text3);">' + r.enc_count + ' <?php echo xlt("enc"); ?>' + (r.unassigned && parseFloat(r.unassigned) !== 0 ? ' &nbsp;·&nbsp; <span style="color:var(--amber);">\$' + Math.abs(parseFloat(r.unassigned)).toFixed(2) + ' <?php echo xlt("unassigned"); ?></span>' : '') + '</span>'
                + '</div></div>';
        }).join('');
    };
    xhr.send();
}

function clearDrawerSearch() {
    document.getElementById('dPayer').value    = '';
    document.getElementById('dCheckNum').value = '';
    document.getElementById('dDateFrom').value = '';
    document.getElementById('dDateTo').value   = '';
    document.getElementById('dAmount').value   = '';
    document.getElementById('dMethod').value   = '';
    var allRadio = document.getElementById('dStatusAll');
    if (allRadio) allRadio.checked = true;
    document.getElementById('drawerResults').innerHTML =
        '<div style="font-size:11px;color:var(--text3);text-align:center;padding:18px 0;"><?php echo xlt("Enter search criteria and click Search"); ?></div>';
    document.getElementById('drawerResultCount').style.display = 'none';
}

// ── Form submission: Post / Finish ────────────────────────────────────────────

/**
 * Serialize the allocation grid into the exact hidden field names that
 * DistributionInsert() in payment.inc.php reads. Field names confirmed
 * directly from payment.inc.php source:
 *
 *   HiddenEncounter{n}  — encounter number
 *   HiddenIns{n}        — insurance level (1/2/3 or 0 for patient)
 *   HiddenCodetype{n}   — code type (CPT4, etc.)
 *   HiddenCode{n}       — procedure code
 *   HiddenModifier{n}   — modifier
 *   Payment{n}          — insurance/patient payment amount (pay_amount)
 *   AdjAmount{n}        — adjustment amount (adj_amount)
 *   Deductible{n}       — patient deductible amount (memo: "Deductible $x", account_code: "Deduct")
 *
 *   hidden_patient_code — pid, read globally per row (must be set for each row)
 *
 * Each billing row emits up to two numbered entries:
 *   1) Payment + Adjustment row  (if either is non-zero)
 *   2) Deductible row            (if non-zero, separate counter)
 *
 * Returns total number of rows injected.
 */
function injectDistributionFields(form) {
    // Remove any previously injected rows
    form.querySelectorAll('[data-dist-row]').forEach(function(el) {
        el.parentNode.removeChild(el);
    });

    // Set hidden_patient_code to the first charge's pid as the global fallback.
    // The PHP loop overrides it per-row using HiddenPatientCode{n}.
    var patCodeEl = document.getElementById('hidden_patient_code');
    var firstPid  = _allCharges.length ? (_allCharges[0].pid || '') : '';
    if (patCodeEl && firstPid) patCodeEl.value = firstPid;

    var rowNum = 0;

    function addHidden(name, val, rowTag) {
        var inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = name;
        inp.value = (val === null || val === undefined) ? '' : String(val);
        inp.setAttribute('data-dist-row', rowTag);
        form.appendChild(inp);
    }

    // Shared row fields (everything except the amount fields)
    function addRowBase(n, r) {
        addHidden('HiddenEncounter' + n, r.encounter  || '', n);
        addHidden('HiddenIns'       + n, r.ins_level  || 1,  n);
        addHidden('HiddenCodetype'  + n, r.code_type  || 'CPT4', n);
        addHidden('HiddenCode'      + n, r.code       || '', n);
        addHidden('HiddenModifier'  + n, r.modifier   || '', n);
    }

    _allCharges.forEach(function(r) {
        var bid     = r.billing_id;
        var g       = function(id){ return parseFloat((document.getElementById(id+'_'+bid)||{value:'0'}).value)||0; };
        var pmt     = g('alloc');
        var adj     = g('adj');
        var ded     = g('ded');
        var coins   = g('coins');
        var copay   = g('copay');
        var takebk  = g('takebk');
        var npChk   = document.getElementById('np_'         + bid);
        var npReasEl= document.getElementById('npreason_'   + bid);
        var npNoteEl= document.getElementById('npnote_'     + bid);
        var npFuEl  = document.getElementById('npfollowup_' + bid);
        var isNp    = npChk && npChk.checked;
        var npReason= npReasEl ? npReasEl.value : '';
        var npNote  = npNoteEl ? npNoteEl.value.trim() : '';
        var npFu    = npFuEl  && npFuEl.checked;

        var hasAmt  = pmt || adj || ded || coins || copay || takebk;
        if (!hasAmt && !isNp) return; // nothing entered

        // ── Row: Insurance Payment + Contractual Adj ──────────────────────────
        if (pmt || adj) {
            rowNum++;
            addRowBase(rowNum, r);
            addHidden('Payment'          + rowNum, pmt.toFixed(2), rowNum);
            addHidden('AdjAmount'        + rowNum, adj.toFixed(2), rowNum);
            addHidden('HiddenPatientCode'+ rowNum, r.pid || '',    rowNum);
        }

        // ── Row: Patient Deductible (account_code = Deduct) ──────────────────
        if (ded) {
            rowNum++;
            addRowBase(rowNum, r);
            addHidden('Payment'          + rowNum, '0',            rowNum);
            addHidden('AdjAmount'        + rowNum, '0',            rowNum);
            addHidden('Deductible'       + rowNum, ded.toFixed(2), rowNum);
            addHidden('HiddenPatientCode'+ rowNum, r.pid || '',    rowNum);
        }

        // ── Row: Coinsurance (patient responsibility, account_code = PP) ──────
        if (coins) {
            rowNum++;
            addRowBase(rowNum, r);
            addHidden('Payment'          + rowNum, coins.toFixed(2), rowNum);
            addHidden('AdjAmount'        + rowNum, '0',              rowNum);
            addHidden('HiddenType'       + rowNum, 'COINS',          rowNum); // memo tag
            addHidden('HiddenPatientCode'+ rowNum, r.pid || '',      rowNum);
        }

        // ── Row: Copay (patient responsibility) ───────────────────────────────
        if (copay) {
            rowNum++;
            addRowBase(rowNum, r);
            addHidden('Payment'          + rowNum, copay.toFixed(2), rowNum);
            addHidden('AdjAmount'        + rowNum, '0',              rowNum);
            addHidden('HiddenType'       + rowNum, 'COPAY',          rowNum);
            addHidden('HiddenPatientCode'+ rowNum, r.pid || '',      rowNum);
        }

        // ── Row: Takeback / Recoupment (negative payment) ─────────────────────
        if (takebk) {
            rowNum++;
            addRowBase(rowNum, r);
            addHidden('Takeback'         + rowNum, takebk.toFixed(2), rowNum);
            addHidden('Payment'          + rowNum, '0',               rowNum);
            addHidden('AdjAmount'        + rowNum, '0',               rowNum);
            addHidden('HiddenPatientCode'+ rowNum, r.pid || '',       rowNum);
        }

        // ── Row: Non-payment / Denial / Follow-up ─────────────────────────────
        if (isNp) {
            rowNum++;
            addRowBase(rowNum, r);
            addHidden('Payment'          + rowNum, '0',                          rowNum);
            addHidden('AdjAmount'        + rowNum, '0',                          rowNum);
            addHidden('FollowUp'         + rowNum, 'y',                          rowNum);
            addHidden('FollowUpReason'   + rowNum, npReason + (npNote ? ' — ' + npNote : ''), rowNum);
            addHidden('HiddenPatientCode'+ rowNum, r.pid || '',                  rowNum);
            // Flag encounter for follow-up in form_encounter if needed
        }
    });

    return rowNum;
}

/**
 * Sync all payment header fields into the canonical form inputs.
 * The form may be in insurance or patient mode; we normalise everything
 * to the field names the PHP handler reads.
 */
function syncHeaderToForm(form) {
    // payment_amount — always keep in sync
    var amtIns = document.getElementById('payment_amount');
    var amtPat = document.getElementById('payment_amount_pat');
    var finalAmt = (amtIns && amtIns.value) ? amtIns.value
                 : (amtPat && amtPat.value) ? amtPat.value : '0';
    if (amtIns) amtIns.value = finalAmt;

    // check_number — patient mode uses check_number_pat
    var chkIns = document.getElementById('check_number');
    var chkPat = document.getElementById('check_number_pat');
    if (chkIns && !chkIns.value && chkPat && chkPat.value) {
        chkIns.value = chkPat.value;
    }

    // post_to_date
    var pdIns = document.getElementById('post_to_date');
    var pdPat = document.getElementById('post_to_date_pat');
    if (pdIns && !pdIns.value && pdPat && pdPat.value) {
        pdIns.value = pdPat.value;
    }

    // payment_method
    var pmIns = document.getElementById('payment_method');
    var pmPat = document.getElementById('payment_method_pat');
    if (pmIns && !pmIns.value && pmPat && pmPat.value) {
        pmIns.value = pmPat.value;
    }

    // type_name — ensure radio value is set in the hidden field the PHP reads
    var rb = document.querySelector('input[name="type_name"]:checked');
    if (rb) {
        // The PHP reads $_POST['type_name'] from the radio directly — that's fine.
        // Also sync hidden_type_code for insurance mode.
        if (rb.value === 'insurance') {
            var carrSel = document.getElementById('hidden_type_code_sel');
            var carrHid = document.getElementById('hidden_type_code');
            if (carrSel && carrHid) carrHid.value = carrSel.value;
        }
    }

    // hidden_patient_code — for insurance EOBs, seed from the first open charge's pid
    // (injectDistributionFields will override per row via HiddenPatientCode{n})
    var patCd = document.getElementById('hidden_patient_code');
    if (patCd && (!patCd.value || patCd.value === '0') && _allCharges.length) {
        patCd.value = _allCharges[0].pid || '';
    }

    // session payment_id — must be current active session
    var pidHid = document.getElementById('payment_id');
    if (pidHid && !pidHid.value && _activeSessionId) {
        pidHid.value = _activeSessionId;
    }
}

// ── Collect payment header fields into a plain object ────────────────────────
function collectHeaderFields() {
    var rb         = document.querySelector('input[name="type_name"]:checked');
    var type_name  = rb ? rb.value : 'insurance';
    var isIns      = type_name === 'insurance';
    var payer_id   = 0;
    var patient_id = 0;

    if (isIns) {
        var carrSel = document.getElementById('hidden_type_code_sel');
        payer_id = (carrSel && carrSel.value) ? parseInt(carrSel.value, 10) : 0;
    } else {
        var ptHid = document.getElementById('hidden_patient_code');
        patient_id = (ptHid && ptHid.value) ? parseInt(ptHid.value, 10) : 0;
    }

    // Amount — use whichever field is visible
    var amtEl = document.getElementById(isIns ? 'payment_amount' : 'payment_amount_pat')
             || document.getElementById('payment_amount');
    var chkEl = document.getElementById(isIns ? 'check_number' : 'check_number_pat')
             || document.getElementById('check_number');
    var dtEl  = document.getElementById('check_date');
    var pmEl  = document.getElementById('payment_method');
    var descEl= document.getElementById('description');

    return {
        type_name:      type_name,
        payer_id:       payer_id,
        patient_id:     patient_id,
        pay_total:      amtEl  ? amtEl.value.replace(/[^0-9.]/g,'') : '0',
        check_number:   chkEl  ? chkEl.value.trim() : '',
        check_date:     dtEl   ? dtEl.value.trim()  : '',
        payment_method: pmEl   ? pmEl.value         : 'check',
        description:    descEl ? descEl.value.trim(): ''
    };
}

// ── Create a brand-new ar_session from the current header fields ──────────────
function createNewEob() {
    var fields = collectHeaderFields();

    // Validate before hitting the server
    if (fields.type_name === 'insurance' && !fields.payer_id) {
        showToast('<?php echo xlt("Please select a Carrier first."); ?>', 'error');
        document.getElementById('hidden_type_code_sel').focus();
        return;
    }
    if (fields.type_name === 'patient' && !fields.patient_id) {
        showToast('<?php echo xlt("Please select a Patient first."); ?>', 'error');
        return;
    }

    var btn = document.getElementById('btnNewEob');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }

    var fd = new FormData();
    fd.append('ajax_create_session', '1');
    Object.keys(fields).forEach(function(k){ fd.append(k, fields[k]); });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'new_payment.php', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        if (btn) { btn.disabled = false; btn.style.opacity = ''; }

        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch(e) {
            showToast('<?php echo xlt("Server error creating EOB."); ?>', 'error');
            console.error('createNewEob response:', xhr.responseText.substring(0,400));
            return;
        }
        if (!resp.success) {
            showToast(resp.error || '<?php echo xlt("Could not create EOB."); ?>', 'error');
            return;
        }

        var sid = resp.session_id;

        // Update hidden payment_id field so PostPayments knows what to post to
        var pidHid = document.getElementById('payment_id');
        if (pidHid) pidHid.value = sid;

        // Inject into sidebar as a new Draft item
        injectSidebarItem(sid, resp.session, fields);

        // Load the new session into the workspace (loads charges, shows panels)
        _activeSessionId = 0; // force reload
        loadSavedEob(sid);

        showToast('<?php echo xlt("New EOB created — enter allocations and click Post EOB."); ?>', 'success');
    };
    xhr.send(fd);
}

// ── Inject a new EOB item into the top of the Recent EOBs sidebar ─────────────
function injectSidebarItem(sid, session, fields) {
    var list = document.querySelector('.eob-hist-list');
    if (!list) return;

    var dt  = fields.check_date || new Date().toISOString().substring(0,10);
    var ref = fields.check_number ? ' · EFT #' + escHtml(fields.check_number) : '';
    var amt = '$' + parseFloat(fields.pay_total || 0).toFixed(2);
    var name= (session && (session.payer_name || session.patient_name))
            ? escHtml((session.payer_name || session.patient_name).toUpperCase())
            : '<?php echo xlt("New Payment"); ?>';

    var div = document.createElement('div');
    div.className  = 'eob-hist-item';
    div.id         = 'eobHistItem_' + sid;
    div.style.cursor = 'pointer';
    div.onclick    = function(){ loadSavedEob(sid); };
    div.innerHTML  =
        '<div style="font-size:9.5px;color:var(--text3);margin-bottom:3px;">' + escHtml(dt) + '</div>'
      + '<div class="eob-hist-payer">' + name + '</div>'
      + '<div class="eob-hist-ref" style="font-size:10px;color:var(--text3);font-family:\'DM Mono\',monospace;">'
      +   'EFT' + ref + ref
      + '</div>'
      + '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:4px;">'
      +   '<div class="eob-hist-amount" style="font-family:\'DM Mono\',monospace;font-weight:700;font-size:13px;">' + amt + '</div>'
      +   '<span class="eob-hist-status draft">◐ <?php echo xlt("Draft"); ?></span>'
      + '</div>';

    // Insert at top of list (before first child)
    list.insertBefore(div, list.firstChild);
}

// ── Toast ────────────────────────────────────────────────────────────────────
var _toastTimer = null;
function showToast(msg, type) {
    var t  = document.getElementById('postToast');
    var tm = document.getElementById('postToastMsg');
    var ic = document.getElementById('postToastIcon');
    if (!t) return;
    t.className = 'show ' + (type || 'success');
    tm.textContent = msg;
    if (ic) ic.innerHTML = (type === 'error')
        ? '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>'
        : '<polyline points="20 6 9 17 4 12"/>';
    clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function(){ t.className = t.className.replace('show','').trim(); }, 4500);
}

// ── Sidebar refresh ───────────────────────────────────────────────────────────
function refreshSidebarItem(sid, srow) {
    if (!srow) return;
    var item = document.getElementById('eobHistItem_' + sid);
    if (!item) return;
    var amtEl   = item.querySelector('.eob-hist-amount');
    if (amtEl)   amtEl.textContent = '$' + parseFloat(srow.pay_total || 0).toFixed(2);
    var badge   = item.querySelector('.eob-hist-status');
    if (badge) {
        badge.className   = 'eob-hist-status posted';
        badge.textContent = '● <?php echo xlt("Posted"); ?>';
    }
    var refEl = item.querySelector('.eob-hist-ref');
    if (refEl && srow.enc_count) {
        var base = refEl.textContent.split('·')[0].trim();
        refEl.textContent = base + ' · ' + srow.enc_count + ' <?php echo xlt("Claims"); ?>';
    }
}

// ── Core AJAX submit ──────────────────────────────────────────────────────────
function submitEobAjax(mode) {
    var form = document.getElementById('new_payment') || document.forms[0];
    if (!form) return false;

    var pidHid    = document.getElementById('payment_id');
    var sessionId = (pidHid && parseInt(pidHid.value, 10)) || _activeSessionId || 0;

    // ── If no session exists yet, create one first then post ─────────────────
    if (!sessionId) {
        var fields = collectHeaderFields();
        if (fields.type_name === 'insurance' && !fields.payer_id) {
            showToast('<?php echo xlt("Please select a Carrier before posting."); ?>', 'error');
            return false;
        }
        if (fields.type_name === 'patient' && !fields.patient_id) {
            showToast('<?php echo xlt("Please select a Patient before posting."); ?>', 'error');
            return false;
        }

        // Disable buttons during create+post
        var btns = document.querySelectorAll('.btn-post,[onclick*="PostPayments"],[onclick*="FinishPayments"]');
        btns.forEach(function(b){ b.disabled = true; b.style.opacity = '0.6'; });

        var fd = new FormData();
        fd.append('ajax_create_session', '1');
        Object.keys(fields).forEach(function(k){ fd.append(k, fields[k]); });

        var createXhr = new XMLHttpRequest();
        createXhr.open('POST', 'new_payment.php', true);
        createXhr.onreadystatechange = function() {
            if (createXhr.readyState !== 4) return;
            btns.forEach(function(b){ b.disabled = false; b.style.opacity = ''; });
            var resp;
            try { resp = JSON.parse(createXhr.responseText); } catch(e) {
                showToast('<?php echo xlt("Error creating EOB session."); ?>', 'error');
                return;
            }
            if (!resp.success) {
                showToast(resp.error || '<?php echo xlt("Could not create EOB."); ?>', 'error');
                return;
            }
            // Session created — inject sidebar item, set IDs, then proceed to post
            injectSidebarItem(resp.session_id, resp.session, fields);
            _activeSessionId = resp.session_id;
            if (pidHid) pidHid.value = resp.session_id;
            // Recurse: now we have a session, submit normally
            submitEobAjax(mode);
        };
        createXhr.send(fd);
        return false;
    }

    // ── Session exists — update its header fields before posting ─────────────
    // This keeps check#, amount, date in sync even if the biller edited them
    // after originally creating the session.
    var headerFields = collectHeaderFields();
    var updateFd = new FormData();
    updateFd.append('ajax_update_session', '1');
    updateFd.append('session_id', sessionId);
    Object.keys(headerFields).forEach(function(k){ updateFd.append(k, headerFields[k]); });

    var rowCount = injectDistributionFields(form);
    if (!rowCount && mode === 'PostPayments') {
        showToast('<?php echo xlt("No allocations entered. Enter a payment, adjustment, or deductible first."); ?>', 'error');
        return false;
    }

    syncHeaderToForm(form);

    // Collect form data (includes all injected hidden fields)
    var data = new FormData(form);
    data.set('mode', mode);
    data.set('payment_id', sessionId);

    // Disable submit buttons while in-flight
    var btns = document.querySelectorAll('.btn-post,[onclick*="PostPayments"],[onclick*="FinishPayments"]');
    btns.forEach(function(b){ b.disabled = true; b.style.opacity = '0.6'; });

    // First update the session header, then post
    var updateXhr = new XMLHttpRequest();
    updateXhr.open('POST', 'new_payment.php', true);
    updateXhr.onreadystatechange = function() {
        if (updateXhr.readyState !== 4) return;
        // Proceed to post regardless of update result (non-critical)
        doPost(data, btns, mode, sessionId);
    };
    updateXhr.send(updateFd);
    return false;
}

function doPost(data, btns, mode, sessionId) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'new_payment.php', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        btns.forEach(function(b){ b.disabled = false; b.style.opacity = ''; });

        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch(e) {
            showToast('<?php echo xlt("Server error — see browser console."); ?>', 'error');
            console.error('PostEOB raw response:', xhr.responseText.substring(0, 800));
            return;
        }

        if (!resp.success) {
            showToast(resp.error || '<?php echo xlt("Posting failed."); ?>', 'error');
            return;
        }

        // Success feedback
        var rowWord = resp.rows === 1 ? '<?php echo xlt("entry"); ?>' : '<?php echo xlt("entries"); ?>';
        showToast('<?php echo xlt("Posted successfully"); ?> — ' + resp.rows + ' ' + rowWord + ' <?php echo xlt("recorded"); ?>', 'success');

        // Refresh sidebar badge for this session
        refreshSidebarItem(resp.session_id, resp.sidebar_row);

        // Reload workspace panels to reflect newly posted data
        if (resp.session_id) {
            _activeSessionId = 0; // reset so loadSavedEob forces a reload
            loadSavedEob(resp.session_id);
        }

        // Post & Print Summary: reload the EOB instead of redirecting to broken edit_payment.php
        if (resp.mode === 'finish') {
            showToast('<?php echo xlt("Posted and finalized."); ?>', 'success');
        }
    };
    xhr.send(data);
}

function PostPayments()  { return submitEobAjax('PostPayments');  }
function FinishPayments(){ return submitEobAjax('FinishPayments'); }


// ── Sample EOB banner ────────────────────────────────────────────────────────
function showSampleEob() {
    var banner = document.getElementById('sampleBanner');
    if (banner) banner.style.display = 'block';
    // Scroll to top so user sees it
    window.scrollTo({top:0, behavior:'smooth'});
}

document.addEventListener('DOMContentLoaded', function(){
    // Wire amount inputs
    var pa  = document.getElementById('payment_amount');
    var pa2 = document.getElementById('payment_amount_pat');
    if (pa)  pa.addEventListener('input',  recalcSummary);
    if (pa2) pa2.addEventListener('input', function(){ var pa=document.getElementById('payment_amount'); if(pa)pa.value=this.value; recalcSummary(); });
    // Apply correct mode on initial load (sets pat-mode class, renames labels, etc.)
    var initRb = document.querySelector('input[name="type_name"]:checked');
    if (initRb) {
        sourceChanged._skipReset = true;
        sourceChanged(initRb.value);
        sourceChanged._skipReset = false;
    }
    // Init: if carrier already pre-selected, load its charges
    var carr = document.getElementById('hidden_type_code_sel');
    if (carr && carr.value) carrierChanged(carr.value);
    // Init: if patient already pre-selected, load their charges
    var pid = document.getElementById('selectedPid');
    var srcPat = document.getElementById('rb_patient');
    if (pid && pid.value && srcPat && srcPat.checked) {
        loadChargesFromServer('?ajax_patient_charges=1&pid='+encodeURIComponent(pid.value), 'patient');
    }
});
</script>
<?php $oemr_ui->oeBelowContainerDiv();?>

<!-- ── Statements / Invoice Search Drawer ────────────────────────────────── -->
<div id="statementsDrawer">
    <div class="stmt-drawer-hd">
        <h3>
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
            <?php echo xlt("Invoice Search"); ?>
        </h3>
        <button type="button" onclick="toggleStatementsDrawer()" style="background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:20px;line-height:1;padding:0 4px;">&times;</button>
    </div>
    <div class="stmt-search-bar">
        <div class="stmt-search-row">
            <div>
                <label class="stmt-lbl" for="stmtName"><?php echo xlt("Patient Name"); ?></label>
                <input type="text" class="stmt-inp" id="stmtName" placeholder="<?php echo xla('Last, First or last name'); ?>">
            </div>
            <div>
                <label class="stmt-lbl" for="stmtPid"><?php echo xlt("Patient ID"); ?></label>
                <input type="text" class="stmt-inp" id="stmtPid" placeholder="<?php echo xla('PID number'); ?>">
            </div>
            <div>
                <label class="stmt-lbl" for="stmtEncounter"><?php echo xlt("Encounter #"); ?></label>
                <input type="text" class="stmt-inp" id="stmtEncounter" placeholder="<?php echo xla('Encounter number'); ?>">
            </div>
        </div>
        <div class="stmt-search-row2">
            <div>
                <label class="stmt-lbl" for="stmtDateFrom"><?php echo xlt("Date From"); ?></label>
                <input type="date" class="stmt-inp" id="stmtDateFrom">
            </div>
            <div>
                <label class="stmt-lbl" for="stmtDateTo"><?php echo xlt("Date To"); ?></label>
                <input type="date" class="stmt-inp" id="stmtDateTo">
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <div>
                    <label class="stmt-lbl"><?php echo xlt("Show"); ?></label>
                    <select class="stmt-inp" id="stmtCategory" style="width:auto;">
                        <option value=""><?php echo xlt("All"); ?></option>
                        <option value="due"><?php echo xlt("Balance Due"); ?></option>
                    </select>
                </div>
                <button type="button" class="stmt-search-btn" onclick="stmtSearch()">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="display:inline;vertical-align:middle;margin-right:4px;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <?php echo xlt("Search"); ?>
                </button>
            </div>
        </div>
    </div>
    <div class="stmt-drawer-body">
        <div id="stmtResultsInfo"></div>
        <table id="stmtResultsTable">
            <thead><tr>
                <th><?php echo xlt("Patient"); ?></th>
                <th><?php echo xlt("Invoice"); ?></th>
                <th><?php echo xlt("Svc Date"); ?></th>
                <th><?php echo xlt("Last Stmt"); ?></th>
                <th class="r"><?php echo xlt("Charge"); ?></th>
                <th class="r"><?php echo xlt("Adj"); ?></th>
                <th class="r"><?php echo xlt("Paid"); ?></th>
                <th class="r"><?php echo xlt("Balance"); ?></th>
                <th class="r"><?php echo xlt("Stmts"); ?></th>
            </tr></thead>
            <tbody id="stmtResultsBody">
                <tr><td colspan="9" class="stmt-empty"><?php echo xlt("Enter search criteria above and click Search"); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- ── ERA Import Drawer ──────────────────────────────────────────────── -->
<div id="eraImportDrawer">
    <div class="era-drawer-hd">
        <h3>
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            <?php echo xlt("Import ERA File"); ?>
        </h3>
        <button type="button" onclick="toggleEraDrawer()" style="background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:20px;line-height:1;padding:0 4px;">&times;</button>
    </div>
    <div class="era-drawer-body">

        <!-- Drop zone -->
        <div id="eraDropZone" onclick="document.getElementById('eraFileInput').click()">
            <svg class="dz-icon" width="36" height="36" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/><polyline points="8 6 12 2 16 6"/></svg>
            <div class="dz-label"><?php echo xlt("Click or drag an .835 / .zip ERA file here"); ?></div>
            <div class="dz-sub"><?php echo xlt("X12 835 format · .edi .txt .835 .zip accepted"); ?></div>
        </div>
        <div id="eraFileChosen"></div>
        <input type="file" id="eraFileInput" accept=".era" style="display:none" onchange="eraDrawerFileChosen(this)">

        <!-- Auto-date banner -->
        <div id="eraDateBanner"></div>

        <!-- Insurance -->
        <label class="era-lbl" for="eraInsuranceName"><?php echo xlt("Insurance Company"); ?></label>
        <select class="era-inp" id="eraInsuranceSel" onchange="eraInsSelChanged(this)">
            <option value=""><?php echo xlt("— auto-detected from ERA, or select —"); ?></option>
            <?php foreach ($insurance_companies_list as $ic): ?>
            <option value="<?php echo attr($ic['id']); ?>"><?php echo text($ic['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="hidden" id="eraInsuranceId" value="">

        <!-- Dates -->
        <label class="era-lbl"><?php echo xlt("Dates"); ?> <span style="font-weight:400;color:var(--text3);text-transform:none;"><?php echo xlt("(auto-filled from BPR segment)"); ?></span></label>
        <div class="era-2col">
            <div>
                <label class="era-lbl" for="eraCheckDate"><?php echo xlt("Check/EFT Date"); ?></label>
                <input type="date" class="era-inp" id="eraCheckDate">
            </div>
            <div>
                <label class="era-lbl" for="eraPostDate"><?php echo xlt("Post To Date"); ?></label>
                <input type="date" class="era-inp" id="eraPostDate">
            </div>
        </div>
        <div style="margin-top:10px;">
            <label class="era-lbl" for="eraDepositDate"><?php echo xlt("Deposit Date"); ?></label>
            <input type="date" class="era-inp" id="eraDepositDate">
        </div>

        <!-- Without update checkbox -->
        <div style="display:flex;align-items:center;gap:8px;margin-top:14px;">
            <input type="checkbox" id="eraWithout" style="accent-color:var(--purple);width:15px;height:15px;">
            <label for="eraWithout" style="font-size:12px;color:var(--text2);cursor:pointer;"><?php echo xlt("Without Update (preview only — no data saved)"); ?></label>
        </div>

        <!-- Process button -->
        <button type="button" class="era-process-btn" id="eraProcessBtn" onclick="eraDrawerProcess()" disabled>
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
            <?php echo xlt("Process ERA File"); ?>
        </button>
        <p style="font-size:10.5px;color:var(--text3);margin-top:10px;text-align:center;"><?php echo xlt("Results open in a new tab via sl_eob_process.php"); ?></p>
    </div>
</div>

<div id="postToast">
    <svg class="toast-icon" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" id="postToastIcon"></svg>
    <span id="postToastMsg"></span>
</div>
<!-- ── All EOBs Popup ─────────────────────────────────────────────────────── -->
<div id="allEobsModal" style="display:none;position:fixed;inset:0;z-index:19999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;" onclick="if(event.target===this)closeAllEobsPopup()">
  <div style="background:#fff;border-radius:14px;box-shadow:0 16px 60px rgba(0,0,0,.25);width:780px;max-width:96vw;max-height:85vh;display:flex;flex-direction:column;overflow:hidden;">
    <div style="background:linear-gradient(135deg,#3b1f7a,#6d28d9);color:#fff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:10px;">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <span style="font-size:15px;font-weight:700;"><?php echo xlt('All EOBs'); ?></span>
        <span id="allEobsCount" style="font-size:11px;opacity:.75;margin-left:6px;"></span>
      </div>
      <button onclick="closeAllEobsPopup()" style="background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:6px;width:28px;height:28px;cursor:pointer;font-size:16px;">×</button>
    </div>
    <div style="overflow-y:auto;flex:1;padding:0;">
      <table style="width:100%;border-collapse:collapse;font-size:12.5px;">
        <thead>
          <tr style="background:#f8f6fd;position:sticky;top:0;z-index:1;">
            <th style="padding:10px 14px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#7c3aed;border-bottom:2px solid #e4ddf5;"><?php echo xlt('Date'); ?></th>
            <th style="padding:10px 14px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#7c3aed;border-bottom:2px solid #e4ddf5;"><?php echo xlt('Payer / Patient'); ?></th>
            <th style="padding:10px 14px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#7c3aed;border-bottom:2px solid #e4ddf5;"><?php echo xlt('Reference'); ?></th>
            <th style="padding:10px 14px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#7c3aed;border-bottom:2px solid #e4ddf5;"><?php echo xlt('Amount'); ?></th>
            <th style="padding:10px 14px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#7c3aed;border-bottom:2px solid #e4ddf5;"><?php echo xlt('Method'); ?></th>
            <th style="padding:10px 14px;text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#7c3aed;border-bottom:2px solid #e4ddf5;"><?php echo xlt('Status'); ?></th>
          </tr>
        </thead>
        <tbody id="allEobsTbody">
          <tr><td colspan="6" style="text-align:center;padding:20px;color:#9ca3af;"><?php echo xlt('Loading...'); ?></td></tr>
        </tbody>
      </table>
    </div>
    <div style="padding:12px 20px;border-top:1px solid #f0ebff;background:#faf8ff;font-size:11.5px;color:#9ca3af;flex-shrink:0;">
      <?php echo xlt('Click any row to open that EOB for editing.'); ?>
    </div>
  </div>
</div>
<style>
.badge-ok     { background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0; }
.badge-partial{ background:#fffbeb;color:#d97706;border:1px solid #fde68a; }
.badge-draft  { background:#faf5ff;color:#7c3aed;border:1px solid #ddd6fe; }
#allEobsTbody tr:hover { background:#f5f3ff; }
body.search-open .wb-header-actions { margin-right:328px; transition:margin-right .28s cubic-bezier(.4,0,.2,1); }
</style>
</body>
</html>