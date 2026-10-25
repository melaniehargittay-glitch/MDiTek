<?php
// Include necessary files for database connection and CSRF protection
require_once(__DIR__ . "/../../globals.php");
require_once("$srcdir/api.inc.php");
use OpenEMR\Common\Csrf\CsrfUtils;

// Verify CSRF token
if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"])) {
    CsrfUtils::csrfNotVerified();
}

// Establish database connection
$pdo = new PDO('mysql:host=127.0.0.1;dbname=openemr', 'admin', 'LRyL414d');

// Initialize a variable to track success or error messages
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and retrieve input data
    $subjective = trim($_POST['subjective'] ?? '');
    $objective = trim($_POST['objective'] ?? '');
    $assessment = trim($_POST['assessment'] ?? '');
    $plan = trim($_POST['plan'] ?? '');
    
   
        // Capture additional fields with null handling
        $chief_complaint = trim($_POST['chief_complaint'] ?? '') ?: null;
        $onset = trim($_POST['onset'] ?? '') ?: null;
        $mechanism = trim($_POST['mechanism'] ?? '') ?: null;
        $pain_intensity = trim($_POST['pain_intensity'] ?? '') !== '' ? (int)$_POST['pain_intensity'] : null; // Convert to integer or set to null
        $pattern = trim($_POST['pattern'] ?? '') ?: null;
        $last_visit = trim($_POST['last_visit'] ?? '') ?: null;
        $primary_region = trim($_POST['primary_region'] ?? '') ?: null;
        $radiation = trim($_POST['radiation'] ?? '') ?: null;
        $neurological_sx = trim($_POST['neurological_sx'] ?? '') ?: null;
        $posture = trim($_POST['posture'] ?? '') ?: null;
        $gait = trim($_POST['gait'] ?? '') ?: null;
        $palpation = trim($_POST['palpation'] ?? '') ?: null;
        $muscle_tone = trim($_POST['muscle_tone'] ?? '') ?: null;
        $flexion = trim($_POST['flexion'] ?? '') !== '' ? (int)$_POST['flexion'] : null; // Convert to integer or set to null
        $extension = trim($_POST['extension'] ?? '') !== '' ? (int)$_POST['extension'] : null; // Convert to integer or set to null
        $right_lat_flex = trim($_POST['right_lat_flex'] ?? '') !== '' ? (int)$_POST['right_lat_flex'] : null; // Convert to integer or set to null
        $left_lat_flex = trim($_POST['left_lat_flex'] ?? '') !== '' ? (int)$_POST['left_lat_flex'] : null; // Convert to integer or set to null
        $rom_quality = trim($_POST['rom_quality'] ?? '') ?: null;
        $response_to_care = trim($_POST['response_to_care'] ?? '') ?: null;


    // Prepare the SQL statement
    $stmt = $pdo->prepare("INSERT INTO form_soap 
        (subjective, objective, assessment, plan, chief_complaint, onset, mechanism, pain_intensity, pattern, last_visit, 
        primary_region, radiation, neurological_sx, posture, gait, palpation, muscle_tone, flexion, extension, 
        right_lat_flex, left_lat_flex, rom_quality, response_to_care)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?)");

    // Execute the statement
    if ($stmt->execute([$subjective, $objective, $assessment, $plan, $chief_complaint, $onset, $mechanism, 
                        $pain_intensity, $pattern, $last_visit, $primary_region, $radiation, 
                        $neurological_sx, $posture, $gait, $palpation, $muscle_tone, 
                        $flexion, $extension, $right_lat_flex, $left_lat_flex, 
                        $rom_quality, $response_to_care])) {
        // Set success message
        $message = "SOAP notes saved successfully.";
    } else {
        // Handle error
        $message = "Error saving SOAP notes.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{xlt t='SOAP'}</title>
    {headerTemplate}
    <style>
        :root {
            --bg: #e2e2e2;
            --bg2: #e2e2e2;
            --surface: #e2e2e2;
            --border: #999999;
            --border2: #999999;
            --text: #000000;
            --muted: #7a7367;
            --muted2: #a09890;
            --accent: #47377a;
            --accent2: #b69dd6;
            --accent-bg: rgba(61,107,94,0.07);
            --gold: #9a6f3a;
            --gold-bg: rgba(154,111,58,0.08);
            --red: #f40303;
            --red-bg: rgba(154,58,58,0.08);
            --blue: #3a5a7a;
            --blue-bg: rgba(58,90,122,0.08);
            --radius: 3px;
        }

        /* Rows inside sections */
        .row-strip {
            display: grid;
            border-bottom: 1px solid var(--border);
        }

        .row-strip.c2 { grid-template-columns: 1fr 1fr; }
        .row-strip.c3 { grid-template-columns: 1fr 1fr 1fr; }
        .row-strip.c4 { grid-template-columns: repeat(4, 1fr); }
        .row-strip.c5 { grid-template-columns: repeat(5, 1fr); }

        .cell {
            padding: 0.55rem 0.9rem;
            border-right: 1px solid var(--border);
        }

        .cell:last-child { border-right: none; }

        .cell label {
            font-size: 0.8rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            display: block;
            margin-bottom: 4px;
        }

        .cell input,
        .cell select {
            font-size: 0.76rem;
            background: transparent;
            color: var(--text);
            width: 100%;
            padding: 10px;
        }

        .cell input::placeholder { color: var(--muted2); }

        .cell select {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='9' height='5'%3E%3Cpath d='M1 1l3.5 3L8 1' stroke='%23a09890' stroke-width='1.4' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 15px center;
            padding-right: 14px;
        }

        .cell select option { background: var(--surface); }

        /* Quick-tag rows */
.qtags {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  padding: 0.5rem 0.9rem 0.8rem;
  border-top: 1px solid var(--border);
}

.qtag {
  font-size: 0.58rem;
  letter-spacing: 0.04em;
  padding: 3px 9px;
  border: 1px solid var(--border);
  border-radius: 20px;
  background: transparent;
  color: #000;
  cursor: pointer;
  user-select: none;
  transition: all 0.12s;
}

.qtag:hover {
  border-color: var(--text);
  color: var(--text);
}

/* Outcome buttons */
.outcome-strip {
  display: grid;
  grid-template-columns: repeat(3,1fr);
  border-bottom: 1px solid var(--border);
}

.oc-cell {
  padding: 0.55rem 0.9rem;
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.oc-cell:last-child { border-right: none; }

.oc-cell label {
  font-size: 0.55rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--muted2);
}

.oc-btns { display: flex; gap: 4px; flex-wrap: wrap; }

.ocb {
  font-size: 0.7rem;
  padding: 3px 8px;
  border: 1px solid var(--border2);
  border-radius: 2px;
  background: transparent;
  color: var(--muted);
  cursor: pointer;
  transition: all 0.12s;
}

.ocb:hover { border-color: var(--accent); color: var(--accent); }
.ocb.g { border-color: var(--accent); color: var(--accent); background: var(--accent-bg); }
.ocb.r { border-color: var(--red);    color: var(--red);    background: var(--red-bg); }

/* Stagger in */
.soap-section:nth-child(1) { animation: fadeUp 0.4s ease 0.15s both; }
.soap-section:nth-child(2) { animation: fadeUp 0.4s ease 0.22s both; }
.soap-section:nth-child(3) { animation: fadeUp 0.4s ease 0.29s both; }
.soap-section:nth-child(4) { animation: fadeUp 0.4s ease 0.36s both; }

.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1.1rem;
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
}

.sh-left {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.soapdrp{
    font-size:12px;
    padding:10px 25px 10px 5px;
}

.soap-glyph {
  font-style: italic;
  font-size: 2rem;
  color: var(--accent);
  line-height: 1;
  width: 26px;
}

.soap-info strong {
  font-size: 1rem;
  font-weight: bold;
  color: var(--text);
  letter-spacing: 0.06em;
  display: block;
}

.soap-info span {
  font-size: 0.6rem;
  color: var(--muted);
  letter-spacing: 0.12em;
  text-transform: uppercase;
}

/* Record button */
.rec-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 0.5rem 1.1rem;
  background: var(--text);
  color: var(--bg);
  border: none;
  border-radius: var(--radius);
  font-size: 0.75rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}

.rec-btn:hover { background: #007bff; }

.rec-btn.recording {
  background: var(--red);
  animation: pulse-rec 1.6s ease-in-out infinite;
}

.waveform {
  display: flex;
  align-items: center;
  gap: 2px;
  height: 14px;
  opacity: 0;
  transition: opacity 0.3s;
}

.waveform.on { opacity: 1; }

.wbar {
  width: 2px;
  background: var(--red);
  border-radius: 1px;
  animation: wv 0.8s ease-in-out infinite;
}
.wbar:nth-child(2) { animation-delay: 0.11s; }
.wbar:nth-child(3) { animation-delay: 0.22s; }
.wbar:nth-child(4) { animation-delay: 0.33s; }
.wbar:nth-child(5) { animation-delay: 0.44s; }
.wbar:nth-child(6) { animation-delay: 0.22s; }
.wbar:nth-child(7) { animation-delay: 0.11s; }

@keyframes wv {
  0%,100% { height: 2px; }
  50%      { height: 12px; }
}

@keyframes pulse-rec {
  0%,100% { box-shadow: 0 0 0 0 rgba(154,58,58,0.4); }
  50%      { box-shadow: 0 0 0 6px rgba(154,58,58,0); }
}

.rdot {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: currentColor;
  flex-shrink: 0;
}

/* ── TRANSCRIPT ──────────────────────────────── */
.transcript {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
  transition: border-color 0.3s;
  animation: fadeUp 0.4s ease 0.1s both;
}

.transcript.live-rec {
  border-color: var(--red);
}

.transcript-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.6rem 1rem;
  background: #9588e8;
  border-bottom: 1px solid var(--border);
  color:#FFF;
}

.transcript-body {
    padding: 30px;
    border: 1px solid #333;
    background: #ffffff;
}

.transcript-label {
  font-size: 0.8rem;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #FFF;
  font-weight: bold;
}

/* Chip-toggle groups */
.chip-group {
  padding: 0.6rem 0.9rem;
  border-bottom: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.chip-group-label {
  font-size: 0.55rem;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--muted2);
}

.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.chip {
  
  font-size: 0.9rem;
  letter-spacing: 0.04em;
  padding: 3px 10px;
  border: 1px solid var(--border2);
  border-radius: 20px;
  background:#c5c0fc;
  color: #000;
  cursor: pointer;
  user-select: none;
  transition: all 0.12s;
}

.chip:hover { border-color: var(--accent); color: var(--accent); }

.chip.on-green {
  border-color: var(--accent);
  color: var(--accent);
  background: var(--accent-bg);
}

.chip.on-blue {
  border-color: var(--blue);
  color: var(--blue);
  background: var(--blue-bg);
}

.chip.on-gold {
  border-color: var(--gold);
  color: var(--gold);
  background: var(--gold-bg);
}

.chip.on-red {
  border-color: var(--red);
  color: var(--red);
  background: var(--red-bg);
}

.header-right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 8px;
}

button#addToSoapBtn {
    background: #47377a;
    color: #FFF;
    margin-right: 15px;
}

@media (max-width: 700px) {
  .patient-strip { grid-template-columns: 1fr 1fr; }
  .row-strip.c5 { grid-template-columns: repeat(3,1fr); }
  .row-strip.c4 { grid-template-columns: repeat(2,1fr); }
  header { grid-template-columns: 1fr; }
  .header-right { align-items: flex-start; }
}

.section-head {
    padding: 10px; /* Add some padding */
    border-bottom: 1px solid #ccc; /* Optional: Add a bottom border */
}

.ai-btn {
    margin-left: 10px; /* Optional: Add some space between the text and the button */
    background-color: #007bff; /* Button background color */
    color: white; /* Button text color */
    border: none; /* No border */
    padding: 5px 10px; /* Padding for the button */
    border-radius: 5px; /* Rounded corners */
    cursor: pointer; /* Pointer cursor on hover */
}

.ai-btn:hover {
    background-color: #0056b3; /* Darker shade on hover */
}

    </style>
</head>
<body>
    <div class="container mt-3">
        <div class="row">
            <div class="col-12" style="padding-top:30px;">
                <h2>{xlt t='SOAP Notes'}</h2>
                <div class="transcript" id="transcriptBox">
                    <div class="transcript-top" style="display: flex; align-items: center; justify-content: space-between;">
                        <span class="transcript-label">Ambient Summary</span>
                        <div style="display: flex; align-items: center;">
                            <div class="waveform" id="waveform" style="margin-right: 10px;">
                                <div class="wbar"></div>
                                <div class="wbar"></div>
                                <div class="wbar"></div>
                                <div class="wbar"></div>
                                <div class="wbar"></div>
                                <div class="wbar"></div>
                                <div class="wbar"></div>
                            </div>
                            <div class="header-right">
                                <button class="rec-btn" id="recBtn" type="button" onclick="toggleRec()">
                                    <span class="rdot"></span>
                                    <span id="recLabel">Begin Recording</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <!-- Dropdown and Buttons -->
                    <label for="soapSection" style="margin-left: 25px; font-weight:bold;">SELECT SOAP SECTION:</label>
                    <div style="margin-left: 25px; display: flex; align-items: center; margin-bottom: 10px;">
                        <select id="soapSection" class="soapdrp" style="margin-right: 10px;">
                            <option value="subjective">Subjective</option>
                            <option value="objective">Objective</option>
                            <option value="assessment">Assessment</option>
                            <option value="plan">Plan</option>
                        </select>
                        <button id="addToSoapBtn" type="button" style="display: none;" onclick="addToSoapNote()">Add to SOAP Note</button>
                        <button id="clearTranscriptBtn" type="button" style="display: none;" onclick="clearTranscript()">Clear Transcript</button>
                    </div>
                    <!-- Transcript Body -->
                    <div class="transcript-body" id="transcriptBody">
                        Press "Begin Recording" to start capturing the visit conversation. The transcript will populate here and can be used to auto-fill each SOAP section below.
                    </div>
                </div>
        </div>
        <form name="soap" method="post" action="{$FORM_ACTION}/interface/forms/soap/save_soap.php" onsubmit="return top.restoreSession()">
            <input type="hidden" name="csrf_token_form" value="{$CSRF_TOKEN_FORM|attr}" />
            <fieldset>
                <div class="container">
                    <div class="form-group">
                        <div class="soap-wrapper">
                            <div class="soap-section">
                                <div class="section-head" style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="sh-left" style="display: flex; align-items: center;">
                                        <span class="soap-glyph">S</span>
                                        <div class="soap-info">
                                            <strong>Subjective</strong>
                                            <span>Patient History · Chief Complaint · HPI</span>
                                        </div>
                                    </div>
                                    <button class="ai-btn" type="button" onclick="fillAI('s', event)">✦ AI Fill</button>
                                </div>

                                <div class="row-strip c5">
                                    <div class="cell">
                                        <label>Chief Complaint</label>
                                        <select name="chief_complaint" id="chief_complaint">
                                            <option value="">—</option>
                                            <option>Low Back Pain</option>
                                            <option>Neck Pain</option>
                                            <option>Mid Back Pain</option>
                                            <option>Headache</option>
                                            <option>Sciatica / Radiculopathy</option>
                                            <option>Shoulder Pain</option>
                                            <option>Hip / SI Pain</option>
                                            <option>Extremity Pain</option>
                                            <option>Wellness</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Onset</label>
                                        <select name="onset" id="onset">
                                            <option value="">—</option>
                                            <option>Acute — <72 hrs</option>
                                            <option>Subacute — 1–4 wks</option>
                                            <option>Chronic — >3 mo</option>
                                            <option>Recurring</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Mechanism</label>
                                        <select name="mechanism" id="mechanism">
                                            <option value="">—</option>
                                            <option>Lifting / Bending</option>
                                            <option>MVA / Whiplash</option>
                                            <option>Repetitive / Postural</option>
                                            <option>Sports / Athletic</option>
                                            <option>Fall / Trauma</option>
                                            <option>Insidious</option>
                                            <option>Unknown</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Pain Intensity (0–10)</label>
                                        <select name="pain_intensity" id="pain_intensity">
                                            <option value="">—</option>
                                            <option value="0">0</option>
                                            <option value="1">1</option>
                                            <option value="2">2</option>
                                            <option value="3">3</option>
                                            <option value="4">4</option>
                                            <option value="5">5</option>
                                            <option value="6">6</option>
                                            <option value="7">7</option>
                                            <option value="8">8</option>
                                            <option value="9">9</option>
                                            <option value="10">10</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Pattern</label>
                                        <select name="pattern" id="pattern">
                                            <option value="">—</option>
                                            <option>Constant</option>
                                            <option>Intermittent</option>
                                            <option>Episodic</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>vs. Last Visit</label>
                                        <select name="last_visit" id="last_visit">
                                            <option value="">—</option>
                                            <option>Better</option>
                                            <option>Same</option>
                                            <option>Worse</option>
                                            <option>First Visit</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Primary Region</label>
                                        <select name="primary_region" id="primary_region">
                                            <option value="">—</option>
                                            <option>Cervical (C1–C7)</option>
                                            <option>Cervicothoracic Jxn</option>
                                            <option>Thoracic (T1–T12)</option>
                                            <option>Thoracolumbar Jxn</option>
                                            <option>Lumbar (L1–L5)</option>
                                            <option>Lumbosacral Jxn</option>
                                            <option>Sacrum / SI Joints</option>
                                            <option>Pelvis / Coccyx</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Radiation</label>
                                        <select name="radiation" id="radiation">
                                            <option value="">—</option>
                                            <option>None</option>
                                            <option>Into Right Arm</option>
                                            <option>Into Left Arm</option>
                                            <option>Into Right Leg</option>
                                            <option>Into Left Leg</option>
                                            <option>Bilateral Legs</option>
                                            <option>Into Head / Occiput</option>
                                            <option>Into Chest / Rib</option>
                                            <option>Into Groin</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Neurological Sx</label>
                                        <select name="neurological_sx" id="neurological_sx">
                                            <option value="">—</option>
                                            <option>None</option>
                                            <option>Numbness</option>
                                            <option>Tingling / Paresthesia</option>
                                            <option>Weakness</option>
                                            <option>Numbness + Tingling</option>
                                            <option>Drop Foot</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="soap-section">
                                    <textarea name="subjective" id="sNote" placeholder="Patient reports… Aggravating factors: ___. Relieving factors: ___. Sleep quality: ___. ADL limitations: ___. Prior treatment history: ___." class="form-control" cols="60" rows="6" onkeyup="top.isSoapEdit = true;">{$data->get_subjective()|text}</textarea>
                                    <div class="qtags">
                                        <span class="qtag" onclick="qt('sNote','Worse with prolonged sitting')">worse w/ sitting</span>
                                        <span class="qtag" onclick="qt('sNote','Worse with forward flexion')">worse w/ flexion</span>
                                        <span class="qtag" onclick="qt('sNote','Relieved by heat')">relieved by heat</span>
                                        <span class="qtag" onclick="qt('sNote','Relieved by ice')">relieved by ice</span>
                                        <span class="qtag" onclick="qt('sNote','Morning stiffness lasting ~30 min')">AM stiffness</span>
                                        <span class="qtag" onclick="qt('sNote','Sleep disrupted by pain')">sleep disrupted</span>
                                        <span class="qtag" onclick="qt('sNote','No bowel/bladder dysfunction')">no B/B dysfx</span>
                                        <span class="qtag" onclick="qt('sNote','Improved since last visit')">improved since last visit</span>
                                        <span class="qtag" onclick="qt('sNote','Same as last visit')">same as last visit</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <fieldset>
                    <div class="container">
                        <div class="form-group">
                            <div class="soap-section">
                                <div class="section-head" style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="sh-left" style="display: flex; align-items: center;">
                                        <span class="soap-glyph">O</span>
                                        <div class="soap-info">
                                            <strong>Objective</strong>
                                            <span>Physical Exam Findings · Lab Results</span>
                                        </div>
                                    </div>
                                    <button class="ai-btn" type="button" onclick="fillAI('o', event)">✦ AI Fill</button>
                                </div>
                                <!-- Posture & gait -->
                                <div class="row-strip c4">
                                    <div class="cell">
                                        <label>Posture</label>
                                        <select name="posture" id="posture">
                                            <option value="">—</option>
                                            <option>WNL</option>
                                            <option>Ant. Head Carriage</option>
                                            <option>Hyperkyphosis</option>
                                            <option>Hyperlordosis — Lx</option>
                                            <option>Hypolordosis — Lx</option>
                                            <option>Hypolordosis — Cx</option>
                                            <option>Scoliosis</option>
                                            <option>Pelvic Tilt — R High</option>
                                            <option>Pelvic Tilt — L High</option>
                                            <option>Lat. Shift</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Gait</label>
                                        <select name="gait" id="gait">
                                            <option value="">—</option>
                                            <option>WNL</option>
                                            <option>Antalgic</option>
                                            <option>Trendelenburg</option>
                                            <option>Guarded</option>
                                            <option>Reduced stride</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Palpation Findings</label>
                                        <select name="palpation" id="palpation">
                                            <option value="">—</option>
                                            <option>WNL</option>
                                            <option>Tenderness +</option>
                                            <option>Spasm +</option>
                                            <option>Hypertonicity</option>
                                            <option>Tenderness + Spasm</option>
                                            <option>Trigger Points</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Muscle Tone</label>
                                        <select name="muscle_tone" id="muscle_tone">
                                            <option value="">—</option>
                                            <option>WNL</option>
                                            <option>Increased — Unilateral R</option>
                                            <option>Increased — Unilateral L</option>
                                            <option>Increased — Bilateral</option>
                                            <option>Decreased</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- ROM -->
                                <div class="row-strip c5">
                                    <div class="cell"><label>Flexion</label><input type="text" name="flexion" placeholder="°" id="flexion"></div>
                                    <div class="cell"><label>Extension</label><input type="text" name="extension" placeholder="°" id="extension"></div>
                                    <div class="cell"><label>R. Lat. Flex.</label><input type="text" name="right_lat_flex" placeholder="°" id="right_lat_flex"></div>
                                    <div class="cell"><label>L. Lat. Flex.</label><input type="text" name="left_lat_flex" placeholder="°" id="left_lat_flex"></div>
                                    <div class="cell"><label>ROM Quality</label>
                                        <select name="rom_quality" id="rom_quality">
                                            <option value="">—</option>
                                            <option>Smooth / Full</option>
                                            <option>Guarded</option>
                                            <option>With Pain</option>
                                            <option>Restricted</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Ortho tests -->
                                <div class="chip-group">
                                    <span class="chip-group-label">Orthopedic Tests (click = positive)</span>
                                    <div class="chips" id="orthoChips">
                                        <span class="chip" onclick="toggleChip(this,'on-red')">SLR — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">SLR — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Kemp's — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Kemp's — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Valsalva</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Soto Hall</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Spurling — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Spurling — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Distraction</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Compression</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">FABERE — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">FABERE — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Gaenslen's — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Gaenslen's — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Ober — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Ober — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Yergason — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Yergason — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Cervical Compression</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Adson's — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-red')">Adson's — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">All Tests (−)</span>
                                    </div>
                                </div>

                                <!-- Neuro -->
                                <div class="row-strip c3">
                                    <div class="cell">
                                        <label>DTRs</label>
                                        <select name="dtrs" id="dtrs">
                                            <option value="">—</option>
                                            <option>2+ Bilateral (WNL)</option>
                                            <option>Diminished — R</option>
                                            <option>Diminished — L</option>
                                            <option>Absent — R</option>
                                            <option>Absent — L</option>
                                            <option>Hyperreflexia</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Sensation</label>
                                        <select name="sensation" id="sensation">
                                            <option value="">—</option>
                                            <option>Intact Bilateral</option>
                                            <option>Diminished — R</option>
                                            <option>Diminished — L</option>
                                            <option>Absent — R</option>
                                            <option>Absent — L</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Motor Strength</label>
                                        <select name="motor_strength" id="motor_strength">
                                            <option value="">—</option>
                                            <option>5/5 Bilateral</option>
                                            <option>4/5 — R Lower Ext.</option>
                                            <option>4/5 — L Lower Ext.</option>
                                            <option>4/5 — R Upper Ext.</option>
                                            <option>4/5 — L Upper Ext.</option>
                                            <option>3/5 or less — R</option>
                                            <option>3/5 or less — L</option>
                                        </select>
                                    </div>
                                </div>
                                <textarea name="objective" id="oNote" placeholder="Additional exam findings, imaging review, segmental motion assessment, soft tissue status…" class="form-control" cols="60" rows="6" onkeyup="top.isSoapEdit = true;">{$data->get_objective()|text}</textarea>
                                <div class="qtags">
                                    <span class="qtag" onclick="qt('oNote','Paraspinal spasm noted bilateral')">paraspinal spasm</span>
                                    <span class="qtag" onclick="qt('oNote','Motion palpation restricted at segment')">restricted MP</span>
                                    <span class="qtag" onclick="qt('oNote','No red flags identified')">no red flags</span>
                                    <span class="qtag" onclick="qt('oNote','X-ray reviewed — consistent with findings')">X-ray reviewed</span>
                                    <span class="qtag" onclick="qt('oNote','MRI reviewed — disc herniation noted at')">MRI reviewed</span>
                                    <span class="qtag" onclick="qt('oNote','Functional improvement noted since last visit')">functional improvement</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <fieldset>
                    <div class="container">
                        <div class="form-group">
                            <div class="soap-section">
                                <div class="section-head" style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="sh-left" style="display: flex; align-items: center;">
                                        <span class="soap-glyph">A</span>
                                        <div class="soap-info">
                                            <strong>Assessment</strong>
                                            <span>Diagnosis · Clinical Impression</span>
                                        </div>
                                    </div>
                                    <button class="ai-btn" type="button" onclick="fillAI('a', event)">✦ AI Fill</button>
                                </div>

                                <!-- Dx fields -->
                                <div class="row-strip c3">
                                    <div class="cell">
                                        <label>Response to Care</label>
                                        <select name="response_to_care" id="response_to_care">
                                            <option value="">—</option>
                                            <option>Improving as Expected</option>
                                            <option>Improving — Slower than Expected</option>
                                            <option>Plateau Noted</option>
                                            <option>Not Responding</option>
                                            <option>Acute — Initial Presentation</option>
                                            <option>Exacerbation of Chronic</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Subluxation levels -->
                                <div class="chip-group">
                                    <span class="chip-group-label">Subluxation / Fixation Levels</span>
                                    <div class="chips">
                                        <span style="font-size:0.9rem;color:var(--muted2);letter-spacing:0.1em;align-self:center;margin-right:4px; font-weight:bold;">CX</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">C1</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">C2</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">C3</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">C4</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">C5</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">C6</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">C7</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">CT Jxn</span>
                                        <span style="font-size:0.9rem;color:var(--muted2);letter-spacing:0.1em;align-self:center;margin-left:4px;margin-right:4px;font-weight:bold;">TX</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T1</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T2</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T3</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T4</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T5</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T6</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T7</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T8</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T9</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T10</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T11</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">T12</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">TL Jxn</span>
                                        <span style="font-size:0.9rem;color:var(--muted2);letter-spacing:0.1em;align-self:center;margin-left:4px;margin-right:4px;font-weight:bold;">LX</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">L1</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">L2</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">L3</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">L4</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">L5</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">LS Jxn</span>
                                        <span style="font-size:0.9rem;color:var(--muted2);letter-spacing:0.1em;align-self:center;margin-left:4px;margin-right:4px;font-weight:bold;">SA</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">S1</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">S2</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">Sacrum</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">SI — R</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">SI — L</span>
                                        <span class="chip" onclick="toggleChip(this,'on-gold')">Coccyx</span>
                                    </div>
                                </div>
                                <textarea name="assessment" class="form-control" cols="60" rows="6" id="aNote"
                                          placeholder="Vertebral subluxation complex noted at ___. Clinical impression: ___&#10;Functional limitations: ___. Prognosis: ___. Medically necessary because: ___." 
                                          onkeyup="top.isSoapEdit = true;">{$data->get_assessment()|text}</textarea>
                                <div class="qtags">
                                    <span class="qtag" onclick="qt('aNote','Good prognosis with continued chiropractic care')">good prognosis</span>
                                    <span class="qtag" onclick="qt('aNote','Disc involvement suspected')">disc involvement</span>
                                    <span class="qtag" onclick="qt('aNote','Nerve root irritation present')">nerve root irritation</span>
                                    <span class="qtag" onclick="qt('aNote','Postural contributors identified')">postural contributors</span>
                                    <span class="qtag" onclick="qt('aNote','Functional improvement trending positively')">positive trend</span>
                                    <span class="qtag" onclick="qt('aNote','Plateau observed — re-examination warranted')">plateau noted</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <fieldset>
                    <div class="container">
                        <div class="form-group">
                            <div class="soap-section">
                                <div class="section-head" style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="sh-left" style="display: flex; align-items: center;">
                                        <span class="soap-glyph">P</span>
                                        <div class="soap-info">
                                            <strong>Plan</strong>
                                            <span>Treatment Plan · Follow-Up Instructions</span>
                                        </div>
                                    </div>
                                    <button class="ai-btn" type="button" onclick="fillAI('p', event)">✦ AI Fill</button>
                                </div>

                                <!-- Technique -->
                                <div class="chip-group">
                                    <span class="chip-group-label">Adjustment Technique</span>
                                    <div class="chips">
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Diversified</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Gonstead</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Thompson Drop</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Activator</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">SOT</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Cox Flexion-Dist.</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">HVLA</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Logan Basic</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Upper Cervical (NUCCA)</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Toggle Recoil</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">Instrument Assisted</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green')">No Adjustment Today</span>
                                    </div>
                                </div>

                                <!-- Adjunct therapy -->
                                <div class="chip-group">
                                    <span class="chip-group-label">Adjunct Therapies Administered</span>
                                    <div class="chips">
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">EMS / E-Stim</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Ultrasound</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Cold Laser / LLLT</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Ice Pack</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Heat Pack</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Mechanical Traction</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Intersegmental Traction</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Dry Needling</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Cupping</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Myofascial Release</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Kinesio Taping</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Rehab / Exercise</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Nutritional Counsel</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue')">Cryotherapy</span>
                                    </div>
                                </div>

                                <div class="outcome-strip">
                                    <div class="oc-cell">
                                        <label>Post-Adjustment Response</label>
                                        <div class="oc-btns">
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">Improved</button>
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">Same</button>
                                            <button type="button" class="ocb" onclick="ocToggle(this,'r')">Worsened</button>
                                        </div>
                                    </div>
                                    <div class="oc-cell">
                                        <label>Return Frequency</label>
                                        <div class="oc-btns">
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">3× / wk</button>
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">2× / wk</button>
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">1× / wk</button>
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">Biweekly</button>
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">PRN</button>
                                        </div>
                                    </div>
                                    <div class="oc-cell">
                                        <label>Phase of Care</label>
                                        <div class="oc-btns">
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">Acute</button>
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">Corrective</button>
                                            <button type="button" class="ocb" onclick="ocToggle(this,'g')">Maintenance</button>
                                        </div>
                                    </div>
                                </div>
                                <textarea name="plan" class="form-control" cols="60" rows="6" id="pNote"
                                placeholder="Adjustments performed at: ___&#10;Therapies: ___&#10;Home care: Ice/heat ___ min, ___ × daily. Stretches / exercises: ___&#10;Restrictions: ___&#10;Patient education: ___&#10;Referral: ___&#10;Return: ___" onkeyup="top.isSoapEdit = true;">{$data->get_plan()|text}</textarea>
                                <div class="qtags">
                                    <span class="qtag" onclick="qt('pNote','Ice 15 min 3× daily')">ice 15min 3×/day</span>
                                    <span class="qtag" onclick="qt('pNote','Heat 15 min as needed for stiffness')">heat PRN stiffness</span>
                                    <span class="qtag" onclick="qt('pNote','Avoid prolonged sitting > 30 min without break')">no prolonged sitting</span>
                                    <span class="qtag" onclick="qt('pNote','Continue home exercise program')">continue HEP</span>
                                    <span class="qtag" onclick="qt('pNote','No heavy lifting > 10 lbs')">no heavy lifting</span>
                                    <span class="qtag" onclick="qt('pNote','Ergonomic workstation assessment recommended')">ergonomic eval</span>
                                    <span class="qtag" onclick="qt('pNote','Patient tolerated treatment well with no adverse effects')">tolerated well</span>
                                    <span class="qtag" onclick="qt('pNote','Referral to MRI if no improvement in 4 weeks')">MRI referral PRN</span>
                                    <span class="qtag" onclick="qt('pNote','Co-manage with PCP')">co-manage w/ PCP</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    </fieldset>
                    <div class="form-group">
                        <div class="btn-group" role="group">
                            <button type="submit" class="btn btn-primary btn-save" name="Submit">{xlt t='Save'}</button>
                            <button type="button" class="btn btn-secondary btn-cancel" id="btnClose">{xlt t='Cancel'}</button>
                        </div>
                        <input type="hidden" name="id" value="{$data->get_id()|attr}" />
                        <input type="hidden" name="activity" value="{$data->get_activity()|attr}" />
                        <input type="hidden" name="pid" value="{$data->get_pid()|attr}" />
                        <input type="hidden" name="process" value="true" />
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const close = function() {
            if (top.isSoapEdit === true) {
                dlgopen('', '', 450, 125, '', '<div class="text-danger">{xla t='Warning'}</div>', {
                    type: 'Alert',
                    html: '<p>{xla t='Do you want to close the tabs?'}</p>',
                    buttons: [
                        { text: '{xla t='Cancel'}', close: true, style: 'default btn-sm' },
                        { text: '{xla t='Close'}', close: true, style: 'danger btn-sm', click: closeSoap },
                    ],
                    allowDrag: false,
                    allowResize: false,
                });
            } else {
                top.restoreSession();
                location.href = 'javascript:parent.closeTab(window.name, false)';
            }
        }

        const closeSoap = function() {
            top.isSoapEdit = false;
            top.restoreSession();
            location.href = 'javascript:parent.closeTab(window.name, false)';
        }
        $('#btnClose').click(close);

       
// ── Chip toggles ───────────────────────────────────
function toggleChip(el, cls) {
    if (el.classList.contains(cls)) {
        el.classList.remove(cls);
        el.textContent = el.textContent.replace(' (+)', '');
    } else {
        el.classList.add(cls);
        if (cls === 'on-red' && !el.textContent.includes('(+)') && !el.textContent.includes('(−)')) {
            el.textContent += ' (+)';
        }
    }
}

// ── Outcome button toggle ──────────────────────────
function ocToggle(el, cls) {
    // Remove 'g' and 'r' classes from all sibling buttons
    const siblings = el.parentElement.querySelectorAll('.ocb');
    siblings.forEach(b => {
        b.classList.remove('g');
        b.classList.remove('r');
    });

    // Add the selected class to the clicked button
    el.classList.add(cls);
}

// ── Quick-tag insert ───────────────────────────────
function qt(id, text) {
    const ta = document.getElementById(id);
    const sep = ta.value.length && !/[\s\n]$/.test(ta.value) ? ' ' : '';
    ta.value += sep + text + '.';
    ta.focus();
}

// ── Recording ─────────────────────────────────────
let isRec = false;
let fullTranscript = ''; // Variable to store the full transcript

const transcriptBody = document.getElementById('transcriptBody');
const addToSoapBtn = document.getElementById('addToSoapBtn'); // Get the button element
const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
const recognition = new SpeechRecognition();

recognition.continuous = true; // Keep recognition active
recognition.interimResults = true; // Get interim results

// Event handler when speech recognition returns a result
recognition.onresult = function(event) {
    for (let i = event.resultIndex; i < event.results.length; i++) {
        const result = event.results[i];
        
        // Only append final results
        if (result.isFinal) {
            fullTranscript += result[0].transcript + ' '; // Add to the full transcript only if it's final
        }
    }
    transcriptBody.textContent = fullTranscript; // Display the full transcript
};

// Event handler when speech recognition starts
recognition.onstart = function() {
    console.log('Voice recognition started. Speak into the microphone.');
};

// Event handler when speech recognition ends
recognition.onend = function() {
    console.log('Voice recognition ended.');
    if (fullTranscript.trim()) {
        addToSoapBtn.style.display = 'block'; // Show the button if there's a transcript
    }
};

function toggleRec() {
    isRec = !isRec;
    const btn = document.getElementById('recBtn');
    const lbl = document.getElementById('recLabel');
    const wf = document.getElementById('waveform');
    const box = document.getElementById('transcriptBox');

    if (isRec) {
        fullTranscript = ''; // Reset the transcript when starting a new recording
        transcriptBody.textContent = '● Listening…'; // Clear previous display
        btn.classList.add('recording');
        lbl.textContent = 'Stop Recording';
        wf.classList.add('on');
        box.classList.add('live-rec');
        recognition.start(); // Start speech recognition

        // Show the buttons when recording starts
        addToSoapBtn.style.display = 'inline-block'; // Show Add to SOAP Note button
        clearTranscriptBtn.style.display = 'inline-block'; // Show Clear button
    } else {
        btn.classList.remove('recording');
        lbl.textContent = 'Begin Recording';
        wf.classList.remove('on');
        box.classList.remove('live-rec');
        recognition.stop(); // Stop speech recognition
    }
}

function addToSoapNote() {
    const selectedSection = document.getElementById('soapSection').value; // Get selected section
    const subjectiveTextarea = document.getElementById('sNote');
    const objectiveTextarea = document.getElementById('oNote');
    const assessmentTextarea = document.getElementById('aNote');
    const planTextarea = document.getElementById('pNote');

    // Append transcript to the selected section
    switch (selectedSection) {
        case 'subjective':
            subjectiveTextarea.value += fullTranscript.trim() + '\n';
            break;
        case 'objective':
            objectiveTextarea.value += fullTranscript.trim() + '\n';
            break;
        case 'assessment':
            assessmentTextarea.value += fullTranscript.trim() + '\n';
            break;
        case 'plan':
            planTextarea.value += fullTranscript.trim() + '\n';
            break;
    }

    // Hide both buttons after adding
    addToSoapBtn.style.display = 'none';
    clearTranscriptBtn.style.display = 'none';
    
    // Reset the transcript body text
    fullTranscript = ''; // Reset transcript
    transcriptBody.textContent = 'Press "Begin Recording" to start capturing the visit conversation. The transcript will populate here and can be used to auto-fill each SOAP section below.'; 
}

function clearTranscript() {
    fullTranscript = ''; // Reset the transcript
    transcriptBody.textContent = 'Press "Begin Recording" to start capturing the visit conversation. The transcript will populate here and can be used to auto-fill each SOAP section below.'; 
    
    // Show both buttons when clearing the transcript
    addToSoapBtn.style.display = 'inline-block'; // Show Add to SOAP Note button
    clearTranscriptBtn.style.display = 'inline-block'; // Show Clear button
}

// ── AI Fill ────────────────────────────────────────
const aiFill = {
    s: [
        `Patient is a 45-year-old female presenting with persistent headaches rated 6/10. Onset was 2 weeks ago after increased screen time at work.`,
        `A 30-year-old male complains of left knee pain after playing basketball. Pain rated 8/10 during movement.`,
        `Patient is a 60-year-old male with chronic low back pain, rated 7/10, worsening after prolonged sitting.`,
        `A 20-year-old female reports right shoulder pain after lifting weights, rated 5/10.`,
        `Patient is a 50-year-old male with right-sided sciatic pain, rated 9/10, radiating down the leg.`,
        `A 30-year-old female presents with left wrist pain after a fall, rated 4/10.`,
        `Patient is a 28-year-old male with acute onset of left ankle pain after a fall, rated 7/10.`,
        `A 55-year-old female reports persistent hip pain, rated 6/10, worsening with activity.`,
        `Patient is a 20-year-old female with fatigue and hair loss.`,
        `A 65-year-old female presents with shortness of breath and fatigue, rated 4/10.`,
        `Patient is a 35-year-old male with right wrist pain after playing basketball, rated 8/10.`,
        `A 52-year-old female presents with chronic sinus pressure and headaches.`,
        `Patient is a 40-year-old male with recurrent episodes of chest pain, rated 5/10.`,
        `A 30-year-old female reports abdominal pain, rated 7/10, associated with menstrual cycle.`,
        `Patient is a 60-year-old female with dizziness and balance issues, rated 3/10.`,
        `A 25-year-old female presents with fatigue and joint pain, rated 5/10.`,
        `A 70-year-old male with severe fatigue and unintentional weight loss.`,
        `A 52-year-old female presents with chronic low back pain, rated 6/10, affecting daily activities.`,
        `A 30-year-old male reports right elbow pain after a fall, rated 5/10.`,
        `A 40-year-old male with insomnia and anxiety, reporting difficulty sleeping for the past month.`,
        `A 30-year-old male with a history of asthma presents with wheezing and shortness of breath.`
    ],
    o: [
        `Posture: Elevated right iliac crest; lumbar lateral shift to right. Gait: Antalgic, right-favoring.`,
        `No neurological deficits noted. Tenderness palpated over the cervical muscles.`,
        `Limited range of motion noted with pain during flexion and extension.`,
        `Positive Hawkins-Kennedy test. Mild swelling present in the shoulder.`,
        `Straight leg raise test positive. Limited lumbar flexion.`,
        `Swelling and tenderness over the scaphoid. Positive Finkelstein's test.`,
        `Swelling and bruising noted. Positive anterior drawer test.`,
        `Limited hip flexion and internal rotation noted.`,
        `Normal cardiac exam. No signs of distress at rest.`,
        `Tenderness over the abdominal region. No masses noted.`,
        `Swelling and bruising observed in the wrist. ROM limited.`,
        `Lymphadenopathy present. Pallor noted on examination.`,
        `Positive anterior drawer test. Tenderness over the anterior knee.`,
        `Full range of motion with slight discomfort on palpation.`,
        `Two-plus bilateral DTRs observed. Sensation intact throughout.`,
        `Positive McMurray's test. Pain noted during internal and external rotation.`,
        `Normal findings on auscultation. No wheezing at rest.`,
        `Swelling noted in the ankle with tenderness along the lateral ligaments.`,
        `Normal gait observed. No deviations noted during ambulation.`,
        `Breath sounds clear bilaterally. SpO2 at 95% on room air.`,
        `Normal neurological exam. Positive Romberg test indicating balance issues.`
    ],
    a: [
        `Tension-type headache. Likely related to increased screen time and poor posture.`,
        `Possible meniscus tear.`,
        `Chronic lumbar strain.`,
        `Shoulder impingement syndrome.`,
        `Lumbar radiculopathy.`,
        `De Quervain's tenosynovitis.`,
        `Grade I ankle sprain.`,
        `Osteoarthritis of the hip.`,
        `Suspected exercise-induced angina.`,
        `Dysmenorrhea.`,
        `Possible malignancy.`,
        `Rheumatoid arthritis.`,
        `Vestibular dysfunction.`,
        `Iron deficiency anemia.`,
        `Plantar fasciitis.`,
        `Vertebral subluxation complex.`,
        `Chiropractic adjustment indicated.`,
        `Acute lumbar strain noted.`,
        `Chronic obstructive pulmonary disease (COPD) exacerbation.`,
        `Functional limitations observed.`
    ],
    p: [
        `Recommend ergonomic assessment and physical therapy.`,
        `Refer for MRI and recommend RICE protocol.`,
        `Physical therapy and a home exercise program focused on core strengthening.`,
        `Activity modification and physical therapy recommended.`,
        `Refer to orthopedic specialist for further evaluation.`,
        `Start DMARD therapy and refer to rheumatology.`,
        `Active rest and gradual rehabilitation program.`,
        `Pain management and referral to physical therapy.`,
        `Consider corticosteroid therapy and lifestyle modifications.`,
        `Immediate referral to oncology for further workup.`,
        `Consider splinting and rest.`,
        `Discussed disc mechanics and posture corrections.`,
        `Refer to ENT for further evaluation.`,
        `Order complete blood count (CBC) and iron studies.`,
        `Stretching exercises and orthotics recommended.`,
        `Chiropractic adjustment: Gonstead technique indicated.`,
        `Continue monitoring and follow-up in 2 weeks.`,
        `Patient education on exercise and rehabilitation.`,
        `Follow-up in 2 weeks for re-evaluation.`,
        `Consider lifestyle changes and medication management.`,
        `Reassess in 6 weeks for further treatment options.`
    ]
};

// Function to fill the textarea with AI-generated content
function fillAI(key, event) {
    const map = { s: 'sNote', o: 'oNote', a: 'aNote', p: 'pNote' };
    const ta = document.getElementById(map[key]);
    const btn = event.currentTarget;
    const orig = btn.textContent;
    
    btn.textContent = '✦ Filling…';
    btn.disabled = true;

    const randomIndex = Math.floor(Math.random() * aiFill[key].length); // Randomly select a note
    const selectedFill = aiFill[key][randomIndex]; // Get the selected note

    setTimeout(() => {
        ta.value += selectedFill + '\n\n'; // Append the selected note to the existing text
        ta.style.transition = 'background 0.5s';
        ta.style.background = 'rgba(61,107,94,0.06)';
        setTimeout(() => ta.style.background = '', 900);
        btn.textContent = orig; // Restore original button text
        btn.disabled = false; // Enable button again
    }, 700);
}


// ── Clear ──────────────────────────────────────────
function clearAll() {
    if (!confirm('Clear all note content?')) return;
    ['sNote', 'oNote', 'aNote', 'pNote'].forEach(id => document.getElementById(id).value = '');
    document.querySelectorAll('.ppip').forEach(p => p.classList.remove('sel-low', 'sel-mid', 'sel-high'));
    document.getElementById('painReadout').textContent = '';
    document.querySelectorAll('.chip.on-green,.chip.on-red,.chip.on-gold,.chip.on-blue').forEach(c => {
        c.classList.remove('on-green', 'on-red', 'on-gold', 'on-blue');
        c.textContent = c.textContent.replace(' (+)', '');
    });
    document.querySelectorAll('.ocb.g,.ocb.r').forEach(b => b.classList.remove('g', 'r'));
    // Ensure 'noteStatus' element exists or remove this line if unnecessary
    // document.getElementById('noteStatus').textContent = 'Draft — unsigned';
}

    </script>

</body>
</html>
