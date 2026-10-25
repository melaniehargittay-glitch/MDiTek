<?php
/* Smarty version 4.3.1, created on 2026-07-06 08:13:56
  from '/var/www/html/interface/forms/soap/templates/general_new.html' */

/* @var Smarty_Internal_Template $_smarty_tpl */
if ($_smarty_tpl->_decodeProperties($_smarty_tpl, array (
  'version' => '4.3.1',
  'unifunc' => 'content_6a4b9c04bb6b56_78493764',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    'ba09be06e15a21ff9a1379698f749a0adb22fee9' => 
    array (
      0 => '/var/www/html/interface/forms/soap/templates/general_new.html',
      1 => 1782911486,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
),false)) {
function content_6a4b9c04bb6b56_78493764 (Smarty_Internal_Template $_smarty_tpl) {
$_smarty_tpl->_checkPlugins(array(0=>array('file'=>'/var/www/html/library/smarty/plugins/function.xlt.php','function'=>'smarty_function_xlt',),1=>array('file'=>'/var/www/html/library/smarty/plugins/function.headerTemplate.php','function'=>'smarty_function_headerTemplate',),2=>array('file'=>'/var/www/html/library/smarty/plugins/function.xla.php','function'=>'smarty_function_xla',),));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo smarty_function_xlt(array('t'=>'Clinical Notes'),$_smarty_tpl);?>
</title>
    <!-- Place the first <?php echo '<script'; ?>
> tag in your HTML's <head> -->
    <?php echo smarty_function_headerTemplate(array(),$_smarty_tpl);?>

    <style>
        :root {
            --bg: #ffffff;
            --bg2: #ffffff;
            --surface: #ffffff;
            --border: #e8e6f0;
            --border2: #d4cee8;
            --text: #1a1a2e;
            --muted: #7a7367;
            --muted2: #a09890;
            --accent: #47377a;
            --accent2: #b69dd6;
            --accent-bg: rgba(61,107,94,0.07);
            --gold: #9a6f3a;
            --gold-bg: rgba(154,111,58,0.08);
            --red: #c0392b;
            --red-bg: rgba(192,57,43,0.08);
            --blue: #3a5a7a;
            --blue-bg: rgba(58,90,122,0.08);
            --radius: 8px;
        }

        /* Rows inside sections */
        body{
            background: #f3f0f9 !important;
        }
        .row-strip {
            display: grid;
            border-bottom: 1px solid var(--border);
            background: #fff;
        }

        .row-strip.c2 { grid-template-columns: 1fr 1fr; }
        .row-strip.c3 { grid-template-columns: 1fr 1fr 1fr; }
        .row-strip.c4 { grid-template-columns: repeat(4, 1fr); }
        .row-strip.c5 { grid-template-columns: repeat(5, 1fr); }

        .cell {
            padding: 0.65rem 0.9rem;
            border-right: 1px solid var(--border);
            background: #fff;
        }

        .cell:last-child { border-right: none; }

        .cell label {
            font-size: 0.68rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            display: block;
            margin-bottom: 5px;
            color: #888;
            font-weight: 700;
        }

        .goal-chip-wrap{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:8px;
}

.goal-chip{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:8px 14px;
    border-radius:999px;
    border:1px solid #d8d5e6;
    background:#fff;
    color:#4b5563;
    font-size:12px;
    font-weight:600;
    cursor:pointer;
    transition:all .15s ease;
    user-select:none;
}

.goal-chip:hover{
    border-color:var(--purple);
    color:var(--purple);
}

.goal-chip.active{
    background:var(--purple);
    border-color:var(--purple);
    color:#fff;
}

        .cell input,
        .cell select {
            font-size: 0.82rem;
            background: #fff;
            color: var(--text);
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            transition: border-color 0.15s, box-shadow 0.15s;
        }

        .cell input:hover,
        .cell select:hover {
            border-color: #b0b0c8;
        }

        .cell input:focus,
        .cell select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124,58,237,0.10);
        }

        .cell input::placeholder { color: var(--muted2); }

        .cell select {
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23999' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 32px;
        }

        .cell select option { background: #fff; color: var(--text); }

        /* Quick-tag rows */
.qtags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 0.6rem 0.9rem 0.8rem;
  border-top: 1px solid var(--border);
  background: #faf9ff;
}

.qtag {
  font-size: 0.72rem;
  letter-spacing: 0.03em;
  padding: 4px 11px;
  border: 1px solid var(--border2);
  border-radius: 20px;
  background: #fff;
  color: #555;
  cursor: pointer;
  user-select: none;
  transition: all 0.12s;
}

.qtag:hover {
  border-color: #7c3aed;
  color: #7c3aed;
  background: #f5f0ff;
}

.qtag.selected {
  border-color: #7c3aed;
  color: #fff;
  background: #7c3aed;
}

/* ── Accident Form — button-grid pickers (mirrors legacy EMR pink-pill UI) ── */
.aa-field-row {
  padding: 0.6rem 0.9rem;
  border-bottom: 1px solid #eeeaf8;
  background: #fff;
}
.aa-field-label {
  font-size: 0.74rem;
  font-weight: 600;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: #6b6480;
  margin-bottom: 8px;
}
.aa-btn-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.aa-opt-btn {
  font-size: 0.8rem;
  padding: 8px 14px;
  border: 1px solid #f3c9bf;
  border-radius: 8px;
  background: #fbe4dc;
  color: #5a3a30;
  cursor: pointer;
  user-select: none;
  transition: all 0.12s;
  font-family: inherit;
}
.aa-opt-btn:hover {
  border-color: #e0937e;
  background: #f7d3c6;
}
.aa-opt-btn.selected {
  background: #7c3aed;
  border-color: #7c3aed;
  color: #fff;
}

/* ── Re-Exam — Comparison Columns (Initial vs. Current) ── */
.rx-compare-table {
  display: flex;
  flex-direction: column;
  padding: 0.4rem 0.9rem 0.9rem;
}
.rx-compare-row {
  display: grid;
  grid-template-columns: 1.4fr 1fr 1fr;
  gap: 10px;
  align-items: center;
  padding: 6px 0;
  border-bottom: 1px solid #f1eef9;
}
.rx-compare-row:last-child { border-bottom: none; }
.rx-compare-head {
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #9994b0;
  border-bottom: 1.5px solid #e2dff0;
  padding-bottom: 8px;
}
.rx-compare-label {
  font-size: 0.82rem;
  font-weight: 600;
  color: #2d2542;
}
.rx-compare-row input {
  width: 100%;
  padding: 6px 9px;
  border: 1px solid #e2dff0;
  border-radius: 7px;
  font-size: 0.82rem;
  font-family: inherit;
  color: #1a1a2e;
  background: #fff;
}
.rx-compare-row input:focus {
  outline: none;
  border-color: #7c3aed;
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
  font-size: 0.75rem;
  padding: 5px 12px;
  border: 1px solid var(--border2);
  border-radius: 20px;
  background: #fff;
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

.soap-section {
    background: #fff;
    border-radius: 12px;
    border: 1px solid var(--border);
    overflow: hidden;
    margin-bottom: 16px;
    box-shadow: 0 1px 4px rgba(71,55,122,0.05);
}

.soap-wrapper {
    display: flex;
    flex-direction: column;
    gap: 0;
}

.section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.85rem 1.1rem;
  background: #fff;
  border-bottom: 1px solid var(--border);
  border-top: 3px solid transparent;
}

button {
    padding: 0.5rem 1.1rem;
    border: none;
    border-radius: 7px;
}



.sh-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.soapdrp{
    font-size:12px;
    padding: 7px 28px 7px 10px;
    border: 1px solid var(--border2);
    border-radius: 7px;
    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23999' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") no-repeat right 10px center;
    appearance: none;
    color: var(--text);
    cursor: pointer;
}

.soap-glyph {
  font-style: normal;
  font-size: 1rem;
  font-weight: 800;
  line-height: 1;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  /* Color set per-section via data attributes / specific selectors below */
  background: #eeedf8;
  color: #5b21b6;
}

.soap-info strong {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--text);
  letter-spacing: 0.02em;
  display: block;
}

.soap-info span {
  font-size: 0.65rem;
  color: #aaa;
  letter-spacing: 0.10em;
  text-transform: uppercase;
}

/* Record button */
.rec-btn {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 0.5rem 1.1rem;
  background:rgba(255,255,255,.15);
  color: #FFF;
  border: none;
  border-radius: 7px;
  font-size: 0.75rem;
  font-weight: bold;
  cursor: pointer;
  transition: background 0.2s, color 0.2s;
}

button#btnClose{
    border-radius: 7px !important;
    margin-left: 10px;
}

.cell select,input,textarea {
    border-radius: 7px;
}

textarea.form-control {
    width:100% !important;
}


.rec-btn.recording {
  background: var(--red);
  animation: pulse-rec 1.6s ease-in-out infinite;
  color: #FFF;
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
  overflow: visible;
  transition: border-color 0.3s;
  animation: fadeUp 0.4s ease 0.1s both;
  border-radius:12px;
  box-shadow:0 2px 12px rgba(71,55,122,.07);
  margin-bottom:30px;
}

.transcript.live-rec {
  border-color: var(--red);
}

.transcript-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 18px;
  background: linear-gradient(135deg, #5b21b6, #7c3aed);
  color:#FFF;
  border-radius: 12px 12px 0 0;
  position: relative;
  overflow: visible;
}

.transcript-body {
    padding: 30px;
    background: #ffffff;
}

.transcript-label {
  font-size: 0.8rem;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  color: #FFF;
  font-weight: bold;
}

/* Billing Chip */

.billing-box {
    margin-top: 10px;
    padding: 10px 12px;
    border-top: 1px solid var(--border);
    background: #faf8ff;
}

.billing-label {
    font-size: 11px;
    font-weight: bold;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #666;
    margin-bottom: 6px;
}

.billing-chip {
    background: #eef6ff;
    border: 1px solid #7aa7d9;
}

.billing-chip.accepted {
    background: #eafbf0;
    border: 1px solid #48a868;
}

.billing-chip.warn {
    background: #fff4e5;
    border: 1px solid #d8a04b;
}

.billing-chip.remove {
    background: #fff1f1;
    border: 1px solid #d97a7a;
}

.billing-audit {
    margin-top: 8px;
    font-size: 12px;
    color: #555;
    line-height: 1.5;
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

button.ai-btn {
    display: none;
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
    background: linear-gradient(135deg,#5b21b6,#7c3aed) !important;
    box-shadow: 0 4px 14px rgba(124,58,237,.35) !important;
    border-radius: 7px;
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
    border-bottom: 1px solid #e8e6f0; /* Optional: Add a bottom border */
}

/* Per-section badge colours */
.soap-section:nth-child(1) .soap-glyph,
.soap-section .section-head[data-section="s"] .soap-glyph { background: #e8f0ff; color: #1d4ed8; }

/* S badge — blue */
.sh-left .soap-glyph.glyph-s { background: #e8f0ff; color: #1d4ed8; }
/* O badge — amber */
.sh-left .soap-glyph.glyph-o { background: #fef3c7; color: #b45309; }
/* A badge — red */
.sh-left .soap-glyph.glyph-a { background: #fee2e2; color: #b91c1c; }
/* P badge — green */
.sh-left .soap-glyph.glyph-p { background: #dcfce7; color: #15803d; }

/* Better textarea styling */
textarea.form-control {
    border: 1px solid var(--border) !important;
    border-radius: 8px !important;
    padding: 10px 12px !important;
    font-size: 0.82rem !important;
    color: var(--text) !important;
    resize: vertical;
    transition: border-color 0.15s, box-shadow 0.15s;
}
textarea.form-control:focus {
    outline: none !important;
    border-color: #7c3aed !important;
    box-shadow: 0 0 0 3px rgba(124,58,237,0.10) !important;
}

/* Chip base improvements */
.chip {
    font-size: 0.75rem !important;
    padding: 4px 11px !important;
    border-radius: 20px !important;
    background: #f0eeff !important;
    border-color: #d4cee8 !important;
    color: #5b21b6 !important;
}

.ai-btn {
    margin-left: 10px; /* Optional: Add some space between the text and the button */
    background: linear-gradient(135deg,#5b3fa6 0%,#3ab8c8 100%) !important; /* Button background color */
    color: white; /* Button text color */
    border: none; /* No border */
    padding: 5px 10px; /* Padding for the button */
    border-radius: 7px; /* Rounded corners */
    cursor: pointer; /* Pointer cursor on hover */
}

.ai-btn:hover {
    background-color: #0056b3; /* Darker shade on hover */
}

/* ── Auto-detect toggle ─────────────────────────── */
.autodetect-toggle {
    display: flex;
    align-items: center;
    gap: 7px;
    cursor: pointer;
    user-select: none;
    padding: 4px 10px;
    border-radius: 20px;
    border: 1.5px solid #d4cee8;
    transition: border-color 0.15s;
}
.autodetect-toggle:hover { border-color: #7c3aed; }
.autodetect-toggle input { display: none; }
.autodetect-slider {
    width: 32px; height: 18px;
    background: #d1d5db;
    border-radius: 9px;
    position: relative;
    transition: background 0.2s;
    flex-shrink: 0;
}
.autodetect-slider::after {
    content: '';
    position: absolute;
    width: 12px; height: 12px;
    background: #fff;
    border-radius: 50%;
    top: 3px; left: 3px;
    transition: transform 0.2s;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.autodetect-toggle input:checked ~ .autodetect-slider { background: #7c3aed; }
.autodetect-toggle input:checked ~ .autodetect-slider::after { transform: translateX(14px); }
.autodetect-label { font-size: 11px; font-weight: 700; color: #7c6fa0; letter-spacing: 0.06em; }

/* ── Transcript body upgrade ────────────────────── */
.transcript-hint { color: #9ca3af; font-size: 13px; }
.transcript-hint strong { color: #6b5fa0; }
.transcript-hint em { font-style: normal; color: #7c3aed; font-weight: 600; }
.transcript-sentence {
    display: inline;
    padding: 1px 3px;
    border-radius: 3px;
    font-size: 13px;
    line-height: 1.8;
}
.ts-s { background: #ede9fe; color: #5b21b6; }
.ts-o { background: #dbeafe; color: #1d4ed8; }
.ts-a { background: #fef3c7; color: #92400e; }
.ts-p { background: #dcfce7; color: #15803d; }
.ts-u { background: #f3f4f6; color: #6b7280; }
.ts-interim { color: #aaa; font-style: italic; }

/* ── Route legend ───────────────────────────────── */
.route-legend {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.06em;
    padding: 3px 8px;
    border-radius: 10px;
}
.s-legend { background: #ede9fe; color: #5b21b6; }
.o-legend { background: #dbeafe; color: #1d4ed8; }
.a-legend { background: #fef3c7; color: #92400e; }
.p-legend { background: #dcfce7; color: #15803d; }
.u-legend { background: #f3f4f6; color: #6b7280; }

/* ── Specialty Bar ──────────────────────────────── */
#specialtyBar {
    position: sticky;
    top: 0;
    z-index: 50;
    background: #fff;
    border-bottom: 2px solid #ece8f8;
    box-shadow: 0 2px 12px rgba(71,55,122,.08);
    margin-bottom: 18px;
}
.specialty-bar-inner {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    flex-wrap: wrap;
}
.specialty-bar-label {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: #7c6fa0;
    white-space: nowrap;
    padding-right: 10px;
    border-right: 2px solid #ece8f8;
    margin-right: 4px;
}
.specialty-pills { display: flex; flex-wrap: wrap; gap: 6px; }
.sp-pill {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    padding: 5px 13px;
    border-radius: 20px;
    border: 1.5px solid #d4cee8;
    background: transparent;
    color: #7c6fa0;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.sp-pill:hover { border-color: #7c3aed; color: #7c3aed; background: #f5f0ff; }
.sp-pill.active {
    background: linear-gradient(135deg, #5b21b6, #7c3aed);
    border-color: transparent;
    color: #fff;
    box-shadow: 0 2px 8px rgba(124,58,237,.3);
}

/* ── Billing box upgrade ────────────────────────── */
.billing-box {
    margin-top: 0;
    padding: 12px 16px;
    background: linear-gradient(135deg,#f8f6ff,#f0f9ff);
    border-top: 2px solid #e0d8f8;
}
.billing-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 8px;
}
.billing-col-label {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.blabel-tag {
    display: inline-block;
    padding: 2px 7px;
    border-radius: 10px;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.1em;
}
.blabel-suggest { background:#dbeafe; color:#1d4ed8; }
.blabel-accepted { background:#dcfce7; color:#15803d; }
.billing-empty { font-size: 11px; color: #aaa; font-style: italic; padding: 4px 0; }
.billing-chip {
    font-size: 11px;
    font-weight: 600;
    padding: 4px 10px;
    cursor: pointer;
    transition: all 0.12s;
}
.billing-chip:hover { opacity: 0.8; transform: scale(0.97); }
.chip.billing-chip.accepted {
    background: #dcfce7;
    border: 1.5px solid #4ade80;
    color: #15803d;
}
.billing-audit {
    margin-top: 10px;
    font-size: 11px;
    color: #6b5fa0;
    background: #f5f0ff;
    border-radius: 6px;
    padding: 6px 10px;
    border-left: 3px solid #a78bfa;
    line-height: 1.5;
}


/* ═══════════════════════════════════════════════
   VISUAL REDESIGN — clean overrides (final layer)
   ═══════════════════════════════════════════════ */

/* ── Reset conflicting chip defaults ── */
.chip {
    font-size: 0.73rem !important;
    padding: 4px 12px !important;
    border-radius: 20px !important;
    background: #f0eeff !important;
    border: 1px solid #d4cee8 !important;
    color: #5b21b6 !important;
    font-weight: 500 !important;
}
.chip:hover { background: #e5deff !important; border-color: #7c3aed !important; }
.chip.on-green { background: #dcfce7 !important; border-color: #4ade80 !important; color: #15803d !important; }
.chip.on-blue  { background: #dbeafe !important; border-color: #60a5fa !important; color: #1d4ed8 !important; }
.chip.on-gold  { background: #fef3c7 !important; border-color: #fbbf24 !important; color: #92400e !important; }
.chip.on-red   { background: #fee2e2 !important; border-color: #f87171 !important; color: #b91c1c !important; }
/* billing chips keep their own look */
.chip.billing-chip.accepted { background: #dcfce7 !important; border: 1.5px solid #4ade80 !important; color: #15803d !important; }

/* ── Dropdowns everywhere (cell + standalone) ── */
select, .cell select {
    appearance: none !important;
    -webkit-appearance: none !important;
    background-color: #fff !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23aaa' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 10px center !important;
    border: 1px solid #e2dff0 !important;
    border-radius: 8px !important;
    padding: 8px 32px 8px 11px !important;
    font-size: 0.82rem !important;
    color: #1a1a2e !important;
    cursor: pointer !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    width: 100% !important;
    box-shadow: none !important;
}
select:hover, .cell select:hover { border-color: #b0b0cc !important; }
select:focus, .cell select:focus {
    outline: none !important;
    border-color: #7c3aed !important;
    box-shadow: 0 0 0 3px rgba(124,58,237,.10) !important;
}

/* ── Cell inputs ── */
.cell input[type="text"] {
    border: 1px solid #e2dff0 !important;
    border-radius: 8px !important;
    padding: 8px 11px !important;
    font-size: 0.82rem !important;
    background: #fff !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}
.cell input[type="text"]:focus {
    outline: none !important;
    border-color: #7c3aed !important;
    box-shadow: 0 0 0 3px rgba(124,58,237,.10) !important;
}

/* ── Cell label ── */
.cell label, .cell > label {
    font-size: 0.62rem !important;
    letter-spacing: 0.11em !important;
    text-transform: uppercase !important;
    color: #9994b0 !important;
    font-weight: 700 !important;
    display: block !important;
    margin-bottom: 5px !important;
}

/* ── Cell padding ── */
.cell { padding: 0.7rem 0.85rem !important; border-right: 1px solid #eeeaf8 !important; }
.cell:last-child { border-right: none !important; }
.row-strip { border-bottom: 1px solid #eeeaf8 !important; background: #fff !important; }

/* ── Textarea ── */
textarea.form-control {
    border: 1px solid #e2dff0 !important;
    border-radius: 10px !important;
    padding: 11px 13px !important;
    font-size: 0.83rem !important;
    color: #1a1a2e !important;
    background: #fff !important;
    resize: vertical !important;
    width: 100% !important;
    margin: 10px 0 !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
    line-height: 1.6 !important;
}
textarea.form-control:focus {
    outline: none !important;
    border-color: #7c3aed !important;
    box-shadow: 0 0 0 3px rgba(124,58,237,.10) !important;
}

/* ── Section cards ── */
.soap-section {
    background: #fff !important;
    border: 1px solid #e8e4f4 !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    margin-bottom: 18px !important;
    box-shadow: 0 1px 6px rgba(71,55,122,.06) !important;
}

/* ── Section head ── */
.section-head {
    background: #fff !important;
    border-bottom: 1px solid #eeeaf8 !important;
    padding: 13px 16px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
}

/* ── Soap glyph as circle badge ── */
.soap-glyph {
    font-style: normal !important;
    font-size: 0.95rem !important;
    font-weight: 800 !important;
    width: 36px !important;
    height: 36px !important;
    border-radius: 50% !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
    line-height: 1 !important;
}
.soap-glyph.glyph-s { background: #dbeafe !important; color: #1d4ed8 !important; }
.soap-glyph.glyph-o { background: #fef3c7 !important; color: #b45309 !important; }
.soap-glyph.glyph-a { background: #fee2e2 !important; color: #b91c1c !important; }
.soap-glyph.glyph-p { background: #dcfce7 !important; color: #15803d !important; }

.soap-info strong { font-size: 0.95rem !important; font-weight: 700 !important; letter-spacing: 0.01em !important; }
.soap-info span   { font-size: 0.6rem  !important; color: #b0aac0 !important; letter-spacing: 0.09em !important; text-transform: uppercase !important; }
.sh-left { gap: 12px !important; }

/* ── Quick tags ── */
.qtags { background: #faf9ff !important; border-top: 1px solid #eeeaf8 !important; padding: 8px 12px 10px !important; gap: 6px !important; }
.qtag  { font-size: 0.7rem !important; padding: 4px 11px !important; border-radius: 20px !important; border: 1px solid #ddd9f0 !important; background: #fff !important; color: #555 !important; }
.qtag:hover { border-color: #7c3aed !important; color: #7c3aed !important; background: #f5f0ff !important; }

/* ── Outcome buttons ── */
.ocb { font-size: 0.73rem !important; padding: 5px 13px !important; border-radius: 20px !important; border: 1px solid #ddd9f0 !important; background: #fff !important; color: #666 !important; }
.ocb:hover { border-color: #7c3aed !important; color: #7c3aed !important; }
.ocb.g { background: #dcfce7 !important; border-color: #4ade80 !important; color: #15803d !important; }
.ocb.r { background: #fee2e2 !important; border-color: #f87171 !important; color: #b91c1c !important; }

/* ── chip-group / chip-group2 ── */
.chip-group, .chip-group2 {
    padding: 10px 14px !important;
    border-bottom: 1px solid #eeeaf8 !important;
    background: #fff !important;
}
.chip-group-label {
    font-size: 0.6rem !important;
    letter-spacing: 0.13em !important;
    text-transform: uppercase !important;
    color: #a0a0b8 !important;
    font-weight: 700 !important;
    display: block !important;
    margin-bottom: 7px !important;
}
.chips { gap: 6px !important; }

/* ── Plan layout additions ── */
.plan-dropdowns-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    border-bottom: 1px solid #eeeaf8;
    background: #fff;
}
.plan-dropdowns-row .cell { border-right: 1px solid #eeeaf8; }
.plan-dropdowns-row .cell:last-child { border-right: none; }

.outcome-strip { background: #fff !important; border-bottom: 1px solid #eeeaf8 !important; }
.oc-cell { border-right: 1px solid #eeeaf8 !important; padding: 10px 14px !important; }
.oc-cell:last-child { border-right: none !important; }
.oc-cell > label { font-size: 0.6rem !important; letter-spacing: 0.11em !important; color: #a0a0b8 !important; font-weight: 700 !important; text-transform: uppercase !important; display: block !important; margin-bottom: 6px !important; }
.oc-btns { gap: 6px !important; flex-wrap: wrap !important; }

/* soapdrp */
.soapdrp {
    font-size: 12px !important;
    padding: 7px 28px 7px 10px !important;
    border: 1px solid #ddd9f0 !important;
    border-radius: 7px !important;
    background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23aaa' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") no-repeat right 10px center !important;
    appearance: none !important;
    color: #1a1a2e !important;
}


/* ── Exercise picker ── */
.ex-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 9px 12px;
    border-bottom: 1px solid #f0eef8;
    cursor: pointer;
    transition: background 0.1s;
    font-weight: normal;
}
.ex-item:last-child { border-bottom: none; }
.ex-item:hover { background: #faf8ff; }
.ex-item input[type="checkbox"] {
    margin-top: 2px;
    flex-shrink: 0;
    accent-color: #7c3aed;
    width: 14px; height: 14px;
}
.ex-name { font-size: 0.8rem; font-weight: 600; color: #1a1a2e; display: block; }
.ex-sub  { font-size: 0.7rem; color: #a0a0b8; display: block; margin-top: 1px; }
.ex-item span { display: flex; flex-direction: column; }
.sel-ex-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 8px 10px;
    border: 1px solid #e8e4f4;
    border-radius: 8px;
    margin-bottom: 6px;
    background: #fff;
}
.sel-ex-name { font-size: 0.8rem; font-weight: 600; color: #1a1a2e; }
.sel-ex-detail { font-size: 0.7rem; color: #a0a0b8; margin-top: 1px; }
.sel-ex-remove { color: #ccc; cursor: pointer; font-size: 16px; line-height: 1; padding: 0 2px; background: none; border: none; }
.sel-ex-remove:hover { color: #b91c1c; }

/* ═══════════════════════════════════════════════
   FINAL VISUAL OVERRIDE (top specificity layer)
   ═══════════════════════════════════════════════ */

/* chips */
.chip {
    font-size: 0.73rem !important;
    padding: 5px 12px !important;
    border-radius: 20px !important;
    background: #f0eeff !important;
    border: 1px solid #d4cee8 !important;
    color: #5b21b6 !important;
    font-weight: 500 !important;
    display: inline-block !important;
}
.chip:hover { background: #e5deff !important; border-color: #7c3aed !important; color: #5b21b6 !important; }
.chip.on-green { background: #dcfce7 !important; border-color: #4ade80 !important; color: #15803d !important; }
.chip.on-blue  { background: #dbeafe !important; border-color: #60a5fa !important; color: #1d4ed8 !important; }
.chip.on-gold  { background: #fef3c7 !important; border-color: #fbbf24 !important; color: #92400e !important; }
.chip.on-red   { background: #fee2e2 !important; border-color: #f87171 !important; color: #b91c1c !important; }
.chip.billing-chip.accepted { background: #dcfce7 !important; border-color: #4ade80 !important; color: #15803d !important; }

/* All selects */
select {
    -webkit-appearance: none !important;
    appearance: none !important;
    background-color: #fff !important;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23aaa' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 10px center !important;
    border: 1px solid #e2dff0 !important;
    border-radius: 8px !important;
    padding: 8px 32px 8px 11px !important;
    font-size: 0.82rem !important;
    color: #1a1a2e !important;
    cursor: pointer !important;
    width: 100% !important;
    transition: border-color 0.15s, box-shadow 0.15s !important;
}
select:hover { border-color: #b0b0cc !important; }
select:focus { outline: none !important; border-color: #7c3aed !important; box-shadow: 0 0 0 3px rgba(124,58,237,.10) !important; }

/* Cell text inputs */
.cell input[type="text"] {
    border: 1px solid #e2dff0 !important;
    border-radius: 8px !important;
    padding: 8px 11px !important;
    font-size: 0.82rem !important;
    background: #fff !important;
    color: #1a1a2e !important;
    width: 100% !important;
}
.cell input[type="text"]:focus { outline: none !important; border-color: #7c3aed !important; box-shadow: 0 0 0 3px rgba(124,58,237,.10) !important; }

/* Cell labels */
.cell label, .cell > label {
    font-size: 0.62rem !important;
    letter-spacing: 0.11em !important;
    text-transform: uppercase !important;
    color: #9994b0 !important;
    font-weight: 700 !important;
    display: block !important;
    margin-bottom: 5px !important;
}
.cell { padding: 0.7rem 0.85rem !important; border-right: 1px solid #eeeaf8 !important; background: #fff !important; }
.cell:last-child { border-right: none !important; }
.row-strip { border-bottom: 1px solid #eeeaf8 !important; background: #fff !important; }

/* Textareas */
textarea.form-control {
    border: 1px solid #e2dff0 !important;
    border-radius: 10px !important;
    padding: 11px 13px !important;
    font-size: 0.83rem !important;
    color: #1a1a2e !important;
    background: #fff !important;
    resize: vertical !important;
    width: 100% !important;
    margin: 10px 0 !important;
    line-height: 1.6 !important;
    display: block !important;
}
textarea.form-control:focus { outline: none !important; border-color: #7c3aed !important; box-shadow: 0 0 0 3px rgba(124,58,237,.10) !important; }

/* Section cards */
.soap-section {
    background: #fff !important;
    border: 1px solid #e8e4f4 !important;
    border-radius: 14px !important;
    overflow: hidden !important;
    margin-bottom: 18px !important;
    box-shadow: 0 1px 6px rgba(71,55,122,.06) !important;
}
.section-head {
    background: #fff !important;
    border-bottom: 1px solid #eeeaf8 !important;
    padding: 13px 16px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
}

/* Badge glyphs */
.soap-glyph {
    font-style: normal !important;
    font-size: 0.95rem !important;
    font-weight: 800 !important;
    width: 36px !important; height: 36px !important;
    border-radius: 50% !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex-shrink: 0 !important;
}
.glyph-s { background: #dbeafe !important; color: #1d4ed8 !important; }
.glyph-o { background: #fef3c7 !important; color: #b45309 !important; }
.glyph-a { background: #fee2e2 !important; color: #b91c1c !important; }
.glyph-p { background: #dcfce7 !important; color: #15803d !important; }
.soap-info strong { font-size: 0.95rem !important; font-weight: 700 !important; }
.soap-info span { font-size: 0.6rem !important; color: #b0aac0 !important; letter-spacing: 0.09em !important; text-transform: uppercase !important; }
.sh-left { gap: 12px !important; }

/* Quick tags */
.qtags { background: #faf9ff !important; border-top: 1px solid #eeeaf8 !important; padding: 8px 12px 10px !important; gap: 6px !important; flex-wrap: wrap !important; display: flex !important; }
.qtag { font-size: 0.7rem !important; padding: 4px 11px !important; border-radius: 20px !important; border: 1px solid #ddd9f0 !important; background: #fff !important; color: #555 !important; }
.qtag:hover { border-color: #7c3aed !important; color: #7c3aed !important; background: #f5f0ff !important; }

/* Outcome buttons */
.ocb { font-size: 0.73rem !important; padding: 5px 13px !important; border-radius: 20px !important; border: 1px solid #ddd9f0 !important; background: #fff !important; color: #666 !important; cursor: pointer !important; }
.ocb:hover { border-color: #7c3aed !important; color: #7c3aed !important; }
.ocb.g { background: #dcfce7 !important; border-color: #4ade80 !important; color: #15803d !important; }
.ocb.r { background: #fee2e2 !important; border-color: #f87171 !important; color: #b91c1c !important; }
.oc-cell { padding: 10px 14px !important; border-right: 1px solid #eeeaf8 !important; }
.oc-cell:last-child { border-right: none !important; }
.oc-cell > label { font-size: 0.6rem !important; letter-spacing: 0.11em !important; color: #a0a0b8 !important; font-weight: 700 !important; text-transform: uppercase !important; display: block !important; margin-bottom: 7px !important; }
.oc-btns { gap: 6px !important; flex-wrap: wrap !important; display: flex !important; }
.outcome-strip { background: #fff !important; border-bottom: 1px solid #eeeaf8 !important; display: grid !important; grid-template-columns: repeat(3,1fr) !important; }

/* chip-group / chip-group2 */
.chip-group, .chip-group2 {
    padding: 10px 14px !important;
    border-bottom: 1px solid #eeeaf8 !important;
    background: #fff !important;
    display: block !important;
}
.chip-group-label { font-size: 0.6rem !important; letter-spacing: 0.13em !important; text-transform: uppercase !important; color: #a0a0b8 !important; font-weight: 700 !important; display: block !important; margin-bottom: 8px !important; }
.chips { gap: 6px !important; display: flex !important; flex-wrap: wrap !important; }

    /* ── SOAP Templates Panel ───────────────────────── */
/* ── SOAP Notes header bar — holds the title + Templates dropdown.
     Always visible regardless of SOAP vs Accident Form mode, so the
     Templates button is reachable even when the Ambient Scribe bar
     (and everything else) is hidden. ── */
.soap-notes-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.soap-notes-bar h2 {
  font-size: 1.4rem;
  font-weight: 700;
  color: #2d2542;
}

/* Templates button now sits on a light background instead of the purple
   Ambient Scribe bar, so it needs filled (not ghost) styling here. */
.soap-notes-bar .tmpl-btn {
  background: linear-gradient(135deg, #5b21b6, #7c3aed);
  border: 1.5px solid transparent;
  color: #fff;
}
.soap-notes-bar .tmpl-btn:hover {
  background: linear-gradient(135deg, #4c1d95, #6d28d9);
  border-color: transparent;
}

.tmpl-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border: 1.5px solid rgba(255,255,255,0.35);
    border-radius: 8px;
    background: rgba(255,255,255,0.15);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.13s;
    white-space: nowrap;
}
.tmpl-btn:hover {
    background: rgba(255,255,255,0.28);
    border-color: rgba(255,255,255,0.6);
}
.tmpl-btn svg { flex-shrink: 0; }

/* Dropdown wrapper */
.tmpl-dropdown-wrap {
    position: relative;
    display: inline-block;
}

.tmpl-panel {
    display: none;
    position: absolute;
    top: calc(100% + 6px);
    right: 0;
    z-index: 9999;

    width: 340px;
    max-height: 520px;

    background: #fff;
    border: 1.5px solid #e2dff0;
    border-radius: 14px;
    box-shadow: 0 12px 40px rgba(91,33,182,.16), 0 2px 8px rgba(0,0,0,.06);

    flex-direction: column;

    overflow: hidden;
    animation: tmplFadeIn .15s ease;
}

.tmpl-panel.open {
    display: flex;
}

@keyframes tmplFadeIn {
    from { opacity:0; transform:translateY(-6px) scale(.98); }
    to   { opacity:1; transform:translateY(0)    scale(1);   }
}

/* Panel header */
.tmpl-panel-hdr {
    padding: 12px 16px 10px;
    background: linear-gradient(135deg, #5b21b6, #7c3aed);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.tmpl-panel-title {
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #fff;
}
.tmpl-panel-close {
    background: rgba(255,255,255,.2);
    border: none;
    border-radius: 50%;
    width: 22px; height: 22px;
    color: #fff;
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    display: flex; align-items: center; justify-content: center;
    padding: 0;
    transition: background .12s;
}
.tmpl-panel-close:hover { background: rgba(255,255,255,.35); }

/* Section nav tabs inside panel */
.tmpl-tabs {
    display: flex;
    border-bottom: 1.5px solid #e8e6f0;
    background: #faf9ff;
}
.tmpl-tab {
    flex: 1;
    padding: 8px 6px;
    border: none;
    background: transparent;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    color: #9994b0;
    cursor: pointer;
    transition: all .12s;
    border-bottom: 2.5px solid transparent;
    margin-bottom: -1.5px;
    font-family: inherit;
}
.tmpl-tab:hover { color: #5b21b6; }
.tmpl-tab.active {
    color: #5b21b6;
    border-bottom-color: #7c3aed;
    background: #fff;
}

/* Template list */
.tmpl-list {
    padding: 8px 0;
    max-height: 220px;
    flex: 1;
    overflow-y: auto;
}
.tmpl-list::-webkit-scrollbar { width: 4px; }
.tmpl-list::-webkit-scrollbar-thumb { background: #d4cee8; border-radius: 2px; }

.tmpl-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 16px;
    cursor: pointer;
    transition: background .1s;
    border-bottom: 1px solid #f3f0fa;
}
.tmpl-item:last-child { border-bottom: none; }
.tmpl-item:hover { background: #f5f0ff; }
.tmpl-item.selected { background: #ede9fe; }
.tmpl-item-icon {
    width: 28px; height: 28px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    flex-shrink: 0;
    background: #ede9fe;
}
.tmpl-item-info { flex: 1; min-width: 0; }
.tmpl-item-name {
    font-size: 12.5px;
    font-weight: 700;
    color: #1a1a2e;
    line-height: 1.2;
}
.tmpl-item-sub {
    font-size: 10.5px;
    color: #9994b0;
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.tmpl-item-check {
    width: 18px; height: 18px;
    border-radius: 50%;
    border: 1.5px solid #d4cee8;
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    transition: all .12s;
    font-size: 10px;
    color: transparent;
}
.tmpl-item.selected .tmpl-item-check {
    background: #5b21b6;
    border-color: #5b21b6;
    color: #fff;
}

/* Additional templates (dimmed, coming soon) */
.tmpl-item.additional .tmpl-item-name { color: #9994b0; }
.tmpl-item.additional .tmpl-item-sub  { color: #c4bfda; }
.tmpl-item.additional .tmpl-item-icon { background: #f3f0fa; filter: grayscale(.5); }
.tmpl-soon-tag {
    font-size: 9px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 8px;
    background: #fef3c7;
    color: #92400e;
    white-space: nowrap;
    flex-shrink: 0;
}

/* Panel footer actions */
.tmpl-actions {
    padding: 10px 12px;
    border-top: 1.5px solid #e8e6f0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    background: #faf9ff;
}
.tmpl-action-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    width: 100%;
    padding: 8px 12px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: all .13s;
    border: 1.5px solid transparent;
}
.tmpl-action-apply {
    background: linear-gradient(135deg, #5b21b6, #7c3aed);
    color: #fff;
    border-color: transparent;
}
.tmpl-action-apply:hover { opacity: .9; }
.tmpl-action-apply:disabled {
    background: #d4cee8;
    color: #9994b0;
    cursor: not-allowed;
}
.tmpl-action-copy {
    background: #fff;
    color: #5b21b6;
    border-color: #d4cee8;
}
.tmpl-action-copy:hover { background: #f5f0ff; border-color: #7c3aed; }
.tmpl-action-save {
    background: #fff;
    color: #15803d;
    border-color: #d4cee8;
}
.tmpl-action-save:hover { background: #f0fdf4; border-color: #4ade80; }

/* Section jump pills inside panel */
.tmpl-section-nav {
    display: flex;
    gap: 5px;
    padding: 8px 12px;
    border-bottom: 1.5px solid #e8e6f0;
    background: #fff;
}
.tmpl-snav-pill {
    flex: 1;
    padding: 4px 6px;
    border-radius: 6px;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .04em;
    text-align: center;
    cursor: pointer;
    border: none;
    font-family: inherit;
    transition: all .12s;
}
.tmpl-snav-s { background: #dbeafe; color: #1d4ed8; }
.tmpl-snav-s:hover { background: #bfdbfe; }
.tmpl-snav-o { background: #fef3c7; color: #92400e; }
.tmpl-snav-o:hover { background: #fde68a; }
.tmpl-snav-a { background: #dcfce7; color: #15803d; }
.tmpl-snav-a:hover { background: #bbf7d0; }
.tmpl-snav-p { background: #ede9fe; color: #5b21b6; }
.tmpl-snav-p:hover { background: #ddd6fe; }
.tmpl-mode-btn.active { background: #5b21b6 !important; color: #fff !important; }
.tmpl-mode-btn:hover:not(.active) { background: #f5f0ff !important; color: #5b21b6 !important; }
</style>
   
</head>
<body>
    <div class="container mt-3">
        <div class="row">
            <div class="col-12" style="padding-top:30px;">
                <div class="soap-notes-bar">
                    <h2 style="margin:0;"><?php echo smarty_function_xlt(array('t'=>'Clinical Notes'),$_smarty_tpl);?>
</h2>
                    <div style="display:flex;align-items:center;gap:10px;">
                    <!-- Templates dropdown — moved here so it stays visible regardless of
                         whether the SOAP UI or the Accident Form is the active mode. This
                         is the only way back to a SOAP template once Auto Accident has
                         hidden the Ambient Scribe bar, so it cannot live inside anything
                         that toggle gets to hide. -->
                    <div class="tmpl-dropdown-wrap" id="tmplDropdownWrap">
                        <button type="button" class="tmpl-btn" id="tmplToggleBtn" onclick="tmplTogglePanel()" aria-haspopup="true" aria-expanded="false">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                            Templates
                            <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" id="tmplChevron"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>

                        <!-- ── Template Panel ── -->
                        <div class="tmpl-panel" id="tmplPanel" role="dialog" aria-label="Note Templates">

                            <!-- Header -->
                            <div class="tmpl-panel-hdr">
                                <div>
                                    <div class="tmpl-panel-title">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-2px;margin-right:4px;"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                                        Note Templates
                                    </div>
                                    <div style="font-size:10px;color:rgba(255,255,255,.65);margin-top:2px;letter-spacing:.02em;">Select a template to pre-fill the entire note</div>
                                </div>
                                <button class="tmpl-panel-close" type="button" onclick="tmplClosePanel()" aria-label="Close">×</button>
                            </div>


                                    <!-- Tabs: Core / Additional -->
                                    <div class="tmpl-tabs">
                                        <button class="tmpl-tab active" type="button" data-tab="core" onclick="tmplSwitchTab(this)">Core Templates</button>
                                        <button class="tmpl-tab" type="button" data-tab="additional" onclick="tmplSwitchTab(this)">Additional</button>
                                    </div>

                                    <!-- Core template list -->
                                    <div class="tmpl-list" id="tmplListCore">
                                        <div class="tmpl-item selected" data-tmpl="blank" onclick="tmplSelect(this)">
                                            <div class="tmpl-item-icon">📄</div>
                                            <div class="tmpl-item-info">
                                                <div class="tmpl-item-name">SOAP Note</div>
                                                <div class="tmpl-item-sub">Start from scratch</div>
                                            </div>
                                            <div class="tmpl-item-check">✓</div>
                                        </div>
                                        <div class="tmpl-item" data-tmpl="auto" onclick="tmplSelect(this)">
                                            <div class="tmpl-item-icon">🚗</div>
                                            <div class="tmpl-item-info">
                                                <div class="tmpl-item-name">Auto Accident</div>
                                                <div class="tmpl-item-sub">Structured accident intake form</div>
                                            </div>
                                            <div class="tmpl-item-check">✓</div>
                                        </div>
                                        <div class="tmpl-item" data-tmpl="reexam" onclick="tmplSelect(this)">
                                            <div class="tmpl-item-icon">🔍</div>
                                            <div class="tmpl-item-info">
                                                <div class="tmpl-item-name">Re-Exam</div>
                                                <div class="tmpl-item-sub">Structured progress re-exam form</div>
                                            </div>
                                            <div class="tmpl-item-check">✓</div>
                                        </div>
                                        <div class="tmpl-item" data-tmpl="diagnosis" onclick="tmplSelect(this)">
                                            <div class="tmpl-item-icon">🩺</div>
                                            <div class="tmpl-item-info">
                                                <div class="tmpl-item-name">Diagnosis Summary</div>
                                                <div class="tmpl-item-sub">Structured diagnosis &amp; clinical impression form</div>
                                            </div>
                                            <div class="tmpl-item-check">✓</div>
                                        </div>
                                        <div class="tmpl-item" data-tmpl="discharge" onclick="tmplSelect(this)">
                                            <div class="tmpl-item-icon">🏁</div>
                                            <div class="tmpl-item-info">
                                                <div class="tmpl-item-name">Discharge Summary</div>
                                                <div class="tmpl-item-sub">Treatment summary, outcomes &amp; discharge narrative</div>
                                            </div>
                                            <div class="tmpl-item-check">✓</div>
                                        </div>
                                    </div>

                                    <!-- Additional template list -->
                                    <div class="tmpl-list" id="tmplListAdditional" style="display:none;">
                                        <div class="tmpl-item additional">
                                            <div class="tmpl-item-icon">⚖️</div>
                                            <div class="tmpl-item-info"><div class="tmpl-item-name">Personal Injury Attorney Narrative</div><div class="tmpl-item-sub">PI documentation template</div></div>
                                            <span class="tmpl-soon-tag">Soon</span>
                                        </div>
                                        <div class="tmpl-item additional">
                                            <div class="tmpl-item-icon">🏥</div>
                                            <div class="tmpl-item-info"><div class="tmpl-item-name">Medicare Maintenance</div><div class="tmpl-item-sub">Medicare maintenance care note</div></div>
                                            <span class="tmpl-soon-tag">Soon</span>
                                        </div>
                                        <div class="tmpl-item additional">
                                            <div class="tmpl-item-icon">🦺</div>
                                            <div class="tmpl-item-info"><div class="tmpl-item-name">Work Comp Follow-Up</div><div class="tmpl-item-sub">Workers compensation follow-up</div></div>
                                            <span class="tmpl-soon-tag">Soon</span>
                                        </div>
                                        <div class="tmpl-item additional">
                                            <div class="tmpl-item-icon">📋</div>
                                            <div class="tmpl-item-info"><div class="tmpl-item-name">Initial Chiropractic Evaluation</div><div class="tmpl-item-sub">New patient comprehensive eval</div></div>
                                            <span class="tmpl-soon-tag">Soon</span>
                                        </div>
                                        <div class="tmpl-item additional">
                                            <div class="tmpl-item-icon">🌿</div>
                                            <div class="tmpl-item-info"><div class="tmpl-item-name">Wellness Visit</div><div class="tmpl-item-sub">Preventive wellness note</div></div>
                                            <span class="tmpl-soon-tag">Soon</span>
                                        </div>
                                        <div class="tmpl-item additional">
                                            <div class="tmpl-item-icon">👶</div>
                                            <div class="tmpl-item-info"><div class="tmpl-item-name">Pediatric Chiropractic</div><div class="tmpl-item-sub">Pediatric chiropractic visit</div></div>
                                            <span class="tmpl-soon-tag">Soon</span>
                                        </div>
                                        <div class="tmpl-item additional">
                                            <div class="tmpl-item-icon">🏅</div>
                                            <div class="tmpl-item-info"><div class="tmpl-item-name">Sports Injury Follow-Up</div><div class="tmpl-item-sub">Athletic / sports injury note</div></div>
                                            <span class="tmpl-soon-tag">Soon</span>
                                        </div>
                                    </div>

                                    <!-- Footer actions -->
                                    <div class="tmpl-actions">
                                        <!-- Mode toggle: Replace vs Append -->
                                        <div style="display:flex;gap:0;margin-bottom:8px;border:1.5px solid #ddd6fe;border-radius:7px;overflow:hidden;">
                                            <button type="button" class="tmpl-mode-btn active" data-mode="replace"
                                                onclick="tmplSetMode('replace')"
                                                style="flex:1;padding:6px 10px;border:none;font-size:11px;font-weight:700;font-family:inherit;cursor:pointer;background:#ede9fe;color:#5b21b6;transition:all .12s;">
                                                ↺ Replace
                                            </button>
                                            <button type="button" class="tmpl-mode-btn" data-mode="prepend"
                                                onclick="tmplSetMode('prepend')"
                                                style="flex:1;padding:6px 10px;border:none;font-size:11px;font-weight:700;font-family:inherit;cursor:pointer;background:#fff;color:#9ca3af;border-left:1.5px solid #ddd6fe;transition:all .12s;">
                                                ↑ Prepend
                                            </button>
                                            <button type="button" class="tmpl-mode-btn" data-mode="append"
                                                onclick="tmplSetMode('append')"
                                                style="flex:1;padding:6px 10px;border:none;font-size:11px;font-weight:700;font-family:inherit;cursor:pointer;background:#fff;color:#9ca3af;border-left:1.5px solid #ddd6fe;transition:all .12s;">
                                                + Append
                                            </button>
                                        </div>
                                        <div style="display:flex;gap:6px;" id="tmplCopyPreviousWrap">
                                            <button type="button" id="tmplCopyPreviousBtn" class="tmpl-action-btn tmpl-action-copy" style="flex:1;" onclick="tmplCopyPrevious()">
                                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                                Copy Previous Note
                                            </button>
                                        </div>
                                    </div>

                                </div><!-- /tmpl-panel -->
                            </div><!-- /tmpl-dropdown-wrap -->

                    <!-- Clinical Narratives dropdown — structured identically to Templates,
                         but inserts plain narrative paragraph text into Subjective rather
                         than switching the page into a structured form. Fully independent
                         state/markup from the Templates dropdown (separate IDs, separate
                         JS functions) so the two don't interfere with each other. -->
                    <div class="tmpl-dropdown-wrap" id="narrDropdownWrap">
                        <button type="button" class="tmpl-btn" id="narrToggleBtn" onclick="narrTogglePanel()" aria-haspopup="true" aria-expanded="false">
                            <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                            Clinical Narratives
                            <svg width="9" height="9" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" id="narrChevron"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>

                        <!-- ── Narrative Panel ── -->
                        <div class="tmpl-panel" id="narrPanel" role="dialog" aria-label="Clinical Narratives">

                            <!-- Header -->
                            <div class="tmpl-panel-hdr">
                                <div>
                                    <div class="tmpl-panel-title">
                                        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="vertical-align:-2px;margin-right:4px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                        Clinical Narratives
                                    </div>
                                    <div style="font-size:10px;color:rgba(255,255,255,.65);margin-top:2px;letter-spacing:.02em;">Insert a narrative paragraph into Subjective</div>
                                </div>
                                <button class="tmpl-panel-close" type="button" onclick="narrClosePanel()" aria-label="Close">×</button>
                            </div>

                            <!-- Narrative list -->
                            <div class="tmpl-list" id="narrList">
                                <div class="tmpl-item" data-narr="maintenance" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">🔄</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Maintenance</div>
                                        <div class="tmpl-item-sub">Routine maintenance chiropractic care</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                                <div class="tmpl-item" data-narr="acute" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">⚡</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Acute Flare</div>
                                        <div class="tmpl-item-sub">Acute increase in symptoms</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                                <div class="tmpl-item" data-narr="auto" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">🚗</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Auto Accident</div>
                                        <div class="tmpl-item-sub">Motor vehicle collision injuries</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                                <div class="tmpl-item" data-narr="reexam" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">🔍</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Re-Exam</div>
                                        <div class="tmpl-item-sub">Progress re-examination</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                                <div class="tmpl-item" data-narr="pi" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">⚖️</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Personal Injury Narrative</div>
                                        <div class="tmpl-item-sub">PI / attorney documentation</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                                <div class="tmpl-item" data-narr="medicare" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">🏥</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Medicare Maintenance Care Narrative</div>
                                        <div class="tmpl-item-sub">Medicare maintenance documentation</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                                <div class="tmpl-item" data-narr="workcomp" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">🦺</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Worker's Compensation Follow-Up</div>
                                        <div class="tmpl-item-sub">Work-related injury follow-up</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                                <div class="tmpl-item" data-narr="discharge" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">🏁</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Discharge Narrative</div>
                                        <div class="tmpl-item-sub">Completion of active care</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                                <div class="tmpl-item" data-narr="wellness" onclick="narrSelect(this)">
                                    <div class="tmpl-item-icon">🌿</div>
                                    <div class="tmpl-item-info">
                                        <div class="tmpl-item-name">Wellness Visit</div>
                                        <div class="tmpl-item-sub">Preventive wellness note</div>
                                    </div>
                                    <div class="tmpl-item-check">✓</div>
                                </div>
                            </div>

                            <!-- Footer actions -->
                            <div class="tmpl-actions">
                                <!-- Mode toggle: Replace vs Prepend vs Append -->
                                <div style="display:flex;gap:0;margin-bottom:8px;border:1.5px solid #ddd6fe;border-radius:7px;overflow:hidden;">
                                    <button type="button" class="narr-mode-btn active" data-mode="replace"
                                        onclick="narrSetMode('replace')"
                                        style="flex:1;padding:6px 10px;border:none;font-size:11px;font-weight:700;font-family:inherit;cursor:pointer;background:#ede9fe;color:#5b21b6;transition:all .12s;">
                                        ↺ Replace
                                    </button>
                                    <button type="button" class="narr-mode-btn" data-mode="prepend"
                                        onclick="narrSetMode('prepend')"
                                        style="flex:1;padding:6px 10px;border:none;font-size:11px;font-weight:700;font-family:inherit;cursor:pointer;background:#fff;color:#9ca3af;border-left:1.5px solid #ddd6fe;transition:all .12s;">
                                        ↑ Prepend
                                    </button>
                                    <button type="button" class="narr-mode-btn" data-mode="append"
                                        onclick="narrSetMode('append')"
                                        style="flex:1;padding:6px 10px;border:none;font-size:11px;font-weight:700;font-family:inherit;cursor:pointer;background:#fff;color:#9ca3af;border-left:1.5px solid #ddd6fe;transition:all .12s;">
                                        + Append
                                    </button>
                                </div>
                            </div>

                        </div><!-- /narrPanel -->
                    </div><!-- /narrDropdownWrap -->
                    </div><!-- /header dropdown group -->
                </div><!-- /soap-notes-bar -->

                <div class="transcript" id="transcriptBox">
                    <div class="transcript-top" style="display: flex; align-items: center; justify-content: space-between;">
                        <span class="transcript-label">🎙 Ambient Scribe</span>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div class="waveform" id="waveform" style="margin-right: 0;">
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
                    <!-- Transcript controls row -->
                    <div style="display:flex; align-items:center; gap:10px; padding:10px 18px 10px; background:#fff; border-top:1px solid #ece8f8; flex-wrap:wrap;">
                        <label style="font-size:11px;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;color:#7c6fa0;white-space:nowrap;">Insert Into</label>
                        <select id="soapSection" class="soapdrp">
                            <option value="subjective">Subjective</option>
                            <option value="objective">Objective</option>
                            <option value="assessment">Assessment</option>
                            <option value="plan">Plan</option>
                        </select>

                        <!-- Auto-detect toggle -->
                        <label class="autodetect-toggle" title="Automatically route each sentence to the right SOAP section">
                            <input type="checkbox" id="autoDetectToggle" checked>
                            <span class="autodetect-slider"></span>
                            <span class="autodetect-label">Auto-Route</span>
                        </label>

                        <button id="addToSoapBtn" type="button" style="display:none;" onclick="addToSoapNote()">＋ Add to Note</button>
                        <button id="generateSoapBtn" type="button" onclick="generateSOAP()" style="display:none; background:linear-gradient(135deg,#16a34a,#22c55e); color:#fff; border-radius:7px;">✦ Generate SOAP</button>
                        <button id="clearTranscriptBtn" type="button" style="display:none;" onclick="clearTranscript()">✕ Clear</button>
                    </div>

                    <!-- Live transcript body -->
                    <div class="transcript-body" id="transcriptBody">
                        <span class="transcript-hint">Press <strong>Begin Recording</strong> to start the ambient scribe. In <em>Auto-Route</em> mode each sentence is automatically sorted into the correct SOAP field as you speak.</span>
                    </div>

                    <!-- Section routing legend -->
                    <div id="routingLegend" style="display:none; padding:6px 18px 10px; background:#fff; gap:10px; flex-wrap:wrap; font-size:11px; border-top:1px solid #ece8f8;" class="d-flex align-items-center">
                        <span style="color:#888; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; font-size:10px;">Routed to →</span>
                        <span class="route-legend s-legend">S Subjective</span>
                        <span class="route-legend o-legend">O Objective</span>
                        <span class="route-legend a-legend">A Assessment</span>
                        <span class="route-legend p-legend">P Plan</span>
                        <span class="route-legend u-legend">? Unmatched</span>
                    </div>
                </div>
        </div>

        <!-- ── Specialty Bar ─────────────────────────────── -->
        <div id="specialtyBar">
            <div class="specialty-bar-inner">
                <span class="specialty-bar-label">Specialty</span>
                <div class="specialty-pills" id="specialtyPills">
                    <button type="button" class="sp-pill active" data-sp="chiro">Chiropractic</button>
                    <button type="button" class="sp-pill" data-sp="primarycare">Primary Care</button>
                    <button type="button" class="sp-pill" data-sp="familymed">Family Med</button>
                    <button type="button" class="sp-pill" data-sp="internalmed">Internal Med</button>
                    <button type="button" class="sp-pill" data-sp="urgentcare">Urgent Care</button>
                    <button type="button" class="sp-pill" data-sp="pain">Pain Mgmt</button>
                    <button type="button" class="sp-pill" data-sp="ortho">Orthopedics</button>
                    <button type="button" class="sp-pill" data-sp="neurology">Neurology</button>
                    <button type="button" class="sp-pill" data-sp="pmr">PM&amp;R</button>
                    <button type="button" class="sp-pill" data-sp="psychiatry">Psychiatry</button>
                    <button type="button" class="sp-pill" data-sp="psychology">Psychology</button>
                    <button type="button" class="sp-pill" data-sp="other">Other</button>
                </div>
                <!-- Hidden select keeps existing JS working -->
                <select id="aiSpecialty" style="display:none;">
                    <option value="chiro">Chiropractic</option>
                    <option value="primarycare">Primary Care</option>
                    <option value="internalmed">Internal Medicine</option>
                    <option value="familymed">Family Medicine</option>
                    <option value="urgentcare">Urgent Care</option>
                    <option value="pain">Pain Management</option>
                    <option value="ortho">Orthopedics</option>
                    <option value="neurology">Neurology</option>
                    <option value="pmr">PM&amp;R</option>
                    <option value="psychiatry">Psychiatry</option>
                    <option value="psychology">Psychology / Therapy</option>
                    <option value="other">Other</option>
                </select>
            </div>
        </div>

        <form name="soap" method="post" action="<?php echo $_smarty_tpl->tpl_vars['FORM_ACTION']->value;?>
/interface/forms/soap/save.php" onsubmit="prepareAccidentSubmit(); prepareReexamSubmit(); prepareDiagnosisSubmit(); prepareDischargeSubmit(); return top.restoreSession()">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr($_smarty_tpl->tpl_vars['CSRF_TOKEN_FORM']->value);?>
" />

            <!-- ══════════════════════════════════════════════════════════════
                 ACCIDENT FORM — replaces the SOAP UI entirely when "Auto
                 Accident" is selected from Templates. Mirrors the legacy
                 "Automobile Accident" + "Post Accident Treatment" screens.
                 This is NOT a SOAP note: it's its own structured intake form.
                 The backend (save.php) only persists subjective/objective/
                 assessment/plan text fields, so on submit, buildAccidentNote()
                 serializes every selection here into a clean structured text
                 block and writes it into the hidden `subjective` field — the
                 visible SOAP textareas stay out of the picture completely
                 while this form is active.
                 ══════════════════════════════════════════════════════════════ -->
            <div class="container" id="accidentFormWrapper" style="display:none;">
                <div class="form-group">
                    <div class="soap-wrapper">

                        <!-- ── Section 1: Automobile Accident Details ─────────── -->
                        <div class="soap-section">
                            <div class="section-head" style="display:flex; justify-content:space-between; align-items:center;">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#fee2e2;color:#b91c1c;">🚗</span>
                                    <div class="soap-info">
                                        <strong>Automobile Accident — Details</strong>
                                        <span class="soap-subtitle">Position · Restraint · Vehicle · Impact · Conditions</span>
                                    </div>
                                </div>
                                <button class="ai-btn" type="button" onclick="aaClearAll()" style="background:#fff;color:#b91c1c;border:1px solid #fecaca;">Clear Form</button>
                            </div>

                            <div class="cell" style="border-right:none; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                                <label style="margin:0; min-width:140px;">Date of Accident</label>
                                <input type="date" id="aa_date" onchange="updateAutoAccidentNote()">
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Position in the Vehicle</div>
                                <div class="aa-btn-grid" data-field="aa_position">
                                    <button type="button" class="aa-opt-btn" data-val="Driver">Driver</button>
                                    <button type="button" class="aa-opt-btn" data-val="Front Occupant">Front Occupant</button>
                                    <button type="button" class="aa-opt-btn" data-val="Left Rear Occupant">Left Rear Occupant</button>
                                    <button type="button" class="aa-opt-btn" data-val="Right Rear Occupant">Right Rear Occupant</button>
                                    <button type="button" class="aa-opt-btn" data-val="Middle Occupant">Middle Occupant</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Seat Restraint</div>
                                <div class="aa-btn-grid" data-field="aa_restraint">
                                    <button type="button" class="aa-opt-btn" data-val="Unrestrained">Unrestrained</button>
                                    <button type="button" class="aa-opt-btn" data-val="Seatbelt Was Worn">Seatbelt Was Worn</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Type of Vehicle Traveling In</div>
                                <div class="aa-btn-grid" data-field="aa_vehicle_type">
                                    <button type="button" class="aa-opt-btn" data-val="Compact Car">Compact Car</button>
                                    <button type="button" class="aa-opt-btn" data-val="Mid Size Car">Mid Size Car</button>
                                    <button type="button" class="aa-opt-btn" data-val="Full Size">Full Size</button>
                                    <button type="button" class="aa-opt-btn" data-val="Mini Van">Mini Van</button>
                                    <button type="button" class="aa-opt-btn" data-val="Van">Van</button>
                                    <button type="button" class="aa-opt-btn" data-val="SUV">SUV</button>
                                    <button type="button" class="aa-opt-btn" data-val="Pick up Truck">Pick up Truck</button>
                                    <button type="button" class="aa-opt-btn" data-val="Motorcycle">Motorcycle</button>
                                    <button type="button" class="aa-opt-btn" data-val="Tractor Trailer">Tractor Trailer</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Part of Vehicle Impacted First</div>
                                <div class="aa-btn-grid" data-field="aa_impact_part">
                                    <button type="button" class="aa-opt-btn" data-val="Rear End">Rear End</button>
                                    <button type="button" class="aa-opt-btn" data-val="Rear Driver Side">Rear Driver Side</button>
                                    <button type="button" class="aa-opt-btn" data-val="Rear Passenger Side">Rear Passenger Side</button>
                                    <button type="button" class="aa-opt-btn" data-val="Front">Front</button>
                                    <button type="button" class="aa-opt-btn" data-val="Front Driver Side">Front Driver Side</button>
                                    <button type="button" class="aa-opt-btn" data-val="Front Passenger Side">Front Passenger Side</button>
                                    <button type="button" class="aa-opt-btn" data-val="Driver Side Doors">Driver Side Doors</button>
                                    <button type="button" class="aa-opt-btn" data-val="Passenger Side Door">Passenger Side Door</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Time of Impact, Vehicle Was</div>
                                <div class="aa-btn-grid" data-field="aa_impact_time">
                                    <button type="button" class="aa-opt-btn" data-val="Stopped">Stopped</button>
                                    <button type="button" class="aa-opt-btn" data-val="Accelerating">Accelerating</button>
                                    <button type="button" class="aa-opt-btn" data-val="Decelerating">Decelerating</button>
                                    <button type="button" class="aa-opt-btn" data-val="Traveling in Traffic">Traveling in Traffic</button>
                                    <button type="button" class="aa-opt-btn" data-val="Making a Left Turn">Making a Left Turn</button>
                                    <button type="button" class="aa-opt-btn" data-val="Making a Right Turn">Making a Right Turn</button>
                                    <button type="button" class="aa-opt-btn" data-val="Going Through an Intersection">Going Through an Intersection</button>
                                    <button type="button" class="aa-opt-btn" data-val="Changing Lanes">Changing Lanes</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Secondary Impact Reported</div>
                                <div class="aa-btn-grid" data-field="aa_secondary_impact">
                                    <button type="button" class="aa-opt-btn" data-val="Yes">Yes</button>
                                    <button type="button" class="aa-opt-btn" data-val="No">No</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Direction Facing at Impact</div>
                                <div class="aa-btn-grid" data-field="aa_direction_facing">
                                    <button type="button" class="aa-opt-btn" data-val="Straight Ahead">Straight Ahead</button>
                                    <button type="button" class="aa-opt-btn" data-val="To the Left">To the Left</button>
                                    <button type="button" class="aa-opt-btn" data-val="To the Right">To the Right</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Brace for Impact</div>
                                <div class="aa-btn-grid" data-field="aa_brace">
                                    <button type="button" class="aa-opt-btn" data-val="Braced with Feet">Braced with Feet</button>
                                    <button type="button" class="aa-opt-btn" data-val="Braced with Hands">Braced with Hands</button>
                                    <button type="button" class="aa-opt-btn" data-val="Did Not Brace">Did Not Brace</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Road Conditions</div>
                                <div class="aa-btn-grid" data-field="aa_road_conditions">
                                    <button type="button" class="aa-opt-btn" data-val="Dry">Dry</button>
                                    <button type="button" class="aa-opt-btn" data-val="Wet">Wet</button>
                                    <button type="button" class="aa-opt-btn" data-val="Icy">Icy</button>
                                    <button type="button" class="aa-opt-btn" data-val="Snow Covered">Snow Covered</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Visibility</div>
                                <div class="aa-btn-grid" data-field="aa_visibility">
                                    <button type="button" class="aa-opt-btn" data-val="Excellent">Excellent</button>
                                    <button type="button" class="aa-opt-btn" data-val="Good">Good</button>
                                    <button type="button" class="aa-opt-btn" data-val="Fair">Fair</button>
                                    <button type="button" class="aa-opt-btn" data-val="Poor">Poor</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Air Bags Deploy</div>
                                <div class="aa-btn-grid" data-field="aa_airbag">
                                    <button type="button" class="aa-opt-btn" data-val="Yes">Yes</button>
                                    <button type="button" class="aa-opt-btn" data-val="No">No</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Lose Consciousness</div>
                                <div class="aa-btn-grid" data-field="aa_loc">
                                    <button type="button" class="aa-opt-btn" data-val="Yes">Yes</button>
                                    <button type="button" class="aa-opt-btn" data-val="No">No</button>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Accident Notes</label>
                                <textarea id="aa_accident_notes" class="form-control" rows="2" placeholder="Additional accident details…" onkeyup="updateAutoAccidentNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Section 2: Post Accident Treatment ─────────────── -->
                        <div class="soap-section">
                            <div class="section-head" style="display:flex; justify-content:space-between; align-items:center;">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#dbeafe;color:#1d4ed8;">🏥</span>
                                    <div class="soap-info">
                                        <strong>Post Accident Treatment</strong>
                                        <span class="soap-subtitle">Disposition · Timeline · Treatment History · Visit Reason</span>
                                    </div>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                                <label style="margin:0; min-width:200px;">Date Post Accident Treatment Began</label>
                                <input type="date" id="aa_treatment_began_date" onchange="updateAutoAccidentNote()">
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Where Did You Go After Accident</div>
                                <div class="aa-btn-grid" data-field="aa_went_to">
                                    <button type="button" class="aa-opt-btn" data-val="Home">Home</button>
                                    <button type="button" class="aa-opt-btn" data-val="Hospital">Hospital</button>
                                    <button type="button" class="aa-opt-btn" data-val="Work">Work</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">When Did You Go</div>
                                <div class="aa-btn-grid" data-field="aa_when_went">
                                    <button type="button" class="aa-opt-btn" data-val="Immediately After">Immediately After</button>
                                    <button type="button" class="aa-opt-btn" data-val="Several Hours After">Several Hours After</button>
                                    <button type="button" class="aa-opt-btn" data-val="One Day After">One Day After</button>
                                    <button type="button" class="aa-opt-btn" data-val="Several Days After">Several Days After</button>
                                    <button type="button" class="aa-opt-btn" data-val="Several Weeks After">Several Weeks After</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">How Did You Get There</div>
                                <div class="aa-btn-grid" data-field="aa_transport">
                                    <button type="button" class="aa-opt-btn" data-val="Ambulance">Ambulance</button>
                                    <button type="button" class="aa-opt-btn" data-val="Drove Self">Drove Self</button>
                                    <button type="button" class="aa-opt-btn" data-val="Family Member">Family Member</button>
                                    <button type="button" class="aa-opt-btn" data-val="Friend">Friend</button>
                                    <button type="button" class="aa-opt-btn" data-val="Air Transportation">Air Transportation</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Condition Since Accident</div>
                                <div class="aa-btn-grid" data-field="aa_condition_since">
                                    <button type="button" class="aa-opt-btn" data-val="No Change">No Change</button>
                                    <button type="button" class="aa-opt-btn" data-val="Slight Improvement">Slight Improvement</button>
                                    <button type="button" class="aa-opt-btn" data-val="Significant Improvement">Significant Improvement</button>
                                    <button type="button" class="aa-opt-btn" data-val="Got Worse">Got Worse</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Treatment Since Accident</div>
                                <div class="aa-btn-grid" data-field="aa_treatment_since">
                                    <button type="button" class="aa-opt-btn" data-val="Medical Doctor">Medical Doctor</button>
                                    <button type="button" class="aa-opt-btn" data-val="Physical Therapist">Physical Therapist</button>
                                    <button type="button" class="aa-opt-btn" data-val="Chiropractor">Chiropractor</button>
                                </div>
                                <input type="text" id="aa_treatment_since_other" placeholder="Other…" style="margin-top:6px;" onkeyup="updateAutoAccidentNote()">
                            </div>

                            <div class="cell" style="border-right:none; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
                                <label style="margin:0; min-width:140px;">Date First Visited</label>
                                <input type="date" id="aa_first_visit_date" onchange="updateAutoAccidentNote()">
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Reason for Visit</div>
                                <div class="aa-btn-grid" data-field="aa_visit_reason" data-multi="true">
                                    <button type="button" class="aa-opt-btn" data-val="Consult">Consult</button>
                                    <button type="button" class="aa-opt-btn" data-val="Followup">Followup</button>
                                    <button type="button" class="aa-opt-btn" data-val="Consult and Medication">Consult and Medication</button>
                                    <button type="button" class="aa-opt-btn" data-val="Surgical Consult">Surgical Consult</button>
                                    <button type="button" class="aa-opt-btn" data-val="Injections">Injections</button>
                                    <button type="button" class="aa-opt-btn" data-val="Orthopedic Consult">Orthopedic Consult</button>
                                    <button type="button" class="aa-opt-btn" data-val="Neurological Consult">Neurological Consult</button>
                                    <button type="button" class="aa-opt-btn" data-val="Chiropractic Care">Chiropractic Care</button>
                                    <button type="button" class="aa-opt-btn" data-val="Physical Therapy">Physical Therapy</button>
                                </div>
                                <input type="text" id="aa_visit_reason_other" placeholder="Other…" style="margin-top:6px;" onkeyup="updateAutoAccidentNote()">
                            </div>

                            <!-- Injury quick-tags carried over from the original Auto Accident widget -->
                            <div class="aa-field-row">
                                <div class="aa-field-label">Reported Injuries</div>
                                <div class="qtags" id="qtags-auto-accident">
                                    <span class="qtag" onclick="aaInjuryTag('Neck Pain')">+ neck pain</span>
                                    <span class="qtag" onclick="aaInjuryTag('Mid-Back Pain')">+ mid-back pain</span>
                                    <span class="qtag" onclick="aaInjuryTag('Low Back Pain')">+ low back pain</span>
                                    <span class="qtag" onclick="aaInjuryTag('Headaches')">+ headaches</span>
                                    <span class="qtag" onclick="aaInjuryTag('Shoulder Pain')">+ shoulder pain</span>
                                    <span class="qtag" onclick="aaInjuryTag('Extremity Symptoms')">+ extremity symptoms</span>
                                    <span class="qtag" onclick="aaInjuryTag('Muscle Stiffness')">+ muscle stiffness</span>
                                    <span class="qtag" onclick="aaInjuryTag('Reduced Mobility')">+ reduced mobility</span>
                                    <span class="qtag" onclick="aaInjuryTag('Dizziness')">+ dizziness</span>
                                    <span class="qtag" onclick="aaClearInjuries()" style="background:#fef2f2;color:#b91c1c;border-color:#fecaca;">clear injuries</span>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Treatment Notes</label>
                                <textarea id="aa_treatment_notes" class="form-control" rows="2" placeholder="Additional treatment notes…" onkeyup="updateAutoAccidentNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Live preview of the structured note that will be saved ── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#f3f0fa;color:#7c3aed;">👁</span>
                                    <div class="soap-info">
                                        <strong>Note Preview</strong>
                                        <span class="soap-subtitle">This is what gets saved to the encounter on Save</span>
                                    </div>
                                </div>
                            </div>
                            <div class="cell" style="border-right:none;">
                                <textarea id="aaNotePreview" class="form-control" rows="10" readonly style="background:#faf9ff;"></textarea>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <!-- ══════════════════════════════ /ACCIDENT FORM ══════════════════════════════ -->

            <!-- ══════════════════════════════════════════════════════════════
                 RE-EXAM FORM — replaces Subjective/Objective/Assessment (and
                 the Ambient Scribe bar) when "Re-Exam" is selected from
                 Templates. Plan stays visible and reused as-is for updated
                 goals/treatment plan, per the request. Like the Accident Form,
                 this is not prefilled paragraph text — it's structured inputs
                 that get serialized into the subjective/objective/assessment
                 fields on save (see buildReexamNote() / prepareReexamSubmit()).
                 ══════════════════════════════════════════════════════════════ -->
            <div class="container" id="reexamFormWrapper" style="display:none;">
                <div class="form-group">
                    <div class="soap-wrapper">

                        <!-- ── Section 1: Progress Tracking ─────────────────── -->
                        <div class="soap-section">
                            <div class="section-head" style="display:flex; justify-content:space-between; align-items:center;">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#dbeafe;color:#1d4ed8;">📈</span>
                                    <div class="soap-info">
                                        <strong>Progress Tracking</strong>
                                        <span class="soap-subtitle">Condition Since Last Visit · Compliance · New Issues</span>
                                    </div>
                                </div>
                                <button class="ai-btn" type="button" onclick="reexamClearAll()" style="background:#fff;color:#b91c1c;border:1px solid #fecaca;">Clear Form</button>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Overall Progress Since Last Visit</div>
                                <div class="aa-btn-grid" data-field="rx_progress">
                                    <button type="button" class="aa-opt-btn" data-val="Significant Improvement">Significant Improvement</button>
                                    <button type="button" class="aa-opt-btn" data-val="Mild Improvement">Mild Improvement</button>
                                    <button type="button" class="aa-opt-btn" data-val="No Change">No Change</button>
                                    <button type="button" class="aa-opt-btn" data-val="Worsened">Worsened</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Compliance with Home Care / HEP</div>
                                <div class="aa-btn-grid" data-field="rx_compliance">
                                    <button type="button" class="aa-opt-btn" data-val="Fully Compliant">Fully Compliant</button>
                                    <button type="button" class="aa-opt-btn" data-val="Partially Compliant">Partially Compliant</button>
                                    <button type="button" class="aa-opt-btn" data-val="Non-Compliant">Non-Compliant</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">New Injury / Accident / Change in Health Status Since Last Visit</div>
                                <div class="aa-btn-grid" data-field="rx_new_issue">
                                    <button type="button" class="aa-opt-btn" data-val="None Reported">None Reported</button>
                                    <button type="button" class="aa-opt-btn" data-val="New Injury Reported">New Injury Reported</button>
                                    <button type="button" class="aa-opt-btn" data-val="New Symptom Reported">New Symptom Reported</button>
                                    <button type="button" class="aa-opt-btn" data-val="Other Health Change Reported">Other Health Change Reported</button>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Progress Notes</label>
                                <textarea id="rx_progress_notes" class="form-control" rows="2" placeholder="Additional progress details…" onkeyup="updateReexamNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Section 2: Outcome Measures ─────────────────── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#fef3c7;color:#92400e;">📊</span>
                                    <div class="soap-info">
                                        <strong>Outcome Measures</strong>
                                        <span class="soap-subtitle">Pain Scores · Functional Outcome Tools</span>
                                    </div>
                                </div>
                            </div>

                            <div class="row-strip c3">
                                <div class="cell">
                                    <label>Current Pain (0–10)</label>
                                    <input type="number" min="0" max="10" id="rx_pain_current" onkeyup="updateReexamNote()" onchange="updateReexamNote()">
                                </div>
                                <div class="cell">
                                    <label>% Overall Improvement</label>
                                    <input type="number" min="0" max="100" id="rx_pct_improvement" placeholder="e.g. 60" onkeyup="updateReexamNote()" onchange="updateReexamNote()">
                                </div>
                                <div class="cell">
                                    <label>Outcome Tool Used</label>
                                    <select id="rx_outcome_tool" onchange="updateReexamNote()">
                                        <option value="">—</option>
                                        <option>Oswestry Disability Index</option>
                                        <option>Neck Disability Index</option>
                                        <option>Roland-Morris Questionnaire</option>
                                        <option>DASH</option>
                                        <option>Lower Extremity Functional Scale</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row-strip c2">
                                <div class="cell">
                                    <label>Outcome Tool Score — Initial</label>
                                    <input type="text" id="rx_outcome_score_initial" placeholder="e.g. 42%" onkeyup="updateReexamNote()">
                                </div>
                                <div class="cell">
                                    <label>Outcome Tool Score — Current</label>
                                    <input type="text" id="rx_outcome_score_current" placeholder="e.g. 18%" onkeyup="updateReexamNote()">
                                </div>
                            </div>
                        </div>

                        <!-- ── Section 3: Comparison Columns (Initial vs Current) ── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#ede9fe;color:#5b21b6;">⇄</span>
                                    <div class="soap-info">
                                        <strong>Comparison Columns</strong>
                                        <span class="soap-subtitle">Initial Evaluation vs. Current Findings</span>
                                    </div>
                                </div>
                            </div>

                            <div class="rx-compare-table">
                                <div class="rx-compare-row rx-compare-head">
                                    <div></div>
                                    <div>Initial Evaluation</div>
                                    <div>Current / Today</div>
                                </div>
                                <div class="rx-compare-row">
                                    <div class="rx-compare-label">Pain Level (0–10)</div>
                                    <div><input type="number" min="0" max="10" id="rx_cmp_pain_initial" onkeyup="updateReexamNote()"></div>
                                    <div><input type="number" min="0" max="10" id="rx_cmp_pain_current" onkeyup="updateReexamNote()"></div>
                                </div>
                                <div class="rx-compare-row">
                                    <div class="rx-compare-label">Range of Motion</div>
                                    <div><input type="text" id="rx_cmp_rom_initial" placeholder="e.g. Restricted" onkeyup="updateReexamNote()"></div>
                                    <div><input type="text" id="rx_cmp_rom_current" placeholder="e.g. Improved" onkeyup="updateReexamNote()"></div>
                                </div>
                                <div class="rx-compare-row">
                                    <div class="rx-compare-label">Functional Capacity</div>
                                    <div><input type="text" id="rx_cmp_function_initial" placeholder="e.g. Limited" onkeyup="updateReexamNote()"></div>
                                    <div><input type="text" id="rx_cmp_function_current" placeholder="e.g. Improved" onkeyup="updateReexamNote()"></div>
                                </div>
                                <div class="rx-compare-row">
                                    <div class="rx-compare-label">Orthopedic / Neuro Findings</div>
                                    <div><input type="text" id="rx_cmp_findings_initial" placeholder="e.g. Positive Spurling's" onkeyup="updateReexamNote()"></div>
                                    <div><input type="text" id="rx_cmp_findings_current" placeholder="e.g. Negative" onkeyup="updateReexamNote()"></div>
                                </div>
                            </div>
                        </div>

                        <!-- ── Section 4: Prognosis ─────────────────────────── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#dcfce7;color:#15803d;">🎯</span>
                                    <div class="soap-info">
                                        <strong>Prognosis</strong>
                                        <span class="soap-subtitle">Clinical Outlook · Medical Necessity</span>
                                    </div>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Prognosis</div>
                                <div class="aa-btn-grid" data-field="rx_prognosis">
                                    <button type="button" class="aa-opt-btn" data-val="Excellent">Excellent</button>
                                    <button type="button" class="aa-opt-btn" data-val="Good">Good</button>
                                    <button type="button" class="aa-opt-btn" data-val="Fair">Fair</button>
                                    <button type="button" class="aa-opt-btn" data-val="Guarded">Guarded</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Medical Necessity for Continued Care</div>
                                <div class="aa-btn-grid" data-field="rx_necessity">
                                    <button type="button" class="aa-opt-btn" data-val="Supported — Continue Active Care">Supported — Continue Active Care</button>
                                    <button type="button" class="aa-opt-btn" data-val="Supported — Transition to Maintenance">Supported — Transition to Maintenance</button>
                                    <button type="button" class="aa-opt-btn" data-val="Not Supported — Discharge">Not Supported — Discharge</button>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Prognosis Notes</label>
                                <textarea id="rx_prognosis_notes" class="form-control" rows="2" placeholder="Additional prognosis details…" onkeyup="updateReexamNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Plan — Re-Exam's own lightweight Plan section, distinct
                             from the regular SOAP Plan fieldset (which is hidden while
                             Re-Exam is active — see showReexamForm()/hideReexamForm()).
                             Feeds into the same structured note as the rest of Re-Exam's
                             fields (see buildReexamNote()) rather than the real `plan`
                             field, so updated goals/treatment plan show up directly in
                             the Re-Exam Summary itself. ── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#ede9fe;color:#5b21b6;">📝</span>
                                    <div class="soap-info">
                                        <strong>Plan</strong>
                                        <span class="soap-subtitle">Updated Treatment Goals · Frequency · Next Steps</span>
                                    </div>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Updated Treatment Goals</div>
                                <div class="aa-btn-grid" data-field="rx_plan_goals" data-multi="true">
                                    <button type="button" class="aa-opt-btn" data-val="Pain Reduction">Pain Reduction</button>
                                    <button type="button" class="aa-opt-btn" data-val="Increase ROM">Increase ROM</button>
                                    <button type="button" class="aa-opt-btn" data-val="Improve Function">Improve Function</button>
                                    <button type="button" class="aa-opt-btn" data-val="Reduce Muscle Spasm">Reduce Muscle Spasm</button>
                                    <button type="button" class="aa-opt-btn" data-val="Improve Posture">Improve Posture</button>
                                    <button type="button" class="aa-opt-btn" data-val="Increase Strength">Increase Strength</button>
                                    <button type="button" class="aa-opt-btn" data-val="Improve ADLs">Improve ADLs</button>
                                    <button type="button" class="aa-opt-btn" data-val="Return To Work">Return To Work</button>
                                    <button type="button" class="aa-opt-btn" data-val="Prevent Recurrence">Prevent Recurrence</button>
                                    <button type="button" class="aa-opt-btn" data-val="Wellness Maintenance">Wellness Maintenance</button>
                                    <button type="button" class="aa-opt-btn" data-val="Transition to Maintenance">Transition to Maintenance</button>
                                    <button type="button" class="aa-opt-btn" data-val="Discharge Planning">Discharge Planning</button>
                                </div>
                            </div>

                            <div class="row-strip c3">
                                <div class="cell">
                                    <label>Frequency</label>
                                    <select id="rx_plan_frequency" onchange="updateReexamNote()">
                                        <option value="">—</option>
                                        <option>3× / week</option>
                                        <option>2× / week</option>
                                        <option>1× / week</option>
                                        <option>Biweekly</option>
                                        <option>Monthly</option>
                                        <option>PRN</option>
                                    </select>
                                </div>
                                <div class="cell">
                                    <label>Duration</label>
                                    <select id="rx_plan_duration" onchange="updateReexamNote()">
                                        <option value="">—</option>
                                        <option>2 weeks</option>
                                        <option>4 weeks</option>
                                        <option>6 weeks</option>
                                        <option>8 weeks</option>
                                        <option>12 weeks</option>
                                        <option>Ongoing</option>
                                    </select>
                                </div>
                                <div class="cell">
                                    <label>Next Visit Plan</label>
                                    <select id="rx_plan_next_visit" onchange="updateReexamNote()">
                                        <option value="">—</option>
                                        <option>Continue current plan</option>
                                        <option>Re-evaluate progress</option>
                                        <option>Advance to corrective phase</option>
                                        <option>Taper frequency</option>
                                        <option>Discharge planning</option>
                                        <option>Refer out</option>
                                    </select>
                                </div>
                            </div>

                            <!-- ── Corrective Exercises — same exercise library as the regular
                                 Plan section, with rx_-prefixed IDs so this can coexist with
                                 (rather than collide with) the original picker, which is
                                 hidden but still present in the DOM while Re-Exam is active. ── -->
                            <div class="chip-group2" style="margin-top:10px;">
                                <span class="chip-group-label">Corrective Exercises</span>
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:8px;">
                                    <!-- Exercise picker -->
                                    <div>
                                        <div style="position:relative;margin-bottom:8px;">
                                            <input type="text" id="rx_exerciseSearch" placeholder="Search exercises…" oninput="rxFilterExercises(this.value)"
                                                style="width:100%;padding:7px 12px 7px 30px;border:1px solid #e2dff0;border-radius:8px;font-size:0.8rem;color:#1a1a2e;background:#fff;">
                                            <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#aaa;font-size:13px;">🔍</span>
                                        </div>
                                        <div id="rx_exerciseList" style="border:1px solid #e8e4f4;border-radius:10px;overflow:hidden;max-height:220px;overflow-y:auto;">
                                            <label class="ex-item"><input type="checkbox" value="Cervical Retraction|2 sets × 10 reps" onchange="rxSyncExercises()"> <span class="ex-name">Cervical Retraction</span><span class="ex-sub">Deep neck flexor strengthening</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Scapular Retraction|2 sets × 15 reps" onchange="rxSyncExercises()"> <span class="ex-name">Scapular Retraction</span><span class="ex-sub">Postural correction</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Cat-Camel|2 sets × 10 reps" onchange="rxSyncExercises()"> <span class="ex-name">Cat-Camel</span><span class="ex-sub">Spinal mobility</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Child's Pose|Hold 30 sec × 3" onchange="rxSyncExercises()"> <span class="ex-name">Child's Pose</span><span class="ex-sub">Lumbar mobility</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Bird Dog|3 sets × 10 reps/side" onchange="rxSyncExercises()"> <span class="ex-name">Bird Dog</span><span class="ex-sub">Core stabilization</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Dead Bug|3 sets × 8 reps/side" onchange="rxSyncExercises()"> <span class="ex-name">Dead Bug</span><span class="ex-sub">Core / spinal control</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Prone Cobra|2 sets × 10 reps" onchange="rxSyncExercises()"> <span class="ex-name">Prone Cobra</span><span class="ex-sub">Extension strengthening</span></label>
                                            <label class="ex-item"><input type="checkbox" value="McKenzie Press-Up|3 sets × 10 reps" onchange="rxSyncExercises()"> <span class="ex-name">McKenzie Press-Up</span><span class="ex-sub">Disc centralization</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Chin Tuck|3 sets × 10 reps" onchange="rxSyncExercises()"> <span class="ex-name">Chin Tuck</span><span class="ex-sub">Cervical alignment</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Hip Flexor Stretch|Hold 30 sec × 2/side" onchange="rxSyncExercises()"> <span class="ex-name">Hip Flexor Stretch</span><span class="ex-sub">Lumbopelvic balance</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Piriformis Stretch|Hold 30 sec × 2/side" onchange="rxSyncExercises()"> <span class="ex-name">Piriformis Stretch</span><span class="ex-sub">SI / hip mobility</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Clamshell|3 sets × 15 reps/side" onchange="rxSyncExercises()"> <span class="ex-name">Clamshell</span><span class="ex-sub">Hip abductor strengthening</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Wall Angel|2 sets × 10 reps" onchange="rxSyncExercises()"> <span class="ex-name">Wall Angel</span><span class="ex-sub">Thoracic mobility</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Thoracic Extension|Hold 30 sec × 3" onchange="rxSyncExercises()"> <span class="ex-name">Thoracic Extension</span><span class="ex-sub">Over foam roller</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Pelvic Tilt|3 sets × 15 reps" onchange="rxSyncExercises()"> <span class="ex-name">Pelvic Tilt</span><span class="ex-sub">Lumbar stabilization</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Plank|3 × 20–30 sec" onchange="rxSyncExercises()"> <span class="ex-name">Plank</span><span class="ex-sub">Core endurance</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Glute Bridge|3 sets × 15 reps" onchange="rxSyncExercises()"> <span class="ex-name">Glute Bridge</span><span class="ex-sub">Posterior chain activation</span></label>
                                            <label class="ex-item"><input type="checkbox" value="Side-Lying Rotation|10 reps/side" onchange="rxSyncExercises()"> <span class="ex-name">Side-Lying Rotation</span><span class="ex-sub">Thoracic mobility</span></label>
                                        </div>
                                    </div>
                                    <!-- Selected exercises -->
                                    <div>
                                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                            <span style="font-size:0.62rem;font-weight:700;letter-spacing:0.11em;text-transform:uppercase;color:#9994b0;">Selected Exercises</span>
                                            <button type="button" onclick="rxClearExercises()" style="font-size:0.7rem;color:#7c3aed;background:none;border:none;padding:0;cursor:pointer;">Clear All</button>
                                        </div>
                                        <div id="rx_selectedExercises" style="min-height:60px;">
                                            <p id="rx_noExMsg" style="font-size:0.75rem;color:#ccc;font-style:italic;margin:8px 0;">No exercises selected</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Plan Notes</label>
                                <textarea id="rx_plan_notes" class="form-control" rows="3" placeholder="Additional updated treatment plan details…" onkeyup="updateReexamNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Live preview of the structured note that will be saved ── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#f3f0fa;color:#7c3aed;">👁</span>
                                    <div class="soap-info">
                                        <strong>Note Preview</strong>
                                        <span class="soap-subtitle">This is what gets saved to the encounter on Save</span>
                                    </div>
                                </div>
                            </div>
                            <div class="cell" style="border-right:none;">
                                <textarea id="rxNotePreview" class="form-control" rows="10" readonly style="background:#faf9ff;"></textarea>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <!-- ══════════════════════════════ /RE-EXAM FORM ══════════════════════════════ -->

            <!-- ══════════════════════════════════════════════════════════════
                 DIAGNOSIS SUMMARY FORM — replaces the entire SOAP UI when
                 "Diagnosis Summary" is selected. Structured pickers compose
                 into a flowing paragraph (see buildDiagnosisNote()), written
                 into the subjective field on save, same pattern as Accident/
                 Re-Exam/Discharge.
                 ══════════════════════════════════════════════════════════════ -->
            <div class="container" id="diagnosisFormWrapper" style="display:none;">
                <div class="form-group">
                    <div class="soap-wrapper">

                        <div class="soap-section">
                            <div class="section-head" style="display:flex; justify-content:space-between; align-items:center;">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#ede9fe;color:#5b21b6;">🩺</span>
                                    <div class="soap-info">
                                        <strong>Diagnosis Summary</strong>
                                        <span class="soap-subtitle">Clinical Impression · Diagnoses · Severity · Supporting Findings</span>
                                    </div>
                                </div>
                                <button class="ai-btn" type="button" onclick="dxClearAll()" style="background:#fff;color:#b91c1c;border:1px solid #fecaca;">Clear Form</button>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Primary Region(s) Involved</div>
                                <div class="aa-btn-grid" data-field="dx_region" data-multi="true">
                                    <button type="button" class="aa-opt-btn" data-val="Cervical Spine">Cervical Spine</button>
                                    <button type="button" class="aa-opt-btn" data-val="Thoracic Spine">Thoracic Spine</button>
                                    <button type="button" class="aa-opt-btn" data-val="Lumbar Spine">Lumbar Spine</button>
                                    <button type="button" class="aa-opt-btn" data-val="Sacrum / Pelvis">Sacrum / Pelvis</button>
                                    <button type="button" class="aa-opt-btn" data-val="Shoulder">Shoulder</button>
                                    <button type="button" class="aa-opt-btn" data-val="Hip / Knee">Hip / Knee</button>
                                    <button type="button" class="aa-opt-btn" data-val="Extremity">Extremity</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Primary Diagnosis Category</div>
                                <div class="aa-btn-grid" data-field="dx_category">
                                    <button type="button" class="aa-opt-btn" data-val="Sprain / Strain Injury">Sprain / Strain Injury</button>
                                    <button type="button" class="aa-opt-btn" data-val="Segmental / Vertebral Dysfunction">Segmental / Vertebral Dysfunction</button>
                                    <button type="button" class="aa-opt-btn" data-val="Disc Pathology">Disc Pathology</button>
                                    <button type="button" class="aa-opt-btn" data-val="Radiculopathy">Radiculopathy</button>
                                    <button type="button" class="aa-opt-btn" data-val="Myofascial Pain Syndrome">Myofascial Pain Syndrome</button>
                                    <button type="button" class="aa-opt-btn" data-val="Whiplash-Associated Disorder">Whiplash-Associated Disorder</button>
                                    <button type="button" class="aa-opt-btn" data-val="Osteoarthritis">Osteoarthritis</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Severity</div>
                                <div class="aa-btn-grid" data-field="dx_severity">
                                    <button type="button" class="aa-opt-btn" data-val="Mild">Mild</button>
                                    <button type="button" class="aa-opt-btn" data-val="Moderate">Moderate</button>
                                    <button type="button" class="aa-opt-btn" data-val="Severe">Severe</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Chronicity</div>
                                <div class="aa-btn-grid" data-field="dx_chronicity">
                                    <button type="button" class="aa-opt-btn" data-val="Acute">Acute</button>
                                    <button type="button" class="aa-opt-btn" data-val="Subacute">Subacute</button>
                                    <button type="button" class="aa-opt-btn" data-val="Chronic">Chronic</button>
                                    <button type="button" class="aa-opt-btn" data-val="Acute on Chronic">Acute on Chronic</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Supporting Clinical Findings</div>
                                <div class="aa-btn-grid" data-field="dx_findings" data-multi="true">
                                    <button type="button" class="aa-opt-btn" data-val="Positive Orthopedic Testing">Positive Orthopedic Testing</button>
                                    <button type="button" class="aa-opt-btn" data-val="Restricted Range of Motion">Restricted Range of Motion</button>
                                    <button type="button" class="aa-opt-btn" data-val="Palpable Muscle Spasm / Guarding">Palpable Muscle Spasm / Guarding</button>
                                    <button type="button" class="aa-opt-btn" data-val="Imaging-Confirmed Pathology">Imaging-Confirmed Pathology</button>
                                    <button type="button" class="aa-opt-btn" data-val="Neurological Deficit on Exam">Neurological Deficit on Exam</button>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Diagnosis Narrative</label>
                                <textarea id="dx_narrative" class="form-control" rows="3" placeholder="Additional diagnostic reasoning or detail to fold into the summary…" onkeyup="updateDiagnosisNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Live preview of the generated paragraph ── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#f3f0fa;color:#7c3aed;">👁</span>
                                    <div class="soap-info">
                                        <strong>Note Preview</strong>
                                        <span class="soap-subtitle">This is what gets saved to the encounter on Save</span>
                                    </div>
                                </div>
                            </div>
                            <div class="cell" style="border-right:none;">
                                <textarea id="dxNotePreview" class="form-control" rows="6" readonly style="background:#faf9ff;"></textarea>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <!-- ══════════════════════════════ /DIAGNOSIS SUMMARY FORM ══════════════════════════════ -->

            <!-- ══════════════════════════════════════════════════════════════
                 DISCHARGE SUMMARY FORM — replaces the entire SOAP UI when
                 "Discharge Summary" is selected. Collapses the note into
                 Treatment Summary, Outcomes, Final Findings, and Discharge
                 Narrative — composed as flowing paragraphs (see
                 buildDischargeNote()), not labeled lines.
                 ══════════════════════════════════════════════════════════════ -->
            <div class="container" id="dischargeFormWrapper" style="display:none;">
                <div class="form-group">
                    <div class="soap-wrapper">

                        <!-- ── Treatment Summary ─────────────────────────── -->
                        <div class="soap-section">
                            <div class="section-head" style="display:flex; justify-content:space-between; align-items:center;">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#dbeafe;color:#1d4ed8;">📋</span>
                                    <div class="soap-info">
                                        <strong>Treatment Summary</strong>
                                        <span class="soap-subtitle">Course of Care · Visit Count · Modalities Used</span>
                                    </div>
                                </div>
                                <button class="ai-btn" type="button" onclick="dcClearAll()" style="background:#fff;color:#b91c1c;border:1px solid #fecaca;">Clear Form</button>
                            </div>

                            <div class="row-strip c3">
                                <div class="cell">
                                    <label>Total Visits</label>
                                    <input type="number" min="0" id="dc_total_visits" onkeyup="updateDischargeNote()" onchange="updateDischargeNote()">
                                </div>
                                <div class="cell">
                                    <label>Date of Initial Visit</label>
                                    <input type="date" id="dc_initial_date" onchange="updateDischargeNote()">
                                </div>
                                <div class="cell">
                                    <label>Date of Discharge</label>
                                    <input type="date" id="dc_discharge_date" onchange="updateDischargeNote()">
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Modalities / Treatments Provided</div>
                                <div class="aa-btn-grid" data-field="dc_modalities" data-multi="true">
                                    <button type="button" class="aa-opt-btn" data-val="Chiropractic Adjustment">Chiropractic Adjustment</button>
                                    <button type="button" class="aa-opt-btn" data-val="Soft Tissue Mobilization">Soft Tissue Mobilization</button>
                                    <button type="button" class="aa-opt-btn" data-val="Therapeutic Exercise">Therapeutic Exercise</button>
                                    <button type="button" class="aa-opt-btn" data-val="Electrical Stimulation">Electrical Stimulation</button>
                                    <button type="button" class="aa-opt-btn" data-val="Ultrasound Therapy">Ultrasound Therapy</button>
                                    <button type="button" class="aa-opt-btn" data-val="Traction">Traction</button>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Treatment Summary Notes</label>
                                <textarea id="dc_treatment_notes" class="form-control" rows="2" placeholder="Additional detail on the course of care…" onkeyup="updateDischargeNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Outcomes ───────────────────────────────────── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#fef3c7;color:#92400e;">📊</span>
                                    <div class="soap-info">
                                        <strong>Outcomes</strong>
                                        <span class="soap-subtitle">Pain &amp; Function Change · Goals Met</span>
                                    </div>
                                </div>
                            </div>

                            <div class="row-strip c2">
                                <div class="cell">
                                    <label>Pain at Start of Care (0–10)</label>
                                    <input type="number" min="0" max="10" id="dc_pain_initial" onkeyup="updateDischargeNote()">
                                </div>
                                <div class="cell">
                                    <label>Pain at Discharge (0–10)</label>
                                    <input type="number" min="0" max="10" id="dc_pain_discharge" onkeyup="updateDischargeNote()">
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Functional Outcome</div>
                                <div class="aa-btn-grid" data-field="dc_outcome">
                                    <button type="button" class="aa-opt-btn" data-val="Full Resolution of Symptoms">Full Resolution of Symptoms</button>
                                    <button type="button" class="aa-opt-btn" data-val="Significant Improvement">Significant Improvement</button>
                                    <button type="button" class="aa-opt-btn" data-val="Moderate Improvement">Moderate Improvement</button>
                                    <button type="button" class="aa-opt-btn" data-val="Reached Maximum Therapeutic Benefit">Reached Maximum Therapeutic Benefit</button>
                                    <button type="button" class="aa-opt-btn" data-val="Plateaued / Minimal Improvement">Plateaued / Minimal Improvement</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Treatment Goals Met</div>
                                <div class="aa-btn-grid" data-field="dc_goals_met">
                                    <button type="button" class="aa-opt-btn" data-val="All Goals Met">All Goals Met</button>
                                    <button type="button" class="aa-opt-btn" data-val="Most Goals Met">Most Goals Met</button>
                                    <button type="button" class="aa-opt-btn" data-val="Partial Goals Met">Partial Goals Met</button>
                                    <button type="button" class="aa-opt-btn" data-val="Goals Not Met">Goals Not Met</button>
                                </div>
                            </div>
                        </div>

                        <!-- ── Final Findings ─────────────────────────────── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#fee2e2;color:#b91c1c;">🔎</span>
                                    <div class="soap-info">
                                        <strong>Final Findings</strong>
                                        <span class="soap-subtitle">Exam Status at Discharge</span>
                                    </div>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Range of Motion at Discharge</div>
                                <div class="aa-btn-grid" data-field="dc_rom">
                                    <button type="button" class="aa-opt-btn" data-val="Full / Within Normal Limits">Full / Within Normal Limits</button>
                                    <button type="button" class="aa-opt-btn" data-val="Mildly Restricted">Mildly Restricted</button>
                                    <button type="button" class="aa-opt-btn" data-val="Moderately Restricted">Moderately Restricted</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Residual Findings</div>
                                <div class="aa-btn-grid" data-field="dc_residual">
                                    <button type="button" class="aa-opt-btn" data-val="No Residual Findings">No Residual Findings</button>
                                    <button type="button" class="aa-opt-btn" data-val="Mild Residual Stiffness">Mild Residual Stiffness</button>
                                    <button type="button" class="aa-opt-btn" data-val="Residual Tenderness on Palpation">Residual Tenderness on Palpation</button>
                                    <button type="button" class="aa-opt-btn" data-val="Residual Functional Limitation">Residual Functional Limitation</button>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Final Findings Notes</label>
                                <textarea id="dc_findings_notes" class="form-control" rows="2" placeholder="Additional exam detail at discharge…" onkeyup="updateDischargeNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Discharge Narrative ─────────────────────────── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#dcfce7;color:#15803d;">✅</span>
                                    <div class="soap-info">
                                        <strong>Discharge Narrative</strong>
                                        <span class="soap-subtitle">Reason for Discharge · Recommendations · Follow-Up</span>
                                    </div>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Reason for Discharge</div>
                                <div class="aa-btn-grid" data-field="dc_reason">
                                    <button type="button" class="aa-opt-btn" data-val="Resolved — Treatment Goals Achieved">Resolved — Treatment Goals Achieved</button>
                                    <button type="button" class="aa-opt-btn" data-val="Reached Maximum Medical Improvement">Reached Maximum Medical Improvement</button>
                                    <button type="button" class="aa-opt-btn" data-val="Transitioning to Maintenance Care">Transitioning to Maintenance Care</button>
                                    <button type="button" class="aa-opt-btn" data-val="Patient Request">Patient Request</button>
                                    <button type="button" class="aa-opt-btn" data-val="Referred to Another Provider">Referred to Another Provider</button>
                                </div>
                            </div>

                            <div class="aa-field-row">
                                <div class="aa-field-label">Recommendations</div>
                                <div class="aa-btn-grid" data-field="dc_recommend" data-multi="true">
                                    <button type="button" class="aa-opt-btn" data-val="Continue Home Exercise Program">Continue Home Exercise Program</button>
                                    <button type="button" class="aa-opt-btn" data-val="Periodic Maintenance Visits">Periodic Maintenance Visits</button>
                                    <button type="button" class="aa-opt-btn" data-val="Return PRN if Symptoms Recur">Return PRN if Symptoms Recur</button>
                                    <button type="button" class="aa-opt-btn" data-val="Follow Up with PCP">Follow Up with PCP</button>
                                    <button type="button" class="aa-opt-btn" data-val="Continue Independent Stretching/Strengthening">Continue Independent Stretching/Strengthening</button>
                                </div>
                            </div>

                            <div class="cell" style="border-right:none;">
                                <label>Discharge Narrative Notes</label>
                                <textarea id="dc_narrative_notes" class="form-control" rows="3" placeholder="Additional closing detail to fold into the discharge narrative…" onkeyup="updateDischargeNote()"></textarea>
                            </div>
                        </div>

                        <!-- ── Live preview of the generated paragraphs ── -->
                        <div class="soap-section">
                            <div class="section-head">
                                <div class="sh-left" style="display:flex; align-items:center;">
                                    <span class="soap-glyph" style="background:#f3f0fa;color:#7c3aed;">👁</span>
                                    <div class="soap-info">
                                        <strong>Note Preview</strong>
                                        <span class="soap-subtitle">This is what gets saved to the encounter on Save</span>
                                    </div>
                                </div>
                            </div>
                            <div class="cell" style="border-right:none;">
                                <textarea id="dcNotePreview" class="form-control" rows="10" readonly style="background:#faf9ff;"></textarea>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
            <!-- ══════════════════════════════ /DISCHARGE SUMMARY FORM ══════════════════════════════ -->

            <div id="soapFormBody">
            <!-- Subjective / Objective / Assessment — hidden together in Re-Exam mode,
                 since that mode replaces them with Progress Tracking / Outcome Measures /
                 Comparison Columns / Prognosis instead. Plan (below, outside this wrapper)
                 stays visible in both modes — Re-Exam reuses it for updated goals/treatment. -->
            <div id="soapSOAWrapper">
            <fieldset>
                <div class="container">
                    <div class="form-group">
                        <div class="soap-wrapper">
                            <div class="soap-section">
                                <div class="section-head" style="display: flex; justify-content: space-between; align-items: center;">
                                    <div class="sh-left" style="display: flex; align-items: center;">
                                        <span class="soap-glyph glyph-s">S</span>
                                        <div class="soap-info">
                                            <strong>Subjective</strong>
                                            <span class="soap-subtitle" 
                                                  data-default="Patient History · Chief Complaint · HPI"
                                                  data-psychiatry="Chief Complaint · Mood · Psychiatric History"
                                                  data-psychology="Presenting Problem · History · Goals"
                                                  data-urgentcare="Chief Complaint · History of Present Illness">Patient History · Chief Complaint · HPI</span>
                                        </div>
                                    </div>
                                    <button class="ai-btn" type="button" onclick="generateSOAP()">✦ AI Generate</button>
                                </div>

                                <div class="row-strip c5">
                                    <!-- Chief Complaint — all specialties, options differ -->
                                    <div class="cell">
                                        <label class="sp-label" data-default="Chief Complaint" data-psychiatry="Chief Complaint / Reason for Visit" data-psychology="Presenting Problem">Chief Complaint</label>
                                        <select name="chief_complaint" id="chief_complaint" onchange="updateSubjectiveNote()">
                                            <!-- Chiro / MSK options (default) -->
                                            <optgroup label="MSK / Chiro" class="sp-opts" data-specialties="chiro ortho pmr pain urgentcare">
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
                                            </optgroup>
                                            <!-- Primary / Family / Urgent Care -->
                                            <optgroup label="General" class="sp-opts" data-specialties="primarycare familymed internalmed urgentcare other">
                                                <option value="">—</option>
                                                <option>Chest Pain</option>
                                                <option>Shortness of Breath</option>
                                                <option>Abdominal Pain</option>
                                                <option>Fatigue</option>
                                                <option>Fever / Infection</option>
                                                <option>Hypertension Follow-up</option>
                                                <option>Diabetes Follow-up</option>
                                                <option>URI / Cough</option>
                                                <option>Headache</option>
                                                <option>Back / Joint Pain</option>
                                                <option>Preventive / Wellness</option>
                                            </optgroup>
                                            <!-- Neurology -->
                                            <optgroup label="Neurology" class="sp-opts" data-specialties="neurology">
                                                <option value="">—</option>
                                                <option>Headache / Migraine</option>
                                                <option>Seizure</option>
                                                <option>Dizziness / Vertigo</option>
                                                <option>Memory / Cognitive Change</option>
                                                <option>Weakness / Numbness</option>
                                                <option>Tremor</option>
                                                <option>Radiculopathy</option>
                                            </optgroup>
                                            <!-- Psych / Psychology -->
                                            <optgroup label="Behavioral Health" class="sp-opts" data-specialties="psychiatry psychology">
                                                <option value="">—</option>
                                                <option>Depression</option>
                                                <option>Anxiety</option>
                                                <option>Bipolar Disorder</option>
                                                <option>PTSD</option>
                                                <option>OCD</option>
                                                <option>ADHD</option>
                                                <option>Psychosis</option>
                                                <option>Substance Use</option>
                                                <option>Adjustment Disorder</option>
                                                <option>Trauma Processing</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Onset</label>
                                        <select name="onset" id="onset" onchange="updateSubjectiveNote()">
                                            <option value="">—</option>
                                            <option>Acute — &lt;72 hrs</option>
                                            <option>Subacute — 1–4 wks</option>
                                            <option>Chronic — &gt;3 mo</option>
                                            <option>Recurring</option>
                                            <option class="sp-opt" data-specialties="psychiatry psychology">Gradual / Insidious</option>
                                            <option class="sp-opt" data-specialties="psychiatry psychology">Situational</option>
                                        </select>
                                    </div>
                                    <!-- Mechanism — hide for psych/neuro -->
                                    <div class="cell sp-field" data-specialties="chiro ortho pmr pain urgentcare primarycare familymed internalmed other">
                                        <label>Mechanism</label>
                                        <select name="mechanism" id="mechanism" onchange="updateSubjectiveNote()">
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
                                    <!-- Stressor / Precipitant — psych/psychology only -->
                                    <div class="cell sp-field" data-specialties="psychiatry psychology" style="display:none">
                                        <label>Precipitating Stressor</label>
                                        <select name="mechanism" id="mechanism_psych" onchange="updateSubjectiveNote()">
                                            <option value="">—</option>
                                            <option>Relationship / Family</option>
                                            <option>Work / Academic</option>
                                            <option>Grief / Loss</option>
                                            <option>Trauma / Abuse</option>
                                            <option>Financial</option>
                                            <option>Medical Illness</option>
                                            <option>No Identifiable Stressor</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label class="sp-label" data-default="Pain Intensity (0–10)" data-psychiatry="Symptom Severity (0–10)" data-psychology="Distress Level (0–10)">Pain Intensity (0–10)</label>
                                        <select name="pain_intensity" id="pain_intensity" onchange="updateSubjectiveNote()">
                                            <option value="">—</option>
                                            <option value="0">0</option><option value="1">1</option><option value="2">2</option>
                                            <option value="3">3</option><option value="4">4</option><option value="5">5</option>
                                            <option value="6">6</option><option value="7">7</option><option value="8">8</option>
                                            <option value="9">9</option><option value="10">10</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Pattern</label>
                                        <select name="pattern" id="pattern" onchange="updateSubjectiveNote()">
                                            <option value="">—</option>
                                            <option>Constant</option>
                                            <option>Intermittent</option>
                                            <option>Episodic</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>vs. Last Visit</label>
                                        <select name="last_visit" id="last_visit" onchange="updateSubjectiveNote()">
                                            <option value="">—</option>
                                            <option>Better</option>
                                            <option>Same</option>
                                            <option>Worse</option>
                                            <option>First Visit</option>
                                        </select>
                                    </div>
                                    <!-- Primary Region — MSK/neuro only -->
                                    <div class="cell sp-field" data-specialties="chiro ortho pmr pain neurology urgentcare primarycare familymed internalmed other">
                                        <label>Primary Region</label>
                                        <select name="primary_region" id="primary_region" onchange="updateSubjectiveNote()">
                                            <option value="">—</option>
                                            <option>Cervical (C1–C7)</option>
                                            <option>Cervicothoracic Jxn</option>
                                            <option>Thoracic (T1–T12)</option>
                                            <option>Thoracolumbar Jxn</option>
                                            <option>Lumbar (L1–L5)</option>
                                            <option>Lumbosacral Jxn</option>
                                            <option>Sacrum / SI Joints</option>
                                            <option>Pelvis / Coccyx</option>
                                            <option>Shoulder</option>
                                            <option>Hip / Knee</option>
                                            <option>Extremity</option>
                                        </select>
                                    </div>
                                    <!-- Mood / Affect — psych only -->
                                    <div class="cell sp-field" data-specialties="psychiatry psychology" style="display:none">
                                        <label>Current Mood (Patient)</label>
                                        <select name="primary_region" id="mood_patient" onchange="updateSubjectiveNote()">
                                            <option value="">—</option>
                                            <option>Depressed</option>
                                            <option>Anxious</option>
                                            <option>Irritable</option>
                                            <option>Elevated / Euphoric</option>
                                            <option>Euthymic</option>
                                            <option>Labile</option>
                                            <option>Flat / Blunted</option>
                                        </select>
                                    </div>
                                    <!-- Radiation — MSK only -->
                                    <div class="cell sp-field" data-specialties="chiro ortho pmr pain neurology">
                                        <label>Radiation</label>
                                        <select name="radiation" id="radiation" onchange="updateSubjectiveNote()">
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
                                    <!-- Neurological Sx — MSK/neuro only -->
                                    <div class="cell sp-field" data-specialties="chiro ortho pmr pain neurology urgentcare">
                                        <label>Neurological Sx</label>
                                        <select name="neurological_sx" id="neurological_sx" onchange="updateSubjectiveNote()">
                                            <option value="">—</option>
                                            <option>None</option>
                                            <option>Numbness</option>
                                            <option>Tingling / Paresthesia</option>
                                            <option>Weakness</option>
                                            <option>Numbness + Tingling</option>
                                            <option>Drop Foot</option>
                                        </select>
                                    </div>
                                    <!-- Sleep / Function — psych -->
                                    <div class="cell sp-field" data-specialties="psychiatry psychology" style="display:none">
                                        <label>Sleep</label>
                                        <select name="neurological_sx" id="sleep_quality">
                                            <option value="">—</option>
                                            <option>Normal</option>
                                            <option>Insomnia — Onset</option>
                                            <option>Insomnia — Maintenance</option>
                                            <option>Hypersomnia</option>
                                            <option>Fragmented</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="soap-section">
                                    <textarea name="subjective" id="sNote" 
                                        data-placeholder-default="Patient reports… Aggravating factors: ___. Relieving factors: ___. Sleep quality: ___. ADL limitations: ___. Prior treatment history: ___."
                                        data-placeholder-psychiatry="Patient presents with chief complaint of ___. Onset: ___. Duration: ___. Mood: ___. Sleep: ___. Appetite: ___. Suicidal ideation: denies/present. Medication compliance: ___."
                                        data-placeholder-psychology="Client presents with ___. Current stressors: ___. Coping strategies: ___. Therapy goals: ___. Motivation for change: ___."
                                        placeholder="Patient reports… Aggravating factors: ___. Relieving factors: ___. Sleep quality: ___. ADL limitations: ___. Prior treatment history: ___." 
                                        class="form-control" cols="60" rows="6" onkeyup="top.isSoapEdit = true;"><?php echo text($_smarty_tpl->tpl_vars['data']->value->get_subjective());?>
</textarea>
                                    <div class="qtags" id="qtags-subjective">
                                        <!-- MSK qtags -->
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain neurology urgentcare primarycare familymed internalmed other" onclick="qt('sNote','Worse with prolonged sitting')">worse w/ sitting</span>
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain neurology urgentcare primarycare familymed internalmed other" onclick="qt('sNote','Worse with forward flexion')">worse w/ flexion</span>
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain urgentcare primarycare familymed internalmed other" onclick="qt('sNote','Relieved by heat')">relieved by heat</span>
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain urgentcare primarycare familymed internalmed other" onclick="qt('sNote','Relieved by ice')">relieved by ice</span>
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain neurology" onclick="qt('sNote','Morning stiffness lasting ~30 min')">AM stiffness</span>
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain primarycare familymed internalmed other urgentcare" onclick="qt('sNote','Sleep disrupted by pain')">sleep disrupted</span>
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain neurology" onclick="qt('sNote','No bowel/bladder dysfunction')">no B/B dysfx</span>
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain neurology urgentcare primarycare familymed internalmed other" onclick="qt('sNote','Improved since last visit')">improved since last visit</span>
                                        <span class="qtag sp-qtag" data-specialties="chiro ortho pmr pain neurology urgentcare primarycare familymed internalmed other" onclick="qt('sNote','Same as last visit')">same as last visit</span>
                                        <!-- Psych qtags -->
                                        <span class="qtag sp-qtag" data-specialties="psychiatry psychology" style="display:none" onclick="qt('sNote','Patient denies suicidal or homicidal ideation')">denies SI/HI</span>
                                        <span class="qtag sp-qtag" data-specialties="psychiatry psychology" style="display:none" onclick="qt('sNote','Medication compliance reported as good')">med compliant</span>
                                        <span class="qtag sp-qtag" data-specialties="psychiatry psychology" style="display:none" onclick="qt('sNote','Mood has been depressed / low most of the day')">depressed mood</span>
                                        <span class="qtag sp-qtag" data-specialties="psychiatry psychology" style="display:none" onclick="qt('sNote','Anxiety is interfering with daily functioning')">anxiety interfering</span>
                                        <span class="qtag sp-qtag" data-specialties="psychology" style="display:none" onclick="qt('sNote','Client demonstrated insight into presenting concerns')">insight present</span>
                                        <span class="qtag sp-qtag" data-specialties="psychology" style="display:none" onclick="qt('sNote','Client is motivated and engaged in the therapeutic process')">motivated / engaged</span>
                                    </div>
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
                                        <span class="soap-glyph glyph-o">O</span>
                                        <div class="soap-info">
                                            <strong>Objective</strong>
                                            <span class="soap-subtitle"
                                                  data-default="Physical Exam Findings · Lab Results"
                                                  data-psychiatry="Mental Status Exam · Vital Signs"
                                                  data-psychology="Behavioral Observations · Functioning">Physical Exam Findings · Lab Results</span>
                                        </div>
                                    </div>
                                    <button class="ai-btn" type="button" onclick="generateSOAP()">✦ AI Generate</button>
                                </div>
                                <!-- Posture & gait — MSK specialties -->
                                <div class="row-strip c4 sp-field" data-specialties="chiro ortho pmr pain neurology urgentcare primarycare familymed internalmed other">
                                    <div class="cell">
                                        <label>Posture</label>
                                        <select name="posture" id="posture" onchange="updateObjectiveNote()">
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
                                        <select name="gait" id="gait" onchange="updateObjectiveNote()">
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
                                        <select name="palpation" id="palpation" onchange="updateObjectiveNote()">
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
                                        <select name="muscle_tone" id="muscle_tone" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>WNL</option>
                                            <option>Increased — Unilateral R</option>
                                            <option>Increased — Unilateral L</option>
                                            <option>Increased — Bilateral</option>
                                            <option>Decreased</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Mental Status Exam — psych/psychology only -->
                                <div class="row-strip c4 sp-field" data-specialties="psychiatry psychology" style="display:none">
                                    <div class="cell">
                                        <label>Appearance / Behavior</label>
                                        <select name="posture" id="appearance" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>Well-groomed, calm</option>
                                            <option>Disheveled</option>
                                            <option>Agitated / Restless</option>
                                            <option>Psychomotor retardation</option>
                                            <option>Appropriate for age</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Affect</label>
                                        <select name="gait" id="affect" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>Euthymic / Full range</option>
                                            <option>Depressed / Sad</option>
                                            <option>Anxious / Fearful</option>
                                            <option>Flat / Blunted</option>
                                            <option>Labile</option>
                                            <option>Euphoric</option>
                                            <option>Irritable</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Thought Process</label>
                                        <select name="palpation" id="thought_process" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>Logical / Linear</option>
                                            <option>Circumstantial</option>
                                            <option>Tangential</option>
                                            <option>Flight of ideas</option>
                                            <option>Disorganized</option>
                                            <option>Thought blocking</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Insight / Judgment</label>
                                        <select name="muscle_tone" id="insight_judgment" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>Good / Good</option>
                                            <option>Fair / Fair</option>
                                            <option>Poor / Poor</option>
                                            <option>Good / Poor</option>
                                            <option>Limited / Limited</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- ROM — MSK/neuro only — all dropdowns -->
                                <div class="row-strip c5 sp-field" data-specialties="chiro ortho pmr pain neurology urgentcare primarycare familymed internalmed other">
                                    <div class="cell">
                                        <label>Flexion</label>
                                        <select name="flexion" id="flexion" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>WNL (&gt;50°)</option>
                                            <option>Mild loss (40–50°)</option>
                                            <option>Moderate loss (25–39°)</option>
                                            <option>Severe loss (&lt;25°)</option>
                                            <option>Pain at end range</option>
                                            <option>Not tested</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Extension</label>
                                        <select name="extension" id="extension" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>WNL (&gt;60°)</option>
                                            <option>Mild loss (45–60°)</option>
                                            <option>Moderate loss (25–44°)</option>
                                            <option>Severe loss (&lt;25°)</option>
                                            <option>Pain at end range</option>
                                            <option>Not tested</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>R. Lat. Flex.</label>
                                        <select name="right_lat_flex" id="right_lat_flex" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>WNL (&gt;25°)</option>
                                            <option>Mild loss (18–25°)</option>
                                            <option>Moderate loss (10–17°)</option>
                                            <option>Severe loss (&lt;10°)</option>
                                            <option>Pain at end range</option>
                                            <option>Not tested</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>L. Lat. Flex.</label>
                                        <select name="left_lat_flex" id="left_lat_flex" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>WNL (&gt;25°)</option>
                                            <option>Mild loss (18–25°)</option>
                                            <option>Moderate loss (10–17°)</option>
                                            <option>Severe loss (&lt;10°)</option>
                                            <option>Pain at end range</option>
                                            <option>Not tested</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>ROM Quality</label>
                                        <select name="rom_quality" id="rom_quality" onchange="updateObjectiveNote()">
                                            <option value="">—</option>
                                            <option>Smooth / Full</option>
                                            <option>Guarded</option>
                                            <option>With Pain</option>
                                            <option>Restricted</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Ortho tests — MSK/neuro only -->
                                <div class="chip-group sp-field" data-specialties="chiro ortho pmr pain neurology urgentcare primarycare familymed internalmed other">
                                    <span class="chip-group-label">Orthopedic Tests (click = positive)</span>
                                    <div class="chips" id="orthoChips">
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">SLR — R</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">SLR — L</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Kemp's — R</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Kemp's — L</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Valsalva</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Soto Hall</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Spurling — R</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Spurling — L</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Distraction</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Compression</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">FABERE — R</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">FABERE — L</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Gaenslen's — R</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Gaenslen's — L</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Ober — R</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Ober — L</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Yergason — R</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Yergason — L</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Cervical Compression</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Adson's — R</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-red', 'oNote')">Adson's — L</span>
                                        <span class="chip" onclick="toggleChip(this, 'on-green', 'oNote')">All Tests (−)</span>
                                    </div>
                                </div>

                                <!-- Neuro -->
                                <div class="row-strip c3">
                                    <div class="cell">
                                        <label>DTRs</label>
                                        <select name="dtrs" id="dtrs" onchange="updateObjectiveNote()">
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
                                        <select name="sensation" id="sensation" onchange="updateObjectiveNote()">
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
                                        <select name="motor_strength" id="motor_strength" onchange="updateObjectiveNote()">
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
                                <textarea name="objective" id="oNote" placeholder="Additional exam findings, imaging review, segmental motion assessment, soft tissue status…" class="form-control" cols="60" rows="6" onkeyup="top.isSoapEdit = true;"><?php echo text($_smarty_tpl->tpl_vars['data']->value->get_objective());?>
</textarea>
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
                                        <span class="soap-glyph glyph-a">A</span>
                                        <div class="soap-info">
                                            <strong>Assessment</strong>
                                            <span class="soap-subtitle"
                                                  data-default="Diagnosis · Clinical Impression"
                                                  data-psychiatry="Psychiatric Diagnosis · DSM-5 Impression"
                                                  data-psychology="Clinical Formulation · Goals Progress">Diagnosis · Clinical Impression</span>
                                        </div>
                                    </div>
                                    <button class="ai-btn" type="button" onclick="generateSOAP()">✦ AI Generate</button>
                                </div>

                                <!-- Dx fields -->
                                <div class="row-strip c3">
                                    <div class="cell">
                                        <label class="sp-label" data-default="Response to Care" data-psychiatry="Response to Treatment" data-psychology="Progress Toward Goals">Response to Care</label>
                                        <select name="response_to_care" id="response_to_care" onchange="updateAssessmentNote()">
                                            <option value="">—</option>
                                            <option>Improving as Expected</option>
                                            <option>Improving — Slower than Expected</option>
                                            <option>Plateau Noted</option>
                                            <option>Not Responding</option>
                                            <option>Acute — Initial Presentation</option>
                                            <option>Exacerbation of Chronic</option>
                                        </select>
                                    </div>
                                    <!-- Diagnosis field for non-chiro -->
                                    <div class="cell sp-field" data-specialties="primarycare familymed internalmed urgentcare neurology psychiatry psychology pmr ortho pain other" style="display:none">
                                        <label class="sp-label" data-default="Primary Diagnosis" data-psychiatry="DSM-5 Diagnosis" data-psychology="Clinical Impression">Primary Diagnosis</label>
                                        <input type="text" id="primary_diagnosis" placeholder="Diagnosis / ICD-10 code" style="width:100%;padding:6px;border:1px solid #ddd;border-radius:6px;">
                                    </div>
                                    <!-- Prognosis -->
                                    <div class="cell sp-field" data-specialties="primarycare familymed internalmed urgentcare neurology psychiatry psychology pmr ortho pain other" style="display:none">
                                        <label>Prognosis</label>
                                        <select name="prognosis" id="prognosis" onchange="updateAssessmentNote()">
                                            <option value="">—</option>
                                            <option>Good</option>
                                            <option>Fair</option>
                                            <option>Guarded</option>
                                            <option>Poor</option>
                                        </select>
                                    </div>
                                </div>
                                <!-- Spinal level chips — chiro only -->
                                <div id="chip-group" class="chips sp-field" data-specialties="chiro pmr ortho pain">
                                <span style="font-size:0.9rem;color:var(--muted2);letter-spacing:0.1em;align-self:center;margin-right:4px; font-weight:bold;">CX</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">C1</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">C2</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">C3</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">C4</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">C5</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">C6</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">C7</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">CT Jxn</span>
                                <span style="font-size:0.9rem;color:var(--muted2);letter-spacing:0.1em;align-self:center;margin-left:4px;margin-right:4px;font-weight:bold;">TX</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T1</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T2</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T3</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T4</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T5</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T6</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T7</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T8</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T9</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T10</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T11</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">T12</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">TL Jxn</span>
                                <span style="font-size:0.9rem;color:var(--muted2);letter-spacing:0.1em;align-self:center;margin-left:4px;margin-right:4px;font-weight:bold;">LX</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">L1</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">L2</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">L3</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">L4</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">L5</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">LS Jxn</span>
                                <span style="font-size:0.9rem;color:var(--muted2);letter-spacing:0.1em;align-self:center;margin-left:4px;margin-right:4px;font-weight:bold;">SA</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">S1</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">S2</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">Sacrum</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">SI — R</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">SI — L</span>
                                <span class="chip" onclick="toggleChip(this, 'on-gold', 'aNote')">Coccyx</span>
                                </div>
                                <textarea name="assessment" class="form-control" cols="60" rows="6" id="aNote"
                                          placeholder="Vertebral subluxation complex noted at ___. Clinical impression: ___&#10;Functional limitations: ___. Prognosis: ___. Medically necessary because: ___." 
                                          onkeyup="top.isSoapEdit = true;"><?php echo text($_smarty_tpl->tpl_vars['data']->value->get_assessment());?>
</textarea>
                                <div class="qtags">
                                    <span class="qtag" onclick="qt('aNote','Good prognosis with continued chiropractic care')">good prognosis</span>
                                    <span class="qtag" onclick="qt('aNote','Disc involvement suspected')">disc involvement</span>
                                    <span class="qtag" onclick="qt('aNote','Nerve root irritation present')">nerve root irritation</span>
                                    <span class="qtag" onclick="qt('aNote','Postural contributors identified')">postural contributors</span>
                                    <span class="qtag" onclick="qt('aNote','Functional improvement trending positively')">positive trend</span>
                                    <span class="qtag" onclick="qt('aNote','Plateau observed — re-examination warranted')">plateau noted</span>
                            </div>
                            <div class="billing-box">
                                <div class="billing-label">Suggested ICD-10</div>
                                <div id="icdSuggestChips" class="chips"></div>
                            
                                <div class="billing-label" style="margin-top:8px;">Accepted ICD-10</div>
                                <div id="icdAcceptedChips" class="chips"></div>
                            
                                <input type="hidden" name="accepted_icd10" id="accepted_icd10" value="" />
                                <div id="billingAuditAssessment" class="billing-audit"></div>
                            </div>
                        </div>
                    </div>
                </div>
                </fieldset>
                </div><!-- /soapSOAWrapper -->
                <fieldset id="planFieldset">
                    <div class="container">
                        <div class="form-group">
                            <div class="soap-section">
                                <div class="section-head" style="display:flex;justify-content:space-between;align-items:center;">
                                    <div class="sh-left" style="display:flex;align-items:center;">
                                        <span class="soap-glyph glyph-p">P</span>
                                        <div class="soap-info">
                                            <strong>Plan</strong>
                                            <span class="soap-subtitle"
                                                  data-default="Treatment Plan · Follow-Up Instructions"
                                                  data-psychiatry="Treatment Plan · Medication · Follow-Up"
                                                  data-psychology="Therapeutic Interventions · Goals · Next Session">Treatment Plan · Follow-Up Instructions</span>
                                        </div>
                                    </div>
                                    <button class="ai-btn" type="button" onclick="generateSOAP()">✦ AI Generate</button>
                                </div>
                                   <div class="soap-card-section">
                                        <label class="soap-label">Treatment Goals</label>
                                    
                                        <div id="goalChips" class="goal-chip-wrap">
                                    
                                            <span class="goal-chip" data-goal="Pain Reduction">Pain Reduction</span>
                                            <span class="goal-chip" data-goal="Increase ROM">Increase ROM</span>
                                            <span class="goal-chip" data-goal="Improve Function">Improve Function</span>
                                            <span class="goal-chip" data-goal="Reduce Muscle Spasm">Reduce Muscle Spasm</span>
                                            <span class="goal-chip" data-goal="Improve Posture">Improve Posture</span>
                                            <span class="goal-chip" data-goal="Increase Strength">Increase Strength</span>
                                            <span class="goal-chip" data-goal="Improve ADLs">Improve ADLs</span>
                                            <span class="goal-chip" data-goal="Return To Work">Return To Work</span>
                                            <span class="goal-chip" data-goal="Prevent Recurrence">Prevent Recurrence</span>
                                            <span class="goal-chip" data-goal="Wellness Maintenance">Wellness Maintenance</span>
                                            <input type="hidden"
                                            id="plan_goals"
                                            name="plan_goals"
                                            value="">
                                    
                                        </div>
                                <!-- 4-column plan dropdowns (all specialties) -->
                                <div class="row-strip c3">  
                                    
                                    <div class="cell">
                                        <label>Frequency</label>
                                        <select id="plan_frequency" onchange="updatePlanNote()">
                                            <option value="">—</option>
                                            <option>3× / week</option>
                                            <option>2× / week</option>
                                            <option>1× / week</option>
                                            <option>Biweekly</option>
                                            <option>Monthly</option>
                                            <option>PRN</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Duration</label>
                                        <select id="plan_duration" onchange="updatePlanNote()">
                                            <option value="">—</option>
                                            <option>2 weeks</option>
                                            <option>4 weeks</option>
                                            <option>6 weeks</option>
                                            <option>8 weeks</option>
                                            <option>12 weeks</option>
                                            <option>Ongoing</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Next Visit Plan</label>
                                        <select id="plan_next_visit" onchange="updatePlanNote()">
                                            <option value="">—</option>
                                            <option>Continue current plan</option>
                                            <option>Re-evaluate progress</option>
                                            <option>Advance to corrective phase</option>
                                            <option>Taper frequency</option>
                                            <option>Discharge planning</option>
                                            <option>Refer out</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Treatments Rendered (chiro — multi-select checkboxes) -->
                                <div class="chip-group2 sp-field" data-specialties="chiro ortho pmr pain" style="margin-top:6px;">
                                    <span class="chip-group-label">Treatments Rendered</span>
                                    <div id="planTreatmentChecks" style="display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;margin-top:6px;">
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Chiropractic Adjustment" onchange="updatePlanNote()">
                                            Chiropractic Adjustment
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Soft Tissue Mobilization" onchange="updatePlanNote()">
                                            Soft Tissue Mobilization
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Myofascial Release" onchange="updatePlanNote()">
                                            Myofascial Release
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Therapeutic Exercise" onchange="updatePlanNote()">
                                            Therapeutic Exercise
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Electrical Stimulation" onchange="updatePlanNote()">
                                            Electrical Stimulation
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Ultrasound Therapy" onchange="updatePlanNote()">
                                            Ultrasound Therapy
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Mechanical Traction" onchange="updatePlanNote()">
                                            Mechanical Traction
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Intersegmental Traction" onchange="updatePlanNote()">
                                            Intersegmental Traction
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Cryotherapy" onchange="updatePlanNote()">
                                            Cryotherapy
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Thermotherapy" onchange="updatePlanNote()">
                                            Thermotherapy
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Cold Laser / LLLT" onchange="updatePlanNote()">
                                            Cold Laser / LLLT
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Dry Needling" onchange="updatePlanNote()">
                                            Dry Needling
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Cupping Therapy" onchange="updatePlanNote()">
                                            Cupping Therapy
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Kinesio Taping" onchange="updatePlanNote()">
                                            Kinesio Taping
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Rehabilitative Exercises" onchange="updatePlanNote()">
                                            Rehabilitative Exercises
                                        </label>
                                        <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:3px 0;">
                                            <input type="checkbox" class="plan-treatment-chk" value="Patient Education" onchange="updatePlanNote()">
                                            Patient Education
                                        </label>
                                    </div>
                                </div>

                                <!-- Non-chiro intervention row (other specialties) -->
                                <div class="row-strip c3 sp-field" data-specialties="primarycare familymed internalmed urgentcare neurology psychiatry psychology pmr ortho pain other" style="display:none">
                                    <div class="cell">
                                        <label class="sp-label" data-default="Intervention" data-psychiatry="Medication / Therapy" data-psychology="Therapeutic Modality">Intervention</label>
                                        <select id="plan_intervention" onchange="updatePlanNote()">
                                            <option value="">—</option>
                                            <optgroup label="General" class="sp-opts" data-specialties="primarycare familymed internalmed urgentcare other">
                                                <option>Medication prescribed</option>
                                                <option>Lab ordered</option>
                                                <option>Imaging ordered</option>
                                                <option>Referral placed</option>
                                                <option>Patient education provided</option>
                                                <option>Lifestyle counseling</option>
                                            </optgroup>
                                            <optgroup label="Psychiatry" class="sp-opts" data-specialties="psychiatry">
                                                <option>Medication initiation</option>
                                                <option>Medication adjustment</option>
                                                <option>Crisis safety planning</option>
                                                <option>Psychoeducation</option>
                                                <option>Referral to therapy</option>
                                                <option>Hospitalization discussed</option>
                                            </optgroup>
                                            <optgroup label="Therapy" class="sp-opts" data-specialties="psychology">
                                                <option>CBT techniques</option>
                                                <option>DBT skills</option>
                                                <option>Motivational Interviewing</option>
                                                <option>EMDR</option>
                                                <option>Mindfulness / ACT</option>
                                                <option>Exposure therapy</option>
                                                <option>Psychodynamic exploration</option>
                                                <option>Homework assigned</option>
                                            </optgroup>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Follow-Up</label>
                                        <select id="plan_followup" onchange="updatePlanNote()">
                                            <option value="">—</option>
                                            <option>1 week</option>
                                            <option>2 weeks</option>
                                            <option>4 weeks</option>
                                            <option>6 weeks</option>
                                            <option>3 months</option>
                                            <option>PRN</option>
                                            <option>Next week — same time</option>
                                        </select>
                                    </div>
                                    <div class="cell">
                                        <label>Patient Education</label>
                                        <select id="plan_education" onchange="updatePlanNote()">
                                            <option value="">—</option>
                                            <option>Medication side effects reviewed</option>
                                            <option>Diagnosis explained</option>
                                            <option>Lifestyle modifications discussed</option>
                                            <option>Warning signs reviewed</option>
                                            <option>Crisis resources provided</option>
                                            <option>Return precautions discussed</option>
                                        </select>
                                    </div>
                                </div>

                                <!-- Adjustment Technique chips -->
                                <div class="chip-group2 sp-field" data-specialties="chiro ortho pmr pain">
                                    <span class="chip-group-label">Adjustment Technique</span>
                                    <div id="planTechniqueChips" class="chips">
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Diversified</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Gonstead</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Thompson Drop</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Activator</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">SOT</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Cox Flexion-Dist.</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">HVLA</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Logan Basic</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Upper Cervical (NUCCA)</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Toggle Recoil</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">Instrument Assisted</span>
                                        <span class="chip" onclick="toggleChip(this,'on-green','pNote')">No Adjustment Today</span>
                                    </div>
                                </div>

                                <!-- Adjunct Therapies chips -->
                                <div class="chip-group2 sp-field" data-specialties="chiro ortho pmr pain">
                                    <span class="chip-group-label">Adjunct Therapies Administered</span>
                                    <div id="planAdjunctChips" class="chips">
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">EMS / E-Stim</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Ultrasound</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Cold Laser / LLLT</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Ice Pack</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Heat Pack</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Mechanical Traction</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Intersegmental Traction</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Dry Needling</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Cupping</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Myofascial Release</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Kinesio Taping</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Rehab / Exercise</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Nutritional Counsel</span>
                                        <span class="chip" onclick="toggleChip(this,'on-blue','pNote')">Cryotherapy</span>
                                    </div>
                                </div>

                                <!-- ── Corrective Exercises ── -->
                                <div class="chip-group2 sp-field" data-specialties="chiro ortho pmr pain">
                                    <span class="chip-group-label">Corrective Exercises</span>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:8px;">
                                        <!-- Exercise picker -->
                                        <div>
                                            <div style="position:relative;margin-bottom:8px;">
                                                <input type="text" id="exerciseSearch" placeholder="Search exercises…" oninput="filterExercises(this.value)"
                                                    style="width:100%;padding:7px 12px 7px 30px;border:1px solid #e2dff0;border-radius:8px;font-size:0.8rem;color:#1a1a2e;background:#fff;">
                                                <span style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#aaa;font-size:13px;">🔍</span>
                                            </div>
                                            <div id="exerciseList" style="border:1px solid #e8e4f4;border-radius:10px;overflow:hidden;max-height:220px;overflow-y:auto;">
                                                <label class="ex-item"><input type="checkbox" value="Cervical Retraction|2 sets × 10 reps" onchange="syncExercises()"> <span class="ex-name">Cervical Retraction</span><span class="ex-sub">Deep neck flexor strengthening</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Scapular Retraction|2 sets × 15 reps" onchange="syncExercises()"> <span class="ex-name">Scapular Retraction</span><span class="ex-sub">Postural correction</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Cat-Camel|2 sets × 10 reps" onchange="syncExercises()"> <span class="ex-name">Cat-Camel</span><span class="ex-sub">Spinal mobility</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Child's Pose|Hold 30 sec × 3" onchange="syncExercises()"> <span class="ex-name">Child's Pose</span><span class="ex-sub">Lumbar mobility</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Bird Dog|3 sets × 10 reps/side" onchange="syncExercises()"> <span class="ex-name">Bird Dog</span><span class="ex-sub">Core stabilization</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Dead Bug|3 sets × 8 reps/side" onchange="syncExercises()"> <span class="ex-name">Dead Bug</span><span class="ex-sub">Core / spinal control</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Prone Cobra|2 sets × 10 reps" onchange="syncExercises()"> <span class="ex-name">Prone Cobra</span><span class="ex-sub">Extension strengthening</span></label>
                                                <label class="ex-item"><input type="checkbox" value="McKenzie Press-Up|3 sets × 10 reps" onchange="syncExercises()"> <span class="ex-name">McKenzie Press-Up</span><span class="ex-sub">Disc centralization</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Chin Tuck|3 sets × 10 reps" onchange="syncExercises()"> <span class="ex-name">Chin Tuck</span><span class="ex-sub">Cervical alignment</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Hip Flexor Stretch|Hold 30 sec × 2/side" onchange="syncExercises()"> <span class="ex-name">Hip Flexor Stretch</span><span class="ex-sub">Lumbopelvic balance</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Piriformis Stretch|Hold 30 sec × 2/side" onchange="syncExercises()"> <span class="ex-name">Piriformis Stretch</span><span class="ex-sub">SI / hip mobility</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Clamshell|3 sets × 15 reps/side" onchange="syncExercises()"> <span class="ex-name">Clamshell</span><span class="ex-sub">Hip abductor strengthening</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Wall Angel|2 sets × 10 reps" onchange="syncExercises()"> <span class="ex-name">Wall Angel</span><span class="ex-sub">Thoracic mobility</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Thoracic Extension|Hold 30 sec × 3" onchange="syncExercises()"> <span class="ex-name">Thoracic Extension</span><span class="ex-sub">Over foam roller</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Pelvic Tilt|3 sets × 15 reps" onchange="syncExercises()"> <span class="ex-name">Pelvic Tilt</span><span class="ex-sub">Lumbar stabilization</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Plank|3 × 20–30 sec" onchange="syncExercises()"> <span class="ex-name">Plank</span><span class="ex-sub">Core endurance</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Glute Bridge|3 sets × 15 reps" onchange="syncExercises()"> <span class="ex-name">Glute Bridge</span><span class="ex-sub">Posterior chain activation</span></label>
                                                <label class="ex-item"><input type="checkbox" value="Side-Lying Rotation|10 reps/side" onchange="syncExercises()"> <span class="ex-name">Side-Lying Rotation</span><span class="ex-sub">Thoracic mobility</span></label>
                                            </div>
                                        </div>
                                        <!-- Selected exercises -->
                                        <div>
                                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                                <span style="font-size:0.62rem;font-weight:700;letter-spacing:0.11em;text-transform:uppercase;color:#9994b0;">Selected Exercises</span>
                                                <button type="button" onclick="clearExercises()" style="font-size:0.7rem;color:#7c3aed;background:none;border:none;padding:0;cursor:pointer;">Clear All</button>
                                            </div>
                                            <div id="selectedExercises" style="min-height:60px;">
                                                <p id="noExMsg" style="font-size:0.75rem;color:#ccc;font-style:italic;margin:8px 0;">No exercises selected</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Outcome strip -->
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

                                <!-- Plan textarea -->
                                <textarea name="plan" class="form-control" cols="60" rows="6" id="pNote"
                                    placeholder="Adjustments performed at: ___&#10;Therapies: ___&#10;Home care: Ice/heat ___ min, ___ × daily. Stretches / exercises: ___&#10;Restrictions: ___&#10;Patient education: ___&#10;Referral: ___&#10;Return: ___"
                                    onkeyup="top.isSoapEdit = true;"><?php echo text($_smarty_tpl->tpl_vars['data']->value->get_plan());?>
</textarea>

                                <!-- Plan quick tags -->
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

                                <!-- Billing -->
                                <div class="billing-box">
                                    <div class="billing-label">Suggested CPT / HCPCS</div>
                                    <div id="cptSuggestChips" class="chips"></div>
                                    <div class="billing-label" style="margin-top:8px;">Accepted CPT / HCPCS</div>
                                    <div id="cptAcceptedChips" class="chips"></div>
                                    <input type="hidden" name="accepted_cpt" id="accepted_cpt" value="" />
                                    <div id="billingAuditPlan" class="billing-audit"></div>
                                </div>
                            </div>
                            </div>
                        </div>
                    </div>
                    </fieldset>
                    </div><!-- /soapFormBody -->
                    <div class="form-group">
                        <div class="btn-group" role="group">
                            <button type="submit" class="btn btn-primary btn-save" name="Submit"><?php echo smarty_function_xlt(array('t'=>'Save'),$_smarty_tpl);?>
</button>
                            <button type="button" class="btn btn-secondary btn-cancel" id="btnClose"><?php echo smarty_function_xlt(array('t'=>'Cancel'),$_smarty_tpl);?>
</button>
                        </div>
                        <input type="hidden" name="id" value="<?php echo attr($_smarty_tpl->tpl_vars['data']->value->get_id());?>
" />
                        <input type="hidden" name="activity" value="<?php echo attr($_smarty_tpl->tpl_vars['data']->value->get_activity());?>
" />
                        <input type="hidden" name="pid" value="<?php echo attr($_smarty_tpl->tpl_vars['data']->value->get_pid());?>
" />
                        <input type="hidden" name="process" value="true" />
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php echo '<script'; ?>
>
        const close = function() {
            if (top.isSoapEdit === true) {
                dlgopen('', '', 450, 125, '', '<div class="text-danger"><?php echo smarty_function_xla(array('t'=>'Warning'),$_smarty_tpl);?>
</div>', {
                    type: 'Alert',
                    html: '<p><?php echo smarty_function_xla(array('t'=>'Do you want to close the tabs?'),$_smarty_tpl);?>
</p>',
                    buttons: [
                        { text: '<?php echo smarty_function_xla(array('t'=>'Cancel'),$_smarty_tpl);?>
', close: true, style: 'default btn-sm' },
                        { text: '<?php echo smarty_function_xla(array('t'=>'Close'),$_smarty_tpl);?>
', close: true, style: 'danger btn-sm', click: closeSoap },
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

var acceptedICD = [];
var acceptedCPT = [];

function uniqArray(arr) {
    var seen = {};
    var out = [];
    var i;
    for (i = 0; i < arr.length; i++) {
        if (arr[i] && !seen[arr[i]]) {
            seen[arr[i]] = true;
            out.push(arr[i]);
        }
    }
    return out;
}
//Selected Treatment Goals
let selectedGoals = [];

document.addEventListener('click', function(e){

    if(!e.target.classList.contains('goal-chip')) {
        return;
    }

    const chip = e.target;
    const goal = chip.dataset.goal;

    if (selectedGoals.includes(goal)) {

        selectedGoals =
            selectedGoals.filter(g => g !== goal);

        chip.classList.remove('active');

    } else {

        selectedGoals.push(goal);

        chip.classList.add('active');
    }

    document.getElementById('plan_goals').value =
        selectedGoals.join('|');

    updatePlanNote();

});

function getSpecialty() {
    var el = document.getElementById('aiSpecialty');
    return el ? el.value : 'chiro';
}

function setHiddenBillingFields() {
    var icdEl = document.getElementById('accepted_icd10');
    var cptEl = document.getElementById('accepted_cpt');

    if (icdEl) {
        icdEl.value = acceptedICD.join('|');
    }
    if (cptEl) {
        cptEl.value = acceptedCPT.join('|');
    }
}

function collectBillingFacts() {
    return {
        specialty: getSpecialty(),
        chiefComplaint: val('chief_complaint'),
        onset: val('onset'),
        pain: val('pain_intensity'),
        region: val('primary_region'),
        radiation: val('radiation'),
        neuro: val('neurological_sx'),
        response: val('response_to_care'),
        diagnosis: val('primary_diagnosis'),
        prognosis: val('prognosis'),
        intervention: val('plan_intervention'),
        followup: val('plan_followup'),
        education: val('plan_education'),
        levelsCount: document.querySelectorAll('#chip-group .chip.on-gold').length,
        orthoPositiveCount: document.querySelectorAll('#orthoChips .chip.on-red').length,
        dtrs: val('dtrs'),
        sensation: val('sensation'),
        motor: val('motor_strength'),
        subjective: document.getElementById('sNote') ? document.getElementById('sNote').value : '',
        objective: document.getElementById('oNote') ? document.getElementById('oNote').value : '',
        assessment: document.getElementById('aNote') ? document.getElementById('aNote').value : '',
        plan: document.getElementById('pNote') ? document.getElementById('pNote').value : ''
    };
}

function suggestICD10(facts) {
    var codes = [];

    if (facts.chiefComplaint === 'Low Back Pain' || facts.chiefComplaint === 'Back / Joint Pain') {
        codes.push('M54.50');
    }
    if (facts.chiefComplaint === 'Neck Pain') {
        codes.push('M54.2');
    }
    if (facts.chiefComplaint === 'Mid Back Pain') {
        codes.push('M54.6');
    }
    if (facts.chiefComplaint === 'Headache' || facts.chiefComplaint === 'Headache / Migraine') {
        codes.push('R51.9');
    }
    if (facts.chiefComplaint === 'Shoulder Pain') {
        codes.push('M25.519');
    }
    if (facts.chiefComplaint === 'Hip / SI Pain') {
        codes.push('M25.559');
    }
    if (facts.chiefComplaint === 'Depression') {
        codes.push('F32.A');
    }
    if (facts.chiefComplaint === 'Anxiety') {
        codes.push('F41.9');
    }
    if (facts.chiefComplaint === 'ADHD') {
        codes.push('F90.9');
    }

    if (facts.radiation === 'Into Right Leg' || facts.radiation === 'Into Left Leg' || facts.radiation === 'Bilateral Legs') {
        codes.push('M54.16');
    }
    if (facts.radiation === 'Into Right Arm' || facts.radiation === 'Into Left Arm') {
        codes.push('M54.12');
    }
    if (facts.neuro === 'Tingling / Paresthesia' || facts.neuro === 'Numbness + Tingling') {
        codes.push('R20.2');
    }
    if (facts.neuro === 'Weakness') {
        codes.push('R53.1');
    }

    if (facts.diagnosis) {
        codes.push(facts.diagnosis);
    }

    return uniqArray(codes);
}

function suggestCPT(facts) {
    var codes = [];

    if (facts.specialty === 'chiro') {
        if (facts.levelsCount >= 5) {
            codes.push('98942-AT review');
        } else if (facts.levelsCount >= 3) {
            codes.push('98941-AT review');
        } else if (facts.levelsCount >= 1) {
            codes.push('98940-AT review');
        }
    } else if (
        facts.specialty === 'primarycare' ||
        facts.specialty === 'familymed' ||
        facts.specialty === 'internalmed' ||
        facts.specialty === 'urgentcare' ||
        facts.specialty === 'other'
    ) {
        if (facts.objective && facts.assessment && facts.plan) {
            codes.push('99213 review');
        }
        if (facts.objective.length > 120 && facts.assessment.length > 120 && facts.plan.length > 120) {
            codes.push('99214 review');
        }
    } else if (facts.specialty === 'psychiatry') {
        if (facts.intervention === 'Medication initiation' || facts.intervention === 'Medication adjustment') {
            codes.push('99214 review');
        }
        codes.push('90833/90836/90838 review');
    } else if (facts.specialty === 'psychology') {
        codes.push('90834 review');
        if (facts.plan.indexOf('60') !== -1 || facts.plan.indexOf('hour') !== -1) {
            codes.push('90837 review');
        }
    }

    return uniqArray(codes);
}

function refreshBillingEngine() {
    var facts = collectBillingFacts();
    var icdList = suggestICD10(facts);
    var cptList = suggestCPT(facts);
    var audit = buildAuditMessages(facts, icdList, cptList);

    renderSuggestionChips(icdList, cptList);
    renderAcceptedBilling();
    renderAuditMessages(audit);
}

function renderSuggestionChips(icdList, cptList) {
    var icdWrap = document.getElementById('icdSuggestChips');
    var cptWrap = document.getElementById('cptSuggestChips');

    if (icdWrap) {
        icdWrap.innerHTML = '';
        icdList.forEach(function(code) {
            var el = document.createElement('span');
            el.className = 'chip billing-chip';
            el.innerHTML = code;
            el.onclick = function() {
                acceptICD(code);
            };
            icdWrap.appendChild(el);
        });
    }

    if (cptWrap) {
        cptWrap.innerHTML = '';
        cptList.forEach(function(code) {
            var el = document.createElement('span');
            el.className = 'chip billing-chip';
            el.innerHTML = code;
            el.onclick = function() {
                acceptCPT(code);
            };
            cptWrap.appendChild(el);
        });
    }
}

function buildAuditMessages(facts, icdList, cptList) {
    var assessMsgs = [];
    var planMsgs = [];

    if (!facts.chiefComplaint) {
        assessMsgs.push('Missing chief complaint support.');
    }
    if (!facts.assessment) {
        assessMsgs.push('Assessment note is empty.');
    }
    if (!facts.objective) {
        assessMsgs.push('Objective findings are missing.');
    }

    if (facts.specialty === 'chiro') {
        if (facts.levelsCount === 0 && cptList.length > 0) {
            planMsgs.push('Chiropractic CMT suggested without documented regions/levels.');
        }
        if (facts.plan.indexOf('maintenance') !== -1 && cptList.join(' ').indexOf('AT') !== -1) {
            planMsgs.push('AT modifier suggestion may conflict with maintenance wording.');
        }
    }

    if (
        facts.specialty === 'primarycare' ||
        facts.specialty === 'familymed' ||
        facts.specialty === 'internalmed' ||
        facts.specialty === 'urgentcare'
    ) {
        if (cptList.indexOf('99214 review') !== -1 && facts.objective.length < 100) {
            planMsgs.push('Higher E/M suggestion may not be fully supported.');
        }
    }

    if (facts.specialty === 'psychiatry' || facts.specialty === 'psychology') {
        if (facts.chiefComplaint === '' && icdList.length > 0) {
            assessMsgs.push('Behavioral health code suggestion should be reviewed against documented diagnosis.');
        }
    }

    return {
        assessment: assessMsgs,
        plan: planMsgs
    };
}

function renderAuditMessages(audit) {
    var assessEl = document.getElementById('billingAuditAssessment');
    var planEl   = document.getElementById('billingAuditPlan');
    if (assessEl) {
        if (audit.assessment.length) { assessEl.style.display=''; assessEl.innerHTML='⚠ '+audit.assessment.join(' '); }
        else { assessEl.style.display='none'; assessEl.innerHTML=''; }
    }
    if (planEl) {
        if (audit.plan.length) { planEl.style.display=''; planEl.innerHTML='⚠ '+audit.plan.join(' '); }
        else { planEl.style.display='none'; planEl.innerHTML=''; }
    }
}

function renderAcceptedBilling() {
    var icdWrap = document.getElementById('icdAcceptedChips');
    var cptWrap = document.getElementById('cptAcceptedChips');
    if (icdWrap) {
        icdWrap.innerHTML = '';
        if (acceptedICD.length === 0) {
            icdWrap.innerHTML = '<span class="billing-empty">Click a suggestion to accept it</span>';
        } else {
            acceptedICD.forEach(function(code) {
                var el = document.createElement('span');
                el.className = 'chip billing-chip accepted';
                el.title = 'Click to remove';
                el.innerHTML = code + ' <span style="opacity:0.6">×</span>';
                el.onclick = function() { removeAcceptedICD(code); };
                icdWrap.appendChild(el);
            });
        }
    }
    if (cptWrap) {
        cptWrap.innerHTML = '';
        if (acceptedCPT.length === 0) {
            cptWrap.innerHTML = '<span class="billing-empty">Click a suggestion to accept it</span>';
        } else {
            acceptedCPT.forEach(function(code) {
                var el = document.createElement('span');
                el.className = 'chip billing-chip accepted';
                el.title = 'Click to remove';
                el.innerHTML = code + ' <span style="opacity:0.6">×</span>';
                el.onclick = function() { removeAcceptedCPT(code); };
                cptWrap.appendChild(el);
            });
        }
    }
    setHiddenBillingFields();
}

function acceptICD(code) {
    acceptedICD.push(code);
    acceptedICD = uniqArray(acceptedICD);
    renderAcceptedBilling();
}

function acceptCPT(code) {
    acceptedCPT.push(code);
    acceptedCPT = uniqArray(acceptedCPT);
    renderAcceptedBilling();
}

function removeAcceptedICD(code) {
    acceptedICD = acceptedICD.filter(function(x) { return x !== code; });
    renderAcceptedBilling();
}

function removeAcceptedCPT(code) {
    acceptedCPT = acceptedCPT.filter(function(x) { return x !== code; });
    renderAcceptedBilling();
}


function generateBillingSuggestions() {

var icd = [];
var cpt = [];

var cc = val('chief_complaint');
var specialty = document.getElementById('aiSpecialty').value;

// ICD logic
if (cc === "Low Back Pain") icd.push("M54.50");
if (cc === "Neck Pain") icd.push("M54.2");
if (cc === "Sciatica / Radiculopathy") icd.push("M54.30");
if (cc === "Headache") icd.push("R51.9");
if (cc === "Anxiety") icd.push("F41.9");
if (cc === "Depression") icd.push("F32.9");

// CPT logic
if (specialty === "chiro") {
    var levels = document.querySelectorAll('#chip-group .chip.on-gold').length;

    if (levels >= 5) cpt.push("98942");
    else if (levels >= 3) cpt.push("98941");
    else if (levels > 0) cpt.push("98940");
}

if (specialty === "primarycare") cpt.push("99213");
if (specialty === "psychiatry") cpt.push("90834");

renderBillingChips(icd, cpt);
}

function renderBillingChips(icd, cpt) {

var icdBox = document.getElementById('icdChips');
var cptBox = document.getElementById('cptChips');

if (!icdBox || !cptBox) return;

icdBox.innerHTML = "";
cptBox.innerHTML = "";

icd.forEach(function(code) {
    var el = document.createElement("span");
    el.className = "chip billing-chip";
    el.innerText = code;
    el.onclick = function() { addICD(code); };
    icdBox.appendChild(el);
});

cpt.forEach(function(code) {
    var el = document.createElement("span");
    el.className = "chip billing-chip";
    el.innerText = code;
    el.onclick = function() { addCPT(code); };
    cptBox.appendChild(el);
});
}

function addICD(code) {
    var a = document.getElementById('aNote');
    if (a && a.value.indexOf(code) === -1) {
        a.value += " ICD-10: " + code + ".";
    }
}

function addCPT(code) {
    var p = document.getElementById('pNote');
    if (p && p.value.indexOf(code) === -1) {
        p.value += " CPT: " + code + ".";
    }
}
// ── Specialty-Aware UI ────────────────────────────────────
function applySpecialty(sp) {
    // 1. Show/hide .sp-field elements based on data-specialties attribute
    document.querySelectorAll('.sp-field').forEach(function(el) {
        var specs = (el.getAttribute('data-specialties') || '').split(' ');
        el.style.display = specs.indexOf(sp) !== -1 ? '' : 'none';
    });

    // 2. Show/hide .sp-qtag elements
    document.querySelectorAll('.sp-qtag').forEach(function(el) {
        var specs = (el.getAttribute('data-specialties') || '').split(' ');
        el.style.display = specs.indexOf(sp) !== -1 ? '' : 'none';
    });

    // 3. Update labels that have sp-label variants
    document.querySelectorAll('.sp-label').forEach(function(el) {
        var text = el.getAttribute('data-' + sp) || el.getAttribute('data-default') || el.textContent;
        el.textContent = text;
    });

    // 4. Update section subtitles
    document.querySelectorAll('.soap-subtitle').forEach(function(el) {
        var text = el.getAttribute('data-' + sp) || el.getAttribute('data-default') || el.textContent;
        el.textContent = text;
    });

    // 5. Update textarea placeholders
    document.querySelectorAll('textarea[data-placeholder-default]').forEach(function(el) {
        var ph = el.getAttribute('data-placeholder-' + sp) || el.getAttribute('data-placeholder-default');
        el.placeholder = ph;
    });

    // 6. Filter <optgroup> elements in selects — hide groups not for this specialty
    document.querySelectorAll('optgroup.sp-opts').forEach(function(og) {
        var specs = (og.getAttribute('data-specialties') || '').split(' ');
        // Can't hide optgroups in all browsers, so remove/re-add or disable
        og.disabled = specs.indexOf(sp) === -1;
        og.style.display = specs.indexOf(sp) !== -1 ? '' : 'none';
    });

    // 7. Adjust plan outcome strip visibility for non-chiro
    var mskSp = ['chiro','ortho','pmr','pain','neurology','urgentcare','primarycare','familymed','internalmed','other'];
    var outcomeStrip = document.querySelector('.outcome-strip');
    if (outcomeStrip) {
        outcomeStrip.style.display = mskSp.indexOf(sp) !== -1 ? '' : 'none';
    }
}

// ── Page init ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    // Wire specialty pills
    document.querySelectorAll('.sp-pill').forEach(function(pill) {
        pill.addEventListener('click', function() {
            var sp = this.getAttribute('data-sp');
            document.querySelectorAll('.sp-pill').forEach(function(p) { p.classList.remove('active'); });
            this.classList.add('active');
            var sel = document.getElementById('aiSpecialty');
            if (sel) sel.value = sp;
            applySpecialty(sp);
            refreshBillingEngine();
        });
    });

    // Apply initial specialty
    var sel = document.getElementById('aiSpecialty');
    if (sel) {
        applySpecialty(sel.value);
        sel.addEventListener('change', function() { applySpecialty(this.value); });
    }

    // Initial billing engine run
    refreshBillingEngine();

    // Wire field changes to billing engine
    ['chief_complaint','onset','mechanism','pain_intensity','pattern','last_visit',
     'primary_region','radiation','neurological_sx','posture','gait','palpation',
     'muscle_tone','rom_quality','dtrs','sensation','motor_strength',
     'response_to_care','primary_diagnosis','prognosis','plan_intervention','plan_followup'
    ].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('change', refreshBillingEngine);
    });

    // Chip clicks and outcome buttons trigger billing refresh
    document.addEventListener('click', function(e) {
        if (e.target && (e.target.classList.contains('chip') || e.target.classList.contains('ocb'))) {
            setTimeout(refreshBillingEngine, 50);
        }
    });

    // Textarea changes for audit messages
    ['sNote','oNote','aNote','pNote'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('input', function() { setTimeout(refreshBillingEngine, 300); });
    });
});

// ── Subjective Dropdown ───────────────────────────────────
        
function updateSubjectiveNote() {
    const cc = val('chief_complaint');
    const onset = val('onset');
    const mech = val('mechanism');
    const pain = val('pain_intensity');
    const severity = getSeverity(pain);
    const pattern = val('pattern');
    const last = val('last_visit');
    const region = val('primary_region');
    const rad = val('radiation');
    const neuro = val('neurological_sx');

    let s = "";

    if (cc) {
        s += 'Patient presents with ' + (severity ? severity + " " : "") + (cc.toLowerCase()) ;
    }

    if (onset) {
        s += ', with ' + (onset.toLowerCase()) + ' onset';
    }

    if (mech && mech !== "Insidious" && mech !== "Unknown") {
        s += ' following ' + (mech.toLowerCase()) + ' injury';
    }

    s += ". ";

    if (pain) {
        s += 'Pain is rated ' + (pain) + '/10';
    }

    if (pattern) {
        s += ' and described as ' + (pattern.toLowerCase()) ;
    }

    s += ". ";

    if (region) {
        s += 'Primary involvement noted in the ' + (region.toLowerCase()) + '. ';
    }

    if (rad && rad !== "None") {
        s += 'Symptoms radiate ' + (rad.toLowerCase()) + '. ';
    }

    if (neuro && neuro !== "None") {
        s += 'Neurological symptoms include ' + (neuro.toLowerCase()) + '. ';
    }

    // 🔵 RED FLAGS AUTO INSERT
    if (!neuro || neuro === "None") {
        s += "Patient denies numbness, tingling, weakness, or bowel/bladder dysfunction. ";
    }

    if (last) {
        s += 'Condition is ' + (last.toLowerCase()) + ' compared to prior visit. ';
    }

    // 🔴 MEDICO-LEGAL
    s += "Symptoms are affecting activities of daily living.";

    // dataset.gen is a single slot shared with updateAutoAccidentNote() (the Auto
    // Accident panel below). Store this piece in dataset.stdGen and combine it with
    // any dataset.aaGen content so using both sets of controls doesn't clobber
    // either one's output; dataset.rec (manual edits / pasted templates) is untouched.
    var stdNote = clean(s);
    var ta = document.getElementById('sNote');
    if (ta) {
        ta.dataset.stdGen = stdNote;
        var combined = [ta.dataset.stdGen || '', ta.dataset.aaGen || ''].filter(Boolean).join(' ');
        setGeneratedContent('sNote', clean(combined));
    } else {
        setGeneratedContent('sNote', stdNote);
    }
}

// Function to toggle the chip's active state


function updateObjectiveNote() {
    let o = "Objective examination findings are as follows: ";

    const posture = val('posture');
    const gait = val('gait');
    const palp = val('palpation');
    const tone = val('muscle_tone');

    if (posture) o += 'Postural assessment reveals ' + (posture.toLowerCase()) + '. ';
    if (gait) o += 'Gait is ' + (gait.toLowerCase()) + '. ';
    if (palp) o += 'Palpation reveals ' + (palp.toLowerCase()) + '. ';
    if (tone) o += 'Muscle tone is ' + (tone.toLowerCase()) + '. ';

    let rom = [];
    if (val('flexion')) rom.push('flexion ' + (val('flexion')) + '°');
    if (val('extension')) rom.push('extension ' + (val('extension')) + '°');
    if (val('right_lat_flex')) rom.push('right lateral flexion ' + (val('right_lat_flex')) + '°');
    if (val('left_lat_flex')) rom.push('left lateral flexion ' + (val('left_lat_flex')) + '°');

    if (rom.length) {
        o += 'Range of motion is restricted with ' + (rom.join(', ')) + '. ';
    }

    const tests = [...document.querySelectorAll('#orthoChips .chip.on-red')]
        .map(c => c.textContent.replace(' (+)', ''));

    if (tests.length) {
        o += 'Orthopedic testing is positive for ' + (tests.join(', ')) + '. ';
    } else {
        o += "Orthopedic testing is negative. ";
    }

    // 🟢 COMPLIANCE
    o += "Findings are consistent with the patient’s reported symptoms.";

    setGeneratedContent('oNote', clean(o));
}
function updateAssessmentNote() {
    let a = "Clinical findings are consistent with a diagnosis of ";

    const levels = [...document.querySelectorAll('#chip-group .chip.on-gold')]
        .map(c => c.textContent.replace(' (+)', ''));

    if (levels.length) {
        a += 'vertebral subluxation complex involving ' + (levels.join(', ')) + '. ';
    }

    const response = val('response_to_care');
    if (response) {
        a += 'Patient is currently ' + (response.toLowerCase()) + '. ';
    }

    // 🟢 COMPLIANCE
    a += "Condition requires continued chiropractic care due to functional limitations and documented objective findings. ";

    // 🔴 MEDICO-LEGAL
    a += "Without treatment, the condition may worsen or result in further functional impairment.";

    setGeneratedContent('aNote', clean(a));
    refreshBillingEngine();
}

function updatePlanNote() {
    var v = function(id) { var el = document.getElementById(id); return el ? el.value : ''; };
    var p = "Treatment plan includes ";
    if (goals) {
    planText +=
        "\nTreatment Goals: " +
        goals.replace(/\|/g, ", ");
}

    var goals = v('plan_goals'), freq = v('plan_frequency'), dur = v('plan_duration'), nxt = v('plan_next_visit');
    if (goals) { p += "treatment goals of " + goals + ". "; }
    if (freq || dur) { p += "Frequency: " + (freq || "—") + " for " + (dur || "—") + ". "; }
    if (nxt) { p += "Next visit: " + nxt + ". "; }

    // Multi-select treatments rendered checkboxes
    var treatments = Array.from(document.querySelectorAll('.plan-treatment-chk:checked'))
        .map(function(c) { return c.value; });
    if (treatments.length) { p += "Treatments rendered: " + treatments.join(', ') + ". "; }

    var techniques = Array.from(document.querySelectorAll('#planTechniqueChips .chip.on-green'))
        .map(function(c) { return c.textContent.replace(' (+)', ''); });
    if (techniques.length) { p += "Chiropractic manipulation utilizing " + techniques.join(', ') + " technique. "; }

    var therapies = Array.from(document.querySelectorAll('#planAdjunctChips .chip.on-blue'))
        .map(function(c) { return c.textContent.replace(' (+)', ''); });
    if (therapies.length) { p += "Adjunctive therapies: " + therapies.join(', ') + ". "; }

    var responses = Array.from(document.querySelectorAll('.ocb.g, .ocb.r'))
        .map(function(b) { return b.textContent; });
    if (responses.length) { p += "Patient response: " + responses.join(', ') + ". "; }

    var exChecked = Array.from(document.querySelectorAll('#exerciseList input[type="checkbox"]:checked'))
        .map(function(cb) { return cb.value.split('|')[0]; });
    if (exChecked.length) { p += "Exercises prescribed: " + exChecked.join(', ') + ". "; }

    p += "Treatment is medically necessary to improve function and reduce pain. ";
    p += "Patient provided informed consent and tolerated treatment without complication.";

    setGeneratedContent('pNote', clean(p));
    refreshBillingEngine();
}
       
// Function to toggle the chip's active state and update the note
function toggleChip(chip, className, noteId) {
    // Toggle the clicked chip's active state
    chip.classList.toggle(className);

    if (chip.classList.contains(className)) {
        chip.textContent += ' (+)'; // Indicate selection
    } else {
        chip.textContent = chip.textContent.replace(' (+)', ''); // Remove the indication if deselected
    }

    // Update the respective note based on noteId
    switch (noteId) {
        case 'oNote':
            updateObjectiveNote(); // Update Objective note
            break;
        case 'aNote':
            updateAssessmentNote(); // Update Assessment note
            break;
        case 'pNote':
            updatePlanNote(); // Update Plan note
            break;
        default:
            console.error("Invalid noteId provided:", noteId);
    }
}

function qt(noteId, text) {
    const noteTextarea = document.getElementById(noteId); // Get the corresponding textarea
    noteTextarea.value += text + '\n'; // Append the text from the button
}

function ocToggle(button, state) {
    const chips = button.parentElement.querySelectorAll('.ocb'); // Get all buttons in this cell
    chips.forEach(btn => {
        btn.classList.remove('g', 'r'); // Remove previous states
    });

    // Apply the active state
    button.classList.add(state);

    // Update the plan note (you can modify the note as per your requirements)
    updatePlanNote();
}

// Function to update notes based on the active chips and dropdown selections
function updateNotes(noteId) {
    const noteTextarea = document.getElementById(noteId);
    let selectedContent = [];

    // Gather selected orthopedic tests (chips) for Objective
    if (noteId === 'oNote') {
        const orthoChips = document.querySelectorAll('#orthoChips .chip.on-red');
        selectedContent = Array.from(orthoChips)
            .map(chip => chip.textContent.replace(' (+)', '')) // Remove the indication for display
            .filter(Boolean); // Filter out any empty values

        // Gather selected dropdown values for Objective
        const dropdowns = document.querySelectorAll('#yourDropdownsSelector'); // Replace with the actual selector for your dropdowns
        dropdowns.forEach(dropdown => {
            if (dropdown.value) {
                selectedContent.push(dropdown.value);
            }
        });
    }

    // Gather selected subluxation/fixation levels (chips) for Assessment
    if (noteId === 'aNote') {
        const subluxationChips = document.querySelectorAll('.chip-group .chip.on-gold');
        selectedContent = Array.from(subluxationChips)
            .map(chip => chip.textContent.replace(' (+)', '')) // Remove the indication for display
            .filter(Boolean); // Filter out any empty values
    }

    // Update the note textarea without clearing previous selections
    noteTextarea.value = selectedContent.length 
        ? selectedContent.join(', ') + '.' // Append selected items
        : ''; // Clear if nothing is selected
}

// ── Recording + Auto-Section Detection ───────────────────
let isRec = false;
let fullTranscript = '';
// Structured segments: array of objects with .text and .section properties
let segments = [];
let interimText = '';

const transcriptBody = document.getElementById('transcriptBody');
const addToSoapBtn   = document.getElementById('addToSoapBtn');

const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
const SPEECH_SUPPORTED  = typeof SpeechRecognition === 'function';
const recognition       = SPEECH_SUPPORTED ? new SpeechRecognition() : null;

if (recognition) {
    recognition.continuous     = true;
    recognition.interimResults = true;
    recognition.lang           = 'en-US';
}

// ── Section keyword classifier ────────────────────────────
const SECTION_RULES = [
    {
        section: 's',
        patterns: [
            /\b(patient (reports?|states?|says?|complains?|presents?|describes?|notes?|feels?|mentions?|denies?))\b/i,
            /\b(chief complaint|history of present illness|hpi|onset|duration|pain (started|began|since))\b/i,
            /\b(aggravat|reliev|radiat|numbness|tingling|stiffness|weakness|symptom|complaint)\b/i,
            /\b(worse|better|improved|same as last|no change|prior to|prior treatment|sleeping)\b/i,
            /\b(rated|on a scale|out of ten|VAS|pain level|severity)\b/i,
            /\b(bowel|bladder|nausea|fever|chills|appetite|mood|anxiety|depression|stress)\b/i,
        ]
    },
    {
        section: 'o',
        patterns: [
            /\b(exam(ination)?|physical exam|objective|on exam|findings?)\b/i,
            /\b(palpat|spasm|tenderness|hypertonicity|muscle tone|posture|gait|ROM|range of motion)\b/i,
            /\b(flexion|extension|lateral flex|rotation|restricted|WNL|within normal)\b/i,
            /\b(orthopedic test|SLR|Kemp|Spurling|FABERE|Gaenslen|Valsalva|positive|negative test)\b/i,
            /\b(reflex|DTR|sensation|motor strength|dermatome|myotome)\b/i,
            /\b(blood pressure|pulse|heart rate|temperature|weight|BMI|vital)\b/i,
            /\b(appear(ance|s)|affect|thought process|insight|judgment|oriented)\b/i,
        ]
    },
    {
        section: 'a',
        patterns: [
            /\b(assessment|diagnosis|impression|consistent with|subluxation|fixation)\b/i,
            /\b(ICD|M54|F3[0-9]|F4[0-9]|G[0-9]|condition is|clinical impression)\b/i,
            /\b(prognosis|responding|not responding|plateau|exacerbation|acute|chronic)\b/i,
            /\b(DSM|disorder|syndrome|neuropathy|radiculopathy|myelopathy|stenosis)\b/i,
            /\b(improving|worsening|stable|guarded|functional limitation)\b/i,
        ]
    },
    {
        section: 'p',
        patterns: [
            /\b(plan|treatment|adjust(ment|ed)|manipulat|technique|therapy|therapies)\b/i,
            /\b(recommend(ed|ation)|prescrib|medication|referral|follow.?up|return)\b/i,
            /\b(exercises?|home care|ice|heat|stretching|traction|ultrasound|e.?stim)\b/i,
            /\b(Diversified|Gonstead|Thompson|Activator|HVLA|SOT|Cox|Logan|NUCCA)\b/i,
            /\b(CBT|DBT|EMDR|ACT|mindfulness|exposure|homework|next session)\b/i,
            /\b(times? per week|frequency|phase of care|maintenance|corrective|acute care)\b/i,
            /\b(patient education|informed consent|tolerated|no adverse|restrictions)\b/i,
        ]
    }
];

function detectSection(sentence) {
    const scores = { s: 0, o: 0, a: 0, p: 0 };
    SECTION_RULES.forEach(function(rule) {
        rule.patterns.forEach(function(pat) {
            if (pat.test(sentence)) scores[rule.section]++;
        });
    });
    const best = Object.entries(scores).sort((a, b) => b[1] - a[1])[0];
    return best[1] > 0 ? best[0] : 'u'; // 'u' = unmatched
}

function sectionToFieldId(sec) {
    return { s: 'sNote', o: 'oNote', a: 'aNote', p: 'pNote' }[sec] || null;
}

function sectionLabel(sec) {
    return { s: 'S', o: 'O', a: 'A', p: 'P', u: '?' }[sec] || '?';
}

function appendToField(fieldId, text) {
    var el = document.getElementById(fieldId);
    if (!el) return;
    // Save recorded content separately so update functions can preserve it
    var existing = (el.dataset.rec || '').trim();
    el.dataset.rec = existing ? existing + '\n' + text.trim() : text.trim();
    // Rebuild visible value: recorded block + generated block
    var generated = (el.dataset.gen || '').trim();
    el.value = buildNoteValue(el.dataset.rec, generated);
    top.isSoapEdit = true;
}

// Build the final textarea value: recorded content first, then generated content
function buildNoteValue(recorded, generated) {
    recorded = (recorded || '').trim();
    generated = (generated || '').trim();
    if (recorded && generated) return recorded + '\n\n' + generated;
    return recorded || generated;
}

// Helper used by all update functions: preserve recorded content, replace generated block
function setGeneratedContent(fieldId, generatedText) {
    var el = document.getElementById(fieldId);
    if (!el) return;
    el.dataset.gen = generatedText;
    el.value = buildNoteValue(el.dataset.rec || '', generatedText);
}

function renderTranscript() {
    if (!transcriptBody) return;
    transcriptBody.innerHTML = '';

    if (segments.length === 0 && !interimText) {
        transcriptBody.innerHTML = '<span class="transcript-hint">Press <strong>Begin Recording</strong> to start the ambient scribe. In <em>Auto-Route</em> mode each sentence is automatically sorted into the correct SOAP field as you speak.</span>';
        return;
    }

    segments.forEach(function(seg) {
        var span = document.createElement('span');
        span.className = 'transcript-sentence ts-' + seg.section;
        span.title = { s:'→ Subjective', o:'→ Objective', a:'→ Assessment', p:'→ Plan', u:'→ Unmatched (use Add button)' }[seg.section] || '';
        span.textContent = seg.text + ' ';
        transcriptBody.appendChild(span);
    });

    if (interimText) {
        var interim = document.createElement('span');
        interim.className = 'ts-interim';
        interim.textContent = interimText;
        transcriptBody.appendChild(interim);
    }

    transcriptBody.scrollTop = transcriptBody.scrollHeight;
}

// Split transcript into sentences
function splitSentences(text) {
    return text.match(/[^.!?\n]+[.!?\n]?/g) || [text];
}

if (recognition) {

recognition.onresult = function(event) {
    var autoDetect = document.getElementById('autoDetectToggle')?.checked !== false;
    interimText = '';

    for (var i = event.resultIndex; i < event.results.length; i++) {
        var result = event.results[i];
        var transcript = result[0].transcript;

        if (result.isFinal) {
            fullTranscript += transcript + ' ';

            if (autoDetect) {
                // Split into sentences and route each one
                splitSentences(transcript).forEach(function(sentence) {
                    sentence = sentence.trim();
                    if (!sentence) return;
                    var sec = detectSection(sentence);
                    segments.push({ text: sentence, section: sec });

                    // Auto-append to correct field immediately
                    var fieldId = sectionToFieldId(sec);
                    if (fieldId) {
                        appendToField(fieldId, sentence);
                    }
                });
            } else {
                // Manual mode — just accumulate
                segments.push({ text: transcript.trim(), section: 'u' });
            }
        } else {
            interimText = transcript;
        }
    }

    renderTranscript();
};

recognition.onstart = function() {
    document.getElementById('routingLegend').style.display = 'flex';
};

recognition.onend = function() {
    if (fullTranscript.trim()) {
        addToSoapBtn.style.display = 'inline-block';
        var genBtn = document.getElementById('generateSoapBtn');
        if (genBtn) genBtn.style.display = 'inline-block';
    }
    interimText = '';
    renderTranscript();
};

recognition.onerror = function(event) {
    console.warn('Speech recognition error:', event.error);
    if (event.error === 'not-allowed') {
        alert('Microphone access denied. Please allow microphone access and try again.');
    }
};

} // end if (recognition)

function toggleRec() {
    isRec = !isRec;
    var btn  = document.getElementById('recBtn');
    var lbl  = document.getElementById('recLabel');
    var wf   = document.getElementById('waveform');
    var box  = document.getElementById('transcriptBox');
    var clearBtn = document.getElementById('clearTranscriptBtn');

    if (!SPEECH_SUPPORTED) {
        isRec = false;
        alert('Voice dictation requires Chrome or Edge.\nSpeech recognition is not supported in Firefox.');
        return;
    }

    if (isRec) {
        // Reset for new recording
        fullTranscript = '';
        segments = [];
        interimText = '';
        renderTranscript();

        btn.classList.add('recording');
        lbl.textContent = 'Stop Recording';
        wf.classList.add('on');
        box.classList.add('live-rec');
        if (clearBtn) clearBtn.style.display = 'inline-block';
        recognition.start();
    } else {
        btn.classList.remove('recording');
        lbl.textContent = 'Begin Recording';
        wf.classList.remove('on');
        box.classList.remove('live-rec');
        recognition.stop();
    }
}

function addToSoapNote() {
    // Manual mode: dump unmatched segments into selected section
    var selectedSection = document.getElementById('soapSection')?.value || 'subjective';
    var sectionMap = { subjective: 'sNote', objective: 'oNote', assessment: 'aNote', plan: 'pNote' };
    var fieldId = sectionMap[selectedSection];

    // Collect unmatched/manual segments not yet routed
    var unrouted = segments
        .filter(function(s) { return s.section === 'u'; })
        .map(function(s) { return s.text; })
        .join(' ');

    if (unrouted.trim() && fieldId) {
        appendToField(fieldId, unrouted.trim());
        // Mark them as routed
        segments.forEach(function(s) {
            if (s.section === 'u') s.section = selectedSection[0];
        });
        renderTranscript();
    }

    addToSoapBtn.style.display = 'none';
    document.getElementById('clearTranscriptBtn').style.display = 'none';
}

function clearTranscript() {
    fullTranscript = '';
    segments = [];
    interimText = '';
    renderTranscript();
    addToSoapBtn.style.display = 'none';
    document.getElementById('clearTranscriptBtn').style.display = 'none';
    document.getElementById('routingLegend').style.display = 'none';
    // Remove recorded content from all fields, keep generated content
    ['sNote', 'oNote', 'aNote', 'pNote'].forEach(function(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.dataset.rec = '';
        el.value = (el.dataset.gen || '').trim();
    });
}

// Function to format the textarea output into structured notes
function fillAI(section) {
    // Map of textareas
    const textareaMap = {
        snote: document.getElementById('sNote'),
        onote: document.getElementById('oNote'),
        anote: document.getElementById('aNote'),
        pnote: document.getElementById('pNote')
    };

    const subjectiveTextarea = textareaMap[section]; // Get the appropriate textarea
    
    // Check if the textarea is defined
    if (!subjectiveTextarea) {
        console.error("Textarea for section '" + section + "' is not defined.");
        return; // Exit the function if textarea is undefined
    }

    // Initialize the formatted notes
    let formattedNotes = '';

    // Collect subjective details as a paragraph
    if (section === 'snote') {
        formattedNotes += "Patient reports experiencing " + document.getElementById('chief_complaint').value + 
                          " that started " + document.getElementById('onset').value + 
                          " after " + document.getElementById('mechanism').value + ". " + 
                          "Pain intensity is rated at " + document.getElementById('pain_intensity').value + 
                          "/10, noted to be " + document.getElementById('pattern').value + 
                          " compared to last visit, which was " + document.getElementById('last_visit').value + 
                          ". The primary region affected is " + document.getElementById('primary_region').value +
                          ", with radiation of pain noted as " + document.getElementById('radiation').value + 
                          " and neurological symptoms including " + document.getElementById('neurological_sx').value + ". ";
    }

    // Collect objective details
    if (section === 'onote') {
        formattedNotes += "Objective Findings:\n";
        formattedNotes += "Posture: " + document.getElementById('posture').value + ".\n";
        formattedNotes += "Gait: " + document.getElementById('gait').value + ".\n";
        formattedNotes += "Palpation: " + document.getElementById('palpation').value + ".\n";
        formattedNotes += "Muscle Tone: " + document.getElementById('muscle_tone').value + ".\n";

        // Add ROM values
        formattedNotes += "Flexion: " + document.getElementById('flexion').value + "°.\n";
        formattedNotes += "Extension: " + document.getElementById('extension').value + "°.\n";
        formattedNotes += "Right Lateral Flexion: " + document.getElementById('right_lat_flex').value + "°.\n";
        formattedNotes += "Left Lateral Flexion: " + document.getElementById('left_lat_flex').value + "°.\n";
    }

    // Collect assessment details
    if (section === 'anote') {
        formattedNotes += "Assessment Section:\n";
        formattedNotes += "Response to Care: " + document.getElementById('response_to_care').value + ".\n";
        
        // Add selected subluxation/fixation levels
        const subluxationChips = document.querySelectorAll('#chip-group .chip.on-gold');
        const selectedSubluxations = Array.from(subluxationChips)
            .map(chip => chip.textContent.replace(' (+)', '')) // Clean up the text
            .filter(Boolean); // Filter out empty values

        if (selectedSubluxations.length) {
            formattedNotes += "Subluxation / Fixation Levels: " + selectedSubluxations.join(', ') + ".\n";
        }
    }

    // Collect plan details
    if (section === 'pnote') {
        formattedNotes += "Plan Section:\n";
        // Append selected adjustment techniques (chips)
        const planChips = document.querySelectorAll('#chip-group-plan .chip.on-green');
        const selectedTechniques = Array.from(planChips)
            .map(chip => chip.textContent.replace(' (+)', '')) // Clean up the text
            .filter(Boolean); // Filter out empty values

        if (selectedTechniques.length) {
            formattedNotes += "Adjustment Techniques: " + selectedTechniques.join(', ') + ".\n";
        }

        // Append responses
        const responses = document.querySelectorAll('.ocb.g, .ocb.r');
        const selectedResponses = Array.from(responses)
            .map(btn => btn.textContent)
            .filter(Boolean);

        if (selectedResponses.length) {
            formattedNotes += "Post-Adjustment Response: " + selectedResponses.join(', ') + ".\n";
        }
    }

    // Set the formatted text to the textarea
    subjectiveTextarea.value = formattedNotes.trim(); // Update textarea with formatted output
}

// Attach the fillAI function to the "AI Format" buttons for each section
document.querySelectorAll('.ai-btn').forEach(function(button) {
    button.addEventListener('click', function() {
        const section = this.getAttribute('data-section'); // Get the section from data attribute
        fillAI(section); // Call fillAI with the appropriate section
    });
});

// ── Clear ──────────────────────────────────────────
function clearAll() {
    if (!confirm('Clear all note content?')) return;
    ['sNote', 'oNote', 'aNote', 'pNote'].forEach(id => {
        var el = document.getElementById(id);
        if (!el) return;
        el.value = '';
        el.dataset.rec = '';
        el.dataset.gen = '';
    });
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


function val(id) {
    return document.getElementById(id)?.value || "";
}

function clean(text) {
    return text.replace(/\s+/g, ' ').replace(/\.\s*\./g, '.').trim();
}

function getSeverity(pain) {
    pain = parseInt(pain);
    if (!pain) return "";
    if (pain >= 8) return "severe";
    if (pain >= 5) return "moderate";
    if (pain >= 2) return "mild";
    return "minimal";
}

async function generateSOAP() {
    // Read specialty from dropdown (defaults to 'chiro' if not present)
    const specialty = document.getElementById('aiSpecialty')?.value || 'chiro';

    // Disable all AI buttons while generating
    const btns = document.querySelectorAll('.ai-btn, #generateSoapBtn');
    btns.forEach(b => { b.disabled = true; b._origText = b.innerText; b.innerText = '⏳ Generating…'; });

    const structured = {
        chief_complaint:  val('chief_complaint'),
        onset:            val('onset'),
        mechanism:        val('mechanism'),
        pain_intensity:   val('pain_intensity'),
        pattern:          val('pattern'),
        last_visit:       val('last_visit'),
        primary_region:   val('primary_region'),
        radiation:        val('radiation'),
        neurological_sx:  val('neurological_sx'),
        posture:          val('posture'),
        gait:             val('gait'),
        palpation:        val('palpation'),
        muscle_tone:      val('muscle_tone'),
        flexion:          val('flexion'),
        extension:        val('extension'),
        right_lat_flex:   val('right_lat_flex'),
        left_lat_flex:    val('left_lat_flex'),
        response_to_care: val('response_to_care')
    };

    const payload = {
        specialty:  specialty,
        transcript: (typeof fullTranscript !== 'undefined') ? fullTranscript : '',
        current: {
            subjective: document.getElementById('sNote')?.value || '',
            objective:  document.getElementById('oNote')?.value  || '',
            assessment: document.getElementById('aNote')?.value  || '',
            plan:       document.getElementById('pNote')?.value  || ''
        },
        structured: structured
    };

    try {
        const response = await fetch('../../custom/ai_soap.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload)
        });

        if (!response.ok) {
            const errData = await response.json().catch(() => ({}));
            throw new Error(errData.error || 'Server error ' + response.status);
        }

        const data = await response.json();

        if (data.subjective) setGeneratedContent('sNote', data.subjective);
        if (data.objective)  setGeneratedContent('oNote', data.objective);
        if (data.assessment) setGeneratedContent('aNote', data.assessment);
        if (data.plan)       setGeneratedContent('pNote', data.plan);

    } catch (err) {
        console.error(err);
        alert('AI generation failed: ' + err.message);
    }

    btns.forEach(b => { b.disabled = false; b.innerText = b._origText || '✦ AI Generate'; });
}

// ── Exercise picker ────────────────────────────────────────────────────────
function syncExercises() {
    var checkboxes = document.querySelectorAll('#exerciseList input[type="checkbox"]:checked');
    var container = document.getElementById('selectedExercises');
    var noMsg = document.getElementById('noExMsg');
    
    // rebuild selected list
    var existing = container.querySelectorAll('.sel-ex-row');
    existing.forEach(function(el) { el.remove(); });
    
    if (checkboxes.length === 0) {
        noMsg.style.display = 'block';
    } else {
        noMsg.style.display = 'none';
        checkboxes.forEach(function(cb) {
            var parts = cb.value.split('|');
            var name = parts[0]; var detail = parts[1] || '';
            var row = document.createElement('div');
            row.className = 'sel-ex-row';
            row.innerHTML = '<div><div class="sel-ex-name">' + name + '</div><div class="sel-ex-detail">' + detail + '</div></div>' +
                '<button type="button" class="sel-ex-remove" onclick="removeExercise(this, this.dataset.val)">×</button>';
            row.querySelector('button').dataset.val = cb.value;
            container.appendChild(row);
        });
    }
    // also append to pNote
    updateExercisesInNote();
}

function removeExercise(btn, valStr) {
    if (!valStr) { valStr = btn.dataset ? btn.dataset.val : ''; }
    var cb = document.querySelector('#exerciseList input[value="' + valStr + '"]');
    if (cb) { cb.checked = false; }
    syncExercises();
}

function clearExercises() {
    document.querySelectorAll('#exerciseList input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });
    syncExercises();
}

function filterExercises(query) {
    var q = query.toLowerCase();
    document.querySelectorAll('.ex-item').forEach(function(item) {
        var name = item.querySelector('.ex-name').textContent.toLowerCase();
        var sub  = item.querySelector('.ex-sub').textContent.toLowerCase();
        item.style.display = (name.includes(q) || sub.includes(q)) ? '' : 'none';
    });
}

function updateExercisesInNote() {
    // optional: append exercise list to pNote textarea
    var checkboxes = document.querySelectorAll('#exerciseList input[type="checkbox"]:checked');
    if (!checkboxes.length) return;
    // don't auto-write to pNote to avoid overwriting manual edits
}

// ══════════════════════════════════════════════════════════════════
//  SOAP NOTE TEMPLATES
// ══════════════════════════════════════════════════════════════════

var SOAP_TEMPLATES = {
    blank: {
        name: 'SOAP Note',
        s: '',
        o: '',
        a: '',
        p: ''
    },
    auto: {
        name: 'Auto Accident',
        // Paragraphs intentionally left blank — selecting this template reveals the
        // Auto Accident dropdown panel (see #autoAccidentPanel) and the Subjective/
        // Objective/Assessment/Plan notes are auto-composed from those selections via
        // updateAutoAccidentNote(), rather than being dumped in as static boilerplate.
        s: '',
        o: '',
        a: '',
        p: ''
    },
    reexam: {
        name: 'Re-Exam',
        // Paragraphs intentionally left blank — selecting this template reveals
        // the Re-Exam Form (see #reexamFormWrapper: Progress Tracking, Outcome
        // Measures, Comparison Columns, Prognosis) in place of Subjective/
        // Objective/Assessment. Plan stays visible and is filled in normally
        // through its own existing controls — see buildReexamNote() for how
        // the structured note is composed instead of static boilerplate.
        s: '',
        o: '',
        a: '',
        p: ''
    },
    diagnosis: {
        name: 'Diagnosis Summary',
        // Paragraphs intentionally left blank — selecting this template reveals
        // the Diagnosis Summary Form (see #diagnosisFormWrapper) in place of the
        // full SOAP UI. See buildDiagnosisNote() for the paragraph composed from
        // the structured selections.
        s: '',
        o: '',
        a: '',
        p: ''
    },
    discharge: {
        name: 'Discharge Summary',
        // Paragraphs intentionally left blank — selecting this template reveals
        // the Discharge Summary Form (see #dischargeFormWrapper: Treatment
        // Summary, Outcomes, Final Findings, Discharge Narrative) in place of
        // the full SOAP UI. See buildDischargeNote() for the paragraphs composed
        // from the structured selections.
        s: '',
        o: '',
        a: '',
        p: ''
    }
};

var _tmplPanelOpen = false;
var _tmplActive    = 'blank';
var _tmplMode      = 'replace'; // 'replace' | 'append'

// ══════════════════════════════════════════════════════════════════
//  CLINICAL NARRATIVES — plain narrative paragraph text, inserted into
//  Subjective only (not full S/O/A/P like SOAP_TEMPLATES). Structured the
//  same way as the Templates dropdown (Replace/Prepend/Append, same panel
//  markup/classes), but fully independent state and selection logic — see
//  narrSelect() / narrTogglePanel() etc. below.
// ══════════════════════════════════════════════════════════════════
var NARRATIVE_TEMPLATES = {
    maintenance: {
        name: 'Maintenance',
        text: 'Patient presents today for routine chiropractic maintenance care. Reports continued overall improvement in pain levels, mobility, and daily functional capacity since previous visit. Denies any new injury, trauma, falls, or aggravating events. Mild intermittent stiffness remains present in the cervical, thoracic, lumbar, and/or pelvic regions, primarily associated with prolonged sitting, prolonged standing, occupational activities, exercise, or daily stressors. Symptoms remain manageable and do not significantly interfere with activities of daily living. Patient reports compliance with home stretching, exercise recommendations, hydration, and ergonomic modifications. Sleep quality is stable. Energy levels are unchanged. Denies numbness, tingling, radiating pain, weakness, bowel or bladder dysfunction, headaches beyond baseline, dizziness, or other neurological complaints. Patient states chiropractic care continues to provide meaningful benefit and wishes to maintain spinal health, mobility, and overall wellness.',
        o: 'Postural evaluation reveals mild residual spinal and postural asymmetries. Gait is stable and non-antalgic. Palpation identifies persistent segmental restrictions and mild hypertonicity within the cervical, thoracic, lumbar, and pelvic musculature. Motion palpation demonstrates vertebral fixation and reduced joint mobility within clinically relevant regions. Active ranges of motion are functional with mild end-range restriction noted in affected spinal segments. Orthopedic testing is negative for acute pathology. Neurological examination remains grossly intact with normal sensation, reflexes, and motor strength throughout bilateral upper and lower extremities. No new objective findings compared to previous evaluation.',
        a: 'Patient demonstrates stable chronic spinal dysfunction with ongoing positive response to conservative chiropractic management. Segmental dysfunction and associated myofascial restriction persist but remain improved compared to initial presentation. No evidence of acute exacerbation, neurological compromise, or significant functional decline. Continued maintenance chiropractic care remains medically appropriate to support spinal mobility, neuromuscular function, postural balance, and prevention of recurrence.',
        p: 'Chiropractic adjustments administered to identified restricted spinal and/or extremity regions utilizing appropriate technique. Patient tolerated treatment without complication. Reinforced home exercise program, stretching routine, ergonomic recommendations, hydration, and postural awareness strategies. Continue maintenance care as clinically indicated. Monitor for any changes in symptoms, function, or neurological status. Follow-up at established maintenance interval.'
    },
    acute: {
        name: 'Acute Flare',
        text: 'Patient presents with acute exacerbation of previously documented condition. Reports increased pain, stiffness, muscle tension, and decreased mobility beginning approximately ___ days ago. Symptoms may be associated with occupational activities, lifting, repetitive motion, prolonged sitting, prolonged standing, sports participation, travel, stress, poor sleeping position, or other aggravating factors. Pain is described as aching, sharp, throbbing, tight, burning, or stabbing and is rated ___/10. Symptoms worsen with movement, bending, lifting, twisting, prolonged positioning, or daily activities. Temporary relief is obtained through rest, heat, ice, stretching, medication, or previous chiropractic treatment. Patient reports decreased functional tolerance for work duties, household activities, exercise, sleep, and recreational activities. Denies recent trauma unless otherwise documented. Denies bowel or bladder dysfunction, saddle anesthesia, progressive weakness, or other red flag symptoms.',
        o: 'Patient demonstrates guarded posture and protective movement patterns. Gait may be mildly antalgic. Palpation reveals increased muscular hypertonicity, tenderness, trigger points, and localized inflammation within affected regions. Motion palpation identifies multiple areas of segmental dysfunction and restricted joint mobility. Active range of motion is decreased and reproduces familiar symptoms. Orthopedic testing is consistent with mechanical spinal dysfunction. Neurological examination remains grossly intact unless otherwise documented. No objective evidence of emergent neurological compromise noted during today\u2019s evaluation.',
        a: 'Acute exacerbation of chronic mechanical spinal dysfunction involving the cervical, thoracic, lumbar, pelvic, and/or extremity regions. Findings are consistent with segmental dysfunction, associated muscular guarding, and soft tissue irritation. Symptoms correlate with objective examination findings. Prognosis remains favorable with conservative chiropractic management. No indication for emergent intervention based on today\u2019s presentation.',
        p: 'Chiropractic adjustments performed to restricted spinal and/or extremity segments. Soft tissue therapy, myofascial release, stretching, therapeutic exercise, cryotherapy, thermotherapy, electrical stimulation, or other adjunctive modalities administered as clinically appropriate. Home care instructions reviewed including activity modification, hydration, stretching, and use of heat or ice. Patient advised to avoid aggravating activities until symptoms improve. Follow-up recommended within ___ days/weeks to reassess progress and response to treatment.'
    },
    auto: {
        name: 'Auto Accident',
        text: 'Patient presents for evaluation and treatment related to injuries sustained in a motor vehicle collision occurring on ___ date. Patient was the driver/passenger of a vehicle involved in a rear-end, front-end, side-impact, rollover, or multi-vehicle collision. Patient was restrained by seatbelt and denies/admits airbag deployment. Following the collision, patient experienced onset of neck pain, mid-back pain, low back pain, headaches, shoulder pain, extremity symptoms, muscle stiffness, reduced mobility, dizziness, or other related complaints. Current pain level is reported as ___/10. Symptoms are aggravated by prolonged sitting, driving, work activities, lifting, bending, twisting, sleeping, and activities of daily living. Patient reports difficulty performing normal occupational, household, recreational, and self-care activities due to accident-related symptoms. Denies loss of consciousness unless otherwise documented. Denies bowel or bladder dysfunction, progressive neurological deficits, or other emergent symptoms.',
        o: 'Postural evaluation reveals guarding and altered biomechanics. Palpation identifies significant muscular hypertonicity, tenderness, trigger points, inflammation, and soft tissue restriction throughout affected regions. Motion palpation demonstrates segmental dysfunction and restricted spinal motion. Active range of motion is reduced and reproduces familiar symptoms. Orthopedic testing findings are consistent with whiplash-associated disorder, cervical strain/sprain, thoracic strain/sprain, lumbar strain/sprain, and related biomechanical dysfunction. Neurological examination demonstrates intact sensation, reflexes, and motor function unless otherwise noted. Findings are consistent with injuries sustained during the reported motor vehicle collision.',
        a: 'Patient presents with accident-related musculoskeletal injuries including cervical, thoracic, lumbar, pelvic, and/or extremity dysfunction. Clinical findings support diagnoses of sprain/strain injury, segmental dysfunction, myofascial injury, and associated neuromusculoskeletal impairment resulting from the motor vehicle collision. Symptoms remain directly related to the reported accident mechanism. Functional limitations continue to impact daily activities, occupational duties, sleep quality, and overall quality of life. Continued chiropractic treatment remains medically necessary.',
        p: 'Chiropractic adjustments administered to affected spinal and/or extremity regions. Adjunctive therapies performed as clinically indicated including soft tissue mobilization, myofascial release, therapeutic exercise, stretching, electrical stimulation, ultrasound, cryotherapy, thermotherapy, or rehabilitative procedures. Home care instructions reviewed and reinforced. Patient advised regarding activity modification, ergonomic considerations, and symptom management strategies. Continue treatment plan focused on pain reduction, restoration of mobility, improvement of function, stabilization of injured tissues, and return to pre-injury status. Re-evaluate progress at regular intervals and coordinate care with other providers as necessary.'
    },
    reexam: {
        name: 'Re-Exam',
        text: 'Patient presents today for re-evaluation of their current treatment plan. Patient reports overall progress since beginning care with measurable improvement in pain levels, mobility, and functional activities. Current symptoms are described as intermittent and less intense than at the start of treatment. Patient reports improved tolerance for daily activities including work duties, household tasks, exercise, and activities of daily living. Residual complaints remain in affected regions with occasional stiffness, soreness, or discomfort during prolonged activity, repetitive movement, lifting, bending, sitting, standing, or sleeping.\n\nPain today is rated ___/10 compared to initial evaluation rating of ___/10. Patient reports approximately ___% overall improvement since beginning treatment. Aggravating factors include ____. Relieving factors include chiropractic treatment, stretching, home exercises, activity modification, ice/heat, and rest.\n\nPatient remains compliant with the recommended treatment plan and home care instructions. No new injuries, accidents, or significant changes in health status reported since the previous evaluation.',
        o: 'Postural evaluation reveals improved alignment with residual biomechanical dysfunction noted. Palpation demonstrates persistent but reduced hypertonicity, tenderness, myofascial restriction, and segmental dysfunction within affected regions. Range of motion testing demonstrates improvement compared to initial findings with remaining restrictions noted in affected spinal and extremity regions.\n\nOrthopedic and neurological testing performed as indicated. Findings demonstrate improvement from initial examination with residual positive findings noted where applicable. Muscle strength, reflexes, and sensory examination remain stable unless otherwise documented.\n\nVertebral subluxation complexes identified at:\nCervical: ___\nThoracic: ___\nLumbar: ___\nSacral/Pelvic: ___\n\nFunctional limitations reviewed:\n☐ Sitting\n☐ Standing\n☐ Walking\n☐ Lifting\n☐ Sleeping\n☐ Driving\n☐ Work Activities\n☐ Recreational Activities\n\nCurrent Functional Improvement:',
        a: 'Patient demonstrates measurable clinical improvement since initiation of care. Objective findings correlate with subjective reports of decreased pain, improved mobility, enhanced function, and increased tolerance to activities of daily living. Although progress has been achieved, residual spinal dysfunction, soft tissue restrictions, and functional deficits remain present.\n\nCurrent diagnoses remain clinically supported and continue to contribute to ongoing symptoms and functional limitations. Patient has responded favorably to treatment and has not yet reached maximum therapeutic benefit.\n\nMedical necessity for continued care is supported by:\n• Persistent objective findings\n• Ongoing functional limitations\n• Residual pain and mobility restrictions\n• Positive response to treatment\n• Prevention of regression and recurrence\n\nPrognosis:\n☐ Excellent\n☐ Good\n☐ Fair\n☐ Guarded\n\nOverall Improvement Since Initial Evaluation: ___ %',
        p: 'Continue chiropractic treatment with emphasis on correction of identified subluxation complexes, restoration of normal joint mechanics, reduction of pain and inflammation, improvement of functional mobility, and stabilization of long-term outcomes.\n\nTreatment Frequency:\n___ visits per week for ___ weeks\n\nTreatment Components:\n• Chiropractic spinal adjustments\n• Extremity adjustments as indicated\n• Myofascial release / soft tissue therapy\n• Therapeutic exercises\n• Neuromuscular re-education\n• Home exercise program\n• Patient education and ergonomic counseling\n\nGoals:\n• Further reduce pain levels\n• Improve range of motion\n• Increase functional capacity\n• Improve postural stability\n• Return to prior level of function\n• Transition toward supportive/maintenance care when appropriate\n\nPatient advised to continue home care recommendations and follow-up as scheduled. Re-examination to be performed in ___ days/weeks to assess progress and update treatment goals.'
    },
    pi: {
        name: 'Personal Injury Narrative',
        text: 'The patient continues to receive conservative chiropractic care for injuries sustained in a reported motor vehicle collision. Subjective complaints remain consistent with the documented mechanism of injury and include pain, stiffness, muscle spasm, and functional limitations affecting normal daily activities.\n\nToday\u2019s examination demonstrates persistent objective findings including restricted range of motion, segmental dysfunction, muscular hypertonicity, tenderness to palpation, and positive orthopedic findings as documented within this encounter. These objective findings continue to support the medical necessity of treatment.\n\nThe patient reports that symptoms interfere with activities of daily living including prolonged sitting, standing, walking, driving, lifting, work duties, household activities, and sleep quality. Although measurable clinical improvement has been observed since the initial evaluation, the patient continues to experience residual pain and functional impairment.\n\nTreatment provided during today\u2019s visit was directed toward reducing pain and inflammation, improving spinal and extremity mobility, restoring normal biomechanics, decreasing muscular spasm, and improving functional capacity. Treatment included chiropractic manipulative therapy along with any adjunctive therapies documented in today\u2019s treatment record.\n\nBased upon today\u2019s clinical findings, continued conservative care remains medically necessary. The patient\u2019s prognosis remains favorable with continued compliance to the prescribed treatment plan, home exercise program, and activity modifications. Updated objective findings, treatment rendered, and patient response are documented within today\u2019s clinical record.',
        o: 'Examination demonstrates decreased active range of motion of the affected spinal regions with pain at end ranges. Palpation reveals hypertonicity, tenderness, and myofascial trigger points involving the cervical, thoracic, and/or lumbar paraspinal musculature. Segmental joint dysfunction is present with associated vertebral subluxation/segmental dysfunction identified during examination.\n\nOrthopedic and neurological findings are consistent with the patient\u2019s mechanism of injury and reported complaints. Additional findings include muscle spasm, guarding, altered posture, and functional movement restrictions as documented in today\u2019s examination.',
        a: 'The patient\u2019s clinical presentation is consistent with acute traumatic injuries sustained during the reported motor vehicle accident. Examination findings correlate with the patient\u2019s subjective complaints and mechanism of injury. The patient demonstrates pain, muscle spasm, restricted spinal motion, and functional limitations affecting activities of daily living.\n\nBased on today\u2019s evaluation, the patient\u2019s condition is directly related to the reported motor vehicle collision and requires continued conservative chiropractic management.',
        p: 'Treatment today consisted of chiropractic manipulative therapy directed to the affected spinal regions, manual therapy as indicated, therapeutic exercises, and adjunctive physiologic therapies as clinically appropriate. Home care instructions, activity modifications, and icing/stretching recommendations were reviewed.\n\nThe patient will be treated {2-3 times per week} for approximately {4-8 weeks}, with periodic re-evaluations to assess objective improvement, functional recovery, and the continued medical necessity of care. Treatment frequency will be adjusted based on clinical progress and functional improvement.\n\nThe prognosis is {good/fair/guarded} due to the acute traumatic nature of the injuries and the patient\u2019s current functional limitations. Continued conservative care is medically necessary to reduce pain, improve spinal mobility, restore function, and facilitate the patient\u2019s return to pre-injury status whenever possible.'
    },
    medicare: {
        name: 'Medicare Maintenance Care Narrative',
        text: 'The patient was seen today for maintenance chiropractic care. The patient has achieved the maximum therapeutic improvement expected from active treatment for the diagnosed spinal condition(s). The purpose of today\u2019s visit is to assist in maintaining spinal function, reducing symptom recurrence, and improving overall quality of life.\n\nThe patient reports continued benefit from periodic chiropractic care, including improved mobility, decreased stiffness, and improved ability to perform activities of daily living. Treatment today was directed toward maintaining joint mobility and reducing spinal dysfunction as documented within this encounter.\n\nThe patient understands that today\u2019s services are considered maintenance care and are not expected to produce significant functional improvement beyond the current level of recovery. Treatment was performed in accordance with the patient\u2019s ongoing maintenance care plan.',
        o: 'Objective examination reveals chronic segmental dysfunction of the cervical, thoracic, and/or lumbar spine with associated paraspinal muscle hypertonicity and tenderness. Mild to moderate restriction of spinal range of motion remains present and is consistent with the patient\u2019s established chronic condition. Palpatory findings continue to demonstrate joint fixation and soft tissue dysfunction without significant change from previous maintenance visits. Functional status remains stable with periodic chiropractic care.',
        a: 'The patient has reached maximum therapeutic improvement and is currently receiving chiropractic maintenance care to preserve spinal mobility, reduce spinal dysfunction, and maintain functional status. Although objective findings continue to demonstrate chronic spinal dysfunction, no expectation of significant additional clinical improvement exists. The patient reports subjective benefit from periodic chiropractic treatment in maintaining comfort, mobility, and quality of life. The patient understands that today\u2019s maintenance treatment is generally considered non-covered by Medicare.',
        p: 'Chiropractic manipulative therapy was performed to the documented spinal regions to maintain spinal function and mobility. The patient tolerated today\u2019s treatment without complication. Home stretching, postural awareness, hydration, ergonomic modifications, and regular exercise were reinforced. The patient will continue maintenance chiropractic care on an as-needed basis based upon symptom recurrence, functional status, and patient preference. The patient has been informed that maintenance care is generally not considered medically necessary under Medicare coverage guidelines.'
    },
    workcomp: {
        name: "Worker's Compensation Follow-Up",
        text: 'The patient presents today for follow-up evaluation and treatment related to the reported work-related injury. Subjective complaints remain consistent with the previously documented mechanism of injury and continue to affect the patient\u2019s ability to perform occupational and daily functional activities.\n\nObjective examination today demonstrates continued findings including restricted spinal motion, muscular hypertonicity, tenderness to palpation, and orthopedic findings as documented in today\u2019s examination. These findings remain consistent with the previously established diagnoses.\n\nTreatment provided today was directed toward reducing pain, improving functional mobility, restoring spinal biomechanics, and facilitating return to pre-injury work activities. The patient tolerated treatment well and demonstrates continued progress toward functional recovery.\n\nCurrent work status, restrictions, and future treatment recommendations have been updated based upon today\u2019s clinical findings.',
        o: 'Objective examination demonstrates persistent segmental dysfunction involving the affected spinal regions with associated paraspinal muscle hypertonicity, tenderness, and myofascial restriction. Active range of motion remains decreased due to pain and muscular guarding. Orthopedic and neurological findings are consistent with the documented work-related injury and correlate with the patient\u2019s reported functional limitations. The patient continues to demonstrate objective findings affecting lifting, bending, prolonged standing, sitting, walking, and other work-related activities. Today\u2019s examination findings are consistent with the accepted industrial injury and were documented in comparison with previous evaluations.',
        a: 'The patient continues to recover from the documented work-related injury and demonstrates objective improvement; however, residual pain, spinal dysfunction, and functional limitations remain. Current examination findings continue to support the accepted industrial diagnosis and indicate that additional conservative chiropractic treatment remains medically necessary to improve spinal mobility, reduce pain, restore function, and facilitate a safe return to full work activities. Prognosis remains favorable provided the patient continues to comply with the prescribed treatment plan, home exercises, and work restrictions.',
        p: 'Chiropractic manipulative therapy was performed to the clinically indicated spinal regions utilizing appropriate techniques. Manual therapy, therapeutic exercises, and adjunctive physiologic therapies were provided as indicated to improve mobility, reduce pain, and restore functional capacity. The patient tolerated today\u2019s treatment without complication. Home exercise instructions, stretching, posture correction, and ergonomic recommendations were reinforced. Current work restrictions should remain in effect until functional improvement supports progression of activity. The patient will continue treatment according to the established plan of care and will be re-evaluated periodically to assess objective improvement, functional recovery, and readiness for return to full duty.'
    },
    discharge: {
        name: 'Discharge Narrative',
        text: 'The patient has completed the prescribed course of conservative chiropractic care. A comprehensive re-evaluation was performed today, including comparison with the initial examination and subsequent progress evaluations.\n\nObjective findings demonstrate that the patient has achieved the maximum therapeutic benefit expected from conservative chiropractic treatment at this time. Although mild residual symptoms may persist, additional active treatment is not anticipated to produce significant functional improvement.\n\nThe patient has demonstrated measurable improvement in pain levels, range of motion, functional capacity, and activities of daily living. Home exercise instructions, ergonomic recommendations, and self-management strategies have been reviewed.\n\nThe patient is discharged from active care and may return for future evaluation should symptoms significantly worsen or a new injury occur.',
        o: 'Final examination demonstrates significant improvement in spinal mobility, joint function, muscular tone, and overall biomechanics compared to the initial evaluation. Active range of motion has improved with minimal discomfort at end ranges. Palpation reveals a marked reduction in muscle hypertonicity, tenderness, and segmental dysfunction. Orthopedic and neurological findings have improved or resolved and no significant functional deficits are identified that would require continued active chiropractic treatment. Objective findings support successful completion of the treatment plan.',
        a: 'The patient has achieved maximum therapeutic benefit from the current episode of care. Treatment goals have been substantially met through improvement in pain, spinal function, mobility, and overall functional capacity. At this time, additional active treatment is not medically necessary. The patient has demonstrated satisfactory clinical improvement and is appropriate for discharge from active chiropractic care. The prognosis for maintaining current functional status is good provided the patient continues with the recommended home exercise program and self-management strategies.',
        p: 'The patient is discharged from active chiropractic treatment effective today. Home exercises, stretching, strengthening activities, ergonomic recommendations, posture education, and proper body mechanics were reviewed and reinforced. The patient was instructed to continue self-management techniques and to seek re-evaluation should symptoms recur, worsen, or a new injury occur. Follow-up care may be scheduled on an as-needed basis or for wellness/maintenance care according to the patient\u2019s preferences and future clinical needs.'
    },
    wellness: {
        name: 'Wellness Visit',
        text: 'The patient presents today for wellness chiropractic care with the goal of promoting optimal spinal function, mobility, and overall musculoskeletal health. The patient reports no significant new injuries or acute exacerbations since the previous visit.\n\nToday\u2019s examination identified areas of spinal restriction and segmental dysfunction as documented within this encounter. Treatment was provided to improve joint mobility, reduce mechanical stress, and support normal neuromusculoskeletal function.\n\nThe patient continues to participate in wellness care to maintain spinal health, improve flexibility and mobility, and help reduce the likelihood of recurrent musculoskeletal dysfunction. Recommendations regarding posture, exercise, stretching, hydration, and home care were reinforced during today\u2019s visit.',
        o: 'Examination reveals mild chronic segmental dysfunction involving the cervical, thoracic, and/or lumbar spine with associated paraspinal muscle hypertonicity and minor restrictions in spinal mobility. Palpatory findings demonstrate joint fixation and soft tissue tightness consistent with routine wellness care. No significant neurological deficits or acute orthopedic findings are present. Overall posture, balance, and functional movement remain stable, with examination findings consistent with maintaining optimal biomechanical function.',
        a: 'The patient\u2019s spinal condition remains stable with periodic wellness chiropractic care. Today\u2019s examination demonstrates minor biomechanical dysfunction without evidence of acute injury or significant clinical deterioration. The patient continues to benefit from regular chiropractic adjustments to promote spinal mobility, improve posture, reduce muscular tension, and support overall musculoskeletal health. The patient demonstrates good compliance with home exercise recommendations and understands the role of wellness care in maintaining function and preventing future episodes of discomfort.',
        p: 'regions to improve joint mobility and maintain optimal spinal function. The patient tolerated today\u2019s treatment well without complication. Home stretching, strengthening exercises, posture education, ergonomic recommendations, hydration, and regular physical activity were reinforced. The patient will continue periodic wellness chiropractic visits based on individual needs and personal health goals to support long-term spinal health, mobility, and overall quality of life.'
    }
};

var _narrPanelOpen = false;
var _narrMode      = 'replace'; // 'replace' | 'prepend' | 'append'

function narrSetMode(mode) {
    _narrMode = mode;
    document.querySelectorAll('.narr-mode-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.mode === mode);
        if (b.dataset.mode === mode) {
            b.style.background = '#ede9fe'; b.style.color = '#5b21b6';
        } else {
            b.style.background = '#fff'; b.style.color = '#9ca3af';
        }
    });
}

function narrTogglePanel() {
    _narrPanelOpen ? narrClosePanel() : narrOpenPanel();
}
function narrOpenPanel() {
    var panel   = document.getElementById('narrPanel');
    var chevron = document.getElementById('narrChevron');
    var btn     = document.getElementById('narrToggleBtn');
    if (panel)   panel.classList.add('open');
    if (chevron) chevron.style.transform = 'rotate(180deg)';
    if (btn)     btn.setAttribute('aria-expanded', 'true');
    _narrPanelOpen = true;
}
function narrClosePanel() {
    var panel   = document.getElementById('narrPanel');
    var chevron = document.getElementById('narrChevron');
    var btn     = document.getElementById('narrToggleBtn');
    if (panel)   panel.classList.remove('open');
    if (chevron) chevron.style.transform = '';
    if (btn)     btn.setAttribute('aria-expanded', 'false');
    _narrPanelOpen = false;
}

// Close when clicking outside the dropdown wrap
document.addEventListener('click', function(e) {
    var wrap = document.getElementById('narrDropdownWrap');
    if (wrap && !wrap.contains(e.target) && _narrPanelOpen) narrClosePanel();
});

// ── Select a narrative — inserts text into the appropriate SOAP fields.
//    If the narrative has only a `text` key, it goes into Subjective only
//    (backward-compatible with all existing narratives). If the narrative
//    also has `o`, `a`, or `p` keys (like the PI narrative), those populate
//    Objective, Assessment, and Plan respectively. All fields respect the
//    current Replace/Prepend/Append mode and write into dataset.rec so they
//    compose correctly with dataset.gen via buildNoteValue(). ──
function narrSelect(el) {
    var key = el.dataset.narr;
    var narr = NARRATIVE_TEMPLATES[key];
    if (!narr) return;

    document.querySelectorAll('#narrList .tmpl-item').forEach(function(i) { i.classList.remove('selected'); });
    el.classList.add('selected');

    function applyToField(fieldId, content) {
        if (!content) return;
        var ta = document.getElementById(fieldId);
        if (!ta) { console.warn('[narrSelect] field not found:', fieldId); return; }
        if (_narrMode === 'replace') {
            ta.dataset.rec = content;
            ta.value = content;
        } else if (_narrMode === 'prepend') {
            var recNow = (ta.dataset.rec || ta.value || '').trim();
            var merged = recNow ? content + '\n\n' + recNow : content;
            ta.dataset.rec = merged;
            ta.value = merged;
        } else if (_narrMode === 'append') {
            var recNow = (ta.dataset.rec || ta.value || '').trim();
            var merged = recNow ? recNow + '\n\n' + content : content;
            ta.dataset.rec = merged;
            ta.value = merged;
        }
        // Dispatch an input event so any listeners (autosave, character count, etc.)
        // pick up the change, same as if the user had typed into the field.
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        top.isSoapEdit = true;
    }

    applyToField('sNote', narr.text);
    applyToField('oNote', narr.o);
    applyToField('aNote', narr.a);
    applyToField('pNote', narr.p);

    if (_narrMode === 'replace') narrClosePanel();
    // In prepend/append mode: keep panel open so user can stack multiple narratives
}

// ── Set mode (replace vs prepend vs append) ────────────────────────
function tmplSetMode(mode) {
    _tmplMode = mode;
    document.querySelectorAll('.tmpl-mode-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.mode === mode);
    });
    // Switching to replace: clear any multi-selections back to just blank
    if (mode === 'replace') {
        document.querySelectorAll('.tmpl-item').forEach(function(i) { i.classList.remove('selected'); });
        var blankItem = document.querySelector('.tmpl-item[data-tmpl="blank"]');
        if (blankItem) blankItem.classList.add('selected');
        _tmplActive = 'blank';
    }
}

// ── Open / Close / Toggle ──────────────────────────────────────────
function tmplTogglePanel() {
    _tmplPanelOpen ? tmplClosePanel() : tmplOpenPanel();
}
function tmplOpenPanel() {
    var panel   = document.getElementById('tmplPanel');
    var chevron = document.getElementById('tmplChevron');
    var btn     = document.getElementById('tmplToggleBtn');
    if (panel)   panel.classList.add('open');
    if (chevron) chevron.style.transform = 'rotate(180deg)';
    if (btn)     btn.setAttribute('aria-expanded', 'true');
    _tmplPanelOpen = true;
}
function tmplClosePanel() {
    var panel   = document.getElementById('tmplPanel');
    var chevron = document.getElementById('tmplChevron');
    var btn     = document.getElementById('tmplToggleBtn');
    if (panel)   panel.classList.remove('open');
    if (chevron) chevron.style.transform = '';
    if (btn)     btn.setAttribute('aria-expanded', 'false');
    _tmplPanelOpen = false;
}

// Close when clicking outside the dropdown wrap
document.addEventListener('click', function(e) {
    var wrap = document.getElementById('tmplDropdownWrap');
    if (wrap && !wrap.contains(e.target) && _tmplPanelOpen) tmplClosePanel();
});

// ── Tab switching ──────────────────────────────────────────────────
function tmplSwitchTab(btn) {
    document.querySelectorAll('.tmpl-tab').forEach(function(t) { t.classList.remove('active'); });
    btn.classList.add('active');
    var tab = btn.dataset.tab;
    document.getElementById('tmplListCore').style.display       = (tab === 'core')       ? '' : 'none';
    document.getElementById('tmplListAdditional').style.display = (tab === 'additional') ? '' : 'none';
}

// ── Select a template — supports multi-select; respects replace/prepend/append ──
function tmplSelect(el) {
    if (el.classList.contains('additional')) return;

    var key = el.dataset.tmpl;
    var tpl = SOAP_TEMPLATES[key];
    if (!tpl) return;

    var isAccident  = (key === 'auto');
    var isReexam    = (key === 'reexam');
    var isDiagnosis = (key === 'diagnosis');
    var isDischarge = (key === 'discharge');

    // Auto Accident is not a SOAP variant — it swaps out the entire SOAP UI for
    // the standalone Accident Form (see #accidentFormWrapper) and does not
    // participate in replace/prepend/append template stacking.
    if (isAccident) {
        document.querySelectorAll('.tmpl-item').forEach(function(i) { i.classList.remove('selected'); });
        el.classList.add('selected');
        _tmplActive = key;
        hideAllSpecialForms();
        showAccidentForm();
        updateTmplButtonLabel(tpl, false, false);
        updateCopyPreviousVisibility();
        tmplClosePanel();
        return;
    }

    // Progress Re-Exam swaps out Subjective/Objective/Assessment (and the
    // Ambient Scribe bar) for the standalone Re-Exam Form (see
    // #reexamFormWrapper), but — unlike Auto Accident — keeps the existing
    // Plan section visible so the user can set updated goals/treatment plan
    // using the same controls as a normal SOAP note.
    if (isReexam) {
        document.querySelectorAll('.tmpl-item').forEach(function(i) { i.classList.remove('selected'); });
        el.classList.add('selected');
        _tmplActive = key;
        hideAllSpecialForms();
        showReexamForm();
        updateTmplButtonLabel(tpl, false, false);
        updateCopyPreviousVisibility();
        tmplClosePanel();
        return;
    }

    // Diagnosis Summary — standalone structured form, fully replaces SOAP UI.
    if (isDiagnosis) {
        document.querySelectorAll('.tmpl-item').forEach(function(i) { i.classList.remove('selected'); });
        el.classList.add('selected');
        _tmplActive = key;
        hideAllSpecialForms();
        showDiagnosisForm();
        updateTmplButtonLabel(tpl, false, false);
        updateCopyPreviousVisibility();
        tmplClosePanel();
        return;
    }

    // Discharge Summary — standalone structured form, fully replaces SOAP UI.
    // Collapses the note into Treatment Summary / Outcomes / Final Findings /
    // Discharge Narrative, composed as paragraphs rather than labeled lines.
    if (isDischarge) {
        document.querySelectorAll('.tmpl-item').forEach(function(i) { i.classList.remove('selected'); });
        el.classList.add('selected');
        _tmplActive = key;
        hideAllSpecialForms();
        showDischargeForm();
        updateTmplButtonLabel(tpl, false, false);
        updateCopyPreviousVisibility();
        tmplClosePanel();
        return;
    }

    // Selecting any other template switches back to the normal SOAP UI if a
    // special form (Accident / Re-Exam / Diagnosis / Discharge) was active.
    hideAllSpecialForms();

    var isReplace = (_tmplMode === 'replace');
    var isPrepend = (_tmplMode === 'prepend');
    var isAppend  = (_tmplMode === 'append');

    if (isReplace) {
        // Replace mode: single selection — deselect others, select this one
        document.querySelectorAll('.tmpl-item').forEach(function(i) { i.classList.remove('selected'); });
        el.classList.add('selected');
        _tmplActive = key;
    } else {
        // Prepend / Append mode: toggle this item's selected state (multi-select)
        el.classList.toggle('selected');
        // _tmplActive tracks the last interacted key for the button label
        _tmplActive = el.classList.contains('selected') ? key : 'blank';
    }

    var fields = { s: 'sNote', o: 'oNote', a: 'aNote', p: 'pNote' };

    Object.keys(fields).forEach(function(k) {
        var ta = document.getElementById(fields[k]);
        if (!ta) return;
        var content = tpl[k] || '';
        if (!content) return;

        // Store template text in dataset.rec so setGeneratedContent() rebuilds
        // as rec+gen and never wipes the template when a dropdown changes.
        if (isReplace) {
            ta.dataset.rec = content;
            ta.dataset.gen = '';
            ta.value       = content;
        } else if (isPrepend) {
            var recNow     = (ta.dataset.rec || '').trim();
            ta.dataset.rec = recNow ? content + '\n\n' + recNow : content;
            ta.value       = buildNoteValue(ta.dataset.rec, ta.dataset.gen || '');
        } else if (isAppend) {
            var recNow     = (ta.dataset.rec || '').trim();
            ta.dataset.rec = recNow ? recNow + '\n\n' + content : content;
            ta.value       = buildNoteValue(ta.dataset.rec, ta.dataset.gen || '');
        }
    });

    updateTmplButtonLabel(tpl, isPrepend, isAppend);
    updateCopyPreviousVisibility();

    if (isReplace) tmplClosePanel();
    // In prepend/append mode: keep panel open so user can stack multiple templates
}

// Copy Previous Note only makes sense for an ordinary SOAP Note — it copies
// the prior visit's S/O/A/P text into the current note, which doesn't apply
// to (and would be confusing for) the structured-form templates (Auto
// Accident, Re-Exam, Diagnosis Summary, Discharge Summary) or, in prepend/
// append mode, anything other than SOAP Note ('blank') being the active
// selection. Hidden rather than disabled, since it's simply not relevant
// outside that one context.
function updateCopyPreviousVisibility() {
    var wrap = document.getElementById('tmplCopyPreviousWrap');
    if (!wrap) return;
    wrap.style.display = (_tmplActive === 'blank') ? '' : 'none';
}

function updateTmplButtonLabel(tpl, isPrepend, isAppend) {
    var btnLabel = document.querySelector('#tmplToggleBtn');
    if (!btnLabel) return;
    var icon    = btnLabel.querySelector('svg:first-child');
    var chevron = document.getElementById('tmplChevron');
    btnLabel.innerHTML = '';
    if (icon) btnLabel.appendChild(icon.cloneNode(true));
    var prefix = isPrepend ? '↑ ' : (isAppend ? '+ ' : '');
    var label  = (tpl.name === 'SOAP Note') ? 'Templates' : prefix + tpl.name;
    btnLabel.appendChild(document.createTextNode(label));
    if (chevron) btnLabel.appendChild(chevron.cloneNode(true));
}

// ══════════════════════════════════════════════════════════════════
//  ACCIDENT FORM — full swap in/out of the SOAP UI
// ══════════════════════════════════════════════════════════════════

// ── Shared: hide every special structured form + restore normal SOAP UI ──
// Called before showing any one special form, and when switching back to a
// regular SOAP template, so only one special form (or none) is ever visible
// at a time regardless of how many exist.
function hideAllSpecialForms() {
    hideAccidentForm();
    hideReexamForm();
    hideDiagnosisForm();
    hideDischargeForm();
}

function showAccidentForm() {
    var accident = document.getElementById('accidentFormWrapper');
    var soapBody = document.getElementById('soapFormBody');
    var specialtyBar = document.getElementById('specialtyBar');
    var transcript = document.getElementById('transcriptBox');
    if (accident) accident.style.display = '';
    if (soapBody) soapBody.style.display = 'none';
    if (specialtyBar) specialtyBar.style.display = 'none';
    if (transcript) transcript.style.display = 'none';
    updateAutoAccidentNote();
}

function hideAccidentForm() {
    var accident = document.getElementById('accidentFormWrapper');
    var soapBody = document.getElementById('soapFormBody');
    var specialtyBar = document.getElementById('specialtyBar');
    var transcript = document.getElementById('transcriptBox');
    if (accident) accident.style.display = 'none';
    if (soapBody) soapBody.style.display = '';
    if (specialtyBar) specialtyBar.style.display = '';
    if (transcript) transcript.style.display = '';
}

// ── Button-grid pickers (single-select by default, multi-select if
//    data-multi="true" — e.g. "Reason for Visit" allows several) ──────────
var aaState = {}; // field name -> string (single) or array (multi)

document.addEventListener('click', function(e) {
    var btn = e.target.closest('.aa-opt-btn');
    if (!btn) return;
    var grid = btn.closest('.aa-btn-grid');
    var field = grid.dataset.field;
    var val = btn.dataset.val;
    var isMulti = grid.dataset.multi === 'true';

    if (isMulti) {
        if (!Array.isArray(aaState[field])) aaState[field] = [];
        var idx = aaState[field].indexOf(val);
        if (idx === -1) {
            aaState[field].push(val);
            btn.classList.add('selected');
        } else {
            aaState[field].splice(idx, 1);
            btn.classList.remove('selected');
        }
    } else {
        // Single-select: clicking the already-selected option deselects it
        var wasSelected = btn.classList.contains('selected');
        grid.querySelectorAll('.aa-opt-btn').forEach(function(b) { b.classList.remove('selected'); });
        if (wasSelected) {
            aaState[field] = '';
        } else {
            btn.classList.add('selected');
            aaState[field] = val;
        }
    }

    // Route to the correct form's note builder based on which wrapper this
    // button grid actually lives in — these widgets are shared markup
    // (.aa-btn-grid/.aa-opt-btn) reused across Accident, Re-Exam, Diagnosis
    // Summary, and Discharge Summary, so a single hardcoded update call here
    // would silently refresh the wrong form's preview.
    if (grid.closest('#accidentFormWrapper')) {
        updateAutoAccidentNote();
    } else if (grid.closest('#reexamFormWrapper')) {
        updateReexamNote();
    } else if (grid.closest('#diagnosisFormWrapper')) {
        updateDiagnosisNote();
    } else if (grid.closest('#dischargeFormWrapper')) {
        updateDischargeNote();
    }
});

function aaClearAll() {
    // Clear only the aa_-namespaced button-grid selections, not aaState
    // entries belonging to Re-Exam / Diagnosis Summary / Discharge Summary.
    Object.keys(aaState).forEach(function(key) {
        if (key.indexOf('aa_') === 0) delete aaState[key];
    });
    _aaInjuries = [];
    document.querySelectorAll('#accidentFormWrapper .aa-opt-btn.selected').forEach(function(b) { b.classList.remove('selected'); });
    document.querySelectorAll('#qtags-auto-accident .qtag').forEach(function(tag) { tag.classList.remove('selected'); });
    ['aa_date','aa_treatment_began_date','aa_first_visit_date'].forEach(function(id) {
        var el = document.getElementById(id); if (el) el.value = '';
    });
    ['aa_accident_notes','aa_treatment_notes','aa_treatment_since_other','aa_visit_reason_other'].forEach(function(id) {
        var el = document.getElementById(id); if (el) el.value = '';
    });
    updateAutoAccidentNote();
}

// ── Auto Accident — injury quick-tags (Reported Injuries) ──────────
var _aaInjuries = []; // selected injury tags, in click order

function aaInjuryTag(label) {
    var i = _aaInjuries.indexOf(label);
    if (i === -1) {
        _aaInjuries.push(label);
    } else {
        _aaInjuries.splice(i, 1); // clicking again removes it
    }
    document.querySelectorAll('#qtags-auto-accident .qtag').forEach(function(tag) {
        if (tag.textContent.trim() === '+ ' + label.toLowerCase()) {
            tag.classList.toggle('selected', _aaInjuries.indexOf(label) !== -1);
        }
    });
    updateAutoAccidentNote();
}

function aaClearInjuries() {
    _aaInjuries = [];
    document.querySelectorAll('#qtags-auto-accident .qtag').forEach(function(tag) {
        tag.classList.remove('selected');
    });
    updateAutoAccidentNote();
}

// ── Date formatting helper ──────────────────────────────────────────
function aaFmtDate(isoDate) {
    if (!isoDate) return '';
    var d = new Date(isoDate + 'T00:00:00');
    if (isNaN(d)) return '';
    return d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
}

// ── Structured note builder ──────────────────────────────────────────
// Serializes every Accident Form selection into a clean, readable text
// block — labeled lines rather than a forced paragraph — since this is
// a structured intake form, not a narrative SOAP note. This is what
// actually gets written into the hidden `subjective` field on save.
function buildAccidentNote() {
    var v  = function(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
    var s  = function(field) { return aaState[field] || ''; };
    var m  = function(field) { return Array.isArray(aaState[field]) ? aaState[field] : []; };

    var lines = [];

    lines.push('═══ AUTOMOBILE ACCIDENT — DETAILS ═══');
    if (v('aa_date'))               lines.push('Date of Accident: ' + aaFmtDate(v('aa_date')));
    if (s('aa_position'))           lines.push('Position in Vehicle: ' + s('aa_position'));
    if (s('aa_restraint'))          lines.push('Seat Restraint: ' + s('aa_restraint'));
    if (s('aa_vehicle_type'))       lines.push('Type of Vehicle: ' + s('aa_vehicle_type'));
    if (s('aa_impact_part'))        lines.push('Part of Vehicle Impacted First: ' + s('aa_impact_part'));
    if (s('aa_impact_time'))        lines.push('Vehicle Status at Time of Impact: ' + s('aa_impact_time'));
    if (s('aa_secondary_impact'))   lines.push('Secondary Impact Reported: ' + s('aa_secondary_impact'));
    if (s('aa_direction_facing'))   lines.push('Direction Facing at Impact: ' + s('aa_direction_facing'));
    if (s('aa_brace'))              lines.push('Brace for Impact: ' + s('aa_brace'));
    if (s('aa_road_conditions'))    lines.push('Road Conditions: ' + s('aa_road_conditions'));
    if (s('aa_visibility'))         lines.push('Visibility: ' + s('aa_visibility'));
    if (s('aa_airbag'))             lines.push('Air Bags Deployed: ' + s('aa_airbag'));
    if (s('aa_loc'))                lines.push('Loss of Consciousness: ' + s('aa_loc'));
    if (v('aa_accident_notes'))     lines.push('Accident Notes: ' + v('aa_accident_notes'));

    lines.push('');
    lines.push('═══ POST ACCIDENT TREATMENT ═══');
    if (v('aa_treatment_began_date')) lines.push('Date Post Accident Treatment Began: ' + aaFmtDate(v('aa_treatment_began_date')));
    if (s('aa_went_to'))              lines.push('Where Patient Went After Accident: ' + s('aa_went_to'));
    if (s('aa_when_went'))            lines.push('When Patient Went: ' + s('aa_when_went'));
    if (s('aa_transport'))            lines.push('How Patient Got There: ' + s('aa_transport'));
    if (s('aa_condition_since'))      lines.push('Condition Since Accident: ' + s('aa_condition_since'));

    var treatSince = s('aa_treatment_since');
    var treatOther = v('aa_treatment_since_other');
    if (treatSince || treatOther) {
        lines.push('Treatment Since Accident: ' + [treatSince, treatOther].filter(Boolean).join(' — '));
    }

    if (v('aa_first_visit_date'))     lines.push('Date First Visited (This Office): ' + aaFmtDate(v('aa_first_visit_date')));

    var reasons = m('aa_visit_reason');
    var reasonOther = v('aa_visit_reason_other');
    if (reasons.length || reasonOther) {
        lines.push('Reason for Visit: ' + reasons.concat(reasonOther ? [reasonOther] : []).join(', '));
    }

    if (_aaInjuries.length)           lines.push('Reported Injuries: ' + _aaInjuries.join(', '));
    if (v('aa_treatment_notes'))      lines.push('Treatment Notes: ' + v('aa_treatment_notes'));

    return lines.join('\n');
}

function updateAutoAccidentNote() {
    var note = buildAccidentNote();
    var preview = document.getElementById('aaNotePreview');
    if (preview) preview.value = note;
}

// ── Submit-time sync ──────────────────────────────────────────────
// save.php only persists the subjective/objective/assessment/plan textareas
// (name="subjective" etc., id="sNote" etc.) — there's no dedicated accident-
// fields table behind this form. When the Accident Form is the active mode,
// write the fully structured note into the real `sNote` field (which carries
// the name="subjective" attribute the backend reads) right before submit, and
// leave objective/assessment/plan blank since this is an intake form, not a
// SOAP note. The visible Accident Form UI is what the user interacts with;
// this just makes sure what they entered actually reaches the database.
function prepareAccidentSubmit() {
    var accident = document.getElementById('accidentFormWrapper');
    var isAccidentActive = accident && accident.style.display !== 'none';
    if (!isAccidentActive) return;

    var sNote = document.getElementById('sNote');
    if (sNote) {
        sNote.dataset.rec = buildAccidentNote();
        sNote.dataset.gen = '';
        sNote.dataset.stdGen = '';
        sNote.dataset.aaGen = '';
        sNote.value = sNote.dataset.rec;
    }
    // Objective/Assessment/Plan intentionally left as-is (typically blank) —
    // this is a structured intake form, not a SOAP note.
}

// ══════════════════════════════════════════════════════════════════
//  RE-EXAM FORM — swaps S/O/A for Progress Tracking / Outcome Measures /
//  Comparison Columns / Prognosis, while leaving Plan visible and untouched
//  so the user can set updated goals/treatment using the normal Plan
//  controls (same pattern as a regular SOAP note's Plan section).
// ══════════════════════════════════════════════════════════════════

function showReexamForm() {
    var reexam = document.getElementById('reexamFormWrapper');
    var soa = document.getElementById('soapSOAWrapper');
    var specialtyBar = document.getElementById('specialtyBar');
    var transcript = document.getElementById('transcriptBox');
    var planFieldset = document.getElementById('planFieldset');
    if (reexam) reexam.style.display = '';
    if (soa) soa.style.display = 'none';
    if (specialtyBar) specialtyBar.style.display = 'none';
    if (transcript) transcript.style.display = 'none';
    // Re-Exam has its own lightweight Plan section (Updated Treatment Goals,
    // Frequency/Duration/Next Visit, Plan Notes) above the Note Preview — the
    // regular SOAP Plan fieldset is hidden while Re-Exam is active so there's
    // only ever one Plan visible at a time. Its real `pNote`/`name="plan"`
    // field is left untouched, just not shown.
    if (planFieldset) planFieldset.style.display = 'none';
    updateReexamNote();
}

function hideReexamForm() {
    var reexam = document.getElementById('reexamFormWrapper');
    var soa = document.getElementById('soapSOAWrapper');
    var specialtyBar = document.getElementById('specialtyBar');
    var transcript = document.getElementById('transcriptBox');
    var planFieldset = document.getElementById('planFieldset');
    if (reexam) reexam.style.display = 'none';
    if (soa) soa.style.display = '';
    if (specialtyBar) specialtyBar.style.display = '';
    if (transcript) transcript.style.display = '';
    if (planFieldset) planFieldset.style.display = '';
}

function reexamClearAll() {
    // Clear only the rx_-namespaced button-grid selections, not aaState
    // entries belonging to the Accident Form's own fields.
    Object.keys(aaState).forEach(function(key) {
        if (key.indexOf('rx_') === 0) delete aaState[key];
    });
    document.querySelectorAll('#reexamFormWrapper .aa-opt-btn.selected').forEach(function(b) { b.classList.remove('selected'); });
    document.querySelectorAll('#reexamFormWrapper input, #reexamFormWrapper textarea').forEach(function(el) {
        // Checkboxes (the exercise picker) need .checked reset, not .value —
        // setting .value='' on a checkbox has no effect on its checked state.
        if (el.type === 'checkbox') { el.checked = false; } else { el.value = ''; }
    });
    document.querySelectorAll('#reexamFormWrapper select').forEach(function(el) { el.selectedIndex = 0; });
    rxSyncExercises(); // rebuild the "Selected Exercises" panel now that all boxes are unchecked
    updateReexamNote();
}

// ── Re-Exam Plan — Corrective Exercises widget ──────────────────────
// Parallels syncExercises()/clearExercises()/filterExercises()/
// removeExercise() (the original Plan section's exercise picker), scoped to
// the rx_-prefixed IDs so this can coexist with the original widget, which
// stays present in the DOM (just hidden) while Re-Exam is active.
function rxSyncExercises() {
    var checkboxes = document.querySelectorAll('#rx_exerciseList input[type="checkbox"]:checked');
    var container = document.getElementById('rx_selectedExercises');
    var noMsg = document.getElementById('rx_noExMsg');

    var existing = container.querySelectorAll('.sel-ex-row');
    existing.forEach(function(el) { el.remove(); });

    if (checkboxes.length === 0) {
        noMsg.style.display = 'block';
    } else {
        noMsg.style.display = 'none';
        checkboxes.forEach(function(cb) {
            var parts = cb.value.split('|');
            var name = parts[0]; var detail = parts[1] || '';
            var row = document.createElement('div');
            row.className = 'sel-ex-row';
            row.innerHTML = '<div><div class="sel-ex-name">' + name + '</div><div class="sel-ex-detail">' + detail + '</div></div>' +
                '<button type="button" class="sel-ex-remove" onclick="rxRemoveExercise(this, this.dataset.val)">×</button>';
            row.querySelector('button').dataset.val = cb.value;
            container.appendChild(row);
        });
    }
    updateReexamNote();
}

function rxRemoveExercise(btn, valStr) {
    if (!valStr) { valStr = btn.dataset ? btn.dataset.val : ''; }
    var cb = document.querySelector('#rx_exerciseList input[value="' + valStr + '"]');
    if (cb) { cb.checked = false; }
    rxSyncExercises();
}

function rxClearExercises() {
    document.querySelectorAll('#rx_exerciseList input[type="checkbox"]').forEach(function(cb) { cb.checked = false; });
    rxSyncExercises();
}

function rxFilterExercises(query) {
    var q = query.toLowerCase();
    document.querySelectorAll('#rx_exerciseList .ex-item').forEach(function(item) {
        var name = item.querySelector('.ex-name').textContent.toLowerCase();
        var sub  = item.querySelector('.ex-sub').textContent.toLowerCase();
        item.style.display = (name.includes(q) || sub.includes(q)) ? '' : 'none';
    });
}

// ══════════════════════════════════════════════════════════════════
//  DIAGNOSIS SUMMARY FORM — full swap in/out of the SOAP UI, same
//  pattern as the Accident Form (replaces S/O/A/P entirely).
// ══════════════════════════════════════════════════════════════════

function showDiagnosisForm() {
    var diagnosis = document.getElementById('diagnosisFormWrapper');
    var soapBody = document.getElementById('soapFormBody');
    var specialtyBar = document.getElementById('specialtyBar');
    var transcript = document.getElementById('transcriptBox');
    if (diagnosis) diagnosis.style.display = '';
    if (soapBody) soapBody.style.display = 'none';
    if (specialtyBar) specialtyBar.style.display = 'none';
    if (transcript) transcript.style.display = 'none';
    updateDiagnosisNote();
}

function hideDiagnosisForm() {
    var diagnosis = document.getElementById('diagnosisFormWrapper');
    var soapBody = document.getElementById('soapFormBody');
    var specialtyBar = document.getElementById('specialtyBar');
    var transcript = document.getElementById('transcriptBox');
    if (diagnosis) diagnosis.style.display = 'none';
    if (soapBody) soapBody.style.display = '';
    if (specialtyBar) specialtyBar.style.display = '';
    if (transcript) transcript.style.display = '';
}

function dxClearAll() {
    Object.keys(aaState).forEach(function(key) {
        if (key.indexOf('dx_') === 0) delete aaState[key];
    });
    document.querySelectorAll('#diagnosisFormWrapper .aa-opt-btn.selected').forEach(function(b) { b.classList.remove('selected'); });
    document.querySelectorAll('#diagnosisFormWrapper input, #diagnosisFormWrapper textarea').forEach(function(el) { el.value = ''; });
    updateDiagnosisNote();
}

// ══════════════════════════════════════════════════════════════════
//  DISCHARGE SUMMARY FORM — full swap in/out of the SOAP UI, same
//  pattern as the Accident Form (replaces S/O/A/P entirely).
// ══════════════════════════════════════════════════════════════════

function showDischargeForm() {
    var discharge = document.getElementById('dischargeFormWrapper');
    var soapBody = document.getElementById('soapFormBody');
    var specialtyBar = document.getElementById('specialtyBar');
    var transcript = document.getElementById('transcriptBox');
    if (discharge) discharge.style.display = '';
    if (soapBody) soapBody.style.display = 'none';
    if (specialtyBar) specialtyBar.style.display = 'none';
    if (transcript) transcript.style.display = 'none';
    updateDischargeNote();
}

function hideDischargeForm() {
    var discharge = document.getElementById('dischargeFormWrapper');
    var soapBody = document.getElementById('soapFormBody');
    var specialtyBar = document.getElementById('specialtyBar');
    var transcript = document.getElementById('transcriptBox');
    if (discharge) discharge.style.display = 'none';
    if (soapBody) soapBody.style.display = '';
    if (specialtyBar) specialtyBar.style.display = '';
    if (transcript) transcript.style.display = '';
}

function dcClearAll() {
    Object.keys(aaState).forEach(function(key) {
        if (key.indexOf('dc_') === 0) delete aaState[key];
    });
    document.querySelectorAll('#dischargeFormWrapper .aa-opt-btn.selected').forEach(function(b) { b.classList.remove('selected'); });
    document.querySelectorAll('#dischargeFormWrapper input, #dischargeFormWrapper textarea').forEach(function(el) { el.value = ''; });
    updateDischargeNote();
}

// ── Structured note builder ──────────────────────────────────────────
// Same pattern as buildAccidentNote(): plain labeled lines rather than a
// forced paragraph, written into the `subjective` field on save. Plan
// keeps using its own real pNote textarea/controls as normal.
// Composes a flowing prose paragraph from the structured Progress Tracking /
// Outcome Measures / Comparison Columns / Prognosis selections, rather than
// labeled lines — this is read by staff and payers the same way the original
// static SOAP templates were, so it needs to read as a clinical narrative.
function buildReexamNote() {
    var v = function(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
    var s = function(field) { return aaState[field] || ''; };
    var m = function(field) { return Array.isArray(aaState[field]) ? aaState[field] : []; };

    var sentences = [];

    // ── Progress paragraph ──
    var p1 = 'Patient presents today for re-evaluation of their current treatment plan.';
    if (s('rx_progress')) {
        var progressPhrase = {
            'Significant Improvement': 'Patient reports significant improvement since the last visit',
            'Mild Improvement': 'Patient reports mild improvement since the last visit',
            'No Change': 'Patient reports no significant change in symptoms since the last visit',
            'Worsened': 'Patient reports worsening of symptoms since the last visit'
        }[s('rx_progress')];
        if (progressPhrase) p1 += ' ' + progressPhrase + '.';
    }
    if (s('rx_compliance')) {
        var compliancePhrase = {
            'Fully Compliant': 'Patient has been fully compliant with the home exercise program and recommended home care.',
            'Partially Compliant': 'Patient reports partial compliance with the home exercise program and recommended home care.',
            'Non-Compliant': 'Patient reports non-compliance with the home exercise program and recommended home care.'
        }[s('rx_compliance')];
        if (compliancePhrase) p1 += ' ' + compliancePhrase;
    }
    if (s('rx_new_issue') && s('rx_new_issue') !== 'None Reported') {
        p1 += ' ' + s('rx_new_issue') + ' since the previous evaluation.';
    } else {
        p1 += ' No new injury, accident, or significant change in health status reported since the previous evaluation.';
    }
    if (v('rx_progress_notes')) p1 += ' ' + v('rx_progress_notes');
    sentences.push(p1);

    // ── Outcome measures paragraph ──
    var p2parts = [];
    if (v('rx_pain_current')) p2parts.push('current pain is rated ' + v('rx_pain_current') + '/10');
    if (v('rx_pct_improvement')) p2parts.push('patient reports approximately ' + v('rx_pct_improvement') + '% overall improvement since beginning treatment');
    if (p2parts.length) {
        sentences.push('On today\u2019s evaluation, ' + p2parts.join(', and ') + '.');
    }
    if (v('rx_outcome_tool')) {
        var scoreParts = [];
        if (v('rx_outcome_score_initial')) scoreParts.push('an initial score of ' + v('rx_outcome_score_initial'));
        if (v('rx_outcome_score_current')) scoreParts.push('a current score of ' + v('rx_outcome_score_current'));
        var scoreText = scoreParts.length ? ' with ' + scoreParts.join(' compared to ') : '';
        sentences.push('Outcome was assessed using the ' + v('rx_outcome_tool') + scoreText + '.');
    }

    // ── Comparison paragraph (Initial vs. Current) ──
    var cmpRows = [
        ['pain level', 'rx_cmp_pain_initial', 'rx_cmp_pain_current'],
        ['range of motion', 'rx_cmp_rom_initial', 'rx_cmp_rom_current'],
        ['functional capacity', 'rx_cmp_function_initial', 'rx_cmp_function_current'],
        ['orthopedic and neurological findings', 'rx_cmp_findings_initial', 'rx_cmp_findings_current']
    ];
    var cmpPhrases = [];
    cmpRows.forEach(function(row) {
        var initial = v(row[1]);
        var current = v(row[2]);
        if (initial || current) {
            cmpPhrases.push(row[0] + ' has changed from ' + (initial || 'baseline not recorded')
                + ' at initial evaluation to ' + (current || 'not yet reassessed') + ' today');
        }
    });
    if (cmpPhrases.length) {
        sentences.push('Compared to the initial evaluation, ' + cmpPhrases.join('; ') + '.');
    }

    // ── Prognosis paragraph ──
    var p4parts = [];
    if (s('rx_prognosis')) p4parts.push('Prognosis is ' + s('rx_prognosis').toLowerCase() + '.');
    if (s('rx_necessity')) {
        var necessityPhrase = {
            'Supported — Continue Active Care': 'Continued active treatment remains medically necessary based on objective findings and functional limitations.',
            'Supported — Transition to Maintenance': 'Medical necessity supports transitioning the patient to a maintenance care plan at this time.',
            'Not Supported — Discharge': 'Medical necessity for continued active care is not supported, and discharge from active treatment is recommended.'
        }[s('rx_necessity')];
        if (necessityPhrase) p4parts.push(necessityPhrase);
    }
    if (v('rx_prognosis_notes')) p4parts.push(v('rx_prognosis_notes'));
    if (p4parts.length) sentences.push(p4parts.join(' '));

    // ── Plan paragraph ── (Re-Exam's own Plan section, above Note Preview —
    // the regular SOAP Plan fieldset is hidden while Re-Exam is active, see
    // showReexamForm()/hideReexamForm(), so updated goals/treatment plan are
    // captured here instead and folded into this same structured note.)
    var goals = m('rx_plan_goals');
    var p5parts = [];
    if (goals.length) {
        var goalsJoined = goals.length > 1
            ? goals.slice(0, -1).join(', ') + ', and ' + goals[goals.length - 1]
            : goals[0];
        p5parts.push('Updated treatment goals include ' + goalsJoined + '.');
    }
    var freqParts = [];
    if (v('rx_plan_frequency')) freqParts.push('a frequency of ' + v('rx_plan_frequency'));
    if (v('rx_plan_duration'))  freqParts.push('a duration of ' + v('rx_plan_duration'));
    if (freqParts.length) {
        p5parts.push('Updated plan calls for ' + freqParts.join(' over ') + '.');
    }
    if (v('rx_plan_next_visit')) p5parts.push('Next visit plan: ' + v('rx_plan_next_visit').toLowerCase() + '.');

    // Selected corrective exercises (see rxSyncExercises())
    var rxExChecked = Array.from(document.querySelectorAll('#rx_exerciseList input[type="checkbox"]:checked'));
    if (rxExChecked.length) {
        var exNames = rxExChecked.map(function(cb) { return cb.value.split('|')[0]; });
        var exJoined = exNames.length > 1
            ? exNames.slice(0, -1).join(', ') + ', and ' + exNames[exNames.length - 1]
            : exNames[0];
        p5parts.push('Corrective exercises prescribed: ' + exJoined + '.');
    }

    if (v('rx_plan_notes')) p5parts.push(v('rx_plan_notes'));
    if (p5parts.length) sentences.push(p5parts.join(' '));

    return clean(sentences.join(' '));
}

function updateReexamNote() {
    var note = buildReexamNote();
    var preview = document.getElementById('rxNotePreview');
    if (preview) preview.value = note;
}

// ── Submit-time sync ──────────────────────────────────────────────
// Writes the structured Re-Exam note into the real `sNote` field (same
// approach as the Accident Form) on save. The regular SOAP Plan fieldset is
// hidden while Re-Exam is active (see showReexamForm()) — Re-Exam has its
// own lightweight Plan section instead (Updated Treatment Goals, Frequency/
// Duration/Next Visit, Plan Notes), composed into this same note by
// buildReexamNote() rather than written into the real `plan` field.
function prepareReexamSubmit() {
    var reexam = document.getElementById('reexamFormWrapper');
    var isReexamActive = reexam && reexam.style.display !== 'none';
    if (!isReexamActive) return;

    var sNote = document.getElementById('sNote');
    if (sNote) {
        sNote.dataset.rec = buildReexamNote();
        sNote.dataset.gen = '';
        sNote.dataset.stdGen = '';
        sNote.dataset.aaGen = '';
        sNote.value = sNote.dataset.rec;
    }
    // Objective/Assessment/Plan intentionally left blank — Subjective carries
    // the full structured Re-Exam summary, including the Plan paragraph.
}

// ══════════════════════════════════════════════════════════════════
//  DIAGNOSIS SUMMARY — paragraph builder
// ══════════════════════════════════════════════════════════════════

function buildDiagnosisNote() {
    var v = function(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
    var s = function(field) { return aaState[field] || ''; };
    var m = function(field) { return Array.isArray(aaState[field]) ? aaState[field] : []; };

    var sentences = [];

    var regions = m('dx_region');
    var p1 = 'Clinical findings are consistent with a diagnosis of';
    if (s('dx_category')) p1 += ' ' + s('dx_category').toLowerCase();
    if (regions.length) {
        var joined = regions.length > 1
            ? regions.slice(0, -1).join(', ').toLowerCase() + ', and ' + regions[regions.length - 1].toLowerCase()
            : regions[0].toLowerCase();
        p1 += ' involving the ' + joined;
    }
    p1 += '.';
    sentences.push(p1);

    var p2parts = [];
    if (s('dx_severity')) p2parts.push('classified as ' + s('dx_severity').toLowerCase() + ' in severity');
    if (s('dx_chronicity')) p2parts.push('presenting as ' + s('dx_chronicity').toLowerCase());
    if (p2parts.length) sentences.push('The condition is ' + p2parts.join(' and ') + '.');

    var findings = m('dx_findings');
    if (findings.length) {
        var fJoined = findings.length > 1
            ? findings.slice(0, -1).join(', ').toLowerCase() + ', and ' + findings[findings.length - 1].toLowerCase()
            : findings[0].toLowerCase();
        sentences.push('This diagnosis is supported by ' + fJoined + '.');
    }

    if (v('dx_narrative')) sentences.push(v('dx_narrative'));

    if (!sentences.length) return '';
    return clean(sentences.join(' '));
}

function updateDiagnosisNote() {
    var note = buildDiagnosisNote();
    var preview = document.getElementById('dxNotePreview');
    if (preview) preview.value = note;
}

// ── Submit-time sync ──────────────────────────────────────────────
function prepareDiagnosisSubmit() {
    var diagnosis = document.getElementById('diagnosisFormWrapper');
    var isActive = diagnosis && diagnosis.style.display !== 'none';
    if (!isActive) return;

    var sNote = document.getElementById('sNote');
    if (sNote) {
        sNote.dataset.rec = buildDiagnosisNote();
        sNote.dataset.gen = '';
        sNote.dataset.stdGen = '';
        sNote.dataset.aaGen = '';
        sNote.value = sNote.dataset.rec;
    }
}

// ══════════════════════════════════════════════════════════════════
//  DISCHARGE SUMMARY — paragraph builder
//  Collapses the note into Treatment Summary, Outcomes, Final Findings,
//  and Discharge Narrative — each rendered as its own flowing paragraph.
// ══════════════════════════════════════════════════════════════════

function buildDischargeNote() {
    var v = function(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; };
    var s = function(field) { return aaState[field] || ''; };
    var m = function(field) { return Array.isArray(aaState[field]) ? aaState[field] : []; };

    var paragraphs = [];

    // ── Treatment Summary ──
    var ts = [];
    var visitParts = [];
    if (v('dc_total_visits')) visitParts.push('a total of ' + v('dc_total_visits') + ' visits');
    if (v('dc_initial_date') && v('dc_discharge_date')) {
        visitParts.push('from ' + aaFmtDate(v('dc_initial_date')) + ' through ' + aaFmtDate(v('dc_discharge_date')));
    } else if (v('dc_initial_date')) {
        visitParts.push('beginning ' + aaFmtDate(v('dc_initial_date')));
    } else if (v('dc_discharge_date')) {
        visitParts.push('through discharge on ' + aaFmtDate(v('dc_discharge_date')));
    }
    var tsOpening = 'Patient completed a course of chiropractic care';
    if (visitParts.length) tsOpening += ' totaling ' + visitParts.join(', ');
    tsOpening += '.';
    ts.push(tsOpening);

    var modalities = m('dc_modalities');
    if (modalities.length) {
        var modJoined = modalities.length > 1
            ? modalities.slice(0, -1).join(', ') + ', and ' + modalities[modalities.length - 1]
            : modalities[0];
        ts.push('Treatment provided included ' + modJoined + '.');
    }
    if (v('dc_treatment_notes')) ts.push(v('dc_treatment_notes'));
    if (ts.length) paragraphs.push('Treatment Summary: ' + ts.join(' '));

    // ── Outcomes ──
    var oc = [];
    if (v('dc_pain_initial') || v('dc_pain_discharge')) {
        oc.push('Pain improved from ' + (v('dc_pain_initial') || '—') + '/10 at the start of care to '
            + (v('dc_pain_discharge') || '—') + '/10 at discharge.');
    }
    if (s('dc_outcome')) oc.push('Overall functional outcome is best described as ' + s('dc_outcome').toLowerCase() + '.');
    if (s('dc_goals_met')) oc.push('Treatment goals: ' + s('dc_goals_met').toLowerCase() + '.');
    if (oc.length) paragraphs.push('Outcomes: ' + oc.join(' '));

    // ── Final Findings ──
    var ff = [];
    if (s('dc_rom')) ff.push('Range of motion at discharge is ' + s('dc_rom').toLowerCase() + '.');
    if (s('dc_residual')) ff.push(s('dc_residual') + ' noted on final examination.');
    if (v('dc_findings_notes')) ff.push(v('dc_findings_notes'));
    if (ff.length) paragraphs.push('Final Findings: ' + ff.join(' '));

    // ── Discharge Narrative ──
    var dn = [];
    if (s('dc_reason')) dn.push('Patient is being discharged from active care due to: ' + s('dc_reason').toLowerCase() + '.');
    var recommend = m('dc_recommend');
    if (recommend.length) {
        var recJoined = recommend.length > 1
            ? recommend.slice(0, -1).join(', ').toLowerCase() + ', and ' + recommend[recommend.length - 1].toLowerCase()
            : recommend[0].toLowerCase();
        dn.push('Recommendations at discharge include ' + recJoined + '.');
    }
    if (v('dc_narrative_notes')) dn.push(v('dc_narrative_notes'));
    if (dn.length) paragraphs.push('Discharge Narrative: ' + dn.join(' '));

    if (!paragraphs.length) return '';
    return paragraphs.map(clean).join('\n\n');
}

function updateDischargeNote() {
    var note = buildDischargeNote();
    var preview = document.getElementById('dcNotePreview');
    if (preview) preview.value = note;
}

// ── Submit-time sync ──────────────────────────────────────────────
function prepareDischargeSubmit() {
    var discharge = document.getElementById('dischargeFormWrapper');
    var isActive = discharge && discharge.style.display !== 'none';
    if (!isActive) return;

    var sNote = document.getElementById('sNote');
    if (sNote) {
        sNote.dataset.rec = buildDischargeNote();
        sNote.dataset.gen = '';
        sNote.dataset.stdGen = '';
        sNote.dataset.aaGen = '';
        sNote.value = sNote.dataset.rec;
    }
}


// ── Copy previous note ─────────────────────────────────────────────
function tmplCopyPrevious() {
    var pidInput        = document.querySelector('input[name="pid"]');
    var idInput         = document.querySelector('input[name="id"]');
    var csrfInput       = document.querySelector('input[name="csrf_token_form"]');
    var formActionMatch = document.querySelector('form[name="soap"]');

    if (!pidInput || !pidInput.value) {
        alert('Could not determine patient ID.');
        return;
    }

    var pid        = pidInput.value;
    var currentId  = idInput        ? idInput.value   : 0;
    var csrfToken  = csrfInput      ? csrfInput.value : '';

    // Build endpoint using the top frame's origin — the SOAP form lives in an iframe
    // so window.location.origin may point to a sub-frame path. top.location gives the real host.
    var origin = '';
    try { origin = window.top.location.origin; } catch(e) {}
    if (!origin) origin = window.location.origin;
    var endpoint = origin + '/interface/forms/soap/get_last_soap.php';

    // Show a subtle loading state on the button
    var btn = document.querySelector('.tmpl-action-copy');
    var origText = btn ? btn.innerHTML : '';
    if (btn) btn.innerHTML = '<svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Loading…';

    var body = new URLSearchParams();
    body.append('pid',             pid);
    body.append('current_id',      currentId);
    body.append('csrf_token_form', csrfToken);

    fetch(endpoint, {
        method:      'POST',
        credentials: 'same-origin',
        headers:     { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:        body.toString()
    })
    .then(function(r) {
        if (!r.ok) throw new Error('Server error ' + r.status);
        return r.json();
    })
    .then(function(data) {
        if (btn) btn.innerHTML = origText;

        if (!data.found) {
            alert(data.message || 'No previous notes found for this patient.');
            return;
        }

        // Format the date nicely
        var dateStr = '';
        if (data.date) {
            var d = new Date(data.date);
            dateStr = isNaN(d) ? data.date : d.toLocaleDateString(undefined, { year:'numeric', month:'short', day:'numeric' });
        }

        var confirmed = confirm(
            'Copy SOAP note from ' + (dateStr || 'previous visit') + '?\n\n' +
            'This will replace the current contents of all four SOAP fields.'
        );
        if (!confirmed) return;

        var fields = { s: 'sNote', o: 'oNote', a: 'aNote', p: 'pNote' };
        var map    = { s: data.subjective, o: data.objective, a: data.assessment, p: data.plan };

        Object.keys(fields).forEach(function(k) {
            var ta = document.getElementById(fields[k]);
            if (!ta) return;
            // Store in dataset.rec so dropdown-triggered setGeneratedContent
            // rebuilds as rec+gen and doesn't wipe this content.
            ta.dataset.rec = map[k] || '';
            ta.dataset.gen = '';
            ta.value       = map[k] || '';
        });

        tmplClosePanel();
    })
    .catch(function(err) {
        if (btn) btn.innerHTML = origText;
        alert('Failed to load previous note: ' + err.message);
    });
}

// ── Save current note as a custom template ─────────────────────────
// Initialise: mark blank as selected on load
document.addEventListener('DOMContentLoaded', function() {
    var blankItem = document.querySelector('.tmpl-item[data-tmpl="blank"]');
    if (blankItem) blankItem.classList.add('selected');
    updateCopyPreviousVisibility();
});


    
<?php echo '</script'; ?>
>

</body>
</html>
<?php }
}
