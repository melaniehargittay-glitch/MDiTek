<?php
/**
 * non_patient_detail.php
 * Summary view for a single person table record.
 * Includes "Upgrade to Patient" action that inserts into patient_data.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

require_once(dirname(__FILE__) . "/../../globals.php");
require_once "$srcdir/user.inc.php";
require_once "$srcdir/options.inc.php";
require_once "$srcdir/pid.inc.php";      // for assignPid() / newPatientForm helpers

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// ── CSRF-protected POST: Save field edits ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_field'])) {
    header('Content-Type: application/json');

    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(array('success' => false, 'error' => 'Invalid CSRF token.'));
        exit;
    }

    $id = (int)($_POST['person_id'] ?? 0);
    if (!$id) {
        echo json_encode(array('success' => false, 'error' => 'Invalid person ID.'));
        exit;
    }

    // Whitelist of editable columns → expected type
    $allowed = array(
        'fname'        => 'str',
        'mi'           => 'str',
        'lname'        => 'str',
        'suffix'       => 'str',
        'address1'     => 'str',
        'address2'     => 'str',
        'city'         => 'str',
        'state'        => 'str',
        'zip'          => 'str',
        'hphone'       => 'str',
        'dob'          => 'date',
        'gender'       => 'str',
        'ssn'          => 'str',
        'send_stmt'    => 'bool',
        'inactv_date'  => 'date',
        'retired_date' => 'date',
    );

    $card   = isset($_POST['card'])  ? $_POST['card']  : '';
    $fields = isset($_POST['fields']) && is_array($_POST['fields']) ? $_POST['fields'] : array();

    if (empty($fields)) {
        echo json_encode(array('success' => false, 'error' => 'No fields provided.'));
        exit;
    }

    $set_parts = array();
    $bind      = array();
    foreach ($fields as $col => $val) {
        if (!array_key_exists($col, $allowed)) continue; // skip unknown columns
        $type = $allowed[$col];
        if ($type === 'date') {
            $val = trim($val);
            if ($val === '' || $val === 'NULL') { $val = null; }
            elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                echo json_encode(array('success' => false, 'error' => "Invalid date for $col."));
                exit;
            }
        } elseif ($type === 'bool') {
            $val = ($val === '1' || $val === 'true' || $val === true) ? 1 : 0;
        } else {
            $val = trim($val);
        }
        $set_parts[] = "`$col` = ?";
        $bind[]      = $val;
    }

    if (empty($set_parts)) {
        echo json_encode(array('success' => false, 'error' => 'No valid fields to update.'));
        exit;
    }

    $bind[] = $id;
    sqlStatement(
        "UPDATE person SET " . implode(', ', $set_parts) . " WHERE id = ?",
        $bind
    );

    // Return the updated record so the page can refresh display values
    $updated = sqlQuery("SELECT * FROM person WHERE id = ?", array($id));
    echo json_encode(array('success' => true, 'record' => $updated));
    exit;
}

// ── CSRF-protected POST: Upgrade to Patient ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upgrade'])) {

    header('Content-Type: application/json');

    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '')) {
        echo json_encode(array('success' => false, 'error' => 'Invalid CSRF token.'));
        exit;
    }
    if (!AclMain::aclCheckCore('patients', 'demo', '', array('write', 'addonly'))) {
        echo json_encode(array('success' => false, 'error' => 'Access denied.'));
        exit;
    }

    $id = (int)($_POST['person_id'] ?? 0);
    if (!$id) {
        echo json_encode(array('success' => false, 'error' => 'Invalid person ID.'));
        exit;
    }

    // Fetch the person record
    $person = sqlQuery("SELECT * FROM person WHERE id = ?", array($id));
    if (!$person) {
        echo json_encode(array('success' => false, 'error' => 'Person record not found.'));
        exit;
    }

    // Check not already a patient
    $already = false;
    if (!empty($person['pid'])) {
        $chk = sqlQuery("SELECT pid FROM patient_data WHERE pid = ?", array($person['pid']));
        if ($chk) { $already = true; }
    }
    if ($already) {
        echo json_encode(array('success' => false, 'error' => 'This person is already a patient.'));
        exit;
    }

    // ── Map person → patient_data fields ─────────────────────────────────────
    $new_pid = (!empty($person['pid']) && (int)$person['pid'] > 0) ? (int)$person['pid'] : null;

    // Use person_no as pubpid if it's a non-empty, non-NULL value
    $person_no_val = isset($person['person_no']) ? trim($person['person_no']) : '';
    $pubpid_val    = ($person_no_val !== '' && $person_no_val !== 'NULL') ? $person_no_val : '';

    // Gender map
    $gender_map = array('M' => 'Male', 'F' => 'Female', 'U' => 'Unknown');
    $sex = isset($gender_map[$person['gender']]) ? $gender_map[$person['gender']] : '';

    // Phone: strip non-digits, format as ###-###-####
    $raw_phone = preg_replace('/\D/', '', isset($person['hphone']) ? $person['hphone'] : '');
    $phone_fmt = '';
    if (strlen($raw_phone) === 10) {
        $phone_fmt = substr($raw_phone,0,3).'-'.substr($raw_phone,3,3).'-'.substr($raw_phone,6);
    } elseif ($raw_phone) {
        $phone_fmt = $raw_phone;
    }

    // DOB: treat zero-date and NULL as empty
    $dob_val = isset($person['dob']) ? $person['dob'] : '';
    if ($dob_val === '0000-00-00' || $dob_val === '0000-00-00 00:00:00') {
        $dob_val = '';
    }

    // SSN: treat literal "NULL" string from CSV import as empty
    $ssn_val = (isset($person['ssn']) && $person['ssn'] !== 'NULL') ? $person['ssn'] : '';

    $fields = array(
        'fname'           => isset($person['fname'])    ? $person['fname']    : '',
        'mname'           => isset($person['mi'])       ? $person['mi']       : '',
        'lname'           => isset($person['lname'])    ? $person['lname']    : '',
        'suffix'          => isset($person['suffix'])   ? $person['suffix']   : '',
        'street'          => isset($person['address1']) ? $person['address1'] : '',
        'street_line_2'   => isset($person['address2']) ? $person['address2'] : '',
        'city'            => isset($person['city'])     ? $person['city']     : '',
        'state'           => isset($person['state'])    ? $person['state']    : '',
        'postal_code'     => isset($person['zip'])      ? $person['zip']      : '',
        'phone_home'      => $phone_fmt,
        'DOB'             => $dob_val,
        'sex'             => $sex,
        'ss'              => $ssn_val,
        'status'          => 'single',
        'hipaa_notice'    => 'signed',
        'hipaa_voice'     => 'yes',
        'hipaa_mail'      => 'yes',
    );

    if ($pubpid_val !== '') {
        $fields['pubpid'] = $pubpid_val;
    }

    // Build INSERT cleanly
    $cols_arr = array();
    $vals_arr = array();
    $bind_arr = array();
    foreach ($fields as $col => $val) {
        $cols_arr[] = "`$col`";
        $vals_arr[] = '?';
        $bind_arr[] = $val;
    }
    if ($new_pid) {
        $cols_arr[] = '`pid`';
        $vals_arr[] = '?';
        $bind_arr[] = $new_pid;
    }
    $cols_arr[] = '`date`';
    $vals_arr[] = 'NOW()';

    $insert_sql = "INSERT INTO patient_data (" . implode(', ', $cols_arr) . ")
                   VALUES (" . implode(', ', $vals_arr) . ")";

    sqlStatement($insert_sql, $bind_arr);

    // Get the newly inserted pid / pubpid
    $inserted_pid = $new_pid;
    if (!$inserted_pid) {
        $new_rec = sqlQuery(
            "SELECT pid FROM patient_data WHERE lname = ? AND fname = ? ORDER BY pid DESC LIMIT 1",
            array($person['lname'], $person['fname'])
        );
        $inserted_pid = $new_rec ? (int)$new_rec['pid'] : 0;
    }

    // If no pubpid was set, update it to match pid now
    if (!$pubpid_val && $inserted_pid) {
        sqlStatement(
            "UPDATE patient_data SET pubpid = ? WHERE pid = ?",
            array((string)$inserted_pid, $inserted_pid)
        );
    }

    // Mark person record as now having this pid (link back)
    if ($inserted_pid) {
        sqlStatement("UPDATE person SET pid = ? WHERE id = ?", array($inserted_pid, $id));
    }

    echo json_encode(array(
        'success' => true,
        'pid'     => $inserted_pid,
        'webroot' => $GLOBALS['web_root'],
    ));
    exit;
}

// ── Load person record for display ───────────────────────────────────────────
$person_id = (int)($_GET['id'] ?? 0);
$person    = null;
$already_patient = false;

if ($person_id) {
    $person = sqlQuery("SELECT * FROM person WHERE id = ?", array($person_id));
    if ($person && !empty($person['pid'])) {
        $chk = sqlQuery("SELECT pid FROM patient_data WHERE pid = ?", array($person['pid']));
        if ($chk) { $already_patient = true; }
    }
}

// ── Helper: format phone ──────────────────────────────────────────────────────
function fmt_phone($raw) {
    $digits = preg_replace('/\D/', '', $raw ?? '');
    if (strlen($digits) === 10) {
        return '(' . substr($digits,0,3) . ') ' . substr($digits,3,3) . '-' . substr($digits,6);
    }
    return $raw ?: '—';
}

$csrf = CsrfUtils::collectCsrfToken();
?>
<!DOCTYPE html>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <title><?php echo xlt("Person Summary"); ?></title>
    <style>
        div#container_div { padding: 10px !important; }

        body { background: #f9fafb; }

        h2 {
            background: none !important;
            color: #000 !important;
            border: 0 !important;
            padding: 0 !important;
            font-weight: bold;
        }

        /* ── Page header ─────────────────────────────────────── */
        .detail-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 28px;
            margin-bottom: 20px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #6b7280;
            text-decoration: none;
            cursor: pointer;
            margin-bottom: 8px;
        }
        .back-link:hover { color: #f97316; }

        /* ── Avatar + name block ─────────────────────────────── */
        .person-hero {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .person-avatar {
            width: 56px; height: 56px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fb923c, #f97316);
            display: flex; align-items: center; justify-content: center;
            font-size: 22px; font-weight: 700;
            color: #fff; flex-shrink: 0;
            letter-spacing: -.5px;
        }
        .person-name {
            font-size: 22px; font-weight: 700;
            color: #111827; line-height: 1.2;
        }
        .person-sub {
            font-size: 13px; color: #6b7280; margin-top: 3px;
        }

        /* ── Status badges ───────────────────────────────────── */
        .badge {
            display: inline-flex; align-items: center; gap: 5px;
            border-radius: 20px; font-size: 11px; font-weight: 600;
            padding: 3px 10px; margin-left: 8px;
        }
        .badge-np  { background:#fff7ed; color:#c2410c; border:1px solid #fed7aa; }
        .badge-ok  { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .badge-off { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }
        .badge-pt  { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }

        /* ── Card grid ───────────────────────────────────────── */
        .cards-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 16px;
        }
        @media (max-width: 700px) { .cards-grid { grid-template-columns: 1fr; } }

        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }
        .card-header {
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
            padding: 10px 16px;
            display: flex; align-items: center; gap: 8px;
        }
        .card-header-icon {
            width: 28px; height: 28px;
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px;
        }
        .card-header-title {
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .06em;
            color: #374151;
        }
        .card-body {
            padding: 14px 16px;
        }
        .field-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            padding: 5px 0;
            border-bottom: 1px solid #f3f4f6;
            font-size: 13px;
            gap: 12px;
        }
        .field-row:last-child { border-bottom: none; }
        .field-label {
            color: #6b7280;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .04em;
            flex-shrink: 0;
            min-width: 110px;
        }
        .field-value {
            color: #111827;
            font-size: 13px;
            text-align: right;
            word-break: break-word;
        }
        .field-value.empty { color: #d1d5db; font-style: italic; }
        .field-value.mono  { font-family: monospace; }

        /* ── Upgrade button ──────────────────────────────────── */
        .upgrade-wrap {
            background: #fff;
            border: 1px solid #fed7aa;
            border-radius: 10px;
            padding: 20px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 14px;
            margin-bottom: 16px;
        }
        .upgrade-wrap.already {
            border-color: #bfdbfe;
            background: #eff6ff;
        }
        .upgrade-text-block {}
        .upgrade-title {
            font-size: 14px; font-weight: 700; color: #111827;
            margin-bottom: 4px;
        }
        .upgrade-sub {
            font-size: 12px; color: #6b7280; line-height: 1.5;
        }
        .btn-upgrade {
            background: #f97316;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 22px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background .15s;
            white-space: nowrap;
        }
        .btn-upgrade:hover   { background: #ea580c; }
        .btn-upgrade:disabled{ opacity: .45; cursor: not-allowed; }

        .btn-view-patient {
            background: #1d4ed8;
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 10px 22px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: background .15s;
        }
        .btn-view-patient:hover { background: #1e40af; }

        .btn-back-list {
            background: transparent;
            color: #6b7280;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 10px 18px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: all .15s;
        }
        .btn-back-list:hover { border-color: #f97316; color: #f97316; }

        /* ── Toast ───────────────────────────────────────────── */
        #np-toast {
            position: fixed;
            bottom: 24px; left: 50%;
            transform: translateX(-50%) translateY(10px);
            background: #1f2937;
            color: #fff;
            border-radius: 8px;
            padding: 12px 20px;
            font-size: 13px;
            font-weight: 500;
            display: flex; align-items: center; gap: 10px;
            opacity: 0;
            pointer-events: none;
            transition: all .25s;
            z-index: 9999;
            max-width: 380px;
            box-shadow: 0 4px 20px rgba(0,0,0,.25);
        }
        #np-toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        #np-toast.toast-ok  { background: #14532d; }
        #np-toast.toast-err { background: #7f1d1d; }

        /* ── Spinner overlay ─────────────────────────────────── */
        #np-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(255,255,255,.6);
            z-index: 9998;
            align-items: center; justify-content: center;
        }
        #np-overlay.show { display: flex; }
        .np-spinner {
            width: 36px; height: 36px;
            border: 3px solid #fed7aa;
            border-top-color: #f97316;
            border-radius: 50%;
            animation: spin .7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .not-found {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }
        .not-found-icon { font-size: 40px; margin-bottom: 12px; }

        /* ── Edit icon on card headers ───────────────────────── */
        .card-header { position: relative; }
        .btn-edit-card {
            margin-left: auto;
            background: transparent;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 4px 8px;
            cursor: pointer;
            color: #9ca3af;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex; align-items: center; gap: 4px;
            transition: all .15s;
        }
        .btn-edit-card:hover { border-color: #f97316; color: #f97316; background: #fff7ed; }
        .btn-edit-card svg { width: 13px; height: 13px; }

        /* ── Edit modal ──────────────────────────────────────── */
        .edit-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,.45); z-index: 800;
            align-items: center; justify-content: center;
        }
        .edit-overlay.open { display: flex; }
        .edit-modal {
            background: #fff; border-radius: 12px;
            padding: 26px 28px; width: 92%; max-width: 480px;
            max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,.25);
            animation: editIn .18s ease;
        }
        @keyframes editIn { from{transform:scale(.95);opacity:0} to{transform:scale(1);opacity:1} }
        .edit-modal-title {
            font-size: 15px; font-weight: 700; color: #111827;
            margin-bottom: 18px; display: flex; align-items: center; gap: 8px;
        }
        .edit-field-group { margin-bottom: 14px; }
        .edit-field-group label {
            display: block; font-size: 11px; font-weight: 600;
            color: #374151; text-transform: uppercase;
            letter-spacing: .04em; margin-bottom: 4px;
        }
        .edit-field-group input,
        .edit-field-group select {
            width: 100%; padding: 8px 11px;
            border: 1px solid #d1d5db; border-radius: 7px;
            font-size: 13px; outline: none; font-family: inherit;
            background: #fff;
        }
        .edit-field-group input:focus,
        .edit-field-group select:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249,115,22,.12);
        }
        .edit-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .btn-edit-cancel {
            background: transparent; color: #6b7280;
            border: 1px solid #d1d5db; border-radius: 7px;
            padding: 9px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .btn-edit-cancel:hover { border-color: #9ca3af; }
        .btn-edit-save {
            background: #f97316; color: #fff; border: none;
            border-radius: 7px; padding: 9px 22px;
            font-size: 13px; font-weight: 700; cursor: pointer;
            transition: background .15s;
        }
        .btn-edit-save:hover { background: #ea580c; }
        .btn-edit-save:disabled { opacity: .4; cursor: not-allowed; }
        .edit-err { color: #dc2626; font-size: 12px; margin-top: 8px; display: none; }
    </style>
</head>
<body>
<div id="container_div" class="container">

<?php if (!$person): ?>
    <div class="not-found">
        <div class="not-found-icon">🔍</div>
        <div style="font-size:16px;font-weight:600;color:#374151;margin-bottom:8px">
            <?php echo xlt("Record not found"); ?>
        </div>
        <div style="font-size:13px;">
            <?php echo xlt("The person record you requested does not exist."); ?>
        </div>
        <button class="btn-back-list" style="margin-top:20px"
                onclick="goBack()">
            ← <?php echo xlt("Back to Non-Patient Finder"); ?>
        </button>
    </div>
<?php else:
    // Build display values
    $initials = strtoupper(
        substr($person['fname'] ?? '', 0, 1) .
        substr($person['lname'] ?? '', 0, 1)
    ) ?: '?';
    $full_name = trim(
        ($person['fname'] ?? '') . ' ' .
        ($person['mi']    ? $person['mi'] . '. ' : '') .
        ($person['lname'] ?? '') .
        ($person['suffix'] ? ', ' . $person['suffix'] : '')
    );
    $is_inactive = !empty($person['inactv_date'])
                   && $person['inactv_date'] !== '0000-00-00'
                   && $person['inactv_date'] !== '0000-00-00 00:00:00'
                   && $person['inactv_date'] !== null
                   && $person['inactv_date'] !== '';

    // Treat zero-dates and literal "NULL" strings from CSV import
    $dob_display = (isset($person['dob']) && $person['dob'] !== '0000-00-00' && $person['dob'] !== '' && $person['dob'] !== 'NULL')
                   ? $person['dob'] : null;
    $person_no_display = (isset($person['person_no']) && $person['person_no'] !== '' && $person['person_no'] !== 'NULL')
                         ? $person['person_no'] : null;
    $gender_labels = array('M'=>'Male','F'=>'Female','U'=>'Unknown');
    $gender_disp   = $gender_labels[$person['gender'] ?? ''] ?? ($person['gender'] ?: '—');
    $send_stmt_disp = $person['send_stmt'] ? 'Yes' : 'No';
?>

    <!-- Back link -->
    <div style="margin-top:20px">
        <a class="back-link" onclick="goBack()" style="cursor:pointer">
            ← <?php echo xlt("Back to Non-Patient Finder"); ?>
        </a>
    </div>

    <!-- Page header -->
    <div class="detail-header">
        <div class="person-hero">
            <div class="person-avatar"><?php echo text($initials); ?></div>
            <div>
                <div class="person-name">
                    <?php echo text($full_name ?: xlt('Unknown Name')); ?>
                    <?php if ($already_patient): ?>
                        <span class="badge badge-pt">✓ Patient</span>
                    <?php elseif ($is_inactive): ?>
                        <span class="badge badge-off">Inactive</span>
                    <?php else: ?>
                        <span class="badge badge-np">Non-Patient</span>
                    <?php endif; ?>
                </div>
                <div class="person-sub">
                    <?php echo xlt("ID"); ?>:
                    <span style="font-family:monospace;font-weight:600">
                        <?php echo $person_no_display ? text($person_no_display) : text($person['pid']); ?>
                    </span>
                    &nbsp;·&nbsp;
                    <?php echo xlt("Person No."); ?>: <span style="font-family:monospace"><?php echo $person_no_display ? text($person_no_display) : xlt('—'); ?></span>
                    &nbsp;·&nbsp;
                    <?php echo xlt("DB ID"); ?>: <span style="font-family:monospace"><?php echo (int)$person['id']; ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Upgrade / already-patient banner ────────────────────────── -->
    <?php if ($already_patient): ?>
    <div class="upgrade-wrap already">
        <div class="upgrade-text-block">
            <div class="upgrade-title">✓ <?php echo xlt("Already a Patient"); ?></div>
            <div class="upgrade-sub">
                <?php echo xlt("This person has been converted to a full OpenEMR patient (PID"); ?>
                <?php echo ' ' . (int)$person['pid']; ?>).
            </div>
        </div>
        <a class="btn-view-patient"
           onclick="viewPatient(<?php echo (int)$person['pid']; ?>)"
           style="cursor:pointer">
            👤 <?php echo xlt("View Patient Record"); ?>
        </a>
    </div>
    <?php elseif (AclMain::aclCheckCore('patients', 'demo', '', array('write', 'addonly'))): ?>
    <div class="upgrade-wrap" id="upgrade-banner">
        <div class="upgrade-text-block">
            <div class="upgrade-title">⬆ <?php echo xlt("Upgrade to Patient"); ?></div>
            <div class="upgrade-sub">
                <?php echo xlt("Convert this person record into a full OpenEMR patient. Their name, address, phone, DOB, gender, and SSN will be copied into patient_data."); ?>
            </div>
        </div>
        <button class="btn-upgrade" id="btn-upgrade" onclick="doUpgrade()">
            <span>⬆</span> <?php echo xlt("Upgrade to Patient"); ?>
        </button>
    </div>
    <?php endif; ?>

    <!-- Summary cards ───────────────────────────────────────────── -->
    <div class="cards-grid">

        <!-- Personal Information -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon" style="background:#fff7ed">👤</div>
                <div class="card-header-title"><?php echo xlt("Personal Information"); ?></div>
                <button class="btn-edit-card" onclick="openEdit('personal')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php echo xlt('Edit'); ?>
                </button>
            </div>
            <div class="card-body">
                <?php
                $personal = array(
                    xlt('First Name')   => $person['fname'],
                    xlt('Middle Init')  => $person['mi'],
                    xlt('Last Name')    => $person['lname'],
                    xlt('Suffix')       => $person['suffix'],
                    xlt('Date of Birth')=> $dob_display,
                    xlt('Gender')       => $gender_disp,
                    xlt('SSN')          => ($person['ssn'] && $person['ssn'] !== 'NULL')
                                           ? '***-**-' . substr($person['ssn'], -4) : null,
                );
                <?php
                $personal_keys = array('fname','mi','lname','suffix','dob','gender','ssn');
                foreach ($personal as $lbl => $val):
                    $pkey = current($personal_keys); next($personal_keys);
                    $empty = !$val;
                ?>
                <div class="field-row" data-key="<?php echo attr($pkey); ?>">
                    <span class="field-label"><?php echo text($lbl); ?></span>
                    <span class="field-value<?php echo $empty?' empty':''; ?>">
                        <?php echo $empty ? xlt('—') : text($val); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon" style="background:#f0fdf4">📞</div>
                <div class="card-header-title"><?php echo xlt("Contact Information"); ?></div>
                <button class="btn-edit-card" onclick="openEdit('contact')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php echo xlt('Edit'); ?>
                </button>
            </div>
            <div class="card-body">
                <?php
                $contact = array(
                    xlt('Home Phone') => fmt_phone($person['hphone']),
                    xlt('Address 1')  => $person['address1'],
                    xlt('Address 2')  => $person['address2'],
                    xlt('City')       => $person['city'],
                    xlt('State')      => $person['state'],
                    xlt('ZIP')        => $person['zip'],
                );
                <?php
                $contact_keys = array('hphone','address1','address2','city','state','zip');
                foreach ($contact as $lbl => $val):
                    $ckey = current($contact_keys); next($contact_keys);
                    $empty = !$val || $val === '—';
                ?>
                <div class="field-row" data-key="<?php echo attr($ckey); ?>">
                    <span class="field-label"><?php echo text($lbl); ?></span>
                    <span class="field-value<?php echo $empty?' empty':''; ?>">
                        <?php echo $empty ? xlt('—') : text($val); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Employment / Status -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon" style="background:#f5f3ff">💼</div>
                <div class="card-header-title"><?php echo xlt("Employment & Status"); ?></div>
                <button class="btn-edit-card" onclick="openEdit('employ')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <?php echo xlt('Edit'); ?>
                </button>
            </div>
            <div class="card-body">
                <?php
                $employ = array(
                    xlt('Employer ID')    => $person['empmstid'] ?: null,
                    xlt('Emp. Status ID') => $person['empstatid'] ?: null,
                    xlt('Retired Date')   => $person['retired_date'] && $person['retired_date'] !== '0000-00-00'
                                             ? $person['retired_date'] : null,
                    xlt('Send Statement') => $send_stmt_disp,
                    xlt('Inactive Date')  => $is_inactive ? $person['inactv_date'] : null,
                );
                <?php
                $employ_keys = array('empmstid','empstatid','retired_date','send_stmt','inactv_date');
                foreach ($employ as $lbl => $val):
                    $ekey = current($employ_keys); next($employ_keys);
                    $empty = ($val === null || $val === '');
                ?>
                <div class="field-row" data-key="<?php echo attr($ekey); ?>">
                    <span class="field-label"><?php echo text($lbl); ?></span>
                    <span class="field-value<?php echo $empty?' empty':''; ?>">
                        <?php echo $empty ? xlt('—') : text($val); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Record Metadata -->
        <div class="card">
            <div class="card-header">
                <div class="card-header-icon" style="background:#fef9c3">🗂</div>
                <div class="card-header-title"><?php echo xlt("Record Metadata"); ?></div>
            </div>
            <div class="card-body">
                <?php
                $meta = array(
                    xlt('Internal ID')  => $person['id'],
                    xlt('Person No.')   => $person_no_display,
                    xlt('Linked PID')   => ($person['pid'] && (int)$person['pid'] > 0) ? $person['pid'] : null,
                    xlt('Username')     => $person['username'],
                    xlt('Trans. Date')  => $person['tran_date'],
                );
                foreach ($meta as $lbl => $val):
                    $empty = ($val === null || $val === '' || $val === '0');
                ?>
                <div class="field-row">
                    <span class="field-label"><?php echo text($lbl); ?></span>
                    <span class="field-value mono<?php echo $empty?' empty':''; ?>">
                        <?php echo $empty ? xlt('—') : text($val); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </div><!-- /cards-grid -->

<?php endif; ?>

</div><!-- /container -->

<!-- Spinner overlay -->
<div id="np-overlay"><div class="np-spinner"></div></div>

<!-- Toast -->
<div id="np-toast"></div>

<!-- Edit Modal -->
<div class="edit-overlay" id="edit-overlay">
  <div class="edit-modal">
    <div class="edit-modal-title" id="edit-modal-title">Edit</div>
    <div id="edit-modal-body"></div>
    <div class="edit-err" id="edit-err"></div>
    <div class="edit-modal-actions">
      <button class="btn-edit-cancel" onclick="closeEdit()"><?php echo xlt('Cancel'); ?></button>
      <button class="btn-edit-save" id="btn-edit-save" onclick="submitEdit()"><?php echo xlt('Save'); ?></button>
    </div>
  </div>
</div>

<script>
var PERSON_ID  = <?php echo (int)(isset($person['id']) ? $person['id'] : 0); ?>;
var CSRF_TOKEN = <?php echo json_encode($csrf); ?>;
var WEB_ROOT   = <?php echo json_encode($GLOBALS['web_root']); ?>;

// Live record — updated after each save so fields stay in sync
var RECORD = <?php echo json_encode($person ?: array()); ?>;

var _editCard = '';

// ── Card definitions: which fields appear in each edit modal ─────────────────
var CARDS = {
    personal: {
        title: <?php echo json_encode('✏ ' . xlt('Edit Personal Information')); ?>,
        fields: [
            { key:'fname',  label:<?php echo json_encode(xlt('First Name')); ?>,  type:'text' },
            { key:'mi',     label:<?php echo json_encode(xlt('Middle Initial')); ?>, type:'text', maxlen:1 },
            { key:'lname',  label:<?php echo json_encode(xlt('Last Name')); ?>,   type:'text' },
            { key:'suffix', label:<?php echo json_encode(xlt('Suffix')); ?>,      type:'text', maxlen:3 },
            { key:'dob',    label:<?php echo json_encode(xlt('Date of Birth')); ?>,  type:'date' },
            { key:'gender', label:<?php echo json_encode(xlt('Gender')); ?>,      type:'select',
              options:[['','— Select —'],['M',<?php echo json_encode(xlt('Male')); ?>],['F',<?php echo json_encode(xlt('Female')); ?>],['U',<?php echo json_encode(xlt('Unknown')); ?>]] },
            { key:'ssn',    label:<?php echo json_encode(xlt('SSN')); ?>,         type:'text', maxlen:9 },
        ]
    },
    contact: {
        title: <?php echo json_encode('✏ ' . xlt('Edit Contact Information')); ?>,
        fields: [
            { key:'hphone',   label:<?php echo json_encode(xlt('Home Phone')); ?>,  type:'text' },
            { key:'address1', label:<?php echo json_encode(xlt('Address 1')); ?>,   type:'text' },
            { key:'address2', label:<?php echo json_encode(xlt('Address 2')); ?>,   type:'text' },
            { key:'city',     label:<?php echo json_encode(xlt('City')); ?>,        type:'text' },
            { key:'state',    label:<?php echo json_encode(xlt('State')); ?>,       type:'text', maxlen:20 },
            { key:'zip',      label:<?php echo json_encode(xlt('ZIP')); ?>,         type:'text', maxlen:10 },
        ]
    },
    employ: {
        title: <?php echo json_encode('✏ ' . xlt('Edit Employment & Status')); ?>,
        fields: [
            { key:'send_stmt',    label:<?php echo json_encode(xlt('Send Statement')); ?>, type:'select',
              options:[['0',<?php echo json_encode(xlt('No')); ?>],['1',<?php echo json_encode(xlt('Yes')); ?>]] },
            { key:'inactv_date',  label:<?php echo json_encode(xlt('Inactive Date')); ?>,  type:'date' },
            { key:'retired_date', label:<?php echo json_encode(xlt('Retired Date')); ?>,   type:'date' },
        ]
    }
};

function openEdit(card) {
    _editCard = card;
    var def = CARDS[card];
    document.getElementById('edit-modal-title').textContent = def.title;
    document.getElementById('edit-err').style.display = 'none';

    var html = '';
    def.fields.forEach(function(f) {
        html += '<div class="edit-field-group"><label>' + escHtml(f.label) + '</label>';
        var cur = RECORD[f.key] !== undefined ? RECORD[f.key] : '';
        if (cur === 'NULL' || cur === null) cur = '';

        if (f.type === 'select') {
            html += '<select id="ef_' + f.key + '">';
            f.options.forEach(function(o) {
                var sel = (String(cur) === String(o[0])) ? ' selected' : '';
                html += '<option value="' + escHtml(o[0]) + '"' + sel + '>' + escHtml(o[1]) + '</option>';
            });
            html += '</select>';
        } else {
            var extra = f.maxlen ? ' maxlength="' + f.maxlen + '"' : '';
            html += '<input type="' + (f.type === 'date' ? 'date' : 'text') + '" id="ef_' + f.key + '"'
                  + ' value="' + escHtml(cur) + '"' + extra + '>';
        }
        html += '</div>';
    });

    document.getElementById('edit-modal-body').innerHTML = html;
    document.getElementById('edit-overlay').classList.add('open');
    // Focus first input
    var first = document.querySelector('#edit-modal-body input, #edit-modal-body select');
    if (first) setTimeout(function(){ first.focus(); }, 80);
}

function closeEdit() {
    document.getElementById('edit-overlay').classList.remove('open');
}

function submitEdit() {
    var def  = CARDS[_editCard];
    var btn  = document.getElementById('btn-edit-save');
    var errEl = document.getElementById('edit-err');
    errEl.style.display = 'none';
    btn.disabled = true;
    btn.textContent = '…';

    var fd = new FormData();
    fd.append('save_field',      '1');
    fd.append('person_id',       PERSON_ID);
    fd.append('card',            _editCard);
    fd.append('csrf_token_form', CSRF_TOKEN);

    def.fields.forEach(function(f) {
        var el = document.getElementById('ef_' + f.key);
        if (el) fd.append('fields[' + f.key + ']', el.value);
    });

    var xhr = new XMLHttpRequest();
    xhr.open('POST', '?id=' + PERSON_ID, true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        btn.disabled = false;
        btn.textContent = <?php echo json_encode(xlt('Save')); ?>;

        if (xhr.status !== 200) {
            errEl.textContent = <?php echo json_encode(xlt('Server error.')); ?>;
            errEl.style.display = 'block';
            return;
        }
        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch(e) { return; }

        if (!resp.success) {
            errEl.textContent = resp.error || 'Error';
            errEl.style.display = 'block';
            return;
        }

        // Update live record
        RECORD = resp.record;
        closeEdit();
        refreshDisplay();
    };
    xhr.send(fd);
}

// ── Refresh visible field values after save ───────────────────────────────────
function refreshDisplay() {
    // Rebuild name/avatar
    var fname = RECORD.fname || '';
    var lname = RECORD.lname || '';
    var mi    = RECORD.mi    || '';
    var suffix = RECORD.suffix || '';
    var initials = ((fname[0]||'') + (lname[0]||'')).toUpperCase() || '?';
    var fullName = (fname + (mi ? ' ' + mi + '.' : '') + ' ' + lname + (suffix ? ', '+suffix : '')).trim();

    var avatarEl = document.querySelector('.person-avatar');
    var nameEl   = document.querySelector('.person-name');
    if (avatarEl) avatarEl.textContent = initials;
    if (nameEl) {
        // Preserve badge — only replace text node
        var badge = nameEl.querySelector('.badge');
        nameEl.textContent = fullName || '—';
        if (badge) nameEl.appendChild(badge);
    }

    // Refresh each field-row by its data-key attribute
    document.querySelectorAll('.field-row[data-key]').forEach(function(row) {
        var key = row.getAttribute('data-key');
        var valEl = row.querySelector('.field-value');
        if (!valEl) return;
        var val = RECORD[key];
        if (val === null || val === undefined || val === '' || val === 'NULL' || val === '0000-00-00') {
            valEl.className = 'field-value empty';
            valEl.textContent = '—';
        } else if (key === 'ssn') {
            valEl.className = 'field-value';
            valEl.textContent = '***-**-' + String(val).slice(-4);
        } else if (key === 'gender') {
            var gmap = {M:'Male',F:'Female',U:'Unknown'};
            valEl.className = 'field-value';
            valEl.textContent = gmap[val] || val;
        } else if (key === 'send_stmt') {
            valEl.className = 'field-value';
            valEl.textContent = (val == 1) ? 'Yes' : 'No';
        } else if (key === 'hphone') {
            var digits = String(val).replace(/\D/g,'');
            valEl.className = 'field-value';
            valEl.textContent = digits.length===10
                ? '('+digits.slice(0,3)+') '+digits.slice(3,6)+'-'+digits.slice(6)
                : val;
        } else {
            valEl.className = 'field-value';
            valEl.textContent = val;
        }
    });
}

// ── Other helpers ─────────────────────────────────────────────────────────────
function showToast(msg, type) {
    var el = document.getElementById('np-toast');
    el.textContent = msg;
    el.className   = 'show toast-' + (type || 'ok');
    clearTimeout(el._t);
    el._t = setTimeout(function () { el.className = ''; }, 3600);
}
function goBack() {
    top.restoreSession();
    top.RTop.location = WEB_ROOT + '/interface/main/finder/non_patient_finder.php';
}
function viewPatient(pid) {
    top.restoreSession();
    if (top.RTop && top.RTop.document && top.RTop.document.body) {
        top.RTop.document.body.style.opacity = '0.6';
        top.RTop.document.body.style.pointerEvents = 'none';
    }
    setTimeout(function () {
        top.RTop.location = WEB_ROOT + '/interface/patient_file/summary/demographics.php?set_pid='
            + encodeURIComponent(pid);
    }, 10);
}
function doUpgrade() {
    if (!confirm(<?php echo json_encode(xlt("Convert this person record to a full patient? Their demographic data will be copied into patient_data.")); ?>)) return;
    var btn     = document.getElementById('btn-upgrade');
    var overlay = document.getElementById('np-overlay');
    if (btn)     { btn.disabled = true; btn.textContent = '⏳ Converting…'; }
    if (overlay) { overlay.classList.add('show'); }
    var fd = new FormData();
    fd.append('upgrade',         '1');
    fd.append('person_id',       PERSON_ID);
    fd.append('csrf_token_form', CSRF_TOKEN);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '?id=' + PERSON_ID, true);
    xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        if (overlay) overlay.classList.remove('show');
        if (xhr.status !== 200) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<span>⬆</span> <?php echo xlt("Upgrade to Patient"); ?>'; }
            showToast('<?php echo xlt("Server error. Please try again."); ?>', 'err');
            return;
        }
        var resp;
        try { resp = JSON.parse(xhr.responseText); } catch (e) { return; }
        if (!resp.success) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<span>⬆</span> <?php echo xlt("Upgrade to Patient"); ?>'; }
            showToast('<?php echo xlt("Error"); ?>: ' + (resp.error || 'Unknown'), 'err');
            return;
        }
        showToast('<?php echo xlt("Patient record created successfully!"); ?>', 'ok');
        var banner = document.getElementById('upgrade-banner');
        if (banner) {
            banner.classList.add('already');
            banner.innerHTML =
                '<div class="upgrade-text-block">'
                + '<div class="upgrade-title">✓ <?php echo xlt("Converted to Patient"); ?></div>'
                + '<div class="upgrade-sub"><?php echo xlt("This person is now a full OpenEMR patient (PID"); ?> ' + resp.pid + ').</div>'
                + '</div>'
                + '<button class="btn-view-patient" onclick="viewPatient(' + resp.pid + ')" style="cursor:pointer">'
                + '👤 <?php echo xlt("View Patient Record"); ?>'
                + '</button>';
        }
    };
    xhr.send(fd);
}
function escHtml(s) {
    if (!s && s !== 0) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
document.getElementById('edit-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});
</script>
</body>
</html>
