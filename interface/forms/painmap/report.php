<?php
/**
 * Graphic Pain Map — report.php
 * Called by forms.php as painmap_report($id)
 * Place at: /interface/forms/painmap/report.php
 */
require_once(__DIR__ . '/../../globals.php');
require_once($srcdir . '/api.inc.php');

use OpenEMR\Common\Acl\AclMain;

if (!AclMain::aclCheckCore('encounters', 'notes')) {
    die(xlt('Access Not Authorized'));
}

function painmap_report($pid, $encounter, $cols, $id)
{
    $row = sqlQuery(
        "SELECT * FROM form_painmap WHERE id = ? AND pid = ? AND activity = 1 LIMIT 1",
        [$id, $pid]
    );
    if (empty($row)) {
        echo '<div style="font-size:12px;color:#666;">No pain map data.</div>';
        return;
    }

    $count   = (int)($row['marker_count'] ?? 0);
    $maxPain = (int)($row['max_pain'] ?? 0);
    $regions = text($row['regions_text'] ?? '');
    
    $markerNotes = [];
    
    if (!empty($row['markers_json'])) {
        // new.php stores plain JSON {dots:[{id,region,x,y},...], notes:{dotId:text,...}}
        $decoded = json_decode($row['markers_json'], true);
        // Fall back to base64 if plain parse fails
        if (!is_array($decoded) || !isset($decoded['dots'])) {
            $decoded = json_decode(base64_decode($row['markers_json']), true);
        }
        $notesMap = $decoded['notes'] ?? [];
        foreach (($decoded['dots'] ?? []) as $dot) {
            $region = $dot['region'] ?? 'Marker';
            // notes keyed by dot id (string), not array index
            $dotId  = (string)($dot['id'] ?? '');
            $note   = trim(
                $notesMap[$dotId]
                ?? $notesMap[(int)$dotId]
                ?? $notesMap[$region]
                ?? ''
            );
            if ($note !== '') {
                $markerNotes[] = $region . ': ' . $note;
            }
        }
    }
    
    $date    = oeFormatShortDate(substr($row['date'] ?? '', 0, 10));

    echo '<div style="font-family:sans-serif;font-size:13px;padding:6px 0;">';
    echo '<strong>' . xlt('Graphic Pain Map') . '</strong>';
    echo ' &mdash; ' . $date;
    if ($count) {
        echo '<br>' . xlt('Markers') . ': ' . $count;
        echo ' &nbsp;|&nbsp; ' . xlt('Max Pain') . ': ' . $maxPain . '/10';
        if ($regions) echo ' &nbsp;|&nbsp; ' . xlt('Regions') . ': ' . $regions;
    } else {
        echo '<br><em>' . xlt('No markers recorded.') . '</em>';
    }
    if (!empty($markerNotes)) {
        echo '<br><strong>Notes:</strong><br>';
        echo nl2br(text(implode("\n", $markerNotes)));
    }
    echo '</div>';
}
