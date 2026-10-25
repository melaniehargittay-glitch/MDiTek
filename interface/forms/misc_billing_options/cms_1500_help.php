<?php
/*
 * CMS-1500 / HCFA Miscellaneous Billing Options — Help Reference
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
// File lives at: interface/forms/misc_billing_options/cms_1500_help.php
// globals.php lives at OpenEMR root (3 levels up)
require_once(__DIR__ . "/../../globals.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo xlt('CMS-1500 Billing Options Help'); ?></title>
    <style>
        /* ── Reset ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        /* ── Variables ── */
        :root {
            --navy:      #1a1d2e;
            --purple:    #5b21b6;
            --purple-l:  #ede9fe;
            --purple-m:  #7c3aed;
            --teal:      #0d9488;
            --teal-l:    #ccfbf1;
            --amber:     #b45309;
            --amber-l:   #fef3c7;
            --red:       #be123c;
            --red-l:     #fce7f3;
            --blue:      #1d4ed8;
            --blue-l:    #dbeafe;
            --green:     #065f46;
            --green-l:   #d1fae5;
            --surface:   #f8f8fc;
            --border:    #e5e7ef;
            --text:      #1a1d2e;
            --text2:     #4b5563;
            --text3:     #9197b3;
            --r:         8px;
        }

        /* ── Base ── */
        html { scroll-behavior: smooth; }
        body {
            background: #fff;
            font-family: 'Georgia', 'Times New Roman', serif;
            font-size: 13px;
            color: var(--text);
            line-height: 1.6;
        }

        /* ── Layout: sidebar + content ── */
        .page-wrap {
            display: flex;
            min-height: 100vh;
        }

        /* ── Sidebar ── */
        .sidebar {
            width: 190px;
            flex-shrink: 0;
            background: var(--navy);
            padding: 20px 0 20px;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }
        .sidebar-logo {
            padding: 0 16px 16px;
            border-bottom: 1px solid rgba(255,255,255,.1);
            margin-bottom: 16px;
        }
        .sidebar-logo-badge {
            display: inline-block;
            background: var(--purple);
            color: #fff;
            font-family: 'DM Mono', 'Courier New', monospace;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .08em;
            padding: 3px 8px;
            border-radius: 4px;
            margin-bottom: 6px;
        }
        .sidebar-logo-title {
            font-family: 'Georgia', serif;
            font-size: 12px;
            font-weight: 700;
            color: #fff;
            line-height: 1.3;
        }
        .sidebar-logo-sub {
            font-size: 10px;
            color: rgba(255,255,255,.4);
            margin-top: 2px;
        }
        .sidebar-section-lbl {
            font-family: 'DM Mono', 'Courier New', monospace;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: rgba(255,255,255,.3);
            padding: 0 16px 6px;
            margin-top: 8px;
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 16px;
            text-decoration: none;
            font-family: 'DM Mono', 'Courier New', monospace;
            font-size: 11px;
            color: rgba(255,255,255,.6);
            transition: all .15s;
            border-left: 2px solid transparent;
        }
        .sidebar-link:hover {
            background: rgba(255,255,255,.06);
            color: #fff;
            border-left-color: var(--purple-m);
        }
        .sidebar-link .box-num {
            display: inline-block;
            min-width: 26px;
            background: rgba(255,255,255,.1);
            border-radius: 3px;
            text-align: center;
            font-size: 9px;
            font-weight: 700;
            padding: 1px 4px;
            flex-shrink: 0;
        }

        /* ── Main content ── */
        .content {
            flex: 1;
            padding: 28px 32px 48px;
            max-width: 560px;
        }

        /* ── Page header ── */
        .page-header {
            border-bottom: 2px solid var(--purple-l);
            padding-bottom: 16px;
            margin-bottom: 24px;
        }
        .page-header-eyebrow {
            font-family: 'DM Mono', 'Courier New', monospace;
            font-size: 10px;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--purple);
            font-weight: 700;
            margin-bottom: 4px;
        }
        .page-header h1 {
            font-size: 18px;
            font-weight: 700;
            color: var(--navy);
            line-height: 1.2;
        }
        .page-header-sub {
            font-size: 12px;
            color: var(--text3);
            margin-top: 4px;
            font-family: 'DM Mono', 'Courier New', monospace;
        }

        /* ── Help entry cards ── */
        .help-entry {
            margin-bottom: 20px;
            border: 1px solid var(--border);
            border-radius: var(--r);
            overflow: hidden;
            scroll-margin-top: 20px;
        }
        .help-entry-head {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .box-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Mono', 'Courier New', monospace;
            font-size: 9.5px;
            font-weight: 800;
            letter-spacing: .04em;
            padding: 2px 8px;
            border-radius: 4px;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .badge-purple { background: var(--purple-l); color: var(--purple); }
        .badge-teal   { background: var(--teal-l);   color: var(--teal); }
        .badge-amber  { background: var(--amber-l);  color: var(--amber); }
        .badge-blue   { background: var(--blue-l);   color: var(--blue); }
        .badge-red    { background: var(--red-l);    color: var(--red); }
        .badge-green  { background: var(--green-l);  color: var(--green); }

        .help-entry-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--navy);
        }
        .help-entry-body {
            padding: 12px 14px;
            font-size: 12.5px;
            line-height: 1.65;
            color: var(--text2);
        }
        .help-entry-body p { margin-bottom: 8px; }
        .help-entry-body p:last-child { margin-bottom: 0; }
        .help-entry-body ul {
            padding-left: 16px;
            margin-bottom: 8px;
        }
        .help-entry-body li { margin-bottom: 3px; }
        .help-entry-body strong { color: var(--text); font-weight: 700; }
        .help-entry-body code {
            font-family: 'DM Mono', 'Courier New', monospace;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 3px;
            padding: 1px 5px;
            font-size: 11px;
            color: var(--purple);
        }

        /* ── Note/tip callout ── */
        .help-tip {
            display: flex;
            gap: 8px;
            background: var(--purple-l);
            border-left: 3px solid var(--purple);
            border-radius: 0 5px 5px 0;
            padding: 8px 10px;
            margin-top: 8px;
            font-size: 11.5px;
            color: var(--purple);
        }
        .help-tip-icon { flex-shrink: 0; font-size: 13px; }

        .help-warn {
            display: flex;
            gap: 8px;
            background: var(--amber-l);
            border-left: 3px solid var(--amber);
            border-radius: 0 5px 5px 0;
            padding: 8px 10px;
            margin-top: 8px;
            font-size: 11.5px;
            color: var(--amber);
        }

        /* ── Section divider ── */
        .section-divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 24px 0 16px;
        }
        .section-divider-line {
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        .section-divider-label {
            font-family: 'DM Mono', 'Courier New', monospace;
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--text3);
            white-space: nowrap;
        }

        /* ── Quick-reference table ── */
        .ref-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            margin-top: 8px;
        }
        .ref-table th {
            text-align: left;
            font-family: 'DM Mono', 'Courier New', monospace;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: var(--text3);
            font-weight: 700;
            padding: 5px 8px;
            border-bottom: 2px solid var(--border);
        }
        .ref-table td {
            padding: 6px 8px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
            color: var(--text2);
        }
        .ref-table tr:last-child td { border-bottom: none; }
        .ref-table tr:hover td { background: var(--surface); }
        .ref-table td:first-child {
            font-family: 'DM Mono', 'Courier New', monospace;
            color: var(--purple);
            font-weight: 700;
            white-space: nowrap;
        }

        /* ── Close button ── */
        .close-bar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(255,255,255,.95);
            backdrop-filter: blur(6px);
            border-bottom: 1px solid var(--border);
            padding: 8px 32px;
            display: flex;
            justify-content: flex-end;
        }
        .close-btn {
            height: 28px;
            padding: 0 12px;
            border-radius: 5px;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text2);
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all .15s;
        }
        .close-btn:hover { background: var(--navy); color: #fff; border-color: var(--navy); }
    </style>
</head>
<body>

<div class="page-wrap">

    <!-- ── Sidebar nav ── -->
    <nav class="sidebar">
        <div class="sidebar-logo">
            <div class="sidebar-logo-badge">CMS-1500</div>
            <div class="sidebar-logo-title">HCFA Billing<br>Options Help</div>
            <div class="sidebar-logo-sub">OpenEMR Reference</div>
        </div>

        <div class="sidebar-section-lbl">Box 10 — Condition</div>
        <a href="#box10a" class="sidebar-link"><span class="box-num">10A</span> Employment</a>
        <a href="#box10b" class="sidebar-link"><span class="box-num">10B</span> Auto Accident</a>
        <a href="#box10c" class="sidebar-link"><span class="box-num">10C</span> Other Accident</a>
        <a href="#box10d" class="sidebar-link"><span class="box-num">10D</span> EPSDT</a>

        <div class="sidebar-section-lbl">Dates</div>
        <a href="#box14" class="sidebar-link"><span class="box-num">14</span> Onset Date</a>
        <a href="#box15" class="sidebar-link"><span class="box-num">15</span> Other Date</a>
        <a href="#box16" class="sidebar-link"><span class="box-num">16</span> Unable to Work</a>
        <a href="#box18" class="sidebar-link"><span class="box-num">18</span> Hospitalization</a>

        <div class="sidebar-section-lbl">Provider</div>
        <a href="#box17" class="sidebar-link"><span class="box-num">17</span> Provider</a>

        <div class="sidebar-section-lbl">Lab &amp; Billing</div>
        <a href="#box20" class="sidebar-link"><span class="box-num">20</span> Outside Lab</a>
        <a href="#box22" class="sidebar-link"><span class="box-num">22</span> Medicaid</a>
        <a href="#box23" class="sidebar-link"><span class="box-num">23</span> Prior Auth</a>
        <a href="#claim-type" class="sidebar-link"><span class="box-num">CLM</span> Claim Type</a>

        <div class="sidebar-section-lbl">Notes</div>
        <a href="#notes" class="sidebar-link"><span class="box-num">NOTE</span> Add. Notes</a>
    </nav>

    <!-- ── Main content ── -->
    <main class="content">

        <!-- Close bar -->
        <div class="close-bar">
            <button class="close-btn" onclick="window.close()">
                <svg width="11" height="11" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Close
            </button>
        </div>

        <!-- Page header -->
        <div class="page-header">
            <div class="page-header-eyebrow">Reference Guide</div>
            <h1>CMS-1500 Miscellaneous Billing Options</h1>
            <div class="page-header-sub">HCFA-1500 Claim Form — Boxes 10 through 23 + Additional Notes</div>
        </div>

        <!-- ══════════════════════════════════════════════
             BOX 10 — CONDITION RELATED TO
        ══════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-line"></div>
            <div class="section-divider-label">Box 10 — Condition Related To</div>
            <div class="section-divider-line"></div>
        </div>

        <div class="help-entry" id="box10a">
            <div class="help-entry-head">
                <span class="box-badge badge-purple">Box 10A</span>
                <span class="help-entry-title">Employment Related</span>
            </div>
            <div class="help-entry-body">
                <p>Check this box if the patient's condition is related to their current or previous <strong>employment or occupation</strong>. This includes workplace injuries, occupational illnesses, or conditions that arose as a direct result of job duties.</p>
                <p>When checked, the claim may be submitted to Workers' Compensation instead of (or in addition to) standard health insurance. Confirm coverage with the payer before submission.</p>
                <div class="help-tip">
                    <span class="help-tip-icon">ℹ</span>
                    <span>If this box is checked, ensure the employer's name and address are included elsewhere in the encounter documentation.</span>
                </div>
            </div>
        </div>

        <div class="help-entry" id="box10b">
            <div class="help-entry-head">
                <span class="box-badge badge-purple">Box 10B</span>
                <span class="help-entry-title">Auto Accident + State</span>
            </div>
            <div class="help-entry-body">
                <p>Check this box if the patient's condition resulted from a <strong>motor vehicle accident</strong>. The <strong>State</strong> field should contain the two-letter state abbreviation for the state where the accident occurred.</p>
                <p>Auto accident claims are often submitted to the patient's auto insurance carrier (PIP — Personal Injury Protection) as the primary payer. The state is required by many payers to process coordination of benefits correctly.</p>
                <div class="help-warn">
                    <span class="help-tip-icon">⚠</span>
                    <span>Leaving the State field blank when Auto Accident is checked may cause claim rejection by some payers.</span>
                </div>
            </div>
        </div>

        <div class="help-entry" id="box10c">
            <div class="help-entry-head">
                <span class="box-badge badge-purple">Box 10C</span>
                <span class="help-entry-title">Other Accident</span>
            </div>
            <div class="help-entry-body">
                <p>Check this box if the patient's condition resulted from <strong>any accident other than an auto accident or work-related injury</strong> — for example, a slip-and-fall on someone else's property, a sports injury, or an accident that may involve third-party liability.</p>
                <p>When this box is checked, third-party liability insurance or a homeowner's/renter's insurance policy may be the primary payer. Document the circumstances of the accident in the patient's chart.</p>
            </div>
        </div>

        <div class="help-entry" id="box10d">
            <div class="help-entry-head">
                <span class="box-badge badge-purple">Box 10D</span>
                <span class="help-entry-title">EPSDT Referral Code &amp; Flag</span>
            </div>
            <div class="help-entry-body">
                <p><strong>EPSDT</strong> (Early and Periodic Screening, Diagnostic, and Treatment) is a Medicaid program for individuals under age 21. It covers comprehensive and preventive health services.</p>
                <p>The <strong>EPSDT Referral Code</strong> field accepts a one- or two-character code indicating the type of referral:</p>
                <table class="ref-table">
                    <thead><tr><th>Code</th><th>Meaning</th></tr></thead>
                    <tbody>
                        <tr><td>AV</td><td>Available — not used (patient declined referral)</td></tr>
                        <tr><td>NU</td><td>Not used (no referral given)</td></tr>
                        <tr><td>S2</td><td>Under treatment (patient is currently being treated)</td></tr>
                        <tr><td>ST</td><td>New service requested (referral given)</td></tr>
                    </tbody>
                </table>
                <p>The <strong>EPSDT Flag</strong> checkbox indicates that this service is being billed under the EPSDT benefit. Check it for Medicaid claims involving preventive or screening services for pediatric patients.</p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════
             DATES
        ══════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-line"></div>
            <div class="section-divider-label">Dates</div>
            <div class="section-divider-line"></div>
        </div>

        <div class="help-entry" id="box14">
            <div class="help-entry-head">
                <span class="box-badge badge-teal">Box 14</span>
                <span class="help-entry-title">Date of Current Illness / Injury / Pregnancy (Onset Date)</span>
            </div>
            <div class="help-entry-body">
                <p>Enter the date of the <strong>first symptom, injury, or onset</strong> of the current illness. For pregnancies, enter the date of the last menstrual period (LMP). For injuries, enter the date of the accident.</p>
                <p>The <strong>Qualifier</strong> dropdown tells the payer how to interpret this date:</p>
                <table class="ref-table">
                    <thead><tr><th>Qualifier</th><th>Meaning</th></tr></thead>
                    <tbody>
                        <tr><td>431</td><td>Onset of Current Symptoms or Illness</td></tr>
                        <tr><td>484</td><td>Last Menstrual Period</td></tr>
                        <tr><td>453</td><td>Acute Manifestation of a Chronic Condition</td></tr>
                        <tr><td>439</td><td>Accident</td></tr>
                    </tbody>
                </table>
                <p>Format: <code>YYYY-MM-DD</code>. This date populates automatically from the patient's injury date in the encounter if the MBO form is new.</p>
                <div class="help-tip">
                    <span class="help-tip-icon">ℹ</span>
                    <span>Many payers — especially for chiropractic, physical therapy, and orthopedic claims — require this field. Missing or incorrect onset dates are a leading cause of claim denials.</span>
                </div>
            </div>
        </div>

        <div class="help-entry" id="box15">
            <div class="help-entry-head">
                <span class="box-badge badge-teal">Box 15</span>
                <span class="help-entry-title">Other Date</span>
            </div>
            <div class="help-entry-body">
                <p>Enter another clinically significant date related to the encounter. The meaning is defined by the <strong>Qualifier</strong> dropdown. Common uses:</p>
                <table class="ref-table">
                    <thead><tr><th>Qualifier</th><th>Meaning</th></tr></thead>
                    <tbody>
                        <tr><td>454</td><td>Initial Treatment Date — the first date the patient was seen for this condition</td></tr>
                        <tr><td>304</td><td>Latest Visit or Consultation</td></tr>
                        <tr><td>455</td><td>Last X-Ray Date</td></tr>
                        <tr><td>471</td><td>Prescription Date</td></tr>
                        <tr><td>090</td><td>Report Start (Assumed Care Date)</td></tr>
                        <tr><td>091</td><td>Report End (Relinquished Care Date)</td></tr>
                    </tbody>
                </table>
                <p>This field is pre-populated from the encounter's <em>initial treatment date</em> when creating a new MBO form.</p>
            </div>
        </div>

        <div class="help-entry" id="box16">
            <div class="help-entry-head">
                <span class="box-badge badge-teal">Box 16</span>
                <span class="help-entry-title">Dates Patient Unable to Work</span>
            </div>
            <div class="help-entry-body">
                <p>Enter the date range during which the patient was <strong>unable to work in their current occupation</strong> due to this condition. Both <strong>From</strong> and <strong>To</strong> dates are required if applicable.</p>
                <p>This field is primarily used for:</p>
                <ul>
                    <li>Workers' compensation claims</li>
                    <li>Disability determinations</li>
                    <li>Auto accident claims requiring lost wages documentation</li>
                </ul>
                <p>Leave blank if the patient was not unable to work or if it is not applicable to the claim.</p>
                <div class="help-tip">
                    <span class="help-tip-icon">ℹ</span>
                    <span>For ongoing disability, enter the service date as the "To" date and update it with each subsequent encounter.</span>
                </div>
            </div>
        </div>

        <div class="help-entry" id="box18">
            <div class="help-entry-head">
                <span class="box-badge badge-teal">Box 18</span>
                <span class="help-entry-title">Hospitalization Dates Related to Current Services</span>
            </div>
            <div class="help-entry-body">
                <p>Enter the <strong>From</strong> and <strong>To</strong> dates of a hospital stay that is related to the services being billed. This is required when outpatient services are provided in connection with an inpatient admission.</p>
                <p>Common scenarios requiring Box 18:</p>
                <ul>
                    <li>Pre-operative or post-operative outpatient services tied to a surgical admission</li>
                    <li>Ancillary services (lab, radiology) billed separately from the hospital stay</li>
                    <li>Consulting physician services during an inpatient stay</li>
                </ul>
                <p>Leave both fields blank if the services being billed are not related to an inpatient admission.</p>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════
             PROVIDER
        ══════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-line"></div>
            <div class="section-divider-label">Provider</div>
            <div class="section-divider-line"></div>
        </div>

        <div class="help-entry" id="box17">
            <div class="help-entry-head">
                <span class="box-badge badge-blue">Box 17</span>
                <span class="help-entry-title">Referring Provider Name &amp; Qualifier</span>
            </div>
            <div class="help-entry-body">
                <p>Select the <strong>referring, ordering, or supervising provider</strong> who directed the patient to the treating provider for this service.</p>
                <p>The <strong>Provider Qualifier</strong> specifies the relationship of the listed provider to the claim:</p>
                <table class="ref-table">
                    <thead><tr><th>Qualifier</th><th>Meaning</th></tr></thead>
                    <tbody>
                        <tr><td>DN</td><td>Referring Provider — ordered or directed the service</td></tr>
                        <tr><td>DQ</td><td>Supervising Provider — oversees the treating provider</td></tr>
                        <tr><td>DK</td><td>Ordering Provider — ordered tests, supplies, or equipment</td></tr>
                    </tbody>
                </table>
                <p>The provider's <strong>NPI</strong> (National Provider Identifier) will be pulled from their OpenEMR profile and transmitted on the claim. Ensure the NPI is entered correctly in the provider setup.</p>
                <div class="help-warn">
                    <span class="help-tip-icon">⚠</span>
                    <span>Many payers require a valid referring provider NPI for specialist visits. A missing or invalid NPI is a common reason for claim rejection.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════
             LAB & BILLING
        ══════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-line"></div>
            <div class="section-divider-label">Lab &amp; Billing</div>
            <div class="section-divider-line"></div>
        </div>

        <div class="help-entry" id="box20">
            <div class="help-entry-head">
                <span class="box-badge badge-amber">Box 20</span>
                <span class="help-entry-title">Outside Lab &amp; Amount Charged</span>
            </div>
            <div class="help-entry-body">
                <p>Check <strong>Outside Lab Used</strong> if laboratory services were performed by an <strong>outside laboratory</strong> (i.e., not in the billing provider's office). Enter the amount charged by the outside lab in the <strong>Amount Charged</strong> field.</p>
                <p>When this box is checked, the claim indicates that the billing provider purchased the lab service from an outside entity and is billing for it as a pass-through. This is sometimes called "purchased lab" billing.</p>
                <div class="help-tip">
                    <span class="help-tip-icon">ℹ</span>
                    <span>Medicare has strict rules about purchased lab services. Most labs should bill Medicare directly under their own NPI. Consult your billing specialist if unsure.</span>
                </div>
            </div>
        </div>

        <div class="help-entry" id="box22">
            <div class="help-entry-head">
                <span class="box-badge badge-amber">Box 22</span>
                <span class="help-entry-title">Medicaid Resubmission Code &amp; Original Reference No.</span>
            </div>
            <div class="help-entry-body">
                <p>Used when <strong>resubmitting a Medicaid claim</strong> that was previously denied, adjusted, or voided. This box is only completed on corrected or replacement claims to Medicaid payers.</p>
                <p>The <strong>Medicaid Resubmission Code (ICD-10)</strong> field accepts a code indicating the reason for resubmission. The <strong>Medicaid Original Reference No.</strong> is the claim number assigned by Medicaid to the original claim being corrected.</p>
                <div class="help-warn">
                    <span class="help-tip-icon">⚠</span>
                    <span>Medicaid resubmission requirements vary by state. Consult your state Medicaid provider manual for specific codes and procedures.</span>
                </div>
            </div>
        </div>

        <div class="help-entry" id="box23">
            <div class="help-entry-head">
                <span class="box-badge badge-amber">Box 23</span>
                <span class="help-entry-title">Prior Authorization Number</span>
            </div>
            <div class="help-entry-body">
                <p>Enter the <strong>prior authorization, pre-certification, or referral number</strong> assigned by the payer for this service. This number is provided by the insurance company before the service is rendered when prior authorization is required.</p>
                <p>Situations requiring a prior authorization number include:</p>
                <ul>
                    <li>Specialist referrals from HMO/gatekeeper plans</li>
                    <li>Elective surgeries and procedures</li>
                    <li>Durable medical equipment (DME)</li>
                    <li>Certain imaging studies (MRI, CT, PET)</li>
                    <li>Inpatient admissions</li>
                </ul>
                <div class="help-warn">
                    <span class="help-tip-icon">⚠</span>
                    <span>Submitting a claim for a service that required prior authorization without including the authorization number will result in denial. Always verify authorization requirements before the service date.</span>
                </div>
            </div>
        </div>

        <div class="help-entry" id="claim-type">
            <div class="help-entry-head">
                <span class="box-badge badge-red">Claim Type</span>
                <span class="help-entry-title">X12 Claim Type: New / Replacement / Void</span>
            </div>
            <div class="help-entry-body">
                <p>This field controls the <strong>CLM05-3 claim frequency code</strong> in the X12 837 transaction and tells the payer how to process the claim relative to a previously submitted claim for the same encounter.</p>
                <table class="ref-table">
                    <thead><tr><th>Selection</th><th>Frequency Code</th><th>Use When</th></tr></thead>
                    <tbody>
                        <tr>
                            <td>New Claim</td>
                            <td><code>1</code></td>
                            <td>First submission for this encounter. Default for all new claims.</td>
                        </tr>
                        <tr>
                            <td>X12 Replacement Claim</td>
                            <td><code>7</code></td>
                            <td>Correcting and replacing a previously submitted claim. The original claim is replaced in full.</td>
                        </tr>
                        <tr>
                            <td>Void Claim</td>
                            <td><code>8</code></td>
                            <td>Cancelling a previously submitted claim entirely. No payment should be made.</td>
                        </tr>
                    </tbody>
                </table>
                <p>The <strong>X12 ICN Resubmission No.</strong> field must be filled in when submitting a Replacement or Void claim — enter the <strong>Internal Control Number (ICN)</strong> or claim number assigned by the payer to the original claim.</p>
                <div class="help-warn">
                    <span class="help-tip-icon">⚠</span>
                    <span>Using Replacement or Void without the correct ICN will cause the payer to be unable to locate the original claim. Always obtain the ICN from the payer's remittance advice or provider portal before resubmitting.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════
             ADDITIONAL NOTES
        ══════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-line"></div>
            <div class="section-divider-label">Additional Notes</div>
            <div class="section-divider-line"></div>
        </div>

        <div class="help-entry" id="notes">
            <div class="help-entry-head">
                <span class="box-badge badge-green">Notes</span>
                <span class="help-entry-title">Additional Notes / Billing Note</span>
            </div>
            <div class="help-entry-body">
                <p>This free-text field stores <strong>internal billing notes</strong> for this encounter. Notes entered here are saved to the encounter record and are visible to billing staff when reviewing claims.</p>
                <p>Common uses include:</p>
                <ul>
                    <li>Noting special payer instructions or exceptions</li>
                    <li>Documenting verbal pre-authorizations received by phone</li>
                    <li>Recording coordination of benefits details</li>
                    <li>Flagging claims that need follow-up</li>
                    <li>Documenting why a specific modifier or code was used</li>
                </ul>
                <div class="help-tip">
                    <span class="help-tip-icon">ℹ</span>
                    <span>Notes in this field are for internal use only and are <strong>not</strong> transmitted on the electronic claim to the payer.</span>
                </div>
            </div>
        </div>

        <!-- ══════════════════════════════════════════════
             GENERAL TIPS
        ══════════════════════════════════════════════ -->
        <div class="section-divider">
            <div class="section-divider-line"></div>
            <div class="section-divider-label">General Guidance</div>
            <div class="section-divider-line"></div>
        </div>

        <div class="help-entry">
            <div class="help-entry-head">
                <span class="box-badge badge-green">Tips</span>
                <span class="help-entry-title">Best Practices for Accurate Claim Submission</span>
            </div>
            <div class="help-entry-body">
                <ul>
                    <li><strong>Always verify payer requirements</strong> before setting these fields — requirements vary significantly between Medicare, Medicaid, and commercial payers.</li>
                    <li><strong>Date formats</strong> should be entered as <code>YYYY-MM-DD</code>. The system will convert these to the format required by the payer on submission.</li>
                    <li><strong>Onset dates</strong> (Box 14) are critical for chiropractic, physical therapy, and injury-related claims. Incorrect dates are a leading cause of denials.</li>
                    <li><strong>Prior authorization numbers</strong> (Box 23) must match exactly what was provided by the payer. Even a single character difference may cause denial.</li>
                    <li><strong>Replacement and void claims</strong> require the original claim's ICN — obtain this from the payer's EOB or provider portal before resubmitting.</li>
                    <li>When in doubt, consult your <strong>payer-specific billing guidelines</strong> or contact the payer's provider services line.</li>
                </ul>
            </div>
        </div>

    </main>
</div>

<script>
// Highlight active section on scroll
(function() {
    var links = document.querySelectorAll('.sidebar-link');
    var entries = document.querySelectorAll('.help-entry[id]');

    function onScroll() {
        var scrollY = window.scrollY + 60;
        var current = '';
        entries.forEach(function(el) {
            if (el.offsetTop <= scrollY) current = el.id;
        });
        links.forEach(function(a) {
            var href = a.getAttribute('href').replace('#','');
            a.style.borderLeftColor = (href === current) ? 'var(--purple-m)' : 'transparent';
            a.style.color = (href === current) ? '#fff' : '';
            a.style.background = (href === current) ? 'rgba(255,255,255,.08)' : '';
        });
    }

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
})();
</script>

</body>
</html>
