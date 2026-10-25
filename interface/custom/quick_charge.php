<?php
ob_start();

require_once(__DIR__ . "/../globals.php");
require_once($GLOBALS['srcdir'] . "/FeeSheetHtml.class.php");
require_once($GLOBALS['srcdir'] . "/options.inc.php");
require_once(__DIR__ . "/../forms/fee_sheet/codes.php");

use OpenEMR\Core\Header;
use OpenEMR\Billing\BillingUtilities;

Header::setupHeader(['common', 'opener', 'dialog']);

// Ensure session PID
if (empty($_SESSION['pid']) && !empty($_GET['pid'])) {
    $_SESSION['pid'] = $_GET['pid'];
}

$pid = $_GET['pid'] ?? $_SESSION['pid'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $provider = $_SESSION['authUserID'];
    $code = $_POST['code'];

    // 🔒 Always get fee from DB (never trust frontend)
    $row = sqlQuery(
        "SELECT fee FROM codes WHERE code_type='CPT4' AND code=? LIMIT 1",
        [$code]
    );

    $fee = $row['fee'] ?? 0;

    $enc = sqlQuery(
        "SELECT encounter FROM form_encounter 
         WHERE pid=? AND DATE(date)=CURDATE()
         ORDER BY encounter DESC LIMIT 1",
        [$pid]
    );
    
    if (!$enc) {
        require_once($GLOBALS['srcdir'] . "/encounter.inc.php");
    
        $encounter = generate_id();
    
        addForm(
            $encounter,
            "Quick Charge Encounter",
            1,
            "newpatient",
            $pid,
            $_SESSION['authUser']
        );
    
        sqlInsert(
            "INSERT INTO form_encounter 
            (pid, encounter, date, provider_id, reason)
            VALUES (?, ?, NOW(), ?, ?)",
            [$pid, $encounter, $provider, 'Quick Charge']
        );
    
        $_SESSION['encounter'] = $encounter;
    
    } else {
        $encounter = $enc['encounter'];
        $_SESSION['encounter'] = $encounter;
    }

    // Add billing
    BillingUtilities::addBilling(
        $encounter,
        'CPT4',
        $code,
        $fee,
        1,
        $provider,
        $pid,
        $_POST['icd']
    );

    echo "<script>window.close();</script>";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Quick Charge</title>
    <style>
        body { font-family: Arial; padding: 15px; }
        input { width: 100%; margin-bottom: 10px; padding: 6px; }
        button { padding: 8px 12px; }

        .dropdown-box {
            border:1px solid #ccc;
            max-height:150px;
            overflow-y:auto;
            display:none;
            background:#fff;
            position:absolute;
            z-index:9999;
            width:100%;
        }

        .dropdown-item {
            padding:6px;
            cursor:pointer;
        }

        .dropdown-item:hover {
            background:#eee;
        }

        .field-wrap {
            position:relative;
        }
    </style>
</head>
<body>

<h3>Quick Charge</h3>

<form method="post">

    <!-- CPT -->
    <label>CPT Code</label>
    <div class="field-wrap">
        <input type="text" id="cpt_input" autocomplete="off" placeholder="Type CPT..." required>
        <input type="hidden" name="code" id="code_hidden">
        <div id="cpt_dropdown" class="dropdown-box"></div>
    </div>

    <!-- Fee -->
    <label>Fee</label>
    <input name="fee" id="fee_input" readonly>

    <!-- ICD -->
    <label>ICD Code</label>
    <div class="field-wrap">
        <input type="text" id="icd_input" autocomplete="off" placeholder="Type ICD...">
        <input type="hidden" name="icd" id="icd_hidden">
        <div id="icd_dropdown" class="dropdown-box"></div>
    </div>

    <button type="submit">Save Charge</button>

</form>

<script>
// ===== CPT AUTOCOMPLETE =====
const cptInput = document.getElementById('cpt_input');
const cptDropdown = document.getElementById('cpt_dropdown');
const codeHidden = document.getElementById('code_hidden');
const feeInput = document.getElementById('fee_input');

cptInput.addEventListener('input', function() {
    let q = this.value;

    if (q.length < 2) {
        cptDropdown.style.display = 'none';
        return;
    }

    fetch('quick_charge_lookup.php?q=' + encodeURIComponent(q))
        .then(res => res.json())
        .then(data => {
            cptDropdown.innerHTML = '';

            data.forEach(item => {
                let div = document.createElement('div');
                div.className = 'dropdown-item';
                div.innerHTML = item.code + ' - ' + item.text;

                div.onclick = function() {
                    cptInput.value = item.code + ' - ' + item.text;
                    codeHidden.value = item.code;
                    feeInput.value = item.fee;
                    cptDropdown.style.display = 'none';
                };

                cptDropdown.appendChild(div);
            });

            cptDropdown.style.display = 'block';
        });
});

// ===== ICD AUTOCOMPLETE =====
const icdInput = document.getElementById('icd_input');
const icdDropdown = document.getElementById('icd_dropdown');
const icdHidden = document.getElementById('icd_hidden');

icdInput.addEventListener('input', function() {
    let q = this.value;

    if (q.length < 2) {
        icdDropdown.style.display = 'none';
        return;
    }

    fetch('quick_charge_icd_lookup.php?q=' + encodeURIComponent(q))
        .then(res => res.json())
        .then(data => {
            icdDropdown.innerHTML = '';

            data.forEach(item => {
                let div = document.createElement('div');
                div.className = 'dropdown-item';
                div.innerHTML = item.code + ' - ' + item.text;

                div.onclick = function() {
                    icdInput.value = item.code + ' - ' + item.text;
                    icdHidden.value = item.code;
                    icdDropdown.style.display = 'none';
                };

                icdDropdown.appendChild(div);
            });

            icdDropdown.style.display = 'block';
        });
});

// hide dropdowns
document.addEventListener('click', function(e) {
    if (!cptDropdown.contains(e.target) && e.target !== cptInput) {
        cptDropdown.style.display = 'none';
    }
    if (!icdDropdown.contains(e.target) && e.target !== icdInput) {
        icdDropdown.style.display = 'none';
    }
});
</script>

</body>
</html>