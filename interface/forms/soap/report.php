<?php

/**
 * soap form
 * Forms generated from formsWiz
 *
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Brady Miller <brady.g.miller@gmail.com>
 * @copyright Copyright (c) 2019 Brady Miller <brady.g.miller@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once(dirname(__FILE__) . '/../../globals.php');
require_once($GLOBALS["srcdir"] . "/api.inc.php");

// Marker written into the `subjective` field by the Auto Accident form's
// buildAccidentNote() (see general_new.html / mdi SOAP template UI). When this
// marker is present, the encounter was created with the Accident Form rather
// than as a normal SOAP note, and the field should be rendered as its own
// "Accident Details" card instead of being labeled "Subjective".
const SOAP_ACCIDENT_MARKER = 'AUTOMOBILE ACCIDENT';

function soap_report($pid, $encounter, $cols, $id)
{
    $cols = 1; // force always 1 column
    $count = 0;
    $data = formFetch("form_soap", $id);
    if ($data) {
        $isAccidentNote = isset($data['subjective'])
            && strpos($data['subjective'], SOAP_ACCIDENT_MARKER) !== false;

        if ($isAccidentNote) {
            soap_report_print_accident_card($data['subjective']);
        }

        print "<table><tr>";
        foreach ($data as $key => $value) {
            if ($key == "id" || $key == "pid" || $key == "user" || $key == "groupname" || $key == "authorized" || $key == "activity" || $key == "date" || $value == "" || $value == "0000-00-00 00:00:00") {
                continue;
            }

            // The Accident Form's structured note already has its own card
            // (printed above) — skip rendering it again as a generic
            // "Subjective" field.
            if ($isAccidentNote && $key == "subjective") {
                continue;
            }

            if ($value == "on") {
                $value = "yes";
            }

            $key = ucwords(str_replace("_", " ", $key));
                                                                              //Updated by Sherwin 10/24/2016
            print "<td><span class=bold>" . xlt($key) . ": </span><span class=text>" . nl2br(text($value)) . "</span></td>";
            $count++;
            if ($count == $cols) {
                $count = 0;
                print "</tr><tr>\n";
            }
        }
    }

    print "</tr></table>";
}

/**
 * Renders the Auto Accident structured note as its own labeled card
 * ("Accident Details") instead of folding it into the generic Subjective
 * field. The note text is plain, line-based (see buildAccidentNote() in the
 * SOAP template UI) using "═══ SECTION ═══" dividers and "Label: value"
 * lines — this just formats those cleanly rather than dumping raw text.
 */
function soap_report_print_accident_card($subjective)
{
    $lines = preg_split("/\r\n|\r|\n/", (string)$subjective);

    print '<table><tr><td>';
    print '<span class=bold>' . xlt('Accident Details') . ':</span><br />';

    $first = true;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        // Section divider lines look like: ═══ AUTOMOBILE ACCIDENT — DETAILS ═══
        if (preg_match('/^[=\x{2550}\x{2500}\-]+\s*(.+?)\s*[=\x{2550}\x{2500}\-]+$/u', $line, $m)) {
            $sectionTitle = trim($m[1]);
            $style = $first ? 'margin-top:4px;' : 'margin-top:14px;';
            print '<div style="' . $style . 'font-weight:bold;text-transform:uppercase;letter-spacing:0.04em;font-size:0.85em;color:#6b6480;">'
                . xlt($sectionTitle) . '</div>';
            $first = false;
            continue;
        }

        // Regular "Label: value" lines
        if (strpos($line, ':') !== false) {
            list($label, $val) = explode(':', $line, 2);
            print '<div><span class=bold>' . xlt(trim($label)) . ': </span><span class=text>'
                . nl2br(text(trim($val))) . '</span></div>';
        } else {
            print '<div><span class=text>' . nl2br(text($line)) . '</span></div>';
        }
        $first = false;
    }

    print '</td></tr></table>';
}
