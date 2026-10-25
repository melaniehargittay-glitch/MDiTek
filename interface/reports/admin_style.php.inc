<style>
:root{--bg:#f3f4f6;--s1:#fff;--border:#e5e7eb;--border2:#d1d5db;--accent:#6b4fd8;--accent2:#5b3fc8;--text:#111827;--text2:#6b7280;--text3:#9ca3af;--green:#166534;--green-bg:#dcfce7;--red:#991b1b;--red-bg:#fee2e2;--yellow:#854d0e;--yellow-bg:#fef9c3;--r:7px;--mono:monospace}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
div#container_div{padding:0!important}
body{background:var(--bg);font-family:-apple-system,'Segoe UI',sans-serif;font-size:13px;color:var(--text)}
.adm-wrap{max-width:100%}
.adm-toolbar{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:var(--s1);border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px}
.adm-title{font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}
.adm-title svg{width:18px;height:18px;color:var(--accent)}
.adm-sub{font-size:11px;font-weight:500;color:var(--text3);font-family:var(--mono)}
.adm-search-bar{padding:12px 20px;background:var(--s1);border-bottom:1px solid var(--border)}
.search-wrap{position:relative;max-width:500px}
.search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--text3);pointer-events:none}
.search-input{width:100%;padding:8px 12px 8px 34px;border:1px solid var(--border2);border-radius:var(--r);font-size:13px;outline:none;transition:border-color .15s}
.search-input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(107,79,216,.1)}
.search-spinner{display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);width:14px;height:14px;border:2px solid var(--border2);border-top-color:var(--accent);border-radius:50%;animation:spin .6s linear infinite}
@keyframes spin{to{transform:translateY(-50%) rotate(360deg)}}
.adm-card{background:var(--s1);border:1px solid var(--border);border-radius:var(--r);margin:16px 20px 20px;overflow:hidden}
.adm-card-head{background:#f9fafb;border-bottom:1px solid var(--border);padding:9px 14px;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:var(--text2);font-weight:600}
.adm-count{font-family:var(--mono);font-size:11px;color:var(--text3)}
.tbl-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12px}
thead tr{background:#f9fafb}
th{padding:8px 10px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text2);text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:8px 10px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
tbody tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#faf5ff}
.empty-row td{text-align:center;padding:28px;color:var(--text3);font-style:italic}
.tbl-btn{background:none;border:1px solid var(--border);border-radius:5px;padding:4px 9px;cursor:pointer;font-size:11px;font-weight:600;color:var(--accent);transition:all .15s;font-family:inherit}
.tbl-btn:hover{background:#ede9fe;border-color:var(--accent)}
.tbl-btn.del{color:#dc2626}
.tbl-btn.del:hover{background:var(--red-bg);border-color:#dc2626}
.badge-ok{background:var(--green-bg);color:var(--green);border-radius:20px;font-size:10px;font-weight:600;padding:2px 8px;white-space:nowrap}
.badge-off{background:var(--red-bg);color:var(--red);border-radius:20px;font-size:10px;font-weight:600;padding:2px 8px;white-space:nowrap}
.badge-neutral{background:#f3f4f6;color:var(--text2);border-radius:20px;font-size:10px;font-weight:600;padding:2px 8px}
/* Buttons */
.btn-primary{background:var(--accent);color:#fff;border:none;border-radius:var(--r);padding:0 16px;height:34px;font-size:12px;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background .15s;font-family:inherit}
.btn-primary:hover{background:var(--accent2)}
.btn-primary:disabled{opacity:.4;cursor:not-allowed}
.btn-ghost{background:transparent;color:var(--text2);border:1px solid var(--border2);border-radius:var(--r);padding:0 14px;height:34px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent)}
/* Modal */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:10px;width:92%;max-width:520px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);animation:mIn .18s ease}
@keyframes mIn{from{transform:scale(.95);opacity:0}to{transform:scale(1);opacity:1}}
.modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);font-weight:700;font-size:14px}
.modal-close{background:none;border:none;font-size:18px;cursor:pointer;color:var(--text3);line-height:1;padding:2px 6px}
.modal-close:hover{color:var(--text)}
.modal-body{padding:18px}
.modal-foot{display:flex;justify-content:flex-end;gap:8px;padding:12px 18px;border-top:1px solid var(--border)}
.modal-err{color:#dc2626;font-size:12px;margin-top:8px;min-height:16px}
/* Form fields */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.field{display:flex;flex-direction:column;gap:4px}
.field-full{grid-column:span 2}
.field label{font-size:11px;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:.04em}
.finput{padding:7px 10px;border:1px solid var(--border2);border-radius:var(--r);font-size:13px;outline:none;font-family:inherit;background:#fff;transition:border-color .15s}
.finput:focus{border-color:var(--accent);box-shadow:0 0 0 2px rgba(107,79,216,.1)}
.finput:read-only{background:#f9fafb;color:var(--text3)}
textarea.finput{resize:vertical;min-height:70px}
/* Toast */
#toast{position:fixed;bottom:18px;left:50%;transform:translateX(-50%) translateY(8px);background:#14532d;color:#fff;border-radius:8px;padding:9px 16px;font-size:12px;font-weight:500;opacity:0;pointer-events:none;transition:all .25s;z-index:9999;box-shadow:0 4px 20px rgba(0,0,0,.3)}
#toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
</style>
