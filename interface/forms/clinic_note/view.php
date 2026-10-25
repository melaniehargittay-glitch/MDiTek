<?php
/**
 * clinic_note new.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Rod Roark <rod@sunsetsystems.com>
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @author    Daniel Ehrlich <daniel.ehrlich1@gmail.com>
 * @copyright Copyright (c) 2005 Rod Roark <rod@sunsetsystems.com>
 * @copyright Copyright (c) 2018-2021 Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2018 Daniel Ehrlich <daniel.ehrlich1@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once("../../globals.php");
require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$row = array();

// ── Ensure form_clinic_note table exists with all required columns ──────────
// The base OpenEMR table may be missing followup_required / followup_timing.
function clinic_note_table_setup() {
    // Create the table if it does not exist at all
    sqlStatement("CREATE TABLE IF NOT EXISTS `form_clinic_note` (
        `id`                int(11)      NOT NULL AUTO_INCREMENT,
        `date`              datetime     DEFAULT NULL,
        `pid`               bigint(20)   DEFAULT NULL,
        `encounter`         bigint(20)   DEFAULT NULL,
        `user`              varchar(255) DEFAULT NULL,
        `groupname`         varchar(255) DEFAULT NULL,
        `authorized`        tinyint(4)   DEFAULT 0,
        `activity`          tinyint(4)   DEFAULT 1,
        `history`           text,
        `examination`       text,
        `plan`              text,
        `followup_required` varchar(10)  DEFAULT '0',
        `followup_timing`   varchar(255) DEFAULT '',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add missing columns for existing installations that have the base table
    $cols = sqlStatement("SHOW COLUMNS FROM `form_clinic_note`");
    $existing = [];
    while ($c = sqlFetchArray($cols)) { $existing[] = $c['Field']; }

    if (!in_array('followup_required', $existing)) {
        sqlStatement("ALTER TABLE `form_clinic_note` ADD COLUMN `followup_required` varchar(10) DEFAULT '0' AFTER `plan`");
    }
    if (!in_array('followup_timing', $existing)) {
        sqlStatement("ALTER TABLE `form_clinic_note` ADD COLUMN `followup_timing` varchar(255) DEFAULT '' AFTER `followup_required`");
    }
}
clinic_note_table_setup();
// ── End table setup ──────────────────────────────────────────────────────────

if (! $encounter) {
    die("Internal error: we do not seem to be in an encounter!");
}

function rbvalue($rbname) {
    $tmp = $_POST[$rbname] ?? null;
    if (! $tmp) { $tmp = '0'; }
    return "$tmp";
}

function cbvalue($cbname) {
    return $_POST[$cbname] ? '1' : '0';
}

function rbinput($name, $value, $desc, $colname) {
    global $row;
    $ret  = "<input type='radio' name='" . attr($name) . "' value='" . attr($value) . "'";
    if (!empty($row) && ($row[$colname] == $value)) { $ret .= " checked"; }
    $ret .= " />" . text($desc);
    return $ret;
}

function rbcell($name, $value, $desc, $colname) {
    return "<td width='25%' nowrap>" . rbinput($name, $value, $desc, $colname) . "</td>\n";
}

function cbinput($name, $colname) {
    global $row;
    $ret  = "<input type='checkbox' name='" . attr($name) . "' value='1'";
    if ($row[$colname]) { $ret .= " checked"; }
    $ret .= " />";
    return $ret;
}

function cbcell($name, $desc, $colname) {
    return "<td width='25%' nowrap>" . cbinput($name, $colname) . text($desc) . "</td>\n";
}

$formid = $_GET['id'] ?? null;

$save_success = false;
$save_error   = '';

if (!empty($_POST['bn_save']) || !empty($_POST['do_save'])) {
    $fu_timing = $_POST['fu_timing'] ?? '';


    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
        CsrfUtils::csrfNotVerified();
    }

    try {
    if ($formid) {
        $query = "UPDATE form_clinic_note SET history = ?, examination = ?, plan = ?, followup_required = ?, followup_timing = ? WHERE id = ?";
        sqlStatement($query, array($_POST['form_history'], $_POST['form_examination'], $_POST['form_plan'], rbvalue('fu_required'), $fu_timing, $formid));
    } else {
        // 'date' omitted — DEFAULT_GENERATED column auto-fills; passing NOW() causes preparation error
        $query = "INSERT INTO form_clinic_note (pid, encounter, user, groupname, authorized, activity, history, examination, plan, followup_required, followup_timing) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)";
        $newid = sqlInsert($query, array(
            (int)$pid,
            (int)$encounter,
            $_SESSION['authUser']      ?? 'unknown',
            $_SESSION['authProvider']  ?? 'Default',
            (int)($userauthorized      ?? 0),
            $_POST['form_history']     ?? '',
            $_POST['form_examination'] ?? '',
            $_POST['form_plan']        ?? '',
            rbvalue('fu_required'),
            $fu_timing
        ));
        addForm($encounter, "Clinic Note", $newid, "clinic_note", $pid, $userauthorized);
        $formid = $newid;
    }

    $save_success = true;

    } catch (\Throwable $e) {
        $save_error = $e->getMessage();
        error_log("clinic_note SAVE ERROR: " . $e->getMessage());
    }

    // Reload the saved row
    if ($save_success) {
        $row = sqlQuery("SELECT * FROM form_clinic_note WHERE id = ? AND activity = '1'", array($formid));
    }
}

if ($formid) {
    $row = sqlQuery("SELECT * FROM form_clinic_note WHERE id = ? AND activity = '1'", array($formid));
}
?>
<html>
<head>
    <?php Header::setupHeader(); ?>
    <style>
        :root {
            --accent: #47377a;
            --accent-light: #f0ecfa;
            --border: #e4ddf5;
            --red: #dc2626;
            --surface: #ffffff;
        }

        body {
            background: #f3f0f9 !important;
            font-family: "Montserrat", sans-serif !important;
            font-size:1em;
        }

        /* ── Ambient Recording Bar ── */
        #ambient-bar {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            margin: 14px 10px 0 10px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(71,55,122,.07);
        }

        #ambient-header {
            background: linear-gradient(135deg, #5b21b6, #7c3aed);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 18px;
        }

        #ambient-header .amb-label {
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        #ambient-header .amb-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Waveform */
        .waveform {
            display: flex;
            align-items: center;
            gap: 2px;
            height: 16px;
            opacity: 0;
            transition: opacity .3s;
        }
        .waveform.on { opacity: 1; }
        .wbar {
            width: 2px;
            background: #fff;
            border-radius: 1px;
            animation: wv .8s ease-in-out infinite;
        }
        .wbar:nth-child(2) { animation-delay: .11s; }
        .wbar:nth-child(3) { animation-delay: .22s; }
        .wbar:nth-child(4) { animation-delay: .33s; }
        .wbar:nth-child(5) { animation-delay: .44s; }
        .wbar:nth-child(6) { animation-delay: .22s; }
        .wbar:nth-child(7) { animation-delay: .11s; }
        @keyframes wv {
            0%,100% { height: 2px; }
            50%      { height: 13px; }
        }

        /* Record button */
        .rec-btn {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 6px 14px;
            background: rgba(255,255,255,.15);
            color: #fff;
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 7px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            white-space: nowrap;
        }
        .rec-btn:hover { background: rgba(255,255,255,.25); }
        .rec-btn.recording {
            background: var(--red);
            border-color: var(--red);
            animation: pulse-rec 1.6s ease-in-out infinite;
        }
        .rdot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: currentColor;
            flex-shrink: 0;
        }
        @keyframes pulse-rec {
            0%,100% { box-shadow: 0 0 0 0 rgba(220,38,38,.4); }
            50%      { box-shadow: 0 0 0 6px rgba(220,38,38,0); }
        }

        /* Ambient body */
        #ambient-body {
            padding: 0;
        }

        #ambient-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 18px;
            border-bottom: 1px solid var(--border);
            background: #faf8ff;
        }

        #ambient-controls label {
            font-size: 11px;
            font-weight: 700;
            color: var(--accent);
            letter-spacing: .08em;
            text-transform: uppercase;
            white-space: nowrap;
            margin: 0;
        }

        #targetField {
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 5px 10px;
            font-size: 12px;
            color: #333;
            background: #fff;
            cursor: pointer;
        }

        .amb-action-btn {
            padding: 5px 13px;
            border-radius: 7px;
            border: none;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all .15s;
        }

        #addToNoteBtn {
            background: linear-gradient(135deg,#5b21b6,#7c3aed);
            color: #fff;
            display: none;
        }
        #addToNoteBtn:hover { opacity: .9; }

        #clearTranscriptBtn {
            background: #fff;
            color: #666;
            border: 1px solid var(--border) !important;
            display: none;
        }
        #clearTranscriptBtn:hover { background: #f5f3ff; color: var(--accent); }

        #transcriptBody {
            min-height: 60px;
            max-height: 120px;
            overflow-y: auto;
            padding: 12px 18px;
            font-size: 13px;
            color: #555;
            line-height: 1.6;
            background: #fff;
        }

        #transcriptBody.live {
            border-left: 3px solid var(--red);
            color: #222;
        }

        /* ── Note table ── */
        .note-table {
            width: 95%;
            margin: 14px auto;
            border-collapse: collapse;
            background: #fff;
            border-radius: 10px;
            overflow: hidden;
            border: 1px solid var(--border);
            box-shadow: 0 2px 10px rgba(71,55,122,.06);
        }

        .note-table tr:first-child td {
            background: #f3f0f9;
            text-align: center;
            font-weight: bold;
            font-size: 13px;
            color: var(--accent);
            padding: 10px;
            border-bottom: 1px solid var(--border);
        }

        .note-table td {
            border: 1px solid var(--border);
            padding: 8px 12px;
            vertical-align: top;
            font-size: 13px;
        }

        .note-table td:first-child {
            width: 110px;
            font-weight: 600;
            color: var(--accent);
            background: #faf8ff;
            white-space: nowrap;
        }

        .note-table textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 8px 10px;
            font-size: 13px;
            resize: vertical;
            font-family: inherit;
            background: #fff;
            color: #222;
            transition: border-color .2s;
            box-sizing: border-box;
        }
        .note-table textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(124,58,237,.08);
        }

        .note-table input[type="text"] {
            border: 1px solid var(--border);
            border-radius: 7px;
            padding: 5px 10px;
            font-size: 13px;
            width: 200px;
        }

        .note-table input[type="radio"] {
            accent-color: var(--accent);
            margin-right: 5px;
        }

        .fu-row { display: flex; flex-direction: column; gap: 6px; }
        .fu-row label { display: flex; align-items: center; gap: 6px; font-weight: normal; }

        /* ── Save/Cancel buttons ── */
        .btn-row {
            display: flex;
            justify-content: center;
            gap: 10px;
            padding: 12px 0 20px;
        }

        .btn-save {
            background: linear-gradient(135deg,#5b21b6,#7c3aed);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 8px 28px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(124,58,237,.3);
            transition: opacity .15s;
        }
        .btn-save:hover { opacity: .9; }

        .btn-cancel {
            background: #fff;
            color: #555;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 8px 22px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-cancel:hover { background: #f5f3ff; color: var(--accent); }
    </style>
</head>

<body>

<?php if ($save_success): ?>
<div id="save-toast" style="
    position:fixed;top:18px;right:18px;z-index:9999;
    background:linear-gradient(135deg,#059669,#10b981);
    color:#fff;border-radius:10px;
    padding:12px 22px;font-size:13px;font-weight:600;
    box-shadow:0 6px 20px rgba(5,150,105,.35);
    display:flex;align-items:center;gap:10px;
    animation:slideIn .3s ease;">
    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
    Clinic note saved successfully
</div>
<style>@keyframes slideIn{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}</style>
<script>
setTimeout(function(){
    var t=document.getElementById('save-toast');
    if(t){t.style.transition='opacity .4s';t.style.opacity='0';setTimeout(function(){t.remove();},400);}
},3000);
</script>
<?php endif; ?>

<?php if ($save_error): ?>
<div style="
    position:fixed;top:18px;right:18px;z-index:9999;
    background:#dc2626;color:#fff;border-radius:10px;
    padding:14px 22px;font-size:13px;font-weight:600;
    box-shadow:0 6px 20px rgba(220,38,38,.35);
    max-width:500px;line-height:1.5;">
    ⚠ Save failed: <?php echo htmlspecialchars($save_error); ?>
</div>
<?php endif; ?>

<!-- ══════════ AMBIENT RECORDING BAR ══════════ -->
<div class="container mt-3">
<div>
<div id="ambient-bar">
    <div id="ambient-header">
        <span class="amb-label">🎙 Ambient Scribe</span>
        <div class="amb-right">
            <div class="waveform" id="waveform">
                <div class="wbar"></div><div class="wbar"></div><div class="wbar"></div>
                <div class="wbar"></div><div class="wbar"></div><div class="wbar"></div>
                <div class="wbar"></div>
            </div>
            <button class="rec-btn" id="recBtn" type="button" onclick="toggleRec()">
                <span class="rdot"></span>
                <span id="recLabel">Begin Recording</span>
            </button>
        </div>
    </div>

    <div id="ambient-body">
        <div id="ambient-controls">
            <label>Insert into:</label>
            <select id="targetField">
                <option value="form_history">History</option>
                <option value="form_examination">Examination</option>
                <option value="form_plan">Plan</option>
            </select>

            <!-- Auto-Route toggle (div-based, no label element, immune to parent label CSS rule) -->
            <div id="arWrap" onclick="toggleAutoRoute()" style="display:flex;align-items:center;gap:8px;cursor:pointer;user-select:none;">
                <div id="arTrack" style="position:relative;width:38px;height:22px;border-radius:11px;background:#7c3aed;transition:background .2s;flex-shrink:0;">
                    <div id="arThumb" style="position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.25);transition:transform .2s;transform:translateX(16px);"></div>
                </div>
                <span style="font-size:11px;font-weight:700;color:#47377a;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap;">Auto-Route</span>
            </div>

            <div id="routeIndicators" style="display:flex;align-items:center;gap:5px;font-size:11px;font-weight:700;letter-spacing:.04em;">
                <span style="color:#888;margin-right:2px;">Routed to →</span>
                <span style="background:#fde68a;color:#92400e;border-radius:5px;padding:2px 8px;">History</span>
                <span style="background:#bfdbfe;color:#1e40af;border-radius:5px;padding:2px 8px;">Examination</span>
                <span style="background:#bbf7d0;color:#166534;border-radius:5px;padding:2px 8px;">Plan</span>
                <span style="background:#f3f4f6;color:#6b7280;border-radius:5px;padding:2px 8px;">? Unmatched</span>
            </div>

            <button class="amb-action-btn" id="addToNoteBtn" type="button" onclick="addToNote()" style="display:none;">
                ✦ Add to Note
            </button>
            <button class="amb-action-btn" id="clearTranscriptBtn" type="button" onclick="clearTranscript()" style="display:none;">
                Clear
            </button>
        </div>
        <div id="transcriptBody">
            Press <strong>Begin Recording</strong> to start the ambient scribe. Spoken content will appear here and can be inserted into any note field below.
        </div>
    </div>
</div>

<!-- ══════════ CLINIC NOTE FORM ══════════ -->
<form method="post" action="<?php echo $rootdir ?>/forms/clinic_note/new.php?id=<?php echo attr_url($formid) ?>"
      onsubmit="return top.restoreSession()">
    <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

    <table class="note-table">
        <tr>
            <td colspan="2">This Encounter</td>
        </tr>

        <tr>
            <td>History</td>
            <td>
                <textarea name="form_history" id="form_history" rows="6"><?php echo text($row['history'] ?? '') ?></textarea>
            </td>
        </tr>

        <tr>
            <td>Examination</td>
            <td>
                <textarea name="form_examination" id="form_examination" rows="6"><?php echo text($row['examination'] ?? '') ?></textarea>
            </td>
        </tr>

        <tr>
            <td>Plan</td>
            <td>
                <textarea name="form_plan" id="form_plan" rows="6"><?php echo text($row['plan'] ?? '') ?></textarea>
            </td>
        </tr>

        <tr>
            <td>Follow Up</td>
            <td>
                <div class="fu-row">
                    <label>
                        <?php echo rbinput('fu_required', '1', 'Required in:', 'followup_required') ?>
                        <input type="text" name="fu_timing" size="10"
                               title="<?php echo xla('When to follow up'); ?>"
                               value="<?php echo attr($row['followup_timing'] ?? '') ?>" />
                    </label>
                    <label><?php echo rbinput('fu_required', '2', 'Pending investigation', 'followup_required') ?></label>
                    <label><?php echo rbinput('fu_required', '0', 'None required', 'followup_required') ?></label>
                </div>
            </td>
        </tr>
    </table>

    <div class="btn-row">
        <button type="submit" class="btn-save" name="bn_save" value="Save" onclick="document.getElementById('do_save').value='1';"><?php echo xlt('Save'); ?></button>
        <button type="button" class="btn-cancel" onclick="parent.closeTab(window.name, false)"><?php echo xlt('Cancel'); ?></button>
    </div>

    <input type="hidden" name="id" value="<?php echo attr($formid ?? '') ?>" />
    <input type="hidden" name="do_save" id="do_save" value="" />
    <!-- Pass formid back into GET on next submit so UPDATE fires instead of INSERT -->
    <?php if ($formid): ?>
    <script>
        // Update the form action to include the new formid so re-saves hit UPDATE not INSERT
        document.querySelector('form').action = '<?php echo $rootdir ?>/forms/clinic_note/new.php?id=<?php echo attr_url($formid) ?>';
    </script>
    <?php endif; ?>
</form>
    </div></div>
<script>
// ── State ────────────────────────────────────────────────────────────────────
let isRec          = false;
let autoRoute      = true;   // on by default
let fullTranscript = '';
let currentSection = 'form_history'; // sticky — resets to History each recording

const transcriptBody  = document.getElementById('transcriptBody');
const clearBtn        = document.getElementById('clearTranscriptBtn');
const routeIndicators = document.getElementById('routeIndicators');
const targetSelect    = document.getElementById('targetField');

// ── Section triggers (update sticky currentSection) ──────────────────────────
// When doctor names a section, all following sentences go there automatically.
const SECTION_TRIGGERS = {
    'form_history': [
        'history','subjective','chief complaint','hpi',
        'patient says','patient reports','patient states','patient complains',
        'patient notes','patient mentions','presenting complaint',
        'past medical','family history','social history','past surgical',
    ],
    'form_examination': [
        'examination','exam','objective','physical exam','on exam',
        'vital signs','vitals','on inspection','on palpation','on auscultation',
        'physical findings','findings show','findings are','presents with findings',
        'physical exam reveals',
    ],
    'form_plan': [
        'plan','assessment and plan','treatment','management',
        'we will','i will','going to prescribe','going to refer','going to order',
        'follow up','follow-up','moving forward','next steps','going forward',
        'the plan is','for the plan','patient was advised','patient was counseled',
        'patient was instructed',
    ],
};

// ── Keyword hints (single-sentence override, does NOT change currentSection) ──
const KEYWORD_HINTS = [
    {
        field: 'form_examination',
        words: [
            'blood pressure','heart rate','pulse rate','temperature','oxygen saturation',
            'o2 sat','respiratory rate','bmi','alert and oriented','no acute distress',
            'nad','regular rate and rhythm','breath sounds','lungs clear','abdomen soft',
            'non-tender','no edema','range of motion','strength is','deep tendon reflex',
            'lymph nodes','skin reveals','lab results','labs show','labs reveal',
            'x-ray','mri shows','ct shows','ekg','ecg','urinalysis','cbc result',
            'hemoglobin','glucose level','a1c','creatinine','sodium level','potassium',
            'tsh','thyroid level',
        ],
    },
    {
        field: 'form_plan',
        words: [
            'prescribe','prescription','start medication','continue medication',
            'discontinue','stop taking','referral to','physical therapy',
            'occupational therapy','lab work ordered','blood work ordered',
            'imaging ordered','surgery scheduled','injection','vaccine','immunization',
            'return in','return to clinic','schedule an appointment',
            'urgent care','emergency room if',
        ],
    },
    {
        field: 'form_history',
        words: [
            'complains of','complaint of','presenting with','onset of','duration of',
            'worsening','improving','headache','nausea','vomiting','dizziness',
            'chest pain','back pain','neck pain','fatigue','weakness','numbness',
            'tingling','swelling','rash','fever','chills','cough','sore throat',
            'shortness of breath','difficulty breathing','allergy to','allergic to',
            'denies any','no history of',
        ],
    },
];

// ── Classifier ────────────────────────────────────────────────────────────────
function classifySentence(sentence) {
    const lower = sentence.toLowerCase();

    // 1. Section triggers — switch sticky section
    for (const [field, triggers] of Object.entries(SECTION_TRIGGERS)) {
        for (const trigger of triggers) {
            if (lower.includes(trigger)) {
                currentSection = field;
                return field;
            }
        }
    }

    // 2. Keyword hints — single-sentence override
    for (const hint of KEYWORD_HINTS) {
        for (const word of hint.words) {
            if (lower.includes(word)) return hint.field;
        }
    }

    // 3. Sticky fallback — stay in current section
    return currentSection;
}

// ── Append text to a textarea with flash ─────────────────────────────────────
function appendToField(fieldId, text) {
    const ta = document.getElementById(fieldId);
    if (!ta) return;
    ta.value = ta.value.trim() ? ta.value + '\n' + text.trim() : text.trim();
    ta.style.transition = 'background .3s';
    ta.style.background = '#f0ecfa';
    setTimeout(() => { ta.style.background = ''; }, 600);
}

// ── Auto-route toggle ─────────────────────────────────────────────────────────
function toggleAutoRoute() {
    autoRoute = !autoRoute;
    document.getElementById('arTrack').style.background = autoRoute ? '#7c3aed' : '#d1c7f0';
    document.getElementById('arThumb').style.transform  = autoRoute ? 'translateX(16px)' : 'translateX(0)';
    // Dropdown always visible — it is the fallback for unmatched sentences
}

// ── Speech Recognition ────────────────────────────────────────────────────────
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
let recognition = null;

if (SpeechRecognition) {
    recognition = new SpeechRecognition();
    recognition.continuous     = true;
    recognition.interimResults = true;
    recognition.lang           = 'en-US';

    recognition.onresult = function(event) {
        let interim = '';
        for (let i = event.resultIndex; i < event.results.length; i++) {
            const res = event.results[i];
            if (res.isFinal) {
                const sentence = res[0].transcript;
                fullTranscript += sentence + ' ';

                // Route every sentence — no silent drops
                // Auto-route: classify (section trigger → keyword hint → sticky fallback)
                // Manual: always use the dropdown selection
                const dest = autoRoute ? classifySentence(sentence) : targetSelect.value;
                appendToField(dest, sentence);
            } else {
                interim += res[0].transcript;
            }
        }
        transcriptBody.textContent = fullTranscript + (interim ? '…' + interim : '');
    };

    recognition.onend = function() {
        if (isRec) { try { recognition.start(); } catch(e) {} }
    };

    recognition.onerror = function(e) {
        console.warn('Speech recognition error:', e.error);
    };
} else {
    document.getElementById('recBtn').disabled = true;
    document.getElementById('recBtn').title = 'Speech recognition not supported in this browser';
}

function toggleRec() {
    if (!recognition) return;
    isRec = !isRec;
    const btn = document.getElementById('recBtn');
    const lbl = document.getElementById('recLabel');
    const wf  = document.getElementById('waveform');

    if (isRec) {
        fullTranscript = '';
        currentSection = 'form_history'; // reset sticky section each recording session
        transcriptBody.textContent = '● Listening…';
        transcriptBody.classList.add('live');
        btn.classList.add('recording');
        lbl.textContent = 'Stop Recording';
        wf.classList.add('on');
        clearBtn.style.display = 'inline-block';
        try { recognition.start(); } catch(e) {}
    } else {
        transcriptBody.classList.remove('live');
        btn.classList.remove('recording');
        lbl.textContent = 'Begin Recording';
        wf.classList.remove('on');
        try { recognition.stop(); } catch(e) {}
    }
}

function clearTranscript() {
    fullTranscript = '';
    transcriptBody.classList.remove('live');
    transcriptBody.innerHTML = 'Press <strong>Begin Recording</strong> to start the ambient scribe. In <strong>Auto-Route</strong> mode each sentence is sorted into History, Examination, or Plan as you speak. Say the section name to switch sections.';
    clearBtn.style.display = 'none';
}
</script>

</body>
</html>






























