<?php

/**
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Jerry Padgett <sjpadgett@gmail.com>
 * @copyright Copyright (c) 2022 Jerry Padgett <sjpadgett@gmail.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

require_once($GLOBALS["srcdir"] . "/api.inc.php");

use OpenEMR\Services\QuestionnaireResponseService;
use OpenEMR\Services\QuestionnaireService;

/**
 * @throws Exception
 */
function questionnaire_assessments_report($pid, $encounter, $cols, $id)
{
    $form = formFetch("form_questionnaire_assessments", $id);
    if (!$form) {
        echo '<p style="color:#6b7280;font-style:italic;padding:8px 0;">' . xlt('No questionnaire data found.') . '</p>';
        return;
    }

    // ── Attempt 1: standard FHIR render via QuestionnaireResponseService ─────
    $qr_raw = trim($form['questionnaire_response'] ?? '');
    if (!empty($qr_raw)) {
        $qr = json_decode($qr_raw, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($qr)) {
            try {
                $responseService = new QuestionnaireResponseService();
                $answers = $responseService->flattenQuestionnaireResponse($qr, '|', '');
                $html    = $responseService->buildQuestionnaireResponseHtml($answers, '|');
                if (trim(strip_tags($html)) !== '') {
                    echo $html;
                    return;
                }
            } catch (Exception $e) {
                // fall through to custom render
            }
        }
    }

    // ── Attempt 2: walk whatever FHIR structure is present and print it ──────
    _qar_render_card($form);
}

// ── Render all available data from the form row ──────────────────────────────
function _qar_render_card(array $form): void
{
    $rows  = [];   // [['q' => ..., 'a' => ...], ...]

    // Try questionnaire_response first
    $qr_raw = trim($form['questionnaire_response'] ?? '');
    if (!empty($qr_raw)) {
        $qr = @json_decode($qr_raw, true);
        if (!empty($qr['item'])) {
            _qar_walk_items($qr['item'], $rows);
        }
    }

    // If still empty, try the questionnaire definition itself (may hold pre-filled answers)
    if (empty($rows)) {
        $q_raw = trim($form['questionnaire'] ?? '');
        if (!empty($q_raw)) {
            $q = @json_decode($q_raw, true);
            if (!empty($q['item'])) {
                _qar_walk_items($q['item'], $rows, true);
            }
        }
    }

    // Last resort: dump every non-empty column from the form row
    if (empty($rows)) {
        $skip = ['id','pid','encounter','activity','authorized','date','user','groupname',
                 'questionnaire','questionnaire_response','questionnaire_response_status'];
        foreach ($form as $col => $val) {
            if (in_array($col, $skip, true)) continue;
            $val = trim((string)($val ?? ''));
            if ($val === '' || $val === '0') continue;
            $rows[] = ['q' => ucwords(str_replace('_', ' ', $col)), 'a' => $val];
        }
    }

    // Status / meta line
    $status = trim($form['questionnaire_response_status'] ?? '');
    $submitted = !empty($form['date']) ? date('m/d/Y', strtotime($form['date'])) : '';

    echo '<div style="font-family:system-ui,-apple-system,sans-serif;font-size:13px;color:#1e0a3c;">';

    // Meta bar
    if ($status || $submitted) {
        echo '<div style="display:flex;gap:12px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">';
        if ($status) {
            $sc = ($status === 'completed') ? '#059669' : (($status === 'in-progress') ? '#d97706' : '#6c757d');
            echo '<span style="display:inline-flex;align-items:center;gap:5px;padding:3px 10px;'
               . 'border-radius:20px;font-size:11px;font-weight:700;color:#fff;background:' . $sc . ';">'
               . '&#9679; ' . htmlspecialchars(ucfirst($status)) . '</span>';
        }
        if ($submitted) {
            echo '<span style="font-size:11.5px;color:#6b7280;">' . xlt('Submitted') . ': ' . htmlspecialchars($submitted) . '</span>';
        }
        echo '</div>';
    }

    if (empty($rows)) {
        echo '<p style="color:#9ca3af;font-style:italic;padding:6px 0;">'
           . xlt('This questionnaire was submitted but contains no answer data.') . '</p>';
    } else {
        echo '<table style="width:100%;border-collapse:collapse;">';
        foreach ($rows as $i => $row) {
            $bg = ($i % 2 === 0) ? '#faf8ff' : '#fff';
            echo '<tr style="background:' . $bg . ';border-bottom:1px solid #ede9fe;">';
            echo '<td style="padding:7px 12px;color:#6b7280;font-size:12px;width:42%;vertical-align:top;line-height:1.4;">'
               . htmlspecialchars($row['q']) . '</td>';
            echo '<td style="padding:7px 12px;font-size:12.5px;font-weight:600;color:#1e0a3c;vertical-align:top;line-height:1.4;">'
               . nl2br(htmlspecialchars($row['a'])) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }

    echo '</div>';
}

// ── Recursively walk FHIR QuestionnaireResponse items → flat Q/A pairs ───────
function _qar_walk_items(array $items, array &$rows, bool $def_mode = false): void
{
    foreach ($items as $item) {
        $text = trim($item['text'] ?? ($item['linkId'] ?? ''));

        // Sub-group: recurse
        if (!empty($item['item'])) {
            _qar_walk_items($item['item'], $rows, $def_mode);
            continue;
        }

        // Leaf with answers
        if (!empty($item['answer'])) {
            $parts = [];
            foreach ((array)$item['answer'] as $ans) {
                foreach ([
                    'valueString', 'valueBoolean', 'valueInteger',
                    'valueDecimal', 'valueDate', 'valueDateTime',
                    'valueCoding', 'valueQuantity', 'valueUri',
                ] as $vk) {
                    if (!isset($ans[$vk])) continue;
                    if ($vk === 'valueCoding') {
                        $parts[] = $ans[$vk]['display'] ?? $ans[$vk]['code'] ?? '';
                    } elseif ($vk === 'valueQuantity') {
                        $parts[] = trim(($ans[$vk]['value'] ?? '') . ' ' . ($ans[$vk]['unit'] ?? ''));
                    } elseif ($vk === 'valueBoolean') {
                        $parts[] = $ans[$vk] ? xlt('Yes') : xlt('No');
                    } else {
                        $v = (string)$ans[$vk];
                        if ($v !== '') $parts[] = $v;
                    }
                    break;
                }
            }
            $answer = implode(', ', array_filter($parts, fn($v) => trim($v) !== ''));
            if ($text !== '' || $answer !== '') {
                $rows[] = ['q' => $text, 'a' => $answer];
            }
            continue;
        }

        // Definition mode: show question text even without an answer
        if ($def_mode && $text !== '') {
            $rows[] = ['q' => $text, 'a' => '—'];
        }
    }
}
