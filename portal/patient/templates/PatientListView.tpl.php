<?php

/**
 * import_template.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @author    Terry Hill <teryhill@yahoo.com>
 * @copyright Copyright (c) 2016-2021 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

    //require_once ("./../verify_session.php");
    require_once("../../library/options.inc.php");
    $this->assign('title', xlt("Patient Portal") . " | " . xlt("Patient Data"));
    $this->assign('nav', 'patientdata');
    /*
     *  row keys are js underscore camelcase and follow the underscores varables used in this template
     *  $row['city'] or $row['postalCode'] e.t.c.. The keys do not match table columns ie postalCode here is postal_code in table.
     *  */
    $row = array();
if ($this->trow) {
    $row = $this->trow;
}
$exclude = array();
if ($this->exclude) {
    $exclude = $this->exclude;
}

    echo "<script>var register='" . attr($this->register) . "';var recid='" . attr($this->recid) . "';var webRoot='" . $GLOBALS['web_root'] . "';var cpid='" . attr($this->cpid) . "';var cuser='" . attr($this->cuser) . "';</script>";

    $this->display('_modalFormHeader.tpl.php');
    // ── MDITek shared design ──────────────────────────────────────────────
    echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
    echo '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">';
    echo '<link rel="stylesheet" href="/portal/portal-shared.css">';
    if (empty($this->register)) {
        $PORTAL_NAV_ACTIVE = 'dashboard';
        require_once(__DIR__ . '/../../portal-nav.php');
    }
?>
<script>
    // bring in the datepicker and datetimepicker localization and setting elements
    <?php require($GLOBALS['srcdir'] . '/js/xl/jquery-datetimepicker-2-5-4-alternate.js.php'); ?>

    $LAB.script("scripts/app/patientdata.js?v=<?php echo $GLOBALS['v_js_includes']; ?>").wait(function(){
        $(function () {
            page.init();
        });
        // hack for browsers or slow sessions which may respond inconsistently with document.ready-second chance at init
        setTimeout(function(){
            if (!page.isInitialized) page.init();
        },1000);
    });
    // profile excludes global from layouts via PatientController
    const exclude = <?php echo js_escape($exclude); ?>;
</script>
<?php if (attr($this->register)) {?>
<style>
    .form-group .inline .dynhide {
        display: none;
    }

    body {
        padding-top: 0;
        padding-bottom: 5px;
        background-color: #fff !important;
    }
</style>
<script>
    // Fixes iFrame in Patient Registratiion
    setInterval(function() {
        window.top.postMessage(document.body.scrollHeight, "*");
    }, 500);
</script>
<?php }?>
<style>
/*
 * PatientListView — portal-design-light aesthetic
 * Font: Plus Jakarta Sans (loaded via _modalFormHeader)
 * Tokens extend portal-shared.css (navy vars kept for nav)
 */

/* ── Design tokens (light palette) ── */
:root {
  --lbg:          #f0f2f8;
  --lwhite:       #ffffff;
  --lborder:      #e2e5f0;
  --lborder2:     #d1d5e8;
  --ltext:        #1e1b4b;
  --ltext2:       #374151;
  --lmuted:       #6b7280;
  --lmuted2:      #9ca3af;
  --lradius:      14px;
  --lshadow-sm:   0 1px 3px rgba(99,102,241,.08),0 1px 2px rgba(0,0,0,.04);
  --lshadow:      0 4px 16px rgba(99,102,241,.10),0 1px 4px rgba(0,0,0,.05);
  --lviolet:      #7c3aed;
  --lviolet-mid:  #6d28d9;
  --lviolet-lt:   #ede9fe;
  --lviolet-xlt:  #f5f3ff;
  --lsky:         #0284c7;
  --lsky-lt:      #e0f2fe;
  --lamber:       #b45309;
  --lamber-lt:    #fef3c7;
  --lemerald:     #047857;
  --lemerald-lt:  #d1fae5;
  --lrose:        #be123c;
  --lrose-lt:     #ffe4e6;
  --lindigo:      #4338ca;
  --lindigo-lt:   #e0e7ff;
  --lslate:       #475569;
  --lslate-lt:    #f1f5f9;
  --lred:         #dc2626;
  --lred-lt:      #fee2e2;
}

/* ── Body ── */
body.pt-has-nav {
  font-family: 'Plus Jakarta Sans','Montserrat',system-ui,sans-serif !important;
  background: var(--lbg) !important;
  color: var(--ltext2) !important;
  padding-top: var(--nav-h) !important;
  min-height: 100vh;
  overflow-x: hidden;
}
body.pt-has-nav::before {
  content: '';
  position: fixed; inset: 0;
  background-image: radial-gradient(circle,rgba(124,58,237,.055) 1px,transparent 1px);
  background-size: 28px 28px;
  pointer-events: none; z-index: 0;
}

/* ── Page layout ── */
.pt-sub-body {
  position: relative; z-index: 1;
  padding: 1.8rem !important;
  max-width: 1020px !important;
  margin: 0 auto !important;
}
.pt-has-nav .container-fluid { padding: 0 !important; }

/* ── Page title row ── */
.pt-page-title-row {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 1.4rem;
}
.pt-page-title-row h2 {
  font-size: 1.1rem; font-weight: 800;
  color: var(--ltext); letter-spacing: -.02em; margin: 0;
}
.pt-page-title-badge {
  font-size: .62rem; font-weight: 700;
  padding: 3px 10px; border-radius: 20px;
  background: var(--lviolet-lt); color: var(--lviolet);
  letter-spacing: .04em;
}

/* ── Card shell ── */
.pt-lcard {
  background: var(--lwhite);
  border: 1px solid var(--lborder);
  border-radius: var(--lradius);
  overflow: hidden;
  margin-bottom: 1.1rem;
  box-shadow: var(--lshadow-sm);
  transition: box-shadow .2s;
  animation: lcardUp .38s ease both;
}
.pt-lcard:hover { box-shadow: var(--lshadow); }
.pt-lcard:nth-child(1){animation-delay:.04s}
.pt-lcard:nth-child(2){animation-delay:.10s}
.pt-lcard:nth-child(3){animation-delay:.16s}
@keyframes lcardUp {
  from{opacity:0;transform:translateY(9px)}
  to  {opacity:1;transform:none}
}

/* Accent top bars */
.pt-lcard::before { content:''; display:block; height:3px; width:100%; }
.laccent-violet::before { background:linear-gradient(90deg,var(--lviolet),#a78bfa); }
.laccent-indigo::before { background:linear-gradient(90deg,var(--lindigo),#818cf8); }
.laccent-sky::before    { background:linear-gradient(90deg,var(--lsky),#38bdf8); }
.laccent-emerald::before{ background:linear-gradient(90deg,var(--lemerald),#34d399); }
.laccent-amber::before  { background:linear-gradient(90deg,var(--lamber),#fbbf24); }

/* ── Card header ── */
.pt-lcard-header {
  display:flex; align-items:center; justify-content:space-between;
  padding: .9rem 1.3rem;
  border-bottom: 1px solid var(--lborder);
  background: var(--lwhite);
}
.pt-lcard-header-left { display:flex; align-items:center; gap:11px; }
.pt-section-icon {
  width:36px; height:36px; border-radius:10px;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.pt-section-icon svg { width:16px; height:16px; }
.laccent-violet  .pt-section-icon { background:var(--lviolet-lt);  color:var(--lviolet); }
.laccent-indigo  .pt-section-icon { background:var(--lindigo-lt);  color:var(--lindigo); }
.laccent-sky     .pt-section-icon { background:var(--lsky-lt);     color:var(--lsky); }
.laccent-emerald .pt-section-icon { background:var(--lemerald-lt); color:var(--lemerald); }
.laccent-amber   .pt-section-icon { background:var(--lamber-lt);   color:var(--lamber); }
.pt-lcard-title { font-size:.85rem; font-weight:700; color:var(--ltext); margin:0; }
.pt-lcard-sub   { font-size:.63rem; color:var(--lmuted2); margin-top:2px; }

/* ── Revise / action button ── */
.btn-edit-light {
  font-size:.67rem; font-weight:700; letter-spacing:.04em;
  padding:6px 16px; border-radius:8px; cursor:pointer;
  border:1.5px solid var(--lborder2);
  background:var(--lbg); color:var(--lslate);
  transition:all .15s; font-family:inherit;
}
.btn-edit-light:hover { border-color:var(--lviolet); color:var(--lviolet); background:var(--lviolet-xlt); }
.btn-edit-light.violet {
  border-color:var(--lviolet-lt); background:var(--lviolet-xlt); color:var(--lviolet);
}
.btn-edit-light.violet:hover {
  background:var(--lviolet); color:#fff;
  box-shadow:0 3px 12px rgba(124,58,237,.3);
}

/* ── Demo grid — 2 columns ── */
.pt-demo-grid {
  display:grid; grid-template-columns:1fr 1fr;
}
@media(max-width:680px){ .pt-demo-grid{grid-template-columns:1fr;} }

/* ── Demo group cells (replaces plist-group) ── */
.plist-group {
  padding: 1.1rem 1.4rem !important;
  border-right: 1px solid var(--lborder) !important;
  border-bottom: 1px solid var(--lborder) !important;
  border-radius: 0 !important;
  background: transparent !important;
  margin: 0 !important;
  min-width: 0 !important;
  box-shadow: none !important;
  transition: background .13s !important;
}
.plist-group:nth-child(even) { border-right:none !important; }
.plist-group:focus-within { background:var(--lviolet-xlt) !important; }

/* Remove bottom border from last row */
.form-row.mb-2.pb-2 .plist-group:last-child,
.form-row.mb-2.pb-2 .plist-group:nth-last-child(2) { border-bottom:none !important; }

/* ── Section group label headers ── */
.pt-demo-group-label {
  font-size:.58rem; font-weight:800;
  text-transform:uppercase; letter-spacing:.1em;
  display:flex; align-items:center; gap:7px;
  margin-bottom:.75rem;
}
.pt-demo-label-badge {
  width:20px; height:20px; border-radius:6px;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.pt-demo-label-badge svg { width:11px; height:11px; }
.ptlabel-who     { color:var(--lviolet); }
.ptlabel-who     .pt-demo-label-badge { background:var(--lviolet-lt);  color:var(--lviolet); }
.ptlabel-contact { color:var(--lsky); }
.ptlabel-contact .pt-demo-label-badge { background:var(--lsky-lt);     color:var(--lsky); }
.ptlabel-choices { color:var(--lemerald); }
.ptlabel-choices .pt-demo-label-badge { background:var(--lemerald-lt); color:var(--lemerald); }
.ptlabel-id      { color:var(--lamber); }
.ptlabel-id      .pt-demo-label-badge { background:var(--lamber-lt);   color:var(--lamber); }
.ptlabel-address { color:var(--lsky); }
.ptlabel-address .pt-demo-label-badge { background:var(--lsky-lt);     color:var(--lsky); }
.ptlabel-other   { color:var(--lslate); }
.ptlabel-other   .pt-demo-label-badge { background:var(--lslate-lt);   color:var(--lslate); }

/* ── Field label ── */
.plist-label {
  font-size:.58rem !important; font-weight:800 !important;
  text-transform:uppercase !important; letter-spacing:.08em !important;
  color:var(--lmuted2) !important;
  display:block !important; margin-bottom:.4rem !important;
}

/* ── Inputs inside cells ── */
.plist-group .form-control {
  font-size:.79rem !important; font-weight:500 !important;
  color:var(--ltext) !important;
  border:1.5px solid var(--lborder2) !important;
  border-radius:8px !important;
  padding:6px 10px !important;
  background:var(--lwhite) !important;
  transition:border-color .14s,box-shadow .14s !important;
  font-family:'DM Mono','Montserrat',monospace !important;
}
.plist-group .form-control:focus {
  border-color:var(--lviolet) !important;
  box-shadow:0 0 0 3px rgba(124,58,237,.12) !important;
  outline:none !important;
}
.plist-group .form-control:disabled,
.plist-group .form-control[disabled] {
  background:var(--lbg) !important;
  color:var(--lmuted2) !important;
  cursor:not-allowed !important;
  border-color:var(--lborder) !important;
}
.plist-group select.form-control {
  font-family:'Plus Jakarta Sans',sans-serif !important;
}

/* ── Form layout wrappers ── */
.form-row.mb-2.pb-2 {
  display:grid !important;
  grid-template-columns:1fr 1fr !important;
  gap:0 !important; padding:0 !important; margin:0 !important;
  border:none !important; background:transparent !important;
  box-shadow:none !important;
}
.form-row.mb-2.pb-2::before { display:none !important; }
fieldset { border:none !important; padding:0 !important; margin:0 !important; }
form { margin:0 !important; }
.help-inline { display:none !important; }

/* ── HIPAA YES/NO pill radios ── */
.btn-gradient {
  display:inline-flex !important; align-items:center !important;
  gap:4px !important; font-size:.67rem !important; font-weight:700 !important;
  letter-spacing:.03em !important; padding:4px 11px !important;
  border-radius:20px !important; cursor:pointer !important;
  border:1.5px solid var(--lborder2) !important;
  background:var(--lwhite) !important; color:var(--lslate) !important;
  transition:all .13s !important;
  margin:2px 2px 2px 0 !important; font-family:inherit !important;
}
.btn-gradient:hover {
  border-color:var(--lviolet) !important;
  color:var(--lviolet) !important; background:var(--lviolet-xlt) !important;
}
.btn-gradient.active {
  background:var(--lviolet) !important;
  border-color:var(--lviolet) !important; color:#fff !important;
  box-shadow:0 2px 8px rgba(124,58,237,.25) !important;
}
.btn-gradient input[type="radio"] {
  position:absolute !important; opacity:0 !important;
  width:0 !important; height:0 !important;
}

/* ── Profile help banner ── */
#profileHelp {
  background:var(--lamber-lt) !important;
  border:1px solid #fde68a !important;
  border-left:4px solid var(--lamber) !important;
  border-radius:var(--lradius) !important;
  padding:1.2rem 1.4rem !important;
  margin-bottom:1.4rem !important;
  width:100% !important; max-width:100% !important;
  box-shadow:none !important;
}
#profileHelp p { color:#78350f !important; font-size:.78rem !important; line-height:1.65 !important; margin-bottom:.8rem !important; }
#dismissHelp {
  font-size:.67rem !important; font-weight:700 !important;
  padding:5px 14px !important; border-radius:8px !important;
  border:none !important; background:var(--lamber) !important;
  color:#fff !important; cursor:pointer !important;
}
#dismissHelp:hover { background:#92400e !important; }

/* ── Alerts ── */
#collectionAlert,#modelAlert { margin-bottom:1rem; }
.alert { border-radius:var(--lradius) !important; border:none !important; font-size:.78rem !important; }
.alert-danger  { background:var(--lrose-lt) !important;   border-left:3px solid var(--lrose) !important;    color:#9f1239 !important; }
.alert-success { background:var(--lemerald-lt) !important;border-left:3px solid var(--lemerald) !important; color:#065f46 !important; }
.alert-warning { background:var(--lamber-lt) !important;  border-left:3px solid var(--lamber) !important;   color:#78350f !important; }

/* ── patientdata.js action buttons ── */
#editDems {
  font-size:.67rem !important; font-weight:700 !important;
  letter-spacing:.04em !important; padding:6px 16px !important;
  border-radius:8px !important; cursor:pointer !important;
  transition:all .15s !important; font-family:inherit !important;
}
#editDems.btn-success {
  border:1.5px solid var(--lviolet-lt) !important;
  background:var(--lviolet-xlt) !important; color:var(--lviolet) !important;
}
#editDems.btn-success:hover {
  background:var(--lviolet) !important; border-color:var(--lviolet) !important;
  color:#fff !important;
}
#editDems.btn-danger {
  border:1.5px solid var(--lrose-lt) !important;
  background:var(--lrose-lt) !important; color:var(--lrose) !important;
}

/* ── Note/comments textarea ── */
#note { min-width:100% !important; resize:vertical; }

/* ── Responsive ── */
@media(max-width:680px){
  .pt-sub-body { padding:1rem !important; }
  .form-row.mb-2.pb-2 { grid-template-columns:1fr !important; }
  .plist-group { border-right:none !important; }
}
</style>
<script>
/* Inject demo-group section labels after Backbone renders the patient template */
(function injectGroupLabels() {
    function labelGroups() {
        var container = document.getElementById('patientModelContainer');
        if (!container) return;
        var form = container.querySelector('.form-row.mb-2.pb-2');
        if (!form || form.dataset.labeled) return;
        form.dataset.labeled = '1';

        // Map of fieldId prefix → label config
        var GROUPS = {
            who: {
                fields:['fname','mname','lname','title','dob','ss','sex','status','date'],
                label:'Identity', icon:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>'
            },
            contact: {
                fields:['street','city','state','postalCode','county','countryCode','phoneHome','phoneBiz','phoneCell','phoneContact','contactRelationship','email','emailDirect'],
                label:'Contact', icon:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><polyline points="2 7 12 13 22 7"/></svg>'
            },
            choices: {
                fields:['hipaaMail','hipaaVoice','hipaaNotice','hipaaMessage','hipaaAllowsms','hipaaAllowemail','allowImmRegUse','allowImmInfoShare','allowHealthInfoEx','allowPatientPortal'],
                label:'Consents &amp; Choices', icon:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>'
            },
            id: {
                fields:['providerid','pubpid','pid','refProviderid','language','race','ethnicity','religion','familySize','mothersname','guardiansname','careTeam','note'],
                label:'Provider &amp; ID', icon:'<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>'
            }
        };

        // Map each plist-group to its group
        var fieldGroups = {who:[], contact:[], choices:[], id:[]};
        var cells = form.querySelectorAll('.plist-group');
        cells.forEach(function(cell) {
            var id = cell.id ? cell.id.replace('InputContainer','') : '';
            var placed = false;
            Object.keys(GROUPS).forEach(function(gkey) {
                if (!placed && GROUPS[gkey].fields.indexOf(id) >= 0) {
                    fieldGroups[gkey].push(cell);
                    placed = true;
                }
            });
            if (!placed) fieldGroups.id.push(cell);
        });

        // Re-render the form as grouped sections
        var COLORS = { who:'ptlabel-who', contact:'ptlabel-contact', choices:'ptlabel-choices', id:'ptlabel-id' };
        form.innerHTML = '';
        Object.keys(GROUPS).forEach(function(gkey) {
            if (fieldGroups[gkey].length === 0) return;
            // Full-width group header spanning both columns
            var hdr = document.createElement('div');
            hdr.className = 'plist-group pt-group-header';
            hdr.style.cssText = 'grid-column:1/-1;border-right:none!important;border-bottom:1px solid var(--lborder)!important;background:var(--lbg)!important;padding:.65rem 1.4rem!important;';
            hdr.innerHTML = '<div class="pt-demo-group-label '+COLORS[gkey]+'">'
                + '<div class="pt-demo-label-badge">'+GROUPS[gkey].icon+'</div>'
                + GROUPS[gkey].label
                + '</div>';
            form.appendChild(hdr);
            fieldGroups[gkey].forEach(function(cell){ form.appendChild(cell); });
        });
    }
    // Try immediately + on mutation
    document.addEventListener('DOMContentLoaded', function(){
        labelGroups();
        var obs = new MutationObserver(labelGroups);
        obs.observe(document.body, {childList:true, subtree:true});
    });
})();
</script>
<body class="pt-has-nav">
<?php if (empty($this->register)): ?>
<div class="pt-sub-body">

  <!-- Page title -->
  <div class="pt-page-title-row">
    <div class="pt-section-icon laccent-violet" style="background:var(--lviolet-lt);color:var(--lviolet);">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
    </div>
    <h2><?php echo xlt('My Profile'); ?></h2>
    <span class="pt-page-title-badge"><?php echo xlt('Patient Record'); ?></span>
  </div>

  <!-- Profile Demographics card -->
  <div class="pt-lcard laccent-violet">
    <div class="pt-lcard-header">
      <div class="pt-lcard-header-left">
        <div class="pt-section-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
        </div>
        <div>
          <div class="pt-lcard-title"><?php echo xlt('Profile Demographics'); ?></div>
          <div class="pt-lcard-sub"><?php echo xlt('Personal &amp; contact information'); ?></div>
        </div>
      </div>
      <div id="collectionAlert"></div>
      <div id="modelAlert"></div>
    </div>

    <!-- Backbone renders the form template here -->
    <div id="patientCollectionContainer" class="collectionContainer"></div>
    <div id="patientModelContainer" class="modelContainer">
      <!-- form fields populated by patientdata.js -->
    </div>
  </div><!-- /.pt-lcard -->

</div><!-- /.pt-sub-body -->
<?php else: ?>
<div>
  <div id="collectionAlert"></div>
  <div id="modelAlert"></div>
  <div id="patientCollectionContainer" class="collectionContainer"></div>
  <div id="patientModelContainer" class="modelContainer"></div>
</div>
<?php endif; ?>

<div class="container-fluid" style="display:none;">
<script type="text/template" id="patientCollectionTemplate"></script>
<script type="text/template" id="patientModelTemplate">


<div id='profileHelp' class='jumbotron jumbotron-fluid' style='display: none; width: 650px; margin: 0 auto;'>
<p>
<?php echo xlt('Any changes here will be reviewed by provider staff before committing to your chart. The following apply'); ?>:<br />
<?php echo xlt('Change any item available and when ready click Send for review. The changes will be flagged and staff notified to review changes before committing them to chart. During the time period before changes are reviewed the Revised button will show Pending and profile data is still available for changes. When accessing profile in pending state all previous edits will appear in Blue and current chart values in Red. You may revert any edit to chart value by clicking that red item (or vica versa) but remember that when you click Send for Review then items that populate the field items are the ones that are sent. Revert Edits button changes everything back to chart values and you may make changes from there. So to recap: Items in BLUE are patient edits with items in RED being original values before any edits.'); ?>
</p>
        <button class="btn btn-primary btn-sm" type="button" id='dismissHelp'><?php echo xlt('Dismiss'); ?></button>
        </div>
        <form onsubmit="return false;">
            <fieldset>
            <div class="form-row mb-2 pb-2">
            <div class="col-sm-auto px-3 form-group plist-group" id="provideridInputContainer">
                    <label class="plist-label" for="providerid"><?php echo xlt('Current Physician')?></label>
                    <div class="controls inline-inputs">
                        <select class="form-control" id="providerid" value="<?php echo $row['providerid'] ?>">
                            <?php
                            echo "<option value='1'>" . xlt('Administrator Assign') . "</option>";
                            foreach ($this->users_list as $user) {
                                $user_name = text($user['fname'] . ' ' . $user['lname']);
                                $id = attr($user['id']);
                                echo "<option value='$id'";
                                if ((int)$id === (int)$row['providerid']) {
                                    echo " selected";
                                }
                                echo ">$user_name</option>";
                            }
                            ?>
                        </select>
                        <span class="help-inline"></span>
                    </div>
            </div>
            <div class="col-sm-auto px-3 form-group plist-group" id="titleInputContainer">
                <label class="plist-label" for="title"><?php echo xlt('Title')?></label><br />
                <div class="controls inline-inputs">
                    <?php
                  # Generate drop down list for Title
                    echo generate_select_list('title', 'titles', $row['title'], xl('Title'), 'Unassigned', "form-control");
                    ?>
                 <span class="help-inline"></span>
                </div>
            </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="fnameInputContainer">
                    <label class="plist-label" for="fname"><?php echo xlt('First{{Name}}')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="fname" required placeholder="<?php echo xla('First Name'); ?>" value="<%= _.escape(item.get('fname') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="mnameInputContainer">
                    <label class="plist-label" for="mname"><?php echo xlt('Middle{{Name}}')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="mname" placeholder="<?php echo xla('Middle Name'); ?>" value="<%= _.escape(item.get('mname') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="lnameInputContainer">
                    <label class="plist-label" for="lname"><?php echo xlt('Last{{Name}}')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="lname" required placeholder="<?php echo xla('Last Name'); ?>" value="<%= _.escape(item.get('lname') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group  dynhide hide" id="pidInputContainer">
                    <label class="plist-label" for="pid"><?php echo xlt('Pid')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="pid" placeholder="<?php echo xla('Pid')?>" value="<%= _.escape(item.get('pid') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="pubpidInputContainer">
                    <label class="plist-label" for="pubpid"><?php echo xlt('Public Patient Id')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="pubpid" disabled value="<%= _.escape(item.get('pubpid') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="dobInputContainer">
                    <label class="plist-label" for="dob"><?php echo xlt('Birth Date')?></label>
                    <div class="controls inline-inputs">
                        <div class="input-group" >
                            <input id="dob" type="text" required class="form-control jquery-date-picker" placeholder="<?php echo xla('I know but we need it!'); ?>" value="<%= item.get('dob') %>" />
                        </div>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="ssInputContainer">
                    <label class="plist-label" for="ss"><?php echo xlt('SSN')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="ss" title="###-##-####" placeholder="<?php echo xla('Social Security(Optional)'); ?>" value="<%= _.escape(item.get('ss') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="sexInputContainer">
                    <label class="plist-label" for="sex"><?php echo xlt('Gender')?></label><br />
                    <div class="controls inline-inputs">
                        <?php
                      # Generate drop down list for Sex
                        echo generate_select_list('sex', 'sex', $row['sex'], xl('Sex'), 'Unassigned');
                        ?>
                     <span class="help-inline"></span>
                    </div>
                </div>
                <!--<div class="col-sm-auto px-3 form-group plist-group" id="pharmacyIdInputContainer">
                    <label class="plist-label" for="pharmacyId"><?php echo xlt('Pharmacy Id')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="pharmacyId" placeholder="<?php echo xla('Pharmacy Id'); ?>" value="<%= _.escape(item.get('pharmacyId') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>-->
                <div class="col-sm-auto px-3 form-group plist-group" id="statusInputContainer">
                    <label class="plist-label" for="status"><?php echo xlt('Marital Status')?></label><br />
                    <div class="controls inline-inputs">
                    <?php
                  # Generate drop down list for Marital Status
                    echo generate_select_list('status', 'marital', $row['status'], xl('Marital Status'), 'Unassigned', "form-control");
                    ?>
                    <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="streetInputContainer">
                    <label class="plist-label" for="street"><?php echo xlt('Street')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="street" required placeholder="<?php echo xla('Street'); ?>" value="<%= _.escape(item.get('street') || '') %>"/>
                        <span class="help-inline"></span>
                    </div>
                </div>
            <div class="col-sm-auto px-3 form-group plist-group" id="cityInputContainer">
                    <label class="plist-label" for="city"><?php echo xlt('City')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="city" required placeholder="<?php echo xla('City'); ?>" value="<%= _.escape(item.get('city') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
            <div class="col-sm-auto px-3 form-group plist-group" id="stateInputContainer">
                <label class="plist-label" for="state"><?php echo xlt('State')?></label><br />
                <div class="controls inline-inputs">
                    <?php
                  # Generate drop down list for State
                    echo generate_select_list('state', 'state', $row['state'], xl('State'), 'Unassigned', "form-control");
                    ?>
                 <span class="help-inline"></span>
                </div>
            </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="postalCodeInputContainer">
                    <label class="plist-label" for="postalCode"><?php echo xlt('Postal Code')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="postalCode" placeholder="<?php echo xla('Postal Code'); ?>" value="<%= _.escape(item.get('postalCode') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="countyInputContainer">
                    <label class="plist-label" for="county"><?php echo xlt('County')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="county" placeholder="<?php echo xla('County'); ?>" value="<%= _.escape(item.get('county') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="countryCodeInputContainer">
                    <label class="plist-label" for="countryCode"><?php echo xlt('Country Code')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="countryCode" placeholder="<?php echo xla('Country Code'); ?>" value="<%= _.escape(item.get('countryCode') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="phoneHomeInputContainer">
                    <label class="plist-label" for="phoneHome"><?php echo xlt('Home Phone')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="phoneHome" placeholder="<?php echo xla('Phone Home'); ?>" value="<%= _.escape(item.get('phoneHome') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="phoneBizInputContainer">
                    <label class="plist-label" for="phoneBiz"><?php echo xlt('Business Phone')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="phoneBiz" placeholder="<?php echo xla('Phone Biz'); ?>" value="<%= _.escape(item.get('phoneBiz') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="phoneCellInputContainer">
                    <label class="plist-label" for="phoneCell"><?php echo xlt('Cell Phone')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="phoneCell" placeholder="<?php echo xla('Phone Cell'); ?>" value="<%= _.escape(item.get('phoneCell') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="phoneContactInputContainer">
                    <label class="plist-label" for="phoneContact"><?php echo xlt('Contact or Notify Phone')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="phoneContact" placeholder="<?php echo xla('Phone Contact'); ?>" value="<%= _.escape(item.get('phoneContact') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="contactRelationshipInputContainer">
                    <label class="plist-label" for="contactRelationship"><?php echo xlt('Contact Relationship')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="contactRelationship" placeholder="<?php echo xla('Contact Relationship'); ?>" value="<%= _.escape(item.get('contactRelationship') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="dateInputContainer">
                    <label class="plist-label" for="date"><?php echo xlt('Date')?></label>
                    <div class="controls inline-inputs">
                        <div class="input-group">
                            <input disabled id="date" type="text" class="form-control jquery-date-time-picker" value="<%= item.get('date') %>" />
                        </div>
                        <span class="help-inline"></span>
                    </div>
                </div><!-- -->
                <div class="col-sm-auto px-3 form-group plist-group dynhide" id="refProvideridInputContainer">
                    <label class="plist-label" for="refProviderid"><?php echo xlt('Referral Provider')?></label>
                    <div class="controls inline-inputs">
                        <select  disabled class="form-control" id="refProviderid"  value="<?php echo $row['refProviderid'] ?>">
                            <?php
                            echo "<option value=''>" . xlt('Unassigned') . "</option>";
                            foreach ($this->users_list as $user) {
                                $user_name = text($user['fname'] . ' ' . $user['lname']);
                                $id = attr($user['id']);
                                echo "<option value='$id'";
                                if ((int)$id === (int)$row['refProviderid']) {
                                    echo " selected";
                                }
                                echo ">$user_name</option>";
                            }
                            ?>
                        </select>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="emailInputContainer">
                    <label class="plist-label" for="email"><?php echo xlt('Email')?></label>
                    <div class="controls inline-inputs">
                        <input type="email" class="form-control" id="email" required placeholder="<?php echo xla('Email'); ?>" value="<%= _.escape(item.get('email') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group dynhide" id="emailDirectInputContainer">
                    <label class="plist-label" for="emailDirect"><?php echo xlt('Email Direct')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="emailDirect" placeholder="<?php echo xla('Direct Email'); ?>" value="<%= _.escape(item.get('emailDirect') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="languageInputContainer">
                    <label class="plist-label" for="language"><?php echo xlt('Preferred Language')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="language" placeholder="<?php echo xla('Language'); ?>" value="<%= _.escape(item.get('language') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
            <div class="col-sm-auto px-3 form-group plist-group" id="raceInputContainer">
                <label class="plist-label" for="race"><?php echo xlt('Race')?></label><br />
                <div class="controls inline-inputs">
                    <?php
                  # Generate drop down list for Race
                    echo generate_select_list('race', 'race', $row[ 'race'], xl('Race'), 'Unassigned', "form-control");
                    ?>
                <span class="help-inline"></span>
                </div>
           </div>
           <div class="col-sm-auto px-3 form-group plist-group" id="ethnicityInputContainer">
                    <label class="plist-label" for="ethnicity"><?php echo xlt('Ethnicity')?></label><br />
                    <div class="controls inline-inputs">
                        <?php
                      # Generate drop down list for Ethnicity
                        echo generate_select_list('ethnicity', 'ethnicity', $row['ethnicity'], xl('Ethnicity'), 'Unassigned', "form-control");
                        ?>
                    <span class="help-inline"></span>
                    </div>
            </div>
            <div class="col-sm-auto px-3 form-group plist-group" id="religionInputContainer">
                <label class="plist-label" for="religion"><?php echo xlt('Religion')?></label><br />
                <div class="controls inline-inputs">
                    <?php
                  # Generate drop down list for Religion
                    echo generate_select_list('religion', 'religious_affiliation', $row['religion'], xl('Religion'), 'Unassigned', "form-control");
                    ?>
                 <span class="help-inline"></span>
                </div>
            </div>
            <div class="col-sm-auto px-3 form-group plist-group dynhide" id="familySizeInputContainer">
                    <label class="plist-label" for="familySize"><?php echo xlt('Family Size')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="familySize" placeholder="<?php echo xla('Family Size'); ?>" value="<%= _.escape(item.get('familySize') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <!--<div class="col-sm-auto px-3 form-group plist-group" id="referralSourceInputContainer">
                    <label class="plist-label" for="referralSource"><?php /*echo xlt('How Referred')*/?></label><br />
                    <div class="controls inline-inputs">
                        <?php
/*                      # Generate drop down list for Referral Source
                        echo generate_select_list('referralSource', 'refsource', $row['referralSource'], xl('Referral Source'), 'Unassigned', "form-control");
                        */?>
                        <span class="help-inline"></span>
                    </div>
                </div>-->
                <!--<div class="col-sm-auto px-3 form-group plist-group dynhide" id="regdateInputContainer">
                    <label class="plist-label" for="regdate"><?php /*echo xlt('Registration Date')*/?></label>
                    <div class="controls inline-inputs">
                        <div class="input-group">
                            <input disabled id="regdate" type="text" class="form-control jquery-date-picker" value="<%= item.get('regdate') %>" />
                        </div>
                        <span class="help-inline"></span>
                    </div>
                </div>-->
                <div class="col-sm-auto px-3 form-group plist-group" id="mothersnameInputContainer">
                    <label class="plist-label" for="mothersname"><?php echo xlt('Mothers Name')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="mothersname" placeholder="<?php echo xla('Mothers Name'); ?>" value="<%= _.escape(item.get('mothersname') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group dynhide" id="guardiansnameInputContainer">
                    <label class="plist-label" for="guardiansname"><?php echo xlt('Guardians Name')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="guardiansname" placeholder="<?php echo xla('Guardians Name'); ?>" value="<%= _.escape(item.get('guardiansname') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="hipaaMailInputContainer">
                    <label class="plist-label" for="hipaaMail"><?php echo xlt('Allow Postal Mail')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaMail0" name="hipaaMail" type="radio" value="NO"<% if (item.get('hipaaMail')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaMail1" name="hipaaMail" type="radio" value="YES"<% if (item.get('hipaaMail')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaMail2" name="hipaaMail" type="radio" value=""<% if (item.get('hipaaMail')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="hipaaVoiceInputContainer">
                    <label class="plist-label" for="hipaaVoice"><?php echo xlt('Allow Voice Call')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaVoice0" name="hipaaVoice" type="radio" value="NO"<% if (item.get('hipaaVoice')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaVoice1" name="hipaaVoice" type="radio" value="YES"<% if (item.get('hipaaVoice')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaVoice2" name="hipaaVoice" type="radio" value=""<% if (item.get('hipaaVoice')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="hipaaNoticeInputContainer">
                    <label class="plist-label" for="hipaaNotice"><?php echo xlt('Allow Notice')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaNotice0" name="hipaaNotice" type="radio" value="NO"<% if (item.get('hipaaNotice')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaNotice1" name="hipaaNotice" type="radio" value="YES"<% if (item.get('hipaaNotice')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaNotice2" name="hipaaNotice" type="radio" value=""<% if (item.get('hipaaNotice')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group dynhide" id="hipaaMessageInputContainer">
                    <label class="plist-label" for="hipaaMessage"><?php echo xlt('Hipaa Message')?></label>
                    <div class="controls inline-inputs">
                        <input type="text" class="form-control" id="hipaaMessage" placeholder="<?php echo xla('Hipaa Message'); ?>" value="<%= _.escape(item.get('hipaaMessage') || '') %>">
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="hipaaAllowsmsInputContainer">
                    <label class="plist-label" for="hipaaAllowsms"><?php echo xlt('Allow SMS')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaAllowsms0" name="hipaaAllowsms" type="radio" value="NO"<% if (item.get('hipaaAllowsms')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaAllowsms1" name="hipaaAllowsms" type="radio" value="YES"<% if (item.get('hipaaAllowsms')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaAllowsms2" name="hipaaAllowsms" type="radio" value=""<% if (item.get('hipaaAllowsms')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="hipaaAllowemailInputContainer">
                    <label class="plist-label" for="hipaaAllowemail"><?php echo xlt('Allow Email')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaAllowemail0" name="hipaaAllowemail" type="radio" value="NO"<% if (item.get('hipaaAllowemail')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaAllowemail1" name="hipaaAllowemail" type="radio" value="YES"<% if (item.get('hipaaAllowemail')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="hipaaAllowemail2" name="hipaaAllowemail" type="radio" value=""<% if (item.get('hipaaAllowemail')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="allowImmRegUseInputContainer">
                    <label class="plist-label" for="allowImmRegUse"><?php echo xlt('Allow Immunization Registry Use')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowImmRegUse0" name="allowImmRegUse" type="radio" value="NO"<% if (item.get('allowImmRegUse')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowImmRegUse1" name="allowImmRegUse" type="radio" value="YES"<% if (item.get('allowImmRegUse')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowImmRegUse2" name="allowImmRegUse" type="radio" value=""<% if (item.get('allowImmRegUse')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="allowImmInfoShareInputContainer">
                    <label class="plist-label" for="allowImmInfoShare"><?php echo xlt('Allow Immunization Info Share')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowImmInfoShare0" name="allowImmInfoShare" type="radio" value="NO"<% if (item.get('allowImmInfoShare')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowImmInfoShare1" name="allowImmInfoShare" type="radio" value="YES"<% if (item.get('allowImmInfoShare')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowImmInfoShare2" name="allowImmInfoShare" type="radio" value=""<% if (item.get('allowImmInfoShare')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="allowHealthInfoExInputContainer">
                    <label class="plist-label" for="allowHealthInfoEx"><?php echo xlt('Allow Health Info Exchange')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowHealthInfoEx0" name="allowHealthInfoEx" type="radio" value="NO"<% if (item.get('allowHealthInfoEx')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowHealthInfoEx1" name="allowHealthInfoEx" type="radio" value="YES"<% if (item.get('allowHealthInfoEx')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input id="allowHealthInfoEx2" name="allowHealthInfoEx" type="radio" value=""<% if (item.get('allowHealthInfoEx')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group" id="allowPatientPortalInputContainer">
                    <label class="plist-label" for="allowPatientPortal"><?php echo xlt('Allow Patient Portal')?></label>
                    <div class="controls inline-inputs">
                            <label class="btn btn-secondary btn-gradient btn-sm"><input disabled id="allowPatientPortal0" name="allowPatientPortal" type="radio" value="NO"<% if (item.get('allowPatientPortal')=="NO") { %> checked="checked"<% } %>><?php echo xlt('NO'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input disabled id="allowPatientPortal1" name="allowPatientPortal" type="radio" value="YES"<% if (item.get('allowPatientPortal')=="YES") { %> checked="checked"<% } %>><?php echo xlt('YES'); ?></label>
                            <label class="btn btn-secondary btn-gradient btn-sm"><input disabled id="allowPatientPortal2" name="allowPatientPortal" type="radio" value=""<% if (item.get('allowPatientPortal')=="") { %> checked="checked"<% } %>><?php echo xlt('Unassigned'); ?></label>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group dynhide" id="careTeamInputContainer">
                    <label class="plist-label" for="careTeam"><?php echo xlt('Care Team')?></label>
                    <div class="controls inline-inputs">
                        <!-- disabled for now. Patient doesn't have a need to select what care team they belong -->
                        <select disabled class="form-control" id="careTeam"
                            title="<?php echo xla('Care Team'); ?>" value="<?php echo $row['careTeam']; ?>">
                            <?php
                            echo "<option value=''>" . xlt('Unassigned') . "</option>";
                            foreach ($this->users_list as $user) {
                                $user_name = text($user['fname'] . ' ' . $user['lname']);
                                $id = attr($user['id']);
                                echo "<option value='$id'";
                                if ((int)$id === (int)$row['careTeam']) {
                                    echo " selected";
                                }
                                echo ">$user_name</option>";
                            }
                            ?>
                        </select>
                        <span class="help-inline"></span>
                    </div>
                </div>
                <div class="col-sm-auto px-3 form-group plist-group dynhide" id="noteInputContainer">
                    <label class="plist-label" style="color:green" for="note"><?php echo xlt('Comments about change request')?></label>
                    <div class="controls inline-inputs">
                        <textarea class="form-control" id="note" rows="2" col="160" style='min-width:380px'><%= _.escape("To Admin: ") %></textarea>
                        <span class="help-inline"></span>
                    </div>
                </div>
                </div>
            </fieldset>
        </form>
</script>
</div><!-- hidden templates -->
</body>
</html>
