<?php
/**
 * MDITek — Register painmap form in OpenEMR registry
 * Place at: /interface/forms/painmap/register.php
 * Visit ONCE, then DELETE.
 */
$ignoreAuth = true;
require_once('../../globals.php');

header('Content-Type: text/html; charset=utf-8');

$results = [];

// ── 1. Check current registry entry ─────────────────────────────
$existing = sqlQuery(
    "SELECT * FROM registry WHERE directory = 'painmap' LIMIT 1"
);

// ── 2. Register if missing ────────────────────────────────────────
if (empty($existing)) {
    $newId = sqlInsert(
        "INSERT INTO registry
            (name, state, directory, sql_run, unpackaged, date, priority, category, nickname, patient_encounter)
         VALUES (?, 1, 'painmap', 1, 1, NOW(), 0, 'Clinical', 'painmap', 1)",
        ['Graphic Pain Map']
    );
    $results[] = $newId
        ? "✓ Registered 'painmap' form in registry (id=$newId)"
        : "✗ INSERT failed";
} else {
    // Make sure it's active
    sqlStatement(
        "UPDATE registry SET state = 1 WHERE directory = 'painmap'"
    );
    $results[] = "✓ Form already registered (id={$existing['id']}) — ensured state=1";
}

// ── 3. Ensure form_painmap table exists ──────────────────────────
try {
    $GLOBALS['adodb']['db']->Execute("CREATE TABLE IF NOT EXISTS `form_painmap` (
        `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `date`           DATETIME DEFAULT NULL,
        `pid`            BIGINT NOT NULL DEFAULT 0,
        `encounter`      BIGINT NOT NULL DEFAULT 0,
        `user`           VARCHAR(255) NOT NULL DEFAULT '',
        `groupname`      VARCHAR(255) NOT NULL DEFAULT 'Default',
        `authorized`     TINYINT(4) NOT NULL DEFAULT 0,
        `activity`       TINYINT(4) NOT NULL DEFAULT 1,
        `markers_json`   MEDIUMTEXT,
        `marker_count`   INT NOT NULL DEFAULT 0,
        `max_pain`       TINYINT DEFAULT NULL,
        `regions_text`   VARCHAR(1000) DEFAULT NULL,
        `provider_notes` TEXT,
        PRIMARY KEY (`id`),
        KEY `idx_pid` (`pid`),
        KEY `idx_enc` (`encounter`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $check = sqlQuery("SELECT COUNT(*) AS cnt FROM information_schema.tables
        WHERE table_schema=DATABASE() AND table_name='form_painmap'");
    $results[] = $check['cnt'] ? "✓ form_painmap table exists" : "✗ Table still missing";
} catch (Exception $e) {
    $results[] = "✗ Table error: " . $e->getMessage();
}

// ── 4. Check required files exist ────────────────────────────────
$files = ['new.php','save.php','report.php','FormPainMap.php','C_FormPainMap.class.php'];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    $results[] = (file_exists($path) ? "✓" : "✗ MISSING") . " $f";
}

// ── 5. Show registry row ─────────────────────────────────────────
$reg = sqlQuery("SELECT * FROM registry WHERE directory='painmap' LIMIT 1");
?>
<!DOCTYPE html>
<html>
<head><title>Pain Map Registration</title>
<style>
body{font-family:monospace;padding:40px;background:#0f0520;color:#e2d9ff}
pre{background:#1e0a3c;padding:16px;border-radius:8px;line-height:1.8}
.ok{color:#34d399}.err{color:#f87171}.warn{color:#fbbf24}
h3{color:#a78bfa;margin-top:24px}
</style>
</head>
<body>
<h2>MDITek — Pain Map Form Registration</h2>

<h3>Results:</h3>
<pre><?php foreach ($results as $r) echo $r . "\n"; ?></pre>

<h3>Registry row:</h3>
<pre><?php print_r($reg); ?></pre>

<h3>Next steps:</h3>
<pre>
1. If all items show ✓ — delete this file and test the pain map save button
2. If report.php is missing — you may need to create it (see below)
3. Hard refresh the pain map page (Cmd+Shift+R) after running this
</pre>

<?php
// Check if report.php is missing and generate a minimal one
if (!file_exists(__DIR__ . '/report.php')): ?>
<h3 class="warn">⚠ report.php is missing — creating minimal version:</h3>
<?php
$reportContent = '<?php
require_once(__DIR__ . \'/../../globals.php\');
require_once("$srcdir/api.inc.php");
use OpenEMR\\Common\\Acl\\AclMain;
if (!AclMain::aclCheckCore(\'encounters\', \'notes\')) { die(xlt(\'Access Not Authorized\')); }
$id = (int)($_GET[\'id\'] ?? 0);
$pid = (int)($pid ?? $_SESSION[\'pid\'] ?? 0);
if ($id) {
    $row = sqlQuery("SELECT * FROM form_painmap WHERE id=? AND pid=?", [$id, $pid]);
}
$regions = $row[\'regions_text\'] ?? \'\';
$count   = $row[\'marker_count\'] ?? 0;
$maxPain = $row[\'max_pain\'] ?? 0;
?>
<div style="font-family:sans-serif;font-size:13px;padding:8px">
  <strong>Graphic Pain Map</strong><br>
  <?php if ($count): ?>
    Markers: <?php echo (int)$count; ?> &nbsp;|&nbsp;
    Max Pain: <?php echo (int)$maxPain; ?>/10 &nbsp;|&nbsp;
    Regions: <?php echo text($regions); ?>
  <?php else: ?>
    No markers recorded.
  <?php endif; ?>
</div>';

file_put_contents(__DIR__ . '/report.php', $reportContent);
echo '<pre class="ok">✓ Created minimal report.php</pre>';
endif; ?>

</body>
</html>
