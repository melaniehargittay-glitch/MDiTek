<?php

/**
 * Dicom viewer wrapper script for documents
 *
 * @package OpenEMR
 * @link    https://www.open-emr.org
 * @author    Victor Kofia <https://kofiav.com> 'Viewer'
 * @author    Jerry Padgett <sjpadgett@gmail.com> 'Viewer wrapper'
 * @copyright Copyright (c) 2017-2018 Victor Kofia <https://kofiav.com>
 * @copyright Copyright (c) 2018-2020 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

/* Warning: This script wraps the Dicom viewer which is HTML5 compatible only and bootstrap styling
*  should not be used inside this script due to style conflicts with viewer, namely, hidden class.
*/

require_once('../interface/globals.php');

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Header;

if (!AclMain::aclCheckCore('patients', 'docs')) {
    echo (new TwigContainer(null, $GLOBALS['kernel']))->getTwig()->render('core/unauthorized.html.twig', ['pageTitle' => xl("Dicom Viewer")]);
    exit;
}

$web_path = $_REQUEST['web_path'] ?? null;
if ($web_path) {
    $patid = $_REQUEST['patient_id'] ?? null;
    $docid = isset($_REQUEST['document_id']) ? $_REQUEST['document_id'] : ($_REQUEST['doc_id'] ?? null);
    $d = new Document(attr($docid));
    $type = '.dcm';
    if ($d->get_mimetype() == 'application/dicom+zip') {
        $type = '.zip';
    }
    $csrf = attr(CsrfUtils::collectCsrfToken());
    $state_url = $GLOBALS['web_root'] . "/library/ajax/upload.php";
    $web_path = attr($web_path) . '&retrieve&patient_id=' . attr_url($patid) . '&document_id=' . attr_url($docid) . '&as_file=false&type=' . attr_url($type);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt("Dicom Viewer"); ?></title>

    <?php Header::setupHeader(['dwv', 'i18next', 'i18next-xhr-backend', 'i18next-browser-languagedetector', 'jszip', 'magic-wand', 'konva']); ?>
    <script type="text/javascript" src="<?php echo $GLOBALS['assets_static_relative']; ?>/flot/dist/es5/jquery.flot.js"></script>
    <!-- Local (dwv) -->
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/browser.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/colourMap.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/custom.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/dropboxLoader.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/filter.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/generic.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/undo.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/help.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/html.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/infoController.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/infoOverlay.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/loader.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/tools.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/gui/plot.js"></script>
    <!-- i18n dwv wrapper -->
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/dwv_i18n.js"></script>
    <!-- Launch the app -->
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/dicom_gui.js"></script>
    <script src="<?php echo $GLOBALS['web_root'] ?>/library/js/dwv/dicom_launcher.js"></script>
</head>
<style type="text/css">
    /* ── Design tokens ────────────────────────────────────────────── */
    :root {
        --dv-bg:         #0d0f14;
        --dv-surface:    #161a24;
        --dv-surface2:   #1e2433;
        --dv-border:     rgba(255,255,255,.08);
        --dv-border2:    rgba(255,255,255,.15);
        --dv-text:       #e8ecf4;
        --dv-muted:      #7a8399;
        --dv-muted2:     #4a5168;
        --dv-accent:     #4f8ef7;
        --dv-accent2:    #34d399;
        --dv-accentbg:   rgba(79,142,247,.14);
        --dv-warn:       #f59e0b;
        --dv-danger:     #ef4444;
        --dv-radius:     8px;
        --dv-radius-sm:  5px;
        --dv-shadow:     0 8px 32px rgba(0,0,0,.55);
    }

    /* ── Base ─────────────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; }

    body {
        background: var(--dv-bg);
        color: var(--dv-text);
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        font-size: 13px;
        margin: 0;
    }

    button, input, li, table { margin-top: 0; }
    li button, li input { margin: 0; }

    /* ── #dwv outer shell ─────────────────────────────────────────── */
    #dwv.container-fluid {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
    }

    /* ── Page header ──────────────────────────────────────────────── */
    #pageHeader {
        display: flex;
        flex-direction: row;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 0 16px;
        height: 52px;
        background: var(--dv-surface);
        border-bottom: 1px solid var(--dv-border2);
        flex-shrink: 0;
    }

    /* Title */
    #pageHeader h2 {
        margin: 0;
        font-size: 14px;
        font-weight: 700;
        color: var(--dv-text);
        display: flex;
        align-items: center;
        gap: 8px;
        letter-spacing: -.01em;
    }
    #pageHeader h2::before {
        content: '';
        display: inline-block;
        width: 28px; height: 28px;
        border-radius: var(--dv-radius-sm);
        background: linear-gradient(135deg, #1a3a6e, #2563eb);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='1.8'%3E%3Crect x='3' y='3' width='18' height='18' rx='2'/%3E%3Ccircle cx='12' cy='12' r='4'/%3E%3Cline x1='12' y1='3' x2='12' y2='7'/%3E%3Cline x1='12' y1='17' x2='12' y2='21'/%3E%3Cline x1='3' y1='12' x2='7' y2='12'/%3E%3Cline x1='17' y1='12' x2='21' y2='12'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: center;
        flex-shrink: 0;
    }
    #pageHeader h2 em {
        font-size: 10px;
        font-style: normal;
        font-weight: 600;
        color: var(--dv-warn);
        background: rgba(245,158,11,.12);
        border: 1px solid rgba(245,158,11,.25);
        border-radius: 99px;
        padding: 2px 9px;
        letter-spacing: .02em;
    }
    #pageHeader h1, #pageHeader h3,
    #pageHeader h5, #pageHeader h6 {
        margin: 0;
        color: var(--dv-text);
    }
    #pageHeader a { color: var(--dv-accent); text-decoration: none; }

    /* Toolbar area inside header */
    #pageHeader .toolbar {
        display: flex;
        align-items: center;
        gap: 4px;
    }

    /* ── Toolbar strip (DWV injects .toolList here) ──────────────── */
    div.toolList {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 4px;
        padding: 7px 16px;
        background: var(--dv-surface);
        border-bottom: 1px solid var(--dv-border);
        flex-shrink: 0;
    }
    .toolList ul {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px;
        padding: 0; margin: 0;
        list-style: none;
    }
    .toolList li { margin: 0; }

    /* ── Base button style ─────────────────────────────────────────── */
    .toolList button,
    .toolList input[type=button] {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 13px 6px 10px;
        border-radius: var(--dv-radius-sm);
        border: 1px solid var(--dv-border2);
        background: var(--dv-surface2);
        color: var(--dv-muted);
        font-size: 12px;
        font-weight: 600;
        font-family: inherit;
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s, box-shadow .15s;
        margin: 0;
        white-space: nowrap;
        position: relative;
    }
    .toolList button:hover {
        background: #252d3d;
        color: var(--dv-text);
        border-color: rgba(255,255,255,.25);
        box-shadow: 0 2px 8px rgba(0,0,0,.3);
    }
    .toolList button.active,
    .toolList button[class*="active"] {
        background: var(--dv-accentbg);
        color: var(--dv-accent);
        border-color: rgba(79,142,247,.4);
        box-shadow: 0 0 0 3px rgba(79,142,247,.1);
    }

    /* ── SVG icon injected before each known button label ─────────── */
    /* Each ::before is an inline SVG data URI sized 14×14               */
    .toolList button::before {
        content: '';
        display: inline-block;
        width: 14px;
        height: 14px;
        flex-shrink: 0;
        background-repeat: no-repeat;
        background-position: center;
        background-size: 14px 14px;
        opacity: .7;
    }
    .toolList button:hover::before,
    .toolList button.active::before { opacity: 1; }

    /* Scroll / Pan */
    .toolList button[id*="Scroll"]::before,
    .toolList button[id*="scroll"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'/%3E%3Cpolyline points='8 12 12 8 16 12'/%3E%3Cline x1='12' y1='16' x2='12' y2='8'/%3E%3C/svg%3E");
    }
    /* ZoomAndPan */
    .toolList button[id*="Zoom"]::before,
    .toolList button[id*="zoom"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cline x1='21' y1='21' x2='16.65' y2='16.65'/%3E%3Cline x1='11' y1='8' x2='11' y2='14'/%3E%3Cline x1='8' y1='11' x2='14' y2='11'/%3E%3C/svg%3E");
    }
    /* WindowLevel / Contrast */
    .toolList button[id*="Window"]::before,
    .toolList button[id*="window"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'/%3E%3Cpath d='M12 2a10 10 0 0 1 0 20z'/%3E%3C/svg%3E");
    }
    /* Draw */
    .toolList button[id*="Draw"]::before,
    .toolList button[id*="draw"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M12 19l7-7 3 3-7 7-3-3z'/%3E%3Cpath d='M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z'/%3E%3Ccircle cx='11' cy='11' r='2'/%3E%3C/svg%3E");
    }
    /* Filter */
    .toolList button[id*="Filter"]::before,
    .toolList button[id*="filter"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolygon points='22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3'/%3E%3C/svg%3E");
    }
    /* Livewire / LiveWire */
    .toolList button[id*="Livewire"]::before,
    .toolList button[id*="livewire"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='22 12 18 12 15 21 9 3 6 12 2 12'/%3E%3C/svg%3E");
    }
    /* Ruler */
    .toolList button[id*="Ruler"]::before,
    .toolList button[id*="ruler"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M3 7l18 10M3 7l4 7M3 7l7-4M21 17l-4-7M21 17l-7 4'/%3E%3C/svg%3E");
    }
    /* Undo */
    .toolList button[id*="Undo"]::before,
    .toolList button[id*="undo"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='9 14 4 9 9 4'/%3E%3Cpath d='M20 20v-7a4 4 0 0 0-4-4H4'/%3E%3C/svg%3E");
    }
    /* Redo */
    .toolList button[id*="Redo"]::before,
    .toolList button[id*="redo"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='15 14 20 9 15 4'/%3E%3Cpath d='M4 20v-7a4 4 0 0 1 4-4h12'/%3E%3C/svg%3E");
    }
    /* Tags popup button */
    .toolList button[id*="Tags"]::before,
    .toolList button[id*="tags"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cline x1='8' y1='6' x2='21' y2='6'/%3E%3Cline x1='8' y1='12' x2='21' y2='12'/%3E%3Cline x1='8' y1='18' x2='21' y2='18'/%3E%3Cline x1='3' y1='6' x2='3.01' y2='6'/%3E%3Cline x1='3' y1='12' x2='3.01' y2='12'/%3E%3Cline x1='3' y1='18' x2='3.01' y2='18'/%3E%3C/svg%3E");
    }
    /* Help popup button */
    .toolList button[id*="Help"]::before,
    .toolList button[id*="help"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Ccircle cx='12' cy='12' r='10'/%3E%3Cpath d='M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3'/%3E%3Cline x1='12' y1='17' x2='12.01' y2='17'/%3E%3C/svg%3E");
    }
    /* File open button */
    .toolList button[id*="File"]::before,
    .toolList button[id*="file"]::before,
    .toolList button[id*="Open"]::before,
    .toolList button[id*="open"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z'/%3E%3C/svg%3E");
    }
    /* Url / URL button */
    .toolList button[id*="Url"]::before,
    .toolList button[id*="url"]::before {
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%237a8399' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71'/%3E%3Cpath d='M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71'/%3E%3C/svg%3E");
    }

    /* Active state icon colour shift to accent blue */
    .toolList button.active::before,
    .toolList button[class*="active"]::before {
        filter: brightness(0) saturate(100%) invert(55%) sepia(80%) saturate(500%) hue-rotate(190deg) brightness(105%);
        opacity: 1;
    }

    /* Divider between button groups inside toolList */
    .toolList li.separator {
        width: 1px; height: 22px;
        background: var(--dv-border2);
        margin: 0 4px;
        display: inline-block;
    }

    /* ── Selects & range inside toolList ──────────────────────────── */
    .toolList select {
        background: var(--dv-surface2);
        border: 1px solid var(--dv-border2);
        border-radius: var(--dv-radius-sm);
        color: var(--dv-text);
        font-size: 12px;
        font-family: inherit;
        padding: 5px 8px;
        outline: none;
        cursor: pointer;
        margin: 0;
    }
    .toolList select:focus { border-color: var(--dv-accent); }
    .toolList input[type=range] {
        height: 4px;
        accent-color: var(--dv-accent);
        cursor: pointer;
        margin: 0;
    }

    /* ── History (hidden — undo/redo accessed via toolbar buttons) ── */
    div.history { display: none; }
    .history_list { width: 100%; }

    /* ── Main content area ────────────────────────────────────────── */
    #pageMain {
        display: flex;
        flex-direction: row;
        justify-content: center;
        align-items: flex-start;
        flex: 1;
        padding: 20px;
        gap: 16px;
        background: var(--dv-bg);
    }

    /* ── Layer / canvas ───────────────────────────────────────────── */
    .layerContainer {
        position: relative;
        margin: 0;
        width: 512px;
        height: 512px;
        background: #000;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 0 0 1px var(--dv-border2), var(--dv-shadow);
    }

    .imageLayer { position: absolute; top: 0; left: 0; }
    .drawDiv    { position: absolute; inset: 0; pointer-events: none; }

    /* ── Drop box ─────────────────────────────────────────────────── */
    .dropBox { }
    .dropBoxBorder {
        position: absolute; inset: 0;
        border: 3px dashed rgba(79,142,247,.45);
        border-radius: 6px;
        background: rgba(79,142,247,.06);
        pointer-events: none;
    }
    .dropBoxBorder.hover {
        border-color: var(--dv-accent);
        background: rgba(79,142,247,.12);
    }

    /* ── Info overlay ─────────────────────────────────────────────── */
    .infoLayer ul { margin: 0; padding: 4px; list-style: none; }
    .infoLayer li { margin-top: 0; }
    .infoLayer canvas { margin: 0; padding: 2px; }

    .info  { color: rgba(200,220,255,.85); text-shadow: 1px 1px 2px #000; font-size: 11px; font-family: 'Courier New', monospace; }
    .infoc { color: #ffd700; text-shadow: 1px 1px 2px #000; font-size: 13px; font-family: 'Courier New', monospace; }

    .infotl { position: absolute; top: 6px;   left: 6px;                           text-align: left;   }
    .infotc { position: absolute; top: 6px;   left: 50%;  transform: translateX(-50%); text-align: center; }
    .infotr { position: absolute; top: 6px;   right: 6px;                          text-align: right;  }
    .infocl { position: absolute; top: 50%;   left: 6px;  transform: translateY(-50%); text-align: left;   }
    .infocr { position: absolute; top: 50%;   right: 6px; transform: translateY(-50%); text-align: right;  }
    .infobl { position: absolute; bottom: 6px; left: 6px;                           text-align: left;   }
    .infobc { position: absolute; bottom: 6px; left: 50%;  transform: translateX(-50%); text-align: center; }
    .infobr { position: absolute; bottom: 6px; right: 6px;                          text-align: right;  }

    .plot {
        position: absolute;
        width: 110px; height: 56px;
        bottom: 12px; right: 6px;
        background: rgba(0,0,0,.5);
        border-radius: 4px;
    }

    /* ── Tags ─────────────────────────────────────────────────────── */
    .tags {
        background: var(--dv-surface2);
        color: var(--dv-text);
        padding: .75rem 1rem;
        border-radius: var(--dv-radius-sm);
        font-size: 12px;
    }
    .tags form { width: 100%; }

    table.tagsTable {
        border-collapse: collapse;
        width: 100%;
        font-size: 11.5px;
        color: var(--dv-text);
    }
    table.tagsTable thead {
        position: sticky; top: 0;
        background: var(--dv-surface);
    }
    table.tagsTable thead th {
        padding: 6px 8px;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: var(--dv-muted);
        border-bottom: 1px solid var(--dv-border2);
    }
    table.tagsTable tr { border-bottom: 1px solid var(--dv-border); }
    table.tagsTable tr:nth-child(even) { background: rgba(255,255,255,.03); }
    table.tagsTable tr:hover           { background: var(--dv-surface2); }
    table.tagsTable td { padding: 5px 8px; word-break: break-all; }
    .highlighted { background: rgba(245,158,11,.18) !important; }

    /* ── Draw list ────────────────────────────────────────────────── */
    table.drawsTable { border-collapse: collapse; width: 100%; font-size: 12px; color: var(--dv-text); }
    table.drawsTable thead th {
        padding: 5px 8px;
        font-size: 9px; font-weight: 700;
        text-transform: uppercase; letter-spacing: .06em;
        color: var(--dv-muted);
        border-bottom: 1px solid var(--dv-border2);
    }
    table.drawsTable td { padding: 5px 8px; vertical-align: middle; }
    table.drawsTable tr:nth-child(even) { background: rgba(255,255,255,.03); }

    /* ── Help ─────────────────────────────────────────────────────── */
    .help {
        padding: 1rem 1.25rem;
        color: var(--dv-text);
        font-size: 12.5px;
        line-height: 1.7;
    }

    /* ── Popups ───────────────────────────────────────────────────── */
    /* DWV's toggle() uses the .show-popup class + visibility/opacity pattern.
       We keep that exact mechanism and just restyle the appearance.        */
    .popup {
        position: fixed;
        top: 15%; left: 15%;
        visibility: hidden;
        opacity: 0;
        transform: scale(1.04);
        transition: visibility 0s linear .2s, opacity .2s, transform .2s;
        z-index: 1000;
    }
    .show-popup {
        opacity: 1;
        visibility: visible;
        transform: scale(1);
        transition: visibility 0s, opacity .2s, transform .2s;
    }

    #loaderlist.popup {
        top: 50%; left: 50%;
        transform: scale(1.04);
        z-index: 1010;
    }
    #loaderlist.show-popup {
        transform: scale(1);
    }

    /* Popup content cards */
    .popup-content {
        background: var(--dv-surface);
        border: 1px solid var(--dv-border2);
        border-radius: var(--dv-radius);
        padding: 20px 22px;
        box-shadow: var(--dv-shadow);
        color: var(--dv-text);
        position: relative;
        min-width: 280px;
    }

    #loaderlist .popup-content {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        width: 360px;
        min-width: unset;
        padding: 20px;
    }

    .lg .popup-content {
        width: 78vw;
        max-height: 80vh;
        overflow-y: auto;
        border-radius: var(--dv-radius);
    }

    #helpPopup .popup-content {
        background: var(--dv-surface);
    }

    /* ── Close button ─────────────────────────────────────────────── */
    .close-button {
        position: absolute;
        top: 12px; right: 12px;
        width: 26px; height: 26px;
        border-radius: 50%;
        background: var(--dv-surface2);
        border: 1px solid var(--dv-border2);
        color: var(--dv-muted);
        display: flex; align-items: center; justify-content: center;
        font-size: 16px; line-height: 1;
        cursor: pointer;
        transition: background .15s, color .15s;
        z-index: 1011;
        text-align: center;
    }
    .close-button:hover { background: var(--dv-danger); color: #fff; border-color: var(--dv-danger); }

    /* ── Loader ───────────────────────────────────────────────────── */
    .loaderSelect {
        display: block;
        width: 100%;
        background: var(--dv-surface2);
        border: 1px solid var(--dv-border2);
        border-radius: var(--dv-radius-sm);
        color: var(--dv-text);
        font-size: 12px;
        font-family: inherit;
        padding: 7px 10px;
        outline: none;
        margin-bottom: 8px;
    }
    .loaderSelect:focus { border-color: var(--dv-accent); }

    #progressbar {
        width: 0;
        max-width: 100%;
        height: 4px;
        background: linear-gradient(90deg, var(--dv-accent), var(--dv-accent2));
        border-radius: 99px;
        transition: width .3s;
        margin-top: 10px;
    }

    /* ── Scrollbar ────────────────────────────────────────────────── */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--dv-border2); border-radius: 99px; }
    ::-webkit-scrollbar-thumb:hover { background: var(--dv-muted2); }
</style>
<body>
    <!-- DWV -->
    <div id="dwv" class="container-fluid" src='<?php echo $web_path ?>'>
    <?php if ($web_path) { ?>
        <input type="hidden" id="state_url" value='<?php echo $state_url ?>' />
        <input type="hidden" id="csrf" value='<?php echo $csrf ?>' />
        <input type="hidden" id="doc_id" value='<?php echo attr($docid) ?>'' />
    <?php } ?>
        <div id="pageHeader">
            <!-- Title -->
            <h2 style="color: #FFF !important; font-family: Montserrat;">DICOM Viewer<span>&nbsp;<em>( Not for Diagnostics )</em></h2>
            </span>
            <span class="editspan"></span>
            <div class="toolbar"></div>
        </div><!-- /pageHeader -->
        <div id="pageMain">
            <!-- get state json data if file -->
            <div class="openData" title="File">
                <!-- Open image files -->
                <div class="popup" id="loaderlist">
                    <div class="popup-content">
                        <span class="close-button" onclick="toggle('loaderlist');">&times;</span>
                        <br />
                        <div class="openData" title="File">
                            <div class="loaderlist"></div>
                            <div id="progressbar"></div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Toolbox -->
            <div class="toolList" title="Toolbox"></div>
            <!-- History -->
            <div class="history" title="History"></div>
            <!-- Tags -->
            <div class="popup lg" id="tagsPopup">
                <div class="popup-content">
                    <span class="close-button" onclick="toggle('tagsPopup');">&times;</span>
                    <div class="tags" title="Tags">Please load a DICOM study to view image tags.</div>
                </div>
            </div>
            <!-- Help -->
            <div class="popup lg" id="helpPopup">
                <div class="popup-content">
                    <span class="close-button" onclick="toggle('helpPopup');">&times;</span>
                    <div class="help" title="Help"></div>
                </div>
            </div>
            <!-- Layer Container -->
            <div class="layerDialog" title="Image">
                <div class="layerContainer">
                    <div class="dropBox dropBoxBorder"></div>
                    <canvas class="imageLayer">Only for HTML5 compatible browsers...</canvas>
                    <div class="drawDiv"></div>
                    <div class="infoLayer">
                        <div class="infotl"></div>
                        <div class="infotc"></div>
                        <div class="infotr"></div>
                        <div class="infocl"></div>
                        <div class="infocr"></div>
                        <div class="infobl"></div>
                        <div class="infobc"></div>
                        <div class="infobr" style="bottom: 64px;"></div>
                        <div class="plot"></div>
                    </div><!-- /infoLayer -->
                </div><!-- /layerContainer -->
            </div><!-- /layerDialog -->
            <!-- DrawList -->
            <div class="drawList" title="Draw list"></div>
        </div><!-- /pageMain -->
    </div><!-- /dwv -->

</body>
</html>
