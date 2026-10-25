<?php

/**
 * OnsiteActivityViewListView.tpl.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2016-2020 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

$this->assign('title', xlt('Portal') . ' | ' . xlt('Activity'));
$this->assign('nav', 'onsiteactivityviews');

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('patientportal', 'portal')) {
    die(xlt("Unauthorized"));
    exit;
}

// Pending portal appointment requests
$pendingAppts = [];
$pendingCount = 0;
try {
    $pendingRes = sqlStatement(
        "SELECT e.pc_eid, e.pc_eventDate, e.pc_startTime, e.pc_duration,
                e.pc_apptstatus, e.pc_hometext, e.pc_pid,
                p.fname, p.lname, p.pid,
                c.pc_catname, c.pc_catcolor,
                CONCAT(u.fname, ' ', u.lname) AS provider_name
         FROM openemr_postcalendar_events e
         LEFT JOIN patient_data p  ON p.pid  = e.pc_pid
         LEFT JOIN openemr_postcalendar_categories c ON c.pc_catid = e.pc_catid
         LEFT JOIN users u ON u.id = e.pc_aid
         WHERE e.pc_apptstatus = '^'
           AND DATE(e.pc_eventDate) >= CURDATE()
           AND e.pc_pid > 0
         ORDER BY e.pc_eventDate ASC, e.pc_startTime ASC
         LIMIT 50",
        []
    );
    if ($pendingRes !== false) {
        while ($r = sqlFetchArray($pendingRes)) {
            $pendingAppts[] = $r;
        }
    }
    $pendingCount = count($pendingAppts);
} catch (Throwable $ex) {
    error_log("OnsiteActivity pending appts: " . $ex->getMessage());
}

$this->display('_FormsHeader.tpl.php');
echo "<script>var cuser='" . $this->cuser . "';</script>";
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap');

:root {
    --c-navy:         #1a1540;
    --c-purple:       #7c3aed;
    --c-purple-mid:   #6d28d9;
    --c-purple-pale:  #f5f3ff;
    --c-purple-light: #ede9fe;
    --c-accent:       #8b5cf6;
    --c-amber:        #f59e0b;
    --c-amber-bg:     #fef3c7;
    --c-green:        #10b981;
    --c-green-bg:     #d1fae5;
    --c-rose:         #ef4444;
    --c-rose-bg:      #fee2e2;
    --c-blue:         #3b82f6;
    --c-blue-bg:      #dbeafe;
    --c-border:       #e5e7eb;
    --c-border2:      #d1d5db;
    --c-text:         #111827;
    --c-text2:        #374151;
    --c-muted:        #6b7280;
    --c-muted2:       #9ca3af;
    --c-bg:           #f5f3ff;
    --c-white:        #ffffff;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
#mdi-page{font-family:'Montserrat',sans-serif!important;background:var(--c-bg);min-height:100vh;color:var(--c-text);}
#mdi-page *{font-family:'Montserrat',sans-serif!important;}

/* COLLECTION TABLE — scoped to avoid global Bootstrap/OpenEMR bleed */
.collectionContainer{overflow-x:auto;}
.collectionContainer table{width:100%;border-collapse:collapse;font-size:.74rem;background:#fff;}
.collectionContainer table thead tr{background:var(--c-navy) !important;color:rgba(255,255,255,.65) !important;}
.collectionContainer table thead th{background:var(--c-navy) !important;color:rgba(255,255,255,.65) !important;font-size:.6rem !important;font-weight:700 !important;letter-spacing:.1em;text-transform:uppercase !important;padding:9px 12px !important;border-right:1px solid rgba(255,255,255,.07) !important;border-bottom:none !important;border-top:none !important;white-space:nowrap;cursor:pointer;user-select:none;vertical-align:middle !important;}
.collectionContainer table thead th:last-child{border-right:none !important;}
.collectionContainer table thead th:hover{color:#fff !important;background:rgba(255,255,255,.06) !important;}
.collectionContainer table tbody tr{background:#fff !important;color:var(--c-text2) !important;font-size:.74rem !important;font-weight:400 !important;text-transform:none !important;border-bottom:1px solid var(--c-border) !important;cursor:pointer;transition:background .1s;}
.collectionContainer table tbody tr:last-child{border-bottom:none !important;}
.collectionContainer table tbody tr:hover{background:var(--c-purple-light) !important;}
.collectionContainer table tbody td{padding:8px 12px !important;color:var(--c-text2) !important;font-size:.74rem !important;font-weight:400 !important;text-transform:none !important;vertical-align:middle !important;border-right:1px solid rgba(0,0,0,.03) !important;border-top:none !important;}
.collectionContainer table tbody td:last-child{border-right:none !important;}
.td-date-p{font-weight:600 !important;color:var(--c-text) !important;white-space:nowrap;}
.td-date-d{display:block;font-size:.62rem !important;color:var(--c-muted2) !important;font-weight:400 !important;}
.collectionContainer .pagination{margin:.6rem 1.2rem;display:flex;gap:3px;}
.collectionContainer .page-item .page-link{background:#fff;border:1px solid var(--c-border);color:var(--c-text2);border-radius:6px !important;font-size:.7rem;padding:4px 10px;}
.collectionContainer .page-item .page-link:hover{background:var(--c-purple-light);border-color:var(--c-accent);color:var(--c-purple);}
.collectionContainer .page-item.active .page-link{background:var(--c-purple);border-color:var(--c-purple);color:#fff;}

/* TOP BAR */
.pa-topbar{background:var(--c-navy);height:52px;display:flex;align-items:center;justify-content:space-between;padding:0 1.4rem;gap:1rem;border-bottom:2px solid rgba(139,92,246,.35);position:sticky;top:0;z-index:100;}
.pa-topbar-l{display:flex;align-items:center;gap:10px;}
.pa-topbar-r{display:flex;align-items:center;gap:8px;}
.pa-icon{width:30px;height:30px;border-radius:7px;background:linear-gradient(135deg,var(--c-purple),var(--c-accent));display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.pa-title{font-size:.85rem;font-weight:700;color:#fff;letter-spacing:.01em;}
.pa-title em{color:rgba(139,92,246,.85);font-style:normal;font-weight:500;}
.pa-search{position:relative;}
.pa-search input{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.16);border-radius:7px;color:#fff;font-size:.73rem;padding:5px 11px 5px 30px;width:200px;outline:none;transition:border-color .18s,background .18s;}
.pa-search input::placeholder{color:rgba(255,255,255,.35);}
.pa-search input:focus{background:rgba(255,255,255,.14);border-color:var(--c-accent);}
.pa-search svg{position:absolute;left:9px;top:50%;transform:translateY(-50%);color:rgba(255,255,255,.35);pointer-events:none;}
#returnHome{background:rgba(255,255,255,.1)!important;border:1px solid rgba(255,255,255,.2)!important;color:rgba(255,255,255,.8)!important;border-radius:7px!important;font-size:.7rem!important;font-weight:600!important;padding:5px 13px!important;display:inline-flex;align-items:center;gap:5px;transition:all .15s;}
#returnHome:hover{background:rgba(255,255,255,.17)!important;color:#fff!important;}

/* BODY GRID */
.pa-body{display:grid;grid-template-columns:1fr 345px;gap:1.2rem;padding:1.3rem 1.4rem 3rem;align-items:start;}
@media(max-width:1100px){.pa-body{grid-template-columns:1fr;}}

/* CARDS */
.pa-card{background:var(--c-white);border:1px solid var(--c-border);border-radius:11px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.07);animation:paUp .32s ease both;}
.pa-card.d1{animation-delay:.1s;}
@keyframes paUp{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}
.pa-card-head{background:var(--c-navy);padding:0 1.2rem;height:44px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(139,92,246,.25);}
.pa-head-title{font-size:.76rem;font-weight:700;color:#fff;display:flex;align-items:center;gap:7px;letter-spacing:.02em;}
.pa-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.pa-dot-v{background:var(--c-accent);box-shadow:0 0 5px var(--c-accent);}
.pa-dot-a{background:var(--c-amber);box-shadow:0 0 5px rgba(245,158,11,.6);}
.pa-dot-g{background:var(--c-green);box-shadow:0 0 5px rgba(16,185,129,.6);}

/* CHIPS STRIP */
.pa-strip{display:flex;align-items:center;gap:6px;padding:.55rem 1.2rem;border-bottom:1px solid var(--c-border);background:#fafafa;}
.pa-chip{display:inline-flex;align-items:center;gap:5px;font-size:.7rem;font-weight:600;color:var(--c-text2);background:#fff;border:1px solid var(--c-border);border-radius:6px;padding:3px 10px 3px 7px;user-select:none;}
.pa-chip-n{display:inline-flex;align-items:center;justify-content:center;width:19px;height:19px;border-radius:5px;font-size:.67rem;font-weight:800;color:#fff;}
.pa-chip-n.v{background:var(--c-purple);}

/* ACTIVITY TABLE */
.pa-tbl-wrap{overflow-x:auto;}
.pa-tbl{width:100%;border-collapse:collapse;font-size:.74rem;}
.pa-tbl thead tr{background:var(--c-navy);}
.pa-tbl thead th{color:rgba(255,255,255,.65);font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:8px 11px;border-right:1px solid rgba(255,255,255,.07);white-space:nowrap;cursor:pointer;user-select:none;transition:color .13s,background .13s;}
.pa-tbl thead th:last-child{border-right:none;}
.pa-tbl thead th:hover{color:#fff;background:rgba(255,255,255,.06);}
.pa-tbl thead th.s-asc::after{content:' \2191';color:var(--c-accent);}
.pa-tbl thead th.s-desc::after{content:' \2193';color:var(--c-accent);}
.pa-tbl tbody tr{border-bottom:1px solid var(--c-border);cursor:pointer;transition:background .1s;}
.pa-tbl tbody tr:last-child{border-bottom:none;}
.pa-tbl tbody tr:hover{background:var(--c-purple-light);}
.pa-tbl tbody td{padding:8px 11px;color:var(--c-text2);vertical-align:middle;border-right:1px solid rgba(0,0,0,.03);}
.pa-tbl tbody td:last-child{border-right:none;}
.pa-tbl .td-p{color:var(--c-text);font-weight:600;white-space:nowrap;}
.pa-tbl .td-nr{max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pa-tbl .td-dim{color:var(--c-muted2);font-size:.62rem;}
.pa-pill{display:inline-flex;align-items:center;gap:3px;font-size:.61rem;font-weight:700;padding:2px 8px;border-radius:20px;white-space:nowrap;letter-spacing:.04em;}
.pa-pill::before{content:'\25B2';font-size:.42rem;opacity:.75;}
.pa-pill-a{background:var(--c-amber-bg);color:#92400e;}
.pa-pill-g{background:var(--c-green-bg);color:#065f46;}
.pa-pill-v{background:var(--c-purple-light);color:#5b21b6;}
.pa-pill-b{background:var(--c-blue-bg);color:#1e40af;}
.pa-pill-r{background:var(--c-rose-bg);color:#991b1b;}
.pa-pill-x{background:#f3f4f6;color:#4b5563;}
.pa-empty-row td{text-align:center;padding:2.5rem!important;color:var(--c-muted);font-size:.78rem;}
#loader{margin-left:8px;vertical-align:middle;}
.pa-paging{padding:.75rem 1.2rem;border-top:1px solid var(--c-border);background:#fafafa;}
.pa-paging .pagination{margin:0;display:flex;gap:3px;align-items:center;}
.pa-paging .page-item .page-link{background:#fff;border:1px solid var(--c-border);color:var(--c-text2);border-radius:6px!important;font-size:.7rem;padding:4px 10px;transition:all .14s;}
.pa-paging .page-item .page-link:hover{background:var(--c-purple-light);border-color:var(--c-accent);color:var(--c-purple);}
.pa-paging .page-item.active .page-link{background:var(--c-purple);border-color:var(--c-purple);color:#fff;}
#collectionAlert .alert{margin:.6rem 1.2rem;border-radius:7px;font-size:.76rem;}

/* PENDING PANEL */
.pa-pend-list{max-height:530px;overflow-y:auto;}
.pa-pend-list::-webkit-scrollbar{width:3px;}
.pa-pend-list::-webkit-scrollbar-thumb{background:var(--c-border2);border-radius:2px;}
.pa-row{display:flex;align-items:flex-start;gap:10px;padding:.8rem 1.2rem;border-bottom:1px solid var(--c-border);cursor:pointer;transition:background .12s;}
.pa-row:last-child{border-bottom:none;}
.pa-row:hover{background:var(--c-purple-pale);}
.pa-av{width:34px;height:34px;border-radius:8px;flex-shrink:0;background:linear-gradient(135deg,var(--c-purple),var(--c-accent));display:flex;align-items:center;justify-content:center;color:#fff;font-size:.62rem;font-weight:800;}
.pa-rb{flex:1;min-width:0;}
.pa-rcat{font-size:.56rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--c-purple);background:var(--c-purple-light);border-radius:4px;padding:1px 6px;display:inline-block;margin-bottom:3px;}
.pa-rname{font-size:.79rem;font-weight:700;color:var(--c-text);margin-bottom:4px;}
.pa-rmeta{display:flex;flex-wrap:wrap;gap:7px;}
.pa-rmi{display:flex;align-items:center;gap:3px;font-size:.61rem;color:var(--c-muted);}
.pa-rnote{font-size:.61rem;color:var(--c-muted);font-style:italic;margin-top:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.pa-pend-footer{display:flex;align-items:center;justify-content:space-between;padding:.6rem 1.2rem;border-top:1px solid var(--c-border);background:var(--c-purple-pale);}
.pa-pend-footer span{font-size:.61rem;color:var(--c-muted);}
.pa-pend-footer a{font-size:.63rem;color:var(--c-purple);font-weight:700;text-decoration:none;}
.pa-empty-pend{display:flex;flex-direction:column;align-items:center;gap:7px;padding:2.5rem 1rem;color:var(--c-muted);font-size:.76rem;text-align:center;}
.pa-empty-pend svg{opacity:.28;}

/* DOCUMENT VIEWER MODAL */
#doc-modal-bg{display:none;position:fixed;inset:0;background:rgba(10,8,25,.72);backdrop-filter:blur(3px);z-index:9980;flex-direction:column;}
#doc-modal-bg.open{display:flex;}
.doc-modal-bar{background:var(--c-navy);height:50px;flex-shrink:0;display:flex;align-items:center;justify-content:space-between;padding:0 1.2rem;border-bottom:2px solid rgba(139,92,246,.35);gap:1rem;}
.doc-modal-bar-l{display:flex;align-items:center;gap:10px;min-width:0;}
.doc-modal-icon{width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,var(--c-purple),var(--c-accent));display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.doc-modal-title{font-size:.82rem;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.doc-modal-title span{color:rgba(255,255,255,.45);font-weight:500;margin-left:6px;font-size:.7rem;}
.doc-modal-bar-r{display:flex;align-items:center;gap:7px;flex-shrink:0;}
.doc-mbar-btn{display:inline-flex;align-items:center;gap:5px;font-size:.68rem;font-weight:600;padding:5px 13px;border-radius:7px;cursor:pointer;border:none;font-family:'Montserrat',sans-serif;transition:all .15s;}
.doc-mbar-btn-open{background:var(--c-purple);color:#fff;box-shadow:0 2px 8px rgba(124,58,237,.35);}
.doc-mbar-btn-open:hover{box-shadow:0 4px 16px rgba(124,58,237,.45);transform:translateY(-1px);}
.doc-mbar-close{background:rgba(255,255,255,.1);border:none;border-radius:7px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.7);cursor:pointer;transition:background .14s;flex-shrink:0;}
.doc-mbar-close:hover{background:rgba(239,68,68,.35);color:#fff;}
.doc-modal-foot{background:var(--c-navy);height:46px;flex-shrink:0;display:flex;align-items:center;padding:0 1.2rem;gap:8px;border-top:1px solid rgba(139,92,246,.2);}
.doc-foot-action{display:inline-flex;align-items:center;gap:5px;font-size:.67rem;font-weight:600;padding:5px 13px;border-radius:7px;cursor:pointer;border:1px solid rgba(255,255,255,.14)!important;font-family:'Montserrat',sans-serif;transition:all .15s;background:rgba(255,255,255,.08);color:rgba(255,255,255,.75);text-decoration:none;}
.doc-foot-action:hover{background:rgba(255,255,255,.15);color:#fff;text-decoration:none;}
.doc-foot-action.primary{background:var(--c-purple);color:#fff;border-color:transparent!important;box-shadow:0 2px 8px rgba(124,58,237,.3);}
.doc-foot-action.primary:hover{box-shadow:0 4px 14px rgba(124,58,237,.45);transform:translateY(-1px);}
.doc-foot-sep{flex:1;}
.doc-foot-label{font-size:.62rem;color:rgba(255,255,255,.35);}
#doc-frame{flex:1;border:none;background:#fff;display:block;width:100%;}
#doc-frame-loading{position:absolute;inset:50px 0 46px 0;background:#fff;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:12px;z-index:1;transition:opacity .3s;}
#doc-frame-loading.hide{opacity:0;pointer-events:none;}
.doc-spinner{width:36px;height:36px;border:3px solid rgba(124,58,237,.15);border-top-color:var(--c-purple);border-radius:50%;animation:spin .75s linear infinite;}
.doc-spinner-text{font-size:.72rem;color:var(--c-muted);}
@keyframes spin{to{transform:rotate(360deg)}}

/* APPROVE MODAL */
#pa-modal-bg{display:none;position:fixed;inset:0;background:rgba(10,8,25,.68);backdrop-filter:blur(4px);z-index:9990;align-items:center;justify-content:center;}
#pa-modal-bg.open{display:flex;}
#pa-modal{background:#fff;border-radius:14px;width:100%;max-width:475px;margin:1rem;box-shadow:0 20px 60px rgba(0,0,0,.22);overflow:hidden;animation:paModalIn .22s cubic-bezier(.34,1.56,.64,1) both;}
@keyframes paModalIn{from{opacity:0;transform:scale(.94) translateY(10px)}to{opacity:1;transform:none}}
.pa-mhead{background:var(--c-navy);padding:1.1rem 1.4rem .9rem;display:flex;align-items:flex-start;justify-content:space-between;border-bottom:2px solid rgba(139,92,246,.28);}
.pa-mpt{font-size:.98rem;font-weight:700;color:#fff;}
.pa-msub{font-size:.64rem;color:rgba(255,255,255,.45);margin-top:2px;}
.pa-mx{background:rgba(255,255,255,.1);border:none;border-radius:7px;width:27px;height:27px;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.65);cursor:pointer;transition:background .14s;flex-shrink:0;}
.pa-mx:hover{background:rgba(255,255,255,.2);color:#fff;}
.pa-mbody{padding:1.2rem 1.4rem;}
.pa-dgrid{display:grid;grid-template-columns:1fr 1fr;gap:.8rem 1rem;}
.pa-dl{font-size:.57rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--c-muted);margin-bottom:3px;}
.pa-dv{font-size:.79rem;color:var(--c-text);font-weight:600;}
.pa-div{height:1px;background:var(--c-border);margin:1rem 0;}
.pa-fl{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--c-muted);margin-bottom:5px;}
.pa-sel{width:100%;background:#fff;border:1px solid var(--c-border2);border-radius:8px;color:var(--c-text);font-size:.78rem;padding:8px 10px;outline:none;font-family:'Montserrat',sans-serif;transition:border-color .18s,box-shadow .18s;cursor:pointer;}
.pa-sel:focus{border-color:var(--c-purple);box-shadow:0 0 0 3px rgba(124,58,237,.11);}
.pa-info{background:#fffbeb;border:1px solid #fde68a;border-radius:7px;padding:8px 11px;font-size:.66rem;color:#78350f;display:flex;align-items:flex-start;gap:6px;margin-top:.9rem;line-height:1.5;}
.pa-mfoot{padding:.85rem 1.4rem 1.1rem;display:flex;gap:7px;justify-content:flex-end;border-top:1px solid var(--c-border);background:#fafafa;}
.pa-btn{font-size:.72rem;font-weight:700;padding:7px 16px;border-radius:7px;cursor:pointer;border:none;font-family:'Montserrat',sans-serif;transition:all .15s;display:inline-flex;align-items:center;gap:5px;}
.pa-btn-g{background:#fff;border:1px solid var(--c-border2)!important;color:var(--c-muted);}
.pa-btn-g:hover{border-color:var(--c-purple)!important;color:var(--c-purple);}
.pa-btn-s{background:#fff;border:1px solid var(--c-border2)!important;color:var(--c-text2);}
.pa-btn-s:hover{border-color:var(--c-purple)!important;color:var(--c-purple);}
.pa-btn-p{background:linear-gradient(135deg,var(--c-purple),var(--c-purple-mid));color:#fff;box-shadow:0 2px 9px rgba(124,58,237,.28);}
.pa-btn-p:hover{box-shadow:0 4px 16px rgba(124,58,237,.42);transform:translateY(-1px);}
.pa-btn-p:disabled{opacity:.5;cursor:not-allowed;transform:none;}

/* TOAST */
.pa-toast{position:fixed;bottom:1.4rem;right:1.4rem;background:var(--c-navy);border-radius:9px;padding:9px 15px;display:flex;align-items:center;gap:8px;font-size:.72rem;color:#fff;box-shadow:0 8px 28px rgba(0,0,0,.2);z-index:99999;pointer-events:none;transform:translateY(14px);opacity:0;transition:all .26s cubic-bezier(.34,1.56,.64,1);}
.pa-toast.show{transform:none;opacity:1;}
.pa-ti{width:21px;height:21px;border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.pa-ts .pa-ti{background:rgba(16,185,129,.2);color:#34d399;}
.pa-te .pa-ti{background:rgba(239,68,68,.2);color:#f87171;}

/* Tab buttons */
.pa-tab-btn{flex:1;font-size:.71rem;font-weight:700;padding:7px 10px;border-radius:7px;cursor:pointer;border:1px solid var(--c-border2);background:#fff;color:var(--c-muted);font-family:'Montserrat',sans-serif;transition:all .15s;display:inline-flex;align-items:center;justify-content:center;gap:5px;}
.pa-tab-btn.active{background:linear-gradient(135deg,var(--c-purple),var(--c-purple-mid));color:#fff;border-color:transparent;box-shadow:0 2px 9px rgba(124,58,237,.28);}
.pa-tab-btn.deny-active{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border-color:transparent;box-shadow:0 2px 9px rgba(220,38,38,.28);}
.pa-textarea{width:100%;background:#fff;border:1px solid var(--c-border2);border-radius:8px;color:var(--c-text);font-size:.76rem;padding:9px 11px;outline:none;font-family:'Montserrat',sans-serif;transition:border-color .18s,box-shadow .18s;resize:vertical;min-height:72px;box-sizing:border-box;margin-top:5px;}
.pa-textarea:focus{border-color:var(--c-purple);box-shadow:0 0 0 3px rgba(124,58,237,.11);}
.pa-btn-deny{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;box-shadow:0 2px 9px rgba(220,38,38,.28);}
.pa-btn-deny:hover{box-shadow:0 4px 16px rgba(220,38,38,.42);transform:translateY(-1px);}
.pa-btn-deny:disabled{opacity:.5;cursor:not-allowed;transform:none;}
</style>
<script>
    $LAB.script("<?php echo $GLOBALS['web_root']; ?>/portal/patient/scripts/app/onsiteactivityviews.js?v=<?php echo $GLOBALS['v_js_includes']; ?>").wait(function(){
        $(function () {
            actpage.init();
        });
        setTimeout(function(){
            if (!actpage.isInitialized) actpage.init();
        },1000);
    });
</script>
<script>
function searchResults() {
    var input = document.getElementById('srch-term').value.toLowerCase();
    var table = document.getElementById('onsiteActivityViewCollectionContainer');
    if (!table) return;
    var rows = table.getElementsByTagName('tr');
    var visible = 0;
    for (var i = 1; i < rows.length; i++) { // start at 1 to skip thead
        var cells = rows[i].getElementsByTagName('td');
        var found = false;
        for (var j = 0; j < cells.length; j++) {
            if (cells[j].textContent.toLowerCase().indexOf(input) !== -1) {
                found = true;
                break;
            }
        }
        rows[i].style.display = found ? '' : 'none';
        if (found) visible++;
    }
    var tn = document.getElementById('pa-total-n');
    if (tn) tn.textContent = input ? visible : (rows.length - 1);
}
</script>
<div class="pa-topbar">
        <div class="pa-topbar-l">
            <div class="pa-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>
            </div>
            <div class="pa-title">
                <?php echo xlt('Portal'); ?> <em>| <?php echo xlt('Activity'); ?></em>
                <span id="loader" class="loader progress progress-striped active" style="width:46px;height:11px;font-size:0;margin-left:8px;vertical-align:middle;display:inline-block;"><span class="progress-bar"></span></span>
            </div>
        </div>
        <div class="pa-topbar-r">
            <div class="pa-search">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" class="form-control" placeholder="<?php echo xla('Search'); ?>" name="srch-term" id="srch-term" onkeyup="searchResults()"/>
            </div>
           
        </div>
    </div>
<div class="pa-body">

        <div>
            <div class="pa-card">
                <div class="pa-card-head">
                    <div class="pa-head-title">
                        <span class="pa-dot pa-dot-v"></span>
                        Onsite Patient Activities                    </div>
                    <span style="font-size:.6rem;color:rgba(255,255,255,.4);font-weight:500;">Click any row to open document</span>
                </div>
                <div class="pa-strip">
                    <span class="pa-chip">
                        <span class="pa-chip-n v" id="pa-total-n">-</span>
                        <?php echo xlt('Total'); ?>
                    </span>
                </div>

    <!-- underscore template for the collection -->
    <script type="text/template" id="onsiteActivityViewCollectionTemplate">
        <% var _tn = document.getElementById('pa-total-n'); if(_tn) _tn.textContent = items.length; %>
        <table class="collection table table-sm table-bordered table-hover" id="onsiteActivityViewCollectionContainer">
        <thead>
            <tr>
                <th id="header_Date"><?php echo xlt('Date')?><% if (actpage.orderBy == 'Date') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_PatientId"><?php echo xlt('Patient Id')?><% if (actpage.orderBy == 'PatientId') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Fname"><?php echo xlt('First{{Name}}')?><% if (actpage.orderBy == 'Fname') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Mname"><?php echo xlt('Middle{{Name}}')?><% if (actpage.orderBy == 'Mname') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Lname"><?php echo xlt('Last{{Name}}')?><% if (actpage.orderBy == 'Lname') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Narrative"><?php echo xlt('Narrative')?><% if (actpage.orderBy == 'Narrative') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Activity"><?php echo xlt('Activity')?><% if (actpage.orderBy == 'Activity') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_RequireAudit"><?php echo xlt('Require Audit')?><% if (actpage.orderBy == 'RequireAudit') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_PendingAction"><?php echo xlt('Pending Action')?><% if (actpage.orderBy == 'PendingAction') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Status"><?php echo xlt('Status')?><% if (actpage.orderBy == 'Status') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
<!-- UNCOMMENT TO SHOW ADDITIONAL COLUMNS - Leave in place for future use
                <th id="header_Id">Id<% if (actpage.orderBy == 'Id') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_ActionTaken">Action Taken<% if (actpage.orderBy == 'ActionTaken') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_TableAction">Table Action<% if (actpage.orderBy == 'TableAction') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_TableArgs">Table Args<% if (actpage.orderBy == 'TableArgs') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_ActionUser">Action User<% if (actpage.orderBy == 'ActionUser') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_ActionTakenTime">Action Taken Time<% if (actpage.orderBy == 'ActionTakenTime') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Checksum">Checksum<% if (actpage.orderBy == 'Checksum') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Title">Title<% if (actpage.orderBy == 'Title') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Dob">Dob<% if (actpage.orderBy == 'Dob') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Ss">Ss<% if (actpage.orderBy == 'Ss') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Street">Street<% if (actpage.orderBy == 'Street') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_PostalCode">Postal Code<% if (actpage.orderBy == 'PostalCode') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_City">City<% if (actpage.orderBy == 'City') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_State">State<% if (actpage.orderBy == 'State') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Referrerid">Referrerid<% if (actpage.orderBy == 'Referrerid') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Providerid">Providerid<% if (actpage.orderBy == 'Providerid') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_RefProviderid">Ref Providerid<% if (actpage.orderBy == 'RefProviderid') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Pubpid">Pubpid<% if (actpage.orderBy == 'Pubpid') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_CareTeam">Care Team<% if (actpage.orderBy == 'CareTeam') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Username">Username<% if (actpage.orderBy == 'Username') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Authorized">Authorized<% if (actpage.orderBy == 'Authorized') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Ufname">Ufname<% if (actpage.orderBy == 'Ufname') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Umname">Umname<% if (actpage.orderBy == 'Umname') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Ulname">Ulname<% if (actpage.orderBy == 'Ulname') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Facility">Facility<% if (actpage.orderBy == 'Facility') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Active">Active<% if (actpage.orderBy == 'Active') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_Utitle">Utitle<% if (actpage.orderBy == 'Utitle') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
                <th id="header_PhysicianType">Physician Type<% if (actpage.orderBy == 'PhysicianType') { %> <i class='icon-arrow-<%= actpage.orderDesc ? 'up' : 'down' %>' /><% } %></th>
-->
            </tr>
        </thead>
        <tbody>
        <% items.each(function(item) { %>
            <tr id="<%= _.escape(item.get('id')) %>">
                <td><span class="td-date-p"><%if (item.get('date')) { %><%= moment(app.parseDate(item.get('date'))).format('MMM D, YYYY') %><% } else { %>—<% } %></span><span class="td-date-d"><%if (item.get('date')) { %><%= moment(app.parseDate(item.get('date'))).format('h:mm A') %><% } %></span></td>
                <td><%= _.escape(item.get('patientId') || '') %></td>
                <td><%= _.escape(item.get('fname') || '') %></td>
                <td><%= _.escape(item.get('mname') || '') %></td>
                <td><%= _.escape(item.get('lname') || '') %></td>
                <td><%= _.escape(item.get('narrative') || '') %></td>
                <td><%= _.escape(item.get('activity') || '') %></td>
                <td><% var ra = (item.get('requireAudit') || '').toString().toLowerCase(); %>
                    <% if (ra === 'yes' || ra === '1' || ra === 'true') { %><span class="pa-pill pa-pill-a">Yes</span><% } else if (ra && ra !== '0' && ra !== 'no') { %><span class="pa-pill pa-pill-x"><%= _.escape(item.get('requireAudit')) %></span><% } else { %><span class="pa-pill pa-pill-x">No</span><% } %></td>
                <td><% var pa = (item.get('pendingAction') || '').toString().toLowerCase(); %>
                    <% if (pa === 'review' || pa === 'pending' || pa === 'waiting') { %><span class="pa-pill pa-pill-v">review</span><% } else if (pa) { %><span class="pa-pill pa-pill-x"><%= _.escape(item.get('pendingAction')) %></span><% } else { %><span class="pa-pill pa-pill-x">—</span><% } %></td>
                <td><% var st = (item.get('status') || '').toString().toLowerCase(); %>
                    <% if (st === 'waiting' || st === 'pending' || st === 'open') { %><span class="pa-pill pa-pill-a">waiting</span>
                    <% } else if (st === 'closed' || st === 'done' || st === 'complete' || st === 'completed') { %><span class="pa-pill pa-pill-g">done</span>
                    <% } else if (st === 'active' || st === 'in progress') { %><span class="pa-pill pa-pill-b">active</span>
                    <% } else if (st) { %><span class="pa-pill pa-pill-x"><%= _.escape(item.get('status')) %></span>
                    <% } else { %><span class="pa-pill pa-pill-x">—</span><% } %></td>
<!-- UNCOMMENT TO SHOW ADDITIONAL COLUMNS - Leave in place for future use
                <td><%= _.escape(item.get('id') || '') %></td>
                <td><%= _.escape(item.get('actionTaken') || '') %></td>
                <td><%= _.escape(item.get('tableAction') || '') %></td>
                <td><%= _.escape(item.get('tableArgs') || '') %></td>
                <td><%= _.escape(item.get('actionUser') || '') %></td>
                <td><%if (item.get('actionTakenTime')) { %><%= moment(app.parseDate(item.get('actionTakenTime'))).format('MMM D, YYYY h:mm A') %><% } else { %>NULL<% } %></td>
                <td><%= _.escape(item.get('checksum') || '') %></td>
                <td><%= _.escape(item.get('title') || '') %></td>
                <td><%if (item.get('dob')) { %><%= moment(app.parseDate(item.get('dob'))).format('MMM D, YYYY') %><% } else { %>NULL<% } %></td>
                <td><%= _.escape(item.get('ss') || '') %></td>
                <td><%= _.escape(item.get('street') || '') %></td>
                <td><%= _.escape(item.get('postalCode') || '') %></td>
                <td><%= _.escape(item.get('city') || '') %></td>
                <td><%= _.escape(item.get('state') || '') %></td>
                <td><%= _.escape(item.get('referrerid') || '') %></td>
                <td><%= _.escape(item.get('providerid') || '') %></td>
                <td><%= _.escape(item.get('refProviderid') || '') %></td>
                <td><%= _.escape(item.get('pubpid') || '') %></td>
                <td><%= _.escape(item.get('careTeam') || '') %></td>
                <td><%= _.escape(item.get('username') || '') %></td>
                <td><%= _.escape(item.get('authorized') || '') %></td>
                <td><%= _.escape(item.get('ufname') || '') %></td>
                <td><%= _.escape(item.get('umname') || '') %></td>
                <td><%= _.escape(item.get('ulname') || '') %></td>
                <td><%= _.escape(item.get('facility') || '') %></td>
                <td><%= _.escape(item.get('active') || '') %></td>
                <td><%= _.escape(item.get('utitle') || '') %></td>
                <td><%= _.escape(item.get('physicianType') || '') %></td>
-->
            </tr>
        <% }); %>
        </tbody>
        </table>
        <%=  view.getPaginationHtml(page) %>
    </script>
    <!-- underscore template for the model -->
    <script type="text/template" id="onsiteActivityViewModelTemplate"></script>
    <div id="collectionAlert"></div>
    <div id="onsiteActivityViewCollectionContainer" class="collectionContainer"></div>
            </div><!-- /pa-card -->
        </div><!-- /left col -->

        <!-- ═══ RIGHT COLUMN — PENDING REQUESTS ═══ -->
        <div>
            <div class="pa-card d1">
                <div class="pa-card-head">
                    <div class="pa-head-title">
                        <span class="pa-dot <?php echo $pendingCount > 0 ? 'pa-dot-a' : 'pa-dot-g'; ?>"></span>
                        <?php echo xlt('Pending Requests'); ?>
                    </div>
                    <?php if ($pendingCount > 0): ?>
                        <span class="pa-pill pa-pill-a" style="font-size:.58rem;padding:2px 9px;"><?php echo $pendingCount; ?> <?php echo xlt('pending'); ?></span>
                    <?php else: ?>
                        <span class="pa-pill pa-pill-g" style="font-size:.58rem;padding:2px 9px;"><?php echo xlt('All clear'); ?></span>
                    <?php endif; ?>
                </div>

                <div class="pa-pend-list" id="pa-pend-list">
                    <?php if (empty($pendingAppts)): ?>
                        <div class="pa-empty-pend">
                            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><polyline points="9 12 11 14 15 10"/></svg>
                            <div style="font-weight:700;"><?php echo xlt('No pending requests'); ?></div>
                            <div style="font-size:.63rem;"><?php echo xlt('All portal appointments are confirmed.'); ?></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($pendingAppts as $appt):
                            $ptF    = $appt['fname'] ?? '';
                            $ptL    = $appt['lname'] ?? '';
                            $ptName = trim($ptF . ' ' . $ptL);
                            $inits  = strtoupper(substr($ptF,0,1) . substr($ptL,0,1)) ?: '?';
                            $aDate    = !empty($appt['pc_eventDate']) ? date('M j, Y', strtotime($appt['pc_eventDate'])) : '-';
                            $aDateYmd = !empty($appt['pc_eventDate']) ? date('Ymd', strtotime($appt['pc_eventDate'])) : '';
                            $aDay   = !empty($appt['pc_eventDate']) ? date('D',      strtotime($appt['pc_eventDate'])) : '';
                            $aTime  = !empty($appt['pc_startTime'])  ? date('g:i A', strtotime($appt['pc_startTime']))  : '-';
                            $dur    = (int)(($appt['pc_duration'] ?? 0) / 60);
                            $cat    = $appt['pc_catname'] ?? 'Appointment';
                            $prov   = trim($appt['provider_name'] ?? '');
                            $note   = $appt['pc_hometext'] ?? '';
                            $eid    = (int)($appt['pc_eid'] ?? 0);
                            $pid    = (int)($appt['pid']    ?? 0);
                        ?>
                        <div class="pa-row"
                             data-eid="<?php echo $eid; ?>"
                             data-pid="<?php echo $pid; ?>"
                             data-name="<?php echo attr($ptName); ?>"
                             data-date="<?php echo attr($aDate . ($aDay ? ' - ' . $aDay : '')); ?>"
                             data-time="<?php echo attr($aTime); ?>"
                             data-dur="<?php echo $dur; ?>"
                             data-cat="<?php echo attr($cat); ?>"
                             data-prov="<?php echo attr($prov); ?>"
                             data-note="<?php echo attr($note); ?>"
                             onclick="paEditAppt(<?php echo $eid; ?>, '<?php echo $aDateYmd; ?>')">
                            <div class="pa-av"><?php echo text($inits); ?></div>
                            <div class="pa-rb">
                                <div class="pa-rcat"><?php echo text($cat); ?></div>
                                <div class="pa-rname"><?php echo text($ptName); ?></div>
                                <div class="pa-rmeta">
                                    <div class="pa-rmi">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                        <?php echo text($aDate); ?><?php echo $aDay ? ' &middot; ' . text($aDay) : ''; ?>
                                    </div>
                                    <div class="pa-rmi">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><polyline points="12 8 12 12 14 14"/></svg>
                                        <?php echo text($aTime); ?><?php echo $dur > 0 ? ' &middot; ' . $dur . ' min' : ''; ?>
                                    </div>
                                    <?php if ($prov): ?>
                                    <div class="pa-rmi">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
                                        <?php echo text($prov); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($note): ?>
                                <div class="pa-rnote"><?php echo text(substr($note, 0, 80)); ?><?php echo strlen($note) > 80 ? '...' : ''; ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <?php if ($pendingCount > 0): ?>
                <div class="pa-pend-footer">
                    <span><?php echo xlt('Click any row to open appointment'); ?></span>
                    <a href="<?php echo attr($GLOBALS['web_root']); ?>/interface/main/main_info.php" target="_top"><?php echo xlt('Open Scheduler'); ?> &rarr;</a>
                </div>
                <?php endif; ?>
            </div><!-- /pa-card -->
        </div><!-- /right col -->

    </div><!-- /pa-body -->

<!-- ═══ APPROVE / DENY MODAL ═══ -->
<div id="pa-modal-bg" onclick="paCloseModal()">
    <div id="pa-modal" onclick="event.stopPropagation()">
        <div class="pa-mhead">
            <div>
                <div class="pa-mpt" id="pa-m-name">-</div>
                <div class="pa-msub"><?php echo xlt('Portal Appointment Request'); ?></div>
            </div>
            <button class="pa-mx" onclick="paCloseModal()">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="pa-mbody">
            <div class="pa-dgrid">
                <div><div class="pa-dl"><?php echo xlt('Date'); ?></div><div class="pa-dv" id="pa-m-date">-</div></div>
                <div><div class="pa-dl"><?php echo xlt('Time'); ?></div><div class="pa-dv" id="pa-m-time">-</div></div>
                <div><div class="pa-dl"><?php echo xlt('Duration'); ?></div><div class="pa-dv" id="pa-m-dur">-</div></div>
                <div><div class="pa-dl"><?php echo xlt('Visit Type'); ?></div><div class="pa-dv" id="pa-m-cat">-</div></div>
                <div style="grid-column:1/-1"><div class="pa-dl"><?php echo xlt('Provider'); ?></div><div class="pa-dv" id="pa-m-prov">-</div></div>
            </div>
            <div id="pa-m-note-row" style="display:none;margin-top:9px;">
                <div class="pa-dl"><?php echo xlt('Patient Note'); ?></div>
                <div style="font-size:.74rem;color:var(--c-muted);font-style:italic;margin-top:3px;" id="pa-m-note"></div>
            </div>
            <div class="pa-div"></div>
            <div style="display:flex;gap:6px;margin-bottom:1rem;">
                <button class="pa-tab-btn active" id="pa-tab-approve" onclick="paSetTab('approve')">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php echo xlt('Approve'); ?>
                </button>
                <button class="pa-tab-btn" id="pa-tab-deny" onclick="paSetTab('deny')">
                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    <?php echo xlt('Deny'); ?>
                </button>
            </div>
            <div id="pa-panel-approve">
                <div class="pa-fl"><?php echo xlt('Message to patient (optional)'); ?></div>
                <textarea class="pa-textarea" id="pa-approve-msg" rows="3" placeholder="<?php echo xla('e.g. Your appointment has been confirmed. Please arrive 10 minutes early.'); ?>"></textarea>
            </div>
            <div id="pa-panel-deny" style="display:none;">
                <div class="pa-fl"><?php echo xlt('Reason / message to patient'); ?></div>
                <textarea class="pa-textarea" id="pa-deny-msg" rows="3" placeholder="<?php echo xla('e.g. Unfortunately we are unable to accommodate this time. Please call to reschedule.'); ?>"></textarea>
            </div>
        </div>
        <div class="pa-mfoot">
            <button class="pa-btn pa-btn-g" onclick="paCloseModal()"><?php echo xlt('Cancel'); ?></button>
            <button class="pa-btn pa-btn-p" id="pa-confirm-btn" onclick="paConfirm()">
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                <span id="pa-confirm-label"><?php echo xlt('Approve & Notify'); ?></span>
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="pa-toast" id="pa-toast">
    <div class="pa-ti" id="pa-ti"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg></div>
    <span id="pa-toast-msg"></span>
</div>

<script>
var paWebRoot = "<?php echo $GLOBALS['web_root']; ?>";
var paEid = null, paPid = null, paCurrentTab = 'approve';

// Open add_edit_event modal — mirrors oldEvt() in demographics.php
function paEditAppt(eventId, eventDate) {
    // eventDate must be Ymd (no dashes), e.g. "20260311"  — same as demographics jsEvent
    var title = <?php echo xlj('Edit Appointment'); ?>;
    var url = paWebRoot + '/interface/main/calendar/add_edit_event.php?date=' + encodeURIComponent(eventDate) + '&eid=' + encodeURIComponent(eventId);
    dlgopen(url, '_blank', 800, 500, '', title);
}

function _gel(id) {
    return document.getElementById(id) || { textContent:'', innerHTML:'', classList:{add:function(){},remove:function(){},contains:function(){return false;}}, style:{}, value:'', href:'', disabled:false };
}

function paSetTab(tab) {
    paCurrentTab = tab;
    var aBtn = document.getElementById('pa-tab-approve');
    var dBtn = document.getElementById('pa-tab-deny');
    var aPanel = document.getElementById('pa-panel-approve');
    var dPanel = document.getElementById('pa-panel-deny');
    var confBtn = document.getElementById('pa-confirm-btn');
    var confLabel = document.getElementById('pa-confirm-label');
    if (tab === 'approve') {
        if (aBtn) { aBtn.classList.add('active'); aBtn.classList.remove('deny-active'); }
        if (dBtn) { dBtn.classList.remove('active','deny-active'); }
        if (aPanel) aPanel.style.display = '';
        if (dPanel) dPanel.style.display = 'none';
        if (confBtn) confBtn.className = 'pa-btn pa-btn-p';
        if (confLabel) confLabel.textContent = '<?php echo xlt('Approve & Notify'); ?>';
    } else {
        if (dBtn) { dBtn.classList.add('active','deny-active'); }
        if (aBtn) { aBtn.classList.remove('active'); }
        if (dPanel) dPanel.style.display = '';
        if (aPanel) aPanel.style.display = 'none';
        if (confBtn) confBtn.className = 'pa-btn pa-btn-deny';
        if (confLabel) confLabel.textContent = '<?php echo xlt('Deny & Notify'); ?>';
    }
}

function paOpenModal(row) {
    paEid = row.dataset.eid;
    paPid = row.dataset.pid;
    _gel('pa-m-name').textContent = row.dataset.name  || '-';
    _gel('pa-m-date').textContent = row.dataset.date  || '-';
    _gel('pa-m-time').textContent = row.dataset.time  || '-';
    _gel('pa-m-dur').textContent  = row.dataset.dur   ? row.dataset.dur + ' min' : '-';
    _gel('pa-m-cat').textContent  = row.dataset.cat   || '-';
    _gel('pa-m-prov').textContent = row.dataset.prov  || '<?php echo xlt('Not assigned'); ?>';
    var nr = document.getElementById('pa-m-note-row');
    var ne = document.getElementById('pa-m-note');
    if (row.dataset.note && row.dataset.note.trim()) {
        if (ne) ne.textContent = row.dataset.note;
        if (nr) nr.style.display = '';
    } else {
        if (nr) nr.style.display = 'none';
    }
    var am = document.getElementById('pa-approve-msg');
    var dm = document.getElementById('pa-deny-msg');
    if (am) am.value = '';
    if (dm) dm.value = '';
    paSetTab('approve');
    _gel('pa-modal-bg').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function paCloseModal() {
    _gel('pa-modal-bg').classList.remove('open');
    document.body.style.overflow = '';
    paEid = null; paPid = null;
}

function paConfirm() {
    if (!paEid) return;
    var btn = document.getElementById('pa-confirm-btn');
    var lbl = document.getElementById('pa-confirm-label');
    var isApprove = (paCurrentTab === 'approve');
    var newStatus = isApprove ? '-' : 'x';
    var msgEl = document.getElementById(isApprove ? 'pa-approve-msg' : 'pa-deny-msg');
    var msg = msgEl ? msgEl.value.trim() : '';
    if (btn) btn.disabled = true;
    if (lbl) lbl.textContent = '<?php echo xlt('Saving...'); ?>';

    var fd = new FormData();
    fd.append('pc_eid', paEid);
    fd.append('pc_apptstatus', newStatus);
    fd.append('csrf_token_form', typeof csrf_token_js !== 'undefined' ? csrf_token_js : '');

    fetch(paWebRoot + '/interface/main/calendar/update_event_status.php', {
        method: 'POST', credentials: 'same-origin', body: fd
    })
    .then(function(r) { return r.ok ? r.json() : {success: false}; })
    .then(function() { return paSendMessage(paEid, paPid, isApprove, msg); })
    .then(function() {
        if (btn) btn.disabled = false;
        if (lbl) lbl.textContent = isApprove ? '<?php echo xlt('Approve & Notify'); ?>' : '<?php echo xlt('Deny & Notify'); ?>';
        var card = document.querySelector('.pa-row[data-eid="' + paEid + '"]');
        if (card) {
            card.style.transition = 'opacity .25s,transform .25s';
            card.style.opacity = '0';
            card.style.transform = 'translateX(16px)';
            setTimeout(function() {
                card.remove();
                var list = document.getElementById('pa-pend-list');
                if (list && !list.querySelector('.pa-row')) {
                    list.innerHTML = '<div class="pa-empty-pend"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><polyline points="9 12 11 14 15 10"/></svg><div style="font-weight:700;"><?php echo xlt('No pending requests'); ?></div></div>';
                }
            }, 260);
        }
        paCloseModal();
        paToast(isApprove ? '<?php echo xlt('Appointment approved & patient notified'); ?>' : '<?php echo xlt('Request denied & patient notified'); ?>', 'success');
    })
    .catch(function(err) {
        if (btn) btn.disabled = false;
        if (lbl) lbl.textContent = isApprove ? '<?php echo xlt('Approve & Notify'); ?>' : '<?php echo xlt('Deny & Notify'); ?>';
        paCloseModal();
        paToast('<?php echo xlt('Error — please try again'); ?>', 'error');
        console.error('paConfirm error:', err);
    });
}

function paSendMessage(eid, pid, isApprove, customMsg) {
    var apptDate = _gel('pa-m-date').textContent;
    var apptTime = _gel('pa-m-time').textContent;
    var cat      = _gel('pa-m-cat').textContent;
    var defaultApprove = '<?php echo xlt('Your appointment request has been approved and scheduled.'); ?>';
    var defaultDeny    = '<?php echo xlt('We were unable to approve your appointment request. Please contact us to reschedule.'); ?>';
    var body    = customMsg || (isApprove ? defaultApprove : defaultDeny);
    var subject = isApprove ? '<?php echo xlt('Appointment Confirmed'); ?>' : '<?php echo xlt('Appointment Request Update'); ?>';
    body += '\n\n<?php echo xlt('Visit Type'); ?>: ' + cat + '\n<?php echo xlt('Date'); ?>: ' + apptDate + ' ' + apptTime;
    var fd = new FormData();
    fd.append('pid', pid);
    fd.append('pc_eid', eid);
    fd.append('subject', subject);
    fd.append('body', body);
    fd.append('csrf_token_form', typeof csrf_token_js !== 'undefined' ? csrf_token_js : '');
    return fetch(paWebRoot + '/portal/messaging/handle_message.php', {
        method: 'POST', credentials: 'same-origin', body: fd
    })
    .then(function(r) { return r.ok ? r.json() : {success: false}; })
    .catch(function(e) { console.warn('paSendMessage:', e); return {success: false}; });
}

function paToast(msg, type) {
    var t = document.getElementById('pa-toast');
    var i = document.getElementById('pa-ti');
    _gel('pa-toast-msg').textContent = msg;
    if (t) t.className = 'pa-toast ' + (type === 'error' ? 'pa-te' : 'pa-ts');
    if (i) i.innerHTML = type === 'error'
        ? '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>'
        : '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
    if (t) { t.classList.add('show'); setTimeout(function(){ t.classList.remove('show'); }, 3500); }
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') paCloseModal();
});
</script>

<?php
    $this->display('_Footer.tpl.php');
?>
