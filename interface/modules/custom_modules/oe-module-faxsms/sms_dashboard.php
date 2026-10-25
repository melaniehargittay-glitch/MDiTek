<?php
require_once(__DIR__ . "/../../../globals.php");
$_SESSION['patient_name'] = "Reminders";

use OpenEMR\Core\Header;


// ── 1. Load ALL logs (source of truth) ─────────────────────────────
$logRows = [];
$res = sqlStatement("
    SELECT nl.*,
           CONCAT(COALESCE(pd.fname,''), ' ', COALESCE(pd.lname,'')) AS patient_name
    FROM notification_log nl
    LEFT JOIN patient_data pd ON pd.pid = nl.pid
    WHERE nl.pc_eid IS NOT NULL
");

while ($row = sqlFetchArray($res)) {
    $eid = (int)$row['pc_eid'];
    if (!$eid) continue;

    $row['_date'] = substr($row['pc_eventDate'] ?? '', 0, 10);

    // Normalize attempts
    if (strtolower($row['status'] ?? '') === 'sent' && (int)$row['attempts'] === 0) {
        $row['attempts'] = 1;
    }

    $logRows[$eid] = $row; // keyed by appointment ID
}

$dateStart = date('Y-m-d H:i:s');
$dateEnd   = date('Y-m-d H:i:s', strtotime('+24 hours'));

// ── 2. Load appointments (next 24h) ────────────────────────────────
if (!function_exists('fetchAppointments')) {
    require_once $GLOBALS['srcdir'] . '/appointments.inc.php';
}

$apptList = fetchAppointments($dateStart, $dateEnd);

// ── 3. Build unified dataset ───────────────────────────────────────
$allRows = [];

// A. Start with logs (authoritative)
foreach ($logRows as $eid => $row) {
    $row['_source'] = 'log';
    $allRows[$eid] = $row;
}

// B. Add only missing appointments as pending
foreach ($apptList as $appt) {
    $eid = (int)($appt['pc_eid'] ?? 0);
    if (!$eid) continue;
    if (($appt['pc_apptstatus'] ?? '') === 'x') continue;

    // If ANY log exists → DO NOTHING
    if (isset($allRows[$eid])) {
        continue;
    }

    $phone = $appt['phone_cell'] ?? $appt['phone_home'] ?? '';
    $ptName = trim(($appt['fname'] ?? '') . ' ' . ($appt['lname'] ?? ''));

    // Only create pending when NO log exists
    $allRows[$eid] = [
        'iLogId'        => 0,
        'pid'           => $appt['pid'],
        'pc_eid'        => $eid,
        'pc_eventDate'  => $appt['pc_eventDate'] ?? $dateStart,
        'patient_info'  => ' ' . $ptName . '|||' . $phone . '|||',
        'patient_name'  => $ptName,
        'status'        => 'pending',
        'attempts'      => 0,
        '_date'         => substr($appt['pc_eventDate'] ?? '', 0, 10),
        '_source'       => 'appointment'
    ];
}

// Convert to indexed array
$allRows = array_values($allRows);

// ── 4. Sort: pending first, then newest ────────────────────────────
usort($allRows, function ($a, $b) {
    $aP = ($a['status'] ?? '') === 'pending' ? 0 : 1;
    $bP = ($b['status'] ?? '') === 'pending' ? 0 : 1;

    if ($aP !== $bP) return $aP - $bP;

    return strcmp($b['_date'] ?? '', $a['_date'] ?? '');
});

// ── 5. Count statuses ──────────────────────────────────────────────
$counts = [
    'pending' => 0,
    'sent'    => 0,
    'failed'  => 0
];

foreach ($allRows as $row) {
    $s = strtolower($row['status'] ?? '');
    if ($s === '') $s = 'pending';
        if (!isset($counts[$s])) $counts[$s] = 0;
        $counts[$s]++;
    }

$pendingCount = $counts['pending'] ?? 0;
$failedCount  = $counts['failed'] ?? 0;
$sentCount    = $counts['sent'] ?? 0;
$totalCount   = $pendingCount + $failedCount + $sentCount;
?>
<!DOCTYPE html>
<html>
<head>
<?php Header::setupHeader(['datatables', 'datatables-bs', 'datatables-responsive']); ?>
<title><?php echo xlt('Reminders'); ?></title>
<style>
/* ── Page shell ── */
body { background: #f4f6f9; font-family: "Montserrat", sans-serif; }

/* ── Top bar ── */
.tracker-topbar {
    background: #1a1f2e;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 20px;
    margin-bottom: 0;
}
.tracker-topbar h2 {
    font-size: 16px;
    font-weight: 600;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.tracker-topbar .top-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.auto-refresh-label {
    font-size: 12px;
    color: #aab;
}
.btn-refresh {
    background: transparent;
    border: 1px solid #aab;
    color: #fff;
    border-radius: 6px;
    padding: 5px 12px;
    font-size: 12px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
}
.btn-refresh:hover { background: rgba(255,255,255,0.1); }

.btn-send-all {
    background: #4361ee;
    border: none;
    color: #fff;
    border-radius: 6px;
    padding: 6px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-send-all:hover { background: #3451d1; }

/* ── Status filter pills ── */
.filter-bar {
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.filter-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all 0.15s;
    background: #f1f5f9;
    color: #475569;
    user-select: none;
}
.filter-pill:hover { background: #e2e8f0; }
.filter-pill.active {
    background: #1a1f2e;
    color: #fff;
    border-color: #1a1f2e;
}
.pill-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
    padding: 0 5px;
}
.filter-pill.active .pill-count { background: rgba(255,255,255,0.2); color:#fff; }
.filter-pill:not(.active) .pill-count { background: #cbd5e1; color: #334155; }

/* colored pill counts */
.pill-pending .pill-count  { background: #fef3c7; color: #92400e; }
.pill-failed .pill-count   { background: #fee2e2; color: #991b1b; }
.pill-sent .pill-count     { background: #d1fae5; color: #065f46; }
.filter-pill.active .pill-count { background: rgba(255,255,255,0.25) !important; color:#fff !important; }

.total-label {
    margin-left: auto;
    font-size: 13px;
    color: #64748b;
}

/* ── Table card ── */
.table-card {
    background: #fff;
    margin: 16px 20px;
    border-radius: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.07);
    overflow: hidden;
}
.table-card-header {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 10px 16px;
    font-size: 13px;
    font-weight: 600;
    color: #334155;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* DataTable overrides */
table.sms-table { width: 100% !important; }
table.sms-table thead tr th {
    background: #1a1f2e !important;
    color: #fff !important;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 10px 12px !important;
    border: none !important;
    white-space: nowrap;
}
table.sms-table thead tr th.sorting:after,
table.sms-table thead tr th.sorting_asc:after,
table.sms-table thead tr th.sorting_desc:after { color: #aab !important; }

table.sms-table tbody td {
    padding: 9px 12px !important;
    font-size: 13px;
    color: #334155;
    vertical-align: middle;
    border-bottom: 1px solid #f1f5f9 !important;
}
table.sms-table tbody tr:hover td { background: #f8fafc; }
table.sms-table tbody tr:last-child td { border-bottom: none !important; }

/* Status badges */
.badge-status {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
.badge-sent    { background: #d1fae5; color: #065f46; }
.badge-pending { background: #fef3c7; color: #92400e; }
.badge-failed  { background: #fee2e2; color: #991b1b; }

/* Action buttons */
.btn-action {
    padding: 4px 10px;
    font-size: 12px;
    font-weight: 600;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    margin-right: 4px;
}
.btn-retry { background: #f59e0b; color: #fff; }
.btn-retry:hover { background: #d97706; }
.btn-send-now { background: #2563eb; color: #fff; }
.btn-send-now:hover { background: #1d4ed8; }

/* DataTables controls */
.dataTables_wrapper .dataTables_filter input {
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 4px 10px;
    font-size: 13px;
}
.dataTables_wrapper .dataTables_length select {
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 3px 6px;
    font-size: 13px;
}
.dataTables_wrapper .dataTables_info { font-size: 12px; color: #64748b; padding: 10px 16px; }
.dataTables_wrapper .dataTables_paginate { padding: 8px 16px; }
.dataTables_wrapper .dataTables_paginate .paginate_button {
    border-radius: 5px !important;
    font-size: 13px !important;
}
.dataTables_wrapper .dataTables_filter,
.dataTables_wrapper .dataTables_length { padding: 10px 16px; }
</style>

<script>
var activeFilter = 'all';

function setFilter(filter) {
    activeFilter = filter;

    // Update pill active states
    document.querySelectorAll('.filter-pill').forEach(function(p) {
        p.classList.toggle('active', p.dataset.filter === filter);
    });

    // Redraw DataTable with new filter
    if (window.smsTable) {
        window.smsTable.draw();
    }
}


function runBatch() {
    fetch('sms_queue_action.php?action=send_all')
        .then(res => res.json())
        .then(res => {

            console.log(res);

            document.getElementById('sms-results').innerHTML += `
                <div>
                    Sent: ${res.sent} | Failed: ${res.failed}
                </div>
            `;

            if ((res.sent + res.failed) > 0) {
                setTimeout(runBatch, 1500);
            }
        })
        .catch(err => {
            console.error(err);
        });
}

// start process



function retrySMS(id) {
    fetch('sms_queue_action.php?action=retry&id=' + id)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { location.reload(); }
            else { alert('Send failed:\n' + JSON.stringify(data, null, 2)); }
        })
        .catch(function(e) { alert('Error: ' + e); });
}

function sendNow(id, eid) {
    var url = id > 0
        ? 'sms_queue_action.php?action=send&id=' + id
        : 'sms_queue_action.php?action=send_appt&eid=' + eid;
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) { location.reload(); }
            else { alert('Send failed:\n' + JSON.stringify(data, null, 2)); }
        })
        .catch(function(e) { alert('Error: ' + e); });
}

function sendAllPending() {
    if (!confirm("Send all pending messages?")) return;
    fetch('sms_queue_action.php?action=send_all')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            alert('Done — Sent: ' + (data.sent||0) + ', Failed: ' + (data.failed||0) +
                (data.errors && data.errors.length ? '\n\nErrors:\n' + data.errors.join('\n') : ''));
            location.reload();
        })
        .catch(function(e) { alert('Error: ' + e); });
}

function manualRefresh() {
    location.reload();
}

// Auto-refresh every 30s
var refreshInterval = 30;
var refreshTimer;
function startAutoRefresh() {
    refreshTimer = setInterval(function() { location.reload(); }, refreshInterval * 1000);
}

$(document).ready(function() {
    window.smsTable = $('#smsTable').DataTable({
        order: [[0, 'desc']],
        pageLength: 10,
        responsive: true,
        language: {
            search: '',
            searchPlaceholder: 'Search...',
            emptyTable: 'No data available in table',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
        },
        columnDefs: [
            { targets: 6, orderable: false }
        ],
        drawCallback: function() {
            // Update "Total" label
            var info = this.api().page.info();
            $('#totalShown').text(info.recordsDisplay);
        }
    });

    // Custom filter by status column (index 4)
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (activeFilter === 'all') return true;
        var status = data[4].trim().toLowerCase();
        return status === activeFilter;
    });

    // Show pending tab by default — called here so smsTable is guaranteed to exist
    setFilter('pending');

    startAutoRefresh();
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.title = 'Reminders';
});
</script>
</head>
<body>

<!-- Top bar -->
<div class="tracker-topbar">
    <h2>📨 SMS Dashboard
        <span style="font-size:12px;font-weight:400;color:#aab;margin-left:8px;">
            Next 24 Hours &mdash; <?= date('M j, g:i A') ?> &ndash; <?= date('M j, g:i A', strtotime('+24 hours')) ?>
        </span>
    </h2>
    <div id="sms-results"></div>
    <div class="top-actions">
        <span class="auto-refresh-label">Auto-refresh <?= $refreshInterval ?? 30 ?>s</span>
        <button class="btn-refresh" onclick="manualRefresh()">↺ Refresh</button>
        <button class="btn-send-all" onclick="sendAllPending()">▶ Send Waiting Messages</button>
    </div>
</div>

<!-- Filter pills -->
<div class="filter-bar">
    <span class="filter-pill active" data-filter="all" onclick="setFilter('all')">
        ○ All <span class="pill-count"><?= $totalCount ?></span>
    </span>
    <span class="filter-pill pill-pending" data-filter="pending" onclick="setFilter('pending')">
        ○ Pending <span class="pill-count"><?= $pendingCount ?></span>
    </span>
    <span class="filter-pill pill-sent" data-filter="sent" onclick="setFilter('sent')">
        ○ Sent <span class="pill-count"><?= $sentCount ?></span>
    </span>
    <span class="filter-pill pill-failed" data-filter="failed" onclick="setFilter('failed')">
        ⚠ Failed <span class="pill-count"><?= $failedCount ?></span>
    </span>
    <span class="total-label">Total: <strong><?= $totalCount ?></strong></span>
</div>

<!-- Table card -->
<div class="table-card">
    <div class="table-card-header">📋 All SMS Submissions</div>
    <table id="smsTable" class="sms-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Patient</th>
                <th>Phone</th>
                <th>Date</th>
                <th>Status</th>
                <th>Attempts</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($allRows as $row):
            $phone      = extractPhone($row['patient_info']);
            $status = strtolower($row['status'] ?? '');
            if ($status === '') {
                $status = 'pending';
            }
            $badgeClass = 'badge-' . $status;
            $patName    = trim($row['patient_name'] ?? '') ?: ('PID ' . $row['pid']);
        ?>
            <tr>
                <td><?= (int)$row['iLogId'] ?></td>
                <td><?= htmlspecialchars($patName) ?></td>
                <td><?= htmlspecialchars($phone) ?></td>
                <td><?= htmlspecialchars($row['_date'] ?: '—') ?></td>
                <td><span class="badge-status <?= $badgeClass ?>"><?= strtoupper($status) ?></span></td>
                <td><?= (int)$row['attempts'] ?></td>
                <td>
                <?php if ($status !== 'sent'): ?>
                    <?php if ($status === 'failed'): ?>
                        <button class="btn-action btn-retry" onclick="retrySMS(<?= (int)$row['iLogId'] ?>)">Retry</button>
                    <?php else: ?>
                        <button class="btn-action btn-send-now" onclick="sendNow(<?= (int)$row['iLogId'] ?>, <?= (int)($row['pc_eid'] ?? 0) ?>)">Send Now</button>
                    <?php endif; ?>
                <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

</body>
</html>

<?php
function extractPhone($patient_info)
{
    $parts = explode("|||", $patient_info);
    return $parts[1] ?? '';
}
