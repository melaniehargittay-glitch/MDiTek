<?php

/**
 * Patient data template — MDItek styled.
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 */

$search_any_type = $GLOBALS['search_any_patient'];

switch ($search_any_type) {
    case 'dual':
        $any_search_class = "any-search-legacy";
        $search_globals_class = "btn-globals-legacy";
        break;
    case 'comprehensive':
        $any_search_class = "any-search-modern";
        $search_globals_class = "btn-globals-modern";
        break;
    case 'fixed':
        $any_search_class = "any-search-fixed";
        $search_globals_class = "btn-globals-fixed";
        break;
    default:
        $any_search_class = "any-search-none";
        $search_globals_class = "btn-globals-none";
}

// Determine patient name display classes (unchanged from original)
$classes = "";
switch ($GLOBALS['patient_name_display']) :
    case 'btn':
        $classes = "btn btn-sm btn-secondary";
        $wrapperElement = 'div';
        $wrapperElementClass = 'btn-group btn-group-sm mb-2';
        $closeElement = '';
        $closeElementClass = '';
        $closeIconClass = 'text-muted';
        $pubpidElement = 'span';
        break;
    case 'text-large':
        $closeAnchorClasses = 'text-muted';
        $wrapperElement = 'h3';
        $wrapperElementClass = 'd-inline';
        $closeElement = 'small';
        $closeElementClass = '';
        $closeIconClass = 'text-muted fa-xs';
        $pubpidElement = 'small';
        break;
    default:
        $closeAnchorClasses = 'text-muted';
        $wrapperElement = 'div';
        $wrapperElementClass = 'd-inline';
        $pubpidElement = 'span';
        $closeElement = 'span';
        $closeElementClass = '';
        $closeIconClass = 'text-muted';
        break;
endswitch;

?>
<style>
/* ── MDItek patient bar ──────────────────────────────────────────── */
#attendantData {
    background: linear-gradient(135deg,#f8f7ff 0%,#f0edff 100%) !important;
    border-bottom: 1px solid #e2d9ff;
    padding: 8px 16px;
}
#attendantData .d-lg-inline-flex { align-items: center; gap: 12px; }

/* Photo */
#attendantData .patientPicture img {
    width:48px!important; height:48px!important;
    border-radius:50%!important; border:2px solid #c4b5fd!important; object-fit:cover;
}

/* Name */
#attendantData .ptName {
    font-size:17px!important; font-weight:700!important;
    color:#1e0a3c!important; text-decoration:none!important; line-height:1.2;
}
#attendantData .ptName:hover { color:#7c3aed!important; }
#attendantData .ptName .text-muted { font-size:11px!important; color:#9ca3af!important; }

/* DOB */
#attendantData .mt-2 span { font-size:11.5px; color:#6b7280; font-family:'JetBrains Mono',monospace; }

/* Close × */
#attendantData a[data-bind*="clearPatient"] { color:#9ca3af!important; margin-left:6px; transition:color .15s; }
#attendantData a[data-bind*="clearPatient"]:hover { color:#ef4444!important; }

/* ── Shared chip button style ────────────────────────────────────── */
#attendantData .btn-group .btn-secondary,
#attendantData #pastEncounters,
#attendantData #portalMsgAlerts,
#attendantData #servicesMsgAlerts,
#attendantData a[data-bind*="viewMessages"] {
    background: #fff !important;
    border: 1px solid #d1c7f0 !important;
    color: #5b21b6 !important;
    font-size: 11px !important;
    font-weight: 700 !important;
    border-radius: 14px !important;
    padding: 4px 11px !important;
    line-height: 1.5;
    transition: all .15s;
    white-space: nowrap;
    box-shadow: 0 1px 2px rgba(91,33,182,.06);
}
#attendantData .btn-group .btn-secondary:hover,
#attendantData #pastEncounters:hover,
#attendantData #servicesMsgAlerts:hover,
#attendantData a[data-bind*="viewMessages"]:hover {
    background: #5b21b6 !important;
    color: #fff !important;
    border-color: #5b21b6 !important;
}

/* Portal gets dark navy treatment */
#attendantData #portalMsgAlerts {
    background: linear-gradient(135deg,#1e0a3c,#3b0764) !important;
    border-color: #5b21b6 !important;
    color: #e9d5ff !important;
    letter-spacing: .04em;
}
#attendantData #portalMsgAlerts:hover {
    background: linear-gradient(135deg,#3b0764,#5b21b6) !important;
    box-shadow: 0 0 0 2px rgba(167,139,250,.3);
}

/* Badges on chip buttons */
#attendantData .btn-group .btn-secondary .badge,
#attendantData #pastEncounters .badge,
#attendantData #portalMsgAlerts .badge,
#attendantData #servicesMsgAlerts .badge {
    background: #a78bfa !important;
    color: #1e0a3c !important;
    font-weight: 800;
    border-radius: 8px;
    font-size: 9px;
    padding: 2px 5px;
    vertical-align: middle;
}
#attendantData #servicesMsgAlerts .badge { background: #ef4444 !important; color: #fff !important; }

/* Portal locked */
#attendantData nav.mdi-portal-locked button { opacity:.4!important; cursor:not-allowed!important; pointer-events:none!important; }

/* ── Dropdown menus — MDI styled ─────────────────────────────────── */
#attendantData .dropdown-menu,
#attendantData ul.dropdown-menu {
    background: #1e0a3c !important;
    border: 1px solid rgba(167,139,250,.25) !important;
    border-radius: 10px !important;
    box-shadow: 0 8px 32px rgba(0,0,0,.35), 0 0 0 1px rgba(167,139,250,.1) !important;
    padding: 6px 0 !important;
    min-width: 200px;
    margin-top: 4px !important;
}
#attendantData .dropdown-item {
    color: #e2d9ff !important;
    font-size: 12px !important;
    font-weight: 500 !important;
    padding: 8px 14px !important;
    display: flex !important;
    align-items: center !important;
    gap: 8px;
    transition: background .12s, color .12s;
    border-radius: 0;
}
#attendantData .dropdown-item:hover,
#attendantData .dropdown-item:focus {
    background: rgba(167,139,250,.15) !important;
    color: #a78bfa !important;
}
#attendantData .dropdown-item svg { color: #a78bfa; flex-shrink:0; }
#attendantData .dropdown-item .badge {
    margin-left: auto !important;
    background: #a78bfa !important;
    color: #1e0a3c !important;
    font-weight: 800 !important;
    border-radius: 8px !important;
    font-size: 9px !important;
    padding: 2px 6px !important;
}
#attendantData .dropdown-divider {
    border-color: rgba(167,139,250,.15) !important;
    margin: 3px 0 !important;
}

/* Encounter dropdown items */
#attendantData #pastEncounters + .dropdown-menu .d-inline-flex { display:flex!important; }
#attendantData #pastEncounters + .dropdown-menu .dropdown-item { font-size:12px!important; }

/* ── Encounter action bar ─────────────────────────────────────────── */
#mdi-enc-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    flex-wrap: nowrap;
    justify-content: flex-end;
}

/* Active Encounter pill — pushed right, close to buttons */
#mdi-active-enc {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ede9fe;
    border: 1.5px solid #c4b5fd;
    border-radius: 10px;
    padding: 6px 14px 6px 10px;
    flex-shrink: 0;
    min-width: 200px;
    cursor: pointer;
    transition: border-color .15s, box-shadow .15s;
    text-decoration: none;
    margin-left: auto;
}
#mdi-active-enc:hover { border-color:#7c3aed; box-shadow:0 0 0 2px rgba(124,58,237,.12); text-decoration:none; }
#mdi-active-enc .enc-icon-wrap {
    width:28px; height:28px; background:#7c3aed; border-radius:6px;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
#mdi-active-enc .enc-label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#7c3aed; line-height:1; }
#mdi-active-enc .enc-value { font-size:13px; font-weight:700; color:#1e0a3c; line-height:1.2; white-space:nowrap; display:flex; align-items:center; gap:5px; }
#mdi-active-enc .enc-value.enc-none { font-size:12px; font-weight:500; color:#9ca3af; font-style:italic; }
#mdi-active-enc .enc-sub { font-size:10px; color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:200px; margin-top:1px; }
#mdi-active-enc .enc-dot { width:8px; height:8px; border-radius:50%; background:#10b981; box-shadow:0 0 0 2px #d1fae5; flex-shrink:0; }

/* Action buttons */
.mdi-enc-action-btn {
    display:inline-flex !important; align-items:center !important; gap:5px !important;
    background:#fff !important; border:1.5px solid #c4b5fd !important; color:#5b21b6 !important;
    font-size:12px !important; font-weight:700 !important; border-radius:8px !important;
    padding:5px 12px !important; line-height:1.4 !important; transition:all .15s !important;
    white-space:nowrap !important; box-shadow:0 1px 3px rgba(91,33,182,.10) !important;
    cursor:pointer !important; text-decoration:none !important; flex-shrink:0;
}
.mdi-enc-action-btn:hover {
    background:#5b21b6 !important; color:#fff !important; border-color:#5b21b6 !important;
    box-shadow:0 2px 6px rgba(91,33,182,.22) !important; text-decoration:none !important;
}
.mdi-enc-action-btn svg { flex-shrink:0; }

/* ── Right-side button stack: View Encounters on top, Sign+New below ── */
#mdi-enc-btn-stack {
    display: flex;
    flex-direction: column;
    gap: 5px;
    flex-shrink: 0;
}
#mdi-enc-btn-row2 {
    display: flex;
    gap: 5px;
    align-items: center;
}

/* View Encounters button — dark navy, full width of stack */
#mdi-view-enc-btn {
    display:inline-flex !important; align-items:center !important; justify-content:center !important; gap:6px !important;
    background:#1e0a3c !important; border:1.5px solid #4c1d95 !important; color:#e9d5ff !important;
    font-size:12px !important; font-weight:700 !important; border-radius:8px !important;
    padding:6px 14px !important; transition:all .15s !important; white-space:nowrap !important;
    cursor:pointer !important; text-decoration:none !important; width:100% !important;
    box-sizing:border-box !important;
}
#mdi-view-enc-btn:hover { background:#3b0764 !important; border-color:#7c3aed !important; box-shadow:0 0 0 2px rgba(124,58,237,.2) !important; text-decoration:none !important; }
#mdi-view-enc-btn .enc-count-badge {
    background:#7c3aed; color:#fff; font-size:10px; font-weight:800;
    border-radius:8px; padding:1px 6px; line-height:1.5;
}
/* ── Encounters side panel ────────────────────────────────────────── */
#mdi-enc-panel-backdrop {
    display:none; position:fixed; inset:0;
    background:rgba(10,5,25,.45); z-index:2147483640;
    opacity:0; transition:opacity .25s ease;
}
#mdi-enc-panel-backdrop.open { opacity:1; }
#mdi-enc-panel {
    position:fixed; top:0; right:0; height:100%;
    width:400px; max-width:95vw;
    background:#fff; z-index:2147483641;
    transform:translateX(100%); transition:transform .3s cubic-bezier(.4,0,.2,1);
    display:flex; flex-direction:column;
    box-shadow:-4px 0 32px rgba(30,10,60,.18);
}
#mdi-enc-panel.open { transform:translateX(0); }

.mdi-ep-header {
    background:#1e0a3c; padding:14px 16px;
    display:flex; align-items:center; justify-content:space-between; flex-shrink:0;
}
.mdi-ep-title { font-size:15px; font-weight:700; color:#fff; font-family:'Montserrat',sans-serif; }
.mdi-ep-close {
    width:28px; height:28px; border-radius:6px; border:none; cursor:pointer;
    background:rgba(255,255,255,.12); color:rgba(255,255,255,.8);
    display:flex; align-items:center; justify-content:center; transition:background .15s;
}
.mdi-ep-close:hover { background:rgba(255,255,255,.25); color:#fff; }

.mdi-ep-tabs {
    display:flex; border-bottom:2px solid #e8e0f5; background:#faf9ff; flex-shrink:0; padding:0 12px;
}
.mdi-ep-tab {
    padding:9px 14px; font-size:12px; font-weight:600; color:#6b7280; cursor:pointer;
    border-bottom:2.5px solid transparent; margin-bottom:-2px; white-space:nowrap;
    font-family:'Montserrat',sans-serif; transition:color .13s, border-color .13s;
}
.mdi-ep-tab.active { color:#1e0a3c; border-bottom-color:#7c3aed; }

.mdi-ep-search {
    padding:10px 12px; border-bottom:1px solid #f0ecfb; flex-shrink:0; background:#faf9ff;
    position:relative;
}
.mdi-ep-search input {
    width:100%; padding:7px 32px 7px 10px; border:1.5px solid #e2d9ff;
    border-radius:7px; font-size:12px; font-family:'Montserrat',sans-serif;
    outline:none; background:#fff; color:#1e0a3c;
}
.mdi-ep-search input:focus { border-color:#7c3aed; }
.mdi-ep-search svg { position:absolute; right:22px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none; }

.mdi-ep-list { flex:1; overflow-y:auto; }
.mdi-ep-list::-webkit-scrollbar { width:3px; }
.mdi-ep-list::-webkit-scrollbar-thumb { background:#c4b5fd; border-radius:2px; }

.mdi-ep-item {
    display:flex; align-items:center; justify-content:space-between;
    padding:11px 14px; border-bottom:1px solid #f5f2fc; cursor:pointer;
    transition:background .12s; gap:10px;
}
.mdi-ep-item:hover { background:#f8f6ff; }
.mdi-ep-item.active-item { background:#ede9fe; border-left:3px solid #7c3aed; }
.mdi-ep-item-left { display:flex; flex-direction:column; gap:2px; min-width:0; }
.mdi-ep-date { font-size:13px; font-weight:700; color:#1e0a3c; font-family:'Montserrat',sans-serif; }
.mdi-ep-type { font-size:11px; font-weight:600; color:#374151; margin-top:1px; }
.mdi-ep-prov { font-size:10.5px; color:#6b7280; }
.mdi-ep-right { display:flex; flex-direction:column; align-items:flex-end; gap:4px; flex-shrink:0; }
.mdi-ep-badge {
    font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; white-space:nowrap;
}
.mdi-ep-badge.open   { background:#ede9fe; color:#7c3aed; }
.mdi-ep-badge.signed { background:#f0fdf4; color:#16a34a; }
.mdi-ep-badge.unsigned { background:#fff1f2; color:#dc2626; }
.mdi-ep-badge.closed { background:#f1f5f9; color:#64748b; }
.mdi-ep-badge.imported { background:#fef9c3; color:#854d0e; border:1px solid #fde047; }
.mdi-ep-procs { font-size:10px; color:#9ca3af; }

.mdi-ep-footer {
    padding:10px 14px; border-top:1px solid #e8e0f5; background:#faf9ff;
    display:flex; align-items:center; justify-content:space-between; flex-shrink:0;
}
.mdi-ep-footer-info { font-size:11px; color:#6b7280; font-family:'Montserrat',sans-serif; }
.mdi-ep-footer-link {
    font-size:11px; font-weight:700; color:#7c3aed; text-decoration:none;
    display:inline-flex; align-items:center; gap:4px;
}
.mdi-ep-footer-link:hover { color:#5b21b6; text-decoration:none; }
.mdi-ep-empty { text-align:center; padding:2.5rem 1rem; color:#9ca3af; font-size:12px; font-family:'Montserrat',sans-serif; }
.mdi-ep-loading { text-align:center; padding:2rem; color:#a78bfa; font-size:12px; font-family:'Montserrat',sans-serif; }

/* Date filter row */
.mdi-ep-datefilter {
    padding:8px 12px 10px; border-bottom:1px solid #f0ecfb; background:#faf9ff; flex-shrink:0;
    display:flex; align-items:center; gap:6px;
}
.mdi-ep-datefilter label { font-size:10px; font-weight:600; color:#9ca3af; white-space:nowrap; text-transform:uppercase; letter-spacing:.05em; }
.mdi-ep-datefilter input[type=date] {
    flex:1; padding:5px 7px; border:1.5px solid #e2d9ff; border-radius:6px;
    font-size:11px; font-family:"Montserrat",sans-serif; color:#1e0a3c;
    background:#fff; outline:none; min-width:0;
}
.mdi-ep-datefilter input[type=date]:focus { border-color:#7c3aed; }
.mdi-ep-df-clear {
    padding:4px 8px; border:1.5px solid #e2d9ff; border-radius:6px; background:#fff;
    color:#9ca3af; font-size:10px; font-weight:600; cursor:pointer; white-space:nowrap;
    transition:all .12s;
}
.mdi-ep-df-clear:hover { border-color:#7c3aed; color:#7c3aed; }
/* Open encounter button in each row */
.mdi-ep-open-btn {
    display:inline-flex; align-items:center; justify-content:center;
    width:26px; height:26px; border-radius:6px; flex-shrink:0;
    background:#ede9fe; border:none; cursor:pointer; transition:background .12s;
    color:#7c3aed;
}
.mdi-ep-open-btn:hover { background:#7c3aed; color:#fff; }

span.badge { display:none; }

/* ── Historical encounters tab ────────────────────────────────────── */
.mdi-ep-hist-item {
    display:flex; flex-direction:column;
    padding:12px 14px; border-bottom:1px solid #f5f2fc;
    cursor:pointer; transition:background .12s; gap:3px;
}
.mdi-ep-hist-item:hover { background:#f8f6ff; }
.mdi-ep-hist-item:last-child { border-bottom:none; }

.mdi-ep-hist-header {
    display:flex; align-items:center; justify-content:space-between; gap:8px;
}
.mdi-ep-hist-date {
    font-size:13px; font-weight:700; color:#1e0a3c;
    font-family:'Montserrat',sans-serif;
}
.mdi-ep-hist-source {
    font-size:9px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
    background:#fef9c3; color:#854d0e; border-radius:10px; padding:2px 7px;
    border:1px solid #fde047;
    white-space:nowrap; flex-shrink:0;
}
.mdi-ep-hist-complaint {
    font-size:11.5px; font-weight:600; color:#374151;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.mdi-ep-hist-meta {
    display:flex; align-items:center; gap:8px; flex-wrap:wrap;
}
.mdi-ep-hist-visit {
    font-size:10px; color:#6b7280;
}
.mdi-ep-hist-prov {
    font-size:10px; color:#6b7280;
}
.mdi-ep-hist-diag {
    font-size:10px; color:#9ca3af;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.mdi-ep-hist-expand-btn {
    align-self:flex-end; background:none; border:none; cursor:pointer;
    font-size:10px; font-weight:600; color:#7c3aed; padding:2px 0;
    font-family:'Montserrat',sans-serif; transition:color .13s;
}
.mdi-ep-hist-expand-btn:hover { color:#5b21b6; }

/* Expanded SOAP detail */
.mdi-ep-hist-detail {
    display:none; margin-top:8px; border-top:1px solid #ede9fe; padding-top:8px;
}
.mdi-ep-hist-detail.open { display:block; }
.mdi-ep-hist-soap-row {
    display:flex; gap:6px; align-items:flex-start; margin-bottom:7px;
}
.mdi-ep-hist-soap-label {
    font-size:9px; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
    min-width:26px; height:22px; border-radius:5px; display:flex; align-items:center;
    justify-content:center; flex-shrink:0; margin-top:1px;
}
.mdi-ep-hist-soap-label.s { background:#1e0a3c; color:#fff; }
.mdi-ep-hist-soap-label.o { background:#065f46; color:#fff; }
.mdi-ep-hist-soap-label.a { background:#92400e; color:#fff; }
.mdi-ep-hist-soap-label.p { background:#3730a3; color:#fff; }
.mdi-ep-hist-soap-label.t { background:#155e75; color:#fff; }
.mdi-ep-hist-soap-text {
    font-size:10.5px; color:#374151; line-height:1.55; white-space:pre-wrap; flex:1;
}
.mdi-ep-hist-field-row {
    display:flex; gap:6px; margin-bottom:4px; font-size:10.5px;
}
.mdi-ep-hist-field-lbl { color:#9ca3af; font-weight:600; min-width:90px; flex-shrink:0; }
.mdi-ep-hist-field-val { color:#374151; flex:1; }
.mdi-ep-hist-file {
    margin-top:8px; padding:6px 10px;
    background:#f8f6ff; border:1px solid #ede9fe; border-radius:6px;
    font-size:10px; color:#7c3aed; display:flex; align-items:center; gap:5px;
}
</style>

<script type="text/html" id="patient-data-template">
    <div class="d-lg-inline-flex w-100">
        <div class="flex-fill">
            <div class="float-left mx-2">
                <!-- ko if: patient -->
                <div data-bind="with: patient" class="patientPicture">
                    <img data-bind="attr: {src: patient_picture}"
                        class="img-thumbnail"
                        width="75"
                        height="75"
                        onError="this.src = '<?php echo $GLOBALS['images_static_relative']; ?>/patient-picture-default.png'" />
                </div>
                <!-- /ko -->
            </div>
            <div class="form-group">
                <!-- ko if: patient -->
                <?php echo "<$wrapperElement class=\"$wrapperElementClass\">"; ?>
                    <a class="ptName <?php echo $classes ?? ''; ?>" data-bind="click:refreshPatient,with: patient" href="#" title="<?php echo xla("To Dashboard") ?>">
                        <span data-bind="text: pname()"></span>
                        <<?php echo $pubpidElement;?> class="text-muted" style="font-size:.7em;">(<span data-bind="text: pubpid"></span>)</<?php echo $pubpidElement;?>>
                    </a>
                    <?php echo ($closeElement !== '') ? "<$closeElement class=\"$closeElementClass\">" : ''; ?>
                    <a href="#" class="pt-1<?php echo (($classes ?? '') !== "") ? " " . $classes : "";?> <?php echo ($closeAnchorClasses !== "") ? " " . $closeAnchorClasses : ""; ?>" data-bind="click:clearPatient" title="<?php echo xla("Close Patient Chart") ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </a>
                    <?php echo ($closeElement !== '') ? "</$closeElement>" : ''; ?>
                <?php echo "</$wrapperElement>"; ?>

                <div class="mt-2">
                    <span data-bind="text:patient().str_dob()"></span>
                </div>
                <!-- /ko -->
            </div>
        </div>

        <div class="flex-fill ml-2" style="min-width:0;">
            <!-- ko if: patient -->
            <!-- ko with: patient -->
            <div id="mdi-enc-bar">

                <!-- ① Active Encounter pill — pushed right via margin-left:auto -->
                <!-- ko if: encounterArray().length > 0 -->
                <a id="mdi-active-enc" href="#"
                   onclick="mdiEncPanelOpen(); return false;"
                   title="<?php echo xla('View Encounters'); ?>">
                    <div class="enc-icon-wrap">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:1px;min-width:0;">
                        <span class="enc-label"><?php echo xlt('Active Encounter'); ?></span>
                        <!-- ko if: selectedEncounter() -->
                        <span class="enc-value">
                            <span class="enc-dot"></span>
                            <span data-bind="text: selectedEncounter().date()"></span>
                        </span>
                        <span class="enc-sub" id="mdi-active-enc-sub"></span>
                        <!-- /ko -->
                        <!-- ko if: !selectedEncounter() -->
                        <span class="enc-value enc-none"><?php echo xlt('None selected'); ?></span>
                        <!-- /ko -->
                    </div>
                </a>
                <!-- /ko -->
                <!-- ko if: encounterArray().length === 0 -->
                <a id="mdi-active-enc" href="#"
                   onclick="mdiEncPanelOpen(); return false;"
                   title="<?php echo xla('View Encounters'); ?>">
                    <div class="enc-icon-wrap">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:1px;">
                        <span class="enc-label"><?php echo xlt('Active Encounter'); ?></span>
                        <span class="enc-value enc-none"><?php echo xlt('None selected'); ?></span>
                    </div>
                </a>
                <!-- /ko -->

                <!-- ② Stacked button group: View Encounters on top, Sign + New below -->
                <div id="mdi-enc-btn-stack">
                    <!-- Top: View Encounters (full width of stack) -->
                    <button id="mdi-view-enc-btn" type="button"
                            onclick="mdiEncPanelOpen()"
                            title="<?php echo xla('View Encounters'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <?php echo xlt('View Encounters'); ?>
                        <span class="enc-count-badge" id="mdi-ep-count-badge" style="display:none"></span>
                    </button>
                    <!-- Bottom row: Sign Notes + New Encounter -->
                    <div id="mdi-enc-btn-row2">
                        <a class="mdi-enc-action-btn"
                           href="/interface/main/tabs/mdi_visits.php?tab=unsigned"
                           onclick="top.restoreSession(); if(typeof top.mdiNavGo==='function'){ top.mdiNavGo('/interface/main/tabs/mdi_visits.php?tab=unsigned'); return false; }"
                           title="<?php echo xla('Sign Notes'); ?>"
                           style="flex:1;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                            <?php echo xlt('Sign Notes'); ?>
                        </a>
                        <a class="mdi-enc-action-btn" data-bind="click: clickNewEncounter" href="#"
                           title="<?php echo xla('New Encounter'); ?>"
                           style="flex:1;justify-content:center;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            <?php echo xlt('New Encounter'); ?>
                        </a>
                    </div>
                </div>

            </div>
            <!-- /ko --><!-- with patient -->
            <!-- /ko --><!-- patient -->
        </div>

    </div>
    </script><!-- end KO template -->

    <!-- ══ ENCOUNTERS SIDE PANEL ══ -->
    <div id="mdi-enc-panel-backdrop" onclick="mdiEncPanelClose()"></div>
    <div id="mdi-enc-panel" role="dialog" aria-label="Encounters">
        <div class="mdi-ep-header">
            <span class="mdi-ep-title">Encounters</span>
            <button class="mdi-ep-close" onclick="mdiEncPanelClose()" title="Close">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="mdi-ep-tabs">
            <div class="mdi-ep-tab active" data-tab="recent" onclick="mdiEpSwitchTab(this,'recent')">Recent</div>
            <div class="mdi-ep-tab" data-tab="all" onclick="mdiEpSwitchTab(this,'all')">All Encounters</div>
            <div class="mdi-ep-tab" data-tab="historical" onclick="mdiEpSwitchTab(this,'historical')">Historical</div>
        </div>
        <div class="mdi-ep-search">
            <input type="text" id="mdi-ep-search-input" placeholder="Search encounters…" oninput="mdiEpApplyFilters()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
        <div class="mdi-ep-datefilter">
            <label>From</label>
            <input type="date" id="mdi-ep-date-from" onchange="mdiEpApplyFilters()">
            <label>To</label>
            <input type="date" id="mdi-ep-date-to" onchange="mdiEpApplyFilters()">
            <button class="mdi-ep-df-clear" onclick="mdiEpClearDates()" title="Clear dates">×</button>
        </div>
        <div class="mdi-ep-list" id="mdi-ep-list">
            <div class="mdi-ep-loading">Loading encounters…</div>
        </div>
        <!-- Historical encounters list — hidden unless historical tab active -->
        <div class="mdi-ep-list" id="mdi-ep-hist-list" style="display:none">
            <div class="mdi-ep-loading">Loading historical encounters…</div>
        </div>
        <div class="mdi-ep-footer">
            <span class="mdi-ep-footer-info" id="mdi-ep-footer-count"></span>
            <a class="mdi-ep-footer-link" id="mdi-ep-footer-hist-link" style="display:none"
               href="#">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 7h8M8 12h5M8 17h6"/></svg>
                <?php echo xlt('View All Historical Encounters'); ?>
            </a>
            <a class="mdi-ep-footer-link" id="mdi-ep-footer-signed-link"
               href="/interface/main/tabs/mdi_visits.php"
               onclick="top.restoreSession(); if(typeof top.mdiNavGo==='function'){ top.mdiNavGo('/interface/main/tabs/mdi_visits.php'); return false; }">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/></svg>
                <?php echo xlt('View Signed Encounters History'); ?>
            </a>
        </div>
    </div>

    <script>
    // ── Encounters side panel ──────────────────────────────────────────────
    var _mdiEpAllEncs  = [];
    var _mdiEpFiltered = [];
    var _mdiEpTab      = 'recent';
    var _mdiEpOpen     = false;

    function mdiEncPanelOpen() {
        _mdiEpOpen = true;
        var bd = document.getElementById('mdi-enc-panel-backdrop');
        var pn = document.getElementById('mdi-enc-panel');
        if (!bd || !pn) return;
        bd.style.display = 'block';
        requestAnimationFrame(function() { bd.classList.add('open'); pn.classList.add('open'); });
        var si = document.getElementById('mdi-ep-search-input');
        if (si) si.value = '';
        var _df = document.getElementById('mdi-ep-date-from');
        var _dt = document.getElementById('mdi-ep-date-to');
        if (_df) _df.value = '';
        if (_dt) _dt.value = '';
        _mdiEpTab = 'recent';
        document.querySelectorAll('.mdi-ep-tab').forEach(function(t) {
            t.classList.toggle('active', t.dataset.tab === 'recent');
        });
        // Reset list visibility
        var normalList = document.getElementById('mdi-ep-list');
        var histList   = document.getElementById('mdi-ep-hist-list');
        var signedLink = document.getElementById('mdi-ep-footer-signed-link');
        var histLink   = document.getElementById('mdi-ep-footer-hist-link');
        if (normalList) normalList.style.display = '';
        if (histList)   histList.style.display   = 'none';
        if (signedLink) signedLink.style.display = '';
        if (histLink)   histLink.style.display   = 'none';
        mdiEpLoad();
    }

    function mdiEncPanelClose() {
        _mdiEpOpen = false;
        var bd = document.getElementById('mdi-enc-panel-backdrop');
        var pn = document.getElementById('mdi-enc-panel');
        if (!bd || !pn) return;
        bd.classList.remove('open'); pn.classList.remove('open');
        setTimeout(function() { bd.style.display = 'none'; }, 310);
    }

    function mdiEpSwitchTab(el, tab) {
        _mdiEpTab = tab;
        document.querySelectorAll('.mdi-ep-tab').forEach(function(t) { t.classList.remove('active'); });
        el.classList.add('active');
        var si = document.getElementById('mdi-ep-search-input');
        if (si) si.value = '';

        var normalList = document.getElementById('mdi-ep-list');
        var histList   = document.getElementById('mdi-ep-hist-list');
        var signedLink = document.getElementById('mdi-ep-footer-signed-link');
        var histLink   = document.getElementById('mdi-ep-footer-hist-link');

        if (tab === 'historical') {
            if (normalList) normalList.style.display = 'none';
            if (histList)   histList.style.display   = '';
            if (signedLink) signedLink.style.display = 'none';
            if (histLink)   histLink.style.display   = '';
            mdiEpHistLoad();
        } else {
            if (normalList) normalList.style.display = '';
            if (histList)   histList.style.display   = 'none';
            if (signedLink) signedLink.style.display = '';
            if (histLink)   histLink.style.display   = 'none';
            mdiEpLoad();
        }
    }

    function mdiEpLoad() {

var pid = 0;

try {

    var raw =
        app_view_model.application_data.patient;

    var pt =
        typeof raw === 'function'
            ? raw()
            : raw;

    if (pt) {

        if (typeof pt.pid === 'function') {
            pid = parseInt(pt.pid()) || 0;

        } else if (pt.pid) {
            pid = parseInt(pt.pid) || 0;
        }
    }

} catch(e) {
    console.warn('PID lookup failed', e);
}

console.log('PID FOUND:', pid);

if (!pid || pid <= 0) {

    _mdiEpAllEncs = [];

    mdiEpRender([]);

    return;
}

var url =
    '/interface/main/tabs/templates/mdi_enc_list.php?pid='
    + encodeURIComponent(pid);

console.log('FETCH URL:', url);

var el = document.getElementById('mdi-ep-list');

if (el) {
    el.innerHTML =
        '<div class="mdi-ep-loading">Loading encounters…</div>';
}

fetch(url, { credentials: 'same-origin' })
.then(function(r) {
    return r.text();  // read as text first so we can log raw on parse failure
})
.then(function(text) {
    var data;
    try {
        data = JSON.parse(text);
    } catch(parseErr) {
        console.error('mdiEpLoad JSON parse failed. Raw response:', text);
        _mdiEpAllEncs = [];
        mdiEpRender([]);
        return;
    }
    _mdiEpAllEncs = Array.isArray(data.encounters) ? data.encounters : [];

    // Also fetch historical encounters and append to the list
    fetch('/interface/main/tabs/templates/mdi_hist_enc_list.php?pid=' + encodeURIComponent(pid), { credentials: 'same-origin' })
    .then(function(hr) { return hr.text(); })
    .then(function(htext) {
        try {
            var hdata = JSON.parse(htext);
            if (Array.isArray(hdata.encounters) && hdata.encounters.length > 0) {
                // Mark as historical, adapt fields to match normal enc shape for rendering
                var histItems = hdata.encounters.map(function(h) {
                    // Build a short chief complaint for display
                    var cc = h.chief_complaint || '';
                    var m = cc.match(/^[A-Z\s]+\s+chief complaint:\s*/i);
                    if (m) cc = cc.substring(m[0].length);
                    cc = cc.length > 50 ? cc.substring(0, 50) + '…' : cc;
                    return {
                        id:            'hist-' + h.id,
                        _histId:       h.id,
                        _histPid:      h.pid,
                        _isHistorical: true,
                        date:          h.encounter_date,
                        category:      cc || 'Historical Encounter',
                        provider:      h.provider_name || h.rendering_provider || h.provider || h.provider_notes || 'Unknown Provider',
                        status:        'Imported',
                        _encUrl:       '/interface/main/tabs/mdi_historical_encounter.php?id=' + encodeURIComponent(h.id) + '&pid=' + encodeURIComponent(h.pid)
                    };
                });
                // Merge: historical items are combined with regular encounters and
                // re-sorted by date desc so the timeline (and "recent" slice) reflects
                // true chronological order rather than historical items simply being
                // tacked on at the end.
                //
                // mdiEpDateVal() parses actual date value rather than comparing raw
                // strings — comparing strings only works when every date is in ISO
                // (YYYY-MM-DD) form. Historical encounter dates aren't guaranteed to
                // be ISO, and a plain string compare on e.g. MM/DD/YYYY breaks across
                // months/years (day-of-month digits get compared before the year, so
                // "06/29/2020" sorts as "greater" than "06/02/2025").
                _mdiEpAllEncs = _mdiEpAllEncs.concat(histItems).sort(function(a, b) {
                    return mdiEpDateVal(b.date) - mdiEpDateVal(a.date);
                });
            }
        } catch(he) { /* historical fetch failed silently — don't break main list */ }
        mdiEpApplyFilters();
        var badge = document.getElementById('mdi-ep-count-badge');
        if (badge) {
            badge.textContent   = _mdiEpAllEncs.length;
            badge.style.display = _mdiEpAllEncs.length > 0 ? 'inline-block' : 'none';
        }
    })
    .catch(function() {
        // Historical fetch failed — still render normal encounters
        mdiEpApplyFilters();
        var badge = document.getElementById('mdi-ep-count-badge');
        if (badge) {
            badge.textContent   = _mdiEpAllEncs.length;
            badge.style.display = _mdiEpAllEncs.length > 0 ? 'inline-block' : 'none';
        }
    });
})
.catch(function(err) {
    console.error('mdiEpLoad fetch failed:', err);
    _mdiEpAllEncs = [];
    mdiEpRender([]);
});
}

    function mdiEpApplyFilters() {
        var q    = (document.getElementById('mdi-ep-search-input') || {}).value || '';
        var from = (document.getElementById('mdi-ep-date-from') || {}).value || '';
        var to   = (document.getElementById('mdi-ep-date-to')   || {}).value || '';
        q = q.toLowerCase().trim();

        if (_mdiEpTab === 'historical') {
            _mdiHistFiltered = _mdiHistAllEncs.filter(function(enc) {
                var d = (enc.encounter_date || '').substring(0, 10);
                if (q && (enc.encounter_date + ' ' + (enc.chief_complaint||'') + ' ' + (enc.diagnoses||'')).toLowerCase().indexOf(q) < 0) return false;
                if (from && d < from) return false;
                if (to   && d > to)   return false;
                return true;
            });
            mdiEpHistRender(_mdiHistFiltered);
            return;
        }

        var base = _mdiEpTab === 'recent' ? _mdiEpAllEncs.slice(0, 15) : _mdiEpAllEncs;
        _mdiEpFiltered = base.filter(function(enc) {
            var d = (enc.date || '').substring(0, 10);
            if (q && (enc.date + ' ' + enc.category + ' ' + (enc.provider||'')).toLowerCase().indexOf(q) < 0) return false;
            if (from && d < from) return false;
            if (to   && d > to)   return false;
            return true;
        });
        mdiEpRender(_mdiEpFiltered);
    }
    function mdiEpFilter(q) { mdiEpApplyFilters(); }
    function mdiEpClearDates() {
        var df = document.getElementById('mdi-ep-date-from');
        var dt = document.getElementById('mdi-ep-date-to');
        if (df) df.value = '';
        if (dt) dt.value = '';
        mdiEpApplyFilters();
    }

    function mdiEpUpdateActivePill() {
        var sub = document.getElementById('mdi-active-enc-sub');
        if (!sub) return;
        try {
            var pt = typeof app_view_model.application_data.patient === 'function' ? app_view_model.application_data.patient() : app_view_model.application_data.patient;
            var se = pt && typeof pt.selectedEncounter === 'function' ? pt.selectedEncounter() : null;
            if (!se) { sub.textContent = ''; return; }
            var seId = String(typeof se.id === 'function' ? se.id() : (se.id || ''));
            for (var i = 0; i < _mdiEpAllEncs.length; i++) {
                if (String(_mdiEpAllEncs[i].id) === seId) {
                    var p = _mdiEpAllEncs[i].provider || '';
                    var c = _mdiEpAllEncs[i].category || '';
                    sub.textContent = p ? p + (c ? ' \u00b7 ' + c : '') : c;
                    return;
                }
            }
            var cat = typeof se.category === 'function' ? se.category() : (se.category || '');
            sub.textContent = cat;
        } catch(e) { sub.textContent = ''; }
    }

    function mdiEpRender(list) {
        var el = document.getElementById('mdi-ep-list');
        var fc = document.getElementById('mdi-ep-footer-count');
        if (!el) return;

        var selectedId = '';
        try {
            var pt = typeof app_view_model.application_data.patient === 'function' ? app_view_model.application_data.patient() : app_view_model.application_data.patient;
            var se = pt && typeof pt.selectedEncounter === 'function' ? pt.selectedEncounter() : null;
            if (se) selectedId = String(typeof se.id === 'function' ? se.id() : (se.id || ''));
        } catch(e) {}

        if (!list || list.length === 0) {
            el.innerHTML = '<div class="mdi-ep-empty">No encounters found.</div>';
            if (fc) fc.textContent = '0 encounters';
            return;
        }

        var total = _mdiEpAllEncs.length;
        if (fc) fc.textContent = 'Showing ' + list.length + (list.length < total ? ' of ' + total : '') + ' encounter' + (total === 1 ? '' : 's');

        var html = '';
        list.forEach(function(enc) {
            var isActive  = enc.id && enc.id === selectedId;
            var status    = enc.status || 'Unsigned';
            var badgeCls  = status.toLowerCase() === 'signed'   ? 'signed'
                          : status.toLowerCase() === 'imported' ? 'imported'
                          : 'unsigned';

            if (enc._isHistorical) {
                // Historical item — yellow badge, click-through to encounter page
                html += '<div class="mdi-ep-item" id="mdi-ep-row-' + mdiEpEsc(enc.id) + '">'
                      + '<div class="mdi-ep-item-left" onclick="mdiHistNav(\'' + mdiEpEsc(enc._encUrl) + '\')" style="cursor:pointer;flex:1;">'
                      + '<div class="mdi-ep-date">' + mdiEpEsc(enc.date) + '</div>'
                      + '<div class="mdi-ep-type">' + mdiEpEsc(enc.category) + '</div>'
                      + (enc.provider ? '<div class="mdi-ep-prov">' + mdiEpEsc(enc.provider) + '</div>' : '')
                      + '</div>'
                      + '<div class="mdi-ep-right">'
                      + '<span class="mdi-ep-badge imported">Imported</span>'
                      + '<button class="mdi-ep-open-btn" onclick="mdiHistNav(\'' + mdiEpEsc(enc._encUrl) + '\')" title="View historical encounter">'
                      + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>'
                      + '</button>'
                      + '</div></div>';
            } else {
                html += '<div class="mdi-ep-item' + (isActive ? ' active-item' : '') + '" id="mdi-ep-row-' + mdiEpEsc(enc.id) + '">'
                      + "<div class=\"mdi-ep-item-left\" onclick=\"mdiEpSelect('" + mdiEpEsc(enc.id) + "', event)\" style=\"cursor:pointer;flex:1;\">"
                      + '<div class="mdi-ep-date">' + mdiEpEsc(enc.date) + '</div>'
                      + '<div class="mdi-ep-type">' + mdiEpEsc(enc.category) + '</div>'
                      + (enc.provider ? '<div class="mdi-ep-prov">' + mdiEpEsc(enc.provider) + '</div>' : '')
                      + '</div>'
                      + '<div class="mdi-ep-right">'
                      + '<span class="mdi-ep-badge ' + badgeCls + '">' + mdiEpEsc(status) + '</span>'
                      + "<button class=\"mdi-ep-open-btn\" onclick=\"mdiEpSelect('" + mdiEpEsc(enc.id) + "', event)\" title=\"Open &amp; set as active encounter\">"
                      + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>'
                      + '</button>'
                      + '</div></div>';
            }
        });
        el.innerHTML = html;

        // Scroll the currently-active encounter into view
        if (selectedId) {
            var activeRow = document.getElementById('mdi-ep-row-' + selectedId);
            if (activeRow) { setTimeout(function() { activeRow.scrollIntoView({block:'nearest', behavior:'smooth'}); }, 80); }
        }
    }

    // Clicking row text selects encounter in KO model
    function mdiEpSelect(encId, e) {

if (e) e.stopPropagation();

try {

    var raw =
        app_view_model.application_data.patient;

    var pt =
        typeof raw === 'function'
            ? raw()
            : raw;

    if (!pt) {
        mdiEncPanelClose();
        return;
    }

    var pid =
        typeof pt.pid === 'function'
            ? parseInt(pt.pid())
            : parseInt(pt.pid || 0);

    /*
     * Keep Knockout active encounter in sync
     */
    var arr =
        typeof pt.encounterArray === 'function'
            ? pt.encounterArray()
            : [];

    var matchedEnc = null;
    for (var i = 0; i < arr.length; i++) {

        var thisId =
            String(
                typeof arr[i].id === 'function'
                    ? arr[i].id()
                    : (arr[i].id || '')
            );

        if (thisId === String(encId)) {
            matchedEnc = arr[i];

            if (typeof pt.selectedEncounter === 'function') {
                pt.selectedEncounter(arr[i]);
            }

            if (typeof arr[i].chooseEncounterEvent === 'function') {
                arr[i].chooseEncounterEvent(arr[i]);
            }

            break;
        }
    }

    // Also fire KO refreshEncounter so the active-enc pill date updates
    if (matchedEnc && typeof pt.refreshEncounter === 'function') {
        try { pt.refreshEncounter(matchedEnc); } catch(ex2) {}
    }

    /*
     * Navigate to encounter
     */
    if (pid && encId) {

        top.restoreSession();

        var url =
            '/interface/patient_file/encounter/encounter_top.php'
            + '?set_pid=' + encodeURIComponent(pid)
            + '&set_encounter=' + encodeURIComponent(encId);

        if (typeof top.mdiNavGo === 'function') {

            top.mdiNavGo(url);

        } else if (top.RTop) {

            top.RTop.location = url;

        } else {

            window.location.href = url;
        }
    }

} catch(ex) {

    console.warn('mdiEpSelect:', ex);
}

mdiEncPanelClose();
}
    // Clicking the → button navigates directly to the encounter view
    function mdiEpOpen(encId, e) {
        if (e) e.stopPropagation();
        try {
            var raw = app_view_model.application_data.patient;
            var pt  = typeof raw === 'function' ? raw() : raw;
            var pid = pt && typeof pt.pid === 'function' ? parseInt(pt.pid()) : 0;
            if (pid && encId) {
                top.restoreSession();
                var url = '/interface/patient_file/encounter/encounter_top.php'
                        + '?set_pid=' + encodeURIComponent(pid)
                        + '&set_encounter=' + encodeURIComponent(encId);
                if (typeof top.mdiNavGo === 'function') top.mdiNavGo(url);
                else if (top.RTop) top.RTop.location = url;
                else window.location.href = url;
            }
        } catch(ex) { console.warn('mdiEpOpen:', ex); }
        mdiEncPanelClose();
    }

    // ── Historical encounters (mdi_historical_encounters table) ───────────
    var _mdiHistAllEncs  = [];
    var _mdiHistFiltered = [];

    function mdiEpHistLoad() {
        var pid = 0;
        try {
            var raw = app_view_model.application_data.patient;
            var pt  = typeof raw === 'function' ? raw() : raw;
            if (pt) pid = parseInt(typeof pt.pid === 'function' ? pt.pid() : (pt.pid || 0)) || 0;
        } catch(e) {}

        var el = document.getElementById('mdi-ep-hist-list');
        var fc = document.getElementById('mdi-ep-footer-count');

        if (!pid) {
            _mdiHistAllEncs = [];
            mdiEpHistRender([]);
            return;
        }

        if (el) el.innerHTML = '<div class="mdi-ep-loading">Loading historical encounters…</div>';

        fetch('/interface/main/tabs/templates/mdi_hist_enc_list.php?pid=' + encodeURIComponent(pid), { credentials: 'same-origin' })
        .then(function(r) { return r.text(); })
        .then(function(text) {
            var data;
            try { data = JSON.parse(text); } catch(e) {
                console.error('mdiEpHistLoad parse error:', text);
                mdiEpHistRender([]);
                return;
            }
            _mdiHistAllEncs = Array.isArray(data.encounters) ? data.encounters : [];
            // Apply search filter
            var q = ((document.getElementById('mdi-ep-search-input') || {}).value || '').toLowerCase().trim();
            _mdiHistFiltered = q
                ? _mdiHistAllEncs.filter(function(e) {
                    return (e.encounter_date + ' ' + (e.chief_complaint||'') + ' ' + (e.diagnoses||'')).toLowerCase().indexOf(q) >= 0;
                  })
                : _mdiHistAllEncs;
            mdiEpHistRender(_mdiHistFiltered);
        })
        .catch(function(err) {
            console.error('mdiEpHistLoad fetch error:', err);
            mdiEpHistRender([]);
        });
    }

    function mdiEpHistRender(list) {
        var el = document.getElementById('mdi-ep-hist-list');
        var fc = document.getElementById('mdi-ep-footer-count');
        if (!el) return;

        if (!list || list.length === 0) {
            el.innerHTML = '<div class="mdi-ep-empty">No historical encounters found.</div>';
            if (fc) fc.textContent = '0 historical encounters';
            return;
        }

        if (fc) fc.textContent = list.length + ' historical encounter' + (list.length === 1 ? '' : 's');

        var html = '';
        list.forEach(function(enc, idx) {
            var uid = 'hist-' + idx;
            var hasDetail = enc.chief_complaint || enc.subjective || enc.objective || enc.assessment || enc.plan || enc.treatment;

            // Format visit info
            var visitInfo = enc.visit_number ? 'Visit ' + enc.visit_number : '';
            if (enc.initial_visit_date) visitInfo += (visitInfo ? ' \u00b7 ' : '') + 'Initial: ' + enc.initial_visit_date;

            html += '<div class="mdi-ep-hist-item">';

            // Header row: date + source badge + click-through arrow
            var encUrl = '/interface/main/tabs/mdi_historical_encounter.php?id=' + encodeURIComponent(enc.id) + '&pid=' + encodeURIComponent(enc.pid || '');
            html += '<div class="mdi-ep-hist-header">'
                  + '<span class="mdi-ep-hist-date" style="cursor:pointer;" onclick="mdiHistNav(\'' + mdiEpEsc(encUrl) + '\')">' + mdiEpEsc(enc.encounter_date || '') + '</span>'
                  + (enc.import_source ? '<span class="mdi-ep-hist-source">' + mdiEpEsc(enc.import_source) + '</span>' : '')
                  + '<button class="mdi-ep-open-btn" onclick="mdiHistNav(\'' + mdiEpEsc(encUrl) + '\')" title="View encounter">'
                  + '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>'
                  + '</button>'
                  + '</div>';

            // Chief complaint (truncated)
            if (enc.chief_complaint) {
                html += '<div class="mdi-ep-hist-complaint">' + mdiEpEsc(enc.chief_complaint.substring(0, 120) + (enc.chief_complaint.length > 120 ? '…' : '')) + '</div>';
            }

            // Meta row: visit # + provider
            html += '<div class="mdi-ep-hist-meta">';
            if (visitInfo) html += '<span class="mdi-ep-hist-visit">' + mdiEpEsc(visitInfo) + '</span>';
            var provName = enc.provider_name || enc.rendering_provider || enc.provider || enc.provider_notes || '';
            if (provName) html += '<span class="mdi-ep-hist-prov">' + mdiEpEsc(provName) + '</span>';
            html += '</div>';

            // Diagnoses
            if (enc.diagnoses) {
                html += '<div class="mdi-ep-hist-diag">' + mdiEpEsc(enc.diagnoses.substring(0, 100) + (enc.diagnoses.length > 100 ? '…' : '')) + '</div>';
            }

            // Expand button + detail panel
            if (hasDetail) {
                html += '<button class="mdi-ep-hist-expand-btn" onclick="mdiHistToggle(\'' + uid + '\', this)">+ View SOAP Notes</button>';
                html += '<div class="mdi-ep-hist-detail" id="' + uid + '">';

                // SOAP rows
                var soap = [
                    { key:'s', label:'S', text: enc.subjective || enc.chief_complaint },
                    { key:'o', label:'O', text: enc.objective },
                    { key:'a', label:'A', text: enc.assessment },
                    { key:'p', label:'P', text: enc.plan },
                    { key:'t', label:'T', text: enc.treatment },
                ];
                soap.forEach(function(row) {
                    if (!row.text) return;
                    html += '<div class="mdi-ep-hist-soap-row">'
                          + '<span class="mdi-ep-hist-soap-label ' + row.key + '">' + row.label + '</span>'
                          + '<div class="mdi-ep-hist-soap-text">' + mdiEpEsc(row.text.substring(0, 600) + (row.text.length > 600 ? '…' : '')) + '</div>'
                          + '</div>';
                });

                // Extra fields
                if (enc.pain_scale) {
                    html += '<div class="mdi-ep-hist-field-row"><span class="mdi-ep-hist-field-lbl">Pain Scale:</span><span class="mdi-ep-hist-field-val">' + mdiEpEsc(enc.pain_scale) + '</span></div>';
                }
                if (enc.rom) {
                    html += '<div class="mdi-ep-hist-field-row"><span class="mdi-ep-hist-field-lbl">ROM:</span><span class="mdi-ep-hist-field-val">' + mdiEpEsc(enc.rom.substring(0, 200)) + '</span></div>';
                }
                if (enc.functional_status) {
                    html += '<div class="mdi-ep-hist-field-row"><span class="mdi-ep-hist-field-lbl">Functional Status:</span><span class="mdi-ep-hist-field-val">' + mdiEpEsc(enc.functional_status.substring(0, 200)) + '</span></div>';
                }

                // Filename chip
                if (enc.original_filename) {
                    html += '<div class="mdi-ep-hist-file">'
                          + '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6z"/><path d="M14 2v6h6"/></svg>'
                          + mdiEpEsc(enc.original_filename)
                          + '</div>';
                }

                html += '</div>'; // .mdi-ep-hist-detail
            }

            html += '</div>'; // .mdi-ep-hist-item
        });

        el.innerHTML = html;
    }

    function mdiHistToggle(uid, btn) {
        var detail = document.getElementById(uid);
        if (!detail) return;
        var isOpen = detail.classList.toggle('open');
        btn.textContent = isOpen ? '− Hide SOAP Notes' : '+ View SOAP Notes';
    }

    function mdiHistNav(url) {
        top.restoreSession();
        if (typeof top.mdiNavGo === 'function') {
            top.mdiNavGo(url);
        } else if (top.RTop) {
            top.RTop.location = url;
        } else {
            window.location.href = url;
        }
        mdiEncPanelClose();
    }

    function mdiEpEsc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // Parses a date string into a numeric timestamp for comparison, handling
    // both ISO (YYYY-MM-DD...) and US (MM/DD/YYYY...) forms explicitly rather
    // than relying on the browser's native Date parsing (which is inconsistent
    // across formats/locales) or raw string comparison (which breaks whenever
    // the format isn't strictly ISO -- see the sort comparator above).
    function mdiEpDateVal(str) {
        str = (str || '').trim();
        if (!str) return 0;
        var iso = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (iso) {
            return Date.UTC(+iso[1], +iso[2] - 1, +iso[3]);
        }
        var us = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})/);
        if (us) {
            return Date.UTC(+us[3], +us[1] - 1, +us[2]);
        }
        var t = Date.parse(str);
        return isNaN(t) ? 0 : t;
    }

    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && _mdiEpOpen) mdiEncPanelClose(); });

    // Subscribe to KO observables to keep badge + pill in sync
    (function() {
        function attachObs() {
            try {
                var pt = typeof app_view_model.application_data.patient === 'function' ? app_view_model.application_data.patient() : app_view_model.application_data.patient;
                if (!pt) { setTimeout(attachObs, 500); return; }

                // Update badge whenever encounter list changes
                function syncBadge() {
                    var arr = typeof pt.encounterArray === 'function' ? pt.encounterArray() : [];
                    var badge = document.getElementById('mdi-ep-count-badge');
                    if (badge) {
                        badge.textContent  = arr.length;
                        badge.style.display = arr.length > 0 ? 'inline-block' : 'none';
                    }
                    if (_mdiEpOpen) mdiEpLoad();
                }
                syncBadge();
                if (typeof pt.encounterArray === 'function' && typeof pt.encounterArray.subscribe === 'function') pt.encounterArray.subscribe(syncBadge);
                if (typeof pt.selectedEncounter === 'function' && typeof pt.selectedEncounter.subscribe === 'function') pt.selectedEncounter.subscribe(function() {
                    mdiEpUpdateActivePill();
                    if (_mdiEpOpen) mdiEpRender(_mdiEpFiltered);
                });
                if (typeof pt.pid === 'function' && typeof pt.pid.subscribe === 'function') pt.pid.subscribe(function() {
                    _mdiEpAllEncs = [];
                    var badge = document.getElementById('mdi-ep-count-badge');
                    if (badge) badge.style.display = 'none';
                    setTimeout(syncBadge, 400);
                });
            } catch(e) { setTimeout(attachObs, 500); }
        }
        setTimeout(attachObs, 800);
    })();
    </script>

    <script>
    // ── Fix New Encounter button on dashboard page ───────────────────────
    (function patchEncounterButtons() {
        function doFix() {
            try {
                // "New Encounter" button — fire KO binding or fallback
                var newEncBtn = document.querySelector('#attendantData a[title="New Encounter"],' +
                    '#attendantData a[title="<?php echo xla("New Encounter"); ?>"]');
                if (newEncBtn && !newEncBtn.dataset.mdiFixed) {
                    newEncBtn.dataset.mdiFixed = '1';
                    newEncBtn.addEventListener('click', function(e) {
                        try {
                            var vm = ko.dataFor(this);
                            if (vm && typeof vm.clickNewEncounter === 'function') {
                                vm.clickNewEncounter();
                            } else if (typeof app_view_model !== 'undefined') {
                                var _ptRaw = app_view_model.application_data && app_view_model.application_data.patient;
                var pt = typeof _ptRaw === 'function' ? _ptRaw() : _ptRaw;
                                if (pt && typeof pt.clickNewEncounter === 'function') pt.clickNewEncounter();
                            }
                        } catch(err) {}
                        e.preventDefault();
                    });
                }
            } catch(e) {}
        }
        setTimeout(doFix, 500);
        setTimeout(doFix, 1500);
    })();

    // KO renders patient-data-template after ko.applyBindings, so pname()
    // is available here. We notify main.php's mdiSetPatientPill via a
    // MutationObserver watching for the patient name to be populated.
    (function() {
        function syncPill() {
            try {
                var _ptRaw = app_view_model && app_view_model.application_data && app_view_model.application_data.patient;
                var pt = typeof _ptRaw === 'function' ? _ptRaw() : _ptRaw;
                if (!pt) return;
                var pid   = typeof pt.pid   === 'function' ? pt.pid()   : '';
                var fname = typeof pt.fname === 'function' ? pt.fname() : '';
                var lname = typeof pt.lname === 'function' ? pt.lname() : '';
                var dob   = typeof pt.dob   === 'function' ? pt.dob()   : '';
                if (pid && parseInt(pid) > 0) {
                    if (typeof mdiSetPatientPill === 'function')
                        mdiSetPatientPill(pid, fname, lname, dob);
                } else {
                    if (typeof mdiClearPatientPill === 'function')
                        mdiClearPatientPill();
                }
                // Gate portal nav based on dashboard visibility
                var portalNav = document.getElementById('mdi-portal-nav');
                var dashVisible = (typeof mdiDashVisible !== 'undefined') ? mdiDashVisible : true;
                if (portalNav) {
                    if (!dashVisible && !(pid && parseInt(pid) > 0)) {
                        portalNav.classList.add('mdi-portal-locked');
                    } else {
                        portalNav.classList.remove('mdi-portal-locked');
                    }
                }
            } catch(e) {}
        }
        // Run immediately and subscribe to KO pid changes
        setTimeout(syncPill, 200);
        setTimeout(function() {
            try {
                var pt = typeof app_view_model.application_data.patient === 'function' ? app_view_model.application_data.patient() : app_view_model.application_data.patient;
                if (pt && typeof pt.pid === 'function') {
                    pt.pid.subscribe(function() { setTimeout(syncPill, 100); });
                }
            } catch(e) {}
        }, 800);
    })();
    </script>
